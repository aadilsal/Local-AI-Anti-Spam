"""Synthetic contact-form data: the generation grid and prompts (W1-10, PRD D6).

Single source of truth for what the generator writes. The Kaggle notebook embeds this file verbatim
(``python -m laas.synth.notebook``), so keep it standard-library only.

Design driven by review notes R13/R14: the public data taught the model "casual = spam", so genuine
messages here deliberately cover email-style, chat-style, short, rambling, non-native and angry
writing, and spam covers short comment spam and human-sounding SEO/web-design pitches.

A *template* is (prompt version, channel, intent, style). Splits are made by template (PRD D4), so
the test side never contains an intent/style combination the model trained on.
"""

from __future__ import annotations

import hashlib
import json
import random
import re
from dataclasses import asdict, dataclass

# v2 (18 Oct 2026, after the pilot review): genuine writers sometimes include links and emails (v1
# had none, which would teach "link = spam"); spam briefs say who is selling to whom (v1's loan
# offers came out as customers asking for loans); stricter artefact filter.
PROMPT_VERSION = 2
MESSAGES_PER_REQUEST = 5

# (business, what a customer would contact it about). At least 20 types (PRD D6).
BUSINESSES = [
    ("plumber", "leaks, boilers, bathroom fitting, emergency call-outs"),
    ("dental practice", "check-ups, emergency appointments, whitening, NHS or private fees"),
    ("family restaurant", "table bookings, private parties, menus, allergies"),
    ("law firm", "wills, conveyancing, employment disputes, initial consultations"),
    ("accountant", "tax returns, bookkeeping, VAT, small-business accounts"),
    ("hair salon", "appointments, colouring, bridal hair, prices"),
    ("gym", "memberships, personal training, cancellation, classes"),
    ("wedding photographer", "availability, packages, travel, albums"),
    ("estate agent", "valuations, viewings, lettings, selling a house"),
    ("roofing contractor", "leaks, new roofs, guttering, quotes"),
    ("veterinary clinic", "appointments, vaccinations, emergencies, pet insurance"),
    ("bakery", "custom cakes, wholesale orders, opening hours, allergens"),
    ("driving school", "lessons, intensive courses, test dates, prices"),
    ("IT support company", "broken laptops, office networks, backups, contracts"),
    ("yoga studio", "class times, beginners, private sessions, memberships"),
    ("landscaping company", "garden design, lawn care, fencing, quotes"),
    ("car repair garage", "MOT or inspection, servicing, warning lights, quotes"),
    ("private tutor", "maths and English tutoring, exam prep, online lessons"),
    ("cleaning company", "end-of-tenancy cleans, office cleaning, regular cleaners"),
    ("clothing boutique", "returns, sizes, stock, alterations"),
    ("bed and breakfast", "room availability, group bookings, parking, pets"),
    ("florist", "wedding flowers, funeral wreaths, same-day delivery"),
    ("physiotherapist", "back pain, sports injuries, appointments, insurance"),
    ("nursery and daycare", "places, fees, settling-in, opening hours"),
    ("electrician", "rewiring, sockets, EV chargers, safety certificates"),
    ("removals company", "house moves, quotes, storage, packing"),
    ("dog groomer", "appointments, breeds, prices, nervous dogs"),
    ("online craft shop", "orders, delivery times, custom pieces, damaged items"),
    ("web design agency", "new websites, redesigns, hosting, prices"),
    ("charity", "volunteering, donations, events, help with services"),
]

LOCALES = [
    ("UK", "British English spelling, UK phone numbers and towns"),
    ("US", "American English spelling, US phone numbers and cities"),
    ("Canada", "Canadian English, Canadian phone numbers and cities"),
    ("Australia", "Australian English, Australian phone numbers and cities"),
    ("Ireland", "Irish English, Irish phone numbers and towns"),
    ("India", "Indian English as commonly written, Indian phone numbers and cities"),
    ("Pakistan", "Pakistani English as commonly written, Pakistani phone numbers and cities"),
    ("Nigeria", "Nigerian English as commonly written, Nigerian phone numbers and cities"),
]


@dataclass(frozen=True)
class Style:
    key: str
    description: str
    words: tuple[int, int]
    weight: float


