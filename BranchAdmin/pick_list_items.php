<?php
require_once '../config/database.php';

// ========== HANDLE AJAX REQUESTS ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    try {
        $conn->begin_transaction();
        
        if ($_POST['action'] === 'save_pick_item') {
            // Validate required fields
            $so_id = $_POST['so_id'];
            $item_id = $_POST['item_id'];
            $quantity_to_pick = $_POST['quantity_to_pick'];
            $location_bin = $_POST['location_bin'];
            $encoded_by = $_POST['encoded_by'];
            $driver_id = !empty($_POST['driver_id']) ? $_POST['driver_id'] : null;
            
            if (empty($so_id) || empty($item_id) || empty($quantity_to_pick) || empty($location_bin)) {
                throw new Exception('All fields are required');
            }
            
            // Check if enough stock is available
            $check_stock_query = "SELECT stock FROM items WHERE item_id = ? FOR UPDATE";
            $check_stock_stmt = $conn->prepare($check_stock_query);
            $check_stock_stmt->bind_param("i", $item_id);
            $check_stock_stmt->execute();
            $stock_result = $check_stock_stmt->get_result();
            $item_data = $stock_result->fetch_assoc();
            
            if (!$item_data || $item_data['stock'] < $quantity_to_pick) {
                throw new Exception('Insufficient stock available. Current stock: ' . ($item_data['stock'] ?? 0));
            }
            
            // Get branch_id from user's branch or default to 1
            $branch_id = 1; // Default branch
            
            // Generate pick list number
            $pick_list_number = 'PL-' . date('Ymd') . '-' . rand(100000, 999999);
            
            // Check if pick list exists for this SO
            $check_pl_query = "SELECT pick_list_id FROM pick_lists WHERE so_id = ? AND pick_status IN ('open', 'in-progress')";
            $check_pl_stmt = $conn->prepare($check_pl_query);
            $check_pl_stmt->bind_param("i", $so_id);
            $check_pl_stmt->execute();
            $pl_result = $check_pl_stmt->get_result();
            
            if ($pl_result->num_rows > 0) {
                $pick_list = $pl_result->fetch_assoc();
                $pick_list_id = $pick_list['pick_list_id'];
                
                // Update driver assignment if provided
                if ($driver_id) {
                    $update_driver_query = "UPDATE pick_lists SET driver_id = ? WHERE pick_list_id = ?";
                    $update_driver_stmt = $conn->prepare($update_driver_query);
                    $update_driver_stmt->bind_param("ii", $driver_id, $pick_list_id);
                    $update_driver_stmt->execute();
                }
            } else {
                // Create new pick list with driver assignment
                $create_pl_query = "INSERT INTO pick_lists (pick_list_number, so_id, branch_id, driver_id, pick_status, pick_date, created_at) 
                                   VALUES (?, ?, ?, ?, 'open', NOW(), NOW())";
                $create_pl_stmt = $conn->prepare($create_pl_query);
                $create_pl_stmt->bind_param("siii", $pick_list_number, $so_id, $branch_id, $driver_id);
                
                if (!$create_pl_stmt->execute()) {
                    throw new Exception('Failed to create pick list');
                }
                
                $pick_list_id = $conn->insert_id;
            }
            
            // Check if item already exists in pick list
            $check_item_query = "SELECT pick_item_id, quantity_to_pick FROM pick_list_items 
                                WHERE pick_list_id = ? AND item_id = ?";
            $check_item_stmt = $conn->prepare($check_item_query);
            $check_item_stmt->bind_param("ii", $pick_list_id, $item_id);
            $check_item_stmt->execute();
            $item_result = $check_item_stmt->get_result();
            
            if ($item_result->num_rows > 0) {
                // Update existing item
                $existing = $item_result->fetch_assoc();
                $new_quantity = $existing['quantity_to_pick'] + $quantity_to_pick;
                
                // Check stock again for the additional quantity
                if ($item_data['stock'] < $new_quantity) {
                    throw new Exception('Insufficient stock for additional quantity. Current stock: ' . $item_data['stock']);
                }
                
                $update_query = "UPDATE pick_list_items 
                                SET quantity_to_pick = ?, location_bin = ? 
                                WHERE pick_item_id = ?";
                $update_stmt = $conn->prepare($update_query);
                $update_stmt->bind_param("isi", $new_quantity, $location_bin, $existing['pick_item_id']);
                
                if (!$update_stmt->execute()) {
                    throw new Exception('Failed to update pick list item');
                }
                
                $pick_item_id = $existing['pick_item_id'];
            } else {
                // Insert new pick list item
                $insert_query = "INSERT INTO pick_list_items (pick_list_id, item_id, quantity_to_pick, location_bin) 
                               VALUES (?, ?, ?, ?)";
                $insert_stmt = $conn->prepare($insert_query);
                $insert_stmt->bind_param("iiis", $pick_list_id, $item_id, $quantity_to_pick, $location_bin);
                
                if (!$insert_stmt->execute()) {
                    throw new Exception('Failed to add pick list item');
                }
                
                $pick_item_id = $conn->insert_id;
            }
            
            // Update items table - decrease stock
            $update_stock_query = "UPDATE items SET stock = stock - ? WHERE item_id = ? AND stock >= ?";
            $update_stock_stmt = $conn->prepare($update_stock_query);
            $update_stock_stmt->bind_param("iii", $quantity_to_pick, $item_id, $quantity_to_pick);
            
            if (!$update_stock_stmt->execute() || $update_stock_stmt->affected_rows === 0) {
                throw new Exception('Failed to update item stock');
            }
            
            // Update sales order status to 'processing'
            $update_so_query = "UPDATE sales_orders 
                               SET order_status = 'processing', updated_at = NOW() 
                               WHERE so_id = ? AND order_status NOT IN ('delivered', 'cancelled')";
            $update_so_stmt = $conn->prepare($update_so_query);
            $update_so_stmt->bind_param("i", $so_id);
            $update_so_stmt->execute();
            
            $conn->commit();
            
            // Get driver name for response
            $driver_name = null;
            if ($driver_id) {
                $driver_query = "SELECT driver_name FROM drivers WHERE driver_id = ?";
                $driver_stmt = $conn->prepare($driver_query);
                $driver_stmt->bind_param("i", $driver_id);
                $driver_stmt->execute();
                $driver_result = $driver_stmt->get_result();
                $driver_data = $driver_result->fetch_assoc();
                $driver_name = $driver_data['driver_name'] ?? null;
            }
            
            echo json_encode([
                'success' => true,
                'message' => 'Item added to pick list successfully. Stock updated.',
                'pick_item_id' => $pick_item_id,
                'pick_list_id' => $pick_list_id,
                'remaining_stock' => $item_data['stock'] - $quantity_to_pick,
                'driver_id' => $driver_id,
                'driver_name' => $driver_name
            ]);
            exit;
        }
        elseif ($_POST['action'] === 'delete_pick_item') {
            $pick_item_id = $_POST['pick_item_id'];
            
            // Get item details before deletion
            $get_item_query = "SELECT pli.*, pl.so_id, pl.pick_list_id, i.item_id, i.stock as current_stock 
                              FROM pick_list_items pli 
                              JOIN pick_lists pl ON pli.pick_list_id = pl.pick_list_id 
                              JOIN items i ON pli.item_id = i.item_id 
                              WHERE pli.pick_item_id = ?";
            $get_item_stmt = $conn->prepare($get_item_query);
            $get_item_stmt->bind_param("i", $pick_item_id);
            $get_item_stmt->execute();
            $item = $get_item_stmt->get_result()->fetch_assoc();
            
            if ($item) {
                // Return stock to items table
                $return_stock_query = "UPDATE items SET stock = stock + ? WHERE item_id = ?";
                $return_stock_stmt = $conn->prepare($return_stock_query);
                $return_stock_stmt->bind_param("ii", $item['quantity_to_pick'], $item['item_id']);
                
                if (!$return_stock_stmt->execute()) {
                    throw new Exception('Failed to return stock');
                }
                
                // Delete the pick list item
                $delete_query = "DELETE FROM pick_list_items WHERE pick_item_id = ?";
                $delete_stmt = $conn->prepare($delete_query);
                $delete_stmt->bind_param("i", $pick_item_id);
                
                if (!$delete_stmt->execute()) {
                    throw new Exception('Failed to delete pick list item');
                }
                
                // Check if pick list has any remaining items
                $check_items_query = "SELECT COUNT(*) as item_count FROM pick_list_items WHERE pick_list_id = ?";
                $check_items_stmt = $conn->prepare($check_items_query);
                $check_items_stmt->bind_param("i", $item['pick_list_id']);
                $check_items_stmt->execute();
                $item_count = $check_items_stmt->get_result()->fetch_assoc()['item_count'];
                
                if ($item_count == 0) {
                    // No items left, update pick list status to cancelled
                    $update_pl_query = "UPDATE pick_lists SET pick_status = 'cancelled', updated_at = NOW() WHERE pick_list_id = ?";
                    $update_pl_stmt = $conn->prepare($update_pl_query);
                    $update_pl_stmt->bind_param("i", $item['pick_list_id']);
                    $update_pl_stmt->execute();
                    
                    // Update sales order status back to pending
                    $update_so_query = "UPDATE sales_orders SET order_status = 'pending', updated_at = NOW() WHERE so_id = ?";
                    $update_so_stmt = $conn->prepare($update_so_query);
                    $update_so_stmt->bind_param("i", $item['so_id']);
                    $update_so_stmt->execute();
                }
            }
            
            $conn->commit();
            
            echo json_encode([
                'success' => true,
                'message' => 'Item deleted successfully. Stock returned.'
            ]);
            exit;
        }
        
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
        exit;
    }
}

