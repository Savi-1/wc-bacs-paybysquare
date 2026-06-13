#!/usr/bin/env bash
#
# Tier 2.5 — Fresh-install smoke harness.
#
# Boots a clean WordPress + latest WooCommerce + this plugin in Docker via
# @wordpress/env, then runs the existing tests/smoke.php inside the container.
#
# Plugin-agnostic: the slug is auto-detected from the plugin's directory name.
# No per-plugin edits needed.
#
# Why this exists: Tier-2 smoke runs on the shared test site with ~24 plugins + months of
# accumulated state. Plenty of customer-side bugs never reproduce there. Tier
# 2.5 boots WP+WC from zero so missing-dependency / autoloader-race /
# option-key-bleed bugs surface immediately.
#
# Prereqs:
#   - Docker daemon running (Docker Desktop on macOS)
#   - Docker Desktop file-sharing implementation: gRPC FUSE (NOT VirtioFS)
#     — VirtioFS has a mount-overlay bug with wp-env
#   - Node 18+ (npx will fetch @wordpress/env on demand)
#
# Exit code: 0 if smoke passes on fresh install, 1 otherwise.

set -euo pipefail

# Resolve project root (this script lives at tests/e2e/).
cd "$(dirname "$0")/../.."
PLUGIN_SLUG="$(basename "$(pwd)")"

cleanup() {
  echo "▶ Tearing down wp-env..."
  npx --yes @wordpress/env destroy --scripts=false >/dev/null 2>&1 || true
}
trap cleanup EXIT

echo "▶ Booting wp-env (fresh WP + latest WC + ${PLUGIN_SLUG})..."
# wp-env auto-activates plugins in .wp-env.json order — WC must be listed
# FIRST in that array so the Requires-Plugins: woocommerce check passes when
# ${PLUGIN_SLUG} is then activated.
#
# `|| true`: wp-env returns non-zero if any plugin failed to auto-activate
# (notably when the WC zip extracted with the nested-wrapper bug — see the
# flatten_wc_if_nested step below). The WordPress container is still up and
# wp-cli still works, so we can recover. We rely on the smoke step's exit
# code as the final verdict, not wp-env's start exit code.
npx --yes @wordpress/env start || true

# Install php-soap in both containers so SOAP-using plugins (ComfortPay,
# anything else doing executeRecurrence/getCardExpiration) can boot cleanly.
# The official wordpress + wordpress:cli images don't include soap by default,
# while typical customer hosts do — this aligns the test env with reality.
# docker-php-ext-enable's path is broken in both images (writes to /conf.d
# instead of $PHP_INI_DIR/conf.d), so we compile-then-enable manually.
install_soap_in() {
  local service="$1"
  local pm_install="$2"  # "apt-get install -y" or "apk add --no-cache"
  local update_cmd="$3"   # "apt-get update -qq" or ":"
  local reload="$4"       # "service apache2 reload" or ":"
  npx --yes @wordpress/env run "$service" -- sh -c "
    php -m | grep -q '^soap\$' && exit 0
    sudo $update_cmd >/dev/null 2>&1 || true
    sudo $pm_install libxml2-dev >/dev/null 2>&1
    sudo docker-php-ext-install soap >/dev/null 2>&1 || true
    sudo mkdir -p /usr/local/etc/php/conf.d
    echo 'extension=soap' | sudo tee /usr/local/etc/php/conf.d/docker-php-ext-soap.ini >/dev/null
    sudo $reload >/dev/null 2>&1 || true
    php -m | grep -q '^soap\$'
  " >/dev/null 2>&1 && echo "✓ $service: soap available" || echo "⚠ $service: soap install failed (continuing)"
}
echo "▶ Ensuring php-soap in both containers..."
install_soap_in wordpress "apt-get install -y --no-install-recommends" "apt-get update -qq" "service apache2 reload"
install_soap_in cli       "apk add --no-cache"                          ":"                  ":"

