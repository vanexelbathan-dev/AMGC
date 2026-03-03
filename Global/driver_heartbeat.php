<?php
/**
 * Driver Heartbeat Endpoint
 * Marks driver as online and maintains session
 */

require_once '../config/database.php';
session_start();

header('Content-Type: application/json');

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

// Validate session
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Get driver_id from session
$user_id = $_SESSION['user_id'];
$driver_id = null;

// Find driver_id from user_id
$driver_sql = "SELECT driver_id FROM drivers WHERE user_id = ? LIMIT 1";
$driver_stmt = $conn->prepare($driver_sql);
$driver_stmt->bind_param("i", $user_id);
$driver_stmt->execute();
$driver_result = $driver_stmt->get_result();

if ($driver_result->num_rows > 0) {
    $driver_row = $driver_result->fetch_assoc();
    $driver_id = $driver_row['driver_id'];
} else {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Driver not found']);
    exit;
}

$driver_stmt->close();

// Update or create driver session record
$session_token = isset($input['session_token']) ? sanitize_input($input['session_token']) : null;
$current_time = date('Y-m-d H:i:s');
$is_heartbeat = isset($input['is_heartbeat']) ? 1 : 0;

// Check if driver_sessions table exists
$table_check = $conn->query("SHOW TABLES LIKE 'driver_sessions'");
if ($table_check->num_rows === 0) {
    // Create table
    $create_table = "CREATE TABLE IF NOT EXISTS driver_sessions (
        session_id INT AUTO_INCREMENT PRIMARY KEY,
        driver_id INT NOT NULL,
        session_token VARCHAR(255),
        last_heartbeat DATETIME,
        last_location_update DATETIME,
        current_latitude DECIMAL(10, 8),
        current_longitude DECIMAL(11, 8),
        is_online TINYINT DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_driver_id (driver_id),
        INDEX idx_heartbeat (last_heartbeat),
        FOREIGN KEY (driver_id) REFERENCES drivers(driver_id) ON DELETE CASCADE
    )";
    
    if ($conn->query($create_table)) {
        error_log("Created driver_sessions table");
    }
}

// Get latitude/longitude from input if provided
$latitude = isset($input['latitude']) ? floatval($input['latitude']) : null;
$longitude = isset($input['longitude']) ? floatval($input['longitude']) : null;

// Update or insert session record
$check_sql = "SELECT session_id FROM driver_sessions WHERE driver_id = ? LIMIT 1";
$check_stmt = $conn->prepare($check_sql);
$check_stmt->bind_param("i", $driver_id);
$check_stmt->execute();
$check_result = $check_stmt->get_result();

if ($check_result->num_rows > 0) {
    // Update existing session
    $update_sql = "UPDATE driver_sessions 
                   SET last_heartbeat = ?, 
                       session_token = ?,
                       is_online = 1,
                       current_latitude = COALESCE(?, current_latitude),
                       current_longitude = COALESCE(?, current_longitude)
                   WHERE driver_id = ?";
    
    $update_stmt = $conn->prepare($update_sql);
    $update_stmt->bind_param("ssddi", $current_time, $session_token, $latitude, $longitude, $driver_id);
    $update_stmt->execute();
    $update_stmt->close();
} else {
    // Create new session
    $insert_sql = "INSERT INTO driver_sessions 
                   (driver_id, session_token, last_heartbeat, current_latitude, current_longitude, is_online) 
                   VALUES (?, ?, ?, ?, ?, 1)";
    
    $insert_stmt = $conn->prepare($insert_sql);
    $insert_stmt->bind_param("issddd", $driver_id, $session_token, $current_time, $latitude, $longitude);
    $insert_stmt->execute();
    $insert_stmt->close();
}

$check_stmt->close();

// Also update drivers table to mark as online
$driver_update = "UPDATE drivers SET status = 'active', last_location_update = ? WHERE driver_id = ?";
$driver_stmt = $conn->prepare($driver_update);
$driver_stmt->bind_param("si", $current_time, $driver_id);
$driver_stmt->execute();
$driver_stmt->close();

// Log heartbeat
error_log("Heartbeat from driver_id: $driver_id, is_heartbeat: $is_heartbeat");

echo json_encode([
    'success' => true,
    'message' => 'Heartbeat received',
    'data' => [
        'driver_id' => $driver_id,
        'timestamp' => $current_time,
        'is_online' => 1
    ]
]);

function sanitize_input($input) {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}
?>
