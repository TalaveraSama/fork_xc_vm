#!/usr/bin/env bash
#
# Push a clean copy of the panel to TalaveraSama/fork_xc_vm over git, so that
# repository can build and publish its own releases and nothing here has to be
# moved by hand again.
#
#   bash build/publish-source-to-fork.sh            # review, then push
#   bash build/publish-source-to-fork.sh --dry-run  # build the tree, push nothing
#
# A plain `git push` of this repository would be a disclosure, not a mirror.
# Its history carries a 7 MB database dump, tmp/cache/hmac_keys and 2754
# runtime cache files, and the default branch adds config.enc, install_id, a
# second dump, the panel's Redis password and its TLS private key.
#
# So this does not push history at all. It assembles the tree from scratch --
# source layer, binary layer, upstream config templates -- exactly as
# build-release.sh does, and commits it as a single fresh commit. Nothing that
# is not in that tree can leak, because no earlier commit exists to hold it.

set -euo pipefail
if [ -z "${BASH_VERSION:-}" ]; then exec bash "$0" "$@"; fi

FORK=${FORK:-TalaveraSama/fork_xc_vm}
BIN_REF=${BIN_REF:-origin/main}
BRANCH=${BRANCH:-main}
DRY=0
OUT=""
WITH_BIN=0
while [ $# -gt 0 ]; do
  case "$1" in
    --dry-run)  DRY=1; shift ;;
    --with-bin) WITH_BIN=1; shift ;;
    --out)     OUT=$2; DRY=1; shift 2 ;;
    -h|--help) sed -n '2,20p' "${BASH_SOURCE[0]}"; exit 0 ;;
    *) echo "unknown argument: $1" >&2; exit 2 ;;
  esac
done

REPO_ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
cd "$REPO_ROOT"

say() { printf '\n==> %s\n' "$*"; }

command -v git >/dev/null || { echo "git is required"; exit 1; }

# Deliberately no GitHub CLI dependency. `gh` is not always the GitHub CLI --
# the `gitsome` package installs a command by the same name -- and the push
# below is plain git anyway. Visibility is checked over the public API with
# whatever HTTP client is present, and a failure to check is a warning, not a
# refusal to work.
check_public() {
  local json=""
  if command -v curl >/dev/null; then
    json=$(curl -fsSL -m 20 "https://api.github.com/repos/$FORK" 2>/dev/null || echo "")
  elif command -v wget >/dev/null; then
    json=$(wget -qO- -T 20 "https://api.github.com/repos/$FORK" 2>/dev/null || echo "")
  else
    echo "    no curl or wget — cannot verify $FORK is public; continuing"
    return 0
  fi

  if [ -z "$json" ]; then
    echo "    could not reach the GitHub API — cannot verify $FORK is public"
    echo "    make sure it exists and is PUBLIC: release assets in a private"
    echo "    repository are not downloadable, and the installer fetches them"
    echo "    anonymously."
    return 0
  fi

  case "$json" in
    *'"private": true'*|*'"private":true'*)
      echo "$FORK is private. Release assets there would not be downloadable," >&2
      echo "and the installer fetches them anonymously. Make it public first." >&2
      exit 1 ;;
    *'"private": false'*|*'"private":false'*)
      echo "    $FORK is public" ;;
    *)
      echo "    $FORK not found, or the API answered unexpectedly" >&2
      exit 1 ;;
  esac
}

if [ "$DRY" -eq 0 ]; then
  check_public
fi

# --out leaves the finished repository somewhere findable, for publishing with
# a GUI client such as GitHub Desktop instead of pushing from here.
if [ -n "$OUT" ]; then
  TREE=$OUT
  if [ -e "$TREE" ] && [ -n "$(ls -A "$TREE" 2>/dev/null)" ]; then
    echo "$TREE already exists and is not empty — remove it or pick another path" >&2
    exit 1
  fi
  mkdir -p "$TREE"
  TREE=$(cd "$TREE" && pwd)
  WORK=$(mktemp -d)
  trap 'rm -rf "$WORK"' EXIT
else
  WORK=$(mktemp -d)
  trap 'rm -rf "$WORK"' EXIT
  TREE="$WORK/tree"
  mkdir -p "$TREE"
fi

# ── Source layer ─────────────────────────────────────────────────
# The same exclusions build-release.sh applies, plus the runtime state this
# repository was captured with. build/ and .github/ are KEPT here -- unlike in
# a deploy archive, the fork needs them to build itself.

say "Source layer"

