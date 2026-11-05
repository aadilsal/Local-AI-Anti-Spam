"""Rebuild the public dataset from its original sources: ``python -m laas.data.public``.

Downloads each source in LICENCES.md into data/raw/public/ (skipped if already there), maps it to
the dataset schema and writes data/processed/public_v1.parquet plus a manifest with a sha256 per
downloaded file, so the data behind a model can be rebuilt on any machine.

- Licence tier comes from the table below, so ``spamassassin`` and ``enron_spam`` can only ever be
  ``eval_only`` (PRD D5). They are here because the permissive sources contain no email at all and
  the R13 failure was flagging real email as spam.
- Exact duplicates (ignoring case, digits and punctuation) are dropped across all sources, and a
  text seen with both labels is dropped entirely (PRD D3).
- ``corpus_split`` is a hash of the group: the conversation for dialogues, otherwise the opening
  words of the text. All turns of a conversation, and near-duplicates that open the same way, land
  on the same side of the split (PRD D4).
- Archives are read in memory and never extracted, so a crafted path inside one can't write to disk.
"""

from __future__ import annotations

import argparse
import csv
import email
import email.policy
import gzip
import hashlib
import io
import json
import re
import tarfile
import urllib.request
import zipfile
from collections import Counter
from collections.abc import Callable, Iterable, Iterator
from dataclasses import dataclass
from datetime import UTC, datetime
from pathlib import Path

import pyarrow as pa
import pyarrow.parquet as pq

from laas.data.schema import SCHEMA, policy_for_tier

TRAINING_DIR = Path(__file__).resolve().parents[2]
DEFAULT_RAW = TRAINING_DIR / "data" / "raw" / "public"
DEFAULT_OUT = TRAINING_DIR / "data" / "processed"
MAX_CHARS = 8000  # long email bodies add featurizing time, not signal
URL = re.compile(r"https?://|www\.", re.IGNORECASE)
csv.field_size_limit(10_000_000)

_GH = "https://raw.githubusercontent.com"


@dataclass(frozen=True)
class Row:
    text: str
    label: str  # "spam" | "ham"
    group: str = ""  # conversation id; rows without one are grouped by their opening words
    subject: str = ""


@dataclass(frozen=True)
class Source:
    name: str
    licence: str
    tier: str
    domain: str
    subtype_ham: str
    subtype_spam: str
    urls: tuple[str, ...]
    parse: Callable[[list[bytes]], Iterable[Row]]


def _decode(raw: bytes) -> str:
    try:
        return raw.decode("utf-8-sig")
    except UnicodeDecodeError:
        return raw.decode("latin-1")


def parse_uci_sms(files: list[bytes]) -> Iterator[Row]:
    with zipfile.ZipFile(io.BytesIO(files[0])) as archive:
        for line in _decode(archive.read("SMSSpamCollection")).splitlines():
            label, _, text = line.partition("\t")
            if label in ("spam", "ham"):
                yield Row(text, label)


def parse_youtube(files: list[bytes]) -> Iterator[Row]:
    with zipfile.ZipFile(io.BytesIO(files[0])) as archive:
        for name in archive.namelist():
            if name.lower().endswith(".csv") and "__MACOSX" not in name:
                for r in csv.DictReader(io.StringIO(_decode(archive.read(name)))):
                    yield Row(r["CONTENT"], "spam" if r["CLASS"] == "1" else "ham")


def parse_india_sms(files: list[bytes]) -> Iterator[Row]:
    for r in csv.DictReader(io.StringIO(_decode(files[0]))):
        label = r["Label"].strip().lower()
        if label in ("spam", "ham"):
            yield Row(r["Msg"], label)


def parse_bitext(files: list[bytes]) -> Iterator[Row]:
    for r in csv.DictReader(io.StringIO(_decode(files[0]))):
        yield Row(r["instruction"], "ham")


def parse_banking77(files: list[bytes]) -> Iterator[Row]:
    for raw in files:
        for r in csv.DictReader(io.StringIO(_decode(raw))):
            yield Row(r["text"], "ham")


