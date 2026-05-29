<?php
require_once '../config/database.php';
require_once '../config/session_handler.php';

// Protect page - only Sales role can access
requireLogin();
requireRole(['sales']);

// Get current user info and branch context
$user_id = $_SESSION['user_id'];
$user_name = isset($_SESSION['first_name']) ? $_SESSION['first_name'] . ' ' . $_SESSION['last_name'] : 'Sales User';
$user_role = isset($_SESSION['role']) ? $_SESSION['role'] : 'sales';
$branch_id = $_SESSION['branch_id'] ?? 0;
$view_all_branches = $_SESSION['view_all_branches'] ?? false;

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
    $user_initials = 'SL';
}

// Get user ID for filtering
$user_id = getUserId();
$branch_id = getUserBranchId();

// Check if branch_id column exists in rmr_requests table
$branch_column_exists = false;
$check_column = $conn->query("SHOW COLUMNS FROM rmr_requests LIKE 'branch_id'");
if ($check_column && $check_column->num_rows > 0) {
    $branch_column_exists = true;
}

// Check if branch_id column exists in sales_orders table
$so_branch_column_exists = false;
$check_so_column = $conn->query("SHOW COLUMNS FROM sales_orders LIKE 'branch_id'");
if ($check_so_column && $check_so_column->num_rows > 0) {
    $so_branch_column_exists = true;
}

// Check if branch_id column exists in customers table
$customers_branch_column_exists = false;
$check_customers_column = $conn->query("SHOW COLUMNS FROM customers LIKE 'branch_id'");
if ($check_customers_column && $check_customers_column->num_rows > 0) {
    $customers_branch_column_exists = true;
}

// Check if branch_id column exists in items table
$items_branch_column_exists = false;
$check_items_column = $conn->query("SHOW COLUMNS FROM items LIKE 'branch_id'");
if ($check_items_column && $check_items_column->num_rows > 0) {
    $items_branch_column_exists = true;
}

// ===== RMR UoM SUPPORT HELPERS =====
function amgc_table_exists($conn, $table) {
    $safe_table = $conn->real_escape_string($table);
    $result = $conn->query("SHOW TABLES LIKE '{$safe_table}'");
    return ($result && $result->num_rows > 0);
}

function amgc_column_exists($conn, $table, $column) {
    $safe_table = $conn->real_escape_string($table);
    $safe_column = $conn->real_escape_string($column);
    $result = $conn->query("SHOW COLUMNS FROM `{$safe_table}` LIKE '{$safe_column}'");
    return ($result && $result->num_rows > 0);
}

function amgc_first_existing_column($conn, $table, $columns) {
    foreach ($columns as $column) {
        if (amgc_column_exists($conn, $table, $column)) {
            return $column;
        }
    }
    return null;
}

function amgc_normalize_uom($value) {
    $value = trim((string)$value);
    return $value === '' ? 'piece' : $value;
}

function amgc_ensure_rmr_uom_columns($conn) {
    if (!amgc_column_exists($conn, 'rmr_requests', 'return_unit_type')) {
        @$conn->query("ALTER TABLE rmr_requests ADD COLUMN return_unit_type VARCHAR(100) NOT NULL DEFAULT 'piece' AFTER return_quantity");
    }
    if (!amgc_column_exists($conn, 'rmr_requests', 'unit_type')) {
        @$conn->query("ALTER TABLE rmr_requests ADD COLUMN unit_type VARCHAR(100) NOT NULL DEFAULT 'piece' AFTER return_unit_type");
    }
    if (!amgc_column_exists($conn, 'rmr_requests', 'return_unit_price')) {
        @$conn->query("ALTER TABLE rmr_requests ADD COLUMN return_unit_price DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER unit_type");
    }
}

amgc_ensure_rmr_uom_columns($conn);
$rmr_return_uom_column_exists = amgc_column_exists($conn, 'rmr_requests', 'return_unit_type');
$rmr_unit_type_column_exists = amgc_column_exists($conn, 'rmr_requests', 'unit_type');
$rmr_return_price_column_exists = amgc_column_exists($conn, 'rmr_requests', 'return_unit_price');

function amgc_get_item_return_uoms($conn, $item_id, $ordered_unit_type, $ordered_qty, $ordered_unit_price, $branch_id = 0) {
    $ordered_unit_type = amgc_normalize_uom($ordered_unit_type);
    $ordered_qty = max(0, (float)$ordered_qty);
    $ordered_unit_price = max(0, (float)$ordered_unit_price);

    $uoms = [];
    $ordered_multiplier = 1.0;

    $has_unit_types = amgc_table_exists($conn, 'unit_types');
    $has_pricing = amgc_table_exists($conn, 'item_unit_pricing');

    if ($has_unit_types) {
        $ut_id_col = amgc_first_existing_column($conn, 'unit_types', ['unit_type_id', 'id']);
        $ut_name_col = amgc_first_existing_column($conn, 'unit_types', ['unit_type_name', 'unit_name', 'name', 'uom', 'unit_type']);
        $ut_mult_col = amgc_first_existing_column($conn, 'unit_types', ['quantity_smallest_pack', 'multiplier', 'conversion_factor', 'qty_per_unit']);
        $ut_item_col = amgc_first_existing_column($conn, 'unit_types', ['item_id']);
        $ut_branch_col = amgc_first_existing_column($conn, 'unit_types', ['branch_id']);
        $ut_status_col = amgc_first_existing_column($conn, 'unit_types', ['status']);

        if ($ut_name_col) {
            $select_id = $ut_id_col ? "ut.`{$ut_id_col}` AS unit_type_id" : "0 AS unit_type_id";
            $select_mult = $ut_mult_col ? "COALESCE(NULLIF(ut.`{$ut_mult_col}`, 0), 1) AS multiplier" : "1 AS multiplier";
            $select_price = "NULL AS unit_price";
            $join = "";
            $where = [];
            $types = '';
            $params = [];

            if ($has_pricing && $ut_id_col && amgc_column_exists($conn, 'item_unit_pricing', 'item_id') && amgc_column_exists($conn, 'item_unit_pricing', 'unit_type_id')) {
                $price_col = amgc_first_existing_column($conn, 'item_unit_pricing', ['price', 'unit_price', 'selling_price']);
                if ($price_col) {
                    $select_price = "iup.`{$price_col}` AS unit_price";
                    $join = "LEFT JOIN item_unit_pricing iup ON iup.unit_type_id = ut.`{$ut_id_col}` AND iup.item_id = ?";
                    $types .= 'i';
                    $params[] = $item_id;
                    if (amgc_column_exists($conn, 'item_unit_pricing', 'effective_date')) {
                        $join .= " AND (iup.effective_date IS NULL OR iup.effective_date <= CURDATE())";
                    }
                    if (amgc_column_exists($conn, 'item_unit_pricing', 'effective_until')) {
                        $join .= " AND (iup.effective_until IS NULL OR iup.effective_until >= CURDATE())";
                    }
                    $where[] = "(iup.item_id IS NOT NULL" . ($ut_item_col ? " OR ut.`{$ut_item_col}` = ?" : "") . ")";
                    if ($ut_item_col) {
                        $types .= 'i';
                        $params[] = $item_id;
                    }
                }
            } elseif ($ut_item_col) {
                $where[] = "ut.`{$ut_item_col}` = ?";
                $types .= 'i';
                $params[] = $item_id;
            }

            if ($ut_branch_col && $branch_id > 0) {
                $where[] = "(ut.`{$ut_branch_col}` IS NULL OR ut.`{$ut_branch_col}` = 0 OR ut.`{$ut_branch_col}` = ?)";
                $types .= 'i';
                $params[] = $branch_id;
            }
            if ($ut_status_col) {
                $where[] = "(ut.`{$ut_status_col}` IS NULL OR LOWER(ut.`{$ut_status_col}`) = 'active')";
            }

            $where_sql = count($where) ? 'WHERE ' . implode(' AND ', $where) : '';
            $order_sql = $ut_mult_col ? "ORDER BY multiplier ASC" : "ORDER BY ut.`{$ut_name_col}` ASC";
            $sql = "SELECT {$select_id}, ut.`{$ut_name_col}` AS unit_type_name, {$select_mult}, {$select_price}
                    FROM unit_types ut
                    {$join}
                    {$where_sql}
                    {$order_sql}";
            $stmt = $conn->prepare($sql);
            if ($stmt) {
                if ($types !== '') {
                    $stmt->bind_param($types, ...$params);
                }
                $stmt->execute();
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()) {
                    $name = amgc_normalize_uom($row['unit_type_name'] ?? 'piece');
                    $mult = (float)($row['multiplier'] ?? 1);
                    if ($mult <= 0) $mult = 1;
                    $price = is_null($row['unit_price']) ? null : (float)$row['unit_price'];
                    $key = strtolower($name);
                    if (!isset($uoms[$key])) {
                        $uoms[$key] = [
                            'unit_type' => $name,
                            'multiplier' => $mult,
                            'unit_price' => $price
                        ];
                    } elseif ($price !== null) {
                        $uoms[$key]['unit_price'] = $price;
                    }
                }
            }
        }
    }

    if (!isset($uoms[strtolower($ordered_unit_type)])) {
        $uoms[strtolower($ordered_unit_type)] = [
            'unit_type' => $ordered_unit_type,
            'multiplier' => 1,
            'unit_price' => $ordered_unit_price
        ];
    }

    foreach ($uoms as $row) {
        if (strtolower($row['unit_type']) === strtolower($ordered_unit_type)) {
            $ordered_multiplier = (float)$row['multiplier'];
            if ($ordered_multiplier <= 0) $ordered_multiplier = 1;
            break;
        }
    }

    $return_uoms = [];
    foreach ($uoms as $row) {
        $mult = (float)$row['multiplier'];
        if ($mult <= 0) $mult = 1;

        // Show the ordered UoM and all smaller UoM only.
        if ($mult <= $ordered_multiplier) {
            $max_qty = (int)floor(($ordered_qty * $ordered_multiplier) / $mult);
            if ($max_qty < 1) continue;

            $unit_price = $row['unit_price'];
            if ($unit_price === null || $unit_price <= 0) {
                $unit_price = ($ordered_multiplier > 0) ? ($ordered_unit_price * ($mult / $ordered_multiplier)) : $ordered_unit_price;
            }

            $return_uoms[] = [
                'unit_type' => $row['unit_type'],
                'multiplier' => $mult,
                'unit_price' => round((float)$unit_price, 2),
                'max_qty' => $max_qty
            ];
        }
    }

    usort($return_uoms, function($a, $b) {
        return $a['multiplier'] <=> $b['multiplier'];
    });

    return $return_uoms;
}

