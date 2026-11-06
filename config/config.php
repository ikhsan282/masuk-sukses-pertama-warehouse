<?php
defined('APP_NAME') || define('APP_NAME', 'Masuk Sukses Pertama Warehouse');
defined('APP_VERSION') || define('APP_VERSION', '1.0.0');
defined('APP_URL') || define('APP_URL', 'http://localhost:8080/masuk-sukses-pertama-warehouse'); // change for production
defined('APP_TIMEZONE') || define('APP_TIMEZONE', 'Asia/Jakarta');

// Email (PHP mail() — works on shared hosting)
defined('MAIL_FROM') || define('MAIL_FROM', 'no-reply@localhost');
defined('MAIL_FROM_NAME') || define('MAIL_FROM_NAME', 'Masuk Sukses Pertama Warehouse');

// Session
defined('SESSION_LIFETIME') || define('SESSION_LIFETIME', 7200); // 2 hours

// Pagination
defined('PER_PAGE') || define('PER_PAGE', 20);

// Company identity (used on printed documents)
defined('COMPANY_NAME') || define('COMPANY_NAME', 'Masuk Sukses Pertama Warehouse');
defined('COMPANY_ADDRESS') || define('COMPANY_ADDRESS', 'Jl. Contoh No. 123, Jakarta'); // change for production

date_default_timezone_set(APP_TIMEZONE);

// Start session once
if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME,
        'path'     => '/',
        'secure'   => true, // requires HTTPS // set true on HTTPS
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}
