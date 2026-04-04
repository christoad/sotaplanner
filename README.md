# SOTA Planner

A web application for planning [Summits on the Air (SOTA)](https://www.sota.org.uk/) activations. Helps amateur radio operators research summits, analyze GPX tracks, coordinate group hikes, and share activation invitations with guests.

Live at **[sotaplanner.com](https://sotaplanner.com)** · also mirrored at **[ki6cr.com/sotaplanner](https://www.ki6cr.com/sotaplanner)**

---

## Features

- **Summit nomination & research** — search the SOTA database, nominate summits to your planning group, track status from Nominated → Researched → Ready → Activated
- **GPX track analysis** — upload a recorded hike or route file; the app calculates hiking time, activation time, rest breaks, hiking speed, elevation gain, and distance. Recorded tracks (with timestamps) get precise stats; route-only files use Naismith's rule for time estimates
- **Activation zone overlay** — queries the [activation.zone API](https://activation.zone) to draw the precise terrain-based activation zone polygon on the map; falls back to a 50 m radius if unavailable
- **Multi-group support** — multiple planning groups can share the same summit database; groups can inherit trail research from each other while keeping their own settings
- **Drive time calculation** — calculates round-trip drive time from any saved home address to the trailhead via Google Maps
- **Activation invitations** — generates a shareable invite page for guests with a timeline, map, elevation profile, activation zone, cell coverage overlay, and a "Get Directions" feature
- **Planned activations** — schedule upcoming activations with `.ics` calendar export
- **SOTA Maps integration** — import community GPX tracks directly from SOTA Maps
- **SOTLAS integration** — pull trail data from SOTLAS

---

## Tech Stack

- **Backend:** PHP 8 with PDO
- **Database:** MySQL (hosted on DreamHost)
- **Maps:** Leaflet.js with OpenStreetMap, OpenTopoMap, and Esri satellite tiles
- **Elevation profiles:** HTML5 Canvas
- **APIs used:**
  - [activation.zone](https://activation.zone) — activation zone polygons
  - [SOTA API](https://api2.sota.org.uk) — summit data
  - [SOTA Maps](https://sotamaps.org) — community GPX tracks
  - Google Maps — drive time and geocoding

---

## Project Structure

```
sotaplanner/
├── config.php              # DB connection, GPX analysis, helper functions
├── index.php               # Dashboard — summit list, group/address selection
├── nominate.php            # Add a summit to the planning group
├── summit_detail.php       # Full summit detail, GPX upload, map, planning
├── load_gpx.php            # GPX file processor (called by summit_detail)
├── activation_invite.php   # Public-facing activation invitation page
├── activation_ics.php      # .ics calendar file generator
├── admin.php               # Admin panel (excluded from ki6cr mirror)
├── manage_addresses.php    # Home base address management
├── choose_group.php        # Planning group switcher
├── user_settings.php       # Per-user preferences
├── trail_research.php      # Trail research helper
├── sotlas_integration.php  # SOTLAS data fetch
├── import_sotamaps_gpx.php # SOTA Maps GPX import
├── field_help.php          # Inline help text
├── sync_sites.sh           # Server-side cron script to sync both deployments
└── gpx_files/              # Uploaded GPX files (not tracked in git)
```

---

## Setup

1. **Clone the repo** into your web root
2. **Create `sotaplanner_secrets.php`** one directory above the web root (never committed):
   ```php
   <?php
   define('DB_HOST', 'your-db-host');
   define('DB_NAME', 'sotaplanner');
   define('DB_USER', 'your-db-user');
   define('DB_PASS', 'your-db-password');
   define('GOOGLE_MAPS_API_KEY', 'your-key');
   ```
3. **Import the database schema** (schema file not included in repo — contact repo owner)
4. **Create the `gpx_files/` directory** and make it writable by the web server

---

## Deployment

The app runs on two domains from the same DreamHost account:
- `sotaplannerdotcom/` — primary
- `ki6cr.com/sotaplanner/` — mirror (excludes `admin.php`)

`sync_sites.sh` is a cron script that syncs GPX uploads from ki6cr → sotaplanner, then syncs the full site sotaplanner → ki6cr.

---

## License

Personal/private project. Not currently open for public contributions.
