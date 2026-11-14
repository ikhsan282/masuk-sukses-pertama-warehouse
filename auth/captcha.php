<?php
/**
 * Endpoint Gambar PNG Adaptive Numeric CAPTCHA Login
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/captcha.php';

$code = captcha_issue_challenge(LOGIN_CAPTCHA_DIGITS);
$dark = (isset($_GET['theme']) && $_GET['theme'] === 'dark');
$png  = captcha_render_png($code, 180, 54, $dark);

header('Content-Type: image/png');
header('Content-Length: ' . strlen($png));
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
header('X-Content-Type-Options: nosniff');

echo $png;
