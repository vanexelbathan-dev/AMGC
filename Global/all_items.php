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

// Get filter parameters
$category = isset($_GET['category']) ? $_GET['category'] : '';
$stock = isset($_GET['stock']) ? $_GET['stock'] : '';
$price = isset($_GET['price']) ? $_GET['price'] : '';

// Build the WHERE clause for items
$where_conditions = [];
$params = [];
$types = "";

// Base condition - only show active items
$where_conditions[] = "i.status = 'active'";

// Add branch filter if user doesn't have view_all_branches permission and branch_id column exists
$check_items_branch = $conn->query("SHOW COLUMNS FROM items LIKE 'branch_id'");
$items_branch_exists = ($check_items_branch && $check_items_branch->num_rows > 0);

if (!$view_all_branches && $user_branch_id > 0 && $items_branch_exists) {
    $where_conditions[] = "i.branch_id = ?";
    $params[] = $user_branch_id;
    $types .= "i";
}

// Category filter
if (!empty($category)) {
    $where_conditions[] = "i.category = ?";
    $params[] = $category;
    $types .= "s";
}

// Stock status filter - based on items.stock column
if (!empty($stock)) {
    if ($stock === 'in_stock') {
        $where_conditions[] = "i.stock >= 10";
    } elseif ($stock === 'low_stock') {
        $where_conditions[] = "i.stock > 0 AND i.stock < i.reorder_level";
    } elseif ($stock === 'out_of_stock') {
        $where_conditions[] = "i.stock <= 0";
    }
}

// Price range filter
if (!empty($price)) {
    if ($price === '0-50') {
        $where_conditions[] = "i.unit_price BETWEEN 0 AND 50";
    } elseif ($price === '50-100') {
        $where_conditions[] = "i.unit_price BETWEEN 50 AND 100";
    } elseif ($price === '100-500') {
        $where_conditions[] = "i.unit_price BETWEEN 100 AND 500";
    } elseif ($price === '500+') {
        $where_conditions[] = "i.unit_price > 500";
    }
}

$where_clause = "WHERE " . implode(" AND ", $where_conditions);

// Get items from database - BASED ONLY ON items TABLE
$sql = "SELECT 
            i.item_id as id, 
            i.item_code, 
            i.item_name, 
            i.description, 
            i.category, 
            i.stock, 
            i.unit_type, 
            i.unit_price, 
            i.reorder_level, 
            i.status,
            i.created_at,
            i.updated_at,
            i.branch_id
        FROM items i
        $where_clause
        ORDER BY i.item_id DESC";

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$items_result = $stmt->get_result();
$items = [];
while ($row = $items_result->fetch_assoc()) {
    $items[] = $row;
}

// Get statistics - BASED ONLY ON items TABLE
$stats_sql = "SELECT 
                COUNT(*) as totalItems,
                SUM(CASE WHEN stock >= 10 THEN 1 ELSE 0 END) as inStockItems,
                SUM(CASE WHEN stock <= 0 THEN 1 ELSE 0 END) as outOfStockItems
              FROM items
              WHERE status = 'active'";

// Add branch filter to stats
if (!$view_all_branches && $user_branch_id > 0 && $items_branch_exists) {
    $stats_sql .= " AND branch_id = $user_branch_id";
}

$stats_result = $conn->query($stats_sql);
$stats = $stats_result->fetch_assoc();

$totalItems = $stats['totalItems'] ?? 0;
$inStockItems = $stats['inStockItems'] ?? 0;
$outOfStockItems = $stats['outOfStockItems'] ?? 0;

// Get unique categories for filter dropdown
$categories_sql = "SELECT DISTINCT category FROM items WHERE status = 'active' AND category IS NOT NULL";

// Add branch filter to categories
if (!$view_all_branches && $user_branch_id > 0 && $items_branch_exists) {
    $categories_sql .= " AND branch_id = $user_branch_id";
}

$categories_sql .= " ORDER BY category";
$categories_result = $conn->query($categories_sql);
$categories = [];
while ($cat_row = $categories_result->fetch_assoc()) {
    $categories[] = $cat_row['category'];
}

// Get user initials for avatar
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

