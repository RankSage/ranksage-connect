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
	 * WHAT: Hard ceiling on how many rows one POST may carry, regardless of config.
	 * WHY:  RankSage folds a batch into at most 200 distinct (date, path, bot) groups and
	 *       still answers 204. Remote config may raise flush_batch_size as far as 500, and
	 *       the plugin DELETEs the whole batch on any 2xx — so a 500-row batch could delete
	 *       rows the server silently dropped past its fold cap. Clamping here keeps the
	 *       plugin inside the contract it can actually verify. Raising this constant
	 *       requires the server-side cap to move first.
	 */
	const MAX_BATCH_SIZE = 200;

	/** How long a buffer overflow keeps the site flagged as degraded. */
	const OVERFLOW_NOTICE_TTL = 7 * DAY_IN_SECONDS;

	/**
	 * Registers the schedule, the recurring event and the flush handler.
	 *
	 * @return void
	 */
	public static function register() {
		add_filter( 'cron_schedules', array( __CLASS__, 'add_schedule' ) ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- a five-minute flush is the documented design; it performs one outbound request.
		add_action( RANKSAGE_CONNECT_FLUSH_HOOK, array( __CLASS__, 'flush' ) );
		add_action( RANKSAGE_CONNECT_FLUSH_NOW_HOOK, array( __CLASS__, 'flush' ) );
		add_action( 'init', array( __CLASS__, 'ensure_scheduled' ), 20 );
	}

	/**
	 * WHAT: Asks for a one-off flush on the next cron tick, if one is not already queued.
	 * HOW:  Schedules a single event on the DEDICATED "flush now" hook, so
	 *       wp_next_scheduled() answers about this valve only and not about the
	 *       permanently-scheduled recurring flush.
	 * WHY:  Both callers (a full batch that means more is waiting, and the buffer
	 *       threshold in Capture::record) previously tested the recurring hook, which
	 *       always has a next occurrence — the valve never fired once.
	 *
	 * @param int $delay Seconds from now.
	 * @return void
	 */
	public static function request_immediate_flush( $delay = 0 ) {
		if ( wp_next_scheduled( RANKSAGE_CONNECT_FLUSH_NOW_HOOK ) ) {
			return;
		}
		wp_schedule_single_event( time() + (int) $delay, RANKSAGE_CONNECT_FLUSH_NOW_HOOK );
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

		// A pending one-off drain must die with the recurring event, or a disconnected
		// site would still run one more flush attempt.
		if ( ! $wanted ) {
			$pending_now = wp_next_scheduled( RANKSAGE_CONNECT_FLUSH_NOW_HOOK );
			while ( false !== $pending_now ) {
				wp_unschedule_event( $pending_now, RANKSAGE_CONNECT_FLUSH_NOW_HOOK );
				$pending_now = wp_next_scheduled( RANKSAGE_CONNECT_FLUSH_NOW_HOOK );
			}
		}
	}

	/**
	 * WHAT: Sends one batch and reconciles the buffer.
	 *
	 * @return void
	 */
	public static function flush() {
		global $wpdb;

		// The only skip that precedes pruning: when the plugin is disconnected or capture
		// is off, Capture::maybe_capture() returns on the same condition, so the buffer
		// cannot grow and there is nothing to cap.
		$settings = RankSage_Connect_Settings::get();
		if ( ! $settings['connected'] || ! $settings['capture_enabled'] || '' === $settings['public_key'] ) {
			return;
		}

		$config = RankSage_Connect_Config::get();
		$table  = RankSage_Connect_Capture::table();

		/**
		 * WHAT: The buffer cap is applied on EVERY flush attempt, before the backoff gate.
		 * WHY:  The gate returns for up to MAX_BACKOFF (6 hours) after repeated delivery
		 *       failures — precisely the window in which the buffer grows without bound —
		 *       so pruning behind the gate meant the 5,000-row cap was enforced exactly
		 *       zero times during an outage, growing the customer's database unchecked.
		 *       Pruning is local-only (one DELETE), so it is safe to run while backing off.
		 */
		self::prune_overflow( (int) $config['buffer_max_rows'] );

		$state = RankSage_Connect_Settings::get_state();
		if ( $state['retry_after'] > time() ) {
			return; // Still backing off from a failed delivery — buffer already capped above.
		}

		$limit = max( 1, min( self::MAX_BATCH_SIZE, (int) $config['flush_batch_size'] ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- dedicated write buffer; rows are read once and deleted.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, path, ua, hit_ts FROM %i ORDER BY id ASC LIMIT %d', $table, $limit ) );

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
			self::record_failure(
				sprintf(
					/* translators: %d: HTTP status code returned by RankSage. */
					__( 'RankSage answered HTTP %d when this site sent AI-crawler visits.', 'ranksage-connect' ),
					$status
				)
			);
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- dedicated write buffer; see above.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id <= %d', $table, $max_id ) );

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
		if ( count( $hits ) >= $limit ) {
			self::request_immediate_flush( 30 );
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
	 * NOTE: The overflow is recorded in its OWN state fields, never in `last_error`.
	 *       Overwriting `last_error` would erase the delivery failure that caused the
	 *       overflow in the first place — leaving wp-admin and ranksage/v1/status showing
	 *       the symptom while hiding the cause.
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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- dedicated write buffer; see flush().
		$cutoff = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i ORDER BY id ASC LIMIT 1 OFFSET %d', $table, $excess - 1 ) );
		if ( $cutoff > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- see above.
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id <= %d', $table, $cutoff ) );
		}

		$state = RankSage_Connect_Settings::get_state();
		RankSage_Connect_Settings::update_state(
			array(
				'overflow_dropped' => (int) $state['overflow_dropped'] + $excess,
				'overflow_at'      => time(),
			)
		);
	}

	/**
	 * WHAT: The human sentence describing a RECENT buffer overflow, or '' when there is none.
	 * WHY:  Shared by wp-admin and ranksage/v1/status so both report the same fact, and
	 *       kept separate from `last_error` so a delivery failure and a buffer overflow
	 *       are two visible lines rather than one overwriting the other.
	 * NOTE: Expires after OVERFLOW_NOTICE_TTL. The running total is kept forever, but a
	 *       drop from three months ago must not pin the site to "degraded" for good —
	 *       a permanent warning is one nobody reads.
	 *
	 * @return string
	 */
	public static function overflow_message() {
		$state = RankSage_Connect_Settings::get_state();
		if ( (int) $state['overflow_dropped'] <= 0 ) {
			return '';
		}
		if ( (int) $state['overflow_at'] < time() - self::OVERFLOW_NOTICE_TTL ) {
			return '';
		}

		return sprintf(
			/* translators: %d: number of dropped buffered hits. */
			__( 'Buffer overflowed recently — %d oldest AI-crawler hits have been dropped in total because they could not be delivered to RankSage in time. See the last error below for the cause.', 'ranksage-connect' ),
			(int) $state['overflow_dropped']
		);
	}
}
