# Installing the DVB module on Ubuntu

What a clean Ubuntu box needs before this module can drive a card, in the
order it has to happen, and which parts come from outside this repository.

Written after commissioning a TBS6909X on Ubuntu 20.04 and 22.04. Every
warning below is something that actually went wrong, not a precaution.

---

## Part 0 — a clean panel, before any of this

The DVB module needs a working panel underneath it. On a bare Ubuntu:

```sh
# 1. A supported release, as root, on a box with nothing else on it.
#    Supported: Ubuntu 18.04 / 20.04 / 22.04 / 24.04, Debian 11 / 12 / 13,
#    Rocky 8 / 9, AlmaLinux 8 / 9, CentOS 7 / 8.
lsb_release -a

# 2. Fetch the release from this fork, not from upstream.
cd /root
curl -fL -O https://github.com/TalaveraSama/fork_xc_vm/releases/latest/download/xc_vm.tar.gz
curl -fsSL https://github.com/TalaveraSama/fork_xc_vm/releases/latest/download/hashes.md5
md5sum xc_vm.tar.gz          # compare the two before going on

# 3. Extract and run. The installer ships at the root of the archive.
mkdir -p /root/xcvm && tar xzf xc_vm.tar.gz -C /root/xcvm
cd /root/xcvm
python3 install
```

It creates the `xc_vm` user, the `xc_vm` and `xc_vm_migrate` databases, a
MariaDB account with a generated password, `/etc/systemd/system/xc_vm.service`,
`/etc/sudoers.d/xc_vm`, a tmpfs line in `/etc/fstab` for
`/home/xc_vm/content/streams`, and the crontab entries.

**Write down the admin URL and credentials it prints at the end.** They are
generated and not shown again.

Then confirm it is actually up before going further:

```sh
systemctl status xc_vm --no-pager | head -5
grep XC_VM_VERSION /home/xc_vm/Core/Config/AppConfig.php
curl -sS -o /dev/null -w '%{http_code}\n' http://127.0.0.1/
```

Two things worth knowing at this point, both of which bit this deployment:

* **Install the panel before building the card driver.** The installer runs
  `apt-get autoremove -y`, which can take `build-essential`, `gcc`, `make` and
  `libssl-dev` with it, since nothing it installs depends on them. Doing it in
  this order avoids the problem entirely.
* **`v4l-utils` goes on, `dvb-tools` does not.** See the next section; the
  panel install alone cannot scan a transponder.

Everything below is the DVB layer, on top of a panel that already works.

---

## The short version

| Layer | Comes from | Installed by |
| --- | --- | --- |
| Card driver | **Outside the repo** — TBS / LinuxTV sources | You, by hand |
| `dvbv5-scan`, `dvbv5-zap`, `dvb-fe-tool` | Ubuntu `dvb-tools` | `install-tuner-node.sh` |
| `dvblast` | Ubuntu `dvblast` | `install-tuner-node.sh` |
| `tsdecrypt` | **Vendored in this module**, compiled locally | `install-tuner-node.sh` |
| Card server (OSCam / ncam) | **Outside the repo** | You, or your provider |
| Everything else | The panel release | `build/install` |

The panel installer alone is **not enough**. Read the next section before
assuming it is.

---

## 1. The trap: `v4l-utils` is not `dvb-tools`

`build/install` installs `v4l-utils` on every supported distribution. On
Debian and Ubuntu that package does **not** contain `dvbv5-scan`,
`dvbv5-zap` or `dvb-fe-tool` — those live in **`dvb-tools`**, built from the
same source but packaged separately, and in the `universe` component.

So a panel installed normally has `v4l-utils` present and still cannot scan a
transponder. The error you get is `dvbv5-scan not found on this node`, which
is accurate and still surprising if you saw `v4l-utils` go by during the
install.

`dvblast` is likewise absent from the panel install.

Both are handled by `install-tuner-node.sh`. Run it.

