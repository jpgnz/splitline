# Splitline

Self-hosted endurance and health telemetry app backed by a single SQLite database. Packaged as a multi-architecture Docker container with no external database or cache services.

Supports syncing from the **Garmin Connect Personal API** (`GARMIN_KEY`) and importing standalone `.fit`/`.zip` files via CLI or a watched folder (`AUTO_IMPORT_DIR`).

![Splitline overview: health dashboard, 3D wind-colored route map, running best efforts, and cycling power analytics](docs/screenshots/00-hero.png)

> More screenshots in [docs/screenshots](docs/screenshots/README.md).

## Features

- **Daily Health & Recovery**: Sleep stages, HRV, resting heart rate, Body Battery, stress, SpO2, weight, VO2 Max, and training readiness.
- **Training Load, Insights & Recap**: Performance Management Chart (CTL/ATL/TSB), sleep/HRV/weather correlations, and Year in Sport recaps.
- **FIT Telemetry & Comparison**: Local FIT archive caching, second-by-second streams with slice inspection, aerobic decoupling (Pa:Hr / Pw:Hr), efficiency factor, offline Best Efforts, and side-by-side activity comparison.
- **3D Maps, Heatmap & Explorer**: MapLibre GL maps with Mapterhorn 3D terrain and hillshading, GPS route heatmap, and Explorer tiles.
- **Routes & Segments**: Automatic matched route clustering, auto-detected climbs, custom segments with personal leaderboards, and Nominatim place naming.
- **Historical Weather & Wind**: Route-sampled Open-Meteo weather with headwind/crosswind/tailwind breakdowns, wind-colored routes, and wind arrows.
- **Sports & Disciplines**: Hubs for Running, Cycling, Hiking, Walking, Swimming, and Strength & Cardio, discipline reclassification, Eddington numbers, and activity exclusion.
- **Gear, Goals & Exports**: Equipment mileage tracking, goals with streaks, `.fit`/`.zip` folder auto-import, GPX/CSV/JSON/FIT exports, and live SQLite backups.
- **Units & Themes**: Metric/Imperial units and Light, Dark, or System themes.

## Quick Start

Pre-built `linux/amd64` and `linux/arm64` images are published to `ghcr.io/splitline-php/splitline` (`:latest`, semver tags such as `:1`, `:1.2`, `:1.2.3`, and `:edge` from `main`).

### 1. Download Compose files

```bash
mkdir splitline && cd splitline
curl -fsSLO https://raw.githubusercontent.com/splitline-php/splitline/main/deploy/docker/compose.yaml
curl -fsSLO https://raw.githubusercontent.com/splitline-php/splitline/main/deploy/docker/.env.example
cp .env.example .env
```

### 2. Configure and start

Set `TIMEZONE`, `APP_URL`, and `GARMIN_KEY` (or `AUTO_IMPORT_DIR` for local FIT files) in `.env`, then start the container:

```bash
docker compose up -d
```

Open `http://localhost:8080`. On startup, Splitline creates `/data/database.sqlite`, runs migrations, and starts background workers and scheduled jobs.

> **Security**: Splitline serves plain HTTP on port `8080` with no built-in authentication or TLS. Restrict access and terminate HTTPS upstream; do not expose port `8080` directly to the internet. See [SECURITY.md](SECURITY.md).

### 3. Backfill history

```bash
docker exec -it splitline php artisan garmin:check
docker exec -it splitline php artisan garmin:sync --backfill
docker exec -it splitline php artisan garmin:sync-streams --queue
docker exec -it splitline php artisan weather:sync --queue
```

Route matching, climb detection, derived metrics, and reverse geocoding run automatically on startup and hourly, or can be queued from Settings.

### Building locally

```bash
cd deploy/docker
cp .env.example .env
docker compose -f compose.yaml -f compose.local.yaml up -d --build
```

## Updating & Backups

### Updating

```bash
docker compose pull
docker compose up -d
```

Migrations run automatically on startup. Persistent data lives in `splitline_data` (`/data`) and `splitline_fits` (`/var/www/html/storage/app/private`).

### Upgrading from v0.1.x (breaking)

> [!WARNING]
> Starting with v0.2.0 the image uses Alpine instead of Debian. The container user's UID changed from `33` to `82`, so v0.1.x volumes cannot be reused and startup will fail with a "not writable" error.

Recreate the Docker volumes and re-import your data:

```bash
docker compose down -v
docker compose pull
docker compose up -d
```

`down -v` deletes `splitline_data` and `splitline_fits`, including the auto-generated `APP_KEY`. Run the Garmin backfill again afterward. Manually entered data (goals, weight goals, gear, and settings) is not stored in Garmin and must be re-entered.

### Live SQLite backup

Download a live SQLite snapshot from **Settings**, or run `.backup` from the CLI (SQLite runs in WAL mode, so do not copy the file directly while running):

