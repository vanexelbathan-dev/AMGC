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

            <?php
            require_once '../config/database.php';
            
            // Get warehouse statistics from database
            $stats = [];
            
            // Total Items - Count of distinct items from items table
            $total_items_query = "SELECT COUNT(*) as total_items FROM items WHERE status = 'active'";
            $result = $conn->query($total_items_query);
            $stats['total_items'] = $result->fetch_assoc()['total_items'] ?? 0;
            
            // Current Stock - SUM of stock column from items table
            $current_stock_query = "SELECT SUM(stock) as current_stock FROM items WHERE status = 'active'";
            $result = $conn->query($current_stock_query);
            $stats['current_stock'] = $result->fetch_assoc()['current_stock'] ?? 0;
            
            // Pending Deliveries (trip tickets with pending or planned status)
            $pending_deliveries_query = "SELECT COUNT(*) as count FROM trip_tickets WHERE trip_status IN ('planned', 'in-progress')";
            $result = $conn->query($pending_deliveries_query);
            $stats['pending_deliveries'] = $result->fetch_assoc()['count'] ?? 0;
            
            // Active Drivers
            $active_drivers_query = "SELECT COUNT(*) as count FROM drivers WHERE status = 'active'";
            $result = $conn->query($active_drivers_query);
            $stats['active_drivers'] = $result->fetch_assoc()['count'] ?? 0;
            ?>

            <!-- Warehouse Stats - UPDATED FOR MOBILE -->
            <div class="row g-3 mb-4">
                <!-- Total Items -->
                <div class="col-6 col-md-3 mb-3">
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
                <div class="col-6 col-md-3 mb-3">
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

                <!-- Active Drivers -->
                <div class="col-6 col-md-3 mb-3">
                    <div class="stat-card delivery">
                        <div class="stat-icon">
                            <i class="bi bi-person-badge"></i>
                        </div>
                        <div>
                            <div class="stat-value"><?php echo $stats['active_drivers']; ?></div>
                            <div class="stat-label">Active Drivers</div>
                        </div>
                    </div>
                </div>

                <!-- Pending Deliveries -->
                <div class="col-6 col-md-3 mb-3">
                    <div class="stat-card pending">
                        <div class="stat-icon">
                            <i class="bi bi-truck"></i>
                        </div>
                        <div>
                            <div class="stat-value"><?php echo $stats['pending_deliveries']; ?></div>
                            <div class="stat-label">Pending Deliveries</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Recent Activities -->
            <div class="row">
                <!-- Recent Pick List Items -->
                <div class="col-lg-6 mb-4">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="bi bi-clipboard-check me-2"></i>Recent Pick List Items</h5>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Pick List #</th>
                                        <th>Status</th>
                                        <th>Date</th>
                                        <th>Items</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $pick_lists_query = "SELECT pl.*, b.branch_name, 
                                                        (SELECT COUNT(*) FROM pick_list_items WHERE pick_list_id = pl.pick_list_id) as item_count
                                                        FROM pick_lists pl
                                                        LEFT JOIN branches b ON pl.branch_id = b.branch_id
                                                        ORDER BY pl.created_at DESC LIMIT 5";
                                    $result = $conn->query($pick_lists_query);
                                    
                                    if ($result->num_rows > 0) {
                                        while($row = $result->fetch_assoc()) {
                                            $status_badge = '';
                                            switch($row['pick_status']) {
                                                case 'completed': $status_badge = 'bg-success'; break;
                                                case 'in-progress': $status_badge = 'bg-info'; break;
                                                case 'cancelled': $status_badge = 'bg-danger'; break;
                                                default: $status_badge = 'bg-warning';
                                            }
                                            ?>
                                            <tr>
                                                <td><span class="badge bg-light text-dark"><?php echo $row['pick_list_number']; ?></span></td>
                                                <td><span class="badge <?php echo $status_badge; ?>"><?php echo ucfirst($row['pick_status']); ?></span></td>
                                                <td><?php echo date('Y-m-d', strtotime($row['pick_date'])); ?></td>
                                                <td><?php echo $row['item_count']; ?></td>
                                            </tr>
                                            <?php
                                        }
                                    } else {
                                        echo '<tr><td colspan="4" class="text-center">No pick lists found</td></tr>';
                                    }
                                    ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Recent Trip Tickets -->
                <div class="col-lg-6 mb-4">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="bi bi-ticket me-2"></i>Recent Trip Tickets</h5>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Ticket ID</th>
                                        <th>Driver</th>
                                        <th>Status</th>
                                        <th>Date</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $trip_tickets_query = "SELECT tt.*, d.driver_name 
                                                          FROM trip_tickets tt
                                                          LEFT JOIN drivers d ON tt.driver_id = d.driver_id
                                                          ORDER BY tt.trip_date DESC LIMIT 5";
                                    $result = $conn->query($trip_tickets_query);
                                    
                                    if ($result->num_rows > 0) {
                                        while($row = $result->fetch_assoc()) {
                                            $status_badge = '';
                                            switch($row['trip_status']) {
                                                case 'completed': $status_badge = 'bg-success'; break;
                                                case 'in-progress': $status_badge = 'bg-warning'; break;
                                                case 'cancelled': $status_badge = 'bg-danger'; break;
                                                default: $status_badge = 'bg-info';
                                            }
                                            ?>
                                            <tr>
                                                <td><span class="badge bg-light text-dark"><?php echo $row['trip_number']; ?></span></td>
                                                <td><?php echo $row['driver_name'] ?? 'N/A'; ?></td>
                                                <td><span class="badge <?php echo $status_badge; ?>"><?php echo ucfirst(str_replace('-', ' ', $row['trip_status'])); ?></span></td>
                                                <td><?php echo date('Y-m-d', strtotime($row['trip_date'])); ?></td>
                                            </tr>
                                            <?php
                                        }
                                    } else {
                                        echo '<tr><td colspan="4" class="text-center">No trip tickets found</td></tr>';
                                    }
                                    ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Inventory Alerts -->
            <div class="card mt-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-exclamation-triangle me-2"></i>Low Stock Alerts</h5>
                </div>
                <div class="card-body">
                    <?php
                    $low_stock_query = "SELECT i.item_name, i.stock, i.reorder_level 
                                       FROM items i
                                       WHERE i.stock <= i.reorder_level AND i.status = 'active'
                                       LIMIT 5";
                    $result = $conn->query($low_stock_query);
                    
                    if ($result->num_rows > 0) {
                        while($row = $result->fetch_assoc()) {
                            echo '<div class="alert alert-warning mb-2">';
                            echo '<strong>' . $row['item_name'] . ':</strong> Stock level at ' . $row['stock'] . ' units (Below threshold of ' . $row['reorder_level'] . ')';
                            echo '</div>';
                        }
                    } else {
                        echo '<div class="alert alert-success">All items are adequately stocked</div>';
                    }
                    ?>
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