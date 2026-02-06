<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sales Orders</title>
    <link rel="stylesheet" href="../css/style.css">
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
                <h3><img src="../Pictures/nobg.png" alt="Logo" class="logo-icon"> <span class="nav-text">Branch Admin</span></h3>
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

                    <li class="nav-item">
                        <a class="nav-link" href="#">
                            <i class="bi bi-info-circle"></i>
                            <span class="nav-text">About</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="#">
                            <i class="bi bi-chat-left-text"></i>
                            <span class="nav-text">Feedback</span>
                        </a>
                    </li>
                </ul>
            </div>
        </div>

        <!-- Main Content -->
        <div class="main-content" id="mainContent">
            <!-- SALES ORDER CONTENT -->
            <div class="page-content active">
                <div class="navbar-top">
                    <div class="page-title">
                        <h2><i class="bi bi-bag me-2"></i>Sales Orders</h2>
                        <p>Manage and track all sales orders</p>
                    </div>
                    
                    <div class="user-info-top">
                        <div class="search-box">
                            <i class="bi bi-search"></i>
                            <input type="text" class="form-control" placeholder="Search sales orders...">
                        </div>
                        
                        <div class="user-profile-top">
                            <div class="user-avatar-top">AD</div>
                            <div class="user-details-top">
                                <span class="user-name-top">Admin User</span>
                                <span class="user-role-top">Administrator</span>
                            </div>
                        </div>
                        
                        <button class="logout-btn-top" onclick="logout()">
                            <i class="bi bi-box-arrow-right"></i> Logout
                        </button>
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
                    <div class="stat-card complete">
                        <i class="bi bi-check-circle stat-icon"></i>
                        <div class="stat-value">215</div>
                        <div class="stat-label">Completed</div>
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
                                <tr>
                                    <td><strong>SO-2024-001245</strong></td>
                                    <td>2024-01-15</td>
                                    <td>Juan Dela Cruz</td>
                                    <td>3 items</td>
                                    <td>₱ 25,500.00</td>
                                    <td><span class="badge badge-success">Paid</span></td>
                                    <td><span class="badge badge-success">Completed</span></td>
                                    <td>
                                        <div class="action-buttons">
                                            <button class="btn-icon btn-view"><i class="bi bi-eye"></i></button>
                                            <button class="btn-icon btn-edit"><i class="bi bi-pencil"></i></button>
                                            <button class="btn-icon btn-delete"><i class="bi bi-trash"></i></button>
                                        </div>
                                    </td>
                                </tr>
                                <tr>
                                    <td><strong>SO-2024-001244</strong></td>
                                    <td>2024-01-15</td>
                                    <td>Maria Santos</td>
                                    <td>2 items</td>
                                    <td>₱ 18,750.00</td>
                                    <td><span class="badge badge-warning">Pending</span></td>
                                    <td><span class="badge badge-warning">Processing</span></td>
                                    <td>
                                        <div class="action-buttons">
                                            <button class="btn-icon btn-view"><i class="bi bi-eye"></i></button>
                                            <button class="btn-icon btn-edit"><i class="bi bi-pencil"></i></button>
                                            <button class="btn-icon btn-delete"><i class="bi bi-trash"></i></button>
                                        </div>
                                    </td>
                                </tr>
                                <tr>
                                    <td><strong>SO-2024-001243</strong></td>
                                    <td>2024-01-14</td>
                                    <td>ABC Corporation</td>
                                    <td>5 items</td>
                                    <td>₱ 45,200.00</td>
                                    <td><span class="badge badge-success">Paid</span></td>
                                    <td><span class="badge badge-info">For Delivery</span></td>
                                    <td>
                                        <div class="action-buttons">
                                            <button class="btn-icon btn-view"><i class="bi bi-eye"></i></button>
                                            <button class="btn-icon btn-edit"><i class="bi bi-pencil"></i></button>
                                            <button class="btn-icon btn-delete"><i class="bi bi-trash"></i></button>
                                        </div>
                                    </td>
                                </tr>
                                <tr>
                                    <td><strong>SO-2024-001242</strong></td>
                                    <td>2024-01-14</td>
                                    <td>XYZ Enterprises</td>
                                    <td>1 item</td>
                                    <td>₱ 8,500.00</td>
                                    <td><span class="badge badge-danger">Overdue</span></td>
                                    <td><span class="badge badge-warning">Pending</span></td>
                                    <td>
                                        <div class="action-buttons">
                                            <button class="btn-icon btn-view"><i class="bi bi-eye"></i></button>
                                            <button class="btn-icon btn-edit"><i class="bi bi-pencil"></i></button>
                                            <button class="btn-icon btn-delete"><i class="bi bi-trash"></i></button>
                                        </div>
                                    </td>
                                </tr>
                                <tr>
                                    <td><strong>SO-2024-001241</strong></td>
                                    <td>2024-01-13</td>
                                    <td>John Smith</td>
                                    <td>4 items</td>
                                    <td>₱ 32,150.00</td>
                                    <td><span class="badge badge-success">Paid</span></td>
                                    <td><span class="badge badge-success">Completed</span></td>
                                    <td>
                                        <div class="action-buttons">
                                            <button class="btn-icon btn-view"><i class="bi bi-eye"></i></button>
                                            <button class="btn-icon btn-edit"><i class="bi bi-pencil"></i></button>
                                            <button class="btn-icon btn-delete"><i class="bi bi-trash"></i></button>
                                        </div>
                                    </td>
                                </tr>
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
                document.getElementById('sidebar').classList.toggle('active');
            });
            
            // Close sidebar when clicking outside on mobile
            document.addEventListener('click', function(event) {
                const sidebar = document.getElementById('sidebar');
                const mobileBtn = document.getElementById('mobileMenuBtn');
                const isMobile = window.innerWidth <= 992;
                
                if (isMobile && sidebar.classList.contains('active') && 
                    !sidebar.contains(event.target) && 
                    !mobileBtn.contains(event.target)) {
                    sidebar.classList.remove('active');
                }
            });

            // New Order Button functionality
            document.getElementById('newOrderBtn').addEventListener('click', function() {
                alert('Create New Sales Order functionality would open here.');
                // In a real application, this would open a modal or redirect to a form
            });

            // Handle window resize
            window.addEventListener('resize', function() {
                // Close sidebar on mobile when resizing to desktop
                if (window.innerWidth > 992) {
                    document.getElementById('sidebar').classList.remove('active');
                }
            });

            // Keyboard shortcuts
            document.addEventListener('keydown', function(e) {
                // Ctrl + N for new order
                if (e.ctrlKey && e.key === 'n') {
                    e.preventDefault();
                    document.getElementById('newOrderBtn').click();
                }
                // Ctrl + F for focus search
                else if (e.ctrlKey && e.key === 'f') {
                    e.preventDefault();
                    document.querySelector('.search-box input').focus();
                }
            });

            // Demo data for sales orders
            loadSalesOrdersData();
        });

        // Logout Function
        function logout() {
            if (confirm('Are you sure you want to logout?')) {
                // In a real application, this would redirect to logout page
                alert('Logout functionality would redirect to login page.');
                // window.location.href = 'logout.php';
            }
        }

        // Load sales orders data (for demo purposes)
        function loadSalesOrdersData() {
            // This function would typically fetch data from an API
            console.log('Sales orders data loaded.');
            
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