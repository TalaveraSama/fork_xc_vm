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
