<?php
/**
 * Server-side map renderer for PDF exports.
 *
 * The on-screen maps (multi_activate.php, summit_detail.php) are Leaflet maps
 * on OpenStreetMap tiles with OSRM road routes, GPX trails and activation
 * zones. To make the offline PDF look the same, this stitches the very same
 * tiles together with GD and draws the same overlays on top, instead of using
 * Google Static Maps (which can only draw straight lines between points).
 *
 * pdfmap_render($spec) returns a temp JPEG path, or null if the tiles could
 * not be fetched (callers fall back to the old Static Maps image).
 *
 * $spec keys:
 *   w, h          output size in pixels
 *   style         'street' (default) or 'topo'
 *   fit           list of [lat,lng] that must all be visible
 *   min_pad       padding in px around the fit points (default 40)
 *   max_zoom      cap on zoom (default 16)
 *   polygons      [ ['pts'=>[[lat,lng]..], 'color'=>'#CC2200', 'fill'=>0.15, 'weight'=>2], ..]
 *   lines         [ ['pts'=>[[lat,lng]..], 'color'=>'#2d7a4f', 'weight'=>3, 'dash'=>false], ..]
 *   markers       [ ['lat'=>, 'lng'=>, 'color'=>'#..', 'text'=>'1', 'r'=>13, 'label'=>'Summit'], ..]
 *                 (text => numbered pill; no text => plain dot; label => caption beside it)
 */

const PDFMAP_SS = 2; // supersampling factor, gives smooth anti-aliased lines

function pdfmap_font() {
    foreach ([__DIR__ . '/lib/DejaVuSans-Bold.ttf', '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf'] as $f) {
        if (is_file($f)) return $f;
    }
    return null;
}

function pdfmap_world_px($lat, $lng, $z) {
    $n = 256 * (1 << $z);
    $s = sin(deg2rad(max(-85.0511, min(85.0511, $lat))));
    return [($lng + 180) / 360 * $n, (0.5 - log((1 + $s) / (1 - $s)) / (4 * M_PI)) * $n];
}

function pdfmap_color($im, $hex, $alpha = 0) {
    $hex = ltrim($hex, '#');
    return imagecolorallocatealpha($im, hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2)), $alpha);
}

// Fetches tiles in small parallel batches (OSM's usage policy allows two
// connections) and keeps them in a disk cache so repeat PDFs cost nothing.
function pdfmap_fetch_tiles(array $need, $style) {
    $dir = sys_get_temp_dir() . '/sotaplanner_tiles';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $out = []; $todo = [];
    foreach ($need as $k => [$z, $x, $y]) {
        $f = "$dir/{$style}_{$z}_{$x}_{$y}.png";
        if (is_file($f) && filesize($f) > 100 && filemtime($f) > time() - 14 * 86400) {
            $im = @imagecreatefromstring(file_get_contents($f));
            if ($im) { $out[$k] = $im; continue; }
        }
        $todo[$k] = [$z, $x, $y, $f];
    }
    if ($todo) {
        // One HTTP/2 connection per host with all tiles multiplexed over it: fast,
        // and still polite to the volunteer-run tile servers (a couple of connections).
        $mh = curl_multi_init();
        if (defined('CURLMOPT_PIPELINING') && defined('CURLPIPE_MULTIPLEX')) curl_multi_setopt($mh, CURLMOPT_PIPELINING, CURLPIPE_MULTIPLEX);
        curl_multi_setopt($mh, CURLMOPT_MAX_HOST_CONNECTIONS, 2);
        $handles = [];
        foreach ($todo as $k => [$z, $x, $y, $f]) {
            $url = $style === 'topo'
                ? "https://a.tile.opentopomap.org/$z/$x/$y.png"
                : "https://tile.openstreetmap.org/$z/$x/$y.png";
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_USERAGENT => 'SOTAplanner/1.0 (https://sotaplanner.com; PDF export)',
            ]);
            if (defined('CURL_HTTP_VERSION_2TLS')) curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_2TLS);
            curl_multi_add_handle($mh, $ch);
            $handles[$k] = $ch;
        }
        do { $status = curl_multi_exec($mh, $running); if ($running) curl_multi_select($mh, 1.0); } while ($running && $status === CURLM_OK);
        foreach ($handles as $k => $ch) {
            $bytes = curl_multi_getcontent($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_multi_remove_handle($mh, $ch);
            $im = ($code === 200 && $bytes) ? @imagecreatefromstring($bytes) : false;
            if ($im) { $out[$k] = $im; @file_put_contents($todo[$k][3], $bytes); }
        }
        curl_multi_close($mh);
    }
    return $out;
}

