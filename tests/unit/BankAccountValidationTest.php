<?php
/**
 * IBAN/BIC validation (3.1.0) and the auto-mode account preference: with
 * several accounts configured, the one matching the order currency must be
 * offered first because banking apps pick the first entry.
 */

final class BankAccountValidationTest extends QrTestCase {

	public function test_missing_bacs_gateway_generates_nothing(): void {
		$this->plugin->bacs_fixture = false;

		$this->assertSame( [], $this->plugin->fetch( $this->order() ) );
		$this->assertSame( [], fake_wp_requests() );
	}

	public function test_no_configured_accounts_logs_and_generates_nothing(): void {
		$this->plugin->bacs_fixture = $this->bacs( [] );

		$this->assertSame( [], $this->plugin->fetch( $this->order() ) );
		$this->assertStringContainsString( 'no IBAN+BIC specified', fake_wp_log_text() );
	}

	/**
	 * @dataProvider rejected_accounts
	 * @param string $iban Account IBAN.
	 * @param string $bic  Account BIC.
	 */
	public function test_malformed_account_is_rejected( $iban, $bic ): void {
		$this->plugin->bacs_fixture = $this->bacs( [ [ 'iban' => $iban, 'bic' => $bic ] ] );

		$this->assertSame( [], $this->plugin->fetch( $this->order() ) );
		$this->assertSame( [], fake_wp_requests() );
	}

	/** @return array<string, array{0: string, 1: string}> */
	public static function rejected_accounts(): array {
		return [
			'IBAN too short'          => [ 'SK8975', self::SK_BIC ],
			'IBAN without check digits' => [ 'SKXX75000000000012345671', self::SK_BIC ],
			'IBAN missing country'    => [ '8975000000000012345671', self::SK_BIC ],
			'BIC nine characters'     => [ self::SK_IBAN, 'CEKOSKBXX' ],
			'BIC too short'           => [ self::SK_IBAN, 'CEKOSK' ],
			'BIC digits in bank code' => [ self::SK_IBAN, '1EKOSKBX' ],
			'both empty'              => [ '', '' ],
			'IBAN one below minimum'  => [ 'SK891234567890', self::SK_BIC ],
			'IBAN one above maximum'  => [ 'SK89' . str_repeat( 'A1B2C', 6 ) . 'X', self::SK_BIC ],
		];
	}

	/**
	 * The IBAN regex allows 15 to 34 characters (2 letters, 2 digits, 11-30
	 * alphanumerics) — pin both ends so a loosened or tightened bound shows.
	 *
	 * @dataProvider iban_length_boundaries
	 * @param string $iban Account IBAN.
	 */
	public function test_iban_length_boundaries_are_accepted( $iban ): void {
		$this->respond_with_png();
		$this->plugin->bacs_fixture = $this->bacs( [ [ 'iban' => $iban, 'bic' => self::SK_BIC ] ] );

		$this->plugin->fetch( $this->order() );

		$this->assertSame( [ $iban ], $this->last_request_values( 'IBAN' ) );
	}

	/** @return array<string, array{0: string}> */
	public static function iban_length_boundaries(): array {
		return [
			'15 characters (minimum)' => [ 'SK8912345678901' ],
			'34 characters (maximum)' => [ 'SK89' . str_repeat( 'A1B2C', 6 ) ],
		];
	}

	/**
	 * @dataProvider incomplete_rows
	 * @param mixed $row Account row as it may come out of the option.
	 */
	public function test_incomplete_account_row_is_skipped( $row ): void {
		$this->plugin->bacs_fixture = $this->bacs( [ $row ] );

		$this->assertSame( [], $this->plugin->fetch( $this->order() ) );
		$this->assertSame( [], fake_wp_requests() );
		$this->assertStringContainsString( 'no IBAN+BIC specified', fake_wp_log_text() );
	}

	/** @return array<string, array{0: mixed}> */
	public static function incomplete_rows(): array {
		return [
			'missing bic key'  => [ [ 'iban' => self::SK_IBAN ] ],
			'missing iban key' => [ [ 'bic' => self::SK_BIC ] ],
			'not an array'     => [ self::SK_IBAN ],
			'null iban'        => [ [ 'iban' => null, 'bic' => self::SK_BIC ] ],
		];
	}

