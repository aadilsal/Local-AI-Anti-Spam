"""Turn generated JSONL into the dataset schema: ``python -m laas.synth.ingest``.

Reads training/data/synthetic/*.jsonl (the unzipped notebook output) and writes
data/processed/synthetic_v1.parquet plus a manifest.

- ``corpus_split``: each intent with 5 or more styles gives one whole style to test and one to val,
  so those templates are never on both sides (PRD D4) and every such intent is measured. Intents
  with fewer styles are split 70/15/15 by message, so they are measured on seen styles.
- ``domain`` is ``syn_<style>`` (or ``syn_comment``), so the per-domain gates report each writing
  style separately: the R13/R14 failure was exactly a style problem.
- Rows from a generator whose licence isn't listed below are refused, never guessed (PRD §13).
- Rows whose text contradicts their label are left out and counted in the manifest
  (``label_problem``). The rules are rough keyword checks from the error analysis of 7 Nov 2026.
"""

from __future__ import annotations

import argparse
import hashlib
import json
import re
from collections import Counter
from datetime import UTC, datetime
from pathlib import Path

import pyarrow as pa
import pyarrow.parquet as pq

from laas.data.schema import SCHEMA, SplitPolicy
from laas.synth.gen import complete_lines
from laas.synth.spec import clean_messages

TRAINING_DIR = Path(__file__).resolve().parents[2]
DEFAULT_IN = TRAINING_DIR / "data" / "synthetic"
DEFAULT_OUT = TRAINING_DIR / "data" / "processed"

# Generators allowed for production training: licences place no conditions on outputs.
GENERATOR_LICENCES = {
    "qwen2.5-7b": "Generated with Qwen2.5-7B-Instruct (Apache-2.0); outputs ours",
    "phi-4-mini": "Generated with Phi-4-mini-instruct (MIT); outputs ours",
}
URL = re.compile(r"https?://|www\.", re.IGNORECASE)
NOISY_TEMPLATE = re.compile(r"^p[123]:[^:]+:fake_enquiry_pitch:")  # spam labels that read as ham
_BARE_DOMAIN = r"\b[\w-]+\.(?:com|net|org|co|ng|in|pk|uk|au|ca|ie|info|biz)\b"
COMMENT_PROMOTION = re.compile(
    rf"https?://|www\.|{_BARE_DOMAIN}|\bcheck (?:them |it |this )?out\b"
    r"|\bvisit (?:our|my|their|us)\b",
    re.IGNORECASE,
)
HAM_SELLING = re.compile(
    r"\bwe(?:\Wre| are) offering\b|\bvisit our\b|\bcheck out our\b|\b(?:email|contact) us at\b"
    r"|\bcall us (?:at|on)\b"
    rf"|\b(?:visit|check out)\b[^.!?\n]{{0,50}}(?:https?://|www\.|{_BARE_DOMAIN})",
    re.IGNORECASE,
)
PITCH_INTENTS = {"seo_pitch", "web_design_pitch", "lead_gen_pitch"}
# The model sometimes writes a pitch from the buyer's side. Labelled spam, that would teach the
# model to block real enquiries to web agencies.
PITCH_BUYER_VOICE = re.compile(
    r"\bcan (?:you|u|your \w+) (?:help|assist|fix|quote|give|offer)\b|\b(?:our|my) (?:web)?site\b"
    r"|\bplease (?:help|fix)\b|\b(?:we|i) need\b|\bany leads\b|\blooking for an?\b"
    r"|\b(?:we|i)\W?(?:re|m| are| am) looking for\b",
    re.IGNORECASE,
)
PITCH_SELLER_VOICE = re.compile(
    r"\b(?:we|i) (?:can|offer|provide|speciali[sz]e|help)\b|\bmy services\b"
    r"|\bour (?:services|team|agency|company|firm|solutions?)\b"
    r"|\b(?:your|ur) (?:web)?site\b|\b(?:your|ur) (?:business|company|firm|online|rankings?)\b"
    r"|\b(?:visit|check out) (?:our|my|us)\b",
    re.IGNORECASE,
)
SCAM_ORDER_TELL = re.compile(
    r"overpa|\bcheque|\bcheck for\b|\bpay by check\b|shipper|courier|shipping (?:agent|company)"
    r"|wire transfer|western union|bank transfer|money order|difference back|any price"
    r"|(?:don't|do not) care",
    re.IGNORECASE,
)
PHISHING_TELL = re.compile(
    r"verif|click|log ?in|password|suspend|expir|renew|legal notice|copyright|overdue|attached"
    r"|link (?:below|here)",
    re.IGNORECASE,
)
CUSTOMER_VOICE = re.compile(
    r"\bi (?:recently |just )?(?:received|ordered|placed|booked|used)\b"
    r"|\bmy (?:website|site|invoice|order|business email)\b",
    re.IGNORECASE,
)


class UnknownGeneratorError(ValueError):
    """A generator whose licence hasn't been checked and recorded."""


MIN_STYLES_TO_HOLD_OUT = 5  # an intent needs this many styles to give one each to test and val


def _template_key(template: str) -> str:
    # Without the prompt version, so a template stays on one side across versions and generators.
    return re.sub(r"^p\d+:", "", template)


def _hash(text: str) -> int:
    return int(hashlib.sha256(text.encode()).hexdigest(), 16)


