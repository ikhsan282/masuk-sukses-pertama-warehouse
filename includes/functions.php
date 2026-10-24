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

// Pagination helper
function paginate(int $total, int $page, int $per_page = PER_PAGE): array {
    $total_pages = max(1, (int)ceil($total / $per_page));
    $page = max(1, min($page, $total_pages));
    return [
        'total'       => $total,
        'per_page'    => $per_page,
        'current'     => $page,
        'total_pages' => $total_pages,
        'offset'      => ($page - 1) * $per_page,
    ];
}

function pagination_html(array $p, string $url_pattern): string {
    if ($p['total_pages'] <= 1) return '';
    $html = '<nav><ul class="pagination pagination-sm mb-0">';
    $prev = $p['current'] - 1;
    $next = $p['current'] + 1;
    $html .= '<li class="page-item ' . ($p['current'] == 1 ? 'disabled' : '') . '">
        <a class="page-link" href="' . sprintf($url_pattern, $prev) . '">&#8249;</a></li>';
    for ($i = 1; $i <= $p['total_pages']; $i++) {
        if ($p['total_pages'] > 7 && abs($i - $p['current']) > 2 && $i != 1 && $i != $p['total_pages']) {
            if ($i == 2 || $i == $p['total_pages'] - 1) { $html .= '<li class="page-item disabled"><span class="page-link">…</span></li>'; }
            continue;
        }
        $active = $i == $p['current'] ? 'active' : '';
        $html .= '<li class="page-item ' . $active . '"><a class="page-link" href="' . sprintf($url_pattern, $i) . '">' . $i . '</a></li>';
    }
    $html .= '<li class="page-item ' . ($p['current'] == $p['total_pages'] ? 'disabled' : '') . '">
        <a class="page-link" href="' . sprintf($url_pattern, $next) . '">&#8250;</a></li>';
    $html .= '</ul></nav>';
    return $html;
}

// Auto-generate reference number
function generate_ref(string $prefix): string {
    return $prefix . date('Ymd') . strtoupper(substr(uniqid(), -5));
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

// Get total stock for an item across all locations
function get_item_stock(int $item_id): int {
    $db = getDB();
    $st = $db->prepare('SELECT COALESCE(SUM(quantity),0) FROM stock WHERE item_id=?');
    $st->bind_param('i', $item_id);
    $st->execute();
    $st->bind_result($qty);
    $st->fetch();
    $st->close();
    return (int)$qty;
}

// Update stock (upsert)
function update_stock(int $item_id, int $location_id, int $delta): bool {
    $db = getDB();
    $st = $db->prepare('INSERT INTO stock (item_id, location_id, quantity)
        VALUES (?,?,?) ON DUPLICATE KEY UPDATE quantity = quantity + ?');
    $st->bind_param('iiii', $item_id, $location_id, $delta, $delta);
    $result = $st->execute();
    $st->close();
    return $result;
}

/**
 * Validate that every line can be fulfilled from one location.
 * $lines: list of [$item_id, $qty, $price]. Quantities for the same item are
 * summed first, so two rows of the same item cannot slip past the check.
 * Throws RuntimeException with a readable "Tersedia: N" message on failure.
 */
function assert_location_stock_available(mysqli $db, int $location_id, array $lines): void {
    $wanted = [];
    foreach ($lines as $line) {
        $item_id = (int)$line[0];
        $qty     = (int)$line[1];
        if ($item_id > 0 && $qty > 0) $wanted[$item_id] = ($wanted[$item_id] ?? 0) + $qty;
    }
    if (!$wanted) return;

    $st = $db->prepare('SELECT COALESCE(s.quantity,0) FROM items i
        LEFT JOIN stock s ON s.item_id = i.id AND s.location_id = ?
        WHERE i.id = ?');
    $loc = $db->prepare('SELECT COALESCE(code,name) FROM locations WHERE id=?');
    $loc->bind_param('i', $location_id); $loc->execute();
    $loc->bind_result($loc_name); $loc->fetch(); $loc->close();

    foreach ($wanted as $item_id => $qty) {
        $available = 0;
        $st->bind_param('ii', $location_id, $item_id);
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

// Log mutation
function log_mutation(int $item_id, int $location_id, string $type, int $qty,
    string $ref_no, string $ref_type, int $ref_id, int $user_id, string $notes = ''): void {
    $db = getDB();
    $st = $db->prepare('INSERT INTO mutations
        (item_id,location_id,type,quantity,reference_no,reference_type,reference_id,user_id,notes)
        VALUES (?,?,?,?,?,?,?,?,?)');
    $st->bind_param('iisissiis', $item_id, $location_id, $type, $qty,
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
