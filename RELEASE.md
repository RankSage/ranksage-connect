# Releasing RankSage Connect to WordPress.org

## The normal path: GitHub Actions (since 3 Oct 2026)

Publishing is a pipeline, not a laptop task. `.github/workflows/deploy-wordpress-org.yml` runs when a
`vX.Y.Z` tag is pushed (or by hand for an existing tag), checks that `Version:`, `RANKSAGE_CONNECT_VERSION`
and `Stable tag` all equal the tag, builds the zip with `build-zip.sh` as a gate, and hands the tree to
`10up/action-wordpress-plugin-deploy` (pinned to a commit), which syncs `trunk/` minus `.distignore`,
copies `.wordpress-org/` (when it exists) to SVN `assets/`, creates `tags/X.Y.Z` and commits.

Directory assets go in `.wordpress-org/` at the repo root — create the folder only when the images exist, because
everything in it is published to SVN `assets/`. Filenames per the WordPress.org spec, all lowercase: `icon-128x128.png`,
`icon-256x256.png` (optionally `icon.svg` plus the PNG fallback), `banner-772x250.png`, `banner-1544x500.png`,
`screenshot-1.png` … `screenshot-4.png` numbered to match `== Screenshots ==` in `readme.txt`. Limits: icon 1 MB, banner
4 MB, screenshot 10 MB. The directory can take up to ~6 hours to show changed images.

Credentials: repository secrets `SVN_USERNAME` and `SVN_PASSWORD` (the WordPress.org **SVN password**,
generated at profiles.wordpress.org → Account & Security → SVN password; never the login password). They
live only in GitHub's encrypted secret store and are redacted from logs. Rotate by regenerating on
WordPress.org and updating the one secret.

Gates that protect a release:
- the job targets the `wordpress-org` environment: a required reviewer (the owner) must approve before
  the secrets are released to it, and only `v*` tags / `main` may deploy;
- the action refuses to re-publish a version whose SVN tag already exists;
- turn on WordPress.org **Release Confirmations** for the plugin so every new tag also needs an emailed
  confirmation — a stolen SVN password then cannot ship alone.

To release:
1. Bump the three version fields, add the changelog entry, open a PR to `main`, merge.
2. Create the GitHub release with tag `vX.Y.Z` (UI or `gh release create vX.Y.Z --generate-notes`).
3. Approve the waiting deployment under Actions → the run → Review deployments.
4. Click the Release Confirmation email. Sites see the update within hours.

For an already-existing tag (first publish, or a re-run): Actions → "Deploy to WordPress.org" → Run
workflow → tag `vX.Y.Z` (tick "dry run" first to rehearse without committing).

## Manual fallback (only if Actions is unavailable)

Run by the plugin owner (`ranksage` on WordPress.org) after the Plugins Team approves the submission. SVN asks for
the WordPress.org password once per session — nobody else can run this.

### 0. Prerequisites (once)

```bash
brew install svn                      # macOS
svn --version
```

The approval email names the repository: `https://plugins.svn.wordpress.org/ranksage-connect/`.

### 1. Build the exact zip you will publish

```bash
git checkout v1.2.1                   # the tag you are releasing
./build-zip.sh                        # refuses on any version or header mismatch
unzip -l dist/ranksage-connect.zip    # 18 files, top-level folder ranksage-connect/
```

### 2. Check out the (empty) SVN repository

```bash
cd "$(mktemp -d)"
svn checkout https://plugins.svn.wordpress.org/ranksage-connect/ svn --username ranksage
cd svn
ls                                    # assets/  branches/  tags/  trunk/
```

### 3. Copy the release into trunk and tag it

```bash
PLUGIN_SRC="/path/to/ranksage-connect/dist/ranksage-connect"   # unzip dist/ranksage-connect.zip first
rm -rf trunk/* && cp -R "$PLUGIN_SRC"/. trunk/
svn add --force trunk/*
svn status                            # only A (added) / M (modified) lines, nothing unexpected
svn commit -m "1.2.1 — initial release" --username ranksage
svn copy trunk tags/1.2.1
svn commit -m "Tag 1.2.1" --username ranksage
```

`readme.txt` `Stable tag: 1.2.1` tells the directory to serve `tags/1.2.1`. Never edit a tag after it is published —
bump the version, update trunk, copy a new tag.

### 4. Directory assets (icon, banner, screenshots)

```bash
# assets/ is NOT part of the plugin zip; it feeds the directory page only.
cp /path/to/icon-256x256.png   assets/icon-256x256.png      # also icon-128x128.png
cp /path/to/banner-1544x500.png assets/banner-1544x500.png  # also banner-772x250.png
cp /path/to/screenshot-1.png   assets/                      # numbered to match readme.txt == Screenshots ==
svn add --force assets/*
svn propset svn:mime-type image/png assets/*.png
svn commit -m "Directory assets" --username ranksage
```

Screenshot captions come from `readme.txt` (`== Screenshots ==`, one numbered line per file).

### 5. Verify

- https://wordpress.org/plugins/ranksage-connect/ shows version 1.2.1 and the assets (allow ~15 minutes).
- Install from a WordPress site's Plugins › Add New and connect once.

### Later releases

1. Bump the three version fields (`Version:` header, `RANKSAGE_CONNECT_VERSION`, `Stable tag`), add a changelog entry.
2. `./build-zip.sh`, tag `vX.Y.Z` in git, GitHub release with the zip.
3. Repeat step 3 with the new tag name. The directory picks up the new `Stable tag` within minutes.
