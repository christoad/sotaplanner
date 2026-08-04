<?php
require_once 'config.php';
session_start();
requireLogin();

$db = getDbConnection();
$callsign = getCurrentCallsign();

$error = '';

// Check if user already has a group
$stmt = $db->prepare("
    SELECT pg.id, pg.name, pg.units
    FROM planning_groups pg
    LEFT JOIN planning_group_members pgm ON pg.id = pgm.planning_group_id
    WHERE pg.owner_callsign = ? OR pgm.callsign = ?
    ORDER BY pg.id ASC LIMIT 1
");
$stmt->execute([$callsign, $callsign]);
$existing_group = $stmt->fetch();

// If user has a group with an address already, send them to the dashboard
if ($existing_group) {
    $addr_stmt = $db->prepare("SELECT COUNT(*) FROM addresses WHERE planning_group_id = ?");
    $addr_stmt->execute([$existing_group['id']]);
    if ((int)$addr_stmt->fetchColumn() > 0) {
        setCurrentPlanningGroup($existing_group['id']);
        header('Location: index.php');
        exit;
    }
}

// Handle: create group (step 1)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_group'])) {
    $group_name = trim($_POST['group_name'] ?? '');
    $units = in_array($_POST['units'] ?? '', ['imperial', 'metric']) ? $_POST['units'] : detectUnitsFromCallsign($callsign);

    if ($group_name === '') {
        $error = 'Please give your dashboard a name.';
    } else {
        try {
            // Save units as the user's personal preference
            $db->prepare("
                INSERT INTO user_settings (user_callsign, units) VALUES (?, ?)
                ON DUPLICATE KEY UPDATE units = VALUES(units)
            ")->execute([$callsign, $units]);
            $_SESSION['user_units'] = $units;

            $stmt = $db->prepare("INSERT INTO planning_groups (name, units, owner_callsign) VALUES (?, ?, ?)");
            $stmt->execute([$group_name, $units, $callsign]);
            $new_group_id = $db->lastInsertId();

            $stmt = $db->prepare("INSERT IGNORE INTO planning_group_members (planning_group_id, callsign, role) VALUES (?, ?, 'owner')");
            $stmt->execute([$new_group_id, $callsign]);

            setCurrentPlanningGroup($new_group_id);
            unset($_SESSION['onboarding_crew_done']);
            header('Location: onboarding.php');
            exit;
        } catch (PDOException $e) {
            if (str_contains($e->getMessage(), 'Duplicate')) {
                $error = 'That dashboard name is already taken. Please choose a different name.';
            } else {
                $error = 'Could not create dashboard. Please try again.';
            }
        }
    }
}

// Handle: add crew callsigns (step 2) — submit or skip
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_crew'])) {
    $group = getCurrentPlanningGroup($db);
    $raw = trim($_POST['crew_callsigns'] ?? '');
    if ($raw !== '' && $group) {
        $ins = $db->prepare("INSERT IGNORE INTO planning_group_members (planning_group_id, callsign, role, invited_by) VALUES (?, ?, 'member', ?)");
        foreach (explode(',', $raw) as $cs) {
            $cs = strtoupper(preg_replace('/[^A-Z0-9]/', '', strtoupper(trim($cs))));
            if ($cs !== '' && $cs !== $callsign) {
                $ins->execute([$group['id'], $cs, $callsign]);
            }
        }
    }
    $_SESSION['onboarding_crew_done'] = true;
    header('Location: onboarding.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['skip_crew'])) {
    $_SESSION['onboarding_crew_done'] = true;
    header('Location: onboarding.php');
    exit;
}

// Handle: add address (step 3)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_address'])) {
    $group = getCurrentPlanningGroup($db);
    $label   = trim($_POST['label']   ?? '');
    $address = trim($_POST['address'] ?? '');

    if ($address === '') {
        $error = 'Please enter a starting location.';
    } elseif ($group) {
        try {
            $stmt = $db->prepare("INSERT INTO addresses (planning_group_id, label, address) VALUES (?, ?, ?)");
            $stmt->execute([$group['id'], $label, $address]);
            $new_addr_id = $db->lastInsertId();

            $setting_key = 'selected_address_group_' . $group['id'];
            $stmt = $db->prepare("INSERT INTO app_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
            $stmt->execute([$setting_key, $new_addr_id]);

            unset($_SESSION['onboarding_crew_done']);
            header('Location: index.php?tour=1');
            exit;
        } catch (PDOException $e) {
            $error = 'Could not save that location. Please try again.';
        }
    }
}

// Determine current step (1 = no group, 2 = crew, 3 = address)
$current_group = getCurrentPlanningGroup($db);
if (!$current_group) {
    $step = 1;
} elseif (empty($_SESSION['onboarding_crew_done'])) {
    $step = 2;
} else {
    $step = 3;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Get Started — SOTA Planner</title>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;1,9..40,400&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
    <style>
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
    --red:           oklch(52% 0.16 22);
    --red-bg:        oklch(96% 0.04 22);
    --surface:       #FFFFFF;
    --border:        #E5E2DA;
    --border-2:      #D4D0C8;
    --font-sans:     'DM Sans', system-ui, sans-serif;
    --font-mono:     'DM Mono', 'Courier New', monospace;
    --r-sm: 4px; --r-md: 8px; --r-lg: 12px; --r-xl: 16px;
    --shadow-sm: 0 1px 3px rgba(28,27,25,0.07), 0 1px 2px rgba(28,27,25,0.05);
    --shadow-md: 0 4px 12px rgba(28,27,25,0.08), 0 2px 4px rgba(28,27,25,0.05);
    --shadow-lg: 0 8px 24px rgba(28,27,25,0.10), 0 4px 8px rgba(28,27,25,0.06);
}

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html { font-size: 16px; -webkit-font-smoothing: antialiased; }
body { font-family: var(--font-sans); background: var(--bg); color: var(--ink); line-height: 1.5; min-height: 100vh; }

/* ── Top strip ── */
.top-strip {
    background: var(--surface);
    border-bottom: 1px solid var(--border);
    padding: 0.875rem 2rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.logo-link {
    display: flex; align-items: center; gap: 0.625rem;
    text-decoration: none; color: var(--ink);
    font-weight: 600; font-size: 0.95rem;
}
.logo-link:hover { text-decoration: none; color: var(--ink); }
.user-note {
    font-size: 0.8rem; color: var(--ink-3);
    font-family: var(--font-mono);
}

/* ── Step indicator ── */
.setup-header {
    text-align: center;
    padding: 2rem 1rem 0;
}
.setup-title {
    font-size: 0.8rem; font-weight: 700;
    text-transform: uppercase; letter-spacing: 0.1em;
    color: var(--ink-3);
}

.step-bar {
    display: flex;
    align-items: flex-start;
    justify-content: center;
    padding: 1.5rem 2rem 0;
    max-width: 560px;
    margin: 0 auto;
}
.step-item {
    display: flex;
    flex-direction: column;
    align-items: center;
    flex: 1;
    min-width: 0;
}
.step-circle {
    width: 48px; height: 48px;
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.1rem; font-weight: 700;
    font-family: var(--font-mono);
    border: 2px solid var(--border-2);
    background: var(--surface);
    color: var(--ink-4);
    position: relative; z-index: 1;
    flex-shrink: 0;
    transition: all 0.2s;
}
.step-circle.done {
    background: var(--green-bg);
    border-color: oklch(72% 0.12 155);
    color: var(--green);
    border-width: 2px;
}
@keyframes step-pulse {
    0%   { box-shadow: 0 0 0 0px oklch(60% 0.10 50 / 0.55); }
    60%  { box-shadow: 0 0 0 12px oklch(60% 0.10 50 / 0.12); }
    100% { box-shadow: 0 0 0 20px oklch(60% 0.10 50 / 0); }
}
.step-circle.active {
    background: var(--ink);
    border-color: var(--ink);
    color: #fff;
    animation: step-pulse 1.8s ease-out infinite;
}
.step-label {
    font-size: 0.75rem; font-weight: 600;
    text-align: center;
    margin-top: 0.5rem;
    color: var(--ink-4);
    line-height: 1.3;
    padding: 0 0.25rem;
}
.step-label.active { color: var(--ink); }
.step-label.done   { color: var(--green); }
.step-sublabel {
    font-size: 0.68rem; font-weight: 400;
    color: var(--ink-4); text-align: center;
    margin-top: 0.2rem; line-height: 1.2;
    padding: 0 0.25rem;
}
.step-sublabel.active { color: var(--ink-3); }
.step-connector-wrap {
    flex: 1;
    padding-top: 24px; /* vertically center with circle */
    min-width: 1rem;
}
.step-connector-line {
    height: 2px;
    background: var(--border-2);
    transition: background 0.3s;
}
.step-connector-line.done {
    background: oklch(72% 0.12 155);
}

/* ── Main layout ── */
.onboarding-wrap {
    max-width: 640px;
    margin: 2.5rem auto 4rem;
    padding: 0 1.25rem;
}

/* ── Hero card ── */
.hero-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--r-xl);
    padding: 3rem 2.5rem 2.5rem;
    box-shadow: var(--shadow-md);
    margin-bottom: 1rem;
}

