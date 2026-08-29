<?php
/**
 * Settings → RankSage admin screen and the Direction-B connect flow.
 *
 * @package RankSage_Connect
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WHAT: The wp-admin surface: connect, per-capability status, two toggles, disconnect.
 * HOW:  A plain WordPress settings page. All CSS is bundled locally; there is no
 *       JavaScript and no external asset of any kind.
 * WHY:  wordpress.org guideline 8 forbids loading admin assets from a CDN. Plain
 *       core-styled admin markup also survives every WP release without maintenance,
 *       which matters when every fix ships through a 24-hour release cooldown.
 */
class RankSage_Connect_Admin {

	const PAGE_SLUG = 'ranksage-connect';

	/**
	 * Registers the admin hooks.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_actions' ) );
		add_action( 'admin_notices', array( __CLASS__, 'maybe_cache_notice' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( RANKSAGE_CONNECT_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/**
	 * Adds Settings → RankSage.
	 *
	 * @return void
	 */
	public static function add_menu() {
		add_options_page(
			__( 'RankSage Connect', 'ranksage-connect' ),
			__( 'RankSage', 'ranksage-connect' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Adds a Settings shortcut on the Plugins screen.
	 *
	 * @param string[] $links Existing action links.
	 * @return string[]
	 */
	public static function action_links( $links ) {
		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'options-general.php?page=' . self::PAGE_SLUG ) ),
			esc_html__( 'Settings', 'ranksage-connect' )
		);
		array_unshift( $links, $settings_link );
		return $links;
	}

	/**
	 * Loads the local admin stylesheet on our page only.
	 *
	 * @param string $hook_suffix Current admin page.
	 * @return void
	 */
	public static function enqueue_assets( $hook_suffix ) {
		if ( 'settings_page_' . self::PAGE_SLUG !== $hook_suffix ) {
			return;
		}
		wp_enqueue_style(
			'ranksage-connect-admin',
			RANKSAGE_CONNECT_URL . 'admin/css/ranksage-connect-admin.css',
			array(),
			RANKSAGE_CONNECT_VERSION
		);
	}

