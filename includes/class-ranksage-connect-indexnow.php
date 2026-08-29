<?php
/**
 * WHAT: Serves the site's IndexNow key file at `/<key>.txt` so RankSage can submit
 *       changed URLs to IndexNow (Bing, Yandex, DuckDuckGo, Naver, Seznam) on the
 *       site owner's behalf without anyone uploading a file by hand.
 * HOW:  On `init` (before the main query runs) the request path is compared to the
 *       stored key with `hash_equals`; a match answers `text/plain` with the key and
 *       exits. The key arrives in the Ed25519-verified connect payload and, after
 *       rotation, in the verified daily config — both already-autoloaded options.
 * WHY:  IndexNow proves ownership by fetching this file. Hosting it from the plugin
 *       is the only way RankSage can cause bytes to be served from a customer origin.
 * NOTE: Keeps both readme promises: nothing executable is written or evaluated, and
 *       ZERO outbound HTTP happens on a front-end request — the key is read from
 *       options the page load already has in memory. The key is NOT a secret (the
 *       IndexNow spec publishes it at this URL by design).
 *
 * @package RankSage_Connect
 */

defined( 'ABSPATH' ) || exit;

/**
 * IndexNow key-file server.
 */
class RankSage_Connect_Indexnow {

	/**
	 * IndexNow keys are 8–128 characters of [A-Za-z0-9-]; RankSage issues 32 hex chars.
	 */
	const KEY_PATTERN = '/^[A-Za-z0-9\-]{8,128}$/';

	/**
	 * Registers the request hook.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'init', array( __CLASS__, 'maybe_serve_key_file' ), 2 );
	}

	/**
	 * Validates a key from any payload; returns '' rather than a malformed value.
	 *
	 * @param mixed $value Candidate key.
	 * @return string
	 */
	public static function sanitize_key( $value ) {
		if ( ! is_string( $value ) ) {
			return '';
		}
		$value = trim( $value );
		return preg_match( self::KEY_PATTERN, $value ) ? $value : '';
	}

	/**
	 * WHAT: The key currently in force — the daily config's copy wins (it reflects a
	 *       rotation within one TTL), else the copy from the connect payload.
	 *
	 * @return string Empty when the site has no key.
	 */
	public static function current_key() {
		$config = RankSage_Connect_Config::get();
		if ( ! empty( $config['indexnow_key'] ) ) {
			$from_config = self::sanitize_key( $config['indexnow_key'] );
			if ( '' !== $from_config ) {
				return $from_config;
			}
		}
		$settings = RankSage_Connect_Settings::get();
		return isset( $settings['indexnow_key'] ) ? self::sanitize_key( $settings['indexnow_key'] ) : '';
	}

	/**
	 * WHAT: The request-path test. Returns in microseconds for ordinary traffic.
	 *
	 * @return void
	 */
	public static function maybe_serve_key_file() {
		if ( is_admin() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : '';
		if ( 'GET' !== $method && 'HEAD' !== $method ) {
			return;
		}

		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$path        = (string) wp_parse_url( $request_uri, PHP_URL_PATH );

		// Subdirectory installs: the key file lives at the WordPress home root.
		$home_path = rtrim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
		if ( '' !== $home_path && 0 === strpos( $path, $home_path ) ) {
			$path = substr( $path, strlen( $home_path ) );
		}

		if ( ! preg_match( '#^/([A-Za-z0-9\-]{8,128})\.txt$#', $path, $matches ) ) {
			return;
		}

		$key = self::current_key();
		if ( '' === $key || ! hash_equals( $key, $matches[1] ) ) {
			return; // Not our file — let WordPress answer as it normally would.
		}

		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'X-Robots-Tag: noindex' );
		header( 'Content-Length: ' . strlen( $key ) );
		if ( 'GET' === $method ) {
			echo $key; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- validated against KEY_PATTERN above.
		}
		exit;
	}
}