function pdfmap_thick_line($im, $x1, $y1, $x2, $y2, $w, $col) {
    $dx = $x2 - $x1; $dy = $y2 - $y1;
    $len = sqrt($dx * $dx + $dy * $dy);
    if ($len < 0.01) return;
    $nx = -$dy / $len * $w / 2; $ny = $dx / $len * $w / 2;
    imagefilledpolygon($im, [$x1 + $nx, $y1 + $ny, $x2 + $nx, $y2 + $ny, $x2 - $nx, $y2 - $ny, $x1 - $nx, $y1 - $ny], $col);
}

function pdfmap_draw_line($im, array $px, $w, $col, $dash = false) {
    $n = count($px);
    if ($n < 2) return;
    $on = 14 * PDFMAP_SS; $off = 10 * PDFMAP_SS; $acc = 0.0; $drawing = true;
    for ($i = 1; $i < $n; $i++) {
        [$x1, $y1] = $px[$i - 1]; [$x2, $y2] = $px[$i];
        if (!$dash) {
            pdfmap_thick_line($im, $x1, $y1, $x2, $y2, $w, $col);
            if ($w > 3) imagefilledellipse($im, (int)round($x2), (int)round($y2), (int)$w, (int)$w, $col);
            continue;
        }
        $len = sqrt(($x2 - $x1) ** 2 + ($y2 - $y1) ** 2);
        $pos = 0.0;
        while ($pos < $len) {
            $limit = $drawing ? $on : $off;
            $step = min($limit - $acc, $len - $pos);
            if ($drawing) {
                $a = $pos / $len; $b = ($pos + $step) / $len;
                pdfmap_thick_line($im, $x1 + ($x2 - $x1) * $a, $y1 + ($y2 - $y1) * $a, $x1 + ($x2 - $x1) * $b, $y1 + ($y2 - $y1) * $b, $w, $col);
            }
            $pos += $step; $acc += $step;
            if ($acc >= $limit - 0.001) { $acc = 0.0; $drawing = !$drawing; }
        }
    }
}

function pdfmap_text($im, $size, $x, $y, $col, $text, $font) {
    imagettftext($im, $size, 0, (int)round($x), (int)round($y), $col, $font, $text);
}

