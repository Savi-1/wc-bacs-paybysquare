<?php
/**
 * This file is part of WordPress plugin: PAY by square for WooCommerce
 *
 * @package Webikon\Woocommerce_Plugin\WC_BACS_Paybysquare
 * @author Webikon (Matej Kravjar) <hello@webikon.sk>
 * @copyright 2017 Webikon & Matej Kravjar
 * @license GPLv2+
 *
 * Plugin Name: PAY by square for WooCommerce
 * Description: Adds a payment QR code on summary page of direct bank transfer
 * Version: 3.2.0
 * Author: Webikon
 * Author URI: https://webikon.sk
 * License: GPLv2+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wc-bacs-paybysquare
 * Domain Path: /languages
 * Requires at least: 6.0
 * Tested up to: 7.1
 * Requires PHP: 7.4
 * WC requires at least: 8.0
 * WC tested up to: 11.1
 * Requires Plugins: woocommerce
 */

namespace Webikon\Woocommerce_Plugin\WC_BACS_Paybysquare;

// protect against direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Declare compatibility with High-Performance Order Storage (HPOS) and with
// the Cart & Checkout blocks. The block Order Confirmation template fires the
// same woocommerce_thankyou_bacs hook the plugin renders on.
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);

require __DIR__ . '/src/class-logger.php';
require __DIR__ . '/src/class-plugin.php';
Plugin::run( __FILE__ );
