# Flussonic module

Turns XC_VM into a control panel for one or more **Flussonic Media Server**
origins: register a server, browse every stream it publishes, and import the
ones you want as live channels of the panel.

---

## What it does

### 1. Origin servers — `flussonic`

Register any number of Flussonic servers. For each one you configure:

| Group | Settings |
| --- | --- |
| **Connection** | scheme, host, port, API user/password *or* bearer token, TLS verification, enabled |
| **Playback** | source protocol, playback host/port (when a CDN or edge fronts the origin), RTMP/RTSP ports, static playback token |
| **Import defaults** | target streaming server, category, bouquets, channel-name prefix, direct-source |
| **Synchronisation** | sync interval, auto-import of new streams, forget streams the origin stopped publishing |

The list shows live status, how many streams were discovered, how many are
already imported, and the last sync time, with per-row *Sync now*, *Enable /
Disable*, *Edit* and *Delete* actions.

### 2. Available streams — `flussonic_streams`

Everything the configured origins publish, in one table: alive state, bitrate,
online clients, resolution and codecs, DVR depth, the Flussonic input URL, and
whether the stream already exists as a panel channel.

Filter by server, by state (alive / offline) or by import status, search by
name or title, then:

- **Import Selected** — tick the rows you want;
- **Import All Pending** — everything not imported yet, for one origin or all;
- **Sync Now** — re-read the catalogue from the origin;
- copy the source URL, or open Flussonic's own `embed.html` preview.

### 3. Import

An import creates a real row in the panel's `streams` table (`type = 1`), so
the channel behaves like any other: it appears in bouquets, playlists, the
Xtream API and the streaming pipeline. Each imported channel gets

- `stream_source` built from the origin's **selected protocol**,
- the configured category and bouquets,
- a binding in `streams_servers` to the chosen streaming server,
- `tv_archive_duration` derived from the stream's DVR depth,
- a `notes` line recording which origin and stream it came from.

Imports are idempotent: a catalogue row remembers the panel stream it created,
and a stream whose URL is already in the panel is adopted instead of
duplicated.

### 4. Cron — `cron:flussonic`

Installed at `*/5 * * * *`. It syncs every enabled origin whose interval has
elapsed and imports new streams for origins with **Auto Import** on.

```
php console.php cron:flussonic             # honour each server's interval
php console.php cron:flussonic --force     # sync everything now
php console.php cron:flussonic 3 --import  # sync server 3 and import its new streams
```

---

## Source URL formats

The protocol is chosen per server in the UI — nothing is hardcoded.

| Protocol | URL built for stream `NAME` |
| --- | --- |
| HLS | `http://HOST:PORT/NAME/index.m3u8` |
| LL-HLS | `http://HOST:PORT/NAME/index.ll.m3u8` |
| MPEG-TS | `http://HOST:PORT/NAME/mpegts` |
| MPEG-DASH | `http://HOST:PORT/NAME/index.mpd` |
| RTMP | `rtmp://HOST[:1935]/static/NAME` |
| RTSP | `rtsp://HOST[:554]/NAME` |

A static playback token, when set, is appended as `?token=…`. Changing the
protocol (or host/token) and then running **Refresh source URLs** rewrites the
`stream_source` of every channel already imported from that origin.

---

## API used

Flussonic's v3 REST API, with HTTP Basic auth or a bearer token:

- `GET /streamer/api/v3/streams` — cursor-paginated list (`limit`, `cursor`,
  `q`), read in pages of 200 until exhausted;
- `GET /streamer/api/v3/streams/{name}` — single stream;
- `GET /streamer/api/v3/server` — version/identity (falls back to
  `/flussonic/api/server`, and is optional — a server that does not expose it
  still syncs).

---

## Layout

```
FlussonicModule.php          routes, navbar, cron entry, install/uninstall
FlussonicController.php      admin pages + JSON actions
FlussonicCronJob.php         cron:flussonic
Contract/                    HttpTransportInterface
Http/CurlTransport.php       cURL implementation
Exception/                   FlussonicApiException
Service/
  FlussonicApiClient.php     v3 API client
  FlussonicUrlBuilder.php    playback URL construction
  FlussonicServerService.php origins CRUD + connection probing
  FlussonicStreamService.php discovered-stream catalogue
  FlussonicSyncService.php   discovery + import pipeline
views/                       flussonic, flussonic_server, flussonic_streams
database.sql                 schema (flussonic_servers, flussonic_streams)
database_drop.sql            teardown
bin/flussonic-api.php        standalone CLI probe
dev/                         offline preview harness (not used in production)
```

### Tables

- **`flussonic_servers`** — one row per origin: connection, playback, import
  defaults, sync state (`status`, `last_sync`, `last_error`, `streams_found`).
- **`flussonic_streams`** — the catalogue: one row per stream seen on an
  origin, with its last known stats and `stream_id`, the link to the panel
  channel it produced (`0` = not imported).

Uninstalling drops both tables and removes the cron row. Channels already
imported are **not** deleted — by then they are ordinary panel streams.

---

## Permissions

| Action | Permission |
| --- | --- |
| View servers / streams, sync, edit origins | `streams` |
| Import streams as channels | `add_stream` |

---

## Trying it without a Flussonic server

`dev/` contains a self-contained preview that runs the real controller,
services and views against recorded Flussonic payloads and a SQLite database.
See [`dev/README.md`](dev/README.md).
