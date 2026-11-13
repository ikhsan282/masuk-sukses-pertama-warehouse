<?php
/**
 * Multi-Company (Holding & Subsidiaries with Shared Warehouse) Integration Tests
 */
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

const TEST_DB = 'db_msp_warehouse_test_multipt';
define('DB_HOST', '127.0.0.1');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', TEST_DB);
define('DB_PORT', (int)(getenv('DB_PORT') ?: 3306));
define('DB_CHARSET', 'utf8mb4');
define('APP_NAME', 'MSP Multi-PT Test');
define('APP_URL', 'http://localhost/test');
define('MAIL_FROM', 'test@example.com');
define('MAIL_FROM_NAME', 'Test');

function getDB(): mysqli {
    static $conn = null;
    if ($conn) return $conn;
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
    $conn->set_charset(DB_CHARSET);
    return $conn;
}

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/purchase_orders.php';
require_once __DIR__ . '/../includes/supplier_returns.php';
require_once __DIR__ . '/../includes/stock_opname.php';
require_once __DIR__ . '/../includes/low_stock_alerts.php';

echo "=== Multi-Company (Holding & Subsidiary Shared Warehouse) Tests ===\n\n";

$boot = new mysqli(DB_HOST, DB_USER, DB_PASS, '', DB_PORT);
$boot->query('DROP DATABASE IF EXISTS `' . TEST_DB . '`');
$schema = str_replace('db_msp_warehouse', TEST_DB, file_get_contents(__DIR__ . '/../database/schema.sql'));
$boot->multi_query($schema);
do { if ($r = $boot->store_result()) $r->free(); } while ($boot->more_results() && $boot->next_result());
$boot->close();

$db = getDB();

// 1. Check seeded companies (1=MSP Holding, 2=SLN, 3=PDM)
$companies = $db->query('SELECT id, code, is_holding FROM companies ORDER BY id')->fetch_all(MYSQLI_ASSOC);
test('Seeded 3 companies (MSP Holding + 2 Anak PT)', count($companies) === 3);
test('Company 1 is MSP Holding', $companies[0]['code'] === 'MSP' && (int)$companies[0]['is_holding'] === 1);
test('Company 2 is SLN Subsidiary', $companies[1]['code'] === 'SLN' && (int)$companies[1]['is_holding'] === 0);

// 2. Centralized Group Item Catalog (NO items.company_id column; stock segregated per PT)
$cols = $db->query("SHOW COLUMNS FROM items LIKE 'company_id'")->num_rows;
test('items table has no company_id ownership column (centralized SKU)', $cols === 0);

