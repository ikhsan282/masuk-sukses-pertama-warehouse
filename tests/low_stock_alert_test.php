<?php
/**
 * Low Stock Alert Test
 * Run: php -c /opt/data/cache/scratch/mariadb/php-ext.ini tests/low_stock_alert_test.php
 */

if (!extension_loaded('mysqli')) {
    die("ERROR: mysqli extension required. Run with:\n  php -c /opt/data/cache/scratch/mariadb/php-ext.ini tests/low_stock_alert_test.php\n");
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
define('DB_NAME', 'db_msp_warehouse_test');
define('DB_PORT', 13306);
define('DB_CHARSET', 'utf8mb4');

// Override config constants
define('APP_NAME', 'Masuk Sukses Pertama Warehouse Test');
define('APP_URL', 'http://localhost/test');
define('MAIL_FROM', 'test@example.com');
define('MAIL_FROM_NAME', 'Test Mailer');

// Mock send_mail BEFORE loading includes
if (!function_exists('send_mail')) {
    function send_mail(string $to, string $subject, string $body): bool {
        $GLOBALS['test_mail_sent'] = true;
        $GLOBALS['test_mail_to'] = $to;
        $GLOBALS['test_mail_subject'] = $subject;
        $GLOBALS['test_mail_body'] = $body;
        return true;
    }
}

if (!function_exists('getDB')) {
    function getDB(): mysqli {
        static $conn = null;
        if ($conn) return $conn;
        mysqli_report(MYSQLI_REPORT_OFF);
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
        if ($conn->connect_error) die("DB connection failed: " . $conn->connect_error);
        $conn->set_charset(DB_CHARSET);
        return $conn;
    }
}

// Load dependencies (send_mail already mocked above)
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/low_stock_alerts.php';

echo "=== Low Stock Alert Tests ===\n\n";

// Setup test database
$boot = new mysqli(DB_HOST, DB_USER, DB_PASS, '', DB_PORT);
if ($boot->connect_error) die("DB connection failed: " . $boot->connect_error . "\n");
$boot->query("DROP DATABASE IF EXISTS `" . DB_NAME . "`");
$schema = str_replace('db_msp_warehouse', DB_NAME, file_get_contents(__DIR__ . '/../database/schema.sql'));
if (!$boot->multi_query($schema)) die("schema load failed: " . $boot->error . "\n");
do { if ($r = $boot->store_result()) $r->free(); if ($boot->errno) die("schema stmt failed: " . $boot->error . "\n"); } while ($boot->more_results() && $boot->next_result());
$boot->close();

$db = getDB();
function q(mysqli $db, string $sql): void { if ($db->query($sql) === false) die("SQL failed: {$db->error}\n$sql\n"); }

// Seed test data
q($db, "INSERT INTO suppliers (id,code,name,is_active) VALUES (100,'SUP001','Test Supplier',1)");
q($db, "INSERT INTO items (id,code,name,category_id,unit_id,min_stock,buy_price,is_active) VALUES 
    (100,'ITEM001','Low Stock Item',1,1,10,1000,1),
    (101,'ITEM002','Zero Stock Item',1,1,5,2000,1),
    (102,'ITEM003','OK Stock Item',1,1,5,1500,1)");

echo "--- Test 1: get_low_stock_items() ---\n";

// Set stock levels: item 1 = 5 (below min 10), item 2 = 0 (below min 5), item 3 = 10 (above min 5)
q($db, "INSERT INTO stock (item_id,location_id,quantity) VALUES (100,1,5),(102,1,10)");

$items = get_low_stock_items();
test('Returns array', is_array($items));
test('Found 2 low-stock items', count($items) === 2, 'Expected 2, got ' . count($items));

if (count($items) >= 2) {
    // Should be ordered by stock ASC
    test('First item has zero stock', (int)$items[0]['total_stock'] === 0);
    test('First item is ITEM002', $items[0]['code'] === 'ITEM002');
    test('Second item has 5 stock', (int)$items[1]['total_stock'] === 5);
    test('Second item is ITEM001', $items[1]['code'] === 'ITEM001');
    
    // Check suggested quantities
    $zero_item = $items[0];
    $low_item = $items[1];
    test('Zero stock suggests 2x min (10)', (int)$zero_item['suggested_qty'] === 10);
    test('Low stock (5) suggests 15', (int)$low_item['suggested_qty'] === 15);
    
    // Check stock value calculation
    test('Zero stock value is 0', (float)$zero_item['stock_value'] === 0.0);
    test('Low stock value is 5000', (float)$low_item['stock_value'] === 5000.0);
}

echo "\n--- Test 2: build_low_stock_email_body() ---\n";

$body = build_low_stock_email_body($items);
test('Returns string', is_string($body));
test('Contains item count', str_contains($body, '2 item'));
test('Contains warning emoji', str_contains($body, '🔴'));
test('Contains table', str_contains($body, '<table'));
test('Contains ITEM001 code', str_contains($body, 'ITEM001'));
test('Contains ITEM002 code', str_contains($body, 'ITEM002'));
test('Contains HABIS badge', str_contains($body, 'HABIS'));
test('Contains MENIPIS badge', str_contains($body, 'MENIPIS'));
test('Contains stock value', str_contains($body, 'Rp'));
test('Contains suggested qty', str_contains($body, 'Saran Pesan'));

// Check HTML escaping
q($db, "INSERT INTO items (id,code,name,category_id,unit_id,min_stock,buy_price,is_active) VALUES 
    (103,'XSS<script>','Test<b>HTML</b>',1,1,10,100,1)");
$xss_items = get_low_stock_items();
$xss_body = build_low_stock_email_body($xss_items);
test('Escapes HTML in item name', !str_contains($xss_body, '<b>HTML</b>'));
test('Escapes script tags', !str_contains($xss_body, '<script>'));
test('Contains escaped entities', str_contains($xss_body, '&lt;') || str_contains($xss_body, '&amp;'));

echo "\n--- Test 3: send_low_stock_email() (dry-run) ---\n";

// Reset mock state
$GLOBALS['test_mail_sent'] = false;
$GLOBALS['test_mail_to'] = '';
$GLOBALS['test_mail_subject'] = '';
$GLOBALS['test_mail_body'] = '';

ob_start();
$result = send_low_stock_email('admin@example.com');
$output = ob_get_clean();

test('Returns true on success', $result === true);
test('Mail function called', $GLOBALS['test_mail_sent'] === true);
test('Sent to admin email', $GLOBALS['test_mail_to'] === 'admin@example.com');
test('Subject contains item count', str_contains($GLOBALS['test_mail_subject'], '3 Item'));
test('Subject contains app name', str_contains($GLOBALS['test_mail_subject'], APP_NAME));
test('Body contains HTML template', str_contains($GLOBALS['test_mail_body'], '<!DOCTYPE html>'));
test('Output logged timestamp', str_contains($output, date('Y-m-d')));

echo "\n--- Test 4: No low stock (skip email) ---\n";

// Remove all low stock
q($db, "INSERT INTO stock (item_id,location_id,quantity) VALUES (100,1,100),(101,1,100),(102,1,100)
    ON DUPLICATE KEY UPDATE quantity=VALUES(quantity)");
q($db, "DELETE FROM items WHERE id=103"); // Remove XSS test item

$GLOBALS['test_mail_sent'] = false;
ob_start();
$result2 = send_low_stock_email('admin@example.com');
$output2 = ob_get_clean();

test('Returns true when no alerts', $result2 === true);
test('Mail not sent', $GLOBALS['test_mail_sent'] === false);
test('Output says no items', str_contains($output2, 'No low stock'));

echo "\n=== Summary ===\n";
echo "Passed: $passed\n";
echo "Failed: $failed\n\n";

exit($failed > 0 ? 1 : 0);
