<?php
// Start session and include database connection
require_once '../config/database.php';
require_once '../config/session_handler.php';

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: ../login.php");
    exit();
}

// Get current user info and branch context
$user_id = $_SESSION['user_id'];
$user_name = isset($_SESSION['first_name']) ? $_SESSION['first_name'] . ' ' . $_SESSION['last_name'] : 'Driver User';
$user_role = isset($_SESSION['role']) ? $_SESSION['role'] : 'delivery';
$branch_id = $_SESSION['branch_id'] ?? 0;
$view_all_branches = $_SESSION['view_all_branches'] ?? false;

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

// AUTO-FIX: Kung ang user ay delivery role at walang branch_id, i-set sa 1 (Main Branch)
if ($user_role == 'delivery' && $branch_id == 0) {
    $branch_id = 1;
    $_SESSION['branch_id'] = 1;
}

// Check if driver_id exists in session or get from users table
$driver_id = $_SESSION['driver_id'] ?? 0;
$driver_info = null;

if ($user_role == 'delivery') {
    // Try to get driver_id from session first
    $driver_id = $_SESSION['driver_id'] ?? 0;
    
    // If not in session, get from users table
    if ($driver_id == 0) {
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
    
    // Get full driver info
    if ($driver_id > 0) {
        $driver_info_query = "SELECT * FROM drivers WHERE driver_id = ?";
        $driver_info_stmt = $conn->prepare($driver_info_query);
        $driver_info_stmt->bind_param("i", $driver_id);
        $driver_info_stmt->execute();
        $driver_info_result = $driver_info_stmt->get_result();
        $driver_info = $driver_info_result->fetch_assoc();
        $driver_info_stmt->close();
    }
}

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
    $user_initials = 'DV';
}

// Check if branch_id column exists in deliveries table
$delivery_branch_column_exists = false;
$check_delivery_column = $conn->query("SHOW COLUMNS FROM deliveries LIKE 'branch_id'");
if ($check_delivery_column && $check_delivery_column->num_rows > 0) {
    $delivery_branch_column_exists = true;
}

// Check if branch_id column exists in sales_orders table
$so_branch_column_exists = false;
$check_so_column = $conn->query("SHOW COLUMNS FROM sales_orders LIKE 'branch_id'");
if ($check_so_column && $check_so_column->num_rows > 0) {
    $so_branch_column_exists = true;
}

// Check if rejection_photo and rejection_reason columns exist in deliveries table
$rejection_photo_column_exists = false;
$check_photo_column = $conn->query("SHOW COLUMNS FROM deliveries LIKE 'rejection_photo'");
if ($check_photo_column && $check_photo_column->num_rows > 0) {
    $rejection_photo_column_exists = true;
}

$rejection_reason_column_exists = false;
$check_reason_column = $conn->query("SHOW COLUMNS FROM deliveries LIKE 'rejection_reason'");
if ($check_reason_column && $check_reason_column->num_rows > 0) {
    $rejection_reason_column_exists = true;
}

// Determine branch filter condition
$branch_condition = "";

if ($delivery_branch_column_exists && !$view_all_branches && $branch_id > 0) {
    $branch_condition = "AND d.branch_id = $branch_id";
}

// DEBUG: Check what deliveries exist
$debug_query = "SELECT COUNT(*) as total FROM deliveries";
$debug_result = $conn->query($debug_query);
$debug_row = $debug_result->fetch_assoc();
$total_deliveries = $debug_row['total'];

$debug_query2 = "SELECT delivery_status, COUNT(*) as count FROM deliveries GROUP BY delivery_status";
$debug_result2 = $conn->query($debug_query2);
$status_counts = [];
while ($row = $debug_result2->fetch_assoc()) {
    $status_counts[] = $row['delivery_status'] . ': ' . $row['count'];
}
$status_summary = implode(', ', $status_counts);

