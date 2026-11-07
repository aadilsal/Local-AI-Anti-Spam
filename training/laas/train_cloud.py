"""Fine-tune the cloud model and export it: ``python -m laas.train_cloud`` (PRD M6-M8).

MiniLM-L6 with a multi-class head over the subtypes. P(spam) is the total probability of the spam
subtypes. Trains on the local GPU with mixed precision, stops early on recall at the cloud FPR gate,
then exports ONNX, quantises to int8 and checks both on CPU: the int8 model may lose at most 0.5
points of recall (M8), and its response times are reported against the 300 ms target (R9).

Same data rules and report as ``laas.train_local``: production mode trains on licence-permitted rows
only, synthetic rows get an equal share of each class (R15), and nothing here is the PRD gate until
the hand-labelled real set exists.
"""

from __future__ import annotations

import argparse
import copy
import json
import random
import time
from collections.abc import Iterator
from datetime import UTC, datetime
from pathlib import Path
from typing import Any

import numpy as np
import numpy.typing as npt
import pyarrow as pa
import pyarrow.compute as arrow_compute
import pyarrow.parquet as pq
from scipy.special import logsumexp

from laas.data.schema import SplitPolicy, assert_trainable
from laas.eval import rates_at, threshold_at_fpr
from laas.train_local import (
    COLUMNS,
    DEFAULT_DATA,
    DEFAULT_RUNS,
    TRAINING_DIR,
    cap_training_rows,
    evaluate,
    gate_thresholds,
    is_synthetic,
    platt_fit,
    results_md,
    synthetic_share_weights,
)

pc: Any = arrow_compute  # pyarrow creates these functions at import; type checkers can't see them
DEFAULT_MODEL = "sentence-transformers/all-MiniLM-L6-v2"
GATE_FPR = 0.003  # PRD §9.4, cloud model
MAX_INT8_RECALL_LOSS = 0.005  # PRD M8
HAM_SUBTYPES = ("enquiry", "support", "other_ham")
SPAM_SUBTYPES = ("solicitation", "bot", "phishing", "crypto", "adult")
SUBTYPES = HAM_SUBTYPES + SPAM_SUBTYPES
SUBTYPE_ID = {name: i for i, name in enumerate(SUBTYPES)}


def subtype_targets(subtypes: list[str], labels: npt.NDArray[np.int_]) -> npt.NDArray[np.int64]:
    """Class index per row. A subtype contradicting its label is a data bug, so it raises."""
    targets = np.array([SUBTYPE_ID[s] for s in subtypes], dtype=np.int64)
    if ((targets >= len(HAM_SUBTYPES)) != (labels == 1)).any():
        raise ValueError("a row's subtype disagrees with its spam/ham label")
    return targets


def spam_logit(logits: npt.NDArray[np.float64]) -> npt.NDArray[np.float64]:
    """log(P(spam) / P(ham)) from raw class logits, without rounding near 0 or 1."""
    n_ham = len(HAM_SUBTYPES)
    out: npt.NDArray[np.float64] = logsumexp(logits[:, n_ham:], axis=1) - logsumexp(
        logits[:, :n_ham], axis=1
    )
    return out


def recall_at_gate(z: npt.NDArray[np.float64], labels: npt.NDArray[np.int_]) -> float:
    return rates_at(z, labels, threshold_at_fpr(z, labels, GATE_FPR))["recall"].value


