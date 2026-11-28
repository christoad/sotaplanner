<?php
require_once 'config.php';
session_start();
requireLogin();

$db = getDbConnection();
$current_user = getCurrentCallsign();

// Handle group parameter from URL (for shareable links)
if (isset($_GET['group'])) {
    $group_id = (int)$_GET['group'];
    // Validate group exists
    $stmt = $db->prepare("SELECT id FROM planning_groups WHERE id = ?");
    $stmt->execute([$group_id]);
    if ($stmt->fetch()) {
        setCurrentPlanningGroup($group_id);
    }
}

if (!isset($_GET['id'])) {
    header('Location: index.php');
    exit;
}

$summit_id = (int)$_GET['id'];

// Fetch summit data
$stmt = $db->prepare("SELECT * FROM summits WHERE id = ?");
$stmt->execute([$summit_id]);
$summit = $stmt->fetch();

if (!$summit || !userCanAccessSummit($db, $summit)) {
    header('Location: index.php');
    exit;
}

// Get current planning group
$current_group = getCurrentPlanningGroup($db);
if (!$current_group) {
    header('Location: index.php');
    exit;
}

// Handle manual data entry
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_trail_data'])) {
    // Build SET clause dynamically so optional fields only update when present
    $sets   = ['trail_link = ?', 'hike_distance_mi = ?', 'hike_elevation_gain_ft = ?', "status = 'researched'"];
    $params = [
        cleanUserUrl($_POST['trail_link'] ?? ''),
        $_POST['hike_distance_mi'] ?: null,
        $_POST['hike_elevation_gain_ft'] ?: null,
    ];
    if (!empty($_POST['difficulty'])) {
        $sets[]   = 'difficulty = ?';
        $params[] = $_POST['difficulty'];
    }
    $params[] = $summit_id;

    $stmt = $db->prepare("UPDATE summits SET " . implode(', ', $sets) . " WHERE id = ?");
    $stmt->execute($params);
    
    // Calculate hike times if data provided
    if (!empty($_POST['hike_distance_mi']) && !empty($_POST['hike_elevation_gain_ft'])) {
        $distance = (float)$_POST['hike_distance_mi'];
        $elevation = (int)$_POST['hike_elevation_gain_ft'];
        
        $pace = $current_group['pace_multiplier'] ?? 1.0;
        $time_up = calculateHikeTime($distance / 2, $elevation, $pace);
        $time_down = calculateHikeTime($distance / 2, 0, $pace);
        
        $stmt = $db->prepare("
            UPDATE summits SET
                hike_time_up_min = ?,
                hike_time_down_min = ?
            WHERE id = ?
        ");
        $stmt->execute([$time_up, $time_down, $summit_id]);
    }
    
    header('Location: summit_detail.php?id=' . $summit_id . '&group=' . $current_group['id']);
    exit;
}

$summit_name = $summit['name'];
$summit_ref = $summit['sota_ref'];
$summit_lat = $summit['latitude'];
$summit_lng = $summit['longitude'];

// Create intelligent search URLs with pre-populated searches
// AllTrails explore with map centered on coordinates
$alltrails_search = "https://www.alltrails.com/explore?b_tl_lat=" . ($summit_lat + 0.05) . 
                    "&b_tl_lng=" . ($summit_lng - 0.05) . 
                    "&b_br_lat=" . ($summit_lat - 0.05) . 
                    "&b_br_lng=" . ($summit_lng + 0.05);

$gaia_gps_search = "https://www.gaiagps.com/map/?loc=15/" . $summit_lat . "/" . $summit_lng . 
                   "&layer=GaiaTopoRasterFeet";

