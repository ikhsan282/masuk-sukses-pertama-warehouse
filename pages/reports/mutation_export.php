<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

require_perm('reports.view');
$db = getDB();

$date_from  = req_str('date_from', date('Y-m-01'));
$date_to    = req_str('date_to',   date('Y-m-d'));
$item_filter= req_int('item_id');
$type_filter= req_str('type');

$where_parts = ['m.created_at >= ?', 'm.created_at <= ?'];
$params = []; $types = '';
$df = $date_from.' 00:00:00'; $dt = $date_to.' 23:59:59';
$params[]=&$df; $params[]=&$dt; $types.='ss';
if ($item_filter) { $where_parts[]='m.item_id=?'; $params[]=&$item_filter; $types.='i'; }
if ($type_filter) { $where_parts[]='m.type=?';    $params[]=&$type_filter; $types.='s'; }
$where='WHERE '.implode(' AND ',$where_parts);

// Print-friendly HTML export
if (req_str('format')==='print') {
$title='Laporan Mutasi Barang'; $subtitle='Periode: '.tgl($date_from).' - '.tgl($date_to);
$st=$db->prepare("SELECT m.created_at, i.code AS item_code, i.name AS item_name, m.type, l.name AS loc_name, m.quantity, m.reference_no, u.abbreviation FROM mutations m JOIN items i ON i.id=m.item_id JOIN units u ON u.id=i.unit_id JOIN locations l ON l.id=m.location_id JOIN users us ON us.id=m.user_id $where ORDER BY m.id DESC");$st->bind_param($types,...$params);
$st->execute(); $rs=$st->get_result();
header('Content-Type: text/html; charset=UTF-8');
function report_cell($value) { return '<td>'.htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8').'</td>'; }
echo '<!doctype html><html><head><meta charset="utf-8"><title>'.htmlspecialchars($title,ENT_QUOTES,'UTF-8').'</title><style>body{font:14px Arial,sans-serif;margin:24px}table{border-collapse:collapse;width:100%}th,td{border:1px solid #888;padding:6px;text-align:left}th{background:#eee}@media print{.no-print{display:none}body{margin:0}}</style></head><body><button class="no-print" onclick="window.print()">Print</button><h1>'.htmlspecialchars($title,ENT_QUOTES,'UTF-8').'</h1><p>'.htmlspecialchars($subtitle??'',ENT_QUOTES,'UTF-8').'</p><table><thead><tr>';
foreach(['Waktu','Kode','Barang','Tipe','Lokasi','Qty','Ref'] as $h) echo '<th>'.htmlspecialchars($h,ENT_QUOTES,'UTF-8').'</th>';
echo '</tr></thead><tbody>';
while($r=$rs->fetch_assoc()) { $type_map=['in'=>'Masuk','out'=>'Keluar','transfer_in'=>'Transfer In','transfer_out'=>'Transfer Out','adjustment'=>'Penyesuaian']; $cells=[date('d/m H:i',strtotime($r['created_at'])),$r['item_code'],$r['item_name'],$type_map[$r['type']]??$r['type'],$r['loc_name'],$r['quantity'].' '.$r['abbreviation'],$r['reference_no']]; foreach($cells as $cell) echo report_cell($cell); echo '</tr>'; }
$st->close(); echo '</tbody></table></body></html>'; exit;

}

// CSV export
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="mutasi_'.date('Ymd').'.csv"');
echo "\xEF\xBB\xBF";
$out=fopen('php://output','w');
fputcsv($out, ['Waktu','Kode Barang','Nama Barang','Tipe','Lokasi','Qty','Satuan','Referensi','Oleh'], ',', '"', '\\');

$st=$db->prepare("SELECT m.created_at, i.code AS item_code, i.name AS item_name, m.type,
    l.name AS loc_name, m.quantity, u.abbreviation, m.reference_no, us.name AS user_name
    FROM mutations m JOIN items i ON i.id=m.item_id JOIN units u ON u.id=i.unit_id
    JOIN locations l ON l.id=m.location_id JOIN users us ON us.id=m.user_id
    $where ORDER BY m.id DESC");
$st->bind_param($types,...$params);
$st->execute(); $rs=$st->get_result();
while($r=$rs->fetch_assoc()) {
    $type_map=['in'=>'Masuk','out'=>'Keluar','transfer_in'=>'Transfer In','transfer_out'=>'Transfer Out','adjustment'=>'Penyesuaian'];
    fputcsv($out, [
        $r['created_at'],
        $r['item_code'],
        $r['item_name'],
        $type_map[$r['type']]??$r['type'],
        $r['loc_name'],
        $r['quantity'],
        $r['abbreviation'],
        $r['reference_no'],
        $r['user_name']
    ], ',', '"', '\\');
}
$st->close();
fclose($out);
exit;
