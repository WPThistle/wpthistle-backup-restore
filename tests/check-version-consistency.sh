#!/usr/bin/env bash
# Fails the build when the plugin's current-version sources disagree.
# README.md's Status line has drifted stale twice now (fixed once in
# afaa987, found stale again at HEAD two releases later) -- this is the
# automated guard requested after the second occurrence, not a
# hypothetical safeguard.
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

PLUGIN_FILE="plugin/wpthistle-backup-restore/wpthistle-backup-restore.php"
README_TXT="plugin/wpthistle-backup-restore/readme.txt"
README_MD="README.md"
STATUS_FILE="STATUS.md"

SEMVER='[0-9]+\.[0-9]+\.[0-9]+'
plugin_header=$(grep -oP "^\s*\*\s*Version:\s*\K$SEMVER" "$PLUGIN_FILE")
constant=$(grep -oP "define\(\s*'WPTHISTLE_BACKUP_VERSION',\s*'\K$SEMVER" "$PLUGIN_FILE")
stable_tag=$(grep -oP "^Stable tag:\s*\K$SEMVER" "$README_TXT")
status_line=$(grep -oP "^\*\*Status:\*\*\s*v\K$SEMVER" "$README_MD")
changelog_top=$(grep -oP "^= \K$SEMVER(?= =\$)" "$README_TXT" | head -1)
status_top=$(grep -oP "^## \K$SEMVER" "$STATUS_FILE" | head -1)

echo "plugin header Version:        $plugin_header"
echo "WPTHISTLE_BACKUP_VERSION:     $constant"
echo "readme.txt Stable tag:        $stable_tag"
echo "README.md Status line:        $status_line"
echo "readme.txt top changelog ('=  X.Y.Z ='): $changelog_top"
echo "STATUS.md top heading ('## X.Y.Z'):      $status_top"

if [ "$plugin_header" != "$constant" ] || [ "$plugin_header" != "$stable_tag" ] \
  || [ "$plugin_header" != "$status_line" ] || [ "$plugin_header" != "$changelog_top" ] \
  || [ "$plugin_header" != "$status_top" ]; then
  echo "Version mismatch -- bump every source to the same version before releasing." >&2
  exit 1
fi

echo "All version sources agree: $plugin_header"
