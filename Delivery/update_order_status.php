<?php
// update_order_status.php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['so_id']) && isset($_POST['new_status'])) {
    try {
        $query = "UPDATE sales_orders SET order_status = ?, updated_at = NOW() WHERE so_id = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param('si', $_POST['new_status'], $_POST['so_id']);
        $stmt->execute();
        
        echo json_encode(['success' => true, 'message' => 'Status updated successfully']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
}

$conn->close();
?>