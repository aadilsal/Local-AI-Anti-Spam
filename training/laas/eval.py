"""Evaluation metrics for the gates in PRD §9.4 (FPR, recall, calibration, keyword baseline)."""

from __future__ import annotations

import math
import re
from dataclasses import dataclass

import numpy as np
import numpy.typing as npt

FloatArray = npt.NDArray[np.float64]
IntArray = npt.NDArray[np.int_]


@dataclass(frozen=True)
class Rate:
    """A proportion with its Wilson 95% interval (review note R4)."""

    k: int
    n: int
    low: float
    high: float

    @property
    def value(self) -> float:
        return self.k / self.n if self.n else float("nan")


def wilson(k: int, n: int, z: float = 1.96) -> Rate:
    if n == 0:
        return Rate(k, n, float("nan"), float("nan"))
    p = k / n
    denom = 1 + z * z / n
    centre = (p + z * z / (2 * n)) / denom
    half = z * math.sqrt(p * (1 - p) / n + z * z / (4 * n * n)) / denom
    return Rate(k, n, max(0.0, centre - half), min(1.0, centre + half))


def threshold_at_fpr(scores: FloatArray, labels: IntArray, max_fpr: float) -> float:
    """Lowest threshold t such that the share of ham with score >= t is at most ``max_fpr``."""
    ham = np.sort(scores[labels == 0])[::-1]
    allowed = math.floor(max_fpr * len(ham))
    if allowed >= len(ham):
        return 0.0
    # Spam is flagged at score >= t; put t just above the (allowed+1)-th highest ham score.
    return float(np.nextafter(ham[allowed], np.inf))


def rates_at(scores: FloatArray, labels: IntArray, threshold: float) -> dict[str, Rate]:
    flagged = scores >= threshold
    ham, spam = labels == 0, labels == 1
    return {
        "fpr": wilson(int((flagged & ham).sum()), int(ham.sum())),
        "recall": wilson(int((flagged & spam).sum()), int(spam.sum())),
    }


def ece(probs: FloatArray, labels: IntArray, bins: int = 10) -> float:
    """Expected calibration error with equal-width bins."""
    edges = np.linspace(0.0, 1.0, bins + 1)
    idx = np.clip(np.digitize(probs, edges[1:-1]), 0, bins - 1)
    total = 0.0
    for b in range(bins):
        mask = idx == b
        if mask.any():
            total += mask.mean() * abs(float(probs[mask].mean()) - float(labels[mask].mean()))
    return total


def roc_auc(scores: FloatArray, labels: IntArray) -> float:
    order = np.argsort(scores, kind="mergesort")
    ranks = np.empty(len(scores), dtype=np.float64)
    sorted_scores = scores[order]
    start = 0
    for i in range(1, len(scores) + 1):
        if i == len(scores) or sorted_scores[i] != sorted_scores[start]:
            ranks[order[start:i]] = (start + 1 + i) / 2  # average rank for ties
            start = i
    n_pos = int((labels == 1).sum())
    n_neg = len(labels) - n_pos
    if n_pos == 0 or n_neg == 0:
        return float("nan")
    return float((ranks[labels == 1].sum() - n_pos * (n_pos + 1) / 2) / (n_pos * n_neg))


KEYWORDS = re.compile(
    r"\b(free|winner|won|prize|click|buy now|offer|discount|viagra|casino|loan|crypto|bitcoin|"
    r"investment|guaranteed|seo|backlinks?|ranking|first page|traffic|unsubscribe|limited time|"
    r"act now|urgent|verify your account|claim|cash|earn money|work from home)\b",
    re.IGNORECASE,
)
URL = re.compile(r"https?://|www\.", re.IGNORECASE)


def keyword_baseline(texts: list[str]) -> IntArray:
    """The rule baseline every model must beat (PRD §9.4): two keyword hits, or a link plus one."""
    out = np.zeros(len(texts), dtype=np.int_)
    for i, text in enumerate(texts):
        hits = len(KEYWORDS.findall(text))
        out[i] = int(hits >= 2 or (hits >= 1 and URL.search(text) is not None))
    return out
