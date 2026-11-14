<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

require_perm('reports.view');
$db = getDB();
grid_apply_timeout($db, 30.0);

$date_from   = req_date('date_from', date('Y-m-01'));
$date_to     = req_date('date_to',   date('Y-m-d'));
$item_filter = req_int('item_id');
$type_filter = req_str('type');
if (!in_array($type_filter, ['in', 'out', 'transfer_in', 'transfer_out', 'adjustment'], true)) {
    $type_filter = '';
}
$cf          = resolve_company_filter('company_id');
$comp_in     = $cf['sql_in'];

$where_parts = ["m.company_id IN ($comp_in)", 'm.created_at >= ?', 'm.created_at <= ?'];
$df = $date_from . ' 00:00:00';
$dt = $date_to . ' 23:59:59';
$params = [$df, $dt];
$types = 'ss';
if ($item_filter) { $where_parts[] = 'm.item_id=?'; $params[] = $item_filter; $types .= 'i'; }
if ($type_filter) { $where_parts[] = 'm.type=?';    $params[] = $type_filter; $types .= 's'; }
$where = 'WHERE ' . implode(' AND ', $where_parts);

$type_map = ['in'=>'Masuk','out'=>'Keluar','transfer_in'=>'Transfer In','transfer_out'=>'Transfer Out','adjustment'=>'Penyesuaian'];
$format = strtolower(req_str('format', 'csv'));

if ($format === 'pdf' || $format === 'excel') {
    require_once __DIR__ . '/../../includes/export.php';
    $max_rows = $format === 'pdf' ? EXPORT_MASTER_PDF_MAX_ROWS : EXPORT_MASTER_XLSX_MAX_ROWS;
    $limit_q  = $max_rows + 1;
    $p_params = $params;
    $p_params[] = $limit_q;
    $st = $db->prepare("SELECT m.created_at, comp.code AS comp_code, i.code AS item_code, i.name AS item_name, m.type,
        l.name AS loc_name, m.quantity, u.abbreviation, m.reference_no, COALESCE(us.name, '-') AS user_name
        FROM mutations m JOIN items i ON i.id=m.item_id JOIN units u ON u.id=i.unit_id
        JOIN locations l ON l.id=m.location_id LEFT JOIN users us ON us.id=m.user_id
        LEFT JOIN companies comp ON comp.id=m.company_id
        $where ORDER BY m.id DESC LIMIT ?");
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
            date('d/m/Y H:i', strtotime($r['created_at'])),
            $r['comp_code'] ?? 'MSP',
            $r['item_code'],
            $r['item_name'],
            $type_map[$r['type']] ?? $r['type'],
            $r['loc_name'],
            (int)$r['quantity'],
            $r['abbreviation'],
            $r['reference_no'],
            $r['user_name'],
        ];
    }
    $columns = [
        ['label' => '#',           'type' => 'int',  'align' => 'C', 'pdf_width' => 24,  'xlsx_width' => 6],
        ['label' => 'Waktu',       'type' => 'text', 'align' => 'C', 'pdf_width' => 80,  'xlsx_width' => 18],
        ['label' => 'PT',          'type' => 'text', 'align' => 'C', 'pdf_width' => 40,  'xlsx_width' => 8],
        ['label' => 'Kode',        'type' => 'text', 'align' => 'C', 'pdf_width' => 68,  'xlsx_width' => 14],
        ['label' => 'Nama Barang', 'type' => 'text', 'align' => 'L', 'pdf_width' => 165, 'xlsx_width' => 28],
        ['label' => 'Tipe',        'type' => 'text', 'align' => 'C', 'pdf_width' => 72,  'xlsx_width' => 14],
        ['label' => 'Lokasi',      'type' => 'text', 'align' => 'L', 'pdf_width' => 108, 'xlsx_width' => 20],
        ['label' => 'Qty',         'type' => 'int',  'align' => 'R', 'pdf_width' => 46,  'xlsx_width' => 10],
        ['label' => 'Satuan',      'type' => 'text', 'align' => 'C', 'pdf_width' => 42,  'xlsx_width' => 8],
        ['label' => 'Referensi',   'type' => 'text', 'align' => 'L', 'pdf_width' => 78,  'xlsx_width' => 16],
        ['label' => 'Oleh',        'type' => 'text', 'align' => 'L', 'pdf_width' => 62,  'xlsx_width' => 15],
    ];
    $subtitle = 'Cakupan: ' . $cf['label'] . ' | Periode: ' . tgl($date_from) . ' - ' . tgl($date_to) . ' | Total: ' . count($rows) . ' baris' . ($truncated ? " (maks. {$max_rows})" : '');
    audit_log('export.' . $format, 'report_mutation', null, '', "Export laporan mutasi format " . strtoupper($format) . " (" . count($rows) . " baris)");
    if ($format === 'pdf') {
        export_pdf_send('laporan_mutasi_' . date('Ymd') . '.pdf', 'Laporan Mutasi Barang', $subtitle, $columns, $rows, 'L');
    }
    export_xlsx_send('laporan_mutasi_' . date('Ymd') . '.xlsx', 'Laporan Mutasi Barang', $subtitle, $columns, $rows, 'Mutasi Barang');
}

