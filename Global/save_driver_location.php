<?php
require_once '../config/database.php';
session_start();

header('Content-Type: application/json');

// Check authentication
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$user_id = $_SESSION['user_id'];

// POST - Save location
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    
    // Create table if not exists
    $create_table = "CREATE TABLE IF NOT EXISTS driver_tracking (
        id INT AUTO_INCREMENT PRIMARY KEY,
        driver_id INT,
        user_id INT,
        latitude DECIMAL(10, 8),
        longitude DECIMAL(11, 8),
        accuracy FLOAT,
        speed FLOAT,
        timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX(driver_id),
        INDEX(timestamp)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8";
    
    $conn->query($create_table);
    
    // Validate input
    if (!isset($input['latitude']) || !isset($input['longitude'])) {
        echo json_encode(['success' => false, 'message' => 'Missing coordinates']);
        exit;
    }
    
    $latitude = floatval($input['latitude']);
    $longitude = floatval($input['longitude']);
    $accuracy = isset($input['accuracy']) ? floatval($input['accuracy']) : 0;
    $speed = isset($input['speed']) ? floatval($input['speed']) : 0;
    
    // Validate coordinates
    if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
        echo json_encode(['success' => false, 'message' => 'Invalid coordinates']);
        exit;
    }
    
    // Get driver ID
    $driver_sql = "SELECT driver_id FROM drivers WHERE user_id = ? LIMIT 1";
    $driver_stmt = $conn->prepare($driver_sql);
    $driver_stmt->bind_param("i", $user_id);
    $driver_stmt->execute();
    $driver_result = $driver_stmt->get_result();
    
    if ($driver_result->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => 'Driver not found']);
        $driver_stmt->close();
        exit;
    }
    
    $driver_row = $driver_result->fetch_assoc();
    $driver_id = $driver_row['driver_id'];
    $driver_stmt->close();
    
    // Update driver online status
    $update_sql = "UPDATE drivers SET status = 'online', last_location_update = NOW() WHERE driver_id = ?";
    $update_stmt = $conn->prepare($update_sql);
    $update_stmt->bind_param("i", $driver_id);
    $update_stmt->execute();
    $update_stmt->close();
    
    // Insert location
    $insert_sql = "INSERT INTO driver_tracking (driver_id, user_id, latitude, longitude, accuracy, speed) VALUES (?, ?, ?, ?, ?, ?)";
    $insert_stmt = $conn->prepare($insert_sql);
    $insert_stmt->bind_param("iiddff", $driver_id, $user_id, $latitude, $longitude, $accuracy, $speed);
    
    if ($insert_stmt->execute()) {
        echo json_encode([
            'success' => true,
            'message' => 'Location saved',
            'driver_id' => $driver_id
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to save']);
    }
    
    $insert_stmt->close();
    $conn->close();
    exit;
}

// GET - Get latest location
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $driver_sql = "SELECT driver_id FROM drivers WHERE user_id = ? LIMIT 1";
    $driver_stmt = $conn->prepare($driver_sql);
    $driver_stmt->bind_param("i", $user_id);
    $driver_stmt->execute();
    $driver_result = $driver_stmt->get_result();
    
    if ($driver_result->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => 'Driver not found']);
        $driver_stmt->close();
        exit;
    }
    
    $driver_row = $driver_result->fetch_assoc();
    $driver_id = $driver_row['driver_id'];
    $driver_stmt->close();
    
    // Get latest location
    $get_sql = "SELECT * FROM driver_tracking WHERE driver_id = ? ORDER BY timestamp DESC LIMIT 1";
    $get_stmt = $conn->prepare($get_sql);
    $get_stmt->bind_param("i", $driver_id);
    $get_stmt->execute();
    $get_result = $get_stmt->get_result();
    
    $location = null;
    if ($get_result->num_rows > 0) {
        $location = $get_result->fetch_assoc();
    }
    
    $get_stmt->close();
    
    echo json_encode([
        'success' => true,
        'location' => $location
    ]);
    
    $conn->close();
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'message' => 'Method not allowed']);
?>
