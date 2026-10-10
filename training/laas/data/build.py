"""Build the processed dataset: ``python -m laas.data.build``.

Writes ``corpus_v1.parquet`` in our schema plus ``manifest.json`` (input hashes and counts), so any
model can be traced back to the exact data it saw.
"""

from __future__ import annotations

import argparse
import hashlib
import json
from collections import Counter
from datetime import UTC, datetime
from pathlib import Path

import pyarrow.parquet as pq

from laas.data.corpus import corpus_files, load_corpus

TRAINING_DIR = Path(__file__).resolve().parents[2]
DEFAULT_CORPUS = TRAINING_DIR / "data" / "raw" / "spam_ham_corpus_v1"
DEFAULT_OUT = TRAINING_DIR / "data" / "processed"


def sha256_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as fh:
        for block in iter(lambda: fh.read(1 << 20), b""):
            digest.update(block)
    return digest.hexdigest()


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--corpus", type=Path, default=DEFAULT_CORPUS)
    parser.add_argument("--out", type=Path, default=DEFAULT_OUT)
    args = parser.parse_args(argv)

    table = load_corpus(args.corpus)
    args.out.mkdir(parents=True, exist_ok=True)
    pq.write_table(table, args.out / "corpus_v1.parquet", compression="zstd")

    counts = Counter(
        zip(
            table.column("split_policy").to_pylist(), table.column("label").to_pylist(), strict=True
        )
    )
    manifest = {
        "dataset": "corpus_v1",
        "built_at": datetime.now(UTC).isoformat(timespec="seconds"),
        "rows": table.num_rows,
        "inputs": {f.name: sha256_file(f) for f in corpus_files(args.corpus)},
        "counts": {f"{policy}/{label}": n for (policy, label), n in sorted(counts.items())},
    }
    (args.out / "manifest.json").write_text(json.dumps(manifest, indent=2) + "\n", encoding="utf-8")

    print(f"Wrote {table.num_rows:,} rows to {args.out / 'corpus_v1.parquet'}")
    for key, n in manifest["counts"].items():
        print(f"  {key:<20} {n:>9,}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
