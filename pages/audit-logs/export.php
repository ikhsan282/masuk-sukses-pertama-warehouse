<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/export.php';

require_perm('users.view');
$db = getDB();
audit_logs_ensure_table($db);
grid_apply_timeout($db, 20.0);

$date_from  = req_date('date_from', date('Y-m-d', strtotime('-29 days')));
$date_to    = req_date('date_to', date('Y-m-d'));
if ($date_from > $date_to) {
    [$date_from, $date_to] = [$date_to, $date_from];
}
$action_grp = req_str('action_group');
$search     = grid_search_term('q');
$format     = strtolower(req_str('format', 'csv'));
$cf         = resolve_company_filter('company_id');

$comp_in = $cf['sql_in'] ?: '0';
$where   = ['DATE(al.created_at) BETWEEN ? AND ?', "(al.company_id IN ($comp_in) OR al.company_id IS NULL)"];
$params  = [$date_from, $date_to];
$types   = 'ss';

$group_map = [
    'auth'        => ['login_success', 'login_failed', 'login_locked', 'login_blocked', 'login_unverified', 'logout'],
    'master'      => ['item.create', 'item.update', 'item.delete', 'items.import', 'user.create', 'user.update', 'user.delete', 'user.verify', 'role.permissions'],
    'transaction' => ['stock_in.create', 'stock_in.delete', 'stock_out.create', 'stock_out.delete', 'transfer.create', 'transfer.delete', 'supplier_return.create'],
    'approval'    => ['po.approve', 'po.reject', 'po.receive', 'adjustment.create', 'adjustment.approve', 'stock_opname.create', 'stock_opname.finalize'],
    'export'      => ['export.pdf', 'export.excel', 'export.csv', 'export.print'],
];
if ($action_grp !== '' && isset($group_map[$action_grp])) {
    $acts = $group_map[$action_grp];
    $ph   = implode(',', array_fill(0, count($acts), '?'));
    $where[] = "al.action IN ($ph)";
    foreach ($acts as $a) {
        $params[] = $a;
        $types   .= 's';
    }
}

if ($search !== '') {
    $like = '%' . $search . '%';
    $where[]  = '(al.username LIKE ? OR al.action LIKE ? OR al.reference_no LIKE ? OR al.description LIKE ? OR al.ip_address LIKE ?)';
    array_push($params, $like, $like, $like, $like, $like);
    $types .= 'sssss';
}

$limit_map = [
    'print' => EXPORT_PRINT_MAX_ROWS,
    'pdf'   => EXPORT_MASTER_PDF_MAX_ROWS,
    'excel' => EXPORT_MASTER_XLSX_MAX_ROWS,
    'csv'   => EXPORT_CSV_MAX_ROWS,
];
$max_rows = $limit_map[$format] ?? EXPORT_CSV_MAX_ROWS;
$limit    = $max_rows + 1;

