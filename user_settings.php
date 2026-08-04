<?php
require_once 'config.php';
session_start();
requireLogin();

$db           = getDbConnection();
$current_user = getCurrentCallsign();
$is_sso       = ($_SESSION['sota_login_type'] ?? '') === 'sota_oauth';
$message      = '';
$callsign_message = '';
$callsign_error   = '';

// ── Save preferences ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_preferences'])) {
    $new_units       = in_array($_POST['units'] ?? '', ['imperial', 'metric']) ? $_POST['units'] : detectUnitsFromCallsign($current_user);
    $activation_time = max(15, min(300, (int)($_POST['default_activation_time'] ?? 60)));

    $db->prepare("
        INSERT INTO user_settings (user_callsign, default_activation_time_min, units)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE
            default_activation_time_min = VALUES(default_activation_time_min),
            units = VALUES(units)
    ")->execute([$current_user, $activation_time, $new_units]);

    $_SESSION['user_units'] = $new_units;
    $message = 'Settings saved.';
}

// ── Save callsigns ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_callsigns'])) {
    $new_primary = strtoupper(preg_replace('/[^A-Z0-9]/', '', strtoupper(trim($_POST['primary_callsign'] ?? ''))));
    $raw_extra   = trim($_POST['additional_callsigns'] ?? '');

    $extra_list = [];
    foreach (explode(',', $raw_extra) as $cs) {
        $cs = strtoupper(preg_replace('/[^A-Z0-9\/]/', '', strtoupper(trim($cs))));
        if ($cs !== '' && $cs !== $new_primary && !in_array($cs, $extra_list)) {
            $extra_list[] = $cs;
        }
    }
    $additional_callsigns = $extra_list ? implode(', ', $extra_list) : null;

    if ($is_sso && !preg_match('/^[A-Z0-9]{3,10}$/', $new_primary)) {
        $callsign_error = 'Please enter a valid callsign (3–10 letters and numbers).';
    } else {
        if ($is_sso && $new_primary !== $current_user) {
            updateUserCallsign($db, $current_user, $new_primary);
            $current_user = $new_primary;
        }
        $db->prepare("
            INSERT INTO users (callsign, callsign_confirmed, additional_callsigns)
            VALUES (?, 1, ?)
            ON DUPLICATE KEY UPDATE additional_callsigns = VALUES(additional_callsigns)
        ")->execute([$current_user, $additional_callsigns]);
        $callsign_message = 'Callsigns saved.';
    }
}

$stmt = $db->prepare("SELECT * FROM user_settings WHERE user_callsign = ?");
$stmt->execute([$current_user]);
$settings = $stmt->fetch();

$current_units       = $settings['units'] ?? detectUnitsFromCallsign($current_user);
$default_activation  = (int)($settings['default_activation_time_min'] ?? 60);

