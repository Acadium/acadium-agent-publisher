#!/usr/bin/env bash
# Build dist/agent-publisher.zip: the package uploaded to WordPress.org
# (https://wordpress.org/plugins/developers/add/) or installed via
# Plugins > Add New > Upload Plugin. Contains only the agent-publisher/ folder.
set -euo pipefail
cd "$(dirname "$0")/.."
version=$(sed -n 's/^ \* Version:[[:space:]]*//p' agent-publisher/agent-publisher.php)
stable=$(sed -n 's/^Stable tag:[[:space:]]*//p' agent-publisher/readme.txt)
if [[ "$version" != "$stable" ]]; then
	echo "Version mismatch: plugin header $version, readme Stable tag $stable" >&2
	exit 1
fi
mkdir -p dist
rm -f dist/agent-publisher.zip
# Deterministic-ish: no macOS metadata, no extra attributes.
COPYFILE_DISABLE=1 zip -rqX dist/agent-publisher.zip agent-publisher -x '*.DS_Store'
unzip -l dist/agent-publisher.zip
echo "Built dist/agent-publisher.zip (version $version)"
