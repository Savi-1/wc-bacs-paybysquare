<?php
/**
 * Pre-release smoke test for wc-bacs-paybysquare.
 *
 * Confirms the plugin loads without fatals on a real WP+WC install, the
 * Settings WC_Integration is registered, and PHP prerequisites for QR
 * generation (xz binary, GD or Imagick, proc_open) are reachable.
 * Run before every tag.
 *
 * Run:
 *     wp eval-file tests/smoke.php
 *
 * Exit code: 0 if all assertions pass, 1 if any fail.
 */

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped

use Webikon\Woocommerce_Plugin\WC_BACS_Paybysquare\Logger;
use Webikon\Woocommerce_Plugin\WC_BACS_Paybysquare\Plugin;
use Webikon\Woocommerce_Plugin\WC_BACS_Paybysquare\Settings;

// $GLOBALS[] not `global $failures`: see comment in any other Webikon smoke.
$GLOBALS['failures'] = [];

function check( string $name, bool $cond, string $detail = '' ): void {
	if ( $cond ) {
		echo "  ✓ $name\n";
	} else {
		echo "  ✗ $name" . ( $detail ? "  --  $detail" : '' ) . "\n";
		$GLOBALS['failures'][] = $name . ( $detail ? ": $detail" : '' );
	}
}

function section( string $title ): void {
	echo "\n== $title ==\n";
}

// ─────────────────────────────────────────────────────────────────────────────
section( 'Sanity: plugin loaded, classes reachable' );

check( 'Plugin class exists',   class_exists( Plugin::class ) );
check( 'Settings class exists', class_exists( Settings::class ) );
check( 'Logger class exists',   class_exists( Logger::class ) );

check(
	'Settings extends WC_Integration',
	is_subclass_of( Settings::class, 'WC_Integration' )
);

// ─────────────────────────────────────────────────────────────────────────────
section( 'PHP runtime: QR-generation prerequisites' );

// The plugin uses Endroid QrCode (or similar) and PAY by square encoding
// expects either GD or Imagick available; xz binary and proc_open for the
// LZMA compression step (SK PAY by square format).
check( 'GD or Imagick available',  extension_loaded( 'gd' ) || extension_loaded( 'imagick' ) );
check( 'proc_open() not disabled', function_exists( 'proc_open' ) );

// xz binary is the LZMA tooling for PAY by square (SK). Not strictly required
// for CZ SPAYD QR codes. Soft-check (informational; doesn't fail the smoke).
if ( function_exists( 'shell_exec' ) ) {
	$xz_path = trim( (string) @shell_exec( 'command -v xz 2>/dev/null' ) );
	if ( '' !== $xz_path ) {
		check( 'xz binary in PATH (for SK PAY by square LZMA)', true );
	} else {
		echo "  ⚠ xz binary not in PATH (SK PAY by square LZMA encoding requires it; CZ SPAYD QR codes are unaffected)\n";
	}
}

// ─────────────────────────────────────────────────────────────────────────────
section( 'WooCommerce integration: BACS gateway present, Settings tab registered' );

if ( ! function_exists( 'WC' ) ) {
	check( 'WooCommerce active', false, 'WC() global not defined — smoke must run on a WP+WC install' );
} else {
	$gateways = WC()->payment_gateways()->payment_gateways();
	check( 'WC BACS gateway present', isset( $gateways['bacs'] ) );

	$integrations = WC()->integrations->get_integrations();
	$found_pbs    = false;
	foreach ( $integrations as $integration ) {
		if ( $integration instanceof Settings ) {
			$found_pbs = true;
			break;
		}
	}
	check( 'Pay by Square Settings registered as a WC_Integration', $found_pbs );
}

// ─────────────────────────────────────────────────────────────────────────────
section( 'WC compatibility declarations' );

if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
	$plugin_id = plugin_basename( dirname( __DIR__ ) . '/wc-bacs-paybysquare.php' );

	$hpos = \Automattic\WooCommerce\Utilities\FeaturesUtil::get_compatible_plugins_for_feature( 'custom_order_tables' );
	check(
		'HPOS compatibility declared (any bucket)',
		isset( $hpos['compatible'], $hpos['incompatible'], $hpos['uncertain'] ) && (
			in_array( $plugin_id, $hpos['compatible'], true )
			|| in_array( $plugin_id, $hpos['incompatible'], true )
			|| in_array( $plugin_id, $hpos['uncertain'], true )
		)
	);
}

// ─────────────────────────────────────────────────────────────────────────────// ─────────────────────────────────────────────────────────────────────────────
// Code-review backlog gate — added 2026-05-16.
// Fails the pre-tag smoke test while BACKLOG.md has any open `- [ ]` items.
// Clear them (check off `- [x]` or delete) before tagging a release.
{
	$cr_backlog_path = __DIR__ . '/../BACKLOG.md';
	if ( is_readable( $cr_backlog_path ) ) {
		$cr_backlog = (string) file_get_contents( $cr_backlog_path );
		$cr_open    = (int) preg_match_all( '/^\s*-\s+\[\s\]/m', $cr_backlog );
		if ( function_exists( 'section' ) ) { section( 'Code-review backlog (BACKLOG.md)' ); }
		if ( function_exists( 'check' ) ) {
			check(
				'BACKLOG.md has no open findings',
				0 === $cr_open,
				sprintf( '%d open finding(s) — clear before tagging (source: CODE_REVIEW_2026_05_16.md)', $cr_open )
			);
		} elseif ( $cr_open > 0 ) {
			echo "\n  ✗ BACKLOG.md has $cr_open open finding(s) — clear before tagging.\n";
			$GLOBALS['failures'][] = sprintf( 'BACKLOG.md has %d open finding(s)', $cr_open );
		}
	}
}


section( 'Summary' );

if ( empty( $GLOBALS['failures'] ) ) {
	echo "\nAll smoke checks passed.\n";
	exit( 0 );
}

echo "\n" . count( $GLOBALS['failures'] ) . " smoke check(s) failed:\n";
foreach ( $GLOBALS['failures'] as $f ) {
	echo "  - $f\n";
}
exit( 1 );
