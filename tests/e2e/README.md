# Tier 2.5 — Fresh-install smoke

End-to-end harness that runs `tests/smoke.php` against a **truly fresh** WordPress + latest WooCommerce, provisioned by `@wordpress/env` in Docker. Plugin-agnostic — slug auto-detected from the parent directory name.

## Why this exists

The Tier-2 smoke on the shared test site runs against a real install with ~24 plugins, accumulated options, custom theme, months of cron history. Plenty of customer-side bugs never reproduce there because the existing state happens to mask them. Tier 2.5 boots WP+WC from zero so anything missing — a dependency declaration, an autoloader race against default fixtures, an option-key prefix bleed — surfaces immediately.

## Prereqs

- Docker daemon running (Docker Desktop on macOS)
- **Docker Desktop → Settings → General → file sharing implementation: gRPC FUSE** (NOT VirtioFS — VirtioFS has a mount-overlay bug with wp-env)
- Node 18+ (npx fetches `@wordpress/env` on demand)
- `tests/smoke.php` already exists for this plugin (Tier 2 is a prerequisite)

## Run locally

```bash
bash tests/e2e/fresh-install.sh
```

First run takes ~75-150s (pulls WP + WC images, builds container, downloads WC zip). Subsequent runs ~30s (cached containers, just verify state + smoke).

## What it does

1. `wp-env start` — provisions fresh WP, downloads latest WC zip, mounts plugin source, auto-activates both
2. Lists active plugins (for diagnostic visibility in CI logs)
3. Runs `wp eval-file tests/smoke.php` in the cli container
4. Tears down wp-env on exit (trap)

Exit code propagates: 0 if smoke passes, 1 if any assertion fails.

## Files

| File | Purpose |
|---|---|
| `.wp-env.json` (repo root) | Tells `@wordpress/env` what to install. WC first in the plugins array so it activates before plugins with `Requires Plugins: woocommerce`. |
| `tests/e2e/fresh-install.sh` | The harness. Plugin-agnostic — slug auto-detected. |

## CI

This plugin's GitHub Actions pipeline (`.github/workflows/pipeline.yml`) runs PHP syntax, coding standards, static analysis, and translation checks on every push and PR. The fresh-install e2e is **not** wired into that pipeline yet — it needs a Docker-capable job (manual or release-gated). For now, run it locally before tagging a release.

## Onboarding a new plugin

1. Copy `.wp-env.json` and `tests/e2e/` into the plugin's repo.
2. Confirm `tests/smoke.php` exists. If not, create it first.
3. Wire the harness into the plugin's CI as a Docker-capable job (manual or release-gated).
4. Locally: `bash tests/e2e/fresh-install.sh` should exit 0.

## When `Requires Plugins:` chains beyond just `woocommerce`

For plugins with extra dependencies (e.g. `wc-tb-cardpay-recurring` depends on `wc-tb-cardpay`), `.wp-env.json` needs an additional entry in the `plugins` array — either a URL to a built zip, a local path, or (rarely) a Git clone URL. Order matters: parent dependencies first.
