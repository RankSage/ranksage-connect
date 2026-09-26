# RankSage Connect — WordPress plugin

Connects a WordPress site to [RankSage](https://www.ranksage.com): AI-crawler visit capture (forwarded as metadata only),
the RankSage tracking tag, and IndexNow key serving. Nothing leaves the site until the administrator connects it.

- Plugin page: https://www.ranksage.com/integrations/wordpress
- WordPress.org listing: pending review (slug `ranksage-connect`)
- Privacy: https://www.ranksage.com/privacy · Terms: https://www.ranksage.com/terms
- Licence: GPL-2.0-or-later (see `LICENSE`)

## Requirements
WordPress 6.3+, PHP 7.4+. The plugin talks only to `api.ranksage.com`, opens the dashboard at `app.ranksage.com`, loads the
tracking tag from `www.ranksage.com`, and pings the IndexNow endpoints listed in `readme.txt` → *External services*.

## Development
```bash
php -l ranksage-connect.php            # syntax
composer global require wp-coding-standards/wpcs && phpcs --standard=WordPress .   # coding standards
./build-zip.sh                         # writes dist/ranksage-connect.zip (validates version headers, runs php -l)
```
Hosts are `defined()`-guarded constants in `ranksage-connect.php` and can be overridden in `wp-config.php`;
the app host also accepts the `ranksage_connect_app_base` filter (https only).

## Releasing
1. Bump `Version:` in `ranksage-connect.php`, `RANKSAGE_CONNECT_VERSION`, and `Stable tag:` in `readme.txt` (the build refuses a mismatch).
2. Add the changelog entry to `readme.txt`, run `./build-zip.sh`, tag `vX.Y.Z`.
3. After WordPress.org approval: commit to SVN `trunk/`, tag `tags/X.Y.Z/`, assets to `/assets`.

History before 27 Sep 2026 lived in the private RankSage monorepo under `packages/ranksage-connect` and was exported with `git subtree split`.
