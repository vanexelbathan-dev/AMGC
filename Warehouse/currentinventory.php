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

// Function to generate unique item code
function generateUniqueItemCode($conn) {
    // Try ITEM format first (ITEM001, ITEM002, etc.)
    $prefix = 'ITEM';
    $number = 1;
    $max_attempts = 1000; // Prevent infinite loop
    
    for ($attempt = 0; $attempt < $max_attempts; $attempt++) {
        $code = $prefix . str_pad($number, 3, '0', STR_PAD_LEFT);
        
        // Check if code exists
        $check = $conn->prepare("SELECT item_id FROM items WHERE item_code = ?");
        $check->bind_param("s", $code);
        $check->execute();
        $result = $check->get_result();
        
        if ($result->num_rows === 0) {
            return $code; // Found unique code
        }
        $number++;
    }
    
    // If ITEM format is exhausted, use timestamp-based code
    return 'ITM' . date('YmdHis');
}

// Get next item code for display
$next_item_code = generateUniqueItemCode($conn);

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $response = ['success' => false, 'message' => ''];
    
    // Add item
    if (isset($_POST['add_item'])) {
        try {
            $conn->begin_transaction();
            
            // Generate unique item code (don't rely on user input)
            $item_code = generateUniqueItemCode($conn);
            
            $item_name = trim($_POST['item_name']);
            $description = !empty($_POST['description']) ? trim($_POST['description']) : null;
            $category = !empty($_POST['category']) ? trim($_POST['category']) : null;
            $stock = (int)$_POST['stock'];
            $unit_type = $_POST['unit_type'] ?? 'piece';
            $unit_price = 0.00; // Default for warehouse (prices set by sales/admin)
            $reorder_level = (int)$_POST['reorder_level'];
            $status = 'active';
            
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
            
            // Insert with branch_id if column exists and price columns
            if ($items_branch_column_exists) {
                if ($price_case_exists) {
                    $stmt = $conn->prepare("INSERT INTO items (item_code, item_name, description, category, stock, unit_type, unit_price, price_case, price_inner_pack, price_box, price_carton, reorder_level, status, branch_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
                    $stmt->bind_param("ssssisdddddisi", $item_code, $item_name, $description, $category, $stock, $unit_type, $unit_price, $unit_price * 12, $unit_price * 6, $unit_price * 24, $unit_price * 48, $reorder_level, $status, $branch_id);
                } else {
                    $stmt = $conn->prepare("INSERT INTO items (item_code, item_name, description, category, stock, unit_type, unit_price, reorder_level, status, branch_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
                    $stmt->bind_param("ssssisdisi", $item_code, $item_name, $description, $category, $stock, $unit_type, $unit_price, $reorder_level, $status, $branch_id);
                }
            } else {
                if ($price_case_exists) {
                    $stmt = $conn->prepare("INSERT INTO items (item_code, item_name, description, category, stock, unit_type, unit_price, price_case, price_inner_pack, price_box, price_carton, reorder_level, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
                    $stmt->bind_param("ssssisdddddis", $item_code, $item_name, $description, $category, $stock, $unit_type, $unit_price, $unit_price * 12, $unit_price * 6, $unit_price * 24, $unit_price * 48, $reorder_level, $status);
                } else {
                    $stmt = $conn->prepare("INSERT INTO items (item_code, item_name, description, category, stock, unit_type, unit_price, reorder_level, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
                    $stmt->bind_param("ssssisdis", $item_code, $item_name, $description, $category, $stock, $unit_type, $unit_price, $reorder_level, $status);
                }
            }
            
            if (!$stmt->execute()) {
                throw new Exception('Failed to add item: ' . $stmt->error);
            }
            
            $item_id = $conn->insert_id;
            
            // Record inventory transaction if adding initial stock and table exists
            if ($stock > 0 && $inventory_transactions_exists) {
                $trans_query = "INSERT INTO inventory_transactions 
                               (branch_id, item_id, transaction_type, quantity_changed, reference_type, reference_id, created_by, created_at) 
                               VALUES (?, ?, 'in', ?, 'initial_stock', ?, ?, NOW())";
                $trans_stmt = $conn->prepare($trans_query);
                $trans_stmt->bind_param("iiiii", $branch_id, $item_id, $stock, $item_id, $user_id);
                $trans_stmt->execute();
            }
            
            $conn->commit();
            
            $response['success'] = true;
            $response['message'] = 'Item added successfully';
            $response['item_code'] = $item_code;
            
        } catch (Exception $e) {
            $conn->rollback();
            $response['message'] = $e->getMessage();
        }
        
        // Return JSON for AJAX
        header('Content-Type: application/json');
        echo json_encode($response);
        exit;
    }
    
    // Edit item
    if (isset($_POST['edit_item'])) {
        try {
            $conn->begin_transaction();
            
            $item_id = (int)$_POST['item_id'];
            $item_name = trim($_POST['item_name']);
            $description = !empty($_POST['description']) ? trim($_POST['description']) : null;
            $category = !empty($_POST['category']) ? trim($_POST['category']) : null;
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
            
            // Check if item exists
            $check = $conn->prepare("SELECT item_id FROM items WHERE item_id = ?");
            $check->bind_param("i", $item_id);
            $check->execute();
            if ($check->get_result()->num_rows === 0) {
                throw new Exception('Item not found');
            }
            
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

// Get inventory statistics
$stats = [];

// Total Items
$total_items_query = "SELECT COUNT(*) as total_items FROM items WHERE status = 'active' $branch_condition";
$result = $conn->query($total_items_query);
$stats['total_items'] = $result->fetch_assoc()['total_items'] ?? 0;

// Current Stock
$current_stock_query = "SELECT SUM(stock) as current_stock FROM items WHERE status = 'active' $branch_condition";
$result = $conn->query($current_stock_query);
$stats['current_stock'] = $result->fetch_assoc()['current_stock'] ?? 0;

// Low Stock Items (stock <= reorder_level and stock > 0)
$low_stock_query = "SELECT COUNT(*) as count FROM items 
                   WHERE stock <= reorder_level AND stock > 0 AND status = 'active' $branch_condition";
$result = $conn->query($low_stock_query);
$stats['low_stock'] = $result->fetch_assoc()['count'] ?? 0;

// Out of Stock Items
$out_of_stock_query = "SELECT COUNT(*) as count FROM items 
                      WHERE stock <= 0 AND status = 'active' $branch_condition";
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

$inventory_query .= " ORDER BY i.item_name";

$items_result = $conn->query($inventory_query);
if (!$items_result) {
    die("Query failed: " . $conn->error);
}
$items = $items_result->fetch_all(MYSQLI_ASSOC);

// Get unique categories for filter
$categories_query = "
    SELECT DISTINCT category 
    FROM items 
    WHERE category IS NOT NULL AND category != '' AND status = 'active'";
    
if ($items_branch_column_exists && !$view_all_branches) {
    $categories_query .= " AND branch_id = $branch_id";
}
$categories_query .= " ORDER BY category";

$categories_result = $conn->query($categories_query);
$categories = $categories_result->fetch_all(MYSQLI_ASSOC);

// Get price columns info for UI
$price_columns_available = $price_case_exists || $price_inner_exists || $price_box_exists || $price_carton_exists;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Current Inventory - Warehouse</title>
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
                    <h2>Current Inventory</h2>
                    <p>Manage and view warehouse inventory</p>
                </div>
            </div>

            <!-- Branch Info Alert -->
            <?php if (!$items_branch_column_exists): ?>
                <div class="alert alert-info alert-dismissible fade show" role="alert">
                    <i class="bi bi-info-circle"></i> 
                    <strong>Branch filtering for items not yet set up.</strong> Items will be visible to all branches.
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Inventory Stats -->
            <div class="row g-3 mb-4">
                <!-- Total Items -->
                <div class="col-md-4 mb-3">
                    <div class="stat-card inventory">
                        <div class="stat-icon">
                            <i class="bi bi-boxes"></i>
                        </div>
                        <div>
                            <div class="stat-value"><?php echo number_format($stats['total_items']); ?></div>
                            <div class="stat-label">Total Items</div>
                        </div>
                    </div>
                </div>

                <!-- Current Stock -->
                <div class="col-md-4 mb-3">
                    <div class="stat-card stock">
                        <div class="stat-icon">
                            <i class="bi bi-box-seam"></i>
                        </div>
                        <div>
                            <div class="stat-value"><?php echo number_format($stats['current_stock']); ?></div>
                            <div class="stat-label">Current Stock</div>
                        </div>
                    </div>
                </div>

                <!-- Low Stock Items -->
                <div class="col-md-4 mb-3">
                    <div class="stat-card pending">
                        <div class="stat-icon">
                            <i class="bi bi-exclamation-triangle"></i>
                        </div>
                        <div>
                            <div class="stat-value"><?php echo $stats['low_stock']; ?></div>
                            <div class="stat-label">Low Stock Items</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Search and Filter with Add Button -->
            <div class="card mb-4">
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-5 col-12">
                            <div class="input-group">
                                <span class="input-group-text">
                                    <i class="bi bi-search"></i>
                                </span>
                                <input type="text" class="form-control" id="searchInput" placeholder="Search by item name or code...">
                            </div>
                        </div>
                        <div class="col-md-4 col-12">
                            <select class="form-select" id="categoryFilter">
                                <option value="">All Categories</option>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?php echo htmlspecialchars($cat['category']); ?>">
                                        <?php echo htmlspecialchars($cat['category']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3 col-12">
                            <button class="btn btn-outline-success w-100" onclick="showAddItemModal()">
                                <i class="bi bi-plus-lg"></i> Add Item
                            </button>
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
                                        <td><?php echo htmlspecialchars($row['category'] ?? 'N/A'); ?></td>
                                        <?php if ($view_all_branches && $items_branch_column_exists): ?>
                                            <td>
                                                <span class="badge bg-info">
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
                                            <button class="btn btn-sm btn-outline-primary" onclick="viewItem(<?php echo $row['item_id']; ?>)" title="View">
                                                <i class="bi bi-eye"></i>
                                            </button>
                                            <button class="btn btn-sm btn-outline-warning" onclick="editItem(<?php echo $row['item_id']; ?>)" title="Edit">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                            <?php if ($inventory_transactions_exists): ?>
                                            <button class="btn btn-sm btn-outline-info" onclick="viewTransactions(<?php echo $row['item_id']; ?>)" title="Transactions">
                                                <i class="bi bi-clock-history"></i>
                                            </button>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php
                                }
                            } else {
                                $colspan = $view_all_branches && $items_branch_column_exists ? 9 : 8;
                                echo '<tr><td colspan="' . $colspan . '" class="text-center py-4"><i class="bi bi-inbox fs-1 d-block text-muted mb-2"></i><p class="text-muted mb-0">No inventory items found</p><button class="btn btn-sm btn-primary mt-2" onclick="showAddItemModal()"><i class="bi bi-plus-circle"></i> Add Item</button></td></tr>';
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Inventory Modal -->
    <div class="modal fade" id="addInventoryModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Add New Inventory Item</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="addInventoryForm">
                        <input type="hidden" name="add_item" value="1">
                        
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle"></i> 
                            Item code will be auto-generated and guaranteed unique.
                            <?php if ($items_branch_column_exists): ?>
                                <br>This item will be assigned to Branch <?php echo $branch_id; ?>.
                            <?php endif; ?>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Item Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="item_name" id="item_name" required placeholder="Enter item name">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Category <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="category" required placeholder="e.g., Electronics, Furniture">
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Initial Stock <span class="text-danger">*</span></label>
                                <input type="number" class="form-control" name="stock" required placeholder="0" min="0" value="0">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Reorder Level <span class="text-danger">*</span></label>
                                <input type="number" class="form-control" name="reorder_level" required placeholder="0" min="0" value="50">
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Unit Type <span class="text-danger">*</span></label>
                                <select class="form-select" name="unit_type" required>
                                    <option value="piece" selected>Piece</option>
                                    <option value="case">Case</option>
                                    <option value="inner-pack">Inner Pack</option>
                                    <option value="box">Box</option>
                                    <option value="carton">Carton</option>
                                </select>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea class="form-control" name="description" placeholder="Item description (optional)" rows="3"></textarea>
                        </div>
                        
                        <?php if ($price_columns_available): ?>
                        <div class="alert alert-light">
                            <i class="bi bi-tag"></i> 
                            <small>Price columns are available for multi-unit pricing. Please contact sales/admin to set prices.</small>
                        </div>
                        <?php endif; ?>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="submitAddForm()">Add Item</button>
                </div>
            </div>
        </div>
    </div>

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
                    <h5 class="modal-title">Edit Inventory Item</h5>
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

        // Show Add Item Modal
        function showAddItemModal() {
            document.getElementById('addInventoryForm').reset();
            new bootstrap.Modal(document.getElementById('addInventoryModal')).show();
        }

        // Submit add form via AJAX
        function submitAddForm() {
            const form = document.getElementById('addInventoryForm');
            const formData = new FormData(form);
            
            // Validate required fields
            const itemName = document.getElementById('item_name').value.trim();
            if (!itemName) {
                Swal.fire('Warning', 'Item Name is required', 'warning');
                return;
            }
            
            Swal.fire({
                title: 'Adding Item...',
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
                        text: data.message + ' (Code: ' + data.item_code + ')',
                        timer: 2000,
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
                Swal.fire('Error', 'An error occurred while saving the item', 'error');
                console.error('Error:', error);
            });
        }

        // View item details
        function viewItem(itemId) {
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
                                        <td>${item.category || 'N/A'}</td>
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
                                        <td><span class="badge bg-info">Branch ${item.branch_id || 'N/A'}</span></td>
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
                    Swal.fire('Error', 'Failed to load item details', 'error');
                }
            })
            .catch(error => {
                Swal.close();
                Swal.fire('Error', 'An error occurred', 'error');
                console.error('Error:', error);
            });
        }

        // View transaction history
        function viewTransactions(itemId) {
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

        // Edit item
        function editItem(itemId) {
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
                                    <input type="text" class="form-control" name="category" value="${item.category ? item.category.replace(/"/g, '&quot;') : ''}" required>
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
                    Swal.fire('Error', 'Failed to load item details', 'error');
                }
            })
            .catch(error => {
                Swal.close();
                Swal.fire('Error', 'An error occurred', 'error');
                console.error('Error:', error);
            });
        }

        // Submit edit form
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

        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            console.log("Inventory Items page loaded!");
            
            initializeSidebar();
            
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

            window.addEventListener('resize', handleSidebarResize);

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

            // Category filter
            const categoryFilter = document.getElementById('categoryFilter');
            if (categoryFilter) {
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

        // Logout function
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

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey && e.key === 'b' && window.innerWidth > 992) {
                e.preventDefault();
                toggleSidebar();
            }
            else if (e.key === 'Escape' && window.innerWidth <= 992) {
                closeMobileSidebar();
            }
            else if (e.ctrlKey && e.key === 'f') {
                e.preventDefault();
                const searchInput = document.getElementById('searchInput');
                if (searchInput) {
                    searchInput.focus();
                }
            }
            else if (e.ctrlKey && e.key === 'n') {
                e.preventDefault();
                showAddItemModal();
            }
        });
    </script>
</body>
</html>