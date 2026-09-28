#!/usr/bin/env bash
#
# Build an installable XC_VM release from this repository.
#
# The deploy tree (/home/xc_vm/) is assembled from two layers:
#
#   1. Binary layer — `bin/`, ~500 MB of bundled runtime: the PHP 8.1 build
#      with xcvm_core.so and the ionCube loader, nginx, nginx-rtmp, redis,
#      ffmpeg/ffprobe, yt-dlp, the MaxMind databases and the install schema.
#      It lives on its own git ref (default `origin/main`) so the source
#      branches stay light.
#   2. Source layer — this repository's tracked files, copied over it.
#
# Nothing is downloaded: the whole release is built from the repository.
#
# Output (in --out, default ./dist), matching upstream's asset names:
#
#   xc_vm.tar.gz   the deploy tree, extracted to /home/xc_vm/ by the installer
#   XC_VM.zip      `install` + xc_vm.tar.gz — what the user downloads
#   hashes.md5     md5 of both archives
#
# Install on the target server:
#
#   unzip XC_VM.zip && sudo python3 install
#
# The installer checks for a local xc_vm.tar.gz BEFORE reaching out to GitHub
# (see download_xc_vm() in `install`), so it picks up our tree, not upstream's.
#
# Usage:
#   build/build-release.sh
#   build/build-release.sh --bin-ref main --out /tmp/dist
#   build/build-release.sh --bin-dir /path/to/bin      # bin/ already on disk

set -Eeuo pipefail

on_err() {
  local code=$? line=${1:-?}
  echo "build-release: FAILED (exit $code) at line $line: $BASH_COMMAND" >&2
  if [ -n "${GITHUB_ACTIONS:-}" ]; then
    echo "::error::build-release failed (exit ${code}) at line ${line}: ${BASH_COMMAND}"
  fi
  exit "$code"
}
trap 'on_err $LINENO' ERR

REPO_ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
cd "$REPO_ROOT"

OUT_DIR="$REPO_ROOT/dist"
BIN_REF=""
BIN_DIR=""
BASE_TAR=""
INSTALL_SRC=""
VERSION=""

while [ $# -gt 0 ]; do
  case "$1" in
    --out)      OUT_DIR=$2; shift 2 ;;
    --bin-ref)  BIN_REF=$2; shift 2 ;;
    --bin-dir)  BIN_DIR=$2; shift 2 ;;
    --base-tar) BASE_TAR=$2; shift 2 ;;
    --install)  INSTALL_SRC=$2; shift 2 ;;
    --version)  VERSION=$2; shift 2 ;;
    -h|--help)  sed -n '2,31p' "${BASH_SOURCE[0]}"; exit 0 ;;
    *) echo "build-release: unknown argument '$1'" >&2; exit 2 ;;
  esac
done

if [ -z "$VERSION" ]; then
  VERSION=$(sed -nE "s/.*define\('XC_VM_VERSION', *'([^']+)'\).*/\1/p" Core/Config/AppConfig.php | head -1)
fi
[ -n "$VERSION" ] || { echo "build-release: cannot read XC_VM_VERSION" >&2; exit 1; }

# The binary layer lives wherever `bin/` is committed. Prefer an explicit ref,
# otherwise this branch if it carries bin/, otherwise the default branch.
if [ -z "$BIN_DIR" ] && [ -z "$BIN_REF" ] && [ -z "$BASE_TAR" ]; then
  for candidate in HEAD origin/main main; do
    if git cat-file -e "$candidate:bin/php/bin/php" 2>/dev/null; then BIN_REF=$candidate; break; fi
  done
  [ -n "$BIN_REF" ] || { echo "build-release: no ref carries bin/ — pass --bin-ref or --bin-dir" >&2; exit 1; }
fi

# Logs, pids, sockets and sessions carried over from the panel the binary
# layer was captured on. The patterns must stay quoted: unquoted, the shell
# expands them against the repository -- tmp/logs/ matches -- and the find
# expression silently stops matching what it should.
runtime_files() {
  find "$1" \( -path '*/logs/*' -o -path '*/log/*' -o -path '*/sessions/*' \
                -o -name '*.pid' -o -name '*.sock' \) -type f 2>/dev/null || true
}

say() {
  printf '\n==> %s\n' "$*"
  [ -n "${GITHUB_ACTIONS:-}" ] && echo "::notice::$*"
  return 0
}

WORK=$(mktemp -d /tmp/xcvm-release-XXXXXX)
trap 'rm -rf "$WORK"' EXIT
STAGE="$WORK/stage"
mkdir -p "$STAGE" "$OUT_DIR"

