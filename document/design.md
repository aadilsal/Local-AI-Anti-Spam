# Design

Product and interface design for the free plugin and the Pro add-on: the screens, the words on them, and the rules that keep them calm, trustworthy and native to WordPress.

**Reads with:** [PRD.md](PRD.md) §8 (user flows), [architecture.md](architecture.md) §8 (admin constraints).

---

## 1. Design principles

1. **Native, not branded.** Use WordPress admin components: `WP_List_Table`, notices, `.wrap`, `.button-primary`, the Settings API, dashicons. No custom design system, no web fonts, no framework. The plugin should look like part of WordPress. This also keeps review, accessibility and support simple.
2. **Zero setup, and a single notice on activation.** No wizard, no onboarding tour, no nag. After the activation notice, the plugin stays quiet until something needs attention.
3. **"Was a real customer lost?" is answered in one glance.** Every screen leads with that answer. The worst outcome for the user is a lost lead, so the design prioritises *finding false positives* over celebrating blocked spam.
4. **Explain every verdict in words.** Show the score, a one-line reason and the words that drove it. Never "AI decided".
5. **One click to fix, and a second to make it stick.** "Not spam" releases the message; the follow-up offers to always allow that sender. The second step is offered, never forced.
6. **Honest numbers.** Counts are counts. No inflated "spam blocked!" banners, and no fake urgency in upgrade prompts.
7. **Free is complete.** The free plugin never shows locked or greyed-out Pro features. Pro is mentioned in exactly one place (Settings → Pro) and only with real data from the site.

## 2. Information architecture

```
wp-admin
├── Dashboard
│   └── [Widget] Spam protection: last 30 days       (F13)
├── Anti-Spam                                         (top-level menu, dashicons-shield)
│   ├── Quarantine        ← default page; badge = new held items since last visit
│   ├── Rules             (allow / block lists)
│   └── Settings
│       ├── General       (protection per integration, sensitivity, retention, subject tag)
│       ├── Notifications (digest frequency, recipient)
│       ├── Privacy       (store IP, share corrections opt-in)
│       ├── Diagnostics   (model version, latency p50/p95, error log)
│       └── Pro           (upgrade card, or licence status when Pro is active)
├── Comments              ← "Mark as spam" uses core's Spam action (hooked)
└── Contact / WPForms     ← "Mark as spam" row action added to their entry lists
```

The menu badge counts **new held items since the last visit**, not the total. That way the badge means "something to look at", not a permanent number.

## 3. Screens

### 3.1 Activation notice (flow 1)

A single dismissible `notice-success` on the plugins screen and dashboard, shown once.

```
┌──────────────────────────────────────────────────────────────────────────┐
│ ✓ Spam protection is on for Contact Form 7, WPForms and comments.         │
│   Checks run on this server: nothing is sent anywhere.                    │
│   Messages flagged as spam are held for 30 days, never deleted.           │
│   [ View quarantine ]   Settings                                     [×]  │
└──────────────────────────────────────────────────────────────────────────┘
```

The list of protected integrations is built from what was detected. If none were found: "Spam protection is on for comments. Install Contact Form 7 or WPForms and it will protect those too."

### 3.2 Quarantine list (F7, flow 2)

`WP_List_Table` with views, search, bulk actions and pagination.

