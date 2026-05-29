<?php
// approve_credit_requests.php
// Branch Admin page to approve/reject credit/discount requests from sales agents
// UPDATED: Added expiration days, effective date range, and ATTACHMENT VIEWING

require_once '../config/database.php';
require_once '../config/session_handler.php';

// Get current user info and branch context
$user_id = $_SESSION['user_id'] ?? 0;
$user_name = isset($_SESSION['first_name']) ? $_SESSION['first_name'] . ' ' . $_SESSION['last_name'] : 'Branch Admin';
$user_role = $_SESSION['role'] ?? 'branch_admin';
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

// Enable error logging
ini_set('log_errors', 1);
ini_set('error_log', '../logs/approve_credit_errors.log');

// Check if credit_discount_requests table exists
$table_exists = false;
$check_table = $conn->query("SHOW TABLES LIKE 'credit_discount_requests'");
if ($check_table && $check_table->num_rows > 0) {
    $table_exists = true;
}

// ------------------------------------------------------------
// Check if attachments table exists
// ------------------------------------------------------------
$attachments_table_exists = false;
$check_attachments_table = $conn->query("SHOW TABLES LIKE 'credit_discount_attachments'");
if ($check_attachments_table && $check_attachments_table->num_rows > 0) {
    $attachments_table_exists = true;
}

// Check if branch_id column exists in customers table
$customers_branch_column_exists = false;
$check_customers_column = $conn->query("SHOW COLUMNS FROM customers LIKE 'branch_id'");
if ($check_customers_column && $check_customers_column->num_rows > 0) {
    $customers_branch_column_exists = true;
}

// Check if approved_by column exists and is valid foreign key
$approved_by_column_exists = false;
$check_approved_by = $conn->query("SHOW COLUMNS FROM credit_discount_requests LIKE 'approved_by'");
if ($check_approved_by && $check_approved_by->num_rows > 0) {
    $approved_by_column_exists = true;
}

// Check if effective_from and effective_until columns exist
$effective_columns_exist = false;
$check_effective_from = $conn->query("SHOW COLUMNS FROM credit_discount_requests LIKE 'effective_from'");
$check_effective_until = $conn->query("SHOW COLUMNS FROM credit_discount_requests LIKE 'effective_until'");
if ($check_effective_from && $check_effective_from->num_rows > 0 && 
    $check_effective_until && $check_effective_until->num_rows > 0) {
    $effective_columns_exist = true;
}

// Get base64 encoded logo for printing
$logo_path = '../Pictures/amgc3DLogo.png';
$logo_base64 = '';
if (file_exists($logo_path)) {
    $image_data = file_get_contents($logo_path);
    $logo_base64 = 'data:image/png;base64,' . base64_encode($image_data);
}

// Determine branch filter condition for customers
$branch_condition = "";
if ($customers_branch_column_exists && !$view_all_branches && $branch_id > 0) {
    $branch_condition = "AND c.branch_id = $branch_id";
}

// ========== HANDLE AJAX REQUESTS ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    try {
        // Start transaction
        $conn->begin_transaction();
        
        // APPROVE REQUEST - WITH EXPIRATION DAYS
        if ($_POST['action'] === 'approve_request') {
            $request_id = (int)$_POST['request_id'];
            $admin_notes = trim($_POST['admin_notes'] ?? '');
            $expiration_days = (int)($_POST['expiration_days'] ?? 30); // Default 30 days
            
            // Validate expiration days
            if ($expiration_days <= 0) {
                $expiration_days = 30;
            }
            
            // Calculate effective from (now) and effective until (now + expiration days)
            $effective_from = date('Y-m-d H:i:s');
            $effective_until = date('Y-m-d H:i:s', strtotime("+{$expiration_days} days"));
            
            error_log("========== APPROVE REQUEST ATTEMPT ==========");
            error_log("Timestamp: " . date('Y-m-d H:i:s'));
            error_log("Request ID: " . $request_id);
            error_log("Admin User ID: " . $user_id);
            error_log("Expiration Days: " . $expiration_days);
            error_log("Effective From: " . $effective_from);
            error_log("Effective Until: " . $effective_until);
            
            // First, check if the request exists and get its current status
            $check_sql = "SELECT r.request_id, r.status, r.customer_id, r.request_type,
                                 r.requested_credit_limit, r.requested_discount_percent, r.credit_terms_days,
                                 c.customer_name, c.branch_id as customer_branch_id
                          FROM credit_discount_requests r
                          LEFT JOIN customers c ON r.customer_id = c.customer_id
                          WHERE r.request_id = ?";
            
            $check_stmt = $conn->prepare($check_sql);
            if (!$check_stmt) {
                throw new Exception("Prepare check failed: " . $conn->error);
            }
            
            $check_stmt->bind_param("i", $request_id);
            if (!$check_stmt->execute()) {
                throw new Exception("Execute check failed: " . $check_stmt->error);
            }
            
            $check_result = $check_stmt->get_result();
            
            if ($check_result->num_rows === 0) {
                throw new Exception("Request ID $request_id not found in database");
            }
            
            $request_data = $check_result->fetch_assoc();
            error_log("Found Request - Current Status: " . $request_data['status']);
            error_log("Customer: " . $request_data['customer_name']);
            
            // Verify branch access if not admin
            if (!$view_all_branches && $customers_branch_column_exists) {
                if (!isset($request_data['customer_branch_id']) || $request_data['customer_branch_id'] != $branch_id) {
                    throw new Exception("Access denied: This customer belongs to a different branch");
                }
            }
            
            // Check if already approved
            if ($request_data['status'] === 'approved') {
                echo json_encode([
                    'success' => true,
                    'message' => 'Request was already approved',
                    'already_approved' => true
                ]);
                $conn->commit();
                exit;
            }
            
            // Update with effective dates
            if ($effective_columns_exist && $approved_by_column_exists) {
                $update_sql = "UPDATE credit_discount_requests 
                               SET status = 'approved', 
                                   admin_notes = ?,
                                   effective_from = ?,
                                   effective_until = ?,
                                   approved_at = NOW(), 
                                   approved_by = ? 
                               WHERE request_id = ?";
                
                error_log("Using update with effective dates");
                error_log("SQL: " . $update_sql);
                
                $update_stmt = $conn->prepare($update_sql);
                if (!$update_stmt) {
                    throw new Exception("Prepare update failed: " . $conn->error);
                }
                
                $update_stmt->bind_param("sssii", $admin_notes, $effective_from, $effective_until, $user_id, $request_id);
                
            } elseif ($effective_columns_exist) {
                // Without approved_by column
                $update_sql = "UPDATE credit_discount_requests 
                               SET status = 'approved', 
                                   admin_notes = ?,
                                   effective_from = ?,
                                   effective_until = ?,
                                   approved_at = NOW()
                               WHERE request_id = ?";
                
                error_log("Using update without approved_by column");
                
                $update_stmt = $conn->prepare($update_sql);
                if (!$update_stmt) {
                    throw new Exception("Prepare update failed: " . $conn->error);
                }
                
                $update_stmt->bind_param("sssi", $admin_notes, $effective_from, $effective_until, $request_id);
                
            } elseif ($approved_by_column_exists) {
                // Without effective dates
                $update_sql = "UPDATE credit_discount_requests 
                               SET status = 'approved', 
                                   admin_notes = ?,
                                   approved_at = NOW(), 
                                   approved_by = ? 
                               WHERE request_id = ?";
                
                error_log("Using update without effective dates");
                
                $update_stmt = $conn->prepare($update_sql);
                if (!$update_stmt) {
                    throw new Exception("Prepare update failed: " . $conn->error);
                }
                
                $update_stmt->bind_param("sii", $admin_notes, $user_id, $request_id);
                
            } else {
                // Fallback
                $update_sql = "UPDATE credit_discount_requests 
                               SET status = 'approved', 
                                   admin_notes = ?,
                                   approved_at = NOW()
                               WHERE request_id = ?";
                
                error_log("Using fallback update");
                
                $update_stmt = $conn->prepare($update_sql);
                if (!$update_stmt) {
                    throw new Exception("Prepare update failed: " . $conn->error);
                }
                
                $update_stmt->bind_param("si", $admin_notes, $request_id);
            }
            
            if (!$update_stmt->execute()) {
                throw new Exception("Execute update failed: " . $update_stmt->error);
            }
            
            $affected_rows = $update_stmt->affected_rows;
            error_log("Affected rows: " . $affected_rows);
            
            // Commit the transaction
            $conn->commit();
            
            error_log("========== APPROVE REQUEST SUCCESS ==========");
            
            echo json_encode([
                'success' => true,
                'message' => 'Request approved successfully. Effective until: ' . date('M d, Y', strtotime($effective_until)),
                'affected_rows' => $affected_rows,
                'request_id' => $request_id,
                'new_status' => 'approved',
                'effective_until' => $effective_until
            ]);
            exit;
        }
        
        // REJECT REQUEST
        elseif ($_POST['action'] === 'reject_request') {
            $request_id = (int)$_POST['request_id'];
            $rejection_reason = trim($_POST['rejection_reason'] ?? '');
            
            error_log("========== REJECT REQUEST ATTEMPT ==========");
            error_log("Request ID: " . $request_id);
            error_log("Rejection Reason: " . $rejection_reason);
            
            // Check if request exists
            $check_sql = "SELECT request_id, status FROM credit_discount_requests WHERE request_id = ?";
            $check_stmt = $conn->prepare($check_sql);
            $check_stmt->bind_param("i", $request_id);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            
            if ($check_result->num_rows === 0) {
                throw new Exception("Request not found");
            }
            
            $request_data = $check_result->fetch_assoc();
            error_log("Current status: " . $request_data['status']);
            
            // Update to rejected
            if ($approved_by_column_exists) {
                $update_sql = "UPDATE credit_discount_requests 
                               SET status = 'rejected', 
                                   admin_notes = CONCAT(IFNULL(admin_notes,''), '\nRejected: ', ?),
                                   approved_at = NOW(), 
                                   approved_by = ? 
                               WHERE request_id = ?";
                $update_stmt = $conn->prepare($update_sql);
                $update_stmt->bind_param("sii", $rejection_reason, $user_id, $request_id);
            } else {
                $update_sql = "UPDATE credit_discount_requests 
                               SET status = 'rejected', 
                                   admin_notes = CONCAT(IFNULL(admin_notes,''), '\nRejected: ', ?),
                                   approved_at = NOW()
                               WHERE request_id = ?";
                $update_stmt = $conn->prepare($update_sql);
                $update_stmt->bind_param("si", $rejection_reason, $request_id);
            }
            
            if (!$update_stmt->execute()) {
                throw new Exception("Failed to reject request: " . $update_stmt->error);
            }
            
            $conn->commit();
            
            echo json_encode([
                'success' => true,
                'message' => 'Request rejected successfully'
            ]);
            exit;
        }
        
        // VIEW REQUEST DETAILS
        elseif ($_POST['action'] === 'view_request') {
            $request_id = (int)$_POST['request_id'];
            
            $query = "SELECT r.*, c.customer_name, c.customer_code, c.email, c.phone_number,
                             u.first_name, u.last_name, u.email as agent_email
                      FROM credit_discount_requests r
                      JOIN customers c ON r.customer_id = c.customer_id
                      JOIN users u ON r.agent_id = u.user_id
                      WHERE r.request_id = ?";
            
            if (!$view_all_branches && $customers_branch_column_exists) {
                $query .= " AND c.branch_id = ?";
                $stmt = $conn->prepare($query);
                $stmt->bind_param("ii", $request_id, $branch_id);
            } else {
                $stmt = $conn->prepare($query);
                $stmt->bind_param("i", $request_id);
            }
            
            $stmt->execute();
            $result = $stmt->get_result();
            $request = $result->fetch_assoc();
            
            if ($request) {
                // Get attachments for this request
                $attachments = [];
                if ($attachments_table_exists) {
                    $att_query = "SELECT * FROM credit_discount_attachments WHERE request_id = ? ORDER BY uploaded_at ASC";
                    $att_stmt = $conn->prepare($att_query);
                    $att_stmt->bind_param("i", $request_id);
                    $att_stmt->execute();
                    $att_result = $att_stmt->get_result();
                    while ($att = $att_result->fetch_assoc()) {
                        $attachments[] = $att;
                    }
                }
                
                echo json_encode([
                    'success' => true,
                    'request' => $request,
                    'attachments' => $attachments
                ]);
            } else {
                throw new Exception('Request not found');
            }
            exit;
        }
        
    } catch (Exception $e) {
        $conn->rollback();
        error_log("========== APPROVE REQUEST ERROR ==========");
        error_log("Error message: " . $e->getMessage());
        error_log("Error trace: " . $e->getTraceAsString());
        
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
        exit;
    }
}

// FETCH REQUESTS FROM DATABASE with branch filtering via customer
$requests_query = "
    SELECT 
        r.request_id,
        r.request_type,
        r.requested_credit_limit,
        r.requested_discount_percent,
        r.credit_terms_days,
        r.reason,
        r.status,
        r.admin_notes,
        r.created_at,
        r.updated_at,
        r.approved_at,
        r.effective_from,
        r.effective_until,
        c.customer_id,
        c.customer_name,
        c.customer_code,
        c.branch_id as customer_branch_id,
        CONCAT(u.first_name, ' ', u.last_name) as agent_name,
        u.email as agent_email
    FROM credit_discount_requests r
    JOIN customers c ON r.customer_id = c.customer_id
    LEFT JOIN users u ON r.agent_id = u.user_id
    WHERE 1=1
    $branch_condition
    ORDER BY r.created_at DESC, r.request_id DESC
";

$requests_result = $conn->query($requests_query);
if (!$requests_result) {
    $requests = [];
    error_log("Credit Discount Requests Query Error: " . $conn->error);
} else {
    $requests = $requests_result->fetch_all(MYSQLI_ASSOC);
}

// Get attachments for all requests
$attachments_by_request = [];
if ($attachments_table_exists && !empty($requests)) {
    $all_request_ids = array_column($requests, 'request_id');
    if (!empty($all_request_ids)) {
        $ids_string = implode(',', $all_request_ids);
        $attachment_query = "SELECT * FROM credit_discount_attachments WHERE request_id IN ($ids_string) ORDER BY uploaded_at ASC";
        $attachments_result = $conn->query($attachment_query);
        if ($attachments_result) {
            while ($attachment = $attachments_result->fetch_assoc()) {
                $attachments_by_request[$attachment['request_id']][] = $attachment;
            }
        }
    }
}

// CALCULATE STATISTICS
$total_requests = count($requests);
$pending_requests = count(array_filter($requests, fn($r) => $r['status'] === 'pending'));
$approved_requests = count(array_filter($requests, fn($r) => $r['status'] === 'approved'));
$rejected_requests = count(array_filter($requests, fn($r) => $r['status'] === 'rejected'));

