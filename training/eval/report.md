> Exported from `20261107-095859-production` by `laas.export`. **Development build:** the PRD gates need the real hand-labelled test set, which no metric below uses.

# Local model run `local-baseline-20261107-0958`

Mode **production** · trained 2026-11-07T09:58:59+00:00 · 96,549 training rows (18,079 spam) · buckets 2^19 · C=4.0

Thresholds from validation, on all ham and on synthetic ham alone: t_low=0.5817 (<= 5% FPR), t_high=0.9748 (<= 0.5% FPR).

Calibration fitted as if 50% of messages were spam (scores mean P(spam) at that mix; thresholds are set on ham scores, so they don't move). Held-out sources (never trained on): youtube_spam.

Synthetic share of training weight (R15: equal in both classes, so the generator's style isn't a label signal): ham 23.5% of 78,470 rows -> 50.0%, spam 92.5% of 18,079 rows -> 50.0%.

> Not the PRD gate: no metric here is measured on real contact-form messages.

| Set | Ham | Spam | AUC | FPR at t_high (95% CI) | Recall at t_high (95% CI) | Oracle recall @0.5% FPR | ECE | Keyword FPR | Keyword recall |
|---|---:|---:|---:|---|---|---:|---:|---:|---:|
| val | 29,592 | 3,886 | 0.9984 | 0.10% [0.07%, 0.14%] | 91.12% [90.19%, 91.98%] | 96.47% | 0.001 | 0.39% | 20.07% |
| test (production tier) | 29,525 | 3,345 | 0.9982 | 0.08% [0.05%, 0.12%] | 90.46% [89.42%, 91.41%] | 96.47% | 0.004 | 0.26% | 24.45% |
| test (synthetic only) | 3,618 | 3,140 | 0.9953 | 0.33% [0.19%, 0.58%] | 90.54% [89.47%, 91.52%] | 91.88% | 0.026 | 1.71% | 24.11% |
| test (all tiers) | 31,833 | 5,541 | 0.9881 | 2.37% [2.20%, 2.54%] | 87.53% [86.63%, 88.37%] | 54.23% | 0.020 | 1.02% | 26.93% |
| holdout (youtube_spam) | 322 | 337 | 0.9713 | 2.17% [1.06%, 4.42%] | 82.49% [78.08%, 86.18%] | 71.81% | 0.059 | 0.00% | 2.97% |

## Per-domain gate: FPR at t_high <= 0.5% for each ham domain (R14)

PASS = point estimate within the gate; PROVEN = whole 95% interval within it.

| Set | Domain | Ham | FPR (95% CI) | Result |
|---|---|---:|---|---|
| val | `chat` | 10,225 | 0.00% [0.00%, 0.04%] | PROVEN |
| val | `assistant` | 7,457 | 0.04% [0.01%, 0.12%] | PROVEN |
| val | `support` | 7,133 | 0.00% [0.00%, 0.05%] | PROVEN |
| val | `syn_short_note` | 1,019 | 0.29% [0.10%, 0.86%] | PASS |
| val | `syn_non_native` | 781 | 0.13% [0.02%, 0.72%] | PASS |
| val | `syn_comment` | 690 | 1.59% [0.89%, 2.83%] | **FAIL** |
| val | `syn_polite_brief` | 686 | 0.00% [0.00%, 0.56%] | PASS |
| val | `sms` | 547 | 1.10% [0.50%, 2.37%] | **FAIL** |
| val | `syn_formal_email` | 483 | 0.00% [0.00%, 0.79%] | PASS |
| val | `syn_rambling` | 203 | 0.00% [0.00%, 1.86%] | PASS |
| val | `syn_casual_chat` | 143 | 2.80% [1.09%, 6.97%] | **FAIL** |
| val | `syn_typo_heavy` | 140 | 0.71% [0.13%, 3.93%] | **FAIL** |
| val | `comment` | 58 | 1.72% [0.30%, 9.14%] | **FAIL** |
| test (production tier) | `chat` | 10,787 | 0.00% [0.00%, 0.04%] | PROVEN |
| test (production tier) | `assistant` | 7,550 | 0.03% [0.01%, 0.10%] | PROVEN |
| test (production tier) | `support` | 6,956 | 0.01% [0.00%, 0.08%] | PROVEN |
| test (production tier) | `syn_casual_chat` | 956 | 0.73% [0.36%, 1.50%] | **FAIL** |
| test (production tier) | `syn_polite_brief` | 768 | 0.00% [0.00%, 0.50%] | PROVEN |
| test (production tier) | `sms` | 564 | 1.24% [0.60%, 2.54%] | **FAIL** |
| test (production tier) | `syn_formal_email` | 509 | 0.20% [0.03%, 1.10%] | PASS |
| test (production tier) | `syn_comment` | 396 | 0.76% [0.26%, 2.20%] | **FAIL** |
| test (production tier) | `syn_non_native` | 300 | 0.00% [0.00%, 1.26%] | PASS |
| test (production tier) | `syn_typo_heavy` | 281 | 0.00% [0.00%, 1.35%] | PASS |
| test (production tier) | `syn_rambling` | 277 | 0.36% [0.06%, 2.02%] | PASS |
| test (production tier) | `syn_angry` | 111 | 0.00% [0.00%, 3.35%] | PASS |
| test (production tier) | `comment` | 50 | 4.00% [1.10%, 13.46%] | **FAIL** |
| test (synthetic only) | `syn_casual_chat` | 956 | 0.73% [0.36%, 1.50%] | **FAIL** |
| test (synthetic only) | `syn_polite_brief` | 768 | 0.00% [0.00%, 0.50%] | PROVEN |
| test (synthetic only) | `syn_formal_email` | 509 | 0.20% [0.03%, 1.10%] | PASS |
| test (synthetic only) | `syn_comment` | 396 | 0.76% [0.26%, 2.20%] | **FAIL** |
| test (synthetic only) | `syn_non_native` | 300 | 0.00% [0.00%, 1.26%] | PASS |
| test (synthetic only) | `syn_typo_heavy` | 281 | 0.00% [0.00%, 1.35%] | PASS |
| test (synthetic only) | `syn_rambling` | 277 | 0.36% [0.06%, 2.02%] | PASS |
| test (synthetic only) | `syn_angry` | 111 | 0.00% [0.00%, 3.35%] | PASS |
| test (all tiers) | `chat` | 10,787 | 0.00% [0.00%, 0.04%] | PROVEN |
| test (all tiers) | `assistant` | 7,550 | 0.03% [0.01%, 0.10%] | PROVEN |
| test (all tiers) | `support` | 6,956 | 0.01% [0.00%, 0.08%] | PROVEN |
| test (all tiers) | `email` | 1,766 | 40.49% [38.22%, 42.79%] | **FAIL** |
| test (all tiers) | `syn_casual_chat` | 956 | 0.73% [0.36%, 1.50%] | **FAIL** |
| test (all tiers) | `syn_polite_brief` | 768 | 0.00% [0.00%, 0.50%] | PROVEN |
| test (all tiers) | `sms` | 564 | 1.24% [0.60%, 2.54%] | **FAIL** |
| test (all tiers) | `social` | 542 | 2.58% [1.54%, 4.29%] | **FAIL** |
| test (all tiers) | `syn_formal_email` | 509 | 0.20% [0.03%, 1.10%] | PASS |
| test (all tiers) | `syn_comment` | 396 | 0.76% [0.26%, 2.20%] | **FAIL** |
| test (all tiers) | `syn_non_native` | 300 | 0.00% [0.00%, 1.26%] | PASS |
| test (all tiers) | `syn_typo_heavy` | 281 | 0.00% [0.00%, 1.35%] | PASS |
| test (all tiers) | `syn_rambling` | 277 | 0.36% [0.06%, 2.02%] | PASS |
| test (all tiers) | `syn_angry` | 111 | 0.00% [0.00%, 3.35%] | PASS |
| test (all tiers) | `comment` | 50 | 4.00% [1.10%, 13.46%] | **FAIL** |
| holdout (youtube_spam) | `comment` | 322 | 2.17% [1.06%, 4.42%] | **FAIL** |

## val: recall at t_high by spam source

- `synthetic_qwen2.5-7b`: 90.1%
- `synthetic_phi-4-mini`: 92.8%
- `uci_sms`: 89.3%
- `india_sms`: 100.0%
- `youtube_train`: 70.5%

## val: false-positive rate at t_high by ham domain

- `chat`: 0.00%
- `assistant`: 0.04%
- `support`: 0.00%
- `syn_short_note`: 0.29%
- `syn_non_native`: 0.13%
- `syn_comment`: 1.59%
- `syn_polite_brief`: 0.00%
- `sms`: 1.10%
- `syn_formal_email`: 0.00%
- `syn_rambling`: 0.00%
- `syn_casual_chat`: 2.80%
- `syn_typo_heavy`: 0.71%
- `comment`: 1.72%
- `syn_long_email`: 0.00%

## test (production tier): recall at t_high by spam source

- `synthetic_qwen2.5-7b`: 89.0%
- `synthetic_phi-4-mini`: 92.4%
- `india_sms`: 100.0%
- `uci_sms`: 91.4%
- `youtube_train`: 70.9%

## test (production tier): false-positive rate at t_high by ham domain

- `chat`: 0.00%
- `assistant`: 0.03%
- `support`: 0.01%
- `syn_casual_chat`: 0.73%
- `syn_polite_brief`: 0.00%
- `sms`: 1.24%
- `syn_formal_email`: 0.20%
- `syn_comment`: 0.76%
- `syn_non_native`: 0.00%
- `syn_typo_heavy`: 0.00%
- `syn_rambling`: 0.36%
- `syn_angry`: 0.00%
- `comment`: 4.00%
- `syn_long_email`: 0.00%

## test (synthetic only): recall at t_high by spam source

- `synthetic_qwen2.5-7b`: 89.0%
- `synthetic_phi-4-mini`: 92.4%

## test (synthetic only): false-positive rate at t_high by ham domain

- `syn_casual_chat`: 0.73%
- `syn_polite_brief`: 0.00%
- `syn_formal_email`: 0.20%
- `syn_comment`: 0.76%
- `syn_non_native`: 0.00%
- `syn_typo_heavy`: 0.00%
- `syn_rambling`: 0.36%
- `syn_angry`: 0.00%
- `syn_long_email`: 0.00%

## test (all tiers): recall at t_high by spam source

- `synthetic_qwen2.5-7b`: 89.0%
- `enron_spam`: 79.5%
- `synthetic_phi-4-mini`: 92.4%
- `deysi_spam`: 90.0%
- `spamassassin`: 90.6%
- `india_sms`: 100.0%
- `uci_sms`: 91.4%
- `youtube_train`: 70.9%

## test (all tiers): false-positive rate at t_high by ham domain

- `chat`: 0.00%
- `assistant`: 0.03%
- `support`: 0.01%
- `email`: 40.49%
- `syn_casual_chat`: 0.73%
- `syn_polite_brief`: 0.00%
- `sms`: 1.24%
- `social`: 2.58%
- `syn_formal_email`: 0.20%
- `syn_comment`: 0.76%
- `syn_non_native`: 0.00%
- `syn_typo_heavy`: 0.00%
- `syn_rambling`: 0.36%
- `syn_angry`: 0.00%
- `comment`: 4.00%
- `syn_long_email`: 0.00%

## holdout (youtube_spam): recall at t_high by spam source

- `youtube_spam`: 82.5%

## holdout (youtube_spam): false-positive rate at t_high by ham domain

- `comment`: 2.17%
