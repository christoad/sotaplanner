<?php
require_once 'config.php';
session_start();

// Already logged in — go to group selection
if (isset($_SESSION['sota_callsign'])) {
    header('Location: planning_groups.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['dev_login'])) {
        $callsign = strtoupper(trim($_POST['callsign'] ?? ''));
        $password  = $_POST['password'] ?? '';

        if ($callsign === 'KI6CR' && $password === 'sota') {
            $_SESSION['sota_callsign']   = 'KI6CR';
            $_SESSION['sota_login_type'] = 'dev';
            header('Location: planning_groups.php');
            exit;
        } else {
            $error = 'Invalid callsign or password.';
        }
    }
}

$sota_oauth_enabled = defined('SOTA_CLIENT_ID') && SOTA_CLIENT_ID !== '';
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
            background: linear-gradient(150deg, #1E3A5F 0%, #2d5a8e 60%, #4A90A4 100%);
            color: white;
            padding: 3rem 1.5rem 2.5rem;
            text-align: center;
        }

        .hero-logo-wrap {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: white;
            border-radius: 50%;
            width: 160px;
            height: 160px;
            margin-bottom: 1.5rem;
            box-shadow: 0 6px 24px rgba(0,0,0,0.2);
        }

        .hero img {
            height: 124px;
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
            background: linear-gradient(135deg, #1E3A5F 0%, #2d5a8e 100%);
            color: white;
        }

        .sota-btn-main:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 18px rgba(30,58,95,0.35);
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
            border-color: #4A90A4;
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
        <img src="logo.png" alt="SOTA Planner">
    </div>
    <h1>SOTA Planner</h1>
    <p class="tagline">Doorstep-to-doorstep planning for busy activators and collaborative teams — understand the full time commitment to getting that summit in your logbook.</p>
</div>

<!-- Feature highlights -->
<div class="features">
    <div class="feature">
        <span class="feature-icon">⏱️</span>
        <h3>Total Day Estimate</h3>
        <p>Drive time + hiking time + radio time = one number. Compare summits and pick what fits your day.</p>
    </div>
    <div class="feature">
        <span class="feature-icon">🗺️</span>
        <h3>GPX Track Analysis</h3>
        <p>Upload a recorded track to get real hiking time, activation time, rest breaks, elevation, and speed.</p>
    </div>
    <div class="feature">
        <span class="feature-icon">📨</span>
        <h3>Shareable Invitations</h3>
        <p>Generate a public invite page for guests — timeline, map, driving directions, no login required.</p>
    </div>
    <div class="feature">
        <span class="feature-icon">👥</span>
        <h3>Group Planning</h3>
        <p>Share a planning group with your activation partners. Everyone sees the same summit wishlist and research.</p>
    </div>
</div>

<!-- How it works steps -->
<div class="how-strip">
    <div class="how-strip-inner">
        <div class="how-step">
            <div class="step-num">1</div>
            <div>
                <strong>Sign in</strong>
                <p>Log in with your SOTA callsign. Your planning groups stay private to you and your invited partners.</p>
            </div>
        </div>
        <div class="how-step">
            <div class="step-num">2</div>
            <div>
                <strong>Create or join a group</strong>
                <p>Set up a planning group for your crew, or join one you've been invited to.</p>
            </div>
        </div>
        <div class="how-step">
            <div class="step-num">3</div>
            <div>
                <strong>Nominate &amp; research summits</strong>
                <p>Add summits by SOTA reference, upload GPX tracks, and build up your wishlist.</p>
            </div>
        </div>
        <div class="how-step">
            <div class="step-num">4</div>
            <div>
                <strong>Plan and activate</strong>
                <p>Schedule an activation, share the invite link, and go chase those points.</p>
            </div>
        </div>
    </div>
</div>

<!-- Login card -->
<div class="login-wrap">
    <div class="login-card">
        <h2>Sign in to get started</h2>
        <p class="sub">Use your SOTA account to keep your planning groups private.</p>

        <?php if ($error): ?>
            <div class="error-msg"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <!-- SOTA SSO -->
        <?php if ($sota_oauth_enabled): ?>
            <a href="oauth_callback.php?action=login" class="sota-btn sota-btn-main">
                ⛰️ Continue with SOTA Login
            </a>
        <?php else: ?>
            <button class="sota-btn sota-btn-disabled" disabled>
                ⛰️ SOTA SSO — Coming Soon
            </button>
            <p class="coming-soon-note">OAuth client registration pending with SOTA team</p>
        <?php endif; ?>

        <div class="divider"><span>Dev mode <span class="dev-badge">Testing</span></span></div>

        <form method="POST">
            <div class="form-group">
                <label>Callsign</label>
                <input type="text" name="callsign"
                       value="<?= htmlspecialchars($_POST['callsign'] ?? '') ?>"
                       autocomplete="username" autocapitalize="characters"
                       placeholder="KI6CR" required>
            </div>
            <div class="form-group">
                <label>Password</label>
                <input type="password" name="password"
                       autocomplete="current-password"
                       placeholder="••••••••" required>
            </div>
            <button type="submit" name="dev_login" class="submit-btn">Sign In</button>
        </form>
    </div>
</div>

<footer>
    <a href="changelog.php">v<?= APP_VERSION ?></a> &nbsp;·&nbsp; sotaplanner.com
</footer>

</body>
</html>