// Get delivery orders that can be rejected (pending, in-transit, partial)
try {
    // First, get ALL deliveries for debugging
    $all_deliveries_query = "
        SELECT 
            d.delivery_id,
            d.delivery_status,
            d.trip_id,
            d.so_id,
            d.stop_sequence,
            d.driver_id,
            d.rejection_photo,
            d.rejection_reason,
            d.delivery_date,
            d.remarks,
            so.so_number,
            so.order_status,
            c.customer_id,
            c.customer_name,
            c.contact_person,
            c.phone_number,
            c.address,
            c.city,
            c.full_address,
            tt.trip_number,
            tt.trip_status,
            dr.driver_name
        FROM deliveries d
        INNER JOIN sales_orders so ON d.so_id = so.so_id
        INNER JOIN customers c ON d.customer_id = c.customer_id
        LEFT JOIN trip_tickets tt ON d.trip_id = tt.trip_id
        LEFT JOIN drivers dr ON d.driver_id = dr.driver_id
        WHERE 1=1
    ";
    
    // Add branch filter
    if ($delivery_branch_column_exists && !$view_all_branches && $branch_id > 0) {
        $all_deliveries_query .= " AND d.branch_id = $branch_id";
    }
    
    // Add driver filter for delivery role
    if ($user_role == 'delivery' && $driver_id > 0) {
        $all_deliveries_query .= " AND d.driver_id = $driver_id";
    }
    
    $all_deliveries_query .= " ORDER BY d.delivery_id DESC LIMIT 50";
    
    $all_result = $conn->query($all_deliveries_query);
    $all_deliveries = $all_result ? $all_result->fetch_all(MYSQLI_ASSOC) : [];
    
    // Now get only the ones that can be rejected (pending, in-transit, partial)
    $pending_orders = array_filter($all_deliveries, function($delivery) {
        return in_array($delivery['delivery_status'], ['pending', 'in-transit', 'partial']);
    });
    
    // Get recent rejected deliveries
    $recent_rejections = array_filter($all_deliveries, function($delivery) {
        return $delivery['delivery_status'] == 'rejected';
    });
    
    // Sort recent rejections by date (most recent first)
    usort($recent_rejections, function($a, $b) {
        return strtotime($b['delivery_date'] ?? '1970-01-01') - strtotime($a['delivery_date'] ?? '1970-01-01');
    });
    
    // Limit to 20
    $recent_rejections = array_slice($recent_rejections, 0, 20);
    
} catch (Exception $e) {
    error_log("Database error in rejecteddelivery.php: " . $e->getMessage());
    $all_deliveries = [];
    $pending_orders = [];
    $recent_rejections = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rejected Delivery Advice - Delivery Management</title>
    <link rel="icon" type="image/png" href="../Pictures/favicon-96x96.png" sizes="96x96" />
    <link rel="icon" type="image/svg+xml" href="../Pictures/favicon.svg" />
    <link rel="shortcut icon" href="../Pictures/favicon.ico" />
    <link rel="apple-touch-icon" sizes="180x180" href="../Pictures/apple-touch-icon.png" />
    <link rel="manifest" href="../Pictures/site.webmanifest" />
    <link rel="stylesheet" href="../css/delivery.css">
    <link rel="stylesheet" href="../css/rejecteddelivery.css">
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
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
        
        /* Alert for missing columns */
        .alert-warning {
            background-color: #fff3cd;
            border-color: #ffecb5;
            color: #856404;
        }
        
        /* Mobile responsive adjustments */
        @media (max-width: 768px) {
            .stat-card {
                padding: 12px;
                min-height: 85px;
                margin-bottom: 8px;
            }
            
            .stat-icon {
                font-size: 2rem;
                margin-right: 12px;
            }
            
            .stat-value {
                font-size: 1.5rem;
            }
            
            .stat-label {
                font-size: 0.8rem;
            }
            
            .col-md-3, .col-md-4, .col-md-6 {
                width: 50%;
                padding-left: 8px;
                padding-right: 8px;
            }
            
            .row.g-3 {
                margin-left: -8px;
                margin-right: -8px;
            }
            
            .mb-3 {
                margin-bottom: 8px !important;
            }
        }
        
        @media (max-width: 576px) {
            .stat-card {
                min-height: 80px;
                padding: 10px;
            }
            
            .stat-icon {
                font-size: 1.8rem;
                margin-right: 10px;
            }
            
            .stat-value {
                font-size: 1.3rem;
            }
            
            .stat-label {
                font-size: 0.75rem;
            }
            
            .col-md-3, .col-md-4, .col-md-6 {
                width: 50%;
                padding-left: 6px;
                padding-right: 6px;
            }
            
            .row.g-3 {
                margin-left: -6px;
                margin-right: -6px;
            }
        }
        
        /* Driver info card */
        .driver-info-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 20px;
        }
        
        .driver-info-card h5 {
            color: white;
            border-bottom: 1px solid rgba(255,255,255,0.3);
            padding-bottom: 10px;
        }
        
        .driver-info-card .info-label {
            color: rgba(255,255,255,0.8);
            font-size: 0.9rem;
        }
        
        .driver-info-card .info-value {
            color: white;
            font-weight: 600;
        }
        
        /* Debug info */
        .debug-info {
            background-color: #f8f9fa;
            border-left: 4px solid #0d6efd;
            padding: 10px 15px;
            margin-bottom: 20px;
            border-radius: 4px;
            font-size: 12px;
        }
        
        /* Order selection */
        .order-info {
            font-size: 12px;
            color: #6c757d;
            margin-top: 5px;
        }
        
        .customer-info-card {
            background-color: #f8f9fa;
            border-left: 4px solid #0d6efd;
            padding: 15px;
            border-radius: 8px;
            margin-top: 15px;
        }
        
        .customer-info-card h6 {
            color: #0d6efd;
            margin-bottom: 10px;
        }
        
        .info-row {
            display: flex;
            margin-bottom: 8px;
        }
        
        .info-label {
            font-weight: 600;
            width: 120px;
            color: #495057;
        }
        
        .info-value {
            color: #212529;
        }
        
        .status-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 500;
        }
        
        .status-pending { background-color: #ffc107; color: #000; }
        .status-in-transit { background-color: #0d6efd; color: #fff; }
        .status-partial { background-color: #0dcaf0; color: #000; }
        .status-delivered { background-color: #198754; color: #fff; }
        .status-rejected { background-color: #dc3545; color: #fff; }
        
        /* Photo view button styles */
.btn-outline-primary {
    border: 1px solid var(--primary-color);
    color: var(--primary-color);
    background: transparent;
    padding: 0.2rem 0.5rem;
    font-size: 0.75rem;
    border-radius: 4px;
    transition: all 0.2s ease;
}

.btn-outline-primary:hover {
    background: var(--primary-color);
    color: white;
}

.btn-link {
    text-decoration: none;
    font-weight: 500;
}

.btn-link:hover {
    text-decoration: underline;
}

/* Table adjustments */
.table td {
    vertical-align: middle;
}
        /* Modal image */
        .modal-image {
            max-width: 100%;
            max-height: 400px;
            border-radius: 8px;
        }

        /* Mobile Profile Modal Styles */
        .user-avatar-large {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            background: linear-gradient(135deg, #047857, #44D34E);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2.5rem;
            font-weight: bold;
            margin: 0 auto;
            border: 4px solid #d1fae5;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        #profileModal .modal-content {
            border: none;
            border-radius: 20px;
            overflow: hidden;
        }

        #profileModal .modal-header {
            background: linear-gradient(135deg, #047857, #44D34E);
            color: white;
            border-bottom: none;
            padding: 1.5rem;
        }

        #profileModal .modal-header .modal-title {
            color: white;
            font-weight: 600;
        }

        #profileModal .modal-header .btn-close {
            filter: brightness(0) invert(1);
            opacity: 0.9;
        }

        #profileModal .modal-header .btn-close:hover {
            opacity: 1;
            transform: rotate(90deg);
        }

        #profileModal .modal-body {
            padding: 2rem;
            background: linear-gradient(135deg, #f9fefc 0%, #f0fdf4 100%);
        }

        #profileModal .branch-info {
            background: #d1fae5;
            color: #047857;
            padding: 0.5rem 1rem;
            border-radius: 50px;
            display: inline-block;
            font-weight: 500;
        }

        #profileModal .btn-danger {
            background: linear-gradient(135deg, #dc3545, #f87171);
            border: none;
            padding: 1rem;
            border-radius: 50px;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        #profileModal .btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(220, 53, 69, 0.3);
        }

        /* Mobile Logout Button in Bottom Nav */
        .mobile-nav .nav-link.logout-btn {
            color: #dc3545;
        }

        .mobile-nav .nav-link.logout-btn i {
            color: #dc3545;
        }

        .mobile-nav .nav-link.logout-btn.active,
        .mobile-nav .nav-link.logout-btn:hover {
            background: rgba(220, 53, 69, 0.1);
            color: #dc3545;
        }

        .mobile-nav .nav-link.logout-btn.active i,
        .mobile-nav .nav-link.logout-btn:hover i {
            color: #dc3545;
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
                        <i class="bi bi-list" id="toggleIcon"></i>
                    </button>
                    <img src="../Pictures/amgc3DLogo.png" alt="Logo" class="logo-icon"> 
                    <span class="nav-text">Delivery</span>
                </h3>
            </div>
            
           <div class="sidebar-menu">
                <ul class="nav flex-column">
                     <li class="nav-item">
                        <a class="nav-link" href="fordelivery.php">
                            <i class="bi bi-truck"></i>
                            <span class="nav-text">For Delivery</span>
                        </a>
                    </li>

                    <li class="nav-item">
                        <a class="nav-link" href="trip_tickets.php">
                            <i class="bi bi-ticket"></i>
                            <span class="nav-text">Trip Tickets</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="rejecteddelivery.php">
                            <i class="bi bi-exclamation-circle"></i>
                            <span class="nav-text">Rejected Delivery</span>
                        </a>
                    </li>
                </ul>
            </div>
            <hr class="sidebar-divider">
            <!-- User Profile Section at the bottom of sidebar -->
            <div class="sidebar-footer">
                <div class="user-profile-sidebar">
                    <div class="user-avatar-sidebar"><?php echo $user_initials; ?></div>
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

        <!-- Main Content Area -->
        <div class="main-content">
            <!-- Header Section with User Info and Logout -->
            <div class="navbar-top">
                <button class="mobile-toggle-btn" id="mobileToggleBtn">
                    <i class="bi bi-list"></i>
                </button>
                <div class="page-title">
                    <h2>Rejected Delivery Advice</h2>
                    <p>Report and document rejected deliveries</p>
                </div>
            </div>

            <!-- Column Missing Alerts -->
            <?php if (!$rejection_photo_column_exists || !$rejection_reason_column_exists): ?>
                <div class="alert alert-warning alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-triangle"></i> 
                    <strong>Database columns missing.</strong> Please run this SQL to add the required columns:
                    <br><br>
                    <?php if (!$rejection_photo_column_exists): ?>
                        <code>ALTER TABLE deliveries ADD COLUMN rejection_photo VARCHAR(255) NULL AFTER remarks;</code><br>
                    <?php endif; ?>
                    <?php if (!$rejection_reason_column_exists): ?>
                        <code>ALTER TABLE deliveries ADD COLUMN rejection_reason TEXT NULL AFTER rejection_photo;</code><br>
                    <?php endif; ?>
                    <br>
                    <button type="button" class="btn btn-sm btn-warning" onclick="copySQL()">
                        <i class="bi bi-files"></i> Copy SQL
                    </button>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Branch Info Alerts -->
            <?php if (!$delivery_branch_column_exists): ?>
                <div class="alert alert-info alert-dismissible fade show" role="alert">
                    <i class="bi bi-info-circle"></i> 
                    <strong>Branch filtering for deliveries not yet set up.</strong> Please run this SQL in phpMyAdmin to enable branch-specific delivery data:
                    <br><br>
                    <code>ALTER TABLE deliveries ADD COLUMN branch_id INT NULL;</code>
                    <br>
                    <code>ALTER TABLE deliveries ADD FOREIGN KEY (branch_id) REFERENCES branches(branch_id);</code>
                    <br><br>
                    <button type="button" class="btn btn-sm btn-primary" onclick="copySQL('deliveries')">
                        <i class="bi bi-files"></i> Copy SQL
                    </button>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- No Orders Warning -->
            <?php if (empty($pending_orders)): ?>
                <div class="alert alert-warning">
                    <i class="bi bi-exclamation-triangle me-2"></i> 
                    <strong>No active deliveries found.</strong> You can only report rejections for deliveries that are in progress.
                    <?php if ($user_role == 'delivery'): ?>
                        <br><small>You don't have any active deliveries assigned to you. Please check with your supervisor.</small>
                    <?php elseif ($delivery_branch_column_exists && !$view_all_branches): ?>
                        <br><small>No pending deliveries for your branch.</small>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <!-- Success/Error Messages -->
            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="bi bi-check-circle me-2"></i><?php echo $_SESSION['success_message']; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php unset($_SESSION['success_message']); ?>
            <?php endif; ?>

            <?php if (isset($_SESSION['error_message'])): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-triangle me-2"></i><?php echo $_SESSION['error_message']; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php unset($_SESSION['error_message']); ?>
            <?php endif; ?>

            <!-- Rejected Delivery Form -->
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="bi bi-pencil-square me-2"></i>Report Rejected Delivery</h5>
                </div>
                <div class="card-body">
                    <form id="rejectedDeliveryForm" action="submit_rejected_delivery.php" method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="branch_id" value="<?php echo $branch_id; ?>">
                        <input type="hidden" name="driver_id" value="<?php echo $driver_id; ?>">
                        
                        <!-- Order Information Section -->
                        <h6 class="mb-3"><i class="bi bi-box-seam me-2"></i>Order Information</h6>
                        <div class="row">
                            <div class="col-md-8 mb-3">
                                <label class="form-label">Select Delivery Order <span class="text-danger">*</span></label>
                                <select class="form-select" id="deliveryOrderId" name="delivery_id" required onchange="loadCustomerInfo()" <?php echo empty($pending_orders) ? 'disabled' : ''; ?>>
                                    <option value="">-- Select Delivery --</option>
                                    <?php foreach ($pending_orders as $order): 
                                        $status_class = '';
                                        $status_text = ucfirst($order['delivery_status']);
                                        switch($order['delivery_status']) {
                                            case 'pending': $status_class = 'status-pending'; break;
                                            case 'in-transit': $status_class = 'status-in-transit'; break;
                                            case 'partial': $status_class = 'status-partial'; break;
                                        }
                                    ?>
                                    <option value="<?php echo $order['delivery_id']; ?>" 
                                            data-so-id="<?php echo $order['so_id']; ?>"
                                            data-so-number="<?php echo htmlspecialchars($order['so_number']); ?>"
                                            data-customer-id="<?php echo $order['customer_id']; ?>"
                                            data-customer-name="<?php echo htmlspecialchars($order['customer_name']); ?>"
                                            data-contact-person="<?php echo htmlspecialchars($order['contact_person'] ?? ''); ?>"
                                            data-phone="<?php echo htmlspecialchars($order['phone_number'] ?? ''); ?>"
                                            data-address="<?php echo htmlspecialchars($order['address'] ?? ''); ?>"
                                            data-city="<?php echo htmlspecialchars($order['city'] ?? ''); ?>"
                                            data-full-address="<?php echo htmlspecialchars($order['full_address'] ?? ''); ?>"
                                            data-trip-number="<?php echo htmlspecialchars($order['trip_number'] ?? ''); ?>"
                                            data-stop="<?php echo $order['stop_sequence'] ?? ''; ?>"
                                            data-driver="<?php echo htmlspecialchars($order['driver_name'] ?? ''); ?>">
                                        [<?php echo $order['so_number']; ?>] <?php echo htmlspecialchars($order['customer_name']); ?> 
                                        (Stop #<?php echo $order['stop_sequence'] ?? 'N/A'; ?>) 
                                        - <span class="<?php echo $status_class; ?>"><?php echo $status_text; ?></span>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="hidden" id="soId" name="so_id">
                                <input type="hidden" id="customerId" name="customer_id">
                                <small class="text-muted">Select a delivery that is currently in progress (Pending, In Transit, or Partial)</small>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Rejection Date <span class="text-danger">*</span></label>
                                <input type="datetime-local" class="form-control" id="rejectionDate" name="rejection_date" required 
                                       value="<?php echo date('Y-m-d\TH:i'); ?>">
                            </div>
                        </div>

                        <!-- Customer Information Card (Auto-filled) -->
<div class="customer-info-card" id="customerInfoCard" style="display: none;">
    <h6><i class="bi bi-person-check me-2"></i>Customer Information</h6>
    <div class="row">
        <div class="col-md-6">
            <div class="info-row">
                <span class="info-label">Customer:</span>
                <span class="info-value" id="displayCustomerName"></span>
            </div>
            <div class="info-row">
                <span class="info-label">Contact:</span>
                <span class="info-value" id="displayContactPerson"></span>
            </div>
            <div class="info-row">
                <span class="info-label">Phone:</span>
                <span class="info-value" id="displayPhoneNumber"></span>
            </div>
        </div>
        <div class="col-md-6">
            <div class="info-row">
                <span class="info-label">Address:</span>
                <span class="info-value" id="displayAddress"></span>
            </div>
            <div class="info-row">
                <span class="info-label">Trip #:</span>
                <span class="info-value" id="displayTripNumber"></span>
            </div>
            <div class="info-row">
                <span class="info-label">Stop #:</span>
                <span class="info-value" id="displayStop"></span>
            </div>
        </div>
    </div>
</div>

                        <hr>

                        <!-- Rejection Reason Section -->
                        <h6 class="mb-3"><i class="bi bi-exclamation-diamond me-2"></i>Rejection Reason</h6>
                        <div class="mb-3">
                            <label class="form-label">Reason for Rejection <span class="text-danger">*</span></label>
                            <select class="form-select" id="rejectionReason" name="rejection_reason" required onchange="handleReasonChange()">
                                <option value="">-- Select Reason --</option>
                                <option value="Customer Not Available">Customer Not Available</option>
                                <option value="Address Not Found">Address Not Found</option>
                                <option value="Customer Refused">Customer Refused</option>
                                <option value="Wrong Address">Wrong Address</option>
                                <option value="Damaged Package">Damaged Package</option>
                                <option value="Incomplete Items">Incomplete Items</option>
                                <option value="Wrong Items">Wrong Items</option>
                                <option value="Security Concern">Security Concern</option>
                                <option value="Other">Other (Please Specify)</option>
                            </select>
                        </div>

                        <div class="mb-3" id="otherReasonDiv" style="display: none;">
                            <label class="form-label">Please Specify <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="otherReason" name="other_reason" placeholder="Specify the rejection reason">
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Detailed Description <span class="text-danger">*</span></label>
                            <textarea class="form-control" id="rejectionDescription" name="description" required rows="3" placeholder="Provide detailed information about why the delivery was rejected..."></textarea>
                        </div>

                        <hr>

                        <!-- Resolution Section -->
                        <h6 class="mb-3"><i class="bi bi-arrow-clockwise me-2"></i>Resolution Actions</h6>
                        <div class="mb-3">
                            <label class="form-label">Proposed Action <span class="text-danger">*</span></label>
                            <select class="form-select" id="proposedAction" name="proposed_action" required>
                                <option value="">-- Select Action --</option>
                                <option value="Return to Warehouse">Return to Warehouse</option>
                                <option value="Retry Delivery">Retry Delivery</option>
                                <option value="Contact Customer">Contact Customer for Arrangement</option>
                                <option value="Hold for Pickup">Hold for Customer Pickup</option>
                                <option value="Cancel Order">Cancel Order</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Scheduled Retry Date (if applicable)</label>
                            <input type="date" class="form-control" id="retryDate" name="retry_date" min="<?php echo date('Y-m-d'); ?>">
                        </div>

                        <hr>

                        <!-- Photo Documentation Section -->
                        <h6 class="mb-3"><i class="bi bi-camera me-2"></i>Photo Documentation</h6>
                        <div class="mb-3">
                            <label class="form-label">Upload Photo of Rejected Package/Location</label>
                            <input type="file" class="form-control" id="rejectionPhoto" name="rejection_photo" accept="image/*" capture="environment">
                            <small class="text-muted">Please take a photo showing the package and/or the delivery location</small>
                        </div>

                        <hr>

                        <!-- Additional Notes -->
                        <div class="mb-3">
                            <label class="form-label">Additional Notes</label>
                            <textarea class="form-control" id="additionalNotes" name="additional_notes" rows="2" placeholder="Any additional notes or observations..."></textarea>
                        </div>

                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" id="confirmCheck" name="confirm" required>
                            <label class="form-check-label" for="confirmCheck">
                                I confirm that all information provided is accurate and true to the best of my knowledge
                            </label>
                        </div>

                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-danger" <?php echo empty($pending_orders) ? 'disabled' : ''; ?>>
                                <i class="bi bi-exclamation-triangle me-2"></i>Submit Rejection Report
                            </button>
                            <button type="button" class="btn btn-secondary" onclick="resetForm()">
                                <i class="bi bi-arrow-counterclockwise me-2"></i>Clear Form
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            
<!-- DESKTOP DECK VIEW (hidden on mobile) -->
<div class="d-none d-md-block">
    <div class="row g-3 p-3">
        <?php foreach ($recent_rejections as $rejection): ?>
        <div class="col-md-6 col-lg-4">
            <div class="rejection-deck-card" onclick="viewDetails(<?php echo $rejection['delivery_id']; ?>)">
                <div class="deck-card-header">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <span class="badge bg-danger">Rejected</span>
                            <span class="badge bg-secondary ms-1">#<?php echo $rejection['stop_sequence'] ?? 'N/A'; ?></span>
                        </div>
                        <small class="text-muted">
                            <i class="bi bi-calendar3"></i>
                            <?php echo !empty($rejection['delivery_date']) ? date('M d, Y', strtotime($rejection['delivery_date'])) : 'N/A'; ?>
                        </small>
                    </div>
                </div>
                
                <div class="deck-card-body">
                    <div class="customer-info-mini mb-2">
                        <h6 class="mb-1"><?php echo htmlspecialchars($rejection['customer_name']); ?></h6>
                        <div class="small text-muted">
                            <i class="bi bi-receipt"></i> <?php echo htmlspecialchars($rejection['so_number']); ?>
                        </div>
                    </div>
                    
                    <div class="d-flex justify-content-between align-items-center mt-3">
                        <div>
                            <?php if (!empty($rejection['rejection_photo'])): ?>
                                <span class="text-success small">
                                    <i class="bi bi-check-circle-fill"></i> Photo
                                </span>
                            <?php else: ?>
                                <span class="text-muted small">
                                    <i class="bi bi-x-circle"></i> No photo
                                </span>
                            <?php endif; ?>
                        </div>
                        <span class="text-muted small"><i class="bi bi-eye"></i> Click to view</span>
                    </div>
                </div>
                
                <div class="deck-card-footer">
                    <small class="text-muted">
                        <i class="bi bi-truck"></i> <?php echo htmlspecialchars($rejection['driver_name'] ?? 'N/A'); ?>
                        <?php if (!empty($rejection['trip_number'])): ?>
                            | <i class="bi bi-ticket"></i> <?php echo htmlspecialchars($rejection['trip_number']); ?>
                        <?php endif; ?>
                    </small>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<!-- MOBILE CARD VIEW (visible only on mobile) -->
<div class="d-block d-md-none p-3">
    <?php foreach ($recent_rejections as $rejection): ?>
    <div class="mobile-rejection-card mb-3" onclick="viewDetails(<?php echo $rejection['delivery_id']; ?>)">
        <div class="card border-0 shadow-sm">
            <div class="card-body p-3">
                <!-- Header -->
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <div>
                        <span class="badge bg-danger">Rejected</span>
                        <span class="badge bg-secondary ms-1">#<?php echo $rejection['stop_sequence'] ?? 'N/A'; ?></span>
                    </div>
                    <small class="text-muted">
                        <i class="bi bi-calendar3"></i>
                        <?php echo !empty($rejection['delivery_date']) ? date('M d, Y', strtotime($rejection['delivery_date'])) : 'N/A'; ?>
                    </small>
                </div>
                
                <!-- Customer Info -->
                <h6 class="fw-bold mb-1"><?php echo htmlspecialchars($rejection['customer_name']); ?></h6>
                <div class="small text-muted mb-2">
                    <i class="bi bi-receipt"></i> <?php echo htmlspecialchars($rejection['so_number']); ?>
                </div>
                
                <!-- Footer -->
                <div class="d-flex justify-content-between align-items-center mt-2">
                    <div>
                        <?php if (!empty($rejection['rejection_photo'])): ?>
                            <span class="text-success small">
                                <i class="bi bi-check-circle-fill"></i> Photo
                            </span>
                        <?php else: ?>
                            <span class="text-muted small">
                                <i class="bi bi-x-circle"></i> No photo
                            </span>
                        <?php endif; ?>
                    </div>
                    <small class="text-muted"><i class="bi bi-eye"></i> Tap to view</small>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
        </div>
    </div>

    <!-- Mobile Bottom Navigation -->
    <div class="mobile-nav" id="mobileNav">
        <ul class="nav">
            <li class="nav-item">
                <a class="nav-link" href="fordelivery.php">
                    <i class="bi bi-truck"></i>
                    <span>Delivery</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="trip_tickets.php">
                    <i class="bi bi-ticket-perforated"></i>
                    <span>Tickets</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link active" href="rejecteddelivery.php">
                    <i class="bi bi-exclamation-circle"></i>
                    <span>Rejected</span>
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
                        <span class="badge bg-success"><?php echo ucfirst($user_role); ?></span>
                    </p>
                    
                    <!-- Branch Info (if applicable) -->
                    <?php if (!$view_all_branches && $branch_id > 0): ?>
                    <div class="branch-info mb-3">
                        <i class="bi bi-building me-1"></i>
                        <span><?php echo htmlspecialchars($branch_name); ?></span>
                    </div>
                    <?php endif; ?>
                    
                    <!-- User ID -->
                    <div class="user-id text-muted small mb-4">
                        <i class="bi bi-hash"></i> User ID: <?php echo $user_id; ?>
                    </div>
                    
                    <!-- Logout Button -->
                    <button class="btn btn-danger btn-lg w-100" onclick="confirmLogout()">
                        <i class="bi bi-box-arrow-right me-2"></i>Logout
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Photo View Modal -->
    <div class="modal fade" id="photoModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Rejection Photo</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body text-center">
                    <img src="" id="modalPhoto" class="modal-image" alt="Rejection photo">
                    <p id="modalReason" class="mt-3 text-muted"></p>
                </div>
            </div>
        </div>
    </div>

    <!-- Details Modal -->
    <div class="modal fade" id="detailsModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title">Rejection Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="detailsModalBody">
                    Loading...
                </div>
            </div>
        </div>
    </div>

    <!-- JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
   <script>
        // Branch context variables
        const branchId = <?php echo $branch_id; ?>;
        const viewAllBranches = <?php echo $view_all_branches ? 'true' : 'false'; ?>;
        const deliveryBranchColumnExists = <?php echo $delivery_branch_column_exists ? 'true' : 'false'; ?>;
        const soBranchColumnExists = <?php echo $so_branch_column_exists ? 'true' : 'false'; ?>;
        const userRole = '<?php echo $user_role; ?>';
        const driverId = <?php echo $driver_id ?: 0; ?>;

        // ================= SIDEBAR FUNCTIONS =================
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const isMobile = window.innerWidth <= 992;
            
            if (isMobile) {
                sidebar.classList.toggle('active');
                
                if (!document.querySelector('.sidebar-overlay')) {
                    const overlay = document.createElement('div');
                    overlay.className = 'sidebar-overlay';
                    document.body.appendChild(overlay);
                    
                    overlay.addEventListener('click', () => {
                        closeMobileSidebar();
                    });
                    
                    setTimeout(() => {
                        overlay.classList.add('active');
                    }, 10);
                } else {
                    const overlay = document.querySelector('.sidebar-overlay');
                    overlay.classList.toggle('active');
                    if (!sidebar.classList.contains('active')) {
                        setTimeout(() => {
                            if (overlay && overlay.parentNode) {
                                overlay.remove();
                            }
                        }, 300);
                    }
                }
            } else {
                sidebar.classList.toggle('collapsed');
                localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
                
                document.querySelectorAll('.nav-text').forEach(text => {
                    text.style.display = sidebar.classList.contains('collapsed') ? 'none' : 'inline-block';
                });
                
                const mainContent = document.querySelector('.main-content');
                if (mainContent) {
                    mainContent.style.marginLeft = sidebar.classList.contains('collapsed') ? '80px' : '250px';
                }
            }
        }

        function closeMobileSidebar() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.querySelector('.sidebar-overlay');
            
            sidebar.classList.remove('active');
            
            if (overlay) {
                overlay.classList.remove('active');
                setTimeout(() => {
                    if (overlay.parentNode) {
                        overlay.remove();
                    }
                }, 300);
            }
        }

        function initializeSidebar() {
            const sidebar = document.getElementById('sidebar');
            
            if (window.innerWidth > 992) {
                const savedCollapsed = localStorage.getItem('sidebarCollapsed');
                if (savedCollapsed === 'true') {
                    sidebar.classList.add('collapsed');
                    document.querySelectorAll('.nav-text').forEach(text => {
                        text.style.display = 'none';
                    });
                    
                    const mainContent = document.querySelector('.main-content');
                    if (mainContent) {
                        mainContent.style.marginLeft = '80px';
                    }
                } else {
                    sidebar.classList.remove('collapsed');
                    document.querySelectorAll('.nav-text').forEach(text => {
                        text.style.display = 'inline-block';
                    });
                    
                    const mainContent = document.querySelector('.main-content');
                    if (mainContent) {
                        mainContent.style.marginLeft = '250px';
                    }
                }
            } else {
                sidebar.classList.remove('active');
                sidebar.classList.remove('collapsed');
                document.querySelectorAll('.nav-text').forEach(text => {
                    text.style.display = 'inline-block';
                });
                
                const mainContent = document.querySelector('.main-content');
                if (mainContent) {
                    mainContent.style.marginLeft = '0';
                }
            }
        }

        function handleSidebarResize() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.querySelector('.sidebar-overlay');
            
            if (window.innerWidth > 992) {
                if (overlay) {
                    overlay.remove();
                }
                sidebar.classList.remove('active');
                
                const savedCollapsed = localStorage.getItem('sidebarCollapsed');
                if (savedCollapsed === 'true') {
                    sidebar.classList.add('collapsed');
                    document.querySelectorAll('.nav-text').forEach(text => {
                        text.style.display = 'none';
                    });
                    
                    const mainContent = document.querySelector('.main-content');
                    if (mainContent) {
                        mainContent.style.marginLeft = '80px';
                    }
                } else {
                    sidebar.classList.remove('collapsed');
                    document.querySelectorAll('.nav-text').forEach(text => {
                        text.style.display = 'inline-block';
                    });
                    
                    const mainContent = document.querySelector('.main-content');
                    if (mainContent) {
                        mainContent.style.marginLeft = '250px';
                    }
                }
            } else {
                sidebar.classList.remove('collapsed');
                document.querySelectorAll('.nav-text').forEach(text => {
                    text.style.display = 'inline-block';
                });
                
                const mainContent = document.querySelector('.main-content');
                if (mainContent) {
                    mainContent.style.marginLeft = '0';
                }
            }
        }
        // ================= END SIDEBAR FUNCTIONS =================

        // ================= MOBILE NAVIGATION FUNCTIONS =================
        function initMobileNav() {
            const mobileNav = document.getElementById('mobileNav');
            const isMobile = window.innerWidth <= 992;
            
            if (isMobile) {
                mobileNav.style.display = 'block';
                
                // Set active state based on current page (excluding logout)
                const currentPage = window.location.pathname.split('/').pop();
                const navLinks = mobileNav.querySelectorAll('.nav-link:not(.logout-btn)');
                
                navLinks.forEach(link => {
                    link.classList.remove('active');
                    const href = link.getAttribute('href');
                    if (currentPage === href) {
                        link.classList.add('active');
                    }
                });
            } else {
                mobileNav.style.display = 'none';
            }
        }

        // ================= PROFILE/LOGOUT FUNCTIONS =================
        function showProfileModal() {
            const profileModal = new bootstrap.Modal(document.getElementById('profileModal'));
            profileModal.show();
        }

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
                confirmButtonColor: '#dc3545',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Yes, logout'
            }).then((result) => {
                if (result.isConfirmed) {
                    localStorage.removeItem('sidebarCollapsed');
                    window.location.href = '../logout.php';
                }
            });
        }

        // Logout function
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

        // Load customer information when order is selected
        function loadCustomerInfo() {
            const select = document.getElementById('deliveryOrderId');
            const selectedOption = select.options[select.selectedIndex];
            
            if (selectedOption && selectedOption.value) {
                // Get data from selected option
                const soId = selectedOption.dataset.soId;
                const soNumber = selectedOption.dataset.soNumber;
                const customerId = selectedOption.dataset.customerId;
                const customerName = selectedOption.dataset.customerName;
                const contactPerson = selectedOption.dataset.contactPerson;
                const phoneNumber = selectedOption.dataset.phone;
                const address = selectedOption.dataset.address;
                const city = selectedOption.dataset.city;
                const fullAddress = selectedOption.dataset.fullAddress || (address + ', ' + city);
                const tripNumber = selectedOption.dataset.tripNumber;
                const stop = selectedOption.dataset.stop;
                
                // Set hidden inputs
                document.getElementById('soId').value = soId;
                document.getElementById('customerId').value = customerId;
                
                // Display customer info
                document.getElementById('displayCustomerName').textContent = customerName || 'N/A';
                document.getElementById('displayContactPerson').textContent = contactPerson || 'N/A';
                document.getElementById('displayPhoneNumber').textContent = phoneNumber || 'N/A';
                
                // Display address
                if (fullAddress && fullAddress !== ', ' && fullAddress !== '') {
                    document.getElementById('displayAddress').textContent = fullAddress;
                } else if (address && city) {
                    document.getElementById('displayAddress').textContent = address + ', ' + city;
                } else {
                    document.getElementById('displayAddress').textContent = 'N/A';
                }
                
                document.getElementById('displayTripNumber').textContent = tripNumber || 'N/A';
                document.getElementById('displayStop').textContent = stop || 'N/A';
                
                // Show customer info card
                document.getElementById('customerInfoCard').style.display = 'block';
                
                // Auto-fill description with basic info
                const description = document.getElementById('rejectionDescription');
                if (!description.value) {
                    description.value = `Delivery rejected for order ${soNumber} to ${customerName}. `;
                }
                
            } else {
                // Hide customer info card
                document.getElementById('customerInfoCard').style.display = 'none';
                document.getElementById('soId').value = '';
                document.getElementById('customerId').value = '';
            }
        }

        // Handle reason change
        function handleReasonChange() {
            const reason = document.getElementById('rejectionReason');
            const reasonValue = reason.value;
            const otherDiv = document.getElementById('otherReasonDiv');
            const otherInput = document.getElementById('otherReason');
            
            if (reasonValue === 'Other') {
                otherDiv.style.display = 'block';
                otherInput.required = true;
            } else {
                otherDiv.style.display = 'none';
                otherInput.required = false;
            }
            
            // Auto-fill description with selected reason
            const description = document.getElementById('rejectionDescription');
            const selectedOption = document.getElementById('deliveryOrderId').selectedOptions[0];
            
            if (selectedOption && selectedOption.value && reasonValue && reasonValue !== 'Other') {
                const customerName = selectedOption.dataset.customerName;
                const soNumber = selectedOption.dataset.soNumber;
                if (!description.value.includes(reasonValue)) {
                    if (description.value.trim() === `Delivery rejected for order ${soNumber} to ${customerName}. `) {
                        description.value += `Reason: ${reasonValue}. `;
                    }
                }
            }
        }

        // Reset form
        function resetForm() {
            // Reset selects
            document.getElementById('deliveryOrderId').value = '';
            document.getElementById('rejectionReason').value = '';
            document.getElementById('proposedAction').value = '';
            
            // Reset date to now
            const now = new Date();
            const year = now.getFullYear();
            const month = String(now.getMonth() + 1).padStart(2, '0');
            const day = String(now.getDate()).padStart(2, '0');
            const hours = String(now.getHours()).padStart(2, '0');
            const minutes = String(now.getMinutes()).padStart(2, '0');
            document.getElementById('rejectionDate').value = `${year}-${month}-${day}T${hours}:${minutes}`;
            
            // Reset other fields
            document.getElementById('otherReasonDiv').style.display = 'none';
            document.getElementById('otherReason').value = '';
            document.getElementById('rejectionDescription').value = '';
            document.getElementById('retryDate').value = '';
            document.getElementById('rejectionPhoto').value = '';
            document.getElementById('additionalNotes').value = '';
            document.getElementById('confirmCheck').checked = false;
            
            // Hide customer info
            document.getElementById('customerInfoCard').style.display = 'none';
            document.getElementById('soId').value = '';
            document.getElementById('customerId').value = '';
        }

        // View photo (for photoModal - old version, keep for compatibility)
        function viewPhoto(photoPath, reason) {
            const modal = new bootstrap.Modal(document.getElementById('photoModal'));
            document.getElementById('modalPhoto').src = '../uploads/rejections/' + photoPath;
            document.getElementById('modalReason').textContent = reason;
            modal.show();
        }

        // ================= UPDATED VIEW DETAILS FUNCTION - CLEAN & SIMPLE =================
        function viewDetails(deliveryId) {
            // Find the delivery data
            <?php
            // Create a JavaScript array of all rejections
            $rejections_json = json_encode($recent_rejections);
            echo "const allRejections = " . $rejections_json . ";\n";
            ?>
            
            const rejection = allRejections.find(r => r.delivery_id == deliveryId);
            
            if (rejection) {
                const hasPhoto = rejection.rejection_photo && rejection.rejection_photo !== '';
                const photoPath = hasPhoto ? '../uploads/rejections/' + rejection.rejection_photo.split('/').pop() : '';
                
                // Format date
                const deliveryDate = rejection.delivery_date ? 
                    new Date(rejection.delivery_date).toLocaleString('en-US', {
                        month: 'short',
                        day: 'numeric',
                        year: 'numeric',
                        hour: '2-digit',
                        minute: '2-digit'
                    }) : 'N/A';
                
                // Create photo section
                const photoSectionHtml = hasPhoto ? 
                    `<div class="photo-section">
                        <button class="photo-button" onclick="expandImage('${photoPath}')">
                            <i class="bi bi-image"></i>
                            View Photo
                        </button>
                    </div>` : 
                    `<div class="photo-section">
                        <div class="no-photo">
                            <i class="bi bi-image"></i>
                            No photo
                        </div>
                    </div>`;
                
                const modalBody = document.getElementById('detailsModalBody');
                modalBody.innerHTML = `
                    ${photoSectionHtml}
                    
                    <div class="info-grid">
                        <div class="info-item">
                            <span class="info-label">Order #</span>
                            <span class="info-value">${rejection.so_number || 'N/A'}</span>
                        </div>
                        
                        <div class="info-item">
                            <span class="info-label">Date</span>
                            <span class="info-value">${deliveryDate}</span>
                        </div>
                        
                        <div class="info-item">
                            <span class="info-label">Customer</span>
                            <span class="info-value">${rejection.customer_name || 'N/A'}</span>
                        </div>
                        
                        <div class="info-item">
                            <span class="info-label">Stop #</span>
                            <span class="info-value"><span class="badge">#${rejection.stop_sequence || 'N/A'}</span></span>
                        </div>
                        
                        <div class="info-item">
                            <span class="info-label">Trip #</span>
                            <span class="info-value">${rejection.trip_number || 'N/A'}</span>
                        </div>
                        
                        <div class="info-item">
                            <span class="info-label">Driver</span>
                            <span class="info-value">${rejection.driver_name || 'N/A'}</span>
                        </div>
                    </div>
                    
                    <div class="divider"></div>
                    
                    <div class="reason-section">
                        <div class="info-label">Reason</div>
                        <div class="reason-text">${rejection.rejection_reason || rejection.remarks || 'N/A'}</div>
                    </div>
                `;
                
                // Update modal title
                document.querySelector('#detailsModal .modal-title').innerHTML = 'Rejection Details';
                    
                // Show modal
                const modal = new bootstrap.Modal(document.getElementById('detailsModal'));
                modal.show();
            }
        }

   // ================= SIMPLE RESPONSIVE IMAGE VIEWER - WALANG DESIGN =================
