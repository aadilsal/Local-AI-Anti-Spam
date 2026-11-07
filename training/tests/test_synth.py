import argparse
import io
import json
import urllib.error
from collections import Counter
from email.message import Message
from pathlib import Path

import pytest

from laas.synth import gen, notebook
from laas.synth.gen import write
from laas.synth.ingest import (
    UnknownGeneratorError,
    label_problem,
    load_synthetic,
    split_for,
    template_splits,
)
from laas.synth.spec import (
    BUSINESSES,
    FOCUS_GRID,
    INTENT_BY_KEY,
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
    ham = next(r for r in reqs if r.label == "ham" and r.channel == "contact_form").messages[1][1]
    comment = next(r for r in reqs if r.intent == "genuine_comment").messages[1][1]
    spam = next(r for r in reqs if r.label == "spam").messages[1][1]
    assert "one in four writers mentions a link" in ham  # pilot review: no links in v1 ham
    assert "include no links" in comment  # first model runs: linked comments read as promotion
    assert "writing TO the" in spam and "not one of its customers" in spam


def test_focus_requests_stick_to_their_grid_and_differ_from_the_main_run() -> None:
    allowed = {(key, style) for key, styles, _ in FOCUS_GRID for style in styles}
    focus = build_requests(1000, seed=1, generator="qwen2.5-7b", focus=True)
    assert all((r.intent, r.style) in allowed for r in focus)
    assert {key for key, _, _ in FOCUS_GRID} == {r.intent for r in focus}
    assert 0.4 < sum(r.label == "ham" for r in focus) / len(focus) < 0.65
    assert {r.style for r in focus if r.label == "spam"} >= {"typo_heavy", "angry", "rambling"}
    main = {r.request_id for r in build_requests(1000, seed=1, generator="qwen2.5-7b")}
    assert not main & {r.request_id for r in focus}


def test_long_styles_ask_for_fewer_messages() -> None:
    reqs = build_requests(400, seed=5, generator="qwen2.5-7b", focus=True)
    long_prompt = next(r for r in reqs if r.style == "long_email").messages[1][1]
    short_prompt = next(r for r in reqs if r.style == "short_note").messages[1][1]
    assert "Write 2 different messages" in long_prompt
    assert "Write 5 different messages" in short_prompt


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
    # One style only, so rows split by text.
    expected = [
        split_for("p1:contact_form:booking:short_note", t, {}) for t in table["text"].to_pylist()
    ]
    assert table.column("corpus_split").to_pylist() == expected


def test_ingest_refuses_unknown_generators(tmp_path: Path) -> None:
    path = tmp_path / "out.jsonl"
    path.write_text(_row("some-unlicensed-model", "hello") + "\n", encoding="utf-8")
    with pytest.raises(UnknownGeneratorError):
        load_synthetic([path])


@pytest.mark.parametrize(
    ("intent", "label", "text", "problem"),
    [
        ("genuine_comment", "ham", "Great post, more at www.mysite.com", "comment_promotion"),
        ("genuine_comment", "ham", "Loved this post, thanks for the tips", ""),
        ("vague_callback", "ham", "We're offering lawn care, call back", "ham_selling"),
        ("booking", "ham", "Can I book? See www.mysite.com for my venue", ""),
        ("advance_fee_order", "spam", "Please quote me for 40 lessons", "scam_order_without_tell"),
        ("advance_fee_order", "spam", "I will overpay by cheque", ""),
        ("phishing", "spam", "I received your invoice, verify it", "phishing_reads_as_customer"),
        ("phishing", "spam", "Hello, can we talk about things", "phishing_reads_as_customer"),
        ("phishing", "spam", "Your domain expires today, renew here", ""),
        ("seo_pitch", "spam", "I can fix your Google ranking", ""),
        ("seo_pitch", "spam", "Our website isn't ranking, can you help", "pitch_reads_as_buyer"),
        ("lead_gen_pitch", "spam", "Looking for a social media manager", "pitch_reads_as_buyer"),
        ("web_design_pitch", "spam", "Your site is slow, visit our site redo.com", ""),
        ("vague_callback", "ham", "Visit RoofPros.com for our guttering", "ham_selling"),
        ("thanks_feedback", "ham", "Thanks! Check out my shop at www.mine.com", "ham_selling"),
        ("service_question", "ham", "I visited your site www.you.com, hours?", ""),
    ],
)
def test_label_problem(intent: str, label: str, text: str, problem: str) -> None:
    row = {"intent": intent, "label": label, "template_id": f"p5:contact_form:{intent}:short_note"}
    assert label_problem(row, text) == problem


def test_ingest_leaves_out_fake_enquiry_pitch_from_old_prompts(tmp_path: Path) -> None:
    path = tmp_path / "out.jsonl"
    lines = [
        _row("phi-4-mini", "old prompt pitch", "p3:contact_form:fake_enquiry_pitch:short_note"),
        _row("qwen2.5-7b", "new prompt pitch", "p4:contact_form:fake_enquiry_pitch:short_note"),
        _row("phi-4-mini", "old prompt booking", "p3:contact_form:booking:short_note"),
    ]
    path.write_text("\n".join(lines) + "\n", encoding="utf-8")
    assert load_synthetic([path]).column("text").to_pylist() == [
        "new prompt pitch",
        "old prompt booking",
    ]


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


def test_ollama_backend_writes_rows_and_lets_server_errors_retry(
    monkeypatch: pytest.MonkeyPatch, tmp_path: Path
) -> None:
    fail = {"now": True}

    def fake_post(
        url: str, path: str, payload: dict[str, object] | None = None
    ) -> dict[str, object]:
        if path == "/api/tags":
            return {"models": [{"name": "m:7b", "digest": "abcdef0123456789"}]}
        assert payload is not None
        assert payload["format"] == {"type": "array", "items": {"type": "string"}}
        if fail["now"]:
            raise OSError("server down")
        return {"message": {"content": '["first message here", "second message here"]'}}

    monkeypatch.setattr(gen, "ollama_post", fake_post)
    out = tmp_path / "out.jsonl"
    argv = ["--generator", "qwen2.5-7b", "--model", "m:7b", "--backend", "ollama"]
    argv += ["--n-requests", "3", "--out", str(out)]

    with pytest.raises(OSError):  # a dead server must not mark requests as permanently failed
        gen.main(argv)
    assert not out.read_text(encoding="utf-8")

    fail["now"] = False
    assert gen.main(argv) == 0
    rows = [json.loads(line) for line in out.read_text(encoding="utf-8").splitlines()]
    assert len(rows) == 6
    assert rows[0]["checkpoint"] == "m:7b@abcdef012345"
    assert gen.main(argv) == 0  # resumes: nothing left to do
    assert len(out.read_text(encoding="utf-8").splitlines()) == 6


def test_power_cut_leaves_a_partial_line_that_resume_and_ingest_survive(
    monkeypatch: pytest.MonkeyPatch, tmp_path: Path
) -> None:
    def fake_post(
        url: str, path: str, payload: dict[str, object] | None = None
    ) -> dict[str, object]:
        if path == "/api/tags":
            return {"models": [{"name": "m:7b", "digest": "abcdef0123456789"}]}
        assert payload is not None
        options = payload["options"]
        assert isinstance(options, dict)
        assert options["num_gpu"] == 99
        return {"message": {"content": '["first message here", "second message here"]'}}

    monkeypatch.setattr(gen, "ollama_post", fake_post)
    out = tmp_path / "out.jsonl"
    argv = ["--generator", "qwen2.5-7b", "--model", "m:7b", "--backend", "ollama"]
    argv += ["--n-requests", "3", "--num-gpu", "99", "--out", str(out)]
    assert gen.main(argv) == 0

    # Keep four whole rows, then half a row cut inside a two-byte character.
    lines = out.read_bytes().splitlines(keepends=True)
    out.write_bytes(b"".join(lines[:4]) + lines[4][:40] + "é".encode()[:1])
    size = out.stat().st_size

    assert load_synthetic([out]).num_rows > 0  # reading doesn't crash on the partial line
    assert out.stat().st_size == size  # and doesn't change the file

    assert gen.main(argv) == 0  # the request in the partial line is generated again
    rows = [json.loads(line) for line in out.read_text(encoding="utf-8").splitlines()]
    assert len(rows) == 6
    assert len({r["request_id"] for r in rows}) == 3


def http_error(code: int) -> urllib.error.HTTPError:
    return urllib.error.HTTPError("http://x/api/chat", code, "error", Message(), None)


def fake_urlopen(
    monkeypatch: pytest.MonkeyPatch, outcomes: list[Exception | bytes]
) -> dict[str, list[int]]:
    calls: dict[str, list[int]] = {"sleeps": [], "opens": []}

    def urlopen(request: object, timeout: int) -> io.BytesIO:
        calls["opens"].append(timeout)
        outcome = outcomes.pop(0)
        if isinstance(outcome, Exception):
            raise outcome
        return io.BytesIO(outcome)

    monkeypatch.setattr("urllib.request.urlopen", urlopen)
    monkeypatch.setattr("time.sleep", calls["sleeps"].append)
    return calls


def test_ollama_post_retries_a_passing_server_error(monkeypatch: pytest.MonkeyPatch) -> None:
    calls = fake_urlopen(monkeypatch, [http_error(500), TimeoutError("slow"), b'{"ok": true}'])
    assert gen.ollama_post("http://x", "/api/chat", {}) == {"ok": True}
    assert calls["sleeps"] == [30, 30]


def test_ollama_post_gives_up_after_the_last_retry(monkeypatch: pytest.MonkeyPatch) -> None:
    calls = fake_urlopen(monkeypatch, [http_error(500) for _ in range(gen.OLLAMA_RETRIES)])
    with pytest.raises(urllib.error.HTTPError):  # the run stops, and a resume retries the request
        gen.ollama_post("http://x", "/api/chat", {})
    assert len(calls["opens"]) == gen.OLLAMA_RETRIES


def test_ollama_post_does_not_retry_a_client_error(monkeypatch: pytest.MonkeyPatch) -> None:
    calls = fake_urlopen(monkeypatch, [http_error(404)])
    with pytest.raises(urllib.error.HTTPError):
        gen.ollama_post("http://x", "/api/chat", {})
    assert calls["sleeps"] == []


STYLES = ("angry", "casual_chat", "formal_email", "non_native", "polite_brief", "short_note")


def test_a_template_keeps_its_split_across_prompt_versions() -> None:
    templates = {f"p{v}:contact_form:booking:{s}" for v in (3, 5) for s in STYLES}
    splits = template_splits(templates)
    for style in STYLES:
        a = split_for(f"p3:contact_form:booking:{style}", "one text", splits)
        assert a == split_for(f"p5:contact_form:booking:{style}", "another text", splits)


def test_every_intent_with_enough_styles_gives_one_to_test_and_one_to_val() -> None:
    intents = ("booking", "seo_pitch", "vague_callback")
    splits = template_splits({f"p5:contact_form:{i}:{s}" for i in intents for s in STYLES})
    for intent in intents:
        mine = [v for k, v in splits.items() if f":{intent}:" in k]
        assert sorted(mine) == ["test", "train", "train", "train", "train", "val"]


def test_intents_with_few_styles_split_by_message() -> None:
    splits = template_splits({"p5:contact_form:phishing:short_note", "p5:contact_form:phishing:x"})
    assert splits == {}
    template = "p5:contact_form:phishing:short_note"
    seen = {split_for(template, f"text {n}", splits) for n in range(200)}
    assert seen == {"train", "val", "test"}


def test_clean_messages_drops_generator_artefacts() -> None:
    assert clean_messages(["Greetings [Owner\u2019s Name], we have an offer for you today"]) == []
    assert clean_messages(["Email us at [email protected] for a quote"]) == []
    assert clean_messages(["do u cover [gardencollections.com] area?"]) == []
    assert clean_messages(["see www.gardencollections.com for details"]) != []
    assert clean_messages(["Domain expires soon. Contact us for续写"]) == []
    assert clean_messages([",{'city': 'Ottawa', 'phone': '613-555-9012'}"]) == []


def test_keyword_stuffing_only_uses_a_style_that_allows_junk() -> None:
    assert INTENT_BY_KEY["keyword_stuffing"].styles == ("short_note",)