$stmt = $db->prepare("SELECT additional_callsigns FROM users WHERE callsign = ?");
$stmt->execute([$current_user]);
$user_profile = $stmt->fetch();
$additional_callsigns = $user_profile['additional_callsigns'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Settings — SOTA Planner</title>
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
      --green-border:  oklch(85% 0.07 155);
      --surface:       #FFFFFF;
      --border:        #E5E2DA;
      --border-2:      #D4D0C8;
      --font-sans:     'DM Sans', system-ui, sans-serif;
      --font-mono:     'DM Mono', 'Courier New', monospace;
      --r-sm: 4px; --r-md: 8px; --r-lg: 12px;
      --shadow-sm: 0 1px 3px rgba(28,27,25,0.07), 0 1px 2px rgba(28,27,25,0.05);
    }
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    html { font-size: 16px; -webkit-font-smoothing: antialiased; }
    body { font-family: var(--font-sans); background: var(--bg); color: var(--ink); line-height: 1.5; min-height: 100vh; }

    .topbar {
      background: var(--surface); border-bottom: 1px solid var(--border);
      height: 56px; display: flex; align-items: center;
      padding: 0 2rem; gap: 1rem; position: sticky; top: 0; z-index: 100;
    }
    .topbar-logo {
      display: flex; align-items: center; gap: 0.75rem;
      text-decoration: none; color: var(--ink);
      font-weight: 600; font-size: 0.95rem; letter-spacing: -0.01em; flex-shrink: 0;
    }
    .topbar-logo:hover { color: var(--ink); }
    .topbar-logo .logo-mark { width: 32px; height: 32px; flex-shrink: 0; }
    .topbar-divider { width: 1px; height: 20px; background: var(--border); flex-shrink: 0; }
    .topbar-nav { display: flex; align-items: center; gap: 0.25rem; }
    .topbar-nav a {
      color: var(--ink-3); font-size: 0.875rem; font-weight: 500;
      padding: 0.5rem 0.75rem; border-radius: var(--r-sm);
      transition: color 0.15s, background 0.15s; text-decoration: none;
    }
    .topbar-nav a:hover { color: var(--ink); background: var(--bg-2); }
    .topbar-right { display: flex; align-items: center; gap: 0.75rem; margin-left: auto; flex-shrink: 0; }
    .user-chip {
      position: relative; display: flex; align-items: center; gap: 0.35rem;
      cursor: pointer; padding: 0.25rem 0.6rem; border-radius: var(--r-sm);
      font-size: 0.8rem; font-weight: 600; color: var(--ink-2);
      border: 1px solid var(--border); background: var(--bg); user-select: none;
    }
    .user-chip:hover { background: var(--bg-2); }
    .user-chip-chevron { transition: transform 0.15s; }
    .user-chip.open .user-chip-chevron { transform: rotate(180deg); }
    .user-dropdown {
      display: none; position: absolute; top: calc(100% + 6px); right: 0;
      background: #fff; border: 1px solid var(--border); border-radius: var(--r-sm);
      box-shadow: 0 4px 16px rgba(0,0,0,0.10); min-width: 130px; overflow: hidden; z-index: 200;
    }
    .user-chip.open .user-dropdown { display: block; }
    .user-dropdown a {
      display: block; padding: 0.6rem 1rem;
      font-size: 0.82rem; font-weight: 500; color: var(--ink-2); text-decoration: none;
    }
    .user-dropdown a:hover { background: var(--bg-2); color: var(--ink); }

    .page { padding: 2rem; max-width: 560px; margin: 0 auto; }

    .page-header { margin-bottom: 1.75rem; }
    .page-header h1 { font-size: 1.375rem; font-weight: 600; letter-spacing: -0.02em; margin-bottom: 0.2rem; }
    .page-header p  { font-size: 0.875rem; color: var(--ink-3); }

    .card {
      background: var(--surface); border: 1px solid var(--border);
      border-radius: var(--r-lg); box-shadow: var(--shadow-sm);
      overflow: hidden; margin-bottom: 1.25rem;
    }
    .card-section {
      padding: 1.25rem 1.5rem;
      border-bottom: 1px solid var(--border);
    }
    .card-section:last-child { border-bottom: none; }
    .section-label {
      font-size: 0.7rem; font-weight: 700; text-transform: uppercase;
      letter-spacing: 0.08em; color: var(--ink-3); margin-bottom: 0.75rem;
    }

    .msg-success {
      display: flex; align-items: center; gap: 0.75rem;
      background: var(--green-bg); color: var(--green);
      border: 1px solid var(--green-border);
      border-radius: var(--r-md); padding: 0.75rem 1rem;
      font-size: 0.875rem; font-weight: 500; margin-bottom: 1.25rem;
    }

    /* Units toggle */
    .units-toggle { display: flex; border: 1px solid var(--border); border-radius: var(--r-md); overflow: hidden; width: 100%; }
    .units-toggle input[type="radio"] { display: none; }
    .units-toggle label {
      flex: 1; text-align: center;
      padding: 0.5rem 1rem; font-size: 0.875rem; font-weight: 500;
      color: var(--ink-3); cursor: pointer; transition: background 0.12s, color 0.12s;
      border-right: 1px solid var(--border);
    }
    .units-toggle label:last-of-type { border-right: none; }
    .units-toggle input[type="radio"]:checked + label {
      background: var(--ink); color: #fff;
    }
    .hint { font-size: 0.775rem; color: var(--ink-3); margin-top: 0.5rem; line-height: 1.4; }

    /* Activation time */
    .time-row { display: flex; align-items: center; gap: 0.75rem; }
    .time-input {
      width: 90px; padding: 0.5rem 0.75rem;
      border: 1px solid var(--border); border-radius: var(--r-md);
      font-family: var(--font-sans); font-size: 0.9375rem; color: var(--ink);
      background: var(--surface); outline: none; transition: border-color 0.15s;
    }
    .time-input:focus { border-color: var(--accent); box-shadow: 0 0 0 3px var(--accent-bg); }
    .time-unit { font-size: 0.875rem; color: var(--ink-3); }

    .btn-save {
      display: inline-flex; align-items: center; justify-content: center;
      height: 38px; padding: 0 1.25rem; border-radius: var(--r-md);
      background: var(--ink); color: #fff;
      font-family: var(--font-sans); font-size: 0.875rem; font-weight: 500;
      border: none; cursor: pointer; transition: background 0.15s;
    }
    .btn-save:hover { background: var(--ink-2); }
    .btn-save:active { transform: scale(0.98); }
  </style>
