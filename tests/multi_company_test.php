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

// 2. Hybrid Catalog: Global Item (company_id NULL) vs Dedicated PT Item (company_id = 2)
$db->query("INSERT INTO items (id, company_id, code, name, category_id, unit_id, min_stock, buy_price, is_active) VALUES
    (200, NULL, 'GLB-001', 'Kertas A4 Global', 1, 1, 10, 50000, 1),
    (201, 2,    'SLN-001', 'Sparepart Khusus SLN', 1, 1, 5, 120000, 1),
    (202, 3,    'PDM-001', 'Kemasan Khusus PDM', 1, 1, 5, 30000, 1)");

// 3. Shared Rack (location_id = 1) holding stock for multiple PTs simultaneously
update_stock(200, 1, 50, 1); // MSP has 50 of GLB-001 in Rack 1
update_stock(200, 1, 30, 2); // SLN has 30 of GLB-001 in Rack 1
update_stock(201, 1, 15, 2); // SLN has 15 of SLN-001 in Rack 1

test('MSP stock of GLB-001 in Rack 1 is 50', get_item_stock(200, 1) === 50);
test('SLN stock of GLB-001 in Rack 1 is 30', get_item_stock(200, 2) === 30);
test('PDM stock of GLB-001 in Rack 1 is 0',  get_item_stock(200, 3) === 0);

// 4. Stock Out validation isolated per PT on the same rack
try {
    assert_location_stock_available($db, 1, [[200, 40, 0]], 2); // SLN only has 30, even though Rack 1 physically has 80 total
    test('SLN cannot consume MSP stock on shared rack', false, 'Allowed 40 when SLN only has 30');
} catch (RuntimeException $e) {
    test('SLN cannot consume MSP stock on shared rack', str_contains($e->getMessage(), 'Tersedia: 30'));
}

try {
    assert_location_stock_available($db, 1, [[200, 40, 0]], 1); // MSP has 50
    test('MSP can consume 40 from its own 50 stock on shared rack', true);
} catch (Throwable $e) {
    test('MSP can consume 40 from its own 50 stock on shared rack', false, $e->getMessage());
}

// 5. PO Receive propagates company_id to stock_in, stock, and mutations
$db->query("INSERT INTO suppliers (id, code, name, is_active) VALUES (200, 'SUP-M', 'Supplier Multi', 1)");
$db->query("INSERT INTO purchase_orders (id, reference_no, company_id, supplier_id, location_id, user_id, status, approval_status, order_date)
    VALUES (200, 'PO-SLN-01', 2, 200, 1, 1, 'pending', 'approved', '2026-10-10')");
$db->query("INSERT INTO purchase_order_details (po_id, item_id, quantity, buy_price, received_quantity)
    VALUES (200, 200, 25, 50000, 0)");

$si_ref = receive_purchase_order_partial($db, 200, [200 => 25], 1);
test('PO for SLN received', str_starts_with($si_ref, 'SI-'));
test('SLN stock of GLB-001 increased to 55 (30+25)', get_item_stock(200, 2) === 55);
test('MSP stock of GLB-001 untouched at 50', get_item_stock(200, 1) === 50);

$si_comp = (int)$db->query("SELECT company_id FROM stock_in WHERE reference_no='$si_ref'")->fetch_row()[0];
test('Generated stock_in belongs to SLN (company_id=2)', $si_comp === 2);

// 6. Supplier Return isolated by company_id
$ret_id = create_supplier_return($db, 200, 1, 1, '2026-10-11', 'Cacat', 'Retur SLN', [[200, 5, 50000]], 2);
test('SLN supplier return deducts SLN stock (55-5=50)', get_item_stock(200, 2) === 50);
test('MSP stock still 50', get_item_stock(200, 1) === 50);
cancel_supplier_return($db, $ret_id, 1);
test('Cancel SLN supplier return restores SLN stock to 55', get_item_stock(200, 2) === 55);

// 7. Stock Opname isolated by company_id
$opn_session = create_stock_opname($db, '2026-10-12', 1, 'Opname SLN', 2);
$cnt = save_stock_opname_count($db, $opn_session, 200, 1, 52);
test('Stock Opname for SLN snapshots SLN system qty (55)', $cnt['system_qty'] === 55 && $cnt['variance'] === -3);
finalize_stock_opname($db, $opn_session, 2);
test('Finalized SLN Stock Opname sets SLN stock to 52', get_item_stock(200, 2) === 52);
test('MSP stock on same rack remains 50', get_item_stock(200, 1) === 50);

// 8. Low Stock Alerts scoped per company + hybrid catalog
$low_sln = get_low_stock_items(2);
$low_sln_codes = array_column($low_sln, 'code');
test('SLN low-stock does not include PDM-only item (PDM-001)', !in_array('PDM-001', $low_sln_codes, true));

$low_pdm = get_low_stock_items(3);
$low_pdm_codes = array_column($low_pdm, 'code');
test('PDM low-stock includes PDM-001 (0 <= 5) and Global GLB-001 (0 <= 10)', in_array('PDM-001', $low_pdm_codes, true) && in_array('GLB-001', $low_pdm_codes, true));
test('PDM low-stock does not include SLN-only item (SLN-001)', !in_array('SLN-001', $low_pdm_codes, true));

// 9. Per-PT Document Reference Numbering
$ref_msp = generate_ref('SI-', 1);
$ref_sln = generate_ref('SO-', 2);
$ref_pdm = generate_ref('PO-', 3);
test('MSP reference contains SI-MSP-', str_starts_with($ref_msp, 'SI-MSP-' . date('Ymd')));
test('SLN reference contains SO-SLN-', str_starts_with($ref_sln, 'SO-SLN-' . date('Ymd')));
test('PDM reference contains PO-PDM-', str_starts_with($ref_pdm, 'PO-PDM-' . date('Ymd')));

// 10. Inter-Company Stock Transfer (MSP transfers 10 of GLB-001 in Rack 1 to PDM in Rack 1)
update_stock(200, 1, -10, 1); // MSP: 50 - 10 = 40
update_stock(200, 1, 10, 3);  // PDM: 0 + 10 = 10
log_mutation(200, 1, 'transfer_out', 10, 'TR-MSP-TEST01', 'transfer', 1, 1, 'Transfer Antar-PT ke PDM', 1);
log_mutation(200, 1, 'transfer_in',  10, 'TR-MSP-TEST01', 'transfer', 1, 1, 'Transfer Antar-PT dari MSP', 3);
test('After Inter-PT Transfer: MSP stock is 40', get_item_stock(200, 1) === 40);
test('After Inter-PT Transfer: PDM stock is 10', get_item_stock(200, 3) === 10);

// 11. Consolidated Stock Query across all PTs (MSP 40 + SLN 52 + PDM 10 = 102)
$consolidated_glb = (int)$db->query("SELECT COALESCE(SUM(quantity),0) FROM stock WHERE item_id=200 AND company_id IN (1,2,3)")->fetch_row()[0];
test('Consolidated stock of GLB-001 across all 3 PTs is 102 (40+52+10)', $consolidated_glb === 102);

echo "\n=== Summary ===\n";
echo "Passed: $passed\n";
echo "Failed: $failed\n";
exit($failed > 0 ? 1 : 0);
