#!/usr/bin/env bash
# Builds the installable plugin zip: dist/freeform-fields-<version>.zip.
#
# Takes committed files from HEAD (honoring export-ignore in .gitattributes),
# adds freshly built scripts from build/, and checks that every version number matches.
# Set ALLOW_DIRTY=1 to build with uncommitted changes (they are NOT included).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SLUG="freeform-fields"
cd "$ROOT"

fail() { echo "Error: $*" >&2; exit 1; }

# 1. Versions must agree.
header="$(sed -n 's/^ \* Version: *//p' "$SLUG.php" | head -1)"
constant="$(sed -n "s/^define( 'WPFF_VERSION', '\(.*\)' );/\1/p" "$SLUG.php")"
stable="$(sed -n 's/^Stable tag: *//p' readme.txt)"
package="$(node -p "require('./package.json').version")"
for v in "$constant" "$stable" "$package"; do
	[ "$v" = "$header" ] || fail "version mismatch: header $header, WPFF_VERSION $constant, readme.txt $stable, package.json $package"
done
grep -q "^## \[$header\]" CHANGELOG.md || fail "CHANGELOG.md has no section for $header"
VERSION="$header"

# 2. The zip is built from HEAD, so warn about work that would be left out.
if [ -n "$(git status --porcelain)" ] && [ "${ALLOW_DIRTY:-}" != "1" ]; then
	fail "uncommitted changes would not be in the zip; commit them or set ALLOW_DIRTY=1"
fi

# 3. Fresh build of the scripts (build/ is not in git).
[ -d node_modules ] || npm ci
npm run build --silent >/dev/null
for f in builder.js blocks.js map.js builder.asset.php blocks.asset.php map.asset.php; do
	[ -f "build/$f" ] || fail "build/$f is missing after npm run build"
done

# 4. Assemble.
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
mkdir -p "$TMP/$SLUG"
git archive --format=tar HEAD | tar -x -C "$TMP/$SLUG"
cp -R build "$TMP/$SLUG/build"

# Sanity: no dev files, every PHP file parses.
for unwanted in tests node_modules vendor .github assets/src composer.json package.json; do
	[ ! -e "$TMP/$SLUG/$unwanted" ] || fail "$unwanted should not be in the zip"
done
while IFS= read -r -d '' php; do
	php -l "$php" >/dev/null || fail "syntax error in ${php#"$TMP/"}"
done < <(find "$TMP/$SLUG" -name '*.php' -print0)

mkdir -p dist
ZIP="$ROOT/dist/$SLUG-$VERSION.zip"
rm -f "$ZIP"
(cd "$TMP" && zip -qr -X "$ZIP" "$SLUG")

echo "Built dist/$SLUG-$VERSION.zip ($(du -h "$ZIP" | cut -f1), $(unzip -l "$ZIP" | tail -1 | awk '{print $2}') files)"