STYLES = [
    Style(
        "formal_email",
        "written like a proper email: greeting, two to four short paragraphs, sign-off with a "
        "first name",
        (60, 220),
        1.4,
    ),
    Style(
        "casual_chat",
        "casual and chatty like a text message: lowercase, little punctuation, maybe an emoji or "
        "'thx', 'pls', 'u'",
        (8, 60),
        1.4,
    ),
    Style("short_note", "one or two short sentences, no greeting", (4, 25), 1.2),
    Style(
        "rambling",
        "long and rambling, with background detail and a few digressions before the point",
        (150, 400),
        0.8,
    ),
    Style(
        "non_native",
        "written by someone whose first language is not English: small grammar mistakes, unusual "
        "phrasing, but clear meaning",
        (20, 120),
        1.0,
    ),
    Style(
        "typo_heavy", "typed quickly on a phone: several typos and missing capitals", (10, 80), 0.8
    ),
    Style(
        "angry",
        "frustrated or angry: some capital letters, exclamation marks, blunt",
        (20, 150),
        0.6,
    ),
    Style("polite_brief", "polite and to the point, three or four sentences", (25, 70), 1.0),
]
STYLE_BY_KEY = {s.key: s for s in STYLES}


@dataclass(frozen=True)
class Intent:
    key: str
    label: str  # "ham" | "spam"
    subtype: str  # labelling-guidelines.md subtypes
    channel: str  # "contact_form" | "comment"
    description: str
    weight: float
    styles: tuple[str, ...] = tuple(STYLE_BY_KEY)


_SPAMMY_STYLES = ("formal_email", "casual_chat", "short_note", "non_native", "polite_brief")

