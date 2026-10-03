=== RankSage Connect ===
Contributors: ranksage
Tags: ai visibility, ai crawlers, chatgpt, seo, analytics
Requires at least: 6.3
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.2.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

See which AI crawlers read your pages and whether ChatGPT, Perplexity and Google AI Overviews mention your brand. Free RankSage account.

== Description ==

**Search has split in two.** Your visitors still arrive from Google, but more and more of them ask ChatGPT, Perplexity, Claude or Google AI Overviews first and never see a results page. Those answer engines read your site with their own crawlers, decide whether to mention you, and send you traffic only when they do. Standard analytics cannot see any of it.

RankSage Connect is the WordPress side of [RankSage](https://www.ranksage.com), the AI visibility and search platform for small teams. Install it, connect once, and your site starts answering the two questions every WordPress owner now has:

* **Are AI engines reading my pages?** Every visit from GPTBot, ClaudeBot, PerplexityBot, OAI-SearchBot and the other AI crawlers is recorded on your server, page by page, and shows up in your RankSage dashboard. Google Analytics and every other browser-based tool miss these completely, because AI crawlers never run JavaScript.
* **Are they mentioning my brand?** RankSage asks the questions your buyers ask across ten answer engines, stores the full answers as evidence, and tells you where you are cited, where a competitor is cited instead, and what to change next.

= What you get =

* **AI crawler visibility, page by page.** Which crawlers came, which pages they fetched, how often, and how that lines up with the pages that get cited in AI answers. If an engine reads your pricing page every week but never mentions you, that is a fixable gap — and RankSage shows you the fix.
* **One ranked list of what to do next.** RankSage joins AI answers, Google Search Console, GA4 and real visitor behaviour on one row per page, then turns the result into a single prioritised queue. No five dashboards, no guessing.
* **Faster indexing of new and changed pages.** The plugin serves your IndexNow key so RankSage can tell Bing, Yandex and other IndexNow engines the moment a page changes.
* **Honest numbers.** If a page cache or CDN is hiding part of your crawler traffic, the settings screen says so and shows the exact exclusion steps for your cache plugin, instead of showing you a count that quietly undercounts.
* **Works alongside your SEO plugin.** Yoast SEO, Rank Math, All in One SEO: keep them. RankSage Connect changes nothing about your titles, meta, sitemaps or schema. It measures what those tools cannot.
* **Light by design.** On a normal page view the plugin does one user-agent check and returns. No database query, no network call, nothing added to the page unless you turn the optional tracking tag on.

= Who it is for =

Founders, marketers and agencies who run WordPress sites and want to know whether the AI shift is sending them customers or sending them to a competitor. You do not need to be technical: connect, then read the dashboard.

= Set up in two minutes =

1. Install and activate the plugin.
2. Go to **Settings → RankSage** and click **Connect to RankSage**. Sign in or create a free account.
3. You are sent back to WordPress, connected. AI-crawler capture and the optional tracking tag are on by default and can each be switched off at any time.

Nothing is sent anywhere until you click Connect. Disconnect at any time from the same screen and everything stops immediately.

= Why a WordPress plugin is the only way to see AI crawlers =

AI crawlers fetch your HTML without running JavaScript, so no script-based analytics tool can record them. The only place the visit exists is on your server, before any page cache answers. This plugin takes that record, buffers it locally and ships it to your RankSage account on a schedule, never during a page load.

If your host uses a full-page cache, cached pages are served without running PHP, and no plugin can see those requests. RankSage Connect detects WP Rocket, LiteSpeed Cache, W3 Total Cache, WP Super Cache, SiteGround Optimizer, WP Engine, Kinsta, WordPress VIP and the generic `WP_CACHE` signal, and gives you the exclusion steps for each. Edge caches such as Cloudflare cannot be seen from PHP at all; for full coverage behind an edge, RankSage offers a Cloudflare Worker snippet.

= Privacy and what is sent =

We would rather over-explain than surprise you. The plugin contacts no server until you connect. After you connect it talks only to RankSage, never sends visitor personal data from the crawler feature, and never stores a RankSage secret on your site. The complete disclosure of every request, what it contains and when it happens is below; it is long on purpose.

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

1. Install and activate RankSage Connect from Plugins → Add New.
2. Go to **Settings → RankSage**.
3. Click **Connect to RankSage** and sign in (or create a free account).
4. You are returned to WordPress connected. The tracking tag and AI-crawler capture are on by default and can be toggled independently.

You can also start from your RankSage dashboard: **Integrations → WordPress** walks you through connecting and can install this plugin for you if your host allows plugin installs from the dashboard.

== Frequently Asked Questions ==

= Do I need a paid RankSage account? =

No. A free RankSage account is enough to connect and see which AI crawlers read your pages.

= Will this slow down my site? =

No. On an ordinary page request the plugin does one user-agent check and returns; there is no database query and no network call. Only a request from a known AI crawler writes a single row, and that write happens after the page has already been sent to the visitor. Delivery to RankSage runs on a schedule, never during a page load.

= Does it track my human visitors? =

AI-crawler capture records **only** requests from known AI crawler user-agents and never records a human visitor. The optional tracking tag is standard RankSage visitor analytics; switch it off under Settings → RankSage if you want crawler data only. The full list of what the tag collects is in the Description under "Privacy and what is sent".

= Does the tracking tag fingerprint my visitors? =

It collects a canvas hash and the graphics renderer name to tell real browsers from bots, and those signals can act as a device fingerprint. They are skipped for visitors whose browser sends Do Not Track. Turn the tag off if you do not want this, or load it only after consent where your visitors' jurisdiction requires it.

= What data does the plugin send, and how do I stop it? =

Nothing at all until you connect. Afterwards: AI-crawler visits (crawler user-agent, path and timestamp, never human visitors), a daily configuration request, and, while the tracking tag is on, the visitor analytics described in the Description, sent by your visitors' browsers. To stop only visitor analytics, untick the tracking tag. To stop everything, click **Disconnect from RankSage**: the tag is removed, scheduled sends are cancelled and the stored keys are deleted from your site immediately. To delete data RankSage already holds, remove the site in your RankSage dashboard or contact RankSage as described in the privacy policy.

= Does it work with Yoast SEO, Rank Math or All in One SEO? =

Yes. RankSage Connect does not touch your content or SEO settings. It measures AI-crawler visits and feeds RankSage; it runs alongside any SEO plugin.

= Why does it say my AI-crawler coverage is degraded? =

Either a full-page cache was detected (it serves pages without running PHP, so the plugin cannot see those requests, and the settings screen lists the exclusion steps for your cache), or a delivery to RankSage failed, or buffered hits had to be dropped because RankSage stayed unreachable long enough for the local buffer to reach its 5,000-row cap. The settings screen names which one it is.

= Does the buffer grow forever if RankSage is unreachable? =

No. It is capped at 5,000 rows, the cap applies on every delivery attempt, the oldest hits are dropped beyond it, and the settings screen shows how many were dropped and why.

= What happens if I deactivate or delete the plugin? =

Deactivating stops all sending and removes the scheduled jobs. Deleting removes its options, the per-user notice setting and its buffer table completely.

== Screenshots ==

1. Settings → RankSage before connecting: what the plugin will and will not send, and the Connect button.
2. Connected: per-capability status — tracking tag, AI-crawler capture, buffered hits, last successful send, configuration source and last error.
3. A page cache was detected: capture is marked degraded and the exact exclusion steps for that cache plugin are listed.
4. The two independent toggles (tracking tag, AI-crawler capture) and Disconnect.

== Changelog ==

= 1.2.2 =
* Directory listing rewritten: what the plugin does for you first, the full data disclosure kept under "Privacy and what is sent". No code change, no reconnect needed.

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

= 1.2.2 =
Listing text only. No functional change, no reconnect needed.

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
