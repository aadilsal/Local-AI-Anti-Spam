"""Loader for the merged public corpus ``spam_ham_corpus_v1`` (see its README)."""

from __future__ import annotations

from pathlib import Path

import pyarrow as pa
import pyarrow.parquet as pq

from laas.data.schema import SCHEMA, policy_for_tier

SOURCE_COLUMNS = [
    "id",
    "text",
    "subject",
    "label",
    "label_id",
    "subtype",
    "domain",
    "language",
    "source",
    "licence",
    "licence_tier",
    "group_id",
    "split",
    "has_url",
]


def corpus_files(root: Path) -> list[Path]:
    """The corpus parquet files in a stable order: train shards, then val, then test."""
    data = root / "data"
    files = [*sorted(data.glob("train-*.parquet")), data / "val.parquet", data / "test.parquet"]
    missing = [f for f in files if not f.is_file()]
    if missing or len(files) < 3:
        raise FileNotFoundError(
            f"Corpus incomplete under {data}: missing {missing or 'train shards'}"
        )
    return files


def load_corpus(root: Path) -> pa.Table:
    """Read the corpus and map it to our schema, with a split policy per row from its licence."""
    table = pa.concat_tables(pq.read_table(f, columns=SOURCE_COLUMNS) for f in corpus_files(root))

    policies = {
        tier: policy_for_tier(tier).value
        for tier in table.column("licence_tier").unique().to_pylist()
    }
    split_policy = pa.array(
        [policies[t] for t in table.column("licence_tier").to_pylist()], pa.string()
    )

    columns = {
        "id": table.column("id").cast(pa.string()),
        "text": table.column("text").cast(pa.string()),
        "subject": table.column("subject").cast(pa.string()),
        "label": table.column("label").cast(pa.string()),
        "label_id": table.column("label_id").cast(pa.int8()),
        "subtype": table.column("subtype").cast(pa.string()),
        "domain": table.column("domain").cast(pa.string()),
        "language": table.column("language").cast(pa.string()),
        "source": table.column("source").cast(pa.string()),
        "licence": table.column("licence").cast(pa.string()),
        "licence_tier": table.column("licence_tier").cast(pa.string()),
        "split_policy": split_policy,
        "template_id": table.column("group_id").cast(pa.string()),
        "corpus_split": table.column("split").cast(pa.string()),
        "has_url": table.column("has_url").cast(pa.bool_()),
    }
    return pa.table(columns, schema=SCHEMA)
