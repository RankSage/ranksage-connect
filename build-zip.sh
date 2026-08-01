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
cp -R "${HERE}/includes"  "${STAGE}/"
cp -R "${HERE}/admin"     "${STAGE}/"

# Belt and braces: nothing that could carry local state.
find "${STAGE}" \( -name '.DS_Store' -o -name '*.log' -o -name '.env*' -o -name '*.map' \) -delete

VERSION="$(grep -m1 '^ \* Version:' "${HERE}/${SLUG}.php" | awk '{print $3}')"
README_STABLE="$(grep -m1 '^Stable tag:' "${HERE}/readme.txt" | awk '{print $3}')"

# wordpress.org serves whatever `Stable tag` points at. A mismatch ships the
# wrong code, or nothing at all.
if [ "${VERSION}" != "${README_STABLE}" ]; then
  echo "REFUSING TO BUILD — version mismatch." >&2
  echo "  ${SLUG}.php Version:   ${VERSION}" >&2
  echo "  readme.txt Stable tag: ${README_STABLE}" >&2
  exit 1
fi

( cd "${OUT}" && zip -rq "${SLUG}.zip" "${SLUG}" )
rm -rf "${STAGE}"

echo "Built ${OUT}/${SLUG}.zip (version ${VERSION})"
echo "Top-level folder inside the zip: ${SLUG}/  — required by wordpress.org"
unzip -l "${OUT}/${SLUG}.zip" | tail -n +4 | head -20