```bash
docker exec splitline sqlite3 /data/database.sqlite ".backup '/data/splitline-backup.sqlite'"
docker cp splitline:/data/splitline-backup.sqlite ./splitline-backup-$(date +%F).sqlite
docker exec splitline rm -f /data/splitline-backup.sqlite
```

Back up `splitline_fits` as well to keep cached FIT archives.

## Artisan Commands

Prefix commands with `docker exec -it splitline` when running in Docker.

| Command                                                                                           | Description                                                                         |
| :------------------------------------------------------------------------------------------------ | :---------------------------------------------------------------------------------- |
| `php artisan garmin:check [--refresh]`                                                            | Verify `GARMIN_KEY` and Garmin Connect Personal API connectivity.                   |
| `php artisan garmin:sync [--days=7]`                                                              | Incremental sync of health summaries and activities (runs every 30 min).            |
| `php artisan garmin:sync --backfill [--from=YYYY-MM-DD] [--until=YYYY-MM-DD]`                     | Resumable history backfill from Garmin.                                             |
| `php artisan garmin:sync-streams [--limit=N] [--activity=ID] [--force] [--redownload] [--queue]`  | Download, cache, and parse FIT streams, GPS routes, and Best Efforts.               |
| `php artisan weather:sync [--limit=N] [--activity=ID] [--force] [--upgrade-only] [--queue]`       | Fetch historical Open-Meteo weather for outdoor activities.                         |
| `php artisan routes:match [--rebuild] [--queue] [--chunk=100]`                                    | Fingerprint outdoor GPS polylines and cluster matched routes (runs hourly).         |
| `php artisan segments:scan [--rebuild] [--queue] [--chunk=50]`                                    | Detect climbs and match personal segments across outdoor streams (runs hourly).     |
| `php artisan activities:compute-derived-metrics [--activity=ID] [--force] [--queue] [--chunk=25]` | Compute aerobic decoupling (Pa:Hr / Pw:Hr), EF, and pacing metrics (runs hourly).   |
| `php artisan geocode:sync [--queue] [--limit=50] [--force]`                                       | Reverse-geocode routes, segments, and activities missing place names (runs hourly). |
| `php artisan import:fit <paths...>`                                                               | Import standalone `.fit` or `.zip` files or directories.                            |
| `php artisan import:scan [--dir=PATH] [--mode=keep\|archive] [--no-age-check]`                    | Scan `AUTO_IMPORT_DIR` for new `.fit` and `.zip` files (runs every 5 min).          |
| `php artisan demo:seed [--database=PATH] [--force] [--days=730]`                                  | Build an isolated synthetic SQLite demo database.                                   |

## Configuration

All variables are optional unless you want to override defaults. Set `GARMIN_KEY` for Garmin syncing or `AUTO_IMPORT_DIR` for folder auto-import.

