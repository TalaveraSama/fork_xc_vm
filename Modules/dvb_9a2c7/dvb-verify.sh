#!/bin/bash
#
# DVB pre-flight.
#
# Reproduces, step by step, everything the panel needs before a transponder
# can go on air -- and runs every step as the `xc_vm` user, because that is
# who cron:dvb runs as. Checking any of this as root proves nothing: root can
# write directories and open devices that the panel cannot.
#
# Run as root (it drops privileges itself):
#
#   bash dvb-verify.sh
#   FREQ=11970000 POL=HORIZONTAL bash dvb-verify.sh
#
#   PANELUSER  account the panel runs as     (default xc_vm)
#   ADAPTER    tuner number                  (default 0)
#   FREQ       kHz for satellite             (default 10970000)
#   POL        HORIZONTAL | VERTICAL         (default VERTICAL)
#   SRATE      symbols/second                (default 27900000)
#   DELSYS     DVBS2 | DVBS                  (default DVBS2)
#   MOD        QPSK | PSK/8 | APSK/16 …      (default PSK/8)
#   FEC        AUTO | 2/3 | 3/4 …            (default 2/3)
#   LNB        LNBf name                     (default UNIVERSAL)
#   DISEQC     0-based satellite number      (default off)

PANELUSER=${PANELUSER:-xc_vm}
ADAPTER=${ADAPTER:-0}
FREQ=${FREQ:-10970000}
POL=${POL:-VERTICAL}
SRATE=${SRATE:-27900000}
DELSYS=${DELSYS:-DVBS2}
MOD=${MOD:-PSK/8}
FEC=${FEC:-2/3}
LNB=${LNB:-UNIVERSAL}
DISEQC=${DISEQC:-}

WORK=/home/xc_vm/tmp/cache/dvb
FAIL=0

rule() { printf '\n%s\n' '--------------------------------------------------------------'; }
head2() { rule; printf '%s\n\n' "$1"; }
bad() { printf '  FAIL  %s\n' "$1"; FAIL=1; }
ok()  { printf '  ok    %s\n' "$1"; }

# Run a command as the panel user. Everything that the cron will have to do
# gets funnelled through here so a root-only success cannot mislead us.
asuser() { su -s /bin/sh -c "$1" "$PANELUSER" 2>&1; }

head2 "1. The panel account"
if ! id "$PANELUSER" >/dev/null 2>&1; then
	bad "no such user: $PANELUSER"
	exit 1
fi
printf '  %s\n\n' "$(id "$PANELUSER")"

if id -nG "$PANELUSER" | tr ' ' '\n' | grep -qx video; then
	ok "in the 'video' group, so it may open /dev/dvb"
else
	bad "NOT in the 'video' group. cron:dvb cannot open any tuner."
	printf '        Fix:  usermod -aG video %s   (then restart cron)\n' "$PANELUSER"
fi

head2 "2. The scratch directory"
printf '  %s\n\n' "$WORK"
for d in /home/xc_vm/tmp /home/xc_vm/tmp/cache "$WORK"; do
	if [ -d "$d" ]; then
		printf '    %s\n' "$(ls -ld "$d")"
	else
		printf '    %-28s does not exist\n' "$d"
	fi
done
printf '\n'

if [ ! -d "$WORK" ]; then
	printf '  Creating it as %s, which is what the panel would have to do:\n' "$PANELUSER"
	asuser "mkdir -p '$WORK'" | sed 's/^/    /'
fi

if [ -d "$WORK" ] && [ -z "$(asuser "touch '$WORK/.probe' && rm -f '$WORK/.probe' && echo ''")" ]; then
	ok "$PANELUSER can write there"
else
	bad "$PANELUSER cannot write there."
	printf '        Fix:  mkdir -p %s && chown -R %s:%s /home/xc_vm/tmp/cache\n' "$WORK" "$PANELUSER" "$PANELUSER"
fi

