<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

require_perm('users.view');
$db = getDB();
grid_apply_timeout($db, 30.0);

$search = grid_search_term('q');
$format = strtolower(req_str('format', 'csv'));

$where  = '';
$params = [];
$types  = '';
if ($search !== '') {
    $where  = 'WHERE u.name LIKE ? OR u.username LIKE ? OR u.email LIKE ?';
    $like   = '%' . escape_like($search) . '%';
    $params = [$like, $like, $like];
    $types  = 'sss';
}

$columns = [
    ['label' => '#',          'type' => 'int',  'align' => 'C', 'pdf_width' => 26,  'xlsx_width' => 6],
    ['label' => 'Nama',       'type' => 'text', 'align' => 'L', 'pdf_width' => 145, 'xlsx_width' => 24],
    ['label' => 'Username',   'type' => 'text', 'align' => 'L', 'pdf_width' => 95,  'xlsx_width' => 16],
    ['label' => 'Email',      'type' => 'text', 'align' => 'L', 'pdf_width' => 155, 'xlsx_width' => 28],
    ['label' => 'Peran',      'type' => 'text', 'align' => 'L', 'pdf_width' => 95,  'xlsx_width' => 18],
    ['label' => 'Akses PT',   'type' => 'text', 'align' => 'L', 'pdf_width' => 115, 'xlsx_width' => 22],
    ['label' => 'Verifikasi', 'type' => 'text', 'align' => 'C', 'pdf_width' => 75,  'xlsx_width' => 14],
    ['label' => 'Status',     'type' => 'text', 'align' => 'C', 'pdf_width' => 62,  'xlsx_width' => 12],
];

$base_sql = "SELECT u.id, u.name, u.username, u.email, u.is_active, u.email_verified_at,
    r.name AS role_name,
    COALESCE(GROUP_CONCAT(c.code ORDER BY c.is_holding DESC, c.code ASC SEPARATOR ', '), '-') AS company_codes
    FROM users u
    JOIN roles r ON r.id = u.role_id
    LEFT JOIN user_companies uc ON uc.user_id = u.id
    LEFT JOIN companies c ON c.id = uc.company_id
    $where
    GROUP BY u.id
    ORDER BY u.id DESC LIMIT ?";

$format_row = static function (array $r, int $idx): array {
    return [
        $idx + 1,
        $r['name'],
        $r['username'],
        $r['email'],
        $r['role_name'],
        $r['company_codes'] ?: '-',
        !empty($r['email_verified_at']) ? 'Terverifikasi' : 'Belum',
        !empty($r['is_active']) ? 'Aktif' : 'Nonaktif',
    ];
};

if ($format === 'pdf' || $format === 'excel') {
    require_once __DIR__ . '/../../includes/export.php';
    $max_rows   = $format === 'pdf' ? EXPORT_MASTER_PDF_MAX_ROWS : EXPORT_MASTER_XLSX_MAX_ROWS;
    $limit_q    = $max_rows + 1;
    $q_params   = $params;
    $q_params[] = $limit_q;

    $st = $db->prepare($base_sql);
    $st->bind_param($types . 'i', ...$q_params);
    $st->execute();
    $raw = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();

    $truncated = count($raw) > $max_rows;
    if ($truncated) array_pop($raw);

    $rows = [];
    foreach ($raw as $idx => $r) {
        $rows[] = $format_row($r, $idx);
    }

    $subtitle = ($search !== '' ? 'Filter: "' . $search . '" | ' : '') . 'Total: ' . count($rows) . ' pengguna' . ($truncated ? " (maks. {$max_rows})" : '');
    audit_log('export.' . $format, 'users', null, '', 'Export daftar pengguna format ' . strtoupper($format) . ' (' . count($rows) . ' baris)');
    if ($format === 'pdf') {
        export_pdf_send('daftar_pengguna_' . date('Ymd') . '.pdf', 'Daftar Pengguna Sistem', $subtitle, $columns, $rows, 'L');
    }
    export_xlsx_send('daftar_pengguna_' . date('Ymd') . '.xlsx', 'Daftar Pengguna Sistem', $subtitle, $columns, $rows, 'Pengguna');
}

if ($format === 'print') {
    audit_log('export.print', 'users', null, '', 'Cetak tampilan daftar pengguna');
    $print_limit = EXPORT_PRINT_MAX_ROWS + 1;
    $q_params    = $params;
    $q_params[]  = $print_limit;

    $st = $db->prepare($base_sql);
    $st->bind_param($types . 'i', ...$q_params);
    $st->execute();
    $raw = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();

    $truncated = count($raw) > EXPORT_PRINT_MAX_ROWS;
    if ($truncated) array_pop($raw);

    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><html lang="id"><head><meta charset="utf-8"><title>Daftar Pengguna Sistem</title>'
       . '<style>body{font:13px Arial,sans-serif;margin:24px}table{border-collapse:collapse;width:100%;margin-top:12px}th,td{border:1px solid #888;padding:6px}th{background:#eee;text-align:left}.warn{background:#fef3c7;border:1px solid #f59e0b;color:#92400e;padding:8px 12px;margin-bottom:12px;border-radius:4px}@media print{.no-print{display:none}body{margin:0}}</style>'
       . '</head><body><button class="no-print" onclick="window.print()">Cetak</button>'
       . '<h2>Daftar Pengguna Sistem</h2><p>Tanggal Cetak: ' . date('d/m/Y H:i') . '</p>';
    if ($truncated) {
        echo '<p class="warn">Tampilan cetak dibatasi maksimal ' . number_format(EXPORT_PRINT_MAX_ROWS, 0, ',', '.') . ' baris.</p>';
    }
    echo '<table><thead><tr>';
    foreach ($columns as $col) echo '<th>' . e($col['label']) . '</th>';
    echo '</tr></thead><tbody>';
    foreach ($raw as $idx => $r) {
        echo '<tr>';
        foreach ($format_row($r, $idx) as $val) echo '<td>' . e((string)$val) . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table></body></html>';
    exit;
}

audit_log('export.csv', 'users', null, '', 'Export CSV daftar pengguna');
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="daftar_pengguna_' . date('Ymd') . '.csv"');
echo "\xEF\xBB\xBF";
$out = fopen('php://output', 'w');
fputcsv($out, array_map(fn($c) => $c['label'], $columns), ',', '"', '\\');

$limit_q    = EXPORT_CSV_MAX_ROWS + 1;
$q_params   = $params;
$q_params[] = $limit_q;
$st = $db->prepare($base_sql);
$st->bind_param($types . 'i', ...$q_params);
$st->execute();
$rs = $st->get_result();
$exported = 0;
$hit_cap  = false;
while ($r = $rs->fetch_assoc()) {
    if ($exported >= EXPORT_CSV_MAX_ROWS) {
        $hit_cap = true;
        break;
    }
    fputcsv($out, csv_safe_row($format_row($r, $exported)), ',', '"', '\\');
    $exported++;
}
$rs->free();
$st->close();
if ($hit_cap) {
    fputcsv($out, csv_safe_row(['CATATAN: Export dibatasi maksimal ' . EXPORT_CSV_MAX_ROWS . ' baris.']), ',', '"', '\\');
}
fclose($out);
exit;
