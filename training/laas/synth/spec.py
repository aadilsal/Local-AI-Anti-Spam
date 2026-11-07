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
# v3 (5 Nov 2026, after the first local sample): some spam came out as real enquiries ("can you
# install EV chargers too?" as comment spam, "quote me for 30 albums" as a scam order), which would
# teach the model to flag real customers. Those intents now require their tell. Writers in one reply
# were also near-copies sharing fake numbers like 0412345678, so variety is spelled out. The second
# sample showed keyword_stuffing written as real enquiries and crypto writers who promoted nothing.
# v4 (6 Nov 2026, after the Qwen sample): half of fake_enquiry_pitch was a real customer mentioning
# a product, and loan_offer writers asked for loans or booked rooms. Both now say what must be sold.
# v5 (7 Nov 2026, after the first model runs): 27% of genuine comments carried links and many read
# as promotion, so comments now carry none and no ham sells anything. Phishing and scam orders came
# out in a customer's voice, so both say who the writer pretends to be. Adds the focus grid below.
PROMPT_VERSION = 5
MESSAGES_PER_REQUEST = 5
LONG_MESSAGES_PER_REQUEST = 2  # long styles: five would not fit the reply limit

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
    Style(
        "long_email",
        "a long, carefully written email with several paragraphs of specific detail",
        (180, 320),
        0.4,
    ),
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
        "found_online_enquiry",
        "ham",
        "enquiry",
        "contact_form",
        "a real customer who found the website online and wants to buy or book something from "
        "the business. They name what they need and ask to be called or emailed, often leaving a "
        "phone number",
        0.5,
        ("short_note", "casual_chat", "polite_brief", "typo_heavy", "non_native", "formal_email"),
    ),
    Intent(
        "detailed_enquiry",
        "ham",
        "enquiry",
        "contact_form",
        "a real customer explaining their job or situation in full: the background, what they "
        "have already tried, dates, budget and several specific questions",
        0.3,
        ("long_email", "rambling"),
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
        "opens with one line that sounds like a customer enquiry, then drops it: the rest is a "
        "sales pitch from the writer's own named company, selling its marketing or software "
        "service to the business, with a benefit or price and how to buy. The writer has no real "
        "need of the business and never asks about its products",
        0.7,
        _SPAMMY_STYLES,
    ),
    # --- bot and scam spam ---
    Intent(
        "phishing",
        "spam",
        "phishing",
        "contact_form",
        "a scam from someone pretending to be a domain registrar, web host, lawyer or billing "
        "department. It warns that the business's own domain is about to expire, demands payment "
        "of an attached invoice, makes a legal or copyright threat, or says its account must be "
        "verified through a link. The writer is never a customer with a problem of their own",
        0.8,
        ("formal_email", "short_note", "polite_brief", "non_native"),
    ),
    Intent(
        "advance_fee_order",
        "spam",
        "phishing",
        "contact_form",
        "a fraudulent large order. Every message must state a clear scam tell in the writer's own "
        "words: they will pay by cheque or overpay and want the difference sent back, they will "
        "ship abroad through their own shipper, or they do not care what it costs. An order "
        "without one of these is not acceptable",
        0.4,
        ("formal_email", "non_native", "polite_brief"),
    ),
    Intent(
        "crypto_investment",
        "spam",
        "crypto",
        "contact_form",
        "promotes a crypto, forex or investment scheme. Every message must name the scheme and "
        "promise returns; the writer is recruiting the business owner, not asking for advice",
        0.5,
        ("casual_chat", "short_note", "non_native", "formal_email"),
    ),
    Intent(
        "loan_offer",
        "spam",
        "bot",
        "contact_form",
        "a lender or broker from a named finance company, cold-pitching business loans, funding or "
        "merchant cash advances with 'instant approval' to the business owner. Every message "
        "offers money and states an amount or terms. The writer never asks for money, a quote or "
        "a booking",
        0.4,
        _SPAMMY_STYLES,
    ),
    Intent(
        "comment_link_spam",
        "spam",
        "bot",
        "comment",
        "a short generic comment ('great post, very informative') that exists only to leave a "
        "link. Every message must contain a made-up link to a site selling something unrelated to "
        "the post, and must not ask the business anything real",
        1.0,
        ("short_note", "casual_chat", "non_native", "polite_brief"),
    ),
    Intent(
        "self_promo_comment",
        "spam",
        "bot",
        "comment",
        "someone using the comments to promote themselves, unrelated to the post: 'subscribe to "
        "my channel', 'check out my music', 'follow me', a giveaway or their small shop. Most "
        "give only a channel or account name and no link, and they ask nothing about the post",
        0.4,
        ("short_note", "casual_chat", "typo_heavy", "non_native", "polite_brief"),
    ),
    Intent(
        "keyword_stuffing",
        "spam",
        "bot",
        "contact_form",
        "machine-written junk, not a real request: repeated keywords, lists of links, or text "
        "that makes no sense. No normal sentences and no questions about the business",
        0.4,
        ("short_note",),  # non_native asks for "clear meaning", which turned it into real enquiries
    ),
]
INTENT_BY_KEY = {i.key: i for i in INTENTS}

