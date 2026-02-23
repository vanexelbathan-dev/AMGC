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
$user_name = isset($_SESSION['first_name']) ? $_SESSION['first_name'] . ' ' . $_SESSION['last_name'] : 'Sales User';
$user_role = isset($_SESSION['role']) ? $_SESSION['role'] : 'sales';
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

// Check if branch_id column exists in customers table
$branch_column_exists = false;
$check_column = $conn->query("SHOW COLUMNS FROM customers LIKE 'branch_id'");
if ($check_column && $check_column->num_rows > 0) {
    $branch_column_exists = true;
}

// Check if branch_id column exists in items table
$items_branch_column_exists = false;
$check_items_column = $conn->query("SHOW COLUMNS FROM items LIKE 'branch_id'");
if ($check_items_column && $check_items_column->num_rows > 0) {
    $items_branch_column_exists = true;
}

// Check if price columns exist in items table
$price_case_exists = false;
$check_price_case = $conn->query("SHOW COLUMNS FROM items LIKE 'price_case'");
if ($check_price_case && $check_price_case->num_rows > 0) {
    $price_case_exists = true;
}

$price_inner_exists = false;
$check_price_inner = $conn->query("SHOW COLUMNS FROM items LIKE 'price_inner_pack'");
if ($check_price_inner && $check_price_inner->num_rows > 0) {
    $price_inner_exists = true;
}

$price_box_exists = false;
$check_price_box = $conn->query("SHOW COLUMNS FROM items LIKE 'price_box'");
if ($check_price_box && $check_price_box->num_rows > 0) {
    $price_box_exists = true;
}

$price_carton_exists = false;
$check_price_carton = $conn->query("SHOW COLUMNS FROM items LIKE 'price_carton'");
if ($check_price_carton && $check_price_carton->num_rows > 0) {
    $price_carton_exists = true;
}

// Check if deliveries table exists
$deliveries_table_exists = false;
$check_deliveries = $conn->query("SHOW TABLES LIKE 'deliveries'");
if ($check_deliveries && $check_deliveries->num_rows > 0) {
    $deliveries_table_exists = true;
}

// Check if inventory_transactions table exists
$inventory_transactions_exists = false;
$check_inv_trans = $conn->query("SHOW TABLES LIKE 'inventory_transactions'");
if ($check_inv_trans && $check_inv_trans->num_rows > 0) {
    $inventory_transactions_exists = true;
}

// Function to create delivery records for completed pick list
function createDeliveriesForPickList($conn, $pick_list_id, $branch_id, $user_id) {
    try {
        // Get pick list details
        $pl_query = "SELECT pl.*, so.customer_id, so.so_number, d.driver_id, d.driver_name
                     FROM pick_lists pl
                     JOIN sales_orders so ON pl.so_id = so.so_id
                     LEFT JOIN drivers d ON pl.driver_id = d.driver_id
                     WHERE pl.pick_list_id = ?";
        $pl_stmt = $conn->prepare($pl_query);
        $pl_stmt->bind_param("i", $pick_list_id);
        $pl_stmt->execute();
        $pl_result = $pl_stmt->get_result();
        $pick_list = $pl_result->fetch_assoc();
        
        if (!$pick_list) {
            return false;
        }
        
        // Check if trip ticket already exists for this pick list
        $check_tt_query = "SELECT trip_id FROM trip_tickets WHERE picklist_id = ?";
        $check_tt_stmt = $conn->prepare($check_tt_query);
        $check_tt_stmt->bind_param("i", $pick_list_id);
        $check_tt_stmt->execute();
        $check_tt_result = $check_tt_stmt->get_result();
        
        $trip_id = null;
        
        // If no trip ticket exists, create one
        if ($check_tt_result->num_rows == 0) {
            // Generate trip number
            $trip_number = 'TRP' . date('Ymd') . rand(1000, 9999);
            
            // Create trip ticket
            $insert_tt_query = "INSERT INTO trip_tickets 
                                (trip_number, picklist_id, so_id, driver_id, branch_id, trip_date, trip_status, created_by, created_at) 
                                VALUES (?, ?, ?, ?, ?, NOW(), 'planned', ?, NOW())";
            $insert_tt_stmt = $conn->prepare($insert_tt_query);
            $insert_tt_stmt->bind_param("siiiii", $trip_number, $pick_list_id, $pick_list['so_id'], $pick_list['driver_id'], $branch_id, $user_id);
            $insert_tt_stmt->execute();
            $trip_id = $conn->insert_id;
            $insert_tt_stmt->close();
        } else {
            $trip_data = $check_tt_result->fetch_assoc();
            $trip_id = $trip_data['trip_id'];
        }
        $check_tt_stmt->close();
        
        // Check if delivery already exists for this trip/so
        $check_delivery_query = "SELECT delivery_id FROM deliveries WHERE trip_id = ? AND so_id = ?";
        $check_delivery_stmt = $conn->prepare($check_delivery_query);
        $check_delivery_stmt->bind_param("ii", $trip_id, $pick_list['so_id']);
        $check_delivery_stmt->execute();
        $check_delivery_result = $check_delivery_stmt->get_result();
        
        if ($check_delivery_result->num_rows == 0) {
            // Create delivery record
            $insert_delivery_query = "INSERT INTO deliveries 
                                      (trip_id, so_id, customer_id, driver_id, branch_id, stop_sequence, delivery_status, created_at, created_by) 
                                      VALUES (?, ?, ?, ?, ?, 1, 'pending', NOW(), ?)";
            $insert_delivery_stmt = $conn->prepare($insert_delivery_query);
            $insert_delivery_stmt->bind_param("iiiiii", $trip_id, $pick_list['so_id'], $pick_list['customer_id'], $pick_list['driver_id'], $branch_id, $user_id);
            $insert_delivery_stmt->execute();
            $insert_delivery_stmt->close();
            
            return true;
        }
        $check_delivery_stmt->close();
        
        return false;
    } catch (Exception $e) {
        error_log("Error creating delivery: " . $e->getMessage());
        return false;
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_pick_item') {
    // Get form data
    $pick_list_id = $_POST['pick_list_id'];
    $item_id = $_POST['item_id'];
    $quantity_to_pick = $_POST['quantity_to_pick'];
    $location_bin = $_POST['location_bin'] ?: NULL;
    
    // Verify that the pick list belongs to user's branch
    $branch_check_query = "SELECT branch_id FROM pick_lists WHERE pick_list_id = ?";
    $branch_check_stmt = $conn->prepare($branch_check_query);
    $branch_check_stmt->bind_param("i", $pick_list_id);
    $branch_check_stmt->execute();
    $branch_check_result = $branch_check_stmt->get_result();
    $pick_list_data = $branch_check_result->fetch_assoc();
    
    if ($pick_list_data) {
        $pick_list_branch_id = $pick_list_data['branch_id'];
        
        // Check if user has access to this branch
        if (!$view_all_branches && $pick_list_branch_id != $user_branch_id) {
            $error_message = "You don't have permission to add items to this pick list!";
        } else {
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
                    $branch_query = "SELECT branch_id FROM pick_lists WHERE pick_list_id = ?";
                    $branch_stmt = $conn->prepare($branch_query);
                    $branch_stmt->bind_param("i", $pick_list_id);
                    $branch_stmt->execute();
                    $branch_result = $branch_stmt->get_result();
                    
                    if ($branch_row = $branch_result->fetch_assoc()) {
                        $branch_id = $branch_row['branch_id'];
                        
                        // Check if inventory table has quantity_reserved column
                        $check_reserved = $conn->query("SHOW COLUMNS FROM inventory LIKE 'quantity_reserved'");
                        if ($check_reserved && $check_reserved->num_rows > 0) {
                            $update_inventory_query = "UPDATE inventory SET quantity_reserved = quantity_reserved + ? WHERE branch_id = ? AND item_id = ?";
                            $update_stmt = $conn->prepare($update_inventory_query);
                            $update_stmt->bind_param("iii", $quantity_to_pick, $branch_id, $item_id);
                            $update_stmt->execute();
                            $update_stmt->close();
                        }
                        
                        // Record inventory transaction if table exists
                        if ($inventory_transactions_exists) {
                            $trans_query = "INSERT INTO inventory_transactions 
                                           (branch_id, item_id, transaction_type, quantity_changed, reference_type, reference_id, created_by, created_at) 
                                           VALUES (?, ?, 'reserve', ?, 'pick_list', ?, ?, NOW())";
                            $trans_stmt = $conn->prepare($trans_query);
                            $trans_stmt->bind_param("iiiii", $branch_id, $item_id, $quantity_to_pick, $pick_list_id, $user_id);
                            $trans_stmt->execute();
                            $trans_stmt->close();
                        }
                    }
                    
                    $branch_stmt->close();
                    
                    $success_message = "Pick list item added successfully!";
                } else {
                    $error_message = "Error adding pick list item: " . $conn->error;
                }
            }
            $stmt->close();
        }
    } else {
        $error_message = "Pick list not found!";
    }
    $branch_check_stmt->close();
}

