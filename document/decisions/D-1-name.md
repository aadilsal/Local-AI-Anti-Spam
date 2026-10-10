# D-1: Product name and slug

**Status:** recommended, awaiting the owner's confirmation · **Checked:** 10 October 2026

## Candidate

**Local AI Anti-Spam**, slug `local-ai-anti-spam`, code prefix `laas_` / `LocalAIAntiSpam\`. The GitHub repository is already named `Local-AI-Anti-Spam`.

## What was checked

| Check | Result |
|---|---|
| WordPress.org slug `local-ai-anti-spam` | **Free** (plugins API: "Plugin not found"). Also free: `local-ai-antispam`, `local-anti-spam`, `ai-anti-spam` |
| Directory search "local ai anti spam" | No plugin with a similar name. The closest by wording is *SpamAnvil: AI Anti-Spam* (about 30 installs). Established neighbours: Maspik, OOPSpam, SilentShield, hCaptcha |
| Web search for the name as a product or trademark | No product or trademark found |
| Domains (DNS lookup) | `localaiantispam.com`, `local-ai-anti-spam.com`, `localaiantispam.io`, `localaiantispam.dev`, `localantispam.com` have no DNS, so they **appear** unregistered. Confirm at a registrar |
| Official trademark registers (USPTO, EUIPO, UK IPO, WIPO) | **Not checked.** These need a manual search: [USPTO](https://tmsearch.uspto.gov/), [WIPO Global Brand Database](https://branddb.wipo.int/) |

## Risks

- **Descriptive name.** "Local AI Anti-Spam" describes the product, which is good for directory search (PRD L1) but weak as a trademark: it would be hard to stop others using similar words.
- **"LocalAI" is an existing open-source project** for running AI models locally. The overlap is in wording only (different product category), and our name separates "Local" and "AI". There is a small risk of confusion; avoid styling it as "LocalAI".
- WordPress.org rejects names that start with someone else's trademark (for example "Contact Form 7 …"). Our name avoids that; the form plugins appear only later in the title (L1), which is allowed.

## Recommendation

Keep **Local AI Anti-Spam** and the slug `local-ai-anti-spam`. Before week 6 (submission):

1. Run the USPTO and WIPO searches for "Local AI Anti-Spam" and "LocalAI".
2. Register `localaiantispam.com` (about $10/year), for the product site and the cloud API subdomain.
3. Mark D-1 as decided in the dev plan.

If you'd rather have a brandable name (stronger trademark, weaker search match), decide **before** S5 (plugin core), when the prefix gets baked into the code and the database.
