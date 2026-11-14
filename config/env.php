<?php
/**
 * Environment & local configuration loader (.env / config/config.local.php).
 * Zero external dependencies; never overrides already-defined constants.
 */
if (!defined('MSP_ENV_LOADED')) {
    define('MSP_ENV_LOADED', true);

    $localCfg = __DIR__ . '/config.local.php';
    if (is_file($localCfg)) {
        require_once $localCfg;
    }

    $envFile = dirname(__DIR__) . '/.env';
    if (is_file($envFile) && is_readable($envFile)) {
        $lines = @file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (is_array($lines)) {
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#')) {
                    continue;
                }
                $eq = strpos($line, '=');
                if ($eq === false) {
                    continue;
                }
                $key = trim(substr($line, 0, $eq));
                $val = trim(substr($line, $eq + 1));
                if ($key === '' || !preg_match('/^[A-Z0-9_]+$/', $key)) {
                    continue;
                }
                $len = strlen($val);
                if ($len >= 2 && (($val[0] === '"' && $val[$len - 1] === '"') || ($val[0] === "'" && $val[$len - 1] === "'"))) {
                    $val = substr($val, 1, -1);
                }
                if (getenv($key) === false) {
                    @putenv("{$key}={$val}");
                    $_ENV[$key] = $val;
                }
            }
        }
    }

    $mapString = [
        'DB_HOST', 'DB_USER', 'DB_PASS', 'DB_NAME', 'DB_CHARSET',
        'APP_NAME', 'APP_URL', 'APP_TIMEZONE',
        'MAIL_DRIVER', 'MAIL_FROM', 'MAIL_FROM_NAME',
        'SMTP_HOST', 'SMTP_USER', 'SMTP_PASS', 'SMTP_SECURE',
        'COMPANY_NAME', 'COMPANY_ADDRESS', 'HEALTH_CHECK_TOKEN',
    ];
    foreach ($mapString as $k) {
        if (!defined($k)) {
            $envVal = getenv($k);
            if ($envVal !== false && ($envVal !== '' || $k === 'DB_PASS')) {
                define($k, (string)$envVal);
            }
        }
    }

    $mapInt = ['DB_PORT', 'SESSION_LIFETIME', 'PER_PAGE', 'SMTP_PORT', 'SMTP_TIMEOUT'];
    foreach ($mapInt as $k) {
        if (!defined($k)) {
            $envVal = getenv($k);
            if ($envVal !== false && is_numeric($envVal)) {
                define($k, (int)$envVal);
            }
        }
    }
}
