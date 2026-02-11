<?php
require_once '../config/database.php';
require_once '../config/session_handler.php';
require_once '../config/template_helper.php';

// Protect page - only Sales role can access
requireLogin();
requireRole(['sales']);

// Get inventory for sales view (all available inventory across branches or just this branch)
$query = "SELECT inv.*, i.item_code, i.item_name, i.category, i.unit_price, i.reorder_level
          FROM inventory inv
          JOIN items i ON inv.item_id = i.item_id
          WHERE i.status = 'active' AND inv.quantity_available > 0
          ORDER BY i.item_name ASC";

$stmt = $conn->prepare($query);
if (!$stmt) {
    die("Database Error: " . $conn->error);
}

if (!$stmt->execute()) {
    die("Query Error: " . $stmt->error);
}

$result = $stmt->get_result();
$inventory_items = [];
if ($result) {
    $inventory_items = $result->fetch_all(MYSQLI_ASSOC);
}

// Calculate stats
$total_items = count($inventory_items);
$total_qty_available = 0;
$total_value = 0;

foreach ($inventory_items as $item) {
    $qty_available = $item['quantity_on_hand'] - $item['quantity_reserved'];
    $total_qty_available += $qty_available;
    $total_value += ($qty_available * $item['unit_price']);
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Current Inventory - Sales</title>
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
                <h3><i class="bi bi-shop logo-icon"></i> <span class="nav-text">Sales</span></h3>
            </div>
            
            <div class="sidebar-menu">
                <ul class="nav flex-column">
                    <li class="nav-item">
                        <a class="nav-link active" href="currentinventory.php">
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
                    <h2><i class="bi bi-boxes me-2"></i>Current Inventory</h2>
                    <p>View all available products in inventory</p>
                </div>
                
                <div class="user-info-top">
                    <div class="user-profile-top">
                        <div class="user-avatar-top"><?php echo substr(getUserName(), 0, 2); ?></div>
                        <div class="user-details-top">
                            <span class="user-name-top"><?php echo getUserName(); ?></span>
                            <span class="user-role-top"><?php echo ucfirst(str_replace('_', ' ', getUserRole())); ?></span>
                        </div>
                    </div>
                    
                    <button class="logout-btn-top" onclick="window.location.href='../logout.php'">
                        <i class="bi bi-box-arrow-right"></i> Logout
                    </button>
                </div>
            </div>

<!-- Inventory Stats -->
<div class="row g-3 mb-4">

    <!-- Total Products -->
    <div class="col-md-3 mb-3">
        <div class="stat-card inventory">
            <div class="stat-icon">
                <i class="bi bi-box"></i>
            </div>
            <div>
                <div class="stat-value">547</div>
                <div class="stat-label">Total Products</div>
            </div>
        </div>
    </div>

    <!-- In Stock -->
    <div class="col-md-3 mb-3">
        <div class="stat-card complete">
            <div class="stat-icon">
                <i class="bi bi-check-circle"></i>
            </div>
            <div>
                <div class="stat-value">521</div>
                <div class="stat-label">In Stock</div>
            </div>
        </div>
    </div>

    <!-- Low Stock -->
    <div class="col-md-3 mb-3">
        <div class="stat-card pending">
            <div class="stat-icon">
                <i class="bi bi-exclamation-circle"></i>
            </div>
            <div>
                <div class="stat-value">18</div>
                <div class="stat-label">Low Stock</div>
            </div>
        </div>
    </div>

    <!-- Critical Stock -->
    <div class="col-md-3 mb-3">
        <div class="stat-card sales">
            <div class="stat-icon">
                <i class="bi bi-exclamation-triangle"></i>
            </div>
            <div>
                <div class="stat-value">8</div>
                <div class="stat-label">Critical Stock</div>
            </div>
        </div>
    </div>

</div>


            <!-- Search and Filter -->
            <div class="card mb-4">
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-8">
                            <div class="input-group">
                                <span class="input-group-text">
                                    <i class="bi bi-search"></i>
                                </span>
                                <input type="text" class="form-control" id="searchInput" placeholder="Search products by name, SKU, or category...">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <select class="form-select" id="categoryFilter">
                                <option value="">All Categories</option>
                                <option value="Electronics">Electronics</option>
                                <option value="Furniture">Furniture</option>
                                <option value="Clothing">Clothing</option>
                                <option value="Food">Food & Beverage</option>
                            </select>
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
                                <th>Product SKU</th>
                                <th>Product Name</th>
                                <th>Category</th>
                                <th>Current Stock</th>
                                <th>Unit Price</th>
                                <th>Total Value</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($inventory_items) > 0): ?>
                                <?php foreach ($inventory_items as $item): ?>
                                    <?php 
                                        $qty_available = $item['quantity_on_hand'] - $item['quantity_reserved'];
                                        $line_value = $qty_available * $item['unit_price'];
                                        $status_badge = ($qty_available < $item['reorder_level']) ? 'bg-warning' : 'bg-success';
                                        $status_text = ($qty_available < $item['reorder_level']) ? 'Low Stock' : 'In Stock';
                                    ?>
                                    <tr>
                                        <td><span class="badge bg-light text-dark"><?php echo htmlspecialchars($item['item_code']); ?></span></td>
                                        <td><?php echo htmlspecialchars($item['item_name']); ?></td>
                                        <td><?php echo htmlspecialchars($item['category'] ?? 'Uncategorized'); ?></td>
                                        <td>
                                            <span class="badge bg-info"><?php echo $qty_available; ?> units</span>
                                        </td>
                                        <td>$<?php echo number_format($item['unit_price'], 2); ?></td>
                                        <td>$<?php echo number_format($line_value, 2); ?></td>
                                        <td><span class="badge <?php echo $status_badge; ?>"><?php echo $status_text; ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" class="text-center text-muted py-4">No inventory items available</td>
                                </tr>
                            <?php endif; ?>
                            <tr>
                                <td><span class="badge bg-light text-dark">SKU-004</span></td>
                                <td>Wireless Mouse</td>
                                <td>Electronics</td>
                                <td>
                                    <span class="badge bg-danger">2 units</span>
                                </td>
                                <td>$19.99</td>
                                <td>$39.98</td>
                                <td><span class="badge bg-danger">Critical Stock</span></td>
                            </tr>
                            <tr>
                                <td><span class="badge bg-light text-dark">SKU-005</span></td>
                                <td>USB-C Cable</td>
                                <td>Electronics</td>
                                <td>
                                    <span class="badge bg-success">256 units</span>
                                </td>
                                <td>$9.99</td>
                                <td>$2,557.44</td>
                                <td><span class="badge bg-success">In Stock</span></td>
                            </tr>
                            <tr>
                                <td><span class="badge bg-light text-dark">SKU-006</span></td>
                                <td>Desk Organizer</td>
                                <td>Furniture</td>
                                <td>
                                    <span class="badge bg-success">75 units</span>
                                </td>
                                <td>$29.99</td>
                                <td>$2,249.25</td>
                                <td><span class="badge bg-success">In Stock</span></td>
                            </tr>
                            <tr>
                                <td><span class="badge bg-light text-dark">SKU-007</span></td>
                                <td>Notebook Set</td>
                                <td>Food</td>
                                <td>
                                    <span class="badge bg-warning">5 units</span>
                                </td>
                                <td>$12.99</td>
                                <td>$64.95</td>
                                <td><span class="badge bg-warning">Low Stock</span></td>
                            </tr>
                            <tr>
                                <td><span class="badge bg-light text-dark">SKU-008</span></td>
                                <td>Coffee Maker</td>
                                <td>Electronics</td>
                                <td>
                                    <span class="badge bg-success">18 units</span>
                                </td>
                                <td>$79.99</td>
                                <td>$1,439.82</td>
                                <td><span class="badge bg-success">In Stock</span></td>
                            </tr>
                        </tbody>
                    </table>
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

        // Logout function
        function logout() {
            if (confirm('Are you sure you want to logout?')) {
                window.location.href = '../login.php';
            }
        }
    </script>
</body>
</html>
