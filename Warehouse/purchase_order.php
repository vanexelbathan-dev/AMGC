<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

// Get current user info and branch context
$user_id = $_SESSION['user_id'];
$user_name = isset($_SESSION['first_name']) ? $_SESSION['first_name'] . ' ' . $_SESSION['last_name'] : 'Warehouse User';
$user_role = isset($_SESSION['role']) ? $_SESSION['role'] : 'warehouse';
$user_branch_id = $_SESSION['branch_id'] ?? 0;
$view_all_branches = $_SESSION['view_all_branches'] ?? false;

// Get user's branch name for display
$branch_name = 'All Branches';
if (!$view_all_branches && $user_branch_id > 0) {
    $branch_query = "SELECT branch_name FROM branches WHERE branch_id = ?";
    $branch_stmt = $conn->prepare($branch_query);
    $branch_stmt->bind_param("i", $user_branch_id);
    $branch_stmt->execute();
    $branch_result = $branch_stmt->get_result();
    if ($branch_row = $branch_result->fetch_assoc()) {
        $branch_name = $branch_row['branch_name'];
    }
    $branch_stmt->close();
}

// IMPORTANT: Get user's category directly from database
$user_category = '';
$cat_query = "SELECT category FROM users WHERE user_id = ?";
$cat_stmt = $conn->prepare($cat_query);
if ($cat_stmt) {
    $cat_stmt->bind_param("i", $user_id);
    $cat_stmt->execute();
    $cat_result = $cat_stmt->get_result();
    if ($cat_row = $cat_result->fetch_assoc()) {
        $user_category = $cat_row['category'];
    }
    $cat_stmt->close();
}

// Check if branch_id column exists in items table
$items_branch_column_exists = false;
$check_branch_col = $conn->query("SHOW COLUMNS FROM items LIKE 'branch_id'");
if ($check_branch_col && $check_branch_col->num_rows > 0) {
    $items_branch_column_exists = true;
}