.step-badge {
    display: inline-flex; align-items: center; gap: 0.35rem;
    font-size: 0.7rem; font-weight: 700;
    text-transform: uppercase; letter-spacing: 0.08em;
    color: var(--accent);
    background: var(--accent-bg);
    border: 1px solid var(--accent-border);
    border-radius: 100px; padding: 0.2rem 0.7rem;
    margin-bottom: 1.25rem;
}

.hero-icon {
    display: block;
    margin-bottom: 1.25rem;
    color: var(--accent);
}

.hero-title {
    font-size: 1.85rem;
    font-weight: 600;
    letter-spacing: -0.02em;
    color: var(--ink);
    line-height: 1.2;
    margin-bottom: 0.875rem;
}

.hero-body {
    font-size: 1.05rem;
    color: var(--ink-2);
    line-height: 1.65;
    margin-bottom: 0.75rem;
}

.hero-body strong {
    color: var(--ink);
    font-weight: 600;
}

.hero-note {
    font-size: 0.875rem;
    color: var(--ink-3);
    line-height: 1.55;
    padding: 0.875rem 1rem;
    background: var(--bg-2);
    border-radius: var(--r-md);
    border-left: 3px solid var(--border-2);
    margin-bottom: 2rem;
}

/* ── Form ── */
.form-section {
    border-top: 1px solid var(--border);
    padding-top: 1.75rem;
    margin-top: 1.75rem;
}
.form-section-title {
    font-size: 0.75rem; font-weight: 700;
    text-transform: uppercase; letter-spacing: 0.08em;
    color: var(--ink-3); margin-bottom: 1.25rem;
}

