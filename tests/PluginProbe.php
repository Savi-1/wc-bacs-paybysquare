<?php
/**
 * Test-only subclass that opens up the QR pipeline for unit testing.
 *
 * Plugin keeps fetch_qrcode_png_info() and the two static sanitizers
 * protected, and reads its settings through a WC_Integration. The probe
 * widens the constructor (Plugin's is protected for the singleton), swaps
 * settings + BACS lookups for in-memory fixtures, and exposes the pipeline
 * entry point. Nothing here changes production behaviour.
 */

namespace Webikon\Woocommerce_Plugin\WC_BACS_Paybysquare;

class PluginProbe extends Plugin {
	/**
	 * Integration settings the probe serves to the code under test.
	 *
	 * @var array<string, string>
	 */
	public $options = [];

	/**
	 * BACS gateway fixture, or false to simulate "gateway unavailable".
	 *
	 * @var \WC_Gateway_BACS|false
	 */
	public $bacs_fixture = false;

	/**
	 * Widen the singleton constructor for tests.
	 */
	public function __construct() {
		parent::__construct();
	}

	/**
	 * @param string $option_key Setting key.
	 * @return string
	 */
	public function get_option( $option_key ) {
		return $this->options[ $option_key ] ?? '';
	}

	/**
	 * @return \WC_Gateway_BACS|false
	 */
	public function get_bacs() {
		return $this->bacs_fixture;
	}

	/**
	 * Run the QR pipeline.
	 *
	 * @param \WC_Order $order Order to generate for.
	 * @return array{0: string, 1: string, 2: string}|array{}
	 */
	public function fetch( $order ) {
		return $this->fetch_qrcode_png_info( $order );
	}

	/**
	 * Render the QR markup for a given image source.
	 *
	 * @param string $src Image src attribute value.
	 * @return string Captured markup.
	 */
	public function render( $src ) {
		ob_start();
		$this->output_qr_code_image( $src );
		return (string) ob_get_clean();
	}

	/**
	 * @param string $value Raw IBAN/BIC.
	 * @return string
	 */
	public static function probe_sanitize( $value ) {
		return static::sanitize( $value );
	}

	/**
	 * @param mixed $value Value from a filter callback.
	 * @return string
	 */
	public static function probe_scalar_to_string( $value ) {
		return static::scalar_to_string( $value );
	}
}
