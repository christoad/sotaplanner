<?php
require_once 'config.php';
session_start();
requireLogin();

if (!isGodMode()) {
    http_response_code(403);
    die('Admin only.');
}

$db = getDbConnection();

// Safety: refuse on staging
$host = $_SERVER['HTTP_HOST'] ?? '';
if (str_contains($host, 'christopherreddick.com')) {
    http_response_code(403);
    die('<b>Error:</b> This tool cannot run on the staging server.');
}

// ── Actions ───────────────────────────────────────────────────────────────────

// Delete one library entry (removes DB row, physical file, and linked gpx_tracks rows)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    $id = (int)$_POST['delete_id'];
    $row = $db->prepare("SELECT * FROM global_gpx_tracks WHERE id = ?");
    $row->execute([$id]);
    $entry = $row->fetch();
    if ($entry) {
        // Remove linked per-group gpx_tracks rows
        $db->prepare("DELETE FROM gpx_tracks WHERE file_path = ? AND from_global_library = 1")
           ->execute([$entry['file_path']]);
        // Remove physical file
        if (file_exists($entry['file_path'])) {
            unlink($entry['file_path']);
        }
        // Remove global library row
        $db->prepare("DELETE FROM global_gpx_tracks WHERE id = ?")->execute([$id]);
        // Remove the per-assoc ref cache so the batch importer re-queues it
        $safe = preg_replace('/[^A-Za-z0-9]/', '', explode('/', $entry['sota_ref'])[0]);
        @unlink(__DIR__ . '/assoc_refs_cache_' . $safe . '.json');

        $flash = ['type' => 'success', 'msg' => "Deleted library entry for {$entry['sota_ref']}. It will re-appear in the importer queue."];
    } else {
        $flash = ['type' => 'error', 'msg' => 'Entry not found.'];
    }
    header('Location: admin_gpx_library.php' . (isset($_GET['assoc']) ? '?assoc=' . urlencode($_GET['assoc']) : ''));
    exit;
}

// ── Data ──────────────────────────────────────────────────────────────────────

$assoc_filter = preg_replace('/[^A-Za-z0-9]/', '', $_GET['assoc'] ?? '');
$search       = trim($_GET['q'] ?? '');

// Total library stats
$total_tracks = (int)$db->query("SELECT COUNT(*) FROM global_gpx_tracks")->fetchColumn();
$total_assocs = (int)$db->query("SELECT COUNT(DISTINCT SUBSTRING_INDEX(sota_ref,'/',1)) FROM global_gpx_tracks")->fetchColumn();

