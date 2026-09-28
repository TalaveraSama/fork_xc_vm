#!/usr/bin/python3
"""Check that a published XC_VM release can actually be installed.

The release archive is deliberately not self-contained: it carries the panel
and the shared data, and the distribution-specific runtime (php, nginx,
nginx_rtmp) is downloaded during the install from the binaries mirror. So
"the build succeeded" says nothing about whether an install would finish.
This reassembles what the installer would end up with -- base archive plus
the per-distribution tarball, combined the same way `install` combines them --
and checks the result is a complete, runnable tree.

It downloads only; it writes nothing outside a temporary directory and never
touches /home/xc_vm. Safe to run on the machine you are about to install on.

    build/verify-release.py                          # this fork, latest stable
    build/verify-release.py --tag 2.3.9 --distro all
    build/verify-release.py --owner Vateron-Media --repo XC_VM   # compare upstream

Exit status is 0 only if every checked distribution would produce a complete
tree. GH_TOKEN is used if set, purely to avoid the anonymous rate limit.
"""

import argparse
import hashlib
import json
import os
import shutil
import sys
import tarfile
import tempfile
import urllib.error
import urllib.request
import zipfile

API = "https://api.github.com"

# The runtime the panel cannot start without. Kept in step with the list in
# build/build-release.sh; that one decides what a build may ship, this one
# decides what an install must end up with.
RUNTIME = [
    "bin/php/bin/php",
    "bin/php/lib/php/extensions/no-debug-non-zts-20210902/xcvm_core.so",
    "bin/nginx/sbin/nginx",
    "bin/nginx_rtmp/sbin/nginx_rtmp",
    "bin/redis/redis-server",
    "bin/ffmpeg_bin/8.0/ffmpeg",
]

# Things the panel is broken without, whichever layer supplies them.
PANEL = [
    "bootstrap.php",
    "console.php",
    "service",
    "update",
    "Core/Config/AppConfig.php",
    "Core/Updates/GitHubReleases.php",
    "vendor/autoload.php",
    "bin/install/database.sql",
]

# A live panel's identity or credentials must never be in a published archive.
LEAKS = ["config.enc", "install_id", "config/install_id", "signals.last",
         "tmp/cache/hmac_keys"]

GREEN, RED, YELLOW, DIM, OFF = "\033[92m", "\033[91m", "\033[93m", "\033[2m", "\033[0m"
if not sys.stdout.isatty():
    GREEN = RED = YELLOW = DIM = OFF = ""

IN_CI = bool(os.environ.get("GITHUB_ACTIONS"))
_problems = []


def note(msg):
    print(msg)
    if IN_CI:
        print("::notice::" + msg.replace("\n", " "))


def bad(msg):
    _problems.append(msg)
    print(f"{RED}FAIL{OFF}  {msg}")
    if IN_CI:
        print("::error::" + msg.replace("\n", " "))


def ok(msg):
    print(f"{GREEN}ok{OFF}    {msg}")


def api(path):
    req = urllib.request.Request(API + path, headers={
        "User-Agent": "xc_vm-verify-release",
        "Accept": "application/vnd.github+json",
    })
    token = os.environ.get("GH_TOKEN") or os.environ.get("GITHUB_TOKEN")
    if token:
        req.add_header("Authorization", "Bearer " + token)
    with urllib.request.urlopen(req, timeout=60) as r:
        return json.load(r)


def fetch(url, dest):
    req = urllib.request.Request(url, headers={"User-Agent": "xc_vm-verify-release"})
    with urllib.request.urlopen(req, timeout=300) as r, open(dest, "wb") as f:
        shutil.copyfileobj(r, f, 1 << 20)
    return os.path.getsize(dest)


def md5(path):
    h = hashlib.md5()
    with open(path, "rb") as f:
        for chunk in iter(lambda: f.read(1 << 20), b""):
            h.update(chunk)
    return h.hexdigest()


def human(n):
    n = float(n)
    for unit in ("B", "KB", "MB", "GB"):
        if n < 1024 or unit == "GB":
            return f"{n:.1f}{unit}"
        n /= 1024.0


def safe_members(tar, dest):
    """Refuse absolute paths and ../ escapes -- the same guard `install` uses."""
    dest = os.path.realpath(dest)
    for m in tar.getmembers():
        target = os.path.realpath(os.path.join(dest, m.name))
        if target == dest or target.startswith(dest + os.sep):
            yield m


def read_hashes(path):
    """`md5  name` per line, as produced by md5sum."""
    out = {}
    with open(path, encoding="utf-8", errors="replace") as f:
        for line in f:
            parts = line.split()
            if len(parts) >= 2:
                out[parts[-1].lstrip("*")] = parts[0]
    return out


def release_by_tag(owner, repo, tag):
    if tag:
        return api(f"/repos/{owner}/{repo}/releases/tags/{tag}")
    for rel in api(f"/repos/{owner}/{repo}/releases"):
        if not rel["draft"] and not rel["prerelease"]:
            return rel
    raise SystemExit(f"{owner}/{repo} has no published stable release")