// Helper functions
function getRequestStatusClass($status) {
    return match($status) {
        'pending' => 'status-pending',
        'approved' => 'status-approved',
        'rejected' => 'status-rejected',
        default => 'status-pending'
    };
}

function getRequestStatusText($status) {
    return match($status) {
        'pending' => 'Pending',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
        default => ucfirst($status)
    };
}

function getRequestTypeText($type) {
    return match($type) {
        'credit' => 'Credit Limit',
        'discount' => 'Discount',
        'both' => 'Credit & Discount',
        'credit_terms' => 'Credit Terms',
        default => ucfirst($type)
    };
}

function formatDate($dateStr) {
    if (!$dateStr) return '';
    $date = new DateTime($dateStr);
    return $date->format('M d, Y H:i');
}

function isRequestExpired($effective_until) {
    if (!$effective_until) return false;
    return strtotime($effective_until) < time();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Approve Credit/Discount Requests - Branch Admin</title>
    <link rel="icon" type="image/png" href="../Pictures/favicon-96x96.png" sizes="96x96" />
    <link rel="icon" type="image/svg+xml" href="../Pictures/favicon.svg" />
    <link rel="shortcut icon" href="../Pictures/favicon.ico" />
    <link rel="apple-touch-icon" sizes="180x180" href="../Pictures/apple-touch-icon.png" />
    <link rel="manifest" href="../Pictures/site.webmanifest" />
    <link rel="stylesheet" href="../css/current_inventory.css">
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
        
        /* Alert for missing table */
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
        
        /* Status badges */
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
        .status-approved { background-color: #d4edda; color: #155724; }
        .status-rejected { background-color: #f8d7da; color: #721c24; }
        .status-expired { background-color: #e9ecef; color: #6c757d; }
        
        /* Request type badge */
        .type-badge {
            display: inline-block;
            padding: 4px 10px;
            font-size: 11px;
            font-weight: 500;
            border-radius: 4px;
        }
        
        .type-credit { background-color: #cce5ff; color: #004085; }
        .type-discount { background-color: #d4edda; color: #155724; }
        .type-both { background-color: #e2d5f2; color: #533f7c; }
        .type-credit_terms { background-color: #fff3cd; color: #856404; }
        
        /* Attachment styles */
        .attachment-list {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            justify-content: flex-start;
            margin-top: 8px;
        }
        .attachment-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background: #f0f0f0;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.7rem;
            color: #333;
            text-decoration: none;
            transition: all 0.2s;
            cursor: pointer;
        }
        .attachment-badge:hover {
            background: #e0e0e0;
            color: #000;
            transform: translateY(-1px);
        }
        .attachment-badge i {
            font-size: 0.75rem;
        }
        
        /* Table column widths */
        .col-id { width: 5%; }
        .col-date { width: 10%; }
        .col-customer { width: 13%; }
        .col-agent { width: 10%; }
        .col-type { width: 8%; }
        .col-credit { width: 8%; }
        .col-discount { width: 7%; }
        .col-terms { width: 7%; }
        .col-reason { width: 15%; }
        .col-status { width: 8%; }
        .col-validity { width: 12%; }
        .col-attachments { width: 8%; }
        .col-actions { width: 10%; text-align: center; }
        
        /* Filter section */
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
        
        .search-box {
            position: relative;
            min-width: 250px;
        }
        
        .search-box i {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #6c757d;
            font-size: 14px;
            z-index: 10;
            pointer-events: none;
        }
        
        .search-box input {
            width: 100%;
            padding: 8px 12px 8px 38px;
            border: 1px solid #ced4da;
            border-radius: 6px;
            height: 40px;
            font-size: 14px;
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
        .btn-approve{
            color: #388e3c;
            background-color: #e8f5e9;
            border-color: #c8e6c9;
        }
        
        .btn-reject{
            color: #c30010;
            background-color: #ffcbd1;
            border-color: #f69697;
        }
             
        /* Modal styling */
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
        
        /* Expiration days input */
        .expiration-slider {
            margin: 15px 0;
        }

        .expiration-number-wrap {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .expiration-number-input {
            max-width: 160px;
        }

        .expiration-value {
            font-size: 14px;
            font-weight: bold;
            color: #0d6efd;
            white-space: nowrap;
        }
        
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
        
        @media print {
            @page {
                size: landscape;
                margin: 0.3in;
            }
            body * { visibility: hidden; background: white !important; color: black !important; border-color: black !important; }
            #printFrame, #printFrame * { visibility: visible; }
            #printFrame { position: absolute; left: 0; top: 0; width: 100%; height: auto; border: none; }
            #printFrame img { filter: none !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            #printFrame * { background: white !important; color: black !important; border-color: #000 !important; box-shadow: none !important; text-shadow: none !important; }
            #printFrame table, #printFrame th, #printFrame td { border: 1px solid #000 !important; }
            #printFrame th { background: white !important; color: black !important; font-weight: bold; }
            #printFrame .summary-box, #printFrame .customer-section, #printFrame .total-row { background: white !important; border: 1px solid #000 !important; }
            #printFrame .badge { background: white !important; border: 1px solid #000 !important; color: black !important; padding: 2px 6px; }
        }
        /* ===== QUICK STATS CARDS STYLE - gaya ng nasa ibaba ===== */
.stat-card {
    background: transparent !important;
    border: none !important;
    box-shadow: 0 4px 10px rgba(0, 0, 0, 0.08) !important;
    min-height: auto !important;
    height: auto !important;
    padding: 0.8rem !important;
    transition: transform 0.2s ease, box-shadow 0.2s ease !important;
    cursor: default !important;
}

/* Gradient backgrounds for each type */
.stat-card.total {
   background: linear-gradient(135deg, #047857, #059669) !important;
    border: none !important;
}

.stat-card.pending {
    background: linear-gradient(135deg, #047857, #059669) !important;
    border: none !important;
}

.stat-card.approved {
    background: linear-gradient(135deg, #047857, #059669) !important;
    border: none !important;
}

.stat-card.rejected {
    background: linear-gradient(135deg, #047857, #059669) !important;
    border: none !important;
}

/* Force text colors to white */
.stat-card .stat-value,
.stat-card .stat-label {
    color: white !important;
}

/* Remove any white background from icon */
.stat-card .stat-icon {
    background: transparent !important;
    color: white !important;
}

/* ===== MOBILE: SQUARE CARDS WITH CENTERED ICON ===== */
@media (max-width: 991px) {
    .stat-card {
        aspect-ratio: 1 / 1 !important;
        display: flex !important;
        flex-direction: column !important;
        justify-content: center !important;
        align-items: center !important;
        text-align: center !important;
        padding: 0.5rem !important;
    }
    
    .stat-card i,
    .stat-card .stat-icon {
        display: block !important;
        text-align: center !important;
        margin: 0 auto 0.3rem auto !important;
        font-size: 1.6rem !important;
        width: auto !important;
        float: none !important;
        position: static !important;
    }
    
    .stat-card .stat-value {
        display: block !important;
        text-align: center !important;
        font-size: 1.2rem !important;
        font-weight: bold !important;
        line-height: 1.2 !important;
        margin: 0.2rem 0 !important;
        width: 100% !important;
    }
    
    .stat-card .stat-label {
        display: block !important;
        text-align: center !important;
        font-size: 0.7rem !important;
        font-weight: 500 !important;
        width: 100% !important;
        white-space: normal !important;
        word-break: break-word !important;
        line-height: 1.2 !important;
    }
    
    /* Para sa "Total Requests" na mahaba */
    .stat-card.total .stat-label {
        font-size: 0.65rem !important;
        max-width: 100% !important;
        padding: 0 0.2rem !important;
    }
}

/* ===== DESKTOP: HORIZONTAL LAYOUT ===== */
@media (min-width: 992px) {
    .stat-card {
        align-items: center !important;
        text-align: left !important;
        padding: 1rem !important;
        aspect-ratio: auto !important;
        min-height: 100px !important;
        display: flex !important;
        flex-direction: row !important;
        justify-content: flex-start !important;
    }
    
    .stat-card i,
    .stat-card .stat-icon {
        margin: 0 0.75rem 0 0 !important;
        font-size: 2rem !important;
        display: inline-flex !important;
        align-items: center !important;
        justify-content: center !important;
        flex-shrink: 0 !important;
    }
    
    .stat-card .stat-value {
        font-size: 1.5rem !important;
        font-weight: bold !important;
        line-height: 1 !important;
        margin: 0 !important;
    }
    
    .stat-card .stat-label {
        font-size: 0.75rem !important;
        font-weight: 500 !important;
        white-space: nowrap !important;
        line-height: 1.2 !important;
        margin-top: 0.25rem !important;
    }
    
    /* Para sa desktop, hindi mag-break ang "Total Requests" */
    .stat-card.total .stat-label {
        white-space: nowrap !important;
    }
}

/* ===== TABLET (768px - 991px) ===== */
@media (min-width: 768px) and (max-width: 991px) {
    .stat-card i,
    .stat-card .stat-icon {
        font-size: 1.4rem !important;
        margin-bottom: 0.25rem !important;
    }
    
    .stat-card .stat-value {
        font-size: 1rem !important;
    }
    
    .stat-card .stat-label {
        font-size: 0.6rem !important;
    }
    
    /* Para sa "Total Requests" sa tablet */
    .stat-card.total .stat-label {
        font-size: 0.55rem !important;
    }
}

/* ===== EXTRA SMALL MOBILE (below 400px) ===== */
@media (max-width: 399px) {
    .stat-card {
        padding: 0.3rem !important;
    }
    
    .stat-card i,
    .stat-card .stat-icon {
        font-size: 1.2rem !important;
        margin-bottom: 0.2rem !important;
    }
    
    .stat-card .stat-value {
        font-size: 0.9rem !important;
    }
    
    .stat-card .stat-label {
        font-size: 0.55rem !important;
    }
    
    /* Para sa "Total Requests" sa sobrang liit na screen */
    .stat-card.total .stat-label {
        font-size: 0.5rem !important;
    }
}

/* ===== HOVER EFFECT ===== */
.stat-card:hover {
    transform: translateY(-2px) !important;
    box-shadow: 0 6px 15px rgba(0, 0, 0, 0.15) !important;
}
/* ===== FILTER ACTION BUTTONS STYLE ===== */
.filter-actions {
    display: flex;
    gap: 0.5rem;
    justify-content: flex-end;
    flex-wrap: wrap;
    margin-top: 0 !important;
    padding-top: 0 !important;
}

.filter-actions .btn {
    padding: 0.35rem 0.85rem;
    font-size: 0.8rem;
    border-radius: 6px;
    transition: all 0.2s ease;
    font-weight: 500;
}

.filter-actions .btn-outline-primary {
    border: 1px solid #dee2e6;
    background: white;
    color: #0d6efd;
}

.filter-actions .btn-outline-primary:hover {
    background: #0d6efd;
    border-color: #0d6efd;
    color: white;
    transform: translateY(-1px);
    box-shadow: 0 2px 4px rgba(13, 110, 253, 0.2);
}

.filter-actions .btn-outline-success {
    border: 1px solid #dee2e6;
    background: white;
    color: #198754;
}

.filter-actions .btn-outline-success:hover {
    background: #198754;
    border-color: #198754;
    color: white;
    transform: translateY(-1px);
    box-shadow: 0 2px 4px rgba(25, 135, 84, 0.2);
}

.filter-actions .btn i {
    margin-right: 0.35rem;
    font-size: 0.75rem;
}

/* Alisin ang white space sa row na may buttons */
.filter-content .row:last-child {
    margin-top: 0 !important;
    padding-top: 0 !important;
}

/* Adjust ang spacing ng buong filter content */
.filter-content {
    padding: 1.25rem;
    padding-bottom: 0.75rem;
}

/* Bawasan ang gap sa pagitan ng rows */
.filter-content .row + .row {
    margin-top: 0 !important;
}

/* Mobile responsive */
@media (max-width: 768px) {
    .filter-actions {
        justify-content: stretch;
        margin-top: 0.25rem !important;
    }
    
    .filter-actions .btn {
        flex: 1;
        text-align: center;
        padding: 0.35rem 0.5rem;
        font-size: 0.75rem;
    }
}
/* ===== FORCE OVERRIDE FOR REQUESTS TABLE MOBILE VIEW ===== */
@media (max-width: 768px) {
    /* Force hide table header */
    #requestsTable thead,
    #requestsTable thead tr,
    #requestsTable thead th {
        display: none !important;
        visibility: hidden !important;
        opacity: 0 !important;
        height: 0 !important;
        padding: 0 !important;
        margin: 0 !important;
        border: none !important;
    }
    
    /* Force table to block display */
    #requestsTable,
    #requestsTable tbody,
    #requestsTable tbody tr {
        display: block !important;
        width: 100% !important;
        box-sizing: border-box !important;
    }
    
    /* Force each row as card */
    #requestsTable tbody tr.request-row {
        background: white !important;
        border-radius: 12px !important;
        margin-bottom: 12px !important;
        padding: 14px !important;
        padding-top: 12px !important;
        box-shadow: 0 1px 3px rgba(0,0,0,0.08) !important;
        border: 1px solid #e5e7eb !important;
        display: block !important;
        width: 100% !important;
        box-sizing: border-box !important;
        position: relative !important;
        cursor: pointer !important;
    }
    
    /* FORCE HIDE all original td elements */
    #requestsTable tbody tr.request-row td {
        display: none !important;
        padding: 0 !important;
        margin: 0 !important;
        border: none !important;
        background: transparent !important;
    }
    
    /* ===== DATE ===== */
    #requestsTable tbody tr.request-row td.col-date {
        display: block !important;
        font-size: 11px !important;
        color: #9ca3af !important;
        margin-bottom: 8px !important;
        padding: 0 !important;
        background: transparent !important;
        border: none !important;
        width: auto !important;
    }
    
    /* ===== STATUS BADGE ===== */
    #requestsTable tbody tr.request-row td.col-status {
        display: block !important;
        position: absolute !important;
        top: 12px !important;
        right: 12px !important;
        padding: 0 !important;
        background: transparent !important;
        z-index: 10 !important;
        width: auto !important;
        height: auto !important;
    }
    
    #requestsTable tbody tr.request-row td.col-status .status-badge {
        display: inline-block !important;
        font-size: 10px !important;
        padding: 4px 10px !important;
        border-radius: 20px !important;
        font-weight: 500 !important;
        margin: 0 !important;
        white-space: nowrap !important;
    }
    
    /* ===== CUSTOMER NAME ===== */
    #requestsTable tbody tr.request-row td.col-customer {
        display: block !important;
        padding: 0 !important;
        margin-top: 5px !important;
        margin-bottom: 4px !important;
        background: transparent !important;
        border: none !important;
        padding-right: 100px !important;
    }
    
    #requestsTable tbody tr.request-row td.col-customer strong {
        display: block !important;
        font-size: 15px !important;
        font-weight: 600 !important;
        color: #1f2937 !important;
        background: transparent !important;
    }
    
    /* ===== CUSTOMER CODE ===== */
    #requestsTable tbody tr.request-row td.col-customer small {
        display: block !important;
        font-size: 10px !important;
        color: #9ca3af !important;
        margin-top: 2px !important;
        background: transparent !important;
    }
    
    /* ===== AGENT ===== */
    #requestsTable tbody tr.request-row td.col-agent {
        display: block !important;
        font-size: 12px !important;
        color: #6c757d !important;
        margin-bottom: 8px !important;
        padding: 0 !important;
        background: transparent !important;
    }
    
    #requestsTable tbody tr.request-row td.col-agent::before {
        content: "Agent: ";
        font-weight: 600;
        color: #495057;
    }
    
    /* ===== TYPE BADGE ===== */
    #requestsTable tbody tr.request-row td.col-type {
        display: block !important;
        margin-bottom: 10px !important;
        padding: 0 !important;
        background: transparent !important;
    }
    
    #requestsTable tbody tr.request-row td.col-type .type-badge {
        display: inline-block !important;
        font-size: 10px !important;
        padding: 3px 10px !important;
        border-radius: 20px !important;
        font-weight: 500 !important;
    }
    
    /* ===== CREDIT ===== */
    #requestsTable tbody tr.request-row td.col-credit {
        display: inline-block !important;
        font-size: 12px !important;
        margin-right: 12px !important;
        margin-bottom: 6px !important;
        padding: 0 !important;
        background: transparent !important;
    }
    
    #requestsTable tbody tr.request-row td.col-credit::before {
        content: "Credit: ";
        font-weight: 600;
        color: #495057;
    }
    
    /* ===== DISCOUNT ===== */
    #requestsTable tbody tr.request-row td.col-discount {
        display: inline-block !important;
        font-size: 12px !important;
        margin-bottom: 6px !important;
        padding: 0 !important;
        background: transparent !important;
    }
    
    #requestsTable tbody tr.request-row td.col-discount::before {
        content: "Discount: ";
        font-weight: 600;
        color: #495057;
    }
    
    /* ===== TERMS ===== */
    #requestsTable tbody tr.request-row td.col-terms {
        display: block !important;
        font-size: 12px !important;
        margin-bottom: 6px !important;
        padding: 0 !important;
        background: transparent !important;
    }
    
    #requestsTable tbody tr.request-row td.col-terms::before {
        content: "Terms: ";
        font-weight: 600;
        color: #495057;
    }
    
    /* ===== VALIDITY ===== */
    #requestsTable tbody tr.request-row td.col-validity {
        display: block !important;
        font-size: 11px !important;
        color: #6c757d !important;
        margin-top: 8px !important;
        padding: 0 !important;
        background: transparent !important;
    }
    
    #requestsTable tbody tr.request-row td.col-validity::before {
        content: "Valid: ";
        font-weight: 600;
        color: #495057;
    }
    
    /* ===== TAP TO VIEW INDICATOR ===== */
    #requestsTable tbody tr.request-row::after {
        content: "Tap to view full details" !important;
        display: block !important;
        text-align: left !important;
        font-size: 9px !important;
        color: #9ca3af !important;
        margin-top: 10px !important;
        padding-top: 8px !important;
        border-top: 1px solid #f0f0f0 !important;
        clear: both !important;
    }
}