</head>
<body>

<nav class="topbar">
  <a href="index.php" class="topbar-logo">
    <span class="logo-mark"><img src="sota-planner-logo.svg" width="32" height="32" alt=""></span>
    <span>SOTAplanner</span>
  </a>
  <div class="topbar-divider"></div>
  <div class="topbar-nav">
    <a href="index.php">Dashboard</a>
    <a href="planning_groups.php">Manage Dashboards</a>
  </div>
  <div class="topbar-right">
    <div class="user-chip" onclick="this.classList.toggle('open')" id="userChip">
      <span><?= htmlspecialchars($current_user) ?></span>
      <svg class="user-chip-chevron" width="10" height="6" viewBox="0 0 10 6" fill="none">
        <path d="M1 1l4 4 4-4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
      </svg>
      <div class="user-dropdown">
        <?php if ($current_user === 'KI6CR' || !empty($_SESSION['_god_mode_real_callsign'])): ?>
          <a href="god_mode.php">God Mode</a>
        <?php endif; ?>
        <a href="user_settings.php">Settings</a>
        <a href="logout.php">Sign Out</a>
      </div>
    </div>
  </div>
</nav>

<div class="page">

  <div class="page-header">
    <h1>Settings</h1>
    <p>Preferences for <?= htmlspecialchars($current_user) ?></p>
  </div>

  <?php if ($message): ?>
  <div class="msg-success">
    <svg width="16" height="16" viewBox="0 0 16 16" fill="none"><circle cx="8" cy="8" r="7" stroke="currentColor" stroke-width="1.4"/><path d="M5 8l2 2 4-4" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
    <?= htmlspecialchars($message) ?>
  </div>
  <?php endif; ?>

  <form method="POST">
    <div class="card">

      <div class="card-section">
        <div class="section-label">Units of Measurement</div>
        <div class="units-toggle">
          <input type="radio" id="units_imperial" name="units" value="imperial" <?= $current_units === 'imperial' ? 'checked' : '' ?>>
          <label for="units_imperial">Imperial &mdash; miles &amp; feet</label>
          <input type="radio" id="units_metric" name="units" value="metric" <?= $current_units === 'metric' ? 'checked' : '' ?>>
          <label for="units_metric">Metric &mdash; km &amp; meters</label>
        </div>
        <div class="hint">Applies to all distances and elevations across the app. Auto-detected from your callsign.</div>
      </div>

      <div class="card-section">
        <div class="section-label">Default Time on Summit</div>
        <div class="time-row">
          <input type="number" class="time-input" name="default_activation_time"
            value="<?= $default_activation ?>" min="15" max="300" step="15" required>
          <span class="time-unit">minutes</span>
        </div>
        <div class="hint">How long you typically spend operating from a summit. Used as the default when planning a new activation.</div>
      </div>

    </div>

    <button type="submit" name="save_preferences" class="btn-save">Save Settings</button>
  </form>

