# PAY by square for WooCommerce — Developer Guide

## Project overview

Adds a payment QR code to WooCommerce's built-in **Direct bank transfer (BACS)** gateway. After checkout the customer sees a QR code on the thank-you page and receives the same code embedded in the on-hold order email; scanning it in a banking app pre-fills IBAN, amount, variable symbol and beneficiary name.

Two QR standards are supported: **PAY by square** (Slovak) and **QR platba** (Czech), plus an automatic mode that picks one from the order currency. The images themselves are rendered by the third-party service `app.bysquare.com` — the plugin never encodes an image locally.

Maintained by Webikon. Published on **wordpress.org**; the source lives on GitHub at `github.com/Webikon/wc-bacs-paybysquare` with CI on **GitHub Actions**. Default branch: `master`. Plugin slug + text domain: `wc-bacs-paybysquare`.

## Requirements

- **PHP**: 7.4+
- **WordPress**: 6.0+ (tested to 7.1)
- **WooCommerce**: 8.0+ (tested to 11.1)
- HPOS (`custom_order_tables`) and Cart & Checkout blocks (`cart_checkout_blocks`) compatibility are declared on `before_woocommerce_init` in the main file.
- `Requires Plugins: woocommerce` is declared in the plugin header, so WordPress 6.5+ refuses activation without WooCommerce (and deactivates the plugin when WooCommerce is removed).

### Runtime floor vs. dev-tooling floor

These are deliberately different numbers and both matter:

| Layer | PHP | Where it's pinned |
|---|---|---|
| Runtime (what customers install on) | 7.4+ | `Requires PHP` header, `readme.txt`, `composer.json` `require.php` (`^7.4 \|\| ^8.0`) |
| Dev tooling (composer, phpunit) | 8.1+ | `composer.json` `config.platform.php` = `8.1.0`; PHPUnit `^10.5` needs 8.1 |
| Static analysis target | 7.4 | `phpstan.neon` `phpVersion: 70400` |

`config.platform.php` makes a lockfile built on a newer local PHP resolve as if it were 8.1. `phpstan.neon` then analyses at `70400` so 8.x-only syntax or functions cannot slip into code that has to run on 7.4. As the comment in `phpstan.neon` notes, this guards the low end only — symbols *removed* in PHP 8 are not reported there; the unit-test job (which runs on PHP 8.x) is what exercises that side.

## Architecture

Namespace: `Webikon\Woocommerce_Plugin\WC_BACS_Paybysquare\`. No autoloader — three `require`s, wired by hand.

```
wc-bacs-paybysquare.php  — Plugin header, HPOS + blocks compatibility declaration,
                           requires src/class-logger.php + src/class-plugin.php,
                           then calls Plugin::run( __FILE__ ).
src/
  class-plugin.php       — Everything: hook registration, the QR pipeline, the
                           thank-you and email renders, the public getters.
  class-settings.php     — WC_Integration subclass. Required lazily from
                           Plugin::register_integration().
  class-logger.php       — PSR-3-shaped façade over wc_get_logger().
assets/blueprints/       — WordPress Playground Live Preview (blueprint.json +
                           guide-notice.php mu-plugin). Belongs in the SVN
                           assets/ directory, NOT in trunk.
