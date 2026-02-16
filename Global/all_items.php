<?php
require_once '../config/database.php';
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

// Get filter parameters
$category = isset($_GET['category']) ? $_GET['category'] : '';
$stock = isset($_GET['stock']) ? $_GET['stock'] : '';
$price = isset($_GET['price']) ? $_GET['price'] : '';

// Build the WHERE clause for items
$where_conditions = [];
$params = [];
$types = "";

// Base condition - only show active items
$where_conditions[] = "i.status = 'active'";

// Category filter
if (!empty($category)) {
    $where_conditions[] = "i.category = ?";
    $params[] = $category;
    $types .= "s";
}

// Stock status filter - based on items.stock column
if (!empty($stock)) {
    if ($stock === 'in_stock') {
        $where_conditions[] = "i.stock >= 10";
    } elseif ($stock === 'low_stock') {
        $where_conditions[] = "i.stock > 0 AND i.stock < i.reorder_level";
    } elseif ($stock === 'out_of_stock') {
        $where_conditions[] = "i.stock <= 0";
    }
}

// Price range filter
if (!empty($price)) {
    if ($price === '0-50') {
        $where_conditions[] = "i.unit_price BETWEEN 0 AND 50";
    } elseif ($price === '50-100') {
        $where_conditions[] = "i.unit_price BETWEEN 50 AND 100";
    } elseif ($price === '100-500') {
        $where_conditions[] = "i.unit_price BETWEEN 100 AND 500";
    } elseif ($price === '500+') {
        $where_conditions[] = "i.unit_price > 500";
    }
}

$where_clause = "WHERE " . implode(" AND ", $where_conditions);

// Get items from database - BASED ONLY ON items TABLE
$sql = "SELECT 
            i.item_id as id, 
            i.item_code, 
            i.item_name, 
            i.description, 
            i.category, 
            i.stock, 
            i.unit_type, 
            i.unit_price, 
            i.reorder_level, 
            i.status,
            i.created_at,
            i.updated_at
        FROM items i
        $where_clause
        ORDER BY i.item_id DESC";

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$items_result = $stmt->get_result();
$items = [];
while ($row = $items_result->fetch_assoc()) {
    $items[] = $row;
}

// Get statistics - BASED ONLY ON items TABLE
$stats_sql = "SELECT 
                COUNT(*) as totalItems,
                SUM(CASE WHEN stock >= 10 THEN 1 ELSE 0 END) as inStockItems,
                SUM(CASE WHEN stock <= 0 THEN 1 ELSE 0 END) as outOfStockItems
              FROM items
              WHERE status = 'active'";
$stats_result = $conn->query($stats_sql);
$stats = $stats_result->fetch_assoc();

$totalItems = $stats['totalItems'] ?? 0;
$inStockItems = $stats['inStockItems'] ?? 0;
$outOfStockItems = $stats['outOfStockItems'] ?? 0;

