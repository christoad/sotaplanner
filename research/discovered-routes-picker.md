# Discovered routes on the summit page: design notes (started 2026-11-24)

Chris's idea (2026-11-24). Trail discovery (BRouter, see `research/trail-discovery-status.md` on branch `brouter-paths`) computes candidate routes for summits with no hike data. When a user adds such a summit to a dashboard, the summit detail page offers the pre-computed routes and lets the user decide what is viable.

## 1. What Chris asked for

1. **Save every route computed, for every summit** (all candidates, not only the top 3), once we trust the discovery results.
2. On `summit_detail.php`, for a summit with discovered routes, show the **top 3 as options**. The user marks which look viable and picks one to use.
3. **"None of these: find more options"**: run a wider or different search for that summit.
4. **"Edit or create my own"**: hand off to the GPX editor (roadmap item "In-site GPX editor") to refine a discovered route or draw a new one.

## 2. Hard rules that apply

- **Nothing discovered is visible to the public before Four Days in May 2027.** Everything sits behind a launch switch in `app_settings` (`discovered_routes_public`, off). Before launch only God Mode sees it (beta testers only if Chris says so: they are outside users, so ask first).
- Global scope only; no country-specific data.
- Credit OpenStreetMap contributors (ODbL) wherever routes, parking or trailheads are shown. Routing by BRouter.
- Copy says "Starting Point", "Travel Time", "Dashboard". No emoji; inline SVG icons. No " — " in any text.
- Shared database: tables are additive, created with a `db_migrate_*.php` script. Only one database-changing feature at a time.
- Global GPX library rule: never edit a shared file in place. A chosen or edited route becomes the dashboard's own GPX file.

## 3. Storage (proposal)

Today each test run already saves all candidates per summit in `/home/chrisr069/sota_discovered/runs/<run>/<REF>.json.gz` (route shape with elevation, distance, climb, time, start road type, gate-closed fallback, confidence and reasons). For the real thing:

