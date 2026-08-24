<?php
require_once 'config.php';
session_start();

// Already logged in — go to dashboard (or group picker if no group set)
if (isset($_SESSION['sota_callsign'])) {
    header('Location: ' . (isset($_SESSION['current_planning_group_id']) ? 'index.php' : 'planning_groups.php'));
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['dev_login'])) {
        $callsign = strtoupper(trim($_POST['callsign'] ?? ''));

        if ($callsign !== '' && preg_match('/^[A-Z0-9]{3,10}$/', $callsign)) {
            $_SESSION['sota_callsign']   = $callsign;
            $_SESSION['sota_login_type'] = 'early_access';

            // Determine redirect based on group membership
            try {
                $db = getDbConnection();

                // Fetch all groups this user belongs to, owned groups first
                $stmt = $db->prepare("
                    SELECT DISTINCT pg.id,
                           CASE WHEN pg.owner_callsign = :cs THEN 0 ELSE 1 END AS sort_order
                    FROM planning_groups pg
                    LEFT JOIN planning_group_members pgm ON pg.id = pgm.planning_group_id
                    WHERE pg.owner_callsign = :cs2 OR pgm.callsign = :cs3
                    ORDER BY sort_order ASC, pg.id ASC
                ");
                $stmt->execute([':cs' => $callsign, ':cs2' => $callsign, ':cs3' => $callsign]);
                $groups = $stmt->fetchAll(PDO::FETCH_COLUMN);

                if (count($groups) === 0) {
                    // New user — no groups yet
                    header('Location: onboarding.php');
                } else {
                    // Returning user — pick best group: saved default cookie > owned/first group
                    $target_group = null;

                    if (!empty($_COOKIE['sota_default_group'])) {
                        $cookie_id = (int)$_COOKIE['sota_default_group'];
                        if (in_array($cookie_id, $groups)) {
                            $target_group = $cookie_id;
                        }
                    }

                    if (!$target_group) {
                        $target_group = $groups[0]; // owned group first, then oldest
                    }

                    $_SESSION['current_planning_group_id'] = $target_group;
                    header('Location: index.php');
                }
            } catch (PDOException $e) {
                // DB error — fall back to group picker
                header('Location: planning_groups.php');
            }
            exit;
        } else {
            $error = 'Please enter a valid callsign (letters and numbers only).';
        }
    }
}

$sota_oauth_enabled = defined('SOTA_CLIENT_ID') && SOTA_CLIENT_ID !== '';

