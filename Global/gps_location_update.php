<?php
/**
 * GPS Location Update API
 * Receives live location updates from driver devices every 10 seconds
 * Uses HTML5 Geolocation API (no external APIs required)
 */

error_reporting(0);
ini_set('display_errors', 0);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Log all requests for debugging
$logFile = '../logs/gps_updates.log';
if (!is_dir('../logs')) {
    mkdir('../logs', 0777, true);
}
error_log('[GPS] Request received: ' . date('Y-m-d H:i:s'), 3, $logFile);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    error_log('[GPS] Invalid method: ' . $_SERVER['REQUEST_METHOD'], 3, $logFile);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit();
}

try {
    require("../config/database.php");

    if (!$conn || $conn->connect_error) {
        error_log('[GPS] Database connection failed: ' . ($conn ? $conn->connect_error : 'No connection'), 3, $logFile);
        echo json_encode([
            'success' => false,
            'error' => 'Database connection failed',
            'timestamp' => date('Y-m-d H:i:s')
        ]);
        exit();
    }

    $input = file_get_contents('php://input');
    error_log('[GPS] Raw input: ' . substr($input, 0, 200), 3, $logFile);
    $data = json_decode($input, true);

    if (!$data || !isset($data['driver_id']) || !isset($data['latitude']) || !isset($data['longitude'])) {
        error_log('[GPS] Missing fields: ' . json_encode($data), 3, $logFile);
        echo json_encode([
            'success' => false,
            'error' => 'Missing required fields: driver_id, latitude, longitude',
            'timestamp' => date('Y-m-d H:i:s')
        ]);
        exit();
    }

    $driver_id = trim($data['driver_id']);
    $driver_name = isset($data['driver_name']) ? trim($data['driver_name']) : 'Driver #' . $driver_id;
    $latitude = floatval($data['latitude']);
    $longitude = floatval($data['longitude']);
    $accuracy = isset($data['accuracy']) ? floatval($data['accuracy']) : null;
    $speed = isset($data['speed']) ? floatval($data['speed']) : null;
    $heading = isset($data['heading']) ? floatval($data['heading']) : null;
    $altitude = isset($data['altitude']) ? floatval($data['altitude']) : null;

    // Ensure driver_locations table exists
    $conn->query("CREATE TABLE IF NOT EXISTS driver_locations (
        id INT(11) NOT NULL AUTO_INCREMENT,
        driver_id VARCHAR(50) NOT NULL,
        driver_name VARCHAR(255) NOT NULL,
        latitude DECIMAL(10,8) NOT NULL DEFAULT 0,
        longitude DECIMAL(11,8) NOT NULL DEFAULT 0,
        last_updated TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        is_active TINYINT(1) DEFAULT 1,
        accuracy DECIMAL(8,2) DEFAULT NULL,
        speed DECIMAL(8,2) DEFAULT NULL,
        heading DECIMAL(8,2) DEFAULT NULL,
        altitude DECIMAL(8,2) DEFAULT NULL,
        PRIMARY KEY (id),
        KEY idx_driver_id (driver_id),
        KEY idx_is_active (is_active),
        KEY idx_last_updated (last_updated)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Ensure driver_tracks table exists for history
    $conn->query("CREATE TABLE IF NOT EXISTS driver_tracks (
        id INT(11) NOT NULL AUTO_INCREMENT,
        driver_id VARCHAR(50) NOT NULL,
        latitude DECIMAL(10,8) NOT NULL,
        longitude DECIMAL(11,8) NOT NULL,
        accuracy DECIMAL(8,2) DEFAULT NULL,
        speed DECIMAL(8,2) DEFAULT NULL,
        heading DECIMAL(8,2) DEFAULT NULL,
        altitude DECIMAL(8,2) DEFAULT NULL,
        timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_driver_id (driver_id),
        KEY idx_timestamp (timestamp)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->begin_transaction();

    try {
        // Check if driver exists in driver_locations
        $checkSql = "SELECT id FROM driver_locations WHERE driver_id = ? LIMIT 1";
        $checkStmt = $conn->prepare($checkSql);
        $checkStmt->bind_param("s", $driver_id);
        $checkStmt->execute();
        $checkResult = $checkStmt->get_result();
        $checkStmt->close();

        if ($checkResult->num_rows > 0) {
            // Update existing driver location
            $updateSql = "UPDATE driver_locations 
                         SET driver_name = ?, latitude = ?, longitude = ?, accuracy = ?, 
                             speed = ?, heading = ?, altitude = ?, is_active = 1, last_updated = NOW() 
                         WHERE driver_id = ?";
            $stmt = $conn->prepare($updateSql);
            $stmt->bind_param("sdddddds", $driver_name, $latitude, $longitude, $accuracy, $speed, $heading, $altitude, $driver_id);
            $stmt->execute();
            $stmt->close();
        } else {
            // Insert new driver location
            $insertSql = "INSERT INTO driver_locations (driver_id, driver_name, latitude, longitude, accuracy, speed, heading, altitude, is_active, last_updated) 
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())";
            $stmt = $conn->prepare($insertSql);
            $stmt->bind_param("ssddddddd", $driver_id, $driver_name, $latitude, $longitude, $accuracy, $speed, $heading, $altitude);
            $stmt->execute();
            $stmt->close();
        }

        // Insert into driver_tracks for history
        $trackSql = "INSERT INTO driver_tracks (driver_id, latitude, longitude, accuracy, speed, heading, altitude, timestamp) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
        $trackStmt = $conn->prepare($trackSql);
        $trackStmt->bind_param("sdddddd", $driver_id, $latitude, $longitude, $accuracy, $speed, $heading, $altitude);
        $trackStmt->execute();
        $trackStmt->close();

        $conn->commit();

        error_log('[GPS] Location saved for driver_id: ' . $driver_id . ' lat: ' . $latitude . ' lon: ' . $longitude, 3, $logFile);
        
        echo json_encode([
            'success' => true,
            'message' => 'Location updated successfully',
            'driver_id' => $driver_id,
            'driver_name' => $driver_name,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'timestamp' => date('Y-m-d H:i:s')
        ]);

    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }

} catch (Exception $e) {
    error_log('[GPS] ERROR: ' . $e->getMessage(), 3, $logFile);
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
