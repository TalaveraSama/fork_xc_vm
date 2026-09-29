#!/bin/bash
#
# DVB signal bench.
#
# Reproduces by hand what the panel does, prints the raw tool output the panel
# used to hide, and walks a ladder of variations until something locks. Run it
# on the node that physically holds the card.
#
#   bash signal-debug.sh
#   FREQ=11970000 POL=HORIZONTAL bash signal-debug.sh
#
# Everything is an environment variable so nothing has to be edited:
#
#   ADAPTER  tuner number                      (default 0)
#   FREQ     frequency in kHz for satellite    (default 10970000 = 10970 MHz)
#   POL      HORIZONTAL | VERTICAL             (default VERTICAL)
#   SRATE    symbol rate in symbols/second     (default 27900000 = 27900 kS/s)
#   DELSYS   DVBS2 | DVBS                      (default DVBS2)
#   LNB      LNBf name, 'dvbv5-zap -l help'    (default UNIVERSAL)
#   DISEQC   0-based satellite number, or off  (default off)
#   SECS     seconds to watch per attempt      (default 5)

ADAPTER=${ADAPTER:-0}
FREQ=${FREQ:-10970000}
POL=${POL:-VERTICAL}
SRATE=${SRATE:-27900000}
DELSYS=${DELSYS:-DVBS2}
LNB=${LNB:-UNIVERSAL}
DISEQC=${DISEQC:-}
SECS=${SECS:-5}

BUSY=0
WORK=$(mktemp -d /tmp/dvbsig.XXXXXX) || exit 1
trap 'rm -rf "$WORK"' EXIT

rule() { printf '\n%s\n' '--------------------------------------------------------------'; }
head2() { rule; printf '%s\n\n' "$1"; }

# ---------------------------------------------------------------- environment

head2 "1. Tools"
for t in dvbv5-zap dvbv5-scan dvb-fe-tool; do
	p=$(command -v "$t" 2>/dev/null)
	printf '  %-14s %s\n' "$t" "${p:-MISSING  (apt-get install dvb-tools)}"
done

if ! command -v dvbv5-zap >/dev/null 2>&1; then
	printf '\nNo dvbv5-zap, nothing else can be tested.\n'
	exit 1
fi

head2 "2. Adapters present"
if [ -d /dev/dvb ]; then
	ls -1 /dev/dvb
else
	printf '  /dev/dvb does not exist: the driver is not loaded.\n'
	printf '  Check:  lspci -vvv | grep -i 6909   and   dmesg | grep -i frontend\n'
	exit 1
fi

FE="/dev/dvb/adapter${ADAPTER}/frontend0"

head2 "3. Is adapter ${ADAPTER} free?"
if [ ! -e "$FE" ]; then
	printf '  %s does not exist.\n' "$FE"
	exit 1
fi
if command -v fuser >/dev/null 2>&1; then
	if fuser -v "$FE" 2>&1 | grep -q .; then
		BUSY=1
		printf '  BUSY - something already holds the frontend:\n\n'
		fuser -v "$FE" 2>&1 | sed 's/^/    /'
		printf '\n  Every test below would fail with "Device or resource busy"\n'
		printf '  and would tell you nothing about your dish, so this stops here.\n\n'
		for pid in $(fuser "$FE" 2>/dev/null); do
			printf '  PID %s is:\n' "$pid"
			ps -o pid=,user=,etime=,cmd= -p "$pid" 2>/dev/null | sed 's/^/    /'
			svc=$(ps -o comm= -p "$pid" 2>/dev/null)
			if [ -n "$svc" ] && command -v systemctl >/dev/null 2>&1; then
				printf '    systemctl status %s   # if this is a service, stop and disable it\n' "$svc"
			fi
		done
		printf '\n  Free the tuner and run this again, or set FORCE=1 to test anyway.\n'
		[ -z "${FORCE:-}" ] && exit 1
	else
		printf '  free\n'
	fi
else
	printf '  fuser not installed (apt-get install psmisc) - cannot tell.\n'
fi

