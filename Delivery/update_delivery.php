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


function normalize_uploaded_files($file_input) {
    $files = [];
    if (!isset($file_input['name'])) {
        return $files;
    }

    if (is_array($file_input['name'])) {
        $count = count($file_input['name']);
        for ($i = 0; $i < $count; $i++) {
            if ($file_input['error'][$i] === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $files[] = [
                'name' => $file_input['name'][$i],
                'type' => $file_input['type'][$i],
                'tmp_name' => $file_input['tmp_name'][$i],
                'error' => $file_input['error'][$i],
                'size' => $file_input['size'][$i]
            ];
        }
    } else {
        if ($file_input['error'] !== UPLOAD_ERR_NO_FILE) {
            $files[] = $file_input;
        }
    }

    return $files;
}

function save_delivery_payment_attachments($conn, $delivery_id, $so_id, $uploaded_by) {
    $uploaded_files = normalize_uploaded_files($_FILES['payment_attachments'] ?? []);

    if (count($uploaded_files) === 0) {
        throw new Exception("Payment attachment is required for check and online transfer payments.");
    }

    $upload_dir = '../uploads/deliveries/';
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }

    $year_month = date('Y-m');
    $upload_subdir = $upload_dir . $year_month . '/';
    if (!file_exists($upload_subdir)) {
        mkdir($upload_subdir, 0777, true);
    }

    $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf'];
    $allowed_types = [
        'image/jpeg',
        'image/jpg',
        'image/png',
        'image/gif',
        'image/webp',
        'application/pdf'
    ];

    $insert_query = "INSERT INTO delivery_attachments
                     (delivery_id, so_id, file_name, file_path, file_type, file_size, uploaded_by, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
    $insert_stmt = $conn->prepare($insert_query);
    if (!$insert_stmt) {
        throw new Exception("Failed to prepare attachment insert: " . $conn->error);
    }

    foreach ($uploaded_files as $file) {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new Exception("Attachment upload failed. Error code: " . $file['error']);
        }

        if ($file['size'] > 10 * 1024 * 1024) {
            throw new Exception("Attachment file is too large. Maximum size is 10MB.");
        }

        $original_name = basename($file['name']);
        $extension = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
        $file_type = $file['type'] ?: 'application/octet-stream';

        if (!in_array($extension, $allowed_extensions, true) || !in_array($file_type, $allowed_types, true)) {
            throw new Exception("Invalid attachment type. Only JPG, PNG, GIF, WEBP and PDF files are allowed.");
        }

        $safe_name_only = preg_replace('/[^A-Za-z0-9._-]/', '_', pathinfo($original_name, PATHINFO_FILENAME));
        $safe_filename = 'payment_' . $delivery_id . '_' . time() . '_' . uniqid() . '_' . $safe_name_only . '.' . $extension;
        $target_file = $upload_subdir . $safe_filename;
        $db_file_path = 'uploads/deliveries/' . $year_month . '/' . $safe_filename;

        if (!move_uploaded_file($file['tmp_name'], $target_file)) {
            throw new Exception("Failed to move uploaded attachment to uploads/deliveries folder.");
        }

        $file_size = (int) $file['size'];
        $insert_stmt->bind_param(
            "iisssii",
            $delivery_id,
            $so_id,
            $original_name,
            $db_file_path,
            $file_type,
            $file_size,
            $uploaded_by
        );

        if (!$insert_stmt->execute()) {
            throw new Exception("Failed to save delivery attachment: " . $insert_stmt->error);
        }
    }

    $insert_stmt->close();
}

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
        
        // Check if payment was collected (toggle switch)
        $collect_payment = isset($_POST['collect_payment']) && $_POST['collect_payment'] == '1';
        $payment_method = $_POST['payment_method'] ?? 'cash';
        
        if (!$delivery_id || !$so_id) {
            throw new Exception("Missing delivery ID or order ID");
        }
        
        // Validate that this delivery belongs to the logged-in driver (if delivery role)
        if ($user_role == 'delivery' && $driver_id > 0) {
            $check_query = "SELECT delivery_id, driver_id, trip_id FROM deliveries WHERE delivery_id = ?";
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
            
            $trip_id = $delivery_data['trip_id'];
        } else {
            // Get trip_id for admin users
            $trip_query = "SELECT trip_id FROM deliveries WHERE delivery_id = ?";
            $trip_stmt = $conn->prepare($trip_query);
            $trip_stmt->bind_param("i", $delivery_id);
            $trip_stmt->execute();
            $trip_result = $trip_stmt->get_result();
            $trip_data = $trip_result->fetch_assoc();
            $trip_id = $trip_data['trip_id'] ?? null;
            $trip_stmt->close();
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
        
        // Add payment collection note if payment was collected
        if ($collect_payment) {
            $payment_amount = isset($_POST['payment_amount']) ? floatval($_POST['payment_amount']) : 0;
            $payment_method = $_POST['payment_method'] ?? 'cash';
            $completion_notes .= "\n[PAYMENT COLLECTED] Method: " . strtoupper($payment_method) . ", Amount: ₱" . number_format($payment_amount, 2) . "\n";
        }
        
        $completion_notes .= str_repeat("=", 50);
        
        // UPDATE the existing delivery record with delivered status AND save proof_delivery_photo
        $query = "UPDATE deliveries SET 
                  delivery_status = 'delivered', 
                  delivery_date = ?, 
                  signed_by = ?, 
                  proof_delivery_photo = ?,
                  remarks = CONCAT(IFNULL(remarks, ''), ?),
                  updated_at = NOW() 
                  WHERE delivery_id = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param('ssssi', $delivery_date, $signed_by, $photo_filename, $completion_notes, $delivery_id);
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
        
        // ===== UPDATE RELATED RECORDS FOR DELIVERED ORDER =====
        // Update pick list status to completed
        $update_pl_query = "UPDATE pick_lists SET pick_status = 'completed', updated_at = NOW() WHERE so_id = ?";
        $update_pl_stmt = $conn->prepare($update_pl_query);
        $update_pl_stmt->bind_param("i", $so_id);
        $update_pl_stmt->execute();
        $update_pl_stmt->close();
        
        // Update trip ticket status to completed if exists
        if ($trip_id) {
            $update_tt_query = "UPDATE trip_tickets SET trip_status = 'completed', updated_at = NOW() WHERE trip_id = ?";
            $update_tt_stmt = $conn->prepare($update_tt_query);
            $update_tt_stmt->bind_param("i", $trip_id);
            $update_tt_stmt->execute();
            $update_tt_stmt->close();
        }
        
        // ===== PAYMENT HANDLING - ONLY IF COLLECT_PAYMENT IS CHECKED =====
        if ($collect_payment && isset($_POST['payment_amount']) && floatval($_POST['payment_amount']) > 0) {
            $payment_amount = floatval($_POST['payment_amount']);
            $payment_method = $_POST['payment_method'] ?? $payment_method;
            
            // Get invoice_id for this sales order
            $invoice_query = "SELECT invoice_id FROM invoices WHERE so_id = ?";
            $invoice_stmt = $conn->prepare($invoice_query);
            $invoice_stmt->bind_param("i", $so_id);
            $invoice_stmt->execute();
            $invoice_result = $invoice_stmt->get_result();
            $invoice_row = $invoice_result->fetch_assoc();
            $invoice_id = $invoice_row['invoice_id'] ?? null;
            $invoice_stmt->close();
            
            if ($invoice_id) {
                // Get customer_id from sales_orders
                $cust_query = "SELECT customer_id FROM sales_orders WHERE so_id = ?";
                $cust_stmt = $conn->prepare($cust_query);
                $cust_stmt->bind_param("i", $so_id);
                $cust_stmt->execute();
                $cust_result = $cust_stmt->get_result();
                $customer_id = null;
                if ($cust_row = $cust_result->fetch_assoc()) {
                    $customer_id = $cust_row['customer_id'];
                }
                $cust_stmt->close();
                
                if ($customer_id) {
                    // Insert payment record
                    $payment_query = "INSERT INTO payments 
                                     (invoice_id, customer_id, payment_method, amount, payment_date, created_by, status";
                    
                    $payment_values = " VALUES (?, ?, ?, ?, NOW(), ?, 'completed'";
                    $params = [$invoice_id, $customer_id, $payment_method, $payment_amount, $user_id];
                    $types = "iisdi";
                    
                    // Add payment method specific fields
                    if ($payment_method == 'cash') {
                        $cash_tendered = isset($_POST['cash_tendered']) ? floatval($_POST['cash_tendered']) : $payment_amount;
                        $cash_change = $cash_tendered - $payment_amount;
                        if ($cash_change < 0) $cash_change = 0;
                        
                        $payment_query .= ", cash_tendered, cash_change)";
                        $payment_values .= ", ?, ?)";
                        $params[] = $cash_tendered;
                        $params[] = $cash_change;
                        $types .= "dd";
                        
                    } elseif ($payment_method == 'check') {
                        $check_number = trim($_POST['check_number'] ?? '');
                        $check_date = trim($_POST['check_date'] ?? '');
                        $bank_name = trim($_POST['bank_name'] ?? '');
                        $bank_branch = trim($_POST['bank_branch'] ?? '');
                        $reference_number = $check_number;
                        
                        $payment_query .= ", reference_number, check_number, check_date, bank_name, bank_branch)";
                        $payment_values .= ", ?, ?, ?, ?, ?)";
                        $params[] = $reference_number;
                        $params[] = $check_number;
                        $params[] = $check_date;
                        $params[] = $bank_name;
                        $params[] = $bank_branch;
                        $types .= "sssss";
                        
                    } elseif ($payment_method == 'online_transfer') {
                        $reference_number = trim($_POST['reference_number'] ?? '');
                        $bank_wallet_id = (int)($_POST['bank_wallet_id'] ?? 0);
                        if ($reference_number === '' || $bank_wallet_id <= 0) {
                            throw new Exception("Reference number and online transfer sub account are required.");
                        }

                        $bank_wallet = null;
                        $online_stmt = $conn->prepare("SELECT b.bank_name, COALESCE(pb.bank_name, '') AS parent_bank_name
                                                       FROM banks b
                                                       LEFT JOIN banks pb ON pb.bank_id = b.parent_bank_id
                                                       INNER JOIN bank_payment_methods bpm ON bpm.bank_id = b.bank_id AND bpm.payment_method = 'online_transfer'
                                                       WHERE b.bank_id = ? AND b.status = 'active' AND b.parent_bank_id IS NOT NULL LIMIT 1");
                        if ($online_stmt) {
                            $online_stmt->bind_param("i", $bank_wallet_id);
                            $online_stmt->execute();
                            $online_row = $online_stmt->get_result()->fetch_assoc();
                            $online_stmt->close();
                            if ($online_row) {
                                $bank_wallet = trim(($online_row['parent_bank_name'] ? $online_row['parent_bank_name'] . ' / ' : '') . $online_row['bank_name']);
                            }
                        }
                        if (!$bank_wallet) {
                            throw new Exception("Please select a registered online transfer sub account.");
                        }

                        $payment_query .= ", reference_number, bank_name)";
                        $payment_values .= ", ?, ?)";
                        $params[] = $reference_number;
                        $params[] = $bank_wallet;
                        $types .= "ss";
                    }                    
                    $payment_query .= $payment_values;
                    
                    $payment_stmt = $conn->prepare($payment_query);
                    if ($payment_stmt) {
                        $payment_stmt->bind_param($types, ...$params);
                        $payment_stmt->execute();
                        $payment_stmt->close();
                        
                        // Update invoice status to paid
                        $update_invoice_paid = "UPDATE invoices SET status = 'paid', paid_at = NOW(), paid_by = ? WHERE invoice_id = ?";
                        $update_paid_stmt = $conn->prepare($update_invoice_paid);
                        $update_paid_stmt->bind_param("ii", $user_id, $invoice_id);
                        $update_paid_stmt->execute();
                        $update_paid_stmt->close();
                    }
                }
            }
        }
        // ===== END OF PAYMENT HANDLING =====
        // NOTE: If collect_payment is NOT checked, invoice remains as 'pending' (not paid)

        // Save multiple payment attachments for CHECK and ONLINE TRANSFER only
        if ($collect_payment && in_array($payment_method, ['check', 'online_transfer'], true)) {
            save_delivery_payment_attachments($conn, $delivery_id, $so_id, $user_id);
        }
        
        // Update inventory - reduce quantity for delivered items
        $items_query = "SELECT soi.item_id, soi.quantity_ordered 
                        FROM sales_order_items soi 
                        WHERE soi.so_id = ?";
        $items_stmt = $conn->prepare($items_query);
        $items_stmt->bind_param("i", $so_id);
        $items_stmt->execute();
        $items_result = $items_stmt->get_result();
        
        while ($item = $items_result->fetch_assoc()) {
            // Check if inventory record exists
            $check_inventory = "SELECT inventory_id FROM inventory WHERE branch_id = ? AND item_id = ?";
            $check_stmt = $conn->prepare($check_inventory);
            $check_stmt->bind_param("ii", $branch_id_post, $item['item_id']);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            
            if ($check_result->num_rows > 0) {
                // Update existing inventory
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
            } else {
                // Insert new inventory record with negative quantity (since it's being deducted)
                $inventory_query = "INSERT INTO inventory (branch_id, item_id, quantity_on_hand, quantity_reserved, updated_at)
                                   VALUES (?, ?, ?, ?, NOW())";
                $inventory_stmt = $conn->prepare($inventory_query);
                $neg_quantity = -$item['quantity_ordered'];
                $inventory_stmt->bind_param("iiii", $branch_id_post, $item['item_id'], $neg_quantity, $neg_quantity);
                $inventory_stmt->execute();
                $inventory_stmt->close();
            }
            $check_stmt->close();
            
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
        if ($collect_payment) {
            $_SESSION['success_message'] = 'Delivery completed successfully! Payment has been recorded.';
        } else {
            $_SESSION['success_message'] = 'Delivery completed successfully! Invoice will be processed separately.';
        }
        
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