---

## 2. The card driver — entirely outside this repository

No package and no script here installs a driver. For a TBS card you build it
from TBS's fork of the LinuxTV tree:

```sh
apt-get install -y build-essential dkms git linux-headers-$(uname -r) \
    patchutils libproc-processtable-perl

git clone https://github.com/tbsdtv/media_build.git
git clone --depth=1 https://github.com/tbsdtv/linux_media.git -b latest ./media
cd media_build
make dir DIR=../media
make allyesconfig
make -j$(nproc)
make install
reboot
```

Confirm afterwards:

```sh
ls /dev/dvb/                       # adapter0 … adapterN
dmesg | grep -i frontend           # one line per demodulator
lspci -vvv | grep -i 6909          # for a TBS6909X
```

A TBS6909X presents **eight adapters**, one `frontend0` each.

Kernel upgrades break out-of-tree drivers. After `apt upgrade` touches the
kernel, `/dev/dvb` may disappear and the module will report that the driver is
not loaded. Rebuild before blaming the panel.

### Which adapter is which input

On a card with several physical inputs, the adapter number does not identify
the satellite — the cabling does. For a TBS6909X in its default arrangement:

| Input | Adapters | Carries |
| --- | --- | --- |
| 0 | 0, 1 | Vertical / low band |
| 1 | 2, 3 | Vertical / high band |
| 2 | 4, 5 | Horizontal / low band |
| 3 | 6, 7 | Horizontal / high band |

With a universal LNB the band boundary is 11700 MHz and the local oscillators
are 9750 and 10600 MHz. So 10970 MHz vertical is V/low → adapter 0 or 1, and
11174 MHz horizontal is H/low → adapter 4 or 5. Picking the wrong adapter
produces a carrier that never locks, with perfectly healthy hardware.

Verify your own cabling rather than trusting the table.

---

## 3. Run the tuner-node script

On the machine holding the card, **as root**, after the panel is installed and
the driver is loaded:

```sh
sh /home/xc_vm/Modules/dvb_9a2c7/install-tuner-node.sh
```

It is idempotent — running it twice is safe — and it does four things.

**Installs the packages.** On Debian and Ubuntu:
`dvb-tools`, `dvblast`, `build-essential`, `libssl-dev`.

**Builds tsdecrypt** from the copy vendored at
`Modules/dvb_9a2c7/vendor/tsdecrypt`, into a temporary directory, and installs
to `/usr/local` (override with `PREFIX=`). It builds with `make ffdecsa`, not
plain `make`: FFdecsa ships inside tsdecrypt, so **libdvbcsa is not a
dependency**. The only external one is OpenSSL, which is why `libssl-dev` is
in the list.

**Adds the panel user to the `video` group**, without which `cron:dvb` cannot
open any frontend no matter what the file permissions say.

**Rewrites the crontab** so `cron:dvb` actually runs.

### Why tsdecrypt is vendored rather than cloned

Upstream's build instructions tell you to `git submodule update --init`, and
its `.gitmodules` points at `georgi.unixsol.org`, not GitHub. When that host
is unreachable the submodule update **returns success and leaves the
directories empty**, and the build then fails in a way that looks like a
compiler problem. Vendoring removes the dependency on a single host being up.

If you would rather not put a build toolchain on a streaming node, drop a
prebuilt binary at `vendor/prebuilt/tsdecrypt-$(uname -m)`; the script
installs it instead, after checking it actually runs. See
`vendor/prebuilt/README.md`, which explains why a binary copied from one
Ubuntu release will not load on another, and how to build one that travels.

The pinned commits are recorded in `vendor/PROVENANCE.txt` — tsdecrypt
`f4876e84`, libfuncs `55d6236c`, libtsfuncs `1482ed31`. The vendored tree is
release 10.0.

---

## 4. The one that catches everybody: autoremove

