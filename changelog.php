<?php require_once 'config.php';
session_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Changelog | SOTA Planner</title>
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
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      flex-wrap: wrap;
      gap: var(--sp-4);
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
    .github-link {
      display: inline-flex;
      align-items: center;
      gap: 0.5rem;
      flex-shrink: 0;
      font-size: 0.82rem;
      font-weight: 600;
      color: var(--ink-2);
      background: var(--surface);
      border: 1px solid var(--border-2);
      border-radius: 100px;
      padding: 0.5rem 1rem;
      text-decoration: none;
      box-shadow: var(--shadow-sm);
      transition: border-color 0.15s, color 0.15s, background 0.15s;
      white-space: nowrap;
    }
    .github-link:hover {
      border-color: var(--accent-border);
      background: var(--accent-bg);
      color: var(--ink);
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
      position: relative;
      padding-left: 1rem;
    }
    .version-block li a { color: var(--accent-2); font-weight: 600; text-decoration: none; }
    .version-block li a:hover { text-decoration: underline; }

    .version-block li::before {
      content: "–";
      color: var(--ink-4);
      position: absolute;
      left: 0;
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
        <div>
            <h1>Changelog</h1>
            <p>Release history and update notes for SOTA Planner</p>
        </div>
        <a href="https://github.com/christoad/sotaplanner" target="_blank" class="github-link">
            <svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor"><path d="M8 0C3.58 0 0 3.58 0 8c0 3.54 2.29 6.53 5.47 7.59.4.07.55-.17.55-.38 0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13-.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.51-1.07-1.78-.2-3.64-.89-3.64-3.95 0-.87.31-1.59.82-2.15-.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82.64-.18 1.32-.27 2-.27.68 0 1.36.09 2 .27 1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.51.56.82 1.27.82 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48 0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A8.01 8.01 0 0 0 16 8c0-4.42-3.58-8-8-8Z"/></svg>
            View source on GitHub
        </a>
    </div>

    <!-- What we're working on -->
    <div class="version-block">
        <div class="version-header">
            <span class="version-number">What we're working on</span>
            <span class="version-badge" style="background: var(--blue);">In progress</span>
        </div>
        <p class="version-desc">Not live yet.</p>
        <ul>
            <li>Summit to Summit mode with elevation plots</li>
            <li>Find hiking routes from one summit to the next</li>
            <li>Post alerts to SOTAwatch from SOTAplanner</li>
            <li>Send your GPX tracks to SOTA Maps</li>
            <li>GPX editor</li>
        </ul>
    </div>

    <!-- November 2026 -->
    <div class="version-block current">
        <div class="version-header">
            <span class="version-number">November 2026 update</span>
            <span class="version-badge">Current</span>
        </div>
        <ul>
            <li>Multi-Activate follows loop trails through several summits</li>
            <li>Radio interference (RFI) map for US summits. Source: <a href="https://github.com/km6am/sota-rf" target="_blank" rel="noopener">Summit RFI by KM6AM</a>, from FCC, NOAA and USGS data</li>
            <li>Advisory Notices: warn others about a summit</li>
            <li>Plan Multi-Activate routes from the dashboard map, with breaks and elevation profiles</li>
            <li>Search box on the dashboard</li>
            <li>Travel times that know about road closures</li>
            <li>Change log on every summit page</li>
        </ul>
    </div>

    <!-- October 2026 -->
    <div class="version-block">
        <div class="version-header">
            <span class="version-number">October 2026 update</span>
        </div>
        <ul>
            <li>A public page for every SOTA summit, ready to share</li>
            <li>Summit weather with a 16-day forecast</li>
            <li>Total activations for every summit</li>
            <li>Community Notes on summit pages</li>
            <li>Weather and topo maps in the Multi-Activate PDF</li>
            <li>Driving route on the summit map</li>
            <li>Add to Calendar for planned activations</li>
        </ul>
    </div>

    <div class="version-block">
        <div class="version-header">
            <span class="version-number">v1.9.7</span>
            <span class="version-date">September 2026</span>
        </div>
        <ul>
            <li>Share Multi-Activate routes, and save routes others share</li>
            <li>Starting addresses checked with Google Maps</li>
        </ul>
    </div>

    <div class="version-block">
        <div class="version-header">
            <span class="version-number">v1.9.6</span>
            <span class="version-date">September 2026</span>
        </div>
        <ul>
            <li>Copy GPS coordinates from the Multi-Activate map</li>
        </ul>
    </div>

    <div class="version-block">
        <div class="version-header">
            <span class="version-number">v1.9.5</span>
            <span class="version-date">September 2026</span>
        </div>
        <ul>
            <li>New Multi-Activate tool: plan several summits in one day</li>
            <li>Offline PDF of your route</li>
            <li>Winter bonus badges</li>
            <li>More precise activation zones from SOTLAS</li>
            <li>Points filter and bulk delete on the dashboard</li>
        </ul>
    </div>

    <!-- August 2026 -->
    <div class="version-block">
        <div class="version-header">
            <span class="version-number">v1.7.4</span>
            <span class="version-date">August 2026</span>
        </div>
        <ul>
            <li>Community Growth page</li>
            <li>Activated summits update from SOTA automatically</li>
            <li>"Starting Point" and "Travel Time", with transit directions</li>
            <li>Map, Topo and Satellite views on the dashboard map</li>
            <li>"Create Invite" for planned activations</li>
        </ul>
    </div>

    <!-- July 2026 -->
    <div class="version-block">
        <div class="version-header">
            <span class="version-number">v1.5.7</span>
            <span class="version-date">July 2026</span>
        </div>
        <ul>
            <li>Planning Groups are now Dashboards</li>
            <li>Richer map hover cards</li>
            <li>Unique Summits filter</li>
            <li>Editable planned activations</li>
        </ul>
    </div>

    <!-- June 2026 -->
    <div class="version-block">
        <div class="version-header">
            <span class="version-number">v1.5.3</span>
            <span class="version-date">June 2026</span>
        </div>
        <ul>
            <li>Add many summits at once</li>
            <li>Public summit notes</li>
            <li>Units per user</li>
            <li>Right-click the map to copy coordinates</li>
            <li>Choose between community routes</li>
        </ul>
    </div>

    <!-- May 2026 -->
    <div class="version-block">
        <div class="version-header">
            <span class="version-number">v1.4.1</span>
            <span class="version-date">May 2026</span>
        </div>
        <ul>
            <li>Sign in with your SOTA account</li>
            <li>Community routes load automatically</li>
            <li>SOTAwatch alerts and activation history on summit pages</li>
            <li>Find summits by name</li>
            <li>New design across the site</li>
        </ul>
    </div>

    <!-- April 2026 -->
    <div class="version-block">
        <div class="version-header">
            <span class="version-number">v1.0.4</span>
            <span class="version-date">April 2026</span>
        </div>
        <ul>
            <li>Summit wishlist with total day estimates</li>
            <li>GPX analysis, activation zones and elevation profiles</li>
            <li>Activation timeline and shareable invitations</li>
            <li>Cell coverage overlay</li>
        </ul>
    </div>

    <!-- February 2026 -->
    <div class="version-block">
        <div class="version-header">
            <span class="version-number">v1.0.0</span>
            <span class="version-date">February 2026</span>
        </div>
        <ul>
            <li>First release for the HamPals Activator Group, Los Angeles</li>
        </ul>
    </div>

</div>

<footer>
    SOTA Planner &nbsp;·&nbsp; <a href="changelog.php">v<?= APP_VERSION ?></a> &nbsp;·&nbsp; <a href="about.php#credits">Credits</a> &nbsp;·&nbsp; <a href="https://sotaplanner.com">sotaplanner.com</a>
</footer>

</body>
</html>