```
Quarantine                                                       [Search messages]
All held (42) | Spam (38) | Solicitation (4)ᴾʳᵒ | Released (6)

Bulk actions ▾  [Apply]                                           42 items  ‹ 1 2 ›
┌───┬──────────────────────┬────────────────────────────────┬──────────┬───────┬─────────────────────┬──────────┐
│ ☐ │ From                 │ Message                        │ Form     │ Score │ Why                 │ Received │
├───┼──────────────────────┼────────────────────────────────┼──────────┼───────┼─────────────────────┼──────────┤
│ ☐ │ Sarah Mitchell       │ Hi, I'd like a quote for a     │ Contact  │ 0.93  │ Words: quote, free, │ 2 h ago  │
│   │ sarah@example.co.uk  │ kitchen refit next month…      │ (CF7)    │ ▮▮▮▮▯ │ consultation        │          │
│   │ Not spam · View · Delete                                                                          │
├───┼──────────────────────┼────────────────────────────────┼──────────┼───────┼─────────────────────┼──────────┤
│ ☐ │ Mark SEO             │ I noticed your website isn't   │ Contact  │ 0.99  │ Words: ranking,     │ 5 h ago  │
│   │ mark@seo-pros.example│ ranking on Google's first page │ (CF7)    │ ▮▮▮▮▮ │ first page, backl…  │          │
└───┴──────────────────────┴────────────────────────────────┴──────────┴───────┴─────────────────────┴──────────┘
```

- **Default sort: lowest score first.** The rows most likely to be real enquiries sit at the top, where a quick glance finds them. This is the most important design decision on the screen (principle 3).
- **Score display:** two decimals plus a 5-segment bar. Colour is never the only signal; the number is always shown.
- **Row actions:** *Not spam* (primary, first), *View*, *Delete*. Bulk actions: *Not spam*, *Delete permanently*.
- **Search** covers sender name, email and message text, limited to rows within the retention window.
- **Empty state:** "Nothing in quarantine. Spam that's caught will be held here for 30 days, so you can check for real enquiries."
- **Expiry hint** in the footer: "Messages are deleted automatically after 30 days. [Change]".
- **Solicitation view** appears only when Pro's solicitation class is on.

### 3.3 Message detail, the "reason view" (F7, L5 screenshot)

```
← Back to quarantine

Sarah Mitchell <sarah@example.co.uk>                 Received 10 Oct 2026, 14:02
Contact form (Contact Form 7) · Score 0.93 · Model local-2026.11.1

┌─ Message ──────────────────────────────────────────────────────────────────┐
│ Hi, I'd like a quote for a kitchen refit next month. Do you offer a free    │
│ consultation? My number is 07700 900123.                                    │
└─────────────────────────────────────────────────────────────────────────────┘

Why this was held
  The model scored this 0.93. Messages scoring 0.92 or more are held.
  Words that pushed it towards spam:   free  ·  consultation  ·  quote
  Things that pulled it towards clean: kitchen  ·  next month  ·  my number
  Links: none

[ Not spam: deliver it ]   [ Delete ]
```

- The body renders as **plain text** (escaped, line breaks preserved, URLs shown as text and never as clickable links). Spam content is untrusted; see security-analysis S1.
- The "Why" section uses real words from the message. With hashing, explanations come from the message's own tokens (architecture §4.3), so they are never random.
- For rule verdicts: "Held because the sender's domain `seo-pros.example` is on your block list. [Edit rules]".

### 3.4 After "Not spam" (flow 3)

Inline success notice; the row disappears from the list:

```
✓ Delivered to the Contact form's normal recipients.
  Always allow sarah@example.co.uk?  [ Always allow ]  [ No thanks ]
```

- If delivery fails (mail error): `notice-error`: "Couldn't deliver this message. Your site's email may not be working. It's still in quarantine." The item stays `held`. A failed release must never lose a message.
- When the user has opted in to sharing, add a quiet line: "This correction will be shared to improve the model (you turned this on in Settings → Privacy)."

### 3.5 "Mark as spam" for misses

- **Comments:** core's *Spam* link is hooked. No new UI.
- **Form entry lists** (WPForms, and later Gravity and Fluent): a row action *Mark as spam (Anti-Spam)*. After clicking it: "Marked as spam. Block `seo-pros.example` from now on? [Block domain] [Block this email] [No thanks]".
- Contact Form 7 has no entry list. A "Report spam that got through" box on the Rules screen covers it: paste the message, and the plugin suggests block rules (sender domain, link domains, a distinctive phrase). If sharing is on, the message is also queued to share.

