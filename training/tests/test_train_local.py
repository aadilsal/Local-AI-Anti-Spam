import numpy as np
import pytest

from laas.train_local import synthetic_share_weights


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
