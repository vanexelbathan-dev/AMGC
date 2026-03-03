

<?php
require_once '../config/database.php';
session_start();

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['isDriver' => false, 'driverId' => null]);
    exit;
}

$user_id = $_SESSION['user_id'];

// Check if user is a driver
$sql = "SELECT driver_id FROM drivers WHERE user_id = ? LIMIT 1";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $row = $result->fetch_assoc();
    echo json_encode([
        'isDriver' => true,
        'driverId' => intval($row['driver_id'])
    ]);
} else {
    echo json_encode(['isDriver' => false, 'driverId' => null]);
}

$stmt->close();
$conn->close();
?>
