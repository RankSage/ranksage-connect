=== RankSage Connect ===
Contributors: ranksage
Tags: ai crawlers, gptbot, analytics, seo, indexnow
Requires at least: 6.3
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.2.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect your site to RankSage: add the RankSage tracking tag and see which AI crawlers (GPTBot, ClaudeBot, PerplexityBot) read your pages.

== Description ==

RankSage Connect links your WordPress site to your [RankSage](https://www.ranksage.com) account. RankSage joins what AI answer engines say about your brand with Google Search Console, GA4 and first-party visitor behaviour on one row — the page — and returns a ranked list of what to change next. This plugin does three things for that join:

1. **Tracking tag** — adds the RankSage tracking script (`rs.js`) to your pages so RankSage can report on visits and behaviour. Can be turned off.
2. **AI-crawler capture** — records when an AI crawler (GPTBot, ClaudeBot, PerplexityBot, OAI-SearchBot, CCBot, Bytespider and similar) fetches one of your pages, and reports those visits to your RankSage account. Can be turned off.
3. **IndexNow key file** — serves your site's IndexNow key at `/<key>.txt`, so RankSage can tell Bing, Yandex and other IndexNow engines when your pages change.

AI crawlers fetch your HTML without running JavaScript. That means no browser-based analytics tool — including Google Analytics — can see them. A server-side record is the only way this data exists at all, and it is the data that tells you whether ChatGPT, Claude and Perplexity are actually reading your content.

**Nothing is sent anywhere until you connect a RankSage account.** An installed but unconnected plugin makes no outbound request of any kind and adds nothing to your pages.

= How this differs from an SEO plugin =

SEO plugins such as Yoast SEO, Rank Math or All in One SEO edit what your pages say to search engines — titles, meta, sitemaps, schema. They do not record which AI crawlers actually fetched which pages, and they cannot, because that requires a server-side record taken before any page cache answers. RankSage Connect does not touch your content or your SEO settings at all; it measures. It records AI-crawler fetches server-side, tells you honestly when a page cache is hiding part of that traffic (and how to fix it for your specific cache), and feeds the result into RankSage, where it sits next to your Search Console clicks, GA4 sessions and AI-answer citations for the same page. It runs happily alongside any SEO plugin.

= Honest note about page caches =

If your site uses a full-page cache, cached pages are served **without running PHP at all**. No WordPress plugin — this one included — can observe those requests.

**What this plugin can detect:** cache layers that announce themselves to PHP — WP Rocket, LiteSpeed Cache, W3 Total Cache, WP Super Cache, SiteGround Optimizer, WP Engine, Kinsta, WordPress VIP, and the generic `WP_CACHE` / `advanced-cache.php` signals. When one is found, the settings screen says so and gives you the exact exclusion steps for it.

**What this plugin cannot detect:** an edge or CDN cache in front of your server — Cloudflare, Fastly, a host's own edge tier. There is no reliable way to see those from PHP, because a request served at the edge never reaches your server at all. If you run one, assume your AI-crawler coverage is undercounted by however much the edge absorbs, and use the RankSage Cloudflare Worker snippet — that runs at the edge itself and is the only path to complete coverage.

We would rather tell you where the number is incomplete than show you one that quietly undercounts.

== External services ==

This plugin connects your site to **RankSage**, an external SaaS product operated by RankSage. It needs a RankSage account to do anything.

* Service: RankSage — https://www.ranksage.com
* Terms of Service: https://www.ranksage.com/terms
* Privacy Policy: https://www.ranksage.com/privacy

**Nothing below happens until you click "Connect to RankSage" and finish signing in.** Before that the plugin contacts no server and adds nothing to your pages. Disconnecting (Settings → RankSage → Disconnect) stops every item below immediately.

= 1. RankSage dashboard — app.ranksage.com (connect only) =

* **What:** the "Connect to RankSage" button opens `https://app.ranksage.com/wp-connect` in your browser.
* **Data sent:** your site's home URL, a one-time WordPress security token (nonce), and the address of this settings page so RankSage can send you back.
* **When:** only when an administrator clicks the button.

= 2. RankSage API — api.ranksage.com (server to server) =

All of these requests go from your web server to `https://api.ranksage.com`, never from your visitors' browsers.

* **Connection handshake** (`/api/v1/wordpress/wp-connect/exchange`): once, when you connect. Sends the single-use code RankSage gave you, your site URL, the plugin version and the name of any detected page-cache plugin. RankSage answers with a signed configuration containing your public tracking key, a site token and your IndexNow key.
* **Configuration pull** (`/api/v1/wordpress/plugin-config`): about once a day while connected. Sends the plugin version and your site URL (in the user-agent header) and the site token. The response (the AI-crawler user-agent list, batch sizes, a kill switch, your IndexNow key) is verified with an Ed25519 signature before use and can never change which server the plugin talks to.
* **AI-crawler hits** (`/api/v1/tracking/bot-hits`): only while "AI-crawler capture" is on. For each request whose user-agent matches a known AI crawler, the plugin stores the crawler's user-agent string, the requested path (query string removed) and a timestamp in a local table, and sends them in one batched request roughly every five minutes, identified by your public tracking key. **No visitor personal data, no IP addresses, no query strings, no post content.** Ordinary human traffic is never recorded by this feature.

= 3. RankSage tracking script — www.ranksage.com/rs.js (in your visitors' browsers) =

Only while "Tracking tag" is on. The plugin adds `https://www.ranksage.com/rs.js` to your front-end pages, with your public tracking key. Each visitor's browser downloads the script from `www.ranksage.com` and sends its events to `https://api.ranksage.com/e`. Events are encrypted in the browser before they are sent.

What the script collects about each visit:

* Pages viewed (full page address), the referring page, time on page, and scroll depth.
* Clicks — the clicked element's tag name, accessible label and the first 50 characters of its visible text, plus click position — and repeated or dead clicks.
* Form interaction timing — which field (by its `name` attribute) was focused and for how long, and whether the form was submitted or abandoned. **Field values are never read or sent.**
* Page performance (load timing, Core Web Vitals), JavaScript error messages (first 120 characters) and failed network request addresses (without query strings), file downloads, copy and print actions.
* Device and browser details: screen size and pixel ratio, browser languages, time-zone offset, platform, user-agent string, number of CPU cores and approximate device memory.
* **Browser fingerprint signals, used to tell real browsers from bots:** a hash of a small image drawn on an off-screen canvas, and the graphics-card (WebGL renderer) name. Together with the device details above these can act as a device fingerprint. They are **not** collected when the visitor's browser sends Do Not Track (`DNT: 1`), which switches the script to an aggregate-only mode with no fingerprint, no click and no form data.
* The visitor's IP address reaches RankSage as part of every web request; RankSage uses it only in hashed form for abuse and rate-limit checks.

What the script stores in the visitor's browser: one `localStorage` entry, `rs_consent`, written only when a visitor opts out, so the opt-out persists. The script sets no cookies. A site owner or consent manager can stop tracking for a visitor by setting `window.__RS_OPT_OUT__ = true` before the script loads, or by calling `window.RanksageTracker.optOut()`.

You are the data controller for your visitors' data. If your visitors are in a jurisdiction that requires consent for analytics or device fingerprinting (for example the EU/UK under GDPR and ePrivacy), load the tag only after consent, or turn "Tracking tag" off and keep AI-crawler capture only — that feature never touches human visitors.

= 4. IndexNow — search engines fetch your key file =

The plugin makes no IndexNow request itself. It answers `GET /<your-key>.txt` on your own site with your IndexNow key so that IndexNow-participating search engines (Bing, Yandex, Naver, Seznam and others) can confirm that RankSage is allowed to notify them — from RankSage's servers, via `https://api.indexnow.org` — when your pages change. The key is public by design. See https://www.indexnow.org/terms for the IndexNow terms.

= What is stored on your site =

Your RankSage *public* tracking key (the same key visible in your page source), the site token, your IndexNow key, your two toggle preferences, the local AI-crawler buffer table, and, per administrator, whether they dismissed the page-cache notice. No RankSage secret or password is ever written to your site. Deleting the plugin removes all of it.

= Security design =

* Every configuration payload RankSage sends is signed with Ed25519 and verified against a public key compiled into this plugin. An unsigned or mis-signed payload is rejected.
* The RankSage hostnames are compiled in and cannot be changed by any server response. A site owner can point them at a different RankSage environment only from `wp-config.php` (the `RANKSAGE_CONNECT_API_BASE`, `RANKSAGE_CONNECT_APP_BASE` and `RANKSAGE_CONNECT_SCRIPT_SRC` constants) or with the `ranksage_connect_app_base` filter. The plugin never downloads, evaluates, includes or writes executable code.
* All admin styles are bundled with the plugin. No CDN, no external fonts, no external scripts in wp-admin.
* Zero outbound HTTP requests happen on a front-end page request. Crawler hits are buffered locally and shipped on a schedule.

== Installation ==

1. Install and activate RankSage Connect.
2. Go to **Settings → RankSage**.
3. Click **Connect to RankSage** and sign in (or create a free account).
4. You are returned to WordPress connected. The tracking tag and AI-crawler capture are on by default and can be toggled independently.

You can also start from your RankSage dashboard: **Integrations → WordPress** will walk you through connecting, and can install this plugin for you if your host allows plugin installs from the dashboard.

== Frequently Asked Questions ==

= Does this plugin slow down my site? =

On an ordinary page request the plugin performs one lowercase conversion and one substring search over the user-agent, and then returns. There is no database query and no network call. Only when the user-agent matches a known AI crawler does it write a single row — and that write is deferred until after the response has been sent to the visitor. Delivery to RankSage happens on a schedule, never during a page load.

= What data does the plugin send, and how do I stop it? =

Nothing at all until you connect. After you connect: AI-crawler visits (crawler user-agent, path, timestamp — never human visitors), a daily configuration request, and — while the tracking tag is on — the visitor analytics described under External services, sent by your visitors' browsers. To stop only visitor analytics, untick "Add the RankSage tracking tag" under Settings → RankSage. To stop everything, click **Disconnect from RankSage** on the same screen: the tag is removed from your pages, scheduled sends are cancelled and the stored keys are deleted from your site immediately. To also delete data RankSage already holds, remove the site in your RankSage dashboard or contact RankSage as described in the privacy policy.

= Does the tracking tag fingerprint my visitors? =

It collects a canvas hash and the WebGL renderer name to distinguish real browsers from bots, and those signals can act as a device fingerprint. They are skipped for visitors whose browser sends Do Not Track. See External services for the full list, and turn the tag off if you do not want this.

= Do I need a paid RankSage account? =

No. A free RankSage account is enough to connect and see AI-crawler data.

= Does it track my human visitors? =

The AI-crawler capture feature records **only** requests from known AI crawler user-agents. It never records a human visitor. The separate tracking tag is standard RankSage analytics and is governed by your RankSage account settings — turn it off in the plugin settings if you only want AI-crawler data.

= What happens if I deactivate or delete the plugin? =

Deactivating stops all sending and removes the scheduled jobs. Deleting the plugin removes its options, the per-user notice setting and its buffer table completely.

= Why does it say my AI-crawler coverage is degraded? =

Either a full-page cache was detected — it serves pages without running PHP, so the plugin cannot observe those requests, and the settings screen lists the exact exclusion steps for it — or a delivery to RankSage failed, or buffered hits had to be dropped because RankSage stayed unreachable long enough for the local buffer to hit its 5,000-row cap. The settings screen names which one it is.

= Does the buffer grow forever if RankSage is unreachable? =

No. The buffer is capped at 5,000 rows, and the cap is applied on every flush attempt, including while the plugin is backing off from a failed delivery. Beyond the cap the oldest hits are dropped, the count of dropped hits is shown in the settings screen, and the delivery error that caused it is shown next to it rather than being overwritten.

== Screenshots ==

1. Settings → RankSage before connecting: what the plugin will and will not send, and the Connect button.
2. Connected: per-capability status — tracking tag, AI-crawler capture, buffered hits, last successful send, configuration source and last error.
3. A page cache was detected: capture is marked degraded and the exact exclusion steps for that cache plugin are listed.
4. The two independent toggles (tracking tag, AI-crawler capture) and Disconnect.

== Changelog ==

= 1.2.1 =
* "Tested up to" is now declared only in readme.txt, as the directory expects; the plugin header no longer repeats it. No functional change.

= 1.2.0 =
* The tracking tag is now added with `wp_enqueue_script()` (deferred) instead of a hand-printed tag, so caching, optimisation and CSP plugins can see and manage it. Requires WordPress 6.3 or later.
* The tag now tells the tracking script where to send events (the RankSage API rather than your own site's `/e`, which does not exist on WordPress).
* The Connect button opens the RankSage dashboard at `app.ranksage.com`. RankSage hosts can be overridden from `wp-config.php` for staging environments, and the dashboard origin via the `ranksage_connect_app_base` filter.
* The page-cache warning now appears only on the Dashboard and Plugins screens and can be dismissed; it returns only if a different cache is detected.
* Status messages are now translatable; request data is sanitised throughout; readme documents every external service, including what the tracking script collects.

= 1.1.1 =
* Fixes the plugin's hosts: 1.1.0 pointed every request at `api.ranksage.io` / `app.ranksage.io` / `cdn.ranksage.io`, none of which resolve, so connect, config pulls and crawler-hit delivery could not succeed. The plugin now talks to `api.ranksage.com`, opens the dashboard at its live address, and loads the tracking tag from `www.ranksage.com/rs.js`. No settings change needed; reconnect once after updating.

= 1.1.0 =
* Serves the site's IndexNow key file at `/<key>.txt` (issued by RankSage on connect, refreshed with the daily signed config) so RankSage can notify Bing, Yandex, DuckDuckGo, Naver and Seznam when pages change. Still zero outbound requests on front-end page loads.

= 1.0.0 =
* Initial release: RankSage tracking tag injection, AI-crawler capture with buffered batch delivery, page-cache detection with per-cache fix instructions, and the `ranksage/v1` status/connect/disconnect REST namespace.

== Upgrade Notice ==

= 1.2.1 =
Housekeeping release for the directory listing. No functional change, no reconnect needed.

= 1.2.0 =
Requires WordPress 6.3+. The tracking tag now loads through the WordPress script API, and the Connect button opens app.ranksage.com. No reconnect needed.

= 1.1.1 =
Required update: 1.1.0 could not reach RankSage at all (wrong hosts). Update, then click Connect once.

= 1.1.0 =
Adds IndexNow key-file hosting. Reconnect is not required: the key arrives with the next daily config pull.

= 1.0.0 =
Initial release.
