# Handoff

XC_VM 2.3.9 with a Flussonic module, packaged as installable GitHub releases.
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

**3. Run the fork's workflows,** in order: *Mirror upstream base archive*,
*Mirror upstream binaries*, then *Build Release* with tag `2.3.9`. After the
two mirrors, neither installing nor updating touches Vateron-Media.

This is still pending, and until this session it could not have worked —
see the first two entries under "Faults found in the fork's own CI" below.
The Actions tab is the only way in: a session token scoped to this repo
**cannot dispatch workflows** (`HTTP 403` on `actions/workflows/*/dispatches`).

**Two ways to start them now.** `workflow_dispatch` runs the copy on the
default branch, so it needs PR #1 merged first — and it needs the Actions tab
or a real `gh`, which the user's machine does not have (its `gh` is gitsome).
So both mirrors also trigger on a pushed tag, which any git client can do and
which runs the workflow file *at the tagged commit*, branch or not:

    git tag seed-base-2.3.9  && git push origin seed-base-2.3.9    # base layer
    git tag seed-binaries    && git push origin seed-binaries      # binaries
    git tag 2.3.9            && git push origin 2.3.9              # the release

`seed-*` deliberately does not match the `base-<version>` and
`binaries-<tag>` releases those jobs publish, so neither can retrigger itself.
The tag is only a doorbell — the version still comes from `AppConfig.php`.

**A plain `N.N.N` tag now publishes a stable release.** `prerelease` used to
default to `true` on anything that was not dispatched by hand, so a pushed
`2.3.9` was published as a prerelease — and `GitHubReleases` drops prereleases
on the stable channel, making the one tag that exists to be seen by the
in-panel updater invisible to it. The flag is now derived from the tag when
the run was not dispatched: `2.3.9` stable, `v2.3.9-flussonic.19` prerelease.

Until a release exists here, the redirection is inert and upstream still wins
by default. Resolved live against the API while writing this:

    TalaveraSama/fork_xc_vm        -> None
    Vateron-Media/XC_VM_Binaries   -> 29062026
    ==> source chosen: Vateron-Media/XC_VM_Binaries

That is `resolve_binaries_source()` run verbatim. The fork is first in the
list and is skipped because it has nothing to offer yet.

Confirmed ready for it: upstream still publishes `2.3.9` as a stable release
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

## Faults found in the fork's own CI

Same rule: do not revert these. All four were verified, not reasoned about.

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