// Count summits ready to activate:
// 1. Global GPX library tracks that have a trailhead
// 2. Drive-up summits not already in bucket 1
// 3. Group-researched summits with manual trailhead + hike data, not already in buckets 1 or 2
$ready_count = 0;
try {
    $db_stat = getDbConnection();
    $stat_stmt = $db_stat->query("SELECT COUNT(*) FROM global_gpx_tracks WHERE trailhead_lat IS NOT NULL AND trailhead_lon IS NOT NULL");
    $ready_count = (int)$stat_stmt->fetchColumn();
    $drive_up_stmt = $db_stat->query("SELECT COUNT(DISTINCT sota_ref) FROM summits WHERE difficulty = 'drive-up' AND sota_ref IS NOT NULL AND sota_ref != '' AND sota_ref NOT IN (SELECT sota_ref FROM global_gpx_tracks WHERE trailhead_lat IS NOT NULL)");
    $ready_count += (int)$drive_up_stmt->fetchColumn();
    $manual_stmt = $db_stat->query("SELECT COUNT(DISTINCT sota_ref) FROM summits WHERE sota_ref IS NOT NULL AND sota_ref != '' AND trailhead_lat IS NOT NULL AND trailhead_lng IS NOT NULL AND (hike_distance_mi IS NOT NULL OR hike_time_up_min IS NOT NULL OR hike_elevation_gain_ft IS NOT NULL) AND difficulty != 'drive-up' AND sota_ref NOT IN (SELECT sota_ref FROM global_gpx_tracks WHERE trailhead_lat IS NOT NULL AND trailhead_lon IS NOT NULL)");
    $ready_count += (int)$manual_stmt->fetchColumn();
} catch (PDOException $e) {
    $ready_count = 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SOTA Planner — Plan Your Activations</title>
    <link href="https://fonts.googleapis.com/css2?family=Overpass:wght@300;600;800&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Overpass', sans-serif;
            background: #f5f5f0;
            color: #1E3A5F;
            min-height: 100vh;
        }

        /* ── Hero ── */
        .hero {
            background: linear-gradient(150deg, #1E3A5F 0%, #4A3A2A 60%, #9B6328 100%);
            color: white;
            padding: 3rem 1.5rem 2.5rem;
            text-align: center;
            position: sticky;
            top: 0;
            z-index: 1;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }

        .hero-illustration-wrap {
            width: 100%;
            max-width: 880px;
            margin: 0 auto 1.5rem;
        }

        .hero-illustration-wrap svg {
            display: block;
            width: 100%;
            height: auto;
        }

        /* Bouncing "scroll" cues, flanking the stat pill left/right so they never sit on
           top of it (previously a single centered cue overlapped the pill below it). */
        .hero-stat-row {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 1rem;
        }

        .scroll-cue-arrow {
            flex-shrink: 0;
            color: rgba(255,255,255,0.65);
            opacity: 0;
            animation: scroll-cue-in 0.6s ease forwards, scroll-cue-bounce 1.6s ease-in-out infinite;
            animation-delay: 1.5s, 1.5s;
            pointer-events: none;
        }
        @keyframes scroll-cue-in { to { opacity: 1; } }

        @keyframes scroll-cue-bounce {
            0%, 100% { transform: translateY(0); }
            50%      { transform: translateY(5px); }
        }

        @media (prefers-reduced-motion: reduce) {
            .scroll-cue-arrow { animation: scroll-cue-in 0.6s ease forwards; animation-delay: 1.5s; }
        }

        .scroll-cue-label {
            display: block;
            text-align: center;
            margin-top: 0.6rem;
            font-size: 0.68rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.14em;
            color: rgba(255,255,255,0.55);
            opacity: 0;
            animation: scroll-cue-in 0.6s ease forwards;
            animation-delay: 1.5s;
            pointer-events: none;
        }

        .hero h1 {
            font-size: 2.4rem;
            font-weight: 800;
            letter-spacing: -0.01em;
            margin-bottom: 0.5rem;
        }

        .hero .tagline {
            font-size: 1.05rem;
            font-weight: 300;
            opacity: 0.82;
            max-width: 520px;
            margin: 0 auto 0.5rem;
            line-height: 1.5;
        }

        /* ── Feature strip ── */
        /* Full-bleed opaque panel holding just the tiles. It parks (sticks) at the top of the
           viewport once scrolled into place, instead of continuing to scroll off-screen — the
           login card below is a normal-flow sibling that scrolls up underneath it. Covering the
           hero is handled by fading the hero's own background out (see the scroll-scrub JS), not
           by this panel's opacity/size, so there's no dependency on exact pixel coverage. */
        .features-panel {
            position: sticky;
            top: 0;
            z-index: 2;
            background: #f5f5f0;
        }

        .features {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 0;
            max-width: 960px;
            margin: 0 auto;
            padding: 2rem 1.5rem 0;
        }

        .feature {
            padding: 1.5rem 1.25rem;
            text-align: center;
        }

        .feature-icon {
            font-size: 2rem;
            margin-bottom: 0.6rem;
            display: block;
        }

        .feature h3 {
            font-size: 0.95rem;
            font-weight: 800;
            color: #1E3A5F;
            margin-bottom: 0.4rem;
        }

        .feature p {
            font-size: 0.83rem;
            color: #666;
            line-height: 1.55;
        }

        /* ── How it works strip ── */
        .how-strip {
            background: white;
            border-top: 1px solid #e8e4d8;
            border-bottom: 1px solid #e8e4d8;
            padding: 1.5rem;
            margin: 1.5rem 0 0;
        }

        .how-strip-inner {
            max-width: 760px;
            margin: 0 auto;
            display: flex;
            align-items: flex-start;
            gap: 2rem;
            flex-wrap: wrap;
        }

        .how-step {
            flex: 1;
            min-width: 160px;
            display: flex;
            gap: 0.75rem;
            align-items: flex-start;
        }

        .step-num {
            background: #1E3A5F;
            color: white;
            font-size: 0.7rem;
            font-weight: 800;
            width: 22px;
            height: 22px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            margin-top: 2px;
        }

        .how-step div p {
            font-size: 0.82rem;
            color: #555;
            line-height: 1.45;
            margin-top: 0.2rem;
        }

        .how-step div strong {
            font-size: 0.88rem;
            color: #1E3A5F;
        }

        /* ── Login card ── */
        .login-wrap {
            max-width: 420px;
            margin: 2rem auto 3rem;
            padding: 0 1.25rem;
        }

        .login-card {
            background: white;
            border-radius: 16px;
            padding: 2rem 2rem 1.75rem;
            box-shadow: 0 4px 24px rgba(0,0,0,0.1);
        }

        .sota-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.6rem;
            width: 100%;
            padding: 0.9rem 1rem;
            border: none;
            border-radius: 8px;
            font-family: 'Overpass', sans-serif;
            font-size: 1rem;
            font-weight: 700;
            line-height: 1.3;
            text-align: center;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.2s;
        }

        .sota-btn-main {
            background: linear-gradient(135deg, #1E3A5F 0%, #4A3A2A 100%);
            color: white;
        }

        .sota-btn-main:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 18px rgba(155,99,40,0.35);
        }

        .sota-btn-disabled {
            background: #f0f0f0;
            color: #bbb;
            cursor: not-allowed;
        }

        .coming-soon-note {
            font-size: 0.75rem;
            color: #bbb;
            text-align: center;
            margin-top: 0.5rem;
        }

        .divider {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin: 1.25rem 0;
        }

        .divider::before, .divider::after {
            content: '';
            flex: 1;
            height: 1px;
            background: #eee;
        }

        .divider span {
            font-size: 0.72rem;
            color: #ccc;
            font-weight: 700;
            white-space: nowrap;
            text-transform: uppercase;
            letter-spacing: 0.06em;
        }

        .form-group {
            margin-bottom: 0.9rem;
        }

        .form-group label {
            display: block;
            font-size: 0.75rem;
            font-weight: 700;
            color: #555;
            margin-bottom: 0.35rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .form-group input {
            width: 100%;
            padding: 0.7rem 0.9rem;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-family: 'Overpass', sans-serif;
            font-size: 1rem;
            color: #1E3A5F;
            transition: border-color 0.2s;
        }

        .form-group input:focus {
            outline: none;
            border-color: #9B6328;
        }

        .submit-btn {
            width: 100%;
            padding: 0.85rem;
            border: none;
            border-radius: 8px;
            background: linear-gradient(135deg, #E6B84A 0%, #D4A574 100%);
            color: white;
            font-family: 'Overpass', sans-serif;
            font-size: 1rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-top: 0.25rem;
        }

        .submit-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 18px rgba(230,184,74,0.4);
        }

        .error-msg {
            background: #fff3f3;
            border: 1px solid #fca5a5;
            color: #dc2626;
            padding: 0.7rem 0.9rem;
            border-radius: 8px;
            font-size: 0.85rem;
            font-weight: 600;
            margin-bottom: 1rem;
        }

        .dev-badge {
            display: inline-block;
            font-size: 0.6rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            background: #fff3cd;
            color: #856404;
            border: 1px solid #ffc107;
            border-radius: 3px;
            padding: 0.05rem 0.35rem;
            vertical-align: middle;
            margin-left: 0.3rem;
        }

        footer {
            text-align: center;
            padding: 1.5rem 1rem;
            color: #bbb;
            font-size: 0.75rem;
        }

        footer a { color: #bbb; text-decoration: none; }

        .hero-stat-pill {
            display: inline-flex;
            align-items: center;
            gap: 0.6rem;
            background: rgba(255,255,255,0.12);
            border: 1px solid rgba(255,255,255,0.22);
            border-radius: 100px;
            padding: 0.55rem 1.25rem 0.55rem 0.85rem;
            font-size: 1rem;
            font-weight: 600;
            color: rgba(255,255,255,0.92);
            margin-top: 1rem;
            letter-spacing: 0.01em;
        }

        .hero-stat-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: #6ee7b7;
            flex-shrink: 0;
        }

        .hero-stat-num {
            font-weight: 800;
            color: #fff;
        }

        @media (max-width: 600px) {
            .hero-illustration-wrap { max-width: 460px; }
            .hero h1 { font-size: 1.8rem; }
            .features { grid-template-columns: 1fr; padding: 1.25rem 0.75rem 0; }
            .feature { padding: 1rem 0.75rem; }
            .login-card { padding: 1.5rem 1.25rem; }
            .sota-btn { font-size: 0.9rem; padding: 0.85rem 0.75rem; gap: 0.45rem; }
            .hero-stat-pill { font-size: 0.85rem; padding: 0.5rem 1rem 0.5rem 0.75rem; text-align: left; }
        }
    </style>
    <noscript><style>.hero-illustration, .hero-reveal, .feature, .how-step, .login-card-item { opacity: 1 !important; transform: none !important; }</style></noscript>
</head>
<body>

<!-- Hero -->
<div class="hero">
    <!-- Data-source bubbles converging into the SOTAplanner mark as the user scrolls — same
         illustration/technique as about.php's "converge-section" (see the vanilla scroll-scrub
         script near the bottom of this file), floating directly on the hero's dark gradient
         (glass-style bubbles, no background card) rather than about.php's light card version. -->
    <div class="hero-illustration-wrap hero-illustration" style="opacity:0;" id="hero-converge">
        <svg viewBox="0 0 800 520" xmlns="http://www.w3.org/2000/svg" aria-label="Summit, Trail Info, Travel Time, Cell Coverage, Hike Time, and Activation Time all combine into one SOTAplanner estimate">
          <defs>
            <marker id="arr" markerWidth="9" markerHeight="9" refX="7.5" refY="4.5" orient="auto">
              <path d="M1.5,1.5 L7.5,4.5 L1.5,7.5" fill="none" stroke="rgba(255,255,255,0.22)" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
            </marker>
            <!-- Forces the logo image to pure white, same effect as the hero's old
                 filter:brightness(0) invert(1) treatment on <img>, so the hub shows the
                 exact production logo mark/wordmark rather than a hand-redrawn copy. -->
            <filter id="toWhite" color-interpolation-filters="sRGB">
              <feColorMatrix type="matrix" values="0 0 0 0 1  0 0 0 0 1  0 0 0 0 1  0 0 0 1 0"/>
            </filter>
          </defs>

          <!-- Arrows stop well short of the (large) logo — a deliberate hover gap, not a
               tangent — same distance the nodes converge to on scroll. Coordinates computed
               against the hub's 340x340 bounding box plus a 32-unit clearance. -->
          <g class="conv-arrows">
          <line x1="111.7" y1="105"  x2="217.7" y2="162"   stroke="rgba(255,255,255,0.16)" stroke-width="1.5" marker-end="url(#arr)"/>
          <line x1="186"   y1="260"  x2="216"   y2="260"   stroke="rgba(255,255,255,0.16)" stroke-width="1.5" marker-end="url(#arr)"/>
          <line x1="111.7" y1="415"  x2="217.7" y2="358"   stroke="rgba(255,255,255,0.16)" stroke-width="1.5" marker-end="url(#arr)"/>
          <line x1="688.3" y1="105"  x2="582.3" y2="162"   stroke="rgba(255,255,255,0.16)" stroke-width="1.5" marker-end="url(#arr)"/>
          <line x1="614"   y1="260"  x2="584"   y2="260"   stroke="rgba(255,255,255,0.16)" stroke-width="1.5" marker-end="url(#arr)"/>
          <line x1="688.3" y1="415"  x2="582.3" y2="358"   stroke="rgba(255,255,255,0.16)" stroke-width="1.5" marker-end="url(#arr)"/>
          </g>

          <!-- Large and in charge — same proportions as the production logo, just scaled up
               to be the clear focal point of the diagram. -->
          <g class="hub-group">
          <image href="sota-planner-logo-font.svg" x="230" y="90" width="340" height="340" filter="url(#toWhite)"/>
          </g>

          <g class="conv-node" data-cx="80" data-cy="88">
          <circle cx="80" cy="88" r="30" fill="rgba(255,255,255,0.12)" stroke="rgba(255,255,255,0.28)" stroke-width="1.5"/>
          <g transform="translate(80,88) scale(1.8) translate(-12,-12)" fill="none" stroke="#ffffff" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
            <path d="M3 20L12 4L21 20H3Z"/>
            <path d="M9 20L12 13L15 17"/>
          </g>
          <text x="80" y="131" text-anchor="middle" font-family="DM Sans,system-ui,sans-serif" font-size="11.5" font-weight="600" fill="#ffffff">Summit</text>
          </g>

          <g class="conv-node" data-cx="150" data-cy="260">
          <circle cx="150" cy="260" r="30" fill="rgba(255,255,255,0.12)" stroke="rgba(255,255,255,0.28)" stroke-width="1.5"/>
          <g transform="translate(150,260) scale(1.8) translate(-12,-12)" fill="none" stroke="#ffffff" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
            <path d="M21 10c0 7-9 13-9 13S3 17 3 10a9 9 0 1 1 18 0z"/>
            <circle cx="12" cy="10" r="3"/>
          </g>
          <text x="150" y="303" text-anchor="middle" font-family="DM Sans,system-ui,sans-serif" font-size="11.5" font-weight="600" fill="#ffffff">Travel Time</text>
          </g>

          <g class="conv-node" data-cx="80" data-cy="432">
          <circle cx="80" cy="432" r="30" fill="rgba(255,255,255,0.12)" stroke="rgba(255,255,255,0.28)" stroke-width="1.5"/>
          <g transform="translate(80,432) scale(1.8) translate(-12,-12)" fill="none" stroke="#ffffff" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="12" cy="4" r="1.5" fill="#ffffff" stroke="none"/>
            <line x1="12" y1="5.5" x2="11" y2="13"/>
            <rect x="8" y="5.5" width="3.5" height="5.5" rx="1"/>
            <line x1="16" y1="7" x2="18" y2="22"/>
            <line x1="11" y1="9" x2="16" y2="8"/>
            <line x1="11" y1="13" x2="8" y2="22"/>
            <line x1="11" y1="13" x2="14" y2="22"/>
          </g>
          <text x="80" y="475" text-anchor="middle" font-family="DM Sans,system-ui,sans-serif" font-size="11.5" font-weight="600" fill="#ffffff">Hike Time</text>
          </g>

          <g class="conv-node" data-cx="720" data-cy="88">
          <circle cx="720" cy="88" r="30" fill="rgba(255,255,255,0.12)" stroke="rgba(255,255,255,0.28)" stroke-width="1.5"/>
          <g transform="translate(720,88) scale(1.8) translate(-12,-12)" fill="none" stroke="#ffffff" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
            <polygon points="1,6 1,22 8,18 16,22 23,18 23,2 16,6 8,2"/>
            <line x1="8" y1="2" x2="8" y2="18"/>
            <line x1="16" y1="6" x2="16" y2="22"/>
          </g>
          <text x="720" y="131" text-anchor="middle" font-family="DM Sans,system-ui,sans-serif" font-size="11.5" font-weight="600" fill="#ffffff">Trail Info</text>
          </g>

          <g class="conv-node" data-cx="650" data-cy="260">
          <circle cx="650" cy="260" r="30" fill="rgba(255,255,255,0.12)" stroke="rgba(255,255,255,0.28)" stroke-width="1.5"/>
          <g transform="translate(650,260) scale(1.8) translate(-12,-12)" fill="#ffffff" stroke="none">
            <rect x="2" y="16" width="3.5" height="5" rx="0.5"/>
            <rect x="8" y="12" width="3.5" height="9" rx="0.5"/>
            <rect x="14" y="7" width="3.5" height="14" rx="0.5"/>
            <rect x="20" y="2" width="3.5" height="19" rx="0.5"/>
          </g>
          <text x="650" y="303" text-anchor="middle" font-family="DM Sans,system-ui,sans-serif" font-size="11.5" font-weight="600" fill="#ffffff">Cell Coverage</text>
          </g>

          <g class="conv-node" data-cx="720" data-cy="432">
          <circle cx="720" cy="432" r="30" fill="rgba(255,255,255,0.12)" stroke="rgba(255,255,255,0.28)" stroke-width="1.5"/>
          <g transform="translate(720,432) scale(1.8) translate(-12,-12)" fill="none" stroke="#ffffff" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
            <rect x="7" y="8" width="8" height="13" rx="2"/>
            <line x1="12" y1="8" x2="12" y2="3"/>
            <rect x="9" y="10" width="4" height="3" rx="0.5"/>
            <circle cx="11" cy="17" r="1.5" fill="#ffffff" stroke="none"/>
          </g>
          <text x="720" y="475" text-anchor="middle" font-family="DM Sans,system-ui,sans-serif" font-size="11.5" font-weight="600" fill="#ffffff">Activation time</text>
          </g>
        </svg>
    </div>
    <p class="tagline hero-reveal" style="opacity:0;">Trail research from activators who've already been there — get to the summit faster, with less research effort.</p>
    <?php if ($ready_count > 0): ?>
    <div class="hero-reveal hero-stat-row" style="opacity:0;">
        <svg class="scroll-cue-arrow" width="16" height="16" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <polyline points="5 8 10 13 15 8"/>
        </svg>
        <span class="hero-stat-pill">
            <span class="hero-stat-dot"></span>
            <span class="hero-stat-num" data-target="<?= $ready_count ?>"><?= number_format($ready_count) ?></span> summits with community trail data
        </span>
        <svg class="scroll-cue-arrow" width="16" height="16" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <polyline points="5 8 10 13 15 8"/>
        </svg>
    </div>
    <span class="scroll-cue-label" aria-hidden="true">Scroll</span>
    <?php else: ?>
    <svg class="scroll-cue-arrow" width="20" height="20" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <polyline points="5 8 10 13 15 8"/>
    </svg>
    <span class="scroll-cue-label" aria-hidden="true">Scroll</span>
    <?php endif; ?>
