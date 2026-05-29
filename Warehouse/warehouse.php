<?php
// Turn on error reporting for debugging (remove in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

try {
    require_once '../config/database.php';
    require_once '../config/session_handler.php';
} catch (Throwable $e) {
    die('Error loading required files: ' . $e->getMessage());
}

// Protect page - only Warehouse role can access
try {
    requireLogin();
    requireRole(['warehouse']);
} catch (Throwable $e) {
    die('Authentication error: ' . $e->getMessage());
}

// Get current user info and branch context
$user_id = $_SESSION['user_id'] ?? 0;
$user_name = isset($_SESSION['first_name']) ? $_SESSION['first_name'] . ' ' . $_SESSION['last_name'] : 'Warehouse User';
$user_role = isset($_SESSION['role']) ? $_SESSION['role'] : 'warehouse';
$user_branch_id = $_SESSION['branch_id'] ?? 0;
$view_all_branches = $_SESSION['view_all_branches'] ?? false;

// IMPORTANT: Get user's category directly from database
$user_category = '';
try {
    $cat_query = "SELECT category FROM users WHERE user_id = ?";
    $cat_stmt = $conn->prepare($cat_query);
    if ($cat_stmt) {
        $cat_stmt->bind_param("i", $user_id);
        $cat_stmt->execute();
        $cat_result = $cat_stmt->get_result();
        if ($cat_row = $cat_result->fetch_assoc()) {
            $user_category = $cat_row['category'];
        }
        $cat_stmt->close();
    }
} catch (Throwable $e) {
    // Silently ignore – category remains empty
}

// Get user's branch name for display
$branch_name = 'All Branches';
try {
    if (!$view_all_branches && $user_branch_id > 0) {
        $branch_query = "SELECT branch_name FROM branches WHERE branch_id = ?";
        $branch_stmt = $conn->prepare($branch_query);
        if ($branch_stmt) {
            $branch_stmt->bind_param("i", $user_branch_id);
            $branch_stmt->execute();
            $branch_result = $branch_stmt->get_result();
            if ($branch_row = $branch_result->fetch_assoc()) {
                $branch_name = $branch_row['branch_name'];
            }
            $branch_stmt->close();
        }
    }
} catch (Throwable $e) {}

// Helper function for safe queries (returns mysqli_result or false)
function safeQuery($conn, $sql, $params = null, $paramType = "i") {
    try {
        if ($params !== null) {
            $stmt = $conn->prepare($sql);
            if (!$stmt) return false;
            
            // Handle multiple parameters
            if (is_array($params)) {
                $types = str_repeat($paramType, count($params));
                $stmt->bind_param($types, ...$params);
            } else {
                $stmt->bind_param($paramType, $params);
            }
            
            $stmt->execute();
            $result = $stmt->get_result();
            $stmt->close();
            return $result;
        } else {
            return $conn->query($sql);
        }
    } catch (Throwable $e) {
        error_log("Query error: " . $e->getMessage() . " SQL: " . $sql);
        return false;
    }
}

// Check table existence (to avoid errors)
$tables_exist = [
    'purchase_orders' => $conn->query("SHOW TABLES LIKE 'purchase_orders'")->num_rows > 0,
    'trip_tickets'    => $conn->query("SHOW TABLES LIKE 'trip_tickets'")->num_rows > 0,
    'pick_lists'      => $conn->query("SHOW TABLES LIKE 'pick_lists'")->num_rows > 0,
    'items'           => $conn->query("SHOW TABLES LIKE 'items'")->num_rows > 0,
    'drivers'         => $conn->query("SHOW TABLES LIKE 'drivers'")->num_rows > 0,
    'branches'        => $conn->query("SHOW TABLES LIKE 'branches'")->num_rows > 0,
    'sales_orders'    => $conn->query("SHOW TABLES LIKE 'sales_orders'")->num_rows > 0,
];

// Check column existence (used in queries)
$po_has_branch      = $conn->query("SHOW COLUMNS FROM purchase_orders LIKE 'branch_id'")->num_rows > 0;
$tt_has_branch      = $conn->query("SHOW COLUMNS FROM trip_tickets LIKE 'branch_id'")->num_rows > 0;
$pl_has_release     = $conn->query("SHOW COLUMNS FROM pick_lists LIKE 'release_status'")->num_rows > 0;
$items_has_branch   = $conn->query("SHOW COLUMNS FROM items LIKE 'branch_id'")->num_rows > 0;
$so_has_branch      = $conn->query("SHOW COLUMNS FROM sales_orders LIKE 'branch_id'")->num_rows > 0;

// Initialize stats
$stats = [
    'purchase_orders' => 0,
    'pending_pickup'   => 0,
    'for_release'      => 0,
    'completed_today'  => 0
];

// 1. PURCHASE ORDERS COUNT - Active POs not completed/cancelled
if ($tables_exist['purchase_orders']) {
    $po_sql = "SELECT COUNT(DISTINCT po.po_id) as cnt
               FROM purchase_orders po
               WHERE po.po_status NOT IN ('cancelled')";
    
    // Add category filter if user has specific category
    if (!empty($user_category)) {
        $po_sql .= " AND EXISTS (
            SELECT 1 FROM purchase_order_items poi 
            JOIN items i ON poi.item_id = i.item_id 
            WHERE poi.po_id = po.po_id 
            AND i.category = '" . $conn->real_escape_string($user_category) . "'
        )";
    }
    
    // Add branch filter
    if (!$view_all_branches && $user_branch_id > 0 && $po_has_branch) {
        $po_sql .= " AND po.branch_id = ?";
        $result = safeQuery($conn, $po_sql, $user_branch_id);
    } else {
        $result = $conn->query($po_sql);
    }
    $stats['purchase_orders'] = ($result && $row = $result->fetch_assoc()) ? (int)$row['cnt'] : 0;
}

// 2. PENDING PICKUP COUNT - Orders that are confirmed but not yet picked
if ($tables_exist['sales_orders']) {
    $pickup_sql = "SELECT COUNT(DISTINCT so.so_id) as cnt
                   FROM sales_orders so
                   LEFT JOIN pick_lists pl ON so.so_id = pl.so_id
                   WHERE so.order_status = 'confirmed'
                   AND (pl.pick_list_id IS NULL OR pl.pick_status != 'completed')";
    
    // Add category filter if user has specific category
    if (!empty($user_category)) {
        $pickup_sql .= " AND EXISTS (
            SELECT 1 FROM sales_order_items soi 
            JOIN items i ON soi.item_id = i.item_id 
            WHERE soi.so_id = so.so_id 
            AND i.category = '" . $conn->real_escape_string($user_category) . "'
        )";
    }
    
    // Add branch filter
    if (!$view_all_branches && $user_branch_id > 0 && $so_has_branch) {
        $pickup_sql .= " AND so.branch_id = ?";
        $result = safeQuery($conn, $pickup_sql, $user_branch_id);
    } else {
        $result = $conn->query($pickup_sql);
    }
    $stats['pending_pickup'] = ($result && $row = $result->fetch_assoc()) ? (int)$row['cnt'] : 0;
}

