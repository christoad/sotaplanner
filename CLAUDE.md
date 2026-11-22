# SOTA Planner: Project Guide for Claude

## Roadmap (single list, created 2026-11-15)

The one place to see what's planned. Keep it short: one line per item, details live in the notes under "Pending Work" below (or the memory file named). When something ships, starts, gets blocked or is dropped, move or delete its line here in the same session. When Chris asks "what's on the roadmap?", answer from this list.

**In progress**
- BRouter hiking path checker (branch `brouter-paths`). Testing phase: comparison runs, then cache table, hike time and gap filling. Notes: "BRouter" sections below.
- BRouter trail discovery (started 2026-11-15, builds on the BRouter work). God Mode only while we test on sample summits. Goal: find trails and starting points for every summit without hike data, run it on all of them, and switch it on publicly the day Chris presents SOTAplanner at Four Days in May (May 2027). Until then nothing discovered may be visible to the public. Notes: "Trail discovery" below.

**Waiting on someone else**
- Summit to Summit (S2S) line-of-sight tool. Needs Gabriel AJ6X's agreement on data use and credit (his license: attribution, noncommercial), plus a microwave frequency list from Chris's contacts. Notes: "S2S (summit-to-summit) line-of-sight mode".
- Post spots and alerts to SOTAwatch. Waiting on VK3ARR to grant write access. Notes: "Track 3" and "SOTAwatch Write API".
- Submit GPX tracks to SOTA Maps from SOTAplanner. Waiting on VK3ARR / SOTA's Maps overhaul. Notes: "GPX upload API" and "SOTA Maps overhaul".
- Smarter GPX crons driven by SOTA's summit CSV. Waiting on Josh WU7H's file. Notes: "SOTA Maps overhaul and summit CSV".

