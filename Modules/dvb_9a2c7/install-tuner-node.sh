#!/bin/sh
#
# Prepare a streaming node to drive a DVB card.
#
# Run this as root ON THE MACHINE THAT HOLDS THE CARD, after the node has been
# installed as an ordinary XC_VM streaming server and after the DVB driver is
# in place. It does not touch the panel.
#
#     sh /home/xc_vm/Modules/dvb_9a2c7/install-tuner-node.sh
#
# What it does, all of it idempotent:
#
#   1. installs dvb-tools (dvbv5-scan) and dvblast
#   2. builds tsdecrypt from the copy vendored in this module
#   3. puts the xc_vm user in the `video` group
#   4. rewrites the crontab so cron:dvb actually runs
#
# Deliberately written for /bin/sh, not bash: Ubuntu and Debian point sh at
# dash, and this is exactly the kind of script that gets run with `sh script`
# by someone who did not read the shebang. No pipefail, no arrays, no [[.

set -e

MAIN_HOME="${MAIN_HOME:-/home/xc_vm/}"
PANEL_USER="${PANEL_USER:-xc_vm}"
MODULE_DIR="$(cd "$(dirname "$0")" && pwd)"
VENDOR_DIR="$MODULE_DIR/vendor/tsdecrypt"
PREFIX="${PREFIX:-/usr/local}"

FAILED=0

say() {
	printf '\n\033[1m==> %s\033[0m\n' "$1"
}

ok() {
	printf '    \033[32mok\033[0m   %s\n' "$1"
}

warn() {
	printf '    \033[33mwarn\033[0m %s\n' "$1"
}

bad() {
	printf '    \033[31mFAIL\033[0m %s\n' "$1"
	FAILED=$((FAILED + 1))
}

if [ "$(id -u)" != "0" ]; then
	echo "This has to run as root: it installs packages and edits the crontab." >&2
	exit 1
fi

# ---------------------------------------------------------------------------
# 1. Distribution packages
# ---------------------------------------------------------------------------

say "Installing the DVB command line tools"

# v4l-utils is already pulled in by the XC_VM node installer, which is easy to
# mistake for having the DVB tools. It is not the same package: dvbv5-scan and
# dvb-fe-tool live in dvb-tools, built from the same source but shipped apart.
if command -v apt-get >/dev/null 2>&1; then
	export DEBIAN_FRONTEND=noninteractive
	apt-get update -qq || warn "apt-get update failed; continuing with the current package lists"
	apt-get install -y -qq dvb-tools dvblast build-essential libssl-dev \
		|| warn "some packages did not install; the checks below will say which"
elif command -v dnf >/dev/null 2>&1; then
	dnf install -y -q v4l-utils dvblast gcc make openssl-devel \
		|| warn "some packages did not install; the checks below will say which"
elif command -v yum >/dev/null 2>&1; then
	yum install -y -q v4l-utils dvblast gcc make openssl-devel \
		|| warn "some packages did not install; the checks below will say which"
else
	warn "No apt-get, dnf or yum found. Install dvb-tools, dvblast, a C compiler and the OpenSSL headers by hand."
fi

# ---------------------------------------------------------------------------
# 2. tsdecrypt
# ---------------------------------------------------------------------------

say "Building tsdecrypt"

if command -v tsdecrypt >/dev/null 2>&1; then
	ok "already installed at $(command -v tsdecrypt), leaving it alone"
elif [ ! -d "$VENDOR_DIR" ]; then
	bad "vendored source missing at $VENDOR_DIR"
else
	# Build in a copy so the release tree stays pristine. Object files left in
	# Modules/ would be clobbered by the next panel update anyway, and a failed
	# build would leave the module directory dirty.
	BUILD_DIR="$(mktemp -d)"
	cp -a "$VENDOR_DIR/." "$BUILD_DIR/"

	if [ ! -d "$BUILD_DIR/libfuncs" ] || [ -z "$(ls -A "$BUILD_DIR/libfuncs" 2>/dev/null)" ]; then
		bad "libfuncs is empty in the vendored copy"
	elif [ ! -d "$BUILD_DIR/libtsfuncs" ] || [ -z "$(ls -A "$BUILD_DIR/libtsfuncs" 2>/dev/null)" ]; then
		bad "libtsfuncs is empty in the vendored copy"
	else
		# `make ffdecsa` rather than plain `make`: FFdecsa ships inside the
		# source tree, so nothing external is linked and libdvbcsa is not
		# needed. FFdecsa_init benchmarks this CPU to choose the fastest
		# variant, which is why the build happens here and not in CI.
		echo "    building in $BUILD_DIR (this benchmarks the CPU, give it a minute)"

		if (cd "$BUILD_DIR" && make clean >/dev/null 2>&1; make ffdecsa >/tmp/tsdecrypt-build.log 2>&1); then
			:
		else
			warn "the build reported an error, checking for the binary anyway"
		fi

		# Trust the artefact, not the exit status.
		if [ -x "$BUILD_DIR/tsdecrypt" ]; then
			(cd "$BUILD_DIR" && make install PREFIX="$PREFIX" >/dev/null 2>&1) || true

			if command -v tsdecrypt >/dev/null 2>&1; then
				ok "installed at $(command -v tsdecrypt)"
			else
				bad "built but not installed; copy $BUILD_DIR/tsdecrypt to $PREFIX/bin by hand"
			fi
		else
			bad "tsdecrypt did not build. Last lines of /tmp/tsdecrypt-build.log:"
			tail -n 15 /tmp/tsdecrypt-build.log 2>/dev/null | sed 's/^/         /'

			if grep -q 'openssl/' /tmp/tsdecrypt-build.log 2>/dev/null; then
				echo "         ^ the OpenSSL development headers are missing: install libssl-dev (or openssl-devel)."
			fi
		fi
	fi

	rm -rf "$BUILD_DIR"
