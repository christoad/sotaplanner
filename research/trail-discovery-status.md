# Trail discovery: status and handoff (updated 2026-11-22)

This is the "pick up where we left off" file for BRouter trail discovery. CLAUDE.md has the short version under "Trail discovery". Read this file before continuing the work.

## 1. Goal and Chris's rules

- **Goal:** for every SOTA summit with no hike data, find likely trails and starting points with our own BRouter server. Run it on every such summit, then switch it on publicly on the day Chris presents SOTAplanner at Four Days in May (May 2027). Until then nothing discovered may be visible to the public. It is Admin Mode only.
- **Global scope (2026-11-16):** only methods that work worldwide. No country-specific data such as U.S. Forest Service MVUM.
- **No paved-road assumption (2026-11-22, replaces the 2026-11-15 "paved first" rule):** Chris said a paved road cannot be assumed to exist near every summit worldwide. Routes are now picked by confidence, not by road surface. The default is `pick = confidence` and `source = both`.
  - The confidence pick still keeps one paved start in the top 3, but only when one was found. Whether to drop that guarantee too is an open question (see section 9).
- **Up to 3 routes per summit**, each with a confidence score. Nobody reviews every summit by hand. A user who adds a discovered summit to a dashboard is told a review is required. Summits with nothing found stay "Needs Research".
- **Dirt starts** always carry the "if the road is gated or closed, park at the paved road here" fallback, when a paved point beyond it was found.
- **AWS box:** using more CPU to go faster is fine, but never run the CPU credits down. Keep them above about 300. Never mention hosting costs anywhere.
- **Credit:** OpenStreetMap contributors (ODbL) wherever parking or trailhead points are shown.

## 2. Where everything lives

| What | Where |
|---|---|
| Page (Admin Mode) | `trail_discovery.php` on production (sotaplanner.com) and staging slot A |
| Search, scoring, comparison | `trail_discovery_lib.php` (`td_discover`, `td_score_candidates`, `td_confidence`, `td_compare_known`, `td_community_evidence`) |
| Parking list client | `parking_lib.php` (`pk_near`, `pk_nearest`, `pk_cell`, `pk_version`) |
| BRouter client (two-summit Path Finder) | `brouter_lib.php`, `brouter_paths.php`, `brouter_paths_lib.php` (its old discover functions are unused) |
| Tables (shared DB) | `trail_disc_runs` (one row per test run, `settings_json`), `trail_disc_results` (one row per summit per run) |
| Route files | `/home/chrisr069/sota_discovered/runs/<run>/<REF>.json.gz` (outside the web roots) |
| Parking cache on DreamHost | `/home/chrisr069/sota_parking_cache/<version>/` (one file per map square, refreshed daily from `index.json`) |
| Research scripts | `research/trail_discovery_*.{php,py}` (section 7) |
| Box configs (copies) | `research/brouter-box/` (nginx, rate limits, status script), `research/osmindex/` (parking list build) |
| Git | branch `brouter-paths`, worktree `.claude/worktrees/brouter-paths`, staging slot A. Everything up to 2026-11-22 is merged to `main` and live. |

**BRouter box:** EC2 t4g.small, 2 GB RAM, `ki6cr-brouter`.
- SSH: `ssh -i ~/.ssh/brouter_ed25519 ubuntu@100.64.180.113` (Tailscale).
- Public proxy: `https://hikepath.sotaplanner.com`, with header `X-Api-Key` (key in `~/.ssh/brouter_api_key` and `BROUTER_API_KEY` in the server secrets).
- Paths:
  - `/brouter`: routing, 20 req/s per IP, burst 60.
  - `/parking/<i>_<j>.tsv`: parking squares, 10 req/s, burst 40. `i = floor(lat/0.2)`, `j = floor(lon/0.2)`. A 404 means there are no points in that square.
  - `/status.json`: server status, updated every 15 s, shown as the "BRouter server" bar on the page.
- nginx answers rate-limit refusals with 503.

## 3. How a summit is searched now (default settings)

