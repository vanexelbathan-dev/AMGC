<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sales Orders</title>
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
                        <a class="nav-link" href="current_inventory.php">
                            <i class="bi bi-bar-chart-line"></i>
                            <span class="nav-text">Current Inventory</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="sales_order.php">
                            <i class="bi bi-bag"></i>
                            <span class="nav-text">Sales Orders</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="pick_list_items.php">
                            <i class="bi bi-list-check"></i>
                            <span class="nav-text">Pick List Items</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="bad_orders.php">
                            <i class="bi bi-x-circle"></i>
                            <span class="nav-text">Bad Orders</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="purchase_order.php">
                            <i class="bi bi-box"></i>
                            <span class="nav-text">Purchase Orders</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="trip_tickets.php">
                            <i class="bi bi-ticket-perforated"></i>
                            <span class="nav-text">Trip Tickets</span>
                        </a>
                    </li>
                    <hr class="sidebar-divider">
                </ul>
            </div>
        </div>

        <!-- Main Content -->
        <div class="main-content" id="mainContent">
            <!-- SALES ORDER CONTENT -->
            <div id="dashboardContent" class="page-content active">
                <div class="navbar-top">
                    <!-- MOBILE MENU BUTTON -->
                    <button class="mobile-menu-btn" id="mobileMenuBtn">
                        <i class="bi bi-list"></i>
                    </button>
                    
                    <div class="page-title">
                        <h2></i>Sales Orders</h2>
                        <p id="dashboardSubtitle">Manage and track all sales orders</p>
                    </div>
                </div>

                <!-- Demo Mode Info -->
                <div class="demo-info-card mb-4">
                    <div class="demo-info-icon">
                        <i class="bi bi-info-circle"></i>
                    </div>
                    <div class="demo-info-content">
                        <h5>Sales Orders Management</h5>
                        <p class="mb-0">This page demonstrates sales order management features. All data shown is sample data for demonstration purposes.</p>
                    </div>
                </div>

                <!-- Quick Stats -->
                <div class="row g-3 mb-4">
                    <div class="col-md-3">
                        <div class="stat-card total">
                            <i class="bi bi-cart-check stat-icon"></i>
                            <div class="stat-value">245</div>
                            <div class="stat-label">Total Orders</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card pending">
                            <i class="bi bi-clock-history stat-icon"></i>
                            <div class="stat-value">18</div>
                            <div class="stat-label">Pending</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card delivery">
                            <i class="bi bi-truck stat-icon"></i>
                            <div class="stat-value">12</div>
                            <div class="stat-label">For Delivery</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card complete">
                            <i class="bi bi-check-circle stat-icon"></i>
                            <div class="stat-value">215</div>
                            <div class="stat-label">Completed</div>
                        </div>
                    </div>
                </div>

                <!-- Search and Filter -->
                <div class="row g-3 mb-4">
                    <div class="col-12">
                        <div class="form-card">
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <div class="search-box">
                                        <i class="bi bi-search"></i>
                                        <input type="text" class="form-control" placeholder="Search by order number...">
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <select class="form-select">
                                        <option>All Status</option>
                                        <option>Pending</option>
                                        <option>Processing</option>
                                        <option>For Delivery</option>
                                        <option>Completed</option>
                                        <option>Cancelled</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <input type="date" class="form-control" placeholder="Date range">
                                </div>
                                <div class="col-md-2">
                                    <button class="btn btn-primary w-100">Filter</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Action Buttons -->
                <div class="row g-3 mb-4">
                    <div class="col-12">
                        <div class="d-flex justify-content-end gap-2">
                            <button class="btn btn-primary" id="newOrderBtn">
                                <i class="bi bi-plus-circle me-1"></i> New Sales Order
                            </button>
                            <button class="btn btn-outline-primary">
                                <i class="bi bi-printer me-1"></i> Print Report
                            </button>
                            <button class="btn btn-outline-primary">
                                <i class="bi bi-download me-1"></i> Export
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Sales Orders Table -->
                <div class="data-table">
                    <div class="table-header">
                        <h5>Recent Sales Orders</h5>
                    </div>
                    <div class="table-responsive">
                        <table class="table custom-table">
                            <thead>
                                <tr>
                                    <th>Order No.</th>
                                    <th>Date</th>
                                    <th>Customer</th>
                                    <th>Items</th>
                                    <th>Total Amount</th>
                                    <th>Payment Status</th>
                                    <th>Order Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="salesOrdersTable">
                                <!-- Data will be populated by JavaScript -->
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Pagination -->
                <div class="d-flex justify-content-between align-items-center mt-4">
                    <div class="text-muted">
                        Showing 1 to 5 of 245 orders
                    </div>
                    <nav aria-label="Page navigation">
                        <ul class="pagination mb-0">
                            <li class="page-item disabled">
                                <a class="page-link" href="#" tabindex="-1">Previous</a>
                            </li>
                            <li class="page-item active"><a class="page-link" href="#">1</a></li>
                            <li class="page-item"><a class="page-link" href="#">2</a></li>
                            <li class="page-item"><a class="page-link" href="#">3</a></li>
                            <li class="page-item">
                                <a class="page-link" href="#">Next</a>
                            </li>
                        </ul>
                    </nav>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Global variables
        let currentUser = 'admin'; // Set to admin for demo
        let currentUserType = 'admin';
        let currentUserBranch = null;
        let allBranches = [
            { id: 'BR-001', name: 'Main Branch', location: 'Makati City' },
            { id: 'BR-002', name: 'North Branch', location: 'Quezon City' },
            { id: 'BR-003', name: 'South Branch', location: 'Muntinlupa City' },
            { id: 'BR-004', name: 'East Branch', location: 'Pasig City' },
            { id: 'BR-005', name: 'West Branch', location: 'Manila City' }
        ];

        // User database (for demo purposes)
        const users = {
            'admin': {
                password: 'admin123',
                fullName: 'Admin User',
                userType: 'admin',
                branch: null, // Admin has access to all branches
                avatar: 'AD'
            },
            'branch1': {
                password: 'branch123',
                fullName: 'Juan Dela Cruz',
                userType: 'branch_manager',
                branch: 'BR-001', // Main Branch
                avatar: 'JC'
            },
            'branch2': {
                password: 'branch123',
                fullName: 'Maria Santos',
                userType: 'branch_manager',
                branch: 'BR-002', // North Branch
                avatar: 'MS'
            },
            'staff1': {
                password: 'staff123',
                fullName: 'Pedro Reyes',
                userType: 'staff',
                branch: 'BR-001', // Main Branch
                avatar: 'PR'
            }
        };

        // Toggle sidebar collapse/expand on desktop
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const toggleIcon = document.getElementById('toggleIcon');
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
                
                // Update the toggle icon
                if (sidebar.classList.contains('collapsed')) {
                    toggleIcon.classList.remove('bi-chevron-left');
                    toggleIcon.classList.add('bi-chevron-right');
                    // Ensure all nav-text are hidden when collapsed
                    document.querySelectorAll('.nav-text').forEach(text => {
                        text.style.display = 'none';
                    });
                } else {
                    toggleIcon.classList.remove('bi-chevron-right');
                    toggleIcon.classList.add('bi-chevron-left');
                    // Show all nav-text when expanded
                    document.querySelectorAll('.nav-text').forEach(text => {
                        text.style.display = 'inline-block';
                    });
                }
                
                // Store preference in localStorage
                localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
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
            console.log("Sales Orders page loaded!");
            
            // Initialize Bootstrap components
            var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
            var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
                return new bootstrap.Tooltip(tooltipTriggerEl);
            });
            
            // Setup mobile menu toggle
            document.getElementById('mobileMenuBtn').addEventListener('click', function() {
                toggleSidebar();
            });
            
            // Add event listener for desktop toggle button
            document.getElementById('desktopToggleBtn').addEventListener('click', function(e) {
                e.stopPropagation(); // Prevent event bubbling
                toggleSidebar();
            });
            
            // Add click listeners to sidebar links to close on mobile
            document.querySelectorAll('.sidebar .nav-link').forEach(link => {
                link.addEventListener('click', function() {
                    if (window.innerWidth <= 992) {
                        closeMobileSidebar();
                    }
                });
            });

            // New Order Button functionality
            document.getElementById('newOrderBtn').addEventListener('click', function() {
                alert('Create New Sales Order functionality would open here.');
                // In a real application, this would open a modal or redirect to a form
            });

            // Update user interface
            updateUserInterface();
            
            // Load sales orders data
            loadSalesOrdersData();
            
            // Load sidebar preference from localStorage
            const savedCollapsed = localStorage.getItem('sidebarCollapsed');
            if (savedCollapsed === 'true' && window.innerWidth > 992) {
                const sidebar = document.getElementById('sidebar');
                const toggleIcon = document.getElementById('toggleIcon');
                sidebar.classList.add('collapsed');
                toggleIcon.classList.remove('bi-chevron-left');
                toggleIcon.classList.add('bi-chevron-right');
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

        // Logout Function
        function logout() {
            if (confirm('Reset demo to initial state?')) {
                localStorage.removeItem('sidebarCollapsed');
                location.reload();
            }
        }

        // Update User Interface based on user type
        function updateUserInterface() {
            const user = users[currentUser];
            
            // Update user info in header
            document.getElementById('userName').textContent = user.fullName;
            document.getElementById('userRole').textContent = user.userType === 'admin' ? 'Administrator' : 'Branch Manager';
            document.getElementById('userAvatar').textContent = user.avatar;
            
            // Update all user info sections
            document.querySelectorAll('.user-name-top').forEach(el => el.textContent = user.fullName);
            document.querySelectorAll('.user-role-top').forEach(el => el.textContent = user.userType === 'admin' ? 'Administrator' : 'Branch Manager');
            document.querySelectorAll('.user-avatar-top').forEach(el => el.textContent = user.avatar);
        }

        // Load sales orders data (for demo purposes)
        function loadSalesOrdersData() {
            // Demo data for sales orders
            const salesOrders = [
                {
                    orderNo: 'SO-2024-001245',
                    date: '2024-01-15',
                    customer: 'Juan Dela Cruz',
                    items: '3 items',
                    amount: '₱ 25,500.00',
                    paymentStatus: 'Paid',
                    orderStatus: 'Completed'
                },
                {
                    orderNo: 'SO-2024-001244',
                    date: '2024-01-15',
                    customer: 'Maria Santos',
                    items: '2 items',
                    amount: '₱ 18,750.00',
                    paymentStatus: 'Pending',
                    orderStatus: 'Processing'
                },
                {
                    orderNo: 'SO-2024-001243',
                    date: '2024-01-14',
                    customer: 'ABC Corporation',
                    items: '5 items',
                    amount: '₱ 45,200.00',
                    paymentStatus: 'Paid',
                    orderStatus: 'For Delivery'
                },
                {
                    orderNo: 'SO-2024-001242',
                    date: '2024-01-14',
                    customer: 'XYZ Enterprises',
                    items: '1 item',
                    amount: '₱ 8,500.00',
                    paymentStatus: 'Overdue',
                    orderStatus: 'Pending'
                },
                {
                    orderNo: 'SO-2024-001241',
                    date: '2024-01-13',
                    customer: 'John Smith',
                    items: '4 items',
                    amount: '₱ 32,150.00',
                    paymentStatus: 'Paid',
                    orderStatus: 'Completed'
                }
            ];
            
            const table = document.getElementById('salesOrdersTable');
            let html = '';
            
            salesOrders.forEach(order => {
                const paymentClass = order.paymentStatus === 'Paid' ? 'badge-success' : 
                                    order.paymentStatus === 'Pending' ? 'badge-warning' : 'badge-danger';
                const orderClass = order.orderStatus === 'Completed' ? 'badge-success' : 
                                  order.orderStatus === 'Processing' ? 'badge-warning' : 
                                  order.orderStatus === 'For Delivery' ? 'badge-info' : 'badge-warning';
                
                html += `
                    <tr>
                        <td><strong>${order.orderNo}</strong></td>
                        <td>${order.date}</td>
                        <td>${order.customer}</td>
                        <td>${order.items}</td>
                        <td>${order.amount}</td>
                        <td><span class="badge ${paymentClass}">${order.paymentStatus}</span></td>
                        <td><span class="badge ${orderClass}">${order.orderStatus}</span></td>
                        <td>
                            <div class="action-buttons">
                                <button class="btn-icon btn-view"><i class="bi bi-eye"></i></button>
                                <button class="btn-icon btn-edit"><i class="bi bi-pencil"></i></button>
                                <button class="btn-icon btn-delete"><i class="bi bi-trash"></i></button>
                            </div>
                        </td>
                    </tr>
                `;
            });
            
            table.innerHTML = html;
            
            // Add event listeners to action buttons
            document.querySelectorAll('.btn-icon.btn-view').forEach(button => {
                button.addEventListener('click', function() {
                    const orderNo = this.closest('tr').querySelector('td:first-child strong').textContent;
                    alert('View order: ' + orderNo);
                });
            });
            
            document.querySelectorAll('.btn-icon.btn-edit').forEach(button => {
                button.addEventListener('click', function() {
                    const orderNo = this.closest('tr').querySelector('td:first-child strong').textContent;
                    alert('Edit order: ' + orderNo);
                });
            });
            
            document.querySelectorAll('.btn-icon.btn-delete').forEach(button => {
                button.addEventListener('click', function() {
                    const orderNo = this.closest('tr').querySelector('td:first-child strong').textContent;
                    if (confirm('Delete order: ' + orderNo + '?')) {
                        alert('Order ' + orderNo + ' would be deleted.');
                    }
                });
            });
        }

        // Handle window resize
        window.addEventListener('resize', function() {
            const sidebar = document.getElementById('sidebar');
            const toggleIcon = document.getElementById('toggleIcon');
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
                    toggleIcon.classList.remove('bi-chevron-left');
                    toggleIcon.classList.add('bi-chevron-right');
                    // Hide all nav-text when collapsed
                    document.querySelectorAll('.nav-text').forEach(text => {
                        text.style.display = 'none';
                    });
                } else {
                    sidebar.classList.remove('collapsed');
                    toggleIcon.classList.remove('bi-chevron-right');
                    toggleIcon.classList.add('bi-chevron-left');
                    // Show all nav-text when expanded
                    document.querySelectorAll('.nav-text').forEach(text => {
                        text.style.display = 'inline-block';
                    });
                }
            } else {
                // Mobile mode - always show expanded
                sidebar.classList.remove('collapsed');
                toggleIcon.classList.remove('bi-chevron-right');
                toggleIcon.classList.add('bi-chevron-left');
                // Show all nav-text on mobile
                document.querySelectorAll('.nav-text').forEach(text => {
                    text.style.display = 'inline-block';
                });
            }
        });

        // Demo Info Card Styling (add if not in CSS)
        const style = document.createElement('style');
        style.textContent = `
            .demo-info-card {
                background: linear-gradient(135deg, rgba(16, 185, 129, 0.1), rgba(16, 185, 129, 0.05));
                border: 1px solid rgba(16, 185, 129, 0.2);
                border-radius: 12px;
                padding: 1.25rem;
                margin-bottom: 1.5rem;
                display: flex;
                align-items: flex-start;
                gap: 1rem;
            }
            
            .demo-info-icon {
                background: rgba(16, 185, 129, 0.2);
                border-radius: 8px;
                width: 40px;
                height: 40px;
                display: flex;
                align-items: center;
                justify-content: center;
                flex-shrink: 0;
            }
            
            .demo-info-icon i {
                color: var(--primary-green);
                font-size: 1.25rem;
            }
            
            .demo-info-content h5 {
                color: var(--primary-green);
                margin-bottom: 0.25rem;
                font-size: 1rem;
            }
            
            .demo-info-content p {
                color: #6b7280;
                font-size: 0.875rem;
                margin-bottom: 0;
            }
        `;
        document.head.appendChild(style);
    </script>
</body>
</html>