// Handle load PO details AJAX request
if (isset($_GET['load_po_details'])) {
    $po_id = (int)$_GET['load_po_details'];
    
    try {
        // Get PO details
        $po_query = "SELECT po.*, b.branch_name 
                     FROM purchase_orders po 
                     LEFT JOIN branches b ON po.branch_id = b.branch_id 
                     WHERE po.po_id = ?";
        
        $stmt = $conn->prepare($po_query);
        if (!$stmt) {
            throw new Exception("Database error: " . $conn->error);
        }
        
        $stmt->bind_param("i", $po_id);
        if (!$stmt->execute()) {
            throw new Exception("Execution error: " . $stmt->error);
        }
        
        $result = $stmt->get_result();
        $po = $result->fetch_assoc();
        
        if (!$po) {
            throw new Exception("Purchase order not found");
        }
        
        // Verify branch access
        if (!$view_all_branches && $po['branch_id'] != $user_branch_id) {
            throw new Exception("You don't have permission to view this PO");
        }
        
        // Get PO items
        $items_query = "SELECT poi.*, i.item_code, i.item_name, i.unit_type, i.stock
                        FROM purchase_order_items poi
                        JOIN items i ON poi.item_id = i.item_id
                        WHERE poi.po_id = ?
                        ORDER BY poi.po_item_id";
        
        $items_stmt = $conn->prepare($items_query);
        if (!$items_stmt) {
            throw new Exception("Database error: " . $conn->error);
        }
        
        $items_stmt->bind_param("i", $po_id);
        if (!$items_stmt->execute()) {
            throw new Exception("Execution error: " . $items_stmt->error);
        }
        
        $items_result = $items_stmt->get_result();
        $items = [];
        while ($row = $items_result->fetch_assoc()) {
            $items[] = $row;
        }
        
        // Calculate totals and completion percentage
        $total_ordered = 0;
        $total_received = 0;
        foreach ($items as $item) {
            $total_ordered += $item['quantity_ordered'];
            $total_received += $item['quantity_received'] ?? 0;
        }
        $completion_percentage = $total_ordered > 0 ? ($total_received / $total_ordered) * 100 : 0;
        
        // Determine status badge color
        $status_badge = 'warning';
        if ($completion_percentage == 100) {
            $status_badge = 'success';
        } elseif ($completion_percentage > 0) {
            $status_badge = 'info';
        }
        
        ?>
        <div class="row">
            <div class="col-md-6">
                <div class="card mb-3">
                    <div class="card-header">
                        <h6 class="mb-0"><i class="bi bi-file-earmark me-2"></i>PO Information</h6>
                    </div>
                    <div class="card-body py-2">
                        <table class="table table-hover mb-0">
                            <tr>
                                <td width="40%"><strong>PO Number:</strong></td>
                                <td><?php echo htmlspecialchars($po['po_number']); ?></td>
                            </tr>
                            <tr>
                                <td><strong>Supplier:</strong></td>
                                <td><?php echo htmlspecialchars($po['supplier_name']); ?></td>
                            </tr>
                            <tr>
                                <td><strong>PO Date:</strong></td>
                                <td><?php echo date('M d, Y', strtotime($po['order_date'])); ?></td>
                            </tr>
                            <tr>
                                <td><strong>Branch:</strong></td>
                                <td><?php echo htmlspecialchars($po['branch_name'] ?? 'N/A'); ?></td>
                            </tr>
                        </table>
                    </div>
                </div>
            </div>
            
            <div class="col-md-6">
                <div class="card mb-3">
                    <div class="card-header">
                        <h6 class="mb-0"><i class="bi bi-truck me-2"></i>Receive Details</h6>
                    </div>
                    <div class="card-body py-2">
                        <table class="table table-sm table-borderless mb-0">
                            <tr>
                                <td width="40%"><strong>Status:</strong></td>
                                <td>
                                    <span class="badge bg-<?php echo $status_badge; ?>">
                                        <?php echo ucfirst($po['po_status'] ?? 'pending'); ?>
                                    </span>
                                </td>
                            </tr>
                            <tr>
                                <td><strong>Total Ordered:</strong></td>
                                <td><?php echo $total_ordered; ?> units</td>
                            </tr>
                            <tr>
                                <td><strong>Total Received:</strong></td>
                                <td><?php echo $total_received; ?> units</td>
                            </tr>
                            <tr>
                                <td><strong>Pending:</strong></td>
                                <td><?php echo ($total_ordered - $total_received); ?> units</td>
                            </tr>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-12">
                <div class="card mb-3">
                    <div class="card-header">
                        <h6 class="mb-0"><i class="bi bi-list-check me-2"></i>Purchase Order Items</h6>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>Item Code</th>
                                    <th>Item Name</th>
                                    <th>Unit</th>
                                    <th class="text-center">Ordered</th>
                                    <th class="text-center">Received</th>
                                    <th class="text-center">Pending</th>
                                    <th class="text-center">Current Stock</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($items as $item): 
                                    $received = $item['quantity_received'] ?? 0;
                                    $ordered = $item['quantity_ordered'];
                                    $pending = $ordered - $received;
                                    $current_stock = $item['stock'] ?? 0;
                                ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($item['item_code']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($item['item_name']); ?></td>
                                    <td><?php echo htmlspecialchars($item['unit_type'] ?? 'Unit'); ?></td>
                                    <td class="text-center"><?php echo $ordered; ?></td>
                                    <td class="text-center"><?php echo $received; ?></td>
                                    <td class="text-center"><?php echo $pending; ?></td>
                                    <td class="text-center"><?php echo $current_stock; ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="row mt-2">
            <div class="col-12 text-end">
                <button type="button" class="btn btn-outline-primary" onclick="window.print()">
                    <i class="bi bi-printer"></i> Print Details
                </button>
            </div>
        </div>
        <?php
        exit;
    } catch (Exception $e) {
        ?>
        <div class="alert alert-danger" role="alert">
            <i class="bi bi-exclamation-triangle"></i> Error: <?php echo htmlspecialchars($e->getMessage()); ?>
        </div>
        <?php
        exit;
    }
}

// Handle load receive items AJAX request
if (isset($_GET['load_receive_items'])) {
    $po_id = (int)$_GET['load_receive_items'];
    
    try {
        // Get PO details
        $po_query = "SELECT po_number, supplier_name, branch_id FROM purchase_orders WHERE po_id = ?";
        $stmt = $conn->prepare($po_query);
        $stmt->bind_param("i", $po_id);
        $stmt->execute();
        $po = $stmt->get_result()->fetch_assoc();
        
        // Get PO items with current stock
        $items_query = "SELECT poi.*, i.item_code, i.item_name, i.unit_type, i.stock
                        FROM purchase_order_items poi
                        JOIN items i ON poi.item_id = i.item_id
                        WHERE poi.po_id = ?
                        ORDER BY poi.po_item_id";
        
        $items_stmt = $conn->prepare($items_query);
        $items_stmt->bind_param("i", $po_id);
        $items_stmt->execute();
        $items_result = $items_stmt->get_result();
        ?>
        
        <div class="container-fluid">
            <div class="mb-3">
                <h6 class="fw-bold">PO Number: <?php echo htmlspecialchars($po['po_number']); ?></h6>
                <p class="text-muted mb-0">Supplier: <?php echo htmlspecialchars($po['supplier_name']); ?></p>
            </div>
            
            <div class="table-responsive">
                <table class="table table-sm table-hover">
                    <thead>
                        <tr>
                            <th>Item Code</th>
                            <th>Item Name</th>
                            <th>Unit</th>
                            <th class="text-center">Current Stock</th>
                            <th class="text-center">Ordered</th>
                            <th class="text-center">Received</th>
                            <th class="text-center">To Receive</th>
                            <th class="text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($item = $items_result->fetch_assoc()): 
                            $received = $item['quantity_received'] ?? 0;
                            $ordered = $item['quantity_ordered'];
                            $pending = $ordered - $received;
                            $current_stock = $item['stock'] ?? 0;
                        ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($item['item_code']); ?></strong></td>
                            <td><?php echo htmlspecialchars($item['item_name']); ?></td>
                            <td><?php echo htmlspecialchars($item['unit_type'] ?? 'Unit'); ?></td>
                            <td class="text-center"><?php echo $current_stock; ?></td>
                            <td class="text-center"><?php echo $ordered; ?></td>
                            <td class="text-center"><?php echo $received; ?></td>
                            <td class="text-center"><?php echo $pending; ?></td>
                            <td class="text-center">
                                <?php if ($pending > 0): ?>
                                <button class="btn btn-sm btn-outline-success" 
                                        onclick="updateReceivedQuantity(<?php echo $item['po_item_id']; ?>, <?php echo $received; ?>, <?php echo $ordered; ?>, <?php echo $current_stock; ?>)">
                                    <i class="bi bi-pencil"></i> Receive
                                </button>
                                <?php else: ?>
                                <span class="badge bg-success">Completed</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
            
            <hr>
            <div class="text-end">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
        
        <?php
        exit;
    } catch (Exception $e) {
        echo '<div class="alert alert-danger">Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
        exit;
    }
}

// Handle AJAX update received quantity
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    try {
        if ($_POST['action'] === 'update_received_quantity') {
            $po_item_id = (int)$_POST['po_item_id'];
            $new_quantity_received = (int)$_POST['quantity_received'];
            
            // Start transaction
            $conn->begin_transaction();
            
            // Get PO item details including current received quantity and item info
            $get_query = "SELECT poi.po_id, poi.item_id, poi.quantity_ordered, poi.quantity_received, 
                                 po.branch_id, i.stock
                          FROM purchase_order_items poi
                          JOIN purchase_orders po ON poi.po_id = po.po_id
                          JOIN items i ON poi.item_id = i.item_id
                          WHERE poi.po_item_id = ?";
            $stmt = $conn->prepare($get_query);
            $stmt->bind_param("i", $po_item_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $item = $result->fetch_assoc();
            
            if (!$item) {
                throw new Exception('Purchase order item not found');
            }
            
            // Verify branch access
            if (!$view_all_branches && $item['branch_id'] != $user_branch_id) {
                throw new Exception('You do not have permission to update this item');
            }
            
            // Validate quantity
            if ($new_quantity_received > $item['quantity_ordered']) {
                throw new Exception('Received quantity cannot exceed ordered quantity (' . $item['quantity_ordered'] . ')');
            }
            
            if ($new_quantity_received < 0) {
                throw new Exception('Received quantity cannot be negative');
            }
            
            // Calculate the additional quantity received
            $old_quantity_received = $item['quantity_received'] ?? 0;
            $additional_quantity = $new_quantity_received - $old_quantity_received;
            
            // Update received quantity in purchase_order_items
            $update_query = "UPDATE purchase_order_items SET quantity_received = ? WHERE po_item_id = ?";
            $update_stmt = $conn->prepare($update_query);
            $update_stmt->bind_param("ii", $new_quantity_received, $po_item_id);
            
            if (!$update_stmt->execute()) {
                throw new Exception('Failed to update received quantity');
            }
            
            // If additional quantity > 0, update items table stock
            if ($additional_quantity > 0) {
                // Get current stock from items table
                $current_stock = $item['stock'] ?? 0;
                $new_stock = $current_stock + $additional_quantity;
                
                // Update the items table stock
                $update_stock_query = "UPDATE items SET stock = ? WHERE item_id = ?";
                $update_stock_stmt = $conn->prepare($update_stock_query);
                $update_stock_stmt->bind_param("ii", $new_stock, $item['item_id']);
                
                if (!$update_stock_stmt->execute()) {
                    throw new Exception('Failed to update item stock');
                }
                
                // Log the stock movement (optional - if you want to track)
                // You can add this to a stock_movements table if you have one
            }
            
            // Check if all items are fully received for this PO
            $check_po_query = "SELECT SUM(CASE WHEN quantity_received >= quantity_ordered THEN 1 ELSE 0 END) as fully_received_count,
                                      COUNT(*) as total_items
                               FROM purchase_order_items
                               WHERE po_id = ?";
            $check_po_stmt = $conn->prepare($check_po_query);
            $check_po_stmt->bind_param("i", $item['po_id']);
            $check_po_stmt->execute();
            $po_status_result = $check_po_stmt->get_result();
            $po_status = $po_status_result->fetch_assoc();
            
            // Update PO status
            $po_status_value = 'pending';
            if ($po_status['fully_received_count'] == $po_status['total_items']) {
                $po_status_value = 'received';
            } elseif ($po_status['fully_received_count'] > 0) {
                $po_status_value = 'partial';
            }
            
            $update_po_query = "UPDATE purchase_orders SET po_status = ? WHERE po_id = ?";
            $update_po_stmt = $conn->prepare($update_po_query);
            $update_po_stmt->bind_param("si", $po_status_value, $item['po_id']);
            $update_po_stmt->execute();
            
            // Commit transaction
            $conn->commit();
            
            // Get updated stock for response
            $updated_stock = $current_stock + $additional_quantity;
            
            echo json_encode([
                'success' => true, 
                'message' => 'Received quantity updated successfully and stock adjusted',
                'new_stock' => $updated_stock,
                'additional_quantity' => $additional_quantity
            ]);
            exit;
        }
    } catch (Exception $e) {
        // Rollback transaction on error
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Purchase Orders - Warehouse <?php echo !$view_all_branches ? '- ' . htmlspecialchars($branch_name ?? '') : ''; ?></title>
    <link rel="icon" type="image/png" href="../Pictures/favicon-96x96.png" sizes="96x96" />
    <link rel="icon" type="image/svg+xml" href="../Pictures/favicon.svg" />
    <link rel="shortcut icon" href="../Pictures/favicon.ico" />
    <link rel="apple-touch-icon" sizes="180x180" href="../Pictures/apple-touch-icon.png" />
    <link rel="manifest" href="../Pictures/site.webmanifest" />
    <link rel="stylesheet" href="../css/warehouse.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        /* Mobile responsive adjustments */
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
        
        /* Extra small devices */
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
        }
        
        /* Branch indicator */
        .branch-indicator {
            display: inline-block;
            padding: 4px 12px;
            background-color: #e7f5ff;
            color: #0d6efd;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 500;
            margin-left: 10px;
        }

        /* Mobile Profile Modal Styles */
        .user-avatar-large {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--dark-green), var(--primary-green));
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2.5rem;
            font-weight: bold;
            margin: 0 auto;
            border: 4px solid var(--light-green);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        #profileModal .modal-content {
            border: none;
            border-radius: 20px;
            overflow: hidden;
        }

        #profileModal .modal-header {
            background: linear-gradient(135deg, var(--dark-green), var(--primary-green));
            color: white;
            border-bottom: none;
            padding: 1.5rem;
        }

        #profileModal .modal-header .modal-title {
            color: white;
            font-weight: 600;
        }

        #profileModal .modal-header .btn-close {
            filter: brightness(0) invert(1);
            opacity: 0.9;
        }

        #profileModal .modal-header .btn-close:hover {
            opacity: 1;
            transform: rotate(90deg);
        }

        #profileModal .modal-body {
            padding: 2rem;
            background: linear-gradient(135deg, #f9fefc 0%, #f0fdf4 100%);
        }

        #profileModal .branch-info {
            background: var(--light-green);
            color: var(--dark-green);
            padding: 0.5rem 1rem;
            border-radius: 50px;
            display: inline-block;
            font-weight: 500;
        }

        #profileModal .btn-danger {
            background: linear-gradient(135deg, #dc3545, #f87171);
            border: none;
            padding: 1rem;
            border-radius: 50px;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        #profileModal .btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(220, 53, 69, 0.3);
        }

        /* Mobile Logout Button in Bottom Nav */
        .mobile-nav .nav-link.logout-btn {
            color: #dc3545;
        }

        .mobile-nav .nav-link.logout-btn i {
            color: #dc3545;
        }

        .mobile-nav .nav-link.logout-btn.active,
        .mobile-nav .nav-link.logout-btn:hover {
            background: rgba(220, 53, 69, 0.1);
            color: #dc3545;
        }

        .mobile-nav .nav-link.logout-btn.active i,
        .mobile-nav .nav-link.logout-btn:hover i {
            color: #dc3545;
        }
    </style>
