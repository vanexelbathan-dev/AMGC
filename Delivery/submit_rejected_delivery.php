<?php
// submit_rejected_delivery.php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: ../login.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        // Handle file upload
        $photo_path = null;
        if (isset($_FILES['rejection_photo']) && $_FILES['rejection_photo']['error'] == 0) {
            $upload_dir = '../uploads/rejections/';
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            $file_name = time() . '_' . basename($_FILES['rejection_photo']['name']);
            $target_file = $upload_dir . $file_name;
            
            if (move_uploaded_file($_FILES['rejection_photo']['tmp_name'], $target_file)) {
                $photo_path = $target_file;
            }
        }
        
        // Update sales order status
        $query = "UPDATE sales_orders SET order_status = 'cancelled', updated_at = NOW() WHERE so_id = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param('i', $_POST['order_id']);
        $stmt->execute();
        
        // Insert into deliveries table as rejected
        $remarks = "Rejected: " . $_POST['rejection_reason'] . ". Details: " . $_POST['description'];
        if ($_POST['rejection_reason'] == 'Other' && !empty($_POST['other_reason'])) {
            $remarks .= " (Other: " . $_POST['other_reason'] . ")";
        }
        
        // Get customer_id from sales order
        $query = "SELECT customer_id FROM sales_orders WHERE so_id = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param('i', $_POST['order_id']);
        $stmt->execute();
        $result = $stmt->get_result();
        $order_data = $result->fetch_assoc();
        $customer_id = $order_data['customer_id'];
        
        // Insert delivery record
        $delivery_date = $_POST['delivery_date'] . ' ' . date('H:i:s');
        $query = "INSERT INTO deliveries (so_id, customer_id, delivery_date, delivery_status, remarks, created_at, updated_at) 
                  VALUES (?, ?, ?, 'rejected', ?, NOW(), NOW())";
        $stmt = $conn->prepare($query);
        $stmt->bind_param('iiss', $_POST['order_id'], $customer_id, $delivery_date, $remarks);
        $stmt->execute();
        
        // Redirect back with success message
        $_SESSION['success_message'] = 'Rejection report submitted successfully!';
        header("Location: rejecteddelivery.php");
        exit();
        
    } catch (Exception $e) {
        die("Database error: " . $e->getMessage());
    }
} else {
    header("Location: rejecteddelivery.php");
    exit();
}

$conn->close();
?>