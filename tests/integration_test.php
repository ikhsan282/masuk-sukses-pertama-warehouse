<?php
// Integration tests: PO concurrency, barcode format, low-stock accuracy
// Run: php -c /opt/data/cache/scratch/mariadb/php-ext.ini tests/integration_test.php
// Or configure php.ini with mysqli extension and point DB to test server

if (!extension_loaded('mysqli')) {
    die("ERROR: mysqli extension required. Run with:\n  php -c /opt/data/cache/scratch/mariadb/php-ext.ini tests/integration_test.php\n");
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

echo "=== Integration Tests ===\n\n";

// Fresh test DB from canonical schema on every run (never touches the real DB)
const TEST_DB = 'db_msp_warehouse_test';
$boot = new mysqli(DB_HOST, DB_USER, DB_PASS, '', DB_PORT);
if ($boot->connect_error) die("DB connection failed: " . $boot->connect_error . "\n");
$boot->query("DROP DATABASE IF EXISTS `" . TEST_DB . "`");
$schema = str_replace('db_msp_warehouse', TEST_DB, file_get_contents(__DIR__ . '/../database/schema.sql'));
if (!$boot->multi_query($schema)) die("schema load failed: " . $boot->error . "\n");
do { if ($r = $boot->store_result()) $r->free(); if ($boot->errno) die("schema stmt failed: " . $boot->error . "\n"); } while ($boot->more_results() && $boot->next_result());
$boot->close();

$db = new mysqli(DB_HOST, DB_USER, DB_PASS, TEST_DB, DB_PORT);
$db->set_charset(DB_CHARSET);
function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES); }
function q(mysqli $db, string $sql): void { if ($db->query($sql) === false) die("SQL failed: {$db->error}\n$sql\n"); }

q($db, "INSERT INTO locations (id,code,name,is_active) VALUES (100,'A1','Test Location',1),(101,'B1','Second',1)");
q($db, "INSERT INTO suppliers (id,code,name,is_active) VALUES (100,'SUP001','Test Supplier',1)");
q($db, "INSERT INTO items (id,code,name,category_id,unit_id,min_stock,buy_price,is_active) VALUES (100,'ITEM001','Test Item 1',1,1,10,1000,1),(101,'ITEM002','Test Item 2',1,1,5,2000,1)");
q($db, "INSERT INTO purchase_orders (id,reference_no,supplier_id,location_id,user_id,status,approval_status,order_date) VALUES (100,'PO-TEST-001',100,100,1,'pending','approved','2026-10-09')");
q($db, "INSERT INTO purchase_order_details (po_id,item_id,quantity,buy_price) VALUES (100,100,20,1000),(100,101,10,2000)");

echo "--- Test 1: PO Concurrent Receive Safety ---\n";

// Test: First receive should succeed
try {
    $ref = receive_purchase_order($db, 100, 1);
    test('First receive succeeds', true);
    test('Returns stock-in reference', str_starts_with($ref, 'SI-'));
} catch (Throwable $e) {
    test('First receive succeeds', false, $e->getMessage());
}

// Check PO status changed
$po = $db->query("SELECT status FROM purchase_orders WHERE id=100")->fetch_assoc();
test('PO status = completed', $po['status'] === 'completed');

// Check stock_in created
$si_count = (int)$db->query("SELECT COUNT(*) FROM stock_in WHERE reference_no LIKE 'SI-PO-TEST-001%'")->fetch_row()[0];
test('Stock-in record created', $si_count === 1);

// Check stock updated
$stock1 = $db->query("SELECT quantity FROM stock WHERE item_id=100 AND location_id=100")->fetch_assoc();
$stock2 = $db->query("SELECT quantity FROM stock WHERE item_id=101 AND location_id=100")->fetch_assoc();
test('Item 1 stock = 20', (int)$stock1['quantity'] === 20);
test('Item 2 stock = 10', (int)$stock2['quantity'] === 10);

// Test: Second receive should fail (status no longer 'ordered')
try {
    receive_purchase_order($db, 100, 2);
    test('Second receive blocked', false, 'Should have thrown exception');
} catch (RuntimeException $e) {
    test('Second receive blocked', str_contains($e->getMessage(), 'sudah diproses'));
}

// Verify no double stock-in
$si_count_after = (int)$db->query("SELECT COUNT(*) FROM stock_in WHERE reference_no LIKE 'SI-PO-TEST-001%'")->fetch_row()[0];
test('No duplicate stock-in', $si_count_after === 1);

// Verify stock not doubled (before race PO adds stock)
$stock1_after = $db->query("SELECT quantity FROM stock WHERE item_id=100 AND location_id=100")->fetch_assoc();
test('Stock not doubled', (int)$stock1_after['quantity'] === 20);

// Real lock contention: a second connection holding FOR UPDATE on the PO must block receive
$holder = new mysqli(DB_HOST, DB_USER, DB_PASS, TEST_DB, DB_PORT);
$contender = new mysqli(DB_HOST, DB_USER, DB_PASS, TEST_DB, DB_PORT);
$contender->query("SET SESSION innodb_lock_wait_timeout=1");
q($db, "INSERT INTO purchase_orders (id,reference_no,supplier_id,location_id,user_id,status,approval_status,order_date) VALUES (101,'PO-RACE',100,100,1,'pending','approved','2026-10-09')");
q($db, "INSERT INTO purchase_order_details (po_id,item_id,quantity,buy_price) VALUES (101,100,7,1000)");
$holder->begin_transaction();
$holder->query("SELECT id FROM purchase_orders WHERE id=101 FOR UPDATE");
try {
    receive_purchase_order($contender, 101, 2);
    test('Concurrent receive blocked by row lock', false, 'Second receiver was not blocked');
} catch (Throwable $e) {
    test('Concurrent receive blocked by row lock', true);
}
$holder->rollback();
$ref2 = receive_purchase_order($contender, 101, 2);
test('Receive succeeds once lock released', str_starts_with($ref2, 'SI-'));
test('Exactly one stock-in for race PO', (int)$db->query("SELECT COUNT(*) FROM stock_in WHERE reference_no LIKE 'SI-PO-RACE%'")->fetch_row()[0] === 1);
test('Race PO stock added once (7)', (int)$db->query("SELECT quantity FROM stock WHERE item_id=100 AND location_id=100")->fetch_row()[0] === 27);

