#!/usr/bin/env bash
# Writes dist/update.json: the manifest the plugin's updater reads from the latest GitHub release.
# Run after bin/build-zip.sh. The release workflow attaches it to the release next to the zip.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

VERSION="$(sed -n 's/^ \* Version: *//p' freeform-fields.php | head -1)"
[ -f "dist/freeform-fields-$VERSION.zip" ] || { echo "Error: dist/freeform-fields-$VERSION.zip is missing; run bin/build-zip.sh first" >&2; exit 1; }

VERSION="$VERSION" node <<'JS'
const fs = require('fs');
const version = process.env.VERSION;
const php = fs.readFileSync('freeform-fields.php', 'utf8');
const readme = fs.readFileSync('readme.txt', 'utf8');
const header = (src, name) => (src.match(new RegExp('^[ *]*' + name + ':\\s*(.+)$', 'm')) || [])[1]?.trim() || '';

// This version's section of CHANGELOG.md, without its "## [x.y.z]" heading.
const changelog = fs.readFileSync('CHANGELOG.md', 'utf8');
const start = changelog.indexOf(`## [${version}]`);
if (start < 0) throw new Error(`CHANGELOG.md has no section for ${version}`);
const rest = changelog.slice(changelog.indexOf('\n', start) + 1);
const end = rest.search(/^## \[/m);
const section = (end < 0 ? rest : rest.slice(0, end)).replace(/^\[[^\]]+\]:.*$/gm, '').trim();

const manifest = {
  version,
  download_url: `https://github.com/kiberberik/freeform-fields/releases/download/v${version}/freeform-fields-${version}.zip`,
  requires: header(php, 'Requires at least'),
  requires_php: header(php, 'Requires PHP'),
  tested: header(readme, 'Tested up to'),
  released: new Date().toISOString().slice(0, 10),
  changelog: section,
};
fs.writeFileSync('dist/update.json', JSON.stringify(manifest, null, 2) + '\n');
console.log(`Wrote dist/update.json for ${version}`);
JS
