<?php
require_once '../config/database.php';
require_once '../config/session_handler.php';

// Get current user info and branch context
$user_id = $_SESSION['user_id'] ?? 0;
$user_name = isset($_SESSION['first_name']) ? $_SESSION['first_name'] . ' ' . $_SESSION['last_name'] : 'Branch Admin';
$user_role = isset($_SESSION['role']) ? $_SESSION['role'] : 'branch_admin';
$branch_id = $_SESSION['branch_id'] ?? 0;
$view_all_branches = $_SESSION['view_all_branches'] ?? false;

// ========== CHECK AND ADD SUPPLIER_ID COLUMN IF NEEDED ==========
$supplier_id_column_exists = false;
$check_supplier_id = $conn->query("SHOW COLUMNS FROM purchase_orders LIKE 'supplier_id'");
if ($check_supplier_id && $check_supplier_id->num_rows > 0) {
    $supplier_id_column_exists = true;
}

// Check if branch_id column exists in purchase_orders table
$po_branch_column_exists = false;
$check_po_column = $conn->query("SHOW COLUMNS FROM purchase_orders LIKE 'branch_id'");
if ($check_po_column && $check_po_column->num_rows > 0) {
    $po_branch_column_exists = true;
}

// Check if branch_id column exists in items table
$items_branch_column_exists = false;
$check_items_column = $conn->query("SHOW COLUMNS FROM items LIKE 'branch_id'");
if ($check_items_column && $check_items_column->num_rows > 0) {
    $items_branch_column_exists = true;
}

// Determine branch filter condition - ONLY if column exists
$po_branch_condition = "";
if ($po_branch_column_exists && !$view_all_branches) {
    $po_branch_condition = "AND po.branch_id = $branch_id";
}

// ========== RESTORED BRANCH FILTER FOR ITEMS ==========
// Ngayon ang items ay filtered base sa branch ng user
$items_branch_condition = "";
if ($items_branch_column_exists && !$view_all_branches) {
    $items_branch_condition = "AND branch_id = $branch_id";
}

// ========== FETCH SUPPLIERS FROM DATABASE FOR DROPDOWN ==========
$suppliers_query = "SELECT supplier_id, supplier_name, supplier_code, contact_person, email, phone_number 
                   FROM suppliers 
                   WHERE status = 'active'";
// Add branch filter for suppliers if needed
if (!$view_all_branches && $branch_id > 0) {
    // Check if branch_id exists in suppliers table first
    $check_supplier_branch = $conn->query("SHOW COLUMNS FROM suppliers LIKE 'branch_id'");
    if ($check_supplier_branch && $check_supplier_branch->num_rows > 0) {
        $suppliers_query .= " AND branch_id = $branch_id";
    }
}
$suppliers_query .= " ORDER BY supplier_name ASC";
$suppliers_result = $conn->query($suppliers_query);
$suppliers_list = $suppliers_result ? $suppliers_result->fetch_all(MYSQLI_ASSOC) : [];

// ========== FETCH ALL ITEMS FOR DROPDOWN - WITH BRANCH FILTER ==========
$items_query = "SELECT 
    item_id, 
    item_code, 
    item_name, 
    unit_type,
    unit_price as price_piece,
    price_case,
    price_inner_pack,
    price_box,
    price_carton,
    stock,
    branch_id,
    status
FROM items 
WHERE status = 'active' 
$items_branch_condition 
ORDER BY item_name";

$items_result = $conn->query($items_query);

if (!$items_result) {
    $items_list = [];
    error_log("Items Query Error: " . $conn->error);
} else {
    $items_list = $items_result->fetch_all(MYSQLI_ASSOC);
}

// Debug: Check if items are loaded
if (empty($items_list)) {
    error_log("WARNING: No items found for branch ID: " . $branch_id);
} else {
    error_log("SUCCESS: " . count($items_list) . " items loaded for branch ID: " . $branch_id);
}

