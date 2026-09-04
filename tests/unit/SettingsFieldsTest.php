<?php
/**
 * The settings schema the QR pipeline depends on. Settings itself needs a
 * live WC_Integration to construct, so this pins only the declarative part:
 * the field list, its defaults and the beneficiary sanitizer.
 */

use Webikon\Woocommerce_Plugin\WC_BACS_Paybysquare\Settings;

final class SettingsFieldsTest extends QrTestCase {

	/** @return array<string, array<string, mixed>> */
	private function form_fields(): array {
		$settings = ( new ReflectionClass( Settings::class ) )->newInstanceWithoutConstructor();
		$settings->init_form_fields();
		return $settings->form_fields;
	}

	public function test_every_setting_the_pipeline_reads_is_a_declared_field(): void {
		$declared = array_keys( $this->form_fields() );

		foreach ( [ 'display', 'beneficiary', 'username', 'password', 'information' ] as $key ) {
			$this->assertContains( $key, $declared, "Plugin::get_option( '$key' ) reads a setting that is no longer a form field." );
		}
	}

	public function test_display_defaults_to_automatic_selection(): void {
		$fields = $this->form_fields();

		$this->assertSame( 'auto', $fields['display']['default'] );
		$this->assertSame( [ 'slovak', 'czech', 'auto' ], array_keys( $fields['display']['options'] ) );
	}

	public function test_credentials_default_to_empty(): void {
		$fields = $this->form_fields();

		$this->assertSame( '', $fields['username']['default'] );
		$this->assertSame( '', $fields['password']['default'] );
		$this->assertSame( 'password', $fields['password']['type'] );
	}

	public function test_beneficiary_sanitizer_keeps_the_value_and_warns_on_czech_invalid_characters(): void {
		$sanitize = $this->form_fields()['beneficiary']['sanitize_callback'];

		$this->assertSame( 'Kvetinárstvo Žofia', $sanitize( 'Kvetinárstvo Žofia' ) );
		$this->assertNotEmpty( $GLOBALS['fake_wp']['filters']['admin_notices'] ?? [], 'A diacritics name must queue the admin warning.' );
	}

	public function test_beneficiary_sanitizer_stays_quiet_for_valid_names(): void {
		$sanitize = $this->form_fields()['beneficiary']['sanitize_callback'];

		$this->assertSame( 'Webikon s.r.o.', $sanitize( 'Webikon s.r.o.' ) );
		$this->assertArrayNotHasKey( 'admin_notices', $GLOBALS['fake_wp']['filters'] );
	}

	public function test_czech_invalid_pattern_matches_what_the_pipeline_rejects(): void {
		$this->assertSame( 1, preg_match( Settings::QRPLATBA_INVALID, 'Žofia' ) );
		$this->assertSame( 0, preg_match( Settings::QRPLATBA_INVALID, 'WEBIKON S.R.O. $%+-/:' ) );
	}
}
