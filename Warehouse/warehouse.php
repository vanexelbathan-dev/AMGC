<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Warehouse Manager</title>
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
                        <a class="nav-link active" href="warehouse.php">
                            <i class="bi bi-speedometer2"></i>
                            <span class="nav-text">Dashboard</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="currentinventory.php">
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
                    <h2><i class="bi bi-speedometer2 me-2"></i>Warehouse Dashboard</h2>
                    <p>Monitor inventory, shipments, and delivery operations</p>
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

<!-- Warehouse Stats -->
<div class="row g-3 mb-4">

    <!-- Total Inventory Items -->
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

    <!-- Pending Deliveries -->
    <div class="col-md-3 mb-3">
        <div class="stat-card pending">
            <div class="stat-icon">
                <i class="bi bi-truck"></i>
            </div>
            <div>
                <div class="stat-value">18</div>
                <div class="stat-label">Pending Deliveries</div>
            </div>
        </div>
    </div>

    <!-- Active Drivers -->
    <div class="col-md-3 mb-3">
        <div class="stat-card delivery">
            <div class="stat-icon">
                <i class="bi bi-person-badge"></i>
            </div>
            <div>
                <div class="stat-value">12</div>
                <div class="stat-label">Active Drivers</div>
            </div>
        </div>
    </div>

    <!-- Warehouse Capacity -->
    <div class="col-md-3 mb-3">
        <div class="stat-card sales">
            <div class="stat-icon">
                <i class="bi bi-graph-up"></i>
            </div>
            <div>
                <div class="stat-value">88%</div>
                <div class="stat-label">Warehouse Capacity</div>
            </div>
        </div>
    </div>

</div>


            <!-- Recent Activities -->
            <div class="row">
                <!-- Recent Pick List Items -->
                <div class="col-lg-6 mb-4">
                    <div class="card">
                        <div class="card-header bg-primary text-white">
                            <h5 class="mb-0"><i class="bi bi-clipboard-check me-2"></i>Recent Pick List Items</h5>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-sm mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Item ID</th>
                                        <th>Product</th>
                                        <th>Quantity</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td>PLI-001</td>
                                        <td>Widget A</td>
                                        <td>50</td>
                                        <td><span class="badge bg-success">Picked</span></td>
                                    </tr>
                                    <tr>
                                        <td>PLI-002</td>
                                        <td>Gadget B</td>
                                        <td>30</td>
                                        <td><span class="badge bg-warning">Pending</span></td>
                                    </tr>
                                    <tr>
                                        <td>PLI-003</td>
                                        <td>Device C</td>
                                        <td>25</td>
                                        <td><span class="badge bg-success">Picked</span></td>
                                    </tr>
                                    <tr>
                                        <td>PLI-004</td>
                                        <td>Tool D</td>
                                        <td>15</td>
                                        <td><span class="badge bg-info">In Progress</span></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Recent Trip Tickets -->
                <div class="col-lg-6 mb-4">
                    <div class="card">
                        <div class="card-header bg-info text-white">
                            <h5 class="mb-0"><i class="bi bi-ticket me-2"></i>Recent Trip Tickets</h5>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-sm mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Ticket ID</th>
                                        <th>Driver</th>
                                        <th>Destination</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td>TT-001</td>
                                        <td>John Smith</td>
                                        <td>New York</td>
                                        <td><span class="badge bg-success">Completed</span></td>
                                    </tr>
                                    <tr>
                                        <td>TT-002</td>
                                        <td>Sarah Jones</td>
                                        <td>Boston</td>
                                        <td><span class="badge bg-warning">In Transit</span></td>
                                    </tr>
                                    <tr>
                                        <td>TT-003</td>
                                        <td>Mike Davis</td>
                                        <td>Philadelphia</td>
                                        <td><span class="badge bg-info">Pending</span></td>
                                    </tr>
                                    <tr>
                                        <td>TT-004</td>
                                        <td>Jessica White</td>
                                        <td>Chicago</td>
                                        <td><span class="badge bg-success">Completed</span></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Inventory Alerts -->
            <div class="card mt-4">
                <div class="card-header bg-warning text-dark">
                    <h5 class="mb-0"><i class="bi bi-exclamation-triangle me-2"></i>Low Stock Alerts</h5>
                </div>
                <div class="card-body">
                    <div class="alert alert-warning mb-2">
                        <strong>Widget A:</strong> Stock level at 45 units (Below threshold of 50)
                    </div>
                    <div class="alert alert-warning mb-0">
                        <strong>Gadget B:</strong> Stock level at 30 units (Below threshold of 75)
                    </div>
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

        // Logout function
        function logout() {
            if (confirm('Are you sure you want to logout?')) {
                window.location.href = '../login.php';
            }
        }
    </script>
</body>
</html>