echo "\n--- Test 2: Barcode Label Format ---\n";

// Load barcode function from labels.php
$labels_code = file_get_contents(__DIR__ . '/../pages/items/labels.php');
preg_match('/function code39\(string \$value\):string\{.*?\}\s*\?>/s', $labels_code, $m);
if ($m) {
    eval(rtrim(preg_replace('/\?>$/', '', trim($m[0]))));
    
    $svg = code39('TEST123');
    test('Generates SVG', str_starts_with($svg, '<svg'));
    test('Contains rectangles (bars)', str_contains($svg, '<rect'));
    test('Has viewBox', preg_match('/viewBox="[^"]+"/', $svg) === 1);
    test('Has aria-label', str_contains($svg, 'aria-label'));
    
    // Check aspect ratio
    $has_none = str_contains($svg, 'preserveAspectRatio="none"');
    test('Does not distort bars', !$has_none, $has_none ? 'Uses "none" which distorts' : '');
    
    // Check quiet zones (Code 39 spec requires them)
    preg_match('/viewBox="(-?\d+) 0 (\d+) (\d+)"/', $svg, $vb);
    if ($vb) {
        $left = (int)$vb[1];
        $width = (int)$vb[2];
        $height = (int)$vb[3];
        test('Has proper quiet zone', $left < -5, 'Missing left quiet zone');
        test('Height correct', $height === 38);
    }
    
    // Test special chars
    $svg_hyphen = code39('ITEM-001');
    test('Handles hyphen', str_contains($svg_hyphen, '<rect'));
    
    // Test invalid chars are skipped
    $svg_invalid = code39('TEST@#$');
    test('Skips invalid chars', str_contains($svg_invalid, '<rect'));
} else {
    test('Extract code39 function', false, 'Could not parse function');
}

echo "\n--- Test 3: Low-Stock Calculation Accuracy ---\n";

// Clear stock for low-stock test
$db->query("UPDATE stock SET quantity=0");

// Set item 100 below min_stock (min=10, current=5)
$db->query("UPDATE stock SET quantity=5 WHERE item_id=100");

// Set item 101 above min_stock (min=5, current=10)
$db->query("INSERT INTO stock (item_id,location_id,quantity) VALUES (101,100,10) ON DUPLICATE KEY UPDATE quantity=10");

// Query low-stock items (same query as alerts.php)
$low = $db->query("
    SELECT i.id, i.code, i.min_stock, 
        COALESCE(SUM(s.quantity),0) AS total_stock,
        GREATEST(i.min_stock*2-COALESCE(SUM(s.quantity),0),1) AS suggested_qty
    FROM items i 
    LEFT JOIN stock s ON s.item_id=i.id 
    WHERE i.is_active=1 
    GROUP BY i.id 
    HAVING total_stock <= MAX(i.min_stock)
    ORDER BY total_stock
")->fetch_all(MYSQLI_ASSOC);

test('Low-stock query returns results', count($low) > 0);
test('Item 100 flagged (5 < 10)', isset($low[0]) && (int)$low[0]['id'] === 100);
test('Item 101 not flagged (10 > 5)', !in_array(101, array_column($low, 'id')));

if (isset($low[0])) {
    $item1 = $low[0];
    test('Total stock correct', (int)$item1['total_stock'] === 5);
    test('Suggested qty = 15', (int)$item1['suggested_qty'] === 15, "Got {$item1['suggested_qty']}");
}

// Test zero stock
$db->query("UPDATE stock SET quantity=0 WHERE item_id=100");
$zero = $db->query("
    SELECT COALESCE(SUM(s.quantity),0) AS total_stock
    FROM items i 
    LEFT JOIN stock s ON s.item_id=i.id 
    WHERE i.id=100
")->fetch_assoc();
test('Handles zero stock', (int)$zero['total_stock'] === 0);

// Test suggested_qty with zero stock (min=10, current=0 → suggest 20)
$db->query("DELETE FROM stock WHERE item_id=100");
$suggest = $db->query("
    SELECT GREATEST(i.min_stock*2-COALESCE(SUM(s.quantity),0),1) AS qty
    FROM items i 
    LEFT JOIN stock s ON s.item_id=i.id 
    WHERE i.id=100
    GROUP BY i.id
")->fetch_assoc();
test('Zero stock suggests 2x min', (int)$suggest['qty'] === 20);

echo "\n=== Summary ===\n";
echo "Passed: $passed\n";
echo "Failed: $failed\n\n";

if ($failed > 0) {
    echo "BUGS FOUND:\n";
    if ($has_none ?? false) echo "- Barcode uses preserveAspectRatio=\"none\" (distorts bars)\n";
    if (($left ?? 0) >= 0) echo "- Barcode missing quiet zones (Code 39 spec violation)\n";
}

exit($failed > 0 ? 1 : 0);
