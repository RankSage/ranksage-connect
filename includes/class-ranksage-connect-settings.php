<?php
/**
 * Connection settings + operational state accessors.
 *
 * @package RankSage_Connect
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WHAT: The single read/write surface for the plugin's two options.
 * HOW:  Static accessors over get_option/update_option with a fixed shape and defaults.
 * WHY:  Every other class reads the connection through here, so "what is stored on the
 *       customer's site?" has exactly one answer — and uninstall.php has exactly two
 *       option names to delete.
 * NOTE: The stored public tracking key is the SAME public key already visible in the
 *       page source of any RankSage-tracked site. No RankSage secret is ever written
 *       to a customer filesystem or database.
 */
class RankSage_Connect_Settings {

	/**
	 * Returns the connection settings with defaults filled in.
	 *
	 * @return array{connected:bool,api_base:string,public_key:string,site_token:string,script_enabled:bool,capture_enabled:bool,connected_at:int,account_label:string}
	 */
	public static function get() {
		$defaults = array(
			'connected'       => false,
			'api_base'        => RANKSAGE_CONNECT_API_BASE,
			'public_key'      => '',
			'site_token'      => '',
			'script_enabled'  => true,
			'capture_enabled' => true,
			'connected_at'    => 0,
			'account_label'   => '',
		);

		$stored = get_option( RANKSAGE_CONNECT_OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$settings = array_merge( $defaults, $stored );

		// The API base is pinned: a stored value that drifted from the compiled host
		// (corrupt option, hostile write) is discarded rather than honoured.
		if ( RANKSAGE_CONNECT_API_BASE !== $settings['api_base'] ) {
			$settings['api_base'] = RANKSAGE_CONNECT_API_BASE;
		}

		$settings['connected']       = (bool) $settings['connected'] && '' !== $settings['public_key'];
		$settings['script_enabled']  = (bool) $settings['script_enabled'];
		$settings['capture_enabled'] = (bool) $settings['capture_enabled'];

		return $settings;
	}

	/**
	 * WHAT: Merges a partial update into the stored settings.
	 * NOTE: Written with autoload = true DELIBERATELY. This option is read on every
	 *       front-end request by the AI-crawler capture hook; autoloaded options come
	 *       from the single alloptions query WordPress already runs, so the read costs
	 *       nothing. A non-autoloaded option here would add a query per pageview.
	 *
	 * @param array $changes Keys to overwrite.
	 * @return void
	 */
	public static function update( array $changes ) {
		$settings = array_merge( self::get(), $changes );
		update_option( RANKSAGE_CONNECT_OPTION, $settings, true );
	}

	/**
	 * WHAT: Stores a verified connect payload.
	 * WHY:  Called from exactly two places — the REST /connect route (Direction A) and
	 *       the admin code exchange (Direction B) — both AFTER Ed25519 verification.
	 *
	 * @param string $public_key    RankSage public tracking key (rs_live_* / rs_staging_*).
	 * @param string $site_token    Opaque per-site token used for config pulls.
	 * @param string $account_label Human label shown in wp-admin.
	 * @return void
	 */
	public static function store_connection( $public_key, $site_token, $account_label = '' ) {
		self::update(
			array(
				'connected'     => true,
				'public_key'    => $public_key,
				'site_token'    => $site_token,
				'account_label' => $account_label,
				'connected_at'  => time(),
			)
		);
	}

	/**
	 * Clears the connection but keeps the user's toggle preferences.
	 *
	 * @return void
	 */
	public static function clear_connection() {
		self::update(
			array(
				'connected'     => false,
				'public_key'    => '',
				'site_token'    => '',
				'account_label' => '',
				'connected_at'  => 0,
			)
		);
		delete_option( RANKSAGE_CONNECT_CONFIG_OPTION );
	}

	/**
	 * Returns operational state (never autoloaded — it changes on every flush).
	 *
	 * NOTE: `overflow_dropped` / `overflow_at` are deliberately SEPARATE from
	 *       `last_error`. Buffer overflow is a consequence of a delivery failure, so
	 *       recording it in `last_error` would overwrite — and hide — the cause.
	 *
	 * @return array{last_flush:int,last_error:string,last_error_at:int,retry_after:int,failures:int,sent_total:int,overflow_dropped:int,overflow_at:int}
	 */
	public static function get_state() {
		$defaults = array(
			'last_flush'       => 0,
			'last_error'       => '',
			'last_error_at'    => 0,
			'retry_after'      => 0,
			'failures'         => 0,
			'sent_total'       => 0,
			'overflow_dropped' => 0,
			'overflow_at'      => 0,
		);

		$stored = get_option( RANKSAGE_CONNECT_STATE_OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return array_merge( $defaults, $stored );
	}

	/**
	 * Merges a partial update into the operational state.
	 *
	 * @param array $changes Keys to overwrite.
	 * @return void
	 */
	public static function update_state( array $changes ) {
		update_option( RANKSAGE_CONNECT_STATE_OPTION, array_merge( self::get_state(), $changes ), false );
	}
}