/* Extra small mobile adjustments */
@media (max-width: 480px) {
    #requestsTable tbody tr.request-row {
        padding: 10px !important;
        padding-top: 10px !important;
    }
    
    #requestsTable tbody tr.request-row td.col-status .status-badge {
        font-size: 9px !important;
        padding: 3px 8px !important;
    }
    
    #requestsTable tbody tr.request-row td.col-customer strong {
        font-size: 14px !important;
    }
    
    #requestsTable tbody tr.request-row td.col-customer {
        padding-right: 85px !important;
    }
    
    #requestsTable tbody tr.request-row::after {
        font-size: 8px !important;
        margin-top: 8px !important;
        padding-top: 6px !important;
    }
    
    #requestsTable tbody tr.request-row td.col-credit,
    #requestsTable tbody tr.request-row td.col-discount {
        font-size: 11px !important;
    }
    
    #requestsTable tbody tr.request-row td.col-terms {
        font-size: 11px !important;
    }
}

/* ===== MODERN VIEW REQUEST MODAL ===== */

/* Modal Container */
#viewRequestModal .modal-content {
    border: none !important;
    border-radius: 20px !important;
    overflow: hidden !important;
    box-shadow: 0 20px 40px rgba(0, 0, 0, 0.15) !important;
    max-height: 90vh !important;
    display: flex !important;
    flex-direction: column !important;
}

/* Modal Header */
#viewRequestModal .modal-header {
    background: linear-gradient(135deg, #047857, #44D34E) !important;
    color: white !important;
    border-bottom: none !important;
    padding: 1rem 1.5rem !important;
    flex-shrink: 0 !important;
}

#viewRequestModal .modal-header .modal-title {
    font-weight: 600 !important;
    font-size: 1.2rem !important;
    display: flex !important;
    align-items: center !important;
    gap: 8px !important;
    color: white !important;
}

#viewRequestModal .modal-header .modal-title i {
    font-size: 1.3rem !important;
    color: white !important;
}

#viewRequestModal .modal-header .btn-close {
    filter: brightness(0) invert(1) !important;
    opacity: 0.8 !important;
    background: transparent !important;
    transition: all 0.2s ease !important;
}

#viewRequestModal .modal-header .btn-close:hover {
    opacity: 1 !important;
    transform: rotate(90deg) !important;
}

/* Modal Body */
#viewRequestModal .modal-body {
    padding: 1.5rem !important;
    overflow-y: auto !important;
    flex: 1 !important;
    background: #f8fafc !important;
}

/* Scrollbar */
#viewRequestModal .modal-body::-webkit-scrollbar {
    width: 6px;
}

#viewRequestModal .modal-body::-webkit-scrollbar-track {
    background: #f1f1f1;
    border-radius: 3px;
}

#viewRequestModal .modal-body::-webkit-scrollbar-thumb {
    background: #44D34E;
    border-radius: 3px;
}

/* Modal Footer */
#viewRequestModal .modal-footer {
    border-top: 1px solid #e9ecef !important;
    padding: 1rem 1.5rem !important;
    background: white !important;
    flex-shrink: 0 !important;
    gap: 0.75rem !important;
}

#viewRequestModal .modal-footer .btn {
    padding: 0.5rem 1.25rem !important;
    font-size: 0.9rem !important;
    font-weight: 500 !important;
    border-radius: 8px !important;
    transition: all 0.2s ease !important;
}

#viewRequestModal .modal-footer .btn-secondary {
    background: #6c757d !important;
    border: none !important;
    color: white !important;
}

#viewRequestModal .modal-footer .btn-secondary:hover {
    background: #5a6268 !important;
    transform: translateY(-1px) !important;
}

#viewRequestModal .modal-footer .btn-success {
    background: linear-gradient(135deg, #047857, #059669) !important;
    border: none !important;
    color: white !important;
}

#viewRequestModal .modal-footer .btn-success:hover {
    transform: translateY(-1px) !important;
    box-shadow: 0 4px 12px rgba(5, 150, 105, 0.3) !important;
}

#viewRequestModal .modal-footer .btn-danger {
    background: linear-gradient(135deg, #dc3545, #c82333) !important;
    border: none !important;
    color: white !important;
}

#viewRequestModal .modal-footer .btn-danger:hover {
    transform: translateY(-1px) !important;
    box-shadow: 0 4px 12px rgba(220, 53, 69, 0.3) !important;
}

/* Info Cards inside Modal */
#viewRequestModal .info-card {
    background: white !important;
    border-radius: 12px !important;
    margin-bottom: 1rem !important;
    border: 1px solid #e9ecef !important;
    overflow: hidden !important;
    transition: all 0.2s ease !important;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05) !important;
}

#viewRequestModal .info-card:hover {
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08) !important;
    border-color: #d1fae5 !important;
}

#viewRequestModal .card-title {
    background: #f8fafc !important;
    border-bottom: 1px solid #e9ecef !important;
    padding: 0.875rem 1.25rem !important;
    font-size: 0.9rem !important;
    font-weight: 600 !important;
    color: #047857 !important;
    margin: 0 !important;
    display: flex !important;
    align-items: center !important;
    gap: 0.5rem !important;
}

#viewRequestModal .card-title i {
    color: #44D34E !important;
    font-size: 1rem !important;
}

#viewRequestModal .card-body {
    padding: 1rem 1.25rem !important;
    background: white !important;
}

/* Info Rows */
#viewRequestModal .info-row {
    display: flex !important;
    margin-bottom: 0.75rem !important;
    line-height: 1.4 !important;
}

#viewRequestModal .info-row:last-child {
    margin-bottom: 0 !important;
}

#viewRequestModal .info-label {
    width: 110px !important;
    flex-shrink: 0 !important;
    font-weight: 600 !important;
    color: #6c757d !important;
    font-size: 0.85rem !important;
}

#viewRequestModal .info-value {
    flex: 1 !important;
    color: #1f2937 !important;
    font-size: 0.85rem !important;
    word-break: break-word !important;
    font-weight: 500 !important;
}

/* Status Badge in Modal */
#viewRequestModal .status-badge {
    display: inline-block !important;
    padding: 0.25rem 0.75rem !important;
    font-size: 0.7rem !important;
    font-weight: 600 !important;
    border-radius: 20px !important;
}

/* Type Badge in Modal */
#viewRequestModal .type-badge {
    display: inline-block !important;
    padding: 4px 10px !important;
    font-size: 11px !important;
    font-weight: 500 !important;
    border-radius: 4px !important;
}

#viewRequestModal .type-credit {
    background-color: #cce5ff !important;
    color: #004085 !important;
}

#viewRequestModal .type-discount {
    background-color: #d4edda !important;
    color: #155724 !important;
}

#viewRequestModal .type-both {
    background-color: #e2d5f2 !important;
    color: #533f7c !important;
}

#viewRequestModal .type-credit_terms {
    background-color: #fff3cd !important;
    color: #856404 !important;
}

/* Attachment section in modal */
.attachment-section {
    background: white !important;
    border-radius: 12px !important;
    margin-top: 1rem !important;
    border: 1px solid #e9ecef !important;
    overflow: hidden !important;
}

.attachment-section .card-title {
    background: #f8fafc !important;
    border-bottom: 1px solid #e9ecef !important;
    padding: 0.875rem 1.25rem !important;
    font-size: 0.9rem !important;
    font-weight: 600 !important;
    color: #047857 !important;
    margin: 0 !important;
}

.attachment-section .card-body {
    padding: 1rem 1.25rem !important;
}

.attachment-list-modal {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
}

.attachment-item {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: #f0f0f0;
    padding: 6px 14px;
    border-radius: 25px;
    text-decoration: none;
    color: #333;
    font-size: 0.85rem;
    transition: all 0.2s;
}

.attachment-item:hover {
    background: #e0e0e0;
    transform: translateY(-1px);
    text-decoration: none;
    color: #000;
}

.attachment-item i {
    font-size: 1rem;
}

/* Two Column Layout */
@media (min-width: 768px) {
    #viewRequestModal .two-columns {
        display: flex !important;
        gap: 1rem !important;
    }
    
    #viewRequestModal .two-columns > div {
        flex: 1 !important;
    }
}

/* Mobile Responsive for Modal */
@media (max-width: 768px) {
    #viewRequestModal .modal-dialog {
        margin: 0.5rem !important;
        max-width: calc(100% - 1rem) !important;
    }
    
    #viewRequestModal .modal-header {
        padding: 0.85rem 1rem !important;
    }
    
    #viewRequestModal .modal-header .modal-title {
        font-size: 1rem !important;
    }
    
    #viewRequestModal .modal-body {
        padding: 1rem !important;
    }
    
    #viewRequestModal .modal-footer {
        padding: 0.75rem 1rem !important;
    }
    
    #viewRequestModal .modal-footer .btn {
        flex: 1 !important;
        padding: 0.45rem 0.75rem !important;
        font-size: 0.85rem !important;
    }
    
    #viewRequestModal .info-row {
        flex-direction: column !important;
        margin-bottom: 0.75rem !important;
    }
    
    #viewRequestModal .info-label {
        width: 100% !important;
        margin-bottom: 0.2rem !important;
        font-size: 0.7rem !important;
    }
    
    #viewRequestModal .info-value {
        font-size: 0.85rem !important;
    }
    
    #viewRequestModal .card-title {
        padding: 0.6rem 1rem !important;
        font-size: 0.8rem !important;
    }
    
    #viewRequestModal .card-body {
        padding: 0.75rem 1rem !important;
    }
}

@media (max-width: 480px) {
    #viewRequestModal .modal-footer {
        padding: 0.6rem 0.75rem !important;
    }
    
    #viewRequestModal .modal-footer .btn {
        font-size: 0.75rem !important;
        padding: 0.4rem 0.5rem !important;
    }
    
    #viewRequestModal .info-value {
        font-size: 0.8rem !important;
    }
    
    #viewRequestModal .card-body {
        padding: 0.6rem 0.75rem !important;
    }
}
/* Approval Modal Styling */
#approvalModal .modal-content {
    border: none !important;
    border-radius: 20px !important;
    overflow: hidden !important;
    box-shadow: 0 20px 40px rgba(0, 0, 0, 0.15) !important;
}

#approvalModal .modal-header {
    padding: 1rem 1.5rem !important;
    border-bottom: none !important;
}

#approvalModal .modal-header.bg-success {
    background: linear-gradient(135deg, #047857, #44D34E) !important;
}

#approvalModal .modal-header.bg-danger {
    background: linear-gradient(135deg, #dc3545, #f87171) !important;
}

#approvalModal .modal-header .modal-title {
    font-weight: 600 !important;
    font-size: 1.1rem !important;
    display: flex !important;
    align-items: center !important;
    gap: 8px !important;
    color: white !important;
}

#approvalModal .modal-header .btn-close {
    filter: brightness(0) invert(1) !important;
    opacity: 0.8 !important;
}

#approvalModal .modal-body {
    padding: 1.5rem !important;
    background: #f8fafc !important;
}

#approvalModal .modal-footer {
    border-top: 1px solid #e9ecef !important;
    padding: 1rem 1.5rem !important;
    background: white !important;
    gap: 0.75rem !important;
}

