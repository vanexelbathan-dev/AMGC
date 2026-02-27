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

// Get all items with available stock - filter by branch if not admin AND if branch_id column exists
$items = [];

if ($items_branch_column_exists) {
    // Branch column exists - apply filtering
    if ($view_all_branches) {
        // Admin sees all branches
        $items_query = "SELECT i.item_id, i.item_code, i.item_name, i.description, i.category, 
                       i.stock, i.unit_type, i.unit_price, i.reorder_level, i.status,
                       b.branch_name
                       FROM items i
                       LEFT JOIN branches b ON i.branch_id = b.branch_id
                       WHERE i.status = 'active'
                       ORDER BY i.item_code ASC";
    } else {
        // Regular user sees only their branch
        $items_query = "SELECT i.item_id, i.item_code, i.item_name, i.description, i.category, 
                       i.stock, i.unit_type, i.unit_price, i.reorder_level, i.status,
                       b.branch_name
                       FROM items i
                       LEFT JOIN branches b ON i.branch_id = b.branch_id
                       WHERE i.status = 'active' AND i.branch_id = $branch_id
                       ORDER BY i.item_code ASC";
    }
} else {
    // Branch column doesn't exist - show all items
    $items_query = "SELECT i.item_id, i.item_code, i.item_name, i.description, i.category, 
                   i.stock, i.unit_type, i.unit_price, i.reorder_level, i.status
                   FROM items i
                   WHERE i.status = 'active'
                   ORDER BY i.item_code ASC";
}

$items_result = $conn->query($items_query);
if ($items_result) {
    $items = $items_result->fetch_all(MYSQLI_ASSOC);
}

// Get all customers - filter by branch if not admin AND if branch_id column exists
$customers = [];

if ($branch_column_exists) {
    // Branch column exists - apply filtering
    if ($view_all_branches) {
        // Admin sees all customers
        $customers_query = "SELECT c.customer_id, c.customer_name, c.email, c.phone_number, c.address, c.city,
                           b.branch_name
                           FROM customers c
                           LEFT JOIN branches b ON c.branch_id = b.branch_id
                           WHERE c.status = 'active'
                           ORDER BY c.customer_name ASC";
    } else {
        // Regular user sees only their branch
        $customers_query = "SELECT c.customer_id, c.customer_name, c.email, c.phone_number, c.address, c.city
                           FROM customers c
                           WHERE c.status = 'active' AND c.branch_id = $branch_id
                           ORDER BY c.customer_name ASC";
    }
} else {
    // Branch column doesn't exist - show all customers
    $customers_query = "SELECT customer_id, customer_name, email, phone_number, address, city
                       FROM customers
                       WHERE status = 'active'
                       ORDER BY customer_name ASC";
}

$customers_result = $conn->query($customers_query);
if ($customers_result) {
    $customers = $customers_result->fetch_all(MYSQLI_ASSOC);
}

