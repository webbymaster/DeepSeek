<?php
@ini_set('session.cookie_httponly',1);
@ini_set('session.use_only_cookies',1);
if (!version_compare(PHP_VERSION, '7.1.0', '>=')) {
    exit("Required PHP_VERSION >= 7.1.0 , Your PHP_VERSION is : " . PHP_VERSION . "\n");
}
if (!function_exists("mysqli_connect")) {
    exit("MySQLi is required to run the application, please contact your hosting to enable php mysqli.");
}
date_default_timezone_set('UTC+3'); // date_default_timezone_set('Europe/Moscow');
session_start();
// ======== НАСТРОЙКИ ЛОГИРОВАНИЯ ========
define('PHP_ERROR_LOG', '/var/www/webbyon/data/logs/webbyon.ru.storage.error.log');

// Создаем директорию логов, если её нет
if (!file_exists(dirname(PHP_ERROR_LOG))) {
    @mkdir(dirname(PHP_ERROR_LOG), 0755, true);
}
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', PHP_ERROR_LOG);  // Используем определённую константу
error_log("\n===== [".date('Y-m-d H:i:s')."] New request =====");
error_log("Request URI: ".($_SERVER['REQUEST_URI'] ?? 'unknown'));
// ======================================
require('assets/includes/functions_general.php');
require('assets/includes/tables.php');
require('assets/includes/functions_one.php');