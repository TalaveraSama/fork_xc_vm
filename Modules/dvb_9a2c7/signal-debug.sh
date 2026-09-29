#!/bin/bash
#
# DVB signal bench.
#
# Reproduces by hand exactly what the panel's signal meter does, prints the raw
# tool output the panel hides, and then walks a ladder of variations until one
# of them locks. Run it on the node that physically holds the card.
#
#   bash signal-debug.sh
#   FREQ=11970000 POL=HORIZONTAL bash signal-debug.sh
#
# Every parameter is an environment variable so nothing has to be edited:
#
#   ADAPTER  tuner number                      (default 0)
#   FREQ     frequency in kHz for satellite    (default 10970000 = 10970 MHz)
#   POL      HORIZONTAL | VERTICAL             (default VERTICAL)
#   SRATE    symbol rate in symbols/second     (default 27900000 = 27900 kS/s)
#   DELSYS   DVBS2 | DVBS                      (default DVBS2)
#   LNB      LNBf name, see -l below           (default UNIVERSAL)
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
		printf '  BUSY - something already holds the frontend:\n'
		fuser -v "$FE" 2>&1 | sed 's/^/    /'
		printf '\n  A busy frontend is why a meter reads nothing. Stop that process\n'
		printf '  (a running stream, or a stray dvbv5-zap) and run this again.\n'
	else
		printf '  free\n'
	fi
else
	printf '  fuser not installed (apt-get install psmisc) - cannot tell.\n'
fi

head2 "4. What the frontend says it is"
dvb-fe-tool -a "$ADAPTER" 2>&1 | sed 's/^/  /'

# ------------------------------------------------------------------ the ladder
#
# Each attempt writes a tuning file and runs the same dvbv5-zap invocation the
# panel uses. The only thing that changes is the parameter under suspicion.

attempt_no=0

try() {
	label=$1 delsys=$2 pol=$3 fec=$4 mod=$5 lnbf=$6 diseqc=$7
	attempt_no=$((attempt_no + 1))

	conf="$WORK/try.conf"
	{
		printf '[CHANNEL]\n'
		printf '\tDELIVERY_SYSTEM = %s\n' "$delsys"
		printf '\tFREQUENCY = %s\n' "$FREQ"
		printf '\tPOLARIZATION = %s\n' "$pol"
		printf '\tSYMBOL_RATE = %s\n' "$SRATE"
		printf '\tINNER_FEC = %s\n' "$fec"
		if [ "$delsys" = "DVBS2" ]; then
			printf '\tMODULATION = %s\n' "$mod"
			printf '\tROLLOFF = AUTO\n'
			printf '\tPILOT = AUTO\n'
		fi
	} > "$conf"

	set -- dvbv5-zap -c "$conf" -a "$ADAPTER" -f 0 -m -t "$SECS" -l "$lnbf"
	[ -n "$diseqc" ] && set -- "$@" -S "$diseqc"
	set -- "$@" CHANNEL

	printf '\n[%d] %s\n' "$attempt_no" "$label"
	printf '    %s\n' "$*"

	out="$WORK/try.log"
	timeout -k 1 $((SECS + 4)) "$@" > "$out" 2>&1

	# dvbv5-zap prints one status line per second; the last one is the verdict.
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

head2 "5. Attempt A - exactly what the panel sent"
LOCKED=0
try "panel settings as stored" "$DELSYS" "$POL" "2/3" "PSK/8" "$LNB" "$DISEQC"

if [ "$LOCKED" = "0" ]; then
	head2 "6. Attempt B onwards - one variable at a time"

	# A DVB-S2 demodulator reads FEC and modulation out of the physical layer
	# header. Forcing the wrong pair blocks a lock on a perfectly good carrier,
	# so this is the single most likely fix.
	try "same, but FEC and modulation AUTO" "$DELSYS" "$POL" "AUTO" "AUTO" "$LNB" "$DISEQC" ||
	try "the other polarization" "$DELSYS" \
		"$([ "$POL" = "VERTICAL" ] && echo HORIZONTAL || echo VERTICAL)" \
		"AUTO" "AUTO" "$LNB" "$DISEQC" ||
	try "plain DVB-S instead of DVB-S2" "DVBS" "$POL" "AUTO" "QPSK" "$LNB" "$DISEQC" ||
	try "DiSEqC port 1 (0-based 0)" "$DELSYS" "$POL" "AUTO" "AUTO" "$LNB" "0" ||
	try "DiSEqC port 2 (0-based 1)" "$DELSYS" "$POL" "AUTO" "AUTO" "$LNB" "1" ||
	try "no DiSEqC at all" "$DELSYS" "$POL" "AUTO" "AUTO" "$LNB" ""
fi

rule
if [ "$LOCKED" = "1" ]; then
	printf 'RESULT: a lock was achieved. Copy the winning values into the panel.\n'
else
	printf 'RESULT: nothing locked.\n\n'
	printf 'Read attempt A above:\n'
	printf '  - a level was reported but never a lock -> the dish and LNB are\n'
	printf '    alive; frequency, symbol rate or LNB band is wrong.\n'
	printf '  - every attempt printed nothing at all   -> no LNB power, wrong\n'
	printf '    LNB type, dead cable, or the frontend is held by another process.\n\n'
	printf 'Useful next checks:\n'
	printf '  dvbv5-zap -l help           list the LNBf names this build accepts\n'
	printf '  FREQ=<other> bash %s\n' "$(basename "$0")"
	printf '  A C-band LNB needs LNB=C-BAND and a frequency near 3700000 kHz,\n'
	printf '  not 10970000. Confirm which band the dish actually feeds.\n'
fi
rule