def latest_binaries(owner, repo):
    """The newest binaries-* mirror release, or upstream's own latest."""
    for rel in api(f"/repos/{owner}/{repo}/releases"):
        if not rel["draft"] and str(rel["tag_name"]).startswith("binaries-"):
            return rel
    return None


def assets_of(rel):
    return {a["name"]: a["browser_download_url"] for a in rel["assets"]}


def find_bin_dir(root):
    """Where the distro tarball keeps its bin/, using `install`'s heuristic."""
    direct = os.path.join(root, "bin")
    if os.path.isdir(direct):
        return direct
    for entry in sorted(os.listdir(root)):
        cand = os.path.join(root, entry, "bin")
        if os.path.isdir(cand):
            return cand
    for entry in sorted(os.listdir(root)):
        cand = os.path.join(root, entry)
        if os.path.isdir(cand) and {"php", "nginx", "nginx_rtmp"} & set(os.listdir(cand)):
            return cand
    if {"php", "nginx", "nginx_rtmp"} & set(os.listdir(root)):
        return root
    for dirpath, dirnames, _ in os.walk(root):
        if {"php", "nginx", "nginx_rtmp"} & set(dirnames):
            return dirpath
    return None


def main():
    ap = argparse.ArgumentParser(description=__doc__,
                                 formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--owner", default="TalaveraSama")
    ap.add_argument("--repo", default="fork_xc_vm")
    ap.add_argument("--tag", default=None, help="release tag (default: latest stable)")
    ap.add_argument("--distro", default="ubuntu_22",
                    help="'all', or a tarball stem such as ubuntu_22 / debian_12")
    ap.add_argument("--keep", action="store_true", help="keep the work directory")
    args = ap.parse_args()

    work = tempfile.mkdtemp(prefix="xcvm-verify-")
    print(f"{DIM}work: {work}{OFF}\n")
    try:
        run(args, work)
    finally:
        if args.keep:
            print(f"\n{DIM}kept: {work}{OFF}")
        else:
            shutil.rmtree(work, ignore_errors=True)

    print()
    if _problems:
        print(f"{RED}{len(_problems)} problem(s){OFF} — this release would not install cleanly:")
        for p in _problems:
            print("  - " + p)
        return 1
    print(f"{GREEN}The release is installable.{OFF}")
    return 0


def run(args, work):
    rel = release_by_tag(args.owner, args.repo, args.tag)
    tag = rel["tag_name"]
    note(f"Release {args.owner}/{args.repo} @ {tag} "
         f"({'prerelease' if rel['prerelease'] else 'stable'}, "
         f"{'draft' if rel['draft'] else 'published'})")

    if rel["draft"]:
        bad("the release is a draft — the in-panel updater cannot see it, and "
            "its assets are not downloadable anonymously")
    if rel["prerelease"]:
        bad(f"'{tag}' is a prerelease — GitHubReleases drops prereleases on the "
            "stable channel, so the updater will not offer it")

    assets = assets_of(rel)
    for want in ("XC_VM.zip", "xc_vm.tar.gz", "hashes.md5"):
        if want not in assets:
            bad(f"release {tag} has no {want}")
    if _problems:
        return

    # ---- 1. the published archives ------------------------------------
    print("\n-- archives --")
    dl = os.path.join(work, "dl")
    os.makedirs(dl)
    sizes = {}
    for name in ("hashes.md5", "XC_VM.zip", "xc_vm.tar.gz"):
        sizes[name] = fetch(assets[name], os.path.join(dl, name))
        print(f"      downloaded {name} ({human(sizes[name])})")

    expected = read_hashes(os.path.join(dl, "hashes.md5"))
    if not expected:
        bad("hashes.md5 is empty or unparseable")
    for name in ("XC_VM.zip", "xc_vm.tar.gz"):
        if name not in expected:
            bad(f"hashes.md5 does not cover {name}")
            continue
        got = md5(os.path.join(dl, name))
        if got == expected[name]:
            ok(f"{name} matches its published md5")
        else:
            bad(f"{name} md5 mismatch: published {expected[name]}, downloaded {got}")

    # ---- 2. what the user actually unzips ------------------------------
    print("\n-- XC_VM.zip --")
    with zipfile.ZipFile(os.path.join(dl, "XC_VM.zip")) as z:
        names = z.namelist()
        for want in ("install", "xc_vm.tar.gz"):
            if want in names:
                ok(f"zip contains {want}")
            else:
                bad(f"zip has no top-level {want} — 'unzip XC_VM.zip && sudo python3 install' "
                    f"would fail (found: {', '.join(names[:6])})")
        if "install" in names:
            z.extract("install", dl)
            head = open(os.path.join(dl, "install"), "rb").read(64)
            if head.startswith(b"#!"):
                ok(f"install is a script ({head.splitlines()[0].decode(errors='replace')})")
            else:
                bad("install does not start with a shebang")
        if "xc_vm.tar.gz" in names:
            z.extract("xc_vm.tar.gz", os.path.join(work, "inner"))
            inner = md5(os.path.join(work, "inner", "xc_vm.tar.gz"))
            outer = md5(os.path.join(dl, "xc_vm.tar.gz"))
            if inner == outer:
                ok("the tarball inside the zip is the published one")
            else:
                bad("the tarball inside XC_VM.zip differs from the published "
                    "xc_vm.tar.gz — two different trees are being shipped under one release")

    # ---- 3. the deploy tree --------------------------------------------
    print("\n-- deploy tree --")
    tree = os.path.join(work, "tree")
    os.makedirs(tree)
    with tarfile.open(os.path.join(dl, "xc_vm.tar.gz")) as t:
        t.extractall(tree, members=safe_members(t, tree))
    count = sum(len(f) for _, _, f in os.walk(tree))
    note(f"deploy tree: {count} files")

    for p in PANEL:
        if not os.path.exists(os.path.join(tree, p)):
            bad(f"deploy tree is missing {p}")
    if not _problems:
        ok(f"all {len(PANEL)} panel entry points present")

    for leak in LEAKS:
        if os.path.exists(os.path.join(tree, leak)):
            bad(f"the archive carries {leak} — a live panel's identity or credentials")
    ok("no credential or identity files in the archive")

    cfg = os.path.join(tree, "Core/Config/AppConfig.php")
    if os.path.exists(cfg):
        text = open(cfg, encoding="utf-8", errors="replace").read()
        owners = {}
        for line in text.splitlines():
            if "GIT_OWNER" not in line or "define(" not in line:
                continue
            quoted = line.split("'")[1::2]
            if len(quoted) >= 2:
                owners[quoted[0]] = quoted[1]
        redirected = {k: v for k, v in owners.items() if k != "GIT_OWNER"}
        if redirected and all(v == args.owner for v in redirected.values()):
            ok(f"the shipped panel points at {args.owner} for all of "
               f"{', '.join(sorted(redirected))}")
        else:
            bad(f"the shipped AppConfig.php does not point at {args.owner}: {redirected}")

    have = {p for p in RUNTIME if os.path.exists(os.path.join(tree, p))}
    missing = [p for p in RUNTIME if p not in have]
    if missing:
        note(f"the archive leaves {len(missing)} runtime component(s) to the installer: "
             + ", ".join(missing))
    else:
        note("the archive already carries the whole runtime")

    if not missing:
        ok("nothing else to check — the tree is complete on its own")
        return

    # ---- 4. what the installer adds ------------------------------------
    print("\n-- per-distribution runtime --")
    brel = latest_binaries(args.owner, args.repo)
    if brel is None:
        bad(f"{args.owner}/{args.repo} publishes no binaries-* release, so the "
            "installer has nowhere to get " + ", ".join(missing))
        return
    note(f"binaries mirror: {brel['tag_name']}")
    bassets = assets_of(brel)

    bhash = {}
    if "hashes.md5" in bassets:
        hp = os.path.join(dl, "binaries.hashes.md5")
        fetch(bassets["hashes.md5"], hp)
        bhash = read_hashes(hp)

    stems = sorted(n[:-7] for n in bassets if n.endswith(".tar.gz"))
    if not stems:
        bad(f"{brel['tag_name']} has no .tar.gz assets")
        return
    wanted = stems if args.distro == "all" else [args.distro]
    complete = []

    for stem in wanted:
        name = stem + ".tar.gz"
        if name not in bassets:
            bad(f"no {name} in {brel['tag_name']} (has: {', '.join(stems)})")
            continue
        path = os.path.join(dl, name)
        fetch(bassets[name], path)
        if name in bhash:
            if md5(path) != bhash[name]:
                bad(f"{name} does not match its published md5 — the installer "
                    "verifies this and would abort")
                continue

        ex = os.path.join(work, "distro", stem)
        os.makedirs(ex, exist_ok=True)
        with tarfile.open(path) as t:
            t.extractall(ex, members=safe_members(t, ex))
        src = find_bin_dir(ex)
        if src is None:
            bad(f"{name}: the installer's structure detection finds no bin/ in it "
                f"(top level: {', '.join(sorted(os.listdir(ex))[:8])})")
            continue

        supplied = {p for p in missing
                    if os.path.exists(os.path.join(src, p[len("bin/"):]))}
        still = [p for p in missing if p not in supplied]
        if still:
            bad(f"{stem}: after the install the tree would still be missing "
                + ", ".join(still))
        else:
            complete.append(stem)
            ok(f"{stem}: supplies all {len(missing)} missing component(s) — "
               f"a complete tree")

    # The one line worth reading: which distributions this release installs
    # cleanly on. Emitted as a notice so it survives into the run annotations,
    # which are readable when the log is not.
    if complete:
        note("complete tree after install on: " + ", ".join(complete))
    for stem in wanted:
        if stem not in complete:
            note(f"NOT verified complete: {stem}")


if __name__ == "__main__":
    sys.exit(main())
