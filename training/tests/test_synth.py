import argparse
import io
import json
from collections import Counter
from pathlib import Path

import pytest

from laas.synth import notebook
from laas.synth.gen import write
from laas.synth.ingest import UnknownGeneratorError, load_synthetic, split_for
from laas.synth.spec import (
    BUSINESSES,
    INTENTS,
    PROMPT_VERSION,
    STYLE_BY_KEY,
    build_requests,
    clean_messages,
    parse_response,
)


def test_requests_are_deterministic_and_cover_the_grid() -> None:
    a = build_requests(3000, seed=1, generator="qwen2.5-7b")
    b = build_requests(3000, seed=1, generator="qwen2.5-7b")
    assert a == b
    assert len({r.request_id for r in a}) == 3000
    assert {r.intent for r in a} == {i.key for i in INTENTS}
    assert len({r.business for r in a}) >= 20  # PRD D6
    labels = Counter(r.label for r in a)
    assert 0.4 < labels["ham"] / len(a) < 0.7


def test_each_request_uses_a_style_its_intent_allows() -> None:
    intents = {i.key: i for i in INTENTS}
    for r in build_requests(2000, seed=2, generator="phi-4-mini"):
        assert r.style in intents[r.intent].styles
        assert r.style in STYLE_BY_KEY
        assert r.template_id == f"p{PROMPT_VERSION}:{r.channel}:{r.intent}:{r.style}"


def test_ham_prompts_ask_for_some_links_and_spam_prompts_say_who_sells() -> None:
    reqs = build_requests(400, seed=4, generator="qwen2.5-7b")
    ham = next(r for r in reqs if r.label == "ham").messages[1][1]
    spam = next(r for r in reqs if r.label == "spam").messages[1][1]
    assert "one in four writers mentions a link" in ham  # pilot review: no links in v1 ham
    assert "writing TO the" in spam and "not one of its customers" in spam


def test_generators_get_different_requests() -> None:
    a = build_requests(50, seed=1, generator="qwen2.5-7b")
    b = build_requests(50, seed=1, generator="phi-4-mini")
    assert [r.request_id for r in a] != [r.request_id for r in b]


@pytest.mark.parametrize(
    ("reply", "expected"),
    [
        ('["one", "two"]', ["one", "two"]),
        ('Sure!\n```json\n["one"]\n```', ["one"]),
        ('[{"text": "one"}, {"message": "two"}]', ["one", "two"]),
        ("no json here", []),
        ("[not valid json", []),
    ],
)
def test_parse_response(reply: str, expected: list[str]) -> None:
    assert parse_response(reply) == expected


def test_clean_messages_drops_junk_but_keeps_real_phrasing() -> None:
    texts = [
        "Here are photos of the leak under the sink.",
        "As an AI, I can't write spam.",
        "Hi [Name], great site",
        "Hi {name}, quick question about your SEO",
        "1. Hi, can I book a table?",
        "Message 2: Do you deliver to Leeds?",
        "Subject: Quote request\nHi, how much for a boiler service?",
        "  ",
        "here are photos of the leak under the sink.",  # duplicate after normalising
        "Hi, two questions:\n1. Do you work weekends?\n2. Do you cover Leeds?",  # a real list
    ]
    assert clean_messages(texts) == [
        "Here are photos of the leak under the sink.",
        "Hi, two questions:\n1. Do you work weekends?\n2. Do you cover Leeds?",
    ]


def _row(generator: str, text: str, template: str = "p1:contact_form:booking:short_note") -> str:
    return json.dumps(
        {
            "request_id": "r1",
            "template_id": template,
            "intent": "booking",
            "label": "ham",
            "subtype": "enquiry",
            "channel": "contact_form",
            "style": "short_note",
            "generator": generator,
            "text": text,
        }
    )


def test_ingest_maps_schema_dedupes_and_skips_failures(tmp_path: Path) -> None:
    path = tmp_path / "out.jsonl"
    lines = [
        _row("qwen2.5-7b", "Table for 2 on Friday?"),
        _row("phi-4-mini", "table for 2 on friday?"),  # duplicate across generators
        json.dumps({"request_id": "r2", "generator": "qwen2.5-7b", "failed": True}),
        _row("phi-4-mini", "Can I move my booking to 7pm?"),
    ]
    path.write_text("\n".join(lines) + "\n", encoding="utf-8")
    table = load_synthetic([path])
    assert table.num_rows == 2
    assert set(table.column("split_policy").to_pylist()) == {"train"}
    assert table.column("domain").to_pylist() == ["syn_short_note", "syn_short_note"]
    expected_split = split_for("p1:contact_form:booking:short_note")
    assert set(table.column("corpus_split").to_pylist()) == {expected_split}


def test_ingest_refuses_unknown_generators(tmp_path: Path) -> None:
    path = tmp_path / "out.jsonl"
    path.write_text(_row("some-unlicensed-model", "hello") + "\n", encoding="utf-8")
    with pytest.raises(UnknownGeneratorError):
        load_synthetic([path])


def test_writer_records_failed_requests_for_resume() -> None:
    req = build_requests(1, seed=3, generator="qwen2.5-7b")[0]
    args = argparse.Namespace(generator="qwen2.5-7b")
    fh = io.StringIO()
    assert write(fh, req, '["Hi, do you cover Leeds?", "Need a quote pls"]', args, "ckpt") == 2
    assert write(fh, req, "I'm sorry, I can't do that.", args, "ckpt") == 0
    rows = [json.loads(line) for line in fh.getvalue().splitlines()]
    assert [r.get("failed", False) for r in rows] == [False, False, True]
    assert all(r["request_id"] == req.request_id for r in rows)


def test_committed_notebook_matches_the_code() -> None:
    assert notebook.main(["--check"]) == 0, "run: python -m laas.synth.notebook"


def test_businesses_have_descriptions() -> None:
    assert all(name and what for name, what in BUSINESSES)
