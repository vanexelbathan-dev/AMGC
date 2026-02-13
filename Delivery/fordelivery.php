<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>For Delivery - Delivery Management</title>
    <link rel="icon" type="image/png" href="../Pictures/favicon-96x96.png" sizes="96x96" />
    <link rel="icon" type="image/svg+xml" href="../Pictures/favicon.svg" />
    <link rel="shortcut icon" href="../Pictures/favicon.ico" />
    <link rel="apple-touch-icon" sizes="180x180" href="../Pictures/apple-touch-icon.png" />
    <link rel="manifest" href="../Pictures/site.webmanifest" />
    <link rel="stylesheet" href="../css/delivery.css">
    <link rel="stylesheet" href="../css/fordelivery.css">
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <style>
        /* Branch badge styling */
        .branch-badge {
            background-color: #e7f1ff;
            color: #0d6efd;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
            margin-left: 5px;
        }
        
        /* Alert for missing branch column */
        .alert-info {
            background-color: #d1ecf1;
            border-color: #bee5eb;
            color: #0c5460;
        }
        
        .alert-info code {
            background-color: #f8f9fa;
            padding: 2px 4px;
            border-radius: 4px;
            color: #c7254e;
        }
    </style>
</head>
<body>
    <?php
    // Start session and include database connection
    require_once '../config/database.php';
    require_once '../config/session_handler.php';
    
    // Check if user is logged in
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
        header("Location: ../login.php");
        exit();
    }
    
    // Get current user info and branch context
    $user_id = $_SESSION['user_id'];
    $user_name = isset($_SESSION['first_name']) ? $_SESSION['first_name'] . ' ' . $_SESSION['last_name'] : 'Driver User';
    $user_role = isset($_SESSION['role']) ? $_SESSION['role'] : 'delivery';
    $branch_id = $_SESSION['branch_id'] ?? 0;
    $view_all_branches = $_SESSION['view_all_branches'] ?? false;
    
    // Check if branch_id column exists in sales_orders table
    $so_branch_column_exists = false;
    $check_so_column = $conn->query("SHOW COLUMNS FROM sales_orders LIKE 'branch_id'");
    if ($check_so_column && $check_so_column->num_rows > 0) {
        $so_branch_column_exists = true;
    }
    
    // Check if branch_id column exists in trip_tickets table
    $tt_branch_column_exists = false;
    $check_tt_column = $conn->query("SHOW COLUMNS FROM trip_tickets LIKE 'branch_id'");
    if ($check_tt_column && $check_tt_column->num_rows > 0) {
        $tt_branch_column_exists = true;
    }
    
    // Check if branch_id column exists in deliveries table
    $delivery_branch_column_exists = false;
    $check_delivery_column = $conn->query("SHOW COLUMNS FROM deliveries LIKE 'branch_id'");
    if ($check_delivery_column && $check_delivery_column->num_rows > 0) {
        $delivery_branch_column_exists = true;
    }
    
    // Determine branch filter condition
    $branch_condition = "";
    $tt_branch_condition = "";
    $delivery_branch_condition = "";
    
    if ($so_branch_column_exists && !$view_all_branches) {
        $branch_condition = "AND so.branch_id = $branch_id";
    }
    
    if ($tt_branch_column_exists && !$view_all_branches) {
        $tt_branch_condition = "AND tt.branch_id = $branch_id";
    }
    
    if ($delivery_branch_column_exists && !$view_all_branches) {
        $delivery_branch_condition = "AND d.branch_id = $branch_id";
    }
    
    // Get delivery statistics with branch filtering
    try {
            $total_for_delivery = 0;
            $in_transit = 0;
            $completed_today = 0;
        // Total for delivery (sales orders with status 'ready' or 'processing')
        $query = "SELECT COUNT(*) as total_for_delivery 
                  FROM sales_orders 
                  WHERE order_status IN ('processing', 'ready') 
                  $branch_condition";
        $result = $conn->query($query);
        if ($result) {
            $row = $result->fetch_assoc();
            $total_for_delivery = $row['total_for_delivery'] ?? 0;
        }
        
        // In transit (trip tickets in progress)
        if ($tt_branch_column_exists && !$view_all_branches) {
            $query = "SELECT COUNT(*) as in_transit 
                      FROM trip_tickets 
                      WHERE trip_status = 'in-progress' 
                      AND branch_id = $branch_id";
        } else {
            $query = "SELECT COUNT(*) as in_transit 
                      FROM trip_tickets 
                      WHERE trip_status = 'in-progress'";
        }
        $result = $conn->query($query);
            if ($result) {
            $row = $result->fetch_assoc();
            $in_transit = $row['in_transit'] ?? 0;
        }
        
        // Completed today (deliveries completed today)
        if ($delivery_branch_column_exists && !$view_all_branches) {
            $query = "
                SELECT COUNT(DISTINCT d.delivery_id) as completed_today 
                FROM deliveries d
                JOIN sales_orders so ON d.so_id = so.so_id
                WHERE d.delivery_status = 'delivered' 
                AND DATE(d.delivery_date) = CURDATE()
                AND d.branch_id = $branch_id
            ";
        } else {
            $query = "
                SELECT COUNT(DISTINCT d.delivery_id) as completed_today 
                FROM deliveries d
                JOIN sales_orders so ON d.so_id = so.so_id
                WHERE d.delivery_status = 'delivered' 
                AND DATE(d.delivery_date) = CURDATE()
            ";
        }
        $result = $conn->query($query);
        if ($result) {
            $row = $result->fetch_assoc();
            $completed_today = $row['completed_today'] ?? 0;
        }
        
        // Get delivery orders data with branch filtering
        $query = "
            SELECT 
                so.so_id,
                so.so_number,
                c.customer_name,
                c.contact_person,
                c.phone_number,
                c.address,
                c.city,
                so.order_status,
                so.total_amount,
                so.order_date,
                so.delivery_date,
                so.branch_id,
                GROUP_CONCAT(CONCAT(i.item_name, ' (', soi.quantity_ordered, ')') SEPARATOR '; ') as items
            FROM sales_orders so
            JOIN customers c ON so.customer_id = c.customer_id
            LEFT JOIN sales_order_items soi ON so.so_id = soi.so_id
            LEFT JOIN items i ON soi.item_id = i.item_id
            WHERE so.order_status IN ('processing', 'ready', 'confirmed')
            $branch_condition
            GROUP BY so.so_id
            ORDER BY so.delivery_date ASC, so.order_date ASC
        ";
        
        $result = $conn->query($query);
        $delivery_orders = [];
        if ($result) {
            $delivery_orders = $result->fetch_all(MYSQLI_ASSOC);
        }
        
    } catch (Exception $e) {
        error_log("Database error in fordelivery.php: " . $e->getMessage());
        $delivery_orders = [];
    }
    ?>

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
                    <span class="nav-text">Delivery</span>
                </h3>
            </div>
            
            <div class="sidebar-menu">
                <ul class="nav flex-column">
                    <li class="nav-item">
                        <a class="nav-link active" href="fordelivery.php">
                            <i class="bi bi-truck"></i>
                            <span class="nav-text">For Delivery</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="trip_tickets.php">
                            <i class="bi bi-ticket"></i>
                            <span class="nav-text">Trip Tickets</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="rejecteddelivery.php">
                            <i class="bi bi-exclamation-circle"></i>
                            <span class="nav-text">Rejected Delivery Advice</span>
                        </a>
                    </li>
                </ul>
            </div>
            <!-- User Profile Section at the bottom of sidebar -->
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
            <!-- Header Section with User Info and Logout -->
            <div class="navbar-top">
                <button class="mobile-toggle-btn" id="mobileToggleBtn">
                    <i class="bi bi-list"></i>
                </button>
                <div class="page-title">
                    <h2>For Delivery</h2>
                    <p>Manage and track deliveries in progress</p>
                </div>
            </div>

            <!-- Branch Info Alert (if no branch_id column in sales_orders) -->
            <?php if (!$so_branch_column_exists): ?>
                <div class="alert alert-info alert-dismissible fade show" role="alert">
                    <i class="bi bi-info-circle"></i> 
                    <strong>Branch filtering for sales orders not yet set up.</strong> Please run this SQL in phpMyAdmin to enable branch-specific order data:
                    <br><br>
                    <code>ALTER TABLE sales_orders ADD COLUMN branch_id INT NULL;</code>
                    <br>
                    <code>ALTER TABLE sales_orders ADD FOREIGN KEY (branch_id) REFERENCES branches(branch_id);</code>
                    <br><br>
                    <button type="button" class="btn btn-sm btn-primary" onclick="copySQL('sales_orders')">
                        <i class="bi bi-files"></i> Copy SQL
                    </button>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if (!$tt_branch_column_exists): ?>
                <div class="alert alert-info alert-dismissible fade show" role="alert">
                    <i class="bi bi-info-circle"></i> 
                    <strong>Branch filtering for trip tickets not yet set up.</strong> Please run this SQL in phpMyAdmin to enable branch-specific trip ticket data:
                    <br><br>
                    <code>ALTER TABLE trip_tickets ADD COLUMN branch_id INT NULL;</code>
                    <br>
                    <code>ALTER TABLE trip_tickets ADD FOREIGN KEY (branch_id) REFERENCES branches(branch_id);</code>
                    <br><br>
                    <button type="button" class="btn btn-sm btn-primary" onclick="copySQL('trip_tickets')">
                        <i class="bi bi-files"></i> Copy SQL
                    </button>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if (!$delivery_branch_column_exists): ?>
                <div class="alert alert-info alert-dismissible fade show" role="alert">
                    <i class="bi bi-info-circle"></i> 
                    <strong>Branch filtering for deliveries not yet set up.</strong> Please run this SQL in phpMyAdmin to enable branch-specific delivery data:
                    <br><br>
                    <code>ALTER TABLE deliveries ADD COLUMN branch_id INT NULL;</code>
                    <br>
                    <code>ALTER TABLE deliveries ADD FOREIGN KEY (branch_id) REFERENCES branches(branch_id);</code>
                    <br><br>
                    <button type="button" class="btn btn-sm btn-primary" onclick="copySQL('deliveries')">
                        <i class="bi bi-files"></i> Copy SQL
                    </button>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Delivery Stats -->
            <div class="row g-3 mb-4 delivery-stats">
                <!-- Total for Delivery -->
                <div class="col-md-4 mb-3">
                    <div class="stat-card inventory">
                        <div class="stat-icon">
                            <i class="bi bi-truck"></i>
                        </div>
                        <div>
                            <div class="stat-value"><?php echo $total_for_delivery; ?></div>
                            <div class="stat-label">Total for Delivery</div>
                            
                        </div>
                    </div>
                </div>

                <!-- In Transit -->
                <div class="col-md-4 mb-3">
                    <div class="stat-card pending">
                        <div class="stat-icon">
                            <i class="bi bi-hourglass-split"></i>
                        </div>
                        <div>
                            <div class="stat-value"><?php echo $in_transit; ?></div>
                            <div class="stat-label">In Transit</div>
                            
                        </div>
                    </div>
                </div>

                <!-- Completed Today -->
                <div class="col-md-4 mb-3">
                    <div class="stat-card complete">
                        <div class="stat-icon">
                            <i class="bi bi-check-circle"></i>
                        </div>
                        <div>
                            <div class="stat-value"><?php echo $completed_today; ?></div>
                            <div class="stat-label">Completed Today</div>
                        
                        </div>
                    </div>
                </div>
            </div>


            <!-- Search and Filter -->
            <div class="card mb-4">
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="input-group">
                                <span class="input-group-text">
                                    <i class="bi bi-search"></i>
                                </span>
                                <input type="text" class="form-control" id="searchInput" placeholder="Search by order ID, customer name, address...">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <select class="form-select" id="statusFilter">
                                <option value="">All Status</option>
                                <option value="processing">Processing</option>
                                <option value="ready">Ready</option>
                                <option value="confirmed">Confirmed</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <!-- No Orders Message -->
            <?php if (empty($delivery_orders)): ?>
                <div class="alert alert-info text-center py-4">
                    <i class="bi bi-truck" style="font-size: 2rem;"></i>
                    <p class="mt-3 mb-0">
                        No orders ready for delivery at this time.
                        <?php if ($so_branch_column_exists && !$view_all_branches): ?>
                            <br><small>No orders found for your branch.</small>
                        <?php endif; ?>
                    </p>
                </div>
            <?php else: ?>

            <!-- Delivery Orders Table -->
            <div class="card">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Order ID</th>
                                <th>Customer Name</th>
                                <th>Address</th>
                                <th>Contact</th>
                                <th>Items</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($delivery_orders as $order): ?>
                            <tr>
                                <td><span class="badge bg-light text-dark"><?php echo htmlspecialchars($order['so_number']); ?></span></td>
                                <td><?php echo htmlspecialchars($order['customer_name']); ?></td>
                                <td><?php echo htmlspecialchars($order['address'] . ', ' . $order['city']); ?></td>
                                <td><?php echo htmlspecialchars($order['phone_number']); ?></td>
                                <td>
                                    <?php 
                                    if (!empty($order['items'])) {
                                        $items = explode('; ', $order['items']);
                                        foreach ($items as $index => $item):
                                            if ($index == 0):
                                    ?>
                                        <small class="d-block"><?php echo htmlspecialchars($item); ?></small>
                                    <?php else: ?>
                                        <small class="d-block text-muted"><?php echo htmlspecialchars($item); ?></small>
                                    <?php 
                                            endif;
                                        endforeach; 
                                    } else {
                                        echo '<small class="text-muted">No items listed</small>';
                                    }
                                    ?>
                                </td>
                                <td>
                                    <?php
                                    $status_badge = '';
                                    switch ($order['order_status']) {
                                        case 'processing':
                                            $status_badge = 'bg-warning';
                                            break;
                                        case 'ready':
                                            $status_badge = 'bg-info';
                                            break;
                                        case 'confirmed':
                                            $status_badge = 'bg-primary';
                                            break;
                                        default:
                                            $status_badge = 'bg-secondary';
                                    }
                                    ?>
                                    <span class="badge <?php echo $status_badge; ?>">
                                        <?php echo ucfirst($order['order_status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <button class="btn btn-sm btn-info" title="View" onclick="viewDelivery(<?php echo $order['so_id']; ?>)">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                    <?php if ($order['order_status'] == 'processing'): ?>
                                    <button class="btn btn-sm btn-success" title="Mark as Ready" onclick="updateStatus(this, <?php echo $order['so_id']; ?>, 'ready')">
                                        <i class="bi bi-box-seam"></i>
                                    </button>
                                    <?php elseif ($order['order_status'] == 'ready'): ?>
                                    <button class="btn btn-sm btn-primary" title="Mark as En-route" onclick="updateStatus(this, <?php echo $order['so_id']; ?>, 'confirmed')">
                                        <i class="bi bi-truck"></i>
                                    </button>
                                    <?php elseif ($order['order_status'] == 'confirmed'): ?>
                                    <button class="btn btn-sm btn-success" title="Mark as Delivered" onclick="showDeliveryModal(<?php echo $order['so_id']; ?>, '<?php echo htmlspecialchars($order['so_number']); ?>')">
                                        <i class="bi bi-check-lg"></i>
                                    </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Delivery Modal -->
    <div class="modal fade" id="deliveryModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Delivery Completion</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="deliveryForm" enctype="multipart/form-data" action="update_delivery.php" method="POST">
                        <input type="hidden" name="so_id" id="modalSoId">
                        <input type="hidden" name="so_number" id="modalSoNumber">
                        <input type="hidden" name="branch_id" value="<?php echo $branch_id; ?>">
                        
                        <div class="alert alert-info">
                            <strong id="orderIdDisplay"></strong> - Delivery Confirmation Required
                            <?php if ($so_branch_column_exists && !$view_all_branches): ?>
                                <br><small>Branch: <?php echo $branch_id; ?></small>
                            <?php endif; ?>
                        </div>

                        <h6 class="mb-3">Delivery Information</h6>
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="form-label">Delivery Date</label>
                                <input type="datetime-local" class="form-control" name="delivery_date" required value="<?php echo date('Y-m-d\TH:i'); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Signed By (Customer Name)</label>
                                <input type="text" class="form-control" name="signed_by" placeholder="Customer name">
                            </div>
                        </div>

                        <h6 class="mb-3">Photo Documentation</h6>
                        <div class="mb-3">
                            <label class="form-label">Upload Proof of Delivery Photo</label>
                            <input type="file" class="form-control" name="proof_photo" accept="image/*" required>
                            <small class="text-muted">Please upload a photo showing the delivered package at the delivery location</small>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Remarks</label>
                            <textarea class="form-control" name="remarks" rows="3" placeholder="Any notes about the delivery..."></textarea>
                        </div>

                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" name="confirm_delivery" required>
                            <label class="form-check-label">
                                I confirm this delivery has been completed successfully
                            </label>
                        </div>
                        
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary">Confirm Delivery</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Branch context variables
        const branchId = <?php echo $branch_id; ?>;
        const viewAllBranches = <?php echo $view_all_branches ? 'true' : 'false'; ?>;
        const soBranchColumnExists = <?php echo $so_branch_column_exists ? 'true' : 'false'; ?>;
        const ttBranchColumnExists = <?php echo $tt_branch_column_exists ? 'true' : 'false'; ?>;
        const deliveryBranchColumnExists = <?php echo $delivery_branch_column_exists ? 'true' : 'false'; ?>;

        let currentOrderId = null;
        let currentOrderNumber = null;

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

        // Search functionality
        const searchInput = document.getElementById('searchInput');
        if (searchInput) {
            searchInput.addEventListener('keyup', function() {
                const filter = this.value.toLowerCase();
                const rows = document.querySelectorAll('tbody tr');
                
                rows.forEach(row => {
                    const text = row.textContent.toLowerCase();
                    row.style.display = text.includes(filter) ? '' : 'none';
                });
            });
        }

        // Status filter
        const statusFilter = document.getElementById('statusFilter');
        if (statusFilter) {
            statusFilter.addEventListener('change', function() {
                const filter = this.value.toLowerCase();
                const rows = document.querySelectorAll('tbody tr');
                
                rows.forEach(row => {
                    const status = row.cells[5]?.textContent.toLowerCase() || '';
                    row.style.display = (filter === '' || status.includes(filter)) ? '' : 'none';
                });
            });
        }

        // View delivery details
        function viewDelivery(orderId) {
            window.location.href = 'order_details.php?id=' + orderId;
        }

        // Update delivery status
        function updateStatus(button, orderId, newStatus) {
            if (confirm('Are you sure you want to update the status?')) {
                fetch('update_order_status.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'so_id=' + orderId + '&new_status=' + newStatus + '&branch_id=' + branchId
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        location.reload();
                    } else {
                        alert('Error: ' + data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error updating status');
                });
            }
        }

        // Show delivery modal
        function showDeliveryModal(orderId, orderNumber) {
            currentOrderId = orderId;
            currentOrderNumber = orderNumber;
            document.getElementById('orderIdDisplay').textContent = orderNumber;
            document.getElementById('modalSoId').value = orderId;
            document.getElementById('modalSoNumber').value = orderNumber;
            document.getElementById('deliveryForm').reset();
            const modal = new bootstrap.Modal(document.getElementById('deliveryModal'));
            modal.show();
        }

        // Copy SQL for database setup
        function copySQL(table) {
            let sql = '';
            if (table === 'sales_orders') {
                sql = "ALTER TABLE sales_orders ADD COLUMN branch_id INT NULL;\nALTER TABLE sales_orders ADD FOREIGN KEY (branch_id) REFERENCES branches(branch_id);";
            } else if (table === 'trip_tickets') {
                sql = "ALTER TABLE trip_tickets ADD COLUMN branch_id INT NULL;\nALTER TABLE trip_tickets ADD FOREIGN KEY (branch_id) REFERENCES branches(branch_id);";
            } else if (table === 'deliveries') {
                sql = "ALTER TABLE deliveries ADD COLUMN branch_id INT NULL;\nALTER TABLE deliveries ADD FOREIGN KEY (branch_id) REFERENCES branches(branch_id);";
            }
            
            navigator.clipboard.writeText(sql).then(() => {
                alert('SQL copied to clipboard!');
            });
        }

        // Logout function
        function logout() {
            if (confirm('Are you sure you want to logout?')) {
                window.location.href = '../logout.php';
            }
        }

        // Initialize when page loads
        document.addEventListener('DOMContentLoaded', function() {
            console.log("Delivery Management page loaded!");
            console.log("Branch ID:", branchId);
            console.log("View All Branches:", viewAllBranches);
            console.log("SO Branch Column Exists:", soBranchColumnExists);
            console.log("TT Branch Column Exists:", ttBranchColumnExists);
            console.log("Delivery Branch Column Exists:", deliveryBranchColumnExists);
            
            // Initialize sidebar
            initializeSidebar();
            
            // Setup mobile toggle button
            const mobileToggleBtn = document.getElementById('mobileToggleBtn');
            if (mobileToggleBtn) {
                mobileToggleBtn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    toggleSidebar();
                });
            }
            
            const mobileMenuBtn = document.getElementById('mobileMenuBtn');
            if (mobileMenuBtn) {
                mobileMenuBtn.addEventListener('click', function(e) {
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
                const mobileBtn = document.getElementById('mobileToggleBtn') || document.getElementById('mobileMenuBtn');
                const overlay = document.querySelector('.sidebar-overlay');
                const isMobile = window.innerWidth <= 992;
                
                if (isMobile && sidebar && sidebar.classList.contains('active') && 
                    !sidebar.contains(event.target) && 
                    (!mobileBtn || !mobileBtn.contains(event.target)) &&
                    (!overlay || !overlay.contains(event.target))) {
                    closeMobileSidebar();
                }
            });

            // Add resize event listener
            window.addEventListener('resize', handleSidebarResize);
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
            // Ctrl + F to focus search
            else if (e.ctrlKey && e.key === 'f' && !e.target.matches('input, textarea')) {
                e.preventDefault();
                const searchInput = document.getElementById('searchInput');
                if (searchInput) {
                    searchInput.focus();
                }
            }
        });
    </script>
</body>
</html>