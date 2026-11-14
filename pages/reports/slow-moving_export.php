<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/slow_moving.php';

require_perm('reports.view');
release_session_lock();
$db = getDB();
grid_apply_timeout($db, 30.0);

$f         = slow_moving_filters();
$cf        = $f['cf'];
$days      = $f['days'];
$sql_parts = slow_moving_build_sql($f);
$from      = $sql_parts['from'];
$where     = $sql_parts['where'];
$params    = $sql_parts['params'];
$types     = $sql_parts['types'];

$format = strtolower(req_str('format', 'csv'));

if ($format === 'pdf' || $format === 'excel') {
    require_once __DIR__ . '/../../includes/export.php';
    $max_rows  = $format === 'pdf' ? EXPORT_MASTER_PDF_MAX_ROWS : EXPORT_MASTER_XLSX_MAX_ROWS;
    $limit_q   = $max_rows + 1;
    $q_params  = array_merge($params, [$limit_q]);
    $st = $db->prepare("SELECT i.code, i.name, c.name AS cat_name, u.abbreviation,
        st.total_stock, i.buy_price, (st.total_stock * i.buy_price) AS held_value,
        mo.last_out_at,
        DATEDIFF(CURDATE(), COALESCE(DATE(mo.last_out_at), DATE(i.created_at))) AS idle_days
        $from $where
        ORDER BY held_value DESC, idle_days DESC, i.code ASC
        LIMIT ?");
    $st->bind_param($types . 'i', ...$q_params);
    $st->execute();
    $raw = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
    $truncated = count($raw) > $max_rows;
    if ($truncated) array_pop($raw);

    $rows = [];
    foreach ($raw as $idx => $r) {
        $idle = max(0, (int)$r['idle_days']);
        [, $clsLabel] = slow_moving_classify($r['last_out_at'] ?? null, $idle);
        $lastOut = !empty($r['last_out_at']) ? date('d/m/Y', strtotime((string)$r['last_out_at'])) : 'Belum Pernah';
        $rows[] = [
            $idx + 1,
            $r['code'],
            $r['name'],
            $r['cat_name'],
            (int)$r['total_stock'] . ' ' . $r['abbreviation'],
            (float)$r['buy_price'],
            (float)$r['held_value'],
            $lastOut,
            $idle . ' hr',
            $clsLabel,
        ];
    }
    $columns = [
        ['label' => '#',              'type' => 'int',      'align' => 'C', 'pdf_width' => 24,  'xlsx_width' => 6],
        ['label' => 'Kode',           'type' => 'text',     'align' => 'C', 'pdf_width' => 68,  'xlsx_width' => 14],
        ['label' => 'Nama Barang',    'type' => 'text',     'align' => 'L', 'pdf_width' => 165, 'xlsx_width' => 30],
        ['label' => 'Kategori',       'type' => 'text',     'align' => 'L', 'pdf_width' => 95,  'xlsx_width' => 20],
        ['label' => 'Stok',           'type' => 'text',     'align' => 'R', 'pdf_width' => 54,  'xlsx_width' => 12],
        ['label' => 'H. Beli',        'type' => 'currency', 'align' => 'R', 'pdf_width' => 86,  'xlsx_width' => 16],
        ['label' => 'Nilai Tertahan', 'type' => 'currency', 'align' => 'R', 'pdf_width' => 96,  'xlsx_width' => 18],
        ['label' => 'Keluar Akhir',   'type' => 'text',     'align' => 'C', 'pdf_width' => 68,  'xlsx_width' => 14],
        ['label' => 'Umur',           'type' => 'text',     'align' => 'R', 'pdf_width' => 44,  'xlsx_width' => 10],
        ['label' => 'Klasifikasi',    'type' => 'text',     'align' => 'C', 'pdf_width' => 68,  'xlsx_width' => 14],
    ];
    $subtitle = 'Cakupan: ' . $cf['label'] . ' | Ambang: >= ' . $days . ' hari | Total: ' . count($rows) . ' barang' . ($truncated ? " (maks. {$max_rows})" : '');
    audit_log('export.' . $format, 'report_slow_moving', null, '', "Export laporan slow-moving & dead stock format " . strtoupper($format) . " (" . count($rows) . " item)");
    if ($format === 'pdf') {
        export_pdf_send('laporan_slow_moving_' . date('Ymd') . '.pdf', 'Laporan Slow-Moving & Dead Stock', $subtitle, $columns, $rows, 'L');
    }
    export_xlsx_send('laporan_slow_moving_' . date('Ymd') . '.xlsx', 'Laporan Slow-Moving & Dead Stock', $subtitle, $columns, $rows, 'Slow Moving');
}

if ($format === 'print') {
    audit_log('export.print', 'report_slow_moving', null, '', 'Cetak tampilan laporan slow-moving & dead stock');
    $title       = 'Laporan Slow-Moving & Dead Stock - ' . date('d/m/Y');
    $subtitle    = 'Cakupan: ' . $cf['label'] . ' | Ambang tidak keluar >= ' . $days . ' hari';
    $print_limit = EXPORT_PRINT_MAX_ROWS + 1;
    $q_params    = array_merge($params, [$print_limit]);
    $st = $db->prepare("SELECT i.code, i.name, c.name AS cat_name, u.abbreviation,
        st.total_stock, i.buy_price, (st.total_stock * i.buy_price) AS held_value,
        mo.last_out_at,
        DATEDIFF(CURDATE(), COALESCE(DATE(mo.last_out_at), DATE(i.created_at))) AS idle_days
        $from $where
        ORDER BY held_value DESC, idle_days DESC, i.code ASC
        LIMIT ?");
    $st->bind_param($types . 'i', ...$q_params);
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
    $truncated = count($rows) > EXPORT_PRINT_MAX_ROWS;
    if ($truncated) array_pop($rows);

    header('Content-Type: text/html; charset=UTF-8');
    $cell = static fn($v) => '<td>' . htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8') . '</td>';
    echo '<!doctype html><html><head><meta charset="utf-8"><title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title><style>body{font:14px Arial,sans-serif;margin:24px}table{border-collapse:collapse;width:100%}th,td{border:1px solid #888;padding:6px;text-align:left}th{background:#eee}.warn{background:#fef3c7;border:1px solid #f59e0b;color:#92400e;padding:8px 12px;margin-bottom:12px;border-radius:4px}@media print{.no-print{display:none}body{margin:0}}</style></head><body><button class="no-print" onclick="window.print()">Print</button><h1>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1><p>' . htmlspecialchars($subtitle, ENT_QUOTES, 'UTF-8') . '</p>';
    if ($truncated) {
        echo '<p class="warn">Tampilan cetak dibatasi maksimal ' . number_format(EXPORT_PRINT_MAX_ROWS, 0, ',', '.') . ' barang. Gunakan Export Excel/CSV atau filter kategori.</p>';
    }
    echo '<table><thead><tr>';
    foreach (['Kode', 'Nama Barang', 'Kategori', 'Stok', 'Nilai Tertahan', 'Keluar Terakhir', 'Umur (Hr)', 'Klasifikasi'] as $h) {
        echo '<th>' . htmlspecialchars($h, ENT_QUOTES, 'UTF-8') . '</th>';
    }
    echo '</tr></thead><tbody>';
    foreach ($rows as $r) {
        $idle = max(0, (int)$r['idle_days']);
        [, $clsLabel] = slow_moving_classify($r['last_out_at'] ?? null, $idle);
        $lastOut = !empty($r['last_out_at']) ? date('d/m/Y', strtotime((string)$r['last_out_at'])) : 'Belum Pernah';
        echo '<tr>';
        foreach ([$r['code'], $r['name'], $r['cat_name'], $r['total_stock'] . ' ' . $r['abbreviation'], idr((float)$r['held_value']), $lastOut, $idle, $clsLabel] as $v) {
            echo $cell($v);
        }
        echo '</tr>';
    }
    echo '</tbody></table></body></html>';
    exit;
}

// Chunked keyset CSV export (capped at EXPORT_CSV_MAX_ROWS, formula-safe)
audit_log('export.csv', 'report_slow_moving', null, '', 'Export CSV laporan slow-moving & dead stock');
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="slow_moving_' . date('Ymd') . '.csv"');
echo "\xEF\xBB\xBF";
$out = fopen('php://output', 'w');
fputcsv($out, ['Kode', 'Nama Barang', 'Kategori', 'Satuan', 'Stok', 'Harga Beli', 'Nilai Tertahan', 'Keluar Terakhir', 'Umur Mengendap (Hari)', 'Klasifikasi'], ',', '"', '\\');

$chunk_size = 5000;
$last_id    = 0;
$exported   = 0;
$hit_cap    = false;

while ($exported < EXPORT_CSV_MAX_ROWS) {
    $take     = min($chunk_size, EXPORT_CSV_MAX_ROWS - $exported + 1);
    $q_params = array_merge($params, [$last_id, $take]);
    $st = $db->prepare("SELECT i.id, i.code, i.name, c.name AS cat_name, u.abbreviation,
        st.total_stock, i.buy_price, (st.total_stock * i.buy_price) AS held_value,
        mo.last_out_at,
        DATEDIFF(CURDATE(), COALESCE(DATE(mo.last_out_at), DATE(i.created_at))) AS idle_days
        $from $where AND i.id > ?
        ORDER BY i.id ASC
        LIMIT ?");
    $st->bind_param($types . 'ii', ...$q_params);
    $st->execute();
    $rs = $st->get_result();
    $count_batch = 0;
    while ($r = $rs->fetch_assoc()) {
        if ($exported >= EXPORT_CSV_MAX_ROWS) {
            $hit_cap = true;
            break;
        }
        $last_id = (int)$r['id'];
        $idle    = max(0, (int)$r['idle_days']);
        [, $clsLabel] = slow_moving_classify($r['last_out_at'] ?? null, $idle);
        $lastOut = !empty($r['last_out_at']) ? date('Y-m-d', strtotime((string)$r['last_out_at'])) : 'Belum Pernah';
        fputcsv($out, csv_safe_row([
            $r['code'], $r['name'], $r['cat_name'], $r['abbreviation'],
            (int)$r['total_stock'], $r['buy_price'], $r['held_value'],
            $lastOut, $idle, $clsLabel,
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
    fputcsv($out, csv_safe_row(['CATATAN: Export dibatasi maksimal ' . EXPORT_CSV_MAX_ROWS . ' barang. Filter kategori/ambang hari untuk mengunduh data lainnya.']), ',', '"', '\\');
}
fclose($out);
exit;