### 3.6 Rules screen (F9)

Two tables side by side on wide screens, stacked on narrow ones: **Always allow** and **Always block**. Each row shows the type (Email / Domain / IP / Phrase), the value, date added, *hits* and *Remove*. An add form has a type select, a value field and an action select. Allow rules win over block rules, and the screen says so.

### 3.7 Settings

**General**

| Setting | Control | Default |
|---|---|---|
| Protect | Checkbox per detected integration | All on |
| Sensitivity | Radio: Relaxed · **Balanced** · Strict, with a one-line explanation of each | Balanced |
| Advanced thresholds | Collapsed; two number fields (clean below / spam at or above) | From model |
| Possible-spam tag | Text field for the subject prefix on borderline mail; empty = off | `[Possible spam]` |
| Keep held messages for | Number + "days" (1–365) | 30 |

Sensitivity copy:
- *Relaxed*: "Holds only very obvious spam. Fewest real messages held by mistake."
- *Balanced*: "Recommended."
- *Strict*: "Holds more spam, but real enquiries are a little more likely to be held. Check the quarantine regularly."

**Notifications:** digest Off · Daily · **Weekly**; recipient (defaults to the admin email); "Only send when something new is held" (on).

**Privacy:** "Store sender IP addresses" (on, with explanation); "Help improve the model" (**off**), with the consent text below, shown in full and not hidden behind a link:

> When you mark a message as "Not spam" or "Spam", send a copy of the message text to [project domain] to help train future models. Before sending, email addresses, phone numbers and the name and email fields are removed. Nothing else about your site or visitors is sent. You can turn this off at any time; messages already sent can be deleted by contacting [address]. [Read the full data policy]

**Diagnostics:** model version and date, the measured false-positive rate from its changelog, the number of checks on this site, latency p50/p95 for the last 200 checks, the last 20 log entries (no message content), and a *Run self-test* button that classifies three built-in samples and confirms the model loads.

**Pro (free plugin only):** the upgrade card (§3.9).

### 3.8 Dashboard widget (F13, L5 screenshot)

```
┌ Spam protection: last 30 days ──────────────────────────┐
│  1,284 checked    312 held    3 released by you         │
│  ▁▂▂▃▂▅▃▂▂▃▄▂▂▁▂▃▂▂▃▅▂▂▃▂▂▃▂▂▃▂  held per day            │
│                                                          │
│  5 held messages scored under 0.95. Worth a quick look.  │
│  [ Review quarantine ]                                   │
└──────────────────────────────────────────────────────────┘
```

- The bottom line appears only when there are held items near the threshold, and links to the quarantine sorted lowest-score first. It nudges the behaviour that prevents lost leads. Otherwise it reads: "Nothing near the line. Looks clean."
- The sparkline is an inline SVG with a text alternative ("Held per day, last 30 days: min 2, max 31").

### 3.9 Upgrade card (flow 4)

Shown only in Settings → Pro. It uses the site's own data, or says nothing.

```
┌ Get a second opinion on unsure messages ───────────────────────────────────┐
│ In the last 30 days, 41 messages on this site were borderline: delivered    │
│ with a [Possible spam] tag because the local model wasn't sure.             │
│                                                                              │
│ Pro sends only those borderline messages to a larger model on our server,   │
│ which decides for you. Clear cases still never leave your site.             │
│                                                                              │
│ Also includes: separate folder for sales pitches · per-site learning ·      │
│ weekly summary for clients.                                                  │
│                                                                              │
│ $39/year for one site · $99/year for unlimited sites                        │
│ [ See Pro ]   14-day refund, no questions asked                              │
└──────────────────────────────────────────────────────────────────────────────┘
```

- *See Pro* is a plain link to the product site's checkout. The free plugin makes no call and loads no SDK (architecture A4).
- When the borderline count is 0: "Your site had no borderline messages in the last 30 days. You probably don't need Pro right now." Telling users that they don't need Pro builds the trust that sells it later.

