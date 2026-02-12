<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Trip Tickets - Warehouse</title>
    <link rel="stylesheet" href="../css/delivery.css">
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
    <?php
    session_start();
    require_once '../config/database.php';
    
    // Check if user is logged in
    if (!isset($_SESSION['user_id'])) {
        header("Location: ../login.php");
        exit();
    }
    
    // Handle form submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_trip') {
        // Generate trip number
        $prefix = "TRP" . date("Ymd");
        $random = rand(1000, 9999);
        $trip_number = $prefix . $random;
        
        // Get form data
        $driver_id = $_POST['driver_id'];
        $branch_id = $_POST['branch_id'];
        $trip_date = $_POST['trip_date'];
        $trip_status = $_POST['trip_status'];
        $total_stops = $_POST['total_stops'] ?: 0;
        $created_by = $_SESSION['user_id'];
        $remarks = $_POST['remarks'] ?: NULL;
        
        // Insert into database
        $stmt = $conn->prepare("INSERT INTO trip_tickets (trip_number, driver_id, branch_id, trip_date, trip_status, total_stops, created_by, remarks) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("siisssis", $trip_number, $driver_id, $branch_id, $trip_date, $trip_status, $total_stops, $created_by, $remarks);
        
        if ($stmt->execute()) {
            $success_message = "Trip ticket created successfully!";
        } else {
            $error_message = "Error creating trip ticket: " . $conn->error;
        }
        $stmt->close();
    }
    ?>

    <!-- MAIN APPLICATION -->
    <div id="appPage">
