<?php
require_once '../config/database.php';
require_once '../config/session_handler.php';

// Check login
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

// ================= USER CONTEXT =================
$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['first_name'] . ' ' . $_SESSION['last_name'];
$user_role = $_SESSION['role'];
$branch_id = $_SESSION['branch_id'];
$view_all_branches = $_SESSION['view_all_branches'] ?? false;

// ================= GET DRIVER ID FOR DELIVERY ROLE =================
$driver_id = 0;
if ($user_role == 'delivery') {
    // Try to get driver_id from session first
    $driver_id = $_SESSION['driver_id'] ?? 0;
    
    // If not in session, get from users table
    if ($driver_id == 0) {
        $driver_query = "SELECT driver_id FROM users WHERE user_id = ? AND driver_id IS NOT NULL";
        $driver_stmt = $conn->prepare($driver_query);
        $driver_stmt->bind_param("i", $user_id);
        $driver_stmt->execute();
        $driver_result = $driver_stmt->get_result();
        if ($driver_row = $driver_result->fetch_assoc()) {
            $driver_id = $driver_row['driver_id'];
            $_SESSION['driver_id'] = $driver_id;
        }
        $driver_stmt->close();
    }
}

// ================= CHECK COLUMNS =================
$tt_branch_column_exists = false;
$check_tt_column = $conn->query("SHOW COLUMNS FROM trip_tickets LIKE 'branch_id'");
if ($check_tt_column && $check_tt_column->num_rows > 0) {
    $tt_branch_column_exists = true;
}

$drivers_branch_column_exists = false;
$check_drivers_column = $conn->query("SHOW COLUMNS FROM drivers LIKE 'branch_id'");
if ($check_drivers_column && $check_drivers_column->num_rows > 0) {
    $drivers_branch_column_exists = true;
}

// ================= ADD TRIP =================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_trip') {

    $prefix = "TRP" . date("Ymd");
    $random = rand(1000, 9999);
    $trip_number = $prefix . $random;

    $driver_id_post = $_POST['driver_id'];
    $trip_date = $_POST['trip_date'];
    $trip_status = $_POST['trip_status'];
    $total_stops = $_POST['total_stops'] ?: 0;
    $created_by = $user_id;
    $remarks = $_POST['remarks'] ?: NULL;

    // Always use session branch_id for security
    $insert_branch_id = $branch_id;

    $stmt = $conn->prepare("
        INSERT INTO trip_tickets 
        (trip_number, driver_id, branch_id, trip_date, trip_status, total_stops, created_by, remarks) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $stmt->bind_param("siisssis",
        $trip_number,
        $driver_id_post,
        $insert_branch_id,
        $trip_date,
        $trip_status,
        $total_stops,
        $created_by,
        $remarks
    );

    if ($stmt->execute()) {
        $success_message = "Trip ticket created successfully!";
    } else {
        $error_message = "Error: " . $stmt->error;
    }

    $stmt->close();
}

// ================= STATISTICS =================
// Use the same filters for statistics
$stats_where = "WHERE 1=1";

if (!$view_all_branches && $branch_id > 0) {
    $stats_where .= " AND tt.branch_id = $branch_id";
}

// Add driver filter for delivery role - FILTER SA TRIP_TICKETS TABLE
if ($user_role == 'delivery' && $driver_id > 0) {
    $stats_where .= " AND tt.driver_id = $driver_id";
}

// Get total trips
$stats_query = "
    SELECT 
        COUNT(DISTINCT tt.trip_id) as total_trips,
        SUM(CASE WHEN tt.trip_status = 'completed' THEN 1 ELSE 0 END) as completed,
        SUM(CASE WHEN tt.trip_status = 'in-progress' THEN 1 ELSE 0 END) as in_transit,
        SUM(CASE WHEN tt.trip_status = 'planned' THEN 1 ELSE 0 END) as pending
    FROM trip_tickets tt
    $stats_where
";

$stats_result = $conn->query($stats_query);
$stats = $stats_result->fetch_assoc();

// ================= DRIVERS =================
$drivers_query = "
    SELECT driver_id, driver_name 
    FROM drivers 
    WHERE status = 'active'
";

