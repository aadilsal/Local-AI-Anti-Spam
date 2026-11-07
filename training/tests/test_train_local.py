import numpy as np
import pytest

from laas.train_local import cap_training_rows, gate_thresholds, synthetic_share_weights


def test_caps_apply_to_public_rows_and_every_synthetic_row_is_kept() -> None:
    labels = np.array([0] * 100 + [0] * 10 + [1] * 20 + [1] * 5)
    synthetic = np.array([False] * 100 + [True] * 10 + [False] * 20 + [True] * 5)
    rows = cap_training_rows(labels, synthetic, 30, 8, np.random.default_rng(1))
    assert rows.tolist() == sorted(rows.tolist())
    assert synthetic[rows].sum() == 15
    assert ((labels[rows] == 0) & ~synthetic[rows]).sum() == 30
    assert ((labels[rows] == 1) & ~synthetic[rows]).sum() == 8


def test_thresholds_hold_on_the_harder_synthetic_ham() -> None:
    # 1,000 easy public ham near 0, 200 synthetic ham spread up to 0.95, and spam at 0.99.
    probs = np.concatenate([np.full(1000, 0.01), np.linspace(0.0, 0.95, 200), np.full(100, 0.99)])
    labels = np.array([0] * 1200 + [1] * 100)
    synthetic = np.array([False] * 1000 + [True] * 200 + [False] * 100)
    t_low, t_high = gate_thresholds(probs, labels, synthetic, 0.005)
    ham = probs[200 * [False] + 800 * [False] + 200 * [True] + 100 * [False]]
    assert (ham >= t_high).mean() <= 0.005
    assert t_low <= t_high
    pooled_low, pooled_high = gate_thresholds(probs, labels, np.zeros(1300, dtype=bool), 0.005)
    assert pooled_high <= t_high and pooled_low <= t_low


def test_synthetic_share_is_equal_in_both_classes_and_class_totals_are_kept() -> None:
    # The pilot's shape: synthetic is a big part of spam and a sliver of ham (R15).
    labels = np.array([1] * 100 + [0] * 1000)
    synthetic = np.array([True] * 25 + [False] * 75 + [True] * 7 + [False] * 993)
    weights, info = synthetic_share_weights(labels, synthetic, 0.5)

    for cls in (0, 1):
        in_class = labels == cls
        assert weights[in_class].sum() == pytest.approx(in_class.sum())  # class total unchanged
        share = weights[in_class & synthetic].sum() / weights[in_class].sum()
        assert share == pytest.approx(0.5)
    assert info["spam"]["share_before"] == pytest.approx(0.25)
    assert info["ham"]["share_before"] == pytest.approx(0.007)
    assert info["ham"]["share_after"] == pytest.approx(0.5)


def test_class_without_synthetic_rows_is_left_alone() -> None:
    labels = np.array([1, 1, 0, 0])
    synthetic = np.array([True, False, False, False])
    weights, info = synthetic_share_weights(labels, synthetic, 0.5)
    assert weights[2:].tolist() == [1.0, 1.0]
    assert info["ham"]["share_after"] == 0.0
    assert weights[:2].tolist() == [1.0, 1.0]  # spam: 1 synthetic + 1 real at share 0.5 = 1 each
