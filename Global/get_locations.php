<?php
/**
 * Get all active driver locations
 * Returns only drivers who have sent location in last 30 seconds
 */

header('Content-Type: application/json');

require_once '../config/database.php';
session_start();

// Check if user is logged in (optional - remove if you want public access)
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// Get all active driver locations (last 30 seconds)
$sql = "SELECT 
            dl.driver_id,
            dl.driver_name,
            dl.latitude,
            dl.longitude,
            dl.speed,
            dl.heading,
            dl.last_update,
            d.vehicle_plate_number,
            d.vehicle_type,
            tt.trip_id,
            tt.trip_number,
            TIMESTAMPDIFF(SECOND, dl.last_update, NOW()) as seconds_ago
        FROM driver_locations dl
        JOIN drivers d ON dl.driver_id = d.driver_id
        LEFT JOIN trip_tickets tt ON d.driver_id = tt.driver_id 
            AND tt.trip_status = 'in-progress'
        WHERE dl.is_active = 1
            AND dl.last_update > DATE_SUB(NOW(), INTERVAL 30 SECOND)
        ORDER BY dl.driver_name";

$result = $conn->query($sql);

if (!$result) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $conn->error]);
    exit;
}

$locations = [];
$online_count = 0;

while ($row = $result->fetch_assoc()) {
    $online_count++;
    $locations[] = [
        'id' => intval($row['driver_id']),
        'name' => $row['driver_name'],
        'lat' => floatval($row['latitude']),
        'lng' => floatval($row['longitude']),
        'vehicle' => $row['vehicle_plate_number'] ?: $row['vehicle_type'] ?: 'Unknown',
        'trip' => $row['trip_number'] ?: null,
        'trip_id' => $row['trip_id'] ?: null,
        'speed' => round(floatval($row['speed']) * 3.6), // Convert m/s to km/h
        'heading' => floatval($row['heading']),
        'last_seen' => intval($row['seconds_ago']),
        'last_update' => $row['last_update']
    ];
}

// Also get total drivers count for stats
$total_sql = "SELECT COUNT(*) as total FROM drivers WHERE status = 'active'";
$total_result = $conn->query($total_sql);
$total_row = $total_result->fetch_assoc();

echo json_encode([
    'success' => true,
    'locations' => $locations,
    'stats' => [
        'online' => $online_count,
        'total' => intval($total_row['total']),
        'offline' => intval($total_row['total']) - $online_count
    ],
    'timestamp' => time()
]);

$conn->close();
?>