	public function test_incomplete_row_does_not_hide_a_complete_one(): void {
		$this->respond_with_png();
		$this->plugin->bacs_fixture = $this->bacs(
			[
				[ 'bic' => self::CZ_BIC ],
				[ 'iban' => self::SK_IBAN, 'bic' => self::SK_BIC ],
			]
		);

		$this->plugin->fetch( $this->order() );

		$this->assertSame( [ self::SK_IBAN ], $this->last_request_values( 'IBAN' ) );
	}

	public function test_missing_accounts_are_logged_as_a_warning_under_the_plugin_source(): void {
		$this->plugin->bacs_fixture = $this->bacs( [] );

		$this->plugin->fetch( $this->order() );

		$log = fake_wp_log();
		$this->assertCount( 1, $log );
		$this->assertSame( 'warning', $log[0]['level'] );
		$this->assertSame( 'wc-bacs-paybysquare', $log[0]['context']['source'] );
	}

	public function test_iban_and_bic_are_normalised_before_validation(): void {
		$this->respond_with_png();
		$this->plugin->bacs_fixture = $this->bacs(
			[ [ 'iban' => 'sk89 7500 0000 0000 1234 5671', 'bic' => 'ceko-skbx' ] ]
		);

		$this->plugin->fetch( $this->order() );

		$this->assertSame( [ self::SK_IBAN ], $this->last_request_values( 'IBAN' ) );
		$this->assertSame( [ self::SK_BIC ], $this->last_request_values( 'BIC' ) );
	}

	public function test_eleven_character_bic_is_accepted(): void {
		$this->respond_with_png();
		$this->plugin->bacs_fixture = $this->bacs( [ [ 'iban' => self::SK_IBAN, 'bic' => 'CEKOSKBXXXX' ] ] );

		$this->plugin->fetch( $this->order() );

		$this->assertSame( [ 'CEKOSKBXXXX' ], $this->last_request_values( 'BIC' ) );
	}

	public function test_auto_mode_offers_slovak_account_first_for_eur(): void {
		$this->respond_with_png();
		$this->plugin->bacs_fixture = $this->bacs(
			[
				[ 'iban' => self::CZ_IBAN, 'bic' => self::CZ_BIC ],
				[ 'iban' => self::SK_IBAN, 'bic' => self::SK_BIC ],
			]
		);

		$this->plugin->fetch( $this->order( [ 'currency' => 'EUR' ] ) );

		$this->assertSame( [ self::SK_IBAN, self::CZ_IBAN ], $this->last_request_values( 'IBAN' ) );
	}

	public function test_auto_mode_offers_czech_account_first_for_czk(): void {
		$this->respond_with_png( 'QrPlatbaCz' );
		$this->plugin->bacs_fixture = $this->bacs(
			[
				[ 'iban' => self::SK_IBAN, 'bic' => self::SK_BIC ],
				[ 'iban' => self::CZ_IBAN, 'bic' => self::CZ_BIC ],
			]
		);

		$this->plugin->fetch( $this->order( [ 'currency' => 'CZK' ] ) );

		$this->assertSame( [ self::CZ_IBAN, self::SK_IBAN ], $this->last_request_values( 'IBAN' ) );
	}

	public function test_forced_display_keeps_configured_account_order(): void {
		$this->respond_with_png();
		$this->plugin->options['display'] = 'slovak';
		$this->plugin->bacs_fixture      = $this->bacs(
			[
				[ 'iban' => self::CZ_IBAN, 'bic' => self::CZ_BIC ],
				[ 'iban' => self::SK_IBAN, 'bic' => self::SK_BIC ],
			]
		);

		$this->plugin->fetch( $this->order( [ 'currency' => 'EUR' ] ) );

		$this->assertSame( [ self::CZ_IBAN, self::SK_IBAN ], $this->last_request_values( 'IBAN' ) );
	}
}
