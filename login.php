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
        }

        .hero-logo-wrap {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 1.25rem;
        }

        .hero img {
            height: 280px;
            filter: brightness(0) invert(1);
            drop-shadow: 0 2px 12px rgba(255,255,255,0.15);
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

        .login-card h2 {
            font-size: 1.2rem;
            font-weight: 800;
            color: #1E3A5F;
            margin-bottom: 0.25rem;
        }

        .login-card .sub {
            font-size: 0.82rem;
            color: #888;
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
            .hero h1 { font-size: 1.8rem; }
            .features { padding: 1.25rem 0.75rem 0; }
            .feature { padding: 1rem 0.75rem; }
        }
    </style>
</head>
<body>

<!-- Hero -->
<div class="hero">
    <div class="hero-logo-wrap">
        <img src="sota-planner-logo-font.svg" width="280" height="280" alt="SOTA Planner">
    </div>
    <p class="tagline">Thousands of hams have already hiked these trails and shared what they found. SOTA Planner puts that community knowledge to work — so you can plan any activation, anywhere, even if you've never been there before.</p>
    <?php if ($ready_count > 0): ?>
    <div>
        <span class="hero-stat-pill">
            <span class="hero-stat-dot"></span>
            <span class="hero-stat-num"><?= number_format($ready_count) ?></span> summits with community trail data
        </span>
    </div>
    <?php endif; ?>
</div>

<!-- Feature highlights -->
<div class="features">
    <div class="feature">
        <span class="feature-icon"><svg width="32" height="32" viewBox="0 0 32 32" fill="none" stroke="#1E3A5F" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="16" cy="16" r="11"/><path d="M8 20 Q12 8 16 14 Q20 20 24 10"/><circle cx="8" cy="20" r="2" fill="#1E3A5F"/><circle cx="24" cy="10" r="2" fill="#1E3A5F"/></svg></span>
        <h3>Community Trail Data</h3>
        <p>Routes, trailheads, and real-world hiking times contributed by hams who've already activated these summits — preloaded for thousands of peaks. No research required.</p>
    </div>
    <div class="feature">
        <span class="feature-icon"><svg width="32" height="32" viewBox="0 0 32 32" fill="none" stroke="#1E3A5F" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="16" cy="17" r="12"/><polyline points="16,10 16,17 21,17"/><path d="M16,5 L16,3"/><path d="M14,3 L18,3"/></svg></span>
        <h3>Total Day Estimate</h3>
        <p>Drive time + hiking time + radio time = one number. Know exactly what a summit requires — even one you've never visited — before you commit to the day.</p>
    </div>
    <div class="feature">
        <span class="feature-icon"><svg width="32" height="32" viewBox="0 0 32 32" fill="none" stroke="#1E3A5F" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="16" cy="13" r="5"/><path d="M16 18 C10 18 5 24 5 28 L27 28 C27 24 22 18 16 18"/><path d="M22 8 C24 6 28 8 26 12"/><path d="M10 8 C8 6 4 8 6 12"/></svg></span>
        <h3>Plan From Anywhere</h3>
        <p>Traveling somewhere new? Search summits near any location — a destination city, a vacation spot — and instantly see what the community knows about each hike.</p>
    </div>
    <div class="feature">
        <span class="feature-icon"><svg width="32" height="32" viewBox="0 0 32 32" fill="none" stroke="#1E3A5F" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="8" width="24" height="16" rx="2"/><polyline points="4,8 16,18 28,8"/></svg></span>
        <h3>Shareable Invitations</h3>
        <p>Generate a public invite page for guests — timeline, map, driving directions, and location sharing. No login required.</p>
    </div>
</div>

<!-- How it works steps -->
<div class="how-strip">
    <div class="how-strip-inner">
        <div class="how-step">
            <div class="step-num">1</div>
            <div>
                <strong>Sign in</strong>
                <p>Log in with your SOTA callsign. Your planning groups are private to you and your invited partners.</p>
            </div>
        </div>
        <div class="how-step">
            <div class="step-num">2</div>
            <div>
                <strong>Search near any location</strong>
                <p>Enter your home, a city you're visiting, or anywhere you'll be. SOTA Planner finds nearby summits and pulls in community trail data for each one.</p>
            </div>
        </div>
        <div class="how-step">
            <div class="step-num">3</div>
            <div>
                <strong>See what you're getting into</strong>
                <p>Community routes, trailheads, real hiking times, and drive estimates are preloaded — so you can compare summits and pick what fits your schedule.</p>
            </div>
        </div>
        <div class="how-step">
            <div class="step-num">4</div>
            <div>
                <strong>Plan and activate</strong>
                <p>Schedule the activation, share the invite link with your hiking partners, and go chase those points.</p>
            </div>
        </div>
    </div>
</div>

<!-- Login card -->
<div class="login-wrap">
    <div class="login-card">
        <h2 style="text-align:center;">Sign in to get started</h2>
        <p class="sub">Use your official SOTA account — the same login you use on sotadata.org.uk.</p>

        <?php if ($error): ?>
            <div class="error-msg"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <!-- No-registration callout -->
        <div style="background:#f0fdf4; border:1px solid #86efac; border-radius:8px; padding:0.85rem 1rem; margin-bottom:1.25rem; display:flex; gap:0.6rem; align-items:flex-start;">
            <span style="font-size:1.1rem; line-height:1.3; flex-shrink:0; color:#16a34a;">✓</span>
            <div>
                <strong style="font-size:0.88rem; color:#166534; display:block; margin-bottom:0.15rem;">No new account to create</strong>
                <p style="font-size:0.8rem; color:#166534; line-height:1.45; margin:0;">Already registered on the SOTA database? You're all set. Click the button below to sign in with your existing SOTA credentials.</p>
            </div>
        </div>

        <!-- SOTA SSO -->
        <?php if ($sota_oauth_enabled): ?>
            <a href="oauth_callback.php?action=login" class="sota-btn sota-btn-main">
                ⛰️ Continue with SOTA Login (SSO)
            </a>
        <?php else: ?>
            <button class="sota-btn sota-btn-disabled" disabled>
                ⛰️ SOTA SSO — Coming Soon
            </button>
            <p class="coming-soon-note">OAuth client registration pending with SOTA team</p>
        <?php endif; ?>

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

</body>
</html>
