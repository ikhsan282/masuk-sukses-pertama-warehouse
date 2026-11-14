<?php
/**
 * Production DB & Composite Index Verification CLI
 *
 * Usage:
 *   php cron/verify_production_db.php                # Check only (exit 1 if missing/mismatch)
 *   php cron/verify_production_db.php --apply-missing # Create missing schema indexes idempotently
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI access only.\n");
}

$args = array_slice($argv ?? [], 1);
$applyMissing = in_array('--apply-missing', $args, true);
$asJson = in_array('--json', $args, true);

require_once __DIR__ . '/../config/database.php';

if (!extension_loaded('mysqli')) {
    fwrite(STDERR, "ERROR: Ekstensi PHP mysqli belum aktif.\n");
    exit(2);
}

$db = getDB();

$expectedTables = [
    'companies', 'roles', 'permissions', 'role_permissions', 'users', 'user_companies',
    'categories', 'units', 'suppliers', 'locations', 'items', 'stock',
    'stock_in', 'stock_in_details', 'stock_out', 'stock_out_details',
    'transfers', 'transfer_details', 'stock_opname', 'stock_opname_items',
    'stock_adjustments', 'stock_adjustment_details',
    'purchase_orders', 'purchase_order_details',
    'supplier_returns', 'supplier_return_details', 'mutations',
];

$requiredIndexes = [
    'mutations' => [
        'idx_mutations_company_id'        => ['company_id'],
        'idx_mutations_item_id'           => ['item_id'],
        'idx_mutations_location_id'       => ['location_id'],
        'idx_mutations_type'              => ['type'],
        'idx_mutations_created_at'        => ['created_at'],
        'idx_mutations_comp_created_id'   => ['company_id', 'created_at', 'id'],
        'idx_mutations_comp_id_created'   => ['company_id', 'id', 'created_at'],
        'idx_mutations_comp_item_created' => ['company_id', 'item_id', 'created_at', 'id'],
        'idx_mutations_comp_type_created' => ['company_id', 'type', 'created_at', 'id'],
    ],
    'stock_in' => [
        'idx_stock_in_company_id'       => ['company_id'],
        'idx_stock_in_transaction_date' => ['transaction_date'],
        'idx_stock_in_comp_date_id'     => ['company_id', 'transaction_date', 'id'],
        'idx_stock_in_comp_id_date'     => ['company_id', 'id', 'transaction_date'],
        'idx_stock_in_comp_id'          => ['company_id', 'id'],
    ],
    'stock_out' => [
        'idx_stock_out_company_id'       => ['company_id'],
        'idx_stock_out_transaction_date' => ['transaction_date'],
        'idx_stock_out_comp_date_id'     => ['company_id', 'transaction_date', 'id'],
        'idx_stock_out_comp_id_date'     => ['company_id', 'id', 'transaction_date'],
        'idx_stock_out_comp_id'          => ['company_id', 'id'],
    ],
    'items' => [
        'idx_items_code'            => ['code'],
        'idx_items_name'            => ['name'],
        'idx_items_active_code'     => ['is_active', 'code'],
        'idx_items_active_cat_code' => ['is_active', 'category_id', 'code'],
        'idx_items_active_name'     => ['is_active', 'name'],
    ],
    'stock' => [
        'idx_stock_comp_item_qty' => ['company_id', 'item_id', 'quantity'],
        'idx_stock_comp_loc_item' => ['company_id', 'location_id', 'item_id'],
        'idx_stock_loc_qty'       => ['location_id', 'quantity'],
    ],
    'transfers' => [
        'idx_transfers_from_comp_id'      => ['from_company_id', 'id'],
        'idx_transfers_to_comp_id'        => ['to_company_id', 'id'],
        'idx_transfers_from_comp_date_id' => ['from_company_id', 'transaction_date', 'id'],
        'idx_transfers_to_comp_date_id'   => ['to_company_id', 'transaction_date', 'id'],
    ],
    'stock_adjustments' => [
        'idx_adjustments_comp_id' => ['company_id', 'id'],
    ],
    'purchase_orders' => [
        'idx_po_comp_status_id' => ['company_id', 'status', 'id'],
        'idx_po_comp_date_id'   => ['company_id', 'order_date', 'id'],
    ],
    'supplier_returns' => [
        'idx_sr_comp_date_id' => ['company_id', 'transaction_date', 'id'],
        'idx_sr_comp_id'      => ['company_id', 'id'],
    ],
    'stock_opname' => [
        'idx_opname_comp_status_id' => ['company_id', 'status', 'session_id'],
    ],
    'categories' => [
        'idx_categories_name' => ['name'],
    ],
    'suppliers' => [
        'idx_suppliers_name'        => ['name'],
        'idx_suppliers_active_name' => ['is_active', 'name'],
    ],
    'locations' => [
        'idx_locations_active_code' => ['is_active', 'code'],
    ],
];

function fetch_schema_charset(mysqli $db): ?array
{
    $stmt = $db->prepare('SELECT DEFAULT_CHARACTER_SET_NAME, DEFAULT_COLLATION_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
    $dbName = DB_NAME;
    $stmt->bind_param('s', $dbName);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function fetch_table_status_map(mysqli $db): array
{
    $stmt = $db->prepare('SELECT TABLE_NAME, ENGINE, TABLE_COLLATION, TABLE_ROWS FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?');
    $dbName = DB_NAME;
    $stmt->bind_param('s', $dbName);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $map = [];
    foreach ($rows as $r) {
        $map[$r['TABLE_NAME']] = $r;
    }
    return $map;
}

function fetch_table_indexes(mysqli $db, string $table): array
{
    $escaped = str_replace('`', '``', $table);
    $res = $db->query("SHOW INDEX FROM `{$escaped}`");
    if (!$res) {
        return [];
    }
    $byKey = [];
    while ($row = $res->fetch_assoc()) {
        $byKey[$row['Key_name']][(int)$row['Seq_in_index']] = $row['Column_name'];
    }
    foreach ($byKey as $k => $cols) {
        ksort($cols);
        $byKey[$k] = array_values($cols);
    }
    return $byKey;
}

function escape_ident(string $ident): string
{
    return '`' . str_replace('`', '``', $ident) . '`';
}

$errors = [];
$warnings = [];
$applied = [];

$schemaInfo = fetch_schema_charset($db);
$charsetOk = $schemaInfo && str_starts_with(strtolower((string)$schemaInfo['DEFAULT_CHARACTER_SET_NAME']), 'utf8mb4');
if (!$charsetOk) {
    $errors[] = 'Database ' . DB_NAME . ' charset bukan utf8mb4 (' . ($schemaInfo['DEFAULT_CHARACTER_SET_NAME'] ?? 'unknown') . ')';
}

$tableMap = fetch_table_status_map($db);
foreach ($expectedTables as $tbl) {
    if (!isset($tableMap[$tbl])) {
        $errors[] = "Tabel wajib tidak ditemukan: {$tbl}";
        continue;
    }
    if (strtoupper((string)$tableMap[$tbl]['ENGINE']) !== 'INNODB') {
        $errors[] = "Tabel {$tbl} tidak memakai engine InnoDB ({$tableMap[$tbl]['ENGINE']})";
    }
}

$companiesRes = $db->query('SELECT code, is_active FROM companies');
$companyMap = [];
if ($companiesRes) {
    while ($r = $companiesRes->fetch_assoc()) {
        $companyMap[$r['code']] = (int)$r['is_active'];
    }
}
foreach (['MSP', 'SLN', 'PDM'] as $code) {
    if (!isset($companyMap[$code])) {
        $warnings[] = "Entitas PT default '{$code}' belum ada di tabel companies.";
    } elseif ($companyMap[$code] !== 1) {
        $warnings[] = "Entitas PT default '{$code}' statusnya nonaktif (is_active=0).";
    }
}

$rolesRes = $db->query('SELECT name FROM roles');
$roleNames = [];
if ($rolesRes) {
    while ($r = $rolesRes->fetch_assoc()) {
        $roleNames[$r['name']] = true;
    }
}
foreach (['Super Admin', 'Admin', 'Staff Gudang', 'Viewer'] as $role) {
    if (!isset($roleNames[$role])) {
        $errors[] = "Role default tidak ditemukan: {$role}";
    }
}

$totalIndexes = 0;
$verifiedIndexes = 0;
foreach ($requiredIndexes as $table => $indexes) {
    if (!isset($tableMap[$table])) {
        continue;
    }
    $existing = fetch_table_indexes($db, $table);
    foreach ($indexes as $indexName => $expectedCols) {
        $totalIndexes++;
        if (isset($existing[$indexName])) {
            if ($existing[$indexName] === $expectedCols) {
                $verifiedIndexes++;
            } else {
                $errors[] = sprintf(
                    'Index %s.%s kolom tidak sesuai (ada: %s; diharapkan: %s)',
                    $table,
                    $indexName,
                    implode(',', $existing[$indexName]),
                    implode(',', $expectedCols)
                );
            }
            continue;
        }

        if ($applyMissing) {
            $colSql = implode(', ', array_map('escape_ident', $expectedCols));
            $sql = 'ALTER TABLE ' . escape_ident($table) . ' ADD INDEX ' . escape_ident($indexName) . " ({$colSql})";
            if ($db->query($sql)) {
                $applied[] = "{$table}.{$indexName} (" . implode(',', $expectedCols) . ')';
                $verifiedIndexes++;
            } else {
                $errors[] = "Gagal menambah index {$table}.{$indexName}: {$db->error}";
            }
        } else {
            $errors[] = "Index hilang: {$table}.{$indexName} (" . implode(',', $expectedCols) . ')';
        }
    }
}

$report = [
    'database'          => DB_NAME,
    'host'              => DB_HOST . ':' . DB_PORT,
    'charset'           => $schemaInfo['DEFAULT_CHARACTER_SET_NAME'] ?? null,
    'collation'         => $schemaInfo['DEFAULT_COLLATION_NAME'] ?? null,
    'tables_checked'    => count($expectedTables),
    'indexes_verified'  => $verifiedIndexes,
    'indexes_expected'  => $totalIndexes,
    'indexes_applied'   => $applied,
    'warnings'          => $warnings,
    'errors'            => $errors,
    'status'            => empty($errors) ? 'OK' : 'FAILED',
];

if ($asJson) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
} else {
    echo "=== MSP Warehouse Production DB Verification ===\n";
    echo "Database : {$report['database']} @ {$report['host']}\n";
    echo "Charset  : {$report['charset']} ({$report['collation']})\n";
    echo "Tabel    : {$report['tables_checked']} tabel inti diperiksa\n";
    echo "Index    : {$verifiedIndexes}/{$totalIndexes} composite & lookup indexes terverifikasi (13 tabel)\n";
    if ($applied) {
        echo "Applied  :\n";
        foreach ($applied as $item) {
            echo "  + {$item}\n";
        }
    }
    if ($warnings) {
        echo "Warnings :\n";
        foreach ($warnings as $w) {
            echo "  ! {$w}\n";
        }
    }
    if ($errors) {
        echo "Errors   :\n";
        foreach ($errors as $err) {
            echo "  [FAIL] {$err}\n";
        }
    } else {
        echo "Status   : [PASS] Semua tabel InnoDB utf8mb4, role, PT, dan index siap produksi.\n";
    }
}

exit(empty($errors) ? 0 : 1);
