<?php
require_once '../config/database.php';
require_once '../config/session_handler.php';

// Get current user info and branch context
$user_id = $_SESSION['user_id'] ?? 0;
$user_name = isset($_SESSION['first_name']) ? $_SESSION['first_name'] . ' ' . $_SESSION['last_name'] : 'Branch Admin';
$user_role = isset($_SESSION['role']) ? $_SESSION['role'] : 'branch_admin';
$branch_id = $_SESSION['branch_id'] ?? 0;
$view_all_branches = $_SESSION['view_all_branches'] ?? false;

// Get user initials for avatar
$user_initials = '';
if (!empty($user_name)) {
    $name_parts = explode(' ', $user_name);
    foreach ($name_parts as $part) {
        if (!empty($part)) {
            $user_initials .= strtoupper(substr($part, 0, 1));
        }
    }
}
if (empty($user_initials)) {
    $user_initials = 'BA';
}

// Get user's branch name for display
$branch_name = 'All Branches';
if (!$view_all_branches && $branch_id > 0) {
    $branch_query = "SELECT branch_name FROM branches WHERE branch_id = ?";
    $branch_stmt = $conn->prepare($branch_query);
    $branch_stmt->bind_param("i", $branch_id);
    $branch_stmt->execute();
    $branch_result = $branch_stmt->get_result();
    if ($branch_row = $branch_result->fetch_assoc()) {
        $branch_name = $branch_row['branch_name'];
    }
    $branch_stmt->close();
}

// Check if branch_id column exists in rmr_requests table
$rmr_branch_column_exists = false;
$check_rmr_column = $conn->query("SHOW COLUMNS FROM rmr_requests LIKE 'branch_id'");
if ($check_rmr_column && $check_rmr_column->num_rows > 0) {
    $rmr_branch_column_exists = true;
}

// Check if branch_id column exists in deliveries table
$delivery_branch_column_exists = false;
$check_delivery_column = $conn->query("SHOW COLUMNS FROM deliveries LIKE 'branch_id'");
if ($check_delivery_column && $check_delivery_column->num_rows > 0) {
    $delivery_branch_column_exists = true;
}

// Check if branch_id column exists in customers table
$customers_branch_column_exists = false;
$check_customers_column = $conn->query("SHOW COLUMNS FROM customers LIKE 'branch_id'");
if ($check_customers_column && $check_customers_column->num_rows > 0) {
    $customers_branch_column_exists = true;
}

// Check if branch_id column exists in items table
$items_branch_column_exists = false;
$check_items_column = $conn->query("SHOW COLUMNS FROM items LIKE 'branch_id'");
if ($check_items_column && $check_items_column->num_rows > 0) {
    $items_branch_column_exists = true;
}

// Check if price columns exist in items table
$price_case_exists = false;
$check_price_case = $conn->query("SHOW COLUMNS FROM items LIKE 'price_case'");
if ($check_price_case && $check_price_case->num_rows > 0) {
    $price_case_exists = true;
}

// Check if inventory_transactions table exists
$inventory_transactions_exists = false;
$check_inv_trans = $conn->query("SHOW TABLES LIKE 'inventory_transactions'");
if ($check_inv_trans && $check_inv_trans->num_rows > 0) {
    $inventory_transactions_exists = true;
}

// Get base64 encoded logo for printing
$logo_path = '../Pictures/amgc3DLogo.png';
$logo_base64 = '';
if (file_exists($logo_path)) {
    $image_data = file_get_contents($logo_path);
    $logo_base64 = 'data:image/png;base64,' . base64_encode($image_data);
}

// Determine branch filter condition
$rmr_branch_condition = "";
$delivery_branch_condition = "";

if ($rmr_branch_column_exists && !$view_all_branches) {
    $rmr_branch_condition = "AND r.branch_id = $branch_id";
}

if ($delivery_branch_column_exists && !$view_all_branches) {
    $delivery_branch_condition = "AND d.branch_id = $branch_id";
}


// Fix older invalid confirmed records caused by ENUM columns that do not allow confirmed.
// We use rmr_status = approved in the database, then display it as Confirmed in the UI.
$rmr_status_column_check = $conn->query("SHOW COLUMNS FROM rmr_requests LIKE 'rmr_status'");
$rmr_disposition_column_check = $conn->query("SHOW COLUMNS FROM rmr_requests LIKE 'disposition_type'");
if ($rmr_status_column_check && $rmr_status_column_check->num_rows > 0 && $rmr_disposition_column_check && $rmr_disposition_column_check->num_rows > 0) {
    @$conn->query("UPDATE rmr_requests SET rmr_status = 'approved', updated_at = NOW() WHERE (rmr_status IS NULL OR rmr_status = '') AND disposition_type IS NOT NULL");
}
// ========== HANDLE AJAX REQUESTS ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    try {
        $conn->begin_transaction();
        
        // PROCESS RMR (Change status to processing)
        if ($_POST['action'] === 'process_rmr') {
            $rmr_id = (int)$_POST['rmr_id'];
            $inspector_name = $_POST['inspector_name'];
            $inspection_type = $_POST['inspection_type'];
            $user_id = $_SESSION['user_id'] ?? 1;
            
            // Verify RMR belongs to user's branch
            if ($rmr_branch_column_exists && !$view_all_branches) {
                $check_query = "SELECT rmr_id FROM rmr_requests WHERE rmr_id = ? AND branch_id = ?";
                $check_stmt = $conn->prepare($check_query);
                $check_stmt->bind_param("ii", $rmr_id, $branch_id);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                
                if ($check_result->num_rows === 0) {
                    throw new Exception('RMR not found or access denied');
                }
            }
            
            // Update RMR status
            $update_query = "UPDATE rmr_requests 
                           SET rmr_status = 'processing', 
                               inspector_name = ?, 
                               inspection_type = ?,
                               updated_at = NOW() 
                           WHERE rmr_id = ?";
            $update_stmt = $conn->prepare($update_query);
            $update_stmt->bind_param("ssi", $inspector_name, $inspection_type, $rmr_id);
            
            if (!$update_stmt->execute()) {
                throw new Exception('Failed to update RMR status');
            }
            
            $conn->commit();
            
            echo json_encode([
                'success' => true,
                'message' => 'RMR is now being processed'
            ]);
            exit;
        }
        
        // APPROVE RMR
        elseif ($_POST['action'] === 'approve_rmr') {
            $rmr_id = (int)$_POST['rmr_id'];
            $disposition_type = $_POST['disposition_type'];
            $approved_amount = (float)$_POST['approved_amount'];
            $approval_notes = $_POST['approval_notes'] ?? null;
            $user_id = $_SESSION['user_id'] ?? 1;
            
            // Verify RMR belongs to user's branch
            if ($rmr_branch_column_exists && !$view_all_branches) {
                $check_query = "SELECT rmr_id FROM rmr_requests WHERE rmr_id = ? AND branch_id = ?";
                $check_stmt = $conn->prepare($check_query);
                $check_stmt->bind_param("ii", $rmr_id, $branch_id);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                
                if ($check_result->num_rows === 0) {
                    throw new Exception('RMR not found or access denied');
                }
            }
            
            // Verify RMR details only. Do NOT add stock here.
            // Returned merchandise must still pass through Purchase Order > Receive Inventory > Returned Merchandise tab.
            $rmr_details_query = "SELECT r.*, i.item_name 
                                 FROM rmr_requests r
                                 JOIN items i ON r.item_id = i.item_id
                                 WHERE r.rmr_id = ?";
            $rmr_details_stmt = $conn->prepare($rmr_details_query);
            $rmr_details_stmt->bind_param("i", $rmr_id);
            $rmr_details_stmt->execute();
            $rmr_details = $rmr_details_stmt->get_result()->fetch_assoc();

            if (!$rmr_details) {
                throw new Exception('RMR details not found');
            }

            // Confirm RMR only. Stock will be returned through Receive Inventory.
            $update_query = "UPDATE rmr_requests 
                           SET rmr_status = 'approved', 
                               disposition_type = ?,
                               updated_at = NOW() 
                           WHERE rmr_id = ?";
            $update_stmt = $conn->prepare($update_query);
            $update_stmt->bind_param("si", $disposition_type, $rmr_id);

            if (!$update_stmt->execute()) {
                throw new Exception('Failed to confirm RMR');
            }

            // Insert into RMR approval/confirmation history
            $history_query = "INSERT INTO rmr_approvals (rmr_id, approved_amount, approval_notes, approved_by, approved_at) 
                            VALUES (?, ?, ?, ?, NOW())";
            $history_stmt = $conn->prepare($history_query);
            $history_stmt->bind_param("idsi", $rmr_id, $approved_amount, $approval_notes, $user_id);
            $history_stmt->execute();

            $conn->commit();

            echo json_encode([
                'success' => true,
                'message' => 'RMR confirmed successfully. Stock was not updated yet. Please receive this RMR through Receive Inventory > Returned Merchandise to return it to inventory.'
            ]);
            exit;
        }
        
        // REJECT RMR
        elseif ($_POST['action'] === 'reject_rmr') {
            $rmr_id = (int)$_POST['rmr_id'];
            $rejection_reason = $_POST['rejection_reason'] ?? 'Rejected by QC';
            $user_id = $_SESSION['user_id'] ?? 1;
            
            // Verify RMR belongs to user's branch
            if ($rmr_branch_column_exists && !$view_all_branches) {
                $check_query = "SELECT rmr_id FROM rmr_requests WHERE rmr_id = ? AND branch_id = ?";
                $check_stmt = $conn->prepare($check_query);
                $check_stmt->bind_param("ii", $rmr_id, $branch_id);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                
                if ($check_result->num_rows === 0) {
                    throw new Exception('RMR not found or access denied');
                }
            }
            
            // Update RMR status to rejected
            $update_query = "UPDATE rmr_requests 
                           SET rmr_status = 'rejected', 
                               reason_details = CONCAT(IFNULL(reason_details, ''), ' | Rejected: ', ?),
                               updated_at = NOW() 
                           WHERE rmr_id = ?";
            $update_stmt = $conn->prepare($update_query);
            $update_stmt->bind_param("si", $rejection_reason, $rmr_id);
            
            if (!$update_stmt->execute()) {
                throw new Exception('Failed to reject RMR');
            }
            
            $conn->commit();
            
            echo json_encode([
                'success' => true,
                'message' => 'RMR rejected successfully'
            ]);
            exit;
        }
        
        // VIEW RMR DETAILS
        elseif ($_POST['action'] === 'view_rmr') {
            $rmr_id = (int)$_POST['rmr_id'];
            
            // Add branch filter if needed
            $query = "
                SELECT 
                    r.*,
                    COALESCE(c.customer_name, dc.customer_name, 'N/A') AS customer_name,
                    COALESCE(c.customer_id, dc.customer_id, r.customer_id) AS customer_id,
                    i.item_code,
                    i.item_name,
                    i.unit_price,
                    i.price_case,
                    i.price_inner_pack,
                    i.price_box,
                    i.price_carton,
                    i.unit_type,
                    i.stock as current_stock,
                    b.branch_name,
                    CONCAT(u.first_name, ' ', u.last_name) as received_by_name
                FROM rmr_requests r
                LEFT JOIN customers c ON r.customer_id = c.customer_id
                LEFT JOIN deliveries d ON r.delivery_id = d.delivery_id
                LEFT JOIN customers dc ON d.customer_id = dc.customer_id
                JOIN items i ON r.item_id = i.item_id
                LEFT JOIN branches b ON r.branch_id = b.branch_id
                LEFT JOIN users u ON r.received_by = u.user_id
                WHERE r.rmr_id = ?
            ";
            
            if ($rmr_branch_column_exists && !$view_all_branches) {
                $query .= " AND r.branch_id = ?";
                $stmt = $conn->prepare($query);
                $stmt->bind_param("ii", $rmr_id, $branch_id);
            } else {
                $stmt = $conn->prepare($query);
                $stmt->bind_param("i", $rmr_id);
            }
            
            $stmt->execute();
            $result = $stmt->get_result();
            $rmr = $result->fetch_assoc();
            
            if ($rmr) {
                // Get approval history if any
                $approval_query = "SELECT * FROM rmr_approvals WHERE rmr_id = ? ORDER BY approved_at DESC LIMIT 1";
                $approval_stmt = $conn->prepare($approval_query);
                $approval_stmt->bind_param("i", $rmr_id);
                $approval_stmt->execute();
                $approval_result = $approval_stmt->get_result();
                $approval = $approval_result->fetch_assoc();
                
                echo json_encode([
                    'success' => true,
                    'rmr' => $rmr,
                    'approval' => $approval
                ]);
            } else {
                throw new Exception('RMR not found');
            }
            exit;
        }
        
        // CREATE RMR FROM REJECTED DELIVERY
        elseif ($_POST['action'] === 'create_rmr_from_delivery') {
            $delivery_id = (int)$_POST['delivery_id'];
            $so_id = (int)$_POST['so_id'];
            $customer_id = (int)$_POST['customer_id'];
            $item_id = (int)$_POST['item_id'];
            $return_quantity = (int)$_POST['return_quantity'];
            $return_reason = $_POST['return_reason'];
            $reason_details = $_POST['reason_details'] ?? '';
            $branch_id_for_insert = $_POST['branch_id'] ?? $branch_id;
            $user_id = $_SESSION['user_id'] ?? 1;
            
            // Validate required fields
            if (!$delivery_id || !$so_id || !$customer_id || !$item_id || !$return_quantity || !$return_reason) {
                throw new Exception('All fields are required');
            }
            
            // Generate RMR number
            $rmr_number = 'RMR-' . date('Ymd') . '-' . str_pad($delivery_id, 5, '0', STR_PAD_LEFT);
            
            // Check if RMR already exists for this delivery
            $check_query = "SELECT rmr_id FROM rmr_requests WHERE delivery_id = ?";
            $check_stmt = $conn->prepare($check_query);
            $check_stmt->bind_param("i", $delivery_id);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            
            if ($check_result->num_rows > 0) {
                throw new Exception('RMR already exists for this delivery');
            }
            
            // Insert into rmr_requests
            $insert_query = "INSERT INTO rmr_requests (
                rmr_number, delivery_id, so_id, customer_id, item_id, 
                return_quantity, return_reason, reason_details, rmr_status, 
                branch_id, received_by, received_date, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?, NOW(), NOW())";
            
            $insert_stmt = $conn->prepare($insert_query);
            $insert_stmt->bind_param(
                "siiiissisi", 
                $rmr_number, $delivery_id, $so_id, $customer_id, $item_id,
                $return_quantity, $return_reason, $reason_details, 
                $branch_id_for_insert, $user_id
            );
            
            if (!$insert_stmt->execute()) {
                throw new Exception('Failed to create RMR: ' . $insert_stmt->error);
            }
            
            $rmr_id = $conn->insert_id;
            
            $conn->commit();
            
            echo json_encode([
                'success' => true,
                'message' => 'RMR created successfully from rejected delivery',
                'rmr_id' => $rmr_id,
                'rmr_number' => $rmr_number
            ]);
            exit;
        }
        
        // PRINT RMR REPORT
        elseif ($_POST['action'] === 'print_rmr') {
            $filter_data = json_decode($_POST['filter_data'] ?? '{}', true);
            
            // Build query based on filters
            $print_query = "
                SELECT 
                    r.rmr_id,
                    r.rmr_number,
                    r.delivery_id,
                    r.so_id,
                    r.return_quantity,
                    r.return_reason,
                    r.reason_details,
                    r.rmr_status,
                    r.received_date,
                    r.inspector_name,
                    r.inspection_type,
                    r.disposition_type,
                    r.branch_id,
                    r.created_at,
                    c.customer_name,
                    i.item_code,
                    i.item_name,
                    i.unit_price,
                    i.unit_type,
                    b.branch_name,
                    CONCAT(u.first_name, ' ', u.last_name) as received_by_name
                FROM rmr_requests r
                LEFT JOIN customers c ON r.customer_id = c.customer_id
                LEFT JOIN deliveries d ON r.delivery_id = d.delivery_id
                LEFT JOIN customers dc ON d.customer_id = dc.customer_id
                JOIN items i ON r.item_id = i.item_id
                LEFT JOIN branches b ON r.branch_id = b.branch_id
                LEFT JOIN users u ON r.received_by = u.user_id
                WHERE 1=1
            ";
            
            // Apply filters
            if (!empty($filter_data['status']) && $filter_data['status'] !== 'all') {
                $print_query .= " AND r.rmr_status = '" . $conn->real_escape_string($filter_data['status']) . "'";
            }
            
            if (!empty($filter_data['reason']) && $filter_data['reason'] !== 'all') {
                $print_query .= " AND r.return_reason = '" . $conn->real_escape_string($filter_data['reason']) . "'";
            }
            
            if (!empty($filter_data['branch']) && $filter_data['branch'] !== 'all' && $rmr_branch_column_exists && $view_all_branches) {
                $print_query .= " AND r.branch_id = " . (int)$filter_data['branch'];
            } elseif (!$view_all_branches && $rmr_branch_column_exists) {
                $print_query .= " AND r.branch_id = $branch_id";
            }
            
            // Date filter
            if (!empty($filter_data['date']) && $filter_data['date'] !== 'all') {
                $date_filter = $filter_data['date'];
                $today = date('Y-m-d');
                
                switch($date_filter) {
                    case 'today':
                        $print_query .= " AND DATE(r.received_date) = '$today'";
                        break;
                    case 'yesterday':
                        $yesterday = date('Y-m-d', strtotime('-1 day'));
                        $print_query .= " AND DATE(r.received_date) = '$yesterday'";
                        break;
                    case 'this_week':
                        $start_week = date('Y-m-d', strtotime('monday this week'));
                        $end_week = date('Y-m-d', strtotime('sunday this week'));
                        $print_query .= " AND DATE(r.received_date) BETWEEN '$start_week' AND '$end_week'";
                        break;
                    case 'this_month':
                        $start_month = date('Y-m-01');
                        $end_month = date('Y-m-t');
                        $print_query .= " AND DATE(r.received_date) BETWEEN '$start_month' AND '$end_month'";
                        break;
                }
            }
            
            $print_query .= " ORDER BY r.received_date DESC, r.rmr_id DESC";
            
            $print_result = $conn->query($print_query);
            $print_items = $print_result ? $print_result->fetch_all(MYSQLI_ASSOC) : [];
            
            echo json_encode([
                'success' => true,
                'items' => $print_items,
                'branch_name' => $branch_id ? ('Branch ' . $branch_id) : 'All Branches',
                'view_all' => $view_all_branches,
                'rmr_branch_column_exists' => $rmr_branch_column_exists
            ]);
            exit;
        }
        
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
        exit;
    }
}

// FETCH RMR REQUESTS FROM DATABASE (including those from rejected deliveries)
if ($price_case_exists) {
    $rmr_query = "
        SELECT 
            r.rmr_id,
            r.rmr_number,
            r.delivery_id,
            r.so_id,
            r.return_quantity,
            r.return_reason,
            r.reason_details,
            r.rmr_status,
            r.received_date,
            r.inspector_name,
            r.inspection_type,
            r.disposition_type,
            r.branch_id,
            r.created_at,
            r.updated_at,
            COALESCE(c.customer_name, dc.customer_name, 'N/A') AS customer_name,
            COALESCE(c.customer_id, dc.customer_id, r.customer_id) AS customer_id,
            i.item_id,
            i.item_code,
            i.item_name,
            i.unit_price,
            i.price_case,
            i.price_inner_pack,
            i.price_box,
            i.price_carton,
            i.unit_type,
            b.branch_name,
            CONCAT(u.first_name, ' ', u.last_name) as received_by_name,
            d.delivery_status as source_delivery_status,
            d.delivery_date as source_delivery_date
        FROM rmr_requests r
        LEFT JOIN customers c ON r.customer_id = c.customer_id
        LEFT JOIN deliveries d ON r.delivery_id = d.delivery_id
        LEFT JOIN customers dc ON d.customer_id = dc.customer_id
        JOIN items i ON r.item_id = i.item_id
        LEFT JOIN branches b ON r.branch_id = b.branch_id
        LEFT JOIN users u ON r.received_by = u.user_id
        WHERE 1=1
        $rmr_branch_condition
        ORDER BY r.created_at DESC, r.rmr_id DESC
    ";
} else {
    $rmr_query = "
        SELECT 
            r.rmr_id,
            r.rmr_number,
            r.delivery_id,
            r.so_id,
            r.return_quantity,
            r.return_reason,
            r.reason_details,
            r.rmr_status,
            r.received_date,
            r.inspector_name,
            r.inspection_type,
            r.disposition_type,
            r.branch_id,
            r.created_at,
            r.updated_at,
            COALESCE(c.customer_name, dc.customer_name, 'N/A') AS customer_name,
            COALESCE(c.customer_id, dc.customer_id, r.customer_id) AS customer_id,
            i.item_id,
            i.item_code,
            i.item_name,
            i.unit_price,
            i.unit_type,
            b.branch_name,
            CONCAT(u.first_name, ' ', u.last_name) as received_by_name,
            d.delivery_status as source_delivery_status,
            d.delivery_date as source_delivery_date
        FROM rmr_requests r
        LEFT JOIN customers c ON r.customer_id = c.customer_id
        LEFT JOIN deliveries d ON r.delivery_id = d.delivery_id
        LEFT JOIN customers dc ON d.customer_id = dc.customer_id
        JOIN items i ON r.item_id = i.item_id
        LEFT JOIN branches b ON r.branch_id = b.branch_id
        LEFT JOIN users u ON r.received_by = u.user_id
        WHERE 1=1
        $rmr_branch_condition
        ORDER BY r.created_at DESC, r.rmr_id DESC
    ";
}