def parse_clinc150(files: list[bytes]) -> Iterator[Row]:
    for split in json.loads(files[0]).values():
        for text, _intent in split:
            yield Row(text, "ham")


def parse_hwu64(files: list[bytes]) -> Iterator[Row]:
    for r in csv.DictReader(io.StringIO(_decode(files[0])), delimiter=";"):
        if r.get("answer"):
            yield Row(r["answer"], "ham")


def parse_snips(files: list[bytes]) -> Iterator[Row]:
    for raw in files:
        for examples in json.loads(_decode(raw)).values():
            for example in examples:
                yield Row("".join(part["text"] for part in example["data"]), "ham")


def parse_abcd(files: list[bytes]) -> Iterator[Row]:
    for split in json.loads(gzip.decompress(files[0])).values():
        for convo in split:
            for speaker, text in convo["original"]:
                if speaker == "customer":
                    yield Row(text, "ham", group=f"abcd:{convo['convo_id']}")


def parse_multiwoz(files: list[bytes]) -> Iterator[Row]:
    for raw in files:
        for dialogue in json.loads(raw):
            for turn in dialogue["turns"]:
                if turn["speaker"] == "USER":
                    yield Row(turn["utterance"], "ham", group=f"woz:{dialogue['dialogue_id']}")


def parse_taskmaster(files: list[bytes]) -> Iterator[Row]:
    for raw in files:
        for dialogue in json.loads(raw):
            for turn in dialogue["utterances"]:
                if turn["speaker"] == "USER":
                    yield Row(turn["text"], "ham", group=f"tm:{dialogue['conversation_id']}")


def email_body(raw: bytes) -> tuple[str, str]:
    """(subject, plain-text body) of a raw email. HTML-only mail comes back with tags stripped."""
    message = email.message_from_bytes(raw, policy=email.policy.default)
    subject = str(message.get("subject", ""))
    part = message.get_body(preferencelist=("plain", "html"))
    if part is None:
        return subject, ""
    try:
        body = part.get_content()
    except (LookupError, ValueError):  # unknown charset or broken encoding in old mail
        payload = part.get_payload(decode=True)
        body = _decode(payload) if isinstance(payload, bytes) else ""
    if part.get_content_type() == "text/html":
        body = re.sub(r"<[^>]+>", " ", body)
    return subject, body


def parse_spamassassin(files: list[bytes]) -> Iterator[Row]:
    for raw in files:
        with tarfile.open(fileobj=io.BytesIO(raw), mode="r:bz2") as archive:
            for member in archive:
                folder = member.name.split("/")[0]
                reader = archive.extractfile(member) if member.isfile() else None
                if reader is None or member.name.endswith("cmds"):
                    continue
                subject, body = email_body(reader.read())
                yield Row(body, "spam" if folder.startswith("spam") else "ham", subject=subject)


def parse_enron(files: list[bytes]) -> Iterator[Row]:
    with zipfile.ZipFile(io.BytesIO(files[0])) as archive:
        text = _decode(archive.read("enron_spam_data.csv"))
    for r in csv.DictReader(io.StringIO(text)):
        yield Row(r["Message"], "spam" if r["Spam/Ham"] == "spam" else "ham", subject=r["Subject"])


_SNIPS = "/sonos/nlu-benchmark/master/2017-06-custom-intent-engines"
_SNIPS_INTENTS = (
    "AddToPlaylist",
    "BookRestaurant",
    "GetWeather",
    "PlayMusic",
    "RateBook",
    "SearchCreativeWork",
    "SearchScreeningEvent",
)
_WOZ = "/budzianowski/multiwoz/master/data/MultiWOZ_2.2/train"
_SA = "https://spamassassin.apache.org/old/publiccorpus"