// Print-friendly HTML export (capped at EXPORT_PRINT_MAX_ROWS to prevent browser OOM)
if ($format==='print') {
    audit_log('export.print', 'report_mutation', null, '', 'Cetak tampilan laporan mutasi');
    $title='Laporan Mutasi Barang'; $subtitle='Cakupan: '.$cf['label'].' | Periode: '.tgl($date_from).' - '.tgl($date_to);
    $print_limit = EXPORT_PRINT_MAX_ROWS + 1;
    $p_params = $params; $p_params[] = $print_limit;
    $st=$db->prepare("SELECT m.created_at, comp.code AS comp_code, i.code AS item_code, i.name AS item_name, m.type, l.name AS loc_name, m.quantity, m.reference_no, u.abbreviation FROM mutations m JOIN items i ON i.id=m.item_id JOIN units u ON u.id=i.unit_id JOIN locations l ON l.id=m.location_id LEFT JOIN companies comp ON comp.id=m.company_id $where ORDER BY m.id DESC LIMIT ?");
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
        echo '<p class="warn">Tampilan cetak dibatasi maksimal '.number_format(EXPORT_PRINT_MAX_ROWS,0,',','.').' baris terbaru. Gunakan Export CSV atau persempit rentang tanggal untuk mengunduh seluruh data.</p>';
    }
    echo '<table><thead><tr>';
    foreach(['Waktu','PT','Kode','Barang','Tipe','Lokasi','Qty','Ref'] as $h) echo '<th>'.htmlspecialchars($h,ENT_QUOTES,'UTF-8').'</th>';
    echo '</tr></thead><tbody>';
    foreach($rows as $r) {
        echo '<tr>';
        $cells=[date('d/m H:i',strtotime($r['created_at'])),$r['comp_code']??'MSP',$r['item_code'],$r['item_name'],$type_map[$r['type']]??$r['type'],$r['loc_name'],$r['quantity'].' '.$r['abbreviation'],$r['reference_no']];
        foreach($cells as $cell) echo $report_cell($cell);
        echo '</tr>';
    }
    echo '</tbody></table></body></html>'; exit;
}

// Chunked keyset CSV export (capped at EXPORT_CSV_MAX_ROWS, formula-safe)
audit_log('export.csv', 'report_mutation', null, '', 'Export CSV laporan mutasi');
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="mutasi_'.date('Ymd').'.csv"');
echo "\xEF\xBB\xBF";
$out=fopen('php://output','w');
fputcsv($out, ['Waktu','PT','Kode Barang','Nama Barang','Tipe','Lokasi','Qty','Satuan','Referensi','Oleh'], ',', '"', '\\');

$chunk_size = 5000;
$last_id = PHP_INT_MAX;
$exported = 0;
$hit_cap = false;
while ($exported < EXPORT_CSV_MAX_ROWS) {
    $take = min($chunk_size, EXPORT_CSV_MAX_ROWS - $exported + 1);
    $sql = "SELECT m.id, m.created_at, comp.code AS comp_code, i.code AS item_code, i.name AS item_name, m.type,
        l.name AS loc_name, m.quantity, u.abbreviation, m.reference_no, us.name AS user_name
        FROM mutations m JOIN items i ON i.id=m.item_id JOIN units u ON u.id=i.unit_id
        JOIN locations l ON l.id=m.location_id JOIN users us ON us.id=m.user_id
        LEFT JOIN companies comp ON comp.id=m.company_id
        $where AND m.id < ? ORDER BY m.id DESC LIMIT ?";
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
        fputcsv($out, csv_safe_row([
            $r['created_at'],
            $r['comp_code'] ?? 'MSP',
            $r['item_code'],
            $r['item_name'],
            $type_map[$r['type']] ?? $r['type'],
            $r['loc_name'],
            $r['quantity'],
            $r['abbreviation'],
            $r['reference_no'],
            $r['user_name'],
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
