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
                            <tr>
                                <td><span class="badge bg-light text-dark">SKU-001</span></td>
                                <td>Laptop Computer</td>
                                <td>Electronics</td>
                                <td>
                                    <span class="badge bg-success">45 units</span>
                                </td>
                                <td>$899.99</td>
                                <td>$40,499.55</td>
                                <td><span class="badge bg-success">In Stock</span></td>
                            </tr>
                            <tr>
                                <td><span class="badge bg-light text-dark">SKU-002</span></td>
                                <td>Office Chair</td>
                                <td>Furniture</td>
                                <td>
                                    <span class="badge bg-warning">8 units</span>
                                </td>
                                <td>$249.99</td>
                                <td>$1,999.92</td>
                                <td><span class="badge bg-warning">Low Stock</span></td>
                            </tr>
                            <tr>
                                <td><span class="badge bg-light text-dark">SKU-003</span></td>
                                <td>Desk Lamp</td>
                                <td>Electronics</td>
                                <td>
                                    <span class="badge bg-success">120 units</span>
                                </td>
                                <td>$34.99</td>
                                <td>$4,198.80</td>
                                <td><span class="badge bg-success">In Stock</span></td>
                            </tr>
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