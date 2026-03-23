<?php
// approve_credit_requests.php
// Branch Admin page to approve/reject credit/discount requests from sales agents
// UPDATED: Fixed database update issue with detailed error logging

require_once '../config/database.php';
require_once '../config/session_handler.php';

// Get current user info and branch context
$user_id = $_SESSION['user_id'] ?? 0;
$user_name = isset($_SESSION['first_name']) ? $_SESSION['first_name'] . ' ' . $_SESSION['last_name'] : 'Branch Admin';
$user_role = $_SESSION['role'] ?? 'branch_admin';
$branch_id = $_SESSION['branch_id'] ?? 0;
$view_all_branches = $_SESSION['view_all_branches'] ?? false;

// Enable error logging
ini_set('log_errors', 1);
ini_set('error_log', '../logs/approve_credit_errors.log');

// Check if credit_discount_requests table exists
$table_exists = false;
$check_table = $conn->query("SHOW TABLES LIKE 'credit_discount_requests'");
if ($check_table && $check_table->num_rows > 0) {
    $table_exists = true;
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
        
        // APPROVE REQUEST - FIXED VERSION WITH DETAILED LOGGING
        if ($_POST['action'] === 'approve_request') {
            $request_id = (int)$_POST['request_id'];
            $admin_notes = trim($_POST['admin_notes'] ?? '');
            
            // Log the attempt
            error_log("========== APPROVE REQUEST ATTEMPT ==========");
            error_log("Timestamp: " . date('Y-m-d H:i:s'));
            error_log("Request ID: " . $request_id);
            error_log("Admin User ID: " . $user_id);
            error_log("Admin Branch ID: " . $branch_id);
            error_log("View All Branches: " . ($view_all_branches ? 'Yes' : 'No'));
            error_log("Admin Notes: " . $admin_notes);
            
            // First, check if the request exists and get its current status
            $check_sql = "SELECT r.request_id, r.status, r.customer_id, 
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
            error_log("Customer Branch ID: " . ($request_data['customer_branch_id'] ?? 'NULL'));
            
            // Verify branch access if not admin
            if (!$view_all_branches && $customers_branch_column_exists) {
                if (!isset($request_data['customer_branch_id']) || $request_data['customer_branch_id'] != $branch_id) {
                    throw new Exception("Access denied: This customer belongs to a different branch");
                }
            }
            
            // Check if already approved
            if ($request_data['status'] === 'approved') {
                // Still return success but with warning
                echo json_encode([
                    'success' => true,
                    'message' => 'Request was already approved',
                    'already_approved' => true
                ]);
                $conn->commit();
                exit;
            }
            
            // DIRECT UPDATE - Simpleng update without CONCAT para iwas error
            if ($approved_by_column_exists) {
                $update_sql = "UPDATE credit_discount_requests 
                               SET status = 'approved', 
                                   admin_notes = ?,
                                   approved_at = NOW(), 
                                   approved_by = ? 
                               WHERE request_id = ?";
                
                error_log("Using update with approved_by column");
                error_log("SQL: " . $update_sql);
                
                $update_stmt = $conn->prepare($update_sql);
                if (!$update_stmt) {
                    throw new Exception("Prepare update failed: " . $conn->error);
                }
                
                $update_stmt->bind_param("sii", $admin_notes, $user_id, $request_id);
                
            } else {
                // Fallback if approved_by column doesn't exist
                $update_sql = "UPDATE credit_discount_requests 
                               SET status = 'approved', 
                                   admin_notes = ?,
                                   approved_at = NOW()
                               WHERE request_id = ?";
                
                error_log("Using update without approved_by column");
                error_log("SQL: " . $update_sql);
                
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
            
            if ($affected_rows === 0) {
                // No rows updated - check if request exists but status is already approved
                $final_check = $conn->query("SELECT status FROM credit_discount_requests WHERE request_id = $request_id");
                $final_status = $final_check->fetch_assoc();
                
                if ($final_status && $final_status['status'] === 'approved') {
                    error_log("Request already had status 'approved'");
                    // Still consider it a success
                } else {
                    throw new Exception("No rows were updated. Request may have been modified by another user.");
                }
            }
            
            // Commit the transaction
            $conn->commit();
            
            error_log("========== APPROVE REQUEST SUCCESS ==========");
            
            echo json_encode([
                'success' => true,
                'message' => 'Request approved successfully',
                'affected_rows' => $affected_rows,
                'request_id' => $request_id,
                'new_status' => 'approved'
            ]);
            exit;
        }
        
        // REJECT REQUEST - FIXED VERSION
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
                echo json_encode([
                    'success' => true,
                    'request' => $request
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
        r.reason,
        r.status,
        r.admin_notes,
        r.created_at,
        r.updated_at,
        r.approved_at,
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
        default => ucfirst($type)
    };
}

function formatDate($dateStr) {
    if (!$dateStr) return '';
    $date = new DateTime($dateStr);
    return $date->format('M d, Y H:i');
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
        
        /* Table column widths */
        .col-id { width: 5%; }
        .col-date { width: 10%; }
        .col-customer { width: 15%; }
        .col-agent { width: 12%; }
        .col-type { width: 8%; }
        .col-credit { width: 10%; }
        .col-discount { width: 8%; }
        .col-reason { width: 18%; }
        .col-status { width: 8%; }
        .col-actions { width: 12%; text-align: center; }
        
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
        
        .btn-view { color: #0d6efd; }
        .btn-approve { color: #198754; }
        .btn-reject { color: #dc3545; }
        
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
                        <i class="bi bi-list"></i>
                    </button>    
                    <img src="../Pictures/amgc3DLogo.png" alt="Logo" class="logo-icon"> 
                    <span class="nav-text">Branch Admin</span>
                </h3>
            </div>
            
            <div class="sidebar-menu">
                <ul class="nav flex-column">
                    <li class="nav-item">
                        <a class="nav-link" href="current_inventory.php">
                            <i class="bi bi-bar-chart-line"></i>
                            <span class="nav-text">Current Inventory</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="sales_order.php">
                            <i class="bi bi-bag"></i>
                            <span class="nav-text">Sales Orders</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="pick_list_items.php">
                            <i class="bi bi-list-check"></i>
                            <span class="nav-text">Pick List Items</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="bad_orders.php">
                            <i class="bi bi-recycle"></i>
                            <span class="nav-text">Bad Orders</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="supplier.php" data-title="Suppliers">
                            <i class="bi bi-building"></i>
                            <span class="nav-text">Suppliers</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="purchase_order.php">
                            <i class="bi bi-box"></i>
                            <span class="nav-text">Purchase Orders</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="drivers.php">
                            <i class="bi bi-truck"></i>
                            <span class="nav-text">Users</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="trip_tickets.php">
                            <i class="bi bi-ticket-perforated"></i>
                            <span class="nav-text">Trip Tickets</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="approve_credit_requests.php">
                            <i class="bi bi-pencil-square"></i>
                            <span class="nav-text">Approve Requests</span>
                        </a>
                    </li>
                    <hr class="sidebar-divider">
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
        <div class="main-content" id="mainContent">
            <!-- PAGE CONTENT -->
            <div class="page-content active">
                <!-- Navbar Top -->
                <div class="navbar-top">
                    <button class="mobile-menu-btn" id="mobileMenuBtn">
                        <i class="bi bi-list"></i>
                    </button>
                    <div class="page-title">
                        <h2><i class="bi bi-pencil-square me-2"></i>Approve Credit/Discount Requests</h2>
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

                <!-- Stats Section -->
                <div class="row g-3 mb-4">
                    <div class="col-md-3 col-6">
                        <div class="stat-card total">
                            <i class="bi bi-inbox stat-icon"></i>
                            <div class="stat-value"><?= $total_requests ?></div>
                            <div class="stat-label">Total Requests</div>
                        </div>
                    </div>
                    <div class="col-md-3 col-6">
                        <div class="stat-card pending">
                            <i class="bi bi-clock-history stat-icon"></i>
                            <div class="stat-value"><?= $pending_requests ?></div>
                            <div class="stat-label">Pending</div>
                        </div>
                    </div>
                    <div class="col-md-3 col-6">
                        <div class="stat-card approved">
                            <i class="bi bi-check-circle stat-icon"></i>
                            <div class="stat-value"><?= $approved_requests ?></div>
                            <div class="stat-label">Approved</div>
                        </div>
                    </div>
                    <div class="col-md-3 col-6">
                        <div class="stat-card rejected">
                            <i class="bi bi-x-circle stat-icon"></i>
                            <div class="stat-value"><?= $rejected_requests ?></div>
                            <div class="stat-label">Rejected</div>
                        </div>
                    </div>
                </div>

                <!-- FILTER SECTION -->
                <div class="filter-section">
                    <div class="filter-controls">
                        <div class="filter-dropdowns">
                            <!-- Status Filter -->
                            <div class="filter-dropdown">
                                <span class="filter-label">Status</span>
                                <select class="form-select" id="statusFilter" onchange="filterRequests()">
                                    <option value="all">All Status</option>
                                    <option value="pending">Pending</option>
                                    <option value="approved">Approved</option>
                                    <option value="rejected">Rejected</option>
                                </select>
                            </div>
                            
                            <!-- Type Filter -->
                            <div class="filter-dropdown">
                                <span class="filter-label">Type</span>
                                <select class="form-select" id="typeFilter" onchange="filterRequests()">
                                    <option value="all">All Types</option>
                                    <option value="credit">Credit Limit</option>
                                    <option value="discount">Discount</option>
                                    <option value="both">Both</option>
                                </select>
                            </div>
                            
                            <!-- Search Box -->
                            <div class="search-box">
                                <i class="bi bi-search"></i>
                                <input type="text" id="searchInput" placeholder="Search customer, agent, reason..." onkeyup="filterRequests()">
                            </div>
                        </div>
                    </div>
                    
                    <div class="filter-actions">
                        <button class="btn btn-outline-primary" onclick="printRequests()">
                            <i class="bi bi-printer me-1"></i> Print
                        </button>
                        <button class="btn btn-outline-success" onclick="exportToExcel()">
                            <i class="bi bi-file-earmark-excel me-1"></i> Export
                        </button>
                    </div>
                </div>

                <!-- Requests Table -->
                <div class="table-container">
                    <table class="table custom-table compact-table" id="requestsTable">
                        <thead>
                            <tr>
                                <th class="col-id">ID</th>
                                <th class="col-date">DATE</th>
                                <th class="col-customer">CUSTOMER</th>
                                <th class="col-agent">AGENT</th>
                                <th class="col-type">TYPE</th>
                                <th class="col-credit">CREDIT (₱)</th>
                                <th class="col-discount">DISCOUNT (%)</th>
                                <th class="col-reason">REASON</th>
                                <th class="col-status">STATUS</th>
                                <th class="col-actions">ACTIONS</th>
                            </tr>
                        </thead>
                        <tbody id="requestsTableBody">
                            <?php if (empty($requests) && $table_exists): ?>
                            <tr>
                                <td colspan="10" class="empty-state-table">
                                    <i class="bi bi-inbox"></i>
                                    <h5>No Requests Found</h5>
                                    <p class="text-muted">There are no credit/discount requests to display.</p>
                                </td>
                            </tr>
                            <?php elseif (!$table_exists): ?>
                            <tr>
                                <td colspan="10" class="empty-state-table">
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
                                        default => 'type-credit'
                                    };
                                ?>
                                <tr class="request-row" 
                                    data-id="<?= $req['request_id'] ?>"
                                    data-status="<?= $req['status'] ?>"
                                    data-type="<?= $req['request_type'] ?>"
                                    data-customer="<?= htmlspecialchars($req['customer_name']) ?>"
                                    data-agent="<?= htmlspecialchars($req['agent_name'] ?? '') ?>"
                                    data-credit="<?= $req['requested_credit_limit'] ?>"
                                    data-discount="<?= $req['requested_discount_percent'] ?>">
                                    <td class="col-id"><?= $req['request_id'] ?></td>
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
                                    <td class="col-reason">
                                        <?= htmlspecialchars(substr($req['reason'], 0, 50)) . (strlen($req['reason']) > 50 ? '...' : '') ?>
                                    </td>
                                    <td class="col-status">
                                        <span class="status-badge <?= getRequestStatusClass($req['status']) ?>">
                                            <?= getRequestStatusText($req['status']) ?>
                                        </span>
                                    </td>
                                    <td class="col-actions">
                                        <div class="action-buttons">
                                            <button class="btn-action btn-view" onclick="viewRequest(<?= $req['request_id'] ?>)" title="View">
                                                <i class="bi bi-eye"></i>
                                            </button>
                                            <?php if ($req['status'] === 'pending'): ?>
                                                <button class="btn-action btn-approve" onclick="showApprovalModal(<?= $req['request_id'] ?>, 'approve')" title="Approve">
                                                    <i class="bi bi-check-circle"></i>
                                                </button>
                                                <button class="btn-action btn-reject" onclick="showApprovalModal(<?= $req['request_id'] ?>, 'reject')" title="Reject">
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
        </div>
    </div>

    <!-- View Request Modal -->
    <div class="modal fade" id="viewRequestModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title"><i class="bi bi-eye me-2"></i>Request Details</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="viewRequestContent">
                    <!-- Content will be loaded here -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-warning" id="editFromViewBtn" style="display: none;">Edit</button>
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
                        <div class="alert alert-info mb-3">
                            <i class="bi bi-info-circle me-2"></i>
                            Approving for Branch <?= $branch_id ?>
                        </div>
                    <?php endif; ?>
                    <div id="approvalFields">
                        <div class="mb-3">
                            <label class="form-label" id="approvalLabel">Admin Notes</label>
                            <textarea class="form-control" id="adminNotes" rows="3" placeholder="Enter any notes..."></textarea>
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

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
    // ========== GLOBAL VARIABLES ==========
    let selectedRequestId = null;
    let currentAction = null;
    const branchId = <?php echo $branch_id; ?>;
    const viewAllBranches = <?php echo $view_all_branches ? 'true' : 'false'; ?>;
    const customersBranchColumnExists = <?php echo $customers_branch_column_exists ? 'true' : 'false'; ?>;
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

    // ========== FILTER FUNCTIONS ==========
    function filterRequests() {
        const statusFilter = document.getElementById('statusFilter').value;
        const typeFilter = document.getElementById('typeFilter').value;
        const searchTerm = document.getElementById('searchInput').value.toLowerCase();
        
        const rows = document.querySelectorAll('.request-row');
        let visibleCount = 0;
        
        rows.forEach(row => {
            let showRow = true;
            
            // Status filter
            if (statusFilter !== 'all') {
                if (row.dataset.status !== statusFilter) showRow = false;
            }
            
            // Type filter
            if (showRow && typeFilter !== 'all') {
                if (row.dataset.type !== typeFilter) showRow = false;
            }
            
            // Search filter
            if (showRow && searchTerm !== '') {
                const customer = row.dataset.customer?.toLowerCase() || '';
                const agent = row.dataset.agent?.toLowerCase() || '';
                const reason = row.querySelector('.col-reason')?.innerText.toLowerCase() || '';
                if (!customer.includes(searchTerm) && !agent.includes(searchTerm) && !reason.includes(searchTerm)) {
                    showRow = false;
                }
            }
            
            row.style.display = showRow ? '' : 'none';
            if (showRow) visibleCount++;
        });
    }

    // ========== REQUEST FUNCTIONS ==========
    document.addEventListener('DOMContentLoaded', function() {
        console.log("Approve Credit Requests - Branch Admin (FIXED VERSION)");
        console.log("Branch ID:", branchId);
        console.log("View All Branches:", viewAllBranches);
        
        initializeSidebar();
        
        document.getElementById('mobileMenuBtn').addEventListener('click', function() {
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
                toggleSidebar();
            }
        });
        
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
            
            if (isMobile && sidebar.classList.contains('active') && 
                !sidebar.contains(event.target) && 
                !mobileBtn.contains(event.target) &&
                !overlay?.contains(event.target)) {
                closeMobileSidebar();
            }
        });

        // Fix modal backdrop issue
        const modals = ['viewRequestModal', 'approvalModal'];
        modals.forEach(modalId => {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.addEventListener('hidden.bs.modal', function () {
                    const backdrop = document.querySelector('.modal-backdrop');
                    if (backdrop) backdrop.remove();
                    document.body.classList.remove('modal-open');
                    document.body.style.removeProperty('padding-right');
                });
            }
        });
    });

    // View Request Details
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
                
                // Format type
                let typeText = r.request_type === 'credit' ? 'Credit Limit Increase' :
                               r.request_type === 'discount' ? 'Discount' : 'Credit & Discount';
                
                let creditHtml = r.requested_credit_limit ? '₱' + parseFloat(r.requested_credit_limit).toFixed(2) : '—';
                let discountHtml = r.requested_discount_percent ? r.requested_discount_percent + '%' : '—';
                
                let statusClass = '';
                if (r.status === 'pending') statusClass = 'status-pending';
                else if (r.status === 'approved') statusClass = 'status-approved';
                else statusClass = 'status-rejected';
                
                const content = document.getElementById('viewRequestContent');
                content.innerHTML = `
                    <div class="row">
                        <div class="col-md-6">
                            <div class="rmr-details-card p-3 mb-3">
                                <h6 class="fw-bold mb-3">Request Information</h6>
                                <table class="table table-sm table-borderless">
                                    <tr><td width="40%" class="detail-label">Request ID:</td><td class="detail-value">${r.request_id}</td></tr>
                                    <tr><td class="detail-label">Customer:</td><td><strong>${r.customer_name}</strong><br><small>${r.customer_code}</small></td></tr>
                                    <tr><td class="detail-label">Agent:</td><td>${r.first_name} ${r.last_name}<br><small>${r.agent_email}</small></td></tr>
                                    <tr><td class="detail-label">Request Type:</td><td><span class="type-badge ${r.request_type === 'credit' ? 'type-credit' : (r.request_type === 'discount' ? 'type-discount' : 'type-both')}">${typeText}</span></td></tr>
                                    <tr><td class="detail-label">Credit Limit:</td><td>${creditHtml}</td></tr>
                                    <tr><td class="detail-label">Discount %:</td><td>${discountHtml}</td></tr>
                                    <tr><td class="detail-label">Status:</td><td><span class="status-badge ${statusClass}">${r.status}</span></td></tr>
                                    <tr><td class="detail-label">Created:</td><td>${new Date(r.created_at).toLocaleString()}</td></tr>
                                </table>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="rmr-details-card p-3 mb-3">
                                <h6 class="fw-bold mb-3">Reason</h6>
                                <p>${r.reason.replace(/\n/g, '<br>')}</p>
                                ${r.admin_notes ? `
                                <h6 class="fw-bold mt-3 mb-2">Admin Notes</h6>
                                <p>${r.admin_notes.replace(/\n/g, '<br>')}</p>
                                ` : ''}
                                ${r.approved_at ? `
                                <p class="text-muted small mt-2">Approved at: ${new Date(r.approved_at).toLocaleString()}</p>
                                ` : ''}
                            </div>
                        </div>
                    </div>
                `;
                
                selectedRequestId = id;
                
                // Show/hide edit button if needed
                document.getElementById('editFromViewBtn').style.display = 'none';
                
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

    // Show Approval/Rejection Modal
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

    // Confirm Approval/Rejection - FIXED VERSION
    function confirmApproval(action) {
        if (action === 'approve') {
            const adminNotes = document.getElementById('adminNotes').value;
            
            showLoading();
            
            const formData = new FormData();
            formData.append('action', 'approve_request');
            formData.append('request_id', selectedRequestId);
            formData.append('admin_notes', adminNotes);
            
            fetch('approve_credit_requests.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                hideLoading();
                
                console.log('Approve response:', data); // Debug log
                
                if (data.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Approved!',
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
        const printBtn = document.querySelector('.btn-outline-primary[onclick="printRequests()"]');
        if (printBtn) {
            const originalText = printBtn.innerHTML;
            printBtn.innerHTML = '<i class="bi bi-printer"></i> Preparing...';
            printBtn.disabled = true;
        }

        // Get current filter values
        const filterData = {
            status: document.getElementById('statusFilter').value,
            type: document.getElementById('typeFilter').value,
            search: document.getElementById('searchInput').value
        };
        
        showLoading();
        
        // We'll just print the visible rows from the table (simpler)
        const visibleRows = document.querySelectorAll('.request-row:not([style*="display: none"])');
        
        if (visibleRows.length === 0) {
            Swal.fire('Warning', 'No requests to print', 'warning');
            if (printBtn) {
                printBtn.innerHTML = '<i class="bi bi-printer"></i> Print';
                printBtn.disabled = false;
            }
            return;
        }
        
        // Build print HTML from visible rows
        let tableRows = '';
        let totalPending = 0, totalApproved = 0, totalRejected = 0;
        
        visibleRows.forEach(row => {
            const cells = row.querySelectorAll('td');
            let idx = 0;
            const id = cells[idx++]?.innerText;
            const date = cells[idx++]?.innerText;
            const customer = cells[idx]?.querySelector('strong')?.innerText || cells[idx]?.innerText;
            idx++; // skip customer cell after extracting
            const agent = cells[idx++]?.innerText;
            const typeCell = cells[idx++]?.innerText.trim();
            const credit = cells[idx++]?.innerText;
            const discount = cells[idx++]?.innerText;
            const reason = cells[idx++]?.innerText;
            const status = cells[idx++]?.innerText;
            
            if (status === 'Pending') totalPending++;
            else if (status === 'Approved') totalApproved++;
            else if (status === 'Rejected') totalRejected++;
            
            tableRows += '<tr>';
            tableRows += `<td style="padding: 3px; border: 1px solid #000;">${id}</td>`;
            tableRows += `<td style="padding: 3px; border: 1px solid #000;">${date}</td>`;
            tableRows += `<td style="padding: 3px; border: 1px solid #000;">${customer}</td>`;
            tableRows += `<td style="padding: 3px; border: 1px solid #000;">${agent}</td>`;
            tableRows += `<td style="padding: 3px; border: 1px solid #000;">${typeCell}</td>`;
            tableRows += `<td style="padding: 3px; border: 1px solid #000; text-align: right;">${credit}</td>`;
            tableRows += `<td style="padding: 3px; border: 1px solid #000; text-align: right;">${discount}</td>`;
            tableRows += `<td style="padding: 3px; border: 1px solid #000;">${reason}</td>`;
            tableRows += `<td style="padding: 3px; border: 1px solid #000;">${status}</td>`;
            tableRows += '</tr>';
        });
        
        const currentDate = new Date().toLocaleDateString('en-US', { 
            year: 'numeric', 
            month: 'short', 
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });
        
        const htmlContent = `
            <!DOCTYPE html>
            <html>
            <head>
                <meta charset="UTF-8">
                <title>Credit/Discount Requests</title>
                <style>
                    body { font-family: Arial, sans-serif; margin: 0; padding: 0; font-size: 9px; }
                    .print-container { max-width: 100%; margin: 0; }
                    .print-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 5px; border-bottom: 1px solid #000; padding-bottom: 3px; }
                    .logo-section { display: flex; align-items: center; gap: 5px; }
                    .company-logo { width: 30px; height: auto; }
                    .company-info h1 { font-size: 14px; margin: 0; font-weight: bold; }
                    .company-info p { font-size: 8px; margin: 0; }
                    .report-title h2 { font-size: 12px; margin: 0; }
                    .report-title .date-info { font-size: 8px; }
                    .summary-box { border: 1px solid #000; padding: 3px; margin-bottom: 5px; display: flex; }
                    .summary-item { flex: 1; text-align: center; border-right: 1px solid #000; }
                    .summary-item:last-child { border-right: none; }
                    .summary-label { font-size: 8px; font-weight: bold; }
                    .summary-value { font-size: 11px; font-weight: bold; }
                    table { width: 100%; border-collapse: collapse; font-size: 8px; }
                    th { border: 1px solid #000; padding: 3px; text-align: left; font-weight: bold; background: white !important; color: black !important; }
                    td { border: 1px solid #000; padding: 3px; }
                    .print-footer { margin-top: 5px; border-top: 1px solid #000; padding-top: 3px; display: flex; justify-content: space-between; font-size: 8px; }
                </style>
            </head>
            <body>
                <div class="print-container">
                    <div class="print-header">
                        <div class="logo-section">
                            <img src="${logoBase64}" alt="AMGC Logo" class="company-logo">
                            <div class="company-info">
                                <h1>AMGC</h1>
                                <p>Requests Report</p>
                            </div>
                        </div>
                        <div class="report-title">
                            <h2>CREDIT/DISCOUNT REQUESTS</h2>
                            <div class="date-info">${currentDate}</div>
                        </div>
                    </div>
                    
                    <div class="summary-box">
                        <div class="summary-item"><div class="summary-label">Total</div><div class="summary-value">${visibleRows.length}</div></div>
                        <div class="summary-item"><div class="summary-label">Pending</div><div class="summary-value">${totalPending}</div></div>
                        <div class="summary-item"><div class="summary-label">Approved</div><div class="summary-value">${totalApproved}</div></div>
                        <div class="summary-item"><div class="summary-label">Rejected</div><div class="summary-value">${totalRejected}</div></div>
                        <div class="summary-item"><div class="summary-label">Branch</div><div class="summary-value">${!viewAllBranches ? 'Branch ' + branchId : 'All'}</div></div>
                    </div>
                    
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Date</th>
                                <th>Customer</th>
                                <th>Agent</th>
                                <th>Type</th>
                                <th style="text-align: right;">Credit</th>
                                <th style="text-align: right;">Discount</th>
                                <th>Reason</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${tableRows}
                        </tbody>
                    </table>
                    
                    <div class="print-footer">
                        <div>Generated: ${currentDate}</div>
                        <div>${document.querySelector('.user-name-sidebar')?.textContent || 'Branch Admin'}</div>
                    </div>
                </div>
            </body>
            </html>
        `;
        
        hideLoading();
        
        const iframe = document.getElementById('printFrame');
        const iframeDoc = iframe.contentWindow.document;
        
        iframeDoc.open();
        iframeDoc.write(htmlContent);
        iframeDoc.close();
        
        setTimeout(() => {
            if (printBtn) {
                printBtn.innerHTML = '<i class="bi bi-printer"></i> Print';
                printBtn.disabled = false;
            }
        }, 1000);
        
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
        
        const excelData = [];
        
        const headers = [
            'Request ID',
            'Date',
            'Customer',
            'Agent',
            'Type',
            'Credit Limit (₱)',
            'Discount (%)',
            'Reason',
            'Status'
        ];
        excelData.push(headers);
        
        rows.forEach(row => {
            const cells = row.querySelectorAll('td');
            let idx = 0;
            const id = cells[idx++]?.innerText || '';
            const date = cells[idx++]?.innerText || '';
            const customer = cells[idx]?.querySelector('strong')?.innerText || cells[idx]?.innerText || '';
            idx++; // skip customer cell after extracting
            const agent = cells[idx++]?.innerText || '';
            const type = cells[idx++]?.innerText || '';
            const credit = cells[idx++]?.innerText.replace('₱', '').replace(/,/g, '') || '';
            const discount = cells[idx++]?.innerText.replace('%', '') || '';
            const reason = cells[idx++]?.innerText || '';
            const status = cells[idx++]?.innerText || '';
            
            excelData.push([id, date, customer, agent, type, credit, discount, reason, status]);
        });
        
        const wb = XLSX.utils.book_new();
        const ws = XLSX.utils.aoa_to_sheet(excelData);
        
        ws['!cols'] = [
            { wch: 8 }, { wch: 15 }, { wch: 25 }, { wch: 20 }, { wch: 12 },
            { wch: 15 }, { wch: 12 }, { wch: 30 }, { wch: 12 }
        ];
        
        XLSX.utils.book_append_sheet(wb, ws, 'CreditDiscountRequests');
        
        const date = new Date();
        const dateStr = date.toISOString().slice(0,10).replace(/-/g, '');
        let filename = `Credit_Discount_Requests_${dateStr}`;
        if (!viewAllBranches) {
            filename += `_Branch_${branchId}`;
        }
        filename += '.xlsx';
        
        XLSX.writeFile(wb, filename);
        
        Swal.fire({
            icon: 'success',
            title: 'Export Complete',
            timer: 2000,
            showConfirmButton: false
        });
    }

    // ========== COPY SQL FUNCTION ==========
    function copySQL() {
        const sql = `CREATE TABLE IF NOT EXISTS \`credit_discount_requests\` (
  \`request_id\` int(11) NOT NULL AUTO_INCREMENT,
  \`customer_id\` int(11) NOT NULL,
  \`agent_id\` int(11) NOT NULL,
  \`request_type\` enum('credit','discount','both') NOT NULL,
  \`requested_credit_limit\` decimal(12,2) DEFAULT NULL,
  \`requested_discount_percent\` decimal(5,2) DEFAULT NULL,
  \`reason\` text DEFAULT NULL,
  \`status\` enum('pending','approved','rejected') DEFAULT 'pending',
  \`admin_notes\` text DEFAULT NULL,
  \`created_at\` timestamp NOT NULL DEFAULT current_timestamp(),
  \`updated_at\` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  \`approved_at\` datetime DEFAULT NULL,
  \`approved_by\` int(11) DEFAULT NULL,
  PRIMARY KEY (\`request_id\`),
  KEY \`customer_id\` (\`customer_id\`),
  KEY \`agent_id\` (\`agent_id\`),
  KEY \`approved_by\` (\`approved_by\`),
  CONSTRAINT \`credit_discount_requests_ibfk_1\` FOREIGN KEY (\`customer_id\`) REFERENCES \`customers\` (\`customer_id\`),
  CONSTRAINT \`credit_discount_requests_ibfk_2\` FOREIGN KEY (\`agent_id\`) REFERENCES \`users\` (\`user_id\`),
  CONSTRAINT \`credit_discount_requests_ibfk_3\` FOREIGN KEY (\`approved_by\`) REFERENCES \`users\` (\`user_id\`)
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

    // ========== LOGOUT ==========
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
                localStorage.removeItem('sidebarCollapsed');
                window.location.href = '../logout.php';
            }
        });
    }

    // Keyboard shortcuts
    document.addEventListener('keydown', function(e) {
        if (e.ctrlKey && e.key === 'b' && window.innerWidth > 992) {
            e.preventDefault();
            toggleSidebar();
        } else if (e.ctrlKey && e.key === 'f') {
            e.preventDefault();
            document.getElementById('searchInput').focus();
        } else if (e.ctrlKey && e.key === 'p') {
            e.preventDefault();
            printRequests();
        }
    });
    </script>
</body>
</html>