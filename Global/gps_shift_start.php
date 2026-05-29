<?php
/**
 * Driver GPS Shift Start/Stop API
 * Enhanced version with detailed error logging and validation
 */

// Disable error display but log them
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/gps_shift_errors.log');

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Allow POST only
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit();
}

try {
    require_once("../config/database.php");
    session_start();

    // Check if user is logged in
    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'error' => 'Unauthorized - Please login first',
            'timestamp' => date('Y-m-d H:i:s')
        ]);
        exit();
    }

    // Check if database connection is working
    if (!$conn || $conn->connect_error) {
        echo json_encode([
            'success' => false,
            'error' => 'Database connection failed: ' . ($conn ? $conn->connect_error : 'No connection object'),
            'timestamp' => date('Y-m-d H:i:s')
        ]);
        exit();
    }

    // Get input from JSON body
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    // If no JSON, try POST
    if (!$data || !is_array($data)) {
        $data = $_POST;
    }

    // Validate required fields
    if (!$data || !isset($data['action'])) {
        echo json_encode([
            'success' => false,
            'error' => 'Missing required field: action',
            'timestamp' => date('Y-m-d H:i:s')
        ]);
        exit();
    }

    $action = trim($data['action']);
    $driver_id = isset($data['driver_id']) ? intval($data['driver_id']) : 0;
    $driver_name = isset($data['driver_name']) ? trim($data['driver_name']) : '';
    $trip_id = isset($data['trip_id']) ? intval($data['trip_id']) : null;
    $latitude = isset($data['latitude']) ? floatval($data['latitude']) : null;
    $longitude = isset($data['longitude']) ? floatval($data['longitude']) : null;
    $speed = isset($data['speed']) ? floatval($data['speed']) : 0;
    $accuracy = isset($data['accuracy']) ? floatval($data['accuracy']) : 0;
    $heading = isset($data['heading']) ? floatval($data['heading']) : 0;
    $force = isset($data['force']) ? boolval($data['force']) : false;

    // Log incoming data for debugging
    error_log("GPS action: $action, driver_id: $driver_id, lat: $latitude, lng: $longitude");

    // If driver_id not provided, try to get from session
    if ($driver_id == 0 && isset($_SESSION['driver_id'])) {
        $driver_id = $_SESSION['driver_id'];
        error_log("Using driver_id from session: $driver_id");
    }

    // If still no driver_id, try to get from users table
    if ($driver_id == 0) {
        $user_id = $_SESSION['user_id'];
        $driver_query = "SELECT driver_id FROM drivers WHERE user_id = ? LIMIT 1";
        $stmt = $conn->prepare($driver_query);
        if ($stmt) {
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc()) {
                $driver_id = $row['driver_id'];
                $_SESSION['driver_id'] = $driver_id;
                error_log("Found driver_id from users table: $driver_id");
            }
            $stmt->close();
        }
    }

    // Validate driver_id
    if ($driver_id == 0) {
        echo json_encode([
            'success' => false,
            'error' => 'Invalid driver ID (0). Cannot proceed.',
            'timestamp' => date('Y-m-d H:i:s')
        ]);
        exit();
    }

    // Check if driver exists in drivers table
    $check_driver = "SELECT driver_id, driver_name FROM drivers WHERE driver_id = ?";
    $check_stmt = $conn->prepare($check_driver);
    $check_stmt->bind_param("i", $driver_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    if ($check_result->num_rows === 0) {
        echo json_encode([
            'success' => false,
            'error' => "Driver ID $driver_id not found in drivers table",
            'timestamp' => date('Y-m-d H:i:s')
        ]);
        $check_stmt->close();
        exit();
    }
    $driver_row = $check_result->fetch_assoc();
    $driver_name = $driver_row['driver_name']; // override with actual name
    $check_stmt->close();

    // Create tables if they don't exist
    $conn->query("CREATE TABLE IF NOT EXISTS driver_sessions (
        session_id INT AUTO_INCREMENT PRIMARY KEY,
        driver_id INT NOT NULL,
        session_token VARCHAR(255),
        shift_start DATETIME,
        shift_end DATETIME DEFAULT NULL,
        last_heartbeat DATETIME,
        current_latitude DECIMAL(10,8),
        current_longitude DECIMAL(11,8),
        is_online TINYINT DEFAULT 0,
        gps_active TINYINT DEFAULT 0,
        total_distance DECIMAL(10,2) DEFAULT 0.00,
        trip_id INT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_driver_id (driver_id),
        INDEX idx_online (is_online),
        FOREIGN KEY (driver_id) REFERENCES drivers(driver_id) ON DELETE CASCADE,
        FOREIGN KEY (trip_id) REFERENCES trip_tickets(trip_id) ON DELETE SET NULL
    )");

    $conn->query("CREATE TABLE IF NOT EXISTS driver_tracking (
        tracking_id INT AUTO_INCREMENT PRIMARY KEY,
        driver_id INT NOT NULL,
        trip_id INT DEFAULT NULL,
        latitude DECIMAL(10,8) NOT NULL,
        longitude DECIMAL(11,8) NOT NULL,
        speed_kmh DECIMAL(5,2) DEFAULT 0,
        location_timestamp DATETIME NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_driver_id (driver_id),
        INDEX idx_driver_time (driver_id, location_timestamp),
        FOREIGN KEY (driver_id) REFERENCES drivers(driver_id) ON DELETE CASCADE,
        FOREIGN KEY (trip_id) REFERENCES trip_tickets(trip_id) ON DELETE SET NULL
    )");

    // Start transaction
    $conn->begin_transaction();

    try {
        if ($action === 'start_shift') {
            // Check if driver already has an active session
            $checkActive = "SELECT session_id FROM driver_sessions WHERE driver_id = ? AND shift_end IS NULL";
            $stmt = $conn->prepare($checkActive);
            $stmt->bind_param("i", $driver_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                if ($force) {
                    // Force mode: End the existing shift first
                    $endShift = "UPDATE driver_sessions 
                                SET shift_end = NOW(), 
                                    gps_active = 0,
                                    is_online = 0 
                                WHERE driver_id = ? AND shift_end IS NULL";
                    $endStmt = $conn->prepare($endShift);
                    $endStmt->bind_param("i", $driver_id);
                    $endStmt->execute();
                    $endStmt->close();
                    error_log("Force ended previous shift for driver $driver_id");
                } else {
                    throw new Exception('Driver already has an active shift. Use force=true to override.');
                }
            }
            $stmt->close();

            // Get current active trip if any
            if (!$trip_id) {
                $trip_query = "SELECT trip_id FROM trip_tickets 
                               WHERE driver_id = ? AND trip_status IN ('in-progress', 'planned')
                               ORDER BY trip_date DESC LIMIT 1";
                $stmt = $conn->prepare($trip_query);
                if ($stmt) {
                    $stmt->bind_param("i", $driver_id);
                    $stmt->execute();
                    $trip_result = $stmt->get_result();
                    if ($trip_row = $trip_result->fetch_assoc()) {
                        $trip_id = $trip_row['trip_id'];
                    }
                    $stmt->close();
                }
            }

            // Start new shift
            $session_token = session_id() . '_' . time();
            $startShift = "INSERT INTO driver_sessions 
                          (driver_id, session_token, shift_start, gps_active, is_online, trip_id) 
                          VALUES (?, ?, NOW(), 1, 1, ?)";
            $stmt = $conn->prepare($startShift);
            $stmt->bind_param("isi", $driver_id, $session_token, $trip_id);
            $stmt->execute();
            
            $session_id = $conn->insert_id;
            $stmt->close();

            // Update drivers table status
            $updateDriver = "UPDATE drivers SET status = 'active' WHERE driver_id = ?";
            $stmt = $conn->prepare($updateDriver);
            $stmt->bind_param("i", $driver_id);
            $stmt->execute();
            $stmt->close();

            // Insert initial location if provided
            if ($latitude && $longitude) {
                $loc_sql = "INSERT INTO driver_tracking 
                           (driver_id, trip_id, latitude, longitude, speed_kmh, location_timestamp) 
                           VALUES (?, ?, ?, ?, ?, NOW())";
                $stmt = $conn->prepare($loc_sql);
                $stmt->bind_param("iiddd", $driver_id, $trip_id, $latitude, $longitude, $speed);
                $stmt->execute();
                $stmt->close();
                error_log("Inserted initial location for driver $driver_id");
            }

            $conn->commit();

            echo json_encode([
                'success' => true,
                'message' => 'Shift started successfully' . ($force ? ' (previous shift ended)' : ''),
                'session_id' => $session_id,
                'driver_id' => $driver_id,
                'driver_name' => $driver_name,
                'trip_id' => $trip_id,
                'timestamp' => date('Y-m-d H:i:s'),
                'gps_status' => 'active'
            ]);

        } elseif ($action === 'end_shift') {
            // End active shift
            $endShift = "UPDATE driver_sessions 
                        SET shift_end = NOW(), 
                            gps_active = 0,
                            is_online = 0 
                        WHERE driver_id = ? AND shift_end IS NULL";
            $stmt = $conn->prepare($endShift);
            $stmt->bind_param("i", $driver_id);
            $stmt->execute();
            
            if ($conn->affected_rows === 0) {
                // Check if there's any record at all
                $checkAny = "SELECT session_id FROM driver_sessions WHERE driver_id = ? LIMIT 1";
                $checkStmt = $conn->prepare($checkAny);
                $checkStmt->bind_param("i", $driver_id);
                $checkStmt->execute();
                $checkResult = $checkStmt->get_result();
                
                if ($checkResult->num_rows > 0) {
                    throw new Exception('Driver has shifts but none are active');
                } else {
                    throw new Exception('No shift found for driver');
                }
                $checkStmt->close();
            }
            $stmt->close();

            // Update driver status
            $updateDriver = "UPDATE drivers SET status = 'inactive' WHERE driver_id = ?";
            $stmt = $conn->prepare($updateDriver);
            $stmt->bind_param("i", $driver_id);
            $stmt->execute();
            $stmt->close();

            $conn->commit();

            echo json_encode([
                'success' => true,
                'message' => 'Shift ended successfully',
                'driver_id' => $driver_id,
                'timestamp' => date('Y-m-d H:i:s'),
                'gps_status' => 'inactive'
            ]);

        } elseif ($action === 'update_location') {
            if (!$latitude || !$longitude) {
                throw new Exception('Missing coordinates');
            }

            // Update driver_sessions (current status)
            $updateSession = "UPDATE driver_sessions 
                             SET last_heartbeat = NOW(), 
                                 is_online = 1,
                                 gps_active = 1,
                                 current_latitude = ?,
                                 current_longitude = ?
                             WHERE driver_id = ? AND shift_end IS NULL";
            $stmt = $conn->prepare($updateSession);
            $stmt->bind_param("ddi", $latitude, $longitude, $driver_id);
            $stmt->execute();
            $stmt->close();

            // Get trip_id from active session if available
            $trip_query = "SELECT trip_id FROM driver_sessions WHERE driver_id = ? AND shift_end IS NULL LIMIT 1";
            $trip_stmt = $conn->prepare($trip_query);
            if ($trip_stmt) {
                $trip_stmt->bind_param("i", $driver_id);
                $trip_stmt->execute();
                $trip_result = $trip_stmt->get_result();
                if ($trip_row = $trip_result->fetch_assoc()) {
                    $trip_id = $trip_row['trip_id'];
                }
                $trip_stmt->close();
            }

            // Insert location into driver_tracking (historical)
            $insertLoc = "INSERT INTO driver_tracking 
                         (driver_id, trip_id, latitude, longitude, speed_kmh, location_timestamp) 
                         VALUES (?, ?, ?, ?, ?, NOW())";
            
            $stmt = $conn->prepare($insertLoc);
            $stmt->bind_param("iiddd", $driver_id, $trip_id, $latitude, $longitude, $speed);
            
            if (!$stmt->execute()) {
                throw new Exception('Failed to insert location: ' . $stmt->error);
            }
            $stmt->close();

            // Update drivers table (optional)
            $updateDriver = "UPDATE drivers 
                            SET last_latitude = ?, 
                                last_longitude = ?, 
                                last_location_update = NOW(),
                                status = 'active'
                            WHERE driver_id = ?";
            $stmt = $conn->prepare($updateDriver);
            $stmt->bind_param("ddi", $latitude, $longitude, $driver_id);
            $stmt->execute();
            $stmt->close();

            $conn->commit();

            echo json_encode([
                'success' => true,
                'message' => 'Location updated',
                'driver_id' => $driver_id,
                'timestamp' => date('Y-m-d H:i:s')
            ]);

        } elseif ($action === 'get_shift_status') {
            $getStatus = "SELECT ds.*, d.driver_name, d.vehicle_plate_number, tt.trip_number 
                         FROM driver_sessions ds
                         JOIN drivers d ON ds.driver_id = d.driver_id
                         LEFT JOIN trip_tickets tt ON ds.trip_id = tt.trip_id
                         WHERE ds.driver_id = ? AND ds.shift_end IS NULL 
                         ORDER BY ds.shift_start DESC LIMIT 1";
            $stmt = $conn->prepare($getStatus);
            $stmt->bind_param("i", $driver_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $session = $result->fetch_assoc();
                $stmt->close();
                
                // Get latest location from driver_tracking
                $loc_query = "SELECT latitude, longitude, location_timestamp as last_update, speed_kmh as speed 
                             FROM driver_tracking 
                             WHERE driver_id = ? 
                             ORDER BY location_timestamp DESC LIMIT 1";
                $loc_stmt = $conn->prepare($loc_query);
                $location = null;
                if ($loc_stmt) {
                    $loc_stmt->bind_param("i", $driver_id);
                    $loc_stmt->execute();
                    $loc_result = $loc_stmt->get_result();
                    if ($loc_row = $loc_result->fetch_assoc()) {
                        $location = [
                            'latitude' => floatval($loc_row['latitude']),
                            'longitude' => floatval($loc_row['longitude']),
                            'speed' => floatval($loc_row['speed']),
                            'last_update' => $loc_row['last_update']
                        ];
                    }
                    $loc_stmt->close();
                }
                
                echo json_encode([
                    'success' => true,
                    'has_active_shift' => true,
                    'session_id' => $session['session_id'],
                    'driver_id' => $session['driver_id'],
                    'driver_name' => $session['driver_name'],
                    'vehicle' => $session['vehicle_plate_number'],
                    'shift_start' => $session['shift_start'],
                    'trip_id' => $session['trip_id'],
                    'trip_number' => $session['trip_number'],
                    'gps_active' => (bool)$session['gps_active'],
                    'is_online' => (bool)$session['is_online'],
                    'total_distance' => $session['total_distance'],
                    'location' => $location,
                    'timestamp' => date('Y-m-d H:i:s')
                ]);
            } else {
                $stmt->close();
                
                echo json_encode([
                    'success' => true,
                    'has_active_shift' => false,
                    'message' => 'No active shift found',
                    'timestamp' => date('Y-m-d H:i:s')
                ]);
            }

        } elseif ($action === 'heartbeat') {
            $heartbeat = "UPDATE driver_sessions 
                         SET last_heartbeat = NOW(), 
                             is_online = 1
                         WHERE driver_id = ? AND shift_end IS NULL";
            $stmt = $conn->prepare($heartbeat);
            $stmt->bind_param("i", $driver_id);
            $stmt->execute();
            $stmt->close();

            $updateDriver = "UPDATE drivers SET status = 'active' WHERE driver_id = ?";
            $stmt = $conn->prepare($updateDriver);
            $stmt->bind_param("i", $driver_id);
            $stmt->execute();
            $stmt->close();

            $conn->commit();

            echo json_encode([
                'success' => true,
                'message' => 'Heartbeat received',
                'driver_id' => $driver_id,
                'timestamp' => date('Y-m-d H:i:s')
            ]);

        } elseif ($action === 'force_end_shift') {
            $endShift = "UPDATE driver_sessions 
                        SET shift_end = NOW(), 
                            gps_active = 0,
                            is_online = 0 
                        WHERE driver_id = ? AND shift_end IS NULL";
            $stmt = $conn->prepare($endShift);
            $stmt->bind_param("i", $driver_id);
            $stmt->execute();
            
            $affected = $conn->affected_rows;
            $stmt->close();

            $updateDriver = "UPDATE drivers SET status = 'inactive' WHERE driver_id = ?";
            $stmt = $conn->prepare($updateDriver);
            $stmt->bind_param("i", $driver_id);
            $stmt->execute();
            $stmt->close();

            $conn->commit();

            echo json_encode([
                'success' => true,
                'message' => $affected > 0 ? 'Shift force ended' : 'No active shift found',
                'driver_id' => $driver_id,
                'affected' => $affected,
                'timestamp' => date('Y-m-d H:i:s')
            ]);

        } else {
            throw new Exception('Invalid action. Supported actions: start_shift, end_shift, update_location, get_shift_status, heartbeat, force_end_shift');
        }

    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }

} catch (Exception $e) {
    error_log("GPS shift control error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'timestamp' => date('Y-m-d H:i:s')
    ]);
} finally {
    if (isset($conn)) {
        $conn->close();
    }
}
?>