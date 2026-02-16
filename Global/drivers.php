<?php
require_once '../config/database.php';
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

// Get filter parameters
$status = isset($_GET['status']) ? $_GET['status'] : '';
$location = isset($_GET['location']) ? $_GET['location'] : '';
$license = isset($_GET['license']) ? $_GET['license'] : '';
$sort = isset($_GET['sort']) ? $_GET['sort'] : 'name';

// Handle AJAX request
if (isset($_GET['ajax']) && $_GET['ajax'] == 1) {
    header('Content-Type: application/json');
    
    $response = [
        'success' => true,
        'drivers' => [],
        'stats' => [
            'totalDrivers' => 0,
            'activeDrivers' => 0,
            'onLeave' => 0,
            'avgRating' => 0
        ]
    ];

    // Build WHERE clause for filtering
    $where_conditions = ["1=1"];
    $params = [];
    $types = "";

    // Status filter
    if (!empty($status)) {
        $where_conditions[] = "d.status = ?";
        $params[] = $status;
        $types .= "s";
    }

    // Branch/Location filter - only show drivers assigned to selected branch
    if (!empty($location) && is_numeric($location)) {
        $where_conditions[] = "d.branch_id = ?";
        $params[] = $location;
        $types .= "i";
    }

    // License status filter
    if (!empty($license)) {
        $today = date('Y-m-d');
        if ($license == 'valid') {
            $where_conditions[] = "d.license_expiry > DATE_ADD(?, INTERVAL 30 DAY)";
            $params[] = $today;
            $types .= "s";
        } elseif ($license == 'expiring') {
            $where_conditions[] = "d.license_expiry BETWEEN ? AND DATE_ADD(?, INTERVAL 30 DAY)";
            $params[] = $today;
            $params[] = $today;
            $types .= "ss";
        } elseif ($license == 'expired') {
            $where_conditions[] = "d.license_expiry < ?";
            $params[] = $today;
            $types .= "s";
        }
    }

    $where_clause = implode(" AND ", $where_conditions);

    // Get drivers with branch information
    $sql = "SELECT 
                d.driver_id as id,
                d.driver_name as name,
                d.license_number as license_no,
                d.license_expiry,
                d.contact_number as phone,
                d.vehicle_type,
                d.vehicle_plate_number as vehicle_id,
                d.status,
                d.branch_id,
                b.branch_name as location,
                b.branch_code,
                CONCAT(u.first_name, ' ', u.last_name) as user_name
            FROM drivers d
            LEFT JOIN branches b ON d.branch_id = b.branch_id
            LEFT JOIN users u ON d.user_id = u.user_id
            WHERE $where_clause";

    // Add sorting
    switch ($sort) {
        case 'name':
            $sql .= " ORDER BY d.driver_name ASC";
            break;
        case 'rating':
            $sql .= " ORDER BY d.driver_name ASC"; // Will be sorted in PHP
            break;
        case 'trips':
            $sql .= " ORDER BY d.driver_name ASC"; // Will be sorted in PHP
            break;
        default:
            $sql .= " ORDER BY d.driver_name ASC";
    }

    $stmt = $conn->prepare($sql);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();

    $total_drivers = 0;
    $active_count = 0;
    $on_leave_count = 0;
    $total_rating = 0;
    $rating_count = 0;

    while ($row = $result->fetch_assoc()) {
        $total_drivers++;
        
        // Count by status
        if ($row['status'] == 'active') $active_count++;
        elseif ($row['status'] == 'on-leave') $on_leave_count++;
        
        // Calculate license status
        $license_status = 'valid';
        if (!empty($row['license_expiry']) && $row['license_expiry'] != '0000-00-00') {
            $expiry = strtotime($row['license_expiry']);
            $today = time();
            $days_until_expiry = ($expiry - $today) / (60 * 60 * 24);
            
            if ($expiry < $today) {
                $license_status = 'expired';
            } elseif ($days_until_expiry <= 30) {
                $license_status = 'expiring';
            }
        }

        // Format phone number to PH format - FIXED: 0912-345-7654 format only
        $phone = $row['phone'] ?? '';
        $formatted_phone = 'N/A';
        if (!empty($phone)) {
            // Remove non-numeric characters
            $phone = preg_replace('/[^0-9]/', '', $phone);
            
            // Format: 09XX-XXX-XXXX for mobile numbers
            if (strlen($phone) == 11 && substr($phone, 0, 2) == '09') {
                $formatted_phone = substr($phone, 0, 4) . '-' . substr($phone, 4, 3) . '-' . substr($phone, 7, 4);
            } 
            // For other numbers, just show the raw digits
            elseif (strlen($phone) > 0) {
                $formatted_phone = $phone;
            }
        }

        // Mock rating and trips
        $rating = 4.0;
        $trips = 0;
        
        if ($row['status'] == 'active') {
            $rating = 4.5;
            $trips = rand(80, 200);
        } elseif ($row['status'] == 'on-leave') {
            $rating = 4.0;
            $trips = rand(30, 79);
        } else {
            $rating = 3.5;
            $trips = rand(0, 29);
        }
        
        $total_rating += $rating;
        $rating_count++;

        $response['drivers'][] = [
            'id' => $row['id'],
            'name' => $row['name'],
            'license_no' => $row['license_no'],
            'license_expiry' => !empty($row['license_expiry']) && $row['license_expiry'] != '0000-00-00' ? date('M d, Y', strtotime($row['license_expiry'])) : 'N/A',
            'license_status' => $license_status,
            'vehicle_id' => $row['vehicle_id'] ?? $row['vehicle_type'] ?? 'N/A',
            'status' => $row['status'],
            'rating' => $rating,
            'trips_completed' => $trips,
            'phone' => $formatted_phone,
            'location' => $row['location'] ?? 'Unassigned',
            'branch_id' => $row['branch_id'] ?? null,
            'branch_code' => $row['branch_code'] ?? ''
        ];
    }

    // Manual sorting for rating and trips
    if ($sort == 'rating') {
        usort($response['drivers'], function($a, $b) {
            return $b['rating'] <=> $a['rating'];
        });
    } elseif ($sort == 'trips') {
        usort($response['drivers'], function($a, $b) {
            return $b['trips_completed'] <=> $a['trips_completed'];
        });
    }

    $response['stats'] = [
        'totalDrivers' => $total_drivers,
        'activeDrivers' => $active_count,
        'onLeave' => $on_leave_count,
        'avgRating' => $rating_count > 0 ? round($total_rating / $rating_count, 1) : 0
    ];

    echo json_encode($response);
    exit;
}

