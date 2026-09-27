#!/usr/bin/env bash
#
# Turn TalaveraSama/fork_xc_vm into this panel's distribution point, so
# installing and updating stop depending on Vateron-Media.
#
#   sh build/setup-fork.sh
#
# Run it from your own machine: the panel repository's CI token is scoped to
# that repository and can neither push elsewhere nor create releases there.
#
# It does two things:
#
#   1. Pushes a workflow that mirrors upstream's per-distribution binaries
#      into releases tagged `binaries-<upstream tag>`. That workflow runs in
#      the fork, so it publishes with the fork's own GITHUB_TOKEN and needs no
#      access token anywhere.
#
#   2. Copies this repository's newest panel release across as a release
#      tagged with the panel version, carrying xc_vm.tar.gz, XC_VM.zip and
#      hashes.md5 — the exact names the installer and the in-panel updater
#      look for.
#
# Why two kinds of release in one repository: the panel one must be tagged
# like a version, because the updater compares tags with PHP's
# version_compare. The binaries cannot share that namespace, so they get a
# `binaries-` prefix and are published as prereleases, which keeps them out
# of the updater's way. The installer knows to prefer a `binaries-` tag.

# Re-exec under bash when started as `sh script`: on Debian and Ubuntu /bin/sh
# is dash, which has no `set -o pipefail`, and the script dies on line one.
if [ -z "${BASH_VERSION:-}" ]; then exec bash "$0" "$@"; fi

set -euo pipefail

SOURCE=${SOURCE:-TalaveraSama/chunklist-xc_vm}
FORK=${FORK:-TalaveraSama/fork_xc_vm}
BRANCH=${BRANCH:-arena/01a0e18f-chunklist-xc-vm}

HERE=$(cd "$(dirname "$0")" && pwd)
REPO_ROOT=$(cd "$HERE/.." 2>/dev/null && pwd || echo "")

command -v gh >/dev/null || { echo "gh (GitHub CLI) is required"; exit 1; }
gh auth status >/dev/null 2>&1 || { echo "Run 'gh auth login' first"; exit 1; }

say() { printf '\n==> %s\n' "$*"; }

# This script is meant to be runnable on its own -- downloading one file beats
# cloning a repository whose working tree is well over a hundred megabytes. Any
# companion file it needs is fetched from the source repository on demand.
fetch_from_source() {
    # $1 = path in the repository, $2 = destination
    mkdir -p "$(dirname "$2")"
    if ! gh api "repos/$SOURCE/contents/$1?ref=$BRANCH" --jq '.content' 2>/dev/null \
         | base64 -d > "$2" 2>/dev/null || [ ! -s "$2" ]; then
        echo "    could not fetch $1 from $SOURCE" >&2
        return 1
    fi
}

need() {
    # $1 = path relative to build/, echoes a usable local path
    if [ -f "$HERE/$1" ]; then
        echo "$HERE/$1"
    else
        dest="$CACHE/$1"
        fetch_from_source "build/$1" "$dest" >&2 || return 1
        echo "$dest"
    fi
}

CACHE=$(mktemp -d)
trap 'rm -rf "$CACHE"' EXIT

say "Distribution repository: $FORK"
gh repo view "$FORK" >/dev/null 2>&1 || {
  echo "    $FORK does not exist. Create it public first:"
  echo "      gh repo create $FORK --public"
  exit 1
}
if [ "$(gh repo view "$FORK" --json isPrivate --jq .isPrivate)" = "true" ]; then
  echo "    $FORK is private. Release assets in a private repository are not"
  echo "    downloadable without a token, and the installer fetches them"
  echo "    anonymously, so it must be public."
  exit 1
fi

# ── 1. the binaries mirror workflow ──────────────────────────────
# Resolve the companion files BEFORE changing directory: once the shell is
# inside the freshly cloned fork, `gh` resolves that repository as the current
# context and reading from the private source repository stops working.
say "Collecting the workflow files"
MIRROR_YML=$(need binaries-mirror/mirror.yml)
MIRROR_README=$(need binaries-mirror/README.md)
if [ -n "$REPO_ROOT" ] && [ -f "$REPO_ROOT/Core/Config/AppConfig.php" ]; then
  APPCONFIG="$REPO_ROOT/Core/Config/AppConfig.php"
