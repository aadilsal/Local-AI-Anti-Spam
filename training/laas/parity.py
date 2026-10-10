"""Generate the PHP <-> Python parity fixtures: ``python -m laas.parity``.

Writes a small seeded random model (parity/parity-model.bin) and parity/fixtures.json with, for each
input, the features and score Python computes. The PHP ParityTest asserts PHP gets the same.
All inputs are invented; never put real submissions here (the repo is public).
"""

from __future__ import annotations

import argparse
import json
import random
from pathlib import Path
from typing import Any

import numpy as np

from laas.featurize import FEATURIZER_VERSION, featurize
from laas.model_file import write_model

REPO_ROOT = Path(__file__).resolve().parents[2]
PARITY_DIR = REPO_ROOT / "parity"
PARITY_BUCKETS = 1 << 16
FEATURE_DUMP_LIMIT = 400  # full feature lists only for small cases; keeps fixtures.json small

HANDWRITTEN: list[tuple[str, str | None]] = [
    ("", None),
    ("   \n\t  ", None),
    ("Hi", None),
    ("Hi, can I book a table for four on Friday at 7pm?", "sarah@example.co.uk"),
    ("Hello! Do you offer a free consultation for kitchen refits?", "j.okafor@example.com"),
    ("I noticed your website isn't ranking on Google's first page. We can fix that!", None),
    (
        "Get BACKLINKS now!!! Visit https://seo-pros.example/offer?id=42 today",
        "mark@seo-pros.example",
    ),
    ("Visit www.example.com/page. Or http://sub.domain.example.co.uk:8080/x#y", None),
    ("Email me at someone.name+tag@mail.example.org or call +44 (0)20 7946 0958", None),
    ("Order 12345 arrived on 2026-10-10, invoice 99.50", None),
    ("CONGRATULATIONS YOU HAVE WON A PRIZE CLICK NOW", None),
    ("Don't 'quote' me on it, it's fine '' ' '", None),
    ("Crypto investment: 10x returns guaranteed, DM on telegram", None),
    ("Ρlеаsе сlісk hеrе tо vеrіfу уоur ассоunt", None),  # Cyrillic/Greek look-alikes
    ("fr​ee mo‌ney⁠ n﻿ow so­on", None),  # zero-width and soft hyphen
    ("Café crème, naïve résumé, Zürich, 東京, Привет мир", None),
    ("emoji test 😀🔥 buy now 💰💰 http://xn--example.example", None),
    ("multiple   spaces\n\nand\r\nnewlines\tand\ttabs", None),
    ("UPPER lower MiXeD", "Person@Example.COM"),
    (
        "link to my own site http://example.com and https://www.example.com/contact",
        "me@example.com",
    ),
    ('<script>alert(1)</script> <a href="http://evil.example">x</a>', None),
    ("phone-ish 2019-2020 and 1-800-555-0199 and (555) 010-9999", None),
    ("see http://example.com/Åsa/page and more", None),  # Å = C3 85: \v-vs-PCRE trap
    ("visit http://exämple.example/ and https://ünï.example.co.uk/x", None),  # non-ASCII hosts
    ("vertical\x0btab and form\x0cfeed and nel\u0085here", None),
    ("a b c d", None),
    ("one two three four five", None),
    ("http://", None),
    ("www.", None),
    ("@@@ ... ### $$$", None),
    ("x" * 9000, None),  # over the 8,000-byte cap
    ("é" * 4001, None),  # cap falls inside a two-byte character
    ("word " * 2000, None),
]


def generated_cases(seed: int, n: int) -> list[tuple[str, str | None]]:
    rng = random.Random(seed)
    words = [
        "hi",
        "hello",
        "quote",
        "free",
        "offer",
        "seo",
        "ranking",
        "backlinks",
        "invoice",
        "booking",
        "table",
        "friday",
        "please",
        "thanks",
        "click",
        "here",
        "now",
        "win",
        "prize",
        "crypto",
        "loan",
        "urgent",
        "account",
        "verify",
        "kitchen",
        "plumber",
    ]
    extras = ["https://a.example/x", "www.b.example.co.uk", "c@d.example", "+1 555 010 1234", "42"]
    cases: list[tuple[str, str | None]] = []
    for _ in range(n):
        parts = [rng.choice(words) for _ in range(rng.randint(1, 40))]
        if rng.random() < 0.5:
            parts.insert(rng.randrange(len(parts) + 1), rng.choice(extras))
        text = " ".join(p.upper() if rng.random() < 0.1 else p for p in parts)
        email = rng.choice([None, "x@b.example.co.uk", "y@a.example"])
        cases.append((text, email))
    return cases


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--out", type=Path, default=PARITY_DIR)
    parser.add_argument("--seed", type=int, default=20261010)
    parser.add_argument("--generated", type=int, default=200)
    args = parser.parse_args(argv)

    rng = np.random.default_rng(args.seed)
    model = write_model(
        args.out / "parity-model.bin",
        weights=rng.normal(0, 0.5, PARITY_BUCKETS),
        dense_weights=rng.normal(0, 0.5, 7),
        bias=-1.25,
        dense_means=[0.05, 0.05, 0.1, 0.5, 0.0, 0.3, 0.2],
        dense_stds=[0.1, 0.1, 0.1, 0.2, 0.0, 0.4, 0.4],
        platt_a=0.9,
        platt_b=-0.1,
        t_low=0.5,
        t_high=0.92,
        model_version="parity-fixture",
    )

    cases: list[dict[str, Any]] = []
    for text, email in HANDWRITTEN + generated_cases(args.seed, args.generated):
        f = featurize(text, email, PARITY_BUCKETS)
        case: dict[str, Any] = {
            "text": text,
            "email": email,
            "n_features": len(f.hashed),
            "dense": f.dense,
            "logit": model.logit(f),
            "score": model.score(text, email),
        }
        if len(f.hashed) <= FEATURE_DUMP_LIMIT:
            case["features"] = sorted([b, v] for b, v in f.hashed.items())
        cases.append(case)

    fixtures = {
        "featurizer_version": FEATURIZER_VERSION,
        "model": "parity-model.bin",
        "n_buckets": PARITY_BUCKETS,
        "cases": cases,
    }
    (args.out / "fixtures.json").write_text(
        json.dumps(fixtures, ensure_ascii=False, separators=(",", ":")) + "\n", encoding="utf-8"
    )
    print(f"Wrote {len(cases)} parity cases to {args.out / 'fixtures.json'}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
