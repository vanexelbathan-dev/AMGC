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
    <!-- DataTables CSS -->
    <link rel="stylesheet" type="text/css" href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap5.min.css">
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
                                                <div class="btn-group" role="group">
                                                    <button class="btn btn-sm btn-outline-primary" onclick="viewOrderDetails(<?php echo $order['so_id']; ?>)" title="View Details">
                                                        <i class="bi bi-eye"></i>
                                                    </button>
                                                    <button class="btn btn-sm btn-outline-secondary" onclick="printSingleOrder(<?php echo $order['so_id']; ?>)" title="Print Order">
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
    <!-- jQuery (required for DataTables) -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <!-- DataTables -->
    <script type="text/javascript" src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
    <script type="text/javascript" src="https://cdn.datatables.net/1.11.5/js/dataTables.bootstrap5.min.js"></script>
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

            // Initialize DataTable - gaya ng customer.php
            try {
                if ($('#ordersTable tbody tr').length > 1 || 
                    ($('#ordersTable tbody tr').length === 1 && $('#ordersTable tbody tr td').length > 1)) {
                    $('#ordersTable').DataTable({
                        pageLength: 25,
                        order: [[1, 'desc']],
                        responsive: true,
                        dom: '<"row"<"col-sm-12 col-md-6"l><"col-sm-12 col-md-6"f>>rtip',
                        language: {
                            search: "_INPUT_",
                            searchPlaceholder: "Search orders..."
                        }
                    });
                }
            } catch(e) {
                console.log('DataTable not initialized');
            }

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
        
        // PRINT SINGLE ORDER - REKTA PRINT NA, WALANG PREVIEW, HINDI LILIPAT NG PAGE
        function printSingleOrder(orderId) {
            currentOrderId = orderId;
            
            // Show loading indicator
            const printBtn = event ? event.target.closest('button') : null;
            if (printBtn) {
                const originalHTML = printBtn.innerHTML;
                printBtn.innerHTML = '<i class="bi bi-printer"></i> Printing...';
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
                    
                    // Create iframe for printing - PARA HINDI LUMIPAT NG PAGE
                    const iframe = document.createElement('iframe');
                    iframe.style.position = 'absolute';
                    iframe.style.width = '0';
                    iframe.style.height = '0';
                    iframe.style.border = 'none';
                    iframe.style.top = '-9999px';
                    iframe.style.left = '-9999px';
                    document.body.appendChild(iframe);
                    
                    // Generate HTML content
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
        
        // PRINT ALL ORDERS - REKTA PRINT NA, WALANG PREVIEW, HINDI LILIPAT NG PAGE
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
            
            // Create iframe for printing - PARA HINDI LUMIPAT NG PAGE
            const iframe = document.createElement('iframe');
            iframe.style.position = 'absolute';
            iframe.style.width = '0';
            iframe.style.height = '0';
            iframe.style.border = 'none';
            iframe.style.top = '-9999px';
            iframe.style.left = '-9999px';
            document.body.appendChild(iframe);
            
            // Generate HTML content
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
        
        // Generate HTML for single order
        function generateSingleOrderHTML(order, items) {
            let itemsHtml = '';
            let totalAmount = 0;
            
            if (items && items.length > 0) {
                itemsHtml = items.map(item => {
                    totalAmount += parseFloat(item.line_total);
                    return `
                        <tr>
                            <td style="padding: 8px; border: 1px solid #ddd;">${item.item_name}</td>
                            <td style="padding: 8px; border: 1px solid #ddd; text-align: center;">${item.quantity_ordered}</td>
                            <td style="padding: 8px; border: 1px solid #ddd; text-align: right;">₱${parseFloat(item.unit_price).toFixed(2)}</td>
                            <td style="padding: 8px; border: 1px solid #ddd; text-align: right;">₱${parseFloat(item.line_total).toFixed(2)}</td>
                        </tr>
                    `;
                }).join('');
            }
            
            return `
                <!DOCTYPE html>
                <html>
                <head>
                    <meta charset="UTF-8">
                    <meta name="viewport" content="width=device-width, initial-scale=1.0">
                    <title>Order #${order.so_number}</title>
                    <style>
                        @page {
                            size: portrait;
                            margin: 1cm;
                        }
                        body {
                            font-family: 'Courier New', Courier, monospace;
                            margin: 0;
                            padding: 20px;
                            color: #000;
                            font-size: 12px;
                            line-height: 1.4;
                        }
                        .print-container {
                            max-width: 800px;
                            margin: 0 auto;
                        }
                        .header {
                            text-align: center;
                            border-bottom: 2px solid #000;
                            padding-bottom: 10px;
                            margin-bottom: 20px;
                        }
                        .company-name {
                            font-size: 24px;
                            font-weight: bold;
                            margin-bottom: 5px;
                        }
                        .order-title {
                            font-size: 16px;
                            font-weight: bold;
                            text-transform: uppercase;
                        }
                        .info-row {
                            display: flex;
                            margin-bottom: 5px;
                        }
                        .info-label {
                            width: 120px;
                            font-weight: bold;
                        }
                        .section {
                            margin-bottom: 20px;
                            padding: 10px;
                            border: 1px solid #000;
                        }
                        .section-title {
                            font-weight: bold;
                            margin-bottom: 10px;
                            border-bottom: 1px solid #000;
                            padding-bottom: 5px;
                            text-transform: uppercase;
                            font-size: 13px;
                        }
                        table {
                            width: 100%;
                            border-collapse: collapse;
                            margin: 10px 0;
                        }
                        th {
                            background: #f0f0f0;
                            border: 1px solid #000;
                            padding: 8px;
                            text-align: left;
                            font-weight: bold;
                        }
                        td {
                            border: 1px solid #000;
                            padding: 8px;
                        }
                        .text-right {
                            text-align: right;
                        }
                        .text-center {
                            text-align: center;
                        }
                        .total-row {
                            font-weight: bold;
                            background: #f0f0f0;
                        }
                        .footer {
                            margin-top: 30px;
                            text-align: center;
                            font-size: 11px;
                            border-top: 1px solid #000;
                            padding-top: 10px;
                        }
                        .branch-info {
                            margin-top: 10px;
                            font-size: 11px;
                        }
                    </style>
                </head>
                <body>
                    <div class="print-container">
                        <div class="header">
                            <div class="company-name">AMGC</div>
                            <div class="order-title">SALES ORDER</div>
                            <div style="margin-top: 5px; font-size: 11px;">${order.so_number}</div>
                            ${order.branch_name ? `<div class="branch-info">Branch: ${order.branch_name}</div>` : ''}
                        </div>
                        
                        <div style="margin-bottom: 20px; display: flex; justify-content: space-between;">
                            <div>
                                <div class="info-row"><span class="info-label">Order Date:</span> <span>${new Date(order.order_date).toLocaleString()}</span></div>
                                <div class="info-row"><span class="info-label">Status:</span> <span style="text-transform: uppercase;">${order.order_status}</span></div>
                                <div class="info-row"><span class="info-label">Created By:</span> <span>${order.created_by || 'System'}</span></div>
                            </div>
                        </div>
                        
                        <div class="section">
                            <div class="section-title">CUSTOMER INFORMATION</div>
                            <div class="info-row"><span class="info-label">Name:</span> <span>${order.customer_name}</span></div>
                            <div class="info-row"><span class="info-label">Email:</span> <span>${order.email || 'N/A'}</span></div>
                            <div class="info-row"><span class="info-label">Phone:</span> <span>${order.phone_number || 'N/A'}</span></div>
                            <div class="info-row"><span class="info-label">Address:</span> <span>${order.address || 'N/A'}</span></div>
                        </div>
                        
                        <div class="section">
                            <div class="section-title">ORDER ITEMS</div>
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
                                        <td colspan="3" style="text-align: right;"><strong>GRAND TOTAL</strong></td>
                                        <td style="text-align: right;"><strong>₱${parseFloat(order.total_amount).toFixed(2)}</strong></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        
                        <div class="footer">
                            <div>Printed on: ${new Date().toLocaleString()}</div>
                            <div style="margin-top: 5px;">Prepared by: ${document.querySelector('.user-name-sidebar')?.textContent || 'Sales Staff'}</div>
                        </div>
                    </div>
                </body>
                </html>
            `;
        }
        
        // Generate HTML for all orders
        function generateAllOrdersHTML(rows) {
            let tableRows = '';
            let totalAmount = 0;
            
            rows.forEach(row => {
                const cells = row.querySelectorAll('td');
                const hasBranchColumn = branchColumnExists && viewAllBranches;
                const colOffset = hasBranchColumn ? 1 : 0;
                
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
                    tableRows += `<td style="padding: 6px; border: 1px solid #000;">${orderNumber}</td>`;
                    tableRows += `<td style="padding: 6px; border: 1px solid #000;">${date}</td>`;
                    tableRows += `<td style="padding: 6px; border: 1px solid #000;">${customer}</td>`;
                    if (hasBranchColumn) {
                        tableRows += `<td style="padding: 6px; border: 1px solid #000;">${branch}</td>`;
                    }
                    tableRows += `<td style="padding: 6px; border: 1px solid #000; text-align: center;">${items}</td>`;
                    tableRows += `<td style="padding: 6px; border: 1px solid #000; text-align: right;">${amount}</td>`;
                    tableRows += `<td style="padding: 6px; border: 1px solid #000;">${status}</td>`;
                    tableRows += '</tr>';
                }
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
                    <style>
                        @page {
                            size: landscape;
                            margin: 1cm;
                        }
                        body {
                            font-family: 'Courier New', Courier, monospace;
                            margin: 0;
                            padding: 20px;
                            color: #000;
                            font-size: 11px;
                        }
                        .print-container {
                            max-width: 100%;
                            margin: 0 auto;
                        }
                        .header {
                            text-align: center;
                            border-bottom: 2px solid #000;
                            padding-bottom: 10px;
                            margin-bottom: 20px;
                        }
                        .company-name {
                            font-size: 22px;
                            font-weight: bold;
                        }
                        .report-title {
                            font-size: 16px;
                            font-weight: bold;
                            text-transform: uppercase;
                        }
                        .summary {
                            display: flex;
                            justify-content: space-between;
                            margin-bottom: 20px;
                            padding: 10px;
                            border: 1px solid #000;
                            background: #f9f9f9;
                        }
                        table {
                            width: 100%;
                            border-collapse: collapse;
                            margin: 20px 0;
                        }
                        th {
                            background: #e0e0e0;
                            border: 1px solid #000;
                            padding: 8px;
                            text-align: left;
                            font-weight: bold;
                        }
                        td {
                            border: 1px solid #000;
                            padding: 6px;
                        }
                        .total-row {
                            font-weight: bold;
                            background: #f0f0f0;
                        }
                        .footer {
                            margin-top: 30px;
                            text-align: center;
                            font-size: 10px;
                            border-top: 1px solid #000;
                            padding-top: 10px;
                        }
                    </style>
                </head>
                <body>
                    <div class="print-container">
                        <div class="header">
                            <div class="company-name">AMGC</div>
                            <div class="report-title">SALES ORDERS REPORT</div>
                            ${!viewAllBranches && branchId > 0 ? `<div style="margin-top: 5px;">Branch ID: ${branchId}</div>` : ''}
                        </div>
                        
                        <div class="summary">
                            <div><strong>Total Orders:</strong> ${rows.length}</div>
                            <div><strong>Report Date:</strong> ${new Date().toLocaleDateString()}</div>
                            <div><strong>Report Time:</strong> ${new Date().toLocaleTimeString()}</div>
                            <div><strong>Total Amount:</strong> ₱${totalAmount.toFixed(2)}</div>
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
                                    <td colspan="${totalColspan}" style="text-align: right;"><strong>GRAND TOTAL</strong></td>
                                    <td style="text-align: right;"><strong>₱${totalAmount.toFixed(2)}</strong></td>
                                    <td></td>
                                </tr>
                            </tbody>
                        </table>
                        
                        <div class="footer">
                            <div>Printed on: ${new Date().toLocaleString()}</div>
                            <div style="margin-top: 5px;">Prepared by: ${document.querySelector('.user-name-sidebar')?.textContent || 'Sales Staff'}</div>
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
        
        // Export to Excel - EXCEL FILE LANG, HINDI CSV
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