<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/functions.php';

// ── Login ──────────────────────────────────────────────────────────────────
function auth_login(string $username, string $password): bool {
    $db = getDB();
    $st = $db->prepare('SELECT id, password, is_active, email_verified_at FROM users WHERE username=? OR email=? LIMIT 1');
    $st->bind_param('ss', $username, $username);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();

    if (!$row || !password_verify($password, $row['password'])) return false;
    if (!$row['is_active']) { set_flash('error', 'Akun Anda dinonaktifkan.'); return false; }
    if (!$row['email_verified_at']) { set_flash('error', 'Email belum diverifikasi. Cek inbox Anda.'); return false; }

    _load_session($row['id']);
    session_regenerate_id(true);
    return true;
}

function _load_session(int $user_id): void {
    $db = getDB();
    $st = $db->prepare('SELECT u.id, u.name, u.username, u.email, u.role_id, r.name AS role_name
        FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=? LIMIT 1');
    $st->bind_param('i', $user_id);
    $st->execute();
    $user = $st->get_result()->fetch_assoc();
    $st->close();

    // Load permissions
    $st2 = $db->prepare('SELECT p.name FROM permissions p
        JOIN role_permissions rp ON rp.permission_id=p.id WHERE rp.role_id=?');
    $st2->bind_param('i', $user['role_id']);
    $st2->execute();
    $res = $st2->get_result();
    $perms = [];
    while ($r = $res->fetch_assoc()) $perms[] = $r['name'];
    $st2->close();

    // Load user companies
    $st3 = $db->prepare('SELECT c.id, c.code, c.name, c.address, c.phone, c.email, c.npwp, c.logo, c.is_holding, uc.is_default
        FROM companies c
        JOIN user_companies uc ON uc.company_id = c.id
        WHERE uc.user_id = ? AND c.is_active = 1
        ORDER BY c.is_holding DESC, c.name ASC');
    $st3->bind_param('i', $user_id);
    $st3->execute();
    $comp_res = $st3->get_result();
    $companies = [];
    $default_comp = null;
    while ($c = $comp_res->fetch_assoc()) {
        $companies[] = $c;
        if ($c['is_default'] || $default_comp === null) {
            $default_comp = $c;
        }
    }
    $st3->close();

    // Fallback if user has no explicit company mapping: assign holding company (id=1)
    if (empty($companies)) {
        $fallback = $db->query('SELECT id, code, name, address, phone, email, npwp, logo, is_holding FROM companies WHERE id=1 LIMIT 1')->fetch_assoc();
        if ($fallback) {
            $companies[] = $fallback;
            $default_comp = $fallback;
        }
    }

    $_SESSION['user']           = $user;
    $_SESSION['perms']          = $perms;
    $_SESSION['user_companies'] = $companies;
    $_SESSION['active_company'] = $default_comp;
}

// ── Multi-Company helpers ──────────────────────────────────────────────────
function current_company(): ?array {
    return $_SESSION['active_company'] ?? null;
}

function current_company_id(): int {
    return (int)($_SESSION['active_company']['id'] ?? 1);
}

function user_companies(): array {
    return $_SESSION['user_companies'] ?? [];
}

function is_holding_user(): bool {
    return !empty($_SESSION['active_company']['is_holding']);
}

function switch_company(int $company_id): bool {
    $companies = user_companies();
    foreach ($companies as $comp) {
        if ((int)$comp['id'] === $company_id) {
            $_SESSION['active_company'] = $comp;
            return true;
        }
    }
    return false;
}

/**
 * Resolve company filter for Reports & Stock views.
 * Supports 'all' (Consolidated view across all user's assigned PTs) or a specific company_id.
 */
function resolve_company_filter(string $param = 'company_id'): array {
    $raw = isset($_REQUEST[$param]) ? trim((string)$_REQUEST[$param]) : (string)current_company_id();
    $allowed_companies = user_companies();
    $allowed_ids = array_values(array_filter(array_map(fn($c) => (int)$c['id'], $allowed_companies)));
    if (empty($allowed_ids)) {
        $allowed_ids = [current_company_id()];
    }

    if ($raw === 'all' && count($allowed_ids) > 1) {
        return [
            'mode'        => 'all',
            'is_all'      => true,
            'company_id'  => 0,
            'param_value' => 'all',
            'ids'         => $allowed_ids,
            'sql_in'      => implode(',', $allowed_ids),
            'label'       => 'Semua PT (Konsolidasi Grup)',
        ];
    }

    $cid = (int)$raw;
    if (!in_array($cid, $allowed_ids, true)) {
        $cid = current_company_id();
    }
    $comp_label = 'MSP';
    foreach ($allowed_companies as $c) {
        if ((int)$c['id'] === $cid) {
            $comp_label = $c['code'] . ' — ' . $c['name'];
            break;
        }
    }

    return [
        'mode'        => 'single',
        'is_all'      => false,
        'company_id'  => $cid,
        'param_value' => (string)$cid,
        'ids'         => [$cid],
        'sql_in'      => (string)$cid,
        'label'       => $comp_label,
    ];
}

function company_filter_select_html(string $name = 'company_id', string $selected = ''): string {
    $companies = user_companies();
    if (count($companies) <= 1) return '';
    if ($selected === '') $selected = (string)current_company_id();
    $html = '<select name="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '" class="form-select form-select-sm grid-select-pt" aria-label="Filter Perusahaan (PT)">';
    $html .= '<option value="all"' . ($selected === 'all' ? ' selected' : '') . '>Semua PT (Konsolidasi)</option>';
    foreach ($companies as $c) {
        $sel = ((string)$c['id'] === (string)$selected) ? ' selected' : '';
        $html .= '<option value="' . (int)$c['id'] . '"' . $sel . '>'
               . htmlspecialchars($c['code'] . ' — ' . $c['name'], ENT_QUOTES, 'UTF-8') . '</option>';
    }
    $html .= '</select>';
    return $html;
}

// ── Auth checks ────────────────────────────────────────────────────────────
function auth_check(): void {
    if (empty($_SESSION['user'])) redirect(APP_URL . '/auth/login.php');
}

function auth_guest(): void {
    if (!empty($_SESSION['user'])) redirect(APP_URL . '/pages/dashboard.php');
}

function auth_logout(): void {
    $_SESSION = [];
    session_destroy();
}

function current_user(): ?array {
    return $_SESSION['user'] ?? null;
}

function can(string $permission): bool {
    return in_array($permission, $_SESSION['perms'] ?? [], true);
}

function require_perm(string $permission): void {
    auth_check();
    if (!can($permission)) {
        http_response_code(403);
        include __DIR__ . '/../includes/header.php';
        echo '<div class="container mt-5"><div class="alert alert-danger">
            <i class="bi bi-shield-exclamation"></i> Anda tidak memiliki izin untuk mengakses halaman ini.</div></div>';
        include __DIR__ . '/../includes/footer.php';
        exit;
    }
}

// ── Email verification ─────────────────────────────────────────────────────
function send_verification_email(string $email, string $name, string $token): bool {
    $link = APP_URL . '/auth/verify-email.php?token=' . urlencode($token);
    $body = email_template('Verifikasi Email Anda',
        "<p>Halo, <strong>{$name}</strong>!</p>
        <p>Klik tombol di bawah untuk memverifikasi alamat email Anda:</p>
        <p style='margin:24px 0'><a href='{$link}' class='btn'>Verifikasi Email</a></p>
        <p>Link berlaku selama 24 jam. Jika Anda tidak mendaftar, abaikan email ini.</p>"
    );
    return send_mail($email, 'Verifikasi Email - ' . APP_NAME, $body);
}

function verify_email_token(string $token): bool {
    $db = getDB();
    $st = $db->prepare('SELECT id FROM users WHERE verification_token=? AND email_verified_at IS NULL LIMIT 1');
    $st->bind_param('s', $token);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$row) return false;

    $st2 = $db->prepare('UPDATE users SET email_verified_at=NOW(), verification_token=NULL WHERE id=?');
    $st2->bind_param('i', $row['id']);
    $st2->execute();
    $st2->close();
    return true;
}

// ── Forgot / Reset password ────────────────────────────────────────────────
function send_reset_email(string $email): bool {
    $db = getDB();
    $st = $db->prepare('SELECT id, name FROM users WHERE email=? AND is_active=1 LIMIT 1');
    $st->bind_param('s', $email);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$row) return false; // silently fail (don't reveal user existence)

    $token   = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));
    $st2 = $db->prepare('UPDATE users SET reset_token=?, reset_token_expires=? WHERE id=?');
    $st2->bind_param('ssi', $token, $expires, $row['id']);
    $st2->execute();
    $st2->close();

    $link = APP_URL . '/auth/reset-password.php?token=' . urlencode($token);
    $body = email_template('Reset Kata Sandi',
        "<p>Halo, <strong>{$row['name']}</strong>!</p>
        <p>Klik tombol di bawah untuk mereset kata sandi Anda:</p>
        <p style='margin:24px 0'><a href='{$link}' class='btn'>Reset Kata Sandi</a></p>
        <p>Link berlaku selama 1 jam. Jika Anda tidak meminta reset, abaikan email ini.</p>"
    );
    return send_mail($email, 'Reset Kata Sandi - ' . APP_NAME, $body);
}

function reset_password(string $token, string $new_password): bool {
    $db = getDB();
    $st = $db->prepare('SELECT id FROM users WHERE reset_token=? AND reset_token_expires > NOW() LIMIT 1');
    $st->bind_param('s', $token);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$row) return false;

    $hash = password_hash($new_password, PASSWORD_BCRYPT, ['cost' => 12]);
    $st2  = $db->prepare('UPDATE users SET password=?, reset_token=NULL, reset_token_expires=NULL WHERE id=?');
    $st2->bind_param('si', $hash, $row['id']);
    $st2->execute();
    $st2->close();
    return true;
}
