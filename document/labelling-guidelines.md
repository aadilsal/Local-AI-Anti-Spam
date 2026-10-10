# Labelling guidelines

How to label real contact-form messages for the hand-labelled test set (PRD §9.1). Every published metric is measured on these labels, so consistency matters more than speed.

**The one question:** *Would the site owner want this message in their inbox?* Yes → `ham`. No → `spam`.

## Labels

| `label` | `subtype` | Use when | Examples |
|---|---|---|---|
| `ham` | `enquiry` | A real person asking about the business's products, services, prices, bookings or availability | "Do you cover Leeds for boiler repairs?" · "Table for 4 on Friday?" |
| `ham` | `support` | An existing customer with a question or problem | "My order hasn't arrived" · "How do I reset my password?" |
| `ham` | `other_ham` | Real and relevant but neither of the above: job applications, press, partnership requests the owner would welcome, complaints, thank-yous | "Loved the event, thank you!" |
| `spam` | `solicitation` | A sales pitch written by a person (or to look like one) selling *to* the business: SEO, web design, marketing, lead lists, app development, guest posts | "Your site isn't ranking on page 1…" |
| `spam` | `bot` | Automated junk: gibberish, link dumps, keyword stuffing, generic "nice site" comments with links | "Great post! visit cheap-meds.example" |
| `spam` | `phishing` | Tries to get credentials, payment or a click on a fake login or invoice | "Your domain expires today, renew here" |
| `spam` | `crypto` | Crypto, investment or "get rich" schemes | "10x returns guaranteed" |
| `spam` | `adult` | Sexual content or dating spam | |

## Hard cases (decide the same way every time)

1. **Sales pitches are spam** (`solicitation`), even when polite and personalised, *unless* the business clearly sells to other businesses and the message is a relevant enquiry for what they sell.
2. **Vague but plausible messages are ham.** "Please call me back about a quote" with no details: ham. When unsure, ham. A false positive costs the owner more than a missed spam (PRD Goal 2).
3. **Rude, angry or badly written messages from real customers are ham.** Quality isn't the question; intent is.
4. **Job seekers and recruiters:** ham if applying *to* the business (`other_ham`); spam if offering recruitment services *to* it (`solicitation`).
5. **Non-English messages:** label them, but mark `language` accordingly. v1 delivers them as "not checked" and they don't count towards the English metrics.
6. **Test submissions by the owner** ("test test"): skip. They don't belong in the test set.
7. **Duplicates:** label once; the pipeline dedupes.
8. **Can't decide after 30 seconds?** Mark `unsure`. Unsure rows are reviewed later and excluded from metrics until resolved.

## Process

1. Data arrives, then **redact first** (`python -m laas.data.redact`). Never label from the raw export.
2. Label with `python -m laas.label` (keyboard: `h` ham, `s` spam, then the subtype key; `u` unsure; `q` quit and save).
3. **Consistency check:** a week later, re-label a random 50 without looking at the first labels. Agreement below 95% means the guidelines need a clearer rule. Add it here.
4. Labels are stored in `data/real/labels.jsonl` (gitignored, encrypted at rest). Never commit them.
