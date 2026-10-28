<?php
// Full forecast page for one summit (opened in a new tab from the weather card on summit_detail.php).
// Same Open-Meteo data and cache as the compact card, so it is adjusted to the summit's elevation.
require_once 'config.php';
require_once 'weather_lib.php';
require_once 'sota_cache_helper.php';
session_start();
// Public: the summit page links here for anyone. Signed-in users use their own units; everyone else gets imperial.
$db = getDbConnection();
$signed_in = (bool)getCurrentCallsign();
$units = $signed_in ? getUserUnits($db) : 'imperial';
session_write_close();

$lat    = (float)($_GET['lat'] ?? 0);
$lon    = (float)($_GET['lon'] ?? 0);
$elev_m = (int)($_GET['elev_m'] ?? 0);
$name   = trim($_GET['name'] ?? '');
$ref    = trim($_GET['ref'] ?? '');

// Signed-out visitors can only open forecasts for real SOTA summits, using SOTA's own coordinates,
// so this page cannot be used as a general-purpose weather lookup.
if (!$signed_in) {
    $known = $ref !== '' ? get_sota_cache_summit($ref) : null;
    if (!$known) {
        http_response_code(404);
        die('Forecast not found');
    }
    $lat = (float)$known['lat'];
    $lon = (float)$known['lon'];
    $elev_m = (int)round($known['altFt'] * 0.3048);
    $name = $known['name'];
}

$w = weather_fetch($lat, $lon, $elev_m);
$d = $w['daily'] ?? null;
$h = $w['hourly'] ?? null;
$h_ = fn($s) => htmlspecialchars((string)$s);
$elev_txt = $units === 'metric' ? number_format($elev_m) . ' m' : number_format(round($elev_m * 3.28084)) . ' ft';
$title = trim(($ref ? $ref . ' ' : '') . $name);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Forecast <?= $h_($title) ?> | SOTAplanner</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
:root {
  --bg:#F7F6F3; --bg-2:#EFEDE8; --ink:#1C1B19; --ink-2:#4A4844; --ink-3:#8C8A86; --ink-4:#B8B5B0;
  --accent:oklch(52% 0.13 50); --red:oklch(52% 0.16 22); --red-bg:oklch(96% 0.04 22);
  --blue:oklch(52% 0.12 240); --blue-bg:oklch(95% 0.04 240);
  --surface:#FFFFFF; --border:#E5E2DA; --border-2:#D4D0C8;
  --font-sans:'DM Sans',system-ui,sans-serif; --r-sm:4px; --r-md:8px; --r-lg:12px;
  --shadow-sm:0 1px 3px rgba(28,27,25,0.07),0 1px 2px rgba(28,27,25,0.05);
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:var(--font-sans);background:var(--bg);color:var(--ink);line-height:1.5;min-height:100vh;-webkit-font-smoothing:antialiased}
.page{max-width:900px;margin:0 auto;padding:2rem 1rem}
h1{font-size:1.35rem;font-weight:600;letter-spacing:-0.01em}
.sub{color:var(--ink-3);font-size:0.85rem;margin:0.25rem 0 1.25rem}
.now{background:var(--surface);border:1px solid var(--border);border-radius:var(--r-lg);padding:1rem 1.25rem;box-shadow:var(--shadow-sm);margin-bottom:1rem;display:flex;gap:1rem;align-items:center;flex-wrap:wrap}
.now .big{font-size:1.6rem}
.day{background:var(--surface);border:1px solid var(--border);border-radius:var(--r-lg);box-shadow:var(--shadow-sm);margin-bottom:0.6rem;overflow:hidden}
.day summary{list-style:none;cursor:pointer;display:grid;grid-template-columns:5.5rem 2rem 1fr auto;gap:0.75rem;align-items:center;padding:0.75rem 1rem}
.day summary::-webkit-details-marker{display:none}
.day summary:hover{background:var(--bg)}
.dname{font-weight:600;font-size:0.9rem}.dname small{display:block;font-weight:400;color:var(--ink-3);font-size:0.72rem}
.dicon{font-size:1.4rem}
.dmeta{font-size:0.78rem;color:var(--ink-2);display:flex;gap:0.9rem;flex-wrap:wrap}
.dtemp{font-weight:600;font-size:0.9rem;white-space:nowrap}.dtemp span{color:var(--ink-3);font-weight:400}
table{width:100%;border-collapse:collapse;font-size:0.78rem}
th{text-align:left;font-size:0.68rem;text-transform:uppercase;letter-spacing:0.05em;color:var(--ink-3);font-weight:600;padding:0.4rem 0.6rem;border-top:1px solid var(--border);background:var(--bg)}
td{padding:0.35rem 0.6rem;border-top:1px solid var(--border);white-space:nowrap}
.tw{overflow-x:auto}
.sun{font-size:0.78rem;color:var(--ink-2);padding:0.4rem 1rem 0.6rem;display:flex;gap:1.25rem;flex-wrap:wrap}
.sunitem{display:inline-flex;align-items:center;gap:0.4rem}
.sunic{color:var(--ink-3);flex-shrink:0}
.flag{display:inline-block;font-size:0.65rem;font-weight:600;border-radius:var(--r-sm);padding:0.05rem 0.4rem;text-transform:uppercase;letter-spacing:0.04em;margin-left:0.4rem}
.flag-storm{background:var(--red-bg);color:var(--red)}.flag-snow{background:var(--blue-bg);color:var(--blue)}
.credit{font-size:0.72rem;color:var(--ink-4);text-align:center;margin-top:1.25rem}.credit a{color:var(--ink-4)}
@media(max-width:560px){.day summary{grid-template-columns:4.2rem 1.6rem 1fr;}.dtemp{grid-column:3}}
</style>
</head>
<body>
<div class="page">
  <h1><?= $h_($title ?: 'Summit forecast') ?></h1>
  <p class="sub"><strong style="color:var(--ink-2);text-transform:uppercase;letter-spacing:0.06em;">Weather at summit &middot; <?= $h_($elev_txt) ?></strong><br>16-day forecast for the summit elevation, not the valley below. Times are local to the summit. Tap a day for hour-by-hour detail.</p>

