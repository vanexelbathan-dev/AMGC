<?php
/**
 * AMGC Database Configuration File
 * 
 * This file contains the database connection settings for the AMGC Inventory Management System.
 * 
 * Database: amgc_inventory_system
 * Created: February 10, 2026
 */

// =====================================================
// DATABASE CONFIGURATION
// =====================================================

// Database Server
define('DB_HOST', 'localhost');

// Database Username (default MySQL username)
define('DB_USER', 'root');

// Database Password (leave empty if no password set)
define('DB_PASSWORD', '');

// Database Name (created by SQL script)
define('DB_NAME', 'amgc_inventory_system');

// Optional: Database Port (default 3306)
define('DB_PORT', 3306);

// =====================================================
// CONNECTION SETTINGS
// =====================================================

// Set to 'mysqli' or 'pdo' depending on your preference
define('DB_DRIVER', 'mysqli');

// Character Set
define('DB_CHARSET', 'utf8mb4');

// =====================================================
// ESTABLISH DATABASE CONNECTION
// =====================================================

try {
    if (DB_DRIVER === 'mysqli') {
        // Using MySQLi
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME, DB_PORT);
        
        // Check connection
        if ($conn->connect_error) {
            die("Database Connection Failed: " . $conn->connect_error);
        }
        
        // Set charset
        $conn->set_charset(DB_CHARSET);
        
        // Enable error reporting
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        
    } elseif (DB_DRIVER === 'pdo') {
        // Using PDO (commented out - uncomment if using PDO)
        // $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        // $conn = new PDO($dsn, DB_USER, DB_PASSWORD);
        // $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }
    
    // Optional: Log successful connection
    // error_log("Database connection established successfully at " . date('Y-m-d H:i:s'));
    
} catch (Exception $e) {
    // Log the error
    error_log("Database Connection Error: " . $e->getMessage());
    
    // Display user-friendly error message
    if (php_sapi_name() === 'cli') {
        // CLI mode
        echo "Database Connection Error: " . $e->getMessage() . "\n";
    } else {
        // Web mode
        die("
            <div style='text-align: center; padding: 50px; font-family: Arial, sans-serif;'>
                <h1 style='color: #c41e3a;'>Database Connection Error</h1>
                <p>Unable to connect to the database. Please check your configuration.</p>
                <p style='color: #666; font-size: 12px;'>Error: " . $e->getMessage() . "</p>
                <p style='color: #999; font-size: 11px;'>
                    Make sure the database server is running and the credentials in config/database.php are correct.
                </p>
            </div>
        ");
    }
    exit;
}

// =====================================================
// HELPER FUNCTIONS FOR DATABASE OPERATIONS
// =====================================================

/**
 * Execute a prepared statement query
 * 
 * @param mysqli $conn Database connection
 * @param string $query SQL query with placeholders (?)
 * @param array $params Parameters to bind
 * @param string $types Data types (e.g., 'sss' for three strings)
 * @return mixed Result object or false
 */
function executeQuery($conn, $query, $params = [], $types = '') {
    if (empty($params)) {
        return $conn->query($query);
    }
    
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        error_log("Query Preparation Error: " . $conn->error);
        return false;
    }
    
    if (!empty($types) && !empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    
    if (!$stmt->execute()) {
        error_log("Query Execution Error: " . $stmt->error);
        return false;
    }
    
    return $stmt->get_result();
}

/**
 * Fetch single row from result
 * 
 * @param mysqli_result $result Query result
 * @return array Associative array of row data
 */
function fetchRow($result) {
    return $result->fetch_assoc();
}

/**
 * Fetch all rows from result
 * 
 * @param mysqli_result $result Query result
 * @return array Array of associative arrays
 */
function fetchAllRows($result) {
    return $result->fetch_all(MYSQLI_ASSOC);
}

/**
 * Get the ID of the last inserted row
 * 
 * @param mysqli $conn Database connection
 * @return int Last insert ID
 */
function getLastInsertId($conn) {
    return $conn->insert_id;
}

/**
 * Get the number of affected rows from last query
 * 
 * @param mysqli $conn Database connection
 * @return int Number of affected rows
 */
function getAffectedRows($conn) {
    return $conn->affected_rows;
}

/**
 * Escape string for SQL (prevent SQL injection)
 * 
 * @param mysqli $conn Database connection
 * @param string $string String to escape
 * @return string Escaped string
 */
function escapeString($conn, $string) {
    return $conn->real_escape_string($string);
}

/**
 * Check if table exists in database
 * 
 * @param mysqli $conn Database connection
 * @param string $table Table name
 * @return bool True if table exists
 */
function tableExists($conn, $table) {
    $result = $conn->query("SHOW TABLES LIKE '" . escapeString($conn, $table) . "'");
    return $result->num_rows > 0;
}

/**
 * Get database statistics
 * 
 * @param mysqli $conn Database connection
 * @return array Statistics array
 */
function getDatabaseStats($conn) {
    $stats = [];
    
    // Get total database size
    $result = $conn->query("SELECT 
        SUM(ROUND(((data_length + index_length) / 1024 / 1024), 2)) AS size_mb
        FROM information_schema.TABLES 
        WHERE table_schema = '" . DB_NAME . "'");
    $row = $result->fetch_assoc();
    $stats['database_size_mb'] = $row['size_mb'] ?? 0;
    
    // Count total tables
    $result = $conn->query("SELECT COUNT(*) as table_count FROM information_schema.TABLES 
        WHERE table_schema = '" . DB_NAME . "'");
    $row = $result->fetch_assoc();
    $stats['total_tables'] = $row['table_count'] ?? 0;
    
    return $stats;
}

/**
 * Close database connection
 * 
 * @param mysqli $conn Database connection
 */
function closeConnection($conn) {
    if ($conn) {
        $conn->close();
    }
}

// =====================================================
// ERROR HANDLING
// =====================================================

/**
 * Log database error
 * 
 * @param string $error Error message
 */
function logDatabaseError($error) {
    $log_file = __DIR__ . '/../logs/database_errors.log';
    
    // Create logs directory if it doesn't exist
    if (!is_dir(dirname($log_file))) {
        mkdir(dirname($log_file), 0755, true);
    }
    
    $timestamp = date('Y-m-d H:i:s');
    $log_message = "[$timestamp] $error\n";
    
    file_put_contents($log_file, $log_message, FILE_APPEND);
}


function testDatabaseConnection() {
    global $conn;
    
    echo "<h3>Database Connection Test</h3>";
    
    if ($conn) {
        echo "<p style='color: green;'><strong>✓ Connected to: " . DB_NAME . "</strong></p>";
        echo "<p>Host: " . DB_HOST . "</p>";
        echo "<p>User: " . DB_USER . "</p>";
        echo "<p>Server Version: " . $conn->server_info . "</p>";
        
        // Test query
        $result = $conn->query("SELECT COUNT(*) as table_count FROM information_schema.TABLES WHERE table_schema = '" . DB_NAME . "'");
        $row = $result->fetch_assoc();
        echo "<p>Total Tables: " . $row['table_count'] . "</p>";
    } else {
        echo "<p style='color: red;'><strong>✗ Connection Failed</strong></p>";
    }
}


?>
