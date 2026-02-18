<?php
// update_delivery.php
session_start();
require_once '../config/database.php';

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: ../login.php");
    exit();
}

// Get user info
$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? 'delivery';
$branch_id = $_SESSION['branch_id'] ?? 0;
$driver_id = $_SESSION['driver_id'] ?? 0;

// If user is delivery role but no driver_id in session, try to get it
if ($user_role == 'delivery' && $driver_id == 0) {
    $driver_query = "SELECT driver_id FROM users WHERE user_id = ? AND driver_id IS NOT NULL";
    $driver_stmt = $conn->prepare($driver_query);
    $driver_stmt->bind_param("i", $user_id);
    $driver_stmt->execute();
    $driver_result = $driver_stmt->get_result();
    if ($driver_row = $driver_result->fetch_assoc()) {
        $driver_id = $driver_row['driver_id'];
        $_SESSION['driver_id'] = $driver_id;
    }
    $driver_stmt->close();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        // Get the delivery_id and so_id from the form
        $delivery_id = isset($_POST['delivery_id']) ? intval($_POST['delivery_id']) : 0;
        $so_id = isset($_POST['so_id']) ? intval($_POST['so_id']) : 0;
        $branch_id_post = isset($_POST['branch_id']) ? intval($_POST['branch_id']) : $branch_id;
        
        if (!$delivery_id || !$so_id) {
            throw new Exception("Missing delivery ID or order ID");
        }
        
        // Validate that this delivery belongs to the logged-in driver (if delivery role)
        if ($user_role == 'delivery' && $driver_id > 0) {
            $check_query = "SELECT delivery_id, driver_id FROM deliveries WHERE delivery_id = ?";
            $check_stmt = $conn->prepare($check_query);
            $check_stmt->bind_param("i", $delivery_id);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            $delivery_data = $check_result->fetch_assoc();
            $check_stmt->close();
            
            if (!$delivery_data) {
                throw new Exception("Delivery not found");
            }
            
            // If delivery has driver_id and it doesn't match logged-in driver, deny access
            if (!empty($delivery_data['driver_id']) && $delivery_data['driver_id'] != $driver_id) {
                throw new Exception("You are not authorized to update this delivery");
            }
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
            
            // Validate file type
            $allowed_types = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
            $file_type = $_FILES['proof_photo']['type'];
            if (!in_array($file_type, $allowed_types)) {
                throw new Exception("Invalid file type. Only JPG, PNG and GIF are allowed.");
            }
            
            // Validate file size (max 5MB)
            if ($_FILES['proof_photo']['size'] > 5 * 1024 * 1024) {
                throw new Exception("File too large. Maximum size is 5MB.");
            }
            
            // Generate safe filename
            $file_extension = pathinfo($_FILES['proof_photo']['name'], PATHINFO_EXTENSION);
            $safe_filename = time() . '_' . uniqid() . '.' . $file_extension;
            $target_file = $upload_subdir . $safe_filename;
            
            if (move_uploaded_file($_FILES['proof_photo']['tmp_name'], $target_file)) {
                $photo_path = $target_file;
                $photo_filename = $year_month . '/' . $safe_filename;
            } else {
                throw new Exception("Failed to upload file");
            }
        } else {
            throw new Exception("Proof of delivery photo is required");
        }
        
        // Start transaction
        $conn->begin_transaction();
        
        // Update sales order status to delivered
        $query = "UPDATE sales_orders SET order_status = 'delivered', updated_at = NOW() WHERE so_id = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param('i', $so_id);
        if (!$stmt->execute()) {
            throw new Exception("Failed to update sales order: " . $stmt->error);
        }
        $stmt->close();
        
        // Prepare delivery completion details
        $delivery_date = $_POST['delivery_date'] ?? date('Y-m-d H:i:s');
        $signed_by = $_POST['signed_by'] ?? '';
        $remarks = isset($_POST['remarks']) ? trim($_POST['remarks']) : '';
        
        // Build comprehensive delivery notes
        $completion_notes = "\n" . str_repeat("=", 50) . "\n";
        $completion_notes .= "DELIVERY COMPLETED: " . date('Y-m-d H:i:s') . "\n";
        $completion_notes .= "Completed by User ID: " . $user_id . "\n";
        $completion_notes .= "Signed by: " . $signed_by . "\n";
        $completion_notes .= "Delivery Date: " . date('Y-m-d H:i:s', strtotime($delivery_date)) . "\n";
        
        if ($photo_filename) {
            $completion_notes .= "Proof Photo: " . $photo_filename . "\n";
        }
        
        if ($remarks) {
            $completion_notes .= "Remarks: " . $remarks . "\n";
        }
        $completion_notes .= str_repeat("=", 50);
        
        // UPDATE the existing delivery record with delivered status
        $query = "UPDATE deliveries SET 
                  delivery_status = 'delivered', 
                  delivery_date = ?, 
                  signed_by = ?, 
                  remarks = CONCAT(IFNULL(remarks, ''), ?),
                  updated_at = NOW() 
                  WHERE delivery_id = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param('sssi', $delivery_date, $signed_by, $completion_notes, $delivery_id);
        if (!$stmt->execute()) {
            throw new Exception("Failed to update delivery: " . $stmt->error);
        }
        
        // Check if update was successful
        if ($stmt->affected_rows === 0) {
            throw new Exception("Delivery record not found or no changes made");
        }
        $stmt->close();
        
        // Update deliveries record with driver_id if not set and we have driver_id
        if ($driver_id > 0) {
            $update_driver = "UPDATE deliveries SET driver_id = ? WHERE delivery_id = ? AND (driver_id IS NULL OR driver_id = 0)";
            $driver_stmt = $conn->prepare($update_driver);
            $driver_stmt->bind_param("ii", $driver_id, $delivery_id);
            $driver_stmt->execute();
            $driver_stmt->close();
        }
        
        // Update inventory - reduce quantity for delivered items
        // Get items from this delivery
        $items_query = "SELECT soi.item_id, soi.quantity_ordered 
                        FROM sales_order_items soi 
                        WHERE soi.so_id = ?";
        $items_stmt = $conn->prepare($items_query);
        $items_stmt->bind_param("i", $so_id);
        $items_stmt->execute();
        $items_result = $items_stmt->get_result();
        
        while ($item = $items_result->fetch_assoc()) {
            // Update inventory for the branch
            $inventory_query = "UPDATE inventory 
                               SET quantity_on_hand = quantity_on_hand - ?,
                                   quantity_reserved = quantity_reserved - ?,
                                   updated_at = NOW()
                               WHERE branch_id = ? AND item_id = ?";
            $inventory_stmt = $conn->prepare($inventory_query);
            $inventory_stmt->bind_param("iiii", 
                $item['quantity_ordered'], 
                $item['quantity_ordered'], 
                $branch_id_post, 
                $item['item_id']
            );
            $inventory_stmt->execute();
            $inventory_stmt->close();
            
            // Record inventory transaction
            $trans_query = "INSERT INTO inventory_transactions 
                           (branch_id, item_id, transaction_type, quantity_changed, reference_type, reference_id, created_by, created_at)
                           VALUES (?, ?, 'out', ?, 'sales_order', ?, ?, NOW())";
            $trans_stmt = $conn->prepare($trans_query);
            $trans_stmt->bind_param("iiiii", 
                $branch_id_post, 
                $item['item_id'], 
                $item['quantity_ordered'], 
                $so_id, 
                $user_id
            );
            $trans_stmt->execute();
            $trans_stmt->close();
        }
        $items_stmt->close();
        
        $conn->commit();
        
        // Redirect back with success message
        $_SESSION['success_message'] = 'Delivery completed successfully! Inventory has been updated.';
        
        // Redirect based on role
        if ($user_role == 'delivery') {
            header("Location: fordelivery.php");
        } else {
            header("Location: fordelivery.php");
        }
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