<?php
/**
 * The 3.2.0 getters shops use to place the QR code in their own markup:
 * get_qrcode_url() and get_qrcode_path(). Both must resolve an order by
 * object or ID, share the cache with the default render, and fail soft.
 */

final class PublicApiTest extends QrTestCase {

	public function test_url_getter_returns_the_cached_image_url_for_an_order_object(): void {
		$this->respond_with_png();
		$order = $this->order();

		$url = $this->plugin->get_qrcode_url( $order );

		$this->assertSame( $this->plugin->fetch( $order )[1], $url );
		$this->assertStringStartsWith( 'https://example.test/wp-content/uploads/paybysquare/', $url );
		$this->assertStringEndsWith( '.png', $url );
	}

	public function test_url_getter_resolves_an_order_id(): void {
		$this->respond_with_png();
		$order = fake_wp_add_order( $this->order( [ 'id' => 42 ] ) );

		$this->assertSame( $this->plugin->fetch( $order )[1], $this->plugin->get_qrcode_url( 42 ) );
	}

	public function test_path_getter_points_at_the_written_file(): void {
		$png = $this->respond_with_png();

		$path = $this->plugin->get_qrcode_path( $this->order() );

		$this->assertFileExists( $path );
		$this->assertSame( $png, file_get_contents( $path ) );
		$this->assertStringStartsWith( $this->uploads . '/paybysquare/', $path );
	}

	public function test_url_and_path_refer_to_the_same_image(): void {
		$this->respond_with_png();
		$order = $this->order();

		$url  = $this->plugin->get_qrcode_url( $order );
		$path = $this->plugin->get_qrcode_path( $order );

		$this->assertSame( basename( $url ), basename( $path ) );
		$this->assertCount( 1, fake_wp_requests(), 'The two getters must share one cached image.' );
	}

	public function test_getters_share_the_cache_with_the_thank_you_render(): void {
		$this->respond_with_png();
		$order = fake_wp_add_order( $this->order( [ 'id' => 42 ] ) );
		ob_start();
		$this->plugin->thankyou_page_qrcode( 42 );
		$html = (string) ob_get_clean();

		$url = $this->plugin->get_qrcode_url( $order );

		$this->assertStringContainsString( '<img src="' . $url . '"', $html );
		$this->assertCount( 1, fake_wp_requests() );
	}

	public function test_unknown_order_id_yields_empty_strings(): void {
		$this->respond_with_png();

		$this->assertSame( '', $this->plugin->get_qrcode_url( 999 ) );
		$this->assertSame( '', $this->plugin->get_qrcode_path( 999 ) );
		$this->assertSame( [], fake_wp_requests() );
	}

	public function test_failed_generation_yields_empty_strings(): void {
		$this->plugin->bacs_fixture = $this->bacs( [] );

		$this->assertSame( '', $this->plugin->get_qrcode_url( $this->order() ) );
		$this->assertSame( '', $this->plugin->get_qrcode_path( $this->order() ) );
	}

	public function test_getters_do_not_gate_on_payment_method_or_status(): void {
		// Same contract as thankyou_page_qrcode(): the caller decides where and
		// when the code belongs; the plugin only produces it.
		$this->respond_with_png();

		$url = $this->plugin->get_qrcode_url( $this->order( [ 'payment_method' => 'cod', 'status' => 'completed' ] ) );

		$this->assertStringEndsWith( '.png', $url );
	}
}
