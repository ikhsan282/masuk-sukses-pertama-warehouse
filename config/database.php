<?php
// Database configuration — change these for cPanel deployment
defined('DB_HOST') || define('DB_HOST', 'localhost');
defined('DB_USER') || define('DB_USER', 'root');
defined('DB_PASS') || define('DB_PASS', '');
defined('DB_NAME') || define('DB_NAME', 'db_msp_warehouse');
defined('DB_PORT') || define('DB_PORT', 3306);
defined('DB_CHARSET') || define('DB_CHARSET', 'utf8mb4');

if (!function_exists('getDB')) {
    function getDB(): mysqli {
        static $conn = null;
        if ($conn !== null) return $conn;

        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
        if ($conn->connect_error) {
            error_log('DB connect failed: ' . $conn->connect_error);
            die(json_encode(['error' => 'Koneksi database gagal.']));
        }
        $conn->set_charset(DB_CHARSET);
        return $conn;
    }
}