// FETCH PICK LISTS WITH ITEMS FROM DATABASE - INCLUDING DRIVER INFO
$picklist_query = "
    SELECT 
        pl.pick_list_id,
        pl.pick_list_number,
        pl.pick_date,
        pl.pick_status,
        pl.picked_by,
        pl.verified_by,
        pl.created_at,
        pl.driver_id,
        d.driver_name as assigned_driver,
        pli.pick_item_id,
        pli.quantity_to_pick,
        pli.quantity_picked,
        pli.location_bin,
        i.item_id,
        i.item_code,
        i.item_name,
        i.unit_type,
        i.stock as current_stock,
        so.so_id,
        so.so_number,
        so.order_status,
        CONCAT(u.first_name, ' ', u.last_name) as encoded_by_name
    FROM pick_lists pl
    LEFT JOIN pick_list_items pli ON pl.pick_list_id = pli.pick_list_id
    LEFT JOIN items i ON pli.item_id = i.item_id
    LEFT JOIN sales_orders so ON pl.so_id = so.so_id
    LEFT JOIN users u ON pl.picked_by = u.user_id
    LEFT JOIN drivers d ON pl.driver_id = d.driver_id
    ORDER BY pl.created_at DESC, pl.pick_list_id DESC
";
$picklist_result = $conn->query($picklist_query);
$picklist_items = $picklist_result->fetch_all(MYSQLI_ASSOC);

// FETCH ALL SALES ORDERS FOR DROPDOWN
$so_query = "
    SELECT 
        so.so_id,
        so.so_number,
        so.customer_id,
        so.order_date,
        so.total_amount,
        so.order_status,
        c.customer_name
    FROM sales_orders so
    LEFT JOIN customers c ON so.customer_id = c.customer_id
    WHERE so.order_status IN ('pending', 'confirmed', 'processing')
    ORDER BY so.order_date DESC
";
$so_result = $conn->query($so_query);
$sales_orders = $so_result->fetch_all(MYSQLI_ASSOC);

// FETCH SALES ORDER ITEMS WITH ITEM DETAILS
$so_items_query = "
    SELECT 
        soi.so_id,
        soi.item_id,
        soi.quantity_ordered,
        soi.quantity_delivered,
        soi.unit_price,
        i.item_code,
        i.item_name,
        i.unit_type,
        i.stock as current_stock,
        i.reorder_level
    FROM sales_order_items soi
    JOIN items i ON soi.item_id = i.item_id
    WHERE soi.so_id IN (SELECT so_id FROM sales_orders WHERE order_status IN ('pending', 'confirmed', 'processing'))
";
$so_items_result = $conn->query($so_items_query);
$so_items = $so_items_result->fetch_all(MYSQLI_ASSOC);

// Organize SO items by SO ID
$so_items_by_so = [];
foreach ($so_items as $item) {
    $so_items_by_so[$item['so_id']][] = $item;
}

// FETCH ALL ITEMS FOR AUTO-FILL
$items_query = "
    SELECT 
        item_id,
        item_code,
        item_name,
        unit_price,
        unit_type,
        stock,
        reorder_level
    FROM items
    WHERE status = 'active'
    ORDER BY item_name ASC
";
$items_result = $conn->query($items_query);
$items_list = $items_result->fetch_all(MYSQLI_ASSOC);

// Create lookup arrays for items
$items_by_code = [];
foreach ($items_list as $item) {
    $items_by_code[$item['item_code']] = $item;
}

// FETCH ALL ACTIVE DRIVERS
$drivers_query = "
    SELECT 
        driver_id,
        driver_name,
        license_number,
        vehicle_type,
        vehicle_plate_number
    FROM drivers
    WHERE status = 'active'
    ORDER BY driver_name ASC
";
$drivers_result = $conn->query($drivers_query);
$drivers_list = $drivers_result->fetch_all(MYSQLI_ASSOC);

// ========== FIXED STATISTICS CALCULATION ==========
// Get distinct pick lists count
$distinct_picklists_query = "SELECT COUNT(DISTINCT pick_list_id) as total FROM pick_lists";
$distinct_result = $conn->query($distinct_picklists_query);
$statTotalItems = $distinct_result->fetch_assoc()['total'];

// Count pick lists by status
$status_counts_query = "
    SELECT 
        pick_status,
        COUNT(DISTINCT pick_list_id) as count 
    FROM pick_lists 
    GROUP BY pick_status
";
$status_result = $conn->query($status_counts_query);

$statWarehouseReady = 0; // Completed pick lists
$statInTransit = 0;      // In-progress pick lists
$statDelivered = 0;      // For delivered items

while ($row = $status_result->fetch_assoc()) {
    if ($row['pick_status'] === 'completed') {
        $statWarehouseReady = $row['count'];
    } elseif ($row['pick_status'] === 'in-progress') {
        $statInTransit = $row['count'];
    }
}

// Count delivered orders from sales_orders
$delivered_query = "
    SELECT COUNT(*) as count 
    FROM sales_orders 
    WHERE order_status = 'delivered'
";
$delivered_result = $conn->query($delivered_query);
$statDelivered = $delivered_result->fetch_assoc()['count'];

// Set default values if null
if (!$statTotalItems) $statTotalItems = 0;
if (!$statWarehouseReady) $statWarehouseReady = 0;
if (!$statInTransit) $statInTransit = 0;
if (!$statDelivered) $statDelivered = 0;

// Helper function for status badge
function getPickStatusBadge($status) {
    return match($status) {
        'open' => 'bg-warning text-dark',
        'in-progress' => 'bg-primary text-white',
        'completed' => 'bg-success text-white',
        'cancelled' => 'bg-danger text-white',
        default => 'bg-secondary text-white'
    };
}

function getPickStatusText($status) {
    return match($status) {
        'open' => 'Pending',
        'in-progress' => 'In Progress',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
        default => ucfirst($status)
    };
}

function getWarehouseStatusText($status) {
    return match($status) {
        'open' => 'Pending',
        'in-progress' => 'Picking',
        'completed' => 'Ready',
        'cancelled' => 'Cancelled',
        default => 'Pending'
    };
}

function formatItemDisplay($item_code, $item_name) {
    return htmlspecialchars($item_code . ' - ' . $item_name);
}

function getOrderStatusBadge($status) {
    return match($status) {
        'pending' => 'bg-warning text-dark',
        'confirmed' => 'bg-info text-white',
        'processing' => 'bg-primary text-white',
        'ready' => 'bg-success text-white',
        'delivered' => 'bg-success text-white',
        'cancelled' => 'bg-danger text-white',
        default => 'bg-secondary text-white'
    };
}

