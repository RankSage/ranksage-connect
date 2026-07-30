<?php
/**
 * Batched delivery of buffered AI-crawler hits to RankSage.
 *
 * @package RankSage_Connect
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WHAT: Ships the buffered bot hits to RankSage in one batched POST, on wp-cron.
 * HOW:  Custom 5-minute schedule (plus an immediate single event when the buffer
 *       crosses its threshold) → read up to flush_batch_size rows → one POST to
 *       /api/v1/tracking/bot-hits with the site's PUBLIC tracking key → delete the
 *       shipped rows on 2xx, otherwise retain and back off.
 * WHY:  One batched request every five minutes is invisible to a host's performance
 *       audit; a request-path call per crawler hit is not.
 * NOTE: The payload matches the RankSage edge-snippet contract exactly —
 *       header X-Ranksage-Key, body {hits:[{path,userAgent,timestamp}]} — because
 *       the server re-classifies and folds every hit through one code path regardless
 *       of which forwarder sent it.
 */
class RankSage_Connect_Flusher {

	/** Cron schedule slug. */
	const SCHEDULE = 'ranksage_five_minutes';

	/** Backoff ceiling after repeated delivery failures. */
	const MAX_BACKOFF = 6 * HOUR_IN_SECONDS;

	/**
	 * Registers the schedule, the recurring event and the flush handler.
	 *
	 * @return void
	 */
	public static function register() {
		add_filter( 'cron_schedules', array( __CLASS__, 'add_schedule' ) ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected -- a five-minute flush is the documented design; it performs one outbound request.
		add_action( RANKSAGE_CONNECT_FLUSH_HOOK, array( __CLASS__, 'flush' ) );
		add_action( 'init', array( __CLASS__, 'ensure_scheduled' ), 20 );
	}

	/**
	 * Adds the five-minute schedule.
	 *
	 * @param array $schedules Existing schedules.
	 * @return array
	 */
	public static function add_schedule( $schedules ) {
		if ( ! isset( $schedules[ self::SCHEDULE ] ) ) {
			$schedules[ self::SCHEDULE ] = array(
				'interval' => 5 * MINUTE_IN_SECONDS,
				'display'  => __( 'Every five minutes (RankSage)', 'ranksage-connect' ),
			);
		}
		return $schedules;
	}

	/**
	 * WHAT: Schedules the recurring flush only while connected and capturing.
	 * WHY:  A disconnected site must schedule no work and make no outbound request —
	 *       that is the consent model wordpress.org guideline 7 requires.
	 *
	 * @return void
	 */
	public static function ensure_scheduled() {
		$settings  = RankSage_Connect_Settings::get();
		$wanted    = $settings['connected'] && $settings['capture_enabled'];
		$scheduled = wp_next_scheduled( RANKSAGE_CONNECT_FLUSH_HOOK );

		if ( $wanted && ! $scheduled ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, self::SCHEDULE, RANKSAGE_CONNECT_FLUSH_HOOK );
		} elseif ( ! $wanted && $scheduled ) {
			wp_unschedule_event( $scheduled, RANKSAGE_CONNECT_FLUSH_HOOK );
		}
	}

