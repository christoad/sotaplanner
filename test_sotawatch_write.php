<?php
require_once 'config.php';
session_start();

if (($_GET['pw'] ?? '') !== 'sota') {
    die('Not found.');
}

$callsign = $_SESSION['sota_callsign'] ?? '';
$login_type = $_SESSION['sota_login_type'] ?? '';
$access_token = $_SESSION['sota_access_token'] ?? '';
$id_token = $_SESSION['sota_id_token'] ?? '';
$token_expires = $_SESSION['sota_token_expires'] ?? 0;

$result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($access_token)) {
    $action = $_POST['action'] ?? 'spot';

    if ($action === 'spot') {
        $sota_ref = trim($_POST['sota_ref'] ?? 'W7O/NC-001');
        $slash = strpos($sota_ref, '/');
        $assoc = $slash !== false ? substr($sota_ref, 0, $slash) : 'W7O';
        $summit = $slash !== false ? substr($sota_ref, $slash + 1) : 'NC-001';

        $body = json_encode([
            'callsign'          => $callsign,
            'activatorCallsign' => $callsign,
            'associationCode'   => $assoc,
            'summitCode'        => $summit,
            'frequency'         => trim($_POST['frequency'] ?? '14.285'),
            'mode'              => trim($_POST['mode'] ?? 'SSB'),
            'type'              => 'TEST',
            'comments'          => 'SOTAplanner write API test',
        ]);

        $ch = curl_init('https://api-db2.sota.org.uk/api/spots');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $access_token,
                'id_token: ' . $id_token,
            ],
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $response  = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_err  = curl_error($ch);
        curl_close($ch);

        $result = [
            'action'    => 'POST spot (TEST)',
            'endpoint'  => 'https://api-db2.sota.org.uk/api/spots',
            'body_sent' => json_decode($body, true),
            'http_code' => $http_code,
            'response'  => $response,
            'curl_err'  => $curl_err,
        ];

    } elseif ($action === 'alert') {
        $sota_ref = trim($_POST['sota_ref'] ?? 'W7O/NC-001');
        $slash = strpos($sota_ref, '/');
        $assoc = $slash !== false ? substr($sota_ref, 0, $slash) : 'W7O';
        $summit = $slash !== false ? substr($sota_ref, $slash + 1) : 'NC-001';

        $date_utc = gmdate('Y-m-d') . 'T' . gmdate('H:i') . ':00Z';

        $body = json_encode([
            'activatingCallsign' => $callsign,
            'associationCode'    => $assoc,
            'summitCode'         => $summit,
            'dateActivated'      => $date_utc,
            'frequency'          => trim($_POST['frequency'] ?? '14.285-SSB'),
            'comments'           => 'SOTAplanner write API test',
            'posterCallsign'     => $callsign,
        ]);

        $ch = curl_init('https://api-db2.sota.org.uk/api/alerts');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $access_token,
                'id_token: ' . $id_token,
            ],
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $response  = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_err  = curl_error($ch);
        curl_close($ch);

        $result = [
            'action'    => 'POST alert',
            'endpoint'  => 'https://api-db2.sota.org.uk/api/alerts',
            'body_sent' => json_decode($body, true),
            'http_code' => $http_code,
            'response'  => $response,
            'curl_err'  => $curl_err,
        ];

    } elseif ($action === 'delete_alert' && !empty($_POST['alert_id'])) {
        $alert_id = (int)$_POST['alert_id'];

        $ch = curl_init('https://api-db2.sota.org.uk/api/alerts/' . $alert_id);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => 'DELETE',
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $access_token,
                'id_token: ' . $id_token,
            ],
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $response  = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_err  = curl_error($ch);
        curl_close($ch);

        $result = [
            'action'    => 'DELETE alert #' . $alert_id,
            'endpoint'  => 'https://api-db2.sota.org.uk/api/alerts/' . $alert_id,
            'http_code' => $http_code,
            'response'  => $response,
            'curl_err'  => $curl_err,
        ];
    }
}