// ========== HANDLE AJAX REQUESTS ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    try {
        $conn->begin_transaction();
        
        // CREATE PURCHASE ORDER
        if ($_POST['action'] === 'create_po') {
            $po_number = 'PO-' . date('Ymd') . '-' . rand(1000, 9999);
            $supplier_name = $_POST['supplier_name'];
            $supplier_id = isset($_POST['supplier_id']) && !empty($_POST['supplier_id']) ? (int)$_POST['supplier_id'] : null;
            $order_date = $_POST['order_date'];
            $expected_delivery = $_POST['expected_delivery'] ?? null;
            $total_amount = (float)$_POST['total_amount'];
            $po_status = $_POST['po_status'] ?? 'draft';
            $created_by = $user_id;
            
            // Build insert query based on which columns exist
            $fields = ["po_number", "supplier_name", "order_date", "expected_delivery", "total_amount", "po_status", "created_by", "created_at", "updated_at"];
            $placeholders = ["?", "?", "?", "?", "?", "?", "?", "NOW()", "NOW()"];
            $types = "ssssdsi";
            $params = [$po_number, $supplier_name, $order_date, $expected_delivery, $total_amount, $po_status, $created_by];
            
            // Add supplier_id if column exists
            if ($supplier_id_column_exists) {
                array_splice($fields, 2, 0, "supplier_id");
                array_splice($placeholders, 2, 0, "?");
                $types .= "i";
                array_splice($params, 2, 0, $supplier_id);
            }
            
            // Add branch_id if column exists
            if ($po_branch_column_exists) {
                $fields[] = "branch_id";
                $placeholders[] = "?";
                $types .= "i";
                $params[] = $branch_id;
            }
            
            $insert_query = "INSERT INTO purchase_orders (" . implode(", ", $fields) . ") 
                           VALUES (" . implode(", ", $placeholders) . ")";
            
            $insert_stmt = $conn->prepare($insert_query);
            if (!$insert_stmt) {
                throw new Exception('Prepare failed: ' . $conn->error);
            }
            
            // Dynamically bind parameters
            $insert_stmt->bind_param($types, ...$params);
            
            if (!$insert_stmt->execute()) {
                throw new Exception('Failed to create purchase order: ' . $insert_stmt->error);
            }
            
            $po_id = $conn->insert_id;
            
            // Add items if provided
            if (isset($_POST['items']) && !empty($_POST['items'])) {
                $items = json_decode($_POST['items'], true);
                if (is_array($items) && count($items) > 0) {
                    foreach ($items as $item) {
                        $item_id = (int)$item['item_id'];
                        $quantity = (int)$item['quantity'];
                        $unit_price = (float)$item['unit_price'];
                        
                        $item_query = "INSERT INTO purchase_order_items (po_id, item_id, quantity_ordered, unit_price) 
                                      VALUES (?, ?, ?, ?)";
                        $item_stmt = $conn->prepare($item_query);
                        $item_stmt->bind_param("iiid", $po_id, $item_id, $quantity, $unit_price);
                        
                        if (!$item_stmt->execute()) {
                            throw new Exception('Failed to add item: ' . $item_stmt->error);
                        }
                    }
                }
            }
            
            $conn->commit();
            
            echo json_encode([
                'success' => true,
                'message' => 'Purchase order created successfully',
                'po_id' => $po_id,
                'po_number' => $po_number
            ]);
            exit;
        }
        
        // UPDATE PURCHASE ORDER
        elseif ($_POST['action'] === 'update_po') {
            $po_id = (int)$_POST['po_id'];
            $supplier_name = $_POST['supplier_name'];
            $supplier_id = isset($_POST['supplier_id']) && !empty($_POST['supplier_id']) ? (int)$_POST['supplier_id'] : null;
            $order_date = $_POST['order_date'];
            $expected_delivery = $_POST['expected_delivery'] ?? null;
            $total_amount = (float)$_POST['total_amount'];
            $po_status = $_POST['po_status'];
            
            // Verify PO belongs to user's branch (if branch column exists and not admin)
            if ($po_branch_column_exists && !$view_all_branches) {
                $check_query = "SELECT po_id FROM purchase_orders WHERE po_id = ? AND branch_id = ?";
                $check_stmt = $conn->prepare($check_query);
                $check_stmt->bind_param("ii", $po_id, $branch_id);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                
                if ($check_result->num_rows === 0) {
                    throw new Exception('Purchase order not found or access denied');
                }
            }
            
            // Get current PO status before update
            $status_query = "SELECT po_status FROM purchase_orders WHERE po_id = ?";
            $status_stmt = $conn->prepare($status_query);
            $status_stmt->bind_param("i", $po_id);
            $status_stmt->execute();
            $status_result = $status_stmt->get_result();
            $current_po = $status_result->fetch_assoc();
            $old_status = $current_po['po_status'];
            
            // Build update query based on which columns exist
            $set_fields = ["supplier_name = ?", "order_date = ?", "expected_delivery = ?", "total_amount = ?", "po_status = ?", "updated_at = NOW()"];
            $types = "sssds";
            $params = [$supplier_name, $order_date, $expected_delivery, $total_amount, $po_status];
            
            // Add supplier_id if column exists
            if ($supplier_id_column_exists) {
                array_splice($set_fields, 1, 0, "supplier_id = ?");
                $types .= "i";
                array_splice($params, 1, 0, $supplier_id);
            }
            
            $params[] = $po_id;
            $types .= "i";
            
            $update_query = "UPDATE purchase_orders 
                           SET " . implode(", ", $set_fields) . " 
                           WHERE po_id = ?";
            
            $update_stmt = $conn->prepare($update_query);
            if (!$update_stmt) {
                throw new Exception('Prepare failed: ' . $conn->error);
            }
            
            $update_stmt->bind_param($types, ...$params);
            
            if (!$update_stmt->execute()) {
                throw new Exception('Failed to update purchase order: ' . $update_stmt->error);
            }
            
            // If status changed to 'received', update inventory
            if ($po_status === 'received' && $old_status !== 'received') {
                // Get all items from this PO
                $items_query = "SELECT poi.item_id, poi.quantity_ordered, poi.unit_price, i.item_name 
                               FROM purchase_order_items poi
                               JOIN items i ON poi.item_id = i.item_id
                               WHERE poi.po_id = ?";
                $items_stmt = $conn->prepare($items_query);
                $items_stmt->bind_param("i", $po_id);
                $items_stmt->execute();
                $items_result = $items_stmt->get_result();
                
                while ($item = $items_result->fetch_assoc()) {
                    $item_id = $item['item_id'];
                    $quantity = $item['quantity_ordered'];
                    
                    // Check if inventory record exists for this branch and item
                    $inv_query = "SELECT inventory_id, quantity_on_hand FROM inventory WHERE branch_id = ? AND item_id = ?";
                    $inv_stmt = $conn->prepare($inv_query);
                    $inv_stmt->bind_param("ii", $branch_id, $item_id);
                    $inv_stmt->execute();
                    $inv_result = $inv_stmt->get_result();
                    
                    if ($inv_result->num_rows > 0) {
                        // Update existing inventory
                        $inv_row = $inv_result->fetch_assoc();
                        $new_quantity = $inv_row['quantity_on_hand'] + $quantity;
                        
                        $update_inv_query = "UPDATE inventory 
                                           SET quantity_on_hand = ?, last_updated_by = ?, updated_at = NOW() 
                                           WHERE inventory_id = ?";
                        $update_inv_stmt = $conn->prepare($update_inv_query);
                        $update_inv_stmt->bind_param("iii", $new_quantity, $user_id, $inv_row['inventory_id']);
                        
                        if (!$update_inv_stmt->execute()) {
                            throw new Exception('Failed to update inventory for item: ' . $item['item_name']);
                        }
                    } else {
                        // Create new inventory record
                        $insert_inv_query = "INSERT INTO inventory (branch_id, item_id, quantity_on_hand, quantity_reserved, last_updated_by, updated_at) 
                                           VALUES (?, ?, ?, 0, ?, NOW())";
                        $insert_inv_stmt = $conn->prepare($insert_inv_query);
                        $insert_inv_stmt->bind_param("iiii", $branch_id, $item_id, $quantity, $user_id);
                        
                        if (!$insert_inv_stmt->execute()) {
                            throw new Exception('Failed to create inventory record for item: ' . $item['item_name']);
                        }
                    }
                    
                    // Record inventory transaction
                    $trans_query = "INSERT INTO inventory_transactions 
                                   (branch_id, item_id, transaction_type, quantity_changed, reference_type, reference_id, created_by, created_at) 
                                   VALUES (?, ?, 'in', ?, 'purchase_order', ?, ?, NOW())";
                    $trans_stmt = $conn->prepare($trans_query);
                    $trans_stmt->bind_param("iiiii", $branch_id, $item_id, $quantity, $po_id, $user_id);
                    
                    if (!$trans_stmt->execute()) {
                        throw new Exception('Failed to record inventory transaction for item: ' . $item['item_name']);
                    }
                }
            }
            
            $conn->commit();
            
            echo json_encode([
                'success' => true,
                'message' => 'Purchase order updated successfully' . ($po_status === 'received' ? ' and inventory updated' : '')
            ]);
            exit;
        }
        
        // DELETE PURCHASE ORDER
        elseif ($_POST['action'] === 'delete_po') {
            $po_id = (int)$_POST['po_id'];
            
            // Verify PO belongs to user's branch (if branch column exists and not admin)
            if ($po_branch_column_exists && !$view_all_branches) {
                $check_query = "SELECT po_id FROM purchase_orders WHERE po_id = ? AND branch_id = ?";
                $check_stmt = $conn->prepare($check_query);
                $check_stmt->bind_param("ii", $po_id, $branch_id);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                
                if ($check_result->num_rows === 0) {
                    throw new Exception('Purchase order not found or access denied');
                }
            }
            
            // Check if PO is already received - cannot delete received POs
            $status_query = "SELECT po_status FROM purchase_orders WHERE po_id = ?";
            $status_stmt = $conn->prepare($status_query);
            $status_stmt->bind_param("i", $po_id);
            $status_stmt->execute();
            $status_result = $status_stmt->get_result();
            $po_data = $status_result->fetch_assoc();
            
            if ($po_data['po_status'] === 'received') {
                throw new Exception('Cannot delete a received purchase order');
            }
            
            // Delete order items first
            $delete_items_query = "DELETE FROM purchase_order_items WHERE po_id = ?";
            $delete_items_stmt = $conn->prepare($delete_items_query);
            $delete_items_stmt->bind_param("i", $po_id);
            $delete_items_stmt->execute();
            
            // Delete the order
            $delete_order_query = "DELETE FROM purchase_orders WHERE po_id = ?";
            $delete_order_stmt = $conn->prepare($delete_order_query);
            $delete_order_stmt->bind_param("i", $po_id);
            
            if (!$delete_order_stmt->execute()) {
                throw new Exception('Failed to delete purchase order: ' . $delete_order_stmt->error);
            }
            
            $conn->commit();
            
            echo json_encode([
                'success' => true,
                'message' => 'Purchase order deleted successfully'
            ]);
            exit;
        }
        
        // GET PURCHASE ORDER DETAILS
        elseif ($_POST['action'] === 'get_po') {
            $po_id = (int)$_POST['po_id'];
            
            // Add branch filter if needed
            $query = "
                SELECT 
                    po.*,
                    b.branch_name,
                    CONCAT(u.first_name, ' ', u.last_name) as created_by_name,
                    COUNT(poi.po_item_id) as total_items,
                    IFNULL(SUM(poi.quantity_ordered), 0) as total_quantity
                FROM purchase_orders po
                LEFT JOIN branches b ON po.branch_id = b.branch_id
                LEFT JOIN users u ON po.created_by = u.user_id
                LEFT JOIN purchase_order_items poi ON po.po_id = poi.po_id
                WHERE po.po_id = ?
            ";
            
            if ($po_branch_column_exists && !$view_all_branches) {
                $query .= " AND po.branch_id = ?";
                $stmt = $conn->prepare($query);
                $stmt->bind_param("ii", $po_id, $branch_id);
            } else {
                $stmt = $conn->prepare($query);
                $stmt->bind_param("i", $po_id);
            }
            
            $stmt->execute();
            $result = $stmt->get_result();
            $po = $result->fetch_assoc();
            
            if ($po) {
                // Get PO items
                $items_query = "
                    SELECT 
                        poi.*,
                        i.item_code,
                        i.item_name,
                        i.unit_type
                    FROM purchase_order_items poi
                    JOIN items i ON poi.item_id = i.item_id
                    WHERE poi.po_id = ?
                    ORDER BY poi.po_item_id
                ";
                $items_stmt = $conn->prepare($items_query);
                $items_stmt->bind_param("i", $po_id);
                $items_stmt->execute();
                $items_result = $items_stmt->get_result();
                $items = $items_result->fetch_all(MYSQLI_ASSOC);
                
                echo json_encode([
                    'success' => true,
                    'po' => $po,
                    'items' => $items
                ]);
            } else {
                throw new Exception('Purchase order not found');
            }
            exit;
        }
        
        // ADD PO ITEM
        elseif ($_POST['action'] === 'add_po_item') {
            $po_id = (int)$_POST['po_id'];
            $item_id = (int)$_POST['item_id'];
            $quantity_ordered = (int)$_POST['quantity_ordered'];
            $unit_price = (float)$_POST['unit_price'];
            
            // Verify PO belongs to user's branch (if branch column exists and not admin)
            if ($po_branch_column_exists && !$view_all_branches) {
                $check_query = "SELECT po_id FROM purchase_orders WHERE po_id = ? AND branch_id = ?";
                $check_stmt = $conn->prepare($check_query);
                $check_stmt->bind_param("ii", $po_id, $branch_id);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                
                if ($check_result->num_rows === 0) {
                    throw new Exception('Purchase order not found or access denied');
                }
            }
            
            // Check if PO is already received - cannot add items to received POs
            $status_query = "SELECT po_status FROM purchase_orders WHERE po_id = ?";
            $status_stmt = $conn->prepare($status_query);
            $status_stmt->bind_param("i", $po_id);
            $status_stmt->execute();
            $status_result = $status_stmt->get_result();
            $po_data = $status_result->fetch_assoc();
            
            if ($po_data['po_status'] === 'received') {
                throw new Exception('Cannot add items to a received purchase order');
            }
            
            $insert_query = "INSERT INTO purchase_order_items (po_id, item_id, quantity_ordered, unit_price) 
                           VALUES (?, ?, ?, ?)";
            $insert_stmt = $conn->prepare($insert_query);
            $insert_stmt->bind_param("iiid", $po_id, $item_id, $quantity_ordered, $unit_price);
            
            if (!$insert_stmt->execute()) {
                throw new Exception('Failed to add item to purchase order: ' . $insert_stmt->error);
            }
            
            // Update PO total amount
            $update_total_query = "UPDATE purchase_orders 
                                  SET total_amount = (SELECT IFNULL(SUM(quantity_ordered * unit_price), 0) FROM purchase_order_items WHERE po_id = ?)
                                  WHERE po_id = ?";
            $update_total_stmt = $conn->prepare($update_total_query);
            $update_total_stmt->bind_param("ii", $po_id, $po_id);
            $update_total_stmt->execute();
            
            $conn->commit();
            
            echo json_encode([
                'success' => true,
                'message' => 'Item added successfully'
            ]);
            exit;
        }
        
        // DELETE PO ITEM
        elseif ($_POST['action'] === 'delete_po_item') {
            $po_item_id = (int)$_POST['po_item_id'];
            $po_id = (int)$_POST['po_id'];
            
            // Verify PO belongs to user's branch (if branch column exists and not admin)
            if ($po_branch_column_exists && !$view_all_branches) {
                $check_query = "SELECT po_id FROM purchase_orders WHERE po_id = ? AND branch_id = ?";
                $check_stmt = $conn->prepare($check_query);
                $check_stmt->bind_param("ii", $po_id, $branch_id);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                
                if ($check_result->num_rows === 0) {
                    throw new Exception('Purchase order not found or access denied');
                }
            }
            
            // Check if PO is already received - cannot delete items from received POs
            $status_query = "SELECT po_status FROM purchase_orders WHERE po_id = ?";
            $status_stmt = $conn->prepare($status_query);
            $status_stmt->bind_param("i", $po_id);
            $status_stmt->execute();
            $status_result = $status_stmt->get_result();
            $po_data = $status_result->fetch_assoc();
            
            if ($po_data['po_status'] === 'received') {
                throw new Exception('Cannot delete items from a received purchase order');
            }
            
            $delete_query = "DELETE FROM purchase_order_items WHERE po_item_id = ?";
            $delete_stmt = $conn->prepare($delete_query);
            $delete_stmt->bind_param("i", $po_item_id);
            
            if (!$delete_stmt->execute()) {
                throw new Exception('Failed to delete item: ' . $delete_stmt->error);
            }
            
            // Update PO total amount
            $update_total_query = "UPDATE purchase_orders 
                                  SET total_amount = (SELECT IFNULL(SUM(quantity_ordered * unit_price), 0) FROM purchase_order_items WHERE po_id = ?)
                                  WHERE po_id = ?";
            $update_total_stmt = $conn->prepare($update_total_query);
            $update_total_stmt->bind_param("ii", $po_id, $po_id);
            $update_total_stmt->execute();
            
            $conn->commit();
            
            echo json_encode([
                'success' => true,
                'message' => 'Item deleted successfully'
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

// FETCH PURCHASE ORDERS FROM DATABASE WITH BRANCH FILTERING
$po_query = "
    SELECT 
        po.po_id,
        po.po_number,
        po.order_date,
        po.expected_delivery,
        po.total_amount,
        po.po_status,
        po.supplier_name,
        po.branch_id,
        po.created_at,
        po.updated_at,
        po.created_by,
        b.branch_name,
        CONCAT(u.first_name, ' ', u.last_name) as created_by_name,
        COUNT(poi.po_item_id) as total_items,
        IFNULL(SUM(poi.quantity_ordered), 0) as total_quantity
    FROM purchase_orders po
    LEFT JOIN branches b ON po.branch_id = b.branch_id
    LEFT JOIN users u ON po.created_by = u.user_id
    LEFT JOIN purchase_order_items poi ON po.po_id = poi.po_id
    WHERE 1=1
    $po_branch_condition
    GROUP BY po.po_id
    ORDER BY po.created_at DESC, po.po_id DESC
";
$po_result = $conn->query($po_query);

if (!$po_result) {
    $purchase_orders = [];
    error_log("PO Query Error: " . $conn->error);
} else {
    $purchase_orders = $po_result->fetch_all(MYSQLI_ASSOC);
}

// CALCULATE STATISTICS FROM REAL DATA (branch-specific)
$total_po = count($purchase_orders);
$draft_po = count(array_filter($purchase_orders, fn($po) => $po['po_status'] === 'draft'));
$submitted_po = count(array_filter($purchase_orders, fn($po) => $po['po_status'] === 'submitted'));
$approved_po = count(array_filter($purchase_orders, fn($po) => $po['po_status'] === 'approved'));
$received_po = count(array_filter($purchase_orders, fn($po) => $po['po_status'] === 'received'));
$cancelled_po = count(array_filter($purchase_orders, fn($po) => $po['po_status'] === 'cancelled'));

// STAT CARD VALUES
$statTotalPO = $total_po;
$statProcessingPO = $submitted_po + $approved_po;
$statDeliveredPO = $received_po;
$statReturnedPO = $cancelled_po;

// Get unique suppliers from purchase_orders for filter - branch specific (only if column exists)
$filter_suppliers_query = "SELECT DISTINCT supplier_name FROM purchase_orders 
                    WHERE supplier_name IS NOT NULL AND supplier_name != ''";

// Only add branch condition if column exists and not viewing all branches
if ($po_branch_column_exists && !$view_all_branches) {
    $filter_suppliers_query .= " AND branch_id = $branch_id";
}

$filter_suppliers_query .= " ORDER BY supplier_name";
$filter_suppliers_result = $conn->query($filter_suppliers_query);
$filter_suppliers = $filter_suppliers_result ? $filter_suppliers_result->fetch_all(MYSQLI_ASSOC) : [];

// Helper function for PO status badge
function getPOStatusClass($status) {
    return match($status) {
        'draft' => 'status-draft',
        'submitted' => 'status-processing',
        'approved' => 'status-approved',
        'received' => 'status-delivered',
        'cancelled' => 'status-cancelled',
        default => 'status-draft'
    };
}

function getPOStatusText($status) {
    return match($status) {
        'draft' => 'Draft',
        'submitted' => 'Processing',
        'approved' => 'Approved',
        'received' => 'Delivered',
        'cancelled' => 'Cancelled',
        default => ucfirst($status)
    };
}

function formatDate($dateStr) {
    if (!$dateStr) return '';
    $date = new DateTime($dateStr);
    return $date->format('M d, Y');
}

function formatDateTime($dateStr) {
    if (!$dateStr) return '';
    $date = new DateTime($dateStr);
    return $date->format('M d, Y H:i');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Purchase Orders - Branch Admin</title>
    <link rel="icon" type="image/png" href="../Pictures/favicon-96x96.png" sizes="96x96" />
    <link rel="icon" type="image/svg+xml" href="../Pictures/favicon.svg" />
    <link rel="shortcut icon" href="../Pictures/favicon.ico" />
    <link rel="apple-touch-icon" sizes="180x180" href="../Pictures/apple-touch-icon.png" />
    <link rel="manifest" href="../Pictures/site.webmanifest" />
    <link rel="stylesheet" href="../css/current_inventory.css">
    <link rel="stylesheet" href="../css/purchase_order.css">
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <!-- Select2 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <!-- SheetJS for Excel Export -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <!-- SweetAlert2 -->
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <!-- Debug: Show items count in console -->
    <script>
        console.log('Current Branch ID: <?= $branch_id ?>');
        console.log('Items loaded for this branch: <?= count($items_list) ?>');
        <?php foreach($items_list as $item): ?>
        console.log('Item: <?= $item['item_name'] ?> (ID: <?= $item['item_id'] ?>, Branch: <?= $item['branch_id'] ?? 'NULL' ?>)');
        <?php endforeach; ?>
    </script>
    
    <style>
        /* Branch badge styling */
        .branch-badge {
            background-color: #e7f1ff;
            color: #0d6efd;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
            margin-left: 5px;
        }
        
        /* Alert for missing branch column */
        .alert-info {
            background-color: #d1ecf1;
            border-color: #bee5eb;
            color: #0c5460;
        }
        
        .alert-info code {
            background-color: #f8f9fa;
            padding: 2px 4px;
            border-radius: 4px;
            color: #c7254e;
        }
        
        /* Main layout */
        .main-content {
            padding: 20px 30px;
            transition: margin-left 0.3s ease;
        }
        
        /* Filter controls layout */
        .filter-controls {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 12px;
            margin-bottom: 25px;
            padding: 16px 20px;
            background-color: #f8f9fa;
            border-radius: 8px;
        }
        
        .filter-select {
            width: 160px;
            padding: 8px 12px;
            border: 1px solid #ced4da;
            border-radius: 4px;
            background-color: white;
            height: 40px;
        }
        
        .filter-search {
            position: relative;
            flex: 0 0 240px;
        }
        
        .filter-search i {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #6c757d;
            font-size: 15px;
            z-index: 10;
            pointer-events: none;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .filter-search input {
            width: 100%;
            padding: 8px 12px 8px 38px;
            border: 1px solid #ced4da;
            border-radius: 4px;
            height: 40px;
            font-size: 14px;
        }
        
        .filter-buttons {
            display: flex;
            gap: 10px;
            margin-left: auto;
        }
        
        .filter-buttons .btn {
            height: 40px;
            padding: 8px 16px;
            font-size: 14px;
        }
        
        /* Table wrapper */
        .table-wrapper {
            margin: 0 0 30px 0;
            width: 100%;
        }
        
        /* Table container */
        .table-container {
            background-color: white;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            overflow-x: auto;
            width: 100%;
        }
        
        /* Table styling */
        .po-table {
            margin-bottom: 0;
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }
        
        /* Column width definitions */
        .col-po { width: 11%; }
        .col-supplier { width: 13%; }
        .col-date { width: 10%; }
        <?php if ($po_branch_column_exists && $view_all_branches): ?>
        .col-branch { width: 8%; }
        <?php endif; ?>
        .col-items { width: 7%; }
        .col-qty { width: 8%; }
        .col-amount { width: 12%; }
        .col-status { width: 10%; }
        .col-expected { width: 12%; }
        .col-actions { width: 12%; }
        
        /* Table header styling */
        .po-table thead th {
            background-color: #f8f9fa;
            font-weight: 600;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #495057;
            padding: 16px 12px;
            border-bottom: 2px solid #dee2e6;
            white-space: nowrap;
            vertical-align: middle;
            text-align: left;
        }
        
        /* Table cell styling */
        .po-table tbody td {
            padding: 14px 12px;
            vertical-align: middle;
            border-bottom: 1px solid #e9ecef;
            font-size: 13px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        /* Column-specific alignments */
        .col-items,
        .col-qty {
            text-align: center !important;
        }
        
        .col-items th,
        .col-qty th {
            text-align: center !important;
        }
        
        .col-amount {
            text-align: right !important;
        }
        
        .col-amount th {
            text-align: right !important;
            padding-right: 20px !important;
        }
        
        .col-actions {
            text-align: center !important;
        }
        
        .col-actions th {
            text-align: center !important;
        }
        
        /* Hover effect */
        .po-table tbody tr:hover {
            background-color: #f8f9fa;
        }
        
        /* Status badge styling */
        .status-badge {
            display: inline-block;
            padding: 5px 12px;
            font-size: 12px;
            font-weight: 500;
            border-radius: 20px;
            text-align: center;
            min-width: 85px;
            white-space: nowrap;
        }
        
        .status-draft {
            background-color: #e9ecef;
            color: #495057;
        }
        
        .status-processing {
            background-color: #cfe2ff;
            color: #084298;
        }
        
        .status-approved {
            background-color: #cce5ff;
            color: #004085;
        }
        
        .status-delivered {
            background-color: #d1e7dd;
            color: #0a3622;
        }
        
        .status-cancelled {
            background-color: #f8d7da;
            color: #58151c;
        }
        
        /* Action buttons styling */
        .action-buttons {
            display: flex;
            gap: 6px;
            justify-content: center;
            align-items: center;
        }
        
        .table-btn {
            background: none;
            border: none;
            padding: 6px;
            border-radius: 4px;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 14px;
        }
        
        .table-btn:hover {
            background-color: #e9ecef;
        }
        
        .btn-view { color: #0d6efd; }
        .btn-edit { color: #ffc107; }
        .btn-delete { color: #dc3545; }
        
        /* Select2 customization */
        .select2-container--default .select2-selection--single {
            height: 38px;
            border: 1px solid #ced4da;
            border-radius: 4px;
        }
        
        .select2-container--default .select2-selection--single .select2-selection__rendered {
            line-height: 38px;
            padding-left: 12px;
        }
        
        .select2-container--default .select2-selection--single .select2-selection__arrow {
            height: 36px;
        }
        
        /* PO Details styling */
        .po-details-card {
            background-color: #f8f9fa;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
        }
        
        .detail-label {
            font-size: 12px;
            color: #6c757d;
            margin-bottom: 4px;
        }
        
        .detail-value {
            font-size: 16px;
            font-weight: 600;
            color: #212529;
        }
        
        /* Items table styling */
        .items-table {
            font-size: 13px;
        }
        
        .items-table th {
            background-color: #e9ecef;
            font-weight: 600;
        }
        
        /* New PO items section */
        .po-items-section {
            margin-top: 20px;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 15px;
            background-color: #f8f9fa;
        }
        
        .po-item-row {
            display: flex;
            gap: 10px;
            align-items: center;
            margin-bottom: 10px;
            padding: 10px;
            background-color: white;
            border-radius: 4px;
            border: 1px solid #dee2e6;
            flex-wrap: wrap;
        }
        
        .po-item-row .item-number {
            flex: 0 0 40px;
            font-weight: 600;
            color: #0d6efd;
            text-align: center;
            font-size: 14px;
        }
        
        .po-item-row .item-select {
            flex: 3;
            min-width: 200px;
        }
        
        .po-item-row .unit-select {
            flex: 1.5;
            min-width: 120px;
        }
        
        .po-item-row .quantity-container {
            flex: 1.5;
            display: flex;
            align-items: center;
            gap: 5px;
            min-width: 120px;
        }
        
        .po-item-row .quantity-container .item-quantity {
            width: 70px;
            text-align: center;
            padding: 6px 4px;
            -moz-appearance: textfield;
        }
        
        .po-item-row .quantity-container .item-quantity::-webkit-outer-spin-button,
        .po-item-row .quantity-container .item-quantity::-webkit-inner-spin-button {
            -webkit-appearance: none;
            margin: 0;
        }
        
        .po-item-row .quantity-container .quantity-btn {
            width: 32px;
            height: 32px;
            padding: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 16px;
            border: 1px solid #ced4da;
            background-color: #f8f9fa;
            cursor: pointer;
        }
        
        .po-item-row .quantity-container .quantity-btn:hover {
            background-color: #e9ecef;
        }
        
        .po-item-row .quantity-container .quantity-btn:active {
            background-color: #dee2e6;
        }
        
        .po-item-row .unit-price-display {
            flex: 1;
            min-width: 100px;
            padding: 8px;
            background-color: #e9ecef;
            border-radius: 4px;
            font-weight: 600;
            text-align: right;
        }
        
        .po-item-row .item-subtotal {
            flex: 1;
            min-width: 100px;
            padding: 8px;
            background-color: #d1e7dd;
            border-radius: 4px;
            font-weight: 600;
            color: #0a3622;
            text-align: right;
        }
        
        .remove-item-btn {
            flex: 0 0 40px;
        }
        
        /* Item count badge */
        .item-count-badge {
            display: inline-block;
            background-color: #0d6efd;
            color: white;
            border-radius: 20px;
            padding: 2px 10px;
            font-size: 12px;
            font-weight: 500;
            margin-left: 10px;
        }
        
        /* Text alignment utilities */
        .text-center {
            text-align: center;
        }
        
        .text-end {
            text-align: right;
        }
        
        /* Responsive adjustments */
        @media (max-width: 1600px) {
            .col-po { width: 11%; }
            .col-supplier { width: 13%; }
            .col-amount { width: 12%; }
        }
        
        @media (max-width: 1400px) {
            .filter-select { width: 140px; }
            .filter-search { flex: 0 0 200px; }
            
            .col-po { width: 11%; }
            .col-supplier { width: 12%; }
            .col-amount { width: 12%; }
            .col-expected { width: 11%; }
        }
        
        @media (max-width: 1200px) {
            .po-table { table-layout: auto; }
            .table-container { overflow-x: auto; }
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
                    <i class="bi bi-list"></i>
                </button>    
                 <img src="../Pictures/amgc3DLogo.png" alt="Logo" class="logo-icon"> 
                 <span class="nav-text">Branch Admin</span>
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
                        <a class="nav-link" href="pick_list_items.php">
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
                        <a class="nav-link" href="supplier.php" data-title="Suppliers">
                            <i class="bi bi-building"></i>
                            <span class="nav-text">Suppliers</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="purchase_order.php">
                            <i class="bi bi-box"></i>
                            <span class="nav-text">Purchase Orders</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="drivers.php">
                            <i class="bi bi-people"></i>
                            <span class="nav-text">Users</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="trip_tickets.php">
                            <i class="bi bi-ticket-perforated"></i>
                            <span class="nav-text">Trip Tickets</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="approve_credit_requests.php">
                            <i class="bi bi-pencil-square"></i>
                            <span class="nav-text">Approve Requests</span>
                        </a>
                    </li>
                    <hr class="sidebar-divider">
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
        
        <!-- Main Content -->
        <div class="main-content" id="mainContent">
            <!-- PURCHASE ORDERS CONTENT -->
            <div id="dashboardContent" class="page-content active">
                <!-- Navbar Top -->
                <div class="navbar-top">
                    <button class="mobile-menu-btn" id="mobileMenuBtn">
                        <i class="bi bi-list"></i>
                    </button>
                    <div class="page-title">
                        <h2>Purchase Orders</h2>
                        <p id="dashboardSubtitle">
                            Manage and track all purchase orders
                        </p>
                    </div>
                </div>

                <!-- Branch Info Alerts -->
                <?php if (!$po_branch_column_exists): ?>
                    <div class="alert alert-info alert-dismissible fade show" role="alert">
                        <i class="bi bi-info-circle"></i> 
                        <strong>Branch filtering for purchase orders not yet set up.</strong> Please run this SQL in phpMyAdmin to enable branch-specific PO data:
                        <br><br>
                        <code>ALTER TABLE purchase_orders ADD COLUMN branch_id INT NULL;</code>
                        <br>
                        <code>ALTER TABLE purchase_orders ADD FOREIGN KEY (branch_id) REFERENCES branches(branch_id);</code>
                        <br><br>
                        <button type="button" class="btn btn-sm btn-primary" onclick="copySQL('purchase_orders')">
                            <i class="bi bi-files"></i> Copy SQL
                        </button>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if (!$items_branch_column_exists): ?>
                    <div class="alert alert-info alert-dismissible fade show" role="alert">
                        <i class="bi bi-info-circle"></i> 
                        <strong>Branch filtering for items not yet set up.</strong> Please run this SQL in phpMyAdmin to enable branch-specific item data:
                        <br><br>
                        <code>ALTER TABLE items ADD COLUMN branch_id INT NULL;</code>
                        <br>
                        <code>ALTER TABLE items ADD FOREIGN KEY (branch_id) REFERENCES branches(branch_id);</code>
                        <br><br>
                        <button type="button" class="btn btn-sm btn-primary" onclick="copySQL('items')">
                            <i class="bi bi-files"></i> Copy SQL
                        </button>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if (!$supplier_id_column_exists): ?>
                    <div class="alert alert-info alert-dismissible fade show" role="alert">
                        <i class="bi bi-info-circle"></i> 
                        <strong>Supplier ID column not yet set up.</strong> To enable supplier tracking, run this SQL:
                        <br><br>
                        <code>ALTER TABLE purchase_orders ADD COLUMN supplier_id INT NULL;</code>
                        <br>
                        <code>ALTER TABLE purchase_orders ADD FOREIGN KEY (supplier_id) REFERENCES suppliers(supplier_id);</code>
                        <br><br>
                        <button type="button" class="btn btn-sm btn-primary" onclick="copySQL('supplier_id')">
                            <i class="bi bi-files"></i> Copy SQL
                        </button>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Branch Info Display -->
                <div class="alert alert-info">
                    <i class="bi bi-info-circle"></i>
                    <strong>Current Branch:</strong> <?= $branch_id ?> | 
                    <strong>Items available for this branch:</strong> <?= count($items_list) ?>
                    <?php if (count($items_list) == 0 && $items_branch_column_exists): ?>
                        <span class="badge bg-warning text-dark ms-2">No items for this branch</span>
                    <?php endif; ?>
                </div>

                <!-- No PO Warning -->
                <?php if (empty($purchase_orders) && $po_branch_column_exists && !$view_all_branches): ?>
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle"></i> 
                        No purchase orders found for your branch.
                    </div>
                <?php endif; ?>

                <!-- Stats Section -->
                <div class="stats-row">
                    <div class="stat-card total">
                        <div class="stat-icon">
                            <i class="bi bi-receipt"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-value" id="totalPO"><?= $statTotalPO ?></div>
                            <div class="stat-label">Total POs</div>
                            <?php if ($po_branch_column_exists && !$view_all_branches): ?>
                                <small class="d-block text-white-50">Your Branch</small>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="stat-card pending">
                        <div class="stat-icon">
                            <i class="bi bi-clock-history"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-value" id="processingPO"><?= $statProcessingPO ?></div>
                            <div class="stat-label">Processing</div>
                        </div>
                    </div>
                    <div class="stat-card processing">
                        <div class="stat-icon">
                            <i class="bi bi-truck"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-value" id="deliveredPO"><?= $statDeliveredPO ?></div>
                            <div class="stat-label">Delivered</div>
                        </div>
                    </div>
                    <div class="stat-card rejected">
                        <div class="stat-icon">
                            <i class="bi bi-x-circle"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-value" id="returnedPO"><?= $statReturnedPO ?></div>
                            <div class="stat-label">Cancelled</div>
                        </div>
                    </div>
                </div>

                <!-- Filter Controls -->
                <div class="filter-controls">
                    <select class="filter-select" id="filterStatus" onchange="filterTable()">
                        <option value="all">All Status</option>
                        <option value="draft">Draft</option>
                        <option value="submitted">Processing</option>
                        <option value="approved">Approved</option>
                        <option value="received">Delivered</option>
                        <option value="cancelled">Cancelled</option>
                    </select>
                    
                    <select class="filter-select" id="filterSupplier" onchange="filterTable()">
                        <option value="all">All Suppliers</option>
                        <?php foreach ($filter_suppliers as $supplier): ?>
                            <?php if (!empty($supplier['supplier_name'])): ?>
                                <option value="<?= htmlspecialchars($supplier['supplier_name']) ?>">
                                    <?= htmlspecialchars($supplier['supplier_name']) ?>
                                </option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                    
                    <select class="filter-select" id="filterMonth" onchange="filterTable()">
                        <option value="all">All Months</option>
                        <option value="1">January</option>
                        <option value="2">February</option>
                        <option value="3">March</option>
                        <option value="4">April</option>
                        <option value="5">May</option>
                        <option value="6">June</option>
                        <option value="7">July</option>
                        <option value="8">August</option>
                        <option value="9">September</option>
                        <option value="10">October</option>
                        <option value="11">November</option>
                        <option value="12">December</option>
                    </select>
                    
                    <?php if ($po_branch_column_exists && $view_all_branches): ?>
                    <select class="filter-select" id="filterBranch" onchange="filterTable()">
                        <option value="all">All Branches</option>
                        <?php
                        // Get unique branches from the data
                        $branches = array_unique(array_column($purchase_orders, 'branch_id'));
                        foreach ($branches as $bid):
                            if (!empty($bid)):
                                $branch_name = '';
                                foreach ($purchase_orders as $po) {
                                    if ($po['branch_id'] == $bid && !empty($po['branch_name'])) {
                                        $branch_name = $po['branch_name'];
                                        break;
                                    }
                                }
                        ?>
                        <option value="<?= $bid ?>"><?= htmlspecialchars($branch_name ?: 'Branch ' . $bid) ?></option>
                        <?php 
                            endif;
                        endforeach; 
                        ?>
                    </select>
                    <?php endif; ?>
                    
                    <div class="filter-search">
                        <i class="bi bi-search"></i>
                        <input type="text" id="searchInput" placeholder="Search PO number, supplier..." onkeyup="filterTable()">
                    </div>
                    
                    <div class="filter-buttons">
                        <button class="btn btn-outline-success" onclick="exportToExcel()">
                            <i class="bi bi-file-earmark-excel me-1"></i> Export
                        </button>
                        <button class="btn btn-primary" onclick="showNewPOModal()">
                            <i class="bi bi-plus-circle me-1"></i> New PO
                        </button>
                    </div>
                </div>

                <!-- Table Container -->
                <div class="table-wrapper">
                    <div class="table-container">
                        <table class="table po-table" id="poTable">
                            <thead>
                                <tr>
                                    <th class="col-po">PO NUMBER</th>
                                    <th class="col-supplier">SUPPLIER</th>
                                    <th class="col-date">ORDER DATE</th>
                                    <?php if ($po_branch_column_exists && $view_all_branches): ?>
                                        <th class="col-branch">BRANCH</th>
                                    <?php endif; ?>
                                    <th class="col-items">ITEMS</th>
                                    <th class="col-qty">QUANTITY</th>
                                    <th class="col-amount">TOTAL AMOUNT</th>
                                    <th class="col-status">STATUS</th>
                                    <th class="col-expected">EXPECTED DELIVERY</th>
                                    <th class="col-actions">ACTIONS</th>
                                </tr>
                            </thead>
                            <tbody id="poTableBody">
                                <?php if (empty($purchase_orders)): ?>
                                <tr>
                                    <td colspan="<?= ($po_branch_column_exists && $view_all_branches) ? '10' : '9' ?>" class="text-center py-5">
                                        <i class="bi bi-inbox fs-1 d-block text-muted mb-3"></i>
                                        <h5>No Purchase Orders Found</h5>
                                        <p class="text-muted mb-0">
                                            <?php if ($po_branch_column_exists && !$view_all_branches): ?>
                                                No purchase orders for your branch.
                                            <?php else: ?>
                                                No purchase orders in the database.
                                            <?php endif; ?>
                                        </p>
                                        <p class="text-muted">Click "New PO" to create your first purchase order.</p>
                                    </td>
                                </tr>
                                <?php else: ?>
                                    <?php foreach ($purchase_orders as $po): ?>
                                    <tr class="po-row" 
                                        data-id="<?= $po['po_id'] ?>"
                                        data-po-number="<?= htmlspecialchars($po['po_number']) ?>"
                                        data-supplier="<?= htmlspecialchars($po['supplier_name'] ?? '') ?>"
                                        data-status="<?= $po['po_status'] ?>"
                                        data-date="<?= $po['order_date'] ?>"
                                        data-branch="<?= $po['branch_id'] ?? '' ?>">
                                        <td class="col-po">
                                            <strong><?= htmlspecialchars($po['po_number']) ?></strong>
                                        </td>
                                        <td class="col-supplier">
                                            <?= htmlspecialchars($po['supplier_name'] ?? 'N/A') ?>
                                        </td>
                                        <td class="col-date"><?= formatDate($po['order_date']) ?></td>
                                        <?php if ($po_branch_column_exists && $view_all_branches): ?>
                                            <td class="col-branch">
                                                <span class="badge bg-info">
                                                    <?= htmlspecialchars($po['branch_name'] ?? 'Branch ' . $po['branch_id']) ?>
                                                </span>
                                            </td>
                                        <?php endif; ?>
                                        <td class="col-items"><?= number_format($po['total_items'] ?? 0) ?></td>
                                        <td class="col-qty"><?= number_format($po['total_quantity'] ?? 0) ?></td>
                                        <td class="col-amount">₱<?= number_format($po['total_amount'] ?? 0, 2) ?></td>
                                        <td class="col-status">
                                            <span class="status-badge <?= getPOStatusClass($po['po_status']) ?>">
                                                <?= getPOStatusText($po['po_status']) ?>
                                            </span>
                                        </td>
                                        <td class="col-expected"><?= formatDate($po['expected_delivery']) ?></td>
                                        <td class="col-actions">
                                            <div class="action-buttons">
                                                <button class="table-btn btn-view" onclick="viewPO(<?= $po['po_id'] ?>)" title="View">
                                                    <i class="bi bi-eye"></i>
                                                </button>
                                                <?php if ($po['po_status'] !== 'received' && $po['po_status'] !== 'cancelled'): ?>
                                                <button class="table-btn btn-edit" onclick="editPO(<?= $po['po_id'] ?>)" title="Edit">
                                                    <i class="bi bi-pencil"></i>
                                                </button>
                                                <button class="table-btn btn-delete" onclick="deletePO(<?= $po['po_id'] ?>)" title="Delete">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                        
                        <!-- Empty State (hidden if there are items) -->
                        <div class="empty-state" id="emptyState" style="display: none;">
                            <div class="empty-state-icon">
                                <i class="bi bi-inbox"></i>
                            </div>
                            <h4>No Purchase Orders Found</h4>
                            <p class="text-muted mb-4">Try adjusting your filters or create a new purchase order</p>
                            <button class="btn btn-primary" onclick="showNewPOModal()">
                                <i class="bi bi-plus-circle me-1"></i> Create New PO
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- NEW PO MODAL -->
    <div class="modal fade" id="newPOModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="bi bi-plus-circle me-2"></i>Create New Purchase Order</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="newPOForm">
                        <?php if ($po_branch_column_exists && !$view_all_branches): ?>
                            <input type="hidden" name="branch_id" value="<?= $branch_id ?>">
                            <div class="alert alert-info mb-3">
                                <i class="bi bi-info-circle me-2"></i>
                                Creating purchase order for Branch <?= $branch_id ?>
                            </div>
                        <?php endif; ?>
                        
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="supplierName" class="form-label">Supplier Name *</label>
                                <select class="form-select" id="supplierName" required style="width: 100%;">
                                    <option value="">-- Select Supplier --</option>
                                    <?php foreach ($suppliers_list as $supplier): ?>
                                        <option value="<?= htmlspecialchars($supplier['supplier_name']) ?>" 
                                                data-id="<?= $supplier['supplier_id'] ?>"
                                                data-code="<?= htmlspecialchars($supplier['supplier_code'] ?? '') ?>"
                                                data-contact="<?= htmlspecialchars($supplier['contact_person'] ?? '') ?>"
                                                data-email="<?= htmlspecialchars($supplier['email'] ?? '') ?>"
                                                data-phone="<?= htmlspecialchars($supplier['phone_number'] ?? '') ?>">
                                            <?= htmlspecialchars($supplier['supplier_name']) ?> 
                                            <?php if (!empty($supplier['supplier_code'])): ?>
                                                (<?= htmlspecialchars($supplier['supplier_code']) ?>)
                                            <?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="hidden" id="supplierId" name="supplier_id">
                            </div>
                            <div class="col-md-6">
                                <label for="poStatus" class="form-label">Status *</label>
                                <select class="form-select" id="poStatus" required>
                                    <option value="draft">Draft</option>
                                    <option value="submitted">Submit for Approval</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="orderDate" class="form-label">Order Date *</label>
                                <input type="date" class="form-control" id="orderDate" required>
                            </div>
                            <div class="col-md-6">
                                <label for="expectedDelivery" class="form-label">Expected Delivery</label>
                                <input type="date" class="form-control" id="expectedDelivery">
                            </div>
                        </div>
                        
                        <!-- Supplier Info Display (optional) -->
                        <div id="supplierInfo" class="alert alert-info mt-3" style="display: none;">
                            <i class="bi bi-info-circle me-2"></i>
                            <span id="supplierInfoText"></span>
                        </div>
                        
                        <!-- Items Section -->
                        <div class="po-items-section mt-4">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h5 class="mb-0">
                                    <i class="bi bi-box-seam me-2"></i>Order Items
                                    <span class="item-count-badge" id="itemCount">0</span>
                                </h5>
                                <button type="button" class="btn btn-sm btn-success" onclick="addItemRow()">
                                    <i class="bi bi-plus-circle"></i> Add Item
                                </button>
                            </div>
                            
                            <div id="itemsContainer">
                                <!-- Item rows will be added here dynamically -->
                            </div>
                            
                            <div class="row mt-3">
                                <div class="col-md-6 offset-md-6">
                                    <div class="card bg-light">
                                        <div class="card-body">
                                            <div class="d-flex justify-content-between">
                                                <span class="fw-bold">Total Amount:</span>
                                                <span class="fw-bold fs-5" id="totalAmountDisplay">₱0.00</span>
                                            </div>
                                            <div class="d-flex justify-content-between mt-2 text-muted">
                                                <span>Total Items:</span>
                                                <span id="totalItemsDisplay">0</span>
                                            </div>
                                            <div class="d-flex justify-content-between text-muted">
                                                <span>Total Quantity:</span>
                                                <span id="totalQuantityDisplay">0</span>
                                            </div>
                                            <input type="hidden" id="totalAmount" name="total_amount" value="0">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Warning if no items available for this branch -->
                        <?php if (empty($items_list)): ?>
                            <div class="alert alert-warning mt-3">
                                <i class="bi bi-exclamation-triangle me-2"></i>
                                <strong>No items found for your branch!</strong> 
                                Please contact administrator to add items to branch <?= $branch_id ?>.
                            </div>
                        <?php endif; ?>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="createPurchaseOrder()">Create PO</button>
                </div>
            </div>
        </div>
    </div>

    <!-- VIEW PO MODAL -->
    <div class="modal fade" id="viewPOModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title"><i class="bi bi-eye me-2"></i>Purchase Order Details</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="poDetailsContent">
                    <!-- Content will be populated by JavaScript -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-warning" id="editFromViewBtn" onclick="editPOFromView()" style="display: none;">Edit PO</button>
                    <button type="button" class="btn btn-primary" onclick="printPODetails()">
                        <i class="bi bi-printer me-1"></i> Print
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- EDIT PO MODAL -->
    <div class="modal fade" id="editPOModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>Edit Purchase Order</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="editPOForm">
                        <input type="hidden" id="editPOId">
                        <?php if ($po_branch_column_exists && !$view_all_branches): ?>
                            <input type="hidden" name="branch_id" value="<?= $branch_id ?>">
                        <?php endif; ?>
                        
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="editPONumber" class="form-label">PO Number</label>
                                <input type="text" class="form-control" id="editPONumber" readonly>
                            </div>
                            <div class="col-md-6">
                                <label for="editPOStatus" class="form-label">Status *</label>
                                <select class="form-select" id="editPOStatus" required>
                                    <option value="draft">Draft</option>
                                    <option value="submitted">Processing</option>
                                    <option value="approved">Approved</option>
                                    <option value="received">Delivered</option>
                                    <option value="cancelled">Cancelled</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="editSupplierName" class="form-label">Supplier Name *</label>
                                <select class="form-select" id="editSupplierName" required style="width: 100%;">
                                    <option value="">-- Select Supplier --</option>
                                    <?php foreach ($suppliers_list as $supplier): ?>
                                        <option value="<?= htmlspecialchars($supplier['supplier_name']) ?>" 
                                                data-id="<?= $supplier['supplier_id'] ?>">
                                            <?= htmlspecialchars($supplier['supplier_name']) ?>
                                            <?php if (!empty($supplier['supplier_code'])): ?>
                                                (<?= htmlspecialchars($supplier['supplier_code']) ?>)
                                            <?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="hidden" id="editSupplierId" name="supplier_id">
                            </div>
                            <div class="col-md-6">
                                <label for="editOrderDate" class="form-label">Order Date *</label>
                                <input type="date" class="form-control" id="editOrderDate" required>
                            </div>
                            <div class="col-md-6">
                                <label for="editExpectedDelivery" class="form-label">Expected Delivery</label>
                                <input type="date" class="form-control" id="editExpectedDelivery">
                            </div>
                            <div class="col-md-6">
                                <label for="editTotalAmount" class="form-label">Total Amount (₱) *</label>
                                <input type="number" class="form-control" id="editTotalAmount" min="0" step="0.01" required>
                            </div>
                        </div>
                        
                        <?php if ($po_branch_column_exists && $view_all_branches): ?>
                        <div class="row g-3 mt-2">
                            <div class="col-md-6">
                                <label for="editBranch" class="form-label">Branch</label>
                                <input type="text" class="form-control" id="editBranch" readonly>
                            </div>
                        </div>
                        <?php endif; ?>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="updatePurchaseOrder()">Update PO</button>
                </div>
            </div>
        </div>
    </div>

    <!-- ADD ITEM MODAL -->
    <div class="modal fade" id="addItemModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title"><i class="bi bi-plus-circle me-2"></i>Add Item to PO</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="addItemForm">
                        <input type="hidden" id="addItemPOId">
                        
                        <div class="mb-3">
                            <label for="itemSelect" class="form-label">Select Item *</label>
                            <select class="form-select" id="itemSelect" required>
                                <option value="">-- Select Item --</option>
                                <?php foreach ($items_list as $item): ?>
                                <option value="<?= $item['item_id'] ?>" 
                                        data-price-piece="<?= $item['price_piece'] ?>"
                                        data-price-case="<?= $item['price_case'] ?? 0 ?>"
                                        data-price-inner="<?= $item['price_inner_pack'] ?? 0 ?>"
                                        data-price-box="<?= $item['price_box'] ?? 0 ?>"
                                        data-price-carton="<?= $item['price_carton'] ?? 0 ?>"
                                        data-code="<?= htmlspecialchars($item['item_code']) ?>"
                                        data-stock="<?= $item['stock'] ?>">
                                    <?= htmlspecialchars($item['item_code'] . ' - ' . $item['item_name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label for="itemQuantity" class="form-label">Quantity *</label>
                            <input type="number" class="form-control" id="itemQuantity" min="1" value="1" required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="itemUnitPrice" class="form-label">Unit Price (₱) *</label>
                            <input type="number" class="form-control" id="itemUnitPrice" min="0" step="0.01" required>
                        </div>
                        
                        <div class="alert alert-info" id="itemSubtotal" style="display: none;">
                            Subtotal: ₱<span id="subtotalAmount">0.00</span>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-success" onclick="addItemToPO()">Add Item</button>
                </div>
            </div>
        </div>
    </div>

    <!-- DELETE CONFIRMATION MODAL -->
    <div class="modal fade" id="deletePOModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title"><i class="bi bi-trash me-2"></i>Confirm Delete</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to delete this purchase order?</p>
                    <p class="fw-bold" id="deletePONumber"></p>
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        This action cannot be undone and will remove all associated items.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-danger" onclick="confirmDeletePO()">Delete PO</button>
                </div>
            </div>
        </div>
    </div>

    <!-- DELETE ITEM CONFIRMATION MODAL -->
    <div class="modal fade" id="deleteItemModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title"><i class="bi bi-trash me-2"></i>Confirm Delete Item</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to remove this item from the purchase order?</p>
                    <p class="fw-bold" id="deleteItemName"></p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-danger" onclick="confirmDeleteItem()">Delete Item</button>
                </div>
            </div>
        </div>
    </div>

    <!-- jQuery and Select2 JS -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
    // ========== GLOBAL VARIABLES ==========
    let currentPOId = null;
    let currentItemId = null;
    let currentPOData = null;
    let itemCounter = 0;
    let itemsList = <?= json_encode($items_list) ?>;
    let suppliersList = <?= json_encode($suppliers_list) ?>;
    const branchId = <?php echo $branch_id; ?>;
    const viewAllBranches = <?php echo $view_all_branches ? 'true' : 'false'; ?>;
    const poBranchColumnExists = <?php echo $po_branch_column_exists ? 'true' : 'false'; ?>;
    const itemsBranchColumnExists = <?php echo $items_branch_column_exists ? 'true' : 'false'; ?>;
    const supplierIdColumnExists = <?php echo $supplier_id_column_exists ? 'true' : 'false'; ?>;
    
    // Debug: Log items loaded
    console.log('Current Branch ID:', branchId);
    console.log('Items loaded for this branch:', itemsList.length);
    console.log('Items list:', itemsList);
    
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

    // ========== SHOW LOADING ==========
    function showLoading() {
        Swal.fire({
            title: 'Processing...',
            text: 'Please wait',
            allowOutsideClick: false,
            didOpen: () => {
                Swal.showLoading();
            }
        });
    }

    // ========== SUPPLIER SELECT FUNCTIONS ==========
    function initializeSupplierSelect() {
        // Initialize Select2 for supplier dropdown
        if ($('#supplierName').length) {
            $('#supplierName').select2({
                dropdownParent: $('#newPOModal'),
                width: '100%',
                placeholder: '-- Search and Select Supplier --',
                allowClear: true,
                templateResult: formatSupplierOption,
                templateSelection: formatSupplierSelection
            });
        }
        
        if ($('#editSupplierName').length) {
            $('#editSupplierName').select2({
                dropdownParent: $('#editPOModal'),
                width: '100%',
                placeholder: '-- Search and Select Supplier --',
                allowClear: true,
                templateResult: formatSupplierOption,
                templateSelection: formatSupplierSelection
            });
        }
        
        // Supplier change handler for new PO
        $('#supplierName').on('change', function() {
            const selected = $(this).find('option:selected');
            const supplierId = selected.data('id');
            const supplierCode = selected.data('code');
            const contactPerson = selected.data('contact');
            const email = selected.data('email');
            const phone = selected.data('phone');
            
            $('#supplierId').val(supplierId || '');
            
            if (selected.val()) {
                let infoText = '';
                if (supplierCode) infoText += `Code: ${supplierCode}`;
                if (contactPerson) infoText += (infoText ? ' | ' : '') + `Contact: ${contactPerson}`;
                if (email) infoText += (infoText ? ' | ' : '') + `Email: ${email}`;
                if (phone) infoText += (infoText ? ' | ' : '') + `Phone: ${phone}`;
                
                if (infoText) {
                    $('#supplierInfoText').text(infoText);
                    $('#supplierInfo').show();
                } else {
                    $('#supplierInfo').hide();
                }
            } else {
                $('#supplierInfo').hide();
                $('#supplierId').val('');
            }
        });
        
        // Supplier change handler for edit PO
        $('#editSupplierName').on('change', function() {
            const selected = $(this).find('option:selected');
            const supplierId = selected.data('id');
            $('#editSupplierId').val(supplierId || '');
        });
    }
    
    function formatSupplierOption(supplier) {
        if (!supplier.id) return supplier.text;
        
        const element = $(supplier.element);
        const supplierCode = element.data('code');
        const contactPerson = element.data('contact');
        
        let displayText = supplier.text;
        let subText = [];
        
        if (supplierCode) subText.push(`Code: ${supplierCode}`);
        if (contactPerson) subText.push(`Contact: ${contactPerson}`);
        
        if (subText.length > 0) {
            return $('<div><strong>' + displayText + '</strong><br><small class="text-muted">' + subText.join(' | ') + '</small></div>');
        }
        
        return $('<div>' + displayText + '</div>');
    }

    function formatSupplierSelection(supplier) {
        return supplier.text.split(' (')[0] || supplier.text;
    }

    // ========== ITEM MANAGEMENT FUNCTIONS ==========
    function addItemRow() {
        if (itemsList.length === 0) {
            Swal.fire('Warning', 'No items available for your branch. Please contact administrator.', 'warning');
            return;
        }
        
        itemCounter++;
        const container = document.getElementById('itemsContainer');
        const itemId = `item_${itemCounter}`;
        
        let options = '<option value="">-- Select Item --</option>';
        itemsList.forEach(item => {
            options += `<option value="${item.item_id}" 
                data-price-piece="${item.price_piece}"
                data-price-case="${item.price_case || 0}"
                data-price-inner="${item.price_inner_pack || 0}"
                data-price-box="${item.price_box || 0}"
                data-price-carton="${item.price_carton || 0}"
                data-code="${item.item_code}" 
                data-name="${item.item_name}">
                ${item.item_code} - ${item.item_name}
            </option>`;
        });
        
        const itemRow = document.createElement('div');
        itemRow.className = 'po-item-row';
        itemRow.id = itemId;
        itemRow.innerHTML = `
            <div class="item-number">#${itemCounter}</div>
            <select class="form-select item-select" onchange="updateItemDetails(this)" required>
                ${options}
            </select>
            <select class="form-select unit-select" onchange="updateUnitPrice(this)" style="flex: 1.5;">
                <option value="piece">Piece (pc)</option>
                <option value="case">Case (cs)</option>
                <option value="inner-pack">Inner Pack (ip)</option>
                <option value="box">Box (bx)</option>
                <option value="carton">Carton (ctn)</option>
            </select>
            <div class="quantity-container">
                <button type="button" class="btn btn-sm btn-outline-secondary quantity-btn" onclick="decrementQuantity(this)">-</button>
                <input type="number" class="form-control item-quantity" min="1" value="1" onchange="updateItemSubtotal(this)" required>
                <button type="button" class="btn btn-sm btn-outline-secondary quantity-btn" onclick="incrementQuantity(this)">+</button>
            </div>
            <div class="unit-price-display">
                ₱<span class="price-value">0.00</span>
            </div>
            <div class="item-subtotal">
                ₱0.00
            </div>
            <button type="button" class="btn btn-sm btn-outline-danger remove-item-btn" onclick="removeItemRow(this)">
                <i class="bi bi-trash"></i>
            </button>
        `;
        
        container.appendChild(itemRow);
        
        // Initialize Select2 for this row
        $(`#${itemId} .item-select`).select2({
            dropdownParent: $('#newPOModal'),
            width: '100%',
            templateResult: formatItemOption,
            templateSelection: formatItemSelection
        });
        
        updateItemCount();
    }
    
    // Format item options in Select2
    function formatItemOption(item) {
        if (!item.id) return item.text;
        
        const element = $(item.element);
        const itemName = element.text();
        const prices = [];
        
        const pricePiece = parseFloat(element.data('price-piece')) || 0;
        const priceCase = parseFloat(element.data('price-case')) || 0;
        const priceInner = parseFloat(element.data('price-inner')) || 0;
        const priceBox = parseFloat(element.data('price-box')) || 0;
        const priceCarton = parseFloat(element.data('price-carton')) || 0;
        
        if (pricePiece > 0) prices.push(`Pc: ₱${pricePiece.toFixed(2)}`);
        if (priceCase > 0) prices.push(`Cs: ₱${priceCase.toFixed(2)}`);
        if (priceInner > 0) prices.push(`Ip: ₱${priceInner.toFixed(2)}`);
        if (priceBox > 0) prices.push(`Bx: ₱${priceBox.toFixed(2)}`);
        if (priceCarton > 0) prices.push(`Ctn: ₱${priceCarton.toFixed(2)}`);
        
        return $('<div><strong>' + itemName + '</strong><br><small class="text-muted">' + prices.join(' | ') + '</small></div>');
    }

    function formatItemSelection(item) {
        return item.text.split(' - ')[1] || item.text;
    }

    function updateItemDetails(select) {
        const row = select.closest('.po-item-row');
        const selected = select.options[select.selectedIndex];
        
        // Store all price data in the row's dataset
        row.dataset.pricePiece = selected.dataset.pricePiece || 0;
        row.dataset.priceCase = selected.dataset.priceCase || 0;
        row.dataset.priceInner = selected.dataset.priceInner || 0;
        row.dataset.priceBox = selected.dataset.priceBox || 0;
        row.dataset.priceCarton = selected.dataset.priceCarton || 0;
        
        // Update unit price based on selected unit
        updateUnitPrice(row.querySelector('.unit-select'));
    }

    function updateUnitPrice(select) {
        const row = select.closest('.po-item-row');
        const unit = select.value;
        let price = 0;
        
        // Get price based on selected unit
        switch(unit) {
            case 'piece':
                price = parseFloat(row.dataset.pricePiece) || 0;
                break;
            case 'case':
                price = parseFloat(row.dataset.priceCase) || 0;
                break;
            case 'inner-pack':
                price = parseFloat(row.dataset.priceInner) || 0;
                break;
            case 'box':
                price = parseFloat(row.dataset.priceBox) || 0;
                break;
            case 'carton':
                price = parseFloat(row.dataset.priceCarton) || 0;
                break;
        }
        
        // Store selected unit and price for later use
        row.dataset.selectedUnit = unit;
        row.dataset.unitPrice = price;
        
        // Display the unit price
        const priceDisplay = row.querySelector('.price-value');
        if (priceDisplay) {
            priceDisplay.textContent = price.toFixed(2);
        }
        
        updateItemSubtotal(row.querySelector('.item-quantity'));
    }
    
    // Increment quantity
    function incrementQuantity(button) {
        const container = button.closest('.quantity-container');
        const input = container.querySelector('.item-quantity');
        const currentValue = parseInt(input.value) || 1;
        input.value = currentValue + 1;
        updateItemSubtotal(input);
    }

    // Decrement quantity
    function decrementQuantity(button) {
        const container = button.closest('.quantity-container');
        const input = container.querySelector('.item-quantity');
        const currentValue = parseInt(input.value) || 1;
        if (currentValue > 1) {
            input.value = currentValue - 1;
            updateItemSubtotal(input);
        }
    }
    
    function updateItemSubtotal(element) {
        const row = element.closest('.po-item-row');
        const quantity = parseFloat(row.querySelector('.item-quantity').value) || 0;
        const unitPrice = parseFloat(row.dataset.unitPrice) || 0;
        const subtotal = quantity * unitPrice;
        
        row.querySelector('.item-subtotal').textContent = `₱${subtotal.toFixed(2)}`;
        
        updateTotalAmount();
    }
    
    function removeItemRow(button) {
        const row = button.closest('.po-item-row');
        
        // Destroy Select2 instance before removing
        const select = row.querySelector('.item-select');
        if (select) {
            $(select).select2('destroy');
        }
        
        row.remove();
        updateTotalAmount();
        updateItemCount();
        
        // Renumber remaining items
        const rows = document.querySelectorAll('.po-item-row');
        rows.forEach((row, index) => {
            const numberDiv = row.querySelector('.item-number');
            if (numberDiv) {
                numberDiv.textContent = `#${index + 1}`;
            }
        });
    }
    
    function updateItemCount() {
        const rows = document.querySelectorAll('.po-item-row');
        const count = rows.length;
        document.getElementById('itemCount').textContent = count;
    }
    
    function updateTotalAmount() {
        const rows = document.querySelectorAll('.po-item-row');
        let total = 0;
        let totalQty = 0;
        let validItems = 0;
        
        rows.forEach(row => {
            const select = row.querySelector('.item-select');
            if (select && select.value) {
                validItems++;
                const quantity = parseFloat(row.querySelector('.item-quantity').value) || 0;
                const unitPrice = parseFloat(row.dataset.unitPrice) || 0;
                total += quantity * unitPrice;
                totalQty += quantity;
            }
        });
        
        document.getElementById('totalAmountDisplay').textContent = `₱${total.toFixed(2)}`;
        document.getElementById('totalAmount').value = total.toFixed(2);
        document.getElementById('totalItemsDisplay').textContent = validItems;
        document.getElementById('totalQuantityDisplay').textContent = totalQty;
    }
    
    function getItemsData() {
        const rows = document.querySelectorAll('.po-item-row');
        const items = [];
        
        rows.forEach(row => {
            const select = row.querySelector('.item-select');
            if (select && select.value) {
                const quantity = parseInt(row.querySelector('.item-quantity').value) || 0;
                const unitPrice = parseFloat(row.dataset.unitPrice) || 0;
                
                items.push({
                    item_id: select.value,
                    quantity: quantity,
                    unit_price: unitPrice
                });
            }
        });
        
        return items;
    }

    // ========== PURCHASE ORDER FUNCTIONS ==========
    document.addEventListener('DOMContentLoaded', function() {
        console.log("Purchase Orders - Live Database Mode with Supplier Search");
        console.log("Branch ID:", branchId);
        console.log("View All Branches:", viewAllBranches);
        console.log("PO Branch Column Exists:", poBranchColumnExists);
        console.log("Items Branch Column Exists:", itemsBranchColumnExists);
        console.log("Supplier ID Column Exists:", supplierIdColumnExists);
        console.log("Suppliers Loaded:", suppliersList.length);
        console.log("Items Loaded for Branch", branchId + ":", itemsList.length);
        
        initializeSidebar();
        
        // Set default date for new PO modal
        const today = new Date();
        const formattedDate = today.toISOString().slice(0, 10);
        document.getElementById('orderDate').value = formattedDate;
        
        // Initialize supplier selects
        initializeSupplierSelect();
        
        // Initialize Select2 for item dropdown if it exists
        if (document.getElementById('itemSelect')) {
            $('#itemSelect').select2({
                dropdownParent: $('#addItemModal'),
                width: '100%'
            });
        }
        
        // Item selection change handler
        $('#itemSelect').on('change', function() {
            const selected = $(this).find('option:selected');
            const pricePiece = selected.data('price-piece');
            if (pricePiece) {
                document.getElementById('itemUnitPrice').value = pricePiece;
                calculateSubtotal();
            }
        });
        
        // Quantity change handler
        document.getElementById('itemQuantity')?.addEventListener('input', calculateSubtotal);
        document.getElementById('itemUnitPrice')?.addEventListener('input', calculateSubtotal);
        
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

        window.addEventListener('resize', function() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.querySelector('.sidebar-overlay');
            
            if (window.innerWidth > 992) {
                if (overlay) overlay.remove();
                sidebar.classList.remove('active');
                const savedCollapsed = localStorage.getItem('sidebarCollapsed');
                if (savedCollapsed === 'true') {
                    sidebar.classList.add('collapsed');
                    document.querySelectorAll('.nav-text').forEach(text => text.style.display = 'none');
                } else {
                    sidebar.classList.remove('collapsed');
                    document.querySelectorAll('.nav-text').forEach(text => text.style.display = 'inline-block');
                }
            } else {
                sidebar.classList.remove('collapsed');
                document.querySelectorAll('.nav-text').forEach(text => text.style.display = 'inline-block');
            }
        });
    });

    // Calculate subtotal
    function calculateSubtotal() {
        const quantity = parseFloat(document.getElementById('itemQuantity')?.value) || 0;
        const price = parseFloat(document.getElementById('itemUnitPrice')?.value) || 0;
        const subtotal = quantity * price;
        document.getElementById('subtotalAmount').textContent = subtotal.toFixed(2);
        document.getElementById('itemSubtotal').style.display = 'block';
    }

    // Filter table function
    function filterTable() {
        const statusFilter = document.getElementById('filterStatus').value;
        const supplierFilter = document.getElementById('filterSupplier').value;
        const monthFilter = document.getElementById('filterMonth').value;
        const branchFilter = document.getElementById('filterBranch')?.value || 'all';
        const searchTerm = document.getElementById('searchInput').value.toLowerCase();
        
        const rows = document.querySelectorAll('.po-row');
        let visibleCount = 0;
        
        rows.forEach(row => {
            const poNumber = row.dataset.poNumber?.toLowerCase() || '';
            const supplier = row.dataset.supplier?.toLowerCase() || '';
            const status = row.dataset.status || '';
            const dateStr = row.dataset.date || '';
            const rowBranch = row.dataset.branch || '';
            
            let matchesStatus = statusFilter === 'all' || status === statusFilter;
            let matchesSupplier = supplierFilter === 'all' || row.dataset.supplier === supplierFilter;
            
            let matchesMonth = true;
            if (monthFilter !== 'all' && dateStr) {
                const poMonth = new Date(dateStr).getMonth() + 1;
                matchesMonth = poMonth === parseInt(monthFilter);
            }
            
            // Branch filter (only when viewing all branches)
            let matchesBranch = true;
            if (poBranchColumnExists && viewAllBranches && branchFilter !== 'all') {
                matchesBranch = rowBranch === branchFilter;
            }
            
            let matchesSearch = searchTerm === '' || 
                poNumber.includes(searchTerm) || 
                supplier.includes(searchTerm);
            
            if (matchesStatus && matchesSupplier && matchesMonth && matchesBranch && matchesSearch) {
                row.style.display = '';
                visibleCount++;
            } else {
                row.style.display = 'none';
            }
        });
        
        // Show empty state if no rows visible
        const emptyState = document.getElementById('emptyState');
        const tableBody = document.getElementById('poTableBody');
        if (visibleCount === 0) {
            if (emptyState) emptyState.style.display = 'block';
            if (tableBody) tableBody.style.display = 'none';
        } else {
            if (emptyState) emptyState.style.display = 'none';
            if (tableBody) tableBody.style.display = 'table-row-group';
        }
    }

    // Show new PO modal
    function showNewPOModal() {
        // Check if there are suppliers available
        <?php if (empty($suppliers_list)): ?>
        Swal.fire({
            title: 'No Suppliers Available',
            text: 'There are no active suppliers. Please add suppliers first.',
            icon: 'warning',
            confirmButtonText: 'OK'
        }).then(() => {
            window.location.href = 'supplier.php';
        });
        return;
        <?php endif; ?>
        
        // Check if there are items available for this branch
        <?php if (empty($items_list)): ?>
        Swal.fire({
            title: 'No Items Available',
            text: 'There are no active items for your branch. Please contact administrator.',
            icon: 'warning',
            confirmButtonText: 'OK'
        });
        return;
        <?php endif; ?>
        
        // Reset form
        document.getElementById('newPOForm').reset();
        $('#supplierName').val(null).trigger('change');
        $('#supplierInfo').hide();
        
        // Clear items container and destroy any Select2 instances
        const itemsContainer = document.getElementById('itemsContainer');
        const oldSelects = itemsContainer.querySelectorAll('.item-select');
        oldSelects.forEach(select => {
            if (select) {
                $(select).select2('destroy');
            }
        });
        itemsContainer.innerHTML = '';
        itemCounter = 0;
        
        // Set default date
        const today = new Date();
        const formattedDate = today.toISOString().slice(0, 10);
        document.getElementById('orderDate').value = formattedDate;
        
        // Add at least one item row
        addItemRow();
        
        updateItemCount();
        updateTotalAmount();
        
        new bootstrap.Modal(document.getElementById('newPOModal')).show();
    }

    // Create Purchase Order
    function createPurchaseOrder() {
        const supplierName = document.getElementById('supplierName').value;
        const supplierId = document.getElementById('supplierId').value;
        const orderDate = document.getElementById('orderDate').value;
        const expectedDelivery = document.getElementById('expectedDelivery').value;
        const items = getItemsData();
        
        if (!supplierName) {
            Swal.fire('Warning', 'Please select a Supplier', 'warning');
            return;
        }
        
        if (!orderDate) {
            Swal.fire('Warning', 'Order Date is required', 'warning');
            return;
        }
        
        if (items.length === 0) {
            Swal.fire('Warning', 'At least one item is required', 'warning');
            return;
        }
        
        // Calculate total from items
        let totalAmount = 0;
        items.forEach(item => {
            totalAmount += item.quantity * item.unit_price;
        });
        
        showLoading();
        
        const formData = new FormData();
        formData.append('action', 'create_po');
        formData.append('supplier_name', supplierName);
        if (supplierId) {
            formData.append('supplier_id', supplierId);
        }
        formData.append('order_date', orderDate);
        formData.append('expected_delivery', expectedDelivery);
        formData.append('total_amount', totalAmount.toFixed(2));
        formData.append('po_status', document.getElementById('poStatus').value);
        formData.append('items', JSON.stringify(items));
        
        fetch('purchase_order.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            Swal.close();
            
            if (data.success) {
                Swal.fire({
                    icon: 'success',
                    title: 'Success!',
                    text: data.message,
                    timer: 2000,
                    showConfirmButton: false
                }).then(() => {
                    bootstrap.Modal.getInstance(document.getElementById('newPOModal')).hide();
                    location.reload();
                });
            } else {
                Swal.fire('Error', data.message, 'error');
            }
        })
        .catch(error => {
            Swal.close();
            console.error('Error:', error);
            Swal.fire('Error', 'An error occurred while creating purchase order', 'error');
        });
    }

    // View Purchase Order
    function viewPO(id) {
        showLoading();
        
        const formData = new FormData();
        formData.append('action', 'get_po');
        formData.append('po_id', id);
        
        fetch('purchase_order.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            Swal.close();
            
            if (data.success) {
                const po = data.po;
                const items = data.items || [];
                currentPOData = po;
                currentPOId = id;
                
                // Format dates
                const orderDate = po.order_date ? new Date(po.order_date).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' }) : 'N/A';
                const expectedDate = po.expected_delivery ? new Date(po.expected_delivery).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' }) : 'N/A';
                const createdDate = po.created_at ? new Date(po.created_at).toLocaleString() : 'N/A';
                const updatedDate = po.updated_at ? new Date(po.updated_at).toLocaleString() : 'N/A';
                
                let branchHtml = '';
                if (po.branch_name) {
                    branchHtml = `
                        <tr>
                            <td class="detail-label">Branch:</td>
                            <td><span class="badge bg-info">${po.branch_name}</span></td>
                        </tr>
                    `;
                }
                
                // Build items table
                let itemsHtml = '';
                if (items.length > 0) {
                    itemsHtml = `
                        <div class="d-flex justify-content-between align-items-center mt-4 mb-3">
                            <h6 class="fw-bold mb-0"><i class="bi bi-box-seam"></i> Order Items <span class="item-count-badge">${items.length}</span></h6>
                            ${po.po_status !== 'received' && po.po_status !== 'cancelled' ? 
                                '<button class="btn btn-sm btn-success" onclick="showAddItemModal(' + po.po_id + ')"><i class="bi bi-plus-circle"></i> Add Item</button>' : ''}
                        </div>
                        <div class="table-responsive">
                            <table class="table table-sm table-bordered items-table">
                                <thead class="table-light">
                                    <tr>
                                        <th>#</th>
                                        <th>Item Code</th>
                                        <th>Item Name</th>
                                        <th class="text-center">Quantity</th>
                                        <th class="text-end">Unit Price</th>
                                        <th class="text-end">Subtotal</th>
                                        ${po.po_status !== 'received' && po.po_status !== 'cancelled' ? '<th class="text-center">Action</th>' : ''}
                                    </tr>
                                </thead>
                                <tbody>
                    `;
                    
                    let totalQty = 0;
                    let totalAmount = 0;
                    
                    items.forEach((item, index) => {
                        const subtotal = item.quantity_ordered * item.unit_price;
                        totalQty += item.quantity_ordered;
                        totalAmount += subtotal;
                        
                        itemsHtml += `<tr>
                            <td>${index + 1}</td>
                            <td>${item.item_code || 'N/A'}</td>
                            <td>${item.item_name || 'N/A'}</td>
                            <td class="text-center">${item.quantity_ordered}</td>
                            <td class="text-end">₱${Number(item.unit_price).toFixed(2)}</td>
                            <td class="text-end">₱${Number(subtotal).toFixed(2)}</td>
                            ${po.po_status !== 'received' && po.po_status !== 'cancelled' ? 
                                `<td class="text-center">
                                    <button class="btn btn-sm btn-outline-danger" onclick="deleteItem(${item.po_item_id}, ${po.po_id}, '${item.item_name}')">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </td>` : ''}
                        </tr>`;
                    });
                    
                    itemsHtml += `
                                </tbody>
                                <tfoot class="table-secondary">
                                    <tr>
                                        <th colspan="3" class="text-end">Totals:</th>
                                        <th class="text-center">${totalQty}</th>
                                        <th></th>
                                        <th class="text-end">₱${Number(totalAmount).toFixed(2)}</th>
                                        ${po.po_status !== 'received' && po.po_status !== 'cancelled' ? '<th></th>' : ''}
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    `;
                } else {
                    itemsHtml = `
                        <div class="d-flex justify-content-between align-items-center mt-4 mb-3">
                            <h6 class="fw-bold mb-0"><i class="bi bi-box-seam"></i> Order Items</h6>
                            ${po.po_status !== 'received' && po.po_status !== 'cancelled' ? 
                                '<button class="btn btn-sm btn-success" onclick="showAddItemModal(' + po.po_id + ')"><i class="bi bi-plus-circle"></i> Add Item</button>' : ''}
                        </div>
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle me-2"></i>
                            No items added to this purchase order yet.
                        </div>
                    `;
                }
                
                const content = document.getElementById('poDetailsContent');
                content.innerHTML = `
                    <div class="row">
                        <div class="col-md-6">
                            <div class="po-details-card">
                                <h6 class="fw-bold mb-3"><i class="bi bi-receipt"></i> Purchase Order Information</h6>
                                <table class="table table-sm table-borderless">
                                    <tr>
                                        <td width="40%" class="detail-label">PO Number:</td>
                                        <td class="detail-value">${po.po_number}</td>
                                    </tr>
                                    ${branchHtml}
                                    <tr>
                                        <td class="detail-label">Supplier:</td>
                                        <td>${po.supplier_name || 'N/A'}</td>
                                    </tr>
                                    <tr>
                                        <td class="detail-label">Order Date:</td>
                                        <td>${orderDate}</td>
                                    </tr>
                                    <tr>
                                        <td class="detail-label">Expected Delivery:</td>
                                        <td>${expectedDate}</td>
                                    </tr>
                                    <tr>
                                        <td class="detail-label">Status:</td>
                                        <td><span class="status-badge ${getStatusClass(po.po_status)}">${getStatusText(po.po_status)}</span></td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="po-details-card">
                                <h6 class="fw-bold mb-3"><i class="bi bi-bar-chart"></i> Order Summary</h6>
                                <table class="table table-sm table-borderless">
                                    <tr>
                                        <td width="40%" class="detail-label">Total Items:</td>
                                        <td>${po.total_items || 0}</td>
                                    </tr>
                                    <tr>
                                        <td class="detail-label">Total Quantity:</td>
                                        <td>${po.total_quantity || 0}</td>
                                    </tr>
                                    <tr>
                                        <td class="detail-label">Total Amount:</td>
                                        <td class="fw-bold fs-5">₱${Number(po.total_amount || 0).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}</td>
                                    </tr>
                                    <tr>
                                        <td class="detail-label">Created By:</td>
                                        <td>${po.created_by_name || 'N/A'}</td>
                                    </tr>
                                    <tr>
                                        <td class="detail-label">Created At:</td>
                                        <td>${createdDate}</td>
                                    </tr>
                                    <tr>
                                        <td class="detail-label">Last Updated:</td>
                                        <td>${updatedDate}</td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                    </div>
                    ${itemsHtml}
                `;
                
                // Show/hide edit button based on status
                const editBtn = document.getElementById('editFromViewBtn');
                if (po.po_status !== 'received' && po.po_status !== 'cancelled') {
                    editBtn.style.display = 'inline-block';
                } else {
                    editBtn.style.display = 'none';
                }
                
                new bootstrap.Modal(document.getElementById('viewPOModal')).show();
            } else {
                Swal.fire('Error', data.message, 'error');
            }
        })
        .catch(error => {
            Swal.close();
            console.error('Error:', error);
            Swal.fire('Error', 'An error occurred while fetching purchase order details', 'error');
        });
    }

    // Edit from View Modal
    function editPOFromView() {
        bootstrap.Modal.getInstance(document.getElementById('viewPOModal')).hide();
        setTimeout(() => {
            editPO(currentPOId);
        }, 300);
    }

    // Edit Purchase Order
    function editPO(id) {
        showLoading();
        
        const formData = new FormData();
        formData.append('action', 'get_po');
        formData.append('po_id', id);
        
        fetch('purchase_order.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            Swal.close();
            
            if (data.success) {
                const po = data.po;
                
                // Format dates for input
                const orderDate = po.order_date ? po.order_date.split(' ')[0] : '';
                const expectedDate = po.expected_delivery ? po.expected_delivery.split(' ')[0] : '';
                
                document.getElementById('editPOId').value = po.po_id;
                document.getElementById('editPONumber').value = po.po_number;
                
                // Set supplier dropdown
                $('#editSupplierName').val(po.supplier_name).trigger('change');
                $('#editSupplierId').val(po.supplier_id || '');
                
                document.getElementById('editOrderDate').value = orderDate;
                document.getElementById('editExpectedDelivery').value = expectedDate;
                document.getElementById('editTotalAmount').value = po.total_amount || 0;
                document.getElementById('editPOStatus').value = po.po_status;
                
                if (poBranchColumnExists && viewAllBranches) {
                    document.getElementById('editBranch').value = po.branch_name || `Branch ${po.branch_id}`;
                }
                
                currentPOId = id;
                new bootstrap.Modal(document.getElementById('editPOModal')).show();
            } else {
                Swal.fire('Error', data.message, 'error');
            }
        })
        .catch(error => {
            Swal.close();
            console.error('Error:', error);
            Swal.fire('Error', 'An error occurred while fetching purchase order details', 'error');
        });
    }

    // Update Purchase Order
    function updatePurchaseOrder() {
        const poId = document.getElementById('editPOId').value;
        const supplierName = document.getElementById('editSupplierName').value;
        const supplierId = document.getElementById('editSupplierId').value;
        const orderDate = document.getElementById('editOrderDate').value;
        const expectedDelivery = document.getElementById('editExpectedDelivery').value;
        const totalAmount = document.getElementById('editTotalAmount').value;
        const poStatus = document.getElementById('editPOStatus').value;
        
        if (!supplierName) {
            Swal.fire('Warning', 'Please select a Supplier', 'warning');
            return;
        }
        
        if (!orderDate) {
            Swal.fire('Warning', 'Order Date is required', 'warning');
            return;
        }
        
        if (!totalAmount || totalAmount < 0) {
            Swal.fire('Warning', 'Valid Total Amount is required', 'warning');
            return;
        }
        
        showLoading();
        
        const formData = new FormData();
        formData.append('action', 'update_po');
        formData.append('po_id', poId);
        formData.append('supplier_name', supplierName);
        if (supplierId) {
            formData.append('supplier_id', supplierId);
        }
        formData.append('order_date', orderDate);
        formData.append('expected_delivery', expectedDelivery);
        formData.append('total_amount', totalAmount);
        formData.append('po_status', poStatus);
        
        fetch('purchase_order.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            Swal.close();
            
            if (data.success) {
                Swal.fire({
                    icon: 'success',
                    title: 'Success!',
                    text: data.message,
                    timer: 2000,
                    showConfirmButton: false
                }).then(() => {
                    bootstrap.Modal.getInstance(document.getElementById('editPOModal')).hide();
                    location.reload();
                });
            } else {
                Swal.fire('Error', data.message, 'error');
            }
        })
        .catch(error => {
            Swal.close();
            console.error('Error:', error);
            Swal.fire('Error', 'An error occurred while updating purchase order', 'error');
        });
    }

    // Show Add Item Modal
    function showAddItemModal(poId) {
        document.getElementById('addItemPOId').value = poId;
        document.getElementById('addItemForm').reset();
        document.getElementById('itemSubtotal').style.display = 'none';
        
        // Reset Select2
        $('#itemSelect').val(null).trigger('change');
        
        new bootstrap.Modal(document.getElementById('addItemModal')).show();
    }

    // Add Item to PO
    function addItemToPO() {
        const poId = document.getElementById('addItemPOId').value;
        const itemId = document.getElementById('itemSelect').value;
        const quantity = document.getElementById('itemQuantity').value;
        const unitPrice = document.getElementById('itemUnitPrice').value;
        
        if (!itemId) {
            Swal.fire('Warning', 'Please select an item', 'warning');
            return;
        }
        
        if (!quantity || quantity <= 0) {
            Swal.fire('Warning', 'Please enter a valid quantity', 'warning');
            return;
        }
        
        if (!unitPrice || unitPrice <= 0) {
            Swal.fire('Warning', 'Please enter a valid unit price', 'warning');
            return;
        }
        
        showLoading();
        
        const formData = new FormData();
        formData.append('action', 'add_po_item');
        formData.append('po_id', poId);
        formData.append('item_id', itemId);
        formData.append('quantity_ordered', quantity);
        formData.append('unit_price', unitPrice);
        
        fetch('purchase_order.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            Swal.close();
            
            if (data.success) {
                Swal.fire({
                    icon: 'success',
                    title: 'Success!',
                    text: data.message,
                    timer: 2000,
                    showConfirmButton: false
                }).then(() => {
                    bootstrap.Modal.getInstance(document.getElementById('addItemModal')).hide();
                    // Refresh the view modal
                    viewPO(poId);
                });
            } else {
                Swal.fire('Error', data.message, 'error');
            }
        })
        .catch(error => {
            Swal.close();
            console.error('Error:', error);
            Swal.fire('Error', 'An error occurred while adding item', 'error');
        });
    }

    // Delete Item
    function deleteItem(itemId, poId, itemName) {
        currentItemId = itemId;
        currentPOId = poId;
        document.getElementById('deleteItemName').textContent = itemName;
        new bootstrap.Modal(document.getElementById('deleteItemModal')).show();
    }

    // Confirm Delete Item
    function confirmDeleteItem() {
        showLoading();
        
        const formData = new FormData();
        formData.append('action', 'delete_po_item');
        formData.append('po_item_id', currentItemId);
        formData.append('po_id', currentPOId);
        
        fetch('purchase_order.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            Swal.close();
            
            if (data.success) {
                Swal.fire({
                    icon: 'success',
                    title: 'Deleted!',
                    text: data.message,
                    timer: 2000,
                    showConfirmButton: false
                }).then(() => {
                    bootstrap.Modal.getInstance(document.getElementById('deleteItemModal')).hide();
                    // Refresh the view modal
                    viewPO(currentPOId);
                });
            } else {
                Swal.fire('Error', data.message, 'error');
            }
        })
        .catch(error => {
            Swal.close();
            console.error('Error:', error);
            Swal.fire('Error', 'An error occurred while deleting item', 'error');
        });
    }

    // Delete Purchase Order
    function deletePO(id) {
        const row = document.querySelector(`.po-row[data-id="${id}"]`);
        if (!row) return;
        
        document.getElementById('deletePONumber').textContent = row.dataset.poNumber;
        currentPOId = id;
        new bootstrap.Modal(document.getElementById('deletePOModal')).show();
    }

    // Confirm Delete
    function confirmDeletePO() {
        showLoading();
        
        const formData = new FormData();
        formData.append('action', 'delete_po');
        formData.append('po_id', currentPOId);
        
        fetch('purchase_order.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            Swal.close();
            
            if (data.success) {
                Swal.fire({
                    icon: 'success',
                    title: 'Deleted!',
                    text: data.message,
                    timer: 2000,
                    showConfirmButton: false
                }).then(() => {
                    bootstrap.Modal.getInstance(document.getElementById('deletePOModal')).hide();
                    location.reload();
                });
            } else {
                Swal.fire('Error', data.message, 'error');
            }
        })
        .catch(error => {
            Swal.close();
            console.error('Error:', error);
            Swal.fire('Error', 'An error occurred while deleting purchase order', 'error');
        });
    }

    // Print PO Details
    function printPODetails() {
        const content = document.getElementById('poDetailsContent').innerHTML;
        const printWindow = window.open('', '_blank');
        printWindow.document.write(`
            <html>
                <head>
                    <title>Purchase Order Details</title>
                    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
                    <style>
                        body { padding: 20px; }
                        .status-badge { display: inline-block; padding: 5px 12px; font-size: 12px; border-radius: 20px; }
                        .status-draft { background-color: #e9ecef; color: #495057; }
                        .status-processing { background-color: #cfe2ff; color: #084298; }
                        .status-approved { background-color: #cce5ff; color: #004085; }
                        .status-delivered { background-color: #d1e7dd; color: #0a3622; }
                        .status-cancelled { background-color: #f8d7da; color: #58151c; }
                        .po-details-card { background-color: #f8f9fa; border-radius: 8px; padding: 20px; margin-bottom: 20px; }
                        .detail-label { font-size: 12px; color: #6c757d; }
                        .detail-value { font-size: 16px; font-weight: 600; }
                        .items-table { font-size: 13px; }
                        @media print {
                            .btn { display: none; }
                        }
                    </style>
                </head>
                <body>
                    <h2 class="mb-4">Purchase Order Details</h2>
                    ${content}
                </body>
            </html>
        `);
        printWindow.document.close();
        printWindow.print();
    }

    // ========== EXCEL EXPORT FUNCTION ==========
    function exportToExcel() {
        const rows = document.querySelectorAll('.po-row:not([style*="display: none"])');
        if (rows.length === 0) {
            Swal.fire('Warning', 'No purchase orders to export', 'warning');
            return;
        }
        
        // Prepare data array for Excel
        const excelData = [];
        
        // Add headers
        const headers = [
            'PO Number',
            'Supplier',
            'Order Date',
            ...(poBranchColumnExists && viewAllBranches ? ['Branch'] : []),
            'Items',
            'Quantity',
            'Total Amount (₱)',
            'Status',
            'Expected Delivery'
        ];
        excelData.push(headers);

        // Add data rows
        rows.forEach(row => {
            if (row.style.display !== 'none') {
                const cells = row.querySelectorAll('td');
                let cellIndex = 0;
                
                const poNumber = cells[cellIndex++]?.innerText || '';
                const supplier = cells[cellIndex++]?.innerText || '';
                const orderDate = cells[cellIndex++]?.innerText || '';
                
                let branch = '';
                if (poBranchColumnExists && viewAllBranches) {
                    branch = cells[cellIndex++]?.innerText || '';
                }
                
                const items = parseInt(cells[cellIndex++]?.innerText) || 0;
                const qty = parseInt(cells[cellIndex++]?.innerText.replace(/,/g, '')) || 0;
                const amount = parseFloat(cells[cellIndex++]?.innerText.replace('₱', '').replace(/,/g, '')) || 0;
                const status = cells[cellIndex++]?.innerText || '';
                const expectedDate = cells[cellIndex++]?.innerText || '';
                
                const rowData = [
                    poNumber,
                    supplier,
                    orderDate,
                    ...(poBranchColumnExists && viewAllBranches ? [branch] : []),
                    items,
                    qty,
                    amount,
                    status,
                    expectedDate
                ];
                
                excelData.push(rowData);
            }
        });

        // Create workbook and worksheet
        const wb = XLSX.utils.book_new();
        const ws = XLSX.utils.aoa_to_sheet(excelData);

        // Set column widths
        const colWidths = [
            { wch: 15 }, // PO Number
            { wch: 25 }, // Supplier
            { wch: 15 }, // Order Date
            ...(poBranchColumnExists && viewAllBranches ? [{ wch: 12 }] : []), // Branch
            { wch: 10 }, // Items
            { wch: 12 }, // Quantity
            { wch: 18 }, // Total Amount
            { wch: 15 }, // Status
            { wch: 15 }  // Expected Delivery
        ];
        ws['!cols'] = colWidths;

        // Add worksheet to workbook
        XLSX.utils.book_append_sheet(wb, ws, 'Purchase Orders');

        // Generate filename with current date and branch info
        const date = new Date();
        const dateStr = date.toISOString().slice(0,10).replace(/-/g, '');
        let filename = `Purchase_Orders_${dateStr}`;
        if (poBranchColumnExists && !viewAllBranches) {
            filename += `_Branch_${branchId}`;
        }
        filename += '.xlsx';

        // Export Excel file
        XLSX.writeFile(wb, filename);
        
        Swal.fire({
            icon: 'success',
            title: 'Export Complete',
            text: 'Excel export completed successfully!',
            timer: 2000,
            showConfirmButton: false
        });
    }

    // ========== COPY SQL FUNCTION ==========
    function copySQL(table) {
        let sql = '';
        if (table === 'purchase_orders') {
            sql = "ALTER TABLE purchase_orders ADD COLUMN branch_id INT NULL;\nALTER TABLE purchase_orders ADD FOREIGN KEY (branch_id) REFERENCES branches(branch_id);";
        } else if (table === 'items') {
            sql = "ALTER TABLE items ADD COLUMN branch_id INT NULL;\nALTER TABLE items ADD FOREIGN KEY (branch_id) REFERENCES branches(branch_id);";
        } else if (table === 'supplier_id') {
            sql = "ALTER TABLE purchase_orders ADD COLUMN supplier_id INT NULL;\nALTER TABLE purchase_orders ADD FOREIGN KEY (supplier_id) REFERENCES suppliers(supplier_id);";
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

    // Helper functions
    function getStatusClass(status) {
        const classes = {
            'draft': 'status-draft',
            'submitted': 'status-processing',
            'approved': 'status-approved',
            'received': 'status-delivered',
            'cancelled': 'status-cancelled'
        };
        return classes[status] || 'status-draft';
    }

    function getStatusText(status) {
        const texts = {
            'draft': 'Draft',
            'submitted': 'Processing',
            'approved': 'Approved',
            'received': 'Delivered',
            'cancelled': 'Cancelled'
        };
        return texts[status] || status;
    }

    // Logout Function
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
                window.location.href = '../logout.php';
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
            document.getElementById('searchInput')?.focus();
        } else if (e.ctrlKey && e.key === 'n') {
            e.preventDefault();
            showNewPOModal();
        }
    });
    </script>
</body>
</html>