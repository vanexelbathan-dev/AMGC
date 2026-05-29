<?php
// DATABASE CONFIG
define('DB_HOST', 'localhost'); 
define('DB_USER', 'root'); 
define('DB_PASSWORD', ''); 
define('DB_NAME', 'amgc_inventory_system'); 

// CONNECT DATABASE
$conn = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);

// Check connection
if ($conn->connect_error) {
    die("Database Connection Failed: " . $conn->connect_error);
}

// Set charset
$conn->set_charset("utf8mb4");
$conn->query("SET time_zone = '+08:00'");

// Enable MySQLi error reporting
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
?>