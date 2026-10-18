# Training data licences (W1-01)

Audit of every data source the models may touch, as required by PRD §13 ("Re-check every dataset licence before training the first production model"). **Not legal advice.**

- **Status:** drafted 10 Oct 2026 from `spam_ham_corpus_v1` (its `sources.csv`, README and `upstream_licences/`). **Owner spot-check of each permissive source is still to do**: open each "Original" link and confirm the licence matches.
- **Rule enforced in code:** only `permissive` rows get `split_policy = train`; everything else is `eval_only` (`laas/data/schema.py`). A production training run raises an error if any other row slips in.

## Allowed for production training (`permissive`)

| Source | Rows kept (spam / ham) | Licence | Obligation when we ship | Original | Owner check |
|---|---:|---|---|---|---|
| `uci_sms`: UCI SMS Spam Collection | 587 / 4,492 | CC BY 4.0 | Attribute in dataset card, model card and plugin readme | archive.ics.uci.edu/dataset/228 | ⬜ |
| `youtube_spam`: UCI YouTube Spam Collection | 750 / 835 | CC BY 4.0 | Attribute | archive.ics.uci.edu/dataset/380 | ⬜ |
| `india_sms`: India Spam SMS Classification | 662 / 1,247 | MIT | Keep copyright + licence notice with redistributed data | github.com/junioralive/india-spam-sms-classification | ⬜ |
| `bitext_support`: Bitext customer support | 0 / 23,863 | CDLA-Sharing-1.0 | If we **redistribute the data** (HF dataset), it must stay under CDLA-Sharing. Trained models are "Results" and are not bound | github.com/bitext/customer-support-llm-chatbot-training-dataset | ⬜ |
| `banking77`: BANKING77 | 0 / 13,022 | CC BY 4.0 | Attribute | github.com/PolyAI-LDN/task-specific-datasets | ⬜ |
| `clinc150`: CLINC150 | 0 / 23,395 | CC BY 3.0 | Attribute | github.com/clinc/oos-eval | ⬜ |
| `hwu64`: HWU64 NLU Evaluation Data | 0 / 25,055 | CC BY 4.0 | Attribute | github.com/xliuhw/NLU-Evaluation-Data | ⬜ |
| `snips`: SNIPS NLU benchmark | 0 / 14,082 | CC0 1.0 | None | github.com/sonos/nlu-benchmark | ⬜ |
| `abcd`: ABCD customer-service dialogues | 0 / 15,691 | MIT | Keep notice with redistributed data | github.com/asappresearch/abcd | ⬜ |
| `multiwoz`: MultiWOZ 2.2 | 0 / 19,375 | MIT | Keep notice with redistributed data | github.com/budzianowski/multiwoz | ⬜ |
| `taskmaster`: Taskmaster 1–3 | 0 / 87,920 | CC BY 4.0 | Attribute | github.com/google-research-datasets/Taskmaster | ⬜ |
| WordPress comment blocklist (`resources/`) | n/a | MIT | Keep notice if bundled in the plugin | (corpus `resources/`) | ⬜ |

**Total trainable: 1,999 spam / 228,977 ham.** No permissive source contains email ham, chat ham, or SEO/web-design solicitation spam.

## Evaluation only (`unclear`)

No licence stated, or a licensed compilation of unlicensed messages. Allowed for experiments and evaluation; **never** production training, and never republished (they contain real personal data).

`exais_sms`, `super_sms`, `uci_sms_translated`, `spamhunter`, `telegram` (provenance doubtful), `enron_spam`, `ceas08`, `nazario`, `nazario_mbox`, `nigerian_fraud`, `ling_spam`, `phish_misc`, `csdmc2010`, `nus_sms`, `honeypot_tweets` (kept out of all splits: account-level labels).

## Evaluation only (`restricted`)

Non-commercial, research-only, or copyright kept by senders.

| Source | Licence |
|---|---|
| `spamassassin` | Copyright remains with original senders |
| `trec07` | TREC research-use agreement |
| `phishing_pot` | CC BY-NC 4.0 (non-commercial) |

## PRD sources not in this corpus

| Source | PRD use | Status |
|---|---|---|
| `Deysi/spam-detection-dataset` | Train with caution | Not included (Hugging Face unreachable when the corpus was built). Provenance undocumented (review note R10): if added, tag it so a retrain without it is one flag |
| Synthetic contact-form set | Main training data | Generator built (`laas/synth/`, Kaggle notebook). Generators, checked on Hugging Face 18 Oct 2026: **Qwen/Qwen2.5-7B-Instruct(-AWQ): Apache-2.0**; **microsoft/Phi-4-mini-instruct: MIT**. Both place no conditions on outputs. `laas.synth.ingest` refuses rows from any generator not listed in `GENERATOR_LICENCES` |
| Hand-labelled real messages | **Test only** | Collecting (beta call). `split_policy = test_only` |
| Opt-in user corrections | Train from model v3 | After launch, under our consent terms |

## Open questions

- [ ] **Distillation and licences.** A teacher model trained on `unclear` data, whose soft labels then train the production model, may carry the same licence question. Until decided, the teacher (cloud model) is trained on permissive + synthetic + consented data only.
- [ ] CDLA-Sharing-1.0: confirm that publishing a dataset mixing CDLA-Sharing rows with CC BY rows is allowed, or publish Bitext rows as a separate config.
