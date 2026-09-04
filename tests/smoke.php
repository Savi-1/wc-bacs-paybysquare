<?php
/**
 * Pre-release smoke test for wc-bacs-paybysquare.
 *
 * Confirms the plugin loads without fatals on a real WP+WC install, the
 * Settings WC_Integration is registered, the QR pipeline's runtime needs are
 * met (SimpleXML, outbound HTTPS to app.bysquare.com, writable uploads cache),
 * the thank-you / email hooks and the public API shops rely on are in place,
 * and the thank-you template on this site still fires the gateway hook.
 * Run before every tag.
 *
 * Run:
 *     wp eval-file tests/smoke.php
 *     wp eval-file tests/smoke.php skip-backlog-gate   (Tier-2.5 fresh-install harness)
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

// The QR image is rendered by app.bysquare.com and returned as base64 PNG —
// the plugin never encodes an image locally, so GD/Imagick/xz are NOT
// prerequisites (an earlier version of this smoke test claimed they were).
// What the pipeline actually needs: SimpleXML to parse the response, outbound
// HTTPS to reach the API, and a writable uploads directory for the PNG cache.
check( 'SimpleXML available (parses the API response)', extension_loaded( 'simplexml' ) );
check( 'wp_remote_post() available', function_exists( 'wp_remote_post' ) );

$pbsq_blocked = defined( 'WP_HTTP_BLOCK_EXTERNAL' ) && WP_HTTP_BLOCK_EXTERNAL;
$pbsq_allowed = defined( 'WP_ACCESSIBLE_HOSTS' )
	&& false !== stripos( (string) WP_ACCESSIBLE_HOSTS, 'app.bysquare.com' );
check(
	'Outbound HTTP to app.bysquare.com not blocked',
	! $pbsq_blocked || $pbsq_allowed,
	'WP_HTTP_BLOCK_EXTERNAL is on and app.bysquare.com is not in WP_ACCESSIBLE_HOSTS'
);

$pbsq_upload = wp_upload_dir();
check(
	'Uploads directory reported without error',
	empty( $pbsq_upload['error'] ),
	(string) ( $pbsq_upload['error'] ?? '' )
);
$pbsq_cache_dir = ( $pbsq_upload['basedir'] ?? '' ) . '/paybysquare';
check(
	'QR cache directory writable',
	wp_mkdir_p( $pbsq_cache_dir ) && is_writable( $pbsq_cache_dir ),
	$pbsq_cache_dir
);

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

	$blocks = \Automattic\WooCommerce\Utilities\FeaturesUtil::get_compatible_plugins_for_feature( 'cart_checkout_blocks' );
	check(
		'Cart & Checkout blocks compatibility declared as compatible',
		in_array( $plugin_id, $blocks['compatible'] ?? [], true ),
		'older WooCommerce lists undeclared plugins as incompatible in the Checkout block editor'
	);
}

// ─────────────────────────────────────────────────────────────────────────────
// Code-review backlog gate — added 2026-05-16.
// Fails the pre-tag smoke test while BACKLOG.md has any open `- [ ]` items.
// Clear them (check off `- [x]` or delete) before tagging a release.
// The Tier-2.5 fresh-install harness runs this same file to verify clean-install
// loadability and passes the `skip-backlog-gate` arg, so an open backlog doesn't
// fail that job — installability and release-readiness are different questions.
{
	$skip_backlog_gate = isset( $args ) && is_array( $args ) && in_array( 'skip-backlog-gate', $args, true );

	$cr_backlog_path = __DIR__ . '/../BACKLOG.md';
	if ( ! $skip_backlog_gate && is_readable( $cr_backlog_path ) ) {
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


// ─────────────────────────────────────────────────────────────────────────────
section( 'Integration surface: hooks + documented public API' );

$pbsq_plugin = Plugin::get_instance();

check(
	'QR code hooked onto woocommerce_thankyou_bacs',
	false !== has_action( 'woocommerce_thankyou_bacs', [ $pbsq_plugin, 'thankyou_page_qrcode' ] )
);
check(
	'QR code hooked into order emails at priority -1000',
	-1000 === has_action( 'woocommerce_email_order_meta', [ $pbsq_plugin, 'onhold_email_qrcode_info' ] )
);

// Shops place the QR code outside the default hook position by calling these
// methods directly, so their visibility is part of the public contract.
foreach ( [ 'get_instance', 'thankyou_page_qrcode', 'onhold_email_qrcode_info', 'get_qrcode_url', 'get_qrcode_path' ] as $pbsq_method ) {
	$pbsq_reflection = new ReflectionMethod( Plugin::class, $pbsq_method );
	check( "Plugin::$pbsq_method() is public", $pbsq_reflection->isPublic() );
}

// Source-pattern check: both documented filters must keep their names. Shops
// (and a wordpress.org support thread) depend on these exact strings.
$pbsq_source = (string) file_get_contents( __DIR__ . '/../src/class-plugin.php' );
foreach ( [ 'pay_by_square_qr_variable_symbol', 'pay_by_square_qrdata' ] as $pbsq_filter ) {
	check(
		"Filter '$pbsq_filter' present in source",
		false !== strpos( $pbsq_source, "apply_filters( '$pbsq_filter'" )
	);
}

// ─────────────────────────────────────────────────────────────────────────────
section( 'Thank-you page render path' );

// woocommerce_thankyou_bacs only fires from two places: the classic
// checkout/thankyou.php template, and the block Order Confirmation's
// "Additional information" block. If a site customised the block template and
// dropped that block, or overrode the classic template with an old copy, the
// QR code silently disappears from the thank-you page while emails keep
// working. That is the single most common support report for this plugin.
// Each branch ends in a check(), so this section always counts toward the
// exit code — a pristine site earns a ✓, not a silent skip.
$pbsq_is_block_theme = function_exists( 'wp_is_block_theme' ) && wp_is_block_theme();

if ( $pbsq_is_block_theme ) {
	// Block theme: WooCommerce serves its Order Confirmation block template and
	// the gateway hook only fires inside the "Additional information" block.
	// Both WooCommerce's own copy (upstream contract) and a Site Editor
	// customisation (where shops actually lose the block) must still carry it.
	$pbsq_block_templates = function_exists( 'get_block_templates' )
		? get_block_templates( [ 'slug__in' => [ 'order-confirmation' ] ] )
		: [];
	check(
		'Block Order Confirmation template resolves',
		! empty( $pbsq_block_templates ),
		'WooCommerce registers woocommerce//order-confirmation on block themes; nothing came back'
	);
	foreach ( $pbsq_block_templates as $pbsq_template ) {
		$pbsq_content = (string) $pbsq_template->content;
		if (
			function_exists( 'parse_blocks' )
			&& class_exists( \Automattic\WooCommerce\Blocks\Utils\BlockTemplateUtils::class )
			&& method_exists( \Automattic\WooCommerce\Blocks\Utils\BlockTemplateUtils::class, 'has_block_including_patterns' )
		) {
			// Resolves wp:pattern references, so a block moved into a synced
			// pattern still counts — WooCommerce uses this same helper.
			$pbsq_has_block = \Automattic\WooCommerce\Blocks\Utils\BlockTemplateUtils::has_block_including_patterns(
				[ 'woocommerce/order-confirmation-additional-information' ],
				parse_blocks( $pbsq_content )
			);
		} else {
			$pbsq_has_block = false !== strpos( $pbsq_content, 'woocommerce/order-confirmation-additional-information' );
		}
		$pbsq_origin = 'custom' === ( $pbsq_template->source ?? '' ) ? 'Site Editor customisation' : 'WooCommerce default';
		check(
			'Order Confirmation template (' . $pbsq_origin . ') keeps the "Additional information" block',
			(bool) $pbsq_has_block,
			'without it WooCommerce never fires woocommerce_thankyou_bacs on block checkout'
		);
	}
} else {
	// Classic theme: WooCommerce loads checkout/thankyou.php through
	// wc_locate_template(), which honours both override locations plus the
	// template-path filters — resolve it the same way instead of guessing.
	$pbsq_resolved = function_exists( 'wc_locate_template' ) ? (string) wc_locate_template( 'checkout/thankyou.php' ) : '';
	$pbsq_core     = function_exists( 'WC' ) ? WC()->plugin_path() . '/templates/checkout/thankyou.php' : '';
	if ( '' === $pbsq_resolved || $pbsq_resolved === $pbsq_core ) {
		check(
			'checkout/thankyou.php resolves to the WooCommerce core template',
			'' !== $pbsq_resolved,
			'wc_locate_template() returned nothing'
		);
	} else {
		// Any spelling of do_action( 'woocommerce_thankyou_' . $method ) counts,
		// including a precomputed hook name or double-quoted interpolation.
		check(
			'Theme override of checkout/thankyou.php still fires the gateway hook',
			1 === preg_match( '/woocommerce_thankyou_(?:[\'"]\s*\.|\{?\$)/', (string) file_get_contents( $pbsq_resolved ) ),
			$pbsq_resolved . ' overrides the WooCommerce template without the gateway-specific do_action()'
		);
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