1. **Search rings:** probe points at 0.75, 1.5, 2.5, 4, 6, 8 km (up to `max_km`; 10 and 12 are available) around the summit. Each probe is routed to the summit with `hiking-mountain`. The route's way tags are read from the summit outward to find where the hiking starts and what road it starts on (`td_way_class`: paved, dirt, or walk).
   - Roads closed to vehicles count as hike.
   - Mapped gates end the drivable road.
   - Ways with `sac_scale` count as trail.
2. **Mapped parking (`source` = parking or both):** every OSM parking lot and trailhead within `max_km` is routed to the summit, trailheads first, up to `TD_MAX_LOTS` (160).
   - Sites within 150 m of each other count as one.
   - The lot is the start when the walking begins within `TD_LOT_M` (400 m) of it.
   - A routed lot is always offered as a start (`$absorb`). Ring starts are added unless a lot is near.
3. **Group and refine:** starts within 150 m are grouped. The best 6 per tier are re-routed, plus up to 4 extra lot clusters.
4. **Dirt fallback:** each dirt start gets a gate-closed fallback (the nearest paved point beyond it, at most 6 extra calls).
5. **Off-trail finish:** when BRouter stops short of the summit, a straight line plus the climb to the listed height is added, with a confidence penalty.
6. **Score each route** (`td_score_candidates`, then `td_confidence`).
7. **Pick 3** (`pick`):
   - `confidence`: top 3 by confidence, with one paved start included if any was found. This is the default.
   - `mixed`: the 3 quickest.
   - `paved_first`: the old rule.
   - The result also stores `chosen_alt`, the other rule's pick, so one run compares both.
8. **Summit confidence** = 1 − product of (1 − route confidence) over the shown routes. Bands: high 70+, medium 40–69, low under 40.
9. **Community comparison:** for test summits only, our routes are compared against the community GPX track (`td_compare_known`), counting gate-closed spots too.

**Speed:** about 11–14 s per summit at about 14 requests/s (`TD_MIN_START_GAP` 0.07). Credits went from 576 to 570 over runs 11 and 12, so there is room to go faster.

## 4. Test runs so far

Test sets are summits with a community track: W6/CT (Southern California, 105 summits) and W4C/WM (western North Carolina, 90 summits).

**Run settings:**
- Run 1: first version.
- Run 2: no-way-to-top and gate fixes.
- Run 3: dirt fallback.
- Run 4: first test in North Carolina.
- Runs 5 and 6 repeat runs 3 and 4, with parking numbers added.
- All runs use 8 km, 1 km spacing and a full search.

**Column meanings:**
- **Shown at 400 m:** one of our 3 shown starts (or its gate-closed spot) is within 400 m of where the community started. This is the main yardstick.
- **Any at 400 m:** any of our candidates was, shown or not.
- **Top 3:** their route is among our shown 3 and the verdict is not "miss".

Table produced by `research/trail_discovery_compare.py`.

| Run | Region | Start points | Pick | Shown at 400 m | Any at 400 m | Top 3 | Match / partial / miss | Median s |
|---|---|---|---|---|---|---|---|---|
| 5 | W6/CT | rings | paved first | 50% | 65% | 38% | 43/53/9 | 32 |
| 6 | W4C/WM | rings | paved first | 37% | 48% | 27% | 27/34/29 | 31 |
| 7 | W6/CT | parking only | paved first | 53% | 65% | 42% | 45/44/16 | 12 |
| 8 | W4C/WM | parking only | paved first | 34% | 42% | 31% | 25/31/34 | 4 |
| 9 | W6/CT | both | paved first | 54% | 68% | 41% | 45/51/9 | 41 |
| 10 | W4C/WM | both | paved first | 40% | 49% | 33% | 28/33/29 | 11 |
| **11** | W6/CT | both, lots always offered | **confidence** | **59%** | **77%** | **65%** | 52/46/7 | 14 |
| **12** | W4C/WM | both, lots always offered | **confidence** | **53%** | **56%** | **63%** | 28/37/25 | 11 |

