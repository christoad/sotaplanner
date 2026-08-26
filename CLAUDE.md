# SOTA Planner — Project Guide for Claude

## Pending Work — Ask Chris at Session Start

**Google Maps API — two-key setup (completed 2026-06-01):**
Two separate API keys are used. Both are in the same paid Google Cloud project.

- **Server key** (ends in `s3W5Fo`) — used in `sotaplanner_secrets.php` as `GOOGLE_MAPS_API_KEY`. Application restrictions: **None**. API restrictions: **Geocoding API + Distance Matrix API only**. Never exposed in HTML.
- **Browser key** (ends in `F9jRs`) — stored as `GOOGLE_MAPS_BROWSER_KEY` in `sotaplanner_secrets.php`. Used in HTML `<script>` tags for the Maps JavaScript API. Application restrictions: **HTTP referrers** — six entries required: `*.sotaplanner.com/*`, `sotaplanner.com/*`, `*.ki6cr.com/*`, `ki6cr.com/*`, `*.christopherreddick.com/*`, `christopherreddick.com/*`. (The `*` wildcard only matches subdomains, not the bare domain — both forms needed.) API restrictions: **Maps JavaScript API only**.

**Why two keys:** HTTP referrer restrictions break server-side PHP calls (no Referer header). IP restrictions break browser calls. Two keys is the only way to properly secure both. The server key never appears in HTML; the browser key is referrer-locked so it can only be used from the three site domains.

**TODO — set up Cloudflare CDN in front of DreamHost (queued 2026-08-25):**
Chris wants to actually implement this in a future session — see "Hosting & Scaling — CDN Options" under Staging Environment below for the researched plan (free Cloudflare account, point sotaplanner.com's nameservers at Cloudflare's two nameservers). This is a live DNS/nameserver change affecting the production domain and email routing, so confirm scope with Chris before touching DNS — don't just do it unprompted at session start.

**Track 2 — SOTA API activation history: RESOLVED 2026-08-25:**
Root-caused via a friend's (WZ1EEE) beta-test bug report of "tons of refreshes/requests every few milliseconds" on the dashboard — traced with live Chrome network monitoring, not just code review. Two real bugs in `sota_refresh.php` (the background endpoint `index.php` silently calls per-summit to refresh activation data):
1. **Missing `session_start()`** — every other AJAX endpoint in the codebase calls `session_start()` before `requireLogin()`; this one never did, so `requireLogin()` always saw an empty session and 302-redirected to `login.php`, which then redirected back to `index.php` — meaning every single background refresh silently re-downloaded the entire dashboard HTML page instead of getting JSON. With the dashboard's loop firing 3 concurrent workers with zero delay between iterations across every summit on the dashboard, this produced the request storm WZ1EEE saw. Fixed by adding `session_start();` (matching the pattern in `save_activation_time.php`, `sync_dashboard_activations.php`, etc.), and added a 200ms pacing delay between iterations in `index.php`'s refresh loop as a courtesy to the external SOTA API.
2. **Invalid `$db->rowCount()` call** — `rowCount()` only exists on `PDOStatement`, not the `PDO` connection object; the code chained `$db->prepare(...)->execute(...)` (discarding the statement handle) then called `$db->rowCount()`, which threw an uncaught fatal error any time a real activation match was found. This meant the endpoint had never once completed successfully even when auth wasn't the blocker. Fixed by capturing the statement handle and calling `rowCount()` on it.

Both fixes are deployed to production and verified live (confirmed a real activation record round-tripping correctly with no crash). **Note for future debugging on this host:** DreamHost's PHP OPcache did not auto-invalidate immediately after these file edits were rsynced — stale bytecode kept serving the old behavior for a bit after deploy. If a fix looks deployed but production behavior doesn't change, try hitting a small `opcache_reset()` script (password-gated, delete after running — same disposable-script pattern as `db_migrate.php`) a few times to catch multiple PHP-FPM workers, same as the `db_migrate.php` pattern's "delete after running" note.

Context: VK3ARR (SOTA team) granted an SSO client for identity login. Chris sent a follow-up explaining read-only intent. Track 1 (SSO login) was completed first. Track 2 (activation history) is now working; SOTAWatch alerts (posting, not just reading) is still blocked separately — see "SOTAwatch Write API" section below.

**Radius-based bulk nomination (completed 2026-06-06):**
`nominate.php` has two tabs: "Name / Reference" (existing search) and "Search by Area" (new). The area tab geocodes any location Google Maps recognizes, draws a red circle on a map, and lists every SOTA summit within the radius as a checklist. Selecting summits and clicking "Nominate" runs through the existing bulk nomination flow and redirects to the dashboard. The SOTA cache (`sota_cache.csv.gz`) was rebuilt to include lat/lon in every entry (format: `code|name|norm|points|alt_ft|lat|lon`). The `search_sota_cache_by_radius()` function in `sota_cache_helper.php` uses a bounding-box pre-filter + Haversine formula. The Maps JS API is lazy-loaded only when the area tab is first clicked. Units (miles/km) follow the user's group preference.

**Track 4 — Batch data pre-population (Global GPX Library): COMPLETE as of 2026-06-09**

All 181,126 summits have been checked against SOTAmaps. Steady-state cron jobs are active. No further manual action needed.

