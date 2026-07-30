<?php
/**
 * RankSage tracking tag injection.
 *
 * @package RankSage_Connect
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WHAT: Prints the RankSage tracking script tag in <head>.
 * HOW:  wp_head at priority 1, echoing the exact tag RankSage's Tracking Setup screen
 *       shows, so a plugin-managed site and a hand-pasted site are byte-identical.
 * WHY:  wp_head rather than wp_enqueue_script because the tag's position, `defer` and
 *       `crossorigin` must be exact — the enqueue API reorders and rewrites attributes.
 * NOTE: Nothing is printed until the site is connected AND the script toggle is on.
 *       That is the wordpress.org guideline 7 consent model: an unconnected install
 *       makes no request to anywhere.
 */
class RankSage_Connect_Head {

	/**
	 * Registers the head hook.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'wp_head', array( __CLASS__, 'print_tag' ), 1 );
	}

	/**
	 * Echoes the tracking tag.
	 *
	 * @return void
	 */
	public static function print_tag() {
		$settings = RankSage_Connect_Settings::get();

		if ( ! $settings['connected'] || ! $settings['script_enabled'] || '' === $settings['public_key'] ) {
			return;
		}

		printf(
			'<!-- RankSage Behavioral Tracking -->%1$s<script src="%2$s" data-public-key="%3$s" crossorigin="anonymous" defer></script>%1$s',
			"\n",
			esc_url( RANKSAGE_CONNECT_SCRIPT_SRC ),
			esc_attr( $settings['public_key'] )
		);
	}
}
