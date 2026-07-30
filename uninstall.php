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

// The buffer table is ours alone; uninstalling means the user wants it gone.
$ranksage_table = $wpdb->prefix . 'ranksage_bot_hits';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name derives from $wpdb->prefix and cannot be parameterised; DROP TABLE takes no bindable values.
$wpdb->query( 'DROP TABLE IF EXISTS ' . $ranksage_table );

// Clear any scheduled work left behind.
foreach ( array( 'ranksage_connect_flush_bot_hits', 'ranksage_connect_refresh_config' ) as $ranksage_hook ) {
	$ranksage_timestamp = wp_next_scheduled( $ranksage_hook );
	while ( false !== $ranksage_timestamp ) {
		wp_unschedule_event( $ranksage_timestamp, $ranksage_hook );
		$ranksage_timestamp = wp_next_scheduled( $ranksage_hook );
	}
}