# wp-env's WC download occasionally extracts woocommerce.latest-stable.zip with
# the inner wrapper directory intact (.../woocommerce.latest-stable/woocommerce/*
# instead of .../woocommerce.latest-stable/*). On affected runs, WC fails to
# auto-activate ("could not be found"), which cascades to skipping our plugin
# too. Detect + flatten + re-activate. Discovered 2026-05-15 during portfolio
# validation — ~half of 12 back-to-back runs hit it; pattern unclear (CDN cache?
# wp-env unzip helper state?). Self-heal in the harness is the workaround.
flatten_wc_if_nested() {
  local state_dir=""
  local d
  for d in "$HOME"/.wp-env/*/; do
    if [ -f "$d/docker-compose.yml" ] && grep -q "/${PLUGIN_SLUG}:/" "$d/docker-compose.yml" 2>/dev/null; then
      state_dir="${d%/}"
      break
    fi
  done

  if [ -z "$state_dir" ]; then
    return
  fi

  local stage="$state_dir/woocommerce.latest-stable"
  if [ -d "$stage/woocommerce" ] && [ ! -f "$stage/woocommerce.php" ]; then
    echo "▶ Flattening nested WC wrapper (wp-env extraction quirk)..."
    mv "$stage/woocommerce"/* "$stage"/ 2>/dev/null || true
    find "$stage/woocommerce" -maxdepth 1 -name ".*" ! -name "." ! -name ".." -exec mv {} "$stage"/ \; 2>/dev/null
    rmdir "$stage/woocommerce" 2>/dev/null || true

    # The initial wp-env start exited non-zero (activation failed when WC
    # wasn't where it expected). wp-env's state-tracker then refuses every
    # subsequent `run` command with "Environment not initialized". Recover
    # by re-running start now that WC is flat — second start should succeed
    # end-to-end (containers already up, WC stage is valid, activation works).
    echo "  ⤷ re-running wp-env start to recover wp-env state..."
    npx --yes @wordpress/env start || true
  fi
}
flatten_wc_if_nested

echo "▶ Active plugins:"
npx --yes @wordpress/env run cli wp plugin list --status=active --field=name

echo "▶ Running smoke test against fresh install..."
if ! npx --yes @wordpress/env run cli wp eval-file "wp-content/plugins/${PLUGIN_SLUG}/tests/smoke.php"; then
  echo
  echo "✗ Tier 2.5 FAILED — smoke had failures on fresh install"
  echo "  (the shared test site Tier-2 may still be green; this surfaces fresh-install-only bugs)"
  exit 1
fi

# Tier 3 — scenario tests. If the plugin ships tests/e2e/scenarios/*.php,
# run each in order. Each scenario is a standalone PHP script run via
# `wp eval-file`; it does its own setup → action → assertion → teardown
# and exits 0 on pass, 1 on fail. Scenarios are independent of each other
# (no shared state assumed); failure of one doesn't abort the rest.
scenarios_dir="tests/e2e/scenarios"
scenario_count=0
scenario_failed=0
if compgen -G "${scenarios_dir}/*.php" >/dev/null 2>&1; then
  echo
  echo "▶ Running Tier 3 scenarios..."
  for scenario in "${scenarios_dir}"/*.php; do
    scenario_count=$((scenario_count + 1))
    name=$(basename "$scenario")
    echo "  ▶ ${name}"
    if ! npx --yes @wordpress/env run cli wp eval-file "wp-content/plugins/${PLUGIN_SLUG}/${scenario}"; then
      scenario_failed=$((scenario_failed + 1))
    fi
  done
  if [ "$scenario_failed" -ne 0 ]; then
    echo
    echo "✗ Tier 3 FAILED — ${scenario_failed}/${scenario_count} scenario(s) had failures"
    exit 1
  fi
  echo "✓ All ${scenario_count} Tier 3 scenarios passed"
fi

echo
if [ "$scenario_count" -gt 0 ]; then
  echo "✓ Tier 2.5 + Tier 3 PASSED — ${PLUGIN_SLUG} loads cleanly + ${scenario_count} scenario(s) green"
else
  echo "✓ Tier 2.5 PASSED — ${PLUGIN_SLUG} loads cleanly on fresh WP+WC"
fi
exit 0
