<?php require_once 'config.php';
session_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Changelog — SOTA Planner</title>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;1,9..40,400&display=swap" rel="stylesheet">
    <style>
    /* === Alpine Precision Design System === */
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    html { font-size: 16px; -webkit-font-smoothing: antialiased; }

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
      --surface:       #FFFFFF;
      --border:        #E5E2DA;
      --border-2:      #D4D0C8;
      --font-sans:     'DM Sans', system-ui, sans-serif;
      --r-sm: 4px; --r-md: 8px; --r-lg: 12px; --r-xl: 16px;
      --sp-1: 0.25rem; --sp-2: 0.5rem; --sp-3: 0.75rem; --sp-4: 1rem;
      --sp-5: 1.25rem; --sp-6: 1.5rem; --sp-8: 2rem;
      --shadow-sm: 0 1px 3px rgba(28,27,25,0.07), 0 1px 2px rgba(28,27,25,0.05);
      --shadow-md: 0 4px 12px rgba(28,27,25,0.08), 0 2px 4px rgba(28,27,25,0.05);
    }

    body {
      font-family: var(--font-sans);
      background: var(--bg);
      color: var(--ink);
      line-height: 1.5;
      min-height: 100vh;
    }

    /* ── Topbar ── */
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
      display: flex;
      align-items: center;
      gap: var(--sp-3);
      text-decoration: none;
      color: var(--ink);
      font-weight: 600;
      font-size: 0.95rem;
      letter-spacing: -0.01em;
      flex-shrink: 0;
    }
    .topbar-logo:hover { text-decoration: none; color: var(--ink); }
    .topbar-logo .logo-mark {
      width: 32px; height: 32px;
      display: flex; align-items: center; justify-content: center;
      flex-shrink: 0;
    }
    .topbar-divider { width: 1px; height: 20px; background: var(--border); flex-shrink: 0; }
    .topbar-right {
      display: flex; align-items: center; gap: var(--sp-3);
      margin-left: auto; flex-shrink: 0;
    }
    .topbar-nav {
      display: flex; align-items: center; gap: var(--sp-1);
    }
    .topbar-nav a {
      color: var(--ink-3);
      font-size: 0.875rem; font-weight: 500;
      padding: var(--sp-2) var(--sp-3);
      border-radius: var(--r-sm);
      transition: color 0.15s, background 0.15s;
      text-decoration: none; white-space: nowrap;
    }
    .topbar-nav a:hover { color: var(--ink); background: var(--bg-2); }

    /* ── Page ── */
    .page {
      max-width: 760px;
      margin: 0 auto;
      padding: var(--sp-8) var(--sp-8) 5rem;
    }

    .page-header {
      margin-bottom: var(--sp-8);
    }
    .page-header h1 {
      font-size: 1.5rem;
      font-weight: 600;
      color: var(--ink);
      margin-bottom: 0.25rem;
    }
    .page-header p {
      font-size: 0.875rem;
      color: var(--ink-3);
    }

    /* ── Version blocks ── */
    .version-block {
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: var(--r-lg);
      padding: 1.5rem;
      margin-bottom: 1rem;
      box-shadow: var(--shadow-sm);
    }
    .version-block.current {
      border-color: var(--accent-border);
      background: var(--accent-bg);
    }

    .version-header {
      display: flex;
      align-items: center;
      gap: 0.75rem;
      margin-bottom: 0.6rem;
      flex-wrap: wrap;
    }
    .version-number {
      font-size: 1rem;
      font-weight: 600;
      color: var(--ink);
    }
    .version-date {
      font-size: 0.8rem;
      color: var(--ink-3);
    }
    .version-badge {
      font-size: 0.68rem;
      font-weight: 600;
      background: var(--green);
      color: #fff;
      border-radius: 20px;
      padding: 0.15rem 0.55rem;
      text-transform: uppercase;
      letter-spacing: 0.05em;
    }

    .version-desc {
      font-size: 0.875rem;
      color: var(--ink-2);
      margin-bottom: 0.85rem;
      line-height: 1.55;
    }

    .version-block ul {
      list-style: none;
      padding: 0;
      display: flex;
      flex-direction: column;
      gap: 0.45rem;
    }
    .version-block li {
      font-size: 0.855rem;
      color: var(--ink-2);
      line-height: 1.55;
      display: flex;
      align-items: baseline;
      gap: 0.5rem;
    }

    /* ── Bullet list ── */
    .version-block li::before {
      content: "–";
      color: var(--ink-4);
      flex-shrink: 0;
    }

    /* ── Footer ── */
    footer {
      text-align: center;
      padding: 1.5rem 1rem;
      color: var(--ink-4);
      font-size: 0.78rem;
    }
    footer a { color: var(--ink-4); text-decoration: none; }
    footer a:hover { text-decoration: underline; }

    @media (max-width: 600px) {
      .topbar { padding: 0 var(--sp-4); }
      .page { padding: var(--sp-6) var(--sp-4) 4rem; }
    }
    </style>
