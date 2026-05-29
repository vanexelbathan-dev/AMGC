<?php
require_once '../config/database.php';
require_once '../config/session_handler.php';

// Protect page - only Sales role can access
requireLogin();
requireRole(['sales']);

// Get current user info
$user_id = $_SESSION['user_id'];
$user_name = isset($_SESSION['first_name']) ? $_SESSION['first_name'] . ' ' . $_SESSION['last_name'] : 'Sales User';
$branch_id = $_SESSION['branch_id'] ?? 0;
$view_all_branches = $_SESSION['view_all_branches'] ?? false;

// Get current month and year
$current_month = date('m');
$current_year = date('Y');
$month_names = [
    1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
    5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
    9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'
];

// Get filter parameters
$selected_month = isset($_GET['month']) ? (int)$_GET['month'] : (int)$current_month;
$selected_year = isset($_GET['year']) ? (int)$_GET['year'] : (int)$current_year;
$selected_agent = isset($_GET['agent_id']) ? (int)$_GET['agent_id'] : $user_id;

// Get all sales agents (for dropdown if admin)
$agents = [];
if ($view_all_branches) {
    $agents_query = "SELECT user_id, CONCAT(first_name, ' ', last_name) as full_name 
                     FROM users WHERE role = 'sales' ORDER BY first_name";
    $agents_result = $conn->query($agents_query);
    if ($agents_result) {
        $agents = $agents_result->fetch_all(MYSQLI_ASSOC);
    }
}

// Get agent's monthly quota
$quota_query = "SELECT monthly_quota FROM user_settings WHERE user_id = ?";
$quota_stmt = $conn->prepare($quota_query);
$quota_stmt->bind_param('i', $selected_agent);
$quota_stmt->execute();
$quota_result = $quota_stmt->get_result();
$monthly_quota = $quota_result->fetch_assoc()['monthly_quota'] ?? 100000; // Default 100k

// Get daily sales for the selected month
$daily_sales_query = "SELECT 
                        DAY(so.created_at) as day,
                        COUNT(DISTINCT so.so_id) as order_count,
                        COALESCE(SUM(so.total_amount), 0) as daily_total
                      FROM sales_orders so
                      WHERE so.user_id = ? 
                        AND MONTH(so.created_at) = ? 
                        AND YEAR(so.created_at) = ?
                        AND so.status != 'cancelled'
                      GROUP BY DAY(so.created_at)
                      ORDER BY day";

$daily_stmt = $conn->prepare($daily_sales_query);
$daily_stmt->bind_param('iii', $selected_agent, $selected_month, $selected_year);
$daily_stmt->execute();
$daily_result = $daily_stmt->get_result();
$daily_sales = [];
$total_monthly_sales = 0;
$total_orders_month = 0;

while ($row = $daily_result->fetch_assoc()) {
    $daily_sales[(int)$row['day']] = [
        'total' => (float)$row['daily_total'],
        'count' => (int)$row['order_count']
    ];
    $total_monthly_sales += (float)$row['daily_total'];
    $total_orders_month += (int)$row['order_count'];
}

// Get last 12 months sales trend
$trend_query = "SELECT 
                  MONTH(created_at) as month,
                  YEAR(created_at) as year,
                  COUNT(DISTINCT so_id) as order_count,
                  COALESCE(SUM(total_amount), 0) as monthly_total
                FROM sales_orders
                WHERE user_id = ? 
                  AND created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
                  AND status != 'cancelled'
                GROUP BY YEAR(created_at), MONTH(created_at)
                ORDER BY year DESC, month DESC";

$trend_stmt = $conn->prepare($trend_query);
$trend_stmt->bind_param('i', $selected_agent);
$trend_stmt->execute();
$trend_result = $trend_stmt->get_result();
$monthly_trend = [];
while ($row = $trend_result->fetch_assoc()) {
    $monthly_trend[] = $row;
}

// Get top products by sales
$top_products_query = "SELECT 
                        p.product_name,
                        COUNT(soi.item_id) as quantity_sold,
                        COALESCE(SUM(soi.subtotal), 0) as total_sales
                      FROM sales_order_items soi
                      JOIN products p ON soi.product_id = p.product_id
                      JOIN sales_orders so ON soi.so_id = so.so_id
                      WHERE so.user_id = ? 
                        AND MONTH(so.created_at) = ? 
                        AND YEAR(so.created_at) = ?
                        AND so.status != 'cancelled'
                      GROUP BY p.product_id
                      ORDER BY total_sales DESC
                      LIMIT 5";