.form-group { margin-bottom: 1.25rem; }
.form-label {
    display: block; font-size: 0.82rem; font-weight: 600;
    color: var(--ink-2); margin-bottom: 0.4rem;
}
.form-input {
    width: 100%; padding: 0.7rem 0.875rem;
    border: 1.5px solid var(--border-2); border-radius: var(--r-md);
    font-family: var(--font-sans); font-size: 1rem; color: var(--ink);
    background: var(--surface); outline: none;
    transition: border-color 0.15s, box-shadow 0.15s;
}
.form-input:focus {
    border-color: var(--accent);
    box-shadow: 0 0 0 3px oklch(85% 0.07 55 / 0.25);
}
.form-input::placeholder { color: var(--ink-4); }
.form-hint { font-size: 0.82rem; color: var(--ink-3); margin-top: 0.35rem; line-height: 1.5; }

.form-row {
    display: flex; gap: 1rem;
}
.form-row .form-group { flex: 1; }

.units-toggle {
    display: flex; gap: 0;
    border: 1.5px solid var(--border-2); border-radius: var(--r-md);
    overflow: hidden;
}
.units-toggle input[type="radio"] { display: none; }
.units-toggle label {
    flex: 1; text-align: center;
    padding: 0.65rem 1rem;
    font-size: 0.875rem; font-weight: 500;
    color: var(--ink-3); cursor: pointer;
    background: var(--surface);
    border-right: 1px solid var(--border-2);
    transition: all 0.15s;
    line-height: 1.3;
}
.units-toggle label:last-of-type { border-right: none; }
.units-toggle input[type="radio"]:checked + label {
    background: var(--ink);
    color: #fff;
    border-color: var(--ink);
}

/* ── Submit button ── */
.btn-submit {
    display: flex; align-items: center; justify-content: center; gap: 0.5rem;
    width: 100%; padding: 0.875rem 1.5rem;
    background: var(--ink); color: #fff;
    border: none; border-radius: var(--r-md);
    font-family: var(--font-sans); font-size: 1rem; font-weight: 600;
    cursor: pointer; transition: background 0.15s, transform 0.1s;
    margin-top: 0.5rem;
}
.btn-submit:hover { background: var(--ink-2); }
.btn-submit:active { transform: scale(0.99); }

