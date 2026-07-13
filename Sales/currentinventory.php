<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../config/database.php';
require_once '../config/session_handler.php';


// Protect page - only Sales role can access
requireLogin();
requireRole(['sales']);

// Get current user info
$user_id = $_SESSION['user_id'];
$user_name = isset($_SESSION['first_name']) ? $_SESSION['first_name'] . ' ' . $_SESSION['last_name'] : 'Sales User';
$user_role = isset($_SESSION['role']) ? $_SESSION['role'] : 'sales';
$branch_id = (int)($_SESSION['branch_id'] ?? 0);
$view_all_branches = $_SESSION['view_all_branches'] ?? false;

// Get user's branch name for display
$branch_name = 'All Branches';
if (!$view_all_branches && $branch_id > 0) {
    $branch_query = "SELECT branch_name FROM branches WHERE branch_id = ?";
    $branch_stmt = $conn->prepare($branch_query);
    $branch_stmt->bind_param("i", $branch_id);
    $branch_stmt->execute();
    $branch_result = $branch_stmt->get_result();
    if ($branch_row = $branch_result->fetch_assoc()) {
        $branch_name = $branch_row['branch_name'];
    }
    $branch_stmt->close();
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
    $user_initials = 'SL';
}

// ------------------------------------------------------------
// Date filter - defaults to current month (1st to today)
// ------------------------------------------------------------
$default_start = date('Y-m-01');
$default_end   = date('Y-m-d');
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : $default_start;
$end_date   = isset($_GET['end_date'])   ? $_GET['end_date']   : $default_end;

// ------------------------------------------------------------
// Default monthly quota (adjust as needed)
// ------------------------------------------------------------
$monthly_quota = 100000;

// ------------------------------------------------------------
// Helper filters
// Branch filter keeps the data inside the user's branch.
// Sales user filter keeps dashboard data limited to records entered by the
// currently logged-in sales user, so the dashboard will not show every
// transaction made by other users in the same branch.
// ------------------------------------------------------------
function branchFilter($table_alias) {
    global $view_all_branches, $branch_id;
    if ($view_all_branches) return '';
    return " AND $table_alias.branch_id = " . intval($branch_id);
}

function tableColumnExists($conn, $table_name, $column_name) {
    $query = "SELECT COUNT(*) AS total
              FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = ?
                AND COLUMN_NAME = ?";
    $stmt = $conn->prepare($query);
    if (!$stmt) return false;

    $stmt->bind_param("ss", $table_name, $column_name);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    return !empty($row) && (int)$row['total'] > 0;
}

function detectSalesOrderUserColumn($conn) {
    $possible_columns = [
        'encoded_by',
        'created_by',
        'created_by_user_id',
        'user_id',
        'sales_user_id',
        'sales_id',
        'sales_agent_id',
        'agent_id'
    ];

    foreach ($possible_columns as $column) {
        if (tableColumnExists($conn, 'sales_orders', $column)) {
            return $column;
        }
    }

    return '';
}

$sales_order_user_column = detectSalesOrderUserColumn($conn);

function salesUserFilter($table_alias) {
    global $sales_order_user_column, $user_id;

    if (empty($sales_order_user_column) || empty($user_id)) {
        return '';
    }

    return " AND $table_alias.`" . $sales_order_user_column . "` = " . intval($user_id);
}

function salesOrderScopeFilter($table_alias) {
    return branchFilter($table_alias) . salesUserFilter($table_alias);
}

// ------------------------------------------------------------
// TODAY'S SALES (always current date)
// ------------------------------------------------------------
$today = date('Y-m-d');
$today_sales_query = "SELECT 
                        COALESCE(SUM(total_amount), 0) as total,
                        COUNT(*) as count
                      FROM sales_orders so
                      WHERE DATE(order_date) = ? 
                        AND order_status NOT IN ('cancelled')
                        " . salesOrderScopeFilter('so');
$stmt = $conn->prepare($today_sales_query);
$stmt->bind_param('s', $today);
$stmt->execute();
$today_sales = $stmt->get_result()->fetch_assoc();
$today_total = (float)($today_sales['total'] ?? 0);
$today_orders = (int)($today_sales['count'] ?? 0);

// ------------------------------------------------------------
// CURRENT MONTH SALES (always this month)
// ------------------------------------------------------------
$month_start = date('Y-m-01');
$month_end   = date('Y-m-t');
$month_sales_query = "SELECT 
                        COALESCE(SUM(total_amount), 0) as total,
                        COUNT(*) as count
                      FROM sales_orders so
                      WHERE DATE(order_date) BETWEEN ? AND ?
                        AND order_status NOT IN ('cancelled')
                        " . salesOrderScopeFilter('so');
$stmt = $conn->prepare($month_sales_query);
$stmt->bind_param('ss', $month_start, $month_end);
$stmt->execute();
$month_sales = $stmt->get_result()->fetch_assoc();
$month_total = (float)($month_sales['total'] ?? 0);
$month_orders = (int)($month_sales['count'] ?? 0);
$avg_order = $month_orders > 0 ? round($month_total / $month_orders, 2) : 0;

// ------------------------------------------------------------
// QUOTA CALCULATIONS
// ------------------------------------------------------------
$days_in_month = (int)date('t');
$daily_quota = $monthly_quota / $days_in_month;
$daily_percentage = $daily_quota > 0 ? min(100, round(($today_total / $daily_quota) * 100, 1)) : 0;
$monthly_percentage = $monthly_quota > 0 ? min(100, round(($month_total / $monthly_quota) * 100, 1)) : 0;

// ------------------------------------------------------------
// LOW STOCK COUNT
// ------------------------------------------------------------
$low_stock_query = "SELECT COUNT(*) as low_stock
                    FROM items i
                    WHERE stock <= reorder_level
                      AND status = 'active'
                      " . branchFilter('i');
$result = $conn->query($low_stock_query);
$low_stock = $result ? (int)$result->fetch_assoc()['low_stock'] : 0;

// ------------------------------------------------------------
// TOP PRODUCTS (filtered by selected date range) - for chart
// ------------------------------------------------------------
$top_products_query = "SELECT 
                        i.item_name,
                        SUM(soi.line_total) as total_sales
                      FROM sales_order_items soi
                      JOIN sales_orders so ON soi.so_id = so.so_id
                      JOIN items i ON soi.item_id = i.item_id
                      WHERE DATE(so.order_date) BETWEEN ? AND ?
                        AND so.order_status NOT IN ('cancelled')
                        " . salesOrderScopeFilter('so') . "
                      GROUP BY i.item_id
                      ORDER BY total_sales DESC
                      LIMIT 5";
