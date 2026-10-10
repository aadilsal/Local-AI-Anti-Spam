"""Shared dataset schema (PRD D1) and the split-policy guard (PRD D5).

Every row carries a ``split_policy``. Only ``train`` rows may reach production training; the guard
raises rather than filters, so a mistake fails loudly instead of silently changing the training set.
"""

from __future__ import annotations

from enum import StrEnum

import pyarrow as pa


class SplitPolicy(StrEnum):
    TRAIN = "train"  # licence allows commercial training
    EVAL_ONLY = "eval_only"  # experiments and evaluation, never production training
    TEST_ONLY = "test_only"  # the hand-labelled real test set: never trained on


# Corpus licence tiers (see the corpus README) mapped to our policy. Only explicitly permissive
# licences may train a production model (PRD §13 "Dataset licensing").
TIER_POLICY: dict[str, SplitPolicy] = {
    "permissive": SplitPolicy.TRAIN,
    "unclear": SplitPolicy.EVAL_ONLY,
    "restricted": SplitPolicy.EVAL_ONLY,
}

SCHEMA = pa.schema(
    [
        ("id", pa.string()),
        ("text", pa.string()),
        ("subject", pa.string()),
        ("label", pa.string()),  # "spam" | "ham"
        ("label_id", pa.int8()),  # 1 | 0
        ("subtype", pa.string()),
        ("domain", pa.string()),
        ("language", pa.string()),
        ("source", pa.string()),
        ("licence", pa.string()),
        ("licence_tier", pa.string()),
        ("split_policy", pa.string()),
        ("template_id", pa.string()),  # near-duplicate group or synthetic template (PRD D4)
        ("corpus_split", pa.string()),  # the upstream train/val/test split, for experiments
        ("has_url", pa.bool_()),
    ]
)


class SplitPolicyError(ValueError):
    """Raised when a row that may not be trained on is about to be."""


def policy_for_tier(tier: str) -> SplitPolicy:
    try:
        return TIER_POLICY[tier]
    except KeyError:
        raise SplitPolicyError(
            f"Unknown licence tier {tier!r}; refusing to guess a policy"
        ) from None


def assert_trainable(table: pa.Table) -> pa.Table:
    """Return ``table`` unchanged if every row has policy ``train``; raise otherwise."""
    policies = set(table.column("split_policy").to_pylist())
    bad = policies - {SplitPolicy.TRAIN.value}
    if bad:
        raise SplitPolicyError(
            f"Rows with split_policy {sorted(bad)} cannot enter production training"
        )
    return table
