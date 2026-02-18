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
            $location_bin = $_POST['location_bin']; // This will now be the customer location
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
            // Validate required fields
            $pick_item_id = $_POST['pick_item_id'];
            $quantity_to_pick = $_POST['quantity_to_pick'];
            $location_bin = $_POST['location_bin'];
            $driver_id = !empty($_POST['driver_id']) ? $_POST['driver_id'] : null;
            
            if (empty($pick_item_id) || empty($quantity_to_pick) || empty($location_bin)) {
                throw new Exception('All fields are required');
            }
            
            // Get pick list item details with branch verification
            $get_item_query = "
                SELECT pli.*, pl.branch_id, pl.so_id, i.item_id, i.stock as current_stock, pl.driver_id as current_driver_id
                FROM pick_list_items pli 
                JOIN pick_lists pl ON pli.pick_list_id = pl.pick_list_id 
                JOIN items i ON pli.item_id = i.item_id 
                WHERE pli.pick_item_id = ?
            ";
            
            if ($pick_lists_branch_column_exists && !$view_all_branches) {
                $get_item_query .= " AND pl.branch_id = ?";
                $get_item_stmt = $conn->prepare($get_item_query);
                $get_item_stmt->bind_param("ii", $pick_item_id, $branch_id);
            } else {
                $get_item_stmt = $conn->prepare($get_item_query);
                $get_item_stmt->bind_param("i", $pick_item_id);
            }
            
            $get_item_stmt->execute();
            $item = $get_item_stmt->get_result()->fetch_assoc();
            
            if (!$item) {
                throw new Exception('Pick list item not found or access denied');
            }
            
            // Verify branch permission
            if ($pick_lists_branch_column_exists && !$view_all_branches && $item['branch_id'] != $branch_id) {
                throw new Exception('You can only update items from your assigned branch');
            }
            
            // Check if quantity changed
            $quantity_difference = $quantity_to_pick - $item['quantity_to_pick'];
            
            if ($quantity_difference != 0) {
                // Update stock in items table
                if ($quantity_difference > 0) {
                    // Increasing quantity - check if enough stock
                    if ($item['current_stock'] < $quantity_difference) {
                        throw new Exception('Insufficient stock for additional quantity. Current stock: ' . $item['current_stock']);
                    }
                    
                    // Decrease stock
                    $update_stock_query = "UPDATE items SET stock = stock - ? WHERE item_id = ?";
                    if ($items_branch_column_exists && !$view_all_branches) {
                        $update_stock_query .= " AND branch_id = ?";
                        $update_stock_stmt = $conn->prepare($update_stock_query);
                        $update_stock_stmt->bind_param("iii", $quantity_difference, $item['item_id'], $branch_id);
                    } else {
                        $update_stock_stmt = $conn->prepare($update_stock_query);
                        $update_stock_stmt->bind_param("ii", $quantity_difference, $item['item_id']);
                    }
                } else {
                    // Decreasing quantity - return stock
                    $update_stock_query = "UPDATE items SET stock = stock + ? WHERE item_id = ?";
                    if ($items_branch_column_exists && !$view_all_branches) {
                        $update_stock_query .= " AND branch_id = ?";
                        $update_stock_stmt = $conn->prepare($update_stock_query);
                        $update_stock_stmt->bind_param("iii", abs($quantity_difference), $item['item_id'], $branch_id);
                    } else {
                        $update_stock_stmt = $conn->prepare($update_stock_query);
                        $update_stock_stmt->bind_param("ii", abs($quantity_difference), $item['item_id']);
                    }
                }
                
                if (!$update_stock_stmt->execute()) {
                    throw new Exception('Failed to update item stock');
                }
            }
            
            // Update driver assignment if changed
            if ($driver_id != $item['current_driver_id']) {
                $update_driver_query = "UPDATE pick_lists SET driver_id = ? WHERE pick_list_id = ?";
                
                if ($pick_lists_branch_column_exists && !$view_all_branches) {
                    $update_driver_query .= " AND branch_id = ?";
                    $update_driver_stmt = $conn->prepare($update_driver_query);
                    $update_driver_stmt->bind_param("iii", $driver_id, $item['pick_list_id'], $branch_id);
                } else {
                    $update_driver_stmt = $conn->prepare($update_driver_query);
                    $update_driver_stmt->bind_param("ii", $driver_id, $item['pick_list_id']);
                }
                
                if (!$update_driver_stmt->execute()) {
                    throw new Exception('Failed to update driver assignment');
                }
            }
            
            // Update the pick list item
            $update_query = "UPDATE pick_list_items 
                           SET quantity_to_pick = ?, location_bin = ? 
                           WHERE pick_item_id = ?";
            $update_stmt = $conn->prepare($update_query);
            $update_stmt->bind_param("isi", $quantity_to_pick, $location_bin, $pick_item_id);
            
            if (!$update_stmt->execute()) {
                throw new Exception('Failed to update pick list item');
            }
            
            $conn->commit();
            
            echo json_encode([
                'success' => true,
                'message' => 'Item updated successfully',
                'pick_item_id' => $pick_item_id
            ]);
            exit;
        }
        elseif ($_POST['action'] === 'delete_pick_item') {
            $pick_item_id = $_POST['pick_item_id'];
            
            // Get item details before deletion with branch verification
            $get_item_query = "
                SELECT pli.*, pl.so_id, pl.pick_list_id, pl.branch_id, 
                       i.item_id, i.stock as current_stock 
                FROM pick_list_items pli 
                JOIN pick_lists pl ON pli.pick_list_id = pl.pick_list_id 
                JOIN items i ON pli.item_id = i.item_id 
                WHERE pli.pick_item_id = ?
            ";
            
            if ($pick_lists_branch_column_exists && !$view_all_branches) {
                $get_item_query .= " AND pl.branch_id = ?";
                $get_item_stmt = $conn->prepare($get_item_query);
                $get_item_stmt->bind_param("ii", $pick_item_id, $branch_id);
            } else {
                $get_item_stmt = $conn->prepare($get_item_query);
                $get_item_stmt->bind_param("i", $pick_item_id);
            }
            
            $get_item_stmt->execute();
            $item = $get_item_stmt->get_result()->fetch_assoc();
            
            if (!$item) {
                throw new Exception('Pick list item not found or access denied');
            }
            
            // Verify branch permission
            if ($pick_lists_branch_column_exists && !$view_all_branches && $item['branch_id'] != $branch_id) {
                throw new Exception('You can only delete items from your assigned branch');
            }
            
            // Return stock to items table
            $return_stock_query = "UPDATE items SET stock = stock + ? WHERE item_id = ?";
            
            if ($items_branch_column_exists && !$view_all_branches) {
                $return_stock_query .= " AND branch_id = ?";
                $return_stock_stmt = $conn->prepare($return_stock_query);
                $return_stock_stmt->bind_param("iii", $item['quantity_to_pick'], $item['item_id'], $branch_id);
            } else {
                $return_stock_stmt = $conn->prepare($return_stock_query);
                $return_stock_stmt->bind_param("ii", $item['quantity_to_pick'], $item['item_id']);
            }
            
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
                
                if ($pick_lists_branch_column_exists && !$view_all_branches) {
                    $update_pl_query .= " AND branch_id = ?";
                    $update_pl_stmt = $conn->prepare($update_pl_query);
                    $update_pl_stmt->bind_param("ii", $item['pick_list_id'], $branch_id);
                } else {
                    $update_pl_stmt = $conn->prepare($update_pl_query);
                    $update_pl_stmt->bind_param("i", $item['pick_list_id']);
                }
                
                $update_pl_stmt->execute();
                
                // Update sales order status back to pending
                $update_so_query = "UPDATE sales_orders SET order_status = 'pending', updated_at = NOW() WHERE so_id = ?";
                
                if ($sales_orders_branch_column_exists && !$view_all_branches) {
                    $update_so_query .= " AND branch_id = ?";
                    $update_so_stmt = $conn->prepare($update_so_query);
                    $update_so_stmt->bind_param("ii", $item['so_id'], $branch_id);
                } else {
                    $update_so_stmt = $conn->prepare($update_so_query);
                    $update_so_stmt->bind_param("i", $item['so_id']);
                }
                
                $update_so_stmt->execute();
            }
            
            $conn->commit();
            
            echo json_encode([
                'success' => true,
                'message' => 'Item deleted successfully. Stock returned.',
                'branch_id' => $branch_id
            ]);
            exit;
        }
        
        // GET PICK ITEM DETAILS FOR EDIT
        elseif ($_POST['action'] === 'get_pick_item') {
            $pick_item_id = (int)$_POST['pick_item_id'];
            
            $query = "
                SELECT 
                    pli.pick_item_id,
                    pli.pick_list_id,
                    pli.item_id,
                    pli.quantity_to_pick,
                    pli.location_bin,
                    pl.driver_id,
                    pl.branch_id,
                    pl.so_id,
                    i.item_code,
                    i.item_name,
                    i.unit_type,
                    i.stock as current_stock,
                    d.driver_name
                FROM pick_list_items pli
                JOIN pick_lists pl ON pli.pick_list_id = pl.pick_list_id
                JOIN items i ON pli.item_id = i.item_id
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
            
            $stmt->execute();
            $result = $stmt->get_result();
            $item = $result->fetch_assoc();
            
            if ($item) {
                echo json_encode([
                    'success' => true,
                    'item' => $item
                ]);
            } else {
                throw new Exception('Item not found or access denied');
            }
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

// ========== FETCH PICK LISTS WITH BRANCH FILTERING AND CUSTOMER LOCATION ==========
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
        pl.created_at as encoded_at
    FROM pick_lists pl
    LEFT JOIN branches b ON pl.branch_id = b.branch_id
    LEFT JOIN pick_list_items pli ON pl.pick_list_id = pli.pick_list_id
    LEFT JOIN items i ON pli.item_id = i.item_id
    LEFT JOIN sales_orders so ON pl.so_id = so.so_id
    LEFT JOIN customers c ON so.customer_id = c.customer_id
    LEFT JOIN users u ON pl.picked_by = u.user_id
    LEFT JOIN drivers d ON pl.driver_id = d.driver_id
    WHERE 1=1
";

// Apply branch filter for pick_lists
if ($pick_lists_branch_column_exists && !$view_all_branches) {
    $picklist_query .= " AND pl.branch_id = $branch_id";
}

$picklist_query .= " ORDER BY pl.created_at DESC, pl.pick_list_id DESC";

$picklist_result = $conn->query($picklist_query);
$picklist_items = $picklist_result->fetch_all(MYSQLI_ASSOC);

// ========== FETCH SALES ORDERS WITH BRANCH FILTERING AND CUSTOMER LOCATION ==========
$so_query = "
    SELECT 
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
        b.branch_name
    FROM sales_orders so
    LEFT JOIN customers c ON so.customer_id = c.customer_id
    LEFT JOIN branches b ON so.branch_id = b.branch_id
    WHERE so.order_status IN ('pending', 'confirmed', 'processing')
";

// Apply branch filter for sales_orders
if ($sales_orders_branch_column_exists && !$view_all_branches) {
    $so_query .= " AND so.branch_id = $branch_id";
}

$so_query .= " ORDER BY so.order_date DESC";

$so_result = $conn->query($so_query);
$sales_orders = $so_result->fetch_all(MYSQLI_ASSOC);

// ========== FETCH SALES ORDER ITEMS WITH BRANCH FILTERING AND CUSTOMER LOCATION ==========
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
        i.reorder_level,
        i.branch_id as item_branch_id,
        c.latitude,
        c.longitude,
        c.full_address,
        c.delivery_instructions
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

// Add item branch filter AFTER the subquery is closed
if ($items_branch_column_exists && !$view_all_branches) {
    $so_items_query .= " AND i.branch_id = $branch_id";
}

$so_items_result = $conn->query($so_items_query);

// Add error checking
if (!$so_items_result) {
    error_log("SQL Error in pick_list_items.php: " . $conn->error);
    error_log("SQL Query: " . $so_items_query);
    $so_items = [];
} else {
    $so_items = $so_items_result->fetch_all(MYSQLI_ASSOC);
}

// Organize SO items by SO ID
$so_items_by_so = [];
foreach ($so_items as $item) {
    $so_items_by_so[$item['so_id']][] = $item;
}

// ========== FETCH ITEMS WITH BRANCH FILTERING ==========
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

// ========== FETCH DRIVERS WITH BRANCH FILTERING ==========
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

// ========== STATISTICS CALCULATION WITH BRANCH FILTERING ==========
// Get distinct pick lists count
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

$statWarehouseReady = 0; // Completed pick lists
$statInTransit = 0;      // In-progress pick lists

while ($row = $status_result->fetch_assoc()) {
    if ($row['pick_status'] === 'completed') {
        $statWarehouseReady = $row['count'];
    } elseif ($row['pick_status'] === 'in-progress') {
        $statInTransit = $row['count'];
    }
}

// Count delivered orders from sales_orders with branch filtering
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

function getBranchBadge($branch_id, $branch_name) {
    if ($branch_id) {
        return '<span class="badge bg-info">' . htmlspecialchars($branch_name ?? 'Branch ' . $branch_id) . '</span>';
    }
    return '<span class="badge bg-secondary">No Branch</span>';
}

// Format location for display
function formatLocation($item) {
    if (!empty($item['latitude']) && !empty($item['longitude'])) {
        return $item['latitude'] . ', ' . $item['longitude'];
    } elseif (!empty($item['full_address'])) {
        return $item['full_address'];
    } else {
        return 'No location data';
    }
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
                    <img src="../Pictures/amgc3DLogo.png" alt="Logo" class="logo-icon"> 
                    <span class="nav-text">
                        <?php echo $view_all_branches ? 'Administrator' : ucfirst(str_replace('_', ' ', $user_role)); ?>
                    </span>
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
                        <a class="nav-link" href="drivers.php">
                            <i class="bi bi-truck"></i>
                            <span class="nav-text">Drivers</span>
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
                    <div class="user-avatar-sidebar">
                        <?php 
                        $initials = '';
                        $name_parts = explode(' ', $user_name);
                        foreach ($name_parts as $part) {
                            if (!empty($part)) $initials .= strtoupper(substr($part, 0, 1));
                            if (strlen($initials) >= 2) break;
                        }
                        echo $initials ?: 'U';
                        ?>
                    </div>
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
                        <h2>
                            Pick List Items
                        </h2>
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
                <div class="row g-3 mb-4">
                    <div class="col-md-3 col-6">
                        <div class="stat-card total">
                            <i class="bi bi-boxes stat-icon"></i>
                            <div class="stat-value" id="totalItems"><?= $statTotalItems ?></div>
                            <div class="stat-label">Total Pick Lists</div>
                            <?php if (!$view_all_branches): ?>
                                <small class="d-block text-white-50"><?php echo htmlspecialchars($branch_name); ?></small>
                            <?php endif; ?>
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

                <!-- FILTER SECTION - UPDATED WITH SEARCH -->
                <div class="filter-section">
                    <div class="filter-controls">
                        <div class="filter-dropdowns">
                            <!-- Date Filter Dropdown -->
                            <div class="filter-dropdown">
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
                                <select class="form-select" id="statusFilter" onchange="applyFilters()">
                                    <option value="all">All Status</option>
                                    <option value="open">Pending</option>
                                    <option value="in-progress">In Progress</option>
                                    <option value="completed">Completed</option>
                                    <option value="cancelled">Cancelled</option>
                                </select>
                            </div>
                            
                            <!-- Branch Filter Dropdown - Only for Admin -->
                            <?php if ($view_all_branches && $pick_lists_branch_column_exists): ?>
                            <div class="filter-dropdown">
                                <span class="filter-label">Branch</span>
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
                            
                            <!-- Quantity Filter Dropdown -->
                            <div class="filter-dropdown">
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
                        <!-- Global Search Box -->
                        <div class="search-box">
                            <i class="bi bi-search"></i>
                            <input type="text" id="globalSearch" placeholder="Search SO number, item, driver, encoder, location..." onkeyup="applyFilters()">
                        </div>
                        <button class="btn btn-outline-primary" onclick="printPickList()">
                            <i class="bi bi-printer me-1"></i> Print
                        </button>
                        <button class="btn btn-outline-success" onclick="exportToExcel()">
                            <i class="bi bi-file-earmark-excel me-1"></i> Export to Excel
                        </button>
                        <button class="btn btn-primary" id="addItemButton" onclick="showAddItemModal()">
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
                                <?php if ($view_all_branches && $pick_lists_branch_column_exists): ?>
                                    <th class="col-branch">BRANCH</th>
                                <?php endif; ?>
                                <th class="col-item-code">ITEM CODE</th>
                                <th class="col-item-name">ITEM NAME</th>
                                <th class="col-to-pick">TO PICK</th>
                                <th class="col-picked">PICKED</th>
                                <th class="col-location">DELIVERY LOCATION</th>
                                <th class="col-status">STATUS</th>
                                <th class="col-encoded">ASSIGNED DRIVER</th>
                                <th class="col-encoded-by">ENCODED BY</th>
                                <th class="col-encoded-at">ENCODED AT</th>
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
                                    
                                    // Format location for display
                                    $location_display = '';
                                    if (!empty($item['latitude']) && !empty($item['longitude'])) {
                                        $location_display = '<span class="customer-location-badge"><i class="bi bi-geo-alt-fill"></i> ' . 
                                                           number_format($item['latitude'], 6) . ', ' . 
                                                           number_format($item['longitude'], 6) . '</span>';
                                        if (!empty($item['full_address'])) {
                                            $location_display .= '<br><small class="text-muted">' . 
                                                                htmlspecialchars(substr($item['full_address'], 0, 50)) . 
                                                                (strlen($item['full_address']) > 50 ? '...' : '') . '</small>';
                                        }
                                    } elseif (!empty($item['full_address'])) {
                                        $location_display = '<span class="location-badge"><i class="bi bi-pin-map-fill"></i> ' . 
                                                           htmlspecialchars(substr($item['full_address'], 0, 50)) . 
                                                           (strlen($item['full_address']) > 50 ? '...' : '') . '</span>';
                                    } else {
                                        $location_display = '<span class="text-muted fst-italic">No location data</span>';
                                    }
                            ?>
                            <tr class="pick-list-row" 
                                data-id="<?= $item['pick_item_id'] ?>"
                                data-pick-list-id="<?= $item['pick_list_id'] ?>"
                                data-so-id="<?= htmlspecialchars($item['so_number'] ?? '') ?>"
                                data-status="<?= $item['pick_status'] ?>"
                                data-item-code="<?= htmlspecialchars($item['item_code'] ?? '') ?>"
                                data-item-name="<?= htmlspecialchars($item['item_name'] ?? '') ?>"
                                data-quantity="<?= $item['quantity_to_pick'] ?? 0 ?>"
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
                                data-customer-name="<?= htmlspecialchars($item['customer_name'] ?? '') ?>">
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
                                <td class="col-to-pick text-center"><?= $item['quantity_to_pick'] ?? 0 ?></td>
                                <td class="col-picked text-center"><?= $item['quantity_picked'] ?? 0 ?></td>
                                <td class="col-location location-cell">
                                    <?= $location_display ?>
                                    <?php if (!empty($item['delivery_instructions'])): ?>
                                    <br><small class="text-info" title="Delivery Instructions">
                                        <i class="bi bi-info-circle"></i> <?= htmlspecialchars(substr($item['delivery_instructions'], 0, 30)) . (strlen($item['delivery_instructions']) > 30 ? '...' : '') ?>
                                    </small>
                                    <?php endif; ?>
                                </td>
                                <td class="col-status">
                                    <span class="badge <?= getPickStatusBadge($item['pick_status']) ?>">
                                        <?= getPickStatusText($item['pick_status']) ?>
                                    </span>
                                    <br>
                                    <small class="text-muted">SO: <?= ucfirst($item['order_status'] ?? 'N/A') ?></small>
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
                                <td class="col-encoded-by">
                                    <span class="badge bg-secondary">
                                        <i class="bi bi-person"></i> <?= htmlspecialchars($item['encoded_by_name'] ?? 'System') ?>
                                    </span>
                                </td>
                                <td class="col-encoded-at">
                                    <span class="text-muted">
                                        <i class="bi bi-clock"></i> 
                                        <?= $item['encoded_at'] ? date('M d, Y H:i', strtotime($item['encoded_at'])) : 'N/A' ?>
                                    </span>
                                </td>
                                <td class="col-actions">
                                    <div class="action-buttons">
                                        <button class="btn-action btn-view" onclick="viewItem(<?= $item['pick_item_id'] ?>)" title="View">
                                            <i class="bi bi-eye"></i>
                                        </button>
                                        <?php if ($item['pick_status'] === 'open' && ($view_all_branches || ($pick_lists_branch_column_exists && $item['branch_id'] == $branch_id))): ?>
                                        <button class="btn-action btn-edit" onclick="editItem(<?= $item['pick_item_id'] ?>)" title="Edit">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <button class="btn-action btn-delete" onclick="deleteItem(<?= $item['pick_item_id'] ?>)" title="Delete">
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
                                <td colspan="<?= ($view_all_branches && $pick_lists_branch_column_exists) ? '12' : '11' ?>" class="empty-state-table">
                                    <i class="bi bi-clipboard"></i>
                                    <h5>No Pick List Items Found</h5>
                                    <p class="text-muted">
                                        <?php if ($view_all_branches): ?>
                                            There are currently no pick list items in any branch.
                                        <?php else: ?>
                                            There are currently no pick list items for <?php echo htmlspecialchars($branch_name); ?>.
                                        <?php endif; ?>
                                    </p>
                                </td>
                            </tr>
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

    <!-- Add Item Modal - UPDATED WITH CUSTOMER LOCATION DISPLAY -->
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
                                    <div class="mt-2 p-2 bg-white rounded" id="previewLocation">
                                        <i class="bi bi-geo-alt-fill text-primary"></i>
                                        <span id="previewAddress" class="small">No location data</span>
                                    </div>
                                    <div id="previewMap" class="map-container" style="display: none;"></div>
                                </div>
                            </div>
                            
                            <!-- Item Selection - Multi-Select Table with Location Info -->
                            <div class="col-12">
                                <label class="form-label fw-bold mb-2">Select Items to Pick</label>
                                <div class="alert alert-info mb-2">
                                    <i class="bi bi-info-circle me-2"></i>
                                    Check the items you want to add to the pick list. The delivery location will be automatically set from the customer's address.
                                </div>
                                
                                <!-- Items Table for Multi-Select -->
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

    <!-- Edit Item Modal - UPDATED WITH LOCATION DISPLAY -->
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
                        <input type="hidden" id="editLocationBin"> <!-- This will store the location -->
                        
                        <?php if (!$view_all_branches && $branch_id > 0): ?>
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle"></i>
                            Editing item for <strong><?php echo htmlspecialchars($branch_name); ?></strong>
                        </div>
                        <?php endif; ?>
                        
                        <div class="row g-3">
                            <!-- Item Information (Read-only) -->
                            <div class="col-md-6">
                                <label class="form-label">Item Code</label>
                                <input type="text" class="form-control" id="editItemCode" readonly>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Item Name</label>
                                <input type="text" class="form-control" id="editItemName" readonly>
                            </div>
                            
                            <!-- Customer Information (Read-only) -->
                            <div class="col-md-6">
                                <label class="form-label">Customer</label>
                                <input type="text" class="form-control" id="editCustomerName" readonly>
                            </div>
                            
                            <!-- Delivery Location Display -->
                            <div class="col-md-6">
                                <label class="form-label">Delivery Location</label>
                                <div class="form-control bg-light" id="editLocationDisplay" readonly style="min-height: 38px;">
                                    <span id="editLocationText" class="text-muted">Loading...</span>
                                </div>
                                <small class="text-muted">Location from customer address</small>
                            </div>
                            
                            <!-- Quantity to Pick -->
                            <div class="col-md-4">
                                <label for="editQuantity" class="form-label">Quantity to Pick</label>
                                <input type="number" class="form-control" id="editQuantity" min="1" required>
                                <small class="text-muted" id="editStockInfo"></small>
                            </div>
                            
                            <!-- Driver Assignment -->
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
                                <!-- Empty column for spacing -->
                            </div>
                            
                            <!-- Current Stock Info -->
                            <div class="col-12">
                                <div class="alert alert-info" id="editStockAlert">
                                    <i class="bi bi-info-circle me-2"></i>
                                    Current stock: <span id="editCurrentStock">0</span> | 
                                    Current pick quantity: <span id="editCurrentQuantity">0</span>
                                </div>
                            </div>
                            
                            <!-- Map Preview -->
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

    <!-- View Item Modal - UPDATED WITH MAP -->
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
                    <button type="button" class="btn btn-warning" onclick="editFromView()" id="editFromViewBtn" style="display: none;">
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
    let selectedPickItemId = null;
    let itemsData = <?= json_encode($items_by_code) ?>;
    let soItemsData = <?= json_encode($so_items_by_so) ?>;
    let driversData = <?= json_encode($drivers_list) ?>;
    let currentUserId = <?= $user_id ?: 1 ?>;
    let availableItems = [];
    let currentSOData = null; // Store current SO data including location
    let maps = {}; // Store map instances

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
            
            // Status filter
            if (statusFilter !== 'all') {
                const rowStatus = row.dataset.status;
                if (rowStatus !== statusFilter) showRow = false;
            }
            
            // Branch filter
            if (showRow && branchFilter !== 'all' && viewAllBranches) {
                const rowBranchId = row.dataset.branchId || '';
                if (rowBranchId != branchFilter) showRow = false;
            }
            
            // Quantity filter
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
            
            // Global search filter
            if (showRow && searchTerm !== '') {
                const soNumber = row.dataset.soId?.toLowerCase() || '';
                const itemCode = row.dataset.itemCode?.toLowerCase() || '';
                const itemName = row.dataset.itemName?.toLowerCase() || '';
                const driverName = row.dataset.driverName?.toLowerCase() || '';
                const encodedBy = row.dataset.encodedBy?.toLowerCase() || '';
                const address = row.dataset.address?.toLowerCase() || '';
                const customerName = row.dataset.customerName?.toLowerCase() || '';
                const location = row.querySelector('.col-location')?.innerText.toLowerCase() || '';
                
                const searchableText = soNumber + ' ' + itemCode + ' ' + itemName + ' ' + driverName + ' ' + encodedBy + ' ' + location + ' ' + address + ' ' + customerName;
                
                if (!searchableText.includes(searchTerm)) {
                    showRow = false;
                }
            }
            
            // Date filter
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
        
        // Update empty state message
        const emptyStateRow = document.querySelector('.empty-state-table');
        if (emptyStateRow) {
            const emptyStateParent = emptyStateRow.closest('tr');
            if (visibleCount === 0) {
                if (emptyStateParent) {
                    emptyStateParent.style.display = '';
                    emptyStateRow.innerHTML = `
                        <td colspan="${viewAllBranches && pickListsBranchColumnExists ? '12' : '11'}" class="empty-state-table">
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
        
        // Reset form
        document.getElementById('modalTitle').textContent = 'Add Pick List Item';
        document.getElementById('itemForm').reset();
        document.getElementById('itemId').value = '';
        document.getElementById('soId').value = '';
        document.getElementById('soNumber').value = '';
        document.getElementById('driverId').value = '';
        
        // Hide preview sections
        document.getElementById('soDetailsPreview').style.display = 'none';
        document.getElementById('driverInfoPreview').style.display = 'none';
        
        // Reset Select2 dropdowns
        $('#soIdSelect').val('').trigger('change');
        $('#driverSelect').val('').trigger('change');
        
        // Clear items table
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
        
        // Reset select all checkbox
        const selectAll = document.getElementById('selectAllItems');
        if (selectAll) {
            selectAll.checked = false;
        }
        
        // Set current date/time
        const now = new Date();
        const formattedDateTime = now.toISOString().slice(0, 16);
        const encodedAt = document.getElementById('encodedAt');
        if (encodedAt) {
            encodedAt.value = formattedDateTime;
        }
        
        const encodedBy = document.getElementById('encodedBy');
        if (encodedBy) {
            encodedBy.value = currentUserId;
        }
        
        // Set branch info
        <?php if ($view_all_branches): ?>
        const selectedBranchName = document.getElementById('selectedBranchName');
        if (selectedBranchName) {
            selectedBranchName.textContent = 'your selected branch';
        }
        <?php endif; ?>
        
        // Clear current SO data
        currentSOData = null;
        
        // Show modal
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
            
            // Store current SO data
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
            
            // Fill hidden fields
            document.getElementById('soId').value = soId;
            document.getElementById('soNumber').value = soNumber;
            document.getElementById('branchId').value = branchId || userBranchId;
            
            // Show SO details with location
            document.getElementById('previewSoNumber').textContent = 'SO #: ' + soNumber;
            document.getElementById('previewCustomer').textContent = 'Customer: ' + customerName;
            document.getElementById('previewOrderDate').textContent = 'Order Date: ' + new Date(orderDate).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
            document.getElementById('previewSoBranch').textContent = 'Branch: ' + (branchName || 'Branch ' + branchId);
            
            // Show location info
            let locationText = '';
            if (latitude && longitude) {
                locationText = `Coordinates: ${parseFloat(latitude).toFixed(6)}, ${parseFloat(longitude).toFixed(6)}`;
                if (address) {
                    locationText += `<br>Address: ${address}`;
                }
                document.getElementById('previewAddress').innerHTML = locationText;
                
                // Show map link
                const mapLink = `https://www.google.com/maps?q=${latitude},${longitude}`;
                document.getElementById('previewLocation').innerHTML += `<br><a href="${mapLink}" target="_blank" class="map-link"><i class="bi bi-box-arrow-up-right"></i> View on Google Maps</a>`;
            } else if (address) {
                locationText = `Address: ${address}`;
                document.getElementById('previewAddress').innerHTML = locationText;
            } else {
                document.getElementById('previewAddress').innerHTML = 'No location data available';
            }
            
            document.getElementById('soDetailsPreview').style.display = 'block';
            
            <?php if ($view_all_branches): ?>
            document.getElementById('selectedBranchName').textContent = branchName || 'Branch ' + branchId;
            <?php endif; ?>
            
            // Populate items table for this SO
            populateItemsForSO(soId, latitude, longitude, address);
            
        } else {
            document.getElementById('soId').value = '';
            document.getElementById('soNumber').value = '';
            document.getElementById('soDetailsPreview').style.display = 'none';
            currentSOData = null;
            
            // Clear items table
            const tableBody = document.getElementById('itemsSelectionBody');
            tableBody.innerHTML = `
                <tr id="noItemsMessage">
                    <td colspan="9" class="text-center py-4 text-muted">
                        <i class="bi bi-inbox fs-4 d-block mb-2"></i>
                        Select a Sales Order to see available items
                    </td>
                </tr>
            `;
            
            // Reset select all checkbox
            const selectAll = document.getElementById('selectAllItems');
            if (selectAll) {
                selectAll.checked = false;
            }
        }
    }

    function populateItemsForSO(soId, latitude, longitude, address) {
        const items = soItemsData[soId] || [];
        availableItems = items;
        const tableBody = document.getElementById('itemsSelectionBody');
        
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
        
        // Format location string for display
        let locationDisplay = '';
        if (latitude && longitude) {
            locationDisplay = `${parseFloat(latitude).toFixed(6)}, ${parseFloat(longitude).toFixed(6)}`;
            if (address) {
                locationDisplay += `<br><small>${address.substring(0, 50)}${address.length > 50 ? '...' : ''}</small>`;
            }
        } else if (address) {
            locationDisplay = address.substring(0, 70) + (address.length > 70 ? '...' : '');
        } else {
            locationDisplay = '<span class="text-warning">No location data</span>';
        }
        
        let html = '';
        items.forEach((item) => {
            // Calculate available quantity to pick
            const orderedQty = item.quantity_ordered || 0;
            const stockQty = item.current_stock || 0;
            const availableToPick = Math.min(orderedQty, stockQty);
            
            // Skip if no quantity available
            if (availableToPick <= 0) return;
            
            // Sanitize item name for HTML attribute
            const safeItemName = item.item_name.replace(/'/g, "\\'").replace(/"/g, '&quot;');
            
            html += `
                <tr class="item-row" data-item-id="${item.item_id}" data-item-code="${item.item_code}">
                    <td>
                        <input type="checkbox" class="form-check-input item-select-checkbox" 
                               id="item_${item.item_id}" value="${item.item_id}"
                               data-item-id="${item.item_id}"
                               data-item-code="${item.item_code}"
                               data-item-name="${safeItemName}"
                               data-unit-type="${item.unit_type}"
                               data-max-qty="${availableToPick}"
                               onchange="toggleItemSelection(this)">
                    </td>
                    <td><strong>${item.item_code}</strong></td>
                    <td>${item.item_name}</td>
                    <td>${item.unit_type.toUpperCase()}</td>
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
                            <i class="bi bi-geo-alt-fill text-primary"></i>
                            <small>${locationDisplay}</small>
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
        
        // Reset select all checkbox
        const selectAll = document.getElementById('selectAllItems');
        if (selectAll) {
            selectAll.checked = false;
        }
    }

    // Toggle all items in the selection table
    function toggleAllItems() {
        const selectAll = document.getElementById('selectAllItems');
        const checkboxes = document.querySelectorAll('.item-select-checkbox');
        
        checkboxes.forEach(checkbox => {
            checkbox.checked = selectAll.checked;
            // Enable/disable quantity input based on checkbox
            const row = checkbox.closest('tr');
            const qtyInput = row.querySelector('.item-qty-input');
            if (qtyInput) {
                qtyInput.disabled = !selectAll.checked;
                if (!selectAll.checked) {
                    qtyInput.value = 0;
                } else {
                    // Set default quantity to 1 when selecting
                    const maxQty = parseInt(checkbox.dataset.maxQty) || 1;
                    qtyInput.value = Math.min(1, maxQty);
                }
            }
        });
        
        updateSelectAllState();
    }

    // Toggle individual item selection
    function toggleItemSelection(checkbox) {
        const row = checkbox.closest('tr');
        const qtyInput = row.querySelector('.item-qty-input');
        
        qtyInput.disabled = !checkbox.checked;
        
        if (!checkbox.checked) {
            qtyInput.value = 0;
        } else {
            // Set default quantity to 1 or max available
            const maxQty = parseInt(checkbox.dataset.maxQty) || 1;
            qtyInput.value = Math.min(1, maxQty);
        }
        
        // Update select all checkbox state
        updateSelectAllState();
    }

    // Update select all checkbox state
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

    // Update item quantity (ensure it doesn't exceed max)
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
                text: `Cannot pick more than ${max} pieces (available stock)`,
                timer: 2000,
                showConfirmButton: false
            });
        }
    }

    // Get location string from row
    function getLocationFromRow(row) {
        const locationDiv = row.querySelector('.location-preview');
        if (locationDiv) {
            const hiddenInput = locationDiv.querySelector('.item-location-hidden');
            if (hiddenInput) {
                return hiddenInput.value;
            }
            // Fallback to text content
            const text = locationDiv.innerText.trim();
            if (text && text !== 'No location data') {
                return text;
            }
        }
        return 'No location data';
    }

    // ========== SAVE FUNCTIONS ==========
    function saveItem() {
        console.log("saveItem called");
        
        // Validate required fields
        const soId = document.getElementById('soId').value;
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
        
        if (!driverId) {
            Swal.fire({
                icon: 'warning',
                title: 'Missing Field',
                text: 'Please assign a driver for this pick list',
                confirmButtonColor: '#0d6efd'
            });
            return;
        }
        
        // Get selected items
        const selectedItems = [];
        const checkboxes = document.querySelectorAll('.item-select-checkbox:checked');
        
        if (checkboxes.length === 0) {
            Swal.fire({
                icon: 'warning',
                title: 'No Items Selected',
                text: 'Please select at least one item to pick',
                confirmButtonColor: '#0d6efd'
            });
            return;
        }
        
        let hasError = false;
        checkboxes.forEach(checkbox => {
            const row = checkbox.closest('tr');
            const qtyInput = row.querySelector('.item-qty-input');
            const maxQty = parseInt(checkbox.dataset.maxQty) || 0;
            const quantity = parseInt(qtyInput.value) || 0;
            
            if (quantity <= 0) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Invalid Quantity',
                    text: `Please enter a valid quantity for item ${checkbox.dataset.itemCode}`,
                    confirmButtonColor: '#0d6efd'
                });
                hasError = true;
                return;
            }
            
            if (quantity > maxQty) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Quantity Exceeds Stock',
                    text: `Cannot pick ${quantity} of ${checkbox.dataset.itemCode}. Max available: ${maxQty}`,
                    confirmButtonColor: '#0d6efd'
                });
                hasError = true;
                return;
            }
            
            // Get location from the row (this comes from customer data)
            const location = getLocationFromRow(row);
            
            selectedItems.push({
                item_id: checkbox.value,
                item_code: checkbox.dataset.itemCode,
                quantity: quantity,
                location_bin: location // This is now the customer location
            });
        });
        
        if (hasError) return;
        
        // Show loading
        showLoading();
        
        // Save items sequentially
        const savePromises = selectedItems.map(item => {
            const formData = new FormData();
            formData.append('action', 'save_pick_item');
            formData.append('so_id', soId);
            formData.append('item_id', item.item_id);
            formData.append('quantity_to_pick', item.quantity);
            formData.append('location_bin', item.location_bin);
            formData.append('encoded_by', currentUserId);
            formData.append('driver_id', driverId);
            
            return fetch('pick_list_items.php', {
                method: 'POST',
                body: formData
            }).then(response => response.json());
        });
        
        Promise.all(savePromises)
            .then(results => {
                hideLoading();
                
                // Check if all succeeded
                const allSuccess = results.every(r => r.success);
                const failedCount = results.filter(r => !r.success).length;
                
                if (allSuccess) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Success!',
                        text: `Added ${selectedItems.length} item(s) to pick list successfully.`,
                        confirmButtonColor: '#0d6efd',
                        timer: 2000
                    }).then(() => {
                        const modal = bootstrap.Modal.getInstance(document.getElementById('itemModal'));
                        if (modal) {
                            modal.hide();
                            // Remove modal backdrop manually if it persists
                            const backdrop = document.querySelector('.modal-backdrop');
                            if (backdrop) {
                                backdrop.remove();
                            }
                            document.body.classList.remove('modal-open');
                        }
                        location.reload();
                    });
                } else {
                    // Some failed
                    const successCount = selectedItems.length - failedCount;
                    Swal.fire({
                        icon: 'warning',
                        title: 'Partial Success',
                        html: `
                            <p>${successCount} of ${selectedItems.length} items were added successfully.</p>
                            <p class="text-danger">${failedCount} item(s) failed.</p>
                            <p class="small text-muted">Please check the logs for details.</p>
                        `,
                        confirmButtonColor: '#0d6efd'
                    }).then(() => {
                        location.reload();
                    });
                }
            })
            .catch(error => {
                hideLoading();
                console.error('Error:', error);
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: 'An error occurred while saving the items',
                    confirmButtonColor: '#0d6efd'
                });
            });
    }

    // ========== EDIT FUNCTIONS ==========
    function editItem(id) {
        // Clear any selected item from view
        selectedPickItemId = null;
        
        showLoading();
        
        const formData = new FormData();
        formData.append('action', 'get_pick_item');
        formData.append('pick_item_id', id);
        
        fetch('pick_list_items.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            hideLoading();
            
            if (data.success) {
                const item = data.item;
                
                // Get additional data from the row
                const row = document.querySelector(`.pick-list-row[data-id="${id}"]`);
                const customerName = row ? row.dataset.customerName : 'Unknown';
                const latitude = row ? row.dataset.latitude : null;
                const longitude = row ? row.dataset.longitude : null;
                const address = row ? row.dataset.address : null;
                
                document.getElementById('editPickItemId').value = item.pick_item_id;
                document.getElementById('editItemId').value = item.item_id;
                document.getElementById('editPickListId').value = item.pick_list_id;
                document.getElementById('editItemCode').value = item.item_code;
                document.getElementById('editItemName').value = item.item_name;
                document.getElementById('editCustomerName').value = customerName;
                document.getElementById('editQuantity').value = item.quantity_to_pick;
                document.getElementById('editLocationBin').value = item.location_bin || 'No location data';
                
                // Set location display
                let locationText = '';
                if (latitude && longitude) {
                    locationText = `📍 ${parseFloat(latitude).toFixed(6)}, ${parseFloat(longitude).toFixed(6)}`;
                    if (address) {
                        locationText += `<br><small>${address}</small>`;
                    }
                    
                    // Show map link
                    const mapLink = `https://www.google.com/maps?q=${latitude},${longitude}`;
                    document.getElementById('editLocationDisplay').innerHTML = locationText;
                    document.getElementById('editMapLink').href = mapLink;
                    document.getElementById('editMapLink').style.display = 'inline-block';
                    
                    // Initialize map
                    initEditMap(latitude, longitude, address);
                } else if (address) {
                    locationText = `📌 ${address}`;
                    document.getElementById('editLocationDisplay').innerHTML = locationText;
                    document.getElementById('editMapLink').style.display = 'none';
                    document.getElementById('editMapContainer').style.display = 'none';
                } else {
                    locationText = item.location_bin || 'No location data';
                    document.getElementById('editLocationDisplay').innerHTML = locationText;
                    document.getElementById('editMapLink').style.display = 'none';
                    document.getElementById('editMapContainer').style.display = 'none';
                }
                
                // Set max attribute for quantity input
                const maxQty = parseInt(item.current_stock) + parseInt(item.quantity_to_pick);
                document.getElementById('editQuantity').max = maxQty;
                document.getElementById('editStockInfo').textContent = `Max available: ${maxQty}`;
                
                // Set driver selection
                $('#editDriverSelect').val(item.driver_id || '').trigger('change');
                document.getElementById('editDriverId').value = item.driver_id || '';
                
                // Show alert based on stock
                const stockAlert = document.getElementById('editStockAlert');
                if (parseInt(item.current_stock) < parseInt(item.quantity_to_pick)) {
                    stockAlert.className = 'alert alert-warning';
                    stockAlert.innerHTML = `
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        Warning: Current stock (${item.current_stock}) is less than pick quantity (${item.quantity_to_pick}). 
                        You may need to adjust the quantity.
                    `;
                } else {
                    stockAlert.className = 'alert alert-info';
                    stockAlert.innerHTML = `
                        <i class="bi bi-info-circle me-2"></i>
                        Current stock: ${item.current_stock} | Current pick quantity: ${item.quantity_to_pick}
                    `;
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
        mapContainer.style.display = 'block';
        
        // Clear previous map
        mapContainer.innerHTML = '';
        
        // Initialize map
        const map = L.map('editMapContainer').setView([lat, lng], 15);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '© OpenStreetMap contributors'
        }).addTo(map);
        
        // Add marker
        const marker = L.marker([lat, lng]).addTo(map);
        if (address) {
            marker.bindPopup(address).openPopup();
        }
        
        // Store map instance
        maps.editMap = map;
        
        // Resize map after modal is shown
        setTimeout(() => {
            map.invalidateSize();
        }, 300);
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
            Swal.fire('Warning', `Quantity cannot exceed ${maxQty}`, 'warning');
            return;
        }
        
        showLoading();
        
        const formData = new FormData();
        formData.append('action', 'update_pick_item');
        formData.append('pick_item_id', pickItemId);
        formData.append('quantity_to_pick', quantity);
        formData.append('location_bin', locationBin);
        formData.append('driver_id', driverId);
        
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
                    timer: 2000,
                    showConfirmButton: false
                }).then(() => {
                    const modal = bootstrap.Modal.getInstance(document.getElementById('editItemModal'));
                    if (modal) {
                        modal.hide();
                        const backdrop = document.querySelector('.modal-backdrop');
                        if (backdrop) {
                            backdrop.remove();
                        }
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

    // ========== VIEW FUNCTIONS ==========
    function viewItem(id) {
        const row = document.querySelector(`.pick-list-row[data-id="${id}"]`);
        if (!row) return;
        
        selectedPickItemId = id;
        
        const soNumber = row.dataset.soId || 'N/A';
        const itemCode = row.dataset.itemCode || 'N/A';
        const itemName = row.querySelector('.col-item-name')?.innerText.split('\n')[0] || 'N/A';
        const quantity = row.dataset.quantity || '0';
        const location = row.querySelector('.col-location')?.innerHTML || '—';
        const status = row.dataset.status || '';
        const driverName = row.dataset.driverName || 'Unassigned';
        const stockText = row.querySelector('.stock-indicator')?.innerText || 'Stock: 0';
        const branchName = row.dataset.branchName || `Branch ${row.dataset.branchId}`;
        const encodedBy = row.dataset.encodedBy || 'System';
        const encodedAt = row.dataset.encodedAt ? new Date(row.dataset.encodedAt).toLocaleString() : 'N/A';
        const customerName = row.dataset.customerName || 'N/A';
        const latitude = row.dataset.latitude;
        const longitude = row.dataset.longitude;
        const address = row.dataset.address;
        
        let detailsHtml = `
            <div class="col-md-6">
                <div class="detail-card">
                    <div class="detail-label">SO Number</div>
                    <div class="detail-value">${soNumber}</div>
                </div>
                <div class="detail-card">
                    <div class="detail-label">Customer</div>
                    <div class="detail-value">${customerName}</div>
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
                <div class="detail-card">
                    <div class="detail-label">Encoded By</div>
                    <div class="detail-value">
                        <span class="badge bg-info">
                            <i class="bi bi-person"></i> ${encodedBy}
                        </span>
                    </div>
                </div>
                <div class="detail-card">
                    <div class="detail-label">Encoded At</div>
                    <div class="detail-value">
                        <span class="text-muted">
                            <i class="bi bi-calendar"></i> ${encodedAt}
                        </span>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
        `;
        
        if (viewAllBranches && pickListsBranchColumnExists) {
            detailsHtml += `
                <div class="detail-card">
                    <div class="detail-label">Branch</div>
                    <div class="detail-value">
                        <span class="badge bg-info">${branchName}</span>
                    </div>
                </div>
            `;
        }
        
        detailsHtml += `
                <div class="detail-card">
                    <div class="detail-label">Delivery Location</div>
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
        
        // Add map if coordinates available
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
        
        // Initialize map if coordinates available
        if (latitude && longitude) {
            setTimeout(() => {
                const mapContainer = document.getElementById('viewMapContainer');
                if (mapContainer) {
                    const map = L.map('viewMapContainer').setView([latitude, longitude], 15);
                    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                        attribution: '© OpenStreetMap contributors'
                    }).addTo(map);
                    
                    const marker = L.marker([latitude, longitude]).addTo(map);
                    if (address) {
                        marker.bindPopup(address).openPopup();
                    }
                    
                    // Store map instance
                    maps.viewMap = map;
                }
            }, 300);
        }
        
        // Show/hide edit button based on status and permissions
        const editBtn = document.getElementById('editFromViewBtn');
        if (editBtn) {
            if (status === 'open' && (viewAllBranches || (pickListsBranchColumnExists && row.dataset.branchId == userBranchId))) {
                editBtn.style.display = 'inline-block';
                editBtn.onclick = function() { editFromView(); };
            } else {
                editBtn.style.display = 'none';
            }
        }
        
        new bootstrap.Modal(document.getElementById('viewItemModal')).show();
    }

    function editFromView() {
        bootstrap.Modal.getInstance(document.getElementById('viewItemModal')).hide();
        setTimeout(() => {
            if (selectedPickItemId) {
                editItem(selectedPickItemId);
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
                    const modal = bootstrap.Modal.getInstance(document.getElementById('deleteModal'));
                    if (modal) {
                        modal.hide();
                        // Remove modal backdrop manually if it persists
                        const backdrop = document.querySelector('.modal-backdrop');
                        if (backdrop) {
                            backdrop.remove();
                        }
                        document.body.classList.remove('modal-open');
                    }
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

    // ========== DRIVER SELECTION HANDLER ==========
    $(document).ready(function() {
        console.log("Document ready - initializing Select2");
        
        // Initialize Select2
        $('.select2-so').select2({
            placeholder: 'Search Sales Order...',
            allowClear: true,
            dropdownParent: $('#itemModal')
        });
        
        $('.select2-driver').select2({
            placeholder: 'Search Driver...',
            allowClear: true,
            dropdownParent: $('#itemModal')
        });
        
        $('.select2-driver-edit').select2({
            placeholder: 'Select Driver...',
            allowClear: true,
            dropdownParent: $('#editItemModal')
        });

        // Driver change event for add modal
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
        
        // Driver change event for edit modal
        $('#editDriverSelect').on('change', function() {
            const select = document.getElementById('editDriverSelect');
            const selectedOption = select.options[select.selectedIndex];
            
            if (selectedOption && selectedOption.value) {
                document.getElementById('editDriverId').value = selectedOption.value;
            } else {
                document.getElementById('editDriverId').value = '';
            }
        });
        
        // Fix for modal backdrop issue when clicking cancel
        $('#itemModal, #editItemModal, #deleteModal, #viewItemModal').on('hidden.bs.modal', function () {
            // Remove any lingering backdrops
            const backdrop = document.querySelector('.modal-backdrop');
            if (backdrop) {
                backdrop.remove();
            }
            document.body.classList.remove('modal-open');
            document.body.style.removeProperty('padding-right');
            
            // Clean up maps
            if (maps.viewMap) {
                maps.viewMap.remove();
                maps.viewMap = null;
            }
            if (maps.editMap) {
                maps.editMap.remove();
                maps.editMap = null;
            }
        });
        
        // Resize maps when modal is shown
        $('#viewItemModal').on('shown.bs.modal', function() {
            if (maps.viewMap) {
                setTimeout(() => {
                    maps.viewMap.invalidateSize();
                }, 100);
            }
        });
        
        $('#editItemModal').on('shown.bs.modal', function() {
            if (maps.editMap) {
                setTimeout(() => {
                    maps.editMap.invalidateSize();
                }, 100);
            }
        });
    });

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
        const headers = [
            'SO Number',
            'Customer Name',
            ...(viewAllBranches && pickListsBranchColumnExists ? ['Branch'] : []),
            'Item Code',
            'Item Name',
            'Quantity to Pick',
            'Quantity Picked',
            'Delivery Location',
            'Coordinates',
            'Status',
            'Assigned Driver',
            'Encoded By',
            'Encoded At',
            'Current Stock'
        ];
        excelData.push(headers);

        // Add data rows
        rows.forEach(row => {
            if (row.style.display !== 'none') {
                const cells = row.querySelectorAll('td');
                let cellIndex = 0;
                
                const soNumberCell = cells[cellIndex++];
                const soNumber = soNumberCell?.innerText.split('\n')[0].trim() || '';
                const customerName = soNumberCell?.querySelector('small')?.innerText || '';
                
                let branchName = '';
                if (viewAllBranches && pickListsBranchColumnExists) {
                    branchName = cells[cellIndex++]?.innerText.replace(/\n/g, ' ').trim() || '';
                }
                
                const itemCode = cells[cellIndex++]?.innerText || '';
                const itemName = cells[cellIndex++]?.innerText.split('\n')[0].trim() || '';
                const toPick = parseInt(cells[cellIndex++]?.innerText) || 0;
                const picked = parseInt(cells[cellIndex++]?.innerText) || 0;
                const locationHtml = cells[cellIndex++]?.innerHTML || '';
                
                // Extract location and coordinates
                let locationText = '';
                let coordinates = '';
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
                
                // Extract stock from the stock indicator
                let stock = 0;
                const stockElement = row.querySelector('.stock-indicator');
                if (stockElement) {
                    const stockText = stockElement.innerText;
                    const stockMatch = stockText.match(/\d+/);
                    if (stockMatch) stock = parseInt(stockMatch[0]);
                }
                
                const rowData = [
                    soNumber,
                    customerName,
                    ...(viewAllBranches && pickListsBranchColumnExists ? [branchName] : []),
                    itemCode,
                    itemName,
                    toPick,
                    picked,
                    locationText,
                    coordinates,
                    status,
                    driver,
                    encodedBy,
                    encodedAt,
                    stock
                ];
                
                excelData.push(rowData);
            }
        });

        // Create workbook and worksheet
        const wb = XLSX.utils.book_new();
        const ws = XLSX.utils.aoa_to_sheet(excelData);

        // Set column widths
        const colWidths = [
            { wch: 15 }, // SO Number
            { wch: 20 }, // Customer Name
            ...(viewAllBranches && pickListsBranchColumnExists ? [{ wch: 15 }] : []), // Branch
            { wch: 15 }, // Item Code
            { wch: 30 }, // Item Name
            { wch: 15 }, // Quantity to Pick
            { wch: 15 }, // Quantity Picked
            { wch: 40 }, // Delivery Location
            { wch: 30 }, // Coordinates
            { wch: 15 }, // Status
            { wch: 20 }, // Assigned Driver
            { wch: 20 }, // Encoded By
            { wch: 20 }, // Encoded At
            { wch: 15 }  // Current Stock
        ];
        ws['!cols'] = colWidths;

        // Add worksheet to workbook
        XLSX.utils.book_append_sheet(wb, ws, 'Pick List Items');

        // Generate filename with current date and branch info
        const date = new Date();
        const dateStr = date.toISOString().slice(0,10).replace(/-/g, '');
        let filename = `Pick_List_Items_${dateStr}`;
        if (!viewAllBranches) {
            filename += `_${branchName.replace(/\s+/g, '_')}`;
        }
        filename += '.xlsx';

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

    // ========== PRINT FUNCTION ==========
    function printPickList() {
        window.print();
    }

    // ========== LOGOUT FUNCTION ==========
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
                window.location.href = '../logout.php';
            }
        });
    }

    // ========== DOCUMENT READY ==========
    document.addEventListener('DOMContentLoaded', function() {
        console.log("DOM fully loaded - Pick List Items with Customer Location");
        console.log("User Branch:", userBranchId);
        console.log("View All Branches:", viewAllBranches);
        console.log("User Role:", userRole);
        console.log("Branch Name:", branchName);
        
        initializeSidebar();
        
        // Mobile menu toggle
        const mobileMenuBtn = document.getElementById('mobileMenuBtn');
        if (mobileMenuBtn) {
            mobileMenuBtn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                toggleSidebar();
            });
        }
        
        const desktopToggleBtn = document.getElementById('desktopToggleBtn');
        if (desktopToggleBtn) {
            desktopToggleBtn.addEventListener('click', function(e) {
                e.preventDefault();
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
        
        // Fix for modal backdrop issue when clicking cancel
        const modals = ['itemModal', 'editItemModal', 'deleteModal', 'viewItemModal'];
        modals.forEach(modalId => {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.addEventListener('hidden.bs.modal', function () {
                    // Remove any lingering backdrops
                    const backdrop = document.querySelector('.modal-backdrop');
                    if (backdrop) {
                        backdrop.remove();
                    }
                    document.body.classList.remove('modal-open');
                    document.body.style.removeProperty('padding-right');
                    document.body.style.removeProperty('overflow');
                    
                    // Clean up maps
                    if (maps.viewMap) {
                        maps.viewMap.remove();
                        maps.viewMap = null;
                    }
                    if (maps.editMap) {
                        maps.editMap.remove();
                        maps.editMap = null;
                    }
                });
            }
        });
    });

    // ========== KEYBOARD SHORTCUTS ==========
    document.addEventListener('keydown', function(e) {
        if (e.ctrlKey && e.key === 'b' && window.innerWidth > 992) {
            e.preventDefault();
            toggleSidebar();
        } else if (e.ctrlKey && e.key === 'f') {
            e.preventDefault();
            const searchInput = document.getElementById('globalSearch');
            if (searchInput) searchInput.focus();
        } else if (e.ctrlKey && e.key === 'n') {
            e.preventDefault();
            showAddItemModal();
        }
    });
    </script>
</body>
</html>