| Variable                           | Default                               | Description                                                                                                        |
| :--------------------------------- | :------------------------------------ | :----------------------------------------------------------------------------------------------------------------- |
| `APP_URL`                          | `http://localhost:8080`               | Public URL of your instance (`https://...` behind TLS termination).                                                |
| `APP_KEY`                          | _(auto-generated)_                    | Laravel encryption key; persisted to `/data/.app_key` if omitted.                                                  |
| `TIMEZONE`                         | `UTC`                                 | IANA timezone for dates, streaks, and daily rollups (falls back to `TZ`).                                          |
| `DEFAULT_UNITS`                    | `metric`                              | Initial unit system (`metric` or `imperial`); overridable in Settings.                                             |
| `DEFAULT_SPORTS_MODE`              | `auto`                                | Initial Sports & Disciplines navigation mode (`auto` or `custom`).                                                 |
| `GARMIN_KEY`                       | _(empty)_                             | Garmin Connect Personal API key.                                                                                   |
| `GARMIN_AUTO_SYNC_STREAMS`         | `true`                                | Auto-queue FIT stream downloads for newly synced activities (caches archives in `splitline_fits`).                 |
| `TRUSTED_PROXIES`                  | _(empty)_                             | Reverse proxy IP/CIDR list or `*` for forwarded HTTPS headers.                                                     |
| `SESSION_SECURE_COOKIE`            | `false`                               | Set to `true` when serving over HTTPS.                                                                             |
| `LOG_CHANNEL`                      | `stderr` _(Docker)_ / `stack` _(dev)_ | Log destination (`stderr` for `docker logs`).                                                                      |
| `DB_DATABASE`                      | `/data/database.sqlite` _(Docker)_    | SQLite database file path.                                                                                         |
| `DB_SQLITE_JOURNAL_MODE`           | `WAL` _(Docker)_                      | SQLite journal mode.                                                                                               |
| `DB_SQLITE_BUSY_TIMEOUT`           | `5000` _(Docker)_                     | SQLite busy timeout in milliseconds.                                                                               |
| `OPEN_METEO_ENABLED`               | `true`                                | Enable historical activity weather lookups.                                                                        |
| `OPEN_METEO_AUTO_SYNC`             | `true`                                | Auto-queue weather lookups for newly synced outdoor activities.                                                    |
| `NOMINATIM_ENABLED`                | `true`                                | Enable reverse geocoding for routes, segments, and activities missing `location_name`.                             |
| `NOMINATIM_AUTO_SYNC`              | `true`                                | Auto-queue reverse geocoding in the background.                                                                    |
| `NOMINATIM_BASE_URL`               | `https://nominatim.openstreetmap.org` | Nominatim endpoint URL (set to a self-hosted instance to lift public rate limits).                                 |
| `NOMINATIM_RATE_LIMIT_MS`          | `1100`                                | Minimum ms between Nominatim requests (enforces >=1000ms on the public OSMF host; `0` for self-hosted).            |
| `NOMINATIM_USER_AGENT`             | _(Splitline/version)_                 | Custom HTTP `User-Agent` header.                                                                                   |
| `NOMINATIM_REFERER`                | _(empty)_                             | Optional HTTP `Referer` header sent to Nominatim.                                                                  |
| `NOMINATIM_EMAIL`                  | _(empty)_                             | Contact email for Nominatim; recommended on the public OSMF endpoint per their usage policy.                       |
| `NOMINATIM_TIMEOUT`                | `15`                                  | HTTP timeout in seconds for Nominatim requests.                                                                    |
| `NOMINATIM_MAX_RETRIES`            | `2`                                   | Max retry attempts for transient connection/5xx errors.                                                            |
| `NOMINATIM_NEGATIVE_CACHE_DAYS`    | `30`                                  | Days to cache unresolved coordinates before retrying.                                                              |
| `NOMINATIM_RETRY_FAILED_DAYS`      | `7`                                   | Days before retrying a failed place lookup.                                                                        |
| `MAPS_ENABLED`                     | `true`                                | Master switch for external map tiles and 3D/hillshade terrain (`false` plots routes & profiles locally only).      |
| `MAP_TILES_ENABLED`                | _(inherits `MAPS_ENABLED`)_           | Enable basemap vector tiles independently of terrain.                                                              |
| `MAP_TERRAIN_ENABLED`              | _(inherits `MAPS_ENABLED`)_           | Enable DEM hillshading and 3D terrain independently of basemap tiles.                                              |
| `MAP_STYLE_LIGHT_URL`              | OpenFreeMap Positron                  | MapLibre light style JSON URL (override for self-hosted vector tiles).                                             |
| `MAP_STYLE_DARK_URL`               | OpenFreeMap Dark                      | MapLibre dark style JSON URL (override for self-hosted vector tiles).                                              |
| `MAP_TERRAIN_TILEJSON_URL`         | Mapterhorn TileJSON                   | Raster-DEM TileJSON URL for hillshade and 3D terrain (override for self-hosted DEM tiles).                         |
| `AUTO_IMPORT_DIR`                  | _(empty)_                             | Drop folder scanned every 5 minutes for `.fit` and `.zip` files (e.g., `/data/imports`).                           |
| `AUTO_IMPORT_MODE`                 | `keep`                                | `keep` (deduplicates via inner-FIT SHA-256) or `archive` (moves to `processed/` or `failed/`).                     |
| `AUTO_IMPORT_MIN_FILE_AGE_SECONDS` | `5`                                   | Minimum file age in seconds before importing; files must also be unchanged across scans, and symlinks are skipped. |

### Purely Offline Mode

To run Splitline with zero outbound network calls (local FIT imports + local route/heatmap/profile plotting), leave `GARMIN_KEY` unset and disable external services in `.env`:

```dotenv
GARMIN_KEY=
OPEN_METEO_ENABLED=false
NOMINATIM_ENABLED=false
MAPS_ENABLED=false
```

## Development

See [CONTRIBUTING.md](CONTRIBUTING.md) for local setup, CI checks, and contribution guidelines.

## Attribution & Disclaimer

- **Weather**: [Open-Meteo.com](https://open-meteo.com/), licensed under [CC BY 4.0](https://creativecommons.org/licenses/by/4.0/).
- **Maps & Terrain**: [OpenFreeMap](https://openfreemap.org/) vector tiles, [OpenStreetMap](https://www.openstreetmap.org/copyright) data, and [Mapterhorn](https://mapterhorn.com/) elevation tiles.
- **Geocoding**: [OpenStreetMap Nominatim](https://nominatim.openstreetmap.org/), place names © OpenStreetMap contributors ([ODbL](https://www.openstreetmap.org/copyright)).
- **Disclaimer**: Splitline is an independent open-source project and is not affiliated with, endorsed by, or sponsored by Garmin Ltd. or its subsidiaries. Garmin and Garmin Connect are trademarks of Garmin Ltd. or its subsidiaries.

## License

Licensed under the [MIT License](LICENSE).
