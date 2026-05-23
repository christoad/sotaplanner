<?php
require_once 'config.php';
session_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>About — SOTA Planner</title>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;1,9..40,400&display=swap" rel="stylesheet">
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
      --r-sm: 4px; --r-md: 8px; --r-lg: 12px; --r-xl: 16px;
      --sp-2: 0.5rem; --sp-3: 0.75rem; --sp-4: 1rem;
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
      max-width: 680px;
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
      font-size: 0.85rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.07em;
      color: var(--ink-3);
      margin-bottom: 0.875rem;
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

    .about-section.accent {
      background: var(--accent-bg);
      border-color: var(--accent-border);
    }

    /* ── Feature list ── */
    .feature-list {
      list-style: none;
      padding: 0;
      display: flex;
      flex-direction: column;
      gap: 0.5rem;
      margin-top: 0.25rem;
    }
    .feature-list li {
      font-size: 0.9rem;
      color: var(--ink-2);
      line-height: 1.55;
      display: flex;
      align-items: baseline;
      gap: 0.6rem;
    }
    .feature-list li::before {
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
        <h1>About SOTA Planner</h1>
        <p>Built for the SOTA community by KI6CR</p>
    </div>

    <div class="about-section accent">
        <h2>The Problem</h2>
        <p>
            Planning a SOTA activation means piecing together information from multiple sources —
            drive time, hike distance, elevation gain, time on summit for radio, and the return
            journey. It's hard to know if you're looking at a 3-hour outing or an 8-hour day
            before you've even started researching.
        </p>
    </div>

    <div class="about-section">
        <h2>The Solution</h2>
        <p>
            <strong>SOTA Planner</strong> brings everything together in one place. Set your
            starting address, nominate summits you're interested in, research the trails, and
            instantly see the <strong>total door-to-door time</strong> for each activation —
            drive up, hike in, radio time on the summit, hike out, drive home.
        </p>
        <p>
            Whether you're squeezing in a quick activation before work or planning a full-day
            adventure, SOTA Planner helps you match the right summit to the time you actually have.
        </p>
    </div>

    <div class="about-section">
        <h2>Key Features</h2>
        <ul class="feature-list">
            <li><strong>Total time estimate</strong> — drive + hike up + activation + hike down + drive back, at a glance</li>
            <li><strong>Drive time calculation</strong> — automatic routing from your starting address to each trailhead</li>
            <li><strong>GPX track analysis</strong> — upload a recorded track to extract real-world hike time, activation time, distance, and elevation</li>
            <li><strong>Planning groups</strong> — collaborate with co-activators; share summit research, GPX tracks, and notes</li>
            <li><strong>Activation timeline</strong> — shareable invitation page for hiking partners with a visual day schedule</li>
            <li><strong>Summit search</strong> — find summits by name, no SOTA reference code needed</li>
            <li><strong>Activation zone overlay</strong> — see the terrain-based activation zone boundary on the summit map</li>
        </ul>
    </div>

    <div class="about-section">
        <h2>Who Built This</h2>
        <p>
            SOTA Planner was created by <strong>Christopher Reddick, KI6CR</strong> — a SOTA
            activator who wanted a better way to plan time-sensitive trips. It's free to use,
            no email address required.
        </p>
        <p>
            More at <a href="https://ki6cr.com" target="_blank">ki6cr.com</a>
        </p>
    </div>

</div>

<footer>
    SOTA Planner &nbsp;·&nbsp;
    <a href="changelog.php">v<?= APP_VERSION ?></a>
    &nbsp;·&nbsp;
    <a href="https://sotaplanner.com">sotaplanner.com</a>
</footer>

</body>
</html>
