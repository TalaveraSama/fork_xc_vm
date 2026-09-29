#!/bin/bash
#
# DVB streaming chain bench.
#
# signal-debug.sh answers "is the carrier locked". This answers the next
# question: given a locked carrier, where between the tuner and the player does
# the picture disappear? Run it on the node that holds the card.
#
#   bash stream-debug.sh
#
# The chain, and what breaks it at each hop:
#
#   dvblast   tunes and remuxes one service per UDP port. It does NOT
#             descramble. By default it strips the conditional access tables,
#             so the module passes -Y (ecm-passthrough) and -W
#             (emm-passthrough) whenever any service on the carrier is being
#             decrypted. Without them tsdecrypt connects to the card server
#             and then waits for ECMs that never arrive.
#   tsdecrypt reads that UDP, pulls the ECMs out, asks the CAMD server for the
#             control word, descrambles, and writes clean UDP to a second
#             port. It picks the ECM PID out of the PMT by CA system: if the
#             configured CA system does not match the CAID actually in the
#             stream it finds no ECM PID at all and never sends a single
#             request, which on the server looks like a client that logs in
#             and then goes quiet.
#   the panel reads the second port.
#
# By contrast TVHeadend, Cesbo Astra and a GTMedia receiver descramble inside
# one process: they demux, read the ECM PID straight from the PMT, talk to the
# card server and apply the control word without any UDP hop. Fewer places to
# break, but the same two requirements: the ECMs must reach the descrambler,
# and the descrambler must be looking for the right CA system.

# CACHE_TMP_PATH is /home/xc_vm/tmp/cache/, not /home/xc_vm/tmp/. Getting this
# wrong made an earlier run of this script report "nothing has ever been
# started" while looking in a directory the module never uses.
WORK=${WORK:-}
SECS=${SECS:-6}

rule() { printf '\n%s\n' '--------------------------------------------------------------'; }
head2() { rule; printf '%s\n\n' "$1"; }

head2 "1. Work directory"
CANDIDATES="/home/xc_vm/tmp/cache/dvb /home/xc_vm/tmp/dvb /tmp/dvb"
if [ -n "$WORK" ]; then
	CANDIDATES="$WORK $CANDIDATES"
fi
FOUNDDIR=""
for d in $CANDIDATES; do
	printf '  %-32s %s\n' "$d" "$([ -d "$d" ] && echo exists || echo 'not there')"
	[ -d "$d" ] && [ -z "$FOUNDDIR" ] && FOUNDDIR=$d
done
if [ -z "$FOUNDDIR" ]; then
	printf '\n  None of them exist, so dvblast was never launched: start() creates\n'
	printf '  this directory before writing its config. Ask the panel why:\n\n'
	printf '    /home/xc_vm/bin/php/bin/php /home/xc_vm/console.php cron:dvb\n\n'
	printf '  Parent directory, which is where a permission problem shows up:\n'
	ls -ld /home/xc_vm/tmp /home/xc_vm/tmp/cache 2>&1 | sed 's/^/    /'
	exit 1
fi
WORK=$FOUNDDIR
printf '\n  using %s\n\n' "$WORK"
ls -ld "$WORK" | sed 's/^/    /'
ls -la "$WORK" | sed 's/^/    /'

head2 "2. Is dvblast running?"
DVBLAST=$(pgrep -a dvblast 2>/dev/null)
if [ -n "$DVBLAST" ]; then
	printf '%s\n' "$DVBLAST" | sed 's/^/    /'
	printf '\n  ECM passthrough (-Y): '
	printf '%s\n' "$DVBLAST" | grep -q -- ' -Y' && printf 'yes\n' || printf 'NO - tsdecrypt will never see an ECM\n'
	printf '  EMM passthrough (-W): '
	printf '%s\n' "$DVBLAST" | grep -q -- ' -W' && printf 'yes\n' || printf 'no\n'
