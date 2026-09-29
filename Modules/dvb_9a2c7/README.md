# DVB module for XC_VM

Native DVB tuner support. Define a transponder in the panel, scan it, import the
services you want as live channels — without Cesbo Astra and without TVHeadend.

Built and tested against a **TBS6909X** (DVB-S/S2/S2X, eight tuners), but
nothing in it is TBS-specific: it drives the standard Linux DVB API, so any
card with a mainline or vendor driver works.

## How the pieces fit

The panel usually runs on a VPS, which cannot hold a PCIe card. So the card
lives on a separate machine, and that machine is registered as an ordinary
**XC_VM streaming server**.

```
  ┌────────────────┐        MySQL         ┌──────────────────────┐
  │  Panel (VPS)   │ ───── dvb_jobs ────► │  Tuner node          │
  │  you click     │                      │  cron:dvb (1/min)    │
  │  "Scan"        │ ◄──── results ────── │  dvbv5-scan → card   │
  └────────────────┘                      └──────────────────────┘
```

Streaming nodes are installed pointing their MySQL at the main server
(`Cli/Commands/LbInstallFlow.php`), so the database is already a working,
authenticated channel between the two. The panel inserts a row into `dvb_jobs`;
`cron:dvb` on the tuner node claims rows addressed to its own `SERVER_ID`,
drives the hardware, and writes the answer back.

The alternative — adding an action to the node's internal API — is not
available: `Public/Controllers/Api/InternalApiController.php` is a fixed
`switch` whose `default:` returns `{"result":false}`, with no module hook.
(`Router::dispatchApi()` is the *panel's* admin API, not the node's.)

Consequence worth knowing: **there is up to a minute of latency** between
pressing Scan and the tuner moving. That is the cron tick, not a hang.

## Setting up the tuner node

### 1. Driver

TBS ships an open-source driver. On the tuner machine:

```sh
mkdir ~/tbsdriver && cd ~/tbsdriver
git clone https://github.com/tbsdtv/media_build.git
git clone --depth=1 https://github.com/tbsdtv/linux_media.git -b latest ./media
cd media_build
make dir DIR=../media
make -j4
make install
wget http://www.tbsdtv.com/download/document/linux/tbs-tuner-firmwares_v1.0.tar.bz2
tar jxvf tbs-tuner-firmwares_v1.0.tar.bz2 -C /lib/firmware/
reboot
```

Verify — a 6909X should give you eight frontends:

```sh
lspci | grep -i tbs
ls /dev/dvb            # adapter0 .. adapter7
dmesg | grep -i frontend
```

### 2. Userspace tools

```sh
apt-get install dvb-tools dvblast
```

`dvbv5-scan` (from `dvb-tools`) does the scanning and `dvblast` does the
streaming. `dvb-fe-tool` is optional: without it adapters are still discovered,
they just show up unnamed.

### 3. Permissions

The panel runs as `xc_vm`, and DVB device nodes belong to group `video`:

```sh
usermod -aG video xc_vm
```

Skip this and every scan fails with *Permission denied on the tuner device*.

### 4. Register the node

Add the machine as a streaming server in the panel as usual. Nothing
DVB-specific is required at this step.

## Using it

1. **Streams → DVB Tuners → Discover adapters**, choosing the tuner node.
   Within a minute the adapters appear.
2. **Add Transponder**: pick the node, type frequency / polarization / symbol
   rate, choose the LNB and DiSEqC port. Press **Save and scan**.
3. Watch the row: `scanning` → `ok` with a service count, or an error that says
   what to fix.
4. **Streams → DVB Services** lists what was found. Tick the ones you want,
   choose a category and bouquets, and press **Import selected**. Within a
   minute DVBlast starts and the channels carry picture.

Units are the usual trap, so the form is forgiving: a satellite frequency typed
as `11778` is read as MHz and converted to kHz, and a symbol rate of `27500` is
read as kSym/s. You are told when that happens.

If a transponder will not lock, the **Show tuning file** button prints exactly
what is handed to the tuner. Wrong polarization and a frequency in the wrong
unit are both obvious at a glance there.

## Running it by hand

```sh
sudo /home/xc_vm/bin/php/bin/php /home/xc_vm/console.php cron:dvb
```

Run on the **tuner node**, not the panel. It claims one job and reports the
outcome. The system crontab entry is installed automatically:

```sh
crontab -u xc_vm -l | grep cron:dvb
# * * * * * /home/xc_vm/bin/php/bin/php /home/xc_vm/console.php cron:dvb # XC_VM
```

If that line is missing, `console.php status` rewrites the crontab.

## Streaming: how a service becomes a channel

Importing a service does five things:

1. allocates a UDP port on the tuner node;
2. creates a `streams` row whose source is `udp://127.0.0.1:<port>`;
3. pins it to the tuner node in `streams_servers`;
4. marks the transponder as "should be streaming";
5. queues a job so DVBlast is rebuilt with the new output.

DVBlast then tunes the carrier **once** and fans every imported service out to
its own port. A transponder with twenty imported channels costs one tuner, not
twenty — which is the whole reason for using it.

The output address is loopback on purpose. The channel runs on the same machine
that holds the card, so ffmpeg reads the loopback: no multicast routing, no IGMP,
nothing on the wire. Set the transponder's **Output host** to a `239.x.y.z` group
only when a second machine has to receive the same services.

A supervisor runs on every cron tick and restarts any DVBlast that should be up
and is not, so streams come back by themselves after the node reboots.

### Two things DVBlast cannot do

- **Multistream (ISI/PLS) carriers.** It has no option for them. Such a
  transponder can be scanned but not streamed, and the module says so instead
  of tuning the wrong thing.
- **Non-universal LNBs.** It assumes 9750/10600 with the switch at 11700 and
  cannot be told another local oscillator. Scanning honours whatever LNB you
  picked; streaming refuses anything but `UNIVERSAL`.

### DiSEqC numbering, a trap worth knowing

dvbv5 counts satellites from 0 and DVBlast from 1. The module stores the port
the way an operator says it (1-4) and converts per tool. If you ever drive these
binaries by hand, getting this wrong gives you a healthy lock on the wrong
satellite — which looks like a working system showing the wrong channels.

## Tables

| Table | Holds |
|---|---|
| `dvb_adapters` | frontends found on each node |
| `dvb_transponders` | what the operator defined |
| `dvb_services` | what scanning found, and the panel stream each maps to |
| `dvb_jobs` | the panel → tuner-node work queue |

Uninstalling drops only the module's cron row. Imported channels are left
alone: by then they are ordinary panel streams, and deleting a subscriber-facing
line-up on uninstall would be destructive.