# Absolute from here on: the packaging step cd's into the staging and output
# directories, so a relative --out (e.g. `dist`) would resolve against the
# wrong place and zip would fail to open its output file.
OUT_DIR=$(cd "$OUT_DIR" && pwd)

# ── 1. Binary layer ──────────────────────────────────────────────
# A base archive is an entire upstream deploy tree: bin/ plus config/,
# storage/, signals/ and the rest. Preferring it over a committed bin/ keeps
# the repository small enough to push, and the tree is pristine rather than
# captured from a running panel -- which is where the stale logs and pid files
# that stopped nginx and php-fpm from starting came from.
if [ -n "$BASE_TAR" ]; then
  say "Base archive: $BASE_TAR"
  [ -f "$BASE_TAR" ] || { echo "build-release: no such file: $BASE_TAR" >&2; exit 1; }
  tar -xzf "$BASE_TAR" -C "$STAGE"
  # A release archive carries its deploy modes -- 0550 files, 0750 directories --
  # so the template overlay and the source layer cannot write over it. Section 6
  # sets every mode explicitly afterwards, so relaxing here costs nothing.
  chmod -R u+rwX "$STAGE"
  say "Base archive: $(find "$STAGE" -type f | wc -l) files, $(du -sh "$STAGE" | cut -f1)"
elif [ -n "$BIN_DIR" ]; then
  say "Binary layer: $BIN_DIR"
  mkdir -p "$STAGE/bin"
  cp -a "$BIN_DIR/." "$STAGE/bin/"
else
  say "Binary layer: bin/ from $BIN_REF ($(git rev-parse --short "$BIN_REF"))"
  git archive "$BIN_REF" bin | tar -x -C "$STAGE"
fi

# "no bin/php/bin/php" on its own says nothing about what the layer DOES
# contain, and on CI the run log is not always reachable, so describe the tree
# instead of just rejecting it. ::error:: puts it in the run's annotations,
# which survive even when the log download does not.
if [ ! -e "$STAGE/bin/php/bin/php" ]; then
  top=$(ls -A "$STAGE" 2>/dev/null | tr '\n' ' ')
  if [ -d "$STAGE/bin" ]; then
    inbin=$(ls -A "$STAGE/bin" 2>/dev/null | tr '\n' ' ')
  else
    inbin="(no bin/ directory at all)"
  fi
  echo "build-release: binary layer has no bin/php/bin/php" >&2
  echo "  staged top level: $top" >&2
  echo "  staged bin/:      $inbin" >&2
  if [ -n "${GITHUB_ACTIONS:-}" ]; then
    echo "::error::binary layer has no bin/php/bin/php -- top level: ${top:0:300}"
    echo "::error::binary layer bin/ contains: ${inbin:0:300}"
  fi
  exit 1
fi
say "Binary layer: $(find "$STAGE/bin" -type f | wc -l) files, $(du -sh "$STAGE/bin" | cut -f1)"

# ── 1b. Template layer ───────────────────────────────────────────
# The binary layer is captured from a running panel, so its text configs are
# that panel's live ones. Overlay upstream's pristine templates: shipping a
# captured redis.conf leaks its requirepass and stops the password ever being
# rotated, and a captured bin/nginx/conf carries that panel's ports, proxy
# IPs, TLS pair and access-code vhosts.

TEMPLATES="$REPO_ROOT/build/templates/bin"
if [ -d "$TEMPLATES" ]; then
  cp -a "$TEMPLATES/." "$STAGE/bin/"
  say "Template layer: $(find "$TEMPLATES" -type f | wc -l) upstream configs over bin/"
fi

# ── 2. Source layer ──────────────────────────────────────────────
# Tracked files only, minus build tooling and the runtime state this
# repository was captured with. Some of that is sensitive: config.enc holds the
# panel's encrypted database credentials, install_id is its identity with the
# module platform, and backups/ are full SQL dumps.
#
# bin/ is excluded here: the binary layer supplies it and the template layer
# has already corrected its configs.

say "Source layer: $(git rev-parse --short HEAD)"

EXCLUDE_RE='^(\.github/|\.gitattributes$|\.gitignore$|build/|dist/|bin/'\
'|backup_[0-9_-]*\.sql$|backups/'\
'|config\.enc$|install_id$|signals\.last$|sysctl\.on$'\
'|(bundled_modules|modules|permissions)\.php$|rclone\.conf$'\
'|Modules/[^/]*\.backup\.[^/]*/|Modules/[^/]*/dev/|Modules/[^/]*/package\.sh$'\
'|tmp/(cidr|cache|crons|logs)/|tmp/crontab$|tmp/[^/]*\.log$)'

