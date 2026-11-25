<?php
// CLI: php trail_discovery_report.php <site dir> <run> [<run> ...]
// One line per run from trail_disc_results: shown start within 400 m of the community start (main yardstick),
// any candidate within 400 m, community route in the top 3, the same two for the run's alternate pick rule,
// verdicts, summit confidence bands (how often each band's summits show the community start), median seconds.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$site = rtrim($argv[1] ?? '', '/');
chdir($site);
require_once $site . '/config.php';
$db = getDbConnection();
foreach (array_slice($argv, 2) as $run) {
    $st = $db->prepare("SELECT * FROM trail_disc_results WHERE run_id = ?"); $st->execute([(int)$run]);
    $all = $st->fetchAll(PDO::FETCH_ASSOC);
    $w = array_values(array_filter($all, fn($r) => $r['verdict']));
    $n = count($w); if (!$n) { echo "run $run: no compared summits\n"; continue; }
    $ok = fn($v) => $v !== null && $v !== '' && (float)$v <= 400;
    $pc = fn($k) => round(100 * $k / $n) . '%';
    $shown = count(array_filter($w, fn($r) => $ok($r['known_shown_start_m'])));
    $alt   = count(array_filter($w, fn($r) => $ok($r['known_alt_start_m'])));
    $any   = count(array_filter($w, fn($r) => $ok($r['known_start_m'])));
    $t3    = count(array_filter($w, fn($r) => $r['known_in_chosen'] == 1 && $r['verdict'] !== 'miss'));
    $t3a   = count(array_filter($w, fn($r) => $r['known_in_alt'] == 1 && $r['verdict'] !== 'miss'));
    $v = array_count_values(array_column($w, 'verdict'));
    $b = [];
    foreach ($w as $r) {
        if ($r['confidence'] === null) continue;
        $k = $r['confidence'] >= 70 ? 'high' : ($r['confidence'] >= 40 ? 'medium' : 'low');
        $b[$k][0] = ($b[$k][0] ?? 0) + 1; $b[$k][1] = ($b[$k][1] ?? 0) + ($ok($r['known_shown_start_m']) ? 1 : 0);
    }
    $secs = array_map('floatval', array_column(array_filter($all, fn($r) => $r['status'] !== 'error'), 'seconds')); sort($secs);
    $none = count(array_filter($all, fn($r) => $r['status'] === 'none'));
    printf("run %d: %d compared (%d no start found) | shown at 400 m %s (alt rule %s) | any at 400 m %s | top 3 %s (alt %s) | match/partial/miss %d/%d/%d | %s | median %.0f s\n",
        $run, $n, $none, $pc($shown), $pc($alt), $pc($any), $pc($t3), $pc($t3a), $v['match'] ?? 0, $v['partial'] ?? 0, $v['miss'] ?? 0,
        implode(', ', array_map(fn($k) => "$k " . ($b[$k][0] ?? 0) . ' (' . (isset($b[$k]) ? round(100 * $b[$k][1] / $b[$k][0]) : '-') . '% right)', ['high', 'medium', 'low'])),
        $secs ? $secs[intdiv(count($secs), 2)] : 0);
}
