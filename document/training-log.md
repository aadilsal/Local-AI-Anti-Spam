# Training log

A running record of data builds, generation runs and model runs: what was run, on what data, and what came out. Newest first. Full reports live in `training/runs/<id>/report.md`, which is gitignored, so the numbers that matter are copied here.

## Current state (7 Nov 2026)

| Item | State |
|---|---|
| Where it runs | The owner's PC only: RTX 2060 6 GB, Ryzen 5 2600X, 16 GB RAM. No Kaggle or Colab |
| Public data | Built: 302,068 rows; counts by source are in `public_v1.manifest.json` |
| Synthetic data | Phi and Qwen complete, including the focus top-up. `synthetic_v1.parquet` built: 49,830 rows (26,260 ham, 23,570 spam) |
| Local model | Latest: `20261107-095859-production`. Synthetic test: 0.33% FPR, 90.5% recall. Not ready to ship; no real-message test yet |
| Cloud model (M6–M8) | Latest: `20261107-101019-cloud-production`. Synthetic test: 0.17% FPR, 91.0% recall. int8 passes M8. No real-message test yet |
| Real test set | 0 of 500 messages. Depends on beta sites, so no PRD §9.4 gate can be measured yet |
| Known gaps | `python -m laas.label`, named in labelling-guidelines.md, does not exist yet. `laas.train`, `laas.export` and `laas.eval --run`, named in agents.md, do not exist yet either |

## Log

### 10 Nov 2026: allow and block rules; comments classified once

- **Rules (F9, design.md §3.6):** `wp_laas_rules` (schema version 2, so existing sites upgrade on load) holds email, domain, IP and phrase rules. Domains match the sender's email domain and its subdomains; phrases are plain case-insensitive substrings, never regexes. Allow beats block, and rules run before the empty and language gates, so an explicit rule applies to any message. Values are normalised (`https://www.Example.com/x` → `example.com`) and invalid ones refused. Hits are counted, except for the Diagnostics self-test.
- **Admin:** a Rules screen (add, list with hit counts, remove) and, after a single *Not spam*, an "Always allow <sender>?" button. Held-by-rule messages say which rule held them.
- **Bug found by the rules test:** WordPress 6.9 applies `pre_comment_approved` twice for each new comment (from `wp_allow_comment()` and again from `wp_new_comment()`), so every genuine comment was scored twice and rule hits were doubled. The comments adapter now reuses its verdict for the same comment within a request.
- **Checks:** 377 PHPUnit tests (16 new for rules); `tests/wp/rules-check.php` (a harmless comment from a blocked domain goes to Spam, a spammy one from an allowed sender is delivered, each rule counts one hit); `admin-check.ps1` now 35 checks, including the allow offer, invalid values, removal and a subscriber being refused. Comments, CF7 capture and WPForms checks still pass.

### 10 Nov 2026: WPForms

