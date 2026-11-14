<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

require_perm('reports.view');
$db = getDB();
grid_apply_timeout($db, 30.0);

$date_from   = req_date('date_from', date('Y-m-01'));
$date_to     = req_date('date_to',   date('Y-m-d'));
$sup_filter  = req_int('supplier_id');
$cf          = resolve_company_filter('company_id');
$comp_in     = $cf['sql_in'];

$where_parts = ["si.company_id IN ($comp_in)", 'si.transaction_date >= ?', 'si.transaction_date <= ?'];
$params = [$date_from, $date_to];
$types = 'ss';
if ($sup_filter) { $where_parts[] = 'si.supplier_id=?'; $params[] = $sup_filter; $types .= 'i'; }
$where = 'WHERE ' . implode(' AND ', $where_parts);
$format = strtolower(req_str('format', 'csv'));

if ($format === 'pdf' || $format === 'excel') {
    require_once __DIR__ . '/../../includes/export.php';
    $max_rows = $format === 'pdf' ? EXPORT_MASTER_PDF_MAX_ROWS : EXPORT_MASTER_XLSX_MAX_ROWS;
    $limit_q  = $max_rows + 1;
    $p_params = $params;
    $p_params[] = $limit_q;
    $st = $db->prepare("SELECT si.reference_no, comp.code AS comp_code, si.transaction_date, s.name AS supplier_name, l.name AS location_name,
        (SELECT COUNT(*) FROM stock_in_details d WHERE d.stock_in_id=si.id) AS item_count,
        (SELECT COALESCE(SUM(d.quantity),0) FROM stock_in_details d WHERE d.stock_in_id=si.id) AS total_qty,
        (SELECT COALESCE(SUM(d.quantity*d.buy_price),0) FROM stock_in_details d WHERE d.stock_in_id=si.id) AS total_value,
        COALESCE(u.name, '-') AS user_name
        FROM stock_in si JOIN suppliers s ON s.id=si.supplier_id JOIN locations l ON l.id=si.location_id
        LEFT JOIN users u ON u.id=si.user_id LEFT JOIN companies comp ON comp.id=si.company_id
        $where ORDER BY si.id DESC LIMIT ?");
    $st->bind_param($types . 'i', ...$p_params);
    $st->execute();
    $raw = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
    $truncated = count($raw) > $max_rows;
    if ($truncated) array_pop($raw);

    $rows = [];
    foreach ($raw as $idx => $r) {
        $rows[] = [
            $idx + 1,
            $r['reference_no'],
            $r['comp_code'] ?? 'MSP',
            substr((string)$r['transaction_date'], 0, 10),
            $r['supplier_name'],
            $r['location_name'],
            (int)$r['item_count'],
            (int)$r['total_qty'],
            (float)$r['total_value'],
            $r['user_name'],
        ];
    }
    $columns = [
        ['label' => '#',         'type' => 'int',      'align' => 'C', 'pdf_width' => 24,  'xlsx_width' => 6],
        ['label' => 'Referensi', 'type' => 'text',     'align' => 'L', 'pdf_width' => 88,  'xlsx_width' => 18],
        ['label' => 'PT',        'type' => 'text',     'align' => 'C', 'pdf_width' => 42,  'xlsx_width' => 8],
        ['label' => 'Tanggal',   'type' => 'text',     'align' => 'C', 'pdf_width' => 64,  'xlsx_width' => 13],
        ['label' => 'Supplier',  'type' => 'text',     'align' => 'L', 'pdf_width' => 170, 'xlsx_width' => 30],
        ['label' => 'Lokasi',    'type' => 'text',     'align' => 'L', 'pdf_width' => 128, 'xlsx_width' => 22],
        ['label' => 'Item',      'type' => 'int',      'align' => 'R', 'pdf_width' => 40,  'xlsx_width' => 8],
        ['label' => 'Total Qty', 'type' => 'int',      'align' => 'R', 'pdf_width' => 58,  'xlsx_width' => 12],
        ['label' => 'Nilai',     'type' => 'currency', 'align' => 'R', 'pdf_width' => 96,  'xlsx_width' => 16],
        ['label' => 'Oleh',      'type' => 'text',     'align' => 'L', 'pdf_width' => 75,  'xlsx_width' => 16],
    ];
    $subtitle = 'Cakupan: ' . $cf['label'] . ' | Periode: ' . tgl($date_from) . ' - ' . tgl($date_to) . ' | Total: ' . count($rows) . ' transaksi' . ($truncated ? " (maks. {$max_rows})" : '');
    audit_log('export.' . $format, 'report_stock_in', null, '', "Export laporan barang masuk format " . strtoupper($format) . " (" . count($rows) . " transaksi)");
    if ($format === 'pdf') {
        export_pdf_send('laporan_barang_masuk_' . date('Ymd') . '.pdf', 'Laporan Barang Masuk', $subtitle, $columns, $rows, 'L');
    }
    export_xlsx_send('laporan_barang_masuk_' . date('Ymd') . '.xlsx', 'Laporan Barang Masuk', $subtitle, $columns, $rows, 'Barang Masuk');
}

// Print-friendly HTML export (capped at EXPORT_PRINT_MAX_ROWS)
if ($format==='print') {
    audit_log('export.print', 'report_stock_in', null, '', 'Cetak tampilan laporan barang masuk');
    $title='Laporan Barang Masuk'; $subtitle='Cakupan: '.$cf['label'].' | Periode: '.tgl($date_from).' - '.tgl($date_to);
    $print_limit = EXPORT_PRINT_MAX_ROWS + 1;
    $p_params = $params; $p_params[] = $print_limit;
    $st=$db->prepare("SELECT si.reference_no, comp.code comp_code, si.transaction_date, s.name supplier_name, l.name location_name, (SELECT COUNT(*) FROM stock_in_details d WHERE d.stock_in_id=si.id) item_count, (SELECT COALESCE(SUM(d.quantity),0) FROM stock_in_details d WHERE d.stock_in_id=si.id) total_qty, (SELECT COALESCE(SUM(d.quantity*d.buy_price),0) FROM stock_in_details d WHERE d.stock_in_id=si.id) total_value FROM stock_in si JOIN suppliers s ON s.id=si.supplier_id JOIN locations l ON l.id=si.location_id LEFT JOIN companies comp ON comp.id=si.company_id $where ORDER BY si.id DESC LIMIT ?");
    $st->bind_param($types . 'i', ...$p_params);
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
    $truncated = count($rows) > EXPORT_PRINT_MAX_ROWS;
    if ($truncated) array_pop($rows);

    header('Content-Type: text/html; charset=UTF-8');
    $report_cell = static fn($value) => '<td>'.htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8').'</td>';
    echo '<!doctype html><html><head><meta charset="utf-8"><title>'.htmlspecialchars($title,ENT_QUOTES,'UTF-8').'</title><style>body{font:14px Arial,sans-serif;margin:24px}table{border-collapse:collapse;width:100%}th,td{border:1px solid #888;padding:6px;text-align:left}th{background:#eee}.warn{background:#fef3c7;border:1px solid #f59e0b;color:#92400e;padding:8px 12px;margin-bottom:12px;border-radius:4px}@media print{.no-print{display:none}body{margin:0}}</style></head><body><button class="no-print" onclick="window.print()">Print</button><h1>'.htmlspecialchars($title,ENT_QUOTES,'UTF-8').'</h1><p>'.htmlspecialchars($subtitle??'',ENT_QUOTES,'UTF-8').'</p>';
    if ($truncated) {
        echo '<p class="warn">Tampilan cetak dibatasi maksimal '.number_format(EXPORT_PRINT_MAX_ROWS,0,',','.').' transaksi terbaru. Gunakan Export CSV atau persempit rentang tanggal.</p>';
    }
    echo '<table><thead><tr>';
    foreach(['Referensi','PT','Tanggal','Supplier','Lokasi','Items','Qty','Nilai'] as $h) echo '<th>'.htmlspecialchars($h,ENT_QUOTES,'UTF-8').'</th>';
    echo '</tr></thead><tbody>';
    foreach($rows as $r) {
        echo '<tr>';
        $cells=[$r['reference_no'],$r['comp_code']??'MSP',tgl($r['transaction_date']),$r['supplier_name'],$r['location_name'],$r['item_count'],number_format($r['total_qty']),idr((float)$r['total_value'])];
        foreach($cells as $cell) echo $report_cell($cell);
        echo '</tr>';
    }
    echo '</tbody></table></body></html>'; exit;
}

// Chunked keyset CSV export (capped at EXPORT_CSV_MAX_ROWS, formula-safe)
audit_log('export.csv', 'report_stock_in', null, '', 'Export CSV laporan barang masuk');
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="barang_masuk_'.date('Ymd').'.csv"');
echo "\xEF\xBB\xBF";
$out=fopen('php://output','w');
fputcsv($out, ['Referensi','PT','Tanggal','Supplier','Lokasi','Item','Total Qty','Nilai','Oleh'], ',', '"', '\\');

$chunk_size = 5000;
$last_id = PHP_INT_MAX;
$exported = 0;
$hit_cap = false;
while ($exported < EXPORT_CSV_MAX_ROWS) {
    $take = min($chunk_size, EXPORT_CSV_MAX_ROWS - $exported + 1);
    $sql = "SELECT si.id, si.reference_no, comp.code comp_code, si.transaction_date, s.name supplier_name, l.name location_name,
        (SELECT COUNT(*) FROM stock_in_details d WHERE d.stock_in_id=si.id) item_count,
        (SELECT COALESCE(SUM(d.quantity),0) FROM stock_in_details d WHERE d.stock_in_id=si.id) total_qty,
        (SELECT COALESCE(SUM(d.quantity*d.buy_price),0) FROM stock_in_details d WHERE d.stock_in_id=si.id) total_value,
        u.name user_name
        FROM stock_in si JOIN suppliers s ON s.id=si.supplier_id JOIN locations l ON l.id=si.location_id JOIN users u ON u.id=si.user_id LEFT JOIN companies comp ON comp.id=si.company_id
        $where AND si.id < ? ORDER BY si.id DESC LIMIT ?";
    $c_params = $params;
    $c_params[] = $last_id;
    $c_params[] = $take;
    $st = $db->prepare($sql);
    $st->bind_param($types . 'ii', ...$c_params);
    $st->execute();
    $rs = $st->get_result();
    $count_batch = 0;
    while ($r = $rs->fetch_assoc()) {
        if ($exported >= EXPORT_CSV_MAX_ROWS) { $hit_cap = true; break; }
        $last_id = (int)$r['id'];
        fputcsv($out, csv_safe_row([
            $r['reference_no'], $r['comp_code'] ?? 'MSP', $r['transaction_date'],
            $r['supplier_name'], $r['location_name'], $r['item_count'],
            $r['total_qty'], $r['total_value'], $r['user_name']
        ]), ',', '"', '\\');
        $exported++;
        $count_batch++;
    }
    $rs->free();
    $st->close();
    fflush($out);
    if ($hit_cap || $count_batch < $take) break;
}
if ($hit_cap) {
    fputcsv($out, csv_safe_row(['CATATAN: Export dibatasi maksimal ' . EXPORT_CSV_MAX_ROWS . ' baris. Persempit rentang tanggal untuk mengunduh data berikutnya.']), ',', '"', '\\');
}
fclose($out);
exit;