$stmt = $conn->prepare($top_products_query);
$stmt->bind_param('ss', $start_date, $end_date);
$stmt->execute();
$top_products_result = $stmt->get_result();
$top_product_names = [];
$top_product_sales = [];
while ($row = $top_products_result->fetch_assoc()) {
    $top_product_names[] = $row['item_name'];
    $top_product_sales[] = (float)$row['total_sales'];
}

// ------------------------------------------------------------
// DAILY SALES (filtered by selected date range)
// ------------------------------------------------------------
$daily_sales_query = "SELECT 
                        DATE(order_date) as day,
                        COALESCE(SUM(total_amount), 0) as total
                      FROM sales_orders so
                      WHERE DATE(order_date) BETWEEN ? AND ?
                        AND order_status NOT IN ('cancelled')
                        " . salesOrderScopeFilter('so') . "
                      GROUP BY DATE(order_date)
                      ORDER BY day";
$stmt = $conn->prepare($daily_sales_query);
$stmt->bind_param('ss', $start_date, $end_date);
$stmt->execute();
$daily_result = $stmt->get_result();
$daily_labels = [];
$daily_totals = [];
while ($row = $daily_result->fetch_assoc()) {
    $daily_labels[] = $row['day'];
    $daily_totals[] = (float)$row['total'];
}

// ------------------------------------------------------------
// SALES BY CATEGORY (filtered by selected date range)
// ------------------------------------------------------------
$category_sales_query = "SELECT 
                            i.category,
                            COALESCE(SUM(soi.line_total), 0) as total_sales
                          FROM sales_order_items soi
                          JOIN sales_orders so ON soi.so_id = so.so_id
                          JOIN items i ON soi.item_id = i.item_id
                          WHERE DATE(so.order_date) BETWEEN ? AND ?
                            AND so.order_status NOT IN ('cancelled')
                            " . salesOrderScopeFilter('so') . "
                          GROUP BY i.category
                          ORDER BY total_sales DESC";
$stmt = $conn->prepare($category_sales_query);
$stmt->bind_param('ss', $start_date, $end_date);
$stmt->execute();
$category_result = $stmt->get_result();
$category_labels = [];
$category_totals = [];
while ($row = $category_result->fetch_assoc()) {
    if (!empty($row['category'])) {
        $category_labels[] = $row['category'];
        $category_totals[] = (float)$row['total_sales'];
    }
}

// ------------------------------------------------------------
// SALES BY DAY OF WEEK (filtered by selected date range)
// ------------------------------------------------------------
$day_of_week_query = "SELECT 
                        DAYOFWEEK(order_date) as dow,
                        COALESCE(SUM(total_amount), 0) as total
                      FROM sales_orders so
                      WHERE DATE(order_date) BETWEEN ? AND ?
                        AND order_status NOT IN ('cancelled')
                        " . salesOrderScopeFilter('so') . "
                      GROUP BY dow";
$stmt = $conn->prepare($day_of_week_query);
$stmt->bind_param('ss', $start_date, $end_date);
$stmt->execute();
$dow_result = $stmt->get_result();
$dow_totals = array_fill(1, 7, 0);
while ($row = $dow_result->fetch_assoc()) {
    $dow_totals[(int)$row['dow']] = (float)$row['total'];
}

// ------------------------------------------------------------
// RECENT ORDERS (filtered by selected date range, latest 10)
// ------------------------------------------------------------
$recent_query = "SELECT 
                    so.so_number,
                    so.order_date,
                    so.total_amount,
                    so.order_status,
                    c.customer_name
                  FROM sales_orders so
                  LEFT JOIN customers c ON so.customer_id = c.customer_id
                  WHERE DATE(so.order_date) BETWEEN ? AND ?
                    AND so.order_status NOT IN ('cancelled')
                    " . salesOrderScopeFilter('so') . "
                  ORDER BY so.order_date DESC
                  LIMIT 10";
$stmt = $conn->prepare($recent_query);
$stmt->bind_param('ss', $start_date, $end_date);
$stmt->execute();
$recent_result = $stmt->get_result();
$recent_orders = $recent_result ? $recent_result->fetch_all(MYSQLI_ASSOC) : [];

$month_names = [
    1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
    5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
    9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'
];

