=== RankSage Connect ===
Contributors: ranksage
Tags: seo, ai search, content optimization, site analytics, ai crawlers
Requires at least: 6.3
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.2.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Your SEO tools tell you what happened. RankSage tells you what to fix next, page by page. This plugin connects your WordPress site.

== Description ==

You already have the data. Search Console shows what people search for. Analytics shows what visitors do. An SEO plugin grades each post. Somewhere, an AI answer engine is describing your business to a buyer, and nothing on your site records it. Each tool is right about its own column. None of them is the page.

[RankSage](https://www.ranksage.com) joins every signal that decides whether a page gets found, on one row, the page: search demand, what AI answers say about you, content quality, competitors, technical health and visitor behaviour. Out comes one ranked list of what to change, with the evidence behind each item, and a check afterwards on whether the change worked.

RankSage Connect is the WordPress side of that join. Connect once and your site contributes the signals no browser script can collect, and receives the fixes and index pings RankSage sends back.

= What changes for you =

* **You see the visits analytics cannot.** ChatGPT, Perplexity, Claude and Google's AI crawlers fetch your HTML without running JavaScript, so Google Analytics never counts them. This plugin records each crawler visit on your server, page by page, and RankSage lines it up against the pages those engines actually cite. A page that is read every week and never cited is a fixable gap, and RankSage names the fix.
* **You get one list instead of five dashboards.** RankSage ranks what to fix across your whole site, from a thin answer block on a high-traffic page to a crawler your host is blocking, and puts the evidence under each item. You open the top one and do it.
* **Your changes reach search engines faster.** The plugin serves your IndexNow key, so RankSage can tell Bing, Yandex, Naver and Seznam the moment a page is published or changed.
* **You keep your SEO plugin.** Yoast SEO, Rank Math and All in One SEO edit what your pages say to search engines. RankSage Connect edits nothing. It measures, and it runs alongside any of them.
* **Visitor behaviour is optional and yours to switch off.** The tracking tag adds one small script that reports where visitors arrive, where they stall and what they click, so RankSage can show which pages win attention and which lose it. Turn it off and keep crawler data only.

= Numbers you can check =

Every score RankSage shows is derived from stored evidence you can open. When a check cannot run, it says so. If a page cache or CDN is hiding part of your crawler traffic, the settings screen tells you which one and gives the exclusion steps for it, so you read a count you can trust rather than one that quietly undercounts.

= Who it is for =

Founders, marketers and agencies who run WordPress sites and want to know what to fix next without stitching exports together. You do not need to be technical. Connect, then read the list.

= Two minutes to connect =

1. Install and activate the plugin.
2. Go to **Settings → RankSage** and click **Connect to RankSage**. Sign in or create a free account.
3. You are sent back to WordPress, connected. Crawler capture and the optional tracking tag are on and can each be switched off at any time.

The plugin sends nothing anywhere until you click Connect. Disconnect from the same screen and everything stops at once.

= How crawler capture works, and where it stops =

AI crawlers never run JavaScript, so the only place their visit exists is on your server, before any page cache answers. The plugin takes that record on the request itself, buffers it locally and sends it to RankSage on a schedule. A normal page view costs one user-agent check.

Full-page caches serve pages without running PHP, and no plugin can see those requests. RankSage Connect recognises WP Rocket, LiteSpeed Cache, W3 Total Cache, WP Super Cache, SiteGround Optimizer, WP Engine, Kinsta and WordPress VIP and gives you the exclusion steps for each. Edge caches such as Cloudflare cannot be seen from PHP at all. For complete coverage behind an edge, RankSage offers a Cloudflare Worker snippet that runs at the edge itself.

= Privacy and what is sent =

We would rather over-explain than surprise you. The plugin contacts no server until you connect. After you connect it talks only to RankSage, the crawler feature never records a human visitor, and no RankSage secret is stored on your site. The complete disclosure of every request, what it contains and when it happens follows. It is long on purpose.


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
3. Click **Connect to RankSage** and sign in, or create a free account.
4. You are returned to WordPress connected. Crawler capture and the tracking tag are on by default and can be toggled independently.

You can also start from your RankSage dashboard: **Integrations → WordPress** walks you through connecting and can install this plugin for you if your host allows plugin installs from the dashboard.

== Frequently Asked Questions ==

= Do I need a paid RankSage account? =

No. A free RankSage account is enough to connect and see which AI crawlers read your pages.

= Will this slow down my site? =

No. On an ordinary page request the plugin does one user-agent check and returns. There is no database query and no network call. Only a request from a known AI crawler writes a single row, and that write happens after the page has already been sent to the visitor. Delivery to RankSage runs on a schedule, never during a page load.

= Does it track my human visitors? =

Crawler capture records only requests from known AI crawler user-agents and never records a human visitor. The optional tracking tag is RankSage visitor analytics; switch it off under Settings → RankSage if you want crawler data only. The full list of what the tag collects is in the Description under "Privacy and what is sent".

= Does the tracking tag fingerprint my visitors? =

It collects a canvas hash and the graphics renderer name to tell real browsers from bots, and those signals can act as a device fingerprint. They are skipped for visitors whose browser sends Do Not Track. Turn the tag off if you do not want this, or load it only after consent where your visitors' jurisdiction requires it.

= What data does the plugin send, and how do I stop it? =

Nothing at all until you connect. Afterwards: AI-crawler visits (crawler user-agent, path and timestamp, never human visitors), a daily configuration request, and, while the tracking tag is on, the visitor analytics described in the Description, sent by your visitors' browsers. To stop only visitor analytics, untick the tracking tag. To stop everything, click **Disconnect from RankSage**. The tag is removed, scheduled sends are cancelled and the stored keys are deleted from your site immediately. To delete data RankSage already holds, remove the site in your RankSage dashboard or contact RankSage as described in the privacy policy.

= Does it work with Yoast SEO, Rank Math or All in One SEO? =

Yes. RankSage Connect does not touch your content or SEO settings. It measures crawler visits and feeds RankSage, and it runs alongside any SEO plugin.

= Why does it say my crawler coverage is degraded? =

Either a full-page cache was detected (it serves pages without running PHP, so the plugin cannot see those requests, and the settings screen lists the exclusion steps for your cache), or a delivery to RankSage failed, or buffered hits had to be dropped because RankSage stayed unreachable long enough for the local buffer to reach its 5,000-row cap. The settings screen names which one it is.

= Does the buffer grow forever if RankSage is unreachable? =

No. It is capped at 5,000 rows, the cap applies on every delivery attempt, the oldest hits are dropped beyond it, and the settings screen shows how many were dropped and why.

= What happens if I deactivate or delete the plugin? =

Deactivating stops all sending and removes the scheduled jobs. Deleting removes its options, the per-user notice setting and its buffer table completely.

== Screenshots ==

1. Settings → RankSage before connecting: what the plugin will and will not send, and the Connect button.
2. Connected: per-capability status for the tracking tag, crawler capture, buffered hits, last successful send, configuration source and last error.
3. A page cache was detected: capture is marked degraded and the exact exclusion steps for that cache plugin are listed.
4. The two independent toggles (tracking tag, crawler capture) and Disconnect.

== Changelog ==

The full history lives in the repository: https://github.com/RankSage/ranksage-connect/blob/main/CHANGELOG.md

= 1.2.2 =
* Directory listing rewritten around what RankSage does for a site owner. The complete data disclosure is kept under "Privacy and what is sent". No code change, no reconnect needed.

= 1.2.1 =
* "Tested up to" is now declared only in readme.txt, as the directory expects. No functional change.

== Upgrade Notice ==

= 1.2.2 =
Listing text only. No functional change, no reconnect needed.
