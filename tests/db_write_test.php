<?php
// Database health test: confirms the site is connected to a WRITABLE MySQL server.
// CLI only. Leaves no data behind (temporary table + a rolled-back transaction).
//
// Run on the server from the site folder:
//   cd /home/chrisr069/sotaplannerdotcom && php tests/db_write_test.php
// Or from your Mac without uploading anything:
//   ssh dreamhost-sota 'cd /home/chrisr069/sotaplannerdotcom && php' < tests/db_write_test.php

if (php_sapi_name() !== 'cli') { http_response_code(404); exit; }

chdir(getenv('SOTA_SITE_DIR') ?: '/home/chrisr069/sotaplannerdotcom');
require 'config.php';

$fails = 0;
function check($label, $ok, $detail = '') {
    global $fails;
    if (!$ok) $fails++;
    echo ($ok ? "PASS  " : "FAIL  ") . $label . ($detail !== '' ? "  ($detail)" : '') . "\n";
}

try {
    $db = getDbConnection();
    check('Connected using config.php', true, 'host ' . DB_HOST);
} catch (Throwable $e) {
    check('Connected using config.php', false, $e->getMessage());
    exit(1);
}

// 1. Server identity and read-only flags
$row = $db->query("SELECT @@hostname h, @@version v, @@read_only ro, @@super_read_only sro")->fetch(PDO::FETCH_ASSOC);
echo "      server {$row['h']}, MySQL {$row['v']}\n";
check('Server is NOT read-only', (int)$row['ro'] === 0 && (int)$row['sro'] === 0, "read_only={$row['ro']} super_read_only={$row['sro']}");
check('Host is a panel hostname, not a server name', stripos(DB_HOST, 'pdx1-mysql') === false, DB_HOST);

// 2. Real data is there
$n = (int)$db->query("SELECT COUNT(*) FROM summits")->fetchColumn();
check('summits table has data', $n > 0, "$n rows");

// 3. Temporary table: create, insert, update, delete (never touches real tables)
try {
    $db->exec("CREATE TEMPORARY TABLE _write_test (id INT PRIMARY KEY, val VARCHAR(20))");
    $db->exec("INSERT INTO _write_test VALUES (1, 'a')");
    $db->exec("UPDATE _write_test SET val = 'b' WHERE id = 1");
    $val = $db->query("SELECT val FROM _write_test WHERE id = 1")->fetchColumn();
    $db->exec("DELETE FROM _write_test WHERE id = 1");
    $left = (int)$db->query("SELECT COUNT(*) FROM _write_test")->fetchColumn();
    check('Temporary table insert/update/delete', $val === 'b' && $left === 0);
    $db->exec("DROP TEMPORARY TABLE _write_test");
} catch (Throwable $e) {
    check('Temporary table insert/update/delete', false, $e->getMessage());
}

// 4. A real table, inside a transaction that is rolled back (app_settings)
$key = '_db_write_test_' . bin2hex(random_bytes(4));
try {
    $engine = $db->query("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'app_settings'")->fetchColumn();
    check('app_settings supports transactions', strtoupper((string)$engine) === 'INNODB', (string)$engine);
    if (strtoupper((string)$engine) === 'INNODB') {
        $db->beginTransaction();
        $db->prepare("INSERT INTO app_settings (setting_key, setting_value) VALUES (?, 'x')")->execute([$key]);
        $db->prepare("UPDATE app_settings SET setting_value = 'y' WHERE setting_key = ?")->execute([$key]);
        $s = $db->prepare("SELECT setting_value FROM app_settings WHERE setting_key = ?");
        $s->execute([$key]);
        check('Real table insert/update inside transaction', $s->fetchColumn() === 'y');
        $db->rollBack();
        $s->execute([$key]);
        check('Rollback left nothing behind', $s->fetchColumn() === false);
    }
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    check('Real table write', false, $e->getMessage());
}

// 5. MySQL 8.4 reserved words used as table or column names (these need backticks in SQL)
$reserved = ['manual', 'parallel', 'qualify', 'tablesample'];
$in = "'" . implode("','", $reserved) . "'";
$hits = $db->query("SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND LOWER(COLUMN_NAME) IN ($in)")->fetchAll(PDO::FETCH_ASSOC);
$tbls = $db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND LOWER(TABLE_NAME) IN ($in)")->fetchAll(PDO::FETCH_COLUMN);
check('No columns or tables named with MySQL 8.4 reserved words', !$hits && !$tbls,
    $hits || $tbls ? json_encode([$hits, $tbls]) : '');

echo $fails === 0 ? "\nALL CHECKS PASSED\n" : "\n$fails CHECK(S) FAILED\n";
exit($fails === 0 ? 0 : 1);
