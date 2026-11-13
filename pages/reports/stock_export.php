<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

require_perm('reports.view');
$db = getDB();
grid_apply_timeout($db, 30.0);

$loc_filter = req_int('location_id');
$cat_filter = req_int('category_id');
$show       = req_str('show');
if (!in_array($show, ['low', 'zero'], true)) $show = '';
$cf         = resolve_company_filter('company_id');
$comp_in    = $cf['sql_in'];

$where_parts = ['i.is_active=1'];
$params=[]; $types='';
if ($cat_filter) { $where_parts[]='i.category_id=?'; $params[]=$cat_filter; $types.='i'; }
if ($loc_filter) {
    $where_parts[] = "EXISTS (SELECT 1 FROM stock sx WHERE sx.item_id=i.id AND sx.company_id IN ($comp_in) AND sx.location_id=?)";
    $params[] = $loc_filter; $types .= 'i';
    $stock_sub = "(SELECT COALESCE(SUM(s.quantity),0) FROM stock s WHERE s.item_id=i.id AND s.company_id IN ($comp_in) AND s.location_id=" . (int)$loc_filter . ")";
} else {
    $stock_sub = "(SELECT COALESCE(SUM(s.quantity),0) FROM stock s WHERE s.item_id=i.id AND s.company_id IN ($comp_in))";
}
if ($show === 'low')  $where_parts[] = "$stock_sub > 0 AND $stock_sub <= i.min_stock";
if ($show === 'zero') $where_parts[] = "$stock_sub = 0";
$where = 'WHERE ' . implode(' AND ', $where_parts);

// Print-friendly HTML export (capped at EXPORT_PRINT_MAX_ROWS)
if (req_str('format')==='print') {
    $title='Laporan Stok Barang - '.date('d/m/Y');
    $subtitle='Cakupan: '.$cf['label'];
    $print_limit = EXPORT_PRINT_MAX_ROWS + 1;
    $p_params = $params; $p_params[] = $print_limit;
    $st=$db->prepare("SELECT i.code, i.name, c.name AS cat_name, u.abbreviation, i.min_stock, $stock_sub AS total_stock FROM items i JOIN categories c ON c.id=i.category_id JOIN units u ON u.id=i.unit_id $where ORDER BY i.id ASC LIMIT ?");
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
        echo '<p class="warn">Tampilan cetak dibatasi maksimal '.number_format(EXPORT_PRINT_MAX_ROWS,0,',','.').' barang. Gunakan Export CSV atau filter kategori/lokasi.</p>';
    }
    echo '<table><thead><tr>';
    foreach(['Kode','Nama','Kategori','Stok','Min','Status'] as $h) echo '<th>'.htmlspecialchars($h,ENT_QUOTES,'UTF-8').'</th>';
    echo '</tr></thead><tbody>';
    foreach($rows as $r) {
        echo '<tr>';
        $sl=$r['total_stock']==0?'Habis':($r['total_stock']<=$r['min_stock']?'Menipis':'Aman');
        $cells=[$r['code'],$r['name'],$r['cat_name'],$r['total_stock'],$r['min_stock'],$sl];
        foreach($cells as $cell) echo $report_cell($cell);
        echo '</tr>';
    }
    echo '</tbody></table></body></html>'; exit;
}

// Chunked keyset CSV export (capped at EXPORT_CSV_MAX_ROWS, formula-safe)
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="stok_'.date('Ymd').'.csv"');
echo "\xEF\xBB\xBF";
$out=fopen('php://output','w');
fputcsv($out, ['Kode','Nama Barang','Kategori','Satuan','Stok','Stok Min','Status','Harga Beli','Nilai Stok'], ',', '"', '\\');

$chunk_size = 5000;
$last_id = 0;
$exported = 0;
$hit_cap = false;
while ($exported < EXPORT_CSV_MAX_ROWS) {
    $take = min($chunk_size, EXPORT_CSV_MAX_ROWS - $exported + 1);
    $sql = "SELECT i.id, i.code, i.name, c.name AS cat_name, u.abbreviation, i.min_stock, i.buy_price,
        $stock_sub AS total_stock
        FROM items i JOIN categories c ON c.id=i.category_id JOIN units u ON u.id=i.unit_id
        $where AND i.id > ? ORDER BY i.id ASC LIMIT ?";
    $c_params = $params;
    $c_params[] = $last_id;
    $c_params[] = $take;
    $st = $db->prepare($sql);
    $st->bind_param($types . 'ii', ...$c_params);
    $st->execute();
    $rs = $st->get_result();
    $count_batch = 0;
    while ($r = $rs->fetch_assoc()) {
        if ($exported >= EXPORT_CSV_MAX_ROWS) {
            $hit_cap = true;
            break;
        }
        $last_id = (int)$r['id'];
        $ts = (float)$r['total_stock'];
        $sl = $ts == 0 ? 'Habis' : ($ts <= $r['min_stock'] ? 'Menipis' : 'Aman');
        $sv = $ts * (float)$r['buy_price'];
        fputcsv($out, csv_safe_row([
            $r['code'], $r['name'], $r['cat_name'], $r['abbreviation'],
            $r['total_stock'], $r['min_stock'], $sl, $r['buy_price'], $sv
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
    fputcsv($out, csv_safe_row(['CATATAN: Export dibatasi maksimal ' . EXPORT_CSV_MAX_ROWS . ' barang. Filter kategori/lokasi untuk mengunduh data lainnya.']), ',', '"', '\\');
}
fclose($out);
exit;
