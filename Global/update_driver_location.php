<?php
require_once '../config/database.php';
session_start();

// Enable error logging
error_log("========== update_driver_location.php called ==========");
error_log("Method: " . $_SERVER['REQUEST_METHOD']);

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    error_log("ERROR: User not logged in");
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized - Please login']);
    exit;
}

error_log("User ID: " . $_SESSION['user_id']);
error_log("Session data: " . print_r($_SESSION, true));

// For POST requests - update location
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    
    error_log("Received input: " . print_r($input, true));
    
    // If no JSON, try POST data
    if (!$input) {
        $input = $_POST;
        error_log("Using POST data: " . print_r($input, true));
    }
    
    // Validate required fields
    if (!isset($input['latitude']) || !isset($input['longitude'])) {
        error_log("ERROR: Missing latitude or longitude");
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing latitude or longitude']);
        exit;
    }
    
    $latitude = floatval($input['latitude']);
    $longitude = floatval($input['longitude']);
    $speed_kmh = isset($input['speed']) ? floatval($input['speed']) : 0;
    $accuracy = isset($input['accuracy']) ? floatval($input['accuracy']) : 0;
    $heading = isset($input['heading']) ? floatval($input['heading']) : 0;
    $current_time = date('Y-m-d H:i:s');
    
    error_log("Parsed coordinates - Lat: $latitude, Lng: $longitude, Speed: $speed_kmh, Accuracy: $accuracy");
    
    // Validate latitude and longitude ranges
    if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
        error_log("ERROR: Invalid coordinates - lat: $latitude, lng: $longitude");
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid latitude or longitude coordinates']);
        exit;
    }
    
    // Get driver_id from session or database
    $driver_id = null;
    
    // Check if session already has driver_id
    if (isset($_SESSION['driver_id']) && $_SESSION['driver_id'] > 0) {
        $driver_id = $_SESSION['driver_id'];
        error_log("Using driver_id from session: " . $driver_id);
    } else {
        // Try to get from drivers table using user_id
        $driver_sql = "SELECT driver_id FROM drivers WHERE user_id = ? LIMIT 1";
        $driver_stmt = $conn->prepare($driver_sql);
        $driver_stmt->bind_param("i", $_SESSION['user_id']);
        $driver_stmt->execute();
        $driver_result = $driver_stmt->get_result();
        
        if ($driver_result->num_rows > 0) {
            $driver_row = $driver_result->fetch_assoc();
            $driver_id = $driver_row['driver_id'];
            $_SESSION['driver_id'] = $driver_id;
            error_log("Found driver_id in drivers table: " . $driver_id);
        } else {
            // Try users table as fallback
            $user_sql = "SELECT driver_id FROM users WHERE user_id = ? AND driver_id IS NOT NULL LIMIT 1";
            $user_stmt = $conn->prepare($user_sql);
            $user_stmt->bind_param("i", $_SESSION['user_id']);
            $user_stmt->execute();
            $user_result = $user_stmt->get_result();
            
            if ($user_result->num_rows > 0) {
                $user_row = $user_result->fetch_assoc();
                $driver_id = $user_row['driver_id'];
                $_SESSION['driver_id'] = $driver_id;
                error_log("Found driver_id in users table: " . $driver_id);
            } else {
                error_log("ERROR: Could not find driver_id for user_id: " . $_SESSION['user_id']);
                
                // Last resort: try to get any driver with this user_id
                $fallback_sql = "SELECT driver_id FROM drivers WHERE user_id = ? OR email = (SELECT email FROM users WHERE user_id = ?) LIMIT 1";
                $fallback_stmt = $conn->prepare($fallback_sql);
                $fallback_stmt->bind_param("ii", $_SESSION['user_id'], $_SESSION['user_id']);
                $fallback_stmt->execute();
                $fallback_result = $fallback_stmt->get_result();
                
                if ($fallback_result->num_rows > 0) {
                    $fallback_row = $fallback_result->fetch_assoc();
                    $driver_id = $fallback_row['driver_id'];
                    $_SESSION['driver_id'] = $driver_id;
                    error_log("Found driver_id via fallback: " . $driver_id);
                } else {
                    http_response_code(403);
                    echo json_encode(['success' => false, 'message' => 'User is not a driver']);
                    exit;
                }
            }
        }
    }
    
    // Get current active trip for this driver (if any)
    $trip_id = null;
    $trip_sql = "SELECT trip_id FROM trip_tickets 
                 WHERE driver_id = ? AND trip_status IN ('in-progress', 'planned') 
                 ORDER BY trip_date DESC, created_at DESC LIMIT 1";
    $trip_stmt = $conn->prepare($trip_sql);
    $trip_stmt->bind_param("i", $driver_id);
    $trip_stmt->execute();
    $trip_result = $trip_stmt->get_result();
    
    if ($trip_result->num_rows > 0) {
        $trip_row = $trip_result->fetch_assoc();
        $trip_id = $trip_row['trip_id'];
        error_log("Found active trip for driver: " . $trip_id);
    } else {
        error_log("No active trip found for driver, tracking without trip");
    }
    $trip_stmt->close();
    
    // Check if driver_tracking table exists and has the right structure
    $table_check = $conn->query("SHOW TABLES LIKE 'driver_tracking'");
    if ($table_check->num_rows === 0) {
        // Create the table if it doesn't exist
        $create_table = "CREATE TABLE IF NOT EXISTS driver_tracking (
            tracking_id INT AUTO_INCREMENT PRIMARY KEY,
            driver_id INT NOT NULL,
            trip_id INT NULL,
            latitude DECIMAL(10, 8) NOT NULL,
            longitude DECIMAL(11, 8) NOT NULL,
            location_timestamp DATETIME NOT NULL,
            speed_kmh DECIMAL(5, 2) DEFAULT 0,
            accuracy_meters DECIMAL(5, 2) DEFAULT 0,
            heading_degrees DECIMAL(5, 2) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_driver_id (driver_id),
            INDEX idx_timestamp (location_timestamp),
            FOREIGN KEY (driver_id) REFERENCES drivers(driver_id) ON DELETE CASCADE,
            FOREIGN KEY (trip_id) REFERENCES trip_tickets(trip_id) ON DELETE SET NULL
        )";
        
        if ($conn->query($create_table)) {
            error_log("Created driver_tracking table");
        } else {
            error_log("Failed to create table: " . $conn->error);
        }
    }
    
    // Insert location data
    $insert_sql = "INSERT INTO driver_tracking 
                   (driver_id, trip_id, latitude, longitude, location_timestamp, speed_kmh, accuracy_meters, heading_degrees) 
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
    
    $insert_stmt = $conn->prepare($insert_sql);
    
    if (!$insert_stmt) {
        // If columns don't exist, use simpler insert
        $insert_sql = "INSERT INTO driver_tracking 
                       (driver_id, trip_id, latitude, longitude, location_timestamp, speed_kmh) 
                       VALUES (?, ?, ?, ?, ?, ?)";
        $insert_stmt = $conn->prepare($insert_sql);
        $insert_stmt->bind_param("iiddds", $driver_id, $trip_id, $latitude, $longitude, $current_time, $speed_kmh);
    } else {
        $insert_stmt->bind_param("iiddsddd", $driver_id, $trip_id, $latitude, $longitude, $current_time, $speed_kmh, $accuracy, $heading);
    }
    
    if ($insert_stmt->execute()) {
        error_log("Location saved successfully for driver_id: " . $driver_id);
        
        // Also update driver's last known location in drivers table if that column exists
        $update_driver = "UPDATE drivers SET last_latitude = ?, last_longitude = ?, last_location_update = ? WHERE driver_id = ?";
        $update_stmt = $conn->prepare($update_driver);
        if ($update_stmt) {
            $update_stmt->bind_param("ddsi", $latitude, $longitude, $current_time, $driver_id);
            $update_stmt->execute();
            $update_stmt->close();
        }
        
        echo json_encode([
            'success' => true, 
            'message' => 'Location updated successfully',
            'data' => [
                'driver_id' => $driver_id,
                'latitude' => $latitude,
                'longitude' => $longitude,
                'trip_id' => $trip_id,
                'timestamp' => $current_time
            ]
        ]);
    } else {
        error_log("Failed to insert location: " . $insert_stmt->error);
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to update location: ' . $insert_stmt->error]);
    }
    
    $insert_stmt->close();
    exit;
}

