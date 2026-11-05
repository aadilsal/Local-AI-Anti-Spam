import random

import numpy as np
import pytest

from laas.train_cloud import HAM_SUBTYPES, SUBTYPES, length_batches, spam_logit, subtype_targets


def test_targets_refuse_a_subtype_that_contradicts_the_label() -> None:
    assert subtype_targets(["enquiry", "bot"], np.array([0, 1])).tolist() == [
        SUBTYPES.index("enquiry"),
        SUBTYPES.index("bot"),
    ]
    with pytest.raises(ValueError):
        subtype_targets(["bot"], np.array([0]))


def test_spam_logit_adds_up_the_spam_subtypes() -> None:
    logits = np.log(np.array([[0.1, 0.05, 0.05, 0.4, 0.2, 0.1, 0.05, 0.05]]))
    n_ham = len(HAM_SUBTYPES)
    assert n_ham == 3
    assert spam_logit(logits)[0] == pytest.approx(np.log(0.8 / 0.2))


def test_spam_logit_keeps_precision_at_the_extremes() -> None:
    logits = np.array([[0.0, 0.0, 0.0, 60.0, 0.0, 0.0, 0.0, 0.0]])
    assert spam_logit(logits)[0] == pytest.approx(60.0 - np.log(3.0))


def test_length_batches_cover_every_row_once() -> None:
    lengths = [random.Random(i).randint(1, 200) for i in range(1000)]
    batches = list(length_batches(lengths, 32, random.Random(0)))
    assert sorted(k for b in batches for k in b) == list(range(1000))
    assert max(len(b) for b in batches) <= 32
