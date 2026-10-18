"""Train the local logistic-regression model: ``python -m laas.train_local``.

Modes:
  production  train only on rows whose licence allows it (split_policy == train); may ship.
  experiment  train on every tier; for research only, never shipped (header says so).

Thresholds come from the validation split: t_high at <= 0.5% FPR, t_low at <= 5% FPR. Metrics are
reported on the production test split and on the all-tier test split, which contains sources the
production model never saw, so it is the more honest of the two. Neither is the real contact-form
test set (PRD §9.4); that comes from the beta sites.
"""

from __future__ import annotations

import argparse
import json
import time
from collections import Counter
from datetime import UTC, datetime
from multiprocessing import Pool
from pathlib import Path
from typing import Any

import numpy as np
import numpy.typing as npt
import pyarrow as pa
import pyarrow.compute as pc
import pyarrow.parquet as pq
import scipy.sparse as sp
from sklearn.linear_model import LogisticRegression

from laas.data.schema import SplitPolicy, assert_trainable
from laas.eval import (
    Rate,
    ece,
    keyword_baseline,
    mix_weights,
    rates_at,
    roc_auc,
    threshold_at_fpr,
    wilson,
)
from laas.featurize import DENSE_NAMES, featurize
from laas.model_file import write_model

TRAINING_DIR = Path(__file__).resolve().parents[1]
DEFAULT_DATA = TRAINING_DIR / "data" / "processed" / "corpus_v1.parquet"
DEFAULT_RUNS = TRAINING_DIR / "runs"
COLUMNS = ["text", "label_id", "split_policy", "corpus_split", "language", "source", "domain"]
EVAL_BATCH = 5000
GATE_FPR = 0.005  # PRD §9.4: false-positive rate on real enquiries, per ham domain (R14)
GATE_MIN_HAM = 50  # fewer ham rows than this can't say anything about a 0.5% rate


def _featurize_one(
    args: tuple[str, int],
) -> tuple[npt.NDArray[np.int32], npt.NDArray[np.float64], list[float]]:
    text, n_buckets = args
    f = featurize(text, None, n_buckets)
    idx = np.fromiter(f.hashed.keys(), dtype=np.int32, count=len(f.hashed))
    val = np.fromiter(f.hashed.values(), dtype=np.float64, count=len(f.hashed))
    return idx, val, f.dense


def featurize_many(
    texts: list[str], n_buckets: int, pool: Any
) -> tuple[sp.csr_matrix, npt.NDArray[np.float64]]:
    results = pool.map(_featurize_one, [(t, n_buckets) for t in texts], chunksize=256)
    indptr = np.zeros(len(results) + 1, dtype=np.int64)
    indptr[1:] = np.cumsum([len(r[0]) for r in results])
    indices = np.concatenate([r[0] for r in results]) if results else np.zeros(0, np.int32)
    data = np.concatenate([r[1] for r in results]) if results else np.zeros(0)
    hashed = sp.csr_matrix((data, indices, indptr), shape=(len(texts), n_buckets))
    dense = np.array([r[2] for r in results], dtype=np.float64).reshape(
        len(texts), len(DENSE_NAMES)
    )
    return hashed, dense


def _rate(r: Rate) -> dict[str, float]:
    return {"value": r.value, "low": r.low, "high": r.high, "k": r.k, "n": r.n}


