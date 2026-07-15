<?php
/**
 * SOTA OAuth 2.0 / OpenID Connect Callback Handler
 *
 * Endpoints (Keycloak realm "SOTA"):
 *   Auth:     https://sso.sota.org.uk/auth/realms/SOTA/protocol/openid-connect/auth
 *   Token:    https://sso.sota.org.uk/auth/realms/SOTA/protocol/openid-connect/token
 *   UserInfo: https://sso.sota.org.uk/auth/realms/SOTA/protocol/openid-connect/userinfo
 *   Logout:   https://sso.sota.org.uk/auth/realms/SOTA/protocol/openid-connect/logout
 *
 * To activate:
 *  1. Register at reflector.sota.org.uk and join the 'API-consumers' group
 *  2. Request a clientId + clientSecret from the SOTA development team
 *  3. Add SOTA_CLIENT_ID and SOTA_CLIENT_SECRET to sotaplanner_secrets.php
 *  4. Set $sota_oauth_enabled = true in login.php
 *  5. Update SOTA_REDIRECT_URI below to match your deployed URL
 */

require_once 'config.php';
session_start();

define('SOTA_AUTH_URL',     'https://sso.sota.org.uk/auth/realms/SOTA/protocol/openid-connect/auth');
define('SOTA_TOKEN_URL',    'https://sso.sota.org.uk/auth/realms/SOTA/protocol/openid-connect/token');
define('SOTA_USERINFO_URL', 'https://sso.sota.org.uk/auth/realms/SOTA/protocol/openid-connect/userinfo');
define('SOTA_REDIRECT_URI', 'https://sotaplanner.com/oauth_callback.php');

// ── Step 1: Redirect browser to SOTA SSO ──────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'login') {
    if (!defined('SOTA_CLIENT_ID') || SOTA_CLIENT_ID === '') {
        die('SOTA OAuth is not configured. Add SOTA_CLIENT_ID to sotaplanner_secrets.php.');
    }

    $state = bin2hex(random_bytes(16));

    // Store state in DB — cookies and sessions are unreliable across OAuth redirects in Safari
    $db = getDbConnection();
    $db->prepare("INSERT INTO app_settings (setting_key, setting_value, updated_at) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value), updated_at=NOW()")
       ->execute(['oauth_state_' . $state, time() + 600]);

    $params = [
        'response_type' => 'code',
        'client_id'     => SOTA_CLIENT_ID,
        'redirect_uri'  => SOTA_REDIRECT_URI,
        'scope'         => 'openid profile',
        'state'         => $state,
    ];

    header('Location: ' . SOTA_AUTH_URL . '?' . http_build_query($params));
    exit;
}