$top_products_stmt = $conn->prepare($top_products_query);
$top_products_stmt->bind_param('iii', $selected_agent, $selected_month, $selected_year);
$top_products_stmt->execute();
$top_products_result = $top_products_stmt->get_result();
$top_products = $top_products_result->fetch_all(MYSQLI_ASSOC);

// Get new customers for the month
$new_customers_query = "SELECT COUNT(*) as new_customers 
                        FROM customers 
                        WHERE user_id = ? 
                          AND MONTH(created_at) = ? 
                          AND YEAR(created_at) = ?";
$new_customers_stmt = $conn->prepare($new_customers_query);
$new_customers_stmt->bind_param('iii', $selected_agent, $selected_month, $selected_year);
$new_customers_stmt->execute();
$new_customers_result = $new_customers_stmt->get_result();
$new_customers = $new_customers_result->fetch_assoc()['new_customers'] ?? 0;

// Get recent orders (last 10)
$recent_orders_query = "SELECT 
                          so.so_number,
                          so.created_at,
                          COALESCE(c.customer_name, 'Walk-in Customer') as customer_name,
                          so.total_amount,
                          so.status,
                          COUNT(soi.item_id) as total_items
                        FROM sales_orders so
                        LEFT JOIN customers c ON so.customer_id = c.customer_id
                        LEFT JOIN sales_order_items soi ON so.so_id = soi.so_id
                        WHERE so.user_id = ?
                        GROUP BY so.so_id
                        ORDER BY so.created_at DESC
                        LIMIT 10";

$recent_orders_stmt = $conn->prepare($recent_orders_query);
$recent_orders_stmt->bind_param('i', $selected_agent);
$recent_orders_stmt->execute();
$recent_orders_result = $recent_orders_stmt->get_result();
$recent_orders = $recent_orders_result->fetch_all(MYSQLI_ASSOC);

// Calculate quota percentage
$quota_percentage = $monthly_quota > 0 ? min(100, round(($total_monthly_sales / $monthly_quota) * 100, 1)) : 0;
$quota_remaining = max(0, $monthly_quota - $total_monthly_sales);

// Get average daily sales
$days_in_month = cal_days_in_month(CAL_GREGORIAN, $selected_month, $selected_year);
$avg_daily_sales = $days_in_month > 0 ? round($total_monthly_sales / $days_in_month, 2) : 0;

