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
        // Get the delivery_id and so_id from the form
        $delivery_id = isset($_POST['delivery_id']) ? intval($_POST['delivery_id']) : 0;
        $so_id = isset($_POST['so_id']) ? intval($_POST['so_id']) : 0;
        
        if (!$delivery_id || !$so_id) {
            throw new Exception("Missing delivery ID or order ID");
        }
        
        // Handle file upload
        $photo_path = null;
        $photo_filename = null;
        if (isset($_FILES['proof_photo']) && $_FILES['proof_photo']['error'] == 0) {
            $upload_dir = '../uploads/deliveries/';
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            // Create year/month subdirectories for better organization
            $year_month = date('Y-m');
            $upload_subdir = $upload_dir . $year_month . '/';
            if (!file_exists($upload_subdir)) {
                mkdir($upload_subdir, 0777, true);
            }
            
            $file_name = time() . '_' . preg_replace('/[^a-zA-Z0-9.]/', '_', basename($_FILES['proof_photo']['name']));
            $target_file = $upload_subdir . $file_name;
            
            if (move_uploaded_file($_FILES['proof_photo']['tmp_name'], $target_file)) {
                $photo_path = $target_file;
                $photo_filename = $year_month . '/' . $file_name; // Store relative path
            }
        }
        
        // Start transaction
        $conn->begin_transaction();
        
        // Update sales order status to delivered (but keep for reference)
        $query = "UPDATE sales_orders SET order_status = 'delivered', updated_at = NOW() WHERE so_id = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param('i', $so_id);
        $stmt->execute();
        
        // Prepare delivery completion details
        $delivery_date = $_POST['delivery_date'] ?? date('Y-m-d H:i:s');
        $signed_by = $_POST['signed_by'] ?? '';
        $remarks = isset($_POST['remarks']) ? $_POST['remarks'] : '';
        
        // Build comprehensive delivery notes
        $completion_notes = "\n" . str_repeat("=", 50) . "\n";
        $completion_notes .= "DELIVERY COMPLETED: " . date('Y-m-d H:i:s') . "\n";
        $completion_notes .= "Signed by: " . $signed_by . "\n";
        $completion_notes .= "Delivery Date: " . date('Y-m-d H:i:s', strtotime($delivery_date)) . "\n";
        
        if ($photo_filename) {
            $completion_notes .= "Proof Photo: " . $photo_filename . "\n";
        }
        
        if ($remarks) {
            $completion_notes .= "Remarks: " . $remarks . "\n";
        }
        $completion_notes .= str_repeat("=", 50);
        
        // UPDATE the existing delivery record with delivered status but KEEP it in the system
        $query = "UPDATE deliveries SET 
                  delivery_status = 'delivered', 
                  delivery_date = ?, 
                  signed_by = ?, 
                  remarks = CONCAT(IFNULL(remarks, ''), ?),
                  updated_at = NOW() 
                  WHERE delivery_id = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param('sssi', $delivery_date, $signed_by, $completion_notes, $delivery_id);
        $stmt->execute();
        
        // Check if update was successful
        if ($stmt->affected_rows === 0) {
            throw new Exception("Delivery record not found");
        }
        
        $conn->commit();
        
        // Redirect back with success message
        $_SESSION['success_message'] = 'Delivery completed successfully!';
        header("Location: fordelivery.php");
        exit();
        
    } catch (Exception $e) {
        $conn->rollback();
        error_log("Delivery update error: " . $e->getMessage());
        $_SESSION['error_message'] = "Error: " . $e->getMessage();
        header("Location: fordelivery.php");
        exit();
    }
} else {
    header("Location: fordelivery.php");
    exit();
}

$conn->close();
?>