</div>

  <!-- ── Callsigns card ─────────────────────────────────────────────── -->
  <?php if ($callsign_message): ?>
  <div class="msg-success" style="margin-top:1.25rem;">
    <svg width="16" height="16" viewBox="0 0 16 16" fill="none"><circle cx="8" cy="8" r="7" stroke="currentColor" stroke-width="1.4"/><path d="M5 8l2 2 4-4" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
    <?= htmlspecialchars($callsign_message) ?>
  </div>
  <?php endif; ?>
  <?php if ($callsign_error): ?>
  <div style="background:var(--red-bg);border:1px solid oklch(82% 0.08 22);color:var(--red);border-radius:var(--r-md);padding:0.75rem 1rem;font-size:0.875rem;font-weight:500;margin-top:1.25rem;">
    <?= htmlspecialchars($callsign_error) ?>
  </div>
  <?php endif; ?>

  <form method="POST" style="margin-top:1.25rem;">
    <div class="card">

      <div class="card-section">
        <div class="section-label">Callsigns</div>

        <?php if ($is_sso): ?>
          <div style="margin-bottom:1rem;">
            <label class="section-label" style="margin-bottom:0.35rem; display:block; text-transform:none; font-size:0.82rem; color:var(--ink-2); font-weight:600;">Primary callsign</label>
            <input
              type="text"
              name="primary_callsign"
              value="<?= htmlspecialchars($current_user) ?>"
              autocapitalize="characters" autocorrect="off" spellcheck="false"
              style="width:100%; padding:0.5rem 0.75rem; border:1px solid var(--border); border-radius:var(--r-md);
                     font-family:var(--font-mono); font-size:0.9375rem; color:var(--ink);
                     background:var(--surface); outline:none; text-transform:uppercase; letter-spacing:0.04em;"
              oninput="this.value=this.value.toUpperCase().replace(/[^A-Z0-9]/g,'')"
            >
            <div class="hint">Your on-air callsign. Changing this renames your account across all dashboards and records.</div>
          </div>
        <?php else: ?>
          <div style="margin-bottom:1rem;">
            <div style="font-size:0.82rem; font-weight:600; color:var(--ink-2); margin-bottom:0.35rem;">Primary callsign</div>
            <div style="font-family:var(--font-mono); font-size:0.9375rem; color:var(--ink); letter-spacing:0.04em;
                        padding:0.5rem 0.75rem; background:var(--bg-2); border:1px solid var(--border);
                        border-radius:var(--r-md);"><?= htmlspecialchars($current_user) ?></div>
            <div class="hint">Logged in via early access — callsign is set at login.</div>
          </div>
        <?php endif; ?>

        <div>
          <label class="section-label" style="margin-bottom:0.35rem; display:block; text-transform:none; font-size:0.82rem; color:var(--ink-2); font-weight:600;">
            Additional callsigns <span style="font-weight:400; color:var(--ink-4);">(optional)</span>
          </label>
          <input
            type="text"
            name="additional_callsigns"
            value="<?= htmlspecialchars($additional_callsigns) ?>"
            placeholder="e.g., W6CMY, KI6CR/VK3"
            autocapitalize="characters" autocorrect="off" spellcheck="false"
            style="width:100%; padding:0.5rem 0.75rem; border:1px solid var(--border); border-radius:var(--r-md);
                   font-family:var(--font-mono); font-size:0.9375rem; color:var(--ink);
                   background:var(--surface); outline:none; text-transform:uppercase; letter-spacing:0.02em;"
            oninput="this.value=this.value.toUpperCase().replace(/[^A-Z0-9\/,\s]/g,'')"
          >
          <div class="hint">Other calls you operate under — club callsigns, portable calls, etc. Separate with commas. Stored for reference.</div>
        </div>
      </div>

    </div>

    <button type="submit" name="save_callsigns" class="btn-save">Save Callsigns</button>
  </form>

<footer style="text-align:center; padding:2rem 1rem 1.5rem; color:var(--ink-4); font-size:0.78rem;">
  SOTA Planner &nbsp;·&nbsp;
  <a href="changelog.php" style="color:var(--ink-4); text-decoration:none;">v<?= APP_VERSION ?></a>
  &nbsp;·&nbsp;
  <a href="https://sotaplanner.com" style="color:var(--ink-4); text-decoration:none;">sotaplanner.com</a>
</footer>

<script>
document.addEventListener('click', function(e) {
  var chip = document.getElementById('userChip');
  if (chip && !chip.contains(e.target)) chip.classList.remove('open');
});
</script>
</body>
</html>