$token_ok     = !empty($access_token);
$token_fresh  = $token_expires > time();
$has_id_token = !empty($id_token);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>SOTAwatch Write API Test</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;1,9..40,400&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
:root {
  --bg:#F7F6F3; --bg-2:#EFEDE8; --ink:#1C1B19; --ink-2:#4A4844; --ink-3:#8C8A86;
  --accent:oklch(52% 0.13 50); --accent-bg:oklch(96% 0.04 65); --accent-border:oklch(84% 0.08 65);
  --green:oklch(52% 0.13 155); --green-bg:oklch(95% 0.04 155);
  --red:oklch(52% 0.16 22); --red-bg:oklch(96% 0.04 22);
  --orange:oklch(62% 0.14 58); --orange-bg:oklch(96% 0.05 58);
  --surface:#fff; --border:#E5E2DA; --border-2:#D4D0C8;
  --font-sans:'DM Sans',system-ui,sans-serif; --font-mono:'DM Mono','Courier New',monospace;
  --r-md:8px; --r-lg:12px; --shadow-sm:0 1px 3px rgba(28,27,25,.07);
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:var(--font-sans);background:var(--bg);color:var(--ink);line-height:1.5}
.page{padding:2rem;max-width:760px;margin:0 auto}
h1{font-size:1.4rem;font-weight:600;margin-bottom:1.5rem}
h2{font-size:1rem;font-weight:600;margin-bottom:.75rem;color:var(--ink-2)}
.card{background:var(--surface);border:1px solid var(--border);border-radius:var(--r-lg);padding:1.5rem;margin-bottom:1.5rem;box-shadow:var(--shadow-sm)}
.status-row{display:flex;align-items:center;gap:.5rem;font-size:.875rem;margin-bottom:.4rem}
.dot{width:10px;height:10px;border-radius:50%;flex-shrink:0}
.dot-green{background:var(--green)} .dot-red{background:var(--red)} .dot-orange{background:var(--orange)}
.form-label{display:block;font-size:.75rem;font-weight:600;color:var(--ink-3);margin-bottom:.3rem;text-transform:uppercase;letter-spacing:.05em}
.form-input{width:100%;padding:.55rem .75rem;border:1px solid var(--border);border-radius:var(--r-md);font-family:var(--font-sans);font-size:.875rem;color:var(--ink);background:var(--surface);outline:none}
.form-input:focus{border-color:var(--accent)}
.form-row{margin-bottom:1rem}
.btn{display:inline-flex;align-items:center;gap:.5rem;padding:0 1rem;height:36px;border-radius:var(--r-md);font-family:var(--font-sans);font-size:.875rem;font-weight:500;cursor:pointer;border:none;transition:background .15s;text-decoration:none}
.btn-primary{background:var(--ink);color:#fff} .btn-primary:hover{background:var(--ink-2)}
.btn-danger{background:var(--red);color:#fff} .btn-danger:hover{opacity:.9}
.btn-ghost{background:transparent;color:var(--ink-2);border:1px solid var(--border)} .btn-ghost:hover{background:var(--bg-2)}
.result-box{background:#1a1a1a;color:#00ff88;padding:1rem;border-radius:var(--r-md);font-family:var(--font-mono);font-size:.8rem;line-height:1.6;overflow-x:auto;white-space:pre-wrap;word-break:break-all}
.result-box.error{color:#ff6b6b}
.tabs{display:flex;gap:.5rem;margin-bottom:1.5rem;flex-wrap:wrap}
.tab-btn{padding:.4rem .9rem;border-radius:20px;font-size:.8rem;font-weight:500;cursor:pointer;border:1px solid var(--border-2);background:var(--surface);color:var(--ink-2)}
.tab-btn.active{background:var(--ink);color:#fff;border-color:var(--ink)}
.tab-pane{display:none} .tab-pane.active{display:block}
.warn-box{background:var(--orange-bg);border:1px solid oklch(84% 0.08 58);border-radius:var(--r-md);padding:.75rem 1rem;font-size:.875rem;color:var(--orange);margin-bottom:1.5rem}
.http-badge{display:inline-block;padding:.15rem .5rem;border-radius:4px;font-family:var(--font-mono);font-size:.8rem;font-weight:600}
.http-2xx{background:var(--green-bg);color:var(--green)}
.http-4xx,.http-5xx{background:var(--red-bg);color:var(--red)}
</style>
</head>
<body>
<div class="page">
  <h1>SOTAwatch Write API Test</h1>
  <p style="color:var(--ink-3);font-size:.875rem;margin-bottom:1.5rem">Tests the <code>api-db2.sota.org.uk</code> write API using your current SOTA SSO session tokens. Spots are posted as <strong>type=TEST</strong> and won't appear in the live feed.</p>

  <!-- Token status -->
  <div class="card">
    <h2>Session Token Status</h2>
    <div class="status-row">
      <div class="dot <?= $login_type === 'oauth' ? 'dot-green' : 'dot-red' ?>"></div>
      <span>Login type: <strong><?= htmlspecialchars($login_type ?: '(none)') ?></strong><?= $login_type !== 'oauth' ? ' — must log in via SOTA SSO to get tokens' : '' ?></span>
    </div>
    <div class="status-row">
      <div class="dot <?= $token_ok ? 'dot-green' : 'dot-red' ?>"></div>
      <span>access_token: <?= $token_ok ? 'present' : 'missing' ?></span>
    </div>
    <div class="status-row">
      <div class="dot <?= $has_id_token ? 'dot-green' : 'dot-red' ?>"></div>
      <span>id_token: <?= $has_id_token ? 'present' : 'missing — log in again after deploying the updated oauth_callback.php' ?></span>
    </div>
    <div class="status-row">
      <div class="dot <?= $token_fresh ? 'dot-green' : 'dot-orange' ?>"></div>
      <span>Token expiry: <?= $token_expires ? date('Y-m-d H:i:s', $token_expires) . ' UTC' : 'unknown' ?><?= !$token_fresh ? ' — EXPIRED, log in again' : '' ?></span>
    </div>
    <div class="status-row">
      <div class="dot dot-green"></div>
      <span>Callsign: <strong><?= htmlspecialchars($callsign) ?></strong></span>
    </div>
  </div>

  <?php if ($login_type !== 'oauth'): ?>
  <div class="warn-box">
    You're logged in via early-access (callsign only), not SOTA SSO. You need to log in with your SOTA account to get OAuth tokens. <a href="oauth_callback.php?action=login">Log in with SOTA SSO &rarr;</a>
  </div>
  <?php endif; ?>

  <!-- Test forms -->
  <?php if ($token_ok): ?>
  <div class="tabs">
    <button class="tab-btn active" onclick="showTab('spot',this)">Post TEST Spot</button>
    <button class="tab-btn" onclick="showTab('alert',this)">Post Alert</button>
    <button class="tab-btn" onclick="showTab('delete_alert',this)">Delete Alert</button>
  </div>

  <div id="tab-spot" class="tab-pane active">
    <div class="card">
      <h2>Post a TEST spot (safe — won't appear live)</h2>
      <form method="POST">
        <input type="hidden" name="action" value="spot">
        <div class="form-row">
          <label class="form-label">Summit Ref</label>
          <input class="form-input" name="sota_ref" value="W7O/NC-001">
        </div>
        <div class="form-row">
          <label class="form-label">Frequency (MHz)</label>
          <input class="form-input" name="frequency" value="14.285">
        </div>
        <div class="form-row">
          <label class="form-label">Mode</label>
          <select class="form-input" name="mode">
            <option>SSB</option><option>CW</option><option>FM</option><option>AM</option><option>Data</option>
          </select>
        </div>
        <button type="submit" class="btn btn-primary">Send TEST Spot</button>
      </form>
    </div>
  </div>

  <div id="tab-alert" class="tab-pane">
    <div class="card">
      <h2>Post an alert (will appear live on SOTAwatch)</h2>
      <form method="POST">
        <input type="hidden" name="action" value="alert">
        <div class="form-row">
          <label class="form-label">Summit Ref</label>
          <input class="form-input" name="sota_ref" value="W7O/NC-001">
        </div>
        <div class="form-row">
          <label class="form-label">Frequency / Mode (free text, max 40 chars)</label>
          <input class="form-input" name="frequency" value="14.285-SSB">
        </div>
        <button type="submit" class="btn btn-primary">Post Alert</button>
      </form>
    </div>
  </div>

  <div id="tab-delete_alert" class="tab-pane">
    <div class="card">
      <h2>Delete an alert by ID</h2>
      <p style="font-size:.875rem;color:var(--ink-3);margin-bottom:1rem">You can find the alert ID in the response after posting one above, or by reading the alerts feed.</p>
      <form method="POST">
        <input type="hidden" name="action" value="delete_alert">
        <div class="form-row">
          <label class="form-label">Alert ID</label>
          <input class="form-input" name="alert_id" type="number" placeholder="e.g. 12345">
        </div>
        <button type="submit" class="btn btn-danger">Delete Alert</button>
      </form>
    </div>
  </div>
  <?php endif; ?>

  <!-- Result -->
  <?php if ($result): ?>
  <div class="card">
    <h2>
      Result: <?= htmlspecialchars($result['action']) ?>
      <span class="http-badge <?= $result['http_code'] >= 200 && $result['http_code'] < 300 ? 'http-2xx' : 'http-4xx' ?>">
        HTTP <?= $result['http_code'] ?>
      </span>
    </h2>
    <?php if (!empty($result['body_sent'])): ?>
    <p style="font-size:.8rem;color:var(--ink-3);margin-bottom:.5rem">Body sent:</p>
    <div class="result-box" style="margin-bottom:1rem"><?= htmlspecialchars(json_encode($result['body_sent'], JSON_PRETTY_PRINT)) ?></div>
    <?php endif; ?>
    <p style="font-size:.8rem;color:var(--ink-3);margin-bottom:.5rem">API response:</p>
    <div class="result-box <?= $result['http_code'] >= 400 ? 'error' : '' ?>"><?php
      $decoded = json_decode($result['response'], true);
      echo htmlspecialchars($decoded !== null ? json_encode($decoded, JSON_PRETTY_PRINT) : ($result['response'] ?: '(empty response)'));
    ?></div>
    <?php if ($result['curl_err']): ?>
    <div class="result-box error" style="margin-top:.5rem">cURL error: <?= htmlspecialchars($result['curl_err']) ?></div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <p style="margin-top:2rem"><a href="index.php" style="color:var(--ink-3);font-size:.875rem">&larr; Back to dashboard</a></p>
</div>

<script>
function showTab(name, btn) {
  document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
  document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
  document.getElementById('tab-' + name).classList.add('active');
  btn.classList.add('active');
}
</script>
</body>
</html>