<?php if (!$d || !$h): ?>
  <div class="now">Forecast is not available right now. Please try again in a few minutes.</div>
<?php else:
  $cur = $w['current'] ?? null;
  if ($cur):
    [$cl, $ci] = weather_wmo($cur['weather_code']); ?>
  <div class="now">
    <span class="big"><?= $ci ?></span>
    <div><strong>Now: <?= $h_(weather_temp($cur['temperature_2m'], $units)) ?>, <?= $h_($cl) ?></strong><br>
    <span style="font-size:0.8rem;color:var(--ink-3)">Feels <?= $h_(weather_temp($cur['apparent_temperature'], $units)) ?> &middot; Wind <?= $h_(weather_speed($cur['wind_speed_10m'], $units)) ?>, gusts <?= $h_(weather_speed($cur['wind_gusts_10m'], $units)) ?></span></div>
  </div>
<?php endif;
  foreach ($d['time'] as $i => $date):
    [$label, $icon] = weather_wmo($d['weather_code'][$i]);
    $snow = $d['snowfall_sum'][$i] ?? 0;
    $hours = [];
    foreach ($h['time'] as $k => $t) {
        if (substr($t, 0, 10) !== $date) continue;
        $hr = (int)substr($t, 11, 2);
        if ($hr < 5 || $hr > 21) continue;
        $hours[] = $k;
    }
    $storm = false;
    foreach ($hours as $k) if (($h['cape'][$k] ?? 0) >= 500 || $h['weather_code'][$k] >= 95) $storm = true;
