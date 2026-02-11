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
                    <span class="nav-text">Branch Admin</span>
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
             <!-- User Profile Section at the bottom of sidebar -->
     <div class="sidebar-footer">
        <div class="user-profile-sidebar">
            <div class="user-avatar-sidebar">AD</div>
            <div class="user-details-sidebar">
                <span class="user-name-sidebar">Quality Control</span>
                <span class="user-role-sidebar">QC Officer</span>
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
            <!-- SALES REPORTS PAGE -->
            <div id="reportsContent" class="page-content active">
                <div class="navbar-top">

                <button class="mobile-menu-btn" id="mobileMenuBtn">
                    <i class="bi bi-list"></i>
                </button>
                    <div class="page-title">
                        <h2>Sales Reports</h2>
                        <p>Monitor sales trends by location, period, and season</p>
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
                            <div class="stat-value" id="avgOrderValue">₱0</div>
                            <div class="stat-label">Avg Order Value</div>
                        </div>
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

    // Mobile menu toggle (legacy - for compatibility)
    document.getElementById('mobileMenuBtn').addEventListener('click', function() {
        toggleSidebar();
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
                // Clear tables if no data
                displayTopItems([]);
                displayLocationSales([]);
                displayPeriodBreakdown([]);
            }
        } catch (error) {
            console.error('Error loading reports:', error);
            // Clear tables on error
            displayTopItems([]);
            displayLocationSales([]);
            displayPeriodBreakdown([]);
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
                <td>$${(item.revenue || 0).toLocaleString()}</td>
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
                <td>$${(loc.totalRevenue || 0).toLocaleString()}</td>
                <td>
                    <span class="badge ${(loc.trend || 0) > 0 ? 'bg-success' : 'bg-danger'}">
                        <i class="bi ${(loc.trend || 0) > 0 ? 'bi-arrow-up' : 'bi-arrow-down'}"></i> ${Math.abs(loc.trend || 0)}%
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
                <td>$${(period.revenue || 0).toLocaleString()}</td>
                <td>
                    <span class="badge ${(period.trend || 0) > 0 ? 'bg-success' : 'bg-danger'}">
                        <i class="bi ${(period.trend || 0) > 0 ? 'bi-arrow-up' : 'bi-arrow-down'}"></i>
                    </span>
                </td>
            </tr>
        `).join('');
    }

    // Initialize when page loads
    document.addEventListener('DOMContentLoaded', function() {
        console.log("Sales Reports page loaded!");
        
        // Initialize sidebar
        initializeSidebar();
        
        // Setup mobile toggle button (if using new button)
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
            const mobileMenuBtn = document.getElementById('mobileMenuBtn');
            const mobileToggleBtn = document.getElementById('mobileToggleBtn');
            const overlay = document.querySelector('.sidebar-overlay');
            const isMobile = window.innerWidth <= 992;
            
            if (isMobile && sidebar.classList.contains('active') && 
                !sidebar.contains(event.target) && 
                (!mobileMenuBtn || !mobileMenuBtn.contains(event.target)) &&
                (!mobileToggleBtn || !mobileToggleBtn.contains(event.target)) &&
                (!overlay || !overlay.contains(event.target))) {
                closeMobileSidebar();
            }
        });

        // Add resize event listener
        window.addEventListener('resize', handleSidebarResize);

        // Load reports on page load
        loadReports();
        
        // Add event listeners to filters
        document.getElementById('periodFilter').addEventListener('change', loadReports);
        document.getElementById('dateFilter').addEventListener('change', loadReports);
        document.getElementById('seasonFilter').addEventListener('change', loadReports);
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
        // Ctrl + R for refresh reports
        else if (e.ctrlKey && e.key === 'r') {
            e.preventDefault();
            loadReports();
        }
    });
</script>
</body>
</html>