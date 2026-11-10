# Release checklist (free plugin)

How the free plugin goes from this repository to sites. Written 10 Nov 2026, for version 0.1.0.

## 1. Build

From `plugin/`:

```powershell
php C:/Users/butta/tools/composer.phar install   # dev tools, once
php vendor/bin/phpcs; php vendor/bin/phpunit     # must be clean
php bin/build-zip.php                            # dist/local-ai-anti-spam.zip
```

The build refuses to run if the plugin header, `LAAS_VERSION` and readme's *Stable tag* disagree, if `model/model.dat` is missing, or if any PHP file starts with a byte-order mark. The zip holds only what ships (see `.distignore`): no tests, vendor or dev config. CI builds it on every push.

The model ships as `model/model.dat` (the `model.bin` format): WordPress.org's Plugin Check rejects `.bin` files as "application files".

## 2. Checks before any release

| Check | Where | Status for 0.1.0 |
|---|---|---|
| PHPUnit, PHPCS (WordPress-Extra, PHP 7.4 syntax) | CI, PHP 7.4 / 8.1 / 8.3 | Pass |
| Latency and memory budget (20 ms, 16 MB) | CI | Pass (8 KB message p95 3.5 ms, 8.1 MB on the owner's PC) |
| End to end in WordPress: comments, CF7, WPForms, rules, stats, admin (escaping, nonces, capabilities) | CI job "WordPress end to end" | Pass locally |
| Plugin Check (WordPress.org) | CI | No errors. Warnings: table names from `$wpdb->prefix` in SQL (needs `%i`, WordPress 6.2+; we support 6.0) |
| **PRD §9.4 gates on the real, hand-labelled test set** | `training/` | **Not measured: the real test set doesn't exist yet** |

## 3. Release paths

**Beta (now):** give `dist/local-ai-anti-spam.zip` to beta sites, which install it with *Plugins → Add New → Upload Plugin*. Nothing in the plugin depends on WordPress.org. Ask beta sites to check the quarantine weekly and click *Not spam* on anything real; those corrections plus a sample of delivered messages (with consent) become the real test set.

**WordPress.org (public listing):** only after the PRD gates pass on real messages.

1. Owner confirms the name and slug (`document/decisions/D-1-name.md`): run the USPTO/WIPO searches; `local-ai-anti-spam` was free on WordPress.org on 10 Oct 2026.
2. Owner confirms *Contributors* in `readme.txt` is their WordPress.org username (currently `aadilsal`).
3. Submit the zip at <https://wordpress.org/plugins/developers/add/> from the owner's WordPress.org account. Review is manual and takes days to weeks; reply to the reviewer's email from that account.
4. On approval, WordPress.org gives an SVN repository. Commit the plugin to `trunk/`, copy it to `tags/0.1.0/`, and put banners, icon and screenshots (design.md §6) in `assets/`.
5. Every later release: bump the version in all three places, add a changelog line with the measured false-positive rate and its interval (PRD L6), build, commit to SVN `trunk/` and a new tag.

## 4. Known limits in 0.1.0

- English only; other languages are delivered unchecked.
- CF7 shows its generic error to a held visitor; WPForms shows its normal confirmation.
- Released CF7 messages can't include uploaded files (CF7 deletes them).
- No Gravity Forms, Elementor or Fluent Forms yet (PRD weeks 10–13).
- Pro add-on, cloud API and product site are not built.