$rmr_result = $conn->query($rmr_query);

if (!$rmr_result) {
    $rmr_requests = [];
    error_log("RMR Query Error: " . $conn->error);
} else {
    $rmr_requests = $rmr_result->fetch_all(MYSQLI_ASSOC);
}

// FETCH REJECTED DELIVERIES THAT DON'T HAVE RMR YET
$rejected_deliveries_query = "
    SELECT 
        d.delivery_id,
        d.trip_id,
        d.so_id,
        d.customer_id,
        d.driver_id,
        d.branch_id,
        d.delivery_date,
        d.delivery_status,
        d.remarks,
        d.rejection_photo,
        d.rejection_reason,
        so.so_number,
        so.total_amount,
        c.customer_name,
        c.contact_person,
        c.phone_number,
        c.address,
        c.city,
        tt.trip_number,
        -- Check if RMR already exists
        (SELECT COUNT(*) FROM rmr_requests r WHERE r.delivery_id = d.delivery_id) as has_rmr
    FROM deliveries d
    LEFT JOIN sales_orders so ON d.so_id = so.so_id
    LEFT JOIN customers c ON d.customer_id = c.customer_id
    LEFT JOIN trip_tickets tt ON d.trip_id = tt.trip_id
    WHERE d.delivery_status = 'rejected'
";

// Add branch filter
if ($delivery_branch_column_exists && !$view_all_branches) {
    $rejected_deliveries_query .= " AND d.branch_id = " . intval($branch_id);
}

$rejected_deliveries_query .= " ORDER BY d.delivery_date DESC LIMIT 100";

$rejected_result = $conn->query($rejected_deliveries_query);

if (!$rejected_result) {
    $rejected_deliveries = [];
    error_log("Rejected Deliveries Query Error: " . $conn->error);
} else {
    $rejected_deliveries = $rejected_result->fetch_all(MYSQLI_ASSOC);
}

// FETCH ITEMS FOR RMR CREATION - WITH ALL PRICE COLUMNS
if ($price_case_exists) {
    $items_query = "SELECT item_id, item_code, item_name, unit_price, price_case, price_inner_pack, price_box, price_carton, unit_type, stock 
                    FROM items 
                    WHERE status = 'active'";
} else {
    $items_query = "SELECT item_id, item_code, item_name, unit_price, unit_type, stock 
                    FROM items 
                    WHERE status = 'active'";
}

if ($items_branch_column_exists && !$view_all_branches) {
    $items_query .= " AND branch_id = $branch_id";
}
$items_query .= " ORDER BY item_name ASC";
$items_result = $conn->query($items_query);

if (!$items_result) {
    $items_list = [];
    error_log("Items Query Error: " . $conn->error);
} else {
    $items_list = $items_result->fetch_all(MYSQLI_ASSOC);
}

// CALCULATE STATISTICS FROM REAL DATA (branch-specific)
$total_rmr = count($rmr_requests);
$pending_rmr = count(array_filter($rmr_requests, fn($r) => $r['rmr_status'] === 'pending'));
$processing_rmr = count(array_filter($rmr_requests, fn($r) => $r['rmr_status'] === 'processing'));
$approved_rmr = count(array_filter($rmr_requests, fn($r) => in_array($r['rmr_status'], ['approved', 'confirmed'])));
$rejected_rmr = count(array_filter($rmr_requests, fn($r) => $r['rmr_status'] === 'rejected'));
$resolved_rmr = count(array_filter($rmr_requests, fn($r) => $r['rmr_status'] === 'resolved'));

// STAT CARD VALUES
$statTotalRMR = $total_rmr;
$statPendingRMR = $pending_rmr;
$statProcessingRMR = $processing_rmr;
$statApprovedRMR = $approved_rmr;

// Helper function for status badge
function getRMRStatusClass($status) {
    return match($status) {
        'pending' => 'status-pending',
        'processing' => 'status-processing',
        'approved' => 'status-approved',
        'confirmed' => 'status-approved',
        'rejected' => 'status-rejected',
        'resolved' => 'status-resolved',
        default => 'status-pending'
    };
}

function getRMRStatusText($status) {
    return match($status) {
        'pending' => 'Pending',
        'processing' => 'Processing',
        'approved' => 'Confirmed',
        'confirmed' => 'Confirmed',
        'rejected' => 'Rejected',
        'resolved' => 'Resolved',
        default => ucfirst($status)
    };
}

function getReturnReasonClass($reason) {
    return match($reason) {
        'damaged' => 'reason-damaged',
        'expired' => 'reason-expired',
        'wrong-item' => 'reason-wrong-item',
        'quality' => 'reason-quality',
        'overstock' => 'reason-overstock',
        'other' => 'reason-other',
        default => 'reason-other'
    };
}

function getReturnReasonText($reason) {
    return match($reason) {
        'damaged' => 'Damaged',
        'expired' => 'Expired',
        'wrong-item' => 'Wrong Item',
        'quality' => 'Quality Issue',
        'overstock' => 'Overstock',
        'other' => 'Other',
        default => ucfirst($reason)
    };
}

function getUnitText($unit) {
    return match($unit) {
        'case' => 'CS',
        'inner-pack' => 'IP',
        'piece' => 'PC',
        'box' => 'BX',
        'carton' => 'CTN',
        default => strtoupper(substr($unit, 0, 2))
    };
}

function getDispositionText($disposition) {
    return match($disposition) {
        'credit' => 'Credit to Customer (Return to Stock)',
        'refund' => 'Cash Refund (Return to Stock)',
        'replacement' => 'Replacement (Return to Stock)',
        'disposal' => 'Destroy Item',
        'return-to-supplier' => 'Return to Supplier',
        default => $disposition ? ucfirst(str_replace('-', ' ', $disposition)) : ''
    };
}

