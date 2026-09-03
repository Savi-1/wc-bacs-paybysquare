<?php
/**
 * The "settings were moved" admin note. 3.2.0 scopes it to the BACS section
 * of WooCommerce → Settings → Payments; before that it fired on every gateway
 * section. This is the fix the release branch is named after, so the gate is
 * pinned in all four dimensions.
 */

final class SettingsNoticeTest extends QrTestCase {

	/**
	 * Point WooCommerce's settings-screen globals at a location.
	 *
	 * @param string|null $screen_id Current screen ID, or null for "no screen".
	 * @param string      $tab       Current WC settings tab.
	 * @param string      $section   Current WC settings section.
	 * @return void
	 */
	private function on_screen( $screen_id, $tab, $section ): void {
		global $current_screen, $current_tab, $current_section;

		$current_screen  = null === $screen_id ? null : (object) [ 'id' => $screen_id ];
		$current_tab     = $tab;
		$current_section = $section;
	}

	/** @return string Captured markup. */
	private function render_note(): string {
		ob_start();
		$this->plugin->add_settings_note();
		return (string) ob_get_clean();
	}

	protected function tearDown(): void {
		$this->on_screen( null, '', '' );
		parent::tearDown();
	}

	public function test_note_renders_on_the_bacs_gateway_section(): void {
		$this->on_screen( 'woocommerce_page_wc-settings', 'checkout', 'bacs' );

		$html = $this->render_note();

		$this->assertStringContainsString( 'settings were moved', $html );
		$this->assertStringContainsString( 'tab=integration', $html );
		$this->assertStringContainsString( 'section=paybysquare', $html );
	}

	/**
	 * @dataProvider other_locations
	 * @param string|null $screen_id Screen ID.
	 * @param string      $tab       Tab.
	 * @param string      $section   Section.
	 */
	public function test_note_is_silent_everywhere_else( $screen_id, $tab, $section ): void {
		$this->on_screen( $screen_id, $tab, $section );

		$this->assertSame( '', $this->render_note() );
	}

	/** @return array<string, array{0: string|null, 1: string, 2: string}> */
	public static function other_locations(): array {
		return [
			'another gateway section'      => [ 'woocommerce_page_wc-settings', 'checkout', 'cod' ],
			'payments tab without section' => [ 'woocommerce_page_wc-settings', 'checkout', '' ],
			'another settings tab'         => [ 'woocommerce_page_wc-settings', 'general', 'bacs' ],
			'another admin screen'         => [ 'edit-shop_order', 'checkout', 'bacs' ],
			'no screen at all'             => [ null, 'checkout', 'bacs' ],
		];
	}
}
