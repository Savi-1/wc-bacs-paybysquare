<?php
/**
 * Unit tests for the two static sanitizers that guard everything entering the
 * generated XML: scalar_to_string() (3.2.0, backs the new filters) and
 * sanitize() (IBAN/BIC normalisation).
 */

use Webikon\Woocommerce_Plugin\WC_BACS_Paybysquare\PluginProbe;

final class ScalarCoercionTest extends QrTestCase {

	/**
	 * @dataProvider scalar_values
	 * @param mixed  $input    Value a filter callback might return.
	 * @param string $expected Coerced result.
	 */
	public function test_scalar_to_string_coerces( $input, $expected ): void {
		$this->assertSame( $expected, PluginProbe::probe_scalar_to_string( $input ) );
	}

	/** @return array<string, array{0: mixed, 1: string}> */
	public static function scalar_values(): array {
		return [
			'string passes through' => [ 'VS123', 'VS123' ],
			'int becomes digits'    => [ 12345, '12345' ],
			'float keeps decimals'  => [ 10.5, '10.5' ],
			'true becomes 1'        => [ true, '1' ],
			'false becomes empty'   => [ false, '' ],
			'null collapses'        => [ null, '' ],
			'array collapses'       => [ [ 'nope' ], '' ],
			'object collapses'      => [ new stdClass(), '' ],
		];
	}

	public function test_sanitize_uppercases_and_strips_separators(): void {
		$this->assertSame(
			'SK8975000000000012345671',
			PluginProbe::probe_sanitize( 'sk89 7500 0000 0000 1234 5671' )
		);
	}

	public function test_sanitize_strips_punctuation(): void {
		$this->assertSame( 'CEKOSKBX', PluginProbe::probe_sanitize( 'ceko-skbx' ) );
	}

	public function test_sanitize_of_empty_string_is_empty(): void {
		$this->assertSame( '', PluginProbe::probe_sanitize( '' ) );
	}
}
