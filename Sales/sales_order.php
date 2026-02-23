<?php
require_once '../config/database.php';
require_once '../config/session_handler.php';

// Protect page - only Sales role can access
requireLogin();
requireRole(['sales']);

// Get current user info and branch context
$user_id = $_SESSION['user_id'];
$user_name = isset($_SESSION['first_name']) ? $_SESSION['first_name'] . ' ' . $_SESSION['last_name'] : 'Sales User';
$user_role = isset($_SESSION['role']) ? $_SESSION['role'] : 'sales';
$branch_id = $_SESSION['branch_id'] ?? 0;
$view_all_branches = $_SESSION['view_all_branches'] ?? false;

// Get user ID for filtering
$user_id = getUserId();
$branch_id = getUserBranchId();

// Check if branch_id column exists in sales_orders table
$branch_column_exists = false;
$check_column = $conn->query("SHOW COLUMNS FROM sales_orders LIKE 'branch_id'");
if ($check_column && $check_column->num_rows > 0) {
    $branch_column_exists = true;
}

// Check if branch_id column exists in customers table
$customers_branch_column_exists = false;
$check_customers_column = $conn->query("SHOW COLUMNS FROM customers LIKE 'branch_id'");
if ($check_customers_column && $check_customers_column->num_rows > 0) {
    $customers_branch_column_exists = true;
}

// Detect AJAX request
$is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
           strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';

// Handle AJAX requests - return ONLY JSON
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'];
    
    if ($action === 'get_order_details' && isset($_POST['order_id'])) {
        $order_id = (int)$_POST['order_id'];
        
        try {
            // Verify order belongs to user's branch (if branch column exists and not admin)
            if ($branch_column_exists && !$view_all_branches) {
                $check_sql = "SELECT so_id FROM sales_orders WHERE so_id = ? AND branch_id = ?";
                $check_stmt = $conn->prepare($check_sql);
                if (!$check_stmt) {
                    throw new Exception("Database prepare error");
                }
                $check_stmt->bind_param('ii', $order_id, $branch_id);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                
                if ($check_result->num_rows === 0) {
                    echo json_encode(['success' => false, 'message' => 'Order not found or access denied']);
                    exit;
                }
            }
            
            // Get order details
            $sql = "SELECT 
                        so.so_id,
                        so.so_number,
                        so.order_date,
                        so.total_amount,
                        so.order_status,
                        so.branch_id,
                        c.customer_name,
                        c.email,
                        c.phone_number,
                        c.address,
                        u.first_name as created_by,
                        b.branch_name
                    FROM sales_orders so
                    LEFT JOIN customers c ON so.customer_id = c.customer_id
                    LEFT JOIN users u ON so.created_by = u.user_id
                    LEFT JOIN branches b ON so.branch_id = b.branch_id
                    WHERE so.so_id = ?";
            
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                throw new Exception("Database prepare error");
            }
            $stmt->bind_param('i', $order_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows === 0) {
                echo json_encode(['success' => false, 'message' => 'Order not found']);
                exit;
            }
            
            $order = $result->fetch_assoc();
            
            // Get order items
            $items_sql = "SELECT 
                            soi.so_item_id,
                            soi.so_id,
                            soi.item_id,
                            soi.quantity_ordered,
                            soi.quantity_delivered,
                            soi.unit_price,
                            soi.line_total,
                            i.item_name,
                            i.item_code,
                            i.unit_type
                         FROM sales_order_items soi
                         JOIN items i ON soi.item_id = i.item_id
                         WHERE soi.so_id = ?
                         ORDER BY soi.so_item_id";
            $items_stmt = $conn->prepare($items_sql);
            if (!$items_stmt) {
                throw new Exception("Database prepare error");
            }
            $items_stmt->bind_param('i', $order_id);
            $items_stmt->execute();
            $items_result = $items_stmt->get_result();
            $items = $items_result->fetch_all(MYSQLI_ASSOC);
            
            echo json_encode([
                'success' => true,
                'order' => $order,
                'items' => $items
            ]);
            
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
    
    // Invalid action
    echo json_encode(['success' => false, 'message' => 'Invalid action']);
    exit;
}

// If it's an AJAX request but no valid action, return error
if ($is_ajax) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

// Handle search and filters
$where_conditions = ["1=1"];
$params = [];
$param_types = "";

// Branch filter - only apply if branch column exists
if ($branch_column_exists) {
    if ($view_all_branches) {
        // Admin sees all branches - no filter needed
    } else {
        // Regular user sees only their branch
        $where_conditions[] = "so.branch_id = ?";
        $params[] = $branch_id;
        $param_types .= "i";
    }
}

// Status filter
if (isset($_GET['status']) && !empty($_GET['status']) && $_GET['status'] !== 'all') {
    $where_conditions[] = "so.order_status = ?";
    $params[] = $_GET['status'];
    $param_types .= "s";
}

// Date range filter
if (isset($_GET['start_date']) && !empty($_GET['start_date'])) {
    $where_conditions[] = "DATE(so.order_date) >= ?";
    $params[] = $_GET['start_date'];
    $param_types .= "s";
}

if (isset($_GET['end_date']) && !empty($_GET['end_date'])) {
    $where_conditions[] = "DATE(so.order_date) <= ?";
    $params[] = $_GET['end_date'];
    $param_types .= "s";
}

// Search by order number or customer name
if (isset($_GET['search']) && !empty($_GET['search'])) {
    $search = "%" . $_GET['search'] . "%";
    $where_conditions[] = "(so.so_number LIKE ? OR c.customer_name LIKE ?)";
    $params[] = $search;
    $params[] = $search;
    $param_types .= "ss";
}

// Build query with branch information
$sql = "SELECT 
            so.so_id,
            so.so_number,
            so.order_date,
            so.total_amount,
            so.order_status,
            so.branch_id,
            c.customer_name,
            b.branch_name,
            (SELECT COUNT(*) FROM sales_order_items WHERE so_id = so.so_id) as item_count
        FROM sales_orders so
        LEFT JOIN customers c ON so.customer_id = c.customer_id
        LEFT JOIN branches b ON so.branch_id = b.branch_id
        WHERE " . implode(" AND ", $where_conditions) . "
        ORDER BY so.order_date DESC";

// Prepare and execute
if (!empty($params)) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($param_types, ...$params);
} else {
    $stmt = $conn->prepare($sql);
}
$stmt->execute();
$result = $stmt->get_result();
$orders = $result->fetch_all(MYSQLI_ASSOC);

// Get order statistics - branch specific
if ($branch_column_exists && !$view_all_branches) {
    $stats_sql = "SELECT 
                    COUNT(*) as total_orders,
                    SUM(CASE WHEN order_status = 'pending' THEN 1 ELSE 0 END) as pending,
                    SUM(CASE WHEN order_status = 'processing' THEN 1 ELSE 0 END) as processing,
                    SUM(CASE WHEN order_status = 'shipped' THEN 1 ELSE 0 END) as shipped,
                    SUM(CASE WHEN order_status = 'delivered' THEN 1 ELSE 0 END) as delivered,
                    SUM(CASE WHEN order_status = 'cancelled' THEN 1 ELSE 0 END) as cancelled,
                    COALESCE(SUM(total_amount), 0) as total_revenue
                  FROM sales_orders 
                  WHERE branch_id = ?";
    $stats_stmt = $conn->prepare($stats_sql);
    $stats_stmt->bind_param('i', $branch_id);
} else {
    $stats_sql = "SELECT 
                    COUNT(*) as total_orders,
                    SUM(CASE WHEN order_status = 'pending' THEN 1 ELSE 0 END) as pending,
                    SUM(CASE WHEN order_status = 'processing' THEN 1 ELSE 0 END) as processing,
                    SUM(CASE WHEN order_status = 'shipped' THEN 1 ELSE 0 END) as shipped,
                    SUM(CASE WHEN order_status = 'delivered' THEN 1 ELSE 0 END) as delivered,
                    SUM(CASE WHEN order_status = 'cancelled' THEN 1 ELSE 0 END) as cancelled,
                    COALESCE(SUM(total_amount), 0) as total_revenue
                  FROM sales_orders";
    $stats_stmt = $conn->prepare($stats_sql);
}
$stats_stmt->execute();
$stats_result = $stats_stmt->get_result();
$stats = $stats_result->fetch_assoc();

