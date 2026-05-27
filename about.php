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

    <!-- Journey illustration -->
    <div class="about-section" style="padding:0;overflow:hidden;margin-bottom:1rem;">
        <svg viewBox="0 0 640 270" xmlns="http://www.w3.org/2000/svg" style="width:100%;display:block;max-width:100%;" aria-label="Illustration of a SOTA activation day: drive to trailhead, hike up, radio activation at summit, hike down, drive home">
          <defs>
            <linearGradient id="g-sky" x1="0" y1="0" x2="0" y2="1">
              <stop offset="0%" stop-color="#E4E0D8"/>
              <stop offset="100%" stop-color="#F2EFE9"/>
            </linearGradient>
            <linearGradient id="g-mtn" x1="0.25" y1="0" x2="0.75" y2="1">
              <stop offset="0%" stop-color="#C8C4B8"/>
              <stop offset="100%" stop-color="#DDD9D0"/>
            </linearGradient>
            <clipPath id="bar-clip">
              <rect x="40" y="220" width="560" height="26" rx="5"/>
            </clipPath>
          </defs>

          <!-- Sky -->
          <rect width="640" height="270" fill="url(#g-sky)"/>

          <!-- Distant ridge (background layer) -->
          <path d="M0,200 L40,182 L80,195 L115,170 L155,188 L185,165 L220,185 L640,185 L640,210 L0,210Z" fill="#D8D4CB" opacity="0.45"/>

          <!-- Main mountain -->
          <path d="M-10,202 L50,202 L105,164 L140,178 L200,122 L248,144 L320,44 L392,144 L440,122 L500,178 L535,164 L590,202 L650,202 L650,270 L-10,270Z" fill="url(#g-mtn)" stroke="#C2BDB4" stroke-width="1.5"/>

          <!-- Snow cap -->
          <path d="M308,70 L320,44 L332,70 L325,74 L315,74Z" fill="rgba(255,255,255,0.7)"/>

          <!-- ── JOURNEY PATH ── -->
          <!-- Drive (left) -->
          <path d="M40,196 L132,190" stroke="#4A72A0" stroke-width="3" stroke-dasharray="8,5" stroke-linecap="round" fill="none"/>
          <!-- Hike up -->
          <path d="M132,190 L228,128 L320,50" stroke="#2E7A50" stroke-width="3" stroke-dasharray="8,5" stroke-linecap="round" fill="none"/>
          <!-- Hike down -->
          <path d="M320,50 L412,128 L508,190" stroke="#2E7A50" stroke-width="3" stroke-dasharray="8,5" stroke-linecap="round" fill="none" opacity="0.8"/>
          <!-- Drive (right) -->
          <path d="M508,190 L600,196" stroke="#4A72A0" stroke-width="3" stroke-dasharray="8,5" stroke-linecap="round" fill="none" opacity="0.8"/>

          <!-- ── ICONS ── -->

          <!-- House (left) -->
          <g transform="translate(40,190)">
            <rect x="-9" y="-7" width="18" height="13" rx="2" fill="white" stroke="#8C8A86" stroke-width="1.5"/>
            <path d="M-12,-7 L0,-21 L12,-7" fill="#EFEDE8" stroke="#8C8A86" stroke-width="1.5" stroke-linejoin="round"/>
            <rect x="-4" y="-1" width="8" height="7" fill="#B8B5B0" rx="1"/>
          </g>

          <!-- Car (left trailhead) -->
          <g transform="translate(132,183)">
            <rect x="-14" y="-6" width="28" height="10" rx="3" fill="#DDEAF7" stroke="#4A72A0" stroke-width="1.5"/>
            <rect x="-8" y="-13" width="16" height="9" rx="2" fill="#DDEAF7" stroke="#4A72A0" stroke-width="1.5"/>
            <circle cx="-8" cy="5" r="4" fill="#4A72A0"/>
            <circle cx="8" cy="5" r="4" fill="#4A72A0"/>
            <circle cx="-8" cy="5" r="1.8" fill="white"/>
            <circle cx="8" cy="5" r="1.8" fill="white"/>
          </g>

          <!-- Hiker (ascending) -->
          <g transform="translate(226,122)">
            <circle cx="0" cy="-15" r="5.5" fill="white" stroke="#2E7A50" stroke-width="1.5"/>
            <path d="M0,-9 L0,2" stroke="#2E7A50" stroke-width="2.5" stroke-linecap="round"/>
            <path d="M0,-1 L-7,6" stroke="#2E7A50" stroke-width="2" stroke-linecap="round"/>
            <path d="M0,-1 L7,4" stroke="#2E7A50" stroke-width="2" stroke-linecap="round"/>
            <path d="M0,2 L-5,13" stroke="#2E7A50" stroke-width="2.5" stroke-linecap="round"/>
            <path d="M0,2 L5,13" stroke="#2E7A50" stroke-width="2.5" stroke-linecap="round"/>
            <path d="M7,4 L11,13" stroke="#2E7A50" stroke-width="1.5" stroke-linecap="round"/>
          </g>

          <!-- Summit: radio burst -->
          <g transform="translate(320,50)">
            <circle cx="0" cy="0" r="18" fill="#B87830" opacity="0.12"/>
            <circle cx="0" cy="0" r="10" fill="#B87830"/>
            <!-- Antenna mast -->
            <line x1="0" y1="-10" x2="0" y2="-24" stroke="#B87830" stroke-width="2.5" stroke-linecap="round"/>
            <line x1="-5" y1="-18" x2="5" y2="-18" stroke="#B87830" stroke-width="2" stroke-linecap="round"/>
            <!-- Signal arcs -->
            <path d="M-8,-20 Q-15,-13 -13,-5" stroke="#B87830" stroke-width="1.5" fill="none" opacity="0.6" stroke-linecap="round"/>
            <path d="M8,-20 Q15,-13 13,-5" stroke="#B87830" stroke-width="1.5" fill="none" opacity="0.6" stroke-linecap="round"/>
            <path d="M-12,-25 Q-22,-15 -19,-5" stroke="#B87830" stroke-width="1" fill="none" opacity="0.32" stroke-linecap="round"/>
            <path d="M12,-25 Q22,-15 19,-5" stroke="#B87830" stroke-width="1" fill="none" opacity="0.32" stroke-linecap="round"/>
            <!-- Waveform inside circle -->
            <path d="M-5,-1 L-2,3 L2,-1 L5,3" stroke="white" stroke-width="1.8" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
          </g>

          <!-- Hiker (descending) -->
          <g transform="translate(414,122)">
            <circle cx="0" cy="-15" r="5.5" fill="white" stroke="#2E7A50" stroke-width="1.5"/>
            <path d="M0,-9 L0,2" stroke="#2E7A50" stroke-width="2.5" stroke-linecap="round"/>
            <path d="M0,-1 L-7,4" stroke="#2E7A50" stroke-width="2" stroke-linecap="round"/>
            <path d="M0,-1 L7,6" stroke="#2E7A50" stroke-width="2" stroke-linecap="round"/>
            <path d="M0,2 L-5,13" stroke="#2E7A50" stroke-width="2.5" stroke-linecap="round"/>
            <path d="M0,2 L5,13" stroke="#2E7A50" stroke-width="2.5" stroke-linecap="round"/>
            <path d="M-7,4 L-11,13" stroke="#2E7A50" stroke-width="1.5" stroke-linecap="round"/>
          </g>

          <!-- Car (right trailhead) -->
          <g transform="translate(508,183)">
            <rect x="-14" y="-6" width="28" height="10" rx="3" fill="#DDEAF7" stroke="#4A72A0" stroke-width="1.5"/>
            <rect x="-8" y="-13" width="16" height="9" rx="2" fill="#DDEAF7" stroke="#4A72A0" stroke-width="1.5"/>
            <circle cx="-8" cy="5" r="4" fill="#4A72A0"/>
            <circle cx="8" cy="5" r="4" fill="#4A72A0"/>
            <circle cx="-8" cy="5" r="1.8" fill="white"/>
            <circle cx="8" cy="5" r="1.8" fill="white"/>
          </g>

          <!-- House (right) -->
          <g transform="translate(600,190)">
            <rect x="-9" y="-7" width="18" height="13" rx="2" fill="white" stroke="#8C8A86" stroke-width="1.5"/>
            <path d="M-12,-7 L0,-21 L12,-7" fill="#EFEDE8" stroke="#8C8A86" stroke-width="1.5" stroke-linejoin="round"/>
            <rect x="-4" y="-1" width="8" height="7" fill="#B8B5B0" rx="1"/>
          </g>

          <!-- ── FLOATING LABELS ── -->
          <!-- Drive (left) -->
          <rect x="57" y="203" width="38" height="15" rx="3" fill="white" opacity="0.82"/>
          <text x="76" y="214" text-anchor="middle" font-family="DM Sans,system-ui,sans-serif" font-size="9" font-weight="700" fill="#4A72A0" letter-spacing="0.07em">DRIVE</text>

          <!-- Hike Up -->
          <rect x="148" y="152" width="52" height="15" rx="3" fill="white" opacity="0.82"/>
          <text x="174" y="163" text-anchor="middle" font-family="DM Sans,system-ui,sans-serif" font-size="9" font-weight="700" fill="#2E7A50" letter-spacing="0.07em">HIKE UP</text>

          <!-- Activate -->
          <rect x="334" y="34" width="62" height="15" rx="3" fill="white" opacity="0.82"/>
          <text x="365" y="45" text-anchor="middle" font-family="DM Sans,system-ui,sans-serif" font-size="9" font-weight="700" fill="#B87830" letter-spacing="0.07em">ACTIVATE</text>

          <!-- Hike Down -->
          <rect x="436" y="152" width="68" height="15" rx="3" fill="white" opacity="0.82"/>
          <text x="470" y="163" text-anchor="middle" font-family="DM Sans,system-ui,sans-serif" font-size="9" font-weight="700" fill="#2E7A50" letter-spacing="0.07em">HIKE DOWN</text>

          <!-- Drive (right) -->
          <rect x="525" y="203" width="38" height="15" rx="3" fill="white" opacity="0.82"/>
          <text x="544" y="214" text-anchor="middle" font-family="DM Sans,system-ui,sans-serif" font-size="9" font-weight="700" fill="#4A72A0" letter-spacing="0.07em">DRIVE</text>

          <!-- ── TIMELINE BAR ── -->
          <!-- Total 315 min, bar x=40–600 (560px) -->
          <!-- Drive:80 | HikeUp:160 | Activate:107 | HikeDown:133 | Drive:80 -->
          <g clip-path="url(#bar-clip)">
            <rect x="40"  y="220" width="80"  height="26" fill="#4A72A0"/>
            <rect x="120" y="220" width="160" height="26" fill="#2E7A50"/>
            <rect x="280" y="220" width="107" height="26" fill="#B87830"/>
            <rect x="387" y="220" width="133" height="26" fill="#2E7A50" opacity="0.82"/>
            <rect x="520" y="220" width="80"  height="26" fill="#4A72A0" opacity="0.82"/>
          </g>

          <!-- Hairline dividers between segments -->
          <line x1="120" y1="220" x2="120" y2="246" stroke="rgba(0,0,0,0.12)" stroke-width="1"/>
          <line x1="280" y1="220" x2="280" y2="246" stroke="rgba(0,0,0,0.12)" stroke-width="1"/>
          <line x1="387" y1="220" x2="387" y2="246" stroke="rgba(0,0,0,0.12)" stroke-width="1"/>
          <line x1="520" y1="220" x2="520" y2="246" stroke="rgba(0,0,0,0.12)" stroke-width="1"/>

          <!-- Bar labels -->
          <text x="80"   y="236" text-anchor="middle" font-family="DM Sans,system-ui,sans-serif" font-size="8.5" font-weight="700" fill="white">Drive</text>
          <text x="200"  y="236" text-anchor="middle" font-family="DM Sans,system-ui,sans-serif" font-size="8.5" font-weight="700" fill="white">Hike Up</text>
          <text x="333"  y="236" text-anchor="middle" font-family="DM Sans,system-ui,sans-serif" font-size="8.5" font-weight="700" fill="white">Activate</text>
          <text x="453"  y="236" text-anchor="middle" font-family="DM Sans,system-ui,sans-serif" font-size="8.5" font-weight="700" fill="white">Hike Down</text>
          <text x="560"  y="236" text-anchor="middle" font-family="DM Sans,system-ui,sans-serif" font-size="8.5" font-weight="700" fill="white">Drive</text>

          <!-- Total -->
          <text x="320" y="260" text-anchor="middle" font-family="DM Sans,system-ui,sans-serif" font-size="10.5" font-weight="600" fill="#4A4844" letter-spacing="-0.01em">Total: 5 hr 15 min, door to door</text>
        </svg>
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
