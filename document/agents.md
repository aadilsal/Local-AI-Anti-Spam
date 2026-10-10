# Agents guide

Instructions for AI coding agents (Claude Code and similar) and human contributors working in this repository. Read this before changing code. The facts here are about *this* project. General coding advice is left out.

> **Status:** pre-code. The layout and commands below are the agreed target from [architecture.md](architecture.md) §2. When a command changes in reality, update this file in the same commit.

---

## 1. What this project is, in five lines

- A WordPress anti-spam plugin whose spam model runs **inside PHP on the customer's server**. There is no API key and no network call in the free plugin.
- The model is a hashed n-gram logistic regression trained in Python and exported to `plugin/model/model.bin`.
- A paid Pro add-on (a separate plugin) sends only *borderline* messages to our FastAPI + ONNX cloud model.
- The users are small-business owners. **A blocked real enquiry is the worst bug we can ship.**
- One developer, about 20 h/week, running cost ≤ $20/month. Prefer boring, small and cheap.

## 2. Repository map

| Path | What | Language | Ships to |
|---|---|---|---|
| `plugin/` | Free plugin | PHP 7.4+ | WordPress.org (SVN) |
| `plugin/src/Inference/` | Featurizer + model reader. **No WordPress calls allowed here** | PHP | Also mirrored to `inference-php/` |
| `pro/` | Pro add-on (Freemius SDK lives here only) | PHP 7.4+ | Freemius |
| `training/` | Dataset, featurizer, training, export, evaluation | Python 3.11 | Hugging Face, `model.bin` |
| `parity/` | Shared fixtures: input → expected score | JSON | CI only |
| `cloud-api/` | Classification service | Python (FastAPI) | One VPS (Docker) |
| `site/` | Static product site | HTML/MD | Static host |
| `document/` | PRD, architecture, design, security | Markdown | GitHub |

## 3. Commands

```bash
# PHP (from plugin/)
composer install                      # dev tools only; the plugin has zero runtime deps
composer test                         # PHPUnit: unit + parity
composer test:wp                      # WP integration tests (wp-env)
composer lint                         # PHPCS (WordPress-Extra) + PHPCompatibility 7.4+
composer bench                        # latency + memory benchmark (must stay < 20 ms / < 16 MB)
npx wp-env start                      # local WordPress with CF7 + WPForms installed
npx wp-env run cli wp plugin check local-ai-anti-spam   # Plugin Check (T8)

# Python (from training/)
uv sync                               # or: pip install -r requirements.lock
pytest                                # unit tests incl. featurizer + split-policy guards
python -m laas.train --config configs/local-v1.yaml
python -m laas.export --run runs/<id>  # writes plugin/model/model.bin + parity/fixtures.json
python -m laas.eval --run runs/<id>    # runs the gates, writes eval/report.md

# Cloud (from cloud-api/)
uv sync && pytest
docker compose up --build
```

Before you say a change is done, run the tests for every area you touched. For anything that touches the featurizer or the model, also run **parity** on both sides.

## 4. Hard rules

Breaking any of these is a bug, even if the tests pass. Each one points to where it is justified.