head2 "4. What the frontend says it is"
printf '  NOTE: "ERROR FE_SET_VOLTAGE: Operation not permitted" below is\n'
printf '  harmless noise that dvb-fe-tool prints on TBS cards as it exits.\n'
printf '  It shows up on cards that lock perfectly well. Ignore it.\n\n'
dvb-fe-tool -a "$ADAPTER" 2>&1 | sed 's/^/  /'

# ------------------------------------------------------------------ the ladder
#
# In monitor mode dvbv5-zap matches the positional argument against the
# FREQUENCY, not against the section name:
#
#   dvbv5-zap [OPTION]... frequency-name (for monitor or all PIDs mode)
#   $ dvbv5-zap -c dvb_channel.conf 573000000 -m
#
# Passing a name here returns "ERROR: Can't find channel" and nothing tunes.

attempt_no=0
LOCKED=0

write_conf() {
	delsys=$1 pol=$2 fec=$3 mod=$4
	{
		printf '[CHANNEL]\n'
		printf '\tDELIVERY_SYSTEM = %s\n' "$delsys"
		printf '\tFREQUENCY = %s\n' "$FREQ"
		printf '\tPOLARIZATION = %s\n' "$pol"
		printf '\tSYMBOL_RATE = %s\n' "$SRATE"
		printf '\tINNER_FEC = %s\n' "$fec"
		if [ "$delsys" = "DVBS2" ]; then
			# There is no AUTO for MODULATION: the tool rejects it with
			# "value AUTO is invalid for MODULATION". FEC does accept AUTO.
			printf '\tMODULATION = %s\n' "$mod"
			printf '\tROLLOFF = AUTO\n'
			printf '\tPILOT = AUTO\n'
		fi
	} > "$WORK/try.conf"
}

try() {
	label=$1 delsys=$2 pol=$3 fec=$4 mod=$5 lnbf=$6 diseqc=$7
	attempt_no=$((attempt_no + 1))
	[ "$LOCKED" = "1" ] && return 0

	write_conf "$delsys" "$pol" "$fec" "$mod"

	set -- dvbv5-zap -c "$WORK/try.conf" -a "$ADAPTER" -f 0 -m -t "$SECS" -l "$lnbf"
	[ -n "$diseqc" ] && set -- "$@" -S "$diseqc"
	set -- "$@" "$FREQ"

	printf '\n[%d] %s\n' "$attempt_no" "$label"
	printf '    %s\n' "$*"

	out="$WORK/try.log"
	timeout -k 1 $((SECS + 4)) "$@" > "$out" 2>&1

	if grep -qiE '(^|[^n])Lock' "$out"; then
		printf '    >>> LOCK <<<\n'
		grep -iE 'Lock' "$out" | tail -2 | sed 's/^/    /'
		printf '\n    This combination works. Put these values in the panel.\n'
		LOCKED=1
		return 0
	fi

	if grep -qiE 'Signal|C/N' "$out"; then
		printf '    no lock, but the tuner reported a level:\n'
		grep -iE 'Signal|C/N' "$out" | tail -2 | sed 's/^/    /'
	else
		printf '    nothing readable - raw output follows:\n'
		sed 's/^/    | /' "$out" | head -12
		[ ! -s "$out" ] && printf '    | (completely empty)\n'
	fi
	return 1
}

OTHERPOL=$([ "$POL" = "VERTICAL" ] && echo HORIZONTAL || echo VERTICAL)

head2 "5. Attempt A - exactly what the panel sends"
try "panel settings as stored" "$DELSYS" "$POL" "2/3" "PSK/8" "$LNB" "$DISEQC"

head2 "6. Attempt B onwards - one variable at a time"
try "FEC AUTO, modulation PSK/8"   "$DELSYS" "$POL"      "AUTO" "PSK/8" "$LNB" "$DISEQC"
try "FEC AUTO, modulation QPSK"    "$DELSYS" "$POL"      "AUTO" "QPSK"  "$LNB" "$DISEQC"
try "the other polarization"       "$DELSYS" "$OTHERPOL" "AUTO" "QPSK"  "$LNB" "$DISEQC"
try "plain DVB-S instead of S2"    "DVBS"    "$POL"      "AUTO" "QPSK"  "$LNB" "$DISEQC"
try "DiSEqC port 1 (0-based 0)"    "$DELSYS" "$POL"      "AUTO" "QPSK"  "$LNB" "0"
try "DiSEqC port 2 (0-based 1)"    "$DELSYS" "$POL"      "AUTO" "QPSK"  "$LNB" "1"

