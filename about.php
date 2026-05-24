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

    .about-section.accent {
      background: var(--accent-bg);
      border-color: var(--accent-border);
    }

    /* ── Feature grid ── */
    .feature-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 0.75rem;
      margin-top: 0.25rem;
    }
    .feature-card {
      background: var(--bg);
      border: 1px solid var(--border);
      border-radius: var(--r-md);
      padding: 1rem;
      display: flex;
      gap: 0.75rem;
      align-items: flex-start;
    }
    .feature-icon {
      width: 32px;
      height: 32px;
      background: var(--surface);
      border: 1px solid var(--border-2);
      border-radius: var(--r-sm);
      display: flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
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
        <div class="feature-grid">

            <div class="feature-card">
                <div class="feature-icon">
                    <svg width="18" height="18" viewBox="0 0 18 18" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <circle cx="9" cy="9" r="7.5" stroke="#1C1B19" stroke-width="1.4"/>
                        <path d="M9 5.5V9.25L11.5 11" stroke="#1C1B19" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </div>
                <div class="feature-text">
                    <div class="feature-name">Total time estimate</div>
                    <div class="feature-desc">Drive + hike up + activation + hike down + drive back, at a glance</div>
                </div>
            </div>

            <div class="feature-card">
                <div class="feature-icon">
                    <svg width="18" height="18" viewBox="0 0 18 18" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M2 9C2 5.13 5.13 2 9 2s7 3.13 7 7" stroke="#1C1B19" stroke-width="1.4" stroke-linecap="round"/>
                        <path d="M3.5 13l1.5-4 2 2 2-3.5 2 2 1.5-3.5" stroke="#1C1B19" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/>
                        <path d="M2.5 13.5h13" stroke="#1C1B19" stroke-width="1.4" stroke-linecap="round"/>
                    </svg>
                </div>
                <div class="feature-text">
                    <div class="feature-name">Drive time calculation</div>
                    <div class="feature-desc">Automatic routing from your home to each trailhead via Google Maps</div>
                </div>
            </div>

            <div class="feature-card">
                <div class="feature-icon">
                    <svg width="18" height="18" viewBox="0 0 18 18" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M1.5 13L5 7l3 4 3-7 3 6 2-3" stroke="#1C1B19" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/>
                        <circle cx="11" cy="4.5" r="1.5" fill="#2b8e8e"/>
                    </svg>
                </div>
                <div class="feature-text">
                    <div class="feature-name">GPX track analysis</div>
                    <div class="feature-desc">Upload a recorded track to extract real hike time, activation time, distance, and elevation</div>
                </div>
            </div>

            <div class="feature-card">
                <div class="feature-icon">
                    <svg width="18" height="18" viewBox="0 0 18 18" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <circle cx="7" cy="7" r="3" stroke="#1C1B19" stroke-width="1.4"/>
                        <circle cx="13" cy="7" r="3" stroke="#1C1B19" stroke-width="1.4"/>
                        <path d="M2.5 15.5c0-2.21 2.01-4 4.5-4s4.5 1.79 4.5 4" stroke="#1C1B19" stroke-width="1.4" stroke-linecap="round"/>
                        <path d="M13 11.5c1.49.37 2.5 1.6 2.5 3" stroke="#1C1B19" stroke-width="1.4" stroke-linecap="round"/>
                    </svg>
                </div>
                <div class="feature-text">
                    <div class="feature-name">Planning groups</div>
                    <div class="feature-desc">Collaborate with co-activators; share summit research, GPX tracks, and notes</div>
                </div>
            </div>

            <div class="feature-card">
                <div class="feature-icon">
                    <svg width="18" height="18" viewBox="0 0 18 18" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <rect x="1.5" y="3.5" width="15" height="12" rx="1.5" stroke="#1C1B19" stroke-width="1.4"/>
                        <path d="M5.5 1.5v4M12.5 1.5v4M1.5 7.5h15" stroke="#1C1B19" stroke-width="1.4" stroke-linecap="round"/>
                        <rect x="4" y="10" width="4" height="2" rx="0.5" fill="#2b8e8e"/>
                        <rect x="10" y="10" width="4" height="2" rx="0.5" fill="#1C1B19" fill-opacity="0.18"/>
                    </svg>
                </div>
                <div class="feature-text">
                    <div class="feature-name">Activation timeline</div>
                    <div class="feature-desc">Shareable invitation page for hiking partners with a visual day schedule</div>
                </div>
            </div>

            <div class="feature-card">
                <div class="feature-icon">
                    <svg width="18" height="18" viewBox="0 0 18 18" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <circle cx="8" cy="8" r="5.5" stroke="#1C1B19" stroke-width="1.4"/>
                        <path d="M12.5 12.5l3.5 3.5" stroke="#1C1B19" stroke-width="1.4" stroke-linecap="round"/>
                    </svg>
                </div>
                <div class="feature-text">
                    <div class="feature-name">Summit search</div>
                    <div class="feature-desc">Find summits by name — no SOTA reference code needed</div>
                </div>
            </div>

            <div class="feature-card">
                <div class="feature-icon">
                    <svg width="18" height="18" viewBox="0 0 18 18" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M9 2C6.24 2 4 4.24 4 7c0 3.75 5 9 5 9s5-5.25 5-9c0-2.76-2.24-5-5-5z" stroke="#1C1B19" stroke-width="1.4" stroke-linejoin="round"/>
                        <circle cx="9" cy="7" r="1.5" fill="#2b8e8e"/>
                        <ellipse cx="9" cy="7" rx="4" ry="2" stroke="#1C1B19" stroke-width="1" stroke-dasharray="2 1.5" opacity="0.5"/>
                    </svg>
                </div>
                <div class="feature-text">
                    <div class="feature-name">Activation zone overlay</div>
                    <div class="feature-desc">Terrain-based activation zone boundary shown on the summit map</div>
                </div>
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
