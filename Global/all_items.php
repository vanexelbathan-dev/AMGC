<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Global - All Items Catalog</title>
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
                        <a class="nav-link" href="sales_reports.php">
                            <i class="bi bi-graph-up"></i>
                            <span class="nav-text">Sales Reports</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="branch_records.php">
                            <i class="bi bi-file-text"></i>
                            <span class="nav-text">Branch Records</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="all_items.php">
                            <i class="bi bi-box"></i>
                            <span class="nav-text">All Items</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="drivers.php">
                            <i class="bi bi-person-badge"></i>
                            <span class="nav-text">Drivers</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="trip_tickets.php">
                            <i class="bi bi-ticket-perforated"></i>
                            <span class="nav-text">Trip Tickets</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="driver_tracking.php">
                            <i class="bi bi-geo-alt"></i>
                            <span class="nav-text">Driver Tracking</span>
                        </a>
                    </li>
                </ul>
            </div>
        </div>

        <!-- Main Content -->
        <div class="main-content" id="mainContent">
            <!-- ALL ITEMS PAGE -->
            <div id="itemsContent" class="page-content active">
                <div class="navbar-top">
                    <div class="page-title">
                        <h2><i class="bi bi-box me-2"></i>All Items Catalog</h2>
                        <p>View all items across the system, including out-of-stock items</p>
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
                                <span class="user-role-top">Global Admin</span>
                            </div>
                        </div>
                        
                        <button class="logout-btn-top" onclick="logout()">
                            <i class="bi bi-box-arrow-right"></i> Logout
                        </button>
                    </div>
                </div>

                <div class="row g-3 mb-4">
                    <div class="col-md-4">
                        <div class="stat-card total">
                            <div class="stat-value" id="totalItems">0</div>
                            <div class="stat-label">Total Items</div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="stat-card sales">
                            <div class="stat-value" id="inStockItems">0</div>
                            <div class="stat-label">In Stock</div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="stat-card complete">
                            <div class="stat-value" id="outOfStockItems">0</div>
                            <div class="stat-label">Out of Stock</div>
                        </div>
                    </div>
                </div>

                <div class="row g-3 mb-4">
                    <div class="col-12">
                        <div class="form-card">
                            <div class="d-flex justify-content-between align-items-center">
                                <h5 class="mb-0">Filter Items</h5>
                            </div>
                            <div class="row mt-3">
                                <div class="col-md-3">
                                    <label class="form-label">Category</label>
                                    <select class="form-select" id="categoryFilter" onchange="loadItems()">
                                        <option value="">All Categories</option>
                                        <option value="electronics">Electronics</option>
                                        <option value="groceries">Groceries</option>
                                        <option value="hardware">Hardware</option>
                                        <option value="other">Other</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Stock Status</label>
                                    <select class="form-select" id="stockFilter" onchange="loadItems()">
                                        <option value="">All Items</option>
                                        <option value="in_stock">In Stock</option>
                                        <option value="low_stock">Low Stock</option>
                                        <option value="out_of_stock">Out of Stock</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Price Range</label>
                                    <select class="form-select" id="priceFilter" onchange="loadItems()">
                                        <option value="">All Prices</option>
                                        <option value="0-50">$0 - $50</option>
                                        <option value="50-100">$50 - $100</option>
                                        <option value="100-500">$100 - $500</option>
                                        <option value="500+">$500+</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Location</label>
                                    <select class="form-select" id="locationFilter" onchange="loadItems()">
                                        <option value="">All Locations</option>
                                        <option value="warehouse">Warehouse</option>
                                        <option value="branch1">Branch 1</option>
                                        <option value="branch2">Branch 2</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="data-table">
                    <div class="table-header">
                        <h5>Complete Items Catalog</h5>
                    </div>
                    <div class="table-responsive">
                        <table class="table custom-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Item Name</th>
                                    <th>Category</th>
                                    <th>Unit Price</th>
                                    <th>Total Quantity</th>
                                    <th>Available</th>
                                    <th>Stock Status</th>
                                    <th>Primary Location</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="itemsTable">
                                <tr>
                                    <td colspan="9" class="text-center py-4">Loading items...</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Item Details Modal -->
    <div class="modal fade" id="itemModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Item Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="itemDetails">
                    <!-- Details will be populated here -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <style>
        /* Mobile responsive adjustments ONLY */
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
            .col-md-4 {
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
            
            .col-md-4 {
                width: 50%;
                padding-left: 6px;
                padding-right: 6px;
            }
            
            .row.g-3 {
                margin-left: -6px;
                margin-right: -6px;
            }
        }
    </style>

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

        // Load items
        async function loadItems() {
            try {
                const category = document.getElementById('categoryFilter').value;
                const stock = document.getElementById('stockFilter').value;
                const price = document.getElementById('priceFilter').value;
                const location = document.getElementById('locationFilter').value;

                const params = new URLSearchParams({
                    category: category,
                    stock: stock,
                    price: price,
                    location: location
                });

                const response = await fetch('api/get_all_items.php?' + params);
                const data = await response.json();
                
                if (data.success) {
                    displayItems(data.items || []);
                    updateItemStats(data.stats || {});
                } else {
                    console.log('No items found');
                    displayItems([]);
                }
            } catch (error) {
                console.error('Error loading items:', error);
            }
        }

        function displayItems(items) {
            const tbody = document.getElementById('itemsTable');
            
            if (items.length === 0) {
                tbody.innerHTML = '<tr><td colspan="9" class="text-center py-4">No items found</td></tr>';
                return;
            }

            tbody.innerHTML = items.map(item => {
                let statusBadge = 'bg-success';
                if (item.available === 0) statusBadge = 'bg-danger';
                else if (item.available < 10) statusBadge = 'bg-warning';

                return `
                <tr>
                    <td>${item.id}</td>
                    <td><strong>${item.item_name}</strong></td>
                    <td>${item.category}</td>
                    <td>$${item.unit_price.toLocaleString()}</td>
                    <td>${item.total_quantity}</td>
                    <td>${item.available}</td>
                    <td>
                        <span class="badge ${statusBadge}">
                            ${item.available === 0 ? 'Out of Stock' : item.available < 10 ? 'Low Stock' : 'In Stock'}
                        </span>
                    </td>
                    <td>${item.primary_location}</td>
                    <td>
                        <button class="btn btn-sm btn-info" onclick="viewItem(${item.id})">
                            <i class="bi bi-eye"></i> View
                        </button>
                    </td>
                </tr>
            `;
            }).join('');
        }

        function updateItemStats(stats) {
            document.getElementById('totalItems').textContent = stats.totalItems || 0;
            document.getElementById('inStockItems').textContent = stats.inStockItems || 0;
            document.getElementById('outOfStockItems').textContent = stats.outOfStockItems || 0;
        }

        function viewItem(id) {
            const modal = new bootstrap.Modal(document.getElementById('itemModal'));
            const details = document.getElementById('itemDetails');
            details.innerHTML = '<p>Loading item details...</p>';
            modal.show();
            
            fetch('api/get_item_details.php?id=' + id)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const item = data.item;
                        details.innerHTML = `
                            <dl class="row">
                                <dt class="col-sm-4">Item ID:</dt>
                                <dd class="col-sm-8">${item.id}</dd>
                                <dt class="col-sm-4">Item Name:</dt>
                                <dd class="col-sm-8">${item.item_name}</dd>
                                <dt class="col-sm-4">Category:</dt>
                                <dd class="col-sm-8">${item.category}</dd>
                                <dt class="col-sm-4">Unit Price:</dt>
                                <dd class="col-sm-8">$${item.unit_price.toLocaleString()}</dd>
                                <dt class="col-sm-4">Total Quantity:</dt>
                                <dd class="col-sm-8">${item.total_quantity}</dd>
                                <dt class="col-sm-4">Available:</dt>
                                <dd class="col-sm-8">${item.available}</dd>
                                <dt class="col-sm-4">Supplier:</dt>
                                <dd class="col-sm-8">${item.supplier || 'N/A'}</dd>
                                <dt class="col-sm-4">Primary Location:</dt>
                                <dd class="col-sm-8">${item.primary_location}</dd>
                            </dl>
                        `;
                    }
                })
                .catch(error => console.error('Error loading item details:', error));
        }

        // Search items
        document.getElementById('searchItems').addEventListener('keyup', async function(e) {
            const searchTerm = e.target.value.toLowerCase();
            try {
                const response = await fetch(`api/search_items.php?q=${encodeURIComponent(searchTerm)}`);
                const data = await response.json();
                displayItems(data.items || []);
            } catch (error) {
                console.error('Error searching items:', error);
            }
        });

        // Load items on page load
        loadItems();
    </script>
</body>
</html>