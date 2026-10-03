# Changelog

All notable changes to RankSage Connect. The WordPress.org listing shows only the latest entries and links here.
Versions follow the plugin header, `RANKSAGE_CONNECT_VERSION` and `Stable tag` in `readme.txt`; each one is a git tag `vX.Y.Z` and an SVN tag.

## 1.2.2

* Directory listing rewritten around what RankSage does for a site owner. The complete data disclosure is kept under "Privacy and what is sent". Changelog history moved to this file. No code change, no reconnect needed.

## 1.2.1

* "Tested up to" is now declared only in readme.txt, as the directory expects; the plugin header no longer repeats it. No functional change.

## 1.2.0

* The tracking tag is now added with `wp_enqueue_script()` (deferred) instead of a hand-printed tag, so caching, optimisation and CSP plugins can see and manage it. Requires WordPress 6.3 or later.
* The tag now tells the tracking script where to send events (the RankSage API rather than your own site's `/e`, which does not exist on WordPress).
* The Connect button opens the RankSage dashboard at `app.ranksage.com`. RankSage hosts can be overridden from `wp-config.php` for staging environments, and the dashboard origin via the `ranksage_connect_app_base` filter.
* The page-cache warning now appears only on the Dashboard and Plugins screens and can be dismissed; it returns only if a different cache is detected.
* Status messages are now translatable; request data is sanitised throughout; readme documents every external service, including what the tracking script collects.

## 1.1.1

* Fixes the plugin's hosts: 1.1.0 pointed every request at `api.ranksage.io` / `app.ranksage.io` / `cdn.ranksage.io`, none of which resolve, so connect, config pulls and crawler-hit delivery could not succeed. The plugin now talks to `api.ranksage.com`, opens the dashboard at its live address, and loads the tracking tag from `www.ranksage.com/rs.js`. No settings change needed; reconnect once after updating.

## 1.1.0

* Serves the site's IndexNow key file at `/<key>.txt` (issued by RankSage on connect, refreshed with the daily signed config) so RankSage can notify Bing, Yandex, DuckDuckGo, Naver and Seznam when pages change. Still zero outbound requests on front-end page loads.

## 1.0.0

* Initial release: RankSage tracking tag injection, AI-crawler capture with buffered batch delivery, page-cache detection with per-cache fix instructions, and the `ranksage/v1` status/connect/disconnect REST namespace.
