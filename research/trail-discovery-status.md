# Trail discovery: status and handoff (updated 2026-11-26)

This is the "pick up where we left off" file for BRouter trail discovery. CLAUDE.md has the short version under "Trail discovery". Read this file before continuing the work.

## 1. Goal and Chris's rules

- **Goal:** for every SOTA summit with no hike data, find likely trails and starting points with our own BRouter server. Run it on every such summit, then switch it on publicly on the day Chris presents SOTAplanner at Four Days in May (May 2027). Until then nothing discovered may be visible to the public. It is God Mode only.
- **Global scope (2026-11-16):** only methods that work worldwide. No country-specific data such as U.S. Forest Service MVUM. **And no region-specific rules (Chris, 2026-11-27):** a pattern found in one region gets a general fix tested on all regions, unless a geographic special case is truly unavoidable.
- **No paved-road assumption (2026-11-22, replaces the 2026-11-15 "paved first" rule):** Chris said a paved road cannot be assumed to exist near every summit worldwide. Routes are now picked by confidence, not by road surface. The default is `pick = confidence_any` (since 2026-11-24) and `source = both`.
  - The confidence pick (`confidence`) still keeps one paved start in the top 3 when one was found. `confidence_any` (added 2026-11-24) drops that guarantee. Tested on runs 13 to 18: the guarantee changes results by about 2 points either way. **Chris, 2026-11-24: no paved rule; `confidence_any` is the default.**
- **Three confidence levels stay** (Chris, 2026-11-24): high, medium, low, even though medium and low are right about equally often today (section 5).
- **Up to 3 routes per summit**, each with a confidence score. Nobody reviews every summit by hand. A user who adds a discovered summit to a dashboard is told a review is required. Summits with nothing found stay "Needs Research".
- **Dirt starts** always carry the "if the road is gated or closed, park at the paved road here" fallback, when a paved point beyond it was found.
- **AWS box:** using more CPU to go faster is fine, but never run the CPU credits down. Keep them above about 300. Never mention hosting costs anywhere.
- **Credit:** OpenStreetMap contributors (ODbL) wherever parking or trailhead points are shown.

## 2. Where everything lives

| What | Where |
|---|---|
| Page (God Mode) | `trail_discovery.php` on production (sotaplanner.com) and staging slot A |
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
   - `confidence_any`: top 3 by confidence, no paved rule. **The default since 2026-11-24.** Alternate stored: `confidence`.
   - `confidence`: top 3 by confidence, with one paved start included if any was found (default 2026-11-22 to 24).
   - `mixed`: the 3 quickest.
   - `paved_first`: the old rule.
   - The result also stores `chosen_alt`, the other rule's pick, so one run compares both.
8. **Summit confidence** (`td_summit_conf`, since 2026-11-24) = logistic of the best and second best shown route scores: z = 0.91 + 0.31 logit(best) + 0.50 logit(second). Bands: high 70+, medium 40–69, low under 40. (Before: 1 − product of misses, which put almost every summit in medium.)
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
| 13 | GM/SS (Scotland) | both | confidence, no paved rule | 47% | 59% | 49% | 62/87/43 | 14 |
| 14 | OE/TI (Tyrol) | both | confidence, no paved rule | 41% | 56% | 46% | 27/37/16 | 20 |
| **15** | W6/CT | both, lot fix, retry | confidence, no paved rule | **65%** | 77% | 68% | 52/46/7 | 16 |
| **16** | GM/SS | both, lot fix, retry | confidence, no paved rule | **51%** | 60% | 51% | 63/87/42 | 13 |
| **17** | W6/CT | both, lot fix step 2 | confidence, no paved rule | **67%** | 77% | 68% | 52/46/7 | 16 |
| 18 | W4C/WM | both, lot fix step 2 | confidence, no paved rule | 54% | 56% | 64% | 28/37/25 | 13 |
| 19 | LA/OL (Norway, Oppland) | both, lot fix step 2 | confidence, no paved rule | 51% | 68% | 63% | 25/25/7 | 12 |
| 20 | LA/BU (Norway, Buskerud) | both, lot fix step 2 | confidence, no paved rule | 58% | 71% | 60% | 19/21/5 | 13 |

