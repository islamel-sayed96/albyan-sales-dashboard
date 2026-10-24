<?php
/*
 * Shared storage for the Al-Bayan sales dashboard.
 * Keeps the whole dashboard data in one JSON file on the server so every
 * device (phone, laptop, ...) sees the same data.
 *
 *   GET  api.php            -> {"rev": n, "data": {...} | null}
 *   POST api.php {rev,data} -> saves when rev matches the server's rev,
 *                              otherwise 409 with the server's current copy.
 */

// Optional password. Leave empty for no password, or set one (e.g. 'Albayan@2026')
// and the dashboard will ask for it once on each device.
$PASSWORD = '';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

$dir  = __DIR__ . '/data';
$file = $dir . '/db.json';

if (!is_dir($dir)) {
    @mkdir($dir, 0755, true);
}
// Block direct browser access to the data folder.
if (!file_exists($dir . '/.htaccess')) {
    @file_put_contents($dir . '/.htaccess', "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
}
if (!file_exists($dir . '/index.html')) {
    @file_put_contents($dir . '/index.html', '');
}

function reply($code, $body) {
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($PASSWORD !== '') {
    $given = $_SERVER['HTTP_X_ACCESS_KEY'] ?? '';
    if (!hash_equals($PASSWORD, $given)) {
        reply(401, ['error' => 'unauthorized']);
    }
}

function readStore($file) {
    if (!file_exists($file)) return ['rev' => 0, 'data' => null];
    $raw = file_get_contents($file);
    $j = json_decode($raw, true);
    if (!is_array($j) || !isset($j['rev'])) return ['rev' => 0, 'data' => null];
    return $j;
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $fp = @fopen($file, 'c+');
    if ($fp) { flock($fp, LOCK_SH); }
    $store = readStore($file);
    if ($fp) { flock($fp, LOCK_UN); fclose($fp); }
    reply(200, $store);
}

if ($method === 'POST') {
    $in = json_decode(file_get_contents('php://input'), true);
    if (!is_array($in) || !isset($in['data']) || !is_array($in['data'])
        || !isset($in['data']['employees'], $in['data']['sales'], $in['data']['targets'])) {
        reply(400, ['error' => 'bad_request']);
    }
    $baseRev = (int)($in['rev'] ?? 0);

    $fp = fopen($file, 'c+');
    if (!$fp) reply(500, ['error' => 'cannot_open_storage']);
    flock($fp, LOCK_EX);
    $store = readStore($file);

    if ($baseRev !== (int)$store['rev']) {
        flock($fp, LOCK_UN); fclose($fp);
        reply(409, $store);
    }

    // Keep one previous copy as a safety net.
    if (file_exists($file) && filesize($file) > 0) {
        @copy($file, $dir . '/db.prev.json');
    }

    $new = ['rev' => (int)$store['rev'] + 1, 'saved_at' => date('c'), 'data' => $in['data']];
    $json = json_encode($new, JSON_UNESCAPED_UNICODE);
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, $json);
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    reply(200, ['rev' => $new['rev']]);
}

reply(405, ['error' => 'method_not_allowed']);
