<?php
require_once '../config/database.php';
require_once '../config/session_handler.php';

requireLogin();
requireRole(['warehouse']);

// Get current user info and branch context
$user_id = $_SESSION['user_id'];
$user_name = isset($_SESSION['first_name']) ? $_SESSION['first_name'] . ' ' . $_SESSION['last_name'] : 'Warehouse User';
$user_role = isset($_SESSION['role']) ? $_SESSION['role'] : 'warehouse';
$branch_id = $_SESSION['branch_id'] ?? 0;
$view_all_branches = $_SESSION['view_all_branches'] ?? false;

// IMPORTANT: Get user's category directly from database
$user_category = '';
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

// Debug - you can remove this after testing
if (empty($user_category)) {
    error_log("WARNING: User ID $user_id has no category assigned in users table");
}

// Get branch name
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

// Check if branch_id column exists in items table
$items_branch_column_exists = false;
$check_items_column = $conn->query("SHOW COLUMNS FROM items LIKE 'branch_id'");
if ($check_items_column && $check_items_column->num_rows > 0) {
    $items_branch_column_exists = true;
}

// Check if price columns exist
$price_case_exists = false;
$check_price_case = $conn->query("SHOW COLUMNS FROM items LIKE 'price_case'");
if ($check_price_case && $check_price_case->num_rows > 0) {
    $price_case_exists = true;
}

$price_inner_exists = false;
$check_price_inner = $conn->query("SHOW COLUMNS FROM items LIKE 'price_inner_pack'");
if ($check_price_inner && $check_price_inner->num_rows > 0) {
    $price_inner_exists = true;
}

$price_box_exists = false;
$check_price_box = $conn->query("SHOW COLUMNS FROM items LIKE 'price_box'");
if ($check_price_box && $check_price_box->num_rows > 0) {
    $price_box_exists = true;
}

$price_carton_exists = false;
$check_price_carton = $conn->query("SHOW COLUMNS FROM items LIKE 'price_carton'");
if ($check_price_carton && $check_price_carton->num_rows > 0) {
    $price_carton_exists = true;
}

