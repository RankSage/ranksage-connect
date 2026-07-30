<?php
/**
 * Signature verification + the remotely-refreshed configuration.
 *
 * @package RankSage_Connect
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WHAT: Verifies RankSage-signed payloads and serves the plugin's live configuration.
 * HOW:  Every RankSage → plugin payload is {payload: "<exact JSON string>", signature:
 *       "<base64 Ed25519>"}. The SIGNED BYTES ARE THE STRING, so neither side depends
 *       on JSON key order. Verified with the compiled-in public key, then decoded.
 * WHY:  wordpress.org holds every plugin release for up to 24 hours before auto-update
 *       propagates ("Protect The Shire", June 2026). Anything that might need to change
 *       inside a day — the AI-crawler UA registry, flush cadence, a kill switch — has to
 *       be server-driven config, not a constant baked into the release.
 * NOTE: Config can change behaviour but NEVER a destination host: sanitize() drops any
 *       absolute URL and keeps only relative paths under the compiled API base.
 */
class RankSage_Connect_Config {

	/** How long a fetched config is trusted before a background refresh is scheduled. */
	const CACHE_TTL = DAY_IN_SECONDS;

	/** How long to wait before retrying after a failed fetch. */
	const RETRY_TTL = HOUR_IN_SECONDS;

	/** Payloads older than this are rejected as replays. */
	const MAX_PAYLOAD_AGE = 900;

	/** In-request memo so repeated get() calls do no repeated work. */
	private static $memo = null;