// Get base64 encoded logo for printing
$logo_path = '../Pictures/amgc3DLogo.png';
$logo_base64 = '';
if (file_exists($logo_path)) {
    $image_data = file_get_contents($logo_path);
    $logo_base64 = 'data:image/png;base64,' . base64_encode($image_data);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sales Orders - Sales Dashboard</title>
    <link rel="icon" type="image/png" href="../Pictures/favicon-96x96.png" sizes="96x96" />
    <link rel="icon" type="image/svg+xml" href="../Pictures/favicon.svg" />
    <link rel="shortcut icon" href="../Pictures/favicon.ico" />
    <link rel="apple-touch-icon" sizes="180x180" href="../Pictures/apple-touch-icon.png" />
    <link rel="manifest" href="../Pictures/site.webmanifest" />
    <link rel="stylesheet" href="../css/sales.css">
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <!-- Google Fonts - Tenor Sans and Alice -->
    <link href="https://fonts.googleapis.com/css2?family=Tenor+Sans&family=Alice&display=swap" rel="stylesheet">
    <style>
        /* Brand Colors */
        :root {
            --green: #2E7D32;
            --green-haze: #1B5E20;
            --deep-sea: #0D4C14;
            --forest-green: #1B4D1F;
            --yellow: #FFC107;
            --white: #FFFFFF;
            --light-gray: #F5F5F5;
            --black: #212121;
        }

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
            
            .col-md-3 {
                width: 50%;
                padding-left: 8px;
                padding-right: 8px;
            }
        }
        
        @media (max-width: 576px) {
            .col-md-3 {
                width: 50%;
                padding-left: 6px;
                padding-right: 6px;
            }
        }

        /* Print Styles - With Brand Colors and Logo */
        @media print {
            @page {
                size: landscape;
                margin: 0.75in;
            }
            
            body {
                background-color: var(--white);
                font-family: 'Tenor Sans', sans-serif;
            }
            
            .no-print {
                display: none !important;
            }
            
            .print-container {
                padding: 20px;
                background: var(--white);
            }
            
            .print-header {
                display: flex;
                align-items: center;
                justify-content: space-between;
                margin-bottom: 30px;
                padding-bottom: 20px;
                border-bottom: 3px solid var(--deep-sea);
            }
            
            .logo-section {
                display: flex;
                align-items: center;
                gap: 15px;
            }
            
            .company-logo {
                width: 80px;
                height: auto;
            }
            
            .company-info h1 {
                font-family: 'Alice', serif;
                font-size: 28px;
                color: var(--deep-sea);
                margin: 0 0 5px 0;
                letter-spacing: 1px;
            }
            
            .company-info p {
                font-family: 'Tenor Sans', sans-serif;
                font-size: 12px;
                color: var(--forest-green);
                margin: 0;
                line-height: 1.5;
            }
            
            .report-title {
                text-align: right;
            }
            
            .report-title h2 {
                font-family: 'Alice', serif;
                font-size: 24px;
                color: var(--green-haze);
                margin: 0 0 5px 0;
            }
            
            .report-title .date-info {
                font-family: 'Tenor Sans', sans-serif;
                font-size: 11px;
                color: var(--forest-green);
            }
            
            .summary-box {
                background: linear-gradient(135deg, var(--light-gray) 0%, var(--white) 100%);
                border: 2px solid var(--green);
                border-radius: 10px;
                padding: 20px;
                margin-bottom: 30px;
                display: flex;
                justify-content: space-between;
                align-items: center;
                box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            }
            
            .summary-item {
                text-align: center;
                flex: 1;
                border-right: 2px solid var(--green-haze);
            }
            
            .summary-item:last-child {
                border-right: none;
            }
            
            .summary-label {
                font-family: 'Tenor Sans', sans-serif;
                font-size: 11px;
                text-transform: uppercase;
                color: var(--deep-sea);
                margin-bottom: 5px;
                font-weight: bold;
            }
            
            .summary-value {
                font-family: 'Alice', serif;
                font-size: 18px;
                color: var(--forest-green);
                font-weight: bold;
            }
            
            table {
                width: 100%;
                border-collapse: collapse;
                margin: 20px 0;
                font-family: 'Tenor Sans', sans-serif;
            }
            
            th {
                background: var(--deep-sea);
                color: var(--white);
                font-family: 'Alice', serif;
                font-size: 13px;
                padding: 12px;
                text-align: left;
                border: 1px solid var(--forest-green);
                text-transform: uppercase;
                letter-spacing: 0.5px;
            }
            
            td {
                padding: 10px;
                border: 1px solid var(--green-haze);
                font-size: 12px;
                color: var(--black);
            }
            
            tr:nth-child(even) {
                background-color: var(--light-gray);
            }
            
            tr:hover {
                background-color: rgba(46, 125, 50, 0.05);
            }
            
            .total-row {
                background: linear-gradient(135deg, var(--green) 0%, var(--deep-sea) 100%) !important;
                color: var(--white);
            }
            
            .total-row td {
                color: var(--white);
                font-family: 'Alice', serif;
                font-size: 14px;
                font-weight: bold;
                border: 1px solid var(--forest-green);
            }
            
            .branch-badge-print {
                background-color: var(--green);
                color: var(--white);
                padding: 3px 10px;
                border-radius: 15px;
                font-size: 11px;
                font-family: 'Tenor Sans', sans-serif;
                display: inline-block;
            }
            
            .status-badge-print {
                background-color: var(--yellow);
                color: var(--black);
                padding: 3px 10px;
                border-radius: 15px;
                font-size: 11px;
                font-family: 'Tenor Sans', sans-serif;
                font-weight: bold;
                display: inline-block;
            }
            
            .print-footer {
                margin-top: 40px;
                padding-top: 20px;
                border-top: 2px solid var(--deep-sea);
                display: flex;
                justify-content: space-between;
                font-family: 'Tenor Sans', sans-serif;
                font-size: 11px;
                color: var(--forest-green);
            }
            
            .signature-line {
                width: 200px;
                border-bottom: 1px solid var(--deep-sea);
                margin-top: 5px;
            }
            
            .prepared-by {
                text-align: left;
            }
            
            .generated-info {
                text-align: right;
            }
        }

        /* Action Buttons Styling */
        .action-buttons {
            display: flex;
            gap: 5px;
        }
        
        .btn-action {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
            border: 1px solid transparent;
        }
        
        .btn-view {
            background-color: #e3f2fd;
            color: #1976d2;
            border-color: #bbdefb;
        }
        
        .btn-view:hover {
            background-color: #bbdefb;
            transform: translateY(-2px);
        }
        
        .btn-print {
            background-color: #e8f5e9;
            color: var(--green);
            border-color: #c8e6c9;
        }
        
        .btn-print:hover {
            background-color: #c8e6c9;
            transform: translateY(-2px);
        }
    </style>
