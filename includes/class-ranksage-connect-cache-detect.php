<?php
/**
 * Page-cache detection.
 *
 * @package RankSage_Connect
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WHAT: Detects whether a full-page cache sits in front of PHP on this site.
 * HOW:  Checks WP_CACHE, the advanced-cache.php drop-in, and the constants/classes the
 *       common caching plugins and managed hosts define.
 * WHY:  Full-page caches serve anonymous requests — including every AI crawler —
 *       WITHOUT booting PHP. The advanced-cache.php drop-in loads before mu-plugins and
 *       before plugins and exits, so NO plugin hook can observe a cache hit. On a
 *       well-cached site this plugin therefore sees roughly the first hit per URL per
 *       cache TTL. Competing AI-crawler plugins ship this undercount silently; we
 *       refuse to. Detection feeds a loud "degraded" state in wp-admin and in the
 *       RankSage dashboard, with per-cache exclusion instructions.
 */
class RankSage_Connect_Cache_Detect {

	/**
	 * WHAT: Returns the human labels of every cache layer detected, newest check first.
	 *
	 * @return string[] Empty when no cache layer was found.
	 */
	public static function detect() {
		$layers = array();

		if ( defined( 'WP_ROCKET_VERSION' ) ) {
			$layers[] = 'WP Rocket';
		}
		if ( defined( 'LSCWP_V' ) || defined( 'LSCWP_CONTENT_DIR' ) ) {
			$layers[] = 'LiteSpeed Cache';
		}
		if ( defined( 'W3TC' ) || defined( 'W3TC_DIR' ) ) {
			$layers[] = 'W3 Total Cache';
		}
		if ( defined( 'WPCACHEHOME' ) ) {
			$layers[] = 'WP Super Cache';
		}
		if ( defined( 'SITEGROUND_OPTIMIZER_VERSION' ) ) {
			$layers[] = 'SiteGround Optimizer';
		}
		if ( defined( 'WPE_APIKEY' ) || defined( 'WPE_API' ) ) {
			$layers[] = 'WP Engine host cache';
		}
		if ( defined( 'KINSTAMU_VERSION' ) ) {
			$layers[] = 'Kinsta host cache';
		}
		if ( defined( 'WPCOM_IS_VIP_ENV' ) && WPCOM_IS_VIP_ENV ) {
			$layers[] = 'WordPress VIP edge cache';
		}

		// Generic signals last — only reported when no named plugin matched, so the
		// message stays specific enough to act on.
		if ( empty( $layers ) ) {
			if ( defined( 'WP_CACHE' ) && WP_CACHE ) {
				$layers[] = 'WP_CACHE enabled';
			}
			if ( defined( 'WP_CONTENT_DIR' ) && file_exists( WP_CONTENT_DIR . '/advanced-cache.php' ) ) {
				$layers[] = 'advanced-cache.php drop-in';
			}
		}

		return array_values( array_unique( $layers ) );
	}

	/**
	 * The single cacheLayer string reported to RankSage, or an empty string.
	 *
	 * @return string
	 */
	public static function summary() {
		$layers = self::detect();
		return empty( $layers ) ? '' : implode( ', ', $layers );
	}

	/**
	 * WHAT: The honest coverage message shown in wp-admin when a cache is detected.
	 * WHY:  "Zero setup AI-crawler tracking" that silently undercounts is worse than
	 *       no tracking. Naming the cache and the fix is the whole differentiator.
	 *
	 * @param string $summary Output of self::summary().
	 * @return string Escaped-safe plain text (caller escapes).
	 */
	public static function coverage_message( $summary ) {
		return sprintf(
			/* translators: %s: comma-separated list of detected cache layers. */
			__( 'AI-crawler capture is degraded: %s serves pages without running PHP, so RankSage sees only the first crawler visit per URL per cache lifetime. Exclude AI crawler user-agents from your cache to restore full coverage — or use the RankSage Cloudflare Worker snippet for 100%% coverage.', 'ranksage-connect' ),
			$summary
		);
	}

	/**
	 * WHAT: Per-cache, copy-paste exclusion instructions.
	 * WHY:  Static copy on purpose — the plugin never programmatically edits another
	 *       plugin's settings, and static text needs no maintenance behind the
	 *       wordpress.org 24-hour release cooldown.
	 *
	 * @param string $summary Output of self::summary().
	 * @return string[] One instruction line per detected layer.
	 */
	public static function exclusion_instructions( $summary ) {
		$map = array(
			'WP Rocket'            => __( 'WP Rocket: Settings → WP Rocket → Advanced Rules → "Never Cache User Agent(s)" → add GPTBot, ClaudeBot, PerplexityBot, OAI-SearchBot, CCBot, Bytespider, Amazonbot.', 'ranksage-connect' ),
			'LiteSpeed Cache'      => __( 'LiteSpeed Cache: LiteSpeed Cache → Cache → Excludes → "Do Not Cache User Agents" → add GPTBot, ClaudeBot, PerplexityBot, OAI-SearchBot, CCBot, Bytespider, Amazonbot.', 'ranksage-connect' ),
			'W3 Total Cache'       => __( 'W3 Total Cache: Performance → Page Cache → Advanced → "Rejected user agents" → add GPTBot, ClaudeBot, PerplexityBot, OAI-SearchBot, CCBot, Bytespider, Amazonbot.', 'ranksage-connect' ),
			'WP Super Cache'       => __( 'WP Super Cache: Settings → WP Super Cache → Advanced → "Rejected User Agents" → add GPTBot, ClaudeBot, PerplexityBot, OAI-SearchBot, CCBot, Bytespider, Amazonbot.', 'ranksage-connect' ),
			'SiteGround Optimizer' => __( 'SiteGround Optimizer: SG Optimizer → Caching → Exclude URLs / user agents from caching, or disable Dynamic Cache for crawler user-agents in Site Tools.', 'ranksage-connect' ),
			'WP Engine host cache' => __( 'WP Engine: host-level caching cannot be excluded by user-agent from wp-admin. Use the RankSage Cloudflare Worker snippet, or ask WP Engine support to add a cache-bypass rule for AI crawler user-agents.', 'ranksage-connect' ),
			'Kinsta host cache'    => __( 'Kinsta: host-level caching cannot be excluded by user-agent from wp-admin. Use the RankSage Cloudflare Worker snippet, or ask Kinsta support to add a cache-bypass rule for AI crawler user-agents.', 'ranksage-connect' ),
		);

		$instructions = array();
		foreach ( $map as $label => $instruction ) {
			if ( false !== strpos( $summary, $label ) ) {
				$instructions[] = $instruction;
			}
		}

		if ( empty( $instructions ) && '' !== $summary ) {
			$instructions[] = __( 'A page cache was detected but not identified. Exclude the AI crawler user-agents (GPTBot, ClaudeBot, PerplexityBot, OAI-SearchBot, CCBot, Bytespider, Amazonbot) from your cache, or use the RankSage Cloudflare Worker snippet for full coverage.', 'ranksage-connect' );
		}

		return $instructions;
	}
}
