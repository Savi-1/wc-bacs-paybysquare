<?php
/**
 * Variable symbol derivation and the `pay_by_square_qr_variable_symbol`
 * filter added in 3.2.0 — the extension point requested on wordpress.org so
 * shops can pair transfers against a proforma-invoice number instead of the
 * order number.
 */

final class VariableSymbolTest extends QrTestCase {

	/**
	 * @dataProvider order_numbers
	 * @param string $order_number Order number as WooCommerce reports it.
	 * @param string $expected     Variable symbol that must reach the API.
	 */
	public function test_variable_symbol_is_derived_from_order_number( $order_number, $expected ): void {
		$this->respond_with_png();

		$this->plugin->fetch( $this->order( [ 'order_number' => $order_number ] ) );

		$this->assertSame( $expected, $this->last_request_value( 'VariableSymbol' ) );
	}

	/** @return array<string, array{0: string, 1: string}> */
	public static function order_numbers(): array {
		return [
			'plain number'                 => [ '1234', '1234' ],
			'prefixed invoice style'       => [ 'WI-2026/00123', '202600123' ],
			'hash prefix'                  => [ '#4321', '4321' ],
			'longer than ten digits trims' => [ '123456789012345', '1234567890' ],
			'letters only yields empty'    => [ 'ABC', '' ],
		];
	}

	public function test_filter_replaces_the_variable_symbol(): void {
		$this->respond_with_png();
		add_filter(
			'pay_by_square_qr_variable_symbol',
			static function ( $variable_symbol, $order ) {
				return '2026001';
			},
			10,
			2
		);

		$this->plugin->fetch( $this->order( [ 'order_number' => '1234' ] ) );

		$this->assertSame( '2026001', $this->last_request_value( 'VariableSymbol' ) );
	}

	public function test_filter_receives_default_and_order(): void {
		$this->respond_with_png();
		$received = [];
		add_filter(
			'pay_by_square_qr_variable_symbol',
			static function ( $variable_symbol, $order ) use ( &$received ) {
				$received = [ $variable_symbol, $order ];
				return $variable_symbol;
			},
			10,
			2
		);
		$order = $this->order( [ 'order_number' => 'WI-2026/00123' ] );

		$this->plugin->fetch( $order );

		$this->assertSame( '202600123', $received[0], 'Filter must see the derived default.' );
		$this->assertSame( $order, $received[1], 'Filter must receive the order it is generating for.' );
	}

	public function test_filter_returning_an_integer_is_stringified(): void {
		$this->respond_with_png();
		add_filter(
			'pay_by_square_qr_variable_symbol',
			static function () {
				return 9988;
			},
			10,
			2
		);

		$this->plugin->fetch( $this->order() );

		$this->assertSame( '9988', $this->last_request_value( 'VariableSymbol' ) );
	}

	public function test_filter_returning_an_array_cannot_leak_into_the_xml(): void {
		$this->respond_with_png();
		add_filter(
			'pay_by_square_qr_variable_symbol',
			static function () {
				return [ 'oops' ];
			},
			10,
			2
		);

		$this->plugin->fetch( $this->order() );

		$this->assertSame( '', $this->last_request_value( 'VariableSymbol' ) );
		$this->assertStringNotContainsString( 'Array', $this->last_request_xml() );
	}
}
