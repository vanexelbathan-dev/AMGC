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
    <style>
    /* Mobile responsive adjustments ONLY - same as warehouse.php */
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
            .col-md-3 {
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
            
            .col-md-3 {
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
                    <p>Manage and view warehouse inventory</p>
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

            <?php
            require_once '../config/database.php';
            
            // Get inventory statistics
            $stats = [];
            
            // Total Items - Count of distinct items from items table
            $total_items_query = "SELECT COUNT(*) as total_items FROM items WHERE status = 'active'";
            $result = $conn->query($total_items_query);
            $stats['total_items'] = $result->fetch_assoc()['total_items'] ?? 0;
            
            // Current Stock - SUM of stock column from items table
            $current_stock_query = "SELECT SUM(stock) as current_stock FROM items WHERE status = 'active'";
            $result = $conn->query($current_stock_query);
            $stats['current_stock'] = $result->fetch_assoc()['current_stock'] ?? 0;
            
            // Low Stock Items (based on items table stock)
            $low_stock_query = "SELECT COUNT(*) as count FROM items 
                               WHERE stock <= reorder_level AND status = 'active'";
            $result = $conn->query($low_stock_query);
            $stats['low_stock'] = $result->fetch_assoc()['count'] ?? 0;
            ?>

            <!-- Inventory Stats - UPDATED: Removed Inventory Value card -->
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
                        <div class="col-md-6 col-12">
                            <div class="input-group">
                                <span class="input-group-text">
                                    <i class="bi bi-search"></i>
                                </span>
                                <input type="text" class="form-control" id="searchInput" placeholder="Search by item name or SKU...">
                            </div>
                        </div>
                        <div class="col-md-4 col-12">
                            <select class="form-select" id="categoryFilter">
                                <option value="">All Categories</option>
                                <?php
                                $categories_query = "SELECT DISTINCT category FROM items WHERE category IS NOT NULL AND category != ''";
                                $result = $conn->query($categories_query);
                                while($row = $result->fetch_assoc()) {
                                    echo '<option value="' . $row['category'] . '">' . $row['category'] . '</option>';
                                }
                                ?>
                            </select>
                        </div>
                        <div class="col-md-2 col-12">
                            <button class="btn btn-primary w-100" data-bs-toggle="modal" data-bs-target="#addInventoryModal">
                                <i class="bi bi-plus-lg"></i> Add Item
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Inventory Table - UPDATED: Removed Unit Price column -->
            <div class="card">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Item Code</th>
                                <th>Item Name</th>
                                <th>Category</th>
                                <th>Total Stock</th>
                                <th>Reorder Level</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $inventory_query = "SELECT i.item_code, i.item_name, i.category, i.stock, 
                                               i.reorder_level, i.status, i.item_id
                                               FROM items i
                                               WHERE i.status = 'active'
                                               ORDER BY i.item_name";
                            $result = $conn->query($inventory_query);
                            
                            if ($result->num_rows > 0) {
                                while($row = $result->fetch_assoc()) {
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
                                        <td><span class="badge bg-light text-dark"><?php echo $row['item_code']; ?></span></td>
                                        <td><?php echo $row['item_name']; ?></td>
                                        <td><?php echo $row['category'] ?? 'N/A'; ?></td>
                                        <td><?php echo number_format($row['stock']); ?></td>
                                        <td><?php echo number_format($row['reorder_level']); ?></td>
                                        <td><span class="badge <?php echo $status_badge; ?>"><?php echo $status_text; ?></span></td>
                                        <td>
                                            <button class="btn btn-sm btn-info" data-bs-toggle="modal" data-bs-target="#viewItemModal" onclick="loadItemDetails(<?php echo $row['item_id']; ?>)">
                                                <i class="bi bi-eye"></i> View
                                            </button>
                                            <button class="btn btn-sm btn-warning" data-bs-toggle="modal" data-bs-target="#editInventoryModal" onclick="loadItemForEdit('<?php echo $row['item_code']; ?>')">
                                                <i class="bi bi-pencil"></i> Edit
                                            </button>
                                        </td>
                                    </tr>
                                    <?php
                                }
                            } else {
                                echo '<tr><td colspan="7" class="text-center">No inventory items found</td></tr>';
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Inventory Modal - UPDATED: Removed Unit Price field -->
    <div class="modal fade" id="addInventoryModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Add New Inventory Item</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="addInventoryForm" action="add_inventory.php" method="POST">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Item Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="item_name" required placeholder="Item name">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Item Code <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="item_code" required placeholder="e.g., ITEM-001">
                                <small class="text-muted">Must be unique</small>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Category <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="category" required placeholder="Category">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Initial Stock <span class="text-danger">*</span></label>
                                <input type="number" class="form-control" name="stock" required placeholder="0" min="0" value="0">
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Reorder Level <span class="text-danger">*</span></label>
                                <input type="number" class="form-control" name="reorder_level" required placeholder="0" min="0" value="50">
                            </div>
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
                            <textarea class="form-control" name="description" placeholder="Item description" rows="3"></textarea>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" form="addInventoryForm" class="btn btn-primary">Add Item</button>
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
                    <!-- Content will be loaded by JavaScript from get_item_details.php -->
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
                    <!-- Content will be loaded by JavaScript from get_item_details.php -->
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
                const category = row.cells[2].textContent.toLowerCase();
                row.style.display = (filter === '' || category.includes(filter)) ? '' : 'none';
            });
        });

        // Load item details for viewing
        function loadItemDetails(itemId) {
            fetch('get_item_details.php?action=view&item_id=' + itemId)
                .then(response => response.text())
                .then(data => {
                    document.getElementById('itemDetailsContent').innerHTML = data;
                })
                .catch(error => {
                    console.error('Error:', error);
                    document.getElementById('itemDetailsContent').innerHTML = '<div class="alert alert-danger">Failed to load item details</div>';
                });
        }

        // Load item data for editing
        function loadItemForEdit(itemCode) {
            fetch('get_item_details.php?action=edit&item_code=' + encodeURIComponent(itemCode))
                .then(response => response.text())
                .then(data => {
                    document.getElementById('editInventoryFormContent').innerHTML = data;
                })
                .catch(error => {
                    console.error('Error:', error);
                    document.getElementById('editInventoryFormContent').innerHTML = '<div class="alert alert-danger">Failed to load item details</div>';
                });
        }

        // Logout function
        function logout() {
            if (confirm('Are you sure you want to logout?')) {
                window.location.href = '../login.php';
            }
        }
    </script>
</body>
</html>