// Handle AJAX request for driver details
if (isset($_GET['ajax_details']) && isset($_GET['id'])) {
    header('Content-Type: application/json');
    
    $driver_id = intval($_GET['id']);
    
    $sql = "SELECT 
                d.driver_id as id,
                d.driver_name as name,
                d.license_number as license_no,
                d.license_expiry,
                d.contact_number as phone,
                d.vehicle_type,
                d.vehicle_plate_number as vehicle_id,
                d.status,
                d.branch_id,
                d.created_at as joined_date,
                b.branch_name as location,
                b.branch_code,
                CONCAT(u.first_name, ' ', u.last_name) as user_name,
                u.email
            FROM drivers d
            LEFT JOIN branches b ON d.branch_id = b.branch_id
            LEFT JOIN users u ON d.user_id = u.user_id
            WHERE d.driver_id = ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $driver_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        // Calculate license status
        $license_status = 'valid';
        if (!empty($row['license_expiry']) && $row['license_expiry'] != '0000-00-00') {
            $expiry = strtotime($row['license_expiry']);
            $today = time();
            $days_until_expiry = ($expiry - $today) / (60 * 60 * 24);
            
            if ($expiry < $today) {
                $license_status = 'expired';
            } elseif ($days_until_expiry <= 30) {
                $license_status = 'expiring';
            }
        }

        // Format phone number to PH format - FIXED: 0912-345-7654 format only
        $phone = $row['phone'] ?? '';
        $formatted_phone = 'N/A';
        if (!empty($phone)) {
            $phone = preg_replace('/[^0-9]/', '', $phone);
            
            if (strlen($phone) == 11 && substr($phone, 0, 2) == '09') {
                $formatted_phone = substr($phone, 0, 4) . '-' . substr($phone, 4, 3) . '-' . substr($phone, 7, 4);
            } elseif (strlen($phone) > 0) {
                $formatted_phone = $phone;
            }
        }

        // Calculate mock rating and trips
        $rating = 4.0;
        $trips = 0;
        
        if ($row['status'] == 'active') {
            $rating = 4.5;
            $trips = rand(80, 200);
        } elseif ($row['status'] == 'on-leave') {
            $rating = 4.0;
            $trips = rand(30, 79);
        } else {
            $rating = 3.5;
            $trips = rand(0, 29);
        }

        $response = [
            'success' => true,
            'driver' => [
                'id' => $row['id'],
                'name' => $row['name'],
                'email' => $row['email'] ?? 'N/A',
                'phone' => $formatted_phone,
                'license_no' => $row['license_no'],
                'license_expiry' => !empty($row['license_expiry']) && $row['license_expiry'] != '0000-00-00' ? date('F d, Y', strtotime($row['license_expiry'])) : 'N/A',
                'license_status' => $license_status,
                'vehicle_id' => $row['vehicle_id'] ?? $row['vehicle_type'] ?? 'N/A',
                'vehicle_type' => $row['vehicle_type'] ?? 'N/A',
                'status' => $row['status'],
                'rating' => $rating,
                'trips_completed' => $trips,
                'joined_date' => !empty($row['joined_date']) ? date('F d, Y', strtotime($row['joined_date'])) : 'N/A',
                'location' => $row['location'] ?? 'Unassigned',
                'branch_code' => $row['branch_code'] ?? '',
                'branch_id' => $row['branch_id'] ?? null
            ]
        ];
    } else {
        $response = ['success' => false, 'message' => 'Driver not found'];
    }
    
    echo json_encode($response);
    exit;
}