	/**
	 * WHAT: Handles the three admin POST/GET actions: toggles, disconnect, code return.
	 * HOW:  Capability check + nonce check on every branch, then redirect back to the
	 *       settings page so a refresh cannot resubmit.
	 *
	 * @return void
	 */
	public static function handle_actions() {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// --- Direction B return leg: RankSage sent us a single-use code. -----------
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the nonce IS the value verified three lines down.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- see above.
		if ( self::PAGE_SLUG === $page && isset( $_GET['ranksage_code'], $_GET['ranksage_nonce'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- see above.
			$nonce = sanitize_text_field( wp_unslash( $_GET['ranksage_nonce'] ) );
			if ( wp_verify_nonce( $nonce, 'ranksage_connect_start' ) ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified on the line above.
				$code   = sanitize_text_field( wp_unslash( $_GET['ranksage_code'] ) );
				$result = self::exchange_code( $code );
				self::redirect_with_notice(
					is_wp_error( $result ) ? 'error' : 'connected',
					is_wp_error( $result ) ? self::detail_key_for( $result ) : ''
				);
			}
			self::redirect_with_notice( 'error', 'link_expired' );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- routed on, then nonce-verified inside each branch via check_admin_referer().
		$action = isset( $_POST['ranksage_action'] ) ? sanitize_key( wp_unslash( $_POST['ranksage_action'] ) ) : '';

		// --- Toggles ---------------------------------------------------------------
		if ( 'save_toggles' === $action ) {
			check_admin_referer( 'ranksage_connect_toggles' );
			RankSage_Connect_Settings::update(
				array(
					'script_enabled'  => isset( $_POST['ranksage_script_enabled'] ),
					'capture_enabled' => isset( $_POST['ranksage_capture_enabled'] ),
				)
			);
			RankSage_Connect_Flusher::ensure_scheduled();
			self::redirect_with_notice( 'saved', '' );
		}

		// --- Disconnect ------------------------------------------------------------
		if ( 'disconnect' === $action ) {
			check_admin_referer( 'ranksage_connect_disconnect' );
			RankSage_Connect_Settings::clear_connection();
			RankSage_Connect_Flusher::ensure_scheduled();
			RankSage_Connect_Config::ensure_scheduled();
			self::redirect_with_notice( 'disconnected', '' );
		}
	}

	/**
	 * WHAT: Exchanges a single-use RankSage code for this site's signed configuration.
	 * HOW:  Server-side POST from the site to RankSage (never a browser redirect
	 *       carrying a credential), then the same Ed25519 verification as /connect.
	 * WHY:  This is the Direction-B path: no WordPress application password is ever
	 *       created and no admin credential leaves the site.
	 *
	 * @param string $code Single-use code from the RankSage redirect.
	 * @return true|WP_Error
	 */
	private static function exchange_code( $code ) {
		$response = wp_remote_post(
			RANKSAGE_CONNECT_API_BASE . '/api/v1/wordpress/wp-connect/exchange',
			array(
				'timeout'    => 15,
				'user-agent' => RankSage_Connect_Config::user_agent(),
				'headers'    => array( 'Content-Type' => 'application/json' ),
				'body'       => wp_json_encode(
					array(
						'code'          => $code,
						'siteUrl'       => home_url( '/' ),
						'pluginVersion' => RANKSAGE_CONNECT_VERSION,
						'cacheLayer'    => RankSage_Connect_Cache_Detect::summary(),
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return new WP_Error(
				'ranksage_exchange_failed',
				__( 'RankSage could not confirm this connection. Please try again from your RankSage dashboard.', 'ranksage-connect' )
			);
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$data = isset( $body['data'] ) && is_array( $body['data'] ) ? $body['data'] : $body;

		if ( ! is_array( $data ) || ! isset( $data['payload'], $data['signature'] ) ) {
			return new WP_Error( 'ranksage_exchange_shape', __( 'Unexpected response from RankSage.', 'ranksage-connect' ) );
		}

		$verified = RankSage_Connect_Config::verify_payload( $data['payload'], $data['signature'] );
		if ( is_wp_error( $verified ) ) {
			return $verified;
		}

		$expected_host = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		$payload_host  = isset( $verified['siteUrl'] ) ? wp_parse_url( (string) $verified['siteUrl'], PHP_URL_HOST ) : null;
		if ( ! $payload_host || strtolower( (string) $payload_host ) !== strtolower( (string) $expected_host ) ) {
			return new WP_Error( 'ranksage_site_mismatch', __( 'This RankSage configuration was issued for a different site.', 'ranksage-connect' ) );
		}
		if ( empty( $verified['publicTrackingKey'] ) ) {
			return new WP_Error( 'ranksage_missing_key', __( 'RankSage configuration did not include a tracking key.', 'ranksage-connect' ) );
		}

		RankSage_Connect_Settings::store_connection(
			sanitize_text_field( (string) $verified['publicTrackingKey'] ),
			isset( $verified['siteToken'] ) ? sanitize_text_field( (string) $verified['siteToken'] ) : '',
			isset( $verified['accountLabel'] ) ? sanitize_text_field( (string) $verified['accountLabel'] ) : '',
			isset( $verified['indexNowKey'] ) ? RankSage_Connect_Indexnow::sanitize_key( $verified['indexNowKey'] ) : ''
		);
		delete_option( RANKSAGE_CONNECT_CONFIG_OPTION );
		RankSage_Connect_Flusher::ensure_scheduled();
		RankSage_Connect_Config::ensure_scheduled();
		RankSage_Connect_Config::refresh();

		return true;
	}

	/**
	 * WHAT: Redirects back to the settings page carrying a notice key and a detail KEY.
	 * HOW:  Both values are opaque slugs looked up in fixed maps at render time — no
	 *       free text ever travels in the URL.
	 * WHY:  The detail used to be the raw error message, rendered verbatim (escaped)
	 *       inside a branded RankSage notice. Escaped prose is not XSS, but an emailed
	 *       link could still put an attacker's sentence — "RankSage: your billing failed,
	 *       call this number" — into a notice the admin trusts because we drew it.
	 *
	 * @param string $notice     Notice key, resolved against the map in render_notice().
	 * @param string $detail_key Optional detail key, resolved against DETAIL_MESSAGES.
	 * @return void
	 */
	private static function redirect_with_notice( $notice, $detail_key ) {
		$url = add_query_arg(
			array(
				'page'            => self::PAGE_SLUG,
				'ranksage_notice' => rawurlencode( $notice ),
				'ranksage_detail' => rawurlencode( $detail_key ),
			),
			admin_url( 'options-general.php' )
		);
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * WHAT: The only detail sentences this plugin will ever render in its own notice.
	 * WHY:  A closed set means the notice text cannot be authored by whoever crafted the
	 *       URL — the key selects one of ours, or nothing is shown.
	 *
	 * @return array<string,string>
	 */
	private static function detail_messages() {
		return array(
			'link_expired'               => __( 'The connection link expired. Please click Connect again.', 'ranksage-connect' ),
			'ranksage_exchange_failed'   => __( 'RankSage could not confirm this connection. Please try again from your RankSage dashboard.', 'ranksage-connect' ),
			'ranksage_exchange_shape'    => __( 'RankSage returned a response this plugin did not understand.', 'ranksage-connect' ),
			'ranksage_site_mismatch'     => __( 'That configuration was issued for a different site.', 'ranksage-connect' ),
			'ranksage_missing_key'       => __( 'RankSage did not return a tracking key.', 'ranksage-connect' ),
			'ranksage_no_sodium'         => __( 'The PHP sodium extension is unavailable, so the signed configuration cannot be verified.', 'ranksage-connect' ),
			'ranksage_bad_payload'       => __( 'The RankSage payload was malformed.', 'ranksage-connect' ),
			'ranksage_bad_signature'     => __( 'The RankSage payload signature was malformed.', 'ranksage-connect' ),
			'ranksage_signature_failed'  => __( 'The RankSage payload signature did not verify.', 'ranksage-connect' ),
			'ranksage_bad_json'          => __( 'The RankSage payload was not valid JSON.', 'ranksage-connect' ),
			'ranksage_stale_payload'     => __( 'The RankSage payload was outside the accepted time window. Check this server\'s clock.', 'ranksage-connect' ),
			'ranksage_unreachable'       => __( 'RankSage could not be reached from this server.', 'ranksage-connect' ),
		);
	}

	/**
	 * WHAT: Site-wide admin notice when a page cache is degrading crawler capture.
	 * WHY:  A degraded capability the user never sees is the same as a silent failure.
	 *
	 * @return void
	 */
	public static function maybe_cache_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$settings = RankSage_Connect_Settings::get();
		if ( ! $settings['connected'] || ! $settings['capture_enabled'] ) {
			return;
		}
		$cache_layer = RankSage_Connect_Cache_Detect::summary();
		if ( '' === $cache_layer ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p><strong>%s</strong> %s <a href="%s">%s</a></p></div>',
			esc_html__( 'RankSage:', 'ranksage-connect' ),
			esc_html( RankSage_Connect_Cache_Detect::coverage_message( $cache_layer ) ),
			esc_url( admin_url( 'options-general.php?page=' . self::PAGE_SLUG ) ),
			esc_html__( 'How to fix', 'ranksage-connect' )
		);
	}

	/**
	 * Renders the settings page.
	 *
	 * @return void
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$status   = RankSage_Connect_Rest::build_status();
		$settings = RankSage_Connect_Settings::get();

		echo '<div class="wrap ranksage-connect-wrap">';
		echo '<h1>' . esc_html__( 'RankSage Connect', 'ranksage-connect' ) . '</h1>';

		self::render_notice();

		if ( ! $status['connected'] ) {
			self::render_connect_cta();
		} else {
			self::render_status_table( $status );
			self::render_toggles( $settings );
			self::render_disconnect();
		}

		echo '<p class="description">';
		printf(
			/* translators: 1: terms of service URL, 2: privacy policy URL. */
			wp_kses(
				__( 'RankSage is an external service. <a href="%1$s" target="_blank" rel="noopener noreferrer">Terms of Service</a> · <a href="%2$s" target="_blank" rel="noopener noreferrer">Privacy Policy</a>', 'ranksage-connect' ),
				array( 'a' => array( 'href' => array(), 'target' => array(), 'rel' => array() ) )
			),
			'https://ranksage.com/terms',
			'https://ranksage.com/privacy'
		);
		echo '</p></div>';
	}

	/**
	 * WHAT: Maps a WP_Error onto one of the closed set of detail keys.
	 * WHY:  Every error this plugin raises itself is in the map; anything else can only
	 *       have come from wp_remote_* transport failure, which IS "could not reach".
	 *
	 * @param WP_Error $error Failure to classify.
	 * @return string
	 */
	private static function detail_key_for( WP_Error $error ) {
		$code = $error->get_error_code();
		return isset( self::detail_messages()[ $code ] ) ? $code : 'ranksage_unreachable';
	}

	/**
	 * WHAT: Renders the redirect-carried notice.
	 * NOTE: Both the notice and the detail are KEYS into fixed maps. An unrecognised key
	 *       renders nothing rather than itself, so no URL-supplied prose can appear.
	 *
	 * @return void
	 */
	private static function render_notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only notice keyed off a redirect this plugin issued; no state change.
		if ( ! isset( $_GET['ranksage_notice'] ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- see above.
		$notice = sanitize_key( wp_unslash( $_GET['ranksage_notice'] ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- see above.
		$detail_key = isset( $_GET['ranksage_detail'] ) ? sanitize_key( wp_unslash( $_GET['ranksage_detail'] ) ) : '';

		$details = self::detail_messages();
		$detail  = isset( $details[ $detail_key ] ) ? $details[ $detail_key ] : '';

		$map = array(
			'connected'    => array( 'success', __( 'Connected to RankSage.', 'ranksage-connect' ) ),
			'disconnected' => array( 'success', __( 'Disconnected from RankSage. Nothing is sent to RankSage any more.', 'ranksage-connect' ) ),
			'saved'        => array( 'success', __( 'Settings saved.', 'ranksage-connect' ) ),
			'error'        => array( 'error', __( 'Could not connect to RankSage.', 'ranksage-connect' ) ),
		);

		if ( ! isset( $map[ $notice ] ) ) {
			return;
		}

		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s %3$s</p></div>',
			esc_attr( $map[ $notice ][0] ),
			esc_html( $map[ $notice ][1] ),
			esc_html( $detail )
		);
	}

	/**
	 * WHAT: The "Connect to RankSage" call to action (Direction B, outbound leg).
	 * HOW:  Links to the RankSage app with this site's URL, a WordPress nonce and the
	 *       admin return URL. RankSage signs the user in, binds the site to their
	 *       verified domain, and redirects back with a single-use code.
	 *
	 * @return void
	 */
	private static function render_connect_cta() {
		$connect_url = add_query_arg(
			array(
				'site'   => rawurlencode( home_url( '/' ) ),
				'nonce'  => rawurlencode( wp_create_nonce( 'ranksage_connect_start' ) ),
				'return' => rawurlencode( admin_url( 'options-general.php?page=' . self::PAGE_SLUG ) ),
			),
			RANKSAGE_CONNECT_APP_BASE . '/wp-connect'
		);

		echo '<div class="card ranksage-card">';
		echo '<h2>' . esc_html__( 'Connect this site', 'ranksage-connect' ) . '</h2>';
		echo '<p>' . esc_html__( 'RankSage Connect sends nothing anywhere until you connect an account. Once connected it does exactly two things: it adds the RankSage tracking tag to your pages, and it reports AI-crawler visits (user-agent, path and timestamp only) to your RankSage account. Both can be turned off independently below.', 'ranksage-connect' ) . '</p>';
		printf(
			'<p><a class="button button-primary" href="%s">%s</a></p>',
			esc_url( $connect_url ),
			esc_html__( 'Connect to RankSage', 'ranksage-connect' )
		);
		echo '</div>';
	}

	/**
	 * Renders the per-capability status table.
	 *
	 * @param array $status Output of RankSage_Connect_Rest::build_status().
	 * @return void
	 */
	private static function render_status_table( array $status ) {
		$cache_layer = $status['cacheLayer'];

		$rows = array(
			array(
				__( 'Tracking script', 'ranksage-connect' ),
				$status['toggles']['scriptInjection'] ? __( 'Active', 'ranksage-connect' ) : __( 'Turned off', 'ranksage-connect' ),
				$status['toggles']['scriptInjection'] ? '' : __( 'Enable "Add the RankSage tracking tag" below.', 'ranksage-connect' ),
			),
			array(
				__( 'AI-crawler capture', 'ranksage-connect' ),
				self::capture_state_label( $status ),
				'' !== $cache_layer ? RankSage_Connect_Cache_Detect::coverage_message( $cache_layer ) : '',
			),
			array(
				__( 'Buffered hits waiting to send', 'ranksage-connect' ),
				(string) (int) $status['bufferDepth'],
				'',
			),
			array(
				__( 'Last successful send', 'ranksage-connect' ),
				$status['lastFlush'] > 0
					// translators: %s: human-readable time difference, e.g. "5 mins".
					? sprintf( __( '%s ago', 'ranksage-connect' ), human_time_diff( $status['lastFlush'] ) )
					: __( 'Never', 'ranksage-connect' ),
				'',
			),
			array(
				__( 'Configuration', 'ranksage-connect' ),
				'remote' === $status['configSource']
					? __( 'Live from RankSage', 'ranksage-connect' )
					: __( 'Built-in defaults (RankSage not reachable)', 'ranksage-connect' ),
				'',
			),
			array(
				__( 'Last error', 'ranksage-connect' ),
				'' === $status['lastError'] ? __( 'None', 'ranksage-connect' ) : $status['lastError'],
				'',
			),
		);

		// Reported as its own row rather than folded into "Last error", so the delivery
		// failure that caused the overflow stays visible next to its consequence.
		if ( '' !== $status['overflowMessage'] ) {
			$rows[] = array(
				__( 'Dropped hits', 'ranksage-connect' ),
				(string) (int) $status['overflowDropped'],
				$status['overflowMessage'],
			);
		}

		echo '<table class="widefat striped ranksage-status"><tbody>';
		foreach ( $rows as $row ) {
			echo '<tr><th scope="row">' . esc_html( $row[0] ) . '</th><td>' . esc_html( $row[1] ) . '</td><td class="description">' . esc_html( $row[2] ) . '</td></tr>';
		}
		echo '</tbody></table>';

		if ( '' !== $cache_layer ) {
			echo '<h3>' . esc_html__( 'Restore full AI-crawler coverage', 'ranksage-connect' ) . '</h3><ol>';
			foreach ( RankSage_Connect_Cache_Detect::exclusion_instructions( $cache_layer ) as $instruction ) {
				echo '<li>' . esc_html( $instruction ) . '</li>';
			}
			echo '</ol>';
		}
	}

	/**
	 * Human label for the crawler-capture capability.
	 *
	 * @param array $status Status document.
	 * @return string
	 */
	private static function capture_state_label( array $status ) {
		if ( ! $status['toggles']['crawlerCapture'] ) {
			return __( 'Turned off', 'ranksage-connect' );
		}
		if ( '' !== $status['cacheLayer'] ) {
			return __( 'Degraded — page cache detected', 'ranksage-connect' );
		}
		if ( '' !== $status['lastError'] ) {
			return __( 'Degraded — last send failed', 'ranksage-connect' );
		}
		if ( '' !== $status['overflowMessage'] ) {
			return __( 'Degraded — buffered hits were dropped', 'ranksage-connect' );
		}
		return __( 'Active', 'ranksage-connect' );
	}

	/**
	 * Renders the two independent capability toggles.
	 *
	 * @param array $settings Connection settings.
	 * @return void
	 */
	private static function render_toggles( array $settings ) {
		echo '<form method="post" action="">';
		wp_nonce_field( 'ranksage_connect_toggles' );
		echo '<input type="hidden" name="ranksage_action" value="save_toggles" />';
		echo '<table class="form-table" role="presentation"><tbody>';

		printf(
			'<tr><th scope="row">%1$s</th><td><label><input type="checkbox" name="ranksage_script_enabled" value="1" %2$s /> %3$s</label></td></tr>',
			esc_html__( 'Tracking tag', 'ranksage-connect' ),
			checked( $settings['script_enabled'], true, false ),
			esc_html__( 'Add the RankSage tracking tag to my pages', 'ranksage-connect' )
		);
		printf(
			'<tr><th scope="row">%1$s</th><td><label><input type="checkbox" name="ranksage_capture_enabled" value="1" %2$s /> %3$s</label><p class="description">%4$s</p></td></tr>',
			esc_html__( 'AI-crawler capture', 'ranksage-connect' ),
			checked( $settings['capture_enabled'], true, false ),
			esc_html__( 'Report AI-crawler visits to my RankSage account', 'ranksage-connect' ),
			esc_html__( 'Sends only the crawler user-agent, the requested path and a timestamp. No visitor personal data, no IP addresses, no query strings.', 'ranksage-connect' )
		);

		echo '</tbody></table>';
		submit_button( __( 'Save changes', 'ranksage-connect' ) );
		echo '</form>';
	}

	/**
	 * Renders the disconnect form.
	 *
	 * @return void
	 */
	private static function render_disconnect() {
		echo '<form method="post" action="">';
		wp_nonce_field( 'ranksage_connect_disconnect' );
		echo '<input type="hidden" name="ranksage_action" value="disconnect" />';
		submit_button( __( 'Disconnect from RankSage', 'ranksage-connect' ), 'delete', 'submit', true );
		echo '</form>';
	}
}
