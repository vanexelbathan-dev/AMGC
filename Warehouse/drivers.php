<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Drivers - Warehouse</title>
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
                        <a class="nav-link active" href="drivers.php">
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
                    <h2><i class="bi bi-person-badge me-2"></i>Driver Management</h2>
                    <p>Manage driver information and track delivery performance</p>
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
            
            // Get driver statistics
            $stats = [];
            
            // Total Drivers
            $total_drivers_query = "SELECT COUNT(*) as count FROM drivers";
            $result = $conn->query($total_drivers_query);
            $stats['total_drivers'] = $result->fetch_assoc()['count'] ?? 0;
            
            // Active Drivers
            $active_drivers_query = "SELECT COUNT(*) as count FROM drivers WHERE status = 'active'";
            $result = $conn->query($active_drivers_query);
            $stats['active_drivers'] = $result->fetch_assoc()['count'] ?? 0;
            
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

            <!-- Search and Filter -->
            <div class="card mb-4">
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="input-group">
                                <span class="input-group-text">
                                    <i class="bi bi-search"></i>
                                </span>
                                <input type="text" class="form-control" id="searchInput" placeholder="Search by name or driver ID...">
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
                            <button class="btn btn-primary w-100" data-bs-toggle="modal" data-bs-target="#addDriverModal">
                                <i class="bi bi-plus-lg"></i> Add Driver
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Drivers Table -->
            <div class="card">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
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
                                             LEFT JOIN branches b ON d.branch_id = b.branch_id
                                             ORDER BY d.driver_name";
                            $result = $conn->query($drivers_query);
                            
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
                                    <tr>
                                        <td><?php echo $row['driver_name']; ?></td>
                                        <td><?php echo $row['license_number']; ?></td>
                                        <td><?php echo $row['contact_number'] ?? 'N/A'; ?></td>
                                        <td><?php echo $row['vehicle_type'] ?? 'N/A'; ?></td>
                                        <td><?php echo $row['vehicle_plate_number'] ?? 'N/A'; ?></td>
                                        <td><?php echo $row['branch_name'] ?? 'N/A'; ?></td>
                                        <td><span class="badge <?php echo $status_badge; ?>"><?php echo ucfirst(str_replace('-', ' ', $row['status'])); ?></span></td>
                                        <td>
                                            <button class="btn btn-sm btn-info" data-bs-toggle="modal" data-bs-target="#viewDriverModal" 
                                                    onclick="loadDriverDetails('<?php echo $row['driver_id']; ?>')">
                                                <i class="bi bi-eye"></i> View
                                            </button>
                                        </td>
                                    </tr>
                                    <?php
                                }
                            } else {
                                echo '<tr><td colspan="8" class="text-center">No drivers found</td></tr>';
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Driver Modal -->
    <div class="modal fade" id="addDriverModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Add New Driver</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="addDriverForm" action="add_driver.php" method="POST">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Driver Name</label>
                                <input type="text" class="form-control" name="driver_name" required placeholder="Full name">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">License Number</label>
                                <input type="text" class="form-control" name="license_number" required placeholder="e.g., DL-123456">
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Contact Number</label>
                                <input type="tel" class="form-control" name="contact_number" placeholder="Phone number">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">License Expiry Date</label>
                                <input type="date" class="form-control" name="license_expiry">
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Vehicle Type</label>
                                <input type="text" class="form-control" name="vehicle_type" placeholder="e.g., Van, Truck">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Vehicle Plate Number</label>
                                <input type="text" class="form-control" name="vehicle_plate_number" placeholder="e.g., ABC-1234">
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Branch</label>
                                <select class="form-select" name="branch_id">
                                    <option value="">Select Branch</option>
                                    <?php
                                    $branches_query = "SELECT branch_id, branch_name FROM branches WHERE status = 'active'";
                                    $result = $conn->query($branches_query);
                                    while($branch = $result->fetch_assoc()) {
                                        echo '<option value="' . $branch['branch_id'] . '">' . $branch['branch_name'] . '</option>';
                                    }
                                    ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Status</label>
                                <select class="form-select" name="status" required>
                                    <option value="active" selected>Active</option>
                                    <option value="inactive">Inactive</option>
                                    <option value="on-leave">On Leave</option>
                                </select>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" form="addDriverForm" class="btn btn-primary">Add Driver</button>
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
        // Mobile menu toggle
        document.getElementById('mobileMenuBtn').addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('show');
        });

        // Load driver details via AJAX
        function loadDriverDetails(driverId) {
            fetch('get_driver_details.php?driver_id=' + driverId)
                .then(response => response.text())
                .then(data => {
                    document.getElementById('driverDetailsContent').innerHTML = data;
                })
                .catch(error => {
                    console.error('Error:', error);
                    document.getElementById('driverDetailsContent').innerHTML = '<div class="alert alert-danger">Failed to load driver details</div>';
                });
        }

        // Search functionality
        document.getElementById('searchInput').addEventListener('keyup', function() {
            const filter = this.value.toLowerCase();
            const rows = document.querySelectorAll('tbody tr');
            
            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(filter) ? '' : 'none';
            });
        });

        // Status filter
        document.getElementById('statusFilter').addEventListener('change', function() {
            const filter = this.value.toLowerCase();
            const rows = document.querySelectorAll('tbody tr');
            
            rows.forEach(row => {
                const status = row.cells[6].textContent.toLowerCase();
                row.style.display = (filter === '' || status.includes(filter)) ? '' : 'none';
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