<?php
require_once '../config/database.php';
require_once '../config/session_handler.php';

// Get current user info and branch context
$user_id = $_SESSION['user_id'] ?? 0;
$user_name = isset($_SESSION['first_name']) ? $_SESSION['first_name'] . ' ' . $_SESSION['last_name'] : 'Branch Admin';
$user_role = isset($_SESSION['role']) ? $_SESSION['role'] : 'branch_admin';
$branch_id = $_SESSION['branch_id'] ?? 0;
$view_all_branches = $_SESSION['view_all_branches'] ?? false;

// Check if branch_id column exists in rmr_requests table
$rmr_branch_column_exists = false;
$check_rmr_column = $conn->query("SHOW COLUMNS FROM rmr_requests LIKE 'branch_id'");
if ($check_rmr_column && $check_rmr_column->num_rows > 0) {
    $rmr_branch_column_exists = true;
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

// Determine branch filter condition
$rmr_branch_condition = "";
if ($rmr_branch_column_exists && !$view_all_branches) {
    $rmr_branch_condition = "AND r.branch_id = $branch_id";
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
            $user_id = $_SESSION['user_id'] ?? 1; // Default to 1 if not set
            
            // Verify RMR belongs to user's branch (if branch column exists and not admin)
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
            
            // Verify RMR belongs to user's branch (if branch column exists and not admin)
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
            
            // Update RMR status to approved
            $update_query = "UPDATE rmr_requests 
                           SET rmr_status = 'approved', 
                               disposition_type = ?,
                               updated_at = NOW() 
                           WHERE rmr_id = ?";
            $update_stmt = $conn->prepare($update_query);
            $update_stmt->bind_param("si", $disposition_type, $rmr_id);
            
            if (!$update_stmt->execute()) {
                throw new Exception('Failed to approve RMR');
            }
            
            // Insert into RMR approval history
            $history_query = "INSERT INTO rmr_approvals (rmr_id, approved_amount, approval_notes, approved_by, approved_at) 
                            VALUES (?, ?, ?, ?, NOW())";
            $history_stmt = $conn->prepare($history_query);
            $history_stmt->bind_param("idsi", $rmr_id, $approved_amount, $approval_notes, $user_id);
            $history_stmt->execute();
            
            $conn->commit();
            
            echo json_encode([
                'success' => true,
                'message' => 'RMR approved successfully'
            ]);
            exit;
        }
        
        // REJECT RMR
        elseif ($_POST['action'] === 'reject_rmr') {
            $rmr_id = (int)$_POST['rmr_id'];
            $rejection_reason = $_POST['rejection_reason'] ?? 'Rejected by QC';
            $user_id = $_SESSION['user_id'] ?? 1;
            
            // Verify RMR belongs to user's branch (if branch column exists and not admin)
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
                    c.customer_name,
                    c.customer_id,
                    i.item_code,
                    i.item_name,
                    i.unit_price,
                    i.unit_type,
                    b.branch_name,
                    CONCAT(u.first_name, ' ', u.last_name) as received_by_name
                FROM rmr_requests r
                JOIN customers c ON r.customer_id = c.customer_id
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
        
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
        exit;
    }
}

// FETCH RMR REQUESTS FROM DATABASE WITH JOINS AND BRANCH FILTERING
$rmr_query = "
    SELECT 
        r.rmr_id,
        r.rmr_number,
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
        c.customer_name,
        c.customer_id,
        i.item_id,
        i.item_code,
        i.item_name,
        i.unit_price,
        i.unit_type,
        b.branch_name,
        CONCAT(u.first_name, ' ', u.last_name) as received_by_name
    FROM rmr_requests r
    JOIN customers c ON r.customer_id = c.customer_id
    JOIN items i ON r.item_id = i.item_id
    LEFT JOIN branches b ON r.branch_id = b.branch_id
    LEFT JOIN users u ON r.received_by = u.user_id
    WHERE 1=1
    $rmr_branch_condition
    ORDER BY r.created_at DESC, r.rmr_id DESC
";
$rmr_result = $conn->query($rmr_query);
$rmr_requests = $rmr_result->fetch_all(MYSQLI_ASSOC);