**Ready to start (nothing blocking)**
- GPX editor modeled on GPX.Studio and Gaia GPS. First step: research those tools. Will use the parking and trailhead list from trail discovery. Notes: "In-site GPX editor".
- Site-wide search bar. Scope to agree with Chris first. Notes: "Queued projects (added 2026-10-28)".
- Accessibility and cookie notice check (find out what's required before adding anything). Same notes.
- Return trip and alternate destination pulldown on the summit page. Notes: `research/public-summit-pages.md`.
- Visual polish, in order: dashboard hover effects, Community Growth chart animation, scroll reveals on About and Changelog, login hero gradient. Notes: "Visual sophistication backlog".
- Icon and graphics redesign. Scope to agree with Chris first. Notes: "Queued projects (added 2026-11-16)".
- Map of GPX library coverage. Notes: memory `project_gpx_coverage_map`.
- RFI layer follow-ups (the layer shipped 2026-11-20; public `/s/` page shipped 2026-11-21): Multi-Activate PDF, click-for-value popup on the heat map. Alaska and Hawaii need KM6AM's grid to cover them first. Notes: "RFI layer on the summit map".

**Ideas, not scheduled**
- Cloudflare CDN in front of DreamHost (live DNS change, needs Chris's go-ahead). Notes: "set up Cloudflare CDN".
- Mapterhorn terrain tiles (elevation, activation zones, hillshade). Notes: "Mapterhorn terrain tiles".
- Drive-up summit status from the public Google My Maps layer. Notes: "Remaining data pre-population idea".
- Email "invited, not signed in" users (needs a way to get their email first). Notes: "email invited, not signed in users".

## Pending Work: Ask Chris at Session Start

**Open branches (cleanup finished 2026-11-15):** only `main` and `brouter-paths` (active project, worktree `.claude/worktrees/brouter-paths`) exist; `dev` and the old merged branches are gone from GitHub and the Mac.

**Advisory Notices (shipped to production 2026-11-14):** community banners at the top of `summit_detail.php` ("Advisory Notice" was chosen because "alert" and "spot" already mean something in SOTA). Any signed-in user posts one from the form at the top of the Community Notes section; levels are Do not attempt (red), Caution (amber), Tip (blue). Keyed by `sota_ref` in table `summit_advisories` (already created in the shared DB), so every dashboard sees them. Only the poster or God Mode can edit (inline form in the list, `advisory_update()`, logged to the summit change history as "Advisory Notice edited" with old and new text) or remove one; removal is a soft delete (`removed_at`, `removed_by`). Logic, banner CSS and banner markup are in `summit_advisory_lib.php` (`advisory_banner_css()`, `advisory_render_banners()`). The top banners are read only; Remove buttons live in the list under the entry form The banners also show on the public `/s/` page (`summit_share.php`) above the "You already have this summit" / "Save this summit" banner, signed in or not. Not shown on Multi-Activate or the invite page. Notice text goes through `advisory_message_html()`: summit designators (W6/CT-012, also W6/CT-12) link to the viewer's own `summit_detail.php` when the summit is on one of their dashboards (current one first, `&group=` added to switch otherwise), else to the public `/s/` page; unknown designators stay plain text. GPS coordinates (decimal pairs with 3+ decimals, N/W letter forms, degrees/minutes/seconds) link to Google Maps, and web addresses (https:// or www.) open in a new tab. Limit is `ADVISORY_MAX_LEN` (500), with a live "characters left" counter on the new and Edit forms. **Map pins (2026-11-14):** on `summit_detail.php` each distinct coordinate across all notices becomes a numbered pin (Pin 1, 2... in display order, most serious notice first, so numbers can shift when a more serious notice is added) colored by its notice level (red / amber with dark number / blue); a matching badge sits before the coordinate in the banner and `advPinFocus(n)` jumps to it; an "Advisory pins (N)" map button fits summit plus pins. Collected via the `&$pins` argument of `advisory_render_banners()` / `advisory_message_html()`; the public `/s/` page passes none, so it shows no badges or pins.

**Public summit pages (`/s/<designator>`, shipped 2026-10-27) and a queued idea (alternate destination and return-trip pulldown on summit detail):** see `research/public-summit-pages.md` before touching `summit_share.php`, `share_lib.php` or the summit page Share button.

**Google Maps API — two-key setup (completed 2026-06-01):**
Two separate API keys are used. Both are in the same paid Google Cloud project.

- **Server key** (ends in `s3W5Fo`) — used in `sotaplanner_secrets.php` as `GOOGLE_MAPS_API_KEY`. Application restrictions: **None**. API restrictions: **Geocoding API + Distance Matrix API only**. Never exposed in HTML.
- **Browser key** (ends in `F9jRs`) — stored as `GOOGLE_MAPS_BROWSER_KEY` in `sotaplanner_secrets.php`. Used in HTML `<script>` tags for the Maps JavaScript API. Application restrictions: **HTTP referrers** — six entries required: `*.sotaplanner.com/*`, `sotaplanner.com/*`, `*.ki6cr.com/*`, `ki6cr.com/*`, `*.christopherreddick.com/*`, `christopherreddick.com/*`. (The `*` wildcard only matches subdomains, not the bare domain — both forms needed.) API restrictions: **Maps JavaScript API only**.

**Server key API list (updated 2026-11-08):** the server key now also allows **Routes API** (and Places API (New), enabled for future use but not called yet). A 403 with reason `API_KEY_SERVICE_BLOCKED` means the API is missing from the key's API restrictions list, not that the project has it switched off. **Drive legs (2026-11-08):** `drive_leg_lib.php` (`drive_leg_get($from, $to)`) is the one source for Multi-Activate drive time, distance and road line (screen, PDF and route import), one Google Routes call per leg, cached 30 days in `drive_leg_cache` (rounded start and end point). It replaced Distance Matrix plus the free OSRM demo server, which knew nothing about road closures (it drew Highway 2 through the Angeles Crest closure). Google can return an empty `{}` for a leg that STARTS on a closed road segment (typically a trailhead) while the reverse direction routes fine, so the lib retries the other way round and stores it as `source='routes_rev'` with the line reversed. If Routes is unavailable it falls back to the Distance Matrix time with no line (dashed straight line on the map, never a guessed route). The summit detail page no longer draws a driving line; its Directions button opens live Google Maps. **Summit detail travel time (2026-11-08):** `summit_detail.php` now uses `drive_leg_summit_rt()` (in `drive_leg_lib.php`) for both the Calculate button and a page-load check, so stored `summits.drive_time_min` (round trip to the starting point, or to the summit if none) is refreshed whenever the cached Routes leg changes (legs expire after 30 days, so closures get picked up). A Distance Matrix fallback time never overwrites an existing stored value. The Travel tile's "Check road closures before you go" line was removed at Chris's request (2026-11-19, "unnecessary here"); Recalculating is a small refresh icon next to the tile label; when the start address or starting point changed since the last calculation, a prominent amber "Recalculate" button shows in the tile instead (`showTravelRecalc()` also shows it right after the starting point is moved on the map, since that save is AJAX with no page reload). The blue driving line stays off the summit map until we decide to show a Routes line there.

**Why two keys:** HTTP referrer restrictions break server-side PHP calls (no Referer header). IP restrictions break browser calls. Two keys is the only way to properly secure both. The server key never appears in HTML; the browser key is referrer-locked so it can only be used from the three site domains.

**TODO — set up Cloudflare CDN in front of DreamHost (queued 2026-08-25):**
Chris wants to actually implement this in a future session — see "Hosting & Scaling — CDN Options" under Staging Environment below for the researched plan (free Cloudflare account, point sotaplanner.com's nameservers at Cloudflare's two nameservers). This is a live DNS/nameserver change affecting the production domain and email routing, so confirm scope with Chris before touching DNS — don't just do it unprompted at session start.

**Community Notes box (added 2026-10-23):** `summit_detail.php` has a collapsed "Community Notes" box (replaced the small Notes card) that loads SOTA articles/links in the background via `sota_resources_refresh.php` / `sota_resources_lib.php` (`api-db2.sota.org.uk/api/resources/<ref>`, read-only GET, cached 6h per summit in `app_settings`). Chris stated on 2026-10-23 that he has SOTA approval to use the SOTA API for this. Article HTML is sanitized server-side before display. **SOTA Maps tracks moved to the same API (2026-11-20):** with SOTA's Maps overhaul the old `api-db.sota.org.uk` server stopped accepting connections; tracks now come from `https://api-db2.sota.org.uk/smp/gpx/summit/ASSOC/REF` (same JSON, same `hdr_id`s). Chris confirmed on 2026-11-20 that his SOTA approval covers reading GPX tracks there, including the weekly import cron. Used in `gpx_import_lib.php`, `gpx_fetch.php`, `import_sotamaps_gpx.php` (shipped 2026-11-20, a fix, not in the changelog).

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
- **SOTA Maps import start point (2026-11-08):** the import no longer waits on OpenStreetMap. It sets the summit's starting point to the track's low end at once (only if the summit has none) and queues a row in `trailhead_snap_queue`. `trailhead_snap.php` then tries the Overpass snap in the background after the summit page loads (`trailhead_snap_run()` in `osm_trailhead_lib.php`; reloads the page once if the point moved). Failures retry on later page views (60 s apart) and in the nightly `trailhead_osm_cron.php` (up to 6 attempts, then `gave_up`, track start kept). A start point edited by hand is never overwritten (`skipped`). Every attempt is logged as "Start point lookup: ..." in `sota_logs/gpx_cron.log`, visible in God Mode's Cron Activity Log. `osm_find_trailhead()` returns false when the lookup failed, null when nothing is mapped. Silent change, deliberately not in the changelog.
- **Cron script** (`trailhead_osm_cron.php`): CLI-only, processes `global_gpx_tracks` rows missing a trailhead (newest-first so recently cron-imported tracks get filled promptly). 100/run at 2s delay.

**Remaining data pre-population idea:**
- **Drive-up summit status from Google My Maps:** A public map exists marking SOTA summits reachable by car (no hiking required). URL: `https://www.google.com/maps/@39.0603443,-99.2805547,3153051m/data=!3m1!1e3!4m2!6m1!1s1JPDeCfGjFoAVlJlXkXvCPxv5-SvDOt4?entry=ttu` — research how to extract the underlying KML from a public Google My Maps layer and import drive-up status into the summits table.

**Track 3 — SOTAwatch write API (post/delete spots and alerts):**
Research complete and tested 2026-05-28. All endpoints and auth headers are fully understood (see "SOTAwatch Write API" section below). `test_sotawatch_write.php` exists on dev for testing. oauth_callback.php updated to store `id_token`. **Blocked on VK3ARR** — our `sotaplanner` client returns HTTP 403 on the write API despite valid tokens. 2026-08-24: re-asked VK3ARR directly on the SOTA Reflector thread (same thread where the SSO callsign claim got fixed — he's responsive there) to explicitly grant write access to the `sotaplanner` client, the same way `sotlas` and `polo` have it. Awaiting his reply. Once granted, re-run the test page to confirm, then build the real UI (spot/alert buttons on summit_detail.php).

**GPX upload API for SOTA Maps — asked, awaiting reply (2026-08-24):**
Checked sotamaps.org directly: the "Import GPX Track" page is a plain web form (file picker + points-reduction dropdown) with no linked API docs, dev page, or GitHub repo anywhere on the site. As far as we can verify, the web form is the only sanctioned way to submit a track today — the only endpoint we know of for SOTA Maps is the undocumented *read* endpoint we already use (`api-db.sota.org.uk/smp/gpx/summit/...`), which its maintainer has called a private API not to be relied on. Asked VK3ARR on the Reflector thread whether a submission API exists or is planned. If one becomes available, it could let users submit routes directly from SOTAplanner instead of the external site — worth revisiting once he replies.

**SOTA Maps overhaul and summit CSV (noted 2026-10-26):** Josh WU7H (SOTA management team) told Chris that SOTA is overhauling the Maps system and working on a way to submit tracks back to it. He agreed that polling SOTA Maps per summit is wasteful and understands the need for a CSV listing which summits have GPX data and which do not. **That file does not exist yet.** The idea: if it is refreshed regularly, we download it, compare against the previous copy, and only fetch summits whose entries are new or updated, instead of pinging all ~181k summits over months. Columns Chris proposed (nothing agreed): SummitCode, TrackCount, LastTrackUploaded, TrackID, distance/gain, start lat/lon, uploader, status, optional bonus/validity, ideally a delta file and a stable public URL.
- **When it arrives:** rewire `batch_gpx_cron.php` so `--mode=new` and `--mode=retry` are driven by the CSV diff (changed `TrackCount` or `LastTrackUploaded`) instead of walking `global_gpx_checked` by age. Keep `global_gpx_checked` as the audit log. Reuse `import_sotamaps_track()` for the actual fetch, and drop the weekly ~4 hour retry sweep once the diff works.
- **Until then:** leave the current crons as they are. Don't build against the CSV or invent its format.
- **Also possible:** a sanctioned track submission path may answer the open question to VK3ARR below, enabling a "submit route from SOTAplanner" feature. Revisit when SOTA announces it.

**SSO callsign claim — RESOLVED 2026-08-24:**
The `sotaplanner` Keycloak client's `id_token` was missing verified `Callsign`/`UserID` claims (see "SOTA SSO OAuth" section below for what changed in `oauth_callback.php`). Flagged to VK3ARR on the SOTA Reflector thread; he fixed it same-day ("exported for a different scope" — a client-scope/protocol-mapper issue on his end). Verified live via `SOTA_SSO_DEBUG` mode before deploying the code fix. No further action needed here.

**Upcoming project (noted 2026-10-25): integrate KM6AM's Summit RFI mapping.** Chris wants to work with KM6AM (author of the Summit RFI project) to bring RFI (radio frequency interference / receiver overload) data into SOTAplanner. First version built 2026-11-19 with his permission (see "Permission granted" and "RFI layer on the summit map" below). **Update 2026-10-25: KM6AM has said he wants to work with Chris, and Chris confirmed it is OK to list him on the changelog's "What we're working on".** Do not scrape or bulk-pull his data; agree the specifics with him first (same courtesy principle as the SOTA API note in the global CLAUDE.md).

**Two integration options Chris is considering (undecided):**
1. **Call KM6AM's site via an API.** Least work for us and his data stays authoritative and fresh, but we depend on his server staying up, and he must provide and document a stable endpoint (today only the WFS feed behind his CalTopo map is visible). Mitigation: cache results in our own table (like the SOTA Community Notes cache) so page loads never block on his site.
2. **Run his tool on our own VPS.** Full control over speed and uptime, and it could remove load from his host, but we would take on hosting, FCC/NOAA data refreshes and maintenance, and need his code/pipeline and license permission. DreamHost shared hosting can't run this (browser-side compute is fine, but a data pipeline and any heavy jobs would need a VPS), so this means new infrastructure and cost.
Leaning (Claude's suggestion, not decided): start with option 1 via a scheduled import of a per-summit export into our own table, and revisit a VPS only if his hosting or the data volume becomes a problem.

What exists on his side (researched from his public pages, 2026-10-25):
- **Summit RFI** (`https://km6am.com/summitRFI`): spatially joins the SOTA summit list with public FCC datasets (ASR structures, ULS land-mobile and microwave, CDBS broadcast FM/TV/AM) plus NOAA Weather Radio. Indexes about 3,620 US summits that have mapped RF sources. Each summit gets an "RFI card": overall front-end overload risk (High / Moderate / Low / Clear) plus a band-by-band breakdown. Search by SOTA code or peak name.
- **Methodology** (as he describes it): field strength estimated from summed ERP of nearby transmitters, scaled by distance, de-rated for vertical antenna pattern and terrain diffraction, anchored to real receiver desense observations. Terrain is SRTM 30 m.
- **Map layers:** Summits (pin colored by overall risk) and Sources (one pin per transmitter, colored by ERP). Published as a CalTopo map (`caltopo.com/m/FLECR3M`) fed live by a WFS service on km6am.com.
- **Field Estimate Map (beta)** (`https://km6am.com/summitRFI/map/`): computed entirely in the browser from FCC/NOAA transmitter data and public terrain tiles; estimates the field a receiver would see against a weak S5 FM signal. Summit circles are colored by a precomputed per-summit value. Click a summit for its report card; after computing a view, click any point for a field estimate. Controls: resolution 500 m / 250 m / 150 m, terrain shadows toggle, opacity slider, band-group layers (a transmitter counts once per group if within an octave of any band in the group). Currently **California only**. He cautions that the broad "Moderate" background is an upper bound in built-up areas (no clutter modeled); hot zones near broadcast sites and terrain shadows are the more reliable parts.
- **Not stated on his pages:** author contact, license terms, update frequency, and any documented API or data download (the WFS feed behind the CalTopo map is the only visible machine-readable source).

**Update 2026-10-25 (KM6AM chat reply):** He is running this on a "rickety" AI-built droplet and expects production use would need significant hardening. He keeps the raw FCC databases locally, heavy compute happens only at bring-up and when FCC data updates, and the map generation runs in the browser. He offered either a polished repo with a `handoff.md`, or pointers for collaborating. His summits are already fully computed and served by his WFS (`https://km6am.com/geoserver/wfs`, JSON output only, EPSG:4326 supported). Layers: `rf:Summits`, `rf:Sources`, `rf:RefSummits` (all SOTA summits plus report-card link and `rfi_risk`/description), `rf:RefZones` (25 m activation-zone polygons tinted by risk). Per-summit report cards live at `https://km6am.com/rf/reports/W6_CC-045_report.html` (replace `/` with `_`, keep leading zeros); the page is JS-rendered, so the data is not in the raw HTML. Chris asked permission to scrape report cards and link to them; KM6AM had not yet answered that specific question. He is traveling to Korea, so expect a delay. Prefer WFS over scraping HTML, and wait for his explicit OK before any bulk pull. Chris told him SOTAplanner builds coverage layers from public cell service maps shown as a Leaflet layer, and KM6AM offered to reconcile against an example repo of ours.

**Permission granted (Chris, 2026-11-19):** KM6AM (Badgie Miller) gave Chris permission to use his Summit RFI data in a thread on the SOTA NA Slack, and asked that he be **cited as the source** for this work. Every place his data appears (map, notes, changelog, any PDF or share page) must credit "Summit RFI by KM6AM" with a link to his GitHub repo `https://github.com/km6am/sota-rf` (Chris, 2026-11-19: link the repo, not the km6am.com web interface; report card links still go to his report pages). Federal transmitters (Forest Service, FAA, military) are licensed by NTIA, not the FCC, so they are missing from his data; the summit page says so. His code is MIT licensed (github.com/km6am/sota-rf); code adapted from it must keep his copyright and license text. His data terms (`DATA_SOURCES.md` in that repo) ask for these credit lines too: transmitters FCC, NOAA Weather Radio NWS, terrain USGS SRTM/3DEP via AWS Terrain Tiles (Mapzen), and a note that values are estimates from registered power, not measurements or an RF exposure assessment. Still be gentle with his server: cache everything on ours.

**RFI layer on the summit map (built 2026-11-19, shipped to production 2026-11-20, in the changelog crediting KM6AM):** An "RFI" button joins the cell coverage buttons on `summit_detail.php` (US summits only, and only shown when his grid covers the summit or he has a report card for it; the dot on the button is colored by the summit's risk). Turning it on adds (1) his national ~250 m field strength heat map with his color scale, (2) a badge above the summit pin with the report card risk and affected bands that opens his full report card in a new tab, (3) a compact legend overlay on the map (band group selector: All bands, HF, 6m to 70cm, Above 70cm; four-step key) whose tier descriptions show on hover or via its "i" button on phones (Chris found the full legend too big, 2026-11-19), and (4) a note under the map with transmitter count, total power, the three strongest transmitters, data date and credits. What his server offers (checked 2026-11-19): no WMS/WMTS tiles; a WFS feed; a summit index `https://km6am.com/rf/qrm_index.json` (~2 MB, ~3,650 US summits with risk, `qrm_levels`, `total_erp_w`, `top` transmitters, `report` path); and the national grid at `/summitRFI/national/v/` (`index.json` plus gzip uint8 blocks `{layer}/{k}/{lat0}_{lon0}.bin`, 450x360 cells, lower 48 only, no CORS headers). Files: `rfi_lib.php` (cache and fetch logic), `rfi_summit.php` (JSON for one summit, refreshes the summit index at most daily with If-Modified-Since and splits it into one file per summit), `rfi_grid.php` (relays each grid block once per grid version, browser unpacks the gzip), `rfi_layer.js` (Leaflet layer adapted from his `natgrid.js`, MIT notice inside). The cache is shared by production and all staging sites at `/home/chrisr069/sota_rfi_cache/` (about 15 MB; old grid versions are deleted when his grid changes). **Shared code and the public page (shipped 2026-11-21):** the map controls (button, badge, legend, loading card, note) live in `rfi_ui.js` / `rfi_ui.css`, used by both `summit_detail.php` and the public `summit_share.php` via `RfiUi.init({map, ref, lat, lon})`; edit them there, not in the pages. The note under the map is one line only (Chris: "too cluttered"): "Full RFI report · Source: Summit RFI by KM6AM, used with permission"; the estimate, FCC/NOAA/USGS and federal caveats sit in the legend's "i" panel. `rfi_allow_request()` in `rfi_lib.php` lets signed-in users in, plus signed-out visitors only when the browser marks the request same-origin (`Sec-Fetch-Site`, Referer fallback), so other sites can't hotlink his data through our relay. Not done yet: the Multi-Activate PDF, a click-for-value popup on the heat map (his map has one), Alaska and Hawaii (his grid does not cover them).

Questions to settle with KM6AM before building:
1. Permission and attribution: how he wants to be credited, license terms, and whether he is happy for SOTAplanner to consume his data.
2. Data access: is the WFS feed OK to use, or can he supply a per-summit export (SOTA ref, overall risk, per-band ratings, source list) that we store in our own table and refresh on a schedule? Preferred over live calls from user page loads.
3. Coverage: US-wide per-summit cards vs California-only field estimate map. Decide what we show for summits with no data (hide vs "not assessed").
4. Update cadence and how we learn about changes (his FCC data refresh schedule).
5. Which bands and what thresholds map to High/Moderate/Low/Clear, so we can explain them accurately in our UI.

Ideas for how it could appear in SOTAplanner (none decided):
- RFI risk badge plus band breakdown card on `summit_detail.php` (near the weather and Summit Info cards), with a link out to his full report card and map for attribution.
- A small RFI indicator on the dashboard list and an optional filter/sort ("quiet summits first").
- Embed or link his Field Estimate Map from the summit page for California summits.
- Show the RFI summary on the activation invitation page and in the Multi-Activate PDF only if it adds value for guests (probably summit page only).
- Store per summit in a new table keyed by `sota_ref` (additive migration via `db_migrate.php`, per the shared-database rule), filled by a cron following the `batch_gpx_cron.php` pattern, not by live requests.
- Use the Alpine Precision tokens for the risk colors (green/orange/red/gray) and keep user-facing copy free of " — ".

**Upcoming project (started 2026-10-29): BRouter hiking path checker, branch `brouter-paths`.** A tool that checks whether a walkable path exists between two points and estimates the hike (gap filling in Multi-Activate, summits with no recorded track, a "does a hiking path exist?" check). Routing engine is BRouter (OSM-based, MIT), self-hosted by K3MGM (Mark) on his AWS server; SOTAplanner calls it from PHP only, with an `X-Api-Key` header, and caches every routed pair permanently (additive table via `db_migrate.php`). The request doc to send him is `research/brouter-hosting-request-for-k3mgm.txt` (Appendix A has the exact 431 map tiles, 6.86 GB, computed from SOTA's public `summitslist.csv`, not the SOTA API); background is `research/trail-gap-routing-and-vps.txt`. His assistant will set the server up from that doc. **Nothing is built on the SOTAplanner side yet.** Keep bulk runs paced. Chris pays for the box himself; never mention hosting costs to Mark or in drafts. BRouter's own time figure is a bike-style time; compute hike time from distance and climb with the existing GPX pace logic. Wait for Mark's base URL and key before building against it; until then at most a handful of manual calls to the public brouter.de.

**BRouter server handed over (2026-11-03):** Mark created EC2 instance `ki6cr-brouter` (t4g.small ARM, 2 GB RAM, 20 GB disk, Ubuntu 24.04, us-west-2) at static IP `44.254.201.100`. Login: `ssh -i ~/.ssh/brouter_ed25519 ubuntu@44.254.201.100` (passwordless sudo). Chris and Claude maintain the software (OS patches, BRouter, TLS, API key, rate limiting); Mark keeps AWS-level control. Ports 80/443 are open to the world for DreamHost; port 22 is allowed only from Chris's home IP until Mark removes it. **Management goes over WireGuard** to the home UniFi server (`wgsrv1`, home WAN port 1194): the box is peer `192.168.4.10`, config `/etc/wireguard/wg0.conf`, enabled at boot as `wg-quick@wg0`; client files are `~/.ssh/wg-brouter-aws*.conf`. The box only routes `192.168.4.0/24` through the tunnel. Chris's own WireGuard client is `192.168.4.2`; a plain LAN machine cannot reach `192.168.4.10` (UniFi blocks LAN to VPN), so turn on the WireGuard tunnel on the Mac first, then `ssh -i ~/.ssh/brouter_ed25519 ubuntu@192.168.4.10`. SSH over the VPN was confirmed from the Mac on 2026-11-03 and Mark was asked to remove the port 22 rule, so after that a tunnel must be on to reach the box. **Tailscale is also installed (2026-11-03)**, the primary way in when away from home: `ssh -i ~/.ssh/brouter_ed25519 ubuntu@100.64.180.113` (hostname `ki6cr-brouter` on Chris's tailnet, free personal account, Mac app installed). It does not depend on Chris's home internet or IP. Chris should turn off key expiry for the machine in the Tailscale admin page, otherwise the box logs out after about 180 days and port 22 is closed. WireGuard stays as the backup path.

**BRouter installed on the box (2026-11-03):** Java 21 plus BRouter v1.7.10 in `/opt/brouter` (jar `brouter-1.7.10-all.jar`, profiles in `/opt/brouter/profiles`, which already includes `hiking-mountain.brf` and `trekking.brf`; custom profiles go in `/var/lib/brouter/customprofiles`). Runs as systemd service `brouter` (user `brouter`, enabled at boot, `-Xmx1g`, 3 threads, 60 s max route time), listening on `127.0.0.1:17777` only. Tiles live in `/var/lib/brouter/segments4`; the list is `/var/lib/brouter/tiles.txt` and the polite download/refresh script is `/var/lib/brouter/download_tiles.sh` (2 s pause, skips unchanged files, only moves complete downloads into place). **Full worldwide set installed 2026-11-10:** all 431 tiles from Appendix A of the K3MGM request doc (every tile with a SOTA summit plus neighbors within about 0.5 degree for trails crossing tile edges), 6.4 GB, zero failures; disk after was 9.2 GB used, 9.2 GB free. The old West Coast list is `tiles.txt.bak-westcoast-20261110`. Test routes passed in NC, UK, Switzerland, Colorado and NZ. If a route ever fails with a missing data file, add the named tile to `tiles.txt` and rerun the script as the `brouter` user. **Weekly refresh (Chris's choice, 2026-11-10):** systemd timer `brouter-tiles-refresh.timer` runs `brouter-tiles-refresh.service` (the same download script) every Sunday 10:00 UTC plus up to 30 min random delay, logging to `/var/lib/brouter/refresh.log` (rotated monthly by `/etc/logrotate.d/brouter-tiles`). brouter.de rebuilds every tile daily from fresh OSM data, so each weekly run downloads the full ~6.4 GB again, one file at a time with a 2 s pause; a run on a day with no rebuild just confirms each tile unchanged. Check results with `grep -c ^FAILED /var/lib/brouter/refresh.log`. Test call (on the box): `curl "http://127.0.0.1:17777/brouter?lonlats=-117.544,33.7201|-117.5342,33.7107&profile=hiking-mountain&alternativeidx=0&format=geojson"`. The Santa Ana test pair gave 4.7 km / 247 m climb with `trekking` and 3.2 km / 226 m with `hiking-mountain`. **Proxy (2026-11-03):** nginx on 80/443 for `hikepath.sotaplanner.com` (site file `/etc/nginx/sites-available/hikepath`, key in `/etc/nginx/brouter_key.conf`, rate limit per IP in `/etc/nginx/conf.d/brouter_limits.conf`: 5 req/s burst 20 at first, raised on 2026-11-21 to 20 req/s burst 60 after two runners at once were refused about 10% of calls (nginx answers refusals with 503) and Chris OK'd using more CPU for speed as long as the credit balance is not run down; trail discovery sends about 14/s, BRouter load about 0.55 of 2 CPUs). **Status (2026-11-21):** `/usr/local/bin/brouter_status.py`, run every 15 s by `brouter-status.timer`, writes `/var/lib/brouter-status/status.json` (routes in the last minute / 5 min / hour with refused, no-route and error counts, response times from `/var/log/nginx/brouter_timed.log`, load, memory, disk, BRouter service state), served at `/status.json` behind the same key and shown as the "BRouter server" bar on Trail Discovery. Copies of the box's nginx and status files are in `research/brouter-box/`. Only `GET /brouter` is forwarded; no or wrong `X-Api-Key` gives 401, POST 405, any other path 404. The API key is saved on Chris's Mac at `~/.ssh/brouter_api_key` (copy it into `sotaplanner_secrets.php` as the BRouter key when building the PHP side; never put it in git or in an email with the URL). **HTTPS (live 2026-11-03):** DNS A record `hikepath` to `44.254.201.100` added by Chris at DreamHost; Let's Encrypt cert issued by certbot (expires 2027-02-01, auto-renew timer installed; HTTP redirects to HTTPS). Base URL: `https://hikepath.sotaplanner.com/brouter`. No ufw; the AWS security group is the firewall and port 17777 is localhost-only. **PHP side, test phase (2026-11-03):** `BROUTER_URL` and `BROUTER_API_KEY` are defined in the server's `sotaplanner_secrets.php` (shared by staging and production; backup at `sotaplanner_secrets.php.bak-20261103`). `brouter_lib.php` is the client (`brouter_route()`, `brouter_route_pair()`, `brouter_read_gpx_points()`; no cache yet). `brouter_test.php` is a God-Mode-only standalone bench on staging: compares a recorded community GPX leg (start to summit) with BRouter's route under both profiles, plus a quick two-point route mode. DB units to remember: `global_gpx_tracks.total_distance` is km and `elevation_gain` is meters. First results: `hiking-mountain` follows real trails far better than `trekking` (NC-304: 96% vs 1% within 50 m, trekking detoured via a road); distance is usually within a few percent, but BRouter climb runs low (-21% and -45% on two summits, +8% on a third), and off-trail recorded routes (W7O/SC-002) diverge. **Still to do:** more comparison runs, permanent cache table, hike-time and gap-filling functions.

**Trail discovery (started 2026-11-15):** Chris's idea: start at the summit, search outward in a spiral with BRouter, follow trails down to a road, trailhead or parking spot an activator could drive to, and keep searching after the first find because another trail may be shorter. Stop at a set distance from the summit. Run it on every summit with no hike data, then turn it all on publicly at Four Days in May 2027 as a headline update. God Mode only until then, starting with a handful of sample summits. Storage was left to Claude to recommend (proposal made 2026-11-15: summary rows in the database, route shapes as files in a private folder outside the web roots, served through a PHP page gated by a launch switch in `app_settings`).
- **Chris's decisions (2026-11-15):** first test is against known community tracks on W6/CT summits (his local summits). Starting points: paved road (ideally with trailhead or parking) preferred, dirt roads only as fallback when no paved start is found. Show up to 3 routes per summit (shortest or least effort). No manual review of every summit: each route gets a confidence score, and a user who adds such a summit to a dashboard is told a review is required. Summits where nothing is found simply stay "Needs Research".
- **Built (branch `brouter-paths`, staging slot A):** `trail_discovery_lib.php` (`td_discover()`: probe rings at 0.75/1.5/2.5/4/6/8/10/12 km, 8 to 32 probes per ring, 3 parallel BRouter calls at most 4 per second, reads BRouter's per-stretch way tags from the summit outward; `td_way_class()` decides paved/dirt/walk, roads closed to vehicles count as hike; groups starts within 150 m, re-routes the best 6 per tier, `td_confidence()` first-draft score with reasons; `td_compare_known()` scores against a community track). God Mode page `trail_discovery.php` (runs, scoreboard, per-summit map). Tables `trail_disc_runs`, `trail_disc_results` (migrated 2026-11-16). Route files: `/home/chrisr069/sota_discovered/runs/<run>/<REF>.json.gz`. Uses no Overpass, unlike the older `bp_discover()` in `brouter_paths_lib.php`.
- **First numbers (2026-11-16):** about 130 BRouter calls and 31 s per summit at 8 km. W6/CT-026 showed the cost of "paved first": dirt starts on Main Divide Road were 0.7 mi, paved starts 3.3 mi plus.
- **Test run 1 (105 W6/CT summits, 8 km, paved first):** 39 match, 52 partial, 13 miss; community route in our top 3 for 65 (78 if we showed the quickest 3 of any road type); median start 186 m from the community start; median hike time error on matches 15%. Every winning route was first found within 6 km (half within 0.75 km). BRouter CPU peaked at 14.5%, credits stayed at 576. Confidence score did not separate matches from misses (both median 90). Problems found: (1) summits with no mapped way to the top: BRouter snaps the summit to the nearest road, so routes showed "0 min" hikes (26 of 105 best routes ended over 120 m from the summit); (2) gated or closed fire and tower roads looked drivable. BRouter does report mapped gates in its NodeTags column.
- **Fixes for run 2:** off-trail finish counted (straight line plus climb to the listed summit height, confidence penalty, capped at 30 when over 400 m); mapped gates end the drivable road (park at the outermost gate); ways with `sac_scale` are trail; tracks or service roads tagged foot/bicycle/horse=yes with no motor vehicle tag count as closed fire roads; service roads without a paved surface tag are dirt tier. Each result also records whether the community route would be in the top 3 under the other selection rule (`known_in_alt`). Known limit: gates that are not mapped (Verdugo, Mt Disappointment road) still look drivable.
- **Test run 2 (2026-11-16, same settings plus the fixes):** 41 match, 55 partial, 9 miss; "0 min" best routes fell from 9 to 3; CPU peaked at 16%, credits stayed 576. Better yardstick than match/partial: on 70 of 105 summits some candidate starts within 400 m of the community start, with a median hike time error of 12%. Top 3 shown includes that start for 53 summits with paved first versus 60 with quickest 3 (64 with quickest 4 plus a separate dirt drive-up note). 27 summits have a dirt road reaching the summit (under 400 m hike), usually a gated fire road, which quickest 3 would list as a "0 min" route. The confidence score does not predict anything (per route AUC 0.61; at summit level no band separates good from bad); the strongest signals are a route's speed rank and 40+ probe hits, and very short top routes (under 45 min) are the least trustworthy (38% found the community start). Real failures are mostly road access OSM does not show. Fixed afterward: the comparison now includes the off-trail straight finish.
- **Gates and closures (Chris, 2026-11-16):** many forest gates close for part of the year, and many dirt roads have a gate right where the trail leaves them (no driving up, or restricted). Chris's rule: scope is global, so only solutions that work worldwide (he rejected U.S. Forest Service MVUM road data as a permanent answer since it covers only U.S. national forests). **Built 2026-11-16:** every dirt start carries a `closed` fallback: the hike from the paved road beyond it (the nearest paved point any probe reached past that dirt start, routed paved point, dirt start, summit; at most 6 extra BRouter calls per summit), shown on the page as "If the dirt road is closed: park at the paved road..." with a dashed line and square marker; `td_compare_known()` also matches a community start against that fallback (`via_closed`). `td_measure()` holds the shared distance, climb, off-trail and time math. **Test run 3 (2026-11-17):** 43 match, 53 partial, 9 miss; 192 of 283 dirt starts got a fallback (median +45 min, a quarter over +1 h 40 min); 23 of 28 dirt drive-ups got one; on about 20 summits the community start sits at our "park at the pavement" point, i.e. people park below the dirt road. Coverage of community starts did not change (that paved point was usually already a paved candidate); the value is an honest time for every dirt start. Staging sessions can expire mid-batch: the batch script re-logs in on a non-JSON answer.
- **Confidence score rebuilt (2026-11-17):** `td_confidence($c, $rank)` is now a logistic model fitted on run 3 (label: our start within 400 m of the community start). Route score = estimated chance people use that route; cross-validated AUC 0.82 (first draft 0.61). Signals: speed rank among routes (strongest), number of probes that led there, community evidence from OTHER summits' tracks in the global GPX library (`td_community_evidence()`: a nearby summit's track starting within 100 m, or 75%+ of the hike on community tracks; the summit's own track is never used), paved start, very short (<45 min) or long (>4 h) hike, off-trail finish. Summit score = 1 - product of (1 - route score) over the routes shown; bands `td_conf_band()`: high 70+, medium 40-69, low under 40. On run 3 (in sample): high summits showed where people park 93-100% of the time, medium about 50%, low about a third. Only 14 to 15 of 105 summits reached high. Weights must be refit as more regions are tested (each test run now has a `region` setting). Results also store `known_shown_start_m` / `known_alt_start_m` (nearest shown start, counting gate-closed parking, to the community start; migration added 2026-11-17).
- **Test run 4 (2026-11-17, W4C/WM western North Carolina, 90 summits, first out-of-sample region):** 27 match, 34 partial, 29 miss; hike time error on matches 7%. Some route started within 400 m of the community start on only 44 of 90 (49%, versus 67% in W6/CT), so discovery itself is weaker there; 14 quickest routes were far shorter than the community's and wrong, most starting on residential roads (likely private mountain developments that OSM does not tag as private). Route ranking transferred well (W6/CT-fitted score: AUC 0.85 on W4C/WM), but summit bands did not: high 75 to 83%, medium and low both about 45 to 50%. So the summit score can single out the most reliable ~10% of summits, but cannot yet sort the rest; the ceiling is set by how often our candidates include the real trailhead at all. Weights refit on both regions and deployed. Most promising next step (needs Chris's go-ahead): our own global index of OSM parking and trailhead points (and access=private roads), built once on the BRouter box by streaming OSM extracts through a tag filter, used both to route from real parking spots and as a confidence signal. No Overpass.
- **Parking and trailhead list (Chris approved 2026-11-19, building):** built on the BRouter box in `/var/lib/osmindex/` by `build_index.sh` (log `build.log`, one TSV per region in `out/`). Covers only the areas around SOTA summits: `pick_regions.py` chose the 320 smallest Geofabrik extracts that contain every active summit plus 12 km around it (61 GB to download in total, each file deleted right after filtering, peak disk about 3.5 GB). 275 summits on small islands with no Geofabrik extract (VP0, J8, PJ2-7, FJ, FS, 8P, P4, 3Y) are skipped. Keeps `amenity=parking` (not underground, multi-storey or rooftop) and `highway`/`tourism=trailhead`, one row each: kind, lat, lon, OSM id, access, fee, parking type, name. Parking areas mapped as multipolygon relations are dropped (about 1%). Gotchas: osmium's referenced-object modes (`tags-filter` without `-R`, `getid -r`, and even plain `getid` with node IDs) need over 1 GB of memory whatever the region size and get killed on the 2 GB box. The first version added node locations with an on-disk index, but that index thrashed the disk on regions over about 400 MB (Finland 87 min, Java 40+ min unfinished). **Since 2026-11-20** `filter.sh` streams instead: tagged objects with `-R`, then the nodes of their ways picked out of an OPL text stream with awk, then locations added on the small result (Java: 2 min; Albania output identical to the old method). Always run osmium on that box inside the `systemd-run -p MemoryMax=` cage: an uncaged test on 2026-11-20 hit the system-wide OOM killer (it killed osmium, not BRouter, but that was luck). **Finished 2026-11-21:** all 320 regions in `out/` (one TSV per region, US states named like `us_texas.tsv`), 5,857,846 rows: 5,840,408 parking, 17,438 trailheads, 247 MB raw. Gotchas from the run: Geofabrik started failing every download for about 60 regions in a row overnight (temporary, cleared by itself; rerunning the script skips finished regions); region ids with a slash (`us/texas`) need the `fid` file-name fix now in the script; a "latest" link can redirect to a dated file that 404s for a while (West Sussex was taken from the previous day's dated file). Old script kept as `build_index.sh.bak-slow-20261120`. **Merged and filtered 2026-11-21:** `merge_filter.py 20` (100 s, run inside the memory cage) wrote `/var/lib/osmindex/parking_20km.tsv`: 2,989,569 points (2,976,718 parking, 12,851 trailheads), 149 MB, last column is km to the nearest of the 172,136 active summits. 31,077 duplicates from overlapping regions removed, 2.84 million points further than 20 km dropped. By distance: 0-5 km 778k, 5-10 km 998k, 10-15 km 705k, 15-20 km 508k (the extra 5 km Chris asked for adds 17%). **Served from the BRouter box (Chris's choice, 2026-11-21: keep everything for trail discovery on the AWS server; not in MySQL, which would have grown the shared database about 4x).** `split_cells.py` wrote one file per 0.2 x 0.2 degree map square to `/var/lib/parking/cells/` (28,526 files, 228 MB, largest 1 MB around Osaka; `index.json` has version, columns and the cell rule). nginx serves them at `https://hikepath.sotaplanner.com/parking/<i>_<j>.tsv` with i = floor(lat/0.2), j = floor(lon/0.2), same `X-Api-Key` as BRouter, GET only, gzip, own rate limit zone `parking` (10 req/s, burst 40, in `/etc/nginx/conf.d/parking_limits.conf`) so it never eats into BRouter's limit. A missing square answers 404, meaning no points there; no directory listing. A 20 km search needs at most 9 squares. Columns: kind (P/T), lat, lon, osm_id, access, fee, parking, name, km to nearest summit. Tested from DreamHost PHP with `BROUTER_API_KEY` (Santa Ana square: 90 rows, 1.8 KB sent). nginx site backup: `/etc/nginx/hikepath.bak-20261121`. To refresh: rerun `build_index.sh` (needs `out/` emptied first; about 61 GB of downloads, so a few times a year at most), `merge_filter.py 20`, then `split_cells.py parking_20km.tsv /var/lib/parking/cells.new` and swap the folder. Credit OpenStreetMap contributors (ODbL) wherever these points are shown. Wales test: 151 MB in, 17,766 parking lots and only 19 trailheads out (trailhead tagging is rare outside the US). Expected final size about 300 MB raw, much less after keeping only points within 20 km of a summit. Private roads were dropped from the plan: BRouter already reads access tags, and a car-profile route from a start to the public road network can check that globally with no extra data. Done: merge and dedupe, summit filter (Chris, 2026-11-21: 20 km, not 15, so long hikes are captured), serving (below). Still to do: use it in `td_discover()` (route from real parking spots, and as a confidence signal).
- **On production, God Mode only (2026-11-21, Chris: God Mode tools only work properly on production, OK to load there):** `trail_discovery.php`, `trail_discovery_lib.php`, `parking_lib.php` and the God Mode card are merged to `main` and live on sotaplanner.com; signed-out visitors are sent to login and the page checks God Mode. The branch `brouter-paths` stays open for further work. Summit maps show the OSM parking lots (blue P) and trailheads (green T) within the run's search area plus 2 km (toggle above the map), each start, gate-closed spot and community start gets its nearest mapped parking or trailhead, and the scoreboard has three tiles: top start within 150 m of mapped parking, community start within 150 m, and "mapped lot we missed" (people park at a mapped lot, none of our top 3 starts there). Stored per result in `trail_disc_results.best_parking_m` / `known_parking_m` (added 2026-11-21; 5000 = none within 5 km). Runs 5 (W6/CT) and 6 (W4C/WM), same settings as 3 and 4, were the first with these numbers. Batch runs can be driven from the Mac with a small script that logs in to a staging slot and calls `?ajax=one` per summit. **Path Finder is two-summit routing only since 2026-11-21** (Chris: drop the feature that doesn't work): its discover tab waited on the public Overpass service, which was overloaded, and showed nothing for 99 s before failing. Old `?mode=discover` links forward to `trail_discovery.php?ref=...` (the one-summit box is prefilled); the old discover functions in the Path Finder library are now unused. Both pages show a live seconds counter and end every request with a message (2 or 5 minute limit, non-JSON answers reported), and Trail Discovery no longer stores rows for unknown summits.
- **Runs 5 and 6 (2026-11-21, same settings as 3 and 4):** identical match / partial / miss counts to runs 3 and 4 (W6/CT 43/53/9, W4C/WM 27/34/29), so the search is repeatable. A shown start within 400 m of the community start: 50% W6/CT, 37% W4C/WM. Community starts within 150 m of a mapped OSM lot or trailhead: 49% W6/CT, 32% W4C/WM; our top start at one: 34% / 24%. "Mapped lot we missed" (people park at a mapped lot, none of our shown starts within 400 m): 20 W6/CT + 11 W4C/WM summits, the case for routing from the parking list inside `td_discover()`. Ten W6/CT summits searched while nginx refused requests (2026-11-21 15:02 to 15:11 Pacific, two runners at once) were re-run; verdicts unchanged.
- **Confidence refitted 2026-11-21 (Chris noticed San Jacinto, a 99% match, at 14%):** the old score's "over 4 hours" penalty marked down big mountains, where every route is long. Data: summits whose quickest route is over 4 h were right 57% of the time (short ones 43 to 49%); the long routes that are really wrong are the ones much slower than that summit's quickest (2% right). New model in `td_confidence($c, $rank, $rel)` via `td_score_candidates()`: speed rank, probe hits, community evidence, mapped parking (start within 150 m +1.39, trailhead +0.29, within 400 m +0.64), relative length (over 1.5x / 2.5x the quickest) and "over 4 h and over 1.2x the quickest" (-1.44). Fitted on runs 5 and 6 (1,164 routes, 194 summits): cross-validated AUC 0.85, 0.84 / 0.88 when fitted on the other region. Cross-validated summit bands: high 25 summits 80% right, medium 51 57%, low 118 31%. Runs 5 and 6 were rescored in place without searching (`research/trail_discovery_rescore.php`; backups `sota_discovered/runs-{5,6}-before-rescore-20261121` and `conf-before-rescore-20261121.tsv`). Refit with `research/trail_discovery_extract.php` (run on the server) and `research/trail_discovery_fit.py` (plain Python, no numpy) when more regions are tested.
- **Start points setting and runs 7 / 8 (2026-11-21, Chris's idea: work backwards from parking):** `source` = `probes` (rings), `parking` (every OSM lot and trailhead within `max_km` routed to the summit, trailheads first, up to `TD_MAX_LOTS` 160; the lot is the start when the walking begins within `TD_LOT_M` 400 m of it, candidates carry `lot`) or `both`. Parking only, same settings otherwise: shown start within 400 m of the community start W6/CT 53% (rings 50%), W4C/WM 34% (rings 37%); community route in top 3 42% / 31% (rings 38% / 27%); no start found 3 / 5 (rings 0 / 1, where nothing is mapped); 12 s and 50 calls per summit in W6/CT, 4 s and 16 in W4C/WM (rings about 31 s, 130 calls). Complementary: either method shows a right start on 58% W6/CT, 40% W4C/WM. Runs 9 / 10 test `both`. Even combined, about 30% (W6/CT) and half (W4C/WM) of community starts are not among our shown starts: next, sort those misses by cause.
- **Page layout (2026-11-21, Chris):** status bar, controls, scoreboard with a "What the numbers mean" glossary, then a review area (map left, the selected summit's routes right, Previous / Next), and the summit list at the bottom.
- **Proposed use (to agree with Chris):** high = fill in hike data up front, labeled as discovered and unverified; medium = routes shown with "review required" when added to a dashboard; low = stays in Needs Research with routes offered as suggestions only. Every user confirmation (GPX upload that matches, "I hiked this") becomes ground truth for refitting the score. Still possible later, all global: learn gates from community tracks that start partway along a dirt road that keeps going; our own gate correction table (later user reports); mapping gates in OpenStreetMap, which reaches BRouter on the weekly tile refresh.

**BRouter AWS server stats (2026-11-09):** K3MGM (Mark) exposes AWS's metrics for the box at a private Lambda URL, stored as `AWS_STATS_URL` in the server's `sotaplanner_secrets.php` (not in git). Hard limit from him: never more often than every 5 minutes, because the data only updates that often. `aws_stats_lib.php` (`aws_stats_capture()`) is the only code that calls it and enforces the limit; pages read only our `aws_server_readings` table (one row per reading, time in Pacific). `aws_stats_cron.php` runs four times a day (`0 4,10,16,22 * * *`, Pacific, since the DreamHost clock is Pacific) on production and logs to `sota_logs/gpx_cron.log`. Shown in God Mode, AWS Server tab: latest tiles, a 30-day CPU credit balance line chart (plain SVG, no library) and the last 12 readings. Each reading is a single 5-minute average at that moment, and the feed has no disk-space-used figure. CPU credits are a running balance, not a daily reset: a t4g.small earns 24 per hour, spends them above about 20% CPU, and caps at 576. The table `aws_server_stats` (one row per day, first version, 2026-11-09) is unused; its one row was copied into `aws_server_readings`. The feed's `bps` fields are labeled as Mark's feed names them; whether they are bits or bytes is unconfirmed.

**Idea, not scheduled (2026-10-24): email "invited, not signed in" users.** God Mode's Users tile shows callsigns added to a dashboard who have never completed SSO (11 of them at the time). Chris may someday email them to say they've been set up. Catch: the app has no email addresses for them (the `users` table is keyed by callsign and SSO only supplies the callsign claim), so this would need a new way to get contact info first.

**Queued projects (added 2026-10-28), not started:**
1. **Search bar on the main site.** Chris wants a site-wide search bar. Scope not yet defined (what it searches: summits on the current dashboard, the SOTA summit cache, help/about/changelog pages, or all of these). Confirm scope with Chris before building. The existing summit search in `sota_cache_helper.php` (indexed `sota_summit_index` table plus `sota_cache_norm.txt`) is the likely backend for summit lookups.
2. **Accessibility and cookie disclosure, only if required.** First determine what is actually required for SOTAplanner (users worldwide, so consider GDPR/ePrivacy and UK rules for cookies, plus accessibility expectations such as WCAG 2.1 AA). Audit what the site sets: PHP session cookie, `sota_default_group` cookie, Google Maps and Google Fonts third-party loads, motion.dev CDN. Strictly necessary cookies may need only a disclosure line or privacy page rather than a consent banner. Then build whatever is needed: cookie/privacy notice, and an accessibility pass (contrast, focus states, keyboard navigation, alt text, ARIA labels, reduced-motion support). Report findings to Chris before adding banners.

**Queued projects (added 2026-11-05), not started:**
1. **S2S (summit-to-summit) line-of-sight mode.** An S2S mode you can switch on while viewing a summit you plan to activate, showing which other summits are reachable at a chosen frequency (2 m now, microwave later, which Chris is new to and wants to consult the brain trust on). Born from the SOTA Slack thread of 2026-11-04/05 (activation zone accuracy, then S2S planning). People and what they offered:
   - **Gabriel AJ6X** built the working version: `https://aj6x.com/s2s/` (2 m at 5 W, with antenna choices, terrain-calculated for 12,689 summits worldwide) and a per-summit page example `https://aj6x.com/w6-sc-213/`. Model uses the direct path plus diffraction over obstacles (no multipath). He has all the DEM data cached and said he can rerun it for other frequencies if Chris gives him a list. His zones are downloadable at `aj6x.com/zones.geojson` (per summit `aj6x.com/w6-sc-213/zone.geojson`). His content is CC licensed: **attribution required, noncommercial** (check the exact license on his site before using anything). Signal only for now; noise is pending results from KO6KZO and KM6AM.
   - **AD8IS Mike** (author of the `sota-oh-boundaries` activation zone code, gitlab.com/roleohibachi/sota-oh-boundaries) says the diffraction code is the same for microwave as 2 m, you just change the frequency. Suggestion: either save the DEM path profile, or precompute path loss for every ham band, and expose **path loss** so the user supplies TX power, antenna gains and RX sensitivity (default to a Baofeng). Reference tool he likes: `https://waveshed.io/simulate?dev`. He also pointed Chris at Gabriel's implementation.
   - **KM6AM Badgie**: his RFI estimate could add the noise floor on top of the path loss, so the full link budget becomes "a solution exists" math. Ties into the Summit RFI integration above.
   - **Planned shape (Chris's, not designed yet):** a mode toggle on the summit page (`summit_detail.php` and possibly the public `/s/` page), built to fit the one-tool-for-everything goal: route, weather, RFI and S2S in one place.
   - **Open items:** get the frequency list for microwave bands from Chris's contacts; decide whether to consume Gabriel's data by link-out, by an agreed data export stored in our own table (cron pattern), or by embedding; agree attribution wording and license with him first. Do not scrape his site or bulk pull anything without his explicit OK (same courtesy rule as SOTA and KM6AM). Decide how Chris's defaults (TX power, antennas) are stored per user.
2. **In-site GPX editor, modeled on GPX.STUDIO (and Gaia GPS, added as a reference 2026-11-15).** Chris wants an editor mode inside SOTAplanner that works like GPX.STUDIO or Gaia GPS's route tools do (trim, split, merge, reshape tracks and save back, presumably including tracks from the Global GPX Library and users' own uploads). Chris suggested we study that project to learn from it. I have not looked at GPX.STUDIO yet: first step is to review what it does (its editing features, UI, and whether it is open source or has terms we must respect), then list the features worth copying, then agree scope with Chris. Constraints to remember: `from_global_library=1` tracks point to shared files in `gpx_files/global/` and must never be edited in place or have the physical file deleted (an edit should become the user's own copy); GPX analysis lives in `config.php`; the Global GPX Library and the SOTA Maps submission question (see the overhaul note above) may eventually connect to this. Do not copy GPX.STUDIO's code or assets without checking its license. **Use the parking and trailhead list (Chris, 2026-11-19):** the worldwide OSM list of parking lots and trailheads built for trail discovery (see "Parking and trailhead list" under Trail discovery) should feed the editor when a user creates or modifies a track: show nearby trailheads and parking as map markers, let a track's start snap to one, and suggest them as starting points. Many trailheads are a spot on a road with no parking lot, and many are not mapped at all, so the editor must still allow a start anywhere.

**Queued projects (added 2026-11-16), not started:**
1. **Icon and graphics redesign.** Chris wants the site's icons and graphics redesigned. Scope not yet defined (which icons, whether the logo is included, map markers, illustrations, one consistent icon set). Confirm scope with Chris before building. Related: the "No emoji as graphics" rule under the design system (leftover emoji in `index.php`, `planning_groups.php`, `sota_magic.php`, `summit_gpx.php` would be replaced as part of this), and the result must stay within the Alpine Precision look.

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

### Testing gotcha: Multi-Activate saves real routes
Opening `multi_activate.php?ids=...` (what the dashboard's Multi-Activate button does) immediately saves a new route on the dashboard, in the shared production database, even from staging. After an automated test, delete the test route again (POST `multi_ids=<id>` to `bulk_delete_summits.php`, which removes only the route, not its summits).

### Playwright Login
Use **staging** (christopherreddick.com/sotaplanner). Its login page has a small **"Developer access"** link in the bottom-left corner; click it, enter `KI6CR`, click **Go**. This passwordless sign-in exists on dev/staging only: `isProductionSite()` in `config.php` (host ends in `sotaplanner.com`) removes it from the production login page and rejects the POST server-side (removed 2026-10-13 after the podcast launch, because on production it let anyone log in as any callsign). Production login is SOTA SSO only.

### Branches and release flow (changed 2026-11-15 at Chris's request: up to 3 parallel features in worktrees, Claude coordinates)
History: on 2026-11-05 parallel work was stopped after lost edits, "main is already checked out" errors and hidden GitHub Desktop stashes. On 2026-11-15 Chris asked to bring it back with the structure below. `main` is exactly what is live on production. Chris never does git work himself; Claude handles all branching, worktrees, merging and deploying (see memory `feedback_avoid_branch_merge_complexity`).

**Words Chris uses:** "feature" = one piece of work (one branch, one worktree folder, one staging slot). "Start a feature: X", "Put X on staging", "What's in progress?", "Ship X", "Park X" (stop but keep), "Drop X" (delete).

1. **Main folder = production copy.** `/Users/chris/Dropbox/ham - amateur radio/sotaplanner` stays on `main` and is never used to develop a feature. Only shipping merges, CLAUDE.md and notes happen there.
2. **One worktree per feature**, inside `.claude/worktrees/` (Dropbox ignores that folder via `xattr -w com.dropbox.ignored 1 .claude/worktrees`, set 2026-11-15; re-run it if the folder is ever recreated). Create from the latest `main` with a descriptive name: `git worktree add .claude/worktrees/<feature> -b <feature> main`. Never branch from another feature branch.
3. **At most 3 features at once**, each in its own staging slot (see Staging Environment). Before starting one, run `git worktree list` and the slot check; tell Chris if all slots are busy.
4. **Helper agents** are launched from the coordinating session, each told its exact worktree path and branch and to touch nothing outside it. Claude reviews each agent's changes before they go to a slot. Agents never merge, deploy to production, or run database migrations.
5. **Only one database-changing feature at a time** (staging and production share one DB). Migrations stay additive (see below).
6. **Overlap check:** before starting a feature, name the big files it will touch (`summit_detail.php`, `index.php`, `config.php`, `multi_activate.php`). If another open feature touches the same file, tell Chris and suggest waiting or picking different work.
7. Tweaks Chris thinks of before saying a feature is tested go on that same branch. New ideas get written down, not started, when 3 features are already open.
8. **Ship X** (only when Chris says so): commit on the feature branch, then in the main folder merge it into `main` (fast-forward when possible). If `main` moved since the branch started, merge `main` into the branch first, redeploy its slot, and ask Chris for a quick recheck if a shared file changed. Rsync only changed files to production, verify the live site, then `git worktree remove .claude/worktrees/<feature>`, `git branch -d <feature>`, and mark the slot free. A tweak after shipping gets a new branch (e.g. `<feature>-2`).
9. **Claude pushes to GitHub itself** (granted by Chris 2026-11-15; git uses the `gh` CLI login). Push `main` after each shipping commit and tell Chris it's on GitHub. Feature branches may be pushed as a backup. Never force-push. Chris no longer uses GitHub Desktop; don't send him there.
10. Git lets only one folder hold a branch. If "main is already checked out" appears, run `git worktree list`; the folder holding it needs `git switch --detach` run from inside it.
11. Urgent security or outage fixes skip the queue: worktree off `main`, ship quickly, tell Chris what went live.

**Staging and production share one database and `config.php`.** So schema changes are live for production the moment they run: keep migrations additive and backward compatible (new tables or nullable columns, never renames or drops), and run them before the code that needs them ships. Staging cannot test SOTA SSO, and the cron jobs run on production only.

### Before Committing a Feature
Commit when Chris says so. Do **not** bump `APP_VERSION` per commit (see Version bumping). User-visible features get a bullet in the current month's changelog block when they are shipped to production.

### Staging Environment
- **URL:** christopherreddick.com/sotaplanner/
- **Server path:** `/home/chrisr069/christopherreddick.com/sotaplanner/`
- **Database:** Same production DB (shared, see above)
- **Deploy to staging** (from the working tree of the branch being tested; if working in a worktree, change the source path to that worktree's root):
  ```
  rsync -avz --exclude='.git' --exclude='.claude' --exclude='.playwright-mcp' --exclude='playwright' "/Users/chris/Dropbox/ham - amateur radio/sotaplanner/" dreamhost-sota:/home/chrisr069/christopherreddick.com/sotaplanner/
  ```
- **Feature staging slots (added 2026-11-15)** for parallel features: `christopherreddick.com/sotaplanner-a/`, `-b/`, `-c/` (server folders `/home/chrisr069/christopherreddick.com/sotaplanner-{a,b,c}/`). Same shared DB and secrets file (config.php finds `/home/chrisr069/sotaplanner_secrets.php` two levels up). In each slot `gpx_files` and `sota_cache.csv.gz` are symlinks to `../sotaplanner/`, so the nightly sync covers them; never let rsync replace those links. Deploy a feature to its slot from its worktree, then record which branch is there:
  ```
  rsync -avz --exclude='.git' --exclude='.claude' --exclude='.playwright-mcp' --exclude='playwright' --exclude='screenshots' --exclude='gpx_files' --exclude='sota_cache.csv.gz' --exclude='weather_cache' "<worktree path>/" dreamhost-sota:/home/chrisr069/christopherreddick.com/sotaplanner-a/
  ssh dreamhost-sota 'echo "<feature> $(date +%F)" > /home/chrisr069/christopherreddick.com/sotaplanner-a/STAGING_BRANCH.txt'
  ```
  Slot check: `ssh dreamhost-sota 'cd /home/chrisr069/christopherreddick.com && for s in a b c; do echo "$s: $(cat sotaplanner-$s/STAGING_BRANCH.txt 2>/dev/null || echo free)"; done'`. When a feature ships or is dropped, write `free` into its slot's file. Developer access login works on every slot. The plain `/sotaplanner/` staging site is for single quick tasks or a final check of combined changes before shipping.
- **Deploy to production** only after Chris approves and the branch is merged to `main`: rsync just the changed files (`git diff --name-only <old main>..main`) to `dreamhost-sota:/home/chrisr069/sotaplannerdotcom/`, from a checkout of `main`.
- **GPX files + SOTA cache** are synced from production to staging daily at 2am via `/home/chrisr069/sync_sites.sh`. No need to manually copy GPX files when testing on staging.

### Hosting & Scaling — CDN Options (researched 2026-08-25)
DreamHost Shared Hosting (what sotaplanner.com runs on) has **no built-in CDN**. If international traffic grows and page-load latency becomes a concern, the path is a free **Cloudflare** setup (DreamHost has a direct partner integration for this): create a free Cloudflare account, then point sotaplanner.com's nameservers at Cloudflare's two assigned nameservers. This gives global edge caching for static assets (CSS/JS/images/SVGs), SSL termination closer to the user, DDoS protection, and basic analytics — DreamHost remains the origin server. Note: since most of SOTAplanner's pages are dynamic PHP (DB-backed dashboard/maps), a CDN mainly speeds up static assets and connection setup, not the PHP/DB round-trip itself — if response time becomes the bottleneck under heavy load, that requires scaling the DreamHost plan or database, not just adding a CDN. Nameserver changes affect live DNS/email routing, so treat this as a change requiring Chris's explicit go-ahead, not something to do unprompted.

### Idea, not scheduled: Mapterhorn terrain tiles (researched 2026-11-04)
Chris asked whether `https://mapterhorn.com/data-access/` could help as the site discovers more of its own information. Verdict: yes, worth a small prototype. Nothing is built. I had not checked what the site currently uses for elevation when I wrote this, so compare against that first.

**What it is:** an open-data project publishing public terrain elevation tiles for web maps. Maintained by Oliver Wipfli (with Brandon Liu and Sascha Brawer sharing admin rights), funded by an NGI0 Core Fund grant (NLnet / European Commission), hosted on donated Cloudflare R2/Workers/bandwidth. Code is BSD-3 (`github.com/mapterhorn/mapterhorn`).
- **Format:** Terrarium-encoded terrain-RGB tiles as 512 px WebP, bundled in PMTiles archives. No COG seen.
- **Coverage:** global 30 m; USA country-wide 10 m with partial 1 m; UK 1 m; Austria 1 m; Switzerland 2 m (partial 0.5 m); most of Germany 1 m; France 5 m; Italy 10 m; Spain 2 m; Japan 1/5/10 m; plus Australia, Canada, Nordics, NZ and others. Exact polygons at `mapterhorn.com/coverage`.
- **Access:** single tile `https://tiles.mapterhorn.com/{z}/{x}/{y}.webp`; TileJSON `https://tiles.mapterhorn.com/tilejson.json`; area extracts with the `pmtiles` CLI (`pmtiles extract --bbox=... https://download.mapterhorn.com/planet.pmtiles out.pmtiles`; planet file is z0-z12, z13-z17 are per-tile files); full downloads at `https://download.mapterhorn.com/` (index `download_urls.json`).

**Possible uses here:** elevation profile and gain/loss for routes with no GPX (pairs with the BRouter paths project); computing our own activation-zone polygons (25 m vertical) for summits SOTLAS and activation.zone lack; slope/steepness flags to sharpen hike-time estimates; a hillshade/relief layer on the maps; higher-resolution terrain than the SRTM 30 m KM6AM uses for his RFI terrain-shadow model (worth mentioning to him).

**Open questions and cautions:**
- **License unresolved:** the pages name no data license or required attribution string. Per-source terms are in `https://download.mapterhorn.com/attribution.json` (not yet read). Read it before shipping anything, and add attribution in the UI.
- **No stated rate limits, fees or keys**, but bandwidth is donated. Fetch only the tiles a user is viewing, cache results, and never run bulk jobs against `tiles.mapterhorn.com` (same courtesy rule as SOTA and KM6AM). Confirm with the project's GitHub/privacy page if heavier use is planned.
- **DreamHost shared hosting is a poor fit for server-side use** (needs PHP GD with WebP, and hosting PMTiles is heavy). Prefer browser-side lookups: fetch a tile, draw it to a canvas, decode Terrarium (`elevation_m = R*256 + G + B/256 - 32768`) from the pixels.
- **Suggested first step:** a prototype on a feature branch showing summit elevation and a route profile from these tiles next to the current values, deployed to staging for Chris to judge.

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
Users can upload a recorded GPX track from a past activation. The app parses it to extract real-world stats: total hiking time, activation time (time spent in the activation zone), rest break time, hiking distance, elevation gain/loss, average hiking speed, and summit coordinates. These stats can be used as the authoritative hike time for that summit. The activation zone polygon overlaid on the map comes from `get_activation_zone_from_api()` in `config.php`, which tries SOTLAS's high-precision boundary first and falls back to the activation.zone API — see "External APIs Used" above. The result is permanently cached (these boundaries don't change) in the `activation_zone_cache` table, keyed by `sota_ref`, storing which source (`sotlas` or `activation_zone`) was used. This single function is shared by every caller that needs an activation zone polygon: `activation_zone.php` (summit detail map when no GPX is present), the GPX-analysis path in `config.php` (stored per-track in `gpx_tracks.activation_zone_polygon`/`activation_zone_method`), and `activation_invite.php`.

**Quiet background upgrade to SOTLAS (added 2026-09-04):** SOTLAS is still rolling out high-precision boundaries to more associations over time, so a summit cached with the `activation_zone` fallback today may have a SOTLAS boundary next month. `activation_zone_upgrade_cron.php` (weekly, Sat 4:00 AM) re-checks SOTLAS for any cache row still on the fallback and hasn't been re-checked in 30+ days (`activation_zone_cache.last_sotlas_check`), upgrading the row in place — no user action or page load needed to trigger it. Logs to the same `sota_logs/gpx_cron.log` visible in God Mode → Data Tools → Cron Activity Log.

### Shared Summit Data
When a new group is created, the owner can choose to "adopt" summit research from an existing group. The summit record gets `uses_shared_data = true` and `source_group_id` pointing to the original group. Shared summits inherit trail data, GPX tracks, and notes from the source group, so new groups don't have to re-research summits from scratch.

### Shared Multi-Activation Routes (added 2026-09-26)
A saved route's link `multi_activate.php?id=N` is public. Members of the route's dashboard get the normal editable page (plus a "Share Link" button that copies that URL); anyone else, signed out or signed in to a different dashboard, gets a read-only view. The public view deliberately hides the owner's starting address (no home marker, drive legs between summits only), the owner's other dashboard summits, the PDF export, and all edit controls, and it never auto-saves. IDs are sequential with no share token, same as `activation_invite.php`.

The large centered "Add this Multi-Summit to your own dashboard" button (above the stat tiles, public view only) goes to `multi_add_to_dashboard.php`, which stores `$_SESSION['pending_multi_import']` and sends signed-out visitors to SOTA SSO (on sotaplanner.com) or `login.php` (staging). `oauth_callback.php` carries the pending route across the SSO round trip in `app_settings` as `oauth_import_<state>` (same reason as the OAuth state itself: sessions are unreliable across the redirect in Safari). `onboarding.php` then shows existing users a "Where should this route go?" screen listing their dashboards (each with its starting location) plus a "Create a new dashboard for it instead" option; brand-new users go straight to creating one (name, then starting address; the crew step is skipped). Either way `multi_import_lib.php` copies the summits (research + GPX, not status/history) and the route into it before landing on `index.php?imported=1`. The SSO leg of this can only be tested on production.

### Multi-Activate Alternate Hike Starts (added 2026-10-08)
Reported by a beta tester (Fox Benchmark then Condor Peak, where Fox is on the trail to Condor): the tool charged a full hike down and back up between summits on the same trail. Fix: an **alternate hike start** pin belongs to an ORDERED PAIR of summits (`from_ref` > `to_ref`), never to a single summit, so solo trailhead data is untouched. Set via the "Alternate start point" button on the later summit's tile, then a map click. Effects: no drive to the later stop (car stays put), the earlier stop's hike down is dropped, the later stop's hike up starts at the pin, and the last stop of a chain still descends fully to the car.
- All logic is in `multi_alt_lib.php` (shared by `multi_activate.php` and `multi_activate_pdf.php`); `multi_alt_start_save.php` is the AJAX endpoint (set / reset / restore).
- Pins are shared ("community"): `multi_alt_starts` (unique per pair), `multi_alt_start_optouts` (a user's personal ignore), `multi_alt_start_log` (history so bad pins can be undone). The person who set a pin deletes it for everyone on Reset; anyone else just opts out. Pins more than 15 miles from the later summit are rejected.
- Hike time comes from the later stop's GPX track (pin snapped to the track, within 200 m, scaled to its recorded pace); otherwise a flagged straight-line "(est.)". The return is assumed to be the later stop's normal full descent to the car, and a stop with no hike data has no descent counted. Loop hikes (returning a different way) are not modeled.
- Public shared view shows community pins but not a viewer's opt-outs.
- **Auto-detect (built):** `multi_alt_detect()` suggests a pin via a green banner (Yes / No thanks; "No thanks" is stored as an opt-out for that pair). Forward: the previous summit is on the next summit's GPX trail (within 200 m, measured to the line between track points, and 8-97% of the way up). Return: the later summit is on the earlier summit's trail ("on the way down"); the pin sits at the later summit, which is how `multi_alt_apply()` tells the two modes apart (no schema column for it). Return mode gives the later stop no hike up and shortens the earlier stop's descent to that point.
- Gotcha: a dashboard summit only gets its community GPX linked when its summit_detail page is first opened, so a Multi-Activate route can show "Hike: -" for a summit with a library track until then.
- Not detected: loop hikes, branching trails (use the manual pin).

### Planned Activations
Users can schedule an activation for a specific summit — date, start time, activation time duration, notes. Each planned activation generates a **shareable invitation page** (no login required) that shows a timeline Gantt chart, the summit map with elevation profile, driving directions, cell coverage overlay, and safety/location sharing info. Designed to be shared with non-ham hiking partners and guests.

Planned activations can export a `.ics` calendar file and include a real-time location sharing link field (e.g. a Garmin inReach or Spot link for guests to follow along).

### Activation History
Past activations can be logged against a summit: date, callsigns of participants, notes. These are stored in the `activations` table. Once official SOTA SSO OAuth is live, the plan is to pull real past activation data from the SOTA API for each summit.

### Winter Bonus Points (added 2026-09-10)
SOTA awards bonus points (usually +3, sometimes 0) to qualifying summits during their local winter season, shown as an orange "+N" badge next to the points value on the dashboard, summit detail page, and nomination search results. Hovering the badge shows a tooltip explaining when the bonus applies.

**Data source — this is the important part:** neither the JSON API (`api2.sota.org.uk`) nor SOTLAS expose a summit's `BonusPoints` value. The only source is SOTA's official worldwide summit list CSV at `https://storage.sota.org.uk/summitslist.csv` (~24 MB, columns include `SummitCode, AssociationName, RegionName, SummitName, AltM, AltFt, Longitude, Latitude, Points, BonusPoints, ValidFrom, ValidTo, ActivationCount, ...`), which is explicitly published for third-party tool consumption (unlike the JSON API's ToS-gated docs — see "SOTA Data API" section below). `rebuild_sota_cache.php` now downloads this CSV directly instead of the old JSON API dump, so the cache rebuild is both simpler and faster (~24 MB vs ~90 MB) and gains bonus data as a side effect.

**Cache format changed:** `sota_cache.csv.gz` gained an 8th pipe-delimited field, `bonus_points`: `code|name|norm|points|alt_ft|lat|lon|bonus`. `sota_cache_helper.php`'s functions handle old 7-field cache rows gracefully (bonus defaults to 0) so this isn't a breaking change if the cache is stale.

**Season dates aren't centralized anywhere:** each of SOTA's ~200 associations sets its own winter bonus date range in its own Association Reference Manual (ARM) document — there's no single published table. Rather than fabricate false precision, the tooltip text (`winterBonusSeasonText()` in `config.php`, mirrored in JS in `nominate.php`) shows a hemisphere-based default (Dec 1 – Mar 15 for Northern, Jun 1 – Sep 15 for Southern, based on the summit's latitude) with a caveat that exact dates are association-specific.

**DB:** `summits.bonus_points` (SMALLINT, default 0), populated at nomination time via `get_sota_cache_summit($ref)['bonus']` in `nominate.php`. Existing summits were backfilled once via a disposable `backfill_bonus_points.php` script (deleted after running, per the db_migrate.php pattern).

**Keeping bonus data fresh:** re-run `rebuild_sota_cache.php?password=sota` periodically (it isn't on a cron schedule — see "Active cron schedule" above, this could be added there if it becomes stale) to pick up SOTA's periodic BonusPoints/ValidTo updates. There's currently no recurring backfill of already-nominated summits' `bonus_points` after a cache rebuild — if that matters later, recreate a small disposable script following the `backfill_bonus_points.php` pattern (join `summits.sota_ref` against the cache via `get_sota_cache_summit()`).

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
- **SOTLAS Activation Zone** (`az.sotl.as/{assoc}/{region}/{number}.geojson`) — high-precision (~1m) terrain-derived activation zone boundary, primary source as of 2026-09; returns HTTP 403 (not 404) when no polygon exists for a summit, so any non-200 is treated as "not found" and triggers the activation.zone fallback below
- **activation.zone API** — SRTM-based (lower-resolution) activation zone polygon fallback, used only when SOTLAS has no boundary for the summit
- **SOTA official summit list CSV** (`storage.sota.org.uk/summitslist.csv`) — worldwide summit list published for third-party tools; the only source for winter `BonusPoints` per summit (see "Winter Bonus Points" above). Downloaded by `rebuild_sota_cache.php` to build `sota_cache.csv.gz`.
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

**DB host must be the panel hostname, not the server name (changed 2026-10-17).** `DB_HOST` in `/home/chrisr069/sotaplanner_secrets.php` (on the server, not in git; shared by staging and production) is `mysql.ki6cr.com`. It used to be `beath.pdx1-mysql-a7-1b.dreamhost.com`. On 2026-10-16 DreamHost upgraded that server to MySQL 8.4, and afterward the server name reached a read-only copy (`@@read_only = 1`), so every write on the site failed until the host was switched. `mysql.ki6cr.com` reaches the writable server (`pdx1-mysql-a7-1a`). DreamHost's own advice is to connect by panel hostname so IP or server changes don't break the site. Never switch it back to the server name. Backup of the old file: `/home/chrisr069/sotaplanner_secrets.php.bak-20261017`. If saves ever fail again, first check `SELECT @@hostname, @@read_only` from the server. MySQL 8.4 also reserves the words MANUAL, PARALLEL, QUALIFY and TABLESAMPLE; backtick them if they appear as table or column names in SQL.

**Database backups (fixed 2026-11-21).** Cron runs `/home/chrisr069/backup_db.sh` nightly at 3:00 AM (Pacific), writing `/home/chrisr069/db_backups/sotaplanner_<date>.sql.gz` and keeping the newest 14; results go to `db_backups/backup.log` as one `OK` or `FAILED` line per night. From the October 2026 host change until 2026-11-21 the script still had the old server name and password, every night was refused, and it kept saving 20-byte empty files while deleting the good ones, so no usable backup existed. It now reads the login from `sotaplanner_secrets.php` (never hardcode it again), checks the dump is complete, and rotates only after a good backup. A good dump is about 12 MB and takes about 5 seconds. Before any big database change, run `bash /home/chrisr069/backup_db.sh` by hand and copy the file to Chris's Mac (`~/sotaplanner_db_backups/`). Old script: `backup_db.sh.bak-20261121`.

Key tables:
- `planning_groups` — groups (id, name, units, owner_callsign)
- `planning_group_members` — join table (planning_group_id, callsign)
- `summits` — one row per summit per group (or shared via source_group_id)
- `addresses` — starting addresses per group, with `lat`/`lng` (added 2026-09-27, backfilled for all 36 existing rows). Coordinates are saved when an address is added (`validateStartingAddress()` in config.php rejects anything Google's Geocoding API can't find). All drive-time code must use `addressOrigin($db, $addr)` / `addressGeo($db, $addr)`, never the raw `address` text. **Why:** Distance Matrix does its own weaker lookup of text origins and returned NOT_FOUND for "Moby's Coffee Shop N Hollywood", and even the Geocoding API found that business name one hour and returned ZERO_RESULTS the next. Street addresses and zips are consistent; business names are not.
- `activations` — logged past activations per summit
- `gpx_tracks` — uploaded/analyzed GPX files with parsed stats; `from_global_library=1` means the row points to a shared file in `gpx_files/global/`
- `summit_notes` — free-text notes per summit per user
- `users` — callsign, name, home address, lat/lng
- `user_settings` — per-user preferences (default activation time)
- `app_settings` — key-value store for per-group settings (e.g. selected address ID); also stores `sota_cache_summit_count` (total SOTA summits from gz cache, refreshed daily) used by the God Mode progress panel
- `global_gpx_tracks` — global GPX library; one row per `sota_ref` that has an imported community route; keyed globally, not per planning group; columns include `trailhead_lat` / `trailhead_lon` (note: `_lon` not `_lng`)
- `global_gpx_checked` — audit log of every summit ever queried against SOTAmaps; `tracks_found=0` means nothing was available; used by cron to avoid redundant re-queries and to schedule periodic retries
- `activation_zone_cache` — global, permanent cache of activation zone polygons keyed by `sota_ref` (PK); columns: `polygon` (GeoJSON coordinates, JSON-encoded), `source` (`sotlas` or `activation_zone`), `fetched_at`, `last_sotlas_check` (drives the weekly upgrade cron — see "GPX Track Analysis" above). Populated by `get_activation_zone_from_api()` in `config.php`

---

## Data Gathering Architecture

The app pre-populates summit data (GPX routes, trailheads, elevation/distance stats) from external community sources so that when a user nominates a summit, it already has route and planning data ready. This is a multi-layer pipeline with browser-based tools for the initial load and background cron jobs for ongoing maintenance.

### Data Sources

| Source | What it provides | API |
|---|---|---|
| **SOTA Mapping Project** | Community-submitted GPX routes | `https://api-db2.sota.org.uk/smp/gpx/summit/ASSOC/REF` (was `api-db`, dead since Nov 2026) |
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

### No emoji as graphics (rule from Chris, 2026-10-28)

Never use emoji as icons or graphics anywhere in the app (buttons, labels, stat tiles, empty states, map markers). Use inline SVG icons in the Alpine Precision style instead. Existing emoji (e.g. the mountain in `index.php`, `planning_groups.php`, `sota_magic.php`, `summit_gpx.php`) are leftovers to replace with SVG when those pages are touched.

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

**Performance rules (added 2026-10-13 after a site health audit; the database itself was fine, every query 0.4-5 ms):**
- PHP locks the session file for the whole request. Any endpoint that only reads the session and does slow work (external API calls, scanning the gz summit cache) must call `session_write_close();` right after the login check, otherwise it blocks every other request from that user. `$_SESSION` stays readable afterward; only writes are lost. Already done for `sota_refresh`, `nominate`, `gpx_fetch`, `activation_zone`, `sync_dashboard_activations`, `import_sotamaps_gpx`, `save_activation_time`, `toggle_multi_expand`, and `summit_detail`.
- Every outbound HTTP call needs a timeout (`CURLOPT_TIMEOUT` or the stream-context `timeout`). The SOTA activations and alerts caches serve stale data and back off 15 minutes if the live fetch fails.
- Sessions live in `/home/chrisr069/sota_sessions` (set in `config.php`, falls back to default if missing). The default `/tmp` is shared with every site on the DreamHost server (30k+ files, 24 minute cleanup), which could silently delete our sessions.
- Never put `ALTER TABLE` migrations in page code; use `db_migrate.php`.
- Summit search (`sota_cache_helper.php`, done 2026-10-13): `sota_cache.csv.gz` is still the source of truth (crons read it), but searches use two generated copies built by `sota_index_rebuild_from_gz()`, which `rebuild_sota_cache.php` calls automatically: the indexed `sota_summit_index` table (reference prefix, radius and single-summit lookups, about 1 to 9 ms instead of about 150 ms) and the plain-text `sota_cache_norm.txt` (keyword search via one in-memory pattern match, about 45 ms instead of about 150 ms; JIT is enabled at runtime because the host has it off). Each function falls back table, then gz scan, if a copy is missing, so staging (which gets only the gz synced) still works. Never edit these copies by hand; rerun `rebuild_sota_cache.php?password=sota`.

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

**One "update" per calendar month (changed 2026-10-13):** the public changelog has one block per month, titled like "October 2026 update". Do not create entries or numbers per release. As features ship to production during the month, append a plain-English bullet to that month's `<ul>`, newest first. Start a new block only when the first release of a new calendar month ships. The "Current" badge always lives on the top block. Older blocks keep their historical v1.x/v2.x numbers.

**Format per block:** the title (month and year plus "update"), "Current" badge on the latest only, then the bullet list (no tags, no technical jargon). Each bullet says what users can now *do* or *see*, not what changed in code.

**Keep it short (Chris, 2026-11-21: "too wordy", "still too much"):** headline features only, at most about 6 bullets a month, each a few words on one line (e.g. "Search box on the dashboard"). No how-to steps, no examples, no lists of buttons; small tweaks, renames and toggles are left out. No theme sentence under the title. Data credits stay short and inline, e.g. "Source: Summit RFI by KM6AM (link), from FCC, NOAA and USGS data"; full credits belong on the About page's Data Sources and Credits section. The whole changelog was cut to this style on 2026-11-21. List items are plain block text with the dash bullet positioned absolutely (an earlier flex layout split any linked item into columns).

## Version bumping

`APP_VERSION` in `config.php` is now the month label, format `YYYY.MM` (e.g. `2026.10`), shown in page footers as `v2026.10`. **It changes only when a new calendar month's first release ships to production.** Never bump it for individual features, fixes, cosmetic changes or refactors; those just add a changelog bullet (if user-visible) to the current month's block, or ship silently.