// Check if inventory_transactions table exists
$inventory_transactions_exists = false;
$check_inv_trans = $conn->query("SHOW TABLES LIKE 'inventory_transactions'");
if ($check_inv_trans && $check_inv_trans->num_rows > 0) {
    $inventory_transactions_exists = true;
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $response = ['success' => false, 'message' => ''];
    
    // Edit item
    if (isset($_POST['edit_item'])) {
        try {
            $conn->begin_transaction();
            
            $item_id = (int)$_POST['item_id'];
            $item_name = trim($_POST['item_name']);
            $description = !empty($_POST['description']) ? trim($_POST['description']) : null;
            
            // Get the original item to check category
            $check_category_query = "SELECT category, branch_id FROM items WHERE item_id = ?";
            $check_category_stmt = $conn->prepare($check_category_query);
            $check_category_stmt->bind_param("i", $item_id);
            $check_category_stmt->execute();
            $check_result = $check_category_stmt->get_result();
            $original_item = $check_result->fetch_assoc();
            $check_category_stmt->close();
            
            if (!$original_item) {
                throw new Exception('Item not found');
            }
            
            // Check branch access
            if (!$view_all_branches && $original_item['branch_id'] != $branch_id) {
                throw new Exception('You do not have permission to edit this item');
            }
            
            // CRITICAL: Check if user's category matches item's category
            if (empty($user_category)) {
                throw new Exception('You do not have a category assigned in the users table. Please contact administrator.');
            }
            
            if ($original_item['category'] != $user_category) {
                throw new Exception('You can only edit items in your assigned category: ' . $user_category);
            }
            
            // Force category to user's category (cannot change)
            $category = $user_category;
            
            $stock = (int)$_POST['stock'];
            $unit_type = $_POST['unit_type'] ?? 'piece';
            $reorder_level = (int)$_POST['reorder_level'];
            $status = $_POST['status'] ?? 'active';
            
            // Validate
            if (empty($item_name)) {
                throw new Exception('Item name is required');
            }
            
            if ($stock < 0) {
                throw new Exception('Stock cannot be negative');
            }
            
            if ($reorder_level < 0) {
                throw new Exception('Reorder level cannot be negative');
            }
            
            // Get old stock to check for changes
            $stock_query = "SELECT stock FROM items WHERE item_id = ?";
            $stock_stmt = $conn->prepare($stock_query);
            $stock_stmt->bind_param("i", $item_id);
            $stock_stmt->execute();
            $result = $stock_stmt->get_result();
            $old_stock = $result->fetch_assoc()['stock'];
            
            // Update
            $stmt = $conn->prepare("UPDATE items SET item_name = ?, description = ?, category = ?, stock = ?, unit_type = ?, reorder_level = ?, status = ?, updated_at = NOW() WHERE item_id = ?");
            $stmt->bind_param("sssisisi", $item_name, $description, $category, $stock, $unit_type, $reorder_level, $status, $item_id);
            
            if (!$stmt->execute()) {
                throw new Exception('Failed to update item: ' . $stmt->error);
            }
            
            // Record inventory transaction if stock changed and table exists
            if ($stock != $old_stock && $inventory_transactions_exists) {
                $quantity_changed = $stock - $old_stock;
                $transaction_type = $quantity_changed > 0 ? 'in' : 'out';
                $quantity_changed = abs($quantity_changed);
                
                $trans_query = "INSERT INTO inventory_transactions 
                               (branch_id, item_id, transaction_type, quantity_changed, reference_type, reference_id, created_by, created_at) 
                               VALUES (?, ?, ?, ?, 'stock_adjustment', ?, ?, NOW())";
                $trans_stmt = $conn->prepare($trans_query);
                $trans_stmt->bind_param("iiiiii", $branch_id, $item_id, $transaction_type, $quantity_changed, $item_id, $user_id);
                $trans_stmt->execute();
            }
            
            $conn->commit();
            
            $response['success'] = true;
            $response['message'] = 'Item updated successfully';
            
        } catch (Exception $e) {
            $conn->rollback();
            $response['message'] = $e->getMessage();
        }
        
        header('Content-Type: application/json');
        echo json_encode($response);
        exit;
    }
    
    // Get item details
    if (isset($_POST['get_item'])) {
        $item_id = (int)$_POST['item_id'];
        
        // First check if user has access to this item based on category
        if (empty($user_category)) {
            echo json_encode(['success' => false, 'message' => 'You do not have a category assigned in the users table. Please contact administrator.']);
            exit;
        }
        
        $check_query = "SELECT category, branch_id FROM items WHERE item_id = ?";
        $check_stmt = $conn->prepare($check_query);
        $check_stmt->bind_param("i", $item_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        $item_data = $check_result->fetch_assoc();
        $check_stmt->close();
        
        if (!$item_data) {
            echo json_encode(['success' => false, 'message' => 'Item not found']);
            exit;
        }
        
        // Check branch access
        if (!$view_all_branches && $item_data['branch_id'] != $branch_id) {
            echo json_encode(['success' => false, 'message' => 'You do not have permission to view this item']);
            exit;
        }
        
        // CRITICAL: Check if item category matches user's category
        if ($item_data['category'] != $user_category) {
            echo json_encode(['success' => false, 'message' => 'You can only view items in your assigned category: ' . $user_category]);
            exit;
        }
        
        $stmt = $conn->prepare("SELECT * FROM items WHERE item_id = ?");
        $stmt->bind_param("i", $item_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $item = $result->fetch_assoc();
        
        if ($item) {
            echo json_encode(['success' => true, 'item' => $item]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Item not found']);
        }
        exit;
    }
    
    // Get inventory transactions
    if (isset($_POST['get_transactions']) && $inventory_transactions_exists) {
        $item_id = (int)$_POST['item_id'];
        
        // First check if user has access to this item based on category
        if (empty($user_category)) {
            echo json_encode(['success' => false, 'message' => 'You do not have a category assigned in the users table. Please contact administrator.']);
            exit;
        }
        
        $check_query = "SELECT category, branch_id FROM items WHERE item_id = ?";
        $check_stmt = $conn->prepare($check_query);
        $check_stmt->bind_param("i", $item_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        $item_data = $check_result->fetch_assoc();
        $check_stmt->close();
        
        if (!$item_data) {
            echo json_encode(['success' => false, 'message' => 'Item not found']);
            exit;
        }
        
        // Check branch access
        if (!$view_all_branches && $item_data['branch_id'] != $branch_id) {
            echo json_encode(['success' => false, 'message' => 'You do not have permission to view this item']);
            exit;
        }
        
        // CRITICAL: Check if item category matches user's category
        if ($item_data['category'] != $user_category) {
            echo json_encode(['success' => false, 'message' => 'You can only view transactions for items in your assigned category: ' . $user_category]);
            exit;
        }
        
        $trans_query = "SELECT * FROM inventory_transactions 
                        WHERE item_id = ? 
                        ORDER BY created_at DESC 
                        LIMIT 50";
        $trans_stmt = $conn->prepare($trans_query);
        $trans_stmt->bind_param("i", $item_id);
        $trans_stmt->execute();
        $trans_result = $trans_stmt->get_result();
        $transactions = $trans_result->fetch_all(MYSQLI_ASSOC);
        
        echo json_encode(['success' => true, 'transactions' => $transactions]);
        exit;
    }
}

// Determine branch filter condition for statistics
$branch_condition = "";
if ($items_branch_column_exists && !$view_all_branches) {
    $branch_condition = "AND items.branch_id = $branch_id";
}

// Determine category filter condition for statistics
$category_condition = "";
if (empty($user_category)) {
    // If no category assigned, show nothing
    $category_condition = "AND 1=0"; // This will return no results
} else {
    $category_condition = "AND items.category = '" . $conn->real_escape_string($user_category) . "'";
}

// Get inventory statistics
$stats = [];

// Total Items
$total_items_query = "SELECT COUNT(*) as total_items FROM items WHERE status = 'active' $branch_condition $category_condition";
$result = $conn->query($total_items_query);
$stats['total_items'] = $result->fetch_assoc()['total_items'] ?? 0;

// Current Stock
$current_stock_query = "SELECT SUM(stock) as current_stock FROM items WHERE status = 'active' $branch_condition $category_condition";
$result = $conn->query($current_stock_query);
$stats['current_stock'] = $result->fetch_assoc()['current_stock'] ?? 0;

// Low Stock Items (stock <= reorder_level and stock > 0)
$low_stock_query = "SELECT COUNT(*) as count FROM items 
                   WHERE stock <= reorder_level AND stock > 0 AND status = 'active' $branch_condition $category_condition";
$result = $conn->query($low_stock_query);
$stats['low_stock'] = $result->fetch_assoc()['count'] ?? 0;

// Out of Stock Items
$out_of_stock_query = "SELECT COUNT(*) as count FROM items 
                      WHERE stock <= 0 AND status = 'active' $branch_condition $category_condition";
$result = $conn->query($out_of_stock_query);
$stats['out_of_stock'] = $result->fetch_assoc()['count'] ?? 0;

// Get inventory items with branch info
$inventory_query = "
    SELECT 
        i.item_id, 
        i.item_code, 
        i.item_name, 
        i.category, 
        i.stock, 
        i.reorder_level, 
        i.status, 
        i.unit_type, 
        i.unit_price,
        i.price_case,
        i.price_inner_pack,
        i.price_box,
        i.price_carton,
        i.description, 
        i.branch_id,
        b.branch_name
    FROM items i
    LEFT JOIN branches b ON i.branch_id = b.branch_id
    WHERE i.status = 'active'";

// Add branch filter if needed
if ($items_branch_column_exists && !$view_all_branches) {
    $inventory_query .= " AND i.branch_id = $branch_id";
}

// Add category filter based on user's assigned category
if (empty($user_category)) {
    // If no category assigned, show nothing
    $inventory_query .= " AND 1=0";
} else {
    $inventory_query .= " AND i.category = '" . $conn->real_escape_string($user_category) . "'";
}

$inventory_query .= " ORDER BY i.item_name";

$items_result = $conn->query($inventory_query);
if (!$items_result) {
    die("Query failed: " . $conn->error);
}
$items = $items_result->fetch_all(MYSQLI_ASSOC);

// Get unique categories for filter - only show user's category
$categories = [];
if (!empty($user_category)) {
    $categories[] = ['category' => $user_category];
}

// Get price columns info for UI
$price_columns_available = $price_case_exists || $price_inner_exists || $price_box_exists || $price_carton_exists;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Current Inventory - <?php echo !empty($user_category) ? htmlspecialchars($user_category) : 'Warehouse'; ?></title>
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
    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<style>
    /* Category indicator */
    .category-indicator {
        display: inline-block;
        padding: 4px 12px;
        background-color: #e7f5ff;
        color: #0d6efd;
        border-radius: 20px;
        font-size: 13px;
        font-weight: 500;
        margin-left: 10px;
    }
    
    .category-indicator i {
        margin-right: 5px;
    }

    /* Mobile Profile Modal Styles */
    .user-avatar-large {
        width: 100px;
        height: 100px;
        border-radius: 50%;
        background: linear-gradient(135deg, var(--dark-green), var(--primary-green));
        color: white;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 2.5rem;
        font-weight: bold;
        margin: 0 auto;
        border: 4px solid var(--light-green);
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
    }

    #profileModal .modal-content {
        border: none;
        border-radius: 20px;
        overflow: hidden;
    }

    #profileModal .modal-header {
        background: linear-gradient(135deg, var(--dark-green), var(--primary-green));
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
        background: var(--light-green);
        color: var(--dark-green);
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

    /* User category in sidebar */
    .user-category-sidebar {
        font-size: 11px;
        color: #0d6efd;
        display: block;
        margin-top: 2px;
        font-weight: 500;
    }

    /* Category info alert */
    .category-info-alert {
        margin-bottom: 1rem;
    }

    /* Search icon inside field */
    .search-wrapper {
        position: relative;
        width: 100%;
    }

    .search-icon {
        position: absolute;
        left: 12px;
        top: 50%;
        transform: translateY(-50%);
        color: #6c757d;
        z-index: 10;
        font-size: 1rem;
        pointer-events: none; /* Allows clicking through to the input */
    }

    .search-input {
        padding-left: 35px !important; /* Make room for the icon */
        width: 100%;
    }
</style>
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
                        <a class="nav-link active" href="currentinventory.php">
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
                    <i class="bi bi-list" id="toggleIcon"></i>
                </button>
                <div class="page-title">
                    <h2>
                        Current Inventory
                    </h2>
                    <p>Manage and view <?php echo !empty($user_category) ? strtolower($user_category) : ''; ?> inventory</p>
                </div>
            </div>

           <!-- Inventory Stats -->
<div class="row stat-card-row g-1 g-sm-2">
    <!-- Card 1 - Total Items -->
    <div class="col">
        <div class="stat-card inventory">
            <i class="bi bi-boxes"></i>
            <div class="stat-content">
                <div class="stat-value"><?php echo number_format($stats['total_items']); ?></div>
                <div class="stat-label">Total <?php echo !empty($user_category) ? htmlspecialchars($user_category) : ''; ?> Items</div>
            </div>
        </div>
    </div>

    <!-- Card 2 - Current Stock -->
    <div class="col">
        <div class="stat-card stock">
            <i class="bi bi-box-seam"></i>
            <div class="stat-content">
                <div class="stat-value"><?php echo number_format($stats['current_stock']); ?></div>
                <div class="stat-label">Current <?php echo !empty($user_category) ? htmlspecialchars($user_category) : ''; ?> Stock</div>
            </div>
        </div>
    </div>

    <!-- Card 3 - Low Stock Items -->
    <div class="col">
        <div class="stat-card pending">
            <i class="bi bi-exclamation-triangle"></i>
            <div class="stat-content">
                <div class="stat-value"><?php echo $stats['low_stock']; ?></div>
                <div class="stat-label">Low Stock Items</div>
            </div>
        </div>
    </div>
</div>
<!-- FILTER SECTION - ALL ITEMS -->
<div class="form-card mb-4">
    <div class="filter-header">
        <h5>
            <i class="bi bi-funnel"></i> Filter Items

        </h5>
        <button class="filter-toggle-btn" type="button" id="itemsFilterToggle" aria-expanded="false">
            <i class="bi bi-chevron-down" id="itemsFilterIcon"></i>
        </button>
    </div>
    
    <div class="filter-content collapsed" id="itemsFilterContent">
        <div class="row g-3">
            <!-- Search Field -->
            <div class="col-12 col-md-8">
                <label class="form-label">
                    <i class="bi bi-search"></i> Search
                </label>
                <div class="search-wrapper">
                    <input type="text" class="form-control search-input" id="searchInput" placeholder="Search by item name or code...">
                </div>
            </div>
            
            <!-- Category Field - DISABLED INPUT TEXT -->
            <div class="col-12 col-md-4">
                <label class="form-label">
                    <i class="bi bi-tag"></i> Category
                </label>
                <?php if (!empty($user_category)): ?>
                    <input type="text" class="form-control" id="categoryFilter" value="<?php echo htmlspecialchars($user_category); ?>" disabled readonly>
                    <input type="hidden" id="userCategory" value="<?php echo htmlspecialchars($user_category); ?>">
                <?php else: ?>
                    <input type="text" class="form-control" value="No Category Assigned" disabled readonly>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

            <!-- Inventory Table -->
            <div class="card">
                <div class="table-container">
                    <table class="table custom-table compact-table">
                        <thead class="table-light">
                            <tr>
                                <th>Item Code</th>
                                <th>Item Name</th>
                                <th>Category</th>
                                <?php if ($view_all_branches && $items_branch_column_exists): ?>
                                    <th>Branch</th>
                                <?php endif; ?>
                                <th>Total Stock</th>
                                <th>Unit Type</th>
                                <th>Reorder Level</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            if (count($items) > 0) {
                                foreach($items as $row) {
                                    $status_badge = 'bg-success';
                                    $status_text = 'In Stock';
                                    
                                    if ($row['stock'] <= 0) {
                                        $status_badge = 'bg-danger';
                                        $status_text = 'Out of Stock';
                                    } elseif ($row['stock'] <= $row['reorder_level']) {
                                        $status_badge = 'bg-warning';
                                        $status_text = 'Low Stock';
                                    }
                                    ?>
                                    <tr>
                                        <td><span class="badge bg-light text-dark"><?php echo htmlspecialchars($row['item_code']); ?></span></td>
                                        <td><?php echo htmlspecialchars($row['item_name']); ?></td>
                                        <td>
                                            <?php if (!empty($row['category'])): ?>
                                                <span class="badge bg-info"><?php echo htmlspecialchars($row['category']); ?></span>
                                            <?php else: ?>
                                                N/A
                                            <?php endif; ?>
                                        </td>
                                        <?php if ($view_all_branches && $items_branch_column_exists): ?>
                                            <td>
                                                <span class="badge bg-secondary">
                                                    <?php echo htmlspecialchars($row['branch_name'] ?? 'Branch ' . $row['branch_id']); ?>
                                                </span>
                                            </td>
                                        <?php endif; ?>
                                        <td>
                                            <span class="<?php echo $row['stock'] <= $row['reorder_level'] ? 'text-danger fw-bold' : ''; ?>">
                                                <?php echo number_format($row['stock']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo ucfirst(str_replace('-', ' ', $row['unit_type'])); ?></td>
                                        <td><?php echo number_format($row['reorder_level']); ?></td>
                                        <td><span class="badge <?php echo $status_badge; ?>"><?php echo $status_text; ?></span></td>
                                        <td>
                                            <div class="action-buttons">
                                                <button class="btn-action btn-view" onclick="viewItem(<?php echo $row['item_id']; ?>)" title="View" <?php echo empty($user_category) ? 'disabled' : ''; ?>>
                                                    <i class="bi bi-eye"></i>
                                                </button>
                                                <button class="btn-action btn-edit" onclick="editItem(<?php echo $row['item_id']; ?>)" title="Edit" <?php echo empty($user_category) ? 'disabled' : ''; ?>>
                                                    <i class="bi bi-pencil"></i>
                                                </button>
                                                <?php if ($inventory_transactions_exists): ?>
                                                <button class="btn-action btn-history" onclick="viewTransactions(<?php echo $row['item_id']; ?>)" title="Transactions" <?php echo empty($user_category) ? 'disabled' : ''; ?>>
                                                    <i class="bi bi-clock-history"></i>
                                                </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php
                                }
                            } else {
                                $colspan = $view_all_branches && $items_branch_column_exists ? 9 : 8;
                                if (!empty($user_category)) {
                                    $message = 'No ' . htmlspecialchars($user_category) . ' items found';
                                } else {
                                    $message = 'No items found - you need a category assigned';
                                }
                                echo '<tr><td colspan="' . $colspan . '" class="text-center py-4"><i class="bi bi-inbox fs-1 d-block text-muted mb-2"></i><p class="text-muted mb-0">' . $message . '</p>';
                                echo '</td></tr>';
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Mobile Bottom Navigation -->
    <div class="mobile-nav" id="mobileNav">
        <ul class="nav">
            <li class="nav-item">
                <a class="nav-link" href="warehouse.php">
                    <i class="bi bi-speedometer2"></i>
                    <span>Dashboard</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link active" href="currentinventory.php">
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
                    <!-- User Avatar -->
                    <div class="user-avatar-large mb-3">
                        <?php echo substr($user_name, 0, 2); ?>
                    </div>
                    
                    <!-- User Name -->
                    <h4 class="mb-1"><?php echo htmlspecialchars($user_name); ?></h4>
                    
                    <!-- User Role -->
                    <p class="text-muted mb-3">
                        <span class="badge bg-success"><?php echo ucfirst($user_role); ?></span>
                        <?php if (!empty($user_category)): ?>
                            <span class="badge bg-info"><?php echo htmlspecialchars($user_category); ?></span>
                        <?php endif; ?>
                    </p>
                    
                    <!-- Branch Info (if applicable) -->
                    <?php if (!$view_all_branches && $branch_id > 0): ?>
                    <div class="branch-info mb-3">
                        <i class="bi bi-building me-1"></i>
                        <span><?php echo htmlspecialchars($branch_name); ?></span>
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

    <!-- REMOVED: Add Inventory Modal -->

    <!-- View Item Details Modal -->
    <div class="modal fade" id="viewItemModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Item Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="itemDetailsContent">
                    <!-- Content will be loaded by JavaScript -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Inventory Modal -->
    <div class="modal fade" id="editInventoryModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit <?php echo !empty($user_category) ? htmlspecialchars($user_category) : ''; ?> Item</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="editInventoryFormContent">
                    <!-- Content will be loaded by JavaScript -->
                </div>
            </div>
        </div>
    </div>

    <!-- Transaction History Modal -->
    <?php if ($inventory_transactions_exists): ?>
    <div class="modal fade" id="transactionModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Transaction History</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="transactionContent">
                    <!-- Content will be loaded by JavaScript -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let userCategory = <?php echo !empty($user_category) ? json_encode($user_category) : 'null'; ?>;

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

        // Original logout function for sidebar
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

        // ================= INVENTORY FUNCTIONS =================
        // REMOVED: showAddItemModal function
        // REMOVED: submitAddForm function

        function viewItem(itemId) {
            if (!userCategory) {
                Swal.fire('Error', 'You do not have a category assigned. Please contact administrator.', 'error');
                return;
            }
            
            Swal.fire({
                title: 'Loading...',
                text: 'Please wait',
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });
            
            const formData = new FormData();
            formData.append('get_item', '1');
            formData.append('item_id', itemId);
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                Swal.close();
                
                if (data.success) {
                    const item = data.item;
                    
                    const statusClass = item.status === 'active' ? 'success' : 
                                       item.status === 'inactive' ? 'secondary' : 'danger';
                    
                    // Build price info if available
                    let priceHtml = '';
                    if (item.price_case || item.price_inner_pack || item.price_box || item.price_carton) {
                        priceHtml = `
                            <div class="row mt-3">
                                <div class="col-12">
                                    <h6 class="fw-bold">Multi-Unit Pricing</h6>
                                    <table class="table table-sm table-bordered">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Unit Type</th>
                                                <th>Price</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            ${item.price_case ? `<tr><td>Case</td><td>₱${Number(item.price_case).toFixed(2)}</td></tr>` : ''}
                                            ${item.price_inner_pack ? `<tr><td>Inner Pack</td><td>₱${Number(item.price_inner_pack).toFixed(2)}</td></tr>` : ''}
                                            ${item.price_box ? `<tr><td>Box</td><td>₱${Number(item.price_box).toFixed(2)}</td></tr>` : ''}
                                            ${item.price_carton ? `<tr><td>Carton</td><td>₱${Number(item.price_carton).toFixed(2)}</td></tr>` : ''}
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        `;
                    }
                    
                    const content = `
                        <div class="row">
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
                                        <td><span class="badge bg-info">${item.category || 'N/A'}</span></td>
                                    </tr>
                                    <tr>
                                        <th>Description:</th>
                                        <td>${item.description || 'No description'}</td>
                                    </tr>
                                    <tr>
                                        <th>Status:</th>
                                        <td><span class="badge bg-${statusClass}">${item.status}</span></td>
                                    </tr>
                                    <?php if ($items_branch_column_exists): ?>
                                    <tr>
                                        <th>Branch:</th>
                                        <td><span class="badge bg-secondary">Branch ${item.branch_id || 'N/A'}</span></td>
                                    </tr>
                                    <?php endif; ?>
                                </table>
                            </div>
                            <div class="col-md-6">
                                <table class="table table-sm table-borderless">
                                    <tr>
                                        <th width="40%">Stock:</th>
                                        <td>${Number(item.stock).toLocaleString()}</td>
                                    </tr>
                                    <tr>
                                        <th>Unit Type:</th>
                                        <td>${item.unit_type}</td>
                                    </tr>
                                    <tr>
                                        <th>Reorder Level:</th>
                                        <td>${item.reorder_level}</td>
                                    </tr>
                                    <tr>
                                        <th>Created:</th>
                                        <td>${new Date(item.created_at).toLocaleDateString()}</td>
                                    </tr>
                                    <tr>
                                        <th>Last Updated:</th>
                                        <td>${new Date(item.updated_at).toLocaleDateString()}</td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                        ${priceHtml}
                    `;
                    
                    document.getElementById('itemDetailsContent').innerHTML = content;
                    new bootstrap.Modal(document.getElementById('viewItemModal')).show();
                } else {
                    Swal.fire('Error', data.message || 'Failed to load item details', 'error');
                }
            })
            .catch(error => {
                Swal.close();
                Swal.fire('Error', 'An error occurred', 'error');
                console.error('Error:', error);
            });
        }

        function viewTransactions(itemId) {
            if (!userCategory) {
                Swal.fire('Error', 'You do not have a category assigned. Please contact administrator.', 'error');
                return;
            }
            
            <?php if (!$inventory_transactions_exists): ?>
            Swal.fire('Info', 'Transaction history is not available', 'info');
            return;
            <?php endif; ?>
            
            Swal.fire({
                title: 'Loading...',
                text: 'Please wait',
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });
            
            const formData = new FormData();
            formData.append('get_transactions', '1');
            formData.append('item_id', itemId);
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                Swal.close();
                
                if (data.success) {
                    let transactionsHtml = '';
                    
                    if (data.transactions && data.transactions.length > 0) {
                        transactionsHtml = `
                            <div class="table-responsive">
                                <table class="table table-sm table-bordered">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Date/Time</th>
                                            <th>Type</th>
                                            <th>Quantity</th>
                                            <th>Reference</th>
                                            <th>Reference ID</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                        `;
                        
                        data.transactions.forEach(trans => {
                            const transTypeClass = trans.transaction_type === 'in' ? 'text-success' : 'text-danger';
                            const transTypeIcon = trans.transaction_type === 'in' ? '↓' : '↑';
                            
                            transactionsHtml += `
                                <tr>
                                    <td>${new Date(trans.created_at).toLocaleString()}</td>
                                    <td class="${transTypeClass}">${transTypeIcon} ${trans.transaction_type.toUpperCase()}</td>
                                    <td class="${transTypeClass}">${trans.quantity_changed}</td>
                                    <td>${trans.reference_type || 'N/A'}</td>
                                    <td>${trans.reference_id || 'N/A'}</td>
                                </tr>
                            `;
                        });
                        
                        transactionsHtml += `
                                    </tbody>
                                </table>
                            </div>
                        `;
                    } else {
                        transactionsHtml = '<div class="alert alert-info">No transaction history found for this item.</div>';
                    }
                    
                    document.getElementById('transactionContent').innerHTML = transactionsHtml;
                    new bootstrap.Modal(document.getElementById('transactionModal')).show();
                } else {
                    Swal.fire('Error', data.message || 'Failed to load transactions', 'error');
                }
            })
            .catch(error => {
                Swal.close();
                Swal.fire('Error', 'An error occurred', 'error');
                console.error('Error:', error);
            });
        }

        function editItem(itemId) {
            if (!userCategory) {
                Swal.fire('Error', 'You do not have a category assigned. Please contact administrator.', 'error');
                return;
            }
            
            Swal.fire({
                title: 'Loading...',
                text: 'Please wait',
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });
            
            const formData = new FormData();
            formData.append('get_item', '1');
            formData.append('item_id', itemId);
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                Swal.close();
                
                if (data.success) {
                    const item = data.item;
                    
                    // Check if user can edit this item based on category
                    if (item.category !== userCategory) {
                        Swal.fire('Access Denied', 'You can only edit items in your assigned category: ' + userCategory, 'error');
                        return;
                    }
                    
                    const content = `
                        <form id="editForm">
                            <input type="hidden" name="edit_item" value="1">
                            <input type="hidden" name="item_id" value="${item.item_id}">
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Item Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" name="item_name" value="${item.item_name.replace(/"/g, '&quot;')}" required>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Item Code</label>
                                    <input type="text" class="form-control" value="${item.item_code}" readonly disabled>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Category <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" value="${item.category}" readonly disabled>
                                    <input type="hidden" name="category" value="${item.category}">
                                    <small class="text-muted">Category cannot be changed</small>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Current Stock <span class="text-danger">*</span></label>
                                    <input type="number" class="form-control" name="stock" value="${item.stock}" min="0" required>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Reorder Level <span class="text-danger">*</span></label>
                                    <input type="number" class="form-control" name="reorder_level" value="${item.reorder_level}" min="0" required>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Unit Type <span class="text-danger">*</span></label>
                                    <select class="form-select" name="unit_type" required>
                                        <option value="piece" ${item.unit_type === 'piece' ? 'selected' : ''}>Piece</option>
                                        <option value="case" ${item.unit_type === 'case' ? 'selected' : ''}>Case</option>
                                        <option value="inner-pack" ${item.unit_type === 'inner-pack' ? 'selected' : ''}>Inner Pack</option>
                                        <option value="box" ${item.unit_type === 'box' ? 'selected' : ''}>Box</option>
                                        <option value="carton" ${item.unit_type === 'carton' ? 'selected' : ''}>Carton</option>
                                    </select>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Description</label>
                                <textarea class="form-control" name="description" rows="3">${item.description ? item.description.replace(/"/g, '&quot;') : ''}</textarea>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Status</label>
                                <select class="form-select" name="status">
                                    <option value="active" ${item.status === 'active' ? 'selected' : ''}>Active</option>
                                    <option value="inactive" ${item.status === 'inactive' ? 'selected' : ''}>Inactive</option>
                                    <option value="discontinued" ${item.status === 'discontinued' ? 'selected' : ''}>Discontinued</option>
                                </select>
                            </div>
                        </form>
                    `;
                    
                    document.getElementById('editInventoryFormContent').innerHTML = content;
                    
                    const modal = new bootstrap.Modal(document.getElementById('editInventoryModal'));
                    modal.show();
                    
                    const modalFooter = document.querySelector('#editInventoryModal .modal-footer');
                    if (modalFooter) {
                        modalFooter.innerHTML = `
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="button" class="btn btn-primary" onclick="submitEditForm()">Update Item</button>
                        `;
                    } else {
                        const footer = document.createElement('div');
                        footer.className = 'modal-footer';
                        footer.innerHTML = `
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="button" class="btn btn-primary" onclick="submitEditForm()">Update Item</button>
                        `;
                        document.querySelector('#editInventoryModal .modal-content').appendChild(footer);
                    }
                } else {
                    Swal.fire('Error', data.message || 'Failed to load item details', 'error');
                }
            })
            .catch(error => {
                Swal.close();
                Swal.fire('Error', 'An error occurred', 'error');
                console.error('Error:', error);
            });
        }

        function submitEditForm() {
            const form = document.getElementById('editForm');
            const formData = new FormData(form);
            
            Swal.fire({
                title: 'Updating...',
                text: 'Please wait',
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });
            
            fetch(window.location.href, {
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
                        timer: 1500,
                        showConfirmButton: false
                    }).then(() => {
                        location.reload();
                    });
                } else {
                    Swal.fire('Error', data.message, 'error');
                }
            })
            .catch(error => {
                Swal.close();
                Swal.fire('Error', 'An error occurred', 'error');
                console.error('Error:', error);
            });
        }

        // ================= INITIALIZATION =================
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

        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            console.log("Inventory Items page loaded! User Category from Database: " + (userCategory || 'Not Assigned'));
            
            if (!userCategory) {
                console.error("WARNING: User has no category assigned in users table!");
            }
            
            initializeSidebar();
            initMobileNav();
            
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
            
            document.querySelectorAll('.sidebar .nav-link').forEach(link => {
                link.addEventListener('click', function() {
                    if (window.innerWidth <= 992) {
                        closeMobileSidebar();
                    }
                });
            });
            
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

            window.addEventListener('resize', function() {
                handleSidebarResize();
                initMobileNav();
            });

            // Search functionality
            const searchInput = document.getElementById('searchInput');
            if (searchInput) {
                searchInput.addEventListener('keyup', function() {
                    const filter = this.value.toLowerCase();
                    const rows = document.querySelectorAll('tbody tr');
                    
                    rows.forEach(row => {
                        const text = row.textContent.toLowerCase();
                        row.style.display = text.includes(filter) ? '' : 'none';
                    });
                });
            }

            // Category filter - disabled for users with category
            const categoryFilter = document.getElementById('categoryFilter');
            if (categoryFilter && !userCategory) {
                categoryFilter.addEventListener('change', function() {
                    const filter = this.value.toLowerCase();
                    const rows = document.querySelectorAll('tbody tr');
                    
                    rows.forEach(row => {
                        if (row.cells.length > 2) {
                            const category = row.cells[2]?.textContent.toLowerCase() || '';
                            row.style.display = (filter === '' || category.includes(filter)) ? '' : 'none';
                        }
                    });
                });
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
            }
            else if (e.ctrlKey && e.key === 'f') {
                e.preventDefault();
                const searchInput = document.getElementById('searchInput');
                if (searchInput) {
                    searchInput.focus();
                }
            }
        });

      // ================= FILTER TOGGLE FUNCTIONS =================