// Get best selling day
$best_day = !empty($daily_sales) ? array_keys($daily_sales, max($daily_sales))[0] ?? null : null;
$best_day_sales = $best_day ? $daily_sales[$best_day]['total'] : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sales Dashboard - Sales</title>
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
    <style>
        /* Same styles as customer.php */
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.04);
            display: flex;
            align-items: center;
            transition: transform 0.2s, box-shadow 0.2s;
            height: 100%;
            border: 1px solid #eef2f6;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 20px rgba(0,0,0,0.08);
        }
        
        .stat-icon {
            font-size: 2.5rem;
            margin-right: 20px;
            width: 60px;
            height: 60px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 12px;
        }
        
        .stat-card.customers .stat-icon { background: #e3f2fd; color: #1976d2; }
        .stat-card.complete .stat-icon { background: #e8f5e9; color: #388e3c; }
        .stat-card.sales .stat-icon { background: #f3e5f5; color: #7b1fa2; }
        .stat-card.pending .stat-icon { background: #fff3e0; color: #f57c00; }
        .stat-card.quota .stat-icon { background: #e0f2f1; color: #00796b; }
        
        .stat-value {
            font-size: 1.8rem;
            font-weight: 700;
            color: #2c3e50;
            line-height: 1.2;
        }
        
        .stat-label {
            font-size: 0.9rem;
            color: #7f8c8d;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .progress {
            height: 10px;
            border-radius: 5px;
            margin-top: 10px;
        }
        
        .progress-bar {
            background: linear-gradient(90deg, #4CAF50, #8BC34A);
            border-radius: 5px;
        }
        
        .chart-container {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.04);
            border: 1px solid #eef2f6;
        }
        
        .chart-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: #495057;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #e9ecef;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .chart-title i {
            color: #1976d2;
            margin-right: 8px;
        }
        
        .filter-bar {
            background: white;
            border-radius: 12px;
            padding: 15px 20px;
            margin-bottom: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.04);
            border: 1px solid #eef2f6;
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            align-items: center;
        }
        
        .filter-item {
            flex: 1;
            min-width: 150px;
        }
        
        .table-mini {
            font-size: 0.9rem;
        }
        
        .table-mini th {
            background: #f8f9fa;
            color: #495057;
            font-weight: 600;
            border-bottom-width: 1px;
        }
        
        .badge-quota {
            background: #e8f5e9;
            color: #2e7d32;
            padding: 5px 10px;
            border-radius: 20px;
            font-weight: 500;
            font-size: 0.8rem;
        }
        
        .badge-warning {
            background: #fff3e0;
            color: #e65100;
        }
        
        .auto-update-badge {
            background: #e3f2fd;
            color: #0d47a1;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
        }
        
        .auto-update-badge i {
            animation: spin 2s linear infinite;
        }
        
        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
        
        .refresh-btn {
            border: 1px solid #dee2e6;
            background: white;
            color: #6c757d;
            padding: 8px 15px;
            border-radius: 8px;
            font-size: 0.9rem;
            transition: all 0.2s;
        }
        
        .refresh-btn:hover {
            background: #f8f9fa;
            color: #1976d2;
            border-color: #1976d2;
        }
        
        /* Mobile responsive */
        @media (max-width: 768px) {
            .stat-card { padding: 15px; }
            .stat-icon { font-size: 2rem; margin-right: 15px; width: 50px; height: 50px; }
            .stat-value { font-size: 1.5rem; }
            .stat-label { font-size: 0.8rem; }
            .filter-bar { flex-direction: column; gap: 10px; }
            .filter-item { width: 100%; }
        }

        /* Status badges */
        .badge-status {
            padding: 6px 12px;
            border-radius: 20px;
            font-weight: 500;
            font-size: 0.75rem;
        }
        
        .status-completed { background: #e8f5e9; color: #2e7d32; }
        .status-pending { background: #fff3e0; color: #e65100; }
        .status-processing { background: #e3f2fd; color: #0d47a1; }
        .status-cancelled { background: #ffebee; color: #c62828; }
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
                    <span class="nav-text">Sales</span>
                </h3>
            </div>
            
            <div class="sidebar-menu">
                <ul class="nav flex-column">
                    <li class="nav-item">
                        <a class="nav-link active" href="dashboard.php">
                            <i class="bi bi-speedometer2"></i>
                            <span class="nav-text">Dashboard</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="orderproduct.php">
                            <i class="bi bi-bag"></i>
                            <span class="nav-text">Order Product</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="sales_order.php">
                            <i class="bi bi-list-check"></i>
                            <span class="nav-text">Sales Orders</span>
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
                </ul>
            </div>
            
            <!-- User Profile Section -->
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
            <!-- Header -->
            <div class="navbar-top">
                <button class="mobile-toggle-btn" id="mobileToggleBtn">
                    <i class="bi bi-list"></i>
                </button>
                <div class="page-title">
                    <h2>Sales Performance Dashboard</h2>
                    <p>Track your KPIs and sales performance in real-time</p>
                </div>
                <div class="ms-auto d-flex align-items-center">
                    <span class="auto-update-badge me-3">
                        <i class="bi bi-arrow-repeat me-1"></i> Auto-updates every 30s
                    </span>
                    <button class="refresh-btn" onclick="location.reload()">
                        <i class="bi bi-arrow-clockwise me-1"></i> Refresh Now
                    </button>
                </div>
            </div>

            <!-- Filter Bar -->
            <div class="filter-bar">
                <div class="filter-item">
                    <label class="form-label fw-bold mb-1">Month</label>
                    <select class="form-select" id="monthSelect" onchange="applyFilters()">
                        <?php for ($m = 1; $m <= 12; $m++): ?>
                            <option value="<?php echo $m; ?>" <?php echo $m == $selected_month ? 'selected' : ''; ?>>
                                <?php echo $month_names[$m]; ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="filter-item">
                    <label class="form-label fw-bold mb-1">Year</label>
                    <select class="form-select" id="yearSelect" onchange="applyFilters()">
                        <?php for ($y = $current_year - 2; $y <= $current_year + 1; $y++): ?>
                            <option value="<?php echo $y; ?>" <?php echo $y == $selected_year ? 'selected' : ''; ?>>
                                <?php echo $y; ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>
                <?php if ($view_all_branches && !empty($agents)): ?>
                <div class="filter-item">
                    <label class="form-label fw-bold mb-1">Sales Agent</label>
                    <select class="form-select" id="agentSelect" onchange="applyFilters()">
                        <?php foreach ($agents as $agent): ?>
                            <option value="<?php echo $agent['user_id']; ?>" <?php echo $agent['user_id'] == $selected_agent ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($agent['full_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
            </div>

            <!-- KPI Cards Row 1 -->
            <div class="row g-3 mb-4">
                <!-- Total Monthly Sales -->
                <div class="col-md-3">
                    <div class="stat-card sales">
                        <div class="stat-icon">
                            <i class="bi bi-cash-stack"></i>
                        </div>
                        <div>
                            <div class="stat-value">₱<?php echo number_format($total_monthly_sales, 2); ?></div>
                            <div class="stat-label">Total Monthly Sales</div>
                            <div class="mt-2">
                                <span class="badge-quota">
                                    <i class="bi bi-trophy-fill me-1"></i> 
                                    <?php echo $quota_percentage; ?>% of quota
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Monthly Quota Progress -->
                <div class="col-md-3">
                    <div class="stat-card quota">
                        <div class="stat-icon">
                            <i class="bi bi-flag-fill"></i>
                        </div>
                        <div>
                            <div class="stat-value">₱<?php echo number_format($monthly_quota, 0); ?></div>
                            <div class="stat-label">Monthly Quota</div>
                            <div class="progress">
                                <div class="progress-bar" role="progressbar" style="width: <?php echo $quota_percentage; ?>%"></div>
                            </div>
                            <small class="text-muted">₱<?php echo number_format($quota_remaining, 2); ?> remaining</small>
                        </div>
                    </div>
                </div>

                <!-- Total Orders -->
                <div class="col-md-3">
                    <div class="stat-card complete">
                        <div class="stat-icon">
                            <i class="bi bi-cart-check"></i>
                        </div>
                        <div>
                            <div class="stat-value"><?php echo $total_orders_month; ?></div>
                            <div class="stat-label">Orders This Month</div>
                            <small class="text-muted">
                                <i class="bi bi-graph-up me-1"></i>
                                Avg: ₱<?php echo $total_orders_month > 0 ? number_format($total_monthly_sales / $total_orders_month, 2) : 0; ?>
                            </small>
                        </div>
                    </div>
                </div>

                <!-- New Customers -->
                <div class="col-md-3">
                    <div class="stat-card customers">
                        <div class="stat-icon">
                            <i class="bi bi-person-plus-fill"></i>
                        </div>
                        <div>
                            <div class="stat-value"><?php echo $new_customers; ?></div>
                            <div class="stat-label">New Customers</div>
                            <small class="text-muted">
                                <i class="bi bi-calendar me-1"></i>
                                This month
                            </small>
                        </div>
                    </div>
                </div>
            </div>

            <!-- KPI Cards Row 2 -->
            <div class="row g-3 mb-4">
                <!-- Average Daily Sales -->
                <div class="col-md-4">
                    <div class="stat-card pending">
                        <div class="stat-icon">
                            <i class="bi bi-bar-chart-fill"></i>
                        </div>
                        <div>
                            <div class="stat-value">₱<?php echo number_format($avg_daily_sales, 2); ?></div>
                            <div class="stat-label">Average Daily Sales</div>
                        </div>
                    </div>
                </div>

                <!-- Best Selling Day -->
                <div class="col-md-4">
                    <div class="stat-card complete">
                        <div class="stat-icon">
                            <i class="bi bi-calendar-check-fill"></i>
                        </div>
                        <div>
                            <div class="stat-value">
                                <?php echo $best_day ? $month_names[$selected_month] . ' ' . $best_day : 'N/A'; ?>
                            </div>
                            <div class="stat-label">Best Selling Day</div>
                            <?php if ($best_day): ?>
                                <small class="text-muted">₱<?php echo number_format($best_day_sales, 2); ?> sales</small>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Orders per Customer -->
                <div class="col-md-4">
                    <div class="stat-card sales">
                        <div class="stat-icon">
                            <i class="bi bi-arrow-repeat"></i>
                        </div>
                        <div>
                            <div class="stat-value">
                                <?php echo $new_customers > 0 ? round($total_orders_month / $new_customers, 1) : $total_orders_month; ?>
                            </div>
                            <div class="stat-label">Orders per Customer</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Charts Row 1 -->
            <div class="row">
                <!-- Daily Sales Chart -->
                <div class="col-md-8">
                    <div class="chart-container">
                        <div class="chart-title">
                            <span><i class="bi bi-calendar-range"></i> Daily Sales - <?php echo $month_names[$selected_month]; ?> <?php echo $selected_year; ?></span>
                            <span class="badge bg-light text-dark">₱<?php echo number_format($total_monthly_sales, 2); ?> total</span>
                        </div>
                        <canvas id="dailySalesChart"></canvas>
                    </div>
                </div>

                <!-- Top Products -->
                <div class="col-md-4">
                    <div class="chart-container">
                        <div class="chart-title">
                            <span><i class="bi bi-trophy"></i> Top Products</span>
                            <span class="badge bg-light text-dark">This month</span>
                        </div>
                        <div style="height: 300px; overflow-y: auto;">
                            <table class="table table-mini table-hover">
                                <thead>
                                    <tr>
                                        <th>Product</th>
                                        <th class="text-end">Qty</th>
                                        <th class="text-end">Sales</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($top_products)): ?>
                                        <tr>
                                            <td colspan="3" class="text-center text-muted py-4">No sales data for this month</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($top_products as $product): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($product['product_name']); ?></td>
                                            <td class="text-end"><?php echo $product['quantity_sold']; ?></td>
                                            <td class="text-end">₱<?php echo number_format($product['total_sales'], 2); ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Charts Row 2 -->
            <div class="row mt-4">
                <!-- 12-Month Trend -->
                <div class="col-md-6">
                    <div class="chart-container">
                        <div class="chart-title">
                            <span><i class="bi bi-graph-up"></i> 12-Month Sales Trend</span>
                            <span class="badge bg-light text-dark">Last 12 months</span>
                        </div>
                        <canvas id="trendChart"></canvas>
                    </div>
                </div>

                <!-- Sales by Day of Week -->
                <div class="col-md-6">
                    <div class="chart-container">
                        <div class="chart-title">
                            <span><i class="bi bi-pie-chart"></i> Sales by Day of Week</span>
                            <span class="badge bg-light text-dark">This month</span>
                        </div>
                        <div style="height: 250px;">
                            <canvas id="dayOfWeekChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Recent Orders Table -->
            <div class="row mt-4">
                <div class="col-12">
                    <div class="chart-container">
                        <div class="chart-title">
                            <span><i class="bi bi-clock-history"></i> Recent Orders</span>
                            <a href="sales_order.php" class="btn btn-sm btn-outline-primary">View All Orders</a>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead class="table-light">
                                    <tr>
                                        <th>Order #</th>
                                        <th>Customer</th>
                                        <th>Date & Time</th>
                                        <th>Items</th>
                                        <th>Total Amount</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($recent_orders)): ?>
                                        <tr>
                                            <td colspan="6" class="text-center text-muted py-4">No recent orders found</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($recent_orders as $order): ?>
                                        <tr>
                                            <td>
                                                <span class="badge bg-light text-dark">
                                                    <?php echo htmlspecialchars($order['so_number'] ?? 'N/A'); ?>
                                                </span>
                                            </td>
                                            <td><?php echo htmlspecialchars($order['customer_name']); ?></td>
                                            <td>
                                                <?php 
                                                $date = new DateTime($order['created_at']);
                                                echo $date->format('M d, Y g:i A');
                                                ?>
                                            </td>
                                            <td class="text-center"><?php echo $order['total_items']; ?></td>
                                            <td>₱<?php echo number_format($order['total_amount'], 2); ?></td>
                                            <td>
                                                <?php
                                                $status_class = '';
                                                switch($order['status']) {
                                                    case 'completed':
                                                        $status_class = 'status-completed';
                                                        break;
                                                    case 'pending':
                                                        $status_class = 'status-pending';
                                                        break;
                                                    case 'processing':
                                                        $status_class = 'status-processing';
                                                        break;
                                                    case 'cancelled':
                                                        $status_class = 'status-cancelled';
                                                        break;
                                                    default:
                                                        $status_class = 'bg-secondary text-white';
                                                }
                                                ?>
                                                <span class="badge-status <?php echo $status_class; ?>">
                                                    <?php echo ucfirst($order['status']); ?>
                                                </span>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    
    <script>
        // Chart instances
        let dailyChart, trendChart, dayOfWeekChart;
        
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

        // ================= DASHBOARD FUNCTIONS =================
        function applyFilters() {
            const month = document.getElementById('monthSelect').value;
            const year = document.getElementById('yearSelect').value;
            const agent = document.getElementById('agentSelect')?.value || <?php echo $user_id; ?>;
            
            window.location.href = `dashboard.php?month=${month}&year=${year}&agent_id=${agent}`;
        }

        // Auto-refresh every 30 seconds
        let refreshInterval = setInterval(function() {
            if (!document.hidden) {
                location.reload();
            }
        }, 30000);

        document.addEventListener('visibilitychange', function() {
            if (document.hidden) {
                clearInterval(refreshInterval);
            } else {
                refreshInterval = setInterval(() => location.reload(), 30000);
            }
        });

        // ================= CHART INITIALIZATION =================
        document.addEventListener('DOMContentLoaded', function() {
            initializeSidebar();
            
            // Sidebar event listeners
            document.getElementById('mobileToggleBtn')?.addEventListener('click', e => {
                e.stopPropagation();
                toggleSidebar();
            });
            
            document.getElementById('desktopToggleBtn')?.addEventListener('click', e => {
                e.stopPropagation();
                toggleSidebar();
            });
            
            document.querySelectorAll('.sidebar .nav-link').forEach(l => {
                l.addEventListener('click', () => { if (window.innerWidth <= 992) closeMobileSidebar(); });
            });
            
            window.addEventListener('resize', initializeSidebar);

            // Initialize charts
            initDailySalesChart();
            initTrendChart();
            initDayOfWeekChart();
        });

        function initDailySalesChart() {
            const ctx = document.getElementById('dailySalesChart').getContext('2d');
            
            // Prepare data from PHP
            const days = [];
            const sales = [];
            const orderCounts = [];
            
            <?php for ($day = 1; $day <= $days_in_month; $day++): ?>
                days.push(<?php echo $day; ?>);
                sales.push(<?php echo isset($daily_sales[$day]) ? $daily_sales[$day]['total'] : 0; ?>);
                orderCounts.push(<?php echo isset($daily_sales[$day]) ? $daily_sales[$day]['count'] : 0; ?>);
            <?php endfor; ?>

            dailyChart = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: days,
                    datasets: [
                        {
                            label: 'Sales (₱)',
                            data: sales,
                            backgroundColor: 'rgba(25, 118, 210, 0.7)',
                            borderColor: '#1976d2',
                            borderWidth: 1,
                            yAxisID: 'y',
                            order: 1
                        },
                        {
                            label: 'Number of Orders',
                            data: orderCounts,
                            type: 'line',
                            borderColor: '#388e3c',
                            backgroundColor: 'rgba(56, 142, 108, 0.1)',
                            borderWidth: 2,
                            tension: 0.4,
                            yAxisID: 'y1',
                            order: 0
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: {
                        mode: 'index',
                        intersect: false,
                    },
                    plugins: {
                        legend: {
                            position: 'top',
                            labels: { usePointStyle: true }
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    let label = context.dataset.label || '';
                                    if (label) {
                                        label += ': ';
                                        if (context.dataset.label.includes('Sales')) {
                                            label += '₱' + context.raw.toFixed(2);
                                        } else {
                                            label += context.raw;
                                        }
                                    }
                                    return label;
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            title: { display: true, text: 'Day of Month' }
                        },
                        y: {
                            type: 'linear',
                            display: true,
                            position: 'left',
                            title: { display: true, text: 'Sales (₱)' },
                            ticks: {
                                callback: function(value) {
                                    return '₱' + value.toLocaleString();
                                }
                            }
                        },
                        y1: {
                            type: 'linear',
                            display: true,
                            position: 'right',
                            title: { display: true, text: 'Number of Orders' },
                            grid: { drawOnChartArea: false }
                        }
                    }
                }
            });
        }

        function initTrendChart() {
            const ctx = document.getElementById('trendChart').getContext('2d');
            
            const months = [];
            const trendSales = [];
            const trendOrders = [];
            
            <?php foreach (array_reverse($monthly_trend) as $trend): ?>
                months.push('<?php echo $month_names[(int)$trend['month']] . ' ' . $trend['year']; ?>');
                trendSales.push(<?php echo $trend['monthly_total']; ?>);
                trendOrders.push(<?php echo $trend['order_count']; ?>);
            <?php endforeach; ?>

            trendChart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: months,
                    datasets: [
                        {
                            label: 'Monthly Sales (₱)',
                            data: trendSales,
                            borderColor: '#7b1fa2',
                            backgroundColor: 'rgba(123, 31, 162, 0.1)',
                            borderWidth: 2,
                            tension: 0.4,
                            fill: true
                        },
                        {
                            label: 'Number of Orders',
                            data: trendOrders,
                            borderColor: '#f57c00',
                            backgroundColor: 'rgba(245, 124, 0, 0.1)',
                            borderWidth: 2,
                            tension: 0.4,
                            yAxisID: 'y1'
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'top' },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    let label = context.dataset.label || '';
                                    if (label) {
                                        label += ': ';
                                        if (context.dataset.label.includes('Sales')) {
                                            label += '₱' + context.raw.toFixed(2);
                                        } else {
                                            label += context.raw;
                                        }
                                    }
                                    return label;
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            title: { display: true, text: 'Sales (₱)' },
                            ticks: {
                                callback: function(value) {
                                    return '₱' + value.toLocaleString();
                                }
                            }
                        },
                        y1: {
                            beginAtZero: true,
                            position: 'right',
                            title: { display: true, text: 'Orders' },
                            grid: { drawOnChartArea: false }
                        }
                    }
                }
            });
        }

        function initDayOfWeekChart() {
            const ctx = document.getElementById('dayOfWeekChart').getContext('2d');
            
            // Calculate sales by day of week
            const dayNames = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
            const daySales = [0, 0, 0, 0, 0, 0, 0];
            
            <?php
            $day_sales_data = [0, 0, 0, 0, 0, 0, 0];
            foreach ($daily_sales as $day => $data) {
                $timestamp = mktime(0, 0, 0, $selected_month, $day, $selected_year);
                $day_of_week = date('w', $timestamp);
                $day_sales_data[$day_of_week] += $data['total'];
            }
            ?>
            
            dayOfWeekChart = new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels: dayNames,
                    datasets: [{
                        data: [<?php echo implode(',', $day_sales_data); ?>],
                        backgroundColor: [
                            '#1976d2', '#388e3c', '#7b1fa2', '#f57c00', 
                            '#00796b', '#c2185b', '#455a64'
                        ],
                        borderWidth: 0
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'bottom' },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const label = context.label || '';
                                    const value = context.raw;
                                    const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                    const percentage = ((value / total) * 100).toFixed(1);
                                    return `${label}: ₱${value.toLocaleString()} (${percentage}%)`;
                                }
                            }
                        }
                    }
                }
            });
        }

        function logout() {
            window.location.href = '../logout.php';
        }

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey && e.key === 'b' && window.innerWidth > 992) {
                e.preventDefault();
                toggleSidebar();
            } else if (e.key === 'Escape' && window.innerWidth <= 992) {
                closeMobileSidebar();
            } else if (e.ctrlKey && e.key === 'r') {
                e.preventDefault();
                location.reload();
            }
        });
    </script>
</body>
</html>