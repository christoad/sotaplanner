<?php
/**
 * God Mode: publish a trail discovery run, so its summits get the "Possible routes" panel on the summit page.
 * Nothing becomes public: the panel is God Mode only (Discovery mode) until the launch switch
 * 'discovered_routes_public' is turned on at Four Days in May 2027. This page only shows the switch, never changes it.
 *
 * Browser: discovered_publish.php (God Mode). CLI, for big runs: php discovered_publish.php --run=N
 */
require_once 'config.php';
require_once 'discovered_routes_lib.php';

if (PHP_SAPI === 'cli') {
    $opt = getopt('', ['run:']);
    $run = (int)($opt['run'] ?? 0);
    if ($run <= 0) { fwrite(STDERR, "Usage: php discovered_publish.php --run=N\n"); exit(1); }
    $db = getDbConnection();
    $r = dr_publish_run($db, $run, 'cli', function ($i, $n, $ref) { if ($i % 100 === 0 || $i === $n) echo "$i / $n  $ref\n"; });
    echo json_encode($r, JSON_PRETTY_PRINT) . "\n";
    exit(empty($r['errors']) ? 0 : 2);
}

session_start();
requireLogin();
if (!isGodMode()) { header('Location: index.php'); exit; }
$db = getDbConnection();
@set_time_limit(600);

$result = null; $resultRun = null; $error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['publish_run'])) {
    $resultRun = (int)$_POST['publish_run'];
    try { $result = dr_publish_run($db, $resultRun, getCurrentCallsign()); }
    catch (Throwable $e) { $error = $e->getMessage(); }
}
session_write_close();

$tablesOk = true;
try { $db->query("SELECT 1 FROM discovered_summits LIMIT 1"); } catch (Throwable $e) { $tablesOk = false; }

