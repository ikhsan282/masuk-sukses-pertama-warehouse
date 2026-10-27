<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

require_perm('reports.view');
$db = getDB();

$supplier_filter = req_int('supplier_id');
$start_date = req_str('start_date', date('Y-m-01'));
$end_date = req_str('end_date', date('Y-m-d'));

$where_parts = ['po.order_date BETWEEN ? AND ?'];
$params = [$start_date, $end_date];
$types = 'ss';

if ($supplier_filter) {
    $where_parts[] = 's.id = ?';
    $params[] = $supplier_filter;
    $types .= 'i';
}

$where = 'WHERE ' . implode(' AND ', $where_parts);

if (req_str('format') === 'print') {
    $title = 'Laporan Performa Supplier';
    $subtitle = 'Periode: ' . tgl($start_date) . ' - ' . tgl($end_date);
    $query = "SELECT s.code, s.name, COUNT(DISTINCT po.id) AS total_po,
        SUM(CASE WHEN po.status='completed' THEN 1 ELSE 0 END) AS completed_po,
        AVG(CASE WHEN po.received_at IS NOT NULL AND po.order_date IS NOT NULL THEN DATEDIFF(po.received_at, po.order_date) ELSE NULL END) AS avg_lead_time,
        ROUND((SUM(CASE WHEN po.received_at IS NOT NULL AND po.expected_date IS NOT NULL AND po.received_at <= po.expected_date THEN 1 ELSE 0 END) / NULLIF(SUM(CASE WHEN po.received_at IS NOT NULL AND po.expected_date IS NOT NULL THEN 1 ELSE 0 END), 0)) * 100, 2) AS on_time_rate,
        COALESCE(SUM(pod.quantity * pod.buy_price), 0) AS total_value
        FROM suppliers s
        LEFT JOIN purchase_orders po ON s.id=po.supplier_id AND po.order_date BETWEEN ? AND ?
        LEFT JOIN purchase_order_details pod ON po.id=pod.po_id " . ($supplier_filter ? "WHERE s.id=?" : "") . " GROUP BY s.id ORDER BY total_value DESC";
    $params = [$start_date, $end_date];
    $types = 'ss';
    if ($supplier_filter) { $params[] = $supplier_filter; $types .= 'i'; }
    $st = $db->prepare($query);
    $st->bind_param($types, ...$params);
    $st->execute();
    $rs = $st->get_result();
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><html><head><meta charset="utf-8"><title>'.htmlspecialchars($title, ENT_QUOTES, 'UTF-8').'</title><style>body{font:14px Arial,sans-serif;margin:24px}table{border-collapse:collapse;width:100%}th,td{border:1px solid #888;padding:6px;text-align:left}th{background:#eee}@media print{.no-print{display:none}body{margin:0}}</style></head><body><button class="no-print" onclick="window.print()">Cetak</button><h1>'.htmlspecialchars($title, ENT_QUOTES, 'UTF-8').'</h1><p>'.htmlspecialchars($subtitle, ENT_QUOTES, 'UTF-8').'</p><table><thead><tr>';
    foreach (['Kode','Supplier','PO','Selesai','Lead Time','On-Time %','Nilai'] as $heading) echo '<th>'.htmlspecialchars($heading, ENT_QUOTES, 'UTF-8').'</th>';
    echo '</tr></thead><tbody>';
    while ($row = $rs->fetch_assoc()) {
        $cells = [$row['code'], $row['name'], $row['total_po'], $row['completed_po'], $row['avg_lead_time'] !== null ? round($row['avg_lead_time'], 1).' hari' : '-', $row['on_time_rate'] !== null ? $row['on_time_rate'].'%' : '-', idr((float)$row['total_value'])];
        echo '<tr>';
        foreach ($cells as $cell) echo '<td>'.htmlspecialchars((string)$cell, ENT_QUOTES, 'UTF-8').'</td>';
        echo '</tr>';
    }
    $st->close();
    echo '</tbody></table></body></html>';
    exit;
}

// CSV export
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="supplier_performance_'.date('Ymd').'.csv"');
echo "\xEF\xBB\xBF";
$out = fopen('php://output', 'w');
fputcsv($out, ['Kode', 'Nama Supplier', 'Kontak', 'Telepon', 'Total PO', 'PO Selesai', 
               'Rata-rata Lead Time (hari)', 'On-Time Rate (%)', 'Total Nilai'], ',', '"', '\\');

$query = "SELECT s.code, s.name, s.contact_person, s.phone,
    COUNT(DISTINCT po.id) as total_po,
    SUM(CASE WHEN po.status='completed' THEN 1 ELSE 0 END) as completed_po,
    AVG(CASE WHEN po.received_at IS NOT NULL AND po.order_date IS NOT NULL 
        THEN DATEDIFF(po.received_at, po.order_date) ELSE NULL END) as avg_lead_time,
    ROUND((SUM(CASE WHEN po.received_at IS NOT NULL AND po.expected_date IS NOT NULL 
        AND po.received_at <= po.expected_date THEN 1 ELSE 0 END) / 
     NULLIF(SUM(CASE WHEN po.received_at IS NOT NULL AND po.expected_date IS NOT NULL THEN 1 ELSE 0 END), 0)) * 100, 2) as on_time_rate,
    COALESCE(SUM(pod.quantity * pod.buy_price), 0) as total_value
FROM suppliers s
LEFT JOIN purchase_orders po ON s.id = po.supplier_id AND po.order_date BETWEEN ? AND ?
LEFT JOIN purchase_order_details pod ON po.id = pod.po_id
" . ($supplier_filter ? "WHERE s.id = ?" : "") . "
GROUP BY s.id ORDER BY total_value DESC";

$st = $db->prepare($query);
$qp = [$start_date, $end_date];
$qt = 'ss';
if ($supplier_filter) { $qp[] = $supplier_filter; $qt .= 'i'; }
$st->bind_param($qt, ...$qp);
$st->execute(); $rs=$st->get_result();
while($r=$rs->fetch_assoc()) {
    $completion_rate = $r['total_po'] > 0 ? round(($r['completed_po'] / $r['total_po']) * 100, 1) : 0;
    $lead_time = $r['avg_lead_time'] !== null ? round($r['avg_lead_time'], 1) : '';
    $on_time = $r['on_time_rate'] !== null ? $r['on_time_rate'] : '';
    
    fputcsv($out, [
        $r['code'],
        $r['name'],
        $r['contact_person'] ?: '',
        $r['phone'] ?: '',
        $r['total_po'],
        $r['completed_po'] . ' (' . $completion_rate . '%)',
        $lead_time,
        $on_time,
        $r['total_value']
    ], ',', '"', '\\');
}
$st->close();
fclose($out);
exit;
