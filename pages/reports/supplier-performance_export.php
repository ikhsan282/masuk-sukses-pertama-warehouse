<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

require_perm('reports.view');
$db = getDB();
grid_apply_timeout($db, 30.0);

$supplier_filter = req_int('supplier_id');
$start_date = req_date('start_date', date('Y-m-01'));
$end_date   = req_date('end_date', date('Y-m-d'));
$cf         = resolve_company_filter('company_id');
$comp_in    = $cf['sql_in'];

$base_sql = "SELECT s.code, s.name, s.contact_person, s.phone,
    COUNT(DISTINCT po.id) AS total_po,
    SUM(CASE WHEN po.status='completed' THEN 1 ELSE 0 END) AS completed_po,
    AVG(CASE WHEN po.received_at IS NOT NULL AND po.order_date IS NOT NULL THEN DATEDIFF(po.received_at, po.order_date) ELSE NULL END) AS avg_lead_time,
    ROUND((SUM(CASE WHEN po.received_at IS NOT NULL AND po.expected_date IS NOT NULL AND po.received_at <= po.expected_date THEN 1 ELSE 0 END) / NULLIF(SUM(CASE WHEN po.received_at IS NOT NULL AND po.expected_date IS NOT NULL THEN 1 ELSE 0 END), 0)) * 100, 2) AS on_time_rate,
    COALESCE(SUM(pod.quantity * pod.buy_price), 0) AS total_value
    FROM suppliers s
    LEFT JOIN purchase_orders po ON s.id=po.supplier_id AND po.company_id IN ($comp_in) AND po.order_date BETWEEN ? AND ?
    LEFT JOIN purchase_order_details pod ON po.id=pod.po_id
    WHERE s.is_active=1" . ($supplier_filter ? " AND s.id=?" : "") . "
    GROUP BY s.id ORDER BY total_value DESC";

if (req_str('format') === 'print') {
    $title = 'Laporan Performa Supplier';
    $subtitle = 'Cakupan: ' . $cf['label'] . ' | Periode: ' . tgl($start_date) . ' - ' . tgl($end_date);
    $print_limit = EXPORT_PRINT_MAX_ROWS + 1;
    $params = [$start_date, $end_date];
    $types = 'ss';
    if ($supplier_filter) { $params[] = $supplier_filter; $types .= 'i'; }
    $params[] = $print_limit; $types .= 'i';
    $st = $db->prepare($base_sql . " LIMIT ?");
    $st->bind_param($types, ...$params);
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
    $truncated = count($rows) > EXPORT_PRINT_MAX_ROWS;
    if ($truncated) array_pop($rows);

    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><html><head><meta charset="utf-8"><title>'.htmlspecialchars($title, ENT_QUOTES, 'UTF-8').'</title><style>body{font:14px Arial,sans-serif;margin:24px}table{border-collapse:collapse;width:100%}th,td{border:1px solid #888;padding:6px;text-align:left}th{background:#eee}.warn{background:#fef3c7;border:1px solid #f59e0b;color:#92400e;padding:8px 12px;margin-bottom:12px;border-radius:4px}@media print{.no-print{display:none}body{margin:0}}</style></head><body><button class="no-print" onclick="window.print()">Cetak</button><h1>'.htmlspecialchars($title, ENT_QUOTES, 'UTF-8').'</h1><p>'.htmlspecialchars($subtitle, ENT_QUOTES, 'UTF-8').'</p>';
    if ($truncated) {
        echo '<p class="warn">Tampilan cetak dibatasi maksimal '.number_format(EXPORT_PRINT_MAX_ROWS,0,',','.').' supplier. Gunakan Export CSV.</p>';
    }
    echo '<table><thead><tr>';
    foreach (['Kode','Supplier','PO','Selesai','Lead Time','On-Time %','Nilai'] as $heading) echo '<th>'.htmlspecialchars($heading, ENT_QUOTES, 'UTF-8').'</th>';
    echo '</tr></thead><tbody>';
    foreach ($rows as $row) {
        $cells = [$row['code'], $row['name'], $row['total_po'], $row['completed_po'], $row['avg_lead_time'] !== null ? round($row['avg_lead_time'], 1).' hari' : '-', $row['on_time_rate'] !== null ? $row['on_time_rate'].'%' : '-', idr((float)$row['total_value'])];
        echo '<tr>';
        foreach ($cells as $cell) echo '<td>'.htmlspecialchars((string)$cell, ENT_QUOTES, 'UTF-8').'</td>';
        echo '</tr>';
    }
    echo '</tbody></table></body></html>';
    exit;
}

// CSV export (capped at EXPORT_CSV_MAX_ROWS, formula-safe)
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="supplier_performance_'.date('Ymd').'.csv"');
echo "\xEF\xBB\xBF";
$out = fopen('php://output', 'w');
fputcsv($out, ['Kode', 'Nama Supplier', 'Kontak', 'Telepon', 'Total PO', 'PO Selesai',
               'Rata-rata Lead Time (hari)', 'On-Time Rate (%)', 'Total Nilai'], ',', '"', '\\');

$csv_limit = EXPORT_CSV_MAX_ROWS + 1;
$qp = [$start_date, $end_date];
$qt = 'ss';
if ($supplier_filter) { $qp[] = $supplier_filter; $qt .= 'i'; }
$qp[] = $csv_limit; $qt .= 'i';

$st = $db->prepare($base_sql . " LIMIT ?");
$st->bind_param($qt, ...$qp);
$st->execute();
$rs = $st->get_result();
$exported = 0;
$hit_cap = false;
while ($r = $rs->fetch_assoc()) {
    if ($exported >= EXPORT_CSV_MAX_ROWS) { $hit_cap = true; break; }
    $completion_rate = $r['total_po'] > 0 ? round(($r['completed_po'] / $r['total_po']) * 100, 1) : 0;
    $lead_time = $r['avg_lead_time'] !== null ? round($r['avg_lead_time'], 1) : '';
    $on_time = $r['on_time_rate'] !== null ? $r['on_time_rate'] : '';
    fputcsv($out, csv_safe_row([
        $r['code'],
        $r['name'],
        $r['contact_person'] ?: '',
        $r['phone'] ?: '',
        $r['total_po'],
        $r['completed_po'] . ' (' . $completion_rate . '%)',
        $lead_time,
        $on_time,
        $r['total_value']
    ]), ',', '"', '\\');
    $exported++;
}
if ($hit_cap) {
    fputcsv($out, csv_safe_row(['CATATAN: Export dibatasi maksimal ' . EXPORT_CSV_MAX_ROWS . ' supplier.']), ',', '"', '\\');
}
$rs->free();
$st->close();
fclose($out);
exit;
