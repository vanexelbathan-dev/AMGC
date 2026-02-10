<?php
require_once '../config/database.php';
require_once '../config/session_handler.php';

// Protect page - only Sales role can access
requireLogin();
requireRole(['sales']);

// Get all items with inventory
$items = [];
$query = "SELECT i.*, SUM(inv.quantity_available) as total_stock
          FROM items i
          LEFT JOIN inventory inv ON i.item_id = inv.item_id
          WHERE i.status = 'active'
          GROUP BY i.item_id
          ORDER BY i.item_code ASC";
$result = $conn->query($query);
if ($result) {
    $items = $result->fetch_all(MYSQLI_ASSOC);
}

// Get stats
$total_products = 0;
$in_stock = 0;
$low_stock = 0;
$critical_stock = 0;

$stats_query = "SELECT 
                COUNT(DISTINCT i.item_id) as total,
                SUM(CASE WHEN inv.quantity_available > i.reorder_level THEN 1 ELSE 0 END) as in_stock,
                SUM(CASE WHEN inv.quantity_available <= i.reorder_level AND inv.quantity_available > 5 THEN 1 ELSE 0 END) as low_stock,
                SUM(CASE WHEN inv.quantity_available <= 5 THEN 1 ELSE 0 END) as critical
                FROM items i
                LEFT JOIN inventory inv ON i.item_id = inv.item_id
                WHERE i.status = 'active'";
$stats_result = $conn->query($stats_query);
if ($stats_result) {
    $stats = $stats_result->fetch_assoc();
    $total_products = $stats['total'] ?? 0;
    $in_stock = $stats['in_stock'] ?? 0;
    $low_stock = $stats['low_stock'] ?? 0;
    $critical_stock = $stats['critical'] ?? 0;
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

<!-- Inventory Stats -->
<div class="row g-3 mb-4">

    <!-- Total Products -->
    <div class="col-md-3 mb-3">
        <div class="stat-card inventory">
            <div class="stat-icon">
                <i class="bi bi-box"></i>
            </div>
            <div>
                <div class="stat-value"><?php echo $total_products; ?></div>
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
                <div class="stat-value"><?php echo $in_stock; ?></div>
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
                <div class="stat-value"><?php echo $low_stock; ?></div>
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
                <div class="stat-value"><?php echo $critical_stock; ?></div>
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
                            <?php if (count($items) > 0): ?>
                                <?php foreach ($items as $item): ?>
                                    <?php
                                        $stock = $item['total_stock'] ?? 0;
                                        $unit_price = $item['unit_price'];
                                        $total_value = $stock * $unit_price;
                                        $reorder_level = $item['reorder_level'];
                                        
                                        if ($stock <= 5) {
                                            $stock_badge = 'bg-danger';
                                            $status_badge = 'bg-danger';
                                            $status_text = 'Critical Stock';
                                        } elseif ($stock <= $reorder_level) {
                                            $stock_badge = 'bg-warning';
                                            $status_badge = 'bg-warning';
                                            $status_text = 'Low Stock';
                                        } else {
                                            $stock_badge = 'bg-success';
                                            $status_badge = 'bg-success';
                                            $status_text = 'In Stock';
                                        }
                                    ?>
                                <tr>
                                    <td><span class="badge bg-light text-dark"><?php echo htmlspecialchars($item['item_code']); ?></span></td>
                                    <td><?php echo htmlspecialchars($item['item_name']); ?></td>
                                    <td><?php echo htmlspecialchars($item['category'] ?? 'N/A'); ?></td>
                                    <td>
                                        <span class="badge <?php echo $stock_badge; ?>"><?php echo $stock; ?> units</span>
                                    </td>
                                    <td>$<?php echo number_format($unit_price, 2); ?></td>
                                    <td>$<?php echo number_format($total_value, 2); ?></td>
                                    <td><span class="badge <?php echo $status_badge; ?>"><?php echo $status_text; ?></span></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" class="text-center text-muted py-4">No products found</td>
                                </tr>
                            <?php endif; ?>
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


    </script>
</body>
</html>
