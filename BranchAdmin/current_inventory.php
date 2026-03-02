<?php
require_once '../config/database.php';
require_once '../config/session_handler.php';

// Get current user info and branch context
$user_id = $_SESSION['user_id'] ?? 0;
$user_name = isset($_SESSION['first_name']) ? $_SESSION['first_name'] . ' ' . $_SESSION['last_name'] : 'Branch Admin';
$user_role = isset($_SESSION['role']) ? $_SESSION['role'] : 'branch_admin';
$branch_id = $_SESSION['branch_id'] ?? 0;
$view_all_branches = $_SESSION['view_all_branches'] ?? false;

// Check if branch_id column exists in items table
$items_branch_column_exists = false;
$check_column = $conn->query("SHOW COLUMNS FROM items LIKE 'branch_id'");
if ($check_column && $check_column->num_rows > 0) {
    $items_branch_column_exists = true;
}

// Check if price columns exist
$price_case_exists = false;
$check_price_case = $conn->query("SHOW COLUMNS FROM items LIKE 'price_case'");
if ($check_price_case && $check_price_case->num_rows > 0) {
    $price_case_exists = true;
}

// Determine branch filter condition
$branch_condition = "";
if ($items_branch_column_exists && !$view_all_branches) {
    $branch_condition = "AND branch_id = $branch_id";
}