function amgc_find_return_uom($uoms, $unit_type) {
    $unit_type = strtolower(amgc_normalize_uom($unit_type));
    foreach ($uoms as $uom) {
        if (strtolower($uom['unit_type']) === $unit_type) {
            return $uom;
        }
    }
    return null;
}

// Handle Add Return
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_return') {
    $customer_id = !empty($_POST['customer_id']) ? (int)$_POST['customer_id'] : null;
    $item_id = !empty($_POST['item_id']) ? (int)$_POST['item_id'] : null;
    $return_quantity = !empty($_POST['return_qty']) ? (int)$_POST['return_qty'] : 0;
    $return_unit_type = isset($_POST['return_unit_type']) ? amgc_normalize_uom($_POST['return_unit_type']) : 'piece';
    $reason = isset($_POST['return_reason']) ? trim($_POST['return_reason']) : 'other';
    $status = 'pending'; // Force status to pending only
    $so_id = !empty($_POST['so_id']) ? (int)$_POST['so_id'] : null;
    $return_unit_price = 0.00;

    // Map reason to enum
    $reason_map = [
        'Defective unit' => 'damaged',
        'Wrong Item' => 'wrong-item',
        'Damaged in shipping' => 'damaged',
        'Not as described' => 'quality',
        'Customer changed mind' => 'other',
        'Expired' => 'expired',
        'Overstock' => 'overstock'
    ];
    $reason_enum = $reason_map[$reason] ?? 'other';

    $rmr_number = 'RMR-' . date('Ymd') . '-' . time();

    if ($customer_id && $item_id && $return_quantity > 0 && $so_id) {
        // Validate that the item really belongs to the selected sales order and compute allowed return UoM.
        $order_item_sql = "SELECT soi.quantity_ordered, COALESCE(NULLIF(soi.unit_type, ''), 'piece') AS ordered_unit_type,
                                  COALESCE(soi.unit_price, i.unit_price, 0) AS ordered_unit_price
                           FROM sales_order_items soi
                           JOIN sales_orders so ON soi.so_id = so.so_id
                           JOIN items i ON soi.item_id = i.item_id
                           WHERE soi.so_id = ?
                             AND soi.item_id = ?
                             AND LOWER(COALESCE(so.order_status, '')) = 'pending'
                             AND NOT EXISTS (SELECT 1 FROM rmr_requests rr WHERE rr.so_id = so.so_id)
                           LIMIT 1";
        $order_item_stmt = $conn->prepare($order_item_sql);
        $order_item_stmt->bind_param('ii', $so_id, $item_id);
        $order_item_stmt->execute();
        $order_item_result = $order_item_stmt->get_result();
        $order_item = $order_item_result ? $order_item_result->fetch_assoc() : null;

        if (!$order_item) {
            $error = 'Selected item/order is not eligible. Only pending sales orders without existing RMR can be returned.';
        } else {
            $ordered_qty = (float)($order_item['quantity_ordered'] ?? 0);
            $ordered_unit_type = $order_item['ordered_unit_type'] ?? 'piece';
            $ordered_unit_price = (float)($order_item['ordered_unit_price'] ?? 0);
            $allowed_uoms = amgc_get_item_return_uoms($conn, $item_id, $ordered_unit_type, $ordered_qty, $ordered_unit_price, $branch_id);
            $selected_uom = amgc_find_return_uom($allowed_uoms, $return_unit_type);

            if (!$selected_uom) {
                $error = 'Invalid return UoM selected for this item.';
            } elseif ($return_quantity > (int)$selected_uom['max_qty']) {
                $error = 'Return quantity exceeds the maximum allowed for selected UoM.';
            } else {
                $return_unit_price = (float)$selected_uom['unit_price'];
                $reason_details = 'Return via sales interface';

                $columns = ['rmr_number', 'so_id', 'customer_id', 'item_id', 'return_quantity'];
                $placeholders = ['?', '?', '?', '?', '?'];
                $types = 'siiii';
                $values = [$rmr_number, $so_id, $customer_id, $item_id, $return_quantity];

                if ($rmr_return_uom_column_exists) {
                    $columns[] = 'return_unit_type';
                    $placeholders[] = '?';
                    $types .= 's';
                    $values[] = $return_unit_type;
                }
                if ($rmr_unit_type_column_exists) {
                    $columns[] = 'unit_type';
                    $placeholders[] = '?';
                    $types .= 's';
                    $values[] = $return_unit_type;
                }
                if ($rmr_return_price_column_exists) {
                    $columns[] = 'return_unit_price';
                    $placeholders[] = '?';
                    $types .= 'd';
                    $values[] = $return_unit_price;
                }

                $columns = array_merge($columns, ['return_reason', 'reason_details', 'rmr_status']);
                $placeholders = array_merge($placeholders, ['?', '?', '?']);
                $types .= 'sss';
                $values[] = $reason_enum;
                $values[] = $reason_details;
                $values[] = $status;

                if ($branch_column_exists) {
                    $columns[] = 'branch_id';
                    $placeholders[] = '?';
                    $types .= 'i';
                    $values[] = $branch_id;
                }

                $sql = "INSERT INTO rmr_requests (`" . implode('`, `', $columns) . "`) VALUES (" . implode(', ', $placeholders) . ")";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param($types, ...$values);

                if ($stmt->execute()) {
                    header("Location: " . $_SERVER['PHP_SELF'] . "?success=1");
                    exit();
                } else {
                    $error = 'Error adding return: ' . $stmt->error;
                }
            }
        }
    } else {
        $error = 'Please fill in all required fields';
    }
}

// Handle Status Update via AJAX or Form
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_status') {
    header('Content-Type: application/json');
    $rmr_id = isset($_POST['rmr_id']) ? (int)$_POST['rmr_id'] : 0;
    $new_status = isset($_POST['status']) ? trim($_POST['status']) : '';
    
    if ($rmr_id > 0 && in_array($new_status, ['pending', 'approved', 'rejected', 'processing', 'completed', 'resolved'])) {
        // Verify return belongs to user's branch (if branch column exists and not admin)
        if ($branch_column_exists && !$view_all_branches) {
            $check_sql = "SELECT rmr_id FROM rmr_requests WHERE rmr_id = ? AND branch_id = ?";
            $check_stmt = $conn->prepare($check_sql);
            $check_stmt->bind_param('ii', $rmr_id, $branch_id);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            
            if ($check_result->num_rows === 0) {
                echo json_encode(['success' => false, 'error' => 'Return request not found or access denied']);
                exit;
            }
        }
        
        $update_sql = "UPDATE rmr_requests SET rmr_status = ? WHERE rmr_id = ?";
        $update_stmt = $conn->prepare($update_sql);
        $update_stmt->bind_param('si', $new_status, $rmr_id);
        
        if ($update_stmt->execute()) {
            echo json_encode(['success' => true]);
            exit();
        } else {
            echo json_encode(['success' => false, 'error' => $update_stmt->error]);
            exit();
        }
    }
    echo json_encode(['success' => false, 'error' => 'Invalid parameters']);
    exit;
}

// Handle AJAX request to get SO details with customer info
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_so_details') {
    header('Content-Type: application/json');
    $so_id = isset($_GET['so_id']) ? (int)$_GET['so_id'] : 0;
    
    if ($so_id > 0) {
        // Verify order belongs to user's branch (if branch column exists and not admin)
        if ($so_branch_column_exists && !$view_all_branches) {
            $check_sql = "SELECT so_id FROM sales_orders WHERE so_id = ? AND branch_id = ?";
            $check_stmt = $conn->prepare($check_sql);
            $check_stmt->bind_param('ii', $so_id, $branch_id);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            
            if ($check_result->num_rows === 0) {
                echo json_encode(['success' => false, 'message' => 'Order not found or access denied']);
                exit;
            }
        }
        
        // Only pending sales orders without existing RMR can be used for a new return.
        $eligibility_sql = "SELECT so_id FROM sales_orders so
                            WHERE so.so_id = ?
                              AND LOWER(COALESCE(so.order_status, '')) = 'pending'
                              AND NOT EXISTS (SELECT 1 FROM rmr_requests rr WHERE rr.so_id = so.so_id)";
        $eligibility_stmt = $conn->prepare($eligibility_sql);
        $eligibility_stmt->bind_param('i', $so_id);
        $eligibility_stmt->execute();
        $eligibility_result = $eligibility_stmt->get_result();
        if (!$eligibility_result || $eligibility_result->num_rows === 0) {
            echo json_encode(['success' => false, 'message' => 'Only pending sales orders without existing RMR request can be returned.']);
            exit;
        }

        // Join with customers table to get customer name
        $query = "SELECT so.*, c.customer_id, c.customer_name, c.branch_id as customer_branch_id
                  FROM sales_orders so
                  JOIN customers c ON so.customer_id = c.customer_id
                  WHERE so.so_id = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param('i', $so_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $order = $result->fetch_assoc();
        
        // Get order items - Only show items from the same branch
        if ($order) {
            $items_query = "SELECT soi.so_item_id, soi.so_id, soi.item_id, soi.unit_type, soi.quantity_ordered, soi.quantity_delivered,
                                  soi.unit_price AS order_unit_price,
                                  i.item_id, i.item_code, i.item_name, i.unit_price AS item_base_price, i.branch_id as item_branch_id
                           FROM sales_order_items soi
                           JOIN items i ON soi.item_id = i.item_id
                           WHERE soi.so_id = ?";
            
            // Add branch filter for items if branch column exists and not admin
            if ($items_branch_column_exists && !$view_all_branches) {
                $items_query .= " AND i.branch_id = ?";
            }
            
            $items_stmt = $conn->prepare($items_query);
            
            if ($items_branch_column_exists && !$view_all_branches) {
                $items_stmt->bind_param('ii', $so_id, $branch_id);
            } else {
                $items_stmt->bind_param('i', $so_id);
            }
            
            $items_stmt->execute();
            $items_result = $items_stmt->get_result();
            $items = [];
            
            if ($items_result) {
                while ($item = $items_result->fetch_assoc()) {
                    // Use quantity_ordered as the column name
                    $ordered_qty = isset($item['quantity_ordered']) ? (int)$item['quantity_ordered'] : 0;
                    
                    $ordered_unit_type = $item['unit_type'] ?: 'piece';
                    $ordered_unit_price = (float)($item['order_unit_price'] ?? $item['item_base_price'] ?? 0);
                    $return_uoms = amgc_get_item_return_uoms($conn, (int)$item['item_id'], $ordered_unit_type, $ordered_qty, $ordered_unit_price, $branch_id);

                    $items[] = [
                        'so_item_id' => $item['so_item_id'],
                        'item_id' => $item['item_id'],
                        'item_code' => $item['item_code'],
                        'item_name' => $item['item_name'],
                        'unit_price' => $ordered_unit_price,
                        'base_unit_price' => $item['item_base_price'],
                        'unit_type' => $ordered_unit_type,
                        'quantity' => $ordered_qty,
                        'quantity_ordered' => $ordered_qty,
                        'return_uoms' => $return_uoms
                    ];
                }
            }
            
            echo json_encode([
                'success' => true,
                'order' => $order,
                'items' => $items
            ]);
            exit;
        }
    }
    
    echo json_encode(['success' => false, 'message' => 'Invalid SO ID or order not found']);
    exit;
}