EXCLUDE_RE='^(\.gitattributes$|dist/|bin/'\
'|backup_[0-9_-]*\.sql$|backups/'\
'|config\.enc$|install_id$|signals\.last$|sysctl\.on$'\
'|Modules/[^/]*\.backup\.[^/]*/'\
'|tmp/(cidr|cache|crons|logs)/|tmp/crontab$|tmp/[^/]*\.log$)'

copied=0
while IFS= read -r f; do
  case "$f" in '') continue ;; esac
  if [[ $f =~ $EXCLUDE_RE ]]; then continue; fi
  mkdir -p "$TREE/$(dirname "$f")"
  cp -a "$f" "$TREE/$f"
  copied=$((copied + 1))
done < <(git ls-files)
echo "    $copied files"

# ── Binary layer ─────────────────────────────────────────────────
# bin/ is 677 MB and is deliberately left out. The fork's CI takes its base
# layer from an upstream release archive instead, which keeps this repository
# near 120 MB -- pushable by ordinary means -- and uses a pristine tree rather
# than one captured from a running panel, whose stale logs and pid files are
# exactly what stopped nginx and php-fpm from starting. --with-bin restores
# the heavy behaviour.
if [ "$WITH_BIN" -eq 1 ]; then
  say "Binary layer from $BIN_REF"
  git archive "$BIN_REF" bin | tar -x -C "$TREE"
  echo "    $(find "$TREE/bin" -type f | wc -l) files, $(du -sh "$TREE/bin" | cut -f1)"
else
  say "No bin/ — the fork builds from an upstream base archive"
fi

# The mirror workflow belongs to the fork, not here: in this repository it
# would publish binaries releases into a private repo nobody can download from.
say "Adding the mirror workflows"
mkdir -p "$TREE/.github/workflows"
cp "$REPO_ROOT/build/binaries-mirror/mirror.yml"      "$TREE/.github/workflows/mirror.yml"
cp "$REPO_ROOT/build/binaries-mirror/mirror-base.yml" "$TREE/.github/workflows/mirror-base.yml"

if [ -d "$TREE/bin" ]; then
  say "Replacing captured configs with upstream templates"
  cp -a "$REPO_ROOT/build/templates/bin/." "$TREE/bin/"
  rm -f "$TREE"/bin/nginx/conf/codes/*.conf
  find "$TREE/bin" \( -path '*/logs/*' -o -path '*/log/*' -o -path '*/sessions/*' \
                      -o -name '*.pid' -o -name '*.sock' \) -type f -delete 2>/dev/null || true
fi

# ── Gate ─────────────────────────────────────────────────────────
# Everything above is allow-listed, but this is a public repository and the
# cost of being wrong is a leaked credential, so check the result itself.

say "Checking the tree for anything private"
fail=0
while IFS= read -r pattern; do
  hits=$(cd "$TREE" && eval "ls -1d $pattern" 2>/dev/null || true)
  if [ -n "$hits" ]; then
    echo "   LEAK: $hits" >&2
    fail=1
  fi
done <<'PATTERNS'
config.enc
install_id
signals.last
backup_*.sql
backups/*.sql
tmp/cache/hmac_keys
tmp/cidr/*
bin/nginx/conf/codes/*.conf
bin/php/sessions/sess_*
PATTERNS

pass=$(grep -rIl --exclude-dir=.git -E '^[[:space:]]*requirepass[[:space:]]+[^#]' "$TREE" 2>/dev/null || true)
for f in $pass; do
  value=$(sed -nE 's/^[[:space:]]*requirepass[[:space:]]+(.*)$/\1/p' "$f" | head -1)
  if [ -n "$value" ] && [ "$value" != '#PASSWORD#' ]; then
    echo "   LEAK: real requirepass in ${f#"$TREE"/}" >&2
    fail=1
  fi
done

# Upstream ships a placeholder keypair that the installer overwrites with a
# unique one before nginx first starts, so it is public by design. Allow it by
# CONTENT, not by path: a real key that happens to sit at the same path must
# still be caught.
PLACEHOLDER_KEY_MD5=6375788d66c48fbf3c0410445545fb03
# Assembled from halves so this line does not match its own pattern: build/ is
# part of the published tree, and the scanner kept reporting itself as a leak.
key_head='-----BEGIN'
key_tail='KEY-----'
for f in $(grep -rlI -- "$key_head .*PRIVATE $key_tail" "$TREE" 2>/dev/null || true); do
  if [ "$(md5sum < "$f" | cut -d' ' -f1)" = "$PLACEHOLDER_KEY_MD5" ]; then
    continue
  fi
  echo "   LEAK: private key in ${f#"$TREE"/}" >&2
  fail=1
done

[ "$fail" -eq 0 ] || { echo "ERROR: refusing to publish" >&2; exit 1; }
echo "    clean — $(find "$TREE" -type f | wc -l) files, $(du -sh "$TREE" | cut -f1)"

# ── Commit and push ──────────────────────────────────────────────
say "Building a single fresh commit"
cd "$TREE"
# `git init -b` needs git 2.28; Ubuntu 20.04 ships 2.25, and this is meant to
# run on the panel's own host. Setting HEAD before the first commit names the
# branch the same way on every version.
git init -q
git symbolic-ref HEAD "refs/heads/$BRANCH"
git config user.name  "$(git -C "$REPO_ROOT" config user.name  || echo 'xc_vm fork')"
git config user.email "$(git -C "$REPO_ROOT" config user.email || echo 'noreply@users.noreply.github.com')"
git add -A
git commit -q -m "XC_VM $(sed -nE "s/.*define\('XC_VM_VERSION', *'([^']+)'\).*/\1/p" \
  Core/Config/AppConfig.php | head -1) with the Flussonic module

