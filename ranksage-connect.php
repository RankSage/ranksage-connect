<?php
/**
 * Plugin Name:       RankSage Connect
 * Plugin URI:        https://ranksage.com/integrations/wordpress
 * Description:       Connects your site to RankSage: adds the RankSage tracking tag and reports AI-crawler visits (GPTBot, ClaudeBot, PerplexityBot and friends) to your RankSage account. Nothing is sent until you connect an account.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Tested up to:      7.0
 * Requires PHP:      7.4
 * Author:            RankSage
 * Author URI:        https://ranksage.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ranksage-connect
 *
 * @package RankSage_Connect
 */

// WHAT: Refuse direct file access.
// WHY:  Standard WordPress hardening — every PHP file in the plugin must be inert
//       when requested directly rather than loaded through WordPress.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'RANKSAGE_CONNECT_VERSION', '1.0.0' );
define( 'RANKSAGE_CONNECT_FILE', __FILE__ );
define( 'RANKSAGE_CONNECT_DIR', plugin_dir_path( __FILE__ ) );
define( 'RANKSAGE_CONNECT_URL', plugin_dir_url( __FILE__ ) );

/**
 * WHAT: The RankSage hosts this plugin is allowed to talk to, and the tracking script URL.
 * WHY:  These are COMPILED-IN and deliberately not remotely overridable. The April 2026
 *       wordpress.org supply-chain incident was delivered through a plugin's own
 *       "analytics module" pointing at an attacker-controlled host. Pinning the host
 *       means a compromised config response can change behaviour but can never
 *       exfiltrate to a new destination.
 * NOTE: Remote config may adjust paths, UA tokens, intervals and the kill switch —
 *       never a hostname. See RankSage_Connect_Config::sanitize().
 */
define( 'RANKSAGE_CONNECT_API_BASE', 'https://api.ranksage.io' );
define( 'RANKSAGE_CONNECT_APP_BASE', 'https://app.ranksage.io' );
define( 'RANKSAGE_CONNECT_SCRIPT_SRC', 'https://cdn.ranksage.io/rs.js' );

/**
 * WHAT: Ed25519 public key (raw 32 bytes, hex) used to verify every payload RankSage
 *       sends this plugin — the connect handshake and the remote config.
 * HOW:  sodium_crypto_sign_verify_detached() over the exact payload string, with the
 *       signature detached in a sibling field.
 * WHY:  Without this, a MITM or another plugin on the same site could repoint this
 *       site's tracking at somebody else's RankSage account.
 * NOTE: A public key is not a secret; publishing it is the point.
 */
define( 'RANKSAGE_CONNECT_SIGNING_PUBLIC_KEY', 'a0db4eea7070119db142c7814ed47cf8375417fd0d20ed80267ba239e769e955' );

/** Option holding the connection + user toggles. */
define( 'RANKSAGE_CONNECT_OPTION', 'ranksage_connect_settings' );
/** Option holding operational state (last flush, last error, backoff). Never autoloaded. */
define( 'RANKSAGE_CONNECT_STATE_OPTION', 'ranksage_connect_state' );
/**
 * Option holding the signature-verified remote config.
 * NOTE: an option (autoloaded), not a transient, precisely BECAUSE the AI-crawler
 * capture hook reads it on every front-end request — see RankSage_Connect_Config::get().
 */
define( 'RANKSAGE_CONNECT_CONFIG_OPTION', 'ranksage_connect_remote_config' );
/** Cron hook that flushes the buffered AI-crawler hits (recurring, every five minutes). */
define( 'RANKSAGE_CONNECT_FLUSH_HOOK', 'ranksage_connect_flush_bot_hits' );
/**
 * WHAT: Separate cron hook for the one-off "drain now" flush.
 * WHY:  The immediate-flush escape valves used to schedule a single event on
 *       RANKSAGE_CONNECT_FLUSH_HOOK guarded by `! wp_next_scheduled( … )`. That hook
 *       always HAS a next occurrence (ensure_scheduled keeps a recurring event on it
 *       with the same empty args), so the guard was permanently false and the valve was
 *       dead code. A distinct hook name gives `wp_next_scheduled` something it can
 *       actually answer "no" to.
 * NOTE: Registered to the same callback as the recurring hook, and unscheduled by both
 *       deactivation and uninstall.php.
 */