// ========== HANDLE AJAX REQUESTS ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    try {
        $conn->begin_transaction();
        
        // ADD ITEM
        if ($_POST['action'] === 'add_item') {
            $item_code = $_POST['item_code'];
            $item_name = $_POST['item_name'];
            $description = $_POST['description'] ?? null;
            $category = $_POST['category'] ?? null;
            $stock = (int)$_POST['stock'];
            $unit_type = $_POST['unit_type'];
            $unit_price = (float)$_POST['unit_price'];
            $reorder_level = (int)$_POST['reorder_level'];
            $status = $_POST['status'] ?? 'active';
            
            // Check if item code already exists
            $check_query = "SELECT item_id FROM items WHERE item_code = ?";
            $check_stmt = $conn->prepare($check_query);
            $check_stmt->bind_param("s", $item_code);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            
            if ($check_result->num_rows > 0) {
                throw new Exception('Item code already exists');
            }
            
            // Insert new item with branch_id and price columns
            if ($items_branch_column_exists) {
                if ($price_case_exists) {
                    $insert_query = "INSERT INTO items (item_code, item_name, description, category, stock, unit_type, unit_price, price_case, price_inner_pack, price_box, price_carton, reorder_level, status, branch_id, created_at, updated_at) 
                                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
                    $insert_stmt = $conn->prepare($insert_query);
                    $insert_stmt->bind_param("ssssisdddddisi", 
                        $item_code, $item_name, $description, $category, $stock, $unit_type, $unit_price, 
                        $unit_price * 12, $unit_price * 6, $unit_price * 24, $unit_price * 48, 
                        $reorder_level, $status, $branch_id);
                } else {
                    $insert_query = "INSERT INTO items (item_code, item_name, description, category, stock, unit_type, unit_price, reorder_level, status, branch_id, created_at, updated_at) 
                                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
                    $insert_stmt = $conn->prepare($insert_query);
                    $insert_stmt->bind_param("ssssisdisi", $item_code, $item_name, $description, $category, $stock, $unit_type, $unit_price, $reorder_level, $status, $branch_id);
                }
            } else {
                if ($price_case_exists) {
                    $insert_query = "INSERT INTO items (item_code, item_name, description, category, stock, unit_type, unit_price, price_case, price_inner_pack, price_box, price_carton, reorder_level, status, created_at, updated_at) 
                                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
                    $insert_stmt = $conn->prepare($insert_query);
                    $insert_stmt->bind_param("ssssisdddddis", 
                        $item_code, $item_name, $description, $category, $stock, $unit_type, $unit_price, 
                        $unit_price * 12, $unit_price * 6, $unit_price * 24, $unit_price * 48, 
                        $reorder_level, $status);
                } else {
                    $insert_query = "INSERT INTO items (item_code, item_name, description, category, stock, unit_type, unit_price, reorder_level, status, created_at, updated_at) 
                                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
                    $insert_stmt = $conn->prepare($insert_query);
                    $insert_stmt->bind_param("ssssisdis", $item_code, $item_name, $description, $category, $stock, $unit_type, $unit_price, $reorder_level, $status);
                }
            }
            
            if (!$insert_stmt->execute()) {
                throw new Exception('Failed to add item: ' . $insert_stmt->error);
            }
            
            $item_id = $conn->insert_id;
            
            $conn->commit();
            
            echo json_encode([
                'success' => true,
                'message' => 'Item added successfully',
                'item_id' => $item_id
            ]);
            exit;
        }
        
        // UPDATE ITEM
        elseif ($_POST['action'] === 'update_item') {
            $item_id = (int)$_POST['item_id'];
            $item_name = $_POST['item_name'];
            $description = $_POST['description'] ?? null;
            $category = $_POST['category'] ?? null;
            $stock = (int)$_POST['stock'];
            $unit_type = $_POST['unit_type'];
            $unit_price = (float)$_POST['unit_price'];
            $reorder_level = (int)$_POST['reorder_level'];
            $status = $_POST['status'] ?? 'active';
            
            // Verify item belongs to user's branch (if branch column exists and not admin)
            if ($items_branch_column_exists && !$view_all_branches) {
                $check_query = "SELECT item_id FROM items WHERE item_id = ? AND branch_id = ?";
                $check_stmt = $conn->prepare($check_query);
                $check_stmt->bind_param("ii", $item_id, $branch_id);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                
                if ($check_result->num_rows === 0) {
                    throw new Exception('Item not found or access denied');
                }
            }
            
            // Update with price columns if they exist
            if ($price_case_exists) {
                $update_query = "UPDATE items 
                               SET item_name = ?, description = ?, category = ?, stock = ?, unit_type = ?, 
                                   unit_price = ?, price_case = ?, price_inner_pack = ?, price_box = ?, price_carton = ?,
                                   reorder_level = ?, status = ?, updated_at = NOW() 
                               WHERE item_id = ?";
                $update_stmt = $conn->prepare($update_query);
                $update_stmt->bind_param("sssisdddddisi", 
                    $item_name, $description, $category, $stock, $unit_type, $unit_price,
                    $unit_price * 12, $unit_price * 6, $unit_price * 24, $unit_price * 48,
                    $reorder_level, $status, $item_id);
            } else {
                $update_query = "UPDATE items 
                               SET item_name = ?, description = ?, category = ?, stock = ?, unit_type = ?, unit_price = ?, reorder_level = ?, status = ?, updated_at = NOW() 
                               WHERE item_id = ?";
                $update_stmt = $conn->prepare($update_query);
                $update_stmt->bind_param("sssisdsii", $item_name, $description, $category, $stock, $unit_type, $unit_price, $reorder_level, $status, $item_id);
            }
            
            if (!$update_stmt->execute()) {
                throw new Exception('Failed to update item: ' . $update_stmt->error);
            }
            
            $conn->commit();
            
            echo json_encode([
                'success' => true,
                'message' => 'Item updated successfully'
            ]);
            exit;
        }
        
        // DELETE ITEM
        elseif ($_POST['action'] === 'delete_item') {
            $item_id = (int)$_POST['item_id'];
            
            // Verify item belongs to user's branch (if branch column exists and not admin)
            if ($items_branch_column_exists && !$view_all_branches) {
                $check_query = "SELECT item_id FROM items WHERE item_id = ? AND branch_id = ?";
                $check_stmt = $conn->prepare($check_query);
                $check_stmt->bind_param("ii", $item_id, $branch_id);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                
                if ($check_result->num_rows === 0) {
                    throw new Exception('Item not found or access denied');
                }
            }
            
            // Check if item is used in sales orders
            $check_so_query = "SELECT COUNT(*) as count FROM sales_order_items WHERE item_id = ?";
            $check_so_stmt = $conn->prepare($check_so_query);
            $check_so_stmt->bind_param("i", $item_id);
            $check_so_stmt->execute();
            $so_count = $check_so_stmt->get_result()->fetch_assoc()['count'];
            
            if ($so_count > 0) {
                // Soft delete - just update status to discontinued
                $update_query = "UPDATE items SET status = 'discontinued', updated_at = NOW() WHERE item_id = ?";
                $update_stmt = $conn->prepare($update_query);
                $update_stmt->bind_param("i", $item_id);
                $update_stmt->execute();
            } else {
                // Hard delete if not used
                $delete_query = "DELETE FROM items WHERE item_id = ?";
                $delete_stmt = $conn->prepare($delete_query);
                $delete_stmt->bind_param("i", $item_id);
                $delete_stmt->execute();
            }
            
            $conn->commit();
            
            echo json_encode([
                'success' => true,
                'message' => 'Item deleted successfully'
            ]);
            exit;
        }
        
        // GET ITEM DETAILS
        elseif ($_POST['action'] === 'get_item') {
            $item_id = (int)$_POST['item_id'];
            
            // Add branch filter if needed
            $query = "SELECT * FROM items WHERE item_id = ?";
            if ($items_branch_column_exists && !$view_all_branches) {
                $query .= " AND branch_id = ?";
                $stmt = $conn->prepare($query);
                $stmt->bind_param("ii", $item_id, $branch_id);
            } else {
                $stmt = $conn->prepare($query);
                $stmt->bind_param("i", $item_id);
            }
            
            $stmt->execute();
            $result = $stmt->get_result();
            $item = $result->fetch_assoc();
            
            if ($item) {
                echo json_encode([
                    'success' => true,
                    'item' => $item
                ]);
            } else {
                throw new Exception('Item not found or access denied');
            }
            exit;
        }
        
        // UPDATE STOCK AFTER SALES ORDER
        elseif ($_POST['action'] === 'update_stock_from_sales') {
            $item_id = (int)$_POST['item_id'];
            $quantity_sold = (int)$_POST['quantity'];
            $so_id = (int)$_POST['so_id'];
            
            // Verify item belongs to user's branch
            if ($items_branch_column_exists && !$view_all_branches) {
                $check_query = "SELECT item_id, stock FROM items WHERE item_id = ? AND branch_id = ?";
                $check_stmt = $conn->prepare($check_query);
                $check_stmt->bind_param("ii", $item_id, $branch_id);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                
                if ($check_result->num_rows === 0) {
                    throw new Exception('Item not found or access denied');
                }
                
                $item = $check_result->fetch_assoc();
                
                // Check if enough stock
                if ($item['stock'] < $quantity_sold) {
                    throw new Exception('Insufficient stock for item');
                }
                
                // Update stock
                $new_stock = $item['stock'] - $quantity_sold;
                $update_query = "UPDATE items SET stock = ?, updated_at = NOW() WHERE item_id = ?";
                $update_stmt = $conn->prepare($update_query);
                $update_stmt->bind_param("ii", $new_stock, $item_id);
                
                if (!$update_stmt->execute()) {
                    throw new Exception('Failed to update stock');
                }
                
                // Record inventory transaction (if table exists)
                $check_transaction_table = $conn->query("SHOW TABLES LIKE 'inventory_transactions'");
                if ($check_transaction_table && $check_transaction_table->num_rows > 0) {
                    $trans_query = "INSERT INTO inventory_transactions 
                                   (branch_id, item_id, transaction_type, quantity_changed, reference_type, reference_id, created_by, created_at) 
                                   VALUES (?, ?, 'out', ?, 'sales_order', ?, ?, NOW())";
                    $trans_stmt = $conn->prepare($trans_query);
                    $trans_stmt->bind_param("iiiii", $branch_id, $item_id, $quantity_sold, $so_id, $user_id);
                    $trans_stmt->execute();
                }
            }
            
            $conn->commit();
            
            echo json_encode([
                'success' => true,
                'message' => 'Stock updated successfully'
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

// FETCH ALL ITEMS FROM items TABLE WITH BRANCH FILTERING
if ($price_case_exists) {
    $items_query = "
        SELECT 
            item_id,
            item_code,
            item_name,
            description,
            category,
            stock as quantity_on_hand,
            unit_type,
            unit_price,
            price_case,
            price_inner_pack,
            price_box,
            price_carton,
            reorder_level,
            status,
            branch_id,
            created_at,
            updated_at
        FROM items
        WHERE 1=1
        $branch_condition
        ORDER BY item_code ASC
    ";
} else {
    $items_query = "
        SELECT 
            item_id,
            item_code,
            item_name,
            description,
            category,
            stock as quantity_on_hand,
            unit_type,
            unit_price,
            reorder_level,
            status,
            branch_id,
            created_at,
            updated_at
        FROM items
        WHERE 1=1
        $branch_condition
        ORDER BY item_code ASC
    ";
}

$items_result = $conn->query($items_query);
$items = $items_result->fetch_all(MYSQLI_ASSOC);

// GET NEXT ITEM CODE FOR AUTO-GENERATION (branch-specific)
$next_number = 1;
if (!empty($items)) {
    // Extract numbers from existing item codes (ITEM001, ITEM002, etc.)
    $numbers = [];
    foreach ($items as $item) {
        if (preg_match('/ITEM(\d+)/', $item['item_code'], $matches)) {
            $numbers[] = intval($matches[1]);
        }
    }
    if (!empty($numbers)) {
        $next_number = max($numbers) + 1;
    }
}
$next_item_code = 'ITEM' . str_pad($next_number, 3, '0', STR_PAD_LEFT);

// CALCULATE STATISTICS FROM REAL DATA (branch-specific)
$total_items = count($items);
$total_stock = array_sum(array_column($items, 'quantity_on_hand'));
$total_value = array_sum(array_map(function($item) {
    return $item['quantity_on_hand'] * $item['unit_price'];
}, $items));

$low_stock_items = array_filter($items, function($item) {
    return $item['quantity_on_hand'] <= $item['reorder_level'] && $item['quantity_on_hand'] > 0;
});
$low_stock_count = count($low_stock_items);

$out_of_stock = count(array_filter($items, fn($item) => $item['quantity_on_hand'] <= 0));

// STAT CARD VALUES - WITH PROPER LABELS
$statInventoryValue = '₱' . number_format($total_value / 1000, 1) . 'K';
$statTotalSKUs = $total_items;
$statNeedsAttention = $low_stock_count + $out_of_stock;
$statHealthyStock = round(($total_items - $low_stock_count - $out_of_stock) / max($total_items, 1) * 100) . '%';

// Stock status function
function getStockStatus($stock, $reorder_level) {
    if ($stock <= 0) return ['label' => 'Out of Stock', 'class' => 'bg-danger text-white'];
    if ($stock <= $reorder_level) return ['label' => 'Low Stock', 'class' => 'bg-warning text-dark'];
    if ($stock <= $reorder_level * 2) return ['label' => 'Normal', 'class' => 'bg-info text-white'];
    return ['label' => 'Adequate', 'class' => 'bg-success text-white'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Current Inventory - Branch Admin</title>
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
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@100;200;300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/inter-ui@3.19.3/inter.css">
    <!-- SheetJS for Excel Export -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <!-- SweetAlert2 -->
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
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
        }
        
        /* Alert for sales integration */
        .sales-integration-alert {
            background-color: #cff4fc;
            border-color: #b6effb;
            color: #055160;
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
                    <span class="nav-text">Branch Admin</span>
                </h3>
            </div>
            
            <div class="sidebar-menu">
                <ul class="nav flex-column">
                    <li class="nav-item">
                        <a class="nav-link active" href="current_inventory.php" data-title="Current Inventory">
                            <i class="bi bi-bar-chart-line"></i>
                            <span class="nav-text">Current Inventory</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="sales_order.php" data-title="Sales Orders">
                            <i class="bi bi-bag"></i>
                            <span class="nav-text">Sales Orders</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="pick_list_items.php" data-title="Pick List Items">
                            <i class="bi bi-list-check"></i>
                            <span class="nav-text">Pick List Items</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="bad_orders.php" data-title="Bad Orders">
                            <i class="bi bi-recycle"></i>
                            <span class="nav-text">Bad Orders</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="purchase_order.php" data-title="Purchase Orders">
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
                        <a class="nav-link" href="trip_tickets.php" data-title="Trip Tickets">
                            <i class="bi bi-ticket-perforated"></i>
                            <span class="nav-text">Trip Tickets</span>
                        </a>
                    </li>
                    <hr class="sidebar-divider">
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

        <!-- Main Content -->
        <div class="main-content" id="mainContent">
            <!-- DASHBOARD -->
            <div id="dashboardContent" class="page-content active">
                <div class="navbar-top">
                    <!-- MOBILE MENU BUTTON -->
                    <button class="mobile-menu-btn" id="mobileMenuBtn">
                        <i class="bi bi-list"></i>
                    </button>
                    <div class="page-title">
                        <h2>Current Inventory</h2>
                        <p id="dashboardSubtitle">
                            Real-time inventory from database
                        </p>
                    </div>
                </div>

                <!-- Branch Info Alerts -->
                <?php if (!$items_branch_column_exists): ?>
                    <div class="alert alert-info alert-dismissible fade show" role="alert">
                        <i class="bi bi-info-circle"></i> 
                        <strong>Branch filtering for items not yet set up.</strong> Please run this SQL in phpMyAdmin to enable branch-specific inventory data:
                        <br><br>
                        <code>ALTER TABLE items ADD COLUMN branch_id INT NULL;</code>
                        <br>
                        <code>ALTER TABLE items ADD FOREIGN KEY (branch_id) REFERENCES branches(branch_id);</code>
                        <br><br>
                        <button type="button" class="btn btn-sm btn-primary" onclick="copySQL('items')">
                            <i class="bi bi-files"></i> Copy SQL
                        </button>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- No Items Warning -->
                <?php if (empty($items) && $items_branch_column_exists && !$view_all_branches): ?>
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle"></i> 
                        No inventory items found for your branch. You can add new items using the "Add Item" button.
                    </div>
                <?php endif; ?>

                <!-- QUICK STATS - WITH PROPER ICONS FOR EACH METRIC -->
                <div class="row g-3 mb-4">
                    <!-- Stat 1: Total Inventory Value -->
                    <div class="col-xl-3 col-md-6">
                        <div class="stat-card total">
                            <i class="bi bi-coin stat-icon"></i>
                            <div class="stat-value"><?= $statInventoryValue ?></div>
                            <div class="stat-label">Total Inventory Value</div>
                            <small class="d-block mt-2"><i class="bi bi-box-seam"></i> <?= number_format($total_stock) ?> units</small>
                            <?php if ($items_branch_column_exists && !$view_all_branches): ?>
                               
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Stat 2: Total SKUs -->
                    <div class="col-xl-3 col-md-6">
                        <div class="stat-card sales">
                            <i class="bi bi-boxes stat-icon"></i>
                            <div class="stat-value"><?= $statTotalSKUs ?></div>
                            <div class="stat-label">Total SKUs</div>
                            <small class="d-block mt-2"><i class="bi bi-tag"></i> <?= count(array_unique(array_column($items, 'category'))) ?> categories</small>
                        </div>
                    </div>
                    
                    <!-- Stat 3: Needs Attention -->
                    <div class="col-xl-3 col-md-6">
                        <div class="stat-card pending">
                            <i class="bi bi-exclamation-triangle stat-icon"></i>
                            <div class="stat-value"><?= $statNeedsAttention ?></div>
                            <div class="stat-label">Needs Attention</div>
                            <small class="d-block mt-2"><?= $low_stock_count ?> low stock, <?= $out_of_stock ?> out</small>
                        </div>
                    </div>
                    
                    <!-- Stat 4: Healthy Stock -->
                    <div class="col-xl-3 col-md-6">
                        <div class="stat-card complete">
                            <i class="bi bi-check-circle stat-icon"></i>
                            <div class="stat-value"><?= $statHealthyStock ?></div>
                            <div class="stat-label">Healthy Stock</div>
                            <small class="d-block mt-2"><?= $total_items - $low_stock_count - $out_of_stock ?> items OK</small>
                        </div>
                    </div>
                </div>

                <!-- SEARCH AND FILTER CONTROLS -->
                <div class="row g-3 mb-4">
                    <div class="col-md-4">
                        <div class="search-box">
                            <i class="bi bi-search"></i>
                            <input type="text" class="form-control" id="searchInput" placeholder="Search by item code, name, or category..." onkeyup="filterItems()">
                        </div>
                    </div>
                    <div class="col-md-3">
                        <select class="form-select" id="categoryFilter" onchange="filterItems()">
                            <option value="">All Categories</option>
                            <?php 
                            $unique_categories = array_unique(array_column($items, 'category'));
                            foreach ($unique_categories as $cat): 
                                if (!empty($cat)):
                            ?>
                                <option value="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars($cat) ?></option>
                            <?php 
                                endif;
                            endforeach; 
                            ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <select class="form-select" id="statusFilter" onchange="filterItems()">
                            <option value="">All Status</option>
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                            <option value="discontinued">Discontinued</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <select class="form-select" id="stockFilter" onchange="filterItems()">
                            <option value="">Stock Level</option>
                            <option value="low">Low Stock</option>
                            <option value="normal">Normal</option>
                            <option value="adequate">Adequate</option>
                            <option value="out">Out of Stock</option>
                        </select>
                    </div>
                </div>

                <!-- INVENTORY TABLE - REAL DATA FROM DATABASE WITH BRANCH FILTERING -->
                <div class="data-table">
                    <div class="table-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">Current Inventory Items</h5>
                        <div class="d-flex gap-2">
                            <span class="text-muted me-2">Total Value: ₱<?= number_format($total_value, 2) ?></span>
                            <?php if ($items_branch_column_exists && $view_all_branches): ?>
                                <span class="badge bg-success align-self-center">All Branches</span>
                            <?php endif; ?>
                            <button class="btn btn-success" onclick="exportToExcel()">
                                <i class="bi bi-file-earmark-excel"></i> Export to Excel
                            </button>
                            <button class="btn btn-primary" onclick="showAddItemModal()">
                                <i class="bi bi-plus-circle"></i> Add Item
                            </button>
                        </div>
                    </div>
                    <div class="table-container">
                        <table class="table custom-table compact-table" id="inventoryTable">
                            <thead>
                                <tr>
                                    <th>Item Code</th>
                                    <th>Item Name</th>
                                    <th>Category</th>
                                    <?php if ($items_branch_column_exists && $view_all_branches): ?>
                                        <th>Branch</th>
                                    <?php endif; ?>
                                    <th>Stock</th>
                                    <th>Unit</th>
                                    <th>Unit Price</th>
                                    <th>Reorder Level</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="inventoryTableBody">
                                <?php if (empty($items)): ?>
                                <tr>
                                    <td colspan="<?= ($items_branch_column_exists && $view_all_branches) ? '10' : '9' ?>" class="text-center py-4">
                                        <i class="bi bi-inbox fs-1 d-block text-muted mb-2"></i>
                                        <p class="text-muted mb-0">
                                            No items found
                                            <?php if ($items_branch_column_exists && !$view_all_branches): ?>
                                                for your branch
                                            <?php endif; ?>
                                        </p>
                                        <button class="btn btn-sm btn-primary mt-2" onclick="showAddItemModal()">
                                            <i class="bi bi-plus-circle"></i> Add Item
                                        </button>
                                    </td>
                                </tr>
                                <?php else: ?>
                                    <?php foreach ($items as $item): 
                                        $stock_status = getStockStatus($item['quantity_on_hand'], $item['reorder_level']);
                                    ?>
                                    <tr class="inventory-row" 
                                        data-id="<?= $item['item_id'] ?>"
                                        data-code="<?= htmlspecialchars($item['item_code']) ?>"
                                        data-name="<?= htmlspecialchars($item['item_name']) ?>"
                                        data-category="<?= htmlspecialchars($item['category'] ?? '') ?>"
                                        data-status="<?= $item['status'] ?>"
                                        data-stock="<?= $item['quantity_on_hand'] ?>"
                                        data-reorder="<?= $item['reorder_level'] ?>"
                                        data-price="<?= $item['unit_price'] ?>"
                                        data-unit="<?= $item['unit_type'] ?>"
                                        data-description="<?= htmlspecialchars($item['description'] ?? '') ?>"
                                        data-branch="<?= $item['branch_id'] ?? '' ?>">
                                        <td><strong><?= htmlspecialchars($item['item_code']) ?></strong></td>
                                        <td>
                                            <?= htmlspecialchars($item['item_name']) ?>
                                            <?php if (!empty($item['description'])): ?>
                                                <small class="d-block text-muted"><?= htmlspecialchars($item['description']) ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= htmlspecialchars($item['category'] ?? 'Uncategorized') ?></td>
                                        <?php if ($items_branch_column_exists && $view_all_branches): ?>
                                            <td>
                                                <span class="badge bg-info">
                                                    Branch <?= $item['branch_id'] ?? 'N/A' ?>
                                                </span>
                                            </td>
                                        <?php endif; ?>
                                        <td>
                                            <span class="<?= $item['quantity_on_hand'] <= $item['reorder_level'] ? 'text-danger fw-bold' : '' ?>">
                                                <?= number_format($item['quantity_on_hand']) ?>
                                            </span>
                                            <span class="badge <?= $stock_status['class'] ?> ms-1"><?= $stock_status['label'] ?></span>
                                        </td>
                                        <td><?= ucfirst(str_replace('-', ' ', $item['unit_type'])) ?></td>
                                        <td>₱<?= number_format($item['unit_price'], 2) ?></td>
                                        <td><?= $item['reorder_level'] ?></td>
                                        <td>
                                            <?php
                                            $status_class = match($item['status']) {
                                                'active' => 'bg-success',
                                                'inactive' => 'bg-secondary',
                                                'discontinued' => 'bg-danger',
                                                default => 'bg-warning'
                                            };
                                            ?>
                                            <span class="badge <?= $status_class ?>"><?= ucfirst($item['status']) ?></span>
                                        </td>
                                        <td>
                                             <div class="action-btn" role="group">
                                            <button class="btn-action btn-view" onclick="viewItem(<?= $item['item_id'] ?>)" title="View">
                                                <i class="bi bi-eye"></i>
                                            </button>
                                            <button class="btn-action btn-edit" onclick="editItem(<?= $item['item_id'] ?>)" title="Edit">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                            <button class="btn-action btn-delete" onclick="deleteItem(<?= $item['item_id'] ?>)" title="Delete">
                                                <i class="bi bi-trash"></i>
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

                <!-- LOW STOCK ALERT - REAL DATA -->
                <?php if ($low_stock_count > 0): ?>
                <div class="row g-3 mt-3">
                    <div class="col-12">
                        <div class="alert alert-warning alert-dismissible fade show mb-0" role="alert">
                            <i class="bi bi-exclamation-triangle-fill me-2"></i>
                            <strong>Low Stock Alert!</strong> <?= $low_stock_count ?> item(s) are below reorder level.
                            <?php if ($items_branch_column_exists && !$view_all_branches): ?>
                                in your branch.
                            <?php endif; ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ADD ITEM MODAL -->
    <div class="modal fade" id="itemModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="itemModalTitle"><i class="bi bi-plus-circle me-2"></i>Add New Item</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="itemForm">
                        <input type="hidden" id="itemId">
                        <?php if ($items_branch_column_exists && !$view_all_branches): ?>
                            <input type="hidden" name="branch_id" value="<?= $branch_id ?>">
                        <?php endif; ?>
                        
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle"></i>
                            <?php if ($items_branch_column_exists && !$view_all_branches): ?>
                                Adding item to Branch <?= $branch_id ?>
                            <?php else: ?>
                                Item code is auto-generated
                            <?php endif; ?>
                        </div>
                        
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="itemCode" class="form-label">Item Code *</label>
                                <input type="text" class="form-control" id="itemCode" value="<?= $next_item_code ?>" readonly required>
                                <small class="text-muted">Auto-generated</small>
                            </div>
                            <div class="col-md-6">
                                <label for="itemName" class="form-label">Item Name *</label>
                                <input type="text" class="form-control" id="itemName" required>
                            </div>
                            <div class="col-12">
                                <label for="description" class="form-label">Description</label>
                                <textarea class="form-control" id="description" rows="2"></textarea>
                            </div>
                            <div class="col-md-4">
                                <label for="category" class="form-label">Category</label>
                                <input type="text" class="form-control" id="category">
                            </div>
                            <div class="col-md-4">
                                <label for="stock" class="form-label">Current Stock *</label>
                                <input type="number" class="form-control" id="stock" min="0" required>
                            </div>
                            <div class="col-md-4">
                                <label for="unitType" class="form-label">Unit Type *</label>
                                <select class="form-select" id="unitType" required>
                                    <option value="piece">Piece</option>
                                    <option value="case">Case</option>
                                    <option value="box">Box</option>
                                    <option value="carton">Carton</option>
                                    <option value="inner-pack">Inner Pack</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label for="unitPrice" class="form-label">Unit Price (₱) *</label>
                                <input type="number" class="form-control" id="unitPrice" min="0" step="0.01" required>
                            </div>
                            <div class="col-md-4">
                                <label for="reorderLevel" class="form-label">Reorder Level *</label>
                                <input type="number" class="form-control" id="reorderLevel" min="0" required>
                            </div>
                            <div class="col-md-4">
                                <label for="status" class="form-label">Status</label>
                                <select class="form-select" id="status">
                                    <option value="active">Active</option>
                                    <option value="inactive">Inactive</option>
                                    <option value="discontinued">Discontinued</option>
                                </select>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="saveItem()">Save Item</button>
                </div>
            </div>
        </div>
    </div>

    <!-- VIEW ITEM MODAL -->
    <div class="modal fade" id="viewItemModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title"><i class="bi bi-eye me-2"></i>Item Details</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row" id="viewItemContent">
                        <!-- Content will be populated by JavaScript -->
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-warning" onclick="editFromView()">Edit Item</button>
                </div>
            </div>
        </div>
    </div>

   <!-- EDIT ITEM MODAL - CORRECTED VERSION -->
<div class="modal fade" id="editItemModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg"> <!-- Ito ang dapat na class -->
        <div class="modal-content">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>Edit Item</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="editItemForm">
                    <input type="hidden" id="editItemId">
                    
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="editItemCode" class="form-label">Item Code</label>
                            <input type="text" class="form-control" id="editItemCode" readonly>
                        </div>
                        <div class="col-md-6">
                            <label for="editItemName" class="form-label">Item Name *</label>
                            <input type="text" class="form-control" id="editItemName" required>
                        </div>
                        <div class="col-12">
                            <label for="editDescription" class="form-label">Description</label>
                            <textarea class="form-control" id="editDescription" rows="2"></textarea>
                        </div>
                        <div class="col-md-4">
                            <label for="editCategory" class="form-label">Category</label>
                            <input type="text" class="form-control" id="editCategory">
                        </div>
                        <div class="col-md-4">
                            <label for="editStock" class="form-label">Current Stock *</label>
                            <input type="number" class="form-control" id="editStock" min="0" required>
                        </div>
                        <div class="col-md-4">
                            <label for="editUnitType" class="form-label">Unit Type *</label>
                            <select class="form-select" id="editUnitType" required>
                                <option value="piece">Piece</option>
                                <option value="case">Case</option>
                                <option value="box">Box</option>
                                <option value="carton">Carton</option>
                                <option value="inner-pack">Inner Pack</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label for="editUnitPrice" class="form-label">Unit Price (₱) *</label>
                            <input type="number" class="form-control" id="editUnitPrice" min="0" step="0.01" required>
                        </div>
                        <div class="col-md-4">
                            <label for="editReorderLevel" class="form-label">Reorder Level *</label>
                            <input type="number" class="form-control" id="editReorderLevel" min="0" required>
                        </div>
                        <div class="col-md-4">
                            <label for="editStatus" class="form-label">Status</label>
                            <select class="form-select" id="editStatus">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                                <option value="discontinued">Discontinued</option>
                            </select>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="updateItem()">Update Item</button>
            </div>
        </div>
    </div>
</div>

    <!-- DELETE CONFIRMATION MODAL -->
    <div class="modal fade" id="deleteItemModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title"><i class="bi bi-trash me-2"></i>Confirm Delete</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to delete this item?</p>
                    <p class="fw-bold" id="deleteItemCode"></p>
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        This action cannot be undone.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-danger" onclick="confirmDelete()">Delete Item</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
    // ========== GLOBAL VARIABLES ==========
    let currentItemId = null;
    const branchId = <?php echo $branch_id; ?>;
    const viewAllBranches = <?php echo $view_all_branches ? 'true' : 'false'; ?>;
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

    // ========== ITEM FUNCTIONS ==========
    document.addEventListener('DOMContentLoaded', function() {
        console.log("Current Inventory - Live Database Mode");
        console.log("Branch ID:", branchId);
        console.log("View All Branches:", viewAllBranches);
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

    // ========== MODAL FUNCTIONS ==========
    
    // Show Add Item Modal
    function showAddItemModal() {
        document.getElementById('itemModalTitle').innerHTML = '<i class="bi bi-plus-circle me-2"></i>Add New Item';
        document.getElementById('itemForm').reset();
        document.getElementById('itemId').value = '';
        document.getElementById('itemCode').value = '<?= $next_item_code ?>';
        document.getElementById('status').value = 'active';
        new bootstrap.Modal(document.getElementById('itemModal')).show();
    }

    // View Item
    function viewItem(id) {
        showLoading();
        
        // Fetch item details via AJAX
        const formData = new FormData();
        formData.append('action', 'get_item');
        formData.append('item_id', id);
        
        fetch('current_inventory.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            Swal.close();
            
            if (data.success) {
                const item = data.item;
                
                const content = document.getElementById('viewItemContent');
                content.innerHTML = `
                    <div class="col-md-6">
                        <table class="table table-sm table-borderless">
                            <tr>
                                <th width="40%">Item Code:</th>
                                <td><strong>${item.item_code}</strong></td>
                            </tr>
                            <tr>
                                <th>Item Name:</th>
                                <td>${item.item_name}</td>
                            </tr>
                            <tr>
                                <th>Category:</th>
                                <td>${item.category || 'Uncategorized'}</td>
                            </tr>
                            <tr>
                                <th>Description:</th>
                                <td>${item.description || 'No description'}</td>
                            </tr>
                            <tr>
                                <th>Status:</th>
                                <td>
                                    <span class="badge bg-${item.status === 'active' ? 'success' : item.status === 'inactive' ? 'secondary' : 'danger'}">
                                        ${item.status.charAt(0).toUpperCase() + item.status.slice(1)}
                                    </span>
                                </td>
                            </tr>
                            ${itemsBranchColumnExists ? `
                            <tr>
                                <th>Branch:</th>
                                <td>
                                    <span class="badge bg-info">Branch ${item.branch_id || 'N/A'}</span>
                                </td>
                            </tr>
                            ` : ''}
                        </table>
                    </div>
                    <div class="col-md-6">
                        <table class="table table-sm table-borderless">
                            <tr>
                                <th width="40%">Stock:</th>
                                <td>${Number(item.stock).toLocaleString()} ${item.unit_type}</td>
                            </tr>
                            <tr>
                                <th>Unit Price:</th>
                                <td>₱${Number(item.unit_price).toFixed(2)}</td>
                            </tr>
                            <tr>
                                <th>Reorder Level:</th>
                                <td>${item.reorder_level}</td>
                            </tr>
                            <tr>
                                <th>Stock Value:</th>
                                <td>₱${(Number(item.stock) * Number(item.unit_price)).toFixed(2)}</td>
                            </tr>
                            <tr>
                                <th>Created At:</th>
                                <td>${new Date(item.created_at).toLocaleString()}</td>
                            </tr>
                            <tr>
                                <th>Last Updated:</th>
                                <td>${new Date(item.updated_at).toLocaleString()}</td>
                            </tr>
                        </table>
                    </div>
                `;
                
                currentItemId = id;
                new bootstrap.Modal(document.getElementById('viewItemModal')).show();
            } else {
                Swal.fire('Error', data.message, 'error');
            }
        })
        .catch(error => {
            Swal.close();
            Swal.fire('Error', 'An error occurred while fetching item details', 'error');
        });
    }

    // Edit from View Modal
    function editFromView() {
        bootstrap.Modal.getInstance(document.getElementById('viewItemModal')).hide();
        setTimeout(() => {
            editItem(currentItemId);
        }, 300);
    }

    // Edit Item
    function editItem(id) {
        showLoading();
        
        // Fetch item details via AJAX
        const formData = new FormData();
        formData.append('action', 'get_item');
        formData.append('item_id', id);
        
        fetch('current_inventory.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            Swal.close();
            
            if (data.success) {
                const item = data.item;
                
                document.getElementById('editItemId').value = item.item_id;
                document.getElementById('editItemCode').value = item.item_code;
                document.getElementById('editItemName').value = item.item_name;
                document.getElementById('editDescription').value = item.description || '';
                document.getElementById('editCategory').value = item.category || '';
                document.getElementById('editStock').value = item.stock;
                document.getElementById('editUnitType').value = item.unit_type;
                document.getElementById('editUnitPrice').value = item.unit_price;
                document.getElementById('editReorderLevel').value = item.reorder_level;
                document.getElementById('editStatus').value = item.status;
                
                currentItemId = id;
                new bootstrap.Modal(document.getElementById('editItemModal')).show();
            } else {
                Swal.fire('Error', data.message, 'error');
            }
        })
        .catch(error => {
            Swal.close();
            Swal.fire('Error', 'An error occurred while fetching item details', 'error');
        });
    }

    // Save Item (Add)
    function saveItem() {
        // Validate required fields
        const itemName = document.getElementById('itemName').value;
        const stock = document.getElementById('stock').value;
        const unitPrice = document.getElementById('unitPrice').value;
        const reorderLevel = document.getElementById('reorderLevel').value;
        
        if (!itemName) {
            Swal.fire('Warning', 'Item Name is required', 'warning');
            return;
        }
        
        if (!stock || stock < 0) {
            Swal.fire('Warning', 'Valid Stock quantity is required', 'warning');
            return;
        }
        
        if (!unitPrice || unitPrice < 0) {
            Swal.fire('Warning', 'Valid Unit Price is required', 'warning');
            return;
        }
        
        if (!reorderLevel || reorderLevel < 0) {
            Swal.fire('Warning', 'Valid Reorder Level is required', 'warning');
            return;
        }
        
        showLoading();
        
        // Prepare form data
        const formData = new FormData(document.getElementById('itemForm'));
        formData.append('action', 'add_item');
        
        fetch('current_inventory.php', {
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
                    bootstrap.Modal.getInstance(document.getElementById('itemModal')).hide();
                    location.reload();
                });
            } else {
                Swal.fire('Error', data.message, 'error');
            }
        })
        .catch(error => {
            Swal.close();
            Swal.fire('Error', 'An error occurred while saving the item', 'error');
        });
    }

    // Update Item
    function updateItem() {
        // Validate required fields
        const itemName = document.getElementById('editItemName').value;
        const stock = document.getElementById('editStock').value;
        const unitPrice = document.getElementById('editUnitPrice').value;
        const reorderLevel = document.getElementById('editReorderLevel').value;
        
        if (!itemName) {
            Swal.fire('Warning', 'Item Name is required', 'warning');
            return;
        }
        
        if (!stock || stock < 0) {
            Swal.fire('Warning', 'Valid Stock quantity is required', 'warning');
            return;
        }
        
        if (!unitPrice || unitPrice < 0) {
            Swal.fire('Warning', 'Valid Unit Price is required', 'warning');
            return;
        }
        
        if (!reorderLevel || reorderLevel < 0) {
            Swal.fire('Warning', 'Valid Reorder Level is required', 'warning');
            return;
        }
        
        showLoading();
        
        // Prepare form data
        const formData = new FormData(document.getElementById('editItemForm'));
        formData.append('action', 'update_item');
        formData.append('item_id', document.getElementById('editItemId').value);
        
        fetch('current_inventory.php', {
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
                    bootstrap.Modal.getInstance(document.getElementById('editItemModal')).hide();
                    location.reload();
                });
            } else {
                Swal.fire('Error', data.message, 'error');
            }
        })
        .catch(error => {
            Swal.close();
            Swal.fire('Error', 'An error occurred while updating the item', 'error');
        });
    }

    // Delete Item
    function deleteItem(id) {
        const row = document.querySelector(`.inventory-row[data-id="${id}"]`);
        if (!row) return;
        
        document.getElementById('deleteItemCode').textContent = row.dataset.code;
        currentItemId = id;
        new bootstrap.Modal(document.getElementById('deleteItemModal')).show();
    }

    // Confirm Delete
    function confirmDelete() {
        showLoading();
        
        const formData = new FormData();
        formData.append('action', 'delete_item');
        formData.append('item_id', currentItemId);
        
        fetch('current_inventory.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            Swal.close();
            
            if (data.success) {
                Swal.fire({
                    icon: 'success',
                    title: 'Deleted!',
                    text: data.message,
                    timer: 2000,
                    showConfirmButton: false
                }).then(() => {
                    bootstrap.Modal.getInstance(document.getElementById('deleteItemModal')).hide();
                    location.reload();
                });
            } else {
                Swal.fire('Error', data.message, 'error');
            }
        })
        .catch(error => {
            Swal.close();
            Swal.fire('Error', 'An error occurred while deleting the item', 'error');
        });
    }

    // ========== FILTER FUNCTIONS ==========
    
    // Filter items
    function filterItems() {
        const searchTerm = document.getElementById('searchInput').value.toLowerCase();
        const category = document.getElementById('categoryFilter').value;
        const status = document.getElementById('statusFilter').value;
        const stockLevel = document.getElementById('stockFilter').value;
        
        const rows = document.querySelectorAll('.inventory-row');
        let visibleCount = 0;
        
        rows.forEach(row => {
            const code = row.dataset.code.toLowerCase();
            const name = row.dataset.name.toLowerCase();
            const rowCategory = row.dataset.category?.toLowerCase() || '';
            const rowStatus = row.dataset.status;
            const stock = parseInt(row.dataset.stock);
            const reorder = parseInt(row.dataset.reorder);
            
            let matchesSearch = searchTerm === '' || code.includes(searchTerm) || name.includes(searchTerm);
            let matchesCategory = category === '' || rowCategory === category.toLowerCase();
            let matchesStatus = status === '' || rowStatus === status;
            
            let matchesStock = true;
            if (stockLevel === 'low') matchesStock = stock <= reorder && stock > 0;
            else if (stockLevel === 'normal') matchesStock = stock > reorder && stock <= reorder * 2;
            else if (stockLevel === 'adequate') matchesStock = stock > reorder * 2;
            else if (stockLevel === 'out') matchesStock = stock <= 0;
            
            if (matchesSearch && matchesCategory && matchesStatus && matchesStock) {
                row.style.display = '';
                visibleCount++;
            } else {
                row.style.display = 'none';
            }
        });
    }

    // Filter low stock
    function filterLowStock() {
        document.getElementById('stockFilter').value = 'low';
        filterItems();
    }

    // ========== EXCEL EXPORT FUNCTION ==========
    function exportToExcel() {
        const rows = document.querySelectorAll('.inventory-row:not([style*="display: none"])');
        if (rows.length === 0) {
            Swal.fire('Warning', 'No items to export', 'warning');
            return;
        }
        
        // Prepare data array for Excel
        const excelData = [];
        
        // Add headers
        const headers = [
            'Item Code',
            'Item Name',
            'Category',
            ...(itemsBranchColumnExists && viewAllBranches ? ['Branch'] : []),
            'Stock',
            'Unit Type',
            'Unit Price (₱)',
            'Reorder Level',
            'Status',
            'Stock Value (₱)'
        ];
        excelData.push(headers);

        // Add data rows
        rows.forEach(row => {
            if (row.style.display !== 'none') {
                const code = row.dataset.code;
                const name = row.dataset.name;
                const category = row.dataset.category || 'Uncategorized';
                const stock = parseInt(row.dataset.stock);
                const unit = row.dataset.unit;
                const price = parseFloat(row.dataset.price);
                const reorder = parseInt(row.dataset.reorder);
                const status = row.dataset.status;
                const value = stock * price;
                const branch = row.dataset.branch;
                
                const rowData = [
                    code,
                    name,
                    category,
                    ...(itemsBranchColumnExists && viewAllBranches ? [`Branch ${branch || 'N/A'}`] : []),
                    stock,
                    unit,
                    price,
                    reorder,
                    status.charAt(0).toUpperCase() + status.slice(1),
                    parseFloat(value.toFixed(2))
                ];
                
                excelData.push(rowData);
            }
        });

        // Create workbook and worksheet
        const wb = XLSX.utils.book_new();
        const ws = XLSX.utils.aoa_to_sheet(excelData);

        // Set column widths
        const colWidths = [
            { wch: 15 }, // Item Code
            { wch: 30 }, // Item Name
            { wch: 20 }, // Category
            ...(itemsBranchColumnExists && viewAllBranches ? [{ wch: 12 }] : []), // Branch
            { wch: 12 }, // Stock
            { wch: 12 }, // Unit Type
            { wch: 15 }, // Unit Price
            { wch: 15 }, // Reorder Level
            { wch: 15 }, // Status
            { wch: 18 }  // Stock Value
        ];
        ws['!cols'] = colWidths;

        // Add worksheet to workbook
        XLSX.utils.book_append_sheet(wb, ws, 'Current Inventory');

        // Generate filename with current date and branch info
        const date = new Date();
        const dateStr = date.toISOString().slice(0,10).replace(/-/g, '');
        let filename = `Current_Inventory_${dateStr}`;
        if (itemsBranchColumnExists && !viewAllBranches) {
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

    // ========== STOCK UPDATE FUNCTION (called from sales order) ==========
    function updateStockFromSales(itemId, quantity, soId) {
        showLoading();
        
        const formData = new FormData();
        formData.append('action', 'update_stock_from_sales');
        formData.append('item_id', itemId);
        formData.append('quantity', quantity);
        formData.append('so_id', soId);
        
        fetch('current_inventory.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            Swal.close();
            
            if (data.success) {
                console.log('Stock updated successfully for item ' + itemId);
                // Optionally refresh the page or update the specific row
                // location.reload();
            } else {
                console.error('Failed to update stock:', data.message);
                Swal.fire('Error', data.message, 'error');
            }
        })
        .catch(error => {
            Swal.close();
            console.error('Error updating stock:', error);
        });
    }

    // ========== COPY SQL FUNCTION ==========
    function copySQL(table) {
        let sql = '';
        if (table === 'items') {
            sql = "ALTER TABLE items ADD COLUMN branch_id INT NULL;\nALTER TABLE items ADD FOREIGN KEY (branch_id) REFERENCES branches(branch_id);";
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

    // ========== LOGOUT FUNCTION ==========
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

    // ========== KEYBOARD SHORTCUTS ==========
    document.addEventListener('keydown', function(e) {
        if (e.ctrlKey && e.key === 'b' && window.innerWidth > 992) {
            e.preventDefault();
            toggleSidebar();
        } else if (e.ctrlKey && e.key === 'f') {
            e.preventDefault();
            document.getElementById('searchInput').focus();
        } else if (e.ctrlKey && e.key === 'n') {
            e.preventDefault();
            showAddItemModal();
        }
    });

    // ========== EXPOSE FUNCTION FOR SALES ORDER PAGE ==========
    // This makes the function available globally for other pages to call
    window.updateInventoryFromSales = updateStockFromSales;
    </script>
</body>
</html>