<!-- Sidebar -->
<div class="sidebar" id="sidebar">
    <div class="sidebar-header">
         <h3>
                    <!-- Burger icon moved before logo -->
                    <button class="desktop-toggle-btn" id="desktopToggleBtn">
                        <i class="bi bi-list" id="toggleIcon"></i>
                    </button>
                    <img src="../Pictures/amgc3DLogo.png" alt="Logo" class="logo-icon"> 
                    <span class="nav-text">Delivery</span>
                </h3>
    </div>
    
    <div class="sidebar-menu">
        <ul class="nav flex-column">
            <li class="nav-item">
                <a class="nav-link" href="fordelivery.php">
                    <i class="bi bi-truck"></i>
                    <span class="nav-text">For Delivery</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link active" href="trip_tickets.php">
                    <i class="bi bi-ticket"></i>
                    <span class="nav-text">Trip Tickets</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="rejecteddelivery.php">
                    <i class="bi bi-exclamation-circle"></i>
                    <span class="nav-text">Rejected Delivery Advice</span>
                </a>
            </li>
        </ul>    
    </div>
    <!-- User Profile Section at the bottom of sidebar -->
            <div class="sidebar-footer">
                <div class="user-profile-sidebar">
                    <div class="user-avatar-sidebar"><?php echo substr($_SESSION['first_name'] ?? 'WM', 0, 2); ?></div>
                    <div class="user-details-sidebar">
                        <span class="user-name-sidebar"><?php echo isset($_SESSION['first_name']) ? $_SESSION['first_name'] . ' ' . $_SESSION['last_name'] : 'Warehouse Manager'; ?></span>
                        <span class="user-role-sidebar"><?php echo isset($_SESSION['role']) ? ucfirst($_SESSION['role']) : 'Warehouse'; ?></span>
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
                    <h2><i class="bi bi-ticket me-2"></i>Trip Tickets</h2>
                    <p>Track and manage delivery trip tickets</p>
                </div>
            </div>

            <?php
            // Get trip ticket statistics
            $stats = [];
            
            // Total Trips
            $total_trips_query = "SELECT COUNT(*) as count FROM trip_tickets";
            $result = $conn->query($total_trips_query);
            $stats['total_trips'] = $result->fetch_assoc()['count'] ?? 0;
            
            // Completed Trips
            $completed_trips_query = "SELECT COUNT(*) as count FROM trip_tickets WHERE trip_status = 'completed'";
            $result = $conn->query($completed_trips_query);
            $stats['completed'] = $result->fetch_assoc()['count'] ?? 0;
            
            // In Transit
            $in_transit_query = "SELECT COUNT(*) as count FROM trip_tickets WHERE trip_status = 'in-progress'";
            $result = $conn->query($in_transit_query);
            $stats['in_transit'] = $result->fetch_assoc()['count'] ?? 0;
            
            // Pending
            $pending_query = "SELECT COUNT(*) as count FROM trip_tickets WHERE trip_status = 'planned'";
            $result = $conn->query($pending_query);
            $stats['pending'] = $result->fetch_assoc()['count'] ?? 0;
            ?>

            <!-- Success/Error Messages -->
            <?php if (isset($success_message)): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <?php echo $success_message; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <?php if (isset($error_message)): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <?php echo $error_message; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Trip Tickets Stats -->
            <div class="row g-3 mb-4">
                <!-- Total Trips -->
                <div class="col-md-3 mb-3">
                    <div class="stat-card inventory">
                        <div class="stat-icon">
                            <i class="bi bi-ticket"></i>
                        </div>
                        <div>
                            <div class="stat-value"><?php echo $stats['total_trips']; ?></div>
                            <div class="stat-label">Total Trips</div>
                        </div>
                    </div>
                </div>

                <!-- Completed -->
                <div class="col-md-3 mb-3">
                    <div class="stat-card sales">
                        <div class="stat-icon">
                            <i class="bi bi-check-circle"></i>
                        </div>
                        <div>
                            <div class="stat-value"><?php echo $stats['completed']; ?></div>
                            <div class="stat-label">Completed</div>
                        </div>
                    </div>
                </div>

                <!-- In Transit -->
                <div class="col-md-3 mb-3">
                    <div class="stat-card pending">
                        <div class="stat-icon">
                            <i class="bi bi-arrow-right-circle"></i>
                        </div>
                        <div>
                            <div class="stat-value"><?php echo $stats['in_transit']; ?></div>
                            <div class="stat-label">In Transit</div>
                        </div>
                    </div>
                </div>

                <!-- Pending -->
                <div class="col-md-3 mb-3">
                    <div class="stat-card delivery">
                        <div class="stat-icon">
                            <i class="bi bi-exclamation-circle"></i>
                        </div>
                        <div>
                            <div class="stat-value"><?php echo $stats['pending']; ?></div>
                            <div class="stat-label">Pending</div>
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
                                <input type="text" class="form-control" id="searchInput" placeholder="Search by ticket ID, driver, or destination...">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <select class="form-select" id="statusFilter">
                                <option value="">All Status</option>
                                <option value="completed">Completed</option>
                                <option value="in-progress">In Transit</option>
                                <option value="planned">Pending</option>
                                <option value="delayed">Delayed</option>
                                <option value="cancelled">Cancelled</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Trip Tickets Table -->
            <div class="card">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Ticket Number</th>
                                <th>Driver Name</th>
                                <th>Branch</th>
                                <th>Trip Date</th>
                                <th>Status</th>
                                <th>Total Stops</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $trip_tickets_query = "SELECT tt.*, d.driver_name, b.branch_name 
                                                  FROM trip_tickets tt
                                                  LEFT JOIN drivers d ON tt.driver_id = d.driver_id
                                                  LEFT JOIN branches b ON tt.branch_id = b.branch_id
                                                  ORDER BY tt.created_at DESC";
                            $result = $conn->query($trip_tickets_query);
                            
                            if ($result->num_rows > 0) {
                                while($row = $result->fetch_assoc()) {
                                    $status_badge = '';
                                    switch($row['trip_status']) {
                                        case 'completed': $status_badge = 'bg-success'; break;
                                        case 'in-progress': $status_badge = 'bg-warning'; break;
                                        case 'cancelled': $status_badge = 'bg-danger'; break;
                                        case 'delayed': $status_badge = 'bg-info'; break;
                                        default: $status_badge = 'bg-secondary';
                                    }
                                    ?>
                                    <tr>
                                        <td><span class="badge bg-light text-dark"><?php echo $row['trip_number']; ?></span></td>
                                        <td><?php echo $row['driver_name'] ?? 'N/A'; ?></td>
                                        <td><?php echo $row['branch_name'] ?? 'N/A'; ?></td>
                                        <td><?php echo date('Y-m-d', strtotime($row['trip_date'])); ?></td>
                                        <td><span class="badge <?php echo $status_badge; ?>"><?php echo ucfirst(str_replace('-', ' ', $row['trip_status'])); ?></span></td>
                                        <td><?php echo $row['total_stops'] ?? 0; ?></td>
                                        <td>
                                            <button class="btn btn-sm btn-info" data-bs-toggle="modal" data-bs-target="#viewDetailsModal" 
                                                    onclick="loadTripDetails('<?php echo $row['trip_id']; ?>')">
                                                <i class="bi bi-eye"></i> View
                                            </button>
                                        </td>
                                    </tr>
                                    <?php
                                }
                            } else {
                                echo '<tr><td colspan="7" class="text-center">No trip tickets found</td></tr>';
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Trip Ticket Modal -->
    <div class="modal fade" id="addTicketModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Create New Trip Ticket</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="addTicketForm" method="POST">
                    <input type="hidden" name="action" value="add_trip">
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Driver <span class="text-danger">*</span></label>
                                <select class="form-select" name="driver_id" required>
                                    <option value="">Select Driver</option>
                                    <?php
                                    $drivers_query = "SELECT driver_id, driver_name FROM drivers WHERE status = 'active'";
                                    $result = $conn->query($drivers_query);
                                    while($driver = $result->fetch_assoc()) {
                                        echo '<option value="' . $driver['driver_id'] . '">' . $driver['driver_name'] . '</option>';
                                    }
                                    ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Branch <span class="text-danger">*</span></label>
                                <select class="form-select" name="branch_id" required>
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
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Trip Date <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" name="trip_date" required value="<?php echo date('Y-m-d'); ?>">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Status <span class="text-danger">*</span></label>
                                <select class="form-select" name="trip_status" required>
                                    <option value="planned" selected>Planned</option>
                                    <option value="in-progress">In Progress</option>
                                    <option value="completed">Completed</option>
                                    <option value="delayed">Delayed</option>
                                    <option value="cancelled">Cancelled</option>
                                </select>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Total Stops</label>
                                <input type="number" class="form-control" name="total_stops" placeholder="Number of stops" min="0" value="0">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Created By</label>
                                <input type="text" class="form-control" value="<?php echo isset($_SESSION['first_name']) ? $_SESSION['first_name'] . ' ' . $_SESSION['last_name'] : 'Current User'; ?>" readonly>
                                <small class="text-muted">Auto-filled from your session</small>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Remarks</label>
                            <textarea class="form-control" name="remarks" placeholder="Additional notes..." rows="3"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Create Ticket</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- View Details Modal -->
    <div class="modal fade" id="viewDetailsModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Trip Ticket Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="tripDetailsContent">
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

        // Load trip details via AJAX
        function loadTripDetails(tripId) {
            // You'll need to create a PHP file named get_trip_details.php to handle this
            fetch('get_trip_details.php?trip_id=' + tripId)
                .then(response => response.text())
                .then(data => {
                    document.getElementById('tripDetailsContent').innerHTML = data;
                })
                .catch(error => {
                    console.error('Error:', error);
                    document.getElementById('tripDetailsContent').innerHTML = '<div class="alert alert-danger">Failed to load trip details</div>';
                });
        }

        // Search functionality
        const searchInput = document.getElementById('searchInput');
        if (searchInput) {
            searchInput.addEventListener('keyup', function() {
                const filter = this.value.toLowerCase();
                const rows = document.querySelectorAll('tbody tr');
                
                rows.forEach(row => {
                    const text = row.textContent.toLowerCase();
                    row.style.display = text.includes(filter) ? '' : 'none';
                });
            });
        }

        // Status filter
        const statusFilter = document.getElementById('statusFilter');
        if (statusFilter) {
            statusFilter.addEventListener('change', function() {
                const filter = this.value.toLowerCase();
                const rows = document.querySelectorAll('tbody tr');
                
                rows.forEach(row => {
                    const statusCell = row.cells[4];
                    if (statusCell) {
                        const status = statusCell.textContent.toLowerCase();
                        const statusValue = status.replace('in transit', 'in-progress').trim();
                        row.style.display = (filter === '' || statusValue === filter) ? '' : 'none';
                    }
                });
            });
        }

        // Logout function
        function logout() {
            if (confirm('Are you sure you want to logout?')) {
                window.location.href = '../logout.php';
            }
        }

        // Form validation
        const addTicketForm = document.getElementById('addTicketForm');
        if (addTicketForm) {
            addTicketForm.addEventListener('submit', function(e) {
                const driverSelect = this.querySelector('select[name="driver_id"]');
                const branchSelect = this.querySelector('select[name="branch_id"]');
                const tripDate = this.querySelector('input[name="trip_date"]');
                
                if (!driverSelect.value) {
                    e.preventDefault();
                    alert('Please select a driver');
                    driverSelect.focus();
                    return false;
                }
                
                if (!branchSelect.value) {
                    e.preventDefault();
                    alert('Please select a branch');
                    branchSelect.focus();
                    return false;
                }
                
                if (!tripDate.value) {
                    e.preventDefault();
                    alert('Please select a trip date');
                    tripDate.focus();
                    return false;
                }
            });
        }

        // Initialize when page loads
        document.addEventListener('DOMContentLoaded', function() {
            console.log("Trip Tickets page loaded!");
            
            // Initialize sidebar
            initializeSidebar();
            
            // Setup mobile toggle button - support multiple button IDs
            const mobileToggleBtn = document.getElementById('mobileToggleBtn');
            if (mobileToggleBtn) {
                mobileToggleBtn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    toggleSidebar();
                });
            }
            
            const mobileMenuBtn = document.getElementById('mobileMenuBtn');
            if (mobileMenuBtn) {
                mobileMenuBtn.addEventListener('click', function(e) {
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
                const mobileBtn = document.getElementById('mobileToggleBtn') || document.getElementById('mobileMenuBtn');
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
            // Ctrl + F to focus search
            else if (e.ctrlKey && e.key === 'f') {
                e.preventDefault();
                const searchInput = document.getElementById('searchInput');
                if (searchInput) {
                    searchInput.focus();
                }
            }
        });
    </script>
</body>
</html>