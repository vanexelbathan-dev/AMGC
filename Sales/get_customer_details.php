<?php
require_once '../config/database.php';
require_once '../config/session_handler.php';

// Protect page
requireLogin();
requireRole(['sales']);

if (isset($_GET['id'])) {
    $customer_id = (int)$_GET['id'];
    $branch_id = getUserBranchId();
    
    // Get customer details
    $sql = "SELECT * FROM customers WHERE customer_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $customer_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $customer = $result->fetch_assoc();
        echo json_encode([
            'success' => true,
            'customer' => $customer
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Customer not found'
        ]);
    }
} else {
    echo json_encode([
        'success' => false,
        'message' => 'No customer ID provided'
    ]);
}
?>