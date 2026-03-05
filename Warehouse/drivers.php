<?php
require_once '../config/database.php';
require_once '../config/session_handler.php';

requireLogin();
requireRole(['warehouse']);

// Get current user info and branch context
$user_id = $_SESSION['user_id'];
$user_name = isset($_SESSION['first_name']) ? $_SESSION['first_name'] . ' ' . $_SESSION['last_name'] : 'Sales User';
$user_role = isset($_SESSION['role']) ? $_SESSION['role'] : 'sales';
$user_branch_id = $_SESSION['branch_id'] ?? 0;
$view_all_branches = $_SESSION['view_all_branches'] ?? false;

// Get user's branch name for display
$branch_name = 'All Branches';
if (!$view_all_branches && $user_branch_id > 0) {
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

// Check if branch_id column exists in drivers table
$drivers_branch_column_exists = false;
$check_drivers_column = $conn->query("SHOW COLUMNS FROM drivers LIKE 'branch_id'");
if ($check_drivers_column && $check_drivers_column->num_rows > 0) {
    $drivers_branch_column_exists = true;
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
    <title>Drivers - Warehouse <?php echo !$view_all_branches ? '- ' . htmlspecialchars($branch_name) : ''; ?></title>
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
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
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
            
            .col-md-3 {
                width: 50%;
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

        /* Mobile Profile Modal Styles */
        .user-avatar-large {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--dark-green), var(--primary-green));
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2.5rem;
            font-weight: bold;
            margin: 0 auto;
            border: 4px solid var(--light-green);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        #profileModal .modal-content {
            border: none;
            border-radius: 20px;
            overflow: hidden;
        }

        #profileModal .modal-header {
            background: linear-gradient(135deg, var(--dark-green), var(--primary-green));
            color: white;
            border-bottom: none;
            padding: 1.5rem;
        }

        #profileModal .modal-header .modal-title {
            color: white;
            font-weight: 600;
        }

        #profileModal .modal-header .btn-close {
            filter: brightness(0) invert(1);
            opacity: 0.9;
        }

        #profileModal .modal-header .btn-close:hover {
            opacity: 1;
            transform: rotate(90deg);
        }

        #profileModal .modal-body {
            padding: 2rem;
            background: linear-gradient(135deg, #f9fefc 0%, #f0fdf4 100%);
        }

        #profileModal .branch-info {
            background: var(--light-green);
            color: var(--dark-green);
            padding: 0.5rem 1rem;
            border-radius: 50px;
            display: inline-block;
            font-weight: 500;
        }

        #profileModal .btn-danger {
            background: linear-gradient(135deg, #dc3545, #f87171);
            border: none;
            padding: 1rem;
            border-radius: 50px;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        #profileModal .btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(220, 53, 69, 0.3);
        }

        /* Mobile Logout Button in Bottom Nav */
        .mobile-nav .nav-link.logout-btn {
            color: #dc3545;
        }

        .mobile-nav .nav-link.logout-btn i {
            color: #dc3545;
        }

        .mobile-nav .nav-link.logout-btn.active,
        .mobile-nav .nav-link.logout-btn:hover {
            background: rgba(220, 53, 69, 0.1);
            color: #dc3545;
        }

        .mobile-nav .nav-link.logout-btn.active i,
        .mobile-nav .nav-link.logout-btn:hover i {
            color: #dc3545;
        }

        /* Search icon inside field */
        .search-wrapper {
            position: relative;
            width: 100%;
        }

        .search-icon {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #6c757d;
            z-index: 10;
            font-size: 1rem;
            pointer-events: none;
        }

        .search-input {
            padding-left: 35px !important;
            width: 100%;
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
                        <a class="nav-link" href="warehouse.php">
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
                        <a class="nav-link" href="purchase_order.php">
                            <i class="bi bi-receipt"></i>
                            <span class="nav-text">Purchase Orders</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="drivers.php">
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
                        <i></i>Driver Management
                    </h2>
                    <p>Manage driver information and track delivery performance</p>
                </div>
            </div>

            <?php
            require_once '../config/database.php';
            
            // Get driver statistics with branch filtering
            $stats = [];
            
            // Total Drivers
            $total_drivers_query = "SELECT COUNT(*) as count FROM drivers";
            if (!$view_all_branches && $user_branch_id > 0 && $drivers_branch_column_exists) {
                $total_drivers_query .= " WHERE branch_id = ?";
                $stmt = $conn->prepare($total_drivers_query);
                $stmt->bind_param("i", $user_branch_id);
                $stmt->execute();
                $result = $stmt->get_result();
                $stats['total_drivers'] = $result->fetch_assoc()['count'] ?? 0;
                $stmt->close();
            } else {
                $result = $conn->query($total_drivers_query);
                $stats['total_drivers'] = $result->fetch_assoc()['count'] ?? 0;
            }
            
            // Active Drivers
            $active_drivers_query = "SELECT COUNT(*) as count FROM drivers WHERE status = 'active'";
            if (!$view_all_branches && $user_branch_id > 0 && $drivers_branch_column_exists) {
                $active_drivers_query .= " AND branch_id = ?";
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
            
            // Trips Completed
            $trips_completed_query = "SELECT COUNT(*) as count FROM trip_tickets WHERE trip_status = 'completed'";
            $result = $conn->query($trips_completed_query);
            $stats['trips_completed'] = $result->fetch_assoc()['count'] ?? 0;
            ?>

            <!-- Driver Stats -->
            <div class="row g-3 mb-4">
                <!-- Total Drivers -->
                <div class="col-md-3 mb-3">
                    <div class="stat-card drivers">
                        <div class="stat-icon">
                            <i class="bi bi-person-badge"></i>
                        </div>
                        <div>
                            <div class="stat-value"><?php echo $stats['total_drivers']; ?></div>
                            <div class="stat-label">Total Drivers</div>
                        </div>
                    </div>
                </div>

                <!-- Active Drivers -->
                <div class="col-md-3 mb-3">
                    <div class="stat-card complete">
                        <div class="stat-icon">
                            <i class="bi bi-check-circle"></i>
                        </div>
                        <div>
                            <div class="stat-value"><?php echo $stats['active_drivers']; ?></div>
                            <div class="stat-label">Active Drivers</div>
                        </div>
                    </div>
                </div>

                <!-- Trips Completed -->
                <div class="col-md-3 mb-3">
                    <div class="stat-card trips">
                        <div class="stat-icon">
                            <i class="bi bi-truck"></i>
                        </div>
                        <div>
                            <div class="stat-value"><?php echo $stats['trips_completed']; ?></div>
                            <div class="stat-label">Trips Completed</div>
                        </div>
                    </div>
                </div>

                <!-- Avg Rating (static for now) -->
                <div class="col-md-3 mb-3">
                    <div class="stat-card stock">
                        <div class="stat-icon">
                            <i class="bi bi-star"></i>
                        </div>
                        <div>
                            <div class="stat-value">4.6/5</div>
                            <div class="stat-label">Avg Rating</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Search and Filter - Updated with icon inside field -->
            <div class="card mb-4">
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <div class="search-wrapper">
                                <i class="bi bi-search search-icon"></i>
                                <input type="text" class="form-control search-input" id="searchInput" placeholder="Search by name or driver ID...">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <select class="form-select" id="statusFilter">
                                <option value="">All Status</option>
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                                <option value="on-leave">On Leave</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <div class="search-wrapper">
                                <i class="bi bi-truck search-icon"></i>
                                <input type="text" class="form-control search-input" id="vehicleTypeFilter" placeholder="Filter by vehicle type...">
                            </div>
                        </div>
                        <div class="col-md-2">
                            <button class="btn btn-outline-secondary w-100" onclick="clearFilters()">
                                <i class="bi bi-x-circle"></i> Clear
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Drivers Table -->
            <div class="card">
                <div class="table-container">
                    <table class="table custom-table compact-table">
                        <thead class="table-light">
                            <tr>
                                <th>Driver Name</th>
                                <th>License Number</th>
                                <th>Contact Number</th>
                                <th>Vehicle Type</th>
                                <th>Vehicle Plate</th>
                                <th>Branch</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $drivers_query = "SELECT d.*, b.branch_name 
                                             FROM drivers d
                                             LEFT JOIN branches b ON d.branch_id = b.branch_id";
                            
                            // Add branch filter if user doesn't have view_all_branches permission
                            if (!$view_all_branches && $user_branch_id > 0 && $drivers_branch_column_exists) {
                                $drivers_query .= " WHERE d.branch_id = ?";
                                $stmt = $conn->prepare($drivers_query . " ORDER BY d.driver_name");
                                $stmt->bind_param("i", $user_branch_id);
                            } else {
                                $stmt = $conn->prepare($drivers_query . " ORDER BY d.driver_name");
                            }
                            
                            $stmt->execute();
                            $result = $stmt->get_result();
                            
                            if ($result->num_rows > 0) {
                                while($row = $result->fetch_assoc()) {
                                    $status_badge = '';
                                    switch($row['status']) {
                                        case 'active': $status_badge = 'bg-success'; break;
                                        case 'inactive': $status_badge = 'bg-secondary'; break;
                                        case 'on-leave': $status_badge = 'bg-warning'; break;
                                        default: $status_badge = 'bg-light text-dark';
                                    }
                                    ?>
                                    <tr data-vehicle-type="<?php echo strtolower($row['vehicle_type'] ?? ''); ?>">
                                        <td><?php echo htmlspecialchars($row['driver_name']); ?></td>
                                        <td><?php echo htmlspecialchars($row['license_number']); ?></td>
                                        <td><?php echo htmlspecialchars($row['contact_number'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($row['vehicle_type'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($row['vehicle_plate_number'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($row['branch_name'] ?? 'N/A'); ?></td>
                                        <td><span class="badge <?php echo $status_badge; ?>"><?php echo ucfirst(str_replace('-', ' ', $row['status'])); ?></span></td>
                                        <td>
                                            <button class="btn-action btn-view" data-bs-toggle="modal" data-bs-target="#viewDriverModal" 
                                                    onclick="loadDriverDetails('<?php echo $row['driver_id']; ?>')">
                                                <i class="bi bi-eye"></i>
                                            </button>
                                        </td>
                                    </tr>
                                    <?php
                                }
                            } else {
                                $colspan = 8;
                                echo '<tr><td colspan="' . $colspan . '" class="text-center">No drivers found for this branch</td></tr>';
                            }
                            $stmt->close();
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Mobile Bottom Navigation -->
    <div class="mobile-nav" id="mobileNav">
        <ul class="nav">
            <li class="nav-item">
                <a class="nav-link" href="warehouse.php">
                    <i class="bi bi-speedometer2"></i>
                    <span>Dashboard</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="currentinventory.php">
                    <i class="bi bi-boxes"></i>
                    <span>Inventory</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="pick_list_items.php">
                    <i class="bi bi-clipboard-check"></i>
                    <span>Pick Lists</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="purchase_order.php">
                    <i class="bi bi-receipt"></i>
                    <span>PO</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link active" href="drivers.php">
                    <i class="bi bi-person-badge"></i>
                    <span>Drivers</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link logout-btn" href="#" onclick="showProfileModal(); return false;">
                    <i class="bi bi-box-arrow-right"></i>
                    <span>Logout</span>
                </a>
            </li>
        </ul>
    </div>

    <!-- Mobile Profile/Logout Modal -->
    <div class="modal fade" id="profileModal" tabindex="-1" aria-labelledby="profileModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="profileModalLabel">
                        <i class="bi bi-person-circle me-2"></i>User Profile
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body text-center">
                    <!-- User Avatar -->
                    <div class="user-avatar-large mb-3">
                        <?php echo substr($user_name, 0, 2); ?>
                    </div>
                    
                    <!-- User Name -->
                    <h4 class="mb-1"><?php echo htmlspecialchars($user_name); ?></h4>
                    
                    <!-- User Role -->
                    <p class="text-muted mb-3">
                        <span class="badge bg-success"><?php echo ucfirst($user_role); ?></span>
                    </p>
                    
                    <!-- Branch Info (if applicable) -->
                    <?php if (!$view_all_branches && $user_branch_id > 0): ?>
                    <div class="branch-info mb-3">
                        <i class="bi bi-building me-1"></i>
                        <span><?php echo htmlspecialchars($branch_name); ?></span>
                    </div>
                    <?php endif; ?>
                    
                    <!-- User ID -->
                    <div class="user-id text-muted small mb-4">
                        <i class="bi bi-hash"></i> User ID: <?php echo $user_id; ?>
                    </div>
                    
                    <!-- Logout Button -->
                    <button class="btn btn-danger btn-lg w-100" onclick="confirmLogout()">
                        <i class="bi bi-box-arrow-right me-2"></i>Logout
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- View Driver Details Modal -->
    <div class="modal fade" id="viewDriverModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Driver Profile</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="driverDetailsContent">
                    <!-- Content will be loaded by JavaScript -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
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

        // ================= MOBILE NAVIGATION FUNCTIONS =================
        function initMobileNav() {
            const mobileNav = document.getElementById('mobileNav');
            const isMobile = window.innerWidth <= 992;
            
            if (isMobile) {
                mobileNav.style.display = 'block';
                
                // Set active state based on current page (excluding logout)
                const currentPage = window.location.pathname.split('/').pop();
                const navLinks = mobileNav.querySelectorAll('.nav-link:not(.logout-btn)');
                
                navLinks.forEach(link => {
                    link.classList.remove('active');
                    const href = link.getAttribute('href');
                    if (currentPage === href) {
                        link.classList.add('active');
                    }
                });
            } else {
                mobileNav.style.display = 'none';
            }
        }

        // ================= PROFILE/LOGOUT FUNCTIONS =================
        function showProfileModal() {
            const profileModal = new bootstrap.Modal(document.getElementById('profileModal'));
            profileModal.show();
        }

        function confirmLogout() {
            // Close the modal first
            const modal = bootstrap.Modal.getInstance(document.getElementById('profileModal'));
            if (modal) {
                modal.hide();
            }
            
            // Show confirmation dialog
            Swal.fire({
                title: 'Are you sure?',
                text: 'You will be logged out of the system',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#dc3545',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Yes, logout'
            }).then((result) => {
                if (result.isConfirmed) {
                    localStorage.removeItem('sidebarCollapsed');
                    window.location.href = '../logout.php';
                }
            });
        }

        // Original logout function for sidebar
        function logout() {
            Swal.fire({
                title: 'Are you sure?',
                text: 'You will be logged out of the system',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#07d826',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Yes, logout'
            }).then((result) => {
                if (result.isConfirmed) {
                    localStorage.removeItem('sidebarCollapsed');
                    window.location.href = '../logout.php';
                }
            });
        }

        // ================= DRIVER FUNCTIONS =================
        // Load driver details via AJAX
        function loadDriverDetails(driverId) {
            const driverDetailsContent = document.getElementById('driverDetailsContent');
            if (driverDetailsContent) {
                driverDetailsContent.innerHTML = '<div class="text-center py-4"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div><p class="mt-2">Loading driver details...</p></div>';
            }
            
            fetch('get_driver_details.php?driver_id=' + driverId)
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Network response was not ok');
                    }
                    return response.text();
                })
                .then(data => {
                    if (driverDetailsContent) {
                        driverDetailsContent.innerHTML = data;
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    if (driverDetailsContent) {
                        driverDetailsContent.innerHTML = '<div class="alert alert-danger"><i class="bi bi-exclamation-triangle"></i> Failed to load driver details. Please try again.</div>';
                    }
                });
        }

        // Filter table function
        function filterTable() {
            const searchInput = document.getElementById('searchInput');
            const statusFilter = document.getElementById('statusFilter');
            const vehicleTypeFilter = document.getElementById('vehicleTypeFilter');
            const rows = document.querySelectorAll('tbody tr');
            
            const searchText = searchInput ? searchInput.value.toLowerCase() : '';
            const statusValue = statusFilter ? statusFilter.value.toLowerCase() : '';
            const vehicleTypeText = vehicleTypeFilter ? vehicleTypeFilter.value.toLowerCase() : '';
            
            rows.forEach(row => {
                let showRow = true;
                
                // Search filter (name, license, contact)
                if (searchText) {
                    const text = row.textContent.toLowerCase();
                    showRow = text.includes(searchText);
                }
                
                // Status filter
                if (showRow && statusValue) {
                    const statusCell = row.cells[6]; // Status is in column 6
                    const status = statusCell ? statusCell.textContent.toLowerCase() : '';
                    showRow = status.includes(statusValue);
                }
                
                // Vehicle type filter
                if (showRow && vehicleTypeText) {
                    const vehicleType = row.dataset.vehicleType || '';
                    showRow = vehicleType.includes(vehicleTypeText);
                }
                
                row.style.display = showRow ? '' : 'none';
            });
        }
        
        // Clear all filters
        function clearFilters() {
            const searchInput = document.getElementById('searchInput');
            const statusFilter = document.getElementById('statusFilter');
            const vehicleTypeFilter = document.getElementById('vehicleTypeFilter');
            
            if (searchInput) searchInput.value = '';
            if (statusFilter) statusFilter.value = '';
            if (vehicleTypeFilter) vehicleTypeFilter.value = '';
            
            filterTable();
        }

        // Setup event listeners
        function setupEventListeners() {
            // Search functionality
            const searchInput = document.getElementById('searchInput');
            if (searchInput) {
                searchInput.addEventListener('keyup', filterTable);
            }

            // Status filter
            const statusFilter = document.getElementById('statusFilter');
            if (statusFilter) {
                statusFilter.addEventListener('change', filterTable);
            }
            
            // Vehicle type filter
            const vehicleTypeFilter = document.getElementById('vehicleTypeFilter');
            if (vehicleTypeFilter) {
                vehicleTypeFilter.addEventListener('keyup', filterTable);
            }
        }

        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            console.log("Drivers Management page loaded!");
            
            // Initialize sidebar
            initializeSidebar();
            
            // Initialize mobile navigation
            initMobileNav();
            
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
            window.addEventListener('resize', function() {
                handleSidebarResize();
                initMobileNav();
            });

            // Setup event listeners
            setupEventListeners();
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
            // Escape to close modal
            else if (e.key === 'Escape') {
                const profileModal = document.getElementById('profileModal');
                if (profileModal.classList.contains('show')) {
                    bootstrap.Modal.getInstance(profileModal).hide();
                }
            }
            // Ctrl + F to focus search
            else if (e.ctrlKey && e.key === 'f') {
                e.preventDefault();
                const searchInput = document.getElementById('searchInput');
                if (searchInput) {
                    searchInput.focus();
                }
            }
            // Ctrl + C to clear filters
            else if (e.ctrlKey && e.key === 'c') {
                e.preventDefault();
                clearFilters();
            }
        });
    </script>
</body>
</html>