.btn-skip {
    display: block; text-align: center;
    font-size: 0.825rem; color: var(--ink-4);
    margin-top: 0.875rem; text-decoration: none;
    transition: color 0.15s;
}
.btn-skip:hover { color: var(--ink-2); text-decoration: none; }

/* ── Error message ── */
.error-msg {
    background: var(--red-bg);
    border: 1px solid oklch(82% 0.08 22);
    color: var(--red);
    padding: 0.75rem 1rem;
    border-radius: var(--r-md);
    font-size: 0.875rem; font-weight: 500;
    margin-bottom: 1.25rem;
}

/* ── Manage later note ── */
.manage-later {
    text-align: center; font-size: 0.82rem;
    color: var(--ink-4); margin-top: 1.25rem;
}
.manage-later a { color: var(--ink-3); }
.manage-later a:hover { color: var(--ink); }

@media (max-width: 480px) {
    .hero-card { padding: 2rem 1.5rem; }
    .hero-title { font-size: 1.5rem; }
    .hero-body { font-size: 0.975rem; }
    .form-row { flex-direction: column; }
    .top-strip { padding: 0.875rem 1rem; }
    .onboarding-wrap { margin-top: 1.75rem; }
    .step-bar { padding: 1.25rem 1rem 0; }
    .step-circle { width: 40px; height: 40px; font-size: 0.95rem; }
    .step-connector-wrap { padding-top: 20px; }
    .step-label { font-size: 0.7rem; }
    .step-sublabel { display: none; }
}
    </style>
</head>
<body>
<script>
if ('scrollRestoration' in history) history.scrollRestoration = 'manual';
window.scrollTo(0, 0);
function snapTopAndFocus() {
    window.scrollTo(0, 0);
    var input = document.querySelector('.hero-card .form-input');
    if (input) input.focus({ preventScroll: true });
}
document.addEventListener('DOMContentLoaded', function() {
    window.scrollTo(0, 0);
    requestAnimationFrame(function() {
        window.scrollTo(0, 0);
        requestAnimationFrame(snapTopAndFocus);
    });
});
window.addEventListener('pageshow', snapTopAndFocus);
</script>

<!-- Top strip with logo -->
<div class="top-strip">
    <a href="index.php" class="logo-link">
        <img src="sota-planner-logo.svg" width="28" height="28" alt="">
        <span>SOTAplanner</span>
    </a>
    <span class="user-note"><?= htmlspecialchars($callsign) ?></span>
</div>

<!-- Step indicator -->
<div class="setup-header">
    <div class="setup-title">3 steps to get started</div>
</div>
<div class="step-bar">

    <div class="step-item">
        <div class="step-circle <?= $step > 1 ? 'done' : 'active' ?>">
            <?php if ($step > 1): ?>
                <svg width="16" height="16" viewBox="0 0 16 16" fill="none"><path d="M3 8l4 4 6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
            <?php else: ?>
                1
            <?php endif; ?>
        </div>
        <div class="step-label <?= $step > 1 ? 'done' : 'active' ?>">Create Dashboard</div>
        <div class="step-sublabel <?= $step === 1 ? 'active' : '' ?>">Name your dashboard</div>
    </div>

    <div class="step-connector-wrap">
        <div class="step-connector-line <?= $step > 1 ? 'done' : '' ?>"></div>
    </div>

    <div class="step-item">
        <div class="step-circle <?= $step === 2 ? 'active' : ($step > 2 ? 'done' : '') ?>">
            <?php if ($step > 2): ?>
                <svg width="16" height="16" viewBox="0 0 16 16" fill="none"><path d="M3 8l4 4 6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
            <?php else: ?>
                2
            <?php endif; ?>
        </div>
        <div class="step-label <?= $step === 2 ? 'active' : ($step > 2 ? 'done' : '') ?>">Add Crew</div>
        <div class="step-sublabel <?= $step === 2 ? 'active' : '' ?>">Optional</div>
    </div>

    <div class="step-connector-wrap">
        <div class="step-connector-line <?= $step > 2 ? 'done' : '' ?>"></div>
    </div>

    <div class="step-item">
        <div class="step-circle <?= $step >= 3 ? 'active' : '' ?>">3</div>
        <div class="step-label <?= $step >= 3 ? 'active' : '' ?>">Set Location</div>
        <div class="step-sublabel <?= $step === 3 ? 'active' : '' ?>">For travel times</div>
    </div>

