# Handoff

XC_VM 2.4.1 with a Flussonic module, packaged as installable GitHub releases.
2.4.1 is this fork's own number; the source layer it is built from is
upstream 2.3.9, which is still their newest stable.
This is what a fresh session needs to carry on without relearning it.

## Where things stand

The panel **installs and runs** on the target server. Getting there took
fifteen releases, most of them fixing a real fault found on that machine.

The distribution fork has since been recreated and the source published into
it, so the working repo and the distribution repo are now the same place.

| | |
| --- | --- |
| Working repo | `TalaveraSama/fork_xc_vm`, branch `arena/01a0e51d-fork-xc-vm` |
| Distribution repo | the same — public, 123 MB, source only, no `bin/` |
| History | one commit (`62a81df`) holding the whole published tree |
| Releases | **none yet** — 0 tags, 0 releases. This is the gap. |
| Target server | Ubuntu 20.04, MariaDB 10.3, panel at `/home/xc_vm`, HTTP 2087 |
| Previous repo | `TalaveraSama/chunklist-xc_vm` — **gone**, API returns 404 |

Everything below that refers to the old working repo is history: its branch,
its PR #1 and its `v2.3.9-flussonic.19` release went with it.

## Outstanding, most urgent first

**1. Rotate the credentials that were exposed while `chunklist-xc_vm` was
public.** The repository itself is gone, which closes the exposure, but
anything served from it before deletion must be assumed read: the Redis
password, the panel's TLS keypair, MariaDB root (it was `2087`, four
characters) and any panel account in the two 7 MB dumps that were committed.
Deleting the repo does not un-publish what was already fetched.

*Verified clean here:* the tree published into `fork_xc_vm` carries none of
it. No `config.enc`, no `install_id`, no database dumps; `build/templates/bin/
redis/redis.conf` ships `requirepass #PASSWORD#`, a placeholder; `config/
rclone.conf` is an empty stub. The one keypair in the tree,
`build/templates/bin/nginx/conf/server.{crt,key}`, is upstream's stock
self-signed certificate (`C=RU, O=XC_VM, CN=XC_VM`), identical in every XC_VM
install and not the server's own.

**2. ~~Recreate `fork_xc_vm` as public and publish the branch into it.~~ Done.**
The repo exists, is public, and holds the source layer — 6148 files, 123 MB,
no `bin/`, exactly what `build/publish-source-to-fork.sh` emits.

**3. ~~Run the fork's workflows.~~ Done — the releases exist.**

    2.4.1              XC_VM 2.4.1              stable       XC_VM.zip, xc_vm.tar.gz, hashes.md5
    2.4.0              XC_VM 2.4.0              stable       XC_VM.zip, xc_vm.tar.gz, hashes.md5
    2.3.9              XC_VM 2.3.9              stable       XC_VM.zip, xc_vm.tar.gz, hashes.md5
    binaries-29062026  XC_VM binaries 29062026  prerelease   6 distro tarballs + hashes.md5
    base-2.4.1         Base deploy tree 2.4.1   prerelease   xc_vm.tar.gz, hashes.md5, upstream-tag.txt
    base-2.4.0         Base deploy tree 2.4.0   prerelease   xc_vm.tar.gz, hashes.md5, upstream-tag.txt
    base-2.3.9         Base deploy tree 2.3.9   prerelease   xc_vm.tar.gz, hashes.md5

`2.4.1` is the newest stable, so it is what the panel's stable channel returns
and what the Update button offers. All three base layers hold the same
174,180,187 byte archive — upstream's 2.3.9 — because that is the tree this source is
built from regardless of the number on the front.

Verified installable on all six distributions at 2.4.1: `complete tree after
install on: debian_11, debian_12, debian_13, ubuntu_20, ubuntu_22, ubuntu_24`,
deploy tree 6157 files, `binaries mirror: binaries-29062026`, and the four
runtime components the archive leaves to the installer. Same verdict at 2.4.0
before it.

2.4.1 was published 2026-09-28T08:58:45Z as stable: `xc_vm.tar.gz`
188,669,954 B, `XC_VM.zip` 187,803,742 B, `hashes.md5` 89 B, and it is what
`releases/latest` returns. It carries the three faults found on the live
server -- the doomed binaries retry, the 404ing data crons, and the missing
GeoLite2 database that killed playback. Cut the usual way: `seed-base-2.4.1`
(base layer, 174,180,187 B, byte-identical to base-2.4.0), then `2.4.1`, then
`verify-2.4.1`; both doorbell tags deleted afterwards, the release tags left
alone.

**The `release: [published]` trigger in `verify-release.yml` never fires for
our own releases.** GitHub does not start workflows from events raised with
the default `GITHUB_TOKEN`, so a release published by `build-release.yml`
starts nothing. Observed, not assumed: 2.4.0 was published and no verify run
appeared. The trigger is still worth keeping for a release published by hand
in the web UI; the reliable route is the doorbell tag,
`git tag verify-<v> && git push origin verify-<v>`, then delete it. Its own notes record its provenance: *"Nothing was taken from
`Vateron-Media/XC_VM` to produce this release."* All three assets resolve
anonymously (`302` to the CDN without a token), which is what an install needs.

**The redirection is live now, not just configured.** While writing this,
`resolve_binaries_source()` and `PANEL_SOURCES` were executed verbatim against
the real API. The fork is first in both lists and, before the releases existed,
was skipped for having nothing to offer. It no longer is:

    TalaveraSama/fork_xc_vm       -> binaries-29062026
    Vateron-Media/XC_VM_Binaries  -> 29062026
    ==> chosen: TalaveraSama/fork_xc_vm @ binaries-29062026

    TalaveraSama/fork_xc_vm   latest=2.3.9  XC_VM.zip present
    Vateron-Media/XC_VM       latest=2.3.9  XC_VM.zip present

Upstream stays in both lists as a fallback, so deleting the mirror degrades an
install instead of breaking it — but nothing reaches for it while the fork has
a release.

**What the archive does and does not carry.** Upstream ships `bin/` as a
skeleton and so does this release, because it is built from upstream's base
archive. `redis` and `ffmpeg` are in it; `bin/php/bin/php`, `xcvm_core.so`,
`nginx` and `nginx_rtmp` are not — the installer downloads those per
distribution, now from this repository's own `binaries-29062026` rather than
from upstream. The release notes list them by name; that list is generated
from the build, not written by hand, so it cannot drift from the artefact.

**The in-panel updater will not offer 2.3.9 to a panel already on 2.3.9.**
`getLatestVersion()` returns null unless `version_compare($latest, $current,
'<=')` is false. So that release is for fresh installs — and the button is
safe either way now, because it resolves to this fork and no longer to
upstream's unpatched tree.

**2.4.0 is the version that makes the Update button do something.** Bumping
`XC_VM_VERSION` is the whole trigger: every panel already on 2.3.9 starts
seeing an update, and it fetches this fork. Three things had to follow the
number, and all three are one-time structural fixes rather than per-release
chores:

  * `.github/release-notes.md` hardcoded `2.3.9` in two places. It now carries
    `@@VERSION@@` and `@@UPSTREAM_VERSION@@`, and `build-release.yml` fails the
    build if a marker is missing, if a substitution is empty, or if any `@@...@@`
    survives into the published notes.
  * `mirror-base.yml` asked upstream for `$VERSION`. That looked harmless
    while the two numbers matched and is actively wrong now: upstream **does**
    have a `2.4.0`, as a *prerelease* of their unreleased 2.4.x/2.5.x line, so
    the first seeded `base-2.4.0` came back holding a deploy tree this fork's
    source was never built against. The upstream release the source layer
    derives from is now stated outright in `.github/upstream-base.txt`
    (`2.3.9`), and that tag is published as `upstream-tag.txt` beside the
    archive so the panel notes credit the layer actually used.
  * `watch-upstream.yml` compared upstream's newest stable against
    `XC_VM_VERSION`. Two faults: string equality meant a weekly
    "upstream released 2.3.9 (we ship 2.4.0)" false alarm, and `$ours` was
    also used as a **git ref inside upstream's repository** for the compare
    and the per-file blob lookups — at 2.4.0 that resolves to their
    prerelease, and at a number they never published it 404s and reports
    every patched file as colliding. It reads `upstream-base.txt` for the
    ref now and compares with `sort -V`.

The rule the three share: this fork's version number and the upstream release
it is built on are separate facts, and anything that needs the second must ask
`.github/upstream-base.txt` rather than infer it from the first.

Migrations do not need a hand: `MigrationRunner` applies them by filename, not
by version, so the bump adds no schema work. The binaries mirror is keyed on
`binaries-<date>`, not on the version, so `binaries-29062026` still serves
2.4.1 — only `base-2.4.1` has to be seeded.

**Verified installable, mechanically.** `build/verify-release.py` downloads a
published release, checks both archives against their published md5, opens
`XC_VM.zip` and confirms it holds `install` plus the same tarball published
beside it, extracts the deploy tree and checks the panel entry points are
present and that no `config.enc`, `install_id` or hmac key came with it, reads
the shipped `AppConfig.php` to confirm the installed panel will point here and
not upstream, and then reassembles base + per-distribution tarball exactly the
way `install` does and asserts the result is a complete runtime. A draft or a
prerelease fails it, since neither is visible to the updater. Run against
`2.3.9`:

    complete tree after install on: debian_11, debian_12, debian_13,
                                    ubuntu_20, ubuntu_22, ubuntu_24

It writes only to a temporary directory and never touches `/home/xc_vm`, so it
is safe to run on the machine about to be installed:

    build/verify-release.py --tag 2.3.9 --distro all

CI runs it on every published release, and it can be started by pushing a
`verify-<tag>` tag.

**Those six are the whole supported list.** `install` also claims Ubuntu 18 and
Rocky/Alma/RHEL/CentOS 8-9, but no tarball is published for any of them — not
here and not upstream, so this is inherited, not a gap the fork introduced. The
RHEL branch at least fails loudly. Ubuntu 18 did not: it fell to a branch that
prints *"using default binaries"* and carries on, wording inherited from when
the archive shipped a complete `bin/`. With the skeleton it now ships, that
path ends in a panel with no php and a success banner over it. `install` now
checks the runtime is actually present after that whole section, whichever
branch ran, and stops with the supported list if it is not.

**How to cut the next release.** Three tags, in order; each is only a
doorbell, the version itself still comes from `AppConfig.php`:

    git tag seed-base-2.4.1 && git push origin seed-base-2.4.1   # base layer
    git tag seed-binaries   && git push origin seed-binaries     # distro binaries
    git tag 2.4.1           && git push origin 2.4.1             # the release

The mirrors are idempotent: they skip when the tag is already mirrored, so
re-running them costs nothing. `seed-*` deliberately does not match the
`base-<version>` and `binaries-<tag>` releases those jobs publish, so neither
can retrigger itself. `workflow_dispatch` also works from the Actions tab, but
not from the user's machine, whose `gh` is gitsome.

**Never delete the tag of a published release.** GitHub demotes the release to
a *draft* the moment its tag disappears, and a draft is invisible to the
updater in exactly the way a prerelease is. This happened here while
re-cutting `2.3.9`. To move a tag that already has a release on it:

    git tag -f 2.3.9 HEAD && git push --force origin 2.3.9

which updates the ref without it ever ceasing to exist. If one does get
demoted, the build's publish step now repairs it — it reconciles `--draft` and
`--prerelease` on every edit instead of only setting them at creation.

with `xc_vm.tar.gz` (174 MB) and `hashes.md5`, and `XC_VM_Binaries` still has
`29062026` with 7 assets. Both mirrors have something to copy.

## What comes from where

Checked end to end against the code, not assumed.

**Independent — resolves to this fork, upstream only as a fallback:**

| Piece | Where it is decided |
| --- | --- |
| Panel archive (`XC_VM.zip`) | `PANEL_SOURCES` in `build/install` |
| Distribution binaries | `BINARIES_SOURCES` in `build/install` |
| In-panel updater | `GIT_OWNER_MAIN` / `GIT_REPO_MAIN` |
| Binaries refresh from the panel | `GIT_OWNER_BIN` / `GIT_REPO_BIN` |
| GeoLite2 databases | `GIT_OWNER_UPDATE` / `GIT_REPO_UPDATE` |
| Proxy-node archive | `GIT_OWNER_PROXY` / `GIT_REPO_PROXY` |

`build/install` used to hardcode `Vateron-Media/XC_VM` for `XC_VM.zip` with no
alternative, so an install run without a local archive fetched the *unpatched*
panel — none of the fixes in this tree, and an updater pointing back upstream.
It now walks `PANEL_SOURCES`, verifies each download against the `hashes.md5`
of the same release rather than a fixed owner, and skips the `binaries-` and
`base-` mirror tags when picking the panel release.

Note the installer prefers a local `xc_vm.tar.gz` / `XC_VM.zip` before any
network call, so installing from a ZIP built here never touches GitHub at all.

**The build refuses to depend on upstream.** `build-release.yml` resolves its
runtime layer from this fork's own `base-<version>` mirror and, if that is
missing, **fails** with instructions instead of quietly reaching for
Vateron-Media. Falling back is now an explicit choice: the
`allow_upstream_base` input, `false` by default and empty on a tag push, so an
unattended release is self-contained or it is not a release at all. Whichever
happens, the chosen base is written into the release body under *Provenance* —
either "Nothing was taken from `Vateron-Media/XC_VM` to produce this release"
or a blockquote saying the opposite. All four paths were simulated.