$google_maps = "https://www.google.com/maps/search/" . urlencode($summit_name . " hiking trail") . 
               "/@" . $summit_lat . "," . $summit_lng . ",14z";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Find Trail for <?= htmlspecialchars($summit_name) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;1,9..40,400&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
    <style>
    :root {
      --bg: #F7F6F3; --bg-2: #EFEDE8; --bg-3: #E5E2DA;
      --ink: #1C1B19; --ink-2: #4A4844; --ink-3: #8C8A86; --ink-4: #B8B5B0;
      --accent: oklch(52% 0.13 50); --accent-2: oklch(44% 0.13 50);
      --accent-bg: oklch(96% 0.04 65); --accent-border: oklch(84% 0.08 65);
      --green: oklch(52% 0.13 155); --green-bg: oklch(95% 0.04 155);
      --orange: oklch(62% 0.14 58); --orange-bg: oklch(96% 0.05 58);
      --red: oklch(52% 0.16 22); --red-bg: oklch(96% 0.04 22);
      --blue: oklch(52% 0.12 240); --blue-bg: oklch(95% 0.04 240);
      --surface: #FFFFFF; --border: #E5E2DA; --border-2: #D4D0C8;
      --font-sans: 'DM Sans', system-ui, sans-serif;
      --font-mono: 'DM Mono', 'Courier New', monospace;
      --r-sm: 4px; --r-md: 8px; --r-lg: 12px;
      --shadow-sm: 0 1px 3px rgba(28,27,25,0.07), 0 1px 2px rgba(28,27,25,0.05);
      --shadow-md: 0 4px 12px rgba(28,27,25,0.08), 0 2px 4px rgba(28,27,25,0.05);
    }
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    html { font-size: 16px; -webkit-font-smoothing: antialiased; }
    body { font-family: var(--font-sans); background: var(--bg); color: var(--ink); line-height: 1.5; min-height: 100vh; }

    /* Topbar */
    .topbar { background: var(--surface); border-bottom: 1px solid var(--border); height: 56px; display: flex; align-items: center; padding: 0 1.5rem; gap: 1rem; position: sticky; top: 0; z-index: 100; }
    .topbar-logo { display: flex; align-items: center; gap: 0.75rem; text-decoration: none; color: var(--ink); font-weight: 600; font-size: 0.95rem; letter-spacing: -0.01em; flex-shrink: 0; }
    .topbar-logo:hover { color: var(--ink); }
    .topbar-divider { width: 1px; height: 20px; background: var(--border); flex-shrink: 0; }
    .topbar-nav { display: flex; align-items: center; gap: 0.25rem; }
    .topbar-nav a { color: var(--ink-3); font-size: 0.875rem; font-weight: 500; padding: 0.5rem 0.75rem; border-radius: var(--r-sm); transition: color 0.15s, background 0.15s; text-decoration: none; white-space: nowrap; }
    .topbar-nav a:hover { color: var(--ink); background: var(--bg-2); }
    .topbar-right { display: flex; align-items: center; gap: 0.75rem; margin-left: auto; flex-shrink: 0; }
    .user-chip { position: relative; display: flex; align-items: center; gap: 0.35rem; cursor: pointer; padding: 0.25rem 0.6rem; border-radius: var(--r-sm); font-size: 0.8rem; font-weight: 600; color: var(--ink-2); border: 1px solid var(--border); background: var(--bg); user-select: none; white-space: nowrap; }
    .user-chip:hover { background: var(--bg-2); }
    .user-chip-chevron { transition: transform 0.15s; }
    .user-chip.open .user-chip-chevron { transform: rotate(180deg); }
    .user-dropdown { display: none; position: absolute; top: calc(100% + 6px); right: 0; background: #fff; border: 1px solid var(--border); border-radius: var(--r-sm); box-shadow: 0 4px 16px rgba(0,0,0,0.10); min-width: 130px; overflow: hidden; z-index: 200; }
    .user-chip.open .user-dropdown { display: block; }
    .user-dropdown a { display: block; padding: 0.6rem 1rem; font-size: 0.82rem; font-weight: 500; color: var(--ink-2); text-decoration: none; }
    .user-dropdown a:hover { background: var(--bg-2); color: var(--ink); }

    /* Page */
    .page { padding: 1.5rem; max-width: 760px; margin: 0 auto; }
    .page-header { margin-bottom: 1.5rem; }
    .page-header h1 { font-size: 1.35rem; font-weight: 600; letter-spacing: -0.02em; margin-bottom: 0.15rem; }
    .page-header .sub { font-size: 0.82rem; color: var(--ink-3); font-family: var(--font-mono); }

    /* Cards */
    .card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--r-lg); padding: 1.25rem; box-shadow: var(--shadow-sm); margin-bottom: 1rem; }
    .card-title { font-size: 0.7rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; color: var(--ink-3); margin-bottom: 0.85rem; }

    /* Info box */
    .info-box { background: var(--accent-bg); border: 1px solid var(--accent-border); border-radius: var(--r-md); padding: 1rem; margin-bottom: 1rem; }
    .info-box-title { font-size: 0.8rem; font-weight: 700; color: var(--accent-2); text-transform: uppercase; letter-spacing: 0.06em; margin-bottom: 0.5rem; }
    .info-box ol { margin-left: 1.25rem; }
    .info-box li { font-size: 0.85rem; color: var(--ink-2); line-height: 1.7; }

    /* Search links */
    .search-links { display: grid; grid-template-columns: repeat(2, 1fr); gap: 0.6rem; margin-bottom: 0; }
    .search-link { display: flex; flex-direction: column; gap: 0.2rem; padding: 0.85rem 1rem; background: var(--bg); border: 1px solid var(--border-2); border-radius: var(--r-md); text-decoration: none; color: var(--ink); transition: border-color 0.15s, background 0.15s; }
    .search-link:hover { border-color: var(--accent-border); background: var(--accent-bg); }
    .search-link-title { font-size: 0.875rem; font-weight: 600; color: var(--ink); }
    .search-link-desc { font-size: 0.75rem; color: var(--ink-3); }

    /* Forms */
    .form-group { margin-bottom: 1rem; }
    .form-label { display: block; font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; color: var(--ink-3); margin-bottom: 0.35rem; }
    .form-input { width: 100%; padding: 0.6rem 0.75rem; border: 1px solid var(--border); border-radius: var(--r-md); font-family: var(--font-sans); font-size: 0.875rem; color: var(--ink); background: var(--surface); transition: border-color 0.15s; outline: none; }
    .form-input:focus { border-color: var(--accent); }
    select.form-input { cursor: pointer; }
    .form-hint { font-size: 0.76rem; color: var(--ink-3); margin-top: 0.3rem; line-height: 1.4; }
    .grid-2col { display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem; }

    /* Buttons */
    .btn { display: inline-flex; align-items: center; justify-content: center; gap: 0.5rem; padding: 0 1rem; height: 36px; border-radius: var(--r-md); font-family: var(--font-sans); font-size: 0.875rem; font-weight: 500; cursor: pointer; border: none; transition: background 0.15s; text-decoration: none; white-space: nowrap; line-height: 1; }
    .btn-primary { background: var(--ink); color: #fff; }
    .btn-primary:hover { background: var(--ink-2); color: #fff; }
    .btn-ghost { background: transparent; color: var(--ink-2); border: 1px solid var(--border); }
    .btn-ghost:hover { background: var(--bg-2); color: var(--ink); }
    .btn-row { display: flex; gap: 0.6rem; flex-wrap: wrap; margin-top: 1rem; }

    /* Alert messages */
    .msg { padding: 0.75rem 1rem; border-radius: var(--r-md); font-size: 0.85rem; margin-bottom: 1rem; }
    .msg-warn { background: oklch(97% 0.04 80); border: 1px solid oklch(88% 0.09 80); color: oklch(42% 0.12 60); }
    .msg-ok { background: var(--green-bg); border: 1px solid oklch(85% 0.07 155); color: var(--green); }

    /* Tips */
    .tips-card { background: var(--accent-bg); border: 1px solid var(--accent-border); border-radius: var(--r-lg); padding: 1.25rem; margin-bottom: 1rem; }
    .tips-card ul { margin-left: 1.1rem; }
    .tips-card li { font-size: 0.85rem; color: var(--ink-2); line-height: 1.7; }

    @media (max-width: 600px) {
      .search-links { grid-template-columns: 1fr 1fr; }
      .grid-2col { grid-template-columns: 1fr; }
    }
    </style>
</head>
<body>

<nav class="topbar">
  <a href="index.php" class="topbar-logo">
    <span style="width:32px;height:32px;flex-shrink:0;display:flex;align-items:center;justify-content:center;">
      <img src="sota-planner-logo.svg" width="32" height="32" alt="">
    </span>
    <span>SOTAplanner</span>
  </a>
  <div class="topbar-divider"></div>
  <div class="topbar-nav">
    <a href="summit_detail.php?id=<?= $summit_id ?>&group=<?= $current_group['id'] ?>">← <?= htmlspecialchars($summit_name) ?></a>
  </div>
  <div class="topbar-right">
    <div class="user-chip" onclick="this.classList.toggle('open')" id="userChip">
      <?= userChipName($current_user) ?>
      <svg class="user-chip-chevron" width="10" height="6" viewBox="0 0 10 6" fill="none">
        <path d="M1 1l4 4 4-4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
      </svg>
      <div class="user-dropdown">
        <?php if (($current_user ?? '') === 'KI6CR' || !empty($_SESSION['_admin_mode_real_callsign'])): ?>
          <a href="admin_mode.php">Admin Mode</a>
        <?php endif; ?>
        <?= betaMenuLink() ?>
        <a href="user_settings.php">Settings</a>
        <a href="logout.php">Sign Out</a>
      </div>
    </div>
  </div>
</nav>

    <div class="page">

      <!-- Page header -->
      <div class="page-header">
        <h1>Find Trail for <?= htmlspecialchars($summit_name) ?></h1>
        <div class="sub"><?= htmlspecialchars($summit_ref) ?> &middot; <?= number_format($summit_lat, 6) ?>, <?= number_format($summit_lng, 6) ?></div>
      </div>

      <!-- How-to info box -->
      <div class="info-box">
        <div class="info-box-title">How to find the right trail</div>
        <ol>
          <li>Search the links below for trails near the summit coordinates</li>
          <li>Look for trails that lead TO or NEAR the summit (<?= number_format($summit['elevation_ft']) ?> ft elevation)</li>
          <li>Once you find the right trail, paste its link and stats into the form below</li>
          <li>Review and save</li>
        </ol>
      </div>

      <!-- Search links -->
      <div class="card">
        <div class="card-title">Search Trail Resources</div>
        <div class="search-links">
          <a href="<?= $alltrails_search ?>" target="_blank" class="search-link">
            <div class="search-link-title">AllTrails</div>
            <div class="search-link-desc">Trails near summit</div>
          </a>
          <a href="<?= $google_maps ?>" target="_blank" class="search-link">
            <div class="search-link-title">Google Maps</div>
            <div class="search-link-desc">Hiking trails in the area</div>
          </a>
          <a href="<?= $gaia_gps_search ?>" target="_blank" class="search-link">
            <div class="search-link-title">GAIA GPS</div>
            <div class="search-link-desc">Topographic map view</div>
          </a>
          <a href="<?= htmlspecialchars($summit['sotlas_link'] ?? '') ?>" target="_blank" class="search-link">
            <div class="search-link-title">SOTLas</div>
            <div class="search-link-desc">SOTA mapping tool</div>
          </a>
        </div>
      </div>

      <!-- Trail data entry -->
      <div class="card">
        <div class="card-title">Enter Trail Information</div>
        <p style="font-size:0.85rem; color:var(--ink-3); margin-bottom:1rem;">Paste the trail link and enter its stats.</p>

        <form method="POST">
          <div class="form-group">
            <label class="form-label" for="trail_link">Trail Link</label>
            <input type="text" id="trail_link" name="trail_link" class="form-input"
                   value="<?= htmlspecialchars($summit['trail_link'] ?? '') ?>"
                   placeholder="https://www.alltrails.com/trail/...">
          </div>

          <div class="grid-2col">
            <div class="form-group">
              <label class="form-label" for="hike_distance_mi">
                Round-Trip Distance (mi)
              </label>
              <input type="number" id="hike_distance_mi" name="hike_distance_mi" class="form-input"
                     step="0.01" placeholder="e.g. 1.4"
                     value="<?= htmlspecialchars($summit['hike_distance_mi'] ?? '') ?>" required>
            </div>
            <div class="form-group">
              <label class="form-label" for="hike_elevation_gain_ft">
                Elevation Gain (ft)
              </label>
              <input type="number" id="hike_elevation_gain_ft" name="hike_elevation_gain_ft" class="form-input"
                     placeholder="e.g. 300"
                     value="<?= htmlspecialchars($summit['hike_elevation_gain_ft'] ?? '') ?>" required>
            </div>
          </div>

          <div class="form-group">
            <label class="form-label" for="difficulty">
              Difficulty
            </label>
            <select id="difficulty" name="difficulty" class="form-input">
              <option value="">Select</option>
              <?php foreach (['drive-up'=>'Drive-Up','easy'=>'Easy','moderate'=>'Moderate','hard'=>'Hard / Strenuous'] as $val=>$lbl): ?>
                <option value="<?= $val ?>" <?= ($summit['difficulty'] ?? '') === $val ? 'selected' : '' ?>><?= $lbl ?></option>
              <?php endforeach; ?>
            </select>
          </div>


          <div class="btn-row">
            <button type="submit" name="save_trail_data" class="btn btn-primary">Save Trail Data</button>
            <a href="summit_detail.php?id=<?= $summit_id ?>&group=<?= $current_group['id'] ?>" class="btn btn-ghost">Skip for Now</a>
          </div>
        </form>
      </div>

      <!-- Tips -->
      <div class="tips-card">
        <div class="card-title">Tips for Finding the Right Trail</div>
        <ul>
          <li><strong>AllTrails:</strong> Use the map view and zoom to the summit coordinates. Look for trails that go to or through the summit.</li>
          <li><strong>Check Elevation:</strong> The trail's highest point should match the summit elevation (<?= number_format($summit['elevation_ft']) ?> ft).</li>
          <li><strong>Read Reviews:</strong> Trail reviews often mention if the trail reaches the summit.</li>
          <li><strong>Multiple Routes:</strong> Some summits have several trails. Pick the most direct or one that matches your skill level.</li>
          <li><strong>Trail Names:</strong> The trail might not be named after the summit. Look for trails in the same area.</li>
        </ul>
      </div>

    </div>

<footer style="text-align:center; padding:1.5rem 1rem; color:var(--ink-4); font-size:0.78rem;">
  SOTA Planner &nbsp;·&nbsp;
  <a href="changelog.php" style="color:var(--ink-4); text-decoration:none;">v<?= APP_VERSION ?></a>
  &nbsp;·&nbsp;
  <a href="about.php#credits" style="color:var(--ink-4); text-decoration:none;">Credits</a> &nbsp;·&nbsp; <a href="https://sotaplanner.com" style="color:var(--ink-4); text-decoration:none;">sotaplanner.com</a>
</footer>

<script>
document.addEventListener('click', function(e) {
  var chip = document.getElementById('userChip');
  if (chip && !chip.contains(e.target)) chip.classList.remove('open');
});
</script>
</body>
</html>