// ── Step 2: Handle callback from SOTA SSO ─────────────────────────────────
if (isset($_GET['code'])) {
    $debug = defined('SOTA_SSO_DEBUG') && SOTA_SSO_DEBUG;
    if ($debug) {
        echo "<pre style='background:#1a1a2e;color:#00ff88;padding:1rem;font-size:13px;'>";
        echo "=== SOTA SSO DEBUG ===\n";
        echo "code present: yes\n";
        echo "state param:  " . htmlspecialchars($_GET['state'] ?? '(none)') . "\n";
        echo "error param:  " . htmlspecialchars($_GET['error'] ?? '(none)') . "\n";
        echo "</pre>";
    }
    // Validate state via DB — immune to Safari ITP and shared-hosting session issues
    $incoming_state = $_GET['state'] ?? '';
    if (empty($incoming_state)) {
        http_response_code(403);
        die('OAuth error: no state parameter returned from SOTA.');
    }
    $db = getDbConnection();
    $stmt = $db->prepare("SELECT setting_value FROM app_settings WHERE setting_key = ?");
    $stmt->execute(['oauth_state_' . $incoming_state]);
    $row = $stmt->fetch();
    if (!$row || (int)$row['setting_value'] < time()) {
        http_response_code(403);
        die('OAuth state invalid or expired. Please try logging in again.');
    }
    $db->prepare("DELETE FROM app_settings WHERE setting_key = ?")->execute(['oauth_state_' . $incoming_state]);

    if (!defined('SOTA_CLIENT_ID')) {
        die('SOTA OAuth credentials are not configured.');
    }

    // Exchange code for tokens
    $token_data = [
        'grant_type'   => 'authorization_code',
        'code'         => $_GET['code'],
        'redirect_uri' => SOTA_REDIRECT_URI,
        'client_id'    => SOTA_CLIENT_ID,
    ];
    if (defined('SOTA_CLIENT_SECRET') && SOTA_CLIENT_SECRET !== '') {
        $token_data['client_secret'] = SOTA_CLIENT_SECRET;
    }

    $ch = curl_init(SOTA_TOKEN_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($token_data),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $response  = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code !== 200 || !$response) {
        error_log("SOTA token exchange failed: HTTP $http_code — $response");
        if ($debug) {
            die("<pre>TOKEN EXCHANGE FAILED\nHTTP: $http_code\nResponse: " . htmlspecialchars($response) . "\nRequest params: " . htmlspecialchars(http_build_query($token_data)) . "</pre>");
        }
        die('Login failed: could not exchange authorization code. Please try again.');
    }

    $tokens = json_decode($response, true);
    if ($debug) {
        echo "<pre>TOKEN RESPONSE (HTTP $http_code):\n" . htmlspecialchars(json_encode($tokens, JSON_PRETTY_PRINT)) . "</pre>";
    }

    if (empty($tokens['access_token'])) {
        error_log("SOTA token response missing access_token: $response");
        if ($debug) {
            die("<pre>NO ACCESS TOKEN\nFull response: " . htmlspecialchars($response) . "</pre>");
        }
        die('Login failed: no access token received.');
    }

    // Decode id_token JWT payload — preferred_username is in the token, no UserInfo round-trip needed.
    // SOTA's realm does not include a custom "callsign" claim; preferred_username is the callsign (lowercase).
    $id_token_parts = explode('.', $tokens['id_token'] ?? '');
    $id_payload = [];
    if (count($id_token_parts) === 3) {
        $raw  = str_replace(['-', '_'], ['+', '/'], $id_token_parts[1]);
        $json = base64_decode(str_pad($raw, strlen($raw) + (4 - strlen($raw) % 4) % 4, '='));
        $id_payload = ($json && ($decoded = json_decode($json, true))) ? $decoded : [];
    }

    if ($debug) {
        echo "<pre>ID_TOKEN PAYLOAD:\n" . htmlspecialchars(json_encode($id_payload, JSON_PRETTY_PRINT)) . "</pre>";
    }

    $callsign = strtoupper(trim($id_payload['preferred_username'] ?? ''));

    if (empty($callsign)) {
        error_log("SOTA id_token missing preferred_username: " . json_encode($id_payload));
        if ($debug) {
            die("<pre>NO CALLSIGN IN ID_TOKEN\nDecoded payload: " . htmlspecialchars(json_encode($id_payload, JSON_PRETTY_PRINT)) . "</pre>");
        }
        die('Login failed: could not retrieve callsign from SOTA account.');
    }

    if ($debug) {
        die("<pre>DEBUG: Login would succeed for callsign: $callsign\nClick <a href='index.php'>here</a> to skip debug and continue normally (disable SOTA_SSO_DEBUG first).</pre>");
    }

    // Store in session
    $_SESSION['sota_callsign']              = $callsign;
    $_SESSION['sota_login_type']            = 'sota_oauth';
    $_SESSION['sota_access_token']          = $tokens['access_token'];
    $_SESSION['sota_id_token']              = $tokens['id_token'] ?? null;
    $_SESSION['sota_refresh_token']         = $tokens['refresh_token'] ?? null;
    $_SESSION['sota_token_expires']         = time() + ($tokens['expires_in'] ?? 300);
    $_SESSION['sota_sso_sub']               = $id_payload['sub'] ?? null;
    $_SESSION['sota_sso_preferred_username'] = $id_payload['preferred_username'] ?? null;

    // Check if this user has confirmed their callsign yet
    $stmt = $db->prepare("SELECT callsign_confirmed FROM users WHERE callsign = ?");
    $stmt->execute([$callsign]);
    $user_row = $stmt->fetch();
    if (!$user_row || !(int)$user_row['callsign_confirmed']) {
        // If the SSO username itself looks like a valid callsign, auto-confirm silently —
        // no need to interrupt the user with a confirmation page.
        $sso_username = $id_payload['preferred_username'] ?? '';
        if (preg_match('/^[A-Z0-9]{3,10}$/i', $sso_username)) {
            $db->prepare("
                INSERT INTO users (callsign, callsign_confirmed, sso_sub)
                VALUES (?, 1, ?)
                ON DUPLICATE KEY UPDATE callsign_confirmed = 1, sso_sub = VALUES(sso_sub)
            ")->execute([$callsign, $id_payload['sub'] ?? null]);
        } else {
            header('Location: callsign_confirm.php');
            exit;
        }
    }

    // Find user's planning groups
    $stmt = $db->prepare("
        SELECT pg.id FROM planning_groups pg
        LEFT JOIN planning_group_members pgm ON pg.id = pgm.planning_group_id
        WHERE pg.owner_callsign = ? OR pgm.callsign = ?
        ORDER BY pg.id ASC
    ");
    $stmt->execute([$callsign, $callsign]);
    $groups = $stmt->fetchAll();

    if (empty($groups)) {
        // New user — send to onboarding wizard
        header('Location: onboarding.php');
        exit;
    }

    // Try to restore last-used group from cookie (may be gone in Safari — fall back to first group)
    $target_group_id = $groups[0]['id'];
    if (!empty($_COOKIE['sota_default_group'])) {
        $cookie_id = (int)$_COOKIE['sota_default_group'];
        foreach ($groups as $g) {
            if ((int)$g['id'] === $cookie_id) {
                $target_group_id = $cookie_id;
                break;
            }
        }
    }

    setCurrentPlanningGroup($target_group_id);
    header('Location: index.php');
    exit;
}

// ── Error / unknown state ──────────────────────────────────────────────────
if (isset($_GET['error'])) {
    $err = htmlspecialchars($_GET['error_description'] ?? $_GET['error']);
    die("SOTA login error: $err");
}

// Fallback
header('Location: login.php');
exit;
