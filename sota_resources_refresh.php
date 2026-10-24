<?php
/**
 * Background endpoint: SOTA articles and links for one summit. Called via fetch() from summit_detail.php
 * after page load so the page never waits on SOTA.
 *
 * GET params: ref (SOTA reference, e.g. W6/CT-019)
 * Returns JSON: { ok, items: [{title, url, article, callsign, ts}] }
 */
require_once 'config.php';
require_once 'sota_resources_lib.php';
session_start();
requireLogin();
session_write_close();

header('Content-Type: application/json');

$ref = trim($_GET['ref'] ?? '');
if (!preg_match('~^[A-Za-z0-9]+/[A-Za-z0-9]+-\d+$~', $ref)) {
    echo json_encode(['ok' => false, 'error' => 'bad ref']);
    exit;
}

echo json_encode(['ok' => true, 'items' => sota_resources_for_ref(getDbConnection(), $ref)]);