1. **No network calls from `plugin/`** unless the opt-in sharing setting is on (C1, security S11). No remote assets, no telemetry, no update pings, no Freemius. Pro features go in `pro/`.
2. **Fail open.** Every integration entry point wraps the pipeline in `try { … } catch (\Throwable $e)` and returns *not spam* on error. Never let an exception tell a form plugin "spam" (S8).
3. **Escape every piece of submitted content at output**: `esc_html`, `esc_attr`. Show message bodies as plain text. Never `wp_kses_post`, `wpautop` or `make_clickable` them (S1). This includes sender names, emails and subjects.
4. **Every state change:** POST + nonce + `current_user_can('laas_manage_quarantine')`. No `wp_ajax_nopriv_*` (S2, S3).
5. **All SQL through `$wpdb->prepare()`**; sort columns come from an allow-list (S5).
6. **PHP 7.4 syntax only.** No `match`, union types, named arguments, `readonly`, enums, constructor promotion or `str_contains` (use `strpos`). PHPCompatibility enforces this.
7. **Core extensions only.** No `mbstring`, `intl` or `curl` dependence. HTTP goes through `wp_remote_*` (and only in `pro/`).
8. **Featurizer changes are made in both languages in the same commit**, `featurizer_version` is bumped, and fixtures are regenerated. Never "fix" parity by loosening the tolerance (`5e-5`).
9. **Never load the weights into a PHP array.** Read with `unpack('g', $blob, $offset)` per active bucket (R1).
10. **Don't use `strtolower` or `mb_strtolower` in the featurizer.** Use the ASCII `strtr` map (locale safety, R2).
11. **Training data rules:** rows with `split_policy` `eval_only` or `test_only` never reach training. The loader raises an error, and you must not bypass it. Never commit the real hand-labelled set, or anything derived from it at row level (S27).
12. **Quarantine is never deleted on arrival.** Only the retention cron, a user action or uninstall delete rows.
13. **Cloud API never logs request bodies** (C4, S19). Don't add body logging "for debugging", even temporarily.
14. **Prefix everything** `laas_` / `LocalAIAntiSpam\`: options, hooks, tables, transients, functions, CSS classes.
15. **Every user-facing string is translatable** (`__()`, `esc_html__()`, `_n()`) with the plugin's text domain.

## 5. Conventions

**PHP**
- PSR-4 autoload under `LocalAIAntiSpam\` → `plugin/src/`. One class per file. WordPress Coding Standards for formatting.
- Code in `src/Inference/` must stay pure: no WordPress functions, no globals, no I/O except reading the model file path it is given. This lets it be mirrored to `inference-php/` and tested without WordPress.
- Integrations implement `Integration` (architecture §5). A new form plugin adds one adapter file plus tests, and touches nothing else.
- Hooks and the `laas_classify()` function are a **public API under semver**. Adding is fine; renaming or changing a signature needs a deprecation cycle (`_deprecated_hook`).
- No new Composer runtime dependencies in `plugin/`. Dev dependencies are fine.

**Python**
- Typed (`mypy --strict` on `laas/`), formatted with `ruff format`, linted with `ruff`.
- Configs are YAML in `training/configs/`. Every run writes `runs/<timestamp>-<sha>/` with config, metrics and the manifest hash.
- Seeds fixed. No notebook-only logic: notebooks may call `laas.*`, never define the pipeline.
- Load checkpoints with `safetensors` only.

**Commits and PRs**
- Conventional commits (`feat(cf7): …`, `fix(inference): …`, `model: local-2026.11.1`).
- A model release commit includes: `model.bin`, regenerated `parity/fixtures.json`, `eval/report.md`, a changelog entry in `readme.txt` with the measured FPR and interval (L6).

## 6. Testing expectations

| Change | Tests required |
|---|---|
| Featurizer or model reader | Unit tests both sides + parity suite + benchmark |
| New integration | Spam path, clean path, release path (mail actually sent, via `pre_wp_mail` capture), fail-open path, "Mark as spam" path |
| Admin screen | Rendering with the XSS corpus fixture; authorisation matrix (admin / editor / subscriber / anonymous); nonce failure |
| Settings or data | Activation, upgrade from the previous DB version, uninstall leaves nothing behind |
| Anything in `plugin/` | No-outbound-call test still passes |
| Cloud API | Auth, rate limit, timeout behaviour, the canary "no content in logs" test |
| Training pipeline | Split-policy guard, dedupe across buckets, group-split leakage test |

Test data uses fictional people and reserved domains (`example.com`, `example.org`). Never paste real form submissions into fixtures.

## 7. Task playbooks

**Add a form-plugin integration**
1. Read that plugin's source to find its *official* spam hook (T1). Write the hook name and version into the adapter's docblock.
2. Implement `Integration` in `plugin/src/Integrations/<Name>.php`, including `release()`.
3. Map fields (types first, names second); exclude password, card and hidden fields.
4. Add the adapter to the detection list and the activation notice.
5. Write the tests in §6 and add the plugin to `wp-env` config.
6. Add a product-site landing page stub in `site/` ("Stop spam in <Name>").

**Ship a new local model**
1. `laas.train` → `laas.export` → `laas.eval`. If any gate fails, stop; do not adjust the gates to pass.
2. Commit `model.bin`, fixtures and report together. Run `composer test && composer bench`.
3. Add a `readme.txt` changelog line: model version, FPR with 95% interval, recall.

**Change a threshold default**
Thresholds live in the `model.bin` header, not in PHP. Re-export the model; never hardcode a number in `Classifier/Thresholds.php`.

**Touch anything that renders submitted content**
Re-read security-analysis S1, use the escaping helpers, and run the XSS corpus test.

## 8. Things to ask the owner about rather than decide

- Anything that changes **what leaves the customer's server**, including new fields in the cloud payload or sharing.
- Moving a feature between free and Pro (directory rules and positioning).
- New runtime dependencies, new paid services, or anything that adds running cost.
- Loosening any evaluation gate or the parity tolerance.
- Public hook or function renames.
- The product name and slug (an open question; the `laas_` prefix is a placeholder).

## 9. Glossary

| Term | Meaning |
|---|---|
| **Held / quarantined** | Stored in `laas_quarantine` and not delivered |
| **Release** | Deliver a held message as the form plugin would have done (including the notification email) |
| **Borderline** | Score between `t_low` and `t_high`. Free: delivered with a flag. Pro: sent to the cloud |
| **FPR** | False-positive rate: real enquiries held ÷ all real enquiries, on the hand-labelled real test set |
| **Parity** | PHP and Python produce the same score (±5e-5) for the same input |
| **Fail open** | On any error, deliver the message |
| **Solicitation** | A sales pitch written by a real person (SEO, web design, lead lists): spam to the owner, but not a bot |
| **Gates** | The evaluation thresholds a model must pass to ship (PRD §9.4) |
