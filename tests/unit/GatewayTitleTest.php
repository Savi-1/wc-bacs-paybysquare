<?php
/**
 * The checkout-facing hint appended to the bank-transfer gateway title, e.g.
 * "Bank transfer (payment QR code)". Runs on every checkout render, so a
 * regression here is visible to every customer.
 */

final class GatewayTitleTest extends QrTestCase {

	public function test_information_is_appended_to_the_bacs_title(): void {
		$this->plugin->options['information'] = '(payment QR code)';

		$this->assertSame(
			'Bank transfer (payment QR code)',
			$this->plugin->filter_gateway_title( 'Bank transfer', 'bacs' )
		);
	}

	public function test_information_is_trimmed(): void {
		$this->plugin->options['information'] = '  (payment QR code)  ';

		$this->assertSame(
			'Bank transfer (payment QR code)',
			$this->plugin->filter_gateway_title( 'Bank transfer', 'bacs' )
		);
	}

	public function test_other_gateways_are_left_alone(): void {
		$this->plugin->options['information'] = '(payment QR code)';

		$this->assertSame( 'Cash on delivery', $this->plugin->filter_gateway_title( 'Cash on delivery', 'cod' ) );
	}

	public function test_blank_information_leaves_the_title_alone(): void {
		$this->plugin->options['information'] = '   ';

		$this->assertSame( 'Bank transfer', $this->plugin->filter_gateway_title( 'Bank transfer', 'bacs' ) );
	}

	public function test_missing_bacs_gateway_leaves_the_title_alone(): void {
		$this->plugin->options['information'] = '(payment QR code)';
		$this->plugin->bacs_fixture           = false;

		$this->assertSame( 'Bank transfer', $this->plugin->filter_gateway_title( 'Bank transfer', 'bacs' ) );
	}
}