// CALCULATE STATISTICS FROM REAL DATA (branch-specific)
$total_rmr = count($rmr_requests);
$pending_rmr = count(array_filter($rmr_requests, fn($r) => $r['rmr_status'] === 'pending'));
$processing_rmr = count(array_filter($rmr_requests, fn($r) => $r['rmr_status'] === 'processing'));
$approved_rmr = count(array_filter($rmr_requests, fn($r) => $r['rmr_status'] === 'approved'));
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
        'rejected' => 'status-rejected',
        'resolved' => 'status-resolved',
        default => 'status-pending'
    };
}

function getRMRStatusText($status) {
    return match($status) {
        'pending' => 'Pending',
        'processing' => 'Processing',
        'approved' => 'Approved',
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
        'credit' => 'Credit to Customer',
        'refund' => 'Cash Refund',
        'replacement' => 'Replacement',
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
    <link rel="icon" type="image/png" href="../Pictures/favicon-96x96.png" sizes="96x96" />
    <link rel="icon" type="image/svg+xml" href="../Pictures/favicon.svg" />
    <link rel="shortcut icon" href="../Pictures/favicon.ico" />
    <link rel="apple-touch-icon" sizes="180x180" href="../Pictures/apple-touch-icon.png" />
    <link rel="manifest" href="../Pictures/site.webmanifest" />
    <link rel="stylesheet" href="../css/current_inventory.css">
    <link rel="stylesheet" href="../css/bad_orders.css">
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
        
        /* Column widths - CHECKBOX COLUMN REMOVED */
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
    </style>
</head>
<body>
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
                        <a class="nav-link active" href="bad_orders.php">
                            <i class="bi bi-recycle"></i>
                            <span class="nav-text">Bad Orders</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="purchase_order.php">
                            <i class="bi bi-box"></i>
                            <span class="nav-text">Purchase Orders</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="trip_tickets.php">
                            <i class="bi bi-ticket-perforated"></i>
                            <span class="nav-text">Trip Tickets</span>
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
            <!-- BAD ORDERS CONTENT -->
            <div class="page-content active">
                <!-- Navbar Top -->
                <div class="navbar-top">
                    <button class="mobile-menu-btn" id="mobileMenuBtn">
                        <i class="bi bi-list"></i>
                    </button>
                    <div class="page-title">
                        <h2><i class="bi bi-recycle me-2"></i>Bad Orders</h2>
                        <p id="dashboardSubtitle">
                            Manage Returned Merchandise Requests (RMR)
                           
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

                <!-- No RMR Warning -->
                <?php if (empty($rmr_requests) && $rmr_branch_column_exists && !$view_all_branches): ?>
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle"></i> 
                        No RMR requests found for your branch.
                    </div>
                <?php endif; ?>

                <!-- Stats Section -->
                <div class="row g-3 mb-4">
                    <div class="col-md-3 col-6">
                        <div class="stat-card total">
                            <i class="bi bi-box-seam stat-icon"></i>
                            <div class="stat-value"><?= $statTotalRMR ?></div>
                            <div class="stat-label">Total RMR</div>
                            <?php if ($rmr_branch_column_exists && !$view_all_branches): ?>
                                <small class="d-block text-white-50">Your Branch</small>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="col-md-3 col-6">
                        <div class="stat-card pending">
                            <i class="bi bi-clock-history stat-icon"></i>
                            <div class="stat-value"><?= $statPendingRMR ?></div>
                            <div class="stat-label">Pending</div>
                        </div>
                    </div>
                    <div class="col-md-3 col-6">
                        <div class="stat-card processing">
                            <i class="bi bi-gear stat-icon"></i>
                            <div class="stat-value"><?= $statProcessingRMR ?></div>
                            <div class="stat-label">Processing</div>
                        </div>
                    </div>
                    <div class="col-md-3 col-6">
                        <div class="stat-card approved">
                            <i class="bi bi-check-circle stat-icon"></i>
                            <div class="stat-value"><?= $statApprovedRMR ?></div>
                            <div class="stat-label">Approved</div>
                        </div>
                    </div>
                </div>

                <!-- FILTER SECTION - WITH DROPDOWN FILTERS ONLY -->
                <div class="filter-section">
                    <div class="filter-controls">
                        <div class="filter-dropdowns">
                            <!-- Date Filter Dropdown -->
                            <div class="filter-dropdown">
                                <span class="filter-label">Date</span>
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
                            
                            <!-- Status Filter Dropdown -->
                            <div class="filter-dropdown">
                                <span class="filter-label">Status</span>
                                <select class="form-select" id="statusFilter" onchange="applyFilters()">
                                    <option value="all">All Status</option>
                                    <option value="pending">Pending</option>
                                    <option value="processing">Processing</option>
                                    <option value="approved">Approved</option>
                                    <option value="rejected">Rejected</option>
                                    <option value="resolved">Resolved</option>
                                </select>
                            </div>
                            
                            <!-- Reason Filter Dropdown -->
                            <div class="filter-dropdown">
                                <span class="filter-label">Reason</span>
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
                            
                            <!-- Quantity Filter Dropdown -->
                            <div class="filter-dropdown">
                                <span class="filter-label">Quantity</span>
                                <select class="form-select" id="quantityFilter" onchange="applyFilters()">
                                    <option value="all">All Quantities</option>
                                    <option value="lt10">Less than 10</option>
                                    <option value="10-50">10 - 50</option>
                                    <option value="51-100">51 - 100</option>
                                    <option value="101-500">101 - 500</option>
                                    <option value="gt500">Greater than 500</option>
                                </select>
                            </div>
                            
                            <?php if ($rmr_branch_column_exists && $view_all_branches): ?>
                            <!-- Branch Filter Dropdown (only visible to admin) -->
                            <div class="filter-dropdown">
                                <span class="filter-label">Branch</span>
                                <select class="form-select" id="branchFilter" onchange="applyFilters()">
                                    <option value="all">All Branches</option>
                                    <?php
                                    // Get unique branches from the data
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
                        </div>
                    </div>
                    
                    <div class="filter-actions">
                        <button class="btn btn-outline-primary" onclick="printRMRReport()">
                            <i class="bi bi-printer me-1"></i> Print
                        </button>
                        <button class="btn btn-outline-success" onclick="exportRMRToExcel()">
                            <i class="bi bi-file-earmark-excel me-1"></i> Export to Excel
                        </button>
                    </div>
                </div>

                <!-- RMR Table - WITHOUT CHECKBOX COLUMN -->
                <div class="table-responsive">
                    <table class="table rmr-table" id="rmrTable">
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
                                        RMR requests are created by the Sales team.
                                        <?php if ($rmr_branch_column_exists && !$view_all_branches): ?>
                                            <br>No requests found for your branch.
                                        <?php else: ?>
                                            <br>No requests available at this time.
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
                                                <button class="table-btn btn-process" onclick="processRMR(<?= $rmr['rmr_id'] ?>)" title="Process">
                                                    <i class="bi bi-gear"></i>
                                                </button>
                                            <?php elseif ($rmr['rmr_status'] === 'processing'): ?>
                                                <button class="table-btn btn-approve" onclick="showApprovalModal(<?= $rmr['rmr_id'] ?>, 'approve')" title="Approve">
                                                    <i class="bi bi-check-circle"></i>
                                                </button>
                                                <button class="table-btn btn-reject" onclick="showApprovalModal(<?= $rmr['rmr_id'] ?>, 'reject')" title="Reject">
                                                    <i class="bi bi-x-circle"></i>
                                                </button>
                                            <?php endif; ?>
                                            <button class="table-btn btn-view" onclick="viewRMR(<?= $rmr['rmr_id'] ?>)" title="View">
                                                <i class="bi bi-eye"></i>
                                            </button>
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
                                <option value="credit">Credit to Customer</option>
                                <option value="refund">Cash Refund</option>
                                <option value="replacement">Replacement Item</option>
                                <option value="disposal">Destroy Item</option>
                                <option value="return-to-supplier">Return to Supplier</option>
                            </select>
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

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
    // ========== GLOBAL VARIABLES ==========
    let selectedRMR = null;
    let currentAction = null;
    const branchId = <?php echo $branch_id; ?>;
    const viewAllBranches = <?php echo $view_all_branches ? 'true' : 'false'; ?>;
    const rmrBranchColumnExists = <?php echo $rmr_branch_column_exists ? 'true' : 'false'; ?>;
    const customersBranchColumnExists = <?php echo $customers_branch_column_exists ? 'true' : 'false'; ?>;
    const itemsBranchColumnExists = <?php echo $items_branch_column_exists ? 'true' : 'false'; ?>;
    
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
        Swal.fire({
            title: 'Processing...',
            text: 'Please wait',
            allowOutsideClick: false,
            didOpen: () => {
                Swal.showLoading();
            }
        });
    }

    // ========== FILTER FUNCTIONS ==========
    function applyFilters() {
        const dateFilter = document.getElementById('dateFilter').value;
        const statusFilter = document.getElementById('statusFilter').value;
        const reasonFilter = document.getElementById('reasonFilter').value;
        const quantityFilter = document.getElementById('quantityFilter').value;
        const branchFilter = document.getElementById('branchFilter')?.value || 'all';
        
        const rows = document.querySelectorAll('.rmr-row');
        let visibleCount = 0;
        
        rows.forEach(row => {
            let showRow = true;
            
            // Status filter
            if (statusFilter !== 'all') {
                const rowStatus = row.dataset.status;
                if (rowStatus !== statusFilter) showRow = false;
            }
            
            // Reason filter
            if (showRow && reasonFilter !== 'all') {
                const rowReason = row.dataset.reason;
                if (rowReason !== reasonFilter) showRow = false;
            }
            
            // Quantity filter
            if (showRow && quantityFilter !== 'all') {
                const rowQuantity = parseFloat(row.dataset.quantity);
                switch(quantityFilter) {
                    case 'lt10':
                        if (rowQuantity >= 10) showRow = false;
                        break;
                    case '10-50':
                        if (rowQuantity < 10 || rowQuantity > 50) showRow = false;
                        break;
                    case '51-100':
                        if (rowQuantity < 51 || rowQuantity > 100) showRow = false;
                        break;
                    case '101-500':
                        if (rowQuantity < 101 || rowQuantity > 500) showRow = false;
                        break;
                    case 'gt500':
                        if (rowQuantity <= 500) showRow = false;
                        break;
                }
            }
            
            // Branch filter (only when viewing all branches)
            if (showRow && rmrBranchColumnExists && viewAllBranches && branchFilter !== 'all') {
                const rowBranch = row.dataset.branch;
                if (rowBranch !== branchFilter) showRow = false;
            }
            
            // Date filter
            if (showRow && dateFilter !== 'all') {
                const rowDate = new Date(row.dataset.receivedDate);
                const today = new Date();
                today.setHours(0, 0, 0, 0);
                
                const yesterday = new Date(today);
                yesterday.setDate(yesterday.getDate() - 1);
                
                const startOfWeek = new Date(today);
                startOfWeek.setDate(today.getDate() - today.getDay());
                
                const endOfWeek = new Date(startOfWeek);
                endOfWeek.setDate(startOfWeek.getDate() + 6);
                
                const startOfLastWeek = new Date(startOfWeek);
                startOfLastWeek.setDate(startOfLastWeek.getDate() - 7);
                
                const endOfLastWeek = new Date(startOfLastWeek);
                endOfLastWeek.setDate(startOfLastWeek.getDate() + 6);
                
                const startOfMonth = new Date(today.getFullYear(), today.getMonth(), 1);
                const endOfMonth = new Date(today.getFullYear(), today.getMonth() + 1, 0);
                
                const startOfLastMonth = new Date(today.getFullYear(), today.getMonth() - 1, 1);
                const endOfLastMonth = new Date(today.getFullYear(), today.getMonth(), 0);
                
                const startOfQuarter = new Date(today.getFullYear(), Math.floor(today.getMonth() / 3) * 3, 1);
                const endOfQuarter = new Date(today.getFullYear(), Math.floor(today.getMonth() / 3) * 3 + 3, 0);
                
                const startOfLastQuarter = new Date(today.getFullYear(), Math.floor(today.getMonth() / 3) * 3 - 3, 1);
                const endOfLastQuarter = new Date(today.getFullYear(), Math.floor(today.getMonth() / 3) * 3, 0);
                
                const startOfYear = new Date(today.getFullYear(), 0, 1);
                const endOfYear = new Date(today.getFullYear(), 11, 31);
                
                const startOfLastYear = new Date(today.getFullYear() - 1, 0, 1);
                const endOfLastYear = new Date(today.getFullYear() - 1, 11, 31);
                
                switch(dateFilter) {
                    case 'today':
                        if (rowDate < today || rowDate > new Date(today.getTime() + 86400000 - 1)) showRow = false;
                        break;
                    case 'yesterday':
                        if (rowDate < yesterday || rowDate > new Date(yesterday.getTime() + 86400000 - 1)) showRow = false;
                        break;
                    case 'this_week':
                        if (rowDate < startOfWeek || rowDate > new Date(endOfWeek.getTime() + 86400000 - 1)) showRow = false;
                        break;
                    case 'last_week':
                        if (rowDate < startOfLastWeek || rowDate > new Date(endOfLastWeek.getTime() + 86400000 - 1)) showRow = false;
                        break;
                    case 'this_month':
                        if (rowDate < startOfMonth || rowDate > new Date(endOfMonth.getTime() + 86400000 - 1)) showRow = false;
                        break;
                    case 'last_month':
                        if (rowDate < startOfLastMonth || rowDate > new Date(endOfLastMonth.getTime() + 86400000 - 1)) showRow = false;
                        break;
                    case 'this_quarter':
                        if (rowDate < startOfQuarter || rowDate > new Date(endOfQuarter.getTime() + 86400000 - 1)) showRow = false;
                        break;
                    case 'last_quarter':
                        if (rowDate < startOfLastQuarter || rowDate > new Date(endOfLastQuarter.getTime() + 86400000 - 1)) showRow = false;
                        break;
                    case 'this_year':
                        if (rowDate < startOfYear || rowDate > new Date(endOfYear.getTime() + 86400000 - 1)) showRow = false;
                        break;
                    case 'last_year':
                        if (rowDate < startOfLastYear || rowDate > new Date(endOfLastYear.getTime() + 86400000 - 1)) showRow = false;
                        break;
                }
            }
            
            row.style.display = showRow ? '' : 'none';
            if (showRow) visibleCount++;
        });
        
        // Show empty state if no rows visible
        const emptyStateRow = document.querySelector('.empty-state-table');
        if (emptyStateRow) {
            const emptyStateParent = emptyStateRow.closest('tr');
            if (visibleCount === 0) {
                if (emptyStateParent) {
                    emptyStateParent.style.display = '';
                    emptyStateRow.innerHTML = `
                        <td colspan="${rmrBranchColumnExists && viewAllBranches ? '10' : '9'}" class="empty-state-table">
                            <i class="bi bi-funnel"></i>
                            <h5>No matching RMR requests</h5>
                            <p class="text-muted">No requests match your filter criteria.</p>
                        </td>
                    `;
                }
            } else {
                if (emptyStateParent) emptyStateParent.style.display = 'none';
            }
        }
    }

    // ========== RMR FUNCTIONS ==========
    document.addEventListener('DOMContentLoaded', function() {
        console.log("Bad Orders - Live Database Mode");
        console.log("Branch ID:", branchId);
        console.log("View All Branches:", viewAllBranches);
        console.log("RMR Branch Column Exists:", rmrBranchColumnExists);
        console.log("Customers Branch Column Exists:", customersBranchColumnExists);
        console.log("Items Branch Column Exists:", itemsBranchColumnExists);
        
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
    });

    // Process RMR
    function processRMR(id) {
        selectedRMR = id;
        new bootstrap.Modal(document.getElementById('processRMRModal')).show();
    }

    // Confirm Process RMR
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
            Swal.close();
            
            if (data.success) {
                Swal.fire({
                    icon: 'success',
                    title: 'Success!',
                    text: data.message,
                    timer: 2000,
                    showConfirmButton: false
                }).then(() => {
                    bootstrap.Modal.getInstance(document.getElementById('processRMRModal')).hide();
                    location.reload();
                });
            } else {
                Swal.fire('Error', data.message, 'error');
            }
        })
        .catch(error => {
            Swal.close();
            Swal.fire('Error', 'An error occurred while processing RMR', 'error');
        });
    }

    // Show Approval/Rejection Modal
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
            approvalMessage.textContent = 'Approve the selected RMR for credit/refund?';
            approvalFields.style.display = 'block';
            rejectionFields.style.display = 'none';
            approveBtn.style.display = 'inline-block';
            rejectBtn.style.display = 'none';
            
            // Clear and set default amount
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

    // Confirm Approval/Rejection
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
            
            fetch('bad_orders.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                Swal.close();
                
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
                Swal.close();
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
            
            fetch('bad_orders.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                Swal.close();
                
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
                Swal.close();
                Swal.fire('Error', 'An error occurred while rejecting RMR', 'error');
            });
        }
    }

    // View RMR Details
    function viewRMR(id) {
        showLoading();
        
        const formData = new FormData();
        formData.append('action', 'view_rmr');
        formData.append('rmr_id', id);
        
        fetch('bad_orders.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            Swal.close();
            
            if (data.success) {
                const rmr = data.rmr;
                const approval = data.approval;
                
                const totalAmount = rmr.return_quantity * rmr.unit_price;
                
                let branchHtml = '';
                if (rmr.branch_name) {
                    branchHtml = `
                        <tr>
                            <td class="detail-label">Branch:</td>
                            <td><span class="badge bg-info">${rmr.branch_name}</span></td>
                        </tr>
                    `;
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
                                    <tr>
                                        <td width="40%" class="detail-label">RMR Number:</td>
                                        <td class="detail-value">${rmr.rmr_number}</td>
                                    </tr>
                                    ${branchHtml}
                                    <tr>
                                        <td class="detail-label">Status:</td>
                                        <td><span class="status-badge ${getStatusClass(rmr.rmr_status)}">${getStatusText(rmr.rmr_status)}</span></td>
                                    </tr>
                                    <tr>
                                        <td class="detail-label">Received Date:</td>
                                        <td>${new Date(rmr.received_date).toLocaleString()}</td>
                                    </tr>
                                    <tr>
                                        <td class="detail-label">Received By:</td>
                                        <td>${rmr.received_by_name || 'N/A'}</td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="rmr-details-card">
                                <h6 class="fw-bold mb-3">Customer & Item Details</h6>
                                <table class="table table-sm table-borderless">
                                    <tr>
                                        <td width="40%" class="detail-label">Customer:</td>
                                        <td>${rmr.customer_name}</td>
                                    </tr>
                                    <tr>
                                        <td class="detail-label">Item Code:</td>
                                        <td>${rmr.item_code}</td>
                                    </tr>
                                    <tr>
                                        <td class="detail-label">Item Name:</td>
                                        <td>${rmr.item_name}</td>
                                    </tr>
                                    <tr>
                                        <td class="detail-label">Unit Type:</td>
                                        <td>${rmr.unit_type}</td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                    </div>
                    <div class="row mt-2">
                        <div class="col-md-6">
                            <div class="rmr-details-card">
                                <h6 class="fw-bold mb-3">Return Details</h6>
                                <table class="table table-sm table-borderless">
                                    <tr>
                                        <td width="40%" class="detail-label">Return Quantity:</td>
                                        <td>${rmr.return_quantity} ${rmr.unit_type}</td>
                                    </tr>
                                    <tr>
                                        <td class="detail-label">Unit Price:</td>
                                        <td>₱${Number(rmr.unit_price).toFixed(2)}</td>
                                    </tr>
                                    <tr>
                                        <td class="detail-label">Total Amount:</td>
                                        <td class="fw-bold">₱${Number(totalAmount).toFixed(2)}</td>
                                    </tr>
                                    <tr>
                                        <td class="detail-label">Return Reason:</td>
                                        <td><span class="return-reason ${getReasonClass(rmr.return_reason)}">${getReasonText(rmr.return_reason)}</span></td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="rmr-details-card">
                                <h6 class="fw-bold mb-3">Inspection Details</h6>
                                <table class="table table-sm table-borderless">
                                    <tr>
                                        <td width="40%" class="detail-label">Inspector:</td>
                                        <td>${rmr.inspector_name || 'N/A'}</td>
                                    </tr>
                                    <tr>
                                        <td class="detail-label">Inspection Type:</td>
                                        <td>${rmr.inspection_type ? rmr.inspection_type.charAt(0).toUpperCase() + rmr.inspection_type.slice(1) : 'N/A'}</td>
                                    </tr>
                                    <tr>
                                        <td class="detail-label">Disposition:</td>
                                        <td>${rmr.disposition_type ? rmr.disposition_type.replace(/-/g, ' ') : 'N/A'}</td>
                                    </tr>
                                    <tr>
                                        <td class="detail-label">Reason Details:</td>
                                        <td>${rmr.reason_details || 'N/A'}</td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                    </div>
                    ${approvalHtml}
                `;
                
                selectedRMR = id;
                
                // Show/hide edit button based on status
                const editBtn = document.getElementById('editFromViewBtn');
                if (rmr.rmr_status === 'pending' || rmr.rmr_status === 'processing') {
                    editBtn.style.display = 'inline-block';
                } else {
                    editBtn.style.display = 'none';
                }
                
                new bootstrap.Modal(document.getElementById('viewRMRModal')).show();
            } else {
                Swal.fire('Error', data.message, 'error');
            }
        })
        .catch(error => {
            Swal.close();
            Swal.fire('Error', 'An error occurred while fetching RMR details', 'error');
        });
    }

    // Edit RMR from View
    function editRMRFromView() {
        bootstrap.Modal.getInstance(document.getElementById('viewRMRModal')).hide();
        setTimeout(() => {
            if (selectedRMR) {
                // Open appropriate action based on status
                const row = document.querySelector(`.rmr-row[data-id="${selectedRMR}"]`);
                if (row) {
                    const status = row.dataset.status;
                    if (status === 'pending') {
                        processRMR(selectedRMR);
                    } else if (status === 'processing') {
                        showApprovalModal(selectedRMR, 'approve');
                    }
                }
            }
        }, 300);
    }

    // Helper functions
    function getStatusClass(status) {
        const classes = {
            'pending': 'status-pending',
            'processing': 'status-processing',
            'approved': 'status-approved',
            'rejected': 'status-rejected',
            'resolved': 'status-resolved'
        };
        return classes[status] || 'status-pending';
    }

    function getStatusText(status) {
        const texts = {
            'pending': 'Pending',
            'processing': 'Processing',
            'approved': 'Approved',
            'rejected': 'Rejected',
            'resolved': 'Resolved'
        };
        return texts[status] || status;
    }

    function getReasonClass(reason) {
        const classes = {
            'damaged': 'reason-damaged',
            'expired': 'reason-expired',
            'wrong-item': 'reason-wrong-item',
            'quality': 'reason-quality',
            'overstock': 'reason-overstock',
            'other': 'reason-other'
        };
        return classes[reason] || 'reason-other';
    }

    function getReasonText(reason) {
        const texts = {
            'damaged': 'Damaged',
            'expired': 'Expired',
            'wrong-item': 'Wrong Item',
            'quality': 'Quality Issue',
            'overstock': 'Overstock',
            'other': 'Other'
        };
        return texts[reason] || reason;
    }

    // Print RMR Details
    function printRMRDetails() {
        const content = document.getElementById('rmrDetailsContent').innerHTML;
        const printWindow = window.open('', '_blank');
        printWindow.document.write(`
            <html>
                <head>
                    <title>RMR Details</title>
                    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
                    <style>
                        body { padding: 20px; }
                        .status-badge { display: inline-block; padding: 5px 12px; font-size: 12px; border-radius: 20px; }
                        .status-pending { background-color: #fff3cd; color: #856404; }
                        .status-processing { background-color: #cce5ff; color: #004085; }
                        .status-approved { background-color: #d4edda; color: #155724; }
                        .status-rejected { background-color: #f8d7da; color: #721c24; }
                        .return-reason { display: inline-block; padding: 4px 10px; font-size: 12px; border-radius: 4px; }
                        .reason-damaged { background-color: #f8d7da; color: #721c24; }
                        .reason-expired { background-color: #fff3cd; color: #856404; }
                        .reason-wrong-item { background-color: #d1ecf1; color: #0c5460; }
                        .reason-quality { background-color: #cce5ff; color: #004085; }
                        .reason-overstock { background-color: #e2d5f2; color: #533f7c; }
                        .reason-other { background-color: #e9ecef; color: #495057; }
                    </style>
                </head>
                <body>
                    <h2 class="mb-4">RMR Details</h2>
                    ${content}
                </body>
            </html>
        `);
        printWindow.document.close();
        printWindow.print();
    }

    function printRMRReport() {
        window.print();
    }

    // ========== EXCEL EXPORT FUNCTION ==========
    function exportRMRToExcel() {
        // Get the table
        const table = document.getElementById('rmrTable');
        if (!table) {
            Swal.fire('Warning', 'Table not found', 'warning');
            return;
        }

        // Get visible rows only
        const rows = table.querySelectorAll('tbody tr.rmr-row:not([style*="display: none"])');
        if (rows.length === 0) {
            Swal.fire('Warning', 'No RMR requests to export', 'warning');
            return;
        }

        // Prepare data array for Excel
        const excelData = [];
        
        // Add headers
        const headers = [
            'RMR Number',
            'Customer',
            'Item Name',
            'Item Code',
            ...(rmrBranchColumnExists && viewAllBranches ? ['Branch'] : []),
            'Quantity',
            'Unit',
            'Total Amount (₱)',
            'Return Reason',
            'Status',
            'Received Date'
        ];
        excelData.push(headers);

        // Add data rows
        rows.forEach(row => {
            const cells = row.querySelectorAll('td');
            let cellIndex = 0;
            
            // Extract data from cells
            const rmrNumber = cells[cellIndex++]?.innerText.trim() || '';
            const customer = cells[cellIndex++]?.innerText.trim() || '';
            
            // Item details
            const itemName = cells[cellIndex]?.innerText.split('\n')[0].trim() || '';
            const itemCode = cells[cellIndex]?.querySelector('small')?.innerText.trim() || '';
            cellIndex++;
            
            let branch = '';
            if (rmrBranchColumnExists && viewAllBranches) {
                branch = cells[cellIndex]?.innerText.trim() || '';
                cellIndex++;
            }
            
            // Quantity with unit
            const qtyCell = cells[cellIndex++]?.innerText.trim() || '';
            const qty = qtyCell.split(' ')[0] || '';
            const unit = qtyCell.split(' ')[1] || '';
            
            // Amount (remove ₱ and commas)
            const amount = cells[cellIndex++]?.innerText.replace('₱', '').replace(/,/g, '') || '0';
            
            const reason = cells[cellIndex++]?.innerText.trim() || '';
            const status = cells[cellIndex++]?.innerText.trim() || '';
            const receivedDate = cells[cellIndex++]?.innerText.trim() || '';
            
            const rowData = [
                rmrNumber,
                customer,
                itemName,
                itemCode,
                ...(rmrBranchColumnExists && viewAllBranches ? [branch] : []),
                parseInt(qty) || 0,
                unit,
                parseFloat(amount),
                reason,
                status,
                receivedDate
            ];
            
            excelData.push(rowData);
        });

        // Create workbook and worksheet
        const wb = XLSX.utils.book_new();
        const ws = XLSX.utils.aoa_to_sheet(excelData);

        // Set column widths
        const colWidths = [
            { wch: 15 }, // RMR Number
            { wch: 25 }, // Customer
            { wch: 30 }, // Item Name
            { wch: 15 }, // Item Code
            ...(rmrBranchColumnExists && viewAllBranches ? [{ wch: 12 }] : []), // Branch
            { wch: 10 }, // Quantity
            { wch: 8 },  // Unit
            { wch: 15 }, // Total Amount
            { wch: 15 }, // Return Reason
            { wch: 15 }, // Status
            { wch: 20 }  // Received Date
        ];
        ws['!cols'] = colWidths;

        // Add worksheet to workbook
        XLSX.utils.book_append_sheet(wb, ws, 'RMR Requests');

        // Generate filename with current date and branch info
        const date = new Date();
        const dateStr = date.toISOString().slice(0,10).replace(/-/g, '');
        let filename = `RMR_Requests_${dateStr}`;
        if (rmrBranchColumnExists && !viewAllBranches) {
            filename += `_Branch_${branchId}`;
        }
        filename += '.xlsx';

        // Export Excel file
        XLSX.writeFile(wb, filename);
        
        Swal.fire({
            icon: 'success',
            title: 'Export Complete',
            text: 'Excel export completed successfully!',
            timer: 2000,
            showConfirmButton: false
        });
    }

    // ========== COPY SQL FUNCTION ==========
    function copySQL(table) {
        let sql = '';
        if (table === 'rmr_requests') {
            sql = "ALTER TABLE rmr_requests ADD COLUMN branch_id INT NULL;\nALTER TABLE rmr_requests ADD FOREIGN KEY (branch_id) REFERENCES branches(branch_id);";
        }
        
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

    // Logout Function
    function logout() {
        Swal.fire({
            title: 'Are you sure?',
            text: 'You will be logged out of the system',
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#0d6efd',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Yes, logout'
        }).then((result) => {
            if (result.isConfirmed) {
                localStorage.removeItem('sidebarCollapsed');
                window.location.href = '../logout.php';
            }
        });
    }
    </script>
</body>
</html>