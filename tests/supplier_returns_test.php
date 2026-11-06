<?php
// Integration test: Supplier returns stock checking, cancellation, and idempotency
// Run: php -c /opt/data/cache/scratch/mariadb/php-ext.ini tests/supplier_returns_test.php

if (!extension_loaded('mysqli')) {
    die("ERROR: mysqli extension required. Run with:\n  php -c /opt/data/cache/scratch/mariadb/php-ext.ini tests/supplier_returns_test.php\n");
}

error_reporting(E_ALL);
ini_set('display_errors', '1');

$passed = $failed = 0;
function test(string $name, bool $pass, string $reason = ''): void {
    global $passed, $failed;
    if ($pass) { $passed++; echo "✓ $name\n"; }
    else { $failed++; echo "✗ $name\n  $reason\n"; }
}

// Override DB config for test
define('DB_HOST', '127.0.0.1');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'db_msp_warehouse');
define('DB_PORT', 13306);
define('DB_CHARSET', 'utf8mb4');

function getDB(): mysqli {
    static $conn = null;
    if ($conn) return $conn;
    mysqli_report(MYSQLI_REPORT_OFF);
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
    if ($conn->connect_error) die("DB connection failed: " . $conn->connect_error);
    $conn->set_charset(DB_CHARSET);
    return $conn;
}

function generate_ref(string $prefix): string {
    return $prefix . date('Ymd') . strtoupper(substr(uniqid(), -5));
}

require_once __DIR__ . '/../includes/supplier_returns.php';

echo "=== Supplier Returns Integration Tests ===\n\n";

// Fresh test DB
const TEST_DB = 'db_msp_warehouse_test_returns';
$boot = new mysqli(DB_HOST, DB_USER, DB_PASS, '', DB_PORT);
if ($boot->connect_error) die("DB connection failed: " . $boot->connect_error . "\n");
$boot->query("DROP DATABASE IF EXISTS `" . TEST_DB . "`");
$schema = str_replace('db_msp_warehouse', TEST_DB, file_get_contents(__DIR__ . '/../database/schema.sql'));
if (!$boot->multi_query($schema)) die("schema load failed: " . $boot->error . "\n");
do { if ($r = $boot->store_result()) $r->free(); if ($boot->errno) die("schema stmt failed: " . $boot->error . "\n"); } while ($boot->more_results() && $boot->next_result());
$boot->close();

$db = new mysqli(DB_HOST, DB_USER, DB_PASS, TEST_DB, DB_PORT);
$db->set_charset(DB_CHARSET);
function q(mysqli $db, string $sql): void { if ($db->query($sql) === false) die("SQL failed: {$db->error}\n$sql\n"); }

q($db, "INSERT INTO locations (id,code,name,is_active) VALUES (100,'A1','Test Location',1)");
q($db, "INSERT INTO suppliers (id,code,name,is_active) VALUES (100,'SUP001','Test Supplier',1)");
q($db, "INSERT INTO items (id,code,name,category_id,unit_id,min_stock,buy_price,is_active) VALUES (100,'ITEM001','Test Item 1',1,1,10,1000,1),(101,'ITEM002','Test Item 2',1,1,5,2000,1)");
q($db, "INSERT INTO stock (item_id,location_id,quantity) VALUES (100,100,50),(101,100,20)");

echo "--- Test 1: Create Return with Stock Check ---\n";

$items = supplier_return_items([100,101], [10,5], [1000,2000]);
try {
    $ret_id = create_supplier_return($db, 100, 100, 1, '2026-10-09', 'Barang rusak', 'Test return', $items);
    test('Return created', $ret_id > 0);
} catch (Throwable $e) {
    test('Return created', false, $e->getMessage());
    exit(1);
}

// Check stock decremented
$s1 = $db->query("SELECT quantity FROM stock WHERE item_id=100 AND location_id=100")->fetch_assoc();
$s2 = $db->query("SELECT quantity FROM stock WHERE item_id=101 AND location_id=100")->fetch_assoc();
test('Item 1 stock decremented (50-10=40)', (int)$s1['quantity'] === 40);
test('Item 2 stock decremented (20-5=15)', (int)$s2['quantity'] === 15);

// Check mutations logged
$mut_count = (int)$db->query("SELECT COUNT(*) FROM mutations WHERE reference_type='supplier_return' AND reference_id=$ret_id")->fetch_row()[0];
test('Mutations logged (2 items)', $mut_count === 2);

// Check mutation type is 'out'
$mut_type = $db->query("SELECT type FROM mutations WHERE reference_type='supplier_return' AND reference_id=$ret_id LIMIT 1")->fetch_assoc();
test('Mutation type is out', $mut_type['type'] === 'out');

// Check details saved
$det_count = (int)$db->query("SELECT COUNT(*) FROM supplier_return_details WHERE return_id=$ret_id")->fetch_row()[0];
test('Details saved (2 items)', $det_count === 2);

