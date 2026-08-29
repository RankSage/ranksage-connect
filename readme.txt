=== RankSage Connect ===
Contributors: ranksage
Tags: ai crawlers, gptbot, analytics, seo, llm
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect your site to RankSage: add the RankSage tracking tag and see which AI crawlers (GPTBot, ClaudeBot, PerplexityBot) read your pages.

== Description ==

RankSage Connect links your WordPress site to your [RankSage](https://www.ranksage.com) account. RankSage joins what AI answer engines say about your brand with Google Search Console, GA4 and first-party visitor behaviour on one row — the page — and returns a ranked list of what to change next. This plugin does exactly two things for that join, and each one can be turned on or off independently:

1. **Tracking tag** — adds the RankSage tracking script to your pages so RankSage can report on visits and behaviour.
2. **AI-crawler capture** — records when an AI crawler (GPTBot, ClaudeBot, PerplexityBot, OAI-SearchBot, CCBot, Bytespider and similar) fetches one of your pages, and reports those visits to your RankSage account.

AI crawlers fetch your HTML without running JavaScript. That means no browser-based analytics tool — including Google Analytics — can see them. A server-side record is the only way this data exists at all, and it is the data that tells you whether ChatGPT, Claude and Perplexity are actually reading your content.

**Nothing is sent anywhere until you connect a RankSage account.** An installed but unconnected plugin makes no outbound request of any kind.

= Honest note about page caches =

If your site uses a full-page cache, cached pages are served **without running PHP at all**. No WordPress plugin — this one included — can observe those requests.

**What this plugin can detect:** cache layers that announce themselves to PHP — WP Rocket, LiteSpeed Cache, W3 Total Cache, WP Super Cache, SiteGround Optimizer, WP Engine, Kinsta, WordPress VIP, and the generic `WP_CACHE` / `advanced-cache.php` signals. When one is found, the settings screen says so and gives you the exact exclusion steps for it.

**What this plugin cannot detect:** an edge or CDN cache in front of your server — Cloudflare, Fastly, a host's own edge tier. There is no reliable way to see those from PHP, because a request served at the edge never reaches your server at all. If you run one, assume your AI-crawler coverage is undercounted by however much the edge absorbs, and use the RankSage Cloudflare Worker snippet — that runs at the edge itself and is the only path to complete coverage.

We would rather tell you where the number is incomplete than show you one that quietly undercounts.

= External service =

This plugin requires an account with **RankSage**, an external SaaS product operated by RankSage.

* Service: RankSage — https://ranksage.com
* Terms of Service: https://ranksage.com/terms
* Privacy Policy: https://ranksage.com/privacy

**What is sent to RankSage, and when:**

* **Only after you click "Connect to RankSage" and complete sign-in.** Before that, the plugin contacts nothing.
* **AI-crawler hits (only while the "AI-crawler capture" toggle is on):** the crawler's user-agent string, the requested path (query strings are stripped before storage), and a timestamp. These are buffered locally in your database and sent in a single batched request roughly every five minutes. **No visitor personal data, no IP addresses, no query strings, no post content.** Only requests whose user-agent matches a known AI crawler are ever recorded — ordinary human traffic is never touched by this feature.
* **Plugin configuration pull:** once a day the plugin requests its configuration (the AI-crawler user-agent list, batch sizes, and a kill switch) from RankSage. This request carries the plugin version and your site URL in the user-agent header, plus the site token issued when you connected, so the configuration can be scoped to your site — nothing else. The response is verified with a cryptographic signature before it is used, and it can never change which server the plugin talks to.
* **Connection handshake:** your site URL, the plugin version and the detected cache layer, once, when you connect.

**What is stored on your site:** your RankSage *public* tracking key (the same key that is visible in your page source), the site token described above, your two toggle preferences, and the local AI-crawler buffer table. No RankSage secret or API password is ever written to your site.

**What the tracking tag sends** is governed by your RankSage account settings and the RankSage privacy policy linked above.

= Security design =

* Every configuration payload RankSage sends is signed with Ed25519 and verified against a public key compiled into this plugin. An unsigned or mis-signed payload is rejected.
* The RankSage hostnames are compiled in and cannot be changed by any server response. The plugin never downloads, evaluates, includes or writes executable code.
* All admin styles are bundled with the plugin. No CDN, no external fonts, no external scripts in wp-admin.
* Zero outbound HTTP requests happen on a front-end page request. Crawler hits are buffered locally and shipped on a schedule.

== Installation ==

1. Install and activate RankSage Connect.
2. Go to **Settings → RankSage**.
3. Click **Connect to RankSage** and sign in (or create a free account).
4. You are returned to WordPress connected. Both capabilities are on by default and can be toggled independently.

You can also start from your RankSage dashboard: **Integrations → WordPress** will walk you through connecting, and can install this plugin for you if your host allows plugin installs from the dashboard.

== Frequently Asked Questions ==

= Does this plugin slow down my site? =

On an ordinary page request the plugin performs one lowercase conversion and one substring search over the user-agent, and then returns. There is no database query and no network call. Only when the user-agent matches a known AI crawler does it write a single row — and that write is deferred until after the response has been sent to the visitor. Delivery to RankSage happens on a schedule, never during a page load.

= Do I need a paid RankSage account? =

No. A free RankSage account is enough to connect and see AI-crawler data.

= Does it track my human visitors? =

The AI-crawler capture feature records **only** requests from known AI crawler user-agents. It never records a human visitor. The separate tracking tag is standard RankSage analytics and is governed by your RankSage account settings — turn it off in the plugin settings if you only want AI-crawler data.

= What happens if I deactivate or delete the plugin? =

Deactivating stops all sending and removes the scheduled job. Deleting the plugin removes its options and drops its buffer table completely.

= Why does it say my AI-crawler coverage is degraded? =

Either a full-page cache was detected — it serves pages without running PHP, so the plugin cannot observe those requests, and the settings screen lists the exact exclusion steps for it — or a delivery to RankSage failed, or buffered hits had to be dropped because RankSage stayed unreachable long enough for the local buffer to hit its 5,000-row cap. The settings screen names which one it is.

= Does the buffer grow forever if RankSage is unreachable? =

No. The buffer is capped at 5,000 rows, and the cap is applied on every flush attempt, including while the plugin is backing off from a failed delivery. Beyond the cap the oldest hits are dropped, the count of dropped hits is shown in the settings screen, and the delivery error that caused it is shown next to it rather than being overwritten.

== Changelog ==

= 1.1.0 =
* Serves the site's IndexNow key file at `/<key>.txt` (issued by RankSage on connect, refreshed with the daily signed config) so RankSage can notify Bing, Yandex, DuckDuckGo, Naver and Seznam when pages change. Still zero outbound requests on front-end page loads.

= 1.0.0 =
* Initial release: RankSage tracking tag injection, AI-crawler capture with buffered batch delivery, page-cache detection with per-cache fix instructions, and the `ranksage/v1` status/connect/disconnect REST namespace.

== Upgrade Notice ==

= 1.1.0 =
Adds IndexNow key-file hosting. Reconnect is not required: the key arrives with the next daily config pull.

= 1.0.0 =
Initial release.
