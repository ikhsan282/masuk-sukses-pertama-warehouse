<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

auth_check();

$target_id = req_int('company_id') ?: req_int('id');
$requested_redirect = trim(req_str('redirect'));
if ($requested_redirect !== '' && str_starts_with($requested_redirect, '/') && !str_starts_with($requested_redirect, '//')) {
    $redirect_to = APP_URL . $requested_redirect;
} else {
    $redirect_to = $_SERVER['HTTP_REFERER'] ?? (APP_URL . '/pages/dashboard.php');
}

// Prevent redirecting outside application
if (!str_starts_with($redirect_to, APP_URL)) {
    $redirect_to = APP_URL . '/pages/dashboard.php';
}

if ($target_id > 0 && switch_company($target_id)) {
    $comp = current_company();
    set_flash('success', 'Beralih ke perusahaan: <strong>' . (string)($comp['name'] ?? '') . '</strong>');
} else {
    set_flash('error', 'Gagal beralih perusahaan atau Anda tidak memiliki akses.');
}

redirect($redirect_to);