// Handle AJAX request to get sales orders by customer
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_orders_by_customer') {
    header('Content-Type: application/json');
    $customer_id = isset($_GET['customer_id']) ? (int)$_GET['customer_id'] : 0;
    
    if ($customer_id > 0) {
        $so_query = "SELECT so.so_id, so.so_number, so.customer_id, so.order_date, so.total_amount, so.branch_id,
                     c.customer_name, b.branch_name
                     FROM sales_orders so
                     JOIN customers c ON so.customer_id = c.customer_id
                     LEFT JOIN branches b ON so.branch_id = b.branch_id
                     WHERE LOWER(COALESCE(so.order_status, '')) = 'pending'
                       AND so.customer_id = ?
                       AND NOT EXISTS (SELECT 1 FROM rmr_requests rr WHERE rr.so_id = so.so_id)";
        
        // Add branch filter if needed
        if ($so_branch_column_exists && !$view_all_branches) {
            $so_query .= " AND so.branch_id = ?";
            $so_stmt = $conn->prepare($so_query);
            $so_stmt->bind_param('ii', $customer_id, $branch_id);
        } else {
            $so_stmt = $conn->prepare($so_query);
            $so_stmt->bind_param('i', $customer_id);
        }
        
        $so_stmt->execute();
        $so_result = $so_stmt->get_result();
        $orders = [];
        
        if ($so_result) {
            while ($order = $so_result->fetch_assoc()) {
                $orders[] = [
                    'so_id' => $order['so_id'],
                    'so_number' => $order['so_number'],
                    'order_date' => $order['order_date'],
                    'total_amount' => $order['total_amount']
                ];
            }
        }
        
        echo json_encode(['success' => true, 'orders' => $orders]);
        exit;
    }
    
    echo json_encode(['success' => false, 'message' => 'Invalid customer ID']);
    exit;
}

// Build query for returns with branch filtering
$where_conditions = ["1=1"];
$params = [];
$param_types = "";

// Branch filter for rmr_requests
if ($branch_column_exists) {
    if ($view_all_branches) {
        // Admin sees all returns - no filter needed
    } else {
        // Regular user sees only their branch returns
        $where_conditions[] = "rmr.branch_id = ?";
        $params[] = $branch_id;
        $param_types .= "i";
    }
}

// Get all returns with item price and SO number
$returns = [];
$query = "SELECT rmr.*, c.customer_name, i.item_name, i.item_code, i.unit_price AS base_item_unit_price,
                 COALESCE(NULLIF(rmr.return_unit_price, 0), soi.unit_price, i.unit_price) AS refund_unit_price,
                 COALESCE(NULLIF(rmr.unit_type, ''), NULLIF(rmr.return_unit_type, ''), NULLIF(soi.unit_type, ''), 'piece') AS return_display_uom,
                 COALESCE(NULLIF(soi.unit_type, ''), 'piece') AS ordered_unit_type,
                 so.so_number, so.branch_id as so_branch_id, b.branch_name
          FROM rmr_requests rmr
          JOIN customers c ON rmr.customer_id = c.customer_id
          JOIN items i ON rmr.item_id = i.item_id
          LEFT JOIN sales_orders so ON rmr.so_id = so.so_id
          LEFT JOIN sales_order_items soi ON rmr.so_id = soi.so_id AND rmr.item_id = soi.item_id
          LEFT JOIN branches b ON rmr.branch_id = b.branch_id
          WHERE " . implode(" AND ", $where_conditions) . "
          ORDER BY rmr.created_at DESC";