// Handle update pick quantity
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_pick_quantity') {
    $pick_item_id = $_POST['pick_item_id'];
    $quantity_picked = $_POST['quantity_picked'];
    $pick_notes = isset($_POST['pick_notes']) ? $_POST['pick_notes'] : '';
    
    $get_query = "SELECT pli.quantity_to_pick, pli.quantity_picked as old_quantity, pli.pick_list_id, pli.item_id, 
                         pl.branch_id, pl.so_id, i.unit_price, i.price_case, i.price_inner_pack, i.price_box, i.price_carton
                  FROM pick_list_items pli
                  JOIN pick_lists pl ON pli.pick_list_id = pl.pick_list_id
                  JOIN items i ON pli.item_id = i.item_id
                  WHERE pli.pick_item_id = ?";
    $stmt = $conn->prepare($get_query);
    $stmt->bind_param("i", $pick_item_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $item = $result->fetch_assoc();
    
    if ($item) {
        // Verify branch access
        if (!$view_all_branches && $item['branch_id'] != $user_branch_id) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'You don\'t have permission to update this item']);
            exit;
        }
        
        $quantity_difference = $quantity_picked - $item['old_quantity'];
        
        // Check if notes column exists
        $check_notes = $conn->query("SHOW COLUMNS FROM pick_list_items LIKE 'notes'");
        if ($check_notes && $check_notes->num_rows > 0) {
            $update_query = "UPDATE pick_list_items SET quantity_picked = ?, notes = CONCAT(IFNULL(notes, ''), ?) WHERE pick_item_id = ?";
            $notes_update = "\n[" . date('Y-m-d H:i:s') . "] Quantity updated from " . $item['old_quantity'] . " to " . $quantity_picked . ($pick_notes ? " - Notes: " . $pick_notes : "");
            $update_stmt = $conn->prepare($update_query);
            $update_stmt->bind_param("isi", $quantity_picked, $notes_update, $pick_item_id);
        } else {
            $update_query = "UPDATE pick_list_items SET quantity_picked = ? WHERE pick_item_id = ?";
            $update_stmt = $conn->prepare($update_query);
            $update_stmt->bind_param("ii", $quantity_picked, $pick_item_id);
        }
        
        if ($update_stmt->execute()) {
            // Update inventory based on the difference
            if ($quantity_difference != 0) {
                // Check if inventory table has quantity_reserved column
                $check_reserved = $conn->query("SHOW COLUMNS FROM inventory LIKE 'quantity_reserved'");
                
                if ($quantity_difference > 0) {
                    // More items picked, reduce reserved and stock
                    if ($check_reserved && $check_reserved->num_rows > 0) {
                        $update_inventory_query = "UPDATE inventory SET quantity_reserved = quantity_reserved - ? WHERE branch_id = ? AND item_id = ?";
                        $update_inventory_stmt = $conn->prepare($update_inventory_query);
                        $update_inventory_stmt->bind_param("iii", $quantity_difference, $item['branch_id'], $item['item_id']);
                        $update_inventory_stmt->execute();
                        $update_inventory_stmt->close();
                    }
                    
                    $update_items_query = "UPDATE items SET stock = stock - ? WHERE item_id = ? AND stock >= ?";
                    $update_items_stmt = $conn->prepare($update_items_query);
                    $update_items_stmt->bind_param("iii", $quantity_difference, $item['item_id'], $quantity_difference);
                    $update_items_stmt->execute();
                    $update_items_stmt->close();
                    
                    // Record inventory transaction if table exists
                    if ($inventory_transactions_exists) {
                        $trans_query = "INSERT INTO inventory_transactions 
                                       (branch_id, item_id, transaction_type, quantity_changed, reference_type, reference_id, created_by, created_at) 
                                       VALUES (?, ?, 'out', ?, 'pick_list', ?, ?, NOW())";
                        $trans_stmt = $conn->prepare($trans_query);
                        $trans_stmt->bind_param("iiiii", $item['branch_id'], $item['item_id'], $quantity_difference, $item['pick_list_id'], $user_id);
                        $trans_stmt->execute();
                        $trans_stmt->close();
                    }
                } else {
                    // Less items picked, increase reserved (return to reserved)
                    $quantity_difference_abs = abs($quantity_difference);
                    
                    if ($check_reserved && $check_reserved->num_rows > 0) {
                        $update_inventory_query = "UPDATE inventory SET quantity_reserved = quantity_reserved + ? WHERE branch_id = ? AND item_id = ?";
                        $update_inventory_stmt = $conn->prepare($update_inventory_query);
                        $update_inventory_stmt->bind_param("iii", $quantity_difference_abs, $item['branch_id'], $item['item_id']);
                        $update_inventory_stmt->execute();
                        $update_inventory_stmt->close();
                    }
                    
                    $update_items_query = "UPDATE items SET stock = stock + ? WHERE item_id = ?";
                    $update_items_stmt = $conn->prepare($update_items_query);
                    $update_items_stmt->bind_param("ii", $quantity_difference_abs, $item['item_id']);
                    $update_items_stmt->execute();
                    $update_items_stmt->close();
                    
                    // Record inventory transaction if table exists
                    if ($inventory_transactions_exists) {
                        $trans_query = "INSERT INTO inventory_transactions 
                                       (branch_id, item_id, transaction_type, quantity_changed, reference_type, reference_id, created_by, created_at) 
                                       VALUES (?, ?, 'in', ?, 'pick_list_return', ?, ?, NOW())";
                        $trans_stmt = $conn->prepare($trans_query);
                        $trans_stmt->bind_param("iiiii", $item['branch_id'], $item['item_id'], $quantity_difference_abs, $item['pick_list_id'], $user_id);
                        $trans_stmt->execute();
                        $trans_stmt->close();
                    }
                }
            }
            
            // Check if all items in pick list are picked
            $check_all_picked_query = "SELECT 
                                        SUM(quantity_to_pick) as total_to_pick,
                                        SUM(quantity_picked) as total_picked
                                      FROM pick_list_items 
                                      WHERE pick_list_id = ?";
            $check_stmt = $conn->prepare($check_all_picked_query);
            $check_stmt->bind_param("i", $item['pick_list_id']);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            $pick_totals = $check_result->fetch_assoc();
            
            if ($pick_totals['total_picked'] >= $pick_totals['total_to_pick']) {
                // Update pick list status to completed
                $update_pl_status = "UPDATE pick_lists SET pick_status = 'completed', updated_at = NOW() WHERE pick_list_id = ?";
                $pl_status_stmt = $conn->prepare($update_pl_status);
                $pl_status_stmt->bind_param("i", $item['pick_list_id']);
                $pl_status_stmt->execute();
                $pl_status_stmt->close();
                
                if ($item['so_id']) {
                    $update_so_status = "UPDATE sales_orders SET order_status = 'ready', updated_at = NOW() WHERE so_id = ?";
                    $so_status_stmt = $conn->prepare($update_so_status);
                    $so_status_stmt->bind_param("i", $item['so_id']);
                    $so_status_stmt->execute();
                    $so_status_stmt->close();
                }
                
                // CREATE DELIVERY RECORD FOR THIS PICK LIST
                createDeliveriesForPickList($conn, $item['pick_list_id'], $item['branch_id'], $user_id);
            }
            
            header('Content-Type: application/json');
            echo json_encode(['success' => true]);
            exit;
        } else {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => $conn->error]);
            exit;
        }
    }
}

