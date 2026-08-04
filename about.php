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
      max-width: 700px;
      margin: 0 auto;
      padding: var(--sp-8) var(--sp-8) 5rem;
    }

    /* ── Hero ── */
    .hero {
      display: flex;
      flex-direction: column;
      align-items: center;
      text-align: center;
      padding: 2.5rem 1rem 2rem;
      margin-bottom: var(--sp-8);
    }
    .hero-logo {
      width: 88px;
      height: 88px;
      margin-bottom: 1.25rem;
    }
    .hero h1 {
      font-size: 1.75rem;
      font-weight: 700;
      color: var(--ink);
      letter-spacing: -0.02em;
      margin-bottom: 0.4rem;
    }
    .hero p {
      font-size: 0.9375rem;
      color: var(--ink-3);
      max-width: 380px;
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
      .hero h1 { font-size: 1.5rem; }
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

    <div class="hero">
        <img src="sota-planner-logo.svg" class="hero-logo" alt="SOTA Planner logo">
        <h1>About SOTA Planner</h1>
        <p>Doorstep-to-doorstep time planning for SOTA activations</p>
    </div>

    <!-- Hub-and-spoke illustration: inputs scattered on left → SOTAplanner logo on right -->
    <div class="about-section" style="padding:0;overflow:hidden;margin-bottom:1rem;">
        <svg viewBox="0 0 680 460" xmlns="http://www.w3.org/2000/svg" style="width:100%;display:block;max-width:100%;" aria-label="Five inputs — Summit, Travel Time, Hike Time, Trail, Activation Time — all flow into SOTAplanner">
          <defs>
            <marker id="arr" markerWidth="9" markerHeight="9" refX="7.5" refY="4.5" orient="auto">
              <path d="M1.5,1.5 L7.5,4.5 L1.5,7.5" fill="none" stroke="#C2BDB4" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
            </marker>
          </defs>

          <!-- Background -->
          <rect width="680" height="460" fill="#F7F6F3" rx="12"/>

          <!-- ── ARROWS (icon right edge → hub left edge) ── -->
          <line x1="85"  y1="55"  x2="505" y2="202" stroke="#D4D0C8" stroke-width="1.5" marker-end="url(#arr)"/>
          <line x1="168" y1="140" x2="502" y2="211" stroke="#D4D0C8" stroke-width="1.5" marker-end="url(#arr)"/>
          <line x1="82"  y1="228" x2="500" y2="228" stroke="#D4D0C8" stroke-width="1.5" marker-end="url(#arr)"/>
          <line x1="170" y1="315" x2="502" y2="245" stroke="#D4D0C8" stroke-width="1.5" marker-end="url(#arr)"/>
          <line x1="85"  y1="400" x2="505" y2="254" stroke="#D4D0C8" stroke-width="1.5" marker-end="url(#arr)"/>

          <!-- ── HUB: SOTAplanner logo (r=80) ── -->
          <circle cx="580" cy="228" r="80" fill="white" stroke="#D8C890" stroke-width="2"/>
          <g transform="translate(580,228) scale(1.36) translate(-55,-55)">
            <circle fill="none" stroke="#1c1b19" stroke-width="1.5" cx="55" cy="55" r="50"/>
            <path fill="none" stroke="#8c8a86" stroke-width=".5" opacity=".2" d="M18,75.5c11.33-4,23.67-5,37-3,13.33-3.33,25.67-3.67,37-1"/>
            <path fill="none" stroke="#8c8a86" stroke-width=".5" opacity=".15" d="M22,81.5c12-4,23-5,33-3,13.33-3.33,24.33-3.67,33-1"/>
            <path fill="none" stroke="#1c1b19" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" d="M26,79.5l17-30,7,8,12-20,22,42"/>
            <circle fill="#2b8e8e" cx="62" cy="35.5" r="3.5"/>
            <circle fill="none" stroke="#2b8e8e" stroke-width="1.2" opacity=".45" cx="62" cy="35.5" r="9"/>
            <circle fill="none" stroke="#2b8e8e" stroke-width=".8" opacity=".2" cx="62" cy="35.5" r="15"/>
          </g>
          <text x="580" y="323" text-anchor="middle" font-family="DM Sans,system-ui,sans-serif" font-size="14" font-weight="700" fill="#1C1B19" letter-spacing="-0.02em">SOTAplanner</text>
          <text x="580" y="338" text-anchor="middle" font-family="DM Sans,system-ui,sans-serif" font-size="9.5" font-weight="400" fill="#8C8A86" letter-spacing="0.01em">the complete picture</text>

          <!-- ── ICON CIRCLES — all use toolkit icons (viewBox 0 0 24 24, scale 1.8) ── -->

          <!-- 1. Summit (55, 55) — staggered LEFT -->
          <circle cx="55" cy="55" r="30" fill="white" stroke="#E5E2DA" stroke-width="1.5"/>
          <g transform="translate(55,55) scale(1.8) translate(-12,-12)" fill="none" stroke="#4A4844" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
            <path d="M3 20L12 4L21 20H3Z"/>
            <path d="M9 20L12 13L15 17"/>
          </g>
          <text x="55" y="98" text-anchor="middle" font-family="DM Sans,system-ui,sans-serif" font-size="11.5" font-weight="600" fill="#1C1B19">Summit</text>

          <!-- 2. Trail Info (138, 140) — staggered RIGHT -->
          <circle cx="138" cy="140" r="30" fill="white" stroke="#E5E2DA" stroke-width="1.5"/>
          <g transform="translate(138,140) scale(1.8) translate(-12,-12)" fill="none" stroke="#4A4844" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
            <polygon points="1,6 1,22 8,18 16,22 23,18 23,2 16,6 8,2"/>
            <line x1="8" y1="2" x2="8" y2="18"/>
            <line x1="16" y1="6" x2="16" y2="22"/>
          </g>
          <text x="138" y="183" text-anchor="middle" font-family="DM Sans,system-ui,sans-serif" font-size="11.5" font-weight="600" fill="#1C1B19">Trail Info</text>

          <!-- 3. Travel Time (52, 228) — staggered LEFT -->
          <circle cx="52" cy="228" r="30" fill="white" stroke="#E5E2DA" stroke-width="1.5"/>
          <g transform="translate(52,228) scale(1.8) translate(-12,-12)" fill="none" stroke="#4A4844" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
            <path d="M21 10c0 7-9 13-9 13S3 17 3 10a9 9 0 1 1 18 0z"/>
            <circle cx="12" cy="10" r="3"/>
          </g>
          <text x="52" y="271" text-anchor="middle" font-family="DM Sans,system-ui,sans-serif" font-size="11.5" font-weight="600" fill="#1C1B19">Travel Time</text>

          <!-- 4. Hike Time (140, 315) — staggered RIGHT -->
          <circle cx="140" cy="315" r="30" fill="white" stroke="#E5E2DA" stroke-width="1.5"/>
          <g transform="translate(140,315) scale(1.8) translate(-12,-12)" fill="none" stroke="#4A4844" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="12" cy="4" r="1.5" fill="#4A4844" stroke="none"/>
            <line x1="12" y1="5.5" x2="11" y2="13"/>
            <rect x="8" y="5.5" width="3.5" height="5.5" rx="1"/>
            <line x1="16" y1="7" x2="18" y2="22"/>
            <line x1="11" y1="9" x2="16" y2="8"/>
            <line x1="11" y1="13" x2="8" y2="22"/>
            <line x1="11" y1="13" x2="14" y2="22"/>
          </g>
          <text x="140" y="358" text-anchor="middle" font-family="DM Sans,system-ui,sans-serif" font-size="11.5" font-weight="600" fill="#1C1B19">Hike Time</text>

          <!-- 5. Activation Time (55, 400) — staggered LEFT (exact toolkit 'radio' icon) -->
          <circle cx="55" cy="400" r="30" fill="white" stroke="#E5E2DA" stroke-width="1.5"/>
          <g transform="translate(55,400) scale(1.8) translate(-12,-12)" fill="none" stroke="#4A4844" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
            <rect x="7" y="8" width="8" height="13" rx="2"/>
            <line x1="12" y1="8" x2="12" y2="3"/>
            <rect x="9" y="10" width="4" height="3" rx="0.5"/>
            <circle cx="11" cy="17" r="1.5" fill="#4A4844" stroke="none"/>
          </g>
          <text x="55" y="443" text-anchor="middle" font-family="DM Sans,system-ui,sans-serif" font-size="11.5" font-weight="600" fill="#1C1B19">Activation time</text>
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
            SOTA Planner was created by <strong>Chris Reddick, KI6CR</strong> — a SOTA
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
