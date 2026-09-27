# XC_VM binaries mirror

Per-distribution runtime tarballs for the XC_VM panel, mirrored from
[`Vateron-Media/XC_VM_Binaries`](https://github.com/Vateron-Media/XC_VM_Binaries).

The panel installer replaces the bundled `bin/` with a build matched to the
host distribution — `ubuntu_20.tar.gz`, `ubuntu_22.tar.gz`, `ubuntu_24.tar.gz`,
`debian_11.tar.gz`, `debian_12.tar.gz`, `debian_13.tar.gz`. Mirroring them
here means an install no longer depends on the upstream repository being
reachable, or on its release assets staying put.

Contents are **unmodified** and verified against upstream's `hashes.md5`
before being republished. Each release keeps upstream's tag (e.g. `29062026`)
so `bin_version.json` still records a meaningful version.

## How it stays current

`.github/workflows/mirror.yml` resolves upstream's latest release, skips the
work if this repository already has that tag with the same assets, downloads,
verifies every checksum, and publishes. It runs weekly and on demand:

```bash
gh workflow run mirror.yml                      # mirror upstream's latest
gh workflow run mirror.yml -f tag=29062026      # a specific tag
gh workflow run mirror.yml -f force=true        # re-upload an existing tag
```

It publishes with the repository's own `GITHUB_TOKEN`, so there is no access
token to create or rotate.

## Why this repository is public

The installer downloads these assets anonymously. Release assets in a private
repository return 404 without a token, so a private mirror would simply not
work. Nothing from the panel repository is copied here — only upstream's
binaries.

## Licensing

Upstream's binaries repository carries **no license file**. The tarballs
bundle builds of ffmpeg, nginx, PHP and redis (each under its own upstream
licence) together with XC_VM's compiled extension. This mirror redistributes
them unmodified for interoperability with the panel; if the upstream authors
object, delete this repository and point the installer back at theirs — the
installer keeps upstream as an automatic fallback.
