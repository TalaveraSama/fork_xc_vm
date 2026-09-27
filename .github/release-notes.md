XC_VM **2.3.9** with the **Flussonic** module built in.

## Install

```bash
sudo apt update && sudo apt full-upgrade -y
sudo apt install -y curl wget python3 unzip

# download XC_VM.zip from this release, then:
unzip XC_VM.zip
sudo python3 install
```

The installer uses the `xc_vm.tar.gz` sitting next to it, so it deploys **this**
tree — not upstream's. Requirements are unchanged: a clean Ubuntu 22.04+ or
Debian 12 host, installed to `/home/xc_vm`.

The archive bundles the full runtime — the PHP 8.1 build with `xcvm_core.so`
and the ionCube loader, nginx, nginx-rtmp, redis, ffmpeg 4.0/7.1/8.0, yt-dlp
and the MaxMind databases — so the install still completes if
`XC_VM_Binaries` is unreachable. That is why it is larger than upstream's.

Already running a panel? Use `xc_vm.tar.gz` with the in-panel updater instead of
reinstalling.

## If the install dies on MariaDB or apt

A panel host usually carries a third-party repository from an earlier install.
When that mirror disappears — `ams2.mirrors.digitalocean.com` is a common one —
apt keeps serving its stale index, so `mariadb-server` resolves to a version it
can never download and the install ends in:

```
mariadb-server : Depends: mariadb-server-10.6 ... but it is not going to be installed
CRITICAL: Could not install MariaDB!
```

The installer now resolves every apt source host up front and comments out the
dead ones (backing up the file), so the distro's own MariaDB is used instead.
If it still fails, the message points at `apt-get update 2>&1 | grep -i '^Err'`.

## If the install ends with "Access denied"

`install` sets up MariaDB assuming root needs no password (the unix_socket
default on a fresh host). On a server that ran a panel before, root already
has one, every database step fails with `ERROR 1045`, and the panel then loops
on `Access denied`. This release fixes three things behind that:

- the installer now **verifies** the panel's account can reach its schema, and
  says `INSTALLATION INCOMPLETE` instead of claiming success;
- the OpenSSL 3 shim is skipped on glibc older than 2.34 — forcing Ubuntu
  22.04's `libssl3` onto 20.04 left dpkg unsatisfiable and made *every*
  subsequent `apt install` abort, MariaDB included;
- root's authentication plugin is read with `-N -B`, so socket auth is
  actually detected rather than compared against `| unix_socket |`.

Already hit it? The panel files are fine — only MariaDB is missing. Finish the
install without redoing it:

```bash
sudo python3 repair-database --dry-run   # show what it would create
sudo python3 repair-database
```

It reads the credentials the panel already expects from `config.ini`, creates
`xc_vm` and `xc_vm_migrate`, imports the schema, creates the account with
grants for both `localhost` and `127.0.0.1`, verifies the connection and
restarts the panel. It will ask for the MariaDB root password if it cannot get
in by itself.

## Uninstall

The zip also carries an `uninstall`, and the install puts a copy at
`/home/xc_vm/uninstall`:

```bash
sudo python3 uninstall --dry-run   # print the plan, change nothing
sudo python3 uninstall
```

It stops the service, drops the crontab, unmounts the two tmpfs filesystems
and removes their `/etc/fstab` entries, drops `xc_vm` and `xc_vm_migrate` with
the panel's DB user, deletes `/home/xc_vm` and the system account, and reverts
the systemd file-descriptor limit. A dump of both databases plus `config/`
lands in `/root/xc_vm-uninstall-<timestamp>/` first.

It leaves `/etc/sysctl.conf`, the MariaDB server config and the installed
packages alone, and says so at the end.

## What's different from upstream 2.3.9

Everything upstream ships, plus the Flussonic module — and nothing else.

### Flussonic module

Register Flussonic Media Server origins, browse every stream they publish, and
import the ones you pick as live channels.

- **Flussonic Servers** — connection (scheme, host, port, Basic auth or bearer,
  TLS), playback (protocol, CDN host/port, RTMP/RTSP ports, static token),
  import defaults (target server, category, bouquets, name prefix) and
  synchronisation (interval, auto-import, auto-remove). *Test Connection*
  probes an origin before you save it.
- **Available Streams** — alive state, bitrate, online clients, resolution and
  codecs, DVR depth and input URL for every stream across all origins, with
  filters by server, state and import status.
- **Import** — creates real panel channels in `streams`, so they show up in
  bouquets, playlists, the Xtream API and the streaming pipeline. Source URLs
  are built from the protocol you chose per server: HLS, LL-HLS, MPEG-TS, DASH,
  RTMP or RTSP. Imports are idempotent and DVR depth maps to
  `tv_archive_duration`.
- **`cron:flussonic`** — installed at `*/5 * * * *`; syncs each origin on its
  own interval and auto-imports when enabled.

Two tables are added, `flussonic_servers` and `flussonic_streams`. Uninstalling
drops them; channels already imported stay, as ordinary panel streams.

The module is also attached to the repository as a standalone
`flussonic_1.0.0.zip` for **Modules → Upload module** on an existing panel.

## A clean install, not a clone

This repository was captured from a running panel. The build strips the live
state so a fresh install does not inherit it: the encrypted credentials
(`config.enc`), the panel identity (`install_id`), the database dumps, the
caches and logs, and the nginx vhosts minted for that panel's access codes.

`bin/redis/redis.conf`, `bin/nginx/conf/*` and `config/modules.php` ship as
upstream's pristine templates — a captured copy of any of them is both a leak
and a bug. Redis in particular only gets a fresh password while its config
still reads `#PASSWORD#`.