# ------------------------------------------------------------------- the scan
#
# dvbv5-scan is what the panel's scan path actually runs, and it takes the
# tuning file with no channel name at all. If this locks while every zap above
# failed, the problem is the zap invocation, not the dish.

head2 "7. Cross-check with dvbv5-scan (no channel name involved)"

# NEVER pipe a running scanner into head: when head exits it closes the pipe,
# the scanner takes SIGPIPE and dies before it writes its output file. That is
# what made an earlier run of this script report "No services written" even
# though the scan had already listed every service. Capture to a file, then
# read the file.

run_scan() {
	label=$1 outconf=$2 logf=$3
	shift 3
	printf '\n%s\n' "$label"
	printf '    %s\n\n' "$*"
	timeout -k 1 180 "$@" > "$logf" 2>&1
	rc=$?
	sed 's/^/    /' "$logf" | head -25
	printf '    [exit %d]\n' "$rc"
	if [ -s "$outconf" ]; then
		printf '    >>> output file written: %s services <<<\n' "$(grep -c '^\[' "$outconf")"
		grep '^\[' "$outconf" | head -25 | sed 's/^/      /'
		return 0
	fi
	printf '    no output file written.\n'
	return 1
}

if command -v dvbv5-scan >/dev/null 2>&1; then
	write_conf "$DELSYS" "$POL" "2/3" "PSK/8"

	# 7a. What the panel sent before 2.5.5. dvbv5-scan has no -t option (that
	#     is dvbv5-zap's --timeout), so this is expected to be rejected
	#     outright. Shown so the failure is visible rather than assumed.
	set -- dvbv5-scan -a "$ADAPTER" -f 0 -o "$WORK/old.conf" -O DVBV5 -t 2 -l "$LNB"
	[ -n "$DISEQC" ] && set -- "$@" -S "$DISEQC"
	set -- "$@" "$WORK/try.conf"
	run_scan "7a. the old panel command, with -t 2 (expected to FAIL)" \
		"$WORK/old.conf" "$WORK/old.log" "$@"

	# 7b. The corrected command: -F keeps the scan on this transponder instead
	#     of chasing every frequency the NIT advertises.
	set -- dvbv5-scan -a "$ADAPTER" -f 0 -o "$WORK/new.conf" -O DVBV5 -F -l "$LNB"
	[ -n "$DISEQC" ] && set -- "$@" -S "$DISEQC"
	set -- "$@" "$WORK/try.conf"
	if run_scan "7b. the corrected command, with -F (expected to WORK)" \
		"$WORK/new.conf" "$WORK/new.log" "$@"; then
		LOCKED=1
		cp "$WORK/new.conf" /tmp/dvb-scan-result.conf 2>/dev/null &&
			printf '\n    Saved a copy at /tmp/dvb-scan-result.conf\n'
	fi
else
	printf '  dvbv5-scan not installed.\n'
fi

rule
if [ "$LOCKED" = "1" ]; then
	printf 'RESULT: something locked. Copy the winning values into the panel.\n'
else
	printf 'RESULT: nothing locked.\n\n'
	if grep -qi 'Device or resource busy' "$WORK"/*.log 2>/dev/null; then
		printf 'The frontend was busy for these tests, so they say NOTHING about\n'
		printf 'your dish. Free the tuner and run this again.\n'
		rule
		exit 1
	fi
	printf 'If a level was reported but never a lock, the dish and LNB are alive\n'
	printf 'and the frequency, symbol rate or LNB band is wrong. If every attempt\n'
	printf 'showed no level at all, suspect LNB power, cabling, the DiSEqC port,\n'
	printf 'or dish alignment.\n\n'
	printf 'Next checks:\n'
	printf '  dvbv5-zap -l help      list the LNBf names this build accepts\n'
	printf '  FREQ=<other> bash %s\n' "$(basename "$0")"
	printf '  UNIVERSAL covers 10800-11800 (LO 9750) and 11600-12700 (LO 10600).\n'
	printf '  A C-band dish needs LNB=C-BAND and a frequency near 3700000 kHz.\n'
fi
rule