// Toggle filter section visibility with localStorage
function toggleFilter(filterType) {
    const content = document.getElementById(filterType + 'FilterContent');
    const icon = document.getElementById(filterType + 'FilterIcon');
    const toggleBtn = document.getElementById(filterType + 'FilterToggle');
    
    if (content && icon && toggleBtn) {
        const isExpanded = toggleBtn.getAttribute('aria-expanded') === 'true';
        
        if (isExpanded) {
            // Collapse
            content.classList.add('collapsed');
            toggleBtn.setAttribute('aria-expanded', 'false');
            icon.style.transform = 'rotate(0deg)';
            localStorage.setItem(filterType + 'FilterHidden', 'true');
        } else {
            // Expand
            content.classList.remove('collapsed');
            toggleBtn.setAttribute('aria-expanded', 'true');
            icon.style.transform = 'rotate(180deg)';
            localStorage.setItem(filterType + 'FilterHidden', 'false');
        }
    }
}

// Initialize picklist filter state - DEFAULT CLOSED
function initPicklistFilterState() {
    const content = document.getElementById('picklistFilterContent');
    const icon = document.getElementById('picklistFilterIcon');
    const toggleBtn = document.getElementById('picklistFilterToggle');
    
    if (content && icon && toggleBtn) {
        // DEFAULT: CLOSED sa simula
        content.classList.add('collapsed');
        toggleBtn.setAttribute('aria-expanded', 'false');
        icon.style.transform = 'rotate(0deg)';
        
        // Save sa localStorage na closed para consistent
        localStorage.setItem('picklistFilterHidden', 'true');
    }
}

