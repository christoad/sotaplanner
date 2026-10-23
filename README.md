# SOTA Planner

A web application for planning [Summits on the Air (SOTA)](https://www.sota.org.uk/) activations. It brings travel time, hiking time, time on the summit and the return trip together into one doorstep-to-doorstep estimate, so an activator can quickly judge whether a summit fits the time they have.

Live at **[sotaplanner.com](https://sotaplanner.com)**. Free to use, and sign-in is through SOTA's own single sign-on (no separate account to create).

Created by Christopher Reddick, KI6CR.

---

## Features

**Planning**
- **Dashboards:** a named collection of summits, members and starting addresses. Private to the owner and invited members. Units (miles or km) are set per dashboard.
- **Summit nomination:** search the full SOTA summit list by name or reference, or use "Search by Area" to pick every summit within a radius of any place Google Maps recognizes.
- **Research and status:** trail distance, elevation gain, hike times, difficulty, notes and links, with each summit moving through Nominated, Researched, Planned and Activated.
- **Total time estimate:** travel (via Google Maps) plus hike up, activation, hike down and travel back.
- **Winter bonus points:** an orange "+N" badge on summits that earn SOTA winter bonus points.
- **Weather forecast:** summit forecast on the summit detail and invitation pages.

**Routes and GPX**
- **GPX analysis:** upload a recorded track and get hiking time, activation time, rest breaks, distance, elevation gain and loss, and average speed.
- **Community route library:** routes from the SOTA Mapping Project are pre-loaded for the vast majority of summits worldwide, so a newly nominated summit often already has a route, stats and a starting point.
- **Activation zone overlay:** high-precision boundaries from SOTLAS, with the activation.zone API as a fallback.
- **Starting points:** pre-filled from OpenStreetMap trailhead data where available.

**Multi-Activate**
- Plan several summits in one outing, with drive legs between them, combined time totals and a PDF export.
- Alternate hike start points for summits that share a trail, so the tool doesn't charge a full hike down and back up between them.
- Shareable read-only route links. Visitors can import the route into their own dashboard through SOTA sign-in.

**Sharing**
- **Planned activations:** schedule an activation with date, start time and notes, and export a `.ics` calendar file.
- **Activation invitation:** a public page (no login) with a timeline, map, elevation profile, directions, cell coverage overlay, weather and a location-sharing link, designed for non-ham hiking partners.
- **Activation history:** past activations logged per summit, with official history pulled from SOTA.

---

## Tech Stack

- **Backend:** PHP 8 with PDO (prepared statements everywhere)
- **Database:** MySQL
- **Frontend:** server-rendered PHP, vanilla JavaScript, inline CSS using the "Alpine Precision" design system (DM Sans and DM Mono). [motion.dev](https://motion.dev) is loaded from a CDN for the login-page animations.
- **Maps:** Google Maps JavaScript API (browser key, referrer-locked)
- **PDF export:** FPDF (bundled in `lib/`)
- **Hosting:** DreamHost shared hosting

**External services**

| Service | Used for |
|---|---|
| SOTA single sign-on (Keycloak OIDC) | Login |
| SOTA summit list CSV (`storage.sota.org.uk`) | Summit database, including winter bonus points |
| SOTA Mapping Project (`sotamaps.org`) | Community GPX routes |
| SOTLAS | Activation zone boundaries and trail data |
| activation.zone | Fallback activation zone polygons |
| Google Maps (Geocoding, Distance Matrix, JS API) | Addresses, travel time, maps |
| OpenStreetMap / Overpass | Trailhead lookup |
| Open-Meteo | Weather forecast |

> **A note on the SOTA data API:** SOTA's terms for `api2.sota.org.uk` prohibit AI-generated software from connecting without prior approval. Contributors should not add code that calls the SOTA API directly without confirming approval first.

---

## Project Structure

The app is a flat set of PHP files in the project root. Shared logic lives in `config.php` and the `*_lib.php` files.

### Pages (what users see)

| File | Purpose |
|---|---|
| `login.php`, `oauth_callback.php`, `logout.php` | SOTA SSO sign-in and sign-out |
| `onboarding.php` | First-run setup for new users and imported routes |
| `index.php` | Main dashboard: summit list, filters, sorting, quick edits |
| `nominate.php` | Add summits by name or reference, or by area |
| `summit_detail.php` | Full summit view: map, elevation chart, activation zone, GPX, planned activations, notes |
| `trail_research.php` | Edit hike distance, gain, starting point and trail notes |
| `planning_groups.php` | Manage dashboards, members and starting addresses |
| `multi_activate.php` | Multi-summit route planner (editable for members, read-only when shared) |
| `activation_invite.php` | Public activation invitation page |
| `user_settings.php` | Per-user preferences |
| `about.php`, `changelog.php` | Project info and monthly release notes |

### Endpoints (called by the pages in the background)

| File | Purpose |
|---|---|
| `sota_refresh.php`, `sync_dashboard_activations.php` | Refresh activation history from SOTA |
| `save_activation_time.php`, `toggle_multi_expand.php`, `vote_feature.php` | Small AJAX saves |
| `drive_time_refresh.php` | Recalculate travel time |
| `load_gpx.php`, `gpx_fetch.php`, `summit_gpx.php`, `import_sotamaps_gpx.php` | GPX upload, analysis, fetch and import |
| `activation_zone.php`, `activation_ics.php`, `summit_weather.php` | Activation zone, calendar file, weather |
| `multi_alt_start_save.php`, `multi_add_to_dashboard.php`, `multi_activate_pdf.php` | Multi-Activate saves, import and PDF |

### Libraries

| File | Purpose |
|---|---|
| `config.php` | Database connection, auth helpers, unit conversion, GPX analysis, activation zone lookup |
| `sota_cache_helper.php` | Summit search over the SOTA cache and index |
| `gpx_import_lib.php` | The single canonical SOTA Maps GPX importer |
| `multi_alt_lib.php`, `multi_import_lib.php` | Multi-Activate alternate starts and route import |
| `weather_lib.php`, `pdf_map_lib.php` | Weather forecasts and PDF map rendering |

### Background jobs (cron, run on production only)

| File | Schedule | Purpose |
|---|---|---|
| `batch_gpx_cron.php` | Nightly, plus weekly retry | Find community GPX routes for new summits and retry ones with none |
| `trailhead_osm_cron.php` | Nightly | Fill in OSM trailheads for newly imported routes |
| `activation_zone_upgrade_cron.php` | Weekly | Upgrade fallback activation zones to SOTLAS boundaries |

### Admin tools (restricted)

`admin.php`, `god_mode.php`, `admin_batch_gpx.php`, `admin_gpx_library.php`, `admin_gpx_map.php`, `admin_trailhead_osm.php`, `rebuild_sota_cache.php`, `bulk_delete_summits.php`, and the map data feeds `api_gpx_map_data.php` and `api_trail_data_map.php`.

### Other

- `db_migrate.php`: idempotent, password-gated schema migration (see below)
- `lib/`: bundled FPDF and fonts
- `research/`: design notes
- `tests/`: manual test scripts
- `gpx_files/`: uploaded and community GPX files (not tracked in git)

---

## How the Data Works

**Summit database.** `rebuild_sota_cache.php` downloads SOTA's official summit list and builds `sota_cache.csv.gz`, which is the source of truth. Two generated copies speed up searching: an indexed `sota_summit_index` table and a plain-text `sota_cache_norm.txt`. Both are rebuilt automatically and are never edited by hand.

**Community route library.** Every summit is checked once against the SOTA Mapping Project. The shortest community track found is saved to a global library (`global_gpx_tracks`), and every check is logged in `global_gpx_checked` so the nightly cron only looks at new summits and the weekly cron retries ones that had nothing. When a user nominates a summit that has a library route, the route, distance, elevation gain and starting point are pre-filled, and the shared file is never deleted if the user removes their copy.

**Activation zones.** `get_activation_zone_from_api()` in `config.php` tries SOTLAS first and falls back to activation.zone. Results are cached permanently in `activation_zone_cache`.

**Naming.** The interface says "Dashboard", "Starting Point" and "Travel Time". The code and database still use the original names (`planning_groups`, `trailhead_lat`, `drive_time_min`). That was an intentional text-only rename.

---

## Setup

1. **Clone the repo** into your web root.
2. **Create `sotaplanner_secrets.php`** one directory above the web root (never committed):
   ```php
   <?php
   define('DB_HOST', 'your-db-host');
   define('DB_NAME', 'sotaplanner');
   define('DB_USER', 'your-db-user');
   define('DB_PASS', 'your-db-password');
   define('GOOGLE_MAPS_API_KEY', 'your-server-key');          // Geocoding + Distance Matrix, never put in HTML
   define('GOOGLE_MAPS_BROWSER_KEY', 'your-browser-key');     // Maps JavaScript API, HTTP-referrer restricted
   define('SOTA_CLIENT_ID', 'your-sso-client-id');            // enables the SOTA SSO login button
   ```
3. **Create the database schema.** The schema file is not included in the repo; contact the repo owner.
4. **Create the `gpx_files/` directory** (with a `global/` subfolder) and make it writable by the web server.
5. **Build the summit cache** by running `rebuild_sota_cache.php` once.

SOTA SSO only works from the production domain, which is the only redirect URI SOTA has approved. On development and staging, a "Developer access" link on the login page signs in by callsign instead. That link is removed on production.

---

## Deployment

- **Branches:** day-to-day work happens on `dev`. `main` is exactly what is live on production.
- **Staging:** `dev` is deployed with rsync to a staging copy of the site for testing.
- **Production:** when a change is approved, `dev` is fast-forward merged into `main` and only the changed files are rsynced to production.
- **Shared database:** staging and production use the same database, so schema changes go live the moment they run. Migrations must be additive and backward compatible (new tables or nullable columns, never renames or drops) and run before the code that needs them.
- **Schema changes** go through `db_migrate.php`: password-gated, idempotent (`SHOW COLUMNS`, `CREATE TABLE IF NOT EXISTS`), prints success or skip for each step, and is deleted from the server after it runs.
- **Releases:** versioning is monthly (`APP_VERSION` in `config.php` is `YYYY.MM`), and the public changelog has one block per month.

---

## License

Personal/private project. Not currently open for public contributions.
