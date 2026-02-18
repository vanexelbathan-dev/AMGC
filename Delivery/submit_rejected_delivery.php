<?php
// submit_rejected_delivery.php
session_start();
require_once '../config/database.php';

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: ../login.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: rejecteddelivery.php");
    exit();
}

// Get current user info
$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['first_name'] . ' ' . $_SESSION['last_name'];

// Get form data
$delivery_id = isset($_POST['delivery_id']) ? intval($_POST['delivery_id']) : 0;
$so_id = isset($_POST['so_id']) ? intval($_POST['so_id']) : 0;
$customer_id = isset($_POST['customer_id']) ? intval($_POST['customer_id']) : 0;
$rejection_date = isset($_POST['rejection_date']) ? $_POST['rejection_date'] : date('Y-m-d H:i:s');
$rejection_reason = isset($_POST['rejection_reason']) ? trim($_POST['rejection_reason']) : '';
$other_reason = isset($_POST['other_reason']) ? trim($_POST['other_reason']) : '';
$description = isset($_POST['description']) ? trim($_POST['description']) : '';
$proposed_action = isset($_POST['proposed_action']) ? trim($_POST['proposed_action']) : '';
$retry_date = isset($_POST['retry_date']) && !empty($_POST['retry_date']) ? $_POST['retry_date'] : null;
$additional_notes = isset($_POST['additional_notes']) ? trim($_POST['additional_notes']) : '';
$branch_id = isset($_POST['branch_id']) ? intval($_POST['branch_id']) : 0;
$driver_id = isset($_POST['driver_id']) ? intval($_POST['driver_id']) : 0;

// Validate required fields
$errors = [];

if ($delivery_id <= 0) {
    $errors[] = "Please select a valid delivery order";
}

if ($so_id <= 0) {
    $errors[] = "Invalid sales order ID";
}

if ($customer_id <= 0) {
    $errors[] = "Invalid customer ID";
}

if (empty($rejection_reason)) {
    $errors[] = "Please select a rejection reason";
}

if (empty($description)) {
    $errors[] = "Please provide a detailed description";
}

// If there are errors, redirect back with error messages
if (!empty($errors)) {
    $_SESSION['error_message'] = implode("<br>", $errors);
    header("Location: rejecteddelivery.php");
    exit();
}