function formatDate($dateTimeStr) {
    if (!$dateTimeStr) return '';
    $date = new DateTime($dateTimeStr);
    return $date->format('M d, Y H:i');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bad Orders - Branch Admin</title>
    <link rel="stylesheet" href="../css/current_inventory.css">
    <link rel="stylesheet" href="../css/bad_orders.css">
    <link rel="icon" type="image/png" href="../Pictures/favicon-96x96.png" sizes="96x96" />
    <link rel="icon" type="image/svg+xml" href="../Pictures/favicon.svg" />
    <link rel="shortcut icon" href="../Pictures/favicon.ico" />
    <link rel="apple-touch-icon" sizes="180x180" href="../Pictures/apple-touch-icon.png" />
    <link rel="manifest" href="../Pictures/site.webmanifest" />
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <!-- SheetJS for Excel Export -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <!-- SweetAlert2 -->
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
        <!-- Session Checker -->
    <script src="../js/session-checker.js"></script>
    <style>
        /* Branch badge styling */
        .branch-badge {
            background-color: #e7f1ff;
            color: #0d6efd;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
            margin-left: 5px;
        }
        
        /* Alert for missing branch column */
        .alert-info {
            background-color: #d1ecf1;
            border-color: #bee5eb;
            color: #0c5460;
        }
        
        .alert-info code {
            background-color: #f8f9fa;
            padding: 2px 4px;
            border-radius: 4px;
            color: #c7254e;
        }
        
        /* Inventory integration alert */
        .inventory-alert {
            background-color: #d4edda;
            border-color: #c3e6cb;
            color: #155724;
            border-left: 4px solid #28a745;
        }
        
        /* Table styles for RMR */
        .rmr-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
            background-color: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }
        
        .rmr-table thead th {
            background-color: #f8f9fa;
            font-weight: 600;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #495057;
            padding: 14px 12px;
            border-bottom: 2px solid #dee2e6;
            white-space: nowrap;
            vertical-align: middle;
            text-align: left;
        }
        
        .rmr-table tbody td {
            padding: 14px 12px;
            vertical-align: middle;
            border-bottom: 1px solid #e9ecef;
            font-size: 13px;
        }
        
        .rmr-table tbody tr:hover {
            background-color: #f8f9fa;
        }
        
        /* Column widths */
        .col-rmr { width: 12%; }
        .col-customer { width: 15%; }
        .col-item { width: 18%; }
        .col-qty { width: 8%; text-align: center; }
        .col-amount { width: 12%; text-align: right; }
        .col-reason { width: 12%; }
        .col-status { width: 10%; }
        .col-received { width: 12%; }
        <?php if ($rmr_branch_column_exists && $view_all_branches): ?>
        .col-branch { width: 8%; }
        <?php endif; ?>
        .col-actions { width: 13%; text-align: center; }
        
        /* Rejected deliveries table enhancements */
        .rejected-table {
            width: 100%;
            table-layout: fixed;
            border-collapse: collapse;
            background-color: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }
        
        .rejected-table thead th {
            background-color: #f8d7da;
            font-weight: 600;
            font-size: 13px;
            color: #721c24;
            padding: 14px 12px;
            border-bottom: 2px solid #f5c6cb;
            white-space: nowrap;
            vertical-align: middle;
            text-align: left;
        }
        
        .rejected-table tbody td {
            padding: 14px 12px;
            vertical-align: middle;
            border-bottom: 1px solid #e9ecef;
            font-size: 13px;
            word-wrap: break-word;
        }
        
        .rejected-table tbody tr:hover {
            background-color: #fff5f5;
        }
        
        /* Custom column widths for better flexibility */
        .rejected-table th:nth-child(1) { width: 8%; }  /* Delivery ID */
        .rejected-table th:nth-child(2) { width: 10%; } /* Order # */
        .rejected-table th:nth-child(3) { width: 15%; } /* Customer */
        .rejected-table th:nth-child(4) { width: 10%; } /* Trip # */
        .rejected-table th:nth-child(5) { width: 12%; } /* Delivery Date */
        .rejected-table th:nth-child(6) { width: 8%; }  /* Photo */
        .rejected-table th:nth-child(7) { width: 8%; }  /* Status */
        .rejected-table th:nth-child(8) { width: 12%; } /* Actions */
        
        /* Print Frame */
        #printFrame {
            position: absolute;
            left: -9999px;
            top: -9999px;
            width: 1px;
            height: 1px;
            opacity: 0;
            pointer-events: none;
        }

        /* Compact print styles - only logo has color */
        @media print {
            @page {
                size: landscape;
                margin: 0.3in;
            }
            
            body * {
                visibility: hidden;
                background: white !important;
                color: black !important;
                border-color: black !important;
            }
            
            #printFrame, #printFrame * {
                visibility: visible;
            }
            
            #printFrame {
                position: absolute;
                left: 0;
                top: 0;
                width: 100%;
                height: auto;
                border: none;
            }
            
            /* Only keep the logo colored */
            #printFrame img {
                filter: none !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            
            /* Everything else black and white */
            #printFrame * {
                background: white !important;
                color: black !important;
                border-color: #000 !important;
                box-shadow: none !important;
                text-shadow: none !important;
                -webkit-print-color-adjust: economy;
                print-color-adjust: economy;
            }
            
            /* Table borders in black */
            #printFrame table, 
            #printFrame th, 
            #printFrame td {
                border: 1px solid #000 !important;
            }
            
            /* Header background to white with black text */
            #printFrame th {
                background: white !important;
                color: black !important;
                font-weight: bold;
            }
            
            /* Remove any gradient backgrounds */
            #printFrame .summary-box,
            #printFrame .customer-section,
            #printFrame .total-row {
                background: white !important;
                border: 1px solid #000 !important;
            }
            
            /* Remove all background colors from badges */
            #printFrame .status-badge,
            #printFrame .return-reason,
            #printFrame .badge {
                background: white !important;
                border: 1px solid #000 !important;
                color: black !important;
                padding: 2px 6px;
            }
        }

        /* Photo thumbnail styling - bigger but not too wide */
        .photo-thumbnail {
            width: 50px;
            height: 50px;
            object-fit: cover;
            border-radius: 6px;
            cursor: pointer;
            transition: transform 0.2s;
            border: 1px solid #dee2e6;
        }
        
        .photo-thumbnail:hover {
            transform: scale(1.1);
            box-shadow: 0 2px 8px rgba(0,0,0,0.2);
        }
        
        .photo-placeholder {
            width: 50px;
            height: 50px;
            background-color: #f8f9fa;
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #6c757d;
            font-size: 14px;
            border: 1px solid #dee2e6;
        }
        
        /* Action buttons container */
        .rejected-table .action-buttons {
            display: flex;
            gap: 8px;
            justify-content: flex-start;
            align-items: center;
        }
        
        .empty-state-table {
            text-align: center;
            padding: 40px 20px;
            background-color: white;
            border-radius: 8px;
        }
        
        .empty-state-table i {
            font-size: 48px;
            color: #adb5bd;
            margin-bottom: 16px;
        }
        
        .empty-state-table h5 {
            color: #495057;
            margin-bottom: 8px;
        }
        
        .empty-state-table p {
            color: #6c757d;
            margin-bottom: 8px;
        }
        
        .status-badge {
            display: inline-block;
            padding: 5px 12px;
            font-size: 12px;
            font-weight: 500;
            border-radius: 20px;
            text-align: center;
            min-width: 85px;
        }
        
        .status-pending { background-color: #fff3cd; color: #856404; }
        .status-processing { background-color: #cce5ff; color: #004085; }
        .status-approved { background-color: #d4edda; color: #155724; }
        .status-rejected { background-color: #f8d7da; color: #721c24; }
        .status-resolved { background-color: #d1ecf1; color: #0c5460; }
        
        .return-reason {
            display: inline-block;
            padding: 4px 10px;
            font-size: 12px;
            font-weight: 500;
            border-radius: 4px;
        }
        
        .reason-damaged { background-color: #f8d7da; color: #721c24; }
        .reason-expired { background-color: #fff3cd; color: #856404; }
        .reason-wrong-item { background-color: #d1ecf1; color: #0c5460; }
        .reason-quality { background-color: #cce5ff; color: #004085; }
        .reason-overstock { background-color: #e2d5f2; color: #533f7c; }
        .reason-other { background-color: #e9ecef; color: #495057; }
        
        /* Filter section layout */
        .filter-section {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
            margin-bottom: 25px;
            padding: 16px 20px;
            background-color: #f8f9fa;
            border-radius: 8px;
        }
        
        .filter-controls {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 12px;
            flex: 1;
        }
        
        .filter-actions {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .filter-dropdowns {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            align-items: center;
        }
        
        .filter-dropdown {
            min-width: 160px;
        }
        
        .filter-dropdown .form-select {
            font-size: 13px;
            padding: 8px 12px;
            border-radius: 6px;
            border: 1px solid #ced4da;
            background-color: white;
            cursor: pointer;
        }
        
        .filter-dropdown .form-select:focus {
            border-color: #0d6efd;
            box-shadow: 0 0 0 0.2rem rgba(13,110,253,0.25);
        }
        
        .filter-label {
            font-size: 12px;
            font-weight: 600;
            color: #495057;
            margin-bottom: 4px;
            display: block;
        }

        /* RMR Details styling */
        .rmr-details-card {
            background-color: #f8f9fa;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
        }
        
        .detail-label {
            font-size: 12px;
            color: #6c757d;
            margin-bottom: 4px;
        }
        
        .detail-value {
            font-size: 16px;
            font-weight: 600;
            color: #212529;
        }
        
        .approval-badge {
            display: inline-block;
            padding: 8px 16px;
            background-color: #d4edda;
            color: #155724;
            border-radius: 20px;
            font-weight: 500;
        }
        
        .action-buttons {
            display: flex;
            gap: 5px;
            justify-content: center;
            align-items: center;
        }
        
        .btn-action {
            background: none;
            border: none;
            padding: 6px;
            border-radius: 4px;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 14px;
        }
        
        .btn-action:hover {
            background-color: #e9ecef;
        }
        
        .nav-tabs .nav-link {
            color: #495057;
            font-weight: 500;
        }
        
        .nav-tabs .nav-link.active {
            color: #0d6efd;
            font-weight: 600;
        }
        
        .badge-rmr-created {
            background-color: #cce5ff;
            color: #004085;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 500;
            margin-left: 5px;
        }
        
        /* Image gallery styling - bigger preview */
        .rejection-image-container {
            margin-top: 15px;
            border: 1px dashed #dee2e6;
            border-radius: 8px;
            padding: 15px;
            background-color: #f8f9fa;
        }
        
        .rejection-image {
            max-width: 100%;
            max-height: 250px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            cursor: pointer;
            transition: transform 0.2s;
            border: 2px solid #dee2e6;
        }
        
        .rejection-image:hover {
            transform: scale(1.02);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        
        .no-image-placeholder {
            padding: 30px;
            text-align: center;
            background-color: #e9ecef;
            border-radius: 8px;
            color: #6c757d;
        }
        
        .no-image-placeholder i {
            font-size: 48px;
            margin-bottom: 10px;
        }
        
        /* Photo Modal - bigger but not too wide */
        #photoViewModal .modal-dialog {
            max-width: 600px;
            margin: 1.75rem auto;
        }
        
        #photoViewModal .modal-dialog-centered {
            display: flex;
            align-items: center;
            min-height: calc(100% - 3.5rem);
        }
        
        #photoViewModal .modal-content {
            background-color: transparent;
            border: none;
            border-radius: 12px;
        }
        
        #photoViewModal .modal-header {
            border-radius: 12px 12px 0 0;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 12px 16px;
        }
        
        #photoViewModal .modal-header .btn-close {
            filter: brightness(0) invert(1);
            opacity: 0.8;
        }
        
        #photoViewModal .modal-header .btn-close:hover {
            opacity: 1;
        }
        
        #photoViewModal .modal-body {
            background-color: rgba(0, 0, 0, 0.85);
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 400px;
            padding: 20px;
            border-radius: 0;
        }
        
        #photoViewModal .modal-footer {
            border-radius: 0 0 12px 12px;
            background-color: rgba(255, 255, 255, 0.98);
            backdrop-filter: blur(5px);
            border-top: 1px solid rgba(0,0,0,0.1);
            padding: 12px 16px;
        }
        
        #photoViewModal img {
            max-height: 450px;
            max-width: 100%;
            object-fit: contain;
            box-shadow: 0 4px 20px rgba(0,0,0,0.5);
            border-radius: 8px;
        }
        
        /* Details Modal Remarks Styling - normal font, just line breaks */
        .details-remarks {
            background-color: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
            font-size: 13px;
            line-height: 1.6;
            border-left: 4px solid #6c757d;
            font-family: inherit;
        }
        
        .details-remarks .remark-line {
            margin-bottom: 8px;
            padding: 4px 0;
            border-bottom: 1px solid #e9ecef;
            white-space: normal;
        }
        
        .details-remarks .remark-line:last-child {
            border-bottom: none;
            margin-bottom: 0;
        }
        
        .details-remarks .timestamp {
            font-weight: 500;
            color: #495057;
        }
        
        /* Debug panel styling */
        .debug-panel {
            margin-top: 30px;
            padding: 20px;
            background-color: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            font-family: monospace;
            font-size: 12px;
        }
        .debug-panel pre {
            background-color: #fff;
            padding: 10px;
            border-radius: 4px;
            max-height: 300px;
            overflow: auto;
        }
        /* ===== MOBILE BOTTOM NAVIGATION - FIXED DROPDOWN ===== */
.mobile-nav {
    position: fixed;
    bottom: 0;
    left: 0;
    right: 0;
    background: white;
    border-top: 1px solid #e5e7eb;
    box-shadow: 0 -2px 10px rgba(0, 0, 0, 0.05);
    z-index: 9999;
    display: none;
    padding: 8px 0 12px 0;
    overflow: visible !important;
}

@media (max-width: 992px) {
    .mobile-nav {
        display: block;
    }

    .main-content {
        padding-bottom: 80px !important;
    }
}

.mobile-nav .nav {
    display: flex;
    justify-content: space-around;
    align-items: center;
    margin: 0;
    padding: 0;
    list-style: none;
    overflow: visible !important;
    scrollbar-width: none;
}

.mobile-nav .nav::-webkit-scrollbar {
    display: none;
}

.mobile-nav .nav-item {
    position: relative;
    flex-shrink: 0;
    text-align: center;
    overflow: visible !important;
}

.mobile-nav .nav-link {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 6px 12px;
    color: #9ca3af;
    font-size: 0.7rem;
    text-decoration: none;
    border-radius: 12px;
    gap: 4px;
    white-space: nowrap;
    background: transparent;
    border: none;
    cursor: pointer;
}

.mobile-nav .nav-link i {
    font-size: 1.3rem;
    margin: 0;
}

.mobile-nav .nav-link span {
    font-size: 0.65rem;
    font-weight: 500;
}

.mobile-nav .nav-link.active {
    color: #059669;
    background: rgba(5, 150, 105, 0.1);
}

.mobile-nav .more-dropdown {
    position: absolute;
    bottom: calc(100% + 8px);
    left: 50%;
    transform: translateX(-50%) translateY(8px);
    background: white;
    border-radius: 12px;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
    border: 1px solid #e5e7eb;
    min-width: 180px;
    z-index: 10000;
    display: none !important;
    opacity: 0;
    visibility: hidden;
    pointer-events: none;
    transition: opacity 0.2s ease, transform 0.2s ease;
}

.mobile-nav .more-dropdown.show {
    display: block !important;
    opacity: 1 !important;
    visibility: visible !important;
    pointer-events: auto !important;
    transform: translateX(-50%) translateY(0) !important;
}

.mobile-nav .more-dropdown::before {
    content: '';
    position: absolute;
    bottom: -6px;
    left: 50%;
    transform: translateX(-50%) rotate(45deg);
    width: 12px;
    height: 12px;
    background: white;
    border-right: 1px solid #e5e7eb;
    border-bottom: 1px solid #e5e7eb;
}

.mobile-nav .dropdown-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 16px;
    color: #374151;
    text-decoration: none;
    transition: background 0.2s ease;
    border-bottom: 1px solid #f3f4f6;
    font-size: 0.85rem;
    background: white;
    width: 100%;
    text-align: left;
    cursor: pointer;
}

.mobile-nav .dropdown-item:last-child {
    border-bottom: none;
}

.mobile-nav .dropdown-item:hover {
    background: #f9fafb;
}

.mobile-nav .dropdown-item.active {
    background: rgba(5, 150, 105, 0.1);
    color: #059669;
}

.mobile-nav .dropdown-item i {
    width: 20px;
    font-size: 1rem;
    color: #6b7280;
}

.mobile-nav .dropdown-item.active i {
    color: #059669;
}

@media (max-width: 480px) {
    .mobile-nav .nav-link {
        padding: 4px 8px;
    }

    .mobile-nav .nav-link i {
        font-size: 1.1rem;
    }

    .mobile-nav .nav-link span {
        font-size: 0.55rem;
    }

    .mobile-nav .more-dropdown {
        min-width: 160px;
    }

    .mobile-nav .dropdown-item {
        padding: 10px 12px;
        font-size: 0.75rem;
    }
}
    </style>
</head>
<body>
    <!-- Loading Overlay (optional) -->
    <div id="loadingOverlay" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(255,255,255,0.8); z-index: 9999; justify-content: center; align-items: center;">
        <div class="spinner-border text-primary" style="width: 3rem; height: 3rem;" role="status">
            <span class="visually-hidden">Loading...</span>
        </div>
    </div>

    <!-- Print Frame (hidden) -->
    <iframe id="printFrame" name="printFrame"></iframe>

    <!-- MAIN APPLICATION -->
    <div id="appPage">
       <!-- Sidebar -->
<div class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <h3>
            <button class="desktop-toggle-btn" id="desktopToggleBtn">
                <i class="bi bi-list" id="toggleIcon"></i>
            </button>
            <img src="../Pictures/amgc3DLogo.png" alt="Logo" class="logo-icon"> 
            <span class="nav-text">Branch Admin</span>
        </h3>
    </div>
    
    <div class="sidebar-content">
        <div class="sidebar-menu">
            <ul class="nav flex-column">
<<<<<<< HEAD
                
            <li class="nav-item">
                <a class="nav-link" href="branchdashboard.php">
                <i class="bi bi-speedometer2"></i>
                <span class="nav-text">Dashboard</span></a>
            </li>
=======
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
                <!-- Warehouse Dropdown - walang dropdown-toggle class -->
<li class="nav-item dropdown-nav">
    <a class="nav-link" href="#" onclick="toggleSidebarDropdown(event, 'warehouseMenu')">
        <i class="bi bi-shop"></i>
        <span class="nav-text">Warehouse</span>
        <i class="bi bi-chevron-down dropdown-arrow"></i>
    </a>
    <div class="collapse" id="warehouseMenu">
        <ul class="nav flex-column ps-4">
            <li class="nav-item">
                <a class="nav-link active" href="current_inventory.php">
                        <i class="bi bi-bar-chart-line"></i>
                        <span class="nav-text">Current Inventory</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="bad_orders.php">
                    <i class="bi bi-recycle"></i>
                    <span class="nav-text">Bad Orders</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="pick_list_items.php">
                    <i class="bi bi-list-check"></i>
                    <span class="nav-text">Pick List Items</span>
                </a>
            </li>
<<<<<<< HEAD
                                            <li class="nav-item">
                                    <a class="nav-link" href="warehouses.php">
                                    <i class="bi bi-shop"></i>
                                    <span class="nav-text">Warehouses</span></a>
                                </li>
=======
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
        </ul>
    </div>
</li>

                
                <!-- Supplier Dropdown - walang dropdown-toggle class -->
<li class="nav-item dropdown-nav">
    <a class="nav-link" href="#" onclick="toggleSidebarDropdown(event, 'supplierMenu')">
        <i class="bi bi-building"></i>
        <span class="nav-text">Supplier</span>
        <i class="bi bi-chevron-down dropdown-arrow"></i>
    </a>
    <div class="collapse" id="supplierMenu">
        <ul class="nav flex-column ps-4">
            <li class="nav-item">
                <a class="nav-link" href="purchase_order.php">
                    <i class="bi bi-box"></i>
<<<<<<< HEAD
                    <span class="nav-text">Receive Inventory</span>
=======
                    <span class="nav-text">Purchase Order</span>
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="supplier.php">
                    <i class="bi bi-people"></i>
                    <span class="nav-text">Supplier List</span>
                </a>
            </li>
        </ul>
    </div>
</li>

<<<<<<< HEAD
<li class="nav-item dropdown-nav">
                            <a class="nav-link" href="#" onclick="toggleSidebarDropdown(event, 'customerMenu')">
                                <i class="bi bi-people"></i><span class="nav-text">Customer</span><i class="bi bi-chevron-down dropdown-arrow"></i>
                            </a>
                            <div class="collapse" id="customerMenu">
                                <ul class="nav flex-column ps-4">
                                    <li class="nav-item"><a class="nav-link" href="customer_list.php"><i class="bi bi-person-badge"></i><span class="nav-text">Customer List</span></a></li>
                                    <li class="nav-item"><a class="nav-link" href="approve_credit_requests.php"><i class="bi bi-pencil-square"></i><span class="nav-text">Approve Credit Request</span></a></li>
                                    <li class="nav-item"><a class="nav-link" href="sales_order.php"><i class="bi bi-cart"></i><span class="nav-text">Sales Order</span></a></li>
                                    <li class="nav-item"><a class="nav-link active" href="collections.php"><i class="bi bi-cash-stack"></i><span class="nav-text">Collections</span></a></li>
                                </ul>
                            </div>
                        </li>
=======
<!-- Customer Dropdown - walang dropdown-toggle class -->
<li class="nav-item dropdown-nav">
    <a class="nav-link" href="#" onclick="toggleSidebarDropdown(event, 'customerMenu')">
        <i class="bi bi-people"></i>
        <span class="nav-text">Customer</span>
        <i class="bi bi-chevron-down dropdown-arrow"></i>
    </a>
    <div class="collapse" id="customerMenu">
        <ul class="nav flex-column ps-4">
            <li class="nav-item">
                <a class="nav-link" href="sales_order.php">
                    <i class="bi bi-cart"></i>
                    <span class="nav-text">Sales Order</span>
                </a>
            </li>
            <li class="nav-item"><a class="nav-link" href="collections.php">
                <i class="bi bi-cash-stack"></i>
                    <span class="nav-text">Collections</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="customer_list.php">
                    <i class="bi bi-person-badge"></i>
                    <span class="nav-text">Customer List</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="approve_credit_requests.php">
                    <i class="bi bi-pencil-square"></i>
                    <span class="nav-text">Approved Credit Request</span>
                </a>
            </li>
        </ul>
    </div>
</li>
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928

<!-- Delivery Dropdown - walang dropdown-toggle class -->
<li class="nav-item dropdown-nav">
    <a class="nav-link" href="#" onclick="toggleSidebarDropdown(event, 'deliveryMenu')">
        <i class="bi bi-truck"></i>
        <span class="nav-text">Delivery</span>
        <i class="bi bi-chevron-down dropdown-arrow"></i>
    </a>
    <div class="collapse" id="deliveryMenu">
        <ul class="nav flex-column ps-4">
            <li class="nav-item">
                <a class="nav-link" href="trip_tickets.php">
                    <i class="bi bi-ticket-perforated"></i>
                    <span class="nav-text">Trip Tickets</span>
                </a>
            </li>
        </ul>
    </div>
</li>
<<<<<<< HEAD
                                    <!-- Banking Dropdown -->
                    <li class="nav-item dropdown-nav">
                        <a class="nav-link" href="#" onclick="toggleSidebarDropdown(event, 'bankingMenu')">
                            <i class="bi bi-bank2"></i>
                            <span class="nav-text">Banking</span>
                            <i class="bi bi-chevron-down dropdown-arrow"></i>
                        </a>

                        <div class="collapse" id="bankingMenu">
                            <ul class="nav flex-column ps-4">
                                <li class="nav-item">
                                    <a class="nav-link" href="deposit.php">
                                        <i class="bi bi-arrow-down-circle"></i>
                                        <span class="nav-text">Deposit</span>
                                    </a>
                                </li>

                                <li class="nav-item">
                                    <a class="nav-link" href="Withdrawal.php">
                                        <i class="bi bi-arrow-up-circle"></i>
                                        <span class="nav-text">Withdrawal</span>
                                    </a>
                                </li>

                                <li class="nav-item">
                                    <a class="nav-link" href="bank_statement.php">
                                        <i class="bi bi-receipt"></i>
                                        <span class="nav-text">Bank Statement</span>
                                    </a>
                                </li>

                                <li class="nav-item">
                                    <a class="nav-link" href="expenses.php">
                                        <i class="bi bi-cash-stack"></i>
                                        <span class="nav-text">Expenses</span>
                                    </a>
                                </li>
                            </ul>
                        </div>
                    </li>
                    
                    <!-- Shared Services Dropdown -->
<li class="nav-item dropdown-nav">
    <a class="nav-link" href="#" onclick="toggleSidebarDropdown(event, 'sharedServicesMenu')">
        <i class="bi bi-grid-3x3-gap"></i>
        <span class="nav-text">Shared Services</span>
        <i class="bi bi-chevron-down dropdown-arrow"></i>
    </a>
    <div class="collapse" id="sharedServicesMenu">
        <ul class="nav flex-column ps-4">
            <li class="nav-item">
                <a class="nav-link" href="motorpool.php">
                    <i class="bi bi-truck"></i>
                    <span class="nav-text">Motorpool</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="central_warehouse.php">
                    <i class="bi bi-box-seam"></i>
                    <span class="nav-text">Central Warehouse</span>
                </a>
            </li>
        </ul>
    </div>
</li>
                    
=======
                <li class="nav-item">
    <a class="nav-link" href="banking.php">
        <i class="bi bi-bank2"></i>
        <span class="nav-text">Banking</span>
    </a>
</li>
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
                <!-- Users -->
                <li class="nav-item">
                    <a class="nav-link" href="drivers.php">
                        <i class="bi bi-people-fill"></i>
                        <span class="nav-text">Users</span>
                    </a>
                </li>
<<<<<<< HEAD
                
                
=======
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
            </ul>
        </div>
    </div>
    
    <div class="sidebar-footer">
        <div class="user-profile-sidebar">
            <div class="user-avatar-sidebar"><?php echo $user_initials; ?></div>
            <div class="user-details-sidebar">
                <span class="user-name-sidebar"><?php echo htmlspecialchars($user_name); ?></span>
                <span class="user-role-sidebar"><?php echo ucfirst($user_role); ?></span>
            </div>
        </div>
        <button class="logout-btn-sidebar" onclick="logout()">
            <i class="bi bi-box-arrow-right"></i>
            <span class="logout-text">Logout</span>
        </button>
    </div>
</div>

        <!-- Main Content -->
        <div class="main-content" id="mainContent">
            <!-- BAD ORDERS CONTENT -->
            <div class="page-content active">
                <!-- Navbar Top -->
                <div class="navbar-top">
                    <button class="mobile-menu-btn" id="mobileMenuBtn">
                        <i class="bi bi-list"></i>
                    </button>
                    <div class="page-title">
                        <h2>Bad Orders</h2>
                        <p id="dashboardSubtitle">
                            Manage Returned Merchandise Requests (RMR) from rejected deliveries
                        </p>
                    </div>
                </div>

                <!-- Branch Info Alerts -->
                <?php if (!$rmr_branch_column_exists): ?>
                    <div class="alert alert-info alert-dismissible fade show" role="alert">
                        <i class="bi bi-info-circle"></i> 
                        <strong>Branch filtering for RMR not yet set up.</strong> Please run this SQL in phpMyAdmin to enable branch-specific RMR data:
                        <br><br>
                        <code>ALTER TABLE rmr_requests ADD COLUMN branch_id INT NULL;</code>
                        <br>
                        <code>ALTER TABLE rmr_requests ADD FOREIGN KEY (branch_id) REFERENCES branches(branch_id);</code>
                        <br><br>
                        <button type="button" class="btn btn-sm btn-primary" onclick="copySQL('rmr_requests')">
                            <i class="bi bi-files"></i> Copy SQL
                        </button>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

               <!-- Stats Section with Horizontal Scroll on Mobile -->
<div class="row stat-card-row g-1 g-sm-2 mb-4">
    <!-- Stat 1: Total RMR -->
    <div class="col">
        <div class="stat-card total">
            <i class="bi bi-box-seam stat-icon"></i>
            <div class="stat-content">
                <div class="stat-value"><?= $statTotalRMR ?></div>
                <div class="stat-label">Total RMR</div>
                <?php if ($rmr_branch_column_exists && !$view_all_branches): ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Stat 2: Pending RMR -->
    <div class="col">
        <div class="stat-card pending">
            <i class="bi bi-clock-history stat-icon"></i>
            <div class="stat-content">
                <div class="stat-value"><?= $statPendingRMR ?></div>
                <div class="stat-label">Pending</div>
            </div>
        </div>
    </div>
    
    <!-- Stat 3: Processing RMR -->
    <div class="col">
        <div class="stat-card processing">
            <i class="bi bi-gear stat-icon"></i>
            <div class="stat-content">
                <div class="stat-value"><?= $statProcessingRMR ?></div>
                <div class="stat-label">Processing</div>
            </div>
        </div>
    </div>
    
    <!-- Stat 4: Approved RMR -->
    <div class="col">
        <div class="stat-card approved">
            <i class="bi bi-check-circle stat-icon"></i>
            <div class="stat-content">
                <div class="stat-value"><?= $statApprovedRMR ?></div>
<<<<<<< HEAD
                <div class="stat-label">Confirmed</div>
=======
                <div class="stat-label">Approved</div>
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
            </div>
        </div>
    </div>
</div>

<<<<<<< HEAD
               <!-- FILTER SECTION - COLLAPSIBLE DESIGN (Entire header clickable) -->
<div class="form-card mb-4" id="filterCard">
    <div class="filter-header" id="filterHeader" style="cursor: pointer;">
=======
               <!-- FILTER SECTION - COLLAPSIBLE DESIGN -->
<div class="form-card mb-4">
    <div class="filter-header">
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
        <h5>
            <i class="bi bi-funnel"></i> Filter RMR Requests
            <span class="filter-count-badge" id="filterCountBadge">0</span>
        </h5>
        <button class="filter-toggle-btn" type="button" id="filterToggleBtn" aria-expanded="false">
            <i class="bi bi-chevron-down" id="filterIcon"></i>
        </button>
    </div>
    
    <div class="filter-content collapsed" id="filterContent">
        <div class="row g-3">
            <!-- Date Filter -->
            <div class="col-12 col-md-3">
                <label class="form-label">
                    <i class="bi bi-calendar3"></i> Date
                </label>
                <select class="form-select" id="dateFilter" onchange="applyFilters()">
                    <option value="all">All Dates</option>
                    <option value="today">Today</option>
                    <option value="yesterday">Yesterday</option>
                    <option value="this_week">This Week</option>
                    <option value="last_week">Last Week</option>
                    <option value="this_month">This Month</option>
                    <option value="last_month">Last Month</option>
                    <option value="this_quarter">This Quarter</option>
                    <option value="last_quarter">Last Quarter</option>
                    <option value="this_year">This Year</option>
                    <option value="last_year">Last Year</option>
                </select>
            </div>
            
            <!-- Status Filter -->
            <div class="col-12 col-md-3">
                <label class="form-label">
                    <i class="bi bi-flag"></i> Status
                </label>
                <select class="form-select" id="statusFilter" onchange="applyFilters()">
                    <option value="all">All Status</option>
                    <option value="pending">Pending</option>
                    <option value="processing">Processing</option>
<<<<<<< HEAD
                    <option value="approved">Confirmed</option>
=======
                    <option value="approved">Approved</option>
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
                    <option value="rejected">Rejected</option>
                    <option value="resolved">Resolved</option>
                </select>
            </div>
            
            <!-- Reason Filter -->
            <div class="col-12 col-md-3">
                <label class="form-label">
                    <i class="bi bi-tag"></i> Reason
                </label>
                <select class="form-select" id="reasonFilter" onchange="applyFilters()">
                    <option value="all">All Reasons</option>
                    <option value="damaged">Damaged</option>
                    <option value="expired">Expired</option>
                    <option value="wrong-item">Wrong Item</option>
                    <option value="quality">Quality Issue</option>
                    <option value="overstock">Overstock</option>
                    <option value="other">Other</option>
                </select>
            </div>
            
            <?php if ($rmr_branch_column_exists && $view_all_branches): ?>
            <!-- Branch Filter -->
            <div class="col-12 col-md-3">
                <label class="form-label">
                    <i class="bi bi-building"></i> Branch
                </label>
                <select class="form-select" id="branchFilter" onchange="applyFilters()">
                    <option value="all">All Branches</option>
                    <?php
                    $branches = array_unique(array_column($rmr_requests, 'branch_id'));
                    foreach ($branches as $bid):
                        if (!empty($bid)):
                    ?>
                    <option value="<?= $bid ?>">Branch <?= $bid ?></option>
                    <?php 
                        endif;
                    endforeach; 
                    ?>
                </select>
            </div>
            <?php endif; ?>
            
            <!-- Action Buttons -->
            <div class="col-12">
                <div class="d-flex justify-content-end gap-2 mt-2">
                    <button class="btn btn-outline-primary btn-sm" onclick="printRMRReport()">
                        <i class="bi bi-printer me-1"></i> Print
                    </button>
                    <button class="btn btn-outline-success btn-sm" onclick="exportRMRToExcel()">
                        <i class="bi bi-file-earmark-excel me-1"></i> Export to Excel
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<<<<<<< HEAD
=======
<!-- Filter Summary (shows when filters are active) -->
<div id="filterSummary" class="filter-summary" style="display: none;">
    <i class="bi bi-funnel-fill"></i>
    <span id="filterSummaryText">Active filters applied</span>
    <button class="clear-filters" onclick="clearAllFilters()">
        <i class="bi bi-x-circle"></i> Clear All
    </button>
</div>
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
               <div class="category-tabs">
    <div class="category-tab active" data-tab="rmr-list">
        <i class="bi bi-list-ul me-1"></i> RMR List
        <span class="tab-badge" id="rmrCountBadge"><?= count($rmr_requests) ?></span>
    </div>
    <div class="category-tab" data-tab="rejected-deliveries">
        <i class="bi bi-exclamation-triangle me-1"></i> Rejected Deliveries
        <span class="tab-badge" id="rejectedCountBadge"><?= count($rejected_deliveries) ?></span>
    </div>
</div>

                <!-- Tab Content -->
                <div class="tab-content-wrapper" id="rmrTabsContent">
                    <!-- RMR List Tab -->
                     <div class="tab-pane active" id="rmr-list-content">
                        <div class="table-container">
                            <table class="table custom-table compact-table" id="rmrTable">
                                <thead>
                                    <tr>
                                        <th class="col-rmr">RMR NUMBER</th>
                                        <th class="col-customer">CUSTOMER</th>
                                        <th class="col-item">ITEM</th>
                                        <?php if ($rmr_branch_column_exists && $view_all_branches): ?>
                                            <th class="col-branch">BRANCH</th>
                                        <?php endif; ?>
                                        <th class="col-qty">QTY</th>
                                        <th class="col-amount">TOTAL AMOUNT</th>
                                        <th class="col-reason">REASON</th>
                                        <th class="col-status">STATUS</th>
                                        <th class="col-received">RECEIVED DATE</th>
                                        <th class="col-actions">ACTIONS</th>
                                    </tr>
                                </thead>
                                <tbody id="rmrTableBody">
                                    <?php if (empty($rmr_requests)): ?>
                                    <tr>
                                        <td colspan="<?= ($rmr_branch_column_exists && $view_all_branches) ? '10' : '9' ?>" class="empty-state-table">
                                            <i class="bi bi-inbox"></i>
                                            <h5>No Returned Merchandise Requests</h5>
                                            <p class="text-muted">
                                                RMR requests are created from rejected deliveries.
                                                <?php if ($rmr_branch_column_exists && !$view_all_branches): ?>
                                                    <br>No requests found for your branch.
                                                <?php endif; ?>
                                            </p>
                                        </td>
                                    </tr>
                                    <?php else: ?>
                                        <?php foreach ($rmr_requests as $rmr): 
                                            $totalAmount = $rmr['return_quantity'] * $rmr['unit_price'];
                                        ?>
                                        <tr class="rmr-row" 
                                            data-id="<?= $rmr['rmr_id'] ?>"
                                            data-rmr-number="<?= htmlspecialchars($rmr['rmr_number']) ?>"
                                            data-status="<?= $rmr['rmr_status'] ?>"
                                            data-reason="<?= $rmr['return_reason'] ?>"
                                            data-received-date="<?= $rmr['received_date'] ?>"
                                            data-quantity="<?= $rmr['return_quantity'] ?>"
                                            data-branch="<?= $rmr['branch_id'] ?? '' ?>">
                                            <td class="col-rmr"><strong><?= htmlspecialchars($rmr['rmr_number']) ?></strong></td>
                                            <td class="col-customer"><?= htmlspecialchars($rmr['customer_name']) ?></td>
                                            <td class="col-item">
                                                <?= htmlspecialchars($rmr['item_name']) ?>
                                                <small class="d-block text-muted"><?= htmlspecialchars($rmr['item_code']) ?></small>
                                            </td>
                                            <?php if ($rmr_branch_column_exists && $view_all_branches): ?>
                                                <td class="col-branch">
                                                    <span class="badge bg-info">
                                                        <?= htmlspecialchars($rmr['branch_name'] ?? 'Branch ' . $rmr['branch_id']) ?>
                                                    </span>
                                                </td>
                                            <?php endif; ?>
                                            <td class="col-qty"><?= $rmr['return_quantity'] ?> <?= getUnitText($rmr['unit_type']) ?></td>
                                            <td class="col-amount">₱<?= number_format($totalAmount, 2) ?></td>
                                            <td class="col-reason">
                                                <span class="return-reason <?= getReturnReasonClass($rmr['return_reason']) ?>">
                                                    <?= getReturnReasonText($rmr['return_reason']) ?>
                                                </span>
                                            </td>
                                            <td class="col-status">
                                                <span class="status-badge <?= getRMRStatusClass($rmr['rmr_status']) ?>">
                                                    <?= getRMRStatusText($rmr['rmr_status']) ?>
                                                </span>
                                            </td>
                                            <td class="col-received"><?= formatDate($rmr['received_date']) ?></td>
                                            <td class="col-actions">
                                                <div class="action-buttons">
                                                    <?php if ($rmr['rmr_status'] === 'pending'): ?>
                                                        <button class="btn-action btn-process" onclick="processRMR(<?= $rmr['rmr_id'] ?>)" title="Process">
                                                            <i class="bi bi-gear"></i>
                                                        </button>
                                                    <?php elseif ($rmr['rmr_status'] === 'processing'): ?>
                                                        <button class="btn-action btn-approve" onclick="showApprovalModal(<?= $rmr['rmr_id'] ?>, 'approve')" title="Confirm">
                                                            <i class="bi bi-check-circle"></i>
                                                        </button>
                                                        <button class="btn-action btn-reject" onclick="showApprovalModal(<?= $rmr['rmr_id'] ?>, 'reject')" title="Reject">
                                                            <i class="bi bi-x-circle"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                   <!-- Rejected Deliveries Tab -->
<div class="tab-pane" id="rejected-deliveries-content" style="display: none;">
    <div class="table-container">
        <table class="table custom-table compact-table" id="rejectedTable">
            <thead>
                 <tr>
                    <th>DELIVERY ID</th>
                    <th>ORDER #</th>
                    <th>CUSTOMER</th>
                    <th>TRIP #</th>
                    <th>DELIVERY DATE</th>
                    <th>PHOTO</th>
                    <th>STATUS</th>
                    <th>ACTIONS</th>
                 </tr>
            </thead>
            <tbody>
                <?php if (empty($rejected_deliveries)): ?>
                <tr>
                    <td colspan="8" class="empty-state-table">
                        <i class="bi bi-check-circle"></i>
                        <h5>No Rejected Deliveries Found</h5>
                        <p class="text-muted">
                            All rejected deliveries have been processed.
                            <?php if ($delivery_branch_column_exists && !$view_all_branches): ?>
                                <br>No rejected deliveries found for your branch.
                            <?php endif; ?>
                        </p>
                        <?php if (isset($_GET['debug'])): ?>
                        <p class="text-danger">Debug: Check query and database connection</p>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php else: ?>
                    <?php foreach ($rejected_deliveries as $delivery): ?>
<<<<<<< HEAD
                    <tr class="rejected-row"
                        data-delivery-date="<?= htmlspecialchars($delivery['delivery_date'] ?? '') ?>"
                        data-status="rejected"
                        data-branch="<?= htmlspecialchars($delivery['branch_id'] ?? '') ?>">
=======
                    <tr>
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
                        <td><span class="badge bg-light text-dark">#<?= $delivery['delivery_id'] ?></span></td>
                        <td data-so-id="<?= $delivery['so_id'] ?? 0 ?>"><strong><?= htmlspecialchars($delivery['so_number'] ?? 'N/A') ?></strong></td>
                        <td data-customer-id="<?= $delivery['customer_id'] ?? 0 ?>"><?= htmlspecialchars($delivery['customer_name'] ?? 'Unknown') ?></td>
                        <td><?= htmlspecialchars($delivery['trip_number'] ?? 'N/A') ?></td>
                        <td><?= $delivery['delivery_date'] ? date('Y-m-d H:i', strtotime($delivery['delivery_date'])) : 'N/A' ?></td>
                        <td>
                            <?php if (!empty($delivery['rejection_photo'])): ?>
                                <img src="../uploads/rejections/<?= basename($delivery['rejection_photo']); ?>" 
                                     class="photo-thumbnail" 
                                     onclick="openPhotoModal('<?= basename($delivery['rejection_photo']); ?>')"
                                     alt="Rejection photo"
                                     title="Click to view full size">
                            <?php else: ?>
                                <div class="photo-placeholder">
                                    <i class="bi bi-image"></i>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge bg-danger">Rejected</span>
                        </td>
                        <td>
                            <div class="action-buttons">
                                <?php if ($delivery['has_rmr'] > 0): ?>
                                    <span class="badge bg-info">RMR Created</span>
                                <?php else: ?>
<<<<<<< HEAD
=======
                                    <button class="btn-action btn-view" onclick='viewRejectedDelivery(<?= $delivery['delivery_id'] ?>, "<?= addslashes($delivery['rejection_photo'] ?? '') ?>", "<?= addslashes(trim(preg_replace('/\s+/', ' ', $delivery['rejection_reason'] ?? 'No rejection reason provided'))) ?>", <?= json_encode($delivery['remarks'] ?? 'No remarks provided') ?>)' title="View Details">
                                        <i class="bi bi-eye"></i>
                                    </button>
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
                                    <button class="btn-action btn-create" onclick="showCreateRMRModal(<?= $delivery['delivery_id'] ?>, <?= $delivery['so_id'] ?? 0 ?>, <?= $delivery['customer_id'] ?? 0 ?>)" title="Create RMR">
                                        <i class="bi bi-plus-circle"></i>
                                    </button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

    <!-- View Rejected Delivery Modal - Modern Style like Current Inventory -->
<div class="modal fade" id="viewRejectedDeliveryModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-exclamation-triangle me-2"></i>Rejected Delivery Details
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="rejectedDeliveryDetails">
                <!-- Details will be loaded here -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" onclick="createRMRFromView()" id="createRMRFromViewBtn">
                    <i class="bi bi-plus-circle me-1"></i> Create RMR
                </button>
            </div>
        </div>
    </div>
</div>
    <!-- Photo View Modal (Bigger but not too wide) -->
    <div class="modal fade" id="photoViewModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered" style="max-width: 600px;">
            <div class="modal-content">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title"><i class="bi bi-image me-2"></i>Rejection Photo</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body text-center p-3">
                    <img src="" id="modalPhoto" class="img-fluid rounded" alt="Rejection photo" style="max-height: 450px; width: auto; margin: 0 auto;">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-primary" onclick="downloadPhoto()">
                        <i class="bi bi-download"></i> Download
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Debug Information Panel (only visible with debug parameter) -->
    <?php if (isset($_GET['debug'])): ?>
    <div class="container mt-4">
        <div class="debug-panel">
            <h5><i class="bi bi-bug"></i> Debug Information</h5>
            <hr>
            <div class="row">
                <div class="col-md-6">
                    <h6>Session/Branch Info:</h6>
                    <ul>
                        <li><strong>Branch ID:</strong> <?= $branch_id ?></li>
                        <li><strong>View All Branches:</strong> <?= $view_all_branches ? 'Yes' : 'No' ?></li>
                        <li><strong>Delivery Branch Column Exists:</strong> <?= $delivery_branch_column_exists ? 'Yes' : 'No' ?></li>
                        <li><strong>RMR Branch Column Exists:</strong> <?= $rmr_branch_column_exists ? 'Yes' : 'No' ?></li>
                    </ul>
                    
                    <h6 class="mt-3">Query Results:</h6>
                    <ul>
                        <li><strong>RMR Requests Found:</strong> <?= count($rmr_requests) ?></li>
                        <li><strong>Rejected Deliveries Found:</strong> <?= count($rejected_deliveries) ?></li>
                        <li><strong>Items Available:</strong> <?= count($items_list) ?></li>
                    </ul>
                </div>
                <div class="col-md-6">
                    <h6>Rejected Deliveries Query:</h6>
                    <pre><?= htmlspecialchars($rejected_deliveries_query) ?></pre>
                    
                    <?php if (count($rejected_deliveries) > 0): ?>
                    <h6 class="mt-3">First Rejected Delivery Data:</h6>
                    <pre><?php print_r($rejected_deliveries[0]); ?></pre>
                    <?php endif; ?>
                    
                    <?php if ($conn->error): ?>
                    <div class="alert alert-danger mt-3">
                        <strong>Database Error:</strong> <?= $conn->error ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Process RMR Modal -->
    <div class="modal fade" id="processRMRModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="bi bi-gear me-2"></i>Process RMR</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>Process the selected RMR for quality inspection?</p>
                    <?php if ($rmr_branch_column_exists && !$view_all_branches): ?>
                        <div class="alert alert-info mb-3">
                            <i class="bi bi-info-circle me-2"></i>
                            Processing RMR for Branch <?= $branch_id ?>
                        </div>
                    <?php endif; ?>
                    <div class="mb-3">
                        <label class="form-label">Inspector Name *</label>
                        <input type="text" class="form-control" id="inspectorName" value="Quality Control Dept" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Inspection Type *</label>
                        <select class="form-select" id="inspectionType" required>
                            <option value="visual">Visual Inspection</option>
                            <option value="functional">Functional Test</option>
                            <option value="lab">Laboratory Test</option>
                            <option value="sample">Sample Testing</option>
                        </select>
                    </div>
                    <div class="alert alert-info mb-0">
                        <i class="bi bi-info-circle me-2"></i>
                        This will change RMR status to "Processing".
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="confirmProcessRMR()">Start Processing</button>
                </div>
            </div>
        </div>
    </div>

   <!-- Create RMR from Rejected Delivery Modal - Modern Style like Current Inventory -->
<div class="modal fade" id="createRMRModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-plus-circle me-2"></i>Create RMR from Rejected Delivery
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="createRMRForm">
                    <input type="hidden" id="rmrDeliveryId" name="delivery_id">
                    <input type="hidden" id="rmrSoId" name="so_id">
                    <input type="hidden" id="rmrCustomerId" name="customer_id">
                    <input type="hidden" name="branch_id" value="<?= $branch_id ?>">
                    
                    <?php if ($rmr_branch_column_exists && !$view_all_branches): ?>
                        <div class="alert alert-info mb-3">
                            <i class="bi bi-info-circle me-2"></i>
                            Creating RMR for Branch <?= $branch_id ?>
                        </div>
                    <?php endif; ?>
                    
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Select Item *</label>
                            <select class="form-select" id="rmrItemId" name="item_id" required>
                                <option value="">-- Select Item --</option>
                                <?php foreach ($items_list as $item): ?>
                                <option value="<?= $item['item_id'] ?>" 
                                        data-price="<?= $item['unit_price'] ?>"
                                        data-code="<?= htmlspecialchars($item['item_code']) ?>">
                                    <?= htmlspecialchars($item['item_code'] . ' - ' . $item['item_name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Return Quantity *</label>
                            <input type="number" class="form-control" id="rmrQuantity" name="return_quantity" min="1" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Return Reason *</label>
                            <select class="form-select" id="rmrReason" name="return_reason" required>
                                <option value="">-- Select Reason --</option>
                                <option value="damaged">Damaged</option>
                                <option value="expired">Expired</option>
                                <option value="wrong-item">Wrong Item</option>
                                <option value="quality">Quality Issue</option>
                                <option value="overstock">Overstock</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Reason Details</label>
                            <textarea class="form-control" id="rmrReasonDetails" name="reason_details" rows="3" placeholder="Provide additional details about the return..."></textarea>
                        </div>
                    </div>
                    
                    <div class="alert alert-warning mt-3">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        This will create a Returned Merchandise Request (RMR) for this rejected delivery.
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="confirmCreateRMR()">Create RMR</button>
            </div>
        </div>
    </div>
</div>

    <!-- Approve/Reject Modal -->
    <div class="modal fade" id="approvalModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header" id="approvalModalHeader">
                    <h5 class="modal-title" id="approvalModalTitle">Approve RMR</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p id="approvalMessage">Approve the selected RMR for credit/refund?</p>
                    <?php if ($rmr_branch_column_exists && !$view_all_branches): ?>
                        <div class="alert alert-info mb-3">
                            <i class="bi bi-info-circle me-2"></i>
                            Approving RMR for Branch <?= $branch_id ?>
                        </div>
                    <?php endif; ?>
                    <div id="approvalFields">
                        <div class="mb-3">
                            <label class="form-label">Disposition *</label>
                            <select class="form-select" id="dispositionType">
                                <option value="credit">Credit to Customer (Return to Stock)</option>
                                <option value="refund">Cash Refund (Return to Stock)</option>
                                <option value="replacement">Replacement (Return to Stock)</option>
                                <option value="disposal">Destroy Item</option>
                                <option value="return-to-supplier">Return to Supplier</option>
                            </select>
                            <small class="text-muted">Confirm only. Stock will be returned through Receive Inventory &gt; Returned Merchandise.</small>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Approved Amount *</label>
                            <input type="number" class="form-control" id="approvedAmount" min="0" step="0.01" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Approval Notes</label>
                            <textarea class="form-control" id="approvalNotes" rows="2"></textarea>
                        </div>
                    </div>
                    <div id="rejectionFields" style="display: none;">
                        <div class="mb-3">
                            <label class="form-label">Rejection Reason *</label>
                            <textarea class="form-control" id="rejectionReason" rows="3" placeholder="Enter reason for rejection..."></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-success" id="approveBtn" onclick="confirmApproval('approve')">Approve</button>
                    <button type="button" class="btn btn-danger" id="rejectBtn" onclick="confirmApproval('reject')" style="display: none;">Reject</button>
                </div>
            </div>
        </div>
    </div>

    <!-- View RMR Details Modal -->
    <div class="modal fade" id="viewRMRModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title"><i class="bi bi-eye me-2"></i>RMR Details</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="rmrDetailsContent">
                    <!-- RMR details will be loaded here -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-primary" onclick="printRMRDetails()">
                        <i class="bi bi-printer me-1"></i> Print RMR
                    </button>
                    <button type="button" class="btn btn-warning" id="editFromViewBtn" onclick="editRMRFromView()" style="display: none;">Edit</button>
                </div>
            </div>
        </div>
    </div>

<<<<<<< HEAD
<!-- Mobile Bottom Navigation - Clean Version (No Arrows) -->
<div class="mobile-nav" id="mobileNav">
    <ul class="nav">
        <!-- Dashboard -->
        <li class="nav-item">
            <a class="nav-link active" href="branchdashboard.php">
                <i class="bi bi-speedometer2"></i>
                <span>Dashboard</span>
            </a>
        </li>

        <!-- Warehouse Dropdown -->
        <li class="nav-item dropdown-more" id="warehouseMobileDropdown">
            <a class="nav-link more-btn" href="#" onclick="toggleMobileDropdown(event, 'warehouseMobileMenu')">
                <i class="bi bi-shop"></i>
                <span>Warehouse</span>
            </a>
            <div class="more-dropdown" id="warehouseMobileMenu">
                <a href="current_inventory.php" class="dropdown-item">
                    <i class="bi bi-bar-chart-line"></i><span>Current Inventory</span>
                </a>
                <a href="bad_orders.php" class="dropdown-item">
                    <i class="bi bi-recycle"></i><span>Bad Orders</span>
                </a>
                <a href="pick_list_items.php" class="dropdown-item">
                    <i class="bi bi-list-check"></i><span>Pick List Items</span>
                </a>
                <a href="warehouses.php" class="dropdown-item">
                    <i class="bi bi-shop"></i><span>Warehouses</span>
                </a>
            </div>
        </li>

        <!-- Supplier Dropdown -->
        <li class="nav-item dropdown-more" id="supplierMobileDropdown">
            <a class="nav-link more-btn" href="#" onclick="toggleMobileDropdown(event, 'supplierMobileMenu')">
                <i class="bi bi-building"></i>
                <span>Supplier</span>
            </a>
            <div class="more-dropdown" id="supplierMobileMenu">
                <a href="purchase_order.php" class="dropdown-item">
                    <i class="bi bi-box"></i><span>Receive Inventory</span>
                </a>
                <a href="supplier.php" class="dropdown-item">
                    <i class="bi bi-people"></i><span>Supplier List</span>
                </a>
            </div>
        </li>

        <!-- Customer Dropdown -->
        <li class="nav-item dropdown-more" id="customerMobileDropdown">
            <a class="nav-link more-btn" href="#" onclick="toggleMobileDropdown(event, 'customerMobileMenu')">
                <i class="bi bi-people"></i>
                <span>Customer</span>
            </a>
            <div class="more-dropdown" id="customerMobileMenu">
                <a href="customer_list.php" class="dropdown-item">
                    <i class="bi bi-person-badge"></i><span>Customer List</span>
                </a>
                <a href="approve_credit_requests.php" class="dropdown-item">
                    <i class="bi bi-pencil-square"></i><span>Approve Credit Request</span>
                </a>
                <a href="sales_order.php" class="dropdown-item">
                    <i class="bi bi-cart"></i><span>Sales Order</span>
                </a>
                <a href="collections.php" class="dropdown-item">
                    <i class="bi bi-cash-stack"></i><span>Collections</span>
                </a>
            </div>
        </li>

        <!-- Delivery Dropdown -->
        <li class="nav-item dropdown-more" id="deliveryMobileDropdown">
            <a class="nav-link more-btn" href="#" onclick="toggleMobileDropdown(event, 'deliveryMobileMenu')">
                <i class="bi bi-truck"></i>
                <span>Delivery</span>
            </a>
            <div class="more-dropdown" id="deliveryMobileMenu">
                <a href="trip_tickets.php" class="dropdown-item">
                    <i class="bi bi-ticket-perforated"></i><span>Trip Tickets</span>
                </a>
            </div>
        </li>

        <!-- Banking Dropdown -->
        <li class="nav-item dropdown-more" id="bankingMobileDropdown">
            <a class="nav-link more-btn" href="#" onclick="toggleMobileDropdown(event, 'bankingMobileMenu')">
                <i class="bi bi-bank2"></i>
                <span>Banking</span>
            </a>
            <div class="more-dropdown" id="bankingMobileMenu">
                <a href="deposit.php" class="dropdown-item">
                    <i class="bi bi-arrow-down-circle"></i><span>Deposit</span>
                </a>
                <a href="Withdrawal.php" class="dropdown-item">
                    <i class="bi bi-arrow-up-circle"></i><span>Withdrawal</span>
                </a>
                <a href="bank_statement.php" class="dropdown-item">
                    <i class="bi bi-receipt"></i><span>Bank Statement</span>
                </a>
                <a href="expenses.php" class="dropdown-item">
                    <i class="bi bi-cash-stack"></i><span>Expenses</span>
                </a>
            </div>
        </li>
        
                <!-- Shared Services -->
         <li class="nav-item dropdown-more" id="sharedServicesMobileDropdown">
            <a class="nav-link more-btn" href="#" onclick="toggleMobileDropdown(event, 'sharedServicesMobileMenu')">
                <i class="bi bi-grid-3x3-gap"></i>
                <span>Shared Services</span>
            </a>
            <div class="more-dropdown" id="sharedServicesMobileMenu">
                <a class="dropdown-item" href="motorpool.php">
                    <i class="bi bi-truck"></i>
                    <span class="nav-text">Motorpool</span>
                </a>
                <a class="dropdown-item" href="central_warehouse.php">
                    <i class="bi bi-box-seam"></i>
                    <span class="nav-text">Central Warehouse</span>
                </a>
            </div>  
         </li>

        <!-- Users -->
        <li class="nav-item">
            <a class="nav-link" href="drivers.php">
                <i class="bi bi-people-fill"></i>
                <span>Users</span>
            </a>
        </li>

        <!-- Profile / Logout -->
        <li class="nav-item" id="profileMobileBtn">
            <a href="#" class="nav-link"
                data-bs-toggle="modal"
                data-bs-target="#profileModal">
                    <i class="bi bi-box-arrow-right"></i>
                    <span>Logout</span>
                </a>
        </li>
    </ul>
</div>

    <!-- Mobile Profile Modal -->
    <div class="modal fade" id="profileModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h5 class="modal-title"><i class="bi bi-person-circle me-2"></i>User Profile</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body text-center"><div class="user-avatar-large mb-3"><?php echo $user_initials; ?></div><h4 class="mb-1"><?php echo htmlspecialchars($user_name); ?></h4><p class="text-muted mb-3"><span class="badge bg-success"><?php echo ucfirst($user_role); ?></span></p><?php if (!$view_all_branches && $branch_id > 0): ?><div class="branch-info mb-3"><i class="bi bi-building me-1"></i><span><?php echo htmlspecialchars($branch_name); ?></span></div><?php endif; ?><div class="user-id text-muted small mb-4"><i class="bi bi-hash"></i> User ID: <?php echo $user_id; ?></div><button class="btn btn-danger btn-lg w-100" onclick="confirmLogout()"><i class="bi bi-box-arrow-right me-2"></i>Logout</button></div></div></div></div>
=======
        <!-- Mobile Bottom Navigation -->
    <div class="mobile-nav" id="mobileNav">
        <ul class="nav">
            <li class="nav-item dropdown-more" id="inventoryDropdown"><a class="nav-link more-btn" href="#" onclick="toggleDropdown(event, 'inventoryDropdownMenu')"><i class="bi bi-box-seam"></i><span>Inventory</span></a><div class="more-dropdown" id="inventoryDropdownMenu"><a href="current_inventory.php" class="dropdown-item"><i class="bi bi-bar-chart-line"></i><span>Current Inventory</span></a><a href="bad_orders.php" class="dropdown-item"><i class="bi bi-recycle"></i><span>Bad Orders</span></a></div></li>
            <li class="nav-item dropdown-more" id="salesDropdown"><a class="nav-link more-btn" href="#" onclick="toggleDropdown(event, 'salesDropdownMenu')"><i class="bi bi-cart"></i><span>Sales</span></a><div class="more-dropdown" id="salesDropdownMenu"><a href="sales_order.php" class="dropdown-item"><i class="bi bi-cart"></i><span>Sales Orders</span></a><a href="pick_list_items.php" class="dropdown-item"><i class="bi bi-list-check"></i><span>Pick Lists</span></a></div></li>
            <li class="nav-item dropdown-more" id="purchaseDropdown"><a class="nav-link more-btn" href="#" onclick="toggleDropdown(event, 'purchaseDropdownMenu')"><i class="bi bi-truck"></i><span>Purchase</span></a><div class="more-dropdown" id="purchaseDropdownMenu" style="right: 0 !important; left: auto !important;"><a href="purchase_order.php" class="dropdown-item"><i class="bi bi-box"></i><span>Purchase Orders</span></a><a href="supplier.php" class="dropdown-item"><i class="bi bi-building"></i><span>Suppliers</span></a></div></li>
            <li class="nav-item"><a class="nav-link" href="trip_tickets.php"><i class="bi bi-ticket-perforated"></i><span>Trips</span></a></li>
            <li class="nav-item dropdown-more" id="moreDropdown"><a class="nav-link more-btn" href="#" onclick="toggleDropdown(event, 'moreDropdownMenu')"><i class="bi bi-three-dots-vertical"></i><span>More</span></a><div class="more-dropdown" id="moreDropdownMenu"><a href="drivers.php" class="dropdown-item"><i class="bi bi-people"></i><span>Users</span></a><a href="approve_credit_requests.php" class="dropdown-item"><i class="bi bi-pencil-square"></i><span>Approve Requests</span></a><div class="dropdown-divider"></div><a href="#" class="dropdown-item logout-item" onclick="showProfileModal(); return false;"><i class="bi bi-box-arrow-right"></i><span>Logout</span></a></div></li>
        </ul>
    </div>

     <!-- Mobile Profile Modal -->
        <div class="modal fade" id="profileModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h5 class="modal-title"><i class="bi bi-person-circle me-2"></i>User Profile</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body text-center"><div class="user-avatar-large mb-3"><?php echo $user_initials; ?></div><h4 class="mb-1"><?php echo htmlspecialchars($user_name); ?></h4><p class="text-muted mb-3"><span class="badge bg-success"><?php echo ucfirst($user_role); ?></span></p><?php if (!$view_all_branches && $branch_id > 0): ?><div class="branch-info mb-3"><i class="bi bi-building me-1"></i><span><?php echo htmlspecialchars($branch_name); ?></span></div><?php endif; ?><div class="user-id text-muted small mb-4"></div><button class="btn btn-danger btn-lg w-100" onclick="confirmLogout()"><i class="bi bi-box-arrow-right me-2"></i>Logout</button></div></div></div></div>
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    
   <script>
    // ========== GLOBAL VARIABLES ==========
    let selectedRMR = null;
    let currentAction = null;
    let activeFilters = { date: 'all', status: 'all', reason: 'all', branch: 'all' };
    const branchId = <?php echo $branch_id; ?>;
    const viewAllBranches = <?php echo $view_all_branches ? 'true' : 'false'; ?>;
    const rmrBranchColumnExists = <?php echo $rmr_branch_column_exists ? 'true' : 'false'; ?>;
    const customersBranchColumnExists = <?php echo $customers_branch_column_exists ? 'true' : 'false'; ?>;
    const itemsBranchColumnExists = <?php echo $items_branch_column_exists ? 'true' : 'false'; ?>;
    const logoBase64 = '<?php echo $logo_base64; ?>';
    
    // ========== SIDEBAR FUNCTIONS ==========
    function toggleSidebar() {
        const sidebar = document.getElementById('sidebar');
        const isMobile = window.innerWidth <= 992;
        
        if (isMobile) {
            sidebar.classList.toggle('active');
            if (!document.querySelector('.sidebar-overlay')) {
                const overlay = document.createElement('div');
                overlay.className = 'sidebar-overlay';
                document.body.appendChild(overlay);
                overlay.addEventListener('click', closeMobileSidebar);
                setTimeout(() => overlay.classList.add('active'), 10);
            }
        } else {
            sidebar.classList.toggle('collapsed');
            localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
            document.querySelectorAll('.nav-text').forEach(text => {
                text.style.display = sidebar.classList.contains('collapsed') ? 'none' : 'inline-block';
            });
        }
    }

    function closeMobileSidebar() {
        const sidebar = document.getElementById('sidebar');
        const overlay = document.querySelector('.sidebar-overlay');
        sidebar.classList.remove('active');
        if (overlay) {
            overlay.classList.remove('active');
            setTimeout(() => overlay.remove(), 300);
        }
    }

    function initializeSidebar() {
        const sidebar = document.getElementById('sidebar');
        if (window.innerWidth > 992) {
            const savedCollapsed = localStorage.getItem('sidebarCollapsed');
            if (savedCollapsed === 'true') {
                sidebar.classList.add('collapsed');
                document.querySelectorAll('.nav-text').forEach(text => text.style.display = 'none');
            }
        }
    }

    // ========== SHOW LOADING ==========
    function showLoading() {
        document.getElementById('loadingOverlay').style.display = 'flex';
    }

    function hideLoading() {
        document.getElementById('loadingOverlay').style.display = 'none';
    }

<<<<<<< HEAD
    // ========== FILTER FUNCTIONS - FIXED ===========
    function getDateRangeForFilter(filterValue) {
        const now = new Date();
        const today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
        let start = null;
        let end = null;

        switch (filterValue) {
            case 'today':
                start = new Date(today);
                end = new Date(today);
                end.setDate(end.getDate() + 1);
                break;
            case 'yesterday':
                start = new Date(today);
                start.setDate(start.getDate() - 1);
                end = new Date(today);
                break;
            case 'this_week': {
                start = new Date(today);
                const day = start.getDay();
                const diff = day === 0 ? 6 : day - 1; // Monday start
                start.setDate(start.getDate() - diff);
                end = new Date(start);
                end.setDate(end.getDate() + 7);
                break;
            }
            case 'last_week': {
                end = new Date(today);
                const day = end.getDay();
                const diff = day === 0 ? 6 : day - 1; // Monday start
                end.setDate(end.getDate() - diff);
                start = new Date(end);
                start.setDate(start.getDate() - 7);
                break;
            }
            case 'this_month':
                start = new Date(today.getFullYear(), today.getMonth(), 1);
                end = new Date(today.getFullYear(), today.getMonth() + 1, 1);
                break;
            case 'last_month':
                start = new Date(today.getFullYear(), today.getMonth() - 1, 1);
                end = new Date(today.getFullYear(), today.getMonth(), 1);
                break;
            case 'this_quarter': {
                const quarterStartMonth = Math.floor(today.getMonth() / 3) * 3;
                start = new Date(today.getFullYear(), quarterStartMonth, 1);
                end = new Date(today.getFullYear(), quarterStartMonth + 3, 1);
                break;
            }
            case 'last_quarter': {
                const quarterStartMonth = Math.floor(today.getMonth() / 3) * 3;
                end = new Date(today.getFullYear(), quarterStartMonth, 1);
                start = new Date(end.getFullYear(), end.getMonth() - 3, 1);
                break;
            }
            case 'this_year':
                start = new Date(today.getFullYear(), 0, 1);
                end = new Date(today.getFullYear() + 1, 0, 1);
                break;
            case 'last_year':
                start = new Date(today.getFullYear() - 1, 0, 1);
                end = new Date(today.getFullYear(), 0, 1);
                break;
            default:
                return null;
        }

        return { start, end };
    }

    function rowDateMatches(rowDateValue, dateFilter) {
        if (dateFilter === 'all') return true;
        if (!rowDateValue || rowDateValue === 'N/A') return false;

        const normalizedValue = String(rowDateValue).replace(' ', 'T');
        const rowDate = new Date(normalizedValue);
        if (isNaN(rowDate.getTime())) return false;

        const range = getDateRangeForFilter(dateFilter);
        if (!range) return true;

        return rowDate >= range.start && rowDate < range.end;
    }

=======
    // ========== FILTER FUNCTIONS - FIXED ==========
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
    function applyFilters() {
        const dateFilter = document.getElementById('dateFilter')?.value || 'all';
        const statusFilter = document.getElementById('statusFilter')?.value || 'all';
        const reasonFilter = document.getElementById('reasonFilter')?.value || 'all';
        const branchFilter = document.getElementById('branchFilter')?.value || 'all';
<<<<<<< HEAD

        activeFilters = { date: dateFilter, status: statusFilter, reason: reasonFilter, branch: branchFilter };

        updateFilterCountBadge();
        updateFilterSummary();

        let visibleRMRCount = 0;
        const rmrRows = document.querySelectorAll('#rmrTableBody tr.rmr-row');

        rmrRows.forEach(row => {
=======
        
        // Update active filters
        activeFilters = { date: dateFilter, status: statusFilter, reason: reasonFilter, branch: branchFilter };
        
        updateFilterCountBadge();
        updateFilterSummary();
        
        const rows = document.querySelectorAll('.rmr-row');
        let visibleCount = 0;
        
        rows.forEach(row => {
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
            let showRow = true;

            if (statusFilter !== 'all') {
                const rowStatus = row.dataset.status || '';
                showRow = rowStatus === statusFilter;
            }

            if (showRow && reasonFilter !== 'all') {
                const rowReason = row.dataset.reason || '';
                showRow = rowReason === reasonFilter;
            }

            if (showRow && rmrBranchColumnExists && viewAllBranches && branchFilter !== 'all') {
<<<<<<< HEAD
                const rowBranch = row.dataset.branch || '';
                showRow = String(rowBranch) === String(branchFilter);
=======
                const rowBranch = row.dataset.branch;
                if (String(rowBranch) !== branchFilter) showRow = false;
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
            }

            if (showRow && dateFilter !== 'all') {
<<<<<<< HEAD
                showRow = rowDateMatches(row.dataset.receivedDate || '', dateFilter);
=======
                const rowDate = row.dataset.receivedDate;
                if (rowDate) {
                    const date = new Date(rowDate);
                    const today = new Date();
                    today.setHours(0, 0, 0, 0);
                    
                    switch(dateFilter) {
                        case 'today':
                            if (date.toDateString() !== today.toDateString()) showRow = false;
                            break;
                        case 'yesterday':
                            const yesterday = new Date(today);
                            yesterday.setDate(yesterday.getDate() - 1);
                            if (date.toDateString() !== yesterday.toDateString()) showRow = false;
                            break;
                        case 'this_week':
                            const startOfWeek = new Date(today);
                            startOfWeek.setDate(today.getDate() - today.getDay());
                            const endOfWeek = new Date(today);
                            endOfWeek.setDate(today.getDate() + (6 - today.getDay()));
                            if (date < startOfWeek || date > endOfWeek) showRow = false;
                            break;
                        case 'this_month':
                            if (date.getMonth() !== today.getMonth() || date.getFullYear() !== today.getFullYear()) showRow = false;
                            break;
                        case 'last_month':
                            const lastMonth = new Date(today);
                            lastMonth.setMonth(lastMonth.getMonth() - 1);
                            if (date.getMonth() !== lastMonth.getMonth() || date.getFullYear() !== lastMonth.getFullYear()) showRow = false;
                            break;
                    }
                }
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
            }

            row.style.display = showRow ? '' : 'none';
            if (showRow) visibleRMRCount++;
        });

        toggleNoResultsRow('rmrTableBody', 'noResultsRow', visibleRMRCount, rmrBranchColumnExists && viewAllBranches ? 10 : 9, 'No RMR requests match the selected filters');

        let visibleRejectedCount = 0;
        const rejectedRows = document.querySelectorAll('#rejectedTable tbody tr.rejected-row');

        rejectedRows.forEach(row => {
            let showRow = true;

            // Rejected Deliveries are always rejected, so only show them when status is All or Rejected.
            if (statusFilter !== 'all' && statusFilter !== 'rejected') {
                showRow = false;
            }

            // Reason filter is for RMR return reason only. Rejected deliveries have no RMR reason yet.
            if (showRow && reasonFilter !== 'all') {
                showRow = false;
            }

            if (showRow && rmrBranchColumnExists && viewAllBranches && branchFilter !== 'all') {
                const rowBranch = row.dataset.branch || '';
                showRow = String(rowBranch) === String(branchFilter);
            }

            if (showRow && dateFilter !== 'all') {
                showRow = rowDateMatches(row.dataset.deliveryDate || '', dateFilter);
            }

            row.style.display = showRow ? '' : 'none';
            if (showRow) visibleRejectedCount++;
        });

        toggleNoResultsRow('rejectedTable', 'noRejectedResultsRow', visibleRejectedCount, 8, 'No rejected deliveries match the selected filters', true);

        const rmrCountBadge = document.getElementById('rmrCountBadge');
        const rejectedCountBadge = document.getElementById('rejectedCountBadge');
        if (rmrCountBadge) rmrCountBadge.textContent = visibleRMRCount;
        if (rejectedCountBadge) rejectedCountBadge.textContent = visibleRejectedCount;
    }

    function toggleNoResultsRow(tableOrBodyId, rowId, visibleCount, colspan, message, useTableId = false) {
        const tableBody = useTableId
            ? document.querySelector(`#${tableOrBodyId} tbody`)
            : document.getElementById(tableOrBodyId);
        if (!tableBody) return;

        const hasRealRows = tableBody.querySelectorAll('tr.rmr-row, tr.rejected-row').length > 0;
        let noResultsRow = document.getElementById(rowId);

        if (visibleCount === 0 && hasRealRows) {
            if (!noResultsRow) {
                noResultsRow = document.createElement('tr');
                noResultsRow.id = rowId;
                noResultsRow.innerHTML = `<td colspan="${colspan}" class="text-center py-4 text-muted">
                    <i class="bi bi-inbox fs-1"></i><br>${message}
                </td>`;
                tableBody.appendChild(noResultsRow);
            }
            noResultsRow.style.display = '';
        } else if (noResultsRow) {
            noResultsRow.style.display = 'none';
        }
    }

    function initFilterToggle() {
    const filterHeader = document.getElementById('filterHeader');
    const filterContent = document.getElementById('filterContent');
    const filterToggleBtn = document.getElementById('filterToggleBtn');
    const filterIcon = document.getElementById('filterIcon');
    
    if (filterHeader && filterContent) {
        // Set initial state - collapsed
        filterContent.classList.add('collapsed');
        if (filterToggleBtn) filterToggleBtn.setAttribute('aria-expanded', 'false');
        
        // Make the entire header clickable
        filterHeader.addEventListener('click', function(e) {
            // Don't toggle if clicking on the button itself (to avoid double toggle)
            if (e.target.closest('.filter-toggle-btn')) {
                e.stopPropagation();
            }
            toggleFilterContent();
        });
        
        // Also keep the button click as a fallback
        if (filterToggleBtn) {
            filterToggleBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                toggleFilterContent();
            });
        }
    }
    
    function toggleFilterContent() {
        const isExpanded = filterContent.classList.contains('collapsed');
        
        if (isExpanded) {
            // Expand
            filterContent.classList.remove('collapsed');
            if (filterToggleBtn) filterToggleBtn.setAttribute('aria-expanded', 'true');
            if (filterIcon) {
                filterIcon.classList.remove('bi-chevron-down');
                filterIcon.classList.add('bi-chevron-up');
            }
        } else {
            // Collapse
            filterContent.classList.add('collapsed');
            if (filterToggleBtn) filterToggleBtn.setAttribute('aria-expanded', 'false');
            if (filterIcon) {
                filterIcon.classList.remove('bi-chevron-up');
                filterIcon.classList.add('bi-chevron-down');
            }
        }
    }
}

    function updateFilterCountBadge() {
        const filterBadge = document.getElementById('filterCountBadge');
        if (filterBadge) {
            const activeCount = Object.values(activeFilters).filter(v => v !== 'all' && v !== '' && v !== null).length;
            filterBadge.textContent = activeCount;
            
            if (activeCount > 0) {
                filterBadge.style.background = '#dc3545';
                filterBadge.style.transform = 'scale(1.05)';
                setTimeout(() => {
                    filterBadge.style.transform = '';
                }, 200);
            } else {
                filterBadge.style.background = '#44d34e';
            }
        }
    }

    function updateFilterSummary() {
        const filterSummary = document.getElementById('filterSummary');
        const filterSummaryText = document.getElementById('filterSummaryText');
        
        if (filterSummary && filterSummaryText) {
            const activeCount = Object.values(activeFilters).filter(v => v !== 'all' && v !== '' && v !== null).length;
            
            if (activeCount > 0) {
                let summaryParts = [];
                
                if (activeFilters.date && activeFilters.date !== 'all') {
                    const dateSelect = document.getElementById('dateFilter');
                    const dateText = dateSelect?.options[dateSelect.selectedIndex]?.text || activeFilters.date;
                    summaryParts.push(`📅 ${dateText}`);
                }
                if (activeFilters.status && activeFilters.status !== 'all') {
                    const statusSelect = document.getElementById('statusFilter');
                    const statusText = statusSelect?.options[statusSelect.selectedIndex]?.text || activeFilters.status;
                    summaryParts.push(`📊 ${statusText}`);
                }
                if (activeFilters.reason && activeFilters.reason !== 'all') {
                    const reasonSelect = document.getElementById('reasonFilter');
                    const reasonText = reasonSelect?.options[reasonSelect.selectedIndex]?.text || activeFilters.reason;
                    summaryParts.push(`🏷️ ${reasonText}`);
                }
                if (activeFilters.branch && activeFilters.branch !== 'all') {
                    const branchSelect = document.getElementById('branchFilter');
                    if (branchSelect) {
                        const branchText = branchSelect.options[branchSelect.selectedIndex]?.text || activeFilters.branch;
                        summaryParts.push(`🏢 ${branchText}`);
                    }
                }
                
                filterSummaryText.textContent = summaryParts.join(' • ');
                filterSummary.style.display = 'flex';
            } else {
                filterSummary.style.display = 'none';
            }
        }
    }

    function clearAllFilters() {
        const dateFilter = document.getElementById('dateFilter');
        const statusFilter = document.getElementById('statusFilter');
        const reasonFilter = document.getElementById('reasonFilter');
        const branchFilter = document.getElementById('branchFilter');
        
        if (dateFilter) dateFilter.value = 'all';
        if (statusFilter) statusFilter.value = 'all';
        if (reasonFilter) reasonFilter.value = 'all';
        if (branchFilter) branchFilter.value = 'all';
        
        activeFilters = { date: 'all', status: 'all', reason: 'all', branch: 'all' };
        
        updateFilterCountBadge();
        updateFilterSummary();
        applyFilters();
    }

    // ========== TAP TO VIEW FUNCTIONALITY ==========
    function initTapToView() {
        const rmrRows = document.querySelectorAll('#rmr-list-content .table-container tbody tr.rmr-row');
        rmrRows.forEach(row => {
            row.removeEventListener('click', handleRMRRowClick);
            row.addEventListener('click', handleRMRRowClick);
        });
        
        const rejectedRows = document.querySelectorAll('#rejected-deliveries-content .table-container tbody tr');
        rejectedRows.forEach(row => {
            row.removeEventListener('click', handleRejectedRowClick);
            row.addEventListener('click', handleRejectedRowClick);
        });
        
        // Show/hide "no results" message
        const tableBody = document.getElementById('rmrTableBody');
        const noResultsRow = document.getElementById('noResultsRow');
        
        if (visibleCount === 0) {
            if (!noResultsRow && tableBody) {
                const tr = document.createElement('tr');
                tr.id = 'noResultsRow';
                const colspan = rmrBranchColumnExists && viewAllBranches ? 9 : 8;
                tr.innerHTML = `<td colspan="${colspan}" class="text-center py-4 text-muted">
                    <i class="bi bi-inbox fs-1"></i><br>No RMR requests match the selected filters
                 </td>`;
                tableBody.appendChild(tr);
            } else if (noResultsRow) {
                noResultsRow.style.display = '';
            }
        } else {
            if (noResultsRow) {
                noResultsRow.style.display = 'none';
            }
        }
    }

<<<<<<< HEAD
    function handleRMRRowClick(event) {
        if (event.target.closest('.btn-action')) {
            return;
        }
        const row = event.currentTarget;
        const rmrId = row.getAttribute('data-id');
        if (rmrId) {
            viewRMR(rmrId);
        }
    }

    function handleRejectedRowClick(event) {
        if (event.target.closest('.btn-action') || event.target.closest('.photo-thumbnail')) {
            return;
        }
        const row = event.currentTarget;
        const deliveryId = row.querySelector('td:first-child .badge')?.innerText.replace('#', '') || '';
        const soId = row.querySelector('td:nth-child(2)')?.getAttribute('data-so-id') || '0';
        const customerId = row.querySelector('td:nth-child(3)')?.getAttribute('data-customer-id') || '0';
        const photoPath = row.querySelector('.photo-thumbnail')?.getAttribute('src')?.split('/').pop() || '';
        const rejectionReason = row.querySelector('td:nth-child(7) .badge')?.innerText || 'No rejection reason provided';
        const remarks = row.querySelector('td:nth-child(2)')?.getAttribute('data-remarks') || 'No remarks provided';
        
        if (deliveryId) {
            viewRejectedDelivery(parseInt(deliveryId), photoPath, rejectionReason, remarks);
        }
    }

=======
    function initFilterToggle() {
        const filterToggleBtn = document.getElementById('filterToggleBtn');
        const filterContent = document.getElementById('filterContent');
        
        if (filterToggleBtn && filterContent) {
            // Start collapsed on page load
            filterContent.classList.add('collapsed');
            filterToggleBtn.setAttribute('aria-expanded', 'false');
            
            filterToggleBtn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                const expanded = this.getAttribute('aria-expanded') === 'true';
                this.setAttribute('aria-expanded', !expanded);
                
                if (filterContent.classList.contains('collapsed')) {
                    filterContent.classList.remove('collapsed');
                } else {
                    filterContent.classList.add('collapsed');
                }
            });
        }
    }

    function updateFilterCountBadge() {
        const filterBadge = document.getElementById('filterCountBadge');
        if (filterBadge) {
            const activeCount = Object.values(activeFilters).filter(v => v !== 'all' && v !== '' && v !== null).length;
            filterBadge.textContent = activeCount;
            
            if (activeCount > 0) {
                filterBadge.style.background = '#dc3545';
                filterBadge.style.transform = 'scale(1.05)';
                setTimeout(() => {
                    filterBadge.style.transform = '';
                }, 200);
            } else {
                filterBadge.style.background = '#44d34e';
            }
        }
    }

    function updateFilterSummary() {
        const filterSummary = document.getElementById('filterSummary');
        const filterSummaryText = document.getElementById('filterSummaryText');
        
        if (filterSummary && filterSummaryText) {
            const activeCount = Object.values(activeFilters).filter(v => v !== 'all' && v !== '' && v !== null).length;
            
            if (activeCount > 0) {
                let summaryParts = [];
                
                if (activeFilters.date && activeFilters.date !== 'all') {
                    const dateSelect = document.getElementById('dateFilter');
                    const dateText = dateSelect?.options[dateSelect.selectedIndex]?.text || activeFilters.date;
                    summaryParts.push(`📅 ${dateText}`);
                }
                if (activeFilters.status && activeFilters.status !== 'all') {
                    const statusSelect = document.getElementById('statusFilter');
                    const statusText = statusSelect?.options[statusSelect.selectedIndex]?.text || activeFilters.status;
                    summaryParts.push(`📊 ${statusText}`);
                }
                if (activeFilters.reason && activeFilters.reason !== 'all') {
                    const reasonSelect = document.getElementById('reasonFilter');
                    const reasonText = reasonSelect?.options[reasonSelect.selectedIndex]?.text || activeFilters.reason;
                    summaryParts.push(`🏷️ ${reasonText}`);
                }
                if (activeFilters.branch && activeFilters.branch !== 'all') {
                    const branchSelect = document.getElementById('branchFilter');
                    if (branchSelect) {
                        const branchText = branchSelect.options[branchSelect.selectedIndex]?.text || activeFilters.branch;
                        summaryParts.push(`🏢 ${branchText}`);
                    }
                }
                
                filterSummaryText.textContent = summaryParts.join(' • ');
                filterSummary.style.display = 'flex';
            } else {
                filterSummary.style.display = 'none';
            }
        }
    }

    function clearAllFilters() {
        const dateFilter = document.getElementById('dateFilter');
        const statusFilter = document.getElementById('statusFilter');
        const reasonFilter = document.getElementById('reasonFilter');
        const branchFilter = document.getElementById('branchFilter');
        
        if (dateFilter) dateFilter.value = 'all';
        if (statusFilter) statusFilter.value = 'all';
        if (reasonFilter) reasonFilter.value = 'all';
        if (branchFilter) branchFilter.value = 'all';
        
        activeFilters = { date: 'all', status: 'all', reason: 'all', branch: 'all' };
        
        updateFilterCountBadge();
        updateFilterSummary();
        applyFilters();
    }

    // ========== TAP TO VIEW FUNCTIONALITY ==========
    function initTapToView() {
        const rmrRows = document.querySelectorAll('#rmr-list-content .table-container tbody tr.rmr-row');
        rmrRows.forEach(row => {
            row.removeEventListener('click', handleRMRRowClick);
            row.addEventListener('click', handleRMRRowClick);
        });
        
        const rejectedRows = document.querySelectorAll('#rejected-deliveries-content .table-container tbody tr');
        rejectedRows.forEach(row => {
            row.removeEventListener('click', handleRejectedRowClick);
            row.addEventListener('click', handleRejectedRowClick);
        });
    }

    function handleRMRRowClick(event) {
        if (event.target.closest('.btn-action')) {
            return;
        }
        const row = event.currentTarget;
        const rmrId = row.getAttribute('data-id');
        if (rmrId) {
            viewRMR(rmrId);
        }
    }

    function handleRejectedRowClick(event) {
        if (event.target.closest('.btn-action') || event.target.closest('.photo-thumbnail')) {
            return;
        }
        const row = event.currentTarget;
        const deliveryId = row.querySelector('td:first-child .badge')?.innerText.replace('#', '') || '';
        const soId = row.querySelector('td:nth-child(2)')?.getAttribute('data-so-id') || '0';
        const customerId = row.querySelector('td:nth-child(3)')?.getAttribute('data-customer-id') || '0';
        const photoPath = row.querySelector('.photo-thumbnail')?.getAttribute('src')?.split('/').pop() || '';
        const rejectionReason = row.querySelector('td:nth-child(7) .badge')?.innerText || 'No rejection reason provided';
        const remarks = row.querySelector('td:nth-child(2)')?.getAttribute('data-remarks') || 'No remarks provided';
        
        if (deliveryId) {
            viewRejectedDelivery(parseInt(deliveryId), photoPath, rejectionReason, remarks);
        }
    }

>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
    // ========== MAIN RMR FUNCTIONS ==========
    function showCreateRMRModal(deliveryId, soId, customerId) {
        document.getElementById('rmrDeliveryId').value = deliveryId;
        document.getElementById('rmrSoId').value = soId;
        document.getElementById('rmrCustomerId').value = customerId;
        document.getElementById('createRMRForm').reset();
        new bootstrap.Modal(document.getElementById('createRMRModal')).show();
    }

    function confirmCreateRMR() {
        const itemId = document.getElementById('rmrItemId').value;
        const quantity = document.getElementById('rmrQuantity').value;
        const reason = document.getElementById('rmrReason').value;
        
        if (!itemId) {
            Swal.fire('Warning', 'Please select an item', 'warning');
            return;
        }
        if (!quantity || quantity <= 0) {
            Swal.fire('Warning', 'Please enter a valid quantity', 'warning');
            return;
        }
        if (!reason) {
            Swal.fire('Warning', 'Please select a return reason', 'warning');
            return;
        }
        
        showLoading();
        const formData = new FormData(document.getElementById('createRMRForm'));
        formData.append('action', 'create_rmr_from_delivery');
        
        fetch('bad_orders.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            hideLoading();
            if (data.success) {
                Swal.fire({ icon: 'success', title: 'Success!', text: data.message, timer: 2000, showConfirmButton: false })
                    .then(() => {
                        bootstrap.Modal.getInstance(document.getElementById('createRMRModal')).hide();
                        location.reload();
                    });
            } else {
                Swal.fire('Error', data.message, 'error');
            }
        })
        .catch(error => {
            hideLoading();
            Swal.fire('Error', 'An error occurred while creating RMR', 'error');
        });
    }

    function processRMR(id) {
        selectedRMR = id;
        new bootstrap.Modal(document.getElementById('processRMRModal')).show();
    }

    function confirmProcessRMR() {
        const inspectorName = document.getElementById('inspectorName').value;
        const inspectionType = document.getElementById('inspectionType').value;
        
        if (!inspectorName) {
            Swal.fire('Warning', 'Inspector Name is required', 'warning');
            return;
        }
        
        showLoading();
        const formData = new FormData();
        formData.append('action', 'process_rmr');
        formData.append('rmr_id', selectedRMR);
        formData.append('inspector_name', inspectorName);
        formData.append('inspection_type', inspectionType);
        
        fetch('bad_orders.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            hideLoading();
            if (data.success) {
                Swal.fire({ icon: 'success', title: 'Success!', text: data.message, timer: 2000, showConfirmButton: false })
                    .then(() => {
                        bootstrap.Modal.getInstance(document.getElementById('processRMRModal')).hide();
                        location.reload();
                    });
            } else {
                Swal.fire('Error', data.message, 'error');
            }
        })
        .catch(error => {
            hideLoading();
            Swal.fire('Error', 'An error occurred while processing RMR', 'error');
        });
    }

    function showApprovalModal(id, action) {
        selectedRMR = id;
        currentAction = action;
        
        const modalTitle = document.getElementById('approvalModalTitle');
        const modalHeader = document.getElementById('approvalModalHeader');
        const approvalMessage = document.getElementById('approvalMessage');
        const approvalFields = document.getElementById('approvalFields');
        const rejectionFields = document.getElementById('rejectionFields');
        const approveBtn = document.getElementById('approveBtn');
        const rejectBtn = document.getElementById('rejectBtn');
        
        if (action === 'approve') {
            modalTitle.textContent = 'Approve RMR';
            modalHeader.className = 'modal-header bg-success text-white';
            approvalMessage.textContent = 'Approve the selected RMR?';
            approvalFields.style.display = 'block';
            rejectionFields.style.display = 'none';
            approveBtn.style.display = 'inline-block';
            rejectBtn.style.display = 'none';
            
            const row = document.querySelector(`.rmr-row[data-id="${id}"]`);
            if (row) {
                const amountCell = row.querySelector('.col-amount');
                if (amountCell) {
                    const amount = amountCell.innerText.replace('₱', '').replace(/,/g, '');
                    document.getElementById('approvedAmount').value = parseFloat(amount).toFixed(2);
                }
            }
        } else {
            modalTitle.textContent = 'Reject RMR';
            modalHeader.className = 'modal-header bg-danger text-white';
            approvalMessage.textContent = 'Reject the selected RMR?';
            approvalFields.style.display = 'none';
            rejectionFields.style.display = 'block';
            approveBtn.style.display = 'none';
            rejectBtn.style.display = 'inline-block';
        }
        
        new bootstrap.Modal(document.getElementById('approvalModal')).show();
    }

    function confirmApproval(action) {
        if (action === 'approve') {
            const dispositionType = document.getElementById('dispositionType').value;
            const approvedAmount = document.getElementById('approvedAmount').value;
            const approvalNotes = document.getElementById('approvalNotes').value;
            
            if (!approvedAmount || approvedAmount <= 0) {
                Swal.fire('Warning', 'Please enter a valid approved amount', 'warning');
                return;
            }
            
            showLoading();
            const formData = new FormData();
            formData.append('action', 'approve_rmr');
            formData.append('rmr_id', selectedRMR);
            formData.append('disposition_type', dispositionType);
            formData.append('approved_amount', approvedAmount);
            formData.append('approval_notes', approvalNotes);
            
            fetch('bad_orders.php', { method: 'POST', body: formData })
                .then(response => response.json())
                .then(data => {
                    hideLoading();
                    if (data.success) {
                        Swal.fire({ icon: 'success', title: 'Approved!', text: data.message, timer: 2000, showConfirmButton: false })
                            .then(() => {
                                bootstrap.Modal.getInstance(document.getElementById('approvalModal')).hide();
                                location.reload();
                            });
                    } else {
                        Swal.fire('Error', data.message, 'error');
                    }
                })
                .catch(error => {
                    hideLoading();
                    Swal.fire('Error', 'An error occurred while approving RMR', 'error');
                });
        } else {
            const rejectionReason = document.getElementById('rejectionReason').value;
            if (!rejectionReason) {
                Swal.fire('Warning', 'Please enter a rejection reason', 'warning');
                return;
            }
            
            showLoading();
            const formData = new FormData();
            formData.append('action', 'reject_rmr');
            formData.append('rmr_id', selectedRMR);
            formData.append('rejection_reason', rejectionReason);
            
            fetch('bad_orders.php', { method: 'POST', body: formData })
                .then(response => response.json())
                .then(data => {
                    hideLoading();
                    if (data.success) {
                        Swal.fire({ icon: 'success', title: 'Rejected!', text: data.message, timer: 2000, showConfirmButton: false })
                            .then(() => {
                                bootstrap.Modal.getInstance(document.getElementById('approvalModal')).hide();
                                location.reload();
                            });
                    } else {
                        Swal.fire('Error', data.message, 'error');
                    }
                })
                .catch(error => {
                    hideLoading();
                    Swal.fire('Error', 'An error occurred while rejecting RMR', 'error');
                });
        }
    }

    function viewRMR(id) {
        showLoading();
        const formData = new FormData();
        formData.append('action', 'view_rmr');
        formData.append('rmr_id', id);
        
        fetch('bad_orders.php', { method: 'POST', body: formData })
            .then(response => response.json())
            .then(data => {
                hideLoading();
                if (data.success) {
                    const rmr = data.rmr;
                    const approval = data.approval;
                    const totalAmount = rmr.return_quantity * rmr.unit_price;
                    
                    let branchHtml = '';
                    if (rmr.branch_name) {
                        branchHtml = `<tr><td class="detail-label">Branch:</td><td><span class="badge bg-info">${rmr.branch_name}</span></td></tr>`;
                    }
                    
                    let deliveryHtml = '';
                    if (rmr.delivery_id) {
                        deliveryHtml = `<tr><td class="detail-label">Source Delivery:</td><td>#${rmr.delivery_id}</td></tr>`;
                    }
                    
                    let approvalHtml = '';
                    if (approval) {
                        approvalHtml = `
                            <div class="alert alert-success mt-3">
                                <h6><i class="bi bi-check-circle me-2"></i>Approval Details</h6>
                                <p><strong>Approved Amount:</strong> ₱${Number(approval.approved_amount).toFixed(2)}</p>
                                <p><strong>Approved By:</strong> User ID: ${approval.approved_by}</p>
                                <p><strong>Approved At:</strong> ${new Date(approval.approved_at).toLocaleString()}</p>
                                ${approval.approval_notes ? `<p><strong>Notes:</strong> ${approval.approval_notes}</p>` : ''}
                            </div>
                        `;
                    }
                    
                    const content = document.getElementById('rmrDetailsContent');
                    content.innerHTML = `
                        <div class="row">
                            <div class="col-md-6">
                                <div class="rmr-details-card">
                                    <h6 class="fw-bold mb-3">RMR Information</h6>
                                    <table class="table table-sm table-borderless">
                                        <tr><td width="40%" class="detail-label">RMR Number:</td><td class="detail-value">${rmr.rmr_number}</td></tr>
                                        ${branchHtml}
                                        ${deliveryHtml}
                                        <tr><td class="detail-label">Status:</td><td><span class="status-badge ${getStatusClass(rmr.rmr_status)}">${getStatusText(rmr.rmr_status)}</span></td></tr>
                                        <tr><td class="detail-label">Received Date:</td><td>${new Date(rmr.received_date).toLocaleString()}</td></tr>
                                        <tr><td class="detail-label">Received By:</td><td>${rmr.received_by_name || 'N/A'}</td></tr>
                                    </table>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="rmr-details-card">
                                    <h6 class="fw-bold mb-3">Customer & Item Details</h6>
                                    <table class="table table-sm table-borderless">
                                        <tr><td width="40%" class="detail-label">Customer:</td><td>${rmr.customer_name}</td></tr>
                                        <tr><td class="detail-label">Item Code:</td><td>${rmr.item_code}</td></tr>
                                        <tr><td class="detail-label">Item Name:</td><td>${rmr.item_name}</td></tr>
                                        <tr><td class="detail-label">Unit Type:</td><td>${rmr.unit_type}</td></tr>
                                    </table>
                                </div>
                            </div>
                        </div>
                        <div class="row mt-2">
                            <div class="col-md-6">
                                <div class="rmr-details-card">
                                    <h6 class="fw-bold mb-3">Return Details</h6>
                                    <table class="table table-sm table-borderless">
                                        <tr><td width="40%" class="detail-label">Return Quantity:</td><td>${rmr.return_quantity} ${rmr.unit_type}</td></tr>
                                        <tr><td class="detail-label">Unit Price:</td><td>₱${Number(rmr.unit_price).toFixed(2)}</td></tr>
                                        <tr><td class="detail-label">Total Amount:</td><td class="fw-bold">₱${Number(totalAmount).toFixed(2)}</td></tr>
                                        <tr><td class="detail-label">Return Reason:</td><td><span class="return-reason ${getReasonClass(rmr.return_reason)}">${getReasonText(rmr.return_reason)}</span></td></tr>
                                    </table>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="rmr-details-card">
                                    <h6 class="fw-bold mb-3">Inspection Details</h6>
                                    <table class="table table-sm table-borderless">
                                        <tr><td width="40%" class="detail-label">Inspector:</td><td>${rmr.inspector_name || 'N/A'}</td></tr>
                                        <tr><td class="detail-label">Inspection Type:</td><td>${rmr.inspection_type ? rmr.inspection_type.charAt(0).toUpperCase() + rmr.inspection_type.slice(1) : 'N/A'}</td></tr>
                                        <tr><td class="detail-label">Disposition:</td><td>${rmr.disposition_type ? getDispositionText(rmr.disposition_type) : 'N/A'}</td></tr>
                                        <tr><td class="detail-label">Reason Details:</td><td>${rmr.reason_details || 'N/A'}</td></tr>
                                    </table>
                                </div>
                            </div>
                        </div>
                        ${approvalHtml}
                    `;
                    
                    selectedRMR = id;
                    const editBtn = document.getElementById('editFromViewBtn');
                    editBtn.style.display = (rmr.rmr_status === 'pending' || rmr.rmr_status === 'processing') ? 'inline-block' : 'none';
                    
                    new bootstrap.Modal(document.getElementById('viewRMRModal')).show();
                } else {
                    Swal.fire('Error', data.message, 'error');
                }
            })
            .catch(error => {
                hideLoading();
                Swal.fire('Error', 'An error occurred while fetching RMR details', 'error');
            });
    }

    // ========== VIEW REJECTED DELIVERY - SINGLE VERSION (FIXED FOR MOBILE) ==========
    function viewRejectedDelivery(deliveryId, photoPath, rejectionReason, remarks) {
        console.log("viewRejectedDelivery called with:", {deliveryId, photoPath, rejectionReason, remarks});
        
        // Build correct image path
        let imageUrl = '';
        if (photoPath && photoPath !== '') {
            // Get just the filename if full path is passed
            const filename = photoPath.split('/').pop();
            imageUrl = '../uploads/rejections/' + filename;
            console.log("Image URL:", imageUrl);
        }
        
        const deliveryData = {
            id: deliveryId,
            order_number: 'N/A',
            customer: 'N/A',
            trip_number: 'N/A',
            delivery_date: 'N/A',
            remarks: remarks || 'No remarks provided',
            rejection_reason: rejectionReason || 'No rejection reason provided',
            photo: imageUrl,
            status: 'Rejected'
        };
        
        // Try to get data from the table row if available
        const rows = document.querySelectorAll('#rejected-deliveries-content .table-container tbody tr');
        for (let r of rows) {
            const idCell = r.querySelector('td:first-child .badge');
            if (idCell && idCell.innerText.replace('#', '') == deliveryId) {
                const cells = r.querySelectorAll('td');
                deliveryData.order_number = cells[1]?.innerText.trim() || 'N/A';
                deliveryData.customer = cells[2]?.innerText.trim() || 'N/A';
                deliveryData.trip_number = cells[3]?.innerText.trim() || 'N/A';
                deliveryData.delivery_date = cells[4]?.innerText.trim() || 'N/A';
                break;
            }
        }
        
        // Clean remarks
        let cleanRemarks = deliveryData.remarks;
        if (deliveryData.rejection_reason && deliveryData.rejection_reason !== 'No rejection reason provided') {
            cleanRemarks = cleanRemarks.replace('REASON: ' + deliveryData.rejection_reason, '');
            cleanRemarks = cleanRemarks.replace('REASON:', '');
        }
        cleanRemarks = cleanRemarks.replace(/\[PHOTO:.*?\]/g, '');
        cleanRemarks = cleanRemarks.replace(/\s+/g, ' ').trim();
        
        let formattedRemarks = '<div class="details-remarks">';
        if (cleanRemarks && cleanRemarks !== 'No remarks provided') {
            const lines = cleanRemarks.split(/(?=\[)|(?=REJECTED by)|(?=DETAILS:)|(?=PROPOSED ACTION:)|(?=RETRY DATE:)/);
            lines.forEach(line => {
                line = line.trim();
                if (line) {
                    formattedRemarks += `<div class="remark-line">${escapeHtml(line)}</div>`;
                }
            });
        } else {
            formattedRemarks += `<div class="remark-line">${escapeHtml(deliveryData.remarks)}</div>`;
        }
        formattedRemarks += '</div>';
        
        // Rejection reason HTML
        let reasonHtml = '';
        if (deliveryData.rejection_reason && deliveryData.rejection_reason !== 'No rejection reason provided') {
            reasonHtml = `<div class="p-3 bg-light rounded" style="max-height: 100px; overflow-y: auto; font-size: 13px;">${escapeHtml(deliveryData.rejection_reason).replace(/\n/g, '<br>')}</div>`;
        } else {
            reasonHtml = `<div class="p-3 bg-light rounded">${escapeHtml(deliveryData.rejection_reason)}</div>`;
        }
        
        // Image HTML
        let imageHtml = '';
        if (deliveryData.photo && deliveryData.photo !== '') {
            imageHtml = `
                <div class="rejection-image-container text-center">
                    <h6 class="fw-bold mb-3"><i class="bi bi-camera"></i> Rejection Photo</h6>
                    <div class="d-flex justify-content-center">
                        <img src="${deliveryData.photo}" 
                             class="rejection-image img-fluid" 
                             alt="Rejection Photo" 
                             onclick="event.stopPropagation(); openPhotoModal('${deliveryData.photo.split('/').pop()}')"
                             style="max-width: 100%; max-height: 300px; cursor: pointer; border: 2px solid #dee2e6; border-radius: 8px; object-fit: contain; background: #f8f9fa;"
                             onerror="this.onerror=null; this.src='data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' width=\'100\' height=\'100\' viewBox=\'0 0 24 24\' fill=\'none\' stroke=\'%236c757d\' stroke-width=\'2\' stroke-linecap=\'round\' stroke-linejoin=\'round\'%3E%3Crect x=\'2\' y=\'2\' width=\'20\' height=\'20\' rx=\'2.18\' ry=\'2.18\'%3E%3C/rect%3E%3Ccircle cx=\'8.5\' cy=\'8.5\' r=\'1.5\'%3E%3C/circle%3E%3Cpolyline points=\'21 15 16 10 5 21\'%3E%3C/polyline%3E%3C/svg%3E'; this.style.objectFit='contain'; this.style.padding='20px';">
                    </div>
                </div>
            `;
        } else {
            imageHtml = `
                <div class="no-image-placeholder text-center">
                    <i class="bi bi-image" style="font-size: 48px;"></i>
                    <p class="mt-2">No photo available for this rejection</p>
                </div>
            `;
        }
        
        // Populate modal
        const content = document.getElementById('rejectedDeliveryDetails');
        content.innerHTML = `
            <div class="row">
                <div class="col-md-6">
                    <div class="rmr-details-card">
                        <h6 class="fw-bold mb-3"><i class="bi bi-truck"></i> Delivery Information</h6>
                        <table class="table table-sm table-borderless">
                            <tr><td width="40%" class="detail-label">Delivery ID:</td><td class="detail-value">#${escapeHtml(deliveryData.id)}</td></tr>
                            <tr><td class="detail-label">Order Number:</td><td><strong>${escapeHtml(deliveryData.order_number)}</strong></td></tr>
                            <tr><td class="detail-label">Trip Number:</td><td>${escapeHtml(deliveryData.trip_number)}</td></tr>
                            <tr><td class="detail-label">Delivery Date:</td><td>${escapeHtml(deliveryData.delivery_date)}</td></tr>
                            <tr><td class="detail-label">Status:</td><td><span class="badge bg-danger">${escapeHtml(deliveryData.status)}</span></td></tr>
                        </table>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="rmr-details-card">
                        <h6 class="fw-bold mb-3"><i class="bi bi-person"></i> Customer Information</h6>
                        <table class="table table-sm table-borderless">
                            <tr><td width="40%" class="detail-label">Customer Name:</td><td>${escapeHtml(deliveryData.customer)}</td></tr>
                        </table>
                    </div>
                </div>
            </div>
            <div class="row mt-2">
                <div class="col-12">
                    <div class="rmr-details-card">
                        <h6 class="fw-bold mb-3"><i class="bi bi-exclamation-diamond"></i> Rejection Reason</h6>
                        ${reasonHtml}
                    </div>
                </div>
            </div>
            <div class="row mt-2">
                <div class="col-12">
                    <div class="rmr-details-card">
                        <h6 class="fw-bold mb-3"><i class="bi bi-chat"></i> Remarks</h6>
                        ${formattedRemarks}
                    </div>
                </div>
            </div>
            <div class="row mt-2">
                <div class="col-12">
                    ${imageHtml}
                </div>
            </div>
        `;
        
        const createBtn = document.getElementById('createRMRFromViewBtn');
        createBtn.setAttribute('data-delivery-id', deliveryId);
        createBtn.setAttribute('data-so-id', deliveryData.order_number !== 'N/A' ? deliveryData.order_number : '0');
        createBtn.setAttribute('data-customer-id', deliveryData.customer !== 'N/A' ? deliveryData.customer : '0');
        
        new bootstrap.Modal(document.getElementById('viewRejectedDeliveryModal')).show();
    }

    function openPhotoModal(photoPath) {
        const modal = new bootstrap.Modal(document.getElementById('photoViewModal'));
        const modalImg = document.getElementById('modalPhoto');
        const fullPath = '../uploads/rejections/' + photoPath;
        modalImg.src = fullPath;
        modal.show();
    }

    function downloadPhoto() {
        const img = document.getElementById('modalPhoto');
        const photoPath = img.src;
        const fileName = photoPath.split('/').pop();
        
        Swal.fire({ title: 'Downloading...', text: 'Please wait', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        
        fetch(photoPath)
            .then(response => response.blob())
            .then(blob => {
                const url = window.URL.createObjectURL(blob);
                const link = document.createElement('a');
                link.href = url;
                link.download = fileName;
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
                window.URL.revokeObjectURL(url);
                Swal.close();
                Swal.fire({ icon: 'success', title: 'Download Complete', text: 'Photo downloaded successfully', timer: 1500, showConfirmButton: false });
            })
            .catch(error => {
                Swal.close();
                Swal.fire({ icon: 'error', title: 'Download Failed', text: 'Unable to download the photo' });
            });
    }

    function createRMRFromView() {
        const btn = document.getElementById('createRMRFromViewBtn');
        const deliveryId = btn.getAttribute('data-delivery-id');
        const soId = btn.getAttribute('data-so-id');
        const customerId = btn.getAttribute('data-customer-id');
        
        bootstrap.Modal.getInstance(document.getElementById('viewRejectedDeliveryModal')).hide();
        setTimeout(() => showCreateRMRModal(deliveryId, soId, customerId), 300);
    }

    function editRMRFromView() {
        bootstrap.Modal.getInstance(document.getElementById('viewRMRModal')).hide();
        setTimeout(() => {
            if (selectedRMR) {
                const row = document.querySelector(`.rmr-row[data-id="${selectedRMR}"]`);
                if (row) {
                    const status = row.dataset.status;
                    if (status === 'pending') processRMR(selectedRMR);
                    else if (status === 'processing') showApprovalModal(selectedRMR, 'approve');
                }
            }
        }, 300);
    }

    // ========== HELPER FUNCTIONS ==========
    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function getStatusClass(status) {
        const classes = { 'pending': 'status-pending', 'processing': 'status-processing', 'approved': 'status-approved', 'rejected': 'status-rejected', 'resolved': 'status-resolved' };
        return classes[status] || 'status-pending';
    }

    function getStatusText(status) {
        const texts = { 'pending': 'Pending', 'processing': 'Processing', 'approved': 'Approved', 'rejected': 'Rejected', 'resolved': 'Resolved' };
        return texts[status] || status;
    }

    function getReasonClass(reason) {
        const classes = { 'damaged': 'reason-damaged', 'expired': 'reason-expired', 'wrong-item': 'reason-wrong-item', 'quality': 'reason-quality', 'overstock': 'reason-overstock', 'other': 'reason-other' };
        return classes[reason] || 'reason-other';
    }

    function getReasonText(reason) {
        const texts = { 'damaged': 'Damaged', 'expired': 'Expired', 'wrong-item': 'Wrong Item', 'quality': 'Quality Issue', 'overstock': 'Overstock', 'other': 'Other' };
        return texts[reason] || reason;
    }

    function getDispositionText(disposition) {
        const texts = { 'credit': 'Credit to Customer (Return to Stock)', 'refund': 'Cash Refund (Return to Stock)', 'replacement': 'Replacement (Return to Stock)', 'disposal': 'Destroy Item', 'return-to-supplier': 'Return to Supplier' };
        return texts[disposition] || disposition;
    }

    // ========== PRINT FUNCTIONS ==========
    function printRMRReport() {
        const printBtn = document.querySelector('.btn-outline-primary[onclick="printRMRReport()"]');
        if (printBtn) {
            printBtn.innerHTML = '<i class="bi bi-printer"></i> Preparing...';
            printBtn.disabled = true;
        }
        
        const filterData = {
            date: document.getElementById('dateFilter').value,
            status: document.getElementById('statusFilter').value,
            reason: document.getElementById('reasonFilter').value,
            branch: document.getElementById('branchFilter')?.value || 'all'
        };
        
        showLoading();
        const formData = new FormData();
        formData.append('action', 'print_rmr');
        formData.append('filter_data', JSON.stringify(filterData));
        
        fetch('bad_orders.php', { method: 'POST', body: formData })
            .then(response => response.json())
            .then(data => {
                hideLoading();
                if (data.success && data.items.length > 0) {
                    const htmlContent = generatePrintHTML(data.items, data.branch_name, data.view_all, data.rmr_branch_column_exists);
                    const iframe = document.getElementById('printFrame');
                    const iframeDoc = iframe.contentWindow.document;
                    iframeDoc.open();
                    iframeDoc.write(htmlContent);
                    iframeDoc.close();
                    setTimeout(() => iframe.contentWindow.print(), 250);
                } else {
                    Swal.fire({ icon: 'warning', title: 'No Data', text: 'No RMR requests match the current filters' });
                }
                if (printBtn) { printBtn.innerHTML = '<i class="bi bi-printer"></i> Print'; printBtn.disabled = false; }
            })
            .catch(error => {
                hideLoading();
                Swal.fire({ icon: 'error', title: 'Error', text: 'An error occurred while preparing print' });
                if (printBtn) { printBtn.innerHTML = '<i class="bi bi-printer"></i> Print'; printBtn.disabled = false; }
            });
    }

    function generatePrintHTML(items, branchName, viewAll, rmrBranchColumnExists) {
        let tableRows = '';
        let totalAmount = 0;
        let totalQuantity = 0;
        
        items.forEach(item => {
            const amount = item.return_quantity * item.unit_price;
            totalAmount += amount;
            totalQuantity += parseInt(item.return_quantity);
            
            tableRows += '<tr>';
            tableRows += `<td style="padding: 3px; border: 1px solid #000;">${item.rmr_number}</td>`;
            tableRows += `<td style="padding: 3px; border: 1px solid #000;">${item.customer_name}</td>`;
            tableRows += `<td style="padding: 3px; border: 1px solid #000;">${item.item_code}<br><small>${item.item_name}</small></td>`;
            if (viewAll && rmrBranchColumnExists) {
                tableRows += `<td style="padding: 3px; border: 1px solid #000;">${item.branch_name || 'Branch ' + item.branch_id}</td>`;
            }
            tableRows += `<td style="padding: 3px; border: 1px solid #000; text-align: center;">${item.return_quantity}</td>`;
            tableRows += `<td style="padding: 3px; border: 1px solid #000; text-align: right;">₱${Number(amount).toFixed(2)}</td>`;
            tableRows += `<td style="padding: 3px; border: 1px solid #000;">${getReasonText(item.return_reason)}</td>`;
            tableRows += `<td style="padding: 3px; border: 1px solid #000;">${getStatusText(item.rmr_status)}</td>`;
            tableRows += `<td style="padding: 3px; border: 1px solid #000;">${new Date(item.received_date).toLocaleDateString()}</td>`;
            tableRows += '</tr>';
        });
        
        const currentDate = new Date().toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
        
        return `<!DOCTYPE html><html><head><meta charset="UTF-8"><title>RMR Report</title><style>body{font-family:Arial;margin:0;padding:0;font-size:9px}.print-container{max-width:100%;margin:0}.print-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:5px;border-bottom:1px solid #000;padding-bottom:3px}.logo-section{display:flex;align-items:center;gap:5px}.company-logo{width:30px;height:auto}.company-info h1{font-size:14px;margin:0}.company-info p{font-size:8px;margin:0}.report-title h2{font-size:12px;margin:0}.report-title .date-info{font-size:8px}.summary-box{border:1px solid #000;padding:3px;margin-bottom:5px;display:flex}.summary-item{flex:1;text-align:center;border-right:1px solid #000}.summary-item:last-child{border-right:none}.summary-label{font-size:8px;font-weight:bold}.summary-value{font-size:11px;font-weight:bold}table{width:100%;border-collapse:collapse;font-size:8px}th,td{border:1px solid #000;padding:3px}th{text-align:left;font-weight:bold;background:white!important;color:black!important}.total-row{font-weight:bold}.print-footer{margin-top:5px;border-top:1px solid #000;padding-top:3px;display:flex;justify-content:space-between;font-size:8px}</style></head><body><div class="print-container"><div class="print-header"><div class="logo-section"><img src="${logoBase64}" alt="Logo" class="company-logo"><div class="company-info"><h1>AMGC</h1><p>RMR Report</p></div></div><div class="report-title"><h2>RETURNED MERCHANDISE REQUESTS</h2><div class="date-info">${currentDate}</div></div></div><div class="summary-box"><div class="summary-item"><div class="summary-label">Total RMR</div><div class="summary-value">${items.length}</div></div><div class="summary-item"><div class="summary-label">Total Qty</div><div class="summary-value">${totalQuantity}</div></div><div class="summary-item"><div class="summary-label">Total Amount</div><div class="summary-value">₱${totalAmount.toFixed(2)}</div></div><div class="summary-item"><div class="summary-label">Branch</div><div class="summary-value">${!viewAll ? branchName : 'All'}</div></div></div><table><thead><tr><th>RMR #</th><th>Customer</th><th>Item</th>${viewAll && rmrBranchColumnExists ? '<th>Branch</th>' : ''}<th style="text-align:center;">Qty</th><th style="text-align:right;">Amount</th><th>Reason</th><th>Status</th><th>Date</th></tr></thead><tbody>${tableRows}<tr class="total-row"><td colspan="${viewAll && rmrBranchColumnExists ? '3' : '2'}" style="text-align:right;">TOTAL</td><td style="text-align:center;">${totalQuantity}</td><td style="text-align:right;">₱${totalAmount.toFixed(2)}</td><td colspan="${viewAll && rmrBranchColumnExists ? '4' : '3'}"></td></tr></tbody></table><div class="print-footer"><div>Generated: ${currentDate}</div><div>${document.querySelector('.user-name-sidebar')?.textContent || 'Branch Admin'}</div></div></div></body></html>`;
    }

    function printRMRDetails() {
        const content = document.getElementById('rmrDetailsContent').innerHTML;
        const printWindow = window.open('', '_blank');
        printWindow.document.write(`<html><head><title>RMR Details</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet"><style>body{padding:20px}.status-badge{display:inline-block;padding:5px 12px;font-size:12px;border-radius:20px}.status-pending{background:#fff3cd;color:#856404}.status-processing{background:#cce5ff;color:#004085}.status-approved{background:#d4edda;color:#155724}.status-rejected{background:#f8d7da;color:#721c24}.return-reason{display:inline-block;padding:4px 10px;font-size:12px;border-radius:4px}.reason-damaged{background:#f8d7da;color:#721c24}.reason-expired{background:#fff3cd;color:#856404}.reason-wrong-item{background:#d1ecf1;color:#0c5460}.reason-quality{background:#cce5ff;color:#004085}.reason-overstock{background:#e2d5f2;color:#533f7c}.reason-other{background:#e9ecef;color:#495057}</style></head><body><h2 class="mb-4">RMR Details</h2>${content}</body></html>`);
        printWindow.document.close();
        printWindow.print();
    }

    // ========== EXCEL EXPORT ==========
    function exportRMRToExcel() {
        const rows = document.querySelectorAll('.rmr-row:not([style*="display: none"])');
        if (rows.length === 0) {
            Swal.fire('Warning', 'No RMR requests to export', 'warning');
            return;
        }
        
        const excelData = [];
        const headers = ['RMR Number', 'Customer', 'Item Name', 'Item Code', ...(rmrBranchColumnExists && viewAllBranches ? ['Branch'] : []), 'Quantity', 'Unit', 'Total Amount (₱)', 'Return Reason', 'Status', 'Received Date'];
        excelData.push(headers);
        
        rows.forEach(row => {
            const cells = row.querySelectorAll('td');
            let idx = 0;
            const rmrNumber = cells[idx++]?.innerText.trim() || '';
            const customer = cells[idx++]?.innerText.trim() || '';
            const itemName = cells[idx]?.innerText.split('\n')[0].trim() || '';
            const itemCode = cells[idx]?.querySelector('small')?.innerText.trim() || '';
            idx++;
            let branch = '';
            if (rmrBranchColumnExists && viewAllBranches) branch = cells[idx++]?.innerText.trim() || '';
            const qtyCell = cells[idx++]?.innerText.trim() || '';
            const qty = qtyCell.split(' ')[0] || '';
            const unit = qtyCell.split(' ')[1] || '';
            const amount = cells[idx++]?.innerText.replace('₱', '').replace(/,/g, '') || '0';
            const reason = cells[idx++]?.innerText.trim() || '';
            const status = cells[idx++]?.innerText.trim() || '';
            const receivedDate = cells[idx++]?.innerText.trim() || '';
            excelData.push([rmrNumber, customer, itemName, itemCode, ...(rmrBranchColumnExists && viewAllBranches ? [branch] : []), parseInt(qty) || 0, unit, parseFloat(amount), reason, status, receivedDate]);
        });
        
        const wb = XLSX.utils.book_new();
        const ws = XLSX.utils.aoa_to_sheet(excelData);
        ws['!cols'] = [{ wch: 15 }, { wch: 25 }, { wch: 30 }, { wch: 15 }, ...(rmrBranchColumnExists && viewAllBranches ? [{ wch: 12 }] : []), { wch: 10 }, { wch: 8 }, { wch: 15 }, { wch: 15 }, { wch: 15 }, { wch: 20 }];
        XLSX.utils.book_append_sheet(wb, ws, 'RMR Requests');
        XLSX.writeFile(wb, `RMR_Requests_${new Date().toISOString().slice(0,10).replace(/-/g, '')}${rmrBranchColumnExists && !viewAllBranches ? `_Branch_${branchId}` : ''}.xlsx`);
        Swal.fire({ icon: 'success', title: 'Export Complete', text: 'Excel export completed successfully!', timer: 2000, showConfirmButton: false });
    }

    function copySQL(table) {
        const sql = "ALTER TABLE rmr_requests ADD COLUMN branch_id INT NULL;\nALTER TABLE rmr_requests ADD FOREIGN KEY (branch_id) REFERENCES branches(branch_id);";
        navigator.clipboard.writeText(sql).then(() => Swal.fire({ icon: 'success', title: 'Copied!', text: 'SQL copied to clipboard', timer: 1500, showConfirmButton: false }));
    }

    function cleanupModalBackdrops() {
    document.querySelectorAll('.modal-backdrop').forEach(backdrop => backdrop.remove());
    document.body.classList.remove('modal-open');
    document.body.style.removeProperty('padding-right');
    document.body.style.removeProperty('overflow');
    if (document.body.hasAttribute('style')) {
        const style = document.body.getAttribute('style');
        if (style && (style.includes('padding-right') || style.includes('overflow'))) {
            document.body.removeAttribute('style');
        }
    }
}
<<<<<<< HEAD
=======

   function showProfileModal() { 
    cleanupModalBackdrops();
    new bootstrap.Modal(document.getElementById('profileModal')).show(); 
}

>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
function confirmLogout() {
            // Close the modal first
            const modal = bootstrap.Modal.getInstance(document.getElementById('profileModal'));
            if (modal) {
                modal.hide();
            }
            
            // Show confirmation dialog
            Swal.fire({
                title: 'Are you sure?',
                text: 'You will be logged out of the system',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#07d826',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Yes, logout'
            }).then((result) => {
                if (result.isConfirmed) {
                    localStorage.removeItem('sidebarCollapsed');
                    window.location.href = '../logout.php';
                }
            });
        }

function logout() { confirmLogout(); }
    
<<<<<<< HEAD

    // ========== MOBILE BOTTOM NAVBAR FIX ==========
    // Global functions because mobile bottom nav uses inline onclick handlers.
    window.closeAllMobileDropdowns = function() {
        const dropdowns = document.querySelectorAll(
            '.mobile-nav .more-dropdown, #inventoryDropdownMenu, #salesDropdownMenu, #purchaseDropdownMenu, #moreDropdownMenu'
        );

        dropdowns.forEach(function(dropdown) {
            dropdown.classList.remove('show');
        });

        document.querySelectorAll('.mobile-nav .more-btn, .more-btn').forEach(function(btn) {
            btn.classList.remove('active');
            btn.setAttribute('aria-expanded', 'false');
        });
    };

    window.toggleMobileDropdown = function(event, dropdownId) {
        if (event) {
            event.preventDefault();
            event.stopPropagation();
        }

        const dropdown = document.getElementById(dropdownId);
        const btn = event ? event.currentTarget : null;

        if (!dropdown) {
            console.error('Mobile dropdown not found:', dropdownId);
            return false;
        }

        const isOpen = dropdown.classList.contains('show');

        window.closeAllMobileDropdowns();

        if (!isOpen) {
            dropdown.classList.add('show');

            if (btn) {
                btn.classList.add('active');
                btn.setAttribute('aria-expanded', 'true');
            }
        }

        return false;
    };

    // Compatibility for old onclick="toggleDropdown(...)" buttons.
    window.toggleDropdown = function(event, dropdownId) {
        return window.toggleMobileDropdown(event, dropdownId);
    };

    window.showProfileModal = function(event) {
        if (event) {
            event.preventDefault();
            event.stopPropagation();
        }

        if (typeof cleanupModalBackdrops === 'function') {
            cleanupModalBackdrops();
        }

        window.closeAllMobileDropdowns();

        const profileModalEl = document.getElementById('profileModal');

        if (profileModalEl && typeof bootstrap !== 'undefined') {
            bootstrap.Modal.getOrCreateInstance(profileModalEl).show();
        } else {
            console.error('Profile modal or Bootstrap is missing.');
        }

        return false;
    };

    document.addEventListener('click', function(e) {
        if (!e.target.closest('.mobile-nav')) {
            window.closeAllMobileDropdowns();
        }
    });

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            window.closeAllMobileDropdowns();
        }
    });

    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('.mobile-nav .dropdown-item').forEach(function(item) {
            item.addEventListener('click', function() {
                window.closeAllMobileDropdowns();
            });
        });

        const profileModalEl = document.getElementById('profileModal');
        if (profileModalEl) {
            profileModalEl.addEventListener('show.bs.modal', function() {
                window.closeAllMobileDropdowns();
            });
        }

        if (typeof setActiveMobileNav === 'function') {
            setActiveMobileNav();
        }
    });