**Norway, runs 19 and 20 (2026-11-26, chosen because LB8VA activates most there; out of sample, weights not refit):** better than Scotland and Tyrol. "Any at 400 m" is 68 to 71%, so the gap to "shown" (about 17 points) is mostly ranking. Of the 47 misses: 17 found but not shown (in 9 the right start is a mapped lot reached only by its own route, 1 probe hit, typically 1.4 to 4x slower than the start we picked, which in 15 of 17 was a gravel forest road start), 23 near misses (0.4 to 1.5 km, mostly no mapped parking at their start), 7 nothing within 1.5 km. In 46 of 47 misses their start is not on any shown route (a different trail, not "parked lower on the same road"; in Scotland run 16 that was 17 cases). **Car check test (all six regions, 1,395 dirt starts with a paved fallback, car-fast from the start to its paved spot):** 77 (6%) have no car route at all (OSM access closes them, which `td_way_class` misses because BRouter's hiking profile reads fewer car tags), and those are right 10 to 18% of the time against 17 to 31% for car-reachable ones; 40 of them were shown (7 right). Example: Gyrihaugen LA/BU-027 showed a 5 min "drive-up" no car can reach; people hike 133 min from the Feristen lot. Toll booths (`barrier=toll_booth`, Norwegian bomvei) were on 19 Norwegian dirt starts and 7 in Tyrol, too few to judge. Scripts used were ad hoc (scratchpad); the car check is about 1 call per dirt start.

**Car check built (2026-11-27, Chris asked for it; setting `car_check`, default on for new runs, `td_car_check()` in the lib).** For each dirt start that is not a mapped lot, BRouter `car-fast` routes from the start to the paved road (the gate-closed spot, then up to two nearest paved starts when a spot answers "target island"). "no track found" = no car can get there: the route becomes the hike from the gate-closed spot (`car_blocked` keeps the old start, shown on the page), or is dropped when there is no usable spot. Applied to the saved runs without searching again (`research/trail_discovery_carcheck.php`, writes NEW runs, old runs untouched):

| Region | Old run, shown at 400 m | Car check run, shown at 400 m | Gains / losses (summits) |
|---|---|---|---|
| W6/CT | 17: 67% | 22: 69% | 2 / 0 |
| W4C/WM | 18: 54% | 23: 54% | 0 / 0 |
| GM/SS | 16: 51% | 21: 51% | 2 / 2 |
| OE/TI | 14: 44% | 24: 41% | 0 / 2 |
| LA/OL | 19: 51% | 26: 54% | 2 / 0 |
| LA/BU | 20: 58% | 25: 58% | 0 / 0 |

Net +3 of 569 summits; shown routes under 20 min fell only from 105 to 101; about 1 s more per summit. Example fixes: W6/CT-170 (a "0 min" drive-up on a closed road became a 2 h hike from the paved road), W6/CT-266, LA/OL-167, Gyrihaugen LA/BU-027 (the 5 min start is gone; Feristen still not shown). Losses are partly re-ranking side effects (GM/SS-029, OE/TI-553), partly closed-looking starts people do use (GM/SS-125, OE/TI-376). **Gotcha:** `car-fast` treats every `highway=track` without a motor vehicle tag as closed unless `profile:use_offroad=1` (value must be 1, `true` gives HTTP 500). Turning offroad on made the check worse (the 16 starts it let back in were right 1 time in 16), so the default strictness is kept. Verdict: a small, cheap, mostly honest improvement, not a big lever; the ceiling is still that on about 30 to 45% of summits none of our candidates is at the real start.

Runs 11 to 14 were rescored on 2026-11-24 with the four-region weights (in sample, so a little flattering): shown at 400 m 62% / 56% / 51% / 44%. Runs 15 and 16 were searched with those weights. Backups before rescoring: `sota_discovered/runs-{11..14}-before-rescore-20261124` and `rows-11-12-before-rescore-20261124.tsv`. Run 14 lost 9 summits to BRouter timeouts (section 8) and 15 OE/TI summits are retired.

**Takeaways:**
- Most of the gain from run 10 to 12 is the confidence pick. The paved-first rule on the same runs 11 and 12 would give 55% and 43%.
- Runs 11 and 12 gained 13 and lost 8 summits in W6/CT (San Jacinto is among the losses), and gained 14 and lost 2 in W4C/WM, compared with runs 9 and 10.
- No community start was beyond the 8 km search distance.
- **Outside the US (2026-11-24) results are lower:** Scotland 47 to 51%, Tyrol 41 to 44%, and "any candidate at their start" is only 56 to 60%, so in those regions our candidates often do not include the real start at all.
- **Paved guarantee:** with or without it, shown at 400 m differs by at most 2 points in runs 13 to 16 (the "alt rule" column of `research/trail_discovery_report.php`).
- **Lot fix, two steps (2026-11-24).** (1) A mapped lot keeps its own route when its walk joins a quicker road or trail start's trail (run 15: W6/CT 62% to 65%, run 16 GM/SS unchanged). (2) After run 15, from DEV-B / Chris: Waterman W6/CT-012 showed the picnic area start while people park at the "FEE AREA" lot 206 m away; as its own route the lot had 1 probe hit and scored 9. Now, when a lot is within `TD_LOT_M` (400 m) of such a start (within 150 m, or route overlap 85%+), the start moves to the lot and keeps both probe counts (Waterman: FEE AREA route 33%, shown). Step 2 full runs: run 17 W6/CT 67% (best so far; Waterman now 39 m), run 18 W4C/WM 54% (rescored run 12: 56%, in sample). Run 17 is the run DEV-B republishes for the summit page picker. Bertha Peak W6/CT-054: people park at an unnamed lot 52 m from their start; it merges with the Cougar Crest Trailhead lot 494 m away (two lots, same trail, overlap rule) and the named trailhead is kept. Left as is: a reasonable answer just outside the 400 m yardstick. Raising the extra lots re-routed (`TD_EXTRA_LOTS`) from 4 to 8 did not help.
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

**Refitted 2026-11-24 on runs 11 to 14** (457 summits in W6/CT, W4C/WM, GM/SS, OE/TI; 4,905 routes; fit variant `v3` in `research/trail_discovery_fit.py`). Cross-validated route AUC 0.78. Fitted on the other three regions it scores GM/SS 0.77, OE/TI 0.72, W4C/WM 0.81, W6/CT 0.76, so route ranking travels reasonably well.

| Signal | Weight |
|---|---|
| Bias | −3.70 |
| Speed rank 0 / 1 / 2 | +1.19 / +1.13 / +0.56 |
| Drive-up (dirt road to the summit) | +1.07 |
| Probe hits: 1.73 × log(1 + min(hits, 200)) / 5 | +1.73 scale |
| Start first found at the 0.75 km ring / 1.5 km ring / 4 km or more | +0.40 / +0.16 / −0.39 |
| Mapped trailhead (lot kind T) | +0.48 |
| Lot no ring probe reached (hits ≤ 1) | +0.14 |
| SAC 3+ trail | +0.23 |
| Community evidence: start within 100 m / 75%+ on community tracks | +0.98 / +0.22 |
| Mapped parking within 150 m / within 400 m | +1.64 / +1.28 |
| Paved start | −0.35 |
| Off-trail gap over 120 m | −0.34 |
| Hike under 45 min | +0.02 |
| Longer than 1.5× / 2.5× the quickest | +0.07 / −0.27 |
| Over 4 h and over 1.2× the quickest | −0.57 |

**Summit score is the weak part (finding 2026-11-24).** `research/trail_discovery_summit_fit.py` re-picks the shown 3 with cross-validated route scores and compares ways to combine them. Summit AUC is only about 0.64 whatever the method. With the calibrated formula (section 3, step 8): high (about 12% of summits) was right 80% of the time overall, 95 to 100% in the US regions, but only about 60% in GM/SS and OE/TI. Below 70, summits are right about half the time, and no signal tried (consensus of probe hits, community tracks nearby, trailhead shown, named lot, number of candidates, fastest time, far start) separates medium from low. The ceiling: on about 40% of summits none of our candidates is at the community start at all, and nothing we measure predicts which summits those are. **So the score must not be used alone to fill in hike data;** user choice (the summit page picker, `research/discovered-routes-picker.md`) is what turns candidates into answers, and the votes it collects are the labels for the next refit.

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
3. **One-line report per run** (shown at 400 m, alternate rule, top 3, bands):
   ```
   ssh dreamhost-sota "cd /home/chrisr069/christopherreddick.com/sotaplanner-a && php research/trail_discovery_report.php . 15 16"
   ```
   Creating runs from the Mac: POST JSON settings to `trail_discovery.php?ajax=new_run` after the developer login (same as the batch script).
4. **Compare runs.** Save `trail_discovery.php?ajax=results&run=N` (signed in) as `rN.json`, then:
   ```
   python3 research/trail_discovery_compare.py r11.json r12.json
   ```
5. **Per-summit miss table or per-route table.** Run on the server against a slot that holds the current code:
   ```
   ssh dreamhost-sota "php /home/chrisr069/christopherreddick.com/sotaplanner-a/research/trail_discovery_misses.php /home/chrisr069/christopherreddick.com/sotaplanner-a 11 12" > misses.tsv
   ssh dreamhost-sota "php /home/chrisr069/christopherreddick.com/sotaplanner-a/research/trail_discovery_cands.php /home/chrisr069/christopherreddick.com/sotaplanner-a 11 12" > cands.tsv
   ```
6. **Refit the confidence score:**
   ```
   ssh dreamhost-sota "php .../sotaplanner-a/research/trail_discovery_extract.php /home/chrisr069/christopherreddick.com/sotaplanner-a 11 12" > routes.tsv
   python3 research/trail_discovery_fit.py routes.tsv final v3
   python3 research/trail_discovery_summit_fit.py routes.tsv v3
   ```
   The rescore script now also re-picks the shown routes and redoes the community comparison (`td_result_row()`, shared with the page).
   Put the new weights into `td_confidence()`, then rescore saved runs without searching again:
   ```
   php research/trail_discovery_rescore.php <site dir> <run> ...
   ```
   Back up the run folders first (as done 2026-11-21: `sota_discovered/runs-{5,6}-before-rescore-20261121`).
7. **Before and after long runs,** check CPU credits in God Mode > AWS Server. Not more often than every 5 minutes (Mark's rule).

## 8. Gotchas learned

- Staging and production share the database. A test row written from staging is live data.
- An unknown summit is now refused, not saved as an error row.
- Deleting a row of an old run loses it. Results can only come back by re-running with today's code. On 2026-11-21 I deleted a real run 1 row by mistake.
- Old runs saved before the `pick` and `source` settings existed are read as paved first with rings (`td_run_settings`). This matters now that the defaults changed.
- Path Finder (`brouter_paths.php`) is two-summit routing only. `?mode=discover` forwards to `trail_discovery.php?ref=`.
- The box's nginx and status files have copies in `research/brouter-box/`. Edit on the box, then copy back.
- Node on the Mac is broken (missing llhttp library). Check JavaScript syntax with macOS JavaScriptCore `jsc` instead.
- **BRouter's thread-priority watchdog (found 2026-11-24).** BRouter runs 3 threads; when a request arrives and all are busy, it stops the longest running route ("operation killed by thread-priority-watchdog after N seconds", HTTP 400). Slow routes lose every time when two runners share the box. `td_route_many` now retries such routes one at a time after the rest (at most `TD_SLOW_MAX` 12 per batch, 20 s each, `TD_SLOW_BUDGET` 40 s).
- **Big glaciated Alpine peaks time out even alone.** Großvenediger OE/TI-003: every route from 6 km out hits BRouter's 60 s limit (`maxRunningTime=60`) with nothing else running; routes from the 0.75 km ring work. 9 of 80 OE/TI summits got no start for this reason (OE/TI-003, 005, 120, 262, 423 among them). Not fixed: ideas are routing to an intermediate point found by the inner rings, or a profile that allows glacier ways.
- **Retired summits:** 15 OE/TI and 2 GM/SS summits with community tracks are no longer in the SOTA list; the batch skips them ("Unknown summit") and stores nothing.

## 9. Next steps, in order

1. **Ship to production** what is on slot A (branch `brouter-paths`, committed 2026-11-24, not merged to `main`): new weights, calibrated summit score, `confidence_any`, lot fix, watchdog retry, `td_result_row()`, report script. Only when Chris says so.
2. **Raise "any candidate at their start" outside the US** (Scotland 60%, Tyrol 56%). Look at run 16 and 14 misses (`trail_discovery_misses.php`): likely lay-bys and roadside verges not mapped as parking in Scotland, and valley villages, huts and lifts in the Alps.
3. **Big glaciated peaks** (section 8): route to an intermediate point from the inner rings, or a glacier-tolerant profile, so they stop timing out.
4. **Nothing-nearby misses (cause d, mostly North Carolina):** car-profile route from each start to the public road network to flag `access=private`.
5. **Storage and the summit page picker:** designed in `research/discovered-routes-picker.md` (Chris's idea, 2026-11-24), to be built as its own feature `discovered-routes` in slot B. It reads the run files; it should not need changes in `trail_discovery_lib.php`.
6. Later, all global: learn gates from community tracks that start partway along a dirt road; our own gate correction table; map gates in OSM.