</div>

<!-- Feature highlights + login card — both park together at the top of the viewport once
     scrolled into place, as one combined sticky unit, so the SSO button never gets covered
     by the tiles as you keep scrolling -->
<div class="features-panel">
<div class="features">
    <div class="feature" style="opacity:0; transform:translateX(-140px);">
        <span class="feature-icon"><svg width="32" height="32" viewBox="0 0 32 32" fill="none" stroke="#1E3A5F" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="16" cy="16" r="11"/><path d="M8 20 Q12 8 16 14 Q20 20 24 10"/><circle cx="8" cy="20" r="2" fill="#1E3A5F"/><circle cx="24" cy="10" r="2" fill="#1E3A5F"/></svg></span>
        <h3>Community Trail Data</h3>
        <p>Routes, starting points, and real-world hiking times contributed by hams who've already activated these summits — preloaded for thousands of peaks. No research required.</p>
    </div>
    <div class="feature" style="opacity:0; transform:translateY(90px);">
        <span class="feature-icon"><svg width="32" height="32" viewBox="0 0 32 32" fill="none" stroke="#1E3A5F" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="16" cy="17" r="12"/><polyline points="16,10 16,17 21,17"/><path d="M16,5 L16,3"/><path d="M14,3 L18,3"/></svg></span>
        <h3>Total Activation Time Estimates</h3>
        <p>Travel time + hiking time + radio time = one number. Know exactly what a summit requires — even one you've never visited — before you commit to the day.</p>
    </div>
    <div class="feature" style="opacity:0; transform:translateX(140px);">
        <span class="feature-icon"><svg width="32" height="32" viewBox="0 0 32 32" fill="none" stroke="#1E3A5F" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="16" cy="13" r="5"/><path d="M16 18 C10 18 5 24 5 28 L27 28 C27 24 22 18 16 18"/><path d="M22 8 C24 6 28 8 26 12"/><path d="M10 8 C8 6 4 8 6 12"/></svg></span>
        <h3>Plan From Anywhere</h3>
        <p>Traveling somewhere new? Search summits near any location — a destination city, a vacation spot — and instantly see what the community knows about each hike.</p>
    </div>
