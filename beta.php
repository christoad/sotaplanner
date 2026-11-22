<?php
// Beta Features: what beta testers can try early and the test cases we'd like them to run.
// Content lives in beta_features_list.php. Only testers and God Mode can open this page.
require_once 'config.php';
session_start();
requireLogin();

$current_user = getCurrentCallsign();
if (!canSeeBeta()) {
    header('Location: index.php');
    exit;
}
session_write_close();

$features = require __DIR__ . '/beta_features_list.php';
$testing  = array_values(array_filter($features, fn($f) => ($f['status'] ?? '') === 'testing'));
$next     = array_values(array_filter($features, fn($f) => ($f['status'] ?? '') === 'next'));
$h = fn($s) => htmlspecialchars((string)$s);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Beta Features | SOTA Planner</title>
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
      --blue:          oklch(52% 0.12 240);
      --blue-bg:       oklch(95% 0.04 240);
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
    a { color: var(--accent-2); }

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

    .page { padding: 2rem; max-width: 760px; margin: 0 auto; }
    .page-header { margin-bottom: 1.5rem; }
    .page-header h1 { font-size: 1.375rem; font-weight: 600; letter-spacing: -0.02em; margin-bottom: 0.25rem; }
    .page-header p  { font-size: 0.9rem; color: var(--ink-2); max-width: 600px; }

    .section-label {
      font-size: 0.72rem; font-weight: 700; text-transform: uppercase;
      letter-spacing: 0.08em; color: var(--ink-3); margin: 2rem 0 0.75rem;
    }

    .card {
      background: var(--surface); border: 1px solid var(--border);
      border-radius: var(--r-lg); box-shadow: var(--shadow-sm);
      padding: 1.25rem 1.5rem; margin-bottom: 1rem;
    }
    .card-info { background: var(--blue-bg); border-color: oklch(85% 0.06 240); box-shadow: none; }
    .card-info h2 { font-size: 0.95rem; font-weight: 600; color: var(--blue); margin-bottom: 0.4rem; }
    .card-info ul { padding-left: 1.1rem; font-size: 0.86rem; color: var(--ink-2); }
    .card-info li { margin: 0.2rem 0; }

    .feat-head { display: flex; align-items: center; gap: 0.6rem; flex-wrap: wrap; margin-bottom: 0.4rem; }
    .feat-head h2 { font-size: 1.1rem; font-weight: 600; letter-spacing: -0.01em; }
    .tag {
      display: inline-block; font-size: 0.62rem; font-weight: 700; border-radius: var(--r-sm);
      padding: 0.12rem 0.4rem; text-transform: uppercase; letter-spacing: 0.08em; white-space: nowrap;
    }
    .tag-beta { background: var(--blue); color: #fff; }
    .tag-next { background: var(--bg-3); color: var(--ink-3); }
    .feat-since { font-size: 0.78rem; color: var(--ink-3); }
    .feat-summary { font-size: 0.9rem; color: var(--ink-2); }

    .btn {
      display: inline-flex; align-items: center; justify-content: center; gap: 0.5rem;
      height: 34px; padding: 0 1rem; border-radius: var(--r-md);
      background: var(--ink); color: #fff; font-family: var(--font-sans);
      font-size: 0.85rem; font-weight: 500; text-decoration: none; margin-top: 0.9rem;
    }
    .btn:hover { background: var(--ink-2); color: #fff; }

    .sub-label { font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.07em; color: var(--ink-3); margin: 1.25rem 0 0.5rem; }
    .focus-list { padding-left: 1.1rem; font-size: 0.88rem; color: var(--ink-2); }
    .focus-list li { margin: 0.2rem 0; }

    .case { border: 1px solid var(--border); border-radius: var(--r-md); margin-bottom: 0.6rem; background: var(--bg); }
    .case summary {
      list-style: none; cursor: pointer; display: flex; align-items: center; gap: 0.65rem;
      padding: 0.7rem 0.9rem; font-size: 0.9rem; font-weight: 600;
    }
    .case summary::-webkit-details-marker { display: none; }
    .case-num {
      flex-shrink: 0; width: 1.6rem; height: 1.6rem; border-radius: 50%;
      display: inline-flex; align-items: center; justify-content: center;
      background: var(--surface); border: 1px solid var(--border-2);
      font-family: var(--font-mono); font-size: 0.72rem; color: var(--ink-2);
    }
    .case-title { flex: 1; }
    .case-chevron { flex-shrink: 0; color: var(--ink-3); transition: transform 0.15s; }
    .case[open] .case-chevron { transform: rotate(180deg); }
    .case.done .case-num { background: var(--green); border-color: var(--green); color: #fff; }
    .case.done .case-title { color: var(--ink-3); }
    .case-body { padding: 0 0.9rem 0.9rem 3.15rem; font-size: 0.88rem; color: var(--ink-2); }
    .case-body ol { padding-left: 1.1rem; }
    .case-body li { margin: 0.25rem 0; }
    .case-expect {
      margin-top: 0.7rem; padding: 0.55rem 0.75rem; border-radius: var(--r-sm);
      background: var(--green-bg); color: var(--ink-2); font-size: 0.85rem;
    }
    .case-expect strong { color: var(--green); }
    .case-done { display: inline-flex; align-items: center; gap: 0.4rem; margin-top: 0.7rem; font-size: 0.82rem; color: var(--ink-3); cursor: pointer; }

    .empty { text-align: center; padding: 1.75rem 1rem; color: var(--ink-3); font-size: 0.9rem; }

    @media (max-width: 640px) {
      .topbar { padding: 0 1rem; }
      .topbar-nav, .topbar-divider { display: none; }
      .page { padding: 1.25rem 1rem; }
      .case-body { padding-left: 0.9rem; }
    }
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
      <?= userChipName($current_user) ?>
      <svg class="user-chip-chevron" width="10" height="6" viewBox="0 0 10 6" fill="none">
        <path d="M1 1l4 4 4-4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
      </svg>
      <div class="user-dropdown">
        <?php if ($current_user === 'KI6CR' || !empty($_SESSION['_god_mode_real_callsign'])): ?>
          <a href="god_mode.php">God Mode</a>
        <?php endif; ?>
        <?= betaMenuLink() ?>
        <a href="user_settings.php">Settings</a>
        <a href="logout.php">Sign Out</a>
      </div>
    </div>
  </div>
</nav>

<div class="page">

  <div class="page-header">
    <h1>Beta Features</h1>
    <p>Thanks for helping test SOTAplanner. These features are open to you before everyone else, and your feedback decides what changes before they go public.</p>
  </div>

  <div class="card card-info">
    <h2>How beta testing works</h2>
    <ul>
      <li>Beta features can change, look unfinished or break. That's expected; it's what we want to find.</li>
      <li>Your dashboards, summits and tracks are safe. Beta features never change anyone else's data.</li>
      <li>Work through the test cases below, then try the feature your own way too. Anything confusing is worth reporting.</li>
      <li>Send feedback by replying to Chris's (KI6CR) beta testing email. Mention the test case number, what you did and what happened. A screenshot helps.</li>
    </ul>
  </div>

  <div class="section-label">Ready to test</div>
  <?php if (!$testing): ?>
    <div class="card"><div class="empty">Nothing to test right now. You'll get an email when the next feature is ready for you.</div></div>
  <?php endif; ?>

  <?php foreach ($testing as $f): $key = preg_replace('/[^a-z0-9_]/', '', $f['key']); ?>
    <div class="card">
      <div class="feat-head">
        <h2><?= $h($f['name']) ?></h2>
        <span class="tag tag-beta">Beta</span>
        <?php if (!empty($f['since'])): ?>
          <span class="feat-since">Open to testers since <?= date('M j, Y', strtotime($f['since'])) ?></span>
        <?php endif; ?>
      </div>
      <p class="feat-summary"><?= $h($f['summary']) ?></p>
      <?php if (!empty($f['where']['url'])): ?>
        <a class="btn" href="<?= $h($f['where']['url']) ?>"><?= $h($f['where']['text'] ?? 'Try it') ?></a>
      <?php endif; ?>

      <?php if (!empty($f['focus'])): ?>
        <div class="sub-label">What we'd like to know</div>
        <ul class="focus-list">
          <?php foreach ($f['focus'] as $q): ?><li><?= $h($q) ?></li><?php endforeach; ?>
        </ul>
      <?php endif; ?>

      <?php if (!empty($f['cases'])): ?>
        <div class="sub-label">Test cases (<?= count($f['cases']) ?>)</div>
        <?php foreach ($f['cases'] as $i => $c): $n = $i + 1; $cid = $key . '-' . $n; ?>
          <details class="case" data-case="<?= $h($cid) ?>">
            <summary>
              <span class="case-num"><?= $n ?></span>
              <span class="case-title"><?= $h($c['title']) ?></span>
              <svg class="case-chevron" width="12" height="8" viewBox="0 0 10 6" fill="none"><path d="M1 1l4 4 4-4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </summary>
            <div class="case-body">
              <ol>
                <?php foreach ($c['steps'] as $s): ?><li><?= $h($s) ?></li><?php endforeach; ?>
              </ol>
              <?php if (!empty($c['expect'])): ?>
                <div class="case-expect"><strong>What should happen:</strong> <?= $h($c['expect']) ?></div>
              <?php endif; ?>
              <label class="case-done"><input type="checkbox" class="case-check"> I've done this one</label>
            </div>
          </details>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>

  <?php if ($next): ?>
    <div class="section-label">Coming next</div>
    <?php foreach ($next as $f): ?>
      <div class="card">
        <div class="feat-head">
          <h2><?= $h($f['name']) ?></h2>
          <span class="tag tag-next">Coming soon</span>
        </div>
        <p class="feat-summary"><?= $h($f['summary']) ?></p>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>

</div>

<footer style="text-align:center; padding:2rem 1rem 1.5rem; color:var(--ink-4); font-size:0.78rem;">
  SOTA Planner &nbsp;·&nbsp;
  <a href="changelog.php" style="color:var(--ink-4); text-decoration:none;">v<?= APP_VERSION ?></a>
  &nbsp;·&nbsp;
  <a href="https://sotaplanner.com" style="color:var(--ink-4); text-decoration:none;">sotaplanner.com</a>
</footer>

<script>
document.addEventListener('click', function (e) {
  var chip = document.getElementById('userChip');
  if (chip && !chip.contains(e.target)) chip.classList.remove('open');
});

// "I've done this one" ticks are remembered in this browser only, for the tester's own tracking.
(function () {
  var KEY = 'sp_beta_done';
  var done = {};
  try { done = JSON.parse(localStorage.getItem(KEY) || '{}') || {}; } catch (e) { done = {}; }
  document.querySelectorAll('.case').forEach(function (el) {
    var id = el.getAttribute('data-case');
    var box = el.querySelector('.case-check');
    if (done[id]) { el.classList.add('done'); box.checked = true; }
    box.addEventListener('change', function () {
      el.classList.toggle('done', box.checked);
      if (box.checked) done[id] = 1; else delete done[id];
      try { localStorage.setItem(KEY, JSON.stringify(done)); } catch (e) {}
    });
  });
})();
</script>
</body>
</html>
