<?php
require_once '../config/database.php';
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
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
    <style>
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
                    <hr class="sidebar-divider">
                </ul>
            </div>
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

                <div class="row g-3 mb-4">
                    <div class="col-md-4">
                        <div class="stat-card total">
                            <div class="stat-value" id="totalSales">₱0.00</div>
                            <div class="stat-label">Total Sales</div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="stat-card sales">
                            <div class="stat-value" id="itemsSold">0</div>
                            <div class="stat-label">Items Sold</div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="stat-card complete">
                            <div class="stat-value" id="avgOrderValue">₱0.00</div>
                            <div class="stat-label">Avg Order Value</div>
                        </div>
                    </div>
                </div>
                
                <div class="row g-3 mb-4">
                    <div class="col-12">
                        <div class="form-card">
                            <div class="d-flex justify-content-between align-items-center">
                                <h5 class="mb-0">Filter Reports</h5>
                            </div>
                            <div class="row mt-3">
                                <div class="col-md-6">
                                    <label class="form-label">Period</label>
                                    <select class="form-select" id="periodFilter" onchange="toggleDateFilter()">
                                        <option value="monthly" selected>Monthly</option>
                                        <option value="daily">Daily</option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" id="dateLabel">Month</label>
                                    <input type="month" class="form-control" id="dateFilter" value="2026-02" onchange="loadReports()">
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
    
    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
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

    function logout() {
        window.location.href = '../logout.php';
    }

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

    // Initialize
    document.addEventListener('DOMContentLoaded', function() {
        initializeSidebar();
        
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
        
        window.addEventListener('resize', handleSidebarResize);
        
        // Set default to February 2026 and load data
        document.getElementById('dateFilter').value = '2026-02';
        loadReports();
    });
    </script>
</body>
</html>
<?php $conn->close(); ?>