echo "\n--- Test 2: Stock Insufficient Check ---\n";

try {
    create_supplier_return($db, 100, 100, 1, '2026-10-09', 'Test', '', supplier_return_items([100], [50], [1000]));
    test('Blocks insufficient stock', false, 'Should have thrown exception');
} catch (RuntimeException $e) {
    test('Blocks insufficient stock', str_contains($e->getMessage(), 'tidak cukup'));
    test('Error shows item name', str_contains($e->getMessage(), 'Test Item'));
}

// Stock unchanged after failed create
$s1_after = $db->query("SELECT quantity FROM stock WHERE item_id=100 AND location_id=100")->fetch_assoc();
test('Stock unchanged after failed create', (int)$s1_after['quantity'] === 40);

echo "\n--- Test 3: Cancel Return Restores Stock ---\n";

try {
    cancel_supplier_return($db, $ret_id, 2);
    test('Cancel succeeds', true);
} catch (Throwable $e) {
    test('Cancel succeeds', false, $e->getMessage());
}

// Check status changed
$status = $db->query("SELECT status FROM supplier_returns WHERE id=$ret_id")->fetch_assoc();
test('Status = cancelled', $status['status'] === 'cancelled');

// Check stock restored
$s1_restored = $db->query("SELECT quantity FROM stock WHERE item_id=100 AND location_id=100")->fetch_assoc();
$s2_restored = $db->query("SELECT quantity FROM stock WHERE item_id=101 AND location_id=100")->fetch_assoc();
test('Item 1 stock restored (40+10=50)', (int)$s1_restored['quantity'] === 50);
test('Item 2 stock restored (15+5=20)', (int)$s2_restored['quantity'] === 20);

// Check reversal mutation logged
$reversal = $db->query("SELECT COUNT(*) FROM mutations WHERE reference_type='supplier_return' AND reference_id=$ret_id AND type='in'")->fetch_row()[0];
test('Reversal mutations logged (type=in)', (int)$reversal === 2);

echo "\n--- Test 4: Double Cancel Blocked ---\n";

try {
    cancel_supplier_return($db, $ret_id, 2);
    test('Blocks double cancel', false, 'Should have thrown exception');
} catch (RuntimeException $e) {
    test('Blocks double cancel', str_contains($e->getMessage(), 'sudah dibatalkan'));
}

// Stock still at restored level
$s1_final = $db->query("SELECT quantity FROM stock WHERE item_id=100 AND location_id=100")->fetch_assoc();
test('Stock not doubled after double cancel', (int)$s1_final['quantity'] === 50);

echo "\n--- Test 5: Concurrent Return Race (Row Lock) ---\n";

q($db, "INSERT INTO items (id,code,name,category_id,unit_id,buy_price,is_active) VALUES (102,'ITEM003','Race Item',1,1,3000,1)");
q($db, "INSERT INTO stock (item_id,location_id,quantity) VALUES (102,100,30)");

$holder = new mysqli(DB_HOST, DB_USER, DB_PASS, TEST_DB, DB_PORT);
$contender = new mysqli(DB_HOST, DB_USER, DB_PASS, TEST_DB, DB_PORT);
$contender->query("SET SESSION innodb_lock_wait_timeout=1");

$holder->begin_transaction();
$holder->query("SELECT quantity FROM stock WHERE item_id=102 AND location_id=100 FOR UPDATE");

try {
    create_supplier_return($contender, 100, 100, 3, '2026-10-09', 'Race test', '', supplier_return_items([102], [15], [3000]));
    test('Concurrent return blocked by lock', false, 'Second return was not blocked');
} catch (Throwable $e) {
    test('Concurrent return blocked by lock', true);
}
$holder->rollback();

// Now succeeds
$race_id = create_supplier_return($contender, 100, 100, 3, '2026-10-09', 'Race test', '', supplier_return_items([102], [15], [3000]));
test('Return succeeds once lock released', $race_id > 0);
$s_race = $db->query("SELECT quantity FROM stock WHERE item_id=102 AND location_id=100")->fetch_assoc();
test('Race item stock correct (30-15=15)', (int)$s_race['quantity'] === 15);

echo "\n--- Test 6: Item Sort Order for Lock Safety ---\n";

$items_unsorted = [[101, 1, 2000], [100, 1, 1000]];
$sorted = supplier_return_items(array_column($items_unsorted, 0), array_column($items_unsorted, 1), array_column($items_unsorted, 2));
test('Items sorted by ID', $sorted[0][0] === 100 && $sorted[1][0] === 101, "Got [{$sorted[0][0]}, {$sorted[1][0]}]");

echo "\n=== Summary ===\n";
echo "Passed: $passed\n";
echo "Failed: $failed\n\n";

exit($failed > 0 ? 1 : 0);