=======
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
    // ========== DROPDOWN FUNCTIONS ==========
    function toggleMoreDropdown(event) {
        event.preventDefault();
        event.stopPropagation();
        const dropdown = document.getElementById('moreDropdownMenu');
        const moreBtn = document.querySelector('.more-btn');
        if (dropdown.classList.contains('show')) {
            dropdown.classList.remove('show');
            moreBtn.classList.remove('active');
            document.removeEventListener('click', closeMoreDropdown);
        } else {
            document.querySelectorAll('.more-dropdown.show').forEach(d => d.classList.remove('show'));
            document.querySelectorAll('.more-btn.active').forEach(btn => btn.classList.remove('active'));
            dropdown.classList.add('show');
            moreBtn.classList.add('active');
            setTimeout(() => document.addEventListener('click', closeMoreDropdown), 100);
        }
    }

    function closeMoreDropdown(event) {
        const dropdown = document.getElementById('moreDropdownMenu');
        const moreBtn = document.querySelector('.more-btn');
        const moreContainer = document.querySelector('.dropdown-more');
        if (moreContainer && !moreContainer.contains(event.target)) {
            dropdown.classList.remove('show');
            moreBtn.classList.remove('active');
            document.removeEventListener('click', closeMoreDropdown);
        }
    }

    function fixPurchaseDropdownPosition() {
        const purchaseDropdown = document.querySelector('#purchaseDropdown .more-dropdown');
        if (purchaseDropdown) {
            purchaseDropdown.style.setProperty('right', '0', 'important');
            purchaseDropdown.style.setProperty('left', 'auto', 'important');
        }
    }