if (!empty($params)) {
    $stmt = $conn->prepare($query);
    $stmt->bind_param($param_types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    $result = $conn->query($query);
}

if ($result) {
    $returns = $result->fetch_all(MYSQLI_ASSOC);
}

// Get stats with branch filtering
$stats_where = ["1=1"];
$stats_params = [];
$stats_param_types = "";

if ($branch_column_exists && !$view_all_branches) {
    $stats_where[] = "rmr.branch_id = ?";
    $stats_params[] = $branch_id;
    $stats_param_types .= "i";
}

$stats_query = "SELECT 
                SUM(CASE WHEN rmr_status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN rmr_status = 'approved' THEN 1 ELSE 0 END) as approved,
                SUM(CASE WHEN rmr_status = 'rejected' THEN 1 ELSE 0 END) as rejected,
                SUM(CASE WHEN rmr_status = 'processing' THEN 1 ELSE 0 END) as processing,
                SUM(CASE WHEN rmr_status IN ('completed', 'resolved') THEN 1 ELSE 0 END) as completed,
                COALESCE(SUM(CASE WHEN rmr_status IN ('approved', 'completed', 'resolved') THEN return_quantity * COALESCE(NULLIF(rmr.return_unit_price, 0), soi.unit_price, i.unit_price) ELSE 0 END), 0) as total_refunds
                FROM rmr_requests rmr
                LEFT JOIN items i ON rmr.item_id = i.item_id
                LEFT JOIN sales_order_items soi ON rmr.so_id = soi.so_id AND rmr.item_id = soi.item_id
                WHERE " . implode(" AND ", $stats_where);

if (!empty($stats_params)) {
    $stats_stmt = $conn->prepare($stats_query);
    $stats_stmt->bind_param($stats_param_types, ...$stats_params);
    $stats_stmt->execute();
    $stats_result = $stats_stmt->get_result();
} else {
    $stats_result = $conn->query($stats_query);
}

$pending = 0;
$approved = 0;
$rejected = 0;
$processing = 0;
$completed = 0;
$total_refunds = 0;

if ($stats_result) {
    $stats = $stats_result->fetch_assoc();
    $pending = $stats['pending'] ?? 0;
    $approved = $stats['approved'] ?? 0;
    $rejected = $stats['rejected'] ?? 0;
    $processing = $stats['processing'] ?? 0;
    $completed = $stats['completed'] ?? 0;
    $total_refunds = $stats['total_refunds'] ?? 0;
}

// Get customers for dropdown - filter by branch if not admin
$customers = [];

if ($customers_branch_column_exists) {
    if ($view_all_branches) {
        $customers_query = "SELECT customer_id, customer_name, branch_id, 
                           (SELECT branch_name FROM branches WHERE branch_id = customers.branch_id) as branch_name
                           FROM customers WHERE status = 'active' ORDER BY customer_name";
        $customers_result = $conn->query($customers_query);
    } else {
        $customers_query = "SELECT customer_id, customer_name FROM customers 
                           WHERE status = 'active' AND branch_id = ? ORDER BY customer_name";
        $customers_stmt = $conn->prepare($customers_query);
        $customers_stmt->bind_param('i', $branch_id);
        $customers_stmt->execute();
        $customers_result = $customers_stmt->get_result();
    }
} else {
    $customers_query = "SELECT customer_id, customer_name FROM customers WHERE status = 'active' ORDER BY customer_name";
    $customers_result = $conn->query($customers_query);
}

if ($customers_result) {
    $customers = $customers_result->fetch_all(MYSQLI_ASSOC);
}

// Check for success message from redirect
$success = '';
$error = '';

if (isset($_GET['success']) && $_GET['success'] == 1) {
    $success = 'Return request added successfully!';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Returned Merchandise - Sales</title>
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
        <!-- Session Checker -->
    <script src="../js/session-checker.js"></script>
    <style>
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
        /* Additional CSS to support the new stat card structure */

/* Remove old conflicting styles if any */
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

/* Gradient backgrounds for each stat type */
.stat-card.pending {
    background: linear-gradient(135deg, #047857, #059669) !important;
}

.stat-card.approved {
    background: linear-gradient(135deg, #047857, #059669) !important;
    }

.stat-card.completed {
    background: linear-gradient(135deg, #047857, #059669) !important;
}

.stat-card.rejected {
    background: linear-gradient(135deg, #047857, #059669) !important;
}

/* Force text colors to white */
.stat-card .stat-value,
.stat-card .stat-label,
.stat-card .stat-content,
.stat-card small,
.stat-card small i,
.stat-card .badge {
    color: white !important;
}

/* Remove any white background from stat-content or other children */
.stat-card .stat-content,
.stat-card .stat-icon {
    background: transparent !important;
}

/* ===== MOBILE: SQUARE CARDS WITH CENTERED ICON ===== */
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
    
    /* Force icon to be centered */
    .stat-card i,
    .stat-card .stat-icon {
        display: block !important;
        text-align: center !important;
        margin: 0 auto 0.3rem auto !important;
        font-size: 1.6rem !important;
        width: auto !important;
        float: none !important;
        position: static !important;
        left: auto !important;
        right: auto !important;
        top: auto !important;
        bottom: auto !important;
    }
    
    .stat-card .stat-value {
        display: block !important;
        text-align: center !important;
        font-size: 1rem !important;
        font-weight: bold !important;
        line-height: 1.2 !important;
        margin: 0.2rem 0 !important;
        width: 100% !important;
        word-break: break-word !important;
        overflow-wrap: break-word !important;
    }
    
    .stat-card .stat-label {
        display: block !important;
        text-align: center !important;
        font-size: 0.65rem !important;
        font-weight: 500 !important;
        width: 100% !important;
        word-break: break-word !important;
        white-space: normal !important;
        line-height: 1.3 !important;
    }
    
    /* Hide the branch name on mobile to save space */
    .stat-card small {
        display: none !important;
    }
}

/* ===== DESKTOP: HORIZONTAL LAYOUT ===== */
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

/* ===== TABLET (768px - 991px) ===== */
@media (min-width: 768px) and (max-width: 991px) {
    .stat-card i,
    .stat-card .stat-icon {
        font-size: 1.4rem !important;
        margin-bottom: 0.25rem !important;
    }
    
    .stat-card .stat-value {
        font-size: 1rem !important;
    }
    
    .stat-card .stat-label {
        font-size: 0.6rem !important;
    }
}

/* ===== EXTRA SMALL MOBILE (below 400px) ===== */
@media (max-width: 399px) {
    .stat-card {
        padding: 0.25rem !important;
    }
    
    .stat-card i,
    .stat-card .stat-icon {
        font-size: 1.1rem !important;
        margin-bottom: 0.15rem !important;
    }
    
    .stat-card .stat-value {
        font-size: 0.75rem !important;
    }
    
    .stat-card .stat-label {
        font-size: 0.5rem !important;
    }
}

/* ===== LANDSCAPE MODE ===== */
@media (max-height: 500px) and (orientation: landscape) {
    .stat-card {
        aspect-ratio: auto !important;
        min-height: 55px !important;
        max-height: 70px !important;
        padding: 0.3rem !important;
        flex-direction: row !important;
        align-items: center !important;
    }
    
    .stat-card i,
    .stat-card .stat-icon {
        font-size: 1rem !important;
        margin: 0 0.5rem 0 0 !important;
    }
    
    .stat-card .stat-value {
        font-size: 0.75rem !important;
    }
    
    .stat-card .stat-label {
        font-size: 0.5rem !important;
    }
    
    .stat-card small {
        display: none !important;
    }
}

/* Row styling for stat cards */
.stat-card-row {
    margin-bottom: 1.5rem;
}

/* Hover effect for stat cards */
.stat-card:hover {
    transform: translateY(-2px) !important;
    box-shadow: 0 6px 15px rgba(0, 0, 0, 0.15) !important;
}

/* Para sa 2-line text sa label (e.g., "Total Orders" -> pwedeng mag-break) */
.stat-card .stat-label {
    display: -webkit-box !important;
    -webkit-line-clamp: 2 !important;
    -webkit-box-orient: vertical !important;
    overflow: hidden !important;
    text-overflow: ellipsis !important;
}

        /* Order info card styling */
        .order-info-card {
            background-color: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
            display: none;
            border-left: 4px solid #0d6efd;
        }
        
        .order-info-card.show {
            display: block;
        }
        
        .customer-badge {
            background-color: #e7f1ff;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.9rem;
            font-weight: 500;
        }
        
        .product-select-group {
            display: none;
        }
        
        .product-select-group.show {
            display: block;
        }
        
        .loading-spinner {
            display: inline-block;
            width: 1rem;
            height: 1rem;
            border: 2px solid #f3f3f3;
            border-top: 2px solid #0d6efd;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin-left: 8px;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        /* Returns Grid Container */
.returns-grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: 1rem;
}

/* Desktop: 2 cards per row */
@media (min-width: 768px) {
    .returns-grid {
        grid-template-columns: repeat(2, 1fr);
        gap: 1rem;
    }
}

/* Return Card */
.return-card {
    background: white;
    border-radius: 12px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
    padding: 0;
    transition: all 0.2s ease;
    border: 1px solid #e9ecef;
    overflow: hidden;
}

.return-card:hover {
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
}

/* Header Row */
.card-header-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0.75rem 1rem;
}

.return-id {
    font-size: 0.7rem;
    font-weight: 800;
    color: #059669;
    font-family: monospace;
    background: #ecfdf5;
    padding: 0.2rem 0.5rem;
    border-radius: 9px;
    letter-spacing: 0.3px;
    border: 1px solid #059669;
}

.status-badge {
    font-size: 0.7rem;
    font-weight: 600;
    padding: 0.2rem 0.6rem;
    border-radius: 20px;
}

.status-pending {
    background: #fef3c7;
    color: #d97706;
}

.status-processing {
    background: #e0f2fe;
    color: #0284c7;
}

.status-approved {
    background: #d1fae5;
    color: #059669;
}

.status-completed {
    background: #d1fae5;
    color: #059669;
}

.status-rejected {
    background: #fee2e2;
    color: #dc2626;
}

/* Card Body */
.card-body-content {
    padding: 0.75rem 1rem;
}

/* Single info item - full width */
.info-item {
    display: flex;
    justify-content: space-between;
    align-items: baseline;
    margin-bottom: 0.6rem;
}

.info-item:last-child {
    margin-bottom: 0;
}

.info-item .info-label {
    font-size: 0.65rem;
    font-weight: 600;
    color: #6c757d;
    letter-spacing: 0.3px;
    width: 110px;
    flex-shrink: 0;
}

.info-item .info-value {
    font-size: 0.8rem;
    font-weight: 500;
    color: #212529;
    text-align: right;
    flex: 1;
    word-break: break-word;
}

.info-value.refund {
    color: #059669;
    font-weight: 700;
}

/* Empty state */
.empty-state {
    grid-column: 1 / -1;
    text-align: center;
    padding: 3rem;
    background: white;
    border-radius: 12px;
    border: 1px solid #e9ecef;
}

.empty-state i {
    font-size: 2.5rem;
    color: #cbd5e1;
    margin-bottom: 0.5rem;
}

.empty-state p {
    margin: 0;
    color: #64748b;
}

/* Mobile */
@media (max-width: 767px) {
    .returns-grid {
        gap: 0.75rem;
    }
    
    .card-header-row {
        padding: 0.6rem 0.75rem;
    }
    
    .card-body-content {
        padding: 0.6rem 0.75rem;
    }
    
    .return-id {
        font-size: 0.65rem;
    }
    
    .status-badge {
        font-size: 0.6rem;
        padding: 0.15rem 0.5rem;
    }
    
    .info-item .info-label {
        font-size: 0.6rem;
        width: 90px;
    }
    
    .info-item .info-value {
        font-size: 0.75rem;
    }
    
    .info-item {
        margin-bottom: 0.5rem;
    }
}

/* Para sa sobrang liit na screen */
@media (max-width: 480px) {
    .info-item .info-label {
        width: 75px;
    }
}

/* ===== ADD BUTTON WRAPPER - OUTSIDE FILTER ===== */
.add-button-wrapper {
    margin-bottom: 1.25rem;
    text-align: right;
}

.add-button-wrapper .btn-success {
    background: linear-gradient(135deg, #059669, #047857) !important;
    border: none !important;
    border-radius: 10px !important;
    padding: 0.7rem 1.5rem !important;
    font-weight: 600 !important;
    font-size: 0.95rem !important;
    box-shadow: 0 4px 8px rgba(5, 150, 105, 0.25) !important;
}

.add-button-wrapper .btn-success:hover {
    transform: translateY(-2px) !important;
    box-shadow: 0 8px 18px rgba(5, 150, 105, 0.35) !important;
    background: linear-gradient(135deg, #047857, #065f46) !important;
}

@media (max-width: 768px) {
    .add-button-wrapper {
        margin-bottom: 1rem;
        text-align: center;
    }
    
    .add-button-wrapper .btn-success {
        width: 100%;
        padding: 0.6rem 1rem !important;
    }
}

/* Filter header styles */
.filter-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    cursor: pointer;
    padding: 0.5rem 0;
}

.filter-toggle-btn {
    background: none;
    border: none;
    font-size: 1.2rem;
    color: #059669;
    transition: transform 0.2s;
}

.filter-content {
    transition: max-height 0.3s ease-out, opacity 0.2s ease;
    overflow: hidden;
}

.filter-content.collapsed {
    max-height: 0 !important;
    opacity: 0;
    margin: 0;
    padding: 0;
}

.filter-content:not(.collapsed) {
    max-height: 300px;
    opacity: 1;
    margin-top: 1rem;
}

/* Form label styling */
.form-label {
    font-size: 0.7rem !important;
    font-weight: 600 !important;
    color: #374151 !important;
    margin-bottom: 0.35rem !important;
    display: flex !important;
    align-items: center !important;
    gap: 0.35rem !important;
    text-transform: uppercase !important;
    letter-spacing: 0.5px !important;
}

.form-label i {
    color: #047857 !important;
    font-size: 0.8rem !important;
}

/* ===== FIXED ESTIMATED REFUND INPUT - Peso sign and amount in one line ===== */
.refund-input-group {
    display: flex !important;
    align-items: stretch !important;
    width: 100% !important;
    flex-wrap: nowrap !important;
}
.refund-input-group .input-group-text {
    width: 48px !important;
    min-width: 48px !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    border-top-right-radius: 0 !important;
    border-bottom-right-radius: 0 !important;
    color: #047857 !important;
    font-weight: 700 !important;
    background: #f9fafb !important;
    border: 1px solid #dbe3ef !important;
    border-right: 0 !important;
}
.refund-input-group .form-control {
    flex: 1 1 auto !important;
    min-width: 0 !important;
    border-top-left-radius: 0 !important;
    border-bottom-left-radius: 0 !important;
    border-left: 0 !important;
}

    
/* ===== RETURNS TABLE VIEW ===== */
.returns-table-card { background: #ffffff; border: 1px solid #e5e7eb; border-radius: 14px; box-shadow: 0 4px 12px rgba(0,0,0,0.06); overflow: hidden; }
.returns-table tbody td { padding: 0.95rem 1rem; font-size: 0.9rem; color: #111827; border-bottom: 1px solid #edf2f7; vertical-align: middle; }
.return-row { cursor: pointer; transition: background-color 0.2s ease, transform 0.1s ease; }
.return-row:hover, .return-row:focus { background: #ecfdf5 !important; outline: none; }
.return-row:active { transform: scale(0.998); }
.detail-rmr-header { display: flex; justify-content: space-between; align-items: center; gap: 0.75rem; flex-wrap: wrap; padding: 0.75rem; background: #f8fafc; border-radius: 12px; }
.detail-box { background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 12px; padding: 0.85rem 1rem; min-height: 72px; }
.detail-box small { display: block; color: #6b7280; font-size: 0.68rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.4px; margin-bottom: 0.3rem; }
.detail-box strong { color: #111827; font-size: 0.95rem; word-break: break-word; }
@media (max-width: 767px) { .returns-table-card { border-radius: 12px; } .returns-table { min-width: 720px; } .returns-table thead th, .returns-table tbody td { padding: 0.75rem 0.85rem; font-size: 0.78rem; } }

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
                    <span class="nav-text">Sales</span>
                </h3>
            </div>
            
            <div class="sidebar-menu">
                <ul class="nav flex-column">
                    <li class="nav-item">
                        <a class="nav-link" href="currentinventory.php">
                            <i class="bi bi-speedometer2"></i>
                            <span class="nav-text">Dashboard</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="customer.php">
                            <i class="bi bi-people"></i>
                            <span class="nav-text">Customer</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="returnedmerchandise.php">
                            <i class="bi bi-arrow-counterclockwise"></i>
                            <span class="nav-text">Returned Merchandise</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="sales_order.php">
                            <i class="bi bi-list-check"></i>
                            <span class="nav-text">Sales Orders</span>
                        </a>
                    </li>
                     <li class="nav-item">
                         <a class="nav-link" href="sales_collections.php">
                             <i class="bi bi-cash-stack"></i>
                             <span class="nav-text">Collections</span>
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
                    <h2>Returned Merchandise Requests</h2>
                    <p>Process and manage merchandise returns</p>
                </div>
            </div>

            <!-- Branch Info Alerts -->
            <?php if (!$branch_column_exists): ?>
                <div class="alert alert-info alert-dismissible fade show" role="alert">
                    <i class="bi bi-info-circle"></i> 
                    <strong>Branch filtering for returns not yet set up.</strong> Please run this SQL in phpMyAdmin to enable branch-specific return data:
                    <br><br>
                    <code>ALTER TABLE rmr_requests ADD COLUMN branch_id INT NULL;</code>
                    <br>
                    <code>ALTER TABLE rmr_requests ADD FOREIGN KEY (branch_id) REFERENCES branches(branch_id);</code>
                    <br><br>
                    <button type="button" class="btn btn-sm btn-primary" onclick="copySQL('rmr')">
                        <i class="bi bi-files"></i> Copy SQL
                    </button>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if (!$so_branch_column_exists): ?>
                <div class="alert alert-info alert-dismissible fade show" role="alert">
                    <i class="bi bi-info-circle"></i> 
                    <strong>Branch filtering for sales orders not yet set up.</strong> Please run this SQL in phpMyAdmin to enable branch-specific order data:
                    <br><br>
                    <code>ALTER TABLE sales_orders ADD COLUMN branch_id INT NULL;</code>
                    <br>
                    <code>ALTER TABLE sales_orders ADD FOREIGN KEY (branch_id) REFERENCES branches(branch_id);</code>
                    <br><br>
                    <button type="button" class="btn btn-sm btn-primary" onclick="copySQL('sales_orders')">
                        <i class="bi bi-files"></i> Copy SQL
                    </button>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Messages -->
            <?php if (!empty($success)): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="bi bi-check-circle"></i> <?php echo $success; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            <?php if (!empty($error)): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-triangle"></i> <?php echo $error; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Return Stats -->
<div class="row stat-card-row g-1 g-sm-2 mb-4 no-print">
    <!-- Stat 1: Pending -->
    <div class="col">
        <div class="stat-card pending">
            <i class="bi bi-hourglass-split stat-icon"></i>
            <div class="stat-content">
                <div class="stat-value"><?php echo $pending; ?></div>
                <div class="stat-label">Pending</div>
                <?php if ($branch_column_exists && !$view_all_branches): ?>
                    <small class="d-block"><?php echo htmlspecialchars($branch_name ?? 'Your Branch'); ?></small>
                <?php else: ?>
                    <small class="d-block">All branches</small>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Stat 2: Approved -->
    <div class="col">
        <div class="stat-card approved">
            <i class="bi bi-check-circle stat-icon"></i>
            <div class="stat-content">
                <div class="stat-value"><?php echo $approved; ?></div>
                <div class="stat-label">Approved</div>
            </div>
        </div>
    </div>

    <!-- Stat 3: Completed -->
    <div class="col">
        <div class="stat-card completed">
            <i class="bi bi-check-circle-fill stat-icon"></i>
            <div class="stat-content">
                <div class="stat-value"><?php echo $completed; ?></div>
                <div class="stat-label">Resolved</div>
            </div>
        </div>
    </div>

    <!-- Stat 4: Rejected -->
    <div class="col">
        <div class="stat-card rejected">
            <i class="bi bi-x-circle stat-icon"></i>
            <div class="stat-content">
                <div class="stat-value"><?php echo $rejected; ?></div>
                <div class="stat-label">Rejected</div>
            </div>
        </div>
    </div>
</div>

            <!-- Search and Filter - WITHOUT NEW RETURN BUTTON (Collapsible) -->
<div class="form-card mb-4">
    <div class="filter-header">
        <h5>
            <i class="bi bi-search"></i> Search & Filter Returns
        </h5>
        <button class="filter-toggle-btn" type="button" id="filterToggleBtn" aria-expanded="false">
            <i class="bi bi-chevron-down" id="filterIcon"></i>
        </button>
    </div>
    
    <div class="filter-content collapsed" id="filterContent">
        <div class="row g-2 g-md-3 align-items-end">
            <!-- Search Field -->
            <div class="col-12 col-md-8">
                <label class="form-label">
                    <i class="bi bi-search"></i> Search
                </label>
                <div class="input-group">
                    <input type="text" class="form-control" id="searchInput" placeholder="Return ID, customer name, product...">
                </div>
            </div>
            
            <!-- Status Filter -->
            <div class="col-12 col-md-4">
                <label class="form-label">
                    <i class="bi bi-filter"></i> Status
                </label>
                <select class="form-select" id="statusFilter">
                    <option value="">All Status</option>
                    <option value="pending">Pending</option>
                    <option value="processing">Processing</option>
                    <option value="approved">Approved</option>
                    <option value="rejected">Rejected</option>
                    <option value="completed">Completed</option>\n                    <option value="resolved">Resolved</option>
                </select>
            </div>
        </div>
    </div>
</div>

            <!-- ADD NEW RETURN BUTTON - MOVED OUTSIDE FILTER, ABOVE CARDS -->
            <div class="add-button-wrapper">
                <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addReturnModal">
                    <i class="bi bi-plus-lg"></i> New Return
                </button>
            </div>

            <!-- Returns Table - Click any row to view details -->
            <div class="returns-table-card">
                <div class="table-responsive">
                    <table class="table returns-table align-middle mb-0">
                        <thead>
                            <tr>
                                <th>RMR Number</th>
                                <th>Customer</th>
                                <th>QTY</th>
                                <th>Request Date</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody id="returnsTableBody">
                        <?php if (count($returns) > 0): ?>
                            <?php foreach ($returns as $return): ?>
                                <?php
                                    $raw_status = strtolower($return['rmr_status'] ?? 'pending');
                                    $status_badge = match($raw_status) {
                                        'pending' => 'status-pending',
                                        'processing' => 'status-processing',
                                        'approved' => 'status-approved',
                                        'completed', 'resolved' => 'status-completed',
                                        'rejected' => 'status-rejected',
                                        default => 'status-default'
                                    };
                                    $status_label = ($raw_status === 'completed') ? 'Resolved' : ucfirst($raw_status);
                                    $refund_amount = $return['return_quantity'] * ($return['refund_unit_price'] ?? $return['base_item_unit_price'] ?? 0);
                                    $show_branch = ($branch_column_exists && $view_all_branches);
                                    $return_uom = $return['return_display_uom'] ?? 'piece';
                                    $request_date = !empty($return['created_at']) ? date('Y-m-d', strtotime($return['created_at'])) : '';
                                ?>
                                <tr class="return-row"
                                    tabindex="0"
                                    role="button"
                                    data-status="<?php echo htmlspecialchars($raw_status); ?>"
                                    data-rmr-number="<?php echo htmlspecialchars($return['rmr_number']); ?>"
                                    data-so-number="<?php echo htmlspecialchars($return['so_number'] ?? 'N/A'); ?>"
                                    data-customer="<?php echo htmlspecialchars($return['customer_name']); ?>"
                                    data-product="<?php echo htmlspecialchars($return['item_name']); ?>"
                                    data-item-code="<?php echo htmlspecialchars($return['item_code'] ?? 'N/A'); ?>"
                                    data-qty="<?php echo htmlspecialchars($return['return_quantity'] . ' ' . $return_uom); ?>"
                                    data-return-uom="<?php echo htmlspecialchars($return_uom); ?>"
                                    data-ordered-uom="<?php echo htmlspecialchars($return['ordered_unit_type'] ?? 'piece'); ?>"
                                    data-reason="<?php echo htmlspecialchars(ucfirst(str_replace('-', ' ', $return['return_reason']))); ?>"
                                    data-request-date="<?php echo htmlspecialchars($request_date); ?>"
                                    data-status-label="<?php echo htmlspecialchars($status_label); ?>"
                                    data-refund="₱<?php echo htmlspecialchars(number_format($refund_amount, 2)); ?>"
                                    data-branch="<?php echo htmlspecialchars($return['branch_name'] ?? ('Branch ' . ($return['branch_id'] ?? ''))); ?>"
                                    onclick="openReturnDetails(this)"
                                    onkeypress="if(event.key === 'Enter'){ openReturnDetails(this); }">
                                    <td><span class="return-id"><?php echo htmlspecialchars($return['rmr_number']); ?></span></td>
                                    <td><?php echo htmlspecialchars($return['customer_name']); ?></td>
                                    <td><?php echo htmlspecialchars($return['return_quantity'] . ' ' . $return_uom); ?></td>
                                    <td><?php echo htmlspecialchars($request_date); ?></td>
                                    <td><span class="status-badge <?php echo $status_badge; ?>"><?php echo htmlspecialchars($status_label); ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr class="empty-table-row">
                                <td colspan="5">
                                    <div class="empty-state">
                                        <i class="bi bi-inbox"></i>
                                        <p>No returns found</p>
                                        <?php if ($branch_column_exists && !$view_all_branches): ?>
                                            <small>No returns for your branch yet</small>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Return Details Modal -->
            <div class="modal fade" id="returnDetailsModal" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-lg modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title"><i class="bi bi-arrow-counterclockwise me-2"></i>Return Request Details</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <div class="detail-rmr-header mb-3">
                                <span class="return-id" id="detail_rmr_number"></span>
                                <span class="status-badge" id="detail_status"></span>
                            </div>
                            <div class="row g-3">
                                <div class="col-md-6"><div class="detail-box"><small>SO Number</small><strong id="detail_so_number"></strong></div></div>
                                <div class="col-md-6"><div class="detail-box"><small>Customer</small><strong id="detail_customer"></strong></div></div>
                                <div class="col-md-6"><div class="detail-box"><small>Product</small><strong id="detail_product"></strong></div></div>
                                <div class="col-md-6"><div class="detail-box"><small>Item Code</small><strong id="detail_item_code"></strong></div></div>
                                <div class="col-md-4"><div class="detail-box"><small>Return QTY</small><strong id="detail_qty"></strong></div></div>
                                <div class="col-md-4"><div class="detail-box"><small>Return UoM</small><strong id="detail_return_uom"></strong></div></div>
                                <div class="col-md-4"><div class="detail-box"><small>Ordered UoM</small><strong id="detail_ordered_uom"></strong></div></div>
                                <div class="col-md-6"><div class="detail-box"><small>Reason</small><strong id="detail_reason"></strong></div></div>
                                <div class="col-md-6"><div class="detail-box"><small>Request Date</small><strong id="detail_request_date"></strong></div></div>
                                <div class="col-md-6"><div class="detail-box"><small>Estimated Refund</small><strong class="text-success" id="detail_refund"></strong></div></div>
                                <?php if ($branch_column_exists && $view_all_branches): ?>
                                <div class="col-md-6"><div class="detail-box"><small>Branch</small><strong id="detail_branch"></strong></div></div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        </div>
                    </div>
                </div>
            </div>

    <!-- Add Return Modal -->
    <div class="modal fade" id="addReturnModal" tabindex="-1" data-bs-backdrop="static">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Add New Return Request</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="addReturnForm" method="POST" action="<?php echo $_SERVER['PHP_SELF']; ?>">
                        <input type="hidden" name="action" value="add_return">
                        
                        <?php if (!$so_branch_column_exists && !$branch_column_exists): ?>
                            <div class="alert alert-warning">
                                <i class="bi bi-exclamation-triangle"></i> 
                                Branch filtering is not fully set up. You may see orders from all branches.
                            </div>
                        <?php endif; ?>
                        
                        <!-- Step 1: Customer Selection -->
                        <div class="mb-4">
                            <label class="form-label fw-bold">Select Customer *</label>
                            <select class="form-select" name="customer_id" id="customer_id" required>
                                <option value="">-- Select Customer --</option>
                                <?php foreach ($customers as $customer): ?>
                                    <option value="<?php echo $customer['customer_id']; ?>">
                                        <?php echo htmlspecialchars($customer['customer_name']); ?>
                                        <?php if (isset($customer['branch_name']) && $view_all_branches): ?>
                                            [<?php echo htmlspecialchars($customer['branch_name']); ?>]
                                        <?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if ($customers_branch_column_exists && !$view_all_branches): ?>
                                <small class="text-muted">Only showing customers from your branch</small>
                            <?php endif; ?>
                        </div>

                        <!-- Step 2: Sales Order Selection - Filtered by selected customer -->
                        <div class="mb-4">
                            <label class="form-label fw-bold">Select Sales Order *</label>
                            <select class="form-select" name="so_id" id="so_id" required disabled>
                                <option value="">-- First select a customer --</option>
                            </select>
                            <small class="text-muted" id="so_loading_msg"></small>
                        </div>

                        <!-- Order Information Card - Auto-filled from sales order -->
                        <div class="order-info-card" id="orderInfoCard">
                            <div class="row">
                                <div class="col-md-6">
                                    <h6 class="fw-bold">Order Information</h6>
                                    <p class="mb-1"><strong>SO Number:</strong> <span id="display_so_number"></span></p>
                                    <p class="mb-1"><strong>Order Date:</strong> <span id="display_order_date"></span></p>
                                    <p class="mb-1"><strong>Total Amount:</strong> ₱<span id="display_total_amount"></span></p>
                                </div>
                                <div class="col-md-6">
                                    <h6 class="fw-bold">Customer Information</h6>
                                    <p class="mb-1"><strong>Name:</strong> <span id="display_customer_name" class="customer-badge"></span></p>
                                </div>
                            </div>
                        </div>

                        <!-- Product Selection - Auto-filled from SO -->
                        <div class="mb-3 product-select-group" id="productSelectGroup">
                            <label class="form-label fw-bold">Product to Return *</label>
                            <select class="form-select" name="item_id" id="item_id" required disabled>
                                <option value="">-- Select Product from Order --</option>
                            </select>
                            <?php if ($items_branch_column_exists && !$view_all_branches): ?>
                                <small class="text-muted">Only showing products from your branch</small>
                            <?php endif; ?>
                        </div>

                        <!-- Quantity, UoM and Refund - User Input -->
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label class="form-label fw-bold">Return UoM *</label>
                                <select class="form-select" name="return_unit_type" id="return_unit_type" required disabled>
                                    <option value="">-- Select UoM --</option>
                                </select>
                                <small class="text-muted" id="uom_hint"></small>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label fw-bold">Return Quantity *</label>
                                <input type="number" class="form-control" name="return_qty" id="return_qty" required min="1" placeholder="Enter quantity to return" disabled>
                                <small class="text-muted" id="max_qty_hint"></small>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label fw-bold">Estimated Refund</label>
                                <div class="input-group refund-input-group">
                                    <span class="input-group-text">₱</span>
                                    <input type="text" class="form-control" id="estimated_refund" readonly value="0.00">
                                </div>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold">Reason for Return *</label>
                            <select class="form-select" name="return_reason" id="return_reason" required disabled>
                                <option value="">-- Select Reason --</option>
                                <option value="Defective unit">Defective unit</option>
                                <option value="Wrong Item">Wrong Item</option>
                                <option value="Damaged in shipping">Damaged in shipping</option>
                                <option value="Not as described">Not as described</option>
                                <option value="Customer changed mind">Customer changed mind</option>
                                <option value="Expired">Expired</option>
                                <option value="Overstock">Overstock</option>
                            </select>
                        </div>

                        <!-- Status - Hidden and always pending -->
                        <input type="hidden" name="return_status" value="pending">
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" form="addReturnForm" class="btn btn-primary" id="submitReturnBtn" disabled>
                        Add Return Request
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Mobile Bottom Navigation -->
    <div class="mobile-nav" id="mobileNav">
        <ul class="nav">
            <li class="nav-item">
                <a class="nav-link" href="currentinventory.php">
                    <i class="bi bi-speedometer2"></i>
                    <span>Dashboard</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="customer.php">
                    <i class="bi bi-people"></i>
                    <span>Customers</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link active" href="returnedmerchandise.php">
                    <i class="bi bi-arrow-counterclockwise"></i>
                    <span>Returns</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="sales_order.php">
                    <i class="bi bi-list-check"></i>
                    <span>Sales Orders</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="sales_collections.php">
                    <i class="bi bi-cash-stack"></i>
                    <span>Collections</span>
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
                        <?php echo $user_initials; ?>
                    </div>
                    
                    <!-- User Name -->
                    <h4 class="mb-1"><?php echo htmlspecialchars($user_name); ?></h4>
                    
                    <!-- User Role -->
                    <p class="text-muted mb-3">
                        <span class="badge bg-success"><?php echo ucfirst($user_role); ?></span>
                    </p>
                    
                    <!-- Branch Info (if applicable) -->
                    <?php if (!$view_all_branches && $branch_id > 0): ?>
                    <div class="branch-info mb-3">
                        <i class="bi bi-building me-1"></i>
                        <span>Branch <?php echo $branch_id; ?></span>
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
        // ================= END SIDEBAR FUNCTIONS =================

        // Branch context variables
        const branchId = <?php echo $branch_id; ?>;
        const viewAllBranches = <?php echo $view_all_branches ? 'true' : 'false'; ?>;
        const branchColumnExists = <?php echo $branch_column_exists ? 'true' : 'false'; ?>;
        const soBranchColumnExists = <?php echo $so_branch_column_exists ? 'true' : 'false'; ?>;
        const itemsBranchColumnExists = <?php echo $items_branch_column_exists ? 'true' : 'false'; ?>;
        const customersBranchColumnExists = <?php echo $customers_branch_column_exists ? 'true' : 'false'; ?>;
        let currentOrderItems = [];

        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            console.log("Returns Management page loaded!");
            console.log("Branch ID:", branchId);
            console.log("View All Branches:", viewAllBranches);
            console.log("Branch Column Exists:", branchColumnExists);

            <?php if (isset($_GET['success']) && $_GET['success'] == 1): ?>
            Swal.fire({
                icon: 'success',
                title: 'Return request submitted!',
                text: 'The new RMR request has been sent successfully.',
                confirmButtonColor: '#047857'
            });
            <?php endif; ?>
            <?php if (!empty($error)): ?>
            Swal.fire({
                icon: 'error',
                title: 'Return request failed',
                text: <?php echo json_encode($error); ?>,
                confirmButtonColor: '#047857'
            });
            <?php endif; ?>
            
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
                
                if (isMobile && sidebar && sidebar.classList.contains('active') && 
                    !sidebar.contains(event.target) && 
                    (!mobileBtn || !mobileBtn.contains(event.target)) &&
                    (!overlay || !overlay.contains(event.target))) {
                    closeMobileSidebar();
                }
            });

            // Add resize event listener
            window.addEventListener('resize', handleSidebarResize);

            // Setup event listeners
            setupEventListeners();
            
            // Setup filter event listeners for card layout
            setupFilterEventListeners();
            
            // Auto-hide alerts after 5 seconds
            document.querySelectorAll('.alert').forEach(function(alert) {
                setTimeout(function() {
                    try {
                        let alertInstance = new bootstrap.Alert(alert);
                        alertInstance.close();
                    } catch(e) {
                        console.log('Alert already closed');
                    }
                }, 5000);
            });
            
            // Filter toggle functionality
            const filterToggleBtn = document.getElementById('filterToggleBtn');
            const filterContent = document.getElementById('filterContent');
            
            if (filterToggleBtn && filterContent) {
                filterToggleBtn.addEventListener('click', function() {
                    const expanded = this.getAttribute('aria-expanded') === 'true' ? false : true;
                    this.setAttribute('aria-expanded', expanded);
                    filterContent.classList.toggle('collapsed');
                });
            }
        });

        // Setup filter event listeners for card layout
        function setupFilterEventListeners() {
            const searchInput = document.getElementById('searchInput');
            const statusFilter = document.getElementById('statusFilter');
            
            if (searchInput) {
                searchInput.addEventListener('keyup', function() {
                    filterCards();
                });
            }
            
            if (statusFilter) {
                statusFilter.addEventListener('change', function() {
                    filterCards();
                });
            }
        }

        // Filter cards function
        function filterCards() {
            const searchValue = document.getElementById('searchInput')?.value.toLowerCase() || '';
            const statusValue = document.getElementById('statusFilter')?.value.toLowerCase() || '';
            const rows = document.querySelectorAll('.return-row');
            let hasVisibleRows = false;

            rows.forEach(row => {
                let matchSearch = true;
                let matchStatus = true;

                if (searchValue !== '') matchSearch = row.textContent.toLowerCase().includes(searchValue);
                if (statusValue !== '') {
                    const rowStatus = (row.dataset.status || '').toLowerCase();
                    matchStatus = rowStatus === statusValue || (statusValue === 'completed' && rowStatus === 'resolved');
                }

                if (matchSearch && matchStatus) {
                    row.style.display = '';
                    hasVisibleRows = true;
                } else {
                    row.style.display = 'none';
                }
            });

            const tbody = document.getElementById('returnsTableBody');
            const existingEmpty = document.querySelector('.filter-empty-row');
            const totalRows = rows.length;

            if (!hasVisibleRows && totalRows > 0) {
                if (!existingEmpty && tbody) {
                    const tr = document.createElement('tr');
                    tr.className = 'filter-empty-row';
                    tr.innerHTML = `<td colspan="5"><div class="empty-state"><i class="bi bi-search"></i><p>No matching returns found</p><small>Try adjusting your search or filter</small></div></td>`;
                    tbody.appendChild(tr);
                }
            } else if (existingEmpty) {
                existingEmpty.remove();
            }
        }

        function resetFilters() {
            if (document.getElementById('searchInput')) document.getElementById('searchInput').value = '';
            if (document.getElementById('statusFilter')) document.getElementById('statusFilter').value = '';
            document.querySelectorAll('.return-row').forEach(row => row.style.display = '');
            const filterEmpty = document.querySelector('.filter-empty-row');
            if (filterEmpty) filterEmpty.remove();
        }

        function openReturnDetails(row) {
            if (!row) return;
            const map = {
                detail_rmr_number: 'rmrNumber', detail_so_number: 'soNumber', detail_customer: 'customer', detail_product: 'product',
                detail_item_code: 'itemCode', detail_qty: 'qty', detail_return_uom: 'returnUom', detail_ordered_uom: 'orderedUom',
                detail_reason: 'reason', detail_request_date: 'requestDate', detail_refund: 'refund', detail_branch: 'branch'
            };

            Object.keys(map).forEach(id => {
                const el = document.getElementById(id);
                if (el) el.textContent = row.dataset[map[id]] || 'N/A';
            });

            const statusEl = document.getElementById('detail_status');
            if (statusEl) {
                const rawStatus = row.dataset.status || 'pending';
                statusEl.className = 'status-badge ' + getStatusBadgeClass(rawStatus);
                statusEl.textContent = row.dataset.statusLabel || rawStatus;
            }

            const modalEl = document.getElementById('returnDetailsModal');
            if (modalEl) new bootstrap.Modal(modalEl).show();
        }

        function getStatusBadgeClass(status) {
            switch ((status || '').toLowerCase()) {
                case 'pending': return 'status-pending';
                case 'processing': return 'status-processing';
                case 'approved': return 'status-approved';
                case 'completed':
                case 'resolved': return 'status-completed';
                case 'rejected': return 'status-rejected';
                default: return 'status-default';
            }
        }

        // Setup event listeners
        function setupEventListeners() {
            // Customer Selection Change - Load Sales Orders for this customer
            const customerSelect = document.getElementById('customer_id');
            const soSelect = document.getElementById('so_id');
            const soLoadingMsg = document.getElementById('so_loading_msg');
            
            if (customerSelect) {
                customerSelect.addEventListener('change', function() {
                    const customerId = this.value;
                    
                    // Reset sales order dropdown
                    if (soSelect) {
                        soSelect.innerHTML = '<option value="">-- Select Sales Order --</option>';
                        soSelect.disabled = true;
                    }
                    
                    // Reset order info and product selection
                    resetReturnForm(true); // Keep customer selection
                    
                    if (customerId) {
                        // Show loading indicator
                        if (soLoadingMsg) {
                            soLoadingMsg.innerHTML = '<span class="loading-spinner"></span> Loading orders...';
                        }
                        
                        // Fetch sales orders for this customer
                        fetch(`<?php echo $_SERVER['PHP_SELF']; ?>?action=get_orders_by_customer&customer_id=${customerId}`)
                            .then(response => response.json())
                            .then(data => {
                                if (soLoadingMsg) soLoadingMsg.innerHTML = '';
                                
                                if (data.success && data.orders && data.orders.length > 0) {
                                    if (soSelect) {
                                        soSelect.innerHTML = '<option value="">-- Select Sales Order --</option>';
                                        data.orders.forEach(order => {
                                            const option = document.createElement('option');
                                            option.value = order.so_id;
                                            option.textContent = `${order.so_number} - ${order.order_date} - ₱${parseFloat(order.total_amount).toFixed(2)}`;
                                            soSelect.appendChild(option);
                                        });
                                        soSelect.disabled = false;
                                    }
                                } else {
                                    if (soSelect) {
                                        soSelect.innerHTML = '<option value="">No orders found for this customer</option>';
                                        soSelect.disabled = true;
                                    }
                                    if (soLoadingMsg) soLoadingMsg.innerHTML = '<span class="text-warning">No sales orders found for this customer.</span>';
                                }
                            })
                            .catch(error => {
                                console.error('Error fetching orders:', error);
                                if (soLoadingMsg) soLoadingMsg.innerHTML = '<span class="text-danger">Error loading orders. Please try again.</span>';
                                if (soSelect) {
                                    soSelect.innerHTML = '<option value="">Error loading orders</option>';
                                    soSelect.disabled = true;
                                }
                            });
                    } else {
                        if (soLoadingMsg) soLoadingMsg.innerHTML = '';
                    }
                });
            }
            
            // SO Selection Change - Auto Fill Everything
            if (soSelect) {
                soSelect.addEventListener('change', function() {
                    const soId = this.value;
                    const customerSelectEl = document.getElementById('customer_id');
                    const selectedCustomer = customerSelectEl?.options[customerSelectEl.selectedIndex]?.text || '';
                    
                    if (soId && soId !== '') {
                        // Show loading indicator in product select
                        const productSelect = document.getElementById('item_id');
                        if (productSelect) {
                            productSelect.innerHTML = '<option value="">Loading products...</option>';
                            productSelect.disabled = true;
                        }
                        
                        // Disable submit button while loading
                        const submitReturnBtn = document.getElementById('submitReturnBtn');
                        if (submitReturnBtn) submitReturnBtn.disabled = true;
                        
                        // Fetch order items via AJAX
                        fetch(`<?php echo $_SERVER['PHP_SELF']; ?>?action=get_so_details&so_id=${soId}`)
                            .then(response => {
                                if (!response.ok) {
                                    throw new Error('Network response was not ok');
                                }
                                return response.json();
                            })
                            .then(data => {
                                if (data.success) {
                                    // Fill order info
                                    const displayCustomerName = document.getElementById('display_customer_name');
                                    const displaySoNumber = document.getElementById('display_so_number');
                                    const displayOrderDate = document.getElementById('display_order_date');
                                    const displayTotalAmount = document.getElementById('display_total_amount');
                                    const orderInfoCard = document.getElementById('orderInfoCard');
                                    const productSelectGroup = document.getElementById('productSelectGroup');
                                    const productSelect = document.getElementById('item_id');
                                    
                                    if (displayCustomerName) displayCustomerName.textContent = data.order.customer_name;
                                    if (displaySoNumber) displaySoNumber.textContent = data.order.so_number;
                                    if (displayOrderDate) displayOrderDate.textContent = data.order.order_date;
                                    if (displayTotalAmount) displayTotalAmount.textContent = parseFloat(data.order.total_amount).toFixed(2);
                                    
                                    // Show order info card
                                    if (orderInfoCard) orderInfoCard.classList.add('show');
                                    
                                    // Populate product dropdown
                                    currentOrderItems = data.items || [];
                                    if (productSelect) {
                                        productSelect.innerHTML = '<option value="">-- Select Product from Order --</option>';
                                        
                                        if (data.items && data.items.length > 0) {
                                            data.items.forEach(item => {
                                                const option = document.createElement('option');
                                                option.value = item.item_id;
                                                const unitTypeLabel = item.unit_type ? String(item.unit_type).toUpperCase() : 'PIECE';
                                                option.textContent = `${item.item_code} - ${item.item_name} (${unitTypeLabel}, Ordered: ${item.quantity_ordered || item.quantity || 0}, Price: ₱${parseFloat(item.unit_price).toFixed(2)})`;
                                                option.dataset.price = item.unit_price;
                                                option.dataset.unitType = item.unit_type || 'piece';
                                                option.dataset.maxQty = item.quantity_ordered || item.quantity || 0;
                                                option.dataset.returnUoms = JSON.stringify(item.return_uoms || []);
                                                productSelect.appendChild(option);
                                            });
                                            
                                            // Enable form fields
                                            productSelect.disabled = false;
                                            if (productSelectGroup) productSelectGroup.classList.add('show');
                                            
                                            const returnQty = document.getElementById('return_qty');
                                            const returnUom = document.getElementById('return_unit_type');
                                            const returnReason = document.getElementById('return_reason');
                                            if (returnQty) returnQty.disabled = true;
                                            if (returnUom) returnUom.disabled = true;
                                            if (returnReason) returnReason.disabled = false;
                                        } else {
                                            productSelect.innerHTML = '<option value="">No products found in this order</option>';
                                            productSelect.disabled = true;
                                        }
                                    }
                                } else {
                                    if (productSelect) {
                                        productSelect.innerHTML = '<option value="">Error loading products</option>';
                                        productSelect.disabled = true;
                                    }
                                    alert('Error: ' + (data.message || 'Failed to load order details'));
                                }
                            })
                            .catch(error => {
                                console.error('Error fetching order details:', error);
                                const productSelect = document.getElementById('item_id');
                                if (productSelect) {
                                    productSelect.innerHTML = '<option value="">Error loading products</option>';
                                    productSelect.disabled = true;
                                }
                                alert('Error loading order details. Please try again.');
                            });
                    } else {
                        resetReturnForm(true);
                    }
                });
            }

            // Product Selection Change
            const itemSelect = document.getElementById('item_id');
            if (itemSelect) {
                itemSelect.addEventListener('change', function() {
                    const returnUom = document.getElementById('return_unit_type');
                    const qtyInput = document.getElementById('return_qty');
                    const maxQtyHint = document.getElementById('max_qty_hint');
                    const uomHint = document.getElementById('uom_hint');
                    const submitReturnBtn = document.getElementById('submitReturnBtn');
                    const estimatedRefund = document.getElementById('estimated_refund');

                    if (returnUom) {
                        returnUom.innerHTML = '<option value="">-- Select UoM --</option>';
                        returnUom.disabled = true;
                    }
                    if (qtyInput) {
                        qtyInput.value = '';
                        qtyInput.disabled = true;
                        qtyInput.removeAttribute('max');
                        qtyInput.placeholder = 'Enter quantity to return';
                    }
                    if (maxQtyHint) maxQtyHint.innerHTML = '';
                    if (uomHint) uomHint.innerHTML = '';
                    if (estimatedRefund) estimatedRefund.value = '0.00';
                    if (submitReturnBtn) submitReturnBtn.disabled = true;

                    if (this.value && this.options[this.selectedIndex]?.dataset?.returnUoms) {
                        let uoms = [];
                        try {
                            uoms = JSON.parse(this.options[this.selectedIndex].dataset.returnUoms || '[]');
                        } catch (e) {
                            uoms = [];
                        }

                        if (returnUom && uoms.length > 0) {
                            uoms.forEach(uom => {
                                const opt = document.createElement('option');
                                opt.value = uom.unit_type;
                                opt.textContent = `${String(uom.unit_type).toUpperCase()} - Max: ${uom.max_qty} - ₱${parseFloat(uom.unit_price || 0).toFixed(2)}`;
                                opt.dataset.price = uom.unit_price || 0;
                                opt.dataset.maxQty = uom.max_qty || 0;
                                opt.dataset.unitType = uom.unit_type || 'piece';
                                returnUom.appendChild(opt);
                            });
                            returnUom.disabled = false;
                            if (uomHint) uomHint.innerHTML = '<span class="text-primary">Choose the UoM to return. Smaller UoM is allowed.</span>';
                        }
                    }
                });
            }

            // Return UoM Selection Change
            const returnUomSelect = document.getElementById('return_unit_type');
            if (returnUomSelect) {
                returnUomSelect.addEventListener('change', function() {
                    const qtyInput = document.getElementById('return_qty');
                    const maxQtyHint = document.getElementById('max_qty_hint');
                    const submitReturnBtn = document.getElementById('submitReturnBtn');
                    const estimatedRefund = document.getElementById('estimated_refund');

                    if (this.value && this.options[this.selectedIndex]?.dataset?.maxQty) {
                        const selectedOption = this.options[this.selectedIndex];
                        const maxQty = parseInt(selectedOption.dataset.maxQty) || 0;
                        const unitType = selectedOption.dataset.unitType || this.value || 'piece';

                        if (qtyInput) {
                            qtyInput.value = '';
                            qtyInput.max = maxQty;
                            qtyInput.placeholder = `Max: ${maxQty}`;
                            qtyInput.disabled = false;
                        }
                        if (maxQtyHint) maxQtyHint.innerHTML = `<span class="text-primary">Maximum return quantity: ${maxQty} ${unitType}</span>`;
                        if (estimatedRefund) estimatedRefund.value = '0.00';
                        if (submitReturnBtn) submitReturnBtn.disabled = false;
                    } else {
                        if (qtyInput) {
                            qtyInput.value = '';
                            qtyInput.disabled = true;
                        }
                        if (maxQtyHint) maxQtyHint.innerHTML = '';
                        if (estimatedRefund) estimatedRefund.value = '0.00';
                        if (submitReturnBtn) submitReturnBtn.disabled = true;
                    }
                });
            }

            // Quantity Input Change
            const returnQty = document.getElementById('return_qty');
            if (returnQty) {
                returnQty.addEventListener('input', calculateRefund);
            }

            // Reset form when modal is closed
            const addReturnModal = document.getElementById('addReturnModal');
            if (addReturnModal) {
                addReturnModal.addEventListener('hidden.bs.modal', function() {
                    resetReturnForm(false);
                });
            }

            // SweetAlert confirmation before submitting new return request
            const addReturnForm = document.getElementById('addReturnForm');
            if (addReturnForm) {
                addReturnForm.addEventListener('submit', function(e) {
                    if (addReturnForm.dataset.confirmed === '1') {
                        const submitBtn = document.getElementById('submitReturnBtn');
                        if (submitBtn) {
                            submitBtn.disabled = true;
                            submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Submitting...';
                        }
                        return true;
                    }

                    e.preventDefault();
                    Swal.fire({
                        title: 'Submit return request?',
                        text: 'Please confirm that all return details are correct.',
                        icon: 'question',
                        showCancelButton: true,
                        confirmButtonColor: '#047857',
                        cancelButtonColor: '#6c757d',
                        confirmButtonText: 'Yes, submit',
                        cancelButtonText: 'Cancel'
                    }).then((result) => {
                        if (result.isConfirmed) {
                            addReturnForm.dataset.confirmed = '1';
                            addReturnForm.submit();
                        }
                    });
                });
            }
        }

        // Calculate estimated refund amount
        function calculateRefund() {
            const returnUom = document.getElementById('return_unit_type');
            const returnQty = document.getElementById('return_qty');
            const quantity = parseInt(returnQty?.value) || 0;
            const estimatedRefund = document.getElementById('estimated_refund');

            if (returnUom && returnUom.selectedIndex > 0 && returnUom.options[returnUom.selectedIndex]?.dataset?.price) {
                const selectedOption = returnUom.options[returnUom.selectedIndex];
                const price = parseFloat(selectedOption.dataset.price) || 0;
                const maxQty = parseInt(selectedOption.dataset.maxQty) || 0;
                const unitType = selectedOption.dataset.unitType || selectedOption.value || 'piece';

                if (quantity > maxQty) {
                    if (returnQty) returnQty.value = maxQty;
                    if (estimatedRefund) estimatedRefund.value = (price * maxQty).toFixed(2);
                    alert(`Maximum return quantity is ${maxQty} ${unitType}`);
                } else {
                    const refund = price * quantity;
                    if (estimatedRefund) estimatedRefund.value = refund.toFixed(2);
                }
            } else {
                if (estimatedRefund) estimatedRefund.value = '0.00';
            }
        }

        // Reset return form
        function resetReturnForm(keepCustomerSelection = false) {
            const orderInfoCard = document.getElementById('orderInfoCard');
            const productSelectGroup = document.getElementById('productSelectGroup');
            const displayCustomerName = document.getElementById('display_customer_name');
            const displaySoNumber = document.getElementById('display_so_number');
            const displayOrderDate = document.getElementById('display_order_date');
            const displayTotalAmount = document.getElementById('display_total_amount');
            const productSelect = document.getElementById('item_id');
            const returnQty = document.getElementById('return_qty');
            const returnReason = document.getElementById('return_reason');
            const returnUom = document.getElementById('return_unit_type');
            const uomHint = document.getElementById('uom_hint');
            const estimatedRefund = document.getElementById('estimated_refund');
            const maxQtyHint = document.getElementById('max_qty_hint');
            const submitReturnBtn = document.getElementById('submitReturnBtn');
            const soSelect = document.getElementById('so_id');
            const soLoadingMsg = document.getElementById('so_loading_msg');
            
            if (orderInfoCard) orderInfoCard.classList.remove('show');
            if (productSelectGroup) productSelectGroup.classList.remove('show');
            
            if (displayCustomerName) displayCustomerName.textContent = '';
            if (displaySoNumber) displaySoNumber.textContent = '';
            if (displayOrderDate) displayOrderDate.textContent = '';
            if (displayTotalAmount) displayTotalAmount.textContent = '';
            
            // Reset sales order dropdown only if not keeping customer selection
            if (!keepCustomerSelection) {
                const customerSelect = document.getElementById('customer_id');
                if (customerSelect) customerSelect.value = '';
                if (soSelect) {
                    soSelect.innerHTML = '<option value="">-- First select a customer --</option>';
                    soSelect.disabled = true;
                }
                if (soLoadingMsg) soLoadingMsg.innerHTML = '';
            } else {
                // Reset SO selection but keep customer
                if (soSelect) {
                    soSelect.innerHTML = '<option value="">-- Select Sales Order --</option>';
                    soSelect.disabled = true;
                }
                if (soLoadingMsg) soLoadingMsg.innerHTML = '';
            }
            
            if (productSelect) {
                productSelect.innerHTML = '<option value="">-- Select Product from Order --</option>';
                productSelect.disabled = true;
            }
            
            if (returnQty) {
                returnQty.value = '';
                returnQty.disabled = true;
            }
            
            if (returnReason) {
                returnReason.value = '';
                returnReason.disabled = true;
            }
            
            if (estimatedRefund) estimatedRefund.value = '0.00';
            if (maxQtyHint) maxQtyHint.innerHTML = '';
            if (uomHint) uomHint.innerHTML = '';
            currentOrderItems = [];
            if (submitReturnBtn) submitReturnBtn.disabled = true;
        }

        // Update return status
        function updateStatus(rmrId, newStatus) {
            if (confirm('Are you sure you want to update this return status to ' + newStatus + '?')) {
                const formData = new FormData();
                formData.append('action', 'update_status');
                formData.append('rmr_id', rmrId);
                formData.append('status', newStatus);
                
                fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        window.location.reload();
                    } else {
                        alert('Error updating status: ' + (data.error || 'Unknown error'));
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error updating status. Please try again.');
                });
            }
        }

        // Copy SQL for database setup
        function copySQL(table) {
            let sql = '';
            if (table === 'rmr') {
                sql = "ALTER TABLE rmr_requests ADD COLUMN branch_id INT NULL;\nALTER TABLE rmr_requests ADD FOREIGN KEY (branch_id) REFERENCES branches(branch_id);";
            } else if (table === 'sales_orders') {
                sql = "ALTER TABLE sales_orders ADD COLUMN branch_id INT NULL;\nALTER TABLE sales_orders ADD FOREIGN KEY (branch_id) REFERENCES branches(branch_id);";
            } else if (table === 'customers') {
                sql = "ALTER TABLE customers ADD COLUMN branch_id INT NULL;\nALTER TABLE customers ADD FOREIGN KEY (branch_id) REFERENCES branches(branch_id);";
            } else if (table === 'items') {
                sql = "ALTER TABLE items ADD COLUMN branch_id INT NULL;\nALTER TABLE items ADD FOREIGN KEY (branch_id) REFERENCES branches(branch_id);";
            }
            
            navigator.clipboard.writeText(sql).then(() => {
                alert('SQL copied to clipboard!');
            });
        }

        function logout() {
            window.location.href = '../logout.php';
        }

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey && e.key === 'b' && window.innerWidth > 992) {
                e.preventDefault();
                toggleSidebar();
            }
            else if (e.key === 'Escape' && window.innerWidth <= 992) {
                closeMobileSidebar();
            }
            else if (e.ctrlKey && e.key === 'f' && !e.target.matches('input, textarea')) {
                e.preventDefault();
                const searchInput = document.getElementById('searchInput');
                if (searchInput) {
                    searchInput.focus();
                }
            }
            else if (e.ctrlKey && e.key === 'n' && !e.target.matches('input, textarea')) {
                e.preventDefault();
                const addButton = document.querySelector('[data-bs-target="#addReturnModal"]');
                if (addButton) {
                    addButton.click();
                }
            }
            else if (e.ctrlKey && e.key === 'r' && !e.target.matches('input, textarea')) {
                e.preventDefault();
                resetReturnForm(false);
            }
        });
        
        // ============= MOBILE NAVIGATION FUNCTION =============
        function initMobileNav() {
            const mobileNav = document.getElementById('mobileNav');
            if (!mobileNav) return;
            
            const isMobile = window.innerWidth <= 992;
            
            if (isMobile) {
                mobileNav.style.display = 'block';
                
                const currentPage = window.location.pathname.split('/').pop();
                const navLinks = mobileNav.querySelectorAll('.nav-link:not(.logout-btn)');
                
                navLinks.forEach(link => {
                    const href = link.getAttribute('href');
                    if (currentPage === href) {
                        link.classList.add('active');
                    } else {
                        link.classList.remove('active');
                    }
                });
            } else {
                mobileNav.style.display = 'none';
            }
        }

        // ============= PROFILE MODAL FUNCTIONS =============
        function showProfileModal() {
            const profileModal = new bootstrap.Modal(document.getElementById('profileModal'));
            profileModal.show();
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
        // ================= LOGOUT FUNCTION =================
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
        
        // Make filter functions globally available
        window.filterCards = filterCards;
        window.applyFilters = filterCards;
        window.resetFilters = resetFilters;
    </script>
</body>
</html>