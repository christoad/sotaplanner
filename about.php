<?php
require_once 'config.php';
session_start();

$db = getDbConnection();
reconcileTrailDataGrowthLog($db);

$total_ready = (int)$db->query("SELECT COUNT(*) FROM trail_data_growth_log")->fetchColumn();

// Monthly new-summit counts, turned into a running cumulative total
$growth_rows = $db->query("
    SELECT DATE_FORMAT(first_seen_date, '%Y-%m-01') AS month, COUNT(*) AS cnt
    FROM trail_data_growth_log
    GROUP BY month
    ORDER BY month ASC
")->fetchAll();

$growth = [];
$running = 0;
foreach ($growth_rows as $r) {
    $running += (int)$r['cnt'];
    $growth[] = ['month' => $r['month'], 'total' => $running, 'added' => (int)$r['cnt']];
}

$bkey = defined('GOOGLE_MAPS_BROWSER_KEY') ? GOOGLE_MAPS_BROWSER_KEY : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>About — SOTA Planner</title>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;1,9..40,400&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
    <style>
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
      --surface:       #FFFFFF;
      --border:        #E5E2DA;
      --border-2:      #D4D0C8;
      --font-sans:     'DM Sans', system-ui, sans-serif;
      --font-mono:     'DM Mono', 'Courier New', monospace;
      --r-sm: 4px; --r-md: 8px; --r-lg: 12px; --r-xl: 16px;
      --sp-1: 0.25rem; --sp-2: 0.5rem; --sp-3: 0.75rem; --sp-4: 1rem;
      --sp-6: 1.5rem; --sp-8: 2rem;
      --shadow-sm: 0 1px 3px rgba(28,27,25,0.07), 0 1px 2px rgba(28,27,25,0.05);
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
    .topbar-nav { display: flex; align-items: center; gap: var(--sp-2); }
    .topbar-nav a {
      color: var(--ink-3); font-size: 0.875rem; font-weight: 500;
      padding: var(--sp-2) var(--sp-3); border-radius: var(--r-sm);
      transition: color 0.15s, background 0.15s;
      text-decoration: none; white-space: nowrap;
    }
    .topbar-nav a:hover { color: var(--ink); background: var(--bg-2); }

    /* ── Page ── */
    .page {
      max-width: 700px;
      margin: 0 auto;
      padding: var(--sp-8) var(--sp-8) 5rem;
    }

    /* ── Content blocks ── */
    .about-section {
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: var(--r-lg);
      padding: 1.5rem;
      margin-bottom: 1rem;
      box-shadow: var(--shadow-sm);
    }
    .about-section h2 {
      font-size: 0.8rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.08em;
      color: var(--ink-3);
      margin-bottom: 1rem;
    }
    .about-section p {
      font-size: 0.9375rem;
      color: var(--ink-2);
      line-height: 1.65;
      margin-bottom: 0.875rem;
    }
    .about-section p:last-child { margin-bottom: 0; }
    .about-section strong { color: var(--ink); font-weight: 600; }
    .about-section a { color: var(--accent); text-decoration: none; }
    .about-section a:hover { text-decoration: underline; }

    /* ── Feature tiles ── */
    .feature-tiles {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
      gap: 0;
      margin: 0.25rem -0.25rem -0.5rem;
    }
    .feature-tile {
      padding: 1rem 0.75rem;
      text-align: center;
    }
    .feature-tile h3 {
      font-size: 0.875rem;
      font-weight: 700;
      color: var(--ink);
      margin: 0.5rem 0 0.3rem;
    }
    .feature-tile p {
      font-size: 0.82rem;
      color: var(--ink-3);
      line-height: 1.5;
    }
    .feature-text {}
    .feature-name {
      font-size: 0.84rem;
      font-weight: 600;
      color: var(--ink);
      margin-bottom: 0.2rem;
      line-height: 1.3;
    }
    .feature-desc {
      font-size: 0.78rem;
      color: var(--ink-3);
      line-height: 1.45;
    }
    .feature-card:last-child:nth-child(odd) {
      grid-column: 1 / -1;
      width: calc(50% - 0.375rem);
      margin-inline: auto;
    }

    /* ── Community Growth ── */
    .growth-subhead { font-size: 0.95rem; font-weight: 600; color: var(--ink); margin: 1.25rem 0 0.15rem; }
    .growth-subhead:first-child { margin-top: 0; }
    .growth-subhead-desc { font-size: 0.82rem; color: var(--ink-3); margin-bottom: 0.75rem; }

    .stat-hero {
      display: inline-flex; align-items: baseline; gap: 0.6rem;
      background: var(--accent-bg); border: 1px solid var(--accent-border);
      border-radius: var(--r-lg); padding: 0.9rem 1.4rem; margin-top: 0.25rem;
    }
    .stat-hero-num { font-size: 2rem; font-weight: 700; color: var(--accent-2); font-variant-numeric: proportional-nums; }
    .stat-hero-label { font-size: 0.85rem; color: var(--ink-2); font-weight: 500; }

    .btn-ghost {
      display: inline-flex; align-items: center; height: 30px; padding: 0 var(--sp-3);
      border-radius: var(--r-md); font-size: 0.8rem; font-weight: 500; cursor: pointer;
      background: transparent; color: var(--ink-2); border: 1px solid var(--border);
      font-family: var(--font-sans); transition: background 0.15s, color 0.15s;
    }
    .btn-ghost:hover { background: var(--bg-2); color: var(--ink); }

    #map-wrap { position: relative; height: 420px; border-radius: var(--r-md); overflow: hidden; border: 1px solid var(--border); }
    #map { width: 100%; height: 100%; }
    #map-loading {
      position: absolute; inset: 0; background: rgba(247,246,243,0.85);
      display: flex; flex-direction: column; align-items: center; justify-content: center;
      z-index: 10; gap: 0.75rem;
    }
    .spinner { width: 30px; height: 30px; border: 3px solid var(--border); border-top-color: var(--accent); border-radius: 50%; animation: spin 0.75s linear infinite; }
    @keyframes spin { to { transform: rotate(360deg); } }
    #map-hint { position: absolute; bottom: var(--sp-3); right: var(--sp-3); background: var(--surface); border: 1px solid var(--border); border-radius: var(--r-sm); padding: 0.3rem 0.6rem; font-size: 0.75rem; color: var(--ink-3); z-index: 5; }

    .gm-style .gm-style-iw-c { border-radius: var(--r-lg) !important; padding: 0 !important; box-shadow: 0 4px 20px rgba(0,0,0,0.15) !important; }
    .gm-style .gm-style-iw-d { overflow: hidden !important; }
    .gm-style .gm-style-iw-tc::after { background: #fff !important; }
    .iw-body { padding: 0.9rem 1rem 0.8rem; min-width: 190px; font-family: var(--font-sans); }
    .iw-ref { font-size: 0.7rem; font-weight: 600; color: var(--ink-3); text-transform: uppercase; letter-spacing: 0.06em; margin-bottom: 0.2rem; font-family: var(--font-mono); }
    .iw-name { font-size: 0.92rem; font-weight: 600; color: var(--ink); line-height: 1.3; margin-bottom: 0.4rem; }
    .iw-points { font-size: 0.78rem; color: var(--ink-3); margin-bottom: 0.55rem; }
    .iw-link { display: inline-flex; align-items: center; height: 26px; padding: 0 0.6rem; border-radius: var(--r-md); font-size: 0.75rem; font-weight: 500; text-decoration: none; background: var(--bg-2); color: var(--ink-2); border: 1px solid var(--border); }
    .iw-link:hover { background: var(--bg-3); color: var(--ink); }

    .chart-wrap { position: relative; }
    #chart-svg { width: 100%; height: 280px; display: block; overflow: visible; }
    .chart-grid line { stroke: var(--border); stroke-width: 1; shape-rendering: crispEdges; }
    .chart-axis-label { font-size: 0.7rem; fill: var(--ink-3); font-family: var(--font-sans); }
    .chart-area { fill: var(--accent-bg); }
    .chart-line { fill: none; stroke: var(--accent); stroke-width: 2; stroke-linejoin: round; stroke-linecap: round; }
    .chart-end-dot { fill: var(--accent); stroke: var(--surface); stroke-width: 2; }
    .chart-end-label { font-size: 0.78rem; font-weight: 600; fill: var(--ink); font-family: var(--font-sans); }
    .chart-crosshair { stroke: var(--ink-4); stroke-width: 1; stroke-dasharray: 3 3; opacity: 0; pointer-events: none; }
    .chart-annotation-line { stroke: var(--ink-3); stroke-width: 1; stroke-dasharray: 4 3; }
    .chart-annotation-dot { fill: var(--surface); stroke: var(--ink-2); stroke-width: 2; }
    .chart-annotation-label { font-size: 0.7rem; font-weight: 600; fill: var(--ink-2); font-family: var(--font-sans); }
    .chart-annotation-hit { fill: transparent; cursor: help; }
    .chart-hover-dot { fill: var(--accent); stroke: var(--surface); stroke-width: 2; opacity: 0; pointer-events: none; }
    .chart-hit-area { fill: transparent; cursor: crosshair; }

    .tooltip {
      position: absolute; pointer-events: none; opacity: 0; transition: opacity 0.1s;
      background: var(--ink); color: #fff; border-radius: var(--r-md);
      padding: 0.45rem 0.7rem; font-size: 0.78rem; white-space: nowrap;
      transform: translate(-50%, calc(-100% - 10px)); z-index: 20;
    }
    .tooltip-val { font-weight: 700; }
    .tooltip-lbl { color: rgba(255,255,255,0.7); margin-top: 0.1rem; }

    .growth-table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
    .growth-table th, .growth-table td { text-align: left; padding: 0.5rem 0.75rem; border-bottom: 1px solid var(--border); }
    .growth-table th { color: var(--ink-3); font-weight: 600; font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.05em; }
    .growth-table td.num, .growth-table th.num { text-align: right; font-variant-numeric: tabular-nums; }
    .growth-note-badge {
        display: inline-flex; align-items: center; gap: 0.35rem;
        font-size: 0.72rem; font-weight: 600; color: var(--ink-2);
        background: var(--bg-2); border: 1px solid var(--border-2);
        border-radius: 100px; padding: 0.15rem 0.65rem;
    }
    .growth-note-badge::before { content: ''; width: 6px; height: 6px; border-radius: 50%; background: var(--ink-3); flex-shrink: 0; }

    .empty-note { padding: var(--sp-6) 0; text-align: center; color: var(--ink-3); font-size: 0.9rem; }

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
      .feature-grid { grid-template-columns: 1fr; }
      .feature-card:last-child:nth-child(odd) {
        grid-column: auto;
        width: 100%;
        margin-inline: 0;
      }
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

    <!-- Hub-and-spoke illustration: inputs scattered on left → SOTAplanner logo on right -->
    <div class="about-section" id="converge-section" style="padding:0;overflow:hidden;margin-bottom:1rem;">
        <svg viewBox="0 0 680 460" xmlns="http://www.w3.org/2000/svg" style="width:100%;display:block;max-width:100%;" aria-label="Six inputs — Summit, Trail Info, Travel Time, Cell Coverage, Hike Time, Activation Time — all flow into SOTAplanner">
          <defs>
            <marker id="arr" markerWidth="9" markerHeight="9" refX="7.5" refY="4.5" orient="auto">
              <path d="M1.5,1.5 L7.5,4.5 L1.5,7.5" fill="none" stroke="#C2BDB4" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
            </marker>
          </defs>

          <!-- Background -->
          <rect width="680" height="460" fill="#F7F6F3" rx="12"/>

          <!-- ── ARROWS (icon edge → hub edge) — fade out as bubbles converge ──
               3 rows on each side of the centered hub, staggered in/out for visual rhythm -->
          <g class="conv-arrows">
          <line x1="95"  y1="80"  x2="260" y2="205" stroke="#D4D0C8" stroke-width="1.5" marker-end="url(#arr)"/>
          <line x1="175" y1="228" x2="258" y2="228" stroke="#D4D0C8" stroke-width="1.5" marker-end="url(#arr)"/>
          <line x1="95"  y1="376" x2="260" y2="251" stroke="#D4D0C8" stroke-width="1.5" marker-end="url(#arr)"/>
          <line x1="585" y1="80"  x2="420" y2="205" stroke="#D4D0C8" stroke-width="1.5" marker-end="url(#arr)"/>
          <line x1="505" y1="228" x2="422" y2="228" stroke="#D4D0C8" stroke-width="1.5" marker-end="url(#arr)"/>
          <line x1="585" y1="376" x2="420" y2="251" stroke="#D4D0C8" stroke-width="1.5" marker-end="url(#arr)"/>
          </g>

          <!-- ── HUB: SOTAplanner logo (r=80), centered on the canvas — grows slightly + glows as it absorbs the bubbles ── -->
          <g class="hub-group">
          <circle class="hub-ring" cx="340" cy="228" r="80" fill="white" stroke="#D8C890" stroke-width="2"/>
          <g transform="translate(340,228) scale(1.36) translate(-55,-55)">
            <circle fill="none" stroke="#1c1b19" stroke-width="1.5" cx="55" cy="55" r="50"/>
            <path fill="none" stroke="#8c8a86" stroke-width=".5" opacity=".2" d="M18,75.5c11.33-4,23.67-5,37-3,13.33-3.33,25.67-3.67,37-1"/>
            <path fill="none" stroke="#8c8a86" stroke-width=".5" opacity=".15" d="M22,81.5c12-4,23-5,33-3,13.33-3.33,24.33-3.67,33-1"/>
            <path fill="none" stroke="#1c1b19" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" d="M26,79.5l17-30,7,8,12-20,22,42"/>
            <circle fill="#2b8e8e" cx="62" cy="35.5" r="3.5"/>
            <circle fill="none" stroke="#2b8e8e" stroke-width="1.2" opacity=".45" cx="62" cy="35.5" r="9"/>
            <circle fill="none" stroke="#2b8e8e" stroke-width=".8" opacity=".2" cx="62" cy="35.5" r="15"/>
          </g>
          <text x="340" y="323" text-anchor="middle" font-family="DM Sans,system-ui,sans-serif" font-size="14" font-weight="700" fill="#1C1B19" letter-spacing="-0.02em">SOTAplanner</text>
          <text x="340" y="338" text-anchor="middle" font-family="DM Sans,system-ui,sans-serif" font-size="9.5" font-weight="400" fill="#8C8A86" letter-spacing="0.01em">the complete picture</text>
          </g>

          <!-- ── ICON CIRCLES — all use toolkit icons (viewBox 0 0 24 24, scale 1.8) ──
               3 bubbles staggered on each side of the centered hub (outer/inner/outer).
               Each is wrapped in a .conv-node group with its own center (data-cx/data-cy)
               so JS can translate+shrink+fade it into the hub as the page scrolls. -->

          <!-- 1. Summit (65, 80) — LEFT, outer -->
          <g class="conv-node" data-cx="65" data-cy="80">
          <circle cx="65" cy="80" r="30" fill="white" stroke="#E5E2DA" stroke-width="1.5"/>
          <g transform="translate(65,80) scale(1.8) translate(-12,-12)" fill="none" stroke="#4A4844" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
            <path d="M3 20L12 4L21 20H3Z"/>
            <path d="M9 20L12 13L15 17"/>
          </g>
          <text x="65" y="123" text-anchor="middle" font-family="DM Sans,system-ui,sans-serif" font-size="11.5" font-weight="600" fill="#1C1B19">Summit</text>
          </g>

          <!-- 2. Travel Time (145, 228) — LEFT, inner -->
          <g class="conv-node" data-cx="145" data-cy="228">
          <circle cx="145" cy="228" r="30" fill="white" stroke="#E5E2DA" stroke-width="1.5"/>
          <g transform="translate(145,228) scale(1.8) translate(-12,-12)" fill="none" stroke="#4A4844" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
            <path d="M21 10c0 7-9 13-9 13S3 17 3 10a9 9 0 1 1 18 0z"/>
            <circle cx="12" cy="10" r="3"/>
          </g>
          <text x="145" y="271" text-anchor="middle" font-family="DM Sans,system-ui,sans-serif" font-size="11.5" font-weight="600" fill="#1C1B19">Travel Time</text>
          </g>

          <!-- 3. Hike Time (65, 376) — LEFT, outer -->
          <g class="conv-node" data-cx="65" data-cy="376">
          <circle cx="65" cy="376" r="30" fill="white" stroke="#E5E2DA" stroke-width="1.5"/>
          <g transform="translate(65,376) scale(1.8) translate(-12,-12)" fill="none" stroke="#4A4844" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="12" cy="4" r="1.5" fill="#4A4844" stroke="none"/>
            <line x1="12" y1="5.5" x2="11" y2="13"/>
            <rect x="8" y="5.5" width="3.5" height="5.5" rx="1"/>
            <line x1="16" y1="7" x2="18" y2="22"/>
            <line x1="11" y1="9" x2="16" y2="8"/>
            <line x1="11" y1="13" x2="8" y2="22"/>
            <line x1="11" y1="13" x2="14" y2="22"/>
          </g>
          <text x="65" y="419" text-anchor="middle" font-family="DM Sans,system-ui,sans-serif" font-size="11.5" font-weight="600" fill="#1C1B19">Hike Time</text>
          </g>

          <!-- 4. Trail Info (615, 80) — RIGHT, outer -->
          <g class="conv-node" data-cx="615" data-cy="80">
          <circle cx="615" cy="80" r="30" fill="white" stroke="#E5E2DA" stroke-width="1.5"/>
          <g transform="translate(615,80) scale(1.8) translate(-12,-12)" fill="none" stroke="#4A4844" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
            <polygon points="1,6 1,22 8,18 16,22 23,18 23,2 16,6 8,2"/>
            <line x1="8" y1="2" x2="8" y2="18"/>
            <line x1="16" y1="6" x2="16" y2="22"/>
          </g>
          <text x="615" y="123" text-anchor="middle" font-family="DM Sans,system-ui,sans-serif" font-size="11.5" font-weight="600" fill="#1C1B19">Trail Info</text>
          </g>

          <!-- 5. Cell Coverage (535, 228) — RIGHT, inner (signal bars icon) -->
          <g class="conv-node" data-cx="535" data-cy="228">
          <circle cx="535" cy="228" r="30" fill="white" stroke="#E5E2DA" stroke-width="1.5"/>
          <g transform="translate(535,228) scale(1.8) translate(-12,-12)" fill="#4A4844" stroke="none">
            <rect x="2" y="16" width="3.5" height="5" rx="0.5"/>
            <rect x="8" y="12" width="3.5" height="9" rx="0.5"/>
            <rect x="14" y="7" width="3.5" height="14" rx="0.5"/>
            <rect x="20" y="2" width="3.5" height="19" rx="0.5"/>
          </g>
          <text x="535" y="271" text-anchor="middle" font-family="DM Sans,system-ui,sans-serif" font-size="11.5" font-weight="600" fill="#1C1B19">Cell Coverage</text>
          </g>

          <!-- 6. Activation Time (615, 376) — RIGHT, outer (exact toolkit 'radio' icon) -->
          <g class="conv-node" data-cx="615" data-cy="376">
          <circle cx="615" cy="376" r="30" fill="white" stroke="#E5E2DA" stroke-width="1.5"/>
          <g transform="translate(615,376) scale(1.8) translate(-12,-12)" fill="none" stroke="#4A4844" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
            <rect x="7" y="8" width="8" height="13" rx="2"/>
            <line x1="12" y1="8" x2="12" y2="3"/>
            <rect x="9" y="10" width="4" height="3" rx="0.5"/>
            <circle cx="11" cy="17" r="1.5" fill="#4A4844" stroke="none"/>
          </g>
          <text x="615" y="419" text-anchor="middle" font-family="DM Sans,system-ui,sans-serif" font-size="11.5" font-weight="600" fill="#1C1B19">Activation time</text>
          </g>
        </svg>
    </div>

    <div class="about-section">
        <h2>What It Does</h2>
        <p>
            <strong>SOTA Planner</strong> pulls together everything required to activate a summit —
            travel time from your front door, hike distance and elevation, time on the air, and the
            return trip — so you can see the full door-to-door picture and know whether a given
            summit fits the time you have.
        </p>
    </div>

    <div class="about-section">
        <h2>Key Features</h2>
        <div class="feature-tiles">
            <div class="feature-tile">
                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#4A4844" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="9"/>
                    <polyline points="12 7 12 12 15 15"/>
                </svg>
                <h3>Total Time Estimate</h3>
                <p>Travel + hike + activation + return, all in one number</p>
            </div>
            <div class="feature-tile">
                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#4A4844" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M21 10c0 7-9 13-9 13S3 17 3 10a9 9 0 1 1 18 0z"/>
                    <circle cx="12" cy="10" r="3"/>
                </svg>
                <h3>Travel Time</h3>
                <p>Automatic routing from your address to each starting point</p>
            </div>
            <div class="feature-tile">
                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#4A4844" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>
                </svg>
                <h3>GPX Analysis</h3>
                <p>Upload a recorded track to get real-world hike and activation times</p>
            </div>
            <div class="feature-tile">
                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#4A4844" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                    <circle cx="9" cy="7" r="4"/>
                    <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
                    <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
                </svg>
                <h3>Dashboards</h3>
                <p>Share summit research and notes with co-activators</p>
            </div>
        </div>
    </div>

    <div class="about-section">
        <h2>Who Built This</h2>
        <p>
            SOTA Planner was created by <strong>Chris Reddick, KI6CR</strong> — a busy ham
            who wanted a smarter way to plan outings with his activator friend group. It's
            free to use, no email or registration required.
        </p>
        <p>
            More at <a href="https://ki6cr.com" target="_blank">ki6cr.com</a>
        </p>
    </div>

    <div class="about-section">
        <h2>Community Growth</h2>
        <p>
            Every summit with community trail data — a GPS route, a starting point, real hike
            times — pulled from the SOTA Mapping Project, OpenStreetMap, and dashboard research
            across the whole site. The same number shown on the login page, tracked here over time.
        </p>
        <div class="stat-hero">
            <span class="stat-hero-num"><?= number_format($total_ready) ?></span>
            <span class="stat-hero-label">summits with community trail data, site-wide</span>
        </div>

        <div class="growth-subhead">Where they are</div>
        <div class="growth-subhead-desc">Every summit with trail data, plotted by location.</div>
        <div id="map-wrap">
            <div id="map-loading">
                <div class="spinner"></div>
                <div style="font-size:0.85rem; color:var(--ink-3);">Loading map&hellip;</div>
            </div>
            <div id="map"></div>
            <div id="map-hint"></div>
        </div>

        <div class="growth-subhead" style="display:flex; align-items:center; justify-content:space-between; gap:1rem;">
            <span>Growth over time</span>
            <?php if (!empty($growth)): ?>
            <button class="btn-ghost" id="toggle-table-btn" type="button">View as table</button>
            <?php endif; ?>
        </div>
        <div class="growth-subhead-desc">Running total of summits with community trail data, by month.</div>
        <?php if (empty($growth)): ?>
            <div class="empty-note">No trail data recorded yet.</div>
        <?php else: ?>
            <div class="chart-wrap" id="chart-wrap">
                <svg id="chart-svg" viewBox="0 0 900 280" preserveAspectRatio="none"></svg>
                <div class="tooltip" id="chart-tooltip">
                    <div class="tooltip-val" id="tooltip-val"></div>
                    <div class="tooltip-lbl" id="tooltip-lbl"></div>
                </div>
            </div>
            <div id="table-wrap" style="display:none; overflow-x:auto;">
                <table class="growth-table">
                    <thead><tr><th>Month</th><th class="num">Total summits</th><th>Note</th></tr></thead>
                    <tbody>
                        <?php foreach (array_reverse($growth) as $g): ?>
                        <tr>
                            <td><?= date('F Y', strtotime($g['month'])) ?></td>
                            <td class="num"><?= number_format($g['total']) ?></td>
                            <td>
                                <?php if ($g['month'] === '2026-06-01'): ?>
                                <span class="growth-note-badge">SOTA Mapping Project mass import (+<?= number_format($g['added']) ?>)</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

</div>

<footer>
    SOTA Planner &nbsp;·&nbsp;
    <a href="changelog.php">v<?= APP_VERSION ?></a>
    &nbsp;·&nbsp;
    <a href="https://sotaplanner.com">sotaplanner.com</a>
</footer>

<!-- Scroll-driven convergence: the five data-source bubbles fly into the SOTAplanner hub
     as the illustration scrolls through the viewport, then reverse if the user scrolls back
     up. Hand-rolled against raw scroll position (same technique as the login page's
     feature-tile scrub) rather than motion.dev, since this is pure scroll-scrubbing with no
     need for its spring/stagger helpers. -->
<script>
(function () {
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

    var section = document.getElementById('converge-section');
    var nodes = section ? section.querySelectorAll('.conv-node') : [];
    var arrows = section ? section.querySelector('.conv-arrows') : null;
    var hubGroup = section ? section.querySelector('.hub-group') : null;
    var hubRing = section ? section.querySelector('.hub-ring') : null;
    if (!section || !nodes.length || !hubGroup) return;

    var hub = { x: 340, y: 228 };
    var ticking = false;

    function smoothstep(p) { return p * p * (3 - 2 * p); }

    function applyScrub() {
        // The illustration sits right at the top of the page (nothing above it to scroll
        // past), so progress is driven directly off how far the page has scrolled rather
        // than the section's position in the viewport — guarantees it always starts fully
        // expanded at scrollY 0 and converges as the user scrolls down.
        var scrollY = window.scrollY || window.pageYOffset;
        var vh = window.innerHeight;
        var triggerDistance = vh * 0.6;
        var p = Math.min(1, Math.max(0, scrollY / triggerDistance));
        var t = smoothstep(p);

        nodes.forEach(function (n) {
            var cx = parseFloat(n.dataset.cx);
            var cy = parseFloat(n.dataset.cy);
            var dx = (hub.x - cx) * t;
            var dy = (hub.y - cy) * t;
            var s = 1 - 0.55 * t;
            n.setAttribute('transform',
                'translate(' + dx + ',' + dy + ') translate(' + cx + ',' + cy + ') scale(' + s + ') translate(' + (-cx) + ',' + (-cy) + ')');
            n.style.opacity = Math.max(0, 1 - t * 1.15);
        });

        if (arrows) arrows.style.opacity = 1 - t;

        hubGroup.setAttribute('transform',
            'translate(' + hub.x + ',' + hub.y + ') scale(' + (1 + 0.08 * t) + ') translate(' + (-hub.x) + ',' + (-hub.y) + ')');
        if (hubRing) hubRing.setAttribute('stroke-width', 2 + 3 * t);

        ticking = false;
    }

    function onScroll() {
        if (!ticking) {
            requestAnimationFrame(applyScrub);
            ticking = true;
        }
    }

    window.addEventListener('scroll', onScroll, { passive: true });
    window.addEventListener('resize', onScroll);
    applyScrub();
})();
</script>

<script>
document.getElementById('toggle-table-btn')?.addEventListener('click', function () {
    var chart = document.getElementById('chart-wrap');
    var table = document.getElementById('table-wrap');
    var showingTable = table.style.display !== 'none';
    table.style.display = showingTable ? 'none' : 'block';
    chart.style.display = showingTable ? 'block' : 'none';
    this.textContent = showingTable ? 'View as table' : 'View as chart';
});
</script>

<?php if (!empty($growth)): ?>
<script>
(function () {
    var data = <?= json_encode(array_map(function ($g) {
        return ['month' => $g['month'], 'total' => $g['total']];
    }, $growth)) ?>;

    var svg = document.getElementById('chart-svg');
    var W = 900, H = 280;
    var padL = 44, padR = 16, padT = 16, padB = 28;
    var plotW = W - padL - padR, plotH = H - padT - padB;

    var maxVal = Math.max.apply(null, data.map(function (d) { return d.total; }));
    // Round the axis ceiling to a clean number
    var niceMax = maxVal <= 5 ? 5 : Math.ceil(maxVal / Math.pow(10, Math.floor(Math.log10(maxVal)))) * Math.pow(10, Math.floor(Math.log10(maxVal)));
    if (niceMax < maxVal) niceMax = maxVal;

    function xFor(i) { return padL + (data.length === 1 ? plotW / 2 : (i / (data.length - 1)) * plotW); }
    function yFor(v) { return padT + plotH - (v / niceMax) * plotH; }

    var ns = 'http://www.w3.org/2000/svg';
    function el(tag, attrs) {
        var e = document.createElementNS(ns, tag);
        for (var k in attrs) e.setAttribute(k, attrs[k]);
        return e;
    }

    // Gridlines + y-axis labels (0, 1/2, max)
    var gridGroup = el('g', { class: 'chart-grid' });
    [0, 0.5, 1].forEach(function (frac) {
        var y = padT + plotH - frac * plotH;
        gridGroup.appendChild(el('line', { x1: padL, x2: W - padR, y1: y, y2: y }));
        var label = el('text', { class: 'chart-axis-label', x: padL - 8, y: y + 4, 'text-anchor': 'end' });
        label.textContent = Math.round(niceMax * frac).toLocaleString();
        gridGroup.appendChild(label);
    });
    svg.appendChild(gridGroup);

    // X-axis labels — thin out so they don't collide
    var maxLabels = 7;
    var step = Math.max(1, Math.ceil(data.length / maxLabels));
    data.forEach(function (d, i) {
        if (i % step !== 0 && i !== data.length - 1) return;
        var label = el('text', { class: 'chart-axis-label', x: xFor(i), y: H - 6, 'text-anchor': 'middle' });
        var dt = new Date(d.month + 'T00:00:00');
        label.textContent = dt.toLocaleDateString('en-US', { month: 'short', year: '2-digit' });
        svg.appendChild(label);
    });

    // Area + line paths
    var linePoints = data.map(function (d, i) { return xFor(i) + ',' + yFor(d.total); }).join(' L ');
    var areaPath = 'M ' + xFor(0) + ',' + yFor(0) + ' L ' + linePoints + ' L ' + xFor(data.length - 1) + ',' + (padT + plotH) + ' L ' + xFor(0) + ',' + (padT + plotH) + ' Z';
    svg.appendChild(el('path', { class: 'chart-area', d: areaPath }));
    svg.appendChild(el('path', { class: 'chart-line', d: 'M ' + linePoints }));

    // Annotation: the June 2026 mass import from the SOTA Mapping Project, which
    // explains the large one-time jump in the line — a one-off historical event,
    // not a recurring feature, so it's fine to key off the literal month.
    var importIdx = -1;
    data.forEach(function (d, i) { if (d.month === '2026-06-01') importIdx = i; });
    if (importIdx > 0) {
        var ix = xFor(importIdx), iy = yFor(data[importIdx].total);
        var delta = data[importIdx].total - data[importIdx - 1].total;
        var annoGroup = el('g', {});

        annoGroup.appendChild(el('line', {
            class: 'chart-annotation-line', x1: ix, x2: ix, y1: padT, y2: padT + plotH
        }));

        var labelAnchor = ix > W * 0.6 ? 'end' : (ix < W * 0.25 ? 'start' : 'middle');
        var labelX = labelAnchor === 'end' ? ix - 6 : (labelAnchor === 'start' ? ix + 6 : ix);
        var label = el('text', {
            class: 'chart-annotation-label', x: labelX, y: padT + 11, 'text-anchor': labelAnchor
        });
        label.textContent = 'SOTA Mapping Project mass import';
        annoGroup.appendChild(label);

        var dot = el('circle', { class: 'chart-annotation-dot', cx: ix, cy: iy, r: 4 });
        var titleEl = document.createElementNS(ns, 'title');
        titleEl.textContent = 'June 2026: +' + delta.toLocaleString() + ' summits added in a single mass import from the SOTA Mapping Project';
        dot.appendChild(titleEl);
        annoGroup.appendChild(dot);

        // Generous invisible hit area so the tooltip is easy to trigger, not just the 4px dot
        var hit = el('circle', { class: 'chart-annotation-hit', cx: ix, cy: padT + 11, r: 10 });
        var hitTitle = document.createElementNS(ns, 'title');
        hitTitle.textContent = titleEl.textContent;
        hit.appendChild(hitTitle);
        annoGroup.appendChild(hit);

        svg.appendChild(annoGroup);
    }

    // End marker + direct label (value at the end, per spec)
    var lastX = xFor(data.length - 1), lastY = yFor(data[data.length - 1].total);
    svg.appendChild(el('circle', { class: 'chart-end-dot', cx: lastX, cy: lastY, r: 4 }));
    var endLabel = el('text', {
        class: 'chart-end-label', x: Math.min(lastX, W - padR - 4), y: lastY - 12,
        'text-anchor': data.length > 3 ? 'end' : 'middle'
    });
    endLabel.textContent = data[data.length - 1].total.toLocaleString();
    svg.appendChild(endLabel);

    // Hover layer: crosshair + snapping dot + tooltip
    var crosshair = el('line', { class: 'chart-crosshair', x1: 0, x2: 0, y1: padT, y2: padT + plotH });
    svg.appendChild(crosshair);
    var hoverDot = el('circle', { class: 'chart-hover-dot', r: 4 });
    svg.appendChild(hoverDot);
    var hitArea = el('rect', { class: 'chart-hit-area', x: padL, y: padT, width: plotW, height: plotH });
    svg.appendChild(hitArea);

    var tooltip = document.getElementById('chart-tooltip');
    var tooltipVal = document.getElementById('tooltip-val');
    var tooltipLbl = document.getElementById('tooltip-lbl');
    var wrap = document.getElementById('chart-wrap');

    function showAt(i) {
        var x = xFor(i), y = yFor(data[i].total);
        crosshair.setAttribute('x1', x); crosshair.setAttribute('x2', x);
        crosshair.style.opacity = 1;
        hoverDot.setAttribute('cx', x); hoverDot.setAttribute('cy', y);
        hoverDot.style.opacity = 1;

        var dt = new Date(data[i].month + 'T00:00:00');
        tooltipVal.textContent = data[i].total.toLocaleString() + ' summits with trail data';
        tooltipLbl.textContent = dt.toLocaleDateString('en-US', { month: 'long', year: 'numeric' });

        var pct = x / W;
        tooltip.style.left = (pct * 100) + '%';
        tooltip.style.top = ((y / H) * 100) + '%';
        tooltip.style.opacity = 1;
    }
    function hideTooltip() {
        crosshair.style.opacity = 0;
        hoverDot.style.opacity = 0;
        tooltip.style.opacity = 0;
    }

    hitArea.addEventListener('pointermove', function (e) {
        var rect = svg.getBoundingClientRect();
        var svgX = ((e.clientX - rect.left) / rect.width) * W;
        var idx = 0, best = Infinity;
        data.forEach(function (d, i) {
            var dist = Math.abs(xFor(i) - svgX);
            if (dist < best) { best = dist; idx = i; }
        });
        showAt(idx);
    });
    hitArea.addEventListener('pointerleave', hideTooltip);
})();
</script>
<?php endif; ?>

<script src="https://unpkg.com/@googlemaps/markerclusterer/dist/index.min.js"></script>
<script>
let allSummits = [];
let map, clusterer, infoWindow;

function initMap() {
    map = new google.maps.Map(document.getElementById('map'), {
        zoom: 2,
        center: { lat: 25, lng: 10 },
        mapTypeId: 'terrain',
        mapTypeControl: false,
        streetViewControl: false,
        fullscreenControl: true,
        gestureHandling: 'greedy',
        styles: [{ featureType: 'poi', stylers: [{ visibility: 'off' }] }]
    });
    infoWindow = new google.maps.InfoWindow({ maxWidth: 260 });
    loadData();
}

async function loadData() {
    try {
        const res = await fetch('api_trail_data_map.php');
        allSummits = await res.json();
        document.getElementById('map-loading').style.display = 'none';
        document.getElementById('map-hint').textContent = `${allSummits.length.toLocaleString()} summits — zoom or pan to explore`;

        // Build every marker once — SuperCluster handles aggregation at any zoom,
        // so there's no need to rebuild/filter per viewport like a smaller dataset would.
        const markers = allSummits.map(m => {
            const marker = new google.maps.Marker({
                position: { lat: m.a, lng: m.o },
                title: m.n || m.r,
                icon: {
                    path: google.maps.SymbolPath.CIRCLE,
                    scale: 5,
                    fillColor: '#c2571a',
                    fillOpacity: 0.85,
                    strokeColor: '#fff',
                    strokeWeight: 1.5,
                },
                optimized: true,
            });
            marker.addListener('click', () => showInfo(marker, m));
            return marker;
        });

        clusterer = new markerClusterer.MarkerClusterer({
            map,
            markers,
            algorithm: new markerClusterer.SuperClusterAlgorithm({ radius: 60, maxZoom: 13 }),
        });
    } catch (e) {
        document.getElementById('map-loading').innerHTML = '<div style="font-size:0.85rem; color:var(--ink-3);">Failed to load map data.</div>';
    }
}

function showInfo(marker, m) {
    const sotaRef = m.r;
    const name = m.n || sotaRef;
    const sotlasUrl = `https://sotlas.com/summit/${encodeURIComponent(sotaRef)}`;
    infoWindow.setContent(`
        <div class="iw-body">
            <div class="iw-ref">${sotaRef}</div>
            <div class="iw-name">${escHtml(name)}</div>
            ${m.p ? `<div class="iw-points">${m.p} point${m.p === 1 ? '' : 's'}</div>` : ''}
            <a class="iw-link" href="${sotlasUrl}" target="_blank">View on SOTLAS ↗</a>
        </div>
    `);
    infoWindow.open(map, marker);
}

function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
</script>
<script src="https://maps.googleapis.com/maps/api/js?key=<?= htmlspecialchars($bkey) ?>&callback=initMap&loading=async" async defer></script>

</body>
</html>