// Per-association breakdown (for dropdown + bar chart)
$assoc_rows = $db->query("
    SELECT SUBSTRING_INDEX(sota_ref,'/',1) as assoc, COUNT(*) as cnt
    FROM global_gpx_tracks
    GROUP BY assoc
    ORDER BY assoc
")->fetchAll();

// Filtered records
$where  = [];
$params = [];
if ($assoc_filter) {
    $where[]  = "sota_ref LIKE ?";
    $params[] = $assoc_filter . '/%';
}
if ($search) {
    $where[]  = "(sota_ref LIKE ? OR source_track_title LIKE ? OR source_callsign LIKE ?)";
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}
$sql = "SELECT * FROM global_gpx_tracks"
     . ($where ? " WHERE " . implode(" AND ", $where) : "")
     . " ORDER BY sota_ref LIMIT 500";
$st = $db->prepare($sql);
$st->execute($params);
$tracks = $st->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Global GPX Library — Admin</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;1,9..40,400&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
:root {
  --bg:#F7F6F3; --bg-2:#EFEDE8; --bg-3:#E5E2DA;
  --ink:#1C1B19; --ink-2:#4A4844; --ink-3:#8C8A86; --ink-4:#B8B5B0;
  --accent:oklch(52% 0.13 50); --accent-2:oklch(44% 0.13 50);
  --accent-bg:oklch(96% 0.04 65); --accent-border:oklch(84% 0.08 65);
  --green:oklch(52% 0.13 155); --green-bg:oklch(95% 0.04 155);
  --red:oklch(52% 0.16 22); --red-bg:oklch(96% 0.04 22);
  --blue:oklch(52% 0.12 240); --blue-bg:oklch(95% 0.04 240);
  --surface:#fff; --border:#E5E2DA; --border-2:#D4D0C8;
  --font-sans:'DM Sans',system-ui,sans-serif; --font-mono:'DM Mono','Courier New',monospace;
  --r-sm:4px; --r-md:8px; --r-lg:12px;
  --shadow-sm:0 1px 3px rgba(28,27,25,.07),0 1px 2px rgba(28,27,25,.05);
  --shadow-md:0 4px 12px rgba(28,27,25,.08),0 2px 4px rgba(28,27,25,.05);
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html{font-size:16px;-webkit-font-smoothing:antialiased}
body{font-family:var(--font-sans);background:var(--bg);color:var(--ink);line-height:1.5;min-height:100vh}
.page{padding:2rem;max-width:1200px;margin:0 auto}
h1{font-size:1.4rem;font-weight:600;margin-bottom:.2rem}
.subtitle{color:var(--ink-3);font-size:.875rem;margin-bottom:1.75rem}
.card{background:var(--surface);border:1px solid var(--border);border-radius:var(--r-lg);padding:1.5rem;margin-bottom:1.5rem;box-shadow:var(--shadow-sm)}
.stats-row{display:flex;gap:1.25rem;margin-bottom:1.5rem;flex-wrap:wrap}
.stat-box{background:var(--surface);border:1px solid var(--border);border-radius:var(--r-lg);padding:1rem 1.5rem;box-shadow:var(--shadow-sm);min-width:140px}
.stat-val{font-size:2rem;font-weight:600;line-height:1;margin-bottom:.15rem}
.stat-lbl{font-size:.72rem;text-transform:uppercase;letter-spacing:.06em;color:var(--ink-3)}
.green{color:var(--green)} .blue{color:var(--blue)} .accent{color:var(--accent)}
.toolbar{display:flex;align-items:center;gap:.75rem;flex-wrap:wrap;margin-bottom:1rem}
.form-input{padding:.45rem .7rem;border:1px solid var(--border);border-radius:var(--r-md);font-family:var(--font-sans);font-size:.875rem;color:var(--ink);background:var(--surface);outline:none;transition:border-color .15s}
.form-input:focus{border-color:var(--accent)}
select.form-input{cursor:pointer}
.btn{display:inline-flex;align-items:center;gap:.4rem;padding:0 .9rem;height:34px;border-radius:var(--r-md);font-family:var(--font-sans);font-size:.82rem;font-weight:500;cursor:pointer;border:none;text-decoration:none;white-space:nowrap}
.btn-ghost{background:transparent;color:var(--ink-2);border:1px solid var(--border)}
.btn-ghost:hover{background:var(--bg-2)}
.btn-danger{background:var(--red-bg);color:var(--red);border:1px solid oklch(85% 0.08 22)}
.btn-danger:hover{background:oklch(93% 0.06 22)}
.results-meta{font-size:.8rem;color:var(--ink-3);margin-bottom:.75rem}
table{width:100%;border-collapse:collapse}
thead th{font-size:.72rem;font-weight:600;text-transform:uppercase;letter-spacing:.06em;color:var(--ink-3);padding:.5rem .75rem;border-bottom:2px solid var(--border);text-align:left;white-space:nowrap;background:var(--bg)}
tbody tr{border-bottom:1px solid var(--border)}
tbody tr:hover{background:var(--bg)}
tbody td{padding:.55rem .75rem;font-size:.85rem;vertical-align:middle}
.ref{font-family:var(--font-mono);font-size:.8rem;font-weight:500;color:var(--ink)}
.callsign{font-family:var(--font-mono);font-size:.78rem;color:var(--ink-3)}
.track-title{color:var(--ink-2);font-size:.83rem;max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.num{font-family:var(--font-mono);font-size:.8rem;color:var(--ink-2);text-align:right}
.date-cell{font-size:.78rem;color:var(--ink-3);white-space:nowrap}
.assoc-badge{display:inline-block;background:var(--accent-bg);color:var(--accent);border:1px solid var(--accent-border);border-radius:var(--r-sm);font-size:.68rem;font-weight:600;padding:.1rem .45rem;font-family:var(--font-mono);letter-spacing:.02em}
.flash{padding:.75rem 1rem;border-radius:var(--r-md);margin-bottom:1rem;font-size:.875rem;font-weight:500}
.flash-success{background:var(--green-bg);color:var(--green);border:1px solid oklch(85% 0.07 155)}
.flash-error{background:var(--red-bg);color:var(--red);border:1px solid oklch(85% 0.08 22)}
.assoc-bar{display:flex;flex-wrap:wrap;gap:.4rem;margin-bottom:1.5rem}
.assoc-pill{display:inline-flex;align-items:center;gap:.35rem;height:26px;padding:0 .6rem;border-radius:100px;font-size:.75rem;font-weight:500;cursor:pointer;border:1px solid var(--border-2);background:var(--surface);color:var(--ink-2);text-decoration:none;transition:all .12s;font-family:var(--font-mono)}
.assoc-pill:hover{border-color:var(--accent-border);background:var(--accent-bg);color:var(--accent)}
.assoc-pill.active{background:var(--ink);border-color:var(--ink);color:#fff}
.assoc-pill .cnt{font-size:.68rem;opacity:.7}
.empty{text-align:center;padding:3rem 1rem;color:var(--ink-3);font-size:.9rem}
</style>
</head>
<body>
<div class="page">
  <p style="margin-bottom:.75rem"><a href="god_mode.php?tab=data" style="color:var(--ink-3);font-size:.875rem">&larr; Data Tools</a></p>
  <h1>Global GPX Library</h1>
  <p class="subtitle">Community tracks imported from <strong>SOTA Mapping Project</strong> — pre-populated for every user who nominates these summits.</p>

  <?php if (isset($_SESSION['gpx_lib_flash'])): $f = $_SESSION['gpx_lib_flash']; unset($_SESSION['gpx_lib_flash']); ?>
  <div class="flash flash-<?= $f['type'] ?>"><?= htmlspecialchars($f['msg']) ?></div>
  <?php endif; ?>

  <!-- Stats -->
  <div class="stats-row">
    <div class="stat-box">
      <div class="stat-val accent"><?= number_format($total_tracks) ?></div>
      <div class="stat-lbl">Tracks in library</div>
    </div>
    <div class="stat-box">
      <div class="stat-val blue"><?= $total_assocs ?></div>
      <div class="stat-lbl">Associations covered</div>
    </div>
    <?php if ($assoc_filter): ?>
    <div class="stat-box">
      <div class="stat-val green"><?= count($tracks) ?></div>
      <div class="stat-lbl">Tracks in <?= htmlspecialchars($assoc_filter) ?></div>
    </div>
    <?php endif; ?>
  </div>

  <!-- Association filter pills -->
  <?php if ($assoc_rows): ?>
  <div class="assoc-bar">
    <a href="admin_gpx_library.php" class="assoc-pill <?= !$assoc_filter ? 'active' : '' ?>">All</a>
    <?php foreach ($assoc_rows as $ar): ?>
    <a href="?assoc=<?= urlencode($ar['assoc']) ?>"
       class="assoc-pill <?= $assoc_filter === $ar['assoc'] ? 'active' : '' ?>">
      <?= htmlspecialchars($ar['assoc']) ?>
      <span class="cnt"><?= $ar['cnt'] ?></span>
    </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- Search + table -->
  <div class="card" style="padding:0">
    <div style="padding:.75rem 1.25rem;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:.75rem;flex-wrap:wrap">
      <form method="get" style="display:flex;gap:.5rem;align-items:center;flex:1">
        <?php if ($assoc_filter): ?>
          <input type="hidden" name="assoc" value="<?= htmlspecialchars($assoc_filter) ?>">
        <?php endif; ?>
        <input type="search" name="q" class="form-input" placeholder="Search ref, title, callsign…"
               value="<?= htmlspecialchars($search) ?>" style="width:240px">
        <button type="submit" class="btn btn-ghost">Search</button>
        <?php if ($search): ?>
          <a href="admin_gpx_library.php<?= $assoc_filter ? '?assoc=' . urlencode($assoc_filter) : '' ?>" class="btn btn-ghost">Clear</a>
        <?php endif; ?>
      </form>
      <span style="font-size:.8rem;color:var(--ink-3)">
        <?= number_format(count($tracks)) ?> result<?= count($tracks) !== 1 ? 's' : '' ?>
        <?= count($tracks) === 500 ? ' (limit 500)' : '' ?>
      </span>
    </div>

    <?php if (empty($tracks)): ?>
      <div class="empty">
        <?php if ($total_tracks === 0): ?>
          No tracks imported yet. Run the <a href="admin_batch_gpx.php">Batch GPX Importer</a> to populate the library.
        <?php else: ?>
          No tracks match your filter.
        <?php endif; ?>
      </div>
    <?php else: ?>
    <div style="overflow-x:auto">
    <table>
      <thead>
        <tr>
          <th>SOTA Ref</th>
          <th>Track Title</th>
          <th>Submitted By</th>
          <th style="text-align:right">Dist (km)</th>
          <th style="text-align:right">Gain (m)</th>
          <th style="text-align:right">Points</th>
          <th>Imported</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($tracks as $t):
          $assoc = explode('/', $t['sota_ref'])[0];
          $dist_km = $t['total_distance'] ? round($t['total_distance'], 2) : null;
          $gain_m  = $t['elevation_gain'] ? round($t['elevation_gain']) : null;
        ?>
        <tr>
          <td>
            <span class="assoc-badge"><?= htmlspecialchars($assoc) ?></span>
            <span class="ref" style="margin-left:.4rem"><?= htmlspecialchars($t['sota_ref']) ?></span>
          </td>
          <td>
            <span class="track-title" title="<?= htmlspecialchars($t['source_track_title'] ?? '') ?>">
              <?= htmlspecialchars($t['source_track_title'] ?: '—') ?>
            </span>
          </td>
          <td><span class="callsign"><?= htmlspecialchars($t['source_callsign'] ?: '—') ?></span></td>
          <td class="num"><?= $dist_km !== null ? $dist_km : '—' ?></td>
          <td class="num"><?= $gain_m !== null ? number_format($gain_m) : '—' ?></td>
          <td class="num"><?= $t['num_points'] ? number_format($t['num_points']) : '—' ?></td>
          <td class="date-cell"><?= $t['imported_at'] ? date('M j, Y', strtotime($t['imported_at'])) : '—' ?></td>
          <td>
            <form method="post" onsubmit="return confirm('Remove <?= htmlspecialchars($t['sota_ref']) ?> from the library?\nThis also deletes the GPX file and re-queues it for the importer.')">
              <input type="hidden" name="delete_id" value="<?= $t['id'] ?>">
              <?php if ($assoc_filter): ?><input type="hidden" name="assoc" value="<?= htmlspecialchars($assoc_filter) ?>"><?php endif; ?>
              <button type="submit" class="btn btn-danger" style="height:28px;font-size:.72rem">Remove</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  </div>

</div>
</body>
</html>