<<<<<<< HEAD
=======
    if (typeof window.toggleDropdown !== 'function') {
        window.toggleDropdown = function(event, dropdownId) {
            event.preventDefault();
            event.stopPropagation();
            const dropdown = document.getElementById(dropdownId);
            const btn = event.currentTarget;
            if (dropdown.classList.contains('show')) {
                dropdown.classList.remove('show');
                btn.classList.remove('active');
            } else {
                ['inventoryDropdownMenu', 'salesDropdownMenu', 'purchaseDropdownMenu', 'moreDropdownMenu'].forEach(id => {
                    const d = document.getElementById(id);
                    if (d && d !== dropdown) d.classList.remove('show');
                });
                document.querySelectorAll('.more-btn').forEach(b => b.classList.remove('active'));
                dropdown.classList.add('show');
                btn.classList.add('active');
                if (dropdownId === 'purchaseDropdownMenu') setTimeout(fixPurchaseDropdownPosition, 10);
                setTimeout(() => {
                    document.addEventListener('click', function closeHandler(e) {
                        if (!dropdown.contains(e.target) && !btn.contains(e.target)) {
                            dropdown.classList.remove('show');
                            btn.classList.remove('active');
                            document.removeEventListener('click', closeHandler);
                        }
                    });
                }, 100);
            }
            if (dropdownId === 'purchaseDropdownMenu') setTimeout(fixPurchaseDropdownPosition, 10);
        };
    }