$wsql = implode(' AND ', $where);
$st = $db->prepare("SELECT al.created_at, COALESCE(c.code, 'GLOBAL') AS company_code, al.username, al.action,
    al.reference_no, al.description, al.ip_address
    FROM audit_logs al
    LEFT JOIN companies c ON c.id = al.company_id
    WHERE $wsql
    ORDER BY al.created_at DESC, al.id DESC
    LIMIT ?");
$types_q  = $types . 'i';
$params_q = array_merge($params, [$limit]);
$st->bind_param($types_q, ...$params_q);
$st->execute();
$rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

$truncated = count($rows) > $max_rows;
if ($truncated) array_pop($rows);

$subtitle = "Periode {$date_from} s/d {$date_to} | Entitas: {$cf['label']} | Total: " . count($rows) . ' aktivitas' . ($truncated ? " (maks. {$max_rows})" : '');
$audit_fmt = in_array($format, ['pdf', 'excel', 'print'], true) ? $format : 'csv';
audit_log('export.' . $audit_fmt, 'audit_logs', null, '', 'Export audit log format ' . strtoupper($audit_fmt) . ' (' . count($rows) . ' baris)');

if ($format === 'pdf' || $format === 'excel') {
    $columns = [
        ['label' => '#',          'type' => 'int',  'align' => 'C', 'pdf_width' => 24,  'xlsx_width' => 6],
        ['label' => 'Waktu',      'type' => 'text', 'align' => 'C', 'pdf_width' => 86,  'xlsx_width' => 20],
        ['label' => 'PT',         'type' => 'text', 'align' => 'C', 'pdf_width' => 46,  'xlsx_width' => 10],
        ['label' => 'Pengguna',   'type' => 'text', 'align' => 'L', 'pdf_width' => 76,  'xlsx_width' => 16],
        ['label' => 'Aksi',       'type' => 'text', 'align' => 'L', 'pdf_width' => 86,  'xlsx_width' => 18],
        ['label' => 'Referensi',  'type' => 'text', 'align' => 'L', 'pdf_width' => 82,  'xlsx_width' => 18],
        ['label' => 'Keterangan', 'type' => 'text', 'align' => 'L', 'pdf_width' => 260, 'xlsx_width' => 42],
        ['label' => 'IP Address', 'type' => 'text', 'align' => 'C', 'pdf_width' => 70,  'xlsx_width' => 16],
    ];
    $dataRows = [];
    foreach ($rows as $i => $r) {
        $dataRows[] = [
            $i + 1,
            date('d/m/Y H:i:s', strtotime($r['created_at'])),
            $r['company_code'],
            $r['username'] !== '' ? $r['username'] : 'system',
            $r['action'],
            $r['reference_no'] !== '' ? $r['reference_no'] : '-',
            $r['description'] !== '' ? $r['description'] : '-',
            $r['ip_address'] ?: '-',
        ];
    }
    if ($format === 'pdf') {
        export_pdf_send('audit_log_' . date('Ymd') . '.pdf', 'Audit Log Aktivitas Sistem', $subtitle, $columns, $dataRows, 'L');
    }
    export_xlsx_send('audit_log_' . date('Ymd') . '.xlsx', 'Audit Log Aktivitas Sistem', $subtitle, $columns, $dataRows, 'Audit Log');
}

if ($format === 'print') {
    ?>
    <!DOCTYPE html><html lang="id"><head><meta charset="UTF-8"><title>Cetak Audit Log</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>body{font-size:12px}@media print{.no-print{display:none}}</style></head><body class="p-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <div><h5 class="mb-0">Audit Log Aktivitas Sistem</h5><small class="text-muted"><?= e($subtitle) ?></small></div>
      <button onclick="window.print()" class="btn btn-primary btn-sm no-print">Cetak / PDF</button>
    </div>
    <table class="table table-bordered table-sm">
      <thead class="table-light"><tr><th>#</th><th>Waktu</th><th>PT</th><th>Pengguna</th><th>Aksi</th><th>Referensi</th><th>Keterangan</th><th>IP</th></tr></thead>
      <tbody><?php foreach ($rows as $i => $r): ?>
        <tr>
          <td><?= $i + 1 ?></td>
          <td class="text-nowrap"><?= date('d/m/Y H:i:s', strtotime($r['created_at'])) ?></td>
          <td><?= e($r['company_code']) ?></td>
          <td><?= e($r['username'] ?: 'system') ?></td>
          <td><?= e($r['action']) ?></td>
          <td><?= e($r['reference_no'] ?: '-') ?></td>
          <td><?= e($r['description'] ?: '-') ?></td>
          <td><?= e($r['ip_address'] ?: '-') ?></td>
        </tr>
      <?php endforeach; ?></tbody>
    </table></body></html>
    <?php
    exit;
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="audit_log_' . date('Ymd') . '.csv"');
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");
fputcsv($out, csv_safe_row(['Waktu', 'PT', 'Pengguna', 'Aksi', 'Referensi', 'Keterangan', 'IP Address']));
foreach ($rows as $r) {
    fputcsv($out, csv_safe_row([
        date('Y-m-d H:i:s', strtotime($r['created_at'])),
        $r['company_code'],
        $r['username'] ?: 'system',
        $r['action'],
        $r['reference_no'],
        $r['description'],
        $r['ip_address'],
    ]));
}
fclose($out);
