<?php
/**
 * Plugin Name: PAY by square — Demo QR Code
 * Description: Generates a demo QR code for the WordPress Playground live preview without requiring API credentials.
 * Version: 1.0.0
 * Author: Webikon
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Intercept the bysquare API call and return a demo QR code image.
 */
add_filter(
	'pre_http_request',
	function ( $preempt, $parsed_args, $url ) {
		if ( false === strpos( $url, 'app.bysquare.com/api/generateQR' ) ) {
			return $preempt;
		}

		// Generate a demo QR code image using GD.
		$size    = 200;
		$modules = 25;
		$module  = (int) floor( $size / $modules );
		$img     = imagecreatetruecolor( $modules * $module, $modules * $module );
		$white   = imagecolorallocate( $img, 255, 255, 255 );
		$black   = imagecolorallocate( $img, 0, 0, 0 );
		imagefill( $img, 0, 0, $white );

		// Draw position markers (the three corner squares of a QR code).
		$markers = [ [ 0, 0 ], [ 0, $modules - 7 ], [ $modules - 7, 0 ] ];
		foreach ( $markers as $pos ) {
			$r = $pos[0];
			$c = $pos[1];
			for ( $i = 0; $i < 7; $i++ ) {
				for ( $j = 0; $j < 7; $j++ ) {
					$fill = ( 0 === $i || 6 === $i || 0 === $j || 6 === $j
						|| ( $i >= 2 && $i <= 4 && $j >= 2 && $j <= 4 ) );
					if ( $fill ) {
						imagefilledrectangle(
							$img,
							( $c + $j ) * $module,
							( $r + $i ) * $module,
							( $c + $j + 1 ) * $module - 1,
							( $r + $i + 1 ) * $module - 1,
							$black
						);
					}
				}
			}
		}

		// Fill data area with a seeded pseudo-random pattern.
		mt_srand( 42 );
		for ( $r = 0; $r < $modules; $r++ ) {
			for ( $c = 0; $c < $modules; $c++ ) {
				// Skip position markers and their separators.
				if ( ( $r < 8 && $c < 8 ) || ( $r < 8 && $c > $modules - 9 ) || ( $r > $modules - 9 && $c < 8 ) ) {
					continue;
				}
				if ( mt_rand( 0, 1 ) ) {
					imagefilledrectangle(
						$img,
						$c * $module,
						$r * $module,
						( $c + 1 ) * $module - 1,
						( $r + 1 ) * $module - 1,
						$black
					);
				}
			}
		}

		ob_start();
		imagepng( $img );
		$png = ob_get_clean();
		imagedestroy( $img );

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$base64 = base64_encode( $png );

		// Build a fake XML response matching the bysquare API format.
		$body = '<ImageSetOfQRCodes>'
			. '<PayBySquare>' . $base64 . '</PayBySquare>'
			. '<QrPlatbaCz>' . $base64 . '</QrPlatbaCz>'
			. '</ImageSetOfQRCodes>';

		return [
			'response' => [
				'code'    => 200,
				'message' => 'OK',
			],
			'body'     => $body,
			'headers'  => [],
			'cookies'  => [],
		];
	},
	10,
	3
);
