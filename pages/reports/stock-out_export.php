<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

require_perm('reports.view');
$db = getDB();
grid_apply_timeout($db, 30.0);

$date_from  = req_date('date_from', date('Y-m-01'));
$date_to    = req_date('date_to',   date('Y-m-d'));
$loc_filter = req_int('location_id');
$cf         = resolve_company_filter('company_id');
$comp_in    = $cf['sql_in'];

$where_parts = ["so.company_id IN ($comp_in)", 'so.transaction_date >= ?', 'so.transaction_date <= ?'];
$params = [$date_from, $date_to];
$types = 'ss';
if ($loc_filter) { $where_parts[] = 'so.location_id=?'; $params[] = $loc_filter; $types .= 'i'; }
$where = 'WHERE ' . implode(' AND ', $where_parts);

// Print-friendly HTML export (capped at EXPORT_PRINT_MAX_ROWS)
if (req_str('format')==='print') {
    $title='Laporan Barang Keluar'; $subtitle='Cakupan: '.$cf['label'].' | Periode: '.tgl($date_from).' - '.tgl($date_to);
    $tot=$db->prepare("SELECT COALESCE(SUM(d.quantity),0) FROM stock_out so JOIN stock_out_details d ON d.stock_out_id=so.id $where");
    $tot->bind_param($types,...$params);$tot->execute();$tot->bind_result($period_qty);$tot->fetch();$tot->close();
    $subtitle.=' | Total Qty: '.number_format((float)$period_qty).' unit';

    $print_limit = EXPORT_PRINT_MAX_ROWS + 1;
    $p_params = $params; $p_params[] = $print_limit;
    $st=$db->prepare("SELECT so.reference_no, comp.code AS comp_code, so.transaction_date, so.recipient, l.name AS location_name, (SELECT COALESCE(SUM(d.quantity),0) FROM stock_out_details d WHERE d.stock_out_id=so.id) AS total_qty FROM stock_out so JOIN locations l ON l.id=so.location_id LEFT JOIN companies comp ON comp.id=so.company_id $where ORDER BY so.id DESC LIMIT ?");
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
    foreach(['Ref','PT','Tgl','Lokasi','Penerima','Qty'] as $h) echo '<th>'.htmlspecialchars($h,ENT_QUOTES,'UTF-8').'</th>';
    echo '</tr></thead><tbody>';
    foreach($rows as $r) {
        echo '<tr>';
        $cells=[$r['reference_no'],$r['comp_code']??'MSP',substr($r['transaction_date'],0,10),$r['location_name'],$r['recipient']??'-',number_format((float)$r['total_qty'])];
        foreach($cells as $cell) echo $report_cell($cell);
        echo '</tr>';
    }
    echo '</tbody></table></body></html>'; exit;
}

// Chunked keyset CSV export (capped at EXPORT_CSV_MAX_ROWS, formula-safe)
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="barang_keluar_'.date('Ymd').'.csv"');
echo "\xEF\xBB\xBF";
$out=fopen('php://output','w');
fputcsv($out, ['Referensi','PT','Tanggal','Lokasi','Penerima','Keperluan','Item','Total Qty','Oleh'], ',', '"', '\\');

$chunk_size = 5000;
$last_id = PHP_INT_MAX;
$exported = 0;
$hit_cap = false;
while ($exported < EXPORT_CSV_MAX_ROWS) {
    $take = min($chunk_size, EXPORT_CSV_MAX_ROWS - $exported + 1);
    $sql = "SELECT so.id, so.reference_no, comp.code AS comp_code, so.transaction_date, so.recipient, so.purpose,
        l.name AS location_name, u.name AS user_name,
        (SELECT COUNT(*) FROM stock_out_details d WHERE d.stock_out_id=so.id) AS item_count,
        (SELECT COALESCE(SUM(d.quantity),0) FROM stock_out_details d WHERE d.stock_out_id=so.id) AS total_qty
        FROM stock_out so JOIN locations l ON l.id=so.location_id JOIN users u ON u.id=so.user_id LEFT JOIN companies comp ON comp.id=so.company_id
        $where AND so.id < ? ORDER BY so.id DESC LIMIT ?";
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
            $r['location_name'], $r['recipient'] ?? '-', $r['purpose'] ?? '-',
            $r['item_count'], $r['total_qty'], $r['user_name']
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