	/**
	 * Registers the daily config refresh.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( RANKSAGE_CONNECT_CONFIG_HOOK, array( __CLASS__, 'refresh' ) );
		add_action( 'init', array( __CLASS__, 'ensure_scheduled' ), 20 );
	}

	/**
	 * WHAT: Keeps the daily refresh scheduled only while connected.
	 * WHY:  An unconnected install must make no outbound request at all — that is the
	 *       wordpress.org guideline 7 consent model.
	 *
	 * @return void
	 */
	public static function ensure_scheduled() {
		$connected = RankSage_Connect_Settings::get()['connected'];
		$scheduled = wp_next_scheduled( RANKSAGE_CONNECT_CONFIG_HOOK );

		if ( $connected && ! $scheduled ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'daily', RANKSAGE_CONNECT_CONFIG_HOOK );
		} elseif ( ! $connected && $scheduled ) {
			wp_unschedule_event( $scheduled, RANKSAGE_CONNECT_CONFIG_HOOK );
		}
	}

	/**
	 * WHAT: Verifies a detached Ed25519 signature over a payload string.
	 * HOW:  sodium_crypto_sign_verify_detached — ext-sodium is core since PHP 7.2, and
	 *       this plugin requires PHP 7.4.
	 * WHY:  This one function is the entire trust boundary between RankSage and the
	 *       customer's site. If it returns false, nothing downstream runs.
	 *
	 * @param string $payload_json  Exact payload string as signed.
	 * @param string $signature_b64 Base64 detached signature.
	 * @return array|WP_Error Decoded payload on success.
	 */
	public static function verify_payload( $payload_json, $signature_b64 ) {
		if ( ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
			return new WP_Error( 'ranksage_no_sodium', __( 'The PHP sodium extension is unavailable, so RankSage cannot verify its signed configuration.', 'ranksage-connect' ) );
		}
		if ( ! is_string( $payload_json ) || ! is_string( $signature_b64 ) || '' === $payload_json || '' === $signature_b64 ) {
			return new WP_Error( 'ranksage_bad_payload', __( 'Malformed RankSage payload.', 'ranksage-connect' ) );
		}

		$signature = base64_decode( $signature_b64, true );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- hex2bin() emits a warning on malformed input; the false return is handled immediately below.
		$public_key = @hex2bin( RANKSAGE_CONNECT_SIGNING_PUBLIC_KEY );

		if ( false === $signature || false === $public_key || SODIUM_CRYPTO_SIGN_BYTES !== strlen( $signature ) ) {
			return new WP_Error( 'ranksage_bad_signature', __( 'The RankSage payload signature is malformed.', 'ranksage-connect' ) );
		}

		if ( ! sodium_crypto_sign_verify_detached( $signature, $payload_json, $public_key ) ) {
			return new WP_Error( 'ranksage_signature_failed', __( 'The RankSage payload signature did not verify.', 'ranksage-connect' ) );
		}

		$decoded = json_decode( $payload_json, true );
		if ( ! is_array( $decoded ) ) {
			return new WP_Error( 'ranksage_bad_json', __( 'The RankSage payload is not valid JSON.', 'ranksage-connect' ) );
		}

		// Replay window. issuedAt is seconds since epoch, written by RankSage.
		$issued_at = isset( $decoded['issuedAt'] ) ? (int) $decoded['issuedAt'] : 0;
		if ( $issued_at <= 0 || abs( time() - $issued_at ) > self::MAX_PAYLOAD_AGE ) {
			return new WP_Error( 'ranksage_stale_payload', __( 'The RankSage payload is outside the accepted time window.', 'ranksage-connect' ) );
		}

		return $decoded;
	}

	/**
	 * WHAT: Returns the live configuration — remote when available, compiled otherwise.
	 * HOW:  Reads an AUTOLOADED option, so the AI-crawler capture hook can consult the
	 *       user-agent registry on every front-end request at zero query cost. A daily
	 *       cron event refreshes it; get() never performs I/O.
	 * WHY:  A transient with an expiry is stored non-autoloaded, which would add a DB
	 *       query to every pageview — unacceptable for code that runs at init priority 1.
	 *       This is the same "cached for 24h, falls back to compiled defaults" contract,
	 *       moved off the request path. Deviation from the research's literal "transient"
	 *       wording, for the performance reason the same research section demands.
	 *
	 * @return array
	 */
	public static function get() {
		if ( null !== self::$memo ) {
			return self::$memo;
		}

		$stored = get_option( RANKSAGE_CONNECT_CONFIG_OPTION, array() );
		$config = is_array( $stored ) && ! empty( $stored['values'] ) && is_array( $stored['values'] )
			? array_merge( self::defaults(), $stored['values'] )
			: self::defaults();

		self::$memo = $config;
		return $config;
	}

	/**
	 * WHAT: Fetches, verifies and stores the remote configuration. Cron entry point.
	 * WHY:  Any failure keeps the compiled defaults AND records the reason in the
	 *       plugin's status surface — a degraded config source is always visible,
	 *       never silently assumed current.
	 *
	 * @return void
	 */
	public static function refresh() {
		$stored = get_option( RANKSAGE_CONNECT_CONFIG_OPTION, array() );
		$next   = is_array( $stored ) && isset( $stored['next_attempt'] ) ? (int) $stored['next_attempt'] : 0;
		if ( $next > time() ) {
			return;
		}

		$fetched = self::fetch_remote();

		if ( is_wp_error( $fetched ) ) {
			update_option(
				RANKSAGE_CONNECT_CONFIG_OPTION,
				array(
					'values'       => is_array( $stored ) && isset( $stored['values'] ) ? $stored['values'] : array(),
					'next_attempt' => time() + self::RETRY_TTL,
				),
				true
			);
			RankSage_Connect_Settings::update_state(
				array(
					'last_error'    => 'config: ' . $fetched->get_error_message(),
					'last_error_at' => time(),
				)
			);
			return;
		}

		update_option(
			RANKSAGE_CONNECT_CONFIG_OPTION,
			array(
				'values'       => $fetched,
				'next_attempt' => time() + self::CACHE_TTL,
			),
			true
		);
		self::$memo = null;
	}

	/**
	 * WHAT: The compiled-in configuration, used before first contact and on any failure.
	 * NOTE: ua_tokens mirrors the RankSage edge-snippet prefilter. It only has to avoid
	 *       forwarding obvious non-bots — RankSage re-classifies every user-agent against
	 *       the canonical registry server-side and drops anything unrecognised.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'ua_tokens'        => array(
				'gptbot',
				'oai-searchbot',
				'chatgpt-user',
				'claudebot',
				'claude-searchbot',
				'claude-user',
				'perplexitybot',
				'perplexity-user',
				'google-extended',
				'googleother',
				'bytespider',
				'amazonbot',
				'applebot-extended',
				'ccbot',
				'meta-externalagent',
				'meta-externalfetcher',
				'duckassistbot',
				'youbot',
				'cohere-ai',
				'mistralai-user',
			),
			'bot_hits_path'    => '/api/v1/tracking/bot-hits',
			'flush_batch_size' => 200,
			'buffer_threshold' => 200,
			'buffer_max_rows'  => 5000,
			'capture_enabled'  => true,
			'source'           => 'compiled',
		);
	}

	/**
	 * Fetches and verifies the remote config.
	 *
	 * @return array|WP_Error
	 */
	private static function fetch_remote() {
		$response = wp_remote_get(
			RANKSAGE_CONNECT_API_BASE . '/api/v1/wordpress/plugin-config',
			array(
				'timeout'    => 10,
				'user-agent' => self::user_agent(),
				'headers'    => array( 'Accept' => 'application/json' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status ) {
			/* translators: %d: HTTP status code returned by RankSage. */
			return new WP_Error( 'ranksage_config_http', sprintf( __( 'RankSage returned HTTP %d for the plugin configuration.', 'ranksage-connect' ), $status ) );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$data = isset( $body['data'] ) && is_array( $body['data'] ) ? $body['data'] : $body;

		if ( ! is_array( $data ) || ! isset( $data['payload'], $data['signature'] ) ) {
			return new WP_Error( 'ranksage_config_shape', __( 'Unexpected configuration response from RankSage.', 'ranksage-connect' ) );
		}

		$verified = self::verify_payload( $data['payload'], $data['signature'] );
		if ( is_wp_error( $verified ) ) {
			return $verified;
		}

		return self::sanitize( $verified );
	}

	/**
	 * WHAT: Reduces a verified config payload to the fields the plugin will honour.
	 * WHY:  Defence in depth. Even a correctly-signed payload may only adjust behaviour
	 *       within bounds — it can never introduce a hostname, a URL, or executable
	 *       content. Nothing here is ever eval'd, included, or written to disk.
	 *
	 * @param array $payload Verified payload.
	 * @return array
	 */
	private static function sanitize( array $payload ) {
		$config = array( 'source' => 'remote' );

		if ( isset( $payload['uaTokens'] ) && is_array( $payload['uaTokens'] ) ) {
			$tokens = array();
			foreach ( $payload['uaTokens'] as $token ) {
				if ( is_string( $token ) && preg_match( '/^[a-z0-9._\- ]{2,64}$/', $token ) ) {
					$tokens[] = $token;
				}
			}
			if ( ! empty( $tokens ) ) {
				$config['ua_tokens'] = array_slice( $tokens, 0, 128 );
			}
		}

		// Path only — a leading slash and no scheme/host. An absolute URL is dropped,
		// which is what keeps the destination pinned to the compiled API base.
		if ( isset( $payload['botHitsPath'] ) && is_string( $payload['botHitsPath'] )
			&& preg_match( '#^/[A-Za-z0-9/_\-]{1,128}$#', $payload['botHitsPath'] ) ) {
			$config['bot_hits_path'] = $payload['botHitsPath'];
		}

		$bounds = array(
			'flushBatchSize'  => array( 'flush_batch_size', 1, 500 ),
			'bufferThreshold' => array( 'buffer_threshold', 1, 5000 ),
			'bufferMaxRows'   => array( 'buffer_max_rows', 100, 100000 ),
		);
		foreach ( $bounds as $remote_key => $spec ) {
			if ( isset( $payload[ $remote_key ] ) && is_numeric( $payload[ $remote_key ] ) ) {
				$config[ $spec[0] ] = max( $spec[1], min( $spec[2], (int) $payload[ $remote_key ] ) );
			}
		}

		// Kill switch: RankSage can stop all capture within one config TTL without a
		// plugin release. It can only ever turn capture OFF, never force it on.
		if ( isset( $payload['captureEnabled'] ) && false === $payload['captureEnabled'] ) {
			$config['capture_enabled'] = false;
		}

		return $config;
	}

	/**
	 * Identifying user-agent for every outbound RankSage call.
	 *
	 * @return string
	 */
	public static function user_agent() {
		return 'RankSageConnect/' . RANKSAGE_CONNECT_VERSION . '; ' . home_url( '/' );
	}
}
