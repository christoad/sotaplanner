<?php
require_once 'config.php';
session_start();

// Already logged in, go to dashboard (or group picker if no group set)
if (isset($_SESSION['sota_callsign'])) {
    if (!empty($_SESSION['pending_multi_import']) || !empty($_SESSION['pending_summit_import'])) { header('Location: onboarding.php'); exit; }
    if (!empty($_SESSION['pending_share_return'])) {
        $ret = $_SESSION['pending_share_return']; unset($_SESSION['pending_share_return']);
        header('Location: summit_share.php?s=' . urlencode($ret)); exit;
    }
    if (!empty($_SESSION['pending_multi_return'])) {
        $mret = (int)$_SESSION['pending_multi_return']; unset($_SESSION['pending_multi_return']);
        header('Location: multi_activate.php?id=' . $mret); exit;
    }
    header('Location: ' . (isset($_SESSION['current_planning_group_id']) ? 'index.php' : 'planning_groups.php'));
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Passwordless callsign sign-in is for dev/staging only. On production, SOTA SSO is the only way in.
    if (isset($_POST['dev_login']) && !isProductionSite()) {
        $callsign = strtoupper(trim($_POST['callsign'] ?? ''));

        if ($callsign !== '' && preg_match('/^[A-Z0-9]{3,10}$/', $callsign)) {
            $_SESSION['sota_callsign']   = $callsign;
            $_SESSION['sota_login_type'] = 'early_access';

            // Came from "Add this Multi-Summit to your own dashboard" on a shared route
            if (!empty($_SESSION['pending_multi_import']) || !empty($_SESSION['pending_summit_import'])) {
                header('Location: onboarding.php');
                exit;
            }

            // Came from "Login" on a shared summit page: go back to it
            if (!empty($_SESSION['pending_share_return'])) {
                $ret = $_SESSION['pending_share_return'];
                unset($_SESSION['pending_share_return']);
                header('Location: summit_share.php?s=' . urlencode($ret));
                exit;
            }

            // Came from "Login" on a shared route page: go back to it
            if (!empty($_SESSION['pending_multi_return'])) {
                $mret = (int)$_SESSION['pending_multi_return'];
                unset($_SESSION['pending_multi_return']);
                header('Location: multi_activate.php?id=' . $mret);
                exit;
            }

            // Determine redirect based on group membership
            try {
                $db = getDbConnection();

                // Demo mode (God Mode switch): run KI6CR through the new-user wizard
                if (isDemoOnboarding($db, $callsign)) {
                    unset($_SESSION['demo_onboarding_active']);
                    header('Location: onboarding.php');
                    exit;
                }

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
                    // New user, no groups yet
                    header('Location: onboarding.php');
                } else {
                    // Returning user, pick best group: saved default cookie > owned/first group
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
                // DB error, fall back to group picker
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
    <title>SOTA Planner | Plan Your Activations</title>
    <link href="https://fonts.googleapis.com/css2?family=Overpass:wght@300;600;800&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Overpass', sans-serif;
            background: #f5f5f0;
            color: #1E3A5F;
            min-height: 100vh;
        }

        /* Two-column split: hero + icon diagram left, sign-in right. Stacks on mobile. */
        .split {
            display: grid;
            grid-template-columns: 1.15fr 1fr;
            min-height: 100vh;
        }

        /* ── Hero (left) ── */
        .hero {
            background: linear-gradient(150deg, #1E3A5F 0%, #4A3A2A 60%, #9B6328 100%);
            color: white;
            padding: 3rem 2.5rem;
            text-align: center;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }

        .hero-illustration-wrap {
            width: 100%;
            max-width: 640px;
            margin: 0 auto 1.5rem;
        }

        .hero-illustration-wrap svg {
            display: block;
            width: 100%;
            height: auto;
        }

        .hero .tagline {
            font-size: 1.1rem;
            font-weight: 300;
            opacity: 0.85;
            max-width: 460px;
            margin: 0 auto;
            line-height: 1.5;
        }

        .hero-stat-pill {
            display: inline-flex;
            align-items: center;
            gap: 0.6rem;
            background: rgba(255,255,255,0.12);
            border: 1px solid rgba(255,255,255,0.22);
            border-radius: 100px;
            padding: 0.55rem 1.25rem 0.55rem 0.85rem;
            font-size: 0.95rem;
            font-weight: 600;
            color: rgba(255,255,255,0.92);
            margin-top: 1.25rem;
            letter-spacing: 0.01em;
        }

        .hero-stat-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: #6ee7b7;
            flex-shrink: 0;
        }

        .hero-stat-num { font-weight: 800; color: #fff; }

        /* ── Sign-in (right) ── */
        .signin {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 3rem 2rem 1.5rem;
        }

        .login-card {
            width: 100%;
            max-width: 380px;
            background: white;
            border-radius: 16px;
            padding: 2.25rem 2rem;
            box-shadow: 0 4px 24px rgba(0,0,0,0.1);
            text-align: center;
        }

        .login-card h2 {
            font-size: 1.5rem;
            font-weight: 800;
            margin-bottom: 0.4rem;
        }

        .login-card .sub {
            font-size: 0.88rem;
            color: #666;
            line-height: 1.5;
            margin-bottom: 1.5rem;
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

        .error-msg {
            background: #fff3f3;
            border: 1px solid #fca5a5;
            color: #dc2626;
            padding: 0.7rem 0.9rem;
            border-radius: 8px;
            font-size: 0.85rem;
            font-weight: 600;
            margin-bottom: 1rem;
            text-align: left;
        }

        footer {
            text-align: center;
            padding: 1.5rem 1rem 0;
            color: #bbb;
            font-size: 0.75rem;
        }

        footer a { color: #bbb; text-decoration: none; }

        /* Gentle fade-up on load (CSS only, so nothing depends on a CDN) */
        @keyframes rise { from { opacity: 0; transform: translateY(14px); } to { opacity: 1; transform: none; } }
        .hero-illustration-wrap { animation: rise 0.7s cubic-bezier(0.16, 1, 0.3, 1) both; }
        .hero .tagline          { animation: rise 0.7s 0.15s cubic-bezier(0.16, 1, 0.3, 1) both; }
        .hero-stat-pill         { animation: rise 0.7s 0.25s cubic-bezier(0.16, 1, 0.3, 1) both; }
        .login-card             { animation: rise 0.7s 0.2s cubic-bezier(0.16, 1, 0.3, 1) both; }
        @media (prefers-reduced-motion: reduce) {
            .hero-illustration-wrap, .hero .tagline, .hero-stat-pill, .login-card { animation: none; }
        }

        @media (max-width: 860px) {
            .split { grid-template-columns: 1fr; min-height: 0; }
            .hero { padding: 2rem 1.25rem; }
            .hero-illustration-wrap { max-width: 460px; }
            .signin { padding: 2rem 1.25rem 1.5rem; }
            .login-card { padding: 1.75rem 1.25rem; }
            .hero-stat-pill { font-size: 0.85rem; padding: 0.5rem 1rem 0.5rem 0.75rem; text-align: left; }
        }
    </style>
</head>
<body>

<div class="split">

<!-- Hero (left on desktop, top on mobile) -->
<div class="hero">
    <div class="hero-illustration-wrap">
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

          <!-- Arrows stop well short of the (large) logo, a deliberate hover gap, not a
               tangent, same distance the nodes converge to on scroll. Coordinates computed
               against the hub's 340x340 bounding box plus a 32-unit clearance. -->
          <g>
          <line x1="111.7" y1="105"  x2="217.7" y2="162"   stroke="rgba(255,255,255,0.16)" stroke-width="1.5" marker-end="url(#arr)"/>
          <line x1="186"   y1="260"  x2="216"   y2="260"   stroke="rgba(255,255,255,0.16)" stroke-width="1.5" marker-end="url(#arr)"/>
          <line x1="111.7" y1="415"  x2="217.7" y2="358"   stroke="rgba(255,255,255,0.16)" stroke-width="1.5" marker-end="url(#arr)"/>
          <line x1="688.3" y1="105"  x2="582.3" y2="162"   stroke="rgba(255,255,255,0.16)" stroke-width="1.5" marker-end="url(#arr)"/>
          <line x1="614"   y1="260"  x2="584"   y2="260"   stroke="rgba(255,255,255,0.16)" stroke-width="1.5" marker-end="url(#arr)"/>
          <line x1="688.3" y1="415"  x2="582.3" y2="358"   stroke="rgba(255,255,255,0.16)" stroke-width="1.5" marker-end="url(#arr)"/>
          </g>

          <!-- Large and in charge, same proportions as the production logo, just scaled up
               to be the clear focal point of the diagram. -->
          <g>
          <image href="sota-planner-logo-font.svg" x="230" y="90" width="340" height="340" filter="url(#toWhite)"/>
          </g>

          <g>
          <circle cx="80" cy="88" r="30" fill="rgba(255,255,255,0.12)" stroke="rgba(255,255,255,0.28)" stroke-width="1.5"/>
          <g transform="translate(80,88) scale(1.8) translate(-12,-12)" fill="none" stroke="#ffffff" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
            <path d="M3 20L12 4L21 20H3Z"/>
            <path d="M9 20L12 13L15 17"/>
          </g>
          <text x="80" y="131" text-anchor="middle" font-family="DM Sans,system-ui,sans-serif" font-size="11.5" font-weight="600" fill="#ffffff">Summit</text>
          </g>

          <g>
          <circle cx="150" cy="260" r="30" fill="rgba(255,255,255,0.12)" stroke="rgba(255,255,255,0.28)" stroke-width="1.5"/>
          <g transform="translate(150,260) scale(1.8) translate(-12,-12)" fill="none" stroke="#ffffff" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
            <path d="M21 10c0 7-9 13-9 13S3 17 3 10a9 9 0 1 1 18 0z"/>
            <circle cx="12" cy="10" r="3"/>
          </g>
          <text x="150" y="303" text-anchor="middle" font-family="DM Sans,system-ui,sans-serif" font-size="11.5" font-weight="600" fill="#ffffff">Travel Time</text>
          </g>

          <g>
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

          <g>
          <circle cx="720" cy="88" r="30" fill="rgba(255,255,255,0.12)" stroke="rgba(255,255,255,0.28)" stroke-width="1.5"/>
          <g transform="translate(720,88) scale(1.8) translate(-12,-12)" fill="none" stroke="#ffffff" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
            <polygon points="1,6 1,22 8,18 16,22 23,18 23,2 16,6 8,2"/>
            <line x1="8" y1="2" x2="8" y2="18"/>
            <line x1="16" y1="6" x2="16" y2="22"/>
          </g>
          <text x="720" y="131" text-anchor="middle" font-family="DM Sans,system-ui,sans-serif" font-size="11.5" font-weight="600" fill="#ffffff">Trail Info</text>
          </g>

          <g>
          <circle cx="650" cy="260" r="30" fill="rgba(255,255,255,0.12)" stroke="rgba(255,255,255,0.28)" stroke-width="1.5"/>
          <g transform="translate(650,260) scale(1.8) translate(-12,-12)" fill="#ffffff" stroke="none">
            <rect x="2" y="16" width="3.5" height="5" rx="0.5"/>
            <rect x="8" y="12" width="3.5" height="9" rx="0.5"/>
            <rect x="14" y="7" width="3.5" height="14" rx="0.5"/>
            <rect x="20" y="2" width="3.5" height="19" rx="0.5"/>
          </g>
          <text x="650" y="303" text-anchor="middle" font-family="DM Sans,system-ui,sans-serif" font-size="11.5" font-weight="600" fill="#ffffff">Cell Coverage</text>
          </g>

          <g>
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
    <p class="tagline">Summit research from activators who have already been there. Get to the summit faster with all your research in one place.</p>
    <?php if ($ready_count > 0): ?>
    <span class="hero-stat-pill">
        <span class="hero-stat-dot"></span>
        <span><span class="hero-stat-num"><?= number_format($ready_count) ?></span> summits with community trail data</span>
    </span>
    <?php endif; ?>
</div>

<!-- Sign-in (right on desktop, below on mobile) -->
<div class="signin">
    <div class="login-card">
        <h2>Sign in</h2>
        <p class="sub">Use your SOTA account. No new account to create.</p>

        <?php if ($error): ?>
            <div class="error-msg"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if ($sota_oauth_enabled): ?>
            <a href="oauth_callback.php?action=login" class="sota-btn sota-btn-main">
                Continue with SOTA Login (SSO)
            </a>
        <?php else: ?>
            <button class="sota-btn sota-btn-disabled" disabled>
                SOTA SSO: Coming Soon
            </button>
            <p class="coming-soon-note">OAuth client registration pending with SOTA team</p>
        <?php endif; ?>
    </div>

    <footer>
        <a href="changelog.php">v<?= APP_VERSION ?></a> &nbsp;·&nbsp; sotaplanner.com
    </footer>
</div>

</div>

<?php if (!isProductionSite()): ?>
<!-- Developer access, bottom left corner (dev/staging only) -->
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
<?php endif; ?>

</body>
</html>
