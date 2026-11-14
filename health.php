<?php
/**
 * Operational System Health Check Endpoint
 *
 * Authorization:
 *   - CLI execution, OR
 *   - Active Super Admin / Admin session, OR
 *   - Matching HEALTH_CHECK_TOKEN via X-Health-Token / Authorization: Bearer / ?token=
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');

function health_extract_token(): string {
    $headerTok = trim((string)($_SERVER['HTTP_X_HEALTH_TOKEN'] ?? ''));
    if ($headerTok !== '') return $headerTok;

    $authHdr = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''));
    if (preg_match('/^\s*Bearer\s+(.+)$/i', $authHdr, $m)) {
        return trim($m[1]);
    }
    return trim((string)($_GET['token'] ?? ''));
}

$authorized = (PHP_SAPI === 'cli');
if (!$authorized && defined('HEALTH_CHECK_TOKEN') && HEALTH_CHECK_TOKEN !== '') {
    $supplied = health_extract_token();
    if ($supplied !== '' && hash_equals((string)HEALTH_CHECK_TOKEN, $supplied)) {
        $authorized = true;
    }
}
if (
    !$authorized
    && !empty($_SESSION['user'])
    && auth_validate_active_session(false)
    && in_array($_SESSION['user']['role_name'] ?? '', ['Super Admin', 'Admin'], true)
) {
    $authorized = true;
}

if (!$authorized) {
    http_response_code(403);
    echo json_encode(['status' => 'forbidden', 'error' => 'Unauthorized']);
    exit;
}

release_session_lock();

$checks  = [];
$healthy = true;

// 1. Database connectivity, latency & schema completeness
$dbStart = microtime(true);
try {
    $db = getDB();
    $res = $db->query('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()');
    $tableCount = $res ? (int)$res->fetch_row()[0] : 0;
    $latencyMs  = round((microtime(true) - $dbStart) * 1000, 2);
    $dbOk       = ($tableCount >= 30);
    if (!$dbOk) $healthy = false;
    $checks['database'] = [
        'status'     => $dbOk ? 'ok' : 'degraded',
        'latency_ms' => $latencyMs,
        'tables'     => $tableCount,
    ];
} catch (Throwable $e) {
    $healthy = false;
    $checks['database'] = [
        'status' => 'fail',
        'error'  => 'Database probe failed',
    ];
}

// 2. Critical PHP extensions
$reqExts = ['mysqli', 'zlib', 'mbstring', 'fileinfo'];
$extStatus = [];
$extsOk = true;
foreach ($reqExts as $ext) {
    $loaded = extension_loaded($ext);
    $extStatus[$ext] = $loaded;
    if (!$loaded) {
        $extsOk  = false;
        $healthy = false;
    }
}
$checks['extensions'] = [
    'status' => $extsOk ? 'ok' : 'fail',
    'loaded' => $extStatus,
];

// 3. Writable runtime storage paths (without exposing full filesystem paths)
$storageDirs = ['uploads', 'uploads/items', 'logs', 'backups'];
$storageOk   = true;
$dirStatus   = [];
foreach ($storageDirs as $relDir) {
    $abs = __DIR__ . '/' . $relDir;
    if (!is_dir($abs)) {
        @mkdir($abs, 0755, true);
    }
    $ok = is_dir($abs) && is_writable($abs);
    $dirStatus[$relDir] = $ok;
    if (!$ok) {
        $storageOk = false;
        $healthy   = false;
    }
}
$checks['storage'] = [
    'status' => $storageOk ? 'ok' : 'fail',
    'dirs'   => $dirStatus,
];

// 4. Latest database backup status in backups/
$backupFiles = glob(__DIR__ . '/backups/*.{sql,sql.gz,zip}', GLOB_BRACE) ?: [];
$latestMtime = 0;
$latestFile  = null;
$latestSize  = 0;
foreach ($backupFiles as $bf) {
    if (!is_file($bf)) continue;
    $mt = (int)@filemtime($bf);
    if ($mt > $latestMtime) {
        $latestMtime = $mt;
        $latestFile  = basename($bf);
        $latestSize  = (int)@filesize($bf);
    }
}
$ageHours = $latestMtime > 0 ? round((time() - $latestMtime) / 3600, 1) : null;
$checks['backup'] = [
    'status'           => $latestMtime === 0 ? 'none' : ($ageHours <= 36.0 ? 'ok' : 'stale'),
    'count'            => count($backupFiles),
    'latest_file'      => $latestFile,
    'latest_size_bytes'=> $latestSize,
    'latest_age_hours' => $ageHours,
];

http_response_code($healthy ? 200 : 503);
echo json_encode([
    'status'    => $healthy ? 'ok' : 'fail',
    'app'       => APP_NAME,
    'version'   => APP_VERSION,
    'timestamp' => gmdate('c'),
    'checks'    => $checks,
], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
