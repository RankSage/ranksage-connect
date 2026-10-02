#!/usr/bin/env bash
#
# WHAT: Builds the wordpress.org submission ZIP for ranksage-connect.
# HOW:  Copies only the files that belong in a release into a clean staging dir,
#       then zips it with the plugin slug as the single top-level folder — which
#       is the structure wordpress.org requires.
# WHY:  Zipping the working directory ships .git, editor files and any local
#       scratch work into a public release, and a wrong top-level folder name is
#       a straight rejection. Doing it by hand is how both happen.
# NOTE: Refuses to build if the plugin still carries the burned development
#       signing key — that key's private half was exposed in a session
#       transcript, and it is the trust anchor the plugin verifies backend
#       config against. Publishing it would ship a known-compromised anchor to
#       every installing site, permanently, in the release history.
#
set -euo pipefail

SLUG="ranksage-connect"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
OUT="${HERE}/dist"
STAGE="${OUT}/${SLUG}"

# The public half of the compromised dev keypair. If this is still compiled in,
# the private half in circulation can forge configuration the plugin trusts.
BURNED_KEY="a0db4eea7070119db142c7814ed47cf8375417fd0d20ed80267ba239e769e955"

if grep -q "${BURNED_KEY}" "${HERE}/${SLUG}.php"; then
  echo "REFUSING TO BUILD — the development signing key is still compiled in." >&2
  echo "" >&2
  echo "  That key's PRIVATE half was exposed in a session transcript. It is the" >&2
  echo "  trust anchor verify_payload() checks backend configuration against, so" >&2
  echo "  shipping it lets anyone holding that private half forge config every" >&2
  echo "  installed site will accept as authentic." >&2
  echo "" >&2
  echo "  Rotate first:" >&2
  echo "    cd ranksage-backend && npx tsx scripts/generate-wp-signing-key.ts" >&2
  echo "  then put the PRIVATE half on Railway as WP_PLUGIN_SIGNING_PRIVATE_KEY" >&2
  echo "  and the PUBLIC half into RANKSAGE_CONNECT_SIGNING_PUBLIC_KEY here," >&2
  echo "  in one commit, and re-run this script." >&2
  exit 1
fi

rm -rf "${OUT}"
mkdir -p "${STAGE}"

# Allow-list, not deny-list: a new stray file is excluded by default rather than
# silently published.
cp "${HERE}/${SLUG}.php"  "${STAGE}/"
cp "${HERE}/readme.txt"   "${STAGE}/"
cp "${HERE}/uninstall.php" "${STAGE}/"
# GPL-2.0 text travels with the code — wordpress.org expects the licence in the zip.
cp "${HERE}/LICENSE"      "${STAGE}/"
cp -R "${HERE}/includes"  "${STAGE}/"
cp -R "${HERE}/admin"     "${STAGE}/"

# Belt and braces: nothing that could carry local state.
find "${STAGE}" \( -name '.DS_Store' -o -name '*.log' -o -name '.env*' -o -name '*.map' \) -delete

header_field() { grep -m1 "^ \* $1:" "${HERE}/${SLUG}.php" | sed -E "s/^ \* $1:[[:space:]]*//" | tr -d '\r'; }
readme_field() { grep -m1 "^$1:" "${HERE}/readme.txt" | sed -E "s/^$1:[[:space:]]*//" | tr -d '\r'; }

VERSION="$(header_field 'Version')"
CONST_VERSION="$(grep -m1 "define( 'RANKSAGE_CONNECT_VERSION'" "${HERE}/${SLUG}.php" | sed -E "s/.*'([0-9.]+)'.*/\1/")"
README_STABLE="$(readme_field 'Stable tag')"

# wordpress.org serves whatever `Stable tag` points at, and the plugin reports
# RANKSAGE_CONNECT_VERSION to RankSage. Any disagreement ships the wrong code or
# lies about which code is running.
if [ "${VERSION}" != "${README_STABLE}" ] || [ "${VERSION}" != "${CONST_VERSION}" ]; then
  echo "REFUSING TO BUILD — version mismatch." >&2
  echo "  ${SLUG}.php Version:          ${VERSION}" >&2
  echo "  RANKSAGE_CONNECT_VERSION:     ${CONST_VERSION}" >&2
  echo "  readme.txt Stable tag:        ${README_STABLE}" >&2
  exit 1
fi

# The header and the readme must agree on the compatibility window, or the
# directory listing and the installer disagree about who can run the plugin.
for field in 'Requires at least' 'Requires PHP'; do
  if [ "$(header_field "${field}")" != "$(readme_field "${field}")" ]; then
    echo "REFUSING TO BUILD — '${field}' differs between ${SLUG}.php and readme.txt." >&2
    exit 1
  fi
done

# 'Tested up to' is a readme field only (wordpress.org review, 28 Sep 2026): a copy in
# the plugin header can override the readme on the directory page. Readme must have it,
# the header must not.
if [ -n "$(header_field 'Tested up to')" ]; then
  echo "REFUSING TO BUILD — 'Tested up to' belongs in readme.txt only; remove it from ${SLUG}.php." >&2
  exit 1
fi
if [ -z "$(readme_field 'Tested up to')" ]; then
  echo "REFUSING TO BUILD — readme.txt has no 'Tested up to' line." >&2
  exit 1
fi

# Syntax-check every shipped PHP file when a PHP binary is available. Missing PHP
# is reported, not silently skipped.
if command -v php >/dev/null 2>&1; then
  while IFS= read -r -d '' php_file; do
    php -l "${php_file}" >/dev/null || { echo "REFUSING TO BUILD — php -l failed: ${php_file}" >&2; exit 1; }
  done < <(find "${STAGE}" -name '*.php' -print0)
  echo "php -l: every PHP file passed"
else
  echo "WARNING: php not on PATH — skipped php -l syntax check" >&2
fi

( cd "${OUT}" && zip -rq "${SLUG}.zip" "${SLUG}" )
rm -rf "${STAGE}"

echo "Built ${OUT}/${SLUG}.zip (version ${VERSION})"
echo "Top-level folder inside the zip: ${SLUG}/  — required by wordpress.org"
unzip -l "${OUT}/${SLUG}.zip"