>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
    function initTabs() {
        const tabs = document.querySelectorAll('.category-tab');
        const panes = document.querySelectorAll('.tab-pane');
        tabs.forEach(tab => {
            tab.addEventListener('click', function() {
                const targetId = this.getAttribute('data-tab');
                tabs.forEach(t => t.classList.remove('active'));
                this.classList.add('active');
                panes.forEach(pane => {
                    pane.classList.remove('active');
                    pane.style.display = 'none';
                });
                const activePane = document.getElementById(targetId + '-content');
                if (activePane) {
                    activePane.classList.add('active');
                    activePane.style.display = 'block';
                    if (targetId === 'rejected-deliveries') {
                        setTimeout(initTapToView, 100);
                    }
                }
            });
        });
    }

    // ========== DOM CONTENT LOADED ==========
    document.addEventListener('DOMContentLoaded', function() {
        console.log("Bad Orders - Live Database Mode");
        initializeSidebar();
        initTabs();
        initTapToView();
        initFilterToggle();
        
        // Add filter change listeners
        const filterSelects = ['dateFilter', 'statusFilter', 'reasonFilter', 'branchFilter'];
        filterSelects.forEach(filterId => {
            const filterElement = document.getElementById(filterId);
            if (filterElement) {
                filterElement.addEventListener('change', function() {
                    applyFilters();
                });
            }
        });
        
        // Initialize filter summary and badge
        updateFilterSummary();
        updateFilterCountBadge();
        
        document.getElementById('mobileMenuBtn').addEventListener('click', function() {
            const sidebar = document.getElementById('sidebar');
            if (window.innerWidth <= 992) {
                sidebar.classList.toggle('active');
                if (!document.querySelector('.sidebar-overlay')) {
                    const overlay = document.createElement('div');
                    overlay.className = 'sidebar-overlay';
                    document.body.appendChild(overlay);
                    overlay.addEventListener('click', closeMobileSidebar);
                    setTimeout(() => overlay.classList.add('active'), 10);
                }
            } else {
                toggleSidebar();
            }
        });
        
        const desktopToggleBtn = document.getElementById('desktopToggleBtn');
        if (desktopToggleBtn) {
            desktopToggleBtn.addEventListener('click', function(e) { e.stopPropagation(); toggleSidebar(); });
        }
        
        document.querySelectorAll('.sidebar .nav-link').forEach(link => {
            link.addEventListener('click', function() { if (window.innerWidth <= 992) closeMobileSidebar(); });
        });
        
        document.addEventListener('click', function(event) {
            const sidebar = document.getElementById('sidebar');
            const mobileBtn = document.getElementById('mobileMenuBtn');
            const overlay = document.querySelector('.sidebar-overlay');
            const isMobile = window.innerWidth <= 992;
            if (isMobile && sidebar.classList.contains('active') && !sidebar.contains(event.target) && !mobileBtn.contains(event.target) && !overlay?.contains(event.target)) {
                closeMobileSidebar();
            }
        });
        
        fixPurchaseDropdownPosition();
    });
    
    document.addEventListener('keydown', function(e) {
        if (e.ctrlKey && e.key === 'b' && window.innerWidth > 992) { e.preventDefault(); toggleSidebar(); }
        else if (e.ctrlKey && e.key === 'p') { e.preventDefault(); printRMRReport(); }
    });
    
    let scrollTimeout;
    window.addEventListener('scroll', function() {
        const dropdown = document.getElementById('moreDropdownMenu');
        if (dropdown && dropdown.classList.contains('show')) {
            if (scrollTimeout) clearTimeout(scrollTimeout);
            scrollTimeout = setTimeout(() => {
                dropdown.classList.remove('show');
                document.querySelector('.more-btn')?.classList.remove('active');
            }, 150);
        }
    });
    
    window.addEventListener('resize', fixPurchaseDropdownPosition);
    
    const purchaseMenu = document.getElementById('purchaseDropdownMenu');
    if (purchaseMenu) {
        new MutationObserver(function(mutations) {
            mutations.forEach(mutation => {
                if (mutation.type === 'attributes' && mutation.attributeName === 'class' && purchaseMenu.classList.contains('show')) {
                    fixPurchaseDropdownPosition();
                }
            });
        }).observe(purchaseMenu, { attributes: true });
    }
        setActiveMobileNav();
        function setActiveMobileNav() {
        const currentPage = window.location.pathname.split('/').pop();
        
        document.querySelectorAll('.mobile-nav .nav-link, .more-btn, .dropdown-item, .has-active').forEach(el => {
            el.classList.remove('active', 'has-active');
        });
        
        document.querySelectorAll('.mobile-nav .nav-link:not(.more-btn)').forEach(link => {
            if (link.getAttribute('href') === currentPage) link.classList.add('active');
        });
        
        document.querySelectorAll('.more-dropdown .dropdown-item').forEach(item => {
            if (item.getAttribute('href') === currentPage) {
                item.classList.add('active');
                const parentDropdown = item.closest('.dropdown-more');
                if (parentDropdown) {
                    const parentBtn = parentDropdown.querySelector('.more-btn');
                    if (parentBtn) parentBtn.classList.add('has-active');
                }
            }
        });
        
        if (currentPage === 'trip_tickets.php') {
            const tripLink = document.querySelector('#mobileNav .nav-link[href="trip_tickets.php"]');
            if (tripLink) tripLink.classList.add('active');
        }
        
        console.log('Current page:', currentPage);
    }
    // ========== SIDEBAR DROPDOWN HANDLING ==========

