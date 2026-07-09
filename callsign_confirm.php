<?php
require_once 'config.php';
session_start();
requireLogin();

// Only SSO users need callsign confirmation
if (($_SESSION['sota_login_type'] ?? '') !== 'sota_oauth') {
    header('Location: index.php');
    exit;
}

$db = getDbConnection();
$callsign = getCurrentCallsign();

// Already confirmed — send to the right place
$stmt = $db->prepare("SELECT callsign_confirmed FROM users WHERE callsign = ?");
$stmt->execute([$callsign]);
$user_row = $stmt->fetch();
if ($user_row && (int)$user_row['callsign_confirmed']) {
    header('Location: index.php');
    exit;
}

$sso_username   = strtoupper($_SESSION['sota_sso_preferred_username'] ?? $callsign);
$sso_sub        = $_SESSION['sota_sso_sub'] ?? null;
$error          = '';
$prefill        = $_POST['callsign'] ?? $sso_username;

// ── POST handler ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new_callsign  = strtoupper(preg_replace('/[^A-Z0-9\/]/', '', strtoupper(trim($_POST['callsign'] ?? ''))));
    $raw_extra     = trim($_POST['additional_callsigns'] ?? '');

    // Sanitise additional callsigns: split, strip non-alphanumeric, uppercase, dedupe
    $extra_list = [];
    foreach (explode(',', $raw_extra) as $cs) {
        $cs = strtoupper(preg_replace('/[^A-Z0-9\/]/', '', strtoupper(trim($cs))));
        if ($cs !== '' && $cs !== $new_callsign && !in_array($cs, $extra_list)) {
            $extra_list[] = $cs;
        }
    }
    $additional_callsigns = $extra_list ? implode(', ', $extra_list) : null;

    if (!preg_match('/^[A-Z0-9]{3,10}$/', $new_callsign)) {
        $error   = 'Please enter a valid callsign (3–10 letters and numbers, no spaces).';
        $prefill = $new_callsign;
    } else {
        // If callsign changed, rename all records
        if ($new_callsign !== $callsign) {
            updateUserCallsign($db, $callsign, $new_callsign);
            $callsign = $new_callsign;
        }

        // Mark confirmed, store additional callsigns and SSO sub
        $db->prepare("
            INSERT INTO users (callsign, callsign_confirmed, additional_callsigns, sso_sub)
            VALUES (?, 1, ?, ?)
            ON DUPLICATE KEY UPDATE
                callsign_confirmed     = 1,
                additional_callsigns   = VALUES(additional_callsigns),
                sso_sub                = VALUES(sso_sub)
        ")->execute([$callsign, $additional_callsigns, $sso_sub]);

        // Route: does this user have any planning groups?
        $stmt = $db->prepare("
            SELECT pg.id FROM planning_groups pg
            LEFT JOIN planning_group_members pgm ON pg.id = pgm.planning_group_id
            WHERE pg.owner_callsign = ? OR pgm.callsign = ?
            ORDER BY pg.id ASC
        ");
        $stmt->execute([$callsign, $callsign]);
        $groups = $stmt->fetchAll();

        if (empty($groups)) {
            header('Location: onboarding.php');
            exit;
        }

        // Restore last-used group from cookie, fall back to first group
        $target_group_id = $groups[0]['id'];
        if (!empty($_COOKIE['sota_default_group'])) {
            $cookie_id = (int)$_COOKIE['sota_default_group'];
            foreach ($groups as $g) {
                if ((int)$g['id'] === $cookie_id) {
                    $target_group_id = $cookie_id;
                    break;
                }
            }
        }
        setCurrentPlanningGroup($target_group_id);
        header('Location: index.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Confirm Your Callsign — SOTA Planner</title>
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
}

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html { font-size: 16px; -webkit-font-smoothing: antialiased; }
body { font-family: var(--font-sans); background: var(--bg); color: var(--ink); line-height: 1.5; min-height: 100vh; }

.top-strip {
    background: var(--surface); border-bottom: 1px solid var(--border);
    padding: 0.875rem 2rem;
    display: flex; align-items: center; justify-content: space-between;
}
.logo-link {
    display: flex; align-items: center; gap: 0.625rem;
    text-decoration: none; color: var(--ink); font-weight: 600; font-size: 0.95rem;
}
.logo-link:hover { text-decoration: none; color: var(--ink); }
.sso-note {
    font-size: 0.78rem; color: var(--ink-3);
    background: var(--accent-bg); border: 1px solid var(--accent-border);
    border-radius: 100px; padding: 0.2rem 0.75rem;
}

.page-wrap {
    max-width: 560px; margin: 3rem auto 4rem; padding: 0 1.25rem;
}

.hero-card {
    background: var(--surface); border: 1px solid var(--border);
    border-radius: var(--r-xl); padding: 3rem 2.5rem 2.5rem;
    box-shadow: var(--shadow-md);
}

.step-badge {
    display: inline-flex; align-items: center; gap: 0.35rem;
    font-size: 0.7rem; font-weight: 700;
    text-transform: uppercase; letter-spacing: 0.08em;
    color: var(--accent); background: var(--accent-bg);
    border: 1px solid var(--accent-border);
    border-radius: 100px; padding: 0.2rem 0.7rem; margin-bottom: 1.25rem;
}

.hero-icon { display: block; margin-bottom: 1.25rem; color: var(--accent); }

.hero-title {
    font-size: 1.85rem; font-weight: 600; letter-spacing: -0.02em;
    color: var(--ink); line-height: 1.2; margin-bottom: 0.875rem;
}

.hero-body {
    font-size: 1.05rem; color: var(--ink-2); line-height: 1.65; margin-bottom: 0.75rem;
}
.hero-body strong { color: var(--ink); font-weight: 600; }

.username-pill {
    display: inline-block;
    font-family: var(--font-mono); font-size: 0.9rem; font-weight: 500;
    background: var(--bg-2); border: 1px solid var(--border-2);
    border-radius: var(--r-sm); padding: 0.1rem 0.5rem;
    color: var(--ink-2);
}

.form-section { border-top: 1px solid var(--border); padding-top: 1.75rem; margin-top: 1.75rem; }
.form-section-title {
    font-size: 0.75rem; font-weight: 700; text-transform: uppercase;
    letter-spacing: 0.08em; color: var(--ink-3); margin-bottom: 1.25rem;
}

.form-group { margin-bottom: 1.25rem; }
.form-label { display: block; font-size: 0.82rem; font-weight: 600; color: var(--ink-2); margin-bottom: 0.4rem; }
.form-input {
    width: 100%; padding: 0.7rem 0.875rem;
    border: 1.5px solid var(--border-2); border-radius: var(--r-md);
    font-family: var(--font-mono); font-size: 1.05rem; color: var(--ink);
    background: var(--surface); outline: none;
    transition: border-color 0.15s, box-shadow 0.15s;
    text-transform: uppercase; letter-spacing: 0.05em;
}
.form-input.plain {
    font-family: var(--font-sans); font-size: 1rem;
    text-transform: none; letter-spacing: normal;
}
.form-input:focus {
    border-color: var(--accent);
    box-shadow: 0 0 0 3px oklch(85% 0.07 55 / 0.25);
}
.form-input::placeholder { color: var(--ink-4); text-transform: none; letter-spacing: normal; }
.form-hint { font-size: 0.82rem; color: var(--ink-3); margin-top: 0.35rem; line-height: 1.5; }

.btn-submit {
    display: flex; align-items: center; justify-content: center; gap: 0.5rem;
    width: 100%; padding: 0.875rem 1.5rem;
    background: var(--ink); color: #fff; border: none; border-radius: var(--r-md);
    font-family: var(--font-sans); font-size: 1rem; font-weight: 600;
    cursor: pointer; transition: background 0.15s, transform 0.1s; margin-top: 0.5rem;
}
.btn-submit:hover { background: var(--ink-2); }
.btn-submit:active { transform: scale(0.99); }

.error-msg {
    background: var(--red-bg); border: 1px solid oklch(82% 0.08 22);
    color: var(--red); padding: 0.75rem 1rem; border-radius: var(--r-md);
    font-size: 0.875rem; font-weight: 500; margin-bottom: 1.25rem;
}

@media (max-width: 480px) {
    .hero-card { padding: 2rem 1.5rem; }
    .hero-title { font-size: 1.5rem; }
    .hero-body { font-size: 0.975rem; }
    .top-strip { padding: 0.875rem 1rem; }
}
    </style>
</head>
<body>

<div class="top-strip">
    <a href="index.php" class="logo-link">
        <img src="sota-planner-logo.svg" width="28" height="28" alt="">
        <span>SOTAplanner</span>
    </a>
    <span class="sso-note">Signed in via SOTA SSO</span>
</div>

<div class="page-wrap">

    <?php if ($error): ?>
        <div class="error-msg"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="hero-card">

        <div class="step-badge">One-time setup</div>

        <span class="hero-icon">
            <svg width="48" height="48" viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="24" cy="16" r="8"/>
                <path d="M8,42 C8,33 15,28 24,28 C33,28 40,33 40,42"/>
                <polyline points="30,10 33,13 39,7"/>
            </svg>
        </span>

        <h1 class="hero-title">Confirm your callsign</h1>

        <p class="hero-body">
            Your SOTA account is registered under the username
            <span class="username-pill"><?= htmlspecialchars($sso_username) ?></span>.
            Most operators use their callsign as their SOTA username, but not everyone does.
        </p>
        <p class="hero-body">
            Please confirm the callsign you use for activations — this is how you'll appear in
            planning groups and activation records. If your username already <em>is</em> your
            callsign, just click Confirm.
        </p>

        <form method="POST">
            <div class="form-section">
                <div class="form-section-title">Your callsign</div>

                <div class="form-group">
                    <label class="form-label" for="callsign">Primary callsign</label>
                    <input
                        type="text"
                        id="callsign"
                        name="callsign"
                        class="form-input"
                        placeholder="e.g., KI6CR"
                        value="<?= htmlspecialchars($prefill) ?>"
                        autocapitalize="characters"
                        autocorrect="off"
                        spellcheck="false"
                        required
                    >
                    <div class="form-hint">The callsign you use on-air for SOTA activations.</div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="additional_callsigns">
                        Additional callsigns
                        <span style="font-weight:400; color:var(--ink-4);">(optional)</span>
                    </label>
                    <input
                        type="text"
                        id="additional_callsigns"
                        name="additional_callsigns"
                        class="form-input plain"
                        placeholder="e.g., W6CMY, KI6CR/VK3"
                        value="<?= htmlspecialchars($_POST['additional_callsigns'] ?? '') ?>"
                        autocapitalize="characters"
                        autocorrect="off"
                        spellcheck="false"
                    >
                    <div class="form-hint">Other calls you operate under — club callsigns, portable calls, etc. Separate with commas. These are stored for reference and can be updated anytime in User Settings.</div>
                </div>
            </div>

            <button type="submit" class="btn-submit">
                Confirm &amp; Continue &nbsp;→
            </button>
        </form>

    </div>

</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var input = document.getElementById('callsign');
    if (input) {
        input.focus();
        input.select();
        input.addEventListener('input', function () {
            this.value = this.value.toUpperCase().replace(/[^A-Z0-9\/]/g, '');
        });
    }
    var extra = document.getElementById('additional_callsigns');
    if (extra) {
        extra.addEventListener('input', function () {
            this.value = this.value.toUpperCase().replace(/[^A-Z0-9\/,\s]/g, '');
        });
    }
});
</script>

</body>
</html>