else
  APPCONFIG="$CACHE/AppConfig.php"
  fetch_from_source "Core/Config/AppConfig.php" "$APPCONFIG"
fi
VERSION=$(sed -nE "s/.*define\('XC_VM_VERSION', *'([^']+)'\).*/\1/p" "$APPCONFIG" | head -1)
[ -n "$VERSION" ] || { echo "    could not read XC_VM_VERSION"; exit 1; }
echo "    ok — panel version $VERSION"

say "Installing the binaries mirror workflow"
WORK=$(mktemp -d)
trap 'rm -rf "$WORK" "$CACHE"' EXIT

git clone -q "https://github.com/$FORK.git" "$WORK/repo" 2>/dev/null || {
  mkdir -p "$WORK/repo" && git -C "$WORK/repo" init -q
  git -C "$WORK/repo" remote add origin "https://github.com/$FORK.git"
}
cd "$WORK/repo"
mkdir -p .github/workflows
cp "$MIRROR_YML" .github/workflows/mirror.yml
cp "$MIRROR_README" README.md
git add .github/workflows/mirror.yml README.md
if git diff --cached --quiet 2>/dev/null; then
  echo "    already up to date"
else
  # Not every token can read /user (integration tokens cannot), and failing to
  # look up a display name is no reason to abandon the setup.
  login=$(gh api user --jq .login 2>/dev/null || echo "")
  if [ -n "$login" ]; then
    author_name="$login"
    author_mail="$(gh api user --jq .id 2>/dev/null || echo 0)+$login@users.noreply.github.com"
  else
    author_name="xc_vm fork setup"
    author_mail="noreply@users.noreply.github.com"
  fi
  git -c user.name="$author_name" -c user.email="$author_mail" \
      commit -q -m "Mirror upstream XC_VM binaries"
  git push -q -u origin "HEAD:$(git symbolic-ref --short HEAD 2>/dev/null || echo main)"
  echo "    pushed"
fi
cd "$REPO_ROOT"

say "Starting the first binaries sync (~435 MB on the runner)"
gh workflow run mirror.yml --repo "$FORK" 2>/dev/null \
  || echo "    GitHub has not registered the workflow yet — re-run: gh workflow run mirror.yml --repo $FORK"

# ── 2. the panel release ─────────────────────────────────────────
say "Publishing the panel as $FORK@$VERSION"

latest=$(gh release list --repo "$SOURCE" --limit 1 --json tagName --jq '.[0].tagName')
[ -n "$latest" ] || { echo "    $SOURCE has no releases to copy"; exit 1; }
echo "    source release: $latest"

mkdir -p "$WORK/assets"
gh release download "$latest" --repo "$SOURCE" --dir "$WORK/assets" \
  --pattern 'xc_vm.tar.gz' --pattern 'XC_VM.zip' --pattern 'hashes.md5'
ls -la "$WORK/assets"

if gh release view "$VERSION" --repo "$FORK" >/dev/null 2>&1; then
  echo "    updating existing release $VERSION"
else
  gh release create "$VERSION" --repo "$FORK" \
    --title "XC_VM $VERSION" \
    --notes "XC_VM $VERSION with the Flussonic module, built from \`$SOURCE\` \`$latest\`.

Install: \`unzip XC_VM.zip && sudo python3 install\`

This release is what the panel's own updater checks, so it will not pull
upstream's archive over the patches this fork carries." \
    --latest
fi
gh release upload "$VERSION" --repo "$FORK" "$WORK/assets"/* --clobber

say "Done"
echo "    Panel:    https://github.com/$FORK/releases/tag/$VERSION"
echo "    Binaries: https://github.com/$FORK/releases  (tagged binaries-*)"
echo
echo "    Watch the mirror:  gh run watch --repo $FORK"
