<?php
require_once '../config/database.php';
require_once '../config/session_handler.php';

// Protect page - only Warehouse role can access
requireLogin();
requireRole(['warehouse']);

// Get current user info and branch context
$user_id = $_SESSION['user_id'];
$user_name = isset($_SESSION['first_name']) ? $_SESSION['first_name'] . ' ' . $_SESSION['last_name'] : 'Warehouse User';
$user_role = isset($_SESSION['role']) ? $_SESSION['role'] : 'warehouse';
$user_branch_id = $_SESSION['branch_id'] ?? 0;
$view_all_branches = $_SESSION['view_all_branches'] ?? false;

// Get user's branch name for display
$branch_name = 'All Branches';
$branch_filter = "";
$params = [];
$types = "";

if (!$view_all_branches && $user_branch_id > 0) {
    $branch_filter = " AND (branch_id = ? OR ? = 0)";
    $params[] = $user_branch_id;
    $params[] = $user_branch_id;
    $types .= "ii";
    
    // Get branch name
    $branch_query = "SELECT branch_name FROM branches WHERE branch_id = ?";
    $branch_stmt = $conn->prepare($branch_query);
    $branch_stmt->bind_param("i", $user_branch_id);
    $branch_stmt->execute();
    $branch_result = $branch_stmt->get_result();
    if ($branch_row = $branch_result->fetch_assoc()) {
        $branch_name = $branch_row['branch_name'];
    }
    $branch_stmt->close();
}

// Check if branch_id column exists in customers table
$branch_column_exists = false;
$check_column = $conn->query("SHOW COLUMNS FROM customers LIKE 'branch_id'");
if ($check_column && $check_column->num_rows > 0) {
    $branch_column_exists = true;
}

