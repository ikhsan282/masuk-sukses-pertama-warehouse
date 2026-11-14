<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

$mode   = req_str('mode', 'stock') === 'alerts' ? 'alerts' : 'stock';
$format = strtolower(req_str('format', 'csv'));

require_perm($mode === 'alerts' ? 'stock.alerts' : 'stock.view');
$db = getDB();
grid_apply_timeout($db, 30.0);

$search     = grid_search_term('q');
$loc_filter = $mode === 'stock' ? req_int('location_id') : 0;

if ($mode === 'alerts') {
    $cid        = (int)current_company_id();
    $comp_label = '[' . (current_company()['code'] ?? 'MSP') . '] ' . (current_company()['name'] ?? COMPANY_NAME);
    $where_parts = [
        'i.is_active = 1',
        "COALESCE((SELECT SUM(s.quantity) FROM stock s WHERE s.item_id = i.id AND s.company_id = {$cid}), 0) <= i.min_stock",
    ];
    $params = [];
    $types  = '';
    if ($search !== '') {
        $where_parts[] = '(i.code LIKE ? OR i.name LIKE ?)';
        $like = '%' . escape_like($search) . '%';
        $params[] = $like;
        $params[] = $like;
        $types   .= 'ss';
    }
    $where = 'WHERE ' . implode(' AND ', $where_parts);

    $title       = 'Peringatan Stok Menipis';
    $entity_type = 'stock_alerts';
    $file_prefix = 'peringatan_stok_' . date('Ymd');
    $sheet_title = 'Peringatan Stok';
    $columns = [
        ['label' => '#',              'type' => 'int',      'align' => 'C', 'pdf_width' => 26,  'xlsx_width' => 6],
        ['label' => 'Kode',           'type' => 'text',     'align' => 'C', 'pdf_width' => 76,  'xlsx_width' => 14],
        ['label' => 'Nama Barang',    'type' => 'text',     'align' => 'L', 'pdf_width' => 235, 'xlsx_width' => 36],
        ['label' => 'Satuan',         'type' => 'text',     'align' => 'C', 'pdf_width' => 48,  'xlsx_width' => 9],
        ['label' => 'Stok Saat Ini',  'type' => 'int',      'align' => 'R', 'pdf_width' => 65,  'xlsx_width' => 12],
        ['label' => 'Minimum',        'type' => 'int',      'align' => 'R', 'pdf_width' => 60,  'xlsx_width' => 10],
        ['label' => 'Saran Pesan',    'type' => 'int',      'align' => 'R', 'pdf_width' => 72,  'xlsx_width' => 12],
        ['label' => 'Estimasi Nilai', 'type' => 'currency', 'align' => 'R', 'pdf_width' => 120, 'xlsx_width' => 18],
    ];
    $select_sql = "SELECT i.id, i.code, i.name, u.abbreviation, i.min_stock, i.buy_price,
        COALESCE((SELECT SUM(s.quantity) FROM stock s WHERE s.item_id = i.id AND s.company_id = {$cid}), 0) AS total_stock,
        GREATEST(i.min_stock * 2 - COALESCE((SELECT SUM(s.quantity) FROM stock s WHERE s.item_id = i.id AND s.company_id = {$cid}), 0), 1) AS suggested_qty
        FROM items i JOIN units u ON u.id = i.unit_id $where ORDER BY total_stock ASC, i.name ASC LIMIT ?";
} else {
    $cf         = resolve_company_filter('company_id');
    $comp_in    = $cf['sql_in'];
    $comp_label = $cf['label'];
    $where_parts = ['i.is_active = 1'];
    $params = [];
    $types  = '';
    if ($search !== '') {
        $where_parts[] = '(i.name LIKE ? OR i.code LIKE ?)';
        $like = '%' . escape_like($search) . '%';
        $params[] = $like;
        $params[] = $like;
        $types   .= 'ss';
    }
    if ($loc_filter) {
        $where_parts[] = "EXISTS (SELECT 1 FROM stock sx WHERE sx.item_id = i.id AND sx.company_id IN ($comp_in) AND sx.location_id = ?)";
        $params[] = $loc_filter;
        $types   .= 'i';
        $stock_sub = "(SELECT COALESCE(SUM(s.quantity), 0) FROM stock s WHERE s.item_id = i.id AND s.company_id IN ($comp_in) AND s.location_id = " . (int)$loc_filter . ")";
    } else {
        $stock_sub = "(SELECT COALESCE(SUM(s.quantity), 0) FROM stock s WHERE s.item_id = i.id AND s.company_id IN ($comp_in))";
    }
    $where = 'WHERE ' . implode(' AND ', $where_parts);

    $title       = 'Daftar Cek Stok Barang';
    $entity_type = 'stock';
    $file_prefix = 'cek_stok_' . date('Ymd');
    $sheet_title = 'Cek Stok';
    $columns = [
        ['label' => '#',          'type' => 'int',      'align' => 'C', 'pdf_width' => 24,  'xlsx_width' => 6],
        ['label' => 'Kode',       'type' => 'text',     'align' => 'C', 'pdf_width' => 70,  'xlsx_width' => 14],
        ['label' => 'Nama Barang','type' => 'text',     'align' => 'L', 'pdf_width' => 195, 'xlsx_width' => 34],
        ['label' => 'Kategori',   'type' => 'text',     'align' => 'L', 'pdf_width' => 115, 'xlsx_width' => 22],
        ['label' => 'Satuan',     'type' => 'text',     'align' => 'C', 'pdf_width' => 44,  'xlsx_width' => 9],
        ['label' => 'Stok',       'type' => 'int',      'align' => 'R', 'pdf_width' => 56,  'xlsx_width' => 10],
        ['label' => 'Min. Stok',  'type' => 'int',      'align' => 'R', 'pdf_width' => 54,  'xlsx_width' => 10],
        ['label' => 'Status',     'type' => 'text',     'align' => 'C', 'pdf_width' => 58,  'xlsx_width' => 12],
        ['label' => 'Nilai Stok', 'type' => 'currency', 'align' => 'R', 'pdf_width' => 120, 'xlsx_width' => 18],
    ];
    $select_sql = "SELECT i.id, i.code, i.name, c.name AS cat_name, u.abbreviation, i.min_stock, i.buy_price,
        $stock_sub AS total_stock
        FROM items i JOIN categories c ON c.id = i.category_id JOIN units u ON u.id = i.unit_id
        $where ORDER BY i.code ASC LIMIT ?";
}

