<?php
/**
 * The plugin's integration surface. Both entries matter to shops: the
 * thank-you hook is where the QR code appears, and thankyou_page_qrcode()
 * being public is what lets a shop place the QR code elsewhere.
 */

use Webikon\Woocommerce_Plugin\WC_BACS_Paybysquare\Plugin;

final class HookRegistrationTest extends QrTestCase {

	/** @var Plugin */
	private $singleton;

	protected function setUp(): void {
		parent::setUp();
		Plugin::run( '/wp-content/plugins/wc-bacs-paybysquare/wc-bacs-paybysquare.php' );
		// run() registers everything on the singleton, and WordPress matches an
		// [ $object, 'method' ] callback by instance — so look it up on that
		// exact object, the way real has_action() would.
		$this->singleton = Plugin::get_instance();
	}

	public function test_qr_code_is_hooked_onto_the_bacs_thankyou_page(): void {
		$this->assertSame(
			10,
			has_action( 'woocommerce_thankyou_bacs', [ $this->singleton, 'thankyou_page_qrcode' ] )
		);
	}

	public function test_email_hook_runs_before_other_order_meta(): void {
		$this->assertSame(
			-1000,
			has_action( 'woocommerce_email_order_meta', [ $this->singleton, 'onhold_email_qrcode_info' ] ),
			'The QR code is meant to sit above the order table in the email.'
		);
	}

	public function test_gateway_title_filter_is_registered_late(): void {
		$this->assertSame(
			1000,
			has_filter( 'woocommerce_gateway_title', [ $this->singleton, 'filter_gateway_title' ] )
		);
	}

	public function test_settings_note_is_hooked_into_the_payments_settings_screen(): void {
		$this->assertSame(
			1000,
			has_action( 'woocommerce_settings_checkout', [ $this->singleton, 'add_settings_note' ] )
		);
	}

	public function test_integration_is_registered_on_plugins_loaded(): void {
		$this->assertNotFalse( has_action( 'plugins_loaded', [ $this->singleton, 'preinit' ] ) );
	}

	/**
	 * WordPress passes exactly accepted_args arguments to a callback. Too few
	 * and the method fatals when WooCommerce fires the hook; too many and an
	 * argument is silently dropped.
	 *
	 * @dataProvider hook_arities
	 * @param string $tag           Hook name.
	 * @param string $method        Plugin method hooked to it.
	 * @param int    $accepted_args Arguments the method needs.
	 */
	public function test_hook_accepts_the_arguments_woocommerce_passes( $tag, $method, $accepted_args ): void {
		$this->assertSame( $accepted_args, fake_wp_accepted_args( $tag, [ $this->singleton, $method ] ) );
	}

	/** @return array<string, array{0: string, 1: string, 2: int}> */
	public static function hook_arities(): array {
		return [
			'gateway title gets title + gateway id' => [ 'woocommerce_gateway_title', 'filter_gateway_title', 2 ],
			'email meta gets order + admin + plain' => [ 'woocommerce_email_order_meta', 'onhold_email_qrcode_info', 3 ],
			'settings note takes no arguments'      => [ 'woocommerce_settings_checkout', 'add_settings_note', 0 ],
			'plugin row notice gets file + data'    => [ 'after_plugin_row_wc-bacs-paybysquare/wc-bacs-paybysquare.php', 'plugin_row_notice', 2 ],
			'thank-you gets the order id'           => [ 'woocommerce_thankyou_bacs', 'thankyou_page_qrcode', 1 ],
		];
	}

	/**
	 * @dataProvider public_api
	 * @param string $method Method that shops may call directly.
	 */
	public function test_method_stays_public( $method ): void {
		$reflection = new ReflectionMethod( Plugin::class, $method );

		$this->assertTrue( $reflection->isPublic(), $method . '() is relied on by shop-side snippets.' );
	}

	/** @return array<int, array{0: string}> */
	public static function public_api(): array {
		return [
			[ 'get_instance' ],
			[ 'thankyou_page_qrcode' ],
			[ 'onhold_email_qrcode_info' ],
			[ 'get_qrcode_url' ],
			[ 'get_qrcode_path' ],
		];
	}
}