// 3. FOR RELEASE COUNT - Pick lists that are completed (picked) and waiting for driver pickup
if ($tables_exist['pick_lists'] && $tables_exist['sales_orders']) {
    $release_sql = "SELECT COUNT(DISTINCT pl.pick_list_id) as cnt
                    FROM pick_lists pl
                    LEFT JOIN sales_orders so ON pl.so_id = so.so_id
                    WHERE LOWER(TRIM(pl.pick_status)) = 'completed'
                    AND LOWER(TRIM(so.order_status)) = 'ready'";
    
    // Add category filter if user has specific category
    if (!empty($user_category)) {
        $release_sql .= " AND EXISTS (
            SELECT 1 FROM pick_list_items pli 
            JOIN items i ON pli.item_id = i.item_id 
            WHERE pli.pick_list_id = pl.pick_list_id 
            AND i.category = '" . $conn->real_escape_string($user_category) . "'
        )";
    }
    
    // Add branch filter
    if (!$view_all_branches && $user_branch_id > 0) {
        // Check if branch_id column exists in pick_lists
        $check_branch = $conn->query("SHOW COLUMNS FROM pick_lists LIKE 'branch_id'");
        if ($check_branch && $check_branch->num_rows > 0) {
            $release_sql .= " AND pl.branch_id = " . intval($user_branch_id);
            $result = $conn->query($release_sql);
        } else {
            $result = $conn->query($release_sql);
        }
    } else {
        $result = $conn->query($release_sql);
    }
    
    $stats['for_release'] = ($result && $row = $result->fetch_assoc()) ? (int)$row['cnt'] : 0;
}

// 4. COMPLETED TODAY COUNT - Pick lists completed today
if ($tables_exist['pick_lists']) {
    $today_sql = "SELECT COUNT(DISTINCT pl.pick_list_id) as cnt
                  FROM pick_lists pl
                  WHERE pl.pick_status = 'completed'
                  AND DATE(pl.updated_at) = CURDATE()";
    
    // Add category filter if user has specific category
    if (!empty($user_category)) {
        $today_sql .= " AND EXISTS (
            SELECT 1 FROM pick_list_items pli 
            JOIN items i ON pli.item_id = i.item_id 
            WHERE pli.pick_list_id = pl.pick_list_id 
            AND i.category = '" . $conn->real_escape_string($user_category) . "'
        )";
    }
    
    // Add branch filter
    if (!$view_all_branches && $user_branch_id > 0) {
        $today_sql .= " AND pl.branch_id = ?";
        $result = safeQuery($conn, $today_sql, $user_branch_id);
    } else {
        $result = $conn->query($today_sql);
    }
    $stats['completed_today'] = ($result && $row = $result->fetch_assoc()) ? (int)$row['cnt'] : 0;
}

// GET PENDING TASKS FOR MODAL
// Tasks are only shown if they are NOT yet processed (PO not yet received, Pick List not yet completed)
$pending_tasks = [
    'purchase_orders' => [],
    'pending_pickup' => []
];

// Get purchase orders that need processing (submitted or approved, but not yet received)
if ($tables_exist['purchase_orders']) {
    $pending_po_sql = "SELECT po.po_id, po.po_number, po.order_date, po.po_status, 
                              s.supplier_name, b.branch_name,
                              (SELECT COUNT(*) FROM purchase_order_items WHERE po_id = po.po_id) as item_count
                       FROM purchase_orders po
                       LEFT JOIN suppliers s ON po.supplier_id = s.supplier_id
                       LEFT JOIN branches b ON po.branch_id = b.branch_id
                       WHERE po.po_status IN ('submitted', 'approved')
                       AND po.po_status != 'received'";
    
    if (!empty($user_category)) {
        $pending_po_sql .= " AND EXISTS (
            SELECT 1 FROM purchase_order_items poi 
            JOIN items i ON poi.item_id = i.item_id 
            WHERE poi.po_id = po.po_id 
            AND i.category = '" . $conn->real_escape_string($user_category) . "'
        )";
    }
    
    if (!$view_all_branches && $user_branch_id > 0 && $po_has_branch) {
        $pending_po_sql .= " AND po.branch_id = " . intval($user_branch_id);
    }
    
    $pending_po_sql .= " ORDER BY po.order_date DESC LIMIT 10";
    $result = $conn->query($pending_po_sql);
    
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $pending_tasks['purchase_orders'][] = $row;
        }
    }
}

// Get pending pick lists that need to be processed (confirmed orders WITHOUT a completed pick list)
if ($tables_exist['sales_orders'] && $tables_exist['pick_lists']) {
    $pending_pickup_sql = "SELECT so.so_id, so.so_number, so.order_date, so.total_amount,
                                  b.branch_name, c.customer_name,
                                  (SELECT COUNT(*) FROM sales_order_items WHERE so_id = so.so_id) as item_count,
                                  pl.pick_list_id, pl.pick_status
                           FROM sales_orders so
                           LEFT JOIN branches b ON so.branch_id = b.branch_id
                           LEFT JOIN customers c ON so.customer_id = c.customer_id
                           LEFT JOIN pick_lists pl ON so.so_id = pl.so_id
                           WHERE so.order_status = 'confirmed'
                           AND (pl.pick_list_id IS NULL OR pl.pick_status != 'completed')";
    
    if (!empty($user_category)) {
        $pending_pickup_sql .= " AND EXISTS (
            SELECT 1 FROM sales_order_items soi 
            JOIN items i ON soi.item_id = i.item_id 
            WHERE soi.so_id = so.so_id 
            AND i.category = '" . $conn->real_escape_string($user_category) . "'
        )";
    }
    
    if (!$view_all_branches && $user_branch_id > 0 && $so_has_branch) {
        $pending_pickup_sql .= " AND so.branch_id = " . intval($user_branch_id);
    }
    
    $pending_pickup_sql .= " ORDER BY so.order_date DESC LIMIT 10";
    $result = $conn->query($pending_pickup_sql);
    
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $pending_tasks['pending_pickup'][] = $row;
        }
    }
}

// Check if there are any tasks to show
$has_tasks = (count($pending_tasks['purchase_orders']) > 0) || (count($pending_tasks['pending_pickup']) > 0);