copied=0
while IFS= read -r f; do
  case "$f" in '') continue ;; esac
  if [[ $f =~ $EXCLUDE_RE ]]; then continue; fi
  mkdir -p "$STAGE/$(dirname "$f")"
  cp -a "$f" "$STAGE/$f"
  copied=$((copied + 1))
done < <(git ls-files)

say "Source layer: $copied files"
find "$STAGE" -name .gitkeep -delete

# Per-install artefacts a captured bin/ carries: nginx vhosts minted for this
# panel's access codes. Only the templates belong in a release.
for c in "$STAGE"/bin/nginx/conf/codes/*.conf; do
  [ -e "$c" ] || continue
  say "Dropping per-install vhost $(basename "$c")"
  rm -f "$c"
done

# Logs and pid files from the panel the binary layer was captured on. Shipping
# them is not just stale state: set_permissions chmods every file under
# bin/nginx to 0550, so a carried-over error.log arrives read-only and nginx
# dies with "could not open error log file (13: Permission denied)" while
# nginx_rtmp -- which that find does not cover -- starts fine.
stale=$(runtime_files "$STAGE/bin")
if [ -n "$stale" ]; then
  echo "$stale" | while read -r f; do
    [ -n "$f" ] && say "Dropping runtime file ${f#"$STAGE"/}" && rm -f "$f"
  done
fi

# ── 3. Runtime scaffolding ───────────────────────────────────────
# Directories the panel writes into. Git cannot carry an empty directory, so
# they are created here; tar preserves them.

say "Creating runtime directories"
for d in \
  backups signals \
  content/archive content/created content/delayed content/epg \
  content/playlists content/streams content/vod \
  tmp/cache/lines tmp/cache/series tmp/cache/streams \
  tmp/cidr tmp/crons tmp/divergence tmp/flood tmp/logs tmp/ministra \
  tmp/opened_cons tmp/player tmp/signals tmp/watch \
  storage/images/admin storage/images/enigma2 \
  Public/assets/player/images/thumbs \
  bin/certbot bin/nginx/logs bin/nginx_rtmp/logs \
  bin/php/sockets bin/php/sessions bin/php/var/log \
  bin/nginx/client_body_temp bin/nginx/fastcgi_temp bin/nginx/proxy_temp \
  bin/nginx/scgi_temp bin/nginx/uwsgi_temp \
  bin/nginx_rtmp/client_body_temp bin/nginx_rtmp/fastcgi_temp \
  bin/nginx_rtmp/proxy_temp bin/nginx_rtmp/scgi_temp bin/nginx_rtmp/uwsgi_temp
do
  mkdir -p "$STAGE/$d"
done

# ── 4. Installer and uninstaller ─────────────────────────────────
if [ -z "$INSTALL_SRC" ]; then
  if [ -f "$REPO_ROOT/build/install" ]; then
    INSTALL_SRC="$REPO_ROOT/build/install"
  else
    echo "build-release: no installer — add build/install or pass --install" >&2
    exit 1
  fi
fi
[ -f "$INSTALL_SRC" ] || { echo "build-release: installer not found: $INSTALL_SRC" >&2; exit 1; }
say "Installer: $INSTALL_SRC ($(stat -c%s "$INSTALL_SRC") bytes)"

# These ship twice: in the zip next to `install`, and inside the deploy tree,
# so a panel installed months ago can still be maintained without hunting down
# the original download.
EXTRA_SCRIPTS=""
for tool in uninstall repair-database doctor; do
  src="$REPO_ROOT/build/$tool"
  if [ -f "$src" ]; then
    cp -a "$src" "$STAGE/$tool"
    EXTRA_SCRIPTS="$EXTRA_SCRIPTS $tool"
    say "Bundled tool: $tool ($(stat -c%s "$src") bytes)"
  fi
done

# ── 5. Provenance stamp ──────────────────────────────────────────
printf '%s\n' \
  "${VERSION}+$(git rev-parse --short=10 HEAD).$(date -u +%Y%m%dT%H%M%SZ).$(od -An -N8 -tx1 /dev/urandom | tr -d ' \n')" \
  > "$STAGE/RELEASE_ID"
say "RELEASE_ID $(cat "$STAGE/RELEASE_ID")"

# ── 6. Permissions ───────────────────────────────────────────────
# Ported from upstream's Makefile `set_permissions` target: the tarball carries
# the modes the running panel expects, and the installer preserves them.

say "Setting permissions"
find "$STAGE" -type d -exec chmod 755 {} +
find "$STAGE" -type f -exec chmod 644 {} +

for d in backups bin config content signals; do chmod 0750 "$STAGE/$d" 2>/dev/null || true; done
chmod 0770 "$STAGE/content/streams" 2>/dev/null || true
chmod 0750 "$STAGE/service" "$STAGE/update" "$STAGE/bin/daemons.sh" 2>/dev/null || true
for tool in $EXTRA_SCRIPTS; do chmod 0750 "$STAGE/$tool" 2>/dev/null || true; done
chmod 0755 "$STAGE/bin/guess" "$STAGE/bin/yt-dlp" 2>/dev/null || true
chmod 0550 "$STAGE/bin/network" "$STAGE/bin/network.py" 2>/dev/null || true
find "$STAGE/bin/ffmpeg_bin" -type f \( -name ffmpeg -o -name ffprobe \) -exec chmod 0551 {} + 2>/dev/null || true
find "$STAGE/bin/nginx" -type d -exec chmod 750 {} + 2>/dev/null || true
find "$STAGE/bin/nginx" -type f -exec chmod 550 {} + 2>/dev/null || true
chmod 0755 "$STAGE/bin/nginx/conf" 2>/dev/null || true
chmod 0600 "$STAGE/bin/nginx/conf/server.key" 2>/dev/null || true
chmod 0750 "$STAGE/bin/nginx_rtmp/sbin/nginx_rtmp" 2>/dev/null || true
# nginx writes its error log and pid here, so these must stay owner-writable
# after the blanket 0550/0750 pass above.
for d in bin/nginx/logs bin/nginx_rtmp/logs bin/php/var/log bin/php/sessions bin/php/sockets; do
  chmod 0750 "$STAGE/$d" 2>/dev/null || true
done
find "$STAGE/bin/php" -type d -exec chmod 750 {} + 2>/dev/null || true
find "$STAGE/bin/php" -type f -exec chmod 550 {} + 2>/dev/null || true
for conf in 1.conf 2.conf 3.conf 4.conf; do chmod 0644 "$STAGE/bin/php/etc/$conf" 2>/dev/null || true; done
chmod 0551 "$STAGE/bin/php/bin/php" "$STAGE/bin/php/sbin/php-fpm" 2>/dev/null || true
chmod 0755 "$STAGE/bin/redis/redis-server" 2>/dev/null || true
chmod 0640 "$STAGE/config/modules.php" 2>/dev/null || true
chmod 0550 "$STAGE/config/rclone.conf" 2>/dev/null || true

# ── 7. Gates ─────────────────────────────────────────────────────
say "Verifying staged tree"

# Compile the bundled python tools. `ast.parse` is NOT enough: it accepts
# symbol-table errors such as declaring `global x` after x was assigned in the
# same scope, which then only blows up when the operator runs the installer.
for tool in install uninstall repair-database doctor; do
  src="$REPO_ROOT/build/$tool"
  [ -f "$src" ] || continue
  if ! python3 - "$src" <<'PYEOF'
import sys
path = sys.argv[1]
try:
    compile(open(path, encoding="utf-8").read(), path, "exec")
except SyntaxError as e:
    print("   %s: %s (line %s)" % (path, e.msg, e.lineno), file=sys.stderr)
    sys.exit(1)
PYEOF
  then
    echo "ERROR: $tool does not compile" >&2
    exit 1
  fi
done
echo "    python tools compile"

# A semicolon inside a `--` comment splits the statement in two for any naive
# SQL splitter, and the comment's tail ends up glued to the next statement.
# This is exactly how the flussonic module's database.sql once failed to apply.
bad_sql=$(find "$STAGE/Modules" "$STAGE/migrations" -name '*.sql' 2>/dev/null \
  -exec awk '/^[[:space:]]*--/ && /;/ {print FILENAME":"FNR": "$0}' {} + || true)
if [ -n "$bad_sql" ]; then
  echo "ERROR: semicolon inside a SQL comment (breaks statement splitting):" >&2
  echo "$bad_sql" | sed "s|^$STAGE/|   - |" >&2
  exit 1
fi
echo "    sql comments clean"

# A read-only log file or a stale pid under bin/ stops nginx from starting at
# all, and the failure is silent.
stale=$(runtime_files "$STAGE/bin")
if [ -n "$stale" ]; then
  echo "ERROR: runtime logs/pid/session files staged under bin/:" >&2
  echo "$stale" | sed "s|^$STAGE/|   - |" >&2
  exit 1
fi
for d in bin/nginx/logs bin/nginx_rtmp/logs bin/php/var/log bin/php/sessions bin/php/sockets; do
  mode=$(stat -c '%a' "$STAGE/$d" 2>/dev/null || echo "")
  case "$mode" in
    7*) ;;
    "") echo "ERROR: $d is missing from the staged tree" >&2; exit 1 ;;
    *)  echo "ERROR: $d is mode $mode — the daemon could not write there" >&2; exit 1 ;;
  esac
done
echo "    log directories writable"

pointers=$(grep -rlI '^version https://git-lfs.github.com/spec/v1' "$STAGE" 2>/dev/null || true)
if [ -n "$pointers" ]; then
  echo "ERROR: Git LFS pointer files staged instead of real binaries:" >&2
  echo "$pointers" | sed "s|^$STAGE/|   - |" >&2
  exit 1
fi

fail=0
for p in bootstrap.php console.php service update uninstall repair-database doctor Core Public Modules vendor \
         config/modules.php config/permissions.php \
         bin/php/bin/php bin/php/lib/php/extensions/no-debug-non-zts-20210902/xcvm_core.so \
         bin/nginx/sbin/nginx bin/nginx_rtmp/sbin/nginx_rtmp bin/redis/redis-server \
         bin/ffmpeg_bin/8.0/ffmpeg bin/install/database.sql; do
  [ -e "$STAGE/$p" ] || { echo "   MISSING: $p" >&2; fail=1; }
done

# Nothing carrying a live panel's identity, credentials or data may ship.
for leak in config.enc install_id signals.last backup_*.sql \
            backups/*.sql tmp/cache/hmac_keys config/install_id; do
  for hit in "$STAGE"/$leak; do
    [ -e "$hit" ] || continue
    echo "   LEAK: ${hit#"$STAGE"/}" >&2
    fail=1
  done
done

# A stale installed_version would make the panel treat a bundled module as
# already installed and skip its database.sql, so it must ship clean.
if grep -q "installed_version" "$STAGE/config/modules.php" 2>/dev/null; then
  echo "   config/modules.php carries installed_version state — ship the clean template" >&2
  fail=1
fi

# StatusCommand rotates the Redis password only when it still reads
# #PASSWORD#. A captured redis.conf would both leak a live credential and
# leave every install sharing it.
if [ -f "$STAGE/bin/redis/redis.conf" ]; then
  pass=$(sed -nE 's/^[[:space:]]*requirepass[[:space:]]+(.*)$/\1/p' "$STAGE/bin/redis/redis.conf" | head -1)
  if [ -n "$pass" ] && [ "$pass" != '#PASSWORD#' ]; then
    echo "   bin/redis/redis.conf carries a real requirepass — ship the #PASSWORD# template" >&2
    fail=1
  fi
fi

# Ditto for the TLS pair and the access-code vhosts: the installer generates a
# unique certificate before nginx starts, and codes are minted per panel.
for c in "$STAGE"/bin/nginx/conf/codes/*.conf; do
  [ -e "$c" ] && { echo "   per-install vhost staged: ${c#"$STAGE"/}" >&2; fail=1; }
done

for d in content/streams tmp/cidr backups signals; do
  [ -d "$STAGE/$d" ] || { echo "   MISSING DIR: $d" >&2; fail=1; }
done

[ "$fail" -eq 0 ] || { echo "ERROR: staged tree rejected" >&2; exit 1; }
say "Staged OK — $(find "$STAGE" -type f | wc -l) files, $(du -sh "$STAGE" | cut -f1)"

# ── 8. Package ───────────────────────────────────────────────────
say "Packaging"
rm -f "$OUT_DIR/xc_vm.tar.gz" "$OUT_DIR/XC_VM.zip" "$OUT_DIR/hashes.md5"

tar -czf "$OUT_DIR/xc_vm.tar.gz" -C "$STAGE" .

cp "$INSTALL_SRC" "$WORK/install"
zip_entries="install"
for tool in $EXTRA_SCRIPTS; do
  cp "$REPO_ROOT/build/$tool" "$WORK/$tool"
  zip_entries="$zip_entries $tool"
done
# shellcheck disable=SC2086
(cd "$WORK" && zip -q "$OUT_DIR/XC_VM.zip" $zip_entries)
(cd "$OUT_DIR" && zip -q -j XC_VM.zip xc_vm.tar.gz)
(cd "$OUT_DIR" && md5sum xc_vm.tar.gz XC_VM.zip | awk '{print $1, $2}' > hashes.md5)

say "Done"
ls -la "$OUT_DIR"
while read -r sum name; do
  say "$name — $(stat -c%s "$OUT_DIR/$name") bytes — md5 $sum"
done < "$OUT_DIR/hashes.md5"