def length_batches(lengths: list[int], size: int, rng: random.Random) -> Iterator[list[int]]:
    """Shuffled batches of similar-length rows, so little GPU time goes on padding."""
    order = list(range(len(lengths)))
    rng.shuffle(order)
    chunks = [
        sorted(order[i : i + size * 64], key=lambda k: lengths[k])
        for i in range(0, len(order), size * 64)
    ]
    batches = [c[i : i + size] for c in chunks for i in range(0, len(c), size)]
    rng.shuffle(batches)
    yield from batches


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--data", type=Path, default=DEFAULT_DATA)
    parser.add_argument("--extra", type=Path, action="append", default=[], metavar="PARQUET")
    parser.add_argument("--runs", type=Path, default=DEFAULT_RUNS)
    parser.add_argument("--mode", choices=["production", "experiment"], default="production")
    parser.add_argument("--model", default=DEFAULT_MODEL)
    parser.add_argument("--max-len", type=int, default=256)
    parser.add_argument("--batch", type=int, default=32, help="16-32 fits a 6 GB card (M7)")
    parser.add_argument("--lr", type=float, default=2e-5)
    parser.add_argument("--epochs", type=int, default=4)
    parser.add_argument("--patience", type=int, default=1)
    parser.add_argument("--max-train-ham", type=int, default=60_000)
    parser.add_argument("--max-train-spam", type=int, default=60_000)
    parser.add_argument("--seed", type=int, default=13)
    parser.add_argument("--holdout", action="append", default=[], metavar="SOURCE")
    parser.add_argument("--calib-spam-share", type=float, default=0.5)
    parser.add_argument("--synthetic-share", type=float, default=0.5)
    # 0 = the whole production-tier test set. A 4,000-row sample held only about 470 spam rows,
    # so one message moved recall by 0.2 points against the 0.5-point M8 gate.
    parser.add_argument("--quant-eval-rows", type=int, default=0)
    args = parser.parse_args(argv)

    # Heavy imports stay here: the rest of training/ and CI run without the "cloud" extra.
    import onnxruntime as ort
    import torch
    from onnxruntime.quantization import QuantType, quantize_dynamic
    from torch import nn
    from transformers import AutoModelForSequenceClassification, AutoTokenizer

    started = time.perf_counter()

    def log(message: str) -> None:
        print(f"[{time.perf_counter() - started:7.1f}s] {message}", flush=True)

    if not torch.cuda.is_available():
        parser.error("no CUDA GPU visible to PyTorch; install the 'cloud' extra (see agents.md)")
    device = "cuda"
    random.seed(args.seed)
    torch.manual_seed(args.seed)
    rng = np.random.default_rng(args.seed)
    py_rng = random.Random(args.seed)
    production = args.mode == "production"

    def locate(path: Path) -> Path:
        if path.exists() or path.is_absolute() or not (TRAINING_DIR / path).exists():
            return path
        return TRAINING_DIR / path

    columns = [*COLUMNS, "subtype"]
    full = pa.concat_tables(
        [pq.read_table(locate(path), columns=columns) for path in [args.data, *args.extra]]
    )
    full = full.filter(pc.equal(full.column("language"), "en"))
    unknown = set(args.holdout) - set(full.column("source").unique().to_pylist())
    if unknown:
        parser.error(f"unknown --holdout source(s): {', '.join(sorted(unknown))}")
    held = pc.is_in(full.column("source"), value_set=pa.array(args.holdout, pa.string()))
    table, holdout = full.filter(pc.invert(held)), full.filter(held)

    def rows(split: str, trainable_only: bool) -> Any:
        t = table.filter(pc.equal(table.column("corpus_split"), split))
        if trainable_only:
            t = assert_trainable(t.filter(pc.equal(t.column("split_policy"), SplitPolicy.TRAIN)))
        return t

    train = rows("train", production)
    all_labels = np.asarray(train.column("label_id").to_numpy(), dtype=np.int_)
    train = train.take(
        cap_training_rows(
            all_labels, is_synthetic(train), args.max_train_ham, args.max_train_spam, rng
        )
    )
    test_production = rows("test", True)
    sets = {
        "val": rows("val", production),
        "test (production tier)": test_production,
        "test (synthetic only)": test_production.filter(is_synthetic(test_production)),
        "test (all tiers)": rows("test", False),
    }
    if args.holdout:
        sets[f"holdout ({', '.join(args.holdout)})"] = holdout

    y_train = np.asarray(train.column("label_id").to_numpy(), dtype=np.int_)
    targets = subtype_targets(train.column("subtype").to_pylist(), y_train)
    weights, balance = synthetic_share_weights(y_train, is_synthetic(train), args.synthetic_share)
    # Equal total weight per class: subtypes are for the head, the spam/ham split is what's gated.
    for cls in (0, 1):
        weights[y_train == cls] *= len(y_train) / (2 * max(1, int((y_train == cls).sum())))
    log(f"{args.mode}: {train.num_rows:,} training rows ({int(y_train.sum()):,} spam)")

    tokenizer = AutoTokenizer.from_pretrained(args.model)
    log(f"Loading {args.model}")
    model = AutoModelForSequenceClassification.from_pretrained(
        args.model, num_labels=len(SUBTYPES), ignore_mismatched_sizes=True
    ).to(device)

    def encode(texts: list[str]) -> list[list[int]]:
        ids: list[list[int]] = tokenizer(
            texts, truncation=True, max_length=args.max_len, padding=False
        )["input_ids"]
        return ids

    def collate(ids: list[list[int]]) -> dict[str, Any]:
        padded = tokenizer.pad({"input_ids": ids}, padding=True, return_tensors="pt")
        return {k: v.to(device) for k, v in padded.items()}

    def predict(ids: list[list[int]]) -> npt.NDArray[np.float64]:
        model.train(False)  # inference mode: dropout off
        order = sorted(range(len(ids)), key=lambda k: len(ids[k]))
        out = np.zeros(len(ids), dtype=np.float64)
        with torch.no_grad(), torch.amp.autocast("cuda", dtype=torch.float16):
            for start in range(0, len(order), args.batch * 4):
                chunk = order[start : start + args.batch * 4]
                logits = model(**collate([ids[k] for k in chunk])).logits
                out[chunk] = spam_logit(logits.float().cpu().numpy().astype(np.float64))
        return out

    train_ids = encode(train.column("text").to_pylist())
    lengths = [len(x) for x in train_ids]
    set_ids = {name: encode(t.column("text").to_pylist()) for name, t in sets.items()}
    y = {
        name: np.asarray(t.column("label_id").to_numpy(), dtype=np.int_) for name, t in sets.items()
    }
    log("Tokenized")
    val_synthetic = is_synthetic(sets["val"])
    # Early stopping judges contact-form-like ham, not the easy chat that dominates validation.
    val_hard = (y["val"] == 1) | (val_synthetic if val_synthetic.any() else True)

    optimizer = torch.optim.AdamW(model.parameters(), lr=args.lr, weight_decay=0.01)
    steps = args.epochs * (len(train_ids) // args.batch + 1)
    schedule = torch.optim.lr_scheduler.OneCycleLR(
        optimizer, max_lr=args.lr, total_steps=steps, pct_start=0.1
    )
    scaler = torch.amp.GradScaler("cuda")
    loss_fn = nn.CrossEntropyLoss(reduction="none")
    target_t = torch.from_numpy(targets)
    weight_t = torch.from_numpy(weights.astype(np.float32))

    best_score, best_state, best_epoch, history = -1.0, None, -1, []
    for epoch in range(args.epochs):
        model.train()
        running, seen = 0.0, 0
        for step, batch in enumerate(length_batches(lengths, args.batch, py_rng)):
            inputs = collate([train_ids[k] for k in batch])
            index = torch.tensor(batch)
            optimizer.zero_grad(set_to_none=True)
            with torch.amp.autocast("cuda", dtype=torch.float16):
                logits = model(**inputs).logits
                per_row = loss_fn(logits, target_t[index].to(device))
                loss = (per_row * weight_t[index].to(device)).mean()
            scaler.scale(loss).backward()
            scaler.unscale_(optimizer)
            nn.utils.clip_grad_norm_(model.parameters(), 1.0)
            scaler.step(optimizer)
            scaler.update()
            schedule.step()
            running += float(loss) * len(batch)
            seen += len(batch)
            if step % 200 == 0:
                log(f"epoch {epoch + 1} step {step}: loss {running / seen:.4f}")
        score = recall_at_gate(predict(set_ids["val"])[val_hard], y["val"][val_hard])
        history.append({"epoch": epoch + 1, "loss": running / seen, "val_recall_at_gate": score})
        log(f"epoch {epoch + 1}: val recall at {GATE_FPR:.1%} FPR = {score:.2%}")
        if score > best_score:
            best_score, best_epoch = score, epoch
            best_state = copy.deepcopy({k: v.cpu() for k, v in model.state_dict().items()})
        elif epoch - best_epoch >= args.patience:
            log("Stopping early: validation stopped improving")
            break
    assert best_state is not None
    model.load_state_dict(best_state)

    z = {name: predict(ids) for name, ids in set_ids.items()}
    log("Scored evaluation sets")
    a, b = platt_fit(z["val"], y["val"], args.calib_spam_share)
    probs = {name: 1 / (1 + np.exp(-(a * v + b))) for name, v in z.items()}
    t_low, t_high = gate_thresholds(probs["val"], y["val"], val_synthetic, GATE_FPR)
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
            GATE_FPR,
        )
        for name in sets
    ]

    stamp = datetime.now(UTC)
    version = f"cloud-{'v1' if production else 'experiment'}-{stamp:%Y%m%d-%H%M}"
    out = args.runs / f"{stamp:%Y%m%d-%H%M%S}-cloud-{args.mode}"
    out.mkdir(parents=True)
    tokenizer.save_pretrained(out / "tokenizer")

    class LogitsOnly(nn.Module):
        def __init__(self, inner: Any) -> None:
            super().__init__()
            self.inner = inner

        def forward(self, input_ids: Any, attention_mask: Any) -> Any:
            return self.inner(input_ids=input_ids, attention_mask=attention_mask).logits

    model.to("cpu").train(False)
    log("Exporting ONNX")
    fp32_path, int8_path = out / "model.onnx", out / "model.int8.onnx"
    sample = tokenizer(["export sample"], return_tensors="pt")
    torch.onnx.export(
        LogitsOnly(model),
        (sample["input_ids"], sample["attention_mask"]),
        str(fp32_path),
        input_names=["input_ids", "attention_mask"],
        output_names=["logits"],
        dynamic_axes={
            "input_ids": {0: "batch", 1: "sequence"},
            "attention_mask": {0: "batch", 1: "sequence"},
            "logits": {0: "batch"},
        },
        opset_version=17,
        dynamo=False,
    )
    # Per-channel scales: per-tensor int8 lost 2.2-3.0 points of recall at matched FPR on the
    # 7 Nov 2026 model; per-channel lost at most 0.36, at the same 23 MB.
    quantize_dynamic(str(fp32_path), str(int8_path), weight_type=QuantType.QInt8, per_channel=True)

    # M8/R9: compare both on CPU, the way it will be served, one message at a time.
    test = sets["test (production tier)"]
    picks = rng.permutation(test.num_rows)
    if args.quant_eval_rows > 0:
        picks = picks[: args.quant_eval_rows]
    ids_q = [set_ids["test (production tier)"][int(k)] for k in picks]
    y_q = y["test (production tier)"][picks]

    def run_onnx(
        path: Path, rows_ids: list[list[int]]
    ) -> tuple[npt.NDArray[np.float64], list[float]]:
        session = ort.InferenceSession(str(path), providers=["CPUExecutionProvider"])
        scores, times = [], []
        for ids in rows_ids:
            feed = {
                "input_ids": np.array([ids], dtype=np.int64),
                "attention_mask": np.ones((1, len(ids)), dtype=np.int64),
            }
            begin = time.perf_counter()
            logits: Any = session.run(["logits"], feed)[0]
            times.append(time.perf_counter() - begin)
            scores.append(spam_logit(logits.astype(np.float64))[0])
        z_q = np.array(scores, dtype=np.float64)
        return 1 / (1 + np.exp(-(a * z_q + b))), times

    log(f"Comparing fp32 and int8 on {len(ids_q):,} rows on CPU")
    p_fp32, t_fp32 = run_onnx(fp32_path, ids_q)
    p_int8, t_int8 = run_onnx(int8_path, ids_q)
    # Quantising shifts the scores, so int8 serves with its own thresholds from validation.
    log(f"Setting int8 thresholds on {len(set_ids['val']):,} validation rows on CPU")
    p_val_int8, _ = run_onnx(int8_path, set_ids["val"])
    int8_t_low, int8_t_high = gate_thresholds(p_val_int8, y["val"], val_synthetic, GATE_FPR)
    r_fp32, r_int8 = rates_at(p_fp32, y_q, t_high), rates_at(p_int8, y_q, int8_t_high)
    recall_loss = r_fp32["recall"].value - r_int8["recall"].value
    quant = {
        "rows": len(ids_q),
        "int8_t_low": int8_t_low,
        "int8_t_high": int8_t_high,
        "fp32_recall": r_fp32["recall"].value,
        "int8_recall": r_int8["recall"].value,
        "fp32_fpr": r_fp32["fpr"].value,
        "int8_fpr": r_int8["fpr"].value,
        "recall_loss": recall_loss,
        "pass": recall_loss < MAX_INT8_RECALL_LOSS,
        "fp32_mb": fp32_path.stat().st_size / 1e6,
        "int8_mb": int8_path.stat().st_size / 1e6,
        "fp32_ms_p95": float(np.percentile(t_fp32, 95) * 1000),
        "int8_ms_p50": float(np.percentile(t_int8, 50) * 1000),
        "int8_ms_p95": float(np.percentile(t_int8, 95) * 1000),
    }
    meta = {
        "model_version": version,
        "base_model": args.model,
        "mode": args.mode,
        "production": production,
        "trained_at": stamp.isoformat(timespec="seconds"),
        "n_train": train.num_rows,
        "n_train_spam": int(y_train.sum()),
        "subtypes": list(SUBTYPES),
        "max_len": args.max_len,
        "batch": args.batch,
        "lr": args.lr,
        "history": history,
        "best_epoch": best_epoch + 1,
        "platt_a": a,
        "platt_b": b,
        "t_low": t_low,
        "t_high": t_high,
        "holdout": args.holdout,
        "calib_spam_share": args.calib_spam_share,
        "synthetic_balance": balance,
        "seconds": round(time.perf_counter() - started, 1),
    }
    (out / "metrics.json").write_text(
        json.dumps({"meta": meta, "quantisation": quant, "results": results}, indent=2)
    )
    report = "\n".join(
        [
            f"# Cloud model run `{version}`",
            "",
            f"Mode **{args.mode}** · {args.model} · trained {meta['trained_at']} · "
            f"{train.num_rows:,} training rows ({int(y_train.sum()):,} spam) · "
            f"best epoch {best_epoch + 1}",
            "",
            f"Thresholds from validation, on all ham and on synthetic ham alone: "
            f"t_low={t_low:.4f} (<= 5% FPR), t_high={t_high:.4f} (<= {GATE_FPR:.1%} FPR). "
            f"int8 uses its own: t_low={int8_t_low:.4f}, t_high={int8_t_high:.4f}. "
            f"Calibrated as if {args.calib_spam_share:.0%} of messages were spam.",
            "",
            "> Not the PRD gate: no metric here is measured on real contact-form messages.",
            "",
            f"## Quantisation (M8), {quant['rows']:,} rows on CPU",
            "",
            f"Recall fp32 {quant['fp32_recall']:.2%} -> int8 {quant['int8_recall']:.2%}, "
            f"a loss of {recall_loss * 100:.2f} points "
            f"(gate < 0.5: **{'PASS' if quant['pass'] else 'FAIL'}**). "
            f"FPR fp32 {quant['fp32_fpr']:.2%} -> int8 {quant['int8_fpr']:.2%}. "
            f"Size {quant['fp32_mb']:.0f} MB -> {quant['int8_mb']:.0f} MB. "
            f"int8 latency p50 {quant['int8_ms_p50']:.0f} ms, p95 {quant['int8_ms_p95']:.0f} ms "
            "on this PC's CPU (target 300 ms p95 on the server).",
            "",
            results_md(results, GATE_FPR),
        ]
    )
    (out / "report.md").write_text(report, encoding="utf-8")
    print(report)
    print(f"Wrote {out}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