</head>
<body>
    <!-- MAIN APPLICATION -->
    <div id="appPage">
        <!-- Sidebar - No Print -->
        <div class="sidebar no-print" id="sidebar">
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
                            <i class="bi bi-boxes"></i>
                            <span class="nav-text">Current Inventory</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="orderproduct.php">
                            <i class="bi bi-bag"></i>
                            <span class="nav-text">Order Product</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="sales_order.php">
                            <i class="bi bi-list-check"></i>
                            <span class="nav-text">Sales Orders</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="customer.php">
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
                </ul>
            </div>
            <!-- User Profile Section at the bottom of sidebar -->
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

        <!-- Main Content Area -->
        <div class="main-content">
            <!-- Header Section with User Info and Logout - No Print -->
            <div class="navbar-top no-print">
                <button class="mobile-toggle-btn" id="mobileToggleBtn">
                    <i class="bi bi-list"></i>
                </button>
                <div class="page-title">
                    <h2>Sales Orders</h2>
                    <p>View and manage customer orders</p>
                </div>
            </div>

            <!-- Branch Info Alert (if no branch_id column) -->
            <?php if (!$branch_column_exists): ?>
                <div class="alert alert-info alert-dismissible fade show" role="alert">
                    <i class="bi bi-info-circle"></i> 
                    <strong>Branch filtering for sales orders not yet set up.</strong> Please run this SQL in phpMyAdmin to enable branch-specific order data:
                    <br><br>
                    <code>ALTER TABLE sales_orders ADD COLUMN branch_id INT NULL;</code>
                    <br>
                    <code>ALTER TABLE sales_orders ADD FOREIGN KEY (branch_id) REFERENCES branches(branch_id);</code>
                    <br><br>
                    <button type="button" class="btn btn-sm btn-primary" onclick="copySQL()">
                        <i class="bi bi-files"></i> Copy SQL
                    </button>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <script>
                    function copySQL() {
                        const sql = "ALTER TABLE sales_orders ADD COLUMN branch_id INT NULL;\nALTER TABLE sales_orders ADD FOREIGN KEY (branch_id) REFERENCES branches(branch_id);";
                        navigator.clipboard.writeText(sql).then(() => {
                            alert('SQL copied to clipboard!');
                        });
                    }
                </script>
            <?php endif; ?>

            <?php if (!$customers_branch_column_exists): ?>
                <div class="alert alert-info alert-dismissible fade show" role="alert">
                    <i class="bi bi-info-circle"></i> 
                    <strong>Branch filtering for customers not yet set up.</strong> Please run this SQL in phpMyAdmin to enable branch-specific customer data:
                    <br><br>
                    <code>ALTER TABLE customers ADD COLUMN branch_id INT NULL;</code>
                    <br>
                    <code>ALTER TABLE customers ADD FOREIGN KEY (branch_id) REFERENCES branches(branch_id);</code>
                    <br><br>
                    <button type="button" class="btn btn-sm btn-primary" onclick="copyCustomersSQL()">
                        <i class="bi bi-files"></i> Copy SQL
                    </button>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <script>
                    function copyCustomersSQL() {
                        const sql = "ALTER TABLE customers ADD COLUMN branch_id INT NULL;\nALTER TABLE customers ADD FOREIGN KEY (branch_id) REFERENCES branches(branch_id);";
                        navigator.clipboard.writeText(sql).then(() => {
                            alert('SQL copied to clipboard!');
                        });
                    }
                </script>
            <?php endif; ?>

            <!-- Statistics Cards - gaya ng customer.php - No Print -->
            <div class="row g-3 mb-4 no-print">
                <div class="col-md-3 mb-3">
                    <div class="stat-card inventory">
                        <div class="stat-icon">
                            <i class="bi bi-receipt"></i>
                        </div>
                        <div>
                            <div class="stat-value"><?php echo $stats['total_orders'] ?? 0; ?></div>
                            <div class="stat-label">Total Orders</div>
                            <?php if ($branch_column_exists && !$view_all_branches): ?>
                                <small class="text-white-50">Your Branch</small>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="col-md-3 mb-3">
                    <div class="stat-card pending">
                        <div class="stat-icon">
                            <i class="bi bi-clock"></i>
                        </div>
                        <div>
                            <div class="stat-value"><?php echo $stats['pending'] ?? 0; ?></div>
                            <div class="stat-label">Pending</div>
                        </div>
                    </div>
                </div>

                <div class="col-md-3 mb-3">
                    <div class="stat-card sales">
                        <div class="stat-icon">
                            <i class="bi bi-gear"></i>
                        </div>
                        <div>
                            <div class="stat-value"><?php echo $stats['processing'] ?? 0; ?></div>
                            <div class="stat-label">Processing</div>
                        </div>
                    </div>
                </div>

                <div class="col-md-3 mb-3">
                    <div class="stat-card complete">
                        <div class="stat-icon">
                            <i class="bi bi-cash-stack"></i>
                        </div>
                        <div>
                            <div class="stat-value">₱<?php echo number_format($stats['total_revenue'] ?? 0, 2); ?></div>
                            <div class="stat-label">Total Revenue</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Search and Filter - No Print -->
            <div class="card mb-4 no-print">
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label fw-bold">Status Filter</label>
                            <select class="form-select" id="statusFilter">
                                <option value="all" <?php echo (!isset($_GET['status']) || $_GET['status'] === 'all') ? 'selected' : ''; ?>>All Status</option>
                                <option value="pending" <?php echo (isset($_GET['status']) && $_GET['status'] === 'pending') ? 'selected' : ''; ?>>Pending</option>
                                <option value="processing" <?php echo (isset($_GET['status']) && $_GET['status'] === 'processing') ? 'selected' : ''; ?>>Processing</option>
                                <option value="shipped" <?php echo (isset($_GET['status']) && $_GET['status'] === 'shipped') ? 'selected' : ''; ?>>Shipped</option>
                                <option value="delivered" <?php echo (isset($_GET['status']) && $_GET['status'] === 'delivered') ? 'selected' : ''; ?>>Delivered</option>
                                <option value="cancelled" <?php echo (isset($_GET['status']) && $_GET['status'] === 'cancelled') ? 'selected' : ''; ?>>Cancelled</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold">Start Date</label>
                            <input type="date" class="form-control" id="startDate" 
                                   value="<?php echo isset($_GET['start_date']) ? $_GET['start_date'] : ''; ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold">End Date</label>
                            <input type="date" class="form-control" id="endDate" 
                                   value="<?php echo isset($_GET['end_date']) ? $_GET['end_date'] : ''; ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold">Search</label>
                            <input type="text" class="form-control" id="searchInput" placeholder="Order # or Customer...">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Action Buttons - No Print -->
            <div class="mb-3 d-flex gap-2 no-print">
                <button class="btn btn-primary" onclick="printAllOrders()">
                    <i class="bi bi-printer"></i> Print All Orders
                </button>
                <button class="btn btn-success" onclick="exportToExcel()">
                    <i class="bi bi-file-earmark-excel"></i> Export to Excel
                </button>
                <button class="btn btn-info" onclick="refreshOrders()">
                    <i class="bi bi-arrow-clockwise"></i> Refresh
                </button>
            </div>

            <!-- Orders Table - DESIGN GAYA NG CUSTOMER.PHP -->
            <div class="card">
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0" id="ordersTable">
                            <thead class="table-light">
                                <tr>
                                    <th>Order #</th>
                                    <th>Date</th>
                                    <th>Customer</th>
                                    <?php if ($branch_column_exists && $view_all_branches): ?>
                                        <th>Branch</th>
                                    <?php endif; ?>
                                    <th>Items</th>
                                    <th>Total Amount</th>
                                    <th>Status</th>
                                    <th class="no-print">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($orders)): ?>
                                    <tr>
                                        <td colspan="<?php echo ($branch_column_exists && $view_all_branches) ? '8' : '7'; ?>" class="text-center text-muted py-4">
                                            <i class="bi bi-inbox" style="font-size: 2rem;"></i><br>
                                            No orders found
                                            <?php if ($branch_column_exists && !$view_all_branches): ?>
                                                <br><small>No orders for your branch yet</small>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($orders as $order): ?>
                                        <tr>
                                            <td><span class="badge bg-light text-dark"><?php echo htmlspecialchars($order['so_number']); ?></span></td>
                                            <td>
                                                <?php echo date('M d, Y', strtotime($order['order_date'])); ?><br>
                                                <small class="text-muted"><?php echo date('h:i A', strtotime($order['order_date'])); ?></small>
                                            </td>
                                            <td><?php echo htmlspecialchars($order['customer_name'] ?? 'Walk-in Customer'); ?></td>
                                            <?php if ($branch_column_exists && $view_all_branches): ?>
                                                <td>
                                                    <span class="badge bg-info">
                                                        <?php echo htmlspecialchars($order['branch_name'] ?? 'Branch ' . $order['branch_id']); ?>
                                                    </span>
                                                </td>
                                            <?php endif; ?>
                                            <td><span class="badge bg-info"><?php echo $order['item_count']; ?> items</span></td>
                                            <td><strong>₱<?php echo number_format($order['total_amount'], 2); ?></strong></td>
                                            <td>
                                                <?php
                                                $status_badge = [
                                                    'pending' => 'bg-warning',
                                                    'processing' => 'bg-info',
                                                    'shipped' => 'bg-primary',
                                                    'delivered' => 'bg-success',
                                                    'cancelled' => 'bg-danger'
                                                ];
                                                $status_text = [
                                                    'pending' => 'Pending',
                                                    'processing' => 'Processing',
                                                    'shipped' => 'Shipped',
                                                    'delivered' => 'Delivered',
                                                    'cancelled' => 'Cancelled'
                                                ];
                                                ?>
                                                <span class="badge <?php echo $status_badge[$order['order_status']] ?? 'bg-secondary'; ?>">
                                                    <?php echo $status_text[$order['order_status']] ?? ucfirst($order['order_status']); ?>
                                                </span>
                                            </td>
                                            <td class="no-print">
                                                <div class="action-buttons">
                                                    <button class="btn-action btn-view" onclick="viewOrderDetails(<?php echo $order['so_id']; ?>)" title="View Details">
                                                        <i class="bi bi-eye"></i>
                                                    </button>
                                                    <button class="btn-action btn-print" onclick="printSingleOrder(<?php echo $order['so_id']; ?>)" title="Print Order">
                                                        <i class="bi bi-printer"></i>
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
            
            <!-- PRINT VERSION - HIDDEN SA SCREEN -->
            <div id="printContainer" style="display: none;"></div>
        </div>
    </div>

    <!-- Order Details Modal - No Print -->
    <div class="modal fade no-print" id="orderDetailsModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Order Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="orderDetailsContent">
                    <!-- Content loaded via AJAX -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-primary" id="printOrderFromDetails" style="display: none;" onclick="printFromDetails()">Print Order</button>
                </div>
            </div>
        </div>
    </div>

    <!-- JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <!-- jQuery (required for AJAX) -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
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

        // Branch context variables
        const branchId = <?php echo $branch_id; ?>;
        const viewAllBranches = <?php echo $view_all_branches ? 'true' : 'false'; ?>;
        const branchColumnExists = <?php echo $branch_column_exists ? 'true' : 'false'; ?>;
        const logoBase64 = '<?php echo $logo_base64; ?>';

        // Global variables
        let currentOrderId = null;

        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            console.log("Sales Orders page loaded!");
            console.log("Branch ID:", branchId);
            console.log("View All Branches:", viewAllBranches);
            console.log("Branch Column Exists:", branchColumnExists);
            
            // Initialize sidebar
            initializeSidebar();
            
            // Setup mobile toggle buttons
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
            window.addEventListener('resize', handleSidebarResize);

            // Set default date range (last 30 days)
            const startDate = document.getElementById('startDate');
            const endDate = document.getElementById('endDate');
            
            if (startDate && !startDate.value) {
                const startDateObj = new Date();
                startDateObj.setDate(startDateObj.getDate() - 30);
                startDate.value = startDateObj.toISOString().split('T')[0];
            }
            
            if (endDate && !endDate.value) {
                endDate.value = new Date().toISOString().split('T')[0];
            }

            // Setup event listeners for search and filters
            setupEventListeners();
        });

        // Setup event listeners
        function setupEventListeners() {
            // Search functionality
            const searchInput = document.getElementById('searchInput');
            if (searchInput) {
                searchInput.addEventListener('keyup', function() {
                    const filter = this.value.toLowerCase();
                    const rows = document.querySelectorAll('#ordersTable tbody tr');
                    
                    rows.forEach(row => {
                        if (row.cells.length > 1 && !row.querySelector('td[colspan]')) {
                            const text = row.textContent.toLowerCase();
                            row.style.display = text.includes(filter) ? '' : 'none';
                        }
                    });
                });
            }

            // Status filter
            const statusFilter = document.getElementById('statusFilter');
            if (statusFilter) {
                statusFilter.addEventListener('change', function() {
                    const filter = this.value.toLowerCase();
                    const rows = document.querySelectorAll('#ordersTable tbody tr');
                    
                    rows.forEach(row => {
                        if (row.cells.length > 1 && !row.querySelector('td[colspan]')) {
                            if (filter === 'all') {
                                row.style.display = '';
                                return;
                            }
                            
                            const statusCell = row.cells[branchColumnExists && viewAllBranches ? 6 : 5];
                            const statusText = statusCell.textContent.toLowerCase().trim();
                            row.style.display = statusText.includes(filter) ? '' : 'none';
                        }
                    });
                });
            }

            // Date filters
            const startDate = document.getElementById('startDate');
            const endDate = document.getElementById('endDate');
            
            if (startDate) startDate.addEventListener('change', filterByDate);
            if (endDate) endDate.addEventListener('change', filterByDate);
        }

        function filterByDate() {
            const startDate = document.getElementById('startDate');
            const endDate = document.getElementById('endDate');
            const startValue = startDate ? startDate.value : '';
            const endValue = endDate ? endDate.value : '';
            const rows = document.querySelectorAll('#ordersTable tbody tr');
            
            rows.forEach(row => {
                if (row.cells.length > 1 && !row.querySelector('td[colspan]')) {
                    const dateCell = row.cells[1];
                    const dateText = dateCell.querySelector('small') ? dateCell.querySelector('small').textContent : '';
                    const rowDate = new Date(dateText);
                    
                    let show = true;
                    if (startValue) {
                        show = show && rowDate >= new Date(startValue);
                    }
                    if (endValue) {
                        const endDateTime = new Date(endValue);
                        endDateTime.setHours(23, 59, 59);
                        show = show && rowDate <= endDateTime;
                    }
                    
                    row.style.display = show ? '' : 'none';
                }
            });
        }

        // Refresh orders
        function refreshOrders() {
            location.reload();
        }

        // View order details
        function viewOrderDetails(orderId) {
            currentOrderId = orderId;
            const modal = new bootstrap.Modal(document.getElementById('orderDetailsModal'));
            
            // Show loading
            const orderDetailsContent = document.getElementById('orderDetailsContent');
            if (orderDetailsContent) {
                orderDetailsContent.innerHTML = `
                    <div class="text-center py-5">
                        <div class="spinner-border text-success" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                        <p class="mt-3">Loading order details...</p>
                    </div>
                `;
            }
            
            modal.show();
            
            // Fetch order details via AJAX
            fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: 'action=get_order_details&order_id=' + orderId
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok');
                }
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    const order = data.order;
                    const items = data.items;
                    
                    let itemsHtml = '';
                    if (items && items.length > 0) {
                        itemsHtml = items.map(item => `
                            <tr>
                                <td>${item.item_name}<br><small class="text-muted">${item.item_code}</small></td>
                                <td class="text-center">${item.quantity_ordered}</td>
                                <td class="text-end">₱${parseFloat(item.unit_price).toFixed(2)}</td>
                                <td class="text-end">₱${parseFloat(item.line_total).toFixed(2)}</td>
                            </tr>
                        `).join('');
                    }
                    
                    if (orderDetailsContent) {
                        orderDetailsContent.innerHTML = `
                            <div class="row mb-4">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label text-muted small mb-1">Order Number</label>
                                        <p class="h5">${order.so_number}</p>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label text-muted small mb-1">Order Date</label>
                                        <p class="mb-0">${new Date(order.order_date).toLocaleString()}</p>
                                    </div>
                                    ${order.branch_name ? `
                                    <div class="mb-3">
                                        <label class="form-label text-muted small mb-1">Branch</label>
                                        <p class="mb-0"><span class="badge bg-info">${order.branch_name}</span></p>
                                    </div>
                                    ` : ''}
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label text-muted small mb-1">Status</label>
                                        <p><span class="badge bg-success fs-6">${order.order_status}</span></p>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label text-muted small mb-1">Created By</label>
                                        <p class="mb-0">${order.created_by || 'System'}</p>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row mb-4">
                                <div class="col-12">
                                    <h6 class="border-bottom pb-2">Customer Information</h6>
                                    <div class="bg-light p-3 rounded">
                                        <p class="mb-1"><strong>Name:</strong> ${order.customer_name}</p>
                                        <p class="mb-1"><strong>Email:</strong> ${order.email || 'N/A'}</p>
                                        <p class="mb-1"><strong>Phone:</strong> ${order.phone_number || 'N/A'}</p>
                                        <p class="mb-0"><strong>Address:</strong> ${order.address || 'N/A'}</p>
                                    </div>
                                </div>
                            </div>
                            
                            <h6 class="border-bottom pb-2">Order Items</h6>
                            <div class="table-responsive mb-4">
                                <table class="table table-bordered table-sm">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Product</th>
                                            <th class="text-center">Quantity</th>
                                            <th class="text-end">Unit Price</th>
                                            <th class="text-end">Total</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        ${itemsHtml}
                                        <tr class="table-success fw-bold">
                                            <td colspan="3" class="text-end">Grand Total</td>
                                            <td class="text-end">₱${parseFloat(order.total_amount).toFixed(2)}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        `;
                    }
                    
                    // Show print button
                    const printButton = document.getElementById('printOrderFromDetails');
                    if (printButton) printButton.style.display = 'inline-block';
                } else {
                    if (orderDetailsContent) {
                        orderDetailsContent.innerHTML = `
                            <div class="alert alert-danger">
                                <i class="bi bi-exclamation-triangle"></i> ${data.message || 'Error loading order details.'}
                            </div>
                        `;
                    }
                    const printButton = document.getElementById('printOrderFromDetails');
                    if (printButton) printButton.style.display = 'none';
                }
            })
            .catch(error => {
                console.error('Error:', error);
                if (orderDetailsContent) {
                    orderDetailsContent.innerHTML = `
                        <div class="alert alert-danger">
                            <i class="bi bi-exclamation-triangle"></i> Network error: ${error.message}
                        </div>
                    `;
                }
                const printButton = document.getElementById('printOrderFromDetails');
                if (printButton) printButton.style.display = 'none';
            });
        }
        
        // PRINT SINGLE ORDER - With Brand Colors and Logo
        function printSingleOrder(orderId) {
            currentOrderId = orderId;
            
            // Show loading indicator
            const printBtn = event ? event.target.closest('button') : null;
            if (printBtn) {
                const originalHTML = printBtn.innerHTML;
                printBtn.innerHTML = '<i class="bi bi-printer"></i>';
                printBtn.disabled = true;
                
                // Restore button after timeout
                setTimeout(() => {
                    printBtn.innerHTML = originalHTML;
                    printBtn.disabled = false;
                }, 3000);
            }
            
            // Get order details
            fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: 'action=get_order_details&order_id=' + orderId
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const order = data.order;
                    const items = data.items;
                    
                    // Create iframe for printing
                    const iframe = document.createElement('iframe');
                    iframe.style.position = 'absolute';
                    iframe.style.width = '0';
                    iframe.style.height = '0';
                    iframe.style.border = 'none';
                    iframe.style.top = '-9999px';
                    iframe.style.left = '-9999px';
                    document.body.appendChild(iframe);
                    
                    // Generate HTML content with brand colors and logo
                    const htmlContent = generateSingleOrderHTML(order, items);
                    
                    // Write to iframe and print
                    const iframeDoc = iframe.contentWindow.document;
                    iframeDoc.open();
                    iframeDoc.write(htmlContent);
                    iframeDoc.close();
                    
                    // Auto print after load
                    iframe.contentWindow.focus();
                    setTimeout(() => {
                        iframe.contentWindow.print();
                        // Remove iframe after print
                        setTimeout(() => {
                            document.body.removeChild(iframe);
                        }, 100);
                    }, 250);
                } else {
                    alert('Error loading order details: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Network error: ' + error.message);
            });
        }
        
        // PRINT ALL ORDERS - With Brand Colors and Logo
        function printAllOrders() {
            const rows = document.querySelectorAll('#ordersTable tbody tr');
            const visibleRows = [];
            
            rows.forEach(row => {
                if (!row.querySelector('td[colspan]') && row.style.display !== 'none') {
                    visibleRows.push(row);
                }
            });
            
            if (visibleRows.length === 0) {
                alert('No orders to print');
                return;
            }
            
            // Show loading on button
            const printBtn = document.querySelector('.btn-primary[onclick="printAllOrders()"]');
            if (printBtn) {
                const originalText = printBtn.innerHTML;
                printBtn.innerHTML = '<i class="bi bi-printer"></i> Printing...';
                printBtn.disabled = true;
                
                setTimeout(() => {
                    printBtn.innerHTML = originalText;
                    printBtn.disabled = false;
                }, 3000);
            }
            
            // Create iframe for printing
            const iframe = document.createElement('iframe');
            iframe.style.position = 'absolute';
            iframe.style.width = '0';
            iframe.style.height = '0';
            iframe.style.border = 'none';
            iframe.style.top = '-9999px';
            iframe.style.left = '-9999px';
            document.body.appendChild(iframe);
            
            // Generate HTML content with brand colors and logo
            const htmlContent = generateAllOrdersHTML(visibleRows);
            
            // Write to iframe and print
            const iframeDoc = iframe.contentWindow.document;
            iframeDoc.open();
            iframeDoc.write(htmlContent);
            iframeDoc.close();
            
            // Auto print after load
            iframe.contentWindow.focus();
            setTimeout(() => {
                iframe.contentWindow.print();
                // Remove iframe after print
                setTimeout(() => {
                    document.body.removeChild(iframe);
                }, 100);
            }, 250);
        }
        
        // Generate HTML for single order with brand colors
        function generateSingleOrderHTML(order, items) {
            let itemsHtml = '';
            
            if (items && items.length > 0) {
                itemsHtml = items.map(item => `
                    <tr>
                        <td style="padding: 10px; border: 1px solid var(--green-haze);">${item.item_name}<br><small style="color: var(--forest-green);">${item.item_code}</small></td>
                        <td style="padding: 10px; border: 1px solid var(--green-haze); text-align: center;">${item.quantity_ordered}</td>
                        <td style="padding: 10px; border: 1px solid var(--green-haze); text-align: right;">₱${parseFloat(item.unit_price).toFixed(2)}</td>
                        <td style="padding: 10px; border: 1px solid var(--green-haze); text-align: right;">₱${parseFloat(item.line_total).toFixed(2)}</td>
                    </tr>
                `).join('');
            }
            
            const currentDate = new Date();
            const formattedDate = currentDate.toLocaleDateString('en-US', { 
                year: 'numeric', 
                month: 'long', 
                day: 'numeric' 
            });
            const formattedTime = currentDate.toLocaleTimeString('en-US', { 
                hour: '2-digit', 
                minute: '2-digit' 
            });
            
            return `
                <!DOCTYPE html>
                <html>
                <head>
                    <meta charset="UTF-8">
                    <meta name="viewport" content="width=device-width, initial-scale=1.0">
                    <title>Order #${order.so_number}</title>
                    <link href="https://fonts.googleapis.com/css2?family=Tenor+Sans&family=Alice&display=swap" rel="stylesheet">
                    <style>
                        :root {
                            --green: #2E7D32;
                            --green-haze: #1B5E20;
                            --deep-sea: #0D4C14;
                            --forest-green: #1B4D1F;
                            --yellow: #FFC107;
                            --white: #FFFFFF;
                            --light-gray: #F5F5F5;
                            --black: #212121;
                        }
                        
                        @page {
                            size: portrait;
                            margin: 0.75in;
                        }
                        
                        body {
                            font-family: 'Tenor Sans', sans-serif;
                            margin: 0;
                            padding: 20px;
                            color: var(--black);
                            background-color: var(--white);
                        }
                        
                        .print-container {
                            max-width: 800px;
                            margin: 0 auto;
                        }
                        
                        .print-header {
                            display: flex;
                            align-items: center;
                            justify-content: space-between;
                            margin-bottom: 30px;
                            padding-bottom: 20px;
                            border-bottom: 3px solid var(--deep-sea);
                        }
                        
                        .logo-section {
                            display: flex;
                            align-items: center;
                            gap: 15px;
                        }
                        
                        .company-logo {
                            width: 80px;
                            height: auto;
                        }
                        
                        .company-info h1 {
                            font-family: 'Alice', serif;
                            font-size: 28px;
                            color: var(--deep-sea);
                            margin: 0 0 5px 0;
                            letter-spacing: 1px;
                        }
                        
                        .company-info p {
                            font-family: 'Tenor Sans', sans-serif;
                            font-size: 12px;
                            color: var(--forest-green);
                            margin: 0;
                            line-height: 1.5;
                        }
                        
                        .report-title {
                            text-align: right;
                        }
                        
                        .report-title h2 {
                            font-family: 'Alice', serif;
                            font-size: 24px;
                            color: var(--green-haze);
                            margin: 0 0 5px 0;
                        }
                        
                        .report-title .date-info {
                            font-family: 'Tenor Sans', sans-serif;
                            font-size: 11px;
                            color: var(--forest-green);
                        }
                        
                        .customer-section {
                            background: linear-gradient(135deg, var(--light-gray) 0%, var(--white) 100%);
                            border: 2px solid var(--green);
                            border-radius: 10px;
                            padding: 20px;
                            margin-bottom: 30px;
                        }
                        
                        .section-title {
                            font-family: 'Alice', serif;
                            font-size: 18px;
                            color: var(--deep-sea);
                            margin-bottom: 15px;
                            border-bottom: 2px solid var(--green-haze);
                            padding-bottom: 5px;
                        }
                        
                        .info-row {
                            display: flex;
                            margin-bottom: 8px;
                            font-size: 13px;
                        }
                        
                        .info-label {
                            width: 120px;
                            font-weight: bold;
                            color: var(--forest-green);
                        }
                        
                        .info-value {
                            flex: 1;
                            color: var(--black);
                        }
                        
                        table {
                            width: 100%;
                            border-collapse: collapse;
                            margin: 20px 0;
                        }
                        
                        th {
                            background: var(--deep-sea);
                            color: var(--white);
                            font-family: 'Alice', serif;
                            font-size: 13px;
                            padding: 12px;
                            text-align: left;
                            border: 1px solid var(--forest-green);
                        }
                        
                        td {
                            padding: 10px;
                            border: 1px solid var(--green-haze);
                            font-size: 12px;
                        }
                        
                        tr:nth-child(even) {
                            background-color: var(--light-gray);
                        }
                        
                        .total-row {
                            background: linear-gradient(135deg, var(--green) 0%, var(--deep-sea) 100%);
                            color: var(--white);
                            font-family: 'Alice', serif;
                            font-size: 14px;
                            font-weight: bold;
                        }
                        
                        .total-row td {
                            color: var(--white);
                            border: 1px solid var(--forest-green);
                        }
                        
                        .status-badge {
                            background-color: var(--yellow);
                            color: var(--black);
                            padding: 5px 15px;
                            border-radius: 20px;
                            font-size: 12px;
                            font-weight: bold;
                            display: inline-block;
                        }
                        
                        .print-footer {
                            margin-top: 40px;
                            padding-top: 20px;
                            border-top: 2px solid var(--deep-sea);
                            display: flex;
                            justify-content: space-between;
                            font-family: 'Tenor Sans', sans-serif;
                            font-size: 11px;
                            color: var(--forest-green);
                        }
                        
                        .signature-line {
                            width: 200px;
                            border-bottom: 1px solid var(--deep-sea);
                            margin-top: 5px;
                        }
                    </style>
                </head>
                <body>
                    <div class="print-container">
                        <div class="print-header">
                            <div class="logo-section">
                                <img src="${logoBase64}" alt="AMGC Logo" class="company-logo">
                                <div class="company-info">
                                    <h1>AMGC</h1>
                                    <p>Quality Products, Quality Service</p>
                                </div>
                            </div>
                            <div class="report-title">
                                <h2>SALES ORDER</h2>
                                <div class="date-info">${formattedDate} | ${formattedTime}</div>
                            </div>
                        </div>
                        
                        <div class="customer-section">
                            <div class="section-title">Order Information</div>
                            <div style="display: flex; justify-content: space-between; margin-bottom: 10px;">
                                <div class="info-row">
                                    <span class="info-label">Order Number:</span>
                                    <span class="info-value"><strong>${order.so_number}</strong></span>
                                </div>
                                <div class="info-row">
                                    <span class="info-label">Status:</span>
                                    <span class="info-value"><span class="status-badge">${order.order_status}</span></span>
                                </div>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Order Date:</span>
                                <span class="info-value">${new Date(order.order_date).toLocaleString()}</span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Created By:</span>
                                <span class="info-value">${order.created_by || 'System'}</span>
                            </div>
                            ${order.branch_name ? `
                            <div class="info-row">
                                <span class="info-label">Branch:</span>
                                <span class="info-value">${order.branch_name}</span>
                            </div>
                            ` : ''}
                        </div>
                        
                        <div class="customer-section">
                            <div class="section-title">Customer Information</div>
                            <div class="info-row">
                                <span class="info-label">Name:</span>
                                <span class="info-value">${order.customer_name}</span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Email:</span>
                                <span class="info-value">${order.email || 'N/A'}</span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Phone:</span>
                                <span class="info-value">${order.phone_number || 'N/A'}</span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Address:</span>
                                <span class="info-value">${order.address || 'N/A'}</span>
                            </div>
                        </div>
                        
                        <div class="section-title">Order Items</div>
                        <table>
                            <thead>
                                <tr>
                                    <th>Product</th>
                                    <th style="text-align: center;">Qty</th>
                                    <th style="text-align: right;">Unit Price</th>
                                    <th style="text-align: right;">Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${itemsHtml}
                                <tr class="total-row">
                                    <td colspan="3" style="text-align: right;">GRAND TOTAL</td>
                                    <td style="text-align: right;">₱${parseFloat(order.total_amount).toFixed(2)}</td>
                                </tr>
                            </tbody>
                        </table>
                        
                        <div class="print-footer">
                            <div class="prepared-by">
                                <div>Prepared by:</div>
                                <div class="signature-line"></div>
                                <div style="margin-top: 5px;">${document.querySelector('.user-name-sidebar')?.textContent || 'Sales Staff'}</div>
                            </div>
                            <div class="generated-info">
                                <div>Generated on:</div>
                                <div>${formattedDate} at ${formattedTime}</div>
                            </div>
                        </div>
                    </div>
                </body>
                </html>
            `;
        }
        
        // Generate HTML for all orders with brand colors
        function generateAllOrdersHTML(rows) {
            let tableRows = '';
            let totalAmount = 0;
            
            rows.forEach(row => {
                const cells = row.querySelectorAll('td');
                const hasBranchColumn = branchColumnExists && viewAllBranches;
                
                if (cells.length >= 6) {
                    const orderNumber = cells[0].textContent.trim();
                    const date = cells[1].textContent.trim().replace(/\n/g, ' ');
                    const customer = cells[2].textContent.trim();
                    
                    let branch = '';
                    let items = '';
                    let amount = '';
                    let status = '';
                    
                    if (hasBranchColumn) {
                        branch = cells[3].textContent.trim();
                        items = cells[4].textContent.trim();
                        amount = cells[5].textContent.trim();
                        status = cells[6].textContent.trim();
                    } else {
                        items = cells[3].textContent.trim();
                        amount = cells[4].textContent.trim();
                        status = cells[5].textContent.trim();
                    }
                    
                    const amountValue = parseFloat(amount.replace('₱', '').replace(',', '')) || 0;
                    totalAmount += amountValue;
                    
                    tableRows += '<tr>';
                    tableRows += `<td style="padding: 8px; border: 1px solid var(--green-haze);">${orderNumber}</td>`;
                    tableRows += `<td style="padding: 8px; border: 1px solid var(--green-haze);">${date}</td>`;
                    tableRows += `<td style="padding: 8px; border: 1px solid var(--green-haze);">${customer}</td>`;
                    if (hasBranchColumn) {
                        tableRows += `<td style="padding: 8px; border: 1px solid var(--green-haze);">${branch}</td>`;
                    }
                    tableRows += `<td style="padding: 8px; border: 1px solid var(--green-haze); text-align: center;">${items}</td>`;
                    tableRows += `<td style="padding: 8px; border: 1px solid var(--green-haze); text-align: right;">${amount}</td>`;
                    tableRows += `<td style="padding: 8px; border: 1px solid var(--green-haze);"><span class="status-badge-print">${status}</span></td>`;
                    tableRows += '</tr>';
                }
            });
            
            const currentDate = new Date();
            const formattedDate = currentDate.toLocaleDateString('en-US', { 
                year: 'numeric', 
                month: 'long', 
                day: 'numeric' 
            });
            const formattedTime = currentDate.toLocaleTimeString('en-US', { 
                hour: '2-digit', 
                minute: '2-digit' 
            });
            
            const columnCount = branchColumnExists && viewAllBranches ? 7 : 6;
            const totalColspan = branchColumnExists && viewAllBranches ? 5 : 4;
            
            return `
                <!DOCTYPE html>
                <html>
                <head>
                    <meta charset="UTF-8">
                    <meta name="viewport" content="width=device-width, initial-scale=1.0">
                    <title>Sales Orders Report</title>
                    <link href="https://fonts.googleapis.com/css2?family=Tenor+Sans&family=Alice&display=swap" rel="stylesheet">
                    <style>
                        :root {
                            --green: #2E7D32;
                            --green-haze: #1B5E20;
                            --deep-sea: #0D4C14;
                            --forest-green: #1B4D1F;
                            --yellow: #FFC107;
                            --white: #FFFFFF;
                            --light-gray: #F5F5F5;
                            --black: #212121;
                        }
                        
                        @page {
                            size: landscape;
                            margin: 0.5in;
                        }
                        
                        body {
                            font-family: 'Tenor Sans', sans-serif;
                            margin: 0;
                            padding: 20px;
                            color: var(--black);
                            background-color: var(--white);
                        }
                        
                        .print-container {
                            max-width: 100%;
                            margin: 0 auto;
                        }
                        
                        .print-header {
                            display: flex;
                            align-items: center;
                            justify-content: space-between;
                            margin-bottom: 30px;
                            padding-bottom: 20px;
                            border-bottom: 3px solid var(--deep-sea);
                        }
                        
                        .logo-section {
                            display: flex;
                            align-items: center;
                            gap: 15px;
                        }
                        
                        .company-logo {
                            width: 70px;
                            height: auto;
                        }
                        
                        .company-info h1 {
                            font-family: 'Alice', serif;
                            font-size: 26px;
                            color: var(--deep-sea);
                            margin: 0 0 5px 0;
                        }
                        
                        .company-info p {
                            font-family: 'Tenor Sans', sans-serif;
                            font-size: 11px;
                            color: var(--forest-green);
                            margin: 0;
                        }
                        
                        .report-title {
                            text-align: right;
                        }
                        
                        .report-title h2 {
                            font-family: 'Alice', serif;
                            font-size: 22px;
                            color: var(--green-haze);
                            margin: 0 0 5px 0;
                        }
                        
                        .report-title .date-info {
                            font-family: 'Tenor Sans', sans-serif;
                            font-size: 10px;
                            color: var(--forest-green);
                        }
                        
                        .summary-box {
                            background: linear-gradient(135deg, var(--light-gray) 0%, var(--white) 100%);
                            border: 2px solid var(--green);
                            border-radius: 10px;
                            padding: 15px;
                            margin-bottom: 25px;
                            display: flex;
                            justify-content: space-between;
                            align-items: center;
                        }
                        
                        .summary-item {
                            text-align: center;
                            flex: 1;
                            border-right: 2px solid var(--green-haze);
                        }
                        
                        .summary-item:last-child {
                            border-right: none;
                        }
                        
                        .summary-label {
                            font-family: 'Tenor Sans', sans-serif;
                            font-size: 10px;
                            text-transform: uppercase;
                            color: var(--deep-sea);
                            margin-bottom: 5px;
                            font-weight: bold;
                        }
                        
                        .summary-value {
                            font-family: 'Alice', serif;
                            font-size: 16px;
                            color: var(--forest-green);
                            font-weight: bold;
                        }
                        
                        table {
                            width: 100%;
                            border-collapse: collapse;
                            margin: 20px 0;
                        }
                        
                        th {
                            background: var(--deep-sea);
                            color: var(--white);
                            font-family: 'Alice', serif;
                            font-size: 12px;
                            padding: 10px;
                            text-align: left;
                            border: 1px solid var(--forest-green);
                        }
                        
                        td {
                            padding: 8px;
                            border: 1px solid var(--green-haze);
                            font-size: 11px;
                        }
                        
                        tr:nth-child(even) {
                            background-color: var(--light-gray);
                        }
                        
                        .total-row {
                            background: linear-gradient(135deg, var(--green) 0%, var(--deep-sea) 100%);
                            color: var(--white);
                            font-family: 'Alice', serif;
                            font-size: 13px;
                            font-weight: bold;
                        }
                        
                        .total-row td {
                            color: var(--white);
                            border: 1px solid var(--forest-green);
                        }
                        
                        .status-badge-print {
                            background-color: var(--yellow);
                            color: var(--black);
                            padding: 3px 10px;
                            border-radius: 15px;
                            font-size: 10px;
                            font-weight: bold;
                            display: inline-block;
                        }
                        
                        .print-footer {
                            margin-top: 30px;
                            padding-top: 15px;
                            border-top: 2px solid var(--deep-sea);
                            display: flex;
                            justify-content: space-between;
                            font-family: 'Tenor Sans', sans-serif;
                            font-size: 10px;
                            color: var(--forest-green);
                        }
                        
                        .signature-line {
                            width: 150px;
                            border-bottom: 1px solid var(--deep-sea);
                            margin-top: 5px;
                        }
                    </style>
                </head>
                <body>
                    <div class="print-container">
                        <div class="print-header">
                            <div class="logo-section">
                                <img src="${logoBase64}" alt="AMGC Logo" class="company-logo">
                                <div class="company-info">
                                    <h1>AMGC</h1>
                                    <p>Quality Products, Quality Service</p>
                                </div>
                            </div>
                            <div class="report-title">
                                <h2>SALES ORDERS REPORT</h2>
                                <div class="date-info">${formattedDate} | ${formattedTime}</div>
                            </div>
                        </div>
                        
                        <div class="summary-box">
                            <div class="summary-item">
                                <div class="summary-label">Total Orders</div>
                                <div class="summary-value">${rows.length}</div>
                            </div>
                            <div class="summary-item">
                                <div class="summary-label">Total Amount</div>
                                <div class="summary-value">₱${totalAmount.toFixed(2)}</div>
                            </div>
                            <div class="summary-item">
                                <div class="summary-label">Branch</div>
                                <div class="summary-value">${!viewAllBranches && branchId > 0 ? `Branch ${branchId}` : 'All Branches'}</div>
                            </div>
                        </div>
                        
                        <table>
                            <thead>
                                <tr>
                                    <th>Order #</th>
                                    <th>Date</th>
                                    <th>Customer</th>
                                    ${branchColumnExists && viewAllBranches ? '<th>Branch</th>' : ''}
                                    <th style="text-align: center;">Items</th>
                                    <th style="text-align: right;">Total Amount</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${tableRows}
                                <tr class="total-row">
                                    <td colspan="${totalColspan}" style="text-align: right;">GRAND TOTAL</td>
                                    <td style="text-align: right;">₱${totalAmount.toFixed(2)}</td>
                                    <td></td>
                                </tr>
                            </tbody>
                        </table>
                        
                        <div class="print-footer">
                            <div class="prepared-by">
                                <div>Prepared by:</div>
                                <div class="signature-line"></div>
                                <div style="margin-top: 5px;">${document.querySelector('.user-name-sidebar')?.textContent || 'Sales Staff'}</div>
                            </div>
                            <div class="generated-info">
                                <div>Generated on:</div>
                                <div>${formattedDate} at ${formattedTime}</div>
                            </div>
                        </div>
                    </div>
                </body>
                </html>
            `;
        }
        
        // Print from details modal
        function printFromDetails() {
            if (currentOrderId) {
                printSingleOrder(currentOrderId);
                const modal = bootstrap.Modal.getInstance(document.getElementById('orderDetailsModal'));
                if (modal) modal.hide();
            }
        }
        
        // Export to Excel
        function exportToExcel() {
            const rows = document.querySelectorAll('#ordersTable tbody tr');
            const visibleRows = [];
            
            rows.forEach(row => {
                if (!row.querySelector('td[colspan]') && row.style.display !== 'none') {
                    visibleRows.push(row);
                }
            });
            
            if (visibleRows.length === 0) {
                alert('No orders to export');
                return;
            }
            
            // Create Excel HTML content
            let excelContent = `
                <html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">
                <head>
                    <meta charset="UTF-8">
                    <title>Sales Orders Export</title>
                    <style>
                        .title { font-size: 20px; font-weight: bold; }
                        .header { background: #4e73df; color: white; }
                        th { background: #4e73df; color: white; padding: 8px; }
                        td { padding: 6px; border: 1px solid #ddd; }
                    </style>
                </head>
                <body>
                    <table border="1">
                        <tr>
                            <td colspan="${branchColumnExists && viewAllBranches ? '8' : '7'}" style="font-size: 20px; font-weight: bold; text-align: center; background: #4e73df; color: white;">
                                SALES ORDERS REPORT
                            </td>
                        </tr>
                        <tr>
                            <td colspan="${branchColumnExists && viewAllBranches ? '8' : '7'}" style="text-align: center;">
                                Export Date: ${new Date().toLocaleString()}
                            </td>
                        </tr>
                        <tr>
                            <td colspan="${branchColumnExists && viewAllBranches ? '8' : '7'}" style="text-align: center;">
                                Total Orders: ${visibleRows.length} | Total Amount: ₱${calculateTotalAmount(visibleRows).toFixed(2)}
                                ${!viewAllBranches && branchId > 0 ? ` | Branch: ${branchId}` : ''}
                            </td>
                        </tr>
                        <tr></tr>
                        <tr>
                            <th>Order #</th>
                            <th>Date</th>
                            <th>Customer</th>
                            ${branchColumnExists && viewAllBranches ? '<th>Branch</th>' : ''}
                            <th>Items</th>
                            <th>Total Amount</th>
                            <th>Status</th>
                        </tr>
            `;
            
            visibleRows.forEach(row => {
                const cells = row.querySelectorAll('td');
                const hasBranchColumn = branchColumnExists && viewAllBranches;
                
                excelContent += '<tr>';
                excelContent += `<td>${cells[0].textContent.trim()}</td>`;
                excelContent += `<td>${cells[1].textContent.trim().replace(/\n/g, ' ')}</td>`;
                excelContent += `<td>${cells[2].textContent.trim()}</td>`;
                
                if (hasBranchColumn) {
                    excelContent += `<td>${cells[3].textContent.trim()}</td>`;
                    excelContent += `<td>${cells[4].textContent.trim()}</td>`;
                    excelContent += `<td>${cells[5].textContent.trim()}</td>`;
                    excelContent += `<td>${cells[6].textContent.trim()}</td>`;
                } else {
                    excelContent += `<td>${cells[3].textContent.trim()}</td>`;
                    excelContent += `<td>${cells[4].textContent.trim()}</td>`;
                    excelContent += `<td>${cells[5].textContent.trim()}</td>`;
                }
                
                excelContent += '</tr>';
            });
            
            excelContent += `
                        <tr style="font-weight: bold; background: #f0f0f0;">
                            <td colspan="${branchColumnExists && viewAllBranches ? '5' : '4'}" style="text-align: right;">GRAND TOTAL</td>
                            <td>₱${calculateTotalAmount(visibleRows).toFixed(2)}</td>
                            <td></td>
                            ${branchColumnExists && viewAllBranches ? '<td></td>' : ''}
                        </tr>
                    </table>
                </body>
                </html>
            `;
            
            // Create Excel file
            const blob = new Blob([excelContent], { 
                type: 'application/vnd.ms-excel' 
            });
            
            const link = document.createElement('a');
            const url = URL.createObjectURL(blob);
            link.setAttribute('href', url);
            link.setAttribute('download', 'sales_orders_' + new Date().toISOString().split('T')[0] + 
                           (!viewAllBranches && branchId > 0 ? '_branch_' + branchId : '') + '.xls');
            link.style.visibility = 'hidden';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            URL.revokeObjectURL(url);
        }
        
        // Helper function to calculate total amount
        function calculateTotalAmount(rows) {
            let total = 0;
            rows.forEach(row => {
                const cells = row.querySelectorAll('td');
                const hasBranchColumn = branchColumnExists && viewAllBranches;
                const amountIndex = hasBranchColumn ? 5 : 4;
                
                if (cells.length > amountIndex) {
                    const amount = cells[amountIndex].textContent.trim();
                    total += parseFloat(amount.replace('₱', '').replace(',', '')) || 0;
                }
            });
            return total;
        }

        function logout() {
            window.location.href = '../logout.php';
        }

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey && e.key === 'b' && window.innerWidth > 992) {
                e.preventDefault();
                toggleSidebar();
            }
            else if (e.key === 'Escape' && window.innerWidth <= 992) {
                closeMobileSidebar();
            }
            else if (e.ctrlKey && e.key === 'f' && !e.target.matches('input, textarea')) {
                e.preventDefault();
                const searchInput = document.getElementById('searchInput');
                if (searchInput) {
                    searchInput.focus();
                }
            }
            else if (e.ctrlKey && e.key === 'r' && !e.target.matches('input, textarea')) {
                e.preventDefault();
                refreshOrders();
            }
            else if (e.ctrlKey && e.key === 'p' && !e.target.matches('input, textarea')) {
                e.preventDefault();
                printAllOrders();
            }
        });
    </script>
</body>
</html>