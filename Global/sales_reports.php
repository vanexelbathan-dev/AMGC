<?php
require_once '../config/database.php';
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

// Get user info for display
$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'] ?? $_SESSION['first_name'] . ' ' . $_SESSION['last_name'] ?? 'Quality Control';
$user_role = $_SESSION['role'] ?? 'global';
$view_all_branches = $_SESSION['view_all_branches'] ?? true;

// Get user's branch name for display (if applicable)
$branch_name = 'All Branches';
$user_branch_id = $_SESSION['branch_id'] ?? 0;
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

// Get initials for avatar
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

// Get filter parameters
$period = isset($_GET['period']) ? $_GET['period'] : 'monthly';
$date = isset($_GET['date']) ? $_GET['date'] : '2026-02';

// Handle AJAX request
if (isset($_GET['ajax']) && $_GET['ajax'] == 1) {
    header('Content-Type: application/json');
    
    $response = [
        'success' => true,
        'metrics' => [
            'totalSales' => 0,
            'itemsSold' => 0,
            'avgOrderValue' => 0
        ],
        'topItems' => [],
        'locationSales' => [],
        'periodBreakdown' => []
    ];

    // Parse date for filtering
    $year = substr($date, 0, 4);
    $month = substr($date, 5, 2);
    
    // Build WHERE clause based on period
    $where_clause = " WHERE so.order_status != 'cancelled' AND so.order_date != '0000-00-00 00:00:00'";
    $location_where = " WHERE b.status = 'active'";
    $location_join = "";
    
    // Add branch filter based on user permissions
    if (!$view_all_branches && $user_branch_id > 0) {
        $where_clause .= " AND so.branch_id = $user_branch_id";
        $location_where .= " AND b.branch_id = $user_branch_id";
    }
    
    if ($period == 'monthly' && !empty($date)) {
        $where_clause .= " AND YEAR(so.order_date) = $year AND MONTH(so.order_date) = $month";
        $location_join = " AND YEAR(so.order_date) = $year AND MONTH(so.order_date) = $month";
    } elseif ($period == 'daily' && !empty($date)) {
        $where_clause .= " AND DATE(so.order_date) = '$date'";
        $location_join = " AND DATE(so.order_date) = '$date'";
    }
    
    // Get total sales, items sold, and average order value
    $metrics_sql = "SELECT 
                    IFNULL(SUM(so.total_amount), 0) as total_sales,
                    COUNT(DISTINCT so.so_id) as total_orders,
                    IFNULL(SUM(soi.quantity_ordered), 0) as total_items
                    FROM sales_orders so
                    LEFT JOIN sales_order_items soi ON so.so_id = soi.so_id
                    $where_clause";
    
    $metrics_result = $conn->query($metrics_sql);
    if ($metrics_result) {
        $metrics_row = $metrics_result->fetch_assoc();
        
        $total_sales = $metrics_row['total_sales'] ?? 0;
        $total_orders = $metrics_row['total_orders'] ?? 0;
        $total_items = $metrics_row['total_items'] ?? 0;
        $avg_order = $total_orders > 0 ? $total_sales / $total_orders : 0;
        
        $response['metrics'] = [
            'totalSales' => $total_sales,
            'itemsSold' => $total_items,
            'avgOrderValue' => $avg_order
        ];
    }

    // Get top selling items - with filtering
    $top_items_sql = "SELECT 
                        i.item_name,
                        i.item_code,
                        b.branch_name as location,
                        SUM(soi.quantity_ordered) as units_sold,
                        SUM(soi.quantity_ordered * soi.unit_price) as revenue
                      FROM sales_order_items soi
                      INNER JOIN sales_orders so ON soi.so_id = so.so_id
                      INNER JOIN items i ON soi.item_id = i.item_id
                      INNER JOIN branches b ON so.branch_id = b.branch_id
                      $where_clause
                      GROUP BY i.item_id, b.branch_id
                      ORDER BY revenue DESC
                      LIMIT 10";
    
    $top_items_result = $conn->query($top_items_sql);
    if ($top_items_result) {
        while ($row = $top_items_result->fetch_assoc()) {
            $response['topItems'][] = [
                'item_name' => $row['item_name'],
                'item_code' => $row['item_code'],
                'location' => $row['location'],
                'units_sold' => (int)$row['units_sold'],
                'revenue' => (float)$row['revenue']
            ];
        }
    }

    // Get sales by location/branch - with filtering
    $location_sales_sql = "SELECT 
                            b.branch_name as location,
                            COUNT(DISTINCT so.so_id) as total_orders,
                            IFNULL(SUM(so.total_amount), 0) as total_revenue,
                            IFNULL(SUM(soi.quantity_ordered), 0) as total_items
                          FROM branches b
                          LEFT JOIN sales_orders so ON b.branch_id = so.branch_id 
                              AND so.order_status != 'cancelled'
                              AND so.order_date != '0000-00-00 00:00:00'
                              $location_join
                          LEFT JOIN sales_order_items soi ON so.so_id = soi.so_id
                          $location_where
                          GROUP BY b.branch_id
                          ORDER BY total_revenue DESC";
    
    $location_result = $conn->query($location_sales_sql);
    if ($location_result) {
        while ($row = $location_result->fetch_assoc()) {
            $response['locationSales'][] = [
                'location' => $row['location'],
                'totalOrders' => (int)$row['total_orders'],
                'totalRevenue' => (float)$row['total_revenue'],
                'totalItems' => (int)$row['total_items']
            ];
        }
    }

    // Get period breakdown - with filtering
    $breakdown_sql = "SELECT 
                        DATE_FORMAT(so.order_date, '%Y-%m-%d') as sale_date,
                        b.branch_name as location,
                        COUNT(DISTINCT so.so_id) as orders,
                        IFNULL(SUM(soi.quantity_ordered), 0) as items_sold,
                        IFNULL(SUM(so.total_amount), 0) as revenue
                      FROM sales_orders so
                      INNER JOIN branches b ON so.branch_id = b.branch_id
                      LEFT JOIN sales_order_items soi ON so.so_id = soi.so_id
                      $where_clause
                      GROUP BY DATE(so.order_date), b.branch_id
                      ORDER BY so.order_date DESC";
    
    if ($period == 'monthly') {
        $breakdown_sql .= " LIMIT 31";
    } else {
        $breakdown_sql .= " LIMIT 20";
    }
    
    $breakdown_result = $conn->query($breakdown_sql);
    if ($breakdown_result) {
        while ($row = $breakdown_result->fetch_assoc()) {
            $response['periodBreakdown'][] = [
                'period' => date('F j, Y', strtotime($row['sale_date'])),
                'location' => $row['location'],
                'orders' => (int)$row['orders'],
                'itemsSold' => (int)$row['items_sold'],
                'revenue' => (float)$row['revenue']
            ];
        }
    }

    echo json_encode($response);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Global - Sales Reports</title>
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
    <!-- SweetAlert2 -->
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
/* ===== FILTER REPORTS & DROPDOWN - UNIFIED RESPONSIVE CSS ===== */

/* Form Card - Base */
.form-card {
    background: white;
    border-radius: clamp(14px, 3vw, 20px);
    padding: clamp(0.8rem, 3vw, 1.5rem);
    box-shadow: 0 8px 20px -5px rgba(4, 120, 87, 0.12);
    border: 1px solid rgba(68, 211, 78, 0.2);
    margin-bottom: clamp(1rem, 2vw, 1.5rem);
    transition: all 0.3s ease;
    width: 100%;
}

/* Card Header */
.form-card h5 {
    color: var(--dark-green);
    font-weight: 700;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: clamp(1rem, 4vw, 1.3rem);
    margin-bottom: clamp(0.5rem, 2vw, 1rem);
    padding-bottom: clamp(0.3rem, 1.5vw, 0.5rem);
    border-bottom: 2px solid rgba(68, 211, 78, 0.2);
    width: 100%;
}

.form-card h5 i {
    color: var(--primary-green);
    background: rgba(68, 211, 78, 0.1);
    padding: clamp(0.3rem, 1.5vw, 0.5rem);
    border-radius: clamp(6px, 2vw, 10px);
    font-size: clamp(0.9rem, 3.5vw, 1.2rem);
}

/* Form Labels */
.form-label {
    font-weight: 600;
    color: var(--dark-color);
    margin-bottom: clamp(0.2rem, 1vw, 0.4rem);
    display: flex;
    align-items: center;
    gap: 0.3rem;
    font-size: clamp(0.75rem, 3vw, 0.9rem);
}

.form-label i {
    color: var(--primary-green);
    font-size: clamp(0.8rem, 3.5vw, 1rem);
}

/* FORM CONTROLS - UNIFIED (SELECT & INPUT) */
.form-select, 
.form-control {
    border: 2px solid #e5e7eb;
    border-radius: clamp(6px, 2vw, 10px);
    padding: clamp(0.35rem, 2vw, 0.7rem) clamp(0.7rem, 3vw, 1rem);
    font-size: clamp(0.75rem, 3.5vw, 0.95rem);
    height: auto;
    min-height: clamp(32px, 7vw, 42px);
    width: 100%;
    background-color: white;
    transition: all 0.2s ease;
    line-height: 1.4;
    box-sizing: border-box;
    /* REMOVED: white-space, overflow, text-overflow - hindi dapat sa select/input mismo */
}

/* SELECT SPECIFIC - WITH CUSTOM ARROW */
.form-select {
    background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3e%3cpath fill='none' stroke='%23047857' stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='m2 5 6 6 6-6'/%3e%3c/svg%3e");
    background-repeat: no-repeat;
    background-position: right clamp(0.5rem, 2vw, 0.75rem) center;
    background-size: clamp(10px, 2.5vw, 14px) clamp(8px, 2vw, 12px);
    padding-right: clamp(1.8rem, 6vw, 2.2rem);
    appearance: none;
    -webkit-appearance: none;
    -moz-appearance: none;
    /* REMOVED: white-space, overflow, text-overflow */
}

/* INPUT SPECIFIC */
.form-control {
    padding-right: clamp(0.7rem, 3vw, 1rem);
}

/* Focus States */
.form-select:focus, 
.form-control:focus {
    border-color: var(--primary-green);
    box-shadow: 0 0 0 3px rgba(68, 211, 78, 0.15);
    outline: none;
}

/* Hover States */
.form-select:hover, 
.form-control:hover {
    border-color: var(--primary-green);
    background-color: rgba(68, 211, 78, 0.02);
}

/* Calendar Icon */
input[type="month"]::-webkit-calendar-picker-indicator {
    width: clamp(14px, 3.5vw, 18px);
    height: clamp(14px, 3.5vw, 18px);
    padding: clamp(1px, 0.5vw, 3px);
    cursor: pointer;
    opacity: 0.6;
    transition: all 0.2s ease;
}

input[type="month"]::-webkit-calendar-picker-indicator:hover {
    opacity: 1;
    background: rgba(68, 211, 78, 0.1);
    transform: scale(1.1);
}

/* ===== RESPONSIVE BREAKPOINTS - SMOOTH TRANSITIONS ===== */

/* Extra Small (below 400px) - 1 column */
@media (max-width: 399px) {
    .form-card {
        padding: 0.7rem;
    }
    
    .form-card h5 {
        font-size: 0.95rem;
    }
    
    .form-card h5 i {
        font-size: 0.85rem;
        padding: 0.25rem;
    }
    
    .form-label {
        font-size: 0.7rem;
    }
    
    .form-label i {
        font-size: 0.75rem;
    }
    
    .form-select, 
    .form-control {
        font-size: 0.7rem;
        padding: 0.25rem 0.5rem;
        min-height: 30px;
    }
    
    .form-select {
        padding-right: 1.6rem;
        background-position: right 0.4rem center;
        background-size: 10px 8px;
    }
    
    .col-12, .col-sm-6 {
        width: 100% !important;
        flex: 0 0 100%;
        max-width: 100%;
    }
    
    .row.g-3 {
        --bs-gutter-y: 0.5rem;
    }
}

/* Small (400px - 575px) - 1 column para hindi mag-break */
@media (min-width: 400px) and (max-width: 575px) {
    .form-card {
        padding: 0.8rem;
    }
    
    .form-card h5 {
        font-size: 1rem;
    }
    
    .form-card h5 i {
        font-size: 0.9rem;
        padding: 0.3rem;
    }
    
    .form-label {
        font-size: 0.75rem;
    }
    
    .form-label i {
        font-size: 0.8rem;
    }
    
    .form-select, 
    .form-control {
        font-size: 0.75rem;
        padding: 0.3rem 0.6rem;
        min-height: 32px;
    }
    
    .form-select {
        padding-right: 1.8rem;
        background-position: right 0.5rem center;
        background-size: 11px 9px;
    }
    
    .col-12, .col-sm-6 {
        width: 100% !important;
        flex: 0 0 100%;
        max-width: 100%;
    }
    
    .row.mt-3 > [class*="col-"] {
        margin-bottom: 0.8rem;
    }
    
    .row.mt-3 > [class*="col-"]:last-child {
        margin-bottom: 0;
    }
}

/* Medium (576px - 767px) - 2 columns na */
@media (min-width: 576px) and (max-width: 767px) {
    .form-card {
        padding: 1rem;
    }
    
    .form-card h5 {
        font-size: 1.1rem;
    }
    
    .form-card h5 i {
        font-size: 1rem;
        padding: 0.35rem;
    }
    
    .form-label {
        font-size: 0.8rem;
    }
    
    .form-label i {
        font-size: 0.85rem;
    }
    
    .form-select, 
    .form-control {
        font-size: 0.8rem;
        padding: 0.35rem 0.65rem;
        min-height: 34px;
    }
    
    .form-select {
        padding-right: 2rem;
        background-size: 12px 10px;
    }
    
    .col-sm-6 {
        width: 50% !important;
        flex: 0 0 50%;
        max-width: 50%;
    }
}

/* Tablet (768px - 991px) */
@media (min-width: 768px) and (max-width: 991px) {
    .form-card {
        padding: 1.2rem;
    }
    
    .form-card h5 {
        font-size: 1.2rem;
    }
    
    .form-card h5 i {
        font-size: 1.1rem;
        padding: 0.4rem;
    }
    
    .form-label {
        font-size: 0.85rem;
    }
    
    .form-label i {
        font-size: 0.9rem;
    }
    
    .form-select, 
    .form-control {
        font-size: 0.85rem;
        padding: 0.4rem 0.7rem;
        min-height: 36px;
    }
    
    .col-md-6 {
        width: 50% !important;
    }
}

/* Small Desktop (992px - 1199px) */
@media (min-width: 992px) and (max-width: 1199px) {
    .form-card {
        padding: 1.3rem;
    }
    
    .form-card h5 {
        font-size: 1.3rem;
    }
    
    .form-label {
        font-size: 0.9rem;
    }
    
    .form-select, 
    .form-control {
        font-size: 0.9rem;
        padding: 0.5rem 0.8rem;
        min-height: 38px;
    }
}

/* Large Desktop (1200px and up) */
@media (min-width: 1200px) {
    .form-card {
        padding: 1.5rem;
    }
    
    .form-card h5 {
        font-size: 1.4rem;
    }
    
    .form-label {
        font-size: 0.95rem;
    }
    
    .form-select, 
    .form-control {
        font-size: 0.95rem;
        padding: 0.6rem 0.9rem;
        min-height: 40px;
    }
}

/* Extra Large Desktop (1400px and up) */
@media (min-width: 1400px) {
    .form-card {
        padding: 1.8rem;
    }
    
    .form-card h5 {
        font-size: 1.5rem;
    }
    
    .form-label {
        font-size: 1rem;
    }
    
    .form-select, 
    .form-control {
        font-size: 1rem;
        padding: 0.7rem 1rem;
        min-height: 42px;
    }
}

/* ===== CONTAINER FIXES - PARA HINDI MAG-BREAK ===== */
.row.mt-3 {
    display: flex;
    flex-wrap: wrap;
    margin-right: -0.5rem;
    margin-left: -0.5rem;
}

.row.mt-3 > [class*="col-"] {
    padding-right: 0.5rem;
    padding-left: 0.5rem;
    box-sizing: border-box;
}

/* Fix para sa Bootstrap grid */
.g-3 {
    --bs-gutter-x: 1rem;
    --bs-gutter-y: 1rem;
}

@media (max-width: 575px) {
    .g-3 {
        --bs-gutter-y: 0.75rem;
    }
}

/* ===== DROPDOWN OPTIONS ===== */
.form-select option {
    font-size: inherit;
    padding: clamp(0.2rem, 1vw, 0.4rem);
    /* REMOVED: white-space, overflow, text-overflow - sa options lang dapat */
}

/* ===== ANIMATION ===== */
.form-card {
    animation: fadeInUp 0.3s ease-out;
}

@keyframes fadeInUp {
    from {
        opacity: 0;
        transform: translateY(10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}
/* ===== SPACING BETWEEN SECTIONS ===== */

/* Space between Filter and Table */
.data-table {
    margin-top: -2rem !important; /* dagdag space sa taas ng table */
}

/* Alternative kung gusto mo sa filter mismo ang space */
.form-card {
    margin-bottom: 2rem !important; /* space sa baba ng filter */
}

/* Responsive spacing */
@media (max-width: 768px) {
    .data-table {
        margin-top: -1.5rem !important;
    }
    
    .form-card {
        margin-bottom: 1.5rem !important;
    }
}

@media (max-width: 576px) {
    .data-table {
        margin-top: -1rem !important;
    }
    
    .form-card {
        margin-bottom: 1rem !important;
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
                    <span class="nav-text">Global</span>
                </h3>
            </div>
            <div class="sidebar-menu">
                <ul class="nav flex-column">
                    <li class="nav-item">
                        <a class="nav-link active" href="sales_reports.php">
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
                        <a class="nav-link" href="drivers.php">
                            <i class="bi bi-people"></i>
                            <span class="nav-text">User Management</span>
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
                    <hr class="sidebar-divider">
                </ul>
            </div>
            <div class="sidebar-footer">
                <div class="user-profile-sidebar">
                    <div class="user-avatar-sidebar"><?php echo $user_initials; ?></div>
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

        <!-- Main Content -->
        <div class="main-content" id="mainContent">
            <div id="reportsContent" class="page-content active">
                <div class="navbar-top">
                    <button class="mobile-toggle-btn" id="mobileToggleBtn">
                        <i class="bi bi-list"></i>
                    </button>
                    <div class="page-title">
                        <h2>Sales Reports</h2>
                        <p>Monitor sales performance by period</p>
                    </div>
                </div>

           <div class="row stat-card-row g-1 g-sm-2">
    <!-- Card 1 -->
    <div class="col">
        <div class="stat-card total">
            <i class="bi bi-graph-up-arrow"></i>
            <div class="stat-content">
                <div class="stat-value" id="totalSales">₱0.00</div>
                <div class="stat-label">Total Sales</div>
            </div>
        </div>
    </div>
    
    <!-- Card 2 -->
    <div class="col">
        <div class="stat-card sales">
            <i class="bi bi-box-seam"></i>
            <div class="stat-content">
                <div class="stat-value" id="itemsSold">0</div>
                <div class="stat-label">Items Sold</div>
            </div>
        </div>
    </div>
    
    <!-- Card 3 -->
    <div class="col">
        <div class="stat-card complete">
            <i class="bi bi-calculator"></i>
            <div class="stat-content">
                <div class="stat-value" id="avgOrderValue">₱0.00</div>
                <div class="stat-label">Avg Order Value</div>
            </div>
        </div>
    </div>
    
    <!-- Pwedeng magdagdag ng Card 4, 5, 6 - automatic mag-aadjust -->
</div>
                
              <!-- FILTER SECTION - SALES REPORTS -->
<div class="row g-3 mb-4">
    <div class="col-12">
        <div class="form-card">
            <div class="filter-header">
                <h5 class="mb-0">
                    <i class="bi bi-funnel"></i> Filter Reports
                </h5>
                <button class="filter-toggle-btn" id="toggleSalesFilter" onclick="toggleFilter('sales')" title="Toggle Filter">
                    <i class="bi bi-chevron-down" id="salesFilterIcon"></i>
                </button>
            </div>
            <div class="filter-content" id="salesFilterContent">
                <div class="row mt-3 g-3">
                    <div class="col-12 col-sm-6">
                        <label class="form-label">
                            <i class="bi bi-calendar-range"></i> Period
                        </label>
                        <select class="form-select" id="periodFilter" onchange="toggleDateFilter()">
                            <option value="monthly" selected>Monthly</option>
                            <option value="daily">Daily</option>
                        </select>
                    </div>
                    <div class="col-12 col-sm-6">
                        <label class="form-label" id="dateLabel">
                            <i class="bi bi-calendar-month"></i> Month
                        </label>
                        <input type="month" class="form-control" id="dateFilter" value="2026-02" onchange="loadReports()">
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
                <div class="row g-3 mb-4">
                    <div class="col-lg-6">
                        <div class="data-table">
                            <div class="table-header">
                                <h5>Top Selling Items</h5>
                            </div>
                            <div class="table-responsive">
                                <table class="table custom-table">
                                    <thead>
                                        <tr>
                                            <th class="text-start">Item Name</th>
                                            <th class="text-start">Location</th>
                                            <th class="text-end">Units Sold</th>
                                            <th class="text-end">Revenue</th>
                                        </tr>
                                    </thead>
                                    <tbody id="topItemsTable">
                                        <tr>
                                            <td colspan="4" class="text-center py-4">Loading data...</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-6">
                        <div class="data-table">
                            <div class="table-header">
                                <h5>Sales by Location</h5>
                            </div>
                            <div class="table-responsive">
                                <table class="table custom-table">
                                    <thead>
                                        <tr>
                                            <th class="text-start">Location</th>
                                            <th class="text-end">Orders</th>
                                            <th class="text-end">Revenue</th>
                                            <th class="text-end">Items</th>
                                        </tr>
                                    </thead>
                                    <tbody id="locationSalesTable">
                                        <tr>
                                            <td colspan="4" class="text-center py-4">Loading data...</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row g-3">
                    <div class="col-12">
                        <div class="data-table">
                            <div class="table-header">
                                <h5>Daily Sales Breakdown</h5>
                            </div>
                            <div class="table-responsive">
                                <table class="table custom-table">
                                    <thead>
                                        <tr>
                                            <th class="text-start">Date</th>
                                            <th class="text-start">Location</th>
                                            <th class="text-end">Orders</th>
                                            <th class="text-end">Items</th>
                                            <th class="text-end">Revenue</th>
                                        </tr>
                                    </thead>
                                    <tbody id="periodBreakdownTable">
                                        <tr>
                                            <td colspan="5" class="text-center py-4">Loading data...</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

     <!-- Mobile Bottom Navigation -->
    <div class="mobile-nav" id="mobileNav">
        <ul class="nav">
            <li class="nav-item">
                <a class="nav-link" href="sales_reports.php">
                    <i class="bi bi-graph-up"></i>
                    <span>Reports</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="branch_records.php">
                    <i class="bi bi-file-text"></i>
                    <span>Records</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="all_items.php">
                    <i class="bi bi-box"></i>
                    <span>Items</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link active" href="drivers.php">
                    <i class="bi bi-people"></i>
                    <span>Users</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="trip_tickets.php">
                    <i class="bi bi-ticket-perforated"></i>
                    <span>Tickets</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="driver_tracking.php">
                    <i class="bi bi-geo-alt"></i>
                    <span>Tracking</span>
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
                        <?php echo $user_initials; ?>
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
                    
                    <!-- Logout Button -->
                    <button class="btn btn-danger btn-lg w-100" onclick="confirmLogout()">
                        <i class="bi bi-box-arrow-right me-2"></i>Logout
                    </button>
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

    // ================= REPORT FUNCTIONS =================
    function toggleDateFilter() {
        const period = document.getElementById('periodFilter').value;
        const dateFilter = document.getElementById('dateFilter');
        const dateLabel = document.getElementById('dateLabel');
        
        if (period === 'monthly') {
            dateLabel.textContent = 'Month';
            dateFilter.type = 'month';
            dateFilter.value = '2026-02';
        } else {
            dateLabel.textContent = 'Date';
            dateFilter.type = 'date';
            dateFilter.value = '2026-02-13';
        }
        loadReports();
    }

    async function loadReports() {
        const period = document.getElementById('periodFilter').value;
        const date = document.getElementById('dateFilter').value;

        const params = new URLSearchParams({
            ajax: 1,
            period: period,
            date: date
        });

        try {
            const response = await fetch('sales_reports.php?' + params);
            const data = await response.json();
            
            if (data.success) {
                // Update metrics
                document.getElementById('totalSales').textContent = '₱' + parseFloat(data.metrics.totalSales || 0).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
                document.getElementById('itemsSold').textContent = parseInt(data.metrics.itemsSold || 0).toLocaleString();
                document.getElementById('avgOrderValue').textContent = '₱' + parseFloat(data.metrics.avgOrderValue || 0).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
                
                // Update top items table
                const topItems = document.getElementById('topItemsTable');
                if (data.topItems.length > 0) {
                    topItems.innerHTML = data.topItems.map(item => `
                        <tr>
                            <td class="text-start"><strong>${escapeHtml(item.item_name)}</strong><br><small class="text-muted">${escapeHtml(item.item_code)}</small></td>
                            <td class="text-start">${escapeHtml(item.location)}</td>
                            <td class="text-end">${item.units_sold.toLocaleString()}</td>
                            <td class="text-end">₱${item.revenue.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',')}</td>
                        </tr>
                    `).join('');
                } else {
                    topItems.innerHTML = '<tr><td colspan="4" class="text-center py-4">No sales data available for this period</td></tr>';
                }
                
                // Update location sales table
                const locationSales = document.getElementById('locationSalesTable');
                if (data.locationSales.length > 0) {
                    locationSales.innerHTML = data.locationSales.map(loc => `
                        <tr>
                            <td class="text-start"><strong>${escapeHtml(loc.location)}</strong></td>
                            <td class="text-end">${loc.totalOrders.toLocaleString()}</td>
                            <td class="text-end">₱${loc.totalRevenue.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',')}</td>
                            <td class="text-end">${loc.totalItems.toLocaleString()}</td>
                        </tr>
                    `).join('');
                } else {
                    locationSales.innerHTML = '<tr><td colspan="4" class="text-center py-4">No sales data available for this period</td></tr>';
                }
                
                // Update period breakdown table
                const periodBreakdown = document.getElementById('periodBreakdownTable');
                if (data.periodBreakdown.length > 0) {
                    periodBreakdown.innerHTML = data.periodBreakdown.map(period => `
                        <tr>
                            <td class="text-start">${escapeHtml(period.period)}</td>
                            <td class="text-start">${escapeHtml(period.location)}</td>
                            <td class="text-end">${period.orders.toLocaleString()}</td>
                            <td class="text-end">${period.itemsSold.toLocaleString()}</td>
                            <td class="text-end">₱${period.revenue.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',')}</td>
                        </tr>
                    `).join('');
                } else {
                    periodBreakdown.innerHTML = '<tr><td colspan="5" class="text-center py-4">No transactions found for this period</td></tr>';
                }
            }
        } catch (error) {
            console.error('Error:', error);
        }
    }

    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // ================= INITIALIZATION =================
    document.addEventListener('DOMContentLoaded', function() {
        initializeSidebar();
        initMobileNav();
        
        document.getElementById('mobileToggleBtn')?.addEventListener('click', (e) => {
            e.stopPropagation();
            toggleSidebar();
        });
        
        document.getElementById('desktopToggleBtn')?.addEventListener('click', (e) => {
            e.stopPropagation();
            toggleSidebar();
        });
        
        document.querySelectorAll('.sidebar .nav-link').forEach(link => {
            link.addEventListener('click', function() {
                if (window.innerWidth <= 992) closeMobileSidebar();
            });
        });
        
        window.addEventListener('resize', function() {
            handleSidebarResize();
            initMobileNav();
        });
        
        // Set default to February 2026 and load data
        document.getElementById('dateFilter').value = '2026-02';
        loadReports();
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
    });
    // ================= FILTER TOGGLE FUNCTIONS =================
// Toggle filter section visibility with localStorage
function toggleFilter(filterType) {
    const contentId = filterType + 'FilterContent';
    const iconId = filterType + 'FilterIcon';
    
    const content = document.getElementById(contentId);
    const icon = document.getElementById(iconId);
    
    if (content && icon) {
        if (content.classList.contains('collapsed')) {
            // Show filter
            content.classList.remove('collapsed');
            icon.style.transform = 'rotate(0deg)';
            localStorage.setItem(filterType + 'FilterHidden', 'false');
        } else {
            // Hide filter
            content.classList.add('collapsed');
            icon.style.transform = 'rotate(-90deg)';
            localStorage.setItem(filterType + 'FilterHidden', 'true');
        }
    }
}

// ================= FILTER TOGGLE FUNCTIONS =================
// Toggle filter section visibility with localStorage
function toggleFilter(filterType) {
    const contentId = filterType + 'FilterContent';
    const iconId = filterType + 'FilterIcon';
    
    const content = document.getElementById(contentId);
    const icon = document.getElementById(iconId);
    
    if (content && icon) {
        if (content.classList.contains('collapsed')) {
            // Show filter
            content.classList.remove('collapsed');
            icon.style.transform = 'rotate(0deg)';
            localStorage.setItem(filterType + 'FilterHidden', 'false');
        } else {
            // Hide filter
            content.classList.add('collapsed');
            icon.style.transform = 'rotate(-90deg)';
            localStorage.setItem(filterType + 'FilterHidden', 'true');
        }
    }
}

// ================= FILTER TOGGLE FUNCTIONS =================
// Toggle filter section visibility with localStorage
function toggleFilter(filterType) {
    const contentId = filterType + 'FilterContent';
    const iconId = filterType + 'FilterIcon';
    
    const content = document.getElementById(contentId);
    const icon = document.getElementById(iconId);
    
    if (content && icon) {
        if (content.classList.contains('collapsed')) {
            // Show filter
            content.classList.remove('collapsed');
            icon.style.transform = 'rotate(0deg)';
            localStorage.setItem(filterType + 'FilterHidden', 'false');
        } else {
            // Hide filter
            content.classList.add('collapsed');
            icon.style.transform = 'rotate(-90deg)';
            localStorage.setItem(filterType + 'FilterHidden', 'true');
        }
    }
}

// Initialize filter states on page load - DEFAULT CLOSED
function initFilterStates() {
    const filterTypes = ['sales', 'branch', 'items', 'driver', 'trip'];
    
    filterTypes.forEach(type => {
        const contentId = type + 'FilterContent';
        const iconId = type + 'FilterIcon';
        
        const content = document.getElementById(contentId);
        const icon = document.getElementById(iconId);
        
        if (content && icon) {
            // DEFAULT: CLOSED sa simula
            content.classList.add('collapsed');
            icon.style.transform = 'rotate(-90deg)';
            
            // Save sa localStorage na closed para consistent
            localStorage.setItem(type + 'FilterHidden', 'true');
        }
    });
}

// Call this sa loob ng DOMContentLoaded
document.addEventListener('DOMContentLoaded', function() {
    // ... existing code ...
    
    // Initialize filter states - lahat closed
    initFilterStates();
});
    </script>
</body>
</html>
<?php $conn->close(); ?>