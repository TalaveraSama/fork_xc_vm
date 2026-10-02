# Release build

`build-release.sh` assembles an installable XC_VM release entirely from this
repository — nothing is downloaded.

The deploy tree (`/home/xc_vm/`) comes from two layers:

1. **Binary layer** — `bin/`, ~700 MB of bundled runtime: the PHP 8.1 build
   carrying `xcvm_core.so` and the ionCube loader, nginx, nginx-rtmp, redis,
   ffmpeg/ffprobe (4.0, 7.1, 8.0), yt-dlp, the MaxMind databases and the
   install schema. It is committed on the default branch so source branches
   stay light; the script finds it automatically (`HEAD`, then `origin/main`).
2. **Source layer** — this repository's tracked files, copied over it.

Because the binary layer bundles PHP and nginx, the archive installs even when
`XC_VM_Binaries` is unreachable: the installer tries to refresh the
distribution binaries and treats a failure as non-fatal.

## Usage

```bash
build/build-release.sh                        # auto-detect the binary layer
build/build-release.sh --bin-ref origin/main --out /tmp/dist
build/build-release.sh --bin-dir /path/to/bin # bin/ already on disk
```

Output, using upstream's asset names:

| File | What it is |
| --- | --- |
| `xc_vm.tar.gz` | the deploy tree, extracted to `/home/xc_vm/` |
| `XC_VM.zip` | `install` + `uninstall` + `repair-database` + `xc_vm.tar.gz` |
| `hashes.md5` | md5 of both |

## Distribution: TalaveraSama/fork_xc_vm

The fork holds a **clean copy of the panel** and builds its own releases, so
nothing has to be moved across by hand:

```bash
bash build/publish-source-to-fork.sh --dry-run   # assemble and check, push nothing
bash build/publish-source-to-fork.sh             # push
```

By default the emitted tree is **source only, about 120 MB** — small enough
to push with an ordinary client. `bin/` is 677 MB and is left out on purpose:
the fork's CI takes a whole deploy tree from a release archive instead, its
own `base-<version>` mirror first and upstream second. That also means the
base is pristine rather than captured from a running panel, whose stale logs
and pid files are exactly what stopped nginx and php-fpm from starting.
`--with-bin` restores the heavy behaviour.

It needs **git only**. There is deliberately no GitHub CLI dependency: `gh` is
not always the GitHub CLI — the `gitsome` package installs a command by the
same name — and the push is plain git anyway. Visibility is checked over the
public API with curl or wget, and failing to check is a warning rather than a
refusal.

### Publishing with GitHub Desktop

`--out` stops before pushing and leaves the finished repository where a GUI
client can open it, which also sidesteps the token entirely — GitHub Desktop
handles authentication itself:

```bash
bash build/publish-source-to-fork.sh --out ~/xcvm-fork
```

Then **File > Add local repository** pointed at that folder, and **Publish
repository** with *Keep this code private* unticked. It is already a git
repository with exactly one commit, so there is nothing to stage or write.

Expect the push to take a while: 6718 files and about 800 MB, most of it the
bundled runtime.

### Pushing with a deploy key

A deploy key is scoped to a single repository and needs no account token. The
private half is generated on the machine that pushes and never leaves it;
only the public half is registered:

```bash
ssh-keygen -t ed25519 -C xcvm-fork -f ~/.ssh/fork_xc_vm -N ''
cat ~/.ssh/fork_xc_vm.pub     # register this at Settings > Deploy keys
```

Tick **Allow write access**, or the push is rejected. Then:

```bash
printf 'Host github-fork\n  HostName github.com\n  User git\n  IdentityFile ~/.ssh/fork_xc_vm\n  IdentitiesOnly yes\n' >> ~/.ssh/config
cd ~/xcvm-fork
git remote add origin git@github-fork:TalaveraSama/fork_xc_vm.git
git push -u origin main
```

A key already registered as a deploy key on another repository cannot be
reused; GitHub requires each to be unique.

### Pushing from the command line

GitHub no longer accepts passwords over HTTPS, so pass an authenticated
remote if the push cannot find credentials:

```bash
FORK_URL='https://USER:TOKEN@github.com/TalaveraSama/fork_xc_vm.git' \
  bash build/publish-source-to-fork.sh
FORK_URL='git@github.com:TalaveraSama/fork_xc_vm.git' \
  bash build/publish-source-to-fork.sh
```