INTENTS = [
    # --- genuine contact-form messages ---
    Intent(
        "quote_request",
        "ham",
        "enquiry",
        "contact_form",
        "asks for a price or quote for something the business does, with some detail of the job",
        1.6,
    ),
    Intent(
        "booking",
        "ham",
        "enquiry",
        "contact_form",
        "wants to book, reschedule or check availability for an appointment, table, room or date",
        1.4,
    ),
    Intent(
        "service_question",
        "ham",
        "enquiry",
        "contact_form",
        "asks a question about the service: whether they cover an area, opening hours, what is "
        "included, how long it takes",
        1.3,
    ),
    Intent(
        "customer_problem",
        "ham",
        "support",
        "contact_form",
        "an existing customer with a problem: late order, wrong item, a fault after a job, a "
        "billing mistake",
        1.1,
    ),
    Intent(
        "complaint",
        "ham",
        "support",
        "contact_form",
        "a genuine complaint from an unhappy customer who wants it put right",
        0.6,
    ),
    Intent(
        "follow_up",
        "ham",
        "enquiry",
        "contact_form",
        "follows up on an earlier call, visit or quote and asks what happens next",
        0.8,
    ),
    Intent(
        "vague_callback",
        "ham",
        "enquiry",
        "contact_form",
        "a vague but genuine message asking for someone to call or email back, with little detail",
        0.7,
        ("short_note", "casual_chat", "polite_brief", "typo_heavy", "non_native"),
    ),
    Intent(
        "job_application",
        "ham",
        "other_ham",
        "contact_form",
        "someone applying for a job or work experience at the business itself",
        0.4,
    ),
    Intent(
        "thanks_feedback",
        "ham",
        "other_ham",
        "contact_form",
        "a thank-you or positive feedback after using the business",
        0.4,
    ),
    # --- genuine blog or page comments ---
    Intent(
        "genuine_comment",
        "ham",
        "other_ham",
        "comment",
        "a real reader commenting on a blog post the business published: an opinion, a related "
        "experience or a question to the author",
        0.7,
        ("casual_chat", "short_note", "polite_brief", "non_native", "typo_heavy", "rambling"),
    ),
    # --- solicitation: sales pitches to the business ---
    Intent(
        "seo_pitch",
        "spam",
        "solicitation",
        "contact_form",
        "an unsolicited SEO pitch: claims the site is not ranking on Google's first page and "
        "offers to fix it, often with a fake audit or a 'quick question'",
        1.2,
        _SPAMMY_STYLES,
    ),
    Intent(
        "web_design_pitch",
        "spam",
        "solicitation",
        "contact_form",
        "an unsolicited offer to redesign the website, build an app or fix 'errors' found on the "
        "site, often from an overseas agency",
        1.0,
        _SPAMMY_STYLES,
    ),
    Intent(
        "lead_gen_pitch",
        "spam",
        "solicitation",
        "contact_form",
        "sells leads, email lists, appointment setting, social media management or paid ads to "
        "the business",
        0.9,
        _SPAMMY_STYLES,
    ),
    Intent(
        "guest_post_request",
        "spam",
        "solicitation",
        "contact_form",
        "asks to publish a 'guest post' or buy a link on the site, or offers backlinks",
        0.6,
        _SPAMMY_STYLES,
    ),
    Intent(
        "fake_enquiry_pitch",
        "spam",
        "solicitation",
        "contact_form",
        "pretends to be a customer enquiry in the first line, then turns into a sales pitch for a "
        "marketing or software service",
        0.7,
        _SPAMMY_STYLES,
    ),
    # --- bot and scam spam ---
    Intent(
        "phishing",
        "spam",
        "phishing",
        "contact_form",
        "a scam: the domain or website is about to expire, an invoice or legal/copyright notice "
        "is attached, or the account needs verifying via a link",
        0.8,
        ("formal_email", "short_note", "polite_brief", "non_native"),
    ),
    Intent(
        "advance_fee_order",
        "spam",
        "phishing",
        "contact_form",
        "a fraudulent large order: wants to buy in bulk, pay by cheque or with an overpayment, or "
        "ship abroad through 'their own shipper'",
        0.4,
        ("formal_email", "non_native", "polite_brief"),
    ),
    Intent(
        "crypto_investment",
        "spam",
        "crypto",
        "contact_form",
        "promotes a crypto, forex or investment scheme with guaranteed returns",
        0.5,
        ("casual_chat", "short_note", "non_native", "formal_email"),
    ),
    Intent(
        "loan_offer",
        "spam",
        "bot",
        "contact_form",
        "a lender or broker cold-pitching business loans, funding or merchant cash advances with "
        "'instant approval' to the business owner (the writer is offering money, not asking "
        "for it)",
        0.4,
        _SPAMMY_STYLES,
    ),
    Intent(
        "comment_link_spam",
        "spam",
        "bot",
        "comment",
        "a short generic comment ('great post, very informative') that exists only to leave a "
        "link to an unrelated site",
        1.0,
        ("short_note", "casual_chat", "non_native", "polite_brief"),
    ),
    Intent(
        "keyword_stuffing",
        "spam",
        "bot",
        "contact_form",
        "machine-written junk: repeated keywords, lists of links, or text that makes no sense",
        0.4,
        ("short_note", "non_native"),
    ),
]
INTENT_BY_KEY = {i.key: i for i in INTENTS}

SYSTEM_PROMPT = (
    "You write realistic example messages for testing a spam filter for small-business websites. "
    "Write exactly what a real person (or a real spammer) would type. Never explain, never add "
    "notes, never say it is an example. Reply with JSON only."
)


@dataclass(frozen=True)
class Request:
    request_id: str
    template_id: str
    intent: str
    label: str
    subtype: str
    channel: str
    style: str
    business: str
    locale: str
    seed: int
    messages: tuple[tuple[str, str], ...]  # (role, content)

    def chat(self) -> list[dict[str, str]]:
        return [{"role": role, "content": content} for role, content in self.messages]

    def meta(self) -> dict[str, object]:
        row = asdict(self)
        del row["messages"]
        return row


def template_id(intent: Intent, style: Style) -> str:
    return f"p{PROMPT_VERSION}:{intent.channel}:{intent.key}:{style.key}"