</div>

<div class="login-wrap">
    <div class="login-card">
        <?php if ($error): ?>
            <div class="error-msg"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <!-- SOTA SSO -->
        <?php if ($sota_oauth_enabled): ?>
            <a href="oauth_callback.php?action=login" class="sota-btn sota-btn-main login-card-item" style="opacity:0; transform:translateY(24px);">
                ⛰️ Continue with SOTA Login (SSO)
            </a>
        <?php else: ?>
            <button class="sota-btn sota-btn-disabled login-card-item" style="opacity:0; transform:translateY(24px);" disabled>
                ⛰️ SOTA SSO — Coming Soon
            </button>
            <p class="coming-soon-note">OAuth client registration pending with SOTA team</p>
        <?php endif; ?>

        <!-- No-registration callout -->
        <div class="login-card-item" style="opacity:0; transform:translateY(24px); background:#f0fdf4; border:1px solid #86efac; border-radius:8px; padding:0.85rem 1rem; margin-top:1.25rem; display:flex; gap:0.6rem; align-items:flex-start;">
            <span style="font-size:1.1rem; line-height:1.3; flex-shrink:0; color:#16a34a;">✓</span>
            <div>
                <strong style="font-size:0.88rem; color:#166534; display:block; margin-bottom:0.15rem;">No new account to create</strong>
                <p style="font-size:0.8rem; color:#166534; line-height:1.45; margin:0;">Use your official SOTA account — the same login you use on sotadata.org.uk. Already registered? You're all set — click above to sign in with your existing SOTA credentials.</p>
            </div>
        </div>
    </div>
