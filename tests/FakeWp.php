<?php
/**
 * Tiny WP/WC function stubs for unit tests. Only stubs what the code paths
 * under test actually call — anything else is intentionally left undefined so
 * accidental coupling fails loudly.
 *
 * State lives in $GLOBALS so a test can inspect what the code under test did:
 * captured HTTP requests, written options, logged messages. Call
 * fake_wp_reset() from setUp().
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound

/**
 * Reset every piece of fake state between tests.
 *
 * @param string|null $upload_basedir Directory wp_upload_dir() should report.
 * @return void
 */
function fake_wp_reset( $upload_basedir = null ) {
	$GLOBALS['fake_wp'] = [
		'filters'   => [],
		'options'   => [],
		'log'       => [],
		'requests'  => [],
		'response'  => null,
		'orders'    => [],
		'upload'    => [
			'basedir' => $upload_basedir ?? sys_get_temp_dir(),
			'baseurl' => 'https://example.test/wp-content/uploads',
			'error'   => false,
		],
	];
}

/**
 * Queue the response the next wp_remote_post() should return.
 *
 * @param mixed $response Array response, or a WP_Error instance.
 * @return void
 */
function fake_wp_set_response( $response ) {
	$GLOBALS['fake_wp']['response'] = $response;
}

/**
 * Build an app.bysquare.com-shaped XML response body.
 *
 * @param int                  $code    HTTP status code.
 * @param array<string,string> $nodes   Child nodes as name => text value.
 * @return array{response: array{code: int}, body: string}
 */
function fake_wp_xml_response( $code, array $nodes ) {
	$body = '<?xml version="1.0" encoding="utf-8"?><BySquareXmlResponse>';
	foreach ( $nodes as $name => $value ) {
		$body .= '<' . $name . '>' . $value . '</' . $name . '>';
	}
	$body .= '</BySquareXmlResponse>';
	return [
		'response' => [ 'code' => $code ],
		'body'     => $body,
	];
}

/**
 * Register an order so wc_get_order() can resolve it by ID.
 *
 * @param object $order Order stub exposing get_id().
 * @return object The same order.
 */
function fake_wp_add_order( $order ) {
	$GLOBALS['fake_wp']['orders'][ $order->get_id() ] = $order;
	return $order;
}

/** @return array<int, array{url: string, args: array<string,mixed>}> */
function fake_wp_requests() {
	return $GLOBALS['fake_wp']['requests'];
}

/** @return array<int, array{level: string, message: string, context: array<string,mixed>}> */
function fake_wp_log() {
	return $GLOBALS['fake_wp']['log'];
}

/**
 * The accepted_args a callback was registered with, or false if not hooked.
 *
 * Real WordPress truncates the argument list to this number, so a wrong value
 * is a runtime fatal (too few) or a silently ignored argument (too many).
 *
 * @param string $tag      Hook name.
 * @param mixed  $callback Callback to look for.
 * @return int|false
 */
function fake_wp_accepted_args( $tag, $callback ) {
	foreach ( $GLOBALS['fake_wp']['filters'][ $tag ] ?? [] as $hooks ) {
		foreach ( $hooks as $hook ) {
			if ( $hook['function'] === $callback ) {
				return $hook['accepted_args'];
			}
		}
	}
	return false;
}

/**
 * Concatenated log messages, for substring assertions.
 *
 * @return string
 */
function fake_wp_log_text() {
	return implode( "\n", array_column( $GLOBALS['fake_wp']['log'], 'message' ) );
}