// Determine active filter for highlighting
$active_filter = 'custom';
if ($start_date === $today && $end_date === $today) {
    $active_filter = 'today';
} elseif ($start_date === date('Y-m-01') && $end_date === date('Y-m-d')) {
    $active_filter = 'month';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sales Dashboard</title>
    <link rel="icon" type="image/png" href="../Pictures/favicon-96x96.png" sizes="96x96" />
    <link rel="icon" type="image/svg+xml" href="../Pictures/favicon.svg" />
    <link rel="shortcut icon" href="../Pictures/favicon.ico" />
    <link rel="apple-touch-icon" sizes="180x180" href="../Pictures/apple-touch-icon.png" />
    <link rel="manifest" href="../Pictures/site.webmanifest" />
    <link rel="stylesheet" href="../css/sales.css">
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <!-- SweetAlert2 -->
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
        <!-- Session Checker -->
    <script src="../js/session-checker.js"></script>
   <style>
    /* ===== SUPER RESPONSIVE STAT CARDS - SAME ROW, NO BREAK, NO SCROLL ===== */

/* Base styles - mobile first approach */
.stat-card {
    border: none;
    border-radius: 12px;
    color: white;
    padding: 0.6rem 0.3rem;
    margin: 0;
    transition: all 0.3s ease;
    box-shadow: 0 4px 10px rgba(0, 0, 0, 0.08);
    height: 100%;
    width: 100%;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    text-align: center;
    cursor: pointer;
    background: linear-gradient(135deg, #047857, #059669);
    overflow: visible;
}

.stat-card.clickable:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 20px rgba(0, 0, 0, 0.15);
}

/* Stat Card Colors */
.stat-card.total, 
.stat-card.sales, 
.stat-card.quota,
.stat-card.complete,
.stat-card.pending { 
    background: linear-gradient(135deg, #047857, #059669) !important;
}

/* Responsive icons */
.stat-card i,
.stat-card .stat-icon {
    font-size: clamp(0.7rem, 5vw, 1.8rem);
    margin-bottom: 0.15rem;
    flex-shrink: 0;
}

/* TEXT - allowed mag-wrap kung kinakailangan */
.stat-value {
    font-size: clamp(0.6rem, 4vw, 1.3rem);
    font-weight: 700;
    line-height: 1.2;
    margin: 0.1rem 0;
    color: white;
    word-break: break-all;
    overflow-wrap: break-word;
    max-width: 100%;
    padding: 0 2px;
}

.stat-label {
    font-size: clamp(0.5rem, 3vw, 0.75rem);
    font-weight: 500;
    opacity: 0.95;
    color: white;
    word-break: break-word;
    overflow-wrap: break-word;
    line-height: 1.2;
    max-width: 100%;
    padding: 0 2px;
}

.stat-card small,
.stat-card .text-white-50 {
    font-size: clamp(0.4rem, 2.5vw, 0.6rem);
    opacity: 0.85;
    margin-top: 0.15rem;
    color: rgba(255,255,255,0.8);
    word-break: break-word;
    overflow-wrap: break-word;
    max-width: 100%;
    padding: 0 2px;
}

/* Progress bar styling */
.stat-card .progress {
    background-color: rgba(255, 255, 255, 0.2) !important;
    height: 2px !important;
    border-radius: 2px !important;
    margin-top: 0.25rem !important;
    margin-bottom: 0.1rem !important;
    width: 90% !important;
    margin-left: auto !important;
    margin-right: auto !important;
}

.stat-card .progress-bar {
    background-color: rgba(255, 255, 255, 0.8) !important;
    border-radius: 2px !important;
}

/* ===== ROW STYLING - NO SCROLL ===== */
.row.stat-card-row {
    display: flex;
    flex-wrap: nowrap !important;
    overflow-x: visible !important;
    margin: 0 !important; /* Remove negative margins */
    margin-bottom: 1.5rem !important;
    width: 100%;
}

.row.stat-card-row > .col,
.row.stat-card-row > [class*="col-"] {
    flex: 1 1 0 !important;
    min-width: 0 !important;
    max-width: 100%;
    padding: 0 4px !important;
    margin-bottom: 0;
}

/* ===== MOBILE (max-width: 767px) ===== */
@media (max-width: 767px) {
    .row.stat-card-row {
        gap: 4px;
    }
    
    .row.stat-card-row > .col,
    .row.stat-card-row > [class*="col-"] {
        padding: 0 2px !important;
    }
    
    .stat-card {
        padding: 0.4rem 0.2rem;
    }
    
    .stat-card i,
    .stat-card .stat-icon {
        font-size: 0.85rem;
        margin-bottom: 0.1rem;
    }
    
    .stat-value {
        font-size: 0.65rem;
    }
    
    .stat-label {
        font-size: 0.5rem;
    }
    
    .stat-card small,
    .stat-card .text-white-50 {
        font-size: 0.4rem;
        display: none;
    }
    
    .stat-card .progress {
        display: none;
    }
}

/* ===== EXTRA SMALL MOBILE (below 400px) ===== */
@media (max-width: 400px) {
    .row.stat-card-row {
        gap: 3px;
    }
    
    .stat-card {
        padding: 0.35rem 0.15rem;
    }
    
    .stat-card i,
    .stat-card .stat-icon {
        font-size: 0.7rem;
    }
    
    .stat-value {
        font-size: 0.55rem;
    }
    
    .stat-label {
        font-size: 0.45rem;
    }
}

/* ===== VERY SMALL (below 350px) ===== */
@media (max-width: 350px) {
    .row.stat-card-row {
        gap: 2px;
    }
    
    .stat-card {
        padding: 0.3rem 0.1rem;
    }
    
    .stat-card i,
    .stat-card .stat-icon {
        font-size: 0.6rem;
    }
    
    .stat-value {
        font-size: 0.5rem;
    }
    
    .stat-label {
        font-size: 0.4rem;
    }
}

/* ===== TABLET (768px - 991px) ===== */
@media (min-width: 768px) and (max-width: 991px) {
    .row.stat-card-row {
        gap: 6px;
    }
    
    .stat-card {
        padding: 0.6rem 0.3rem;
    }
    
    .stat-card i,
    .stat-card .stat-icon {
        font-size: 1.1rem;
    }
    
    .stat-value {
        font-size: 0.85rem;
    }
    
    .stat-label {
        font-size: 0.6rem;
    }
    
    .stat-card small,
    .stat-card .text-white-50 {
        font-size: 0.45rem;
        display: block;
    }
    
    .stat-card .progress {
        display: flex;
        width: 100%;
    }
}

/* ===== DESKTOP (min-width: 992px) ===== */
@media (min-width: 992px) {
    .row.stat-card-row {
        gap: 12px;
        margin-bottom: 1.5rem !important;
    }
    
    .row.stat-card-row > .col,
    .row.stat-card-row > [class*="col-"] {
        padding: 0 !important;
    }
    
    /* Horizontal layout */
    .stat-card {
        flex-direction: row !important;
        align-items: center !important;
        justify-content: flex-start !important;
        text-align: left !important;
        padding: 1rem !important;
        min-height: 100px;
    }
    
    .stat-card i,
    .stat-card .stat-icon {
        font-size: 1.5rem !important;
        margin: 0 0.75rem 0 0 !important;
        align-self: center !important;
        flex-shrink: 0;
    }
    
    .stat-card .stat-content {
        display: flex !important;
        flex-direction: column !important;
        align-items: flex-start !important;
        text-align: left !important;
        flex: 1;
        min-width: 0;
    }
    
    .stat-card .stat-value {
        font-size: 1.1rem !important;
        text-align: left !important;
        margin: 0 0 0.2rem 0 !important;
        white-space: nowrap !important;
        word-break: normal !important;
    }
    
    .stat-card .stat-label {
        font-size: 0.7rem !important;
        text-align: left !important;
        white-space: nowrap !important;
    }
    
    .stat-card .progress {
        display: flex !important;
        height: 4px !important;
        width: 100% !important;
        margin: 6px 0 0 0 !important;
    }
    
    .stat-card .text-white-50,
    .stat-card small {
        display: block !important;
        font-size: 0.55rem !important;
        margin-top: 4px !important;
        white-space: nowrap !important;
    }
}

/* ===== LANDSCAPE MODE ON MOBILE ===== */
@media (max-width: 767px) and (orientation: landscape) {
    .stat-card {
        padding: 0.3rem 0.15rem;
        min-height: 50px;
    }
    
    .stat-card i,
    .stat-card .stat-icon {
        font-size: 0.7rem;
    }
    
    .stat-value {
        font-size: 0.55rem;
    }
    
    .stat-label {
        font-size: 0.45rem;
    }
    
    .stat-card small,
    .stat-card .text-white-50,
    .stat-card .progress {
        display: none;
    }
}

/* ===== ACTIVE FILTER ===== */
.stat-card.active-filter {
    box-shadow: 0 0 0 2px white, 0 4px 10px rgba(0, 0, 0, 0.08) !important;
    transform: translateY(-2px) !important;
}

.stat-card.clickable {
    cursor: pointer;
}

@media (min-width: 992px) {
    .stat-card.clickable:hover {
        transform: translateY(-2px) !important;
        box-shadow: 0 6px 15px rgba(0, 0, 0, 0.15) !important;
    }
}

@media (max-width: 991px) {
    .stat-card.clickable:active {
        transform: scale(0.98) !important;
    }
}

/* ===== PREVENT BODY SCROLL ===== */
body {
    overflow-x: hidden !important;
    width: 100% !important;
    position: relative !important;
}

.container, .container-fluid {
    overflow-x: hidden !important;
}
       /* ===== CHART STYLES ===== */
    
    /* Chart container styling */
    .chart-container {
        background: white;
        border-radius: 16px;
        padding: 1.25rem;
        margin-bottom: 1.5rem;
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.04);
        border: 1px solid #d1fae5;
        transition: all 0.3s ease;
    }
    
    .chart-container:hover {
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
    }
    
    /* Chart title styling */
    .chart-title {
        font-size: 1rem;
        font-weight: 600;
        color: #1f2937;
        margin-bottom: 1rem;
        padding-bottom: 0.5rem;
        border-bottom: 2px solid #d1fae5;
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 8px;
    }
    
    .chart-title i {
        color: #047857;
        margin-right: 8px;
    }
    
    .chart-title .badge {
        background: #d1fae5 !important;
        color: #047857 !important;
        font-weight: 600;
        padding: 0.25rem 0.75rem;
        border-radius: 20px;
    }
    
    /* Canvas wrapper for better responsiveness */
    canvas {
        max-width: 100%;
        height: auto !important;
    }
    
    /* Chart legend styling */
    .chart-legend {
        display: flex;
        justify-content: center;
        gap: 1rem;
        margin-top: 0.75rem;
        flex-wrap: wrap;
    }
    
    .chart-legend-item {
        display: flex;
        align-items: center;
        gap: 0.35rem;
        font-size: 0.7rem;
        color: #6b7280;
    }
    
    .chart-legend-color {
        width: 12px;
        height: 12px;
        border-radius: 3px;
    }
    
    /* No data message styling */
    .no-data-message {
        height: 200px;
        display: flex;
        align-items: center;
        justify-content: center;
        color: #9ca3af;
        font-style: italic;
        font-size: 0.9rem;
    }
    
    /* Chart tooltip customization (via CSS variables) */
    .chartjs-tooltip {
        background: rgba(0, 0, 0, 0.8) !important;
        border-radius: 8px !important;
        padding: 0.5rem !important;
        color: white !important;
        font-size: 0.75rem !important;
    }
    
    /* ===== MOBILE CHART ADJUSTMENTS - LARGER FOR MOBILE ===== */
    @media (max-width: 991px) {
        /* Increase chart height on mobile */
        #dailyChart,
        #topProductsChart,
        #categoryChart,
        #dowChart {
            min-height: 280px !important;
            max-height: 320px !important;
            height: auto !important;
        }
        
        .chart-container {
            padding: 1rem;
        }
        
        .chart-title {
            font-size: 0.95rem;
            flex-direction: row;
            flex-wrap: wrap;
            justify-content: space-between;
        }
        
        .chart-title .badge {
            font-size: 0.7rem;
        }
    }
    
    /* ===== TABLET ADJUSTMENTS ===== */
    @media (min-width: 768px) and (max-width: 991px) {
        #dailyChart,
        #topProductsChart,
        #categoryChart,
        #dowChart {
            min-height: 300px !important;
            max-height: 350px !important;
        }
    }
    
    /* ===== DESKTOP CHART HEIGHTS ===== */
    @media (min-width: 992px) {
        #dailyChart {
            min-height: 280px;
            max-height: 320px;
        }
        
        #topProductsChart {
            min-height: 280px;
            max-height: 320px;
        }
        
        #categoryChart {
            min-height: 280px;
            max-height: 320px;
        }
        
        #dowChart {
            min-height: 280px;
            max-height: 320px;
        }
    }
    
    /* ===== EXTRA SMALL MOBILE (below 480px) ===== */
    @media (max-width: 480px) {
        #dailyChart,
        #topProductsChart,
        #categoryChart,
        #dowChart {
            min-height: 250px !important;
            max-height: 280px !important;
        }
        
        .chart-container {
            padding: 0.85rem;
        }
        
        .chart-title {
            font-size: 0.85rem;
            flex-direction: column;
            align-items: flex-start;
            gap: 4px;
        }
        
        .chart-title .badge {
            font-size: 0.65rem;
            align-self: flex-start;
        }
    }
    /* Status badges */
    .badge-status {
        padding: 4px 10px;
        border-radius: 20px;
        font-weight: 500;
        font-size: 0.7rem;
        display: inline-block;
    }

    .status-completed { background: rgba(16, 185, 129, 0.15); color: #059669; }
    .status-pending { background: rgba(245, 158, 11, 0.15); color: #d97706; }
    .status-processing { background: rgba(59, 130, 246, 0.15); color: #3b82f6; }
    .status-cancelled { background: rgba(239, 68, 68, 0.15); color: #dc2626; }

    .no-data-message {
        height: 200px;
        display: flex;
        align-items: center;
        justify-content: center;
        color: #9ca3af;
        font-style: italic;
    }

    /* Buttons */
    .btn-primary {
        background: linear-gradient(135deg, #047857, #44D34E);
        border: none;
        border-radius: 8px;
        padding: 0.5rem 1rem;
        font-weight: 500;
        color: white;
    }

    .btn-primary:hover {
        background: linear-gradient(135deg, #065f46, #047857);
        transform: translateY(-1px);
    }

    .btn-outline-secondary {
        border: 1px solid #d1fae5;
        color: #047857;
        background: white;
    }

    .btn-outline-secondary:hover {
        background: #d1fae5;
        color: #047857;
    }

    /* Form controls */
    .form-control, .form-select {
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        padding: 0.5rem 0.75rem;
        font-size: 0.9rem;
    }

    .form-control:focus, .form-select:focus {
        border-color: #44D34E;
        box-shadow: 0 0 0 3px rgba(68, 211, 78, 0.2);
    }

    /* Table */
    .card {
        border: none !important;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05) !important;
    }

    .table thead th {
        background: #047857 !important;
        color: white !important;
        border: none !important;
        padding: 0.75rem 1rem !important;
        font-size: 0.8rem !important;
        font-weight: 600 !important;
    }

    .table tbody td {
        padding: 0.75rem 1rem !important;
        vertical-align: middle !important;
        border-bottom: 1px solid #d1fae5 !important;
    }

    .table tbody tr:hover {
        background-color: #d1fae5 !important;
    }

    /* Mobile Profile Modal Styles */
    .user-avatar-large {
        width: 100px;
        height: 100px;
        border-radius: 50%;
        background: linear-gradient(135deg, #047857, #44D34E);
        color: white;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 2.5rem;
        font-weight: bold;
        margin: 0 auto;
        border: 4px solid #d1fae5;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
    }

    #profileModal .modal-content {
        border: none;
        border-radius: 20px;
        overflow: hidden;
    }

    #profileModal .modal-header {
        background: linear-gradient(135deg, #047857, #44D34E);
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
        background: #d1fae5;
        color: #047857;
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
    
    /* Responsive adjustments for mobile */
    @media (max-width: 768px) {
        .filter-bar {
            flex-direction: column;
        }
        .filter-item {
            width: 100%;
        }
        .btn-primary, .btn-outline-secondary {
            width: 100%;
        }
        .chart-title {
            flex-direction: column;
            align-items: flex-start;
        }
    }
        /* ===== RECENT ORDERS TABLE STYLES ===== */
    
    /* Desktop: Normal table layout */
    .card {
        border: none !important;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05) !important;
        margin-bottom: 1.5rem;
        border-radius: 16px;
        overflow: hidden;
    }
    
    .table thead th {
        background: #047857 !important;
        color: white !important;
        border: none !important;
        padding: 0.85rem 1rem !important;
        font-size: 0.8rem !important;
        font-weight: 600 !important;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    
    .table tbody td {
        padding: 0.85rem 1rem !important;
        vertical-align: middle !important;
        border-bottom: 1px solid #d1fae5 !important;
        font-size: 0.9rem;
    }
    
    /* ===== MOBILE: HORIZONTAL CARD STYLE LAYOUT ===== */
    @media (max-width: 768px) {
        /* Hide table header on mobile */
        .table thead {
            display: none;
        }
        
        /* Make each row a card */
        .table tbody tr {
            display: block;
            margin-bottom: 0.75rem;
            background: white;
            border: 1px solid #d1fae5;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 1px 4px rgba(0, 0, 0, 0.04);
            transition: all 0.2s ease;
        }
        
        .table tbody tr:hover {
            transform: translateY(-1px);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
        }
        
        /* Make cells horizontal (label and value side by side) */
        .table tbody td {
            display: flex;
            align-items: center;
            justify-content: flex-start;
            padding: 0.6rem 0.9rem !important;
            border-bottom: 1px solid #f0fdf4;
            gap: 0.75rem;
            flex-wrap: wrap;
        }
        
        .table tbody td:last-child {
            border-bottom: none;
        }
        
        /* Label styling - fixed width, bold */
        .table tbody td:before {
            content: attr(data-label);
            font-weight: 600;
            color: #047857;
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            min-width: 85px;
            flex-shrink: 0;
        }
        
        /* Value styling - flexible, normal weight */
        .table tbody td .value-content,
        .table tbody td strong {
            font-weight: 500;
            color: #1e293b;
            font-size: 0.85rem;
        }
        
        .table tbody td .badge-status {
            font-weight: 500;
            color: #a77700;
            font-size: 0.85rem;
        }

        /* SO Number - special styling */
        .table tbody td:first-child:before {
            font-weight: 700;
        }
        
        .table tbody td:first-child strong {
            font-weight: 700;
            color: #047857;
            font-family: monospace;
            font-size: 0.85rem;
        }
        
        /* Amount styling */
        .table tbody td:nth-child(4) .value-content {
            font-weight: 700;
            color: #047857;
        }
        
        /* Status badge styling */
        .table tbody td:last-child .badge-status {
            padding: 0.25rem 0.75rem;
            font-size: 0.7rem;
            font-weight: 600;
            border-radius: 50px;
            display: inline-block;
        }
    }
    
    /* Tablet (768px - 991px) - keep normal table but smaller */
    @media (min-width: 768px) and (max-width: 991px) {
        .table thead th {
            font-size: 0.7rem;
            padding: 0.6rem 0.8rem !important;
        }
        
        .table tbody td {
            padding: 0.6rem 0.8rem !important;
            font-size: 0.8rem;
        }
        
        .badge-status {
            padding: 0.2rem 0.6rem;
            font-size: 0.65rem;
        }
    }
        /* ===== VIEW ALL BUTTON STYLES ===== */
    .btn-view-all {
        background: rgba(255, 255, 255, 0.2);
        border: 1px solid rgba(255, 255, 255, 0.3);
        color: white;
        padding: 0.35rem 1rem;
        border-radius: 50px;
        font-size: 0.75rem;
        font-weight: 500;
        text-decoration: none;
        transition: all 0.2s ease;
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
    }
    
    .btn-view-all:hover {
        background: rgba(255, 255, 255, 0.3);
        border-color: rgba(255, 255, 255, 0.5);
        color: white;
        transform: translateY(-1px);
    }
    
    .btn-view-all i {
        font-size: 0.8rem;
    }
    
    /* Mobile view all button */
    @media (max-width: 768px) {
        .btn-view-all {
            padding: 0.25rem 0.8rem;
            font-size: 0.7rem;
        }
        
        .btn-view-all i {
            font-size: 0.7rem;
        }
    }
    
    /* ===== REMOVE WHITE LINE IN TABLE HEADER ===== */
    .table thead th {
        background: #047857 !important;
        color: white !important;
        border: none !important;
        padding: 0.85rem 1rem !important;
        font-size: 0.8rem !important;
        font-weight: 600 !important;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        border-bottom: none !important; /* Remove white line */
    }
    
    /* Ensure no border on table header row */
    .table thead tr {
        border: none !important;
    }
    
    .table thead {
        border: none !important;
    }
    
    /* Remove any border from table */
    .table {
        border-collapse: collapse !important;
    }
    
    /* Table header container - no border */
    .table-header {
        border-bottom: none !important;
    }
    
    /* Card header - remove any border */
    .card {
        border: none !important;
        overflow: hidden;
    }
    
    /* Table container - remove border */
    .table-container {
        border: none !important;
    }
</style>
</head>
<body>
    <div id="appPage">
        <!-- Sidebar -->
        <div class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <h3>
                    <button class="desktop-toggle-btn" id="desktopToggleBtn">
                        <i class="bi bi-list" id="toggleIcon"></i>
                    </button>
                    <img src="../Pictures/amgc3DLogo.png" alt="Logo" class="logo-icon"> 
                    <span class="nav-text">Sales</span>
                </h3>
            </div>
            <div class="sidebar-menu">
                <ul class="nav flex-column">
                    <li class="nav-item">
                        <a class="nav-link active" href="currentinventory.php">
                            <i class="bi bi-speedometer2"></i>
                            <span class="nav-text">Dashboard</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="customer.php">
                            <i class="bi bi-people"></i>
                            <span class="nav-text">Customer</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="returnedmerchandise.php">
                            <i class="bi bi-arrow-counterclockwise"></i>
                            <span class="nav-text">Returned Merchandise</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="sales_order.php">
                            <i class="bi bi-list-check"></i>
                            <span class="nav-text">Sales Orders</span>
                        </a>
                    </li>
                     <li class="nav-item">
                         <a class="nav-link" href="sales_collections.php">
                             <i class="bi bi-cash-stack"></i>
                             <span class="nav-text">Collections</span>
                        </a>
                    </li>
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
        <div class="main-content">
            <!-- Top Bar -->
            <div class="navbar-top">
                <button class="mobile-toggle-btn" id="mobileToggleBtn">
                    <i class="bi bi-list"></i>
                </button>
                <div class="page-title">
                    <h2>Sales Dashboard</h2>
                    <p>Welcome back, <?php echo htmlspecialchars($user_name); ?></p>
                </div>
            </div>

           <!-- KPI Cards - Lahat sa isang row, walang break -->
<div class="row stat-card-row g-1 g-sm-2 mb-4 no-print">
    <!-- Today's Sales -->
    <div class="col">
        <div class="stat-card total clickable <?php echo $active_filter === 'today' ? 'active-filter' : ''; ?>" onclick="filterByDate('today')">
            <i class="bi bi-calendar-day stat-icon"></i>
            <div class="stat-content">
                <div class="stat-value">₱<?php echo number_format($today_total, 2); ?></div>
                <div class="stat-label">Today's Sales</div>
                <div class="progress">
                    <div class="progress-bar" style="width: <?php echo $daily_percentage; ?>%"></div>
                </div>
                <small><?php echo $daily_percentage; ?>% of quota</small>
            </div>
        </div>
    </div>
    
    <!-- Monthly Sales -->
    <div class="col">
        <div class="stat-card sales clickable <?php echo $active_filter === 'month' ? 'active-filter' : ''; ?>" onclick="filterByDate('month')">
            <i class="bi bi-graph-up stat-icon"></i>
            <div class="stat-content">
                <div class="stat-value">₱<?php echo number_format($month_total, 2); ?></div>
                <div class="stat-label">Monthly Sales</div>
                <div class="progress">
                    <div class="progress-bar" style="width: <?php echo $monthly_percentage; ?>%"></div>
                </div>
                <small><?php echo $monthly_percentage; ?>% of ₱<?php echo number_format($monthly_quota, 2); ?></small>
            </div>
        </div>
    </div>
    
    <!-- Orders This Month -->
    <div class="col">
        <div class="stat-card quota clickable" onclick="filterByDate('month')">
            <i class="bi bi-receipt stat-icon"></i>
            <div class="stat-content">
                <div class="stat-value"><?php echo $month_orders; ?></div>
                <div class="stat-label">Orders This Month</div>
                <small>₱<?php echo number_format($avg_order, 2); ?> avg</small>
            </div>
        </div>
    </div>
    
    <!-- Low Stock -->
    <div class="col">
        <div class="stat-card pending">
            <i class="bi bi-box-seam stat-icon"></i>
            <div class="stat-content">
                <div class="stat-value"><?php echo $low_stock; ?></div>
                <div class="stat-label">Low Stock</div>
            </div>
        </div>
    </div>
</div>
                                   <!-- FILTER SECTION - COLLAPSIBLE DESIGN (Like Picklist) -->
            <div class="form-card mb-4">
                <div class="filter-header">
                    <h5>
                        <i class="bi bi-funnel"></i> Filter Dashboard
                    </h5>
                    <button class="filter-toggle-btn" type="button" id="filterToggleBtn" aria-expanded="false">
                        <i class="bi bi-chevron-down" id="filterIcon"></i>
                    </button>
                </div>
                
                <div class="filter-content collapsed" id="filterContent">
                    <!-- All filters in one row for desktop -->
                    <div class="row g-3 align-items-end">
                        <!-- Start Date -->
                        <div class="col-12 col-sm-6 col-md-3">
                            <label class="form-label">
                                <i class="bi bi-calendar"></i> Start Date
                            </label>
                            <input type="date" class="form-control" id="startDate" value="<?php echo $start_date; ?>">
                        </div>
                        
                        <!-- End Date -->
                        <div class="col-12 col-sm-6 col-md-3">
                            <label class="form-label">
                                <i class="bi bi-calendar"></i> End Date
                            </label>
                            <input type="date" class="form-control" id="endDate" value="<?php echo $end_date; ?>">
                        </div>
                        
                        <!-- Apply Filter Button -->
                        <div class="col-12 col-sm-6 col-md-3">
                            <button class="btn btn-primary w-100" onclick="applyDateFilter()">
                                <i class="bi bi-funnel"></i> Apply Filter
                            </button>
                        </div>
                        
                        <!-- Current Month Button -->
                        <div class="col-12 col-sm-6 col-md-3">
                            <button class="btn btn-outline-secondary w-100" onclick="resetToCurrentMonth()">
                                <i class="bi bi-calendar-month"></i> Current Month
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Charts Row 1 -->
            <div class="row">
                <div class="col-md-8">
                    <div class="chart-container">
                        <div class="chart-title">
                            <span><i class="bi bi-calendar-range"></i> Daily Sales (<?php echo date('M d, Y', strtotime($start_date)); ?> - <?php echo date('M d, Y', strtotime($end_date)); ?>)</span>
                            <span class="badge bg-light text-dark">₱<?php echo number_format(array_sum($daily_totals), 2); ?> total</span>
                        </div>
                        <canvas id="dailyChart" style="height: 250px; width: 100%;"></canvas>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="chart-container">
                        <div class="chart-title">
                            <span><i class="bi bi-trophy"></i> Top Products</span>
                        </div>
                        <?php if (empty($top_product_names)): ?>
                            <div class="no-data-message">No sales in selected period</div>
                        <?php else: ?>
                            <canvas id="topProductsChart" style="height: 250px; width: 100%;"></canvas>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Charts Row 2 -->
            <div class="row mt-2">
                <div class="col-md-6">
                    <div class="chart-container">
                        <div class="chart-title">
                            <span><i class="bi bi-pie-chart"></i> Sales by Category</span>
                        </div>
                        <?php if (empty($category_labels)): ?>
                            <div class="no-data-message">No sales in selected period</div>
                        <?php else: ?>
                            <canvas id="categoryChart" style="height: 250px; width: 100%;"></canvas>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="chart-container">
                        <div class="chart-title">
                            <span><i class="bi bi-bar-chart"></i> Sales by Day of Week</span>
                        </div>
                        <canvas id="dowChart" style="height: 250px; width: 100%;"></canvas>
                    </div>
                </div>
            </div>

            <!-- Recent Orders Table -->
            <div class="card">
                <div class="table-container">
                    <div class="table-header" style="background: #047857; padding: 0.75rem 1.25rem;">
                        <h5 style="color: white; margin: 0; display: flex; justify-content: space-between; align-items: center;">
                            <span><i class="bi bi-clock-history"></i> Recent Orders</span>
                           <a href="sales_order.php" class="btn-view-all">
                                <i class="bi bi-box-arrow-up-right"></i> View All
                            </a>
                        </h5>
                    </div>
                    <div class="table-responsive">
                        <table class="table custom-table compact-table">
                            <thead>
                                <tr>
                                    <th>Order #</th>
                                    <th>Customer</th>
                                    <th>Date</th>
                                    <th>Total</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                                                       <tbody>
                            <?php if (empty($recent_orders)): ?>
                                <tr><td colspan="5" class="text-center text-muted py-4">No orders in selected period</td></tr>
                            <?php else: ?>
                                <?php foreach ($recent_orders as $o): ?>
                                <tr>
                                    <td data-label="SO NUMBER">
                                        <strong><?php echo htmlspecialchars($o['so_number']); ?></strong>
                                    </td>
                                    <td data-label="CUSTOMER">
                                        <?php echo htmlspecialchars($o['customer_name'] ?? 'Walk-in'); ?>
                                    </td>
                                    <td data-label="DATE">
                                        <?php echo date('M d, Y', strtotime($o['order_date'])); ?>
                                    </td>
                                    <td data-label="AMOUNT">
                                        ₱<?php echo number_format($o['total_amount'], 2); ?>
                                    </td>
                                    <td data-label="STATUS">
                                        <?php
                                        $status_class = match($o['order_status']) {
                                            'delivered' => 'status-completed',
                                            'pending'   => 'status-pending',
                                            'processing'=> 'status-processing',
                                            'cancelled' => 'status-cancelled',
                                            default     => 'bg-secondary text-white'
                                        };
                                        $status_label = match($o['order_status']) {
                                            'delivered' => 'Delivered',
                                            'pending'   => 'Pending',
                                            'processing'=> 'Processing',
                                            'cancelled' => 'Cancelled',
                                            default     => ucfirst($o['order_status'])
                                        };
                                        ?>
                                        <span class="badge-status <?php echo $status_class; ?>">
                                            <?php echo $status_label; ?>
                                        </span>
                                    </td>
                                  
                                <?php endforeach; ?>
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
                <a class="nav-link" href="currentinventory.php">
                    <i class="bi bi-speedometer2"></i>
                    <span>Dashboard</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="customer.php">
                    <i class="bi bi-people"></i>
                    <span>Customers</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="returnedmerchandise.php">
                    <i class="bi bi-arrow-counterclockwise"></i>
                    <span>Returns</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="sales_order.php">
                    <i class="bi bi-list-check"></i>
                    <span>Sales Orders</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="sales_collections.php">
                    <i class="bi bi-cash-stack"></i>
                    <span>Collections</span>
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
                    <?php if (!$view_all_branches && $branch_id > 0): ?>
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

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // ================= GLOBAL VARIABLES =================
        const userRole = '<?php echo $user_role; ?>';
        const userInitials = '<?php echo $user_initials; ?>';
        const branchName = '<?php echo $branch_name; ?>';
        const userId = <?php echo $user_id; ?>;

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
                        setTimeout(() => overlay.remove(), 300);
                    }
                }
            } else {
                sidebar.classList.toggle('collapsed');
                localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
                document.querySelectorAll('.nav-text').forEach(t => t.style.display = sidebar.classList.contains('collapsed') ? 'none' : 'inline-block');
                document.querySelector('.main-content').style.marginLeft = sidebar.classList.contains('collapsed') ? '80px' : '250px';
            }
        }
        
        function closeMobileSidebar() {
            document.getElementById('sidebar').classList.remove('active');
            const overlay = document.querySelector('.sidebar-overlay');
            if (overlay) {
                overlay.classList.remove('active');
                setTimeout(() => overlay.remove(), 300);
            }
        }
        
        function initializeSidebar() {
            const sidebar = document.getElementById('sidebar');
            if (window.innerWidth > 992) {
                const collapsed = localStorage.getItem('sidebarCollapsed') === 'true';
                sidebar.classList.toggle('collapsed', collapsed);
                document.querySelectorAll('.nav-text').forEach(t => t.style.display = collapsed ? 'none' : 'inline-block');
                document.querySelector('.main-content').style.marginLeft = collapsed ? '80px' : '250px';
            } else {
                sidebar.classList.remove('active', 'collapsed');
                document.querySelectorAll('.nav-text').forEach(t => t.style.display = 'inline-block');
                document.querySelector('.main-content').style.marginLeft = '0';
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
                const collapsed = localStorage.getItem('sidebarCollapsed') === 'true';
                sidebar.classList.toggle('collapsed', collapsed);
                document.querySelectorAll('.nav-text').forEach(t => t.style.display = collapsed ? 'none' : 'inline-block');
                document.querySelector('.main-content').style.marginLeft = collapsed ? '80px' : '250px';
            } else {
                sidebar.classList.remove('collapsed');
                document.querySelectorAll('.nav-text').forEach(t => t.style.display = 'inline-block');
                document.querySelector('.main-content').style.marginLeft = '0';
            }
        }

        // ================= MOBILE NAVIGATION FUNCTIONS =================
        function initMobileNav() {
            const mobileNav = document.getElementById('mobileNav');
            const isMobile = window.innerWidth <= 992;
            
            if (isMobile) {
                mobileNav.style.display = 'block';
                
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
            const modal = bootstrap.Modal.getInstance(document.getElementById('profileModal'));
            if (modal) {
                modal.hide();
            }
            
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

        // ================= DATE FILTER FUNCTIONS =================
        function applyDateFilter() {
            const start = document.getElementById('startDate').value;
            const end = document.getElementById('endDate').value;
            if (!start || !end) {
                Swal.fire('Warning', 'Please select both start and end dates', 'warning');
                return;
            }
            window.location.href = `currentinventory.php?start_date=${start}&end_date=${end}`;
        }

        function resetToCurrentMonth() {
            const today = new Date();
            const firstDay = new Date(today.getFullYear(), today.getMonth(), 1);
            const firstDayStr = firstDay.toISOString().split('T')[0];
            const todayStr = today.toISOString().split('T')[0];
            window.location.href = `currentinventory.php?start_date=${firstDayStr}&end_date=${todayStr}`;
        }
        
        // Function to filter by date when clicking on stat cards
        function filterByDate(type) {
            const today = new Date();
            if (type === 'today') {
                const todayStr = today.toISOString().split('T')[0];
                window.location.href = `currentinventory.php?start_date=${todayStr}&end_date=${todayStr}`;
            } else if (type === 'month') {
                const firstDay = new Date(today.getFullYear(), today.getMonth(), 1);
                const firstDayStr = firstDay.toISOString().split('T')[0];
                const todayStr = today.toISOString().split('T')[0];
                window.location.href = `currentinventory.php?start_date=${firstDayStr}&end_date=${todayStr}`;
            }
        }

        // ================= CHARTS INITIALIZATION =================
        function initCharts() {
            // Daily sales
            const dailyLabels = <?php echo json_encode($daily_labels); ?>;
            const dailyData = <?php echo json_encode($daily_totals); ?>;
            if (dailyLabels.length > 0) {
                new Chart(document.getElementById('dailyChart'), {
                    type: 'bar',
                    data: {
                        labels: dailyLabels,
                        datasets: [{
                            label: 'Sales (₱)',
                            data: dailyData,
                            backgroundColor: 'rgba(4, 120, 87, 0.6)',
                            borderColor: '#047857',
                            borderWidth: 1,
                            borderRadius: 4
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: true,
                        plugins: {
                            tooltip: { callbacks: { label: ctx => '₱' + ctx.raw.toFixed(2) } },
                            legend: { display: false }
                        },
                        scales: {
                            y: { beginAtZero: true, ticks: { callback: v => '₱' + v } }
                        }
                    }
                });
            }

            // Top Products (horizontal bar)
            const topProductNames = <?php echo json_encode($top_product_names); ?>;
            const topProductSales = <?php echo json_encode($top_product_sales); ?>;
            if (topProductNames.length > 0) {
                new Chart(document.getElementById('topProductsChart'), {
                    type: 'bar',
                    data: {
                        labels: topProductNames,
                        datasets: [{
                            label: 'Sales (₱)',
                            data: topProductSales,
                            backgroundColor: 'rgba(245, 158, 11, 0.6)',
                            borderColor: '#f59e0b',
                            borderWidth: 1,
                            borderRadius: 4
                        }]
                    },
                    options: {
                        indexAxis: 'y',
                        responsive: true,
                        maintainAspectRatio: true,
                        plugins: {
                            tooltip: { callbacks: { label: ctx => '₱' + ctx.raw.toFixed(2) } },
                            legend: { display: false }
                        },
                        scales: {
                            x: { ticks: { callback: v => '₱' + v } }
                        }
                    }
                });
            }

            // Sales by Category
            const categoryLabels = <?php echo json_encode($category_labels); ?>;
            const categoryData = <?php echo json_encode($category_totals); ?>;
            if (categoryLabels.length > 0) {
                new Chart(document.getElementById('categoryChart'), {
                    type: 'bar',
                    data: {
                        labels: categoryLabels,
                        datasets: [{
                            label: 'Sales (₱)',
                            data: categoryData,
                            backgroundColor: 'rgba(123, 31, 162, 0.6)',
                            borderColor: '#7b1fa2',
                            borderWidth: 1,
                            borderRadius: 4
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: true,
                        plugins: {
                            tooltip: { callbacks: { label: ctx => '₱' + ctx.raw.toFixed(2) } },
                            legend: { display: false }
                        },
                        scales: {
                            y: { beginAtZero: true, ticks: { callback: v => '₱' + v } }
                        }
                    }
                });
            }

            // Day of week (bar chart)
            const dowData = [<?php echo implode(',', $dow_totals); ?>];
            new Chart(document.getElementById('dowChart'), {
                type: 'bar',
                data: {
                    labels: ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'],
                    datasets: [{
                        label: 'Sales (₱)',
                        data: dowData,
                        backgroundColor: 'rgba(68, 211, 78, 0.6)',
                        borderColor: '#44D34E',
                        borderWidth: 1,
                        borderRadius: 4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    plugins: {
                        tooltip: { callbacks: { label: ctx => '₱' + ctx.raw.toFixed(2) } },
                        legend: { display: false }
                    },
                    scales: {
                        y: { beginAtZero: true, ticks: { callback: v => '₱' + v } }
                    }
                }
            });
        }

        // ================= LOGOUT FUNCTION =================
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

        // ================= INITIALIZATION =================
        document.addEventListener('DOMContentLoaded', function() {
            initializeSidebar();
            initMobileNav();
            
            document.getElementById('mobileToggleBtn')?.addEventListener('click', e => { e.stopPropagation(); toggleSidebar(); });
            document.getElementById('desktopToggleBtn')?.addEventListener('click', e => { e.stopPropagation(); toggleSidebar(); });
            
            document.querySelectorAll('.sidebar .nav-link').forEach(l => {
                l.addEventListener('click', () => { if (window.innerWidth <= 992) closeMobileSidebar(); });
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
            
            window.addEventListener('resize', function() {
                handleSidebarResize();
                initMobileNav();
            });
            
            // Fix modal backdrop issue
            const modals = ['profileModal'];
            modals.forEach(modalId => {
                const modal = document.getElementById(modalId);
                if (modal) {
                    modal.addEventListener('hidden.bs.modal', function () {
                        const backdrop = document.querySelector('.modal-backdrop');
                        if (backdrop) backdrop.remove();
                        document.body.classList.remove('modal-open');
                        document.body.style.removeProperty('padding-right');
                    });
                }
            });
            
            initCharts();
        });

        // ================= KEYBOARD SHORTCUTS =================
        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey && e.key === 'b' && window.innerWidth > 992) {
                e.preventDefault();
                toggleSidebar();
            } else if (e.key === 'Escape' && window.innerWidth <= 992) {
                closeMobileSidebar();
            } else if (e.key === 'Escape') {
                const profileModal = document.getElementById('profileModal');
                if (profileModal.classList.contains('show')) {
                    bootstrap.Modal.getInstance(profileModal).hide();
                }
            }
        });
            // Filter toggle functionality (like picklist) - ALWAYS CLOSED BY DEFAULT
    const filterToggleBtn = document.getElementById('filterToggleBtn');
    const filterContent = document.getElementById('filterContent');
    
    if (filterToggleBtn && filterContent) {
        // FORCE CLOSED BY DEFAULT - no localStorage memory
        filterContent.classList.add('collapsed');
        filterToggleBtn.setAttribute('aria-expanded', 'false');
        
        filterToggleBtn.addEventListener('click', function() {
            const isExpanded = filterToggleBtn.getAttribute('aria-expanded') === 'true';
            
            if (isExpanded) {
                filterContent.classList.add('collapsed');
                filterToggleBtn.setAttribute('aria-expanded', 'false');
            } else {
                filterContent.classList.remove('collapsed');
                filterToggleBtn.setAttribute('aria-expanded', 'true');
            }
        });
        
        // Also make the header clickable
        const filterHeader = document.querySelector('.filter-header');
        if (filterHeader) {
            filterHeader.style.cursor = 'pointer';
            filterHeader.addEventListener('click', function(e) {
                // Don't trigger if clicking the button directly
                if (e.target.closest('.filter-toggle-btn')) return;
                
                const isExpanded = filterToggleBtn.getAttribute('aria-expanded') === 'true';
                if (isExpanded) {
                    filterContent.classList.add('collapsed');
                    filterToggleBtn.setAttribute('aria-expanded', 'false');
                } else {
                    filterContent.classList.remove('collapsed');
                    filterToggleBtn.setAttribute('aria-expanded', 'true');
                }
            });
        }
    }
    </script>
    <?php require_once __DIR__ . '/../config/task_login_alert.php'; ?>
</body>
</html>