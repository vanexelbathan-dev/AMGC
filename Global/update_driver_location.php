<?php
require_once '../config/database.php';
session_start();

// Enable error reporting for debugging (remove in production)
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized - Please login']);
    exit;
}

// For POST requests - update location or heartbeat
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    
    // If no JSON, try POST data
    if (!$input) {
        $input = $_POST;
    }
    
    // Check if this is a heartbeat (online status update)
    $is_heartbeat = isset($input['is_heartbeat']) ? intval($input['is_heartbeat']) : 0;
    
    if ($is_heartbeat) {
        // Handle heartbeat - just update online status
        $user_id = $_SESSION['user_id'];
        
        // Get driver_id
        $driver_sql = "SELECT driver_id FROM drivers WHERE user_id = ? LIMIT 1";
        $driver_stmt = $conn->prepare($driver_sql);
        if (!$driver_stmt) {
            echo json_encode(['success' => false, 'message' => 'Database error']);
            exit;
        }
        $driver_stmt->bind_param("i", $user_id);
        $driver_stmt->execute();
        $driver_result = $driver_stmt->get_result();
        
        if ($driver_result->num_rows > 0) {
            $driver_row = $driver_result->fetch_assoc();
            $driver_id = $driver_row['driver_id'];
            
            // Update driver status to active
            $current_time = date('Y-m-d H:i:s');
            
            // Update driver_sessions table
            $session_token = session_id();
            $check_sql = "SELECT session_id FROM driver_sessions WHERE driver_id = ? LIMIT 1";
            $check_stmt = $conn->prepare($check_sql);
            if ($check_stmt) {
                $check_stmt->bind_param("i", $driver_id);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                
                if ($check_result->num_rows > 0) {
                    $update = "UPDATE driver_sessions SET last_heartbeat = ?, is_online = 1 WHERE driver_id = ?";
                    $update_stmt = $conn->prepare($update);
                    if ($update_stmt) {
                        $update_stmt->bind_param("si", $current_time, $driver_id);
                        $update_stmt->execute();
                        $update_stmt->close();
                    }
                } else {
                    $insert = "INSERT INTO driver_sessions (driver_id, session_token, last_heartbeat, is_online) VALUES (?, ?, ?, 1)";
                    $insert_stmt = $conn->prepare($insert);
                    if ($insert_stmt) {
                        $insert_stmt->bind_param("iss", $driver_id, $session_token, $current_time);
                        $insert_stmt->execute();
                        $insert_stmt->close();
                    }
                }
                $check_stmt->close();
            }
            
            // Update drivers table
            $update = "UPDATE drivers SET status = 'active', last_location_update = ? WHERE driver_id = ?";
            $stmt = $conn->prepare($update);
            if ($stmt) {
                $stmt->bind_param("si", $current_time, $driver_id);
                $stmt->execute();
                $stmt->close();
            }
            
            echo json_encode([
                'success' => true,
                'message' => 'Online status updated',
                'is_heartbeat' => 1,
                'driver_id' => $driver_id
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Driver not found']);
        }
        $driver_stmt->close();
        exit;
    }
    
    // Validate required fields
    if (!isset($input['latitude']) || !isset($input['longitude'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing latitude or longitude']);
        exit;
    }
    
    $latitude = floatval($input['latitude']);
    $longitude = floatval($input['longitude']);
    $speed_kmh = isset($input['speed']) ? floatval($input['speed']) : 0;
    $accuracy = isset($input['accuracy']) ? floatval($input['accuracy']) : 0;
    $current_time = date('Y-m-d H:i:s');
    
    // Validate latitude and longitude ranges
    if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid coordinates']);
        exit;
    }
    
    // Get driver_id from session or database
    $driver_id = null;
    $user_id = $_SESSION['user_id'];
    
    // Check if session already has driver_id
    if (isset($_SESSION['driver_id']) && $_SESSION['driver_id'] > 0) {
        $driver_id = $_SESSION['driver_id'];
    } else {
        // Try to get from drivers table using user_id
        $driver_sql = "SELECT driver_id FROM drivers WHERE user_id = ? LIMIT 1";
        $driver_stmt = $conn->prepare($driver_sql);
        if ($driver_stmt) {
            $driver_stmt->bind_param("i", $user_id);
            $driver_stmt->execute();
            $driver_result = $driver_stmt->get_result();
            
            if ($driver_result->num_rows > 0) {
                $driver_row = $driver_result->fetch_assoc();
                $driver_id = $driver_row['driver_id'];
                $_SESSION['driver_id'] = $driver_id;
            }
            $driver_stmt->close();
        }
        
        // Try users table as fallback
        if (!$driver_id) {
            $user_sql = "SELECT driver_id FROM users WHERE user_id = ? AND driver_id IS NOT NULL LIMIT 1";
            $user_stmt = $conn->prepare($user_sql);
            if ($user_stmt) {
                $user_stmt->bind_param("i", $user_id);
                $user_stmt->execute();
                $user_result = $user_stmt->get_result();
                if ($user_result->num_rows > 0) {
                    $user_row = $user_result->fetch_assoc();
                    $driver_id = $user_row['driver_id'];
                    $_SESSION['driver_id'] = $driver_id;
                }
                $user_stmt->close();
            }
        }
        
        if (!$driver_id) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'User is not a driver']);
            exit;
        }
    }
    
    // Get current active trip for this driver (if any)
    $trip_id = null;
    $trip_sql = "SELECT trip_id FROM trip_tickets 
                 WHERE driver_id = ? AND trip_status IN ('in-progress', 'planned') 
                 ORDER BY trip_date DESC, created_at DESC LIMIT 1";
    $trip_stmt = $conn->prepare($trip_sql);
    if ($trip_stmt) {
        $trip_stmt->bind_param("i", $driver_id);
        $trip_stmt->execute();
        $trip_result = $trip_stmt->get_result();
        if ($trip_result->num_rows > 0) {
            $trip_row = $trip_result->fetch_assoc();
            $trip_id = $trip_row['trip_id'];
        }
        $trip_stmt->close();
    }
    
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
        $conn->query($create_table);
    }
    
    // Insert location data
    $insert_sql = "INSERT INTO driver_tracking 
                   (driver_id, trip_id, latitude, longitude, location_timestamp, speed_kmh, accuracy_meters) 
                   VALUES (?, ?, ?, ?, ?, ?, ?)";
    
    $insert_stmt = $conn->prepare($insert_sql);
    if (!$insert_stmt) {
        // If columns don't exist, use simpler insert
        $insert_sql = "INSERT INTO driver_tracking 
                       (driver_id, trip_id, latitude, longitude, location_timestamp, speed_kmh) 
                       VALUES (?, ?, ?, ?, ?, ?)";
        $insert_stmt = $conn->prepare($insert_sql);
        if ($insert_stmt) {
            $insert_stmt->bind_param("iiddds", $driver_id, $trip_id, $latitude, $longitude, $current_time, $speed_kmh);
        }
    } else {
        $insert_stmt->bind_param("iiddsdd", $driver_id, $trip_id, $latitude, $longitude, $current_time, $speed_kmh, $accuracy);
    }
    
    if ($insert_stmt && $insert_stmt->execute()) {
        // Update driver_sessions with latest location
        $update_session = "UPDATE driver_sessions SET current_latitude = ?, current_longitude = ?, last_location_update = ?, is_online = 1 WHERE driver_id = ?";
        $update_stmt = $conn->prepare($update_session);
        if ($update_stmt) {
            $update_stmt->bind_param("ddsi", $latitude, $longitude, $current_time, $driver_id);
            $update_stmt->execute();
            $update_stmt->close();
        }
        
        // Also update driver's last known location in drivers table
        $update_driver = "UPDATE drivers SET last_latitude = ?, last_longitude = ?, last_location_update = ?, status = 'active' WHERE driver_id = ?";
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
        $error = $insert_stmt ? $insert_stmt->error : 'Statement preparation failed';
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to update location: ' . $error]);
    }
    
    if ($insert_stmt) $insert_stmt->close();
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
        if ($driver_stmt) {
            $driver_stmt->bind_param("i", $_SESSION['user_id']);
            $driver_stmt->execute();
            $driver_result = $driver_stmt->get_result();
            if ($driver_result->num_rows > 0) {
                $driver_row = $driver_result->fetch_assoc();
                $driver_id = $driver_row['driver_id'];
            }
            $driver_stmt->close();
        }
        
        if (!$driver_id) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Driver not found']);
            exit;
        }
    }
    
    $location_sql = "SELECT latitude, longitude, location_timestamp, speed_kmh
                     FROM driver_tracking 
                     WHERE driver_id = ? 
                     ORDER BY location_timestamp DESC 
                     LIMIT 1";
    $location_stmt = $conn->prepare($location_sql);
    if ($location_stmt) {
        $location_stmt->bind_param("i", $driver_id);
        $location_stmt->execute();
        $location_result = $location_stmt->get_result();
        
        if ($location_row = $location_result->fetch_assoc()) {
            // Calculate time ago
            $last_update = new DateTime($location_row['location_timestamp']);
            $now = new DateTime();
            $interval = $now->diff($last_update);
            $minutes_ago = ($interval->days * 24 * 60) + ($interval->h * 60) + $interval->i;
            
            echo json_encode([
                'success' => true,
                'data' => $location_row,
                'minutes_ago' => $minutes_ago
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'No location data found'
            ]);
        }
        $location_stmt->close();
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error']);
    }
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'message' => 'Method not allowed']);
?>