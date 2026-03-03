<?php
/**
 * Get Real-time Driver Status
 * Returns online/offline status based on recent activity
 */

require_once '../config/database.php';
session_start();

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$driver_id = isset($_GET['driver_id']) ? intval($_GET['driver_id']) : null;

if (!$driver_id) {
    // Get current user's driver_id
    $user_id = $_SESSION['user_id'];
    $sql = "SELECT driver_id FROM drivers WHERE user_id = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $driver_id = $row['driver_id'];
    } else {
        echo json_encode(['success' => false, 'message' => 'Driver not found']);
        exit;
    }
    $stmt->close();
}

// Get driver info and recent activity
$sql = "SELECT 
            d.driver_id,
            d.driver_name,
            d.status,
            d.last_location_update,
            dt.latitude,
            dt.longitude,
            dt.location_timestamp,
            dt.speed_kmh,
            ds.is_online,
            ds.last_heartbeat,
            ds.current_latitude,
            ds.current_longitude
        FROM drivers d
        LEFT JOIN driver_tracking dt ON d.driver_id = dt.driver_id 
            AND dt.location_timestamp = (
                SELECT MAX(location_timestamp) FROM driver_tracking WHERE driver_id = d.driver_id
            )
        LEFT JOIN driver_sessions ds ON d.driver_id = ds.driver_id
        WHERE d.driver_id = ?
        LIMIT 1";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $driver_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'Driver not found']);
    exit;
}

$row = $result->fetch_assoc();
$stmt->close();

// Determine online status
$is_online = false;
$last_activity = null;
$online_reason = 'No activity';

// Check heartbeat (most recent indicator)
if (!empty($row['last_heartbeat'])) {
    $heartbeat_time = strtotime($row['last_heartbeat']);
    $now = time();
    $minutes_ago = floor(($now - $heartbeat_time) / 60);
    
    // Online if heartbeat within last 2 minutes
    if ($minutes_ago <= 2) {
        $is_online = true;
        $last_activity = $row['last_heartbeat'];
        $online_reason = 'Heartbeat active';
    } else {
        $last_activity = $row['last_heartbeat'];
        $online_reason = 'Heartbeat ' . $minutes_ago . 'min ago';
    }
}

// Check recent location update
if (!empty($row['location_timestamp']) && !$is_online) {
    $location_time = strtotime($row['location_timestamp']);
    $now = time();
    $minutes_ago = floor(($now - $location_time) / 60);
    
    // Online if location update within last 3 minutes
    if ($minutes_ago <= 3) {
        $is_online = true;
        $last_activity = $row['location_timestamp'];
        $online_reason = 'Location update';
    } else {
        if (empty($last_activity)) {
            $last_activity = $row['location_timestamp'];
            $online_reason = 'Location ' . $minutes_ago . 'min ago';
        }
    }
}

// Check driver status
if ($row['status'] !== 'active') {
    $is_online = false;
    $online_reason = 'Driver inactive';
}

// Get current location
$latitude = $row['latitude'] ?? $row['current_latitude'];
$longitude = $row['longitude'] ?? $row['current_longitude'];

echo json_encode([
    'success' => true,
    'driver' => [
        'id' => $row['driver_id'],
        'name' => $row['driver_name'],
        'status' => $row['status'],
        'is_online' => $is_online,
        'online_reason' => $online_reason,
        'last_activity' => $last_activity,
        'latitude' => $latitude ? floatval($latitude) : null,
        'longitude' => $longitude ? floatval($longitude) : null,
        'speed_kmh' => $row['speed_kmh'] ? floatval($row['speed_kmh']) : 0,
        'current_location' => $latitude && $longitude 
            ? number_format($latitude, 6) . ', ' . number_format($longitude, 6)
            : 'No location'
    ]
]);
?>
