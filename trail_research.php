<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config.php';
session_start();

$db = getDbConnection();
$current_user = $_SESSION['callsign'] ?? 'KI6CR';

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

if (!$summit) {
    header('Location: index.php');
    exit;
}

// Get current planning group
$current_group = getCurrentPlanningGroup($db);
if (!$current_group) {
    header('Location: index.php');
    exit;
}

// -----------------------------------------------------------------------
// Trail URL scraper — tries AllTrails (__NEXT_DATA__), JSON-LD, meta tags
// -----------------------------------------------------------------------
function scrapeTrailUrl($url) {
    $result = [
        'trail_name'         => null,
        'distance_mi'        => null,
        'elevation_gain_ft'  => null,
        'difficulty'         => null,
        'trailhead_lat'      => null,
        'trailhead_lng'      => null,
        'source'             => null,
        'fields_found'       => [],
        'error'              => null,
    ];

    // Detect known Cloudflare-protected domains upfront
    $blocked_domains = ['alltrails.com', 'gaiagps.com', 'strava.com', 'komoot.com'];
    $host = strtolower(parse_url($url, PHP_URL_HOST) ?? '');
    foreach ($blocked_domains as $d) {
        if (str_contains($host, $d)) {
            $result['error'] = "blocked:$d";
            return $result;
        }
    }

    // Fetch with browser-like headers; social bot UAs are sometimes whitelisted
    $user_agents = [
        'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)',
        'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
    ];

    $html = null;
    foreach ($user_agents as $ua) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 12,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT      => $ua,
            CURLOPT_HTTPHEADER     => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language: en-US,en;q=0.9',
                'Connection: keep-alive',
                'Cache-Control: no-cache',
            ],
            CURLOPT_ENCODING       => '',
        ]);
        $body      = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // Reject Cloudflare challenge pages even if they return 200
        if ($http_code === 200 && $body && strlen($body) > 2000
            && stripos($body, 'captcha') === false
            && stripos($body, 'cf-browser-verification') === false
            && stripos($body, 'Just a moment') === false) {
            $html = $body;
            break;
        }
    }

    if (!$html) {
        $result['error'] = 'Could not fetch that page — the site appears to be blocking automated requests. Please enter trail data manually below.';
        return $result;
    }

    // ---- 1. AllTrails __NEXT_DATA__ (most reliable for AllTrails) ----
    if (strpos($url, 'alltrails.com') !== false) {
        if (preg_match('/<script[^>]+id=["\']__NEXT_DATA__["\'][^>]*>(.*?)<\/script>/si', $html, $m)) {
            $nd = json_decode($m[1], true);
            // Navigate to trail object — structure: props.pageProps.trail
            $trail = $nd['props']['pageProps']['trail'] ?? null;
            if ($trail) {
                $result['source'] = 'AllTrails';
                // Distance: stored in meters, round-trip
                if (isset($trail['length'])) {
                    $result['distance_mi']       = round($trail['length'] * 0.000621371, 2);
                    $result['fields_found'][]    = 'distance_mi';
                }
                // Elevation gain: stored in meters
                if (isset($trail['elevation_gain'])) {
                    $result['elevation_gain_ft'] = round($trail['elevation_gain'] * 3.28084);
                    $result['fields_found'][]    = 'elevation_gain_ft';
                }
                // Difficulty: 1=easy, 2=moderate, 3=hard
                if (isset($trail['difficulty_rating'])) {
                    $d = (int)$trail['difficulty_rating'];
                    $result['difficulty'] = $d <= 1 ? 'easy' : ($d === 2 ? 'moderate' : 'hard');
                    $result['fields_found'][] = 'difficulty';
                }
                // Trailhead coordinates
                if (isset($trail['lat'], $trail['lng'])) {
                    $result['trailhead_lat']  = (float)$trail['lat'];
                    $result['trailhead_lng']  = (float)$trail['lng'];
                    $result['fields_found'][] = 'trailhead';
                }
                if (isset($trail['name'])) {
                    $result['trail_name'] = $trail['name'];
                }
                return $result;
            }
        }
    }

    // ---- 2. JSON-LD structured data (works for many trail sites) ----
    preg_match_all('/<script[^>]+type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/si', $html, $ldMatches);
    foreach ($ldMatches[1] as $jsonStr) {
        $ld = json_decode($jsonStr, true);
        if (!$ld) continue;
        $items = isset($ld['@graph']) ? $ld['@graph'] : [$ld];
        foreach ($items as $item) {
            $type = is_array($item['@type'] ?? null)
                ? implode(',', $item['@type'])
                : ($item['@type'] ?? '');
            if (!preg_match('/Hiking|Exercise|Trail|Place/i', $type)) continue;

            $result['source'] = 'Structured Data';

            // Distance — may be "7.1 miles", "11.4 km", or a number in meters
            foreach (['distance', 'length'] as $dk) {
                if (!isset($item[$dk])) continue;
                $raw = $item[$dk];
                preg_match('/[\d.]+/', (string)$raw, $dm);
                if (!$dm) continue;
                $val = (float)$dm[0];
                if (stripos($raw, 'km') !== false)    $val *= 0.621371;
                elseif (stripos($raw, 'm') !== false && !stripos($raw, 'mile')) $val *= 0.000621371;
                $result['distance_mi']    = round($val, 2);
                $result['fields_found'][] = 'distance_mi';
                break;
            }

            // Elevation gain
            foreach (['ascent', 'elevationGain', 'elevation_gain'] as $ek) {
                if (!isset($item[$ek])) continue;
                $raw = $item[$ek];
                preg_match('/[\d.]+/', (string)$raw, $em);
                if (!$em) continue;
                $val = (float)$em[0];
                // If unit is meters (< 300 is suspicious for feet, but could be a low trail)
                // Check if the value looks like meters vs feet
                if (stripos($raw, 'meter') !== false || stripos($raw, ' m') !== false) $val *= 3.28084;
                $result['elevation_gain_ft'] = round($val);
                $result['fields_found'][]    = 'elevation_gain_ft';
                break;
            }

            // Difficulty
            foreach (['difficulty', 'exerciseDifficulty'] as $dfk) {
                if (!isset($item[$dfk])) continue;
                $d = strtolower((string)$item[$dfk]);
                if (strpos($d, 'easy') !== false)                               $result['difficulty'] = 'easy';
                elseif (strpos($d, 'hard') !== false || strpos($d, 'strenuous') !== false) $result['difficulty'] = 'hard';
                elseif (strpos($d, 'moderate') !== false)                       $result['difficulty'] = 'moderate';
                if ($result['difficulty']) $result['fields_found'][] = 'difficulty';
                break;
            }

            // Coordinates
            if (isset($item['geo']['latitude'])) {
                $result['trailhead_lat']  = (float)$item['geo']['latitude'];
                $result['trailhead_lng']  = (float)$item['geo']['longitude'];
                $result['fields_found'][] = 'trailhead';
            }
            if (isset($item['name'])) $result['trail_name'] = $item['name'];

            if (!empty($result['fields_found'])) return $result;
        }
    }

    // ---- 3. Meta tag fallback ----
    $metas = [];
    preg_match_all('/<meta[^>]+>/i', $html, $metaMatches);
    foreach ($metaMatches[0] as $tag) {
        $name    = '';
        $content = '';
        if (preg_match('/(?:name|property)=["\']([^"\']+)["\']/i', $tag, $n)) $name    = strtolower($n[1]);
        if (preg_match('/content=["\']([^"\']+)["\']/i', $tag, $c))           $content = $c[1];
        if ($name && $content) $metas[$name] = $content;
    }
    if (!empty($metas)) {
        $result['source'] = 'Page Meta Tags';
        if (isset($metas['og:title'])) $result['trail_name'] = $metas['og:title'];
        // Some sites put distance/gain in description meta
        $desc = $metas['og:description'] ?? $metas['description'] ?? '';
        if ($desc) {
            if (preg_match('/([\d.]+)\s*mi(?:les?)?/i', $desc, $dm))
                { $result['distance_mi'] = (float)$dm[1]; $result['fields_found'][] = 'distance_mi'; }
            if (preg_match('/([\d,]+)\s*ft?\s+(?:gain|elevation)/i', $desc, $em))
                { $result['elevation_gain_ft'] = (int)str_replace(',', '', $em[1]); $result['fields_found'][] = 'elevation_gain_ft'; }
        }
    }

    if (empty($result['fields_found'])) {
        $result['error'] = 'Page was fetched but no trail data could be extracted. Please enter the details manually.';
    }
    return $result;
}