// Handle AJAX request for item details
if (isset($_GET['ajax']) && isset($_GET['id'])) {
    $item_id = intval($_GET['id']);
    $item_sql = "SELECT 
                    i.item_id as id, 
                    i.item_code, 
                    i.item_name, 
                    i.description, 
                    i.category, 
                    i.stock, 
                    i.unit_type, 
                    i.unit_price, 
                    i.reorder_level, 
                    i.status,
                    i.created_at,
                    i.updated_at,
                    i.branch_id
                 FROM items i 
                 WHERE i.item_id = ? AND i.status = 'active'";
    $item_stmt = $conn->prepare($item_sql);
    $item_stmt->bind_param("i", $item_id);
    $item_stmt->execute();
    $item_result = $item_stmt->get_result();
    
    if ($item_result->num_rows > 0) {
        $item = $item_result->fetch_assoc();
        
        // Get branch name if available
        if (!empty($item['branch_id'])) {
            $branch_sql = "SELECT branch_name FROM branches WHERE branch_id = ?";
            $branch_stmt = $conn->prepare($branch_sql);
            $branch_stmt->bind_param("i", $item['branch_id']);
            $branch_stmt->execute();
            $branch_result = $branch_stmt->get_result();
            if ($branch_row = $branch_result->fetch_assoc()) {
                $item['branch_name'] = $branch_row['branch_name'];
            }
        }
        
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'item' => $item]);
    } else {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Item not found']);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Global - All Items Catalog</title>
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
/* I-add ito sa loob ng <style> tag */
.stat-card-row {
    margin-bottom: 1.5rem !important;
}

/* Para sa mobile, ensure na may space */
@media (max-width: 768px) {
    .stat-card-row {
        margin-bottom: 1rem !important;
    }
    
    .form-card {
        margin-top: 0.5rem;
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
                    <!-- Burger icon moved before logo -->
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
                        <a class="nav-link active" href="all_items.php">
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
                </ul>
            </div>
             <!-- User Profile Section at the bottom of sidebar -->
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
            <!-- ALL ITEMS PAGE -->
            <div id="itemsContent" class="page-content active">
                <div class="navbar-top">
                    <button class="mobile-toggle-btn" id="mobileToggleBtn">
                        <i class="bi bi-list"></i>
                    </button>

                    <div class="page-title">
                        <h2>All Items Catalog</h2>
                        <p>View all items across the system, including out-of-stock items</p>
                    </div>
                </div>
<div class="row stat-card-row g-1 g-sm-2">
    <!-- Card 1 - Total Items -->
    <div class="col">
        <div class="stat-card total">
            <i class="bi bi-box"></i>
            <div class="stat-content">
                <div class="stat-value" id="totalItems"><?php echo number_format($totalItems); ?></div>
                <div class="stat-label">Total Items</div>
            </div>
        </div>
    </div>
    
    <!-- Card 2 - In Stock -->
    <div class="col">
        <div class="stat-card sales">
            <i class="bi bi-cart"></i>
            <div class="stat-content">
                <div class="stat-value" id="inStockItems"><?php echo number_format($inStockItems); ?></div>
                <div class="stat-label">In Stock</div>
            </div>
        </div>
    </div>
    
    <!-- Card 3 - Out of Stock -->
    <div class="col">
        <div class="stat-card complete">
            <i class="bi bi-x-circle"></i>
            <div class="stat-content">
                <div class="stat-value" id="outOfStockItems"><?php echo number_format($outOfStockItems); ?></div>
                <div class="stat-label">Out of Stock</div>
            </div>
        </div>
    </div>
</div> <!-- Isang closing div lang dito -->

<!-- Filter Section - Hiwalay na row -->
<div class="row g-3 mb-4">
    <div class="col-12">
        <div class="form-card">
            <div class="d-flex justify-content-between align-items-center">
                <h5 class="mb-0">Filter Items</h5>
            </div>
            <div class="row mt-3">
                <!-- Filter options here -->
                <div class="col-md-4">
                    <label class="form-label">Category</label>
                    <select class="form-select" id="categoryFilter" onchange="applyFilters()">
                        <option value="">All Categories</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?php echo htmlspecialchars($cat); ?>" <?php echo $category == $cat ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($cat); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Stock Status</label>
                    <select class="form-select" id="stockFilter" onchange="applyFilters()">
                        <option value="">All Items</option>
                        <option value="in_stock" <?php echo $stock == 'in_stock' ? 'selected' : ''; ?>>In Stock</option>
                        <option value="low_stock" <?php echo $stock == 'low_stock' ? 'selected' : ''; ?>>Low Stock</option>
                        <option value="out_of_stock" <?php echo $stock == 'out_of_stock' ? 'selected' : ''; ?>>Out of Stock</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Price Range</label>
                    <select class="form-select" id="priceFilter" onchange="applyFilters()">
                        <option value="">All Prices</option>
                        <option value="0-50" <?php echo $price == '0-50' ? 'selected' : ''; ?>>₱0 - ₱50</option>
                        <option value="50-100" <?php echo $price == '50-100' ? 'selected' : ''; ?>>₱50 - ₱100</option>
                        <option value="100-500" <?php echo $price == '100-500' ? 'selected' : ''; ?>>₱100 - ₱500</option>
                        <option value="500+" <?php echo $price == '500+' ? 'selected' : ''; ?>>₱500+</option>
                    </select>
                </div>
            </div>
        </div>
    </div>
</div> <!-- Eto ang closing div para sa filter section -->

                <div class="data-table">
                    <div class="table-header">
                        <h5>Complete Items Catalog</h5>
                    </div>
                    <div class="table-container">
                        <table class="table custom-table compact-table" id="itemsTable">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Item Name</th>
                                    <th>Category</th>
                                    <th>Unit Price</th>
                                    <th>Total Quantity</th>
                                    <th>Available</th>
                                    <th>Stock Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="itemsTable">
                                <?php if (count($items) > 0): ?>
                                    <?php foreach ($items as $item): ?>
                                        <?php 
                                        $stock_quantity = $item['stock'] ?? 0;
                                        $reorder_level = $item['reorder_level'] ?? 50;
                                        
                                        $statusBadge = 'bg-success';
                                        $statusText = 'In Stock';
                                        if ($stock_quantity <= 0) {
                                            $statusBadge = 'bg-danger';
                                            $statusText = 'Out of Stock';
                                        } elseif ($stock_quantity < $reorder_level) {
                                            $statusBadge = 'bg-warning';
                                            $statusText = 'Low Stock';
                                        }
                                        ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($item['id']); ?></td>
                                            <td><strong><?php echo htmlspecialchars($item['item_name']); ?></strong></td>
                                            <td><?php echo htmlspecialchars($item['category'] ?? 'N/A'); ?></td>
                                            <td>₱<?php echo number_format($item['unit_price'] ?? 0, 2); ?></td>
                                            <td><?php echo number_format($stock_quantity); ?></td>
                                            <td><?php echo number_format($stock_quantity); ?></td>
                                            <td>
                                                <span class="badge <?php echo $statusBadge; ?>">
                                                    <?php echo $statusText; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <button class="btn-action btn-view" onclick="viewItem(<?php echo $item['id']; ?>)">
                                                    <i class="bi bi-eye"></i>
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="8" class="text-center py-4">No items found</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
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

    <!-- Item Details Modal -->
    <div class="modal fade" id="itemModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Item Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="itemDetails">
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

        // Apply filters by submitting form
        function applyFilters() {
            const category = document.getElementById('categoryFilter').value;
            const stock = document.getElementById('stockFilter').value;
            const price = document.getElementById('priceFilter').value;
            
            const params = new URLSearchParams();
            if (category) params.append('category', category);
            if (stock) params.append('stock', stock);
            if (price) params.append('price', price);
            
            window.location.href = 'all_items.php?' + params.toString();
        }

        // View item details via AJAX
        function viewItem(id) {
            const modal = new bootstrap.Modal(document.getElementById('itemModal'));
            const details = document.getElementById('itemDetails');
            details.innerHTML = '<p>Loading item details...</p>';
            modal.show();
            
            fetch('all_items.php?ajax=1&id=' + id)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const item = data.item;
                        details.innerHTML = `
                            <dl class="row">
                                <dt class="col-sm-4">Item ID:</dt>
                                <dd class="col-sm-8">${item.id}</dd>
                                <dt class="col-sm-4">Item Code:</dt>
                                <dd class="col-sm-8">${item.item_code || 'N/A'}</dd>
                                <dt class="col-sm-4">Item Name:</dt>
                                <dd class="col-sm-8">${escapeHtml(item.item_name)}</dd>
                                <dt class="col-sm-4">Category:</dt>
                                <dd class="col-sm-8">${escapeHtml(item.category || 'N/A')}</dd>
                                <dt class="col-sm-4">Description:</dt>
                                <dd class="col-sm-8">${escapeHtml(item.description || 'No description')}</dd>
                                <dt class="col-sm-4">Unit Type:</dt>
                                <dd class="col-sm-8">${escapeHtml(item.unit_type || 'piece')}</dd>
                                <dt class="col-sm-4">Unit Price:</dt>
                                <dd class="col-sm-8">₱${parseFloat(item.unit_price || 0).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}</dd>
                                <dt class="col-sm-4">Stock Quantity:</dt>
                                <dd class="col-sm-8">${Number(item.stock || 0).toLocaleString()}</dd>
                                <dt class="col-sm-4">Reorder Level:</dt>
                                <dd class="col-sm-8">${Number(item.reorder_level || 50).toLocaleString()}</dd>
                                <dt class="col-sm-4">Status:</dt>
                                <dd class="col-sm-8"><span class="badge ${item.status === 'active' ? 'bg-success' : 'bg-secondary'}">${escapeHtml(item.status || 'active')}</span></dd>
                                ${item.branch_id ? `<dt class="col-sm-4">Branch:</dt><dd class="col-sm-8">${escapeHtml(item.branch_name || 'Branch ' + item.branch_id)}</dd>` : ''}
                                <dt class="col-sm-4">Created At:</dt>
                                <dd class="col-sm-8">${item.created_at ? new Date(item.created_at).toLocaleString() : 'N/A'}</dd>
                                <dt class="col-sm-4">Updated At:</dt>
                                <dd class="col-sm-8">${item.updated_at ? new Date(item.updated_at).toLocaleString() : 'N/A'}</dd>
                            </dl>
                        `;
                    } else {
                        details.innerHTML = '<p class="text-danger">Failed to load item details.</p>';
                    }
                })
                .catch(error => {
                    console.error('Error loading item details:', error);
                    details.innerHTML = '<p class="text-danger">Error loading item details.</p>';
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
            console.log("Items Management page loaded!");
            
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
                
                if (isMobile && sidebar.classList.contains('active') && 
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
                const itemModal = document.getElementById('itemModal');
                if (itemModal.classList.contains('show')) {
                    bootstrap.Modal.getInstance(itemModal).hide();
                }
            }
        });
    </script>
</body>
</html>
<?php $conn->close(); ?>