function getStockStatusClass($stock, $reorder_level) {
    if ($stock <= 0) return 'bg-danger text-white';
    if ($stock <= $reorder_level) return 'bg-warning text-dark';
    return 'bg-success text-white';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pick List Items</title>
    <link rel="icon" type="image/png" href="../Pictures/favicon-96x96.png" sizes="96x96" />
    <link rel="icon" type="image/svg+xml" href="../Pictures/favicon.svg" />
    <link rel="shortcut icon" href="../Pictures/favicon.ico" />
    <link rel="apple-touch-icon" sizes="180x180" href="../Pictures/apple-touch-icon.png" />
    <link rel="manifest" href="../Pictures/site.webmanifest" />
    <link rel="stylesheet" href="../css/current_inventory.css">
    <link rel="stylesheet" href="../css/pick_list_items.css">
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <!-- Select2 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <!-- SweetAlert2 -->
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <!-- SheetJS for Excel Export -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <style>
        /* Table styles for pick list items */
        .pick-list-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        
        .pick-list-table thead th {
            background-color: #f8f9fa;
            font-weight: 600;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #495057;
            padding: 14px 12px;
            border-bottom: 2px solid #dee2e6;
            white-space: nowrap;
            vertical-align: middle;
            text-align: left;
        }
        
        .pick-list-table tbody td {
            padding: 14px 12px;
            vertical-align: middle;
            border-bottom: 1px solid #e9ecef;
            font-size: 13px;
        }
        
        .pick-list-table tbody tr:hover {
            background-color: #f8f9fa;
        }
        
        /* Column widths */
        .col-so { width: 12%; }
        .col-item-code { width: 10%; }
        .col-item-name { width: 20%; }
        .col-to-pick { width: 8%; text-align: center; }
        .col-picked { width: 8%; text-align: center; }
        .col-location { width: 10%; }
        .col-status { width: 10%; }
        .col-encoded { width: 12%; }
        .col-actions { width: 10%; text-align: center; }
        
        .empty-state-table {
            text-align: center;
            padding: 40px 20px;
            background-color: white;
            border-radius: 8px;
        }
        
        .empty-state-table i {
            font-size: 48px;
            color: #adb5bd;
            margin-bottom: 16px;
        }
        
        .empty-state-table h5 {
            color: #495057;
            margin-bottom: 8px;
        }
        
        .empty-state-table p {
            color: #6c757d;
            margin-bottom: 20px;
        }
        
        /* Filter section layout */
        .filter-section {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
            margin-bottom: 25px;
            padding: 16px 20px;
            background-color: #f8f9fa;
            border-radius: 8px;
        }
        
        .filter-controls {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 12px;
            flex: 1;
        }
        
        .filter-actions {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .filter-dropdowns {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            align-items: center;
        }
        
        .filter-dropdown {
            min-width: 160px;
        }
        
        .filter-dropdown .form-select {
            font-size: 13px;
            padding: 8px 12px;
            border-radius: 6px;
            border: 1px solid #ced4da;
            background-color: white;
            cursor: pointer;
        }
        
        .filter-dropdown .form-select:focus {
            border-color: #0d6efd;
            box-shadow: 0 0 0 0.2rem rgba(13,110,253,0.25);
        }
        
        .filter-label {
            font-size: 12px;
            font-weight: 600;
            color: #495057;
            margin-bottom: 4px;
            display: block;
        }

        /* New Item Card */
        .new-item-card {
            border-radius: 12px;
            padding: 30px;
            text-align: center;
            color: white;
            cursor: pointer;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            margin-top: 20px;
        }

        .new-item-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(102, 126, 234, 0.4);
        }

        .new-item-card .add-icon {
            width: 60px;
            height: 60px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 15px;
            font-size: 24px;
        }

        .new-item-card h5 {
            margin-bottom: 10px;
            font-weight: 600;
        }

        .new-item-card p {
            margin-bottom: 0;
            opacity: 0.9;
        }

        /* Select2 customization */
        .select2-container--default .select2-selection--single {
            height: 38px;
            border: 1px solid #ced4da;
            border-radius: 6px;
        }

        .select2-container--default .select2-selection--single .select2-selection__rendered {
            line-height: 38px;
            padding-left: 12px;
        }

        .select2-container--default .select2-selection--single .select2-selection__arrow {
            height: 36px;
        }

        .select2-dropdown {
            border: 1px solid #ced4da;
            border-radius: 6px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .select2-search__field {
            border-radius: 4px;
            border: 1px solid #ced4da;
            padding: 6px 12px;
        }

        .item-preview {
            background-color: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
            margin-top: 15px;
            border-left: 4px solid #0d6efd;
        }

        .item-preview-label {
            font-size: 12px;
            color: #6c757d;
            margin-bottom: 4px;
        }

        .item-preview-value {
            font-weight: 600;
            color: #212529;
        }

        .so-details {
            background-color: #e8f4fd;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 15px;
            border-left: 4px solid #0d6efd;
        }

        .so-details-label {
            font-size: 12px;
            color: #0d6efd;
            font-weight: 600;
            margin-bottom: 4px;
        }

        .so-details-value {
            font-weight: 500;
            color: #212529;
        }

        .driver-info {
            background-color: #fff3cd;
            border-radius: 8px;
            padding: 15px;
            margin-top: 15px;
            border-left: 4px solid #ffc107;
        }

        .driver-info-label {
            font-size: 12px;
            color: #856404;
            font-weight: 600;
            margin-bottom: 4px;
        }

        .driver-info-value {
            font-weight: 500;
            color: #212529;
        }

        .driver-badge {
            display: inline-block;
            padding: 4px 10px;
            background-color: #cfe2ff;
            color: #084298;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 500;
            margin-top: 4px;
        }

        .driver-badge i {
            margin-right: 4px;
        }

        .status-badge {
            display: inline-block;
            padding: 5px 12px;
            font-size: 12px;
            font-weight: 500;
            border-radius: 20px;
            text-align: center;
            min-width: 85px;
        }

        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(255,255,255,0.8);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 9999;
        }

        .loading-spinner {
            width: 50px;
            height: 50px;
            border: 5px solid #f3f3f3;
            border-top: 5px solid #0d6efd;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .stock-indicator {
            font-size: 11px;
            padding: 2px 8px;
            border-radius: 12px;
            display: inline-block;
            margin-top: 4px;
        }
    </style>
</head>
<body>
    <!-- Loading Overlay -->
    <div id="loadingOverlay" class="loading-overlay">
        <div class="loading-spinner"></div>
    </div>

    <!-- MAIN APPLICATION -->
    <div id="appPage">
        <!-- Sidebar -->
        <div class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <h3>
                    <button class="desktop-toggle-btn" id="desktopToggleBtn">
                        <i class="bi bi-list"></i>
                    </button>    
                    <img src="../Pictures/amgc3DLogo.png" alt="Logo" class="logo-icon"> <span class="nav-text">Branch Admin</span>
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
                        <a class="nav-link" href="sales_order.php">
                            <i class="bi bi-bag"></i>
                            <span class="nav-text">Sales Orders</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="pick_list_items.php">
                            <i class="bi bi-list-check"></i>
                            <span class="nav-text">Pick List Items</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="bad_orders.php">
                            <i class="bi bi-recycle"></i>
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
            <!-- PICK LIST ITEMS CONTENT -->
            <div class="page-content active">
                <!-- Navbar Top -->
                <div class="navbar-top">
                    <!-- MOBILE MENU BUTTON -->
                    <button class="mobile-menu-btn" id="mobileMenuBtn">
                        <i class="bi bi-list"></i>
                    </button>
                    <div class="page-title">
                        <h2>Pick List Items</h2>
                        <p>Manage pick list items and assign drivers for fulfillment</p>
                    </div>
                </div>

                <!-- Stats Section -->
                <div class="row g-3 mb-4">
                    <div class="col-md-3 col-6">
                        <div class="stat-card total">
                            <i class="bi bi-boxes stat-icon"></i>
                            <div class="stat-value" id="totalItems"><?= $statTotalItems ?></div>
                            <div class="stat-label">Total Pick Lists</div>
                        </div>
                    </div>
                    <div class="col-md-3 col-6">
                        <div class="stat-card pending">
                            <i class="bi bi-check2-circle stat-icon"></i>
                            <div class="stat-value" id="warehouseReady"><?= $statWarehouseReady ?></div>
                            <div class="stat-label">Warehouse Ready</div>
                        </div>
                    </div>
                    <div class="col-md-3 col-6">
                        <div class="stat-card delivery">
                            <i class="bi bi-truck stat-icon"></i>
                            <div class="stat-value" id="inTransit"><?= $statInTransit ?></div>
                            <div class="stat-label">In Transit</div>
                        </div>
                    </div>
                    <div class="col-md-3 col-6">
                        <div class="stat-card complete">
                            <i class="bi bi-check-circle stat-icon"></i>
                            <div class="stat-value" id="delivered"><?= $statDelivered ?></div>
                            <div class="stat-label">Delivered</div>
                        </div>
                    </div>
                </div>

                <!-- FILTER SECTION -->
                <div class="filter-section">
                    <div class="filter-controls">
                        <div class="filter-dropdowns">
                            <!-- Date Filter Dropdown -->
                            <div class="filter-dropdown">
                                <span class="filter-label">Date</span>
                                <select class="form-select" id="dateFilter" onchange="applyFilters()">
                                    <option value="all">All Dates</option>
                                    <option value="today">Today</option>
                                    <option value="yesterday">Yesterday</option>
                                    <option value="this_week">This Week</option>
                                    <option value="last_week">Last Week</option>
                                    <option value="this_month">This Month</option>
                                    <option value="last_month">Last Month</option>
                                    <option value="this_quarter">This Quarter</option>
                                    <option value="last_quarter">Last Quarter</option>
                                    <option value="this_year">This Year</option>
                                    <option value="last_year">Last Year</option>
                                </select>
                            </div>
                            
                            <!-- Status Filter Dropdown -->
                            <div class="filter-dropdown">
                                <span class="filter-label">Status</span>
                                <select class="form-select" id="statusFilter" onchange="applyFilters()">
                                    <option value="all">All Status</option>
                                    <option value="open">Pending</option>
                                    <option value="in-progress">In Progress</option>
                                    <option value="completed">Completed</option>
                                    <option value="cancelled">Cancelled</option>
                                </select>
                            </div>
                            
                            <!-- Driver Filter Dropdown - NEW -->
                            <div class="filter-dropdown">
                                <span class="filter-label">Driver</span>
                                <select class="form-select" id="driverFilter" onchange="applyFilters()">
                                    <option value="all">All Drivers</option>
                                    <option value="unassigned">Unassigned</option>
                                    <?php foreach ($drivers_list as $driver): ?>
                                        <option value="<?= $driver['driver_id'] ?>">
                                            <?= htmlspecialchars($driver['driver_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <!-- Quantity Filter Dropdown -->
                            <div class="filter-dropdown">
                                <span class="filter-label">Quantity to Pick</span>
                                <select class="form-select" id="quantityFilter" onchange="applyFilters()">
                                    <option value="all">All Quantities</option>
                                    <option value="lt10">Less than 10</option>
                                    <option value="10-50">10 - 50</option>
                                    <option value="51-100">51 - 100</option>
                                    <option value="101-500">101 - 500</option>
                                    <option value="gt500">Greater than 500</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="filter-actions">
                        <button class="btn btn-outline-primary" onclick="printPickList()">
                            <i class="bi bi-printer me-1"></i> Print
                        </button>
                        <button class="btn btn-outline-success" onclick="exportToExcel()">
                            <i class="bi bi-file-earmark-excel me-1"></i> Export to Excel
                        </button>
                        <button class="btn btn-primary" onclick="showAddItemModal()">
                            <i class="bi bi-plus-circle me-1"></i> Add Item
                        </button>
                    </div>
                </div>

                <!-- Pick List Items Table -->
                <div class="table-responsive">
                    <table class="table pick-list-table" id="pickListTable">
                        <thead>
                            <tr>
                                <th class="col-so">SO NUMBER</th>
                                <th class="col-item-code">ITEM CODE</th>
                                <th class="col-item-name">ITEM NAME</th>
                                <th class="col-to-pick">TO PICK</th>
                                <th class="col-picked">PICKED</th>
                                <th class="col-location">LOCATION</th>
                                <th class="col-status">STATUS</th>
                                <th class="col-encoded">ASSIGNED DRIVER</th>
                                <th class="col-actions">ACTIONS</th>
                            </tr>
                        </thead>
                        <tbody id="pickListTableBody">
                            <?php 
                            $has_displayable_items = false;
                            
                            if (!empty($picklist_items)) {
                                foreach ($picklist_items as $item) {
                                    if ($item['item_id'] !== null) {
                                        $has_displayable_items = true;
                                        break;
                                    }
                                }
                            }
                            
                            if ($has_displayable_items): 
                                foreach ($picklist_items as $item): 
                                    if ($item['item_id'] === null) continue;
                            ?>
                            <tr class="pick-list-row" 
                                data-id="<?= $item['pick_item_id'] ?>"
                                data-pick-list-id="<?= $item['pick_list_id'] ?>"
                                data-so-id="<?= htmlspecialchars($item['so_number'] ?? '') ?>"
                                data-status="<?= $item['pick_status'] ?>"
                                data-item-code="<?= htmlspecialchars($item['item_code'] ?? '') ?>"
                                data-quantity="<?= $item['quantity_to_pick'] ?? 0 ?>"
                                data-created-date="<?= $item['created_at'] ?? '' ?>"
                                data-driver-id="<?= $item['driver_id'] ?? '' ?>"
                                data-driver-name="<?= htmlspecialchars($item['assigned_driver'] ?? 'Unassigned') ?>">
                                <td class="col-so">
                                    <strong><?= htmlspecialchars($item['so_number'] ?? 'N/A') ?></strong>
                                    <?php if ($item['order_status']): ?>
                                    <?php endif; ?>
                                </td>
                                <td class="col-item-code"><?= htmlspecialchars($item['item_code'] ?? 'N/A') ?></td>
                                <td class="col-item-name">
                                    <?= htmlspecialchars($item['item_name'] ?? 'Unknown Item') ?>
                                    <span class="stock-indicator <?= getStockStatusClass($item['current_stock'] ?? 0, 50) ?>">
                                        Stock: <?= $item['current_stock'] ?? 0 ?>
                                    </span>
                                </td>
                                <td class="col-to-pick"><?= $item['quantity_to_pick'] ?? 0 ?></td>
                                <td class="col-picked"><?= $item['quantity_picked'] ?? 0 ?></td>
                                <td class="col-location"><?= htmlspecialchars($item['location_bin'] ?? '—') ?></td>
                                <td class="col-status">
                                    <span class="badge <?= getOrderStatusBadge($item['order_status']) ?>" style="font-size: 10px;">
                                        <?= ucfirst($item['order_status']) ?>
                                    </span>
                                </td>
                                <td class="col-encoded">
                                    <?php if (!empty($item['assigned_driver'])): ?>
                                        <span class="driver-badge">
                                            <i class="bi bi-truck"></i> <?= htmlspecialchars($item['assigned_driver']) ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted fst-italic">Unassigned</span>
                                    <?php endif; ?>
                                </td>
                                <td class="col-actions">
                                    <div class="action-buttons">
                                        <button class="table-btn btn-view" onclick="viewItem(<?= $item['pick_item_id'] ?>)" title="View">
                                            <i class="bi bi-eye"></i>
                                        </button>
                                        <?php if ($item['pick_status'] === 'open'): ?>
                                        <button class="table-btn btn-delete" onclick="deleteItem(<?= $item['pick_item_id'] ?>)" title="Delete">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php 
                                endforeach;
                            else: 
                            ?>
                            <tr>
                                <td colspan="9" class="empty-state-table">
                                    <i class="bi bi-clipboard"></i>
                                    <h5>No Pick List Items Found</h5>
                                    <p class="text-muted">There are currently no pick list items in the database.</p>

                                </td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Add Item Card -->
                <div class="new-item-card mt-4" onclick="showAddItemModal()">
                    <div class="add-icon">
                        <i class="bi bi-plus-lg"></i>
                    </div>
                    <h5>Add New Pick List Item</h5>
                    <p>Click to add a new item to the pick list and assign a driver</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Add/Edit Item Modal -->
    <div class="modal fade" id="itemModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="modalTitle"><i class="bi bi-plus-circle me-2"></i>Add Pick List Item</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="itemForm">
                        <input type="hidden" id="itemId">
                        
                        <div class="row g-3">
                            <!-- SO ID Dropdown -->
                            <div class="col-md-6">
                                <label for="soIdSelect" class="form-label">Sales Order *</label>
                                <select class="form-select select2-so" id="soIdSelect" style="width: 100%;" onchange="onSOSelected()" required>
                                    <option value="">Select Sales Order</option>
                                    <?php foreach ($sales_orders as $so): ?>
                                        <option value="<?= $so['so_id'] ?>" 
                                                data-so-number="<?= htmlspecialchars($so['so_number']) ?>"
                                                data-customer-name="<?= htmlspecialchars($so['customer_name'] ?? 'N/A') ?>"
                                                data-order-date="<?= $so['order_date'] ?>"
                                                data-total-amount="<?= $so['total_amount'] ?>">
                                            <?= htmlspecialchars($so['so_number'] . ' - ' . ($so['customer_name'] ?? 'Unknown') . ' - ' . date('M d, Y', strtotime($so['order_date']))) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="hidden" id="soId" name="so_id">
                                <input type="hidden" id="soNumber" name="so_number">
                            </div>
                            
                            <!-- SO Details Preview -->
                            <div class="col-md-6">
                                <div id="soDetailsPreview" style="display: none;" class="so-details">
                                    <div class="so-details-label">Sales Order Details</div>
                                    <div class="so-details-value" id="previewSoNumber">-</div>
                                    <div class="so-details-value" id="previewCustomer">-</div>
                                    <div class="so-details-value" id="previewOrderDate">-</div>
                                </div>
                            </div>
                            
                            <!-- Item Code Dropdown (will be populated based on SO) -->
                            <div class="col-md-6">
                                <label for="itemCodeSelect" class="form-label">Item *</label>
                                <select class="form-select select2-item" id="itemCodeSelect" style="width: 100%;" onchange="onItemSelected()" required>
                                    <option value="">Select Item</option>
                                </select>
                                <input type="hidden" id="itemCode" name="item_code">
                                <input type="hidden" id="itemId" name="item_id">
                            </div>
                            
                            <!-- Auto-filled Item Details -->
                            <div class="col-12">
                                <div class="item-preview" id="itemPreview" style="display: none;">
                                    <div class="row">
                                        <div class="col-md-4">
                                            <div class="item-preview-label">Item Name</div>
                                            <div class="item-preview-value" id="previewItemName">-</div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="item-preview-label">Unit Type</div>
                                            <div class="item-preview-value" id="previewUnitType">-</div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="item-preview-label">Available Stock</div>
                                            <div class="item-preview-value" id="previewStock">-</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Quantity Fields - Auto-filled from SO -->
                            <div class="col-md-4">
                                <label for="caseQty" class="form-label">Case Quantity</label>
                                <input type="number" class="form-control" id="caseQty" name="case_qty" min="0" value="0" readonly>
                                <small class="text-muted">1 Case = 12 pcs</small>
                            </div>
                            <div class="col-md-4">
                                <label for="innerPackQty" class="form-label">Inner Pack Quantity</label>
                                <input type="number" class="form-control" id="innerPackQty" name="inner_pack_qty" min="0" value="0" readonly>
                                <small class="text-muted">1 Inner Pack = 6 pcs</small>
                            </div>
                            <div class="col-md-4">
                                <label for="pieceQty" class="form-label">Piece Quantity</label>
                                <input type="number" class="form-control" id="pieceQty" name="piece_qty" min="0" value="0" readonly>
                                <small class="text-muted">Per piece</small>
                            </div>
                            
                            <!-- Total Quantity (Read Only) -->
                            <div class="col-md-6">
                                <label for="totalQuantity" class="form-label">Total Quantity to Pick</label>
                                <input type="number" class="form-control bg-light" id="totalQuantity" name="quantity_to_pick" readonly>
                            </div>
                            
                            <!-- Location Bin - Auto-filled from inventory -->
                            <div class="col-md-6">
                                <label for="locationBin" class="form-label">Location/Bin *</label>
                                <input type="text" class="form-control" id="locationBin" name="location_bin" required placeholder="e.g., A-01-01">
                            </div>
                            
                            <!-- DRIVER ASSIGNMENT - NEW SECTION -->
                            <div class="col-md-6">
                                <label for="driverSelect" class="form-label">Assign Driver *</label>
                                <select class="form-select select2-driver" id="driverSelect" style="width: 100%;" required>
                                    <option value="">Select Driver</option>
                                    <?php foreach ($drivers_list as $driver): ?>
                                        <option value="<?= $driver['driver_id'] ?>" 
                                                data-license="<?= htmlspecialchars($driver['license_number']) ?>"
                                                data-vehicle="<?= htmlspecialchars($driver['vehicle_type'] ?? 'N/A') ?>"
                                                data-plate="<?= htmlspecialchars($driver['vehicle_plate_number'] ?? 'N/A') ?>">
                                            <?= htmlspecialchars($driver['driver_name'] . ' - ' . ($driver['vehicle_plate_number'] ?? 'No vehicle')) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="hidden" id="driverId" name="driver_id">
                            </div>
                            
                            <!-- Driver Info Preview - NEW -->
                            <div class="col-md-6">
                                <div id="driverInfoPreview" style="display: none;" class="driver-info">
                                    <div class="driver-info-label">Driver Information</div>
                                    <div class="driver-info-value" id="previewDriverName">-</div>
                                    <div class="driver-info-value" id="previewDriverVehicle">-</div>
                                </div>
                            </div>
                            
                            <!-- Encoded By and At -->
                            <div class="col-md-6">
                                <label for="encodedBy" class="form-label">Encoded By *</label>
                                <input type="text" class="form-control" id="encodedBy" name="encoded_by" value="1" required readonly>
                            </div>
                            <div class="col-md-6">
                                <label for="encodedAt" class="form-label">Encoded At *</label>
                                <input type="datetime-local" class="form-control" id="encodedAt" name="encoded_at" required readonly>
                            </div>
                        </div>
                        
                        <div class="alert alert-info mt-3">
                            <i class="bi bi-info-circle me-2"></i>
                            <strong>Driver Assignment:</strong> The assigned driver will be responsible for delivering this pick list. Driver information can be updated later.
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-circle me-1"></i> Cancel
                    </button>
                    <button type="button" class="btn btn-primary" onclick="saveItem()">
                        <i class="bi bi-check-circle me-1"></i> Save Item
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- View Item Modal - UPDATED TO SHOW DRIVER INFO -->
    <div class="modal fade" id="viewItemModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title"><i class="bi bi-eye me-2"></i>Pick List Item Details</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div id="viewItemDetails" class="row">
                        <!-- Content will be populated by JavaScript -->
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-circle me-1"></i> Close
                    </button>
                    <button type="button" class="btn btn-warning" onclick="editCurrentItem()" id="editFromViewBtn">
                        <i class="bi bi-pencil me-1"></i> Edit
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal fade" id="deleteModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title"><i class="bi bi-trash me-2"></i>Confirm Delete</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to delete this item from the pick list?</p>
                    <div class="alert alert-warning mb-0">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        This will return the stock to the items table.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-circle me-1"></i> Cancel
                    </button>
                    <button type="button" class="btn btn-danger" onclick="confirmDelete()">
                        <i class="bi bi-trash me-1"></i> Delete Item
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- jQuery and Select2 JS -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <script>
    // ========== GLOBAL VARIABLES ==========
    let selectedItemId = null;
    let selectedPickItemId = null;
    let itemsData = <?= json_encode($items_by_code) ?>;
    let soItemsData = <?= json_encode($so_items_by_so) ?>;
    let driversData = <?= json_encode($drivers_list) ?>;
    let currentUserId = 1; // This should be from session
    
    // ========== LOADING FUNCTIONS ==========
    function showLoading() {
        document.getElementById('loadingOverlay').style.display = 'flex';
    }

    function hideLoading() {
        document.getElementById('loadingOverlay').style.display = 'none';
    }

    // ========== SIDEBAR FUNCTIONS ==========
    function toggleSidebar() {
        const sidebar = document.getElementById('sidebar');
        const isMobile = window.innerWidth <= 992;
        
        if (isMobile) {
            sidebar.classList.toggle('active');
            if (!document.querySelector('.sidebar-overlay')) {
                const overlay = document.createElement('div');
                overlay.className = 'sidebar-overlay';
                document.body.appendChild(overlay);
                overlay.addEventListener('click', closeMobileSidebar);
                setTimeout(() => overlay.classList.add('active'), 10);
            }
        } else {
            sidebar.classList.toggle('collapsed');
            localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
            document.querySelectorAll('.nav-text').forEach(text => {
                text.style.display = sidebar.classList.contains('collapsed') ? 'none' : 'inline-block';
            });
        }
    }

    function closeMobileSidebar() {
        const sidebar = document.getElementById('sidebar');
        const overlay = document.querySelector('.sidebar-overlay');
        sidebar.classList.remove('active');
        if (overlay) {
            overlay.classList.remove('active');
            setTimeout(() => overlay.remove(), 300);
        }
    }

    function initializeSidebar() {
        const sidebar = document.getElementById('sidebar');
        if (window.innerWidth > 992) {
            const savedCollapsed = localStorage.getItem('sidebarCollapsed');
            if (savedCollapsed === 'true') {
                sidebar.classList.add('collapsed');
                document.querySelectorAll('.nav-text').forEach(text => text.style.display = 'none');
            }
        }
    }

    // ========== FILTER FUNCTIONS ==========
    function applyFilters() {
        const dateFilter = document.getElementById('dateFilter').value;
        const statusFilter = document.getElementById('statusFilter').value;
        const driverFilter = document.getElementById('driverFilter').value;
        const itemFilter = document.getElementById('itemFilter').value;
        const quantityFilter = document.getElementById('quantityFilter').value;
        
        const rows = document.querySelectorAll('.pick-list-row');
        let visibleCount = 0;
        
        rows.forEach(row => {
            let showRow = true;
            
            if (statusFilter !== 'all') {
                const rowStatus = row.dataset.status;
                if (rowStatus !== statusFilter) showRow = false;
            }
            
            if (showRow && driverFilter !== 'all') {
                const rowDriverId = row.dataset.driverId || '';
                if (driverFilter === 'unassigned') {
                    if (rowDriverId) showRow = false;
                } else {
                    if (rowDriverId !== driverFilter) showRow = false;
                }
            }
            
            if (showRow && itemFilter !== 'all') {
                const rowItemCode = row.dataset.itemCode;
                if (rowItemCode !== itemFilter) showRow = false;
            }
            
            if (showRow && quantityFilter !== 'all') {
                const rowQuantity = parseFloat(row.dataset.quantity);
                switch(quantityFilter) {
                    case 'lt10':
                        if (rowQuantity >= 10) showRow = false;
                        break;
                    case '10-50':
                        if (rowQuantity < 10 || rowQuantity > 50) showRow = false;
                        break;
                    case '51-100':
                        if (rowQuantity < 51 || rowQuantity > 100) showRow = false;
                        break;
                    case '101-500':
                        if (rowQuantity < 101 || rowQuantity > 500) showRow = false;
                        break;
                    case 'gt500':
                        if (rowQuantity <= 500) showRow = false;
                        break;
                }
            }
            
            if (showRow && dateFilter !== 'all') {
                const rowDate = new Date(row.dataset.createdDate);
                const today = new Date();
                today.setHours(0, 0, 0, 0);
                
                const yesterday = new Date(today);
                yesterday.setDate(yesterday.getDate() - 1);
                
                const startOfWeek = new Date(today);
                startOfWeek.setDate(today.getDate() - today.getDay());
                const endOfWeek = new Date(startOfWeek);
                endOfWeek.setDate(startOfWeek.getDate() + 6);
                
                const startOfMonth = new Date(today.getFullYear(), today.getMonth(), 1);
                const endOfMonth = new Date(today.getFullYear(), today.getMonth() + 1, 0);
                
                const startOfYear = new Date(today.getFullYear(), 0, 1);
                const endOfYear = new Date(today.getFullYear(), 11, 31);
                
                switch(dateFilter) {
                    case 'today':
                        if (rowDate < today || rowDate > new Date(today.getTime() + 86400000 - 1)) showRow = false;
                        break;
                    case 'yesterday':
                        if (rowDate < yesterday || rowDate > new Date(yesterday.getTime() + 86400000 - 1)) showRow = false;
                        break;
                    case 'this_week':
                        if (rowDate < startOfWeek || rowDate > new Date(endOfWeek.getTime() + 86400000 - 1)) showRow = false;
                        break;
                    case 'this_month':
                        if (rowDate < startOfMonth || rowDate > new Date(endOfMonth.getTime() + 86400000 - 1)) showRow = false;
                        break;
                    case 'this_year':
                        if (rowDate < startOfYear || rowDate > new Date(endOfYear.getTime() + 86400000 - 1)) showRow = false;
                        break;
                }
            }
            
            row.style.display = showRow ? '' : 'none';
            if (showRow) visibleCount++;
        });
        
        const emptyStateRow = document.querySelector('.empty-state-table');
        if (emptyStateRow) {
            const emptyStateParent = emptyStateRow.closest('tr');
            if (visibleCount === 0) {
                if (emptyStateParent) {
                    emptyStateParent.style.display = '';
                    emptyStateRow.innerHTML = `
                        <td colspan="9" class="empty-state-table">
                            <i class="bi bi-funnel"></i>
                            <h5>No matching pick list items</h5>
                            <p class="text-muted">No items match your filter criteria.</p>
                            <button class="btn btn-primary mt-3" onclick="clearAllFilters()">
                                <i class="bi bi-x-circle me-1"></i> Clear Filters
                            </button>
                        </td>
                    `;
                }
            } else {
                if (emptyStateParent) emptyStateParent.style.display = 'none';
            }
        }
    }

    function clearAllFilters() {
        document.getElementById('dateFilter').value = 'all';
        document.getElementById('statusFilter').value = 'all';
        document.getElementById('driverFilter').value = 'all';
        document.getElementById('itemFilter').value = 'all';
        document.getElementById('quantityFilter').value = 'all';
        applyFilters();
    }

    // ========== MODAL FUNCTIONS ==========
    function showAddItemModal() {
        document.getElementById('modalTitle').textContent = 'Add Pick List Item';
        document.getElementById('itemForm').reset();
        document.getElementById('itemId').value = '';
        document.getElementById('soId').value = '';
        document.getElementById('soNumber').value = '';
        document.getElementById('itemCode').value = '';
        document.getElementById('itemId').value = '';
        document.getElementById('driverId').value = '';
        document.getElementById('itemPreview').style.display = 'none';
        document.getElementById('soDetailsPreview').style.display = 'none';
        document.getElementById('driverInfoPreview').style.display = 'none';
        document.getElementById('locationBin').value = '';
        document.getElementById('locationBin').readOnly = false;
        
        // Reset Select2 dropdowns
        $('#soIdSelect').val('').trigger('change');
        $('#driverSelect').val('').trigger('change');
        
        // Clear item dropdown
        const itemSelect = $('#itemCodeSelect');
        itemSelect.empty();
        itemSelect.append('<option value="">Select Item</option>');
        itemSelect.trigger('change');
        
        // Set current date/time
        const now = new Date();
        const formattedDateTime = now.toISOString().slice(0, 16);
        document.getElementById('encodedAt').value = formattedDateTime;
        document.getElementById('encodedBy').value = '<?= $_SESSION['user_id'] ?? 1 ?>';
        
        new bootstrap.Modal(document.getElementById('itemModal')).show();
    }

    function onSOSelected() {
        const select = document.getElementById('soIdSelect');
        const selectedOption = select.options[select.selectedIndex];
        
        if (selectedOption.value) {
            const soId = selectedOption.value;
            const soNumber = selectedOption.dataset.soNumber;
            const customerName = selectedOption.dataset.customerName;
            const orderDate = selectedOption.dataset.orderDate;
            
            // Fill hidden fields
            document.getElementById('soId').value = soId;
            document.getElementById('soNumber').value = soNumber;
            
            // Show SO details
            document.getElementById('previewSoNumber').textContent = 'SO #: ' + soNumber;
            document.getElementById('previewCustomer').textContent = 'Customer: ' + customerName;
            document.getElementById('previewOrderDate').textContent = 'Order Date: ' + new Date(orderDate).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
            document.getElementById('soDetailsPreview').style.display = 'block';
            
            // Populate item dropdown based on selected SO
            populateItemsForSO(soId);
            
            // Clear item preview
            document.getElementById('itemPreview').style.display = 'none';
            document.getElementById('itemCode').value = '';
            document.getElementById('itemId').value = '';
            document.getElementById('caseQty').value = 0;
            document.getElementById('innerPackQty').value = 0;
            document.getElementById('pieceQty').value = 0;
            document.getElementById('totalQuantity').value = 0;
            document.getElementById('locationBin').value = '';
            
        } else {
            document.getElementById('soId').value = '';
            document.getElementById('soNumber').value = '';
            document.getElementById('soDetailsPreview').style.display = 'none';
            
            // Clear item dropdown
            const itemSelect = $('#itemCodeSelect');
            itemSelect.empty();
            itemSelect.append('<option value="">Select Item</option>');
            itemSelect.trigger('change');
        }
    }

    function populateItemsForSO(soId) {
        const items = soItemsData[soId] || [];
        const itemSelect = $('#itemCodeSelect');
        
        // Clear and add default option
        itemSelect.empty();
        itemSelect.append('<option value="">Select Item</option>');
        
        // Add items from the selected SO
        items.forEach(item => {
            // Calculate already picked quantity for this SO and item
            const alreadyPicked = 0; // You can calculate this from existing pick list items
            const availableToPick = item.quantity_ordered - (item.quantity_delivered || 0) - alreadyPicked;
            
            if (availableToPick > 0 && item.current_stock > 0) {
                const option = new Option(
                    item.item_code + ' - ' + item.item_name + 
                    ' (Ordered: ' + item.quantity_ordered + 
                    ', Stock: ' + item.current_stock + 
                    ', Available: ' + availableToPick + ')',
                    item.item_code,
                    false,
                    false
                );
                
                // Add data attributes
                $(option).attr('data-item-id', item.item_id);
                $(option).attr('data-item-name', item.item_name);
                $(option).attr('data-unit-price', item.unit_price);
                $(option).attr('data-unit-type', item.unit_type);
                $(option).attr('data-current-stock', item.current_stock || 0);
                $(option).attr('data-quantity-ordered', availableToPick);
                
                itemSelect.append(option);
            }
        });
        
        itemSelect.trigger('change');
    }

    function onItemSelected() {
        const select = document.getElementById('itemCodeSelect');
        const selectedOption = select.options[select.selectedIndex];
        
        if (selectedOption.value) {
            const itemCode = selectedOption.value;
            const itemName = selectedOption.dataset.itemName;
            const unitType = selectedOption.dataset.unitType;
            const currentStock = parseInt(selectedOption.dataset.currentStock) || 0;
            const itemId = selectedOption.dataset.itemId;
            const quantityOrdered = parseInt(selectedOption.dataset.quantityOrdered) || 0;
            
            // Check if enough stock
            if (currentStock < quantityOrdered) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Insufficient Stock',
                    text: `Only ${currentStock} ${unitType} available. Cannot pick ${quantityOrdered}.`,
                    confirmButtonColor: '#0d6efd'
                });
                return;
            }
            
            // Fill hidden fields
            document.getElementById('itemCode').value = itemCode;
            document.getElementById('itemId').value = itemId;
            
            // Show and update preview
            document.getElementById('previewItemName').textContent = itemName;
            document.getElementById('previewUnitType').textContent = unitType.toUpperCase();
            
            let stockStatus = currentStock + ' ' + unitType.toUpperCase() + ' available';
            if (currentStock <= 10) {
                stockStatus += ' ⚠️ Low Stock!';
            }
            document.getElementById('previewStock').textContent = stockStatus;
            document.getElementById('itemPreview').style.display = 'block';
            
            // Auto-fill quantities from SO
            // Assuming 1 Case = 12 pcs, 1 Inner Pack = 6 pcs
            let remainingQty = Math.min(quantityOrdered, currentStock);
            const cases = Math.floor(remainingQty / 12);
            remainingQty = remainingQty % 12;
            const innerPacks = Math.floor(remainingQty / 6);
            const pieces = remainingQty % 6;
            
            document.getElementById('caseQty').value = cases;
            document.getElementById('innerPackQty').value = innerPacks;
            document.getElementById('pieceQty').value = pieces;
            
            // Calculate and set total quantity
            const totalPieces = (cases * 12) + (innerPacks * 6) + pieces;
            document.getElementById('totalQuantity').value = totalPieces;
            
            // Auto-fill location bin with default
            document.getElementById('locationBin').value = 'A-01-01';
            
        } else {
            document.getElementById('itemCode').value = '';
            document.getElementById('itemId').value = '';
            document.getElementById('itemPreview').style.display = 'none';
            document.getElementById('caseQty').value = 0;
            document.getElementById('innerPackQty').value = 0;
            document.getElementById('pieceQty').value = 0;
            document.getElementById('totalQuantity').value = 0;
            document.getElementById('locationBin').value = '';
        }
    }

    // NEW: Driver selection handler
    $(document).ready(function() {
        // Initialize driver Select2
        $('.select2-driver').select2({
            placeholder: 'Search Driver...',
            allowClear: true,
            dropdownParent: $('#itemModal')
        });

        // Driver change event
        $('#driverSelect').on('change', function() {
            const select = document.getElementById('driverSelect');
            const selectedOption = select.options[select.selectedIndex];
            
            if (selectedOption && selectedOption.value) {
                const driverId = selectedOption.value;
                const driverName = selectedOption.text.split(' - ')[0];
                const vehiclePlate = selectedOption.dataset.plate || 'N/A';
                const vehicleType = selectedOption.dataset.vehicle || 'N/A';
                
                document.getElementById('driverId').value = driverId;
                
                // Show driver info preview
                document.getElementById('previewDriverName').textContent = 'Driver: ' + driverName;
                document.getElementById('previewDriverVehicle').textContent = 'Vehicle: ' + vehicleType + ' - ' + vehiclePlate;
                document.getElementById('driverInfoPreview').style.display = 'block';
            } else {
                document.getElementById('driverId').value = '';
                document.getElementById('driverInfoPreview').style.display = 'none';
            }
        });
    });

    // ========== SAVE FUNCTIONS ==========
    function saveItem() {
        // Validate required fields
        const soId = document.getElementById('soId').value;
        const itemId = document.getElementById('itemId').value;
        const totalQuantity = document.getElementById('totalQuantity').value;
        const locationBin = document.getElementById('locationBin').value;
        const encodedBy = document.getElementById('encodedBy').value;
        const driverId = document.getElementById('driverId').value;
        
        if (!soId) {
            Swal.fire({
                icon: 'warning',
                title: 'Missing Field',
                text: 'Please select a Sales Order',
                confirmButtonColor: '#0d6efd'
            });
            return;
        }
        
        if (!itemId) {
            Swal.fire({
                icon: 'warning',
                title: 'Missing Field',
                text: 'Please select an Item',
                confirmButtonColor: '#0d6efd'
            });
            return;
        }
        
        if (totalQuantity <= 0) {
            Swal.fire({
                icon: 'warning',
                title: 'Invalid Quantity',
                text: 'The selected item has no available quantity to pick',
                confirmButtonColor: '#0d6efd'
            });
            return;
        }
        
        if (!locationBin) {
            Swal.fire({
                icon: 'warning',
                title: 'Missing Field',
                text: 'Location/Bin is required',
                confirmButtonColor: '#0d6efd'
            });
            return;
        }
        
        if (!driverId) {
            Swal.fire({
                icon: 'warning',
                title: 'Missing Field',
                text: 'Please assign a driver for this pick list',
                confirmButtonColor: '#0d6efd'
            });
            return;
        }
        
        // Show loading
        showLoading();
        
        // Prepare form data
        const formData = new FormData();
        formData.append('action', 'save_pick_item');
        formData.append('so_id', soId);
        formData.append('item_id', itemId);
        formData.append('quantity_to_pick', totalQuantity);
        formData.append('location_bin', locationBin);
        formData.append('encoded_by', encodedBy);
        formData.append('driver_id', driverId);
        
        // Send AJAX request
        fetch('pick_list_items.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            hideLoading();
            
            if (data.success) {
                Swal.fire({
                    icon: 'success',
                    title: 'Success!',
                    text: data.message,
                    confirmButtonColor: '#0d6efd',
                    timer: 2000
                }).then(() => {
                    // Close modal
                    bootstrap.Modal.getInstance(document.getElementById('itemModal')).hide();
                    // Reload page to show updated data
                    location.reload();
                });
            } else {
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: data.message,
                    confirmButtonColor: '#0d6efd'
                });
            }
        })
        .catch(error => {
            hideLoading();
            console.error('Error:', error);
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: 'An error occurred while saving the item',
                confirmButtonColor: '#0d6efd'
            });
        });
    }

    // ========== VIEW FUNCTIONS ==========
    function viewItem(id) {
        const row = document.querySelector(`.pick-list-row[data-id="${id}"]`);
        if (!row) return;
        
        selectedPickItemId = id;
        
        const soNumber = row.dataset.soId || 'N/A';
        const itemCode = row.dataset.itemCode || 'N/A';
        const itemName = row.querySelector('.col-item-name')?.innerText.split('\n')[0] || 'N/A';
        const quantity = row.dataset.quantity || '0';
        const location = row.querySelector('.col-location')?.innerText || '—';
        const status = row.dataset.status || '';
        const driverName = row.dataset.driverName || 'Unassigned';
        const stockText = row.querySelector('.stock-indicator')?.innerText || 'Stock: 0';
        
        const detailsHtml = `
            <div class="col-md-6">
                <div class="detail-card">
                    <div class="detail-label">SO Number</div>
                    <div class="detail-value">${soNumber}</div>
                </div>
                <div class="detail-card">
                    <div class="detail-label">Item Code</div>
                    <div class="detail-value">${itemCode}</div>
                </div>
                <div class="detail-card">
                    <div class="detail-label">Item Name</div>
                    <div class="detail-value">${itemName}</div>
                </div>
                <div class="detail-card">
                    <div class="detail-label">Quantity to Pick</div>
                    <div class="detail-value">${quantity}</div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="detail-card">
                    <div class="detail-label">Location/Bin</div>
                    <div class="detail-value">${location}</div>
                </div>
                <div class="detail-card">
                    <div class="detail-label">Status</div>
                    <div class="detail-value"><span class="badge ${getPickStatusBadge(status)}">${getPickStatusText(status)}</span></div>
                </div>
                <div class="detail-card">
                    <div class="detail-label">Assigned Driver</div>
                    <div class="detail-value">
                        <span class="driver-badge">
                            <i class="bi bi-truck"></i> ${driverName}
                        </span>
                    </div>
                </div>
                <div class="detail-card">
                    <div class="detail-label">Current Stock</div>
                    <div class="detail-value">${stockText}</div>
                </div>
            </div>
        `;
        
        document.getElementById('viewItemDetails').innerHTML = detailsHtml;
        new bootstrap.Modal(document.getElementById('viewItemModal')).show();
    }

    function editCurrentItem() {
        bootstrap.Modal.getInstance(document.getElementById('viewItemModal')).hide();
        // In a real implementation, this would open the edit modal with the item data
        Swal.fire({
            icon: 'info',
            title: 'Edit Item',
            text: 'Edit functionality for pick list items will be available soon',
            confirmButtonColor: '#0d6efd'
        });
    }

    // ========== DELETE FUNCTIONS ==========
    function deleteItem(id) {
        window.itemToDelete = id;
        new bootstrap.Modal(document.getElementById('deleteModal')).show();
    }

    function confirmDelete() {
        const pickItemId = window.itemToDelete;
        
        if (!pickItemId) {
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: 'No item selected',
                confirmButtonColor: '#0d6efd'
            });
            return;
        }
        
        // Show loading
        showLoading();
        
        // Prepare form data
        const formData = new FormData();
        formData.append('action', 'delete_pick_item');
        formData.append('pick_item_id', pickItemId);
        
        // Send AJAX request
        fetch('pick_list_items.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            hideLoading();
            
            if (data.success) {
                Swal.fire({
                    icon: 'success',
                    title: 'Deleted!',
                    text: data.message,
                    confirmButtonColor: '#0d6efd',
                    timer: 2000
                }).then(() => {
                    // Close modal
                    bootstrap.Modal.getInstance(document.getElementById('deleteModal')).hide();
                    // Reload page to show updated data
                    location.reload();
                });
            } else {
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: data.message,
                    confirmButtonColor: '#0d6efd'
                });
            }
        })
        .catch(error => {
            hideLoading();
            console.error('Error:', error);
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: 'An error occurred while deleting the item',
                confirmButtonColor: '#0d6efd'
            });
        });
    }

    // ========== HELPER FUNCTIONS ==========
    function getPickStatusBadge(status) {
        const classes = {
            'open': 'bg-warning text-dark',
            'in-progress': 'bg-primary text-white',
            'completed': 'bg-success text-white',
            'cancelled': 'bg-danger text-white'
        };
        return classes[status] || 'bg-secondary text-white';
    }

    function getPickStatusText(status) {
        const texts = {
            'open': 'Pending',
            'in-progress': 'In Progress',
            'completed': 'Completed',
            'cancelled': 'Cancelled'
        };
        return texts[status] || status;
    }

    // ========== EXCEL EXPORT FUNCTION ==========
    function exportToExcel() {
        const rows = document.querySelectorAll('.pick-list-row:not([style*="display: none"])');
        if (rows.length === 0) {
            Swal.fire({
                icon: 'warning',
                title: 'No Data',
                text: 'No pick list items to export',
                confirmButtonColor: '#0d6efd'
            });
            return;
        }
        
        // Prepare data array for Excel
        const excelData = [];
        
        // Add headers
        excelData.push([
            'SO Number',
            'Item Code',
            'Item Name',
            'Quantity to Pick',
            'Quantity Picked',
            'Location/Bin',
            'Status',
            'Assigned Driver',
            'Current Stock'
        ]);

        // Add data rows
        rows.forEach(row => {
            if (row.style.display !== 'none') {
                const cells = row.querySelectorAll('td');
                const soNumber = cells[0]?.innerText.replace(/\n/g, ' ').trim() || '';
                const itemCode = cells[1]?.innerText || '';
                const itemName = cells[2]?.innerText.split('\n')[0].trim() || '';
                const toPick = parseInt(cells[3]?.innerText) || 0;
                const picked = parseInt(cells[4]?.innerText) || 0;
                const location = cells[5]?.innerText || '';
                const status = cells[6]?.innerText || '';
                const driver = cells[7]?.innerText || '';
                
                // Extract stock from the stock indicator
                let stock = 0;
                const stockElement = cells[2]?.querySelector('.stock-indicator');
                if (stockElement) {
                    const stockText = stockElement.innerText;
                    const stockMatch = stockText.match(/\d+/);
                    if (stockMatch) stock = parseInt(stockMatch[0]);
                }
                
                excelData.push([
                    soNumber,
                    itemCode,
                    itemName,
                    toPick,
                    picked,
                    location,
                    status,
                    driver,
                    stock
                ]);
            }
        });

        // Create workbook and worksheet
        const wb = XLSX.utils.book_new();
        const ws = XLSX.utils.aoa_to_sheet(excelData);

        // Set column widths
        ws['!cols'] = [
            { wch: 15 }, // SO Number
            { wch: 15 }, // Item Code
            { wch: 30 }, // Item Name
            { wch: 15 }, // Quantity to Pick
            { wch: 15 }, // Quantity Picked
            { wch: 15 }, // Location/Bin
            { wch: 15 }, // Status
            { wch: 25 }, // Assigned Driver
            { wch: 15 }  // Current Stock
        ];

        // Add worksheet to workbook
        XLSX.utils.book_append_sheet(wb, ws, 'Pick List Items');

        // Generate filename with current date
        const date = new Date();
        const dateStr = date.toISOString().slice(0,10).replace(/-/g, '');
        const filename = `Pick_List_Items_${dateStr}.xlsx`;

        // Export Excel file
        XLSX.writeFile(wb, filename);
        
        Swal.fire({
            icon: 'success',
            title: 'Export Complete',
            text: 'Pick list items exported successfully!',
            confirmButtonColor: '#0d6efd',
            timer: 2000
        });
    }

    // ========== PICK LIST FUNCTIONS ==========
    document.addEventListener('DOMContentLoaded', function() {
        console.log("Pick List Items - Live Database Mode with Driver Assignment");
        
        initializeSidebar();
        
        // Initialize Select2
        $('.select2-so').select2({
            placeholder: 'Search Sales Order...',
            allowClear: true,
            dropdownParent: $('#itemModal')
        });
        
        $('.select2-item').select2({
            placeholder: 'Select Item...',
            allowClear: true,
            dropdownParent: $('#itemModal')
        });
        
        // Driver Select2 is initialized in the ready function above
        
        // Mobile menu toggle
        document.getElementById('mobileMenuBtn').addEventListener('click', function() {
            const sidebar = document.getElementById('sidebar');
            const isMobile = window.innerWidth <= 992;
            
            if (isMobile) {
                sidebar.classList.toggle('active');
                if (!document.querySelector('.sidebar-overlay')) {
                    const overlay = document.createElement('div');
                    overlay.className = 'sidebar-overlay';
                    document.body.appendChild(overlay);
                    overlay.addEventListener('click', closeMobileSidebar);
                    setTimeout(() => overlay.classList.add('active'), 10);
                }
            } else {
                toggleSidebar();
            }
        });
        
        const desktopToggleBtn = document.getElementById('desktopToggleBtn');
        if (desktopToggleBtn) {
            desktopToggleBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                toggleSidebar();
            });
        }
        
        document.querySelectorAll('.sidebar .nav-link').forEach(link => {
            link.addEventListener('click', function() {
                if (window.innerWidth <= 992) closeMobileSidebar();
            });
        });

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
    });

    function printPickList() {
        window.print();
    }

    // Logout Function
    function logout() {
        Swal.fire({
            title: 'Are you sure?',
            text: 'You will be logged out of the system',
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#0d6efd',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Yes, logout'
        }).then((result) => {
            if (result.isConfirmed) {
                localStorage.removeItem('sidebarCollapsed');
                window.location.href = 'login.php';
            }
        });
    }

    // Keyboard shortcuts
    document.addEventListener('keydown', function(e) {
        if (e.ctrlKey && e.key === 'b' && window.innerWidth > 992) {
            e.preventDefault();
            toggleSidebar();
        } else if (e.ctrlKey && e.key === 'f') {
            e.preventDefault();
            const searchInput = document.getElementById('searchInput');
            if (searchInput) searchInput.focus();
        } else if (e.ctrlKey && e.key === 'n') {
            e.preventDefault();
            showAddItemModal();
        }
    });
    </script>
</body>
</html>