<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../config/database.php';
require_once '../config/session_handler.php';

// Protect page - only Sales role can access
requireLogin();
requireRole(['sales']);

// Get current user info (fixed: never null)
$user_id = $_SESSION['user_id'] ?? 0;
$first_name = $_SESSION['first_name'] ?? '';
$last_name  = $_SESSION['last_name'] ?? '';
$user_name = trim($first_name . ' ' . $last_name);
if (empty($user_name)) {
    $user_name = 'Sales User';
}

$branch_id = (int)($_SESSION['branch_id'] ?? 0);
$view_all_branches = $_SESSION['view_all_branches'] ?? false;

// Get user initials for avatar (fixed: explode only on valid string)
$user_initials = '';
if (!empty($user_name) && is_string($user_name)) {
    $name_parts = explode(' ', trim($user_name));
    foreach ($name_parts as $part) {
        if (!empty($part)) {
            $user_initials .= strtoupper(substr($part, 0, 1));
        }
    }
}
if (empty($user_initials)) {
    $user_initials = 'SL';
}

// Create upload directory if not exists
$upload_dir = '../uploads/credit_discount_attachments/';
if (!file_exists($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

// ------------------------------------------------------------
// Check if the credit_discount_requests table exists
// ------------------------------------------------------------
$tableExists = false;
$checkTable = $conn->query("SHOW TABLES LIKE 'credit_discount_requests'");
if ($checkTable && $checkTable->num_rows > 0) {
    $tableExists = true;
}

// ------------------------------------------------------------
// Check if attachments table exists
// ------------------------------------------------------------
$attachmentsTableExists = false;
$checkAttachmentsTable = $conn->query("SHOW TABLES LIKE 'credit_discount_attachments'");
if ($checkAttachmentsTable && $checkAttachmentsTable->num_rows > 0) {
    $attachmentsTableExists = true;
}

// ------------------------------------------------------------
// Check if credit_terms_days column exists
// ------------------------------------------------------------
$credit_terms_column_exists = false;
if ($tableExists) {
    $checkColumn = $conn->query("SHOW COLUMNS FROM credit_discount_requests LIKE 'credit_terms_days'");
    if ($checkColumn && $checkColumn->num_rows > 0) {
        $credit_terms_column_exists = true;
    }
}

// ------------------------------------------------------------
// Check if effective_from and effective_until columns exist
// ------------------------------------------------------------
$effective_columns_exist = false;
if ($tableExists) {
    $checkEffectiveFrom = $conn->query("SHOW COLUMNS FROM credit_discount_requests LIKE 'effective_from'");
    $checkEffectiveUntil = $conn->query("SHOW COLUMNS FROM credit_discount_requests LIKE 'effective_until'");
    if ($checkEffectiveFrom && $checkEffectiveFrom->num_rows > 0 && 
        $checkEffectiveUntil && $checkEffectiveUntil->num_rows > 0) {
        $effective_columns_exist = true;
    }
}

// ------------------------------------------------------------
// Check if amount-based discount columns exist
// ------------------------------------------------------------
$discount_amount_columns_exist = false;
if ($tableExists) {
    $checkDiscountType = $conn->query("SHOW COLUMNS FROM credit_discount_requests LIKE 'discount_calculation_type'");
    $checkDiscountBasedAmount = $conn->query("SHOW COLUMNS FROM credit_discount_requests LIKE 'discount_based_amount'");
    $checkCalculatedDiscountAmount = $conn->query("SHOW COLUMNS FROM credit_discount_requests LIKE 'calculated_discount_amount'");
    if ($checkDiscountType && $checkDiscountType->num_rows > 0 &&
        $checkDiscountBasedAmount && $checkDiscountBasedAmount->num_rows > 0 &&
        $checkCalculatedDiscountAmount && $checkCalculatedDiscountAmount->num_rows > 0) {
        $discount_amount_columns_exist = true;
    }
}

// ------------------------------------------------------------
// Check if branch_id column exists in customers table
// ------------------------------------------------------------
$branch_column_exists = false;
$check = $conn->query("SHOW COLUMNS FROM customers LIKE 'branch_id'");
if ($check && $check->num_rows > 0) {
    $branch_column_exists = true;
}

// ------------------------------------------------------------
// Helper to add branch filter to customer query
// ------------------------------------------------------------
function customerBranchFilter() {
    global $view_all_branches, $branch_id, $branch_column_exists;
    if (!$branch_column_exists || $view_all_branches) return '';
    return " AND branch_id = " . intval($branch_id);
}

// ------------------------------------------------------------
// Get active customers for dropdown
// ------------------------------------------------------------
$customers_query = "SELECT customer_id, customer_name 
                    FROM customers 
                    WHERE status = 'active' 
                    " . customerBranchFilter() . "
                    ORDER BY customer_name ASC";
$customers_result = $conn->query($customers_query);
$customers = [];
if ($customers_result) {
    $customers = $customers_result->fetch_all(MYSQLI_ASSOC);
}

// ------------------------------------------------------------
// Handle form submission (only if table exists)
// ------------------------------------------------------------
$success_message = '';
$error_message = '';

if ($tableExists && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_request') {
    $customer_id = (int)($_POST['customer_id'] ?? 0);
    $request_type = $_POST['request_type'] ?? '';
    $credit_limit = !empty($_POST['credit_limit']) ? (float)$_POST['credit_limit'] : null;
    $discount_calculation_type = $_POST['discount_calculation_type'] ?? 'percentage';
    if (!in_array($discount_calculation_type, ['percentage', 'amount_based'])) {
        $discount_calculation_type = 'percentage';
    }
    $discount_percent = !empty($_POST['discount_percent']) ? (float)$_POST['discount_percent'] : null;
    $discount_based_amount = !empty($_POST['discount_based_amount']) ? (float)$_POST['discount_based_amount'] : null;
    $calculated_discount_amount = null;
    if ($discount_calculation_type === 'amount_based') {
        $discount_percent = null;
        $calculated_discount_amount = $discount_based_amount;
    } else {
        $discount_based_amount = null;
    }
    $credit_terms_days = !empty($_POST['credit_terms_days']) ? (int)$_POST['credit_terms_days'] : null;
    
    // Handle reason: either from dropdown or custom text
    $reason_select = $_POST['reason_select'] ?? '';
    $reason_other = trim($_POST['reason_other'] ?? '');
    if ($reason_select === 'Other' && !empty($reason_other)) {
        $reason = $reason_other;
    } else {
        $reason = $reason_select;
    }
    
    // Basic validation
    if ($customer_id <= 0) {
        $error_message = 'Please select a customer.';
    } elseif (!in_array($request_type, ['discount', 'credit_terms', 'both'])) {
        $error_message = 'Invalid request type.';
    } elseif (($request_type === 'credit_terms' || $request_type === 'both') && ($credit_limit === null || $credit_limit <= 0)) {
        $error_message = 'Please enter a valid credit limit.';
    } elseif (($request_type === 'credit_terms' || $request_type === 'both') && ($credit_terms_days === null || $credit_terms_days <= 0)) {
        $error_message = 'Please enter a valid number of days for credit terms.';
    } elseif (($request_type === 'discount' || $request_type === 'both') && $discount_calculation_type === 'percentage' && ($discount_percent === null || $discount_percent <= 0 || $discount_percent > 100)) {
        $error_message = 'Please enter a valid discount percentage (1-100).';
    } elseif (($request_type === 'discount' || $request_type === 'both') && $discount_calculation_type === 'amount_based' && ($discount_based_amount === null || $discount_based_amount <= 0)) {
        $error_message = 'Please enter a valid discount amount.';
    } elseif (($request_type === 'discount' || $request_type === 'both') && $discount_calculation_type === 'amount_based' && !$discount_amount_columns_exist) {
        $error_message = 'Database columns for amount-based discount are missing. Please run the ALTER TABLE SQL shown on this page.';
    } elseif (empty($reason)) {
        $error_message = 'Please provide a reason for the request.';
    } else {
        // Check if customer already has a pending request today
        $check_pending = $conn->prepare("
            SELECT request_id, status, created_at 
            FROM credit_discount_requests 
            WHERE customer_id = ? 
            AND status = 'pending' 
            AND DATE(created_at) = CURDATE()
        ");
        $check_pending->bind_param('i', $customer_id);
        $check_pending->execute();
        $pending_result = $check_pending->get_result();
        
        if ($pending_result->num_rows > 0) {
            $existing = $pending_result->fetch_assoc();
            $error_message = 'This customer already has a pending request submitted today. Please wait for the current request to be processed before submitting a new one.';
        } else {
            // Check if customer has an active approved request that hasn't expired
            $check_active = $conn->prepare("
                SELECT request_id, request_type, requested_credit_limit, requested_discount_percent, 
                       credit_terms_days, effective_from, effective_until
                FROM credit_discount_requests 
                WHERE customer_id = ? 
                AND status = 'approved'
                AND (effective_until IS NULL OR effective_until > NOW())
                ORDER BY created_at DESC 
                LIMIT 1
            ");
            $check_active->bind_param('i', $customer_id);
            $check_active->execute();
            $active_result = $check_active->get_result();
            
            if ($active_result->num_rows > 0) {
                $active = $active_result->fetch_assoc();
                $expiry_text = '';
                if ($active['effective_until']) {
                    $expiry_date = date('M d, Y', strtotime($active['effective_until']));
                    $expiry_text = " (expires on $expiry_date)";
                }
                $error_message = 'This customer already has an active approved request' . $expiry_text . '. Please wait until it expires before submitting a new request.';
            } else {
                // Verify customer belongs to agent's branch (if column exists and not admin)
                if ($branch_column_exists && !$view_all_branches) {
                    $check_cust = $conn->prepare("SELECT customer_id FROM customers WHERE customer_id = ? AND branch_id = ?");
                    $check_cust->bind_param('ii', $customer_id, $branch_id);
                    $check_cust->execute();
                    $cust_result = $check_cust->get_result();
                    if ($cust_result->num_rows === 0) {
                        $error_message = 'Selected customer does not belong to your branch.';
                    }
                }

                if (empty($error_message)) {
                    // Insert request - use dynamic SQL based on column existence
                    if ($credit_terms_column_exists && $discount_amount_columns_exist) {
                        $insert_sql = "INSERT INTO credit_discount_requests 
                                       (customer_id, agent_id, request_type, requested_credit_limit, requested_discount_percent, discount_calculation_type, discount_based_amount, calculated_discount_amount, credit_terms_days, reason, status)
                                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')";
                        $stmt = $conn->prepare($insert_sql);
                        $stmt->bind_param('iisddsddis', $customer_id, $user_id, $request_type, $credit_limit, $discount_percent, $discount_calculation_type, $discount_based_amount, $calculated_discount_amount, $credit_terms_days, $reason);
                    } elseif ($credit_terms_column_exists) {
                        $insert_sql = "INSERT INTO credit_discount_requests 
                                       (customer_id, agent_id, request_type, requested_credit_limit, requested_discount_percent, credit_terms_days, reason, status)
                                       VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')";
                        $stmt = $conn->prepare($insert_sql);
                        $stmt->bind_param('iisddis', $customer_id, $user_id, $request_type, $credit_limit, $discount_percent, $credit_terms_days, $reason);
                    } elseif ($discount_amount_columns_exist) {
                        $insert_sql = "INSERT INTO credit_discount_requests 
                                       (customer_id, agent_id, request_type, requested_credit_limit, requested_discount_percent, discount_calculation_type, discount_based_amount, calculated_discount_amount, reason, status)
                                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')";
                        $stmt = $conn->prepare($insert_sql);
                        $stmt->bind_param('iisddsdds', $customer_id, $user_id, $request_type, $credit_limit, $discount_percent, $discount_calculation_type, $discount_based_amount, $calculated_discount_amount, $reason);
                    } else {
                        $insert_sql = "INSERT INTO credit_discount_requests 
                                       (customer_id, agent_id, request_type, requested_credit_limit, requested_discount_percent, reason, status)
                                       VALUES (?, ?, ?, ?, ?, ?, 'pending')";
                        $stmt = $conn->prepare($insert_sql);
                        $stmt->bind_param('iisdds', $customer_id, $user_id, $request_type, $credit_limit, $discount_percent, $reason);
                    }
                    
                    if ($stmt->execute()) {
                        $request_id = $conn->insert_id;
                        
                        // Handle file uploads
                        $upload_errors = [];
                        $upload_success = true;
                        
                        if ($attachmentsTableExists && isset($_FILES['attachments']) && !empty($_FILES['attachments']['name'][0])) {
                            $total_files = count($_FILES['attachments']['name']);
                            
                            for ($i = 0; $i < $total_files; $i++) {
                                if ($_FILES['attachments']['error'][$i] === UPLOAD_ERR_OK) {
                                    $file_name = $_FILES['attachments']['name'][$i];
                                    $file_tmp = $_FILES['attachments']['tmp_name'][$i];
                                    $file_size = $_FILES['attachments']['size'][$i];
                                    $file_type = $_FILES['attachments']['type'][$i];
                                    
                                    // Validate file size (max 10MB)
                                    if ($file_size > 10 * 1024 * 1024) {
                                        $upload_errors[] = "File '$file_name' exceeds 10MB limit.";
                                        $upload_success = false;
                                        continue;
                                    }
                                    
                                    // Validate file extension
                                    $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx', 'xls', 'xlsx'];
                                    $file_extension = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
                                    if (!in_array($file_extension, $allowed_extensions)) {
                                        $upload_errors[] = "File '$file_name' has invalid extension. Allowed: " . implode(', ', $allowed_extensions);
                                        $upload_success = false;
                                        continue;
                                    }
                                    
                                    // Generate unique file name
                                    $unique_filename = time() . '_' . $request_id . '_' . $i . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', $file_name);
                                    $file_path = $upload_dir . $unique_filename;
                                    
                                    if (move_uploaded_file($file_tmp, $file_path)) {
                                        // Insert attachment record
                                        $insert_attachment = $conn->prepare("
                                            INSERT INTO credit_discount_attachments 
                                            (request_id, file_name, original_file_name, file_path, file_size, file_type, uploaded_by)
                                            VALUES (?, ?, ?, ?, ?, ?, ?)
                                        ");
                                        $insert_attachment->bind_param('isssisi', $request_id, $unique_filename, $file_name, $file_path, $file_size, $file_type, $user_id);
                                        $insert_attachment->execute();
                                    } else {
                                        $upload_errors[] = "Failed to upload file '$file_name'.";
                                        $upload_success = false;
                                    }
                                }
                            }
                        }
                        
                        $success_message = 'Request submitted successfully!';
                        if (!empty($upload_errors)) {
                            $success_message .= ' However, some files failed to upload: ' . implode(' ', $upload_errors);
                        } elseif ($attachmentsTableExists && isset($_FILES['attachments']) && !empty($_FILES['attachments']['name'][0])) {
                            $success_message .= ' Attachments uploaded successfully.';
                        }
                    } else {
                        $error_message = 'Database error: ' . $stmt->error;
                    }
                }
            }
        }
    }
}

// ------------------------------------------------------------
// Get recent requests by this agent (only if table exists)
// ------------------------------------------------------------
$recent_requests = [];
if ($tableExists) {
    if ($credit_terms_column_exists && $effective_columns_exist) {
        $recent_query = "SELECT r.*, c.customer_name 
                         FROM credit_discount_requests r
                         JOIN customers c ON r.customer_id = c.customer_id
                         WHERE r.agent_id = ?
                         ORDER BY r.created_at DESC
                         LIMIT 10";
    } elseif ($credit_terms_column_exists) {
        $recent_query = "SELECT r.*, c.customer_name, NULL as effective_from, NULL as effective_until
                         FROM credit_discount_requests r
                         JOIN customers c ON r.customer_id = c.customer_id
                         WHERE r.agent_id = ?
                         ORDER BY r.created_at DESC
                         LIMIT 10";
    } else {
        $recent_query = "SELECT r.*, c.customer_name, NULL as credit_terms_days, NULL as effective_from, NULL as effective_until
                         FROM credit_discount_requests r
                         JOIN customers c ON r.customer_id = c.customer_id
                         WHERE r.agent_id = ?
                         ORDER BY r.created_at DESC
                         LIMIT 10";
    }
    $stmt = $conn->prepare($recent_query);
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $recent_result = $stmt->get_result();
    $recent_requests = $recent_result ? $recent_result->fetch_all(MYSQLI_ASSOC) : [];
}

// Get attachments for recent requests
$attachments_by_request = [];
if ($attachmentsTableExists && !empty($recent_requests)) {
    $request_ids = array_column($recent_requests, 'request_id');
    if (!empty($request_ids)) {
        $ids_string = implode(',', array_fill(0, count($request_ids), '?'));
        $types = str_repeat('i', count($request_ids));
        $attachment_query = "SELECT * FROM credit_discount_attachments WHERE request_id IN ($ids_string) ORDER BY uploaded_at ASC";
        $stmt = $conn->prepare($attachment_query);
        $stmt->bind_param($types, ...$request_ids);
        $stmt->execute();
        $attachments_result = $stmt->get_result();
        while ($attachment = $attachments_result->fetch_assoc()) {
            $attachments_by_request[$attachment['request_id']][] = $attachment;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Credit / Discount Request</title>
    <link rel="icon" type="image/png" href="../Pictures/favicon-96x96.png" sizes="96x96" />
    <link rel="stylesheet" href="../css/sales.css">
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <!-- SweetAlert2 -->
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
                .stat-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.04);
            display: flex;
            align-items: center;
            transition: transform 0.2s, box-shadow 0.2s;
            height: 100%;
            border: 1px solid #eef2f6;
        }
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 20px rgba(0,0,0,0.08);
        }
        .stat-icon {
            font-size: 2.5rem;
            margin-right: 20px;
            width: 60px;
            height: 60px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 12px;
        }
        .badge-status {
            padding: 6px 12px;
            border-radius: 20px;
            font-weight: 500;
            font-size: 0.75rem;
        }
        .status-pending { background: #fff3e0; color: #e65100; }
        .status-approved { background: #e8f5e9; color: #2e7d32; }
        .status-rejected { background: #ffebee; color: #c62828; }
        .status-expired { background: #e9ecef; color: #6c757d; }

        /* ===== TIGHTER SPACING ===== */
        .main-content {
            padding-top: 8px !important;
        }
        .navbar-top {
            margin-bottom: 8px !important;
        }
        .alert {
            margin-bottom: 8px !important;
            padding: 8px 12px !important;
        }
        .card {
            margin-bottom: 12px !important;
        }
        .card-header {
            padding: 8px 15px !important;
        }
        .card-body {
            padding: 12px !important;
        }
        /* Reduce gap between form rows */
        .row.g-3 {
            --bs-gutter-y: 0.5rem !important;
            --bs-gutter-x: 1rem !important;
        }
        .row.g-3 > [class*="col-"] {
            margin-top: 0.5rem !important;
        }
        /* Smaller form controls */
        .form-control, .form-select {
            padding: 0.375rem 0.5rem !important;
            font-size: 0.9rem !important;
        }
        .form-label {
            margin-bottom: 0.2rem !important;
            font-size: 0.85rem !important;
        }
        small.text-muted {
            font-size: 0.75rem !important;
        }
        /* ===== TABLE CENTERING ===== */
        .table td, .table th {
            padding: 0.5rem !important;
            vertical-align: middle;
            text-align: center;
        }
        .table thead th {
            padding: 0.5rem !important;
            font-size: 0.85rem;
            text-align: center;
        }
        .table tbody td {
            font-size: 0.85rem;
        }
        /* Left-align the Reason column for readability */
        .table td:nth-child(7) {
            text-align: left;
        }
        /* Badge inside table */
        .badge {
            font-size: 0.7rem !important;
            padding: 0.3rem 0.5rem !important;
        }
        /* Validity column styling */
        .validity-active {
            color: #2e7d32;
            font-weight: 500;
        }
        .validity-expired {
            color: #c62828;
            font-weight: 500;
        }
        .validity-soon {
            color: #e65100;
            font-weight: 500;
        }
        /* Active request warning */
        .active-request-warning {
            background-color: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 10px 15px;
            margin-bottom: 15px;
            border-radius: 8px;
        }
        .active-request-warning i {
            color: #ffc107;
            margin-right: 8px;
        }
        /* Tooltip for validity */
        .validity-tooltip {
            cursor: help;
            border-bottom: 1px dashed #999;
        }
        /* Attachment styles */
        .attachment-list {
            display: flex;
            flex-wrap: wrap;
            gap: 5px;
            justify-content: center;
        }
        .attachment-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            background: #f0f0f0;
            padding: 3px 8px;
            border-radius: 15px;
            font-size: 0.7rem;
            color: #333;
            text-decoration: none;
            transition: all 0.2s;
        }
        .attachment-badge:hover {
            background: #e0e0e0;
            color: #000;
        }
        .attachment-badge i {
            font-size: 0.7rem;
        }
        .file-preview {
            margin-top: 10px;
            padding: 10px;
            background: #f8f9fa;
            border-radius: 8px;
            border: 1px dashed #dee2e6;
        }
        .file-preview-item {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: white;
            padding: 5px 10px;
            border-radius: 20px;
            margin: 5px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .remove-file {
            cursor: pointer;
            color: #dc3545;
            font-size: 0.8rem;
        }
        .remove-file:hover {
            color: #a71d2a;
        }
        .file-input-wrapper {
            position: relative;
        }
        .file-input-wrapper .btn {
            margin-bottom: 5px;
        }
        /* ===== MODERN FORM STYLES ===== */
/* Gradient Header - COMPACT VERSION */
.bg-gradient-primary {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
}

/* Compact header styling - palitan mo ito */
.card-header.bg-gradient-primary {
    padding: 12px 20px !important;
}

.card-header.bg-gradient-primary .d-flex {
    gap: 12px;
}

.card-header.bg-gradient-primary .rounded-circle {
    width: 40px;
    height: 40px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.card-header.bg-gradient-primary .rounded-circle i {
    font-size: 1.2rem;
}

.card-header.bg-gradient-primary h5 {
    font-size: 1rem;
    margin-bottom: 2px;
}

.card-header.bg-gradient-primary p {
    font-size: 0.7rem;
    margin-bottom: 0;
    line-height: 1.3;
}

        /* Modern Form Controls */
        .form-group-modern {
            position: relative;
        }

        .input-group-modern {
            position: relative;
            display: flex;
            align-items: stretch;
        }

        .input-icon {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            z-index: 10;
            color: #6c757d;
            font-size: 1rem;
        }

        .input-icon.align-start {
            top: 16px;
            transform: none;
        }

        .form-control-modern {
            width: 100%;
            padding: 10px 12px 10px 38px;
            font-size: 0.95rem;
            font-weight: 400;
            line-height: 1.5;
            color: #212529;
            background-color: #f8f9fa;
            border: 1px solid #e9ecef;
            border-radius: 12px;
            transition: all 0.2s ease-in-out;
        }

        .form-control-modern:focus {
            background-color: #fff;
            border-color: #667eea;
            outline: 0;
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
        }

        select.form-control-modern {
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%236c757d' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 12px center;
            background-size: 14px;
            padding-right: 32px;
        }

        textarea.form-control-modern {
            padding-top: 12px;
            padding-bottom: 12px;
            resize: vertical;
            min-height: 100px;
        }

        /* Modern Upload Area */
        .upload-area-modern {
            border: 2px dashed #dee2e6;
            border-radius: 16px;
            background-color: #f8f9fa;
            cursor: pointer;
            transition: all 0.2s ease;
            overflow: hidden;
        }

        .upload-area-modern:hover {
            border-color: #28a745;
            background-color: #f0f3ff;
        }

        .upload-area-modern.drag-over {
            border-color: #28a745;
            background-color: #e8f5e9;
        }

        .upload-content {
            padding: 30px 20px;
            text-align: center;
        }

        .upload-icon {
            width: 50px;
            height: 50px;
            margin: 0 auto 12px;
            background: #e9ecef;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .upload-icon i {
            font-size: 24px;
            color: #6c757d;
        }

        .upload-area-modern:hover .upload-icon {
            background: #28a745;
        }

        .upload-area-modern:hover .upload-icon i {
            color: white;
        }

        .upload-text {
            margin: 0 0 5px;
            font-size: 0.9rem;
            color: #495057;
            font-weight: 500;
        }

        .upload-hint {
            margin: 0;
            font-size: 0.7rem;
            color: #6c757d;
        }

        /* Modern File Preview */
        .file-preview-modern {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 12px;
        }

        .file-preview-modern strong {
            display: block;
            margin-bottom: 10px;
            font-size: 0.8rem;
            color: #495057;
        }

        .file-preview-item-modern {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: white;
            padding: 6px 12px;
            border-radius: 20px;
            margin: 4px;
            font-size: 0.8rem;
            box-shadow: 0 1px 2px rgba(0,0,0,0.05);
            border: 1px solid #e9ecef;
        }

        .file-preview-item-modern i {
            font-size: 0.9rem;
        }

        .file-preview-item-modern .file-name {
            max-width: 150px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .file-preview-item-modern .file-size {
            font-size: 0.7rem;
        }

        .file-preview-item-modern .remove-file {
            cursor: pointer;
            color: #dc3545;
            margin-left: 6px;
            font-size: 0.85rem;
            transition: all 0.2s;
        }

        .file-preview-item-modern .remove-file:hover {
            color: #a71d2a;
            transform: scale(1.1);
        }

        /* Dynamic Field Cards */
        .dynamic-field-card {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 15px;
            margin-bottom: 16px;
            border: 1px solid #e9ecef;
            transition: all 0.2s;
        }

        .dynamic-field-card:hover {
            border-color: #667eea;
            box-shadow: 0 2px 8px rgba(102, 126, 234, 0.1);
        }

        .dynamic-field-card.mt-3 {
            margin-top: 1rem !important;
        }

        /* Gap utility */
        .gap-2 {
            gap: 0.5rem !important;
        }

        .d-flex.flex-wrap {
            display: flex;
            flex-wrap: wrap;
        }

        /* Mobile Responsive */
        @media (max-width: 768px) {
            .card-header.p-4 {
                padding: 16px !important;
            }
            
            .card-body.p-4 {
                padding: 16px !important;
            }
            
            .form-control-modern {
                font-size: 16px;
                padding: 10px 12px 10px 38px;
            }
            
            .upload-content {
                padding: 20px 15px;
            }
            
            .upload-icon {
                width: 40px;
                height: 40px;
            }
            
            .upload-icon i {
                font-size: 20px;
            }
            
            .upload-text {
                font-size: 0.85rem;
            }
            
            .btn {
                padding: 8px 20px !important;
                font-size: 0.85rem;
            }
            
            .gap-3 {
                gap: 10px !important;
            }
        }

        /* Tablet Responsive */
        @media (min-width: 769px) and (max-width: 1024px) {
            .card-body.p-4 {
                padding: 24px !important;
            }
        }
        /* ===== REQUEST CARD STYLES ===== */

.request-card {
    background: white;
    transition: all 0.2s ease;
    border: 1px solid #e9ecef !important;
}

.request-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.08);
    border-color: #667eea !important;
}

/* Badge styles */
.badge-status {
    padding: 4px 10px;
    border-radius: 20px;
    font-weight: 500;
    font-size: 0.7rem;
}

.status-pending { background: #fff3e0; color: #e65100; }
.status-approved { background: #e8f5e9; color: #2e7d32; }
.status-rejected { background: #ffebee; color: #c62828; }
.status-expired { background: #e9ecef; color: #6c757d; }

/* Attachment badge */
.attachment-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    background: #f0f0f0;
    border-radius: 50%;
    color: #555;
    text-decoration: none;
    transition: all 0.2s;
}

.attachment-badge:hover {
    background: #667eea;
    color: white;
}

/* Mobile optimization */
@media (max-width: 768px) {
    .request-card {
        padding: 12px !important;
    }
    
    .badge-status, .badge {
        font-size: 0.65rem !important;
        padding: 3px 8px !important;
    }
    
    .request-card .row .col-6 {
        margin-bottom: 8px;
    }
}
/* ===== TOGGLE BUTTON WITH PROPER ANIMATION ===== */

#toggleTableBtn {
    background: transparent !important;
    border: none !important;
    cursor: pointer;
    padding: 8px !important;
    margin: 0 !important;
    border-radius: 50% !important;
    transition: background 0.2s ease !important;
    display: flex;
    align-items: center;
    justify-content: center;
}

#toggleTableBtn:hover {
    background: rgba(255, 255, 255, 0.15) !important;
}

/* Icon animation - ADD TRANSITION */
#toggleIcon {
    font-size: 1.2rem;
    color: white;
    transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1) !important;
    display: inline-block;
}

/* Rotate animation for chevron */
#toggleIcon.bi-chevron-up {
    transform: rotate(0deg);
}

#toggleIcon.bi-chevron-down {
    transform: rotate(180deg);
}

/* Container animation */
#requestTableContainer {
    overflow: hidden;
    transition: all 0.3s ease-out;
}

#requestTableContainer.show {
    display: block !important;
    animation: slideDown 0.3s ease-out;
}

@keyframes slideDown {
    from {
        opacity: 0;
        transform: translateY(-10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}
/* Container animation - smooth expand/collapse */
#requestTableContainer {
    overflow: hidden;
    transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
}

#requestTableContainer.show {
    display: block !important;
    animation: slideDown 0.4s ease-out;
}

@keyframes slideDown {
    from {
        opacity: 0;
        transform: translateY(-20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}
/* Ensure header text is visible */
.card-header .fw-semibold {
    font-size: 1rem;
    font-weight: 600;
}

.card-header .badge {
    font-size: 0.7rem;
}

/* Make sure toggle icon is visible */
#toggleIcon {
    font-size: 1.2rem;
    color: #6c757d;
}

/* Card header background */
.card-header.bg-white {
    background-color: white !important;
    border-bottom: 1px solid #e9ecef;
}

/* Validity legend inside card */
.bg-light {
    background-color: #f8f9fa !important;
}

/* Fix for validity legend text */
.validity-active, .validity-soon, .validity-expired {
    display: inline-flex;
    align-items: center;
    gap: 3px;
}

.validity-active { color: #2e7d32; }
.validity-soon { color: #e65100; }
.validity-expired { color: #c62828; }
/* ===== FIX FOR RECENT REQUESTS HEADER COLOR ===== */

/* Make Recent Requests header have color */
.card.mb-4.border-0.shadow-sm.rounded-4.overflow-hidden .card-header {
    background: linear-gradient(135deg, #065f46, #047857) !important;
    color: white !important;
}

.card.mb-4.border-0.shadow-sm.rounded-4.overflow-hidden .card-header h5,
.card.mb-4.border-0.shadow-sm.rounded-4.overflow-hidden .card-header .fw-semibold {
    color: white !important;
}

.card.mb-4.border-0.shadow-sm.rounded-4.overflow-hidden .card-header i {
    color: white !important;
}

.card.mb-4.border-0.shadow-sm.rounded-4.overflow-hidden .card-header .badge {
    background-color: rgba(255,255,255,0.2) !important;
    color: white !important;
}

.card.mb-4.border-0.shadow-sm.rounded-4.overflow-hidden .card-header .btn-link {
    color: white !important;
}

.card.mb-4.border-0.shadow-sm.rounded-4.overflow-hidden .card-header .btn-link:hover {
    background: rgba(255,255,255,0.1);
    border-radius: 50%;
}

/* Toggle icon color in header */
#toggleIcon {
    color: white !important;
}
    </style>
</head>
<body>
    <div id="appPage">
        <!-- Sidebar -->
        <div class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <h3>
                    <button class="desktop-toggle-btn" id="desktopToggleBtn">
                        <i class="bi bi-list" id="toggleIcon"></i>
                    </button>
                    <img src="../Pictures/amgc3DLogo.png" alt="Logo" class="logo-icon"> 
                    <span class="nav-text">Sales</span>
                </h3>
            </div>
            <div class="sidebar-menu">
                <ul class="nav flex-column">
                    <li class="nav-item">
                        <a class="nav-link" href="currentinventory.php">
                            <i class="bi bi-speedometer2"></i>
                            <span class="nav-text">Dashboard</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="customer.php">
                            <i class="bi bi-people"></i>
                            <span class="nav-text">Customer</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="returnedmerchandise.php">
                            <i class="bi bi-arrow-counterclockwise"></i>
                            <span class="nav-text">Returned Merchandise</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="sales_order.php">
                            <i class="bi bi-list-check"></i>
                            <span class="nav-text">Sales Orders</span>
                        </a>
                    </li>
                    <li class="nav-item"><a class="nav-link" href="collections.php"><i class="bi bi-cash-stack"></i><span class="nav-text">Collections</span></a></li>
                </ul>
            </div>
            <div class="sidebar-footer">
                <div class="user-profile-sidebar">
                    <div class="user-avatar-sidebar"><?php echo substr($user_name, 0, 2); ?></div>
                    <div class="user-details-sidebar">
                        <span class="user-name-sidebar"><?php echo htmlspecialchars($user_name); ?></span>
                    </div>
                </div>
                <button class="logout-btn-sidebar" onclick="logout()">
                    <i class="bi bi-box-arrow-right"></i>
                    <span class="logout-text">Logout</span>
                </button>
            </div>
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <!-- Top Bar -->
            <div class="navbar-top">
                <button class="mobile-toggle-btn" id="mobileToggleBtn">
                    <i class="bi bi-list"></i>
                </button>
                <div class="page-title">
                    <h2>Credit / Discount Request</h2>
                    <p>Submit a request for customer discount or credit terms</p>
                </div>
            </div>

            <!-- Attachments Table Missing Warning -->
            <?php if (!$attachmentsTableExists && $tableExists): ?>
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
                    <button class="btn btn-sm btn-primary" onclick="copyAttachmentsSQL()">
                        <i class="bi bi-files"></i> Copy SQL
                    </button>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <script>
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
                        Swal.fire({
                            icon: 'success',
                            title: 'Copied!',
                            text: 'SQL copied to clipboard',
                            timer: 1500,
                            showConfirmButton: false
                        });
                    });
                }
                </script>
            <?php endif; ?>

            <!-- Database update needed warning -->
            <?php if ($tableExists && !$credit_terms_column_exists): ?>
                <div class="alert alert-warning alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-triangle"></i> 
                    <strong>Database update needed!</strong> The <code>credit_terms_days</code> column is missing. 
                    Please run the SQL below to update your table:
                    <br><br>
                    <code class="small">ALTER TABLE `credit_discount_requests` ADD COLUMN `credit_terms_days` int(11) DEFAULT NULL AFTER `requested_discount_percent`;</code>
                    <br><br>
                    <button class="btn btn-sm btn-primary" onclick="copySQL()">
                        <i class="bi bi-files"></i> Copy SQL
                    </button>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <script>
                function copySQL() {
                    const sql = "ALTER TABLE `credit_discount_requests` ADD COLUMN `credit_terms_days` int(11) DEFAULT NULL AFTER `requested_discount_percent`;";
                    navigator.clipboard.writeText(sql).then(() => {
                        Swal.fire({
                            icon: 'success',
                            title: 'Copied!',
                            text: 'SQL copied to clipboard',
                            timer: 1500,
                            showConfirmButton: false
                        });
                    });
                }
                </script>
            <?php endif; ?>

            <!-- Effective columns missing warning -->
            <?php if ($tableExists && !$effective_columns_exist): ?>
                <div class="alert alert-warning alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-triangle"></i> 
                    <strong>Database update recommended!</strong> The <code>effective_from</code> and <code>effective_until</code> columns are missing.
                    Please run the SQL below to add expiration date tracking:
                    <br><br>
                    <code class="small">ALTER TABLE `credit_discount_requests` ADD COLUMN `effective_from` datetime DEFAULT NULL AFTER `admin_notes`, ADD COLUMN `effective_until` datetime DEFAULT NULL AFTER `effective_from`;</code>
                    <br><br>
                    <button class="btn btn-sm btn-primary" onclick="copyEffectiveSQL()">
                        <i class="bi bi-files"></i> Copy SQL
                    </button>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <script>
                function copyEffectiveSQL() {
                    const sql = "ALTER TABLE `credit_discount_requests` ADD COLUMN `effective_from` datetime DEFAULT NULL AFTER `admin_notes`, ADD COLUMN `effective_until` datetime DEFAULT NULL AFTER `effective_from`;";
                    navigator.clipboard.writeText(sql).then(() => {
                        Swal.fire({
                            icon: 'success',
                            title: 'Copied!',
                            text: 'SQL copied to clipboard',
                            timer: 1500,
                            showConfirmButton: false
                        });
                    });
                }
                </script>
            <?php endif; ?>

            <!-- Amount based discount columns missing warning -->
            <?php if ($tableExists && !$discount_amount_columns_exist): ?>
                <div class="alert alert-warning alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-triangle"></i> 
                    <strong>Amount-based discount columns missing!</strong> Please run this SQL so <code>Based on Amount</code> can save in the database:
                    <br><br>
                    <code class="small">ALTER TABLE `credit_discount_requests` ADD COLUMN `discount_calculation_type` enum('percentage','amount_based') DEFAULT 'percentage' AFTER `requested_discount_percent`, ADD COLUMN `discount_based_amount` decimal(12,2) DEFAULT NULL AFTER `discount_calculation_type`, ADD COLUMN `calculated_discount_amount` decimal(12,2) DEFAULT NULL AFTER `discount_based_amount`;</code>
                    <br><br>
                    <button class="btn btn-sm btn-primary" onclick="copyDiscountAmountSQL()">
                        <i class="bi bi-files"></i> Copy SQL
                    </button>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <script>
                function copyDiscountAmountSQL() {
                    const sql = "ALTER TABLE `credit_discount_requests` ADD COLUMN `discount_calculation_type` enum('percentage','amount_based') DEFAULT 'percentage' AFTER `requested_discount_percent`, ADD COLUMN `discount_based_amount` decimal(12,2) DEFAULT NULL AFTER `discount_calculation_type`, ADD COLUMN `calculated_discount_amount` decimal(12,2) DEFAULT NULL AFTER `discount_based_amount`;";
                    navigator.clipboard.writeText(sql).then(() => {
                        Swal.fire({
                            icon: 'success',
                            title: 'Copied!',
                            text: 'SQL copied to clipboard',
                            timer: 1500,
                            showConfirmButton: false
                        });
                    });
                }
                </script>
            <?php endif; ?>

            <!-- Table missing warning -->
            <?php if (!$tableExists): ?>
                <div class="alert alert-warning alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-triangle"></i> 
                    <strong>The <code>credit_discount_requests</code> table does not exist.</strong> 
                    Please run the SQL script below to create it.
                    <br><br>
                    <button class="btn btn-sm btn-primary" onclick="copyFullSQL()">
                        <i class="bi bi-files"></i> Copy SQL
                    </button>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <script>
                function copyFullSQL() {
                    const sql = `CREATE TABLE IF NOT EXISTS \`credit_discount_requests\` (
  \`request_id\` int(11) NOT NULL AUTO_INCREMENT,
  \`customer_id\` int(11) NOT NULL,
  \`agent_id\` int(11) NOT NULL,
  \`request_type\` enum('discount','credit_terms','both') NOT NULL,
  \`requested_credit_limit\` decimal(12,2) DEFAULT NULL,
  \`requested_discount_percent\` decimal(5,2) DEFAULT NULL,
  \`credit_terms_days\` int(11) DEFAULT NULL,
  \`reason\` text DEFAULT NULL,
  \`status\` enum('pending','approved','rejected') DEFAULT 'pending',
  \`admin_notes\` text DEFAULT NULL,
  \`effective_from\` datetime DEFAULT NULL,
  \`effective_until\` datetime DEFAULT NULL,
  \`created_at\` timestamp NOT NULL DEFAULT current_timestamp(),
  \`updated_at\` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  \`approved_at\` datetime DEFAULT NULL,
  \`approved_by\` int(11) DEFAULT NULL,
  PRIMARY KEY (\`request_id\`),
  KEY \`customer_id\` (\`customer_id\`),
  KEY \`agent_id\` (\`agent_id\`),
  KEY \`approved_by\` (\`approved_by\`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;`;
                    navigator.clipboard.writeText(sql).then(() => {
                        Swal.fire({
                            icon: 'success',
                            title: 'Copied!',
                            text: 'SQL copied to clipboard',
                            timer: 1500,
                            showConfirmButton: false
                        });
                    });
                }
                </script>
            <?php endif; ?>

            <!-- Messages with Modern Design -->
<?php if (!empty($success_message)): ?>
    <div class="alert alert-success alert-dismissible fade show border-0 rounded-3 shadow-sm" role="alert" style="background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%); border-left: 4px solid #059669;">
        <div class="d-flex align-items-center">
            <div class="flex-shrink-0">
                <i class="bi bi-check-circle-fill fs-4 text-success"></i>
            </div>
            <div class="flex-grow-1 ms-3">
                <strong class="text-success">Success!</strong> <?php echo $success_message; ?>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    </div>
<?php endif; ?>
<?php if (!empty($error_message)): ?>
    <div class="alert alert-danger alert-dismissible fade show border-0 rounded-3 shadow-sm" role="alert" style="background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%); border-left: 4px solid #dc2626;">
        <div class="d-flex align-items-center">
            <div class="flex-shrink-0">
                <i class="bi bi-exclamation-triangle-fill fs-4 text-danger"></i>
            </div>
            <div class="flex-grow-1 ms-3">
                <strong class="text-danger">Error!</strong> <?php echo $error_message; ?>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    </div>
<?php endif; ?>

            <!-- Request Form - Modern Redesign -->
<div class="card mb-4 border-0 shadow-sm rounded-4 overflow-hidden">
    <div class="card-header bg-gradient-primary text-white border-0 p-4">
        <div class="d-flex align-items-center">
            <div class="d-flex align-items-center">
                <i class="bi bi-pencil-square fs-4 text-white"></i>
            </div>
            <div>
                <h5 class="mb-0 fw-bold text-white">New Credit/Discount Request</h5>
                <p class="mb-0 text-white-50 small">Fill out the form below to submit a request</p>
            </div>
        </div>
    </div>
    
    <div class="card-body p-4">
        <?php if (!$tableExists): ?>
            <div class="alert alert-info">The request form is disabled because the required table does not exist.</div>
        <?php else: ?>
        <form method="POST" id="requestForm" enctype="multipart/form-data">
            <input type="hidden" name="action" value="submit_request">
            
            <!-- Form Grid -->
            <div class="row g-4">
                <!-- Left Column -->
                <div class="col-lg-6">
                    <!-- Customer Field -->
                    <div class="form-group-modern mb-4">
                        <label class="form-label fw-semibold mb-2">
                            Customer <span class="text-danger">*</span>
                        </label>
                        <div class="input-group-modern">
                            <span class="input-icon">
                                <i class="bi bi-person"></i>
                            </span>
                            <select class="form-control-modern" name="customer_id" id="customerSelect" required>
                                <option value="">Select Customer</option>
                                <?php foreach ($customers as $cust): ?>
                                    <option value="<?php echo $cust['customer_id']; ?>">
                                        <?php echo htmlspecialchars($cust['customer_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <small class="text-muted" id="customerStatusMsg"></small>
                    </div>
                    
                    <!-- Request Type Field -->
                    <div class="form-group-modern mb-4">
                        <label class="form-label fw-semibold mb-2">
                            Request Type <span class="text-danger">*</span>
                        </label>
                        <div class="input-group-modern">
                            <span class="input-icon">
                                <i class="bi bi-tags"></i>
                            </span>
                            <select class="form-control-modern" name="request_type" id="requestType" required>
                                <option value="">Select Type</option>
                                <option value="discount">Discount Only</option>
                                <option value="credit_terms">Credit Terms Only</option>
                                <option value="both">Credit Terms & Discount</option>
                            </select>
                        </div>
                    </div>
                    
                    <!-- Dynamic Fields Container -->
                    <div id="dynamicFieldsContainer"></div>
                </div>
                
                <!-- Right Column -->
                <div class="col-lg-6">
                    <!-- Reason Field with Dropdown and "Other" textbox -->
                    <div class="form-group-modern mb-4">
                        <label class="form-label fw-semibold mb-2">
                            Reason for Request <span class="text-danger">*</span>
                        </label>
                        <div class="input-group-modern">
                            <span class="input-icon">
                                <i class="bi bi-chat-text"></i>
                            </span>
                            <select class="form-control-modern" name="reason_select" id="reasonSelect" required>
                                <option value="">-- Select a reason --</option>
                                <option value="Increased sales volume">Increased sales volume</option>
                                <option value="Customer loyalty discount">Customer loyalty discount</option>
                                <option value="Promotional campaign">Promotional campaign</option>
                                <option value="Competitor price matching">Competitor price matching</option>
                                <option value="Long-term contract">Long-term contract</option>
                                <option value="Seasonal promotion">Seasonal promotion</option>
                                <option value="Other">Other (please specify)</option>
                            </select>
                        </div>
                        <div id="otherReasonDiv" style="display: none; margin-top: 10px;">
                            <label class="form-label fw-semibold mb-1">Please specify:</label>
                            <input type="text" class="form-control-modern" name="reason_other" id="reasonOther" placeholder="Type your reason here...">
                        </div>
                        <small class="text-muted">Select a reason from the list, or choose "Other" to type your own.</small>
                    </div>
                    
                    <!-- Attachments Field -->
                    <div class="form-group-modern mb-4">
                        <label class="form-label fw-semibold mb-2">
                            Supporting Documents
                        </label>
                        
                        <!-- Modern Upload Area -->
                        <div class="upload-area-modern" id="uploadBox">
                            <input type="file" name="attachments[]" id="attachments" 
                                   multiple accept=".jpg,.jpeg,.png,.gif,.pdf,.doc,.docx,.xls,.xlsx" 
                                   style="display: none;">
                            <div class="upload-content">
                                <div class="upload-icon">
                                    <i class="bi bi-cloud-upload"></i>
                                </div>
                                <p class="upload-text">Click or drag files here</p>
                                <p class="upload-hint">Max 10MB per file. Allowed: JPG, PNG, GIF, PDF, DOC, XLS</p>
                            </div>
                        </div>
                        
                        <!-- File Preview -->
                        <div id="filePreview" class="file-preview-modern mt-3" style="display: none;"></div>
                    </div>
                </div>
            </div>
            
            <!-- Action Buttons -->
            <div class="row mt-4">
                <div class="col-12">
                    <div class="border-top pt-4">
                        <div class="d-flex gap-3 justify-content-end">
                            <button type="reset" class="btn btn-outline-secondary px-4 py-2 rounded-pill" onclick="resetForm()">
                                <i class="bi bi-arrow-repeat me-1"></i> Reset
                            </button>
                            <button type="submit" class="btn btn-primary px-4 py-2 rounded-pill">
                                <i class="bi bi-send me-1"></i> Submit Request
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </form>
        <?php endif; ?>
    </div>
</div>

           <!-- Recent Requests Section - CARD LAYOUT with Validity Legend -->
<div class="card mb-4 border-0 shadow-sm rounded-4 overflow-hidden">
    <div class="card-header bg-gradient-primary text-white border-0 py-2 px-4 d-flex justify-content-between align-items-center">
        <div class="d-flex align-items-center">
            <i class="bi bi-clock-history me-2"></i>
            <h5 class="mb-0 fw-semibold">Recent Requests</h5>
            <span class="badge bg-white bg-opacity-25 ms-2"><?php echo count($recent_requests); ?></span>
        </div>
        <button class="btn btn-sm btn-link text-white p-0 m-0 border-0" id="toggleTableBtn" onclick="toggleRequestTable()" style="background: transparent !important; box-shadow: none !important;">
            <i class="bi bi-chevron-down" id="toggleTableIcon"></i>
        </button>
    </div>
    
    <div id="requestTableContainer" style="display: none;">
        <?php if (!$tableExists): ?>
            <div class="text-center text-muted py-5">
                <i class="bi bi-database-exclamation fs-1 d-block mb-2"></i>
                <p>Table does not exist. Please run the SQL to create it.</p>
            </div>
        <?php elseif (empty($recent_requests)): ?>
            <div class="text-center text-muted py-5">
                <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                <p>No requests yet.</p>
            </div>
        <?php else: ?>
            <div class="p-3">
                <?php foreach ($recent_requests as $req): ?>
                    <?php
                        $status_class = match($req['status']) {
                            'pending'  => 'status-pending',
                            'approved' => 'status-approved',
                            'rejected' => 'status-rejected',
                            default    => 'bg-secondary text-white'
                        };
                        $type_label = match($req['request_type']) {
                            'discount' => 'Discount',
                            'credit_terms' => 'Credit Terms',
                            'both' => 'Credit & Discount',
                            default => ucfirst($req['request_type'])
                        };
                        
                        $is_expired = false;
                        $validity_class = '';
                        $remaining_days = 0;
                        
                        if ($req['status'] === 'approved' && isset($req['effective_until']) && $req['effective_until']) {
                            $effective_until = strtotime($req['effective_until']);
                            $now = time();
                            $is_expired = $effective_until < $now;
                            $remaining_days = ceil(($effective_until - $now) / 86400);
                            
                            if ($is_expired) {
                                $status_class = 'status-expired';
                                $validity_class = 'validity-expired';
                            } elseif ($remaining_days <= 3) {
                                $validity_class = 'validity-soon';
                            } else {
                                $validity_class = 'validity-active';
                            }
                        } elseif ($req['status'] === 'approved') {
                            $validity_class = 'validity-active';
                        }
                        
                        $validity_html = '';
                        if ($req['status'] === 'approved' && isset($req['effective_until']) && $req['effective_until']) {
                            if ($is_expired) {
                                $validity_html = '<span class="badge bg-secondary"><i class="bi bi-calendar-x me-1"></i>Expired ' . date('M d', strtotime($req['effective_until'])) . '</span>';
                            } elseif ($remaining_days <= 3) {
                                $validity_html = '<span class="badge bg-warning text-dark"><i class="bi bi-clock-alert me-1"></i>' . $remaining_days . ' days left</span>';
                            } else {
                                $validity_html = '<span class="badge bg-success"><i class="bi bi-calendar-check me-1"></i>Valid until ' . date('M d, Y', strtotime($req['effective_until'])) . '</span>';
                            }
                        } elseif ($req['status'] === 'approved') {
                            $validity_html = '<span class="badge bg-info"><i class="bi bi-infinity me-1"></i>Permanent</span>';
                        }
                        
                        $attachments = $attachments_by_request[$req['request_id']] ?? [];
                    ?>
                    
                    <!-- Request Card -->
                    <div class="request-card mb-3 p-3 rounded-3 border">
                        <!-- Header Row -->
                        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-2">
                            <div class="d-flex align-items-center gap-2 flex-wrap">
                                <span class="fw-bold"><?php echo htmlspecialchars($req['customer_name']); ?></span>
                                <span class="badge bg-info"><?php echo $type_label; ?></span>
                                <span class="badge-status <?php echo $status_class; ?>"><?php echo ucfirst($req['status']); ?></span>
                                <?php if ($validity_html): ?>
                                    <?php echo $validity_html; ?>
                                <?php endif; ?>
                            </div>
                            <small class="text-muted">
                                <i class="bi bi-calendar"></i> <?php echo date('M d, Y', strtotime($req['created_at'])); ?>
                            </small>
                        </div>
                        
                        <!-- Details Grid -->
                        <div class="row g-2 mb-2">
                            <?php if ($req['requested_credit_limit']): ?>
                            <div class="col-6 col-md-3">
                                <small class="text-muted d-block">Credit Limit</small>
                                <span class="fw-semibold">₱<?php echo number_format($req['requested_credit_limit'], 2); ?></span>
                            </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($req['requested_discount_percent']) || !empty($req['discount_based_amount']) || !empty($req['calculated_discount_amount'])): ?>
                            <div class="col-6 col-md-3">
                                <small class="text-muted d-block">Discount</small>
                                <span class="fw-semibold">
                                    <?php if (($req['discount_calculation_type'] ?? 'percentage') === 'amount_based'): ?>
                                        ₱<?php echo number_format((float)($req['calculated_discount_amount'] ?? $req['discount_based_amount']), 2); ?>
                                    <?php else: ?>
                                        <?php echo $req['requested_discount_percent']; ?>%
                                    <?php endif; ?>
                                </span>
                            </div>
                            <?php endif; ?>
                            
                            <?php if (isset($req['credit_terms_days']) && $req['credit_terms_days']): ?>
                            <div class="col-6 col-md-3">
                                <small class="text-muted d-block">Credit Terms</small>
                                <span class="fw-semibold"><?php echo $req['credit_terms_days']; ?> days</span>
                            </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($attachments)): ?>
                            <div class="col-6 col-md-3">
                                <small class="text-muted d-block">Attachments</small>
                                <div class="d-flex gap-1 flex-wrap">
                                    <?php foreach ($attachments as $att): ?>
                                        <a href="#" class="attachment-badge" onclick="viewAttachment('<?php echo $att['file_path']; ?>', '<?php echo htmlspecialchars($att['original_file_name']); ?>'); return false;">
                                            <i class="bi bi-paperclip"></i>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Reason Row -->
                        <div class="mt-2 pt-2 border-top">
                            <small class="text-muted d-block mb-1">Reason</small>
                            <p class="mb-0 small"><?php echo nl2br(htmlspecialchars($req['reason'])); ?></p>
                        </div>
                        
                        <!-- Admin Notes (if any) -->
                        <?php if (!empty($req['admin_notes'])): ?>
                        <div class="mt-2 pt-2 border-top">
                            <small class="text-muted d-block mb-1">Admin Notes</small>
                            <p class="mb-0 small text-muted"><?php echo htmlspecialchars($req['admin_notes']); ?></p>
                        </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <!-- Validity Legend INSIDE the card -->
            <div class="border-top pt-3 pb-2 px-3 bg-light">
                <small class="text-muted">
                    <i class="bi bi-info-circle me-1"></i> 
                    <strong>Validity Legend:</strong>
                    <span class="validity-active ms-2"><i class="bi bi-calendar-check me-1"></i> Active</span>
                    <span class="validity-soon ms-2"><i class="bi bi-clock-alert me-1"></i> Expiring soon</span>
                    <span class="validity-expired ms-2"><i class="bi bi-calendar-x me-1"></i> Expired</span>
                    <span class="ms-2"><i class="bi bi-infinity me-1"></i> Permanent</span>
                </small>
            </div>
        <?php endif; ?>
    </div>
</div>

    <!-- File Preview Modal -->
    <div class="modal fade" id="fileViewModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="fileViewTitle">File Preview</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="fileViewBody">
                    <div class="text-center">
                        <div class="spinner-border" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <a href="#" id="downloadLink" class="btn btn-primary" download>Download</a>
                </div>
            </div>
        </div>
    </div>


        <!-- Mobile Bottom Navigation -->
    <div class="mobile-nav" id="mobileNav">
        <ul class="nav">
            <li class="nav-item">
                <a class="nav-link" href="currentinventory.php">
                    <i class="bi bi-speedometer2"></i>
                    <span>Dashboard</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="customer.php">
                    <i class="bi bi-people"></i>
                    <span>Customers</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="returnedmerchandise.php">
                    <i class="bi bi-arrow-counterclockwise"></i>
                    <span>Returns</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="sales_order.php">
                    <i class="bi bi-list-check"></i>
                    <span>Sales Orders</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link logout-btn" href="#" onclick="showProfileModal(); return false;">
                    <i class="bi bi-box-arrow-right"></i>
                    <span>Logout</span>
                </a>
            </li>
        </ul>
    </div>

    <!-- Mobile Profile/Logout Modal -->
    <div class="modal fade" id="profileModal" tabindex="-1" aria-labelledby="profileModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="profileModalLabel">
                        <i class="bi bi-person-circle me-2"></i>User Profile
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body text-center">
                    <!-- User Avatar -->
                    <div class="user-avatar-large mb-3">
                        <?php echo $user_initials; ?>
                    </div>
                    
                    <!-- User Name -->
                    <h4 class="mb-1"><?php echo htmlspecialchars($user_name); ?></h4>
                    
                    <!-- User Role -->
                    <p class="text-muted mb-3">
                        <span class="badge bg-success">Sales</span>
                    </p>
                    
                    <!-- Branch Info (if applicable) -->
                    <?php if (!$view_all_branches && $branch_id > 0): ?>
                    <div class="branch-info mb-3">
                        <i class="bi bi-building me-1"></i>
                        <span>Branch <?php echo $branch_id; ?></span>
                    </div>
                    <?php endif; ?>
                
                    
                    <!-- Logout Button -->
                    <button class="btn btn-danger btn-lg w-100" onclick="confirmLogout()">
                        <i class="bi bi-box-arrow-right me-2"></i>Logout
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // File preview array
        let selectedFiles = [];
        
        // Sidebar functions
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
                } else {
                    const overlay = document.querySelector('.sidebar-overlay');
                    overlay.classList.toggle('active');
                    if (!sidebar.classList.contains('active')) {
                        setTimeout(() => overlay.remove(), 300);
                    }
                }
            } else {
                sidebar.classList.toggle('collapsed');
                localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
                document.querySelectorAll('.nav-text').forEach(t => t.style.display = sidebar.classList.contains('collapsed') ? 'none' : 'inline-block');
                document.querySelector('.main-content').style.marginLeft = sidebar.classList.contains('collapsed') ? '80px' : '250px';
            }
        }
        
        function closeMobileSidebar() {
            document.getElementById('sidebar').classList.remove('active');
            const overlay = document.querySelector('.sidebar-overlay');
            if (overlay) {
                overlay.classList.remove('active');
                setTimeout(() => overlay.remove(), 300);
            }
        }
        
        function initializeSidebar() {
            const sidebar = document.getElementById('sidebar');
            if (window.innerWidth > 992) {
                const collapsed = localStorage.getItem('sidebarCollapsed') === 'true';
                sidebar.classList.toggle('collapsed', collapsed);
                document.querySelectorAll('.nav-text').forEach(t => t.style.display = collapsed ? 'none' : 'inline-block');
                document.querySelector('.main-content').style.marginLeft = collapsed ? '80px' : '250px';
            } else {
                sidebar.classList.remove('active', 'collapsed');
                document.querySelectorAll('.nav-text').forEach(t => t.style.display = 'inline-block');
                document.querySelector('.main-content').style.marginLeft = '0';
            }
        }
        
        document.addEventListener('DOMContentLoaded', function() {
            initializeSidebar();
            document.getElementById('mobileToggleBtn')?.addEventListener('click', e => { e.stopPropagation(); toggleSidebar(); });
            document.getElementById('desktopToggleBtn')?.addEventListener('click', e => { e.stopPropagation(); toggleSidebar(); });
            document.querySelectorAll('.sidebar .nav-link').forEach(l => {
                l.addEventListener('click', () => { if (window.innerWidth <= 992) closeMobileSidebar(); });
            });
            window.addEventListener('resize', initializeSidebar);
            
            // File input handler
            const fileInput = document.getElementById('attachments');
            if (fileInput) {
                fileInput.addEventListener('change', handleFileSelect);
            }
            
            // ========== AUTO-SELECT CUSTOMER FROM URL PARAMETERS ==========
            const urlParams = new URLSearchParams(window.location.search);
            const customerId = urlParams.get('customer_id');
            const customerName = urlParams.get('customer_name');
            
            if (customerId && document.getElementById('customerSelect')) {
                const customerSelect = document.getElementById('customerSelect');
                const customerFieldContainer = customerSelect.closest('.form-group-modern');
                
                // I-set ang dropdown value
                customerSelect.value = customerId;
                
                // Kunin ang selected customer name
                let selectedCustomerName = decodeURIComponent(customerName || '');
                if (!selectedCustomerName) {
                    const selectedOption = customerSelect.options[customerSelect.selectedIndex];
                    selectedCustomerName = selectedOption ? selectedOption.text : 'Selected Customer';
                }
                
                // Palitan ang dropdown ng static text (readonly)
                if (customerFieldContainer) {
                    customerFieldContainer.innerHTML = `
                        <label class="form-label fw-semibold mb-2">
                            Customer <span class="text-danger">*</span>
                        </label>
                        <div class="input-group-modern">
                            <span class="input-icon">
                                <i class="bi bi-person"></i>
                            </span>
                            <input type="text" class="form-control-modern" value="${escapeHtml(selectedCustomerName)}" readonly disabled style="background-color: #f0f0f0; cursor: not-allowed;">
                        </div>
                        <input type="hidden" name="customer_id" value="${customerId}">
                        <small class="text-muted text-success">✓ Customer locked - request for this specific customer only</small>
                    `;
                }
                
                Swal.fire({
                    icon: 'success',
                    title: 'Customer Selected',
                    text: `Creating request for ${selectedCustomerName}`,
                    timer: 2500,
                    showConfirmButton: false,
                    toast: true,
                    position: 'top-end',
                    background: '#f0fdf4',
                    color: '#166534',
                    iconColor: '#059669',
                    customClass: {
                        popup: 'rounded-4 shadow-lg border-start border-4 border-success'
                    }
                });
            }
            
            // Show/hide "Other" reason textbox
            const reasonSelect = document.getElementById('reasonSelect');
            const otherDiv = document.getElementById('otherReasonDiv');
            const reasonOther = document.getElementById('reasonOther');
            
            function toggleOtherReason() {
                if (reasonSelect.value === 'Other') {
                    otherDiv.style.display = 'block';
                    reasonOther.required = true;
                } else {
                    otherDiv.style.display = 'none';
                    reasonOther.required = false;
                    reasonOther.value = '';
                }
            }
            
            if (reasonSelect) {
                reasonSelect.addEventListener('change', toggleOtherReason);
                toggleOtherReason(); // initial state
            }
        });
        
        // Toggle credit/discount fields based on request type
        const requestType = document.getElementById('requestType');
        const creditLimitField = document.getElementById('creditLimitField');
        const creditDaysField = document.getElementById('creditDaysField');
        const discountField = document.getElementById('discountField');

        if (requestType && creditLimitField && creditDaysField && discountField) {
            requestType.addEventListener('change', function() {
                const val = this.value;
                if (val === 'credit_terms') {
                    creditLimitField.style.display = 'block';
                    creditDaysField.style.display = 'block';
                    discountField.style.display = 'none';
                } else if (val === 'discount') {
                    creditLimitField.style.display = 'none';
                    creditDaysField.style.display = 'none';
                    discountField.style.display = 'block';
                } else if (val === 'both') {
                    creditLimitField.style.display = 'block';
                    creditDaysField.style.display = 'block';
                    discountField.style.display = 'block';
                } else {
                    creditLimitField.style.display = 'none';
                    creditDaysField.style.display = 'none';
                    discountField.style.display = 'none';
                }
            });
        }
        
        // Handle file selection
        function handleFileSelect(event) {
            const files = Array.from(event.target.files);
            selectedFiles = files;
            updateFilePreview();
        }
        
        // Update file preview display
        function updateFilePreview() {
            const previewDiv = document.getElementById('filePreview');
            if (!previewDiv) return;
            
            if (selectedFiles.length === 0) {
                previewDiv.style.display = 'none';
                previewDiv.innerHTML = '';
                return;
            }
            
            previewDiv.style.display = 'block';
            let html = '<strong>Selected files:</strong><br>';
            selectedFiles.forEach((file, index) => {
                const fileSize = (file.size / 1024).toFixed(2);
                html += `
                    <div class="file-preview-item">
                        <i class="bi bi-file-earmark-${getFileIcon(file.name)}"></i>
                        <span>${escapeHtml(file.name)} (${fileSize} KB)</span>
                        <i class="bi bi-x-circle remove-file" onclick="removeFile(${index})"></i>
                    </div>
                `;
            });
            previewDiv.innerHTML = html;
        }
        
        // Get file icon based on extension
        function getFileIcon(filename) {
            const ext = filename.split('.').pop().toLowerCase();
            if (['jpg', 'jpeg', 'png', 'gif'].includes(ext)) return 'image';
            if (ext === 'pdf') return 'pdf';
            if (['doc', 'docx'].includes(ext)) return 'word';
            if (['xls', 'xlsx'].includes(ext)) return 'excel';
            return 'text';
        }
        
        // Remove file from selection
        function removeFile(index) {
            selectedFiles.splice(index, 1);
            const fileInput = document.getElementById('attachments');
            const dataTransfer = new DataTransfer();
            selectedFiles.forEach(file => dataTransfer.items.add(file));
            fileInput.files = dataTransfer.files;
            updateFilePreview();
        }
        
        // Escape HTML
        function escapeHtml(str) {
            return str.replace(/[&<>]/g, function(m) {
                if (m === '&') return '&amp;';
                if (m === '<') return '&lt;';
                if (m === '>') return '&gt;';
                return m;
            });
        }
        
        // Reset form
        function resetForm() {
            document.getElementById('requestForm').reset();
            selectedFiles = [];
            const fileInput = document.getElementById('attachments');
            if (fileInput) fileInput.value = '';
            updateFilePreview();
            // Hide other reason div and clear
            const otherDiv = document.getElementById('otherReasonDiv');
            if (otherDiv) otherDiv.style.display = 'none';
            const reasonOther = document.getElementById('reasonOther');
            if (reasonOther) reasonOther.value = '';
            // Re-run toggle to set required attributes properly
            const reasonSelect = document.getElementById('reasonSelect');
            if (reasonSelect && reasonSelect.value === 'Other') {
                reasonSelect.value = '';
                if (otherDiv) otherDiv.style.display = 'none';
                if (reasonOther) reasonOther.required = false;
            }
            // Reset dynamic fields
            const dynamicContainer = document.getElementById('dynamicFieldsContainer');
            if (dynamicContainer) dynamicContainer.innerHTML = '';
            const requestTypeSelect = document.getElementById('requestType');
            if (requestTypeSelect) requestTypeSelect.value = '';
        }
        
        // View attachment
        let fileViewModal;
        
        function viewAttachment(filePath, fileName) {
            fileViewModal = new bootstrap.Modal(document.getElementById('fileViewModal'));
            const modalBody = document.getElementById('fileViewBody');
            const modalTitle = document.getElementById('fileViewTitle');
            const downloadLink = document.getElementById('downloadLink');
            
            modalTitle.innerText = fileName;
            downloadLink.href = filePath;
            downloadLink.download = fileName;
            
            const ext = fileName.split('.').pop().toLowerCase();
            
            if (['jpg', 'jpeg', 'png', 'gif'].includes(ext)) {
                modalBody.innerHTML = `<img src="${filePath}" class="img-fluid" alt="${fileName}">`;
            } else if (ext === 'pdf') {
                modalBody.innerHTML = `<embed src="${filePath}" type="application/pdf" width="100%" height="500px">`;
            } else {
                modalBody.innerHTML = `
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle"></i> 
                        This file type cannot be previewed directly. Please download to view.
                        <br><br>
                        <a href="${filePath}" class="btn btn-primary" download="${fileName}">
                            <i class="bi bi-download"></i> Download ${fileName}
                        </a>
                    </div>
                `;
            }
            
            fileViewModal.show();
            
            // Reset modal body when hidden
            document.getElementById('fileViewModal').addEventListener('hidden.bs.modal', function() {
                modalBody.innerHTML = '<div class="text-center"><div class="spinner-border" role="status"><span class="visually-hidden">Loading...</span></div></div>';
            }, { once: true });
        }
        
        function logout() {
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
                    window.location.href = '../logout.php';
                }
            });
        }

        // ============= MOBILE NAVIGATION FUNCTION =============
        function initMobileNav() {
            const mobileNav = document.getElementById('mobileNav');
            if (!mobileNav) return;
            
            const isMobile = window.innerWidth <= 992;
            
            if (isMobile) {
                mobileNav.style.display = 'block';
                
                const currentPage = window.location.pathname.split('/').pop();
                const navLinks = mobileNav.querySelectorAll('.nav-link:not(.logout-btn)');
                
                navLinks.forEach(link => {
                    const href = link.getAttribute('href');
                    if (currentPage === href) {
                        link.classList.add('active');
                    } else {
                        link.classList.remove('active');
                    }
                });
            } else {
                mobileNav.style.display = 'none';
            }
        }

        // ============= PROFILE MODAL FUNCTIONS =============
        function showProfileModal() {
            const profileModal = new bootstrap.Modal(document.getElementById('profileModal'));
            profileModal.show();
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
        
        // Dynamic field management - UPDATED FOR MODERN DESIGN
        const requestTypeSelect = document.getElementById('requestType');
        const dynamicContainer = document.getElementById('dynamicFieldsContainer');
        
        function getDiscountInputHtml(extraClass = '') {
            return `
                <div class="dynamic-field-card ${extraClass}">
                    <label class="form-label fw-semibold mb-2">
                        Discount Type <span class="text-danger">*</span>
                    </label>
                    <div class="input-group-modern mb-2">
                        <span class="input-icon">
                            <i class="bi bi-sliders"></i>
                        </span>
                        <select class="form-control-modern discount-type-select" name="discount_calculation_type" required onchange="toggleDiscountInputs(this)">
                            <option value="percentage">Based on Percent</option>
                            <option value="amount_based">Based on Amount</option>
                        </select>
                    </div>
                    <div class="discount-percent-wrap">
                        <label class="form-label fw-semibold mb-2">Requested Discount Percent <span class="text-danger">*</span></label>
                        <div class="input-group-modern">
                            <span class="input-icon"><i class="bi bi-percent"></i></span>
                            <input type="number" class="form-control-modern discount-percent-input"
                                   name="discount_percent" step="0.01" min="0.01" max="100"
                                   placeholder="Enter discount percentage" required>
                        </div>
                        <small class="text-muted">Enter percentage between 1 and 100</small>
                    </div>
                    <div class="discount-amount-wrap" style="display:none;">
                        <label class="form-label fw-semibold mb-2">Requested Discount Amount <span class="text-danger">*</span></label>
                        <div class="input-group-modern">
                            <span class="input-icon">₱</span>
                            <input type="number" class="form-control-modern discount-amount-input"
                                   name="discount_based_amount" step="0.01" min="0.01"
                                   placeholder="Enter discount amount">
                        </div>
                        <small class="text-muted">Fixed amount discount to request for approval</small>
                    </div>
                </div>
            `;
        }

        function toggleDiscountInputs(selectEl) {
            const card = selectEl.closest('.dynamic-field-card');
            if (!card) return;
            const percentWrap = card.querySelector('.discount-percent-wrap');
            const amountWrap = card.querySelector('.discount-amount-wrap');
            const percentInput = card.querySelector('.discount-percent-input');
            const amountInput = card.querySelector('.discount-amount-input');

            if (selectEl.value === 'amount_based') {
                percentWrap.style.display = 'none';
                amountWrap.style.display = 'block';
                percentInput.required = false;
                percentInput.value = '';
                amountInput.required = true;
            } else {
                percentWrap.style.display = 'block';
                amountWrap.style.display = 'none';
                percentInput.required = true;
                amountInput.required = false;
                amountInput.value = '';
            }
        }

        function updateDynamicFields() {
            const type = requestTypeSelect.value;
            
            if (!dynamicContainer) return;
            
            let html = '';
            
            if (type === 'discount') {
                html = getDiscountInputHtml();
            } 
            else if (type === 'credit_terms') {
                html = `
                    <div class="dynamic-field-card">
                        <label class="form-label fw-semibold mb-2">
                            Credit Limit <span class="text-danger">*</span>
                        </label>
                        <div class="input-group-modern">
                            <span class="input-icon">
                                ₱
                            </span>
                            <input type="number" class="form-control-modern" 
                                   name="credit_limit" step="0.01" min="0" 
                                   placeholder="Enter credit limit amount">
                        </div>
                        <small class="text-muted">Enter the requested credit limit amount</small>
                    </div>
                    <div class="dynamic-field-card mt-3">
                        <label class="form-label fw-semibold mb-2">
                            Payment Terms <span class="text-danger">*</span>
                        </label>
                        <div class="input-group-modern">
                            <span class="input-icon">
                                <i class="bi bi-calendar"></i>
                            </span>
                            <input type="number" class="form-control-modern" 
                                   name="credit_terms_days" min="0" 
                                   placeholder="Enter number of days">
                        </div>
                        <small class="text-muted">Number of days for payment terms</small>
                    </div>
                `;
            }
            else if (type === 'both') {
                html = `
                    <div class="dynamic-field-card">
                        <label class="form-label fw-semibold mb-2">
                            Credit Limit <span class="text-danger">*</span>
                        </label>
                        <div class="input-group-modern">
                            <span class="input-icon">
                                ₱
                            </span>
                            <input type="number" class="form-control-modern" 
                                   name="credit_limit" step="0.01" min="0" 
                                   placeholder="Enter credit limit amount">
                        </div>
                        <small class="text-muted">Enter the requested credit limit amount</small>
                    </div>
                    <div class="dynamic-field-card mt-3">
                        <label class="form-label fw-semibold mb-2">
                            Payment Terms <span class="text-danger">*</span>
                        </label>
                        <div class="input-group-modern">
                            <span class="input-icon">
                                <i class="bi bi-calendar"></i>
                            </span>
                            <input type="number" class="form-control-modern" 
                                   name="credit_terms_days" min="0" 
                                   placeholder="Enter number of days">
                        </div>
                        <small class="text-muted">Number of days for payment terms</small>
                    </div>
                    ${getDiscountInputHtml('mt-3')}
                `;
            }
            else {
                html = '';
            }
            
            dynamicContainer.innerHTML = html;
        }
        
        // Event listener
        if (requestTypeSelect) {
            requestTypeSelect.addEventListener('change', updateDynamicFields);
        }
        
        // Drag and drop upload - UPDATED
        const uploadBox = document.getElementById('uploadBox');
        const fileInput = document.getElementById('attachments');
        
        if (uploadBox && fileInput) {
            uploadBox.addEventListener('click', () => {
                fileInput.click();
            });
            
            uploadBox.addEventListener('dragover', (e) => {
                e.preventDefault();
                uploadBox.classList.add('drag-over');
            });
            
            uploadBox.addEventListener('dragleave', () => {
                uploadBox.classList.remove('drag-over');
            });
            
            uploadBox.addEventListener('drop', (e) => {
                e.preventDefault();
                uploadBox.classList.remove('drag-over');
                
                const files = e.dataTransfer.files;
                fileInput.files = files;
                handleFileSelect({ target: fileInput });
            });
        }
        
        // Updated file preview function
        function updateFilePreview() {
            const previewDiv = document.getElementById('filePreview');
            if (!previewDiv) return;
            
            if (selectedFiles.length === 0) {
                previewDiv.style.display = 'none';
                previewDiv.innerHTML = '';
                return;
            }
            
            previewDiv.style.display = 'block';
            let html = '<strong>Selected Files</strong><div class="d-flex flex-wrap gap-2 mt-2">';
            selectedFiles.forEach((file, index) => {
                const fileSize = (file.size / 1024).toFixed(2);
                const fileType = getFileIcon(file.name);
                html += `
                    <div class="file-preview-item-modern">
                        <i class="bi bi-file-earmark-${fileType}"></i>
                        <span class="file-name">${escapeHtml(file.name)}</span>
                        <span class="file-size text-muted">(${fileSize} KB)</span>
                        <i class="bi bi-x-circle-fill remove-file" onclick="removeFile(${index})"></i>
                    </div>
                `;
            });
            html += '</div>';
            previewDiv.innerHTML = html;
        }
        
        // Get file icon based on extension
        function getFileIcon(filename) {
            const ext = filename.split('.').pop().toLowerCase();
            if (['jpg', 'jpeg', 'png', 'gif'].includes(ext)) return 'image';
            if (ext === 'pdf') return 'pdf';
            if (['doc', 'docx'].includes(ext)) return 'word';
            if (['xls', 'xlsx'].includes(ext)) return 'excel';
            return 'text';
        }
        
        // Remove file from selection
        function removeFile(index) {
            selectedFiles.splice(index, 1);
            const fileInput = document.getElementById('attachments');
            const dataTransfer = new DataTransfer();
            selectedFiles.forEach(file => dataTransfer.items.add(file));
            fileInput.files = dataTransfer.files;
            updateFilePreview();
        }
        
        // Escape HTML
        function escapeHtml(str) {
            return str.replace(/[&<>]/g, function(m) {
                if (m === '&') return '&amp;';
                if (m === '<') return '&lt;';
                if (m === '>') return '&gt;';
                return m;
            });
        }
        
        // Reset form - UPDATED
        function resetForm() {
            document.getElementById('requestForm').reset();
            selectedFiles = [];
            const fileInput = document.getElementById('attachments');
            if (fileInput) fileInput.value = '';
            updateFilePreview();
            
            // Clear dynamic fields
            if (dynamicContainer) {
                dynamicContainer.innerHTML = '';
            }
            
            // Reset request type select
            if (requestTypeSelect) {
                requestTypeSelect.value = '';
            }
            
            // Hide other reason div and clear
            const otherDiv = document.getElementById('otherReasonDiv');
            if (otherDiv) otherDiv.style.display = 'none';
            const reasonOther = document.getElementById('reasonOther');
            if (reasonOther) reasonOther.value = '';
            const reasonSelect = document.getElementById('reasonSelect');
            if (reasonSelect) {
                reasonSelect.value = '';
                // Re-run toggle to set required attributes
                if (typeof toggleOtherReason === 'function') toggleOtherReason();
            }
        }
        
        function toggleRequestTable() {
            const container = document.getElementById('requestTableContainer');
            const icon = document.getElementById('toggleTableIcon');  // Pinalitan ang ID
            
            console.log('Toggle clicked', container.style.display, icon.classList);
            
            if (container && icon) {
                // Check if currently hidden or visible
                if (container.style.display === 'none' || container.style.display === '') {
                    // OPEN - container is hidden
                    container.style.display = 'block';
                    setTimeout(() => {
                        container.classList.add('show');
                    }, 10);
                    // Change icon to UP (^) when open
                    icon.className = 'bi bi-chevron-up';
                    
                    // Smooth scroll to see the opened content
                    setTimeout(() => {
                        const card = container.closest('.card');
                        if (card) {
                            card.scrollIntoView({ 
                                behavior: 'smooth', 
                                block: 'start',
                                inline: 'nearest'
                            });
                        }
                    }, 200);
                } else {
                    // CLOSE - container is visible
                    container.classList.remove('show');
                    setTimeout(() => {
                        container.style.display = 'none';
                    }, 300);
                    // Change icon to DOWN (v) when closed
                    icon.className = 'bi bi-chevron-down';
                }
            }
        }
        
        // Initialize on page load - DEFAULT CLOSED with DOWN arrow
        document.addEventListener('DOMContentLoaded', function() {
            const container = document.getElementById('requestTableContainer');
            const icon = document.getElementById('toggleTableIcon');  // Pinalitan ang ID
            
            console.log('Page loaded - setting default closed state');
            
            // Default: closed
            if (container) {
                container.style.display = 'none';
                container.classList.remove('show');
            }
            // Default icon: chevron-down (v) for closed state
            if (icon) {
                icon.className = 'bi bi-chevron-down';
            }
        });
    </script>
</body>
</html>