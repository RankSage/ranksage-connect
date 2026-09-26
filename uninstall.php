<?php
/**
 * Uninstall cleanup — removes every trace of RankSage Connect from this site.
 *
 * @package RankSage_Connect
 */

// Only WordPress may run this file, and only during an actual uninstall.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

delete_option( 'ranksage_connect_settings' );
delete_option( 'ranksage_connect_state' );
delete_option( 'ranksage_connect_remote_config' );

// Per-user dismissal of the page-cache notice.
delete_metadata( 'user', 0, 'ranksage_connect_cache_notice_dismissed', '', true );

// The buffer table is ours alone; uninstalling means the user wants it gone.
$ranksage_connect_table = $wpdb->prefix . 'ranksage_bot_hits';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- removing the plugin's own table on uninstall is the point.
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $ranksage_connect_table ) );

// Clear any scheduled work left behind.
// NOTE: `ranksage_connect_flush_bot_hits_now` is the one-off "drain now" hook — it is a
// separate hook precisely so `wp_next_scheduled()` can distinguish it from the recurring
// flush, so it also has to be cleared separately here.
foreach ( array( 'ranksage_connect_flush_bot_hits', 'ranksage_connect_flush_bot_hits_now', 'ranksage_connect_refresh_config' ) as $ranksage_connect_hook ) {
	$ranksage_connect_timestamp = wp_next_scheduled( $ranksage_connect_hook );
	while ( false !== $ranksage_connect_timestamp ) {
		wp_unschedule_event( $ranksage_connect_timestamp, $ranksage_connect_hook );
		$ranksage_connect_timestamp = wp_next_scheduled( $ranksage_connect_hook );
	}
}
