<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Global - Items Management</title>
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
                <h3><i class="bi bi-globe logo-icon"></i> <span class="nav-text">Global</span></h3>
            </div>
            
            <div class="sidebar-menu">
                <ul class="nav flex-column">
                    <li class="nav-item">
                        <a class="nav-link active" href="items.php">
                            <i class="bi bi-box"></i>
                            <span class="nav-text">Items</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="vendors.php">
                            <i class="bi bi-shop"></i>
                            <span class="nav-text">Vendors</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="company.php">
                            <i class="bi bi-building"></i>
                            <span class="nav-text">Company</span>
                        </a>
                    </li>

                    <hr class="sidebar-divider">

                    <li class="nav-item">
                        <a class="nav-link" href="#">
                            <i class="bi bi-info-circle"></i>
                            <span class="nav-text">About</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="#">
                            <i class="bi bi-chat-left-text"></i>
                            <span class="nav-text">Feedback</span>
                        </a>
                    </li>
                </ul>
            </div>
        </div>

        <!-- Main Content -->
        <div class="main-content" id="mainContent">
            <!-- ITEMS PAGE -->
            <div id="itemsContent" class="page-content active">
                <div class="navbar-top">
                    <div class="page-title">
                        <h2><i class="bi bi-box me-2"></i>Items Management</h2>
                        <p>Manage global inventory items and products</p>
                    </div>
                    
                    <div class="user-info-top">
                        <div class="search-box">
                            <i class="bi bi-search"></i>
                            <input type="text" class="form-control" placeholder="Search items..." id="searchItems">
                        </div>
                        
                        <div class="user-profile-top">
                            <div class="user-avatar-top">AD</div>
                            <div class="user-details-top">
                                <span class="user-name-top">Admin User</span>
                                <span class="user-role-top">Administrator</span>
                            </div>
                        </div>
                        
                        <button class="logout-btn-top" onclick="logout()">
                            <i class="bi bi-box-arrow-right"></i> Logout
                        </button>
                    </div>
                </div>

                <!-- Demo Mode Info -->
                <div class="demo-info-card mb-4">
                    <div class="demo-info-icon">
                        <i class="bi bi-info-circle"></i>
                    </div>
                    <div class="demo-info-content">
                        <h5>Items Management</h5>
                        <p class="mb-0">Manage global items and products that are used across all branches and operations.</p>
                    </div>
                </div>

                <div class="row g-3 mb-4">
                    <div class="col-12">
                        <div class="form-card">
                            <div class="d-flex justify-content-between align-items-center">
                                <h5 class="mb-0">Item Management</h5>
                                <button class="btn btn-primary" id="addItemBtn">
                                    <i class="bi bi-plus-circle me-1"></i> Add New Item
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row g-3 mb-4">
                    <div class="col-md-3">
                        <div class="stat-card total">
                            <div class="stat-value" id="totalItems">0</div>
                            <div class="stat-label">Total Items</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card sales">
                            <div class="stat-value" id="activeItems">0</div>
                            <div class="stat-label">Active Items</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card complete">
                            <div class="stat-value" id="lowStockItems">0</div>
                            <div class="stat-label">Low Stock Items</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card pending">
                            <div class="stat-value" id="inventoryValue">₱ 0.00</div>
                            <div class="stat-label">Total Inventory Value</div>
                        </div>
                    </div>
                </div>

                <div class="data-table">
                    <div class="table-header">
                        <h5>Items List</h5>
                    </div>
                    <div class="table-responsive">
                        <table class="table custom-table">
                            <thead>
                                <tr>
                                    <th>Item Code</th>
                                    <th>Item Name</th>
                                    <th>Description</th>
                                    <th>Category</th>
                                    <th>Unit Price</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="itemsTable">
                                <tr>
                                    <td colspan="7" class="text-center py-4">Loading items...</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal for Add/Edit Item -->
    <div class="modal fade" id="itemModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="itemModalLabel">Add Item</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="itemForm">
                        <div class="mb-3">
                            <label for="itemCode" class="form-label">Item Code</label>
                            <input type="text" class="form-control" id="itemCode" required>
                        </div>
                        <div class="mb-3">
                            <label for="itemName" class="form-label">Item Name</label>
                            <input type="text" class="form-control" id="itemName" required>
                        </div>
                        <div class="mb-3">
                            <label for="itemDescription" class="form-label">Description</label>
                            <textarea class="form-control" id="itemDescription" rows="3"></textarea>
                        </div>
                        <div class="mb-3">
                            <label for="itemCategory" class="form-label">Category</label>
                            <input type="text" class="form-control" id="itemCategory">
                        </div>
                        <div class="mb-3">
                            <label for="itemPrice" class="form-label">Unit Price</label>
                            <input type="number" class="form-control" id="itemPrice" step="0.01">
                        </div>
                        <div class="mb-3">
                            <label for="itemStatus" class="form-label">Status</label>
                            <select class="form-select" id="itemStatus">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
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

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Mobile menu toggle
        document.getElementById('mobileMenuBtn').addEventListener('click', function() {
            const sidebar = document.getElementById('sidebar');
            sidebar.classList.toggle('show');
        });

        // Logout function
        function logout() {
            alert('Logging out...');
            window.location.href = '../login.php';
        }

        // Load items from database
        async function loadItems() {
            try {
                const response = await fetch('api/get_items.php');
                const data = await response.json();
                
                if (data.success) {
                    const items = data.data || [];
                    displayItems(items);
                    updateStats(items);
                } else {
                    console.log('No items found or error occurred');
                    displayItems([]);
                }
            } catch (error) {
                console.error('Error loading items:', error);
                displayItems([]);
            }
        }

        // Display items in table
        function displayItems(items) {
            const tbody = document.getElementById('itemsTable');
            
            if (items.length === 0) {
                tbody.innerHTML = '<tr><td colspan="7" class="text-center py-4">No items found</td></tr>';
                return;
            }

            tbody.innerHTML = items.map(item => `
                <tr>
                    <td>${item.item_code || '-'}</td>
                    <td>${item.item_name || '-'}</td>
                    <td>${item.description || '-'}</td>
                    <td>${item.category || '-'}</td>
                    <td>₱ ${parseFloat(item.unit_price || 0).toFixed(2)}</td>
                    <td><span class="badge ${item.status === 'active' ? 'badge-success' : 'badge-warning'}">${item.status || 'active'}</span></td>
                    <td>
                        <button class="btn btn-sm btn-info" onclick="editItem(${item.id})">
                            <i class="bi bi-pencil"></i> Edit
                        </button>
                        <button class="btn btn-sm btn-danger" onclick="deleteItem(${item.id})">
                            <i class="bi bi-trash"></i> Delete
                        </button>
                    </td>
                </tr>
            `).join('');
        }

        // Update statistics
        function updateStats(items) {
            document.getElementById('totalItems').textContent = items.length;
            const activeItems = items.filter(i => i.status === 'active' || !i.status).length;
            document.getElementById('activeItems').textContent = activeItems;
            document.getElementById('lowStockItems').textContent = items.length > 0 ? Math.floor(items.length * 0.1) : 0;
            const totalValue = items.reduce((sum, item) => sum + (parseFloat(item.unit_price || 0) * 10), 0);
            document.getElementById('inventoryValue').textContent = '₱ ' + totalValue.toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        }

        // Add new item button
        document.getElementById('addItemBtn').addEventListener('click', function() {
            document.getElementById('itemForm').reset();
            document.getElementById('itemModalLabel').textContent = 'Add Item';
            new bootstrap.Modal(document.getElementById('itemModal')).show();
        });

        // Save item
        async function saveItem() {
            const itemCode = document.getElementById('itemCode').value;
            const itemName = document.getElementById('itemName').value;
            const itemDescription = document.getElementById('itemDescription').value;
            const itemCategory = document.getElementById('itemCategory').value;
            const itemPrice = document.getElementById('itemPrice').value;
            const itemStatus = document.getElementById('itemStatus').value;

            if (!itemCode.trim() || !itemName.trim()) {
                alert('Please enter item code and name');
                return;
            }

            try {
                const response = await fetch('api/save_item.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        item_code: itemCode,
                        item_name: itemName,
                        description: itemDescription,
                        category: itemCategory,
                        unit_price: itemPrice,
                        status: itemStatus
                    })
                });

                const data = await response.json();
                if (data.success) {
                    alert('Item saved successfully');
                    bootstrap.Modal.getInstance(document.getElementById('itemModal')).hide();
                    loadItems();
                } else {
                    alert('Error saving item: ' + (data.message || 'Unknown error'));
                }
            } catch (error) {
                console.error('Error saving item:', error);
                alert('Error saving item');
            }
        }

        // Edit item
        async function editItem(id) {
            try {
                const response = await fetch(`api/get_item.php?id=${id}`);
                const data = await response.json();
                
                if (data.success) {
                    const item = data.data;
                    document.getElementById('itemCode').value = item.item_code || '';
                    document.getElementById('itemName').value = item.item_name || '';
                    document.getElementById('itemDescription').value = item.description || '';
                    document.getElementById('itemCategory').value = item.category || '';
                    document.getElementById('itemPrice').value = item.unit_price || '';
                    document.getElementById('itemStatus').value = item.status || 'active';
                    document.getElementById('itemModalLabel').textContent = 'Edit Item';
                    new bootstrap.Modal(document.getElementById('itemModal')).show();
                }
            } catch (error) {
                console.error('Error loading item:', error);
            }
        }

        // Delete item
        async function deleteItem(id) {
            if (!confirm('Are you sure you want to delete this item?')) return;

            try {
                const response = await fetch('api/delete_item.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({ id: id })
                });

                const data = await response.json();
                if (data.success) {
                    alert('Item deleted successfully');
                    loadItems();
                } else {
                    alert('Error deleting item');
                }
            } catch (error) {
                console.error('Error deleting item:', error);
                alert('Error deleting item');
            }
        }

        // Search items
        document.getElementById('searchItems').addEventListener('keyup', async function(e) {
            const searchTerm = e.target.value.toLowerCase();
            try {
                const response = await fetch(`api/search_items.php?q=${encodeURIComponent(searchTerm)}`);
                const data = await response.json();
                displayItems(data.data || []);
            } catch (error) {
                console.error('Error searching items:', error);
            }
        });

        // Load items on page load
        loadItems();
    </script>
</body>
</html>