**GeoIP and the proxy node — redirected, pending two repositories.**

| Piece | Now reads | Repo must exist |
| --- | --- | --- |
| GeoLite2 GeoIP databases | `GIT_OWNER_UPDATE` / `GIT_REPO_UPDATE` | `TalaveraSama/XC_VM_Update` |
| Proxy-node archive | `GIT_OWNER_PROXY` / `GIT_REPO_PROXY` | `TalaveraSama/XC_VM_Proxy` |

`AppConfig.php` now owns all four sources per-repo — `GIT_OWNER_MAIN`,
`GIT_OWNER_BIN`, `GIT_OWNER_UPDATE`, `GIT_OWNER_PROXY` — all pointing at this
fork. `GIT_OWNER` itself is no longer read by anything.

Mirroring these two was **not** a matter of copying `mirror.yml`. Upstream
tags them `25.09.26` and `1.0.0` as *stable*, and the panel reads a repository
through `GitHubReleases`, which filters by channel and compares tags with
`version_compare`. A stable `25.09.26` published next to the panel's `2.3.9`
in this repo would read as a newer *panel*, and the Update button would try to
install the GeoIP databases as the panel. That is why `binaries-` and `base-`
are both prefixed *and* prereleases — and why these two go to their own repos
instead, where upstream's tags and flags can be kept byte-for-byte.