function pdfmap_render(array $spec) {
    if (!function_exists('imagecreatetruecolor') || !function_exists('imagettftext')) return null;
    // Stay well inside the script's time limit: past the budget, callers use the quick fallback image
    if (time() - ($_SERVER['REQUEST_TIME'] ?? time()) > 105) return null;
    $W = (int)$spec['w']; $H = (int)$spec['h'];
    $style = $spec['style'] ?? 'street';
    $pad = $spec['min_pad'] ?? 40;
    $maxZoom = min($spec['max_zoom'] ?? 16, $style === 'topo' ? 17 : 19);
    $fit = $spec['fit'] ?? [];
    if (!$fit) return null;
    $font = pdfmap_font();

    // Largest zoom at which every fit point sits inside the padded frame
    $z = $maxZoom;
    for (; $z > 1; $z--) {
        $minx = $miny = INF; $maxx = $maxy = -INF;
        foreach ($fit as [$la, $ln]) {
            [$px, $py] = pdfmap_world_px($la, $ln, $z);
            $minx = min($minx, $px); $maxx = max($maxx, $px);
            $miny = min($miny, $py); $maxy = max($maxy, $py);
        }
        if (($maxx - $minx) <= $W - 2 * $pad && ($maxy - $miny) <= $H - 2 * $pad) break;
    }
    $minx = $miny = INF; $maxx = $maxy = -INF;
    foreach ($fit as [$la, $ln]) {
        [$px, $py] = pdfmap_world_px($la, $ln, $z);
        $minx = min($minx, $px); $maxx = max($maxx, $px);
        $miny = min($miny, $py); $maxy = max($maxy, $py);
    }
    $ox = ($minx + $maxx) / 2 - $W / 2;
    $oy = ($miny + $maxy) / 2 - $H / 2;

    // Tiles
    $n = 1 << $z;
    $need = [];
    for ($ty = (int)floor($oy / 256); $ty <= (int)floor(($oy + $H - 1) / 256); $ty++) {
        if ($ty < 0 || $ty >= $n) continue;
        for ($tx = (int)floor($ox / 256); $tx <= (int)floor(($ox + $W - 1) / 256); $tx++) {
            $need["$tx/$ty"] = [$z, (($tx % $n) + $n) % $n, $ty];
        }
    }
    $tiles = pdfmap_fetch_tiles($need, $style);
    if (count($tiles) < max(1, count($need) * 0.6)) return null; // mostly missing: let the caller fall back

    $S = PDFMAP_SS;
    $big = imagecreatetruecolor($W * $S, $H * $S);
    imagefill($big, 0, 0, imagecolorallocate($big, 232, 228, 220));
    foreach ($tiles as $k => $tim) {
        [$tx, $ty] = array_map('intval', explode('/', $k));
        imagecopyresampled($big, $tim, (int)round(($tx * 256 - $ox) * $S), (int)round(($ty * 256 - $oy) * $S),
            0, 0, 256 * $S, 256 * $S, imagesx($tim), imagesy($tim));
       
    }
    imagealphablending($big, true);

    $toPx = function ($pts) use ($z, $ox, $oy, $S) {
        $o = [];
        foreach ($pts as [$la, $ln]) {
            [$px, $py] = pdfmap_world_px($la, $ln, $z);
            $o[] = [($px - $ox) * $S, ($py - $oy) * $S];
        }
        return $o;
    };

    foreach ($spec['polygons'] ?? [] as $poly) {
        $px = $toPx($poly['pts']);
        if (count($px) < 3) continue;
        $flat = [];
        foreach ($px as [$x, $y]) { $flat[] = $x; $flat[] = $y; }
        imagefilledpolygon($big, $flat, pdfmap_color($big, $poly['color'], (int)round(127 * (1 - ($poly['fill'] ?? 0.15)))));
        $px[] = $px[0];
        pdfmap_draw_line($big, $px, ($poly['weight'] ?? 2) * $S, pdfmap_color($big, $poly['color'], 20), true);
    }
    foreach ($spec['lines'] ?? [] as $ln) {
        pdfmap_draw_line($big, $toPx($ln['pts']), ($ln['weight'] ?? 3) * $S, pdfmap_color($big, $ln['color'], 12), !empty($ln['dash']));
    }
    foreach ($spec['markers'] ?? [] as $m) {
        [[$cx, $cy]] = $toPx([[$m['lat'], $m['lng']]]);
        $r = ($m['r'] ?? 13) * $S;
        $text = $m['text'] ?? '';
        $white = imagecolorallocate($big, 255, 255, 255);
        $col = pdfmap_color($big, $m['color'], 0);
        $tw = 0; $th = 0;
        if ($text !== '' && $font) {
            $bb = imagettfbbox(13 * $S * 0.75, 0, $font, $text);
            $tw = $bb[2] - $bb[0]; $th = $bb[1] - $bb[7];
        }
        if ($text !== '') {
            $wide = max($r * 2, $tw + 14 * $S);
            imagefilledellipse($big, (int)$cx, (int)$cy, (int)($wide + 5 * $S), (int)($r * 2 + 5 * $S), $white);
            imagefilledellipse($big, (int)$cx, (int)$cy, (int)$wide, (int)($r * 2), $col);
            if ($font) pdfmap_text($big, 13 * $S * 0.75, $cx - $tw / 2 - $bb[0], $cy + $th / 2, $white, $text, $font);
        } else {
            imagefilledellipse($big, (int)$cx, (int)$cy, (int)($r * 2 + 4 * $S), (int)($r * 2 + 4 * $S), $white);
            imagefilledellipse($big, (int)$cx, (int)$cy, (int)($r * 2), (int)($r * 2), $col);
        }
        if (!empty($m['label']) && $font) {
            $size = 11 * $S * 0.75;
            $lbb = imagettfbbox($size, 0, $font, $m['label']);
            $lx = (($m['side'] ?? '') === 'left') ? $cx - $r - 8 * $S - ($lbb[2] - $lbb[0]) : $cx + $r + 8 * $S;
            $ly = $cy + 4 * $S;
            $dark = imagecolorallocate($big, 28, 27, 25);
            foreach ([[-2, 0], [2, 0], [0, -2], [0, 2], [-2, -2], [2, 2], [-2, 2], [2, -2]] as [$dx, $dy]) {
                pdfmap_text($big, $size, $lx + $dx * $S * 0.6, $ly + $dy * $S * 0.6, $white, $m['label'], $font);
            }
            pdfmap_text($big, $size, $lx, $ly, $dark, $m['label'], $font);
        }
    }

    // Attribution (required by the OSM / OpenTopoMap licenses)
    if ($font) {
        $attr = $style === 'topo' ? '(c) OpenStreetMap contributors, OpenTopoMap' : '(c) OpenStreetMap contributors';
        $size = 9 * $S * 0.75;
        $bb = imagettfbbox($size, 0, $font, $attr);
        $bw = $bb[2] - $bb[0] + 10 * $S;
        imagefilledrectangle($big, $W * $S - $bw, $H * $S - 18 * $S, $W * $S, $H * $S, imagecolorallocatealpha($big, 255, 255, 255, 30));
        pdfmap_text($big, $size, $W * $S - $bw + 5 * $S, $H * $S - 5 * $S, imagecolorallocate($big, 70, 68, 64), $attr, $font);
    }
    imagerectangle($big, 0, 0, $W * $S - 1, $H * $S - 1, imagecolorallocate($big, 200, 196, 188));

    $out = imagecreatetruecolor($W, $H);
    imagecopyresampled($out, $big, 0, 0, 0, 0, $W, $H, $W * $S, $H * $S);
    $tmp = tempnam(sys_get_temp_dir(), 'sotapdfmap_');
    $path = $tmp . '.jpg';
    @unlink($tmp);
    imagejpeg($out, $path, 88);
    return $path;
}

