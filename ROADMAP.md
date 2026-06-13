# PAY by square for WooCommerce — Roadmap

## Bug Fixes

| # | Issue | File | Severity |
|---|-------|------|----------|
| 1 | Textdomain path wrong — resolves to `wc-bacs-paybysquare/src` instead of `wc-bacs-paybysquare`, translations may not load | `class-plugin.php:172` | High |
| 2 | No null check on `$bacs->account_details` — could error if BACS has no accounts | `class-plugin.php:411` | Medium |
| 3 | PAY by square logo missing — spec requires the official logo next to every QR code | `class-plugin.php:384-390` | Medium |
| 4 | Invalid HTML ID — space in `id` attribute | `class-settings.php:146` | Low |
| 5 | Typo — `"generage"` should be `"generate"` | `class-plugin.php:464` | Low |
| 6 | QR images never cleaned up — `uploads/paybysquare/` grows forever | `class-plugin.php:486` | Low |

## High Impact Improvements

| # | Improvement | Why |
|---|------------|-----|
| 7 | Migrate to REST API v3 (`api.bysquare.com`) — JSON + API keys instead of XML + passwords. Has a simple GET endpoint too. | Legacy XML API will likely be deprecated. Cleaner integration, better DX. |
| 8 | Use `generateQRPrint` endpoint — returns branded images with the official PAY by square logo | Solves bug #3 and looks more professional. |
| 9 | Add PaymentDueDate — configurable offset from order date (e.g., +7 or +14 days) | Banking apps display it when scanning. Many businesses need this. |
| 10 | Credit monitoring in admin — use V2 endpoints or `GET /info` to show remaining credits on the settings page | Currently admins only find out when they hit E601. Proactive = fewer support tickets. |

## Medium Impact Improvements

| # | Improvement | Why |
|---|------------|-----|
| 11 | Credential validation on save — use `CheckLoginData` or `GET /info` to verify credentials immediately | Prevents confusion from typos. |
| 12 | Add ConstantSymbol / SpecificSymbol — settings fields for these payment identifiers | Part of the spec, some Slovak businesses require them. |
| 13 | Support both SK + CZ QR codes simultaneously — the API can return both in one call | Useful for shops serving both markets. |
| 14 | Image cache cleanup cron — delete QR PNGs older than X days | Prevents upload directory bloat over time. |

## Nice to Have

| # | Improvement | Why |
|---|------------|-----|
| 15 | `.distignore` + GitHub deploy action — auto-deploy to WordPress.org SVN on release | Eliminates manual SVN process. |
| 16 | Rename `assets/` to `.wordpress-org/` | Proper WordPress.org repo structure. |
| 17 | Block checkout explicit testing — readme claims support but no block-specific code exists | May work via legacy hooks, should be verified. |

## Recommended Phases

- **Phase 1:** Bug fixes #1-6
- **Phase 2:** Migrate to REST API v3 (#7), branded QR images (#8), credential validation (#11)
- **Phase 3:** PaymentDueDate (#9), credit monitoring (#10), ConstantSymbol/SpecificSymbol (#12)
- **Phase 4:** Repo structure (#15-16), deploy automation, block checkout verification (#17)