// Get branches for filter dropdown
$branches_sql = "SELECT branch_id, branch_name, branch_code FROM branches WHERE status = 'active' ORDER BY branch_name";
$branches_result = $conn->query($branches_sql);
$branches = [];
while ($row = $branches_result->fetch_assoc()) {
    $branches[] = $row;
}

// Get user info from session
$user_name = $_SESSION['user_name'] ?? 'Quality Control';
$user_role = $_SESSION['user_role'] ?? 'QC Officer';
$user_initials = '';
if (!empty($user_name)) {
    $name_parts = explode(' ', $user_name);
    foreach ($name_parts as $part) {
        if (!empty($part)) {
            $user_initials .= strtoupper(substr($part, 0, 1));
        }
    }
}
if (empty($user_initials)) {
    $user_initials = 'AD';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Global - Drivers</title>
    <link rel="icon" type="image/png" href="../Pictures/favicon-96x96.png" sizes="96x96" />
    <link rel="icon" type="image/svg+xml" href="../Pictures/favicon.svg" />
    <link rel="shortcut icon" href="../Pictures/favicon.ico" />
    <link rel="apple-touch-icon" sizes="180x180" href="../Pictures/apple-touch-icon.png" />
    <link rel="manifest" href="../Pictures/site.webmanifest" />
    <link rel="stylesheet" href="../css/global.css">
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <style>
        .table td, .table th {
            vertical-align: middle;
        }
        .text-end {
            text-align: right !important;
        }
        .text-start {
            text-align: left !important;
        }
        .stat-value {
            font-size: 1.8rem;
            font-weight: 600;
            line-height: 1.2;
        }
        /* Hide ID column */
        .id-column, th:nth-child(1), td:nth-child(1) {
            display: none;
        }
        /* Mobile responsive adjustments ONLY */
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
                        <i class="bi bi-list"></i>
                    </button>    
                    <img src="../Pictures/amgc3DLogo.png" alt="Logo" class="logo-icon">
                    <span class="nav-text">Global</span>
                </h3>
            </div>
            
            <div class="sidebar-menu">
                <ul class="nav flex-column">
                    <li class="nav-item">
                        <a class="nav-link" href="sales_reports.php">
                            <i class="bi bi-graph-up"></i>
                            <span class="nav-text">Sales Reports</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="branch_records.php">
                            <i class="bi bi-file-text"></i>
                            <span class="nav-text">Branch Records</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="all_items.php">
                            <i class="bi bi-box"></i>
                            <span class="nav-text">All Items</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="drivers.php">
                            <i class="bi bi-person-badge"></i>
                            <span class="nav-text">Drivers</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="trip_tickets.php">
                            <i class="bi bi-ticket-perforated"></i>
                            <span class="nav-text">Trip Tickets</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="driver_tracking.php">
                            <i class="bi bi-geo-alt"></i>
                            <span class="nav-text">Driver Tracking</span>
                        </a>
                    </li>
                </ul>
            </div>
            <!-- User Profile Section at the bottom of sidebar -->
            <div class="sidebar-footer">
                <div class="user-profile-sidebar">
                    <div class="user-avatar-sidebar"><?php echo $user_initials; ?></div>
                    <div class="user-details-sidebar">
                        <span class="user-name-sidebar"><?php echo htmlspecialchars($user_name); ?></span>
                        <span class="user-role-sidebar"><?php echo htmlspecialchars($user_role); ?></span>
                    </div>
                </div>
                
                <button class="logout-btn-sidebar" onclick="logout()">
                    <i class="bi bi-box-arrow-right"></i>
                    <span class="logout-text">Logout</span>
                </button>
            </div>
        </div>

        <!-- Main Content -->
        <div class="main-content" id="mainContent">
            <!-- DRIVERS PAGE -->
            <div id="driversContent" class="page-content active">
                <div class="navbar-top">
                    <button class="mobile-toggle-btn" id="mobileToggleBtn">
                        <i class="bi bi-list"></i>
                    </button>

                    <div class="page-title">
                        <h2>All Drivers</h2>
                        <p>Manage and view all drivers across all locations</p>
                    </div>
                </div>

                <div class="row g-3 mb-4">
                    <div class="col-md-3">
                        <div class="stat-card total">
                            <div class="stat-value" id="totalDrivers">0</div>
                            <div class="stat-label">Total Drivers</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card sales">
                            <div class="stat-value" id="activeDrivers">0</div>
                            <div class="stat-label">Active Drivers</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card complete">
                            <div class="stat-value" id="onLeave">0</div>
                            <div class="stat-label">On Leave</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card total">
                            <div class="stat-value" id="avgRating">0/5</div>
                            <div class="stat-label">Avg Rating</div>
                        </div>
                    </div>
                </div>

                <div class="row g-3 mb-4">
                    <div class="col-12">
                        <div class="form-card">
                            <div class="d-flex justify-content-between align-items-center">
                                <h5 class="mb-0">Filter Drivers</h5>
                            </div>
                            <div class="row mt-3">
                                <div class="col-md-3">
                                    <label class="form-label">Status</label>
                                    <select class="form-select" id="statusFilter" onchange="loadDrivers()">
                                        <option value="">All Status</option>
                                        <option value="active">Active</option>
                                        <option value="on-leave">On Leave</option>
                                        <option value="inactive">Inactive</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Location/Branch</label>
                                    <select class="form-select" id="locationFilter" onchange="loadDrivers()">
                                        <option value="">All Locations</option>
                                        <?php foreach ($branches as $branch): ?>
                                            <option value="<?php echo $branch['branch_id']; ?>">
                                                <?php echo htmlspecialchars($branch['branch_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">License Status</label>
                                    <select class="form-select" id="licenseFilter" onchange="loadDrivers()">
                                        <option value="">All</option>
                                        <option value="valid">Valid</option>
                                        <option value="expiring">Expiring Soon</option>
                                        <option value="expired">Expired</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Sort By</label>
                                    <select class="form-select" id="sortFilter" onchange="loadDrivers()">
                                        <option value="name">Name</option>
                                        <option value="rating">Rating (Highest First)</option>
                                        <option value="trips">Trips Completed (Most First)</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="data-table">
                    <div class="table-header">
                        <h5>Driver Information</h5>
                    </div>
                    <div class="table-responsive">
                        <table class="table custom-table">
                            <thead>
                                <tr>
                                    <th class="id-column">Driver ID</th>
                                    <th>Name</th>
                                    <th>License No</th>
                                    <th>License Expiry</th>
                                    <th>Vehicle</th>
                                    <th>Status</th>
                                    <th>Rating</th>
                                    <th>Trips Completed</th>
                                    <th>Phone</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="driversTable">
                                <tr>
                                    <td colspan="10" class="text-center py-4">
                                        <div class="spinner-border text-primary" role="status">
                                            <span class="visually-hidden">Loading...</span>
                                        </div>
                                        <p class="mt-2">Loading drivers...</p>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Driver Details Modal -->
    <div class="modal fade" id="driverModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Driver Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="driverDetails">
                    <!-- Details will be populated here -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
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
                    overlay.addEventListener('click', closeMobileSidebar);
                    setTimeout(() => overlay.classList.add('active'), 10);
                } else {
                    const overlay = document.querySelector('.sidebar-overlay');
                    overlay.classList.toggle('active');
                    if (!sidebar.classList.contains('active')) {
                        setTimeout(() => overlay?.remove(), 300);
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
                setTimeout(() => overlay.remove(), 300);
            }
        }

        function initializeSidebar() {
            const sidebar = document.getElementById('sidebar');
            if (window.innerWidth > 992) {
                const savedCollapsed = localStorage.getItem('sidebarCollapsed');
                if (savedCollapsed === 'true') {
                    sidebar.classList.add('collapsed');
                    document.querySelectorAll('.nav-text').forEach(text => text.style.display = 'none');
                    const mainContent = document.querySelector('.main-content');
                    if (mainContent) mainContent.style.marginLeft = '80px';
                } else {
                    sidebar.classList.remove('collapsed');
                    document.querySelectorAll('.nav-text').forEach(text => text.style.display = 'inline-block');
                    const mainContent = document.querySelector('.main-content');
                    if (mainContent) mainContent.style.marginLeft = '250px';
                }
            } else {
                sidebar.classList.remove('active', 'collapsed');
                document.querySelectorAll('.nav-text').forEach(text => text.style.display = 'inline-block');
                const mainContent = document.querySelector('.main-content');
                if (mainContent) mainContent.style.marginLeft = '0';
            }
        }

        function handleSidebarResize() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.querySelector('.sidebar-overlay');
            
            if (window.innerWidth > 992) {
                overlay?.remove();
                sidebar.classList.remove('active');
                const savedCollapsed = localStorage.getItem('sidebarCollapsed');
                if (savedCollapsed === 'true') {
                    sidebar.classList.add('collapsed');
                    document.querySelectorAll('.nav-text').forEach(text => text.style.display = 'none');
                    const mainContent = document.querySelector('.main-content');
                    if (mainContent) mainContent.style.marginLeft = '80px';
                } else {
                    sidebar.classList.remove('collapsed');
                    document.querySelectorAll('.nav-text').forEach(text => text.style.display = 'inline-block');
                    const mainContent = document.querySelector('.main-content');
                    if (mainContent) mainContent.style.marginLeft = '250px';
                }
            } else {
                sidebar.classList.remove('collapsed');
                document.querySelectorAll('.nav-text').forEach(text => text.style.display = 'inline-block');
                const mainContent = document.querySelector('.main-content');
                if (mainContent) mainContent.style.marginLeft = '0';
            }
        }
        // ================= END SIDEBAR FUNCTIONS =================

        // Logout function
        function logout() {
            window.location.href = '../logout.php';
        }

        // Load drivers
        async function loadDrivers() {
            const tbody = document.getElementById('driversTable');
            tbody.innerHTML = '<tr><td colspan="10" class="text-center py-4"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div><p class="mt-2">Loading drivers...</p></td></tr>';
            
            try {
                const status = document.getElementById('statusFilter').value;
                const location = document.getElementById('locationFilter').value;
                const license = document.getElementById('licenseFilter').value;
                const sort = document.getElementById('sortFilter').value;

                const params = new URLSearchParams({
                    ajax: 1,
                    status: status,
                    location: location,
                    license: license,
                    sort: sort
                });

                const response = await fetch('drivers.php?' + params);
                const data = await response.json();
                
                if (data.success) {
                    displayDrivers(data.drivers || []);
                    updateDriverStats(data.stats || {});
                }
            } catch (error) {
                console.error('Error loading drivers:', error);
                tbody.innerHTML = '<tr><td colspan="10" class="text-center py-4 text-danger">Error loading drivers</td></tr>';
            }
        }

        function displayDrivers(drivers) {
            const tbody = document.getElementById('driversTable');
            
            if (drivers.length === 0) {
                tbody.innerHTML = '<tr><td colspan="10" class="text-center py-4">No drivers found</td></tr>';
                return;
            }

            tbody.innerHTML = drivers.map(driver => {
                let statusBadge = 'bg-success';
                let statusText = driver.status;
                if (driver.status === 'on-leave') {
                    statusBadge = 'bg-warning';
                    statusText = 'On Leave';
                } else if (driver.status === 'inactive') {
                    statusBadge = 'bg-secondary';
                    statusText = 'Inactive';
                }

                let licenseBadge = 'bg-success';
                if (driver.license_status === 'expiring') licenseBadge = 'bg-warning';
                else if (driver.license_status === 'expired') licenseBadge = 'bg-danger';

                const ratingStars = '⭐'.repeat(Math.round(driver.rating));

                return `
                <tr>
                    <td class="id-column">${driver.id}</td>
                    <td><strong>${escapeHtml(driver.name)}</strong><br><small class="text-muted">${escapeHtml(driver.location)}</small></td>
                    <td>${escapeHtml(driver.license_no)}</td>
                    <td>
                        <span class="badge ${licenseBadge}">
                            ${escapeHtml(driver.license_expiry)}
                        </span>
                    </td>
                    <td>${escapeHtml(driver.vehicle_id)}</td>
                    <td>
                        <span class="badge ${statusBadge}">
                            ${statusText}
                        </span>
                    </td>
                    <td>
                        ${ratingStars} <small>${driver.rating}/5</small>
                    </td>
                    <td>${driver.trips_completed.toLocaleString()}</td>
                    <td>${escapeHtml(driver.phone)}</td>
                    <td>
                        <button class="btn-action btn-view" onclick="viewDriver(${driver.id})">
                            <i class="bi bi-eye"></i>
                        </button>
                    </td>
                </tr>
            `;
            }).join('');
        }

        function updateDriverStats(stats) {
            document.getElementById('totalDrivers').textContent = stats.totalDrivers || 0;
            document.getElementById('activeDrivers').textContent = stats.activeDrivers || 0;
            document.getElementById('onLeave').textContent = stats.onLeave || 0;
            document.getElementById('avgRating').textContent = (stats.avgRating || 0).toFixed(1) + '/5';
        }

        function viewDriver(id) {
            const modal = new bootstrap.Modal(document.getElementById('driverModal'));
            const details = document.getElementById('driverDetails');
            details.innerHTML = '<div class="text-center py-4"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div><p class="mt-2">Loading driver details...</p></div>';
            modal.show();
            
            fetch('drivers.php?ajax_details=1&id=' + id)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const driver = data.driver;
                        let statusBadge = 'bg-success';
                        if (driver.status === 'on-leave') statusBadge = 'bg-warning';
                        else if (driver.status === 'inactive') statusBadge = 'bg-secondary';
                        
                        let licenseBadge = 'bg-success';
                        if (driver.license_status === 'expiring') licenseBadge = 'bg-warning';
                        else if (driver.license_status === 'expired') licenseBadge = 'bg-danger';
                        
                        details.innerHTML = `
                            <dl class="row">
                                <dt class="col-sm-4">Driver ID:</dt>
                                <dd class="col-sm-8">${driver.id}</dd>
                                
                                <dt class="col-sm-4">Name:</dt>
                                <dd class="col-sm-8"><strong>${escapeHtml(driver.name)}</strong></dd>
                                
                                <dt class="col-sm-4">Email:</dt>
                                <dd class="col-sm-8">${escapeHtml(driver.email)}</dd>
                                
                                <dt class="col-sm-4">Phone:</dt>
                                <dd class="col-sm-8">${escapeHtml(driver.phone)}</dd>
                                
                                <dt class="col-sm-4">Location:</dt>
                                <dd class="col-sm-8">${escapeHtml(driver.location)} ${driver.branch_code ? '(' + driver.branch_code + ')' : ''}</dd>
                                
                                <dt class="col-sm-4">License No:</dt>
                                <dd class="col-sm-8">${escapeHtml(driver.license_no)}</dd>
                                
                                <dt class="col-sm-4">License Expiry:</dt>
                                <dd class="col-sm-8">
                                    <span class="badge ${licenseBadge}">
                                        ${escapeHtml(driver.license_expiry)}
                                    </span>
                                </dd>
                                
                                <dt class="col-sm-4">Vehicle:</dt>
                                <dd class="col-sm-8">
                                    ${escapeHtml(driver.vehicle_id)} 
                                    <small class="text-muted">(${escapeHtml(driver.vehicle_type)})</small>
                                </dd>
                                
                                <dt class="col-sm-4">Status:</dt>
                                <dd class="col-sm-8">
                                    <span class="badge ${statusBadge}">
                                        ${driver.status === 'on-leave' ? 'On Leave' : driver.status}
                                    </span>
                                </dd>
                                
                                <dt class="col-sm-4">Rating:</dt>
                                <dd class="col-sm-8">${driver.rating}/5 ⭐ (${driver.trips_completed} trips)</dd>
                                
                                <dt class="col-sm-4">Joined Date:</dt>
                                <dd class="col-sm-8">${escapeHtml(driver.joined_date)}</dd>
                            </dl>
                        `;
                    } else {
                        details.innerHTML = '<p class="text-danger">Failed to load driver details.</p>';
                    }
                })
                .catch(error => {
                    console.error('Error loading driver details:', error);
                    details.innerHTML = '<p class="text-danger">Error loading driver details.</p>';
                });
        }

        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Initialize when page loads
        document.addEventListener('DOMContentLoaded', function() {
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
                const mobileToggleBtn = document.getElementById('mobileToggleBtn');
                const overlay = document.querySelector('.sidebar-overlay');
                const isMobile = window.innerWidth <= 992;
                
                if (isMobile && sidebar.classList.contains('active') && 
                    !sidebar.contains(event.target) && 
                    (!mobileToggleBtn || !mobileToggleBtn.contains(event.target)) &&
                    (!overlay || !overlay.contains(event.target))) {
                    closeMobileSidebar();
                }
            });

            window.addEventListener('resize', handleSidebarResize);
            loadDrivers();
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
            else if (e.ctrlKey && e.key === 'r') {
                e.preventDefault();
                loadDrivers();
            }
        });
    </script>
</body>
</html>
<?php $conn->close(); ?>