- **Adapter** (checked against WPForms Lite 2.0.4): `wpforms_process_honeypot` at priority 20, after WPForms' own honeypot. It runs once fields are formatted and before any entry or email; returning a reason makes WPForms stop silently, so the visitor sees the normal confirmation (WPForms' own behaviour for spam it catches). Password, hidden, payment, card, captcha, signature and file fields are neither classified nor stored.
- **Release** re-sends the form's notifications through WPForms' public `entry_email()` with the stored fields. It counts as delivered only if a mail actually went out (`wp_mail_succeeded`, or a mail plugin short-circuiting `pre_wp_mail` with success); if notifications are off for the form, release reports failure and the row stays held.
- **End to end in wp-env** (`tests/wp/wpforms-check.php`, through WPForms' own `process()`): a booking enquiry notified the admin; an SEO pitch (score 1.00) was held, sent no notification and showed no error; release sent the form's notification with its subject and the message. PHPUnit: 361 tests; PHPCS clean.

### 10 Nov 2026: admin screens

- **Built (design.md §3.1, 3.2, 3.3, 3.7):** an Anti-Spam menu whose badge counts messages held since the user's last visit; the quarantine list (lowest score first, held and released views, search across sender and body, row and bulk *Not spam* / *Delete*, a five-segment score bar with a text alternative); the reason view (body as escaped plain text, score against the threshold in use, the words behind the score, links counted but never clickable); Settings → General (protection per integration, sensitivity, possible-spam tag, retention 1–365 days, store IP) and Settings → Diagnostics (model, thresholds, a three-sample self-test, the error log); and a one-time activation notice.
- **Borderline CF7 mail** is delivered with the possible-spam tag on the site owner's subject (not on the autoresponder).
- **Security:** every action is a POST checked with a nonce and `laas_manage_quarantine` (given to `manage_options` users, filterable); settings go through the Settings API with a sanitiser.
- **Checked over HTTP in wp-env** (`tests/wp/admin-check.ps1`, 27 checks pass): XSS payloads in the name, body and feature fields come out escaped in the list and the reason view; a POST without a nonce gets 403; a subscriber gets 403 on the page and on actions; a logged-out visitor is sent to login; *Not spam* delivers, and a release that can't deliver reports failure and keeps the row held; bulk delete works; settings save and sanitise (retention capped at 365, HTML stripped from the tag, per-integration switches kept); the self-test passes 3 of 3. A borderline CF7 message arrived as `[Possible spam] … "Quick question"`.
- **Not yet:** the "Always allow this sender" step after *Not spam* (needs the Rules screen), the dashboard widget, the digest email and latency figures in Diagnostics.

### 10 Nov 2026: quarantine and Contact Form 7

- **Quarantine:** `wp_laas_quarantine` (architecture §6.1) is created on activation and on a schema-version bump; a daily `laas_retention` cron deletes rows older than 30 days (setting `retention_days`) in batches of 500; `uninstall.php` drops the table, options, cron and comment meta. The sender IP is stored only while the `store_ip` setting is on (default).
- **CF7 adapter** (checked against CF7 6.2.4.1): `wpcf7_spam` at priority 20, after CF7's own checks. The message comes from textareas and message-like text fields, sender email and name by field type, and fields that look sensitive (password, card, token, quiz, captcha) are neither classified nor stored. Spam is held with the posted values; if it can't be stored, it is delivered.
- **Release:** rebuilds CF7's `mail` (and active `mail_2`) from the form's templates and the stored values, with special tags from stored meta, and sends it with `wp_mail`. Uploaded files can't be resent (CF7 deletes them), so file fields show their names. A failed delivery leaves the row held.
- **End to end in wp-env**, through CF7's real REST endpoint: a booking enquiry was mailed; an SEO pitch (score 1.00) was held, and CF7 showed the visitor its usual error message. Releasing it sent CF7's mail to the admin with the original subject, body and Reply-To; the row became `released` and a second release was refused. No errors were logged. PHPUnit: 359 tests pass; PHPCS clean.

### 7 Nov 2026: local model exported into the plugin; plugin core started

- **Export:** `python -m laas.export --run runs/20261107-095859-production` wrote `plugin/model/model.bin` (2,099,155 bytes, under the 3 MB M3 limit; `t_low` 0.5817, `t_high` 0.9748), `parity/release-fixtures.json` (93 inputs) and `training/eval/report.md`. It refuses experiment runs, a featurizer version mismatch, thresholds out of order and files over 3 MB. **Development build only:** the PRD gates need the real test set.
- **Parity on the shipped model:** PHP matches Python's score within 5e-5 on all 93 inputs (`ReleaseModelTest`), alongside the existing 233-case parity suite.
- **Benchmark (PHP 8.3 on this PC):** worst case, an 8 KB message, p95 3.5 ms (budget 20 ms); 8.1 MB peak extra memory (budget 16 MB). Loading takes 1.6 ms, or 16 ms with the once-per-version checksum. Shared hosting will be slower, so CI should keep running the benchmark.
- **Plugin core:** `Classifier\Pipeline` (empty and language gates, model, thresholds from `model.bin` plus the Relaxed/Balanced/Strict offset, a +0.05 lean for messages under 5 words, fail-open on any error), `Submission`, `Verdict`, `LanguageGate`, a `laas_log` ring buffer with no message content, the public `laas_classify()` and the `laas_pre_classify`, `laas_score`, `laas_thresholds` and `laas_verdict` filters, and the first integration: WordPress comments (spam goes to core's Spam list; logged-in users are trusted). PHPUnit: 354 tests pass, and PHPCS (WordPress-Extra, PHP 7.4 compatibility) is clean.
- **In real WordPress (10 Nov 2026, wp-env, PHP 8.3):** `tests/wp/comments-smoke.php` posts comments through `wp_new_comment()`. A genuine comment scored 0.17 and was held only by core's first-comment moderation; an SEO pitch and crypto link spam scored 1.00 and went to Spam; a Russian comment was delivered unchecked. `laas_classify()` worked and nothing was logged. CI passed on PHP 7.4, 8.1 and 8.3.
- **Not yet:** quarantine table, rules, admin screens, Contact Form 7 and WPForms adapters, uninstall, and running the WordPress test in CI.

### 7 Nov 2026: third model runs, with every intent measured and per-channel int8

Both used `public_v1` + `synthetic_v1` with the new split, production mode, holding out `youtube_spam`: 96,549 training rows (18,079 spam). Runs: `20261107-095859-production` and `20261107-101019-cloud-production` (best epoch 3). **No PRD gate is measured here, because no metric uses real contact-form messages.** The split changed, so these numbers can't be compared row for row with earlier runs.

| Set | Local FPR | Local recall | Cloud FPR | Cloud recall |
|---|---:|---:|---:|---:|
| Synthetic test (3,618 ham, 3,140 spam) | 0.33% | 90.5% | 0.17% | 91.0% |
| Recall at 0.5% FPR, synthetic test | | 91.9% | | 96.4% |
| YouTube holdout (322 ham, 337 spam) | 2.17% | 82.5% | 2.80% | 85.2% |

- **Both models are within their pooled synthetic gates** (local ≤ 0.5%, cloud ≤ 0.3%). Per domain, `syn_comment` still fails for both (local 0.76% on test and 1.59% on val; cloud 1.01% and 1.16%), and so does `syn_casual_chat` for local (0.73%).
- **The cloud model now clearly beats the local one** on synthetic test (AUC 0.9978 against 0.9953).
- **The targeted intents improved.** Measured on synthetic val + test, the models hold 0.4% (local) and 0.1% (cloud) of `vague_callback` (1,642 rows), and 1.3% and 1.1% of `genuine_comment` (1,086 rows). Held comments are mostly casual-chat style, and a few really do promote something.
- **Weakest spam** (share caught, local / cloud): `keyword_stuffing` 70% / 69%, even though it's split by message; `guest_post_request` 83% / 86%; `crypto_investment` 93% / 78% on cloud.
- **M8 passes:** int8 recall 92.47% against fp32 91.06% on the whole production-tier test (32,870 rows), each at its own threshold, at the same 0.04% FPR. A game was running during part of this step, so the latency figures (p50 5 ms, p95 20 ms) are high.
- **Email (eval-only sources):** cloud holds 5.15% of email ham and catches about 50% of Enron and SpamAssassin spam. These sources aren't contact forms and can't be trained on.

### 7 Nov 2026: synthetic split covers every intent

- **Problem:** synthetic val and test came from hashing whole templates (intent + style). With 1–8 styles per intent, that left `vague_callback`, `self_promo_comment` and `crypto_investment` out of both, so the intents behind most false positives weren't measured.
- **Fix:** `laas.synth.ingest` now gives one whole style to test and one to val for every intent with at least 5 styles, so those are still unseen templates (PRD D4). `phishing`, `advance_fee_order`, `comment_link_spam`, `detailed_enquiry` and `keyword_stuffing` have 1–4 styles, so they're split 70/15/15 by message instead. That measures them on styles they also train on, which flatters their scores.
- **Result:** every one of the 24 intents is now in train, val and test: train 35,199, val 7,873, test 6,758. Results from before this change can't be compared directly; the same-row comparison below is the last like-for-like one.

### 7 Nov 2026: int8 fix for M8

- **Cause:** `quantize_dynamic` used one scale per weight tensor. Converting the `20261107-054203` model's fp32 file to six variants, and measuring recall loss at matched FPRs of 0.3%, 0.5% and 1% on synthetic val (5,877 rows) and test (8,949 rows, 3,863 spam), gave:

| Variant | Size | Loss on val (points) | Loss on test (points) |
|---|---:|---|---|
| Per-tensor (old) | 23.0 MB | 1.79, 1.24, 0.93 | 2.20, 2.95, 2.25 |
| **Per-channel (new)** | 23.2 MB | 0.25, 0.00, 0.31 | -0.52, 0.36, 0.36 |
| Per-channel, MatMul only | 58.9 MB | 0.00, 0.12, 0.31 | -0.78, -0.03, 0.36 |
| Per-channel, FFN output layers kept fp32 | 69.5 MB | 0.25, 0.00, 0.00 | 0.85, -0.18, -0.21 |

- **Change:** `train_cloud` quantises per channel and checks M8 on the whole production-tier test set by default. The old 4,000-row sample held about 470 spam rows, so a single message moved recall by 0.2 points against a 0.5-point gate.

### 7 Nov 2026: second model runs, compared with the first on the same rows

Both used `public_v1` + the rebuilt `synthetic_v1`, production mode, holding out `youtube_spam`: 98,496 training rows (19,644 spam). Runs: `20261107-053415-production` and `20261107-054203-cloud-production`. **No PRD gate is measured here, because no metric uses real contact-form messages.**

Old and new models scored on the same rows, each at its own `t_high`. Report tables use different splits, so use these to compare runs. The old models trained on some templates now in the synthetic test, which flatters them there.

| Rows | Local FPR, old → new | Local recall, old → new | Cloud FPR, old → new | Cloud recall, old → new |
|---|---|---|---|---|
| Synthetic test (3,149 ham, 3,658 spam) | 3.21% → 0.19% | 98.4% → 90.0% | 3.84% → 0.57% | 99.2% → 92.3% |
| YouTube holdout (322 ham, 337 spam) | 2.48% → 2.80% | 27.0% → 84.6% | 2.80% → 5.28% | 39.5% → 87.8% |

- **Real unseen comments improved most.** At 1% FPR, holdout recall went from 18% to 80% (local) and 16% to 74% (cloud). With 322 ham, 1% is about 3 messages. YouTube comments from other videos are now in training, so this says nothing about contact forms.
- **On synthetic rows, most of the change is the threshold.** At 0.5% FPR, recall barely moved: local 93.7% → 93.6%, cloud 91.4% → 92.0%. The new thresholds trade recall for fewer held enquiries, as intended.
- **Coverage gap:** the template hash leaves `vague_callback`, `self_promo_comment` and `crypto_investment` out of synthetic val and test, and puts `genuine_comment` in val only. `vague_callback` and `genuine_comment` caused about 60% of the first runs' false positives, so the top-up's main target isn't measured on unseen templates.
- **The production-tier FPRs (0.07% local, 0.11% cloud) mostly reflect chat and support ham.** Use the synthetic row above instead.
- **The cloud model doesn't clearly beat the local one.** At 0.2% FPR on synthetic test, recall is 70% cloud against 89% local, and about level at 0.5%. Cloud leads on public test rows (93.2% against 89.8% at 0.5% FPR).
- **Errors:** missed spam is mostly short phishing notes, some of them unclear even to a reader ("Invoice attached"). Many held ham rows promote a link or look mislabelled, and many of those come from older prompts, so the measured FPR may be too high. Cloud also holds 4.4% of `thanks_feedback`, mostly short notes with links.
- **int8 (M8):** on 8,949 test rows (3,863 spam), it loses 2.2–3.2 points of recall at matched FPRs from 0.3% to 1%, and more at 0.2%. It still fails, and the smaller loss seen on the first model doesn't hold here. Measured on this PC's CPU, int8 is only slightly faster (p95 15 ms against 17 ms), but much smaller (23 MB against 91 MB).

### 7 Nov 2026: focus run complete, `synthetic_v1` rebuilt

- **Run:** overnight on this PC with prompt v5 and `--focus`, 2,000 requests per generator. Qwen gave 8,776 messages (25 failed requests, 1.2%) and finished at 4:40 AM. Phi gave 8,083 messages (170 failed, 8.5%) and finished at 8:10 AM. No request is missing and no line is broken. One Ollama 500 was retried automatically.
- **Sample check (random rows from every intent, both generators):** phishing, scam orders and comments read much better than before. Two problems showed up, in the older files as well: pitches written from the buyer's side ("our website isn't ranking, can you help"), and ham telling the reader to visit a link. Phi puts a link or domain in far more ham than Qwen does: 22.7% against 2.8% in the focus files.
- **New ingest rules:** `pitch_reads_as_buyer` leaves out 388 rows. As spam they would teach the model to block real enquiries to web agencies. `ham_selling` now also catches "visit" or "check out" followed by a link, which raises it from 298 rows on the old files to 1,286 on all four. In samples, most dropped rows were correct, but some genuine ones went too. Other ham with links stays.
- **Dataset:** 49,830 rows: 27,001 Qwen and 22,829 Phi; train 37,146, val 5,877, test 6,807. Left out in total: 1,286 selling ham, 679 promotional comments, 652 old `fake_enquiry_pitch`, 424 phishing, 398 scam orders, 388 buyer-side pitches.
- **Still thin:** `long_email` has only 164 ham rows and no spam, and `rambling` has 691 spam rows against 1,838 ham. Long-message results will rest on few examples.

### 7 Nov 2026: fixes from the analysis, prompt v5, targeted generation

- **Data:** `laas.synth.ingest` leaves out rows whose text contradicts their label and counts them in the manifest: 803 promotional comments, 298 selling ham, 277 customer-voice or tell-less phishing, 180 scam orders with no tell, and 652 old `fake_enquiry_pitch`. That leaves 34,619 rows before the top-up. The rules are rough keyword checks, and a sample showed some genuine rows removed too. Synthetic val and test are now 15% each, not 10%.
- **Public data:** `public_v1` is now 302,068 rows. YouTube is split by video: `youtube_train` trains, and `youtube_spam` (now 659 rows, not 1,655) stays as the holdout, so holdout results aren't comparable with the first runs. `deysi_spam` is added for evaluation only. The blog comment CSV from the Google codelab returned 404, and metasmoke has no public dump or data licence, so neither is used. Training-grade real spam is still only SMS and YouTube comments.
- **Training:** the 60,000 caps now apply only to public rows, so all synthetic rows are trained on. Thresholds must hold on all ham and on synthetic ham alone, and early stopping uses synthetic ham. Reports gain `test (synthetic only)`. The int8 model gets its own thresholds, saved in `metrics.json`.
- **Prompt v5:** comments carry no links and no ham sells anything. Phishing and scam orders say who the writer pretends to be. New: `found_online_enquiry`, `detailed_enquiry`, `self_promo_comment` and the `long_email` style. `--focus` generates only the targeted grid, including spam in the styles that only ham had. Long styles ask for 2 messages per request.
- **Sample check:** 16 Qwen focus requests gave 68 messages with none failed and 1 caught by the rules. Phishing and scam orders read correctly and comments had no links. Rambling `crypto_investment` still opens like a customer. Phi's v5 output was sampled after the full run (see the entry above).

### 7 Nov 2026: search for more public datasets

No public dataset of real contact-form submissions was found, and no blog or forum comment-spam set has been confirmed usable. Most "permissive" spam sets on Hugging Face and Kaggle are unexplained or re-merged copies of older email and SMS data, so their stated licences can't be trusted without provenance. `mshenoda/spam-messages` says it merges SMS Spam Collection, Enron and `telegram-spam-ham`, which is itself email, not Telegram. `mnemoraorg/spam-email-5k5` and `ahmedsial` appear to contain the UCI SMS data. `Deysi/spam-detection-dataset` states Apache-2.0 but no origin (R10). **Leads still to check:** Charcoal's metasmoke spam-post data (no download or data licence found) and the Kaggle blog comment CSV used in a Google codelab (licence unverified). Nothing was downloaded.

### 7 Nov 2026: error analysis of the first model runs

Both saved models scored 25,344 rows on the CPU: all non-chat evaluation rows, plus samples of chat-type rows and training-side synthetic rows. Hand-read counts are one reader's judgement on small samples. No model or data changed.

- **False positives are concentrated.** `vague_callback` and `genuine_comment` are 60% of the cloud model's false positives and 63% of the local model's on synthetic ham. Style tables mostly reflect this, because a split can hold one intent per style (`syn_casual_chat` in val is all `vague_callback`).
- **Ham labels:** of 45 ham messages flagged by both models, about 13 read as promotion or a pitch, 7 are unclear and 25 read as genuine customers. 27% of `genuine_comment` rows contain links.
- **Spam labels:** noise sits mainly in `advance_fee_order` and `phishing`. A rough keyword check finds a scam tell in 59% of Qwen's `advance_fee_order` rows and 86% of Phi's. About 5 of 18 sampled `phishing` rows read as customers. `seo_pitch` and `web_design_pitch` samples were almost all correct, so short buyer-voice misses are the exception there.
- **Training data doesn't match the product.** Public ham has a median of 8 words, from chat and assistant data, and the only real spam is 976 SMS messages. Spam styles exclude `angry`, `rambling` and `typo_heavy`, so those styles appear only as ham.
- **Length drives the local model's email failure.** `log_length` is its largest dense weight (+2.52 per std), and its email FPR rises from 12% at up to 25 words to 86% above 400. Email AUC is weak in both models: 0.77 local, 0.84 cloud.
- **Thresholds alone aren't enough.** Set on synthetic val ham and measured on synthetic test, a 0.5% target gives 0.49% FPR / 82.4% recall (local) and 0.60% / 85.3% (cloud). A 2% target gives 1.15% / 91.5% and 1.74% / 97.6%. Val has only 11 synthetic templates against 21 in test, and is harder than test.
- **int8 is mainly a threshold problem.** With each model's own threshold on 6,000 rows, recall is 95.07% against 95.47% at 1% FPR and 97.34% against 97.68% at 2%, both within the 0.5-point limit. AUC is 0.9954 against 0.9957. The 0.3% and 0.5% FPR comparisons are unstable on this sample.

### 6 Nov 2026: first local and cloud model runs on this PC

Both used `public_v1` + `synthetic_v1`, production mode, holding out `youtube_spam`: 73,042 training rows (13,042 spam). **No PRD gate is measured here, because no metric uses real contact-form messages.**

| Set | Local FPR / recall | Cloud (fp32) FPR / recall |
|---|---|---|
| Target FPR | 0.5% | 0.3% |
| val | 0.50% / 93.14% | 0.30% / 95.98% |
| test (production tier) | 0.57% / 97.23% | 0.45% / 98.58% |
| test (all tiers) | 3.78% / 94.69% | 1.33% / 86.94% |
| holdout (`youtube_spam`) | 2.96% / 30.99% | 4.50% / 40.25% |

**Problems found:**

- **Ham that resembles contact-form messages fails the FPR gate.** `chat`, `assistant` and `support` pass and make up about 85% of production test ham. Across the 3,668 synthetic ham rows in that test set, the FPR works out at about 3.4% (local) and 2.7% (cloud), from the per-domain tables. `syn_comment` is 15.71% in both models, and part of that looks like wrong labels: 62 of its 280 ham rows contain links, and many of those read as comment spam ("Check out www... Email: ..."), probably because ham prompts ask one writer in four to mention a link. Which rows the models flagged has not been checked.
- **Unseen spam is mostly missed:** 31% and 40% recall on `youtube_spam`. Email ham is also often flagged: 57.36% (local) and 16.02% (cloud). No email is trained on.
- **Most synthetic ham was not trained on.** The 60,000 ham cap samples at random, so only 6.8% of training ham is synthetic (about 4,080 of 14,709 rows). Weighting raises its share but can't add the rows that were dropped.
- **int8 fails M8:** recall falls from 98.00% to 95.71% (2.29 points against a limit of 0.5), while FPR falls from 0.36% to 0.19%, using the fp32 threshold. Setting a threshold for int8 itself may close much of this gap; not yet tested. Size is 23 MB, with 15 ms p95 latency on this PC's CPU.

### 6 Nov 2026: Qwen run complete, `synthetic_v1` built

Qwen finished at 19:53 in about 8 hours, all on prompt v4 with one checkpoint (`qwen2.5:7b@845dbda0ea48`). All 4,000 requests are present, with no broken lines and no duplicate rows. 19,516 messages: 10,402 ham and 9,114 spam. 46 requests failed (1.1%, against 11.6% for Phi).

`laas.synth.ingest` wrote 36,177 rows: Qwen 19,515 and Phi 16,662. Train has 26,775 rows, val 2,997 and test 6,405, from 121 templates. **Decided:** Phi's v3 `fake_enquiry_pitch` (652 messages) is left out at ingest because much of it reads as real customers. The raw file is unchanged, so this can be undone. Phi's `loan_offer` is kept, including the 82 a rough check flagged as borrowers.

Possible label signals in the combined data, none acted on. **Fine:** links (ham 21.9%, spam 20.8%) and 555 numbers (ham 7.2%, spam 8.9%). **To measure at training:** `example.` domains (ham 3.8%, spam 0.7%) and email addresses (ham 24.0%, spam 19.3%) lean towards ham, and ham is slightly longer (median 33 words against 29). **Next:** train both models.

### 6 Nov 2026: type-checker errors cleared

Pylance's errors in `train_local.py` and the notebook, and the same kinds in `train_cloud.py`, `gen.py` and `test_data.py`, are fixed with type annotations and ignore comments for imports that only exist on Colab or at run time. Nothing about training or generation changes. The one notebook code change is wrapping a name in `str()` in a printed line. The running Qwen job isn't affected, and a resume is unaffected too.

### 6 Nov 2026: Qwen sped up about 4x by keeping it all on the GPU

Ollama's memory estimate left 16% of Qwen on the CPU even at `--max-model-len 2048`. The new `--num-gpu 99` option puts every layer on the GPU, where it fits: 5.3 of 6.1 GB, with Windows and open apps included. Speed went from about 19 to about 56 tokens per second. A 64-request sample through `laas.synth.gen` took 508 s (7.9 s per request, about 30 s before) and gave 308 messages (160 spam, 148 ham) with 1 failed request and no server errors, at 68 °C. For 4,000 requests that means about 9 hours and about 19,000 messages. The sample is not used in the dataset. **Limit:** only about 800 MB of GPU memory is spare, so a game or other GPU-heavy app could cause errors during a run.

**Label noise found in that sample:** half of `fake_enquiry_pitch` (5 of 10, all `casual_chat`) reads as a real customer with a brief mention of a product, and most of `loan_offer` (at least 8 of 10) has the writer asking for a loan or a booking instead of offering one. The two intents are about 12% of the sample's spam. Rows record their `intent` and `style`, so they can be dropped at ingest if the prompts aren't changed.

### 6 Nov 2026: prompt v4 for `loan_offer` and `fake_enquiry_pitch`

Both briefs now say what must be sold and that the writer never asks for money or about the business's products. A check of 18 Qwen requests (85 messages, read by hand, not used in the dataset) found about 6 of 40 `loan_offer` and about 9 of 45 `fake_enquiry_pitch` still reading as a real customer, against at least 8 of 10 and 5 of 10 before. The misses bunch by request: one `formal_email` IT support reply was all genuine-sounding, and so was most of one `short_note` hair salon reply. **Known limit:** expect about 2% of Qwen's spam to read like real customers, down from about 8%.

`split_for` now ignores the prompt version, so a template stays on one side of the split across prompt versions and generators. No synthetic dataset had been built yet, so nothing needed rebuilding. The placeholder filter now also catches `[Owner’s Name]`.

**Phi still has the old prompts (v3).** Its `fake_enquiry_pitch` (652 messages) has the same problem, judging by a sample of 12, and its `loan_offer` (403 messages) is mostly fine, with a rough check flagging 82 as borrowers. Not yet handled.

### 6 Nov 2026: Phi run complete

Finished at 11:13 after being stopped and resumed. All 4,000 requests are present, with no broken lines, no duplicates, one checkpoint (`phi4-mini:latest@78fad5d182a7`) and prompt v3 throughout. 17,353 messages: 9,279 ham and 8,074 spam. 462 requests failed (11.6%) and are not retried; `formal_email` lost 34% of its requests (241 of 708) against 3–10% for other styles, leaving 2,192 messages in that style. A check of the requests that fail is still open.

Possible label signals, none acted on yet. **Fine:** 555 numbers (ham 9.1%, spam 9.7%) and links (ham 32.0%, spam 33.5%) are even. **To measure at training:** `example.` domains (ham 5.4%, spam 1.2%) and email addresses (ham 31.7%, spam 19.6%) lean towards ham, and spam is shorter (median 26 words against 36). PyTorch sees the RTX 2060 (CUDA 12.6) and ran a test calculation on it, now that the installer has finished.

**Power cuts:** `laas.synth.gen` now removes a half-written last line before it resumes, so a sudden cut no longer crashes the resume. `laas.synth.ingest` skips such a line without changing the file. Rows already saved for a request cut partway through stay, so that request may keep fewer messages. A request with no complete row is generated again.

### 5 Nov 2026: Phi run stopped by an Ollama 500 at 1,200 requests

At 15:05 Ollama returned HTTP 500 partway through a reply (253 tokens in, the only 500 in the log, with no error message) and `laas.synth.gen` stopped, as designed. Nothing saved was lost: 1,200 requests and 5,225 messages. Replaying the same request with its own seed twice and with two other seeds gave 200 each time, and its 323-token prompt matches the failed one, so a resume will get past it. The cause is unknown. The installer was running and physical memory was low (1 GB free), but nothing links either to the failure. Failed requests are at 130 of 1,200 (11%), of which 69 are `formal_email`. 524 of 5,225 messages use 555 numbers.

**Fix:** `ollama_post` now tries a request up to three times, 30 seconds apart, on server errors, timeouts and dropped connections, so a long run isn't stopped by one rare error. Client errors such as a missing model still stop it straight away. If every try fails the run stops as before, and a resume retries the request. A run started before this change doesn't get the retry.

### 5 Nov 2026: full Phi run started (early check)

Started 12:53 from the owner's terminal. At 229 requests: 984 messages, 13% of requests failed, about 6.6 s per request, on course to finish around 20:15. Links appear in 34% of ham and 38% of spam, so a link alone should not become a spam signal. A rough check flagged 17 of 448 spam messages (4%) as reading like a buyer, mostly `advance_fee_order`, and some of those are correct. **To check when the dataset is built:** `formal_email` fails far more often than other styles (14 of the 29 failures), and 101 of 984 messages use 555 phone numbers, which could become a label signal if they are uneven between ham and spam.

### 5 Nov 2026: third sample and go-ahead for full generation (prompt v3)

60 Phi requests at `--max-model-len 4096 --max-tokens 2400`: 266 messages, 7 failed requests (12%), about 6.5 s per request, 100% on GPU. Comment spam, crypto and scam orders now read correctly. `keyword_stuffing` was limited to the `short_note` style, and `[email protected]` and bracketed domains are now filtered. **Known limit:** some very short spam from Phi still reads like a real request (for example "I've spotted errors on my website. Could you assist?"), so expect some label noise in `syn_short_note`. Estimates: Phi 4,000 requests in about 7 hours for about 17,700 messages; Qwen 4,000 requests in about 33 hours for about 20,000 messages. The last prompt tweaks were checked by unit tests, not a further sample.

### 5 Nov 2026: second sample (prompt v3, `--max-model-len 2048`)

Qwen: 60 messages, 0 failed requests, about 30 s per request, model 16% on CPU, so the smaller context did not speed it up. Phi: 50 messages, 2 of 12 requests failed, about 6 s per request after loading, 100% on GPU. The installer was running during both, so these timings may improve. Earlier label problems were fixed, but `keyword_stuffing` came out as real enquiries and some `crypto_investment` writers promoted nothing, so those descriptions were tightened. Phi often ignores the style: its `typo_heavy` has no typos and its `non_native` reads as fluent.

### 5 Nov 2026: first local generation sample (prompt v2)

12 Qwen requests through Ollama gave 60 messages with 0 failed requests, at about 25 s per request with the model 18% on CPU. **Not used.** Review found spam that reads as genuine enquiries (`comment_link_spam`, `advance_fee_order`), near-copies within a reply, and repeated fake phone numbers. Prompt v3 fixes these; `--max-model-len 2048` is being tested to fit the model on the GPU.

### 5 Nov 2026: moved to the local PC

- **Found:** `training/data/` and `training/runs/` are gitignored and were not on this PC. The original `spam_ham_corpus_v1`, the 909-message synthetic pilot and earlier run outputs were not found on the C: or F: drives (folder names up to four levels deep). Earlier results are therefore only on record in PRD review notes R13, R14 and R15.
- **Decided:** rebuild the public data from original sources with a script (`laas/data/public.py`), so any machine can reproduce it. Generate with Ollama, because vLLM does not run on Windows. See review note R16.
- **Fixed:** `.gitignore`'s `data/` rule also matched the source package `training/laas/data/`, so new modules there were silently left out of git. Added an exception for that folder; dataset folders stay ignored.
- **Confirmed by the owner:** `spam_ham_corpus_v1` is gone, and the Kaggle and Colab runs never completed, so there is no earlier data to recover. Everything is built from scratch on this PC.
- **Storage:** F: is a USB hard drive, too slow for a Python environment. `training/.venv` is a junction to `C:/Users/butta/.venvs/laas-training`, and `training/data` and `training/runs` are junctions to `C:\Users\butta\laas\`. Datasets are not backed up anywhere else, but they can be rebuilt from the scripts and manifests.
- **Still to set up after the game install finishes:** PHP and Composer are not installed, and neither are the plugin's `composer` and `npm` packages. That work writes thousands of files to F:.
- **Handover:** the owner runs the big jobs (full generation and model training) from their own terminal, using the commands in agents.md. Claude prepares, reviews results and records them here.
- **Differences from the original corpus:** only 13 sources are rebuilt (all 11 permissive, plus `spamassassin` and `enron_spam` for email evaluation). Other evaluation-only sources are left out, so results are not directly comparable with R13 and R14.