# Targeted top-up (``--focus``), from the error analysis of 7 Nov 2026: (intent, styles, weight).
# Ham where the models raised false alarms, long ham, and spam in styles that only ham had.
_LOOSE_SPAM_STYLES = ("typo_heavy", "angry", "rambling")
FOCUS_GRID: tuple[tuple[str, tuple[str, ...], float], ...] = (
    ("genuine_comment", INTENT_BY_KEY["genuine_comment"].styles, 3.0),
    ("vague_callback", INTENT_BY_KEY["vague_callback"].styles, 2.5),
    ("found_online_enquiry", INTENT_BY_KEY["found_online_enquiry"].styles, 2.5),
    ("detailed_enquiry", INTENT_BY_KEY["detailed_enquiry"].styles, 1.5),
    ("customer_problem", ("short_note", "casual_chat"), 1.0),
    ("thanks_feedback", ("short_note", "casual_chat"), 0.5),
    ("quote_request", ("short_note", "typo_heavy"), 0.5),
    ("self_promo_comment", INTENT_BY_KEY["self_promo_comment"].styles, 2.5),
    ("phishing", INTENT_BY_KEY["phishing"].styles, 1.5),
    ("advance_fee_order", INTENT_BY_KEY["advance_fee_order"].styles, 1.5),
    ("seo_pitch", _LOOSE_SPAM_STYLES, 1.5),
    ("web_design_pitch", _LOOSE_SPAM_STYLES, 1.5),
    ("lead_gen_pitch", _LOOSE_SPAM_STYLES, 1.0),
    ("fake_enquiry_pitch", ("typo_heavy", "rambling"), 1.0),
    ("crypto_investment", ("typo_heavy", "rambling"), 0.5),
)

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
            "A site owner reading closely must be able to tell the writer is not a real customer, "
            "and even a very short message must say plainly what is being sold or pushed. "
            "Some messages include a link, an email address or a phone number; others ask for a "
            "reply instead."
        )
    else:
        who = f"a real customer or reader of the {business[0]}"
        if intent.channel == "comment":
            extras = (
                "Writers share only an opinion, an experience or a question. They include no "
                "links, phone numbers or email addresses, and never recommend or promote a "
                "company, website or product."
            )
        else:
            # Without this, genuine messages never contain links and the model learns "link = spam".
            extras = (
                "About one in four writers mentions a link (their own website, a photo album, a "
                "listing or the product page they mean) or adds their email address; the rest "
                "don't. The writer wants something from this business and sells nothing to it."
            )
    where = (
        "the contact form on the website"
        if intent.channel == "contact_form"
        else "the comments under a blog post on the website"
    )
    low, high = style.words
    count = LONG_MESSAGES_PER_REQUEST if low >= 150 else MESSAGES_PER_REQUEST
    return (
        f"Business: a {business[0]} ({business[1]}).\n"
        f"Where the message is posted: {where}.\n"
        f"Who writes it: {who}. What it is: {intent.description}.\n"
        f"{extras}\n"
        f"Writing style: {style.description}.\n"
        f"Length: about {low}-{high} words each.\n"
        f"Locale: {locale[0]}: {locale[1]}.\n\n"
        f"Write {count} different messages, each from a different writer with "
        "different details: vary the opening words, the situation and the sentence structure. Make "
        "them believable; a careful human must not be able to tell they are generated. Invent "
        "names, and never reuse a name, company or phone number between messages. Phone numbers "
        "must look real, not 555 numbers or runs like 12345678. Use made-up company names; any "
        "website or email "
        "must use a made-up domain (never a real brand). Do not use placeholders like [Name].\n\n"
        'Reply with a JSON array of strings only, like ["first message", "second message"].'
    )


def build_requests(n: int, seed: int, generator: str, focus: bool = False) -> list[Request]:
    """Sample ``n`` generation requests. Deterministic for a given (seed, generator, focus)."""
    tag = "focus:" if focus else ""
    rng = random.Random(f"{seed}:{generator}:{tag}{PROMPT_VERSION}")
    grid = (
        [(INTENT_BY_KEY[key], styles, weight) for key, styles, weight in FOCUS_GRID]
        if focus
        else [(i, i.styles, i.weight) for i in INTENTS]
    )
    out: list[Request] = []
    for k in range(n):
        intent, style_keys, _ = rng.choices(grid, weights=[entry[2] for entry in grid])[0]
        styles = [STYLE_BY_KEY[key] for key in style_keys]
        style = rng.choices(styles, weights=[s.weight for s in styles])[0]
        business = rng.choice(BUSINESSES)
        locale = rng.choice(LOCALES)
        rid = hashlib.sha256(f"{generator}:{seed}:{tag}{k}".encode()).hexdigest()[:16]
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
    "[email protected]",  # how some scraped web text shows an email; Phi copies it
)
# The model's own scaffolding at the start of a message ("1.", "Message 2:", "Subject:"); a numbered
# list inside a genuine email is fine, so only the start is checked.
_LEADING_LABEL = re.compile(
    r"^\s*((message|email|comment|msg)\s*#?\d+\s*[:.)-]|\d+[.)]\s|subject\s*:)", re.I
)
_PLACEHOLDER = re.compile(
    r"\[[A-Z][A-Za-z '\u2019]{1,25}\]|\{\{?[A-Za-z_ ]{2,25}\}?\}|\[[a-z0-9.-]+\.[a-z]{2,}\]"
)


# Output that isn't a message: Qwen drifting into Chinese mid-sentence, or a dict leaking from Phi.
_STRAY_OUTPUT = re.compile(r"[぀-ヿ一-鿿]|\{'")


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
        if _LEADING_LABEL.match(text) or _PLACEHOLDER.search(text) or _STRAY_OUTPUT.search(text):
            continue
        seen.add(key)
        out.append(text)
    return out