// Helper function for order status badge
function getOrderStatusBadge($status) {
    switch($status) {
        case 'pending': return 'status-pending';
        case 'confirmed': return 'status-confirmed';
        case 'processing': return 'status-processing';
        case 'ready': return 'status-ready';
        case 'delivered': return 'status-delivered';
        case 'cancelled': return 'status-cancelled';
        default: return 'bg-secondary text-white';
    }
}

function getOrderStatusText($status) {
    switch($status) {
        case 'pending': return 'Pending';
        case 'confirmed': return 'Confirmed';
        case 'processing': return 'Processing';
        case 'ready': return 'Ready for Delivery';
        case 'delivered': return 'Delivered';
        case 'cancelled': return 'Cancelled';
        default: return ucfirst($status);
    }
}

// Helper function for pick status badge
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

// Helper function to format location (clean version)
function formatLocation($row) {
    $output = '';
    
    if (!empty($row['customer_latitude']) && !empty($row['customer_longitude'])) {
        $output .= '<span class="coordinate-badge"><i class="bi bi-geo-alt-fill"></i> ' . 
                   number_format($row['customer_latitude'], 6) . ', ' . 
                   number_format($row['customer_longitude'], 6) . '</span>';
        $output .= '<br><a href="https://www.google.com/maps?q=' . $row['customer_latitude'] . ',' . $row['customer_longitude'] . '" target="_blank" class="map-link">';
        $output .= '<i class="bi bi-box-arrow-up-right"></i> View Map</a>';
    }
    
    if (!empty($row['customer_address'])) {
        if (!empty($output)) $output .= '<br>';
        $output .= '<span class="address-text" title="' . htmlspecialchars($row['customer_address']) . '">';
        $output .= '<i class="bi bi-pin-map-fill"></i> ' . 
                   htmlspecialchars(substr($row['customer_address'], 0, 40)) . 
                   (strlen($row['customer_address']) > 40 ? '...' : '') . '</span>';
    }
    
    if (empty($output)) {
        $output = '<span class="text-muted fst-italic">—</span>';
    }
    
    return $output;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pick List Items - Warehouse <?php echo !$view_all_branches ? '- ' . htmlspecialchars($branch_name) : ''; ?></title>
    <link rel="icon" type="image/png" href="../Pictures/favicon-96x96.png" sizes="96x96" />
    <link rel="icon" type="image/svg+xml" href="../Pictures/favicon.svg" />
    <link rel="shortcut icon" href="../Pictures/favicon.ico" />
    <link rel="apple-touch-icon" sizes="180x180" href="../Pictures/apple-touch-icon.png" />
    <link rel="manifest" href="../Pictures/site.webmanifest" />
    <link rel="stylesheet" href="../css/warehouse.css">
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
        
        .branch-indicator i {
            margin-right: 5px;
        }
        
        .table td {
            vertical-align: middle;
        }
        
        /* Order status badges */
        .order-status-badge {
            display: inline-block;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
            min-width: 100px;
            text-align: center;
        }
        
        .status-pending { background-color: #fff3cd; color: #856404; }
        .status-confirmed { background-color: #cce5ff; color: #004085; }
        .status-processing { background-color: #b8daff; color: #004085; }
        .status-ready { background-color: #d4edda; color: #155724; }
        .status-delivered { background-color: #d1e7dd; color: #0a3622; }
        .status-cancelled { background-color: #f8d7da; color: #721c24; }
        
        /* Quantity display */
        .quantity-display {
            font-size: 14px;
            font-weight: 600;
        }
        
        /* Modal enhancements */
        .quick-pick-btn {
            transition: all 0.2s;
        }
        
        .quick-pick-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .is-invalid {
            border-color: #dc3545;
        }
        
        .is-invalid:focus {
            box-shadow: 0 0 0 0.25rem rgba(220, 53, 69, 0.25);
        }
        
        /* Location styling - clean version */
        .coordinate-badge {
            background-color: #e9ecef;
            color: #495057;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 11px;
            display: inline-block;
            margin: 2px 0;
        }
        
        .coordinate-badge i {
            color: #0d6efd;
            margin-right: 3px;
        }
        
        .address-text {
            font-size: 11px;
            color: #6c757d;
            display: inline-block;
        }
        
        .map-link {
            font-size: 10px;
            color: #0d6efd;
            text-decoration: none;
            display: inline-block;
        }
        
        .map-link:hover {
            text-decoration: underline;
        }
        
        /* Clean table styling */
        .table th {
            font-weight: 600;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            background-color: #f8f9fa;
            white-space: nowrap;
        }
        
        .table td {
            font-size: 13px;
        }
        
        /* Status group headers */
        .status-group-header {
            background-color: #e9ecef;
            font-weight: 600;
            padding: 8px 16px;
            border-left: 4px solid #0d6efd;
        }
        
        .status-group-header.pending {
            border-left-color: #ffc107;
        }
        
        .status-group-header.completed {
            border-left-color: #198754;
            margin-top: 20px;
        }
        
        .status-group-header i {
            margin-right: 8px;
        }

        /* Delivery status indicator */
        .delivery-status-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 500;
            margin-left: 5px;
        }
        
        .delivery-created {
            background-color: #d1e7dd;
            color: #0a3622;
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
                        Pick List Items
                    </h2>
                    <p>Manage pick list items with customer delivery locations</p>
                </div>
            </div>

            <?php
            // Get pick list statistics - filtered by branch
            $stats = [];
            $branch_filter = "";
            $params = [];
            $types = "";
            
            if (!$view_all_branches && $user_branch_id > 0) {
                $branch_filter = " WHERE pl.branch_id = ? ";
                $params[] = $user_branch_id;
                $types .= "i";
            }
            
            // Total items query with branch filter
            $total_items_query = "SELECT COUNT(*) as count 
                                 FROM pick_list_items pli
                                 JOIN pick_lists pl ON pli.pick_list_id = pl.pick_list_id" . $branch_filter;
            $stmt = $conn->prepare($total_items_query);
            if (!empty($params)) {
                $stmt->bind_param($types, ...$params);
            }
            $stmt->execute();
            $result = $stmt->get_result();
            $stats['total_items'] = $result->fetch_assoc()['count'] ?? 0;
            $stmt->close();
            
            // Picked items query with branch filter
            $picked_query = "SELECT COUNT(*) as count 
                            FROM pick_list_items pli
                            JOIN pick_lists pl ON pli.pick_list_id = pl.pick_list_id
                            WHERE pli.quantity_picked >= pli.quantity_to_pick" . 
                            ($branch_filter ? str_replace("WHERE", "AND", $branch_filter) : "");
            $stmt = $conn->prepare($picked_query);
            if (!empty($params)) {
                $stmt->bind_param($types, ...$params);
            }
            $stmt->execute();
            $result = $stmt->get_result();
            $stats['picked'] = $result->fetch_assoc()['count'] ?? 0;
            $stmt->close();
            
            // Pending items query with branch filter
            $pending_query = "SELECT COUNT(*) as count 
                             FROM pick_list_items pli
                             JOIN pick_lists pl ON pli.pick_list_id = pl.pick_list_id
                             WHERE pli.quantity_picked = 0" . 
                             ($branch_filter ? str_replace("WHERE", "AND", $branch_filter) : "");
            $stmt = $conn->prepare($pending_query);
            if (!empty($params)) {
                $stmt->bind_param($types, ...$params);
            }
            $stmt->execute();
            $result = $stmt->get_result();
            $stats['pending'] = $result->fetch_assoc()['count'] ?? 0;
            $stmt->close();
            
            // Total value query with branch filter
            $check_price = $conn->query("SHOW COLUMNS FROM items LIKE 'unit_price'");
            if ($check_price && $check_price->num_rows > 0) {
                $value_query = "SELECT SUM(pli.quantity_to_pick * i.unit_price) as total_value 
                               FROM pick_list_items pli
                               JOIN pick_lists pl ON pli.pick_list_id = pl.pick_list_id
                               JOIN items i ON pli.item_id = i.item_id" . $branch_filter;
                $stmt = $conn->prepare($value_query);
                if (!empty($params)) {
                    $stmt->bind_param($types, ...$params);
                }
                $stmt->execute();
                $result = $stmt->get_result();
                $total_value = $result->fetch_assoc()['total_value'] ?? 0;
                $stmt->close();
            } else {
                $total_value = 0;
            }
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

            <!-- Stats Cards -->
            <div class="row g-3 mb-4">
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

            <!-- Search and Filter - UPDATED: Driver dropdown replaces Location filter -->
            <div class="card mb-4">
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-5">
                            <div class="input-group">
                                <span class="input-group-text">
                                    <i class="bi bi-search"></i>
                                </span>
                                <input type="text" class="form-control" id="searchInput" placeholder="Search pick list or item...">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <select class="form-select" id="statusFilter">
                                <option value="">All Order Status</option>
                                <option value="pending">Pending</option>
                                <option value="confirmed">Confirmed</option>
                                <option value="processing">Processing</option>
                                <option value="ready">Ready for Delivery</option>
                                <option value="delivered">Delivered</option>
                                <option value="cancelled">Cancelled</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <select class="form-select" id="driverFilter">
                                <option value="">All Drivers</option>
                                <?php
                                // Get all drivers from current branch
                                $drivers_filter_query = "SELECT driver_id, driver_name, vehicle_plate_number 
                                                        FROM drivers 
                                                        WHERE status = 'active'";
                                
                                if (!$view_all_branches && $user_branch_id > 0) {
                                    $drivers_filter_query .= " AND branch_id = ?";
                                    $driver_stmt = $conn->prepare($drivers_filter_query . " ORDER BY driver_name");
                                    $driver_stmt->bind_param("i", $user_branch_id);
                                } else {
                                    $drivers_filter_query .= " ORDER BY driver_name";
                                    $driver_stmt = $conn->prepare($drivers_filter_query);
                                }
                                
                                $driver_stmt->execute();
                                $drivers_result = $driver_stmt->get_result();
                                
                                if ($drivers_result->num_rows > 0) {
                                    while($driver = $drivers_result->fetch_assoc()) {
                                        $vehicle_info = !empty($driver['vehicle_plate_number']) ? ' - ' . $driver['vehicle_plate_number'] : '';
                                        echo '<option value="' . $driver['driver_id'] . '">' . 
                                             htmlspecialchars($driver['driver_name'] . $vehicle_info) . '</option>';
                                    }
                                } else {
                                    echo '<option value="" disabled>No drivers available</option>';
                                }
                                $driver_stmt->close();
                                ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <button class="btn btn-outline-secondary w-100" onclick="clearFilters()">
                                <i class="bi bi-x-circle"></i> Clear
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Clean Pick List Items Table - Location bin removed, customer location only -->
            <div class="card">
                <div class="table-container">
                    <table class="table custom-table compact-table">
                        <thead>
                            <tr>
                                <th>Pick List</th>
                                <th>Item</th>
                                <th class="text-center">To Pick</th>
                                <th class="text-center">Picked</th>
                                <th>Assigned Driver</th>
                                <th>Delivery Location</th>
                                <th>Order Status</th>
                                <?php if ($view_all_branches): ?>
                                    <th>Branch</th>
                                <?php endif; ?>
                                <th class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            // Get pick list items with all necessary data
                            $pick_list_items_query = "SELECT 
                                pli.pick_item_id,
                                pli.pick_list_id,
                                pli.item_id,
                                pli.quantity_to_pick,
                                pli.quantity_picked,
                                pli.location_bin,
                                pl.pick_list_number, 
                                pl.pick_status as pick_list_status,
                                pl.branch_id,
                                pl.so_id,
                                b.branch_name,
                                i.item_name, 
                                i.item_code,
                                i.unit_price,
                                i.price_case,
                                i.price_inner_pack,
                                i.price_box,
                                i.price_carton,
                                d.driver_id,
                                d.driver_name,
                                d.vehicle_plate_number,
                                so.order_status,
                                so.so_number,
                                so.total_amount,
                                c.customer_name,
                                c.latitude as customer_latitude,
                                c.longitude as customer_longitude,
                                c.full_address as customer_address,
                                c.delivery_instructions
                            FROM pick_list_items pli
                            INNER JOIN pick_lists pl ON pli.pick_list_id = pl.pick_list_id
                            INNER JOIN branches b ON pl.branch_id = b.branch_id
                            INNER JOIN items i ON pli.item_id = i.item_id
                            LEFT JOIN drivers d ON pl.driver_id = d.driver_id
                            LEFT JOIN sales_orders so ON pl.so_id = so.so_id
                            LEFT JOIN customers c ON so.customer_id = c.customer_id";

                            if (!$view_all_branches && $user_branch_id > 0) {
                                $pick_list_items_query .= " WHERE pl.branch_id = ?";
                                $pick_list_items_query .= " ORDER BY 
                                    CASE 
                                        WHEN pl.pick_status IN ('open', 'in-progress') THEN 1
                                        WHEN pl.pick_status = 'completed' THEN 2
                                        ELSE 3
                                    END,
                                    pli.pick_item_id DESC";
                                
                                $stmt = $conn->prepare($pick_list_items_query);
                                $stmt->bind_param("i", $user_branch_id);
                            } else {
                                $pick_list_items_query .= " ORDER BY 
                                    CASE 
                                        WHEN pl.pick_status IN ('open', 'in-progress') THEN 1
                                        WHEN pl.pick_status = 'completed' THEN 2
                                        ELSE 3
                                    END,
                                    pli.pick_item_id DESC";
                                
                                $stmt = $conn->prepare($pick_list_items_query);
                            }
                            
                            $stmt->execute();
                            $result = $stmt->get_result();
                            
                            if ($result && $result->num_rows > 0) {
                                $current_status_group = '';
                                while($row = $result->fetch_assoc()) {
                                    $has_coordinates = !empty($row['customer_latitude']) && !empty($row['customer_longitude']);
                                    $has_address = !empty($row['customer_address']);
                                    
                                    // Add status group header when status changes
                                    $status_group = '';
                                    if (in_array($row['pick_list_status'], ['open', 'in-progress'])) {
                                        $status_group = 'pending';
                                    } elseif ($row['pick_list_status'] == 'completed') {
                                        $status_group = 'completed';
                                    }
                                    
                                    ?>
                                    <tr data-order-status="<?php echo $row['order_status'] ?? ''; ?>"
                                        data-driver-id="<?php echo $row['driver_id'] ?? ''; ?>"
                                        data-location-type="<?php 
                                            if ($has_coordinates) echo 'coordinates';
                                            elseif ($has_address) echo 'address';
                                            else echo 'none';
                                        ?>">
                                        <td>
                                            <span class="fw-semibold"><?php echo htmlspecialchars($row['pick_list_number']); ?></span>
                                            <?php if (!empty($row['pick_list_status'])): ?>
                                                <br><small class="badge <?php echo getPickStatusBadge($row['pick_list_status']); ?>" style="font-size: 10px;">
                                                    <?php echo getPickStatusText($row['pick_list_status']); ?>
                                                </small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="fw-semibold"><?php echo htmlspecialchars($row['item_name']); ?></div>
                                            <small class="text-muted"><?php echo htmlspecialchars($row['item_code']); ?></small>
                                            <?php if (!empty($row['notes'])): ?>
                                                <br><small class="text-info"><i class="bi bi-info-circle"></i> <?php echo htmlspecialchars(substr($row['notes'], 0, 30)) . (strlen($row['notes']) > 30 ? '...' : ''); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center"><?php echo number_format($row['quantity_to_pick']); ?></td>
                                        <td class="text-center"><?php echo number_format($row['quantity_picked']); ?></td>
                                        <td>
                                            <?php if (!empty($row['driver_name'])): ?>
                                                    <?php echo htmlspecialchars($row['driver_name']); ?>
                                                </span>
                                                <?php if (!empty($row['vehicle_plate_number'])): ?>
                                                    <br><small class="text-muted"><?php echo htmlspecialchars($row['vehicle_plate_number']); ?></small>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="max-width: 250px;">
                                            <?php echo formatLocation($row); ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($row['order_status'])): ?>
                                                <span class="order-status-badge <?php echo getOrderStatusBadge($row['order_status']); ?>">
                                                    <?php echo getOrderStatusText($row['order_status']); ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <?php if ($view_all_branches): ?>
                                            <td>
                                                <span class="badge bg-info text-dark"><?php echo htmlspecialchars($row['branch_name']); ?></span>
                                            </td>
                                        <?php endif; ?>
                                        <td class="text-center">
                                            <div class="btn-group btn-group-sm" role="group">
                                                <button class="btn btn-outline-info" data-bs-toggle="modal" data-bs-target="#viewItemModal" 
                                                        onclick="loadPickItemDetails(<?php echo $row['pick_item_id']; ?>)" title="View Details">
                                                    <i class="bi bi-eye"></i>
                                                </button>
                                                <?php if (!isset($row['order_status']) || ($row['order_status'] != 'delivered' && $row['order_status'] != 'cancelled')): ?>
                                                <button class="btn btn-outline-warning" data-bs-toggle="modal" data-bs-target="#updatePickModal"
                                                        onclick="setUpdatePickItem(
                                                            <?php echo $row['pick_item_id']; ?>, 
                                                            <?php echo $row['quantity_to_pick']; ?>, 
                                                            <?php echo $row['quantity_picked']; ?>, 
                                                            '<?php echo addslashes($row['item_name']); ?>', 
                                                            '<?php echo addslashes($row['item_code']); ?>'
                                                        )" title="Update Picked Quantity">
                                                    <i class="bi bi-pencil"></i>
                                                </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php
                                }
                            } else {
                                $colspan = $view_all_branches ? 9 : 8;
                                echo '<tr><td colspan="' . $colspan . '" class="text-center py-5 text-muted">';
                                echo '<i class="bi bi-inbox fs-1 d-block mb-3"></i>';
                                echo '<p>No pick list items found</p>';
                                echo '</td></tr>';
                            }
                            $stmt->close();
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
                                    $pick_lists_query = "SELECT pl.pick_list_id, pl.pick_list_number, b.branch_name, 
                                                                d.driver_name
                                                        FROM pick_lists pl
                                                        JOIN branches b ON pl.branch_id = b.branch_id
                                                        LEFT JOIN drivers d ON pl.driver_id = d.driver_id
                                                        WHERE pl.pick_status IN ('open', 'in-progress')";
                                    
                                    if (!$view_all_branches && $user_branch_id > 0) {
                                        $pick_lists_query .= " AND pl.branch_id = ?";
                                        $pl_stmt = $conn->prepare($pick_lists_query . " ORDER BY pl.created_at DESC");
                                        $pl_stmt->bind_param("i", $user_branch_id);
                                    } else {
                                        $pl_stmt = $conn->prepare($pick_lists_query . " ORDER BY pl.created_at DESC");
                                    }
                                    
                                    $pl_stmt->execute();
                                    $result = $pl_stmt->get_result();
                                    
                                    if ($result->num_rows > 0) {
                                        while($pick_list = $result->fetch_assoc()) {
                                            $driver_info = $pick_list['driver_name'] ? ' - Driver: ' . $pick_list['driver_name'] : '';
                                            echo '<option value="' . $pick_list['pick_list_id'] . '">' . 
                                                 $pick_list['pick_list_number'] . ' - ' . $pick_list['branch_name'] . 
                                                 $driver_info . '</option>';
                                        }
                                    } else {
                                        echo '<option value="">No active pick lists available for your branch</option>';
                                    }
                                    $pl_stmt->close();
                                    ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Item <span class="text-danger">*</span></label>
                                <select class="form-select" name="item_id" required>
                                    <option value="">Select Item</option>
                                    <?php
                                    $items_query = "SELECT item_id, item_name, item_code FROM items WHERE status = 'active'";
                                    
                                    // Filter items by branch if items table has branch_id column
                                    if (!$view_all_branches && $user_branch_id > 0 && $items_branch_column_exists) {
                                        $items_query .= " AND branch_id = ?";
                                        $item_stmt = $conn->prepare($items_query . " ORDER BY item_name");
                                        $item_stmt->bind_param("i", $user_branch_id);
                                    } else {
                                        $item_stmt = $conn->prepare($items_query . " ORDER BY item_name");
                                    }
                                    
                                    $item_stmt->execute();
                                    $result = $item_stmt->get_result();
                                    
                                    while($item = $result->fetch_assoc()) {
                                        echo '<option value="' . $item['item_id'] . '">' . 
                                             $item['item_code'] . ' - ' . $item['item_name'] . '</option>';
                                    }
                                    $item_stmt->close();
                                    ?>
                                </select>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Quantity to Pick <span class="text-danger">*</span></label>
                                <input type="number" class="form-control" name="quantity_to_pick" required min="1" value="1">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Location Bin (Optional)</label>
                                <input type="text" class="form-control" name="location_bin" placeholder="e.g., A-12, B-05">
                            </div>
                        </div>
                        
                        <div class="alert alert-info mt-3">
                            <i class="bi bi-info-circle me-2"></i>
                            <small>Customer delivery location will be automatically associated through the sales order.</small>
                        </div>
                        
                        <?php if ($price_case_exists || $price_inner_exists || $price_box_exists || $price_carton_exists): ?>
                        <div class="alert alert-light mt-2">
                            <i class="bi bi-tag"></i> 
                            <small>Multi-unit pricing is available for this item (Case, Inner Pack, Box, Carton).</small>
                        </div>
                        <?php endif; ?>
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
                                                          WHERE so.order_status IN ('confirmed', 'processing')";
                                    
                                    if (!$view_all_branches && $user_branch_id > 0) {
                                        $sales_orders_query .= " AND so.branch_id = ?";
                                        $so_stmt = $conn->prepare($sales_orders_query . " ORDER BY so.order_date DESC");
                                        $so_stmt->bind_param("i", $user_branch_id);
                                    } else {
                                        $so_stmt = $conn->prepare($sales_orders_query . " ORDER BY so.order_date DESC");
                                    }
                                    
                                    $so_stmt->execute();
                                    $result = $so_stmt->get_result();
                                    
                                    while($so = $result->fetch_assoc()) {
                                        echo '<option value="' . $so['so_id'] . '">' . 
                                             $so['so_number'] . ' - ' . $so['customer_name'] . '</option>';
                                    }
                                    $so_stmt->close();
                                    ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Branch</label>
                                <select class="form-select" name="branch_id" required <?php echo !$view_all_branches ? 'disabled' : ''; ?>>
                                    <option value="">Select Branch</option>
                                    <?php
                                    $branches_query = "SELECT branch_id, branch_name FROM branches WHERE status = 'active'";
                                    
                                    if (!$view_all_branches && $user_branch_id > 0) {
                                        $branches_query .= " AND branch_id = ?";
                                        $branch_stmt = $conn->prepare($branches_query);
                                        $branch_stmt->bind_param("i", $user_branch_id);
                                        $branch_stmt->execute();
                                        $result = $branch_stmt->get_result();
                                    } else {
                                        $result = $conn->query($branches_query);
                                    }
                                    
                                    while($branch = $result->fetch_assoc()) {
                                        $selected = (!$view_all_branches && $branch['branch_id'] == $user_branch_id) ? 'selected' : '';
                                        echo '<option value="' . $branch['branch_id'] . '" ' . $selected . '>' . $branch['branch_name'] . '</option>';
                                    }
                                    
                                    if (!$view_all_branches && $user_branch_id > 0) {
                                        $branch_stmt->close();
                                    }
                                    ?>
                                </select>
                                <?php if (!$view_all_branches): ?>
                                    <input type="hidden" name="branch_id" value="<?php echo $user_branch_id; ?>">
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Assign Driver</label>
                                <select class="form-select" name="driver_id">
                                    <option value="">Select Driver (Optional)</option>
                                    <?php
                                    $drivers_query = "SELECT driver_id, driver_name, vehicle_plate_number 
                                                      FROM drivers WHERE status = 'active'";
                                    
                                    if (!$view_all_branches && $user_branch_id > 0) {
                                        $drivers_query .= " AND branch_id = ?";
                                        $driver_stmt = $conn->prepare($drivers_query . " ORDER BY driver_name");
                                        $driver_stmt->bind_param("i", $user_branch_id);
                                    } else {
                                        $driver_stmt = $conn->prepare($drivers_query . " ORDER BY driver_name");
                                    }
                                    
                                    $driver_stmt->execute();
                                    $drivers_result = $driver_stmt->get_result();
                                    
                                    while($driver = $drivers_result->fetch_assoc()) {
                                        echo '<option value="' . $driver['driver_id'] . '">' . 
                                             htmlspecialchars($driver['driver_name']) . ' - ' . 
                                             htmlspecialchars($driver['vehicle_plate_number'] ?? 'No plate') . '</option>';
                                    }
                                    $driver_stmt->close();
                                    ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Pick Date</label>
                                <input type="date" class="form-control" name="pick_date" value="<?php echo date('Y-m-d'); ?>">
                            </div>
                        </div>
                        
                        <?php if ($deliveries_table_exists): ?>
                        <div class="alert alert-success mt-3">
                            <i class="bi bi-truck"></i>
                            <small>Delivery records will be automatically created when pick list is completed.</small>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success">Create Pick List</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Update Pick Quantity Modal - Enhanced Version -->
    <div class="modal fade" id="updatePickModal" tabindex="-1" aria-labelledby="updatePickModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title" id="updatePickModalLabel">
                        <i class="bi bi-pencil-square me-2"></i>Update Picked Quantity
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="updatePickForm" method="POST">
                    <input type="hidden" name="action" value="update_pick_quantity">
                    <input type="hidden" name="pick_item_id" id="update_pick_item_id">
                    <input type="hidden" name="original_quantity_picked" id="original_quantity_picked">
                    
                    <div class="modal-body">
                        <!-- Item Information Summary -->
                        <div class="alert alert-info mb-3" id="itemSummaryInfo">
                            <div class="d-flex align-items-center">
                                <i class="bi bi-box-seam fs-4 me-3"></i>
                                <div>
                                    <strong id="summaryItemName">Loading...</strong><br>
                                    <span id="summaryItemCode" class="text-muted"></span>
                                </div>
                            </div>
                        </div>

                        <!-- Quick Pick Buttons -->
                        <div class="btn-group w-100 mb-3" role="group" aria-label="Quick pick buttons">
                            <button type="button" class="btn btn-outline-primary quick-pick-btn" onclick="quickPick(25)">25%</button>
                            <button type="button" class="btn btn-outline-primary quick-pick-btn" onclick="quickPick(50)">50%</button>
                            <button type="button" class="btn btn-outline-primary quick-pick-btn" onclick="quickPick(75)">75%</button>
                            <button type="button" class="btn btn-outline-primary quick-pick-btn" onclick="quickPick(100)">100%</button>
                        </div>

                        <!-- Quantity Information -->
                        <div class="card bg-light mb-3">
                            <div class="card-body p-3">
                                <div class="row">
                                    <div class="col-6">
                                        <label class="form-label text-muted mb-1">Quantity to Pick</label>
                                        <div class="h4 mb-0" id="update_quantity_to_pick">0</div>
                                    </div>
                                    <div class="col-6">
                                        <label class="form-label text-muted mb-1">Previously Picked</label>
                                        <div class="h4 mb-0" id="previously_picked">0</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Current Picked Quantity Input -->
                        <div class="mb-3">
                            <label for="update_quantity_picked" class="form-label fw-bold">
                                Current Picked Quantity <span class="text-danger">*</span>
                            </label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-123"></i></span>
                                <input type="number" class="form-control form-control-lg" 
                                       name="quantity_picked" id="update_quantity_picked" 
                                       placeholder="Enter picked quantity" min="0" required>
                            </div>
                            <div class="form-text" id="quantityHelpText">
                                Enter the quantity that has been picked up
                            </div>
                        </div>

                        <!-- Validation Messages -->
                        <div class="alert alert-warning alert-dismissible fade show" id="quantityWarning" style="display: none;">
                            <i class="bi bi-exclamation-triangle me-2"></i>
                            <span id="warningMessage"></span>
                        </div>

                        <!-- Progress Bar for Pick Completion -->
                        <div class="mb-3" id="progressContainer" style="display: none;">
                            <label class="form-label">Pick Completion Progress</label>
                            <div class="progress" style="height: 20px;">
                                <div class="progress-bar bg-success" id="pickProgress" role="progressbar" style="width: 0%;" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100">0%</div>
                            </div>
                        </div>

                        <!-- Additional Notes -->
                        <div class="mb-3">
                            <label for="pick_notes" class="form-label">Notes (Optional)</label>
                            <textarea class="form-control" id="pick_notes" name="pick_notes" 
                                      rows="2" placeholder="Add any notes about the picked items..."></textarea>
                        </div>
                        
                        <!-- Auto-create delivery notification -->
                        <?php if ($deliveries_table_exists): ?>
                        <div class="alert alert-success mt-3" id="deliveryNotice" style="display: none;">
                            <i class="bi bi-check-circle-fill me-2"></i>
                            When all items are picked, a delivery record will be automatically created for the assigned driver.
                        </div>
                        <?php endif; ?>
                        
                        <!-- Price information (if available) -->
                        <?php if ($price_case_exists || $price_inner_exists || $price_box_exists || $price_carton_exists): ?>
                        <div class="alert alert-light mt-2">
                            <i class="bi bi-tag"></i> 
                            <small>Multi-unit pricing is configured for this item.</small>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Action Buttons -->
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="bi bi-x-circle me-1"></i>Cancel
                        </button>
                        <button type="submit" class="btn btn-warning" id="submitUpdateBtn">
                            <i class="bi bi-check-circle me-1"></i>Update Quantity
                        </button>
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

        // Enhanced function to set values for update pick modal
        function setUpdatePickItem(pickItemId, quantityToPick, quantityPicked, itemName, itemCode) {
            console.log('Setting update for pick item:', pickItemId, quantityToPick, quantityPicked);
            
            if (!document.getElementById('update_pick_item_id')) {
                console.error('Update modal elements not found');
                return;
            }
            
            document.getElementById('update_pick_item_id').value = pickItemId;
            document.getElementById('update_quantity_to_pick').textContent = quantityToPick;
            document.getElementById('previously_picked').textContent = quantityPicked;
            document.getElementById('original_quantity_picked').value = quantityPicked;
            
            if (document.getElementById('summaryItemName')) {
                document.getElementById('summaryItemName').textContent = itemName || 'Item Name';
            }
            if (document.getElementById('summaryItemCode')) {
                document.getElementById('summaryItemCode').textContent = itemCode || '';
            }
            
            const quantityInput = document.getElementById('update_quantity_picked');
            if (quantityInput) {
                quantityInput.value = quantityPicked;
                quantityInput.max = quantityToPick;
                quantityInput.min = 0;
                quantityInput.setAttribute('data-max', quantityToPick);
                quantityInput.setAttribute('data-current', quantityPicked);
            }
            
            const warningDiv = document.getElementById('quantityWarning');
            if (warningDiv) {
                warningDiv.style.display = 'none';
            }
            
            const remainingToPick = quantityToPick - quantityPicked;
            const helpText = document.getElementById('quantityHelpText');
            if (helpText) {
                helpText.innerHTML = `Remaining to pick: <strong>${remainingToPick}</strong> of ${quantityToPick}`;
            }
            
            // Show delivery notice if this might complete the pick list
            const deliveryNotice = document.getElementById('deliveryNotice');
            if (deliveryNotice) {
                if (quantityPicked < quantityToPick) {
                    deliveryNotice.style.display = 'block';
                } else {
                    deliveryNotice.style.display = 'none';
                }
            }
            
            updateProgressBar(quantityPicked, quantityToPick);
            
            const notesField = document.getElementById('pick_notes');
            if (notesField) {
                notesField.value = '';
            }
        }

        function updateProgressBar(current, total) {
            const progressContainer = document.getElementById('progressContainer');
            const progressBar = document.getElementById('pickProgress');
            
            if (progressContainer && progressBar && total > 0) {
                const percentage = Math.round((current / total) * 100);
                progressBar.style.width = percentage + '%';
                progressBar.textContent = percentage + '%';
                progressBar.setAttribute('aria-valuenow', percentage);
                progressContainer.style.display = 'block';
            }
        }

        function quickPick(percentage) {
            const quantityToPick = parseInt(document.getElementById('update_quantity_to_pick').textContent);
            const quantityPicked = document.getElementById('update_quantity_picked');
            
            if (quantityPicked && quantityToPick) {
                const value = Math.floor(quantityToPick * (percentage / 100));
                quantityPicked.value = value;
                validateQuantity();
                updateProgressBar(value, quantityToPick);
                
                // Update delivery notice
                const deliveryNotice = document.getElementById('deliveryNotice');
                if (deliveryNotice) {
                    if (value < quantityToPick) {
                        deliveryNotice.style.display = 'block';
                    } else {
                        deliveryNotice.style.display = 'none';
                    }
                }
            }
        }

        function validateQuantity() {
            const quantityPicked = document.getElementById('update_quantity_picked');
            const quantityToPick = parseInt(document.getElementById('update_quantity_to_pick').textContent);
            const warningDiv = document.getElementById('quantityWarning');
            const warningMessage = document.getElementById('warningMessage');
            const submitBtn = document.getElementById('submitUpdateBtn');
            
            if (quantityPicked && warningDiv && warningMessage) {
                const value = parseInt(quantityPicked.value);
                
                if (isNaN(value) || value < 0) {
                    warningDiv.style.display = 'block';
                    warningMessage.textContent = 'Please enter a valid quantity (minimum 0)';
                    quantityPicked.classList.add('is-invalid');
                    if (submitBtn) submitBtn.disabled = true;
                } else if (value > quantityToPick) {
                    warningDiv.style.display = 'block';
                    warningMessage.textContent = `Quantity picked (${value}) cannot exceed quantity to pick (${quantityToPick})`;
                    quantityPicked.classList.add('is-invalid');
                    if (submitBtn) submitBtn.disabled = true;
                } else {
                    warningDiv.style.display = 'none';
                    quantityPicked.classList.remove('is-invalid');
                    if (submitBtn) submitBtn.disabled = false;
                }
                
                const remainingToPick = quantityToPick - value;
                const helpText = document.getElementById('quantityHelpText');
                if (helpText) {
                    helpText.innerHTML = `Remaining to pick: <strong>${remainingToPick}</strong> of ${quantityToPick}`;
                }
                
                updateProgressBar(value, quantityToPick);
            }
        }

        function loadPickItemDetails(pickItemId) {
            const pickItemDetailsContent = document.getElementById('pickItemDetailsContent');
            if (pickItemDetailsContent) {
                pickItemDetailsContent.innerHTML = '<div class="text-center py-4"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div><p class="mt-2">Loading item details...</p></div>';
            }
            
            fetch('get_pick_item_details.php?pick_item_id=' + pickItemId)
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Network response was not ok');
                    }
                    return response.text();
                })
                .then(data => {
                    if (pickItemDetailsContent) {
                        pickItemDetailsContent.innerHTML = data;
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    if (pickItemDetailsContent) {
                        pickItemDetailsContent.innerHTML = '<div class="alert alert-danger"><i class="bi bi-exclamation-triangle"></i> Failed to load item details. Please try again.</div>';
                    }
                });
        }

        // UPDATED filterTable function to include driver filter
        function filterTable() {
            const searchInput = document.getElementById('searchInput');
            const statusFilter = document.getElementById('statusFilter');
            const driverFilter = document.getElementById('driverFilter');
            const rows = document.querySelectorAll('tbody tr');
            
            const searchText = searchInput ? searchInput.value.toLowerCase() : '';
            const statusValue = statusFilter ? statusFilter.value : '';
            const driverValue = driverFilter ? driverFilter.value : '';
            
            let visibleCount = 0;
            
            rows.forEach(row => {
                // Skip header rows
                if (row.classList.contains('status-group-header')) {
                    return;
                }
                
                let showRow = true;
                
                if (searchText) {
                    const rowText = row.textContent.toLowerCase();
                    showRow = rowText.includes(searchText);
                }
                
                if (showRow && statusValue) {
                    const orderStatus = row.dataset.orderStatus;
                    showRow = orderStatus === statusValue;
                }
                
                if (showRow && driverValue) {
                    const driverId = row.dataset.driverId;
                    showRow = driverId === driverValue;
                }
                
                row.style.display = showRow ? '' : 'none';
                if (showRow) visibleCount++;
            });
            
            const itemCount = document.getElementById('itemCount');
            if (itemCount) {
                itemCount.textContent = visibleCount + ' items';
            }
        }

        function clearFilters() {
            const searchInput = document.getElementById('searchInput');
            const statusFilter = document.getElementById('statusFilter');
            const driverFilter = document.getElementById('driverFilter');
            
            if (searchInput) searchInput.value = '';
            if (statusFilter) statusFilter.value = '';
            if (driverFilter) driverFilter.value = '';
            
            filterTable();
        }

        function logout() {
            if (confirm('Are you sure you want to logout?')) {
                window.location.href = '../logout.php';
            }
        }

        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            console.log("Pick List Management - Auto-create Delivery on Complete");
            
            initializeSidebar();
            
            const mobileToggleBtn = document.getElementById('mobileToggleBtn');
            if (mobileToggleBtn) {
                mobileToggleBtn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    toggleSidebar();
                });
            }
            
            const desktopToggleBtn = document.getElementById('desktopToggleBtn');
            if (desktopToggleBtn) {
                desktopToggleBtn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    toggleSidebar();
                });
            }
            
            document.querySelectorAll('.sidebar .nav-link').forEach(link => {
                link.addEventListener('click', function() {
                    if (window.innerWidth <= 992) {
                        closeMobileSidebar();
                    }
                });
            });
            
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

            window.addEventListener('resize', handleSidebarResize);

            const quantityPicked = document.getElementById('update_quantity_picked');
            if (quantityPicked) {
                quantityPicked.addEventListener('input', validateQuantity);
                quantityPicked.addEventListener('change', validateQuantity);
            }
            
            const updatePickForm = document.getElementById('updatePickForm');
            if (updatePickForm) {
                updatePickForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    
                    const quantityPicked = document.getElementById('update_quantity_picked');
                    const quantityToPick = parseInt(document.getElementById('update_quantity_to_pick').textContent);
                    
                    if (!quantityPicked.value) {
                        alert('Please enter the picked quantity');
                        quantityPicked.focus();
                        return false;
                    }
                    
                    const pickedValue = parseInt(quantityPicked.value);
                    if (isNaN(pickedValue) || pickedValue < 0) {
                        alert('Please enter a valid quantity (minimum 0)');
                        quantityPicked.focus();
                        return false;
                    }
                    
                    if (pickedValue > quantityToPick) {
                        alert(`Quantity picked cannot exceed ${quantityToPick}`);
                        quantityPicked.focus();
                        return false;
                    }
                    
                    let confirmMessage = 'Are you sure you want to update the picked quantity? This will adjust inventory levels.';
                    
                    // Check if this will complete the pick list
                    if (pickedValue >= quantityToPick && quantityPicked.value != quantityPicked.getAttribute('data-current')) {
                        confirmMessage = 'This will complete the pick list and create a delivery record for the assigned driver. Continue?';
                    }
                    
                    if (!confirm(confirmMessage)) {
                        return false;
                    }
                    
                    const formData = new FormData(this);
                    
                    fetch(window.location.href, {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            alert('Pick quantity updated successfully! Delivery record will be created if all items are picked.');
                            window.location.reload();
                        } else {
                            alert('Error: ' + (data.message || 'Unknown error occurred'));
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        alert('Failed to update pick quantity. Please try again.');
                    });
                });
            }

            const updatePickModal = document.getElementById('updatePickModal');
            if (updatePickModal) {
                updatePickModal.addEventListener('hidden.bs.modal', function() {
                    const warningDiv = document.getElementById('quantityWarning');
                    const quantityInput = document.getElementById('update_quantity_picked');
                    const submitBtn = document.getElementById('submitUpdateBtn');
                    const progressContainer = document.getElementById('progressContainer');
                    const deliveryNotice = document.getElementById('deliveryNotice');
                    
                    if (warningDiv) warningDiv.style.display = 'none';
                    if (quantityInput) quantityInput.classList.remove('is-invalid');
                    if (submitBtn) submitBtn.disabled = false;
                    if (progressContainer) progressContainer.style.display = 'none';
                    if (deliveryNotice) deliveryNotice.style.display = 'none';
                });
            }

            const searchInput = document.getElementById('searchInput');
            const statusFilter = document.getElementById('statusFilter');
            const driverFilter = document.getElementById('driverFilter');
            
            if (searchInput) searchInput.addEventListener('keyup', filterTable);
            if (statusFilter) statusFilter.addEventListener('change', filterTable);
            if (driverFilter) driverFilter.addEventListener('change', filterTable);

            filterTable();

            document.addEventListener('keydown', function(e) {
                if (e.ctrlKey && e.key === 'b' && window.innerWidth > 992) {
                    e.preventDefault();
                    toggleSidebar();
                }
                else if (e.key === 'Escape' && window.innerWidth <= 992) {
                    closeMobileSidebar();
                }
                else if (e.ctrlKey && e.key === 'f') {
                    e.preventDefault();
                    const searchInput = document.getElementById('searchInput');
                    if (searchInput) searchInput.focus();
                }
                else if (e.ctrlKey && e.key === 'c') {
                    e.preventDefault();
                    clearFilters();
                }
            });
        });
    </script>
</body>
</html>