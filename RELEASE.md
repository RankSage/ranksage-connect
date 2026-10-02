# Releasing RankSage Connect to WordPress.org (SVN)

Run by the plugin owner (`ranksage` on WordPress.org) after the Plugins Team approves the submission. SVN asks for
the WordPress.org password once per session — nobody else can run this.

## 0. Prerequisites (once)

```bash
brew install svn                      # macOS
svn --version
```

The approval email names the repository: `https://plugins.svn.wordpress.org/ranksage-connect/`.

## 1. Build the exact zip you will publish

```bash
git checkout v1.2.1                   # the tag you are releasing
./build-zip.sh                        # refuses on any version or header mismatch
unzip -l dist/ranksage-connect.zip    # 18 files, top-level folder ranksage-connect/
```

## 2. Check out the (empty) SVN repository

```bash
cd "$(mktemp -d)"
svn checkout https://plugins.svn.wordpress.org/ranksage-connect/ svn --username ranksage
cd svn
ls                                    # assets/  branches/  tags/  trunk/
```

## 3. Copy the release into trunk and tag it

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

## 4. Directory assets (icon, banner, screenshots)

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

## 5. Verify

- https://wordpress.org/plugins/ranksage-connect/ shows version 1.2.1 and the assets (allow ~15 minutes).
- Install from a WordPress site's Plugins › Add New and connect once.

## Later releases

1. Bump the three version fields (`Version:` header, `RANKSAGE_CONNECT_VERSION`, `Stable tag`), add a changelog entry.
2. `./build-zip.sh`, tag `vX.Y.Z` in git, GitHub release with the zip.
3. Repeat step 3 with the new tag name. The directory picks up the new `Stable tag` within minutes.
