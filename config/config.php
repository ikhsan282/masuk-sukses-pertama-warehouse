<?php
require_once __DIR__ . '/env.php';

if (!function_exists('msp_detect_app_url')) {
    function msp_detect_app_url(): string {
        if (PHP_SAPI === 'cli' || empty($_SERVER['HTTP_HOST'])) {
            return 'http://localhost:8080/masuk-sukses-pertama-warehouse';
        }
        $host = preg_replace('/[^a-zA-Z0-9.\-:\[\]]/', '', (string)$_SERVER['HTTP_HOST']);
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
            || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');
        $scheme = $isHttps ? 'https' : 'http';

        $basePath = '';
        $appRoot = realpath(dirname(__DIR__));
        $docRoot = !empty($_SERVER['DOCUMENT_ROOT']) ? realpath((string)$_SERVER['DOCUMENT_ROOT']) : false;
        if ($appRoot && $docRoot) {
            $appNorm = str_replace('\\', '/', rtrim($appRoot, '/\\'));
            $docNorm = str_replace('\\', '/', rtrim($docRoot, '/\\'));
            if (stripos($appNorm, $docNorm) === 0) {
                $rel = substr($appNorm, strlen($docNorm));
                $basePath = $rel === '' ? '' : '/' . ltrim($rel, '/');
            }
        }
        return rtrim($scheme . '://' . $host . $basePath, '/');
    }
}

defined('APP_NAME') || define('APP_NAME', 'Masuk Sukses Pertama Warehouse');
defined('APP_VERSION') || define('APP_VERSION', '1.1.0');
defined('APP_URL') || define('APP_URL', msp_detect_app_url());
defined('APP_TIMEZONE') || define('APP_TIMEZONE', 'Asia/Jakarta');

// Email (PHP mail() or built-in SMTP socket)
defined('MAIL_DRIVER')    || define('MAIL_DRIVER', 'mail'); // 'mail' | 'smtp'
defined('MAIL_FROM')      || define('MAIL_FROM', 'no-reply@localhost');
defined('MAIL_FROM_NAME') || define('MAIL_FROM_NAME', 'Masuk Sukses Pertama Warehouse');
defined('SMTP_HOST')      || define('SMTP_HOST', '');
defined('SMTP_PORT')      || define('SMTP_PORT', 587);
defined('SMTP_USER')      || define('SMTP_USER', '');
defined('SMTP_PASS')      || define('SMTP_PASS', '');
defined('SMTP_SECURE')    || define('SMTP_SECURE', 'tls'); // 'tls' | 'ssl' | ''
defined('SMTP_TIMEOUT')   || define('SMTP_TIMEOUT', 10);

// Session
defined('SESSION_LIFETIME') || define('SESSION_LIFETIME', 7200); // 2 hours

// Pagination
defined('PER_PAGE') || define('PER_PAGE', 20);

// Company identity fallback (printed docs prioritize active company record from `companies` table)
defined('COMPANY_NAME')    || define('COMPANY_NAME', 'PT Masuk Sukses Pertama');
defined('COMPANY_ADDRESS') || define('COMPANY_ADDRESS', 'The East Building Lt. 37 Mega Kuningan, Jakarta Selatan');

date_default_timezone_set(APP_TIMEZONE);

// Start session once
if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    $is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');
    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME,
        'path'     => '/',
        'secure'   => $is_https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}