// Toggle sidebar dropdown function - properly handles collapsed state
function toggleSidebarDropdown(event, targetId) {
    event.preventDefault();
    event.stopPropagation();
    
    const target = document.getElementById(targetId);
    const btn = event.currentTarget;
    const arrow = btn.querySelector('.dropdown-arrow');
    const sidebar = document.getElementById('sidebar');
    
    // If sidebar is collapsed, expand it first then open dropdown
    if (sidebar.classList.contains('collapsed')) {
        // Expand the sidebar first
        sidebar.classList.remove('collapsed');
        localStorage.setItem('sidebarCollapsed', 'false');
        
        // Small delay to let CSS transition complete, then open dropdown
        setTimeout(() => {
            // Close all other dropdowns first
            document.querySelectorAll('.sidebar .collapse.show').forEach(collapse => {
                if (collapse.id !== targetId) {
                    collapse.classList.remove('show');
                    const otherBtn = document.querySelector(`[onclick*="${collapse.id}"]`);
                    if (otherBtn) {
                        const otherArrow = otherBtn.querySelector('.dropdown-arrow');
                        if (otherArrow) otherArrow.style.transform = 'translateY(-50%) rotate(0deg)';
                    }
                }
            });
            
            // Open the clicked dropdown
            target.classList.add('show');
            if (arrow) arrow.style.transform = 'translateY(-50%) rotate(180deg)';
        }, 50);
        return;
    }
    
    // Normal behavior when sidebar is already expanded
    if (target.classList.contains('show')) {
        target.classList.remove('show');
        if (arrow) arrow.style.transform = 'translateY(-50%) rotate(0deg)';
    } else {
        // Close all other open dropdowns
        document.querySelectorAll('.sidebar .collapse.show').forEach(collapse => {
            if (collapse.id !== targetId) {
                collapse.classList.remove('show');
                const otherBtn = document.querySelector(`[onclick*="${collapse.id}"]`);
                if (otherBtn) {
                    const otherArrow = otherBtn.querySelector('.dropdown-arrow');
                    if (otherArrow) otherArrow.style.transform = 'translateY(-50%) rotate(0deg)';
                }
            }
        });
        
        target.classList.add('show');
        if (arrow) arrow.style.transform = 'translateY(-50%) rotate(180deg)';
    }
}

