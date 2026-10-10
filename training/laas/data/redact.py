"""Redact real messages on arrival.

Usage: ``python -m laas.data.redact export.csv --site s01 --business plumber``

Removes personal details before anyone reads or labels a message (beta-call terms §3, PRD C3).
Details are replaced with neutral stand-ins of the same kind, so the featurizer still sees "there
was an email / phone / link here" and the test set keeps those signals:

    emails  -> user@example.com
    phones  -> +00 000 000 0000
    URLs    -> scheme://host/   (host kept: link domains are a spam signal, not personal data)
    IPs, UK postcodes -> removed

Names can't be found reliably in free text, so name fields are never exported in the first place:
only the message column is read.
"""

from __future__ import annotations

import argparse
import csv
import hashlib
import json
import re
from datetime import UTC, datetime
from pathlib import Path

TRAINING_DIR = Path(__file__).resolve().parents[2]
INBOX = TRAINING_DIR / "data" / "real" / "inbox"

EMAIL = re.compile(r"[A-Za-z0-9._%+-]+@[A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)+")
URL = re.compile(r"\b(https?://|www\.)([A-Za-z0-9.-]+)[^\s<>\"']*", re.IGNORECASE)
PHONE = re.compile(r"\+?[0-9][0-9 ().-]{6,}[0-9]")
IPV4 = re.compile(r"\b(?:[0-9]{1,3}\.){3}[0-9]{1,3}\b")
UK_POSTCODE = re.compile(r"\b[A-Z]{1,2}[0-9][A-Z0-9]? ?[0-9][A-Z]{2}\b", re.IGNORECASE)


def redact(text: str) -> str:
    text = EMAIL.sub("user@example.com", text)
    text = URL.sub(lambda m: f"{m.group(1)}{m.group(2)}/", text)
    text = IPV4.sub("", text)
    text = PHONE.sub("+00 000 000 0000", text)
    return UK_POSTCODE.sub("", text)


def _rows(path: Path, column: str) -> list[str]:
    if path.suffix.lower() == ".jsonl":
        with path.open(encoding="utf-8") as fh:
            return [str(json.loads(line).get(column, "")) for line in fh if line.strip()]
    with path.open(encoding="utf-8-sig", newline="") as fh:
        return [row.get(column, "") or "" for row in csv.DictReader(fh)]


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("export", type=Path, help="CSV or JSONL export from the site")
    parser.add_argument("--site", required=True, help="Short site id, e.g. s01 (no real names)")
    parser.add_argument("--business", required=True, help='Business type, e.g. "plumber"')
    parser.add_argument("--column", default="message", help="Column holding the message text")
    args = parser.parse_args(argv)

    INBOX.mkdir(parents=True, exist_ok=True)
    out = INBOX / f"{args.site}.jsonl"
    received = datetime.now(UTC).date().isoformat()
    kept = 0
    with out.open("a", encoding="utf-8") as fh:
        for raw in _rows(args.export, args.column):
            text = redact(raw).strip()
            if not text:
                continue
            row_id = hashlib.sha256(f"{args.site}:{text}".encode()).hexdigest()[:16]
            row = {"id": row_id, "site": args.site, "business": args.business, "text": text}
            fh.write(json.dumps({**row, "received": received}, ensure_ascii=False) + "\n")
            kept += 1
    print(f"Redacted {kept} messages into {out}. Now delete the raw export: {args.export}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