def evaluate(
    name: str,
    texts: list[str],
    labels: npt.NDArray[np.int_],
    probs: npt.NDArray[np.float64],
    sources: list[str],
    domains: list[str],
    t_low: float,
    t_high: float,
    spam_share: float,
) -> dict[str, Any]:
    kw = keyword_baseline(texts)
    flagged = probs >= t_high
    per_source = Counter(s for s, y in zip(sources, labels, strict=True) if y == 1)
    caught = Counter(s for s, y, f in zip(sources, labels, flagged, strict=True) if y == 1 and f)
    ham_dom = Counter(d for d, y in zip(domains, labels, strict=True) if y == 0)
    fp_dom = Counter(d for d, y, f in zip(domains, labels, flagged, strict=True) if y == 0 and f)
    gates = {}
    for domain, n in ham_dom.most_common():
        if n >= GATE_MIN_HAM:
            r = wilson(fp_dom[domain], n)
            gates[domain] = {
                **_rate(r),
                "pass": r.value <= GATE_FPR,  # point estimate within the gate
                "proven": r.high <= GATE_FPR,  # whole 95% interval within the gate (R4)
            }
    return {
        "name": name,
        "n_ham": int((labels == 0).sum()),
        "n_spam": int((labels == 1).sum()),
        "roc_auc": roc_auc(probs, labels),
        "ece": ece(probs, labels, weights=mix_weights(labels, spam_share)),  # at the calibrated mix
        "at_t_high": {k: _rate(v) for k, v in rates_at(probs, labels, t_high).items()},
        "at_t_low": {k: _rate(v) for k, v in rates_at(probs, labels, t_low).items()},
        "oracle_recall_at_fpr": {
            str(f): rates_at(probs, labels, threshold_at_fpr(probs, labels, f))["recall"].value
            for f in (0.005, 0.01)
        },
        "keyword_baseline": {
            k: _rate(v) for k, v in rates_at(kw.astype(np.float64), labels, 0.5).items()
        },
        "recall_by_source": {s: caught[s] / n for s, n in per_source.most_common()},
        "fpr_by_domain": {d: fp_dom[d] / n for d, n in ham_dom.most_common()},
        "domain_gates": gates,
    }


def synthetic_share_weights(
    labels: npt.NDArray[np.int_], synthetic: npt.NDArray[np.bool_], share: float
) -> tuple[npt.NDArray[np.float64], dict[str, dict[str, float]]]:
    """Weights that give synthetic rows the same share of each class's total weight (R15).

    Without this, generator-written text sits mostly on one side (the pilot: ~23% of spam but ~0.7%
    of ham), so "sounds generated" becomes a label signal. With an equal share in both classes the
    generator's style says nothing about the label. Each class keeps its total weight; a class with
    no synthetic (or no real) rows is left unweighted.
    """
    weights = np.ones(len(labels), dtype=np.float64)
    info: dict[str, dict[str, float]] = {}
    for cls, name in ((0, "ham"), (1, "spam")):
        in_class = labels == cls
        syn, real = in_class & synthetic, in_class & ~synthetic
        n_syn, n_real, n = int(syn.sum()), int(real.sum()), int(in_class.sum())
        before = n_syn / n if n else 0.0
        after = before
        if n_syn and n_real:
            weights[syn] = share * n / n_syn
            weights[real] = (1 - share) * n / n_real
            after = share
        info[name] = {
            "synthetic": n_syn,
            "real": n_real,
            "share_before": before,
            "share_after": after,
        }
    return weights, info


def _fmt(r: dict[str, float]) -> str:
    return f"{r['value']:.2%} [{r['low']:.2%}, {r['high']:.2%}]"


