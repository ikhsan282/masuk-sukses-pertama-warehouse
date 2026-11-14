<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

require_perm('stock_opname.view');
$db = getDB();
grid_apply_timeout($db, 30.0);

$id     = req_int('id');
$format = strtolower(req_str('format', 'csv'));

$head = $db->prepare('SELECT o.*, u.name AS user_name, fu.name AS finalized_name,
    c.code AS company_code, c.name AS company_name
    FROM stock_opname o
    JOIN users u ON u.id = o.user_id
    LEFT JOIN users fu ON fu.id = o.finalized_by
    LEFT JOIN companies c ON c.id = o.company_id
    WHERE o.session_id = ?');
$head->bind_param('i', $id);
$head->execute();
$opname = $head->get_result()->fetch_assoc();
$head->close();
if (!$opname) {
    set_flash('error', 'Sesi stock opname tidak ditemukan.');
    redirect(APP_URL . '/pages/stock-opname/index.php');
}
require_company_access((int)$opname['company_id'], false);
release_session_lock();

$compLabel = '[' . ($opname['company_code'] ?? 'MSP') . '] ' . ($opname['company_name'] ?? COMPANY_NAME);
$refLabel  = 'OPNAME-' . $id;

if ($format === 'pdf' || $format === 'excel') {
    require_once __DIR__ . '/../../includes/export.php';
    $max_rows = $format === 'pdf' ? EXPORT_MASTER_PDF_MAX_ROWS : EXPORT_MASTER_XLSX_MAX_ROWS;
    $limit_q  = $max_rows + 1;

    $st = $db->prepare('SELECT oi.system_qty, oi.physical_qty, oi.variance, i.code, i.name, u.abbreviation,
        l.code AS location_code, l.name AS location_name
        FROM stock_opname_items oi
        JOIN items i ON i.id = oi.item_id
        JOIN units u ON u.id = i.unit_id
        JOIN locations l ON l.id = oi.location_id
        WHERE oi.session_id = ?
        ORDER BY l.code ASC, i.name ASC LIMIT ?');
    $st->bind_param('ii', $id, $limit_q);
    $st->execute();
    $raw = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();

    $truncated = count($raw) > $max_rows;
    if ($truncated) array_pop($raw);

    $rows = [];
    $net  = 0;
    foreach ($raw as $idx => $r) {
        $v = (int)$r['variance'];
        $net += $v;
        $rows[] = [
            $idx + 1,
            $r['code'],
            $r['name'],
            $r['abbreviation'],
            $r['location_code'] . ' - ' . $r['location_name'],
            (int)$r['system_qty'],
            (int)$r['physical_qty'],
            ($v > 0 ? '+' : '') . $v,
        ];
    }
    $columns = [
        ['label' => '#',          'type' => 'int',  'align' => 'C', 'pdf_width' => 26,  'xlsx_width' => 6],
        ['label' => 'Kode',       'type' => 'text', 'align' => 'C', 'pdf_width' => 76,  'xlsx_width' => 14],
        ['label' => 'Nama Barang','type' => 'text', 'align' => 'L', 'pdf_width' => 210, 'xlsx_width' => 34],
        ['label' => 'Satuan',     'type' => 'text', 'align' => 'C', 'pdf_width' => 48,  'xlsx_width' => 10],
        ['label' => 'Lokasi',     'type' => 'text', 'align' => 'L', 'pdf_width' => 150, 'xlsx_width' => 24],
        ['label' => 'Sistem',     'type' => 'int',  'align' => 'R', 'pdf_width' => 65,  'xlsx_width' => 12],
        ['label' => 'Fisik',      'type' => 'int',  'align' => 'R', 'pdf_width' => 65,  'xlsx_width' => 12],
        ['label' => 'Variance',   'type' => 'text', 'align' => 'R', 'pdf_width' => 72,  'xlsx_width' => 12],
    ];
    $netStr = ($net > 0 ? '+' : '') . $net;
    $subtitle = $compLabel . ' | Sesi #' . $id . ' (' . tgl($opname['opname_date']) . ') | Status: ' . strtoupper($opname['status'])
              . ' | Item: ' . count($rows) . ' | Selisih Bersih: ' . $netStr . ($truncated ? " (maks. {$max_rows})" : '');

    audit_log('export.' . $format, 'stock_opname', $id, $refLabel, 'Export variance stock opname #' . $id . ' format ' . strtoupper($format), (int)$opname['company_id']);
    if ($format === 'pdf') {
        export_pdf_send('variance_opname_' . $id . '_' . date('Ymd') . '.pdf', 'Laporan Variance Stock Opname #' . $id, $subtitle, $columns, $rows, 'L');
    }
    export_xlsx_send('variance_opname_' . $id . '_' . date('Ymd') . '.xlsx', 'Laporan Variance Stock Opname #' . $id, $subtitle, $columns, $rows, 'Variance Opname');
}

if ($format === 'print') {
    audit_log('export.print', 'stock_opname', $id, $refLabel, 'Cetak laporan variance stock opname #' . $id, (int)$opname['company_id']);
    $print_limit = EXPORT_PRINT_MAX_ROWS + 1;
    $st = $db->prepare('SELECT oi.system_qty, oi.physical_qty, oi.variance, i.code, i.name, u.abbreviation,
        l.code AS location_code, l.name AS location_name
        FROM stock_opname_items oi
        JOIN items i ON i.id = oi.item_id
        JOIN units u ON u.id = i.unit_id
        JOIN locations l ON l.id = oi.location_id
        WHERE oi.session_id = ?
        ORDER BY l.code ASC, i.name ASC LIMIT ?');
    $st->bind_param('ii', $id, $print_limit);
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
    $truncated = count($rows) > EXPORT_PRINT_MAX_ROWS;
    if ($truncated) array_pop($rows);

    header('Content-Type: text/html; charset=UTF-8');
    $title = 'Laporan Variance Stock Opname #' . $id;
    echo '<!doctype html><html lang="id"><head><meta charset="utf-8"><title>' . e($title) . '</title>'
       . '<style>body{font:13px Arial,sans-serif;margin:24px}table{border-collapse:collapse;width:100%;margin-top:12px}th,td{border:1px solid #888;padding:6px}th{background:#eee;text-align:left}.text-end{text-align:right}.warn{background:#fef3c7;border:1px solid #f59e0b;color:#92400e;padding:8px 12px;margin-bottom:12px;border-radius:4px}@media print{.no-print{display:none}body{margin:0}}</style>'
       . '</head><body><button class="no-print" onclick="window.print()">Cetak</button>'
       . '<h2>' . e($title) . '</h2>'
       . '<p>' . e($compLabel) . ' &middot; Tanggal: ' . tgl($opname['opname_date']) . ' &middot; Petugas: ' . e($opname['user_name']) . ' &middot; Status: <strong>' . strtoupper(e($opname['status'])) . '</strong></p>';
    if ($truncated) {
        echo '<p class="warn">Tampilan cetak dibatasi maksimal ' . number_format(EXPORT_PRINT_MAX_ROWS, 0, ',', '.') . ' baris. Gunakan Export Excel atau CSV untuk seluruh data.</p>';
    }
    echo '<table><thead><tr><th>Kode</th><th>Nama Barang</th><th>Satuan</th><th>Lokasi</th><th class="text-end">Sistem</th><th class="text-end">Fisik</th><th class="text-end">Variance</th></tr></thead><tbody>';
    foreach ($rows as $r) {
        $v = (int)$r['variance'];
        echo '<tr><td>' . e($r['code']) . '</td><td>' . e($r['name']) . '</td><td>' . e($r['abbreviation']) . '</td>'
           . '<td>' . e($r['location_code'] . ' - ' . $r['location_name']) . '</td>'
           . '<td class="text-end">' . (int)$r['system_qty'] . '</td>'
           . '<td class="text-end">' . (int)$r['physical_qty'] . '</td>'
           . '<td class="text-end">' . ($v > 0 ? '+' : '') . $v . '</td></tr>';
    }
    echo '</tbody></table></body></html>';
    exit;
}

audit_log('export.csv', 'stock_opname', $id, $refLabel, 'Export CSV variance stock opname #' . $id, (int)$opname['company_id']);
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="variance_opname_' . $id . '_' . date('Ymd') . '.csv"');
echo "\xEF\xBB\xBF";
$out = fopen('php://output', 'w');
fputcsv($out, ['Sesi', 'Tanggal', 'Kode Barang', 'Nama Barang', 'Satuan', 'Lokasi', 'Stok Sistem', 'Stok Fisik', 'Variance'], ',', '"', '\\');

$chunk_size = 5000;
$last_id    = 0;
$exported   = 0;
$hit_cap    = false;
while ($exported < EXPORT_CSV_MAX_ROWS) {
    $take = min($chunk_size, EXPORT_CSV_MAX_ROWS - $exported + 1);
    $st = $db->prepare('SELECT oi.id, oi.system_qty, oi.physical_qty, oi.variance, i.code, i.name, u.abbreviation,
        l.code AS location_code, l.name AS location_name
        FROM stock_opname_items oi
        JOIN items i ON i.id = oi.item_id
        JOIN units u ON u.id = i.unit_id
        JOIN locations l ON l.id = oi.location_id
        WHERE oi.session_id = ? AND oi.id > ?
        ORDER BY oi.id ASC LIMIT ?');
    $st->bind_param('iii', $id, $last_id, $take);
    $st->execute();
    $rs = $st->get_result();
    $batch = 0;
    while ($r = $rs->fetch_assoc()) {
        if ($exported >= EXPORT_CSV_MAX_ROWS) {
            $hit_cap = true;
            break;
        }
        $last_id = (int)$r['id'];
        fputcsv($out, csv_safe_row([
            '#' . $id,
            $opname['opname_date'],
            $r['code'],
            $r['name'],
            $r['abbreviation'],
            $r['location_code'] . ' - ' . $r['location_name'],
            (int)$r['system_qty'],
            (int)$r['physical_qty'],
            (int)$r['variance'],
        ]), ',', '"', '\\');
        $exported++;
        $batch++;
    }
    $rs->free();
    $st->close();
    fflush($out);
    if ($hit_cap || $batch < $take) break;
}
if ($hit_cap) {
    fputcsv($out, csv_safe_row(['CATATAN: Export dibatasi maksimal ' . EXPORT_CSV_MAX_ROWS . ' baris.']), ',', '"', '\\');
}
fclose($out);
exit;