#approvalModal .modal-footer .btn {
    padding: 0.5rem 1.25rem !important;
    border-radius: 8px !important;
    font-weight: 500 !important;
}

.expiration-slider {
    background: white !important;
    border-radius: 12px !important;
    padding: 1rem !important;
    margin: 1rem 0 !important;
    border: 1px solid #e9ecef !important;
}

.expiration-number-wrap {
    display: flex !important;
    align-items: center !important;
    gap: 1rem !important;
    flex-wrap: wrap !important;
}

.expiration-number-input {
    max-width: 180px !important;
}

.expiration-value {
    font-weight: 600 !important;
    color: #047857 !important;
}
/* FORCE FILTER TO WORK ON MOBILE - CRITICAL FIX */
@media (max-width: 768px) {
    /* Ensure hidden rows are completely hidden */
    #requestsTable tbody tr.request-row[style*="display: none"],
    #requestsTable tbody tr.request-row[style*="display:none"] {
        display: none !important;
    }
    
    /* Ensure visible rows display as block */
    #requestsTable tbody tr.request-row:not([style*="display: none"]):not([style*="display:none"]) {
        display: block !important;
    }
}
/* File Preview Modal - Buttons anchored to image corners */
#fileViewModal .modal-dialog {
    margin: 0 auto;
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 100vh;
    max-width: none;
    width: auto;
}

#fileViewModal .modal-content {
    background: transparent !important;
    border: none !important;
    box-shadow: none !important;
    width: auto;
    margin: 0 auto;
}

#fileViewModal .modal-body {
    display: flex !important;
    justify-content: center !important;
    align-items: center !important;
    padding: 20px;
}

/* Attachment container */
#fileViewModal .attachment-container {
    display: flex;
    justify-content: center;
    align-items: center;
}

/* Attachment wrapper - position relative para sa absolute buttons */
#fileViewModal .attachment-wrapper {
    position: relative;
    display: inline-block;
    line-height: 0;
}

/* Close button - nasa top-right corner ng image mismo */
#fileViewModal .btn-close-attachment {
    position: absolute;
    top: 5px;
    right: 5px;
    width: 30px;
    height: 30px;
    background-color: rgba(0,0,0,0.6);
    border-radius: 50%;
    display: flex !important;
    align-items: center;
    justify-content: center;
    border: none;
    color: white;
    font-size: 12px;
    cursor: pointer;
    transition: all 0.2s ease;
    z-index: 10;
    padding: 0;
    margin: 0;
}

#fileViewModal .btn-close-attachment:hover {
    background-color: rgba(0,0,0,0.8);
    transform: scale(1.05);
}

/* Download button - nasa bottom-right corner ng image mismo */
#fileViewModal .btn-download-attachment {
    position: absolute;
    bottom: 5px;
    right: 5px;
    width: 30px;
    height: 30px;
    background-color: rgba(0,0,0,0.6);
    border-radius: 50%;
    display: flex !important;
    align-items: center;
    justify-content: center;
    text-decoration: none;
    color: white;
    font-size: 12px;
    transition: all 0.2s ease;
    z-index: 10;
}

#fileViewModal .btn-download-attachment:hover {
    background-color: rgba(0,0,0,0.8);
    transform: scale(1.05);
    color: white;
}

/* Attachment content - the actual image or PDF */
#fileViewModal .attachment-content {
    display: inline-block;
    line-height: 0;
}

/* For images inside modal */
#fileViewModal .attachment-content img {
    max-height: 85vh;
    max-width: 85vw;
    width: auto;
    height: auto;
    object-fit: contain;
    border-radius: 8px;
    box-shadow: 0 4px 30px rgba(0,0,0,0.3);
    display: block;
}

/* For PDF embeds */
#fileViewModal .attachment-content embed {
    width: 80vw;
    height: 80vh;
    border-radius: 8px;
    box-shadow: 0 4px 30px rgba(0,0,0,0.3);
    display: block;
}