`build/data-mirror/` holds the kit, same shape as `build/binaries-mirror/`:
one `mirror-data.yml` serving both repos (upstream is derived from the
mirror's own name, and it refuses to run under any other name), a README, and
`bootstrap.sh` which creates both repositories, installs the workflow and
starts the first sync. The workflow lives in the mirror repo and publishes
with that repo's own `GITHUB_TOKEN`, so there is no PAT to create or rotate.

**Order matters.** Run `sh build/data-mirror/bootstrap.sh` *before* this
branch reaches the panel, or `cron:maxmind` and `cron:proxy` will 404 against
repositories that do not exist yet. Both failures are non-fatal — GeoIP simply
stops refreshing — but it is a silent degradation. Setting either constant
back to `'Vateron-Media'` restores upstream for that one repository.

Everything else naming Vateron-Media is inert: copyright headers, a curl
User-Agent string in `GitHubReleases.php`, and the `repository` metadata of
the bundled `plex` and `watch` modules.

## How the release is built

`build/build-release.sh` stages a deploy tree and packs upstream's asset
names — `xc_vm.tar.gz`, `XC_VM.zip` (= `install` + the tarball + the three
tools), `hashes.md5`. It resolves its base layer in this order, and CI picks
automatically:

1. `bin/` committed at `HEAD` (never true here)
2. `bin/` on `origin/main` (never true here either — see below)
3. `--base-tar`: a whole deploy tree from a release archive — the fork's own
   `base-<version>` mirror, then upstream's release

Both routes were built and compared: identical, 7138 entries.

The fork keeps **no `bin/`** on purpose. It would be 677 MB in every clone,
and the captured one is where several of the bugs below came from. Note that
routes 1 and 2 are now both dead ends here — the fork has a single commit and
no `bin/` anywhere — so **route 3 is the only one**, which is why running the
base mirror before the build is not optional.

## 2.4.4 is current

Published 2026-09-29, stable, and what `releases/latest` returns:
`xc_vm.tar.gz` 188,676,551 B, `XC_VM.zip` 187,802,605 B, `hashes.md5` 89 B.
Verified installing on debian_11/12/13 and ubuntu_20/22/24, deploy tree 6157
files. Adds the Watch Folder cron schedule to everything in 2.4.3.

## 2.4.3

Published 2026-09-29, stable, and what `releases/latest` returns:
`xc_vm.tar.gz` 188,671,332 B, `XC_VM.zip` 187,804,980 B, `hashes.md5` 89 B.
Verified installing on debian_11/12/13 and ubuntu_20/22/24, deploy tree 6157
files. It adds the `console.php update` fix to everything in 2.4.2.

## 2.4.2

Published 2026-09-29, stable, and what `releases/latest` returns:
`xc_vm.tar.gz` 188,666,041 B, `XC_VM.zip` 187,802,953 B, `hashes.md5` 89 B.
Verified installing on debian_11/12/13 and ubuntu_20/22/24, deploy tree 6157
files, binaries mirror `binaries-29062026`. Base layer `base-2.4.2` is the
same 174,180,187 B upstream-2.3.9 archive as every base before it.

It exists for a reason worth remembering: the two fixes in it -- the
undefined array key warnings and the Flussonic import that never started
its channels -- had been applied to the live server by hand, and the panel's
own Update button reverted both. `update` replaces everything outside
`bin/*`, `content`, `backups`, `tmp`, `config` and `signals`, and `Modules/`
is not on that list. **A hand-patched file survives exactly until the next
update.** Hotfix by curl to see whether a fix works; cut a release to keep it.

## Faults found on the real server, and why the fixes matter

Reverting any of these puts the panel back in a state that looks installed
and is not. Each was reproduced before being fixed.

- **The OpenSSL 3 shim broke apt entirely.** `install` force-installs Ubuntu
  22.04's `libssl3`, which needs glibc 2.34; 20.04 has 2.31. dpkg took it with
  `--force-depends` and from then on *every* apt install aborted — all 34
  system packages and MariaDB with them. Skipped below glibc 2.34.
- **MariaDB's auth plugin was read without `-N -B`,** so it came back as
  `| unix_socket |` and never matched. Socket-authenticated root always took
  the wrong branch.
- **Nothing checked the database step,** so a MariaDB that rejects root left
  no schema and no account while the installer still printed success. It now
  verifies with the panel's own credentials and says INSTALLATION INCOMPLETE.
- **A dead apt mirror poisons the run.** A leftover repository whose host no
  longer resolves leaves apt serving a stale index, so `mariadb-server`
  resolves to a version it cannot fetch. Unreachable source hosts are now
  resolved with `getaddrinfo` and commented out.
- **Migrations 002, 008 and 009** were the only three using
  `INSERT ... SELECT <literals> WHERE NOT EXISTS`. MySQL and MariaDB require
  a FROM clause before WHERE; `FROM DUAL` added.
- **The Flussonic module failed with "the link to the node"** — a fragment of
  a comment in its own `database.sql`. `ModuleMigrator::runFile()` split on
  `;` and only then dropped `--` lines, so a semicolon inside a comment glued
  its tail onto the next statement. Comments are stripped before splitting now.
- **nginx would not start, silently.** The captured `bin/` ships
  `bin/nginx/logs/error.log`, and `set_permissions` chmods everything under
  `bin/nginx` to 0550 — read-only even for its owner. nginx_rtmp started fine
  because that `find` never covers it, which made it look half-working.
- **Then php-fpm, identically:** `bin/php/var/log/php-fpm.log` at 0550, so it
  never started and nginx answered 502. The first fix missed it because it
  only looked for `*/logs/*`; php's is `var/log`, singular.
- **`service` sent daemon stderr to `/dev/null`,** which is why both of those
  failed without a trace. It writes to `tmp/logs/boot.log` now.

### apt was already broken, and the installer ground on for ten minutes

Reported from a live Ubuntu 22.04 host that also runs Flussonic. The 2.4.0
release downloaded, checksummed and unpacked correctly; the install then
failed with all thirty-eight system packages reported as individually
"failed", MariaDB uninstallable, and a closing message blaming a dead apt
mirror. None of that was the cause. `dpkg` had two conflicting Flussonic
packages installed at once:

    flussonic-transcoder6.1.3 : Breaks: flussonic-transcoder but 23.02.0 is installed

While any installed package has unmet dependencies, **every** `apt-get
install` fails, including ones this installer never asked for. The state was
broken before the installer started and `apt --fix-broken install` cannot
resolve it, because apt will not choose which of two installed packages to
drop.

Three fixes, all in `build/install`:

  * `assert_package_manager_sane()` runs `apt-get check` before the MariaDB
    password prompt and before any repository is touched. If apt is broken it
    attempts one repair, and if that fails it prints the offending lines
    verbatim and stops. Failing in ten seconds with the real reason beats
    failing in ten minutes with the wrong one — and it says outright that the
    package is not at fault, because the log otherwise reads like one.
  * The conflicting-package removal was the literal string `mysql-server`,
    which on Ubuntu is not an installed package name. `mysql-server-8.0` and
    friends stayed, and those are exactly what `mariadb-server` Conflicts:
    with, so MariaDB could never install on a host that had MySQL. It now
    asks `dpkg-query` for the installed `mysql-server*` / `mysql-client*`
    names and removes those.
  * The final MariaDB diagnosis assumed one cause. It now asks apt whether
    the problem is a broken state or an unfetchable package and prints the
    advice that matches.

Note for anyone reading such a log: the long list of `Del <package>` lines is
`apt-get autoclean` deleting cached `.deb` files. Nothing is uninstalled
there, however much it looks like it.

**Confirmed fixed on that host.** With the gate in place the run stopped in
about ten seconds, before the password prompt and before any repository was
touched, naming both Flussonic packages. After
`apt-get remove flussonic-transcoder flussonic-transcoder-base` and
`apt-get install curl libcurl4` (together — 1.24 and 1.29 block each other if
taken one at a time), `apt-get check` came back clean and **2.4.0 installed
successfully** on Ubuntu 22.04. So the six-distribution CI verdict now has a
live install behind it as well.

One cosmetic wart left: `printc` frames each message in a fixed 62-column box
and the new diagnostics are longer than that, so they overflow their frames.
Readable, but untidy. Shortening those strings means republishing the archive,
which is why it was not done mid-install.

### The binaries updater could never succeed, and retried every minute

The first live panel logged `BINARIES  Updating XC_VM binaries from XC_VM
server...` once a minute, for hours, and a weekly `EXCEPTION  Resource not
found (404)` out of `MaxMindCronJob`. Two separate faults, both created by
pointing the fork's constants at repositories that are not shaped the way the
code assumed.

**The loop.** `BinariesCommand` took `getReleases()[0]` as "the latest
binaries release". In upstream that is right: `XC_VM_Binaries` is a
single-purpose repository whose newest release *is* the runtime. This fork
publishes both kinds of release into one repository — the panel under `2.4.0`,
the runtime under the `binaries-29062026` **prerelease** — so `getReleases()[0]`
on the stable channel returns `2.4.0`, whose assets are `xc_vm.tar.gz`,
`XC_VM.zip` and `hashes.md5`. No `debian_12.tar.gz` to fetch. So:
`bin_version.json` says `binaries-29062026`, the updater says the latest is
`2.4.0`, they differ, `update_binaries.sh` runs, finds no asset, fails, and
leaves `bin_version.json` untouched — which guarantees the next run repeats it.
Selecting the unstable channel does not help: `2.4.0` is still first.

`build/install` already got this right —
`get_latest_binaries_tag()` prefers a `binaries-` tag and only falls back to the
newest stable release for upstream's single-purpose repo. The panel now shares
that rule through `GitHubReleases::getBinariesTag()`. Verified against the live
API payload: before, `2.4.0` on both channels (update attempted); after,
`binaries-29062026` (skipped, because it equals what is installed). An owner
with no `binaries-` tag still resolves to its newest stable release, so upstream
keeps working as a fallback.

Worth knowing: the once-a-minute *cadence* is not the updater's. The signal is
created by `console.php status`, which `StartupCommand` runs on every service
start, and consumed at most once per minute by `cron:root_signals`. A panel that
logs this line every minute is starting the `xc_vm` unit every minute — the unit
is `Type=simple` with `Restart=always` and `RestartSec=1`, and
`ExecStart=/bin/bash /home/xc_vm/service start` returns once the daemons are up.
`systemctl show xc_vm -p NRestarts` settles it. The fix here stops the futile
download; it does not stop a restart loop.

**The 404.** `GIT_OWNER_UPDATE` and `GIT_OWNER_PROXY` were moved to
`TalaveraSama` ahead of the mirrors existing, so `MaxMindCronJob` and
`ProxyArchiveCronJob` asked GitHub for a repository that is not there. 404
raises out of `getReleases()`, and an uncaught throw from a cron job lands in
the panel log as an EXCEPTION — for what is only a data refresh. Both now go
through `GitHubReleases::locate()`, which walks a list of owners and returns the
first that actually serves releases: this fork first, upstream as a safety net,
the same shape as `BINARIES_SOURCES` and `PANEL_SOURCES` in `build/install`.
When no owner answers, the job prints `[ERROR]` and returns 1 instead of
throwing. Creating `TalaveraSama/XC_VM_Update` and `TalaveraSama/XC_VM_Proxy`
therefore becomes a silent upgrade rather than a prerequisite.

That change exposed a latent bug that had to be fixed with it: the releases
cache was keyed `gitapi_<repo>_<channel>`, with no owner. Two owners of a
repository called `XC_VM_Update` shared one cache file, so `locate()` could hand
back one owner's tag list while the download URLs were built from another's.
The key now includes the owner.

### Lines downloaded their playlist and played nothing

Reported from the field right after the 404 above: the client authenticates,
pulls its M3U, and no channel plays. The panel's own preview player works, so
the streams themselves are fine.

One call explains it. `Public/stream/auth.php` resolves the client country on
**every** playback request:

    $rCountryCode = GeoIPService::getIPInfo($rIP);

`getIPInfo()` did `new \MaxMind\Db\Reader(GEOLITE2_BIN)` with no `is_file()`
guard and no `try`. When `bin/maxmind/GeoLite2-Country.mmdb` is absent the
constructor throws, `auth.php` runs with `display_errors` off, and the client
gets nothing. The playlist endpoints -- `Public/index.php`,
`PlayerApiController`, `StreamingBootstrap` -- never look up an IP, which is
exactly why the list keeps working while playback is dead. A confusing symptom
with a one-line cause.

And the database is missing for a reason that is not the operator's fault.
`build/install` downloads it post-install by calling `cron:maxmind --force`,
deliberately non-fatally ("run_command never raises, so a download failure
won't abort"). That cron was the one 404ing on the missing `XC_VM_Update`
mirror. So the install completed, reported success, served playlists, and
could not play a single channel.

Two changes. `GeoIPService::read()` now checks the file exists, catches
`\Throwable`, logs once per path and returns false -- GeoIP is enrichment, and
an absent database should cost a country code, not the whole service. It also
covers `getISP()`, which reads `GeoIP2-ISP.mmdb`: a **paid** database most
installs never have, reached on the same unguarded path whenever `show_isps`
is on. And the installer now checks for the file after the download and says
so loudly if it is not there, instead of leaving a server that looks installed
and plays nothing.

Worth keeping in mind: with no country resolvable, a line whose `forced_country`
is set to something other than `ALL` is still denied -- correctly, since the
claim cannot be verified. Those lines need the database, not just the fix.

### Opening a page with no query string logged a warning

Reported from the live panel:

    [Main Server] WARNING  Undefined array key "period"
    #0 Public/Controllers/Admin/StreamRankController.php(26): handleError()

`StreamRankController` read its filter as:

    $rPeriod = (RequestManager::getAll()['period'] ?: 'all');

The `?:` looks like it handles the absent key and does not. PHP evaluates the
left operand first, so reading a key that is not there warns, *then* the
fallback applies. Reach Stream Rank from the menu -- no `?period=` -- and the
warning fires on every load. Only `??` suppresses it, and `RequestManager`
already offers the idiomatic form, `get('period', 'all')`.

The query itself was never wrong. `StatsCronJob` writes exactly
`today|week|month|all` into `streams_stats.type` and the view's `<select>`
offers those same four values, so `all` was always the right default. Cosmetic
noise, not a broken page -- but it recurs on every page load and buries real
warnings in the log.

`EpgViewController` had the same bug twice, on `page` and `entries`, and is
reached from the menu the same way. Both now use `get()` with a default that
reproduces the old arithmetic exactly: `intval(null)` was 0, so `max(0, 1)`
still gives page 1 and `max(0, default_entries)` still gives the configured
page size. Stream Rank additionally whitelists the period against those four
values, so a hand-typed `?period=junk` falls back to `all` instead of querying
for a type that cannot exist and rendering an empty table with nothing
selected.

**This pattern is everywhere upstream and was deliberately not swept.** A scan
found ~1500 unguarded `getAll()['key']` reads, 261 in the admin controllers
alone. Nearly all are DataTables AJAX endpoints -- `draw`, `start`, `length`,
`search`, `filter` -- where the browser always sends the key, so they cannot
warn in practice. Fixing all of them would add hundreds of files to
`.github/patched-upstream-files.txt`, and every entry there is a file an
upstream upgrade has to re-apply by hand. The three fixed here are the ones
reachable by plain navigation. If another surfaces in the log, the fix is one
line and the file gets added to the manifest.

### Imported Flussonic channels were created but never started

Reported from the live panel: import a stream from a Flussonic origin and
it appears in the list but does not run, until the operator opens it and
presses Restart.

`FlussonicSyncService::importStreams()` ended with:

    StreamProcess::updateStreams($rResult['stream_ids']);

which reads as "tell the servers about these streams" and is not that.
`updateStreams()` inserts an `update_streams` **cache signal** -- it
refreshes the configuration of streams that are *already running*, and its
first statement is `if (!SettingsManager::getAll()['enable_cache']) return;`,
so with the cache off it does nothing whatsoever. Starting a stream is a
different call entirely, the one behind the panel's own Start button:

    ApiClient::request(['action' => 'stream', 'sub' => 'start',
                       'stream_ids' => [...], 'servers' => [$rServerID]]);

`Public/admin/api.php` fans that out to each listed server's internal API as
`function=start`, where `InternalApiController` calls
`StreamProcess::startMonitor()`. The import path never made it. Upstream's
own create-a-stream flow does make it -- `StreamService.php:421` and
`ChannelService.php:205`, both gated on the form's *restart on edit* box --
so the module was simply missing the second half of the sequence.

Three details worth keeping:

- **Group by server.** Omitting `servers` is not harmless: the handler
  falls back to `array_keys($rAllServers)` and broadcasts the start to
  every server in the install, including ones with no `streams_servers`
  row for that channel. The servers are read back from `streams_servers`
  rather than from the import's target list, which also covers a
  `target_server_id` still naming a deleted server -- `Public/admin/api.php`
  indexes `$rAllServers` by that id without checking it exists.
- **Skip `direct_source`.** Those channels hand the origin URL straight to
  the client; there is no ffmpeg to start, and `StreamProcess::startStream()`
  filters on `direct_source = 0` so the call would match nothing.
- **Raise the timeout.** `ApiClient::request()` defaults to 5 s and the
  receiving end sleeps 50 ms per stream, so a bulk import would be cut off
  part way through the batch. It now scales with the batch, capped at 120 s.

**Refresh source URLs** had the same shape and is fixed with it: it rewrote
`stream_source` and pushed a cache update, but a running process holds the
URL it was started with, so changing the protocol or token appeared to do
nothing until each channel was restarted by hand.

### `console.php update` did nothing, silently, and exited 0

Found while telling the operator how to update from the shell. The command
printed not one line and returned success; the panel stayed on the old
version. `execute()` opened with:

    if (empty($rArgs[0])) {
        return 0;
    }

and `CommandRegistry::dispatch()` does `array_slice($rArgv, 2)` -- the
command name is already consumed, so `console.php update` arrives with an
empty array and takes that return. The sub-command has to be repeated:
`RootSignalsCronJob.php:551` (which is what the panel's Update button ends
up running) issues `console.php update update`, and the python updater
issues `console.php update post-update`.

So the button always worked and only the hand-typed form was dead -- the
worst possible split, because the shell is where you go when the button
looks stuck, and it answers with silence and exit 0, which reads exactly
like "already up to date".

`$rArgs[0] ?? 'update'` now defaults the bare form to the main action, and
an unrecognised sub-command reports itself on stderr and returns 1 instead
of falling off the end of the switch into `return 0`.

### Watch Folder never scanned, because nothing ever scheduled it

Reported from the live panel: the Watch Folder feature does not work.

`WatchModule` defines thirteen public methods. `getCronEntries()` is not
one of them, so it inherited `BaseModule`'s default, which returns an empty
array. `ModuleLoader::collectCronEntries()` skips it, and `StartupCommand`
and `StatusCommand` write a crontab with no watch line in it. The folders
sat there and were never scanned.

What hid it is that `registerCommands()` **is** implemented, so `cron:watch`
existed and ran correctly by hand -- the module looked installed and
healthy from every angle except the one that mattered. The control that
settles it is the Flussonic module, which works and does declare
`getCronEntries(): ['*/5 * * * *' => 'cron:flussonic']`.

Fixed with `['* * * * *' => 'cron:watch']`, which is the interval
`CronProviderInterface` documents for this exact job in its own docblock.
Every minute is safe: `WatchCronJob` takes a PID lock in
`CACHE_TMP_PATH/watch_pid` and exits at once when a previous scan is still
running, and `watch_folders` has no per-folder interval column, so the
crontab line is the only schedule that exists.

A second, latent fault went with it. `WatchService.php` imported
`XcVm\Module\Tmdb\TmdbApiService`, which does not exist; the correct class
is `XcVm\Infrastructure\Tmdb\TmdbApiService`, and `WatchItemCommand.php`
in the same module imports exactly that. `WatchService::updateCategories()`
calls it and would fatal with a class-not-found -- but nothing calls
`updateCategories()` anywhere in the tree, so it never fired. Corrected
rather than left as a tripwire.

#### This module is written for a core this fork does not have

`module.json` declares `requires_core >= 2.5.0`; the fork is built on
upstream 2.3.9. Nothing enforces that field -- it is only displayed on the
Modules page -- but it is accurate, and it shows. Six classes the module
imports do not exist here: `TopbarRegistry`, `TableRegistry`,
`PermissionRegistry`, `QuickToolsRegistry`, `VodImportedEvent` and the Tmdb
service above.

Those cost nothing, and it is worth knowing why before someone 'fixes' them.
PHP resolves a type hint only when the method is called, and core 2.3.9
never calls `registerTopbar`/`registerTables`/`registerPermissions`/
`registerQuickTools` -- it has no such registries. It does not need them:
everything those methods would register is still hard-coded in core, which
is what 2.5.0 refactored away. `Public/Views/admin/topbar.php` carries the
watch buttons, `TableController.php:171` has `case "watch_output"`, the
`folder_watch*` permissions are in core, and `clear_watch_logs` is in
`post.php` and `quick_tools.php`. Likewise `onVodImported` never fires
because nothing dispatches `VodImportedEvent`, and nothing needs to:
`MovieService.php:426` updates `watch_logs` directly. The four methods and
the listener are dead code on this core, not breakage.

**The module is not in `.github/patched-upstream-files.txt` on purpose.**
That list is compared against the upstream XC_VM tree with an `src/`
prefix, and this module does not live there -- it ships from
`Vateron-Media/Module_Watchfolder` and carries its own `update` block
pointing at it. Listing it would only produce false positives. The real
exposure is different and worth remembering: pressing **Update** on Watch
Folder in the Modules page pulls from that repository and reverts this fix,
reinstalling a module built for a 2.5.0 core. The weekly `module_updates`
cron is safe -- it only checks availability and never downloads or applies.

## Faults found in the fork's own CI

Same rule: do not revert these. Every one was verified, not reasoned about.
The last five were found by running the release end to end; each had stopped
it dead, and none was visible from reading the files.

- **`mirror.yml` and `mirror-base.yml` were not valid YAML, and had already
  failed.** In each, the continuation lines of the `--notes "..."` string sat
  at column 0 inside a `run: |` block whose indentation is 10, which ends the
  block scalar and leaves prose to be parsed as YAML. GitHub agreed twice
  over: `gh workflow list` showed those two by *file path* instead of by
  `name:`, and runs `36356985383` / `36356984766` both failed in 0s with
  "This run likely failed because of a workflow file issue". Since these are
  steps 1 and 2 of the release procedure, nothing downstream could ever have
  run. Fixed by indenting the continuation lines to the block level; the
  extracted shell is byte-identical to what was intended.
- **The release lint checked nothing and passed.** It diffed against a
  hardcoded base commit, `5fa9738…`, which belongs to the deleted working
  repo. Here `git diff` aborts with "Invalid symmetric difference
  expression", writes to stderr, and the loop reads no filenames — so the
  step went green having linted zero files. That step is the only thing
  standing between a PHP syntax error and someone's server. It now reads the
  manifest below, prints the count, and fails outright on an empty file set
  or a manifest entry missing from the tree. All three failure paths were
  exercised; the happy path lints 32 files.
- **Two hardcoded lists of the same thing, disagreeing.** The linter's list
  and the watcher's seven paths have been replaced by
  `.github/patched-upstream-files.txt`, read by both.
- **The upstream watcher under-reported collisions.** It grepped the compare
  endpoint's file list, which is capped at 300 entries and does not paginate,
  and every omitted file reads as "applies cleanly". It now asks for each
  patched file's blob SHA at both tags, which is exact for the cost of 15 API
  calls, and marks the headline count `300+ (list truncated)` when the cap is
  hit.
- **The mirror jobs had no repository to act on.** `gh` infers that from the
  git checkout it runs in, and `mirror.yml` deliberately never checks the tree
  out — it only moves release assets. So every `gh release` call in its publish
  step, none of which passed `--repo`, died on "failed to run git: fatal: not a
  git repository" *after* downloading and verifying 456 MB. `mirror-base.yml`
  survived only because it checks out for an unrelated reason. Fixed with
  `GH_REPO` at workflow level, which an explicit `--repo` still overrides, so
  the reads from upstream are unaffected. `build/data-mirror/mirror-data.yml`
  had the identical latent fault and was fixed with it.
- **`build/binaries-mirror/` was not valid YAML either** — the same column-0
  continuation lines as the two active workflows, in the copy `bootstrap.sh`
  installs into a mirror repository. It would have sat there doing nothing.
- **Every script in `build/` had lost its executable bit.** The release build
  failed with exit code 126 — found but not executable — because the workflow
  runs `build/build-release.sh` directly, as `build/README.md` documents. The
  same was true of `install`, `uninstall`, `doctor`, `repair-database` and the
  helper scripts; `install` matters beyond CI, since it ships inside
  `XC_VM.zip` and is what someone runs to install the panel. All are `100755`
  now.
- **`build-release.sh` demanded a complete runtime from a base archive.** Both
  of its completeness checks were written for the original model, where `bin/`
  was captured off a live panel. Upstream's base archive carries a skeleton, so
  the check rejected every base archive in existence — including upstream's own
  for the version being built. The requirement now depends on where the layer
  came from; a captured `bin/` is still held to the full list.
- **The release notes described an archive that was not being built.** They
  promised the full runtime was bundled and that "the install still completes
  if `XC_VM_Binaries` is unreachable", which a base-archive build makes false.
  The paragraph is now generated from an inventory the build writes, so it
  cannot drift from the artefact. The template keeps a `@@RUNTIME@@` marker and
  the substitution asserts it is there, so removing it fails the build rather
  than publishing notes with a hole.

## This fork modifies twenty-two upstream files

Established by byte comparison against a clone of upstream 2.3.9, with the two
corrections a naive diff needs:

- **Line endings are not changes.** `* text=auto` rewrites CRLF on checkout,
  which makes roughly 200 untouched files — most of `ministra/`, most of
  `Public/assets/` — look modified.
- **Git LFS pointers are not changes.** `Public/assets/admin/videos/
  login-bg.mp4` is LFS upstream, so a clone without LFS yields a 132-byte
  pointer. Our copy's sha256 matches the pointer's oid exactly: not patched.

The list lives in `.github/patched-upstream-files.txt` and is the single
source of truth for both workflows. It is **22 files**, and it grew one fix at a
time: 18 once the GeoIP and proxy sources were redirected, plus
`Core/Updates/GitHubReleases.php` when the binaries updater had to learn that
this fork keeps the runtime in a `binaries-` prerelease, plus
`Core/GeoIP/GeoIPService.php` when a missing GeoLite2 database turned out to
kill playback outright, plus `EpgViewController.php` and
`StreamRankController.php` for the undefined-key warnings. Re-measure with the
byte comparison above rather than trusting this number.

The entry the original handoff missed is `resources/langs/en.ini`. It is **not
a deliberate patch**: the panel appends missing language keys at runtime with
the key as its own value, and that edit came across when the source was
captured from the running server. The two appended keys are
`mass_edit_mags` and `mass_edit_enigmas` — the navbar labels
`CoreNavbarProvider.php` asks for, which upstream's `en.ini` genuinely lacks
(it defines only the `permission_`-prefixed variants). Harmless, but it is
drift, and it is now tracked rather than invisible.

Of the other twenty-one, fifteen carry the fixes above and six only redirect
`GIT_OWNER_MAIN` / `GIT_OWNER_BIN` and the repo names at this fork. That split
was re-checked per file and holds.

## Upstream

Their latest **stable** release is still 2.3.9 — the version here. 2.4.0
through 2.5.3 are tagged but all prerelease, so there is nothing to move to.
`.github/workflows/watch-upstream.yml` checks weekly and opens an issue when
that changes.

**The cost of that move is much higher than previously recorded.** The old
figure — "of the original seven, only `StatusCommand.php` collides" — came
from the truncated compare list. Measured properly, blob by blob, against
2.5.3: **12 of the 15 patched files collide.** Only the three SQL migrations
still apply cleanly. Budget accordingly.

**Never use the panel's Update button while it points upstream.** `update`
`cp -a`s the archive over `/home/xc_vm`, excluding only `bin/*`, `content`,
`backups`, `tmp`, `config` and `signals` — it would revert every patch above.
`GIT_OWNER_MAIN`, `GIT_OWNER_BIN`, `GIT_REPO_MAIN` and `GIT_REPO_BIN` in
`Core/Config/AppConfig.php` already point at `TalaveraSama/fork_xc_vm` (kept
separate from `GIT_OWNER`, which still serves `XC_VM_Update` and
`XC_VM_Proxy` — GeoIP and the proxy node are data this fork adds nothing to),
so once this repo has a release tagged `2.3.9`, the button becomes safe. The
tag must look like a version: the updater compares with PHP's
`version_compare`, and `v2.3.9-flussonic.19` sorts *below* `2.3.9`.

## Environment, so it is not rediscovered

**This sandbox.** No native `php` — `npm i @php-wasm/cli` in `/tmp` gives a
working `php -l`, but note it exits 0 even on a parse error, so read its
output rather than its status. No `docker`, no MariaDB, no root, no
`shellcheck`, no `actionlint`, no `pyyaml` (`pip` is PEP-668 locked; `npm i
yaml` and node parse workflows fine). `release-assets.githubusercontent.com`
is **blocked**, so release assets cannot be downloaded or uploaded here —
which is why the build runs in CI. `git clone` from github.com and
`api.github.com` do work, and cloning upstream is how the patched-file list
was verified.

**The token** can read this repository and push to its own branch. It
**cannot dispatch workflows** (403), create repositories, or change
visibility. Anything in the Actions tab is the user's to click.

**The user's machine.** Ubuntu 20.04: git 2.25 (no `git init -b`), `/bin/sh`
is dash (no `set -o pipefail`), and `gh` is **gitsome**, an unrelated tool
that crashes on a pyOpenSSL mismatch — never assume the GitHub CLI.

## Verification habits that caught real bugs

- `ast.parse` accepts symbol-table errors. A `global` declared after the name
  was assigned shipped a `SyntaxError` to the user. Use `compile()`; the build
  and CI now do.
- Check the artifact, not the command's exit code. An unquoted `find` pattern
  in a variable expanded against the repository and silently stopped matching;
  only comparing the tarball before and after showed it. The dead lint SHA and
  the truncated compare list are the same bug wearing different clothes — all
  three passed cleanly while doing nothing.
- Test both directions of a gate. After changing the private-key scanner, a
  freshly generated key was dropped in to confirm it still fired. The new lint
  step was likewise run against a deliberately broken PHP file, a stale
  manifest entry and an empty manifest.
- Parse the YAML, do not read it. Two workflows looked fine and were not.

## The module

`Modules/flussonic_1f4a9/` — register Flussonic origins, browse their streams,
import the chosen ones as real panel channels in `streams`. Protocol is chosen
per server in the UI (HLS, LL-HLS, MPEG-TS, DASH, RTMP, RTSP). Adds
`flussonic_servers` and `flussonic_streams`, plus a `*/5 * * * *` sync job.
`modules_archives/flussonic_1.0.0.zip` installs it standalone on an existing
panel. `Modules/flussonic_1f4a9/dev/` is an offline preview harness, excluded
from releases but still linted.

## DVB module (TBS6909X) — added in 1.0.0, import still open

`Modules/dvb_9a2c7/` replaces "put Astra or TVHeadend in front of the card and
import its output" with a panel-native path: define a transponder, scan it, see
the services. The operator asked for exactly this and explicitly rejected both
Astra (commercial, subscription) and TVHeadend.

**Architecture, and why.** The panel is on a VPS and cannot hold a PCIe card, so
the card sits on a separate machine registered as an ordinary XC_VM streaming
server. Those nodes already point their MySQL at the main server
(`Cli/Commands/LbInstallFlow.php:155`), so the database is a working,
authenticated transport — the panel INSERTs into `dvb_jobs`, `cron:dvb` on the
tuner node claims rows matching its own `SERVER_ID`, drives the hardware and
writes back.

The obvious alternative was checked and ruled out: `InternalApiController` is a
fixed `switch` whose `default:` returns `{"result":false}`, with **no module
hook**. `Router::dispatchApi()` is the panel's admin API, not the node's. A
module cannot add an internal-API action today.

**Hardware facts confirmed before building.** TBS ships an open-source driver
(`tbsdtv/media_build` + `tbsdtv/linux_media`, kernels 4.19-6.12), the card
presents plain `/dev/dvb/adapterN/frontendM`, and TBS itself documents DVBlast
against the 6909X. Scanning uses `dvbv5-scan` (v4l-utils); DVBlast is the
intended engine for the streaming half.

**`getCronEntries()` is present and must stay.** `['* * * * *' => 'cron:dvb']`.
A module that omits it inherits `BaseModule`'s empty array,
`ModuleLoader::collectCronEntries()` skips it, and the job silently never runs
even though the command exists and works by hand — the exact bug that kept
Watch Folder idle until 2.4.4. The queue's only consumer is this cron, so the
interval is also the worst-case "press Scan → tuner moves" latency.

**Not finished:** importing selected services as panel streams.
`DvbController::apiImport()` returns an explicit "not yet" instead of failing
silently. Nothing in core needs changing for playback —
`Domain/Stream/StreamProcess.php` passes `-i {STREAM_SOURCE}` straight to
ffmpeg, so `udp://@...` already works.

**Verification gotcha found while building this.** `build/php-structure-check.py`
does not separate HTML from PHP when tracking quotes: a lone apostrophe in plain
HTML text (`the card's inputs`) is reported as `unterminated ' string`, and an
even number of them can mask a real error. Keep apostrophes out of HTML text in
views, or the tool stops being a usable signal.

### 2.4.5 — DVB import and DVBlast streaming

The scanner from the previous entry now has the other half. Importing a service
allocates a UDP port on the tuner node, creates a `streams` row sourced from
`udp://127.0.0.1:<port>`, pins it to that node in `streams_servers`, and queues
a `restream` job. One DVBlast per transponder tunes the carrier once and fans
every imported service out to its own port.

Loopback is the default output on purpose: the channel runs on the machine that
holds the card, so there is no multicast to route and nothing on the wire. The
transponder's `output_host` accepts a 239.x.y.z group for the rare case where a
second machine needs the same services.

A supervisor runs on every `cron:dvb` tick and restarts whatever should be
streaming and is not. That, not the job queue, is what brings channels back
after the tuner node reboots.

**Failure modes closed deliberately, each of which is silent if left open:**

- A scan will not claim a tuner that is feeding a live transponder. Without the
  guard the scan wins, every channel on that carrier drops, and the operator
  sees only "device busy" on the scan with no hint of what they broke.
- `stopstream` works from the job's `ref_id` alone, so the stop queued by
  `delete()` still reaps DVBlast after the transponder row is gone. Treating the
  missing row as an error would strand a process holding a tuner for ever.
- `housekeep()` releases `dvb_adapters.in_use_by` whose owner is neither
  scanning nor running — a stale claim is indistinguishable from a live one at
  the point of use, so a crashed run would otherwise cost a tuner until reboot.
- Multistream (ISI/PLS) carriers and non-universal LNBs are refused with a
  reason. DVBlast can express neither; tuning them anyway produces a confident
  lock on the wrong frequency.
- DiSEqC is converted per tool: dvbv5 counts satellites from 0, DVBlast from 1.
  The stored value is what an operator says (1-4). Getting this wrong gives a
  healthy lock on the wrong satellite.
- Process signals are numeric (15/9) via `ProcessManager::kill()`. `SIGTERM` and
  `SIGKILL` come from pcntl, not posix, so naming them would make killing a
  tuner process fatal on a build without that extension.

Ports are allocated monotonically per node from 10000 and never reused: a dying
DVBlast still writing to a reclaimed port would briefly show the old channel on
a new one.

**Build gate that caught 2.4.5 on the first attempt.** `build-release.sh`
rejects any `--` SQL comment containing a semicolon, because a naive statement
splitter cuts there and glues the comment's tail onto the next statement. Two
prose comments in the dvb schema tripped it ("...`discover` job; rows are..."
and "...imports it; NULL means..."). Reword rather than suppress: the gate is
right, and it is the same fault that once stopped the flussonic schema from
applying. Check before tagging with:

    find Modules migrations -name '*.sql' -exec \
      awk '/^[[:space:]]*--/ && /;/ {print FILENAME":"FNR}' {} +

**2.4.5 is current.** Stable, `releases/latest`, published 2026-09-29T03:44:23Z.
`xc_vm.tar.gz` 188,711,281 B, `XC_VM.zip` 187,842,770 B, `hashes.md5` 89 B.
Verified across the six distros, `deploy tree: 6174 files`. Carries the dvb
module (1.1.0) with scanning and DVBlast streaming. The VPS runs 2.4.2, so the
updater offers it 2.4.5 directly.

### 2.4.6 — NEWCAMD/CS378X descrambling (dvb module 1.2.0)

Encrypted services can now be descrambled in place. `tsdecrypt` sits between
DVBlast and ffmpeg, pulls the ECMs out of the transport stream, and asks a card
server for the control words over NEWCAMD or CS378X (camd35 over TCP):

    DVBlast --udp:enc_port--> tsdecrypt --udp:output_port--> ffmpeg
                                  \-- NEWCAMD/CS378X --> card server

**The two-port design is the load-bearing decision.** `output_port` is always
what the panel channel reads. Decryption inserts a stage *behind* that address
instead of replacing it, so assigning or removing a card server never rewrites
`streams`.`stream_source`. The one-port alternative — channel points at DVBlast
when clear, at tsdecrypt when encrypted — means every toggle has to rewrite
every affected channel, and the ones that get missed play silence with nothing
to explain it.

Things that are not obvious and cost time if rediscovered:

- **DVBlast strips CA tables by default.** `-Y` (ECM) and `-W` (EMM) are added
  to the command as soon as any service on that carrier is decrypted. Without
  them tsdecrypt starts cleanly, logs a successful CAMD login, and then never
  receives a single ECM — which looks like a card entitlement problem and is
  not. This is also why enabling decryption restarts the transponder.
- **NEWCAMD needs a 28-hex-character DES key** and the server rejects a wrong
  key exactly the way it rejects a wrong password. Validated in
  `DvbCamdService::save()` so the operator finds out at the form, not from a
  log on another machine. CS378X has no such key, and the field hides itself.
- **tsdecrypt daemonises itself** with `-d <pidfile>` and writes its own pid
  file, so unlike DVBlast there is no `setsid ... & echo $!`.
- **`-s host:port` needs the port spelled out.** The default is 2233, which is
  the CS378X port; NEWCAMD lines are usually elsewhere.
- **One process per encrypted service**, because descrambling is per service
  while tuning is per transponder. A card line with a session cap will notice.
- Stopping a transponder stops its decryptors first, in the same pass. Waiting
  for the supervisor would leave them up to a minute on a dead input, each
  holding a card-server session.
- Decryptors whose service row was deleted are unreachable by query, so the
  supervisor sweeps `cw*.pid` in the scratch directory and reaps the orphans.

`tsdecrypt` is not packaged by most distributions and must be built on the
tuner node (`github.com/gfto/tsdecrypt`). The module reports its absence as a
message on the service rather than failing silently.

**Schema.** New table `dvb_camd`, and four columns on `dvb_services`
(`camd_id`, `enc_port`, `decrypt_status`, `decrypt_message`). Because 2.4.5 is
already published, this ships as `Modules/dvb_9a2c7/migrations/1.2.0.sql` as
well as in the master `database.sql` — editing only the `CREATE TABLE` would
leave every already-installed panel without the columns. Master and delta were
diffed column by column; they agree. Also added the `database_drop.sql` the
module had been missing since 1.0.0, so uninstalling no longer strands five
tables.

### 2.4.7 — tsdecrypt vendored, plus a tuner-node installer

`Modules/dvb_9a2c7/install-tuner-node.sh` now does the whole node setup in one
idempotent pass: dvb-tools and dvblast, building tsdecrypt, the `video` group,
and `console.php startup` so `cron:dvb` actually lands in the crontab. Written
for dash, not bash, because someone will run it as `sh script`.

**The upstream tsdecrypt build instructions do not work reliably, and fail in
a way that hides the cause.** `.gitmodules` points libfuncs and libtsfuncs at
`georgi.unixsol.org`, the author's own box, not GitHub. When it is unreachable
`git clone && git submodule update --init` still *succeeds*, leaving two empty
directories; the build then dies on a missing header far from the real fault.
Both libraries are mirrored on GitHub, so all three trees are vendored under
`Modules/dvb_9a2c7/vendor/tsdecrypt/` with the commits pinned in
`vendor/PROVENANCE.txt`. A tuner node now builds from what the release already
put on disk, with no network at all. 128 files, 1.1 MB.

Two corrections to what 2.4.6 documented:

- **libdvbcsa is not a dependency.** tsdecrypt's README says libdvbcsa is the
  default library, but current master has `all: ffdecsa` — FFdecsa ships in
  the tree and is the default target. OpenSSL is the only external dependency.
  The 2.4.6 README told people to install `libdvbcsa-dev`, which was harmless
  but wrong.
- **Source, not a binary, on purpose.** `FFdecsa_init` benchmarks the CPU at
  build time to choose the descrambling variant, so the output is specific to
  the machine that compiled it. Shipping a binary would be slower everywhere
  or an illegal instruction on older hardware.

Verified by building the vendored tree in the sandbox: libfuncs and libtsfuncs
compile through to `libtsfuncs.a`, and the build stops only at
`openssl/aes.h`, which is absent there. Executable bits on
`install-tuner-node.sh` and `FFdecsa_init` are 100755 in the index, and the
vendored `.gitignore` files exclude only build artefacts (128 files on disk,
128 in the index).

### 2.4.8 — proxy archive downloaded from the wrong mirror

Found in a real install log on Ubuntu 20.04. The panel listed the release from
one owner and then downloaded the asset from another:

    Mirror TalaveraSama/XC_VM_Proxy unusable: Resource not found (404)
    Fetching releases for Vateron-Media/XC_VM_Proxy ... Retrieved 1 releases
    Retrieved MD5 hash for proxy.tar.gz in version 1.0.0
    [ERROR] proxy.tar.gz: download failed ... 404 for
            https://github.com/TalaveraSama/XC_VM_Proxy/releases/download/1.0.0/proxy.tar.gz

`GitHubReleases::locate()` walks a list of owners and returns an instance bound
to whichever one answered. Every lookup in `ProxyArchiveUpdater` followed that
instance — including `getAssetHash()`, which is why a valid md5 was reported —
except the download URL on line 138, which hardcoded `GIT_OWNER_PROXY`. So on
any panel where the fork mirror does not exist, the proxy archive could never
be fetched, and the error blamed a repository that had already been reported
unusable two lines earlier.

The constructor of `GitHubReleases` already warns about exactly this hazard
("a cache shared between them would serve one owner's tag list while the
download URLs are built from another") — the cache was keyed by owner to avoid
it, and then the URL builder reintroduced it.

Fix: `getOwner()` and `getRepoName()` accessors on `GitHubReleases`, and
`ProxyArchiveUpdater` asks the resolved instance. Verified both directions --
fork mirror present keeps the old URL, fork mirror absent now resolves to
upstream. Confirmed live: the upstream URL answers 302 and the one from the log
answers 404.

`MaxMindCronJob` is the only other `locate()` caller and was never affected: it
delegates to `$repo->getGeolite()`, which builds its URLs from `$this->owner`.
That is why GeoIP downloaded fine in the same install while the proxy did not.

This disappears entirely once `TalaveraSama/XC_VM_Proxy` exists and is public,
but the fallback has to work regardless — that is what it is for.

### 2.4.9 — `console.php startup` must run as root, not as xc_vm

Found while installing a tuner node on a real box: the installer stopped and
asked for a password.

    ==> Registering the DVB worker in cron
    [sudo] password for xc_vm:

The prompt names xc_vm as the *invoking* user, so a second sudo was running
inside a process already dropped to xc_vm. `StartupCommand` shells out to
`sudo crontab -l/-r/<file>` to install the root crontab, and the panel
installer explicitly removes `/etc/sudoers.d/xc_vm` (build/install:2609), so
xc_vm has no passwordless sudo and that inner call can never succeed. The
prompt is visible despite `>/dev/null 2>&1` because sudo writes it straight to
the terminal.

Running startup as root is the supported path, not merely a workaround.
`StartupCommand:147` picks the prefix of every generated cron line from its own
euid:

    $rPrefix = (euid is root) ? 'sudo -u xc_vm ' : '';

As root it writes `sudo -u xc_vm php ... cron:dvb` into root's crontab, which
is correct. As xc_vm it would write the line unprefixed. So running it as the
wrong user breaks two things at once: the crontab is not written at all, and
the lines it would have written are wrong.

`build/install:2864` and `ServiceCommand.php:77` already ran it as root. Three
places did not, and all three are fixed:

  * `Modules/dvb_9a2c7/install-tuner-node.sh`
  * `Cli/Commands/LbInstallFlow.php:228`   — streaming node install over SSH
  * `Cli/Commands/ProxyInstallFlow.php:79` — proxy node install over SSH

The two SSH ones are the worse half of this. Both connect as root (the
credentials are stored as `root_username`/`root_password`) and both run plenty
of other `sudo` commands, but for startup they dropped to xc_vm. Over a
non-interactive SSH channel the sudo prompt gets no answer, so **every node
installed through the panel came up with no crontab at all** — no cron:dvb, and
nothing in the output saying so. That is exactly the silent-queue failure the
2.4.5 notes warn about: the panel writes rows into `dvb_jobs` and nothing ever
runs them.

Grep to keep it fixed:

    grep -rn "sudo -u xc_vm.*console.php startup" --include=*.php --include=*.sh .

### 2.5.0 — the DVB views never ran any JavaScript (dvb module 1.2.2)

Reported from the real panel: "Discover adapters" did nothing and the NEWCAMD
reachability test produced no message at all, neither success nor failure. The
backend was fine. The views were broken in three separate ways, each enough on
its own to make every button inert.

**1. No footer, so no jQuery.** Every view ended its markup and went straight
to `<script>`. `Public/Views/admin/footer.php` is what loads
`assets/js/vendor.min.js` (jQuery, line 29) and `jquery-toast` (line 30). The
controller `require_once`s the footer file but does not render it -- calling
`renderUnifiedLayoutFooter('admin')` is the view's job, exactly as
`flussonic.php:179` does. Without it `$` is undefined and every handler throws.

**2. `<script>` instead of `<script id="scripts">`.** The panel navigates by
XHR and swaps the page's script block, `Public/assets/admin/js/common.js:269`:

    $("#scripts").replaceWith($(rData).filter("#scripts"));

`filter()` matches only top-level nodes carrying that id. A plain `<script>`
never matches, so the incoming page contributes nothing and the outgoing
page's block is replaced with an empty set. The module's JS is not merely
skipped, it is deleted. 102 of the 120 core admin views carry the id; the 18
that do not are infrastructure partials (header, footer, topbar, modals...),
not pages.

**3. `toastr` does not exist in this panel.** The views called
`toastr.success/error/info` seven times. The codebase has zero references to
toastr and 295 to `$.toast()` (jquery-toast). Even with jQuery loaded those
calls would have thrown.

Fixed in all five views. Also added a `.fail()` handler to all eleven `$.post`
calls: jQuery does not invoke the success callback on a failed request, so
without one an HTTP error is indistinguishable from nothing happening --
precisely the symptom that was reported. `dvb_camd.php` was additionally
missing its closing `</body></html>`.

Checklist for any future module view:

  * ends with `renderUnifiedLayoutFooter('admin')` before the script block
  * the script block is `<script id="scripts">`
  * notifications use `$.toast()`, never `toastr`
  * every `$.post`/`$.getJSON` chains a `.fail()`

No schema change, so no `migrations/1.2.2.sql`.

### 2.5.1 — `update update` answered from a stale release cache

Straight after 2.5.0 was published, a panel on 2.4.9 kept reporting:

    Checking for updates (server=MAIN, version=2.4.9)...
    Using cached releases (channel: stable) from /home/xc_vm/tmp/gitapi_TalaveraSama_fork_xc_vm_stable
    Already up to date.

`GitHubReleases` caches the release list for 30 minutes
(`$cache_ttl = 1800`). The list had been fetched while 2.4.9 was still the
newest tag, so the check could not see 2.5.0 — and repeating the command
never helped, because every attempt read the same file. The only escape was
deleting it by hand.

Only humans reach this code path. `RootSignalsCronJob:551` runs
`console.php update update` for the panel's Update button, and the rest is
someone typing it. Answering "update me now" from a half-hour-old list is
simply wrong, so the `update` action now calls `clearCache()` before asking.

The periodic checker behind the "update available" banner is a different
class, `UpdateCronJob`, which builds its own client and still uses the cache,
so the unauthenticated 60-requests-per-hour API limit stays protected where
it is actually at risk. `cron:maxmind --force` and `cron:proxy --force`
already had their own escape hatch; the update check was the one that did not.

Symptom to recognise: "Already up to date" together with a "Using cached
releases" line naming a file in `/home/xc_vm/tmp/`. Manual workaround on an
older build:

    rm -f /home/xc_vm/tmp/gitapi_<owner>_<repo>_<channel>

## 2.5.2 — a live signal meter, because "tuner never locked" says nothing

`Scanned OK 0 / Services found 0` with `SIGNAL —` gives an operator no way to
tell a mis-aimed dish from mistyped tuning parameters. Both fail identically.

Added a per-transponder meter (blue aerial button on the DVB page). It runs
`dvbv5-zap -c <conf> -a N -f N -m -t 3 [-l <lnb>] [-S diseqc-1] 'CHANNEL'`
and reports strength, C/N, postBER and UCB, refreshing every 1.5 s.

Read it like this:

* strength high, no lock  -> the dish is fine; symbol rate, FEC, modulation or
  polarization is wrong.
* strength near zero      -> LNB power, cabling, DiSEqC port or dish alignment.

Four things that are deliberate and must not be "simplified" away:

1. **It runs inline, not through `dvb_jobs`.** `cron:dvb` ticks once a minute.
   A meter that updates once a minute cannot be used to aim an antenna, which
   is the only reason it exists.
2. **The zap is wrapped in `timeout -k 1 5`.** `dvbv5-zap -m` never exits on
   its own. Without the timeout it would hold the frontend open forever and
   every later scan on that adapter would fail with "device busy".
3. **It refuses when the transponder is streaming.** `DvbAdapterService::pick()`
   returns the adapter a transponder already holds (`in_use_by = <its own id>`),
   so measuring a live transponder would fight dvblast for the same frontend.
4. **It refuses when `server_id` is not this node.** The card is physical. A
   queued job would answer a minute later to a caller polling every 1.5 s.

`parseSignal()` was also wrong in a way that would have made the meter lie:

* `C/N= -13.80dB` was read by `([0-9.]+)` as **+13.80** — a dead carrier scored
  as healthy. All numbers now match `-?[0-9.]+`.
* `Signal=` comes back as a percentage on some drivers and as **dBm** on
  others. `-33.40dBm` was being read as `33%`. The unit is now matched
  explicitly and dBm is mapped with `(dBm + 75) * 2`, clamped to 0..100.
* Lock was `stripos($log, 'Lock') !== false`, which is also true for
  `unlocked`. Now `/(?<!un)\bLock/i` — note there is no trailing `\b`, or
  `LOCKED` would stop matching.

When nothing at all can be read, the meter returns `explainFailure()` rather
than a confident `0%`; a zero that means "no data" and a zero that means "no
signal" are different answers.

Readings land in `signal_strength` / `signal_quality` through the new
`DvbTransponderService::recordSignal()`, which touches **only** those two
columns. `recordScan()` would also reset `scan_status` and `scan_message`, i.e.
erase the failure you are in the middle of diagnosing.

## 2.5.3 — show what the tuner actually said, and a manual bench

The 2.5.2 meter worked and reported `The tuner never locked...`, which is the
canned text from `explainFailure()`. That message is fine when the carrier is
genuinely weak and useless when the real cause was, say, a busy frontend: both
produce the same sentence. `apiSignal()` now also returns the last 2000 bytes
of raw `dvbv5-zap` output and the meter prints it under the bars.

Added `Modules/dvb_9a2c7/signal-debug.sh`, run on the node holding the card:

    bash signal-debug.sh
    FREQ=11970000 POL=HORIZONTAL bash signal-debug.sh

It checks the tools, lists `/dev/dvb`, reports whether the frontend is already
held by another process, dumps `dvb-fe-tool` capabilities, then replays the
exact panel invocation and walks a ladder of one-variable-at-a-time variations
until something locks.

The first rung of that ladder is the one that matters most. `buildInitialFile()`
writes `INNER_FEC` and `MODULATION` straight from the transponder row, so a
DVB-S2 carrier entered as `2/3` + `PSK/8` will refuse to lock unless those
happen to be exactly right. A DVB-S2 demodulator reads FEC and modulation out
of the physical layer header, so `AUTO` for both is strictly better for
scanning: it cannot be wrong, and forcing a value can.

## 2.5.4 — the meter was passing the wrong positional argument

The 2.5.3 meter never tuned anything. Raw output, once it was finally visible:

    reading channels from file '/tmp/.../try.conf'
    ERROR: Can't find channel

`buildZapCommand()` passed the tuning file's section name, `CHANNEL`. That is
right for ordinary zapping and wrong for monitor mode. The synopsis is explicit:

    dvbv5-zap [OPTION]... channel-name
    dvbv5-zap [OPTION]... frequency-name (for monitor or all PIDs mode)

and the manual's monitoring example is `dvbv5-zap -c dvb_channel.conf 573000000
-m`. In monitor mode the positional is matched against **FREQUENCY**. It now
passes `(int) $rT['frequency']`, the same value `buildInitialFile()` writes on
the FREQUENCY line, so the two can never drift apart.

Two things learned from the real card that are worth not rediscovering:

* **There is no `AUTO` for `MODULATION`.** The tool answers `value AUTO is
  invalid for MODULATION while parsing line 7`. `INNER_FEC = AUTO` is fine and
  is the right default; modulation has to be one of QPSK, PSK/8, APSK/16,
  APSK/32. The panel's form never offered a bare AUTO, so only the bench script
  was affected, but the asymmetry is easy to get wrong again.
* **`ERROR FE_SET_VOLTAGE: Operation not permitted` is harmless.** `dvb-fe-tool`
  prints it on exit on TBS cards, including cards that lock perfectly well
  (tbsdtv/linux_media#401, and a linux-media thread showing it right after a
  healthy `Lock (0x1f) Signal= -35.77dBm C/N= 11.90dB`). It is not a diagnosis.

`signal-debug.sh` now passes the frequency, uses explicit modulations, labels
the FE_SET_VOLTAGE line as noise, and finishes with a `dvbv5-scan` cross-check.
dvbv5-scan needs no channel name at all, so if it locks while every zap fails,
the fault is in the zap invocation rather than in the dish.

## 2.5.5 — the card was fine all along: three bugs, none of them the dish

A manual bench on the real TBS6909X locked on the first try with the settings
already stored in the panel, and dvbv5-scan listed 21 services:

    Lock (0x1f) Signal= -30,57dBm C/N= 11,60dB

So every "tuner never locked" this module has ever reported on that machine was
its own doing. Three separate faults, all now fixed.

**1. `dvbv5-scan` has no `-t` option.** `buildCommand()` passed `-t 2`, copied
from `dvbv5-zap` where `-t` is `--timeout`. dvbv5-scan's options are
`-3 -a -C -d -f -F -G -I -l -N -o -O -p -S -T -U -v -w -W`; `-t` is rejected,
so the tool exited without writing an output file, and `scan()` treats a
missing output file as a failed scan. What the old comment claimed to want --
do not chase other transponders -- is `-F/--file-freqs-only`, which is what it
passes now. This is the second time confusing two tools' flags has cost a
release; the first was `-W/-Y` on tsdecrypt.

**2. `parseSignal()` matched nothing under a non-English locale.** The tools
print the decimal separator the locale asks for, so a Spanish machine emits
`Signal= -30,57dBm`. The old pattern `(-?[0-9.]+)` stops at the comma and then
fails to reach the unit, so *no* reading matched -- not a wrong number, no
number at all. There is now a single `NUMBER` constant accepting either
separator and a `toNumber()` that normalises it. Verified against the real log:
71% bar, quality 55, locked; the old pattern returned empty arrays.

**3. `signal-debug.sh` killed its own scanner.** Section 7 piped a running
dvbv5-scan into `head -40`. When head exits it closes the pipe, the scanner
takes SIGPIPE and dies -- after listing all 21 services but before writing its
output file, which is why the bench said "No services written" and looked like
it confirmed the panel's failure. Never pipe a long-running producer into
`head`: capture to a file, then read the file.

The bench now runs both scan commands, the old `-t 2` one and the corrected
`-F` one, so the difference is demonstrated rather than asserted.

Not a fault, worth recording: `ERROR FE_SET_VOLTAGE: Operation not permitted`
is noise `dvb-fe-tool` prints on exit on TBS cards, present on cards that lock
perfectly (tbsdtv/linux_media#401). And `MODULATION` has no `AUTO` value,
though `INNER_FEC` does.

## 2.5.6 — proof for 2.5.5, and a bench that stops lying when the tuner is busy

The 2.5.5 bench confirmed the `-t 2` diagnosis outright:

    7a. the old panel command, with -t 2 (expected to FAIL)
        dvbv5-scan: invalid option -- 't'
        [exit 255]

That run also exposed a different problem. `fuser` reported the frontend held
by a process named `astra` (Cesbo Astra, the software this module exists to
replace), so all seven zap attempts returned `Device or resource busy` and the
script still finished with "suspect LNB power, cabling, the DiSEqC port, or
dish alignment". It had already detected the real cause in section 3 and then
blamed the antenna anyway.

`signal-debug.sh` now stops as soon as it finds the frontend busy, prints
`ps` for each holding PID and the `systemctl status` line to investigate it,
and exits 1. `FORCE=1` overrides. If a `Device or resource busy` shows up in
any attempt log, the final verdict says the run proves nothing about the dish
instead of offering antenna advice. Both directions were exercised against a
fake `fuser`: busy exits 1 before any test, free proceeds.

`explainFailure()` gained a branch for `invalid option` / `unrecognized
option`, which reports a module bug rather than a tuning problem. Its
`Device or resource busy` branch was already correct and already ordered ahead
of the generic "never locked" text, so the panel itself would have named the
conflict properly.

Operationally: Cesbo Astra must be stopped and disabled on that node, or it
will keep claiming tuners the module wants.

## 2.5.7 — the import threw away the CAMD you picked

Scanning and importing both worked, 21 services were found and channels were
created, and not one of them would open. The DECRYPT column read "—" on every
imported row even though "Decrypt with: 70w" had been chosen.

`apiImport()` built its options array with `category_id`, `bouquets`, `prefix`,
`skip_encrypted` and `start`. It never read `camd_id`. The view has always sent
it (`camd_id: $('#import_camd').val()`) and `DvbImportService::import()` has
always expected it (`$rCamdID = (int) ($rOptions['camd_id'] ?? 0)`), so the
value was dropped in the one layer between them.

The consequence is total rather than partial, because of:

    $rDecrypt = ($rCamd !== null) && !empty($rService['encrypted']);

With no CAMD, `$rDecrypt` is false for every service, so each row was stored
with `camd_id` NULL, `enc_port` NULL and `decrypt_status` 'off'. DVBlast then
wrote the scrambled transport stream straight to `output_port`, tsdecrypt was
never started, and the channel served encrypted bytes to the player.

`apiDecrypt()` / `assignCamd()` were never affected: that path reads `camd_id`,
allocates an `enc_port` when one is missing and restreams. It is the recovery
route for channels imported before this fix, and it works on 2.5.6 too.

Also added a guard for a genuinely confusing combination: choosing a CAMD while
leaving "Skip encrypted" ticked now fails with an explanation instead of
importing nothing useful. The encrypted services are precisely the ones a CAMD
exists for, so the two settings cancel out.

Wiring the CAMD up is not the same as the CAMD working. Once `camd_id` and
`enc_port` are set, `decrypt_status` moves pending -> running or error, and
`decrypt_message` carries tsdecrypt's reason. That is where to look next if a
channel still does not open.

## 2.5.8 — "running" was a lie when tsdecrypt is alive but rejected

With 2.5.7 the chain finally ran end to end: DVBlast -> tsdecrypt -> NEWCAMD.
The CAMD server's own log showed the node connecting to port 10011 over and
over and being turned away:

    user rubengt is trying to connect but doesnt exist ! (generic)
    plain newcamd-client <node ip> rejected (no such user)

The panel said `running` throughout. `explainFailure()` is only consulted when
tsdecrypt has *died*; a process that stays up and reconnects for ever passes
`isRunning()`, the watch loop does `continue`, and the status is never
revisited. So the one case an operator cannot diagnose from the outside was
also the one case the panel refused to report.

New `liveTrouble()` inspects the log tail of a *running* tsdecrypt each tick.
It matches known rejection wording (`no such user`, `doesnt exist`, `access
denied`, `login fail`, `bad password`, `rejected`), and failing that counts
connection attempts: eight or more in forty lines means the session is being
dropped as fast as it is opened. The raw tail is always appended, since a
guess about which signature matched is worth less than what the tool printed.
Marking the row `error` is non-destructive; nothing is killed or restarted.

Two configuration traps this exposed, both in `dvb_camd`:

* `username` must exist on the CAMD server. Nothing in the module can know
  that, but the status now says so instead of claiming success.
* `ca_system` defaults to `CONAX`, and `caSystem()` falls back to `CONAX` for
  any unrecognised value. A Nagravision carrier (CAID 1802) will never decrypt
  under that default. Setting `caid` is the better fix: `buildCommand()` emits
  `-C <caid>` when it is present and only falls back to `-c <ca_system>` when
  it is empty.

## 2.5.9 — one encrypted channel is one CAMD session

The CAMD server's log reads `SID:CAID@provider`, one connection per channel:

    02CB:1802@000000

Two of those service ids match services imported from the transponder
(`010F` = 271, `02A0` = 672), and `1802` is Nagravision, which is why a profile
left on the `CONAX` default decrypts nothing. Filling in `caid` is the better
fix than changing `ca_system`: `buildCommand()` emits `-C <caid>` when it is
set and only falls back to `-c <ca_system>` when it is empty.

The header comment of `DvbDecryptRunner` already warned that "a CAMD line with
a session limit will notice", but nothing enforced it. Twenty-one encrypted
channels means twenty-one simultaneous logins; the server rejects the surplus,
and a rejected tsdecrypt reconnects for ever, which looks like a flood and can
get the address banned.

`dvb_camd` gains `max_connections` (0 = no cap, the previous behaviour) via
`migrations/1.3.0.sql`. `supervise()` counts live sessions per CAMD in a
separate pass before starting anything -- necessary because a row further down
the list already holds a session when an earlier row asks for one -- and holds
the surplus back with a message naming the cap instead of letting the server
refuse it.

## stream-debug.sh — finding which hop drops the picture

signal-debug.sh proves the carrier locks. `Modules/dvb_9a2c7/stream-debug.sh`
answers the next question: given a lock, where between tuner and player does
the picture vanish. It lists the work directory, reports whether dvblast and
tsdecrypt are running and with which arguments, prints the dvblast configs and
the tsdecrypt logs verbatim, extracts the UDP ports from those configs and
uses tcpdump to say which of them actually carry traffic.

Two things it checks that are easy to get wrong and invisible from the panel:

* **`-Y` and `-W` on the dvblast command line.** dvblast strips conditional
  access tables by default. `DvbStreamRunner::buildCommand()` adds both as
  soon as any service on the carrier is decrypting, but dvblast only reads its
  config and arguments at startup, so a carrier that was already streaming
  when the CAMD was assigned keeps running without them. tsdecrypt then logs
  in and waits for ECMs that never arrive.
* **Which CA selection tsdecrypt got.** `-C <caid>` is exact; `-c <name>`
  falls back to CONAX for anything unrecognised. tsdecrypt locates the ECM PID
  in the PMT by CA system, so a CONAX setting on a Nagravision (1802) carrier
  finds no ECM PID and never sends a request. On the server that looks like a
  client which logs in and then goes silent — not like a failure at all.

Worth recording for context, since the question keeps coming up: TVHeadend,
Cesbo Astra and set-top receivers descramble inside a single process. They
demux, read the ECM PID from the PMT, talk to the card server and apply the
control word with no UDP hop. This module splits those stages across dvblast
and tsdecrypt, which buys reuse and costs two extra failure modes — ECMs must
survive the remux, and the descrambler must be told the right CA system.
Neither is a defect in the design, but both are invisible unless something
looks at the actual command lines, which is what this script is for.

## 2.6.0 — the supervisor knew why, and told nobody

`stream-debug.sh` answered in its first section: `/home/xc_vm/tmp/dvb` did not
exist. `DvbStreamRunner::start()` calls `ensureWorkDir()` before writing the
DVBlast config, so a missing directory means start() returned through one of
its four early exits and dvblast was never launched. No producer, hence no UDP
anywhere, hence a black channel — and none of that is a decryption problem.

The four exits are very different from each other:

    No services imported from this transponder; nothing to stream.
    <the unsupported() blocker: multistream, or a non-universal LNB>
    dvblast not found on this node. Install it (apt-get install dvblast).
    No tuner available on this node. Run Discover adapters first.

`supervise()` files the right one in `stream_message` every time. Nothing
showed it. `cron:dvb` printed `0 started, 0 stopped, 1 failed`, and the
transponder list rendered a red `error` badge with the reason buried in a
`title=` attribute, visible only on hover. Both supervisors now return a
`messages` array that `cron:dvb` prints one per line, and the transponder list
prints the message under the badge instead of hiding it in a tooltip.

Running `console.php cron:dvb` by hand is now a real diagnostic:

    /home/xc_vm/bin/php/bin/php /home/xc_vm/console.php cron:dvb

This is the same fault as 2.5.2's signal meter, 2.5.6's busy frontend and
2.5.8's "running" decryptor: the module knew the answer and the interface
showed a status code instead. Worth treating as a standing rule — any branch
that records a reason must also have a path that displays it.

## Newcamd protocol notes (OSCam module-newcamd.c)

Read while diagnosing `rubengt`. Recording it because two of these facts let
you rule things out from a server log alone.

Session shape: TCP connect, the server sends a **14-byte challenge**, the
client derives a key and sends `MSG_CLIENT_2_SERVER_LOGIN`, the server answers
`_ACK` or `_NAK`, the client asks `MSG_CARD_DATA_REQ` and gets `MSG_CARD_DATA`
carrying the CAID, provider list and serial. Only then does the ECM loop run,
with `MSG_KEEPALIVE` alongside.

Key derivation happens twice: an initial key from the 14-byte challenge plus
the pre-shared `ncd_key`, then a session key from `ncd_key` plus the
**MD5-crypted** password. The key is 14 bytes, which is why `desKey()` insists
on 28 hex characters — that check is right.

**A server log that names the user proves the DES key is correct.** The
username travels inside the DES-encrypted login message. If `ncd_key` did not
match, the server would decrypt garbage and could not print `rubengt`. The
module's own wording, "a wrong DES key looks exactly like a wrong password",
is true from the client side and false from the server side: on the server
they are distinguishable, and this is how.

**The newcamd port is bound to a CAID on the server.** OSCam's port syntax is
`port = 10011@1802:000000`, and `mk_user_ftab()` resolves the filter as client
CAID table, then client IDENT, then **server port CAID**. So a client arriving
on 10011 is talking about CAID 1802 whether it knows it or not — which is the
argument for setting `caid` on the profile so `buildCommand()` emits
`-C 1802` rather than falling back to `-c CONAX`.

ECM requests carry SID at bytes 8-9, CAID at 10-11 and PRID at 12-15, so the
server sees exactly which service is being asked about. A DCW response is 19
bytes with 16 bytes of control word, or 3 bytes when not found.

Causal note, because it was briefly got wrong: a rejected login is a complete
and sufficient explanation for "the server never asks for an ECM". The ECM
loop is downstream of `MSG_CLIENT_2_SERVER_LOGIN_ACK`. No CA-system theory is
needed until the account exists and the login succeeds.

## Correction: the newcamd traffic was never ours

Two turns were spent analysing OSCam log lines showing a client `rubengt`
being rejected from `190.143.242.57`, on the assumption it was this module's
tsdecrypt. It was not.

`DvbDecryptRunner::workDir()` and `DvbStreamRunner::workDir()` both resolve to
`CACHE_TMP_PATH/dvb/`, and tsdecrypt's pidfile and log live there. That
directory did not exist on the node, which proves neither dvblast nor
tsdecrypt had ever been launched by the panel. The rejected logins came from
something else sharing the same public address — the Cesbo Astra install, a
receiver, anything on that LAN.

The lesson is cheap to state and was expensive here: **a NAT address is not an
identity.** Before attributing traffic to a component, confirm the component
has ever run. `stream-debug.sh` answers that in its first section, and it was
available before the analysis started.

None of the protocol notes above are wrong, and the account and CAID advice
still applies once the chain runs. But the live blocker was never decryption:
dvblast does not start, so there is no transport stream, so no decryptor is
ever spawned, so nothing connects to the card server.

## 2.6.1 — the real blocker, and a dead-end error message

2.6.0 made `supervise()` show its reason, and the transponder row answered
immediately:

    Could not write the DVBlast config to /home/xc_vm/tmp/cache/dvb/tp1.conf.

Two things follow. First, the actual scratch path is
`CACHE_TMP_PATH/dvb/` = `/home/xc_vm/tmp/cache/dvb/`, **not**
`/home/xc_vm/tmp/dvb/`. `stream-debug.sh` was looking in the latter and
reported "nothing has ever been started" from the wrong directory — right
conclusion, wrong evidence. It now probes all three candidates, prints which
exist, and on finding none lists `ls -ld` of the parents, which is where a
permission problem is visible.

Second, that message was itself a dead end: it names the file and stops. It
cannot distinguish a missing parent from a directory owned by the wrong user,
which need different fixes. `ensureWorkDir()` in both runners now returns the
reason instead of a bool, built by the shared
`DvbScanService::describePath()`: which user the process runs as, who owns the
directory, its mode, whether it is writable, and the `chown` that fixes it.

Care needed at the call sites — both read `if (!self::ensureWorkDir())`, and
returning null for success inverts that into failure-on-success. Both were
rewritten to `$rWhy = ...; if ($rWhy !== null)`.

The likely cause on that node is ownership: `console.php` has been run as root
several times this session, and `update` does `cp -a`, so parts of
`/home/xc_vm` can end up root-owned while `cron:dvb` runs as `xc_vm`.

## dvb-verify.sh — pre-flight as the panel user, not as root

`Modules/dvb_9a2c7/dvb-verify.sh` checks everything that has to be true before
a transponder can go on air, and runs each step through
`su -s /bin/sh -c … xc_vm`. That detail is the whole point: root can create
directories and open frontends that `cron:dvb` cannot, so verifying any of
this as root proves nothing about whether the panel will work.

It checks, in order: that the panel account exists and is **in the `video`
group** (without it no tuner can be opened, and `explainFailure()` already
had a branch for the resulting "Permission denied" it could never otherwise
explain); that the account can write `/home/xc_vm/tmp/cache/dvb`; that
dvbv5-scan, dvbv5-zap, dvblast and tsdecrypt are installed; which adapters are
free and which are held, naming the holding process; then it writes the same
tuning file `buildInitialFile()` produces and runs the same scan command
`buildCommand()` produces, as the panel user, and reports the services found.

Flags were cross-checked against the module: `-F`, `-O DVBV5`, `-a`, `-f`,
`-o`, `-l` and a conditional `-S`. Note that grepping `buildCommand()` for
flags still turns up `-t 2` — that is the comment recording the 2.5.5 bug, not
code. Easy to misread, as happened once while writing this.

## dvb-verify.sh correction — a busy tuner can mean success

The permission fix worked: `/home/xc_vm/tmp/cache/dvb` was created owned by
`xc_vm`, and `cron:dvb` started dvblast, which claimed adapter0. The verify
script then reported:

    adapter0  held by pid 3646 (dvblast)
    FAIL  adapter 0 is busy; free it before testing

That is exactly backwards. A frontend held by our own `dvblast` is the module
working, and the script was telling the operator to kill the process they had
spent the whole session trying to start. It now names the holder, treats
`dvblast`/`tsdecrypt` as the panel running, and falls through to a free
adapter for its own test rather than fighting the live one.

Generalising it, since this is the fourth variant of the same mistake in this
module: **a check that reports a state must also interpret it.** "Busy" is not
a verdict until you know who holds it, the same way "running" was not a
verdict for tsdecrypt in 2.5.8 and "no lock" was not a verdict for a frontend
that was never opened in 2.5.6.

Practical follow-on: manual capture tests must target a free adapter. On this
card adapter7 is held by an unrelated process called `streamer`, adapter0 by
the panel, and 1-6 are free.

## 2.6.2 — a 156 MB log in five minutes, and a pin that was not honoured

The chain now runs: dvblast holds adapter0 and all 21 UDP ports carry traffic.
Two faults showed up in the same capture.

**The log.** `tp1.log` reached 156 MB in roughly five minutes. dvblast emits
one `couldn't writev to 127.0.0.1:NNNNN (Connection refused)` per packet per
port that has no reader, and until the panel's consumers start, that is every
port. `start()` appends with `>>` and nothing bounded it. `supervise()` now
calls `trimLog()` on every pass, keeping the last 64 KB once the file passes
4 MB. Safe against the live process because dvblast holds the file `O_APPEND`
and so continues at the new end.

**The pin.** The transponder form offers "Any free tuner on that server" or a
specific adapter. `pick()` honoured a pinned `adapter_id` unconditionally:

    if ($rPinned !== null) { return $rPinned; }

so a second transponder pinned to a tuner the first was already streaming on
got it handed back, and the failure resurfaced downstream as "Device or
resource busy" — which reads like a hardware fault rather than two carriers
competing. `pick()` now refuses a pin held by a *different* transponder.

It deliberately does not fall through to any free tuner in that case: a pin
usually means that port is cabled to a particular dish, and quietly switching
would scan the wrong satellite. `start()` instead reports which transponder
holds the pinned adapter and suggests repinning or "Any free tuner".

Two operator-side notes from the same session. dvblast was running without
`-Y`/`-W` because no service had a `camd_id`: those flags are added only when
`decrypting()` is true for some service on the carrier, so assigning the CAMD
and letting the transponder restart is what turns ECM passthrough on. And
`dvbv5-zap` in record mode (`-P -r`) wants the **channel name**, while monitor
mode (`-m`) wants the **frequency** — the two forms in the synopsis are not
interchangeable, which cost a confusing "Can't find channel".

## 2.6.3 — the card server was never the problem: a CAID mismatch

A manual tsdecrypt run against a captured transport stream produced the whole
answer, and it is not what it looks like. The login succeeded:

    CAM | [newcamd] Card info: CAID 0x1802 Admin=NO
    --- | ECM CAID: 0x187a (NAGRA)
    ERR | [newcamd] Card was not able to decode the channel.

The card holds **0x1802**. tsdecrypt was sending it ECMs for **0x187a**. The
transponder's PMT advertises three Nagra CA descriptors — 0x1802 on CA PID
0x0c20, 0x1871 on 0x0c21, 0x187a on 0x0c22 — and selecting the CA system by
family name (`-c NAGRA`) makes tsdecrypt take the last matching descriptor,
which is not the one the card holds. "Card was not able to decode the channel"
then reads like a missing entitlement at the provider, and is not one.

So on any carrier with multiple CA systems, the `caid` field on the CAMD
profile is effectively mandatory: `buildCommand()` only emits `-C` when it is
set, and otherwise falls back to `-c`.

`liveTrouble()` now parses both lines out of the tsdecrypt log and reports the
mismatch by name, with the CAID to set. It also recognises "not able to
decode" on its own.

**`-C` format matters.** tsdecrypt parses it with `strtoul(optarg, NULL, 0)`,
base 0, so a bare `1802` is read as *decimal* 1802 = CAID 0x070a and matches
nothing. `caid()` already prefixed `0x`, but its hex filter turned an operator's
`0x1802` into `0x01802` by eating the `x` and keeping the `0`. Harmless under
strtoul, by luck. It now strips a leading `0x` first.

Also confirmed from the same log: `dvbv5-zap` in record mode needs `-l` (else
"Need a LNBf to work") and takes the **channel name**, not the frequency.

## Differential CAID/SID testing against a known-good account

With `-C 0x1802 -M 0x02ce` every client-side field finally lined up: the card
reports CAID 0x1802, tsdecrypt selects ECM CAID 0x1802 on CA PID 0x0c20, the
service is 0x02ce, and real ECM payloads go out. The server still answers
"Card was not able to decode the channel", which in newcamd terms is the
3-byte DCW meaning not-found.

That exhausts what the client can be blamed for, but "it must be the provider"
is a weak conclusion on its own. There is a stronger one available whenever
another account is visible in the same server's log.

An earlier capture of that log showed a working user, `celestedeco`, receiving
control words for a list of SIDs on the same CAID and provider. Two of those,
**0x010f (271)** and **0x02a0 (672)**, are also present in our transponder's
PAT. **0x02ce (718) was not in that list.**

So the test is: run the same tsdecrypt command with `-M 0x010f`. If it returns
a control word, the account and the whole chain are fine and 718 is simply not
in the package — a per-channel entitlement question, not a broken setup. If it
fails identically on a SID another user decrypts right now, the difference is
account configuration on the server (reader group, CAID/ident), and that is a
precise, non-arguable thing to hand the administrator.

Worth generalising: when a shared server exposes another account's successful
requests, that account is a control group. Comparing against it converts "my
side looks right" into "same CAID, same provider, same service, same server,
one account gets a CW and the other does not".

## 2.6.4 — a heuristic was overruling the operator

The transponder went on air with 71% signal and 21 services, the import form
had "Skip encrypted" unticked and "Decrypt with: 70w" chosen, the channel was
created — and the DECRYPT column still read "—". The clue was in the CA
column: the padlocks had turned **green and open**, meaning `encrypted = 0` on
every row, where an earlier scan had shown them yellow and closed.

`encrypted` is set from the presence of `PID_09` in the scanned channel file.
The code already called it "a heuristic, not a promise", and it was wrong
here: the same services' PMTs carry three Nagra CA descriptors each, as the
tsdecrypt dump shows. So a scan that does not emit PID_09 marks a scrambled
mux as free to air.

Two places then silently refused to act on the operator's instruction:

* `import()` required `($rCamd !== null) && !empty($rService['encrypted'])`,
  so choosing a CAMD did nothing at all.
* The services view only rendered the per-row DECRYPT dropdown when
  `$rLinked && !empty($rRow['encrypted'])`, so the manual recovery path was
  hidden too. Between them there was no way to decrypt anything and no
  message explaining it.

Picking a CAMD is an explicit instruction and now outranks the guess:
`$rDecrypt = ($rCamd !== null)`. The dropdown shows for any imported service.
Being wrong in this direction costs one CAMD session on a free-to-air service,
since tsdecrypt passes unscrambled data through unharmed; being wrong in the
other direction cost the whole feature.

The "Free to air" tooltip no longer asserts. It now says no conditional access
was seen in the scan, that this is a guess from the channel file, and to
assign a CAMD anyway if the picture is scrambled.

Same shape as 2.5.2, 2.5.6, 2.5.8 and 2.6.0: the module preferred its own
inference to what it had been told, and said nothing when the two disagreed.

## Conclusion of the decryption investigation: the account, not the code

The differential test settled it. Running tsdecrypt against the captured mux
with `-C 0x1802 -M 271`:

* service 271 found in the PAT, PMT parsed
* card reports CAID 0x1802, tsdecrypt selects ECM CAID 0x1802
* ECM PID 0x0acc, which is the 0x1802 descriptor in *that service's* PMT
* a real ECM payload is sent
* the server answers "Card was not able to decode the channel"

And the same server's log, captured earlier, shows another account served for
exactly that service:

    (ecm) celestedeco (1802@000000/0000/010F/...): cache3 (1660 ms) by gtmediaserver

`010F` is 271. Same server, same CAID, same provider, same service: one
account gets a control word in 1.6 s, the other does not. Every client-side
variable is now controlled, so the remaining difference is the account.

One more detail worth keeping. Each failure takes **exactly three seconds**,
and the OSCam web interface showed `3000` on that user's row. A card that
rejects an ECM answers immediately; a flat three-second gap is a timer
expiring because nothing served the request. In OSCam that is what happens
when no reader in the user's group can supply the CAID — so the first thing
to check is the account's `group` against the reader's, then `caid`/`ident`,
then any `services` filter.

Nothing in this module needs to change for it. The panel side is complete:
set `caid` to `1802` on the CAMD profile and, once the account is fixed,
assigning the CAMD is the only remaining step.

## 2.6.5 — it decrypts; now the stutter

AMC Series came up with `DECRYPT: 70w` showing **running**, channel #22 live at
2428 Kbps, h264/aac. The whole chain works: card, dvblast, tsdecrypt, newcamd,
panel. What remained was a periodic stutter, and the reported 58 FPS against a
nominal 59.94 is about 3% of frames missing.

Two causes, one of them ours.

**Control words arriving late.** The card server's own log gives the latency
distribution: median 1772 ms, 95th percentile 2128 ms, worst 2181 ms. Control
words rotate every crypto period, so a CW that arrives after the period starts
leaves tsdecrypt with no key for those packets, and `mute_on_error` then emits
nothing rather than mush. That is the stutter. `-T/--input-buffer` exists for
exactly this and the field is already in the CAMD profile, but its help text
suggested a flat 1000 ms, which is below this line's 95th percentile. It now
says to set it above the slowest answer the server gives and names 2500 for a
two-second line.

**ffmpeg's UDP defaults.** `sourceUrl()` emitted a bare `udp://host:port`. The
default socket receive buffer is 64 KB, which overflows on the bursts a
transponder produces, and the loss reads as a bad signal rather than as a
buffer problem. It now appends
`?overrun_nonfatal=1&fifo_size=100000&buffer_size=2097152` — roughly 18 MB of
FIFO, since fifo_size counts 188-byte packets, plus a socket buffer that will
be clamped by `net.core.rmem_max` if that is left at the distribution default.

Only new imports get the tuned URL. Existing channels keep the source string
stored at import time and have to be edited or re-imported.

## 2.6.6 — session visibility, and why only one channel opens

Reported symptom: 19 connections visible for the newcamd user on OSCam, but
only one channel plays.

That shape is not a session *limit*. A limit refuses the surplus and leaves
the earlier sessions working. Nineteen connections churning while exactly one
channel decrypts is what `uniq` does in OSCam: with `uniq = 1` (or 2/3) each
new login for the same account disconnects the previous one, so twenty-one
tsdecrypt processes take turns kicking each other off and only whichever
logged in last holds a usable session. The account needs `uniq = 0`.

The structural point behind it stands either way: descrambling is per service,
so twenty-one encrypted channels mean twenty-one CAMD logins. A single local
OSCam acting as a proxy collapses that to one upstream session and serves all
of them locally, which is the right shape for this many channels.

Panel changes here are about seeing it. `DvbCamdService::all()` now also
counts services whose `decrypt_status` is `running`, and the CAMD list shows
`running / assigned` with the cap underneath and an explicit "N not
decrypting" when the two disagree. Assigned and running are very different
numbers once a line runs out of sessions, and showing only the first left the
operator guessing.

`supervise()` also caches the CAMD row per pass instead of calling
`DvbCamdService::find()` once per service. It only reaches that line when a
decryptor is not already running, so the saving is nil in steady state and
twenty-one identical queries a minute exactly when the node is thrashing.

## 2.6.7 — connected but starved

Seven services showed `70w` + running, ports allocated in pairs as designed
(10000/10001, 10002/10003, …), and OSCam listed twenty-one connections for the
account. Only one of them, `00DB:1802@000000` — SID 219, AMC Series — was
pulling ECMs. The rest were logged in and silent.

That rules out both a session cap and `uniq`: a cap refuses the surplus and
`uniq` makes sessions take turns, and neither leaves twenty sessions quietly
connected. A decryptor that logs in and never asks anything is a decryptor
receiving no input.

tsdecrypt's README names this exact state:

    ECM | Received 0 (0 dup) and processed 0 in 60 seconds.
    CW  | *ERR* No valid code word was received for 60 seconds!

and attributes it to the streamer not passing ECMs through. `liveTrouble()`
now matches both lines and reports that the decryptor is connected but starved,
naming the two causes: dvblast not writing to that service's port, or dvblast
running without `-Y/--ecm-passthrough` because it was started before the CAMD
was assigned. The pattern deliberately does not match `Received 1 (0 dup)`,
which is the different problem of ECMs arriving and being refused.

This is the hardest failure to see from outside, because on the card server it
looks like a perfectly healthy idle session rather than an error.

## 2.6.8 — the EMM flood, which was mine

The decryptor logs finally explained both the stutter and the dead channels:

    WRN | Too many items (10012) in EMM queue, dropping the oldest.
          Consider switching to cs378x protocol!
    EMM | Received 76603, Skipped 91, Sent 25, Processed 21 in 60 seconds.
    CWC | SID 0x00e0 EcmTime: 9996 ms CW_time: 18164 ms

Seventy-six thousand EMMs a minute, per decryptor. The card server answered
with `EMM rejected by card`, `Failed to read message` and
`EMM unexpected server response`, its client list filled with
`timeout (5000 ms)`, and control words arrived up to eighteen seconds late.
That is the stutter, and on a saturated node it is also why several decryptors
only ever logged `Input read timeout`.

`buildCommand()` added `-Y` and `-W` together whenever any service on the
carrier was decrypting. `-Y/--ecm-passthrough` is always needed. `-W/--emm-passthrough`
is not: it is only useful when a CAMD profile has EMM forwarding switched on,
and the account here reports `Admin=NO`, meaning no AU rights, so the EMMs
could never have been used. They now travel only when some decrypting
service's profile actually asks for them.

tsdecrypt's own warning recommends cs378x for EMM-heavy setups, which is
another argument for the local OSCam proxy: one upstream session, EMM handling
in one place, and control words cached out of the critical path.

The EMM checkbox now carries the numbers rather than a vague "off unless the
provider asks for it".

## verify-release.py — the release cadence outgrew the API page size

`verify-2.6.8` failed with:

    TalaveraSama/fork_xc_vm publishes no binaries-* release, so the installer
    has nowhere to get bin/php/bin/php, ...

The release existed. `binaries-29062026` was sitting at index **30** of 61
releases, which is the first item of page two, and `api()` fetched a single
page. GitHub returns 30 items by default, so `latest_binaries()` simply never
saw it. Thirteen consecutive verifications had passed before the repository
crossed that boundary, which is a good reminder that a check can rot without
anyone touching it.

`api_list()` now pages with `per_page=100` until a short page comes back, and
both `/releases` walks use it. Verified against the live API: 61 releases
enumerated, mirror found.

Two things worth keeping in mind. A failing verification is not automatically
a bad release — this one was fine and the verifier was wrong, which is why the
first move was to re-run it rather than to pull the release. And the `base-*`
releases double the count, so the page boundary arrives twice as fast as the
version numbers suggest.

## 2.6.9 — signal bars that stay on screen

Two bars per transponder in the list, strength and quality, replacing the
single percentage badge. Strength and quality fail for different reasons — a
misaimed dish drops strength, a marginal carrier drops quality — and someone
adjusting an antenna needs to watch both.

The interesting half is that they now update while the carrier is on air.
`measureSignal()` used to refuse outright when `streaming` was set, on the
grounds that the meter and dvblast would fight over the tuner. That is true of
`dvbv5-zap`, which tunes. It is not true of `dvb-fe-tool --femon`, which the
manual describes as monitoring "a frontend that is already being streamed via
some other application" and which "opens the frontend on read-only mode". Its
output is the same format `parseSignal()` already reads.

So `measureSignal()` now branches: streaming carriers go through
`monitorSignal()` and `--femon`, idle ones keep the `dvbv5-zap` path. The
controller's refusal is gone.

The list refreshes only rows marked `data-live="1"`, which are the streaming
ones, and walks them one at a time with `.always()` chaining so a slow node
cannot pile requests up. Idle carriers keep showing their last stored reading
rather than being tuned behind the operator's back, which would claim a tuner
nobody asked to use.

## 2.7.0 — the bars caused the 502s

2.6.9 put live bars in the transponder list and polled `dvb_signal` once per
streaming carrier every 15 seconds. That endpoint shells out to
`dvb-fe-tool --femon` and blocks for `SIGNAL_SECONDS + 1` = four seconds.

So every open copy of the DVB page held a PHP-FPM worker for four seconds per
carrier, continuously. A panel pool is small; it ran dry, and nginx answered
**502 across the whole panel** — which also explains the top status bar only
filling in after a manual reload, since its own AJAX was being refused.

Sampling now happens where the time is free. `cron:dvb` gains
`sampleSignals()`, which walks the carriers that are on air on this node,
measures them read-only with `--femon` and stores the result through
`recordSignal()`. The page reads what the cron left behind:
`DvbTransponderService::signalSnapshot()` is a single `SELECT` over
`dvb_transponders`, exposed as `dvb_signal_cache`, and the list fetches it
once for every row every 20 seconds instead of once per row.

`apiSignal()` is unchanged and still synchronous, because a human pressed a
button and is waiting for the answer. The rule worth keeping: **an endpoint
that shells out must never be put on a timer.** One button press is fine; a
poll multiplies the cost by every open tab.

## 2.7.1 — plain UDP URLs again, and a slower meter

Reverts the query string 2.6.5 appended to imported sources. It now reads
`udp://127.0.0.1:10048` again. The operator asked for it: the parameters show
up verbatim in the stream editor, `buffer_size` is clamped to
`net.core.rmem_max` regardless, and nobody could measure the benefit. Anyone
who wants them can add them to an individual stream.

The manual meter's poll goes from 1500 ms to 3000 ms between samples. It was
already chained inside the success callback rather than on a fixed interval,
so it never overlapped, but the request behind it holds a PHP-FPM worker for
about four seconds while it reads the demodulator, and a tight loop there
competes with the rest of the panel for a small pool.

Worth stating plainly, because it was checked and ruled out: with 2.7.0 the
list bars read a cached row and the meter is chained, so neither is a
plausible source of sustained 502s any more. A panel still returning 502
constantly while roughly twenty ffmpeg processes, twenty tsdecrypt processes
and a dvblast share the node is far more likely to be out of CPU, memory or
PHP-FPM children, and `nginx`'s error log says which in so many words.