// Show a warning if any important tables are missing
$missing_tables = array_keys(array_filter($tables_exist, fn($v) => !$v));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Warehouse Manager <?php echo !$view_all_branches ? '- ' . htmlspecialchars($branch_name) : ''; ?></title>
    <link rel="icon" type="image/png" href="../Pictures/favicon-96x96.png" sizes="96x96" />
    <link rel="icon" type="image/svg+xml" href="../Pictures/favicon.svg" />
    <link rel="shortcut icon" href="../Pictures/favicon.ico" />
    <link rel="apple-touch-icon" sizes="180x180" href="../Pictures/apple-touch-icon.png" />
    <link rel="manifest" href="../Pictures/site.webmanifest" />
    <link rel="stylesheet" href="../css/warehouse.css">
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        .stat-card {
            cursor: pointer;
            transition: transform 0.2s;
        }
        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        .error-box {
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        .modal-table {
            max-height: 400px;
            overflow-y: auto;
        }
        .debug-info {
            background: #e7f3ff;
            border: 1px solid #b8daff;
            color: #004085;
            padding: 5px 10px;
            border-radius: 4px;
            margin-bottom: 10px;
            font-size: 0.9em;
        }
        /* Order status badges */
        .order-status-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 500;
        }
        .status-ready { background-color: #d4edda; color: #155724; }
        .status-confirmed { background-color: #cce5ff; color: #004085; }
        .status-pending { background-color: #fff3cd; color: #856404; }
        .status-completed { background-color: #d1e7dd; color: #0a3622; }
        .status-delivered { background-color: #d1e7dd; color: #0a3622; }
        .status-cancelled { background-color: #f8d7da; color: #721c24; }
        
        /* Task Modal Styles */
        .task-modal .modal-content {
            border-radius: 15px;
            overflow: hidden;
        }
        .task-modal-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px 25px;
            border-bottom: none;
        }
        .task-item {
            border-left: 4px solid #667eea;
            transition: all 0.3s ease;
            margin-bottom: 15px;
            background: #f8f9fa;
            border-radius: 10px;
            padding: 15px;
        }
        .task-item:hover {
            transform: translateX(5px);
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .task-item.po-task {
            border-left-color: #ffc107;
        }
        .task-item.pickup-task {
            border-left-color: #28a745;
        }
        .task-action-btn {
            margin-top: 10px;
        }
        .task-count-badge {
            background: #dc3545;
            color: white;
            border-radius: 20px;
            padding: 2px 8px;
            font-size: 12px;
            margin-left: 8px;
        }
        .task-status-badge {
            font-size: 11px;
            padding: 2px 6px;
            border-radius: 10px;
        }
        .task-status-pending {
            background: #ffc107;
            color: #000;
        }
        .task-status-inprogress {
            background: #17a2b8;
            color: #fff;
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
                    <span class="nav-text">Warehouse</span>
                </h3>
            </div>
            
            <div class="sidebar-menu">
                <ul class="nav flex-column">
                    <li class="nav-item">
                        <a class="nav-link active" href="warehouse.php">
                            <i class="bi bi-speedometer2"></i>
                            <span class="nav-text">Dashboard</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="currentinventory.php">
                            <i class="bi bi-boxes"></i>
                            <span class="nav-text">Current Inventory</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="pick_list_items.php">
                            <i class="bi bi-clipboard-check"></i>
                            <span class="nav-text">Pick List Items</span>
                        </a>    
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="purchase_order.php">
                            <i class="bi bi-receipt"></i>
                            <span class="nav-text">Purchase Orders</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="drivers.php">
                            <i class="bi bi-person-badge"></i>
                            <span class="nav-text">Drivers</span>
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
            <!-- Header Section -->
            <div class="navbar-top">
                <button class="mobile-toggle-btn" id="mobileToggleBtn">
                    <i class="bi bi-list"></i>
                </button>
                <div class="page-title">
                    <h2>Warehouse Dashboard</h2>
                    <p>Monitor inventory, shipments, and delivery operations</p>
                </div>
            </div>

            <?php if (!empty($missing_tables)): ?>
            <div class="error-box">
                <strong>Note:</strong> The following tables are missing: <?php echo implode(', ', $missing_tables); ?>. 
                Some data may be unavailable. Please check your database.
            </div>
            <?php endif; ?>

            <!-- Stat Cards -->
            <div class="row stat-card-row g-2 mb-4">
                <!-- Purchase Orders -->
                <div class="col-12 col-sm-6 col-lg-3">
                    <div class="stat-card inventory" role="button" tabindex="0" data-bs-toggle="modal" data-bs-target="#poModal">
                        <i class="bi bi-receipt"></i>
                        <div class="stat-content">
                            <div class="stat-value"><?php echo $stats['purchase_orders']; ?></div>
                            <div class="stat-label">Purchase Orders</div>
                        </div>
                    </div>
                </div>

                <!-- Pending Pickup (Confirmed Orders) -->
                <div class="col-12 col-sm-6 col-lg-3">
                    <div class="stat-card stock" role="button" tabindex="0" data-bs-toggle="modal" data-bs-target="#pickupModal">
                        <i class="bi bi-truck"></i>
                        <div class="stat-content">
                            <div class="stat-value"><?php echo $stats['pending_pickup']; ?></div>
                            <div class="stat-label">Pending Pickup</div>
                        </div>
                    </div>
                </div>

                <!-- For Release (Picked Items Waiting for Driver) -->
                <div class="col-12 col-sm-6 col-lg-3">
                    <div class="stat-card delivery" role="button" tabindex="0" data-bs-toggle="modal" data-bs-target="#releaseModal">
                        <i class="bi bi-box-seam"></i>
                        <div class="stat-content">
                            <div class="stat-value"><?php echo $stats['for_release']; ?></div>
                            <div class="stat-label">For Release</div>
                        </div>
                    </div>
                </div>

                <!-- Completed Today -->
                <div class="col-12 col-sm-6 col-lg-3">
                    <div class="stat-card approved" role="button" tabindex="0" data-bs-toggle="modal" data-bs-target="#todayModal">
                        <i class="bi bi-check-circle"></i>
                        <div class="stat-content">
                            <div class="stat-value"><?php echo $stats['completed_today']; ?></div>
                            <div class="stat-label">Completed Today</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- TASK MODAL - Shows pending tasks on page load -->
            <div class="modal fade" id="taskModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
                <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
                    <div class="modal-content task-modal">
                        <div class="modal-header task-modal-header">
                            <h5 class="modal-title">
                                <i class="bi bi-list-check me-2"></i>
                                Your Pending Tasks
                                <?php if ($has_tasks): ?>
                                    <span class="task-count-badge">
                                        <?php echo (count($pending_tasks['purchase_orders']) + count($pending_tasks['pending_pickup'])); ?>
                                    </span>
                                <?php endif; ?>
                            </h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <?php if (!$has_tasks): ?>
                                <div class="text-center py-5">
                                    <i class="bi bi-check-circle-fill text-success" style="font-size: 64px;"></i>
                                    <h4 class="mt-3">No Pending Tasks!</h4>
                                    <p class="text-muted">All caught up! You have no pending tasks to complete.</p>
                                </div>
                            <?php else: ?>
                                <!-- Purchase Orders Tasks -->
                                <?php if (count($pending_tasks['purchase_orders']) > 0): ?>
                                    <div class="mb-4">
                                        <h6 class="fw-bold text-warning mb-3">
                                            <i class="bi bi-receipt me-2"></i>
                                            Purchase Orders to Process
                                            <span class="badge bg-warning ms-2"><?php echo count($pending_tasks['purchase_orders']); ?></span>
                                        </h6>
                                        <?php foreach ($pending_tasks['purchase_orders'] as $po): ?>
                                            <div class="task-item po-task" data-task-id="po_<?php echo $po['po_id']; ?>" data-task-type="po">
                                                <div class="d-flex justify-content-between align-items-start">
                                                    <div class="flex-grow-1">
                                                        <div class="d-flex align-items-center mb-2">
                                                            <strong class="me-2"><?php echo htmlspecialchars($po['po_number']); ?></strong>
                                                        </div>
                                                        <small class="text-muted d-block">
                                                            <i class="bi bi-calendar"></i> <?php echo date('Y-m-d', strtotime($po['order_date'])); ?>
                                                        </small>
                                                        <div class="mt-1">
                                                            <span class="badge bg-<?php echo $po['po_status'] == 'submitted' ? 'info' : 'primary'; ?>">
                                                                <?php echo ucfirst($po['po_status']); ?>
                                                            </span>
                                                            <small class="text-muted ms-2">
                                                                <i class="bi bi-box"></i> <?php echo $po['item_count']; ?> items
                                                            </small>
                                                        </div>
                                                    </div>
                                                    <button class="btn btn-sm btn-primary task-action-btn" onclick="redirectToTask('po', <?php echo $po['po_id']; ?>, '<?php echo htmlspecialchars($po['po_number']); ?>')">
                                                        <i class="bi bi-arrow-right"></i> View & Process
                                                    </button>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                                
                                <!-- Pending Pickup Tasks -->
                                <?php if (count($pending_tasks['pending_pickup']) > 0): ?>
                                    <div>
                                        <h6 class="fw-bold text-success mb-3">
                                            <i class="bi bi-truck me-2"></i>
                                            Orders Ready for Pickup
                                            <span class="badge bg-success ms-2"><?php echo count($pending_tasks['pending_pickup']); ?></span>
                                        </h6>
                                        <?php foreach ($pending_tasks['pending_pickup'] as $order): ?>
                                            <div class="task-item pickup-task" data-task-id="pickup_<?php echo $order['so_id']; ?>" data-task-type="pickup">
                                                <div class="d-flex justify-content-between align-items-start">
                                                    <div class="flex-grow-1">
                                                        <div class="d-flex align-items-center mb-2">
                                                            <strong class="me-2"><?php echo htmlspecialchars($order['so_number']); ?></strong>
                                                        </div>
                                                        <small class="text-muted d-block">
                                                            <i class="bi bi-calendar"></i> <?php echo date('Y-m-d', strtotime($order['order_date'])); ?>
                                                        </small>
                                                        <div class="mt-1">
                                                            <span class="badge bg-success">Confirmed</span>
                                                            <small class="text-muted ms-2">
                                                                <i class="bi bi-box"></i> <?php echo $order['item_count']; ?> items | 
                                                            </small>
                                                        </div>
                                                    </div>
                                                    <button class="btn btn-sm btn-success task-action-btn" onclick="redirectToTask('pickup', <?php echo $order['so_id']; ?>, '<?php echo htmlspecialchars($order['so_number']); ?>')">
                                                        <i class="bi bi-arrow-right"></i> Start
                                                    </button>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                                
                                <div class="alert alert-info mt-3 mb-0">
                                    <i class="bi bi-info-circle me-2"></i>
                                    <small>Tasks will automatically disappear from this list once they are fully processed (PO received or Pick List completed).</small>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                            <button type="button" class="btn btn-primary" onclick="dontShowAgain()">
                                <i class="bi bi-check-lg"></i> Don't show again today
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Purchase Orders Modal -->
            <div class="modal fade" id="poModal" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-lg modal-dialog-scrollable">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title"><i class="bi bi-receipt me-2"></i>Purchase Orders</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <div class="modal-table">
                                <table class="table table-sm table-hover">
                                    <thead>
                                        <tr>
                                            <th>PO #</th>
                                            <?php if ($view_all_branches): ?><th>Branch</th><?php endif; ?>
                                            <th>Supplier</th>
                                            <th>Order Date</th>
                                            <th>Expected Delivery</th>
                                            <th>Status</th>
                                            <th>Total Items</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        if ($tables_exist['purchase_orders']) {
                                            $po_detail_sql = "SELECT po.po_id, po.po_number, po.order_date, po.expected_delivery, po.po_status, 
                                                                    s.supplier_name, b.branch_name,
                                                                    (SELECT COUNT(*) FROM purchase_order_items WHERE po_id = po.po_id) as item_count
                                                              FROM purchase_orders po
                                                              LEFT JOIN suppliers s ON po.supplier_id = s.supplier_id
                                                              LEFT JOIN branches b ON po.branch_id = b.branch_id
                                                              WHERE po.po_status NOT IN ('cancelled')";
                                            
                                            if (!empty($user_category)) {
                                                $po_detail_sql .= " AND EXISTS (
                                                    SELECT 1 FROM purchase_order_items poi 
                                                    JOIN items i ON poi.item_id = i.item_id 
                                                    WHERE poi.po_id = po.po_id 
                                                    AND i.category = '" . $conn->real_escape_string($user_category) . "'
                                                )";
                                            }
                                            
                                            $po_detail_sql .= " GROUP BY po.po_id ORDER BY po.order_date DESC";
                                            
                                            if (!$view_all_branches && $user_branch_id > 0 && $po_has_branch) {
                                                $po_detail_sql .= " AND po.branch_id = ?";
                                                $result = safeQuery($conn, $po_detail_sql, $user_branch_id);
                                            } else {
                                                $result = $conn->query($po_detail_sql);
                                            }
                                            
                                            if ($result && $result->num_rows > 0) {
                                                while ($row = $result->fetch_assoc()) {
                                                    $status_badge = match($row['po_status']) {
                                                        'draft' => 'bg-secondary',
                                                        'submitted' => 'bg-info',
                                                        'approved' => 'bg-primary',
                                                        'received' => 'bg-success',
                                                        default => 'bg-warning'
                                                    };
                                                    
                                                    echo '<tr>';
                                                    echo '<td>' . htmlspecialchars($row['po_number']) . '</td>';
                                                    if ($view_all_branches) {
                                                        echo '<td>' . htmlspecialchars($row['branch_name'] ?? 'N/A') . '</td>';
                                                    }
                                                    echo '<td>' . htmlspecialchars($row['supplier_name'] ?? 'N/A') . '</td>';
                                                    echo '<td>' . date('Y-m-d', strtotime($row['order_date'])) . '</td>';
                                                    echo '<td>' . ($row['expected_delivery'] ? date('Y-m-d', strtotime($row['expected_delivery'])) : 'N/A') . '</td>';
                                                    echo '<td><span class="badge ' . $status_badge . '">' . ucfirst($row['po_status']) . '</span></td>';
                                                    echo '<td>' . $row['item_count'] . '</td>';
                                                    echo '</tr>';
                                                }
                                            } else {
                                                $colspan = $view_all_branches ? 7 : 6;
                                                echo '<tr><td colspan="' . $colspan . '" class="text-center">No purchase orders found</td></tr>';
                                            }
                                        } else {
                                            echo '<tr><td colspan="7" class="text-center">Table not available</td></tr>';
                                        }
                                        ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Pending Pickup Modal (Confirmed Orders) -->
            <div class="modal fade" id="pickupModal" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-xl modal-dialog-scrollable">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title"><i class="bi bi-truck me-2"></i>Pending Pickup (Confirmed Orders)</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <div class="modal-table">
                                <table class="table table-sm table-hover">
                                    <thead>
                                        <tr>
                                            <th>SO #</th>
                                            <th>Order Date</th>
                                            <th>Customer</th>
                                            <th>Branch</th>
                                            <th>Total Amount</th>
                                            <th>Items</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        if ($tables_exist['sales_orders']) {
                                            $pickup_detail_sql = "SELECT
                                                so.so_id,
                                                so.so_number, 
                                                so.order_date,
                                                so.total_amount,
                                                b.branch_name,
                                                c.customer_name,
                                                (SELECT COUNT(*) FROM sales_order_items WHERE so_id = so.so_id) as item_count,
                                                pl.pick_list_id,
                                                pl.pick_status
                                              FROM sales_orders so
                                              LEFT JOIN branches b ON so.branch_id = b.branch_id
                                              LEFT JOIN customers c ON so.customer_id = c.customer_id
                                              LEFT JOIN pick_lists pl ON so.so_id = pl.so_id
                                              WHERE so.order_status = 'confirmed'
                                              AND (pl.pick_list_id IS NULL OR pl.pick_status != 'completed')";
                                            
                                            if (!empty($user_category)) {
                                                $pickup_detail_sql .= " AND EXISTS (
                                                    SELECT 1 FROM sales_order_items soi 
                                                    JOIN items i ON soi.item_id = i.item_id 
                                                    WHERE soi.so_id = so.so_id 
                                                    AND i.category = '" . $conn->real_escape_string($user_category) . "'
                                                )";
                                            }
                                            
                                            if (!$view_all_branches && $user_branch_id > 0 && $so_has_branch) {
                                                $pickup_detail_sql .= " AND so.branch_id = " . intval($user_branch_id);
                                            }
                                            
                                            $pickup_detail_sql .= " GROUP BY so.so_id ORDER BY so.order_date DESC";
                                            
                                            $result = $conn->query($pickup_detail_sql);
                                            
                                            if ($result && $result->num_rows > 0) {
                                                while ($row = $result->fetch_assoc()) {
                                                    $status_text = $row['pick_status'] ?? 'Not Started';
                                                    $status_badge = match($status_text) {
                                                        'in-progress' => 'bg-info',
                                                        'completed' => 'bg-success',
                                                        default => 'bg-warning'
                                                    };
                                                    
                                                    echo '<tr>';
                                                    echo '<td>' . htmlspecialchars($row['so_number']) . '</td>';
                                                    echo '<td>' . date('Y-m-d', strtotime($row['order_date'])) . '</td>';
                                                    echo '<td>' . htmlspecialchars($row['customer_name'] ?? 'N/A') . '</td>';
                                                    echo '<td>' . htmlspecialchars($row['branch_name'] ?? 'N/A') . '</td>';
                                                    echo '<td>₱' . number_format($row['total_amount'], 2) . '</td>';
                                                    echo '<td>' . $row['item_count'] . '</td>';
                                                    echo '<td><span class="badge ' . $status_badge . '">' . ucfirst($status_text) . '</span></td>';
                                                    echo '</tr>';
                                                }
                                            } else {
                                                echo '<tr><td colspan="7" class="text-center">No pending pickups found</td></tr>';
                                            }
                                        } else {
                                            echo '<tr><td colspan="7" class="text-center">Sales orders table not available</td></tr>';
                                        }
                                        ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- For Release Modal (Completed Pick Lists Waiting for Driver) -->
            <div class="modal fade" id="releaseModal" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-xl modal-dialog-scrollable">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title"><i class="bi bi-box-seam me-2"></i>For Release (Picked Items Waiting for Driver)</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <div class="modal-table">
                                <table class="table table-sm table-hover">
                                    <thead>
                                        <tr>
                                            <th>Pick List #</th>
                                            <th>Pick Date</th>
                                            <th>Item Count</th>
                                            <th>Assigned Driver</th>
                                            <th>Order Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        if ($tables_exist['pick_lists'] && $tables_exist['sales_orders']) {
                                            $release_detail_sql = "SELECT 
                                                pl.pick_list_id,
                                                pl.pick_list_number, 
                                                pl.pick_date, 
                                                pl.updated_at,
                                                b.branch_name,
                                                so.so_id,
                                                so.so_number,
                                                so.order_status,
                                                c.customer_name,
                                                d.driver_name,
                                                (SELECT COUNT(*) FROM pick_list_items WHERE pick_list_id = pl.pick_list_id) as item_count
                                              FROM pick_lists pl
                                              LEFT JOIN branches b ON pl.branch_id = b.branch_id
                                              LEFT JOIN sales_orders so ON pl.so_id = so.so_id
                                              LEFT JOIN customers c ON so.customer_id = c.customer_id
                                              LEFT JOIN drivers d ON pl.driver_id = d.driver_id
                                              WHERE LOWER(TRIM(pl.pick_status)) = 'completed'
                                              AND LOWER(TRIM(so.order_status)) = 'ready'";
                                            
                                            if (!empty($user_category)) {
                                                $release_detail_sql .= " AND EXISTS (
                                                    SELECT 1 FROM pick_list_items pli 
                                                    JOIN items i ON pli.item_id = i.item_id 
                                                    WHERE pli.pick_list_id = pl.pick_list_id 
                                                    AND i.category = '" . $conn->real_escape_string($user_category) . "'
                                                )";
                                            }
                                            
                                            if (!$view_all_branches && $user_branch_id > 0) {
                                                $release_detail_sql .= " AND pl.branch_id = " . intval($user_branch_id);
                                            }
                                            
                                            $release_detail_sql .= " GROUP BY pl.pick_list_id ORDER BY pl.updated_at DESC";
                                            
                                            $result = $conn->query($release_detail_sql);
                                            
                                            if ($result && $result->num_rows > 0) {
                                                while ($row = $result->fetch_assoc()) {
                                                    $status_badge = '';
                                                    switch($row['order_status']) {
                                                        case 'ready': $status_badge = 'bg-success'; break;
                                                        case 'delivered': $status_badge = 'bg-info'; break;
                                                        case 'confirmed': $status_badge = 'bg-primary'; break;
                                                        default: $status_badge = 'bg-warning';
                                                    }
                                                    
                                                    echo '<tr>';
                                                    echo '<td>' . htmlspecialchars($row['pick_list_number']) . '</td>';
                                                    echo '<td>' . date('Y-m-d', strtotime($row['pick_date'])) . '</td>';
                                                    echo '<td>' . $row['item_count'] . '</td>';
                                                    echo '<td>' . htmlspecialchars($row['driver_name'] ?? 'Not Assigned') . '</td>';
                                                    echo '<td><span class="badge ' . $status_badge . '">' . ucfirst($row['order_status'] ?? 'N/A') . '</span></td>';
                                                    echo '</tr>';
                                                }
                                            } else {
                                                echo '<tr><td colspan="5" class="text-center">No items waiting for driver pickup</td></tr>';
                                            }
                                        } else {
                                            echo '<tr><td colspan="5" class="text-center">Tables not available</td></tr>';
                                        }
                                        ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Completed Today Modal -->
            <div class="modal fade" id="todayModal" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-xl modal-dialog-scrollable">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title"><i class="bi bi-check-circle me-2"></i>Completed Today (Pick Lists)</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <div class="modal-table">
                                <table class="table table-sm table-hover">
                                    <thead>
                                        <tr>
                                            <th>Pick List #</th>
                                            <?php if ($view_all_branches): ?><th>Branch</th><?php endif; ?>
                                            <th>SO #</th>
                                            <th>Customer</th>
                                            <th>Pick Date</th>
                                            <th>Item Count</th>
                                            <th>Driver</th>
                                            <th>Order Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        if ($tables_exist['pick_lists']) {
                                            $today_detail_sql = "SELECT 
                                                pl.pick_list_number, 
                                                pl.pick_date, 
                                                pl.updated_at,
                                                b.branch_name,
                                                so.so_number,
                                                so.order_status,
                                                c.customer_name,
                                                d.driver_name,
                                                (SELECT COUNT(*) FROM pick_list_items WHERE pick_list_id = pl.pick_list_id) as item_count
                                              FROM pick_lists pl
                                              LEFT JOIN branches b ON pl.branch_id = b.branch_id
                                              LEFT JOIN sales_orders so ON pl.so_id = so.so_id
                                              LEFT JOIN customers c ON so.customer_id = c.customer_id
                                              LEFT JOIN drivers d ON pl.driver_id = d.driver_id
                                              WHERE pl.pick_status = 'completed'
                                              AND DATE(pl.updated_at) = CURDATE()";
                                            
                                            if (!empty($user_category)) {
                                                $today_detail_sql .= " AND EXISTS (
                                                    SELECT 1 FROM pick_list_items pli 
                                                    JOIN items i ON pli.item_id = i.item_id 
                                                    WHERE pli.pick_list_id = pl.pick_list_id 
                                                    AND i.category = '" . $conn->real_escape_string($user_category) . "'
                                                )";
                                            }
                                            
                                            if (!$view_all_branches && $user_branch_id > 0) {
                                                $today_detail_sql .= " AND pl.branch_id = ?";
                                                $result = safeQuery($conn, $today_detail_sql, $user_branch_id);
                                            } else {
                                                $result = $conn->query($today_detail_sql);
                                            }
                                            
                                            if ($result && $result->num_rows > 0) {
                                                while ($row = $result->fetch_assoc()) {
                                                    $status_badge = '';
                                                    switch($row['order_status']) {
                                                        case 'ready': $status_badge = 'bg-success'; break;
                                                        case 'delivered': $status_badge = 'bg-info'; break;
                                                        default: $status_badge = 'bg-warning';
                                                    }
                                                    
                                                    echo '<tr>';
                                                    echo '<td>' . htmlspecialchars($row['pick_list_number']) . '</td>';
                                                    if ($view_all_branches) {
                                                        echo '<td>' . htmlspecialchars($row['branch_name'] ?? 'N/A') . '</td>';
                                                    }
                                                    echo '<td>' . htmlspecialchars($row['so_number'] ?? 'N/A') . '</td>';
                                                    echo '<td>' . htmlspecialchars($row['customer_name'] ?? 'N/A') . '</td>';
                                                    echo '<td>' . date('Y-m-d', strtotime($row['pick_date'])) . '</td>';
                                                    echo '<td>' . $row['item_count'] . '</td>';
                                                    echo '<td>' . htmlspecialchars($row['driver_name'] ?? 'Not Assigned') . '</td>';
                                                    echo '<td><span class="badge ' . $status_badge . '">' . ucfirst($row['order_status'] ?? 'N/A') . '</span></td>';
                                                    echo '</tr>';
                                                }
                                            } else {
                                                $colspan = $view_all_branches ? 8 : 7;
                                                echo '<tr><td colspan="' . $colspan . '" class="text-center">No pick lists completed today</td></tr>';
                                            }
                                        } else {
                                            echo '<tr><td colspan="8" class="text-center">Table not available</td></tr>';
                                        }
                                        ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Recent Activities -->
            <div class="row">
                <!-- Recent Pick List Items -->
                <div class="col-lg-6 mb-4">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="bi bi-clipboard-check me-2"></i>Recent Pick List Items</h5>
                        </div>
                        <div class="table-container">
                            <table class="table custom-table compact-table">
                                <thead class="table-light">
                                    <tr>
                                        <th>Pick List #</th>
                                        <?php if ($view_all_branches): ?><th>Branch</th><?php endif; ?>
                                        <th>Status</th>
                                        <th>Date</th>
                                        <th>Items</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    if ($tables_exist['pick_lists']) {
                                        $pick_lists_query = "SELECT DISTINCT pl.pick_list_id, pl.pick_list_number, pl.pick_status, pl.pick_date, pl.created_at,
                                                                    b.branch_name,
                                                                    (SELECT COUNT(*) FROM pick_list_items WHERE pick_list_id = pl.pick_list_id) as item_count
                                                             FROM pick_lists pl
                                                             LEFT JOIN branches b ON pl.branch_id = b.branch_id
                                                             WHERE 1=1";
                                        
                                        if (!empty($user_category)) {
                                            $pick_lists_query .= " AND EXISTS (
                                                SELECT 1 FROM pick_list_items pli 
                                                JOIN items i ON pli.item_id = i.item_id 
                                                WHERE pli.pick_list_id = pl.pick_list_id 
                                                AND i.category = '" . $conn->real_escape_string($user_category) . "'
                                            )";
                                        }
                                        
                                        $pick_lists_query .= " AND pl.pick_status != 'cancelled'";
                                        
                                        if (!$view_all_branches && $user_branch_id > 0) {
                                            $pick_lists_query .= " AND pl.branch_id = ? ORDER BY pl.created_at DESC LIMIT 5";
                                            $result = safeQuery($conn, $pick_lists_query, $user_branch_id);
                                        } else {
                                            $pick_lists_query .= " ORDER BY pl.created_at DESC LIMIT 5";
                                            $result = $conn->query($pick_lists_query);
                                        }
                                        
                                        if ($result && $result->num_rows > 0) {
                                            while($row = $result->fetch_assoc()) {
                                                $status_badge = '';
                                                switch($row['pick_status']) {
                                                    case 'completed': $status_badge = 'bg-success'; break;
                                                    case 'in-progress': $status_badge = 'bg-info'; break;
                                                    default: $status_badge = 'bg-warning';
                                                }
                                                ?>
                                                <tr>
                                                    <td><span class="badge bg-light text-dark"><?php echo $row['pick_list_number']; ?></span></td>
                                                    <?php if ($view_all_branches): ?>
                                                        <td><span class="badge bg-info"><?php echo htmlspecialchars($row['branch_name'] ?? 'N/A'); ?></span></td>
                                                    <?php endif; ?>
                                                    <td><span class="badge <?php echo $status_badge; ?>"><?php echo ucfirst($row['pick_status']); ?></span></td>
                                                    <td><?php echo date('Y-m-d', strtotime($row['pick_date'])); ?></td>
                                                    <td><?php echo $row['item_count']; ?></td>
                                                </tr>
                                                <?php
                                            }
                                        } else {
                                            $colspan = $view_all_branches ? 5 : 4;
                                            echo '<tr><td colspan="' . $colspan . '" class="text-center">No pick lists found</td></tr>';
                                        }
                                    } else {
                                        $colspan = $view_all_branches ? 5 : 4;
                                        echo '<tr><td colspan="' . $colspan . '" class="text-center">Table not available</td></tr>';
                                    }
                                    ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Inventory Alerts -->
                <div class="col-lg-6 mb-4">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="bi bi-exclamation-triangle me-2"></i>Low Stock Alerts</h5>
                        </div>
                        <div class="card-body">
                            <?php
                            if ($tables_exist['items']) {
                                $low_stock_query = "SELECT i.item_name, i.stock, i.reorder_level, b.branch_name 
                                                   FROM items i
                                                   LEFT JOIN branches b ON i.branch_id = b.branch_id
                                                   WHERE i.stock <= i.reorder_level AND i.status = 'active'";
                                
                                if (!empty($user_category)) {
                                    $low_stock_query .= " AND i.category = '" . $conn->real_escape_string($user_category) . "'";
                                }
                                
                                if (!$view_all_branches && $user_branch_id > 0 && $items_has_branch) {
                                    $low_stock_query .= " AND i.branch_id = ? LIMIT 5";
                                    $result = safeQuery($conn, $low_stock_query, $user_branch_id);
                                } else {
                                    $low_stock_query .= " LIMIT 5";
                                    $result = $conn->query($low_stock_query);
                                }
                                
                                if ($result && $result->num_rows > 0) {
                                    while($row = $result->fetch_assoc()) {
                                        $branch_info = $view_all_branches ? ' [' . $row['branch_name'] . ']' : '';
                                        echo '<div class="alert alert-warning mb-2">';
                                        echo '<i class="bi bi-exclamation-triangle me-2"></i>';
                                        echo '<strong>' . htmlspecialchars($row['item_name'] . $branch_info) . ':</strong> ';
                                        echo 'Stock level at ' . $row['stock'] . ' units (Below threshold of ' . $row['reorder_level'] . ')';
                                        echo '</div>';
                                    }
                                } else {
                                    echo '<div class="alert alert-success mb-0">';
                                    echo '<i class="bi bi-check-circle me-2"></i>';
                                    echo 'All items are adequately stocked';
                                    echo '</div>';
                                }
                            } else {
                                echo '<div class="alert alert-warning mb-0">Items table not available</div>';
                            }
                            ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Mobile Bottom Navigation -->
    <div class="mobile-nav" id="mobileNav">
        <ul class="nav">
            <li class="nav-item">
                <a class="nav-link active" href="warehouse.php">
                    <i class="bi bi-speedometer2"></i>
                    <span>Dashboard</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="currentinventory.php">
                    <i class="bi bi-boxes"></i>
                    <span>Inventory</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="pick_list_items.php">
                    <i class="bi bi-clipboard-check"></i>
                    <span>Pick Lists</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="purchase_order.php">
                    <i class="bi bi-receipt"></i>
                    <span>PO</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="drivers.php">
                    <i class="bi bi-person-badge"></i>
                    <span>Drivers</span>
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
                    <div class="user-avatar-large mb-3">
                        <?php echo substr($user_name, 0, 2); ?>
                    </div>
                    <h4 class="mb-1"><?php echo htmlspecialchars($user_name); ?></h4>
                    <p class="text-muted mb-3">
                        <span class="badge bg-success"><?php echo ucfirst($user_role); ?></span>
                        <?php if (!empty($user_category)): ?>
                            <span class="badge bg-info"><?php echo htmlspecialchars($user_category); ?></span>
                        <?php endif; ?>
                    </p>
                    <?php if (!$view_all_branches && $user_branch_id > 0): ?>
                    <div class="branch-info mb-3">
                        <i class="bi bi-building me-1"></i>
                        <span><?php echo htmlspecialchars($branch_name); ?></span>
                    </div>
                    <?php endif; ?>
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
        let userCategory = <?php echo !empty($user_category) ? json_encode($user_category) : 'null'; ?>;
        let hasTasks = <?php echo $has_tasks ? 'true' : 'false'; ?>;
        
        // Redirect to appropriate page and automatically open the edit modal
        function redirectToTask(type, id, identifier) {
            if (type === 'po') {
                Swal.fire({
                    title: 'Opening Purchase Order',
                    text: `Redirecting to PO #${identifier}...`,
                    icon: 'info',
                    timer: 1500,
                    showConfirmButton: false
                }).then(() => {
                    // Store the PO ID in sessionStorage to trigger modal on the target page
                    sessionStorage.setItem('auto_open_po', id);
                    window.location.href = `purchase_order.php`;
                });
            } else if (type === 'pickup') {
                Swal.fire({
                    text: `Redirecting to create pick list for order ${identifier}...`,
                    icon: 'info',
                    timer: 1500,
                    showConfirmButton: false
                }).then(() => {
                    // Store the SO ID in sessionStorage to trigger modal on the target page
                    sessionStorage.setItem('auto_open_so', id);
                    window.location.href = `pick_list_items.php`;
                });
            }
        }
        
        // Don't show again for today
        function dontShowAgain() {
            const TASK_MODAL_KEY = 'warehouse_task_modal_shown_' + new Date().toDateString();
            localStorage.setItem(TASK_MODAL_KEY, 'true');
            const modal = bootstrap.Modal.getInstance(document.getElementById('taskModal'));
            if (modal) modal.hide();
            Swal.fire({
                title: 'Noted!',
                text: 'Task list will not show again today. You can always check your pending tasks from the dashboard cards.',
                icon: 'info',
                timer: 2000,
                showConfirmButton: false
            });
        }
        
        // Show task modal on page load
        function showTaskModal() {
            const TASK_MODAL_KEY = 'warehouse_task_modal_shown_' + new Date().toDateString();
            const modalShownToday = localStorage.getItem(TASK_MODAL_KEY);
            
            if (hasTasks && !modalShownToday) {
                const taskModal = new bootstrap.Modal(document.getElementById('taskModal'), {
                    backdrop: 'static',
                    keyboard: true
                });
                taskModal.show();
            }
        }
        
        // Check if page was just reloaded (coming back from processing)
        function checkPageReload() {
            const justProcessed = sessionStorage.getItem('just_processed');
            if (justProcessed) {
                sessionStorage.removeItem('just_processed');
                // Refresh the page to show updated tasks
                location.reload();
            }
        }
        
        // Check if tasks are still pending (called when returning to dashboard)
        function checkTaskStatus() {
            // Simple page reload to refresh task list
            location.reload();
        }

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
                    overlay.addEventListener('click', closeMobileSidebar);
                    setTimeout(() => overlay.classList.add('active'), 10);
                } else {
                    const overlay = document.querySelector('.sidebar-overlay');
                    overlay.classList.toggle('active');
                    if (!sidebar.classList.contains('active')) {
                        setTimeout(() => {
                            if (overlay && overlay.parentNode) overlay.remove();
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
                setTimeout(() => overlay.remove(), 300);
            }
        }

        function initializeSidebar() {
            const sidebar = document.getElementById('sidebar');
            if (window.innerWidth > 992) {
                const savedCollapsed = localStorage.getItem('sidebarCollapsed') === 'true';
                if (savedCollapsed) {
                    sidebar.classList.add('collapsed');
                    document.querySelectorAll('.nav-text').forEach(t => t.style.display = 'none');
                    document.querySelector('.main-content').style.marginLeft = '80px';
                } else {
                    sidebar.classList.remove('collapsed');
                    document.querySelectorAll('.nav-text').forEach(t => t.style.display = 'inline-block');
                    document.querySelector('.main-content').style.marginLeft = '250px';
                }
            } else {
                sidebar.classList.remove('active', 'collapsed');
                document.querySelectorAll('.nav-text').forEach(t => t.style.display = 'inline-block');
                document.querySelector('.main-content').style.marginLeft = '0';
            }
        }

        function handleSidebarResize() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.querySelector('.sidebar-overlay');
            if (window.innerWidth > 992) {
                if (overlay) overlay.remove();
                sidebar.classList.remove('active');
                const savedCollapsed = localStorage.getItem('sidebarCollapsed') === 'true';
                sidebar.classList.toggle('collapsed', savedCollapsed);
                document.querySelectorAll('.nav-text').forEach(t => t.style.display = savedCollapsed ? 'none' : 'inline-block');
                document.querySelector('.main-content').style.marginLeft = savedCollapsed ? '80px' : '250px';
            } else {
                sidebar.classList.remove('collapsed');
                document.querySelectorAll('.nav-text').forEach(t => t.style.display = 'inline-block');
                document.querySelector('.main-content').style.marginLeft = '0';
            }
        }

        function initMobileNav() {
            const mobileNav = document.getElementById('mobileNav');
            if (mobileNav) {
                mobileNav.style.display = window.innerWidth <= 992 ? 'block' : 'none';
            }
        }

        function showProfileModal() {
            new bootstrap.Modal(document.getElementById('profileModal')).show();
        }

        function confirmLogout() {
            const modal = bootstrap.Modal.getInstance(document.getElementById('profileModal'));
            if (modal) modal.hide();
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
        
        // Add event listener for page visibility (when coming back from another page)
        document.addEventListener('visibilitychange', function() {
            if (!document.hidden) {
                // Page became visible again, refresh tasks
                setTimeout(checkTaskStatus, 500);
            }
        });

        document.addEventListener('DOMContentLoaded', function() {
            console.log("Warehouse Dashboard loaded! User Category: " + (userCategory || 'Not Assigned'));
            initializeSidebar();
            initMobileNav();
            
            // Show task modal if there are tasks
            setTimeout(() => {
                showTaskModal();
            }, 500);
            
            // Check if we just came back from processing
            checkPageReload();
            
            document.getElementById('mobileToggleBtn')?.addEventListener('click', toggleSidebar);
            document.getElementById('desktopToggleBtn')?.addEventListener('click', toggleSidebar);
            window.addEventListener('resize', function() {
                handleSidebarResize();
                initMobileNav();
            });
        });

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey && e.key === 'b' && window.innerWidth > 992) {
                e.preventDefault();
                toggleSidebar();
            } else if (e.key === 'Escape' && window.innerWidth <= 992) {
                closeMobileSidebar();
            } else if (e.key === 'Escape') {
                const profileModal = document.getElementById('profileModal');
                if (profileModal && profileModal.classList.contains('show')) {
                    bootstrap.Modal.getInstance(profileModal).hide();
                }
            }
        });
    </script>
</body>
</html>