</head>
<body>
    <div id="appPage">
        <!-- Sidebar -->
        <div class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <h3>
                    <button class="desktop-toggle-btn" id="desktopToggleBtn">
                        <i class="bi bi-list" id="toggleIcon"></i>
                    </button>
                    <img src="../Pictures/amgc3DLogo.png" alt="Logo" class="logo-icon"> 
                    <span class="nav-text">Warehouse</span>
                </h3>
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
                        <a class="nav-link" href="pick_list_items.php">
                            <i class="bi bi-clipboard-check"></i>
                            <span class="nav-text">Pick List Items</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="purchase_order.php">
                            <i class="bi bi-receipt"></i>
                            <span class="nav-text">Purchase Orders</span>
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

        <!-- Main Content -->
        <div class="main-content">
            <!-- Header -->
            <div class="navbar-top">
                <button class="mobile-toggle-btn" id="mobileToggleBtn">
                    <i class="bi bi-list"></i>
                </button>
                <div class="page-title">
                    <h2>Purchase Orders</h2>
                    <p>View and receive items from supplier orders</p>
                </div>
            </div>

            <?php
            // Get purchase order statistics - filtered by branch and category
            // Determine branch filter condition
            $branch_condition = "";
            if ($items_branch_column_exists && !$view_all_branches) {
                $branch_condition = "AND i.branch_id = " . $user_branch_id;
            }
            
            // Determine category filter condition
            $category_condition = "";
            if (empty($user_category)) {
                // If no category assigned, show nothing
                $category_condition = "AND 1=0";
            } else {
                $category_condition = "AND i.category = '" . $conn->real_escape_string($user_category) . "'";
            }
            
            $stats = [];
            
            // Total PO items
            $total_items_query = "SELECT COUNT(*) as count 
                                 FROM purchase_order_items poi
                                 JOIN purchase_orders po ON poi.po_id = po.po_id
                                 JOIN items i ON poi.item_id = i.item_id
                                 WHERE 1=1 $branch_condition $category_condition";
            $result = $conn->query($total_items_query);
            $stats['total_items'] = $result->fetch_assoc()['count'] ?? 0;
            
            // Fully received items
            $received_query = "SELECT COUNT(*) as count 
                             FROM purchase_order_items poi
                             JOIN purchase_orders po ON poi.po_id = po.po_id
                             JOIN items i ON poi.item_id = i.item_id
                             WHERE poi.quantity_received >= poi.quantity_ordered $branch_condition $category_condition";
            $result = $conn->query($received_query);
            $stats['received'] = $result->fetch_assoc()['count'] ?? 0;
            
            // Pending items
            $pending_query = "SELECT COUNT(*) as count 
                             FROM purchase_order_items poi
                             JOIN purchase_orders po ON poi.po_id = po.po_id
                             JOIN items i ON poi.item_id = i.item_id
                             WHERE poi.quantity_received = 0 $branch_condition $category_condition";
            $result = $conn->query($pending_query);
            $stats['pending'] = $result->fetch_assoc()['count'] ?? 0;
            
            // Total PO count
            $total_po_query = "SELECT COUNT(DISTINCT po.po_id) as count 
                             FROM purchase_orders po
                             JOIN purchase_order_items poi ON po.po_id = poi.po_id
                             JOIN items i ON poi.item_id = i.item_id
                             WHERE 1=1 $branch_condition $category_condition";
            $result = $conn->query($total_po_query);
            $stats['total_pos'] = $result->fetch_assoc()['count'] ?? 0;
            ?>

            <!-- Stats Cards - Original Design -->
            <div class="row g-3 mb-4">
                <div class="col-md-3 mb-3">
                    <div class="stat-card inventory">
                        <div class="stat-icon">
                            <i class="bi bi-receipt"></i>
                        </div>
                        <div>
                            <div class="stat-value"><?php echo $stats['total_pos']; ?></div>
                            <div class="stat-label">Total Orders</div>
                        </div>
                    </div>
                </div>

                <div class="col-md-3 mb-3">
                    <div class="stat-card sales">
                        <div class="stat-icon">
                            <i class="bi bi-check-circle"></i>
                        </div>
                        <div>
                            <div class="stat-value"><?php echo $stats['received']; ?></div>
                            <div class="stat-label">Received Items</div>
                        </div>
                    </div>
                </div>

                <div class="col-md-3 mb-3">
                    <div class="stat-card pending">
                        <div class="stat-icon">
                            <i class="bi bi-hourglass-split"></i>
                        </div>
                        <div>
                            <div class="stat-value"><?php echo $stats['pending']; ?></div>
                            <div class="stat-label">Pending Items</div>
                        </div>
                    </div>
                </div>

                <div class="col-md-3 mb-3">
                    <div class="stat-card delivery">
                        <div class="stat-icon">
                            <i class="bi bi-box"></i>
                        </div>
                        <div>
                            <div class="stat-value"><?php echo $stats['total_items']; ?></div>
                            <div class="stat-label">Total Items</div>
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
                                <input type="text" class="form-control" id="searchInput" placeholder="Search PO number or supplier...">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <select class="form-select" id="statusFilter">
                                <option value="">All Status</option>
                                <option value="pending">Pending</option>
                                <option value="partial">Partial Received</option>
                                <option value="received">Received</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Purchase Orders Table -->
            <div class="card">
                <div class="table-container">
                    <table class="table custom-table compact-table">
                        <thead class="table-light">
                            <tr>
                                <th>PO Number</th>
                                <th>Supplier</th>
                                <th>PO Date</th>
                                <th>Status</th>
                                <th>Total Items</th>
                                <th>Received</th>
                                <th>Pending</th>
                                <th class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            // Get purchase orders - filtered by branch and category
                            // Build branch and category conditions for main query
                            $main_branch_condition = "";
                            if ($items_branch_column_exists && !$view_all_branches) {
                                $main_branch_condition = "AND i.branch_id = " . $user_branch_id;
                            }
                            
                            $main_category_condition = "";
                            if (empty($user_category)) {
                                // If no category assigned, show nothing
                                $main_category_condition = "AND 1=0";
                            } else {
                                $main_category_condition = "AND i.category = '" . $conn->real_escape_string($user_category) . "'";
                            }
                            
                            $po_query = "SELECT DISTINCT po.po_id, po.po_number, po.supplier_name, po.order_date, po.po_status, po.branch_id,
                                        SUM(poi.quantity_ordered) as total_ordered,
                                        SUM(poi.quantity_received) as total_received
                                        FROM purchase_orders po
                                        JOIN purchase_order_items poi ON po.po_id = poi.po_id
                                        JOIN items i ON poi.item_id = i.item_id
                                        WHERE 1=1 $main_branch_condition $main_category_condition
                                        GROUP BY po.po_id 
                                        ORDER BY po.order_date DESC";
                            
                            $po_result = $conn->query($po_query);
                            
                            if ($po_result->num_rows > 0) {
                                while ($po = $po_result->fetch_assoc()) {
                                    $total_ord = $po['total_ordered'] ?? 0;
                                    $total_rec = $po['total_received'] ?? 0;
                                    $pending = $total_ord - $total_rec;
                                    $completion = $total_ord > 0 ? ($total_rec / $total_ord) * 100 : 0;
                                    
                                    // Determine status
                                    if ($completion == 100) {
                                        $status_badge = 'success';
                                        $status_text = 'Received';
                                    } elseif ($completion > 0) {
                                        $status_badge = 'info';
                                        $status_text = 'Partial';
                                    } else {
                                        $status_badge = 'warning';
                                        $status_text = 'Pending';
                                    }
                                    ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($po['po_number']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($po['supplier_name']); ?></td>
                                        <td><?php echo date('M d, Y', strtotime($po['order_date'])); ?></td>
                                        <td><span class="badge bg-<?php echo $status_badge; ?>"><?php echo $status_text; ?></span></td>
                                        <td><?php echo $total_ord; ?></td>
                                        <td><?php echo $total_rec; ?></td>
                                        <td><?php echo $pending; ?></td>
                                        <td class="text-center">
                                            <div class="action-buttons">
                                                <button class="btn-action btn-view" onclick="showPODetails(<?php echo $po['po_id']; ?>)" title="View Details">
                                                    <i class="bi bi-eye"></i>
                                                </button>
                                                <?php if ($status_text != 'Received'): ?>
                                                <button class="btn-action btn-receive" onclick="openReceiveModal(<?php echo $po['po_id']; ?>)" title="Receive Items">
                                                    <i class="bi bi-box-seam"></i>
                                                </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php
                                }
                            } else {
                                echo '<tr><td colspan="9" class="text-center py-5 text-muted">';
                                echo '<i class="bi bi-inbox fs-1 d-block mb-3"></i>';
                                echo '<p>No purchase orders found</p>';
                                echo '</td></tr>';
                            }
                            $po_stmt->close();
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Mobile Bottom Navigation -->
    <div class="mobile-nav" id="mobileNav">
        <ul class="nav">
            <li class="nav-item">
                <a class="nav-link" href="warehouse.php">
                    <i class="bi bi-speedometer2"></i>
                    <span>Dashboard</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="currentinventory.php">
                    <i class="bi bi-boxes"></i>
                    <span>Inventory</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="pick_list_items.php">
                    <i class="bi bi-clipboard-check"></i>
                    <span>Pick Lists</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link active" href="purchase_order.php">
                    <i class="bi bi-receipt"></i>
                    <span>PO</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="drivers.php">
                    <i class="bi bi-person-badge"></i>
                    <span>Drivers</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link logout-btn" href="#" onclick="showProfileModal(); return false;">
                    <i class="bi bi-box-arrow-right"></i>
                    <span>Logout</span>
                </a>
            </li>
        </ul>
    </div>

    <!-- Mobile Profile/Logout Modal -->
    <div class="modal fade" id="profileModal" tabindex="-1" aria-labelledby="profileModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="profileModalLabel">
                        <i class="bi bi-person-circle me-2"></i>User Profile
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body text-center">
                    <!-- User Avatar -->
                    <div class="user-avatar-large mb-3">
                        <?php echo substr($user_name, 0, 2); ?>
                    </div>
                    
                    <!-- User Name -->
                    <h4 class="mb-1"><?php echo htmlspecialchars($user_name); ?></h4>
                    
                    <!-- User Role -->
                    <p class="text-muted mb-3">
                        <span class="badge bg-success"><?php echo ucfirst($user_role); ?></span>
                    </p>
                    
                    <!-- Branch Info (if applicable) -->
                    <?php if (!$view_all_branches && $user_branch_id > 0): ?>
                    <div class="branch-info mb-3">
                        <i class="bi bi-building me-1"></i>
                        <span><?php echo htmlspecialchars($branch_name); ?></span>
                    </div>
                    <?php endif; ?>
                    
                    <!-- User ID -->
                    <div class="user-id text-muted small mb-4">
                        <i class="bi bi-hash"></i> User ID: <?php echo $user_id; ?>
                    </div>
                    
                    <!-- Logout Button -->
                    <button class="btn btn-danger btn-lg w-100" onclick="confirmLogout()">
                        <i class="bi bi-box-arrow-right me-2"></i>Logout
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- PO Details Modal -->
    <div class="modal fade" id="poDetailsModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Purchase Order Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="poModalBody">
                    <div class="text-center py-4">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                        <p class="mt-2">Loading PO details...</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Receive Items Modal -->
    <div class="modal fade" id="receiveModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Receive Items</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="receiveModalBody">
                    <div class="text-center py-4">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                        <p class="mt-2">Loading items...</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Update Received Quantity Modal -->
    <div class="modal fade" id="updateQuantityModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Update Received Quantity</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label"><strong>Quantity Received:</strong></label>
                        <input type="number" id="quantityInput" class="form-control" min="0" placeholder="Enter quantity received">
                        <small id="quantityHelp" class="form-text text-muted mt-2"></small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="submitUpdateQuantity()">Update</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let currentPoItemId = null;
        let currentMaxQuantity = 0;

        // ================= SIDEBAR FUNCTIONS =================
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const isMobile = window.innerWidth <= 992;
            
            if (isMobile) {
                sidebar.classList.toggle('active');
                
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
                sidebar.classList.toggle('collapsed');
                localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
                
                document.querySelectorAll('.nav-text').forEach(text => {
                    text.style.display = sidebar.classList.contains('collapsed') ? 'none' : 'inline-block';
                });
                
                const mainContent = document.querySelector('.main-content');
                if (mainContent) {
                    mainContent.style.marginLeft = sidebar.classList.contains('collapsed') ? '80px' : '250px';
                }
            }
        }

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

        function initializeSidebar() {
            const sidebar = document.getElementById('sidebar');
            
            if (window.innerWidth > 992) {
                const savedCollapsed = localStorage.getItem('sidebarCollapsed');
                if (savedCollapsed === 'true') {
                    sidebar.classList.add('collapsed');
                    document.querySelectorAll('.nav-text').forEach(text => {
                        text.style.display = 'none';
                    });
                    
                    const mainContent = document.querySelector('.main-content');
                    if (mainContent) {
                        mainContent.style.marginLeft = '80px';
                    }
                } else {
                    sidebar.classList.remove('collapsed');
                    document.querySelectorAll('.nav-text').forEach(text => {
                        text.style.display = 'inline-block';
                    });
                    
                    const mainContent = document.querySelector('.main-content');
                    if (mainContent) {
                        mainContent.style.marginLeft = '250px';
                    }
                }
            } else {
                sidebar.classList.remove('active');
                sidebar.classList.remove('collapsed');
                document.querySelectorAll('.nav-text').forEach(text => {
                    text.style.display = 'inline-block';
                });
                
                const mainContent = document.querySelector('.main-content');
                if (mainContent) {
                    mainContent.style.marginLeft = '0';
                }
            }
        }

        function handleSidebarResize() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.querySelector('.sidebar-overlay');
            
            if (window.innerWidth > 992) {
                if (overlay) {
                    overlay.remove();
                }
                sidebar.classList.remove('active');
                
                const savedCollapsed = localStorage.getItem('sidebarCollapsed');
                if (savedCollapsed === 'true') {
                    sidebar.classList.add('collapsed');
                    document.querySelectorAll('.nav-text').forEach(text => {
                        text.style.display = 'none';
                    });
                    
                    const mainContent = document.querySelector('.main-content');
                    if (mainContent) {
                        mainContent.style.marginLeft = '80px';
                    }
                } else {
                    sidebar.classList.remove('collapsed');
                    document.querySelectorAll('.nav-text').forEach(text => {
                        text.style.display = 'inline-block';
                    });
                    
                    const mainContent = document.querySelector('.main-content');
                    if (mainContent) {
                        mainContent.style.marginLeft = '250px';
                    }
                }
            } else {
                sidebar.classList.remove('collapsed');
                document.querySelectorAll('.nav-text').forEach(text => {
                    text.style.display = 'inline-block';
                });
                
                const mainContent = document.querySelector('.main-content');
                if (mainContent) {
                    mainContent.style.marginLeft = '0';
                }
            }
        }

        // ================= MOBILE NAVIGATION FUNCTIONS =================
        function initMobileNav() {
            const mobileNav = document.getElementById('mobileNav');
            const isMobile = window.innerWidth <= 992;
            
            if (isMobile) {
                mobileNav.style.display = 'block';
                
                // Set active state based on current page (excluding logout)
                const currentPage = window.location.pathname.split('/').pop();
                const navLinks = mobileNav.querySelectorAll('.nav-link:not(.logout-btn)');
                
                navLinks.forEach(link => {
                    link.classList.remove('active');
                    const href = link.getAttribute('href');
                    if (currentPage === href) {
                        link.classList.add('active');
                    }
                });
            } else {
                mobileNav.style.display = 'none';
            }
        }

        // ================= PROFILE/LOGOUT FUNCTIONS =================
        function showProfileModal() {
            const profileModal = new bootstrap.Modal(document.getElementById('profileModal'));
            profileModal.show();
        }

        function confirmLogout() {
            // Close the modal first
            const modal = bootstrap.Modal.getInstance(document.getElementById('profileModal'));
            if (modal) {
                modal.hide();
            }
            
            // Show confirmation dialog
            Swal.fire({
                title: 'Are you sure?',
                text: 'You will be logged out of the system',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#dc3545',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Yes, logout'
            }).then((result) => {
                if (result.isConfirmed) {
                    localStorage.removeItem('sidebarCollapsed');
                    window.location.href = '../logout.php';
                }
            });
        }

        // Original logout function for sidebar
        function logout() {
            Swal.fire({
                title: 'Are you sure?',
                text: 'You will be logged out of the system',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#dc3545',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Yes, logout'
            }).then((result) => {
                if (result.isConfirmed) {
                    localStorage.removeItem('sidebarCollapsed');
                    window.location.href = '../logout.php';
                }
            });
        }

        // ================= PURCHASE ORDER FUNCTIONS =================
        function showPODetails(poId) {
            const modal = new bootstrap.Modal(document.getElementById('poDetailsModal'));
            const modalBody = document.getElementById('poModalBody');
            
            modalBody.innerHTML = '<div class="text-center py-4"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div><p class="mt-2">Loading PO details...</p></div>';
            
            fetch(`purchase_order.php?load_po_details=${poId}`)
                .then(response => response.text())
                .then(html => {
                    modalBody.innerHTML = html;
                })
                .catch(error => {
                    console.error('Error loading PO details:', error);
                    modalBody.innerHTML = '<div class="alert alert-danger">Error loading PO details. Please try again.</div>';
                });
            
            modal.show();
        }

        function openReceiveModal(poId) {
            const modal = new bootstrap.Modal(document.getElementById('receiveModal'));
            const modalBody = document.getElementById('receiveModalBody');
            
            modalBody.innerHTML = '<div class="text-center py-4"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div><p class="mt-2">Loading items...</p></div>';
            
            fetch(`purchase_order.php?load_receive_items=${poId}`)
                .then(response => response.text())
                .then(html => {
                    modalBody.innerHTML = html;
                })
                .catch(error => {
                    console.error('Error loading receive items:', error);
                    modalBody.innerHTML = '<div class="alert alert-danger">Error loading items. Please try again.</div>';
                });
            
            modal.show();
        }

        function updateReceivedQuantity(poItemId, currentQty, maxQty, currentStock) {
            currentPoItemId = poItemId;
            currentMaxQuantity = maxQty;
            
            const input = document.getElementById('quantityInput');
            input.value = currentQty;
            input.max = maxQty;
            
            document.getElementById('quantityHelp').textContent = `Maximum: ${maxQty} units | Current Stock: ${currentStock}`;
            
            // Close receive modal if open
            const receiveModal = bootstrap.Modal.getInstance(document.getElementById('receiveModal'));
            if (receiveModal) {
                receiveModal.hide();
            }
            
            const modal = new bootstrap.Modal(document.getElementById('updateQuantityModal'));
            modal.show();
        }

        function submitUpdateQuantity() {
            const quantity = parseInt(document.getElementById('quantityInput').value);
            
            if (isNaN(quantity) || quantity < 0) {
                Swal.fire('Warning', 'Please enter a valid quantity', 'warning');
                return;
            }
            
            if (quantity > currentMaxQuantity) {
                Swal.fire('Warning', `Quantity cannot exceed ${currentMaxQuantity} units`, 'warning');
                return;
            }
            
            const formData = new FormData();
            formData.append('action', 'update_received_quantity');
            formData.append('po_item_id', currentPoItemId);
            formData.append('quantity_received', quantity);
            
            Swal.fire({
                title: 'Updating...',
                text: 'Please wait',
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });
            
            fetch('purchase_order.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    bootstrap.Modal.getInstance(document.getElementById('updateQuantityModal')).hide();
                    Swal.fire({
                        icon: 'success',
                        title: 'Success!',
                        text: 'Received quantity updated and stock adjusted successfully!',
                        timer: 1500,
                        showConfirmButton: false
                    }).then(() => {
                        location.reload();
                    });
                } else {
                    Swal.fire('Error', data.message || 'Unknown error occurred', 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                Swal.fire('Error', 'An error occurred. Please try again.', 'error');
            });
        }

        // Search and filter functionality
        function filterTable() {
            const searchTerm = document.getElementById('searchInput').value.toLowerCase();
            const statusFilter = document.getElementById('statusFilter').value;
            const rows = document.querySelectorAll('tbody tr');
            
            rows.forEach(row => {
                if (row.querySelector('td[colspan]')) return;
                
                const text = row.innerText.toLowerCase();
                const status = row.querySelector('td:nth-child(4) .badge').innerText.toLowerCase();
                
                let show = text.includes(searchTerm);
                if (statusFilter) {
                    show = show && status.includes(statusFilter.toLowerCase());
                }
                
                row.style.display = show ? '' : 'none';
            });
        }

        // ================= INITIALIZATION =================
        document.addEventListener('DOMContentLoaded', function() {
            console.log("Purchase Orders page loaded!");
            
            initializeSidebar();
            initMobileNav();
            
            const mobileToggleBtn = document.getElementById('mobileToggleBtn');
            const desktopToggleBtn = document.getElementById('desktopToggleBtn');
            
            if (mobileToggleBtn) {
                mobileToggleBtn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    toggleSidebar();
                });
            }
            
            if (desktopToggleBtn) {
                desktopToggleBtn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    toggleSidebar();
                });
            }
            
            // Close sidebar when clicking outside on mobile
            document.addEventListener('click', function(event) {
                const sidebar = document.getElementById('sidebar');
                const mobileBtn = document.getElementById('mobileToggleBtn');
                const overlay = document.querySelector('.sidebar-overlay');
                const isMobile = window.innerWidth <= 992;
                
                if (isMobile && sidebar && sidebar.classList.contains('active') && 
                    !sidebar.contains(event.target) && 
                    (!mobileBtn || !mobileBtn.contains(event.target)) &&
                    (!overlay || !overlay.contains(event.target))) {
                    closeMobileSidebar();
                }
            });

            window.addEventListener('resize', function() {
                handleSidebarResize();
                initMobileNav();
            });

            // Search and filter event listeners
            document.getElementById('searchInput').addEventListener('keyup', filterTable);
            document.getElementById('statusFilter').addEventListener('change', filterTable);
        });

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey && e.key === 'b' && window.innerWidth > 992) {
                e.preventDefault();
                toggleSidebar();
            }
            else if (e.key === 'Escape' && window.innerWidth <= 992) {
                closeMobileSidebar();
            }
            else if (e.key === 'Escape') {
                const profileModal = document.getElementById('profileModal');
                if (profileModal.classList.contains('show')) {
                    bootstrap.Modal.getInstance(profileModal).hide();
                }
            }
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
<?php $conn->close(); ?>
