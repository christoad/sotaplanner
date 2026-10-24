<?php
// Summit weather via Open-Meteo (free, non-commercial, CC-BY 4.0: credit must be shown wherever it is displayed).
// Raw data is always cached in metric; the weather_* helpers convert for display.

const WEATHER_CACHE_TTL = 10800; // 3 hours

function weather_fetch($lat, $lon, $elev_m) {
    $lat = round((float)$lat, 2);
    $lon = round((float)$lon, 2);
    $elev_m = (int)round((float)$elev_m);
    if (abs($lat) > 90 || abs($lon) > 180 || ($lat == 0 && $lon == 0)) return null;

    $dir = __DIR__ . '/weather_cache';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $file = "$dir/" . md5("v2,$lat,$lon,$elev_m") . '.json';

    $cached = is_readable($file) ? json_decode(@file_get_contents($file), true) : null;
    if ($cached && (time() - filemtime($file)) < WEATHER_CACHE_TTL) return $cached;
    // Recently failed live fetch: serve stale data (if any) rather than retrying on every page load
    if (is_file("$file.fail") && (time() - filemtime("$file.fail")) < 900) return $cached ?: null;

    $url = 'https://api.open-meteo.com/v1/forecast?' . http_build_query([
        'latitude'  => $lat,
        'longitude' => $lon,
        'elevation' => $elev_m,
        'timezone'  => 'auto',
        'forecast_days' => 16,
        'current'   => 'temperature_2m,apparent_temperature,weather_code,wind_speed_10m,wind_gusts_10m',
        'hourly'    => 'temperature_2m,apparent_temperature,precipitation,snowfall,weather_code,wind_speed_10m,wind_gusts_10m,cape,freezing_level_height,precipitation_probability,lightning_potential,cloud_cover,cloud_cover_low,visibility,wind_direction_10m',
        'daily'     => 'weather_code,temperature_2m_max,temperature_2m_min,precipitation_sum,snowfall_sum,wind_speed_10m_max,wind_gusts_10m_max,precipitation_probability_max,sunrise,sunset',
    ]);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_CONNECTTIMEOUT => 3]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = ($code === 200 && $body) ? json_decode($body, true) : null;
    if (!$data || empty($data['hourly']['time'])) {
        @touch("$file.fail");
        return $cached ?: null;
    }
    @file_put_contents($file, $body, LOCK_EX);
    @unlink("$file.fail");
    return $data;
}

// WMO weather code => [label, emoji]
function weather_wmo($code) {
    $code = (int)$code;
    if ($code === 0) return ['Clear', '☀️'];
    if ($code === 1) return ['Mostly clear', '🌤️'];
    if ($code === 2) return ['Partly cloudy', '⛅'];
    if ($code === 3) return ['Overcast', '☁️'];
    if ($code === 45 || $code === 48) return ['Fog', '🌫️'];
    if ($code >= 51 && $code <= 57) return ['Drizzle', '🌦️'];
    if ($code >= 61 && $code <= 67) return ['Rain', '🌧️'];
    if ($code >= 71 && $code <= 77) return ['Snow', '🌨️'];
    if ($code >= 80 && $code <= 82) return ['Rain showers', '🌦️'];
    if ($code === 85 || $code === 86) return ['Snow showers', '🌨️'];
    if ($code >= 95) return ['Thunderstorms', '⛈️'];
    return ['Mixed', '⛅'];
}

function weather_temp($c, $units) {
    return $units === 'metric' ? round($c) . '°C' : round($c * 9 / 5 + 32) . '°F';
}
function weather_speed($kmh, $units) {
    return $units === 'metric' ? round($kmh) . ' km/h' : round($kmh * 0.621371) . ' mph';
}
function weather_precip($mm, $units) {
    if ($units === 'metric') return ($mm < 0.1 ? '0' : round($mm, 1)) . ' mm';
    return ($mm < 0.01 ? '0' : round($mm / 25.4, 2)) . ' in';
}
function weather_snow($cm, $units) {
    return $units === 'metric' ? round($cm, 1) . ' cm' : round($cm / 2.54, 1) . ' in';
}
function weather_vis($m, $units) {
    return $units === 'metric' ? round($m / 1000, $m >= 10000 ? 0 : 1) . ' km' : round($m / 1609.34, $m >= 16093 ? 0 : 1) . ' mi';
}
function weather_compass($deg) {
    $pts = ['N','NNE','NE','ENE','E','ESE','SE','SSE','S','SSW','SW','WSW','W','WNW','NW','NNW'];
    return $pts[(int)round(((float)$deg % 360) / 22.5) % 16];
}
// Rough label for lightning potential (J/kg); null when the model has no value for this place
function weather_lightning($v) {
    if ($v === null) return '';
    if ($v < 1) return 'None';
    if ($v < 25) return 'Low';
    if ($v < 100) return 'Moderate';
    return 'High';
}
function weather_height($m, $units) {
    return $units === 'metric' ? number_format(round($m, -1)) . ' m' : number_format(round($m * 3.28084, -2)) . ' ft';
}

