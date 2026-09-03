<?php
/**
 * What actually reaches the customer: the thank-you page render, the email
 * render and its gating, the PHPMailer embedding, and the markup itself.
 *
 * thankyou_page_qrcode() is public API in practice — shops call it to place
 * the QR code somewhere other than the default hook position — so its
 * behaviour is pinned here on purpose.
 */

/**
 * Stand-in for the PHPMailer instance WordPress hands to phpmailer_init.
 * Records what the plugin tried to embed.
 */
final class FakeMailer {
	/** @var array<int, array{0: string, 1: string}> */
	public $embedded = [];

	/** @var bool */
	public $fail = false;

	// phpcs:ignore WordPress.NamingConventions.ValidVariableName.PropertyNotSnakeCase
	public $ErrorInfo = 'Could not access file';

	/**
	 * @param string $path Image path.
	 * @param string $cid  Content ID.
	 * @return bool
	 */
	public function addEmbeddedImage( $path, $cid ) {
		if ( $this->fail ) {
			return false;
		}
		$this->embedded[] = [ $path, $cid ];
		return true;
	}
}

final class RenderTest extends QrTestCase {

	/**
	 * @param int $order_id Order to render for.
	 * @return string Captured markup.
	 */
	private function render_thankyou( $order_id ): string {
		ob_start();
		$this->plugin->thankyou_page_qrcode( $order_id );
		return (string) ob_get_clean();
	}

	/**
	 * @param WC_Order $order         Order to render for.
	 * @param bool     $sent_to_admin Admin copy of the email.
	 * @param bool     $plain_text    Plain-text email.
	 * @return string Captured markup.
	 */
	private function render_email( $order, $sent_to_admin = false, $plain_text = false ): string {
		ob_start();
		$this->plugin->onhold_email_qrcode_info( $order, $sent_to_admin, $plain_text );
		return (string) ob_get_clean();
	}

	public function test_thankyou_page_renders_the_cached_image_url(): void {
		$this->respond_with_png();
		$order = fake_wp_add_order( $this->order( [ 'id' => 42 ] ) );
		$info  = $this->plugin->fetch( $order );

		$html = $this->render_thankyou( 42 );

		$this->assertStringContainsString( '<img src="' . $info[1] . '"', $html );
		$this->assertStringContainsString( 'scan this QR code', $html );
	}

	public function test_thankyou_page_renders_nothing_for_an_unknown_order(): void {
		$this->respond_with_png();

		$this->assertSame( '', $this->render_thankyou( 999 ) );
	}

	public function test_thankyou_page_renders_nothing_when_generation_fails(): void {
		$this->plugin->bacs_fixture = $this->bacs( [] );
		fake_wp_add_order( $this->order( [ 'id' => 42 ] ) );

		$this->assertSame( '', $this->render_thankyou( 42 ) );
	}

	/**
	 * Characterisation: unlike the email, the thank-you render has no status
	 * gate. That mirrors WC_Gateway_BACS::thankyou_page(), which prints the
	 * bank details for any status, and it is what shops calling the method
	 * directly get today.
	 *
	 * @dataProvider any_status
	 * @param string $status Order status.
	 */
	public function test_thankyou_page_renders_regardless_of_order_status( $status ): void {
		$this->respond_with_png();
		fake_wp_add_order( $this->order( [ 'id' => 42, 'status' => $status ] ) );

		$this->assertStringContainsString( '<img src="', $this->render_thankyou( 42 ) );
	}

	/** @return array<string, array{0: string}> */
	public static function any_status(): array {
		return [
			'on-hold'   => [ 'on-hold' ],
			'pending'   => [ 'pending' ],
			'completed' => [ 'completed' ],
			'failed'    => [ 'failed' ],
		];
	}

