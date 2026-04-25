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
    $_SESSION['oauth_state'] = $state;

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
    // Validate state to prevent CSRF
    if (!isset($_GET['state']) || $_GET['state'] !== ($_SESSION['oauth_state'] ?? '')) {
        http_response_code(403);
        die('OAuth state mismatch. Please try logging in again.');
    }
    unset($_SESSION['oauth_state']);

    if (!defined('SOTA_CLIENT_ID') || !defined('SOTA_CLIENT_SECRET')) {
        die('SOTA OAuth credentials are not configured.');
    }

    // Exchange code for tokens
    $token_data = [
        'grant_type'    => 'authorization_code',
        'code'          => $_GET['code'],
        'redirect_uri'  => SOTA_REDIRECT_URI,
        'client_id'     => SOTA_CLIENT_ID,
        'client_secret' => SOTA_CLIENT_SECRET,
    ];

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
        die('Login failed: could not exchange authorization code. Please try again.');
    }

    $tokens = json_decode($response, true);
    if (empty($tokens['access_token'])) {
        error_log("SOTA token response missing access_token: $response");
        die('Login failed: no access token received.');
    }

    // Fetch user profile from userinfo endpoint
    $ch = curl_init(SOTA_USERINFO_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $tokens['access_token']],
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $userinfo_raw = curl_exec($ch);
    curl_close($ch);

    $userinfo = json_decode($userinfo_raw, true);

    // Callsign is the preferred_username in SOTA's Keycloak
    $callsign = strtoupper(trim($userinfo['preferred_username'] ?? $userinfo['sub'] ?? ''));

    if (empty($callsign)) {
        error_log("SOTA userinfo missing callsign: $userinfo_raw");
        die('Login failed: could not retrieve callsign from SOTA account.');
    }

    // Store in session
    $_SESSION['sota_callsign']      = $callsign;
    $_SESSION['sota_login_type']    = 'oauth';
    $_SESSION['sota_access_token']  = $tokens['access_token'];
    $_SESSION['sota_refresh_token'] = $tokens['refresh_token'] ?? null;
    $_SESSION['sota_token_expires'] = time() + ($tokens['expires_in'] ?? 300);

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
