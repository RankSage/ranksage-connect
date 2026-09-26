<?php
/**
 * The ranksage/v1 REST namespace.
 *
 * @package RankSage_Connect
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WHAT: The three routes RankSage uses to configure and inspect this plugin.
 * HOW:  GET /status, POST /connect, POST /disconnect under ranksage/v1, every one of
 *       them gated on current_user_can('manage_options'); /connect additionally
 *       requires a valid Ed25519 signature over the payload.
 * WHY:  /status is the visible-degradation surface — it is how the RankSage dashboard
 *       can say "capture is degraded, WP Rocket detected, 340 hits buffered, last
 *       flush failed 12 minutes ago" instead of showing a green tick that lies.
 * NOTE: manage_options alone would let any site admin point the site at their own
 *       RankSage account, which is fine — it is their site. The SIGNATURE is what
 *       stops a MITM or another plugin repointing it at somebody else's account.
 */
class RankSage_Connect_Rest {

	const NAMESPACE_V1 = 'ranksage/v1';

	/**
	 * Registers the REST routes.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Route registration.
	 *
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			self::NAMESPACE_V1,
			'/status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_status' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/connect',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'post_connect' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
				'args'                => array(
					'payload'   => array(
						'required' => true,
						'type'     => 'string',
					),
					'signature' => array(
						'required' => true,
						'type'     => 'string',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/disconnect',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'post_disconnect' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
			)
		);
	}

	/**
	 * Permission callback for every route in the namespace.
	 *
	 * @return bool
	 */
	public static function can_manage() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * WHAT: The plugin's self-report.
	 *
	 * @return WP_REST_Response
	 */
	public static function get_status() {
		return new WP_REST_Response( self::build_status(), 200 );
	}

	/**
	 * WHAT: Builds the status document shared by REST and the admin screen.
	 *
	 * @return array
	 */
	public static function build_status() {
		$settings         = RankSage_Connect_Settings::get();
		$state            = RankSage_Connect_Settings::get_state();
		$config           = RankSage_Connect_Config::get();
		$cache_layer      = RankSage_Connect_Cache_Detect::summary();
		$overflow_message = RankSage_Connect_Flusher::overflow_message();

		return array(
			'version'          => RANKSAGE_CONNECT_VERSION,
			'connected'        => $settings['connected'],
			'accountLabel'     => $settings['account_label'],
			'connectedAt'      => (int) $settings['connected_at'],
			'siteUrl'          => home_url( '/' ),
			'toggles'          => array(
				'scriptInjection' => $settings['script_enabled'],
				'crawlerCapture'  => $settings['capture_enabled'],
			),
			'cacheLayer'       => $cache_layer,
			// The one field the RankSage dashboard keys its degraded badge off.
			'degraded'         => '' !== $cache_layer || '' !== $state['last_error'] || '' !== $overflow_message,
			'bufferDepth'      => RankSage_Connect_Capture::buffer_depth(),
			'lastFlush'        => (int) $state['last_flush'],
			'lastError'        => $state['last_error'],
			'lastErrorAt'      => (int) $state['last_error_at'],
			// Reported alongside — never instead of — lastError: an overflow is caused BY
			// a delivery failure, so collapsing the two would hide the cause.
			'overflowDropped'  => (int) $state['overflow_dropped'],
			'overflowAt'       => (int) $state['overflow_at'],
			'overflowMessage'  => $overflow_message,
			'retryAfter'       => (int) $state['retry_after'],
			'sentTotal'        => (int) $state['sent_total'],
			'configSource'     => $config['source'],
			'phpVersion'       => PHP_VERSION,
			'wordpressVersion' => get_bloginfo( 'version' ),
		);
	}

	/**
	 * WHAT: Applies a RankSage-signed connect payload.
	 * HOW:  Verify signature → assert the payload names THIS site → store.
	 * WHY:  The site-URL assertion means a payload captured from one site cannot be
	 *       replayed against another.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function post_connect( WP_REST_Request $request ) {
		$verified = RankSage_Connect_Config::verify_payload(
			$request->get_param( 'payload' ),
			$request->get_param( 'signature' )
		);

		if ( is_wp_error( $verified ) ) {
			return new WP_Error( $verified->get_error_code(), $verified->get_error_message(), array( 'status' => 400 ) );
		}

		$expected_host = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		$payload_host  = isset( $verified['siteUrl'] ) ? wp_parse_url( (string) $verified['siteUrl'], PHP_URL_HOST ) : null;

		if ( ! $payload_host || strtolower( (string) $payload_host ) !== strtolower( (string) $expected_host ) ) {
			return new WP_Error(
				'ranksage_site_mismatch',
				__( 'This RankSage configuration was issued for a different site.', 'ranksage-connect' ),
				array( 'status' => 400 )
			);
		}

		if ( empty( $verified['publicTrackingKey'] ) || ! is_string( $verified['publicTrackingKey'] ) ) {
			return new WP_Error( 'ranksage_missing_key', __( 'RankSage configuration did not include a tracking key.', 'ranksage-connect' ), array( 'status' => 400 ) );
		}

		RankSage_Connect_Settings::store_connection(
			sanitize_text_field( $verified['publicTrackingKey'] ),
			isset( $verified['siteToken'] ) ? sanitize_text_field( (string) $verified['siteToken'] ) : '',
			isset( $verified['accountLabel'] ) ? sanitize_text_field( (string) $verified['accountLabel'] ) : '',
			isset( $verified['indexNowKey'] ) ? RankSage_Connect_Indexnow::sanitize_key( $verified['indexNowKey'] ) : ''
		);

		// Config may have changed with the account; refetch immediately.
		delete_option( RANKSAGE_CONNECT_CONFIG_OPTION );
		RankSage_Connect_Flusher::ensure_scheduled();
		RankSage_Connect_Config::ensure_scheduled();
		RankSage_Connect_Config::refresh();

		return new WP_REST_Response( self::build_status(), 200 );
	}

	/**
	 * Clears the connection.
	 *
	 * @return WP_REST_Response
	 */
	public static function post_disconnect() {
		RankSage_Connect_Settings::clear_connection();
		RankSage_Connect_Flusher::ensure_scheduled();

		return new WP_REST_Response( self::build_status(), 200 );
	}
}