Source and bundled runtime for this fork, assembled from
$(git -C "$REPO_ROOT" rev-parse --short HEAD) with the binary layer from
$(git -C "$REPO_ROOT" rev-parse --short "$BIN_REF").

Published as a single commit on purpose: the originating repository's history
holds database dumps, an encrypted config and private keys, none of which
belong in a public mirror."

echo "    $(git rev-list --count HEAD) commit, $(git ls-files | wc -l) files"

if [ "$DRY" -eq 1 ]; then
  if [ -n "$OUT" ]; then
    say "Ready to publish"
    echo "    $TREE"
    echo
    echo "    It is already a git repository with one commit. In GitHub Desktop:"
    echo "      File > Add local repository...  ->  $TREE"
    echo "      then Publish repository, unticking \"Keep this code private\","
    echo "      named  ${FORK#*/}  under  ${FORK%/*}."
    echo
    echo "    If the repository already exists on GitHub, use Repository >"
    echo "    Repository settings... to point the remote at it and push."
    echo
    echo "    Or push it straight from here, without rebuilding anything:"
    echo "      cd $TREE"
    echo "      git remote add origin https://github.com/$FORK.git"
    echo "      git push -u origin $BRANCH"
    echo "    git asks for a username and password; the password is a personal"
    echo "    access token with 'repo' scope, not your account password."
    echo
    echo "    Or with a deploy key, which is scoped to this one repository and"
    echo "    needs no token. Generate it here -- the private half never leaves"
    echo "    this machine -- and register only the public half:"
    echo "      ssh-keygen -t ed25519 -C xcvm-fork -f ~/.ssh/fork_xc_vm -N ''"
    echo "      cat ~/.ssh/fork_xc_vm.pub"
    echo "    Paste that at https://github.com/$FORK/settings/keys"
    echo "    ('Add deploy key', and tick Allow write access), then:"
    echo "      printf 'Host github-fork\\n HostName github.com\\n User git\\n IdentityFile ~/.ssh/fork_xc_vm\\n IdentitiesOnly yes\\n' >> ~/.ssh/config"
    echo "      cd $TREE"
    echo "      git remote add origin git@github-fork:$FORK.git"
    echo "      git push -u origin $BRANCH"
  else
    say "Dry run — not pushing"
    echo "    tree kept at: $TREE"
  fi
  exit 0
fi

say "Pushing to $FORK ($BRANCH)"
echo "    this uploads several hundred MB and will take a while"
git remote add origin "${FORK_URL:-https://github.com/$FORK.git}"
if ! git push --force origin "HEAD:$BRANCH"; then
  echo
  echo "Push failed. GitHub stopped accepting passwords, so plain HTTPS needs a"
  echo "personal access token with 'repo' scope, entered at the password prompt"
  echo "or supplied in the URL:"
  echo
  echo "  FORK_URL='https://USER:TOKEN@github.com/$FORK.git' bash $0"
  echo
  echo "Over SSH instead:"
  echo
  echo "  FORK_URL='git@github.com:$FORK.git' bash $0"
  exit 1
fi

say "Done"
echo "    https://github.com/$FORK"
echo
echo "    The fork builds its own releases from here. Start them from"
echo "    https://github.com/$FORK/actions -- 'Mirror upstream binaries',"
echo "    then 'Build Release' with tag $VERSION."