else
	printf '    not running.\n\n'
	printf '  This alone explains "no UDP at all". Nothing is being produced,\n'
	printf '  so every stage downstream is starved. Check that the transponder\n'
	printf '  is marked streaming in the panel and that cron:dvb is running.\n'
fi

head2 "3. The dvblast config files"
found=0
for f in "$WORK"/*.conf "$WORK"/*.cfg; do
	[ -f "$f" ] || continue
	found=1
	printf '  %s\n' "$f"
	sed 's/^/      /' "$f"
	printf '\n'
done
[ "$found" = "0" ] && printf '  none. dvblast was never handed a config.\n'

head2 "4. Is tsdecrypt running?"
TSD=$(pgrep -a tsdecrypt 2>/dev/null)
if [ -n "$TSD" ]; then
	printf '%s\n' "$TSD" | sed 's/^/    /'
	printf '\n  CA selection actually in use:\n'
	printf '%s\n' "$TSD" | grep -oE '(-C|-c) [A-Za-z0-9]+' | sort -u | sed 's/^/      /'
	printf '\n  -C <caid> is exact. -c <name> defaults to CONAX and will find no\n'
	printf '  ECM PID on, say, a Nagravision (1802) carrier - which is exactly\n'
	printf '  how "the server never asks for an ECM" happens.\n'
else
	printf '    not running.\n'
fi

head2 "5. tsdecrypt logs"
found=0
for f in "$WORK"/cw*.log; do
	[ -f "$f" ] || continue
	found=1
	printf '  %s  (last 15 lines)\n' "$f"
	tail -n 15 "$f" | sed 's/^/      /'
	printf '\n'
done
[ "$found" = "0" ] && printf '  none.\n'

head2 "6. Other logs in the work directory"
found=0
for f in "$WORK"/*.log; do
	[ -f "$f" ] || continue
	case "$f" in */cw*.log) continue;; esac
	found=1
	printf '  %s  (last 12 lines)\n' "$f"
	tail -n 12 "$f" | sed 's/^/      /'
	printf '\n'
done
[ "$found" = "0" ] && printf '  none.\n'

# ------------------------------------------------------------------- the wire
#
# Ports come out of the config files, so this measures exactly what the module
# told dvblast to use rather than a guess.

head2 "7. Is anything actually on the wire?"
PORTS=$(cat "$WORK"/*.conf 2>/dev/null | grep -oE '[0-9]{1,3}(\.[0-9]{1,3}){3}:[0-9]+' | cut -d: -f2 | sort -un)

if [ -z "$PORTS" ]; then
	printf '  No ports found in the config files, nothing to listen for.\n'
elif ! command -v tcpdump >/dev/null 2>&1; then
	printf '  tcpdump not installed (apt-get install tcpdump).\n'
	printf '  Ports the config mentions: %s\n\n' "$(echo $PORTS | tr '\n' ' ')"
	printf '  Sockets currently open:\n'
	ss -ulnp 2>/dev/null | sed 's/^/    /' | head -30
else
	for p in $PORTS; do
		printf '  udp port %s: ' "$p"
		n=$(timeout "$SECS" tcpdump -nn -i any -c 50 "udp port $p" 2>/dev/null | grep -c 'IP')
		if [ "${n:-0}" -gt 0 ]; then
			printf '%s packets in %ss  -- flowing\n' "$n" "$SECS"
		else
			printf 'silent for %ss\n' "$SECS"
		fi
	done
	printf '\n  Read it as a chain. The lower port of each pair is what dvblast\n'
	printf '  writes (enc_port) and the higher is what tsdecrypt emits and the\n'
	printf '  panel reads (output_port).\n'
	printf '    both silent          -> dvblast is not producing\n'
	printf '    first only           -> tsdecrypt is not decrypting, see 4 and 5\n'
	printf '    both flowing, black  -> descrambling runs but the control words\n'
	printf '                            are wrong, so check the CAMD account\n'
fi

rule
printf 'Paste this whole output back.\n'
rule
