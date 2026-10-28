# Public summit pages and queued ideas (noted 2026-10-27)

## Queued idea: alternate destination and return-trip pulldown on summit detail

Chris wants `summit_detail.php` to let the activator pick a different place to end the day than where they started, via a pulldown for the return trip (for example a different saved starting address, or another point), so Travel Time (RT) and the time bar reflect a one-way or point-to-point outing. Nothing is built or designed yet.

Questions to settle first:
- What the pulldown lists (the dashboard's saved starting addresses plus a custom point?).
- Whether it is stored per summit or per planned activation.
- How it interacts with the Multi-Activate alternate hike starts (`multi_alt_lib.php`) and the Distance Matrix calls (`calculateDriveTime()`, `addressOrigin()`).
- Whether the activation invite page and PDF should show the alternate return.

Ask Chris before starting.

## Public summit pages (shipped 2026-10-27)

Every SOTA summit has a public, read-only page at `/s/<designator>-<name>` (for example `/s/w6-ct-012-waterman-mtn`), no login and no "share" step. Only the designator matters when resolving (`/s/w6-ct-012` works). The name part is cosmetic and abbreviated (mountain to mtn, peak to pk, and so on, see `shareMakeSlug()`). `.htaccess` holds the one rewrite rule; the app also answers at `summit_share.php?s=<slug>`.

Files:
- `summit_share.php` (the page)
- `summit_share_data.php` (public feeds by slug: gpx, weather, resources, alerts, activations, zone)
- `share_lib.php` (slug, `sharePublicSummit()`, `importSharedSummitIntoGroup()`)
- `share_save.php` ("Save this summit"), `share_login.php` ("Login" that returns to the page)
- `nominate_lib.php` (`_link_global_gpx()` and `_maybe_set_researched()`, moved out of `nominate.php` so both use them)
- The Share button on `summit_detail.php` just copies the page's URL.
- `summit_forecast.php` is now public, but signed-out visitors only get real SOTA summits using SOTA's own coordinates (not an open weather proxy).

Data: SOTA summit list cache for basics, plus community research chosen the same way `nominate.php` picks a source summit (trail link, hike stats, difficulty, starting point, cell service), plus the global GPX library. It deliberately shows nothing about the dashboard or person behind the research: no owner, address, travel time, private notes, status, planned trips, history or edit controls. A sharer's own GPX filename is never shown; only library routes get the Community Route card.

Save flow: `pending_summit_import` (a SOTA ref) and `pending_share_return` (a slug) live in the session and are carried across SSO in `app_settings` as `oauth_share_<state>` JSON. `onboarding.php` handles both the pick-a-dashboard and new-dashboard paths, then lands on `index.php?summit_saved=1&new_ids=<id>` (orange row highlight). SSO only works on production, so staging falls back to the developer login; the SSO leg of Save and Login was never tested end to end before shipping.

Other notes:
- The `shared_summits` table (from the first design, which needed an explicit share) is now unused; `db_migrate.php` created it. Leave it, or drop it in a later cleanup.
- Left off the public page on purpose: travel time and starting address, status pipeline, private notes, planned activations and history, GPS and hike-time settings, GPX upload and swap, right-click set-starting-point, driving route. Restored at Chris's request: designator copy, expand map, Activation Zone zoom, Download GPX, track type, full forecast link, Last Activated, editable Summit Time.
- Possible follow-up: rate-limit the anonymous Last Activated lookup, which can trigger SOTA API calls on a cold cache.
