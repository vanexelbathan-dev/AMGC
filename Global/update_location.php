<?php
/**
 * Simple Driver Location Update Endpoint
 * Based on the video tutorial approach
 */

header('Content-Type: application/json');

// Enable error logging but don't display errors
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

require_once '../config/database.php';
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized - Please login']);
    exit;
}

$user_id = $_SESSION['user_id'];

// Get driver info - simplified query
$driver_query = "SELECT d.driver_id, d.driver_name 
                 FROM drivers d 
                 WHERE d.user_id = ? 
                 LIMIT 1";
$stmt = $conn->prepare($driver_query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$driver = $result->fetch_assoc();
$stmt->close();

// If not found in drivers table, try users table
if (!$driver) {
    $user_query = "SELECT driver_id FROM users WHERE user_id = ? AND driver_id IS NOT NULL LIMIT 1";
    $stmt = $conn->prepare($user_query);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user_data = $result->fetch_assoc();
    $stmt->close();
    
    if ($user_data && $user_data['driver_id']) {
        // Get driver name from drivers table
        $name_query = "SELECT driver_name FROM drivers WHERE driver_id = ? LIMIT 1";
        $stmt = $conn->prepare($name_query);
        $stmt->bind_param("i", $user_data['driver_id']);
        $stmt->execute();
        $name_result = $stmt->get_result();
        $name_data = $name_result->fetch_assoc();
        $stmt->close();
        
        $driver = [
            'driver_id' => $user_data['driver_id'],
            'driver_name' => $name_data ? $name_data['driver_name'] : 'Driver'
        ];
    }
}

if (!$driver) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Driver not found for this user']);
    exit;
}

// Get POST data (JSON)
$input = file_get_contents('php://input');
$data = json_decode($input, true);

// If no JSON, try POST
if (!$data) {
    $data = $_POST;
}

if (!$data || !isset($data['latitude']) || !isset($data['longitude'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing latitude or longitude']);
    exit;
}

$latitude = floatval($data['latitude']);
$longitude = floatval($data['longitude']);
$accuracy = isset($data['accuracy']) ? floatval($data['accuracy']) : 0;
$speed = isset($data['speed']) ? floatval($data['speed']) : 0;
$heading = isset($data['heading']) ? floatval($data['heading']) : 0;

// Validate coordinates
if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid coordinates']);
    exit;
}

// Simple upsert - insert or update
$sql = "INSERT INTO driver_locations 
        (driver_id, driver_name, latitude, longitude, accuracy, speed, heading, is_active)
        VALUES (?, ?, ?, ?, ?, ?, ?, 1)
        ON DUPLICATE KEY UPDATE
        latitude = VALUES(latitude),
        longitude = VALUES(longitude),
        accuracy = VALUES(accuracy),
        speed = VALUES(speed),
        heading = VALUES(heading),
        is_active = 1,
        last_update = CURRENT_TIMESTAMP";

$stmt = $conn->prepare($sql);
$stmt->bind_param(
    "isddddd", 
    $driver['driver_id'],
    $driver['driver_name'],
    $latitude,
    $longitude,
    $accuracy,
    $speed,
    $heading
);

if ($stmt->execute()) {
    // Also update drivers table
    $update_driver = "UPDATE drivers SET 
                      last_latitude = ?, 
                      last_longitude = ?, 
                      last_location_update = NOW() 
                      WHERE driver_id = ?";
    $update_stmt = $conn->prepare($update_driver);
    $update_stmt->bind_param("ddi", $latitude, $longitude, $driver['driver_id']);
    $update_stmt->execute();
    $update_stmt->close();
    
    echo json_encode([
        'success' => true,
        'message' => 'Location updated',
        'timestamp' => time()
    ]);
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $conn->error]);
}

$stmt->close();
$conn->close();
?>