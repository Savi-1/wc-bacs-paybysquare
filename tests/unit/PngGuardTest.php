<?php
/**
 * The response-handling half of the pipeline: the PNG magic-byte guard added
 * in 3.2.0, the on-disk cache, and what happens when app.bysquare.com answers
 * with something other than an image.
 */

final class PngGuardTest extends QrTestCase {

	/** @return string Directory the pipeline caches generated PNGs in. */
	private function cache_dir(): string {
		return $this->uploads . '/paybysquare';
	}

	/** @return array<int, string> Files currently in the cache directory. */
	private function cached_files(): array {
		if ( ! is_dir( $this->cache_dir() ) ) {
			return [];
		}
		return array_values( array_diff( scandir( $this->cache_dir() ), [ '.', '..' ] ) );
	}

	public function test_valid_png_is_written_and_returned(): void {
		$png = $this->respond_with_png();

		$info = $this->plugin->fetch( $this->order() );

		$this->assertCount( 3, $info );
		[ $path, $url, $hash ] = $info;
		$this->assertSame( $this->cache_dir() . '/' . $hash . '.png', $path );
		$this->assertSame( 'https://example.test/wp-content/uploads/paybysquare/' . $hash . '.png', $url );
		$this->assertFileExists( $path );
		$this->assertSame( $png, file_get_contents( $path ) );
	}

	public function test_non_png_payload_is_refused_and_nothing_is_written(): void {
		fake_wp_set_response(
			fake_wp_xml_response( 200, [ 'PayBySquare' => base64_encode( '<html>rate limited</html>' ) ] )
		);

		$this->assertSame( [], $this->plugin->fetch( $this->order() ) );
		$this->assertSame( [], $this->cached_files(), 'A non-image response must never be cached as a .png.' );
		$this->assertStringContainsString( 'not a valid PNG image', fake_wp_log_text() );
	}

	public function test_missing_slovak_node_is_refused(): void {
		fake_wp_set_response( fake_wp_xml_response( 200, [ 'QrPlatbaCz' => base64_encode( 'x' ) ] ) );

		$this->assertSame( [], $this->plugin->fetch( $this->order() ) );
		$this->assertStringContainsString( 'missing paybysquare code', fake_wp_log_text() );
	}

	public function test_well_formed_html_page_is_refused_as_a_missing_code(): void {
		// XHTML parses as XML, so it gets past the parser and is caught by the
		// node check instead — still no image, still no cache write.
		fake_wp_set_response( [ 'response' => [ 'code' => 200 ], 'body' => '<html><body><h1>503 Service Unavailable</h1></body></html>' ] );

		$this->assertSame( [], $this->plugin->fetch( $this->order() ) );
		$this->assertStringContainsString( 'missing paybysquare code', fake_wp_log_text() );
		$this->assertSame( [], $this->cached_files() );
	}

	public function test_missing_czech_node_is_refused(): void {
		$this->plugin->options['display'] = 'czech';
		fake_wp_set_response( fake_wp_xml_response( 200, [ 'PayBySquare' => base64_encode( 'x' ) ] ) );

		$this->assertSame( [], $this->plugin->fetch( $this->order( [ 'currency' => 'CZK' ] ) ) );
		$this->assertStringContainsString( 'missing qrplatbacz code', fake_wp_log_text() );
	}

	public function test_transport_error_is_logged(): void {
		fake_wp_set_response( new WP_Error( 'http_request_failed', 'cURL error 28: timeout' ) );

		$this->assertSame( [], $this->plugin->fetch( $this->order() ) );
		$this->assertStringContainsString( 'cURL error 28: timeout', fake_wp_log_text() );
	}

	public function test_response_without_status_code_is_logged(): void {
		fake_wp_set_response( [ 'response' => [], 'body' => '<x/>' ] );

		$this->assertSame( [], $this->plugin->fetch( $this->order() ) );
		$this->assertStringContainsString( 'without a code', fake_wp_log_text() );
	}

	/**
	 * A proxy or WAF answering with an HTML error page must be logged, not
	 * leak libxml parser warnings into the thank-you page. failOnWarning in
	 * phpunit.xml.dist is what catches a regression here.
	 *
	 * @dataProvider non_xml_bodies
	 * @param string $body Response body.
	 */
	public function test_non_xml_body_is_refused( $body ): void {
		fake_wp_set_response( [ 'response' => [ 'code' => 200 ], 'body' => $body ] );

		$this->assertSame( [], $this->plugin->fetch( $this->order() ) );
		$this->assertStringContainsString( 'not valid XML', fake_wp_log_text() );
		$this->assertSame( [], $this->cached_files() );
	}

	/** @return array<string, array{0: string}> */
	public static function non_xml_bodies(): array {
		return [
			'plain text'      => [ 'not xml at all' ],
			'html error page' => [ '<!DOCTYPE html><html><body><p>503 Service Unavailable<br></body></html>' ],
			'truncated xml'   => [ '<?xml version="1.0"?><BySquareXmlResponse><PayBySquare>abc' ],
		];
	}

