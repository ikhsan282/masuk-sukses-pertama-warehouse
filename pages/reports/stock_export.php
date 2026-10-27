<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

require_perm('reports.view');
$db = getDB();

$loc_filter = req_int('location_id');
$cat_filter = req_int('category_id');
$show       = req_str('show');

$where_parts = ['i.is_active=1'];
$params=[]; $types='';
if ($cat_filter) { $where_parts[]='i.category_id=?'; $params[]=&$cat_filter; $types.='i'; }
if ($loc_filter) { $where_parts[]='s.location_id=?';  $params[]=&$loc_filter; $types.='i'; }
$where='WHERE '.implode(' AND ',$where_parts);

$having = '';
if ($show==='low')  $having = 'HAVING total_stock > 0 AND total_stock <= MAX(i.min_stock)';
if ($show==='zero') $having = 'HAVING total_stock = 0';

// Print-friendly HTML export
if (req_str('format')==='print') {
$title='Laporan Stok Barang - '.date('d/m/Y');
$total_value = $db->query('SELECT COALESCE(SUM(s.quantity*i.buy_price),0) FROM stock s JOIN items i ON i.id=s.item_id')->fetch_row()[0];
$st=$db->prepare("SELECT i.code, i.name, c.name AS cat_name, u.abbreviation, i.min_stock, COALESCE(SUM(s.quantity),0) AS total_stock FROM items i JOIN categories c ON c.id=i.category_id JOIN units u ON u.id=i.unit_id LEFT JOIN stock s ON s.item_id=i.id $where GROUP BY i.id, i.code, i.name, i.min_stock, c.name, u.abbreviation $having ORDER BY i.code");
if($types)$st->bind_param($types,...$params);
$st->execute(); $rs=$st->get_result();
header('Content-Type: text/html; charset=UTF-8');
function report_cell($value) { return '<td>'.htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8').'</td>'; }
echo '<!doctype html><html><head><meta charset="utf-8"><title>'.htmlspecialchars($title,ENT_QUOTES,'UTF-8').'</title><style>body{font:14px Arial,sans-serif;margin:24px}table{border-collapse:collapse;width:100%}th,td{border:1px solid #888;padding:6px;text-align:left}th{background:#eee}@media print{.no-print{display:none}body{margin:0}}</style></head><body><button class="no-print" onclick="window.print()">Print</button><h1>'.htmlspecialchars($title,ENT_QUOTES,'UTF-8').'</h1><p>'.htmlspecialchars($subtitle??'',ENT_QUOTES,'UTF-8').'</p><table><thead><tr>';
foreach(['Kode','Nama','Kategori','Stok','Min','Status'] as $h) echo '<th>'.htmlspecialchars($h,ENT_QUOTES,'UTF-8').'</th>';
echo '</tr></thead><tbody>';
while($r=$rs->fetch_assoc()) { $sl=$r['total_stock']==0?'Habis':($r['total_stock']<=$r['min_stock']?'Menipis':'Aman'); $cells=[$r['code'],$r['name'],$r['cat_name'],$r['total_stock'],$r['min_stock'],$sl]; foreach($cells as $cell) echo report_cell($cell); echo '</tr>'; }
$st->close(); echo '</tbody></table></body></html>'; exit;

}

// CSV export
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="stok_'.date('Ymd').'.csv"');
echo "\xEF\xBB\xBF";
$out=fopen('php://output','w');
fputcsv($out, ['Kode','Nama Barang','Kategori','Satuan','Stok','Stok Min','Status','Harga Beli','Nilai Stok'], ',', '"', '\\');

$st=$db->prepare("SELECT i.code, i.name, c.name AS cat_name, u.abbreviation, i.min_stock, i.buy_price,
    COALESCE(SUM(s.quantity),0) AS total_stock, COALESCE(SUM(s.quantity)*i.buy_price,0) AS stock_value
    FROM items i JOIN categories c ON c.id=i.category_id JOIN units u ON u.id=i.unit_id
    LEFT JOIN stock s ON s.item_id=i.id $where
    GROUP BY i.id, i.code, i.name, i.min_stock, i.buy_price, c.name, u.abbreviation $having ORDER BY i.code");
if($types)$st->bind_param($types,...$params);
$st->execute(); $rs=$st->get_result();
while($r=$rs->fetch_assoc()) {
    $sl=$r['total_stock']==0?'Habis':($r['total_stock']<=$r['min_stock']?'Menipis':'Aman');
    fputcsv($out, [$r['code'],$r['name'],$r['cat_name'],$r['abbreviation'],
        $r['total_stock'],$r['min_stock'],$sl,$r['buy_price'],$r['stock_value']], ',', '"', '\\');
}
$st->close();
fclose($out);
exit;
