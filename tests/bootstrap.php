<?php
/**
 * PHPUnit bootstrap: defines just enough WP/WC surface for src/ to load, then
 * loads the plugin classes plus the test probe.
 *
 * The heavy integration paths (real BACS gateway, real app.bysquare.com call,
 * real thank-you page render) are covered by tests/smoke.php on a live WP+WC
 * install — here everything outside src/ is faked.
 */

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}

	require __DIR__ . '/../vendor/autoload.php';
	require __DIR__ . '/FakeWp.php';

	fake_wp_reset();

	// Minimal WC_Integration stub so src/class-settings.php can be loaded for
	// its constants. Settings is never instantiated in unit tests.
	if ( ! class_exists( 'WC_Integration' ) ) {
		class WC_Integration {
			/** @var string */
			public $id = '';
			/** @var string */
			public $method_title = '';
			/** @var string */
			public $method_description = '';
			/** @var array<string, mixed> */
			public $form_fields = [];
			/** @var array<string, mixed> */
			public $settings = [];

			public function init_form_fields() {}
			public function init_settings() {}

			public function get_option( $key, $empty_value = null ) {
				return $this->settings[ $key ] ?? '';
			}
		}
	}

	// Minimal WC_Gateway_BACS stub: only the account list is read.
	if ( ! class_exists( 'WC_Gateway_BACS' ) ) {
		class WC_Gateway_BACS {
			/** @var array<int, array{iban?: mixed, bic?: mixed}> */
			public $account_details = [];

			/**
			 * @param array<int, array{iban?: mixed, bic?: mixed}> $accounts Bank accounts.
			 */
			public function __construct( array $accounts = [] ) {
				$this->account_details = $accounts;
			}
		}
	}

	// Minimal WC_Order stub covering the getters the QR payload needs.
	if ( ! class_exists( 'WC_Order' ) ) {
		class WC_Order {
			/** @var array<string, mixed> */
			private $data;

			/**
			 * @param array<string, mixed> $data Overrides for the defaults below.
			 */
			public function __construct( array $data = [] ) {
				$this->data = $data + [
					'id'             => 1,
					'order_number'   => '1',
					'total'          => '10.00',
					'currency'       => 'EUR',
					'payment_method' => 'bacs',
					'status'         => 'on-hold',
				];
			}

			public function get_id() {
				return $this->data['id'];
			}

			public function get_order_number() {
				return $this->data['order_number'];
			}

			public function get_total() {
				return $this->data['total'];
			}

			public function get_currency() {
				return $this->data['currency'];
			}

			public function get_payment_method() {
				return $this->data['payment_method'];
			}

			public function get_status() {
				return $this->data['status'];
			}
		}
	}

	require __DIR__ . '/../src/class-logger.php';
	require __DIR__ . '/../src/class-settings.php';
	require __DIR__ . '/../src/class-plugin.php';
	require __DIR__ . '/PluginProbe.php';
	require __DIR__ . '/QrTestCase.php';
}
