<?php
// QRZ XML Data API helper. Credentials (QRZ_USERNAME / QRZ_PASSWORD) live in sotaplanner_secrets.php.
// Used only by God Mode for one-off lookups; results are shown on screen and never stored.

const QRZ_XML_URL = 'https://xmldata.qrz.com/xml/current/';

function qrz_request(array $params): ?SimpleXMLElement {
    $url = QRZ_XML_URL . '?' . http_build_query($params);
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    $body = curl_exec($ch);
    curl_close($ch);
    if ($body === false || $body === '') return null;
    // QRZ uses a default XML namespace; strip it so elements are reachable as ->Callsign etc.
    $body = preg_replace('/\sxmlns="[^"]*"/', '', $body, 1);
    $xml  = @simplexml_load_string($body);
    return $xml ?: null;
}

// Returns [sessionKey|null, errorMessage|null, subscriptionNote|null]
function qrz_login(): array {
    if (!defined('QRZ_USERNAME') || !defined('QRZ_PASSWORD')) {
        return [null, 'QRZ_USERNAME and QRZ_PASSWORD are not set in sotaplanner_secrets.php.', null];
    }
    $xml = qrz_request(['username' => QRZ_USERNAME, 'password' => QRZ_PASSWORD, 'agent' => 'SOTAplanner']);
    if (!$xml || !isset($xml->Session)) return [null, 'Could not reach QRZ.', null];
    if (!empty($xml->Session->Error)) return [null, (string)$xml->Session->Error, null];
    $key = (string)($xml->Session->Key ?? '');
    return $key !== '' ? [$key, null, (string)($xml->Session->SubExp ?? '')] : [null, 'QRZ returned no session key.', null];
}

// Returns ['ok' => bool, 'name' => ?, 'email' => ?, 'location' => ?, 'error' => ?]
function qrz_lookup(string $session_key, string $callsign): array {
    $xml = qrz_request(['s' => $session_key, 'callsign' => $callsign]);
    if (!$xml) return ['ok' => false, 'error' => 'No response from QRZ'];
    if (!empty($xml->Session->Error)) return ['ok' => false, 'error' => (string)$xml->Session->Error];
    $c = $xml->Callsign ?? null;
    if (!$c) return ['ok' => false, 'error' => 'Not found'];
    $name  = trim(((string)$c->fname) . ' ' . ((string)$c->name));
    $place = trim(implode(', ', array_filter([(string)$c->addr2, (string)$c->state, (string)$c->country])));
    return [
        'ok'       => true,
        'name'     => $name,
        'email'    => trim((string)$c->email),
        'location' => $place,
    ];
}
