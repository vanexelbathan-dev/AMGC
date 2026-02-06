<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Current Inventory - Warehouse</title>
    <link rel="stylesheet" href="../css/style.css">
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
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
                <h3><i class="bi bi-building logo-icon"></i> <span class="nav-text">Warehouse</span></h3>
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
                        <a class="nav-link" href="trip_tickets.php">
                            <i class="bi bi-ticket"></i>
                            <span class="nav-text">Trip Tickets</span>
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
        </div>

        <!-- Main Content Area -->
        <div class="main-content">
            <!-- Header Section with User Info and Logout -->
            <div class="navbar-top">
                <div class="page-title">
                    <h2><i class="bi bi-boxes me-2"></i>Current Inventory</h2>
                    <p>Manage and add items to warehouse inventory</p>
                </div>
                
                <div class="user-info-top">
                    <div class="user-profile-top">
                        <div class="user-avatar-top" id="userAvatar">WM</div>
                        <div class="user-details-top">
                            <span class="user-name-top" id="userName">Warehouse Manager</span>
                            <span class="user-role-top" id="userRole">Warehouse</span>
                        </div>
                    </div>
                    
                    <button class="logout-btn-top" onclick="logout()">
                        <i class="bi bi-box-arrow-right"></i> Logout
                    </button>
                </div>
            </div>

            <!-- Inventory Stats -->
            <div class="row g-3 mb-4">

                <!-- Total Items -->
                <div class="col-md-3 mb-3">
                    <div class="stat-card inventory">
                        <div class="stat-icon">
                            <i class="bi bi-boxes"></i>
                        </div>
                        <div>
                            <div class="stat-value">2,450</div>
                            <div class="stat-label">Total Items</div>
                        </div>
                    </div>
                </div>

                <!-- Low Stock Items -->
                <div class="col-md-3 mb-3">
                    <div class="stat-card alert">
                        <div class="stat-icon">
                            <i class="bi bi-exclamation-triangle"></i>
                        </div>
                        <div>
                            <div class="stat-value">8</div>
                            <div class="stat-label">Low Stock Items</div>
                        </div>
                    </div>
                </div>

                <!-- Total Value -->
                <div class="col-md-3 mb-3">
                    <div class="stat-card stock">
                        <div class="stat-icon">
                            <i class="bi bi-graph-up"></i>
                        </div>
                        <div>
                            <div class="stat-value">$145K</div>
                            <div class="stat-label">Inventory Value</div>
                        </div>
                    </div>
                </div>

                <!-- Warehouse Capacity -->
                <div class="col-md-3 mb-3">
                    <div class="stat-card capacity">
                        <div class="stat-icon">
                            <i class="bi bi-percent"></i>
                        </div>
                        <div>
                            <div class="stat-value">87%</div>
                            <div class="stat-label">Capacity Used</div>
                        </div>
                    </div>
                </div>

            </div>

            <!-- Search and Filter with Add Button -->
            <div class="card mb-4">
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="input-group">
                                <span class="input-group-text">
                                    <i class="bi bi-search"></i>
                                </span>
                                <input type="text" class="form-control" id="searchInput" placeholder="Search by item name or SKU...">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <select class="form-select" id="categoryFilter">
                                <option value="">All Categories</option>
                                <option value="Electronics">Electronics</option>
                                <option value="Tools">Tools</option>
                                <option value="Hardware">Hardware</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <button class="btn btn-primary w-100" data-bs-toggle="modal" data-bs-target="#addInventoryModal">
                                <i class="bi bi-plus-lg"></i> Add Item
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Inventory Table -->
            <div class="card">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Item ID</th>
                                <th>Item Name</th>
                                <th>SKU</th>
                                <th>Category</th>
                                <th>Quantity</th>
                                <th>Unit Price</th>
                                <th>Total Value</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><span class="badge bg-light text-dark">INV-001</span></td>
                                <td>Widget A</td>
                                <td>WGT-A-100</td>
                                <td>Electronics</td>
                                <td>150</td>
                                <td>$45.00</td>
                                <td>$6,750.00</td>
                                <td><span class="badge bg-success">In Stock</span></td>
                                <td>
                                    <button class="btn btn-sm btn-warning" data-bs-toggle="modal" data-bs-target="#editInventoryModal" onclick="loadEditForm('INV-001', 'Widget A', 'WGT-A-100', 'Electronics', 150, 45)">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                </td>
                            </tr>
                            <tr>
                                <td><span class="badge bg-light text-dark">INV-002</span></td>
                                <td>Gadget B</td>
                                <td>GDG-B-200</td>
                                <td>Tools</td>
                                <td>45</td>
                                <td>$65.00</td>
                                <td>$2,925.00</td>
                                <td><span class="badge bg-warning">Low Stock</span></td>
                                <td>
                                    <button class="btn btn-sm btn-warning" data-bs-toggle="modal" data-bs-target="#editInventoryModal" onclick="loadEditForm('INV-002', 'Gadget B', 'GDG-B-200', 'Tools', 45, 65)">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                </td>
                            </tr>
                            <tr>
                                <td><span class="badge bg-light text-dark">INV-003</span></td>
                                <td>Device C</td>
                                <td>DEV-C-300</td>
                                <td>Hardware</td>
                                <td>320</td>
                                <td>$28.50</td>
                                <td>$9,120.00</td>
                                <td><span class="badge bg-success">In Stock</span></td>
                                <td>
                                    <button class="btn btn-sm btn-warning" data-bs-toggle="modal" data-bs-target="#editInventoryModal" onclick="loadEditForm('INV-003', 'Device C', 'DEV-C-300', 'Hardware', 320, 28.50)">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                </td>
                            </tr>
                            <tr>
                                <td><span class="badge bg-light text-dark">INV-004</span></td>
                                <td>Tool D</td>
                                <td>TOL-D-400</td>
                                <td>Tools</td>
                                <td>85</td>
                                <td>$75.00</td>
                                <td>$6,375.00</td>
                                <td><span class="badge bg-success">In Stock</span></td>
                                <td>
                                    <button class="btn btn-sm btn-warning" data-bs-toggle="modal" data-bs-target="#editInventoryModal" onclick="loadEditForm('INV-004', 'Tool D', 'TOL-D-400', 'Tools', 85, 75)">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                </td>
                            </tr>
                            <tr>
                                <td><span class="badge bg-light text-dark">INV-005</span></td>
                                <td>Component E</td>
                                <td>CMP-E-500</td>
                                <td>Electronics</td>
                                <td>12</td>
                                <td>$120.00</td>
                                <td>$1,440.00</td>
                                <td><span class="badge bg-danger">Critical</span></td>
                                <td>
                                    <button class="btn btn-sm btn-warning" data-bs-toggle="modal" data-bs-target="#editInventoryModal" onclick="loadEditForm('INV-005', 'Component E', 'CMP-E-500', 'Electronics', 12, 120)">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                </td>
                            </tr>
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
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Item Name</label>
                                <input type="text" class="form-control" id="itemName" required placeholder="Item name">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">SKU</label>
                                <input type="text" class="form-control" id="itemSku" required placeholder="e.g., WGT-A-100">
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Category</label>
                                <select class="form-select" id="itemCategory" required>
                                    <option value="">Select Category</option>
                                    <option value="Electronics">Electronics</option>
                                    <option value="Tools">Tools</option>
                                    <option value="Hardware">Hardware</option>
                                    <option value="Other">Other</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Quantity</label>
                                <input type="number" class="form-control" id="itemQuantity" required placeholder="0" min="0">
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Unit Price</label>
                                <input type="number" class="form-control" id="itemPrice" required placeholder="0.00" min="0" step="0.01">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Minimum Stock Level</label>
                                <input type="number" class="form-control" id="minStock" required placeholder="0" min="0">
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="addNewItem()">Add Item</button>
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
                <div class="modal-body">
                    <form id="editInventoryForm">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Item Name</label>
                                <input type="text" class="form-control" id="editItemName" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">SKU</label>
                                <input type="text" class="form-control" id="editItemSku" required disabled>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Category</label>
                                <select class="form-select" id="editItemCategory" required>
                                    <option value="">Select Category</option>
                                    <option value="Electronics">Electronics</option>
                                    <option value="Tools">Tools</option>
                                    <option value="Hardware">Hardware</option>
                                    <option value="Other">Other</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Quantity</label>
                                <input type="number" class="form-control" id="editItemQuantity" required min="0">
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Unit Price</label>
                                <input type="number" class="form-control" id="editItemPrice" required min="0" step="0.01">
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

    <!-- JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Mobile menu toggle
        document.getElementById('mobileMenuBtn').addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('show');
        });

        // Add new item
        function addNewItem() {
            const name = document.getElementById('itemName').value;
            const sku = document.getElementById('itemSku').value;
            const category = document.getElementById('itemCategory').value;
            const quantity = document.getElementById('itemQuantity').value;
            const price = parseFloat(document.getElementById('itemPrice').value);

            if (!name || !sku || !category || !quantity || !price) {
                alert('Please fill in all required fields');
                return;
            }

            // Generate item ID
            const itemId = 'INV-' + String(Math.floor(Math.random() * 1000)).padStart(3, '0');
            const totalValue = (quantity * price).toFixed(2);
            const status = quantity > 50 ? 'In Stock' : (quantity > 0 ? 'Low Stock' : 'Out of Stock');
            let statusBadge = 'bg-success';
            if (quantity <= 0) statusBadge = 'bg-danger';
            else if (quantity <= 50) statusBadge = 'bg-warning';

            // Add row to table
            const table = document.querySelector('tbody');
            const newRow = table.insertRow(0);
            
            newRow.innerHTML = `
                <td><span class="badge bg-light text-dark">${itemId}</span></td>
                <td>${name}</td>
                <td>${sku}</td>
                <td>${category}</td>
                <td>${quantity}</td>
                <td>$${price.toFixed(2)}</td>
                <td>$${totalValue}</td>
                <td><span class="badge ${statusBadge}">${status}</span></td>
                <td>
                    <button class="btn btn-sm btn-warning" data-bs-toggle="modal" data-bs-target="#editInventoryModal" onclick="loadEditForm('${itemId}', '${name}', '${sku}', '${category}', ${quantity}, ${price})">
                        <i class="bi bi-pencil"></i>
                    </button>
                </td>
            `;

            // Reset form and close modal
            document.getElementById('addInventoryForm').reset();
            bootstrap.Modal.getInstance(document.getElementById('addInventoryModal')).hide();
            alert(`Item ${itemId} - ${name} has been added successfully!`);
        }

        // Load edit form
        function loadEditForm(itemId, name, sku, category, quantity, price) {
            document.getElementById('editItemName').value = name;
            document.getElementById('editItemSku').value = sku;
            document.getElementById('editItemCategory').value = category;
            document.getElementById('editItemQuantity').value = quantity;
            document.getElementById('editItemPrice').value = price;
        }

        // Update item
        function updateItem() {
            alert('Item has been updated successfully!');
            bootstrap.Modal.getInstance(document.getElementById('editInventoryModal')).hide();
        }

        // Search functionality
        document.getElementById('searchInput').addEventListener('keyup', function() {
            const filter = this.value.toLowerCase();
            const rows = document.querySelectorAll('tbody tr');
            
            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(filter) ? '' : 'none';
            });
        });

        // Category filter
        document.getElementById('categoryFilter').addEventListener('change', function() {
            const filter = this.value.toLowerCase();
            const rows = document.querySelectorAll('tbody tr');
            
            rows.forEach(row => {
                const category = row.cells[3].textContent.toLowerCase();
                row.style.display = (filter === '' || category.includes(filter)) ? '' : 'none';
            });
        });

        // Logout function
        function logout() {
            if (confirm('Are you sure you want to logout?')) {
                window.location.href = '../login.php';
            }
        }
    </script>
</body>
</html>
