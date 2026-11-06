<?php
// Regression tests for Barang Keluar / Transfer stock validation.
// Run: php -c /opt/data/cache/scratch/mariadb/php-ext.ini tests/location_stock_test.php
if (!extension_loaded('mysqli')) die("mysqli required\n");
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

defined('DB_HOST') || define('DB_HOST', '127.0.0.1');
defined('DB_USER') || define('DB_USER', 'root');
defined('DB_PASS') || define('DB_PASS', '');
defined('DB_NAME') || define('DB_NAME', 'db_wh_location_stock_test');
defined('DB_PORT') || define('DB_PORT', 13306);
defined('DB_CHARSET') || define('DB_CHARSET', 'utf8mb4');
defined('APP_NAME') || define('APP_NAME', 'Test');
defined('MAIL_FROM') || define('MAIL_FROM', 'test@example.com');
defined('MAIL_FROM_NAME') || define('MAIL_FROM_NAME', 'Test');

require_once __DIR__ . '/../includes/functions.php';

$boot = new mysqli(DB_HOST, DB_USER, DB_PASS, '', DB_PORT);
$boot->query('DROP DATABASE IF EXISTS `' . DB_NAME . '`');
$schema = str_replace('db_msp_warehouse', DB_NAME, file_get_contents(__DIR__ . '/../database/schema.sql'));
$boot->multi_query($schema);
do { if ($r = $boot->store_result()) $r->free(); } while ($boot->more_results() && $boot->next_result());
$boot->close();

$db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
$db->set_charset(DB_CHARSET);
$db->query("INSERT INTO items(id,code,name,category_id,unit_id,min_stock,is_active) VALUES(100,'KAOS-L','Kaos Polos Putih Ukuran L',1,1,2,1)");
$db->query("INSERT INTO stock(item_id,location_id,quantity) VALUES(100,1,10)");

$passed = $failed = 0;
function test(string $name, bool $ok, string $reason=''): void {
    global $passed, $failed;
    $ok ? $passed++ : $failed++;
    echo ($ok ? '✓' : '✗') . " $name" . ($ok ? '' : "\n  $reason") . "\n";
}

echo "=== Location Stock Validation Tests ===\n";

try {
    assert_location_stock_available($db, 1, [[100, 7, 0]]);
    test('Qty lebih kecil dari stok pada lokasi asal diterima', true);
} catch (Throwable $e) {
    test('Qty lebih kecil dari stok pada lokasi asal diterima', false, $e->getMessage());
}

try {
    assert_location_stock_available($db, 2, [[100, 1, 0]]);
    test('Lokasi tanpa baris stok ditolak', false, 'Tidak melempar error');
} catch (RuntimeException $e) {
    test('Lokasi tanpa baris stok ditolak', str_contains($e->getMessage(), 'Tersedia: 0'), $e->getMessage());
    test('Pesan menyebut lokasi asal', str_contains($e->getMessage(), 'RAK-A2'), $e->getMessage());
}

try {
    assert_location_stock_available($db, 1, [[100, 6, 0], [100, 5, 0]]);
    test('Qty duplikat diagregasi sebelum validasi', false, 'Total 11 lolos walau stok hanya 10');
} catch (RuntimeException $e) {
    test('Qty duplikat diagregasi sebelum validasi', str_contains(strtolower($e->getMessage()), 'diminta: 11'), $e->getMessage());
}

try {
    assert_location_stock_available($db, 1, [[100, 10, 0]]);
    test('Qty tepat sama dengan stok diterima', true);
} catch (Throwable $e) {
    test('Qty tepat sama dengan stok diterima', false, $e->getMessage());
}

echo "Passed: $passed\nFailed: $failed\n";
exit($failed ? 1 : 0);