// Set active sidebar item based on current page
function setActiveSidebarItem() {
    const currentPage = window.location.pathname.split('/').pop();
    
    // Remove active class from all nav links
    document.querySelectorAll('.sidebar .nav-link').forEach(link => {
        link.classList.remove('active');
    });
    
    // Find and activate the matching link
    document.querySelectorAll('.sidebar .nav-link').forEach(link => {
        const href = link.getAttribute('href');
        if (href === currentPage) {
            link.classList.add('active');
            
            // If this link is inside a dropdown, expand the dropdown
            const collapseDiv = link.closest('.collapse');
            if (collapseDiv) {
                collapseDiv.classList.add('show');
                const parentBtn = document.querySelector(`[onclick*="${collapseDiv.id}"]`);
                if (parentBtn) {
                    const arrow = parentBtn.querySelector('.dropdown-arrow');
                    if (arrow) arrow.style.transform = 'translateY(-50%) rotate(180deg)';
                }
            }
        }
    });
}

// Update active state for dropdown parent when sidebar is collapsed
function updateDropdownParentActiveState() {
    const sidebar = document.getElementById('sidebar');
    if (!sidebar) return;
    
    if (sidebar.classList.contains('collapsed')) {
        // Find all dropdown-nav items that have an active child link
        document.querySelectorAll('.dropdown-nav').forEach(dropdownNav => {
            const hasActiveChild = dropdownNav.querySelector('.nav-link.active');
            const parentLink = dropdownNav.querySelector(':scope > .nav-link');
            
            if (hasActiveChild && parentLink) {
                parentLink.classList.add('active');
            } else if (parentLink) {
                parentLink.classList.remove('active');
            }
        });
    }
}

// Function to expand all dropdown containers that contain active links
function expandActiveDropdownContainers() {
    const sidebar = document.getElementById('sidebar');
    if (!sidebar) return;
    
    // Find all dropdown-nav containers
    const dropdownNavs = document.querySelectorAll('.sidebar .dropdown-nav');
    
    dropdownNavs.forEach(dropdownNav => {
        // Check if this dropdown contains any active link
        const activeLink = dropdownNav.querySelector('.nav-link.active');
        
        if (activeLink) {
            // Find the collapse element inside this dropdown
            const collapseDiv = dropdownNav.querySelector('.collapse');
            
            if (collapseDiv && !collapseDiv.classList.contains('show')) {
                // Open the dropdown
                collapseDiv.classList.add('show');
                
                // Rotate the arrow of the parent link
                const parentLink = dropdownNav.querySelector(':scope > .nav-link');
                if (parentLink) {
                    const arrow = parentLink.querySelector('.dropdown-arrow');
                    if (arrow) {
                        arrow.style.transform = 'translateY(-50%) rotate(180deg)';
                    }
                    // Also add active class to parent if sidebar is collapsed
                    if (sidebar.classList.contains('collapsed')) {
                        parentLink.classList.add('active');
                    }
                }
            }
        }
    });
}

// Toggle sidebar function (updated with proper behavior)
function toggleSidebar() {
    const sidebar = document.getElementById('sidebar');
    const desktopToggleBtn = document.getElementById('desktopToggleBtn');
    
    if (window.innerWidth <= 992) {
        // Mobile behavior
        sidebar.classList.toggle('active');
        let overlay = document.querySelector('.sidebar-overlay');
        if (!overlay) { 
            overlay = document.createElement('div'); 
            overlay.className = 'sidebar-overlay'; 
            document.body.appendChild(overlay); 
            overlay.addEventListener('click', function() { 
                sidebar.classList.remove('active'); 
                overlay.classList.remove('active'); 
                setTimeout(function() { overlay.remove(); }, 300); 
            }); 
        }
        setTimeout(function() { overlay.classList.add('active'); }, 10);
    } else {
        // Desktop behavior - toggle collapse
        const wasCollapsed = sidebar.classList.contains('collapsed');
        sidebar.classList.toggle('collapsed');
        localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
        
        // If expanding from collapsed state
        if (wasCollapsed && !sidebar.classList.contains('collapsed')) {
            // Remove any inline styles that might have been set by hover
            sidebar.style.width = '';
            
            // AFTER expanding, find any active child link and open its parent dropdown
            setTimeout(function() {
                expandActiveDropdownContainers();
            }, 150);
        }
    }
}

// Initialize sidebar on DOM load
document.addEventListener('DOMContentLoaded', function() {
    // Restore sidebar state from localStorage
    const sidebar = document.getElementById('sidebar');
    if (sidebar && window.innerWidth > 992) {
        const isCollapsed = localStorage.getItem('sidebarCollapsed') === 'true';
        if (isCollapsed) {
            sidebar.classList.add('collapsed');
        } else {
            sidebar.classList.remove('collapsed');
        }
    }
    
    // Set active sidebar item
    setActiveSidebarItem();
    
    // Update parent active states
    updateDropdownParentActiveState();
    
    // Prevent dropdown from closing when clicking inside it
    document.querySelectorAll('.sidebar .collapse').forEach(collapse => {
        collapse.addEventListener('click', function(e) {
            e.stopPropagation();
        });
    });
    
    // Handle desktop toggle button
    const desktopToggleBtn = document.getElementById('desktopToggleBtn');
    if (desktopToggleBtn) {
        desktopToggleBtn.addEventListener('click', function() {
            setTimeout(() => {
                if (sidebar.classList.contains('collapsed')) {
                    // Close all dropdowns when collapsing
                    document.querySelectorAll('.sidebar .collapse.show').forEach(collapse => {
                        collapse.classList.remove('show');
                        const parentBtn = document.querySelector(`[onclick*="${collapse.id}"]`);
                        if (parentBtn) {
                            const arrow = parentBtn.querySelector('.dropdown-arrow');
                            if (arrow) arrow.style.transform = 'translateY(-50%) rotate(0deg)';
                        }
                    });
                }
            }, 50);
        });
    }
    
    // Handle mobile menu button
    const mobileMenuBtn = document.getElementById('mobileMenuBtn');
    if (mobileMenuBtn) {
        mobileMenuBtn.addEventListener('click', toggleSidebar);
    }
    
    // Close sidebar when clicking outside on mobile
    document.addEventListener('click', function(e) {
        if (window.innerWidth <= 992 && sidebar && sidebar.classList.contains('active') && 
            !sidebar.contains(e.target) && mobileMenuBtn && !mobileMenuBtn.contains(e.target)) {
            sidebar.classList.remove('active');
            const overlay = document.querySelector('.sidebar-overlay');
            if (overlay) overlay.remove();
        }
    });
});
</script>
</body>
</html>
