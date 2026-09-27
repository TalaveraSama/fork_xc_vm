# Flussonic module — offline preview

A self-contained sandbox that runs the module **for real** without a panel
install, a MySQL server or a Flussonic box.

```bash
# from the repository root
php -S 0.0.0.0:8080 Modules/flussonic_1f4a9/dev/index.php
# then open http://localhost:8080/flussonic
```

Append `?reset=1` to any page to rebuild the sandbox database from scratch.

## What is real, and what is not

**Real** — everything that matters:

| | |
| --- | --- |
| Controller | `FlussonicController` |
| Services | `FlussonicApiClient`, `FlussonicUrlBuilder`, `FlussonicServerService`, `FlussonicStreamService`, `FlussonicSyncService` |
| Views | `views/flussonic.php`, `views/flussonic_server.php`, `views/flussonic_streams.php` |
| Schema | the shipped `database.sql`, plus the panel tables taken from the repository's SQL dump |
| Layout | the panel's own `Public/Views/layouts` + `Public/Views/admin/{header,topbar,footer}.php`, assets and all |
| Routing | the core `Router`, so page names and API actions resolve exactly as in production |
| Import | writes to `streams`, `streams_servers` and `bouquets` through the real `StreamRepository` / `BouquetService` / `StreamProcess` |

**Substituted** — three seams, all confined to `dev/`:

| Production | Sandbox |
| --- | --- |
| MySQL via `DatabaseHandler` | `Support/DevDatabase.php` — the same handler on a SQLite file, with a MySQL→SQLite translation pass (`Support/MysqlToSqlite.php`) |
| `CurlTransport` hitting a Flussonic server | `Support/DevTransport.php` — recorded v3 payloads for two demo origins, injected via `FlussonicServerService::setTransport()` |
| Session/user bootstrap | `bootstrap.php` — fixed admin user, permissions and settings |

Core admin pages other than the module's own are not booted; links to them land
on `Support/placeholder.php` so the navbar stays usable. The one exception is
`stream?id=N`, which shows the raw `streams` row the import produced.

## Demo content

`Support/DevSeed.php` creates one main + one edge streaming server, five live
categories, two bouquets, and three Flussonic origins:

| Origin | Protocol | State |
| --- | --- | --- |
| Flussonic Main (demo) | HLS | 12 streams, **already imported** into category *Flussonic Imports* / bouquet *Full Package* |
| Flussonic Edge NI (demo) | MPEG-TS | 5 streams, **pending import**, prefix `NI \|` |
| Offline Origin (demo) | HLS | unreachable — shows the error state |

Credentials for the two reachable origins are `admin` / `flussonic`; anything
else returns HTTP 401, so the failure paths are reachable from the UI too.

## Things worth clicking

- **Test Connection** on the add/edit form, with right and wrong credentials.
- **Sync Now** — re-reads the catalogue (cursor pagination included).
- **Import Selected** / **Import All Pending** on *Flussonic Streams*, then
  follow the green `imported` badge to see the row it created in `streams`.
- Change a server's **Source Protocol** and run *Refresh source URLs*: the
  `stream_source` of every channel already imported from it is rewritten.

## Notes

- `dev/runtime/` holds the SQLite file and is git-ignored.
- Nothing in `dev/` is loaded by `ModuleLoader`; it only runs when you start
  `dev/index.php` yourself.
- The only production change made for this harness is
  `FlussonicServerService::setTransport()`, a normal dependency-injection seam
  that defaults to cURL.
