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
import pyarrow.compute as pc
import pyarrow.parquet as pq
import scipy.sparse as sp
from sklearn.linear_model import LogisticRegression

from laas.data.schema import SplitPolicy, assert_trainable
from laas.eval import (
    Rate,
    ece,
    keyword_baseline,
    rates_at,
    roc_auc,
    threshold_at_fpr,
)
from laas.featurize import DENSE_NAMES, featurize
from laas.model_file import write_model

TRAINING_DIR = Path(__file__).resolve().parents[1]
DEFAULT_DATA = TRAINING_DIR / "data" / "processed" / "corpus_v1.parquet"
DEFAULT_RUNS = TRAINING_DIR / "runs"
COLUMNS = ["text", "label_id", "split_policy", "corpus_split", "language", "source", "domain"]


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
) -> dict[str, Any]:
    kw = keyword_baseline(texts)
    flagged = probs >= t_high
    per_source = Counter(s for s, y in zip(sources, labels, strict=True) if y == 1)
    caught = Counter(s for s, y, f in zip(sources, labels, flagged, strict=True) if y == 1 and f)
    ham_dom = Counter(d for d, y in zip(domains, labels, strict=True) if y == 0)
    fp_dom = Counter(d for d, y, f in zip(domains, labels, flagged, strict=True) if y == 0 and f)
    return {
        "name": name,
        "n_ham": int((labels == 0).sum()),
        "n_spam": int((labels == 1).sum()),
        "roc_auc": roc_auc(probs, labels),
        "ece": ece(probs, labels),
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
    }


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
    for r in results:
        lines += ["", f"## {r['name']}: recall at t_high by spam source", ""]
        lines += [f"- `{s}`: {v:.1%}" for s, v in r["recall_by_source"].items()]
        lines += ["", f"## {r['name']}: false-positive rate at t_high by ham domain", ""]
        lines += [f"- `{d}`: {v:.2%}" for d, v in r["fpr_by_domain"].items()]
    return "\n".join(lines) + "\n"


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--data", type=Path, default=DEFAULT_DATA)
    parser.add_argument("--runs", type=Path, default=DEFAULT_RUNS)
    parser.add_argument("--mode", choices=["production", "experiment"], default="production")
    parser.add_argument("--bucket-bits", type=int, default=19)
    parser.add_argument("--C", type=float, default=4.0)
    parser.add_argument("--max-train-ham", type=int, default=60_000)
    parser.add_argument("--max-train-spam", type=int, default=60_000)
    parser.add_argument("--workers", type=int, default=7)
    parser.add_argument("--seed", type=int, default=13)
    args = parser.parse_args(argv)

    n_buckets = 1 << args.bucket_bits
    rng = np.random.default_rng(args.seed)
    table = pq.read_table(args.data, columns=COLUMNS)
    table = table.filter(pc.equal(table.column("language"), "en"))
    production = args.mode == "production"

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

    started = time.perf_counter()
    with Pool(args.workers) as pool:
        x_train, d_train = featurize_many(train.column("text").to_pylist(), n_buckets, pool)
        means, stds = d_train.mean(axis=0), d_train.std(axis=0)
        stds = np.where(stds > 1e-12, stds, 1.0)
        y_train = np.asarray(train.column("label_id").to_numpy(), dtype=np.int_)

        model = LogisticRegression(
            C=args.C, solver="liblinear", class_weight="balanced", max_iter=500
        )
        model.fit(sp.hstack([x_train, sp.csr_matrix((d_train - means) / stds)]).tocsr(), y_train)
        w = model.coef_[0].astype(np.float32).astype(np.float64)  # what model.bin will hold
        bias = float(model.intercept_[0])

        def logits(t: Any) -> npt.NDArray[np.float64]:
            xs, ds = featurize_many(t.column("text").to_pylist(), n_buckets, pool)
            out: npt.NDArray[np.float64] = (
                xs @ w[:n_buckets] + ((ds - means) / stds) @ w[n_buckets:] + bias
            )
            return out

        z = {name: logits(t) for name, t in sets.items()}
    seconds = time.perf_counter() - started

    y = {
        name: np.asarray(t.column("label_id").to_numpy(), dtype=np.int_) for name, t in sets.items()
    }
    platt = LogisticRegression(C=1e6).fit(z["val"].reshape(-1, 1), y["val"])
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
