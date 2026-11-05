import io
import tarfile
import zipfile

from laas.data.public import (
    SOURCE_BY_NAME,
    Row,
    build_table,
    email_body,
    parse_spamassassin,
    parse_uci_sms,
    split_for,
)
from laas.data.schema import SplitPolicy

SMS = SOURCE_BY_NAME["uci_sms"]
ENRON = SOURCE_BY_NAME["enron_spam"]


def test_duplicates_ignore_case_digits_and_punctuation() -> None:
    table = build_table([(SMS, [Row("Win 100 now!", "spam"), Row("win 250 NOW", "spam")])])
    assert table.num_rows == 1


def test_text_seen_with_both_labels_is_dropped() -> None:
    rows = [Row("same words", "spam"), Row("same words", "ham"), Row("same words", "ham")]
    assert build_table([(SMS, rows)]).num_rows == 0


def test_conversation_stays_on_one_side_of_the_split() -> None:
    rows = [Row(f"turn number {'abcdefgh'[i]}", "ham", group="convo-1") for i in range(8)]
    table = build_table([(SMS, rows)])
    assert table.num_rows == 8
    assert set(table.column("corpus_split").to_pylist()) == {split_for("convo-1")}


def test_near_duplicates_share_a_split() -> None:
    opening = "congratulations you have won a free prize claim"
    table = build_table([(SMS, [Row(f"{opening} {w} today", "spam") for w in ("cash", "car")])])
    assert table.num_rows == 2
    assert len(set(table.column("corpus_split").to_pylist())) == 1


def test_licence_tier_decides_the_policy() -> None:
    table = build_table([(SMS, [Row("from sms", "ham")]), (ENRON, [Row("from enron", "ham")])])
    policy = dict(
        zip(
            table.column("source").to_pylist(),
            table.column("split_policy").to_pylist(),
            strict=True,
        )
    )
    assert policy == {"uci_sms": SplitPolicy.TRAIN, "enron_spam": SplitPolicy.EVAL_ONLY}


def test_parse_uci_sms() -> None:
    buffer = io.BytesIO()
    with zipfile.ZipFile(buffer, "w") as archive:
        archive.writestr("SMSSpamCollection", "ham\thello there\nspam\tfree prize\nbad line\n")
    assert [(r.label, r.text) for r in parse_uci_sms([buffer.getvalue()])] == [
        ("ham", "hello there"),
        ("spam", "free prize"),
    ]


def test_email_body_handles_plain_and_html() -> None:
    plain = b"Subject: Hi\nContent-Type: text/plain\n\nbody text\n"
    assert email_body(plain) == ("Hi", "body text\n")
    html = b"Subject: Sale\nContent-Type: text/html\n\n<p>big <b>sale</b></p>\n"
    subject, body = email_body(html)
    assert subject == "Sale"
    assert "<" not in body and "sale" in body


def test_parse_spamassassin_labels_by_folder_and_skips_index() -> None:
    buffer = io.BytesIO()
    with tarfile.open(fileobj=buffer, mode="w:bz2") as archive:
        for name, raw in (
            ("spam/001", b"Subject: a\n\nbuy now\n"),
            ("easy_ham/001", b"Subject: b\n\nsee you\n"),
            ("spam/cmds", b"mv 1 2\n"),
        ):
            info = tarfile.TarInfo(name)
            info.size = len(raw)
            archive.addfile(info, io.BytesIO(raw))
    rows = list(parse_spamassassin([buffer.getvalue()]))
    assert sorted((r.label, r.text.strip()) for r in rows) == [
        ("ham", "see you"),
        ("spam", "buy now"),
    ]
