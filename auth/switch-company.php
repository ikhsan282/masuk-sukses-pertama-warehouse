<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method Not Allowed: gunakan POST dengan token CSRF.');
}

auth_check();
verify_csrf();

$target_id = (int)($_POST['company_id'] ?? ($_POST['id'] ?? 0));
$requested_redirect = trim(str_replace(["\r", "\n"], '', (string)($_POST['redirect'] ?? '')));
if ($requested_redirect !== '' && str_starts_with($requested_redirect, '/') && !str_starts_with($requested_redirect, '//')) {
    $redirect_to = APP_URL . $requested_redirect;
} else {
    $redirect_to = str_replace(["\r", "\n"], '', (string)($_SERVER['HTTP_REFERER'] ?? (APP_URL . '/pages/dashboard.php')));
}

// Prevent redirecting outside application
if (!str_starts_with($redirect_to, APP_URL)) {
    $redirect_to = APP_URL . '/pages/dashboard.php';
}

if ($target_id > 0 && switch_company($target_id)) {
    $comp = current_company();
    audit_log('company.switch', 'company', $target_id, (string)($comp['code'] ?? ''), 'Beralih ke perusahaan ' . (string)($comp['name'] ?? ''));
    set_flash('success', 'Beralih ke perusahaan: <strong>' . (string)($comp['name'] ?? '') . '</strong>');
} else {
    set_flash('error', 'Gagal beralih perusahaan atau Anda tidak memiliki akses.');
}

redirect($redirect_to);
