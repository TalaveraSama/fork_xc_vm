# @@NAME@@ mirror

Release assets for the XC_VM panel, mirrored from
[`Vateron-Media/@@NAME@@`](https://github.com/Vateron-Media/@@NAME@@).

The panel fetches these at install time and keeps them fresh with a cron job.
Mirroring them here means neither step depends on the upstream repository
being reachable, or on its release assets staying put.

Contents are **unmodified** and verified against upstream's `hashes.md5`
before being republished. Tags and the stable/prerelease flag are kept exactly
as upstream published them: the panel reads this repository through
`GitHubReleases`, which filters by channel and compares tags with PHP's
`version_compare`, so a retagged or re-flagged mirror would either be
invisible to the panel or read as a newer release of something else.

## How it stays current

`.github/workflows/mirror-data.yml` resolves upstream's latest release, skips
the work if this repository already has that tag with the same assets,
downloads, verifies every checksum, and publishes. It runs weekly and on
demand:

```bash
gh workflow run mirror-data.yml                  # mirror upstream's latest
gh workflow run mirror-data.yml -f tag=25.09.26  # a specific tag
gh workflow run mirror-data.yml -f force=true    # re-upload an existing tag
```

It publishes with the repository's own `GITHUB_TOKEN`, so there is no access
token to create or rotate.

## Why the name matters

The workflow derives upstream from this repository's own name — `@@NAME@@`
here, so `Vateron-Media/@@NAME@@` there — and refuses to run under any other
name rather than failing later in a way that looks like a network problem. On
the panel side, `Core/Config/AppConfig.php` pairs `GIT_OWNER_UPDATE` /
`GIT_OWNER_PROXY` with `GIT_REPO_UPDATE` / `GIT_REPO_PROXY`, whose values are
`XC_VM_Update` and `XC_VM_Proxy`. Renaming this repository breaks both ends.

## Why this repository is public

The panel downloads these assets anonymously. Release assets in a private
repository return 404 without a token, so a private mirror would simply not
work.

## Licensing

Contents are redistributed unmodified for interoperability with the panel.
The GeoLite2 databases are MaxMind's, under their own licence. If the upstream
authors object, delete this repository and set the matching constant in
`Core/Config/AppConfig.php` back to `Vateron-Media`.
