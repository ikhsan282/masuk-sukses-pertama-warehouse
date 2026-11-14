<?php
require_once __DIR__ . '/../config/database.php';

// CSRF
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . csrf_token() . '">';
}

function verify_csrf(): void {
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals(csrf_token(), $token)) {
        http_response_code(403);
        die('CSRF token tidak valid.');
    }
}

// Flash messages
function set_flash(string $type, string $message): void {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function get_flash(): ?array {
    if (!empty($_SESSION['flash'])) {
        $f = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $f;
    }
    return null;
}

function flash_html(): string {
    $f = get_flash();
    if (!$f) return '';
    $map = ['success' => 'success', 'error' => 'danger', 'warning' => 'warning', 'info' => 'info'];
    $cls = $map[$f['type']] ?? 'info';
    $msg = htmlspecialchars($f['message']);
    return "<div class=\"alert alert-{$cls} alert-dismissible fade show\" role=\"alert\">{$msg}
        <button type=\"button\" class=\"btn-close\" data-bs-dismiss=\"alert\"></button></div>";
}

// Redirect
function redirect(string $url): void {
    header('Location: ' . $url);
    exit;
}

// HTML escape
function e(?string $str): string {
    return $str !== null ? htmlspecialchars($str, ENT_QUOTES, 'UTF-8') : '';
}

// Format currency IDR
function idr(float $amount): string {
    return 'Rp ' . number_format($amount, 0, ',', '.');
}

// Format date to Indonesian
function tgl(string $date): string {
    if (!$date) return '-';
    $bulan = ['','Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des'];
    $d = date_create($date);
    return $d ? date_format($d, 'd') . ' ' . $bulan[(int)date_format($d, 'n')] . ' ' . date_format($d, 'Y') : '-';
}

// Grid & Export Safety Limits
defined('GRID_MAX_OFFSET')       || define('GRID_MAX_OFFSET', 50000);
defined('EXPORT_PRINT_MAX_ROWS') || define('EXPORT_PRINT_MAX_ROWS', 5000);
defined('EXPORT_CSV_MAX_ROWS')   || define('EXPORT_CSV_MAX_ROWS', 100000);

// Strict allowlist for per_page query param (prevents ?per_page=9999999 OOM/DoS)
function grid_per_page(array $allowed = [10, 20, 50, 100], ?int $default = null): int {
    $fallback = $default ?? PER_PAGE;
    if (!in_array($fallback, $allowed, true)) $fallback = $allowed[0];
    $val = isset($_GET['per_page']) ? (int)$_GET['per_page'] : $fallback;
    return in_array($val, $allowed, true) ? $val : $fallback;
}

// Bounded, sanitized, & trimmed search query
function grid_search_term(string $key = 'q', int $max_len = 100): string {
    $raw = isset($_REQUEST[$key]) ? trim((string)$_REQUEST[$key]) : '';
    if ($raw === '') return '';
    $clean = preg_replace('/[\x00-\x1F\x7F]/u', '', $raw);
    return $clean !== null ? mb_substr(trim($clean), 0, $max_len, 'UTF-8') : '';
}

// Escape SQL LIKE wildcards (% _ \) to block wildcard DoS (?q=%%%%)
function escape_like(string $term): string {
    return addcslashes($term, "\\%_");
}

// Strict YYYY-MM-DD validator for date filter params
function req_date(string $key, string $default = ''): string {
    $raw = isset($_REQUEST[$key]) ? trim((string)$_REQUEST[$key]) : '';
    if ($raw === '') return $default;
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
    if ($dt && $dt->format('Y-m-d') === $raw) return $raw;
    return $default;
}

// Apply per-session statement timeout guard on heavy grid queries (MariaDB / MySQL)
function grid_apply_timeout(mysqli $db, float $seconds = 10.0): void {
    static $handler_registered = false;
    $sec = max(1.0, min(60.0, $seconds));
    try {
        $info = $db->server_info ?? '';
        if (stripos($info, 'MariaDB') !== false) {
            $db->query('SET SESSION max_statement_time = ' . number_format($sec, 2, '.', ''));
        } else {
            $ms = (int)round($sec * 1000);
            $db->query('SET SESSION max_execution_time = ' . $ms);
        }
    } catch (Throwable $e) {
        // Ignore if database user lacks privilege or older server
    }
    if (!$handler_registered && PHP_SAPI !== 'cli') {
        $handler_registered = true;
        $prev = set_exception_handler(null);
        set_exception_handler(static function (Throwable $e) use ($prev): void {
            if ($e instanceof mysqli_sql_exception && in_array($e->getCode(), [1969, 3024], true)) {
                if (!headers_sent()) http_response_code(503);
                echo '<div style="font-family:system-ui,sans-serif;max-width:680px;margin:40px auto;padding:16px 20px;border:1px solid #f59e0b;background:#fef3c7;color:#92400e;border-radius:8px">'
                   . '<strong>Batas Waktu Kueri Tercapai:</strong> Data terlalu besar untuk filter ini. Silakan persempit kata kunci pencarian atau rentang tanggal. '
                   . '<a href="?" style="color:#92400e;font-weight:600;margin-left:8px">Reset Filter</a></div>';
                exit;
            }
            if ($prev) {
                $prev($e);
            } else {
                throw $e;
            }
        });
    }
}

// Build XSS-safe and sprintf-crash-safe pagination URL
function grid_page_url(array $query_params, int $page): string {
    $clean = [];
    foreach ($query_params as $k => $v) {
        if ($v === '' || $v === null) continue;
        $clean[(string)$k] = $v;
    }
    $clean['page'] = max(1, $page);
    return htmlspecialchars('?' . http_build_query($clean), ENT_QUOTES, 'UTF-8');
}

// Pagination helper with safe deep-offset window guard for millions of rows
function paginate(int $total, int $page, int $per_page = PER_PAGE, int $max_offset = GRID_MAX_OFFSET): array {
    $total = max(0, $total);
    $per_page = max(1, min(100, $per_page));
    $total_pages = max(1, (int)ceil($total / $per_page));
    $max_safe_page = ($max_offset > 0) ? max(1, (int)floor($max_offset / $per_page)) : $total_pages;
    $accessible_pages = min($total_pages, $max_safe_page);
    $page = max(1, min($page, $accessible_pages));
    $offset = ($page - 1) * $per_page;
    $from = $total === 0 ? 0 : ($offset + 1);
    $to = $total === 0 ? 0 : min($total, $offset + $per_page);
    return [
        'total'            => $total,
        'per_page'         => $per_page,
        'current'          => $page,
        'total_pages'      => $total_pages,
        'accessible_pages' => $accessible_pages,
        'offset'           => $offset,
        'from'             => $from,
        'to'               => $to,
        'offset_capped'    => $total_pages > $accessible_pages,
        'max_offset'       => $max_offset,
    ];
}

function pagination_html(array $p, string $url_pattern): string {
    $max_page = (int)($p['accessible_pages'] ?? $p['total_pages']);
    if ($max_page <= 1) return '';
    $build = static function (int $target) use ($url_pattern): string {
        $url = str_replace('%d', (string)$target, $url_pattern);
        return htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
    };
    $html = '<nav aria-label="Navigasi halaman"><ul class="pagination pagination-sm mb-0">';
    $prev = max(1, $p['current'] - 1);
    $next = min($max_page, $p['current'] + 1);
    $html .= '<li class="page-item ' . ($p['current'] == 1 ? 'disabled' : '') . '"><a class="page-link" href="' . $build($prev) . '" aria-label="Sebelumnya">&#8249;</a></li>';
    for ($i = 1; $i <= $max_page; $i++) {
        if ($max_page > 7 && abs($i - $p['current']) > 2 && $i != 1 && $i != $max_page) {
            if ($i == 2 || $i == $max_page - 1) { $html .= '<li class="page-item disabled"><span class="page-link">…</span></li>'; }
            continue;
        }
        $active = $i == $p['current'] ? 'active' : '';
        $html .= '<li class="page-item ' . $active . '"><a class="page-link" href="' . $build($i) . '">' . $i . '</a></li>';
    }
    $html .= '<li class="page-item ' . ($p['current'] == $max_page ? 'disabled' : '') . '"><a class="page-link" href="' . $build($next) . '" aria-label="Berikutnya">&#8250;</a></li>';
    $html .= '</ul></nav>';
    return $html;
}

// Standardized grid card-footer with formatted ranges, per-page selector, safe pagination & jump input
function grid_footer_html(array $p, string $entity_label = 'data', array $query_params = [], array $opts = []): string {
    $show_per_page = $opts['show_per_page'] ?? true;
    $allowed_sizes = $opts['per_page_options'] ?? [10, 20, 50, 100];
    $total = (int)$p['total'];
    $from  = (int)($p['from'] ?? 0);
    $to    = (int)($p['to'] ?? 0);
    $cur   = (int)$p['current'];
    $total_pages = (int)$p['total_pages'];
    $acc_pages   = (int)($p['accessible_pages'] ?? $total_pages);

    $clean_params = [];
    foreach ($query_params as $k => $v) {
        if ($v === '' || $v === null) continue;
        $clean_params[(string)$k] = $v;
    }

    $fmt_total = number_format($total, 0, ',', '.');
    $fmt_from  = number_format($from, 0, ',', '.');
    $fmt_to    = number_format($to, 0, ',', '.');
    $fmt_cur   = number_format($cur, 0, ',', '.');
    $fmt_pages = number_format($total_pages, 0, ',', '.');
    $label_esc = htmlspecialchars($entity_label, ENT_QUOTES, 'UTF-8');

    $summary = $total > 0
        ? "Menampilkan <strong>{$fmt_from}–{$fmt_to}</strong> dari <strong>{$fmt_total}</strong> {$label_esc}"
        : "Menampilkan <strong>0</strong> dari <strong>0</strong> {$label_esc}";

    $html  = '<div class="card-footer grid-footer bg-white d-flex flex-wrap align-items-center justify-content-between gap-2 py-2 no-print">';
    $html .= '<div class="d-flex flex-wrap align-items-center gap-2 small text-muted">';
    $html .= '<span>' . $summary . '</span>';
    if ($total > 0) {
        $html .= '<span class="d-none d-sm-inline text-muted">&bull; Hal. ' . $fmt_cur . ' / ' . $fmt_pages . '</span>';
    }
    if (!empty($p['offset_capped'])) {
        $fmt_cap = number_format((int)$p['max_offset'], 0, ',', '.');
        $html .= '<span class="badge bg-warning-subtle text-warning border border-warning-subtle" title="Batasi kueri dengan pencarian atau rentang tanggal agar tetap cepat">Batas aman offset ' . $fmt_cap . ' baris</span>';
    }
    $html .= '</div>';

    $html .= '<div class="d-flex flex-wrap align-items-center gap-2 ms-auto">';
    if ($show_per_page) {
        $pp_params = $clean_params;
        unset($pp_params['page'], $pp_params['per_page']);
        $html .= '<form method="GET" class="d-inline-flex align-items-center gap-1 m-0">';
        foreach ($pp_params as $hk => $hv) {
            $html .= '<input type="hidden" name="' . htmlspecialchars($hk, ENT_QUOTES, 'UTF-8') . '" value="' . htmlspecialchars((string)$hv, ENT_QUOTES, 'UTF-8') . '">';
        }
        $html .= '<input type="hidden" name="page" value="1">';
        $html .= '<select name="per_page" class="form-select form-select-sm w-auto" aria-label="Jumlah baris per halaman" onchange="this.form.submit()">';
        foreach ($allowed_sizes as $sz) {
            $sel = ((int)$p['per_page'] === (int)$sz) ? ' selected' : '';
            $html .= '<option value="' . (int)$sz . '"' . $sel . '>' . (int)$sz . ' / hal</option>';
        }
        $html .= '</select></form>';
    }

    if ($acc_pages > 1) {
        $url_params = $clean_params;
        if ($show_per_page) $url_params['per_page'] = (int)$p['per_page'];
        $html .= '<nav aria-label="Navigasi halaman"><ul class="pagination pagination-sm mb-0">';
        $html .= '<li class="page-item ' . ($cur <= 1 ? 'disabled' : '') . '"><a class="page-link" href="' . grid_page_url($url_params, 1) . '" title="Halaman pertama" aria-label="Pertama">&laquo;</a></li>';
        $html .= '<li class="page-item ' . ($cur <= 1 ? 'disabled' : '') . '"><a class="page-link" href="' . grid_page_url($url_params, max(1, $cur - 1)) . '" title="Sebelumnya" aria-label="Sebelumnya">&#8249;</a></li>';

        for ($i = 1; $i <= $acc_pages; $i++) {
            if ($acc_pages > 7 && abs($i - $cur) > 2 && $i != 1 && $i != $acc_pages) {
                if ($i == 2 || $i == $acc_pages - 1) {
                    $html .= '<li class="page-item disabled"><span class="page-link">…</span></li>';
                }
                continue;
            }
            $active = ($i === $cur) ? ' active' : '';
            $html .= '<li class="page-item' . $active . '"><a class="page-link" href="' . grid_page_url($url_params, $i) . '">' . number_format($i, 0, ',', '.') . '</a></li>';
        }

        $html .= '<li class="page-item ' . ($cur >= $acc_pages ? 'disabled' : '') . '"><a class="page-link" href="' . grid_page_url($url_params, min($acc_pages, $cur + 1)) . '" title="Berikutnya" aria-label="Berikutnya">&#8250;</a></li>';
        $html .= '<li class="page-item ' . ($cur >= $acc_pages ? 'disabled' : '') . '"><a class="page-link" href="' . grid_page_url($url_params, $acc_pages) . '" title="Halaman terakhir dalam batas aman" aria-label="Terakhir">&raquo;</a></li>';
        $html .= '</ul></nav>';

        if ($acc_pages > 5) {
            $jmp_params = $clean_params;
            if ($show_per_page) $jmp_params['per_page'] = (int)$p['per_page'];
            unset($jmp_params['page']);
            $html .= '<form method="GET" class="d-none d-md-inline-flex align-items-center gap-1 m-0">';
            foreach ($jmp_params as $hk => $hv) {
                $html .= '<input type="hidden" name="' . htmlspecialchars($hk, ENT_QUOTES, 'UTF-8') . '" value="' . htmlspecialchars((string)$hv, ENT_QUOTES, 'UTF-8') . '">';
            }
            $html .= '<input type="number" name="page" min="1" max="' . $acc_pages . '" value="' . $cur . '" class="form-control form-control-sm" style="width:68px" title="Lompat ke halaman (1–' . $acc_pages . ')" aria-label="Lompat halaman">';
            $html .= '<button type="submit" class="btn btn-outline-secondary btn-sm py-0 px-2" style="height:28px" title="Buka halaman">Go</button>';
            $html .= '</form>';
        }
    }
    $html .= '</div></div>';
    return $html;
}

// Standardized search box, date range & action controls for grid filter toolbars
function grid_filter_search_html(string $value, string $placeholder = 'Cari...', string $name = 'q'): string {
    $val = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    $ph  = htmlspecialchars($placeholder, ENT_QUOTES, 'UTF-8');
    $nm  = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    return '<div class="input-group input-group-sm grid-search-group">'
         . '<span class="input-group-text bg-transparent"><i class="bi bi-search text-muted"></i></span>'
         . '<input type="text" name="' . $nm . '" class="form-control" placeholder="' . $ph . '" value="' . $val . '" aria-label="' . $ph . '">'
         . '</div>';
}

function grid_filter_date_range_html(string $from_name, string $from_val, string $to_name, string $to_val, bool $required = false): string {
    $req = $required ? ' required' : '';
    return '<div class="grid-date-range">'
         . '<input type="date" name="' . htmlspecialchars($from_name, ENT_QUOTES, 'UTF-8') . '" class="form-control form-control-sm" value="' . htmlspecialchars($from_val, ENT_QUOTES, 'UTF-8') . '" title="Dari tanggal" aria-label="Dari tanggal"' . $req . '>'
         . '<span class="text-muted small">&ndash;</span>'
         . '<input type="date" name="' . htmlspecialchars($to_name, ENT_QUOTES, 'UTF-8') . '" class="form-control form-control-sm" value="' . htmlspecialchars($to_val, ENT_QUOTES, 'UTF-8') . '" title="Sampai tanggal" aria-label="Sampai tanggal"' . $req . '>'
         . '</div>';
}

function grid_filter_actions_html(int $per_page, bool $show_reset = false, string $submit_label = 'Filter', string $submit_icon = 'bi-funnel'): string {
    $html  = '<input type="hidden" name="per_page" value="' . (int)$per_page . '">';
    $html .= '<div class="grid-filter-actions">';
    $html .= '<button type="submit" class="btn btn-sm btn-primary text-nowrap"><i class="bi ' . htmlspecialchars($submit_icon, ENT_QUOTES, 'UTF-8') . ' me-1"></i>' . htmlspecialchars($submit_label, ENT_QUOTES, 'UTF-8') . '</button>';
    if ($show_reset) {
        $html .= '<a href="?per_page=' . (int)$per_page . '" class="btn btn-sm btn-outline-secondary text-nowrap" title="Reset filter"><i class="bi bi-arrow-counterclockwise me-1"></i>Reset</a>';
    }
    $html .= '</div>';
    return $html;
}

// Standardized Export dropdown (PDF & Excel) for Data Master headers
function master_export_dropdown_html(array $query_params = [], string $endpoint = 'export.php'): string {
    $clean = [];
    foreach ($query_params as $k => $v) {
        if ($v !== '' && $v !== null && $v !== 0 && $v !== '0') {
            $clean[$k] = $v;
        }
    }
    $pdf_url   = htmlspecialchars($endpoint . '?' . http_build_query($clean + ['format' => 'pdf']), ENT_QUOTES, 'UTF-8');
    $excel_url = htmlspecialchars($endpoint . '?' . http_build_query($clean + ['format' => 'excel']), ENT_QUOTES, 'UTF-8');
    return '<div class="btn-group">'
         . '<button type="button" class="btn btn-outline-secondary btn-sm dropdown-toggle text-nowrap" data-bs-toggle="dropdown" aria-expanded="false">'
         . '<i class="bi bi-download me-1"></i>Export'
         . '</button>'
         . '<ul class="dropdown-menu dropdown-menu-end shadow-sm">'
         . '<li><a class="dropdown-item" href="' . $pdf_url . '" target="_blank" rel="noopener"><i class="bi bi-file-earmark-pdf text-danger me-2"></i>Export PDF</a></li>'
         . '<li><a class="dropdown-item" href="' . $excel_url . '"><i class="bi bi-file-earmark-excel text-success me-2"></i>Export Excel</a></li>'
         . '</ul>'
         . '</div>';
}

// Guard CSV export cells against Excel/Calc formula injection (CWE-1236)
function csv_safe_cell($value): string {
    $str = (string)$value;
    if ($str === '') return '';
    if (preg_match('/^[=+\t\r@]/', $str)) {
        return "'" . $str;
    }
    if (str_starts_with($str, '-') && !is_numeric($str)) {
        return "'" . $str;
    }
    return $str;
}

function csv_safe_row(array $row): array {
    return array_map('csv_safe_cell', $row);
}

// Resolve company code by ID (or active company) for document numbering
function company_code(?int $company_id = null): string {
    static $cache = [];
    if ($company_id === null && function_exists('current_company')) {
        $cc = current_company();
        if (!empty($cc['code'])) {
            if (!empty($cc['id'])) $cache[(int)$cc['id']] = strtoupper($cc['code']);
            return strtoupper($cc['code']);
        }
    }
    $cid = $company_id ?: (function_exists('current_company_id') ? current_company_id() : 1);
    $cid = $cid ?: 1;
    if (isset($cache[$cid])) return $cache[$cid];
    if (function_exists('getDB')) {
        try {
            $db = getDB();
            $st = $db->prepare('SELECT code FROM companies WHERE id=? LIMIT 1');
            if ($st) {
                $st->bind_param('i', $cid);
                $st->execute();
                $st->bind_result($code);
                if ($st->fetch() && $code) {
                    $st->close();
                    return $cache[$cid] = strtoupper($code);
                }
                $st->close();
            }
        } catch (Throwable $e) {}
    }
    return $cache[$cid] = 'MSP';
}

// Auto-generate reference number per PT (e.g. SI-MSP-20261109XXXXX)
function generate_ref(string $prefix, ?int $company_id = null): string {
    $clean_prefix = rtrim($prefix, '-');
    $code = company_code($company_id);
    return $clean_prefix . '-' . $code . '-' . date('Ymd') . strtoupper(substr(uniqid(), -5));
}

// Send email (shared hosting compatible)
if (!function_exists('send_mail')) {
    function send_mail(string $to, string $subject, string $body): bool {
        $from      = MAIL_FROM;
        $from_name = MAIL_FROM_NAME;
        $headers   = "From: {$from_name} <{$from}>\r\n";
        $headers  .= "Reply-To: {$from}\r\n";
        $headers  .= "MIME-Version: 1.0\r\n";
        $headers  .= "Content-Type: text/html; charset=UTF-8\r\n";
        $headers  .= "X-Mailer: PHP/" . PHP_VERSION;
        return mail($to, $subject, $body, $headers);
    }
}

// Email templates
function email_template(string $title, string $body): string {
    return '<!DOCTYPE html><html><head><meta charset="UTF-8">
    <style>body{font-family:Arial,sans-serif;background:#f4f4f4;margin:0;padding:20px}
    .box{background:#fff;max-width:520px;margin:auto;padding:32px;border-radius:8px}
    .btn{display:inline-block;padding:12px 24px;background:#198754;color:#fff;text-decoration:none;border-radius:6px}
    h2{color:#1a1a2e}p{color:#444;line-height:1.6}</style></head>
    <body><div class="box"><h2>' . APP_NAME . '</h2><h3>' . $title . '</h3>' . $body .
    '<p style="margin-top:32px;font-size:12px;color:#999">&copy; ' . date('Y') . ' ' . APP_NAME . '</p>
    </div></body></html>';
}

// Safe int/string from request
function req_int(string $key, int $default = 0): int {
    return isset($_REQUEST[$key]) ? (int)$_REQUEST[$key] : $default;
}

function req_str(string $key, string $default = ''): string {
    return isset($_REQUEST[$key]) ? trim($_REQUEST[$key]) : $default;
}

// Get total stock for an item across all locations (filtered by company if specified or active)
function get_item_stock(int $item_id, ?int $company_id = null): int {
    $db = getDB();
    if ($company_id === null && function_exists('current_company_id')) {
        $company_id = current_company_id();
    }
    if ($company_id) {
        $st = $db->prepare('SELECT COALESCE(SUM(quantity),0) FROM stock WHERE item_id=? AND company_id=?');
        $st->bind_param('ii', $item_id, $company_id);
    } else {
        $st = $db->prepare('SELECT COALESCE(SUM(quantity),0) FROM stock WHERE item_id=?');
        $st->bind_param('i', $item_id);
    }
    $st->execute();
    $st->bind_result($qty);
    $st->fetch();
    $st->close();
    return (int)$qty;
}

// Update stock (upsert with company_id)
function update_stock(int $item_id, int $location_id, int $delta, ?int $company_id = null): bool {
    $db = getDB();
    if ($company_id === null && function_exists('current_company_id')) {
        $company_id = current_company_id();
    }
    $company_id = $company_id ?: 1;
    $st = $db->prepare('INSERT INTO stock (item_id, location_id, company_id, quantity)
        VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE quantity = quantity + ?');
    $st->bind_param('iiiii', $item_id, $location_id, $company_id, $delta, $delta);
    $result = $st->execute();
    $st->close();
    if ($result && isset($_SESSION['low_stock_cache'][$company_id])) {
        unset($_SESSION['low_stock_cache'][$company_id]);
    }
    return $result;
}

/**
 * Validate that every line can be fulfilled from one location for a specific company.
 * $lines: list of [$item_id, $qty, $price]. Quantities for the same item are
 * summed first, so two rows of the same item cannot slip past the check.
 * Throws RuntimeException with a readable "Tersedia: N" message on failure.
 */
function assert_location_stock_available(mysqli $db, int $location_id, array $lines, ?int $company_id = null): void {
    $wanted = [];
    foreach ($lines as $line) {
        $item_id = (int)$line[0];
        $qty     = (int)$line[1];
        if ($item_id > 0 && $qty > 0) $wanted[$item_id] = ($wanted[$item_id] ?? 0) + $qty;
    }
    if (!$wanted) return;

    if ($company_id === null && function_exists('current_company_id')) {
        $company_id = current_company_id();
    }
    $company_id = $company_id ?: 1;

    $st = $db->prepare('SELECT COALESCE(s.quantity,0) FROM items i
        LEFT JOIN stock s ON s.item_id = i.id AND s.location_id = ? AND s.company_id = ?
        WHERE i.id = ?');
    $loc = $db->prepare('SELECT COALESCE(code,name) FROM locations WHERE id=?');
    $loc->bind_param('i', $location_id); $loc->execute();
    $loc->bind_result($loc_name); $loc->fetch(); $loc->close();

    foreach ($wanted as $item_id => $qty) {
        $available = 0;
        $st->bind_param('iii', $location_id, $company_id, $item_id);
        $st->execute();
        $st->bind_result($qty_row);
        if ($st->fetch()) $available = (int)$qty_row;
        $st->free_result();
        if ($qty <= $available) continue;

        $in = $db->prepare('SELECT name FROM items WHERE id=?');
        $in->bind_param('i', $item_id); $in->execute();
        $in->bind_result($item_name); $in->fetch(); $in->close();

        $st->close();
        throw new RuntimeException(sprintf(
            'Stok %s tidak cukup di lokasi %s. Tersedia: %d, diminta: %d.',
            $item_name, $loc_name ?: '-', $available, $qty
        ));
    }
    $st->close();
}

// Log mutation with company_id
function log_mutation(int $item_id, int $location_id, string $type, int $qty,
    string $ref_no, string $ref_type, int $ref_id, int $user_id, string $notes = '', ?int $company_id = null): void {
    $db = getDB();
    if ($company_id === null && function_exists('current_company_id')) {
        $company_id = current_company_id();
    }
    $company_id = $company_id ?: 1;
    $st = $db->prepare('INSERT INTO mutations
        (item_id,location_id,company_id,type,quantity,reference_no,reference_type,reference_id,user_id,notes)
        VALUES (?,?,?,?,?,?,?,?,?,?)');
    $st->bind_param('iiisissiis', $item_id, $location_id, $company_id, $type, $qty,
        $ref_no, $ref_type, $ref_id, $user_id, $notes);
    $st->execute();
    $st->close();
}

// Upload item image (JPG/PNG/WebP, max 2MB)
function upload_item_image(array $file, ?string $old_image = null): ?string {
    if (empty($file['tmp_name']) || $file['error'] === UPLOAD_ERR_NO_FILE) return $old_image;
    if ($file['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('Upload foto gagal (kode ' . $file['error'] . ').');
    if ($file['size'] > 2 * 1024 * 1024) throw new RuntimeException('Ukuran foto maksimal 2MB.');

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']);
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($allowed[$mime])) throw new RuntimeException('Format foto harus JPG, PNG, atau WebP.');

    $dir = __DIR__ . '/../uploads/items';
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    // Delete old image if replaced
    if ($old_image) {
        $old_path = $dir . '/' . basename($old_image);
        if (is_file($old_path)) @unlink($old_path);
    }

    $filename = 'item_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $allowed[$mime];
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $filename)) {
        throw new RuntimeException('Gagal menyimpan file foto.');
    }
    return 'uploads/items/' . $filename;
}

// Delete item image file from disk
function delete_item_image(?string $image): void {
    if (!$image) return;
    $path = __DIR__ . '/../' . $image;
    if (is_file($path) && strpos(realpath($path), realpath(__DIR__ . '/../uploads/items')) === 0) {
        @unlink($path);
    }
}
