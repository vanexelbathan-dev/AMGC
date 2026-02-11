<?php
require_once '../config/database.php';
require_once '../config/session_handler.php';

// Protect page - only Sales role can access
requireLogin();
requireRole(['sales']);

// Get all items with available stock from items table
$items_result = $conn->query("SELECT i.item_id, i.item_code, i.item_name, i.description, i.category, 
                              i.stock, i.unit_type, i.unit_price, i.reorder_level, i.status
                              FROM items i
                              WHERE i.status = 'active'
                              ORDER BY i.item_code ASC");
$items = [];
if ($items_result) {
    $items = $items_result->fetch_all(MYSQLI_ASSOC);
}

// Get all customers for dropdown
$customers_result = $conn->query("SELECT customer_id, customer_name, email, phone_number, address FROM customers WHERE status = 'active' ORDER BY customer_name ASC");
$customers = [];
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
            
            // Check if customer already exists with this name
            $check_sql = "SELECT customer_id FROM customers WHERE customer_name = ? AND status = 'active'";
            $check_stmt = $conn->prepare($check_sql);
            $check_stmt->bind_param('s', $customer_name);
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
                $customer_code = 'CUST' . str_pad(rand(1000, 9999), 4, '0', STR_PAD_LEFT);
                $sql_new_cust = "INSERT INTO customers (customer_name, customer_code, email, phone_number, address, status) VALUES (?, ?, ?, ?, ?, 'active')";
                $stmt_new_cust = $conn->prepare($sql_new_cust);
                if (!$stmt_new_cust) {
                    throw new Exception("Database error preparing new customer: " . $conn->error);
                }
                $stmt_new_cust->bind_param('sssss', $customer_name, $customer_code, $email, $phone, $address);
                if (!$stmt_new_cust->execute()) {
                    throw new Exception("Failed to create new customer: " . $stmt_new_cust->error);
                }
                $customer_id = $stmt_new_cust->insert_id;
                error_log("Created new customer ID: $customer_id");
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
            
            // Check current stock from items table
            $stock_check = $conn->query("SELECT stock FROM items WHERE item_id = $item_id");
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
        // Fixed: Using 'siisdss' format
        // s = string (so_number)
        // i = integer (customer_id)
        // i = integer (branch_id)
        // s = string (order_date)
        // d = double (total_amount)
        // s = string (order_status)
        // i = integer (created_by)
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
            
            // Deduct inventory from items table stock column
            $sql_deduct = "UPDATE items 
                          SET stock = stock - ? 
                          WHERE item_id = ? 
                          AND stock >= ?";
            $stmt_deduct = $conn->prepare($sql_deduct);
            if (!$stmt_deduct) {
                throw new Exception("Prepare failed for stock update: " . $conn->error);
            }
            $stmt_deduct->bind_param('iii', $quantity, $item_id, $quantity);
            
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
    <link rel="stylesheet" href="../css/style.css">
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
        
        /* Add to Cart Button - GREEN WITHOUT BORDER - SAME FOR BOTH */
        .btn-add-to-cart {
            height: 36px;
            font-size: 14px;
            padding: 6px 12px;
            border-radius: 6px;
            background: #28a745; /* Green background */
            color: white; /* White text */
            border: none !important; /* Remove border */
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
            background: #218838; /* Dark green on hover */
        }
        
        .btn-add-to-cart:active {
            background: #1e7e34; /* Even darker green when clicked */
        }
        
        .btn-add-to-cart:disabled {
            background: #6c757d; /* Gray when disabled */
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
        
        /* MOBILE PREVIEW STYLES ONLY */
        @media (max-width: 768px) {
            /* Products Grid for Mobile - 2 Columns */
            #productsContainer {
                display: grid !important;
                grid-template-columns: repeat(2, 1fr) !important;
                gap: 12px !important;
            }
            
            /* Remove Bootstrap row/col spacing for mobile */
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
            
            /* Product Title */
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
            
            /* SKU Badge */
            .product-card-mobile .badge {
                font-size: 10px !important;
                padding: 4px 8px !important;
                background: #f8f9fa !important;
                color: #666 !important;
                border: 1px solid #dee2e6 !important;
            }
            
            /* Stock Info */
            .product-stock-mobile {
                font-size: 12px !important;
                color: #6c757d !important;
                margin-bottom: 8px !important;
                line-height: 1.4 !important;
            }
            
            /* Price */
            .product-price-mobile {
                font-size: 16px !important;
                font-weight: 700 !important;
                color: #28a745 !important;
                margin-bottom: 12px !important;
            }
            
            /* Quantity Input Group - IMPROVED SPACING */
            .product-input-group-mobile {
                margin-top: auto !important;
            }
            
            /* Error Message */
            .product-input-group-mobile .error-message {
                font-size: 11px !important;
                text-align: center !important;
                margin-top: 5px !important;
                min-height: 16px !important;
            }
            
            /* Cart adjustments for mobile */
            .order-summary {
                position: static !important;
                margin-top: 20px !important;
                border-radius: 10px !important;
            }
            
            /* Cart Items in Mobile */
            .cart-item {
                padding: 12px !important;
                margin-bottom: 8px !important;
                border-radius: 8px !important;
            }
            
            /* Buttons in Mobile */
            .btn-group-mobile .btn {
                padding: 12px !important;
                font-size: 14px !important;
                margin-bottom: 8px !important;
                border-radius: 8px !important;
            }
        }
        
        /* DESKTOP VIEW - ALIGN PROPERLY */
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
            
            /* FOR DESKTOP: Keep button below quantity controls */
            .product-input-group-mobile {
                margin-top: auto;
                display: flex;
                flex-direction: column;
            }
            
            /* FOR DESKTOP: Align quantity controls properly */
            .quantity-control {
                width: 100%;
            }
            
            /* FOR DESKTOP: Add to Cart button positioned at bottom right */
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
    <!-- MOBILE MENU BUTTON -->
    <button class="mobile-menu-btn" id="mobileMenuBtn">
        <i class="bi bi-list"></i>
    </button>

    <!-- MAIN APPLICATION -->
    <div id="appPage">
        <!-- Sidebar -->
        <div class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <h3><i class="bi bi-shop logo-icon"></i> <span class="nav-text">Sales</span></h3>
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
        </div>

        <!-- Main Content Area -->
        <div class="main-content">
            <!-- Header Section with User Info and Logout -->
            <div class="navbar-top">
                <div class="page-title">
                    <h2><i class="bi bi-bag me-2"></i>Order Products</h2>
                    <p>Select products and quantities to create an order</p>
                </div>
                
                <div class="user-info-top">
                    <div class="user-profile-top">
                        <div class="user-avatar-top" id="userAvatar"><?php echo substr(getUserName(), 0, 2); ?></div>
                        <div class="user-details-top">
                            <span class="user-name-top" id="userName"><?php echo getUserName(); ?></span>
                            <span class="user-role-top" id="userRole"><?php echo ucfirst(str_replace('_', ' ', getUserRole())); ?></span>
                        </div>
                    </div>
                    
                    <button class="logout-btn-top" onclick="window.location.href='../logout.php'">
                        <i class="bi bi-box-arrow-right"></i> Logout
                    </button>
                </div>
            </div>

            <div class="row">
                <!-- Products Section -->
                <div class="col-lg-8">
                    <!-- Available Products -->
                    <div class="card mb-4">
                        <div class="card-header bg-light">
                            <h5 class="mb-0">Available Products</h5>
                        </div>
                        <div class="card-body">
                            <div class="row g-3" id="productsContainer">
                                <!-- Products will be populated here -->
                            </div>
                        </div>
                    </div>

                    <!-- Customer Information -->
                    <div class="card">
                        <div class="card-header bg-light">
                            <h5 class="mb-0">Customer Information</h5>
                        </div>
                        <div class="card-body">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">Select Customer</label>
                                    <select class="form-select" id="customerSelect">
                                        <option value="">-- Choose Customer --</option>
                                        <?php foreach ($customers as $customer): ?>
                                            <option value="<?php echo $customer['customer_id']; ?>" 
                                                    data-email="<?php echo htmlspecialchars($customer['email']); ?>"
                                                    data-phone="<?php echo htmlspecialchars($customer['phone_number']); ?>"
                                                    data-address="<?php echo htmlspecialchars($customer['address']); ?>">
                                                <?php echo htmlspecialchars($customer['customer_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Or Enter New Customer</label>
                                    <input type="text" class="form-control" id="newCustomerName" placeholder="Customer name">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Email</label>
                                    <input type="email" class="form-control" id="customerEmail" placeholder="customer@example.com">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Phone</label>
                                    <input type="tel" class="form-control" id="customerPhone" placeholder="(555) 000-0000">
                                </div>
                                <div class="col-12">
                                    <label class="form-label">Address</label>
                                    <textarea class="form-control" id="customerAddress" rows="2" placeholder="Delivery address"></textarea>
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
        // Inventory data from database
        const inventory = <?php echo json_encode(array_map(function($item) {
            return [
                'id' => (int)$item['item_id'],
                'name' => $item['item_name'],
                'sku' => $item['item_code'],
                'price' => (float)$item['unit_price'],
                'stock' => (int)($item['stock'] ?? 0)
            ];
        }, $items)); ?>;

        let cart = [];

        // Initialize page
        function init() {
            renderProducts();
            document.getElementById('mobileMenuBtn').addEventListener('click', function() {
                document.getElementById('sidebar').classList.toggle('show');
            });
            
            // Add customer select change listener for autofill
            document.getElementById('customerSelect').addEventListener('change', function() {
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
            
            document.getElementById('newCustomerName').addEventListener('input', function() {
                if (this.value.trim() !== '') {
                    document.getElementById('customerSelect').value = '';
                    document.getElementById('customerEmail').value = '';
                    document.getElementById('customerPhone').value = '';
                    document.getElementById('customerAddress').value = '';
                }
            });
            
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
            let currentValue = parseInt(qtyInput.value) || 0;
            if (currentValue > 0) {
                qtyInput.value = currentValue - 1;
                validateQuantity(productId);
            }
        }

        // Increase quantity
        function increaseQuantity(productId) {
            const qtyInput = document.getElementById(`qty-${productId}`);
            const availableStock = getAvailableStock(productId);
            let currentValue = parseInt(qtyInput.value) || 0;
            if (currentValue < availableStock) {
                qtyInput.value = currentValue + 1;
                validateQuantity(productId);
            } else {
                document.getElementById(`error-${productId}`).textContent = `Only ${availableStock} available`;
            }
        }

        // Update quantity input max value based on available stock
        function updateQuantityInput(productId) {
            const qtyInput = document.getElementById(`qty-${productId}`);
            const availableStock = getAvailableStock(productId);
            qtyInput.max = availableStock;
        }

        // Validate quantity input and update button state
        function validateQuantity(productId) {
            const qtyInput = document.getElementById(`qty-${productId}`);
            const addButton = document.getElementById(`btn-add-${productId}`);
            const errorDiv = document.getElementById(`error-${productId}`);
            const availableStock = getAvailableStock(productId);
            
            let value = parseInt(qtyInput.value) || 0;
            
            if (value < 0) {
                value = 0;
                qtyInput.value = 0;
            }
            
            if (value > availableStock) {
                value = availableStock;
                qtyInput.value = availableStock;
                errorDiv.textContent = `Max ${availableStock} units`;
                addButton.disabled = false;
            } else if (value === 0) {
                errorDiv.textContent = '';
                addButton.disabled = true;
            } else {
                errorDiv.textContent = '';
                addButton.disabled = false;
            }
            
            return value;
        }

        // Add to cart with validation
        function addToCart(productId) {
            const product = inventory.find(p => p.id === productId);
            const qtyInput = document.getElementById(`qty-${productId}`);
            const quantity = parseInt(qtyInput.value) || 0;
            const errorDiv = document.getElementById(`error-${productId}`);
            const availableStock = getAvailableStock(productId);

            if (quantity <= 0) {
                errorDiv.textContent = 'Please enter a quantity';
                return;
            }

            if (quantity > availableStock) {
                errorDiv.textContent = `Only ${availableStock} available`;
                return;
            }

            const existingItem = cart.find(item => item.id === productId);
            
            if (existingItem) {
                const newTotal = existingItem.quantity + quantity;
                if (newTotal > product.stock) {
                    errorDiv.textContent = `Cannot add more than ${product.stock} total`;
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

            qtyInput.value = '0';
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

            if (cart.length === 0) {
                cartItemsDiv.innerHTML = '<p class="text-white-50 text-center">No items in cart</p>';
                subtotalDiv.textContent = '₱0.00';
                totalItemsDiv.textContent = '0';
                totalPriceDiv.textContent = '₱0.00';
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

            subtotalDiv.textContent = `₱${subtotal.toFixed(2)}`;
            totalItemsDiv.textContent = totalItems;
            totalPriceDiv.textContent = `₱${subtotal.toFixed(2)}`;
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
            const newCustomer = document.getElementById('newCustomerName').value.trim();
            const email = document.getElementById('customerEmail').value.trim();
            const phone = document.getElementById('customerPhone').value.trim();
            const address = document.getElementById('customerAddress').value.trim();
            
            const selectedCustomer = customerSelect.options[customerSelect.selectedIndex];
            const customerName = selectedCustomer.value ? selectedCustomer.text : newCustomer;

            if (!customerSelect.value && !newCustomer) {
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

            document.getElementById('reviewCustomer').textContent = customerName;
            document.getElementById('reviewEmail').textContent = email;
            document.getElementById('reviewPhone').textContent = phone;
            document.getElementById('reviewAddress').textContent = address;

            const subtotal = cart.reduce((sum, item) => sum + (item.price * item.quantity), 0);
            document.getElementById('reviewSubtotal').textContent = `₱${subtotal.toFixed(2)}`;
            document.getElementById('reviewTotal').textContent = `₱${subtotal.toFixed(2)}`;
        }

        // Submit order - FIXED VERSION
        function submitOrder() {
            const customerSelect = document.getElementById('customerSelect');
            const customer_id = customerSelect.value ? parseInt(customerSelect.value) : 0;
            const customer_name = document.getElementById('newCustomerName').value.trim();
            const email = document.getElementById('customerEmail').value.trim();
            const phone = document.getElementById('customerPhone').value.trim();
            const address = document.getElementById('customerAddress').value.trim();
            
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
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<i class="bi bi-hourglass-split"></i> Processing...';
            submitBtn.disabled = true;
            
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
    
    // Check if response is empty or contains HTML error
    if (!text || text.trim() === '') {
        throw new Error('Empty response from server');
    }
    
    // If response starts with '<', it's probably an HTML error page
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
        
        document.getElementById('successSoNumber').textContent = data.so_number;
        document.getElementById('successOrderDate').textContent = new Date().toLocaleDateString();
        const successModal = new bootstrap.Modal(document.getElementById('successModal'));
        successModal.show();
        
        setTimeout(() => {
            document.getElementById('customerSelect').value = '';
            document.getElementById('newCustomerName').value = '';
            document.getElementById('customerEmail').value = '';
            document.getElementById('customerPhone').value = '';
            document.getElementById('customerAddress').value = '';
        }, 500);
        
    } else {
        showToast('Error: ' + (data.message || 'Failed to submit order'));
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
    }
})
.catch(error => {
    console.error('Error:', error.message);
    showToast('Error: ' + error.message);
    submitBtn.innerHTML = originalText;
    submitBtn.disabled = false;
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

        // Initialize on page load
        window.addEventListener('DOMContentLoaded', init);
    </script>
</body>
</html>