if ($drivers_branch_column_exists && !$view_all_branches && $branch_id > 0) {
    $drivers_query .= " AND branch_id = $branch_id";
}

// For delivery role, only show their own driver
if ($user_role == 'delivery' && $driver_id > 0) {
    $drivers_query .= " AND driver_id = $driver_id";
}

$drivers_result = $conn->query($drivers_query);
$drivers = $drivers_result ? $drivers_result->fetch_all(MYSQLI_ASSOC) : [];

// ================= BRANCH DROPDOWN =================
$branches_query = "
    SELECT branch_id, branch_name 
    FROM branches
";

if (!$view_all_branches && $branch_id > 0) {
    $branches_query .= " WHERE branch_id = $branch_id";
}

$branches_result = $conn->query($branches_query);
$branches = $branches_result ? $branches_result->fetch_all(MYSQLI_ASSOC) : [];

// ================= TRIP LIST - FIXED: FILTER SA TRIP_TICKETS.DRIVER_ID =================
$trip_tickets_query = "
    SELECT 
        tt.*, 
        -- Get driver info from drivers table
        d.driver_name,
        d.license_number,
        d.contact_number,
        d.vehicle_type,
        d.vehicle_plate_number,
        b.branch_name,
        pl.pick_list_id,
        pl.pick_list_number,
        pl.so_id,
        so.so_number,
        c.customer_name,
        c.address,
        c.city,
        -- Count deliveries for this trip
        COUNT(DISTINCT d2.delivery_id) as delivery_count,
        SUM(CASE WHEN d2.delivery_status = 'pending' THEN 1 ELSE 0 END) as pending_deliveries,
        SUM(CASE WHEN d2.delivery_status = 'delivered' THEN 1 ELSE 0 END) as delivered_count,
        SUM(CASE WHEN d2.delivery_status = 'in-transit' THEN 1 ELSE 0 END) as in_transit_deliveries,
        SUM(CASE WHEN d2.delivery_status = 'partial' THEN 1 ELSE 0 END) as partial_deliveries
    FROM trip_tickets tt
    LEFT JOIN drivers d ON tt.driver_id = d.driver_id
    LEFT JOIN branches b ON tt.branch_id = b.branch_id
    LEFT JOIN pick_lists pl ON tt.picklist_id = pl.pick_list_id
    LEFT JOIN sales_orders so ON pl.so_id = so.so_id
    LEFT JOIN customers c ON so.customer_id = c.customer_id
    LEFT JOIN deliveries d2 ON tt.trip_id = d2.trip_id
    WHERE 1=1
";

// Add branch filter
if (!$view_all_branches && $branch_id > 0) {
    $trip_tickets_query .= " AND tt.branch_id = $branch_id";
}

// Add driver filter for delivery role - ITO ANG PINAKA-IMPORTANTE!
// Filter by driver_id sa trip_tickets table mismo
if ($user_role == 'delivery' && $driver_id > 0) {
    $trip_tickets_query .= " AND tt.driver_id = $driver_id";
}

$trip_tickets_query .= " GROUP BY tt.trip_id ORDER BY tt.created_at DESC";

$result = $conn->query($trip_tickets_query);

if (!$result) {
    // Log error para malaman kung may mali sa query
    error_log("SQL Error in trip_tickets.php: " . $conn->error);
    error_log("Query: " . $trip_tickets_query);
    $trip_tickets = [];
} else {
    $trip_tickets = $result->fetch_all(MYSQLI_ASSOC);
}

// Debug: Check kung may nakuha na trip tickets
if ($user_role == 'delivery') {
    error_log("Delivery Role - Driver ID: " . $driver_id);
    error_log("Number of trip tickets found: " . count($trip_tickets));
}

// ================= GET ALL DELIVERIES FOR EACH TRIP =================
$trip_deliveries = [];
if (!empty($trip_tickets)) {
    $trip_ids = array_column($trip_tickets, 'trip_id');
    $trip_ids_str = implode(',', $trip_ids);
    
    $deliveries_query = "
        SELECT 
            d.*,
            c.customer_name,
            c.address,
            c.city
        FROM deliveries d
        LEFT JOIN customers c ON d.customer_id = c.customer_id
        WHERE d.trip_id IN ($trip_ids_str)
        ORDER BY d.stop_sequence ASC, d.delivery_id ASC
    ";
    
    $deliveries_result = $conn->query($deliveries_query);
    if ($deliveries_result) {
        while ($delivery = $deliveries_result->fetch_assoc()) {
            $trip_deliveries[$delivery['trip_id']][] = $delivery;
        }
    }
}