try {
    // Begin transaction
    $conn->begin_transaction();
    
    // Combine reason if "Other" was selected
    $final_reason = $rejection_reason;
    if ($rejection_reason == 'Other' && !empty($other_reason)) {
        $final_reason = 'Other: ' . $other_reason;
    }
    
    // Format rejection date
    try {
        $formatted_date = date('Y-m-d H:i:s', strtotime($rejection_date));
    } catch (Exception $e) {
        $formatted_date = date('Y-m-d H:i:s');
    }
    
    // Combine remarks
    $remarks = "REASON: " . $final_reason . ". DETAILS: " . $description;
    if (!empty($additional_notes)) {
        $remarks .= " ADDITIONAL NOTES: " . $additional_notes;
    }
    if (!empty($proposed_action)) {
        $remarks .= " PROPOSED ACTION: " . $proposed_action;
    }
    if (!empty($retry_date)) {
        $remarks .= " RETRY DATE: " . $retry_date;
    }
    
    // Handle file upload
    $photo_path = null;
    if (isset($_FILES['rejection_photo']) && $_FILES['rejection_photo']['error'] == 0) {
        $upload_dir = '../uploads/rejections/';
        
        // Create directory if it doesn't exist
        if (!file_exists($upload_dir)) {
            if (!mkdir($upload_dir, 0777, true)) {
                error_log("Failed to create directory: " . $upload_dir);
            }
        }
        
        // Check if directory is writable
        if (is_writable($upload_dir)) {
            $file_extension = pathinfo($_FILES['rejection_photo']['name'], PATHINFO_EXTENSION);
            $file_name = 'rejected_' . $delivery_id . '_' . time() . '.' . $file_extension;
            $target_file = $upload_dir . $file_name;
            
            if (move_uploaded_file($_FILES['rejection_photo']['tmp_name'], $target_file)) {
                $photo_path = $target_file;
                $remarks .= " [PHOTO: " . $file_name . "]";
            } else {
                error_log("Failed to move uploaded file: " . error_get_last()['message']);
            }
        } else {
            error_log("Upload directory is not writable: " . $upload_dir);
        }
    }
    
    // Check if delivery exists and update it
    $check_query = "SELECT d.*, tt.trip_id 
                    FROM deliveries d 
                    LEFT JOIN trip_tickets tt ON d.trip_id = tt.trip_id
                    WHERE d.delivery_id = ?";
    $check_stmt = $conn->prepare($check_query);
    
    if (!$check_stmt) {
        throw new Exception("Database prepare error: " . $conn->error);
    }
    
    $check_stmt->bind_param("i", $delivery_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    if ($check_result->num_rows == 0) {
        // If delivery doesn't exist, create new rejected delivery record
        $insert_query = "INSERT INTO deliveries (so_id, customer_id, driver_id, branch_id, delivery_date, delivery_status, remarks, created_at) 
                        VALUES (?, ?, ?, ?, ?, 'rejected', ?, NOW())";
        $insert_stmt = $conn->prepare($insert_query);
        
        if (!$insert_stmt) {
            throw new Exception("Database prepare error for insert: " . $conn->error);
        }
        
        $insert_stmt->bind_param("iiiiss", $so_id, $customer_id, $driver_id, $branch_id, $formatted_date, $remarks);
        
        if (!$insert_stmt->execute()) {
            throw new Exception("Failed to insert delivery record: " . $insert_stmt->error);
        }
        
        $delivery_id = $conn->insert_id;
    } else {
        $delivery = $check_result->fetch_assoc();
        $trip_id = $delivery['trip_id'] ?? null;
        
        // Check if delivery is already rejected
        if ($delivery['delivery_status'] == 'rejected') {
            throw new Exception("This delivery is already marked as rejected");
        }
        
        // Update the existing delivery record
        $update_query = "UPDATE deliveries SET 
                         delivery_status = 'rejected',
                         remarks = CONCAT(IFNULL(remarks, ''), ?),
                         signed_by = NULL,
                         delivery_date = ?
                         WHERE delivery_id = ?";
        
        $update_stmt = $conn->prepare($update_query);
        if (!$update_stmt) {
            throw new Exception("Database prepare error for update: " . $conn->error);
        }
        
        $remarks_prefix = "\n[" . date('Y-m-d H:i:s') . "] REJECTED by " . $user_name . ": " . $remarks;
        $update_stmt->bind_param("ssi", $remarks_prefix, $formatted_date, $delivery_id);
        
        if (!$update_stmt->execute()) {
            throw new Exception("Failed to update delivery record: " . $update_stmt->error);
        }
        
        $trip_id = $delivery['trip_id'] ?? null;
    }
    
    // Update sales order status to 'cancelled' or 'pending' based on proposed action
    $new_order_status = 'cancelled';
    if ($proposed_action == 'Retry Delivery' || $proposed_action == 'Contact Customer') {
        $new_order_status = 'pending';
    }
    
    $update_so_query = "UPDATE sales_orders SET order_status = ?, updated_at = NOW() WHERE so_id = ?";
    $update_so_stmt = $conn->prepare($update_so_query);
    if ($update_so_stmt) {
        $update_so_stmt->bind_param("si", $new_order_status, $so_id);
        $update_so_stmt->execute();
        $update_so_stmt->close();
    }
    
    // Update related trip ticket status if exists
    if (!empty($trip_id)) {
        $trip_update = "UPDATE trip_tickets 
                        SET trip_status = 'delayed', 
                            updated_at = NOW() 
                        WHERE trip_id = ? AND trip_status NOT IN ('completed', 'cancelled')";
        $trip_stmt = $conn->prepare($trip_update);
        if ($trip_stmt) {
            $trip_stmt->bind_param("i", $trip_id);
            $trip_stmt->execute();
            $trip_stmt->close();
        }
    }
    
    // Commit transaction
    $conn->commit();
    
    $_SESSION['success_message'] = 'Rejection report submitted successfully!';
    
} catch (Exception $e) {
    // Rollback transaction on error
    if ($conn->connect_error === null) {
        $conn->rollback();
    }
    error_log("Error in submit_rejected_delivery.php: " . $e->getMessage());
    $_SESSION['error_message'] = "Error submitting rejection report: " . $e->getMessage();
}

// Redirect back to the form
header("Location: rejecteddelivery.php");
exit();

$conn->close();
?>