</head>
<body>

<nav class="topbar">
    <a href="index.php" class="topbar-logo">
        <span class="logo-mark">
            <img src="sota-planner-logo.svg" width="32" height="32" alt="">
        </span>
        <span>SOTAplanner</span>
    </a>
    <div class="topbar-divider"></div>
    <div class="topbar-nav">
        <a href="index.php">← Dashboard</a>
    </div>
</nav>

<div class="page">

    <div class="page-header">
        <h1>Changelog</h1>
        <p>Release history and update notes for SOTA Planner</p>
    </div>

    <!-- August 2026 (v1.5.8-v1.6.0) -->
    <div class="version-block current">
        <div class="version-header">
            <span class="version-number">v1.7.1</span>
            <span class="version-date">August 2026</span>
            <span class="version-badge">Current</span>
        </div>
        <p class="version-desc">Live activation syncing with the SOTA API, a new Community Growth page, and travel terminology that works for transit trips too.</p>
        <ul>
            <li>Nominating summits now opens straight to Bulk Search by Area — search a location and add every summit in range at once; the name/reference search is still there as a second tab</li>
            <li>Summits marked Drive Up now show their real travel time on the dashboard map, instead of a "+ Research" flag they don't need since there's no hike to research</li>
            <li>New "Community Growth" page (in the top nav) shows every summit with community trail data plotted on a world map, plus a chart of how that number has grown over time — including a callout marking the big June 2026 SOTA Mapping Project import</li>
            <li>The dashboard map now has a Map / Topo / Satellite toggle, same as the summit detail map, so you can switch base layers when scanning your summit list</li>
            <li>Opening your dashboard now quietly checks the SOTA activation record in the background — if a summit your team already activated is still showing as Ready, the row fades from green to grey and picks up the activation date automatically, no page reload needed</li>
            <li>Summit detail now shows "Ready to Activate" instead of just "Ready" on the status buttons</li>
            <li>"Trailhead" is now "Starting Point" throughout the app — it could be a trailhead, a parking lot, or a transit stop, wherever your journey to the summit begins</li>
            <li>"Drive Time" is now "Travel Time," and the "Directions" links to Google Maps no longer force driving mode — Google will offer transit, walking, and driving options, which is handy for activations reached by train or bus</li>
        </ul>
    </div>

    <!-- July 2026 (v1.5.4-v1.5.7) -->
    <div class="version-block">
        <div class="version-header">
            <span class="version-number">v1.5.7</span>
            <span class="version-date">July 2026</span>
        </div>
        <p class="version-desc">Dashboards (formerly Planning Groups), richer map hover cards, and editable planned activations.</p>
        <ul>
            <li>"Planning Groups" has been renamed "Dashboards" throughout the app — same feature, clearer name for the workspace that holds your summit list, addresses, and members</li>
            <li>The "Groups & Addresses" page is now "Manage Dashboards"</li>
            <li>Hovering a summit on the dashboard map now shows a points badge, plus a breakdown of drive time and hike time alongside the total</li>
            <li>Summits without hike research no longer show a misleading time on the map — they're marked "+ Research" instead</li>
            <li>Summits you've already activated this year now appear faded on the map so they stand out as done</li>
            <li>The dashboard filter bar is now organized into two clean rows — filters on top, controls below — and wraps properly on mobile</li>
            <li>A new Unique Summits toggle lets you instantly filter to summits your group has never activated, making it easy to hunt for firsts</li>
            <li>Planned activations on the summit detail page can now be edited — click Edit to update the date, time, callsigns, duration, guest message, travel notes, or live GPS link without deleting and re-creating</li>
            <li>The activation invite now shows a Get Directions button in the hero that opens Google Maps with the trailhead pre-set as the destination</li>
            <li>The Day at a Glance timeline now includes a Back at Trailhead time so guests can see the full shape of the day</li>
            <li>The invite page Gantt chart now uses the same hike time calculation as the summit detail page, including pace multiplier and one-way track handling</li>
        </ul>
    </div>

    <!-- June 2026 (v1.4.2-v1.5.3) -->
    <div class="version-block">
        <div class="version-header">
            <span class="version-number">v1.5.3</span>
            <span class="version-date">June 2026</span>
        </div>
        <p class="version-desc">Bulk nomination, public summit notes, per-user units, and smarter GPS-driven trail data.</p>
        <ul>
            <li>The Nominate page now uses a wider two-column layout on desktop — the "Search by Area" tab shows the summit list alongside a large map, and hovering a summit name highlights its dot on the map</li>
            <li>Expired summits are now filtered out of all search results and can no longer be nominated</li>
            <li>Summit notes can now be shared publicly — check the new "Share publicly" box when adding a note and it will appear on that summit's page for all planning groups, with a green Public badge and your group's name</li>
            <li>In the "Search by Area" nominator, the Nominate button now appears above the summit list so it's always visible without scrolling when a large radius returns many results</li>
            <li>Nominate multiple summits at once by pasting a comma-separated list of SOTA references</li>
            <li>Units preference (metric or imperial) is now per-user and auto-detected from your callsign — non-US/Canada users default to metric</li>
            <li>Summit detail now shows who last activated the summit globally (with callsign and date) and separately tracks the last activation by your planning group</li>
            <li>GPX elevation profile now correctly detects when a track has no elevation data and shows a clear message instead of a blank chart</li>
            <li>Login page shows how many summits in the global library already have a GPX route and trailhead coordinate, giving new visitors an at-a-glance sense of how much data is pre-loaded</li>
            <li>Right-clicking anywhere on the summit map shows the exact coordinates of that point — click them to copy to your clipboard, with a brief "Copied" confirmation just like Google Maps</li>
            <li>When a community route is imported, distance and elevation are automatically enabled as the source for hike planning — no need to manually check "Use GPS data"</li>
            <li>Hovering over the locked distance or elevation fields shows a tooltip explaining they're set by the GPS track</li>
            <li>The loading animation while searching for a community route now uses the SOTAplanner mountain logo — the path draws itself and the summit marker pulses when the peak is reached</li>
            <li>Trailhead detection is now more reliable: if a cached community route had no trailhead stored, it's automatically re-derived from the GPS file on next use</li>
            <li>Summit search now narrows as you type a SOTA reference prefix — type "W6/CT-" to browse all summits in that region and pick one from the list</li>
            <li>When you type a full designator directly, the summit name now appears in the confirmation box so you can verify you have the right summit before nominating</li>
            <li>When nominating a summit, a loading indicator shows while the app searches the SOTA Mapping Project for a community route</li>
            <li>If a community route has multiple alternatives on the SOTA Mapping Project, a "swap" option now appears on the summit detail page to try a different one</li>
        </ul>
    </div>

    <!-- May 2026 (v1.0.5-v1.4.1) -->
    <div class="version-block">
        <div class="version-header">
            <span class="version-number">v1.4.1</span>
            <span class="version-date">May 2026</span>
        </div>
        <p class="version-desc">SOTA SSO login, community trail routes, SOTAwatch alerts, and the first Alpine Precision redesign.</p>
        <ul>
            <li>When you upload a GPX file, the app now automatically determines whether the track is an ascent, descent, or out-and-back — no more manual selection needed</li>
            <li>The trailhead location is automatically identified from the GPX and saved to the summit if none was set</li>
            <li>The "Use GPS data" toggle now saves instantly — no Save button needed</li>
            <li>Distance and elevation gain fields are greyed out when GPS data is active, making it clear those values are coming from the track file</li>
            <li>The summit's radio time in the planning timeline now uses your personal default from User Settings rather than the recorded activation time from the GPX</li>
            <li>When you add a summit that has a community-submitted track on the SOTA Mapping Project, the route map and elevation profile appear automatically — no GPX upload needed</li>
            <li>Hike distance and elevation gain are pre-filled from the community track</li>
            <li>A "Community route from SOTA Mapping Project" badge appears on any summit using a shared track</li>
            <li>Summit detail page now shows recent SOTAwatch alerts — see who has spotted this summit and when, right alongside your planning data</li>
            <li>Activation history pulled from the SOTA database appears on each summit detail page — see past activations at a glance</li>
            <li>Redesigned About page with a clearer feature overview and illustrated layout</li>
            <li>Nominate a summit by searching its name — type "Mount Wilson" or "Mt Adams" and pick from results, no need to know the SOTA reference code</li>
            <li>Summit detail page form is more compact — distance, gain, and difficulty sit in one row; trailhead and cell service share a row</li>
            <li>Trail app links (AllTrails, Gaia, CalTopo, etc.) are now tucked behind a toggle so they don't clutter the page</li>
            <li>Activation Zone map button now toggles — click once to zoom to the activation zone, click again to zoom back out to the full GPX track</li>
            <li>The day timeline bar is taller and now shows each activity's label and duration directly inside the colored segment — no more reading a separate legend</li>
            <li>Notes on the summit detail page now show which group member wrote them</li>
            <li>New users are walked through creating a group and adding a starting address step-by-step</li>
            <li>Drive time now auto-calculates the first time you open a summit detail page, no button press needed</li>
            <li>Groups &amp; Addresses page automatically opens your group if you only belong to one</li>
            <li>Log in using your official SOTA credentials — no separate password needed</li>
            <li>When you add your first starting address to a group, it's automatically set as the active address — no extra step needed</li>
            <li>Co-activators field renamed for clarity when creating a new group</li>
            <li>Any group member can add new callsigns to a planning group, not just the owner</li>
            <li>New SVG logo across all pages</li>
            <li>Returning users land directly on their dashboard after signing in — no more group picker every time</li>
            <li>New users see a welcome screen with clear instructions on how to get started</li>
            <li>Address prompt opens automatically when you create a new planning group</li>
            <li>Your callsign now appears in the top corner of every page — click it to sign out</li>
            <li>All pages redesigned with the Alpine Precision design system — warm off-white background, clean typography, warm amber accent color</li>
            <li>Planning Groups page rebuilt with a sidebar + detail panel layout</li>
        </ul>
    </div>

    <!-- April 2026 (v1.0.0-v1.0.4) -->
    <div class="version-block">
        <div class="version-header">
            <span class="version-number">v1.0.4</span>
            <span class="version-date">April 2026</span>
        </div>
        <p class="version-desc">Initial release — summit wishlist, GPX analysis, activation timeline, and shareable invitations.</p>
        <ul>
            <li>Sign in with your callsign — SOTA SSO coming when OAuth credentials are available</li>
            <li>Planning groups are now private to the owner and invited members</li>
            <li>Group owners can add and remove members by callsign</li>
            <li>Summit list on mobile shows tap-friendly cards with key stats at a glance</li>
            <li>Summit detail, invitation, and planning pages all work cleanly on a phone</li>
            <li>Activation timeline on summit detail — Gantt chart showing drive, hike up, radio time, and hike down with milestone markers</li>
            <li>Download the GPX track from summit detail and invitation pages to load onto a watch or phone</li>
            <li>Summit wishlist with drive time, hike time, and total day estimate</li>
            <li>GPX track upload and analysis — hiking time, activation time, elevation, speed</li>
            <li>Activation zone overlay on the summit map</li>
            <li>Elevation profile chart with interactive map crosshair</li>
            <li>Shareable activation invitation page for guests — map, timeline, driving directions</li>
            <li>Planned activations with .ics calendar export</li>
            <li>Cell coverage overlay (T-Mobile, Verizon, AT&amp;T)</li>
            <li>SOTAmaps GPX import — pull community tracks directly from sotamaps.org</li>
            <li>Drive time calculated from your saved starting address</li>
            <li>Shared summit research — new groups can inherit trail data from existing groups</li>
            <li>SOTLAS summit data integration</li>
            <li>Multi-group support — keep separate wishlists for different crews or regions</li>
        </ul>
    </div>

</div>

<footer>
    SOTA Planner &nbsp;·&nbsp; <a href="changelog.php">v<?= APP_VERSION ?></a> &nbsp;·&nbsp; <a href="https://sotaplanner.com">sotaplanner.com</a>
</footer>

</body>
</html>