SOURCES = [
    Source(
        "uci_sms",
        "CC BY 4.0",
        "permissive",
        "sms",
        "other_ham",
        "bot",
        ("https://archive.ics.uci.edu/static/public/228/sms+spam+collection.zip",),
        parse_uci_sms,
    ),
    Source(
        "youtube_spam",
        "CC BY 4.0",
        "permissive",
        "comment",
        "other_ham",
        "bot",
        ("https://archive.ics.uci.edu/static/public/380/youtube+spam+collection.zip",),
        parse_youtube,
    ),
    Source(
        "india_sms",
        "MIT",
        "permissive",
        "sms",
        "other_ham",
        "bot",
        (f"{_GH}/junioralive/india-spam-sms-classification/main/dataset/spam_ham_india.csv",),
        parse_india_sms,
    ),
    Source(
        "bitext_support",
        "CDLA-Sharing-1.0",
        "permissive",
        "support",
        "support",
        "bot",
        (
            f"{_GH}/bitext/customer-support-llm-chatbot-training-dataset/main/data/"
            "Bitext_Sample_Customer_Support_Training_Dataset_27K_responses-v11.csv",
        ),
        parse_bitext,
    ),
    Source(
        "banking77",
        "CC BY 4.0",
        "permissive",
        "assistant",
        "enquiry",
        "bot",
        (
            f"{_GH}/PolyAI-LDN/task-specific-datasets/master/banking_data/train.csv",
            f"{_GH}/PolyAI-LDN/task-specific-datasets/master/banking_data/test.csv",
        ),
        parse_banking77,
    ),
    Source(
        "clinc150",
        "CC BY 3.0",
        "permissive",
        "assistant",
        "enquiry",
        "bot",
        (f"{_GH}/clinc/oos-eval/master/data/data_full.json",),
        parse_clinc150,
    ),
    Source(
        "hwu64",
        "CC BY 4.0",
        "permissive",
        "assistant",
        "enquiry",
        "bot",
        (
            f"{_GH}/xliuhw/NLU-Evaluation-Data/master/AnnotatedData/"
            "NLU-Data-Home-Domain-Annotated-All.csv",
        ),
        parse_hwu64,
    ),
    Source(
        "snips",
        "CC0 1.0",
        "permissive",
        "assistant",
        "enquiry",
        "bot",
        tuple(f"{_GH}{_SNIPS}/{i}/train_{i}_full.json" for i in _SNIPS_INTENTS),
        parse_snips,
    ),
    Source(
        "abcd",
        "MIT",
        "permissive",
        "support",
        "support",
        "bot",
        (f"{_GH}/asappresearch/abcd/master/data/abcd_v1.1.json.gz",),
        parse_abcd,
    ),
    Source(
        "multiwoz",
        "MIT",
        "permissive",
        "chat",
        "enquiry",
        "bot",
        tuple(f"{_GH}{_WOZ}/dialogues_{i:03d}.json" for i in range(1, 6)),
        parse_multiwoz,
    ),
    Source(
        "taskmaster",
        "CC BY 4.0",
        "permissive",
        "chat",
        "enquiry",
        "bot",
        (
            f"{_GH}/google-research-datasets/Taskmaster/master/TM-1-2019/self-dialogs.json",
            f"{_GH}/google-research-datasets/Taskmaster/master/TM-1-2019/woz-dialogs.json",
        ),
        parse_taskmaster,
    ),
    Source(
        "spamassassin",
        "Copyright remains with original senders",
        "restricted",
        "email",
        "other_ham",
        "bot",
        tuple(
            f"{_SA}/{name}.tar.bz2"
            for name in (
                "20030228_easy_ham",
                "20030228_easy_ham_2",
                "20030228_hard_ham",
                "20030228_spam",
                "20050311_spam_2",
            )
        ),
        parse_spamassassin,
    ),
    Source(
        "enron_spam",
        "No licence stated",
        "unclear",
        "email",
        "other_ham",
        "bot",
        ("https://github.com/MWiechmann/enron_spam_data/raw/master/enron_spam_data.zip",),
        parse_enron,
    ),
]
SOURCE_BY_NAME = {s.name: s for s in SOURCES}


def dedupe_key(text: str) -> str:
    return re.sub(r"[^a-z]+", " ", text.lower()).strip()


def split_for(group: str) -> str:
    bucket = int(hashlib.sha256(group.encode()).hexdigest(), 16) % 10
    return "test" if bucket == 0 else ("val" if bucket == 1 else "train")