// Handle trail URL scrape (pre-populate form)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['scrape_trail'])) {
    $scrape_url    = trim($_POST['trail_link'] ?? '');
    $scraped       = $scrape_url ? scrapeTrailUrl($scrape_url) : ['error' => 'No URL provided.', 'fields_found' => []];
    // Pass results back to the form via session so we can show them
    $_SESSION['trail_scrape'] = array_merge($scraped, ['url' => $scrape_url]);
    header('Location: trail_research.php?id=' . $summit_id . '&group=' . $current_group['id'] . '&scraped=1');
    exit;
}

// Pick up any scrape results from session
$scraped = null;
if (isset($_GET['scraped']) && isset($_SESSION['trail_scrape'])) {
    $scraped = $_SESSION['trail_scrape'];
    unset($_SESSION['trail_scrape']);
}

// Handle manual data entry
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_trail_data'])) {
    // Build SET clause dynamically so optional fields only update when present
    $sets   = ['trail_link = ?', 'hike_distance_mi = ?', 'hike_elevation_gain_ft = ?', "status = 'researched'"];
    $params = [
        $_POST['trail_link'] ?: null,
        $_POST['hike_distance_mi'] ?: null,
        $_POST['hike_elevation_gain_ft'] ?: null,
    ];
    if (!empty($_POST['difficulty'])) {
        $sets[]   = 'difficulty = ?';
        $params[] = $_POST['difficulty'];
    }
    if (!empty($_POST['trailhead_lat'])) {
        $sets[]   = 'trailhead_lat = ?';
        $params[] = (float)$_POST['trailhead_lat'];
    }
    if (!empty($_POST['trailhead_lng'])) {
        $sets[]   = 'trailhead_lng = ?';
        $params[] = (float)$_POST['trailhead_lng'];
    }
    $params[] = $summit_id;

    $stmt = $db->prepare("UPDATE summits SET " . implode(', ', $sets) . " WHERE id = ?");
    $stmt->execute($params);
    
    // Calculate hike times if data provided
    if (!empty($_POST['hike_distance_mi']) && !empty($_POST['hike_elevation_gain_ft'])) {
        $distance = (float)$_POST['hike_distance_mi'];
        $elevation = (int)$_POST['hike_elevation_gain_ft'];
        
        $time_up = calculateHikeTime($distance / 2, $elevation);
        $time_down = calculateHikeTime($distance / 2, 0);
        
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
    <link href="https://fonts.googleapis.com/css2?family=Overpass:wght@300;600;800&family=Courier+Prime:wght@400;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --navy: #1E3A5F;
            --teal: #4A90A4;
            --light-blue: #5BA4B8;
            --gold: #E6B84A;
            --tan: #D4A574;
            --snow: #F5F5F0;
            /* Aliases */
            --peak-brown: #1E3A5F;
            --trail-green: #4A90A4;
            --forest-dark: #1E3A5F;
            --summit-gold: #E6B84A;
            --earth-tan: #D4A574;
            --snow-white: #F5F5F0;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Overpass', sans-serif;
            background: linear-gradient(135deg, #F5F5F0 0%, #E8E4D8 100%);
            color: var(--forest-dark);
            min-height: 100vh;
            padding: 2rem;
        }

        .container {
            max-width: 1000px;
            margin: 0 auto;
        }

        h1 {
            font-size: 2.5rem;
            font-weight: 800;
            color: var(--peak-brown);
            margin-bottom: 0.5rem;
        }

        .subtitle {
            color: #666;
            margin-bottom: 2rem;
            font-size: 1.1rem;
        }

        .card {
            background: white;
            border-radius: 12px;
            padding: 2rem;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
            margin-bottom: 2rem;
        }

        .info-box {
            background: #E8F4F8;
            border-left: 4px solid var(--trail-green);
            padding: 1.5rem;
            border-radius: 6px;
            margin-bottom: 2rem;
        }

        .info-box h3 {
            color: var(--forest-dark);
            margin-bottom: 0.5rem;
        }

        .search-links {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .search-link {
            display: block;
            padding: 1.5rem;
            background: var(--snow-white);
            border: 2px solid var(--earth-tan);
            border-radius: 8px;
            text-decoration: none;
            color: var(--forest-dark);
            transition: all 0.3s;
            text-align: center;
        }

        .search-link:hover {
            border-color: var(--trail-green);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        .search-link-title {
            font-size: 1.2rem;
            font-weight: 700;
            color: var(--peak-brown);
            margin-bottom: 0.5rem;
        }

        .search-link-desc {
            font-size: 0.9rem;
            color: #666;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        label {
            display: block;
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: var(--forest-dark);
            text-transform: uppercase;
            font-size: 0.85rem;
            letter-spacing: 0.05em;
        }

        input[type="text"],
        input[type="number"] {
            width: 100%;
            padding: 0.75rem;
            border: 2px solid var(--earth-tan);
            border-radius: 6px;
            font-family: 'Overpass', sans-serif;
            font-size: 1rem;
            transition: all 0.3s ease;
        }

        input:focus {
            outline: none;
            border-color: var(--trail-green);
            box-shadow: 0 0 0 3px rgba(74, 124, 89, 0.1);
        }

        .btn {
            padding: 0.75rem 1.5rem;
            background: linear-gradient(135deg, var(--trail-green) 0%, var(--forest-dark) 100%);
            color: white;
            border: none;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            font-size: 0.9rem;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-block;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(0, 0, 0, 0.2);
        }

        .btn-secondary {
            background: linear-gradient(135deg, #999 0%, #666 100%);
        }

        .helper-text {
            font-size: 0.85rem;
            color: #666;
            margin-top: 0.5rem;
            font-style: italic;
        }

        .grid-2col {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔍 Find Trail for <?= htmlspecialchars($summit_name) ?></h1>
        <p class="subtitle"><?= htmlspecialchars($summit_ref) ?> • <?= number_format($summit_lat, 6) ?>, <?= number_format($summit_lng, 6) ?></p>
        
        <div class="info-box">
            <h3>📍 How to Find the Right Trail:</h3>
            <ol style="margin-left: 1.5rem; line-height: 1.8;">
                <li>Click on the search links below to find trails near this summit</li>
                <li>Look for trails that lead TO or NEAR the summit coordinates</li>
                <li>Check the trail's highest point matches the summit elevation (<?= number_format($summit['elevation_ft']) ?> ft)</li>
                <li>Once you find the right trail, copy its details into the form below</li>
            </ol>
        </div>

        <div class="card">
            <h2 style="margin-bottom: 1.5rem; color: var(--peak-brown);">Search These Trail Resources:</h2>
            
            <div class="search-links">
                <a href="<?= $alltrails_search ?>" target="_blank" class="search-link">
                    <div class="search-link-title">🥾 AllTrails</div>
                    <div class="search-link-desc">Search trails near summit coordinates</div>
                </a>
                
                <a href="<?= $google_maps ?>" target="_blank" class="search-link">
                    <div class="search-link-title">🗺️ Google Maps</div>
                    <div class="search-link-desc">Find hiking trails in the area</div>
                </a>
                
                <a href="<?= $gaia_gps_search ?>" target="_blank" class="search-link">
                    <div class="search-link-title">🧭 GAIA GPS</div>
                    <div class="search-link-desc">View summit on topographic map</div>
                </a>
                
                <a href="<?= $summit['sotlas_link'] ?>" target="_blank" class="search-link">
                    <div class="search-link-title">📡 SOTLas</div>
                    <div class="search-link-desc">View on SOTA mapping tool</div>
                </a>
            </div>
        </div>

        <div class="card">
            <h2 style="margin-bottom: 0.5rem; color: var(--peak-brown);">Enter Trail Information:</h2>
            <p style="color:#666; font-size:0.9rem; margin-bottom:1.5rem;">Paste an AllTrails (or other trail site) URL and click <strong>Look Up Trail Data</strong> to auto-fill the fields below.</p>

            <?php if ($scraped): ?>
                <?php if ($scraped['error']): ?>
                    <?php
                    $isBlocked = str_starts_with($scraped['error'], 'blocked:');
                    $blockedSite = $isBlocked ? ucfirst(explode(':', $scraped['error'])[1]) : '';
                    ?>
                    <div style="background:#FFF8E1; border-left:4px solid #F9A825; padding:1rem; border-radius:6px; margin-bottom:1.25rem; font-size:0.9rem; color:#5D4037;">
                        <?php if ($isBlocked): ?>
                            🔒 <strong><?= htmlspecialchars($blockedSite) ?> blocks automated data fetching</strong> (Cloudflare protection).
                            Enter the trail stats manually below — you can still save the link.
                        <?php else: ?>
                            ⚠️ <?= htmlspecialchars($scraped['error']) ?>
                        <?php endif; ?>
                    </div>
                <?php elseif (!empty($scraped['fields_found'])): ?>
                    <div style="background:#E6F4EA; border-left:4px solid #2E7D32; padding:1rem; border-radius:6px; margin-bottom:1.25rem; font-size:0.9rem; color:#1B5E20;">
                        ✅ <strong>Found from <?= htmlspecialchars($scraped['source']) ?>:</strong>
                        <?php
                        $labels = ['distance_mi'=>'Distance', 'elevation_gain_ft'=>'Elevation Gain', 'difficulty'=>'Difficulty', 'trailhead'=>'Trailhead Coords'];
                        foreach ($scraped['fields_found'] as $f) {
                            if (isset($labels[$f])) echo ' <span style="background:#C8E6C9;padding:0.15rem 0.5rem;border-radius:4px;font-weight:700;">' . $labels[$f] . '</span>';
                        }
                        ?>
                        &nbsp;— Review the values below and click <strong>Save Trail Data</strong>.
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <!-- Step 1: URL + Lookup -->
            <form method="POST" style="margin-bottom:1.25rem;">
                <input type="hidden" name="summit_id" value="<?= $summit_id ?>">
                <label for="trail_link_lookup">Trail Link (AllTrails, Hiking Project, etc.)</label>
                <div style="display:flex; gap:0.5rem; margin-top:0.5rem;">
                    <input type="text" id="trail_link_lookup" name="trail_link"
                           value="<?= htmlspecialchars($scraped['url'] ?? '') ?>"
                           placeholder="https://www.alltrails.com/trail/..." style="flex:1;">
                    <button type="submit" name="scrape_trail" class="btn" style="white-space:nowrap; width:auto; padding:0.75rem 1.25rem;">
                        🔍 Look Up Trail Data
                    </button>
                </div>
                <p class="helper-text">Auto-fill works with: Hiking Project, TrailLink, and most sites with structured data. AllTrails and GaiaGPS block automated access — paste the link and enter stats manually.</p>
            </form>

            <!-- Step 2: Confirm / manual entry + Save -->
            <form method="POST">
                <input type="hidden" name="trail_link" id="save-trail-link" value="<?= htmlspecialchars($scraped['url'] ?? '') ?>">
                <script>
                // Keep save form's trail_link in sync with the lookup input
                document.getElementById('trail_link_lookup')?.addEventListener('input', function() {
                    document.getElementById('save-trail-link').value = this.value;
                });
                </script>
                <input type="hidden" name="trailhead_lat" value="<?= htmlspecialchars($scraped['trailhead_lat'] ?? '') ?>">
                <input type="hidden" name="trailhead_lng" value="<?= htmlspecialchars($scraped['trailhead_lng'] ?? '') ?>">

                <div class="grid-2col">
                    <div class="form-group">
                        <label for="hike_distance_mi">
                            Round-Trip Distance (miles)
                            <?php if ($scraped && in_array('distance_mi', $scraped['fields_found'] ?? [])): ?>
                                <span style="background:#C8E6C9;color:#1B5E20;padding:0.1rem 0.4rem;border-radius:4px;font-size:0.75rem;font-weight:700;">Auto-filled</span>
                            <?php endif; ?>
                        </label>
                        <input type="number" id="hike_distance_mi" name="hike_distance_mi"
                               step="0.01" placeholder="e.g., 1.4"
                               value="<?= htmlspecialchars($scraped['distance_mi'] ?? '') ?>"
                               required>
                    </div>

                    <div class="form-group">
                        <label for="hike_elevation_gain_ft">
                            Elevation Gain (feet)
                            <?php if ($scraped && in_array('elevation_gain_ft', $scraped['fields_found'] ?? [])): ?>
                                <span style="background:#C8E6C9;color:#1B5E20;padding:0.1rem 0.4rem;border-radius:4px;font-size:0.75rem;font-weight:700;">Auto-filled</span>
                            <?php endif; ?>
                        </label>
                        <input type="number" id="hike_elevation_gain_ft" name="hike_elevation_gain_ft"
                               placeholder="e.g., 300"
                               value="<?= htmlspecialchars($scraped['elevation_gain_ft'] ?? '') ?>"
                               required>
                    </div>
                </div>

                <div class="form-group">
                    <label for="difficulty">
                        Difficulty
                        <?php if ($scraped && in_array('difficulty', $scraped['fields_found'] ?? [])): ?>
                            <span style="background:#C8E6C9;color:#1B5E20;padding:0.1rem 0.4rem;border-radius:4px;font-size:0.75rem;font-weight:700;">Auto-filled</span>
                        <?php endif; ?>
                    </label>
                    <select id="difficulty" name="difficulty"
                            style="width:100%;padding:0.75rem;border:2px solid var(--earth-tan);border-radius:6px;font-family:'Overpass',sans-serif;font-size:1rem;">
                        <option value="">— select —</option>
                        <?php foreach (['drive-up'=>'Drive-Up','easy'=>'Easy','moderate'=>'Moderate','hard'=>'Hard / Strenuous'] as $val=>$lbl): ?>
                            <option value="<?= $val ?>" <?= ($scraped['difficulty'] ?? '') === $val ? 'selected' : '' ?>><?= $lbl ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <?php if ($scraped && in_array('trailhead', $scraped['fields_found'] ?? [])): ?>
                <p style="font-size:0.82rem; color:#2E7D32; margin-bottom:1rem;">
                    📍 Trailhead coordinates also captured: <?= $scraped['trailhead_lat'] ?>, <?= $scraped['trailhead_lng'] ?>
                </p>
                <?php endif; ?>

                <div style="margin-top: 1.5rem; display: flex; gap: 1rem;">
                    <button type="submit" name="save_trail_data" class="btn">Save Trail Data</button>
                    <a href="summit_detail.php?id=<?= $summit_id ?>&group=<?= $current_group['id'] ?>" class="btn btn-secondary">Skip for Now</a>
                </div>
            </form>
        </div>

        <div class="card" style="background: #FFF4E6; border-left: 4px solid var(--summit-gold);">
            <h3 style="color: var(--peak-brown); margin-bottom: 1rem;">💡 Tips for Finding the Right Trail</h3>
            <ul style="margin-left: 1.5rem; line-height: 1.8; color: #666;">
                <li><strong>AllTrails:</strong> Use the map view and zoom to the summit coordinates. Look for trails that go to or pass through the summit.</li>
                <li><strong>Check Elevation:</strong> The trail's highest point should match the summit elevation (<?= number_format($summit['elevation_ft']) ?> ft).</li>
                <li><strong>Read Reviews:</strong> Trail reviews often mention if the trail goes to the summit or a nearby peak.</li>
                <li><strong>Multiple Routes:</strong> Some summits have several trails. Pick the most popular or one that matches your skill level.</li>
                <li><strong>Trail Names:</strong> The trail might not be named after the summit. Look for trails in the same area.</li>
            </ul>
        </div>
    </div>
<footer style="text-align:center; padding:2rem 1rem 1.5rem; color:#aaa; font-size:0.78rem;">
    SOTA Planner &nbsp;·&nbsp; <a href="changelog.php" style="color:#aaa; text-decoration:none;">v<?= APP_VERSION ?></a> &nbsp;·&nbsp; <a href="https://sotaplanner.com" style="color:#aaa; text-decoration:none;">sotaplanner.com</a>
</footer>
</body>
</html>