// Initialize items filter state - DEFAULT CLOSED
function initItemsFilterState() {
    const content = document.getElementById('itemsFilterContent');
    const icon = document.getElementById('itemsFilterIcon');
    const toggleBtn = document.getElementById('itemsFilterToggle');
    
    if (content && icon && toggleBtn) {
        // DEFAULT: CLOSED sa simula
        content.classList.add('collapsed');
        toggleBtn.setAttribute('aria-expanded', 'false');
        icon.style.transform = 'rotate(0deg)';
        
        // Save sa localStorage na closed para consistent
        localStorage.setItem('itemsFilterHidden', 'true');
    }
}

// Generic function for any filter - DEFAULT CLOSED
function initFilterState(filterType) {
    const content = document.getElementById(filterType + 'FilterContent');
    const icon = document.getElementById(filterType + 'FilterIcon');
    const toggleBtn = document.getElementById(filterType + 'FilterToggle');
    
    if (content && icon && toggleBtn) {
        // DEFAULT: CLOSED sa simula
        content.classList.add('collapsed');
        toggleBtn.setAttribute('aria-expanded', 'false');
        icon.style.transform = 'rotate(0deg)';
        
        // Save sa localStorage na closed para consistent
        localStorage.setItem(filterType + 'FilterHidden', 'true');
    }
}