**Takeaways:**
- Most of the gain from run 10 to 12 is the confidence pick. The paved-first rule on the same runs 11 and 12 would give 55% and 43%.
- Runs 11 and 12 gained 13 and lost 8 summits in W6/CT (San Jacinto is among the losses), and gained 14 and lost 2 in W4C/WM, compared with runs 9 and 10.
- No community start was beyond the 8 km search distance.
- Runs 5 and 6 repeated runs 3 and 4 exactly, so the search is repeatable.

**Misses by cause (runs 9 and 10, before the confidence pick):**

| Cause | W6/CT | W4C/WM |
|---|---|---|
| (a) We found their start, but the pick rule did not show it. Mostly fixed by the confidence pick. | 17 | 9 |
| (b) A mapped lot at their start was routed, but the start moved up the road past it. Fixed by always offering lots. | 9 | 4 |
| (c) Near misses: no mapped parking there, our nearest start 0.4 to 1.5 km away. | 18 | 17 |
| (d) Nothing of ours within 1.5 km. Likely private or unmapped access. | 3 | 24 |

Example of (c): Bertha Peak W6/CT-054. Its lot route merged into the quicker Cougar Crest trailhead route 505 m away.

## 5. Confidence score

`td_confidence($c, $rank, $rel)` is a logistic model. Its label is "route start (or its gate-closed spot) within 400 m of the community start".

Current weights were fitted on runs 5 and 6 (1,164 routes, 194 summits): cross-validated AUC 0.85.

| Signal | Weight |
|---|---|
| Bias | −3.32 |
| Speed rank 0 / 1 / 2 | +1.16 / +1.09 / +0.16 |
| Drive-up | +1.00 |
| Probe hits: 1.40 × log(1 + min(hits, 200)) / 5 | +1.40 scale |
| Community evidence: start within 100 m | +1.11 |
| Community evidence: 75%+ of the hike on community tracks | +0.38 |
| Mapped parking within 150 m (trailhead adds +0.29) | +1.39 |
| Mapped parking within 400 m | +0.64 |
| Paved start | +0.06 |
| Off-trail gap over 120 m | −0.03 |
| Hike under 45 min | +0.32 |
| Longer than 2.5× the quickest (1.5× gives −0.62) | −1.14 |
| Over 4 h and over 1.2× the quickest | −1.44 |

**Known problem: the bands are inflated in runs 11 and 12.** Offering every lot as a start makes "start at mapped parking" true by construction for those routes. The medium band was right only 51% (W6/CT) and 42% (W4C/WM) of the time. Ranking still works. **Refit on runs 11 and 12 before trusting the bands** (section 7). Expect the parking weights to drop and the probe-hit and community weights to matter more.

## 6. Parking and trailhead list (done 2026-11-21)

- **Contents:** 2,989,569 OSM points within 20 km of the 172,136 active summits (2,976,718 parking, 12,851 trailheads).
- **Build:** made on the box from 320 Geofabrik extracts (`/var/lib/osmindex/`, scripts in `research/osmindex/`).
- **Serving:** 0.2° squares in `/var/lib/parking/cells/` (28,526 files, 228 MB).
- **Columns:** kind (P or T), lat, lon, osm_id, access, fee, parking, name, km to the nearest summit.
- **Refresh, a few times a year at most** (about 61 GB of downloads):
  1. Empty `out/`.
  2. Run `build_index.sh`.
  3. Run `merge_filter.py 20`.
  4. Run `split_cells.py parking_20km.tsv /var/lib/parking/cells.new`, then swap the folder.
- **Gotchas:** see CLAUDE.md ("Parking and trailhead list"). The most important rule: **always run osmium inside `systemd-run --scope -p MemoryMax=700M`**. An uncaged run hit the system-wide out-of-memory killer.

## 7. How to run, analyse and refit

