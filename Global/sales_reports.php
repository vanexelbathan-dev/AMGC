<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Global - Sales Reports</title>
    <link rel="stylesheet" href="../css/style.css">
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
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
                <h3><i class="bi bi-globe logo-icon"></i> <span class="nav-text">Global</span></h3>
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
                </ul>
            </div>
        </div>

        <!-- Main Content -->
        <div class="main-content" id="mainContent">
            <!-- SALES REPORTS PAGE -->
            <div id="reportsContent" class="page-content active">
                <div class="navbar-top">
                    <div class="page-title">
                        <h2><i class="bi bi-graph-up me-2"></i>Sales Reports</h2>
                        <p>Monitor sales trends by location, period, and season</p>
                    </div>
                    
                    <div class="user-info-top">
                        <div class="search-box">
                            <i class="bi bi-search"></i>
                            <input type="text" class="form-control" placeholder="Search by location..." id="searchLocation">
                        </div>
                        
                        <div class="user-profile-top">
                            <div class="user-avatar-top">AD</div>
                            <div class="user-details-top">
                                <span class="user-name-top">Admin User</span>
                                <span class="user-role-top">Global Admin</span>
                            </div>
                        </div>
                        
                        <button class="logout-btn-top" onclick="logout()">
                            <i class="bi bi-box-arrow-right"></i> Logout
                        </button>
                    </div>
                </div>

                <div class="row g-3 mb-4">
                    <div class="col-12">
                        <div class="form-card">
                            <div class="d-flex justify-content-between align-items-center">
                                <h5 class="mb-0">Report Filters</h5>
                            </div>
                            <div class="row mt-3">
                                <div class="col-md-4">
                                    <label class="form-label">Period</label>
                                    <select class="form-select" id="periodFilter" onchange="loadReports()">
                                        <option value="daily">Daily</option>
                                        <option value="weekly">Weekly</option>
                                        <option value="monthly" selected>Monthly</option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Date Range</label>
                                    <input type="month" class="form-control" id="dateFilter" onchange="loadReports()">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Season</label>
                                    <select class="form-select" id="seasonFilter" onchange="loadReports()">
                                        <option value="">All Seasons</option>
                                        <option value="spring">Spring</option>
                                        <option value="summer">Summer</option>
                                        <option value="fall">Fall</option>
                                        <option value="winter">Winter</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row g-3 mb-4">
                    <div class="col-md-4">
                        <div class="stat-card total">
                            <div class="stat-value" id="totalSales">$0</div>
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
                            <div class="stat-value" id="avgOrderValue">$0</div>
                            <div class="stat-label">Avg Order Value</div>
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
                                            <th>Item Name</th>
                                            <th>Location</th>
                                            <th>Units Sold</th>
                                            <th>Revenue</th>
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
                                            <th>Location</th>
                                            <th>Total Orders</th>
                                            <th>Total Revenue</th>
                                            <th>Performance</th>
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
                                <h5>Daily/Weekly/Monthly Breakdown</h5>
                            </div>
                            <div class="table-responsive">
                                <table class="table custom-table">
                                    <thead>
                                        <tr>
                                            <th>Period</th>
                                            <th>Location</th>
                                            <th>Orders</th>
                                            <th>Items Sold</th>
                                            <th>Revenue</th>
                                            <th>Trend</th>
                                        </tr>
                                    </thead>
                                    <tbody id="periodBreakdownTable">
                                        <tr>
                                            <td colspan="6" class="text-center py-4">Loading data...</td>
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
        // Mobile menu toggle
        document.getElementById('mobileMenuBtn').addEventListener('click', function() {
            const sidebar = document.getElementById('sidebar');
            sidebar.classList.toggle('show');
        });

        // Set current month as default
        document.getElementById('dateFilter').valueAsDate = new Date();

        // Logout function
        function logout() {
            alert('Logging out...');
            window.location.href = '../login.php';
        }

        // Load sales reports
        async function loadReports() {
            try {
                const period = document.getElementById('periodFilter').value;
                const date = document.getElementById('dateFilter').value;
                const season = document.getElementById('seasonFilter').value;

                const response = await fetch('api/get_sales_reports.php?period=' + period + '&date=' + date + '&season=' + season);
                const data = await response.json();
                
                if (data.success) {
                    displaySalesMetrics(data.metrics);
                    displayTopItems(data.topItems || []);
                    displayLocationSales(data.locationSales || []);
                    displayPeriodBreakdown(data.periodBreakdown || []);
                } else {
                    console.log('No data found');
                }
            } catch (error) {
                console.error('Error loading reports:', error);
            }
        }

        function displaySalesMetrics(metrics) {
            document.getElementById('totalSales').textContent = '$' + (metrics.totalSales || 0).toLocaleString();
            document.getElementById('itemsSold').textContent = (metrics.itemsSold || 0).toLocaleString();
            document.getElementById('avgOrderValue').textContent = '$' + (metrics.avgOrderValue || 0).toLocaleString();
        }

        function displayTopItems(items) {
            const tbody = document.getElementById('topItemsTable');
            
            if (items.length === 0) {
                tbody.innerHTML = '<tr><td colspan="4" class="text-center py-4">No data available</td></tr>';
                return;
            }

            tbody.innerHTML = items.map(item => `
                <tr>
                    <td><strong>${item.item_name}</strong></td>
                    <td>${item.location}</td>
                    <td>${item.units_sold}</td>
                    <td>$${item.revenue.toLocaleString()}</td>
                </tr>
            `).join('');
        }

        function displayLocationSales(locations) {
            const tbody = document.getElementById('locationSalesTable');
            
            if (locations.length === 0) {
                tbody.innerHTML = '<tr><td colspan="4" class="text-center py-4">No data available</td></tr>';
                return;
            }

            tbody.innerHTML = locations.map(loc => `
                <tr>
                    <td><strong>${loc.location}</strong></td>
                    <td>${loc.totalOrders}</td>
                    <td>$${loc.totalRevenue.toLocaleString()}</td>
                    <td>
                        <span class="badge ${loc.trend > 0 ? 'bg-success' : 'bg-danger'}">
                            <i class="bi ${loc.trend > 0 ? 'bi-arrow-up' : 'bi-arrow-down'}"></i> ${Math.abs(loc.trend)}%
                        </span>
                    </td>
                </tr>
            `).join('');
        }

        function displayPeriodBreakdown(periods) {
            const tbody = document.getElementById('periodBreakdownTable');
            
            if (periods.length === 0) {
                tbody.innerHTML = '<tr><td colspan="6" class="text-center py-4">No data available</td></tr>';
                return;
            }

            tbody.innerHTML = periods.map(period => `
                <tr>
                    <td>${period.period}</td>
                    <td>${period.location}</td>
                    <td>${period.orders}</td>
                    <td>${period.itemsSold}</td>
                    <td>$${period.revenue.toLocaleString()}</td>
                    <td>
                        <span class="badge ${period.trend > 0 ? 'bg-success' : 'bg-danger'}">
                            <i class="bi ${period.trend > 0 ? 'bi-arrow-up' : 'bi-arrow-down'}"></i>
                        </span>
                    </td>
                </tr>
            `).join('');
        }

        // Load reports on page load
        loadReports();
    </script>
</body>
</html>