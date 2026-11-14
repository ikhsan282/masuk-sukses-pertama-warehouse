<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/functions.php';

defined('LOGIN_MAX_ATTEMPTS')      || define('LOGIN_MAX_ATTEMPTS', 5);
defined('LOGIN_LOCKOUT_SECONDS')   || define('LOGIN_LOCKOUT_SECONDS', 300); // 5 minutes cooldown
defined('REMEMBER_COOKIE_NAME')    || define('REMEMBER_COOKIE_NAME', 'msp_remember');
defined('REMEMBER_LIFETIME_DAYS')  || define('REMEMBER_LIFETIME_DAYS', 30);

function _remember_cookie_options(int $expires): array {
    $is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');
    return [
        'expires'  => $expires,
        'path'     => '/',
        'secure'   => $is_https,
        'httponly' => true,
        'samesite' => 'Lax',
    ];
}

function remember_tokens_ensure_table(?mysqli $db = null): bool {
    static $ready = false;
    if ($ready) return true;
    try {
        $db = $db ?: getDB();
        $db->query("CREATE TABLE IF NOT EXISTS `user_remember_tokens` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT UNSIGNED NOT NULL,
            `selector` CHAR(24) NOT NULL UNIQUE,
            `token_hash` CHAR(64) NOT NULL,
            `expires_at` DATETIME NOT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_remember_user_expires` (`user_id`, `expires_at`),
            INDEX `idx_remember_expires` (`expires_at`),
            FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $ready = true;
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function auth_issue_remember_token(int $user_id): void {
    try {
        $db = getDB();
        if (!remember_tokens_ensure_table($db)) return;
        $selector  = bin2hex(random_bytes(12));
        $validator = bin2hex(random_bytes(32));
        $hash      = hash('sha256', $validator);
        $expiresAt = date('Y-m-d H:i:s', time() + (REMEMBER_LIFETIME_DAYS * 86400));

        $st = $db->prepare('INSERT INTO user_remember_tokens (user_id, selector, token_hash, expires_at) VALUES (?, ?, ?, ?)');
        if ($st) {
            $st->bind_param('isss', $user_id, $selector, $hash, $expiresAt);
            $st->execute();
            $st->close();
        }
        $db->query('DELETE FROM user_remember_tokens WHERE expires_at < NOW()');
        if (!headers_sent()) {
            setcookie(REMEMBER_COOKIE_NAME, $selector . ':' . $validator, _remember_cookie_options(time() + (REMEMBER_LIFETIME_DAYS * 86400)));
        }
    } catch (Throwable $e) {}
}

function auth_revoke_remember_cookie(): void {
    try {
        $raw = (string)($_COOKIE[REMEMBER_COOKIE_NAME] ?? '');
        if ($raw !== '' && strpos($raw, ':') !== false) {
            [$selector] = explode(':', $raw, 2);
            if (preg_match('/^[a-f0-9]{24}$/', $selector)) {
                $db = getDB();
                if (remember_tokens_ensure_table($db)) {
                    $st = $db->prepare('DELETE FROM user_remember_tokens WHERE selector = ?');
                    if ($st) {
                        $st->bind_param('s', $selector);
                        $st->execute();
                        $st->close();
                    }
                }
            }
        }
    } catch (Throwable $e) {}
    unset($_COOKIE[REMEMBER_COOKIE_NAME]);
    if (!headers_sent()) {
        setcookie(REMEMBER_COOKIE_NAME, '', _remember_cookie_options(time() - 3600));
    }
}

function auth_revoke_all_remember_tokens(int $user_id): void {
    try {
        $db = getDB();
        if (!remember_tokens_ensure_table($db)) return;
        $st = $db->prepare('DELETE FROM user_remember_tokens WHERE user_id = ?');
        if ($st) {
            $st->bind_param('i', $user_id);
            $st->execute();
            $st->close();
        }
    } catch (Throwable $e) {}
}

function auth_attempt_remember_login(): bool {
    $raw = (string)($_COOKIE[REMEMBER_COOKIE_NAME] ?? '');
    if ($raw === '' || strpos($raw, ':') === false) return false;
    [$selector, $validator] = explode(':', $raw, 2);
    if (!preg_match('/^[a-f0-9]{24}$/', $selector) || !preg_match('/^[a-f0-9]{64}$/', $validator)) {
        auth_revoke_remember_cookie();
        return false;
    }
    try {
        $db = getDB();
        if (!remember_tokens_ensure_table($db)) return false;
        $st = $db->prepare('SELECT rt.user_id, rt.token_hash, u.username, u.is_active, u.email_verified_at
            FROM user_remember_tokens rt
            JOIN users u ON u.id = rt.user_id
            WHERE rt.selector = ? AND rt.expires_at > NOW() LIMIT 1');
        if (!$st) return false;
        $st->bind_param('s', $selector);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $st->close();

        if (!$row || !hash_equals((string)$row['token_hash'], hash('sha256', $validator))) {
            auth_revoke_remember_cookie();
            return false;
        }
        if (empty($row['is_active']) || empty($row['email_verified_at'])) {
            auth_revoke_all_remember_tokens((int)$row['user_id']);
            auth_revoke_remember_cookie();
            return false;
        }

        // Rotate remember token on successful consumption
        $del = $db->prepare('DELETE FROM user_remember_tokens WHERE selector = ?');
        if ($del) {
            $del->bind_param('s', $selector);
            $del->execute();
            $del->close();
        }

        _load_session((int)$row['user_id']);
        session_regenerate_id(true);
        auth_issue_remember_token((int)$row['user_id']);
        audit_log('login_remember', 'user', (int)$row['user_id'], '', 'Auto-login melalui Ingat saya', current_company_id(), (int)$row['user_id'], (string)$row['username']);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function login_attempts_ensure_table(?mysqli $db = null): bool {
    static $ready = false;
    if ($ready) return true;
    try {
        $db = $db ?: getDB();
        $db->query("CREATE TABLE IF NOT EXISTS `login_attempts` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `username_key` VARCHAR(120) NOT NULL,
            `ip_address` VARCHAR(45) NOT NULL,
            `attempted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_login_attempts_lookup` (`username_key`, `ip_address`, `attempted_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $ready = true;
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function login_remaining_lockout_seconds(string $username_key, string $ip): int {
    try {
        $db = getDB();
        if (!login_attempts_ensure_table($db)) return 0;
        $window = (int)LOGIN_LOCKOUT_SECONDS;
        $st = $db->prepare('SELECT COUNT(*), TIMESTAMPDIFF(SECOND, MAX(attempted_at), NOW()) FROM login_attempts WHERE username_key=? AND ip_address=? AND attempted_at >= DATE_SUB(NOW(), INTERVAL ? SECOND)');
        if (!$st) return 0;
        $st->bind_param('ssi', $username_key, $ip, $window);
        $st->execute();
        $st->bind_result($cnt, $elapsed);
        $st->fetch();
        $st->close();
        if ((int)$cnt < LOGIN_MAX_ATTEMPTS || $elapsed === null) return 0;
        return max(1, $window - max(0, (int)$elapsed));
    } catch (Throwable $e) {
        return 0;
    }
}

function login_record_failure(string $username_key, string $ip): void {
    try {
        $db = getDB();
        if (!login_attempts_ensure_table($db)) return;
        $st = $db->prepare('INSERT INTO login_attempts (username_key, ip_address, attempted_at) VALUES (?, ?, NOW())');
        if ($st) {
            $st->bind_param('ss', $username_key, $ip);
            $st->execute();
            $st->close();
        }
        $cutoff = date('Y-m-d H:i:s', time() - 86400);
        $cl = $db->prepare('DELETE FROM login_attempts WHERE attempted_at < ?');
        if ($cl) {
            $cl->bind_param('s', $cutoff);
            $cl->execute();
            $cl->close();
        }
    } catch (Throwable $e) {}
}

function login_clear_failures(string $username_key, string $ip): void {
    try {
        $db = getDB();
        if (!login_attempts_ensure_table($db)) return;
        $st = $db->prepare('DELETE FROM login_attempts WHERE username_key=? AND ip_address=?');
        if ($st) {
            $st->bind_param('ss', $username_key, $ip);
            $st->execute();
            $st->close();
        }
    } catch (Throwable $e) {}
}

// ── Login ──────────────────────────────────────────────────────────────────
function auth_login(string $username, string $password, bool $remember = false): bool {
    $db  = getDB();
    $key = mb_strtolower(mb_substr(trim($username), 0, 120, 'UTF-8'), 'UTF-8');
    $ip  = mb_substr((string)($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'), 0, 45, 'UTF-8');

    $waitSec = login_remaining_lockout_seconds($key, $ip);
    if ($waitSec > 0) {
        $mins = max(1, (int)ceil($waitSec / 60));
        set_flash('error', "Terlalu banyak percobaan masuk gagal. Silakan coba lagi dalam {$mins} menit.");
        if (!headers_sent()) http_response_code(429);
        audit_log('login_locked', 'user', null, '', "Akun '{$key}' diblokir sementara ({$waitSec} detik) dari IP {$ip}", null, null, $key);
        return false;
    }

    $st = $db->prepare('SELECT id, username, password, is_active, email_verified_at FROM users WHERE username=? OR email=? LIMIT 1');
    $st->bind_param('ss', $username, $username);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();

    if (!$row || !password_verify($password, $row['password'])) {
        login_record_failure($key, $ip);
        audit_log('login_failed', 'user', $row['id'] ?? null, '', "Percobaan masuk gagal untuk '{$key}'", null, $row['id'] ?? null, $key);
        return false;
    }
    if (!$row['is_active']) {
        set_flash('error', 'Akun Anda dinonaktifkan.');
        audit_log('login_blocked', 'user', (int)$row['id'], '', 'Akun dinonaktifkan mencoba login', null, (int)$row['id'], (string)$row['username']);
        return false;
    }
    if (!$row['email_verified_at']) {
        set_flash('error', 'Email belum diverifikasi. Hubungi Admin atau cek inbox Anda.');
        audit_log('login_unverified', 'user', (int)$row['id'], '', 'Akun belum terverifikasi mencoba login', null, (int)$row['id'], (string)$row['username']);
        return false;
    }

    login_clear_failures($key, $ip);
    _load_session($row['id']);
    session_regenerate_id(true);
    if ($remember) {
        auth_issue_remember_token((int)$row['id']);
    }
    audit_log('login_success', 'user', (int)$row['id'], '', 'Login berhasil', current_company_id(), (int)$row['id'], (string)$row['username']);
    return true;
}

function auth_token_hash(string $token): string {
    return hash('sha256', $token);
}

function auth_permissions_epoch_file(): string {
    $scope = (defined('DB_NAME') ? (string)DB_NAME : 'db') . '|' . dirname(__DIR__);
    return sys_get_temp_dir() . '/msp_perms_epoch_' . md5($scope) . '.txt';
}

function auth_current_permissions_epoch(): int {
    $file = auth_permissions_epoch_file();
    if (!is_file($file)) return 0;
    return (int)@file_get_contents($file);
}

function auth_touch_permissions_epoch(): void {
    $epoch = max(time(), auth_current_permissions_epoch() + 1);
    @file_put_contents(auth_permissions_epoch_file(), (string)$epoch, LOCK_EX);
}

function _load_session(int $user_id): void {
    $db = getDB();
    $st = $db->prepare('SELECT u.id, u.name, u.username, u.email, u.role_id, u.password, r.name AS role_name
        FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=? LIMIT 1');
    $st->bind_param('i', $user_id);
    $st->execute();
    $user = $st->get_result()->fetch_assoc();
    $st->close();

    if (!$user) {
        $_SESSION = [];
        return;
    }
    $pwdHash = (string)($user['password'] ?? '');
    unset($user['password']);

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
    $_SESSION['pwd_sig']        = hash('sha256', $pwdHash);
    $_SESSION['auth_role_id']   = (int)$user['role_id'];
    $_SESSION['perms_epoch']    = auth_current_permissions_epoch();
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

function sync_active_user_session(): void {
    if (!empty($_SESSION['user']['id'])) {
        $prevCompId = current_company_id();
        _load_session((int)$_SESSION['user']['id']);
        switch_company($prevCompId);
    }
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
function auth_force_logout(string $reason = 'Sesi Anda telah berakhir. Silakan masuk kembali.'): void {
    auth_revoke_remember_cookie();
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
        session_regenerate_id(true);
    }
    set_flash('error', $reason);
    redirect(APP_URL . '/auth/login.php');
}

function auth_validate_active_session(bool $redirectOnFailure = true): bool {
    $uid = (int)($_SESSION['user']['id'] ?? 0);
    if ($uid <= 0) {
        return false;
    }
    try {
        $db = getDB();
        $st = $db->prepare('SELECT is_active, role_id, password FROM users WHERE id = ? LIMIT 1');
        if (!$st) return true;
        $st->bind_param('i', $uid);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $st->close();

        $uname = (string)($_SESSION['user']['username'] ?? '');
        if (!$row || empty($row['is_active'])) {
            audit_log('session_revoked', 'user', $uid, '', 'Sesi dihentikan karena akun dinonaktifkan atau dihapus', null, $uid, $uname);
            auth_revoke_all_remember_tokens($uid);
            if ($redirectOnFailure) {
                auth_force_logout('Akun Anda telah dinonaktifkan atau tidak lagi tersedia.');
            }
            auth_revoke_remember_cookie();
            $_SESSION = [];
            return false;
        }

        if (!empty($_SESSION['pwd_sig']) && !hash_equals((string)$_SESSION['pwd_sig'], hash('sha256', (string)$row['password']))) {
            audit_log('session_invalidated', 'user', $uid, '', 'Sesi dihentikan karena kata sandi akun berubah', null, $uid, $uname);
            if ($redirectOnFailure) {
                auth_force_logout('Kata sandi akun telah diperbarui. Silakan masuk kembali.');
            }
            auth_revoke_remember_cookie();
            $_SESSION = [];
            return false;
        }

        $roleChanged  = isset($_SESSION['auth_role_id']) && (int)$row['role_id'] !== (int)$_SESSION['auth_role_id'];
        $epochChanged = (int)($_SESSION['perms_epoch'] ?? 0) < auth_current_permissions_epoch();
        if ($roleChanged || $epochChanged) {
            sync_active_user_session();
        }
    } catch (Throwable $e) {
        // Keep active session if DB check encounters a transient exception
    }
    return true;
}

function auth_check(): void {
    if (empty($_SESSION['user'])) {
        auth_attempt_remember_login();
    }
    if (empty($_SESSION['user'])) redirect(APP_URL . '/auth/login.php');
    auth_validate_active_session(true);
}

function auth_guest(): void {
    if (empty($_SESSION['user'])) {
        auth_attempt_remember_login();
    }
    if (!empty($_SESSION['user'])) {
        $role = $_SESSION['user']['role_name'] ?? '';
        redirect(APP_URL . (in_array($role, ['Staff Gudang', 'Viewer'], true) ? '/pages/portal.php' : '/pages/dashboard.php'));
    }
}

function auth_logout(): void {
    if (!empty($_SESSION['user']['id'])) {
        audit_log('logout', 'user', (int)$_SESSION['user']['id'], '', 'Logout dari sistem');
    }
    auth_revoke_remember_cookie();
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

/** Return allowed company IDs for the authenticated user. */
function user_company_ids(): array {
    return array_values(array_filter(array_map(fn($c) => (int)($c['id'] ?? 0), user_companies())));
}

/** Check whether the authenticated user holds access to a given company ID. */
function user_has_company_access(int $company_id): bool {
    return $company_id > 0 && in_array($company_id, user_company_ids(), true);
}

/**
 * Require access to at least one of the specified company IDs or abort with HTTP 403.
 *
 * @param int[] $company_ids
 * @param bool  $with_layout Render app navbar/layout when true; minimal standalone HTML when false.
 */
function require_any_company_access(array $company_ids, bool $with_layout = true): void {
    auth_check();
    foreach ($company_ids as $cid) {
        if (user_has_company_access((int)$cid)) {
            return;
        }
    }
    if (!headers_sent()) {
        http_response_code(403);
    }
    $msg = 'Anda tidak memiliki izin akses untuk perusahaan (PT) pada transaksi atau dokumen ini.';
    if ($with_layout) {
        include __DIR__ . '/../includes/header.php';
        echo '<div class="container mt-5"><div class="alert alert-danger"><i class="bi bi-shield-exclamation me-2"></i>'
            . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8')
            . '</div><a href="' . htmlspecialchars(APP_URL . '/pages/dashboard.php', ENT_QUOTES, 'UTF-8') . '" class="btn btn-outline-secondary btn-sm">Kembali ke Dashboard</a></div>';
        include __DIR__ . '/../includes/footer.php';
    } else {
        echo '<!doctype html><html lang="id"><head><meta charset="utf-8"><title>403 Akses Ditolak</title></head>'
            . '<body style="font:14px/1.5 Arial,sans-serif;margin:40px;color:#842029;background:#f8d7da;padding:16px;border-radius:6px">'
            . '<strong>403 Akses Ditolak:</strong> ' . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8')
            . '</body></html>';
    }
    exit;
}

/** Require access to a specific company ID or abort with HTTP 403. */
function require_company_access(int $company_id, bool $with_layout = true): void {
    require_any_company_access([$company_id], $with_layout);
}

// ── Email verification ─────────────────────────────────────────────────────
function send_verification_email(string $email, string $name, string $token): bool {
    $link    = APP_URL . '/auth/verify-email.php?token=' . urlencode($token);
    $nameEsc = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    $linkEsc = htmlspecialchars($link, ENT_QUOTES, 'UTF-8');
    $body = email_template('Verifikasi Email Anda',
        "<p>Halo, <strong>{$nameEsc}</strong>!</p>
        <p>Klik tombol di bawah untuk memverifikasi alamat email Anda:</p>
        <p style='margin:24px 0'><a href='{$linkEsc}' class='btn'>Verifikasi Email</a></p>
        <p>Link berlaku selama 24 jam. Jika Anda tidak mendaftar, abaikan email ini.</p>"
    );
    return send_mail($email, 'Verifikasi Email - ' . APP_NAME, $body);
}

function verify_email_token(string $token): bool {
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return false;
    }
    $db        = getDB();
    $tokenHash = auth_token_hash($token);
    $st = $db->prepare('SELECT id FROM users WHERE verification_token=? AND email_verified_at IS NULL LIMIT 1');
    $st->bind_param('s', $tokenHash);
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
function password_reset_allow_attempt(string $email, string $ip): bool {
    try {
        $db = getDB();
        if (!login_attempts_ensure_table($db)) return true;
        $window   = 300;
        $emailKey = mb_substr('pwd_reset:' . mb_strtolower(trim($email), 'UTF-8'), 0, 120, 'UTF-8');
        $ipKey    = '__pwd_reset_ip__';

        $st = $db->prepare('SELECT
            COALESCE(SUM(username_key = ?), 0),
            COALESCE(SUM(username_key = ?), 0)
            FROM login_attempts
            WHERE username_key IN (?, ?) AND ip_address = ? AND attempted_at >= DATE_SUB(NOW(), INTERVAL ? SECOND)');
        if (!$st) return true;
        $st->bind_param('sssssi', $emailKey, $ipKey, $emailKey, $ipKey, $ip, $window);
        $st->execute();
        $st->bind_result($emailCnt, $ipCnt);
        $st->fetch();
        $st->close();

        if ((int)$emailCnt >= 3 || (int)$ipCnt >= 10) {
            return false;
        }
        login_record_failure($emailKey, $ip);
        login_record_failure($ipKey, $ip);
        return true;
    } catch (Throwable $e) {
        return true;
    }
}

function auth_find_user_by_reset_token(string $token): ?int {
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }
    $db        = getDB();
    $tokenHash = auth_token_hash($token);
    $st = $db->prepare('SELECT id FROM users WHERE reset_token=? AND reset_token_expires > NOW() AND is_active=1 LIMIT 1');
    if (!$st) return null;
    $st->bind_param('s', $tokenHash);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    return $row ? (int)$row['id'] : null;
}

function send_reset_email(string $email): bool {
    $ip = mb_substr((string)($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'), 0, 45, 'UTF-8');
    if (!password_reset_allow_attempt($email, $ip)) {
        audit_log('password_reset_throttled', 'user', null, '', "Permintaan reset sandi dibatasi untuk '{$email}' dari IP {$ip}");
        return false;
    }

    $db = getDB();
    $st = $db->prepare('SELECT id, name FROM users WHERE email=? AND is_active=1 LIMIT 1');
    $st->bind_param('s', $email);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$row) return false; // silently fail (don't reveal user existence)

    $token     = bin2hex(random_bytes(32));
    $tokenHash = auth_token_hash($token);
    $expires   = date('Y-m-d H:i:s', strtotime('+1 hour'));
    $st2 = $db->prepare('UPDATE users SET reset_token=?, reset_token_expires=? WHERE id=?');
    $st2->bind_param('ssi', $tokenHash, $expires, $row['id']);
    $st2->execute();
    $st2->close();

    $link    = APP_URL . '/auth/reset-password.php?token=' . urlencode($token);
    $nameEsc = htmlspecialchars((string)$row['name'], ENT_QUOTES, 'UTF-8');
    $linkEsc = htmlspecialchars($link, ENT_QUOTES, 'UTF-8');
    $body = email_template('Reset Kata Sandi',
        "<p>Halo, <strong>{$nameEsc}</strong>!</p>
        <p>Klik tombol di bawah untuk mereset kata sandi Anda:</p>
        <p style='margin:24px 0'><a href='{$linkEsc}' class='btn'>Reset Kata Sandi</a></p>
        <p>Link berlaku selama 1 jam. Jika Anda tidak meminta reset, abaikan email ini.</p>"
    );
    return send_mail($email, 'Reset Kata Sandi - ' . APP_NAME, $body);
}

function reset_password(string $token, string $new_password): bool {
    $uid = auth_find_user_by_reset_token($token);
    if ($uid === null) return false;

    $db   = getDB();
    $hash = password_hash($new_password, PASSWORD_BCRYPT, ['cost' => 12]);
    $st2  = $db->prepare('UPDATE users SET password=?, reset_token=NULL, reset_token_expires=NULL WHERE id=?');
    $st2->bind_param('si', $hash, $uid);
    $st2->execute();
    $st2->close();
    auth_revoke_all_remember_tokens($uid);
    audit_log('password_reset_complete', 'user', $uid, '', 'Kata sandi direset melalui tautan pemulihan', null, $uid, '');
    return true;
}