def group_for(row: Row, key: str) -> str:
    return row.group or " ".join(key.split()[:8])


def build_table(sources: Iterable[tuple[Source, Iterable[Row]]]) -> pa.Table:
    kept: dict[str, tuple[Source, Row, str]] = {}
    conflicted: set[str] = set()
    for source, parsed in sources:
        for row in parsed:
            text = row.text.strip()[:MAX_CHARS]
            key = dedupe_key(text)
            if len(key) < 2 or key in conflicted:
                continue
            first = kept.get(key)
            if first is None:
                kept[key] = (source, Row(text, row.label, row.group, row.subject), key)
            elif first[1].label != row.label:  # same words, both labels: neither can be trusted
                del kept[key]
                conflicted.add(key)

    rows: dict[str, list[object]] = {name: [] for name in SCHEMA.names}
    for source, row, key in kept.values():
        group = group_for(row, key)
        values = {
            "id": hashlib.sha256(f"{source.name}:{row.text}".encode()).hexdigest()[:16],
            "text": row.text,
            "subject": row.subject,
            "label": row.label,
            "label_id": 1 if row.label == "spam" else 0,
            "subtype": source.subtype_spam if row.label == "spam" else source.subtype_ham,
            "domain": source.domain,
            "language": "en",
            "source": source.name,
            "licence": source.licence,
            "licence_tier": source.tier,
            "split_policy": policy_for_tier(source.tier).value,
            "template_id": group,
            "corpus_split": split_for(group),
            "has_url": bool(URL.search(row.text)),
        }
        for name in SCHEMA.names:
            rows[name].append(values[name])
    return pa.table(rows, schema=SCHEMA)


def fetch(source: Source, raw_dir: Path) -> list[Path]:
    """Download a source's files unless already present. A partial download never counts."""
    folder = raw_dir / source.name
    folder.mkdir(parents=True, exist_ok=True)
    paths = []
    for url in source.urls:
        path = folder / url.rsplit("/", 1)[-1].replace("+", "_")
        if not path.exists():
            print(f"  downloading {url}", flush=True)
            partial = path.with_suffix(path.suffix + ".part")
            request = urllib.request.Request(url, headers={"User-Agent": "laas-training"})
            with urllib.request.urlopen(request, timeout=300) as response, partial.open("wb") as fh:
                while block := response.read(1 << 20):
                    fh.write(block)
            partial.replace(path)
        paths.append(path)
    return paths


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--raw", type=Path, default=DEFAULT_RAW)
    parser.add_argument("--out", type=Path, default=DEFAULT_OUT)
    args = parser.parse_args(argv)

    inputs: dict[str, str] = {}

    def load() -> Iterator[tuple[Source, Iterable[Row]]]:
        for source in SOURCES:
            print(source.name, flush=True)
            files = []
            for path in fetch(source, args.raw):
                raw = path.read_bytes()
                inputs[f"{source.name}/{path.name}"] = hashlib.sha256(raw).hexdigest()
                files.append(raw)
            yield source, source.parse(files)

    table = build_table(load())
    args.out.mkdir(parents=True, exist_ok=True)
    pq.write_table(table, args.out / "public_v1.parquet", compression="zstd")

    def count(*cols: str) -> dict[str, int]:
        keys = zip(*(table.column(c).to_pylist() for c in cols), strict=True)
        return {"/".join(k): n for k, n in sorted(Counter(keys).items())}

    manifest = {
        "dataset": "public_v1",
        "built_at": datetime.now(UTC).isoformat(timespec="seconds"),
        "rows": table.num_rows,
        "inputs": inputs,
        "by_source_label": count("source", "label"),
        "by_split_label": count("corpus_split", "label"),
        "by_policy_label": count("split_policy", "label"),
    }
    (args.out / "public_v1.manifest.json").write_text(
        json.dumps(manifest, indent=2) + "\n", encoding="utf-8"
    )
    print(f"Wrote {table.num_rows:,} rows to {args.out / 'public_v1.parquet'}")
    for key, n in manifest["by_source_label"].items():
        print(f"  {key:<24} {n:>9,}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
