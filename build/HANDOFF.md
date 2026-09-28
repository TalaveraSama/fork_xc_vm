# Handoff

XC_VM 2.4.0 with a Flussonic module, packaged as installable GitHub releases.
2.4.0 is this fork's own number; the source layer it is built from is
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

    2.4.0              XC_VM 2.4.0              stable       XC_VM.zip, xc_vm.tar.gz, hashes.md5
    2.3.9              XC_VM 2.3.9              stable       XC_VM.zip, xc_vm.tar.gz, hashes.md5
    binaries-29062026  XC_VM binaries 29062026  prerelease   6 distro tarballs + hashes.md5
    base-2.4.0         Base deploy tree 2.4.0   prerelease   xc_vm.tar.gz, hashes.md5, upstream-tag.txt
    base-2.3.9         Base deploy tree 2.3.9   prerelease   xc_vm.tar.gz, hashes.md5

`2.4.0` is the newest stable, so it is what the panel's stable channel returns
and what the Update button offers. Both base layers hold the same 174,180,187
byte archive — upstream's 2.3.9 — because that is the tree this source is
built from regardless of the number on the front.

Verified installable on all six distributions after being rebuilt at 2.4.0:
`complete tree after install on: debian_11, debian_12, debian_13, ubuntu_20,
ubuntu_22, ubuntu_24`, deploy tree 6157 files.

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
2.4.0 — only `base-2.4.0` has to be seeded.

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

    git tag seed-base-2.4.0 && git push origin seed-base-2.4.0   # base layer
    git tag seed-binaries   && git push origin seed-binaries     # distro binaries
    git tag 2.4.0           && git push origin 2.4.0             # the release

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

## This fork modifies eighteen upstream files

Established by byte comparison against a clone of upstream 2.3.9, with the two
corrections a naive diff needs:

- **Line endings are not changes.** `* text=auto` rewrites CRLF on checkout,
  which makes roughly 200 untouched files — most of `ministra/`, most of
  `Public/assets/` — look modified.
- **Git LFS pointers are not changes.** `Public/assets/admin/videos/
  login-bg.mp4` is LFS upstream, so a clone without LFS yields a 132-byte
  pointer. Our copy's sha256 matches the pointer's oid exactly: not patched.

The list lives in `.github/patched-upstream-files.txt` and is the single
source of truth for both workflows. It is **18 files** since the GeoIP and
proxy sources were redirected too; re-measure with the byte comparison above
rather than trusting this number. The one that the original handoff missed is
`resources/langs/en.ini`, which the previous handoff missed. It is **not a
deliberate patch**: the panel appends missing language keys at runtime with
the key as its own value, and that edit came across when the source was
captured from the running server. The two appended keys are
`mass_edit_mags` and `mass_edit_enigmas` — the navbar labels
`CoreNavbarProvider.php` asks for, which upstream's `en.ini` genuinely lacks
(it defines only the `permission_`-prefixed variants). Harmless, but it is
drift, and it is now tracked rather than invisible.

Of the other fourteen, seven carry the fixes above and seven only redirect
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