A plain `git push` of this repository would be a disclosure rather than a
mirror: its history carries a 7 MB database dump, `tmp/cache/hmac_keys` and
2754 runtime cache files, and the default branch adds `config.enc`,
`install_id`, a second dump, the panel's Redis password and its TLS private
key. So the script pushes **no history at all**. It assembles the tree the way
`build-release.sh` does — source layer, binary layer, upstream config
templates — and commits it once. Nothing outside that tree can leak, because
no earlier commit exists to hold it.

It then checks the result rather than trusting the exclusions: known private
paths, a `requirepass` that is not `#PASSWORD#`, and any private key whose
content is not upstream's public placeholder — allowed by hash, not by path,
so a real key at the same filename is still caught.

The fork receives all three workflows, so from then on it builds releases,
mirrors the binaries and watches upstream on its own.

### Publishing only the release assets

That public repository is where this panel is published, so installing and
updating no longer reach Vateron-Media. One command sets it up:

```bash
gh api repos/TalaveraSama/chunklist-xc_vm/contents/build/setup-fork.sh \
   --jq .content -H "Accept: application/vnd.github+json" \
   | base64 -d > setup-fork.sh && bash setup-fork.sh
```

One file is enough — it fetches whatever else it needs from this repository,
so there is no need to clone a working tree well past a hundred megabytes. It
pushes the binaries mirror workflow and copies this repository's newest
release across. Run it from your own machine: the CI token here is scoped to
this repository and can neither push nor publish elsewhere.

It holds two kinds of release side by side:

| Tag | Contents | Who reads it |
| --- | --- | --- |
| `2.4.0` (a version) | `xc_vm.tar.gz`, `XC_VM.zip`, `hashes.md5` | the installer, and the panel's own updater |
| `binaries-29062026` | the six per-distribution tarballs | `install` when replacing `bin/` |

They cannot share a namespace: the updater compares tags with PHP's
`version_compare`, so the panel release must look like a version, and the
binaries are published as prereleases with a `binaries-` prefix to stay out
of its way. `get_latest_binaries_tag()` prefers a `binaries-` tag and falls
back to the newest stable release, which is what upstream's single-purpose
repository publishes.

`GIT_OWNER_MAIN` and `GIT_OWNER_BIN` in `Core/Config/AppConfig.php` point the
panel at the fork. They are separate from `GIT_OWNER` on purpose: GeoIP
(`XC_VM_Update`) and the proxy node (`XC_VM_Proxy`) are data this fork adds
nothing to, so they keep coming from upstream.

With this in place the panel's Update button is safe — it now finds this
fork's releases, which carry the patches, instead of upstream's, which would
revert them.

## Binaries mirror

The installer replaces the bundled `bin/` with a build matched to the host
distribution, downloaded from a binaries repository. `BINARIES_SOURCES` in
`build/install` lists where to look, in order:

```python
BINARIES_SOURCES = [
    ("TalaveraSama", "chunklist-xc_vm-binaries"),
    ("Vateron-Media", "XC_VM_Binaries"),
]
```

The mirror is tried first so an install does not depend on upstream staying
reachable; upstream is an automatic fallback, so a missing mirror degrades to
today's behaviour instead of breaking installs.

`build/binaries-mirror/` holds everything the mirror repository needs — the
workflow, its README, and `bootstrap.sh`, which creates the repository, pushes
the workflow and starts the first sync:

```bash
sh build/binaries-mirror/bootstrap.sh
```

Run it from your own machine: the panel repository's CI token is scoped to
that repository and can neither create repositories nor push elsewhere. The
mirror must be **public** — the installer fetches release assets anonymously,
and a private repository returns 404.

## Surviving a messy host

Two failures showed up installing onto a server that had run a panel before,
both patched in `build/install`:

- **A dead apt mirror poisons everything.** A leftover third-party repository
  whose host no longer resolves leaves apt serving a stale index, so
  `mariadb-server` resolves to a version it cannot fetch. Every source host is
  now resolved with `getaddrinfo` before the package phase and unreachable
  ones are commented out, with a `.xc_vm.bak` copy kept.
- **The OpenSSL 3 shim broke apt outright.** Forcing Ubuntu 22.04's `libssl3`
  onto glibc 2.31 left dpkg with an unsatisfiable dependency, and from there
  *every* `apt install` in the run aborted. It is skipped below glibc 2.34.

