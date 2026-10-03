# Directory assets (not shipped in the plugin)

The deploy workflow copies this folder to the SVN `assets/` directory, which feeds the
wordpress.org/plugins/ranksage-connect page only. Expected files (PNG):

- `icon-128x128.png`, `icon-256x256.png`
- `banner-772x250.png`, `banner-1544x500.png`
- `screenshot-1.png` … `screenshot-4.png` — numbered to match `== Screenshots ==` in `readme.txt`

Until images exist here the directory page shows the default icon; nothing else is affected.