`build/install` runs `apt-get autoremove -y`. If the panel is installed
**after** you built the driver, that can take `build-essential`, `gcc`, `make`
and `libssl-dev` with it, because nothing installed by the panel depends on
them.

The symptoms are confusing: the driver still works, because it is already
compiled, but `install-tuner-node.sh` cannot build tsdecrypt and a later
kernel upgrade leaves you unable to rebuild the driver at all.

**Install the panel first, then the driver, then the tuner-node script.** If
you did it the other way round, just re-run `install-tuner-node.sh`; it puts
the build tools back.

---

## 5. Useful extras, not installed by anything

```sh
apt-get install -y psmisc tcpdump
```

`psmisc` provides `fuser`, which is the only convenient way to see which
process holds a frontend. `tcpdump` is what the bundled diagnostics use to
prove whether a UDP port is carrying anything. Neither is required to stream;
both turn "it does not work" into an answer.

---

## 6. Verify before touching the panel

Two scripts ship with the module. Run them in this order.

```sh
bash /home/xc_vm/Modules/dvb_9a2c7/dvb-verify.sh
```

Checks the whole precondition list **as the `xc_vm` user**, not as root, which
is the point: root can create directories and open frontends that the panel
cannot. It reports group membership, scratch-directory access, the four
binaries, which adapters are free and who holds the rest, then runs the real
scan command and lists the services found.

```sh
bash /home/xc_vm/Modules/dvb_9a2c7/stream-debug.sh
```

For when a carrier is on air but no picture arrives. Shows whether dvblast and
tsdecrypt are running and with which arguments, prints their configs and logs,
and measures the UDP ports.

There is also `signal-debug.sh`, which walks a ladder of tuning variations
until one locks — for aiming a dish or confirming a transponder's parameters.

---

## 7. The card server is not part of this

Descrambling needs a CAMD server (OSCam, ncam, or a provider's line). Nothing
here installs or configures one. The module speaks **NEWCAMD** and **CS378X**
through tsdecrypt.

Two settings on the CAMD profile are worth knowing before you start, because
both cost a lot of time when wrong:

**Set the CAID explicitly.** A transponder can advertise several CA systems of
the same family — one measured carrier carried Nagra `0x1802`, `0x1871` and
`0x187a` on three separate ECM PIDs. Naming only the family makes tsdecrypt
pick the last descriptor in the PMT, which need not be the one the card holds,
and the server answers "card was not able to decode" as though the
subscription were at fault. Write it with the prefix: `1802`, stored and
emitted as `0x1802`, because tsdecrypt parses it with `strtoul(base 0)` and
would read a bare `1802` as decimal.

**Leave "Forward EMMs" off** unless the provider asks for it. A busy mux
carries tens of thousands of EMMs a minute — 76,603 in sixty seconds on one
measured carrier — and forwarding them floods the card server, delaying
control words by up to eighteen seconds and making every channel stutter. A
card reporting `Admin=NO` has no AU rights and cannot use them at all.

With more than a handful of channels, consider a **local OSCam as a proxy**:
descrambling is per service, so twenty encrypted channels open twenty CAMD
logins, while one local proxy holds a single upstream session, caches control
words and serves them all.

---

## 8. Order of operations, start to finish

1. Install Ubuntu, then the panel (`build/install`).
2. Build and install the card driver. Reboot. Confirm `/dev/dvb`.
3. `sh /home/xc_vm/Modules/dvb_9a2c7/install-tuner-node.sh`
4. `apt-get install -y psmisc tcpdump`
5. `bash /home/xc_vm/Modules/dvb_9a2c7/dvb-verify.sh` — fix anything it reports.
6. In the panel: **Adapters → Discover adapters**.
7. Add a transponder, scan it, import the services.
8. Add the CAMD profile with its CAID, assign it to the imported services.

Steps 5 and 6 are the ones people skip. The module cannot pick a tuner it has
never been told about, and `dvb-verify.sh` exists because every failure it
checks for has happened at least once.
