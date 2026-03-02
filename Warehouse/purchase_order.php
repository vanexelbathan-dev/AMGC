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
        $items_query = "SELECT poi.*, i.item_code, i.item_name, i.unit_type
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
        <!-- Item Information Section -->
        <div class="row">
            <div class="col-md-6">
                <div class="card mb-3">
                    <div class="card-header">
                        <h6 class="mb-0"><i class="bi bi-file-earmark me-2"></i>PO Information</h6>
                    </div>
                    <div class="card-body py-2">
                        <table class="table table-sm table-borderless mb-0">
                            <tr>
                                <td width="40%"><strong>PO Number:</strong></td>
                                <td><span class="badge bg-light text-dark"><?php echo htmlspecialchars($po['po_number']); ?></span></td>
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
                                <td><strong style="color: var(--primary-green);"><?php echo $total_received; ?></strong> units</td>
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

        <!-- Progress Section -->
        <div class="row">
            <div class="col-12">
                <div class="card mb-3">
                    <div class="card-header">
                        <h6 class="mb-0"><i class="bi bi-graph-up me-2"></i>Receiving Progress</h6>
                    </div>
                    <div class="card-body">
                        <div class="progress mb-3" style="height: 25px;">
                            <div class="progress-bar bg-<?php echo $status_badge; ?> progress-bar-striped" 
                                 role="progressbar" 
                                 style="width: <?php echo $completion_percentage; ?>%; background: linear-gradient(135deg, var(--primary-green), var(--light-green)) !important;"
                                 aria-valuenow="<?php echo round($completion_percentage); ?>" 
                                 aria-valuemin="0" 
                                 aria-valuemax="100">
                                <?php echo round($completion_percentage, 1); ?>%
                            </div>
                        </div>
                        
                        <div class="row g-2 justify-content-center">
                            <div class="col-6 col-md-5">
                                <div class="progress-stat-card">
                                    <div class="d-flex flex-column align-items-center">
                                        <span class="progress-stat-label">Received</span>
                                        <span class="progress-stat-value" style="color: var(--primary-green);"><?php echo number_format($total_received); ?></span>
                                        <small class="text-muted">units</small>
                                    </div>
                                </div>
                            </div>
                            <div class="col-6 col-md-5">
                                <div class="progress-stat-card">
                                    <div class="d-flex flex-column align-items-center">
                                        <span class="progress-stat-label">Total</span>
                                        <span class="progress-stat-value"><?php echo number_format($total_ordered); ?></span>
                                        <small class="text-muted">units</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="text-center mt-3">
                            <span class="badge bg-<?php echo $status_badge; ?> p-2" style="font-size: 0.9rem;">
                                <i class="bi bi-check-circle me-1"></i> <?php echo round($completion_percentage, 1); ?>% Received
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- PO Items Section -->
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h6 class="mb-0"><i class="bi bi-list-check me-2"></i>Purchase Order Items</h6>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover table-sm mb-0">
                            <thead style="background-color: #f8f9fa;">
                                <tr>
                                    <th>Item Code</th>
                                    <th>Item Name</th>
                                    <th>Unit</th>
                                    <th>Ordered</th>
                                    <th>Received</th>
                                    <th>Pending</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($items as $item): 
                                    $received = $item['quantity_received'] ?? 0;
                                    $ordered = $item['quantity_ordered'];
                                    $pending = $ordered - $received;
                                    $item_status = $received >= $ordered ? 'success' : ($received > 0 ? 'info' : 'warning');
                                ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($item['item_code']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($item['item_name']); ?></td>
                                    <td><small><?php echo htmlspecialchars($item['unit_type'] ?? 'Unit'); ?></small></td>
                                    <td><?php echo $ordered; ?></td>
                                    <td><span style="color: var(--primary-green); font-weight: bold;"><?php echo $received; ?></span></td>
                                    <td><?php echo $pending; ?></td>
                                    <td>
                                        <button class="btn btn-sm btn-outline-success" 
                                                onclick="updateReceivedQuantity(<?php echo $item['po_item_id']; ?>, <?php echo $received; ?>, <?php echo $ordered; ?>)">
                                            <i class="bi bi-pencil"></i> Update
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
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

// Handle AJAX update received quantity
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    try {
        if ($_POST['action'] === 'update_received_quantity') {
            $po_item_id = (int)$_POST['po_item_id'];
            $quantity_received = (int)$_POST['quantity_received'];
            
            // Get PO item details
            $get_query = "SELECT poi.po_id, poi.quantity_ordered, poi.quantity_received, po.branch_id
                          FROM purchase_order_items poi
                          JOIN purchase_orders po ON poi.po_id = po.po_id
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
            if ($quantity_received > $item['quantity_ordered']) {
                throw new Exception('Received quantity cannot exceed ordered quantity (' . $item['quantity_ordered'] . ')');
            }
            
            // Update received quantity
            $update_query = "UPDATE purchase_order_items SET quantity_received = ? WHERE po_item_id = ?";
            $update_stmt = $conn->prepare($update_query);
            $update_stmt->bind_param("ii", $quantity_received, $po_item_id);
            
            if (!$update_stmt->execute()) {
                throw new Exception('Failed to update received quantity');
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
            
            // Update PO status if all items received
            if ($po_status['fully_received_count'] == $po_status['total_items']) {
                $update_po_query = "UPDATE purchase_orders SET po_status = 'received' WHERE po_id = ?";
                $update_po_stmt = $conn->prepare($update_po_query);
                $update_po_stmt->bind_param("i", $item['po_id']);
                $update_po_stmt->execute();
            }
            
            echo json_encode(['success' => true, 'message' => 'Received quantity updated successfully']);
            exit;
        }
    } catch (Exception $e) {
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
    <title>Warehouse - Purchase Orders</title>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <!-- Custom CSS -->
    <link href="../css/warehouse.css" rel="stylesheet">
    
    <style>
        :root {
            --primary-green: #44D34E;
            --light-green: #B0EB9F;
            --dark-green: #048964;
            --dark-text: #2C3E50;
            --light-text: #7F8C8D;
        }

        .progress-stat-card {
            background: white;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            padding: 15px;
            text-align: center;
        }

        .progress-stat-label {
            font-size: 0.85rem;
            color: var(--light-text);
            font-weight: 600;
        }

        .progress-stat-value {
            font-size: 1.5rem;
            font-weight: bold;
            color: var(--dark-green);
            margin: 5px 0;
        }

        .card {
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            border: 1px solid rgba(0,0,0,0.08);
        }

        .card-header {
            background-color: #f8f9fa;
            border-bottom: 1px solid #dee2e6;
            padding: 1rem;
        }

        .btn-outline-success {
            color: var(--primary-green);
            border-color: var(--primary-green);
        }

        .btn-outline-success:hover {
            background-color: var(--primary-green);
            border-color: var(--primary-green);
            color: white;
        }
    </style>
</head>
<body>
    <!-- MAIN APPLICATION -->
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
                            <i class="bi bi-file-earmark"></i>
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
            <!-- Header Section -->
            <div class="navbar-top">
                <button class="mobile-toggle-btn" id="mobileToggleBtn">
                    <i class="bi bi-list"></i>
                </button>
                <div class="page-title">
                    <h2>
                        Purchase Orders
                    </h2>
                    <p>Receive and track purchase order items</p>
                </div>
            </div>

            <?php
            // Get purchase order statistics - filtered by branch
            $stats = [];
            $branch_filter = "";
            $params = [];
            $types = "";
            
            if (!$view_all_branches && $user_branch_id > 0) {
                $branch_filter = " WHERE po.branch_id = ? ";
                $params[] = $user_branch_id;
                $types .= "i";
            }
            
            // Total PO items
            $total_items_query = "SELECT COUNT(*) as count 
                                 FROM purchase_order_items poi
                                 JOIN purchase_orders po ON poi.po_id = po.po_id" . $branch_filter;
            $stmt = $conn->prepare($total_items_query);
            if (!empty($params)) {
                $stmt->bind_param($types, ...$params);
            }
            $stmt->execute();
            $result = $stmt->get_result();
            $stats['total_items'] = $result->fetch_assoc()['count'] ?? 0;
            $stmt->close();
            
            // Fully received items
            $received_query = "SELECT COUNT(*) as count 
                             FROM purchase_order_items poi
                             JOIN purchase_orders po ON poi.po_id = po.po_id
                             WHERE poi.quantity_received >= poi.quantity_ordered" . 
                             ($branch_filter ? str_replace("WHERE", "AND", $branch_filter) : "");
            $stmt = $conn->prepare($received_query);
            if (!empty($params)) {
                $stmt->bind_param($types, ...$params);
            }
            $stmt->execute();
            $result = $stmt->get_result();
            $stats['received'] = $result->fetch_assoc()['count'] ?? 0;
            $stmt->close();
            
            // Pending items
            $pending_query = "SELECT COUNT(*) as count 
                             FROM purchase_order_items poi
                             JOIN purchase_orders po ON poi.po_id = po.po_id
                             WHERE poi.quantity_received = 0" . 
                             ($branch_filter ? str_replace("WHERE", "AND", $branch_filter) : "");
            $stmt = $conn->prepare($pending_query);
            if (!empty($params)) {
                $stmt->bind_param($types, ...$params);
            }
            $stmt->execute();
            $result = $stmt->get_result();
            $stats['pending'] = $result->fetch_assoc()['count'] ?? 0;
            $stmt->close();
            
            // Total PO count
            $total_po_query = "SELECT COUNT(DISTINCT po.po_id) as count 
                             FROM purchase_orders po" . $branch_filter;
            $stmt = $conn->prepare($total_po_query);
            if (!empty($params)) {
                $stmt->bind_param($types, ...$params);
            }
            $stmt->execute();
            $result = $stmt->get_result();
            $stats['total_pos'] = $result->fetch_assoc()['count'] ?? 0;
            $stmt->close();
            ?>

            <!-- Stats Cards -->
            <div class="row g-3 mb-4">
                <div class="col-md-3 mb-3">
                    <div class="stat-card inventory">
                        <div class="stat-icon">
                            <i class="bi bi-file-earmark"></i>
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
                                <option value="received">Received</option>
                                <option value="partial">Partial Received</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Purchase Orders Table -->
            <div class="card">
                <div class="table-responsive">
                    <table class="table table-hover table-sm mb-0" id="poTable">
                        <thead style="background-color: #f8f9fa;">
                            <tr>
                                <th>PO Number</th>
                                <th>Supplier</th>
                                <th>PO Date</th>
                                <th>Status</th>
                                <th>Total Items</th>
                                <th>Received</th>
                                <th>Pending</th>
                                <th>Progress</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            // Get purchase orders
                            $po_query = "SELECT DISTINCT po.po_id, po.po_number, po.supplier_name, po.order_date, po.po_status, po.branch_id,
                                        SUM(poi.quantity_ordered) as total_ordered,
                                        SUM(poi.quantity_received) as total_received
                                        FROM purchase_orders po
                                        JOIN purchase_order_items poi ON po.po_id = poi.po_id" . 
                                        $branch_filter . 
                                        " GROUP BY po.po_id 
                                        ORDER BY po.order_date DESC";
                            
                            $po_stmt = $conn->prepare($po_query);
                            if (!empty($params)) {
                                $po_stmt->bind_param($types, ...$params);
                            }
                            $po_stmt->execute();
                            $po_result = $po_stmt->get_result();
                            
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
                                        <td><span style="color: var(--primary-green); font-weight: bold;"><?php echo $total_rec; ?></span></td>
                                        <td><?php echo $pending; ?></td>
                                        <td>
                                            <div style="width: 100px;">
                                                <div class="progress" style="height: 20px;">
                                                    <div class="progress-bar" style="width: <?php echo $completion; ?>%; background: var(--primary-green);"></div>
                                                </div>
                                                <small class="text-muted"><?php echo round($completion, 0); ?>%</small>
                                            </div>
                                        </td>
                                        <td>
                                            <button class="btn btn-sm btn-outline-success" onclick="showPODetails(<?php echo $po['po_id']; ?>)">
                                                <i class="bi bi-eye"></i> View
                                            </button>
                                        </td>
                                    </tr>
                                    <?php
                                }
                            } else {
                                echo '<tr><td colspan="9" class="text-center py-4 text-muted">No purchase orders found</td></tr>';
                            }
                            $po_stmt->close();
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- PO Details Modal -->
    <div class="modal fade" id="poDetailsModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Purchase Order Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="poModalBody" style="max-height: 80vh; overflow-y: auto;">
                    <div class="text-center py-4">
                        <div class="spinner-border text-success" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
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
                    <button type="button" class="btn btn-success" onclick="submitUpdateQuantity()">Update</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../js/sidebar.js"></script>
    
    <script>
        let currentPoItemId = null;
        let currentMaxQuantity = 0;

        function logout() {
            if (confirm('Are you sure you want to logout?')) {
                window.location.href = '../logout.php';
            }
        }

        function showPODetails(poId) {
            const modal = new bootstrap.Modal(document.getElementById('poDetailsModal'));
            const modalBody = document.getElementById('poModalBody');
            
            fetch(`purchase_order.php?load_po_details=${poId}`)
                .then(response => response.text())
                .then(html => {
                    modalBody.innerHTML = html;
                })
                .catch(error => {
                    console.error('[v0] Error loading PO details:', error);
                    modalBody.innerHTML = '<div class="alert alert-danger"><i class="bi bi-exclamation-triangle"></i> Error loading PO details. Please try again.</div>';
                });
            
            modal.show();
        }

        function updateReceivedQuantity(poItemId, currentQty, maxQty) {
            currentPoItemId = poItemId;
            currentMaxQuantity = maxQty;
            
            const input = document.getElementById('quantityInput');
            input.value = currentQty;
            input.max = maxQty;
            
            document.getElementById('quantityHelp').textContent = `Maximum: ${maxQty} units`;
            
            const modal = new bootstrap.Modal(document.getElementById('updateQuantityModal'));
            modal.show();
        }

        function submitUpdateQuantity() {
            const quantity = parseInt(document.getElementById('quantityInput').value);
            
            if (isNaN(quantity) || quantity < 0) {
                alert('Please enter a valid quantity');
                return;
            }
            
            if (quantity > currentMaxQuantity) {
                alert(`Quantity cannot exceed ${currentMaxQuantity} units`);
                return;
            }
            
            const formData = new FormData();
            formData.append('action', 'update_received_quantity');
            formData.append('po_item_id', currentPoItemId);
            formData.append('quantity_received', quantity);
            
            fetch('purchase_order.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    bootstrap.Modal.getInstance(document.getElementById('updateQuantityModal')).hide();
                    alert('Received quantity updated successfully!');
                    location.reload();
                } else {
                    alert('Error: ' + data.message);
                }
            })
            .catch(error => {
                console.error('[v0] Error:', error);
                alert('An error occurred. Please try again.');
            });
        }

        // Search and filter functionality
        document.getElementById('searchInput').addEventListener('keyup', function() {
            filterTable();
        });

        document.getElementById('statusFilter').addEventListener('change', function() {
            filterTable();
        });

        function filterTable() {
            const searchTerm = document.getElementById('searchInput').value.toLowerCase();
            const statusFilter = document.getElementById('statusFilter').value;
            const rows = document.querySelectorAll('#poTable tbody tr');
            
            rows.forEach(row => {
                const text = row.innerText.toLowerCase();
                const status = row.querySelector('td:nth-child(4)').innerText.toLowerCase();
                
                let show = text.includes(searchTerm);
                if (statusFilter) {
                    show = show && status.includes(statusFilter.toLowerCase());
                }
                
                row.style.display = show ? '' : 'none';
            });
        }
    </script>
</body>
</html>
<?php $conn->close(); ?>