def user_prompt(
    intent: Intent, style: Style, business: tuple[str, str], locale: tuple[str, str]
) -> str:
    if intent.label == "spam":
        who = f"a spammer or scammer writing TO the {business[0]} (not one of its customers)"
        extras = (
            "Some messages include a link, an email address or a phone number; others ask for a "
            "reply instead."
        )
    else:
        who = f"a real customer or reader of the {business[0]}"
        # Without this, genuine messages never contain links and the model learns "link = spam".
        extras = (
            "About one in four writers mentions a link (their own website, a photo album, a "
            "listing or the product page they mean) or adds their email address; the rest don't."
        )
    where = (
        "the contact form on the website"
        if intent.channel == "contact_form"
        else "the comments under a blog post on the website"
    )
    low, high = style.words
    return (
        f"Business: a {business[0]} ({business[1]}).\n"
        f"Where the message is posted: {where}.\n"
        f"Who writes it: {who}. What it is: {intent.description}.\n"
        f"{extras}\n"
        f"Writing style: {style.description}.\n"
        f"Length: about {low}-{high} words each.\n"
        f"Locale: {locale[0]}: {locale[1]}.\n\n"
        f"Write {MESSAGES_PER_REQUEST} different messages, each from a different writer with "
        "different details. Make them varied and believable; a careful human must not be able to "
        "tell they are generated. Invent names. Use made-up company names; any website or email "
        "must use a made-up domain (never a real brand). Do not use placeholders like [Name].\n\n"
        'Reply with a JSON array of strings only, like ["first message", "second message"].'
    )


def build_requests(n: int, seed: int, generator: str) -> list[Request]:
    """Sample ``n`` generation requests. Deterministic for a given (seed, generator)."""
    rng = random.Random(f"{seed}:{generator}:{PROMPT_VERSION}")
    intent_weights = [i.weight for i in INTENTS]
    out: list[Request] = []
    for k in range(n):
        intent = rng.choices(INTENTS, weights=intent_weights)[0]
        styles = [STYLE_BY_KEY[key] for key in intent.styles]
        style = rng.choices(styles, weights=[s.weight for s in styles])[0]
        business = rng.choice(BUSINESSES)
        locale = rng.choice(LOCALES)
        rid = hashlib.sha256(f"{generator}:{seed}:{k}".encode()).hexdigest()[:16]
        out.append(
            Request(
                request_id=rid,
                template_id=template_id(intent, style),
                intent=intent.key,
                label=intent.label,
                subtype=intent.subtype,
                channel=intent.channel,
                style=style.key,
                business=business[0],
                locale=locale[0],
                seed=seed,
                messages=(
                    ("system", SYSTEM_PROMPT),
                    ("user", user_prompt(intent, style, business, locale)),
                ),
            )
        )
    return out


_ARRAY = re.compile(r"\[.*\]", re.DOTALL)
_REFUSALS = (
    "as an ai",
    "i cannot assist",
    "i can't assist",
    "i can't help with",
    "i cannot help with",
    "example message",
    "[name]",
    "[your name]",
    "lorem ipsum",
    "language model",
)
# The model's own scaffolding at the start of a message ("1.", "Message 2:", "Subject:"); a numbered
# list inside a genuine email is fine, so only the start is checked.
_LEADING_LABEL = re.compile(
    r"^\s*((message|email|comment|msg)\s*#?\d+\s*[:.)-]|\d+[.)]\s|subject\s*:)", re.I
)
_PLACEHOLDER = re.compile(r"\[[A-Z][A-Za-z ]{1,25}\]|\{\{?[A-Za-z_ ]{2,25}\}?\}")


def parse_response(text: str) -> list[str]:
    """Pull the JSON array of messages out of a model reply; [] if there isn't one."""
    match = _ARRAY.search(text)
    if not match:
        return []
    try:
        items = json.loads(match.group(0))
    except json.JSONDecodeError:
        return []
    if not isinstance(items, list):
        return []
    out = []
    for item in items:
        if isinstance(item, dict):
            item = item.get("text") or item.get("message") or ""
        if isinstance(item, str):
            out.append(item)
    return out


def clean_messages(texts: list[str]) -> list[str]:
    """Drop empty, refusal-like, placeholder, scaffolded and duplicate messages."""
    seen: set[str] = set()
    out = []
    for text in texts:
        text = text.strip()
        key = " ".join(text.lower().split())
        if len(key) < 3 or key in seen or any(marker in key for marker in _REFUSALS):
            continue
        if _LEADING_LABEL.match(text) or _PLACEHOLDER.search(text):
            continue
        seen.add(key)
        out.append(text)
    return out