$format_row = static function (array $r, int $idx) use ($mode): array {
    $ts = (int)$r['total_stock'];
    if ($mode === 'alerts') {
        $sug = (int)$r['suggested_qty'];
        $est = $sug * (float)$r['buy_price'];
        return [$idx + 1, $r['code'], $r['name'], $r['abbreviation'], $ts, (int)$r['min_stock'], $sug, $est];
    }
    $sl = $ts === 0 ? 'Habis' : ($ts <= (int)$r['min_stock'] ? 'Menipis' : 'Aman');
    return [$idx + 1, $r['code'], $r['name'], $r['cat_name'], $r['abbreviation'], $ts, (int)$r['min_stock'], $sl, $ts * (float)$r['buy_price']];
};

if ($format === 'pdf' || $format === 'excel') {
    require_once __DIR__ . '/../../includes/export.php';
    $max_rows   = $format === 'pdf' ? EXPORT_MASTER_PDF_MAX_ROWS : EXPORT_MASTER_XLSX_MAX_ROWS;
    $limit_q    = $max_rows + 1;
    $q_params   = $params;
    $q_params[] = $limit_q;

    $st = $db->prepare($select_sql);
    $st->bind_param($types . 'i', ...$q_params);
    $st->execute();
    $raw = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();

    $truncated = count($raw) > $max_rows;
    if ($truncated) array_pop($raw);

    $rows = [];
    foreach ($raw as $idx => $r) {
        $rows[] = $format_row($r, $idx);
    }

    $subtitle = 'Cakupan: ' . $comp_label . ' | Total: ' . count($rows) . ' item' . ($truncated ? " (maks. {$max_rows})" : '');
    audit_log('export.' . $format, $entity_type, null, '', "Export {$title} format " . strtoupper($format) . ' (' . count($rows) . ' item)');
    if ($format === 'pdf') {
        export_pdf_send($file_prefix . '.pdf', $title, $subtitle, $columns, $rows, 'L');
    }
    export_xlsx_send($file_prefix . '.xlsx', $title, $subtitle, $columns, $rows, $sheet_title);
}

