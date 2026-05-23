# SOTA Planner — Project Guide for Claude

## Pending Work — Ask Chris at Session Start

**Google Maps API key — referrer restriction:**
Billing is working. Distance Matrix and Geocoding APIs are functional. The Maps JavaScript API fails with `RefererNotAllowedMapError` because the key's HTTP referrer allowlist doesn't include the site domains. At the start of the next session, ask Chris: "Ready to fix the Google Maps JS API? You need to add `*.sotaplanner.com/*` and `*.ki6cr.com/*` to the allowed referrers on the API key in Google Cloud Console (APIs & Services → Credentials → key ending in W5Fo → Application restrictions). Once done, interactive maps will work and we can remove test_maps.php."

**Track 2 — SOTA API read-only features (not yet built):**
At the start of the next session, ask Chris: "Ready to build the SOTA API read-only features? We planned to show activation history (which group members have activated a summit) and existing SOTAWatch alerts on the summit detail page. Both use the public SOTA API and don't need OAuth."

Context: VK3ARR (SOTA team) granted an SSO client for identity login. Chris sent a follow-up explaining read-only intent. Track 1 (SSO login) was completed first. Track 2 is the next step once VK3ARR replies confirming API access approach.

---

## Development Workflow

### Playwright Screenshots
Always save Playwright screenshots to the `playwright/` folder in the project root (e.g. `playwright/my-screenshot.png`). This folder is gitignored and excluded from rsync deploys — never commit screenshots.

### Branches
- **`main`** — production branch. Only merge here when a feature is tested and confirmed working.
- **`dev`** — development branch. All new work happens here.

### Staging Environment
- **URL:** christopherreddick.com/sotaplanner/
- **Server path:** `/home/chrisr069/christopherreddick.com/sotaplanner/`
- **Database:** Same production DB (shared — no schema changes without care)
- **Deploy dev to staging:**
  ```
  rsync -avz --exclude='.git' --exclude='.claude' --exclude='.playwright-mcp' --exclude='playwright' "/Users/chris/Dropbox/ham - amateur radio/sotaplanner/" dreamhost-sota:/home/chrisr069/christopherreddick.com/sotaplanner/
  ```
- After confirming on staging, merge `dev` → `main` in GitHub Desktop, then deploy to production as usual.

---


## What This Site Does

SOTA Planner is a web app for amateur radio operators who participate in **SOTA — Summits On The Air**. SOTA is an amateur radio program where operators ("activators") hike to a designated summit and operate a radio station from the top to earn points. Chasers contact activators from home. Both activators and chasers log contacts; summits have point values based on height and difficulty.

The app's core value proposition is **doorstep-to-doorstep time planning**. Activations require coordinating drive time, hike time, time on summit for radio, and the return trip. Most tools only show hiking distance and elevation — SOTA Planner brings everything together into one total time estimate so an activator can quickly judge whether a given summit fits the time they have available.

**Creator:** Christopher Reddick, KI6CR. Built for himself and the broader SOTA community. Free to use, no signup required (early access uses callsign-only login; full SOTA SSO OAuth is planned once credentials are obtained from the SOTA organization).

---

## Core Concepts

### Summits
A SOTA summit is a designated peak with a unique reference code (e.g. `W7O/NC-001`). Each summit has a points value (1–10), elevation, and geographic coordinates. The app pulls summit data from the SOTA API and SOTLAS. Users nominate summits they're interested in activating and build up research data on each one.

### Planning Groups
The primary organizational unit. A planning group is a named collection of summits, members, and starting addresses. Most users have one group; some have multiple (e.g. separate groups for different activation partners or regions). Groups are private — only visible to the owner and invited members. The group owner can add members by callsign.

Key group attributes:
- **Units**: metric or imperial (stored per group, affects all distance/elevation display)
- **Addresses**: one or more starting locations (home, trailhead area, etc.) used for Google Maps drive time calculations
- **Selected address**: the currently active address for drive time — stored in `app_settings` as `selected_address_group_{id}`
- **Default group**: users can save a preferred group via cookie (`sota_default_group`) so it loads automatically on login

### Summit Data & Research
Each summit in a group has:
- **Basic SOTA data**: name, region, points, elevation, coordinates (from SOTA API / SOTLAS)
- **Hike data**: trail distance (mi), elevation gain (ft), hike time up/down (min) — entered manually or imported from GPX
- **Drive time**: calculated from the group's selected address via Google Maps Distance Matrix API
- **Total time**: drive + hike up + activation time + hike down + drive back = full day estimate
- **Status**: Nominated, Researched, Planned, Activated — tracks progress through the planning lifecycle
- **Difficulty**: Easy / Moderate / Hard / Very Hard
- **Links**: trail link, SOTLAS link, map link, GPX link

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
| Login | `login.php` | Callsign login (early access) + SOTA SSO (when live). Returning users skip group picker and land on dashboard. |
| Dashboard | `index.php` | Main summit list for the active planning group. Filter by status/difficulty, sort columns, quick-edit activation time, nominate new summits. |
| Summit Detail | `summit_detail.php` | Full detail view for one summit: map, elevation chart, activation zone, GPX upload/analysis, activation timeline, planned activations, notes. |
| Planning Groups | `planning_groups.php` | Create/manage groups, add members, manage starting addresses, switch active group, set default group. |
| Nominate Summit | `nominate.php` | Add a summit to the current group by SOTA reference — fetches data from SOTA API. |
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

The login button on `login.php` is active when `SOTA_CLIENT_ID` is defined. Client secret is optional — omitted from token exchange if `SOTA_CLIENT_SECRET` is not defined.

---


## Auth & Login State

Currently in **early access mode**: any valid callsign (3–10 alphanumeric characters) logs in without a password. Full SOTA SSO OAuth is planned — the `oauth_callback.php` handler exists and is ready, pending official client credentials from the SOTA organization.

Login flow:
1. User enters callsign → session set
2. If returning user with groups: go straight to dashboard (last-used group from cookie, or owned group, or first group)
3. If new user (no groups): go to planning_groups.php with welcome banner

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
- `gpx_tracks` — uploaded/analyzed GPX files with parsed stats
- `summit_notes` — free-text notes per summit per user
- `users` — callsign, name, home address, lat/lng
- `user_settings` — per-user preferences (default activation time)
- `app_settings` — key-value store for per-group settings (e.g. selected address ID)

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
        <a href="planning_groups.php">Groups</a>
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

**Format per version block:** version number, month+year date, "Current" badge on the latest only, one-sentence description of the release theme, then a short plain-English bullet list (no tags, no technical jargon). Each bullet should describe what the user can now *do* or *see*, not what changed in the code.

## Version bumping

Only bump `APP_VERSION` in `config.php` (and add a changelog entry) when the commit contains a new feature or a meaningful UX improvement. **Do not bump the version for cosmetic changes, design polish, dead-code removal, or internal refactors.** Those ship silently under the current version number.
