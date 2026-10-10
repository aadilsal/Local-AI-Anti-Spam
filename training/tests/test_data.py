from pathlib import Path

import pyarrow as pa
import pyarrow.compute as pc
import pyarrow.parquet as pq
import pytest

from laas.data.corpus import load_corpus
from laas.data.schema import (
    SCHEMA,
    SplitPolicy,
    SplitPolicyError,
    assert_trainable,
    policy_for_tier,
)


def _rows(split: str, tiers: list[str]) -> pa.Table:
    n = len(tiers)
    return pa.table(
        {
            "id": [f"{split}-{i}" for i in range(n)],
            "text": ["Hi, can I book a table for four?"] * n,
            "subject": [""] * n,
            "label": ["ham"] * n,
            "label_id": pa.array([0] * n, pa.int64()),
            "subtype": ["enquiry"] * n,
            "domain": ["enquiry"] * n,
            "language": ["en"] * n,
            "source": ["example_source"] * n,
            "licence": ["CC BY 4.0"] * n,
            "licence_tier": tiers,
            "group_id": pa.array(list(range(n)), pa.int64()),
            "split": [split] * n,
            "has_url": [False] * n,
        }
    )


@pytest.fixture
def fake_corpus(tmp_path: Path) -> Path:
    data = tmp_path / "data"
    data.mkdir()
    pq.write_table(
        _rows("train", ["permissive", "unclear", "restricted"]),
        data / "train-00000-of-00001.parquet",
    )
    pq.write_table(_rows("val", ["permissive"]), data / "val.parquet")
    pq.write_table(_rows("test", ["unclear"]), data / "test.parquet")
    return tmp_path


def test_only_permissive_licences_may_train() -> None:
    assert policy_for_tier("permissive") is SplitPolicy.TRAIN
    assert policy_for_tier("unclear") is SplitPolicy.EVAL_ONLY
    assert policy_for_tier("restricted") is SplitPolicy.EVAL_ONLY


def test_unknown_tier_is_refused() -> None:
    with pytest.raises(SplitPolicyError):
        policy_for_tier("probably-fine")


def test_guard_raises_on_any_non_train_row(fake_corpus: Path) -> None:
    table = load_corpus(fake_corpus)
    with pytest.raises(SplitPolicyError):
        assert_trainable(table)


def test_guard_passes_train_only_rows(fake_corpus: Path) -> None:
    table = load_corpus(fake_corpus)
    train_only = table.filter(pc.equal(table.column("split_policy"), "train"))
    assert assert_trainable(train_only).num_rows == 2


def test_loader_maps_to_schema(fake_corpus: Path) -> None:
    table = load_corpus(fake_corpus)
    assert table.schema.equals(SCHEMA)
    assert table.num_rows == 5
    assert table.column("split_policy").to_pylist() == [
        "train",
        "eval_only",
        "eval_only",
        "train",
        "eval_only",
    ]
    assert table.column("template_id").to_pylist()[:3] == ["0", "1", "2"]
    assert table.column("corpus_split").to_pylist() == ["train", "train", "train", "val", "test"]


def test_loader_reports_missing_files(tmp_path: Path) -> None:
    (tmp_path / "data").mkdir()
    with pytest.raises(FileNotFoundError):
        load_corpus(tmp_path)