?>
  <details class="day">
    <summary>
      <div class="dname"><?= $i === 0 ? 'Today' : $h_(date('D', strtotime($date))) ?><small><?= $h_(date('M j', strtotime($date))) ?></small></div>
      <div class="dicon"><?= $icon ?></div>
      <div class="dmeta">
        <span><?= $h_($label) ?><?php if ($storm): ?><span class="flag flag-storm">Storm risk</span><?php endif; ?><?php if ($snow > 0.1): ?><span class="flag flag-snow">Snow</span><?php endif; ?></span>
        <span>Wind <?= $h_(weather_speed($d['wind_speed_10m_max'][$i], $units)) ?>, gusts <?= $h_(weather_speed($d['wind_gusts_10m_max'][$i], $units)) ?></span>
        <?php $pp = $d['precipitation_probability_max'][$i] ?? null; ?>
        <span><?= $pp !== null ? 'Rain chance ' . (int)$pp . '%, ' : '' ?>Precip <?= $h_(weather_precip($d['precipitation_sum'][$i], $units)) ?><?= $snow > 0.1 ? ', snow ' . $h_(weather_snow($snow, $units)) : '' ?></span>
      </div>
      <div class="dtemp"><?= $h_(weather_temp($d['temperature_2m_max'][$i], $units)) ?> <span>/ <?= $h_(weather_temp($d['temperature_2m_min'][$i], $units)) ?></span></div>
    </summary>
    <?php if (!empty($d['sunrise'][$i]) && !empty($d['sunset'][$i])): ?>
    <div class="sun"><span class="sunitem"><svg class="sunic" width="22" height="16" viewBox="0 0 22 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 14h18M5.5 14a5.5 5.5 0 0 1 11 0M11 2v4M9 4l2-2 2 2"/></svg>Sunrise <?= $h_(date('g:i A', strtotime($d['sunrise'][$i]))) ?></span><span class="sunitem"><svg class="sunic" width="22" height="16" viewBox="0 0 22 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 14h18M5.5 14a5.5 5.5 0 0 1 11 0M11 2v4M9 4l2 2 2-2"/></svg>Sunset <?= $h_(date('g:i A', strtotime($d['sunset'][$i]))) ?></span></div>
    <?php endif; ?>
    <?php if ($hours): ?>
    <div class="tw"><table>
      <thead><tr><th>Time</th><th></th><th>Temp</th><th>Feels</th><th>Wind</th><th>Gusts</th><th>Wind from</th><th>Rain %</th><th>Precip</th><th>Cloud</th><th>Low cloud</th><th>Visibility</th><th>Lightning</th><th>Freezing level</th></tr></thead>
      <tbody>
      <?php foreach ($hours as $k): [$hl, $hi] = weather_wmo($h['weather_code'][$k]); ?>
        <tr title="<?= $h_($hl) ?>">
          <td><?= $h_(date('g A', strtotime($h['time'][$k]))) ?></td>
          <td><?= $hi ?></td>
          <td><?= $h_(weather_temp($h['temperature_2m'][$k], $units)) ?></td>
          <td><?= $h_(weather_temp($h['apparent_temperature'][$k], $units)) ?></td>
          <td><?= $h_(weather_speed($h['wind_speed_10m'][$k], $units)) ?></td>
          <td><?= $h_(weather_speed($h['wind_gusts_10m'][$k], $units)) ?></td>
          <td><?= isset($h['wind_direction_10m'][$k]) ? $h_(weather_compass($h['wind_direction_10m'][$k])) : '' ?></td>
          <td><?= isset($h['precipitation_probability'][$k]) ? (int)$h['precipitation_probability'][$k] . '%' : '' ?></td>
          <td><?= $h_(weather_precip($h['precipitation'][$k], $units)) ?></td>
          <td><?= isset($h['cloud_cover'][$k]) ? (int)$h['cloud_cover'][$k] . '%' : '' ?></td>
          <td><?= isset($h['cloud_cover_low'][$k]) ? (int)$h['cloud_cover_low'][$k] . '%' : '' ?></td>
          <td><?= isset($h['visibility'][$k]) ? $h_(weather_vis($h['visibility'][$k], $units)) : '' ?></td>
          <td><?= $h_(weather_lightning($h['lightning_potential'][$k] ?? null)) ?></td>
          <td><?= isset($h['freezing_level_height'][$k]) ? $h_(weather_height($h['freezing_level_height'][$k], $units)) : '' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </details>
<?php endforeach; endif; ?>
  <p class="credit"><?= weather_credit_html() ?></p>
</div>
</body>
</html>
