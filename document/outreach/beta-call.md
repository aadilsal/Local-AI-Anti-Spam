# Beta-site call: real contact-form messages

**Purpose:** collect consented, real contact-form messages (spam *and* genuine enquiries) for the hand-labelled test set. Every published metric is measured on this set (PRD §9.4), so it is the most important data the project has. Target: **500 labelled messages by the end of week 2**, and 1,000–2,000 over time.

> Draft. The data-handling terms below are not legal advice. Have them reviewed before collecting data from sites in the EU or UK.

---

## 1. Message to send (email, WhatsApp, LinkedIn)

> **Subject: Could your contact form help me build a free spam filter?**
>
> Hi [name],
>
> I'm building a free WordPress plugin that filters contact-form spam with a small AI model that runs on the website's own server, so no data goes to a third party and there are no check limits.
>
> To make sure it never blocks real customers, I need to test it on **real contact-form messages**, both the spam and the genuine enquiries. Would you share the messages your site's contact form has received?
>
> - It takes about 10 minutes, once (details below).
> - I remove names, email addresses and phone numbers as soon as the data arrives. I keep only the message text.
> - It's used only to test and train the filter, never published, never shared, and deleted whenever you ask.
> - In return: free Pro for your site for life, and early access.
>
> Interested? Reply and I'll send the two-step instructions.
>
> Thanks,
> Aadil

## 2. Instructions to send after a "yes"

Pick whichever is easiest. Older messages are fine: the last 6–12 months is ideal.

**Option A: export stored entries (best).**
- *Contact Form 7 with Flamingo:* Flamingo → Inbound Messages → Export (include the Spam view too).
- *WPForms Pro, Gravity Forms, Fluent Forms, Formidable:* Entries → Export CSV (include spam entries if your plugin keeps them).
- *WordPress comments:* the Spam and Approved comment lists both help.

**Option B: forward notification emails.**
Search your inbox for the contact-form notification emails, and forward them (or export the folder) to **[collection address]**. If your spam filter or Akismet catches some, include those too.

**Option C: going forward.**
Add a mail rule that forwards new contact-form notifications to **[collection address]** for the next 4–8 weeks.

Tell me roughly what your business does (e.g. "plumber, Leeds"). It helps check the model works across business types.

## 3. Data-handling terms (send with the instructions)

1. **What we collect:** the text of messages sent through your website's contact form or comments, and whether you consider each one spam.
2. **What we remove on arrival:** names, email addresses, phone numbers, postal addresses, IP addresses, and any form field that isn't the message itself. The original export is deleted after processing.
3. **What we keep:** the de-identified message text, a spam/not-spam label, and your business type.
4. **What it's used for:** testing and training Local AI Anti-Spam's models only.
5. **What we never do:** publish the messages, share them, or sell them. Only aggregate statistics (for example "false-positive rate 0.4%") are ever published.
6. **Storage:** encrypted, on the developer's machine; not in any public repository.
7. **Withdrawal:** email **[contact address]** at any time and everything from your site is deleted within 14 days.
8. **Your side:** you confirm you're allowed to share these messages for this purpose under your own privacy policy.

## 4. Intake checklist (for us)

- [ ] Log the site in `data/real/SITES.md` (gitignored): site ID, business type, date, consent email saved.
- [ ] Run the redaction script before anything else touches the export (built in W1-02; until then, keep raw exports encrypted and unopened).
- [ ] Delete the raw export once the redacted version is checked.
- [ ] Send a thank-you and record the free-Pro promise.

## 5. Tracking

Track who you asked and what arrived in `data/real/SITES.md`, which is gitignored, **not in this file**: this repository is public.
