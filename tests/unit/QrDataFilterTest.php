<?php
/**
 * The `pay_by_square_qrdata` filter added in 3.2.0: full control over the QR
 * payload, plus the normalisation that keeps a hostile or sloppy callback from
 * producing invalid XML.
 */

final class QrDataFilterTest extends QrTestCase {

	public function test_filter_can_replace_every_scalar_field(): void {
		$this->respond_with_png();
		add_filter(
			'pay_by_square_qrdata',
			static function ( $qrdata, $order ) {
				$qrdata['total']            = '42.50';
				$qrdata['currency']         = 'CZK';
				$qrdata['variable_symbol']  = '777';
				$qrdata['payment_note']     = 'Proforma 2026001';
				$qrdata['beneficiary_name'] = 'WEBIKON';
				return $qrdata;
			},
			10,
			2
		);

		$this->plugin->fetch( $this->order() );

		$this->assertSame( '42.50', $this->last_request_value( 'Amount' ) );
		$this->assertSame( 'CZK', $this->last_request_value( 'CurrencyCode' ) );
		$this->assertSame( '777', $this->last_request_value( 'VariableSymbol' ) );
		$this->assertSame( 'Proforma 2026001', $this->last_request_value( 'PaymentNote' ) );
		$this->assertSame( 'WEBIKON', $this->last_request_value( 'BeneficiaryName' ) );
		// The QR standard is chosen from the ORDER currency before the filter
		// runs, so a filtered currency code does not switch to QR Platba.
		$this->assertSame( 'true', $this->last_request_value( 'Slovak' ) );
		$this->assertSame( 'false', $this->last_request_value( 'Czech' ) );
	}

	public function test_filtered_currency_does_not_switch_the_qr_standard(): void {
		$this->respond_with_png( 'QrPlatbaCz' );
		add_filter(
			'pay_by_square_qrdata',
			static function ( $qrdata, $order ) {
				$qrdata['currency'] = 'CZK';
				return $qrdata;
			},
			10,
			2
		);

		$result = $this->plugin->fetch( $this->order( [ 'currency' => 'EUR' ] ) );

		$this->assertSame( 'true', $this->last_request_value( 'Slovak' ) );
		$this->assertSame( 'CZK', $this->last_request_value( 'CurrencyCode' ) );
		$this->assertSame( [], $result, 'A Slovak request answered with only a Czech code must be refused.' );
		$this->assertStringContainsString( 'missing paybysquare code', fake_wp_log_text() );
	}

	public function test_filter_supplied_text_is_xml_escaped(): void {
		$this->respond_with_png();
		add_filter(
			'pay_by_square_qrdata',
			static function ( $qrdata, $order ) {
				$qrdata['payment_note']     = 'Faktura 1 & 2 <b>';
				$qrdata['beneficiary_name'] = 'H&M';
				$qrdata['variable_symbol']  = '12<34';
				$qrdata['bank_accounts'][]  = [
					'iban' => 'SK&1',
					'bic'  => 'A<B',
				];
				return $qrdata;
			},
			10,
			2
		);

		$this->plugin->fetch( $this->order() );

		$xml = $this->last_request_xml();
		$this->assertStringContainsString( '<PaymentNote>Faktura 1 &amp; 2 &lt;b&gt;</PaymentNote>', $xml );
		$this->assertStringContainsString( '<BeneficiaryName>H&amp;M</BeneficiaryName>', $xml );
		$this->assertStringContainsString( '<VariableSymbol>12&lt;34</VariableSymbol>', $xml );
		$this->assertStringContainsString( '<IBAN>SK&amp;1</IBAN><BIC>A&lt;B</BIC>', $xml );
		$this->assertNotFalse( simplexml_load_string( $xml ), 'The request document must stay well-formed XML.' );
	}

	/**
	 * @dataProvider scalar_fields
	 * @param string $field   Payload key a callback may corrupt.
	 * @param string $element XML element it ends up in.
	 */
	public function test_non_scalar_field_collapses_to_empty( $field, $element ): void {
		$this->respond_with_png();
		add_filter(
			'pay_by_square_qrdata',
			static function ( $qrdata, $order ) use ( $field ) {
				$qrdata[ $field ] = [ 'nope' ];
				return $qrdata;
			},
			10,
			2
		);

		$this->plugin->fetch( $this->order() );

		$this->assertSame( '', $this->last_request_value( $element ) );
		$this->assertStringNotContainsString( 'Array', $this->last_request_xml() );
	}