// Handle order submission via AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_order') {
    header('Content-Type: application/json');
    
    try {
        $conn->begin_transaction();
        
        // Log incoming data for debugging
        error_log("Order submission started");
        error_log("POST data: " . print_r($_POST, true));
        
        $customer_id = isset($_POST['customer_id']) ? (int)$_POST['customer_id'] : 0;
        $customer_name = isset($_POST['customer_name']) ? trim($_POST['customer_name']) : '';
        $email = isset($_POST['email']) ? trim($_POST['email']) : '';
        $phone = isset($_POST['phone']) ? trim($_POST['phone']) : '';
        $address = isset($_POST['address']) ? trim($_POST['address']) : '';
        $items_data = isset($_POST['items']) ? json_decode($_POST['items'], true) : [];
        
        error_log("Customer ID: $customer_id, Customer Name: $customer_name");
        error_log("Items data count: " . count($items_data));
        
        if (empty($items_data)) {
            throw new Exception("No items in cart");
        }
        
        // If customer_id is 0 and customer_name is provided, create new customer
        if ($customer_id === 0 && !empty($customer_name)) {
            error_log("Creating/updating customer: $customer_name");
            
            // Check if customer already exists with this name for this branch
            if ($branch_column_exists && !$view_all_branches) {
                $check_sql = "SELECT customer_id FROM customers WHERE customer_name = ? AND branch_id = ? AND status = 'active'";
                $check_stmt = $conn->prepare($check_sql);
                $check_stmt->bind_param('si', $customer_name, $branch_id);
            } else {
                $check_sql = "SELECT customer_id FROM customers WHERE customer_name = ? AND status = 'active'";
                $check_stmt = $conn->prepare($check_sql);
                $check_stmt->bind_param('s', $customer_name);
            }
            
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            
            if ($check_result->num_rows > 0) {
                $existing_customer = $check_result->fetch_assoc();
                $customer_id = $existing_customer['customer_id'];
                
                // Update existing customer info
                $update_sql = "UPDATE customers SET email = ?, phone_number = ?, address = ? WHERE customer_id = ?";
                $update_stmt = $conn->prepare($update_sql);
                $update_stmt->bind_param('sssi', $email, $phone, $address, $customer_id);
                if (!$update_stmt->execute()) {
                    throw new Exception("Failed to update customer: " . $update_stmt->error);
                }
                error_log("Updated existing customer ID: $customer_id");
            } else {
                // Create new customer - generate customer code
                $customer_code = 'CUST-' . date('Ymd') . '-' . str_pad(rand(1000, 9999), 4, '0', STR_PAD_LEFT);
                
                if ($branch_column_exists && !$view_all_branches) {
                    $sql_new_cust = "INSERT INTO customers (customer_name, customer_code, email, phone_number, address, status, branch_id) 
                                   VALUES (?, ?, ?, ?, ?, 'active', ?)";
                    $stmt_new_cust = $conn->prepare($sql_new_cust);
                    if (!$stmt_new_cust) {
                        throw new Exception("Database error preparing new customer: " . $conn->error);
                    }
                    $stmt_new_cust->bind_param('sssssi', $customer_name, $customer_code, $email, $phone, $address, $branch_id);
                } else {
                    $sql_new_cust = "INSERT INTO customers (customer_name, customer_code, email, phone_number, address, status) 
                                   VALUES (?, ?, ?, ?, ?, 'active')";
                    $stmt_new_cust = $conn->prepare($sql_new_cust);
                    if (!$stmt_new_cust) {
                        throw new Exception("Database error preparing new customer: " . $conn->error);
                    }
                    $stmt_new_cust->bind_param('sssss', $customer_name, $customer_code, $email, $phone, $address);
                }
                
                if (!$stmt_new_cust->execute()) {
                    throw new Exception("Failed to create new customer: " . $stmt_new_cust->error);
                }
                $customer_id = $stmt_new_cust->insert_id;
                error_log("Created new customer ID: $customer_id for branch ID: $branch_id");
            }
        }
        
        if ($customer_id === 0) {
            throw new Exception("Customer is required");
        }
        
        error_log("Using customer ID: $customer_id");
        
        // Validate stock availability before processing
        foreach ($items_data as $item) {
            $item_id = (int)$item['id'];
            $quantity = (int)$item['quantity'];
            
            // Check current stock from items table with branch filter
            if ($items_branch_column_exists && !$view_all_branches) {
                $stock_check = $conn->query("SELECT stock FROM items WHERE item_id = $item_id AND branch_id = $branch_id");
            } else {
                $stock_check = $conn->query("SELECT stock FROM items WHERE item_id = $item_id");
            }
            
            $stock_row = $stock_check->fetch_assoc();
            $current_stock = $stock_row ? (int)$stock_row['stock'] : 0;
            
            if ($quantity > $current_stock) {
                $item_name = isset($item['name']) ? $item['name'] : "Item ID: $item_id";
                throw new Exception("Insufficient stock for $item_name. Available: $current_stock, Requested: $quantity");
            }
        }
        
        // Create sales order
        $total_amount = 0;
        foreach ($items_data as $item) {
            $total_amount += $item['price'] * $item['quantity'];
        }
        
        $so_number = 'SO-' . date('Ymd') . '-' . substr(time(), -4) . rand(100, 999);
        $order_date = date('Y-m-d H:i:s');
        $user_id = getUserId();
        $branch_id = getUserBranchId();

        error_log("Creating sales order: SO Number: $so_number, User ID: $user_id, Branch ID: $branch_id, Customer ID: $customer_id, Total: $total_amount");

        if ($user_id === 0) {
            throw new Exception("User session invalid. Please log in again.");
        }

        $sql = "INSERT INTO sales_orders (so_number, customer_id, branch_id, order_date, total_amount, order_status, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        
        $status = 'pending';
        $stmt->bind_param('siisdss', $so_number, $customer_id, $branch_id, $order_date, $total_amount, $status, $user_id);

        if (!$stmt->execute()) {
            throw new Exception("Error creating order: " . $stmt->error);
        }
        
        $so_id = $stmt->insert_id;
        error_log("Sales order created with ID: $so_id");
        
        // Insert order items and deduct inventory
        $sql_items = "INSERT INTO sales_order_items (so_id, item_id, quantity_ordered, unit_price)
                     VALUES (?, ?, ?, ?)";
        $stmt_items = $conn->prepare($sql_items);
        if (!$stmt_items) {
            throw new Exception("Prepare failed for order items: " . $conn->error);
        }
        
        foreach ($items_data as $item) {
            $item_id = (int)$item['id'];
            $quantity = (int)$item['quantity'];
            $unit_price = (float)$item['price'];
            
            $stmt_items->bind_param('iiid', $so_id, $item_id, $quantity, $unit_price);
            if (!$stmt_items->execute()) {
                throw new Exception("Error adding order item: " . $stmt_items->error);
            }
            
            error_log("Added order item: Item ID: $item_id, Qty: $quantity, Price: $unit_price");
            
            // Deduct inventory from items table stock column with branch filter
            if ($items_branch_column_exists && !$view_all_branches) {
                $sql_deduct = "UPDATE items 
                              SET stock = stock - ? 
                              WHERE item_id = ? AND branch_id = ? 
                              AND stock >= ?";
                $stmt_deduct = $conn->prepare($sql_deduct);
                if (!$stmt_deduct) {
                    throw new Exception("Prepare failed for stock update: " . $conn->error);
                }
                $stmt_deduct->bind_param('iiii', $quantity, $item_id, $branch_id, $quantity);
            } else {
                $sql_deduct = "UPDATE items 
                              SET stock = stock - ? 
                              WHERE item_id = ? 
                              AND stock >= ?";
                $stmt_deduct = $conn->prepare($sql_deduct);
                if (!$stmt_deduct) {
                    throw new Exception("Prepare failed for stock update: " . $conn->error);
                }
                $stmt_deduct->bind_param('iii', $quantity, $item_id, $quantity);
            }
            
            if (!$stmt_deduct->execute()) {
                throw new Exception("Error updating inventory: " . $stmt_deduct->error);
            }
            
            if ($stmt_deduct->affected_rows === 0) {
                throw new Exception("Failed to deduct inventory for item ID: $item_id. Stock may have changed.");
            }
            
            error_log("Updated item stock: Item ID: $item_id, Deducted: $quantity");
        }
        
        $conn->commit();
        error_log("Order submitted successfully! SO Number: $so_number");
        
        echo json_encode([
            'success' => true, 
            'message' => 'Order submitted successfully!', 
            'so_number' => $so_number,
            'so_id' => $so_id
        ]);
        exit;
        
    } catch (Exception $e) {
        $conn->rollback();
        error_log("Order submission error: " . $e->getMessage());
        echo json_encode([
            'success' => false, 
            'message' => $e->getMessage()
        ]);
        exit;
    }
}
?>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order Product - Sales</title>
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
    <style>
        .cart-item {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 10px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-left: 4px solid var(--primary-green);
        }
        
        .order-summary {
            background: linear-gradient(135deg, var(--dark-green), var(--primary-green));
            color: white;
            padding: 20px;
            border-radius: 8px;
            position: sticky;
            top: 20px;
        }
        
        .error-message {
            color: #f87171;
            font-size: 0.875rem;
            margin-top: 5px;
            min-height: 18px;
        }
        
        /* Custom quantity input with plus/minus buttons */
        .quantity-control {
            display: flex;
            align-items: center;
            width: 100%;
        }
        
        .quantity-control button {
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 1px solid #dee2e6;
            background: white;
            font-size: 18px;
            font-weight: bold;
            cursor: pointer;
        }
        
        .quantity-control button:hover {
            background: #f8f9fa;
        }
        
        .quantity-control button:active {
            background: #e9ecef;
        }
        
        .quantity-control button:disabled {
            background: #e9ecef;
            color: #adb5bd;
            cursor: not-allowed;
        }
        
        .quantity-control .decrease-btn {
            border-radius: 6px 0 0 6px;
            color: #dc3545;
            border-right: none;
        }
        
        .quantity-control .increase-btn {
            border-radius: 0 6px 6px 0;
            color: #28a745;
            border-left: none;
        }
        
        .quantity-control input {
            width: 50px;
            height: 36px;
            text-align: center;
            border: 1px solid #dee2e6;
            font-size: 16px;
            -moz-appearance: textfield;
        }
        
        /* Hide number input arrows */
        .quantity-control input::-webkit-outer-spin-button,
        .quantity-control input::-webkit-inner-spin-button {
            -webkit-appearance: none;
            margin: 0;
        }
        
        .quantity-control input:focus {
            outline: none;
        }
        
        .quantity-control input:disabled {
            background: #e9ecef;
            cursor: not-allowed;
        }
        
        /* Add to Cart Button */
        .btn-add-to-cart {
            height: 36px;
            font-size: 14px;
            padding: 6px 12px;
            border-radius: 6px;
            background: #28a745;
            color: white;
            border: none !important;
            font-weight: 500;
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 5px;
            margin-top: 8px;
            cursor: pointer;
        }
        
        .btn-add-to-cart:hover {
            background: #218838;
        }
        
        .btn-add-to-cart:active {
            background: #1e7e34;
        }
        
        .btn-add-to-cart:disabled {
            background: #6c757d;
            color: white;
            cursor: not-allowed;
        }
        
        .btn-add-to-cart i {
            font-size: 16px;
        }
        
        /* Stock warning */
        .stock-warning {
            color: #ff6b6b;
            font-size: 12px;
            font-weight: 500;
            margin-bottom: 5px;
        }
        
        /* Toast notification */
        .toast-notification {
            position: fixed;
            top: 20px;
            right: 20px;
            background: var(--primary-green);
            color: white;
            padding: 12px 20px;
            border-radius: 8px;
            z-index: 9999;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            animation: slideIn 0.3s ease-out;
        }
        
        @keyframes slideIn {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        
        @keyframes slideOut {
            from { transform: translateX(0); opacity: 1; }
            to { transform: translateX(100%); opacity: 0; }
        }
        
        /* Success Modal Styles */
        .success-icon {
            font-size: 4rem;
            color: #28a745;
            margin-bottom: 20px;
        }
        
        .order-details {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
            margin: 15px 0;
        }
        
        .order-details h6 {
            color: #495057;
            margin-bottom: 10px;
        }
        
        /* Branch Badge */
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
        
        /* MOBILE PREVIEW STYLES */
        @media (max-width: 768px) {
            #productsContainer {
                display: grid !important;
                grid-template-columns: repeat(2, 1fr) !important;
                gap: 12px !important;
            }
            
            #productsContainer .col-md-6 {
                width: 100% !important;
                padding: 0 !important;
                margin-bottom: 0 !important;
            }
            
            .product-card-mobile {
                margin-bottom: 0 !important;
                height: 100% !important;
                display: flex !important;
                flex-direction: column !important;
                border: 1px solid #dee2e6 !important;
                border-radius: 8px !important;
                overflow: hidden !important;
                background: white !important;
            }
            
            .product-card-mobile .card {
                height: 100% !important;
                border: none !important;
                margin: 0 !important;
            }
            
            .product-card-mobile .card-body {
                flex: 1 !important;
                padding: 15px !important;
                display: flex !important;
                flex-direction: column !important;
            }
            
            .product-card-mobile .card-title {
                font-size: 14px !important;
                font-weight: 600 !important;
                line-height: 1.4 !important;
                height: 2.8em !important;
                overflow: hidden !important;
                display: -webkit-box !important;
                -webkit-line-clamp: 2 !important;
                -webkit-box-orient: vertical !important;
                margin-bottom: 8px !important;
                color: #333 !important;
            }
            
            .product-card-mobile .badge {
                font-size: 10px !important;
                padding: 4px 8px !important;
                background: #f8f9fa !important;
                color: #666 !important;
                border: 1px solid #dee2e6 !important;
            }
            
            .product-stock-mobile {
                font-size: 12px !important;
                color: #6c757d !important;
                margin-bottom: 8px !important;
                line-height: 1.4 !important;
            }
            
            .product-price-mobile {
                font-size: 16px !important;
                font-weight: 700 !important;
                color: #28a745 !important;
                margin-bottom: 12px !important;
            }
            
            .product-input-group-mobile {
                margin-top: auto !important;
            }
            
            .product-input-group-mobile .error-message {
                font-size: 11px !important;
                text-align: center !important;
                margin-top: 5px !important;
                min-height: 16px !important;
            }
            
            .order-summary {
                position: static !important;
                margin-top: 20px !important;
                border-radius: 10px !important;
            }
            
            .cart-item {
                padding: 12px !important;
                margin-bottom: 8px !important;
                border-radius: 8px !important;
            }
            
            .btn-group-mobile .btn {
                padding: 12px !important;
                font-size: 14px !important;
                margin-bottom: 8px !important;
                border-radius: 8px !important;
            }
        }
        
        /* DESKTOP VIEW */
        @media (min-width: 769px) {
            #productsContainer {
                display: flex !important;
                flex-wrap: wrap !important;
            }
            
            #productsContainer .col-md-6 {
                width: 50% !important;
                padding: 10px !important;
            }
            
            .product-card-mobile {
                display: block !important;
                height: 100%;
            }
            
            .product-card-mobile .card {
                height: 100%;
                display: flex;
                flex-direction: column;
            }
            
            .product-card-mobile .card-body {
                flex: 1;
                display: flex;
                flex-direction: column;
            }
            
            .product-input-group-mobile {
                margin-top: auto;
                display: flex;
                flex-direction: column;
            }
            
            .quantity-control {
                width: 100%;
            }
            
            .btn-add-to-cart {
                margin-top: auto;
                align-self: flex-end;
                width: auto;
                min-width: 120px;
            }
            
            .product-card-mobile .card-title {
                font-size: 16px;
                font-weight: 600;
                line-height: 1.4;
                margin-bottom: 10px;
                color: #333;
                min-height: 2.8em;
            }
            
            .product-card-mobile .badge {
                font-size: 12px;
                padding: 4px 8px;
            }
            
            .product-stock-mobile {
                font-size: 14px;
                margin-bottom: 10px;
            }
            
            .product-price-mobile {
                font-size: 18px;
                margin-bottom: 15px;
            }
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
                        <a class="nav-link active" href="orderproduct.php">
                            <i class="bi bi-bag"></i>
                            <span class="nav-text">Order Product</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="sales_order.php">
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
            <!-- Header Section -->
            <div class="navbar-top">
                <button class="mobile-toggle-btn" id="mobileToggleBtn">
                    <i class="bi bi-list"></i>
                </button>
                <div class="page-title">
                    <h2>Order Products</h2>
                    <p>Select products and quantities to create an order</p>
                </div>
            </div>

            <!-- Branch Info Alert (if no branch_id column in items or customers) -->
            <?php if (!$items_branch_column_exists): ?>
                <div class="alert alert-info alert-dismissible fade show" role="alert">
                    <i class="bi bi-info-circle"></i> 
                    <strong>Branch filtering for products not yet set up.</strong> Please run this SQL in phpMyAdmin to enable branch-specific product data:
                    <br><br>
                    <code>ALTER TABLE items ADD COLUMN branch_id INT NULL;</code>
                    <br>
                    <code>ALTER TABLE items ADD FOREIGN KEY (branch_id) REFERENCES branches(branch_id);</code>
                    <br><br>
                    <button type="button" class="btn btn-sm btn-primary" onclick="copyItemsSQL()">
                        <i class="bi bi-files"></i> Copy SQL
                    </button>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <script>
                    function copyItemsSQL() {
                        const sql = "ALTER TABLE items ADD COLUMN branch_id INT NULL;\nALTER TABLE items ADD FOREIGN KEY (branch_id) REFERENCES branches(branch_id);";
                        navigator.clipboard.writeText(sql).then(() => {
                            alert('SQL copied to clipboard!');
                        });
                    }
                </script>
            <?php endif; ?>

            <?php if (!$branch_column_exists): ?>
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

            <div class="row">
                <!-- Products Section -->
                <div class="col-lg-8">
                    <!-- Available Products -->
                    <div class="card mb-4">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">Available Products</h5>
                            <?php if ($items_branch_column_exists && !$view_all_branches): ?>
                            <?php elseif ($view_all_branches): ?>
                                <span class="badge bg-success">All Branches</span>
                            <?php endif; ?>
                        </div>
                        <div class="card-body">
                            <?php if (count($items) === 0): ?>
                                <div class="alert alert-warning">
                                    <i class="bi bi-exclamation-triangle"></i> No products available for your branch.
                                </div>
                            <?php endif; ?>
                            <div class="row g-3" id="productsContainer">
                                <!-- Products will be populated here -->
                            </div>
                        </div>
                    </div>

                    <!-- Customer Information -->
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">Customer Information</h5>
                            <?php if ($branch_column_exists && !$view_all_branches): ?>
                            <?php elseif ($view_all_branches): ?>
                                <span class="badge bg-success">All Branches</span>
                            <?php endif; ?>
                        </div>
                        <div class="card-body">
                            <?php if ($branch_column_exists && !$view_all_branches && count($customers) === 0): ?>
                                <div class="alert alert-warning mb-3">
                                    <i class="bi bi-exclamation-triangle"></i> No customers found for your branch. You can add new customers.
                                </div>
                            <?php endif; ?>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">Select Customer</label>
                                    <select class="form-select" id="customerSelect">
                                        <option value="">-- Choose Customer --</option>
                                        <?php foreach ($customers as $customer): ?>
                                            <option value="<?php echo $customer['customer_id']; ?>" 
                                                    data-email="<?php echo htmlspecialchars($customer['email'] ?? ''); ?>"
                                                    data-phone="<?php echo htmlspecialchars($customer['phone_number'] ?? ''); ?>"
                                                    data-address="<?php echo htmlspecialchars($customer['address'] ?? ''); ?>">
                                                <?php echo htmlspecialchars($customer['customer_name']); ?>
                                                <?php if (isset($customer['branch_name']) && $view_all_branches): ?>
                                                    (<?php echo htmlspecialchars($customer['branch_name'] ?? 'No Branch'); ?>)
                                                <?php endif; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Email</label>
                                    <input type="email" class="form-control" id="customerEmail" placeholder="customer@example.com">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Phone</label>
                                    <input type="tel" class="form-control" id="customerPhone" placeholder="(555) 000-0000">
                                </div>
                                <div class="col-6">
                                    <label class="form-label">Address</label>
                                    <textarea class="form-control" id="customerAddress" rows="3" placeholder="Delivery address"></textarea>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Cart Section -->
                <div class="col-lg-4">
                    <div class="order-summary">
                        <h5 class="mb-3"><i class="bi bi-cart3"></i> Order Summary</h5>
                        
                        <div id="cartItems" class="mb-3">
                            <p class="text-white-50 text-center">No items in cart</p>
                        </div>

                        <hr class="bg-white-50">

                        <div class="mb-2">
                            <div class="d-flex justify-content-between">
                                <span>Subtotal:</span>
                                <span id="subtotal">₱0.00</span>
                            </div>
                        </div>
                        <div class="mb-3">
                            <div class="d-flex justify-content-between">
                                <span>Total Items:</span>
                                <span id="totalItems">0</span>
                            </div>
                        </div>

                        <hr class="bg-white-50">

                        <div class="mb-4">
                            <h6 class="mb-2">Total</h6>
                            <h3 id="totalPrice" class="mb-0">₱0.00</h3>
                        </div>

                        <div class="btn-group-mobile">
                            <button class="btn btn-light w-100 mb-2" onclick="viewCart()">
                                <i class="bi bi-eye"></i> View & Confirm Order
                            </button>
                            <button class="btn btn-outline-light w-100" onclick="clearCart()">
                                <i class="bi bi-trash"></i> Clear Cart
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Cart Modal -->
    <div class="modal fade" id="cartModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Review & Confirm Order</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <h6 class="mb-3">Order Items</h6>
                    <div id="reviewItems" class="mb-4"></div>

                    <h6 class="mb-3">Delivery Information</h6>
                    <div class="alert alert-light">
                        <p class="mb-2"><strong>Customer:</strong> <span id="reviewCustomer">-</span></p>
                        <p class="mb-2"><strong>Email:</strong> <span id="reviewEmail">-</span></p>
                        <p class="mb-2"><strong>Phone:</strong> <span id="reviewPhone">-</span></p>
                        <p class="mb-0"><strong>Address:</strong> <span id="reviewAddress">-</span></p>
                    </div>

                    <h6 class="mb-3">Order Total</h6>
                    <div class="alert alert-light">
                        <div class="d-flex justify-content-between mb-2">
                            <span>Subtotal:</span>
                            <span id="reviewSubtotal">₱0.00</span>
                        </div>
                        <div class="d-flex justify-content-between">
                            <strong>Total:</strong>
                            <strong id="reviewTotal" class="text-success">₱0.00</strong>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Add Order</button>
                    <button type="button" class="btn btn-success" onclick="submitOrder()">
                        <i class="bi bi-check-circle"></i> Confirm & Submit Order
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Success Modal -->
    <div class="modal fade" id="successModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header border-0">
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center py-4">
                    <div class="success-icon">
                        <i class="bi bi-check-circle-fill"></i>
                    </div>
                    <h4 class="modal-title mb-3">Order Submitted Successfully!</h4>
                    
                    <div class="order-details">
                        <h6>Order Details</h6>
                        <p class="mb-2"><strong>Order Number:</strong> <span id="successSoNumber">-</span></p>
                        <p class="mb-2"><strong>Date:</strong> <span id="successOrderDate">-</span></p>
                        <p class="mb-2"><strong>Branch:</strong> <span id="successBranch">Branch <?php echo $branch_id; ?></span></p>
                        <p class="mb-0"><strong>Status:</strong> <span class="badge bg-success">Pending</span></p>
                    </div>
                    
                    <p class="text-muted">Your order has been submitted and is being processed. You can track its status in the orders section.</p>
                </div>
                <div class="modal-footer border-0 justify-content-center">
                    <button type="button" class="btn btn-primary" onclick="createNewOrder()">
                        <i class="bi bi-plus-circle me-2"></i> Create New Order
                    </button>
                    <button type="button" class="btn btn-outline-secondary" onclick="viewOrders()">
                        <i class="bi bi-list-ul me-2"></i> View Orders
                    </button>
                </div>
            </div>
        </div>
    </div>

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

        // Inventory data from database with branch context
        const inventory = <?php echo json_encode(array_map(function($item) {
            return [
                'id' => (int)$item['item_id'],
                'name' => $item['item_name'],
                'sku' => $item['item_code'],
                'price' => (float)$item['unit_price'],
                'stock' => (int)($item['stock'] ?? 0)
            ];
        }, $items)); ?>;

        // Branch context
        const branchId = <?php echo $branch_id; ?>;
        const viewAllBranches = <?php echo $view_all_branches ? 'true' : 'false'; ?>;
        const itemsBranchColumnExists = <?php echo $items_branch_column_exists ? 'true' : 'false'; ?>;
        const customersBranchColumnExists = <?php echo $branch_column_exists ? 'true' : 'false'; ?>;

        let cart = [];

        // Initialize page
        function init() {
            renderProducts();
            
            // Add customer select change listener for autofill
            const customerSelect = document.getElementById('customerSelect');
            if (customerSelect) {
                customerSelect.addEventListener('change', function() {
                    const selectedOption = this.options[this.selectedIndex];
                    if (this.value) {
                        const email = selectedOption.getAttribute('data-email') || '';
                        const phone = selectedOption.getAttribute('data-phone') || '';
                        const address = selectedOption.getAttribute('data-address') || '';
                        
                        document.getElementById('customerEmail').value = email;
                        document.getElementById('customerPhone').value = phone;
                        document.getElementById('customerAddress').value = address;
                        document.getElementById('newCustomerName').value = '';
                    } else {
                        document.getElementById('customerEmail').value = '';
                        document.getElementById('customerPhone').value = '';
                        document.getElementById('customerAddress').value = '';
                    }
                });
            }
            
            const newCustomerName = document.getElementById('newCustomerName');
            if (newCustomerName) {
                newCustomerName.addEventListener('input', function() {
                    if (this.value.trim() !== '') {
                        document.getElementById('customerSelect').value = '';
                    }
                });
            }
            
            updateCart();
        }

        // Calculate available stock for a product (considering items in cart)
        function getAvailableStock(productId) {
            const product = inventory.find(p => p.id === productId);
            if (!product) return 0;
            
            const cartItem = cart.find(item => item.id === productId);
            const inCart = cartItem ? cartItem.quantity : 0;
            
            return Math.max(0, product.stock - inCart);
        }

        // Render product cards with plus/minus buttons
        function renderProducts() {
            const container = document.getElementById('productsContainer');
            if (!container) return;
            
            if (inventory.length === 0) {
                container.innerHTML = `
                    <div class="col-12">
                        <div class="alert alert-info text-center py-4">
                            <i class="bi bi-box-seam" style="font-size: 2rem;"></i>
                            <p class="mt-3 mb-0">No products available for your branch.</p>
                        </div>
                    </div>
                `;
                return;
            }
            
            container.innerHTML = inventory.map(product => {
                const availableStock = getAvailableStock(product.id);
                const outOfStock = availableStock === 0;
                const lowStock = availableStock > 0 && availableStock < 10;
                
                return `
                <div class="col-md-6 product-card-mobile">
                    <div class="card h-100">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <h6 class="card-title mb-0">${product.name}</h6>
                                <span class="badge bg-light text-dark">${product.sku}</span>
                            </div>
                            <p class="text-muted small mb-1 product-stock-mobile">
                                Available: <strong class="${lowStock ? 'text-danger' : ''}">${availableStock} units</strong>
                                ${lowStock && !outOfStock ? '<span class="stock-warning"> - Low Stock</span>' : ''}
                            </p>
                            ${outOfStock ? '<div class="stock-warning mb-1">Out of Stock</div>' : ''}
                            <p class="h5 text-success mb-3 product-price-mobile">₱${product.price.toFixed(2)}</p>
                            
                            <div class="product-input-group-mobile">
                                <div class="quantity-control">
                                    <button type="button" class="decrease-btn" onclick="decreaseQuantity(${product.id})" ${outOfStock ? 'disabled' : ''}>
                                        <i class="bi bi-dash-lg"></i>
                                    </button>
                                    <input type="number" class="form-control" id="qty-${product.id}" 
                                           min="0" max="${availableStock}" value="0" 
                                           onchange="updateQuantityInput(${product.id})"
                                           oninput="validateQuantity(${product.id})"
                                           ${outOfStock ? 'disabled' : ''}>
                                    <button type="button" class="increase-btn" onclick="increaseQuantity(${product.id})" ${outOfStock ? 'disabled' : ''}>
                                        <i class="bi bi-plus-lg"></i>
                                    </button>
                                </div>
                                
                                <button class="btn-add-to-cart" type="button" 
                                        onclick="addToCart(${product.id})"
                                        id="btn-add-${product.id}"
                                        ${outOfStock ? 'disabled' : ''}>
                                    <i class="bi bi-cart-plus"></i> Add to Cart
                                </button>
                                
                                <div class="error-message" id="error-${product.id}"></div>
                            </div>
                        </div>
                    </div>
                </div>
                `;
            }).join('');
        }

        // Decrease quantity
        function decreaseQuantity(productId) {
            const qtyInput = document.getElementById(`qty-${productId}`);
            if (qtyInput) {
                let currentValue = parseInt(qtyInput.value) || 0;
                if (currentValue > 0) {
                    qtyInput.value = currentValue - 1;
                    validateQuantity(productId);
                }
            }
        }

        // Increase quantity
        function increaseQuantity(productId) {
            const qtyInput = document.getElementById(`qty-${productId}`);
            if (qtyInput) {
                const availableStock = getAvailableStock(productId);
                let currentValue = parseInt(qtyInput.value) || 0;
                if (currentValue < availableStock) {
                    qtyInput.value = currentValue + 1;
                    validateQuantity(productId);
                } else {
                    document.getElementById(`error-${productId}`).textContent = `Only ${availableStock} available`;
                }
            }
        }

        // Update quantity input max value based on available stock
        function updateQuantityInput(productId) {
            const qtyInput = document.getElementById(`qty-${productId}`);
            if (qtyInput) {
                const availableStock = getAvailableStock(productId);
                qtyInput.max = availableStock;
            }
        }

        // Validate quantity input and update button state
        function validateQuantity(productId) {
            const qtyInput = document.getElementById(`qty-${productId}`);
            const addButton = document.getElementById(`btn-add-${productId}`);
            const errorDiv = document.getElementById(`error-${productId}`);
            const availableStock = getAvailableStock(productId);
            
            if (!qtyInput) return 0;
            
            let value = parseInt(qtyInput.value) || 0;
            
            if (value < 0) {
                value = 0;
                qtyInput.value = 0;
            }
            
            if (value > availableStock) {
                value = availableStock;
                qtyInput.value = availableStock;
                if (errorDiv) errorDiv.textContent = `Max ${availableStock} units`;
                if (addButton) addButton.disabled = false;
            } else if (value === 0) {
                if (errorDiv) errorDiv.textContent = '';
                if (addButton) addButton.disabled = true;
            } else {
                if (errorDiv) errorDiv.textContent = '';
                if (addButton) addButton.disabled = false;
            }
            
            return value;
        }

        // Add to cart with validation
        function addToCart(productId) {
            const product = inventory.find(p => p.id === productId);
            const qtyInput = document.getElementById(`qty-${productId}`);
            const quantity = parseInt(qtyInput?.value) || 0;
            const errorDiv = document.getElementById(`error-${productId}`);
            const availableStock = getAvailableStock(productId);

            if (quantity <= 0) {
                if (errorDiv) errorDiv.textContent = 'Please enter a quantity';
                return;
            }

            if (quantity > availableStock) {
                if (errorDiv) errorDiv.textContent = `Only ${availableStock} available`;
                return;
            }

            const existingItem = cart.find(item => item.id === productId);
            
            if (existingItem) {
                const newTotal = existingItem.quantity + quantity;
                if (newTotal > product.stock) {
                    if (errorDiv) errorDiv.textContent = `Cannot add more than ${product.stock} total`;
                    return;
                }
                existingItem.quantity += quantity;
            } else {
                cart.push({
                    id: productId,
                    name: product.name,
                    price: product.price,
                    quantity: quantity,
                    sku: product.sku
                });
            }

            if (qtyInput) qtyInput.value = '0';
            updateCart();
            renderProducts();
            
            showToast(`${quantity} × ${product.name} added to cart!`);
        }

        // Show toast notification
        function showToast(message) {
            const existingToast = document.querySelector('.toast-notification');
            if (existingToast) {
                existingToast.remove();
            }
            
            const toast = document.createElement('div');
            toast.className = 'toast-notification';
            toast.innerHTML = `
                <div class="d-flex align-items-center">
                    <i class="bi bi-check-circle-fill me-2"></i>
                    <span>${message}</span>
                </div>
            `;
            
            document.body.appendChild(toast);
            
            setTimeout(() => {
                toast.style.animation = 'slideOut 0.3s ease-out';
                setTimeout(() => toast.remove(), 300);
            }, 3000);
        }

        // Update cart display
        function updateCart() {
            const cartItemsDiv = document.getElementById('cartItems');
            const subtotalDiv = document.getElementById('subtotal');
            const totalItemsDiv = document.getElementById('totalItems');
            const totalPriceDiv = document.getElementById('totalPrice');

            if (!cartItemsDiv) return;

            if (cart.length === 0) {
                cartItemsDiv.innerHTML = '<p class="text-white-50 text-center">No items in cart</p>';
                if (subtotalDiv) subtotalDiv.textContent = '₱0.00';
                if (totalItemsDiv) totalItemsDiv.textContent = '0';
                if (totalPriceDiv) totalPriceDiv.textContent = '₱0.00';
                return;
            }

            cartItemsDiv.innerHTML = cart.map(item => {
                const product = inventory.find(p => p.id === item.id);
                const remainingStock = product ? product.stock - item.quantity : 0;
                const lowStockWarning = remainingStock < 10 ? `<div class="text-warning small mt-1">${remainingStock} left in stock</div>` : '';
                
                return `
                <div class="cart-item">
                    <div style="flex: 1;">
                        <div class="text-black-50 small">${item.name}</div>
                        <div class="text-black-50 small">${item.sku}</div>
                        <div class="text-black-50 small">₱${item.price.toFixed(2)} × ${item.quantity}</div>
                        ${lowStockWarning}
                    </div>
                    <div class="text-end">
                        <div class="text-black fw-bold">₱${(item.price * item.quantity).toFixed(2)}</div>
                        <button class="btn btn-sm btn-outline-light mt-1" onclick="removeFromCart(${item.id})">
                            <i class="bi bi-trash"></i>
                        </button>
                    </div>
                </div>
                `;
            }).join('');

            const subtotal = cart.reduce((sum, item) => sum + (item.price * item.quantity), 0);
            const totalItems = cart.reduce((sum, item) => sum + item.quantity, 0);

            if (subtotalDiv) subtotalDiv.textContent = `₱${subtotal.toFixed(2)}`;
            if (totalItemsDiv) totalItemsDiv.textContent = totalItems;
            if (totalPriceDiv) totalPriceDiv.textContent = `₱${subtotal.toFixed(2)}`;
        }

        // Remove from cart
        function removeFromCart(productId) {
            cart = cart.filter(item => item.id !== productId);
            updateCart();
            renderProducts();
            showToast('Item removed from cart');
        }

        // Clear cart
        function clearCart() {
            if (cart.length === 0) {
                showToast('Cart is already empty');
                return;
            }
            
            if (confirm('Clear all items from cart?')) {
                cart = [];
                updateCart();
                renderProducts();
                showToast('Cart cleared');
            }
        }

        // View cart and confirm
        function viewCart() {
            if (cart.length === 0) {
                showToast('Please add items to cart first');
                return;
            }

            const customerSelect = document.getElementById('customerSelect');
            const newCustomer = document.getElementById('newCustomerName')?.value.trim() || '';
            const email = document.getElementById('customerEmail')?.value.trim() || '';
            const phone = document.getElementById('customerPhone')?.value.trim() || '';
            const address = document.getElementById('customerAddress')?.value.trim() || '';
            
            const selectedCustomer = customerSelect?.options[customerSelect.selectedIndex];
            const customerName = selectedCustomer?.value ? selectedCustomer.text.split('(')[0].trim() : newCustomer;

            if (!customerSelect?.value && !newCustomer) {
                showToast('Please select or enter a customer');
                return;
            }

            if (!email) {
                showToast('Please enter customer email');
                return;
            }

            if (!phone) {
                showToast('Please enter customer phone');
                return;
            }

            if (!address) {
                showToast('Please enter delivery address');
                return;
            }

            populateReviewModal(customerName, email, phone, address);
            
            const modal = new bootstrap.Modal(document.getElementById('cartModal'));
            modal.show();
        }

        // Populate review modal
        function populateReviewModal(customerName, email, phone, address) {
            const reviewItems = document.getElementById('reviewItems');
            if (reviewItems) {
                reviewItems.innerHTML = `
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead class="table-light">
                                <tr>
                                    <th>Product</th>
                                    <th>SKU</th>
                                    <th>Price</th>
                                    <th>Qty</th>
                                    <th>Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${cart.map(item => {
                                    const product = inventory.find(p => p.id === item.id);
                                    const remainingStock = product ? product.stock - item.quantity : 0;
                                    return `
                                    <tr>
                                        <td>
                                            ${item.name}
                                            ${remainingStock < 10 ? 
                                                `<br><small class="text-warning">${remainingStock} left in stock</small>` : ''}
                                        </td>
                                        <td>${item.sku}</td>
                                        <td>₱${item.price.toFixed(2)}</td>
                                        <td>${item.quantity}</td>
                                        <td>₱${(item.price * item.quantity).toFixed(2)}</td>
                                    </tr>
                                    `;
                                }).join('')}
                            </tbody>
                        </table>
                    </div>
                `;
            }

            const reviewCustomer = document.getElementById('reviewCustomer');
            const reviewEmail = document.getElementById('reviewEmail');
            const reviewPhone = document.getElementById('reviewPhone');
            const reviewAddress = document.getElementById('reviewAddress');
            const reviewSubtotal = document.getElementById('reviewSubtotal');
            const reviewTotal = document.getElementById('reviewTotal');
            
            if (reviewCustomer) reviewCustomer.textContent = customerName;
            if (reviewEmail) reviewEmail.textContent = email;
            if (reviewPhone) reviewPhone.textContent = phone;
            if (reviewAddress) reviewAddress.textContent = address;

            const subtotal = cart.reduce((sum, item) => sum + (item.price * item.quantity), 0);
            if (reviewSubtotal) reviewSubtotal.textContent = `₱${subtotal.toFixed(2)}`;
            if (reviewTotal) reviewTotal.textContent = `₱${subtotal.toFixed(2)}`;
        }

        // Submit order
        function submitOrder() {
            const customerSelect = document.getElementById('customerSelect');
            const customer_id = customerSelect?.value ? parseInt(customerSelect.value) : 0;
            const customer_name = document.getElementById('newCustomerName')?.value.trim() || '';
            const email = document.getElementById('customerEmail')?.value.trim() || '';
            const phone = document.getElementById('customerPhone')?.value.trim() || '';
            const address = document.getElementById('customerAddress')?.value.trim() || '';
            
            if (!customer_id && !customer_name) {
                showToast('Please select or enter a customer');
                return;
            }
            
            if (!email || !phone || !address) {
                showToast('Please fill in all customer information');
                return;
            }
            
            if (cart.length === 0) {
                showToast('Cart is empty');
                return;
            }
            
            const cartData = cart.map(item => ({
                id: item.id,
                name: item.name,
                price: item.price,
                quantity: item.quantity,
                sku: item.sku
            }));
            
            const formData = new FormData();
            formData.append('action', 'submit_order');
            formData.append('customer_id', customer_id);
            formData.append('customer_name', customer_name);
            formData.append('email', email);
            formData.append('phone', phone);
            formData.append('address', address);
            formData.append('items', JSON.stringify(cartData));
            
            const submitBtn = document.querySelector('#cartModal .btn-success');
            let originalText = '';
            if (submitBtn) {
                originalText = submitBtn.innerHTML;
                submitBtn.innerHTML = '<i class="bi bi-hourglass-split"></i> Processing...';
                submitBtn.disabled = true;
            }
            
            console.log('Submitting order...');

            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => {
                console.log('Response status:', response.status);
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                return response.text();
            })
            .then(text => {
                console.log('Raw response:', text);
                
                if (!text || text.trim() === '') {
                    throw new Error('Empty response from server');
                }
                
                if (text.trim().startsWith('<')) {
                    console.error('HTML response received instead of JSON:', text.substring(0, 500));
                    throw new Error('Server returned HTML instead of JSON');
                }
                
                try {
                    return JSON.parse(text);
                } catch (e) {
                    console.error('JSON parse error:', e);
                    console.error('Response text:', text);
                    throw new Error('Invalid JSON response from server');
                }
            })
            .then(data => {
                console.log('Parsed response:', data);
                
                const cartModal = bootstrap.Modal.getInstance(document.getElementById('cartModal'));
                if (cartModal) {
                    cartModal.hide();
                }
                
                if (data.success) {
                    cart = [];
                    updateCart();
                    renderProducts();
                    
                    const successSoNumber = document.getElementById('successSoNumber');
                    const successOrderDate = document.getElementById('successOrderDate');
                    const successBranch = document.getElementById('successBranch');
                    
                    if (successSoNumber) successSoNumber.textContent = data.so_number;
                    if (successOrderDate) successOrderDate.textContent = new Date().toLocaleDateString();
                    if (successBranch) successBranch.textContent = `Branch ${branchId}`;
                    
                    const successModal = new bootstrap.Modal(document.getElementById('successModal'));
                    successModal.show();
                    
                    setTimeout(() => {
                        const customerSelect = document.getElementById('customerSelect');
                        const newCustomerName = document.getElementById('newCustomerName');
                        const customerEmail = document.getElementById('customerEmail');
                        const customerPhone = document.getElementById('customerPhone');
                        const customerAddress = document.getElementById('customerAddress');
                        
                        if (customerSelect) customerSelect.value = '';
                        if (newCustomerName) newCustomerName.value = '';
                        if (customerEmail) customerEmail.value = '';
                        if (customerPhone) customerPhone.value = '';
                        if (customerAddress) customerAddress.value = '';
                    }, 500);
                    
                } else {
                    showToast('Error: ' + (data.message || 'Failed to submit order'));
                    if (submitBtn) {
                        submitBtn.innerHTML = originalText;
                        submitBtn.disabled = false;
                    }
                }
            })
            .catch(error => {
                console.error('Error:', error.message);
                showToast('Error: ' + error.message);
                if (submitBtn) {
                    submitBtn.innerHTML = originalText;
                    submitBtn.disabled = false;
                }
            });
        }

        // Create new order
        function createNewOrder() {
            const successModal = bootstrap.Modal.getInstance(document.getElementById('successModal'));
            if (successModal) {
                successModal.hide();
            }
        }

        // View orders
        function viewOrders() {
            window.location.href = 'sales_order.php';
        }

        function logout() {
            window.location.href = '../logout.php';
        }

        // Initialize when page loads
        document.addEventListener('DOMContentLoaded', function() {
            console.log("Sales Order page loaded!");
            console.log("Branch ID:", branchId);
            console.log("View All Branches:", viewAllBranches);
            console.log("Items Branch Column Exists:", itemsBranchColumnExists);
            console.log("Customers Branch Column Exists:", customersBranchColumnExists);
            console.log("Products loaded:", inventory.length);
            
            // Initialize sidebar
            initializeSidebar();
            
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
            window.addEventListener('resize', handleSidebarResize);
            
            // Initialize the order product functionality
            init();
        });

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey && e.key === 'b' && window.innerWidth > 992) {
                e.preventDefault();
                toggleSidebar();
            } else if (e.key === 'Escape' && window.innerWidth <= 992) {
                closeMobileSidebar();
            } else if (e.ctrlKey && e.key === 'n') {
                e.preventDefault();
                createNewOrder();
            } else if (e.ctrlKey && e.key === 'v') {
                e.preventDefault();
                viewCart();
            }
        });
    </script>
</body>
</html>