// Check if branch_id column exists in items table
$items_branch_column_exists = false;
$check_items_column = $conn->query("SHOW COLUMNS FROM items LIKE 'branch_id'");
if ($check_items_column && $check_items_column->num_rows > 0) {
    $items_branch_column_exists = true;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Warehouse Manager <?php echo !$view_all_branches ? '- ' . htmlspecialchars($branch_name) : ''; ?></title>
    <link rel="icon" type="image/png" href="../Pictures/favicon-96x96.png" sizes="96x96" />
    <link rel="icon" type="image/svg+xml" href="../Pictures/favicon.svg" />
    <link rel="shortcut icon" href="../Pictures/favicon.ico" />
    <link rel="apple-touch-icon" sizes="180x180" href="../Pictures/apple-touch-icon.png" />
    <link rel="manifest" href="../Pictures/site.webmanifest" />
    <link rel="stylesheet" href="../css/warehouse.css">
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <style>
        /* Branch indicator */
        .branch-indicator {
            display: inline-block;
            padding: 4px 12px;
            background-color: #e7f5ff;
            color: #0d6efd;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 500;
            margin-left: 10px;
        }
        
        .branch-indicator i {
            margin-right: 5px;
        }
        
        /* User branch in sidebar */
        .user-branch-sidebar {
            font-size: 11px;
            color: #aaa;
            display: block;
            margin-top: 2px;
        }
        
        /* Stat cards */
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            display: flex;
            align-items: center;
            transition: transform 0.2s;
            border-left: 4px solid;
        }
        
        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        
        .stat-card.inventory { border-left-color: #0d6efd; }
        .stat-card.stock { border-left-color: #198754; }
        .stat-card.delivery { border-left-color: #fd7e14; }
        .stat-card.pending { border-left-color: #ffc107; }
        
        .stat-icon {
            font-size: 2.5rem;
            margin-right: 15px;
            color: #6c757d;
        }
        
        .stat-value {
            font-size: 1.8rem;
            font-weight: 600;
            line-height: 1.2;
        }
        
        .stat-label {
            font-size: 0.9rem;
            color: #6c757d;
        }
        
        /* Mobile responsive */
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
            
            .col-6 {
                padding-left: 8px;
                padding-right: 8px;
            }
            
            .row.g-3 {
                margin-left: -8px;
                margin-right: -8px;
            }
        }
        
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
        }
        
        /* Card headers */
        .card-header {
            background-color: #f8f9fa;
            font-weight: 600;
            padding: 12px 16px;
        }
        
        .card-header i {
            color: #0d6efd;
        }
        
        /* Table styles */
        .table th {
            font-weight: 600;
            color: #495057;
            font-size: 0.85rem;
        }
        
        .table td {
            vertical-align: middle;
        }
        
        /* Badge styles */
        .badge {
            font-weight: 500;
            padding: 6px 10px;
        }
        
        /* Alert styles */
        .alert-warning {
            background-color: #fff3cd;
            border-color: #ffecb5;
            color: #856404;
        }
        
        .alert-success {
            background-color: #d1e7dd;
            border-color: #badbcc;
            color: #0a3622;
        }
    </style>
</head>
<body>
    <!-- MAIN APPLICATION -->
    <div id="appPage">
        <!-- Sidebar -->
        <div class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <h3>
                    <button class="desktop-toggle-btn" id="desktopToggleBtn">
                        <i class="bi bi-list" id="toggleIcon"></i>
                    </button>
                    <img src="../Pictures/amgc3DLogo.png" alt="Logo" class="logo-icon"> 
                    <span class="nav-text">Warehouse</span>
                </h3>
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
            <!-- User Profile Section at the bottom of sidebar -->
            <div class="sidebar-footer">
                <div class="user-profile-sidebar">
                    <div class="user-avatar-sidebar"><?php echo substr($user_name, 0, 2); ?></div>
                    <div class="user-details-sidebar">
                        <span class="user-name-sidebar"><?php echo htmlspecialchars($user_name); ?></span>
                        <?php if (!$view_all_branches): ?>
                            <span class="user-branch-sidebar"><i class="bi bi-building"></i> <?php echo htmlspecialchars($branch_name); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <button class="logout-btn-sidebar" onclick="logout()">
                    <i class="bi bi-box-arrow-right"></i>
                    <span class="logout-text">Logout</span>
                </button>
            </div>
        </div>

        <!-- Main Content Area -->
        <div class="main-content">
            <!-- Header Section with User Info and Logout -->
            <div class="navbar-top">
                <button class="mobile-toggle-btn" id="mobileToggleBtn">
                    <i class="bi bi-list"></i>
                </button>
                <div class="page-title">
                    <h2>
                        <i></i>Warehouse Dashboard
                        <?php if (!$view_all_branches && $user_branch_id > 0): ?>
                            <span class="branch-indicator">
                                <i class="bi bi-building"></i> <?php echo htmlspecialchars($branch_name); ?>
                            </span>
                        <?php endif; ?>
                    </h2>
                    <p>Monitor inventory, shipments, and delivery operations</p>
                </div>
            </div>

            <?php
            // Get warehouse statistics from database with branch filtering
            
            // Prepare base queries with branch filter - REMOVED CANCELLED STATUS
            $total_items_query = "SELECT COUNT(*) as total_items FROM items WHERE status = 'active'";
            $current_stock_query = "SELECT SUM(stock) as current_stock FROM items WHERE status = 'active'";
            $pending_deliveries_query = "SELECT COUNT(*) as count FROM trip_tickets WHERE trip_status IN ('planned', 'in-progress')";
            $active_drivers_query = "SELECT COUNT(*) as count FROM drivers WHERE status = 'active'";
            
            // Add branch filters if items table has branch_id column
            if (!$view_all_branches && $user_branch_id > 0) {
                if ($items_branch_column_exists) {
                    $total_items_query .= " AND branch_id = ?";
                    $current_stock_query .= " AND branch_id = ?";
                }
                
                // Check if trip_tickets has branch_id column
                $check_tt_branch = $conn->query("SHOW COLUMNS FROM trip_tickets LIKE 'branch_id'");
                if ($check_tt_branch && $check_tt_branch->num_rows > 0) {
                    $pending_deliveries_query .= " AND branch_id = ?";
                }
                
                // Check if drivers has branch_id column
                $check_drivers_branch = $conn->query("SHOW COLUMNS FROM drivers LIKE 'branch_id'");
                if ($check_drivers_branch && $check_drivers_branch->num_rows > 0) {
                    $active_drivers_query .= " AND branch_id = ?";
                }
            }
            
            // Execute queries with proper error handling
            $stats = [];
            
            // Total Items
            if (!$view_all_branches && $user_branch_id > 0 && $items_branch_column_exists) {
                $stmt = $conn->prepare($total_items_query);
                $stmt->bind_param("i", $user_branch_id);
                $stmt->execute();
                $result = $stmt->get_result();
                $stats['total_items'] = $result->fetch_assoc()['total_items'] ?? 0;
                $stmt->close();
            } else {
                $result = $conn->query($total_items_query);
                $stats['total_items'] = $result->fetch_assoc()['total_items'] ?? 0;
            }
            
            // Current Stock
            if (!$view_all_branches && $user_branch_id > 0 && $items_branch_column_exists) {
                $stmt = $conn->prepare($current_stock_query);
                $stmt->bind_param("i", $user_branch_id);
                $stmt->execute();
                $result = $stmt->get_result();
                $stats['current_stock'] = $result->fetch_assoc()['current_stock'] ?? 0;
                $stmt->close();
            } else {
                $result = $conn->query($current_stock_query);
                $stats['current_stock'] = $result->fetch_assoc()['current_stock'] ?? 0;
            }
            
            // Pending Deliveries - EXCLUDING CANCELLED
            if (!$view_all_branches && $user_branch_id > 0) {
                $check_tt_branch = $conn->query("SHOW COLUMNS FROM trip_tickets LIKE 'branch_id'");
                if ($check_tt_branch && $check_tt_branch->num_rows > 0) {
                    $stmt = $conn->prepare($pending_deliveries_query);
                    $stmt->bind_param("i", $user_branch_id);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    $stats['pending_deliveries'] = $result->fetch_assoc()['count'] ?? 0;
                    $stmt->close();
                } else {
                    $result = $conn->query($pending_deliveries_query);
                    $stats['pending_deliveries'] = $result->fetch_assoc()['count'] ?? 0;
                }
            } else {
                $result = $conn->query($pending_deliveries_query);
                $stats['pending_deliveries'] = $result->fetch_assoc()['count'] ?? 0;
            }
            
            // Active Drivers
            if (!$view_all_branches && $user_branch_id > 0) {
                $check_drivers_branch = $conn->query("SHOW COLUMNS FROM drivers LIKE 'branch_id'");
                if ($check_drivers_branch && $check_drivers_branch->num_rows > 0) {
                    $stmt = $conn->prepare($active_drivers_query);
                    $stmt->bind_param("i", $user_branch_id);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    $stats['active_drivers'] = $result->fetch_assoc()['count'] ?? 0;
                    $stmt->close();
                } else {
                    $result = $conn->query($active_drivers_query);
                    $stats['active_drivers'] = $result->fetch_assoc()['count'] ?? 0;
                }
            } else {
                $result = $conn->query($active_drivers_query);
                $stats['active_drivers'] = $result->fetch_assoc()['count'] ?? 0;
            }
            ?>

            <!-- Warehouse Stats -->
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
                            <div class="stat-label">Pending Pickup</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Recent Activities -->
            <div class="row">
                <!-- Recent Pick List Items - REMOVED CANCELLED STATUS -->
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
                                        <?php if ($view_all_branches): ?><th>Branch</th><?php endif; ?>
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
                                                        WHERE pl.pick_status != 'cancelled'"; // EXCLUDE CANCELLED
                                    
                                    if (!$view_all_branches && $user_branch_id > 0) {
                                        $pick_lists_query .= " AND pl.branch_id = ?";
                                        $stmt = $conn->prepare($pick_lists_query . " ORDER BY pl.created_at DESC LIMIT 5");
                                        $stmt->bind_param("i", $user_branch_id);
                                    } else {
                                        $stmt = $conn->prepare($pick_lists_query . " ORDER BY pl.created_at DESC LIMIT 5");
                                    }
                                    
                                    $stmt->execute();
                                    $result = $stmt->get_result();
                                    
                                    if ($result->num_rows > 0) {
                                        while($row = $result->fetch_assoc()) {
                                            $status_badge = '';
                                            switch($row['pick_status']) {
                                                case 'completed': $status_badge = 'bg-success'; break;
                                                case 'in-progress': $status_badge = 'bg-info'; break;
                                                default: $status_badge = 'bg-warning';
                                            }
                                            ?>
                                            <tr>
                                                <td><span class="badge bg-light text-dark"><?php echo $row['pick_list_number']; ?></span></td>
                                                <?php if ($view_all_branches): ?>
                                                    <td><span class="badge bg-info"><?php echo htmlspecialchars($row['branch_name']); ?></span></td>
                                                <?php endif; ?>
                                                <td><span class="badge <?php echo $status_badge; ?>"><?php echo ucfirst($row['pick_status']); ?></span></td>
                                                <td><?php echo date('Y-m-d', strtotime($row['pick_date'])); ?></td>
                                                <td><?php echo $row['item_count']; ?></td>
                                            </tr>
                                            <?php
                                        }
                                    } else {
                                        $colspan = $view_all_branches ? 5 : 4;
                                        echo '<tr><td colspan="' . $colspan . '" class="text-center">No pick lists found for this branch</td></tr>';
                                    }
                                    $stmt->close();
                                    ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Recent Trip Tickets - UPDATED: Driver from Pick List (Plain Text Only) -->
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
                                        <?php if ($view_all_branches): ?><th>Branch</th><?php endif; ?>
                                        <th>Driver</th>
                                        <th>Status</th>
                                        <th>Date</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    // UPDATED QUERY: Get driver from pick list through picklist_id
                                    $trip_tickets_query = "SELECT 
                                        tt.trip_id,
                                        tt.trip_number, 
                                        tt.trip_date,
                                        tt.trip_status,
                                        tt.branch_id,
                                        b.branch_name,
                                        tt.picklist_id,
                                        -- Get driver from pick list (priority) or fallback to trip ticket driver
                                        COALESCE(pl.driver_name, d.driver_name) as driver_name
                                    FROM trip_tickets tt
                                    LEFT JOIN drivers d ON tt.driver_id = d.driver_id
                                    LEFT JOIN branches b ON tt.branch_id = b.branch_id
                                    LEFT JOIN (
                                        SELECT 
                                            pl.pick_list_id,
                                            d.driver_name
                                        FROM pick_lists pl
                                        LEFT JOIN drivers d ON pl.driver_id = d.driver_id
                                    ) pl ON tt.picklist_id = pl.pick_list_id
                                    WHERE tt.trip_status != 'cancelled'"; // EXCLUDE CANCELLED
                                    
                                    // Check if trip_tickets has branch_id column
                                    $check_tt_branch = $conn->query("SHOW COLUMNS FROM trip_tickets LIKE 'branch_id'");
                                    if (!$view_all_branches && $user_branch_id > 0 && $check_tt_branch && $check_tt_branch->num_rows > 0) {
                                        $trip_tickets_query .= " AND tt.branch_id = ?";
                                        $stmt = $conn->prepare($trip_tickets_query . " ORDER BY tt.trip_date DESC LIMIT 5");
                                        $stmt->bind_param("i", $user_branch_id);
                                    } else {
                                        $stmt = $conn->prepare($trip_tickets_query . " ORDER BY tt.trip_date DESC LIMIT 5");
                                    }
                                    
                                    $stmt->execute();
                                    $result = $stmt->get_result();
                                    
                                    if ($result->num_rows > 0) {
                                        while($row = $result->fetch_assoc()) {
                                            $status_badge = '';
                                            switch($row['trip_status']) {
                                                case 'completed': $status_badge = 'bg-success'; break;
                                                case 'in-progress': $status_badge = 'bg-warning'; break;
                                                default: $status_badge = 'bg-info';
                                            }
                                            
                                            // Determine driver display (plain text only)
                                            $driver_display = !empty($row['driver_name']) ? $row['driver_name'] : 'N/A';
                                            ?>
                                            <tr>
                                                <td><span class="badge bg-light text-dark"><?php echo $row['trip_number']; ?></span></td>
                                                <?php if ($view_all_branches): ?>
                                                    <td><span class="badge bg-info"><?php echo htmlspecialchars($row['branch_name'] ?? 'N/A'); ?></span></td>
                                                <?php endif; ?>
                                                <td><?php echo htmlspecialchars($driver_display); ?></td>
                                                <td><span class="badge <?php echo $status_badge; ?>"><?php echo ucfirst(str_replace('-', ' ', $row['trip_status'])); ?></span></td>
                                                <td><?php echo date('Y-m-d', strtotime($row['trip_date'])); ?></td>
                                            </tr>
                                            <?php
                                        }
                                    } else {
                                        $colspan = $view_all_branches ? 5 : 4;
                                        echo '<tr><td colspan="' . $colspan . '" class="text-center">No trip tickets found for this branch</td></tr>';
                                    }
                                    $stmt->close();
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
                    $low_stock_query = "SELECT i.item_name, i.stock, i.reorder_level, b.branch_name 
                                       FROM items i
                                       LEFT JOIN branches b ON i.branch_id = b.branch_id
                                       WHERE i.stock <= i.reorder_level AND i.status = 'active'";
                    
                    if (!$view_all_branches && $user_branch_id > 0 && $items_branch_column_exists) {
                        $low_stock_query .= " AND i.branch_id = ?";
                        $stmt = $conn->prepare($low_stock_query . " LIMIT 5");
                        $stmt->bind_param("i", $user_branch_id);
                    } else {
                        $stmt = $conn->prepare($low_stock_query . " LIMIT 5");
                    }
                    
                    $stmt->execute();
                    $result = $stmt->get_result();
                    
                    if ($result->num_rows > 0) {
                        while($row = $result->fetch_assoc()) {
                            $branch_info = $view_all_branches ? ' [' . $row['branch_name'] . ']' : '';
                            echo '<div class="alert alert-warning mb-2">';
                            echo '<i class="bi bi-exclamation-triangle me-2"></i>';
                            echo '<strong>' . htmlspecialchars($row['item_name'] . $branch_info) . ':</strong> ';
                            echo 'Stock level at ' . $row['stock'] . ' units (Below threshold of ' . $row['reorder_level'] . ')';
                            echo '</div>';
                        }
                    } else {
                        echo '<div class="alert alert-success mb-0">';
                        echo '<i class="bi bi-check-circle me-2"></i>';
                        echo 'All items are adequately stocked';
                        echo '</div>';
                    }
                    $stmt->close();
                    ?>
                </div>
            </div>
        </div>
    </div>

    <!-- JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // ================= SIDEBAR FUNCTIONS =================
        // Toggle sidebar collapse/expand
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const isMobile = window.innerWidth <= 992;
            
            if (isMobile) {
                // On mobile, toggle active state
                sidebar.classList.toggle('active');
                
                // Create overlay for mobile
                if (!document.querySelector('.sidebar-overlay')) {
                    const overlay = document.createElement('div');
                    overlay.className = 'sidebar-overlay';
                    document.body.appendChild(overlay);
                    
                    overlay.addEventListener('click', () => {
                        closeMobileSidebar();
                    });
                    
                    setTimeout(() => {
                        overlay.classList.add('active');
                    }, 10);
                } else {
                    // If overlay exists, toggle its active state
                    const overlay = document.querySelector('.sidebar-overlay');
                    overlay.classList.toggle('active');
                    if (!sidebar.classList.contains('active')) {
                        setTimeout(() => {
                            if (overlay && overlay.parentNode) {
                                overlay.remove();
                            }
                        }, 300);
                    }
                }
            } else {
                // On desktop, toggle between expanded and collapsed
                sidebar.classList.toggle('collapsed');
                
                // Store preference in localStorage
                localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
                
                // Show/hide nav text
                document.querySelectorAll('.nav-text').forEach(text => {
                    text.style.display = sidebar.classList.contains('collapsed') ? 'none' : 'inline-block';
                });
                
                // Adjust main content margin
                const mainContent = document.querySelector('.main-content');
                if (mainContent) {
                    mainContent.style.marginLeft = sidebar.classList.contains('collapsed') ? '80px' : '250px';
                }
            }
        }

        // Close mobile sidebar
        function closeMobileSidebar() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.querySelector('.sidebar-overlay');
            
            sidebar.classList.remove('active');
            
            if (overlay) {
                overlay.classList.remove('active');
                setTimeout(() => {
                    if (overlay.parentNode) {
                        overlay.remove();
                    }
                }, 300);
            }
        }

        // Initialize sidebar when page loads
        function initializeSidebar() {
            const sidebar = document.getElementById('sidebar');
            
            // Load saved preference from localStorage for desktop
            if (window.innerWidth > 992) {
                const savedCollapsed = localStorage.getItem('sidebarCollapsed');
                if (savedCollapsed === 'true') {
                    sidebar.classList.add('collapsed');
                    document.querySelectorAll('.nav-text').forEach(text => {
                        text.style.display = 'none';
                    });
                    
                    // Adjust main content margin
                    const mainContent = document.querySelector('.main-content');
                    if (mainContent) {
                        mainContent.style.marginLeft = '80px';
                    }
                } else {
                    sidebar.classList.remove('collapsed');
                    document.querySelectorAll('.nav-text').forEach(text => {
                        text.style.display = 'inline-block';
                    });
                    
                    // Adjust main content margin
                    const mainContent = document.querySelector('.main-content');
                    if (mainContent) {
                        mainContent.style.marginLeft = '250px';
                    }
                }
            } else {
                // On mobile, always start with closed sidebar
                sidebar.classList.remove('active');
                sidebar.classList.remove('collapsed');
                document.querySelectorAll('.nav-text').forEach(text => {
                    text.style.display = 'inline-block';
                });
                
                // Adjust main content margin
                const mainContent = document.querySelector('.main-content');
                if (mainContent) {
                    mainContent.style.marginLeft = '0';
                }
            }
        }

        // Handle window resize for sidebar
        function handleSidebarResize() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.querySelector('.sidebar-overlay');
            
            if (window.innerWidth > 992) {
                // Desktop mode - remove mobile overlay
                if (overlay) {
                    overlay.remove();
                }
                sidebar.classList.remove('active');
                
                // Load saved preference
                const savedCollapsed = localStorage.getItem('sidebarCollapsed');
                if (savedCollapsed === 'true') {
                    sidebar.classList.add('collapsed');
                    document.querySelectorAll('.nav-text').forEach(text => {
                        text.style.display = 'none';
                    });
                    
                    // Adjust main content margin
                    const mainContent = document.querySelector('.main-content');
                    if (mainContent) {
                        mainContent.style.marginLeft = '80px';
                    }
                } else {
                    sidebar.classList.remove('collapsed');
                    document.querySelectorAll('.nav-text').forEach(text => {
                        text.style.display = 'inline-block';
                    });
                    
                    // Adjust main content margin
                    const mainContent = document.querySelector('.main-content');
                    if (mainContent) {
                        mainContent.style.marginLeft = '250px';
                    }
                }
            } else {
                // Mobile mode - always show expanded when visible
                sidebar.classList.remove('collapsed');
                document.querySelectorAll('.nav-text').forEach(text => {
                    text.style.display = 'inline-block';
                });
                
                // Adjust main content margin
                const mainContent = document.querySelector('.main-content');
                if (mainContent) {
                    mainContent.style.marginLeft = '0';
                }
            }
        }
        // ================= END SIDEBAR FUNCTIONS =================

        // Logout function
        function logout() {
            if (confirm('Are you sure you want to logout?')) {
                window.location.href = '../logout.php';
            }
        }

        // Initialize when page loads
        document.addEventListener('DOMContentLoaded', function() {
            console.log("Warehouse Dashboard loaded!");
            
            // Initialize sidebar
            initializeSidebar();
            
            // Setup mobile toggle button
            const mobileToggleBtn = document.getElementById('mobileToggleBtn');
            if (mobileToggleBtn) {
                mobileToggleBtn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    toggleSidebar();
                });
            }
            
            // Setup desktop toggle button
            const desktopToggleBtn = document.getElementById('desktopToggleBtn');
            if (desktopToggleBtn) {
                desktopToggleBtn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    toggleSidebar();
                });
            }
            
            // Add click listeners to sidebar links to close on mobile
            document.querySelectorAll('.sidebar .nav-link').forEach(link => {
                link.addEventListener('click', function() {
                    if (window.innerWidth <= 992) {
                        closeMobileSidebar();
                    }
                });
            });
            
            // Close sidebar when clicking outside on mobile
            document.addEventListener('click', function(event) {
                const sidebar = document.getElementById('sidebar');
                const mobileBtn = document.getElementById('mobileToggleBtn');
                const overlay = document.querySelector('.sidebar-overlay');
                const isMobile = window.innerWidth <= 992;
                
                if (isMobile && sidebar && sidebar.classList.contains('active') && 
                    !sidebar.contains(event.target) && 
                    (!mobileBtn || !mobileBtn.contains(event.target)) &&
                    (!overlay || !overlay.contains(event.target))) {
                    closeMobileSidebar();
                }
            });

            // Add resize event listener
            window.addEventListener('resize', handleSidebarResize);
        });

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl + B to toggle sidebar (desktop only)
            if (e.ctrlKey && e.key === 'b' && window.innerWidth > 992) {
                e.preventDefault();
                toggleSidebar();
            }
            // Escape to close sidebar on mobile
            else if (e.key === 'Escape' && window.innerWidth <= 992) {
                closeMobileSidebar();
            }
        });
    </script>
</body>
</html>