	/** @return array<string, array{0: string, 1: string}> */
	public static function scalar_fields(): array {
		return [
			'total'            => [ 'total', 'Amount' ],
			'currency'         => [ 'currency', 'CurrencyCode' ],
			'variable_symbol'  => [ 'variable_symbol', 'VariableSymbol' ],
			'payment_note'     => [ 'payment_note', 'PaymentNote' ],
			'beneficiary_name' => [ 'beneficiary_name', 'BeneficiaryName' ],
		];
	}

	public function test_filter_receives_the_narrow_filters_result(): void {
		$this->respond_with_png();
		add_filter(
			'pay_by_square_qr_variable_symbol',
			static function () {
				return '5555';
			},
			10,
			2
		);
		$seen = null;
		add_filter(
			'pay_by_square_qrdata',
			static function ( $qrdata, $order ) use ( &$seen ) {
				$seen = $qrdata['variable_symbol'];
				return $qrdata;
			},
			10,
			2
		);

		$this->plugin->fetch( $this->order() );

		$this->assertSame( '5555', $seen, 'The wide filter must run after the narrow one.' );
	}

	public function test_filter_can_add_a_bank_account(): void {
		$this->respond_with_png();
		add_filter(
			'pay_by_square_qrdata',
			static function ( $qrdata, $order ) {
				$qrdata['bank_accounts'][] = [
					'iban' => QrTestCase::CZ_IBAN,
					'bic'  => QrTestCase::CZ_BIC,
				];
				return $qrdata;
			},
			10,
			2
		);

		$this->plugin->fetch( $this->order() );

		$this->assertSame( [ self::SK_IBAN, self::CZ_IBAN ], $this->last_request_values( 'IBAN' ) );
	}

	public function test_account_without_bic_is_dropped(): void {
		$this->respond_with_png();
		add_filter(
			'pay_by_square_qrdata',
			static function ( $qrdata, $order ) {
				$qrdata['bank_accounts'][] = [ 'iban' => QrTestCase::CZ_IBAN ];
				return $qrdata;
			},
			10,
			2
		);

		$this->plugin->fetch( $this->order() );

		$this->assertSame( [ self::SK_IBAN ], $this->last_request_values( 'IBAN' ) );
	}

	public function test_non_scalar_account_field_collapses_to_empty(): void {
		$this->respond_with_png();
		add_filter(
			'pay_by_square_qrdata',
			static function ( $qrdata, $order ) {
				$qrdata['bank_accounts'] = [ [ 'iban' => [ 'nope' ], 'bic' => QrTestCase::SK_BIC ] ];
				return $qrdata;
			},
			10,
			2
		);

		$this->plugin->fetch( $this->order() );

		$this->assertSame( [ '' ], $this->last_request_values( 'IBAN' ) );
		$this->assertStringNotContainsString( 'Array', $this->last_request_xml() );
	}

	public function test_filter_returning_a_non_array_does_not_break_the_request(): void {
		$this->respond_with_png();
		add_filter(
			'pay_by_square_qrdata',
			static function () {
				return 'garbage';
			},
			10,
			2
		);

		// Documented fallback: the payload empties out rather than fatalling or
		// leaking a PHP notice into the XML. The API rejects it, which is the
		// correct outcome for a filter that discarded the data.
		$this->plugin->fetch( $this->order() );

		$this->assertSame( '', $this->last_request_value( 'Amount' ) );
		$this->assertSame( [], $this->last_request_values( 'IBAN' ) );
	}

	public function test_filtered_payload_gets_its_own_cache_entry(): void {
		$this->respond_with_png();
		$this->plugin->fetch( $this->order() );
		$unfiltered = fake_wp_requests();

		add_filter(
			'pay_by_square_qrdata',
			static function ( $qrdata, $order ) {
				$qrdata['variable_symbol'] = '1111';
				return $qrdata;
			},
			10,
			2
		);
		$this->respond_with_png();
		$this->plugin->fetch( $this->order() );

		$this->assertCount(
			count( $unfiltered ) + 1,
			fake_wp_requests(),
			'Changing filtered data must miss the PNG cache, not serve the previous image.'
		);
	}
}
