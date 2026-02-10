<!DOCTYPE html>
<?php
require_once '../config/database.php';
require_once '../config/session_handler.php';

// Protect page - only Sales role can access
requireLogin();
requireRole(['sales']);

// Get all items
$items_result = $conn->query("SELECT i.*, SUM(inv.quantity_available) as stock
                              FROM items i
                              LEFT JOIN inventory inv ON i.item_id = inv.item_id
                              WHERE i.status = 'active'
                              GROUP BY i.item_id
                              ORDER BY i.item_code ASC");
$items = [];
if ($items_result) {
    $items = $items_result->fetch_all(MYSQLI_ASSOC);
}

// Get all customers for dropdown
$customers_result = $conn->query("SELECT customer_id, customer_name FROM customers WHERE status = 'active' ORDER BY customer_name ASC");
$customers = [];
if ($customers_result) {
    $customers = $customers_result->fetch_all(MYSQLI_ASSOC);
}

// Handle order submission via AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_order') {
    $customer_id = isset($_POST['customer_id']) ? (int)$_POST['customer_id'] : 0;
    $customer_name = isset($_POST['customer_name']) ? trim($_POST['customer_name']) : '';
    $email = isset($_POST['email']) ? trim($_POST['email']) : '';
    $phone = isset($_POST['phone']) ? trim($_POST['phone']) : '';
    $address = isset($_POST['address']) ? trim($_POST['address']) : '';
    $items_data = isset($_POST['items']) ? json_decode($_POST['items'], true) : [];
    
    if (!empty($items_data)) {
        // Create sales order
        $total_amount = 0;
        foreach ($items_data as $item) {
            $total_amount += $item['price'] * $item['quantity'];
        }
        
        $so_number = 'SO-' . date('Ymd') . '-' . time();
        $order_date = date('Y-m-d H:i:s');
        $user_id = getUserId();
        $branch_id = 1; // Default branch
        
        $sql = "INSERT INTO sales_orders (so_number, customer_id, branch_id, order_date, total_amount, order_status, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $status = 'pending';
        $stmt->bind_param('siiidss', $so_number, $customer_id, $branch_id, $order_date, $total_amount, $status, $user_id);
        
        if ($stmt->execute()) {
            $so_id = $stmt->insert_id;
            
            // Insert order items
            $sql_items = "INSERT INTO sales_order_items (so_id, item_id, quantity_ordered, unit_price)
                         VALUES (?, ?, ?, ?)";
            $stmt_items = $conn->prepare($sql_items);
            
            foreach ($items_data as $item) {
                $item_id = $item['id'];
                $quantity = $item['quantity'];
                $unit_price = $item['price'];
                $stmt_items->bind_param('iiid', $so_id, $item_id, $quantity, $unit_price);
                $stmt_items->execute();
            }
            
            echo json_encode(['success' => true, 'message' => 'Order submitted successfully!', 'so_number' => $so_number]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Error creating order']);
        }
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
                        <a class="nav-link active" href="orderproduct.php">
                            <i class="bi bi-bag"></i>
                            <span class="nav-text">Order Product</span>
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
                                            <option value="<?php echo $customer['customer_id']; ?>"><?php echo htmlspecialchars($customer['customer_name']); ?></option>
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
                                <span id="subtotal">$0.00</span>
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
                            <h3 id="totalPrice" class="mb-0">$0.00</h3>
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
                            <span id="reviewSubtotal">$0.00</span>
                        </div>
                        <div class="d-flex justify-content-between">
                            <strong>Total:</strong>
                            <strong id="reviewTotal" class="text-success">$0.00</strong>
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

    <!-- JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Inventory data from database
        const inventory = <?php echo json_encode(array_map(function($item) {
            return [
                'id' => $item['item_id'],
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
            // Update cart on load
            updateCart();
        }

        // Render product cards with plus/minus buttons
        function renderProducts() {
            const container = document.getElementById('productsContainer');
            container.innerHTML = inventory.map(product => {
                const lowStock = product.stock < 10;
                const outOfStock = product.stock === 0;
                
                return `
                <div class="col-md-6 product-card-mobile">
                    <div class="card h-100">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <h6 class="card-title mb-0">${product.name}</h6>
                                <span class="badge bg-light text-dark">${product.sku}</span>
                            </div>
                            <p class="text-muted small mb-1 product-stock-mobile">
                                Stock: <strong class="${lowStock ? 'text-danger' : ''}">${product.stock} units</strong>
                                ${lowStock && !outOfStock ? '<span class="stock-warning"> - Low Stock</span>' : ''}
                            </p>
                            ${outOfStock ? '<div class="stock-warning mb-1">Out of Stock</div>' : ''}
                            <p class="h5 text-success mb-3 product-price-mobile">$${product.price.toFixed(2)}</p>
                            
                            <div class="product-input-group-mobile">
                                <div class="quantity-control">
                                    <button type="button" class="decrease-btn" onclick="decreaseQuantity(${product.id})" ${outOfStock ? 'disabled' : ''}>
                                        <i class="bi bi-dash-lg"></i>
                                    </button>
                                    <input type="number" class="form-control" id="qty-${product.id}" 
                                           min="0" max="${product.stock}" value="0" 
                                           onchange="validateQuantity(${product.id})"
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
            const product = inventory.find(p => p.id === productId);
            let currentValue = parseInt(qtyInput.value) || 0;
            if (currentValue < product.stock) {
                qtyInput.value = currentValue + 1;
                validateQuantity(productId);
            }
        }

        // Validate quantity input and update button state
        function validateQuantity(productId) {
            const qtyInput = document.getElementById(`qty-${productId}`);
            const addButton = document.getElementById(`btn-add-${productId}`);
            const product = inventory.find(p => p.id === productId);
            const errorDiv = document.getElementById(`error-${productId}`);
            
            let value = parseInt(qtyInput.value) || 0;
            
            if (value < 0) {
                value = 0;
                qtyInput.value = 0;
            }
            
            if (value > product.stock) {
                value = product.stock;
                qtyInput.value = product.stock;
                errorDiv.textContent = `Max ${product.stock} units`;
                addButton.disabled = false;
                return value;
            } else {
                errorDiv.textContent = '';
            }
            
            // Check available stock considering items already in cart
            const cartItem = cart.find(item => item.id === productId);
            const alreadyInCart = cartItem ? cartItem.quantity : 0;
            const availableStock = product.stock - alreadyInCart;
            
            if (value > availableStock) {
                errorDiv.textContent = `Only ${availableStock} more available`;
                addButton.disabled = true;
            } else if (value === 0) {
                addButton.disabled = true;
            } else {
                addButton.disabled = false;
            }
            
            return value;
        }

        // Add to cart with validation
        function addToCart(productId) {
            const product = inventory.find(p => p.id === productId);
            const quantity = validateQuantity(productId);
            const errorDiv = document.getElementById(`error-${productId}`);
            const addButton = document.getElementById(`btn-add-${productId}`);

            if (quantity <= 0) {
                errorDiv.textContent = 'Please enter a quantity';
                return;
            }

            // Check available stock considering items already in cart
            const cartItem = cart.find(item => item.id === productId);
            const alreadyInCart = cartItem ? cartItem.quantity : 0;
            const availableStock = product.stock - alreadyInCart;

            if (quantity > availableStock) {
                errorDiv.textContent = `Only ${availableStock} more available`;
                addButton.disabled = true;
                return;
            }

            if (cartItem) {
                cartItem.quantity += quantity;
            } else {
                cart.push({ ...product, quantity });
            }

            // Reset quantity input and disable button
            document.getElementById(`qty-${productId}`).value = '0';
            addButton.disabled = true;
            updateCart();
            
            // Show success feedback
            showToast(`${quantity} × ${product.name} added to cart!`);
        }

        // Show toast notification
        function showToast(message) {
            // Remove existing toast if any
            const existingToast = document.querySelector('.toast-notification');
            if (existingToast) {
                existingToast.remove();
            }
            
            const toast = document.createElement('div');
            toast.className = 'toast-notification';
            toast.style.cssText = `
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
            `;
            
            toast.innerHTML = `
                <div class="d-flex align-items-center">
                    <i class="bi bi-check-circle-fill me-2"></i>
                    <span>${message}</span>
                </div>
            `;
            
            document.body.appendChild(toast);
            
            // Auto remove after 3 seconds
            setTimeout(() => {
                toast.style.animation = 'slideOut 0.3s ease-out';
                setTimeout(() => toast.remove(), 300);
            }, 3000);
            
            // Add CSS animations
            if (!document.querySelector('#toast-styles')) {
                const style = document.createElement('style');
                style.id = 'toast-styles';
                style.textContent = `
                    @keyframes slideIn {
                        from { transform: translateX(100%); opacity: 0; }
                        to { transform: translateX(0); opacity: 1; }
                    }
                    @keyframes slideOut {
                        from { transform: translateX(0); opacity: 1; }
                        to { transform: translateX(100%); opacity: 0; }
                    }
                `;
                document.head.appendChild(style);
            }
        }

        // Update cart display
        function updateCart() {
            const cartItemsDiv = document.getElementById('cartItems');
            const subtotalDiv = document.getElementById('subtotal');
            const totalItemsDiv = document.getElementById('totalItems');
            const totalPriceDiv = document.getElementById('totalPrice');

            if (cart.length === 0) {
                cartItemsDiv.innerHTML = '<p class="text-white-50 text-center">No items in cart</p>';
                subtotalDiv.textContent = '$0.00';
                totalItemsDiv.textContent = '0';
                totalPriceDiv.textContent = '$0.00';
                return;
            }

            cartItemsDiv.innerHTML = cart.map(item => {
                const product = inventory.find(p => p.id === item.id);
                const remainingStock = product.stock - item.quantity;
                const lowStockWarning = remainingStock < 10 ? `<div class="text-warning small mt-1">${remainingStock} left in stock</div>` : '';
                
                return `
                <div class="cart-item">
                    <div style="flex: 1;">
                        <div class="text-black-50 small">${item.name}</div>
                        <div class="text-black-50 small">$${item.price.toFixed(2)} × ${item.quantity}</div>
                        ${lowStockWarning}
                    </div>
                    <div class="text-end">
                        <div class="text-black fw-bold">$${(item.price * item.quantity).toFixed(2)}</div>
                        <button class="btn btn-sm btn-outline-light mt-1" onclick="removeFromCart(${item.id})">
                            <i class="bi bi-trash"></i>
                        </button>
                    </div>
                </div>
                `;
            }).join('');

            const subtotal = cart.reduce((sum, item) => sum + (item.price * item.quantity), 0);
            const totalItems = cart.reduce((sum, item) => sum + item.quantity, 0);

            subtotalDiv.textContent = `$${subtotal.toFixed(2)}`;
            totalItemsDiv.textContent = totalItems;
            totalPriceDiv.textContent = `$${subtotal.toFixed(2)}`;
            
            // Update all quantity inputs to reflect new stock limits
            cart.forEach(item => {
                validateQuantity(item.id);
            });
        }

        // Remove from cart
        function removeFromCart(productId) {
            cart = cart.filter(item => item.id !== productId);
            updateCart();
            showToast('Item removed from cart');
            // Re-enable the add button for this product
            validateQuantity(productId);
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
                // Reset all quantity inputs and re-enable buttons
                inventory.forEach(product => {
                    document.getElementById(`qty-${product.id}`).value = '0';
                    validateQuantity(product.id);
                });
                showToast('Cart cleared');
            }
        }

        // View cart and confirm
        function viewCart() {
            if (cart.length === 0) {
                showToast('Please add items to cart first');
                return;
            }

            const customer = document.getElementById('customerSelect').value;
            const newCustomer = document.getElementById('newCustomerName').value;
            const email = document.getElementById('customerEmail').value;
            const phone = document.getElementById('customerPhone').value;
            const address = document.getElementById('customerAddress').value;

            if (!customer && !newCustomer) {
                showToast('Please select or enter a customer');
                return;
            }

            if (!email || !phone || !address) {
                showToast('Please fill in all customer information');
                return;
            }

            // Populate review
            const reviewItems = document.getElementById('reviewItems');
            reviewItems.innerHTML = `
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead class="table-light">
                            <tr>
                                <th>Product</th>
                                <th>Price</th>
                                <th>Qty</th>
                                <th>Total</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${cart.map(item => {
                                const product = inventory.find(p => p.id === item.id);
                                const remainingStock = product.stock - item.quantity;
                                return `
                                <tr>
                                    <td>
                                        ${item.name}
                                        ${remainingStock < 10 ? 
                                            `<br><small class="text-warning">${remainingStock} left in stock</small>` : ''}
                                    </td>
                                    <td>$${item.price.toFixed(2)}</td>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <button class="btn btn-sm btn-outline-secondary" onclick="updateCartQuantity(${item.id}, -1)">
                                                <i class="bi bi-dash"></i>
                                            </button>
                                            <input type="number" class="form-control form-control-sm mx-2" 
                                                   style="width: 60px;" min="1" max="${product.stock}" 
                                                   value="${item.quantity}" 
                                                   onchange="updateCartQuantity(${item.id}, 0, this.value)">
                                            <button class="btn btn-sm btn-outline-secondary" onclick="updateCartQuantity(${item.id}, 1)">
                                                <i class="bi bi-plus"></i>
                                            </button>
                                        </div>
                                    </td>
                                    <td>$${(item.price * item.quantity).toFixed(2)}</td>
                                    <td>
                                        <button class="btn btn-sm btn-danger" onclick="removeFromCartReview(${item.id})">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                                `;
                            }).join('')}
                        </tbody>
                    </table>
                </div>
            `;

            document.getElementById('reviewCustomer').textContent = newCustomer || 'Selected Customer';
            document.getElementById('reviewEmail').textContent = email;
            document.getElementById('reviewPhone').textContent = phone;
            document.getElementById('reviewAddress').textContent = address;

            const subtotal = cart.reduce((sum, item) => sum + (item.price * item.quantity), 0);
            document.getElementById('reviewSubtotal').textContent = `$${subtotal.toFixed(2)}`;
            document.getElementById('reviewTotal').textContent = `$${subtotal.toFixed(2)}`;

            const modal = new bootstrap.Modal(document.getElementById('cartModal'));
            modal.show();
        }

        // Update cart quantity in review modal
        function updateCartQuantity(productId, change, directValue = null) {
            const item = cart.find(i => i.id === productId);
            const product = inventory.find(p => p.id === productId);
            
            if (item) {
                let newQuantity;
                
                if (directValue !== null) {
                    newQuantity = parseInt(directValue) || 1;
                } else {
                    newQuantity = item.quantity + change;
                }
                
                if (newQuantity < 1) {
                    removeFromCart(productId);
                    viewCart();
                    return;
                }
                
                // Check stock limit
                if (newQuantity > product.stock) {
                    showToast(`Only ${product.stock} units available`);
                    return;
                }
                
                item.quantity = newQuantity;
                updateCart();
                const subtotal = cart.reduce((sum, item) => sum + (item.price * item.quantity), 0);
                document.getElementById('reviewSubtotal').textContent = `$${subtotal.toFixed(2)}`;
                document.getElementById('reviewTotal').textContent = `$${subtotal.toFixed(2)}`;
            }
        }

        // Remove from cart in review modal
        function removeFromCartReview(productId) {
            removeFromCart(productId);
            // Refresh review items
            viewCart();
        }

        // Submit order
        function submitOrder() {
            const customerSelect = document.getElementById('customerSelect');
            const customer_id = customerSelect.value || 0;
            const customer_name = document.getElementById('newCustomerName').value;
            const email = document.getElementById('customerEmail').value;
            const phone = document.getElementById('customerPhone').value;
            const address = document.getElementById('customerAddress').value;
            
            const formData = new FormData();
            formData.append('action', 'submit_order');
            formData.append('customer_id', customer_id);
            formData.append('customer_name', customer_name);
            formData.append('email', email);
            formData.append('phone', phone);
            formData.append('address', address);
            formData.append('items', JSON.stringify(cart));
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast(`Order ${data.so_number} submitted successfully!`);
                    
                    // Clear cart and form
                    cart = [];
                    updateCart();
                    document.getElementById('customerSelect').value = '';
                    document.getElementById('newCustomerName').value = '';
                    document.getElementById('customerEmail').value = '';
                    document.getElementById('customerPhone').value = '';
                    document.getElementById('customerAddress').value = '';
                    
                    // Re-render products
                    renderProducts();
                    
                    // Close modal
                    const modal = bootstrap.Modal.getInstance(document.getElementById('cartModal'));
                    modal.hide();
                } else {
                    showToast('Error: ' + (data.message || 'Failed to submit order'));
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showToast('Error submitting order');
            });
        }

        // Initialize on page load
        window.addEventListener('DOMContentLoaded', init);
    </script>
</body>
</html>
