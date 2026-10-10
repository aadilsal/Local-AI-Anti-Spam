"""Featurizer shared with PHP (plugin/src/Inference/Featurizer.php). See architecture.md §4.1.

Every rule here has a byte-for-byte twin in PHP. Keep them in step: change both in one commit, bump
FEATURIZER_VERSION, and regenerate the parity fixtures. Regex classes are ASCII-only on purpose so
Python's ``re`` and PHP's PCRE (without the ``u`` flag) match exactly the same spans.
"""

from __future__ import annotations

import itertools
import math
import re
import zlib
from dataclasses import dataclass, field

FEATURIZER_VERSION = 1
MAX_BYTES = 8000
CHAR_NGRAMS = (3, 4, 5)
# Char n-grams cost ~3 per byte; capping their input keeps an 8 KB message inside the 20 ms budget.
CHAR_MAX_BYTES = 3000

ZERO_WIDTH = "​‌‍‎‏⁠﻿­"

# Cyrillic and Greek letters that look like Latin ones.
HOMOGLYPHS = {
    "а": "a", "е": "e", "о": "o", "р": "p", "с": "c", "у": "y", "х": "x", "і": "i", "ј": "j",
    "ѕ": "s", "А": "A", "В": "B", "Е": "E", "К": "K", "М": "M", "Н": "H", "О": "O", "Р": "P",
    "С": "C", "Т": "T", "Х": "X", "α": "a", "ο": "o", "ν": "v", "Α": "A", "Β": "B", "Ε": "E",
    "Ζ": "Z", "Η": "H", "Ι": "I", "Κ": "K", "Μ": "M", "Ν": "N", "Ο": "O", "Ρ": "P", "Τ": "T",
    "Υ": "Y", "Χ": "X",
}  # fmt: skip

# Second-level labels under which the registrable domain has three labels (example.co.uk).
SECOND_LEVEL = frozenset(
    ["co.uk", "org.uk", "ac.uk", "gov.uk", "com.au", "net.au", "org.au", "co.nz", "co.in",
     "com.pk", "co.za", "com.br", "co.jp"]
)  # fmt: skip

DENSE_NAMES = (
    "link_count",
    "distinct_link_domains",
    "caps_ratio",
    "log_length",
    "email_domain_matches_link",
    "has_email",
    "short_message",
)

_TRANSLATE = str.maketrans({**{c: None for c in ZERO_WIDTH}, **HOMOGLYPHS})
_LOWER = str.maketrans("ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")
# \x0b, not \v: in PCRE "\v" means any vertical whitespace (including byte 0x85), unlike Python.
URL_RE = re.compile(r"(?:https?://|www\.)[^ \t\n\r\f\x0b<>\"']+")
EMAIL_RE = re.compile(r"[a-z0-9._%+-]+@[a-z0-9-]+(?:\.[a-z0-9-]+)+")
PHONE_RE = re.compile(r"\+?[0-9][0-9 ().-]{6,}[0-9]")
NUM_RE = re.compile(r"[0-9]+")
WS_RE = re.compile(r"[ \t\n\r\f\x0b]+")
TOKEN_SPLIT_RE = re.compile(r"[^a-z0-9_']+")
DOMAIN_TOKEN_RE = re.compile(r"[^a-z0-9]+")
SCHEME_RE = re.compile(r"^(?:https?://)?(?:www\.)?")
HOST_END_RE = re.compile(r"[/?#:]")


@dataclass
class Features:
    """Hashed sparse features (bucket -> value), raw dense features, token strings per bucket."""

    hashed: dict[int, float]
    dense: list[float]
    names: dict[int, str] = field(default_factory=dict)  # for explanations; unigrams only


def truncate_utf8(text: str, max_bytes: int = MAX_BYTES) -> str:
    return text.encode("utf-8")[:max_bytes].decode("utf-8", "ignore")