if ( ! class_exists( 'WP_Error' ) ) {
	/** Minimal WP_Error stub: only the message accessor is used. */
	class WP_Error {
		/** @var string */
		private $message;

		public function __construct( $code = '', $message = '' ) {
			$this->message = $message;
		}

		public function get_error_message() {
			return $this->message;
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	/**
	 * Mirrors WP_Hook: a callback is stored once per (tag, priority) and keeps
	 * its accepted_args, which apply_filters()/do_action() honour by slicing.
	 */
	function add_filter( $tag, $callback, $priority = 10, $accepted_args = 1 ) {
		foreach ( $GLOBALS['fake_wp']['filters'][ $tag ][ $priority ] ?? [] as $hook ) {
			if ( $hook['function'] === $callback ) {
				return true;
			}
		}
		$GLOBALS['fake_wp']['filters'][ $tag ][ $priority ][] = [
			'function'      => $callback,
			'accepted_args' => (int) $accepted_args,
		];
		return true;
	}
}

if ( ! function_exists( 'remove_filter' ) ) {
	function remove_filter( $tag, $callback, $priority = 10 ) {
		$removed = false;
		foreach ( $GLOBALS['fake_wp']['filters'][ $tag ][ $priority ] ?? [] as $i => $hook ) {
			if ( $hook['function'] === $callback ) {
				unset( $GLOBALS['fake_wp']['filters'][ $tag ][ $priority ][ $i ] );
				$removed = true;
			}
		}
		return $removed;
	}
}

if ( ! function_exists( 'remove_action' ) ) {
	function remove_action( $tag, $callback, $priority = 10 ) {
		return remove_filter( $tag, $callback, $priority );
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( $tag, $callback, $priority = 10, $accepted_args = 1 ) {
		return add_filter( $tag, $callback, $priority, $accepted_args );
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value, ...$args ) {
		$hooked = $GLOBALS['fake_wp']['filters'][ $tag ] ?? [];
		ksort( $hooked );
		foreach ( $hooked as $hooks ) {
			foreach ( $hooks as $hook ) {
				$all   = array_merge( [ $value ], $args );
				$value = call_user_func_array( $hook['function'], array_slice( $all, 0, $hook['accepted_args'] ) );
			}
		}
		return $value;
	}
}

if ( ! function_exists( 'do_action' ) ) {
	function do_action( $tag, ...$args ) {
		$hooked = $GLOBALS['fake_wp']['filters'][ $tag ] ?? [];
		ksort( $hooked );
		foreach ( $hooked as $hooks ) {
			foreach ( $hooks as $hook ) {
				call_user_func_array( $hook['function'], array_slice( $args, 0, $hook['accepted_args'] ) );
			}
		}
	}
}

if ( ! function_exists( 'has_action' ) ) {
	/**
	 * Returns the priority the callback is hooked at, or false.
	 *
	 * Identity semantics follow real WordPress: an [ $object, 'method' ] pair
	 * only matches the very same instance, so tests must look up the instance
	 * the plugin actually registered.
	 *
	 * @param string $tag      Hook name.
	 * @param mixed  $callback Callback to look for.
	 * @return int|false
	 */
	function has_action( $tag, $callback ) {
		foreach ( $GLOBALS['fake_wp']['filters'][ $tag ] ?? [] as $priority => $hooks ) {
			foreach ( $hooks as $hook ) {
				if ( $hook['function'] === $callback ) {
					return $priority;
				}
			}
		}
		return false;
	}
}

if ( ! function_exists( 'has_filter' ) ) {
	function has_filter( $tag, $callback ) {
		return has_action( $tag, $callback );
	}
}

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) {
		return $text;
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $text, $domain = 'default' ) {
		return $text;
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	// Real esc_html()/esc_attr() run _wp_specialchars() with double_encode
	// off, so an already-encoded entity passes through untouched. Mirror that.
	function esc_html( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8', false );
	}
}

if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8', false );
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, $options = 0, $depth = 512 ) {
		return json_encode( $data, $options, $depth );
	}
}

if ( ! function_exists( 'wp_upload_dir' ) ) {
	function wp_upload_dir() {
		return $GLOBALS['fake_wp']['upload'];
	}
}

if ( ! function_exists( 'wp_mkdir_p' ) ) {
	function wp_mkdir_p( $target ) {
		return is_dir( $target ) || mkdir( $target, 0777, true );
	}
}

if ( ! function_exists( 'wp_remote_post' ) ) {
	function wp_remote_post( $url, $args = [] ) {
		$GLOBALS['fake_wp']['requests'][] = [
			'url'  => $url,
			'args' => $args,
		];
		return $GLOBALS['fake_wp']['response'];
	}
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $option, $default_value = false ) {
		return $GLOBALS['fake_wp']['options'][ $option ] ?? $default_value;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( $option, $value, $autoload = null ) {
		$GLOBALS['fake_wp']['options'][ $option ] = $value;
		return true;
	}
}

if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( $option ) {
		unset( $GLOBALS['fake_wp']['options'][ $option ] );
		return true;
	}
}

if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( $path = '', $scheme = 'admin' ) {
		return 'https://example.test/wp-admin/' . ltrim( (string) $path, '/' );
	}
}

if ( ! function_exists( 'add_query_arg' ) ) {
	function add_query_arg( $args, $url = '' ) {
		return $url . '?' . http_build_query( is_array( $args ) ? $args : [] );
	}
}

if ( ! function_exists( 'plugin_basename' ) ) {
	function plugin_basename( $file ) {
		return basename( dirname( (string) $file ) ) . '/' . basename( (string) $file );
	}
}

if ( ! function_exists( 'load_plugin_textdomain' ) ) {
	function load_plugin_textdomain( $domain, $deprecated = false, $plugin_rel_path = false ) {
		return true;
	}
}

if ( ! function_exists( 'wc_get_order' ) ) {
	function wc_get_order( $order_id = 0 ) {
		if ( is_object( $order_id ) ) {
			return $order_id;
		}
		return $GLOBALS['fake_wp']['orders'][ $order_id ] ?? false;
	}
}

if ( ! function_exists( 'wc_get_logger' ) ) {
	/** Recording logger: Logger::log() funnels every message in here. */
	class Fake_WC_Logger {
		public function log( $level, $message, $context = [] ) {
			$GLOBALS['fake_wp']['log'][] = [
				'level'   => $level,
				'message' => $message,
				'context' => $context,
			];
		}
	}

	function wc_get_logger() {
		return new Fake_WC_Logger();
	}
}
