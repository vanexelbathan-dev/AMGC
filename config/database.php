<?php
// DATABASE CONFIG
define('DB_HOST', 'srv1981.hstgr.io');
define('DB_USER', 'u431396407_amgc2026');
define('DB_PASSWORD', 'Amgc@2026');
define('DB_NAME', 'u431396407_amgc_system');
define('DB_PORT', 3306);
define('DB_CHARSET', 'utf8mb4');

// CONNECT DATABASE
$conn = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME, DB_PORT);

// Check connection
if ($conn->connect_error) {
    die("Database Connection Failed: " . $conn->connect_error);
}

// Set charset
$conn->set_charset(DB_CHARSET);
$conn->query("SET time_zone = '+08:00'");

// Enable MySQLi error reporting
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
?>