define( 'RANKSAGE_CONNECT_FLUSH_NOW_HOOK', 'ranksage_connect_flush_bot_hits_now' );
/** Cron hook that refreshes the remote config. */
define( 'RANKSAGE_CONNECT_CONFIG_HOOK', 'ranksage_connect_refresh_config' );

require_once RANKSAGE_CONNECT_DIR . 'includes/class-ranksage-connect-settings.php';
require_once RANKSAGE_CONNECT_DIR . 'includes/class-ranksage-connect-config.php';
require_once RANKSAGE_CONNECT_DIR . 'includes/class-ranksage-connect-cache-detect.php';
require_once RANKSAGE_CONNECT_DIR . 'includes/class-ranksage-connect-capture.php';
require_once RANKSAGE_CONNECT_DIR . 'includes/class-ranksage-connect-flusher.php';
require_once RANKSAGE_CONNECT_DIR . 'includes/class-ranksage-connect-head.php';
require_once RANKSAGE_CONNECT_DIR . 'includes/class-ranksage-connect-rest.php';
require_once RANKSAGE_CONNECT_DIR . 'includes/class-ranksage-connect-admin.php';

/**
 * WHAT: Registers every hook the plugin uses.
 * HOW:  Called once on plugins_loaded; each collaborator registers its own hooks so
 *       the wiring stays greppable from one place.
 * WHY:  A single entry point makes "what does this plugin do to my site?" answerable
 *       in one screen — which is exactly what a wordpress.org reviewer asks.
 */
function ranksage_connect_bootstrap() {
	RankSage_Connect_Head::register();
	RankSage_Connect_Capture::register();
	RankSage_Connect_Flusher::register();
	RankSage_Connect_Config::register();
	RankSage_Connect_Rest::register();

	if ( is_admin() ) {
		RankSage_Connect_Admin::register();
	}
}
add_action( 'plugins_loaded', 'ranksage_connect_bootstrap' );

/**
 * WHAT: Creates the buffer table on activation.
 * WHY:  The AI-crawler capture writes one row per bot hit AFTER the response is
 *       flushed; a dedicated narrow table keeps that write cheap and keeps the
 *       options table (which is autoloaded on every request) untouched.
 * NOTE: dbDelta formatting rules are strict — two spaces after PRIMARY KEY, one field
 *       per line, no backticks around the table name.
 */
function ranksage_connect_activate() {
	global $wpdb;

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$table_name      = $wpdb->prefix . 'ranksage_bot_hits';
	$charset_collate = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE {$table_name} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		path varchar(512) NOT NULL DEFAULT '',
		ua varchar(512) NOT NULL DEFAULT '',
		hit_ts int(11) unsigned NOT NULL DEFAULT 0,
		PRIMARY KEY  (id)
	) {$charset_collate};";

	dbDelta( $sql );
}
register_activation_hook( __FILE__, 'ranksage_connect_activate' );

/**
 * WHAT: Unschedules the flush cron on deactivation.
 * WHY:  A deactivated plugin must leave no scheduled work behind. Data and options
 *       survive deactivation on purpose (reactivating restores the connection);
 *       uninstall.php is what removes them.
 */
function ranksage_connect_deactivate() {
	foreach ( array( RANKSAGE_CONNECT_FLUSH_HOOK, RANKSAGE_CONNECT_FLUSH_NOW_HOOK, RANKSAGE_CONNECT_CONFIG_HOOK ) as $hook ) {
		$timestamp = wp_next_scheduled( $hook );
		while ( false !== $timestamp ) {
			wp_unschedule_event( $timestamp, $hook );
			$timestamp = wp_next_scheduled( $hook );
		}
	}
}
register_deactivation_hook( __FILE__, 'ranksage_connect_deactivate' );
