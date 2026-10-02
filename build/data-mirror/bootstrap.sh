#!/usr/bin/env bash
#
# Create the two data mirror repositories and start their first sync.
#
# Run this from your own machine, where `gh` is logged in as you — the panel
# repository's CI token is scoped to that repository and can neither create
# repositories nor push anywhere else.
#
#   sh build/data-mirror/bootstrap.sh
#
# It creates two PUBLIC repositories, adds the mirror workflow to each, and
# triggers the first run:
#
#   <owner>/XC_VM_Update   GeoLite2 databases + blocked_asns.json.gz  (~80 MB)
#   <owner>/XC_VM_Proxy    proxy.tar.gz, the proxy-node archive
#
# The names are not free-form. mirror-data.yml derives upstream from the
# repository's own name, and Core/Config/AppConfig.php pairs GIT_OWNER_UPDATE
# and GIT_OWNER_PROXY with GIT_REPO_UPDATE / GIT_REPO_PROXY, which are
# 'XC_VM_Update' and 'XC_VM_Proxy'. Renaming either one breaks both ends.
#
# Public, for the same reason the binaries mirror is: the panel fetches these
# assets anonymously, and release assets in a private repository return 404
# without a token.

# Re-exec under bash when started as `sh script`: on Debian and Ubuntu /bin/sh
# is dash, which has no `set -o pipefail`, and the script dies on line one.
if [ -z "${BASH_VERSION:-}" ]; then exec bash "$0" "$@"; fi

set -euo pipefail

OWNER=${OWNER:-TalaveraSama}
HERE=$(cd "$(dirname "$0")" && pwd)

command -v gh >/dev/null || { echo "gh (GitHub CLI) is required"; exit 1; }
gh auth status >/dev/null 2>&1 || { echo "Run 'gh auth login' first"; exit 1; }

# `gh` is not always the GitHub CLI -- the gitsome package installs a command
# by the same name. Fail clearly here rather than three steps later.
gh api user --jq .login >/dev/null 2>&1 || {
  echo "That 'gh' does not speak to the GitHub API."
  echo "On Ubuntu 20.04 the gitsome package installs an unrelated 'gh'."
  echo "Install the real CLI: https://github.com/cli/cli#installation"
  exit 1
}

LOGIN=$(gh api user --jq .login)
EMAIL="$(gh api user --jq .id)+${LOGIN}@users.noreply.github.com"

mirror_one() {
  name=$1
  description=$2
  slug="$OWNER/$name"

  echo
  echo "==> $slug"

  if gh repo view "$slug" >/dev/null 2>&1; then
    echo "    already exists - reusing it"
    visibility=$(gh repo view "$slug" --json visibility --jq .visibility)
    if [ "$visibility" != "PUBLIC" ]; then
      echo "    WARNING: $slug is $visibility. The panel downloads these assets"
      echo "             anonymously and a private repo returns 404. Make it public."
    fi
  else
    gh repo create "$slug" --public --description "$description"
    echo "    created"
  fi

  work=$(mktemp -d)
  # shellcheck disable=SC2064
  trap "rm -rf '$work'" RETURN

  git clone -q "https://github.com/$slug.git" "$work/repo" 2>/dev/null || {
    mkdir -p "$work/repo"
    git -C "$work/repo" init -q
    git -C "$work/repo" remote add origin "https://github.com/$slug.git"
  }

  mkdir -p "$work/repo/.github/workflows"
  cp "$HERE/mirror-data.yml" "$work/repo/.github/workflows/mirror-data.yml"
  sed -e "s|@@SLUG@@|$slug|g" -e "s|@@NAME@@|$name|g" \
      "$HERE/README.md" > "$work/repo/README.md"

  git -C "$work/repo" add .github/workflows/mirror-data.yml README.md
  if git -C "$work/repo" diff --cached --quiet; then
    echo "    workflow already up to date"
  else
    git -C "$work/repo" \
      -c user.name="$LOGIN" -c user.email="$EMAIL" \
      commit -q -m "Mirror $name from Vateron-Media"
    branch=$(git -C "$work/repo" symbolic-ref --short HEAD 2>/dev/null || echo main)
    git -C "$work/repo" push -q -u origin "HEAD:$branch"
    echo "    workflow pushed"
  fi

  echo "    starting first sync"
  gh workflow run mirror-data.yml --repo "$slug" 2>/dev/null || {
    echo "    could not dispatch yet - GitHub needs a moment to register it."
    echo "    Re-run:  gh workflow run mirror-data.yml --repo $slug"
    return 0
  }
}

mirror_one XC_VM_Update \
  "Mirror of Vateron-Media/XC_VM_Update: GeoLite2 databases for the XC_VM panel"
mirror_one XC_VM_Proxy \
  "Mirror of Vateron-Media/XC_VM_Proxy: proxy-node archive for the XC_VM panel"

cat <<EOF

Done. Watch them:

  gh run watch --repo $OWNER/XC_VM_Update
  gh run watch --repo $OWNER/XC_VM_Proxy

Then confirm the panel sees them:

  php console.php cron:maxmind --force
  php console.php cron:proxy --force

GIT_OWNER_UPDATE and GIT_OWNER_PROXY in Core/Config/AppConfig.php already
point at '$OWNER'. Set either back to 'Vateron-Media' to fall back to
upstream for that one repository.
EOF