</div>

<!-- Main content -->
<div class="onboarding-wrap">

    <?php if ($error): ?>
        <div class="error-msg"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if ($step === 1): ?>
    <!-- ═══════════════════════════════════════════════════════════════ -->
    <!-- STEP 1: Create a Dashboard                                     -->
    <!-- ═══════════════════════════════════════════════════════════════ -->
    <div class="hero-card">

        <div class="step-badge">Step 1 of 3</div>

        <span class="hero-icon">
            <svg width="48" height="48" viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="4,38 16,18 23,28 30,12 44,38"/>
                <line x1="4" y1="38" x2="44" y2="38"/>
            </svg>
        </span>

        <h1 class="hero-title">Create your dashboard</h1>

        <p class="hero-body">
            A <strong>dashboard</strong> is a single activator, or a team of activators, who
            collaborate together to find and research summits. Everyone on the dashboard shares the
            same wishlist — nominating peaks, uploading GPX tracks, adding trail notes, and
            building up research side by side.
        </p>

        <p class="hero-body">
            Solo activators use one dashboard for themselves. Teams name theirs after their crew
            or callsigns. You can create additional dashboards later if you activate with
            multiple sets of friends.
        </p>

        <div class="hero-note">
            After setup, you can add co-activators by callsign from the <strong>Manage Dashboards</strong> page — they'll see all the shared research the next time they log in.
        </div>

        <form method="POST">
            <div class="form-section">
                <div class="form-section-title">Name your dashboard</div>

                <div class="form-group">
                    <label class="form-label" for="group_name">Dashboard name</label>
                    <input
                        type="text"
                        id="group_name"
                        name="group_name"
                        class="form-input"
                        placeholder="e.g., KI6CR Crew, Pacific Northwest Activators, My Summits"
                        value="<?= htmlspecialchars($_POST['group_name'] ?? '') ?>"
                        required
                    >
                    <div class="form-hint">Name it after your callsign, your crew, or wherever you activate most.</div>
                </div>

                <div class="form-group">
                    <label class="form-label">Your Preferred Units</label>
                    <div class="units-toggle">
                        <?php $detected_units = detectUnitsFromCallsign($callsign); ?>
                        <input type="radio" id="units_imperial" name="units" value="imperial"
                            <?= ($_POST['units'] ?? $detected_units) === 'imperial' ? 'checked' : '' ?>>
                        <label for="units_imperial">
                            Imperial<br>
                            <span style="font-size:0.75rem; opacity:0.65;">miles &amp; feet</span>
                        </label>
                        <input type="radio" id="units_metric" name="units" value="metric"
                            <?= ($_POST['units'] ?? $detected_units) === 'metric' ? 'checked' : '' ?>>
                        <label for="units_metric">
                            Metric<br>
                            <span style="font-size:0.75rem; opacity:0.65;">km &amp; meters</span>
                        </label>
                    </div>
                    <div class="form-hint" style="margin-top:0.5rem;">We detected <?= $detected_units === 'imperial' ? 'imperial' : 'metric' ?> from your callsign. You can change this any time in User Settings.</div>
                </div>
            </div>

            <button type="submit" name="create_group" class="btn-submit">
                Create Dashboard &nbsp;→
            </button>
        </form>

    </div>

    <?php elseif ($step === 2): ?>
    <!-- ═══════════════════════════════════════════════════════════════ -->
    <!-- STEP 2: Add Crew Callsigns                                     -->
    <!-- ═══════════════════════════════════════════════════════════════ -->
    <div class="hero-card">

        <div class="step-badge">Step 2 of 3</div>

        <span class="hero-icon">
            <svg width="48" height="48" viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="18" cy="17" r="6"/>
                <path d="M4,42 C4,31 10,27 18,27 C26,27 32,31 32,42"/>
                <circle cx="33" cy="15" r="5"/>
                <path d="M33,25 C39,25 44,29 44,38"/>
            </svg>
        </span>

        <h1 class="hero-title">Who activates with you?</h1>

        <p class="hero-body">
            Add the callsigns of anyone who shares this dashboard with you. <strong>The next time
            they log in to SOTA Planner, this dashboard will already be there</strong> — they'll
            see all the shared research, summit wishlist, and notes without any extra setup
            on their end.
        </p>

        <p class="hero-body">
            Going solo? No problem — skip this and move on.
        </p>

        <div class="hero-note">
            You can add or remove callsigns anytime from <strong>Manage Dashboards</strong> in the nav menu.
        </div>

        <form method="POST">
            <div class="form-section">
                <div class="form-section-title">Co-activator callsigns</div>

                <div class="form-group">
                    <label class="form-label" for="crew_callsigns">Callsigns <span style="font-weight:400; color:var(--ink-4);">(optional)</span></label>
                    <input
                        type="text"
                        id="crew_callsigns"
                        name="crew_callsigns"
                        class="form-input"
                        placeholder="e.g., K3MGM, N6ARA, W6CMY"
                        autocapitalize="characters"
                    >
                    <div class="form-hint">Separate multiple callsigns with commas.</div>
                </div>
            </div>

            <button type="submit" name="add_crew" class="btn-submit">
                Save &amp; Continue &nbsp;→
            </button>
        </form>

        <form method="POST" style="margin:0">
            <button type="submit" name="skip_crew" style="
                display: block; width: 100%; margin-top: 0.75rem;
                padding: 0.8rem 1.5rem;
                background: transparent; border: 1.5px solid var(--border-2);
                border-radius: var(--r-md); cursor: pointer;
                font-family: var(--font-sans); font-size: 0.95rem;
                font-weight: 500; color: var(--ink-3);
                text-align: center; transition: border-color 0.15s, color 0.15s;
            " onmouseover="this.style.borderColor='var(--ink-3)';this.style.color='var(--ink)'"
               onmouseout="this.style.borderColor='var(--border-2)';this.style.color='var(--ink-3)'">
                I'm a solo activator — skip this step
            </button>
        </form>

    </div>

    <?php else: ?>
    <!-- ═══════════════════════════════════════════════════════════════ -->
    <!-- STEP 3: Add a Starting Address                                 -->
    <!-- ═══════════════════════════════════════════════════════════════ -->
    <div class="hero-card">

        <div class="step-badge">Step 3 of 3</div>

        <span class="hero-icon">
            <svg width="48" height="48" viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M24,4 C15.2,4 8,11.2 8,20 C8,31.3 24,46 24,46 C24,46 40,31.3 40,20 C40,11.2 32.8,4 24,4Z"/>
                <circle cx="24" cy="20" r="6"/>
            </svg>
        </span>

        <h1 class="hero-title">Set your starting location</h1>

        <p class="hero-body">
            SOTA Planner calculates <strong>travel time from this address to each summit's
            starting point</strong>, then adds it to the hike time and radio time. That total
            door-to-door number is the key figure for deciding whether a summit fits your day.
        </p>

        <p class="hero-body">
            Enter your home neighborhood, a nearby cross street, a zip code — anything
            Google Maps can find. You don't need to use your exact address.
        </p>

        <div class="hero-note">
            You can add multiple locations and switch between them anytime. Use a cross street
            near home for privacy, or add a work address for weekday activations.
        </div>

        <form method="POST">
            <div class="form-section">
                <div class="form-section-title">Where are you starting from?</div>

                <div class="form-group">
                    <label class="form-label" for="addr_address">Address or location</label>
                    <input
                        type="text"
                        id="addr_address"
                        name="address"
                        class="form-input"
                        placeholder="e.g., 91601, Biloxi &amp; Burbank Blvd, Moby's Coffee &amp; Tea"
                        value="<?= htmlspecialchars($_POST['address'] ?? '') ?>"
                        required
                    >
                    <div class="form-hint">Anything Google Maps can find works — a zip code, a local business, cross streets, or a full address.</div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="addr_label">Nickname <span style="font-weight:400; color:var(--ink-4);">(optional)</span></label>
                    <input
                        type="text"
                        id="addr_label"
                        name="label"
                        class="form-input"
                        placeholder="e.g., Home, Work, Cabin"
                        value="<?= htmlspecialchars($_POST['label'] ?? '') ?>"
                    >
                </div>
            </div>

            <button type="submit" name="add_address" class="btn-submit">
                Save &amp; Go to Dashboard &nbsp;→
            </button>
        </form>

        <a href="index.php" class="btn-skip">Skip for now — I'll add an address later</a>

    </div>

    <?php endif; ?>

    <div class="manage-later">
        You can update all of this anytime from <a href="planning_groups.php">Manage Dashboards</a>.
    </div>

</div>

</body>
</html>