**What's built:**
- `global_gpx_tracks` table — one row per imported summit, keyed globally
- `global_gpx_checked` table — one row per summit ever queried against SOTAmaps (tracks_found=0 means nothing found; used to avoid re-querying and schedule retries)
- `gpx_import_lib.php` — shared library with the canonical `import_sotamaps_track()` function used by both the browser importer and the cron
- `batch_gpx_cron.php` — CLI cron for new-summit discovery and no-track retries
- `trailhead_osm_cron.php` — CLI cron for OSM trailhead lookup on newly imported tracks
- **Progress panel** in God Mode → Data Tools tab — shows Checked / With Route / No Route / With Trailhead / Remaining with a progress bar. Total count is cached in `app_settings` key `sota_cache_summit_count` (refreshed once/day from the gz file using a 512-byte buffer — must match the cron's buffer size or counts will diverge).
- **Login page badge** on `login.php` — shows count of summits in `global_gpx_tracks` with `trailhead_lat IS NOT NULL AND trailhead_lon IS NOT NULL` as a live stat in the hero.

**Active cron schedule (steady-state):**
```
5 0 * * *   batch_gpx_cron.php --mode=new --limit=500       # nightly: check any new SOTA summits
30 0 * * 6  batch_gpx_cron.php --mode=retry --limit=10000   # weekly (Sat): retry no-track summits (~4hrs)
0 1 * * *   trailhead_osm_cron.php                          # nightly: fill trailheads for new tracks
```

**Log file location:** `/home/chrisr069/sota_logs/gpx_cron.log` — visible in God Mode → Data Tools → Cron Activity Log. The `/home/chrisr069/logs/` directory is root-owned and not writable; always use `sota_logs/` instead.

**Important lessons learned the hard way:**
- Staging (christopherreddick.com/sotaplanner) shares the production database. The importer blocks itself if run on staging (`HTTP_HOST` check). Always run batch tools on production only.
- GPX files for global tracks live in `gpx_files/global/` on the server. When `from_global_library=1`, never delete the physical file on track removal.
- The `sota_cache_summit_count` cache in `app_settings` must be counted with a 512-byte gz buffer (matching the cron). A 64-byte buffer causes double-counting of long lines and inflates the total.

**OSM Trailhead Lookup:**
- **Browser tool** (`admin_trailhead_osm.php`): interactive start/pause/stop UI. Queries Overpass within 400m of the GPX low-elevation endpoint. Falls back to the GPX endpoint itself. Default 1.5s delay.
- **Cron script** (`trailhead_osm_cron.php`): CLI-only, processes `global_gpx_tracks` rows missing a trailhead (newest-first so recently cron-imported tracks get filled promptly). 100/run at 2s delay.

**Remaining data pre-population idea:**
- **Drive-up summit status from Google My Maps:** A public map exists marking SOTA summits reachable by car (no hiking required). URL: `https://www.google.com/maps/@39.0603443,-99.2805547,3153051m/data=!3m1!1e3!4m2!6m1!1s1JPDeCfGjFoAVlJlXkXvCPxv5-SvDOt4?entry=ttu` — research how to extract the underlying KML from a public Google My Maps layer and import drive-up status into the summits table.

**Track 3 — SOTAwatch write API (post/delete spots and alerts):**
Research complete and tested 2026-05-28. All endpoints and auth headers are fully understood (see "SOTAwatch Write API" section below). `test_sotawatch_write.php` exists on dev for testing. oauth_callback.php updated to store `id_token`. **Blocked on VK3ARR** — our `sotaplanner` client returns HTTP 403 on the write API despite valid tokens. 2026-08-24: re-asked VK3ARR directly on the SOTA Reflector thread (same thread where the SSO callsign claim got fixed — he's responsive there) to explicitly grant write access to the `sotaplanner` client, the same way `sotlas` and `polo` have it. Awaiting his reply. Once granted, re-run the test page to confirm, then build the real UI (spot/alert buttons on summit_detail.php).

**GPX upload API for SOTA Maps — asked, awaiting reply (2026-08-24):**
Checked sotamaps.org directly: the "Import GPX Track" page is a plain web form (file picker + points-reduction dropdown) with no linked API docs, dev page, or GitHub repo anywhere on the site. As far as we can verify, the web form is the only sanctioned way to submit a track today — the only endpoint we know of for SOTA Maps is the undocumented *read* endpoint we already use (`api-db.sota.org.uk/smp/gpx/summit/...`), which its maintainer has called a private API not to be relied on. Asked VK3ARR on the Reflector thread whether a submission API exists or is planned. If one becomes available, it could let users submit routes directly from SOTAplanner instead of the external site — worth revisiting once he replies.

**SSO callsign claim — RESOLVED 2026-08-24:**
The `sotaplanner` Keycloak client's `id_token` was missing verified `Callsign`/`UserID` claims (see "SOTA SSO OAuth" section below for what changed in `oauth_callback.php`). Flagged to VK3ARR on the SOTA Reflector thread; he fixed it same-day ("exported for a different scope" — a client-scope/protocol-mapper issue on his end). Verified live via `SOTA_SSO_DEBUG` mode before deploying the code fix. No further action needed here.

**Visual sophistication backlog (started 2026-08-23):**
Chris asked for design inspiration from anime.js, motion.dev, Kokonut UI, Bklit UI, and Manus.im, wanting to make the site feel more polished/animated without changing the Alpine Precision visual language. Takeaways: motion.dev (Framer Motion) is the animation engine of choice — spring hovers, layout animation, scroll-linked reveals; Kokonut UI shows that pattern applied to ordinary cards/buttons; Bklit UI is a charts/data-viz kit (D3 + Motion) relevant to the Community Growth page; Manus.im is an aesthetic reference for hero gradients and staged scroll reveals rather than everything visible on load.

**Done:** `login.php` hero (logo/tagline/stat pill) and the feature cards / "how it works" steps already fade+rise in via motion.dev, loaded from `https://cdn.jsdelivr.net/npm/motion@11/+esm` with a `<noscript>` and reduced-motion fallback, plus a CDN-load timeout fallback so the page never breaks if the CDN is slow/blocked. 2026-08-23: enlarged the hero logo (280px → 340px, 220px on mobile) and extended the same reveal pattern to the login card — the SSO button and the "no account to create" callout now stagger in via `inView('.login-wrap', ...)` when scrolled into view (see the `<script type="module">` block at the bottom of `login.php` for the pattern to copy elsewhere).

**Still to do (menu — pick one at a time, in this rough priority order):**
1. **Dashboard card/row micro-interactions** (`index.php`) — Kokonut-style hover lift on summit rows/cards, smoother filter-pill and status-badge transitions, animated reordering when sorting.
2. **Community Growth page chart polish** (Bklit-style) — animated draw-in for the growth chart, consistent tooltip styling on the world map, smooth transitions when data updates.
3. **Scroll-reveal on other content pages** — About and Changelog could use the same stagger-fade-on-scroll pattern already proven on `login.php`.
4. **Login hero gradient refinement** (Manus-style) — a more custom/considered gradient and type hierarchy on the hero background, since it's the first-impression page.

When picking one of these up, reuse the existing motion.dev-via-CDN pattern from `login.php` rather than introducing a different animation library or a build-step dependency.

---

## Development Workflow

### Playwright Screenshots
Always save Playwright screenshots to the `playwright/` folder in the project root (e.g. `playwright/my-screenshot.png`). This folder is gitignored and excluded from rsync deploys — never commit screenshots.

### Playwright Login
The staging login page shows a hero section by default. To log in, click the small **"Developer access"** link in the bottom-left corner of the page — this reveals the callsign input. Enter `KI6CR` and click **Go**.

### Branches
**Work happens directly on `main`.** There is no `dev` branch — it was deleted 2026-08-23 (it had gone stale, ~55 commits behind, since everything since the global GPX library work had already been committed straight to `main`). Don't recreate a `dev`/staging-merge workflow unless Chris asks for it; commit and deploy straight from `main`.

### Before Committing a Feature
1. Bump `APP_VERSION` in `config.php`
2. Add a new version block to `changelog.php` describing the new features in plain English (see changelog conventions below)

### Staging Environment
- **URL:** christopherreddick.com/sotaplanner/
- **Server path:** `/home/chrisr069/christopherreddick.com/sotaplanner/`
- **Database:** Same production DB (shared — no schema changes without care)
- **Deploy to staging:**
  ```
  rsync -avz --exclude='.git' --exclude='.claude' --exclude='.playwright-mcp' --exclude='playwright' "/Users/chris/Dropbox/ham - amateur radio/sotaplanner/" dreamhost-sota:/home/chrisr069/christopherreddick.com/sotaplanner/
  ```
- After confirming on staging, commit on `main` and deploy to production as usual.
- **GPX files + SOTA cache** are synced from production → staging daily at 2am via `/home/chrisr069/sync_sites.sh`. No need to manually copy GPX files when testing on staging.

### Hosting & Scaling — CDN Options (researched 2026-08-25)
DreamHost Shared Hosting (what sotaplanner.com runs on) has **no built-in CDN**. If international traffic grows and page-load latency becomes a concern, the path is a free **Cloudflare** setup (DreamHost has a direct partner integration for this): create a free Cloudflare account, then point sotaplanner.com's nameservers at Cloudflare's two assigned nameservers. This gives global edge caching for static assets (CSS/JS/images/SVGs), SSL termination closer to the user, DDoS protection, and basic analytics — DreamHost remains the origin server. Note: since most of SOTAplanner's pages are dynamic PHP (DB-backed dashboard/maps), a CDN mainly speeds up static assets and connection setup, not the PHP/DB round-trip itself — if response time becomes the bottleneck under heavy load, that requires scaling the DreamHost plan or database, not just adding a CDN. Nameserver changes affect live DNS/email routing, so treat this as a change requiring Chris's explicit go-ahead, not something to do unprompted.

---


## What This Site Does

SOTA Planner is a web app for amateur radio operators who participate in **SOTA — Summits On The Air**. SOTA is an amateur radio program where operators ("activators") hike to a designated summit and operate a radio station from the top to earn points. Chasers contact activators from home. Both activators and chasers log contacts; summits have point values based on height and difficulty.

The app's core value proposition is **doorstep-to-doorstep time planning**. Activations require coordinating travel time, hike time, time on summit for radio, and the return trip. Most tools only show hiking distance and elevation — SOTA Planner brings everything together into one total time estimate so an activator can quickly judge whether a given summit fits the time they have available.

**Creator:** Christopher Reddick, KI6CR. Built for himself and the broader SOTA community. Free to use, no signup required (early access uses callsign-only login; full SOTA SSO OAuth is planned once credentials are obtained from the SOTA organization).

---

## Core Concepts

### Summits
A SOTA summit is a designated peak with a unique reference code (e.g. `W7O/NC-001`). Each summit has a points value (1–10), elevation, and geographic coordinates. The app pulls summit data from the SOTA API and SOTLAS. Users nominate summits they're interested in activating and build up research data on each one.

### Dashboards (formerly "Planning Groups")
The primary organizational unit. A dashboard is a named collection of summits, members, and starting addresses. Most users have one dashboard; some have multiple (e.g. separate dashboards for different activation partners or regions). Dashboards are private — only visible to the owner and invited members. The dashboard owner can add members by callsign.

**Terminology note (renamed 2026-07):** The user-facing term is **"Dashboard"** everywhere — nav link is "Manage Dashboards" (`planning_groups.php`), buttons read "Create Dashboard" / "Delete Dashboard" / "Rename Dashboard", etc. Under the hood the table, PHP functions/variables, and session keys still use `planning_group` (`planning_groups` table, `getCurrentPlanningGroup()`, `setCurrentPlanningGroup()`, `$_SESSION['current_planning_group_id']`, `selected_address_group_{id}` setting key) — this was an intentional UI-text-only rename to avoid a DB migration and code-wide refactor. When writing new UI copy, always say "Dashboard"; when writing/reading code, the internal name is still "planning group".

Key dashboard attributes:
- **Units**: metric or imperial (stored per dashboard, affects all distance/elevation display)
- **Addresses**: one or more starting locations (home, trailhead area, etc.) used for Google Maps drive time calculations
- **Selected address**: the currently active address for drive time — stored in `app_settings` as `selected_address_group_{id}`
- **Default dashboard**: users can save a preferred dashboard via cookie (`sota_default_group`) so it loads automatically on login

### Summit Data & Research
Each summit in a group has:
- **Basic SOTA data**: name, region, points, elevation, coordinates (from SOTA API / SOTLAS)
- **Hike data**: trail distance (mi), elevation gain (ft), hike time up/down (min) — entered manually or imported from GPX
- **Travel time**: calculated from the group's selected address via Google Maps Distance Matrix API
- **Total time**: travel + hike up + activation time + hike down + travel back = full day estimate
- **Status**: Nominated, Researched, Planned, Activated — tracks progress through the planning lifecycle
- **Difficulty**: Easy / Moderate / Hard / Very Hard
- **Links**: trail link, SOTLAS link, map link, GPX link

**Terminology note (renamed 2026-08):** The user-facing terms are **"Starting Point"** (not "Trailhead" — could be a trailhead, a parking lot, or a transit stop, e.g. for European activations reached by train/bus) and **"Travel Time"** (not "Drive Time" — the Google Maps "Directions" links no longer force `travelmode=driving`, so Google offers transit/walk/drive/bike). Under the hood, this is still a UI-text-only rename: the `trailhead_lat`/`trailhead_lng` columns, `drive_time_min` column, `calculateDriveTime()` function, and the Distance Matrix API call all keep their original names and still compute *driving* time specifically — there is no real transit-time calculation yet. When writing new UI copy, say "Starting Point" / "Travel Time"; when writing/reading code, the internal name is still "trailhead" / "drive time".

### GPX Track Analysis
Users can upload a recorded GPX track from a past activation. The app parses it to extract real-world stats: total hiking time, activation time (time spent in the activation zone), rest break time, hiking distance, elevation gain/loss, average hiking speed, and summit coordinates. These stats can be used as the authoritative hike time for that summit. The activation zone polygon is overlaid on the map using the activation.zone API.

### Shared Summit Data
When a new group is created, the owner can choose to "adopt" summit research from an existing group. The summit record gets `uses_shared_data = true` and `source_group_id` pointing to the original group. Shared summits inherit trail data, GPX tracks, and notes from the source group, so new groups don't have to re-research summits from scratch.

### Planned Activations
Users can schedule an activation for a specific summit — date, start time, activation time duration, notes. Each planned activation generates a **shareable invitation page** (no login required) that shows a timeline Gantt chart, the summit map with elevation profile, driving directions, cell coverage overlay, and safety/location sharing info. Designed to be shared with non-ham hiking partners and guests.

Planned activations can export a `.ics` calendar file and include a real-time location sharing link field (e.g. a Garmin inReach or Spot link for guests to follow along).

### Activation History
Past activations can be logged against a summit: date, callsigns of participants, notes. These are stored in the `activations` table. Once official SOTA SSO OAuth is live, the plan is to pull real past activation data from the SOTA API for each summit.

---

## Key Pages

| Page | File | Purpose |
|------|------|---------|
| Login | `login.php` | Callsign login (early access) + SOTA SSO (when live). Returning users skip the dashboard picker and land on the main dashboard view. |
| Dashboard (main view) | `index.php` | Main summit list for the active dashboard. Filter by status/difficulty, sort columns, quick-edit activation time, nominate new summits. |
| Summit Detail | `summit_detail.php` | Full detail view for one summit: map, elevation chart, activation zone, GPX upload/analysis, activation timeline, planned activations, notes. |
| Manage Dashboards | `planning_groups.php` | Create/manage dashboards, add members, manage starting addresses, switch active dashboard, set default dashboard. |
| Nominate Summit | `nominate.php` | Add a summit to the current dashboard by SOTA reference — fetches data from SOTA API. |
| Activation Invite | (generated URL) | Public shareable page for a planned activation. No login required. |
| Trail Research | `trail_research.php` | Dedicated view for editing hike distance, gain, trailhead, and trail notes for a summit. |
| User Settings | `user_settings.php` | Per-user preferences — default activation time on summit (minutes). |
| About | `about.php` | Project description and creator info. |
| Changelog | `changelog.php` | Release history. |
| Admin | `admin.php` | Admin tools (restricted). |

---

## External APIs Used

- **Google Maps JavaScript API** — interactive maps on summit detail and invitation pages
- **Google Maps Distance Matrix API** — drive time calculation from starting address to trailhead
- **Google Maps Geocoding API** — converting address strings to lat/lng
- **SOTA API** (`api2.sota.org.uk`) — summit lookup by reference, fetching summit metadata
- **SOTLAS** (`sotlas.com`) — additional trail/summit reference data and links
- **activation.zone API** — terrain-based activation zone polygon for a given summit's coordinates
- **SOTAmaps** (`sotamaps.org`) — community GPX track import

---

## SOTA SSO OAuth

Client credentials granted by VK3ARR (SOTA team). Stored in `sotaplanner_secrets.php` (one level above web root, not in git).

- **Client ID**: `sotaplanner` (the `resource` field in Keycloak terminology)
- **Client Secret**: none provided — configured as a public client (no secret required)
- **Realm**: `SOTA`
- **Auth server**: `https://sso.sota.org.uk/auth/`

Keycloak OIDC endpoints (already hardcoded in `oauth_callback.php`):
- Auth: `https://sso.sota.org.uk/auth/realms/SOTA/protocol/openid-connect/auth`
- Token: `https://sso.sota.org.uk/auth/realms/SOTA/protocol/openid-connect/token`
- UserInfo: `https://sso.sota.org.uk/auth/realms/SOTA/protocol/openid-connect/userinfo`

**SSO is locked to sotaplanner.com only** — the SOTA team has only whitelisted the production redirect URI. Testing SSO via staging (christopherreddick.com/sotaplanner) is not possible.

**JWT token claims — fixed 2026-08-24:** SOTA's Keycloak now includes verified `Callsign` and `UserID` claims (capitalized, e.g. `"Callsign": "KI6CR"`, `"UserID": 6994`) directly in the `id_token`/`access_token` payloads. These were initially missing — VK3ARR's client had them "exported for a different scope" (a Keycloak protocol-mapper/client-scope misconfiguration) until we flagged it on the SOTA Reflector thread; he fixed it same-day. Confirmed via `SOTA_SSO_DEBUG` mode against a live production login. `oauth_callback.php` now reads `Callsign` first, falling back to `preferred_username` only if `Callsign` is absent. Since the claim is server-verified, SSO logins auto-confirm (`callsign_confirmed = 1`) without a manual step. **`callsign_confirm.php` was removed entirely (2026-08-23)** — the Callsign claim is now reliable on every login, so the manual-confirmation fallback page and its redirect in `oauth_callback.php` were deleted rather than kept as dead code. (`SOTA_USERINFO_URL` is still defined but no longer called — kept only for reference.)

The login button on `login.php` is active when `SOTA_CLIENT_ID` is defined. Client secret is optional — omitted from token exchange if `SOTA_CLIENT_SECRET` is not defined.

---


## SOTAwatch Write API (Post/Delete Spots & Alerts)

Fully researched May 2026 by studying the open-source [sotlas-frontend](https://github.com/manuelkasper/sotlas-frontend) project (Manuel Kasper's SOTLAS site), which posts and deletes spots and alerts. All endpoints confirmed working.

### Two different base URLs

| Purpose | Base URL |
|---|---|
| Reading spots/alerts/summits (read-only, no auth) | `https://api2.sota.org.uk/api/` |
| **Writing spots & alerts (requires auth)** | `https://api-db2.sota.org.uk/api/` |

### Authentication for write requests

After SOTA SSO login, the OAuth token exchange returns an `access_token` and `id_token`. Both are required as headers on every write request:

```
Authorization: Bearer {access_token}
id_token: {id_token}
```

Tokens expire — before each write request, refresh using the `refresh_token` if the access token is within 60 seconds of expiry.

**Critical:** We must store the `access_token`, `id_token`, and `refresh_token` after SSO login. Currently `oauth_callback.php` only uses the token to get the callsign, then discards it. To support write API calls, these tokens need to be saved (e.g. in the session or database).

### POST a spot

`POST https://api-db2.sota.org.uk/api/spots`

```json
{
  "callsign": "KI6CR",
  "activatorCallsign": "KI6CR/P",
  "associationCode": "W7O",
  "summitCode": "NC-001",
  "frequency": "14.285",
  "mode": "SSB",
  "type": "NORMAL",
  "comments": "CQ SOTA"
}
```

- `frequency`: MHz as a string
- `mode`: one of `AM`, `CW`, `Data`, `DV`, `FM`, `SSB`
- `type`: `NORMAL`, `QRT` (done for the day), or `TEST`
- When editing an existing spot, also include `"id": {spotId}` and `"userID": {userID}` (without userID you get "User does not own spot!" error)

### DELETE a spot

`DELETE https://api-db2.sota.org.uk/api/spots/{spotId}`

### POST an alert

`POST https://api-db2.sota.org.uk/api/alerts`

```json
{
  "activatingCallsign": "KI6CR/P",
  "associationCode": "W7O",
  "summitCode": "NC-001",
  "dateActivated": "2026-05-28T18:00:00Z",
  "frequency": "14.285-SSB, 7.032-CW",
  "comments": "optional notes",
  "posterCallsign": "KI6CR"
}
```

- `dateActivated`: ISO 8601 UTC datetime string (`YYYY-MM-DDTHH:mm:ssZ`)
- `frequency`: free-text field combining frequency and mode (e.g. `"14.285-SSB"`), max 40 characters
- When editing an existing alert, also include `"id": {alertId}`

### DELETE an alert

`DELETE https://api-db2.sota.org.uk/api/alerts/{alertId}`

Ownership is determined server-side by matching the JWT's user ID to the alert's `userID` field. Users can only delete their own alerts/spots.

### The gate: client ID write permission — CONFIRMED BLOCKED

Tested 2026-05-28 with valid OAuth tokens (access_token + id_token both present, token fresh). The write API returned **HTTP 403 Forbidden** from the Rocket (Rust) backend. This confirms our `sotaplanner` client ID is recognized but not authorized for write operations.

**Write access must be explicitly granted by VK3ARR.** Each app needs a separate write permission grant. SOTLAS uses `sotlas`, Ham2k PoLo uses `polo` — both had to be registered for write access. We need to email VK3ARR and ask them to grant write API access to the `sotaplanner` client.

---


## Auth & Login State

Currently in **early access mode**: any valid callsign (3–10 alphanumeric characters) logs in without a password. Full SOTA SSO OAuth is planned — the `oauth_callback.php` handler exists and is ready, pending official client credentials from the SOTA organization.

Login flow:
1. User enters callsign → session set
2. If returning user with dashboards: go straight to the main dashboard view (last-used dashboard from cookie, or owned dashboard, or first dashboard)
3. If new user (no dashboards): go to planning_groups.php (Manage Dashboards) with welcome banner

Session keys: `sota_callsign`, `sota_login_type`, `current_planning_group_id`

---

## Database Overview

MySQL on DreamHost. All queries use PDO with prepared statements.

Key tables:
- `planning_groups` — groups (id, name, units, owner_callsign)
- `planning_group_members` — join table (planning_group_id, callsign)
- `summits` — one row per summit per group (or shared via source_group_id)
- `addresses` — starting addresses per group
- `activations` — logged past activations per summit
- `gpx_tracks` — uploaded/analyzed GPX files with parsed stats; `from_global_library=1` means the row points to a shared file in `gpx_files/global/`
- `summit_notes` — free-text notes per summit per user
- `users` — callsign, name, home address, lat/lng
- `user_settings` — per-user preferences (default activation time)
- `app_settings` — key-value store for per-group settings (e.g. selected address ID); also stores `sota_cache_summit_count` (total SOTA summits from gz cache, refreshed daily) used by the God Mode progress panel
- `global_gpx_tracks` — global GPX library; one row per `sota_ref` that has an imported community route; keyed globally, not per planning group; columns include `trailhead_lat` / `trailhead_lon` (note: `_lon` not `_lng`)
- `global_gpx_checked` — audit log of every summit ever queried against SOTAmaps; `tracks_found=0` means nothing was available; used by cron to avoid redundant re-queries and to schedule periodic retries

---

## Data Gathering Architecture

The app pre-populates summit data (GPX routes, trailheads, elevation/distance stats) from external community sources so that when a user nominates a summit, it already has route and planning data ready. This is a multi-layer pipeline with browser-based tools for the initial load and background cron jobs for ongoing maintenance.

### Data Sources

| Source | What it provides | API |
|---|---|---|
| **SOTA Mapping Project** | Community-submitted GPX routes | `https://api-db.sota.org.uk/smp/gpx/summit/ASSOC/REF` |
| **OpenStreetMap / Overpass** | Tagged trailheads and parking areas | `https://overpass-api.de/api/interpreter` |

For SOTAmaps, each summit may have multiple submitted tracks — the shortest-distance track is selected (best for planning; avoids long wandering routes). For OSM, the GPX track's low-elevation endpoint is used as the search origin, queried within 400m. Dedicated trailhead tags (`highway=trailhead`, `tourism=trailhead`) are preferred over generic parking. If nothing qualifies, the GPX low-elevation endpoint itself becomes the trailhead coordinate.

### Key Files

| File | Type | Purpose |
|---|---|---|
| `gpx_import_lib.php` | Shared library | The single canonical `import_sotamaps_track($db, $sota_ref)` function. Fetches from SOTAmaps, saves GPX file, analyzes, inserts into `global_gpx_tracks`, backfills `gpx_tracks` for any existing nominated summits, and records the check result in `global_gpx_checked`. Used by both the browser tool and the cron. |
| `admin_batch_gpx.php` | Browser tool (admin) | Association-by-association import UI with live log, progress bar, pause/stop, and completion chime. Use for the one-time initial full import. Writes to `global_gpx_checked` via the shared lib. |
| `admin_trailhead_osm.php` | Browser tool (admin) | Interactive OSM trailhead lookup — start/pause/stop UI. Processes all `global_gpx_tracks` rows missing a trailhead. Run once after the initial GPX import. Default 1.5s delay. |
| `batch_gpx_cron.php` | CLI cron | Two modes: `--mode=new` (summits in SOTA cache not yet in `global_gpx_checked`) and `--mode=retry` (summits with `tracks_found=0` older than 30 days). Default `--limit=500`, `--delay=1500`. |
| `trailhead_osm_cron.php` | CLI cron | Queries OSM for any `global_gpx_tracks` rows that are missing a trailhead. Processes newest-first (so recently cron-imported tracks get trailheads promptly). Default `--limit=100`, `--delay=2000`. |

### How `global_gpx_checked` Works

This table is the key to efficient ongoing cron operation:
- Every time a summit is queried against SOTAmaps — whether a track was found or not — a row is written with `last_checked = NOW()` and `tracks_found = N` (0 if nothing).
- The `--mode=new` cron finds summits in the SOTA cache with **no row in `global_gpx_checked` at all** — these are genuinely new summits added to SOTA since the last scan.
- The `--mode=retry` cron finds rows where `tracks_found = 0` and `last_checked < 30 days ago` — retrying in case community tracks have since been uploaded.
- Summits that have been imported never appear in either queue because they have a row in `global_gpx_checked` with `tracks_found > 0`.
- The table was backfilled from `global_gpx_tracks` when first created, so previously imported summits won't be re-processed.

### Cron Log Viewer

The log is visible without SSH in **God Mode → Data Tools tab → Cron Activity Log**. Shows the last 180 lines color-coded: green = success, red = error, yellow = skip/capped, gray = no-tracks/separator. Auto-scrolls to most recent entry. Includes a "Last run" summary line parsed from the log.

### Rate Limiting

| Tool | Delay |
|---|---|
| Browser GPX importer | 500ms default (adjustable in UI) |
| Browser trailhead tool | 1500ms default (adjustable in UI) |
| GPX cron | 1500ms (`--delay=1500`) |
| Trailhead cron | 2000ms (`--delay=2000`) |

Overpass API is a shared public service — never reduce trailhead delays below 1500ms.

### What Happens at Nomination Time

When a user nominates a summit (`nominate.php`), the code checks `global_gpx_tracks` for a matching `sota_ref`. If found:
- A row is inserted into `gpx_tracks` with `from_global_library=1`, pointing to the shared file in `gpx_files/global/`
- `summits.hike_distance_mi` and `hike_elevation_gain_ft` are pre-filled from the global track stats
- `summits.trailhead_lat/lng` is pre-filled if the global track has a trailhead stored
- Summit detail shows "Community route from SOTA Mapping Project" attribution
- Deleting the user's GPX track does **not** delete the physical file when `from_global_library=1`

---

## Design System: Alpine Precision

All pages use the **Alpine Precision** design system. When adding new pages or UI, match this exactly — do not invent new colors, fonts, or patterns.

### Font

```html
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;1,9..40,400&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
```

- **Body/UI font**: `'DM Sans', system-ui, sans-serif`
- **Monospace**: `'DM Mono', 'Courier New', monospace` (used for callsigns, code)

### CSS Variables (paste into every new page's `<style>`)

```css
:root {
  --bg:            #F7F6F3;
  --bg-2:          #EFEDE8;
  --bg-3:          #E5E2DA;
  --ink:           #1C1B19;
  --ink-2:         #4A4844;
  --ink-3:         #8C8A86;
  --ink-4:         #B8B5B0;
  --accent:        oklch(52% 0.13 50);
  --accent-2:      oklch(44% 0.13 50);
  --accent-bg:     oklch(96% 0.04 65);
  --accent-border: oklch(84% 0.08 65);
  --green:         oklch(52% 0.13 155);
  --green-bg:      oklch(95% 0.04 155);
  --orange:        oklch(62% 0.14 58);
  --orange-bg:     oklch(96% 0.05 58);
  --red:           oklch(52% 0.16 22);
  --red-bg:        oklch(96% 0.04 22);
  --blue:          oklch(52% 0.12 240);
  --blue-bg:       oklch(95% 0.04 240);
  --gray-badge:    oklch(55% 0.02 200);
  --gray-bg:       oklch(93% 0.01 200);
  --surface:       #FFFFFF;
  --border:        #E5E2DA;
  --border-2:      #D4D0C8;
  --font-sans:     'DM Sans', system-ui, sans-serif;
  --font-mono:     'DM Mono', 'Courier New', monospace;
  --r-sm: 4px; --r-md: 8px; --r-lg: 12px; --r-xl: 16px;
  --sp-1: 0.25rem; --sp-2: 0.5rem; --sp-3: 0.75rem; --sp-4: 1rem;
  --sp-5: 1.25rem; --sp-6: 1.5rem; --sp-8: 2rem; --sp-10: 2.5rem;
  --sp-12: 3rem; --sp-16: 4rem;
  --shadow-sm: 0 1px 3px rgba(28,27,25,0.07), 0 1px 2px rgba(28,27,25,0.05);
  --shadow-md: 0 4px 12px rgba(28,27,25,0.08), 0 2px 4px rgba(28,27,25,0.05);
  --shadow-lg: 0 8px 24px rgba(28,27,25,0.10), 0 4px 8px rgba(28,27,25,0.06);
}
```

### Body baseline

```css
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html { font-size: 16px; -webkit-font-smoothing: antialiased; }

body {
  font-family: var(--font-sans);
  background: var(--bg);
  color: var(--ink);
  line-height: 1.5;
  min-height: 100vh;
}
```

---

## Topbar (standard nav, all logged-in pages)

Every logged-in page has a sticky 56px topbar. Use this HTML/CSS pattern exactly:

```css
.topbar {
  background: var(--surface);
  border-bottom: 1px solid var(--border);
  height: 56px;
  display: flex;
  align-items: center;
  padding: 0 var(--sp-8);
  gap: var(--sp-4);
  position: sticky;
  top: 0;
  z-index: 100;
}
.topbar-logo {
  display: flex; align-items: center; gap: var(--sp-3);
  text-decoration: none; color: var(--ink);
  font-weight: 600; font-size: 0.95rem; letter-spacing: -0.01em;
  flex-shrink: 0;
}
.topbar-logo:hover { text-decoration: none; color: var(--ink); }
.topbar-logo .logo-mark {
  width: 32px; height: 32px;
  display: flex; align-items: center; justify-content: center;
  flex-shrink: 0;
}
.topbar-divider { width: 1px; height: 20px; background: var(--border); flex-shrink: 0; }
.topbar-nav { display: flex; align-items: center; gap: var(--sp-1); }
.topbar-nav a {
  color: var(--ink-3); font-size: 0.875rem; font-weight: 500;
  padding: var(--sp-2) var(--sp-3); border-radius: var(--r-sm);
  transition: color 0.15s, background 0.15s;
  text-decoration: none; white-space: nowrap;
}
.topbar-nav a:hover { color: var(--ink); background: var(--bg-2); }
.topbar-right {
  display: flex; align-items: center; gap: var(--sp-3);
  margin-left: auto; flex-shrink: 0;
}
```

```html
<nav class="topbar">
    <a href="index.php" class="topbar-logo">
        <span class="logo-mark">
            <img src="sota-planner-logo.svg" width="32" height="32" alt="">
        </span>
        <span>SOTAplanner</span>
    </a>
    <div class="topbar-divider"></div>
    <div class="topbar-nav">
        <a href="index.php">Dashboard</a>
        <a href="planning_groups.php">Manage Dashboards</a>
    </div>
    <div class="topbar-right">
        <!-- user chip goes here — see below -->
    </div>
</nav>
```

### User chip (callsign + logout dropdown, topbar-right)

```css
.user-chip {
  position: relative;
  display: flex; align-items: center; gap: 0.35rem;
  cursor: pointer; padding: 0.25rem 0.6rem;
  border-radius: var(--r-sm);
  font-size: 0.8rem; font-weight: 600; color: var(--ink-2);
  border: 1px solid var(--border); background: var(--bg);
  user-select: none; white-space: nowrap;
}
.user-chip:hover { background: var(--bg-2); }
.user-chip-chevron { transition: transform 0.15s; flex-shrink: 0; }
.user-chip.open .user-chip-chevron { transform: rotate(180deg); }
.user-dropdown {
  display: none;
  position: absolute; top: calc(100% + 6px); right: 0;
  background: #fff; border: 1px solid var(--border);
  border-radius: var(--r-sm); box-shadow: 0 4px 16px rgba(0,0,0,0.1);
  min-width: 130px; overflow: hidden; z-index: 200;
}
.user-chip.open .user-dropdown { display: block; }
.user-dropdown a {
  display: block; padding: 0.6rem 1rem;
  font-size: 0.82rem; font-weight: 500; color: var(--ink-2);
  text-decoration: none;
}
.user-dropdown a:hover { background: var(--bg-2); color: var(--ink); }
```

```html
<div class="user-chip" onclick="this.classList.toggle('open')" id="userChip">
    <span><?= htmlspecialchars($_SESSION['sota_callsign'] ?? '') ?></span>
    <svg class="user-chip-chevron" width="10" height="6" viewBox="0 0 10 6" fill="none">
        <path d="M1 1l4 4 4-4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
    </svg>
    <div class="user-dropdown">
        <a href="logout.php">Sign Out</a>
    </div>
</div>
<script>
document.addEventListener('click', function(e) {
    var chip = document.getElementById('userChip');
    if (chip && !chip.contains(e.target)) chip.classList.remove('open');
});
</script>
```

---

## Buttons

```css
.btn {
  display: inline-flex; align-items: center; justify-content: center;
  gap: var(--sp-2); padding: 0 var(--sp-4); height: 36px;
  border-radius: var(--r-md); font-family: var(--font-sans);
  font-size: 0.875rem; font-weight: 500; cursor: pointer; border: none;
  transition: background 0.15s, box-shadow 0.15s, transform 0.1s;
  text-decoration: none; white-space: nowrap; line-height: 1;
}
.btn:hover { text-decoration: none; }
.btn:active { transform: scale(0.98); }
.btn-primary { background: var(--ink); color: #fff; }
.btn-primary:hover { background: var(--ink-2); color: #fff; }
.btn-ghost {
  background: transparent; color: var(--ink-2);
  border: 1px solid var(--border);
}
.btn-ghost:hover { background: var(--bg-2); color: var(--ink); }
.btn-sm { height: 30px; padding: 0 var(--sp-3); font-size: 0.8rem; }
```

**Accent/amber button** (used on login page submit, prominent CTAs):
```css
background: linear-gradient(135deg, #E6B84A 0%, #D4A574 100%);
color: white; font-weight: 700;
```

---

## Cards / Panels

```css
/* Standard white card */
.card {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--r-lg);
  padding: 1.5rem;
  box-shadow: var(--shadow-sm);
}

/* Accent-tinted card (e.g. current version highlight) */
.card-accent {
  background: var(--accent-bg);
  border: 1px solid var(--accent-border);
  border-radius: var(--r-lg);
  padding: 1.5rem;
}
```

---

## Tags / Badges

Used in changelog, status indicators, etc.

```css
.tag {
  display: inline-block; font-size: 0.65rem; font-weight: 600;
  border-radius: var(--r-sm); padding: 0.1rem 0.4rem;
  text-transform: uppercase; letter-spacing: 0.05em;
  white-space: nowrap;
}
.tag-new     { background: var(--green-bg);  color: var(--green); }
.tag-fix     { background: var(--red-bg);    color: var(--red); }
.tag-improve { background: var(--blue-bg);   color: var(--blue); }
.tag-accent  { background: var(--accent-bg); color: var(--accent); }
```

Status badge (rounded pill, white text):
```css
.badge {
  display: inline-block; font-size: 0.68rem; font-weight: 600;
  border-radius: 20px; padding: 0.15rem 0.55rem;
  text-transform: uppercase; letter-spacing: 0.05em; color: #fff;
}
/* Colors: var(--green), var(--red), var(--orange), var(--accent) */
```

---

## Messages / Alerts

```css
.msg {
  display: flex; align-items: center; justify-content: space-between;
  gap: var(--sp-4); padding: var(--sp-3) var(--sp-4);
  border-radius: var(--r-md); font-size: 0.875rem; font-weight: 500;
  margin-bottom: var(--sp-4);
}
.msg-info { background: var(--accent-bg); color: var(--accent-2); border: 1px solid var(--accent-border); }
.msg-success { background: var(--green-bg); color: var(--green); border: 1px solid oklch(85% 0.07 155); }
.msg-error { background: var(--red-bg); color: var(--red); border: 1px solid oklch(85% 0.08 22); }
```

---

## Filter Pills

```css
.filter-pill {
  display: inline-flex; align-items: center; height: 28px;
  padding: 0 var(--sp-3); border-radius: 100px; font-size: 0.775rem;
  font-weight: 500; background: var(--surface); border: 1px solid var(--border-2);
  color: var(--ink-2); cursor: pointer; transition: all 0.12s;
  font-family: var(--font-sans); white-space: nowrap;
}
.filter-pill:hover { border-color: var(--accent-border); color: var(--ink); background: var(--accent-bg); }
.filter-pill.active { background: var(--ink); border-color: var(--ink); color: #fff; }
```

---

## Toolbar

```css
.toolbar {
  display: flex; align-items: center; gap: var(--sp-3); flex-wrap: wrap;
  padding: var(--sp-3) var(--sp-4); background: var(--surface);
  border: 1px solid var(--border); border-radius: var(--r-lg);
  margin-bottom: var(--sp-3);
}
.toolbar-label {
  font-size: 0.75rem; font-weight: 600; color: var(--ink-3);
  text-transform: uppercase; letter-spacing: 0.07em; white-space: nowrap;
}
.toolbar-sep { width: 1px; height: 16px; background: var(--border-2); flex-shrink: 0; }
.toolbar-right { margin-left: auto; display: flex; align-items: center; gap: var(--sp-2); }
```

---

## Form inputs

```css
.form-input {
  width: 100%; padding: 0.6rem 0.75rem;
  border: 1px solid var(--border); border-radius: var(--r-md);
  font-family: var(--font-sans); font-size: 0.875rem; color: var(--ink);
  background: var(--surface); transition: border-color 0.15s; outline: none;
}
.form-input:focus { border-color: var(--accent); }
.form-label {
  display: block; font-size: 0.75rem; font-weight: 600;
  color: var(--ink-3); margin-bottom: 0.35rem;
  text-transform: uppercase; letter-spacing: 0.05em;
}
.form-hint { font-size: 0.775rem; color: var(--ink-3); margin-top: 0.3rem; line-height: 1.4; }
```

---

## Logo

- **`sota-planner-logo.svg`** — the mountain/peak mark, used at 32×32 in topbars and at larger sizes elsewhere
- **`sota-planner-logo-font.svg`** — the full logotype with "SOTA Planner" text, used at 280×280 on the login hero

---

## Page layout

```css
/* Standard content page (max 760px for text-heavy pages like changelog/about) */
.page { padding: var(--sp-8); max-width: 760px; margin: 0 auto; }

/* Wide dashboard layout */
.page { padding: var(--sp-8); max-width: 1400px; margin: 0 auto; }
```

---

## Footer

```html
<footer style="text-align:center; padding:1.5rem 1rem; color:var(--ink-4); font-size:0.78rem;">
    SOTA Planner &nbsp;·&nbsp;
    <a href="changelog.php" style="color:var(--ink-4); text-decoration:none;">v<?= APP_VERSION ?></a>
    &nbsp;·&nbsp;
    <a href="https://sotaplanner.com" style="color:var(--ink-4); text-decoration:none;">sotaplanner.com</a>
</footer>
```

---

## PHP session / auth patterns

- `requireLogin()` — call at top of any protected page (defined in config.php); redirects to login.php if not logged in
- `$_SESSION['sota_callsign']` — the logged-in callsign (uppercase)
- `$_SESSION['sota_login_type']` — `'early_access'` or `'sota_oauth'`
- `$_SESSION['current_planning_group_id']` — active planning group ID
- `getCurrentCallsign()` — helper that returns session callsign
- `getCurrentPlanningGroup($db)` — returns current group row or null
- `setCurrentPlanningGroup($id)` — sets session group
- `getDbConnection()` — returns PDO instance

---

## Changelog conventions

The changelog is **user-facing and feature-focused**. Keep it high-level and readable by a non-developer.

**What to include:**
- New features and meaningful UX improvements worth calling out to users

**What to omit:**
- Bug fixes, PHP errors, internal refactors, cosmetic tweaks, technical debt cleanup — none of this belongs in the public changelog

**Grouped by month (changed 2026-08):** one `version-block` per calendar month, not per version bump — otherwise the page grows a new header/date block for every small release. When shipping a feature in a month that already has a block, append a bullet to that month's `<ul>` and bump the `version-number` shown in its header to the new `APP_VERSION`; only start a new block when the calendar month changes. The "Current" badge always lives on the latest (top) block.

**Format per version block:** version number (the latest reached that month), month+year date, "Current" badge on the latest only, one-sentence description summarizing the month's theme, then a plain-English bullet list (no tags, no technical jargon) with the newest change first. Each bullet should describe what the user can now *do* or *see*, not what changed in the code.

## Version bumping

Only bump `APP_VERSION` in `config.php` (and add a changelog entry) when the commit contains a new feature or a meaningful UX improvement. **Do not bump the version for cosmetic changes, design polish, dead-code removal, or internal refactors.** Those ship silently under the current version number.
