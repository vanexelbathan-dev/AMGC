<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rejected Delivery Advice - Delivery Management</title>
    <link rel="icon" type="image/png" href="../Pictures/favicon-96x96.png" sizes="96x96" />
    <link rel="icon" type="image/svg+xml" href="../Pictures/favicon.svg" />
    <link rel="shortcut icon" href="../Pictures/favicon.ico" />
    <link rel="apple-touch-icon" sizes="180x180" href="../Pictures/apple-touch-icon.png" />
    <link rel="manifest" href="../Pictures/site.webmanifest" />
    <link rel="stylesheet" href="../css/delivery.css">
    <link rel="stylesheet" href="../css/rejecteddelivery.css">
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
</head>
<body>
    <?php
    // Start session and include database connection
    session_start();
    require_once '../config/database.php';
    
    // Check if user is logged in
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
        header("Location: ../login.php");
        exit();
    }
    
    // Get current user info
    $user_id = $_SESSION['user_id'];
    $user_name = isset($_SESSION['first_name']) ? $_SESSION['first_name'] . ' ' . $_SESSION['last_name'] : 'Driver User';
    $user_role = isset($_SESSION['role']) ? $_SESSION['role'] : 'delivery';
    
    // Get driver information if available
    try {
        $query = "
            SELECT * FROM drivers 
            WHERE user_id = ? 
            OR driver_name LIKE ?
            LIMIT 1
        ";
        $stmt = $conn->prepare($query);
        $search_name = '%' . (isset($_SESSION['first_name']) ? $_SESSION['first_name'] : '') . '%';
        $stmt->bind_param('is', $user_id, $search_name);
        $stmt->execute();
        $result = $stmt->get_result();
        $driver_info = $result->fetch_assoc();
        
        // Get recent sales orders for dropdown
        $query = "
            SELECT so.so_id, so.so_number, c.customer_name 
            FROM sales_orders so
            JOIN customers c ON so.customer_id = c.customer_id
            WHERE so.order_status IN ('confirmed', 'ready')
            ORDER BY so.order_date DESC
            LIMIT 20
        ";
        $result = $conn->query($query);
        $recent_orders = $result->fetch_all(MYSQLI_ASSOC);
        
        // Get recent rejected deliveries
        $query = "
            SELECT 
                d.delivery_date,
                so.so_number,
                c.customer_name,
                d.remarks,
                d.delivery_status
            FROM deliveries d
            JOIN sales_orders so ON d.so_id = so.so_id
            JOIN customers c ON d.customer_id = c.customer_id
            WHERE d.delivery_status = 'rejected'
            ORDER BY d.delivery_date DESC
            LIMIT 10
        ";
        $result = $conn->query($query);
        $recent_rejections = $result->fetch_all(MYSQLI_ASSOC);
        
    } catch (Exception $e) {
        die("Database error: " . $e->getMessage());
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
                <a class="nav-link" href="fordelivery.php">
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
                <a class="nav-link active" href="rejecteddelivery.php">
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
                    <span class="user-role-sidebar"><?php echo htmlspecialchars(ucfirst($user_role)); ?></span>
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
                    <h2>Rejected Delivery Advice</h2>
                    <p>Report and document rejected deliveries</p>
                </div>
            </div>

            <!-- Rejected Delivery Form -->
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-pencil-square me-2"></i>Report Rejected Delivery</h5>
                </div>
                <div class="card-body">
                    <form id="rejectedDeliveryForm" action="submit_rejected_delivery.php" method="POST" enctype="multipart/form-data">
                        <!-- Order Information Section -->
                        <h6 class="mb-3"><i class="bi bi-box-seam me-2"></i>Order Information</h6>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Order ID <span class="text-danger">*</span></label>
                                <select class="form-select" id="rejOrderId" name="order_id" required>
                                    <option value="">-- Select Order --</option>
                                    <?php foreach ($recent_orders as $order): ?>
                                    <option value="<?php echo $order['so_id']; ?>">
                                        <?php echo htmlspecialchars($order['so_number'] . ' - ' . $order['customer_name']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Delivery Date <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" id="rejDeliveryDate" name="delivery_date" required 
       value="<?php echo date('Y-m-d'); ?>" min="<?php echo date('Y-m-d'); ?>">
                            </div>
                        </div>

                        <hr>

                        <!-- Customer Information Section -->
                        <h6 class="mb-3"><i class="bi bi-person-check me-2"></i>Customer Information</h6>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Customer Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="rejCustomerName" name="customer_name" required placeholder="Full name">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Contact Number <span class="text-danger">*</span></label>
                                <input type="tel" class="form-control" id="rejContactNumber" name="contact_number" required placeholder="(555) 000-0000">
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Delivery Address <span class="text-danger">*</span></label>
                            <textarea class="form-control" id="rejDeliveryAddress" name="delivery_address" required rows="2" placeholder="Full delivery address"></textarea>
                        </div>

                        <hr>

                        <!-- Rejection Reason Section -->
                        <h6 class="mb-3"><i class="bi bi-exclamation-diamond me-2"></i>Rejection Reason</h6>
                        <div class="mb-3">
                            <label class="form-label">Reason for Rejection <span class="text-danger">*</span></label>
                            <select class="form-select" id="rejReason" name="rejection_reason" required onchange="handleReasonChange()">
                                <option value="">-- Select Reason --</option>
                                <option value="Customer Not Available">Customer Not Available</option>
                                <option value="Address Not Found">Address Not Found</option>
                                <option value="Customer Refused">Customer Refused</option>
                                <option value="Wrong Address">Wrong Address</option>
                                <option value="Damaged Package">Damaged Package</option>
                                <option value="Security Concern">Security Concern</option>
                                <option value="Other">Other (Please Specify)</option>
                            </select>
                        </div>

                        <div class="mb-3" id="otherReasonDiv" style="display: none;">
                            <label class="form-label">Please Specify <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="otherReason" name="other_reason" placeholder="Specify the rejection reason">
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Detailed Description <span class="text-danger">*</span></label>
                            <textarea class="form-control" id="rejDescription" name="description" required rows="3" placeholder="Provide detailed information about the rejection..."></textarea>
                        </div>

                        <hr>

                        <!-- Resolution Section -->
                        <h6 class="mb-3"><i class="bi bi-arrow-clockwise me-2"></i>Resolution Actions</h6>
                        <div class="mb-3">
                            <label class="form-label">Proposed Action <span class="text-danger">*</span></label>
                            <select class="form-select" id="rejAction" name="proposed_action" required>
                                <option value="">-- Select Action --</option>
                                <option value="Return to Warehouse">Return to Warehouse</option>
                                <option value="Retry Delivery">Retry Delivery</option>
                                <option value="Contact Customer">Contact Customer for Arrangement</option>
                                <option value="Hold for Pickup">Hold for Customer Pickup</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Scheduled Retry Date (if applicable)</label>
                            <input type="date" class="form-control" id="rejRetryDate" name="retry_date">
                        </div>

                        <hr>

                        <!-- Photo Documentation Section -->
                        <h6 class="mb-3"><i class="bi bi-camera me-2"></i>Photo Documentation</h6>
                        <div class="mb-3">
                            <label class="form-label">Upload Photo of Rejected Package/Location</label>
                            <input type="file" class="form-control" id="rejPhoto" name="rejection_photo" accept="image/*">
                            <small class="text-muted">Please upload a photo showing the package and/or the delivery location</small>
                        </div>

                        <hr>

                        <!-- Driver Information Section -->
                        <h6 class="mb-3"><i class="bi bi-person-badge me-2"></i>Driver Information</h6>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Driver Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="rejDriverName" name="driver_name" required 
                                       value="<?php echo $driver_info ? htmlspecialchars($driver_info['driver_name']) : htmlspecialchars($user_name); ?>">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Driver ID <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="rejDriverId" name="driver_id" required 
                                       value="<?php echo $driver_info ? htmlspecialchars($driver_info['driver_id']) : htmlspecialchars($user_id); ?>">
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Driver Contact Number <span class="text-danger">*</span></label>
                            <input type="tel" class="form-control" id="rejDriverContact" name="driver_contact" required 
                                   value="<?php echo $driver_info ? htmlspecialchars($driver_info['contact_number']) : ''; ?>" 
                                   placeholder="(555) 000-0000">
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Additional Notes</label>
                            <textarea class="form-control" id="rejAdditionalNotes" name="additional_notes" rows="2" placeholder="Any additional notes or observations..."></textarea>
                        </div>

                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" id="rejConfirm" name="confirm" required>
                            <label class="form-check-label" for="rejConfirm">
                                I confirm that all information provided is accurate and true to the best of my knowledge
                            </label>
                        </div>

                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-check-lg me-2"></i>Submit Rejection Report
                            </button>
                            <button type="reset" class="btn btn-secondary" onclick="resetForm()">
                                <i class="bi bi-arrow-counterclockwise me-2"></i>Clear Form
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Recent Rejected Deliveries -->
            <div class="card mt-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-clock-history me-2"></i>Recent Rejected Deliveries</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($recent_rejections)): ?>
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle me-2"></i>No rejected deliveries found.
                        </div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Order ID</th>
                                    <th>Customer</th>
                                    <th>Reason</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recent_rejections as $rejection): ?>
                                <tr>
                                    <td><?php echo !empty($rejection['delivery_date']) ? date('M d, Y', strtotime($rejection['delivery_date'])) : 'N/A'; ?></td>
                                    <td><?php echo htmlspecialchars($rejection['so_number']); ?></td>
                                    <td><?php echo htmlspecialchars($rejection['customer_name']); ?></td>
                                    <td>
                                        <small><?php echo htmlspecialchars(substr($rejection['remarks'], 0, 50)); ?>...</small>
                                    </td>
                                    <td><span class="badge bg-danger">Rejected</span></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- JavaScript -->
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

        // Handle reason change
        function handleReasonChange() {
            const reason = document.getElementById('rejReason');
            if (reason) {
                const reasonValue = reason.value;
                const otherDiv = document.getElementById('otherReasonDiv');
                
                if (reasonValue === 'Other') {
                    otherDiv.style.display = 'block';
                    document.getElementById('otherReason').required = true;
                } else {
                    otherDiv.style.display = 'none';
                    document.getElementById('otherReason').required = false;
                }
            }
        }

        // Reset form
        function resetForm() {
            const rejDeliveryDate = document.getElementById('rejDeliveryDate');
            if (rejDeliveryDate) {
                rejDeliveryDate.value = '<?php echo date('Y-m-d'); ?>';
            }
            
            const otherDiv = document.getElementById('otherReasonDiv');
            if (otherDiv) {
                otherDiv.style.display = 'none';
            }
            
            const otherReason = document.getElementById('otherReason');
            if (otherReason) {
                otherReason.required = false;
            }
            
            const rejReason = document.getElementById('rejReason');
            if (rejReason) {
                rejReason.value = '';
            }
        }

        // Logout function
        function logout() {
            if (confirm('Are you sure you want to logout?')) {
                window.location.href = '../logout.php';
            }
        }

        // Initialize when page loads
        document.addEventListener('DOMContentLoaded', function() {
            console.log("Rejection Management page loaded!");
            
            // Initialize sidebar
            initializeSidebar();
            
            // Setup mobile toggle button - support multiple button IDs
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
            
            // Initialize reason change handler if element exists
            const rejReason = document.getElementById('rejReason');
            if (rejReason) {
                rejReason.addEventListener('change', handleReasonChange);
            }
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
            // Ctrl + R to reset form
            else if (e.ctrlKey && e.key === 'r') {
                e.preventDefault();
                resetForm();
            }
        });
    </script>
</body>
</html>