/* For other file types */
#fileViewModal .attachment-content .alert {
    max-width: 500px;
    margin: 20px;
    display: block;
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
    <!-- Loading Overlay -->
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
                <li class="nav-item">
                <a class="nav-link" href="branchdashboard.php">
                <i class="bi bi-speedometer2"></i>
                <span class="nav-text">Dashboard</span></a>
            </li>
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
                                            <li class="nav-item">
                                    <a class="nav-link" href="warehouses.php">
                                    <i class="bi bi-shop"></i>
                                    <span class="nav-text">Warehouses</span></a>
                                </li>
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
                    <span class="nav-text">Receive Inventory</span>
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
                    
                <!-- Users -->
                <li class="nav-item">
                    <a class="nav-link" href="drivers.php">
                        <i class="bi bi-people-fill"></i>
                        <span class="nav-text">Users</span>
                    </a>
                </li>
                
                
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
            <!-- PAGE CONTENT -->
            <div class="page-content active">
                <!-- Navbar Top -->
                <div class="navbar-top">
                    <button class="mobile-menu-btn" id="mobileMenuBtn">
                        <i class="bi bi-list"></i>
                    </button>
                    <div class="page-title">
                        <h2></i>Approve Credit/Discount Requests</h2>
                        <p id="dashboardSubtitle">
                            Review and approve/reject requests from sales agents
                            <?php if (!$view_all_branches && $branch_id > 0): ?>
                                for your branch
                            <?php endif; ?>
                        </p>
                    </div>
                </div>

                <!-- Table Missing Alert -->
                <?php if (!$table_exists): ?>
                    <div class="alert alert-info alert-dismissible fade show" role="alert">
                        <i class="bi bi-info-circle"></i> 
                        <strong>The <code>credit_discount_requests</code> table does not exist.</strong> 
                        <br><br>
                        <button type="button" class="btn btn-sm btn-primary" onclick="copySQL()">
                            <i class="bi bi-files"></i> Copy SQL
                        </button>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Add effective columns alert if missing -->
                <?php if ($table_exists && !$effective_columns_exist): ?>
                    <div class="alert alert-warning alert-dismissible fade show" role="alert">
                        <i class="bi bi-exclamation-triangle"></i> 
                        <strong>Database update recommended!</strong> The <code>effective_from</code> and <code>effective_until</code> columns are missing.
                        Please run the SQL below to add expiration date tracking:
                        <br><br>
                        <code class="small">ALTER TABLE `credit_discount_requests` ADD COLUMN `effective_from` datetime DEFAULT NULL AFTER `admin_notes`, ADD COLUMN `effective_until` datetime DEFAULT NULL AFTER `effective_from`;</code>
                        <br><br>
                        <button type="button" class="btn btn-sm btn-primary" onclick="copyEffectiveSQL()">
                            <i class="bi bi-files"></i> Copy SQL
                        </button>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Attachments Table Missing Alert -->
                <?php if ($table_exists && !$attachments_table_exists): ?>
                    <div class="alert alert-warning alert-dismissible fade show" role="alert">
                        <i class="bi bi-exclamation-triangle"></i> 
                        <strong>Attachments table missing!</strong> The <code>credit_discount_attachments</code> table does not exist.
                        Please run the SQL below to add attachment support:
                        <br><br>
                        <code class="small">CREATE TABLE `credit_discount_attachments` (
  `attachment_id` int(11) NOT NULL AUTO_INCREMENT,
  `request_id` int(11) NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `original_file_name` varchar(255) NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `file_size` int(11) DEFAULT NULL,
  `file_type` varchar(100) DEFAULT NULL,
  `uploaded_by` int(11) NOT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`attachment_id`),
  KEY `request_id` (`request_id`),
  KEY `uploaded_by` (`uploaded_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;</code>
                        <br><br>
                        <button type="button" class="btn btn-sm btn-primary" onclick="copyAttachmentsSQL()">
                            <i class="bi bi-files"></i> Copy SQL
                        </button>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Stats Section -->
                <div class="row stat-card-row g-1 g-sm-2 mb-4">
                    <!-- Stat 1: Total Requests -->
                    <div class="col">
                        <div class="stat-card total">
                            <i class="bi bi-inbox stat-icon"></i>
                            <div class="stat-content">
                                <div class="stat-value"><?= $total_requests ?></div>
                                <div class="stat-label">Total Requests</div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Stat 2: Pending -->
                    <div class="col">
                        <div class="stat-card pending">
                            <i class="bi bi-clock-history stat-icon"></i>
                            <div class="stat-content">
                                <div class="stat-value"><?= $pending_requests ?></div>
                                <div class="stat-label">Pending</div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Stat 3: Approved -->
                    <div class="col">
                        <div class="stat-card approved">
                            <i class="bi bi-check-circle stat-icon"></i>
                            <div class="stat-content">
                                <div class="stat-value"><?= $approved_requests ?></div>
                                <div class="stat-label">Approved</div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Stat 4: Rejected -->
                    <div class="col">
                        <div class="stat-card rejected">
                            <i class="bi bi-x-circle stat-icon"></i>
                            <div class="stat-content">
                                <div class="stat-value"><?= $rejected_requests ?></div>
                                <div class="stat-label">Rejected</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- FILTER SECTION - COLLAPSIBLE DESIGN (Entire header clickable) -->
<div class="form-card mb-4" id="filterCard">
    <div class="filter-header" id="filterHeader" style="cursor: pointer;">
        <h5>
            <i class="bi bi-funnel"></i> Filter Requests
        </h5>
        <button class="filter-toggle-btn" type="button" id="filterToggleBtn" aria-expanded="false">
            <i class="bi bi-chevron-down" id="filterIcon"></i>
        </button>
    </div>
    
    <div class="filter-content collapsed" id="filterContent">
        <div class="row g-3">
            <!-- Status Filter -->
            <div class="col-12 col-md-4">
                <label class="form-label">
                    <i class="bi bi-flag"></i> Status
                </label>
                <select class="form-select" id="statusFilter" onchange="filterRequests()">
                    <option value="all">All Status</option>
                    <option value="pending">Pending</option>
                    <option value="approved">Approved</option>
                    <option value="rejected">Rejected</option>
                </select>
            </div>
            
            <!-- Type Filter -->
            <div class="col-12 col-md-4">
                <label class="form-label">
                    <i class="bi bi-tag"></i> Type
                </label>
                <select class="form-select" id="typeFilter" onchange="filterRequests()">
                    <option value="all">All Types</option>
                    <option value="credit">Credit Limit</option>
                    <option value="discount">Discount</option>
                    <option value="both">Both</option>
                    <option value="credit_terms">Credit Terms</option>
                </select>
            </div>
            
            <!-- Search Box -->
            <div class="col-12 col-md-4">
                <label class="form-label">
                    <i class="bi bi-search"></i> Search
                </label>
                <div class="search-box-wrapper">
                    <input type="text" class="form-control" id="searchInput" placeholder="Search customer, agent, reason..." onkeyup="filterRequests()">
                </div>
            </div>
        </div>
        
        <!-- Action Buttons Row -->
        <div class="row g-3 mt-2">
            <div class="col-12">
                <label class="form-label">&nbsp;</label>
                <div class="filter-actions">
                    <button class="btn btn-outline-primary" onclick="printRequests()">
                        <i class="bi bi-printer me-1"></i> Print
                    </button>
                    <button class="btn btn-outline-success" onclick="exportToExcel()">
                        <i class="bi bi-file-earmark-excel me-1"></i> Export
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

                <!-- Requests Table -->
                <div class="table-container">
                    <table class="table custom-table compact-table" id="requestsTable">
                        <thead>
                            <tr>
                                <th class="col-date">DATE</th>
                                <th class="col-customer">CUSTOMER</th>
                                <th class="col-agent">AGENT</th>
                                <th class="col-type">TYPE</th>
                                <th class="col-credit">CREDIT (₱)</th>
                                <th class="col-discount">DISCOUNT (%)</th>
                                <th class="col-terms">TERMS (DAYS)</th>
                                <th class="col-status">STATUS</th>
                                <th class="col-validity">VALIDITY</th>
                            </tr>
                        </thead>
                        <tbody id="requestsTableBody">
                            <?php if (empty($requests) && $table_exists): ?>
                            <tr>
                                <td colspan="9" class="empty-state-table">
                                    <i class="bi bi-inbox"></i>
                                    <h5>No Requests Found</h5>
                                    <p class="text-muted">There are no credit/discount requests to display.</p>
                                </td>
                            </tr>
                            <?php elseif (!$table_exists): ?>
                            <tr>
                                <td colspan="9" class="empty-state-table">
                                    <i class="bi bi-database"></i>
                                    <h5>Table Missing</h5>
                                    <p class="text-muted">The credit_discount_requests table does not exist.</p>
                                </td>
                            </tr>
                            <?php else: ?>
                                <?php foreach ($requests as $req): 
                                    $type_class = match($req['request_type']) {
                                        'credit' => 'type-credit',
                                        'discount' => 'type-discount',
                                        'both' => 'type-both',
                                        'credit_terms' => 'type-credit_terms',
                                        default => 'type-credit'
                                    };
                                    $is_expired = isRequestExpired($req['effective_until']);
                                    $status_display = $req['status'];
                                    if ($req['status'] === 'approved' && $is_expired) {
                                        $status_display = 'Expired';
                                    }
                                    $attachments = $attachments_by_request[$req['request_id']] ?? [];
                                ?>
                                <tr class="request-row" 
                                    data-id="<?= $req['request_id'] ?>"
                                    data-status="<?= $req['status'] ?>"
                                    data-type="<?= $req['request_type'] ?>"
                                    data-customer="<?= htmlspecialchars($req['customer_name']) ?>"
                                    data-agent="<?= htmlspecialchars($req['agent_name'] ?? '') ?>"
                                    data-credit="<?= $req['requested_credit_limit'] ?>"
                                    data-discount="<?= $req['requested_discount_percent'] ?>"
                                    data-terms="<?= $req['credit_terms_days'] ?>">
                                    <td class="col-date"><?= formatDate($req['created_at']) ?></td>
                                    <td class="col-customer">
                                        <strong><?= htmlspecialchars($req['customer_name']) ?></strong>
                                        <br><small class="text-muted"><?= htmlspecialchars($req['customer_code']) ?></small>
                                    </td>
                                    <td class="col-agent"><?= htmlspecialchars($req['agent_name'] ?? 'N/A') ?></td>
                                    <td class="col-type">
                                        <span class="type-badge <?= $type_class ?>">
                                            <?= getRequestTypeText($req['request_type']) ?>
                                        </span>
                                    </td>
                                    <td class="col-credit">
                                        <?= $req['requested_credit_limit'] ? '₱' . number_format($req['requested_credit_limit'], 2) : '—' ?>
                                    </td>
                                    <td class="col-discount">
                                        <?= $req['requested_discount_percent'] ? $req['requested_discount_percent'] . '%' : '—' ?>
                                    </td>
                                    <td class="col-terms">
                                        <?= $req['credit_terms_days'] ? $req['credit_terms_days'] . ' days' : '—' ?>
                                    </td>
                                    <td class="col-status">
                                        <span class="status-badge <?= $req['status'] === 'approved' && $is_expired ? 'status-expired' : getRequestStatusClass($req['status']) ?>">
                                            <?= $status_display ?>
                                        </span>
                                    </td>
                                    <td class="col-validity">
                                        <?php if ($req['status'] === 'approved' && $req['effective_until']): ?>
                                            <small>Until:<br><?= date('M d, Y', strtotime($req['effective_until'])) ?></small>
                                        <?php elseif ($req['status'] === 'approved'): ?>
                                            <small>No expiry set</small>
                                        <?php else: ?>
                                            —
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- View Request Modal -->
<div class="modal fade" id="viewRequestModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-eye me-2"></i>Request Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="viewRequestContent">
                <!-- Content will be loaded here -->
            </div>
            <div class="modal-footer" id="modalFooterActions">
                <!-- Approve/Reject buttons will be dynamically added here for pending requests -->
            </div>
        </div>
    </div>
</div>
    <!-- Approve/Reject Modal -->
    <div class="modal fade" id="approvalModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header" id="approvalModalHeader">
                    <h5 class="modal-title" id="approvalModalTitle">Approve Request</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p id="approvalMessage">Approve this request?</p>
                    <?php if (!$view_all_branches && $customers_branch_column_exists): ?>
                       
                    <?php endif; ?>
                    <div id="approvalFields">
                        <div class="mb-3">
                            <label class="form-label">Admin Notes</label>
                            <textarea class="form-control" id="adminNotes" rows="3" placeholder="Enter any notes..."></textarea>
                        </div>
                        <div class="mb-3 expiration-slider">
                            <label class="form-label fw-bold">Validity Period (Days)</label>
                            <div class="expiration-number-wrap">
                                <div class="input-group expiration-number-input">
                                    <input 
                                        type="number" 
                                        class="form-control" 
                                        id="expirationDays" 
                                        min="1" 
                                        max="365" 
                                        value="30"
                                        oninput="updateExpirationValue(this.value)"
                                    >
                                    <span class="input-group-text">days</span>
                                </div>
                                <span class="expiration-value" id="expirationValue">30 days</span>
                            </div>
                            <small class="text-muted">Set how many days this approval will be valid. The customer can only use this until the expiration date.</small>
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

   <!-- File Preview Modal -->
<div class="modal fade" id="fileViewModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content bg-transparent border-0 shadow-none">
            <div class="modal-body p-0">
                <div class="attachment-container">
                    <div class="attachment-wrapper">
                        <!-- Close button -->
                        <button type="button" class="btn-close-attachment" data-bs-dismiss="modal" aria-label="Close">
                            <i class="bi bi-x-lg"></i>
                        </button>
                        <!-- Download button -->
                        <a href="#" id="downloadLink" class="btn-download-attachment" download>
                            <i class="bi bi-download"></i>
                        </a>
                        <!-- Content will be loaded here -->
                        <div class="attachment-content">
                            <div class="spinner-border text-light" role="status">
                                <span class="visually-hidden">Loading...</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Mobile Bottom Navigation - Clean Version (No Arrows) -->
<div class="mobile-nav" id="mobileNav">
    <?php 
    $current_page = basename($_SERVER['PHP_SELF']);
    $is_warehouse_page = in_array($current_page, ['current_inventory.php', 'bad_orders.php', 'pick_list_items.php', 'warehouses.php']);
    $is_supplier_page = in_array($current_page, ['purchase_order.php', 'supplier.php']);
    $is_customer_page = in_array($current_page, ['customer_list.php', 'approve_credit_requests.php', 'sales_order.php', 'collections.php']);
    $is_delivery_page = ($current_page == 'trip_tickets.php');
    $is_banking_page = in_array($current_page, ['deposit.php', 'Withdrawal.php', 'bank_statement.php', 'expenses.php']);
    ?>
    <ul class="nav">
        <!-- Dashboard -->
        <li class="nav-item">
            <a class="nav-link <?php echo ($current_page == 'branchdashboard.php') ? 'active' : ''; ?>" href="branchdashboard.php">
                <i class="bi bi-speedometer2"></i>
                <span>Dashboard</span>
            </a>
        </li>

        <!-- Warehouse Dropdown -->
        <li class="nav-item dropdown-more" id="warehouseMobileDropdown">
            <a class="nav-link more-btn <?php echo $is_warehouse_page ? 'active' : ''; ?>" href="#" onclick="toggleMobileDropdown(event, 'warehouseMobileMenu')">
                <i class="bi bi-shop"></i>
                <span>Warehouse</span>
            </a>
            <div class="more-dropdown" id="warehouseMobileMenu">
                <a href="current_inventory.php" class="dropdown-item <?php echo ($current_page == 'current_inventory.php') ? 'active' : ''; ?>">
                    <i class="bi bi-bar-chart-line"></i><span>Current Inventory</span>
                </a>
                <a href="bad_orders.php" class="dropdown-item <?php echo ($current_page == 'bad_orders.php') ? 'active' : ''; ?>">
                    <i class="bi bi-recycle"></i><span>Bad Orders</span>
                </a>
                <a href="pick_list_items.php" class="dropdown-item <?php echo ($current_page == 'pick_list_items.php') ? 'active' : ''; ?>">
                    <i class="bi bi-list-check"></i><span>Pick List Items</span>
                </a>
                <a href="warehouses.php" class="dropdown-item <?php echo ($current_page == 'warehouses.php') ? 'active' : ''; ?>">
                    <i class="bi bi-shop"></i><span>Warehouses</span>
                </a>
            </div>
        </li>

        <!-- Supplier Dropdown -->
        <li class="nav-item dropdown-more" id="supplierMobileDropdown">
            <a class="nav-link more-btn <?php echo $is_supplier_page ? 'active' : ''; ?>" href="#" onclick="toggleMobileDropdown(event, 'supplierMobileMenu')">
                <i class="bi bi-building"></i>
                <span>Supplier</span>
            </a>
            <div class="more-dropdown" id="supplierMobileMenu">
                <a href="purchase_order.php" class="dropdown-item <?php echo ($current_page == 'purchase_order.php') ? 'active' : ''; ?>">
                    <i class="bi bi-box"></i><span>Receive Inventory</span>
                </a>
                <a href="supplier.php" class="dropdown-item <?php echo ($current_page == 'supplier.php') ? 'active' : ''; ?>">
                    <i class="bi bi-people"></i><span>Supplier List</span>
                </a>
            </div>
        </li>

        <!-- Customer Dropdown -->
        <li class="nav-item dropdown-more" id="customerMobileDropdown">
            <a class="nav-link more-btn <?php echo $is_customer_page ? 'active' : ''; ?>" href="#" onclick="toggleMobileDropdown(event, 'customerMobileMenu')">
                <i class="bi bi-people"></i>
                <span>Customer</span>
            </a>
            <div class="more-dropdown" id="customerMobileMenu">
                <a href="customer_list.php" class="dropdown-item <?php echo ($current_page == 'customer_list.php') ? 'active' : ''; ?>">
                    <i class="bi bi-person-badge"></i><span>Customer List</span>
                </a>
                <a href="approve_credit_requests.php" class="dropdown-item <?php echo ($current_page == 'approve_credit_requests.php') ? 'active' : ''; ?>">
                    <i class="bi bi-pencil-square"></i><span>Approve Credit Request</span>
                </a>
                <a href="sales_order.php" class="dropdown-item <?php echo ($current_page == 'sales_order.php') ? 'active' : ''; ?>">
                    <i class="bi bi-cart"></i><span>Sales Order</span>
                </a>
                <a href="collections.php" class="dropdown-item <?php echo ($current_page == 'collections.php') ? 'active' : ''; ?>">
                    <i class="bi bi-cash-stack"></i><span>Collections</span>
                </a>
            </div>
        </li>

        <!-- Delivery Dropdown -->
        <li class="nav-item dropdown-more" id="deliveryMobileDropdown">
            <a class="nav-link more-btn <?php echo $is_delivery_page ? 'active' : ''; ?>" href="#" onclick="toggleMobileDropdown(event, 'deliveryMobileMenu')">
                <i class="bi bi-truck"></i>
                <span>Delivery</span>
            </a>
            <div class="more-dropdown" id="deliveryMobileMenu">
                <a href="trip_tickets.php" class="dropdown-item <?php echo ($current_page == 'trip_tickets.php') ? 'active' : ''; ?>">
                    <i class="bi bi-ticket-perforated"></i><span>Trip Tickets</span>
                </a>
            </div>
        </li>

        <!-- Banking Dropdown -->
        <li class="nav-item dropdown-more" id="bankingMobileDropdown">
            <a class="nav-link more-btn <?php echo $is_banking_page ? 'active' : ''; ?>" href="#" onclick="toggleMobileDropdown(event, 'bankingMobileMenu')">
                <i class="bi bi-bank2"></i>
                <span>Banking</span>
            </a>
            <div class="more-dropdown" id="bankingMobileMenu">
                <a href="deposit.php" class="dropdown-item <?php echo ($current_page == 'deposit.php') ? 'active' : ''; ?>">
                    <i class="bi bi-arrow-down-circle"></i><span>Deposit</span>
                </a>
                <a href="Withdrawal.php" class="dropdown-item <?php echo ($current_page == 'Withdrawal.php') ? 'active' : ''; ?>">
                    <i class="bi bi-arrow-up-circle"></i><span>Withdrawal</span>
                </a>
                <a href="bank_statement.php" class="dropdown-item <?php echo ($current_page == 'bank_statement.php') ? 'active' : ''; ?>">
                    <i class="bi bi-receipt"></i><span>Bank Statement</span>
                </a>
                <a href="expenses.php" class="dropdown-item <?php echo ($current_page == 'expenses.php') ? 'active' : ''; ?>">
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
            <a class="nav-link <?php echo ($current_page == 'drivers.php') ? 'active' : ''; ?>" href="drivers.php">
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


    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    
 <script>
    // ========== GLOBAL VARIABLES ==========
    let selectedRequestId = null;
    let currentAction = null;
    const branchId = <?php echo $branch_id; ?>;
    const viewAllBranches = <?php echo $view_all_branches ? 'true' : 'false'; ?>;
    const customersBranchColumnExists = <?php echo $customers_branch_column_exists ? 'true' : 'false'; ?>;
    const effectiveColumnsExist = <?php echo $effective_columns_exist ? 'true' : 'false'; ?>;
    const logoBase64 = '<?php echo $logo_base64; ?>';
    let globalScrollTimeout;

    // Update expiration value display
    function updateExpirationValue(value) {
        let num = parseInt(value, 10);
        if (isNaN(num) || num < 1) {
            num = 1;
        } else if (num > 365) {
            num = 365;
        }
        const input = document.getElementById('expirationDays');
        const label = document.getElementById('expirationValue');
        if (input) input.value = num;
        if (label) label.innerText = num + ' day' + (num > 1 ? 's' : '');
    }

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

    function showLoading() {
        document.getElementById('loadingOverlay').style.display = 'flex';
    }

    function hideLoading() {
        document.getElementById('loadingOverlay').style.display = 'none';
    }

    // ========== VIEW ATTACHMENT FUNCTION ==========
// ========== VIEW ATTACHMENT FUNCTION ==========
let fileViewModal;

function viewAttachment(filePath, fileName) {
    // Get the current view request modal instance
    const viewModalElement = document.getElementById('viewRequestModal');
    const viewModal = bootstrap.Modal.getInstance(viewModalElement);
    
    // Store the current content to restore later (optional)
    const currentContent = document.getElementById('viewRequestContent')?.innerHTML;
    if (currentContent) {
        sessionStorage.setItem('savedContent', currentContent);
    }
    
    // Hide the view request modal first with a class to prevent flicker
    if (viewModal) {
        viewModalElement.classList.add('hiding-for-attachment');
        viewModal.hide();
        setTimeout(() => {
            viewModalElement.classList.remove('hiding-for-attachment');
        }, 300);
    }
    
    // Store in session that we came from view modal
    sessionStorage.setItem('returnToViewModal', 'true');
    
    if (!fileViewModal) {
        fileViewModal = new bootstrap.Modal(document.getElementById('fileViewModal'));
    }
    
    const attachmentContent = document.querySelector('#fileViewModal .attachment-content');
    const downloadLink = document.getElementById('downloadLink');
    
    // Set download link
    downloadLink.href = filePath;
    downloadLink.download = fileName;
    
    const ext = fileName.split('.').pop().toLowerCase();
    
    // Clear previous content
    attachmentContent.innerHTML = '';
    
    if (['jpg', 'jpeg', 'png', 'gif'].includes(ext)) {
        const img = document.createElement('img');
        img.src = filePath;
        img.alt = fileName;
        img.style.opacity = '0';
        img.onload = function() {
            img.style.opacity = '1';
            console.log('Image loaded:', img.width, 'x', img.height);
        };
        attachmentContent.appendChild(img);
    } else if (ext === 'pdf') {
        const embed = document.createElement('embed');
        embed.src = filePath;
        embed.type = 'application/pdf';
        attachmentContent.appendChild(embed);
    } else {
        attachmentContent.innerHTML = `
            <div class="alert alert-info m-0">
                <i class="bi bi-info-circle me-2"></i> 
                This file type cannot be previewed directly. Please download to view.
            </div>
        `;
    }
    
    // Remove existing event listener to avoid duplicates
    document.getElementById('fileViewModal').removeEventListener('hidden.bs.modal', handleFileModalHidden);
    document.getElementById('fileViewModal').addEventListener('hidden.bs.modal', handleFileModalHidden);
    
    fileViewModal.show();
}

function handleFileModalHidden() {
    // Use requestAnimationFrame for smooth transition
    requestAnimationFrame(function() {
        // Clean up backdrops without flicker
        const backdrops = document.querySelectorAll('.modal-backdrop');
        if (backdrops.length > 1) {
            backdrops.forEach((backdrop, index) => {
                if (index < backdrops.length - 1 && backdrop.parentNode) {
                    backdrop.remove();
                }
            });
        }
        
        // Reset attachment content silently
        const attachmentContent = document.querySelector('#fileViewModal .attachment-content');
        if (attachmentContent) {
            attachmentContent.innerHTML = '<div class="spinner-border text-light" role="status"><span class="visually-hidden">Loading...</span></div>';
        }
        
        // Check if we need to return to view modal
        if (sessionStorage.getItem('returnToViewModal') === 'true') {
            sessionStorage.removeItem('returnToViewModal');
            
            // Get the view request modal element
            const viewModalElement = document.getElementById('viewRequestModal');
            if (viewModalElement) {
                // Use a very short delay and ensure no white flash
                setTimeout(function() {
                    // Ensure body has modal-open class
                    if (!document.body.classList.contains('modal-open')) {
                        document.body.classList.add('modal-open');
                    }
                    
                    // Show the modal without recreating the instance
                    const viewModal = bootstrap.Modal.getInstance(viewModalElement);
                    if (viewModal) {
                        viewModal.show();
                    } else {
                        new bootstrap.Modal(viewModalElement).show();
                    }
                    
                    // Ensure backdrop is correct
                    setTimeout(function() {
                        const modalBackdrop = document.querySelector('.modal-backdrop');
                        if (!modalBackdrop && viewModalElement.classList.contains('show')) {
                            const newBackdrop = document.createElement('div');
                            newBackdrop.className = 'modal-backdrop fade show';
                            document.body.appendChild(newBackdrop);
                        }
                    }, 50);
                }, 50);
            }
        } else {
            // If no modal to return to, just clean up
            const anyModalOpen = document.querySelector('.modal.show');
            if (!anyModalOpen) {
                if (backdrops.length > 0) {
                    backdrops.forEach(backdrop => {
                        if (backdrop && backdrop.parentNode) backdrop.remove();
                    });
                }
                document.body.classList.remove('modal-open');
                document.body.style.removeProperty('overflow');
                document.body.style.removeProperty('padding-right');
            }
        }
    });
}


    // ========== FILTER FUNCTION ==========
    function filterRequests() {
        console.log("filterRequests called");
        
        const statusFilter = document.getElementById('statusFilter');
        const typeFilter = document.getElementById('typeFilter');
        const searchInput = document.getElementById('searchInput');
        
        const statusValue = statusFilter ? statusFilter.value : 'all';
        const typeValue = typeFilter ? typeFilter.value : 'all';
        const searchTerm = searchInput ? searchInput.value.toLowerCase().trim() : '';
        
        console.log("Filters - Status:", statusValue, "Type:", typeValue, "Search:", searchTerm);
        
        const rows = document.querySelectorAll('#requestsTable tbody tr.request-row');
        let visibleCount = 0;
        
        rows.forEach(row => {
            if (row.querySelector('.empty-state-table')) {
                row.style.display = '';
                return;
            }
            
            let showRow = true;
            const rowStatus = row.getAttribute('data-status') || '';
            const rowType = row.getAttribute('data-type') || '';
            
            const customerCell = row.querySelector('td.col-customer');
            let customerName = '';
            let customerCode = '';
            
            if (customerCell) {
                const strongElement = customerCell.querySelector('strong');
                if (strongElement) {
                    customerName = strongElement.innerText.toLowerCase();
                }
                const smallElement = customerCell.querySelector('small');
                if (smallElement) {
                    customerCode = smallElement.innerText.toLowerCase();
                }
            }
            
            const dataCustomer = (row.getAttribute('data-customer') || '').toLowerCase();
            const searchableCustomerText = customerName + ' ' + customerCode + ' ' + dataCustomer;
            
            let agentName = (row.getAttribute('data-agent') || '').toLowerCase();
            const agentCell = row.querySelector('td.col-agent');
            if (agentCell && !agentName) {
                agentName = agentCell.innerText.toLowerCase();
            }
            
            const rowCredit = row.getAttribute('data-credit');
            const rowDiscount = row.getAttribute('data-discount');
            const hasCredit = rowCredit && rowCredit !== '' && rowCredit !== 'null' && parseFloat(rowCredit) > 0;
            const hasDiscount = rowDiscount && rowDiscount !== '' && rowDiscount !== 'null' && parseFloat(rowDiscount) > 0;
            
            if (statusValue !== 'all' && rowStatus !== statusValue) {
                showRow = false;
            }
            
            if (showRow && typeValue !== 'all') {
                switch (typeValue) {
                    case 'credit':
                        showRow = hasCredit;
                        break;
                    case 'discount':
                        showRow = hasDiscount;
                        break;
                    case 'both':
                        showRow = hasCredit && hasDiscount;
                        break;
                    case 'credit_terms':
                        showRow = rowType === 'credit_terms';
                        break;
                    default:
                        showRow = true;
                }
            }
            
            if (showRow && searchTerm !== '') {
                const searchableText = searchableCustomerText + ' ' + agentName;
                if (!searchableText.includes(searchTerm)) {
                    showRow = false;
                }
            }
            
            if (showRow) {
                row.style.display = '';
                visibleCount++;
            } else {
                row.style.display = 'none';
            }
        });
        
        console.log("Visible rows:", visibleCount, "Total rows:", rows.length);
        showEmptyFilterMessage(visibleCount === 0 && rows.length > 0);
        
        // Reinitialize tap to view after filtering
        setTimeout(function() {
            setupTapToView();
        }, 100);
    }
    
    function showEmptyFilterMessage(show) {
        let emptyMsg = document.getElementById('filterEmptyMessage');
        
        if (show) {
            if (!emptyMsg) {
                const tableBody = document.querySelector('#requestsTable tbody');
                if (tableBody && !tableBody.querySelector('#filterEmptyMessage')) {
                    emptyMsg = document.createElement('tr');
                    emptyMsg.id = 'filterEmptyMessage';
                    emptyMsg.innerHTML = `
                        <td colspan="9" class="empty-state-table">
                            <i class="bi bi-search"></i>
                            <h5>No matching requests</h5>
                            <p class="text-muted">Try adjusting your filters or search term</p>
                        </td>
                    `;
                    tableBody.appendChild(emptyMsg);
                }
            } else {
                emptyMsg.style.display = '';
            }
        } else {
            if (emptyMsg) {
                emptyMsg.style.display = 'none';
            }
        }
    }

    // ========== RE-INITIALIZE FILTER EVENTS ==========
    function initFilterEvents() {
        console.log("Initializing filter events");
        
        const statusFilter = document.getElementById('statusFilter');
        const typeFilter = document.getElementById('typeFilter');
        const searchInput = document.getElementById('searchInput');
        
        if (statusFilter) {
            statusFilter.removeAttribute('onchange');
            const newStatusFilter = statusFilter.cloneNode(true);
            statusFilter.parentNode.replaceChild(newStatusFilter, statusFilter);
            newStatusFilter.addEventListener('change', function() {
                filterRequests();
            });
        }
        
        if (typeFilter) {
            typeFilter.removeAttribute('onchange');
            const newTypeFilter = typeFilter.cloneNode(true);
            typeFilter.parentNode.replaceChild(newTypeFilter, typeFilter);
            newTypeFilter.addEventListener('change', function() {
                filterRequests();
            });
        }
        
        if (searchInput) {
            searchInput.removeAttribute('onkeyup');
            const newSearchInput = searchInput.cloneNode(true);
            searchInput.parentNode.replaceChild(newSearchInput, searchInput);
            newSearchInput.addEventListener('input', function() {
                filterRequests();
            });
            newSearchInput.addEventListener('keyup', function(e) {
                if (e.key === 'Enter') {
                    filterRequests();
                }
            });
        }
        
        console.log("Filter events initialized");
    }

    // ========== TAP TO VIEW - WORKS ON ALL DEVICES (Desktop & Mobile) ==========
    function setupTapToView() {
        const requestRows = document.querySelectorAll('#requestsTable tbody tr.request-row');
        
        requestRows.forEach(row => {
            // Skip empty state rows
            if (row.querySelector('.empty-state-table')) return;
            
            // Check if row is visible
            const isVisible = row.style.display !== 'none';
            
            if (isVisible) {
                // Remove existing listeners to avoid duplicates
                if (row.hasAttribute('data-tap-listener')) {
                    row.removeEventListener('click', handleRowClick);
                    row.removeAttribute('data-tap-listener');
                }
                
                // Add click listener to the entire row
                row.setAttribute('data-tap-listener', 'true');
                row.addEventListener('click', handleRowClick);
                row.style.cursor = 'pointer';
                
                // Add hover effect for desktop
                row.addEventListener('mouseenter', function() {
                    this.style.backgroundColor = '#f8f9fa';
                    this.style.transition = 'background-color 0.2s ease';
                });
                row.addEventListener('mouseleave', function() {
                    this.style.backgroundColor = '';
                });
            } else {
                // Remove listeners for hidden rows
                if (row.hasAttribute('data-tap-listener')) {
                    row.removeEventListener('click', handleRowClick);
                    row.removeAttribute('data-tap-listener');
                    row.style.cursor = '';
                }
            }
        });
    }

    // Handle row click
    function handleRowClick(event) {
        // Don't trigger if clicked on a button or inside a button
        if (event.target.closest('.btn-action') || 
            event.target.closest('.attachment-badge') || 
            event.target.closest('.btn')) {
            return;
        }
        
        const row = event.currentTarget;
        const requestId = row.getAttribute('data-id');
        if (requestId && typeof viewRequest === 'function') {
            event.preventDefault();
            viewRequest(requestId);
        }
    }

    // ========== VIEW REQUEST DETAILS ==========
    function viewRequest(id) {
        showLoading();
        
        const formData = new FormData();
        formData.append('action', 'view_request');
        formData.append('request_id', id);
        
        fetch('approve_credit_requests.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            hideLoading();
            
            if (data.success) {
                const r = data.request;
                const attachments = data.attachments || [];
                
                let typeText = r.request_type === 'credit' ? 'Credit Limit Increase' :
                               r.request_type === 'discount' ? 'Discount' :
                               r.request_type === 'both' ? 'Credit & Discount' :
                               r.request_type === 'credit_terms' ? 'Credit Terms' : 'Unknown';
                
                let creditHtml = r.requested_credit_limit ? '₱' + parseFloat(r.requested_credit_limit).toFixed(2) : '—';
                let discountHtml = r.requested_discount_percent ? r.requested_discount_percent + '%' : '—';
                let termsHtml = r.credit_terms_days ? r.credit_terms_days + ' days' : '—';
                let validityHtml = '—';
                if (r.effective_until) {
                    validityHtml = 'Until: ' + new Date(r.effective_until).toLocaleDateString();
                } else if (r.status === 'approved') {
                    validityHtml = 'No expiry set';
                }
                
                let statusClass = '';
                let isExpired = r.effective_until && new Date(r.effective_until) < new Date();
                if (r.status === 'pending') statusClass = 'status-pending';
                else if (r.status === 'approved' && isExpired) statusClass = 'status-expired';
                else if (r.status === 'approved') statusClass = 'status-approved';
                else statusClass = 'status-rejected';
                
                let statusDisplay = r.status;
                if (r.status === 'approved' && isExpired) statusDisplay = 'Expired';
                
                // Build attachments HTML
                let attachmentsHtml = '';
                if (attachments.length > 0) {
                    let attachmentItems = '';
                    attachments.forEach(att => {
                        const ext = att.original_file_name.split('.').pop().toLowerCase();
                        let iconClass = 'file-earmark-text';
                        if (['jpg', 'jpeg', 'png', 'gif'].includes(ext)) iconClass = 'file-earmark-image';
                        else if (ext === 'pdf') iconClass = 'file-earmark-pdf';
                        else if (['doc', 'docx'].includes(ext)) iconClass = 'file-earmark-word';
                        else if (['xls', 'xlsx'].includes(ext)) iconClass = 'file-earmark-excel';
                        
                        attachmentItems += `
                            <a href="#" class="attachment-item" onclick="viewAttachment('${att.file_path}', '${escapeHtml(att.original_file_name)}'); return false;">
                                <i class="bi bi-${iconClass}"></i>
                                ${escapeHtml(att.original_file_name)}
                                <small class="text-muted">(${(att.file_size / 1024).toFixed(1)} KB)</small>
                            </a>
                        `;
                    });
                    attachmentsHtml = `
                        <div class="attachment-section">
                            <div class="card-title">
                                <i class="bi bi-paperclip"></i> Supporting Documents (${attachments.length} files)
                            </div>
                            <div class="card-body">
                                <div class="attachment-list-modal">
                                    ${attachmentItems}
                                </div>
                            </div>
                        </div>
                    `;
                }
                
                let reasonHtml = escapeHtml(r.reason).replace(/\n/g, '<br>');
                if (!reasonHtml) reasonHtml = '—';
                
                const content = document.getElementById('viewRequestContent');
                content.innerHTML = `
                    <div class="row">
                        <div class="col-md-6">
                            <div class="info-card">
                                <div class="card-title">
                                    <i class="bi bi-info-circle"></i> Request Information
                                </div>
                                <div class="card-body">
                                    <div class="info-row">
                                        <div class="info-label">Request ID:</div>
                                        <div class="info-value">${r.request_id}</div>
                                    </div>
                                    <div class="info-row">
                                        <div class="info-label">Customer:</div>
                                        <div class="info-value"><strong>${escapeHtml(r.customer_name)}</strong><br><small>${escapeHtml(r.customer_code)}</small></div>
                                    </div>
                                    <div class="info-row">
                                        <div class="info-label">Agent:</div>
                                        <div class="info-value">${escapeHtml(r.first_name)} ${escapeHtml(r.last_name)}<br><small>${escapeHtml(r.agent_email)}</small></div>
                                    </div>
                                    <div class="info-row">
                                        <div class="info-label">Request Type:</div>
                                        <div class="info-value"><span class="type-badge ${r.request_type === 'credit' ? 'type-credit' : (r.request_type === 'discount' ? 'type-discount' : (r.request_type === 'both' ? 'type-both' : 'type-credit_terms'))}">${typeText}</span></div>
                                    </div>
                                    <div class="info-row">
                                        <div class="info-label">Credit Limit:</div>
                                        <div class="info-value">${creditHtml}</div>
                                    </div>
                                    <div class="info-row">
                                        <div class="info-label">Discount %:</div>
                                        <div class="info-value">${discountHtml}</div>
                                    </div>
                                    <div class="info-row">
                                        <div class="info-label">Credit Terms:</div>
                                        <div class="info-value">${termsHtml}</div>
                                    </div>
                                    <div class="info-row">
                                        <div class="info-label">Validity:</div>
                                        <div class="info-value">${validityHtml}</div>
                                    </div>
                                    <div class="info-row">
                                        <div class="info-label">Status:</div>
                                        <div class="info-value"><span class="status-badge ${statusClass}">${statusDisplay}</span></div>
                                    </div>
                                    <div class="info-row">
                                        <div class="info-label">Created:</div>
                                        <div class="info-value">${new Date(r.created_at).toLocaleString()}</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="info-card">
                                <div class="card-title">
                                    <i class="bi bi-chat-text"></i> Reason for Request
                                </div>
                                <div class="card-body">
                                    <div class="info-row">
                                        <div class="info-value">${reasonHtml}</div>
                                    </div>
                                    ${r.admin_notes ? `
                                    <div class="info-row mt-3">
                                        <div class="info-label">Admin Notes:</div>
                                        <div class="info-value">${escapeHtml(r.admin_notes).replace(/\n/g, '<br>')}</div>
                                    </div>
                                    ` : ''}
                                    ${r.approved_at ? `
                                    <div class="info-row mt-3">
                                        <div class="info-label">Approved at:</div>
                                        <div class="info-value">${new Date(r.approved_at).toLocaleString()}</div>
                                    </div>
                                    ` : ''}
                                </div>
                            </div>
                        </div>
                    </div>
                    ${attachmentsHtml}
                `;
                
                const modalFooter = document.getElementById('modalFooterActions');
                    if (modalFooter) {
                        // Clear existing buttons
                        modalFooter.innerHTML = '';
                        
                        if (r.status === 'pending') {
                            modalFooter.innerHTML += `
                                <button type="button" class="btn btn-danger" onclick="closeModalAndShowReject(${r.request_id})">
                                    <i class="bi bi-x-circle"></i> Reject
                                </button>
                                <button type="button" class="btn btn-success" onclick="closeModalAndShowApprove(${r.request_id})">
                                    <i class="bi bi-check-circle"></i> Approve
                                </button>
                            `;
                        }
                    }
                
                selectedRequestId = id;
                new bootstrap.Modal(document.getElementById('viewRequestModal')).show();
            } else {
                Swal.fire('Error', data.message, 'error');
            }
        })
        .catch(error => {
            hideLoading();
            Swal.fire('Error', 'An error occurred while fetching request details', 'error');
        });
    }

    function closeModalAndShowApprove(requestId) {
        const modal = bootstrap.Modal.getInstance(document.getElementById('viewRequestModal'));
        if (modal) {
            modal.hide();
        }
        setTimeout(() => {
            showApprovalModal(requestId, 'approve');
        }, 300);
    }

    function closeModalAndShowReject(requestId) {
        const modal = bootstrap.Modal.getInstance(document.getElementById('viewRequestModal'));
        if (modal) {
            modal.hide();
        }
        setTimeout(() => {
            showApprovalModal(requestId, 'reject');
        }, 300);
    }

    function escapeHtml(str) {
        if (!str) return '';
        return str.replace(/[&<>]/g, function(m) {
            if (m === '&') return '&amp;';
            if (m === '<') return '&lt;';
            if (m === '>') return '&gt;';
            return m;
        });
    }

    function showApprovalModal(id, action) {
        selectedRequestId = id;
        currentAction = action;
        
        const modalTitle = document.getElementById('approvalModalTitle');
        const modalHeader = document.getElementById('approvalModalHeader');
        const approvalMessage = document.getElementById('approvalMessage');
        const approvalFields = document.getElementById('approvalFields');
        const rejectionFields = document.getElementById('rejectionFields');
        const approveBtn = document.getElementById('approveBtn');
        const rejectBtn = document.getElementById('rejectBtn');
        
        if (action === 'approve') {
            modalTitle.textContent = 'Approve Request';
            modalHeader.className = 'modal-header bg-success text-white';
            approvalMessage.textContent = 'Approve this request?';
            approvalFields.style.display = 'block';
            rejectionFields.style.display = 'none';
            approveBtn.style.display = 'inline-block';
            rejectBtn.style.display = 'none';
            document.getElementById('adminNotes').value = '';
            document.getElementById('expirationDays').value = '30';
            updateExpirationValue(30);
        } else {
            modalTitle.textContent = 'Reject Request';
            modalHeader.className = 'modal-header bg-danger text-white';
            approvalMessage.textContent = 'Reject this request?';
            approvalFields.style.display = 'none';
            rejectionFields.style.display = 'block';
            approveBtn.style.display = 'none';
            rejectBtn.style.display = 'inline-block';
            document.getElementById('rejectionReason').value = '';
        }
        
        new bootstrap.Modal(document.getElementById('approvalModal')).show();
    }

    function confirmApproval(action) {
        if (action === 'approve') {
            const adminNotes = document.getElementById('adminNotes').value;
            let expirationDays = parseInt(document.getElementById('expirationDays').value, 10);

            if (isNaN(expirationDays) || expirationDays < 1) {
                Swal.fire('Warning', 'Please enter a valid number of days', 'warning');
                return;
            }

            if (expirationDays > 365) {
                expirationDays = 365;
                document.getElementById('expirationDays').value = 365;
                updateExpirationValue(365);
            }
            
            showLoading();
            
            const formData = new FormData();
            formData.append('action', 'approve_request');
            formData.append('request_id', selectedRequestId);
            formData.append('admin_notes', adminNotes);
            formData.append('expiration_days', expirationDays);
            
            fetch('approve_credit_requests.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                hideLoading();
                
                if (data.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Approved!',
                        text: data.message,
                        timer: 3000,
                        showConfirmButton: false
                    }).then(() => {
                        bootstrap.Modal.getInstance(document.getElementById('approvalModal')).hide();
                        location.reload();
                    });
                } else {
                    Swal.fire('Error', data.message, 'error');
                }
            })
            .catch(error => {
                hideLoading();
                console.error('Fetch error:', error);
                Swal.fire('Error', 'Network error: ' + error.message, 'error');
            });
        } else {
            const rejectionReason = document.getElementById('rejectionReason').value;
            
            if (!rejectionReason) {
                Swal.fire('Warning', 'Please enter a rejection reason', 'warning');
                return;
            }
            
            showLoading();
            
            const formData = new FormData();
            formData.append('action', 'reject_request');
            formData.append('request_id', selectedRequestId);
            formData.append('rejection_reason', rejectionReason);
            
            fetch('approve_credit_requests.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                hideLoading();
                
                if (data.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Rejected!',
                        text: data.message,
                        timer: 2000,
                        showConfirmButton: false
                    }).then(() => {
                        bootstrap.Modal.getInstance(document.getElementById('approvalModal')).hide();
                        location.reload();
                    });
                } else {
                    Swal.fire('Error', data.message, 'error');
                }
            })
            .catch(error => {
                hideLoading();
                Swal.fire('Error', 'An error occurred while rejecting request', 'error');
            });
        }
    }

    // ========== PRINT FUNCTION ==========
    function printRequests() {
        const printBtn = document.querySelector('.btn-outline-primary');
        if (printBtn && printBtn.innerText.includes('Print')) {
            const originalText = printBtn.innerHTML;
            printBtn.innerHTML = '<i class="bi bi-printer"></i> Preparing...';
            printBtn.disabled = true;
            
            setTimeout(() => {
                printBtn.innerHTML = originalText;
                printBtn.disabled = false;
            }, 2000);
        }

        const visibleRows = document.querySelectorAll('.request-row:not([style*="display: none"])');
        
        if (visibleRows.length === 0) {
            Swal.fire('Warning', 'No requests to print', 'warning');
            return;
        }
        
        let tableRows = '';
        let totalPending = 0, totalApproved = 0, totalRejected = 0;
        
        visibleRows.forEach(row => {
            const cells = row.querySelectorAll('td');
            let idx = 0;
            const id = cells[idx++]?.innerText || '';
            const date = cells[idx++]?.innerText || '';
            const customer = cells[idx]?.querySelector('strong')?.innerText || cells[idx]?.innerText || '';
            idx++;
            const agent = cells[idx++]?.innerText || '';
            const typeCell = cells[idx++]?.innerText.trim() || '';
            const credit = cells[idx++]?.innerText || '';
            const discount = cells[idx++]?.innerText || '';
            const terms = cells[idx++]?.innerText || '';
            const reason = cells[idx++]?.innerText || '';
            const status = cells[idx++]?.innerText || '';
            const validity = cells[idx++]?.innerText || '';
            
            if (status === 'Pending') totalPending++;
            else if (status === 'Approved') totalApproved++;
            else if (status === 'Rejected') totalRejected++;
            
            tableRows += '<tr>';
            tableRows += `<td style="padding: 3px; border: 1px solid #000;">${escapeHtml(id)}</td>`;
            tableRows += `<td style="padding: 3px; border: 1px solid #000;">${escapeHtml(date)}</td>`;
            tableRows += `<td style="padding: 3px; border: 1px solid #000;">${escapeHtml(customer)}</td>`;
            tableRows += `<td style="padding: 3px; border: 1px solid #000;">${escapeHtml(agent)}</td>`;
            tableRows += `<td style="padding: 3px; border: 1px solid #000;">${escapeHtml(typeCell)}</td>`;
            tableRows += `<td style="padding: 3px; border: 1px solid #000; text-align: right;">${escapeHtml(credit)}</td>`;
            tableRows += `<td style="padding: 3px; border: 1px solid #000; text-align: right;">${escapeHtml(discount)}</td>`;
            tableRows += `<td style="padding: 3px; border: 1px solid #000; text-align: right;">${escapeHtml(terms)}</td>`;
            tableRows += `<td style="padding: 3px; border: 1px solid #000;">${escapeHtml(reason)}</td>`;
            tableRows += `<td style="padding: 3px; border: 1px solid #000;">${escapeHtml(status)}</td>`;
            tableRows += `<td style="padding: 3px; border: 1px solid #000;">${escapeHtml(validity)}</td>`;
            tableRows += '</tr>';
        });
        
        const currentDate = new Date().toLocaleDateString('en-US', { 
            year: 'numeric', 
            month: 'short', 
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });
        
        const htmlContent = `<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Credit/Discount Requests</title><style>body{font-family:Arial;margin:0;padding:0;font-size:9px}.print-container{max-width:100%;margin:0}.print-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:5px;border-bottom:1px solid #000;padding-bottom:3px}.logo-section{display:flex;align-items:center;gap:5px}.company-logo{width:30px;height:auto}.company-info h1{font-size:14px;margin:0;font-weight:bold}.company-info p{font-size:8px;margin:0}.report-title h2{font-size:12px;margin:0}.report-title .date-info{font-size:8px}.summary-box{border:1px solid #000;padding:3px;margin-bottom:5px;display:flex}.summary-item{flex:1;text-align:center;border-right:1px solid #000}.summary-item:last-child{border-right:none}.summary-label{font-size:8px;font-weight:bold}.summary-value{font-size:11px;font-weight:bold}table{width:100%;border-collapse:collapse;font-size:8px}th{border:1px solid #000;padding:3px;text-align:left;font-weight:bold;background:white !important;color:black !important}td{border:1px solid #000;padding:3px}.print-footer{margin-top:5px;border-top:1px solid #000;padding-top:3px;display:flex;justify-content:space-between;font-size:8px}</style></head><body><div class="print-container"><div class="print-header"><div class="logo-section"><img src="${logoBase64}" alt="AMGC Logo" class="company-logo"><div class="company-info"><h1>AMGC</h1><p>Requests Report</p></div></div><div class="report-title"><h2>CREDIT/DISCOUNT REQUESTS</h2><div class="date-info">${currentDate}</div></div></div><div class="summary-box"><div class="summary-item"><div class="summary-label">Total</div><div class="summary-value">${visibleRows.length}</div></div><div class="summary-item"><div class="summary-label">Pending</div><div class="summary-value">${totalPending}</div></div><div class="summary-item"><div class="summary-label">Approved</div><div class="summary-value">${totalApproved}</div></div><div class="summary-item"><div class="summary-label">Rejected</div><div class="summary-value">${totalRejected}</div></div><div class="summary-item"><div class="summary-label">Branch</div><div class="summary-value">${!viewAllBranches ? 'Branch ' + branchId : 'All'}</div></div></div><table><thead><tr><th>ID</th><th>Date</th><th>Customer</th><th>Agent</th><th>Type</th><th style="text-align:right">Credit</th><th style="text-align:right">Discount</th><th style="text-align:right">Terms</th><th>Reason</th><th>Status</th><th>Validity</th></tr></thead><tbody>${tableRows}</tbody></table><div class="print-footer"><div>Generated: ${currentDate}</div><div>${document.querySelector('.user-name-sidebar')?.textContent || 'Branch Admin'}</div></div></div></body></html>`;
        
        hideLoading();
        
        const iframe = document.getElementById('printFrame');
        const iframeDoc = iframe.contentWindow.document;
        
        iframeDoc.open();
        iframeDoc.write(htmlContent);
        iframeDoc.close();
        
        setTimeout(() => {
            iframe.contentWindow.focus();
            iframe.contentWindow.print();
        }, 250);
    }

    // ========== EXCEL EXPORT ==========
    function exportToExcel() {
        const rows = document.querySelectorAll('.request-row:not([style*="display: none"])');
        if (rows.length === 0) {
            Swal.fire('Warning', 'No requests to export', 'warning');
            return;
        }
        
        const excelData = [['Request ID', 'Date', 'Customer', 'Agent', 'Type', 'Credit Limit (₱)', 'Discount (%)', 'Credit Terms (Days)', 'Reason', 'Status', 'Validity']];
        
        rows.forEach(row => {
            const cells = row.querySelectorAll('td');
            let idx = 0;
            const id = cells[idx++]?.innerText || '';
            const date = cells[idx++]?.innerText || '';
            const customer = cells[idx]?.querySelector('strong')?.innerText || cells[idx]?.innerText || '';
            idx++;
            const agent = cells[idx++]?.innerText || '';
            const type = cells[idx++]?.innerText || '';
            const credit = cells[idx++]?.innerText.replace('₱', '').replace(/,/g, '') || '';
            const discount = cells[idx++]?.innerText.replace('%', '') || '';
            const terms = cells[idx++]?.innerText.replace(' days', '') || '';
            const reason = cells[idx++]?.innerText || '';
            const status = cells[idx++]?.innerText || '';
            const validity = cells[idx++]?.innerText || '';
            
            excelData.push([id, date, customer, agent, type, credit, discount, terms, reason, status, validity]);
        });
        
        const wb = XLSX.utils.book_new();
        const ws = XLSX.utils.aoa_to_sheet(excelData);
        ws['!cols'] = [{ wch: 8 }, { wch: 15 }, { wch: 25 }, { wch: 20 }, { wch: 12 }, { wch: 15 }, { wch: 12 }, { wch: 15 }, { wch: 30 }, { wch: 12 }, { wch: 15 }];
        XLSX.utils.book_append_sheet(wb, ws, 'CreditDiscountRequests');
        
        const date = new Date();
        const dateStr = date.toISOString().slice(0,10).replace(/-/g, '');
        let filename = `Credit_Discount_Requests_${dateStr}`;
        if (!viewAllBranches) filename += `_Branch_${branchId}`;
        filename += '.xlsx';
        
        XLSX.writeFile(wb, filename);
        Swal.fire({ icon: 'success', title: 'Export Complete', timer: 2000, showConfirmButton: false });
    }

    // ========== COPY SQL FUNCTIONS ==========
    function copySQL() {
        const sql = `CREATE TABLE IF NOT EXISTS \`credit_discount_requests\` (...)`;
        navigator.clipboard.writeText(sql).then(() => {
            Swal.fire({ icon: 'success', title: 'Copied!', timer: 1500, showConfirmButton: false });
        });
    }
    
    function copyEffectiveSQL() {
        const sql = "ALTER TABLE `credit_discount_requests` ADD COLUMN `effective_from` datetime DEFAULT NULL AFTER `admin_notes`, ADD COLUMN `effective_until` datetime DEFAULT NULL AFTER `effective_from`;";
        navigator.clipboard.writeText(sql).then(() => {
            Swal.fire({ icon: 'success', title: 'Copied!', timer: 1500, showConfirmButton: false });
        });
    }
    
    function copyAttachmentsSQL() {
        const sql = `CREATE TABLE \`credit_discount_attachments\` (
  \`attachment_id\` int(11) NOT NULL AUTO_INCREMENT,
  \`request_id\` int(11) NOT NULL,
  \`file_name\` varchar(255) NOT NULL,
  \`original_file_name\` varchar(255) NOT NULL,
  \`file_path\` varchar(500) NOT NULL,
  \`file_size\` int(11) DEFAULT NULL,
  \`file_type\` varchar(100) DEFAULT NULL,
  \`uploaded_by\` int(11) NOT NULL,
  \`uploaded_at\` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (\`attachment_id\`),
  KEY \`request_id\` (\`request_id\`),
  KEY \`uploaded_by\` (\`uploaded_by\`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;`;
        navigator.clipboard.writeText(sql).then(() => {
            Swal.fire({ icon: 'success', title: 'Copied!', timer: 1500, showConfirmButton: false });
        });
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

    function showProfileModal() { 
        cleanupModalBackdrops();
        new bootstrap.Modal(document.getElementById('profileModal')).show(); 
    }

    function confirmLogout() {
        const modal = bootstrap.Modal.getInstance(document.getElementById('profileModal'));
        if (modal) {
            modal.hide();
        }
        
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

    
// ========== MOBILE BOTTOM NAV FIX ==========
window.closeAllMobileDropdowns=function(){
['inventoryDropdownMenu','salesDropdownMenu','purchaseDropdownMenu','moreDropdownMenu'].forEach(id=>{
 const d=document.getElementById(id);
 if(d) d.classList.remove('show');
});
document.querySelectorAll('.more-btn').forEach(btn=>{
 btn.classList.remove('active','has-active');
 btn.setAttribute('aria-expanded','false');
});
};

window.toggleMobileDropdown=function(event,dropdownId){
 event.preventDefault();
 event.stopPropagation();
 const dropdown=document.getElementById(dropdownId);
 const btn=event.currentTarget;
 if(!dropdown) return false;

 const opened=dropdown.classList.contains('show');
 window.closeAllMobileDropdowns();

 if(!opened){
   dropdown.classList.add('show');
   btn.classList.add('active');
   btn.setAttribute('aria-expanded','true');
 }
 return false;
};

window.toggleDropdown=function(event,dropdownId){
 return window.toggleMobileDropdown(event,dropdownId);
};

window.showProfileModal=function(event){
 if(event){event.preventDefault();event.stopPropagation();}
 cleanupModalBackdrops();
 window.closeAllMobileDropdowns();
 new bootstrap.Modal(document.getElementById('profileModal')).show();
 return false;
};

document.addEventListener('click',e=>{
 if(!e.target.closest('.mobile-nav')){
   window.closeAllMobileDropdowns();
 }
});

document.addEventListener('keydown',e=>{
 if(e.key==='Escape') window.closeAllMobileDropdowns();
});


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
        
        if (currentPage === 'approve_credit_requests.php') {
            const approveLink = document.querySelector('#moreDropdownMenu .dropdown-item[href="approve_credit_requests.php"]');
            if (approveLink) {
                approveLink.classList.add('active');
                const parentDropdown = approveLink.closest('.dropdown-more');
                if (parentDropdown) {
                    const parentBtn = parentDropdown.querySelector('.more-btn');
                    if (parentBtn) parentBtn.classList.add('has-active');
                }
            }
        }
        
        if (currentPage === 'drivers.php') {
            const usersLink = document.querySelector('#moreDropdownMenu .dropdown-item[href="drivers.php"]');
            if (usersLink) {
                usersLink.classList.add('active');
                const parentDropdown = usersLink.closest('.dropdown-more');
                if (parentDropdown) {
                    const parentBtn = parentDropdown.querySelector('.more-btn');
                    if (parentBtn) parentBtn.classList.add('has-active');
                }
            }
        }
    }

    function fixPurchaseDropdownPosition() {
        const purchaseDropdown = document.querySelector('#purchaseDropdown .more-dropdown');
        if (purchaseDropdown) {
            purchaseDropdown.style.setProperty('right', '0', 'important');
            purchaseDropdown.style.setProperty('left', 'auto', 'important');
        }
    }
    
    const purchaseMenu = document.getElementById('purchaseDropdownMenu');
    if (purchaseMenu) {
        new MutationObserver(mutations => {
            mutations.forEach(mutation => {
                if (mutation.type === 'attributes' && mutation.attributeName === 'class' && purchaseMenu.classList.contains('show')) {
                    fixPurchaseDropdownPosition();
                }
            });
        }).observe(purchaseMenu, { attributes: true });
    }
    
    window.addEventListener('resize', fixPurchaseDropdownPosition);

    // ===== FILTER TOGGLE - ENTIRE HEADER CLICKABLE =====
document.addEventListener('DOMContentLoaded', function() {
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
        const isExpanded = !filterContent.classList.contains('collapsed');
        
        if (isExpanded) {
            // Collapse
            filterContent.classList.add('collapsed');
            if (filterToggleBtn) filterToggleBtn.setAttribute('aria-expanded', 'false');
            if (filterIcon) {
                filterIcon.classList.remove('bi-chevron-up');
                filterIcon.classList.add('bi-chevron-down');
            }
        } else {
            // Expand
            filterContent.classList.remove('collapsed');
            if (filterToggleBtn) filterToggleBtn.setAttribute('aria-expanded', 'true');
            if (filterIcon) {
                filterIcon.classList.remove('bi-chevron-down');
                filterIcon.classList.add('bi-chevron-up');
            }
        }
    }
});

    // ===== MAIN INITIALIZATION =====
    document.addEventListener('DOMContentLoaded', function() {
        console.log("Approve Credit Requests - Branch Admin - Initializing");
        
        initializeSidebar();
        updateExpirationValue(document.getElementById('expirationDays')?.value || 30);
        
        initFilterEvents();
        
        // Setup tap to view (works on all devices)
        setupTapToView();
        
        setTimeout(function() {
            filterRequests();
        }, 100);
        
        const mobileMenuBtn = document.getElementById('mobileMenuBtn');
        if (mobileMenuBtn) {
            mobileMenuBtn.addEventListener('click', function() {
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
        }
        
        const desktopToggleBtn = document.getElementById('desktopToggleBtn');
        if (desktopToggleBtn) {
            desktopToggleBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                toggleSidebar();
            });
        }
        
        document.querySelectorAll('.sidebar .nav-link').forEach(link => {
            link.addEventListener('click', function() {
                if (window.innerWidth <= 992) closeMobileSidebar();
            });
        });

        document.addEventListener('click', function(event) {
            const sidebar = document.getElementById('sidebar');
            const mobileBtn = document.getElementById('mobileMenuBtn');
            const overlay = document.querySelector('.sidebar-overlay');
            const isMobile = window.innerWidth <= 992;
            
            if (isMobile && sidebar && sidebar.classList.contains('active') && 
                !sidebar.contains(event.target) && 
                mobileBtn && !mobileBtn.contains(event.target) &&
                !overlay?.contains(event.target)) {
                closeMobileSidebar();
            }
        });

        const modals = ['viewRequestModal', 'approvalModal', 'fileViewModal'];
            modals.forEach(modalId => {
                const modal = document.getElementById(modalId);
                if (modal) {
                    modal.addEventListener('hidden.bs.modal', function () {
                        // Use requestAnimationFrame to prevent visual flicker
                        requestAnimationFrame(function() {
                            const backdrops = document.querySelectorAll('.modal-backdrop');
                            // Only remove extra backdrops, keep the last one if needed
                            if (backdrops.length > 1) {
                                for (let i = 0; i < backdrops.length - 1; i++) {
                                    if (backdrops[i] && backdrops[i].parentNode) {
                                        backdrops[i].remove();
                                    }
                                }
                            } else if (backdrops.length === 1) {
                                // Check if any modal is still open
                                const anyModalOpen = document.querySelector('.modal.show');
                                if (!anyModalOpen) {
                                    backdrops[0].remove();
                                    document.body.classList.remove('modal-open');
                                    document.body.style.removeProperty('overflow');
                                    document.body.style.removeProperty('padding-right');
                                    document.body.style.removeProperty('position');
                                }
                            } else {
                                document.body.classList.remove('modal-open');
                                document.body.style.removeProperty('overflow');
                                document.body.style.removeProperty('padding-right');
                                document.body.style.removeProperty('position');
                            }
                        });
                    });
                }
            });
        
        setActiveMobileNav();
        fixPurchaseDropdownPosition();
        
        let resizeTimer;
        window.addEventListener('resize', function() {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(function() {
                console.log("Window resized, reinitializing...");
                setupTapToView();
                filterRequests();
            }, 250);
        });
        
        window.addEventListener('orientationchange', function() {
            setTimeout(function() {
                setupTapToView();
                filterRequests();
            }, 100);
        });
    });

    // Keyboard shortcuts
    document.addEventListener('keydown', function(e) {
        if (e.ctrlKey && e.key === 'b' && window.innerWidth > 992) {
            e.preventDefault();
            toggleSidebar();
        } else if (e.ctrlKey && e.key === 'f') {
            e.preventDefault();
            const searchInput = document.getElementById('searchInput');
            if (searchInput) searchInput.focus();
        } else if (e.ctrlKey && e.key === 'p') {
            e.preventDefault();
            printRequests();
        }
    });

    // Observe table changes
    const tableObserver = new MutationObserver(function(mutations) {
        mutations.forEach(function(mutation) {
            if (mutation.type === 'childList' && mutation.addedNodes.length > 0) {
                console.log("Table changed, reinitializing tap view");
                setupTapToView();
            }
        });
    });

    const tableBody = document.querySelector('#requestsTable tbody');
    if (tableBody) {
        tableObserver.observe(tableBody, { childList: true, subtree: true });
    }
    
    // ========== SIDEBAR DROPDOWN HANDLING ==========
    function toggleSidebarDropdown(event, targetId) {
        event.preventDefault();
        event.stopPropagation();
        
        const target = document.getElementById(targetId);
        const btn = event.currentTarget;
        const arrow = btn.querySelector('.dropdown-arrow');
        const sidebar = document.getElementById('sidebar');
        
        if (sidebar.classList.contains('collapsed')) {
            sidebar.classList.remove('collapsed');
            localStorage.setItem('sidebarCollapsed', 'false');
            
            setTimeout(() => {
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
            }, 50);
            return;
        }
        
        if (target.classList.contains('show')) {
            target.classList.remove('show');
            if (arrow) arrow.style.transform = 'translateY(-50%) rotate(0deg)';
        } else {
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

    function setActiveSidebarItem() {
        const currentPage = window.location.pathname.split('/').pop();
        
        document.querySelectorAll('.sidebar .nav-link').forEach(link => {
            link.classList.remove('active');
        });
        
        document.querySelectorAll('.sidebar .nav-link').forEach(link => {
            const href = link.getAttribute('href');
            if (href === currentPage) {
                link.classList.add('active');
                
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

    function updateDropdownParentActiveState() {
        const sidebar = document.getElementById('sidebar');
        if (!sidebar) return;
        
        if (sidebar.classList.contains('collapsed')) {
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

    function expandActiveDropdownContainers() {
        const sidebar = document.getElementById('sidebar');
        if (!sidebar) return;
        
        const dropdownNavs = document.querySelectorAll('.sidebar .dropdown-nav');
        
        dropdownNavs.forEach(dropdownNav => {
            const activeLink = dropdownNav.querySelector('.nav-link.active');
            
            if (activeLink) {
                const collapseDiv = dropdownNav.querySelector('.collapse');
                
                if (collapseDiv && !collapseDiv.classList.contains('show')) {
                    collapseDiv.classList.add('show');
                    
                    const parentLink = dropdownNav.querySelector(':scope > .nav-link');
                    if (parentLink) {
                        const arrow = parentLink.querySelector('.dropdown-arrow');
                        if (arrow) {
                            arrow.style.transform = 'translateY(-50%) rotate(180deg)';
                        }
                        if (sidebar.classList.contains('collapsed')) {
                            parentLink.classList.add('active');
                        }
                    }
                }
            }
        });
    }

    // Re-declare toggleSidebar to avoid duplicate
    window.toggleSidebar = function() {
        const sidebar = document.getElementById('sidebar');
        const desktopToggleBtn = document.getElementById('desktopToggleBtn');
        
        if (window.innerWidth <= 992) {
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
            const wasCollapsed = sidebar.classList.contains('collapsed');
            sidebar.classList.toggle('collapsed');
            localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
            
            if (wasCollapsed && !sidebar.classList.contains('collapsed')) {
                sidebar.style.width = '';
                
                setTimeout(function() {
                    expandActiveDropdownContainers();
                }, 150);
            }
        }
    };

    // Initialize sidebar on DOM load
    document.addEventListener('DOMContentLoaded', function() {
        const sidebar = document.getElementById('sidebar');
        if (sidebar && window.innerWidth > 992) {
            const isCollapsed = localStorage.getItem('sidebarCollapsed') === 'true';
            if (isCollapsed) {
                sidebar.classList.add('collapsed');
            } else {
                sidebar.classList.remove('collapsed');
            }
        }
        
        setActiveSidebarItem();
        updateDropdownParentActiveState();
        
        document.querySelectorAll('.sidebar .collapse').forEach(collapse => {
            collapse.addEventListener('click', function(e) {
                e.stopPropagation();
            });
        });
        
        const desktopToggleBtn = document.getElementById('desktopToggleBtn');
        if (desktopToggleBtn) {
            desktopToggleBtn.addEventListener('click', function() {
                setTimeout(() => {
                    if (sidebar.classList.contains('collapsed')) {
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
        
        const mobileMenuBtn = document.getElementById('mobileMenuBtn');
        if (mobileMenuBtn) {
            mobileMenuBtn.addEventListener('click', toggleSidebar);
        }
        
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