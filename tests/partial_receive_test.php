<?php
// Partial PO Receive Integration Tests
// Run: php -c /opt/data/cache/scratch/mariadb/php-ext.ini tests/partial_receive_test.php

if (!extension_loaded('mysqli')) {
    die("ERROR: mysqli extension required.\n");
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

require_once __DIR__ . '/../includes/purchase_orders.php';

echo "=== Partial PO Receive Tests ===\n\n";

// Fresh test DB
const TEST_DB = 'db_msp_warehouse_test_partial';
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

// Schema already includes received_quantity and updated status enum - no migration needed

// Seed test data
q($db, "INSERT INTO locations (id,code,name,is_active) VALUES (100,'A1','Test Location',1)");
q($db, "INSERT INTO suppliers (id,code,name,is_active) VALUES (100,'SUP001','Test Supplier',1)");
q($db, "INSERT INTO items (id,code,name,category_id,unit_id,min_stock,buy_price,is_active) VALUES (100,'ITEM001','Test Item 1',1,1,10,1000,1),(101,'ITEM002','Test Item 2',1,1,5,2000,1),(102,'ITEM003','Test Item 3',1,1,8,1500,1)");
q($db, "INSERT INTO purchase_orders (id,reference_no,supplier_id,location_id,user_id,status,approval_status,order_date) VALUES (100,'PO-PARTIAL-001',100,100,1,'pending','approved','2026-10-09')");
q($db, "INSERT INTO purchase_order_details (po_id,item_id,quantity,buy_price,received_quantity) VALUES (100,100,20,1000,0),(100,101,10,2000,0)");

echo "--- Test 1: Partial Receive ---\n";

// Receive partial quantities: item 100 = 15/20, item 101 = 5/10
try {
    $ref = receive_purchase_order_partial($db, 100, [100 => 15, 101 => 5], 1);
    test('Partial receive succeeds', true);
    test('Returns stock-in reference', str_starts_with($ref, 'SI-'));
} catch (Throwable $e) {
    test('Partial receive succeeds', false, $e->getMessage());
}

// Check PO status = partial
$po = $db->query("SELECT status FROM purchase_orders WHERE id=100")->fetch_assoc();
test('PO status = partial', $po['status'] === 'partial');

// Check received quantities updated
$details = $db->query("SELECT item_id, quantity, received_quantity FROM purchase_order_details WHERE po_id=100 ORDER BY item_id")->fetch_all(MYSQLI_ASSOC);
test('Item 100 received_quantity = 15', (int)$details[0]['received_quantity'] === 15);
test('Item 101 received_quantity = 5', (int)$details[1]['received_quantity'] === 5);

// Check stock created correctly
$stock100 = $db->query("SELECT quantity FROM stock WHERE item_id=100 AND location_id=100")->fetch_assoc();
$stock101 = $db->query("SELECT quantity FROM stock WHERE item_id=101 AND location_id=100")->fetch_assoc();
test('Item 100 stock = 15', (int)$stock100['quantity'] === 15);
test('Item 101 stock = 5', (int)$stock101['quantity'] === 5);

echo "\n--- Test 2: Complete Remaining Items ---\n";

// Receive remaining: item 100 = 5 more (20 total), item 101 = 5 more (10 total)
try {
    $ref2 = receive_purchase_order_partial($db, 100, [100 => 5, 101 => 5], 1);
    test('Second partial receive succeeds', true);
} catch (Throwable $e) {
    test('Second partial receive succeeds', false, $e->getMessage());
}

// Check PO status = completed (all items fully received)
$po = $db->query("SELECT status FROM purchase_orders WHERE id=100")->fetch_assoc();
test('PO status = completed', $po['status'] === 'completed');

// Check received quantities = ordered quantities
$details = $db->query("SELECT item_id, quantity, received_quantity FROM purchase_order_details WHERE po_id=100 ORDER BY item_id")->fetch_all(MYSQLI_ASSOC);
test('Item 100 received_quantity = 20', (int)$details[0]['received_quantity'] === 20);
test('Item 101 received_quantity = 10', (int)$details[1]['received_quantity'] === 10);

// Check stock cumulative
$stock100 = $db->query("SELECT quantity FROM stock WHERE item_id=100 AND location_id=100")->fetch_assoc();
$stock101 = $db->query("SELECT quantity FROM stock WHERE item_id=101 AND location_id=100")->fetch_assoc();
test('Item 100 total stock = 20', (int)$stock100['quantity'] === 20);
test('Item 101 total stock = 10', (int)$stock101['quantity'] === 10);

echo "\n--- Test 3: Validation - Over-receive Prevention ---\n";

// Try to receive more than ordered
q($db, "INSERT INTO purchase_orders (id,reference_no,supplier_id,location_id,user_id,status,approval_status,order_date) VALUES (101,'PO-OVERRCV',100,100,1,'pending','approved','2026-10-09')");
q($db, "INSERT INTO purchase_order_details (po_id,item_id,quantity,buy_price,received_quantity) VALUES (101,100,10,1000,5)");

try {
    receive_purchase_order_partial($db, 101, [100 => 10], 1); // Already received 5, trying to receive 10 more = 15 total > 10 ordered
    test('Over-receive blocked', false, 'Should have thrown exception');
} catch (RuntimeException $e) {
    test('Over-receive blocked', str_contains($e->getMessage(), 'melebihi'));
}

echo "\n--- Test 4: Zero Quantity Handling ---\n";

// Receive with zero quantity should be skipped
q($db, "INSERT INTO purchase_orders (id,reference_no,supplier_id,location_id,user_id,status,approval_status,order_date) VALUES (102,'PO-ZERO',100,100,1,'pending','approved','2026-10-09')");
q($db, "INSERT INTO purchase_order_details (po_id,item_id,quantity,buy_price,received_quantity) VALUES (102,100,10,1000,0),(102,101,5,2000,0)");

try {
    $ref3 = receive_purchase_order_partial($db, 102, [100 => 10, 101 => 0], 1); // Only receive item 100
    test('Zero quantity handled', true);
} catch (Throwable $e) {
    test('Zero quantity handled', false, $e->getMessage());
}

$po102 = $db->query("SELECT status FROM purchase_orders WHERE id=102")->fetch_assoc();
test('PO status = partial (not completed)', $po102['status'] === 'partial');

$d102 = $db->query("SELECT item_id, received_quantity FROM purchase_order_details WHERE po_id=102 ORDER BY item_id")->fetch_all(MYSQLI_ASSOC);
test('Item 100 received = 10', (int)$d102[0]['received_quantity'] === 10);
test('Item 101 received = 0', (int)$d102[1]['received_quantity'] === 0);

echo "\n--- Test 5: Full Receive in One Go ---\n";

// Receive all items at once (backwards compatibility)
q($db, "INSERT INTO purchase_orders (id,reference_no,supplier_id,location_id,user_id,status,approval_status,order_date) VALUES (103,'PO-FULL',100,100,1,'pending','approved','2026-10-09')");
q($db, "INSERT INTO purchase_order_details (po_id,item_id,quantity,buy_price,received_quantity) VALUES (103,100,20,1000,0),(103,101,10,2000,0)");

try {
    $ref4 = receive_purchase_order_partial($db, 103, [100 => 20, 101 => 10], 1);
    test('Full receive succeeds', true);
} catch (Throwable $e) {
    test('Full receive succeeds', false, $e->getMessage());
}

$po103 = $db->query("SELECT status FROM purchase_orders WHERE id=103")->fetch_assoc();
test('PO status = completed immediately', $po103['status'] === 'completed');

echo "\n=== Summary ===\n";
echo "Passed: $passed\n";
echo "Failed: $failed\n\n";

exit($failed > 0 ? 1 : 0);