head2 "3. Tools"
for t in dvbv5-scan dvbv5-zap dvblast tsdecrypt; do
	p=$(command -v "$t" 2>/dev/null)
	if [ -n "$p" ]; then ok "$t  $p"; else bad "$t missing"; fi
done

head2 "4. Tuners"
if [ ! -d /dev/dvb ]; then
	bad "/dev/dvb does not exist: the driver is not loaded"
else
	for a in /dev/dvb/adapter*; do
		n=${a##*/adapter}
		fe="$a/frontend0"
		[ -e "$fe" ] || continue
		holder=$(fuser "$fe" 2>/dev/null | tr -d ' ')
		if [ -n "$holder" ]; then
			printf '    adapter%-2s held by pid %s (%s)\n' "$n" "$holder" \
				"$(ps -o comm= -p "$holder" 2>/dev/null)"
		else
			printf '    adapter%-2s free   %s\n' "$n" "$(ls -l "$fe" | awk '{print $1, $3, $4}')"
		fi
	done
	printf '\n'
	if [ -n "$(fuser /dev/dvb/adapter$ADAPTER/frontend0 2>/dev/null)" ]; then
		bad "adapter $ADAPTER is busy; free it before testing"
	else
		ok "adapter $ADAPTER is free"
	fi
fi

head2 "5. The tuning file the panel writes"
CONF="$WORK/verify.conf"
OUT="$WORK/verify-out.conf"
{
	printf '[CHANNEL]\n'
	printf '\tDELIVERY_SYSTEM = %s\n' "$DELSYS"
	printf '\tFREQUENCY = %s\n' "$FREQ"
	printf '\tPOLARIZATION = %s\n' "$POL"
	printf '\tSYMBOL_RATE = %s\n' "$SRATE"
	printf '\tINNER_FEC = %s\n' "$FEC"
	if [ "$DELSYS" = "DVBS2" ]; then
		printf '\tMODULATION = %s\n' "$MOD"
		printf '\tROLLOFF = AUTO\n'
		printf '\tPILOT = AUTO\n'
	fi
} > "$CONF" 2>/dev/null || { bad "cannot write $CONF"; exit 1; }
chown "$PANELUSER":"$PANELUSER" "$CONF" 2>/dev/null
cat -n "$CONF" | sed 's/^/    /'

head2 "6. The exact scan the panel runs, as $PANELUSER"
SCAN="dvbv5-scan -a $ADAPTER -f 0 -o '$OUT' -O DVBV5 -F -l '$LNB'"
[ -n "$DISEQC" ] && SCAN="$SCAN -S $DISEQC"
SCAN="$SCAN '$CONF'"
printf '  %s\n\n' "$SCAN"

rm -f "$OUT"
asuser "timeout -k 1 180 $SCAN" > "$WORK/verify.log" 2>&1
printf '  exit %s, output:\n\n' "$?"
sed 's/^/    /' "$WORK/verify.log" | tail -n 40

head2 "7. Verdict"
if [ -s "$OUT" ]; then
	n=$(grep -c '^\[' "$OUT")
	ok "$n service(s) written to $OUT"
	grep '^\[' "$OUT" | sed 's/^/      /'
	printf '\n  The DVB side is healthy: %s can open the tuner, lock the\n' "$PANELUSER"
	printf '  carrier and enumerate services. Anything still broken is\n'
	printf '  downstream of here.\n'
else
	bad "no services written"
	if grep -qi 'busy' "$WORK/verify.log"; then
		printf '        The frontend was busy. Stop whatever holds it.\n'
	elif grep -qi 'permission\|denied' "$WORK/verify.log"; then
		printf '        Permission denied. See step 1: the video group.\n'
	elif grep -qi 'lock' "$WORK/verify.log"; then
		printf '        It locked but wrote nothing. Check the output path.\n'
	else
		printf '        No lock. Re-check frequency, polarization and LNB band.\n'
	fi
fi

rm -f "$CONF"

rule
[ "$FAIL" = "0" ] && printf 'All checks passed.\n' || printf 'Something above needs fixing. Paste the whole output back.\n'
rule
