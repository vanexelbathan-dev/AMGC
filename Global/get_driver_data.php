<?php
/**
 * Backend Data Provider for Real-time Driver Tracking
 * Provides JSON data for admin dashboard map display
 * Returns latest locations and recent tracks for all active drivers
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Only allow GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit();
}

require("../config/database.php");

try {
    // Get optional parameters
    $minutes_back = isset($_GET['minutes']) ? intval($_GET['minutes']) : 30; // Default to 30 minutes
    $driver_id = isset($_GET['driver_id']) ? trim($_GET['driver_id']) : null; // Optional specific driver
    
    // Validate parameters
    if ($minutes_back < 1 || $minutes_back > 1440) { // Max 24 hours
        $minutes_back = 30;
    }
    
    // Build the base query for active drivers with instructor profile pictures
    // Use a subquery to get only the most recent entry for each driver_id
    $driverQuery = "
    SELECT 
        dl.driver_id,
        dl.driver_name,
        dl.latitude,
        dl.longitude,
        dl.accuracy,
        dl.speed,
        dl.heading,
        dl.altitude,
        dl.last_updated,
        dl.is_active,
        di.profile_picture,
        di.full_name as instructor_full_name,
        CASE 
            WHEN dl.last_updated >= DATE_SUB(NOW(), INTERVAL 1 MINUTE) THEN 'LIVE'
            WHEN dl.last_updated >= DATE_SUB(NOW(), INTERVAL 5 MINUTE) THEN 'RECENT'
            WHEN dl.last_updated >= DATE_SUB(NOW(), INTERVAL 30 MINUTE) THEN 'STALE'
            ELSE 'OFFLINE'
        END as status
    FROM driver_locations dl
    INNER JOIN (
        SELECT 
            driver_id,
            MAX(last_updated) as max_updated
        FROM driver_locations 
        WHERE is_active = TRUE
        GROUP BY driver_id
    ) latest ON dl.driver_id = latest.driver_id AND dl.last_updated = latest.max_updated
    LEFT JOIN users u ON dl.driver_name = u.username
    LEFT JOIN driving_instructors di ON u.id = di.user_id
    WHERE dl.is_active = TRUE
    ";
    
    // Add driver filter if specified
    if ($driver_id) {
        $driverQuery .= " AND dl.driver_id = ?";
    }
    
    $driverQuery .= " ORDER BY dl.last_updated DESC";
    
    $stmt = $conn->prepare($driverQuery);
    if ($driver_id) {
        $stmt->bind_param("s", $driver_id);
    }
    
    if (!$stmt->execute()) {
        throw new Exception('Failed to fetch driver data: ' . $stmt->error);
    }
    
    $result = $stmt->get_result();
    $drivers = [];
    
    while ($row = $result->fetch_assoc()) {
        $drivers[] = [
            'driver_id' => $row['driver_id'], // Keep as string to preserve unique IDs
            'driver_name' => $row['driver_name'],
            'latitude' => floatval($row['latitude']),
            'longitude' => floatval($row['longitude']),
            'accuracy' => $row['accuracy'] ? floatval($row['accuracy']) : null,
            'speed' => $row['speed'] ? floatval($row['speed']) : null,
            'heading' => $row['heading'] ? floatval($row['heading']) : null,
            'altitude' => $row['altitude'] ? floatval($row['altitude']) : null,
            'last_updated' => $row['last_updated'],
            'status' => $row['status'],
            'is_active' => (bool)$row['is_active'],
            'profile_picture' => $row['profile_picture'],
            'instructor_full_name' => $row['instructor_full_name']
        ];
    }
    $stmt->close();
    
    // Get recent tracks for each driver
    $tracks = [];
    foreach ($drivers as $driver) {
        $trackQuery = "
        SELECT 
            latitude,
            longitude,
            accuracy,
            speed,
            heading,
            altitude,
            timestamp
        FROM driver_tracks 
        WHERE driver_id = ? 
        AND timestamp >= DATE_SUB(NOW(), INTERVAL ? MINUTE)
        ORDER BY timestamp ASC
        ";
        
        $trackStmt = $conn->prepare($trackQuery);
        $trackStmt->bind_param("si", $driver['driver_id'], $minutes_back);
        
        if ($trackStmt->execute()) {
            $trackResult = $trackStmt->get_result();
            $driverTracks = [];
            
            while ($trackRow = $trackResult->fetch_assoc()) {
                $driverTracks[] = [
                    'latitude' => floatval($trackRow['latitude']),
                    'longitude' => floatval($trackRow['longitude']),
                    'accuracy' => $trackRow['accuracy'] ? floatval($trackRow['accuracy']) : null,
                    'speed' => $trackRow['speed'] ? floatval($trackRow['speed']) : null,
                    'heading' => $trackRow['heading'] ? floatval($trackRow['heading']) : null,
                    'altitude' => $trackRow['altitude'] ? floatval($trackRow['altitude']) : null,
                    'timestamp' => $trackRow['timestamp']
                ];
            }
            
            $tracks[$driver['driver_id']] = $driverTracks;
        }
        $trackStmt->close();
    }
    
    // Get system statistics
    $statsQuery = "
    SELECT 
        COUNT(*) as total_drivers,
        SUM(CASE WHEN last_updated >= DATE_SUB(NOW(), INTERVAL 1 MINUTE) THEN 1 ELSE 0 END) as live_drivers,
        SUM(CASE WHEN last_updated >= DATE_SUB(NOW(), INTERVAL 5 MINUTE) THEN 1 ELSE 0 END) as recent_drivers,
        SUM(CASE WHEN last_updated < DATE_SUB(NOW(), INTERVAL 30 MINUTE) THEN 1 ELSE 0 END) as offline_drivers
    FROM driver_locations 
    WHERE is_active = TRUE
    ";
    
    $statsResult = $conn->query($statsQuery);
    $stats = $statsResult ? $statsResult->fetch_assoc() : [
        'total_drivers' => 0,
        'live_drivers' => 0,
        'recent_drivers' => 0,
        'offline_drivers' => 0
    ];
    
    // Prepare response
    $response = [
        'success' => true,
        'timestamp' => date('Y-m-d H:i:s'),
        'data' => [
            'drivers' => $drivers,
            'tracks' => $tracks,
            'statistics' => [
                'total_drivers' => intval($stats['total_drivers']),
                'live_drivers' => intval($stats['live_drivers']),
                'recent_drivers' => intval($stats['recent_drivers']),
                'offline_drivers' => intval($stats['offline_drivers'])
            ],
            'parameters' => [
                'minutes_back' => $minutes_back,
                'driver_id' => $driver_id
            ]
        ]
    ];
    
    echo json_encode($response, JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    // Log error for debugging
    error_log("Driver data fetch error: " . $e->getMessage());
    
    // Return error response
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'timestamp' => date('Y-m-d H:i:s'),
        'data' => [
            'drivers' => [],
            'tracks' => [],
            'statistics' => [
                'total_drivers' => 0,
                'live_drivers' => 0,
                'recent_drivers' => 0,
                'offline_drivers' => 0
            ]
        ]
    ]);
} finally {
    if (isset($conn)) {
        $conn->close();
    }
}
?>