fi

# ---------------------------------------------------------------------------
# 3. Device permissions
# ---------------------------------------------------------------------------

say "Granting access to the tuner devices"

if ! id "$PANEL_USER" >/dev/null 2>&1; then
	bad "user $PANEL_USER does not exist; is this really a streaming node?"
elif id -nG "$PANEL_USER" | tr ' ' '\n' | grep -qx video; then
	ok "$PANEL_USER is already in the video group"
else
	usermod -aG video "$PANEL_USER"
	ok "$PANEL_USER added to the video group"
	warn "already-running processes keep their old groups. Restart the XC_VM services (or reboot) before scanning, or every scan reports Permission denied."
fi

# ---------------------------------------------------------------------------
# 4. The cron entry
# ---------------------------------------------------------------------------

say "Registering the DVB worker in cron"

PHP_BIN="$MAIN_HOME/bin/php/bin/php"
PHP_BIN="$(echo "$PHP_BIN" | tr -s /)"

if [ ! -x "$PHP_BIN" ]; then
	bad "no PHP at $PHP_BIN; run this on the node itself, after installing it"
else
	# StartupCommand rewrites the whole crontab from the module manifests, so
	# this is the supported way to get cron:dvb in. Editing the crontab by hand
	# works until the next startup wipes it.
	#
	# Run it as root, not as $PANEL_USER. StartupCommand shells out to
	# "sudo crontab" to install the root crontab, and it decides the prefix of
	# every cron line from its own euid: as root it writes
	# "sudo -u xc_vm php ... cron:dvb", as xc_vm it writes the line unprefixed.
	# Running it as xc_vm therefore does two wrong things at once -- the inner
	# sudo has no password (the panel installer deliberately removes
	# /etc/sudoers.d/xc_vm) so the crontab is never written, and the lines it
	# would write are wrong anyway. Matches build/install and ServiceCommand.
	"$PHP_BIN" "$(echo "$MAIN_HOME/console.php" | tr -s /)" startup >/dev/null 2>&1 || true

	if crontab -l 2>/dev/null | grep -q 'cron:dvb'; then
		ok "cron:dvb is in the crontab"
	else
		bad "cron:dvb is NOT in the crontab. Without it the panel queues jobs that nothing ever runs, silently."
		echo "         Check the dvb module is present and enabled: ls $MAIN_HOME/Modules/"
	fi
fi

# ---------------------------------------------------------------------------
# Verification
# ---------------------------------------------------------------------------

say "Checking the result"

for BIN in dvbv5-scan dvblast tsdecrypt; do
	if command -v "$BIN" >/dev/null 2>&1; then
		ok "$BIN -> $(command -v "$BIN")"
	elif [ "$BIN" = "tsdecrypt" ]; then
		warn "tsdecrypt missing. Only needed for encrypted services."
	else
		bad "$BIN missing"
	fi
done

if command -v dvb-fe-tool >/dev/null 2>&1; then
	ok "dvb-fe-tool -> $(command -v dvb-fe-tool)"
else
	warn "dvb-fe-tool missing. Adapters are still discovered, they just show up unnamed."
fi

if [ -d /dev/dvb ]; then
	COUNT="$(ls -1 /dev/dvb 2>/dev/null | grep -c '^adapter' || true)"
	if [ "$COUNT" -gt 0 ]; then
		ok "$COUNT DVB adapter(s) present in /dev/dvb"
	else
		bad "/dev/dvb exists but holds no adapters"
	fi
else
	bad "/dev/dvb does not exist. The driver is not loaded: check dmesg | grep -i frontend"
fi

echo ""

if [ "$FAILED" -gt 0 ]; then
	printf '\033[31m%s check(s) failed.\033[0m Fix those, then run this again — it is safe to repeat.\n' "$FAILED"
	exit 1
fi

printf '\033[32mThis node is ready.\033[0m\n'
echo ""
echo "Next, in the panel:"
echo "  1. Management -> Service Setup -> DVB Tuners -> Discover adapters"
echo "  2. add a transponder and scan it"
echo "  3. import the services you want as channels"
echo ""
echo "Allow up to a minute between clicking and the tuner moving. That is the"
echo "cron tick, not a hang."
