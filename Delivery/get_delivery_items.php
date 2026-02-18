<?php
// get_delivery_items.php
require_once '../config/database.php';
require_once '../config/session_handler.php';

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit();
}

$delivery_id = isset($_GET['delivery_id']) ? intval($_GET['delivery_id']) : 0;

if (!$delivery_id) {
    echo json_encode(['success' => false, 'message' => 'Invalid delivery ID']);
    exit();
}

// Get delivery items
$query = "
    SELECT 
        soi.item_id,
        i.item_name,
        soi.quantity_ordered as quantity,
        soi.unit_price as price
    FROM deliveries d
    INNER JOIN sales_orders so ON d.so_id = so.so_id
    INNER JOIN sales_order_items soi ON so.so_id = soi.so_id
    INNER JOIN items i ON soi.item_id = i.item_id
    WHERE d.delivery_id = ?
";

$stmt = $conn->prepare($query);
$stmt->bind_param("i", $delivery_id);
$stmt->execute();
$result = $stmt->get_result();

$items = [];
while ($row = $result->fetch_assoc()) {
    $items[] = $row;
}

echo json_encode(['success' => true, 'items' => $items]);

$conn->close();
?>