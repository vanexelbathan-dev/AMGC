<?php
/**
 * GPS Shutdown API
 * Marks a driver as inactive when they log out
 * This removes them from the active drivers list in admin dashboard
 */

// Disable error display to prevent HTML error pages
error_reporting(0);
ini_set('display_errors', 0);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit();
}

try {
    require("connection.php");

    // Check if database connection is working
    if (!$mysqli || $mysqli->connect_error) {
        echo json_encode([
            'success' => false,
            'error' => 'Database connection failed: ' . ($mysqli ? $mysqli->connect_error : 'No connection object'),
            'timestamp' => date('Y-m-d H:i:s')
        ]);
        exit();
    }

    // Get JSON input
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);

    // Validate required fields
    if (!$data || !isset($data['driver_id'])) {
        echo json_encode([
            'success' => false,
            'error' => 'Missing required field: driver_id',
            'timestamp' => date('Y-m-d H:i:s')
        ]);
        exit();
    }

    // Sanitize input data
    $driver_id = trim($data['driver_id']);
    $driver_name = isset($data['driver_name']) ? trim($data['driver_name']) : 'Driver #' . $driver_id;

    // Check if GPS tracking tables exist
    $checkTables = $mysqli->query("SHOW TABLES LIKE 'driver_locations'");
    if ($checkTables->num_rows == 0) {
        echo json_encode([
            'success' => true,
            'message' => 'GPS shutdown completed (GPS tables not set up)',
            'driver_id' => $driver_id,
            'timestamp' => date('Y-m-d H:i:s')
        ]);
        exit();
    }

    // Start transaction
    $mysqli->begin_transaction();

    try {
        // Mark driver as inactive in driver_locations table
        $updateDriverStatus = "
        UPDATE driver_locations 
        SET is_active = FALSE, last_updated = NOW()
        WHERE driver_id = ?
        ";
        
        $stmt = $mysqli->prepare($updateDriverStatus);
        $stmt->bind_param("s", $driver_id);
        
        if (!$stmt->execute()) {
            throw new Exception('Failed to update driver status: ' . $stmt->error);
        }
        $stmt->close();

        // If no rows were affected, insert a record to mark as inactive
        if ($mysqli->affected_rows == 0) {
            $insertInactiveDriver = "
            INSERT INTO driver_locations (driver_id, driver_name, latitude, longitude, is_active, last_updated)
            VALUES (?, ?, 0, 0, FALSE, NOW())
            ";
            
            $stmt2 = $mysqli->prepare($insertInactiveDriver);
            $stmt2->bind_param("ss", $driver_id, $driver_name);
            $stmt2->execute();
            $stmt2->close();
        }

        // Commit transaction
        $mysqli->commit();

        // Return success response
        echo json_encode([
            'success' => true,
            'message' => 'GPS tracking shutdown successfully',
            'driver_id' => $driver_id,
            'driver_name' => $driver_name,
            'timestamp' => date('Y-m-d H:i:s'),
            'status' => 'inactive'
        ]);

    } catch (Exception $e) {
        // Rollback transaction on error
        $mysqli->rollback();
        throw $e;
    }

} catch (Exception $e) {
    // Log error for debugging
    error_log("GPS shutdown error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'timestamp' => date('Y-m-d H:i:s')
    ]);
} finally {
    if (isset($mysqli)) {
        $mysqli->close();
    }
}
?>