	/**
	 * WHAT: Sends one batch and reconciles the buffer.
	 *
	 * @return void
	 */
	public static function flush() {
		global $wpdb;

		$settings = RankSage_Connect_Settings::get();
		if ( ! $settings['connected'] || ! $settings['capture_enabled'] || '' === $settings['public_key'] ) {
			return;
		}

		$state = RankSage_Connect_Settings::get_state();
		if ( $state['retry_after'] > time() ) {
			return; // Still backing off from a failed delivery.
		}

		$config = RankSage_Connect_Config::get();
		$table  = RankSage_Connect_Capture::table();
		$limit  = (int) $config['flush_batch_size'];

		self::prune_overflow( (int) $config['buffer_max_rows'] );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is derived from $wpdb->prefix; the only variable is bound via prepare().
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, path, ua, hit_ts FROM ' . $table . ' ORDER BY id ASC LIMIT %d', $limit ) );

		if ( empty( $rows ) ) {
			RankSage_Connect_Settings::update_state( array( 'last_flush' => time() ) );
			return;
		}

		$hits   = array();
		$max_id = 0;
		foreach ( $rows as $row ) {
			$hits[] = array(
				'path'      => $row->path,
				'userAgent' => $row->ua,
				'timestamp' => (int) $row->hit_ts * 1000,
			);
			$max_id = max( $max_id, (int) $row->id );
		}

		$response = wp_remote_post(
			RANKSAGE_CONNECT_API_BASE . $config['bot_hits_path'],
			array(
				'timeout'    => 15,
				'user-agent' => RankSage_Connect_Config::user_agent(),
				'headers'    => array(
					'Content-Type'   => 'application/json',
					'X-Ranksage-Key' => $settings['public_key'],
				),
				'body'       => wp_json_encode( array( 'hits' => $hits ) ),
			)
		);

		if ( is_wp_error( $response ) ) {
			self::record_failure( $response->get_error_message() );
			return;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( $status < 200 || $status >= 300 ) {
			self::record_failure( sprintf( 'HTTP %d from RankSage', $status ) );
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is derived from $wpdb->prefix; the only variable is bound via prepare().
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . $table . ' WHERE id <= %d', $max_id ) );

		RankSage_Connect_Settings::update_state(
			array(
				'last_flush'  => time(),
				'last_error'  => '',
				'retry_after' => 0,
				'failures'    => 0,
				'sent_total'  => (int) $state['sent_total'] + count( $hits ),
			)
		);

		// A full batch means more is waiting; drain promptly rather than at the next tick.
		if ( count( $hits ) >= $limit && ! wp_next_scheduled( RANKSAGE_CONNECT_FLUSH_HOOK ) ) {
			wp_schedule_single_event( time() + 30, RANKSAGE_CONNECT_FLUSH_HOOK );
		}
	}

	/**
	 * WHAT: Records a delivery failure and sets an exponential retry window.
	 * WHY:  Retaining the buffer keeps the data; the backoff keeps a RankSage outage
	 *       from turning into a cron storm on the customer's site. The reason is
	 *       surfaced in wp-admin and in ranksage/v1/status — never swallowed.
	 *
	 * @param string $message Failure reason.
	 * @return void
	 */
	private static function record_failure( $message ) {
		$state    = RankSage_Connect_Settings::get_state();
		$failures = (int) $state['failures'] + 1;
		$backoff  = min( self::MAX_BACKOFF, 5 * MINUTE_IN_SECONDS * pow( 2, min( 8, $failures - 1 ) ) );

		RankSage_Connect_Settings::update_state(
			array(
				'failures'      => $failures,
				'last_error'    => $message,
				'last_error_at' => time(),
				'retry_after'   => time() + (int) $backoff,
			)
		);
	}

	/**
	 * WHAT: Caps the buffer so a long outage cannot grow the customer's database.
	 * WHY:  Dropping the OLDEST rows keeps recent crawler activity, which is the data
	 *       that matters, and the drop is recorded rather than silent.
	 *
	 * @param int $max_rows Hard ceiling on buffered rows.
	 * @return void
	 */
	private static function prune_overflow( $max_rows ) {
		global $wpdb;

		$depth = RankSage_Connect_Capture::buffer_depth();
		if ( $depth <= $max_rows ) {
			return;
		}

		$table  = RankSage_Connect_Capture::table();
		$excess = $depth - $max_rows;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is derived from $wpdb->prefix; the only variable is bound via prepare().
		$cutoff = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . $table . ' ORDER BY id ASC LIMIT 1 OFFSET %d', $excess - 1 ) );
		if ( $cutoff > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- see above.
			$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . $table . ' WHERE id <= %d', $cutoff ) );
		}

		RankSage_Connect_Settings::update_state(
			array(
				'last_error'    => sprintf(
					/* translators: %d: number of dropped buffered hits. */
					__( 'Buffer overflowed — %d oldest AI-crawler hits were dropped because RankSage could not be reached.', 'ranksage-connect' ),
					$excess
				),
				'last_error_at' => time(),
			)
		);
	}
}
