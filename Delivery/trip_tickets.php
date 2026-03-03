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

// ================= HANDLE STATUS UPDATE =================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_trip_status') {
    header('Content-Type: application/json');
    
    try {
        $trip_id = intval($_POST['trip_id']);
        $new_status = $_POST['status'];
        
        // Verify that the trip exists and user has permission
        $check_query = "SELECT tt.trip_id, tt.branch_id, tt.driver_id, tt.trip_status 
                       FROM trip_tickets tt
                       WHERE tt.trip_id = ?";
        $check_stmt = $conn->prepare($check_query);
        $check_stmt->bind_param("i", $trip_id);
        $check_stmt->execute();
        $result = $check_stmt->get_result();
        $trip = $result->fetch_assoc();
        
        if (!$trip) {
            throw new Exception('Trip ticket not found');
        }
        
        // Check branch permission
        if (!$view_all_branches && $trip['branch_id'] != $branch_id) {
            throw new Exception('You do not have permission to update this trip ticket');
        }
        
        // For delivery role, check if they are the assigned driver
        if ($user_role == 'delivery' && $trip['driver_id'] != $driver_id) {
            throw new Exception('You are not assigned to this trip');
        }
        
        // Update the status
        $update_query = "UPDATE trip_tickets SET trip_status = ?, updated_at = NOW() WHERE trip_id = ?";
        $update_stmt = $conn->prepare($update_query);
        $update_stmt->bind_param("si", $new_status, $trip_id);
        
        if (!$update_stmt->execute()) {
            throw new Exception('Failed to update status');
        }
        
        echo json_encode(['success' => true, 'message' => 'Status updated successfully']);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// ================= FUNCTION TO AUTO-UPDATE TRIP STATUS BASED ON DELIVERIES =================
function updateTripStatusBasedOnDeliveries($conn, $trip_id) {
    try {
        // Check all deliveries for this trip
        $check_query = "
            SELECT 
                COUNT(*) as total_deliveries,
                SUM(CASE WHEN delivery_status = 'delivered' THEN 1 ELSE 0 END) as delivered_count,
                SUM(CASE WHEN delivery_status IN ('pending', 'in-transit') THEN 1 ELSE 0 END) as pending_count
            FROM deliveries 
            WHERE trip_id = ?
        ";
        $check_stmt = $conn->prepare($check_query);
        $check_stmt->bind_param("i", $trip_id);
        $check_stmt->execute();
        $result = $check_stmt->get_result();
        $delivery_stats = $result->fetch_assoc();
        
        // If all deliveries are delivered, update trip status to completed
        if ($delivery_stats['total_deliveries'] > 0 && 
            $delivery_stats['delivered_count'] == $delivery_stats['total_deliveries']) {
            
            $update_query = "UPDATE trip_tickets SET trip_status = 'completed', updated_at = NOW() WHERE trip_id = ? AND trip_status != 'completed'";
            $update_stmt = $conn->prepare($update_query);
            $update_stmt->bind_param("i", $trip_id);
            $update_stmt->execute();
            
            return true;
        }
        
        return false;
    } catch (Exception $e) {
        error_log("Error auto-updating trip status: " . $e->getMessage());
        return false;
    }
}

// Check if we need to auto-update trip status (called when a delivery is completed)
if (isset($_GET['check_trip_status']) && isset($_GET['trip_id'])) {
    header('Content-Type: application/json');
    $trip_id = intval($_GET['trip_id']);
    $updated = updateTripStatusBasedOnDeliveries($conn, $trip_id);
    echo json_encode(['updated' => $updated]);
    exit;
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

// Add driver filter for delivery role
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

// ================= TRIP LIST =================
$trip_tickets_query = "
    SELECT 
        tt.trip_id,
        tt.trip_number,
        tt.driver_id,
        tt.branch_id,
        tt.trip_date,
        tt.trip_status,
        tt.total_stops,
        tt.total_delivered,
        tt.total_failed,
        tt.remarks,
        tt.created_at,
        tt.updated_at,
        tt.created_by,
        tt.picklist_id,
        -- Get driver info from drivers table
        d.driver_name,
        d.license_number,
        d.contact_number,
        d.vehicle_type,
        d.vehicle_plate_number,
        b.branch_name,
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

// Add driver filter for delivery role
if ($user_role == 'delivery' && $driver_id > 0) {
    $trip_tickets_query .= " AND tt.driver_id = $driver_id";
}

$trip_tickets_query .= " GROUP BY tt.trip_id ORDER BY 
    CASE 
        WHEN tt.trip_status IN ('planned', 'in-progress') THEN 1
        WHEN tt.trip_status = 'completed' THEN 2
        ELSE 3
    END,
    tt.created_at DESC";

$result = $conn->query($trip_tickets_query);

if (!$result) {
    error_log("SQL Error in trip_tickets.php: " . $conn->error);
    error_log("Query: " . $trip_tickets_query);
    $trip_tickets = [];
} else {
    $trip_tickets = $result->fetch_all(MYSQLI_ASSOC);
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

// Helper function for trip status badge
function getTripStatusBadge($status) {
    return match($status) {
        'planned' => 'bg-warning text-dark',
        'pending' => 'bg-warning text-dark',
        'in-progress' => 'bg-primary text-white',
        'completed' => 'bg-success text-white',
        'cancelled' => 'bg-danger text-white',
        'delayed' => 'bg-info text-white',
        default => 'bg-secondary text-white'
    };
}

function getTripStatusText($status) {
    return match($status) {
        'planned' => 'Pending',
        'pending' => 'Pending',
        'in-progress' => 'In Transit',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
        'delayed' => 'Delayed',
        default => ucfirst(str_replace('-', ' ', $status))
    };
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
    <link rel="stylesheet" href="../css/del_trip_tickets.css">
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
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
                            <span class="nav-text">Rejected Delivery</span>
                        </a>
                    </li>
                </ul>
            </div>
            <hr class="sidebar-divider">
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
            <!-- Header Section -->
            <div class="navbar-top">
                <button class="mobile-toggle-btn" id="mobileToggleBtn">
                    <i class="bi bi-list"></i>
                </button>
                <div class="page-title">
                    <h2>Trip Tickets</h2>
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

            <!-- No Trip Tickets Message -->
            <?php if (empty($trip_tickets)): ?>
                <div class="alert alert-info text-center py-4">
                    <i class="bi bi-ticket" style="font-size: 2rem;"></i>
                    <p class="mt-3 mb-0">
                        No trip tickets found.
                        <?php if ($user_role == 'delivery'): ?>
                            <br><small>You don't have any trip tickets assigned yet.</small>
                        <?php elseif ($tt_branch_column_exists && !$view_all_branches): ?>
                            <br><small>No trip tickets for your branch yet.</small>
                        <?php endif; ?>
                    </p>
                </div>
            <?php else: ?>

            <!-- Trip Tickets Table - Same design as pick_list_items.php -->
            <div class="card">
                <div class="table-container">
                    <table class="table custom-table compact-table">
                        <thead>
                            <tr>
                                <th class="col-trip-number">TRIP NUMBER</th>
                                <th class="col-driver">DRIVER</th>
                                <th class="col-branch">BRANCH</th>
                                <th class="col-date">TRIP DATE</th>
                                <th class="col-status">STATUS</th>
                                <th class="col-customer">CUSTOMER</th>
                                <th class="col-deliveries">DELIVERIES</th>
                                <th class="col-actions text-center">ACTIONS</th>
                            </tr>
                        </thead>
                        <tbody id="tripTableBody">
                            <?php 
                            $current_status_group = '';
                            foreach ($trip_tickets as $row): 
                                // Determine status group for headers
                                $status_group = '';
                                if (in_array($row['trip_status'], ['planned', 'in-progress'])) {
                                    $status_group = 'pending';
                                } elseif ($row['trip_status'] == 'completed') {
                                    $status_group = 'completed';
                                }

                                
                                $driver_display = !empty($row['driver_name']) ? $row['driver_name'] : 'N/A';
                                $customer_display = !empty($row['customer_name']) ? $row['customer_name'] : 'N/A';
                                $delivery_count = $row['delivery_count'] ?? 0;
                                $pending_count = $row['pending_deliveries'] ?? 0;
                                $delivered_count = $row['delivered_count'] ?? 0;
                                
                                // Check if all deliveries are delivered but trip status is not completed
                                $needs_update = ($delivery_count > 0 && $delivered_count == $delivery_count && $row['trip_status'] != 'completed');
                            ?>
                            <tr class="trip-row" 
                                data-status="<?php echo $row['trip_status']; ?>"
                                data-driver-id="<?php echo $row['driver_id'] ?? ''; ?>"
                                data-trip-id="<?php echo $row['trip_id']; ?>"
                                data-search="<?php echo strtolower($row['trip_number'] . ' ' . ($row['driver_name'] ?? '') . ' ' . ($row['customer_name'] ?? '')); ?>">
                                <td class="col-trip-number">
                                    <span class="fw-semibold"><?php echo htmlspecialchars($row['trip_number']); ?></span>
                                    <?php if (!empty($row['pick_list_number'])): ?>
                                        <br><small class="text-muted">PL: <?php echo htmlspecialchars($row['pick_list_number']); ?></small>
                                    <?php endif; ?>
                                    <?php if ($needs_update): ?>
                                        <br><span class="auto-update-badge"><i class="bi bi-arrow-repeat"></i> Auto-update ready</span>
                                    <?php endif; ?>
                                </td>
                                <td class="col-driver">
                                    <?php if ($driver_display != 'N/A'): ?>
                                        <span">
                                            <i></i> <?php echo htmlspecialchars($driver_display); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="col-branch">
                                    <?php echo htmlspecialchars($row['branch_name'] ?? 'N/A'); ?>
                                </td>
                                <td class="col-date">
                                    <?php echo date('Y-m-d', strtotime($row['trip_date'])); ?>
                                </td>
                                <td class="col-status">
                                    <span class="badge <?php echo getTripStatusBadge($row['trip_status']); ?>" style="padding: 6px 12px;">
                                        <?php echo getTripStatusText($row['trip_status']); ?>
                                    </span>
                                </td>
                                <td class="col-customer">
                                    <span class="fw-semibold"><?php echo htmlspecialchars($customer_display); ?></span>
                                    <?php if (!empty($row['so_number'])): ?>
                                        <br><small class="text-muted"><?php echo htmlspecialchars($row['so_number']); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td class="col-deliveries">
                                    <span class="badge bg-primary delivery-count">
                                        <i class="bi bi-box"></i> <?php echo $delivery_count; ?>
                                    </span>
                                    <?php if ($pending_count > 0): ?>
                                        <br><small class="text-warning"><?php echo $pending_count; ?> pending</small>
                                    <?php endif; ?>
                                    <?php if ($delivered_count > 0): ?>
                                        <br><small class="text-success"><?php echo $delivered_count; ?> delivered</small>
                                    <?php endif; ?>
                                </td>
                                <td class="col-actions">
                                    <div class="action-buttons">
                                        <button class="btn-action btn-view" onclick="viewTripDetails(<?php echo $row['trip_id']; ?>)" title="View Details">
                                            <i class="bi bi-eye"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
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

    <!-- Update Status Modal -->
    <div class="modal fade" id="statusModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title"><i class="bi bi-arrow-repeat me-2"></i>Update Trip Status</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="statusTripId">
                    
                    <div class="alert alert-info mb-3">
                        <strong>Trip #: <span id="statusTripNumber"></span></strong>
                    </div>
                    
                    <div class="alert alert-success mb-3" id="autoUpdateNotice" style="display: none;">
                        <i class="bi bi-info-circle-fill me-2"></i>
                        <strong>Note:</strong> This trip can be auto-completed because all deliveries are marked as delivered.
                    </div>
                    
                    <p class="mb-3">Select new status:</p>
                    
                    <div class="status-option" onclick="selectStatus('planned')" id="opt-planned">
                        <span class="badge bg-warning text-dark status-badge">Pending</span>
                        <span>Trip is planned but not yet started</span>
                    </div>
                    
                    <div class="status-option" onclick="selectStatus('in-progress')" id="opt-in-progress">
                        <span class="badge bg-primary text-white status-badge">In Transit</span>
                        <span>Delivery is in progress</span>
                    </div>
                    
                    <div class="status-option" onclick="selectStatus('completed')" id="opt-completed">
                        <span class="badge bg-success text-white status-badge">Completed</span>
                        <span>All deliveries completed</span>
                    </div>
                    
                    <div class="status-option" onclick="selectStatus('delayed')" id="opt-delayed">
                        <span class="badge bg-info text-white status-badge">Delayed</span>
                        <span>Delivery is delayed</span>
                    </div>
                    
                    <div class="status-option" onclick="selectStatus('cancelled')" id="opt-cancelled">
                        <span class="badge bg-danger text-white status-badge">Cancelled</span>
                        <span>Trip has been cancelled</span>
                    </div>
                    
                    <input type="hidden" id="selectedStatus" value="">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-warning" onclick="updateTripStatus()" id="updateStatusBtn">Update Status</button>
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

        let currentTripId = null;
        let currentTripNumber = null;

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

        // View trip details
        function viewTripDetails(tripId) {
            fetch('get_trip_details.php?trip_id=' + tripId + '&branch_id=' + branchId + '&view_all=' + viewAllBranches)
                .then(response => response.text())
                .then(data => {
                    document.getElementById('tripDetailsContent').innerHTML = data;
                    const modal = new bootstrap.Modal(document.getElementById('viewDetailsModal'));
                    modal.show();
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Failed to load trip details');
                });
        }

        // Show status update modal
        function showStatusModal(tripId, currentStatus) {
            currentTripId = tripId;
            
            // Get trip number from the row
            const row = document.querySelector(`.trip-row[data-trip-id="${tripId}"]`);
            if (row) {
                const tripNumber = row.querySelector('.fw-semibold').textContent;
                document.getElementById('statusTripNumber').textContent = tripNumber;
            }
            
            // Check if this trip can be auto-completed
            const deliveredCount = parseInt(row.querySelector('.text-success')?.textContent || '0');
            const deliveryCount = parseInt(row.querySelector('.badge.bg-primary')?.textContent.replace(/[^0-9]/g, '') || '0');
            
            const autoUpdateNotice = document.getElementById('autoUpdateNotice');
            if (deliveryCount > 0 && deliveredCount == deliveryCount && currentStatus != 'completed') {
                autoUpdateNotice.style.display = 'block';
            } else {
                autoUpdateNotice.style.display = 'none';
            }
            
            // Remove selected class from all options
            document.querySelectorAll('.status-option').forEach(opt => {
                opt.classList.remove('selected');
            });
            
            // Add selected class to current status option
            const currentOpt = document.getElementById(`opt-${currentStatus}`);
            if (currentOpt) {
                currentOpt.classList.add('selected');
                document.getElementById('selectedStatus').value = currentStatus;
            }
            
            const modal = new bootstrap.Modal(document.getElementById('statusModal'));
            modal.show();
        }

        // Select status option
        function selectStatus(status) {
            document.querySelectorAll('.status-option').forEach(opt => {
                opt.classList.remove('selected');
            });
            document.getElementById(`opt-${status}`).classList.add('selected');
            document.getElementById('selectedStatus').value = status;
        }

        // Update trip status
        function updateTripStatus() {
            const newStatus = document.getElementById('selectedStatus').value;
            
            if (!newStatus) {
                alert('Please select a status');
                return;
            }
            
            if (!confirm('Are you sure you want to update this trip status?')) {
                return;
            }
            
            const formData = new FormData();
            formData.append('action', 'update_trip_status');
            formData.append('trip_id', currentTripId);
            formData.append('status', newStatus);
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert(data.message);
                    location.reload();
                } else {
                    alert('Error: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Failed to update status');
            });
        }

        // Check if trip needs auto-update
        function checkTripAutoUpdate(tripId) {
            fetch('?check_trip_status=1&trip_id=' + tripId)
                .then(response => response.json())
                .then(data => {
                    if (data.updated) {
                        // Reload to show updated status
                        location.reload();
                    }
                })
                .catch(error => console.error('Error checking trip status:', error));
        }

        // Auto-check trips that might need update when page loads
        document.addEventListener('DOMContentLoaded', function() {
            // Check all trips that have all deliveries delivered but not completed
            document.querySelectorAll('.trip-row .auto-update-badge').forEach(badge => {
                const row = badge.closest('.trip-row');
                if (row) {
                    const tripId = row.dataset.tripId;
                    if (tripId) {
                        checkTripAutoUpdate(tripId);
                    }
                }
            });
        });

        // Filter table function
        function filterTable() {
            const searchInput = document.getElementById('searchInput');
            const statusFilter = document.getElementById('statusFilter');
            const driverFilter = document.getElementById('driverFilter');
            const rows = document.querySelectorAll('.trip-row');
            
            const searchText = searchInput ? searchInput.value.toLowerCase() : '';
            const statusValue = statusFilter ? statusFilter.value : '';
            const driverValue = driverFilter ? driverFilter.value : '';
            
            rows.forEach(row => {
                let showRow = true;
                
                if (searchText) {
                    const searchData = row.dataset.search || row.textContent.toLowerCase();
                    showRow = searchData.includes(searchText);
                }
                
                if (showRow && statusValue) {
                    const rowStatus = row.dataset.status;
                    showRow = rowStatus === statusValue;
                }
                
                if (showRow && driverValue) {
                    const rowDriverId = row.dataset.driverId;
                    showRow = rowDriverId === driverValue;
                }
                
                row.style.display = showRow ? '' : 'none';
            });
        }

        // Clear all filters
        function clearFilters() {
            const searchInput = document.getElementById('searchInput');
            const statusFilter = document.getElementById('statusFilter');
            const driverFilter = document.getElementById('driverFilter');
            
            if (searchInput) searchInput.value = '';
            if (statusFilter) statusFilter.value = '';
            if (driverFilter) driverFilter.value = '';
            
            filterTable();
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
            console.log("Trip Tickets page loaded - With auto-complete when all deliveries are delivered");
            
            initializeSidebar();
            
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
            
            document.querySelectorAll('.sidebar .nav-link').forEach(link => {
                link.addEventListener('click', function() {
                    if (window.innerWidth <= 992) {
                        closeMobileSidebar();
                    }
                });
            });
            
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

            window.addEventListener('resize', handleSidebarResize);

            // Filter event listeners
            const searchInput = document.getElementById('searchInput');
            const statusFilter = document.getElementById('statusFilter');
            const driverFilter = document.getElementById('driverFilter');
            
            if (searchInput) searchInput.addEventListener('keyup', filterTable);
            if (statusFilter) statusFilter.addEventListener('change', filterTable);
            if (driverFilter) driverFilter.addEventListener('change', filterTable);
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