	public function test_email_embeds_the_image_by_content_id(): void {
		$this->respond_with_png();
		$order = $this->order();
		$info  = $this->plugin->fetch( $order );

		$html = $this->render_email( $order );

		$this->assertStringContainsString( '<img src="cid:' . $info[2] . '"', $html );
		$this->assertNotFalse(
			has_action( 'phpmailer_init', [ $this->plugin, 'onhold_email_attachments' ] ),
			'The embedded image needs the phpmailer_init hook to be attached.'
		);
	}

	public function test_email_attachment_is_embedded_once_and_the_hook_disarms(): void {
		$this->respond_with_png();
		$order = $this->order();
		$info  = $this->plugin->fetch( $order );
		$this->render_email( $order );

		$mailer = new FakeMailer();
		do_action( 'phpmailer_init', $mailer );

		$this->assertSame( [ [ $info[0], $info[2] ] ], $mailer->embedded );
		$this->assertFalse(
			has_action( 'phpmailer_init', [ $this->plugin, 'onhold_email_attachments' ] ),
			'The hook must disarm after one send.'
		);

		// Any later email in the same request must not inherit the image.
		$later = new FakeMailer();
		do_action( 'phpmailer_init', $later );

		$this->assertSame( [], $later->embedded );
	}

	public function test_each_email_embeds_its_own_order(): void {
		$this->respond_with_png();
		$first  = $this->order( [ 'id' => 1, 'order_number' => '1001' ] );
		$second = $this->order( [ 'id' => 2, 'order_number' => '1002' ] );

		$this->render_email( $first );
		$mailer_one = new FakeMailer();
		do_action( 'phpmailer_init', $mailer_one );

		$this->render_email( $second );
		$mailer_two = new FakeMailer();
		do_action( 'phpmailer_init', $mailer_two );

		$this->assertSame( $this->plugin->fetch( $first )[2], $mailer_one->embedded[0][1] );
		$this->assertSame( $this->plugin->fetch( $second )[2], $mailer_two->embedded[0][1] );
		$this->assertNotSame( $mailer_one->embedded, $mailer_two->embedded );
	}

	public function test_failed_embedding_is_logged_as_a_warning(): void {
		$this->respond_with_png();
		$order = $this->order();
		$this->render_email( $order );

		$mailer       = new FakeMailer();
		$mailer->fail = true;
		do_action( 'phpmailer_init', $mailer );

		$log = fake_wp_log();
		$this->assertSame( 'warning', end( $log )['level'] );
		$this->assertStringContainsString( 'Could not access file', fake_wp_log_text() );
	}

	public function test_email_skips_the_admin_copy(): void {
		$this->respond_with_png();

		$this->assertSame( '', $this->render_email( $this->order(), true, false ) );
	}

	public function test_email_skips_plain_text(): void {
		$this->respond_with_png();

		$this->assertSame( '', $this->render_email( $this->order(), false, true ) );
	}

	public function test_email_skips_other_payment_methods(): void {
		$this->respond_with_png();

		$this->assertSame( '', $this->render_email( $this->order( [ 'payment_method' => 'cod' ] ) ) );
	}

	public function test_email_skips_orders_that_are_no_longer_on_hold(): void {
		$this->respond_with_png();

		$this->assertSame( '', $this->render_email( $this->order( [ 'status' => 'processing' ] ) ) );
	}

	public function test_email_renders_nothing_when_generation_fails(): void {
		$this->plugin->bacs_fixture = $this->bacs( [] );

		$this->assertSame( '', $this->render_email( $this->order() ) );
		$this->assertFalse( has_action( 'phpmailer_init', [ $this->plugin, 'onhold_email_attachments' ] ) );
	}

	public function test_image_source_is_attribute_escaped(): void {
		$html = $this->plugin->render( 'https://example.test/qr.png?a=1&b=2' );

		$this->assertStringContainsString( 'src="https://example.test/qr.png?a=1&amp;b=2"', $html );
	}

	public function test_empty_source_renders_nothing(): void {
		$this->assertSame( '', $this->plugin->render( '' ) );
	}
}
