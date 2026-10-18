from pathlib import Path

import numpy as np
import pytest

from laas.eval import ece, mix_weights, roc_auc, threshold_at_fpr, wilson
from laas.featurize import MAX_BYTES, featurize, normalise, registrable_domain
from laas.model_file import read_model, write_model


@pytest.mark.parametrize(
    ("url", "domain"),
    [
        ("https://www.shop.example.co.uk/x?y=1", "example.co.uk"),
        ("http://sub.example.com:8080/page", "example.com"),
        ("www.example.org.", "example.org"),
        ("example.com.pk", "example.com.pk"),
        ("http://", ""),
    ],
)
def test_registrable_domain(url: str, domain: str) -> None:
    assert registrable_domain(url) == domain


def test_normalise_replaces_entities_and_folds_case() -> None:
    text, caps, domains = normalise(
        "Call +44 20 7946 0958 or EMAIL a.b@example.com, see https://x.example.co.uk/p"
    )
    assert text == "call __phone__ or email __email__ , see __url__ __dom_example_co_uk__"
    assert domains == ["example.co.uk"]
    assert 0.0 < caps < 0.5


def test_homoglyphs_and_zero_width_are_folded() -> None:
    plain, _, _ = normalise("free money")
    disguised, _, _ = normalise("fr​ее mоnеy")  # zero-width + Cyrillic е/о
    assert disguised == plain


def test_truncation_never_splits_a_character() -> None:
    text, _, _ = normalise("é" * (MAX_BYTES // 2 + 5))
    assert len(text.encode("utf-8")) <= MAX_BYTES
    assert text == "é" * (MAX_BYTES // 2)


def test_featurize_is_deterministic_with_sender_features() -> None:
    a = featurize("Get backlinks now https://seo.example/offer", "x@seo.example")
    b = featurize("Get backlinks now https://seo.example/offer", "x@seo.example")
    assert a.hashed == b.hashed and a.dense == b.dense
    assert a.dense[4] == 1.0  # sender domain matches the link domain
    assert a.dense[5] == 1.0  # has an email
    assert a.dense[6] == 0.0  # five tokens, so not "short"
    assert "backlinks" in a.names.values()


def test_empty_text_has_no_hashed_features() -> None:
    assert featurize("   ").hashed == {}


def test_model_file_round_trip_and_tamper_detection(tmp_path: Path) -> None:
    path = tmp_path / "model.bin"
    model = write_model(
        path,
        weights=np.linspace(-1, 1, 1 << 10),
        dense_weights=np.zeros(7),
        bias=0.0,
        dense_means=[0.0] * 7,
        dense_stds=[1.0] * 7,
        platt_a=1.0,
        platt_b=0.0,
        t_low=0.5,
        t_high=0.9,
        model_version="test",
    )
    assert read_model(path).n_buckets == 1 << 10
    assert 0.0 < model.score("hello there") < 1.0

    blob = bytearray(path.read_bytes())
    blob[-1] ^= 0xFF
    path.write_bytes(bytes(blob))
    with pytest.raises(ValueError, match="checksum"):
        read_model(path)


def test_wilson_upper_bound_with_zero_errors() -> None:
    r = wilson(0, 1000)
    assert r.low == 0.0
    assert r.high == pytest.approx(0.00383, abs=1e-5)  # why 1,000 ham can't prove 0.3% (R4)


def test_threshold_at_fpr_respects_the_limit() -> None:
    rng = np.random.default_rng(0)
    scores = rng.random(10_000)
    labels = (rng.random(10_000) < 0.2).astype(np.int_)
    t = threshold_at_fpr(scores, labels, 0.005)
    fpr = ((scores >= t) & (labels == 0)).sum() / (labels == 0).sum()
    assert fpr <= 0.005


def test_mix_weights_reweight_to_target_share() -> None:
    labels = np.array([1] + [0] * 99)  # 1% spam
    w = mix_weights(labels, 0.5)
    assert np.isclose(w[labels == 1].sum(), w[labels == 0].sum())  # now looks like 50% spam
    # Scores of 0.5 everywhere are perfectly calibrated at a 50/50 mix, badly at the raw 1% mix.
    probs = np.full(100, 0.5)
    assert ece(probs, labels, weights=w) == pytest.approx(0.0)
    assert ece(probs, labels) == pytest.approx(0.49)


def test_auc_and_ece_on_perfect_scores() -> None:
    labels = np.array([0, 0, 1, 1])
    scores = np.array([0.0, 0.0, 1.0, 1.0])
    assert roc_auc(scores, labels) == 1.0
    assert ece(scores, labels) == 0.0