def template_splits(templates: set[str]) -> dict[str, str]:
    """Hold out whole styles, so test and val measure styles the model never saw (PRD D4).

    Every intent with at least MIN_STYLES_TO_HOLD_OUT styles gives exactly one style to test and
    one to val. A plain hash over all templates left whole intents (vague_callback, crypto) out of
    test and val. Intents with fewer styles aren't listed here; ``split_for`` splits them by row.
    """
    by_intent: dict[str, list[str]] = {}
    for key in {_template_key(t) for t in templates}:
        intent = key.rsplit(":", 1)[0]  # channel:intent
        by_intent.setdefault(intent, []).append(key)
    splits: dict[str, str] = {}
    for keys in by_intent.values():
        if len(keys) < MIN_STYLES_TO_HOLD_OUT:
            continue
        ordered = sorted(keys, key=_hash)
        splits.update({key: "train" for key in ordered[2:]})
        splits[ordered[0]], splits[ordered[1]] = "test", "val"
    return splits


def split_for(template: str, text: str, splits: dict[str, str]) -> str:
    """The template's split, or for intents with too few styles, 70/15/15 by message text.

    Rows split by text share their style with training rows, so those intents score as if seen.
    """
    held = splits.get(_template_key(template))
    if held is not None:
        return held
    bucket = _hash(" ".join(text.lower().split())) % 20
    return "test" if bucket < 3 else ("val" if bucket < 6 else "train")


def label_problem(row: dict[str, str], text: str) -> str:
    """Why a generated row's text contradicts its label, or "" if it doesn't."""
    if NOISY_TEMPLATE.match(row["template_id"]):
        return "old_fake_enquiry_pitch"
    intent = row["intent"]
    if row["label"] == "ham" and HAM_SELLING.search(text):
        return "ham_selling"
    if intent == "genuine_comment" and COMMENT_PROMOTION.search(text):
        return "comment_promotion"
    if intent == "advance_fee_order" and not SCAM_ORDER_TELL.search(text):
        return "scam_order_without_tell"
    if intent == "phishing" and (not PHISHING_TELL.search(text) or CUSTOMER_VOICE.search(text)):
        return "phishing_reads_as_customer"
    if (
        intent in PITCH_INTENTS
        and PITCH_BUYER_VOICE.search(text)
        and not PITCH_SELLER_VOICE.search(text)
    ):
        return "pitch_reads_as_buyer"
    return ""


def load_synthetic(paths: list[Path], dropped: Counter[str] | None = None) -> pa.Table:
    rows: dict[str, list[object]] = {name: [] for name in SCHEMA.names}
    seen: set[str] = set()
    dropped = Counter() if dropped is None else dropped
    kept: list[dict[str, object]] = []
    for path in paths:
        # Read-only: the file may belong to a stopped run, or one still writing.
        for line in complete_lines(path).decode("utf-8").split("\n"):
            if not line.strip():
                continue
            r = json.loads(line)
            if r.get("failed"):
                continue
            licence = GENERATOR_LICENCES.get(r["generator"])
            if licence is None:
                raise UnknownGeneratorError(
                    f"{path.name}: generator {r['generator']!r} has no recorded licence"
                )
            text = r["text"].strip()
            problem = label_problem(r, text)
            if problem:
                dropped[problem] += 1
                continue
            key = " ".join(text.lower().split())
            if key in seen:  # exact duplicates across generators and requests
                continue
            if not clean_messages([text]):  # same filter as generation, for older outputs too
                continue
            seen.add(key)
            values = {
                "id": hashlib.sha256(text.encode()).hexdigest()[:16],
                "text": text,
                "subject": "",
                "label": r["label"],
                "label_id": 1 if r["label"] == "spam" else 0,
                "subtype": r["subtype"],
                "domain": "syn_comment" if r["channel"] == "comment" else f"syn_{r['style']}",
                "language": "en",
                "source": f"synthetic_{r['generator']}",
                "licence": licence,
                "licence_tier": "permissive",
                "split_policy": SplitPolicy.TRAIN.value,
                "template_id": r["template_id"],
                "corpus_split": "",  # set below, once every template is known
                "has_url": bool(URL.search(text)),
            }
            kept.append(values)
    splits = template_splits({str(v["template_id"]) for v in kept})
    for values in kept:
        values["corpus_split"] = split_for(str(values["template_id"]), str(values["text"]), splits)
        for name in SCHEMA.names:
            rows[name].append(values[name])
    return pa.table(rows, schema=SCHEMA)


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--in", dest="src", type=Path, default=DEFAULT_IN)
    parser.add_argument("--out", type=Path, default=DEFAULT_OUT)
    args = parser.parse_args(argv)

    paths = sorted(args.src.rglob("*.jsonl"))
    if not paths:
        parser.error(f"no .jsonl files under {args.src}; unzip synthetic.zip there first")
    dropped: Counter[str] = Counter()
    table = load_synthetic(paths, dropped)
    args.out.mkdir(parents=True, exist_ok=True)
    pq.write_table(table, args.out / "synthetic_v1.parquet", compression="zstd")

    def count(*cols: str) -> dict[str, int]:
        keys = zip(*(table.column(c).to_pylist() for c in cols), strict=True)
        return {"/".join(k): n for k, n in sorted(Counter(keys).items())}

    manifest = {
        "dataset": "synthetic_v1",
        "built_at": datetime.now(UTC).isoformat(timespec="seconds"),
        "rows": table.num_rows,
        "inputs": {p.name: hashlib.sha256(p.read_bytes()).hexdigest() for p in paths},
        "by_split_label": count("corpus_split", "label"),
        "by_source": count("source"),
        "by_domain_label": count("domain", "label"),
        "dropped_label_problems": dict(sorted(dropped.items())),
    }
    out = args.out / "synthetic_v1.manifest.json"
    out.write_text(json.dumps(manifest, indent=2) + "\n", encoding="utf-8")
    print(f"Wrote {table.num_rows:,} rows to {args.out / 'synthetic_v1.parquet'}")
    print(f"Left out for label problems: {dict(sorted(dropped.items()))}")
    for key, n in manifest["by_split_label"].items():
        print(f"  {key:<14} {n:>8,}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
