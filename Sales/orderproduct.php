<!DOCTYPE html>
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
        
        .qty-input {
            width: 80px;
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
                        <div class="user-avatar-top" id="userAvatar">AD</div>
                        <div class="user-details-top">
                            <span class="user-name-top" id="userName">Admin User</span>
                            <span class="user-role-top" id="userRole">Administrator</span>
                        </div>
                    </div>
                    
                    <button class="logout-btn-top" onclick="logout()">
                        <i class="bi bi-box-arrow-right"></i> Logout
                    </button>
                </div>
            </div>

            <div class="row">
                <!-- Products Section -->
                <div class="col-lg-8">
                    <!-- Header Section -->

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
                                        <option value="1">John Doe</option>
                                        <option value="2">Jane Smith</option>
                                        <option value="3">ABC Corporation</option>
                                        <option value="4">XYZ Limited</option>
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
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Edit Order</button>
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
        // Sample inventory data - includes all products
        const inventory = [
            { id: 1, name: 'Laptop Computer', sku: 'SKU-001', price: 899.99, stock: 45 },
            { id: 2, name: 'Office Chair', sku: 'SKU-002', price: 249.99, stock: 8 },
            { id: 3, name: 'Desk Lamp', sku: 'SKU-003', price: 34.99, stock: 120 },
            { id: 4, name: 'Wireless Mouse', sku: 'SKU-004', price: 19.99, stock: 2 },
            { id: 5, name: 'USB-C Cable', sku: 'SKU-005', price: 9.99, stock: 256 },
            { id: 6, name: 'Desk Organizer', sku: 'SKU-006', price: 29.99, stock: 75 },
            { id: 7, name: 'Notebook Set', sku: 'SKU-007', price: 12.99, stock: 5 },
            { id: 8, name: 'Coffee Maker', sku: 'SKU-008', price: 79.99, stock: 18 },
        ];

        let cart = [];

        // Initialize page
        function init() {
            renderProducts();
            document.getElementById('mobileMenuBtn').addEventListener('click', function() {
                document.getElementById('sidebar').classList.toggle('show');
            });
        }

        // Render product cards
        function renderProducts() {
            const container = document.getElementById('productsContainer');
            container.innerHTML = inventory.map(product => `
                <div class="col-md-6">
                    <div class="card h-100">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <h6 class="card-title mb-0">${product.name}</h6>
                                <span class="badge bg-light text-dark">${product.sku}</span>
                            </div>
                            <p class="text-muted small mb-2">Stock: <strong>${product.stock} units</strong></p>
                            <p class="h5 text-success mb-3">$${product.price.toFixed(2)}</p>
                            
                            <div class="input-group input-group-sm">
                                <input type="number" class="form-control" id="qty-${product.id}" min="0" max="${product.stock}" value="0" placeholder="Qty">
                                <button class="btn btn-outline-success btn-sm" type="button" onclick="addToCart(${product.id})">
                                    <i class="bi bi-plus-lg"></i> Add
                                </button>
                            </div>
                            <div class="error-message" id="error-${product.id}"></div>
                        </div>
                    </div>
                </div>
            `).join('');
        }

        // Add to cart with validation
        function addToCart(productId) {
            const product = inventory.find(p => p.id === productId);
            const qtyInput = document.getElementById(`qty-${productId}`);
            const quantity = parseInt(qtyInput.value);
            const errorDiv = document.getElementById(`error-${productId}`);

            errorDiv.textContent = '';

            if (quantity <= 0) {
                errorDiv.textContent = 'Please enter a quantity';
                return;
            }

            if (quantity > product.stock) {
                errorDiv.textContent = `Only ${product.stock} units available`;
                return;
            }

            const existingItem = cart.find(item => item.id === productId);
            if (existingItem) {
                existingItem.quantity += quantity;
            } else {
                cart.push({ ...product, quantity });
            }

            qtyInput.value = '0';
            updateCart();
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

            cartItemsDiv.innerHTML = cart.map(item => `
                <div class="cart-item">
                    <div style="flex: 1;">
                        <div class="text-black-50 small">${item.name}</div>
                        <div class="text-black-50 small">$${item.price.toFixed(2)} × ${item.quantity}</div>
                    </div>
                    <div class="text-end">
                        <div class="text-black fw-bold">$${(item.price * item.quantity).toFixed(2)}</div>
                        <button class="btn btn-sm btn-outline-light mt-1" onclick="removeFromCart(${item.id})">
                            <i class="bi bi-trash"></i>
                        </button>
                    </div>
                </div>
            `).join('');

            const subtotal = cart.reduce((sum, item) => sum + (item.price * item.quantity), 0);
            const totalItems = cart.reduce((sum, item) => sum + item.quantity, 0);

            subtotalDiv.textContent = `$${subtotal.toFixed(2)}`;
            totalItemsDiv.textContent = totalItems;
            totalPriceDiv.textContent = `$${subtotal.toFixed(2)}`;
        }

        // Remove from cart
        function removeFromCart(productId) {
            cart = cart.filter(item => item.id !== productId);
            updateCart();
        }

        // Clear cart
        function clearCart() {
            if (confirm('Clear all items from cart?')) {
                cart = [];
                updateCart();
            }
        }

        // View cart and confirm
        function viewCart() {
            if (cart.length === 0) {
                alert('Please add items to cart first');
                return;
            }

            const customer = document.getElementById('customerSelect').value;
            const newCustomer = document.getElementById('newCustomerName').value;
            const email = document.getElementById('customerEmail').value;
            const phone = document.getElementById('customerPhone').value;
            const address = document.getElementById('customerAddress').value;

            if (!customer && !newCustomer) {
                alert('Please select or enter a customer');
                return;
            }

            if (!email || !phone || !address) {
                alert('Please fill in all customer information');
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
                            ${cart.map(item => `
                                <tr>
                                    <td>${item.name}</td>
                                    <td>$${item.price.toFixed(2)}</td>
                                    <td>
                                        <input type="number" class="form-control form-control-sm" style="width: 60px;" min="1" value="${item.quantity}" onchange="updateItemQuantity(${item.id}, this.value)">
                                    </td>
                                    <td>$${(item.price * item.quantity).toFixed(2)}</td>
                                    <td>
                                        <button class="btn btn-sm btn-danger" onclick="removeFromCart(${item.id})">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                            `).join('')}
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

        // Update item quantity in review
        function updateItemQuantity(productId, newQuantity) {
            const item = cart.find(i => i.id === productId);
            if (item) {
                item.quantity = parseInt(newQuantity);
                const subtotal = cart.reduce((sum, item) => sum + (item.price * item.quantity), 0);
                document.getElementById('reviewSubtotal').textContent = `$${subtotal.toFixed(2)}`;
                document.getElementById('reviewTotal').textContent = `$${subtotal.toFixed(2)}`;
            }
        }

        // Submit order
        function submitOrder() {
            alert('Order submitted successfully!\n\nOrder ID: ORD-' + Math.random().toString(36).substr(2, 9).toUpperCase());
            cart = [];
            updateCart();
            document.getElementById('customerSelect').value = '';
            document.getElementById('newCustomerName').value = '';
            document.getElementById('customerEmail').value = '';
            document.getElementById('customerPhone').value = '';
            document.getElementById('customerAddress').value = '';
            bootstrap.Modal.getInstance(document.getElementById('cartModal')).hide();
        }

        // Initialize on page load
        window.addEventListener('DOMContentLoaded', init);

        // Logout function
        function logout() {
            if (confirm('Are you sure you want to logout?')) {
                window.location.href = '../login.php';
            }
        }
    </script>
</body>
</html>