jobs/                    — Shell entry points for lint / phpcs / phpstan / phpunit / i18n.
phpstan-rules/           — Custom PHPStan rule (CatchMustLogRule).
tests/                   — Unit suite, smoke test, e2e harness.
languages/               — .pot + Slovak .po/.mo/.l10n.php.
```

### Boot sequence

`Plugin::run( $file )` is called at file scope. It resolves the singleton (`Plugin::get_instance()`), then registers everything up front — there is no conditional bootstrap:

| Hook | Type | Callback | Priority / args |
|---|---|---|---|
| `plugins_loaded` | action | `preinit()` | 10 |
| `init` | action | `initialize()` | 10 |
| `plugin_action_links_{basename}` | filter | `add_settings_link()` | 10 |
| `network_admin_plugin_action_links_{basename}` | filter | `add_settings_link()` | 10 |
| `after_plugin_row_{basename}` | action | `plugin_row_notice()` | 10 / 2 |
| `woocommerce_settings_api_form_fields_bacs` | filter | `filter_form_fields()` | 1000 |
| `woocommerce_settings_checkout` | action | `add_settings_note()` | 1000 / 0 |
| `woocommerce_thankyou_bacs` | action | `thankyou_page_qrcode()` | 10 |
| `woocommerce_email_order_meta` | action | `onhold_email_qrcode_info()` | -1000 / 3 |
| `woocommerce_gateway_title` | filter | `filter_gateway_title()` | 1000 / 2 |

`preinit()` adds `register_integration()` to `woocommerce_integrations`. `initialize()` only calls `load_plugin_textdomain()`.

### `Logger`

`src/class-logger.php` mirrors `Psr\Log\AbstractLogger` (the abstract class is not used, to avoid the dependency). Every level method funnels into `log()`, which sets `$context['source'] = 'wc-bacs-paybysquare'` and forwards to `wc_get_logger()`. So all plugin output lands in **WooCommerce → Status → Logs** under the `wc-bacs-paybysquare` source. Nothing in the plugin echoes an error to the customer — failures are silent on the page and loud in the log.

### `Settings` and the WC_Integration relationship

`Settings extends \WC_Integration` with `id = Plugin::INTEGRATION_ID` (`'paybysquare'`), so its screen renders at **WooCommerce → Settings → Integration → PAY by square**. Fields: `beneficiary`, `username`, `password`, `information`, `display` (`slovak` / `czech` / `auto`, default `auto`).

`Plugin::get_pbsq()` resolves the live instance through `WC()->integrations->get_integration( 'paybysquare' )` and caches it (`false` when absent, with an error logged). `Plugin::get_option()` reads through it, so the whole pipeline goes through one accessor — which is also the seam the unit-test probe overrides.

Two settings behaviours worth knowing:

- **Migration from the old location.** `Settings::init_settings()` calls the parent, then — if the integration's own option row is not an array yet — reads `woocommerce_bacs_settings`, lifts `paybysquare_{key}` entries (falling back to each field's default) and writes them to the integration option.
- **Back-compat fields on the BACS screen.** `Plugin::filter_form_fields()` re-injects the same fields into the BACS gateway settings form, but *only* while the integration's own option row does not exist.

## QR code pipeline

All of it is `Plugin::fetch_qrcode_png_info( \WC_Order $order )`, which returns `[ $path, $url, $hash ]` or `[]`.

1. **Gateway + standard.** Bail if `get_bacs()` is false. Resolve `$slovak` / `$czech` from the `display` setting (`auto` maps EUR → Slovak, CZK → Czech). If neither, return `[]` — no API credit is spent.
2. **Bank accounts.** Iterate `$bacs->account_details`. Skip any row that is not an array or lacks both `iban` and `bic`. Normalise each through `Plugin::sanitize()` (uppercase, strip everything but `0-9A-Z` — so spaces in a stored IBAN are tolerated), then validate: IBAN against `/^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}$/`, BIC against `/^[A-Z]{4}[A-Z]{2}[A-Z0-9]{2}([A-Z0-9]{3})?$/` (8 or 11 chars). No valid account → warning logged, `[]` returned.
3. **Account ordering.** In `auto` mode with more than one account, accounts whose IBAN starts with `SK` (EUR) or `CZ` (CZK) are moved to the front. Banking apps offer the first entry, so order is user-visible.
4. **Beneficiary.** Uppercased. For the Czech standard, a beneficiary matching `Settings::QRPLATBA_INVALID` (`;[^0-9A-Za-z $%+./:-];`) aborts with a logged error.
5. **Payload.** `total`, `currency`, `variable_symbol`, `payment_note` (`'PAY by square ' . order number`), `beneficiary_name`, `bank_accounts`.
6. **Filters.** `pay_by_square_qr_variable_symbol`, then `pay_by_square_qrdata` (see below), then re-normalisation: every scalar field through `Plugin::scalar_to_string()` and every account row rebuilt from its `iban`/`bic` keys, so a filter cannot put an array or object into the XML.
7. **Cache key.** `sha1( wp_json_encode( $qrdata + [ 'display' => $display ] ) )`. Cached at `{uploads basedir}/paybysquare/{hash}.png`, served from `{uploads baseurl}/paybysquare/{hash}.png`.
8. **Cache re-validation.** If the file exists, the first 4 bytes are read and compared against `"\x89PNG"`. A truncated or non-PNG cache entry is *not* served — it logs a warning and falls through to regeneration. (A file written by a version before the magic-byte guard is exactly this case.)
9. **Request.** `wp_remote_post()` to `https://app.bysquare.com/api/generateQR` with `content-type: text/xml` and a hand-built `BySquareXmlDocuments` body. Credentials (`username`, `password`) and every payload value go in through `esc_html()`. `CountryOptions` carries `<Slovak>` / `<Czech>` booleans.
10. **Response parsing.** `libxml_use_internal_errors( true )` is set around `simplexml_load_string()`, errors are collected and cleared, and the previous setting is restored — so a malformed body never prints parser warnings onto the thank-you page or into an email. On a parse failure the first libxml message is folded into the log line.
11. **Status handling.**