// ================= GET DRIVER INFO FOR DISPLAY =================
$driver_info = null;
if ($user_role == 'delivery' && $driver_id > 0) {
    $driver_info_query = "SELECT * FROM drivers WHERE driver_id = ?";
    $driver_info_stmt = $conn->prepare($driver_info_query);
    $driver_info_stmt->bind_param("i", $driver_id);
    $driver_info_stmt->execute();
    $driver_info_result = $driver_info_stmt->get_result();
    $driver_info = $driver_info_result->fetch_assoc();
    $driver_info_stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Trip Tickets - Delivery Management</title>
    <link rel="icon" type="image/png" href="../Pictures/favicon-96x96.png" sizes="96x96" />
    <link rel="icon" type="image/svg+xml" href="../Pictures/favicon.svg" />
    <link rel="shortcut icon" href="../Pictures/favicon.ico" />
    <link rel="apple-touch-icon" sizes="180x180" href="../Pictures/apple-touch-icon.png" />
    <link rel="manifest" href="../Pictures/site.webmanifest" />
    <link rel="stylesheet" href="../css/delivery.css">
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <style>
        /* Branch badge styling */
        .branch-badge {
            background-color: #e7f1ff;
            color: #0d6efd;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
            margin-left: 5px;
        }
        
        /* Driver info card */
        .driver-info-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 20px;
        }
        
        .driver-info-card h5 {
            color: white;
            border-bottom: 1px solid rgba(255,255,255,0.3);
            padding-bottom: 10px;
        }
        
        .driver-info-card .info-label {
            color: rgba(255,255,255,0.8);
            font-size: 0.9rem;
        }
        
        .driver-info-card .info-value {
            color: white;
            font-weight: 600;
        }
        
        /* Alert for missing branch column */
        .alert-info {
            background-color: #d1ecf1;
            border-color: #bee5eb;
            color: #0c5460;
        }
        
        .alert-info code {
            background-color: #f8f9fa;
            padding: 2px 4px;
            border-radius: 4px;
            color: #c7254e;
        }
        
        /* Mobile responsive adjustments */
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
        
        /* Driver badge styling */
        .driver-badge {
            display: inline-block;
            padding: 4px 10px;
            background-color: #e8f4fd;
            color: #084298;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
            border-left: 3px solid #0d6efd;
        }
        
        .driver-badge i {
            margin-right: 4px;
            color: #0d6efd;
        }
        
        .picklist-indicator {
            font-size: 10px;
            color: #6c757d;
            display: block;
        }
        
        /* Status badges */
        .status-badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
            min-width: 90px;
            text-align: center;
        }
        
        /* Customer info */
        .customer-info {
            font-size: 12px;
            color: #6c757d;
        }
        
        /* Delivery count badge */
        .delivery-count {
            background-color: #0d6efd;
            color: white;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 10px;
            margin-left: 5px;
        }
        
        /* Trip card styling */
        .trip-card {
            border: 1px solid #dee2e6;
            border-radius: 8px;
            margin-bottom: 15px;
            background-color: white;
        }
        
        .trip-card-header {
            background-color: #f8f9fa;
            padding: 12px 15px;
            border-bottom: 1px solid #dee2e6;
            border-radius: 8px 8px 0 0;
            cursor: pointer;
        }
        
        .trip-card-header:hover {
            background-color: #e9ecef;
        }
        
        .trip-card-body {
            padding: 15px;
        }
        
        .delivery-item {
            padding: 10px;
            border-left: 3px solid #0d6efd;
            margin-bottom: 10px;
            background-color: #f8f9fa;
            border-radius: 4px;
        }
        
        .delivery-item.pending {
            border-left-color: #ffc107;
        }
        
        .delivery-item.delivered {
            border-left-color: #198754;
            background-color: #d1e7dd;
        }
        
        .delivery-item.partial {
            border-left-color: #0dcaf0;
        }
        
        .delivery-item.in-transit {
            border-left-color: #0d6efd;
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
                    <div class="user-avatar-sidebar"><?php echo substr($user_name, 0, 2); ?></div>
                    <div class="user-details-sidebar">
                        <span class="user-name-sidebar"><?php echo htmlspecialchars($user_name); ?></span>
                        <?php if ($driver_info): ?>
                            <span class="user-role-sidebar">Driver: <?php echo htmlspecialchars($driver_info['driver_name']); ?></span>
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
            <!-- Header Section -->
            <div class="navbar-top">
                <button class="mobile-toggle-btn" id="mobileToggleBtn">
                    <i class="bi bi-list"></i>
                </button>
                <div class="page-title">
                    <h2><i class="bi bi-ticket me-2"></i>Trip Tickets</h2>
                    <p>Track and manage delivery trip tickets</p>
                </div>
                <?php if ($user_role != 'delivery'): ?>
                <div class="ms-auto me-3">
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addTicketModal">
                        <i class="bi bi-plus-circle me-1"></i> New Trip Ticket
                    </button>
                </div>
                <?php endif; ?>
            </div>

            <!-- Driver Info Card (for delivery role) -->
            <?php if ($user_role == 'delivery' && $driver_info): ?>
            <div class="driver-info-card">
                <div class="row">
                    <div class="col-md-3">
                        <h5><i class="bi bi-truck"></i> Your Driver Details</h5>
                    </div>
                    <div class="col-md-9">
                        <div class="row">
                            <div class="col-md-3">
                                <div class="info-label">Driver Name</div>
                                <div class="info-value"><?php echo htmlspecialchars($driver_info['driver_name']); ?></div>
                            </div>
                            <div class="col-md-3">
                                <div class="info-label">License</div>
                                <div class="info-value"><?php echo htmlspecialchars($driver_info['license_number']); ?></div>
                            </div>
                            <div class="col-md-3">
                                <div class="info-label">Vehicle</div>
                                <div class="info-value"><?php echo htmlspecialchars($driver_info['vehicle_type']); ?></div>
                            </div>
                            <div class="col-md-3">
                                <div class="info-label">Plate Number</div>
                                <div class="info-value"><?php echo htmlspecialchars($driver_info['vehicle_plate_number']); ?></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Branch Info Alerts -->
            <?php if (!$tt_branch_column_exists): ?>
                <div class="alert alert-info alert-dismissible fade show" role="alert">
                    <i class="bi bi-info-circle"></i> 
                    <strong>Branch filtering for trip tickets not yet set up.</strong> Please run this SQL in phpMyAdmin to enable branch-specific trip ticket data:
                    <br><br>
                    <code>ALTER TABLE trip_tickets ADD COLUMN branch_id INT NULL;</code>
                    <br>
                    <code>ALTER TABLE trip_tickets ADD FOREIGN KEY (branch_id) REFERENCES branches(branch_id);</code>
                    <br><br>
                    <button type="button" class="btn btn-sm btn-primary" onclick="copySQL('trip_tickets')">
                        <i class="bi bi-files"></i> Copy SQL
                    </button>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if (!$drivers_branch_column_exists): ?>
                <div class="alert alert-info alert-dismissible fade show" role="alert">
                    <i class="bi bi-info-circle"></i> 
                    <strong>Branch filtering for drivers not yet set up.</strong> Please run this SQL in phpMyAdmin to enable branch-specific driver data:
                    <br><br>
                    <code>ALTER TABLE drivers ADD COLUMN branch_id INT NULL;</code>
                    <br>
                    <code>ALTER TABLE drivers ADD FOREIGN KEY (branch_id) REFERENCES branches(branch_id);</code>
                    <br><br>
                    <button type="button" class="btn btn-sm btn-primary" onclick="copySQL('drivers')">
                        <i class="bi bi-files"></i> Copy SQL
                    </button>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

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
                            <div class="stat-value"><?php echo $stats['total_trips'] ?? 0; ?></div>
                            <div class="stat-label">Total Trips</div>
                            <?php if ($user_role == 'delivery'): ?>
                                <small class="text-white-50">Your Trips</small>
                            <?php elseif ($tt_branch_column_exists && !$view_all_branches): ?>
                                <small class="text-white-50">Your Branch</small>
                            <?php endif; ?>
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
                            <div class="stat-value"><?php echo $stats['completed'] ?? 0; ?></div>
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
                            <div class="stat-value"><?php echo $stats['in_transit'] ?? 0; ?></div>
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
                            <div class="stat-value"><?php echo $stats['pending'] ?? 0; ?></div>
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
                                <input type="text" class="form-control" id="searchInput" placeholder="Search by ticket ID, driver, customer...">
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

            <!-- No Trip Tickets Message -->
            <?php if (empty($trip_tickets)): ?>
                <div class="alert alert-info text-center py-4">
                    <i class="bi bi-ticket" style="font-size: 2rem;"></i>
                    <p class="mt-3 mb-0">
                        No trip tickets found.
                        <?php if ($user_role == 'delivery'): ?>
                            <br><small>You don't have any trip tickets assigned yet. Please check with your warehouse supervisor.</small>
                        <?php elseif ($tt_branch_column_exists && !$view_all_branches): ?>
                            <br><small>No trip tickets for your branch yet.</small>
                        <?php endif; ?>
                    </p>
                </div>
            <?php else: ?>

            <!-- Trip Tickets List with Deliveries -->
            <div class="row">
                <?php foreach ($trip_tickets as $row): 
                    $status_badge = '';
                    switch($row['trip_status']) {
                        case 'completed': $status_badge = 'bg-success'; break;
                        case 'in-progress': $status_badge = 'bg-warning text-dark'; break;
                        case 'cancelled': $status_badge = 'bg-danger'; break;
                        case 'delayed': $status_badge = 'bg-info'; break;
                        case 'planned':
                        default: $status_badge = 'bg-secondary';
                    }
                    
                    $driver_display = !empty($row['driver_name']) ? $row['driver_name'] : 'N/A';
                    $customer_display = !empty($row['customer_name']) ? $row['customer_name'] : 'N/A';
                    $deliveries = $trip_deliveries[$row['trip_id']] ?? [];
                    $delivery_count = count($deliveries);
                ?>
                <div class="col-12">
                    <div class="trip-card">
                        <div class="trip-card-header" onclick="toggleTripDetails('trip-<?php echo $row['trip_id']; ?>')">
                            <div class="row align-items-center">
                                <div class="col-md-2">
                                    <span class="badge bg-light text-dark fs-6 p-2"><?php echo htmlspecialchars($row['trip_number']); ?></span>
                                    <?php if (!empty($row['pick_list_number'])): ?>
                                        <br><small class="text-muted">PL: <?php echo $row['pick_list_number']; ?></small>
                                    <?php endif; ?>
                                </div>
                                <div class="col-md-2">
                                    <?php if ($driver_display != 'N/A'): ?>
                                        <span class="driver-badge">
                                            <i class="bi bi-person-badge"></i> <?php echo htmlspecialchars($driver_display); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </div>
                                <div class="col-md-1">
                                    <?php echo htmlspecialchars($row['branch_name'] ?? 'N/A'); ?>
                                </div>
                                <div class="col-md-1">
                                    <?php echo date('Y-m-d', strtotime($row['trip_date'])); ?>
                                </div>
                                <div class="col-md-1">
                                    <span class="badge <?php echo $status_badge; ?>" style="padding: 6px 12px;">
                                        <?php echo ucfirst(str_replace('-', ' ', $row['trip_status'])); ?>
                                    </span>
                                </div>
                                <div class="col-md-3">
                                    <span class="fw-semibold"><?php echo htmlspecialchars($customer_display); ?></span>
                                    <?php if (!empty($row['so_number'])): ?>
                                        <br><small class="text-muted"><?php echo htmlspecialchars($row['so_number']); ?></small>
                                    <?php endif; ?>
                                </div>
                                <div class="col-md-2 text-end">
                                    <span class="badge bg-primary delivery-count">
                                        <i class="bi bi-box"></i> <?php echo $delivery_count; ?> deliveries
                                    </span>
                                    <br>
                                    <?php if ($row['pending_deliveries'] > 0): ?>
                                        <small class="text-warning"><?php echo $row['pending_deliveries']; ?> pending</small>
                                    <?php endif; ?>
                                    <?php if ($row['delivered_count'] > 0): ?>
                                        <small class="text-success"><?php echo $row['delivered_count']; ?> delivered</small>
                                    <?php endif; ?>
                                    <button class="btn btn-sm btn-outline-info ms-2" onclick="loadTripDetails('<?php echo $row['trip_id']; ?>')">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                        <div class="trip-card-body" id="trip-<?php echo $row['trip_id']; ?>" style="display: none;">
                            <h6 class="mb-3"><i class="bi bi-truck"></i> Deliveries for this Trip</h6>
                            <?php if (empty($deliveries)): ?>
                                <p class="text-muted">No deliveries recorded for this trip yet.</p>
                            <?php else: ?>
                                <div class="row">
                                    <?php foreach ($deliveries as $delivery): 
                                        $delivery_status_badge = '';
                                        $status_class = '';
                                        switch($delivery['delivery_status']) {
                                            case 'delivered': 
                                                $delivery_status_badge = 'bg-success'; 
                                                $status_class = 'delivered';
                                                break;
                                            case 'pending': 
                                                $delivery_status_badge = 'bg-warning text-dark'; 
                                                $status_class = 'pending';
                                                break;
                                            case 'in-transit': 
                                                $delivery_status_badge = 'bg-primary'; 
                                                $status_class = 'in-transit';
                                                break;
                                            case 'partial': 
                                                $delivery_status_badge = 'bg-info'; 
                                                $status_class = 'partial';
                                                break;
                                            default: 
                                                $delivery_status_badge = 'bg-secondary';
                                                $status_class = '';
                                        }
                                    ?>
                                    <div class="col-md-6">
                                        <div class="delivery-item <?php echo $status_class; ?>">
                                            <div class="d-flex justify-content-between">
                                                <strong>Stop #<?php echo $delivery['stop_sequence'] ?? 'N/A'; ?></strong>
                                                <span class="badge <?php echo $delivery_status_badge; ?>">
                                                    <?php echo ucfirst($delivery['delivery_status']); ?>
                                                </span>
                                            </div>
                                            <p class="mb-1"><strong><?php echo htmlspecialchars($delivery['customer_name']); ?></strong></p>
                                            <p class="mb-1 small"><?php echo htmlspecialchars($delivery['address'] . ', ' . $delivery['city']); ?></p>
                                            <?php if ($delivery['delivery_status'] == 'delivered' && !empty($delivery['signed_by'])): ?>
                                                <p class="mb-0 small text-success">
                                                    <i class="bi bi-check-circle"></i> Received by: <?php echo htmlspecialchars($delivery['signed_by']); ?>
                                                </p>
                                            <?php endif; ?>
                                            <?php if (!empty($delivery['remarks'])): ?>
                                                <p class="mb-0 small text-muted">
                                                    <i class="bi bi-chat"></i> <?php echo htmlspecialchars($delivery['remarks']); ?>
                                                </p>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Add Trip Ticket Modal (for non-delivery roles only) -->
    <?php if ($user_role != 'delivery'): ?>
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
                        <?php if ($tt_branch_column_exists && !$view_all_branches): ?>
                            <div class="alert alert-info">
                                <i class="bi bi-info-circle"></i> Creating trip ticket for Branch <?php echo $branch_id; ?>
                                <input type="hidden" name="branch_id" value="<?php echo $branch_id; ?>">
                            </div>
                        <?php endif; ?>

                        <?php if (empty($drivers)): ?>
                            <div class="alert alert-warning">
                                <i class="bi bi-exclamation-triangle"></i> No drivers available for your branch.
                                <?php if ($view_all_branches): ?>
                                    Please add drivers first.
                                <?php else: ?>
                                    Please contact admin to assign drivers to your branch.
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Driver <span class="text-danger">*</span></label>
                                <select class="form-select" name="driver_id" required <?php echo empty($drivers) ? 'disabled' : ''; ?>>
                                    <option value="">Select Driver</option>
                                    <?php foreach ($drivers as $driver): ?>
                                        <option value="<?php echo $driver['driver_id']; ?>"><?php echo htmlspecialchars($driver['driver_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Branch <span class="text-danger">*</span></label>
                                <?php if ($tt_branch_column_exists && !$view_all_branches): ?>
                                    <input type="text" class="form-control" value="Branch <?php echo $branch_id; ?>" readonly>
                                    <input type="hidden" name="branch_id" value="<?php echo $branch_id; ?>">
                                <?php else: ?>
                                    <select class="form-select" name="branch_id" required>
                                        <option value="">Select Branch</option>
                                        <?php foreach ($branches as $branch): ?>
                                            <option value="<?php echo $branch['branch_id']; ?>"><?php echo htmlspecialchars($branch['branch_name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php endif; ?>
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
                                <input type="text" class="form-control" value="<?php echo htmlspecialchars($user_name); ?>" readonly>
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
                        <button type="submit" class="btn btn-primary" <?php echo empty($drivers) ? 'disabled' : ''; ?>>Create Ticket</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

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
        // Branch context variables
        const branchId = <?php echo $branch_id; ?>;
        const viewAllBranches = <?php echo $view_all_branches ? 'true' : 'false'; ?>;
        const ttBranchColumnExists = <?php echo $tt_branch_column_exists ? 'true' : 'false'; ?>;
        const driversBranchColumnExists = <?php echo $drivers_branch_column_exists ? 'true' : 'false'; ?>;
        const userRole = '<?php echo $user_role; ?>';
        const driverId = <?php echo $driver_id ?: 0; ?>;

        // ================= SIDEBAR FUNCTIONS =================
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const isMobile = window.innerWidth <= 992;
            
            if (isMobile) {
                sidebar.classList.toggle('active');
                
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
                sidebar.classList.toggle('collapsed');
                localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
                
                document.querySelectorAll('.nav-text').forEach(text => {
                    text.style.display = sidebar.classList.contains('collapsed') ? 'none' : 'inline-block';
                });
                
                const mainContent = document.querySelector('.main-content');
                if (mainContent) {
                    mainContent.style.marginLeft = sidebar.classList.contains('collapsed') ? '80px' : '250px';
                }
            }
        }

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

        function initializeSidebar() {
            const sidebar = document.getElementById('sidebar');
            
            if (window.innerWidth > 992) {
                const savedCollapsed = localStorage.getItem('sidebarCollapsed');
                if (savedCollapsed === 'true') {
                    sidebar.classList.add('collapsed');
                    document.querySelectorAll('.nav-text').forEach(text => {
                        text.style.display = 'none';
                    });
                    
                    const mainContent = document.querySelector('.main-content');
                    if (mainContent) {
                        mainContent.style.marginLeft = '80px';
                    }
                } else {
                    sidebar.classList.remove('collapsed');
                    document.querySelectorAll('.nav-text').forEach(text => {
                        text.style.display = 'inline-block';
                    });
                    
                    const mainContent = document.querySelector('.main-content');
                    if (mainContent) {
                        mainContent.style.marginLeft = '250px';
                    }
                }
            } else {
                sidebar.classList.remove('active');
                sidebar.classList.remove('collapsed');
                document.querySelectorAll('.nav-text').forEach(text => {
                    text.style.display = 'inline-block';
                });
                
                const mainContent = document.querySelector('.main-content');
                if (mainContent) {
                    mainContent.style.marginLeft = '0';
                }
            }
        }

        function handleSidebarResize() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.querySelector('.sidebar-overlay');
            
            if (window.innerWidth > 992) {
                if (overlay) {
                    overlay.remove();
                }
                sidebar.classList.remove('active');
                
                const savedCollapsed = localStorage.getItem('sidebarCollapsed');
                if (savedCollapsed === 'true') {
                    sidebar.classList.add('collapsed');
                    document.querySelectorAll('.nav-text').forEach(text => {
                        text.style.display = 'none';
                    });
                    
                    const mainContent = document.querySelector('.main-content');
                    if (mainContent) {
                        mainContent.style.marginLeft = '80px';
                    }
                } else {
                    sidebar.classList.remove('collapsed');
                    document.querySelectorAll('.nav-text').forEach(text => {
                        text.style.display = 'inline-block';
                    });
                    
                    const mainContent = document.querySelector('.main-content');
                    if (mainContent) {
                        mainContent.style.marginLeft = '250px';
                    }
                }
            } else {
                sidebar.classList.remove('collapsed');
                document.querySelectorAll('.nav-text').forEach(text => {
                    text.style.display = 'inline-block';
                });
                
                const mainContent = document.querySelector('.main-content');
                if (mainContent) {
                    mainContent.style.marginLeft = '0';
                }
            }
        }
        // ================= END SIDEBAR FUNCTIONS =================

        // Toggle trip details visibility
        function toggleTripDetails(tripId) {
            const element = document.getElementById(tripId);
            if (element) {
                if (element.style.display === 'none' || element.style.display === '') {
                    element.style.display = 'block';
                } else {
                    element.style.display = 'none';
                }
            }
        }

        // Load trip details via AJAX
        function loadTripDetails(tripId) {
            fetch('get_trip_details.php?trip_id=' + tripId + '&branch_id=' + branchId + '&view_all=' + viewAllBranches)
                .then(response => response.text())
                .then(data => {
                    document.getElementById('tripDetailsContent').innerHTML = data;
                    const modal = new bootstrap.Modal(document.getElementById('viewDetailsModal'));
                    modal.show();
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
                const cards = document.querySelectorAll('.trip-card');
                
                cards.forEach(card => {
                    const text = card.textContent.toLowerCase();
                    card.style.display = text.includes(filter) ? '' : 'none';
                });
            });
        }

        // Status filter
        const statusFilter = document.getElementById('statusFilter');
        if (statusFilter) {
            statusFilter.addEventListener('change', function() {
                const filter = this.value.toLowerCase();
                const cards = document.querySelectorAll('.trip-card');
                
                cards.forEach(card => {
                    const statusElement = card.querySelector('.badge.bg-success, .badge.bg-warning, .badge.bg-secondary, .badge.bg-info, .badge.bg-danger');
                    if (statusElement) {
                        const status = statusElement.textContent.toLowerCase().trim();
                        card.style.display = (filter === '' || status.includes(filter.replace('-', ' '))) ? '' : 'none';
                    }
                });
            });
        }

        // Copy SQL for database setup
        function copySQL(table) {
            let sql = '';
            if (table === 'trip_tickets') {
                sql = "ALTER TABLE trip_tickets ADD COLUMN branch_id INT NULL;\nALTER TABLE trip_tickets ADD FOREIGN KEY (branch_id) REFERENCES branches(branch_id);";
            } else if (table === 'drivers') {
                sql = "ALTER TABLE drivers ADD COLUMN branch_id INT NULL;\nALTER TABLE drivers ADD FOREIGN KEY (branch_id) REFERENCES branches(branch_id);";
            }
            
            navigator.clipboard.writeText(sql).then(() => {
                alert('SQL copied to clipboard!');
            });
        }

        // Logout function
        function logout() {
            if (confirm('Are you sure you want to logout?')) {
                window.location.href = '../logout.php';
            }
        }

        // Form validation (for non-delivery roles)
        const addTicketForm = document.getElementById('addTicketForm');
        if (addTicketForm) {
            addTicketForm.addEventListener('submit', function(e) {
                const driverSelect = this.querySelector('select[name="driver_id"]');
                
                <?php if (!($tt_branch_column_exists && !$view_all_branches)): ?>
                const branchSelect = this.querySelector('select[name="branch_id"]');
                if (!branchSelect || !branchSelect.value) {
                    e.preventDefault();
                    alert('Please select a branch');
                    if (branchSelect) branchSelect.focus();
                    return false;
                }
                <?php endif; ?>
                
                if (!driverSelect || !driverSelect.value) {
                    e.preventDefault();
                    alert('Please select a driver');
                    if (driverSelect) driverSelect.focus();
                    return false;
                }
                
                const tripDate = this.querySelector('input[name="trip_date"]');
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
            console.log("Trip Tickets page loaded - Filtering by driver_id in trip_tickets table");
            console.log("User Role:", userRole);
            console.log("Driver ID:", driverId);
            console.log("Branch ID:", branchId);
            
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
            if (e.ctrlKey && e.key === 'b' && window.innerWidth > 992) {
                e.preventDefault();
                toggleSidebar();
            }
            else if (e.key === 'Escape' && window.innerWidth <= 992) {
                closeMobileSidebar();
            }
            else if (e.ctrlKey && e.key === 'f' && !e.target.matches('input, textarea')) {
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