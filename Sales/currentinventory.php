<?php
require_once '../config/database.php';
require_once '../config/session_handler.php';
require_once '../config/template_helper.php';

// Protect page - only Sales role can access
requireLogin();
requireRole(['sales']);

// Get current user info
$user_id = $_SESSION['user_id'];
$user_name = isset($_SESSION['first_name']) ? $_SESSION['first_name'] . ' ' . $_SESSION['last_name'] : 'Driver User';
$user_role = isset($_SESSION['role']) ? $_SESSION['role'] : 'delivery';
$branch_id = getBranchId();
$is_admin = isAdmin();

// Get inventory for sales view - from items table with stock tracking, filtered by branch
$branch_where = $is_admin ? "" : "AND i.branch_id = $branch_id";

$query = "SELECT 
            i.item_id,
            i.item_code, 
            i.item_name, 
            i.category, 
            i.unit_price, 
            i.reorder_level,
            COALESCE(i.stock, 0) as current_stock
          FROM items i
          WHERE i.status = 'active' $branch_where
          ORDER BY i.item_name ASC";

$stmt = $conn->prepare($query);
if (!$stmt) {
    die("Database Error: " . $conn->error);
}

if (!$stmt->execute()) {
    die("Query Error: " . $stmt->error);
}

$result = $stmt->get_result();
$inventory_items = [];
if ($result) {
    $inventory_items = $result->fetch_all(MYSQLI_ASSOC);
}

// Calculate stats
$total_items = count($inventory_items);
$total_qty_available = 0;
$total_value = 0;
$in_stock_count = 0;
$low_stock_count = 0;
$critical_stock_count = 0;

foreach ($inventory_items as $item) {
    $qty_available = $item['current_stock'];
    $total_qty_available += $qty_available;
    $total_value += ($qty_available * $item['unit_price']);
    
    // Categorize stock status
    if ($qty_available <= 0) {
        $critical_stock_count++;
    } elseif ($qty_available < $item['reorder_level']) {
        $low_stock_count++;
    } else {
        $in_stock_count++;
    }
}

// Format stats for display
$total_products = $total_items;
$in_stock_display = $in_stock_count;
$low_stock_display = $low_stock_count;
$critical_stock_display = $critical_stock_count;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Current Inventory - Sales</title>
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
    <!-- SweetAlert2 -->
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
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
                <span class="nav-text">Sales</span>
        </h3>
            </div>
            
            <div class="sidebar-menu">
                <ul class="nav flex-column">
                    <li class="nav-item">
                        <a class="nav-link active" href="currentinventory.php">
                            <i class="bi bi-boxes"></i>
                            <span class="nav-text">Current Inventory</span>
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
                    <h2><i class="bi bi-boxes me-2"></i>Current Inventory</h2>
                    <p>View all available products in inventory</p>
                </div>
            </div>

<!-- Inventory Stats -->
<div class="row g-3 mb-4">

    <!-- Total Products -->
    <div class="col-md-3 mb-3">
        <div class="stat-card inventory">
            <div class="stat-icon">
                <i class="bi bi-box"></i>
            </div>
            <div>
                <div class="stat-value"><?php echo $total_products; ?></div>
                <div class="stat-label">Total Products</div>
            </div>
        </div>
    </div>

    <!-- In Stock -->
    <div class="col-md-3 mb-3">
        <div class="stat-card complete">
            <div class="stat-icon">
                <i class="bi bi-check-circle"></i>
            </div>
            <div>
                <div class="stat-value"><?php echo $in_stock_display; ?></div>
                <div class="stat-label">In Stock</div>
            </div>
        </div>
    </div>

    <!-- Low Stock -->
    <div class="col-md-3 mb-3">
        <div class="stat-card pending">
            <div class="stat-icon">
                <i class="bi bi-exclamation-circle"></i>
            </div>
            <div>
                <div class="stat-value"><?php echo $low_stock_display; ?></div>
                <div class="stat-label">Low Stock</div>
            </div>
        </div>
    </div>

    <!-- Critical Stock -->
    <div class="col-md-3 mb-3">
        <div class="stat-card sales">
            <div class="stat-icon">
                <i class="bi bi-exclamation-triangle"></i>
            </div>
            <div>
                <div class="stat-value"><?php echo $critical_stock_display; ?></div>
                <div class="stat-label">Critical Stock</div>
            </div>
        </div>
    </div>

</div>


            <!-- Search and Filter -->
            <div class="card mb-4">
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-8">
                            <div class="input-group">
                                <span class="input-group-text">
                                    <i class="bi bi-search"></i>
                                </span>
                                <input type="text" class="form-control" id="searchInput" placeholder="Search products by name, SKU, or category...">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <select class="form-select" id="categoryFilter">
                                <option value="">All Categories</option>
                                <option value="Electronics">Electronics</option>
                                <option value="Furniture">Furniture</option>
                                <option value="Clothing">Clothing</option>
                                <option value="Food">Food & Beverage</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Inventory Table -->
            <div class="card">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Product SKU</th>
                                <th>Product Name</th>
                                <th>Category</th>
                                <th>Current Stock</th>
                                <th>Unit Price</th>
                                <th>Total Value</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($inventory_items) > 0): ?>
                                <?php foreach ($inventory_items as $item): ?>
                                    <?php 
                                        $qty_available = $item['current_stock'];
                                        $line_value = $qty_available * $item['unit_price'];
                                        
                                        // Determine status badge based on stock level
                                        if ($qty_available <= 0) {
                                            $status_badge = 'bg-danger';
                                            $status_text = 'Critical';
                                        } elseif ($qty_available < $item['reorder_level']) {
                                            $status_badge = 'bg-warning';
                                            $status_text = 'Low Stock';
                                        } else {
                                            $status_badge = 'bg-success';
                                            $status_text = 'In Stock';
                                        }
                                    ?>
                                    <tr>
                                        <td><span class="badge bg-light text-dark"><?php echo htmlspecialchars($item['item_code']); ?></span></td>
                                        <td><?php echo htmlspecialchars($item['item_name']); ?></td>
                                        <td><?php echo htmlspecialchars($item['category'] ?? 'Uncategorized'); ?></td>
                                        <td>
                                            <span class="badge bg-info"><?php echo $qty_available; ?> units</span>
                                        </td>
                                        <td>₱<?php echo number_format($item['unit_price'], 2); ?></td>
                                        <td>₱<?php echo number_format($line_value, 2); ?></td>
                                        <td><span class="badge <?php echo $status_badge; ?>"><?php echo $status_text; ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" class="text-center text-muted py-4">No inventory items available</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
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

        // Category filter
        const categoryFilter = document.getElementById('categoryFilter');
        if (categoryFilter) {
            categoryFilter.addEventListener('change', function() {
                const filter = this.value.toLowerCase();
                const rows = document.querySelectorAll('tbody tr');
                
                rows.forEach(row => {
                    const categoryCell = row.cells[2];
                    if (categoryCell) {
                        const category = categoryCell.textContent.toLowerCase();
                        row.style.display = (filter === '' || category.includes(filter)) ? '' : 'none';
                    }
                });
            });
        }

        // Logout function with SweetAlert2 confirmation
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
                    window.location.href = '../login.php';
                }
            });
        }

        // Initialize when page loads
        document.addEventListener('DOMContentLoaded', function() {
            console.log("Inventory Management page loaded!");
            
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
            else if (e.ctrlKey && e.key === 'f') {
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