function expandImage(imageSrc) {
    // Close the current details modal first
    const detailsModal = bootstrap.Modal.getInstance(document.getElementById('detailsModal'));
    if (detailsModal) {
        detailsModal.hide();
    }
    
    setTimeout(() => {
        // Remove existing modal
        const existingModal = document.getElementById('imageExpandModal');
        if (existingModal) existingModal.remove();
        
        // Create simple modal - image lang, walang design
        const modalHtml = `
            <div class="modal fade" id="imageExpandModal" tabindex="-1">
                <div class="modal-dialog modal-dialog-centered modal-xl">
                    <div class="modal-content bg-transparent border-0">
                        <div class="modal-body text-center py-4" style="overflow: hidden;">
                            <img src="${imageSrc}" class="img-fluid" alt="Photo" style="max-height: 80vh; width: auto;">
                        </div>
                    </div>
                </div>
            </div>
        `;
        
        document.body.insertAdjacentHTML('beforeend', modalHtml);
        
        // Prevent body scroll
        document.body.style.overflow = 'hidden';
        
        // Show modal
        const modal = new bootstrap.Modal(document.getElementById('imageExpandModal'));
        modal.show();
        
        // Click anywhere to close
        document.getElementById('imageExpandModal').addEventListener('click', function() {
            modal.hide();
        });
        
        // Clean up
        document.getElementById('imageExpandModal').addEventListener('hidden.bs.modal', function() {
            this.remove();
            document.body.style.overflow = '';
        });
        
    }, 300);
}

        // Copy SQL for database setup
        function copySQL(table) {
            let sql = '';
            if (table === 'deliveries') {
                sql = "ALTER TABLE deliveries ADD COLUMN branch_id INT NULL;\nALTER TABLE deliveries ADD FOREIGN KEY (branch_id) REFERENCES branches(branch_id);";
            } else if (table === 'sales_orders') {
                sql = "ALTER TABLE sales_orders ADD COLUMN branch_id INT NULL;\nALTER TABLE sales_orders ADD FOREIGN KEY (branch_id) REFERENCES branches(branch_id);";
            } else {
                sql = "ALTER TABLE deliveries ADD COLUMN rejection_photo VARCHAR(255) NULL AFTER remarks;\nALTER TABLE deliveries ADD COLUMN rejection_reason TEXT NULL AFTER rejection_photo;";
            }
            
            navigator.clipboard.writeText(sql).then(() => {
                Swal.fire('Success', 'SQL copied to clipboard!', 'success');
            });
        }

        // ================= INITIALIZATION =================
        document.addEventListener('DOMContentLoaded', function() {
            console.log("Rejection Management page loaded - Fixed version");
            console.log("User Role:", userRole);
            console.log("Driver ID:", driverId);
            console.log("Branch ID:", branchId);
            
            // Initialize sidebar
            initializeSidebar();
            initMobileNav();
            
            // Setup mobile toggle button
            const mobileToggleBtn = document.getElementById('mobileToggleBtn');
            if (mobileToggleBtn) {
                mobileToggleBtn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    toggleSidebar();
                });
            }
            
            const desktopToggleBtn = document.getElementById('desktopToggleBtn');
            if (desktopToggleBtn) {
                desktopToggleBtn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    toggleSidebar();
                });
            }
            
            // Add click listeners to sidebar links to close on mobile
            document.querySelectorAll('.sidebar .nav-link').forEach(link => {
                link.addEventListener('click', function() {
                    if (window.innerWidth <= 992) {
                        closeMobileSidebar();
                    }
                });
            });
            
            // Close sidebar when clicking outside on mobile
            document.addEventListener('click', function(event) {
                const sidebar = document.getElementById('sidebar');
                const mobileBtn = document.getElementById('mobileToggleBtn');
                const overlay = document.querySelector('.sidebar-overlay');
                const isMobile = window.innerWidth <= 992;
                
                if (isMobile && sidebar && sidebar.classList.contains('active') && 
                    !sidebar.contains(event.target) && 
                    (!mobileBtn || !mobileBtn.contains(event.target)) &&
                    (!overlay || !overlay.contains(event.target))) {
                    closeMobileSidebar();
                }
            });

            // Add resize event listener
            window.addEventListener('resize', function() {
                handleSidebarResize();
                initMobileNav();
            });
            
            // Initialize retry date min attribute
            const retryDate = document.getElementById('retryDate');
            if (retryDate) {
                retryDate.min = '<?php echo date('Y-m-d'); ?>';
            }
            
            // Log the number of pending orders
            const pendingOrdersSelect = document.getElementById('deliveryOrderId');
            if (pendingOrdersSelect) {
                console.log("Pending orders in dropdown:", pendingOrdersSelect.options.length - 1);
            }
        });

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey && e.key === 'b' && window.innerWidth > 992) {
                e.preventDefault();
                toggleSidebar();
            }
            else if (e.key === 'Escape' && window.innerWidth <= 992) {
                closeMobileSidebar();
            }
            else if (e.key === 'Escape') {
                const profileModal = document.getElementById('profileModal');
                if (profileModal.classList.contains('show')) {
                    bootstrap.Modal.getInstance(profileModal).hide();
                }
                const photoModal = document.getElementById('photoModal');
                if (photoModal.classList.contains('show')) {
                    bootstrap.Modal.getInstance(photoModal).hide();
                }
                const detailsModal = document.getElementById('detailsModal');
                if (detailsModal.classList.contains('show')) {
                    bootstrap.Modal.getInstance(detailsModal).hide();
                }
            }
            else if (e.ctrlKey && e.key === 'r' && !e.target.matches('input, textarea, select')) {
                e.preventDefault();
                resetForm();
            }
        });
    </script>
</body>
</html>