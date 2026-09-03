<?php
/**
 * Shared fixture for the QR-pipeline unit tests: a fresh fake WP, an isolated
 * uploads directory (the pipeline caches PNGs on disk) and a probe wired with
 * settings that would produce a valid Slovak QR code. Individual tests then
 * change only the one thing they are about.
 */

use PHPUnit\Framework\TestCase;
use Webikon\Woocommerce_Plugin\WC_BACS_Paybysquare\PluginProbe;

abstract class QrTestCase extends TestCase {
	/** A structurally valid Slovak IBAN + BIC pair. */
	const SK_IBAN = 'SK8975000000000012345671';
	const SK_BIC  = 'CEKOSKBX';

	/** A structurally valid Czech IBAN + BIC pair. */
	const CZ_IBAN = 'CZ6508000000192000145399';
	const CZ_BIC  = 'GIBACZPX';

	/** @var PluginProbe */
	protected $plugin;

	/** @var string */
	protected $uploads;

	protected function setUp(): void {
		parent::setUp();

		// Per-process + high-entropy name: two phpunit runs on one machine must
		// never share a cache directory (failOnWarning would turn a "File exists"
		// notice from mkdir() into a red suite).
		$this->uploads = sys_get_temp_dir() . '/pbsq-test-' . getmypid() . '-' . uniqid( '', true );
		$this->assertTrue( mkdir( $this->uploads, 0777, true ), 'Could not create the test uploads directory.' );
		fake_wp_reset( $this->uploads );

		$this->plugin               = new PluginProbe();
		$this->plugin->options      = [
			'display'     => 'auto',
			'beneficiary' => 'Webikon',
			'username'    => 'user@example.test',
			'password'    => 'secret',
		];
		$this->plugin->bacs_fixture = $this->bacs( [ [ 'iban' => self::SK_IBAN, 'bic' => self::SK_BIC ] ] );
	}

	protected function tearDown(): void {
		$this->rmrf( $this->uploads );
		parent::tearDown();
	}

	/**
	 * @param array<int, array{iban?: mixed, bic?: mixed}> $accounts Bank accounts.
	 * @return WC_Gateway_BACS
	 */
	protected function bacs( array $accounts ) {
		return new WC_Gateway_BACS( $accounts );
	}

	/**
	 * @param array<string, mixed> $data Order field overrides.
	 * @return WC_Order
	 */
	protected function order( array $data = [] ) {
		return new WC_Order( $data );
	}

	/**
	 * Queue a successful API response carrying a valid PNG.
	 *
	 * @param string $node Response node name (PayBySquare or QrPlatbaCz).
	 * @return string The raw PNG bytes the API "returned".
	 */
	protected function respond_with_png( $node = 'PayBySquare' ) {
		$png = "\x89PNG\r\n\x1a\n" . 'fake-image-bytes';
		fake_wp_set_response( fake_wp_xml_response( 200, [ $node => base64_encode( $png ) ] ) );
		return $png;
	}

	/**
	 * The XML body of the last captured API request.
	 *
	 * @return string
	 */
	protected function last_request_xml() {
		$requests = fake_wp_requests();
		$this->assertNotEmpty( $requests, 'Expected an API request to have been sent.' );
		return end( $requests )['args']['body'];
	}

	/**
	 * Text content of every occurrence of an XML element in the last request.
	 *
	 * @param string $element Element name.
	 * @return array<int, string>
	 */
	protected function last_request_values( $element ) {
		preg_match_all( '#<' . $element . '>(.*?)</' . $element . '>#s', $this->last_request_xml(), $matches );
		return $matches[1];
	}

	/**
	 * Single text content of an XML element in the last request.
	 *
	 * @param string $element Element name.
	 * @return string
	 */
	protected function last_request_value( $element ) {
		$values = $this->last_request_values( $element );
		return $values[0] ?? '';
	}

	/**
	 * @param string $dir Directory to remove recursively.
	 * @return void
	 */
	private function rmrf( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( scandir( $dir ) as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$path = $dir . '/' . $entry;
			is_dir( $path ) ? $this->rmrf( $path ) : unlink( $path );
		}
		rmdir( $dir );
	}
}