1. **Start a run:** on the Trail Discovery page, open the run form and pick the region and settings. The defaults are now both start points and confidence pick.
2. **Run it in bulk from the Mac.** It needs a staging slot because developer login does not work on production. Results go to the shared database, so production shows them too.
   ```
   python3 research/trail_discovery_batch.py https://christopherreddick.com/sotaplanner-a <run> [<run> ...]
   ```
   - It resumes where it stopped and logs in again when the session expires.
   - Two runs at once from one IP are fine at the 20/s limit.
3. **Compare runs.** Save `trail_discovery.php?ajax=results&run=N` (signed in) as `rN.json`, then:
   ```
   python3 research/trail_discovery_compare.py r11.json r12.json
   ```
4. **Per-summit miss table or per-route table.** Run on the server against a slot that holds the current code:
   ```
   ssh dreamhost-sota "php /home/chrisr069/christopherreddick.com/sotaplanner-a/research/trail_discovery_misses.php /home/chrisr069/christopherreddick.com/sotaplanner-a 11 12" > misses.tsv
   ssh dreamhost-sota "php /home/chrisr069/christopherreddick.com/sotaplanner-a/research/trail_discovery_cands.php /home/chrisr069/christopherreddick.com/sotaplanner-a 11 12" > cands.tsv
   ```
5. **Refit the confidence score:**
   ```
   ssh dreamhost-sota "php .../sotaplanner-a/research/trail_discovery_extract.php /home/chrisr069/christopherreddick.com/sotaplanner-a 11 12" > routes.tsv
   python3 research/trail_discovery_fit.py routes.tsv
   ```
   Put the new weights into `td_confidence()`, then rescore saved runs without searching again:
   ```
   php research/trail_discovery_rescore.php <site dir> <run> ...
   ```
   Back up the run folders first (as done 2026-11-21: `sota_discovered/runs-{5,6}-before-rescore-20261121`).
6. **Before and after long runs,** check CPU credits in Admin Mode > AWS Server. Not more often than every 5 minutes (Mark's rule).

## 8. Gotchas learned

- Staging and production share the database. A test row written from staging is live data.
- An unknown summit is now refused, not saved as an error row.
- Deleting a row of an old run loses it. Results can only come back by re-running with today's code. On 2026-11-21 I deleted a real run 1 row by mistake.
- Old runs saved before the `pick` and `source` settings existed are read as paved first with rings (`td_run_settings`). This matters now that the defaults changed.
- Path Finder (`brouter_paths.php`) is two-summit routing only. `?mode=discover` forwards to `trail_discovery.php?ref=`.
- The box's nginx and status files have copies in `research/brouter-box/`. Edit on the box, then copy back.
- Node on the Mac is broken (missing llhttp library). Check JavaScript syntax with macOS JavaScriptCore `jsc` instead.

## 9. Next steps, in order

1. **Refit the confidence score on runs 11 and 12,** rescore those runs, and check the bands are honest again: high about 80%+ right, low clearly worse.
2. **Third region, never seen before, to check that it holds up outside the US.** Pick one with good community-track coverage and few paved roads, for example a UK or Alps association. This directly tests Chris's 2026-11-22 point.
   - Settings: both start points, confidence pick.
   - Also try one run with the paved guarantee switched off. That needs a small new `pick` option: confidence top 3, no paved rule.
3. **Fix near misses (cause c):** keep a lot or trailhead start as its own candidate even when its route merges into a quicker nearby start (Bertha Peak).
4. **Nothing-nearby misses (cause d, mostly North Carolina):** probably private roads or unmapped access. Try a car-profile route from each start to the public road network to flag `access=private`, which works globally.
5. **Storage for the full run** (not built): summary rows in the database, route shapes as files, served by a PHP page behind a launch switch in `app_settings`.
6. **Proposed public use (to agree with Chris):**
   - High: fill in hike data, labelled as discovered and unverified.
   - Medium: routes shown with "review required".
   - Low: stays Needs Research, with suggestions only.
   - Every user confirmation (a matching GPX upload, "I hiked this") becomes ground truth for refitting.
7. Later, all global:
   - Learn gates from community tracks that start partway along a dirt road.
   - Our own gate correction table.
   - Map gates in OSM, which reaches BRouter on the weekly tile refresh.