// GET request - get driver's last known location
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $driver_id = isset($_GET['driver_id']) ? intval($_GET['driver_id']) : null;
    
    if (!$driver_id && isset($_SESSION['driver_id'])) {
        $driver_id = $_SESSION['driver_id'];
    }
    
    if (!$driver_id) {
        // Try to get from session user_id
        $driver_sql = "SELECT driver_id FROM drivers WHERE user_id = ? LIMIT 1";
        $driver_stmt = $conn->prepare($driver_sql);
        $driver_stmt->bind_param("i", $_SESSION['user_id']);
        $driver_stmt->execute();
        $driver_result = $driver_stmt->get_result();
        
        if ($driver_result->num_rows > 0) {
            $driver_row = $driver_result->fetch_assoc();
            $driver_id = $driver_row['driver_id'];
        } else {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Driver not found']);
            exit;
        }
    }
    
    $location_sql = "SELECT latitude, longitude, location_timestamp, speed_kmh, accuracy_meters 
                     FROM driver_tracking 
                     WHERE driver_id = ? 
                     ORDER BY location_timestamp DESC 
                     LIMIT 1";
    $location_stmt = $conn->prepare($location_sql);
    $location_stmt->bind_param("i", $driver_id);
    $location_stmt->execute();
    $location_result = $location_stmt->get_result();
    
    if ($location_row = $location_result->fetch_assoc()) {
        echo json_encode([
            'success' => true,
            'data' => $location_row
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'No location data found'
        ]);
    }
    $location_stmt->close();
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'message' => 'Method not allowed']);
?>