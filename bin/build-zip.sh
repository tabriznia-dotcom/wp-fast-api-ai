#!/usr/bin/env bash
# Builds the release ZIP in dist/ai-page-designer.zip.
#
# Steps: production JS build, copy files not listed in .distignore, zip.
# The plugin needs no Composer or npm on the server: the ZIP contains the
# plugin's own autoloader, compiled assets in build/ and the readable sources in src/.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SLUG="ai-page-designer"
DIST="$ROOT/dist"
TARGET="$DIST/$SLUG"

cd "$ROOT"
if [ "${SKIP_JS_BUILD:-0}" != "1" ]; then
	npm run build
fi

rm -rf "$DIST"
mkdir -p "$TARGET"

EXCLUDES=()
while IFS= read -r line; do
	case "$line" in ''|'#'*) continue ;; esac
	EXCLUDES+=( "--exclude=./${line#/}" )
done < .distignore
tar -C "$ROOT" -cf - "${EXCLUDES[@]}" . | tar -C "$TARGET" -xf -

(cd "$DIST" && zip -qr "$SLUG.zip" "$SLUG")
echo "Created $DIST/$SLUG.zip ($(du -h "$DIST/$SLUG.zip" | cut -f1))"