$runs = $db->query("SELECT r.id, r.note, r.settings_json, r.created_at,
                           (SELECT COUNT(*) FROM trail_disc_results x WHERE x.run_id = r.id AND x.status = 'ok') AS n_ok
                    FROM trail_disc_runs r ORDER BY r.id DESC")->fetchAll();
$pubCounts = [];
if ($tablesOk) {
    foreach ($db->query("SELECT run_id, COUNT(*) n, SUM(status = 'found') f FROM discovered_summits GROUP BY run_id") as $r) $pubCounts[(int)$r['run_id']] = $r;
    $totS = (int)$db->query("SELECT COUNT(*) FROM discovered_summits")->fetchColumn();
    $totR = (int)$db->query("SELECT COUNT(*) FROM discovered_routes")->fetchColumn();
    $votes = $db->query("SELECT verdict, COUNT(*) n FROM discovered_route_votes GROUP BY verdict")->fetchAll(PDO::FETCH_KEY_PAIR);
    $sample = $db->query("SELECT ds.sota_ref, ds.run_id, ds.band, ds.summit_conf, ds.n_routes,
                                 (SELECT CONCAT(s.id, ':', s.planning_group_id) FROM summits s LEFT JOIN planning_groups pg ON pg.id = s.planning_group_id
                                  WHERE s.sota_ref = ds.sota_ref ORDER BY (pg.owner_callsign = " . $db->quote(getCurrentCallsign()) . ") DESC, s.id LIMIT 1) AS any_summit
                          FROM discovered_summits ds ORDER BY ds.published_at DESC, ds.sota_ref LIMIT 400")->fetchAll();
}
$public = dr_public();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Publish discovered routes | SOTAplanner</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;1,9..40,400&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
:root {
  --bg:#F7F6F3; --bg-2:#EFEDE8; --bg-3:#E5E2DA; --ink:#1C1B19; --ink-2:#4A4844; --ink-3:#8C8A86; --ink-4:#B8B5B0;
  --accent:oklch(52% 0.13 50); --accent-bg:oklch(96% 0.04 65); --accent-border:oklch(84% 0.08 65);
  --green:oklch(52% 0.13 155); --green-bg:oklch(95% 0.04 155); --orange:oklch(62% 0.14 58); --orange-bg:oklch(96% 0.05 58);
  --red:oklch(52% 0.16 22); --red-bg:oklch(96% 0.04 22); --blue:oklch(52% 0.12 240); --blue-bg:oklch(95% 0.04 240);
  --surface:#FFFFFF; --border:#E5E2DA; --border-2:#D4D0C8;
  --font-sans:'DM Sans', system-ui, sans-serif; --font-mono:'DM Mono', 'Courier New', monospace;
  --r-sm:4px; --r-md:8px; --r-lg:12px; --shadow-sm:0 1px 3px rgba(28,27,25,0.07), 0 1px 2px rgba(28,27,25,0.05);
}
*, *::before, *::after { box-sizing:border-box; margin:0; padding:0; }
html { font-size:16px; -webkit-font-smoothing:antialiased; }
body { font-family:var(--font-sans); background:var(--bg); color:var(--ink); line-height:1.5; min-height:100vh; }
a { color:var(--accent); }
.topbar { background:var(--surface); border-bottom:1px solid var(--border); height:56px; display:flex; align-items:center; padding:0 2rem; gap:1rem; position:sticky; top:0; z-index:100; }
.topbar-logo { display:flex; align-items:center; gap:0.75rem; text-decoration:none; color:var(--ink); font-weight:600; font-size:0.95rem; }
.topbar-nav a { color:var(--ink-3); font-size:0.875rem; font-weight:500; padding:0.5rem 0.75rem; border-radius:var(--r-sm); text-decoration:none; }
.topbar-nav a:hover { color:var(--ink); background:var(--bg-2); }
.page { padding:2rem; max-width:1100px; margin:0 auto; }
h1 { font-size:1.5rem; font-weight:600; letter-spacing:-0.01em; margin-bottom:0.35rem; }
.lead { color:var(--ink-2); font-size:0.92rem; margin-bottom:1.25rem; max-width:760px; }
.card { background:var(--surface); border:1px solid var(--border); border-radius:var(--r-lg); padding:1.25rem 1.5rem; box-shadow:var(--shadow-sm); margin-bottom:1rem; }
.card h2 { font-size:1rem; font-weight:600; margin-bottom:0.75rem; }
.msg { padding:0.75rem 1rem; border-radius:var(--r-md); font-size:0.875rem; font-weight:500; margin-bottom:1rem; }
.msg-success { background:var(--green-bg); color:var(--green); border:1px solid oklch(85% 0.07 155); }
.msg-error { background:var(--red-bg); color:var(--red); border:1px solid oklch(85% 0.08 22); }
.msg-info { background:var(--accent-bg); color:oklch(44% 0.13 50); border:1px solid var(--accent-border); }
table { width:100%; border-collapse:collapse; font-size:0.85rem; }
th { text-align:left; font-size:0.7rem; text-transform:uppercase; letter-spacing:0.06em; color:var(--ink-3); font-weight:600; padding:0.4rem 0.5rem; border-bottom:1px solid var(--border); }
td { padding:0.45rem 0.5rem; border-bottom:1px solid var(--bg-2); vertical-align:middle; }
.mono { font-family:var(--font-mono); font-size:0.8rem; }
.btn { display:inline-flex; align-items:center; justify-content:center; padding:0 0.75rem; height:30px; border-radius:var(--r-md); font-family:var(--font-sans); font-size:0.8rem; font-weight:500; cursor:pointer; border:none; background:var(--ink); color:#fff; }
.btn:hover { background:var(--ink-2); }
.btn-ghost { background:transparent; color:var(--ink-2); border:1px solid var(--border); }
.btn-ghost:hover { background:var(--bg-2); color:var(--ink); }
.tag { display:inline-block; font-size:0.65rem; font-weight:600; border-radius:var(--r-sm); padding:0.1rem 0.4rem; text-transform:uppercase; letter-spacing:0.05em; }
.tag-high { background:var(--green-bg); color:var(--green); } .tag-medium { background:var(--orange-bg); color:oklch(48% 0.13 58); }
.tag-low { background:var(--red-bg); color:var(--red); } .tag-off { background:var(--bg-2); color:var(--ink-2); }
.stats { display:flex; gap:2rem; flex-wrap:wrap; font-size:0.9rem; }
.stats b { font-size:1.2rem; display:block; }
.muted { color:var(--ink-3); }
.scroll { max-height:420px; overflow:auto; }
@media (max-width:640px) { .page { padding:1rem; } .topbar { padding:0 1rem; } }
</style>
</head>
<body>
<nav class="topbar">
  <a href="index.php" class="topbar-logo"><img src="sota-planner-logo.svg" width="32" height="32" alt=""><span>SOTAplanner</span></a>
  <div class="topbar-nav"><a href="god_mode.php">God Mode</a><a href="trail_discovery.php">Trail Discovery</a></div>
</nav>
<div class="page">
  <h1>Publish discovered routes</h1>
  <p class="lead">Publishing a trail discovery run gives its summits the "Possible routes" panel on the summit page (map, top 3 routes, votes, Use this route).
  A later publish of the same summit replaces it; the run files stay as history.</p>

  <div class="msg msg-info">
    Launch switch <span class="mono">discovered_routes_public</span>: <strong><?= $public ? 'ON (everyone sees discovered routes)' : 'Off' ?></strong>.
    <?php if (!$public): ?>Only God Mode sees the panel, and only while "Discovery mode" is on (user menu on a summit page). It is turned on at Four Days in May 2027.<?php endif; ?>
  </div>

  <?php if (!$tablesOk): ?>
    <div class="msg msg-error">The discovered routes tables do not exist yet. Run db_migrate_discovered.php first.</div>
  <?php endif; ?>
  <?php if ($error): ?><div class="msg msg-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
  <?php if ($result): ?>
    <div class="msg <?= $result['errors'] ? 'msg-error' : 'msg-success' ?>">
      Run <?= $resultRun ?>: <?= $result['published'] ?> summits published (<?= $result['routes'] ?> routes<?= $result['replaced'] ? ', ' . $result['replaced'] . ' replaced an earlier publish' : '' ?><?= $result['none'] ? ', ' . $result['none'] . ' with no route found' : '' ?>)<?= $result['skipped'] ? ', ' . $result['skipped'] . ' skipped (unreadable file)' : '' ?>.
      <?php foreach (array_slice($result['errors'], 0, 10) as $e): ?><br><?= htmlspecialchars($e) ?><?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if ($tablesOk): ?>
  <div class="card">
    <h2>Published so far</h2>
    <div class="stats">
      <div><b><?= number_format($totS) ?></b><span class="muted">summits</span></div>
      <div><b><?= number_format($totR) ?></b><span class="muted">routes</span></div>
      <div><b><?= (int)($votes['viable'] ?? 0) ?> / <?= (int)($votes['not_viable'] ?? 0) ?></b><span class="muted">viable / not viable votes</span></div>
      <div><b><?= (int)($votes['used'] ?? 0) ?></b><span class="muted">routes used</span></div>
      <div><b><?= (int)($votes['confirmed'] ?? 0) ?></b><span class="muted">confirmed</span></div>
    </div>
  </div>
  <?php endif; ?>

  <div class="card">
    <h2>Trail discovery runs</h2>
    <div class="scroll">
    <table>
      <tr><th>Run</th><th>Region</th><th>Settings</th><th>Summits</th><th>Published</th><th></th></tr>
      <?php foreach ($runs as $r):
        $set = json_decode($r['settings_json'] ?? '', true) ?: [];
        $pc = $pubCounts[(int)$r['id']] ?? null; ?>
      <tr>
        <td class="mono"><?= (int)$r['id'] ?></td>
        <td class="mono"><?= htmlspecialchars($set['region'] ?? '') ?></td>
        <td class="muted"><?= htmlspecialchars(($set['source'] ?? 'probes') . ', ' . ($set['pick'] ?? 'paved_first') . ', ' . ($set['max_km'] ?? '?') . ' km') ?><?= $r['note'] ? '<br>' . htmlspecialchars($r['note']) : '' ?></td>
        <td><?= (int)$r['n_ok'] ?></td>
        <td><?= $pc ? (int)$pc['n'] . ' (' . (int)$pc['f'] . ' with routes)' : '<span class="muted">none</span>' ?></td>
        <td>
          <?php if ($tablesOk && $r['n_ok'] > 0): ?>
          <form method="post" style="display:inline" onsubmit="this.querySelector('button').disabled=true; this.querySelector('button').textContent='Publishing...';">
            <input type="hidden" name="publish_run" value="<?= (int)$r['id'] ?>">
            <button class="btn <?= $pc ? 'btn-ghost' : '' ?>"><?= $pc ? 'Publish again' : 'Publish run ' . (int)$r['id'] ?></button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </table>
    </div>
  </div>

  <?php if ($tablesOk && $sample): ?>
  <div class="card">
    <h2>Published summits</h2>
    <p class="muted" style="font-size:0.82rem; margin-bottom:0.6rem;">The panel shows on a summit page only when the summit is on one of your dashboards. "Open" goes to a dashboard copy of it if there is one.</p>
    <div class="scroll">
    <table>
      <tr><th>Summit</th><th>Run</th><th>Confidence</th><th>Routes</th><th></th></tr>
      <?php foreach ($sample as $s): ?>
      <tr>
        <td class="mono"><?= htmlspecialchars($s['sota_ref']) ?></td>
        <td class="mono"><?= (int)$s['run_id'] ?></td>
        <td><?php if ($s['band']): ?><span class="tag tag-<?= htmlspecialchars($s['band']) ?>"><?= htmlspecialchars($s['band']) ?></span> <?= (int)$s['summit_conf'] ?>%<?php else: ?><span class="tag tag-off">none</span><?php endif; ?></td>
        <td><?= (int)$s['n_routes'] ?></td>
        <td><?php if ($s['any_summit']): [$sid, $sgid] = array_map('intval', explode(':', $s['any_summit'])); ?><a href="summit_detail.php?id=<?= $sid ?>&amp;group=<?= $sgid ?>">Open</a><?php else: ?><span class="muted">not on a dashboard</span><?php endif; ?></td>
      </tr>
      <?php endforeach; ?>
    </table>
    </div>
  </div>
  <?php endif; ?>
</div>
</body>
</html>
