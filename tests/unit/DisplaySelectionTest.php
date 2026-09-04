<?php
/**
 * Which QR standard gets requested, and when the pipeline bails out before
 * spending an app.bysquare.com credit.
 */

final class DisplaySelectionTest extends QrTestCase {

	public function test_unset_display_generates_nothing(): void {
		$this->plugin->options['display'] = '';

		$this->assertSame( [], $this->plugin->fetch( $this->order() ) );
		$this->assertSame( [], fake_wp_requests(), 'No API credit may be spent when no standard is selected.' );
	}

	public function test_auto_display_with_unsupported_currency_generates_nothing(): void {
		$this->assertSame( [], $this->plugin->fetch( $this->order( [ 'currency' => 'USD' ] ) ) );
		$this->assertSame( [], fake_wp_requests() );
	}

	public function test_auto_display_with_eur_requests_slovak_standard(): void {
		$this->respond_with_png();

		$this->plugin->fetch( $this->order( [ 'currency' => 'EUR' ] ) );

		$this->assertSame( 'true', $this->last_request_value( 'Slovak' ) );
		$this->assertSame( 'false', $this->last_request_value( 'Czech' ) );
	}

	public function test_auto_display_with_czk_requests_czech_standard(): void {
		$this->respond_with_png( 'QrPlatbaCz' );
		$this->plugin->bacs_fixture = $this->bacs( [ [ 'iban' => self::CZ_IBAN, 'bic' => self::CZ_BIC ] ] );

		$this->plugin->fetch( $this->order( [ 'currency' => 'CZK' ] ) );

		$this->assertSame( 'false', $this->last_request_value( 'Slovak' ) );
		$this->assertSame( 'true', $this->last_request_value( 'Czech' ) );
	}

	public function test_forced_slovak_display_ignores_currency(): void {
		$this->respond_with_png();
		$this->plugin->options['display'] = 'slovak';

		$this->plugin->fetch( $this->order( [ 'currency' => 'CZK' ] ) );

		$this->assertSame( 'true', $this->last_request_value( 'Slovak' ) );
	}

	public function test_czech_standard_rejects_beneficiary_with_diacritics(): void {
		$this->respond_with_png( 'QrPlatbaCz' );
		$this->plugin->options['display']     = 'czech';
		$this->plugin->options['beneficiary'] = 'Kvetinárstvo Žofia';

		$this->assertSame( [], $this->plugin->fetch( $this->order( [ 'currency' => 'CZK' ] ) ) );
		$this->assertSame( [], fake_wp_requests() );
		$this->assertStringContainsString( 'Invalid character detected in beneficiary name', fake_wp_log_text() );
	}

	/**
	 * QR Platba allows a limited character set in the beneficiary name; the
	 * legal-form suffixes and separators Slovak/Czech shops actually use must
	 * stay inside it.
	 *
	 * @dataProvider czech_beneficiaries
	 * @param string $beneficiary Configured beneficiary name.
	 * @param string $expected    Uppercased name that must reach the API.
	 */
	public function test_czech_standard_accepts_legal_beneficiary_names( $beneficiary, $expected ): void {
		$this->respond_with_png( 'QrPlatbaCz' );
		$this->plugin->options['display']     = 'czech';
		$this->plugin->options['beneficiary'] = $beneficiary;
		$this->plugin->bacs_fixture           = $this->bacs( [ [ 'iban' => self::CZ_IBAN, 'bic' => self::CZ_BIC ] ] );

		$this->assertCount( 3, $this->plugin->fetch( $this->order( [ 'currency' => 'CZK' ] ) ) );
		$this->assertSame( $expected, $this->last_request_value( 'BeneficiaryName' ) );
	}

	/** @return array<string, array{0: string, 1: string}> */
	public static function czech_beneficiaries(): array {
		return [
			'legal form suffix' => [ 'Webikon s.r.o.', 'WEBIKON S.R.O.' ],
			'space'             => [ 'A B', 'A B' ],
			'allowed symbols'   => [ 'a$b%c+d-e.f/g:h', 'A$B%C+D-E.F/G:H' ],
		];
	}

	public function test_beneficiary_is_uppercased_in_payload(): void {
		$this->respond_with_png();
		$this->plugin->options['beneficiary'] = 'Webikon s.r.o.';

		$this->plugin->fetch( $this->order() );

		$this->assertSame( 'WEBIKON S.R.O.', $this->last_request_value( 'BeneficiaryName' ) );
	}

	public function test_settings_supplied_text_is_xml_escaped(): void {
		$this->respond_with_png();
		$this->plugin->options['beneficiary'] = 'Kvety & Dary';
		$this->plugin->options['username']    = 'shop&co';
		$this->plugin->options['password']    = 'p<a>ss"word';

		$this->plugin->fetch( $this->order() );

		$xml = $this->last_request_xml();
		$this->assertStringContainsString( '<BeneficiaryName>KVETY &amp; DARY</BeneficiaryName>', $xml );
		$this->assertStringContainsString( '<Username>shop&amp;co</Username>', $xml );
		$this->assertStringContainsString( '<Password>p&lt;a&gt;ss&quot;word</Password>', $xml );
		$this->assertNotFalse( simplexml_load_string( $xml ) );
	}

	/**
	 * The two fields the customer actually transfers must reach the API exactly
	 * as WooCommerce reports them — no reformatting, no rounding.
	 *
	 * @dataProvider amounts
	 * @param string $total    Order total as WC_Order::get_total() returns it.
	 * @param string $currency Order currency.
	 * @param string $node     Response node for that standard.
	 */
	public function test_amount_and_currency_pass_through_unmodified( $total, $currency, $node ): void {
		$this->respond_with_png( $node );
		$this->plugin->bacs_fixture = $this->bacs(
			[
				[ 'iban' => self::SK_IBAN, 'bic' => self::SK_BIC ],
				[ 'iban' => self::CZ_IBAN, 'bic' => self::CZ_BIC ],
			]
		);

		$this->plugin->fetch( $this->order( [ 'total' => $total, 'currency' => $currency ] ) );

		$this->assertSame( $total, $this->last_request_value( 'Amount' ) );
		$this->assertSame( $currency, $this->last_request_value( 'CurrencyCode' ) );
	}

	/** @return array<string, array{0: string, 1: string, 2: string}> */
	public static function amounts(): array {
		return [
			'euro with cents'    => [ '123.45', 'EUR', 'PayBySquare' ],
			'euro whole number'  => [ '10', 'EUR', 'PayBySquare' ],
			'koruna with haléře' => [ '2500.50', 'CZK', 'QrPlatbaCz' ],
		];
	}

	public function test_payment_note_carries_the_order_number(): void {
		$this->respond_with_png();

		$this->plugin->fetch( $this->order( [ 'order_number' => 'WI-2026/00123' ] ) );

		$this->assertSame( 'PAY by square WI-2026/00123', $this->last_request_value( 'PaymentNote' ) );
	}
}
