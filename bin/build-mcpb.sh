#!/usr/bin/env bash
# Build dist/acadium-agent-publisher.mcpb: the Claude Desktop extension
# (MCP Bundle, https://github.com/modelcontextprotocol/mcpb). Claude Desktop
# installs it when the file is opened and ships its own Node.js, so users
# install nothing and edit no JSON. The bridge version is pinned in
# desktop-extension/package.json (+ package-lock.json); the bundle version
# follows the plugin's.
set -euo pipefail
cd "$(dirname "$0")/.."
version=$(sed -n 's/^ \* Version:[[:space:]]*//p' acadium-agent-publisher/acadium-agent-publisher.php)
mcpb="npx -y @anthropic-ai/mcpb@2.1.2"

build=$(mktemp -d)
trap 'rm -rf "$build"' EXIT
cp desktop-extension/manifest.json desktop-extension/package.json desktop-extension/package-lock.json "$build/"
[[ -f desktop-extension/icon.png ]] && cp desktop-extension/icon.png "$build/"
(cd "$build" && npm ci --omit=dev --no-audit --no-fund --silent)
# Version from the plugin header.
node -e 'const f=process.argv[1],v=process.argv[2],m=JSON.parse(require("fs").readFileSync(f));m.version=v;require("fs").writeFileSync(f,JSON.stringify(m,null,2)+"\n")' "$build/manifest.json" "$version"

$mcpb validate "$build/manifest.json"
mkdir -p dist
rm -f dist/acadium-agent-publisher.mcpb
$mcpb pack "$build" dist/acadium-agent-publisher.mcpb >/dev/null
ls -l dist/acadium-agent-publisher.mcpb | awk '{print $5" bytes"}'
echo "Built dist/acadium-agent-publisher.mcpb (version $version)"
