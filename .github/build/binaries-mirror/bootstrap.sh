#!/usr/bin/env bash
#
# Create the binaries mirror repository and start the first sync.
#
# Run this from your own machine, where `gh` is logged in as you — the panel
# repository's CI token is scoped to that repository and cannot create repos
# or push elsewhere.
#
#   sh build/binaries-mirror/bootstrap.sh
#
# It creates a PUBLIC repository (release assets in a private repo are not
# downloadable without a token, and the installer fetches them anonymously),
# adds the mirror workflow, and triggers the first run. Nothing from the panel
# repository is copied across: the mirror only ever holds upstream's binaries.

# Re-exec under bash when started as `sh script`: on Debian and Ubuntu /bin/sh
# is dash, which has no `set -o pipefail`, and the script dies on line one.
if [ -z "${BASH_VERSION:-}" ]; then exec bash "$0" "$@"; fi

set -euo pipefail

OWNER=${OWNER:-TalaveraSama}
NAME=${NAME:-chunklist-xc_vm-binaries}
SLUG="$OWNER/$NAME"

HERE=$(cd "$(dirname "$0")" && pwd)

command -v gh >/dev/null || { echo "gh (GitHub CLI) is required"; exit 1; }
gh auth status >/dev/null 2>&1 || { echo "Run 'gh auth login' first"; exit 1; }

echo "==> Target: $SLUG"

if gh repo view "$SLUG" >/dev/null 2>&1; then
  echo "    already exists — reusing it"
else
  gh repo create "$SLUG" --public \
    --description "Mirror of Vateron-Media/XC_VM_Binaries: per-distribution runtime binaries for the XC_VM panel"
  echo "    created"
fi

WORK=$(mktemp -d)
trap 'rm -rf "$WORK"' EXIT

echo "==> Adding the mirror workflow"
git clone -q "https://github.com/$SLUG.git" "$WORK/repo" 2>/dev/null || {
  mkdir -p "$WORK/repo" && git -C "$WORK/repo" init -q
  git -C "$WORK/repo" remote add origin "https://github.com/$SLUG.git"
}

cd "$WORK/repo"
mkdir -p .github/workflows
cp "$HERE/mirror.yml" .github/workflows/mirror.yml
cp "$HERE/README.md" README.md

git add .github/workflows/mirror.yml README.md
if git diff --cached --quiet; then
  echo "    already up to date"
else
  git -c user.name="$(gh api user --jq .login)" \
      -c user.email="$(gh api user --jq '.id')+$(gh api user --jq .login)@users.noreply.github.com" \
      commit -q -m "Mirror upstream XC_VM binaries"
  branch=$(git symbolic-ref --short HEAD 2>/dev/null || echo main)
  git push -q -u origin "HEAD:$branch"
  echo "    pushed"
fi

echo "==> Starting the first sync (this downloads ~435 MB on the runner)"
gh workflow run mirror.yml --repo "$SLUG" || {
  echo "    could not dispatch yet — GitHub needs a moment to register the workflow."
  echo "    Re-run:  gh workflow run mirror.yml --repo $SLUG"
  exit 0
}

echo
echo "Watch it:   gh run watch --repo $SLUG"
echo "Releases:   https://github.com/$SLUG/releases"
