<?php
/**
 * How the pipeline reacts to the documented app.bysquare.com error responses,
 * including the monthly-limit flag the settings screen reads back.
 */

final class ApiErrorHandlingTest extends QrTestCase {

	const LIMIT_OPTION = 'woocommerce_bacs_paybysquare_limit_exceeded';

	public function test_monthly_limit_sets_the_flag(): void {
		fake_wp_set_response( fake_wp_xml_response( 400, [ 'ErrorCode' => 'E601' ] ) );

		$this->assertSame( [], $this->plugin->fetch( $this->order() ) );
		$this->assertSame( gmdate( 'Ym' ), get_option( self::LIMIT_OPTION ) );
		$this->assertStringContainsString( 'limit was reached', fake_wp_log_text() );
	}

	public function test_successful_generation_clears_the_limit_flag(): void {
		update_option( self::LIMIT_OPTION, gmdate( 'Ym' ) );
		$this->respond_with_png();

		$this->plugin->fetch( $this->order() );

		$this->assertFalse( get_option( self::LIMIT_OPTION ) );
	}

	public function test_other_bad_request_logs_code_message_and_detail(): void {
		fake_wp_set_response(
			fake_wp_xml_response(
				400,
				[
					'ErrorCode' => 'E301',
					'Message'   => 'Invalid IBAN',
					'Detail'    => 'account 1',
				]
			)
		);

		$this->assertSame( [], $this->plugin->fetch( $this->order() ) );
		$log = fake_wp_log_text();
		$this->assertStringContainsString( 'E301', $log );
		$this->assertStringContainsString( 'Invalid IBAN', $log );
		$this->assertStringContainsString( 'account 1', $log );
		$this->assertArrayNotHasKey( self::LIMIT_OPTION, $GLOBALS['fake_wp']['options'] );
	}

	public function test_bad_request_without_error_code_is_logged(): void {
		fake_wp_set_response( fake_wp_xml_response( 400, [] ) );

		$this->assertSame( [], $this->plugin->fetch( $this->order() ) );
		$this->assertStringContainsString( 'code 400 without details', fake_wp_log_text() );
	}

	public function test_rejected_credentials_are_logged(): void {
		fake_wp_set_response( fake_wp_xml_response( 401, [] ) );

		$this->assertSame( [], $this->plugin->fetch( $this->order() ) );
		$this->assertStringContainsString( 'Username and Password pair', fake_wp_log_text() );
	}

	public function test_unexpected_status_code_is_logged(): void {
		fake_wp_set_response( fake_wp_xml_response( 503, [] ) );

		$this->assertSame( [], $this->plugin->fetch( $this->order() ) );
		$this->assertStringContainsString( 'code "503"', fake_wp_log_text() );
	}
}