// Get unique categories for filter dropdown
$categories_sql = "SELECT DISTINCT category FROM items WHERE status = 'active' AND category IS NOT NULL ORDER BY category";
$categories_result = $conn->query($categories_sql);
$categories = [];
while ($cat_row = $categories_result->fetch_assoc()) {
    $categories[] = $cat_row['category'];
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

// Handle AJAX request for item details
if (isset($_GET['ajax']) && isset($_GET['id'])) {
    $item_id = intval($_GET['id']);
    $item_sql = "SELECT 
                    i.item_id as id, 
                    i.item_code, 
                    i.item_name, 
                    i.description, 
                    i.category, 
                    i.stock, 
                    i.unit_type, 
                    i.unit_price, 
                    i.reorder_level, 
                    i.status,
                    i.created_at,
                    i.updated_at
                 FROM items i 
                 WHERE i.item_id = ? AND i.status = 'active'";
    $item_stmt = $conn->prepare($item_sql);
    $item_stmt->bind_param("i", $item_id);
    $item_stmt->execute();
    $item_result = $item_stmt->get_result();
    
    if ($item_result->num_rows > 0) {
        $item = $item_result->fetch_assoc();
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'item' => $item]);
    } else {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Item not found']);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Global - All Items Catalog</title>
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
                    <span class="nav-text">Global</span>
                </h3>
            </div>
            
            <div class="sidebar-menu">
                <ul class="nav flex-column">
                    <li class="nav-item">
                        <a class="nav-link" href="sales_reports.php">
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
                        <a class="nav-link active" href="all_items.php">
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
             <!-- User Profile Section at the bottom of sidebar -->
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
            <!-- ALL ITEMS PAGE -->
            <div id="itemsContent" class="page-content active">
                <div class="navbar-top">
                        <button class="mobile-toggle-btn" id="mobileToggleBtn">
                            <i class="bi bi-list"></i>
                        </button>

                        <div class="page-title">
                        <h2>All Items Catalog</h2>
                        <p>View all items across the system, including out-of-stock items</p>
                    </div>
                </div>

                <div class="row g-3 mb-4">
                    <div class="col-md-4">
                        <div class="stat-card total">
                            <div class="stat-value" id="totalItems"><?php echo number_format($totalItems); ?></div>
                            <div class="stat-label">Total Items</div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="stat-card sales">
                            <div class="stat-value" id="inStockItems"><?php echo number_format($inStockItems); ?></div>
                            <div class="stat-label">In Stock</div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="stat-card complete">
                            <div class="stat-value" id="outOfStockItems"><?php echo number_format($outOfStockItems); ?></div>
                            <div class="stat-label">Out of Stock</div>
                        </div>
                    </div>
                </div>

                <div class="row g-3 mb-4">
                    <div class="col-12">
                        <div class="form-card">
                            <div class="d-flex justify-content-between align-items-center">
                                <h5 class="mb-0">Filter Items</h5>
                            </div>
                            <div class="row mt-3">
                                <div class="col-md-4">
                                    <label class="form-label">Category</label>
                                    <select class="form-select" id="categoryFilter" onchange="applyFilters()">
                                        <option value="">All Categories</option>
                                        <?php foreach ($categories as $cat): ?>
                                            <option value="<?php echo htmlspecialchars($cat); ?>" <?php echo $category == $cat ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($cat); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Stock Status</label>
                                    <select class="form-select" id="stockFilter" onchange="applyFilters()">
                                        <option value="">All Items</option>
                                        <option value="in_stock" <?php echo $stock == 'in_stock' ? 'selected' : ''; ?>>In Stock</option>
                                        <option value="low_stock" <?php echo $stock == 'low_stock' ? 'selected' : ''; ?>>Low Stock</option>
                                        <option value="out_of_stock" <?php echo $stock == 'out_of_stock' ? 'selected' : ''; ?>>Out of Stock</option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Price Range</label>
                                    <select class="form-select" id="priceFilter" onchange="applyFilters()">
                                        <option value="">All Prices</option>
                                        <option value="0-50" <?php echo $price == '0-50' ? 'selected' : ''; ?>>₱0 - ₱50</option>
                                        <option value="50-100" <?php echo $price == '50-100' ? 'selected' : ''; ?>>₱50 - ₱100</option>
                                        <option value="100-500" <?php echo $price == '100-500' ? 'selected' : ''; ?>>₱100 - ₱500</option>
                                        <option value="500+" <?php echo $price == '500+' ? 'selected' : ''; ?>>₱500+</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="data-table">
                    <div class="table-header">
                        <h5>Complete Items Catalog</h5>
                    </div>
                    <div class="table-responsive">
                        <table class="table custom-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Item Name</th>
                                    <th>Category</th>
                                    <th>Unit Price</th>
                                    <th>Total Quantity</th>
                                    <th>Available</th>
                                    <th>Stock Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="itemsTable">
                                <?php if (count($items) > 0): ?>
                                    <?php foreach ($items as $item): ?>
                                        <?php 
                                        $stock_quantity = $item['stock'] ?? 0;
                                        $reorder_level = $item['reorder_level'] ?? 50;
                                        
                                        $statusBadge = 'bg-success';
                                        $statusText = 'In Stock';
                                        if ($stock_quantity <= 0) {
                                            $statusBadge = 'bg-danger';
                                            $statusText = 'Out of Stock';
                                        } elseif ($stock_quantity < $reorder_level) {
                                            $statusBadge = 'bg-warning';
                                            $statusText = 'Low Stock';
                                        }
                                        ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($item['id']); ?></td>
                                            <td><strong><?php echo htmlspecialchars($item['item_name']); ?></strong></td>
                                            <td><?php echo htmlspecialchars($item['category'] ?? 'N/A'); ?></td>
                                            <td>₱<?php echo number_format($item['unit_price'] ?? 0, 2); ?></td>
                                            <td><?php echo number_format($stock_quantity); ?></td>
                                            <td><?php echo number_format($stock_quantity); ?></td>
                                            <td>
                                                <span class="badge <?php echo $statusBadge; ?>">
                                                    <?php echo $statusText; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <button class="btn btn-sm btn-info" onclick="viewItem(<?php echo $item['id']; ?>)">
                                                    <i class="bi bi-eye"></i> View
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="8" class="text-center py-4">No items found</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Item Details Modal -->
    <div class="modal fade" id="itemModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Item Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="itemDetails">
                    <!-- Details will be populated here -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
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

        // Apply filters by submitting form
        function applyFilters() {
            const category = document.getElementById('categoryFilter').value;
            const stock = document.getElementById('stockFilter').value;
            const price = document.getElementById('priceFilter').value;
            
            const params = new URLSearchParams();
            if (category) params.append('category', category);
            if (stock) params.append('stock', stock);
            if (price) params.append('price', price);
            
            window.location.href = 'all_items.php?' + params.toString();
        }

        // Logout function
        function logout() {
            window.location.href = '../logout.php';
        }

        // View item details via AJAX
        function viewItem(id) {
            const modal = new bootstrap.Modal(document.getElementById('itemModal'));
            const details = document.getElementById('itemDetails');
            details.innerHTML = '<p>Loading item details...</p>';
            modal.show();
            
            fetch('all_items.php?ajax=1&id=' + id)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const item = data.item;
                        details.innerHTML = `
                            <dl class="row">
                                <dt class="col-sm-4">Item ID:</dt>
                                <dd class="col-sm-8">${item.id}</dd>
                                <dt class="col-sm-4">Item Code:</dt>
                                <dd class="col-sm-8">${item.item_code || 'N/A'}</dd>
                                <dt class="col-sm-4">Item Name:</dt>
                                <dd class="col-sm-8">${item.item_name}</dd>
                                <dt class="col-sm-4">Category:</dt>
                                <dd class="col-sm-8">${item.category || 'N/A'}</dd>
                                <dt class="col-sm-4">Description:</dt>
                                <dd class="col-sm-8">${item.description || 'No description'}</dd>
                                <dt class="col-sm-4">Unit Type:</dt>
                                <dd class="col-sm-8">${item.unit_type || 'piece'}</dd>
                                <dt class="col-sm-4">Unit Price:</dt>
                                <dd class="col-sm-8">₱${parseFloat(item.unit_price || 0).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}</dd>
                                <dt class="col-sm-4">Stock Quantity:</dt>
                                <dd class="col-sm-8">${Number(item.stock || 0).toLocaleString()}</dd>
                                <dt class="col-sm-4">Reorder Level:</dt>
                                <dd class="col-sm-8">${Number(item.reorder_level || 50).toLocaleString()}</dd>
                                <dt class="col-sm-4">Status:</dt>
                                <dd class="col-sm-8"><span class="badge ${item.status === 'active' ? 'bg-success' : 'bg-secondary'}">${item.status || 'active'}</span></dd>
                                <dt class="col-sm-4">Created At:</dt>
                                <dd class="col-sm-8">${item.created_at ? new Date(item.created_at).toLocaleString() : 'N/A'}</dd>
                                <dt class="col-sm-4">Updated At:</dt>
                                <dd class="col-sm-8">${item.updated_at ? new Date(item.updated_at).toLocaleString() : 'N/A'}</dd>
                            </dl>
                        `;
                    } else {
                        details.innerHTML = '<p class="text-danger">Failed to load item details.</p>';
                    }
                })
                .catch(error => {
                    console.error('Error loading item details:', error);
                    details.innerHTML = '<p class="text-danger">Error loading item details.</p>';
                });
        }

        // Initialize when page loads
        document.addEventListener('DOMContentLoaded', function() {
            console.log("Items Management page loaded!");
            
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
                const mobileBtn = document.getElementById('mobileToggleBtn');
                const overlay = document.querySelector('.sidebar-overlay');
                const isMobile = window.innerWidth <= 992;
                
                if (isMobile && sidebar.classList.contains('active') && 
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
        });
    </script>
</body>
</html>
<?php $conn->close(); ?>