def hash_feature(feature: bytes, n_buckets: int) -> tuple[int, float]:
    h = zlib.crc32(feature)
    return h & (n_buckets - 1), (-1.0 if h >> 31 else 1.0)


def registrable_domain(url: str) -> str:
    host = SCHEME_RE.sub("", url, count=1)
    host = HOST_END_RE.split(host, maxsplit=1)[0].rstrip(".,;!?)]}")
    labels = [label for label in host.split(".") if label]
    if len(labels) >= 3 and ".".join(labels[-2:]) in SECOND_LEVEL:
        return ".".join(labels[-3:])
    return ".".join(labels[-2:])


def normalise(text: str) -> tuple[str, float, list[str]]:
    """Return (normalised text, caps ratio, link domains in order of appearance)."""
    text = truncate_utf8(text).translate(_TRANSLATE)

    letters = sum(1 for c in text if "a" <= c <= "z" or "A" <= c <= "Z")
    caps = sum(1 for c in text if "A" <= c <= "Z")
    caps_ratio = caps / letters if letters else 0.0

    text = text.translate(_LOWER)
    domains: list[str] = []

    def _url(m: re.Match[str]) -> str:
        domain = registrable_domain(m.group(0))
        domains.append(domain)
        return " __url__ __dom_" + DOMAIN_TOKEN_RE.sub("_", domain) + "__ "

    text = URL_RE.sub(_url, text)
    text = EMAIL_RE.sub(" __email__ ", text)
    text = PHONE_RE.sub(" __phone__ ", text)
    text = NUM_RE.sub(" __num__ ", text)
    text = WS_RE.sub(" ", text).strip(" ")
    return text, caps_ratio, domains


def _add_family(counts: dict[bytes, int], out: dict[int, float], n_buckets: int) -> None:
    family: dict[int, float] = {}
    for feature, count in counts.items():
        bucket, sign = hash_feature(feature, n_buckets)
        family[bucket] = family.get(bucket, 0.0) + sign * math.log1p(count)
    norm = math.sqrt(sum(v * v for v in family.values()))
    if norm > 0:
        for bucket, value in family.items():
            out[bucket] = out.get(bucket, 0.0) + value / norm


def featurize(text: str, email: str | None = None, n_buckets: int = 1 << 19) -> Features:
    norm, caps_ratio, domains = normalise(text)

    tokens = [t.strip("'") for t in TOKEN_SPLIT_RE.split(norm)]
    tokens = [t for t in tokens if t]

    words: dict[bytes, int] = {}
    names: dict[int, str] = {}
    for tok in tokens:
        key = b"w:" + tok.encode()
        words[key] = words.get(key, 0) + 1
        names.setdefault(hash_feature(key, n_buckets)[0], tok)
    for a, b in itertools.pairwise(tokens):
        key = b"b:" + a.encode() + b" " + b.encode()
        words[key] = words.get(key, 0) + 1

    padded = b" " + norm.encode("utf-8")[:CHAR_MAX_BYTES] + b" "
    chars: dict[bytes, int] = {}
    for n in CHAR_NGRAMS:
        for i in range(len(padded) - n + 1):
            key = b"c:" + padded[i : i + n]
            chars[key] = chars.get(key, 0) + 1

    hashed: dict[int, float] = {}
    _add_family(words, hashed, n_buckets)
    _add_family(chars, hashed, n_buckets)

    email_domain = email.rsplit("@", 1)[-1].translate(_LOWER) if email and "@" in email else ""
    dense = [
        min(len(domains), 10) / 10,
        min(len(set(domains)), 5) / 5,
        caps_ratio,
        math.log1p(len(norm.encode("utf-8"))) / 10,
        1.0 if email_domain and registrable_domain(email_domain) in domains else 0.0,
        1.0 if email_domain else 0.0,
        1.0 if len(tokens) < 5 else 0.0,
    ]
    return Features(hashed=hashed, dense=dense, names=names)
