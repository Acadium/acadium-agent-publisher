#!/usr/bin/env bash
# Build dist/acadium-agent-publisher.zip: the package uploaded to WordPress.org
# (https://wordpress.org/plugins/developers/add/) or installed via
# Plugins > Add New > Upload Plugin. Contains only the acadium-agent-publisher/ folder.
set -euo pipefail
cd "$(dirname "$0")/.."
version=$(sed -n 's/^ \* Version:[[:space:]]*//p' acadium-agent-publisher/acadium-agent-publisher.php)
stable=$(sed -n 's/^Stable tag:[[:space:]]*//p' acadium-agent-publisher/readme.txt)
if [[ "$version" != "$stable" ]]; then
	echo "Version mismatch: plugin header $version, readme Stable tag $stable" >&2
	exit 1
fi
# Bundled dependencies (MCP Adapter + Jetpack Autoloader), exactly as pinned
# in composer.lock. Uses Docker so no local PHP/Composer is needed.
rm -rf acadium-agent-publisher/vendor
docker run --rm -v "$PWD/acadium-agent-publisher":/app -w /app composer:2 \
	install --no-dev --no-interaction --no-progress --optimize-autoloader >/dev/null

mkdir -p dist
rm -f dist/acadium-agent-publisher.zip
# Deterministic-ish: no macOS metadata, no extra attributes.
COPYFILE_DISABLE=1 zip -rqX dist/acadium-agent-publisher.zip acadium-agent-publisher -x '*.DS_Store'
unzip -l dist/acadium-agent-publisher.zip | tail -3
echo "Built dist/acadium-agent-publisher.zip (version $version)"