</div>

<!-- How it works steps — part of the same combined sticky unit as the tiles + login card
     above, so all three sections park and settle together with nothing hidden behind another -->
<div class="how-strip">
    <div class="how-strip-inner">
        <div class="how-step" style="opacity:0; transform:translateY(20px);">
            <div class="step-num">1</div>
            <div>
                <strong>Sign in</strong>
                <p>Log in with your SOTA callsign. Your dashboards are private to you and your invited partners.</p>
            </div>
        </div>
        <div class="how-step" style="opacity:0; transform:translateY(20px);">
            <div class="step-num">2</div>
            <div>
                <strong>Search near any location</strong>
                <p>Enter your home, a city you're visiting, or anywhere you'll be. SOTA Planner finds nearby summits and pulls in community trail data for each one.</p>
            </div>
        </div>
        <div class="how-step" style="opacity:0; transform:translateY(20px);">
            <div class="step-num">3</div>
            <div>
                <strong>Plan and activate</strong>
                <p>Compare total time estimates, schedule your activation, and go chase those points.</p>
            </div>
        </div>
    </div>
</div>
</div>

<footer>
    <a href="changelog.php">v<?= APP_VERSION ?></a> &nbsp;·&nbsp; sotaplanner.com
</footer>

<!-- Developer access — bottom left corner -->
<div style="position:fixed; bottom:1rem; left:1rem; z-index:999;">
    <div id="dev-access" style="display:<?= $error ? 'block' : 'none' ?>; margin-bottom:0.5rem; padding:0.85rem 1rem; background:#fff; border:1px solid #e0e0e0; border-radius:10px; box-shadow:0 4px 16px rgba(0,0,0,0.1); width:220px;">
        <?php if ($error): ?>
            <div style="font-size:0.75rem;color:#dc2626;margin-bottom:0.6rem;font-weight:600;"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <form method="POST" style="display:flex; gap:0.4rem; align-items:center;">
            <input type="text" name="callsign"
                   value="<?= htmlspecialchars($_POST['callsign'] ?? '') ?>"
                   autocomplete="off" autocapitalize="characters"
                   placeholder="Callsign"
                   style="flex:1;padding:0.4rem 0.6rem;border:1px solid #ddd;border-radius:6px;font-family:inherit;font-size:0.82rem;color:#333;">
            <button type="submit" name="dev_login"
                    style="padding:0.4rem 0.75rem;border:none;border-radius:6px;background:#555;color:#fff;font-family:inherit;font-size:0.8rem;font-weight:600;cursor:pointer;">
                Go
            </button>
        </form>
    </div>
    <button type="button" id="dev-toggle"
            style="background:none;border:none;cursor:pointer;font-size:0.68rem;color:#ccc;font-family:inherit;padding:0;"
            onmouseover="this.style.color='#999'" onmouseout="this.style.color='#ccc'">
        Developer access
    </button>