$db->query("INSERT INTO items (id, code, name, category_id, unit_id, min_stock, buy_price, is_active) VALUES
    (200, 'SKU-TEST-01', 'Ban Loader Shared SKU 01', 1, 1, 10, 50000, 1)");

// 3. Shared Rack (location_id = 1) holding stock for multiple PTs simultaneously (and test seeded MSP-OPL-178 = id 10)
update_stock(200, 1, 100, 1);  // MSP has 100 of SKU-TEST-01 in Rack 1
update_stock(200, 1, 1500, 2); // SLN has 1500 of SKU-TEST-01 in Rack 1
// PDM starts with 0 on SKU-TEST-01

test('MSP stock of SKU-TEST-01 in Rack 1 is 100', get_item_stock(200, 1) === 100);
test('SLN stock of SKU-TEST-01 in Rack 1 is 1500', get_item_stock(200, 2) === 1500);
test('PDM stock of SKU-TEST-01 in Rack 1 is 0',  get_item_stock(200, 3) === 0);

// Verify seeded MSP-OPL-178 (id=10) multi-PT ownership: MSP 100, SLN 1500, PDM 15
test('Seeded MSP-OPL-178 belongs separately: MSP=100, SLN=1500, PDM=15',
    get_item_stock(10, 1) === 100 && get_item_stock(10, 2) === 1500 && get_item_stock(10, 3) === 15);

// 4. Stock Out validation isolated per PT on the same rack
try {
    assert_location_stock_available($db, 1, [[200, 150, 0]], 1); // MSP only has 100, even though Rack 1 has 1600 total across PTs
    test('MSP cannot consume SLN stock on shared rack', false, 'Allowed 150 when MSP only has 100');
} catch (RuntimeException $e) {
    test('MSP cannot consume SLN stock on shared rack', str_contains($e->getMessage(), 'Tersedia: 100'));
}

try {
    assert_location_stock_available($db, 1, [[200, 150, 0]], 2); // SLN has 1500
    test('SLN can consume 150 from its own 1500 stock on shared rack', true);
} catch (Throwable $e) {
    test('SLN can consume 150 from its own 1500 stock on shared rack', false, $e->getMessage());
}

// 5. PO Receive propagates company_id to stock_in, stock, and mutations
$db->query("INSERT INTO suppliers (id, code, name, is_active) VALUES (200, 'SUP-M', 'Supplier Multi', 1)");
$db->query("INSERT INTO purchase_orders (id, reference_no, company_id, supplier_id, location_id, user_id, status, approval_status, order_date)
    VALUES (200, 'PO-SLN-01', 2, 200, 1, 1, 'pending', 'approved', '2026-10-10')");
$db->query("INSERT INTO purchase_order_details (po_id, item_id, quantity, buy_price, received_quantity)
    VALUES (200, 200, 25, 50000, 0)");

$si_ref = receive_purchase_order_partial($db, 200, [200 => 25], 1);
test('PO for SLN received', str_starts_with($si_ref, 'SI-'));
test('SLN stock of SKU-TEST-01 increased to 1525 (1500+25)', get_item_stock(200, 2) === 1525);
test('MSP stock of SKU-TEST-01 untouched at 100', get_item_stock(200, 1) === 100);

$si_comp = (int)$db->query("SELECT company_id FROM stock_in WHERE reference_no='$si_ref'")->fetch_row()[0];
test('Generated stock_in belongs to SLN (company_id=2)', $si_comp === 2);

// 6. Supplier Return isolated by company_id
$ret_id = create_supplier_return($db, 200, 1, 1, '2026-10-11', 'Cacat', 'Retur SLN', [[200, 25, 50000]], 2);
test('SLN supplier return deducts SLN stock (1525-25=1500)', get_item_stock(200, 2) === 1500);
test('MSP stock still 100', get_item_stock(200, 1) === 100);
cancel_supplier_return($db, $ret_id, 1);
test('Cancel SLN supplier return restores SLN stock to 1525', get_item_stock(200, 2) === 1525);

// 7. Stock Opname isolated by company_id
$opn_session = create_stock_opname($db, '2026-10-12', 1, 'Opname SLN', 2);
$cnt = save_stock_opname_count($db, $opn_session, 200, 1, 1490);
test('Stock Opname for SLN snapshots SLN system qty (1525)', $cnt['system_qty'] === 1525 && $cnt['variance'] === -35);
finalize_stock_opname($db, $opn_session, 2);
test('Finalized SLN Stock Opname sets SLN stock to 1490', get_item_stock(200, 2) === 1490);
test('MSP stock on same rack remains 100', get_item_stock(200, 1) === 100);

// 8. Low Stock Alerts scoped per company across centralized catalog
$low_pdm = get_low_stock_items(3);
$low_pdm_codes = array_column($low_pdm, 'code');
test('PDM low-stock includes SKU-TEST-01 (0 <= 10) for PDM while MSP (100) and SLN (1490) are safe', in_array('SKU-TEST-01', $low_pdm_codes, true));
$low_msp_codes = array_column(get_low_stock_items(1), 'code');
test('MSP low-stock does not flag SKU-TEST-01 (100 > 10)', !in_array('SKU-TEST-01', $low_msp_codes, true));

// 9. Per-PT Document Reference Numbering
$ref_msp = generate_ref('SI-', 1);
$ref_sln = generate_ref('SO-', 2);
$ref_pdm = generate_ref('PO-', 3);
test('MSP reference contains SI-MSP-', str_starts_with($ref_msp, 'SI-MSP-' . date('Ymd')));
test('SLN reference contains SO-SLN-', str_starts_with($ref_sln, 'SO-SLN-' . date('Ymd')));
test('PDM reference contains PO-PDM-', str_starts_with($ref_pdm, 'PO-PDM-' . date('Ymd')));

// 10. Inter-Company Stock Transfer (PT B / SLN transfers 50 of MSP-OPL-178 in Rack B1 to PT C / PDM)
update_stock(10, 3, -50, 2); // SLN: 1500 - 50 = 1450
update_stock(10, 3, 50, 3);  // PDM: 15 + 50 = 65
log_mutation(10, 3, 'transfer_out', 50, 'TR-SLN-TEST01', 'transfer', 99, 1, 'Transfer Antar-PT SLN -> PDM', 2);
log_mutation(10, 3, 'transfer_in',  50, 'TR-SLN-TEST01', 'transfer', 99, 1, 'Transfer Antar-PT SLN -> PDM', 3);
test('After Inter-PT Transfer: MSP stock stays 100', get_item_stock(10, 1) === 100);
test('After Inter-PT Transfer: SLN stock becomes 1450', get_item_stock(10, 2) === 1450);
test('After Inter-PT Transfer: PDM stock increases to 65 (15+50)', get_item_stock(10, 3) === 65);

// 11. Consolidated Stock Query across all PTs (MSP 100 + SLN 1450 + PDM 65 = 1615)
$consolidated = (int)$db->query("SELECT COALESCE(SUM(quantity),0) FROM stock WHERE item_id=10 AND company_id IN (1,2,3)")->fetch_row()[0];
test('Consolidated stock of MSP-OPL-178 across all 3 PTs is 1615 (100+1450+65)', $consolidated === 1615);

echo "\n=== Summary ===\n";
echo "Passed: $passed\n";
echo "Failed: $failed\n";
exit($failed > 0 ? 1 : 0);