def report_md(meta: dict[str, Any], results: list[dict[str, Any]]) -> str:
    lines = [
        f"# Local model run `{meta['model_version']}`",
        "",
        f"Mode **{meta['mode']}** · trained {meta['trained_at']} · "
        f"{meta['n_train']:,} training rows ({meta['n_train_spam']:,} spam) · "
        f"buckets 2^{meta['bucket_bits']} · C={meta['C']}",
        "",
        f"Thresholds from validation: t_low={meta['t_low']:.4f} (<= 5% FPR), "
        f"t_high={meta['t_high']:.4f} (<= 0.5% FPR).",
        "",
        f"Calibration fitted as if {meta['calib_spam_share']:.0%} of messages were spam "
        "(scores mean P(spam) at that mix; thresholds are set on ham scores, so they don't move). "
        f"Held-out sources (never trained on): {', '.join(meta['holdout']) or 'none'}.",
        "",
        "Synthetic share of training weight (R15: equal in both classes, so the generator's style "
        "isn't a label signal): "
        + ", ".join(
            f"{k} {v['share_before']:.1%} of {v['synthetic'] + v['real']:,.0f} rows "
            f"-> {v['share_after']:.1%}"
            for k, v in meta["synthetic_balance"].items()
        )
        + ".",
        "",
        "> Not the PRD gate: no metric here is measured on real contact-form messages.",
        "",
        "| Set | Ham | Spam | AUC | FPR at t_high (95% CI) | Recall at t_high (95% CI) "
        "| Oracle recall @0.5% FPR | ECE | Keyword FPR | Keyword recall |",
        "|---|---:|---:|---:|---|---|---:|---:|---:|---:|",
    ]
    for r in results:
        lines.append(
            f"| {r['name']} | {r['n_ham']:,} | {r['n_spam']:,} | {r['roc_auc']:.4f} "
            f"| {_fmt(r['at_t_high']['fpr'])} | {_fmt(r['at_t_high']['recall'])} "
            f"| {r['oracle_recall_at_fpr']['0.005']:.2%} | {r['ece']:.3f} "
            f"| {r['keyword_baseline']['fpr']['value']:.2%} "
            f"| {r['keyword_baseline']['recall']['value']:.2%} |"
        )
    lines += [
        "",
        f"## Per-domain gate: FPR at t_high <= {GATE_FPR:.1%} for each ham domain (R14)",
        "",
        "PASS = point estimate within the gate; PROVEN = whole 95% interval within it.",
        "",
        "| Set | Domain | Ham | FPR (95% CI) | Result |",
        "|---|---|---:|---|---|",
    ]
    for r in results:
        for domain, g in r["domain_gates"].items():
            verdict = "PROVEN" if g["proven"] else ("PASS" if g["pass"] else "**FAIL**")
            lines.append(f"| {r['name']} | `{domain}` | {g['n']:,} | {_fmt(g)} | {verdict} |")
    for r in results:
        lines += ["", f"## {r['name']}: recall at t_high by spam source", ""]
        lines += [f"- `{s}`: {v:.1%}" for s, v in r["recall_by_source"].items()]
        lines += ["", f"## {r['name']}: false-positive rate at t_high by ham domain", ""]
        lines += [f"- `{d}`: {v:.2%}" for d, v in r["fpr_by_domain"].items()]
    return "\n".join(lines) + "\n"


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--data", type=Path, default=DEFAULT_DATA)
    parser.add_argument(
        "--extra",
        type=Path,
        action="append",
        default=[],
        metavar="PARQUET",
        help="More data in the same schema, e.g. data/processed/synthetic_v1.parquet. Its rows "
        "follow their own split_policy and corpus_split. Repeat for several files.",
    )
    parser.add_argument("--runs", type=Path, default=DEFAULT_RUNS)
    parser.add_argument("--mode", choices=["production", "experiment"], default="production")
    parser.add_argument("--bucket-bits", type=int, default=19)
    parser.add_argument("--C", type=float, default=4.0)
    parser.add_argument("--max-train-ham", type=int, default=60_000)
    parser.add_argument("--max-train-spam", type=int, default=60_000)
    parser.add_argument("--workers", type=int, default=7)
    parser.add_argument("--seed", type=int, default=13)
    parser.add_argument(
        "--holdout",
        action="append",
        default=[],
        metavar="SOURCE",
        help="Leave a source out of training and validation entirely, then report how the model "
        "does on it: a test on data it has never seen. Repeat for several sources.",
    )
    parser.add_argument(
        "--calib-spam-share",
        type=float,
        default=0.5,
        help="Spam share the calibrated scores should assume (real contact-form mix; 0.5 until "
        "the hand-labelled set exists). Thresholds are unaffected.",
    )
    parser.add_argument(
        "--synthetic-share",
        default="auto",
        help="Share of each class's training weight given to synthetic rows, the same for ham "
        "and spam so the generator's style carries no label signal (R15). 'auto' = 0.5 when "
        "synthetic rows are present; 'off' = no reweighting; or a number between 0 and 1.",
    )
    args = parser.parse_args(argv)
    if args.synthetic_share not in ("auto", "off"):
        try:
            if not 0 < float(args.synthetic_share) < 1:
                raise ValueError
        except ValueError:
            parser.error("--synthetic-share must be 'auto', 'off' or a number between 0 and 1")

    n_buckets = 1 << args.bucket_bits
    rng = np.random.default_rng(args.seed)

    def locate(path: Path) -> Path:
        # Relative paths work from the repo root as well as from training/.
        if path.exists() or path.is_absolute() or not (TRAINING_DIR / path).exists():
            return path
        return TRAINING_DIR / path

    full = pa.concat_tables(
        [pq.read_table(locate(path), columns=COLUMNS) for path in [args.data, *args.extra]]
    )
    full = full.filter(pc.equal(full.column("language"), "en"))
    production = args.mode == "production"

    unknown = set(args.holdout) - set(full.column("source").unique().to_pylist())
    if unknown:
        parser.error(f"unknown --holdout source(s): {', '.join(sorted(unknown))}")
    held = pc.is_in(full.column("source"), value_set=pa.array(args.holdout, pa.string()))
    table = full.filter(pc.invert(held))  # everything training and validation may see
    holdout = full.filter(held)  # every split of the held-out sources: never trained on

    def rows(split: str, trainable_only: bool) -> Any:
        t = table.filter(pc.equal(table.column("corpus_split"), split))
        if trainable_only:
            t = assert_trainable(t.filter(pc.equal(t.column("split_policy"), SplitPolicy.TRAIN)))
        return t

    train = rows("train", production)
    labels = np.asarray(train.column("label_id").to_numpy(), dtype=np.int_)
    keep = []
    for cls, cap in ((0, args.max_train_ham), (1, args.max_train_spam)):
        idx = np.flatnonzero(labels == cls)
        keep.append(rng.choice(idx, size=min(cap, len(idx)), replace=False))
    train = train.take(np.sort(np.concatenate(keep)))

    sets = {
        "val": rows("val", production),
        "test (production tier)": rows("test", True),
        "test (all tiers)": rows("test", False),
    }
    if args.holdout:
        sets[f"holdout ({', '.join(args.holdout)})"] = holdout

    started = time.perf_counter()

    def log(message: str) -> None:
        print(f"[{time.perf_counter() - started:7.1f}s] {message}", flush=True)

    log(f"{args.mode}: {train.num_rows:,} training rows; featurizing with {args.workers} workers")
    with Pool(args.workers) as pool:
        x_train, d_train = featurize_many(train.column("text").to_pylist(), n_buckets, pool)
        log(f"Featurized training set ({x_train.nnz:,} non-zeros); fitting logistic regression")
        means, stds = d_train.mean(axis=0), d_train.std(axis=0)
        stds = np.where(stds > 1e-12, stds, 1.0)
        y_train = np.asarray(train.column("label_id").to_numpy(), dtype=np.int_)

        is_synthetic = np.asarray(
            pc.starts_with(train.column("source"), "synthetic_").to_numpy(zero_copy_only=False),
            dtype=np.bool_,
        )
        share = 0.5 if args.synthetic_share == "auto" else args.synthetic_share
        if share == "off" or not is_synthetic.any():
            sample_weight = np.ones(len(y_train))
            _, balance = synthetic_share_weights(y_train, is_synthetic, 0.5)
            for side in balance.values():
                side["share_after"] = side["share_before"]
        else:
            sample_weight, balance = synthetic_share_weights(y_train, is_synthetic, float(share))
        log(
            "Synthetic share of training weight: "
            + ", ".join(
                f"{k} {v['share_before']:.1%} -> {v['share_after']:.1%}" for k, v in balance.items()
            )
        )

        model = LogisticRegression(
            C=args.C, solver="liblinear", class_weight="balanced", max_iter=500
        )
        model.fit(
            sp.hstack([x_train, sp.csr_matrix((d_train - means) / stds)]).tocsr(),
            y_train,
            sample_weight=sample_weight,
        )
        w = model.coef_[0].astype(np.float32).astype(np.float64)  # what model.bin will hold
        bias = float(model.intercept_[0])

        del x_train  # free memory before scoring the evaluation sets
        log("Model fitted; scoring evaluation sets")

        def logits(t: Any) -> npt.NDArray[np.float64]:
            texts = t.column("text").to_pylist()
            parts = []
            for start in range(0, len(texts), EVAL_BATCH):  # batches keep peak memory low
                xs, ds = featurize_many(texts[start : start + EVAL_BATCH], n_buckets, pool)
                parts.append(xs @ w[:n_buckets] + ((ds - means) / stds) @ w[n_buckets:] + bias)
            out: npt.NDArray[np.float64] = np.concatenate(parts) if parts else np.zeros(0)
            return out

        z = {}
        for name, t in sets.items():
            z[name] = logits(t)
            log(f"Scored {name} ({t.num_rows:,} rows)")
    seconds = time.perf_counter() - started

    y = {
        name: np.asarray(t.column("label_id").to_numpy(), dtype=np.int_) for name, t in sets.items()
    }
    # Reweight validation so calibration assumes the target spam share instead of the val mix (R14).
    val_share = float(y["val"].mean())
    weights = mix_weights(y["val"], args.calib_spam_share)
    platt = LogisticRegression(C=1e6).fit(z["val"].reshape(-1, 1), y["val"], sample_weight=weights)
    a, b = float(platt.coef_[0][0]), float(platt.intercept_[0])
    probs = {name: 1 / (1 + np.exp(-(a * v + b))) for name, v in z.items()}
    t_high = threshold_at_fpr(probs["val"], y["val"], 0.005)
    t_low = min(threshold_at_fpr(probs["val"], y["val"], 0.05), t_high)

    results = [
        evaluate(
            name,
            sets[name].column("text").to_pylist(),
            y[name],
            probs[name],
            sets[name].column("source").to_pylist(),
            sets[name].column("domain").to_pylist(),
            t_low,
            t_high,
            args.calib_spam_share,
        )
        for name in sets
    ]

    stamp = datetime.now(UTC)
    version = f"local-{'baseline' if production else 'experiment'}-{stamp:%Y%m%d-%H%M}"
    meta = {
        "model_version": version,
        "mode": args.mode,
        "production": production,
        "trained_at": stamp.isoformat(timespec="seconds"),
        "n_train": train.num_rows,
        "n_train_spam": int(y_train.sum()),
        "bucket_bits": args.bucket_bits,
        "C": args.C,
        "t_low": t_low,
        "t_high": t_high,
        "featurize_and_train_seconds": round(seconds, 1),
        "holdout": args.holdout,
        "calib_spam_share": args.calib_spam_share,
        "synthetic_balance": balance,
        "val_spam_share": val_share,
    }
    out = args.runs / f"{stamp:%Y%m%d-%H%M%S}-{args.mode}"
    write_model(
        out / "model.bin",
        weights=w[:n_buckets],
        dense_weights=w[n_buckets:],
        bias=bias,
        dense_means=means.tolist(),
        dense_stds=stds.tolist(),
        platt_a=a,
        platt_b=b,
        t_low=t_low,
        t_high=t_high,
        model_version=version,
        metrics={"production": production, "sets": {r["name"]: r["at_t_high"] for r in results}},
        trained_at=meta["trained_at"],
    )
    (out / "metrics.json").write_text(json.dumps({"meta": meta, "results": results}, indent=2))
    (out / "report.md").write_text(report_md(meta, results), encoding="utf-8")
    print(report_md(meta, results))
    print(f"Wrote {out}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