// Short 5-day-style outlook and current conditions for the compact summit detail display
function weather_compact($w, $units) {
    if (!$w) return null;
    $cur = $w['current'] ?? null;
    $out = ['now' => null, 'days' => []];
    if ($cur) {
        [$label, $icon] = weather_wmo($cur['weather_code']);
        $out['now'] = [
            'icon'  => $icon,
            'label' => $label,
            'temp'  => weather_temp($cur['temperature_2m'], $units),
            'feels' => weather_temp($cur['apparent_temperature'], $units),
            'wind'  => weather_speed($cur['wind_speed_10m'], $units),
            'gust'  => weather_speed($cur['wind_gusts_10m'], $units),
        ];
    }
    $d = $w['daily'] ?? null;
    if ($d) {
        for ($i = 0; $i < min(4, count($d['time'])); $i++) {
            [$label, $icon] = weather_wmo($d['weather_code'][$i]);
            $out['days'][] = [
                'day'  => $i === 0 ? 'Today' : date('D', strtotime($d['time'][$i])),
                'icon' => $icon,
                'label' => $label,
                'hi'   => weather_temp($d['temperature_2m_max'][$i], $units),
                'lo'   => weather_temp($d['temperature_2m_min'][$i], $units),
                // Older cached forecasts have gusts only; show those until the cache refreshes
                'wind' => weather_speed($d['wind_speed_10m_max'][$i] ?? $d['wind_gusts_10m_max'][$i] ?? 0, $units),
                'gust' => weather_speed($d['wind_gusts_10m_max'][$i] ?? 0, $units),
            ];
        }
    }
    return $out;
}

// Hourly rows between two local timestamps (inclusive of the hour containing each end), plus a summary
function weather_window($w, $start_ts, $end_ts) {
    if (!$w || empty($w['hourly']['time'])) return null;
    $h = $w['hourly'];
    $from = date('Y-m-d\TH:00', $start_ts);
    $to   = date('Y-m-d\TH:00', $end_ts);
    $rows = [];
    foreach ($h['time'] as $i => $t) {
        if ($t < $from || $t > $to) continue;
        $rows[] = [
            'time'   => $t,
            'code'   => $h['weather_code'][$i],
            'temp'   => $h['temperature_2m'][$i],
            'feels'  => $h['apparent_temperature'][$i],
            'precip' => $h['precipitation'][$i],
            'snow'   => $h['snowfall'][$i],
            'wind'   => $h['wind_speed_10m'][$i],
            'gust'   => $h['wind_gusts_10m'][$i],
            'cape'   => $h['cape'][$i] ?? 0,
            'frz'    => $h['freezing_level_height'][$i] ?? null,
        ];
    }
    if (!$rows) return null;
    $sum = [
        'tmin'   => min(array_column($rows, 'temp')),
        'tmax'   => max(array_column($rows, 'temp')),
        'fmin'   => min(array_column($rows, 'feels')),
        'wind'   => max(array_column($rows, 'wind')),
        'gust'   => max(array_column($rows, 'gust')),
        'precip' => array_sum(array_column($rows, 'precip')),
        'snow'   => array_sum(array_column($rows, 'snow')),
        'storm'  => (max(array_column($rows, 'cape')) >= 500) || (max(array_column($rows, 'code')) >= 95),
        'frz'    => null,
    ];
    $frz = array_filter(array_column($rows, 'frz'), fn($v) => $v !== null);
    if ($frz) $sum['frz'] = min($frz);
    return ['rows' => $rows, 'summary' => $sum];
}

function weather_credit_html() {
    return 'Weather data by <a href="https://open-meteo.com/" target="_blank" rel="noopener">Open-Meteo.com</a>';
}