// Add event listeners
document.addEventListener('DOMContentLoaded', function() {
    // Initialize picklist filter - DEFAULT CLOSED
    initPicklistFilterState();
    
    // Initialize items filter - kung meron
    if (document.getElementById('itemsFilterContent')) {
        initItemsFilterState();
    }
    
    // Toggle button for picklist
    document.getElementById('picklistFilterToggle')?.addEventListener('click', function() {
        toggleFilter('picklist');
    });
    
    // Toggle button for items - kung meron
    document.getElementById('itemsFilterToggle')?.addEventListener('click', function() {
        toggleFilter('items');
    });
    
    // Enter key on search (picklist)
    document.getElementById('searchInput')?.addEventListener('keypress', function(e) {
        if (e.key === 'Enter') applyFilters();
    });
});

// Apply filters function (sample - i-customize per page)
function applyFilters() {
    // Get filter values
    const search = document.getElementById('searchInput')?.value || '';
    const status = document.getElementById('statusFilter')?.value || '';
    const driver = document.getElementById('driverFilter')?.value || '';
    
    // Build URL parameters
    const params = new URLSearchParams();
    if (search) params.append('search', search);
    if (status) params.append('status', status);
    if (driver) params.append('driver', driver);
    
    // Redirect with filters
    window.location.href = window.location.pathname + '?' + params.toString();
}

// Clear filters function
function clearFilters() {
    document.getElementById('searchInput') && (document.getElementById('searchInput').value = '');
    document.getElementById('statusFilter') && (document.getElementById('statusFilter').value = '');
    document.getElementById('driverFilter') && (document.getElementById('driverFilter').value = '');
    
    // Apply the cleared filters
    applyFilters();
}
    </script>
</body>
</html>