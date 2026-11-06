<?php
// Integration test: stock opname count snapshots and atomic finalization.
if (!extension_loaded('mysqli')) die("mysqli extension required\n");
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$passed = $failed = 0;
function test(string $name, bool $ok, string $reason = ''): void {
    global $passed, $failed;
    $ok ? $passed++ : $failed++;
    echo ($ok ? "✓" : "✗") . " {$name}" . ($ok ? '' : ": {$reason}") . "\n";
}
function generate_ref(string $prefix): string { return $prefix . bin2hex(random_bytes(5)); }

const HOST = '127.0.0.1';
const PORT = 13306;
const TEST_DB = 'db_msp_warehouse_test_opname';
$boot = new mysqli(HOST, 'root', '', '', PORT);
$boot->query('DROP DATABASE IF EXISTS `' . TEST_DB . '`');
$schema = str_replace('db_msp_warehouse', TEST_DB, file_get_contents(__DIR__ . '/../database/schema.sql'));
$boot->multi_query($schema);
do { if ($r = $boot->store_result()) $r->free(); } while ($boot->more_results() && $boot->next_result());
$boot->close();
$db = new mysqli(HOST, 'root', '', TEST_DB, PORT);
$db->set_charset('utf8mb4');
$db->query("INSERT INTO locations (id,code,name) VALUES (100,'T-01','Test')");
$db->query("INSERT INTO items (id,code,name,category_id,unit_id) VALUES (100,'OPN-ITEM','Opname Item',1,1)");
$db->query("INSERT INTO stock (item_id,location_id,quantity) VALUES (100,100,10)");

require_once __DIR__ . '/../includes/stock_opname.php';

echo "=== Stock Opname Integration Tests ===\n";
$session = create_stock_opname($db, '2026-10-09', 1, 'Cycle count');
test('Draft session created', $session > 0);
$count = save_stock_opname_count($db, $session, 100, 100, 7);
test('System quantity snapshot is 10', $count['system_qty'] === 10);
test('Variance is -3', $count['variance'] === -3);

$adjustments = finalize_stock_opname($db, $session, 2);
test('One adjustment created', count($adjustments) === 1);
$status = $db->query("SELECT status FROM stock_opname WHERE session_id={$session}")->fetch_assoc()['status'];
test('Session finalized', $status === 'finalized');
$stock = (int)$db->query("SELECT quantity FROM stock WHERE item_id=100 AND location_id=100")->fetch_row()[0];
test('Stock set to physical count', $stock === 7);
$adj = $db->query("SELECT status,opname_session_id FROM stock_adjustments WHERE id={$adjustments[0]}")->fetch_assoc();
test('Approved adjustment links session', $adj['status'] === 'approved' && (int)$adj['opname_session_id'] === $session);
$detail = $db->query("SELECT system_qty,physical_qty,difference FROM stock_adjustment_details WHERE adjustment_id={$adjustments[0]}")->fetch_assoc();
test('Adjustment detail preserves variance', (int)$detail['system_qty'] === 10 && (int)$detail['physical_qty'] === 7 && (int)$detail['difference'] === -3);
$mutation = $db->query("SELECT type,quantity,reference_type FROM mutations WHERE reference_id={$adjustments[0]} AND reference_type='adjustment'")->fetch_assoc();
test('Adjustment mutation posted', $mutation['type'] === 'adjustment' && (int)$mutation['quantity'] === 3);

try {
    finalize_stock_opname($db, $session, 2);
    test('Double finalize blocked', false, 'No exception');
} catch (RuntimeException $e) {
    test('Double finalize blocked', str_contains($e->getMessage(), 'finalized'));
}

$session2 = create_stock_opname($db, '2026-10-09', 1, 'Conflict');
save_stock_opname_count($db, $session2, 100, 100, 5);
$db->query("UPDATE stock SET quantity=8 WHERE item_id=100 AND location_id=100");
try {
    finalize_stock_opname($db, $session2, 2);
    test('Changed stock blocks finalization', false, 'No exception');
} catch (RuntimeException $e) {
    test('Changed stock blocks finalization', str_contains($e->getMessage(), 'berubah'));
}
$status2 = $db->query("SELECT status FROM stock_opname WHERE session_id={$session2}")->fetch_assoc()['status'];
test('Failed finalization rolls back', $status2 === 'draft');

try {
    save_stock_opname_count($db, $session, 100, 100, 1);
    test('Finalized session rejects edits', false, 'No exception');
} catch (RuntimeException $e) {
    test('Finalized session rejects edits', str_contains($e->getMessage(), 'finalized'));
}

try {
    save_stock_opname_count($db, $session2, 100, 100, -1);
    test('Negative count rejected', false, 'No exception');
} catch (InvalidArgumentException $e) {
    test('Negative count rejected', true);
}

echo "Passed: {$passed}\nFailed: {$failed}\n";
exit($failed ? 1 : 0);
