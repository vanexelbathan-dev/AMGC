<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pick List Items - Warehouse</title>
    <link rel="stylesheet" href="../css/style.css">
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <style>
    /* Mobile responsive adjustments ONLY - same as warehouse.php */
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
            
            /* Make cards 2 columns on mobile */
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
        
        /* Extra small devices (phones, less than 576px) */
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
    <?php
    session_start();
    require_once '../config/database.php';
    
    // Check if user is logged in
    if (!isset($_SESSION['user_id'])) {
        header("Location: ../login.php");
        exit();
    }
    
    // Handle form submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_pick_item') {
        // Get form data
        $pick_list_id = $_POST['pick_list_id'];
        $item_id = $_POST['item_id'];
        $quantity_to_pick = $_POST['quantity_to_pick'];
        $location_bin = $_POST['location_bin'] ?: NULL;
        
        // Check if item already exists in the pick list
        $check_query = "SELECT * FROM pick_list_items WHERE pick_list_id = ? AND item_id = ?";
        $stmt = $conn->prepare($check_query);
        $stmt->bind_param("ii", $pick_list_id, $item_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $error_message = "This item already exists in the selected pick list!";
        } else {
            // Insert into database
            $insert_query = "INSERT INTO pick_list_items (pick_list_id, item_id, quantity_to_pick, location_bin) VALUES (?, ?, ?, ?)";
            $stmt = $conn->prepare($insert_query);
            $stmt->bind_param("iiis", $pick_list_id, $item_id, $quantity_to_pick, $location_bin);
            
            if ($stmt->execute()) {
                // Update inventory reserved quantity
                // First get the branch_id from pick_list
                $branch_query = "SELECT branch_id FROM pick_lists WHERE pick_list_id = ?";
                $branch_stmt = $conn->prepare($branch_query);
                $branch_stmt->bind_param("i", $pick_list_id);
                $branch_stmt->execute();
                $branch_result = $branch_stmt->get_result();
                
                if ($branch_row = $branch_result->fetch_assoc()) {
                    $branch_id = $branch_row['branch_id'];
                    
                    // Update inventory reserved quantity
                    $update_inventory_query = "UPDATE inventory SET quantity_reserved = quantity_reserved + ? WHERE branch_id = ? AND item_id = ?";
                    $update_stmt = $conn->prepare($update_inventory_query);
                    $update_stmt->bind_param("iii", $quantity_to_pick, $branch_id, $item_id);
                    $update_stmt->execute();
                    $update_stmt->close();
                }
                
                $branch_stmt->close();
                
                $success_message = "Pick list item added successfully!";
            } else {
                $error_message = "Error adding pick list item: " . $conn->error;
            }
        }
        $stmt->close();
    }
    ?>
    
    <!-- MOBILE MENU BUTTON -->
    <button class="mobile-menu-btn" id="mobileMenuBtn">
        <i class="bi bi-list"></i>
    </button>

    <!-- MAIN APPLICATION -->
    <div id="appPage">
        <!-- Sidebar -->
        <div class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <h3><i class="bi bi-building logo-icon"></i> <span class="nav-text">Warehouse</span></h3>
            </div>
            
            <div class="sidebar-menu">
                <ul class="nav flex-column">
                    <li class="nav-item">
                        <a class="nav-link" href="warehouse.php">
                            <i class="bi bi-speedometer2"></i>
                            <span class="nav-text">Dashboard</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="currentinventory.php">
                            <i class="bi bi-boxes"></i>
                            <span class="nav-text">Current Inventory</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="pick_list_items.php">
                            <i class="bi bi-clipboard-check"></i>
                            <span class="nav-text">Pick List Items</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="drivers.php">
                            <i class="bi bi-person-badge"></i>
                            <span class="nav-text">Drivers</span>
                        </a>
                    </li>
                </ul>
            </div>
        </div>

        <!-- Main Content Area -->
        <div class="main-content">
            <!-- Header Section with User Info and Logout -->
            <div class="navbar-top">
                <div class="page-title">
                    <h2><i class="bi bi-clipboard-check me-2"></i>Pick List Items</h2>
                    <p>Manage and track pick list items for shipments</p>
                </div>
                
                <div class="user-info-top">
                    <div class="user-profile-top">
                        <div class="user-avatar-top" id="userAvatar">WM</div>
                        <div class="user-details-top">
                            <span class="user-name-top" id="userName"><?php echo isset($_SESSION['first_name']) ? $_SESSION['first_name'] . ' ' . $_SESSION['last_name'] : 'Warehouse Manager'; ?></span>
                            <span class="user-role-top" id="userRole"><?php echo isset($_SESSION['role']) ? ucfirst($_SESSION['role']) : 'Warehouse'; ?></span>
                        </div>
                    </div>
                    
                    <button class="logout-btn-top" onclick="logout()">
                        <i class="bi bi-box-arrow-right"></i> Logout
                    </button>
                </div>
            </div>

            <?php
            // Get pick list statistics
            $stats = [];
            
            // Total Pick List Items
            $total_items_query = "SELECT COUNT(*) as count FROM pick_list_items";
            $result = $conn->query($total_items_query);
            $stats['total_items'] = $result->fetch_assoc()['count'] ?? 0;
            
            // Picked Items
            $picked_query = "SELECT COUNT(*) as count FROM pick_list_items WHERE quantity_picked >= quantity_to_pick";
            $result = $conn->query($picked_query);
            $stats['picked'] = $result->fetch_assoc()['count'] ?? 0;
            
            // Pending Pickup
            $pending_query = "SELECT COUNT(*) as count FROM pick_list_items WHERE quantity_picked = 0";
            $result = $conn->query($pending_query);
            $stats['pending'] = $result->fetch_assoc()['count'] ?? 0;
            
            // Partial Pickup
            $partial_query = "SELECT COUNT(*) as count FROM pick_list_items WHERE quantity_picked > 0 AND quantity_picked < quantity_to_pick";
            $result = $conn->query($partial_query);
            $stats['partial'] = $result->fetch_assoc()['count'] ?? 0;
            
            // Total Value (estimated)
            $value_query = "SELECT SUM(pli.quantity_to_pick * i.unit_price) as total_value 
                           FROM pick_list_items pli
                           JOIN items i ON pli.item_id = i.item_id";
            $result = $conn->query($value_query);
            $total_value = $result->fetch_assoc()['total_value'] ?? 0;
            ?>

            <!-- Success/Error Messages -->
            <?php if (isset($success_message)): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <?php echo $success_message; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <?php if (isset($error_message)): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <?php echo $error_message; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Pick List Stats -->
            <div class="row g-3 mb-4">
                <!-- Total Items -->
                <div class="col-md-3 mb-3">
                    <div class="stat-card inventory">
                        <div class="stat-icon">
                            <i class="bi bi-clipboard-check"></i>
                        </div>
                        <div>
                            <div class="stat-value"><?php echo $stats['total_items']; ?></div>
                            <div class="stat-label">Total Items</div>
                        </div>
                    </div>
                </div>

                <!-- Picked -->
                <div class="col-md-3 mb-3">
                    <div class="stat-card sales">
                        <div class="stat-icon">
                            <i class="bi bi-check-circle"></i>
                        </div>
                        <div>
                            <div class="stat-value"><?php echo $stats['picked']; ?></div>
                            <div class="stat-label">Picked</div>
                        </div>
                    </div>
                </div>

                <!-- Pending -->
                <div class="col-md-3 mb-3">
                    <div class="stat-card pending">
                        <div class="stat-icon">
                            <i class="bi bi-hourglass-split"></i>
                        </div>
                        <div>
                            <div class="stat-value"><?php echo $stats['pending']; ?></div>
                            <div class="stat-label">Pending Pickup</div>
                        </div>
                    </div>
                </div>

                <!-- Total Value -->
                <div class="col-md-3 mb-3">
                    <div class="stat-card delivery">
                        <div class="stat-icon">
                            <i class="bi bi-currency-exchange"></i>
                        </div>
                        <div>
                            <div class="stat-value">₱<?php echo number_format($total_value, 0); ?></div>
                            <div class="stat-label">Total Value</div>
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
                                <input type="text" class="form-control" id="searchInput" placeholder="Search by item or pick list...">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <select class="form-select" id="statusFilter">
                                <option value="">All Status</option>
                                <option value="complete">Complete</option>
                                <option value="partial">Partial</option>
                                <option value="pending">Pending</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Pick List Items Table -->
            <div class="card">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Pick List #</th>
                                <th>Item Name</th>
                                <th>Quantity to Pick</th>
                                <th>Quantity Picked</th>
                                <th>Location Bin</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $pick_list_items_query = "SELECT pli.*, pl.pick_list_number, i.item_name, i.item_code,
                                                     CASE 
                                                         WHEN pli.quantity_picked >= pli.quantity_to_pick THEN 'Complete'
                                                         WHEN pli.quantity_picked > 0 THEN 'Partial'
                                                         ELSE 'Pending'
                                                     END as pick_status
                                                     FROM pick_list_items pli
                                                     JOIN pick_lists pl ON pli.pick_list_id = pl.pick_list_id
                                                     JOIN items i ON pli.item_id = i.item_id
                                                     ORDER BY pli.pick_item_id DESC";
                            $result = $conn->query($pick_list_items_query);
                            
                            if ($result->num_rows > 0) {
                                while($row = $result->fetch_assoc()) {
                                    $status_badge = '';
                                    switch($row['pick_status']) {
                                        case 'Complete': $status_badge = 'bg-success'; break;
                                        case 'Partial': $status_badge = 'bg-info'; break;
                                        default: $status_badge = 'bg-warning';
                                    }
                                    
                                    // Calculate completion percentage
                                    $completion = $row['quantity_to_pick'] > 0 ? ($row['quantity_picked'] / $row['quantity_to_pick']) * 100 : 0;
                                    ?>
                                    <tr>
                                        <td><span class="badge bg-light text-dark"><?php echo $row['pick_list_number']; ?></span></td>
                                        <td>
                                            <div><?php echo $row['item_name']; ?></div>
                                            <small class="text-muted"><?php echo $row['item_code']; ?></small>
                                        </td>
                                        <td><?php echo $row['quantity_to_pick']; ?></td>
                                        <td>
                                            <div><?php echo $row['quantity_picked']; ?></div>
                                            <small class="text-muted"><?php echo number_format($completion, 0); ?>%</small>
                                        </td>
                                        <td><?php echo $row['location_bin'] ?? 'N/A'; ?></td>
                                        <td><span class="badge <?php echo $status_badge; ?>"><?php echo $row['pick_status']; ?></span></td>
                                        <td>
                                            <div class="btn-group" role="group">
                                                <button class="btn btn-sm btn-info" data-bs-toggle="modal" data-bs-target="#viewItemModal" 
                                                        onclick="loadPickItemDetails('<?php echo $row['pick_item_id']; ?>')">
                                                    <i class="bi bi-eye"></i>
                                                </button>
                                                <button class="btn btn-sm btn-warning" data-bs-toggle="modal" data-bs-target="#updatePickModal"
                                                        onclick="setUpdatePickItem('<?php echo $row['pick_item_id']; ?>', '<?php echo $row['quantity_to_pick']; ?>', '<?php echo $row['quantity_picked']; ?>')">
                                                    <i class="bi bi-pencil"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php
                                }
                            } else {
                                echo '<tr><td colspan="7" class="text-center">No pick list items found</td></tr>';
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Pick List Item Modal -->
    <div class="modal fade" id="addPickListModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Add Pick List Item</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="addPickListForm" method="POST">
                    <input type="hidden" name="action" value="add_pick_item">
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Pick List <span class="text-danger">*</span></label>
                                <select class="form-select" name="pick_list_id" required>
                                    <option value="">Select Pick List</option>
                                    <?php
                                    $pick_lists_query = "SELECT pl.pick_list_id, pl.pick_list_number, b.branch_name 
                                                        FROM pick_lists pl
                                                        JOIN branches b ON pl.branch_id = b.branch_id
                                                        WHERE pl.pick_status IN ('open', 'in-progress')
                                                        ORDER BY pl.created_at DESC";
                                    $result = $conn->query($pick_lists_query);
                                    if ($result->num_rows > 0) {
                                        while($pick_list = $result->fetch_assoc()) {
                                            echo '<option value="' . $pick_list['pick_list_id'] . '">' . 
                                                 $pick_list['pick_list_number'] . ' - ' . $pick_list['branch_name'] . '</option>';
                                        }
                                    } else {
                                        echo '<option value="">No active pick lists available</option>';
                                    }
                                    ?>
                                </select>
                                <small class="text-muted">Only open or in-progress pick lists are shown</small>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Item <span class="text-danger">*</span></label>
                                <select class="form-select" name="item_id" required>
                                    <option value="">Select Item</option>
                                    <?php
                                    $items_query = "SELECT item_id, item_name, item_code FROM items WHERE status = 'active' ORDER BY item_name";
                                    $result = $conn->query($items_query);
                                    while($item = $result->fetch_assoc()) {
                                        echo '<option value="' . $item['item_id'] . '">' . 
                                             $item['item_code'] . ' - ' . $item['item_name'] . '</option>';
                                    }
                                    ?>
                                </select>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Quantity to Pick <span class="text-danger">*</span></label>
                                <input type="number" class="form-control" name="quantity_to_pick" required placeholder="0" min="1" value="1">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Location Bin</label>
                                <input type="text" class="form-control" name="location_bin" placeholder="e.g., A-12, B-05">
                            </div>
                        </div>
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle"></i> 
                            Adding an item to a pick list will automatically reserve the quantity in inventory.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Add Item</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Create New Pick List Modal -->
    <div class="modal fade" id="createPickListModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Create New Pick List</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form action="create_pick_list.php" method="POST" target="_blank">
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Sales Order</label>
                                <select class="form-select" name="so_id" required>
                                    <option value="">Select Sales Order</option>
                                    <?php
                                    $sales_orders_query = "SELECT so.so_id, so.so_number, c.customer_name 
                                                          FROM sales_orders so
                                                          JOIN customers c ON so.customer_id = c.customer_id
                                                          WHERE so.order_status IN ('confirmed', 'processing')
                                                          ORDER BY so.order_date DESC";
                                    $result = $conn->query($sales_orders_query);
                                    while($so = $result->fetch_assoc()) {
                                        echo '<option value="' . $so['so_id'] . '">' . 
                                             $so['so_number'] . ' - ' . $so['customer_name'] . '</option>';
                                    }
                                    ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Branch</label>
                                <select class="form-select" name="branch_id" required>
                                    <option value="">Select Branch</option>
                                    <?php
                                    $branches_query = "SELECT branch_id, branch_name FROM branches WHERE status = 'active'";
                                    $result = $conn->query($branches_query);
                                    while($branch = $result->fetch_assoc()) {
                                        echo '<option value="' . $branch['branch_id'] . '">' . $branch['branch_name'] . '</option>';
                                    }
                                    ?>
                                </select>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Pick Date</label>
                                <input type="date" class="form-control" name="pick_date" value="<?php echo date('Y-m-d'); ?>">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Status</label>
                                <select class="form-select" name="pick_status">
                                    <option value="open" selected>Open</option>
                                    <option value="in-progress">In Progress</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success">Create Pick List</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Update Pick Quantity Modal -->
    <div class="modal fade" id="updatePickModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Update Picked Quantity</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="updatePickForm" method="POST">
                    <input type="hidden" name="action" value="update_pick_quantity">
                    <input type="hidden" name="pick_item_id" id="update_pick_item_id">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Quantity to Pick</label>
                            <input type="number" class="form-control" id="update_quantity_to_pick" readonly>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Quantity Picked <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" name="quantity_picked" id="update_quantity_picked" 
                                   placeholder="Enter picked quantity" min="0" required>
                        </div>
                        <div class="alert alert-warning">
                            <i class="bi bi-exclamation-triangle"></i> 
                            Make sure the picked quantity is accurate. This cannot be easily reversed.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-warning">Update Picked Quantity</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- View Item Details Modal -->
    <div class="modal fade" id="viewItemModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Pick List Item Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="pickItemDetailsContent">
                    <!-- Content will be loaded by JavaScript -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Mobile menu toggle
        document.getElementById('mobileMenuBtn').addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('show');
        });

        // Load pick item details via AJAX
        function loadPickItemDetails(pickItemId) {
            fetch('get_pick_item_details.php?pick_item_id=' + pickItemId)
                .then(response => response.text())
                .then(data => {
                    document.getElementById('pickItemDetailsContent').innerHTML = data;
                })
                .catch(error => {
                    console.error('Error:', error);
                    document.getElementById('pickItemDetailsContent').innerHTML = '<div class="alert alert-danger">Failed to load item details</div>';
                });
        }

        // Set values for update pick modal
        function setUpdatePickItem(pickItemId, quantityToPick, quantityPicked) {
            document.getElementById('update_pick_item_id').value = pickItemId;
            document.getElementById('update_quantity_to_pick').value = quantityToPick;
            document.getElementById('update_quantity_picked').value = quantityPicked;
            document.getElementById('update_quantity_picked').max = quantityToPick;
        }

        // Handle update pick form submission
        document.getElementById('updatePickForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            
            fetch('update_pick_quantity.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Pick quantity updated successfully!');
                    window.location.reload();
                } else {
                    alert('Error: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Failed to update pick quantity');
            });
        });

        // Search functionality
        document.getElementById('searchInput').addEventListener('keyup', function() {
            const filter = this.value.toLowerCase();
            const rows = document.querySelectorAll('tbody tr');
            
            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(filter) ? '' : 'none';
            });
        });

        // Status filter
        document.getElementById('statusFilter').addEventListener('change', function() {
            const filter = this.value.toLowerCase();
            const rows = document.querySelectorAll('tbody tr');
            
            rows.forEach(row => {
                const status = row.cells[5].textContent.toLowerCase();
                row.style.display = (filter === '' || status.includes(filter)) ? '' : 'none';
            });
        });

        // Logout function
        function logout() {
            if (confirm('Are you sure you want to logout?')) {
                window.location.href = '../logout.php';
            }
        }

        // Form validation
        document.getElementById('addPickListForm').addEventListener('submit', function(e) {
            const pickListSelect = this.querySelector('select[name="pick_list_id"]');
            const itemSelect = this.querySelector('select[name="item_id"]');
            const quantityInput = this.querySelector('input[name="quantity_to_pick"]');
            
            if (!pickListSelect.value) {
                e.preventDefault();
                alert('Please select a pick list');
                pickListSelect.focus();
                return false;
            }
            
            if (!itemSelect.value) {
                e.preventDefault();
                alert('Please select an item');
                itemSelect.focus();
                return false;
            }
            
            if (!quantityInput.value || quantityInput.value <= 0) {
                e.preventDefault();
                alert('Please enter a valid quantity (minimum 1)');
                quantityInput.focus();
                return false;
            }
            
            return confirm('Are you sure you want to add this item to the pick list?');
        });
    </script>
</body>
</html>