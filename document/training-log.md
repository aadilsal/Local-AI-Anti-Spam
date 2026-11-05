# Training log

A running record of data builds, generation runs and model runs: what was run, on what data, and what came out. Newest first. Full reports live in `training/runs/<id>/report.md`, which is gitignored, so the numbers that matter are copied here.

## Current state (5 Nov 2026)

| Item | State |
|---|---|
| Where it runs | The owner's PC only: RTX 2060 6 GB, Ryzen 5 2600X, 16 GB RAM. No Kaggle or Colab |
| Public data | Built: 291,425 rows (257,955 trainable, of which 2,077 spam; 33,470 evaluation-only) |
| Synthetic data | Prompt v3 reviewed on samples; full run started by the owner from their terminal |
| Local model | Not yet trained on this PC |
| Cloud model (M6–M8) | `laas/train_cloud.py` written, not yet run |
| Real test set | 0 of 500 messages. Depends on beta sites, so no PRD §9.4 gate can be measured yet |
| Known gaps | `python -m laas.label`, named in labelling-guidelines.md, does not exist yet. `laas.train`, `laas.export` and `laas.eval --run`, named in agents.md, do not exist yet either |

## Log

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