if ($format === 'print') {
    audit_log('export.print', $entity_type, null, '', "Cetak tampilan {$title}");
    $print_limit = EXPORT_PRINT_MAX_ROWS + 1;
    $q_params    = $params;
    $q_params[]  = $print_limit;

    $st = $db->prepare($select_sql);
    $st->bind_param($types . 'i', ...$q_params);
    $st->execute();
    $raw = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();

    $truncated = count($raw) > EXPORT_PRINT_MAX_ROWS;
    if ($truncated) array_pop($raw);

    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><html lang="id"><head><meta charset="utf-8"><title>' . e($title) . '</title>'
       . '<style>body{font:13px Arial,sans-serif;margin:24px}table{border-collapse:collapse;width:100%;margin-top:12px}th,td{border:1px solid #888;padding:6px}th{background:#eee;text-align:left}.text-end{text-align:right}.warn{background:#fef3c7;border:1px solid #f59e0b;color:#92400e;padding:8px 12px;margin-bottom:12px;border-radius:4px}@media print{.no-print{display:none}body{margin:0}}</style>'
       . '</head><body><button class="no-print" onclick="window.print()">Cetak</button>'
       . '<h2>' . e($title) . '</h2><p>Cakupan: ' . e($comp_label) . ' &middot; Tanggal Cetak: ' . date('d/m/Y H:i') . '</p>';
    if ($truncated) {
        echo '<p class="warn">Tampilan cetak dibatasi maksimal ' . number_format(EXPORT_PRINT_MAX_ROWS, 0, ',', '.') . ' item. Gunakan Export Excel atau CSV untuk seluruh data.</p>';
    }
    echo '<table><thead><tr>';
    foreach ($columns as $col) {
        echo '<th' . ($col['align'] === 'R' ? ' class="text-end"' : '') . '>' . e($col['label']) . '</th>';
    }
    echo '</tr></thead><tbody>';
    foreach ($raw as $idx => $r) {
        $cells = $format_row($r, $idx);
        echo '<tr>';
        foreach ($cells as $cIdx => $val) {
            $col = $columns[$cIdx];
            $disp = $col['type'] === 'currency' ? idr((float)$val) : (string)$val;
            echo '<td' . ($col['align'] === 'R' ? ' class="text-end"' : '') . '>' . e($disp) . '</td>';
        }
        echo '</tr>';
    }
    echo '</tbody></table></body></html>';
    exit;
}

audit_log('export.csv', $entity_type, null, '', "Export CSV {$title}");
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $file_prefix . '.csv"');
echo "\xEF\xBB\xBF";
$out = fopen('php://output', 'w');
fputcsv($out, array_map(fn($c) => $c['label'], $columns), ',', '"', '\\');

$limit_q    = EXPORT_CSV_MAX_ROWS + 1;
$q_params   = $params;
$q_params[] = $limit_q;
$st = $db->prepare($select_sql);
$st->bind_param($types . 'i', ...$q_params);
$st->execute();
$rs = $st->get_result();

$exported = 0;
$hit_cap  = false;
while ($r = $rs->fetch_assoc()) {
    if ($exported >= EXPORT_CSV_MAX_ROWS) {
        $hit_cap = true;
        break;
    }
    fputcsv($out, csv_safe_row($format_row($r, $exported)), ',', '"', '\\');
    $exported++;
}
$rs->free();
$st->close();
if ($hit_cap) {
    fputcsv($out, csv_safe_row(['CATATAN: Export dibatasi maksimal ' . EXPORT_CSV_MAX_ROWS . ' baris.']), ',', '"', '\\');
}
fclose($out);
exit;