### 3.10 Email digest (F15)

Plain, mobile-friendly HTML with a text alternative. Subject: `[Site name] 7 messages held as spam this week`.

```
7 messages were held as spam on Example Plumbing this week.
Most likely to be real (lowest score first):

  Sarah Mitchell: "Hi, I'd like a quote for a kitchen refit…"   score 0.93   [Review]
  J. Okafor: "Do you cover the Leeds area for boiler…"           score 0.94   [Review]
  …and 5 more, all scored 0.99 or higher.

[ Open quarantine ]

Held messages are deleted after 30 days. Change digest settings: [link]
```

- Sender names and first lines are escaped and truncated to 60 characters. No links from the spam body ever appear in the email.
- *Review* opens the wp-admin detail view (login required). There is no one-click release from email (security-analysis S4).

### 3.11 Pro additions

- **Licence status** replaces the upgrade card: plan, sites used, cloud checks this month (with a bar on the Agency plan; warning notice at 80%, per PRO-10), and the cloud status (Connected · Slow · Unreachable, falling back to local).
- **Reason view** for cloud verdicts: "Checked by the cloud model (cloud-minilm-2026.12.0): **sales pitch**, 88%. Local score was 0.71 (borderline)."
- **Weekly client summary (PRO-8):** branded with the site name, written for a non-technical client. "This week your contact form received 34 enquiries and 112 spam messages, which were kept out of your inbox."
- **Agency dashboard (PRO-7):** one table: site · checked · held · released · borderline · last seen · [Open quarantine →]. It is sortable, and sites not seen for 48 hours are highlighted.

## 4. Content and voice

- Plain English, short sentences, no jargon. "Held" rather than "quarantined" in sentences (the menu item stays *Quarantine* because people search for that word). "Score" not "probability". "Model" is fine; "AI" is used in the listing title only.
- Name the form ("Contact form (Contact Form 7)"), never just "source: cf7".
- Error messages say what happened, what it means for the user's messages, and what to do: "The spam model couldn't be loaded, so messages are being delivered without checking. Reinstall the plugin or contact support."
- All strings are wrapped for translation (`__()`, `_n()`, text domain = plugin slug) from day one (F20).

## 5. Accessibility (WCAG 2.2 AA)

- Built on core components, which carry their own semantics and keyboard support. Every custom element (score bar, sparkline, upgrade card) has a text equivalent.
- Score bars use `role="img"` with `aria-label="Score 0.93 out of 1"`; the number is visible text as well.
- Notices use core's `notice` classes, so screen readers announce them. Ajax results go through `wp.a11y.speak()`.
- Focus moves to the success notice after *Not spam*, and back to the next row after a row disappears.
- Contrast: core admin colours only. No meaning carried by colour alone.
- All actions work without JavaScript (POST forms through `admin-post.php`). JavaScript only adds inline updates.

## 6. WordPress.org listing assets (L1–L5)

| Asset | Content |
|---|---|
| Banner 1544×500 / 772×250 | Plain shield mark + "Spam filtering that runs on your own server". No stock photos. |
| Icon 256×256 / 128×128 | Shield with a small chip/check; legible at 32 px |
| Screenshot 1 | Quarantine list (lowest score first, one plausible real enquiry at the top) |
| Screenshot 2 | Reason view with words highlighted |
| Screenshot 3 | Dashboard widget |
| Screenshot 4 | Settings: General (shows zero required setup) |
| Screenshot 5 | Rules screen |

All sample names, emails and messages in screenshots are fictional and use reserved domains (`example.com`, `example.co.uk`).

## 7. Open design questions

- [ ] Final product name, icon and colour (blocked on the naming open question in the PRD).
- [ ] Should borderline mail be tagged in the subject by default, or should the tag be opt-in? Default on is safer (the user sees the uncertainty); test with the first 10 beta sites.
- [ ] Should the solicitation folder show in the free plugin as an empty, explained view? Current answer: no (principle 7).
