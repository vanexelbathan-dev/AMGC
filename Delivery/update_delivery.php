<?php
// update_delivery.php
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
        if (isset($_FILES['proof_photo']) && $_FILES['proof_photo']['error'] == 0) {
            $upload_dir = '../uploads/deliveries/';
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            $file_name = time() . '_' . basename($_FILES['proof_photo']['name']);
            $target_file = $upload_dir . $file_name;
            
            if (move_uploaded_file($_FILES['proof_photo']['tmp_name'], $target_file)) {
                $photo_path = $target_file;
            }
        }
        
        // Update sales order status
        $query = "UPDATE sales_orders SET order_status = 'delivered', updated_at = NOW() WHERE so_id = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param('i', $_POST['so_id']);
        $stmt->execute();
        
        // Get customer_id from sales order
        $query = "SELECT customer_id FROM sales_orders WHERE so_id = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param('i', $_POST['so_id']);
        $stmt->execute();
        $result = $stmt->get_result();
        $order_data = $result->fetch_assoc();
        $customer_id = $order_data['customer_id'];
        
        // Prepare remarks
        $remarks = $_POST['remarks'];
        if ($photo_path) {
            $remarks .= " [Photo: " . basename($photo_path) . "]";
        }
        
        // Insert into deliveries table
        $query = "INSERT INTO deliveries (so_id, customer_id, delivery_date, delivery_status, signed_by, remarks, created_at, updated_at) 
                  VALUES (?, ?, ?, 'delivered', ?, ?, NOW(), NOW())";
        $stmt = $conn->prepare($query);
        $stmt->bind_param('issss', $_POST['so_id'], $customer_id, $_POST['delivery_date'], $_POST['signed_by'], $remarks);
        $stmt->execute();
        
        // Redirect back with success message
        $_SESSION['success_message'] = 'Delivery completed successfully!';
        header("Location: fordelivery.php");
        exit();
        
    } catch (Exception $e) {
        die("Database error: " . $e->getMessage());
    }
} else {
    header("Location: fordelivery.php");
    exit();
}

$conn->close();
?>