Note the schema needs **MariaDB 10.6 or newer**: `panel_logs` is declared
`utf8mb4`/`utf8mb3`, and `utf8mb3` is only an alias from 10.6 on. On 10.3 that
one table fails to create while the rest imports.

## Diagnosing a live install

`install` treats several late steps as non-fatal — a failed schema migration
is "not recorded, will retry", a bundled module that fails to install is
logged and skipped — and then reports success. `build/doctor` checks what
actually landed and finishes the job:

```bash
sudo python3 doctor --check   # report only
sudo python3 doctor           # check and repair
```

It verifies the database with the panel's own credentials, lists every
migration as applied or pending, checks the module tables exist, checks the
service and that something is listening on the configured ports, and repairs
by re-running `console.php status 1` (migrations plus bundled-module sync,
both idempotent).

It also prints **every URL the panel is reachable on**. The installer derives
that URL from whichever interface routes to 8.8.8.8, which on a host running
WARP or a VPN is the tunnel address — a URL nobody can open. `doctor` lists
all interfaces with tunnels last and labelled, and `getIP()` now prefers a
non-tunnel interface too.

## Repairing a failed install

`install` assumes it can reach MariaDB root without a password. On a host that
ran a panel before, root already has one: every DB step fails with
`ERROR 1045`, and the panel is left with no database. `build/repair-database`
creates what was skipped — both databases, the schema, and the panel account
with grants for `localhost` and `127.0.0.1` — reading the credentials the
panel already expects from `config.ini`, and verifying the connection before
restarting.

Three installer bugs behind that failure are patched in `build/install`: the
result is now verified rather than assumed, the OpenSSL 3 shim is skipped on
glibc < 2.34 (forcing it broke apt for the whole run), and root's auth plugin
is queried with `-N -B` so socket auth is detected.

## Uninstalling

`build/uninstall` ships twice: in the zip next to `install`, and inside the
deploy tree at `/home/xc_vm/uninstall`, so a panel installed months ago can
still be removed without hunting down the original download.

```bash
sudo python3 uninstall --dry-run   # print the plan, change nothing
sudo python3 uninstall             # asks for confirmation
```

It reverses `install` in the order that keeps the box usable at every step:
stop and disable the unit and kill anything left owned by `xc_vm`, drop the
user's crontab, unmount the two tmpfs filesystems **and strip their
`/etc/fstab` entries** (leaving those behind is the one failure that can stop
a server booting), drop `xc_vm` and `xc_vm_migrate` along with the panel's
randomly-named DB account, remove `/home/xc_vm`, delete the account, and take
`DefaultLimitNOFILE=655350` back out of the systemd configs.

Before any of that it writes `/root/xc_vm-uninstall-<timestamp>/` with a dump
of both databases, `config/` and `credentials.txt`. `/etc/fstab` is backed up
in place too.

It finds MariaDB by trying socket auth first and falling back to the root
password in `/root/credentials.txt`, and identifies the panel's DB user by its
grants rather than guessing the 32-character random name. Re-running it on a
clean machine is a no-op.

What it deliberately does **not** touch, and reports at the end: `/etc/sysctl.conf`
(the installer overwrites it keeping no copy, so there is nothing to restore),
the MariaDB server config, and the system packages — other software may depend
on them. `--purge-credentials` and `--purge-mariadb-repo` opt into the rest.

On the target server: `unzip XC_VM.zip && sudo python3 install`. The installer
checks for a local `xc_vm.tar.gz` *before* reaching out to GitHub
(`download_xc_vm()` in `build/install`), so it deploys our tree.

## Shipping a clean install, not a clone

This repository was captured from a **running** panel, so both layers carry
live state. A release must not inherit it — the build strips it and then
fails if any of it reappears:

| Dropped | Why |
| --- | --- |
| `config.enc`, `install_id` | the panel's encrypted DB/Redis credentials and its identity with the module platform |
| `backups/*.sql`, `backup_*.sql` | full database dumps |
| `tmp/cidr/`, `tmp/cache/`, `tmp/crons/`, `tmp/*.log` | runtime caches and logs, including `tmp/cache/hmac_keys` |
| `bin/nginx/conf/codes/*.conf` | nginx vhosts minted for that panel's access codes |
| `signals.last`, `sysctl.on` | host-specific runtime markers |
| `Modules/*.backup.*/`, `Modules/*/dev/` | stale copies and the Flussonic preview harness |

Three files are **replaced** with upstream's pristine templates rather than
dropped, because a captured copy is both a leak and a functional bug:

- **`bin/redis/redis.conf`** — a live install's copy holds a real
  `requirepass`. `StatusCommand` only rotates it while it still reads
  `#PASSWORD#`, so every install would otherwise share that password.
- **`bin/nginx/conf/*`** — `nginx.conf`, `ports/*.conf`, `realip_xc_vm.conf`
  and the `server.key`/`server.crt` pair are rewritten per panel. The
  installer generates a unique certificate before nginx starts.
- **`config/modules.php`** — the installed-version registry. A captured copy
  claiming `flussonic 0.1.0` would make the panel run `updateModule()`
  instead of `installModule()`, so `database.sql` would never apply and the
  module's tables would never be created.

Those templates are vendored under `build/templates/bin/` and `config/`, and
`.gitattributes` keeps them byte-identical to upstream 2.3.9.

The build also fails on a staged Git LFS pointer, a missing core path
(including `xcvm_core.so` and the nginx/redis/ffmpeg binaries), or a missing
runtime directory.

### Verified against the bundled binaries

Both substituted configs were checked by running the runtime that ships in
`bin/`, not just by inspection:

- **nginx 1.31.2** — `nginx -t` on the staged `bin/nginx/conf/nginx.conf`
  (deploy path rewritten to a sandbox prefix, ports moved off 80/443 so it can
  bind unprivileged) reports *"configuration file … test is successful"*, with
  every include resolved and the placeholder `server.crt`/`server.key` loaded.
- **KeyDB 6.3.4** — the binary at `bin/redis/redis-server` loads the staged
  `redis.conf` and reaches *"Server initialized"*; the only messages are
  sandbox `ulimit`/`overcommit_memory` warnings.

`bin/guess` also runs. `bin/php/bin/php` and `ffmpeg` need `libssl.so.1.1` and
`libfribidi.so.0`, which the installer provides as system packages, so they
cannot be exercised here.

## Keeping up with upstream

This fork is a flattened copy of upstream's `src/`, not a git fork, so there
is no shared history to merge. What makes an upgrade cheap is that it changes
**only seven upstream files**:

| File | Why |
| --- | --- |
| `Core/Module/ModuleMigrator.php` | strip SQL comments before splitting on `;` |
| `Cli/Commands/StatusCommand.php` | silence the empty socket glob |
| `migrations/00{2,8,9}_*.sql` | `FROM DUAL`, without which MariaDB rejects them |
| `service` | keep daemon stderr instead of sending it to `/dev/null` |
| `Public/Views/admin/topbar.php` | the Flussonic navigation entry |

Everything else this fork adds lives in `Modules/flussonic_1f4a9/`, `build/`
and `.github/`, which upstream never touches.

To move to a new upstream release: check out its `src/` over this tree,
re-apply those seven patches, bump nothing (the version comes from upstream's
`AppConfig.php`), and tag. `.github/workflows/watch-upstream.yml` watches for
a new **stable** release weekly and opens an issue listing which of the seven
that release also modified — the only ones needing manual work.

Upstream's 2.5.x line is tagged but published as prereleases, so the watcher
ignores it: their latest stable is still 2.3.9. This fork ships **2.7.13** --
deliberately ahead, so a panel installed from here is offered this fork's
updates instead of sitting on a number upstream also uses.

Because upstream's 2.4.x tags exist as prereleases, the version this fork
ships and the upstream release it is built from are kept as separate facts:
`.github/upstream-base.txt` names the latter (`2.3.9`). The base-layer mirror
downloads that tag, the release notes credit it, and the watcher uses it as
the ref to compare from — asking upstream for "our" number would quietly pick
up their unreleased line.

### Never use the panel's Update button

`update` downloads from `Vateron-Media/XC_VM` and `cp -a`'s the archive over
`/home/xc_vm`, excluding only `bin/*`, `content`, `backups`, `tmp`, `config`
and `signals`. Every patch in the table above would be reverted in place. The
Flussonic module directory survives — upstream's archive does not contain it,
and `cp -a` does not delete what it does not carry — but the panel would go
back to failing its migrations and swallowing nginx's errors.

## CI

`.github/workflows/build-release.yml` runs the same script on a tag push
(`v*`) or from the Actions tab, then creates the release and uploads the three
assets with their checksums in the body.

```bash
git tag v2.3.9-flussonic.2
git push origin v2.3.9-flussonic.2
```
