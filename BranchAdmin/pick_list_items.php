<?php
require_once '../config/database.php';
require_once '../config/session_handler.php';

// ========== GET USER SESSION INFO ==========
$user_id = $_SESSION['user_id'] ?? 0;
$user_name = isset($_SESSION['first_name']) ? $_SESSION['first_name'] . ' ' . $_SESSION['last_name'] : 'User';
$user_role = $_SESSION['role'] ?? 'branch_admin';
$branch_id = $_SESSION['branch_id'] ?? 0;
$view_all_branches = $_SESSION['view_all_branches'] ?? false;

// ========== CHECK DATABASE COLUMNS ==========
// Check if branch_id column exists in pick_lists table
$pick_lists_branch_column_exists = false;
$check_pl_column = $conn->query("SHOW COLUMNS FROM pick_lists LIKE 'branch_id'");
if ($check_pl_column && $check_pl_column->num_rows > 0) {
    $pick_lists_branch_column_exists = true;
}

// Check if branch_id column exists in sales_orders table
$sales_orders_branch_column_exists = false;
$check_so_column = $conn->query("SHOW COLUMNS FROM sales_orders LIKE 'branch_id'");
if ($check_so_column && $check_so_column->num_rows > 0) {
    $sales_orders_branch_column_exists = true;
}

// Check if branch_id column exists in items table
$items_branch_column_exists = false;
$check_item_column = $conn->query("SHOW COLUMNS FROM items LIKE 'branch_id'");
if ($check_item_column && $check_item_column->num_rows > 0) {
    $items_branch_column_exists = true;
}

// Check if branch_id column exists in drivers table
$drivers_branch_column_exists = false;
$check_drivers_column = $conn->query("SHOW COLUMNS FROM drivers LIKE 'branch_id'");
if ($check_drivers_column && $check_drivers_column->num_rows > 0) {
    $drivers_branch_column_exists = true;
}

// ========== BRANCH FILTER CONDITIONS ==========
$pick_lists_branch_condition = "";
$sales_orders_branch_condition = "";
$items_branch_condition = "";
$drivers_branch_condition = "";

if ($pick_lists_branch_column_exists && !$view_all_branches) {
    $pick_lists_branch_condition = "AND pl.branch_id = $branch_id";
}

if ($sales_orders_branch_column_exists && !$view_all_branches) {
    $sales_orders_branch_condition = "AND so.branch_id = $branch_id";
}

if ($items_branch_column_exists && !$view_all_branches) {
    $items_branch_condition = "AND i.branch_id = $branch_id";
}

if ($drivers_branch_column_exists && !$view_all_branches) {
    $drivers_branch_condition = "AND branch_id = $branch_id";
}

// Get branch name for display
$branch_name = 'All Branches';
$branch_display_id = 0;
if (!$view_all_branches) {
    $branch_display_id = $branch_id;
    $branch_query = "SELECT branch_name FROM branches WHERE branch_id = ?";
    $branch_stmt = $conn->prepare($branch_query);
    $branch_stmt->bind_param("i", $branch_id);
    $branch_stmt->execute();
    $branch_result = $branch_stmt->get_result();
    if ($branch_row = $branch_result->fetch_assoc()) {
        $branch_name = $branch_row['branch_name'];
    }
}

