<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Current Inventory</title>
    <link rel="icon" type="image/png" href="../Pictures/favicon-96x96.png" sizes="96x96" />
    <link rel="icon" type="image/svg+xml" href="../Pictures/favicon.svg" />
    <link rel="shortcut icon" href="../Pictures/favicon.ico" />
    <link rel="apple-touch-icon" sizes="180x180" href="../Pictures/apple-touch-icon.png" />
    <link rel="manifest" href="../Pictures/site.webmanifest" />
    <link rel="stylesheet" href="../css/current_inventory.css">
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@100;200;300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/inter-ui@3.19.3/inter.css">

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
                        <a class="nav-link active" href="current_inventory.php" data-title="Current Inventory">
                            <i class="bi bi-bar-chart-line"></i>
                            <span class="nav-text">Current Inventory</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="sales_order.php" data-title="Sales Orders">
                            <i class="bi bi-bag"></i>
                            <span class="nav-text">Sales Orders</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="pick_list_items.php" data-title="Pick List Items">
                            <i class="bi bi-list-check"></i>
                            <span class="nav-text">Pick List Items</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="bad_orders.php" data-title="Bad Orders">
                            <i class="bi bi-recycle"></i>
                            <span class="nav-text">Bad Orders</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="purchase_order.php" data-title="Purchase Orders">
                            <i class="bi bi-box"></i>
                            <span class="nav-text">Purchase Orders</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="trip_tickets.php" data-title="Trip Tickets">
                            <i class="bi bi-ticket-perforated"></i>
                            <span class="nav-text">Trip Tickets</span>
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
            <!-- DASHBOARD -->
            <div id="dashboardContent" class="page-content active">
                <div class="navbar-top">
                    <!-- MOBILE MENU BUTTON -->
                    <button class="mobile-menu-btn" id="mobileMenuBtn">
                        <i class="bi bi-list"></i>
                    </button>
                    <div class="page-title">
                        <h2></i>Current Inventory</h2>
                        <p id="dashboardSubtitle">Welcome to Inventory System Demo Mode</p>
                    </div>
                </div>


                <!-- Quick Stats -->
                <div class="row g-3 mb-4">
                    <div class="col-xl-3 col-md-6">
                        <div class="stat-card total">
                            <i class="bi bi-bar-chart stat-icon"></i>
                            <div class="stat-value" id="statMonthlySales">₱ 1.2M</div>
                            <div class="stat-label">Monthly Sales</div>
                            <small class="d-block mt-2"><i class="bi bi-arrow-up-right"></i> 12.5% from last month</small>
                        </div>
                    </div>
                    <div class="col-xl-3 col-md-6">
                        <div class="stat-card sales">
                            <i class="bi bi-cash-coin stat-icon"></i>
                            <div class="stat-value" id="statActiveAccounts">156</div>
                            <div class="stat-label">Active Accounts</div>
                            <small class="d-block mt-2"><i class="bi bi-arrow-up-right"></i> 8 new this month</small>
                        </div>
                    </div>
                    <div class="col-xl-3 col-md-6">
                        <div class="stat-card pending">
                            <i class="bi bi-clock stat-icon"></i>
                            <div class="stat-value" id="statPendingDeliveries">12</div>
                            <div class="stat-label">Pending Deliveries</div>
                            <small class="d-block mt-2">3 require immediate attention</small>
                        </div>
                    </div>
                    <div class="col-xl-3 col-md-6">
                        <div class="stat-card complete">
                            <i class="bi bi-check-circle stat-icon"></i>
                            <div class="stat-value" id="statOrderAccuracy">98%</div>
                            <div class="stat-label">Order Accuracy</div>
                            <small class="d-block mt-2">Excellent performance</small>
                        </div>
                    </div>
                </div>

                <!-- Recent Activity -->
                <div class="row g-3">
                    <div class="col-lg-8">
                        <div class="data-table">
                            <div class="table-header">
                                <h5>Recent Transactions</h5>
                            </div>
                            <div class="table-responsive">
                                <table class="table custom-table">
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th>Type</th>
                                            <th>Reference</th>
                                            <th>Amount</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody id="recentTransactions">
                                        <tr>
                                            <td>2024-01-15</td>
                                            <td><span class="badge badge-success">Sale</span></td>
                                            <td>SALE-001245</td>
                                            <td>₱ 25,000</td>
                                            <td><span class="badge badge-success">Completed</span></td>
                                        </tr>
                                        <tr>
                                            <td>2024-01-15</td>
                                            <td><span class="badge badge-info">Purchase</span></td>
                                            <td>PO-001244</td>
                                            <td>₱ 45,000</td>
                                            <td><span class="badge badge-warning">Pending</span></td>
                                        </tr>
                                        <tr>
                                            <td>2024-01-14</td>
                                            <td><span class="badge badge-primary">Delivery</span></td>
                                            <td>DEL-001243</td>
                                            <td>₱ 18,750</td>
                                            <td><span class="badge badge-info">In Transit</span></td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-4">
                        <div class="chart-container">
                            <h5>Monthly Sales Trend</h5>
                            <canvas id="dashboardChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div> 

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    
  <script>
    // Toggle sidebar collapse/expand on desktop
    function toggleSidebar() {
        const sidebar = document.getElementById('sidebar');
        const isMobile = window.innerWidth <= 992;
        
        if (isMobile) {
            // On mobile, use the existing hamburger functionality
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
                overlay.remove();
            }, 300);
        }
    }

    // Initialize when page loads
    document.addEventListener('DOMContentLoaded', function() {
        console.log("Current Inventory page loaded!");
        
        // Setup mobile menu toggle
        document.getElementById('mobileMenuBtn').addEventListener('click', function() {
            const sidebar = document.getElementById('sidebar');
            const isMobile = window.innerWidth <= 992;
            
            if (isMobile) {
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
                }
            } else {
                // On desktop, toggle sidebar collapse
                toggleSidebar();
            }
        });
        
        // Add event listener for desktop toggle button
        const desktopToggleBtn = document.getElementById('desktopToggleBtn');
        if (desktopToggleBtn) {
            desktopToggleBtn.addEventListener('click', function(e) {
                e.stopPropagation(); // Prevent event bubbling
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
            const mobileBtn = document.getElementById('mobileMenuBtn');
            const overlay = document.querySelector('.sidebar-overlay');
            const isMobile = window.innerWidth <= 992;
            
            if (isMobile && sidebar.classList.contains('active') && 
                !sidebar.contains(event.target) && 
                !mobileBtn.contains(event.target) &&
                !overlay?.contains(event.target)) {
                closeMobileSidebar();
            }
        });

        // Initialize charts
        initializeCharts();
        
        // Update dashboard statistics
        updateDashboardStats();
        
        // Update recent transactions
        updateRecentTransactions();

        // Load sidebar preference from localStorage
        const savedCollapsed = localStorage.getItem('sidebarCollapsed');
        if (savedCollapsed === 'true' && window.innerWidth > 992) {
            const sidebar = document.getElementById('sidebar');
            sidebar.classList.add('collapsed');
            // Hide all nav-text when collapsed
            document.querySelectorAll('.nav-text').forEach(text => {
                text.style.display = 'none';
            });
        } else {
            // Show all nav-text by default when expanded
            document.querySelectorAll('.nav-text').forEach(text => {
                text.style.display = 'inline-block';
            });
        }
    });

    // Handle window resize
    window.addEventListener('resize', function() {
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
                // Hide all nav-text when collapsed
                document.querySelectorAll('.nav-text').forEach(text => {
                    text.style.display = 'none';
                });
            } else {
                sidebar.classList.remove('collapsed');
                // Show all nav-text when expanded
                document.querySelectorAll('.nav-text').forEach(text => {
                    text.style.display = 'inline-block';
                });
            }
        } else {
            // Mobile mode - always show expanded
            sidebar.classList.remove('collapsed');
            // Show all nav-text on mobile
            document.querySelectorAll('.nav-text').forEach(text => {
                text.style.display = 'inline-block';
            });
        }
    });

    // Initialize Charts
    function initializeCharts() {
        // Dashboard Chart
        const dashboardCtx = document.getElementById('dashboardChart');
        if (dashboardCtx) {
            new Chart(dashboardCtx, {
                type: 'line',
                data: {
                    labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun'],
                    datasets: [{
                        label: 'Sales (₱)',
                        data: [850000, 920000, 1010000, 980000, 1120000, 1250000],
                        borderColor: '#10b981',
                        backgroundColor: 'rgba(16, 185, 129, 0.1)',
                        fill: true,
                        tension: 0.4
                    }]
                },
                options: {
                    responsive: true,
                    plugins: {
                        legend: {
                            display: false
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                callback: function(value) {
                                    return '₱ ' + (value / 1000).toFixed(0) + 'K';
                                }
                            }
                        }
                    }
                }
            });
        }
    }

    // Update dashboard statistics
    function updateDashboardStats() {
        // Mock data for demo
        document.getElementById('statMonthlySales').textContent = '₱ 1.2M';
        document.getElementById('statActiveAccounts').textContent = '156';
        document.getElementById('statPendingDeliveries').textContent = '12';
        document.getElementById('statOrderAccuracy').textContent = '98%';
    }

    // Update recent transactions
    function updateRecentTransactions() {
        const transactionsTable = document.getElementById('recentTransactions');
        
        // Mock transactions data
        const transactions = [
            { date: '2024-01-15', type: 'Sale', ref: 'SALE-001245', amount: '₱ 25,000', status: 'Completed' },
            { date: '2024-01-15', type: 'Purchase', ref: 'PO-001244', amount: '₱ 45,000', status: 'Pending' },
            { date: '2024-01-14', type: 'Delivery', ref: 'DEL-001243', amount: '₱ 18,750', status: 'In Transit' }
        ];
        
        // Update table
        let html = '';
        transactions.forEach(transaction => {
            const typeClass = transaction.type === 'Sale' ? 'badge-success' : 
                             transaction.type === 'Purchase' ? 'badge-info' : 'badge-primary';
            const statusClass = transaction.status === 'Completed' ? 'badge-success' :
                               transaction.status === 'Pending' ? 'badge-warning' : 'badge-info';
            
            html += `
                <tr>
                    <td>${transaction.date}</td>
                    <td><span class="badge ${typeClass}">${transaction.type}</span></td>
                    <td>${transaction.ref}</td>
                    <td>${transaction.amount}</td>
                    <td><span class="badge ${statusClass}">${transaction.status}</span></td>
                </tr>
            `;
        });
        
        transactionsTable.innerHTML = html;
    }

    // Logout Function - Now just refreshes the page for demo reset
    function logout() {
        if (confirm('Reset demo to initial state?')) {
            localStorage.removeItem('sidebarCollapsed');
            location.reload();
        }
    }

    // Keyboard shortcuts
    document.addEventListener('keydown', function(e) {
        // Ctrl + R for reset
        if (e.ctrlKey && e.key === 'r') {
            e.preventDefault();
            logout();
        }
        // Ctrl + B to toggle sidebar (desktop only)
        else if (e.ctrlKey && e.key === 'b' && window.innerWidth > 992) {
            e.preventDefault();
            toggleSidebar();
        }
    });
</script>
</body>
</html>