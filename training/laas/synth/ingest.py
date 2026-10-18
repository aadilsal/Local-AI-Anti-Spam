"""Turn generated JSONL into the dataset schema: ``python -m laas.synth.ingest``.

Reads training/data/synthetic/*.jsonl (the unzipped notebook output) and writes
data/processed/synthetic_v1.parquet plus a manifest.

- ``corpus_split`` comes from a hash of ``template_id`` (80/10/10), so an intent/style template is
  never on both sides of the split (PRD D4).
- ``domain`` is ``syn_<style>`` (or ``syn_comment``), so the per-domain gates report each writing
  style separately: the R13/R14 failure was exactly a style problem.
- Rows from a generator whose licence isn't listed below are refused, never guessed (PRD §13).
"""

from __future__ import annotations

import argparse
import hashlib
import json
import re
from collections import Counter
from datetime import UTC, datetime
from pathlib import Path

import pyarrow as pa
import pyarrow.parquet as pq

from laas.data.schema import SCHEMA, SplitPolicy

TRAINING_DIR = Path(__file__).resolve().parents[2]
DEFAULT_IN = TRAINING_DIR / "data" / "synthetic"
DEFAULT_OUT = TRAINING_DIR / "data" / "processed"

# Generators allowed for production training: licences place no conditions on outputs.
GENERATOR_LICENCES = {
    "qwen2.5-7b": "Generated with Qwen2.5-7B-Instruct (Apache-2.0); outputs ours",
    "phi-4-mini": "Generated with Phi-4-mini-instruct (MIT); outputs ours",
}
URL = re.compile(r"https?://|www\.", re.IGNORECASE)


class UnknownGeneratorError(ValueError):
    """A generator whose licence hasn't been checked and recorded."""


def split_for(template: str) -> str:
    bucket = int(hashlib.sha256(template.encode()).hexdigest(), 16) % 10
    return "test" if bucket == 0 else ("val" if bucket == 1 else "train")


def load_synthetic(paths: list[Path]) -> pa.Table:
    rows: dict[str, list[object]] = {name: [] for name in SCHEMA.names}
    seen: set[str] = set()
    for path in paths:
        with path.open(encoding="utf-8") as fh:
            for line in fh:
                if not line.strip():
                    continue
                r = json.loads(line)
                if r.get("failed"):
                    continue
                licence = GENERATOR_LICENCES.get(r["generator"])
                if licence is None:
                    raise UnknownGeneratorError(
                        f"{path.name}: generator {r['generator']!r} has no recorded licence"
                    )
                text = r["text"].strip()
                key = " ".join(text.lower().split())
                if key in seen:  # exact duplicates across generators and requests
                    continue
                seen.add(key)
                values = {
                    "id": hashlib.sha256(text.encode()).hexdigest()[:16],
                    "text": text,
                    "subject": "",
                    "label": r["label"],
                    "label_id": 1 if r["label"] == "spam" else 0,
                    "subtype": r["subtype"],
                    "domain": "syn_comment" if r["channel"] == "comment" else f"syn_{r['style']}",
                    "language": "en",
                    "source": f"synthetic_{r['generator']}",
                    "licence": licence,
                    "licence_tier": "permissive",
                    "split_policy": SplitPolicy.TRAIN.value,
                    "template_id": r["template_id"],
                    "corpus_split": split_for(r["template_id"]),
                    "has_url": bool(URL.search(text)),
                }
                for name in SCHEMA.names:
                    rows[name].append(values[name])
    return pa.table(rows, schema=SCHEMA)


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--in", dest="src", type=Path, default=DEFAULT_IN)
    parser.add_argument("--out", type=Path, default=DEFAULT_OUT)
    args = parser.parse_args(argv)

    paths = sorted(args.src.rglob("*.jsonl"))
    if not paths:
        parser.error(f"no .jsonl files under {args.src}; unzip synthetic.zip there first")
    table = load_synthetic(paths)
    args.out.mkdir(parents=True, exist_ok=True)
    pq.write_table(table, args.out / "synthetic_v1.parquet", compression="zstd")

    def count(*cols: str) -> dict[str, int]:
        keys = zip(*(table.column(c).to_pylist() for c in cols), strict=True)
        return {"/".join(k): n for k, n in sorted(Counter(keys).items())}

    manifest = {
        "dataset": "synthetic_v1",
        "built_at": datetime.now(UTC).isoformat(timespec="seconds"),
        "rows": table.num_rows,
        "inputs": {p.name: hashlib.sha256(p.read_bytes()).hexdigest() for p in paths},
        "by_split_label": count("corpus_split", "label"),
        "by_source": count("source"),
        "by_domain_label": count("domain", "label"),
    }
    out = args.out / "synthetic_v1.manifest.json"
    out.write_text(json.dumps(manifest, indent=2) + "\n", encoding="utf-8")
    print(f"Wrote {table.num_rows:,} rows to {args.out / 'synthetic_v1.parquet'}")
    for key, n in manifest["by_split_label"].items():
        print(f"  {key:<14} {n:>8,}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
