<?php
/**
 * AI-crawler capture — the only code this plugin runs on a front-end request.
 *
 * @package RankSage_Connect
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WHAT: Records AI-crawler page fetches into a local buffer table.
 * HOW:  init priority 1 → one in-memory substring pass over the user-agent → miss
 *       returns immediately → hit registers a `shutdown` callback that writes ONE row.
 * WHY:  AI crawlers fetch HTML without executing JavaScript, so a browser beacon can
 *       never see them and GA4 has the same blind spot. A server-side record is the
 *       only way this data exists at all.
 * NOTE: There is deliberately ZERO outbound HTTP on the request path. The commonly
 *       recommended `wp_remote_post(..., ['blocking' => false, 'timeout' => 0.01])`
 *       fire-and-forget pattern still pays the DNS phase — up to a full second added
 *       to TTFB on a system-resolver libcurl build (core.trac #63547, #18738) — and a
 *       per-request outbound call is exactly what gets a plugin onto managed-host
 *       disallow lists. Human traffic here pays one lowercase + one substring loop.
 */
class RankSage_Connect_Capture {

	/** Longest path/user-agent we store; matches the RankSage ingest caps. */
	const MAX_FIELD_LENGTH = 512;

	/**
	 * Registers the capture hook.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'init', array( __CLASS__, 'maybe_capture' ), 1 );
	}

	/**
	 * Fully-qualified buffer table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'ranksage_bot_hits';
	}

	/**
	 * WHAT: The request-path test. Returns in microseconds for ordinary traffic.
	 *
	 * @return void
	 */
	public static function maybe_capture() {
		// Admin, cron and REST requests are not crawler page views.
		if ( is_admin() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		$settings = RankSage_Connect_Settings::get();
		if ( ! $settings['connected'] || ! $settings['capture_enabled'] ) {
			return;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- read-only substring test; the value is sanitized before storage in record().
		$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : '';
		if ( '' === $user_agent ) {
			return;
		}

		$config = RankSage_Connect_Config::get();
		if ( empty( $config['capture_enabled'] ) ) {
			return; // Server-side kill switch.
		}

		$haystack = strtolower( $user_agent );
		$matched  = false;
		foreach ( $config['ua_tokens'] as $token ) {
			if ( false !== strpos( $haystack, $token ) ) {
				$matched = true;
				break;
			}
		}
		if ( ! $matched ) {
			return;
		}

		// Deferred to `shutdown` so the INSERT happens after the response is sent —
		// on FPM this is after fastcgi_finish_request(), so the crawler waits for nothing.
		add_action(
			'shutdown',
			function () use ( $user_agent ) {
				RankSage_Connect_Capture::record( $user_agent );
			}
		);
	}

	/**
	 * WHAT: Writes one buffered hit and, at the threshold, asks for an immediate flush.
	 * HOW:  $wpdb->insert (which prepares internally) with explicit format specifiers.
	 * WHY:  Runs on `shutdown`, never inline — a tracking write must never be able to
	 *       slow, or fail, a visitor's response.
	 *
	 * @param string $user_agent Raw user-agent of the matched request.
	 * @return void
	 */
	public static function record( $user_agent ) {
		global $wpdb;

		$path = self::current_path();
		if ( '' === $path ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- dedicated buffer table; caching a write buffer is meaningless.
		$inserted = $wpdb->insert(
			self::table(),
			array(
				'path'   => substr( $path, 0, self::MAX_FIELD_LENGTH ),
				'ua'     => substr( sanitize_text_field( $user_agent ), 0, self::MAX_FIELD_LENGTH ),
				'hit_ts' => time(),
			),
			array( '%s', '%s', '%d' )
		);

		if ( false === $inserted ) {
			RankSage_Connect_Settings::update_state(
				array(
					'last_error'    => __( 'Could not save an AI-crawler visit to the local buffer table.', 'ranksage-connect' ),
					'last_error_at' => time(),
				)
			);
			return;
		}

		/**
		 * WHAT: Ask for an immediate drain once the buffer crosses its threshold.
		 * HOW:  The cheap cron lookup runs FIRST so the COUNT(*) is skipped entirely
		 *       whenever a drain is already queued.
		 * WHY:  This valve was dead before: it tested RANKSAGE_CONNECT_FLUSH_HOOK, which
		 *       always has a next occurrence because ensure_scheduled() keeps a recurring
		 *       event on it. It now tests the dedicated one-off hook, so it actually fires.
		 * NOTE: The COUNT(*) runs on `shutdown` and only for requests already identified
		 *       as AI-crawler hits — never on the human request path.
		 */
		if ( wp_next_scheduled( RANKSAGE_CONNECT_FLUSH_NOW_HOOK ) ) {
			return;
		}
		$config = RankSage_Connect_Config::get();
		if ( self::buffer_depth() >= (int) $config['buffer_threshold'] ) {
			RankSage_Connect_Flusher::request_immediate_flush( 0 );
		}
	}

	/**
	 * WHAT: The request path, query string and host stripped.
	 * WHY:  RankSage stores a bare path per (date, path, bot). Query strings can carry
	 *       personal data and are dropped here, at the source, not server-side.
	 *
	 * @return string
	 */
	private static function current_path() {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- split and sanitized on the following lines.
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		if ( '' === $request_uri ) {
			return '';
		}

		$path = strtok( $request_uri, '?' );
		$path = false === $path ? '' : strtok( $path, '#' );
		$path = false === $path ? '' : sanitize_text_field( $path );

		if ( '' === $path ) {
			return '';
		}
		if ( '/' !== substr( $path, 0, 1 ) ) {
			$path = '/' . $path;
		}
		if ( strlen( $path ) > 1 && '/' === substr( $path, -1 ) ) {
			$path = substr( $path, 0, -1 );
		}

		return $path;
	}

	/**
	 * Current number of buffered hits.
	 *
	 * @return int
	 */
	public static function buffer_depth() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- live depth of a write buffer; caching it would defeat the threshold check.
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', self::table() ) );
	}
}