</div>

<script>
document.getElementById('dev-toggle').addEventListener('click', function() {
    var d = document.getElementById('dev-access');
    d.style.display = d.style.display === 'none' ? 'block' : 'none';
});
</script>

<!-- Bubble-to-logo convergence, ported from about.php's "converge-section" scrub. Pure
     vanilla scroll-position math (no motion.dev dependency), so it works even if the CDN
     import below fails. Runs fast (finishes by ~35% of a viewport height of scrolling) so
     the bubbles visibly crash into the hub before the feature cards below have revealed much,
     giving the two effects a sequenced feel even though the card scrub (in the module script)
     starts progressing from scroll position 0 same as this does. -->
<script>
(function () {
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

    var section = document.getElementById('hero-converge');
    var nodes = section ? section.querySelectorAll('.conv-node') : [];
    var arrows = section ? section.querySelector('.conv-arrows') : null;
    var hubGroup = section ? section.querySelector('.hub-group') : null;
    var hubRing = section ? section.querySelector('.hub-ring') : null;
    var scrollCues = document.querySelectorAll('.scroll-cue-arrow, .scroll-cue-label');
    if (!section || !nodes.length || !hubGroup) return;

    var hub = { x: 400, y: 260 };
    var ticking = false;

    function smoothstep(p) { return p * p * (3 - 2 * p); }

    function applyScrub() {
        var scrollY = window.scrollY || window.pageYOffset;
        var vh = window.innerHeight;
        var triggerDistance = vh * 0.35;
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

        // Only touch the scroll cues once the user has actually scrolled — leave them alone at
        // scrollY 0 so their own CSS delayed fade-in (see .scroll-cue-in keyframe) gets to play,
        // instead of this script's initial call stomping them to opacity:1 immediately.
        if (scrollCues.length && scrollY > 0) {
            var cueP = Math.min(1, scrollY / (vh * 0.08));
            var cueOpacity = Math.max(0, 1 - cueP);
            scrollCues.forEach(function (el) { el.style.opacity = cueOpacity; });
        }

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

<!-- Sleek entrance / scroll animations, powered by motion.dev's vanilla-JS library -->
<script type="module">
(function () {
    // Safari (especially with its collapsing toolbar) can resolve CSS `100vh` to a
    // different pixel value than JS `window.innerHeight` at any given moment. Size the hero
    // from the same JS-measured value instead of trusting CSS vh, so the scroll-scrub math
    // (which reads window.innerHeight directly) always lines up with what's actually on screen.
    function setViewportHeights() {
        var hero = document.querySelector('.hero');
        if (hero) hero.style.minHeight = window.innerHeight + 'px';
    }
    setViewportHeights();
    window.addEventListener('resize', setViewportHeights);

    var revealSelector = '.hero-illustration, .hero-reveal, .feature, .how-step, .login-card-item';

    function showAllInstantly() {
        document.querySelectorAll(revealSelector).forEach(function (el) {
            el.style.opacity = '1';
            el.style.transform = 'none';
        });
    }

    // Respect reduced-motion preference — just show everything, no movement
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        showAllInstantly();
        return;
    }

    function withTimeout(promise, ms) {
        return Promise.race([
            promise,
            new Promise(function (_, reject) {
                setTimeout(function () { reject(new Error('motion load timeout')); }, ms);
            })
        ]);
    }

    withTimeout(import('https://cdn.jsdelivr.net/npm/motion@11/+esm'), 2000).then(function (motion) {
        var animate = motion.animate;
        var stagger = motion.stagger;
        var inView = motion.inView;
        var ease = [0.16, 1, 0.3, 1];
        var pop = [0.34, 1.56, 0.64, 1]; // bouncy "overshoot" ease for a dramatic entrance

        // 1. Hero illustration: big dramatic drop-and-pop — falls, scales up, and fades in
        var logoLanded = false;
        var logoAnim = animate('.hero-illustration', { opacity: [0, 1], scale: [0.55, 1], y: [-70, 0] },
            { duration: 0.75, easing: pop });

        // 2. Once the illustration lands, tagline + stat pill fade/rise in with a stagger
        logoAnim.finished.then(function () {
            logoLanded = true; // hand off logo opacity control to the scroll-scrub below

            animate('.hero-reveal', { opacity: [0, 1], transform: ['translateY(16px)', 'translateY(0)'] },
                { duration: 0.7, delay: stagger(0.12), easing: ease });

            // Stat pill: count up to the real number, plus a soft "live" pulse on the dot
            var statEl = document.querySelector('.hero-stat-num');
            if (statEl) {
                var target = parseInt(statEl.dataset.target, 10) || 0;
                statEl.textContent = '0';
                animate(0, target, {
                    duration: 1.3,
                    delay: 0.4,
                    easing: ease,
                    onUpdate: function (v) { statEl.textContent = Math.round(v).toLocaleString(); }
                });
            }
            animate('.hero-stat-dot', { opacity: [1, 0.5, 1], scale: [1, 1.2, 1] },
                { duration: 1.8, repeat: Infinity, easing: 'ease-in-out' });
        });

        // 3. Feature tiles + SSO login card: all scroll-linked, scrubbed directly to
        //    scroll position (advances as you scroll down, reverses as you scroll back
        //    up) so everything arrives together as the tiles panel scrolls up and parks
        //    at the top of the viewport — and the hero (background gradient included, not
        //    just its logo/text) fades OUT in the same motion, once the initial pop-in has
        //    landed. Fading the hero's own background — rather than relying on the tiles
        //    panel to physically occlude it — is what guarantees no gradient ever bleeds
        //    through underneath, in any browser (this is the fix for the Safari gap).
        //    Hand-rolled against raw scroll position (rather than a scroll-timeline helper)
        //    so it behaves identically in every browser, including Safari.
        var featuresPanel = document.querySelector('.features-panel');
        var featureTiles = document.querySelectorAll('.feature');
        var loginItems = document.querySelectorAll('.login-card-item');
        var heroEl = document.querySelector('.hero');
        if (featuresPanel && featureTiles.length === 3) {
            var tileConfig = [];
            var ticking = false;

            function smoothstep(p) { return p * p * (3 - 2 * p); }

            // On narrow screens the tiles stack in a single column, so sliding tiles 1 and 3
            // in from way off to the sides just pushes them (and the page) off-screen. Below
            // the mobile breakpoint, all three simply rise up together instead.
            function buildTileConfig() {
                var cfg = window.innerWidth < 600 ? [
                    { el: featureTiles[0], axis: 'Y', from: 60 },
                    { el: featureTiles[1], axis: 'Y', from: 60 },
                    { el: featureTiles[2], axis: 'Y', from: 60 }
                ] : [
                    { el: featureTiles[0], axis: 'X', from: -140 },
                    { el: featureTiles[1], axis: 'Y', from: 90 },
                    { el: featureTiles[2], axis: 'X', from: 140 }
                ];
                loginItems.forEach(function (el) {
                    cfg.push({ el: el, axis: 'Y', from: 24 });
                });
                return cfg;
            }

            function applyScrub() {
                var rect = featuresPanel.getBoundingClientRect();
                var vh = window.innerHeight;
                var startY = vh;        // progress 0: panel top at bottom of viewport
                var endY = vh * 0.2;    // progress 1: panel top near 20% down the viewport
                var raw = (startY - rect.top) / (startY - endY);
                var p = smoothstep(Math.min(1, Math.max(0, raw)));
                tileConfig.forEach(function (t) {
                    t.el.style.opacity = p;
                    t.el.style.transform = 'translate' + t.axis + '(' + (t.from * (1 - p)) + 'px)';
                });
                if (logoLanded) {
                    heroEl.style.opacity = 1 - p;
                }
                ticking = false;
            }

            function onScroll() {
                if (!ticking) {
                    requestAnimationFrame(applyScrub);
                    ticking = true;
                }
            }

            function onResize() {
                setViewportHeights();
                tileConfig = buildTileConfig();
                applyScrub();
            }

            tileConfig = buildTileConfig();
            window.addEventListener('scroll', onScroll, { passive: true });
            window.addEventListener('resize', onResize);
            applyScrub();
        }

        var stopSteps = inView('.how-strip', function () {
            animate('.how-step', { opacity: [0, 1], transform: ['translateY(20px)', 'translateY(0)'] },
                { duration: 0.5, delay: stagger(0.1), easing: ease });
            stopSteps();
        }, { amount: 0.3 });
    }).catch(function () {
        // CDN blocked/slow — fail safe to a fully visible, static page
        showAllInstantly();
    });
})();
</script>

</body>
</html>
