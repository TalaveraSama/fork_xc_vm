#!/bin/bash
#
# Build a single self-contained installer bundle.
#
# A normal install reaches the network three times: for the panel archive,
# for the distribution's runtime binaries, and for ~47 apt packages. This
# collects all three into one tarball that installs with no internet at all.
#
# Run it on a machine with network that matches the TARGET distribution —
# the apt packages are resolved against the running release, so building an
# Ubuntu 22 bundle on Ubuntu 20 gives you the wrong .debs.
#
#   sudo bash build/make-offline-bundle.sh 2.7.11
#
set -euo pipefail

VERSION="${1:-}"
OWNER="${OWNER:-TalaveraSama}"
REPO="${REPO:-fork_xc_vm}"

if [ -z "$VERSION" ]; then
	echo "usage: $0 <version>    e.g. $0 2.7.11" >&2
	exit 1
fi

. /etc/os-release
case "$ID" in
	ubuntu) PATCH="ubuntu_${VERSION_ID%%.*}.tar.gz" ;;
	debian) PATCH="debian_${VERSION_ID%%.*}.tar.gz" ;;
	*) echo "only ubuntu and debian are bundled; this is $ID" >&2; exit 1 ;;
esac

OUT="xc_vm-offline-${VERSION}-${ID}${VERSION_ID%%.*}"
rm -rf "$OUT" && mkdir -p "$OUT/debs"
BASE="https://github.com/$OWNER/$REPO/releases/download"

echo "==> panel archive"
curl -fL -o "$OUT/xc_vm.tar.gz" "$BASE/$VERSION/xc_vm.tar.gz"
curl -fsSL -o "$OUT/hashes.md5" "$BASE/$VERSION/hashes.md5"

echo "==> runtime binaries ($PATCH)"
BIN_TAG=$(curl -fsSL "https://api.github.com/repos/$OWNER/$REPO/releases?per_page=100" \
	| grep -oE '"tag_name": *"binaries-[^"]+"' | head -1 | sed 's/.*"\(binaries-[^"]*\)"/\1/')

if [ -z "$BIN_TAG" ]; then
	echo "no binaries-* release found on $OWNER/$REPO" >&2
	exit 1
fi

echo "    from $BIN_TAG"
curl -fL -o "$OUT/$PATCH" "$BASE/$BIN_TAG/$PATCH"

echo "==> the installer itself"
tar xzf "$OUT/xc_vm.tar.gz" -C "$OUT" install 2>/dev/null \
	|| { echo "no 'install' at the archive root" >&2; exit 1; }

echo "==> apt packages"
# --reinstall so packages already present here are still fetched: the target
# machine will not have them.
PKGS=$(grep -oE "'[a-z0-9.+-]+'" "$OUT/install" 2>/dev/null | tr -d "'" | sort -u \
	| grep -E '^(lib|php|nginx|mariadb|certbot|iproute2|net-tools|v4l-utils|cron|git|curl|wget|zip|unzip|xz-utils|sysstat|alsa-utils|e2fsprogs|mcrypt|cpufrequtils|dirmngr|gpg-agent)' || true)
apt-get update -qq
# shellcheck disable=SC2086
apt-get install -y --reinstall --download-only $PKGS 2>/dev/null || \
	echo "    some packages did not resolve; the bundle installs what it got"
cp -n /var/cache/apt/archives/*.deb "$OUT/debs/" 2>/dev/null || true
echo "    $(ls -1 "$OUT/debs" | wc -l) .deb collected"

cat > "$OUT/install-offline.sh" <<'INNER'
#!/bin/bash
# Install with no network. Run as root from inside this directory.
set -euo pipefail
cd "$(dirname "$0")"

if [ "$(id -u)" -ne 0 ]; then echo "run as root" >&2; exit 1; fi

echo "==> seeding apt from the bundle"
cp -n debs/*.deb /var/cache/apt/archives/ 2>/dev/null || true
dpkg -i debs/*.deb 2>/dev/null || apt-get -y -f install --no-download || true

echo "==> verifying the panel archive"
md5sum -c --ignore-missing hashes.md5 || { echo "checksum mismatch" >&2; exit 1; }

echo "==> running the installer"
# It finds ./xc_vm.tar.gz and ./<distro>.tar.gz beside it and skips every
# download.
python3 install
INNER
chmod +x "$OUT/install-offline.sh"

echo "==> packing"
tar czf "$OUT.tar.gz" "$OUT"
rm -rf "$OUT"
echo
echo "Bundle: $OUT.tar.gz  ($(du -h "$OUT.tar.gz" | cut -f1))"
echo "Copy it to the target, extract, and run ./install-offline.sh as root."
