<?php
/**
 * RankSage tracking tag — enqueued through the WordPress script API.
 *
 * @package RankSage_Connect
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WHAT: Adds the RankSage tracking script (rs.js) to front-end pages.
 * HOW:  wp_enqueue_script() with the WP 6.3 `strategy => 'defer'` loading strategy,
 *       a small inline "before" bootstrap (wp_add_inline_script) that sets the globals
 *       rs.js reads at start-up, and the `script_loader_tag` filter to add the
 *       `data-public-key` and `crossorigin` attributes to that one tag.
 * WHY:  wordpress.org review rejects hand-printed <script> tags in wp_head — the
 *       enqueue API is what lets caching/optimisation plugins, CSP tooling and
 *       themes see, reorder, defer or dequeue the script like any other.
 * NOTE: Nothing is enqueued until the site is connected AND the tracking toggle is on.
 *       That is the wordpress.org guideline 7 consent model: an unconnected install
 *       makes no request to anywhere. rs.js itself only starts recording once it has
 *       a RankSage session — see the bootstrap() docblock for the exact contract.
 */
class RankSage_Connect_Head {

	/** Script handle; WordPress renders the tag id as "{handle}-js". */
	const HANDLE = 'ranksage-connect-rs';

	/**
	 * Registers the hooks.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_filter( 'script_loader_tag', array( __CLASS__, 'add_tag_attributes' ), 10, 2 );
	}

	/**
	 * WHAT: Whether the tag should be on this site right now.
	 *
	 * @return bool
	 */
	private static function is_active() {
		$settings = RankSage_Connect_Settings::get();
		return $settings['connected'] && $settings['script_enabled'] && '' !== $settings['public_key'];
	}

	/**
	 * WHAT: Enqueues rs.js with the defer strategy plus its inline bootstrap.
	 * NOTE: `null` version on purpose — rs.js is served and versioned by RankSage, and a
	 *       `?ver=` query string would only fragment the CDN cache for the same bytes.
	 *
	 * @return void
	 */
	public static function enqueue() {
		if ( ! self::is_active() ) {
			return;
		}

		$settings = RankSage_Connect_Settings::get();

		wp_enqueue_script(
			self::HANDLE,
			RANKSAGE_CONNECT_SCRIPT_SRC,
			array(),
			null, // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- versioned by RankSage at the source; see NOTE above.
			array(
				'strategy'  => 'defer',
				'in_footer' => false,
			)
		);

		wp_add_inline_script( self::HANDLE, self::bootstrap( $settings['public_key'] ), 'before' );
	}

	/**
	 * WHAT: The inline bootstrap that runs before rs.js.
	 * HOW:  Sets `window.__RS_API_KEY__` (the site's PUBLIC tracking key — the same value
	 *       already visible in page source) and `window.__RS_INGEST_URL__` (the RankSage
	 *       API's absolute /e endpoint), without overwriting values a theme already set.
	 * WHY:  rs.js ignores `data-public-key` and reads these globals instead. Its ingest
	 *       default is same-origin `/e`, which on a WordPress site is a 404 — so without
	 *       __RS_INGEST_URL__ every batch would be lost even once a session exists.
	 * NOTE: rs.js ALSO requires `__RS_SESSION_ID__`, `__RS_TOKEN__` and `__RS_AES_KEY__`,
	 *       which RankSage mints only from POST /session/init authenticated with the
	 *       account's SECRET key. This plugin deliberately never stores that secret, so
	 *       until RankSage can open a session from the public key alone, rs.js loads and
	 *       then stays idle. Everything this tag needs for that server-side change is
	 *       already emitted here, so no plugin release is required when it lands.
	 *
	 * @param string $public_key The site's public tracking key.
	 * @return string JavaScript.
	 */
	private static function bootstrap( $public_key ) {
		return sprintf(
			'window.__RS_API_KEY__=window.__RS_API_KEY__||%1$s;window.__RS_INGEST_URL__=window.__RS_INGEST_URL__||%2$s;',
			wp_json_encode( (string) $public_key ),
			wp_json_encode( esc_url_raw( RANKSAGE_CONNECT_API_BASE . '/e' ) )
		);
	}

	/**
	 * WHAT: Adds `data-public-key` and `crossorigin="anonymous"` to the rs.js tag only.
	 * HOW:  WP_HTML_Tag_Processor (core since 6.2) finds the <script> whose id is this
	 *       handle's and sets attributes on it; the inline bootstrap in the same $tag
	 *       string is left untouched.
	 * WHY:  `data-public-key` keeps the tag byte-compatible with the snippet RankSage's
	 *       Tracking Setup screen shows (and with RankSage's "tag present" check), and
	 *       `crossorigin` gives the page readable error reports from the cross-origin
	 *       script. The enqueue API has no parameter for either.
	 *
	 * @param string $tag    The full HTML for this handle (inline scripts included).
	 * @param string $handle Script handle.
	 * @return string
	 */
	public static function add_tag_attributes( $tag, $handle ) {
		if ( self::HANDLE !== $handle || ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
			return $tag;
		}

		$settings  = RankSage_Connect_Settings::get();
		$processor = new WP_HTML_Tag_Processor( $tag );
		while ( $processor->next_tag( 'script' ) ) {
			if ( self::HANDLE . '-js' === $processor->get_attribute( 'id' ) ) {
				$processor->set_attribute( 'data-public-key', (string) $settings['public_key'] );
				$processor->set_attribute( 'crossorigin', 'anonymous' );
				break;
			}
		}
		return $processor->get_updated_html();
	}
}