| HTTP | Behaviour |
|---|---|
| 200 | Read `PayBySquare` (Slovak) or `QrPlatbaCz` (Czech) node; missing node → logged error, `[]`. `base64_decode()`, then **assert `substr($raw, 0, 4) === "\x89PNG"`** before writing — a 200 carrying a non-image body is refused rather than persisted as a `.png`. Write with `LOCK_EX`; a failed write logs `error_get_last()`. |
| 400 + `ErrorCode` `E601` | Monthly quota exhausted. Sets the option `woocommerce_bacs_paybysquare_limit_exceeded` to `gmdate('Ym')`, which the settings screen reads back to show the "limit depleted" warning. |
| 400 (other) | Logs code, `Message` and `Detail`. |
| 401 | Logs "Username and Password pair does not exists or is disabled." |
| other | Logs the code. |

On success the limit option is deleted and `[ $path, $url, $hash ]` is returned.

## Where the QR code renders

### Thank-you page

`woocommerce_thankyou_bacs` → `thankyou_page_qrcode( $order_id )`. **There is no order-status gate here, on purpose.** It mirrors `WC_Gateway_BACS::thankyou_page()`, which prints the bank details for any status. The payment-method gate is the hook name itself — WooCommerce only fires `woocommerce_thankyou_{method}` for the order's own gateway.

That hook fires from two places: the classic `checkout/thankyou.php` template, and the block Order Confirmation template's **"Additional information"** block. A site that customises the block template and removes that block loses the thank-you QR code while emails keep working — check this first when a shop reports a missing QR code, and note that `tests/smoke.php` asserts against it.

### Emails

`woocommerce_email_order_meta` at priority **-1000** with 3 args → `onhold_email_qrcode_info( $order, $sent_to_admin, $plain_text )`. The callback runs for every order email but renders only when **all** of these hold:

- not the admin copy (`! $sent_to_admin`),
- not the plain-text variant (`! $plain_text`),
- `'bacs' === $order->get_payment_method()`,
- `'on-hold' === $order->get_status()`.

So in practice: the customer-facing HTML email for a BACS order that is currently on-hold. This mirrors `WC_Gateway_BACS::email_instructions()`, which is also on-hold-gated — and is why the thank-you path and the email path deliberately differ.

**Embedding.** The image cannot be a URL in email, so:

1. `onhold_email_qrcode_info()` stores `$this->order`, adds `onhold_email_attachments()` to `phpmailer_init`, and emits `<img src="cid:{hash}">`.
2. `onhold_email_attachments( $phpmailer )` **removes itself from `phpmailer_init` and nulls `$this->order` first**, then re-checks the order and calls `$phpmailer->addEmbeddedImage( $path, $hash )`. A failed embed logs a warning carrying `$phpmailer->ErrorInfo`.

That one-shot disarm is load-bearing: without it a later `wp_mail()` in the same request (another order's email, an admin copy) would inherit the previous order's image.

Both paths render through `Plugin::output_qr_code_image( $src )` — a `<div>` with a short instruction paragraph and an `<img>` at `width: 16em`, with `$src` run through `esc_attr()`.

## Public API for integrators

Shops that want the QR code somewhere else — a custom thank-you page, a PDF invoice, the account order view — use these. All are public, and `tests/smoke.php` asserts their visibility as part of the contract.

```php
if ( class_exists( '\Webikon\Woocommerce_Plugin\WC_BACS_Paybysquare\Plugin' ) ) {
    $plugin = \Webikon\Woocommerce_Plugin\WC_BACS_Paybysquare\Plugin::get_instance();
    $url    = $plugin->get_qrcode_url( $order );   // image URL, '' on failure
    $path   = $plugin->get_qrcode_path( $order );  // absolute file path, '' on failure
    $plugin->thankyou_page_qrcode( $order );       // echoes the default markup
}
```

| Method | Since | Accepts | Returns |
|---|---|---|---|
| `Plugin::get_instance()` | — | — | The singleton |
| `get_qrcode_url( $order )` | 3.2.0 | `WC_Order` or order ID | Image URL, or `''` |
| `get_qrcode_path( $order )` | 3.2.0 | `WC_Order` or order ID | Absolute path, or `''` |
| `thankyou_page_qrcode( $order )` | — | `WC_Order` or order ID | void (echoes markup) |

**None of these gate on payment method or order status** — the caller decides where a bank-transfer QR code belongs. They share the on-disk cache with the default render, so repeated calls for one order spend one `app.bysquare.com` generation; a new order (or any changed payload) spends another.

### Filters

| Hook | Type | Fired in | Arguments |
|---|---|---|---|
| `pay_by_square_qr_variable_symbol` | filter | `Plugin::fetch_qrcode_png_info()` | `( string $variable_symbol, \WC_Order $order )` |
| `pay_by_square_qrdata` | filter | `Plugin::fetch_qrcode_png_info()` | `( array $qrdata, \WC_Order $order )` |

`pay_by_square_qr_variable_symbol` runs first, and its result is visible in `$qrdata['variable_symbol']` when the wide filter runs.

**Default variable symbol.** `preg_replace( '/[^0-9]+/', '', $order->get_order_number() )` then `substr( …, 0, 10 )` — non-digits stripped, then the **first 10 digits** kept. `WI-2026/00123` → `202600123`; `123456789012345` → `1234567890`; `ABC` → `''` (pinned in `tests/unit/VariableSymbolTest.php`).

**`pay_by_square_qrdata` contract.** It covers every field (`total`, `currency`, `variable_symbol`, `payment_note`, `beneficiary_name`, `bank_accounts`). Two documented limits:

- `currency` only changes the currency code sent with the amount. Which *standard* is generated (PAY by square vs. QR platba) is decided from the order currency and the `display` setting **before** this filter runs.
- A **non-array return yields an empty payload** rather than a fatal or a PHP notice in the XML — every field falls back to `''` and the account list to `[]`, and the API rejects the request. Pinned by `QrDataFilterTest::test_filter_returning_a_non_array_does_not_break_the_request()`.

A filtered payload gets its own cache entry, because the filtered array is what feeds the `sha1()`.

## Tests

### `tests/unit/` — PHPUnit 10.5, no WordPress install

```bash
composer test          # or: jobs/unit-tests.sh
```

110 test methods across 13 classes (data providers expand these at runtime). Config in `phpunit.xml.dist`: bootstrap `tests/bootstrap.php`, suite `unit`, `failOnWarning` and `failOnNotice` both on — so a stray notice from a `mkdir()` reddens the suite.

| File | Role |
|---|---|
| `tests/bootstrap.php` | Defines `ABSPATH` **before** requiring the autoloader (every `src/` file opens with `defined('ABSPATH') \|\| exit;`), loads `FakeWp.php`, then declares minimal `WC_Integration`, `WC_Gateway_BACS` and `WC_Order` stubs and requires the three `src/` files plus the probe and base test case. |
| `tests/FakeWp.php` | The fake WordPress: hook registry that mirrors `WP_Hook` semantics (including `accepted_args` slicing), in-memory options, a recording `wc_get_logger()`, a `wp_remote_post()` that captures requests and replays a queued response, and a configurable `wp_upload_dir()`. State lives in `$GLOBALS['fake_wp']`; `fake_wp_reset()` clears it. Anything the code under test does not call is intentionally left undefined so accidental coupling fails loudly. |
| `tests/PluginProbe.php` | `extends Plugin`. Widens the protected constructor, serves settings and the BACS gateway from in-memory fixtures, and exposes `fetch()` (the pipeline), `render()` (captured markup) and the two static sanitizers. Changes no production behaviour. |
| `tests/QrTestCase.php` | Shared fixture: a per-process, high-entropy temp uploads directory (two concurrent phpunit runs must never share a PNG cache), a probe wired with settings that would produce a valid Slovak QR code, valid SK/CZ IBAN+BIC constants, `respond_with_png()`, and helpers that read values back out of the last captured request XML. |

| Test class | Covers |
|---|---|
| `ApiErrorHandlingTest` | The documented `app.bysquare.com` error responses, and the monthly-limit option the settings screen reads back. |
| `BankAccountValidationTest` | IBAN/BIC validation, incomplete rows being skipped without hiding a complete one, and the auto-mode currency preference for account order. |
| `DisplaySelectionTest` | Which standard is requested, when the pipeline bails before spending a credit, beneficiary uppercasing and the Czech character rule, and XML escaping of settings-supplied text. |
| `GatewayTitleTest` | The checkout hint appended to the BACS gateway title, and every case where it must be left alone. |
| `HookRegistrationTest` | The integration surface: hook names, priorities, `accepted_args`, and which methods must stay public. |
| `PngGuardTest` | Response handling: the magic-byte guard, non-XML bodies, libxml state restoration, transport errors, and the on-disk cache (hit, corrupt entry, zero-byte entry, and each field that must miss). |
| `PublicApiTest` | `get_qrcode_url()` / `get_qrcode_path()` — order object or ID, shared cache with the default render, soft failure, and the absence of a payment-method/status gate. |
| `QrDataFilterTest` | `pay_by_square_qrdata`: replacing every field, adding/dropping accounts, escaping, the non-scalar collapse, and the non-array fallback. |
| `RenderTest` | What reaches the customer: thank-you render (including that it ignores order status), email gating, the PHPMailer embed, the one-shot disarm, and markup escaping. |
| `ScalarCoercionTest` | The two static sanitizers — `scalar_to_string()` and `sanitize()`. |
| `SettingsFieldsTest` | The declarative half of `Settings`: every setting the pipeline reads is a declared field, defaults, and the beneficiary sanitizer. |
| `SettingsNoticeTest` | The "settings were moved" note, pinned in all four dimensions so it stays scoped to the BACS section. |
| `VariableSymbolTest` | Variable-symbol derivation and the `pay_by_square_qr_variable_symbol` filter. |

Keep `FakeWp.php` small. Anything needing more than a few stubs belongs in the smoke test against a real install — a unit suite that reimplements WordPress passes while production breaks.

### `tests/smoke.php` — pre-tag gate

```bash
wp eval-file tests/smoke.php                     # full gate, run before tagging
wp eval-file tests/smoke.php skip-backlog-gate   # used by the e2e harness
```

Runs against a live WP+WC install. 23 `check()` call sites; on a classic-theme install with WooCommerce active that is **24 assertions** (the public-method and filter-name loops expand), plus one more for the `BACKLOG.md` gate when that file is present next to `tests/`. Groups: classes load and `Settings` extends `WC_Integration`; runtime prerequisites (SimpleXML, `wp_remote_post()`, outbound HTTP to `app.bysquare.com` not blocked by `WP_HTTP_BLOCK_EXTERNAL`, a writable `uploads/paybysquare`); the BACS gateway and the integration are registered; HPOS and Cart & Checkout blocks declarations; the `BACKLOG.md` gate; the two hooks with their exact priorities; the five public API methods; the two filter names as literal source strings; and the thank-you render path.

That last group branches. On a block theme it resolves the `order-confirmation` block template and asserts it still carries `woocommerce/order-confirmation-additional-information` (via WooCommerce's own `has_block_including_patterns()` so a block moved into a synced pattern still counts), checking both WooCommerce's default and any Site Editor customisation. On a classic theme it resolves `checkout/thankyou.php` through `wc_locate_template()`; if the site overrides it, the override must still contain a `do_action( 'woocommerce_thankyou_' . …)`. Both branches end in a `check()`, so a pristine site earns a tick rather than a silent skip.

Two conventions:

- **`$GLOBALS['failures']`, not `global $failures`.** `wp eval-file` wraps the script in an anonymous function, so `global` does not behave as expected.
- **The `BACKLOG.md` gate is release-readiness, not installability.** It fails while the file has any open `- [ ]` item. `tests/e2e/fresh-install.sh` passes `skip-backlog-gate` so an open backlog does not fail that job. Run the smoke test with **no** arguments before tagging.

### `tests/e2e/fresh-install.sh` — Tier 2.5

Boots clean WP + latest WooCommerce + this plugin via `@wordpress/env` in Docker (`.wp-env.json`, WooCommerce listed first so it activates before a plugin with `Requires Plugins: woocommerce`), then runs `tests/smoke.php` in the cli container. Plugin-agnostic — the slug is auto-detected from the directory name. Tears wp-env down via an `EXIT` trap.

Two self-healing steps worth knowing: it installs `php-soap` into both containers (aligning them with typical customer hosts), and it detects the wp-env extraction quirk where `woocommerce.latest-stable.zip` lands with its wrapper directory intact, flattens it and re-runs `wp-env start`. It will also run `tests/e2e/scenarios/*.php` as Tier 3 if that directory ever exists — it currently does not.

macOS prerequisite: Docker Desktop file sharing must be **gRPC FUSE**, not VirtioFS. Details in `tests/e2e/README.md`. This harness is **not** wired into CI — run it locally before tagging.

## Build / dev commands

```bash
composer install                    # dev deps (phpcs, wpcs, phpstan, stubs, wp-cli i18n, phpunit)
jobs/php-syntax.sh                  # php -l over every *.php in the plugin root + src/
jobs/unit-tests.sh                  # vendor/bin/phpunit --colors=never
composer test                       # same suite, plain `phpunit`
jobs/code-standards.sh              # phpcs -s   (jobs/code-standards.sh --fix runs phpcbf)
jobs/static-analysis.sh             # phpstan analyze --memory-limit=1G
jobs/update-translations.sh         # regenerate languages/*.pot and update the .po
wp eval-file tests/smoke.php        # pre-tag gate against a live WP+WC install
bash tests/e2e/fresh-install.sh     # Tier 2.5 fresh-install validation
```

The `jobs/` scripts share two helpers: `jobs/.files.sh` prints the analysed paths (`*.php src`), and `jobs/.runner.sh` finds every `*.php` under them and pipes it into whatever command it is given. So the analysed surface is defined in exactly one place — `tests/`, `jobs/` and `vendor/` are outside it.

`.phpcs.xml` uses the full `WordPress` ruleset with one override: short array syntax is allowed (`Universal.Arrays.DisallowShortArraySyntax.Found` excluded, `Generic.Arrays.DisallowLongArraySyntax` enabled instead).

`phpstan.neon` runs at **level max** with WordPress + WooCommerce stubs as bootstrap files, no baseline, and registers one custom rule: `phpstan-rules/CatchMustLogRule.php`, which flags any `catch` block that neither rethrows nor logs.

## CI

`.github/workflows/pipeline.yml` — one `test` job on `ubuntu-latest`, triggered on pushes to `master` and on every pull request. Steps in order:

1. **Repo Checkout** — `actions/checkout@v4`
2. **PHP Syntax** — `jobs/php-syntax.sh` (before composer, so a parse error fails fast)
3. **Composer Install** — `php-actions/composer@v6`, `php_version: 8.4`, `args: --no-scripts`
4. **Unit Tests** — `jobs/unit-tests.sh`
5. **Code Standards** — `jobs/code-standards.sh`
6. **Static Analysis** — `jobs/static-analysis.sh`
7. **Translation Updates** — `jobs/update-translations.sh && git diff --exit-code`

**Step 7 fails whenever the POT/PO are stale.** The POT records source line references, so *any* edit that shifts lines in `src/` or the main file makes the committed POT diverge and reddens the pipeline. Regenerate and commit `languages/` as part of the same change.

**There is no zip-building job.** wordpress.org builds the customer zip from SVN, so unlike the update-server plugins in the Webikon portfolio there is nothing to package or upload here. The Tier 2.5 e2e harness is also not wired in — it needs a Docker-capable job.

## Release flow

Distribution is **wordpress.org SVN, manual**. There is no update-server flip.

1. Bump `Version:` in `wc-bacs-paybysquare.php` and `Stable tag:` in `readme.txt` — they must match.
2. Update `Tested up to` / `WC tested up to` in both the plugin header and `readme.txt` if they moved.
3. Rename the `= Unreleased =` block in `readme.txt` to `= X.Y.Z =`. Customer-facing changelog text is Slovak. Older entries live in `changelog.txt`.
4. Clear every open `- [ ]` in `BACKLOG.md` (the smoke gate fails otherwise).
5. Regenerate translations (see below) and commit `languages/`.
6. `wp eval-file tests/smoke.php` with **no** arguments against a live WP+WC install.
7. Commit, push to `master`, tag `X.Y.Z`.
8. Copy into the wordpress.org SVN checkout's `trunk/`: the main file `wc-bacs-paybysquare.php`, `src/`, `readme.txt`, `changelog.txt`, `languages/`. `svn add` / `svn rm` as needed, commit, then `svn cp trunk tags/X.Y.Z` and commit that.
9. **`assets/` does not go into trunk.** `assets/blueprints/` (the Playground Live Preview blueprint plus its guide mu-plugin) belongs in the SVN **`assets/`** directory — the blueprint fetches its mu-plugin from `ps.w.org/wc-bacs-paybysquare/assets/blueprints/…`, which only resolves from there. Screenshots go to the same place.

Nothing else belongs in trunk: not `tests/`, `jobs/`, `phpstan-rules/`, `vendor/`, `composer.json`/`composer.lock`, `phpunit.xml.dist`, `phpstan.neon`, `.phpcs.xml`, `.wp-env.json`, `.github/`, `BACKLOG.md`, `ROADMAP.md` or `DEVELOPER.md`. `.distignore` encodes this list — it is the checklist for the manual copy and is also what `wp dist-archive` reads. The GitHub deploy action that would automate the SVN push is still open as ROADMAP item 15, so until then the trunk copy is a manual, reviewed step.

**Every SVN commit notifies all listed contributors** (`readme.txt` `Contributors:`). Sanity-check the build in WordPress Playground before committing.

## Translations

```bash
jobs/update-translations.sh                 # POT + PO
vendor/bin/wp i18n make-mo languages/       # .mo — required, this is what WP loads
vendor/bin/wp i18n make-php languages/      # .l10n.php — the modern fast path
```

`jobs/update-translations.sh` derives the slug from the main file name, runs `wp i18n make-pot` restricted to the `jobs/.files.sh` paths (excluding `vendor`) with a pinned `POT-Creation-Date` header and a fixed file comment, then `wp i18n update-po` to merge into the Slovak `.po`. It does **not** compile — `make-mo` and `make-php` are separate steps, and both artefacts have to be committed so they ship in the SVN trunk.

**Gotcha:** `wp-cli/i18n-command` is pinned to `~2.6.6` in `composer.json`. Version 2.7.0 rewrites the PO revision date on every run, which makes the CI freshness check (`jobs/update-translations.sh && git diff --exit-code`) fail on a tree that is actually up to date. Do not relax that constraint without re-testing step 7 of the pipeline.

## Quirks / gotchas

- **Credentials are not part of the cache key.** The `sha1()` covers the payload plus the `display` setting only. Changing the `app.bysquare.com` username or password does not invalidate cached PNGs — which is usually what you want (no wasted credits), but means a credentials fix will not visibly "re-fix" an order whose image is already cached. Delete the file to force regeneration.
- **The PNG cache lives in `uploads/paybysquare/` and is publicly readable**, keyed by a hash of the payload. It is never cleaned up — the directory grows for the life of the site (ROADMAP items 6 and 14).
- **`get_instance()` uses `new self()`, not `new static()`.** A subclass cannot become the singleton; `tests/PluginProbe.php` therefore instantiates itself directly rather than going through the accessor.
- **The "settings were moved" note is gated on four conditions** — `$current_screen->id`, `$current_tab`, `$current_section` and the screen existing at all. Before this gate it fired on every gateway section under WooCommerce → Settings → Payments. `SettingsNoticeTest` pins all four; do not loosen one "because the others are enough".
- **BACS account rows are treated as untrusted plain array data.** `account_details` comes from an option, so a row that is not an array or is missing `iban`/`bic` is skipped rather than assumed well-formed — and one bad row must not hide a good one (pinned in `BankAccountValidationTest`).
- **IBAN/BIC validation is structural, not checksum-based.** `sanitize()` uppercases and strips non-alphanumerics (so stored spaces are fine), then the two regexes check shape only. A typo that keeps the shape still reaches the API.
- **Everything a filter returns is coerced.** `scalar_to_string()` collapses non-scalars to `''`, and account rows are rebuilt key by key. The generated XML can therefore never receive an array or object, however hostile the callback.
- **XML values go through `esc_html()`**, which is HTML escaping applied to an XML document. It is what the escaping tests assert against; if you change it, update `DisplaySelectionTest` and `QrDataFilterTest` alongside.
- **The `phpmailer_init` handler must disarm itself first.** `onhold_email_attachments()` calls `remove_action()` and nulls `$this->order` before doing any work. Reordering that would leak one order's QR image into the next `wp_mail()` of the same request.
- **`ROADMAP.md` line references are from an older revision of the code** — treat the file names and descriptions as current, the line numbers as approximate.

## Cross-plugin context

Sibling plugin **`wc-dpd`** ships through the same wordpress.org SVN channel, though its source lives on GitLab with the rest of the Webikon portfolio; this plugin is the only one hosted on GitHub. Every other Webikon WooCommerce plugin distributes through a private update server with GitLab CI building the zip — so release tooling copied from those repos will not apply here: there is no archive job, no staging upload and no version flip, and the tag is only half the release. The manual SVN copy in step 8 above is what customers actually receive.