// Get base64 encoded logo for printing
$logo_path = '../Pictures/amgc3DLogo.png';
$logo_base64 = '';
if (file_exists($logo_path)) {
    $image_data = file_get_contents($logo_path);
    $logo_base64 = 'data:image/png;base64,' . base64_encode($image_data);
}

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
            
            // Verify user has permission to access this sales order
            if ($sales_orders_branch_column_exists && !$view_all_branches) {
                $check_so_query = "SELECT so_id, branch_id FROM sales_orders WHERE so_id = ?";
                $check_so_stmt = $conn->prepare($check_so_query);
                $check_so_stmt->bind_param("i", $so_id);
                $check_so_stmt->execute();
                $so_result = $check_so_stmt->get_result();
                $so_data = $so_result->fetch_assoc();
                
                if (!$so_data) {
                    throw new Exception('Sales order not found');
                }
                
                if ($so_data['branch_id'] != $branch_id) {
                    throw new Exception('You can only process orders from your assigned branch');
                }
            }
            
            // Verify user has permission to access this item
            if ($items_branch_column_exists && !$view_all_branches) {
                $check_item_query = "SELECT item_id, branch_id, stock FROM items WHERE item_id = ? FOR UPDATE";
                $check_item_stmt = $conn->prepare($check_item_query);
                $check_item_stmt->bind_param("i", $item_id);
                $check_item_stmt->execute();
                $item_result = $check_item_stmt->get_result();
                $item_data = $item_result->fetch_assoc();
                
                if (!$item_data) {
                    throw new Exception('Item not found');
                }
                
                if ($item_data['branch_id'] != $branch_id) {
                    throw new Exception('You can only pick items from your assigned branch');
                }
            } else {
                // Just check stock without branch verification
                $check_stock_query = "SELECT stock FROM items WHERE item_id = ? FOR UPDATE";
                $check_stock_stmt = $conn->prepare($check_stock_query);
                $check_stock_stmt->bind_param("i", $item_id);
                $check_stock_stmt->execute();
                $stock_result = $check_stock_stmt->get_result();
                $item_data = $stock_result->fetch_assoc();
            }
            
            if (!$item_data || $item_data['stock'] < $quantity_to_pick) {
                throw new Exception('Insufficient stock available. Current stock: ' . ($item_data['stock'] ?? 0));
            }
            
            // Use user's branch ID
            $branch_id_for_insert = $branch_id;
            
            // Generate pick list number with branch prefix
            $branch_prefix = $view_all_branches ? 'ADMIN' : 'B' . str_pad($branch_id, 2, '0', STR_PAD_LEFT);
            $pick_list_number = $branch_prefix . '-PL-' . date('Ymd') . '-' . rand(1000, 9999);
            
            // Check if pick list exists for this SO
            $check_pl_query = "SELECT pick_list_id FROM pick_lists WHERE so_id = ? AND pick_status IN ('open', 'in-progress')";
            
            if ($pick_lists_branch_column_exists && !$view_all_branches) {
                $check_pl_query .= " AND branch_id = ?";
                $check_pl_stmt = $conn->prepare($check_pl_query);
                $check_pl_stmt->bind_param("ii", $so_id, $branch_id);
            } else {
                $check_pl_stmt = $conn->prepare($check_pl_query);
                $check_pl_stmt->bind_param("i", $so_id);
            }
            
            $check_pl_stmt->execute();
            $pl_result = $check_pl_stmt->get_result();
            
            if ($pl_result->num_rows > 0) {
                $pick_list = $pl_result->fetch_assoc();
                $pick_list_id = $pick_list['pick_list_id'];
                
                // Verify pick list belongs to user's branch
                if ($pick_lists_branch_column_exists && !$view_all_branches) {
                    $check_pl_branch_query = "SELECT branch_id FROM pick_lists WHERE pick_list_id = ?";
                    $check_pl_branch_stmt = $conn->prepare($check_pl_branch_query);
                    $check_pl_branch_stmt->bind_param("i", $pick_list_id);
                    $check_pl_branch_stmt->execute();
                    $pl_branch_result = $check_pl_branch_stmt->get_result();
                    $pl_branch_data = $pl_branch_result->fetch_assoc();
                    
                    if ($pl_branch_data && $pl_branch_data['branch_id'] != $branch_id) {
                        throw new Exception('Cannot modify pick list from another branch');
                    }
                }
                
                // Update driver assignment if provided
                if ($driver_id) {
                    $update_driver_query = "UPDATE pick_lists SET driver_id = ? WHERE pick_list_id = ?";
                    
                    if ($pick_lists_branch_column_exists && !$view_all_branches) {
                        $update_driver_query .= " AND branch_id = ?";
                        $update_driver_stmt = $conn->prepare($update_driver_query);
                        $update_driver_stmt->bind_param("iii", $driver_id, $pick_list_id, $branch_id);
                    } else {
                        $update_driver_stmt = $conn->prepare($update_driver_query);
                        $update_driver_stmt->bind_param("ii", $driver_id, $pick_list_id);
                    }
                    
                    $update_driver_stmt->execute();
                }
            } else {
                // Create new pick list with branch ID
                if ($pick_lists_branch_column_exists) {
                    $create_pl_query = "INSERT INTO pick_lists (pick_list_number, so_id, branch_id, driver_id, pick_status, pick_date, created_at) 
                                       VALUES (?, ?, ?, ?, 'open', NOW(), NOW())";
                    $create_pl_stmt = $conn->prepare($create_pl_query);
                    $create_pl_stmt->bind_param("siii", $pick_list_number, $so_id, $branch_id_for_insert, $driver_id);
                } else {
                    $create_pl_query = "INSERT INTO pick_lists (pick_list_number, so_id, driver_id, pick_status, pick_date, created_at) 
                                       VALUES (?, ?, ?, 'open', NOW(), NOW())";
                    $create_pl_stmt = $conn->prepare($create_pl_query);
                    $create_pl_stmt->bind_param("sii", $pick_list_number, $so_id, $driver_id);
                }
                
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
            if ($items_branch_column_exists && !$view_all_branches) {
                $update_stock_query .= " AND branch_id = ?";
                $update_stock_stmt = $conn->prepare($update_stock_query);
                $update_stock_stmt->bind_param("iiii", $quantity_to_pick, $item_id, $quantity_to_pick, $branch_id);
            } else {
                $update_stock_stmt = $conn->prepare($update_stock_query);
                $update_stock_stmt->bind_param("iii", $quantity_to_pick, $item_id, $quantity_to_pick);
            }
            
            if (!$update_stock_stmt->execute() || $update_stock_stmt->affected_rows === 0) {
                throw new Exception('Failed to update item stock');
            }
            
            // Update sales order status to 'processing'
            $update_so_query = "UPDATE sales_orders 
                               SET order_status = 'processing', updated_at = NOW() 
                               WHERE so_id = ? AND order_status NOT IN ('delivered', 'cancelled')";
            
            if ($sales_orders_branch_column_exists && !$view_all_branches) {
                $update_so_query .= " AND branch_id = ?";
                $update_so_stmt = $conn->prepare($update_so_query);
                $update_so_stmt->bind_param("ii", $so_id, $branch_id);
            } else {
                $update_so_stmt = $conn->prepare($update_so_query);
                $update_so_stmt->bind_param("i", $so_id);
            }
            
            $update_so_stmt->execute();
            
            $conn->commit();
            
            // Get driver name for response
            $driver_name = null;
            if ($driver_id) {
                $driver_query = "SELECT driver_name FROM drivers WHERE driver_id = ?";
                
                if ($drivers_branch_column_exists && !$view_all_branches) {
                    $driver_query .= " AND branch_id = ?";
                    $driver_stmt = $conn->prepare($driver_query);
                    $driver_stmt->bind_param("ii", $driver_id, $branch_id);
                } else {
                    $driver_stmt = $conn->prepare($driver_query);
                    $driver_stmt->bind_param("i", $driver_id);
                }
                
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
                'driver_name' => $driver_name,
                'branch_id' => $branch_id_for_insert,
                'branch_name' => $branch_name
            ]);
            exit;
        }
        elseif ($_POST['action'] === 'update_pick_item') {
            $pick_item_id = intval($_POST['pick_item_id'] ?? 0);
            $quantity_to_pick = intval($_POST['quantity_to_pick'] ?? 0);
            $location_bin = trim($_POST['location_bin'] ?? '');
            $driver_id = !empty($_POST['driver_id']) ? intval($_POST['driver_id']) : null;

            if ($pick_item_id <= 0 || $quantity_to_pick <= 0 || $location_bin === '') {
                throw new Exception('Invalid item details');
            }

            $get_query = "SELECT pli.pick_item_id, pli.pick_list_id, pli.item_id, pli.quantity_to_pick, pl.branch_id, pl.pick_status, i.stock FROM pick_list_items pli INNER JOIN pick_lists pl ON pli.pick_list_id = pl.pick_list_id INNER JOIN items i ON pli.item_id = i.item_id WHERE pli.pick_item_id = ?";

            if ($pick_lists_branch_column_exists && !$view_all_branches) {
                $get_query .= " AND pl.branch_id = ?";
                $get_stmt = $conn->prepare($get_query);
                $get_stmt->bind_param("ii", $pick_item_id, $branch_id);
            } else {
                $get_stmt = $conn->prepare($get_query);
                $get_stmt->bind_param("i", $pick_item_id);
            }
            $get_stmt->execute();
            $pick_item = $get_stmt->get_result()->fetch_assoc();

            if (!$pick_item) {
                throw new Exception('Pick item not found');
            }
            if (in_array($pick_item['pick_status'], ['completed', 'cancelled'])) {
                throw new Exception('Completed or cancelled pick list items cannot be edited');
            }

            $old_quantity = intval($pick_item['quantity_to_pick']);
            $current_stock = intval($pick_item['stock']);
            $difference = $quantity_to_pick - $old_quantity;

            if ($difference > 0 && $current_stock < $difference) {
                throw new Exception('Insufficient stock available. Current stock: ' . $current_stock);
            }

            if ($difference > 0) {
                $stock_query = "UPDATE items SET stock = stock - ? WHERE item_id = ? AND stock >= ?";
                if ($items_branch_column_exists && !$view_all_branches) {
                    $stock_query .= " AND branch_id = ?";
                    $stock_stmt = $conn->prepare($stock_query);
                    $stock_stmt->bind_param("iiii", $difference, $pick_item['item_id'], $difference, $branch_id);
                } else {
                    $stock_stmt = $conn->prepare($stock_query);
                    $stock_stmt->bind_param("iii", $difference, $pick_item['item_id'], $difference);
                }
                if (!$stock_stmt->execute() || $stock_stmt->affected_rows === 0) {
                    throw new Exception('Failed to update item stock');
                }
            } elseif ($difference < 0) {
                $return_quantity = abs($difference);
                $stock_query = "UPDATE items SET stock = stock + ? WHERE item_id = ?";
                if ($items_branch_column_exists && !$view_all_branches) {
                    $stock_query .= " AND branch_id = ?";
                    $stock_stmt = $conn->prepare($stock_query);
                    $stock_stmt->bind_param("iii", $return_quantity, $pick_item['item_id'], $branch_id);
                } else {
                    $stock_stmt = $conn->prepare($stock_query);
                    $stock_stmt->bind_param("ii", $return_quantity, $pick_item['item_id']);
                }
                if (!$stock_stmt->execute()) {
                    throw new Exception('Failed to return item stock');
                }
            }

            $update_stmt = $conn->prepare("UPDATE pick_list_items SET quantity_to_pick = ?, location_bin = ? WHERE pick_item_id = ?");
            $update_stmt->bind_param("isi", $quantity_to_pick, $location_bin, $pick_item_id);
            if (!$update_stmt->execute()) {
                throw new Exception('Failed to update pick item');
            }

            $driver_query = "UPDATE pick_lists SET driver_id = ? WHERE pick_list_id = ?";
            if ($pick_lists_branch_column_exists && !$view_all_branches) {
                $driver_query .= " AND branch_id = ?";
                $driver_stmt = $conn->prepare($driver_query);
                $driver_stmt->bind_param("iii", $driver_id, $pick_item['pick_list_id'], $branch_id);
            } else {
                $driver_stmt = $conn->prepare($driver_query);
                $driver_stmt->bind_param("ii", $driver_id, $pick_item['pick_list_id']);
            }
            $driver_stmt->execute();

            $conn->commit();
            echo json_encode(['success' => true, 'message' => 'Item updated successfully']);
            exit;
        }
        elseif ($_POST['action'] === 'delete_pick_item') {
            $pick_item_id = intval($_POST['pick_item_id'] ?? 0);
            if ($pick_item_id <= 0) {
                throw new Exception('Invalid pick item ID');
            }

            $get_query = "SELECT pli.pick_item_id, pli.pick_list_id, pli.item_id, pli.quantity_to_pick, pl.branch_id, pl.pick_status FROM pick_list_items pli INNER JOIN pick_lists pl ON pli.pick_list_id = pl.pick_list_id WHERE pli.pick_item_id = ?";
            if ($pick_lists_branch_column_exists && !$view_all_branches) {
                $get_query .= " AND pl.branch_id = ?";
                $get_stmt = $conn->prepare($get_query);
                $get_stmt->bind_param("ii", $pick_item_id, $branch_id);
            } else {
                $get_stmt = $conn->prepare($get_query);
                $get_stmt->bind_param("i", $pick_item_id);
            }
            $get_stmt->execute();
            $pick_item = $get_stmt->get_result()->fetch_assoc();

            if (!$pick_item) {
                throw new Exception('Pick item not found');
            }
            if (in_array($pick_item['pick_status'], ['completed', 'cancelled'])) {
                throw new Exception('Completed or cancelled pick list items cannot be deleted');
            }

            $return_query = "UPDATE items SET stock = stock + ? WHERE item_id = ?";
            if ($items_branch_column_exists && !$view_all_branches) {
                $return_query .= " AND branch_id = ?";
                $return_stmt = $conn->prepare($return_query);
                $return_stmt->bind_param("iii", $pick_item['quantity_to_pick'], $pick_item['item_id'], $branch_id);
            } else {
                $return_stmt = $conn->prepare($return_query);
                $return_stmt->bind_param("ii", $pick_item['quantity_to_pick'], $pick_item['item_id']);
            }
            if (!$return_stmt->execute()) {
                throw new Exception('Failed to return stock');
            }

            $delete_stmt = $conn->prepare("DELETE FROM pick_list_items WHERE pick_item_id = ?");
            $delete_stmt->bind_param("i", $pick_item_id);
            if (!$delete_stmt->execute()) {
                throw new Exception('Failed to delete pick item');
            }

            $conn->commit();
            echo json_encode(['success' => true, 'message' => 'Item deleted successfully. Stock returned.']);
            exit;
        }
        elseif ($_POST['action'] === 'get_pick_item') {
            $pick_item_id = intval($_POST['pick_item_id'] ?? 0);
            if ($pick_item_id <= 0) {
                throw new Exception('Invalid pick item ID');
            }

            $query = "
                SELECT
                    pli.pick_item_id,
                    pli.pick_list_id,
                    pli.item_id,
                    pli.quantity_to_pick,
                    pli.quantity_picked,
                    pli.location_bin,
                    pl.pick_list_number,
                    pl.so_id,
                    pl.driver_id,
                    pl.pick_status,
                    pl.branch_id,
                    so.so_number,
                    so.order_status,
                    i.item_code,
                    i.item_name,
                    i.unit_type,
                    i.stock AS current_stock,
                    c.customer_name,
                    c.latitude,
                    c.longitude,
                    c.full_address,
                    c.delivery_instructions,
                    d.driver_name
                FROM pick_list_items pli
                INNER JOIN pick_lists pl ON pli.pick_list_id = pl.pick_list_id
                LEFT JOIN sales_orders so ON pl.so_id = so.so_id
                LEFT JOIN customers c ON so.customer_id = c.customer_id
                LEFT JOIN items i ON pli.item_id = i.item_id
                LEFT JOIN drivers d ON pl.driver_id = d.driver_id
                WHERE pli.pick_item_id = ?
            ";
            if ($pick_lists_branch_column_exists && !$view_all_branches) {
                $query .= " AND pl.branch_id = ?";
                $stmt = $conn->prepare($query);
                $stmt->bind_param("ii", $pick_item_id, $branch_id);
            } else {
                $stmt = $conn->prepare($query);
                $stmt->bind_param("i", $pick_item_id);
            }
            if (!$stmt->execute()) {
                throw new Exception('Failed to fetch item details');
            }
            $item = $stmt->get_result()->fetch_assoc();
            if (!$item) {
                throw new Exception('Pick item not found');
            }

            $conn->commit();
            echo json_encode(['success' => true, 'item' => $item]);
            exit;
        }
        elseif ($_POST['action'] === 'get_so_items') {
            $so_id = intval($_POST['so_id'] ?? 0);
            if ($so_id <= 0) {
                throw new Exception('Invalid sales order ID');
            }

            $query = "
                SELECT
                    soi.so_id,
                    soi.item_id,
                    soi.quantity_ordered,
                    COALESCE(soi.quantity_delivered, 0) AS quantity_delivered,
                    soi.unit_price,
                    soi.unit_type,
                    i.item_code,
                    i.item_name,
                    i.unit_type AS item_unit_type,
                    i.stock AS current_stock,
                    i.reorder_level,
                    i.branch_id AS item_branch_id,
                    c.latitude,
                    c.longitude,
                    c.full_address,
                    c.delivery_instructions,
                    c.customer_name
                FROM sales_order_items soi
                INNER JOIN items i ON soi.item_id = i.item_id
                INNER JOIN sales_orders so ON soi.so_id = so.so_id
                LEFT JOIN customers c ON so.customer_id = c.customer_id
                WHERE soi.so_id = ?
            ";
            if ($sales_orders_branch_column_exists && !$view_all_branches) {
                $query .= " AND so.branch_id = ?";
            }
            if ($items_branch_column_exists && !$view_all_branches) {
                $query .= " AND i.branch_id = ?";
            }
            $query .= " ORDER BY i.item_name ASC";

            $stmt = $conn->prepare($query);
            if ($sales_orders_branch_column_exists && !$view_all_branches && $items_branch_column_exists && !$view_all_branches) {
                $stmt->bind_param("iii", $so_id, $branch_id, $branch_id);
            } elseif (($sales_orders_branch_column_exists && !$view_all_branches) || ($items_branch_column_exists && !$view_all_branches)) {
                $stmt->bind_param("ii", $so_id, $branch_id);
            } else {
                $stmt->bind_param("i", $so_id);
            }
            if (!$stmt->execute()) {
                throw new Exception('Failed to fetch sales order items');
            }
            $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

            $conn->commit();
            echo json_encode(['success' => true, 'items' => $items]);
            exit;
        }
        elseif ($_POST['action'] === 'print_pick_list') {
            $query = "
                SELECT
                    pl.pick_list_id,
                    pl.pick_list_number,
                    pl.pick_date,
                    pl.pick_status,
                    pl.created_at,
                    pl.driver_id,
                    pl.branch_id,
                    b.branch_name,
                    d.driver_name AS assigned_driver,
                    pli.pick_item_id,
                    pli.quantity_to_pick,
                    pli.quantity_picked,
                    pli.location_bin,
                    i.item_code,
                    i.item_name,
                    COALESCE(soi.unit_type, i.unit_type) AS unit_type,
                    i.stock AS current_stock,
                    so.so_number,
                    so.order_status,
                    c.customer_name,
                    c.latitude,
                    c.longitude,
                    c.full_address,
                    c.delivery_instructions
                FROM pick_lists pl
                LEFT JOIN branches b ON pl.branch_id = b.branch_id
                INNER JOIN pick_list_items pli ON pl.pick_list_id = pli.pick_list_id
                LEFT JOIN items i ON pli.item_id = i.item_id
                LEFT JOIN sales_orders so ON pl.so_id = so.so_id
                LEFT JOIN customers c ON so.customer_id = c.customer_id
                LEFT JOIN drivers d ON pl.driver_id = d.driver_id
                LEFT JOIN sales_order_items soi ON so.so_id = soi.so_id AND pli.item_id = soi.item_id
                WHERE 1=1
            ";
            if ($pick_lists_branch_column_exists && !$view_all_branches) {
                $query .= " AND pl.branch_id = ?";
                $stmt = $conn->prepare($query . " ORDER BY pl.created_at DESC, pl.pick_list_id DESC");
                $stmt->bind_param("i", $branch_id);
            } else {
                $stmt = $conn->prepare($query . " ORDER BY pl.created_at DESC, pl.pick_list_id DESC");
            }
            if (!$stmt->execute()) {
                throw new Exception('Failed to prepare print data');
            }
            $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

            $conn->commit();
            echo json_encode(['success' => true, 'items' => $items, 'branch_name' => $branch_name, 'view_all' => $view_all_branches]);
            exit;
        }
        else {
            throw new Exception('Invalid action');
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

// ========== FETCH PICK LISTS WITH BRANCH FILTERING ==========
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
        pl.branch_id,
        b.branch_name,
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
        i.branch_id as item_branch_id,
        so.so_id,
        so.so_number,
        so.order_status,
        so.branch_id as so_branch_id,
        so.customer_id,
        c.customer_name,
        c.latitude,
        c.longitude,
        c.full_address,
        c.delivery_instructions,
        CONCAT(u.first_name, ' ', u.last_name) as encoded_by_name,
        u.email as encoded_by_email,
        pl.created_at as encoded_at,
        soi.unit_type as order_unit_type
    FROM pick_lists pl
    LEFT JOIN branches b ON pl.branch_id = b.branch_id
    LEFT JOIN pick_list_items pli ON pl.pick_list_id = pli.pick_list_id
    LEFT JOIN items i ON pli.item_id = i.item_id
    LEFT JOIN sales_orders so ON pl.so_id = so.so_id
    LEFT JOIN customers c ON so.customer_id = c.customer_id
    LEFT JOIN users u ON pl.picked_by = u.user_id
    LEFT JOIN drivers d ON pl.driver_id = d.driver_id
    LEFT JOIN sales_order_items soi ON so.so_id = soi.so_id AND pli.item_id = soi.item_id
    WHERE 1=1
";

// Apply branch filter for pick_lists
if ($pick_lists_branch_column_exists && !$view_all_branches) {
    $picklist_query .= " AND pl.branch_id = $branch_id";
}

$picklist_query .= " ORDER BY pl.created_at DESC, pl.pick_list_id DESC";

$picklist_result = $conn->query($picklist_query);
$picklist_items = $picklist_result->fetch_all(MYSQLI_ASSOC);

// ========== FETCH SALES ORDERS ==========
$so_query = "
    SELECT DISTINCT
        so.so_id,
        so.so_number,
        so.customer_id,
        so.order_date,
        so.total_amount,
        so.order_status,
        so.branch_id,
        c.customer_name,
        c.latitude,
        c.longitude,
        c.full_address,
        c.delivery_instructions,
        b.branch_name,
        (SELECT COUNT(*) FROM sales_order_items WHERE so_id = so.so_id) as item_count
    FROM sales_orders so
    LEFT JOIN customers c ON so.customer_id = c.customer_id
    LEFT JOIN branches b ON so.branch_id = b.branch_id
    WHERE so.order_status IN ('pending', 'confirmed', 'processing')
    AND EXISTS (SELECT 1 FROM sales_order_items WHERE so_id = so.so_id)
";

// Apply branch filter for sales_orders
if ($sales_orders_branch_column_exists && !$view_all_branches) {
    $so_query .= " AND so.branch_id = $branch_id";
}

$so_query .= " ORDER BY so.order_date DESC";

$so_result = $conn->query($so_query);
$sales_orders = $so_result->fetch_all(MYSQLI_ASSOC);

// ========== FETCH SALES ORDER ITEMS ==========
$so_items_query = "
    SELECT 
        soi.so_id,
        soi.item_id,
        soi.quantity_ordered,
        soi.quantity_delivered,
        soi.unit_price,
        soi.unit_type,
        i.item_code,
        i.item_name,
        i.unit_type as item_unit_type,
        i.stock as current_stock,
        i.reorder_level,
        i.branch_id as item_branch_id,
        c.latitude,
        c.longitude,
        c.full_address,
        c.delivery_instructions,
        c.customer_name
    FROM sales_order_items soi
    JOIN items i ON soi.item_id = i.item_id
    JOIN sales_orders so ON soi.so_id = so.so_id
    LEFT JOIN customers c ON so.customer_id = c.customer_id
    WHERE soi.so_id IN (
        SELECT so_id FROM sales_orders 
        WHERE order_status IN ('pending', 'confirmed', 'processing')
";

if ($sales_orders_branch_column_exists && !$view_all_branches) {
    $so_items_query .= " AND branch_id = $branch_id";
}

$so_items_query .= "
    )
";

if ($items_branch_column_exists && !$view_all_branches) {
    $so_items_query .= " AND i.branch_id = $branch_id";
}

$so_items_result = $conn->query($so_items_query);
$so_items = $so_items_result->fetch_all(MYSQLI_ASSOC);

// Organize SO items by SO ID
$so_items_by_so = [];
foreach ($so_items as $item) {
    $so_items_by_so[$item['so_id']][] = $item;
}

// ========== FETCH ITEMS ==========
$items_query = "
    SELECT 
        item_id,
        item_code,
        item_name,
        unit_price,
        unit_type,
        stock,
        reorder_level,
        branch_id
    FROM items
    WHERE status = 'active'
";

if ($items_branch_column_exists && !$view_all_branches) {
    $items_query .= " AND branch_id = $branch_id";
}

$items_query .= " ORDER BY item_name ASC";

$items_result = $conn->query($items_query);
$items_list = $items_result->fetch_all(MYSQLI_ASSOC);

// Create lookup arrays for items
$items_by_code = [];
foreach ($items_list as $item) {
    $items_by_code[$item['item_code']] = $item;
}

// ========== FETCH DRIVERS ==========
$drivers_query = "
    SELECT 
        driver_id,
        driver_name,
        license_number,
        vehicle_type,
        vehicle_plate_number
    FROM drivers
    WHERE status = 'active'
";

if ($drivers_branch_column_exists && !$view_all_branches) {
    $drivers_query .= " AND branch_id = $branch_id";
}

$drivers_query .= " ORDER BY driver_name ASC";

$drivers_result = $conn->query($drivers_query);
$drivers_list = $drivers_result->fetch_all(MYSQLI_ASSOC);

// ========== STATISTICS CALCULATION ==========
$distinct_picklists_query = "SELECT COUNT(DISTINCT pick_list_id) as total FROM pick_lists WHERE 1=1";

if ($pick_lists_branch_column_exists && !$view_all_branches) {
    $distinct_picklists_query .= " AND branch_id = $branch_id";
}

$distinct_result = $conn->query($distinct_picklists_query);
$statTotalItems = $distinct_result->fetch_assoc()['total'];

// Count pick lists by status
$status_counts_query = "
    SELECT 
        pick_status,
        COUNT(DISTINCT pick_list_id) as count 
    FROM pick_lists 
    WHERE 1=1
";

if ($pick_lists_branch_column_exists && !$view_all_branches) {
    $status_counts_query .= " AND branch_id = $branch_id";
}

$status_counts_query .= " GROUP BY pick_status";

$status_result = $conn->query($status_counts_query);

$statWarehouseReady = 0;
$statInTransit = 0;

while ($row = $status_result->fetch_assoc()) {
    if ($row['pick_status'] === 'completed') {
        $statWarehouseReady = $row['count'];
    } elseif ($row['pick_status'] === 'in-progress') {
        $statInTransit = $row['count'];
    }
}

// Count delivered orders
$delivered_query = "
    SELECT COUNT(*) as count 
    FROM sales_orders 
    WHERE order_status = 'delivered'
";

if ($sales_orders_branch_column_exists && !$view_all_branches) {
    $delivered_query .= " AND branch_id = $branch_id";
}

$delivered_result = $conn->query($delivered_query);
$statDelivered = $delivered_result->fetch_assoc()['count'];

// Set default values if null
if (!$statTotalItems) $statTotalItems = 0;
if (!$statWarehouseReady) $statWarehouseReady = 0;
if (!$statInTransit) $statInTransit = 0;
if (!$statDelivered) $statDelivered = 0;

// ========== HELPER FUNCTIONS ==========
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

function getStockStatusClass($stock, $reorder_level) {
    if ($stock <= 0) return 'bg-danger text-white';
    if ($stock <= $reorder_level) return 'bg-warning text-dark';
    return 'bg-success text-white';
}

// Get user initials for avatar
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
    $user_initials = 'BA';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pick List Items - <?php echo htmlspecialchars($branch_name); ?></title>
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
    <!-- Leaflet CSS for maps -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <!-- Select2 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <!-- SweetAlert2 -->
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <!-- SheetJS for Excel Export -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
        <!-- Session Checker -->
    <script src="../js/session-checker.js"></script>
    
    <style>
        :root {
            --green: #2E7D32;
            --green-haze: #1B5E20;
            --deep-sea: #0D4C14;
            --forest-green: #1B4D1F;
            --yellow: #FFC107;
            --white: #FFFFFF;
            --light-gray: #F5F5F5;
            --black: #212121;
        }

        /* Print Frame */
        #printFrame {
            position: absolute;
            left: -9999px;
            top: -9999px;
            width: 1px;
            height: 1px;
            opacity: 0;
            pointer-events: none;
        }

        /* Compact print styles */
        @media print {
            @page {
                size: landscape;
                margin: 0.3in;
            }
            
            body * {
                visibility: hidden;
                background: white !important;
                color: black !important;
                border-color: black !important;
            }
            
            #printFrame, #printFrame * {
                visibility: visible;
            }
            
            #printFrame {
                position: absolute;
                left: 0;
                top: 0;
                width: 100%;
                height: auto;
                border: none;
            }
            
            #printFrame img {
                filter: none !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            
            #printFrame * {
                background: white !important;
                color: black !important;
                border-color: #000 !important;
                box-shadow: none !important;
                text-shadow: none !important;
                -webkit-print-color-adjust: economy;
                print-color-adjust: economy;
            }
            
            #printFrame table, 
            #printFrame th, 
            #printFrame td {
                border: 1px solid #000 !important;
            }
            
            #printFrame th {
                background: white !important;
                color: black !important;
                font-weight: bold;
            }
            
            #printFrame .summary-box,
            #printFrame .customer-section,
            #printFrame .total-row {
                background: white !important;
                border: 1px solid #000 !important;
            }
            
            #printFrame .badge {
                background: white !important;
                border: 1px solid #000 !important;
                color: black !important;
                padding: 2px 6px;
            }
        }
        
        /* ===== QUICK STATS CARDS ===== */
        .stat-card {
            background: transparent !important;
            border: none !important;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.08) !important;
            min-height: auto !important;
            height: auto !important;
            padding: 0.8rem !important;
            transition: transform 0.2s ease, box-shadow 0.2s ease !important;
            cursor: default !important;
        }

        .stat-card.total {
            background: linear-gradient(135deg, #047857, #059669) !important;
            border: none !important;
        }

        .stat-card.pending {
            background: linear-gradient(135deg, #047857, #059669) !important;
            border: none !important;
        }

        .stat-card.complete {
            background: linear-gradient(135deg, #047857, #059669) !important;
            border: none !important;
        }

        .stat-card.delivery {
            background: linear-gradient(135deg, #047857, #059669) !important;
            border: none !important;
        }

        .stat-card .stat-value,
        .stat-card .stat-label,
        .stat-card .stat-content,
        .stat-card small,
        .stat-card small i,
        .stat-card .badge {
            color: white !important;
        }

        .stat-card .stat-content,
        .stat-card .stat-icon {
            background: transparent !important;
        }

        @media (max-width: 991px) {
            .stat-card {
                aspect-ratio: 1 / 1 !important;
                display: flex !important;
                flex-direction: column !important;
                justify-content: center !important;
                align-items: center !important;
                text-align: center !important;
                padding: 0.5rem !important;
            }
            
            .stat-card i,
            .stat-card .stat-icon {
                display: block !important;
                text-align: center !important;
                margin: 0 auto 0.3rem auto !important;
                font-size: 1.6rem !important;
                width: auto !important;
                float: none !important;
                position: static !important;
            }
            
            .stat-card .stat-value {
                display: block !important;
                text-align: center !important;
                font-size: 1.2rem !important;
                font-weight: bold !important;
                line-height: 1.2 !important;
                margin: 0.2rem 0 !important;
                width: 100% !important;
            }
            
            .stat-card .stat-label {
                display: block !important;
                text-align: center !important;
                font-size: 0.7rem !important;
                font-weight: 500 !important;
                width: 100% !important;
            }
            
            .stat-card small {
                display: none !important;
            }
        }

        @media (min-width: 992px) {
            .stat-card {
                align-items: flex-start !important;
                text-align: left !important;
                padding: 1rem !important;
                aspect-ratio: auto !important;
                min-height: 120px !important;
                max-height: 130px !important;
                display: flex !important;
                flex-direction: row !important;
                justify-content: flex-start !important;
            }
            
            .stat-card i,
            .stat-card .stat-icon {
                align-self: flex-start !important;
                margin: 0 0.75rem 0 0 !important;
                font-size: 1.6rem !important;
                display: inline-block !important;
                text-align: left !important;
            }
            
            .stat-card .stat-content {
                display: flex !important;
                flex-direction: column !important;
                align-items: flex-start !important;
                text-align: left !important;
                flex: 1 !important;
            }
            
            .stat-card .stat-value {
                align-self: flex-start !important;
                margin: 0 0 0.05rem 0 !important;
                font-size: 1.4rem !important;
                line-height: 1.2 !important;
                text-align: left !important;
            }
            
            .stat-card .stat-label {
                align-self: flex-start !important;
                margin-top: 0.1rem !important;
                font-size: 0.75rem !important;
                font-weight: 500 !important;
                text-align: left !important;
            }
            
            .stat-card small {
                align-self: flex-start !important;
                margin-top: 0.2rem !important;
                display: block !important;
                font-size: 0.65rem !important;
                opacity: 0.9 !important;
                text-align: left !important;
            }
        }

        .stat-card-row {
            margin-bottom: 1.5rem;
        }

        .stat-card:hover {
            transform: translateY(-2px) !important;
            box-shadow: 0 6px 15px rgba(0, 0, 0, 0.15) !important;
        }
        
        /* ===== ACTION BUTTONS WRAPPER - OUTSIDE FILTER ===== */
        .action-button-wrapper {
            margin-bottom: 1.25rem;
            text-align: right;
            display: flex;
            justify-content: flex-end;
            gap: 0.75rem;
        }
        
        .btn-outline-primary {
            border: 1px solid #047857;
            color: #047857;
            background: white;
            border-radius: 10px;
            padding: 0.6rem 1.2rem;
            font-weight: 600;
            font-size: 0.85rem;
            transition: all 0.3s ease;
        }
        
        .btn-outline-primary:hover {
            background: #047857;
            color: white;
            transform: translateY(-2px);
        }
        
        .btn-outline-success {
            border: 1px solid #059669;
            color: #059669;
            background: white;
            border-radius: 10px;
            padding: 0.6rem 1.2rem;
            font-weight: 600;
            font-size: 0.85rem;
            transition: all 0.3s ease;
        }
        
        .btn-outline-success:hover {
            background: #059669;
            color: white;
            transform: translateY(-2px);
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #059669, #047857) !important;
            border: none !important;
            border-radius: 10px !important;
            padding: 0.6rem 1.2rem !important;
            font-weight: 600 !important;
            font-size: 0.85rem !important;
            transition: all 0.3s ease !important;
            box-shadow: 0 4px 8px rgba(5, 150, 105, 0.25) !important;
            color: white;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px) !important;
            box-shadow: 0 8px 18px rgba(5, 150, 105, 0.35) !important;
            background: linear-gradient(135deg, #047857, #065f46) !important;
        }
        
        @media (max-width: 768px) {
            .action-button-wrapper {
                justify-content: center;
                margin-bottom: 1rem;
                gap: 0.5rem;
            }
            
            .btn-outline-primary,
            .btn-outline-success,
            .btn-primary {
                flex: 1;
                padding: 0.5rem 0.8rem !important;
                font-size: 0.75rem !important;
                text-align: center;
                justify-content: center;
            }
        }
        
        @media (max-width: 480px) {
            .action-button-wrapper {
                flex-direction: column;
            }
            
            .btn-outline-primary,
            .btn-outline-success,
            .btn-primary {
                width: 100%;
            }
        }

        /* ===== PICK LISTS TABLE - MOBILE CARD VIEW ===== */
        @media (max-width: 768px) {
            #pickListTableContainer,
            .table-container:has(#pickListTable) {
                overflow-x: visible !important;
                overflow-y: visible !important;
                max-width: 100% !important;
                width: 100% !important;
            }
            
            #pickListTable {
                display: block !important;
                width: 100% !important;
                min-width: 100% !important;
            }
            
            #pickListTable thead {
                display: none !important;
            }
            
            #pickListTable tbody {
                display: block !important;
                width: 100% !important;
            }
            
            #pickListTable tbody tr {
                display: block !important;
                background: white !important;
                border-radius: 16px !important;
                margin-bottom: 16px !important;
                padding: 16px !important;
                box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08) !important;
                border: 1px solid #e9ecef !important;
                position: relative !important;
                width: 100% !important;
                box-sizing: border-box !important;
                cursor: pointer !important;
                min-height: auto !important;
            }
            
            #pickListTable tbody tr td {
                display: none !important;
            }
            
            #pickListTable tbody tr td.col-so {
                display: block !important;
                margin-bottom: 8px !important;
                padding: 0 !important;
            }
            
            #pickListTable tbody tr td.col-so strong {
                font-size: 14px !important;
                font-weight: 700 !important;
                color: #047857 !important;
                display: block !important;
                margin-bottom: 4px !important;
            }
            
            #pickListTable tbody tr td.col-so small {
                display: block !important;
                font-size: 13px !important;
                color: #1f2937 !important;
                font-weight: 500 !important;
            }
            
            #pickListTable tbody tr td.col-item-name {
                display: block !important;
                margin-bottom: 8px !important;
                padding: 0 !important;
                font-size: 13px !important;
                color: #6c757d !important;
            }
            
            #pickListTable tbody tr td.col-status {
                display: block !important;
                margin-bottom: 8px !important;
                padding: 0 !important;
            }
            
            #pickListTable tbody tr td.col-status .badge {
                font-size: 11px !important;
                padding: 4px 14px !important;
                border-radius: 20px !important;
                display: inline-block !important;
            }
            
            #pickListTable tbody tr td.col-encoded {
                display: block !important;
                margin: 0 !important;
                padding: 0 !important;
                font-size: 0 !important;
            }
            
            #pickListTable tbody tr td.col-encoded::before {
                content: "Driver: " !important;
                font-weight: 600 !important;
                color: #6c757d !important;
                font-size: 12px !important;
                display: inline !important;
            }
            
            #pickListTable tbody tr td.col-encoded .driver-badge {
                font-size: 12px !important;
                font-weight: 500 !important;
                color: #1f2937 !important;
                display: inline !important;
            }
            
            #pickListTable tbody tr td.col-encoded .driver-badge i {
                font-size: 11px !important;
                margin-right: 4px !important;
                color: #6c757d !important;
                display: inline !important;
            }
            
            #pickListTable tbody tr td.col-branch,
            #pickListTable tbody tr td.col-item-code,
            #pickListTable tbody tr td.col-unit,
            #pickListTable tbody tr td.col-to-pick,
            #pickListTable tbody tr td.col-picked,
            #pickListTable tbody tr td.col-location,
            #pickListTable tbody tr td.col-encoded-by,
            #pickListTable tbody tr td.col-encoded-at,
            #pickListTable tbody tr td.col-actions {
                display: none !important;
            }
            
            #pickListTable tbody tr::after {
                content: "Tap to view details" !important;
                position: absolute !important;
                bottom: 12px !important;
                right: 12px !important;
                font-size: 9px !important;
                color: #9ca3af !important;
                background: transparent !important;
                padding: 0 !important;
                pointer-events: none !important;
            }
        }

        @media (max-width: 480px) {
            #pickListTable tbody tr {
                padding: 14px !important;
            }
            
            #pickListTable tbody tr td.col-so strong {
                font-size: 13px !important;
            }
            
            #pickListTable tbody tr td.col-so small {
                font-size: 12px !important;
            }
            
            #pickListTable tbody tr td.col-item-name {
                font-size: 12px !important;
                margin-bottom: 6px !important;
            }
            
            #pickListTable tbody tr td.col-status .badge {
                font-size: 10px !important;
                padding: 3px 12px !important;
            }
            
            #pickListTable tbody tr td.col-encoded::before {
                font-size: 11px !important;
            }
            
            #pickListTable tbody tr td.col-encoded .driver-badge {
                font-size: 11px !important;
            }
            
            #pickListTable tbody tr::after {
                font-size: 8px !important;
                bottom: 10px !important;
                right: 10px !important;
            }
        }

        /* Filter section styles */
        .form-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
            border: 1px solid #e2e8f0;
            margin-bottom: 1.5rem;
            overflow: hidden;
        }

        

        .filter-header h5 {
            margin: 0;
            font-size: 0.95rem;
            font-weight: 600;
            color: #1e293b;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .filter-header h5 i {
            color: #44d34e;
            font-size: 1rem;
        }

        .filter-toggle-btn {
            background: transparent;
            border: none;
            color: #64748b;
            width: 32px;
            height: 32px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .filter-toggle-btn i {
            font-size: 1rem;
            transition: transform 0.3s ease;
        }

        .filter-toggle-btn:hover {
            background: rgba(68, 211, 78, 0.1);
        }

        .filter-toggle-btn[aria-expanded="true"] i {
            transform: rotate(180deg);
        }

        .filter-content {
            transition: all 0.3s ease-in-out;
            overflow: hidden;
        }

        .filter-content.collapsed {
            display: none;
        }

        .filter-content:not(.collapsed) {
            display: block;
            padding: 1.25rem;
        }

        .filter-content .form-label {
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 0.35rem;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .filter-content .form-select,
        .filter-content .form-control {
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 0.5rem 0.75rem;
            font-size: 0.85rem;
            background-color: #fff;
            height: 40px;
        }

        .search-box {
            position: relative;
        }

        .search-box input {
            padding-left: 2.25rem;
        }

        .search-box i {
            position: absolute;
            left: 0.75rem;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            font-size: 0.9rem;
            pointer-events: none;
        }

        @media (max-width: 768px) {
            .filter-content:not(.collapsed) {
                padding: 1rem;
            }
            
            .filter-content .form-select,
            .filter-content .form-control {
                height: 36px;
                font-size: 0.8rem;
            }
            
            .filter-content .form-label {
                font-size: 0.7rem;
            }
        }
        
        /* ===== MOBILE BOTTOM NAVIGATION - FIXED DROPDOWN ===== */
.mobile-nav {
    position: fixed;
    bottom: 0;
    left: 0;
    right: 0;
    background: white;
    border-top: 1px solid #e5e7eb;
    box-shadow: 0 -2px 10px rgba(0, 0, 0, 0.05);
    z-index: 9999;
    display: none;
    padding: 8px 0 12px 0;
    overflow: visible !important;
}

@media (max-width: 992px) {
    .mobile-nav {
        display: block;
    }

    .main-content {
        padding-bottom: 80px !important;
    }
}

.mobile-nav .nav {
    display: flex;
    justify-content: space-around;
    align-items: center;
    margin: 0;
    padding: 0;
    list-style: none;
    overflow: visible !important;
    scrollbar-width: none;
}

.mobile-nav .nav::-webkit-scrollbar {
    display: none;
}

.mobile-nav .nav-item {
    position: relative;
    flex-shrink: 0;
    text-align: center;
    overflow: visible !important;
}

.mobile-nav .nav-link {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 6px 12px;
    color: #9ca3af;
    font-size: 0.7rem;
    text-decoration: none;
    border-radius: 12px;
    gap: 4px;
    white-space: nowrap;
    background: transparent;
    border: none;
    cursor: pointer;
}

.mobile-nav .nav-link i {
    font-size: 1.3rem;
    margin: 0;
}

.mobile-nav .nav-link span {
    font-size: 0.65rem;
    font-weight: 500;
}

.mobile-nav .nav-link.active {
    color: #059669;
    background: rgba(5, 150, 105, 0.1);
}

.mobile-nav .more-dropdown {
    position: absolute;
    bottom: calc(100% + 8px);
    left: 50%;
    transform: translateX(-50%) translateY(8px);
    background: white;
    border-radius: 12px;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
    border: 1px solid #e5e7eb;
    min-width: 180px;
    z-index: 10000;
    display: none !important;
    opacity: 0;
    visibility: hidden;
    pointer-events: none;
    transition: opacity 0.2s ease, transform 0.2s ease;
}

.mobile-nav .more-dropdown.show {
    display: block !important;
    opacity: 1 !important;
    visibility: visible !important;
    pointer-events: auto !important;
    transform: translateX(-50%) translateY(0) !important;
}

.mobile-nav .more-dropdown::before {
    content: '';
    position: absolute;
    bottom: -6px;
    left: 50%;
    transform: translateX(-50%) rotate(45deg);
    width: 12px;
    height: 12px;
    background: white;
    border-right: 1px solid #e5e7eb;
    border-bottom: 1px solid #e5e7eb;
}

.mobile-nav .dropdown-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 16px;
    color: #374151;
    text-decoration: none;
    transition: background 0.2s ease;
    border-bottom: 1px solid #f3f4f6;
    font-size: 0.85rem;
    background: white;
    width: 100%;
    text-align: left;
    cursor: pointer;
}

.mobile-nav .dropdown-item:last-child {
    border-bottom: none;
}

.mobile-nav .dropdown-item:hover {
    background: #f9fafb;
}

.mobile-nav .dropdown-item.active {
    background: rgba(5, 150, 105, 0.1);
    color: #059669;
}

.mobile-nav .dropdown-item i {
    width: 20px;
    font-size: 1rem;
    color: #6b7280;
}

.mobile-nav .dropdown-item.active i {
    color: #059669;
}

@media (max-width: 480px) {
    .mobile-nav .nav-link {
        padding: 4px 8px;
    }

    .mobile-nav .nav-link i {
        font-size: 1.1rem;
    }

    .mobile-nav .nav-link span {
        font-size: 0.55rem;
    }

    .mobile-nav .more-dropdown {
        min-width: 160px;
    }

    .mobile-nav .dropdown-item {
        padding: 10px 12px;
        font-size: 0.75rem;
    }
}
    </style>
</head>
<body>
    <!-- Loading Overlay -->
    <div id="loadingOverlay" class="loading-overlay">
        <div class="loading-spinner"></div>
    </div>

    <!-- Print Frame (hidden) -->
    <iframe id="printFrame" name="printFrame"></iframe>

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
            <span class="nav-text">Branch Admin</span>
        </h3>
    </div>
    
    <div class="sidebar-content">
        <div class="sidebar-menu">
            <ul class="nav flex-column">
                <li class="nav-item">
                <a class="nav-link" href="branchdashboard.php">
                <i class="bi bi-speedometer2"></i>
                <span class="nav-text">Dashboard</span></a>
            </li>
                <!-- Warehouse Dropdown -->
<li class="nav-item dropdown-nav">
    <a class="nav-link" href="#" onclick="toggleSidebarDropdown(event, 'warehouseMenu')">
        <i class="bi bi-shop"></i>
        <span class="nav-text">Warehouse</span>
        <i class="bi bi-chevron-down dropdown-arrow"></i>
    </a>
    <div class="collapse" id="warehouseMenu">
        <ul class="nav flex-column ps-4">
            <li class="nav-item">
                <a class="nav-link active" href="current_inventory.php">
                        <i class="bi bi-bar-chart-line"></i>
                        <span class="nav-text">Current Inventory</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="bad_orders.php">
                    <i class="bi bi-recycle"></i>
                    <span class="nav-text">Bad Orders</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="pick_list_items.php">
                    <i class="bi bi-list-check"></i>
                    <span class="nav-text">Pick List Items</span>
                </a>
            </li>
                                            <li class="nav-item">
                                    <a class="nav-link" href="warehouses.php">
                                    <i class="bi bi-shop"></i>
                                    <span class="nav-text">Warehouses</span></a>
                                </li>
        </ul>
    </div>
</li>

                
                <!-- Supplier Dropdown -->
<li class="nav-item dropdown-nav">
    <a class="nav-link" href="#" onclick="toggleSidebarDropdown(event, 'supplierMenu')">
        <i class="bi bi-building"></i>
        <span class="nav-text">Supplier</span>
        <i class="bi bi-chevron-down dropdown-arrow"></i>
    </a>
    <div class="collapse" id="supplierMenu">
        <ul class="nav flex-column ps-4">
            <li class="nav-item">
                <a class="nav-link" href="purchase_order.php">
                    <i class="bi bi-box"></i>
                    <span class="nav-text">Receive Inventory</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="supplier.php">
                    <i class="bi bi-people"></i>
                    <span class="nav-text">Supplier List</span>
                </a>
            </li>
        </ul>
    </div>
</li>

<li class="nav-item dropdown-nav">
                            <a class="nav-link" href="#" onclick="toggleSidebarDropdown(event, 'customerMenu')">
                                <i class="bi bi-people"></i><span class="nav-text">Customer</span><i class="bi bi-chevron-down dropdown-arrow"></i>
                            </a>
                            <div class="collapse" id="customerMenu">
                                <ul class="nav flex-column ps-4">
                                    <li class="nav-item"><a class="nav-link" href="customer_list.php"><i class="bi bi-person-badge"></i><span class="nav-text">Customer List</span></a></li>
                                    <li class="nav-item"><a class="nav-link" href="approve_credit_requests.php"><i class="bi bi-pencil-square"></i><span class="nav-text">Approve Credit Request</span></a></li>
                                    <li class="nav-item"><a class="nav-link" href="sales_order.php"><i class="bi bi-cart"></i><span class="nav-text">Sales Order</span></a></li>
                                    <li class="nav-item"><a class="nav-link active" href="collections.php"><i class="bi bi-cash-stack"></i><span class="nav-text">Collections</span></a></li>
                                </ul>
                            </div>
                        </li>

<!-- Delivery Dropdown -->
<li class="nav-item dropdown-nav">
    <a class="nav-link" href="#" onclick="toggleSidebarDropdown(event, 'deliveryMenu')">
        <i class="bi bi-truck"></i>
        <span class="nav-text">Delivery</span>
        <i class="bi bi-chevron-down dropdown-arrow"></i>
    </a>
    <div class="collapse" id="deliveryMenu">
        <ul class="nav flex-column ps-4">
            <li class="nav-item">
                <a class="nav-link" href="trip_tickets.php">
                    <i class="bi bi-ticket-perforated"></i>
                    <span class="nav-text">Trip Tickets</span>
                </a>
            </li>
        </ul>
    </div>
</li>
                    <!-- Banking Dropdown -->
                    <li class="nav-item dropdown-nav">
                        <a class="nav-link" href="#" onclick="toggleSidebarDropdown(event, 'bankingMenu')">
                            <i class="bi bi-bank2"></i>
                            <span class="nav-text">Banking</span>
                            <i class="bi bi-chevron-down dropdown-arrow"></i>
                        </a>

                        <div class="collapse" id="bankingMenu">
                            <ul class="nav flex-column ps-4">
                                <li class="nav-item">
                                    <a class="nav-link" href="deposit.php">
                                        <i class="bi bi-arrow-down-circle"></i>
                                        <span class="nav-text">Deposit</span>
                                    </a>
                                </li>

                                <li class="nav-item">
                                    <a class="nav-link" href="Withdrawal.php">
                                        <i class="bi bi-arrow-up-circle"></i>
                                        <span class="nav-text">Withdrawal</span>
                                    </a>
                                </li>

                                <li class="nav-item">
                                    <a class="nav-link" href="bank_statement.php">
                                        <i class="bi bi-receipt"></i>
                                        <span class="nav-text">Bank Statement</span>
                                    </a>
                                </li>

                                <li class="nav-item">
                                    <a class="nav-link" href="expenses.php">
                                        <i class="bi bi-cash-stack"></i>
                                        <span class="nav-text">Expenses</span>
                                    </a>
                                </li>
                            </ul>
                        </div>
                    </li>
                    
                    <!-- Shared Services Dropdown -->
<li class="nav-item dropdown-nav">
    <a class="nav-link" href="#" onclick="toggleSidebarDropdown(event, 'sharedServicesMenu')">
        <i class="bi bi-grid-3x3-gap"></i>
        <span class="nav-text">Shared Services</span>
        <i class="bi bi-chevron-down dropdown-arrow"></i>
    </a>
    <div class="collapse" id="sharedServicesMenu">
        <ul class="nav flex-column ps-4">
            <li class="nav-item">
                <a class="nav-link" href="motorpool.php">
                    <i class="bi bi-truck"></i>
                    <span class="nav-text">Motorpool</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="central_warehouse.php">
                    <i class="bi bi-box-seam"></i>
                    <span class="nav-text">Central Warehouse</span>
                </a>
            </li>
        </ul>
    </div>
</li>
                    
                <!-- Users -->
                <li class="nav-item">
                    <a class="nav-link" href="drivers.php">
                        <i class="bi bi-people-fill"></i>
                        <span class="nav-text">Users</span>
                    </a>
                </li>
                
                
            </ul>
        </div>
    </div>
    
    <div class="sidebar-footer">
        <div class="user-profile-sidebar">
            <div class="user-avatar-sidebar"><?php echo $user_initials; ?></div>
            <div class="user-details-sidebar">
                <span class="user-name-sidebar"><?php echo htmlspecialchars($user_name); ?></span>
                <span class="user-role-sidebar"><?php echo ucfirst($user_role); ?></span>
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
                    <button class="mobile-menu-btn" id="mobileMenuBtn">
                        <i class="bi bi-list"></i>
                    </button>
                    <div class="page-title">
                        <h2>Pick List Items</h2>
                        <p>
                            <?php 
                            if ($view_all_branches) {
                                echo 'Managing pick list items for all branches';
                            } else {
                                echo 'Manage pick list items for ' . htmlspecialchars($branch_name);
                            }
                            ?>
                        </p>
                    </div>
                    <?php if ($view_all_branches): ?>
                    <div class="ms-auto me-3">
                        <div class="branch-selector">
                            <select id="branchViewSelector" class="form-select form-select-sm" onchange="changeBranchView()">
                                <option value="all">All Branches</option>
                                <?php
                                $all_branches_query = "SELECT branch_id, branch_name FROM branches ORDER BY branch_name";
                                $all_branches_result = $conn->query($all_branches_query);
                                while ($branch = $all_branches_result->fetch_assoc()):
                                ?>
                                <option value="<?php echo $branch['branch_id']; ?>" <?php echo ($branch_id == $branch['branch_id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($branch['branch_name']); ?>
                                </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Branch Filter Alerts -->
                <?php if (!$pick_lists_branch_column_exists): ?>
                    <div class="alert alert-info alert-dismissible fade show" role="alert">
                        <i class="bi bi-info-circle"></i> 
                        <strong>Branch filtering for pick lists not yet set up.</strong> Please run this SQL in phpMyAdmin to enable branch-specific pick list data:
                        <br><br>
                        <code>ALTER TABLE pick_lists ADD COLUMN branch_id INT NULL AFTER so_id;</code>
                        <br>
                        <code>ALTER TABLE pick_lists ADD FOREIGN KEY (branch_id) REFERENCES branches(branch_id);</code>
                        <br>
                        <code>UPDATE pick_lists SET branch_id = 1 WHERE branch_id IS NULL;</code>
                        <br><br>
                        <button type="button" class="btn btn-sm btn-primary" onclick="copySQL('pick_lists')">
                            <i class="bi bi-files"></i> Copy SQL
                        </button>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if (!$drivers_branch_column_exists && !$view_all_branches): ?>
                    <div class="alert alert-info alert-dismissible fade show" role="alert">
                        <i class="bi bi-info-circle"></i> 
                        <strong>Branch filtering for drivers not yet set up.</strong> Please run this SQL in phpMyAdmin to enable branch-specific driver data:
                        <br><br>
                        <code>ALTER TABLE drivers ADD COLUMN branch_id INT NULL;</code>
                        <br>
                        <code>ALTER TABLE drivers ADD FOREIGN KEY (branch_id) REFERENCES branches(branch_id);</code>
                        <br><br>
                        <button type="button" class="btn btn-sm btn-primary" onclick="copySQL('drivers')">
                            <i class="bi bi-files"></i> Copy SQL
                        </button>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- No Items Warning -->
                <?php if (empty($picklist_items) && $pick_lists_branch_column_exists): ?>
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle"></i> 
                        <?php if ($view_all_branches): ?>
                            No pick list items found in any branch.
                        <?php else: ?>
                            No pick list items found. You can add new items using the "Add Item" button.
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <!-- No Drivers Warning -->
                <?php if (empty($drivers_list) && !$view_all_branches): ?>
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle"></i> 
                        No active drivers found for <?php echo htmlspecialchars($branch_name); ?>. Please contact admin to assign drivers to your branch.
                    </div>
                <?php endif; ?>

                <!-- Stats Section -->
                <div class="row stat-card-row g-1 g-sm-2 mb-4">
                    <div class="col">
                        <div class="stat-card total">
                            <i class="bi bi-boxes stat-icon"></i>
                            <div class="stat-content">
                                <div class="stat-value" id="totalItems"><?= $statTotalItems ?></div>
                                <div class="stat-label">Total Pick Lists</div>
                                <?php if (!$view_all_branches): ?>
                                    <small class="d-block"><?php echo htmlspecialchars($branch_name); ?></small>
                                <?php else: ?>
                                    <small class="d-block">All branches</small>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col">
                        <div class="stat-card pending">
                            <i class="bi bi-check2-circle stat-icon"></i>
                            <div class="stat-content">
                                <div class="stat-value" id="warehouseReady"><?= $statWarehouseReady ?></div>
                                <div class="stat-label">Warehouse Ready</div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col">
                        <div class="stat-card delivery">
                            <i class="bi bi-truck stat-icon"></i>
                            <div class="stat-content">
                                <div class="stat-value" id="inTransit"><?= $statInTransit ?></div>
                                <div class="stat-label">In Transit</div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col">
                        <div class="stat-card complete">
                            <i class="bi bi-check-circle stat-icon"></i>
                            <div class="stat-content">
                                <div class="stat-value" id="delivered"><?= $statDelivered ?></div>
                                <div class="stat-label">Delivered</div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- FILTER SECTION - COLLAPSIBLE DESIGN (Entire header clickable) -->
<div class="form-card mb-4" id="filterCard">
    <div class="filter-header" id="filterHeader" style="cursor: pointer;">
        <h5>
            <i class="bi bi-funnel"></i> Filter Pick Lists
        </h5>
        <button class="filter-toggle-btn" type="button" id="filterToggleBtn" aria-expanded="false">
            <i class="bi bi-chevron-down" id="filterIcon"></i>
        </button>
    </div>
    
    <div class="filter-content collapsed" id="filterContent">
        <div class="row g-3">
            <!-- Date Filter -->
            <div class="col-12 col-md-6 col-lg-3">
                <label class="form-label">
                    <i class="bi bi-calendar"></i> Date
                </label>
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
            
            <!-- Status Filter -->
            <div class="col-12 col-md-6 col-lg-3">
                <label class="form-label">
                    <i class="bi bi-flag"></i> Status
                </label>
                <select class="form-select" id="statusFilter" onchange="applyFilters()">
                    <option value="all">All Status</option>
                    <option value="open">Pending</option>
                    <option value="in-progress">In Progress</option>
                    <option value="completed">Completed</option>
                    <option value="cancelled">Cancelled</option>
                </select>
            </div>
            
            <!-- Branch Filter (Only for Admin) -->
            <?php if ($view_all_branches && $pick_lists_branch_column_exists): ?>
            <div class="col-12 col-md-6 col-lg-3">
                <label class="form-label">
                    <i class="bi bi-building"></i> Branch
                </label>
                <select class="form-select" id="branchFilter" onchange="applyFilters()">
                    <option value="all">All Branches</option>
                    <?php
                    $branches_for_filter = "SELECT branch_id, branch_name FROM branches ORDER BY branch_name";
                    $branches_filter_result = $conn->query($branches_for_filter);
                    while ($branch = $branches_filter_result->fetch_assoc()):
                    ?>
                    <option value="<?= $branch['branch_id'] ?>">
                        <?= htmlspecialchars($branch['branch_name']) ?>
                    </option>
                    <?php endwhile; ?>
                </select>
            </div>
            <?php endif; ?>
            
            <!-- Quantity Filter -->
            <div class="col-12 col-md-6 col-lg-3">
                <label class="form-label">
                    <i class="bi bi-box-seam"></i> Quantity
                </label>
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
        
        <!-- Second Row for Search Only -->
        <div class="row g-3 mt-2">
            <!-- Global Search Box -->
            <div class="col-12 col-md-12">
                <label class="form-label">
                    <i class="bi bi-search"></i> Global Search
                </label>
                <div class="search-box">
                    <input type="text" class="form-control" id="globalSearch" placeholder="Search SO number, item, driver, encoder, location..." onkeyup="applyFilters()">
                </div>
            </div>
        </div>
    </div>
</div>

                <!-- ACTION BUTTONS - MOVED OUTSIDE FILTER, ABOVE TABLE -->
                <div class="action-button-wrapper">
                    <button class="btn-outline-primary" onclick="printPickList()">
                        <i class="bi bi-printer me-1"></i> Print
                    </button>
                    <button class="btn-outline-success" onclick="exportToExcel()">
                        <i class="bi bi-file-earmark-excel me-1"></i> Export
                    </button>
                </div>

                <!-- Pick List Items Table -->
                <div class="table-container">
                    <table class="table custom-table compact-table" id="pickListTable">
                        <!-- Update the thead section - around line 900 -->
                        <thead>
                            <tr>
                                <th class="col-so">SO NUMBER</th>
                                <?php if ($view_all_branches && $pick_lists_branch_column_exists): ?>
                                    <th class="col-branch">BRANCH</th>
                                <?php endif; ?>
                                <th class="col-item-code">ITEM CODE</th>
                                <th class="col-item-name">ITEM NAME</th>
                                <th class="col-unit">UNIT</th>
                                <th class="col-to-pick">TO PICK</th>
                                <th class="col-picked">PICKED</th>
                                <th class="col-status">STATUS</th>
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
                                    
                                    // Determine which unit type to use
                                    $unit_type = $item['order_unit_type'] ?? $item['unit_type'] ?? 'N/A';
                            ?>
                            <tr class="pick-list-row" 
                                data-id="<?= $item['pick_item_id'] ?>"
                                data-pick-list-id="<?= $item['pick_list_id'] ?>"
                                data-so-id="<?= htmlspecialchars($item['so_number'] ?? '') ?>"
                                data-status="<?= $item['pick_status'] ?>"
                                data-item-code="<?= htmlspecialchars($item['item_code'] ?? '') ?>"
                                data-item-name="<?= htmlspecialchars($item['item_name'] ?? '') ?>"
                                data-unit-type="<?= htmlspecialchars($unit_type) ?>"
                                data-quantity="<?= $item['quantity_to_pick'] ?? 0 ?>"
                                data-picked="<?= $item['quantity_picked'] ?? 0 ?>"
                                data-created-date="<?= $item['created_at'] ?? '' ?>"
                                data-driver-id="<?= $item['driver_id'] ?? '' ?>"
                                data-driver-name="<?= htmlspecialchars($item['assigned_driver'] ?? 'Unassigned') ?>"
                                data-branch-id="<?= $item['branch_id'] ?? '' ?>"
                                data-branch-name="<?= htmlspecialchars($item['branch_name'] ?? '') ?>"
                                data-encoded-by="<?= htmlspecialchars($item['encoded_by_name'] ?? 'System') ?>"
                                data-encoded-at="<?= htmlspecialchars($item['encoded_at'] ?? '') ?>"
                                data-latitude="<?= $item['latitude'] ?? '' ?>"
                                data-longitude="<?= $item['longitude'] ?? '' ?>"
                                data-address="<?= htmlspecialchars($item['full_address'] ?? '') ?>"
                                data-customer-name="<?= htmlspecialchars($item['customer_name'] ?? '') ?>"
                                data-location="<?= htmlspecialchars($item['location_bin'] ?? '') ?>">
                                <td class="col-so">
                                    <strong><?= htmlspecialchars($item['so_number'] ?? 'N/A') ?></strong>
                                    <?php if (!empty($item['customer_name'])): ?>
                                    <br><small class="text-muted"><?= htmlspecialchars($item['customer_name']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <?php if ($view_all_branches && $pick_lists_branch_column_exists): ?>
                                <td class="col-branch">
                                    <span class="badge bg-info">
                                        <?= htmlspecialchars($item['branch_name'] ?? 'Branch ' . $item['branch_id']) ?>
                                    </span>
                                </td>
                                <?php endif; ?>
                                <td class="col-item-code"><?= htmlspecialchars($item['item_code'] ?? 'N/A') ?></td>
                                <td class="col-item-name">
                                    <?= htmlspecialchars($item['item_name'] ?? 'Unknown Item') ?>
                                    <?php if ($item['current_stock'] !== null): ?>
                                    <span class="stock-indicator <?= getStockStatusClass($item['current_stock'] ?? 0, 50) ?>">
                                        Stock: <?= $item['current_stock'] ?? 0 ?>
                                    </span>
                                    <?php endif; ?>
                                </td>
                                <td class="col-unit"><?= htmlspecialchars(strtoupper($unit_type)) ?></td>
                                <td class="col-to-pick text-center"><?= $item['quantity_to_pick'] ?? 0 ?></td>
                                <td class="col-picked text-center"><?= $item['quantity_picked'] ?? 0 ?></td>
                                <td class="col-status">
                                    <span class="badge <?= getPickStatusBadge($item['pick_status']) ?>">
                                        <?= getPickStatusText($item['pick_status']) ?>
                                    </span>
                                    <br>
                                    <small class="text-muted">SO: <?= ucfirst($item['order_status'] ?? 'N/A') ?></small>
                                </td>
                            </tr>
                            <?php 
                                endforeach;
                            else: 
                            ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Add Item Card -->
                <div class="new-item-card mt-4" id="addItemCard" onclick="showAddItemModal()">
                    <div class="add-icon">
                        <i class="bi bi-plus-lg"></i>
                    </div>
                    <h5>Add New Pick List Item</h5>
                    <p>Click to add a new item to the pick list and assign a driver</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Item Modal (remaining modal HTML same as original) -->
    <div class="modal fade" id="itemModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="modalTitle"><i class="bi bi-plus-circle me-2"></i>Add Pick List Item</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="itemForm">
                        <input type="hidden" id="itemId">
                        <input type="hidden" id="branchId" name="branch_id" value="<?= $branch_id ?>">
                        
                        <?php if (!$view_all_branches && $branch_id > 0): ?>
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle"></i>
                            Adding item to <strong><?php echo htmlspecialchars($branch_name); ?></strong>
                        </div>
                        <?php else: ?>
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle"></i>
                            <strong>Administrator Mode:</strong> Adding item to <span id="selectedBranchName">your selected branch</span>
                        </div>
                        <?php endif; ?>
                        
                        <?php if (empty($sales_orders) && !$view_all_branches): ?>
                        <div class="alert alert-warning">
                            <i class="bi bi-exclamation-triangle"></i>
                            No sales orders available for <?php echo htmlspecialchars($branch_name); ?>. You need to create a sales order first.
                        </div>
                        <?php endif; ?>
                        
                        <?php if (empty($drivers_list) && !$view_all_branches): ?>
                        <div class="alert alert-warning">
                            <i class="bi bi-exclamation-triangle"></i>
                            No drivers available for <?php echo htmlspecialchars($branch_name); ?>. Please contact admin to assign drivers.
                        </div>
                        <?php endif; ?>
                        
                        <div class="row g-3">
                            <!-- SO ID Dropdown -->
                            <div class="col-md-6">
                                <label for="soIdSelect" class="form-label">Sales Order</label>
                                <select class="form-select select2-so" id="soIdSelect" style="width: 100%;" onchange="onSOSelected()" required>
                                    <option value="">Select Sales Order</option>
                                    <?php foreach ($sales_orders as $so): ?>
                                        <?php if ($so['so_id']): ?>
                                        <option value="<?= $so['so_id'] ?>" 
                                                data-so-number="<?= htmlspecialchars($so['so_number']) ?>"
                                                data-customer-name="<?= htmlspecialchars($so['customer_name'] ?? 'N/A') ?>"
                                                data-order-date="<?= $so['order_date'] ?>"
                                                data-total-amount="<?= $so['total_amount'] ?>"
                                                data-branch-id="<?= $so['branch_id'] ?? '' ?>"
                                                data-branch-name="<?= htmlspecialchars($so['branch_name'] ?? '') ?>"
                                                data-latitude="<?= $so['latitude'] ?? '' ?>"
                                                data-longitude="<?= $so['longitude'] ?? '' ?>"
                                                data-address="<?= htmlspecialchars($so['full_address'] ?? '') ?>"
                                                data-delivery-instructions="<?= htmlspecialchars($so['delivery_instructions'] ?? '') ?>">
                                            <?= htmlspecialchars($so['so_number'] . ' - ' . ($so['customer_name'] ?? 'Unknown') . ' - ' . date('M d, Y', strtotime($so['order_date']))) ?>
                                            <?php if ($view_all_branches && !empty($so['branch_name'])): ?>
                                                [<?= htmlspecialchars($so['branch_name']) ?>]
                                            <?php endif; ?>
                                        </option>
                                    <?php endif; ?>
                                    <?php endforeach; ?>
                                </select>
                                <input type="hidden" id="soId" name="so_id">
                                <input type="hidden" id="soNumber" name="so_number">
                                <?php if ($sales_orders_branch_column_exists && !$view_all_branches): ?>
                                    <small class="text-muted">Your branch orders only</small>
                                <?php endif; ?>
                            </div>
                            
                            <!-- SO Details Preview with Location -->
                            <div class="col-md-6">
                                <div id="soDetailsPreview" style="display: none;" class="so-details">
                                    <div class="so-details-label">Sales Order Details</div>
                                    <div class="so-details-value" id="previewSoNumber">-</div>
                                    <div class="so-details-value" id="previewCustomer">-</div>
                                    <div class="so-details-value" id="previewOrderDate">-</div>
                                    <div class="so-details-value" id="previewSoBranch">-</div>
                                    <div class="mt-2 p-2 bg-white rounded" id="previewAddress"></div>
                                    <div id="previewMap" class="map-container" style="display: none;"></div>
                                </div>
                            </div>
                            
                            <!-- Item Selection - Multi-Select Table -->
                            <div class="col-12">
                                <label class="form-label fw-bold mb-2">Select Items to Pick</label>
                                <div class="alert alert-info mb-2">
                                    <i class="bi bi-info-circle me-2"></i>
                                    Check the items you want to add to the pick list. The delivery location will be automatically set from the customer's address.
                                </div>
                                
                                <div class="table-responsive items-selection-table">
                                    <table class="table table-sm table-hover mb-0" id="itemsSelectionTable">
                                        <thead class="table-light">
                                            <tr>
                                                <th width="50">
                                                    <input type="checkbox" class="form-check-input" id="selectAllItems" onclick="toggleAllItems()">
                                                </th>
                                                <th>Item Code</th>
                                                <th>Item Name</th>
                                                <th>Unit</th>
                                                <th>Ordered</th>
                                                <th>Stock</th>
                                                <th>Available</th>
                                                <th width="100">Qty to Pick</th>
                                                <th>Delivery Location</th>
                                            </tr>
                                        </thead>
                                        <tbody id="itemsSelectionBody">
                                            <tr id="noItemsMessage">
                                                <td colspan="9" class="text-center py-4 text-muted">
                                                    <i class="bi bi-inbox fs-4 d-block mb-2"></i>
                                                    Select a Sales Order to see available items
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                                <small class="text-muted mt-1 d-block">
                                    <i class="bi bi-info-circle"></i> 
                                    The delivery location is automatically set from the customer's address. No manual entry needed.
                                </small>
                            </div>
                            
                            <!-- Driver Assignment -->
                            <div class="col-md-6">
                                <label for="driverSelect" class="form-label">Assign Driver</label>
                                <select class="form-select select2-driver" id="driverSelect" style="width: 100%;" required>
                                    <option value="">Select Driver</option>
                                    <?php foreach ($drivers_list as $driver): ?>
                                        <option value="<?= $driver['driver_id'] ?>" 
                                                data-license="<?= htmlspecialchars($driver['license_number'] ?? '') ?>"
                                                data-vehicle="<?= htmlspecialchars($driver['vehicle_type'] ?? 'N/A') ?>"
                                                data-plate="<?= htmlspecialchars($driver['vehicle_plate_number'] ?? 'N/A') ?>">
                                            <?= htmlspecialchars($driver['driver_name'] . ' - ' . ($driver['vehicle_plate_number'] ?? 'No vehicle')) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="hidden" id="driverId" name="driver_id">
                                <?php if ($drivers_branch_column_exists && !$view_all_branches): ?>
                                    <small class="text-muted">Your branch drivers only</small>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Driver Info Preview -->
                            <div class="col-md-6">
                                <div id="driverInfoPreview" style="display: none;" class="driver-info">
                                    <div class="driver-info-label">Driver Information</div>
                                    <div class="driver-info-value" id="previewDriverName">-</div>
                                    <div class="driver-info-value" id="previewDriverVehicle">-</div>
                                </div>
                            </div>
                            
                            <!-- Encoded By and At -->
                            <div class="col-md-6">
                                <label for="encodedBy" class="form-label">Encoded By</label>
                                <input type="text" class="form-control" id="encodedBy" name="encoded_by" value="<?= $user_id ?>" required readonly>
                                <small class="text-muted">User ID: <?= $user_id ?></small>
                            </div>
                            <div class="col-md-6">
                                <label for="encodedAt" class="form-label">Encoded At</label>
                                <input type="datetime-local" class="form-control" id="encodedAt" name="encoded_at" required readonly>
                            </div>
                        </div>
                        
                        <div class="alert alert-info mt-3">
                            <i class="bi bi-info-circle me-2"></i>
                            <strong>Driver Assignment:</strong> The assigned driver will be responsible for delivering this pick list.
                            <?php if (!$view_all_branches): ?>
                                <br>This pick list will be created for <strong><?php echo htmlspecialchars($branch_name); ?></strong>.
                            <?php endif; ?>
                            <br><strong>Delivery Location:</strong> The location is automatically set from the customer's address/coordinates.
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-circle me-1"></i> Cancel
                    </button>
                    <button type="button" class="btn btn-primary" onclick="saveItem()">
                        <i class="bi bi-check-circle me-1"></i> Save Items
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Item Modal -->
    <div class="modal fade" id="editItemModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>Edit Pick List Item</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="editItemForm" onsubmit="return false;">
                        <input type="hidden" id="editPickItemId">
                        <input type="hidden" id="editItemId">
                        <input type="hidden" id="editPickListId">
                        <input type="hidden" id="editLocationBin">
                        
                        <?php if (!$view_all_branches && $branch_id > 0): ?>
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle"></i>
                            Editing item for <strong><?php echo htmlspecialchars($branch_name); ?></strong>
                        </div>
                        <?php endif; ?>
                        
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Item Code</label>
                                <input type="text" class="form-control" id="editItemCode" readonly>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Item Name</label>
                                <input type="text" class="form-control" id="editItemName" readonly>
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Unit Type</label>
                                <input type="text" class="form-control" id="editUnitType" readonly>
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Customer</label>
                                <input type="text" class="form-control" id="editCustomerName" readonly>
                            </div>
                            
                            <div class="col-md-12">
                                <label class="form-label">Delivery Location</label>
                                <div class="form-control bg-light" id="editLocationDisplay" readonly style="min-height: 38px;">
                                    <span id="editLocationText" class="text-muted">Loading...</span>
                                </div>
                                <small class="text-muted">Location from customer address</small>
                            </div>
                            
                            <div class="col-md-4">
                                <label for="editQuantity" class="form-label">Quantity to Pick</label>
                                <input type="number" class="form-control" id="editQuantity" min="1" required>
                                <small class="text-muted" id="editStockInfo"></small>
                            </div>
                            
                            <div class="col-md-4">
                                <label for="editDriverSelect" class="form-label">Assign Driver</label>
                                <select class="form-select select2-driver-edit" id="editDriverSelect" style="width: 100%;">
                                    <option value="">Select Driver</option>
                                    <?php foreach ($drivers_list as $driver): ?>
                                        <option value="<?= $driver['driver_id'] ?>">
                                            <?= htmlspecialchars($driver['driver_name'] . ' - ' . ($driver['vehicle_plate_number'] ?? 'No vehicle')) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="hidden" id="editDriverId">
                            </div>
                            
                            <div class="col-md-4">
                            </div>
                            
                            <div class="col-12">
                                <div class="alert alert-info" id="editStockAlert">
                                    <i class="bi bi-info-circle me-2"></i>
                                    Current stock: <span id="editCurrentStock">0</span> | 
                                    Current pick quantity: <span id="editCurrentQuantity">0</span>
                                </div>
                            </div>
                            
                            <div class="col-12">
                                <div id="editMapContainer" class="map-container" style="display: none;"></div>
                                <a href="#" id="editMapLink" class="map-link" target="_blank" style="display: none;">View on Google Maps</a>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-circle me-1"></i> Cancel
                    </button>
                    <button type="button" class="btn btn-primary" onclick="updateItem()">
                        <i class="bi bi-check-circle me-1"></i> Update Item
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- View Item Modal -->
    <div class="modal fade" id="viewItemModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-eye"></i> Pick List Item Details
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div id="viewItemDetails" class="row"></div>
                </div>
                <div class="modal-footer">
                    <!-- Footer will be populated dynamically by JavaScript -->
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
                        <?php if (!$view_all_branches): ?>
                            <br>This action is for <strong><?php echo htmlspecialchars($branch_name); ?></strong>.
                        <?php endif; ?>
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

<!-- Mobile Bottom Navigation - Clean Version (No Arrows) -->
<div class="mobile-nav" id="mobileNav">
    <ul class="nav">
        <!-- Dashboard -->
        <li class="nav-item">
            <a class="nav-link active" href="branchdashboard.php">
                <i class="bi bi-speedometer2"></i>
                <span>Dashboard</span>
            </a>
        </li>

        <!-- Warehouse Dropdown -->
        <li class="nav-item dropdown-more" id="warehouseMobileDropdown">
            <a class="nav-link more-btn" href="#" onclick="toggleMobileDropdown(event, 'warehouseMobileMenu')">
                <i class="bi bi-shop"></i>
                <span>Warehouse</span>
            </a>
            <div class="more-dropdown" id="warehouseMobileMenu">
                <a href="current_inventory.php" class="dropdown-item">
                    <i class="bi bi-bar-chart-line"></i><span>Current Inventory</span>
                </a>
                <a href="bad_orders.php" class="dropdown-item">
                    <i class="bi bi-recycle"></i><span>Bad Orders</span>
                </a>
                <a href="pick_list_items.php" class="dropdown-item">
                    <i class="bi bi-list-check"></i><span>Pick List Items</span>
                </a>
                <a href="warehouses.php" class="dropdown-item">
                    <i class="bi bi-shop"></i><span>Warehouses</span>
                </a>
            </div>
        </li>

        <!-- Supplier Dropdown -->
        <li class="nav-item dropdown-more" id="supplierMobileDropdown">
            <a class="nav-link more-btn" href="#" onclick="toggleMobileDropdown(event, 'supplierMobileMenu')">
                <i class="bi bi-building"></i>
                <span>Supplier</span>
            </a>
            <div class="more-dropdown" id="supplierMobileMenu">
                <a href="purchase_order.php" class="dropdown-item">
                    <i class="bi bi-box"></i><span>Receive Inventory</span>
                </a>
                <a href="supplier.php" class="dropdown-item">
                    <i class="bi bi-people"></i><span>Supplier List</span>
                </a>
            </div>
        </li>

        <!-- Customer Dropdown -->
        <li class="nav-item dropdown-more" id="customerMobileDropdown">
            <a class="nav-link more-btn" href="#" onclick="toggleMobileDropdown(event, 'customerMobileMenu')">
                <i class="bi bi-people"></i>
                <span>Customer</span>
            </a>
            <div class="more-dropdown" id="customerMobileMenu">
                <a href="customer_list.php" class="dropdown-item">
                    <i class="bi bi-person-badge"></i><span>Customer List</span>
                </a>
                <a href="approve_credit_requests.php" class="dropdown-item">
                    <i class="bi bi-pencil-square"></i><span>Approve Credit Request</span>
                </a>
                <a href="sales_order.php" class="dropdown-item">
                    <i class="bi bi-cart"></i><span>Sales Order</span>
                </a>
                <a href="collections.php" class="dropdown-item">
                    <i class="bi bi-cash-stack"></i><span>Collections</span>
                </a>
            </div>
        </li>

        <!-- Delivery Dropdown -->
        <li class="nav-item dropdown-more" id="deliveryMobileDropdown">
            <a class="nav-link more-btn" href="#" onclick="toggleMobileDropdown(event, 'deliveryMobileMenu')">
                <i class="bi bi-truck"></i>
                <span>Delivery</span>
            </a>
            <div class="more-dropdown" id="deliveryMobileMenu">
                <a href="trip_tickets.php" class="dropdown-item">
                    <i class="bi bi-ticket-perforated"></i><span>Trip Tickets</span>
                </a>
            </div>
        </li>

        <!-- Banking Dropdown -->
        <li class="nav-item dropdown-more" id="bankingMobileDropdown">
            <a class="nav-link more-btn" href="#" onclick="toggleMobileDropdown(event, 'bankingMobileMenu')">
                <i class="bi bi-bank2"></i>
                <span>Banking</span>
            </a>
            <div class="more-dropdown" id="bankingMobileMenu">
                <a href="deposit.php" class="dropdown-item">
                    <i class="bi bi-arrow-down-circle"></i><span>Deposit</span>
                </a>
                <a href="Withdrawal.php" class="dropdown-item">
                    <i class="bi bi-arrow-up-circle"></i><span>Withdrawal</span>
                </a>
                <a href="bank_statement.php" class="dropdown-item">
                    <i class="bi bi-receipt"></i><span>Bank Statement</span>
                </a>
                <a href="expenses.php" class="dropdown-item">
                    <i class="bi bi-cash-stack"></i><span>Expenses</span>
                </a>
            </div>
        </li>
        
                <!-- Shared Services -->
         <li class="nav-item dropdown-more" id="sharedServicesMobileDropdown">
            <a class="nav-link more-btn" href="#" onclick="toggleMobileDropdown(event, 'sharedServicesMobileMenu')">
                <i class="bi bi-grid-3x3-gap"></i>
                <span>Shared Services</span>
            </a>
            <div class="more-dropdown" id="sharedServicesMobileMenu">
                <a class="dropdown-item" href="motorpool.php">
                    <i class="bi bi-truck"></i>
                    <span class="nav-text">Motorpool</span>
                </a>
                <a class="dropdown-item" href="central_warehouse.php">
                    <i class="bi bi-box-seam"></i>
                    <span class="nav-text">Central Warehouse</span>
                </a>
            </div>  
         </li>

        <!-- Users -->
        <li class="nav-item">
            <a class="nav-link" href="drivers.php">
                <i class="bi bi-people-fill"></i>
                <span>Users</span>
            </a>
        </li>

        <!-- Profile / Logout -->
        <li class="nav-item" id="profileMobileBtn">
            <a href="#" class="nav-link"
                data-bs-toggle="modal"
                data-bs-target="#profileModal">
                    <i class="bi bi-box-arrow-right"></i>
                    <span>Logout</span>
                </a>
        </li>
    </ul>
</div>

    <!-- Mobile Profile Modal -->
    <div class="modal fade" id="profileModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h5 class="modal-title"><i class="bi bi-person-circle me-2"></i>User Profile</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body text-center"><div class="user-avatar-large mb-3"><?php echo $user_initials; ?></div><h4 class="mb-1"><?php echo htmlspecialchars($user_name); ?></h4><p class="text-muted mb-3"><span class="badge bg-success"><?php echo ucfirst($user_role); ?></span></p><?php if (!$view_all_branches && $branch_id > 0): ?><div class="branch-info mb-3"><i class="bi bi-building me-1"></i><span><?php echo htmlspecialchars($branch_name); ?></span></div><?php endif; ?><div class="user-id text-muted small mb-4"><i class="bi bi-hash"></i> User ID: <?php echo $user_id; ?></div><button class="btn btn-danger btn-lg w-100" onclick="confirmLogout()"><i class="bi bi-box-arrow-right me-2"></i>Logout</button></div></div></div></div>

    <!-- jQuery and Select2 JS -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <!-- Leaflet JS for maps -->
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <script>
    // ========== GLOBAL VARIABLES ==========
    const userBranchId = <?php echo $branch_id; ?>;
    const viewAllBranches = <?php echo $view_all_branches ? 'true' : 'false'; ?>;
    const pickListsBranchColumnExists = <?php echo $pick_lists_branch_column_exists ? 'true' : 'false'; ?>;
    const salesOrdersBranchColumnExists = <?php echo $sales_orders_branch_column_exists ? 'true' : 'false'; ?>;
    const itemsBranchColumnExists = <?php echo $items_branch_column_exists ? 'true' : 'false'; ?>;
    const driversBranchColumnExists = <?php echo $drivers_branch_column_exists ? 'true' : 'false'; ?>;
    const userRole = '<?php echo $user_role; ?>';
    const branchName = '<?php echo htmlspecialchars($branch_name); ?>';
    const branchDisplayId = <?php echo $branch_display_id; ?>;
    const logoBase64 = '<?php echo $logo_base64; ?>';
    let selectedPickItemId = null;
    let itemsData = <?= json_encode($items_by_code) ?>;
    let soItemsData = <?= json_encode($so_items_by_so) ?>;
    let driversData = <?= json_encode($drivers_list) ?>;
    let currentUserId = <?= $user_id ?: 1 ?>;
    let availableItems = [];
    let currentSOData = null;
    let maps = {};
    let globalScrollTimeout;

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

    // ========== BRANCH VIEW FUNCTIONS ==========
    function changeBranchView() {
        const selector = document.getElementById('branchViewSelector');
        if (!selector) return;
        
        const branchId = selector.value;
        
        if (branchId === 'all') {
            window.location.href = 'pick_list_items.php?view=all';
        } else {
            window.location.href = 'pick_list_items.php?branch_id=' + branchId;
        }
    }

    // ========== COPY SQL FUNCTION ==========
    function copySQL(table) {
        let sql = '';
        if (table === 'pick_lists') {
            sql = "ALTER TABLE pick_lists ADD COLUMN branch_id INT NULL AFTER so_id;\nALTER TABLE pick_lists ADD FOREIGN KEY (branch_id) REFERENCES branches(branch_id);\nUPDATE pick_lists SET branch_id = 1 WHERE branch_id IS NULL;";
        } else if (table === 'drivers') {
            sql = "ALTER TABLE drivers ADD COLUMN branch_id INT NULL;\nALTER TABLE drivers ADD FOREIGN KEY (branch_id) REFERENCES branches(branch_id);";
        }
        
        navigator.clipboard.writeText(sql).then(() => {
            Swal.fire({
                icon: 'success',
                title: 'Copied!',
                text: 'SQL copied to clipboard',
                timer: 1500,
                showConfirmButton: false
            });
        });
    }

    // ========== FILTER FUNCTIONS ==========
    function applyFilters() {
        const dateFilter = document.getElementById('dateFilter').value;
        const statusFilter = document.getElementById('statusFilter').value;
        const quantityFilter = document.getElementById('quantityFilter').value;
        const branchFilter = document.getElementById('branchFilter')?.value || 'all';
        const searchTerm = document.getElementById('globalSearch').value.toLowerCase();
        
        const rows = document.querySelectorAll('.pick-list-row');
        let visibleCount = 0;
        
        rows.forEach(row => {
            let showRow = true;
            
            if (statusFilter !== 'all') {
                const rowStatus = row.dataset.status;
                if (rowStatus !== statusFilter) showRow = false;
            }
            
            if (showRow && branchFilter !== 'all' && viewAllBranches) {
                const rowBranchId = row.dataset.branchId || '';
                if (rowBranchId != branchFilter) showRow = false;
            }
            
            if (showRow && quantityFilter !== 'all') {
                const rowQuantity = parseFloat(row.dataset.quantity);
                switch(quantityFilter) {
                    case 'lt10': if (rowQuantity >= 10) showRow = false; break;
                    case '10-50': if (rowQuantity < 10 || rowQuantity > 50) showRow = false; break;
                    case '51-100': if (rowQuantity < 51 || rowQuantity > 100) showRow = false; break;
                    case '101-500': if (rowQuantity < 101 || rowQuantity > 500) showRow = false; break;
                    case 'gt500': if (rowQuantity <= 500) showRow = false; break;
                }
            }
            
            if (showRow && searchTerm !== '') {
                const soNumber = row.dataset.soId?.toLowerCase() || '';
                const itemCode = row.dataset.itemCode?.toLowerCase() || '';
                const itemName = row.dataset.itemName?.toLowerCase() || '';
                const unitType = row.dataset.unitType?.toLowerCase() || '';
                const driverName = row.dataset.driverName?.toLowerCase() || '';
                const encodedBy = row.dataset.encodedBy?.toLowerCase() || '';
                const address = row.dataset.address?.toLowerCase() || '';
                const customerName = row.dataset.customerName?.toLowerCase() || '';
                const location = row.querySelector('.col-location')?.innerText.toLowerCase() || '';
                
                const searchableText = soNumber + ' ' + itemCode + ' ' + itemName + ' ' + unitType + ' ' + driverName + ' ' + encodedBy + ' ' + location + ' ' + address + ' ' + customerName;
                if (!searchableText.includes(searchTerm)) showRow = false;
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
                    default:
                        break;
                }
            }
            
            row.style.display = showRow ? '' : 'none';
            if (showRow) visibleCount++;
        });
        
        const emptyStateRow = document.querySelector('.empty-state-table');
        const emptyStateParent = emptyStateRow?.closest('tr');
        
        if (visibleCount === 0) {
            if (emptyStateParent) {
                emptyStateParent.style.display = '';
                emptyStateRow.innerHTML = `
                    <td colspan="${viewAllBranches && pickListsBranchColumnExists ? '13' : '12'}" class="empty-state-table">
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
        
        setupMobileTapToView();
    }

    function clearAllFilters() {
        document.getElementById('dateFilter').value = 'all';
        document.getElementById('statusFilter').value = 'all';
        if (document.getElementById('branchFilter')) {
            document.getElementById('branchFilter').value = 'all';
        }
        document.getElementById('quantityFilter').value = 'all';
        document.getElementById('globalSearch').value = '';
        applyFilters();
    }

    // ========== MODAL FUNCTIONS ==========
    function showAddItemModal() {
        console.log("showAddItemModal called");
        
        <?php if (empty($sales_orders) && !$view_all_branches): ?>
        Swal.fire({
            icon: 'warning',
            title: 'No Sales Orders',
            text: 'No sales orders available for <?php echo htmlspecialchars($branch_name); ?>. You need to create a sales order first.',
            confirmButtonColor: '#0d6efd'
        });
        return;
        <?php endif; ?>
        
        <?php if (empty($drivers_list) && !$view_all_branches): ?>
        Swal.fire({
            icon: 'warning',
            title: 'No Drivers Available',
            text: 'No drivers available for <?php echo htmlspecialchars($branch_name); ?>. Please contact admin to assign drivers.',
            confirmButtonColor: '#0d6efd'
        });
        return;
        <?php endif; ?>
        
        document.getElementById('modalTitle').textContent = 'Add Pick List Item';
        document.getElementById('itemForm').reset();
        document.getElementById('itemId').value = '';
        document.getElementById('soId').value = '';
        document.getElementById('soNumber').value = '';
        document.getElementById('driverId').value = '';
        
        document.getElementById('soDetailsPreview').style.display = 'none';
        document.getElementById('driverInfoPreview').style.display = 'none';
        
        $('#soIdSelect').val('').trigger('change');
        $('#driverSelect').val('').trigger('change');
        
        const tableBody = document.getElementById('itemsSelectionBody');
        if (tableBody) {
            tableBody.innerHTML = `
                <tr id="noItemsMessage">
                    <td colspan="9" class="text-center py-4 text-muted">
                        <i class="bi bi-inbox fs-4 d-block mb-2"></i>
                        Select a Sales Order to see available items
                    </td>
                </tr>
            `;
        }
        
        const selectAll = document.getElementById('selectAllItems');
        if (selectAll) selectAll.checked = false;
        
        const now = new Date();
        const formattedDateTime = now.toISOString().slice(0, 16);
        const encodedAt = document.getElementById('encodedAt');
        if (encodedAt) encodedAt.value = formattedDateTime;
        
        const encodedBy = document.getElementById('encodedBy');
        if (encodedBy) encodedBy.value = currentUserId;
        
        <?php if ($view_all_branches): ?>
        const selectedBranchName = document.getElementById('selectedBranchName');
        if (selectedBranchName) selectedBranchName.textContent = 'your selected branch';
        <?php endif; ?>
        
        currentSOData = null;
        
        const modal = new bootstrap.Modal(document.getElementById('itemModal'));
        modal.show();
    }

    function onSOSelected() {
        const select = document.getElementById('soIdSelect');
        const selectedOption = select.options[select.selectedIndex];
        
        if (selectedOption && selectedOption.value) {
            const soId = selectedOption.value;
            const soNumber = selectedOption.dataset.soNumber;
            const customerName = selectedOption.dataset.customerName;
            const orderDate = selectedOption.dataset.orderDate;
            const branchId = selectedOption.dataset.branchId;
            const branchName = selectedOption.dataset.branchName;
            const latitude = selectedOption.dataset.latitude;
            const longitude = selectedOption.dataset.longitude;
            const address = selectedOption.dataset.address;
            const deliveryInstructions = selectedOption.dataset.deliveryInstructions;
            
            currentSOData = {
                soId: soId,
                soNumber: soNumber,
                customerName: customerName,
                orderDate: orderDate,
                branchId: branchId,
                branchName: branchName,
                latitude: latitude,
                longitude: longitude,
                address: address,
                deliveryInstructions: deliveryInstructions
            };
            
            document.getElementById('soId').value = soId;
            document.getElementById('soNumber').value = soNumber;
            document.getElementById('branchId').value = branchId || userBranchId;
            
            document.getElementById('previewSoNumber').textContent = 'SO #: ' + soNumber;
            document.getElementById('previewCustomer').textContent = 'Customer: ' + customerName;
            document.getElementById('previewOrderDate').textContent = 'Order Date: ' + new Date(orderDate).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
            document.getElementById('previewSoBranch').textContent = 'Branch: ' + (branchName || 'Branch ' + branchId);
            
            let locationText = '';
            if (latitude && longitude) {
                locationText = '📍 Coordinates: ' + parseFloat(latitude).toFixed(6) + ', ' + parseFloat(longitude).toFixed(6);
                if (address) locationText += '<br>🏠 Address: ' + address;
                const mapLink = 'https://www.google.com/maps?q=' + latitude + ',' + longitude;
                locationText += '<br><a href="' + mapLink + '" target="_blank" class="map-link mt-1 d-inline-block"><i class="bi bi-box-arrow-up-right"></i> View on Google Maps</a>';
                document.getElementById('previewAddress').innerHTML = locationText;
            } else if (address) {
                locationText = '🏠 Address: ' + address;
                document.getElementById('previewAddress').innerHTML = locationText;
            } else {
                document.getElementById('previewAddress').innerHTML = 'No location data available';
            }
            
            document.getElementById('soDetailsPreview').style.display = 'block';
            
            <?php if ($view_all_branches): ?>
            document.getElementById('selectedBranchName').textContent = branchName || 'Branch ' + branchId;
            <?php endif; ?>
            
            fetchItemsForSO(soId, latitude, longitude, address);
            
        } else {
            document.getElementById('soId').value = '';
            document.getElementById('soNumber').value = '';
            document.getElementById('soDetailsPreview').style.display = 'none';
            currentSOData = null;
            
            const tableBody = document.getElementById('itemsSelectionBody');
            tableBody.innerHTML = `
                <tr id="noItemsMessage">
                    <td colspan="9" class="text-center py-4 text-muted">
                        <i class="bi bi-inbox fs-4 d-block mb-2"></i>
                        Select a Sales Order to see available items
                    </td>
                </tr>
            `;
            
            const selectAll = document.getElementById('selectAllItems');
            if (selectAll) selectAll.checked = false;
        }
    }

    function fetchItemsForSO(soId, latitude, longitude, address) {
        showLoading();
        
        const formData = new FormData();
        formData.append('action', 'get_so_items');
        formData.append('so_id', soId);
        
        fetch('pick_list_items.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            hideLoading();
            
            if (data.success) {
                const items = data.items || [];
                const customerData = data.customer || {};
                const locLat = latitude || customerData.latitude;
                const locLng = longitude || customerData.longitude;
                const locAddress = address || customerData.full_address;
                populateItemsTable(items, locLat, locLng, locAddress);
            } else {
                Swal.fire('Error', 'Failed to load items for this sales order', 'error');
            }
        })
        .catch(error => {
            hideLoading();
            console.error('Error:', error);
            Swal.fire('Error', 'An error occurred while fetching items', 'error');
        });
    }

    function populateItemsTable(items, latitude, longitude, address) {
        const tableBody = document.getElementById('itemsSelectionBody');
        availableItems = items;
        
        if (items.length === 0) {
            tableBody.innerHTML = `
                <tr>
                    <td colspan="9" class="text-center py-4 text-muted">
                        <i class="bi bi-inbox fs-4 d-block mb-2"></i>
                        No items found for this sales order
                    </td>
                </tr>
            `;
            return;
        }
        
        let locationDisplay = '';
        if (latitude && longitude) {
            locationDisplay = '<i class="bi bi-geo-alt-fill text-primary"></i> ' + parseFloat(latitude).toFixed(6) + ', ' + parseFloat(longitude).toFixed(6);
            if (address) {
                locationDisplay += '<br><small class="text-muted">' + address.substring(0, 50) + (address.length > 50 ? '...' : '') + '</small>';
            }
        } else if (address) {
            locationDisplay = '<i class="bi bi-pin-map-fill text-primary"></i> ' + address.substring(0, 70) + (address.length > 70 ? '...' : '');
        } else {
            locationDisplay = '<span class="text-warning"><i class="bi bi-exclamation-triangle"></i> No location data</span>';
        }
        
        let html = '';
        items.forEach((item) => {
            const orderedQty = item.quantity_ordered || 0;
            const stockQty = item.current_stock || 0;
            const availableToPick = Math.min(orderedQty, stockQty);
            
            if (availableToPick <= 0) return;
            
            const unitType = item.unit_type || item.item_unit_type || 'piece';
            const safeItemName = item.item_name.replace(/'/g, "\\'").replace(/"/g, '&quot;');
            
            html += `
                <tr class="item-row" data-item-id="${item.item_id}" data-item-code="${item.item_code}">
                    <td>
                        <input type="checkbox" class="form-check-input item-select-checkbox" 
                               id="item_${item.item_id}" value="${item.item_id}"
                               data-item-id="${item.item_id}"
                               data-item-code="${item.item_code}"
                               data-item-name="${safeItemName}"
                               data-unit-type="${unitType}"
                               data-max-qty="${availableToPick}"
                               onchange="toggleItemSelection(this)">
                    </td>
                    <td><strong>${item.item_code}</strong></td>
                    <td>${item.item_name}</td>
                    <td>${unitType.toUpperCase()}</td>
                    <td class="text-center">${orderedQty}</td>
                    <td class="text-center">${stockQty}</td>
                    <td class="text-center">
                        <span class="badge ${availableToPick > 0 ? 'bg-success' : 'bg-danger'}">
                            ${availableToPick}
                        </span>
                    </td>
                    <td>
                        <input type="number" class="form-control form-control-sm item-qty-input" 
                               min="1" max="${availableToPick}" value="${Math.min(1, availableToPick)}" 
                               disabled data-item-id="${item.item_id}" onchange="updateItemQuantity(this)">
                    </td>
                    <td>
                        <div class="location-preview" data-item-id="${item.item_id}">
                            ${locationDisplay}
                            <input type="hidden" class="item-location-hidden" value="${latitude && longitude ? latitude + ',' + longitude : (address || 'No location')}">
                        </div>
                    </td>
                </tr>
            `;
        });
        
        if (html === '') {
            tableBody.innerHTML = `
                <tr>
                    <td colspan="9" class="text-center py-4 text-warning">
                        <i class="bi bi-exclamation-triangle fs-4 d-block mb-2"></i>
                        No items available to pick (all out of stock or already picked)
                    </td>
                </tr>
            `;
        } else {
            tableBody.innerHTML = html;
        }
        
        const selectAll = document.getElementById('selectAllItems');
        if (selectAll) selectAll.checked = false;
    }

    function toggleAllItems() {
        const selectAll = document.getElementById('selectAllItems');
        const checkboxes = document.querySelectorAll('.item-select-checkbox');
        
        checkboxes.forEach(checkbox => {
            checkbox.checked = selectAll.checked;
            const row = checkbox.closest('tr');
            const qtyInput = row.querySelector('.item-qty-input');
            if (qtyInput) {
                qtyInput.disabled = !selectAll.checked;
                if (!selectAll.checked) {
                    qtyInput.value = 0;
                } else {
                    const maxQty = parseInt(checkbox.dataset.maxQty) || 1;
                    qtyInput.value = Math.min(1, maxQty);
                }
            }
        });
        
        updateSelectAllState();
    }

    function toggleItemSelection(checkbox) {
        const row = checkbox.closest('tr');
        const qtyInput = row.querySelector('.item-qty-input');
        
        qtyInput.disabled = !checkbox.checked;
        
        if (!checkbox.checked) {
            qtyInput.value = 0;
        } else {
            const maxQty = parseInt(checkbox.dataset.maxQty) || 1;
            qtyInput.value = Math.min(1, maxQty);
        }
        
        updateSelectAllState();
    }

    function updateSelectAllState() {
        const checkboxes = document.querySelectorAll('.item-select-checkbox');
        const selectAll = document.getElementById('selectAllItems');
        
        if (checkboxes.length === 0) {
            selectAll.checked = false;
            selectAll.indeterminate = false;
            return;
        }
        
        const checkedCount = Array.from(checkboxes).filter(cb => cb.checked).length;
        
        if (checkedCount === 0) {
            selectAll.checked = false;
            selectAll.indeterminate = false;
        } else if (checkedCount === checkboxes.length) {
            selectAll.checked = true;
            selectAll.indeterminate = false;
        } else {
            selectAll.checked = false;
            selectAll.indeterminate = true;
        }
    }

    function updateItemQuantity(input) {
        const max = parseInt(input.max);
        let value = parseInt(input.value);
        
        if (isNaN(value) || value < 1) {
            input.value = 1;
        } else if (value > max) {
            input.value = max;
            Swal.fire({
                icon: 'warning',
                title: 'Maximum Quantity',
                text: 'Cannot pick more than ' + max + ' pieces (available stock)',
                timer: 2000,
                showConfirmButton: false
            });
        }
    }

    function getLocationFromRow(row) {
        const locationDiv = row.querySelector('.location-preview');
        if (locationDiv) {
            const hiddenInput = locationDiv.querySelector('.item-location-hidden');
            if (hiddenInput) return hiddenInput.value;
            const text = locationDiv.innerText.trim();
            if (text && text !== 'No location data') return text;
        }
        return 'No location data';
    }

    // ========== SAVE FUNCTIONS ==========
    function saveItem() {
        console.log("saveItem called");
        
        const soId = document.getElementById('soId').value;
        const driverId = document.getElementById('driverId').value;
        
        if (!soId) {
            Swal.fire({ icon: 'warning', title: 'Missing Field', text: 'Please select a Sales Order', confirmButtonColor: '#0d6efd' });
            return;
        }
        
        if (!driverId) {
            Swal.fire({ icon: 'warning', title: 'Missing Field', text: 'Please assign a driver for this pick list', confirmButtonColor: '#0d6efd' });
            return;
        }
        
        const selectedItems = [];
        const checkboxes = document.querySelectorAll('.item-select-checkbox:checked');
        
        if (checkboxes.length === 0) {
            Swal.fire({ icon: 'warning', title: 'No Items Selected', text: 'Please select at least one item to pick', confirmButtonColor: '#0d6efd' });
            return;
        }
        
        let hasError = false;
        checkboxes.forEach(checkbox => {
            const row = checkbox.closest('tr');
            const qtyInput = row.querySelector('.item-qty-input');
            const maxQty = parseInt(checkbox.dataset.maxQty) || 0;
            const quantity = parseInt(qtyInput.value) || 0;
            
            if (quantity <= 0) {
                Swal.fire({ icon: 'warning', title: 'Invalid Quantity', text: 'Please enter a valid quantity for item ' + checkbox.dataset.itemCode, confirmButtonColor: '#0d6efd' });
                hasError = true;
                return;
            }
            
            if (quantity > maxQty) {
                Swal.fire({ icon: 'warning', title: 'Quantity Exceeds Stock', text: 'Cannot pick ' + quantity + ' of ' + checkbox.dataset.itemCode + '. Max available: ' + maxQty, confirmButtonColor: '#0d6efd' });
                hasError = true;
                return;
            }
            
            const location = getLocationFromRow(row);
            
            selectedItems.push({
                item_id: checkbox.value,
                item_code: checkbox.dataset.itemCode,
                quantity: quantity,
                location_bin: location
            });
        });
        
        if (hasError) return;
        
        showLoading();
        
        const savePromises = selectedItems.map(item => {
            const formData = new FormData();
            formData.append('action', 'save_pick_item');
            formData.append('so_id', soId);
            formData.append('item_id', item.item_id);
            formData.append('quantity_to_pick', item.quantity);
            formData.append('location_bin', item.location_bin);
            formData.append('encoded_by', currentUserId);
            formData.append('driver_id', driverId);
            
            return fetch('pick_list_items.php', { method: 'POST', body: formData }).then(response => response.json());
        });
        
        Promise.all(savePromises)
            .then(results => {
                hideLoading();
                const allSuccess = results.every(r => r.success);
                const failedCount = results.filter(r => !r.success).length;
                
                if (allSuccess) {
                    Swal.fire({ icon: 'success', title: 'Success!', text: 'Added ' + selectedItems.length + ' item(s) to pick list successfully.', confirmButtonColor: '#0d6efd', timer: 2000 }).then(() => {
                        const modal = bootstrap.Modal.getInstance(document.getElementById('itemModal'));
                        if (modal) {
                            modal.hide();
                            const backdrop = document.querySelector('.modal-backdrop');
                            if (backdrop) backdrop.remove();
                            document.body.classList.remove('modal-open');
                        }
                        location.reload();
                    });
                } else {
                    const successCount = selectedItems.length - failedCount;
                    Swal.fire({ icon: 'warning', title: 'Partial Success', html: '<p>' + successCount + ' of ' + selectedItems.length + ' items were added successfully.</p><p class="text-danger">' + failedCount + ' item(s) failed.</p>', confirmButtonColor: '#0d6efd' }).then(() => location.reload());
                }
            })
            .catch(error => {
                hideLoading();
                console.error('Error:', error);
                Swal.fire({ icon: 'error', title: 'Error', text: 'An error occurred while saving the items', confirmButtonColor: '#0d6efd' });
            });
    }

    // ========== EDIT FUNCTIONS ==========
    function editItem(id) {
        selectedPickItemId = null;
        showLoading();
        
        const formData = new FormData();
        formData.append('action', 'get_pick_item');
        formData.append('pick_item_id', id);
        
        fetch('pick_list_items.php', { method: 'POST', body: formData })
            .then(response => response.json())
            .then(data => {
                hideLoading();
                
                if (data.success) {
                    const item = data.item;
                    const row = document.querySelector('.pick-list-row[data-id="' + id + '"]');
                    const customerName = row ? row.dataset.customerName : 'Unknown';
                    const latitude = row ? row.dataset.latitude : null;
                    const longitude = row ? row.dataset.longitude : null;
                    const address = row ? row.dataset.address : null;
                    const unitType = row ? row.dataset.unitType : (item.unit_type || 'N/A');
                    
                    document.getElementById('editPickItemId').value = item.pick_item_id;
                    document.getElementById('editItemId').value = item.item_id;
                    document.getElementById('editPickListId').value = item.pick_list_id;
                    document.getElementById('editItemCode').value = item.item_code;
                    document.getElementById('editItemName').value = item.item_name;
                    document.getElementById('editUnitType').value = unitType ? unitType.toUpperCase() : 'N/A';
                    document.getElementById('editCustomerName').value = customerName;
                    document.getElementById('editQuantity').value = item.quantity_to_pick;
                    document.getElementById('editLocationBin').value = item.location_bin || 'No location data';
                    
                    if (maps.editMap) {
                        maps.editMap.remove();
                        maps.editMap = null;
                    }
                    
                    let locationText = '';
                    if (latitude && longitude) {
                        locationText = '📍 ' + parseFloat(latitude).toFixed(6) + ', ' + parseFloat(longitude).toFixed(6);
                        if (address) locationText += '<br><small>' + address + '</small>';
                        const mapLink = 'https://www.google.com/maps?q=' + latitude + ',' + longitude;
                        document.getElementById('editLocationDisplay').innerHTML = locationText;
                        document.getElementById('editMapLink').href = mapLink;
                        document.getElementById('editMapLink').style.display = 'inline-block';
                        setTimeout(() => { initEditMap(latitude, longitude, address); }, 300);
                    } else if (address) {
                        locationText = '📌 ' + address;
                        document.getElementById('editLocationDisplay').innerHTML = locationText;
                        document.getElementById('editMapLink').style.display = 'none';
                        document.getElementById('editMapContainer').style.display = 'none';
                    } else {
                        locationText = item.location_bin || 'No location data';
                        document.getElementById('editLocationDisplay').innerHTML = locationText;
                        document.getElementById('editMapLink').style.display = 'none';
                        document.getElementById('editMapContainer').style.display = 'none';
                    }
                    
                    const maxQty = parseInt(item.current_stock) + parseInt(item.quantity_to_pick);
                    document.getElementById('editQuantity').max = maxQty;
                    document.getElementById('editStockInfo').textContent = 'Max available: ' + maxQty;
                    
                    $('#editDriverSelect').val(item.driver_id || '').trigger('change');
                    document.getElementById('editDriverId').value = item.driver_id || '';
                    
                    const stockAlert = document.getElementById('editStockAlert');
                    if (parseInt(item.current_stock) < parseInt(item.quantity_to_pick)) {
                        stockAlert.className = 'alert alert-warning';
                        stockAlert.innerHTML = '<i class="bi bi-exclamation-triangle me-2"></i> Warning: Current stock (' + item.current_stock + ') is less than pick quantity (' + item.quantity_to_pick + '). You may need to adjust the quantity.';
                    } else {
                        stockAlert.className = 'alert alert-info';
                        stockAlert.innerHTML = '<i class="bi bi-info-circle me-2"></i> Current stock: ' + item.current_stock + ' | Current pick quantity: ' + item.quantity_to_pick;
                    }
                    
                    new bootstrap.Modal(document.getElementById('editItemModal')).show();
                } else {
                    Swal.fire('Error', data.message, 'error');
                }
            })
            .catch(error => {
                hideLoading();
                console.error('Error:', error);
                Swal.fire('Error', 'An error occurred while fetching item details', 'error');
            });
    }

    function initEditMap(lat, lng, address) {
        const mapContainer = document.getElementById('editMapContainer');
        if (!mapContainer) return;
        
        mapContainer.style.display = 'block';
        mapContainer.innerHTML = '';
        
        const map = L.map('editMapContainer').setView([lat, lng], 15);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '© OpenStreetMap contributors' }).addTo(map);
        
        const marker = L.marker([lat, lng]).addTo(map);
        if (address) marker.bindPopup(address).openPopup();
        
        maps.editMap = map;
        
        setTimeout(() => { map.invalidateSize(); }, 100);
    }

    function updateItem() {
        const pickItemId = document.getElementById('editPickItemId').value;
        const quantity = document.getElementById('editQuantity').value;
        const locationBin = document.getElementById('editLocationBin').value;
        const driverId = document.getElementById('editDriverId').value;
        
        if (!quantity || quantity < 1) {
            Swal.fire('Warning', 'Please enter a valid quantity', 'warning');
            return;
        }
        
        const maxQty = parseInt(document.getElementById('editQuantity').max);
        if (parseInt(quantity) > maxQty) {
            Swal.fire('Warning', 'Quantity cannot exceed ' + maxQty, 'warning');
            return;
        }
        
        showLoading();
        
        const formData = new FormData();
        formData.append('action', 'update_pick_item');
        formData.append('pick_item_id', pickItemId);
        formData.append('quantity_to_pick', quantity);
        formData.append('location_bin', locationBin);
        formData.append('driver_id', driverId);
        
        fetch('pick_list_items.php', { method: 'POST', body: formData })
            .then(response => response.json())
            .then(data => {
                hideLoading();
                
                if (data.success) {
                    Swal.fire({ icon: 'success', title: 'Success!', text: data.message, timer: 2000, showConfirmButton: false }).then(() => {
                        const modal = bootstrap.Modal.getInstance(document.getElementById('editItemModal'));
                        if (modal) {
                            modal.hide();
                            const backdrop = document.querySelector('.modal-backdrop');
                            if (backdrop) backdrop.remove();
                            document.body.classList.remove('modal-open');
                        }
                        location.reload();
                    });
                } else {
                    Swal.fire('Error', data.message, 'error');
                }
            })
            .catch(error => {
                hideLoading();
                console.error('Error:', error);
                Swal.fire('Error', 'An error occurred while updating the item', 'error');
            });
    }

   function viewItem(id) {
    const row = document.querySelector('.pick-list-row[data-id="' + id + '"]');
    if (!row) return;
    
    selectedPickItemId = id;
    
    const soNumber = row.dataset.soId || 'N/A';
    const itemCode = row.dataset.itemCode || 'N/A';
    const itemName = row.dataset.itemName || 'N/A';
    const unitType = row.dataset.unitType || 'N/A';
    const quantity = row.dataset.quantity || '0';
    const picked = row.dataset.picked || '0';
    const location = row.dataset.location || 'No location data';
    const status = row.dataset.status || '';
    const driverName = row.dataset.driverName || 'Unassigned';
    const stockText = row.querySelector('.stock-indicator')?.innerText || 'Stock: 0';
    const branchName = row.dataset.branchName || 'Branch ' + row.dataset.branchId;
    const encodedBy = row.dataset.encodedBy || 'System';
    const encodedAt = row.dataset.encodedAt ? new Date(row.dataset.encodedAt).toLocaleString() : 'N/A';
    const customerName = row.dataset.customerName || 'N/A';
    const latitude = row.dataset.latitude;
    const longitude = row.dataset.longitude;
    const address = row.dataset.address;
    
    const isCompleted = (status === 'completed');
    
    const canEditDelete = (!isCompleted && status === 'open' && (viewAllBranches || (pickListsBranchColumnExists && row.dataset.branchId == userBranchId)));
    
    if (maps.viewMap) {
        maps.viewMap.remove();
        maps.viewMap = null;
    }
    
    let detailsHtml = `
        <div class="col-md-6">
            <div class="detail-card"><div class="detail-label">SO Number</div><div class="detail-value">${soNumber}</div></div>
            <div class="detail-card"><div class="detail-label">Customer</div><div class="detail-value">${customerName}</div></div>
            <div class="detail-card"><div class="detail-label">Item Code</div><div class="detail-value">${itemCode}</div></div>
            <div class="detail-card"><div class="detail-label">Item Name</div><div class="detail-value">${itemName}</div></div>
            <div class="detail-card"><div class="detail-label">Unit Type</div><div class="detail-value">${unitType.toUpperCase()}</div></div>
            <div class="detail-card"><div class="detail-label">Quantity to Pick</div><div class="detail-value">${quantity}</div></div>
            <div class="detail-card"><div class="detail-label">Quantity Picked</div><div class="detail-value">${picked}</div></div>
        </div>
        <div class="col-md-6">
    `;
    
    if (viewAllBranches && pickListsBranchColumnExists) {
        detailsHtml += `<div class="detail-card"><div class="detail-label">Branch</div><div class="detail-value"><span class="badge bg-info">${branchName}</span></div></div>`;
    }
    
    detailsHtml += `
            <div class="detail-card"><div class="detail-label">Delivery Location</div><div class="detail-value">${location}</div></div>
            <div class="detail-card"><div class="detail-label">Status</div><div class="detail-value"><span class="badge ${getPickStatusBadge(status)}">${getPickStatusText(status)}</span></div></div>
            <div class="detail-card"><div class="detail-label">Assigned Driver</div><div class="detail-value"><span class="driver-badge"><i class="bi bi-truck"></i> ${driverName}</span></div></div>
            <div class="detail-card"><div class="detail-label">Encoded By</div><div class="detail-value"><span class="badge bg-secondary"><i class="bi bi-person"></i> ${encodedBy}</span></div></div>
            <div class="detail-card"><div class="detail-label">Encoded At</div><div class="detail-value"><span class="text-muted"><i class="bi bi-calendar"></i> ${encodedAt}</span></div></div>
            <div class="detail-card"><div class="detail-label">Current Stock</div><div class="detail-value">${stockText}</div></div>
        </div>
    `;
    
    if (latitude && longitude) {
        detailsHtml += `
            <div class="col-12 mt-3">
                <div class="detail-card">
                    <div class="detail-label">Location Map</div>
                    <div id="viewMapContainer" class="map-container"></div>
                    <a href="https://www.google.com/maps?q=${latitude},${longitude}" target="_blank" class="map-link mt-2 d-block">
                        <i class="bi bi-box-arrow-up-right"></i> View on Google Maps
                    </a>
                </div>
            </div>
        `;
    }
    
    document.getElementById('viewItemDetails').innerHTML = detailsHtml;
    
    if (latitude && longitude) {
        setTimeout(() => {
            const mapContainer = document.getElementById('viewMapContainer');
            if (mapContainer) {
                const map = L.map('viewMapContainer').setView([latitude, longitude], 15);
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '© OpenStreetMap contributors' }).addTo(map);
                const marker = L.marker([latitude, longitude]).addTo(map);
                if (address) marker.bindPopup(address).openPopup();
                maps.viewMap = map;
                setTimeout(() => { map.invalidateSize(); }, 100);
            }
        }, 300);
    }
    
    // Update modal footer
    const modalFooter = document.querySelector('#viewItemModal .modal-footer');
    if (modalFooter) {
        if (isCompleted) {
            // Walang buttons sa footer kapag completed
            modalFooter.innerHTML = '';
            modalFooter.style.display = 'none';
        } else {
            modalFooter.style.display = 'flex';
            if (canEditDelete) {
                modalFooter.innerHTML = `
                    <button type="button" class="btn btn-warning" onclick="editFromView()">
                        <i class="bi bi-pencil me-1"></i> Edit
                    </button>
                    <button type="button" class="btn btn-danger" onclick="deleteFromView()">
                        <i class="bi bi-trash me-1"></i> Delete
                    </button>
                `;
            } else {
                // Walang buttons kung hindi completed pero hindi rin pwedeng i-edit/delete
                modalFooter.innerHTML = '';
                modalFooter.style.display = 'none';
            }
        }
    }
    
    new bootstrap.Modal(document.getElementById('viewItemModal')).show();
}

    function editFromView() {
        bootstrap.Modal.getInstance(document.getElementById('viewItemModal')).hide();
        setTimeout(() => { if (selectedPickItemId) editItem(selectedPickItemId); }, 300);
    }
    
    function deleteFromView() {
        bootstrap.Modal.getInstance(document.getElementById('viewItemModal')).hide();
        setTimeout(() => { 
            if (selectedPickItemId) {
                deleteItem(selectedPickItemId);
            }
        }, 300);
    }

    // ========== DELETE FUNCTIONS ==========
    function deleteItem(id) {
        window.itemToDelete = id;
        new bootstrap.Modal(document.getElementById('deleteModal')).show();
    }

    function confirmDelete() {
        const pickItemId = window.itemToDelete;
        
        if (!pickItemId) {
            Swal.fire({ icon: 'error', title: 'Error', text: 'No item selected', confirmButtonColor: '#0d6efd' });
            return;
        }
        
        showLoading();
        
        const formData = new FormData();
        formData.append('action', 'delete_pick_item');
        formData.append('pick_item_id', pickItemId);
        
        fetch('pick_list_items.php', { method: 'POST', body: formData })
            .then(response => response.json())
            .then(data => {
                hideLoading();
                
                if (data.success) {
                    Swal.fire({ icon: 'success', title: 'Deleted!', text: data.message, confirmButtonColor: '#0d6efd', timer: 2000 }).then(() => {
                        const modal = bootstrap.Modal.getInstance(document.getElementById('deleteModal'));
                        if (modal) {
                            modal.hide();
                            const backdrop = document.querySelector('.modal-backdrop');
                            if (backdrop) backdrop.remove();
                            document.body.classList.remove('modal-open');
                        }
                        location.reload();
                    });
                } else {
                    Swal.fire({ icon: 'error', title: 'Error', text: data.message, confirmButtonColor: '#0d6efd' });
                }
            })
            .catch(error => {
                hideLoading();
                console.error('Error:', error);
                Swal.fire({ icon: 'error', title: 'Error', text: 'An error occurred while deleting the item', confirmButtonColor: '#0d6efd' });
            });
    }

    // ========== DRIVER SELECTION HANDLER ==========
$(document).ready(function() {
    console.log("Document ready - initializing Select2");
    
    // Fix: Initialize Select2 with dropdownParent set to body to prevent cutoff
    $('.select2-so').select2({ 
        placeholder: 'Search Sales Order...', 
        allowClear: true, 
        dropdownParent: $('body'),
        width: '100%'
    });
    
    $('.select2-driver').select2({ 
        placeholder: 'Search Driver...', 
        allowClear: true, 
        dropdownParent: $('body'),
        width: '100%'
    });
    
    $('.select2-driver-edit').select2({ 
        placeholder: 'Select Driver...', 
        allowClear: true, 
        dropdownParent: $('body'),
        width: '100%'
    });

    // Also initialize when modal is shown (for dynamic content)
    $('#itemModal').on('shown.bs.modal', function() {
        $('.select2-so').select2({
            placeholder: 'Search Sales Order...',
            allowClear: true,
            dropdownParent: $('body'),
            width: '100%'
        });
        $('.select2-driver').select2({
            placeholder: 'Search Driver...',
            allowClear: true,
            dropdownParent: $('body'),
            width: '100%'
        });
    });
    
    $('#editItemModal').on('shown.bs.modal', function() {
        $('.select2-driver-edit').select2({
            placeholder: 'Select Driver...',
            allowClear: true,
            dropdownParent: $('body'),
            width: '100%'
        });
    });

    $('#driverSelect').on('change', function() {
        const select = document.getElementById('driverSelect');
        const selectedOption = select.options[select.selectedIndex];
        
        if (selectedOption && selectedOption.value) {
            const driverId = selectedOption.value;
            const driverName = selectedOption.text.split(' - ')[0];
            const vehiclePlate = selectedOption.dataset.plate || 'N/A';
            const vehicleType = selectedOption.dataset.vehicle || 'N/A';
            
            document.getElementById('driverId').value = driverId;
            document.getElementById('previewDriverName').textContent = 'Driver: ' + driverName;
            document.getElementById('previewDriverVehicle').textContent = 'Vehicle: ' + vehicleType + ' - ' + vehiclePlate;
            document.getElementById('driverInfoPreview').style.display = 'block';
        } else {
            document.getElementById('driverId').value = '';
            document.getElementById('driverInfoPreview').style.display = 'none';
        }
    });
    
    $('#editDriverSelect').on('change', function() {
        const select = document.getElementById('editDriverSelect');
        const selectedOption = select.options[select.selectedIndex];
        document.getElementById('editDriverId').value = selectedOption && selectedOption.value ? selectedOption.value : '';
    });
    
    $('#itemModal, #editItemModal, #deleteModal, #viewItemModal').on('hidden.bs.modal', function () {
        const backdrop = document.querySelector('.modal-backdrop');
        if (backdrop) backdrop.remove();
        document.body.classList.remove('modal-open');
        document.body.style.removeProperty('padding-right');
        
        if (maps.viewMap) { maps.viewMap.remove(); maps.viewMap = null; }
        if (maps.editMap) { maps.editMap.remove(); maps.editMap = null; }
        
        const editMapContainer = document.getElementById('editMapContainer');
        if (editMapContainer) { editMapContainer.innerHTML = ''; editMapContainer.style.display = 'none'; }
        
        const viewMapContainer = document.getElementById('viewMapContainer');
        if (viewMapContainer) viewMapContainer.innerHTML = '';
    });
    
    $('#viewItemModal').on('shown.bs.modal', function() { if (maps.viewMap) setTimeout(() => maps.viewMap.invalidateSize(), 100); });
    $('#editItemModal').on('shown.bs.modal', function() { if (maps.editMap) setTimeout(() => maps.editMap.invalidateSize(), 100); });
});

    // ========== HELPER FUNCTIONS ==========
    function getPickStatusBadge(status) {
        const classes = { 'open': 'bg-warning text-dark', 'in-progress': 'bg-primary text-white', 'completed': 'bg-success text-white', 'cancelled': 'bg-danger text-white' };
        return classes[status] || 'bg-secondary text-white';
    }

    function getPickStatusText(status) {
        const texts = { 'open': 'Pending', 'in-progress': 'In Progress', 'completed': 'Completed', 'cancelled': 'Cancelled' };
        return texts[status] || status;
    }

    // ========== EXCEL EXPORT FUNCTION ==========
    function exportToExcel() {
        const rows = document.querySelectorAll('.pick-list-row:not([style*="display: none"])');
        if (rows.length === 0) {
            Swal.fire({ icon: 'warning', title: 'No Data', text: 'No pick list items to export', confirmButtonColor: '#0d6efd' });
            return;
        }
        
        const excelData = [];
        const headers = ['SO Number', 'Customer Name', ...(viewAllBranches && pickListsBranchColumnExists ? ['Branch'] : []), 'Item Code', 'Item Name', 'Unit Type', 'Quantity to Pick', 'Quantity Picked', 'Delivery Location', 'Coordinates', 'Status', 'Assigned Driver', 'Encoded By', 'Encoded At', 'Current Stock'];
        excelData.push(headers);

        rows.forEach(row => {
            if (row.style.display !== 'none') {
                const cells = row.querySelectorAll('td');
                let cellIndex = 0;
                const soNumberCell = cells[cellIndex++];
                const soNumber = soNumberCell?.innerText.split('\n')[0].trim() || '';
                const customerName = soNumberCell?.querySelector('small')?.innerText || '';
                let branchName = '';
                if (viewAllBranches && pickListsBranchColumnExists) branchName = cells[cellIndex++]?.innerText.replace(/\n/g, ' ').trim() || '';
                const itemCode = cells[cellIndex++]?.innerText || '';
                const itemName = cells[cellIndex++]?.innerText.split('\n')[0].trim() || '';
                const unitType = cells[cellIndex++]?.innerText || '';
                const toPick = parseInt(cells[cellIndex++]?.innerText) || 0;
                const picked = parseInt(cells[cellIndex++]?.innerText) || 0;
                const locationHtml = cells[cellIndex++]?.innerHTML || '';
                
                let locationText = '', coordinates = '';
                const locationMatch = locationHtml.match(/📍 ([\d.-]+), ([\d.-]+)/);
                if (locationMatch) {
                    coordinates = locationMatch[1] + ', ' + locationMatch[2];
                    const addressMatch = locationHtml.match(/<small>(.*?)<\/small>/);
                    locationText = addressMatch ? addressMatch[1] : '';
                } else {
                    locationText = locationHtml.replace(/<[^>]*>/g, '').trim();
                }
                
                const status = cells[cellIndex++]?.innerText || '';
                const driver = cells[cellIndex++]?.innerText || '';
                const encodedBy = cells[cellIndex++]?.innerText || '';
                const encodedAt = cells[cellIndex++]?.innerText || '';
                
                let stock = 0;
                const stockElement = row.querySelector('.stock-indicator');
                if (stockElement) { const stockMatch = stockElement.innerText.match(/\d+/); if (stockMatch) stock = parseInt(stockMatch[0]); }
                
                excelData.push([soNumber, customerName, ...(viewAllBranches && pickListsBranchColumnExists ? [branchName] : []), itemCode, itemName, unitType, toPick, picked, locationText, coordinates, status, driver, encodedBy, encodedAt, stock]);
            }
        });

        const wb = XLSX.utils.book_new();
        const ws = XLSX.utils.aoa_to_sheet(excelData);
        ws['!cols'] = [{ wch: 15 }, { wch: 20 }, ...(viewAllBranches && pickListsBranchColumnExists ? [{ wch: 15 }] : []), { wch: 15 }, { wch: 30 }, { wch: 10 }, { wch: 15 }, { wch: 15 }, { wch: 40 }, { wch: 30 }, { wch: 15 }, { wch: 20 }, { wch: 20 }, { wch: 20 }, { wch: 15 }];
        XLSX.utils.book_append_sheet(wb, ws, 'Pick List Items');
        const date = new Date();
        const dateStr = date.toISOString().slice(0,10).replace(/-/g, '');
        let filename = 'Pick_List_Items_' + dateStr;
        if (!viewAllBranches) filename += '_' + branchName.replace(/\s+/g, '_');
        filename += '.xlsx';
        XLSX.writeFile(wb, filename);
        Swal.fire({ icon: 'success', title: 'Export Complete', text: 'Pick list items exported successfully!', confirmButtonColor: '#0d6efd', timer: 2000 });
    }

    // ========== PRINT FUNCTION ==========
    function printPickList() {
        const printBtn = document.querySelector('.btn-outline-primary[onclick="printPickList()"]');
        if (printBtn) { printBtn.innerHTML = '<i class="bi bi-printer"></i> Preparing...'; printBtn.disabled = true; }

        const filterData = {
            date: document.getElementById('dateFilter').value,
            status: document.getElementById('statusFilter').value,
            branch: document.getElementById('branchFilter')?.value || 'all',
            quantity: document.getElementById('quantityFilter').value,
            search: document.getElementById('globalSearch').value
        };
        
        showLoading();
        
        const formData = new FormData();
        formData.append('action', 'print_pick_list');
        formData.append('filter_data', JSON.stringify(filterData));
        
        fetch('pick_list_items.php', { method: 'POST', body: formData })
            .then(response => response.json())
            .then(data => {
                hideLoading();
                if (data.success && data.items.length > 0) {
                    const htmlContent = generatePrintHTML(data.items, data.branch_name, data.view_all);
                    const iframe = document.getElementById('printFrame');
                    const iframeDoc = iframe.contentWindow.document;
                    iframeDoc.open();
                    iframeDoc.write(htmlContent);
                    iframeDoc.close();
                    setTimeout(() => iframe.contentWindow.print(), 250);
                } else {
                    Swal.fire({ icon: 'warning', title: 'No Data', text: 'No pick list items match the current filters', confirmButtonColor: '#0d6efd' });
                }
                if (printBtn) { printBtn.innerHTML = '<i class="bi bi-printer"></i> Print'; printBtn.disabled = false; }
            })
            .catch(error => {
                hideLoading();
                console.error('Error:', error);
                Swal.fire({ icon: 'error', title: 'Error', text: 'An error occurred while preparing print', confirmButtonColor: '#0d6efd' });
                if (printBtn) { printBtn.innerHTML = '<i class="bi bi-printer"></i> Print'; printBtn.disabled = false; }
            });
    }

    function generatePrintHTML(items, branchName, viewAll) {
        let tableRows = '';
        let totalItems = 0;
        let totalQuantity = 0;
        
        const pickLists = {};
        items.forEach(item => {
            if (!pickLists[item.pick_list_id]) {
                pickLists[item.pick_list_id] = { pick_list_number: item.pick_list_number, pick_date: item.pick_date, pick_status: item.pick_status, driver_name: item.assigned_driver || 'Unassigned', branch_name: item.branch_name, items: [] };
            }
            pickLists[item.pick_list_id].items.push(item);
            totalItems++;
            totalQuantity += parseInt(item.quantity_to_pick) || 0;
        });
        
        items.forEach(item => {
            let locationDisplay = '';
            if (item.latitude && item.longitude) {
                locationDisplay = parseFloat(item.latitude).toFixed(6) + ', ' + parseFloat(item.longitude).toFixed(6);
            } else if (item.full_address) {
                locationDisplay = item.full_address.substring(0, 40) + (item.full_address.length > 40 ? '...' : '');
            } else {
                locationDisplay = 'No location';
            }
            
            tableRows += '<tr>';
            tableRows += '<td style="padding: 3px; border: 1px solid #000;">' + (item.so_number || 'N/A') + '</td>';
            if (viewAll && pickListsBranchColumnExists) {
                tableRows += '<td style="padding: 3px; border: 1px solid #000;">' + (item.branch_name || 'Branch ' + item.branch_id) + '</td>';
            }
            tableRows += '<td style="padding: 3px; border: 1px solid #000;">' + item.item_code + '</td>';
            tableRows += '<td style="padding: 3px; border: 1px solid #000;">' + item.item_name + '</td>';
            tableRows += '<td style="padding: 3px; border: 1px solid #000;">' + (item.unit_type ? item.unit_type.toUpperCase() : 'N/A') + '</td>';
            tableRows += '<td style="padding: 3px; border: 1px solid #000; text-align: center;">' + item.quantity_to_pick + '</td>';
            tableRows += '<td style="padding: 3px; border: 1px solid #000;">' + locationDisplay + '</td>';
            tableRows += '<td style="padding: 3px; border: 1px solid #000;">' + item.pick_status + '</td>';
            tableRows += '<td style="padding: 3px; border: 1px solid #000;">' + (item.assigned_driver || 'Unassigned') + '</td>';
            tableRows += '<td style="padding: 3px; border: 1px solid #000;">' + (item.encoded_by_name || 'System') + '</td>';
            tableRows += '</tr>';
        });
        
        const currentDate = new Date().toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
        
        return '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Pick List Items Report</title><style>body{font-family:Arial;margin:0;padding:0;font-size:9px}.print-container{max-width:100%;margin:0}.print-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:5px;border-bottom:1px solid #000;padding-bottom:3px}.logo-section{display:flex;align-items:center;gap:5px}.company-logo{width:30px;height:auto}.company-info h1{font-size:14px;margin:0;font-weight:bold}.company-info p{font-size:8px;margin:0}.report-title h2{font-size:12px;margin:0}.report-title .date-info{font-size:8px}.summary-box{border:1px solid #000;padding:3px;margin-bottom:5px;display:flex}.summary-item{flex:1;text-align:center;border-right:1px solid #000}.summary-item:last-child{border-right:none}.summary-label{font-size:8px;font-weight:bold}.summary-value{font-size:11px;font-weight:bold}table{width:100%;border-collapse:collapse;font-size:8px}th{border:1px solid #000;padding:3px;text-align:left;font-weight:bold;background:white !important;color:black !important}td{border:1px solid #000;padding:3px}.total-row{font-weight:bold}.print-footer{margin-top:5px;border-top:1px solid #000;padding-top:3px;display:flex;justify-content:space-between;font-size:8px}</style></head><body><div class="print-container"><div class="print-header"><div class="logo-section"><img src="' + logoBase64 + '" alt="AMGC Logo" class="company-logo"><div class="company-info"><h1>AMGC</h1><p>Pick List Report</p></div></div><div class="report-title"><h2>PICK LIST ITEMS</h2><div class="date-info">' + currentDate + '</div></div></div><div class="summary-box"><div class="summary-item"><div class="summary-label">Total Items</div><div class="summary-value">' + totalItems + '</div></div><div class="summary-item"><div class="summary-label">Total Qty</div><div class="summary-value">' + totalQuantity + '</div></div><div class="summary-item"><div class="summary-label">Branch</div><div class="summary-value">' + (!viewAll ? branchName : 'All') + '</div></div></div><table><thead><tr><th>SO #</th>' + (viewAll && pickListsBranchColumnExists ? '<th>Branch</th>' : '') + '<th>Item Code</th><th>Item Name</th><th>Unit</th><th style="text-align:center">Qty</th><th>Location</th><th>Status</th><th>Driver</th><th>Encoded By</th></tr></thead><tbody>' + tableRows + '<tr class="total-row"><td colspan="' + (viewAll && pickListsBranchColumnExists ? '5' : '4') + '" style="text-align:right">TOTAL</td><td style="text-align:center">' + totalQuantity + '</td><td colspan="' + (viewAll && pickListsBranchColumnExists ? '5' : '4') + '"></td></tr></tbody></table><div class="print-footer"><div>Generated: ' + currentDate + '</div><div>' + (document.querySelector('.user-name-sidebar')?.textContent || 'Branch Admin') + '</div></div></div></body></html>';
    }
    
    function cleanupModalBackdrops() {
        document.querySelectorAll('.modal-backdrop').forEach(backdrop => backdrop.remove());
        document.body.classList.remove('modal-open');
        document.body.style.removeProperty('padding-right');
        document.body.style.removeProperty('overflow');
        if (document.body.hasAttribute('style')) {
            const style = document.body.getAttribute('style');
            if (style && (style.includes('padding-right') || style.includes('overflow'))) {
                document.body.removeAttribute('style');
            }
        }
    }
function confirmLogout() {
        const modal = bootstrap.Modal.getInstance(document.getElementById('profileModal'));
        if (modal) {
            modal.hide();
        }
        
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
                window.location.href = '../logout.php';
            }
        });
    }

    function logout() { confirmLogout(); }
    
    document.addEventListener('DOMContentLoaded', function() {
        console.log("DOM fully loaded - Pick List Items with Customer Location");
        console.log("User Branch:", userBranchId);
        console.log("View All Branches:", viewAllBranches);
        console.log("User Role:", userRole);
        console.log("Branch Name:", branchName);
        
        initializeSidebar();
        setActiveMobileNav();
        
        const mobileMenuBtn = document.getElementById('mobileMenuBtn');
        if (mobileMenuBtn) {
            mobileMenuBtn.addEventListener('click', function(e) { e.preventDefault(); e.stopPropagation(); toggleSidebar(); });
        }
        
        const desktopToggleBtn = document.getElementById('desktopToggleBtn');
        if (desktopToggleBtn) {
            desktopToggleBtn.addEventListener('click', function(e) { e.preventDefault(); e.stopPropagation(); toggleSidebar(); });
        }
        
        document.querySelectorAll('.sidebar .nav-link').forEach(link => {
            link.addEventListener('click', function() { if (window.innerWidth <= 992) closeMobileSidebar(); });
        });

        document.addEventListener('click', function(event) {
            const sidebar = document.getElementById('sidebar');
            const mobileBtn = document.getElementById('mobileMenuBtn');
            const overlay = document.querySelector('.sidebar-overlay');
            const isMobile = window.innerWidth <= 992;
            if (isMobile && sidebar.classList.contains('active') && !sidebar.contains(event.target) && !mobileBtn.contains(event.target) && !overlay?.contains(event.target)) {
                closeMobileSidebar();
            }
        });
        
        const modals = ['itemModal', 'editItemModal', 'deleteModal', 'viewItemModal'];
        modals.forEach(modalId => {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.addEventListener('hidden.bs.modal', function () {
                    const backdrop = document.querySelector('.modal-backdrop');
                    if (backdrop) backdrop.remove();
                    document.body.classList.remove('modal-open');
                    document.body.style.removeProperty('padding-right');
                    document.body.style.removeProperty('overflow');
                    if (maps.viewMap) { maps.viewMap.remove(); maps.viewMap = null; }
                    if (maps.editMap) { maps.editMap.remove(); maps.editMap = null; }
                    const editMapContainer = document.getElementById('editMapContainer');
                    if (editMapContainer) { editMapContainer.innerHTML = ''; editMapContainer.style.display = 'none'; }
                });
            }
        });
        
        // Initialize tap to view on mobile
        setupMobileTapToView();
    });

    document.addEventListener('keydown', function(e) {
        if (e.ctrlKey && e.key === 'b' && window.innerWidth > 992) { e.preventDefault(); toggleSidebar(); }
        else if (e.ctrlKey && e.key === 'f') { e.preventDefault(); const searchInput = document.getElementById('globalSearch'); if (searchInput) searchInput.focus(); }
        else if (e.ctrlKey && e.key === 'n') { e.preventDefault(); showAddItemModal(); }
        else if (e.ctrlKey && e.key === 'p') { e.preventDefault(); printPickList(); }
    });


    // ========== MOBILE BOTTOM NAVBAR FIX ==========
    // Global functions because mobile bottom nav uses inline onclick handlers.
    window.closeAllMobileDropdowns = function() {
        const dropdowns = document.querySelectorAll(
            '.mobile-nav .more-dropdown, #inventoryDropdownMenu, #salesDropdownMenu, #purchaseDropdownMenu, #moreDropdownMenu'
        );

        dropdowns.forEach(function(dropdown) {
            dropdown.classList.remove('show');
        });

        document.querySelectorAll('.mobile-nav .more-btn, .more-btn').forEach(function(btn) {
            btn.classList.remove('active');
            btn.setAttribute('aria-expanded', 'false');
        });
    };

    window.toggleMobileDropdown = function(event, dropdownId) {
        if (event) {
            event.preventDefault();
            event.stopPropagation();
        }

        const dropdown = document.getElementById(dropdownId);
        const btn = event ? event.currentTarget : null;

        if (!dropdown) {
            console.error('Mobile dropdown not found:', dropdownId);
            return false;
        }

        const isOpen = dropdown.classList.contains('show');

        window.closeAllMobileDropdowns();

        if (!isOpen) {
            dropdown.classList.add('show');

            if (btn) {
                btn.classList.add('active');
                btn.setAttribute('aria-expanded', 'true');
            }
        }

        return false;
    };

    // Compatibility for old onclick="toggleDropdown(...)" buttons.
    window.toggleDropdown = function(event, dropdownId) {
        return window.toggleMobileDropdown(event, dropdownId);
    };

    window.showProfileModal = function(event) {
        if (event) {
            event.preventDefault();
            event.stopPropagation();
        }

        if (typeof cleanupModalBackdrops === 'function') {
            cleanupModalBackdrops();
        }

        window.closeAllMobileDropdowns();

        const profileModalEl = document.getElementById('profileModal');

        if (profileModalEl && typeof bootstrap !== 'undefined') {
            bootstrap.Modal.getOrCreateInstance(profileModalEl).show();
        } else {
            console.error('Profile modal or Bootstrap is missing.');
        }

        return false;
    };

    document.addEventListener('click', function(e) {
        if (!e.target.closest('.mobile-nav')) {
            window.closeAllMobileDropdowns();
        }
    });

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            window.closeAllMobileDropdowns();
        }
    });

    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('.mobile-nav .dropdown-item').forEach(function(item) {
            item.addEventListener('click', function() {
                window.closeAllMobileDropdowns();
            });
        });

        const profileModalEl = document.getElementById('profileModal');
        if (profileModalEl) {
            profileModalEl.addEventListener('show.bs.modal', function() {
                window.closeAllMobileDropdowns();
            });
        }

        if (typeof setActiveMobileNav === 'function') {
            setActiveMobileNav();
        }
    });


    function toggleMoreDropdown(event) {
        event.preventDefault(); event.stopPropagation();
        const dropdown = document.getElementById('moreDropdownMenu');
        const moreBtn = document.querySelector('.more-btn');
        if (dropdown.classList.contains('show')) {
            dropdown.classList.remove('show'); moreBtn.classList.remove('active'); document.removeEventListener('click', closeMoreDropdown);
        } else {
            document.querySelectorAll('.more-dropdown.show').forEach(d => d.classList.remove('show'));
            document.querySelectorAll('.more-btn.active').forEach(btn => btn.classList.remove('active'));
            dropdown.classList.add('show'); moreBtn.classList.add('active');
            setTimeout(() => { document.addEventListener('click', closeMoreDropdown); }, 100);
        }
    }

    function closeMoreDropdown(event) {
        const dropdown = document.getElementById('moreDropdownMenu');
        const moreBtn = document.querySelector('.more-btn');
        const moreContainer = document.querySelector('.dropdown-more');
        if (moreContainer && !moreContainer.contains(event.target)) {
            dropdown.classList.remove('show'); moreBtn.classList.remove('active'); document.removeEventListener('click', closeMoreDropdown);
        }
    }

    document.addEventListener('keydown', function(e) { if (e.key === 'Escape') { const dropdown = document.getElementById('moreDropdownMenu'); if (dropdown && dropdown.classList.contains('show')) { dropdown.classList.remove('show'); document.querySelector('.more-btn')?.classList.remove('active'); } } });
    
    window.addEventListener('scroll', function() {
        const dropdown = document.getElementById('moreDropdownMenu');
        if (dropdown && dropdown.classList.contains('show')) {
            if (globalScrollTimeout) clearTimeout(globalScrollTimeout);
            globalScrollTimeout = setTimeout(() => { dropdown.classList.remove('show'); document.querySelector('.more-btn')?.classList.remove('active'); }, 150);
        }
    });
function fixPurchaseDropdownPosition() {
        const purchaseDropdown = document.querySelector('#purchaseDropdown .more-dropdown');
        if (purchaseDropdown) { purchaseDropdown.style.setProperty('right', '0', 'important'); purchaseDropdown.style.setProperty('left', 'auto', 'important'); }
    }
    
    document.addEventListener('DOMContentLoaded', function() { fixPurchaseDropdownPosition(); setActiveMobileNav(); });
    window.addEventListener('resize', fixPurchaseDropdownPosition);
    
    const purchaseMenu = document.getElementById('purchaseDropdownMenu');
    if (purchaseMenu) {
        new MutationObserver(mutations => { mutations.forEach(mutation => { if (mutation.type === 'attributes' && mutation.attributeName === 'class' && purchaseMenu.classList.contains('show')) fixPurchaseDropdownPosition(); }); }).observe(purchaseMenu, { attributes: true });
    }

    function setActiveMobileNav() {
        const currentPage = window.location.pathname.split('/').pop();
        
        document.querySelectorAll('.mobile-nav .nav-link, .more-btn, .dropdown-item, .has-active').forEach(el => {
            el.classList.remove('active', 'has-active');
        });
        
        document.querySelectorAll('.mobile-nav .nav-link:not(.more-btn)').forEach(link => {
            if (link.getAttribute('href') === currentPage) link.classList.add('active');
        });
        
        document.querySelectorAll('.more-dropdown .dropdown-item').forEach(item => {
            if (item.getAttribute('href') === currentPage) {
                item.classList.add('active');
                const parentDropdown = item.closest('.dropdown-more');
                if (parentDropdown) {
                    const parentBtn = parentDropdown.querySelector('.more-btn');
                    if (parentBtn) parentBtn.classList.add('has-active');
                }
            }
        });
        
        if (currentPage === 'trip_tickets.php') {
            const tripLink = document.querySelector('#mobileNav .nav-link[href="trip_tickets.php"]');
            if (tripLink) tripLink.classList.add('active');
        }
        
        console.log('Current page:', currentPage);
    }
    
   // Filter Toggle Functionality - Entire header clickable
document.addEventListener('DOMContentLoaded', function() {
    const filterHeader = document.getElementById('filterHeader');
    const filterContent = document.getElementById('filterContent');
    const filterToggleBtn = document.getElementById('filterToggleBtn');
    const filterIcon = document.getElementById('filterIcon');
    
    if (filterHeader && filterContent) {
        // Set initial state - collapsed
        filterContent.classList.add('collapsed');
        if (filterToggleBtn) filterToggleBtn.setAttribute('aria-expanded', 'false');
        
        // Make the entire header clickable
        filterHeader.addEventListener('click', function(e) {
            // Don't toggle if clicking on the button itself (to avoid double toggle)
            if (e.target.closest('.filter-toggle-btn')) {
                e.stopPropagation();
            }
            toggleFilterContent();
        });
        
        // Also keep the button click as a fallback
        if (filterToggleBtn) {
            filterToggleBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                toggleFilterContent();
            });
        }
    }
    
    function toggleFilterContent() {
        const isExpanded = !filterContent.classList.contains('collapsed');
        
        if (isExpanded) {
            // Collapse
            filterContent.classList.add('collapsed');
            if (filterToggleBtn) filterToggleBtn.setAttribute('aria-expanded', 'false');
            if (filterIcon) {
                filterIcon.classList.remove('bi-chevron-up');
                filterIcon.classList.add('bi-chevron-down');
            }
        } else {
            // Expand
            filterContent.classList.remove('collapsed');
            if (filterToggleBtn) filterToggleBtn.setAttribute('aria-expanded', 'true');
            if (filterIcon) {
                filterIcon.classList.remove('bi-chevron-down');
                filterIcon.classList.add('bi-chevron-up');
            }
        }
    }
});
    
    // ===== SETUP CLICK TO VIEW ON DESKTOP ROWS =====
function setupMobileTapToView() {
    const pickListRows = document.querySelectorAll('#pickListTable tbody tr.pick-list-row');
    
    // Para sa lahat ng devices, gawing clickable ang buong row
    pickListRows.forEach(row => {
        if (!row.hasAttribute('data-click-listener')) {
            row.setAttribute('data-click-listener', 'true');
            row.addEventListener('click', handleRowClick);
            row.style.cursor = 'pointer';
        }
    });
}

function handleRowClick(event) {
    // Huwag mag-trigger kung ang click ay sa button (para hindi ma-block ang edit/delete kung meron man)
    if (event.target.closest('.btn-action') || 
        event.target.closest('.action-btn') || 
        event.target.closest('.btn') ||
        event.target.closest('button')) {
        return;
    }
    
    const pickItemId = this.getAttribute('data-id');
    
    if (pickItemId) {
        viewItem(pickItemId);
    }
}

    function handleMobileRowClick(event) {
        if (event.target.closest('.btn-action') || event.target.closest('.action-btn')) {
            return;
        }
        
        const pickItemId = this.getAttribute('data-id');
        
        if (pickItemId) {
            viewItem(pickItemId);
        }
    }

    let resizeTimeout;
    window.addEventListener('resize', function() {
        clearTimeout(resizeTimeout);
        resizeTimeout = setTimeout(function() {
            setupMobileTapToView();
        }, 250);
    });

    const observer = new MutationObserver(function(mutations) {
        mutations.forEach(function(mutation) {
            if (mutation.type === 'childList' || mutation.type === 'subtree') {
                setupMobileTapToView();
            }
        });
    });

    const tableBody = document.querySelector('#pickListTable tbody');
    if (tableBody) {
        observer.observe(tableBody, { childList: true, subtree: true });
    }

    const tableContainer = document.querySelector('#pickListTable');
    if (tableContainer) {
        observer.observe(tableContainer, { childList: true, subtree: true, attributes: true });
    }
    
    // ========== SIDEBAR DROPDOWN HANDLING ==========
    function toggleSidebarDropdown(event, targetId) {
        event.preventDefault();
        event.stopPropagation();
        
        const target = document.getElementById(targetId);
        const btn = event.currentTarget;
        const arrow = btn.querySelector('.dropdown-arrow');
        const sidebar = document.getElementById('sidebar');
        
        if (sidebar.classList.contains('collapsed')) {
            sidebar.classList.remove('collapsed');
            localStorage.setItem('sidebarCollapsed', 'false');
            
            setTimeout(() => {
                document.querySelectorAll('.sidebar .collapse.show').forEach(collapse => {
                    if (collapse.id !== targetId) {
                        collapse.classList.remove('show');
                        const otherBtn = document.querySelector(`[onclick*="${collapse.id}"]`);
                        if (otherBtn) {
                            const otherArrow = otherBtn.querySelector('.dropdown-arrow');
                            if (otherArrow) otherArrow.style.transform = 'translateY(-50%) rotate(0deg)';
                        }
                    }
                });
                
                target.classList.add('show');
                if (arrow) arrow.style.transform = 'translateY(-50%) rotate(180deg)';
            }, 50);
            return;
        }
        
        if (target.classList.contains('show')) {
            target.classList.remove('show');
            if (arrow) arrow.style.transform = 'translateY(-50%) rotate(0deg)';
        } else {
            document.querySelectorAll('.sidebar .collapse.show').forEach(collapse => {
                if (collapse.id !== targetId) {
                    collapse.classList.remove('show');
                    const otherBtn = document.querySelector(`[onclick*="${collapse.id}"]`);
                    if (otherBtn) {
                        const otherArrow = otherBtn.querySelector('.dropdown-arrow');
                        if (otherArrow) otherArrow.style.transform = 'translateY(-50%) rotate(0deg)';
                    }
                }
            });
            
            target.classList.add('show');
            if (arrow) arrow.style.transform = 'translateY(-50%) rotate(180deg)';
        }
    }

    function setActiveSidebarItem() {
        const currentPage = window.location.pathname.split('/').pop();
        
        document.querySelectorAll('.sidebar .nav-link').forEach(link => {
            link.classList.remove('active');
        });
        
        document.querySelectorAll('.sidebar .nav-link').forEach(link => {
            const href = link.getAttribute('href');
            if (href === currentPage) {
                link.classList.add('active');
                
                const collapseDiv = link.closest('.collapse');
                if (collapseDiv) {
                    collapseDiv.classList.add('show');
                    const parentBtn = document.querySelector(`[onclick*="${collapseDiv.id}"]`);
                    if (parentBtn) {
                        const arrow = parentBtn.querySelector('.dropdown-arrow');
                        if (arrow) arrow.style.transform = 'translateY(-50%) rotate(180deg)';
                    }
                }
            }
        });
    }

    function updateDropdownParentActiveState() {
        const sidebar = document.getElementById('sidebar');
        if (!sidebar) return;
        
        if (sidebar.classList.contains('collapsed')) {
            document.querySelectorAll('.dropdown-nav').forEach(dropdownNav => {
                const hasActiveChild = dropdownNav.querySelector('.nav-link.active');
                const parentLink = dropdownNav.querySelector(':scope > .nav-link');
                
                if (hasActiveChild && parentLink) {
                    parentLink.classList.add('active');
                } else if (parentLink) {
                    parentLink.classList.remove('active');
                }
            });
        }
    }

    function expandActiveDropdownContainers() {
        const sidebar = document.getElementById('sidebar');
        if (!sidebar) return;
        
        const dropdownNavs = document.querySelectorAll('.sidebar .dropdown-nav');
        
        dropdownNavs.forEach(dropdownNav => {
            const activeLink = dropdownNav.querySelector('.nav-link.active');
            
            if (activeLink) {
                const collapseDiv = dropdownNav.querySelector('.collapse');
                
                if (collapseDiv && !collapseDiv.classList.contains('show')) {
                    collapseDiv.classList.add('show');
                    
                    const parentLink = dropdownNav.querySelector(':scope > .nav-link');
                    if (parentLink) {
                        const arrow = parentLink.querySelector('.dropdown-arrow');
                        if (arrow) {
                            arrow.style.transform = 'translateY(-50%) rotate(180deg)';
                        }
                        if (sidebar.classList.contains('collapsed')) {
                            parentLink.classList.add('active');
                        }
                    }
                }
            }
        });
    }

    function toggleSidebar() {
        const sidebar = document.getElementById('sidebar');
        const desktopToggleBtn = document.getElementById('desktopToggleBtn');
        
        if (window.innerWidth <= 992) {
            sidebar.classList.toggle('active');
            let overlay = document.querySelector('.sidebar-overlay');
            if (!overlay) { 
                overlay = document.createElement('div'); 
                overlay.className = 'sidebar-overlay'; 
                document.body.appendChild(overlay); 
                overlay.addEventListener('click', function() { 
                    sidebar.classList.remove('active'); 
                    overlay.classList.remove('active'); 
                    setTimeout(function() { overlay.remove(); }, 300); 
                }); 
            }
            setTimeout(function() { overlay.classList.add('active'); }, 10);
        } else {
            const wasCollapsed = sidebar.classList.contains('collapsed');
            sidebar.classList.toggle('collapsed');
            localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
            
            if (wasCollapsed && !sidebar.classList.contains('collapsed')) {
                sidebar.style.width = '';
                
                setTimeout(function() {
                    expandActiveDropdownContainers();
                }, 150);
            }
        }
    }

    document.addEventListener('DOMContentLoaded', function() {
        const sidebar = document.getElementById('sidebar');
        if (sidebar && window.innerWidth > 992) {
            const isCollapsed = localStorage.getItem('sidebarCollapsed') === 'true';
            if (isCollapsed) {
                sidebar.classList.add('collapsed');
            } else {
                sidebar.classList.remove('collapsed');
            }
        }
        
        setActiveSidebarItem();
        updateDropdownParentActiveState();
        
        document.querySelectorAll('.sidebar .collapse').forEach(collapse => {
            collapse.addEventListener('click', function(e) {
                e.stopPropagation();
            });
        });
        
        const desktopToggleBtn = document.getElementById('desktopToggleBtn');
        if (desktopToggleBtn) {
            desktopToggleBtn.addEventListener('click', function() {
                setTimeout(() => {
                    if (sidebar.classList.contains('collapsed')) {
                        document.querySelectorAll('.sidebar .collapse.show').forEach(collapse => {
                            collapse.classList.remove('show');
                            const parentBtn = document.querySelector(`[onclick*="${collapse.id}"]`);
                            if (parentBtn) {
                                const arrow = parentBtn.querySelector('.dropdown-arrow');
                                if (arrow) arrow.style.transform = 'translateY(-50%) rotate(0deg)';
                            }
                        });
                    }
                }, 50);
            });
        }
        
        const mobileMenuBtn = document.getElementById('mobileMenuBtn');
        if (mobileMenuBtn) {
            mobileMenuBtn.addEventListener('click', toggleSidebar);
        }
        
        document.addEventListener('click', function(e) {
            if (window.innerWidth <= 992 && sidebar && sidebar.classList.contains('active') && 
                !sidebar.contains(e.target) && mobileMenuBtn && !mobileMenuBtn.contains(e.target)) {
                sidebar.classList.remove('active');
                const overlay = document.querySelector('.sidebar-overlay');
                if (overlay) overlay.remove();
            }
        });
    });
    </script>
</body>
</html>