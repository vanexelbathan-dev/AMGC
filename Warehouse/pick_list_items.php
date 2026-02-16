 <?php
    session_start();
    require_once '../config/database.php';
    
    // Check if user is logged in
    if (!isset($_SESSION['user_id'])) {
        header("Location: ../login.php");
        exit();
    }

// Get current user info and branch context
$user_id = $_SESSION['user_id'];
$user_name = isset($_SESSION['first_name']) ? $_SESSION['first_name'] . ' ' . $_SESSION['last_name'] : 'Sales User';
$user_role = isset($_SESSION['role']) ? $_SESSION['role'] : 'sales';
$branch_id = $_SESSION['branch_id'] ?? 0;
$view_all_branches = $_SESSION['view_all_branches'] ?? false;

// Check if branch_id column exists in customers table
$branch_column_exists = false;
$check_column = $conn->query("SHOW COLUMNS FROM customers LIKE 'branch_id'");
if ($check_column && $check_column->num_rows > 0) {
    $branch_column_exists = true;
}

// Check if branch_id column exists in items table
$items_branch_column_exists = false;
$check_items_column = $conn->query("SHOW COLUMNS FROM items LIKE 'branch_id'");
if ($check_items_column && $check_items_column->num_rows > 0) {
    $items_branch_column_exists = true;
}
    // Handle form submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_pick_item') {
        // Get form data
        $pick_list_id = $_POST['pick_list_id'];
        $item_id = $_POST['item_id'];
        $quantity_to_pick = $_POST['quantity_to_pick'];
        $location_bin = $_POST['location_bin'] ?: NULL;
        
        // Check if item already exists in the pick list
        $check_query = "SELECT * FROM pick_list_items WHERE pick_list_id = ? AND item_id = ?";
        $stmt = $conn->prepare($check_query);
        $stmt->bind_param("ii", $pick_list_id, $item_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $error_message = "This item already exists in the selected pick list!";
        } else {
            // Insert into database
            $insert_query = "INSERT INTO pick_list_items (pick_list_id, item_id, quantity_to_pick, location_bin) VALUES (?, ?, ?, ?)";
            $stmt = $conn->prepare($insert_query);
            $stmt->bind_param("iiis", $pick_list_id, $item_id, $quantity_to_pick, $location_bin);
            
            if ($stmt->execute()) {
                // Update inventory reserved quantity
                $branch_query = "SELECT branch_id FROM pick_lists WHERE pick_list_id = ?";
                $branch_stmt = $conn->prepare($branch_query);
                $branch_stmt->bind_param("i", $pick_list_id);
                $branch_stmt->execute();
                $branch_result = $branch_stmt->get_result();
                
                if ($branch_row = $branch_result->fetch_assoc()) {
                    $branch_id = $branch_row['branch_id'];
                    
                    $update_inventory_query = "UPDATE inventory SET quantity_reserved = quantity_reserved + ? WHERE branch_id = ? AND item_id = ?";
                    $update_stmt = $conn->prepare($update_inventory_query);
                    $update_stmt->bind_param("iii", $quantity_to_pick, $branch_id, $item_id);
                    $update_stmt->execute();
                    $update_stmt->close();
                }
                
                $branch_stmt->close();
                
                $success_message = "Pick list item added successfully!";
            } else {
                $error_message = "Error adding pick list item: " . $conn->error;
            }
        }
        $stmt->close();
    }
    
    // Handle update pick quantity
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_pick_quantity') {
        $pick_item_id = $_POST['pick_item_id'];
        $quantity_picked = $_POST['quantity_picked'];
        
        $get_query = "SELECT pli.quantity_to_pick, pli.pick_list_id, pli.item_id, 
                             pl.branch_id, pl.so_id
                      FROM pick_list_items pli
                      JOIN pick_lists pl ON pli.pick_list_id = pl.pick_list_id
                      WHERE pli.pick_item_id = ?";
        $stmt = $conn->prepare($get_query);
        $stmt->bind_param("i", $pick_item_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $item = $result->fetch_assoc();
        
        if ($item) {
            $update_query = "UPDATE pick_list_items SET quantity_picked = ? WHERE pick_item_id = ?";
            $update_stmt = $conn->prepare($update_query);
            $update_stmt->bind_param("ii", $quantity_picked, $pick_item_id);
            
            if ($update_stmt->execute()) {
                $update_inventory_query = "UPDATE inventory SET quantity_reserved = quantity_reserved - ? WHERE branch_id = ? AND item_id = ?";
                $update_inventory_stmt = $conn->prepare($update_inventory_query);
                $update_inventory_stmt->bind_param("iii", $quantity_picked, $item['branch_id'], $item['item_id']);
                $update_inventory_stmt->execute();
                $update_inventory_stmt->close();
                
                $update_items_query = "UPDATE items SET stock = stock - ? WHERE item_id = ? AND stock >= ?";
                $update_items_stmt = $conn->prepare($update_items_query);
                $update_items_stmt->bind_param("iii", $quantity_picked, $item['item_id'], $quantity_picked);
                $update_items_stmt->execute();
                $update_items_stmt->close();
                
                // Check if all items in pick list are picked
                $check_all_picked_query = "SELECT 
                                            SUM(quantity_to_pick) as total_to_pick,
                                            SUM(quantity_picked) as total_picked
                                          FROM pick_list_items 
                                          WHERE pick_list_id = ?";
                $check_stmt = $conn->prepare($check_all_picked_query);
                $check_stmt->bind_param("i", $item['pick_list_id']);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                $pick_totals = $check_result->fetch_assoc();
                
                if ($pick_totals['total_picked'] >= $pick_totals['total_to_pick']) {
                    $update_pl_status = "UPDATE pick_lists SET pick_status = 'completed', updated_at = NOW() WHERE pick_list_id = ?";
                    $pl_status_stmt = $conn->prepare($update_pl_status);
                    $pl_status_stmt->bind_param("i", $item['pick_list_id']);
                    $pl_status_stmt->execute();
                    $pl_status_stmt->close();
                    
                    if ($item['so_id']) {
                        $update_so_status = "UPDATE sales_orders SET order_status = 'ready', updated_at = NOW() WHERE so_id = ?";
                        $so_status_stmt = $conn->prepare($update_so_status);
                        $so_status_stmt->bind_param("i", $item['so_id']);
                        $so_status_stmt->execute();
                        $so_status_stmt->close();
                    }
                }
                
                echo json_encode(['success' => true]);
                exit;
            } else {
                echo json_encode(['success' => false, 'message' => $conn->error]);
                exit;
            }
        }
    }
    
    // Helper function for order status badge
    function getOrderStatusBadge($status) {
        switch($status) {
            case 'pending': return 'status-pending';
            case 'confirmed': return 'status-confirmed';
            case 'processing': return 'status-processing';
            case 'ready': return 'status-ready';
            case 'delivered': return 'status-delivered';
            case 'cancelled': return 'status-cancelled';
            default: return 'bg-secondary text-white';
        }
    }
    
    function getOrderStatusText($status) {
        switch($status) {
            case 'pending': return 'Pending';
            case 'confirmed': return 'Confirmed';
            case 'processing': return 'Processing';
            case 'ready': return 'Ready for Delivery';
            case 'delivered': return 'Delivered';
            case 'cancelled': return 'Cancelled';
            default: return ucfirst($status);
        }
    }
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pick List Items - Warehouse</title>
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
    <style>
    /* Mobile responsive adjustments ONLY - same as warehouse.php */
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
            
            /* Make cards 2 columns on mobile */
            .col-md-3 {
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
        
        /* Extra small devices (phones, less than 576px) */
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
            
            .col-md-3 {
                width: 50%;
                padding-left: 6px;
                padding-right: 6px;
            }
            
            .row.g-3 {
                margin-left: -6px;
                margin-right: -6px;
            }
        }
        
        /* Driver badge styling */
        .driver-badge {
            display: inline-block;
            padding: 4px 10px;
            background-color: #e8f4fd;
            color: #084298;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
            border-left: 3px solid #0d6efd;
        }
        
        .driver-badge i {
            margin-right: 4px;
            color: #0d6efd;
        }
        
        .table td {
            vertical-align: middle;
        }
        
        /* Order status badges */
        .order-status-badge {
            display: inline-block;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
            min-width: 100px;
            text-align: center;
        }
        
        .status-pending { background-color: #fff3cd; color: #856404; }
        .status-confirmed { background-color: #cce5ff; color: #004085; }
        .status-processing { background-color: #b8daff; color: #004085; }
        .status-ready { background-color: #d4edda; color: #155724; }
        .status-delivered { background-color: #d1e7dd; color: #0a3622; }
        .status-cancelled { background-color: #f8d7da; color: #721c24; }
        
        /* Quantity display */
        .quantity-display {
            font-size: 14px;
            font-weight: 600;
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
                        <a class="nav-link" href="warehouse.php">
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
                        <a class="nav-link active" href="pick_list_items.php">
                            <i class="bi bi-clipboard-check"></i>
                            <span class="nav-text">Pick List Items</span>
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
                        <span class="user-role-sidebar">
                            <?php echo htmlspecialchars(ucfirst($user_role)); ?>
                            <?php if ($items_branch_column_exists || $branch_column_exists): ?>
                            <?php endif; ?>
                        </span>
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
                    <h2>Pick List Items</h2>
                    <p>Manage and track pick list items for shipments</p>
                </div>
            </div>

            <?php
            // Get pick list statistics
            $stats = [];
            
            $total_items_query = "SELECT COUNT(*) as count FROM pick_list_items";
            $result = $conn->query($total_items_query);
            $stats['total_items'] = $result->fetch_assoc()['count'] ?? 0;
            
            $picked_query = "SELECT COUNT(*) as count FROM pick_list_items WHERE quantity_picked >= quantity_to_pick";
            $result = $conn->query($picked_query);
            $stats['picked'] = $result->fetch_assoc()['count'] ?? 0;
            
            $pending_query = "SELECT COUNT(*) as count FROM pick_list_items WHERE quantity_picked = 0";
            $result = $conn->query($pending_query);
            $stats['pending'] = $result->fetch_assoc()['count'] ?? 0;
            
            $value_query = "SELECT SUM(pli.quantity_to_pick * i.unit_price) as total_value 
                           FROM pick_list_items pli
                           JOIN items i ON pli.item_id = i.item_id";
            $result = $conn->query($value_query);
            $total_value = $result->fetch_assoc()['total_value'] ?? 0;
            ?>

            <!-- Success/Error Messages -->
            <?php if (isset($success_message)): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <?php echo $success_message; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <?php if (isset($error_message)): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <?php echo $error_message; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Stats Cards -->
            <div class="row g-3 mb-4">
                <div class="col-md-3 mb-3">
                    <div class="stat-card inventory">
                        <div class="stat-icon">
                            <i class="bi bi-clipboard-check"></i>
                        </div>
                        <div>
                            <div class="stat-value"><?php echo $stats['total_items']; ?></div>
                            <div class="stat-label">Total Items</div>
                        </div>
                    </div>
                </div>

                <div class="col-md-3 mb-3">
                    <div class="stat-card sales">
                        <div class="stat-icon">
                            <i class="bi bi-check-circle"></i>
                        </div>
                        <div>
                            <div class="stat-value"><?php echo $stats['picked']; ?></div>
                            <div class="stat-label">Picked</div>
                        </div>
                    </div>
                </div>

                <div class="col-md-3 mb-3">
                    <div class="stat-card pending">
                        <div class="stat-icon">
                            <i class="bi bi-hourglass-split"></i>
                        </div>
                        <div>
                            <div class="stat-value"><?php echo $stats['pending']; ?></div>
                            <div class="stat-label">Pending Pickup</div>
                        </div>
                    </div>
                </div>

                <div class="col-md-3 mb-3">
                    <div class="stat-card delivery">
                        <div class="stat-icon">
                            <i class="bi bi-currency-exchange"></i>
                        </div>
                        <div>
                            <div class="stat-value">₱<?php echo number_format($total_value, 0); ?></div>
                            <div class="stat-label">Total Value</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Search and Filter -->
            <div class="card mb-4">
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <div class="input-group">
                                <span class="input-group-text">
                                    <i class="bi bi-search"></i>
                                </span>
                                <input type="text" class="form-control" id="searchInput" placeholder="Search by item or pick list...">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <select class="form-select" id="statusFilter">
                                <option value="">All Status</option>
                                <option value="pending">Pending</option>
                                <option value="confirmed">Confirmed</option>
                                <option value="processing">Processing</option>
                                <option value="ready">Ready for Delivery</option>
                                <option value="delivered">Delivered</option>
                                <option value="cancelled">Cancelled</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <select class="form-select" id="driverFilter">
                                <option value="">All Drivers</option>
                                <?php
                                $drivers_filter_query = "SELECT driver_id, driver_name FROM drivers WHERE status = 'active' ORDER BY driver_name";
                                $drivers_result = $conn->query($drivers_filter_query);
                                while ($driver = $drivers_result->fetch_assoc()) {
                                    echo '<option value="' . $driver['driver_id'] . '">' . htmlspecialchars($driver['driver_name']) . '</option>';
                                }
                                ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <button class="btn btn-outline-secondary w-100" onclick="clearFilters()">
                                <i class="bi bi-x-circle"></i> Clear
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Pick List Items Table - REMOVED PICK STATUS COLUMN -->
            <div class="card">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Pick List</th>
                                <th>Item</th>
                                <th>Qty to Pick</th>
                                <th>Qty Picked</th>
                                <th>Location</th>
                                <th>Assigned Driver</th>
                                <th>Order Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $pick_list_items_query = "SELECT pli.*, 
                                                             pl.pick_list_number, 
                                                             i.item_name, 
                                                             i.item_code,
                                                             d.driver_id,
                                                             d.driver_name,
                                                             d.vehicle_plate_number,
                                                             so.order_status,
                                                             so.so_number
                                                     FROM pick_list_items pli
                                                     JOIN pick_lists pl ON pli.pick_list_id = pl.pick_list_id
                                                     JOIN items i ON pli.item_id = i.item_id
                                                     LEFT JOIN drivers d ON pl.driver_id = d.driver_id
                                                     LEFT JOIN sales_orders so ON pl.so_id = so.so_id
                                                     ORDER BY pli.pick_item_id DESC";
                            $result = $conn->query($pick_list_items_query);
                            
                            if ($result && $result->num_rows > 0) {
                                while($row = $result->fetch_assoc()) {
                                    ?>
                                    <tr data-driver-id="<?php echo $row['driver_id'] ?? ''; ?>" 
                                        data-order-status="<?php echo $row['order_status'] ?? ''; ?>">
                                        <td>
                                            <span class="badge bg-light text-dark fs-6 p-2"><?php echo htmlspecialchars($row['pick_list_number']); ?></span>
                                        </td>
                                        <td>
                                            <div class="fw-bold"><?php echo htmlspecialchars($row['item_name']); ?></div>
                                            <div><?php echo htmlspecialchars($row['item_code']); ?></div>
                                        </td>
                                        <td><span class="quantity-display"><?php echo number_format($row['quantity_to_pick']); ?></span></td>
                                        <td>
                                            <span class="quantity-display"><?php echo number_format($row['quantity_picked']); ?></span>
                                        </td>
                                        <td><?php echo htmlspecialchars($row['location_bin'] ?? '—'); ?></td>
                                        <td>
                                            <?php if (!empty($row['driver_name'])): ?>
                                                <span class="driver-badge">
                                                    <i class="bi bi-truck"></i>
                                                    <?php echo htmlspecialchars($row['driver_name']); ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($row['order_status'])): ?>
                                                <span class="order-status-badge <?php echo getOrderStatusBadge($row['order_status']); ?>">
                                                    <?php echo getOrderStatusText($row['order_status']); ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="btn-group" role="group">
                                                <button class="btn btn-sm btn-info" data-bs-toggle="modal" data-bs-target="#viewItemModal" 
                                                        onclick="loadPickItemDetails('<?php echo $row['pick_item_id']; ?>')">
                                                    <i class="bi bi-eye"></i>
                                                </button>
                                                <?php if ($row['order_status'] != 'delivered' && $row['order_status'] != 'cancelled'): ?>
                                                <button class="btn btn-sm btn-warning" data-bs-toggle="modal" data-bs-target="#updatePickModal"
                                                        onclick="setUpdatePickItem('<?php echo $row['pick_item_id']; ?>', '<?php echo $row['quantity_to_pick']; ?>', '<?php echo $row['quantity_picked']; ?>')">
                                                    <i class="bi bi-pencil"></i>
                                                </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php
                                }
                            } else {
                                echo '<tr><td colspan="8" class="text-center py-4">No pick list items found</td></tr>';
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Pick List Item Modal -->
    <div class="modal fade" id="addPickListModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Add Pick List Item</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="addPickListForm" method="POST">
                    <input type="hidden" name="action" value="add_pick_item">
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Pick List <span class="text-danger">*</span></label>
                                <select class="form-select" name="pick_list_id" required>
                                    <option value="">Select Pick List</option>
                                    <?php
                                    $pick_lists_query = "SELECT pl.pick_list_id, pl.pick_list_number, b.branch_name, 
                                                                d.driver_name
                                                        FROM pick_lists pl
                                                        JOIN branches b ON pl.branch_id = b.branch_id
                                                        LEFT JOIN drivers d ON pl.driver_id = d.driver_id
                                                        WHERE pl.pick_status IN ('open', 'in-progress')
                                                        ORDER BY pl.created_at DESC";
                                    $result = $conn->query($pick_lists_query);
                                    if ($result->num_rows > 0) {
                                        while($pick_list = $result->fetch_assoc()) {
                                            $driver_info = $pick_list['driver_name'] ? ' - Driver: ' . $pick_list['driver_name'] : '';
                                            echo '<option value="' . $pick_list['pick_list_id'] . '">' . 
                                                 $pick_list['pick_list_number'] . ' - ' . $pick_list['branch_name'] . 
                                                 $driver_info . '</option>';
                                        }
                                    } else {
                                        echo '<option value="">No active pick lists available</option>';
                                    }
                                    ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Item <span class="text-danger">*</span></label>
                                <select class="form-select" name="item_id" required>
                                    <option value="">Select Item</option>
                                    <?php
                                    $items_query = "SELECT item_id, item_name, item_code FROM items WHERE status = 'active' ORDER BY item_name";
                                    $result = $conn->query($items_query);
                                    while($item = $result->fetch_assoc()) {
                                        echo '<option value="' . $item['item_id'] . '">' . 
                                             $item['item_code'] . ' - ' . $item['item_name'] . '</option>';
                                    }
                                    ?>
                                </select>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Quantity to Pick <span class="text-danger">*</span></label>
                                <input type="number" class="form-control" name="quantity_to_pick" required placeholder="0" min="1" value="1">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Location Bin</label>
                                <input type="text" class="form-control" name="location_bin" placeholder="e.g., A-12, B-05">
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Add Item</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Create New Pick List Modal -->
    <div class="modal fade" id="createPickListModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Create New Pick List</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form action="create_pick_list.php" method="POST" target="_blank">
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Sales Order</label>
                                <select class="form-select" name="so_id" required>
                                    <option value="">Select Sales Order</option>
                                    <?php
                                    $sales_orders_query = "SELECT so.so_id, so.so_number, c.customer_name
                                                          FROM sales_orders so
                                                          JOIN customers c ON so.customer_id = c.customer_id
                                                          WHERE so.order_status IN ('confirmed', 'processing')
                                                          ORDER BY so.order_date DESC";
                                    $result = $conn->query($sales_orders_query);
                                    while($so = $result->fetch_assoc()) {
                                        echo '<option value="' . $so['so_id'] . '">' . 
                                             $so['so_number'] . ' - ' . $so['customer_name'] . '</option>';
                                    }
                                    ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Branch</label>
                                <select class="form-select" name="branch_id" required>
                                    <option value="">Select Branch</option>
                                    <?php
                                    $branches_query = "SELECT branch_id, branch_name FROM branches WHERE status = 'active'";
                                    $result = $conn->query($branches_query);
                                    while($branch = $result->fetch_assoc()) {
                                        echo '<option value="' . $branch['branch_id'] . '">' . $branch['branch_name'] . '</option>';
                                    }
                                    ?>
                                </select>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Assign Driver</label>
                                <select class="form-select" name="driver_id">
                                    <option value="">Select Driver (Optional)</option>
                                    <?php
                                    $drivers_query = "SELECT driver_id, driver_name, vehicle_plate_number 
                                                      FROM drivers WHERE status = 'active' 
                                                      ORDER BY driver_name";
                                    $drivers_result = $conn->query($drivers_query);
                                    while($driver = $drivers_result->fetch_assoc()) {
                                        echo '<option value="' . $driver['driver_id'] . '">' . 
                                             htmlspecialchars($driver['driver_name']) . ' - ' . 
                                             htmlspecialchars($driver['vehicle_plate_number'] ?? 'No plate') . '</option>';
                                    }
                                    ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Pick Date</label>
                                <input type="date" class="form-control" name="pick_date" value="<?php echo date('Y-m-d'); ?>">
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success">Create Pick List</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Update Pick Quantity Modal -->
    <div class="modal fade" id="updatePickModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Update Picked Quantity</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="updatePickForm" method="POST">
                    <input type="hidden" name="action" value="update_pick_quantity">
                    <input type="hidden" name="pick_item_id" id="update_pick_item_id">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Quantity to Pick</label>
                            <input type="number" class="form-control" id="update_quantity_to_pick" readonly>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Quantity Picked <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" name="quantity_picked" id="update_quantity_picked" 
                                   placeholder="Enter picked quantity" min="0" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-warning">Update Picked Quantity</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- View Item Details Modal -->
    <div class="modal fade" id="viewItemModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Pick List Item Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="pickItemDetailsContent">
                    <!-- Content will be loaded by JavaScript -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // ================= SIDEBAR FUNCTIONS =================
        // Toggle sidebar collapse/expand
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const isMobile = window.innerWidth <= 992;
            
            if (isMobile) {
                // On mobile, toggle active state
                sidebar.classList.toggle('active');
                
                // Create overlay for mobile
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
                    // If overlay exists, toggle its active state
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
                // On desktop, toggle between expanded and collapsed
                sidebar.classList.toggle('collapsed');
                
                // Store preference in localStorage
                localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
                
                // Show/hide nav text
                document.querySelectorAll('.nav-text').forEach(text => {
                    text.style.display = sidebar.classList.contains('collapsed') ? 'none' : 'inline-block';
                });
                
                // Adjust main content margin
                const mainContent = document.querySelector('.main-content');
                if (mainContent) {
                    mainContent.style.marginLeft = sidebar.classList.contains('collapsed') ? '80px' : '250px';
                }
            }
        }

        // Close mobile sidebar
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

        // Initialize sidebar when page loads
        function initializeSidebar() {
            const sidebar = document.getElementById('sidebar');
            
            // Load saved preference from localStorage for desktop
            if (window.innerWidth > 992) {
                const savedCollapsed = localStorage.getItem('sidebarCollapsed');
                if (savedCollapsed === 'true') {
                    sidebar.classList.add('collapsed');
                    document.querySelectorAll('.nav-text').forEach(text => {
                        text.style.display = 'none';
                    });
                    
                    // Adjust main content margin
                    const mainContent = document.querySelector('.main-content');
                    if (mainContent) {
                        mainContent.style.marginLeft = '80px';
                    }
                } else {
                    sidebar.classList.remove('collapsed');
                    document.querySelectorAll('.nav-text').forEach(text => {
                        text.style.display = 'inline-block';
                    });
                    
                    // Adjust main content margin
                    const mainContent = document.querySelector('.main-content');
                    if (mainContent) {
                        mainContent.style.marginLeft = '250px';
                    }
                }
            } else {
                // On mobile, always start with closed sidebar
                sidebar.classList.remove('active');
                sidebar.classList.remove('collapsed');
                document.querySelectorAll('.nav-text').forEach(text => {
                    text.style.display = 'inline-block';
                });
                
                // Adjust main content margin
                const mainContent = document.querySelector('.main-content');
                if (mainContent) {
                    mainContent.style.marginLeft = '0';
                }
            }
        }

        // Handle window resize for sidebar
        function handleSidebarResize() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.querySelector('.sidebar-overlay');
            
            if (window.innerWidth > 992) {
                // Desktop mode - remove mobile overlay
                if (overlay) {
                    overlay.remove();
                }
                sidebar.classList.remove('active');
                
                // Load saved preference
                const savedCollapsed = localStorage.getItem('sidebarCollapsed');
                if (savedCollapsed === 'true') {
                    sidebar.classList.add('collapsed');
                    document.querySelectorAll('.nav-text').forEach(text => {
                        text.style.display = 'none';
                    });
                    
                    // Adjust main content margin
                    const mainContent = document.querySelector('.main-content');
                    if (mainContent) {
                        mainContent.style.marginLeft = '80px';
                    }
                } else {
                    sidebar.classList.remove('collapsed');
                    document.querySelectorAll('.nav-text').forEach(text => {
                        text.style.display = 'inline-block';
                    });
                    
                    // Adjust main content margin
                    const mainContent = document.querySelector('.main-content');
                    if (mainContent) {
                        mainContent.style.marginLeft = '250px';
                    }
                }
            } else {
                // Mobile mode - always show expanded when visible
                sidebar.classList.remove('collapsed');
                document.querySelectorAll('.nav-text').forEach(text => {
                    text.style.display = 'inline-block';
                });
                
                // Adjust main content margin
                const mainContent = document.querySelector('.main-content');
                if (mainContent) {
                    mainContent.style.marginLeft = '0';
                }
            }
        }
        // ================= END SIDEBAR FUNCTIONS =================

        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            console.log("Pick List Management page loaded!");
            
            // Initialize sidebar
            initializeSidebar();
            
            // Setup mobile toggle button - support multiple button IDs
            const mobileToggleBtn = document.getElementById('mobileToggleBtn');
            if (mobileToggleBtn) {
                mobileToggleBtn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    toggleSidebar();
                });
            }
            
            const mobileMenuBtn = document.getElementById('mobileMenuBtn');
            if (mobileMenuBtn) {
                mobileMenuBtn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    toggleSidebar();
                });
            }
            
            // Setup desktop toggle button
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
                const mobileBtn = document.getElementById('mobileToggleBtn') || document.getElementById('mobileMenuBtn');
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

            // Initialize tooltips
            var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
            tooltipTriggerList.map(function(tooltipTriggerEl) {
                return new bootstrap.Tooltip(tooltipTriggerEl);
            });

            // Setup event listeners
            setupEventListeners();
        });

        // Setup event listeners
        function setupEventListeners() {
            // Handle update pick form submission
            const updatePickForm = document.getElementById('updatePickForm');
            if (updatePickForm) {
                updatePickForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    
                    const formData = new FormData(this);
                    
                    fetch('pick_list_items.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            alert('Pick quantity updated successfully!');
                            window.location.reload();
                        } else {
                            alert('Error: ' + data.message);
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        alert('Failed to update pick quantity');
                    });
                });
            }

            // Filter table event listeners
            const searchInput = document.getElementById('searchInput');
            const statusFilter = document.getElementById('statusFilter');
            const driverFilter = document.getElementById('driverFilter');
            
            if (searchInput) searchInput.addEventListener('keyup', filterTable);
            if (statusFilter) statusFilter.addEventListener('change', filterTable);
            if (driverFilter) driverFilter.addEventListener('change', filterTable);

            // Form validation for add pick list
            const addPickListForm = document.getElementById('addPickListForm');
            if (addPickListForm) {
                addPickListForm.addEventListener('submit', function(e) {
                    const pickListSelect = this.querySelector('select[name="pick_list_id"]');
                    const itemSelect = this.querySelector('select[name="item_id"]');
                    const quantityInput = this.querySelector('input[name="quantity_to_pick"]');
                    
                    if (!pickListSelect || !pickListSelect.value) {
                        e.preventDefault();
                        alert('Please select a pick list');
                        if (pickListSelect) pickListSelect.focus();
                        return false;
                    }
                    
                    if (!itemSelect || !itemSelect.value) {
                        e.preventDefault();
                        alert('Please select an item');
                        if (itemSelect) itemSelect.focus();
                        return false;
                    }
                    
                    if (!quantityInput || !quantityInput.value || parseInt(quantityInput.value) <= 0) {
                        e.preventDefault();
                        alert('Please enter a valid quantity (minimum 1)');
                        if (quantityInput) quantityInput.focus();
                        return false;
                    }
                    
                    if (!confirm('Are you sure you want to add this item to the pick list?')) {
                        e.preventDefault();
                        return false;
                    }
                    
                    return true;
                });
            }
        }

        // Load pick item details via AJAX
        function loadPickItemDetails(pickItemId) {
            const pickItemDetailsContent = document.getElementById('pickItemDetailsContent');
            if (pickItemDetailsContent) {
                pickItemDetailsContent.innerHTML = '<div class="text-center py-4"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div><p class="mt-2">Loading item details...</p></div>';
            }
            
            fetch('get_pick_item_details.php?pick_item_id=' + pickItemId)
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Network response was not ok');
                    }
                    return response.text();
                })
                .then(data => {
                    if (pickItemDetailsContent) {
                        pickItemDetailsContent.innerHTML = data;
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    if (pickItemDetailsContent) {
                        pickItemDetailsContent.innerHTML = '<div class="alert alert-danger"><i class="bi bi-exclamation-triangle"></i> Failed to load item details. Please try again.</div>';
                    }
                });
        }

        // Set values for update pick modal
        function setUpdatePickItem(pickItemId, quantityToPick, quantityPicked) {
            const updatePickItemId = document.getElementById('update_pick_item_id');
            const updateQuantityToPick = document.getElementById('update_quantity_to_pick');
            const updateQuantityPicked = document.getElementById('update_quantity_picked');
            
            if (updatePickItemId) updatePickItemId.value = pickItemId;
            if (updateQuantityToPick) updateQuantityToPick.value = quantityToPick;
            if (updateQuantityPicked) {
                updateQuantityPicked.value = quantityPicked;
                updateQuantityPicked.max = quantityToPick;
            }
        }

        // Filter table function
        function filterTable() {
            const searchInput = document.getElementById('searchInput');
            const statusFilter = document.getElementById('statusFilter');
            const driverFilter = document.getElementById('driverFilter');
            const rows = document.querySelectorAll('tbody tr');
            
            const searchText = searchInput ? searchInput.value.toLowerCase() : '';
            const statusValue = statusFilter ? statusFilter.value : '';
            const driverValue = driverFilter ? driverFilter.value : '';
            
            rows.forEach(row => {
                let showRow = true;
                
                if (searchText) {
                    const text = row.textContent.toLowerCase();
                    showRow = text.includes(searchText);
                }
                
                if (showRow && statusValue) {
                    const orderStatus = row.dataset.orderStatus;
                    showRow = orderStatus === statusValue;
                }
                
                if (showRow && driverValue) {
                    const driverId = row.dataset.driverId;
                    showRow = driverId === driverValue;
                }
                
                row.style.display = showRow ? '' : 'none';
            });
        }

        // Clear all filters
        function clearFilters() {
            const searchInput = document.getElementById('searchInput');
            const statusFilter = document.getElementById('statusFilter');
            const driverFilter = document.getElementById('driverFilter');
            
            if (searchInput) searchInput.value = '';
            if (statusFilter) statusFilter.value = '';
            if (driverFilter) driverFilter.value = '';
            
            filterTable();
        }

        // Logout function
        function logout() {
            if (confirm('Are you sure you want to logout?')) {
                window.location.href = '../logout.php';
            }
        }

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl + B to toggle sidebar (desktop only)
            if (e.ctrlKey && e.key === 'b' && window.innerWidth > 992) {
                e.preventDefault();
                toggleSidebar();
            }
            // Escape to close sidebar on mobile
            else if (e.key === 'Escape' && window.innerWidth <= 992) {
                closeMobileSidebar();
            }
            // Ctrl + F to focus search
            else if (e.ctrlKey && e.key === 'f') {
                e.preventDefault();
                const searchInput = document.getElementById('searchInput');
                if (searchInput) {
                    searchInput.focus();
                }
            }
            // Ctrl + N to add new pick list
            else if (e.ctrlKey && e.key === 'n') {
                e.preventDefault();
                const addButton = document.querySelector('[data-bs-target="#addPickListModal"]');
                if (addButton) {
                    addButton.click();
                }
            }
            // Ctrl + C to clear filters
            else if (e.ctrlKey && e.key === 'c') {
                e.preventDefault();
                clearFilters();
            }
        });
    </script>
</body>
</html>