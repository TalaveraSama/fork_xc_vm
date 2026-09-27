#!/bin/sh
#
# Build an installable module archive for the XC_VM admin panel.
#
#   Modules → Upload module → pick the .zip
#
# The panel's ModuleManager::uploadAndInstall() extracts the archive, reads
# `name` and `hash_id` from module.json, places the files under
# Modules/{name}_{hash_id:0:5}/, applies database.sql and runs install().
# Nothing here talks to the marketplace, so the archive installs offline on
# any XC_VM >= 2.3 regardless of how the panel itself was deployed.
#
# Usage:  sh package.sh [output-directory]      (default: ./dist)

set -e

MODULE_DIR=$(cd "$(dirname "$0")" && pwd)
MODULE_NAME=$(sed -n 's/.*"name"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' "$MODULE_DIR/module.json" | head -1)
VERSION=$(sed -n 's/.*"version"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' "$MODULE_DIR/module.json" | head -1)

if [ -z "$MODULE_NAME" ] || [ -z "$VERSION" ]; then
  echo "package.sh: cannot read name/version from module.json" >&2
  exit 1
fi

OUT_DIR=${1:-$MODULE_DIR/dist}
ARCHIVE="$OUT_DIR/${MODULE_NAME}_${VERSION}.zip"

# Staging copy so the archive contains a single clean `flussonic/` directory.
STAGE=$(mktemp -d)
trap 'rm -rf "$STAGE"' EXIT
mkdir -p "$STAGE/$MODULE_NAME"

# Everything the panel needs at runtime. `dev/` (the offline preview harness),
# build output and VCS noise are deliberately left out.
tar -c -C "$MODULE_DIR" \
  --exclude='dev' \
  --exclude='dist' \
  --exclude='.git*' \
  --exclude='package.sh' \
  --exclude='*.zip' \
  . | tar -x -C "$STAGE/$MODULE_NAME"

mkdir -p "$OUT_DIR"
rm -f "$ARCHIVE"
(cd "$STAGE" && zip -q -r -X "$ARCHIVE" "$MODULE_NAME")

echo "$ARCHIVE"
unzip -l "$ARCHIVE" | tail -1