- **`discovered_routes`**: one row per route. `sota_ref`, `route_no`, `run_id`, `start_lat`, `start_lon`, `start_kind` (paved, dirt, lot, trailhead), `start_name`, `dist_m`, `gain_m`, `time_min`, `confidence`, `rank_shown` (1 to 3, null when not in the top 3), `has_closed_fallback`, `file` (shape), `created_at`.
- **`discovered_summits`**: one row per summit. `sota_ref` (PK), `run_id`, `summit_conf`, `band`, `n_routes`, `status` (found, none), `searched_at`, `settings_json`.
- **Route shapes** stay as gzip files outside the web roots (e.g. `sota_discovered/published/<REF>.json.gz`), served through a PHP endpoint that checks the launch switch or God Mode.
- **Publishing**: a God Mode action or CLI script copies a finished run into these tables ("publish run N"). A later run for the same summit replaces it; keep the old file for history.
- **`discovered_route_votes`**: `sota_ref`, `route_no`, `callsign`, `planning_group_id`, `verdict` (viable, not_viable, used), `created_at`. This is the ground truth that refits the confidence score (Chris's plan: every user confirmation counts).

## 4. Summit page flow (proposal)

Shown when the summit has discovered routes AND no hike data of its own (no uploaded GPX, no community track). Placed where the GPX / route section is today.

1. **Panel "Possible routes (computer generated, not verified)"**: map with the 3 routes in 3 colors, plus mapped parking and trailheads near each start. One card per route: starting point name or road, distance, climb, hike time, confidence (high / review), "If the dirt road is gated: park at ..." line when there is a fallback.
2. Per card: **Looks viable / Not viable** (vote, saved), and **Use this route**.
3. **Use this route**: writes a GPX file into the dashboard's own GPX folder, inserts a `gpx_tracks` row (source `discovered`), sets the starting point and hike distance, climb and time on the summit, and records a `used` vote. The summit stays flagged "review required" until the user confirms (or uploads a real track).
4. **None of these: find more options**: runs `td_discover()` again for this summit with a wider search (10 or 12 km, rings plus parking, closer probe spacing) or excluding the rejected starts. About 15 to 40 s and up to a few hundred BRouter calls, so: one at a time per user, show a progress counter, rate limit per user per day, results added to the stored routes.
5. **Edit or create my own**: opens the GPX editor with the selected route loaded (or empty, centered on the summit). Until the editor exists, this offers the existing GPX upload and "move the starting point on the map".
6. Summits where discovery found nothing keep today's "Needs Research" state, with a "Search for routes" button (same as step 4).

## 5. Confidence bands for users (finding 2026-11-24, needs Chris's decision)

Recalibrated on runs 11 / 12: about a fifth of summits score 70+ and those are right about 90% of the time. Below 70, summits are right about half the time and nothing we have tried separates "medium" from "low". Suggestion: two user-facing levels, "Likely" (70+) and "Needs review" (everything else), instead of three.

## 6. Build order (suggested)

1. Migration for the 3 tables, publish script, read endpoint gated by God Mode / launch switch.
2. Summit page panel with map, cards, votes and "Use this route" (God Mode only).
3. "Find more options" (on-demand discovery with limits).
4. "Edit or create my own" placeholder, then the real hook once the GPX editor exists.
5. God Mode view of votes; feed votes into the refit script.

## 7. Chris's answers (2026-11-24)

- **Three levels** (High 70+, Medium 40 to 69, Low under 40), not two.
- **Incubate on its own, God Mode only** (no beta testers). Chris wants to keep developing the summit page without this feature in the way. Chosen design: a plug-in panel plus a God Mode **"Discovery mode"** switch (user chip menu on the summit page, remembered per callsign in `app_settings` as `discovery_mode_<CALLSIGN>`). Switch off = the summit page is exactly as before, even for God Mode. A full clone of `summit_detail.php` was rejected (two 3,600-line copies drift apart).
- **"Use this route" fills in the hike data straight away**; the panel shows "Review required" until someone clicks "Looks right".
- **No daily limit decided.** Chris's goal: run discovery on every summit without hike data before Four Days in May, so users normally never call BRouter. "Find more options" (step 3) is for the leftovers; settle its limit when building it.

## 8. What is built (steps 1 and 2, branch `discovered-routes`, staging slot B, 2026-11-24)

- **Tables** (`db_migrate_discovered.php`, run 2026-11-24 after a database backup, deleted from the server): `discovered_summits`, `discovered_routes` (every candidate, `rank_shown` 1 to 3 for the shown ones), `discovered_route_votes` (`verdict` viable / not_viable / used / confirmed, with `run_id` + `route_no` + start point so votes survive a republish). Launch switch `discovered_routes_public` created as `0`.
- **`discovered_routes_lib.php`**: switches (`dr_public`, `dr_can_see`, `dr_mode_on`, `dr_panel_on`, `dr_menu_link`), own run file reader (never edits trail discovery), `dr_publish_run()`, `dr_render_panel()`, `dr_summit_payload()`, `dr_votes()`, `dr_route_gpx()`.
- **`discovered_publish.php`**: God Mode page listing trail discovery runs with "Publish run N"; also CLI `php discovered_publish.php --run=N` for big runs. Writes slim copies to `sota_discovered/published/<REF>.json.gz` (about 14 KB per summit). Runs 11 to 14 published (457 summits, 4,905 routes).
- **`discovered_routes.php`**: gated endpoint (God Mode or launch switch): GET routes + votes + mapped parking within 600 m of each start; POST vote / use / confirm (use and confirm need the summit on the current dashboard); `?action=mode` switch.
- **Panel** (`dr_ui.js`, `dr_ui.css`): own Leaflet map (3 colored routes, numbered starts, dashed gate-closed fallback with square marker, dashed off-trail finish, P / T parking and trailheads, the summit's existing route as a gray dotted line for comparison), 3 cards (start, round trip distance, climb, hike time with the dashboard's pace, warnings, level, "Why this score" for God Mode), Looks viable / Not viable toggles with counts, Use this route with an inline confirm. "Find more options" and "Edit or create my own" are shown disabled.
- **Summit page hooks** (3 lines in `summit_detail.php`): the `require_once`, `dr_menu_link()` in the user chip, `dr_render_panel()` just above the map.
- **"Use this route"**: writes `gpx_files/disc_<summit>_grp<group>_<time>.gpx` (route only, ascent, summit point appended for an off-trail finish), replaces the dashboard's `gpx_tracks` row (an older discovered file is deleted; uploads and library files are never deleted), sets starting point, round trip distance and climb, `gpx_source = 'discovered'`, records a `used` vote, logs to the summit change history.
- **Beta Features page (Chris, 2026-11-24):** listed under "Coming next" in `beta_features_list.php` as "Suggested routes for summits with no track" (a preview text only; the feature itself stays God Mode only).
- **Start next to a mapped lot (Chris, 2026-11-24, Waterman W6/CT-012 route 3):** the start was the picnic area at the end of a dirt track, while the mapped lot "FEE AREA" 206 m away is where the community track starts. Cause: trail discovery's dedupe merges the lot route into the slightly quicker picnic area route ("cause (c)" in the trail discovery handoff). Fixed by DEV-A on `brouter-paths` (2026-11-24, not yet on production): a mapped lot within 400 m of a quicker start whose walk joins it now becomes that start. Saved runs 11 to 16 need a re-search (not a rescore); Republished 2026-11-24 from the fresh runs 17 (W6/CT) and 18 (W4C/WM); Waterman now shows "Parking: FEE AREA" as Route 2, about 26 m from the community start. Published now: runs 17, 18, 13 (GM/SS), 14 (OE/TI). Meanwhile the card says "Mapped parking (name) N m from this starting point, not on the route" (`near_lot`, any unrestricted lot or trailhead 40 to 500 m from a road start).
- After launch the panel is meant only for summits with no hike data of their own; in Discovery mode God Mode sees it on every published summit (the test summits all have community tracks).
