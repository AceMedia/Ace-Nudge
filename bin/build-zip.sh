#!/usr/bin/env bash
# Build dist/ace-nudge-VERSION.zip containing a single ace-nudge/ folder, ready for Plugins > Add New > Upload.
set -euo pipefail
cd "$(dirname "$0")/.."
version=$(sed -n 's/^ \* Version: *//p' ace-nudge.php | head -1)
out="dist/ace-nudge-${version}.zip"
mkdir -p dist
rm -f "$out"
git archive --format=zip --prefix=ace-nudge/ -o "$out" HEAD
echo "$out"