	public function test_parser_state_is_restored_after_a_bad_body(): void {
		fake_wp_set_response( [ 'response' => [ 'code' => 200 ], 'body' => 'not xml at all' ] );

		$this->plugin->fetch( $this->order() );

		$this->assertFalse( libxml_use_internal_errors( false ), 'libxml error handling must be left as it was found.' );
		$this->assertSame( [], libxml_get_errors() );
	}

	public function test_png_guard_logs_at_error_level_under_the_plugin_source(): void {
		fake_wp_set_response(
			fake_wp_xml_response( 200, [ 'PayBySquare' => base64_encode( '<html>rate limited</html>' ) ] )
		);

		$this->plugin->fetch( $this->order() );

		$log = fake_wp_log();
		$this->assertCount( 1, $log );
		$this->assertSame( 'error', $log[0]['level'] );
		$this->assertSame( 'wc-bacs-paybysquare', $log[0]['context']['source'] );
	}

	public function test_second_generation_is_served_from_cache(): void {
		$this->respond_with_png();
		$first = $this->plugin->fetch( $this->order() );

		$second = $this->plugin->fetch( $this->order() );

		$this->assertSame( $first, $second );
		$this->assertCount( 1, fake_wp_requests(), 'A cached QR code must not spend a second API credit.' );
	}

	public function test_corrupt_cache_entry_is_regenerated(): void {
		$png  = $this->respond_with_png();
		$info = $this->plugin->fetch( $this->order() );
		file_put_contents( $info[0], '<html>rate limited</html>' );

		$again = $this->plugin->fetch( $this->order() );

		$this->assertSame( $info, $again );
		$this->assertCount( 2, fake_wp_requests(), 'A poisoned cache entry must be regenerated, not served.' );
		$this->assertSame( $png, file_get_contents( $info[0] ) );
		$this->assertStringContainsString( 'not a valid PNG image, regenerating', fake_wp_log_text() );
	}

	public function test_zero_byte_cache_entry_is_regenerated(): void {
		$png  = $this->respond_with_png();
		$info = $this->plugin->fetch( $this->order() );
		file_put_contents( $info[0], '' );

		$this->plugin->fetch( $this->order() );

		$this->assertCount( 2, fake_wp_requests() );
		$this->assertSame( $png, file_get_contents( $info[0] ) );
	}

	/**
	 * The cache key is the whole payload plus the display setting. Every one
	 * of these changes must produce a new image, otherwise a shop that switches
	 * banks or corrects a beneficiary keeps serving the old QR code.
	 */
	public function test_switching_the_qr_standard_misses_the_cache(): void {
		$this->plugin->options['display'] = 'slovak';
		$this->respond_with_png();
		$slovak = $this->plugin->fetch( $this->order( [ 'currency' => 'CZK' ] ) );

		$this->plugin->options['display'] = 'czech';
		$this->respond_with_png( 'QrPlatbaCz' );
		$czech = $this->plugin->fetch( $this->order( [ 'currency' => 'CZK' ] ) );

		$this->assertNotSame( $slovak[2], $czech[2] );
		$this->assertCount( 2, fake_wp_requests() );
	}

	public function test_changing_the_bank_account_misses_the_cache(): void {
		$this->respond_with_png();
		$before = $this->plugin->fetch( $this->order() );

		$this->plugin->bacs_fixture = $this->bacs( [ [ 'iban' => self::CZ_IBAN, 'bic' => self::CZ_BIC ] ] );
		$after                      = $this->plugin->fetch( $this->order() );

		$this->assertNotSame( $before[2], $after[2] );
		$this->assertCount( 2, fake_wp_requests() );
	}

	public function test_changing_the_beneficiary_misses_the_cache(): void {
		$this->respond_with_png();
		$before = $this->plugin->fetch( $this->order() );

		$this->plugin->options['beneficiary'] = 'Webikon s.r.o.';
		$after                                = $this->plugin->fetch( $this->order() );

		$this->assertNotSame( $before[2], $after[2] );
		$this->assertCount( 2, fake_wp_requests() );
	}

	public function test_changing_the_amount_misses_the_cache(): void {
		$this->respond_with_png();
		$before = $this->plugin->fetch( $this->order( [ 'total' => '10.00' ] ) );

		$after = $this->plugin->fetch( $this->order( [ 'total' => '20.00' ] ) );

		$this->assertNotSame( $before[2], $after[2] );
		$this->assertCount( 2, fake_wp_requests() );
	}

	public function test_upload_directory_error_aborts_before_the_api_call(): void {
		$GLOBALS['fake_wp']['upload']['error'] = 'Unable to create directory.';

		$this->assertSame( [], $this->plugin->fetch( $this->order() ) );
		$this->assertSame( [], fake_wp_requests() );
		$this->assertStringContainsString( 'upload directory failed', fake_wp_log_text() );
	}

	public function test_credentials_are_sent_in_the_request(): void {
		$this->respond_with_png();

		$this->plugin->fetch( $this->order() );

		$requests = fake_wp_requests();
		$request  = end( $requests );
		$this->assertSame( 'https://app.bysquare.com/api/generateQR', $request['url'] );
		$this->assertSame( 'text/xml', $request['args']['headers']['content-type'] );
		$this->assertSame( 'user@example.test', $this->last_request_value( 'Username' ) );
		$this->assertSame( 'secret', $this->last_request_value( 'Password' ) );
	}
}