// Real road geometry for one drive leg, the same OSRM service the on-screen
// map uses. $from/$to are "lat,lng" strings. Cached per session; returns a list
// of [lat,lng] or null (caller then draws a dashed straight line, like the page).
function pdfmap_osrm_route($from, $to) {
    if (!$from || !$to) return null;
    if (!isset($_SESSION['multi_osrm_cache']) || !is_array($_SESSION['multi_osrm_cache'])) $_SESSION['multi_osrm_cache'] = [];
    $k = $from . '>' . $to;
    if (isset($_SESSION['multi_osrm_cache'][$k])) return $_SESSION['multi_osrm_cache'][$k];
    [$flat, $flng] = array_map('floatval', explode(',', $from));
    [$tlat, $tlng] = array_map('floatval', explode(',', $to));
    $url = "https://router.project-osrm.org/route/v1/driving/$flng,$flat;$tlng,$tlat?overview=full&geometries=geojson";
    for ($try = 0; $try < 2; $try++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_USERAGENT => 'SOTAplanner/1.0 (https://sotaplanner.com; PDF export)']);
        $resp = curl_exec($ch);
        $data = $resp ? json_decode($resp, true) : null;
        if (($data['code'] ?? '') === 'Ok' && !empty($data['routes'][0]['geometry']['coordinates'])) {
            $pts = array_map(fn($c) => [$c[1], $c[0]], $data['routes'][0]['geometry']['coordinates']);
            $_SESSION['multi_osrm_cache'][$k] = $pts;
            return $pts;
        }
        usleep(400000);
    }
    return null;
}

// Track points from a GPX file ([lat,lng] list, evenly thinned to ~$max points).
// Attribute order inside the tag doesn't matter.
function pdfmap_gpx_points($file, $max = 800) {
    if (!$file || !is_file($file)) return null;
    $xml = @file_get_contents($file);
    if (!$xml) return null;
    preg_match_all('/<(?:trkpt|rtept)\b([^>]*)>/i', $xml, $m);
    $pts = [];
    foreach ($m[1] as $attrs) {
        if (preg_match('/\blat\s*=\s*"([^"]+)"/i', $attrs, $a) && preg_match('/\blon\s*=\s*"([^"]+)"/i', $attrs, $b)) {
            $pts[] = [(float)$a[1], (float)$b[1]];
        }
    }
    $total = count($pts);
    if ($total < 2) return null;
    if ($total <= $max) return $pts;
    $step = $total / $max; $out = [];
    for ($i = 0; $i < $max; $i++) $out[] = $pts[(int)floor($i * $step)];
    $out[] = $pts[$total - 1];
    return $out;
}

// Activation zone polygon -> [lat,lng] ring (GeoJSON is [lon,lat], possibly nested)
function pdfmap_az_ring($polygon) {
    if (!$polygon) return [];
    if (isset($polygon[0][0][0]) && is_array($polygon[0][0][0])) $ring = $polygon[0][0];
    elseif (isset($polygon[0][0]) && is_array($polygon[0][0])) $ring = $polygon[0];
    else $ring = $polygon;
    $out = [];
    foreach ($ring as $pt) if (isset($pt[0], $pt[1])) $out[] = [(float)$pt[1], (float)$pt[0]];
    return count($out) >= 3 ? $out : [];
}
