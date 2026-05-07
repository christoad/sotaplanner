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

    /* ── Tags ── */
    .tag {
      display: inline-block;
      font-size: 0.65rem;
      font-weight: 600;
      border-radius: var(--r-sm);
      padding: 0.1rem 0.4rem;
      text-transform: uppercase;
      letter-spacing: 0.05em;
      white-space: nowrap;
      flex-shrink: 0;
    }
    .tag-new     { background: var(--green-bg);  color: var(--green); }
    .tag-fix     { background: var(--red-bg);    color: var(--red); }
    .tag-improve { background: var(--blue-bg);   color: var(--blue); }

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

    <!-- v1.0.7 -->
    <div class="version-block current">
        <div class="version-header">
            <span class="version-number">v1.0.7</span>
            <span class="version-date">May 2026</span>
            <span class="version-badge">Current</span>
        </div>
        <p class="version-desc">New SVG logo across all pages, smarter post-login redirect, and several UX improvements.</p>
        <ul>
            <li><span class="tag tag-new">New</span> SVG logo replaces old inline SVG and logo.png across all pages — sharper at every size</li>
            <li><span class="tag tag-new">New</span> Returning users skip the group picker and land directly on their dashboard after sign-in (uses saved default group, or falls back to their owned group)</li>
            <li><span class="tag tag-improve">Improve</span> Welcome banner on the Planning Groups page greets new users and explains how to get started</li>
            <li><span class="tag tag-improve">Improve</span> Address modal opens automatically after creating a new planning group</li>
            <li><span class="tag tag-fix">Fix</span> Activation Zone button now appears for any summit with a SOTA reference, not just GPX-tracked ones</li>
            <li><span class="tag tag-fix">Fix</span> Activation zone data is fetched directly on Summit Detail when no GPX is attached</li>
        </ul>
    </div>

    <!-- v1.0.6 -->
    <div class="version-block">
        <div class="version-header">
            <span class="version-number">v1.0.6</span>
            <span class="version-date">May 2026</span>
        </div>
        <p class="version-desc">Logged-in callsign now visible on every page with a click-to-logout dropdown. Login page updated to match the warm amber color scheme.</p>
        <ul>
            <li><span class="tag tag-new">New</span> Callsign chip in the top-right corner of every logged-in page — click to reveal a Sign Out option</li>
            <li><span class="tag tag-fix">Fix</span> Planning Groups page PHP parse error (mismatched if/endif) that caused a blank white page</li>
            <li><span class="tag tag-improve">Improve</span> Login page hero and button colors updated to match the warm amber Alpine Precision theme</li>
        </ul>
    </div>

    <!-- v1.0.5 -->
    <div class="version-block">
        <div class="version-header">
            <span class="version-number">v1.0.5</span>
            <span class="version-date">May 2026</span>
        </div>
        <p class="version-desc">Visual redesign — Alpine Precision design system across all pages. Minor content updates.</p>
        <ul>
            <li><span class="tag tag-improve">Improve</span> Dashboard and all major pages redesigned with Alpine Precision design system: warm off-white background, DM Sans typography, clean borders and subtle shadows</li>
            <li><span class="tag tag-improve">Improve</span> Planning Groups page rebuilt with sidebar group list and detail panel layout replacing the old two-path form</li>
            <li><span class="tag tag-improve">Improve</span> Replaced teal accent color with warm amber throughout all pages and map polylines</li>
            <li><span class="tag tag-fix">Fix</span> Selected address ID now correctly read from app_settings on the Planning Groups page</li>
            <li><span class="tag tag-improve">Improve</span> Updated login page tagline to better describe the app's value for activators and teams</li>
            <li><span class="tag tag-fix">Fix</span> Corrected author name to Christopher Reddick on the About page</li>
        </ul>
    </div>

    <!-- v1.0.4 -->
    <div class="version-block">
        <div class="version-header">
            <span class="version-number">v1.0.4</span>
            <span class="version-date">April 2026</span>
        </div>
        <p class="version-desc">User authentication and planning group privacy. Users now log in with their callsign — groups are private to their owner and invited members.</p>
        <ul>
            <li><span class="tag tag-new">New</span> Login page with callsign-based authentication — SOTA SSO (OAuth) ready, dev login active for testing</li>
            <li><span class="tag tag-new">New</span> Planning groups are now private — only visible to the owner and invited members</li>
            <li><span class="tag tag-new">New</span> Group member management — owners can add or remove members by callsign from the Planning Groups page</li>
            <li><span class="tag tag-new">New</span> Member callsigns field on group creation — invite your activation partners when creating a new group</li>
            <li><span class="tag tag-new">New</span> Sign Out link and logged-in callsign indicator in the site header</li>
            <li><span class="tag tag-new">New</span> SOTA SSO OAuth callback handler ready for production credentials (oauth_callback.php)</li>
            <li><span class="tag tag-improve">Improve</span> Cookie-restored default group now validates membership before restoring</li>
        </ul>
    </div>

    <!-- v1.0.3 -->
    <div class="version-block">
        <div class="version-header">
            <span class="version-number">v1.0.3</span>
            <span class="version-date">April 2026</span>
        </div>
        <p class="version-desc">Mobile UX overhaul across all major pages.</p>
        <ul>
            <li><span class="tag tag-improve">Improve</span> Summit list on mobile now renders as tap-friendly cards instead of a wide scrolling table — shows name, difficulty, status, total time, and a hike/drive/distance/gain stats strip</li>
            <li><span class="tag tag-fix">Fix</span> Header title no longer clips the left edge on mobile — negative-margin bleed now matches container padding correctly</li>
            <li><span class="tag tag-improve">Improve</span> Gantt chart milestones on summit detail and invitation pages now render as a clean vertical event list on mobile instead of overlapping absolute-positioned dots</li>
            <li><span class="tag tag-improve">Improve</span> Invitation page quick-facts row switches to a responsive grid on mobile instead of a cramped no-wrap horizontal scroll</li>
            <li><span class="tag tag-improve">Improve</span> Planned activation forms stack to single-column on mobile (date/time/radio fields no longer squeezed into a 3-column grid)</li>
            <li><span class="tag tag-fix">Fix</span> Planned activation action buttons (View Invite, Copy Link, Edit, ×) stay compact and inline on mobile instead of going full-width</li>
            <li><span class="tag tag-fix">Fix</span> Trailhead geocode input no longer gets crushed to a sliver on mobile — Find button stays compact beside the text field</li>
            <li><span class="tag tag-improve">Improve</span> Invitation page hides carrier coverage map toggles on mobile where they aren't useful for guests</li>
            <li><span class="tag tag-improve">Improve</span> Renamed "Use Custom Data" button to "Clear Imported Data" for clarity</li>
        </ul>
    </div>

    <!-- v1.0.2 -->
    <div class="version-block">
        <div class="version-header">
            <span class="version-number">v1.0.2</span>
            <span class="version-date">April 2026</span>
        </div>
        <p class="version-desc">Activation timeline visualization and GPX stat display improvements.</p>
        <ul>
            <li><span class="tag tag-new">New</span> Activation Timeline on summit detail page — collapsible Gantt chart showing drive, hike up, radio time, and hike down segments with milestone markers</li>
            <li><span class="tag tag-improve">Improve</span> GPX stat cards (hiking time, activation time, speed, rest breaks) are now hidden when the uploaded file is a route without timestamps</li>
            <li><span class="tag tag-new">New</span> "Want more stats?" banner shown when a route-only GPX is present, prompting user to upload a recorded track after their activation</li>
            <li><span class="tag tag-fix">Fix</span> Directions focus on activation invite page now uses <code>preventScroll:true</code> to avoid a double-scroll jump when tapping drive/return buttons</li>
            <li><span class="tag tag-fix">Fix</span> Removed unintended auto-scroll to addresses section after selecting a planning group</li>
        </ul>
    </div>

    <!-- v1.0.1 -->
    <div class="version-block">
        <div class="version-header">
            <span class="version-number">v1.0.1</span>
            <span class="version-date">April 2026</span>
        </div>
        <p class="version-desc">UX improvements and activation zone precision update.</p>
        <ul>
            <li><span class="tag tag-improve">Improve</span> Activation zone polygon now matches activation.zone website precision — updated deg_delta from 0.001 to 0.040</li>
            <li><span class="tag tag-new">New</span> GPX download button on summit detail and invitation pages for loading tracks onto watches and phones</li>
            <li><span class="tag tag-new">New</span> Daily automated database backup via cron (14-day retention)</li>
            <li><span class="tag tag-improve">Improve</span> Planning Groups page redesigned — "Join existing" and "Create new" shown side-by-side with clear OR divider</li>
            <li><span class="tag tag-improve">Improve</span> Page renamed from manage_addresses.php to planning_groups.php to better reflect its purpose</li>
            <li><span class="tag tag-improve">Improve</span> Empty addresses state now prompts user to add a starting location with explanation of how it's used</li>
            <li><span class="tag tag-improve">Improve</span> Page auto-scrolls to addresses section after selecting or creating a group</li>
        </ul>
    </div>

    <!-- v1.0.0 -->
    <div class="version-block">
        <div class="version-header">
            <span class="version-number">v1.0.0</span>
            <span class="version-date">April 2026</span>
        </div>
        <p class="version-desc">Initial public release. Full feature set for planning, researching, and sharing SOTA activations.</p>
        <ul>
            <li><span class="tag tag-new">New</span> Multi-group support — multiple planning groups share the same summit database</li>
            <li><span class="tag tag-new">New</span> GPX track upload and analysis — hiking time, activation time, rest breaks, elevation, speed</li>
            <li><span class="tag tag-new">New</span> Activation zone overlay using the activation.zone API with terrain-based polygon</li>
            <li><span class="tag tag-new">New</span> Elevation profile chart with interactive map crosshair hover on summit detail and invitation pages</li>
            <li><span class="tag tag-new">New</span> Activation invitation page for sharing with non-ham guests — timeline, map, driving directions</li>
            <li><span class="tag tag-new">New</span> Real-time location sharing link field on planned activations</li>
            <li><span class="tag tag-new">New</span> SOTA Maps GPX import — pull community tracks directly from sotamaps.org</li>
            <li><span class="tag tag-new">New</span> Cell coverage overlay (T-Mobile, Verizon, AT&amp;T) on summit and invitation maps</li>
            <li><span class="tag tag-new">New</span> Planned activations with calendar (.ics) export and shareable invite links</li>
            <li><span class="tag tag-new">New</span> Drive time calculation from saved home addresses via Google Maps</li>
            <li><span class="tag tag-new">New</span> Shared summit data — new groups can inherit trail research from existing groups</li>
            <li><span class="tag tag-new">New</span> Auto-import GPX track from source group when adopting a shared summit</li>
            <li><span class="tag tag-new">New</span> SOTLAS integration for trail and summit reference data</li>
            <li><span class="tag tag-fix">Fix</span> Invitation URL double-slash when site is hosted at domain root</li>
            <li><span class="tag tag-fix">Fix</span> SOTLAS link encoding — forward slash in summit reference no longer percent-encoded</li>
            <li><span class="tag tag-fix">Fix</span> Cookie star buttons now reflect immediately without requiring a page reload</li>
            <li><span class="tag tag-improve">Improve</span> Safety and location sharing info on invitation page is now generic and configurable per activation</li>
        </ul>
    </div>

</div>

<footer>
    SOTA Planner &nbsp;·&nbsp; <a href="changelog.php">v<?= APP_VERSION ?></a> &nbsp;·&nbsp; <a href="https://sotaplanner.com">sotaplanner.com</a>
</footer>

</body>
</html>
