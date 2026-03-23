<?php
// Enable error reporting for debugging (remove in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../config/database.php';
require_once '../config/session_handler.php';

// Check database connection
if (!$conn) {
    die("Database connection failed: " . mysqli_connect_error());
}

// Get current user info and branch context
$user_id = $_SESSION['user_id'] ?? 0;
$user_name = isset($_SESSION['first_name']) ? $_SESSION['first_name'] . ' ' . $_SESSION['last_name'] : 'Branch Admin';
$user_role = isset($_SESSION['role']) ? $_SESSION['role'] : 'branch_admin';
$branch_id = $_SESSION['branch_id'] ?? 0;
$view_all_branches = $_SESSION['view_all_branches'] ?? false;

// Check if branch_id column exists in items table
$items_branch_column_exists = false;
$check_column = $conn->query("SHOW COLUMNS FROM items LIKE 'branch_id'");
if ($check_column && $check_column->num_rows > 0) {
    $items_branch_column_exists = true;
}

// Check if branch_id column exists in suppliers table
$suppliers_branch_column_exists = false;
$check_suppliers_branch = $conn->query("SHOW COLUMNS FROM suppliers LIKE 'branch_id'");
if ($check_suppliers_branch && $check_suppliers_branch->num_rows > 0) {
    $suppliers_branch_column_exists = true;
}

// Check if price columns exist
$price_case_exists = false;
$check_price_case = $conn->query("SHOW COLUMNS FROM items LIKE 'price_case'");
if ($check_price_case && $check_price_case->num_rows > 0) {
    $price_case_exists = true;
}

// Determine branch filter condition for items
$items_branch_condition = "";
if ($items_branch_column_exists && !$view_all_branches && $branch_id > 0) {
    $items_branch_condition = "AND branch_id = " . intval($branch_id);
}

// Determine branch filter condition for suppliers
$suppliers_branch_condition = "";
if ($suppliers_branch_column_exists && !$view_all_branches && $branch_id > 0) {
    $suppliers_branch_condition = "AND branch_id = " . intval($branch_id);
}

// ========== CALCULATE AVERAGE DAILY OFFTAKE ==========
// Get total sales quantity for last 30 days from sales_order_items
$avg_offtake_query = "
    SELECT 
        COALESCE(SUM(soi.quantity_ordered), 0) as total_quantity_30d,
        COUNT(DISTINCT DATE(so.created_at)) as active_days
    FROM sales_order_items soi
    JOIN sales_orders so ON soi.so_id = so.so_id
    WHERE so.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    AND so.order_status IN ('delivered', 'confirmed', 'processing', 'ready')
";

if ($items_branch_column_exists && !$view_all_branches && $branch_id > 0) {
    $avg_offtake_query .= " AND so.branch_id = " . intval($branch_id);
}

$avg_offtake_result = $conn->query($avg_offtake_query);
if (!$avg_offtake_result) {
    error_log("Offtake Query Error: " . $conn->error);
    $total_quantity_30d = 0;
    $active_days = 0;
} else {
    $offtake_data = $avg_offtake_result->fetch_assoc();
    $total_quantity_30d = $offtake_data['total_quantity_30d'] ?? 0;
    $active_days = $offtake_data['active_days'] ?? 0;
}

// Calculate average daily offtake (avoid division by zero)
$avg_daily_offtake = $active_days > 0 ? round($total_quantity_30d / $active_days, 1) : 0;

// ========== GET BASE64 ENCODED LOGO FOR PRINTING ==========
$logo_path = '../Pictures/amgc3DLogo.png';
$logo_base64 = '';
if (file_exists($logo_path)) {
    $image_data = file_get_contents($logo_path);
    $logo_base64 = 'data:image/png;base64,' . base64_encode($image_data);
}

// ========== IMAGE UPLOAD HANDLER ==========
function handleImageUpload($file) {
    // Validate file
    $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $max_file_size = 5 * 1024 * 1024; // 5MB
    
    // Check file size
    if ($file['size'] > $max_file_size) {
        return false;
    }
    
    // Check file type
    $file_info = pathinfo($file['name']);
    $extension = strtolower($file_info['extension']);
    
    if (!in_array($extension, $allowed_extensions)) {
        return false;
    }
    
    // Create uploads directory if it doesn't exist
    $upload_dir = '../uploads/products/';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    
    // Generate unique filename
    $filename = 'item_' . time() . '_' . uniqid() . '.' . $extension;
    $filepath = $upload_dir . $filename;
    
    // Move uploaded file
    if (move_uploaded_file($file['tmp_name'], $filepath)) {
        return $filename;
    }
    
    return false;
}

// ========== HANDLE AJAX REQUESTS ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    try {
        $conn->begin_transaction();
        
        // ADD ITEM - FIXED VERSION
        if ($_POST['action'] === 'add_item') {
            $item_code = $_POST['item_code'] ?? '';
            $item_name = $_POST['item_name'] ?? '';
            $description = $_POST['description'] ?? null;
            $category = $_POST['category'] ?? 'General';
            $stock = (int)($_POST['stock'] ?? 0);
            
            // FIXED: Convert unit_type to match database enum (replace hyphen with underscore)
            $unit_type = $_POST['unit_type'] ?? 'piece';
            if ($unit_type === 'inner-pack') {
                $unit_type = 'inner_pack';
            }
            
            $unit_price = (float)($_POST['unit_price'] ?? 0);
            $reorder_level = (int)($_POST['reorder_level'] ?? 0);
            $status = $_POST['status'] ?? 'active';
            $picture_filename = null;
            
            // Validate required fields
            if (empty($item_code)) throw new Exception('Item code is required');
            if (empty($item_name)) throw new Exception('Item name is required');
            
            // Handle image upload
            if (isset($_FILES['itemPicture']) && $_FILES['itemPicture']['size'] > 0) {
                $picture_filename = handleImageUpload($_FILES['itemPicture']);
                if (!$picture_filename) {
                    throw new Exception('Failed to upload image. Please check file format and size.');
                }
            }
            
            // Check if item code already exists
            $check_query = "SELECT item_id FROM items WHERE item_code = ?";
            $check_stmt = $conn->prepare($check_query);
            if (!$check_stmt) throw new Exception('Database prepare error');
            $check_stmt->bind_param("s", $item_code);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            
            if ($check_result->num_rows > 0) {
                throw new Exception('Item code already exists');
            }
            
            // Calculate price multipliers
            $price_case = $unit_price * 12;
            $price_inner_pack = $unit_price * 6;
            $price_box = $unit_price * 24;
            $price_carton = $unit_price * 48;
            
            // Insert new item - FIXED: Correct column names and parameter counts
            if ($items_branch_column_exists) {
                $insert_query = "INSERT INTO items (
                    item_code, item_name, description, category, stock, unit_type, unit_price, 
                    price_case, price_inner_pack, price_box, price_carton, reorder_level, status, 
                    branch_id, product_image_url, created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
                
                $insert_stmt = $conn->prepare($insert_query);
                if (!$insert_stmt) throw new Exception('Database prepare error');
                $insert_stmt->bind_param("ssssisdddddisss", 
                    $item_code, $item_name, $description, $category, $stock, $unit_type, $unit_price,
                    $price_case, $price_inner_pack, $price_box, $price_carton,
                    $reorder_level, $status, $branch_id, $picture_filename
                );
            } else {
                $insert_query = "INSERT INTO items (
                    item_code, item_name, description, category, stock, unit_type, unit_price, 
                    price_case, price_inner_pack, price_box, price_carton, reorder_level, status, 
                    product_image_url, created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
                
                $insert_stmt = $conn->prepare($insert_query);
                if (!$insert_stmt) throw new Exception('Database prepare error');
                $insert_stmt->bind_param("ssssisdddddsss", 
                    $item_code, $item_name, $description, $category, $stock, $unit_type, $unit_price,
                    $price_case, $price_inner_pack, $price_box, $price_carton,
                    $reorder_level, $status, $picture_filename
                );
            }
            
            if (!$insert_stmt->execute()) {
                throw new Exception('Failed to add item: ' . $insert_stmt->error);
            }
            
            $item_id = $conn->insert_id;
            
            $conn->commit();
            
            echo json_encode([
                'success' => true,
                'message' => 'Item added successfully',
                'item_id' => $item_id
            ]);
            exit;
        }
        
        // UPDATE ITEM - FIXED VERSION
        elseif ($_POST['action'] === 'update_item') {
            $item_id = (int)($_POST['item_id'] ?? 0);
            $item_name = $_POST['item_name'] ?? '';
            $description = $_POST['description'] ?? null;
            $category = $_POST['category'] ?? 'General';
            $stock = (int)($_POST['stock'] ?? 0);
            
            // FIXED: Convert unit_type to match database enum (replace hyphen with underscore)
            $unit_type = $_POST['unit_type'] ?? 'piece';
            if ($unit_type === 'inner-pack') {
                $unit_type = 'inner_pack';
            }
            
            $unit_price = (float)($_POST['unit_price'] ?? 0);
            $reorder_level = (int)($_POST['reorder_level'] ?? 0);
            $status = $_POST['status'] ?? 'active';
            
            // Validate required fields
            if ($item_id <= 0) throw new Exception('Invalid item ID');
            if (empty($item_name)) throw new Exception('Item name is required');
            
            // Verify item belongs to user's branch (if branch column exists and not admin)
            if ($items_branch_column_exists && !$view_all_branches) {
                $check_query = "SELECT item_id FROM items WHERE item_id = ? AND branch_id = ?";
                $check_stmt = $conn->prepare($check_query);
                if (!$check_stmt) throw new Exception('Database prepare error');
                $check_stmt->bind_param("ii", $item_id, $branch_id);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                
                if ($check_result->num_rows === 0) {
                    throw new Exception('Item not found or access denied');
                }
            }
            
            // Handle image upload if a new image is provided
            $picture_filename = null;
            $current_picture = null;
            
            // Get current picture filename if exists
            $pic_query = "SELECT product_image_url FROM items WHERE item_id = ?";
            $pic_stmt = $conn->prepare($pic_query);
            if (!$pic_stmt) throw new Exception('Database prepare error');
            $pic_stmt->bind_param("i", $item_id);
            $pic_stmt->execute();
            $pic_result = $pic_stmt->get_result();
            $pic_row = $pic_result->fetch_assoc();
            $current_picture = $pic_row['product_image_url'] ?? null;
            
            if (isset($_FILES['editItemPicture']) && $_FILES['editItemPicture']['size'] > 0) {
                $picture_filename = handleImageUpload($_FILES['editItemPicture']);
                if (!$picture_filename) {
                    throw new Exception('Failed to upload image. Please check file format and size.');
                }
            } else {
                $picture_filename = $current_picture;
            }
            
            // Calculate price multipliers
            $price_case = $unit_price * 12;
            $price_inner_pack = $unit_price * 6;
            $price_box = $unit_price * 24;
            $price_carton = $unit_price * 48;
            
            // Update item - FIXED: Correct parameter types
            $update_query = "UPDATE items 
                           SET item_name = ?, description = ?, category = ?, stock = ?, unit_type = ?, 
                               unit_price = ?, price_case = ?, price_inner_pack = ?, price_box = ?, price_carton = ?,
                               reorder_level = ?, status = ?, product_image_url = ?, updated_at = NOW() 
                           WHERE item_id = ?";
            
            $update_stmt = $conn->prepare($update_query);
            if (!$update_stmt) throw new Exception('Database prepare error');
            $update_stmt->bind_param("sssisddddddssi", 
                $item_name, $description, $category, $stock, $unit_type, $unit_price,
                $price_case, $price_inner_pack, $price_box, $price_carton,
                $reorder_level, $status, $picture_filename, $item_id
            );
            
            if (!$update_stmt->execute()) {
                throw new Exception('Failed to update item: ' . $update_stmt->error);
            }
            
            $conn->commit();
            
            echo json_encode([
                'success' => true,
                'message' => 'Item updated successfully'
            ]);
            exit;
        }
        
        // TOGGLE ITEM STATUS
        elseif ($_POST['action'] === 'toggle_status') {
            $item_id = (int)($_POST['item_id'] ?? 0);
            $new_status = $_POST['status'] ?? 'inactive';
            
            if ($item_id <= 0) throw new Exception('Invalid item ID');
            
            // Verify item belongs to user's branch (if branch column exists and not admin)
            if ($items_branch_column_exists && !$view_all_branches) {
                $check_query = "SELECT item_id FROM items WHERE item_id = ? AND branch_id = ?";
                $check_stmt = $conn->prepare($check_query);
                if (!$check_stmt) throw new Exception('Database prepare error');
                $check_stmt->bind_param("ii", $item_id, $branch_id);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                
                if ($check_result->num_rows === 0) {
                    throw new Exception('Item not found or access denied');
                }
            }
            
            $update_query = "UPDATE items SET status = ?, updated_at = NOW() WHERE item_id = ?";
            $update_stmt = $conn->prepare($update_query);
            if (!$update_stmt) throw new Exception('Database prepare error');
            $update_stmt->bind_param("si", $new_status, $item_id);
            
            if (!$update_stmt->execute()) {
                throw new Exception('Failed to update item status');
            }
            
            $conn->commit();
            
            echo json_encode([
                'success' => true,
                'message' => 'Item status updated successfully'
            ]);
            exit;
        }
        
        // DELETE ITEM
        elseif ($_POST['action'] === 'delete_item') {
            $item_id = (int)($_POST['item_id'] ?? 0);
            
            if ($item_id <= 0) throw new Exception('Invalid item ID');
            
            // Verify item belongs to user's branch (if branch column exists and not admin)
            if ($items_branch_column_exists && !$view_all_branches) {
                $check_query = "SELECT item_id FROM items WHERE item_id = ? AND branch_id = ?";
                $check_stmt = $conn->prepare($check_query);
                if (!$check_stmt) throw new Exception('Database prepare error');
                $check_stmt->bind_param("ii", $item_id, $branch_id);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                
                if ($check_result->num_rows === 0) {
                    throw new Exception('Item not found or access denied');
                }
            }
            
            // Check if item is used in sales orders
            $check_so_query = "SELECT COUNT(*) as count FROM sales_order_items WHERE item_id = ?";
            $check_so_stmt = $conn->prepare($check_so_query);
            if (!$check_so_stmt) throw new Exception('Database prepare error');
            $check_so_stmt->bind_param("i", $item_id);
            $check_so_stmt->execute();
            $so_result = $check_so_stmt->get_result();
            $so_count = $so_result->fetch_assoc()['count'] ?? 0;
            
            if ($so_count > 0) {
                // Soft delete - just update status to discontinued
                $update_query = "UPDATE items SET status = 'discontinued', updated_at = NOW() WHERE item_id = ?";
                $update_stmt = $conn->prepare($update_query);
                if (!$update_stmt) throw new Exception('Database prepare error');
                $update_stmt->bind_param("i", $item_id);
                $update_stmt->execute();
            } else {
                // Hard delete if not used
                $delete_query = "DELETE FROM items WHERE item_id = ?";
                $delete_stmt = $conn->prepare($delete_query);
                if (!$delete_stmt) throw new Exception('Database prepare error');
                $delete_stmt->bind_param("i", $item_id);
                $delete_stmt->execute();
            }
            
            $conn->commit();
            
            echo json_encode([
                'success' => true,
                'message' => 'Item deleted successfully'
            ]);
            exit;
        }
        
        // GET ITEM DETAILS
        elseif ($_POST['action'] === 'get_item') {
            $item_id = (int)($_POST['item_id'] ?? 0);
            
            if ($item_id <= 0) throw new Exception('Invalid item ID');
            
            // Add branch filter if needed
            $query = "SELECT * FROM items WHERE item_id = ?";
            if ($items_branch_column_exists && !$view_all_branches) {
                $query .= " AND branch_id = ?";
                $stmt = $conn->prepare($query);
                if (!$stmt) throw new Exception('Database prepare error');
                $stmt->bind_param("ii", $item_id, $branch_id);
            } else {
                $stmt = $conn->prepare($query);
                if (!$stmt) throw new Exception('Database prepare error');
                $stmt->bind_param("i", $item_id);
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
        
        // GET SUPPLIERS (for supplier selector modal) - from suppliers table
        elseif ($_POST['action'] === 'get_suppliers') {
            // Get suppliers from suppliers table
            $suppliers_query = "SELECT supplier_id, supplier_name, supplier_code, contact_person, email, phone_number 
                               FROM suppliers 
                               WHERE status = 'active'";
            
            if ($suppliers_branch_column_exists && !$view_all_branches && $branch_id > 0) {
                $suppliers_query .= " AND branch_id = " . intval($branch_id);
            }
            $suppliers_query .= " ORDER BY supplier_name ASC";
            
            $suppliers_result = $conn->query($suppliers_query);
            if (!$suppliers_result) {
                throw new Exception('Failed to fetch suppliers: ' . $conn->error);
            }
            $suppliers = $suppliers_result->fetch_all(MYSQLI_ASSOC);
            
            echo json_encode([
                'success' => true,
                'suppliers' => $suppliers
            ]);
            exit;
        }
        
        // GET SUPPLIER DETAILS (for supplier selector modal) - uses suppliers table
        elseif ($_POST['action'] === 'get_supplier_details') {
            $supplier_id = (int)($_POST['supplier_id'] ?? 0);
            
            if ($supplier_id <= 0) throw new Exception('Invalid supplier ID');
            
            // Get supplier details
            $supplier_query = "SELECT * FROM suppliers WHERE supplier_id = ?";
            $supplier_stmt = $conn->prepare($supplier_query);
            if (!$supplier_stmt) throw new Exception('Database prepare error');
            $supplier_stmt->bind_param("i", $supplier_id);
            $supplier_stmt->execute();
            $supplier_result = $supplier_stmt->get_result();
            $supplier = $supplier_result->fetch_assoc();
            
            if (!$supplier) {
                throw new Exception('Supplier not found');
            }
            
            // Get purchase orders for this supplier (optional, if you want to show history)
            $po_query = "SELECT po.*, COUNT(poi.po_item_id) as total_items, 
                                SUM(poi.quantity_ordered) as total_quantity,
                                b.branch_name
                          FROM purchase_orders po
                          LEFT JOIN purchase_order_items poi ON po.po_id = poi.po_id
                          LEFT JOIN branches b ON po.branch_id = b.branch_id
                          WHERE po.supplier_id = ?
                          GROUP BY po.po_id
                          ORDER BY po.created_at DESC";
            
            $po_stmt = $conn->prepare($po_query);
            if (!$po_stmt) throw new Exception('Database prepare error');
            $po_stmt->bind_param("i", $supplier_id);
            $po_stmt->execute();
            $po_result = $po_stmt->get_result();
            $purchase_orders = $po_result->fetch_all(MYSQLI_ASSOC);
            
            // Get items for each PO
            foreach ($purchase_orders as &$po) {
                $items_query = "SELECT poi.*, i.item_name, i.item_code, i.unit_type 
                                FROM purchase_order_items poi
                                JOIN items i ON poi.item_id = i.item_id
                                WHERE poi.po_id = ?";
                $items_stmt = $conn->prepare($items_query);
                if (!$items_stmt) throw new Exception('Database prepare error');
                $items_stmt->bind_param("i", $po['po_id']);
                $items_stmt->execute();
                $items_result = $items_stmt->get_result();
                $po['items'] = $items_result->fetch_all(MYSQLI_ASSOC);
            }
            
            echo json_encode([
                'success' => true,
                'supplier' => $supplier,
                'purchase_orders' => $purchase_orders
            ]);
            exit;
        }
        
        // GET LOW STOCK ITEMS
        elseif ($_POST['action'] === 'get_low_stock_items') {
            $low_stock_query = "SELECT item_id, item_code, item_name, stock, reorder_level, 
                                       unit_type, unit_price, category, product_image_url
                                FROM items 
                                WHERE stock <= reorder_level AND status = 'active'";
            
            if ($items_branch_column_exists && !$view_all_branches) {
                $low_stock_query .= " AND branch_id = ?";
                $low_stock_stmt = $conn->prepare($low_stock_query);
                if (!$low_stock_stmt) throw new Exception('Database prepare error');
                $low_stock_stmt->bind_param("i", $branch_id);
            } else {
                $low_stock_stmt = $conn->prepare($low_stock_query);
                if (!$low_stock_stmt) throw new Exception('Database prepare error');
            }
            
            $low_stock_stmt->execute();
            $low_stock_result = $low_stock_stmt->get_result();
            $low_stock_items = $low_stock_result->fetch_all(MYSQLI_ASSOC);
            
            echo json_encode([
                'success' => true,
                'items' => $low_stock_items
            ]);
            exit;
        }
        
        // GET OFFTAKE DATA WITH DATE RANGE
        elseif ($_POST['action'] === 'get_offtake_data') {
            $start_date = $_POST['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
            $end_date = $_POST['end_date'] ?? date('Y-m-d');
            
            $offtake_query = "
                SELECT 
                    DATE(so.created_at) as sale_date,
                    COUNT(DISTINCT so.so_id) as order_count,
                    SUM(soi.quantity_ordered) as total_quantity,
                    SUM(so.total_amount) as total_amount
                FROM sales_order_items soi
                JOIN sales_orders so ON soi.so_id = so.so_id
                WHERE DATE(so.created_at) BETWEEN ? AND ?
                AND so.order_status IN ('delivered', 'confirmed', 'processing', 'ready')
            ";
            
            if ($items_branch_column_exists && !$view_all_branches && $branch_id > 0) {
                $offtake_query .= " AND so.branch_id = ? GROUP BY DATE(so.created_at) ORDER BY so.created_at";
                $offtake_stmt = $conn->prepare($offtake_query);
                if (!$offtake_stmt) throw new Exception('Database prepare error');
                $offtake_stmt->bind_param("ssi", $start_date, $end_date, $branch_id);
            } else {
                $offtake_query .= " GROUP BY DATE(so.created_at) ORDER BY so.created_at";
                $offtake_stmt = $conn->prepare($offtake_query);
                if (!$offtake_stmt) throw new Exception('Database prepare error');
                $offtake_stmt->bind_param("ss", $start_date, $end_date);
            }
            
            $offtake_stmt->execute();
            $offtake_result = $offtake_stmt->get_result();
            $daily_data = $offtake_result->fetch_all(MYSQLI_ASSOC);
            
            // Calculate summary
            $total_quantity = array_sum(array_column($daily_data, 'total_quantity'));
            $total_orders = array_sum(array_column($daily_data, 'order_count'));
            $total_amount = array_sum(array_column($daily_data, 'total_amount'));
            $active_days = count($daily_data);
            $avg_daily = $active_days > 0 ? round($total_quantity / $active_days, 1) : 0;
            
            // Get total items count
            $items_count_query = "SELECT COUNT(*) as total_items FROM items WHERE status = 'active'";
            if ($items_branch_column_exists && !$view_all_branches && $branch_id > 0) {
                $items_count_query .= " AND branch_id = " . intval($branch_id);
            }
            $items_count_result = $conn->query($items_count_query);
            $total_items = $items_count_result ? (int)($items_count_result->fetch_assoc()['total_items'] ?? 1) : 1;
            
            $avg_per_item = $total_items > 0 ? round($avg_daily / $total_items, 1) : 0;
            
            echo json_encode([
                'success' => true,
                'daily_data' => $daily_data,
                'summary' => [
                    'total_quantity' => $total_quantity,
                    'total_orders' => $total_orders,
                    'total_amount' => $total_amount,
                    'active_days' => $active_days,
                    'avg_daily' => $avg_daily,
                    'avg_per_item' => $avg_per_item
                ],
                'date_range' => [
                    'start' => $start_date,
                    'end' => $end_date
                ]
            ]);
            exit;
        }
        
        // PRINT OFFTAKE REPORT
        elseif ($_POST['action'] === 'print_offtake') {
            $filter_data = json_decode($_POST['filter_data'] ?? '{}', true);
            
            $start_date = $filter_data['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
            $end_date = $filter_data['end_date'] ?? date('Y-m-d');
            
            $offtake_query = "
                SELECT 
                    DATE(so.created_at) as sale_date,
                    COUNT(DISTINCT so.so_id) as order_count,
                    SUM(soi.quantity_ordered) as total_quantity,
                    SUM(so.total_amount) as total_amount
                FROM sales_order_items soi
                JOIN sales_orders so ON soi.so_id = so.so_id
                WHERE DATE(so.created_at) BETWEEN ? AND ?
                AND so.order_status IN ('delivered', 'confirmed', 'processing', 'ready')
            ";
            
            if ($items_branch_column_exists && !$view_all_branches && $branch_id > 0) {
                $offtake_query .= " AND so.branch_id = ? GROUP BY DATE(so.created_at) ORDER BY so.created_at";
                $offtake_stmt = $conn->prepare($offtake_query);
                $offtake_stmt->bind_param("ssi", $start_date, $end_date, $branch_id);
            } else {
                $offtake_query .= " GROUP BY DATE(so.created_at) ORDER BY so.created_at";
                $offtake_stmt = $conn->prepare($offtake_query);
                $offtake_stmt->bind_param("ss", $start_date, $end_date);
            }
            
            $offtake_stmt->execute();
            $offtake_result = $offtake_stmt->get_result();
            $daily_data = $offtake_result->fetch_all(MYSQLI_ASSOC);
            
            // Calculate summary
            $total_quantity = array_sum(array_column($daily_data, 'total_quantity'));
            $total_orders = array_sum(array_column($daily_data, 'order_count'));
            $total_amount = array_sum(array_column($daily_data, 'total_amount'));
            $active_days = count($daily_data);
            $avg_daily = $active_days > 0 ? round($total_quantity / $active_days, 1) : 0;
            
            // Get total items count
            $items_count_query = "SELECT COUNT(*) as total_items FROM items WHERE status = 'active'";
            if ($items_branch_column_exists && !$view_all_branches && $branch_id > 0) {
                $items_count_query .= " AND branch_id = " . intval($branch_id);
            }
            $items_count_result = $conn->query($items_count_query);
            $total_items = $items_count_result ? (int)($items_count_result->fetch_assoc()['total_items'] ?? 1) : 1;
            
            $avg_per_item = $total_items > 0 ? round($avg_daily / $total_items, 1) : 0;
            
            echo json_encode([
                'success' => true,
                'items' => $daily_data,
                'summary' => [
                    'total_quantity' => $total_quantity,
                    'total_orders' => $total_orders,
                    'total_amount' => $total_amount,
                    'active_days' => $active_days,
                    'avg_daily' => $avg_daily,
                    'avg_per_item' => $avg_per_item
                ],
                'date_range' => [
                    'start' => $start_date,
                    'end' => $end_date
                ],
                'branch_name' => $branch_id ? ('Branch ' . $branch_id) : 'All Branches',
                'view_all' => $view_all_branches
            ]);
            exit;
        }
        
        // UPDATE STOCK AFTER SALES ORDER
        elseif ($_POST['action'] === 'update_stock_from_sales') {
            $item_id = (int)($_POST['item_id'] ?? 0);
            $quantity_sold = (int)($_POST['quantity'] ?? 0);
            $so_id = (int)($_POST['so_id'] ?? 0);
            
            if ($item_id <= 0 || $quantity_sold <= 0 || $so_id <= 0) {
                throw new Exception('Invalid parameters');
            }
            
            // Verify item belongs to user's branch
            if ($items_branch_column_exists && !$view_all_branches) {
                $check_query = "SELECT item_id, stock FROM items WHERE item_id = ? AND branch_id = ?";
                $check_stmt = $conn->prepare($check_query);
                if (!$check_stmt) throw new Exception('Database prepare error');
                $check_stmt->bind_param("ii", $item_id, $branch_id);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                
                if ($check_result->num_rows === 0) {
                    throw new Exception('Item not found or access denied');
                }
                
                $item = $check_result->fetch_assoc();
                
                // Check if enough stock
                if ($item['stock'] < $quantity_sold) {
                    throw new Exception('Insufficient stock for item');
                }
                
                // Update stock
                $new_stock = $item['stock'] - $quantity_sold;
                $update_query = "UPDATE items SET stock = ?, updated_at = NOW() WHERE item_id = ?";
                $update_stmt = $conn->prepare($update_query);
                if (!$update_stmt) throw new Exception('Database prepare error');
                $update_stmt->bind_param("ii", $new_stock, $item_id);
                
                if (!$update_stmt->execute()) {
                    throw new Exception('Failed to update stock');
                }
                
                // Record inventory transaction (if table exists)
                $check_transaction_table = $conn->query("SHOW TABLES LIKE 'inventory_transactions'");
                if ($check_transaction_table && $check_transaction_table->num_rows > 0) {
                    $trans_query = "INSERT INTO inventory_transactions 
                                   (branch_id, item_id, transaction_type, quantity_changed, reference_type, reference_id, created_by, created_at) 
                                   VALUES (?, ?, 'out', ?, 'sales_order', ?, ?, NOW())";
                    $trans_stmt = $conn->prepare($trans_query);
                    if ($trans_stmt) {
                        $trans_stmt->bind_param("iiiii", $branch_id, $item_id, $quantity_sold, $so_id, $user_id);
                        $trans_stmt->execute();
                    }
                }
            }
            
            $conn->commit();
            
            echo json_encode([
                'success' => true,
                'message' => 'Stock updated successfully'
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

// FETCH ALL ITEMS FROM items TABLE WITH BRANCH FILTERING
$items_query = "
    SELECT 
        item_id,
        item_code,
        item_name,
        description,
        category,
        stock as quantity_on_hand,
        unit_type,
        unit_price,
        price_case,
        price_inner_pack,
        price_box,
        price_carton,
        reorder_level,
        status,
        branch_id,
        product_image_url,
        created_at,
        updated_at
    FROM items
    WHERE 1=1
    $items_branch_condition
    ORDER BY category, item_name ASC
";

$items_result = $conn->query($items_query);
if (!$items_result) {
    error_log("Items Query Error: " . $conn->error);
    $items = [];
} else {
    $items = $items_result->fetch_all(MYSQLI_ASSOC);
}

// Group items by category for tab view
$items_by_category = [];
$unique_categories = [];
foreach ($items as $item) {
    $category = $item['category'] ?? 'Uncategorized';
    if (!isset($items_by_category[$category])) {
        $items_by_category[$category] = [];
        $unique_categories[] = $category;
    }
    $items_by_category[$category][] = $item;
}

// ========== FETCH SUPPLIER ITEMS FROM suppliers AND purchase_orders ==========
// This builds the data for the "By Supplier" tabbed view.
// We need to get items that have been ordered from each supplier (via purchase_orders).
$supplier_items_query = "
    SELECT 
        s.supplier_id,
        s.supplier_name,
        i.item_id,
        i.item_code,
        i.item_name,
        i.category,
        i.stock as quantity_on_hand,
        i.unit_type,
        i.reorder_level,
        i.status,
        i.product_image_url,
        i.branch_id
    FROM suppliers s
    JOIN purchase_orders po ON s.supplier_id = po.supplier_id
    JOIN purchase_order_items poi ON po.po_id = poi.po_id
    JOIN items i ON poi.item_id = i.item_id
    WHERE s.status = 'active'
";

if ($items_branch_column_exists && !$view_all_branches && $branch_id > 0) {
    $supplier_items_query .= " AND i.branch_id = " . intval($branch_id);
}

$supplier_items_query .= " ORDER BY s.supplier_name, i.item_name";

$supplier_items_result = $conn->query($supplier_items_query);
if (!$supplier_items_result) {
    error_log("Supplier Items Query Error: " . $conn->error);
    $supplier_items = [];
    $items_by_supplier = [];
} else {
    $supplier_items = $supplier_items_result->fetch_all(MYSQLI_ASSOC);
    
    $items_by_supplier = [];
    foreach ($supplier_items as $item) {
        $supplier = $item['supplier_name'] ?? 'Unknown Supplier';
        if (!isset($items_by_supplier[$supplier])) {
            $items_by_supplier[$supplier] = [];
        }
        $items_by_supplier[$supplier][] = $item;
    }
}

// GET NEXT ITEM CODE FOR AUTO-GENERATION (branch-specific)
$next_number = 1;
if (!empty($items)) {
    // Extract numbers from existing item codes (ITEM001, ITEM002, etc.)
    $numbers = [];
    foreach ($items as $item) {
        if (preg_match('/ITEM(\d+)/', $item['item_code'], $matches)) {
            $numbers[] = intval($matches[1]);
        }
    }
    if (!empty($numbers)) {
        $next_number = max($numbers) + 1;
    }
}
$next_item_code = 'ITEM' . str_pad($next_number, 3, '0', STR_PAD_LEFT);

// CALCULATE STATISTICS FROM REAL DATA (branch-specific)
$total_items = count($items);
$total_stock = array_sum(array_column($items, 'quantity_on_hand'));
$total_value = array_sum(array_map(function($item) {
    return $item['quantity_on_hand'] * $item['unit_price'];
}, $items));

$low_stock_items = array_filter($items, function($item) {
    return $item['quantity_on_hand'] <= $item['reorder_level'] && $item['quantity_on_hand'] > 0;
});
$low_stock_count = count($low_stock_items);

$out_of_stock = count(array_filter($items, function($item) {
    return $item['quantity_on_hand'] <= 0;
}));

// Get unique categories count
$unique_categories_count = count(array_unique(array_column($items, 'category')));

// Get unique suppliers count (distinct suppliers that have items)
$suppliers_count = count(array_unique(array_column($supplier_items, 'supplier_name')));

// Get total items count for offtake calculation
$total_items_count = count($items);
$avg_per_item = $total_items_count > 0 ? round($avg_daily_offtake / $total_items_count, 1) : 0;

// STAT CARD VALUES - WITH PROPER LABELS
$statInventoryValue = '₱' . number_format($total_value / 1000, 1) . 'K';
$statNeedsAttention = $low_stock_count + $out_of_stock;

// Stock status function
function getStockStatus($stock, $reorder_level) {
    if ($stock <= 0) return ['label' => 'Out of Stock', 'class' => 'bg-danger text-white'];
    if ($stock <= $reorder_level) return ['label' => 'Low Stock', 'class' => 'bg-warning text-dark'];
    if ($stock <= $reorder_level * 2) return ['label' => 'Normal', 'class' => 'bg-info text-white'];
    return ['label' => 'Adequate', 'class' => 'bg-success text-white'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Current Inventory - Branch Admin</title>
    <link rel="icon" type="image/png" href="../Pictures/favicon-96x96.png" sizes="96x96" />
    <link rel="icon" type="image/svg+xml" href="../Pictures/favicon.svg" />
    <link rel="shortcut icon" href="../Pictures/favicon.ico" />
    <link rel="apple-touch-icon" sizes="180x180" href="../Pictures/apple-touch-icon.png" />
    <link rel="manifest" href="../Pictures/site.webmanifest" />
    <link rel="stylesheet" href="../css/current_inventory.css">
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@100;200;300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/inter-ui@3.19.3/inter.css">
    <!-- SheetJS for Excel Export -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <!-- SweetAlert2 -->
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <!-- jQuery and Select2 -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    
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
            
            .col-md-3, .col-md-4, .col-md-6 {
                width: 50%;
                padding-left: 8px;
                padding-right: 8px;
            }
            
            .row.g-3 {
                margin-left: -8px;
                margin-right: -8px;
            }
            
            .category-tabs {
                flex-wrap: nowrap;
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
                padding-bottom: 10px;
            }
            
            .category-tab {
                white-space: nowrap;
            }
        }
        
        /* Alert for sales integration */
        .sales-integration-alert {
            background-color: #cff4fc;
            border-color: #b6effb;
            color: #055160;
        }
        
        /* Stat card hover effect for clickable cards */
        .stat-card.clickable {
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        
        .stat-card.clickable:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        
        /* Supplier dropdown styling */
        .supplier-selector {
            min-width: 200px;
        }
        
        .supplier-info {
            background-color: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
            border-left: 4px solid #2E7D32;
        }
        
        .supplier-stat {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #e9ecef;
        }
        
        .supplier-stat:last-child {
            border-bottom: none;
        }
        
        .supplier-stat-label {
            color: #6c757d;
            font-size: 13px;
        }
        
        .supplier-stat-value {
            font-weight: 600;
            color: #212529;
        }
        
        /* Low stock items list */
        .low-stock-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid #e9ecef;
        }
        
        .low-stock-item:last-child {
            border-bottom: none;
        }
        
        .low-stock-item-info {
            flex: 1;
        }
        
        .low-stock-item-name {
            font-weight: 600;
            color: #212529;
        }
        
        .low-stock-item-code {
            font-size: 11px;
            color: #6c757d;
            margin-top: 2px;
        }
        
        .low-stock-item-stats {
            text-align: right;
            margin-right: 15px;
        }
        
        .low-stock-item-current {
            font-weight: 600;
            color: #dc3545;
        }
        
        .low-stock-item-reorder {
            font-size: 11px;
            color: #6c757d;
        }
        
        .stock-status-badge {
            display: inline-block;
            padding: 4px 8px;
            font-size: 11px;
            border-radius: 20px;
            font-weight: 500;
        }
        
        .stock-badge-danger {
            background-color: #f8d7da;
            color: #721c24;
        }
        
        .stock-badge-warning {
            background-color: #fff3cd;
            color: #856404;
        }
        
        /* Modal styling */
        .modal-supplier-details {
            max-height: 500px;
            overflow-y: auto;
        }
        
        .modal-header-success {
            background: linear-gradient(135deg, #2E7D32, #1B5E20);
            color: white;
        }
        
        .modal-header-warning {
            background: linear-gradient(135deg, #FFC107, #FFB300);
            color: #212529;
        }
        
        .modal-header-offtake {
            background: linear-gradient(135deg, #9C27B0, #7B1FA2);
            color: white;
        }
        
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
        
        /* Image thumbnail in table - FIXED */
        .item-thumbnail {
            width: 50px;
            height: 50px;
            object-fit: cover;
            border-radius: 8px;
            border: 1px solid #dee2e6;
            background-color: #f8f9fa;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }
        
        .item-thumbnail i {
            font-size: 24px;
            color: #adb5bd;
        }
        
        .item-thumbnail img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 8px;
        }
        
        /* Status toggle styling - simplified (no labels) */
        .status-toggle {
            display: flex;
            justify-content: center;
            align-items: center;
        }
        
        .toggle-switch {
            position: relative;
            display: inline-block;
            width: 46px;
            height: 22px;
            margin: 0;
        }
        
        .toggle-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        
        .toggle-slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #dc3545;
            transition: .3s;
            border-radius: 22px;
        }
        
        .toggle-slider:before {
            position: absolute;
            content: "";
            height: 18px;
            width: 18px;
            left: 2px;
            bottom: 2px;
            background-color: white;
            transition: .3s;
            border-radius: 50%;
        }
        
        input:checked + .toggle-slider {
            background-color: #28a745;
        }
        
        input:checked + .toggle-slider:before {
            transform: translateX(24px);
        }
        
        /* Status column header */
        th.col-status {
            text-align: center;
        }
        
        /* View toggle buttons */
        .view-toggle {
            display: flex;
            gap: 5px;
            margin-left: 15px;
        }
        
        .view-btn {
            padding: 6px 12px;
            border: 1px solid #dee2e6;
            background-color: white;
            border-radius: 6px;
            color: #6c757d;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .view-btn.active {
            background-color: #2E7D32;
            border-color: #2E7D32;
            color: white;
        }
        
        .view-btn:hover:not(.active) {
            background-color: #e9ecef;
        }
        
        /* Tab styling - for both category and supplier */
        .category-tabs {
            border-bottom: 2px solid #dee2e6;
            margin-bottom: 20px;
            padding-bottom: 5px;
            display: flex;
            flex-wrap: wrap;
            gap: 5px;
        }
        
        .category-tab {
            display: inline-flex;
            align-items: center;
            padding: 10px 20px;
            background-color: #f8f9fa;
            border: 1px solid #dee2e6;
            border-bottom: none;
            border-radius: 8px 8px 0 0;
            cursor: pointer;
            font-weight: 500;
            color: #495057;
            transition: all 0.2s;
        }
        
        .category-tab:hover {
            background-color: #e9ecef;
        }
        
        .category-tab.active {
            background-color: #2E7D32;
            color: white;
            border-color: #2E7D32;
        }
        
        .category-tab.active i {
            color: white;
        }
        
        .category-tab i {
            margin-right: 8px;
            color: #6c757d;
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
        }
        
        .tab-badge {
            background-color: rgba(255,255,255,0.2);
            padding: 3px 8px;
            border-radius: 20px;
            margin-left: 8px;
            font-size: 11px;
        }
        
        .category-tab.active .tab-badge {
            background-color: rgba(255,255,255,0.3);
        }
        
        /* Filter status indicators */
        .filter-active {
            border-left: 3px solid #2E7D32 !important;
        }
        
        .filter-summary {
            background-color: #e8f5e9;
            padding: 8px 15px;
            border-radius: 20px;
            font-size: 12px;
            color: #2E7D32;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 15px;
        }
        
        .filter-summary i {
            font-size: 14px;
        }
        
        .clear-filters {
            color: #2E7D32;
            text-decoration: none;
            font-weight: 500;
            cursor: pointer;
        }
        
        .clear-filters:hover {
            text-decoration: underline;
        }
        
        .no-items-message {
            text-align: center;
            padding: 40px 20px;
            background-color: #f8f9fa;
            border-radius: 8px;
            color: #6c757d;
        }
        
        .no-items-message i {
            font-size: 48px;
            margin-bottom: 15px;
            color: #adb5bd;
        }
        
        /* Table adjustments */
        .table th, .table td {
            vertical-align: middle;
        }
        
        .col-image {
            width: 70px;
        }
        
        .col-status {
            width: 80px;
            text-align: center;
        }
        
        .col-actions {
            width: 120px;
        }
        
        /* Offtake card specific styling - simplified */
        .offtake-card {
            background: linear-gradient(135deg, #9C27B0, #7B1FA2);
        }
        
        .offtake-card .stat-value {
            font-size: 2rem;
        }
        
        /* Offtake modal styling - without chart */
        .offtake-summary-card {
            background-color: #f8f9fa;
            border-radius: 12px;
            padding: 20px;
            text-align: center;
            border-left: 4px solid #9C27B0;
            height: 100%;
            transition: transform 0.2s;
        }
        
        .offtake-summary-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        
        .offtake-summary-value {
            font-size: 2rem;
            font-weight: 700;
            color: #212529;
            line-height: 1.2;
        }
        
        .offtake-summary-label {
            font-size: 0.85rem;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .date-filter-row {
            background-color: #f8f9fa;
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 25px;
            border: 1px solid #e9ecef;
        }
        
        .offtake-table {
            font-size: 0.9rem;
        }
        
        .offtake-table th {
            background-color: #9C27B0;
            color: white;
            font-weight: 600;
            padding: 12px 10px;
        }
        
        .offtake-table td {
            padding: 10px;
            vertical-align: middle;
        }
        
        .offtake-table tbody tr:hover {
            background-color: #f8f9fa;
        }
        
        .total-row {
            background-color: #e9ecef;
            font-weight: 600;
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
        
        /* Compact print styles - only logo has color */
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
            
            /* Only keep the logo colored */
            #printFrame img {
                filter: none !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            
            /* Everything else black and white */
            #printFrame * {
                background: white !important;
                color: black !important;
                border-color: #000 !important;
                box-shadow: none !important;
                text-shadow: none !important;
                -webkit-print-color-adjust: economy;
                print-color-adjust: economy;
            }
            
            /* Table borders in black */
            #printFrame table, 
            #printFrame th, 
            #printFrame td {
                border: 1px solid #000 !important;
            }
            
            /* Header background to white with black text */
            #printFrame th {
                background: white !important;
                color: black !important;
                font-weight: bold;
            }
            
            /* Remove any gradient backgrounds */
            #printFrame .summary-box,
            #printFrame .customer-section,
            #printFrame .total-row {
                background: white !important;
                border: 1px solid #000 !important;
            }
            
            /* Remove all background colors from badges */
            #printFrame .badge {
                background: white !important;
                border: 1px solid #000 !important;
                color: black !important;
                padding: 2px 6px;
            }
        }
    </style>
</head>
<body>
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
            
            <div class="sidebar-menu">
                <ul class="nav flex-column">
                    <li class="nav-item">
                        <a class="nav-link active" href="current_inventory.php" data-title="Current Inventory">
                            <i class="bi bi-bar-chart-line"></i>
                            <span class="nav-text">Current Inventory</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="sales_order.php" data-title="Sales Orders">
                            <i class="bi bi-bag"></i>
                            <span class="nav-text">Sales Orders</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="pick_list_items.php" data-title="Pick List Items">
                            <i class="bi bi-list-check"></i>
                            <span class="nav-text">Pick List Items</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="bad_orders.php" data-title="Bad Orders">
                            <i class="bi bi-recycle"></i>
                            <span class="nav-text">Bad Orders</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="supplier.php" data-title="Suppliers">
                            <i class="bi bi-bar-chart-line"></i>
                            <span class="nav-text">Suppliers</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="purchase_order.php" data-title="Purchase Orders">
                            <i class="bi bi-box"></i>
                            <span class="nav-text">Purchase Orders</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="drivers.php">
                            <i class="bi bi-truck"></i>
                            <span class="nav-text">Users</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="trip_tickets.php" data-title="Trip Tickets">
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
            <!-- DASHBOARD -->
            <div id="dashboardContent" class="page-content active">
                <div class="navbar-top">
                    <!-- MOBILE MENU BUTTON -->
                    <button class="mobile-menu-btn" id="mobileMenuBtn">
                        <i class="bi bi-list"></i>
                    </button>
                    <div class="page-title">
                        <h2>Current Inventory</h2>
                        <p id="dashboardSubtitle">
                            Real-time inventory from database
                        </p>
                    </div>
                </div>

                <!-- Branch Info Alerts -->
                <?php if (!$items_branch_column_exists): ?>
                    <div class="alert alert-info alert-dismissible fade show" role="alert">
                        <i class="bi bi-info-circle"></i> 
                        <strong>Branch filtering for items not yet set up.</strong> Please run this SQL in phpMyAdmin to enable branch-specific inventory data:
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

                <!-- No Items Warning -->
                <?php if (empty($items) && $items_branch_column_exists && !$view_all_branches): ?>
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle"></i> 
                        No inventory items found for your branch. You can add new items using the "Add Item" button.
                    </div>
                <?php endif; ?>

                <!-- QUICK STATS - WITH NEW AVERAGE DAILY OFFTAKE CARD (CLICKABLE) -->
                <div class="row g-3 mb-4">
                    <!-- Stat 1: Total Inventory Value -->
                    <div class="col-xl-3 col-md-6">
                        <div class="stat-card total">
                            <i class="bi bi-coin stat-icon"></i>
                            <div class="stat-value"><?= $statInventoryValue ?></div>
                            <div class="stat-label">Total Inventory Value</div>
                            <small class="d-block mt-2"><i class="bi bi-box-seam"></i> <?= number_format($total_stock) ?> units</small>
                        </div>
                    </div>
                    
                    <!-- Stat 2: Average Daily Offtake (CLICKABLE) -->
                    <div class="col-xl-3 col-md-6">
                        <div class="stat-card offtake-card clickable" onclick="showOfftakeModal()">
                            <i class="bi bi-graph-up-arrow stat-icon"></i>
                            <div class="stat-value"><?= number_format($avg_daily_offtake, 1) ?></div>
                            <div class="stat-label">Avg Daily Offtake</div>
                            <small class="d-block mt-2"><i class="bi bi-calendar"></i> Last 30 days</small>
                        </div>
                    </div>
                    
                    <!-- Stat 3: Total - Good to Know (Clickable for supplier selector) -->
                    <div class="col-xl-3 col-md-6">
                        <div class="stat-card sales clickable" onclick="showSupplierSelector()">
                            <i class="bi bi-info-circle stat-icon"></i>
                            <div class="stat-value"><?= $suppliers_count ?></div>
                            <div class="stat-label">Total - Good to Know</div>
                            <small class="d-block mt-2"><i class="bi bi-building"></i> <?= $suppliers_count ?> suppliers</small>
                        </div>
                    </div>
                    
                    <!-- Stat 4: Needs Attention (Clickable for low stock modal) -->
                    <div class="col-xl-3 col-md-6">
                        <div class="stat-card pending clickable" onclick="showLowStockModal()">
                            <i class="bi bi-exclamation-triangle stat-icon"></i>
                            <div class="stat-value"><?= $statNeedsAttention ?></div>
                            <div class="stat-label">Needs Attention</div>
                            <small class="d-block mt-2"><?= $low_stock_count ?> low stock, <?= $out_of_stock ?> out</small>
                        </div>
                    </div>
                </div>

                <!-- SEARCH AND FILTER CONTROLS WITH VIEW TOGGLE -->
                <div class="row g-3 mb-4">
                    <div class="col-md-4">
                        <div class="search-box">
                            <i class="bi bi-search"></i>
                            <input type="text" class="form-control" id="searchInput" placeholder="Search by item code, name, or category..." onkeyup="filterItems()">
                        </div>
                    </div>
                    <div class="col-md-3">
                        <select class="form-select" id="categoryFilter" onchange="filterItems()">
                            <option value="">All Categories</option>
                            <?php 
                            if (!empty($items)) {
                                $unique_categories = array_unique(array_column($items, 'category'));
                                foreach ($unique_categories as $cat): 
                                    if (!empty($cat)):
                            ?>
                                <option value="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars($cat) ?></option>
                            <?php 
                                    endif;
                                endforeach; 
                            }
                            ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <select class="form-select" id="statusFilter" onchange="filterItems()">
                            <option value="">All Status</option>
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                            <option value="discontinued">Discontinued</option>
                        </select>
                    </div>
                    <div class="col-md-2 d-flex">
                        <select class="form-select" id="stockFilter" onchange="filterItems()" style="margin-right: 10px;">
                            <option value="">Stock Level</option>
                            <option value="low">Low Stock</option>
                            <option value="normal">Normal</option>
                            <option value="adequate">Adequate</option>
                            <option value="out">Out of Stock</option>
                        </select>
                    </div>
                </div>
                
                <!-- Filter Summary (shown when filters are active) -->
                <div id="filterSummary" style="display: none;" class="filter-summary">
                    <i class="bi bi-funnel-fill"></i>
                    <span id="filterSummaryText"></span>
                    <span class="clear-filters" onclick="clearAllFilters()">
                        <i class="bi bi-x-circle"></i> Clear Filters
                    </span>
                </div>
                
                <!-- View Toggle and Add Button -->
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div class="view-toggle">
                        <button class="view-btn active" id="viewCategoryBtn" onclick="toggleView('category')">
                            <i class="bi bi-grid"></i> By Category
                        </button>
                        <button class="view-btn" id="viewSupplierBtn" onclick="toggleView('supplier')">
                            <i class="bi bi-building"></i> By Supplier
                        </button>
                    </div>
                    <button class="btn btn-primary" onclick="showAddItemModal()">
                        <i class="bi bi-plus-circle"></i> Add Item
                    </button>
                </div>

                <!-- CATEGORY VIEW WITH TABS -->
                <div id="categoryView">
                    <?php if (empty($items)): ?>
                        <div class="empty-state text-center py-5">
                            <i class="bi bi-inbox fs-1 d-block text-muted mb-2"></i>
                            <p class="text-muted">No items found</p>
                            <button class="btn btn-primary mt-2" onclick="showAddItemModal()">
                                <i class="bi bi-plus-circle"></i> Add Item
                            </button>
                        </div>
                    <?php else: ?>
                        <!-- Category Tabs with All Categories tab -->
                        <div class="category-tabs">
                            <!-- All Categories Tab -->
                            <div class="category-tab active" 
                                 onclick="switchCategoryTab('cat-tab-all', this)"
                                 data-tab="cat-tab-all">
                                <i class="bi bi-grid"></i> 
                                All Categories
                                <span class="tab-badge"><?= $total_items ?></span>
                            </div>
                            
                            <?php 
                            foreach ($items_by_category as $category => $category_items): 
                                $tab_id = 'cat-tab-' . preg_replace('/[^a-z0-9]/i', '-', strtolower($category));
                                $item_count = count($category_items);
                            ?>
                            <div class="category-tab" 
                                 onclick="switchCategoryTab('<?= $tab_id ?>', this)"
                                 data-tab="<?= $tab_id ?>"
                                 data-category="<?= htmlspecialchars($category) ?>">
                                <i class="bi bi-folder"></i> 
                                <?= htmlspecialchars($category) ?>
                                <span class="tab-badge"><?= $item_count ?></span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <!-- All Categories Tab Content -->
                        <div id="cat-tab-all" class="tab-content active">
                            <div class="table-container">
                                <table class="table custom-table compact-table">
                                    <thead>
                                        <tr>
                                            <th class="col-image">Image</th>
                                            <th>Item Name</th>
                                            <th>Category</th>
                                            <?php if ($items_branch_column_exists && $view_all_branches): ?>
                                                <th>Branch</th>
                                            <?php endif; ?>
                                            <th>Stock</th>
                                            <th>Unit</th>
                                            <th class="col-status">Active</th>
                                            <th class="col-actions">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        foreach ($items as $item): 
                                            $stock_status = getStockStatus($item['quantity_on_hand'], $item['reorder_level']);
                                            $image_url = !empty($item['product_image_url']) ? '../uploads/products/' . $item['product_image_url'] : '';
                                        ?>
                                        <tr class="inventory-row" 
                                            data-id="<?= $item['item_id'] ?>"
                                            data-code="<?= htmlspecialchars($item['item_code']) ?>"
                                            data-name="<?= htmlspecialchars($item['item_name']) ?>"
                                            data-category="<?= htmlspecialchars($item['category'] ?? '') ?>"
                                            data-status="<?= $item['status'] ?>"
                                            data-stock="<?= $item['quantity_on_hand'] ?>"
                                            data-reorder="<?= $item['reorder_level'] ?>"
                                            data-price="<?= $item['unit_price'] ?>"
                                            data-unit="<?= $item['unit_type'] ?>"
                                            data-description="<?= htmlspecialchars($item['description'] ?? '') ?>"
                                            data-branch="<?= $item['branch_id'] ?? '' ?>">
                                            <td class="col-image">
                                                <div class="item-thumbnail">
                                                    <?php if (!empty($item['product_image_url'])): ?>
                                                        <img src="<?= '../uploads/products/' . $item['product_image_url'] ?>" alt="<?= htmlspecialchars($item['item_name']) ?>" onerror="this.onerror=null; this.style.display='none'; this.parentNode.innerHTML='<i class=\'bi bi-image\'></i>';">
                                                    <?php else: ?>
                                                        <i class="bi bi-image"></i>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td>
                                                <?= htmlspecialchars($item['item_name']) ?>
                                                <?php if (!empty($item['description'])): ?>
                                                    <small class="d-block text-muted"><?= htmlspecialchars($item['description']) ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td><?= htmlspecialchars($item['category'] ?? 'Uncategorized') ?></td>
                                            <?php if ($items_branch_column_exists && $view_all_branches): ?>
                                                <td>
                                                    <span class="badge bg-info">
                                                        Branch <?= $item['branch_id'] ?? 'N/A' ?>
                                                    </span>
                                                </td>
                                            <?php endif; ?>
                                            <td>
                                                <span class="<?= $item['quantity_on_hand'] <= $item['reorder_level'] ? 'text-danger fw-bold' : '' ?>">
                                                    <?= number_format($item['quantity_on_hand']) ?>
                                                </span>
                                                <span class="badge <?= $stock_status['class'] ?> ms-1"><?= $stock_status['label'] ?></span>
                                            </td>
                                            <td><?= ucfirst(str_replace('_', ' ', $item['unit_type'])) ?></td>
                                            <td class="col-status">
                                                <div class="status-toggle">
                                                    <label class="toggle-switch">
                                                        <input type="checkbox" class="status-checkbox" 
                                                               data-id="<?= $item['item_id'] ?>"
                                                               <?= $item['status'] === 'active' ? 'checked' : '' ?>
                                                               onchange="toggleItemStatus(<?= $item['item_id'] ?>, this)">
                                                        <span class="toggle-slider"></span>
                                                    </label>
                                                </div>
                                            </td>
                                            <td class="col-actions">
                                                <div class="action-btn" role="group">
                                                    <button class="btn-action btn-view" onclick="viewItem(<?= $item['item_id'] ?>)" title="View">
                                                        <i class="bi bi-eye"></i>
                                                    </button>
                                                    <button class="btn-action btn-edit" onclick="editItem(<?= $item['item_id'] ?>)" title="Edit">
                                                        <i class="bi bi-pencil"></i>
                                                    </button>
                                                    <button class="btn-action btn-delete" onclick="deleteItem(<?= $item['item_id'] ?>)" title="Delete">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        
                        <!-- Individual Category Tab Contents -->
                        <?php 
                        foreach ($items_by_category as $category => $category_items): 
                            $tab_id = 'cat-tab-' . preg_replace('/[^a-z0-9]/i', '-', strtolower($category));
                        ?>
                        <div id="<?= $tab_id ?>" class="tab-content" data-category="<?= htmlspecialchars($category) ?>">
                            <div class="table-container">
                                <table class="table custom-table compact-table">
                                    <thead>
                                        <tr>
                                            <th class="col-image">Image</th>
                                            <th>Item Name</th>
                                            <th>Category</th>
                                            <?php if ($items_branch_column_exists && $view_all_branches): ?>
                                                <th>Branch</th>
                                            <?php endif; ?>
                                            <th>Stock</th>
                                            <th>Unit</th>
                                            <th class="col-status">Active</th>
                                            <th class="col-actions">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($category_items as $item): 
                                            $stock_status = getStockStatus($item['quantity_on_hand'], $item['reorder_level']);
                                            $image_url = !empty($item['product_image_url']) ? '../uploads/products/' . $item['product_image_url'] : '';
                                        ?>
                                        <tr class="inventory-row" 
                                            data-id="<?= $item['item_id'] ?>"
                                            data-code="<?= htmlspecialchars($item['item_code']) ?>"
                                            data-name="<?= htmlspecialchars($item['item_name']) ?>"
                                            data-category="<?= htmlspecialchars($item['category'] ?? '') ?>"
                                            data-status="<?= $item['status'] ?>"
                                            data-stock="<?= $item['quantity_on_hand'] ?>"
                                            data-reorder="<?= $item['reorder_level'] ?>"
                                            data-price="<?= $item['unit_price'] ?>"
                                            data-unit="<?= $item['unit_type'] ?>"
                                            data-description="<?= htmlspecialchars($item['description'] ?? '') ?>"
                                            data-branch="<?= $item['branch_id'] ?? '' ?>">
                                            <td class="col-image">
                                                <div class="item-thumbnail">
                                                    <?php if (!empty($item['product_image_url'])): ?>
                                                        <img src="<?= '../uploads/products/' . $item['product_image_url'] ?>" alt="<?= htmlspecialchars($item['item_name']) ?>" onerror="this.onerror=null; this.style.display='none'; this.parentNode.innerHTML='<i class=\'bi bi-image\'></i>';">
                                                    <?php else: ?>
                                                        <i class="bi bi-image"></i>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td>
                                                <?= htmlspecialchars($item['item_name']) ?>
                                                <?php if (!empty($item['description'])): ?>
                                                    <small class="d-block text-muted"><?= htmlspecialchars($item['description']) ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td><?= htmlspecialchars($item['category'] ?? 'Uncategorized') ?></td>
                                            <?php if ($items_branch_column_exists && $view_all_branches): ?>
                                                <td>
                                                    <span class="badge bg-info">
                                                        Branch <?= $item['branch_id'] ?? 'N/A' ?>
                                                    </span>
                                                </td>
                                            <?php endif; ?>
                                            <td>
                                                <span class="<?= $item['quantity_on_hand'] <= $item['reorder_level'] ? 'text-danger fw-bold' : '' ?>">
                                                    <?= number_format($item['quantity_on_hand']) ?>
                                                </span>
                                                <span class="badge <?= $stock_status['class'] ?> ms-1"><?= $stock_status['label'] ?></span>
                                            </td>
                                            <td><?= ucfirst(str_replace('_', ' ', $item['unit_type'])) ?></td>
                                            <td class="col-status">
                                                <div class="status-toggle">
                                                    <label class="toggle-switch">
                                                        <input type="checkbox" class="status-checkbox" 
                                                               data-id="<?= $item['item_id'] ?>"
                                                               <?= $item['status'] === 'active' ? 'checked' : '' ?>
                                                               onchange="toggleItemStatus(<?= $item['item_id'] ?>, this)">
                                                        <span class="toggle-slider"></span>
                                                    </label>
                                                </div>
                                            </td>
                                            <td class="col-actions">
                                                <div class="action-btn" role="group">
                                                    <button class="btn-action btn-view" onclick="viewItem(<?= $item['item_id'] ?>)" title="View">
                                                        <i class="bi bi-eye"></i>
                                                    </button>
                                                    <button class="btn-action btn-edit" onclick="editItem(<?= $item['item_id'] ?>)" title="Edit">
                                                        <i class="bi bi-pencil"></i>
                                                    </button>
                                                    <button class="btn-action btn-delete" onclick="deleteItem(<?= $item['item_id'] ?>)" title="Delete">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <!-- No items message for this tab (hidden by default) -->
                            <div class="no-items-message" id="no-items-<?= $tab_id ?>" style="display: none;">
                                <i class="bi bi-inbox"></i>
                                <h5>No items match your filters</h5>
                                <p class="text-muted">Try adjusting your search or filter criteria</p>
                                <button class="btn btn-sm btn-outline-primary" onclick="clearAllFilters()">
                                    <i class="bi bi-x-circle"></i> Clear Filters
                                </button>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <!-- SUPPLIER VIEW WITH TABS (data from suppliers table + associated items) -->
                <div id="supplierView" style="display: none;">
                    <?php if (empty($supplier_items)): ?>
                        <div class="empty-state text-center py-5">
                            <i class="bi bi-building fs-1 d-block text-muted mb-2"></i>
                            <p class="text-muted">No items found for any supplier</p>
                        </div>
                    <?php else: ?>
                        <!-- Supplier Tabs with All Suppliers tab -->
                        <div class="category-tabs">
                            <!-- All Suppliers Tab -->
                            <div class="category-tab active" 
                                 onclick="switchSupplierTab('sup-tab-all', this)"
                                 data-tab="sup-tab-all">
                                <i class="bi bi-building"></i> 
                                All Suppliers
                                <span class="tab-badge"><?= count($supplier_items) ?></span>
                            </div>
                            
                            <?php 
                            foreach ($items_by_supplier as $supplier => $supplier_items_group): 
                                $tab_id = 'sup-tab-' . preg_replace('/[^a-z0-9]/i', '-', strtolower($supplier));
                                $item_count = count($supplier_items_group);
                            ?>
                            <div class="category-tab" 
                                 onclick="switchSupplierTab('<?= $tab_id ?>', this)"
                                 data-tab="<?= $tab_id ?>"
                                 data-supplier="<?= htmlspecialchars($supplier) ?>">
                                <i class="bi bi-building"></i> 
                                <?= htmlspecialchars($supplier) ?>
                                <span class="tab-badge"><?= $item_count ?></span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <!-- All Suppliers Tab Content -->
                        <div id="sup-tab-all" class="tab-content active">
                            <div class="table-container">
                                <table class="table custom-table compact-table">
                                    <thead>
                                        <tr>
                                            <th class="col-image">Image</th>
                                            <th>Item Name</th>
                                            <th>Category</th>
                                            <?php if ($items_branch_column_exists && $view_all_branches): ?>
                                                <th>Branch</th>
                                            <?php endif; ?>
                                            <th>Stock</th>
                                            <th>Unit</th>
                                            <th class="col-status">Active</th>
                                            <th class="col-actions">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($supplier_items as $item): 
                                            $stock_status = getStockStatus($item['quantity_on_hand'], $item['reorder_level']);
                                        ?>
                                        <tr class="inventory-row" 
                                            data-id="<?= $item['item_id'] ?>"
                                            data-code="<?= htmlspecialchars($item['item_code']) ?>"
                                            data-name="<?= htmlspecialchars($item['item_name']) ?>"
                                            data-category="<?= htmlspecialchars($item['category'] ?? '') ?>"
                                            data-status="<?= $item['status'] ?>"
                                            data-stock="<?= $item['quantity_on_hand'] ?>"
                                            data-reorder="<?= $item['reorder_level'] ?>"
                                            data-price="0"
                                            data-unit="<?= $item['unit_type'] ?>"
                                            data-branch="<?= $item['branch_id'] ?? '' ?>">
                                            <td class="col-image">
                                                <div class="item-thumbnail">
                                                    <?php if (!empty($item['product_image_url'])): ?>
                                                        <img src="<?= '../uploads/products/' . $item['product_image_url'] ?>" alt="<?= htmlspecialchars($item['item_name']) ?>" onerror="this.onerror=null; this.style.display='none'; this.parentNode.innerHTML='<i class=\'bi bi-image\'></i>';">
                                                    <?php else: ?>
                                                        <i class="bi bi-image"></i>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td><?= htmlspecialchars($item['item_name']) ?></td>
                                            <td><?= htmlspecialchars($item['category'] ?? 'Uncategorized') ?></td>
                                            <?php if ($items_branch_column_exists && $view_all_branches): ?>
                                                <td>
                                                    <span class="badge bg-info">
                                                        Branch <?= $item['branch_id'] ?? 'N/A' ?>
                                                    </span>
                                                </td>
                                            <?php endif; ?>
                                            <td>
                                                <span class="<?= $item['quantity_on_hand'] <= $item['reorder_level'] ? 'text-danger fw-bold' : '' ?>">
                                                    <?= number_format($item['quantity_on_hand']) ?>
                                                </span>
                                                <span class="badge <?= $stock_status['class'] ?> ms-1"><?= $stock_status['label'] ?></span>
                                            </td>
                                            <td><?= ucfirst(str_replace('_', ' ', $item['unit_type'])) ?></td>
                                            <td class="col-status">
                                                <div class="status-toggle">
                                                    <label class="toggle-switch">
                                                        <input type="checkbox" class="status-checkbox" 
                                                               data-id="<?= $item['item_id'] ?>"
                                                               <?= $item['status'] === 'active' ? 'checked' : '' ?>
                                                               onchange="toggleItemStatus(<?= $item['item_id'] ?>, this)">
                                                        <span class="toggle-slider"></span>
                                                    </label>
                                                </div>
                                            </td>
                                            <td class="col-actions">
                                                <div class="action-btn" role="group">
                                                    <button class="btn-action btn-view" onclick="viewItem(<?= $item['item_id'] ?>)" title="View">
                                                        <i class="bi bi-eye"></i>
                                                    </button>
                                                    <button class="btn-action btn-edit" onclick="editItem(<?= $item['item_id'] ?>)" title="Edit">
                                                        <i class="bi bi-pencil"></i>
                                                    </button>
                                                    <button class="btn-action btn-delete" onclick="deleteItem(<?= $item['item_id'] ?>)" title="Delete">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        
                        <!-- Individual Supplier Tab Contents -->
                        <?php 
                        foreach ($items_by_supplier as $supplier => $supplier_items_group): 
                            $tab_id = 'sup-tab-' . preg_replace('/[^a-z0-9]/i', '-', strtolower($supplier));
                        ?>
                        <div id="<?= $tab_id ?>" class="tab-content" data-supplier="<?= htmlspecialchars($supplier) ?>">
                            <div class="table-container">
                                <table class="table custom-table compact-table">
                                    <thead>
                                        <tr>
                                            <th class="col-image">Image</th>
                                            <th>Item Name</th>
                                            <th>Category</th>
                                            <?php if ($items_branch_column_exists && $view_all_branches): ?>
                                                <th>Branch</th>
                                            <?php endif; ?>
                                            <th>Stock</th>
                                            <th>Unit</th>
                                            <th class="col-status">Active</th>
                                            <th class="col-actions">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($supplier_items_group as $item): 
                                            $stock_status = getStockStatus($item['quantity_on_hand'], $item['reorder_level']);
                                        ?>
                                        <tr class="inventory-row" 
                                            data-id="<?= $item['item_id'] ?>"
                                            data-code="<?= htmlspecialchars($item['item_code']) ?>"
                                            data-name="<?= htmlspecialchars($item['item_name']) ?>"
                                            data-category="<?= htmlspecialchars($item['category'] ?? '') ?>"
                                            data-status="<?= $item['status'] ?>"
                                            data-stock="<?= $item['quantity_on_hand'] ?>"
                                            data-reorder="<?= $item['reorder_level'] ?>"
                                            data-price="0"
                                            data-unit="<?= $item['unit_type'] ?>"
                                            data-branch="<?= $item['branch_id'] ?? '' ?>">
                                            <td class="col-image">
                                                <div class="item-thumbnail">
                                                    <?php if (!empty($item['product_image_url'])): ?>
                                                        <img src="<?= '../uploads/products/' . $item['product_image_url'] ?>" alt="<?= htmlspecialchars($item['item_name']) ?>" onerror="this.onerror=null; this.style.display='none'; this.parentNode.innerHTML='<i class=\'bi bi-image\'></i>';">
                                                    <?php else: ?>
                                                        <i class="bi bi-image"></i>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td><?= htmlspecialchars($item['item_name']) ?></td>
                                            <td><?= htmlspecialchars($item['category'] ?? 'Uncategorized') ?></td>
                                            <?php if ($items_branch_column_exists && $view_all_branches): ?>
                                                <td>
                                                    <span class="badge bg-info">
                                                        Branch <?= $item['branch_id'] ?? 'N/A' ?>
                                                    </span>
                                                </td>
                                            <?php endif; ?>
                                            <td>
                                                <span class="<?= $item['quantity_on_hand'] <= $item['reorder_level'] ? 'text-danger fw-bold' : '' ?>">
                                                    <?= number_format($item['quantity_on_hand']) ?>
                                                </span>
                                                <span class="badge <?= $stock_status['class'] ?> ms-1"><?= $stock_status['label'] ?></span>
                                            </td>
                                            <td><?= ucfirst(str_replace('_', ' ', $item['unit_type'])) ?></td>
                                            <td class="col-status">
                                                <div class="status-toggle">
                                                    <label class="toggle-switch">
                                                        <input type="checkbox" class="status-checkbox" 
                                                               data-id="<?= $item['item_id'] ?>"
                                                               <?= $item['status'] === 'active' ? 'checked' : '' ?>
                                                               onchange="toggleItemStatus(<?= $item['item_id'] ?>, this)">
                                                        <span class="toggle-slider"></span>
                                                    </label>
                                                </div>
                                            </td>
                                            <td class="col-actions">
                                                <div class="action-btn" role="group">
                                                    <button class="btn-action btn-view" onclick="viewItem(<?= $item['item_id'] ?>)" title="View">
                                                        <i class="bi bi-eye"></i>
                                                    </button>
                                                    <button class="btn-action btn-edit" onclick="editItem(<?= $item['item_id'] ?>)" title="Edit">
                                                        <i class="bi bi-pencil"></i>
                                                    </button>
                                                    <button class="btn-action btn-delete" onclick="deleteItem(<?= $item['item_id'] ?>)" title="Delete">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <!-- No items message for this tab (hidden by default) -->
                            <div class="no-items-message" id="no-items-<?= $tab_id ?>" style="display: none;">
                                <i class="bi bi-inbox"></i>
                                <h5>No items match your filters</h5>
                                <p class="text-muted">Try adjusting your search or filter criteria</p>
                                <button class="btn btn-sm btn-outline-primary" onclick="clearAllFilters()">
                                    <i class="bi bi-x-circle"></i> Clear Filters
                                </button>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <!-- LOW STOCK ALERT - REAL DATA -->
                <?php if ($low_stock_count > 0): ?>
                <div class="row g-3 mt-3">
                    <div class="col-12">
                        <div class="alert alert-warning alert-dismissible fade show mb-0" role="alert">
                            <i class="bi bi-exclamation-triangle-fill me-2"></i>
                            <strong>Low Stock Alert!</strong> <?= $low_stock_count ?> item(s) are below reorder level.
                            <?php if ($items_branch_column_exists && !$view_all_branches): ?>
                                in your branch.
                            <?php endif; ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ADD ITEM MODAL -->
    <div class="modal fade" id="itemModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="itemModalTitle"><i class="bi bi-plus-circle me-2"></i>Add New Item</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="itemForm" enctype="multipart/form-data">
                        <input type="hidden" id="itemId">
                        <?php if ($items_branch_column_exists && !$view_all_branches && $branch_id > 0): ?>
                            <input type="hidden" name="branch_id" value="<?= $branch_id ?>">
                        <?php endif; ?>
                        
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle"></i>
                            <?php if ($items_branch_column_exists && !$view_all_branches && $branch_id > 0): ?>
                                Adding item to Branch <?= $branch_id ?>
                            <?php else: ?>
                                Item code is auto-generated
                            <?php endif; ?>
                        </div>
                        
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="itemCode" class="form-label">Item Code *</label>
                                <input type="text" class="form-control" id="itemCode" value="<?= $next_item_code ?>" readonly required>
                                <small class="text-muted">Auto-generated</small>
                            </div>
                            <div class="col-md-6">
                                <label for="itemName" class="form-label">Item Name *</label>
                                <input type="text" class="form-control" id="itemName" required>
                            </div>
                            <div class="col-12">
                                <label for="description" class="form-label">Description</label>
                                <textarea class="form-control" id="description" rows="2"></textarea>
                            </div>
                            <div class="col-md-4">
                                <label for="category" class="form-label">Category</label>
                                <select class="form-select" id="category">
                                    <option value="">Select Category</option>
                                    <option value="Cement">Cement</option>
                                    <option value="Oil">Oil</option>
                                    <option value="General">General</option>
                                </select>
                            </div>
                            <div class="col-md-8">
                                <label for="itemPicture" class="form-label">Item Picture (Optional)</label>
                                <input type="file" class="form-control" id="itemPicture" accept="image/*">
                                <small class="text-muted">Supported formats: JPG, PNG, GIF, WebP (Max 5MB)</small>
                            </div>
                            <div class="col-md-4">
                                <label for="stock" class="form-label">Current Stock *</label>
                                <input type="number" class="form-control" id="stock" min="0" required>
                            </div>
                            <div class="col-md-4">
                                <label for="unitType" class="form-label">Unit Type *</label>
                                <select class="form-select" id="unitType" required>
                                    <option value="piece">Piece</option>
                                    <option value="case">Case</option>
                                    <option value="box">Box</option>
                                    <option value="carton">Carton</option>
                                    <option value="inner-pack">Inner Pack</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label for="unitPrice" class="form-label">Unit Price (₱) *</label>
                                <input type="number" class="form-control" id="unitPrice" min="0" step="0.01" required>
                            </div>
                            <div class="col-md-4">
                                <label for="reorderLevel" class="form-label">Reorder Level *</label>
                                <input type="number" class="form-control" id="reorderLevel" min="0" required>
                            </div>
                            <div class="col-md-4">
                                <label for="status" class="form-label">Status</label>
                                <select class="form-select" id="status">
                                    <option value="active">Active</option>
                                    <option value="inactive">Inactive</option>
                                    <option value="discontinued">Discontinued</option>
                                </select>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="saveItem()">Save Item</button>
                </div>
            </div>
        </div>
    </div>

    <!-- VIEW ITEM MODAL -->
    <div class="modal fade" id="viewItemModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title"><i class="bi bi-eye me-2"></i>Item Details</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row" id="viewItemContent">
                        <!-- Content will be populated by JavaScript -->
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-warning" onclick="editFromView()">Edit Item</button>
                </div>
            </div>
        </div>
    </div>

   <!-- EDIT ITEM MODAL - CORRECTED VERSION -->
<div class="modal fade" id="editItemModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>Edit Item</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="editItemForm" enctype="multipart/form-data">
                    <input type="hidden" id="editItemId">
                    
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="editItemCode" class="form-label">Item Code</label>
                            <input type="text" class="form-control" id="editItemCode" readonly>
                        </div>
                        <div class="col-md-6">
                            <label for="editItemName" class="form-label">Item Name *</label>
                            <input type="text" class="form-control" id="editItemName" required>
                        </div>
                        <div class="col-12">
                            <label for="editDescription" class="form-label">Description</label>
                            <textarea class="form-control" id="editDescription" rows="2"></textarea>
                        </div>
                        <div class="col-md-4">
                            <label for="editCategory" class="form-label">Category</label>
                            <select class="form-select" id="editCategory">
                                <option value="">Select Category</option>
                                <option value="Cement">Cement</option>
                                <option value="Oil">Oil</option>
                                <option value="General">General</option>
                            </select>
                        </div>
                        <div class="col-md-8">
                            <label for="editItemPicture" class="form-label">Item Picture (Optional)</label>
                            <input type="file" class="form-control" id="editItemPicture" accept="image/*">
                            <small class="text-muted">Supported formats: JPG, PNG, GIF, WebP (Max 5MB)</small>
                            <div id="currentItemPictureDiv" class="mt-2" style="display:none;">
                                <img id="currentItemPicture" src="" alt="Current item picture" style="max-width: 100px; max-height: 100px; border-radius: 4px;">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label for="editStock" class="form-label">Current Stock *</label>
                            <input type="number" class="form-control" id="editStock" min="0" required>
                        </div>
                        <div class="col-md-4">
                            <label for="editUnitType" class="form-label">Unit Type *</label>
                            <select class="form-select" id="editUnitType" required>
                                <option value="piece">Piece</option>
                                <option value="case">Case</option>
                                <option value="box">Box</option>
                                <option value="carton">Carton</option>
                                <option value="inner-pack">Inner Pack</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label for="editUnitPrice" class="form-label">Unit Price (₱) *</label>
                            <input type="number" class="form-control" id="editUnitPrice" min="0" step="0.01" required>
                        </div>
                        <div class="col-md-4">
                            <label for="editReorderLevel" class="form-label">Reorder Level *</label>
                            <input type="number" class="form-control" id="editReorderLevel" min="0" required>
                        </div>
                        <div class="col-md-4">
                            <label for="editStatus" class="form-label">Status</label>
                            <select class="form-select" id="editStatus">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                                <option value="discontinued">Discontinued</option>
                            </select>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="updateItem()">Update Item</button>
            </div>
        </div>
    </div>
</div>

    <!-- DELETE CONFIRMATION MODAL -->
    <div class="modal fade" id="deleteItemModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title"><i class="bi bi-trash me-2"></i>Confirm Delete</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to delete this item?</p>
                    <p class="fw-bold" id="deleteItemCode"></p>
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        This action cannot be undone.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-danger" onclick="confirmDelete()">Delete Item</button>
                </div>
            </div>
        </div>
    </div>

    <!-- SUPPLIER SELECTOR MODAL -->
    <div class="modal fade" id="supplierSelectorModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header modal-header-success">
                    <h5 class="modal-title"><i class="bi bi-building me-2"></i>Supplier Information</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-4">
                        <label for="supplierSelect" class="form-label fw-bold">Select Supplier</label>
                        <select class="form-select select2-supplier" id="supplierSelect" style="width: 100%;">
                            <option value="">-- Choose Supplier --</option>
                        </select>
                    </div>
                    
                    <div id="supplierDetailsContainer" style="display: none;">
                        <div class="supplier-info mb-3" id="supplierSummary">
                            <!-- Supplier summary will be loaded here -->
                        </div>
                        
                        <h6 class="fw-bold mt-4 mb-3"><i class="bi bi-receipt"></i> Purchase Orders</h6>
                        <div id="supplierPurchaseOrders" class="modal-supplier-details">
                            <!-- Purchase orders will be loaded here -->
                        </div>
                    </div>
                    
                    <div id="noSupplierSelected" class="text-center py-4 text-muted">
                        <i class="bi bi-building" style="font-size: 48px;"></i>
                        <p class="mt-3">Select a supplier to view details and purchase order history</p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- LOW STOCK MODAL -->
    <div class="modal fade" id="lowStockModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header modal-header-warning">
                    <h5 class="modal-title"><i class="bi bi-exclamation-triangle me-2"></i>Low Stock Items</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-warning mb-4">
                        <i class="bi bi-info-circle me-2"></i>
                        These items are below their reorder level and need attention.
                    </div>
                    
                    <div id="lowStockItemsContainer" class="modal-supplier-details">
                        <!-- Low stock items will be loaded here -->
                    </div>
                    
                    <div id="noLowStockItems" class="text-center py-4 text-muted" style="display: none;">
                        <i class="bi bi-check-circle" style="font-size: 48px;"></i>
                        <p class="mt-3">No low stock items found</p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-primary" onclick="filterLowStock()">
                        <i class="bi bi-funnel"></i> Show in Table
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- OFFTAKE MODAL - UPDATED (NO GRAPH) -->
    <div class="modal fade" id="offtakeModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header modal-header-offtake">
                    <h5 class="modal-title"><i class="bi bi-graph-up-arrow me-2"></i>Average Daily Offtake Analysis</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <!-- Date Range Filter -->
                    <div class="date-filter-row">
                        <div class="row g-3 align-items-end">
                            <div class="col-md-4">
                                <label for="offtakeStartDate" class="form-label fw-bold">Start Date</label>
                                <input type="date" class="form-control" id="offtakeStartDate" value="<?= date('Y-m-d', strtotime('-30 days')) ?>">
                            </div>
                            <div class="col-md-4">
                                <label for="offtakeEndDate" class="form-label fw-bold">End Date</label>
                                <input type="date" class="form-control" id="offtakeEndDate" value="<?= date('Y-m-d') ?>">
                            </div>
                            <div class="col-md-4">
                                <button class="btn btn-primary w-100" onclick="loadOfftakeData()">
                                    <i class="bi bi-funnel"></i> Apply Filter
                                </button>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Summary Cards -->
                    <div class="row g-3 mb-4" id="offtakeSummaryContainer">
                        <div class="col-md-3">
                            <div class="offtake-summary-card">
                                <div class="offtake-summary-value" id="summaryAvgDaily">0</div>
                                <div class="offtake-summary-label">Avg Daily Offtake</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="offtake-summary-card">
                                <div class="offtake-summary-value" id="summaryTotalQty">0</div>
                                <div class="offtake-summary-label">Total Quantity</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="offtake-summary-card">
                                <div class="offtake-summary-value" id="summaryActiveDays">0</div>
                                <div class="offtake-summary-label">Active Days</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="offtake-summary-card">
                                <div class="offtake-summary-value" id="summaryPerItem">0</div>
                                <div class="offtake-summary-label">Per Item Avg</div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Data Table -->
                    <div class="card">
                        <div class="card-header bg-light d-flex justify-content-between align-items-center">
                            <h6 class="mb-0 fw-bold"><i class="bi bi-table"></i> Daily Breakdown</h6>
                            <div>
                                <button class="btn btn-sm btn-outline-success me-2" onclick="exportOfftakeToExcel()">
                                    <i class="bi bi-file-earmark-excel"></i> Export
                                </button>
                                <button class="btn btn-sm btn-outline-primary" onclick="printOfftakeReport()">
                                    <i class="bi bi-printer"></i> Print
                                </button>
                            </div>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-sm offtake-table mb-0">
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th class="text-center">Orders</th>
                                            <th class="text-center">Quantity</th>
                                            <th class="text-end">Amount (₱)</th>
                                        </tr>
                                    </thead>
                                    <tbody id="offtakeTableBody">
                                        <tr>
                                            <td colspan="4" class="text-center py-4 text-muted">
                                                <i class="bi bi-arrow-up-circle fs-4 d-block mb-2"></i>
                                                Select date range to view data
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Date Range Info -->
                    <div class="mt-3 text-end text-muted small">
                        <i class="bi bi-info-circle"></i> Showing data for <span id="dateRangeDisplay">selected period</span>
                    </div>
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
    // ========== GLOBAL VARIABLES ==========
    let currentItemId = null;
    let currentView = 'category'; // 'category' or 'supplier'
    const branchId = <?php echo $branch_id; ?>;
    const viewAllBranches = <?php echo $view_all_branches ? 'true' : 'false'; ?>;
    const itemsBranchColumnExists = <?php echo $items_branch_column_exists ? 'true' : 'false'; ?>;
    const logoBase64 = '<?php echo $logo_base64; ?>';
    let suppliersList = [];
    
    // Track active filters
    let activeFilters = {
        search: '',
        category: '',
        status: '',
        stockLevel: ''
    };
    
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

    // ========== VIEW TOGGLE FUNCTIONS ==========
    function toggleView(view) {
        currentView = view;
        
        // Update button states
        document.getElementById('viewCategoryBtn').classList.remove('active');
        document.getElementById('viewSupplierBtn').classList.remove('active');
        
        if (view === 'category') {
            document.getElementById('viewCategoryBtn').classList.add('active');
            document.getElementById('categoryView').style.display = 'block';
            document.getElementById('supplierView').style.display = 'none';
        } else {
            document.getElementById('viewSupplierBtn').classList.add('active');
            document.getElementById('categoryView').style.display = 'none';
            document.getElementById('supplierView').style.display = 'block';
        }
        
        // Re-apply filters to the active view
        filterItems();
    }

    // ========== CATEGORY TAB FUNCTIONS ==========
    function switchCategoryTab(tabId, element) {
        // Remove active class from all category tabs
        document.querySelectorAll('#categoryView .category-tab').forEach(tab => {
            tab.classList.remove('active');
        });
        
        // Add active class to clicked tab
        element.classList.add('active');
        
        // Hide all category tab content
        document.querySelectorAll('#categoryView .tab-content').forEach(content => {
            content.classList.remove('active');
        });
        
        // Show selected tab content
        document.getElementById(tabId).classList.add('active');
        
        // Re-apply filters to the newly active tab
        filterItems();
    }

    // ========== SUPPLIER TAB FUNCTIONS ==========
    function switchSupplierTab(tabId, element) {
        // Remove active class from all supplier tabs
        document.querySelectorAll('#supplierView .category-tab').forEach(tab => {
            tab.classList.remove('active');
        });
        
        // Add active class to clicked tab
        element.classList.add('active');
        
        // Hide all supplier tab content
        document.querySelectorAll('#supplierView .tab-content').forEach(content => {
            content.classList.remove('active');
        });
        
        // Show selected tab content
        document.getElementById(tabId).classList.add('active');
        
        // Re-apply filters to the newly active tab
        filterItems();
    }

    // ========== FILTER FUNCTIONS ==========
    function filterItems() {
        // Get filter values
        const searchTerm = document.getElementById('searchInput').value.toLowerCase();
        const category = document.getElementById('categoryFilter').value;
        const status = document.getElementById('statusFilter').value;
        const stockLevel = document.getElementById('stockFilter').value;
        
        // Update active filters
        activeFilters = {
            search: searchTerm,
            category: category,
            status: status,
            stockLevel: stockLevel
        };
        
        // Update filter summary
        updateFilterSummary();
        
        if (currentView === 'category') {
            filterCategoryView();
        } else {
            filterSupplierView();
        }
    }
    
    function filterCategoryView() {
        const activeTab = document.querySelector('#categoryView .tab-content.active');
        if (!activeTab) return;
        
        const rows = activeTab.querySelectorAll('.inventory-row');
        let visibleCount = 0;
        
        rows.forEach(row => {
            const code = row.dataset.code.toLowerCase();
            const name = row.dataset.name.toLowerCase();
            const rowCategory = row.dataset.category?.toLowerCase() || '';
            const rowStatus = row.dataset.status;
            const stock = parseInt(row.dataset.stock);
            const reorder = parseInt(row.dataset.reorder);
            
            // Search filter
            let matchesSearch = activeFilters.search === '' || 
                code.includes(activeFilters.search) || 
                name.includes(activeFilters.search);
            
            // Category filter (overrides tab selection)
            let matchesCategory = activeFilters.category === '' || 
                rowCategory === activeFilters.category.toLowerCase();
            
            // Status filter
            let matchesStatus = activeFilters.status === '' || 
                rowStatus === activeFilters.status;
            
            // Stock level filter
            let matchesStock = true;
            if (activeFilters.stockLevel === 'low') {
                matchesStock = stock <= reorder && stock > 0;
            } else if (activeFilters.stockLevel === 'normal') {
                matchesStock = stock > reorder && stock <= reorder * 2;
            } else if (activeFilters.stockLevel === 'adequate') {
                matchesStock = stock > reorder * 2;
            } else if (activeFilters.stockLevel === 'out') {
                matchesStock = stock <= 0;
            }
            
            const showRow = matchesSearch && matchesCategory && matchesStatus && matchesStock;
            
            if (showRow) {
                row.style.display = '';
                visibleCount++;
            } else {
                row.style.display = 'none';
            }
        });
        
        // Show/hide no items message for this tab
        const tabId = activeTab.id;
        const noItemsMsg = document.getElementById(`no-items-${tabId}`);
        if (noItemsMsg) {
            if (visibleCount === 0) {
                noItemsMsg.style.display = 'block';
            } else {
                noItemsMsg.style.display = 'none';
            }
        }
    }
    
    function filterSupplierView() {
        const activeTab = document.querySelector('#supplierView .tab-content.active');
        if (!activeTab) return;
        
        const rows = activeTab.querySelectorAll('.inventory-row');
        let visibleCount = 0;
        
        rows.forEach(row => {
            const code = row.dataset.code.toLowerCase();
            const name = row.dataset.name.toLowerCase();
            const rowCategory = row.dataset.category?.toLowerCase() || '';
            const rowStatus = row.dataset.status;
            const stock = parseInt(row.dataset.stock);
            const reorder = parseInt(row.dataset.reorder);
            
            // Search filter
            let matchesSearch = activeFilters.search === '' || 
                code.includes(activeFilters.search) || 
                name.includes(activeFilters.search);
            
            // Category filter
            let matchesCategory = activeFilters.category === '' || 
                rowCategory === activeFilters.category.toLowerCase();
            
            // Status filter
            let matchesStatus = activeFilters.status === '' || 
                rowStatus === activeFilters.status;
            
            // Stock level filter
            let matchesStock = true;
            if (activeFilters.stockLevel === 'low') {
                matchesStock = stock <= reorder && stock > 0;
            } else if (activeFilters.stockLevel === 'normal') {
                matchesStock = stock > reorder && stock <= reorder * 2;
            } else if (activeFilters.stockLevel === 'adequate') {
                matchesStock = stock > reorder * 2;
            } else if (activeFilters.stockLevel === 'out') {
                matchesStock = stock <= 0;
            }
            
            const showRow = matchesSearch && matchesCategory && matchesStatus && matchesStock;
            
            if (showRow) {
                row.style.display = '';
                visibleCount++;
            } else {
                row.style.display = 'none';
            }
        });
        
        // Show/hide no items message for this tab
        const tabId = activeTab.id;
        const noItemsMsg = document.getElementById(`no-items-${tabId}`);
        if (noItemsMsg) {
            if (visibleCount === 0) {
                noItemsMsg.style.display = 'block';
            } else {
                noItemsMsg.style.display = 'none';
            }
        }
    }
    
    function updateFilterSummary() {
        const filterSummary = document.getElementById('filterSummary');
        const filterSummaryText = document.getElementById('filterSummaryText');
        
        const activeFilterCount = Object.values(activeFilters).filter(v => v !== '').length;
        
        if (activeFilterCount > 0) {
            let summaryParts = [];
            
            if (activeFilters.search) {
                summaryParts.push(`Search: "${activeFilters.search}"`);
            }
            if (activeFilters.category) {
                const categorySelect = document.getElementById('categoryFilter');
                const selectedOption = categorySelect.options[categorySelect.selectedIndex];
                summaryParts.push(`Category: ${selectedOption.text}`);
            }
            if (activeFilters.status) {
                const statusSelect = document.getElementById('statusFilter');
                const selectedOption = statusSelect.options[statusSelect.selectedIndex];
                summaryParts.push(`Status: ${selectedOption.text}`);
            }
            if (activeFilters.stockLevel) {
                const stockSelect = document.getElementById('stockFilter');
                const selectedOption = stockSelect.options[stockSelect.selectedIndex];
                summaryParts.push(`Stock: ${selectedOption.text}`);
            }
            
            filterSummaryText.textContent = summaryParts.join(' • ');
            filterSummary.style.display = 'inline-flex';
        } else {
            filterSummary.style.display = 'none';
        }
    }
    
    function clearAllFilters() {
        // Reset all filter inputs
        document.getElementById('searchInput').value = '';
        document.getElementById('categoryFilter').value = '';
        document.getElementById('statusFilter').value = '';
        document.getElementById('stockFilter').value = '';
        
        // Reset active filters
        activeFilters = {
            search: '',
            category: '',
            status: '',
            stockLevel: ''
        };
        
        // Hide filter summary
        document.getElementById('filterSummary').style.display = 'none';
        
        // Re-apply filters (this will show all items)
        filterItems();
    }

    // ========== ITEM STATUS TOGGLE ==========
    function toggleItemStatus(itemId, checkbox) {
        const newStatus = checkbox.checked ? 'active' : 'inactive';
        
        showLoading();
        
        const formData = new FormData();
        formData.append('action', 'toggle_status');
        formData.append('item_id', itemId);
        formData.append('status', newStatus);
        
        fetch('current_inventory.php', {
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
                    timer: 1500,
                    showConfirmButton: false
                });
                
                // Update row data attribute
                const row = document.querySelector(`.inventory-row[data-id="${itemId}"]`);
                if (row) {
                    row.dataset.status = newStatus;
                }
            } else {
                Swal.fire('Error', data.message, 'error');
                // Revert checkbox
                checkbox.checked = !checkbox.checked;
            }
        })
        .catch(error => {
            Swal.close();
            console.error('Error:', error);
            Swal.fire('Error', 'An error occurred while updating status', 'error');
            // Revert checkbox
            checkbox.checked = !checkbox.checked;
        });
    }

    // ========== SUPPLIER FUNCTIONS ==========
    function showSupplierSelector() {
        showLoading();
        
        const formData = new FormData();
        formData.append('action', 'get_suppliers');
        
        fetch('current_inventory.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            Swal.close();
            
            if (data.success) {
                suppliersList = data.suppliers || [];
                
                // Populate supplier select
                const select = $('#supplierSelect');
                select.empty();
                select.append('<option value="">-- Choose Supplier --</option>');
                
                suppliersList.forEach(supplier => {
                    select.append(new Option(supplier.supplier_name, supplier.supplier_id));
                });
                
                // Initialize Select2
                select.select2({
                    dropdownParent: $('#supplierSelectorModal'),
                    width: '100%',
                    placeholder: '-- Choose Supplier --'
                });
                
                // Hide details container
                document.getElementById('supplierDetailsContainer').style.display = 'none';
                document.getElementById('noSupplierSelected').style.display = 'block';
                
                new bootstrap.Modal(document.getElementById('supplierSelectorModal')).show();
            } else {
                Swal.fire('Error', data.message, 'error');
            }
        })
        .catch(error => {
            Swal.close();
            console.error('Error:', error);
            Swal.fire('Error', 'An error occurred while loading suppliers', 'error');
        });
    }

    // Load supplier details when selected
    $('#supplierSelect').on('change', function() {
        const supplierId = $(this).val();
        
        if (supplierId) {
            loadSupplierDetails(supplierId);
        } else {
            document.getElementById('supplierDetailsContainer').style.display = 'none';
            document.getElementById('noSupplierSelected').style.display = 'block';
        }
    });

    function loadSupplierDetails(supplierId) {
        showLoading();
        
        const formData = new FormData();
        formData.append('action', 'get_supplier_details');
        formData.append('supplier_id', supplierId);
        
        fetch('current_inventory.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            Swal.close();
            
            if (data.success) {
                const supplier = data.supplier;
                const purchaseOrders = data.purchase_orders || [];
                
                // Calculate totals
                let totalOrders = purchaseOrders.length;
                let totalItems = 0;
                let totalQuantity = 0;
                let totalSpent = 0;
                
                purchaseOrders.forEach(po => {
                    totalItems += po.total_items || 0;
                    totalQuantity += parseInt(po.total_quantity) || 0;
                    totalSpent += parseFloat(po.total_amount) || 0;
                });
                
                // Update summary
                document.getElementById('supplierSummary').innerHTML = `
                    <h6 class="fw-bold mb-3">${supplier.supplier_name}</h6>
                    <div class="supplier-stat">
                        <span class="supplier-stat-label">Supplier Code:</span>
                        <span class="supplier-stat-value">${supplier.supplier_code || 'N/A'}</span>
                    </div>
                    <div class="supplier-stat">
                        <span class="supplier-stat-label">Contact Person:</span>
                        <span class="supplier-stat-value">${supplier.contact_person || 'N/A'}</span>
                    </div>
                    <div class="supplier-stat">
                        <span class="supplier-stat-label">Email:</span>
                        <span class="supplier-stat-value">${supplier.email || 'N/A'}</span>
                    </div>
                    <div class="supplier-stat">
                        <span class="supplier-stat-label">Phone:</span>
                        <span class="supplier-stat-value">${supplier.phone_number || 'N/A'}</span>
                    </div>
                    <div class="supplier-stat">
                        <span class="supplier-stat-label">Credit Limit:</span>
                        <span class="supplier-stat-value">₱${parseFloat(supplier.credit_limit || 0).toFixed(2)}</span>
                    </div>
                    <div class="supplier-stat">
                        <span class="supplier-stat-label">Total Purchase Orders:</span>
                        <span class="supplier-stat-value">${totalOrders}</span>
                    </div>
                    <div class="supplier-stat">
                        <span class="supplier-stat-label">Total Items Ordered:</span>
                        <span class="supplier-stat-value">${totalItems}</span>
                    </div>
                    <div class="supplier-stat">
                        <span class="supplier-stat-label">Total Quantity:</span>
                        <span class="supplier-stat-value">${totalQuantity}</span>
                    </div>
                    <div class="supplier-stat">
                        <span class="supplier-stat-label">Total Spent:</span>
                        <span class="supplier-stat-value">₱${totalSpent.toFixed(2)}</span>
                    </div>
                `;
                
                // Generate purchase orders list
                let poHtml = '';
                if (purchaseOrders.length > 0) {
                    purchaseOrders.forEach(po => {
                        const orderDate = po.order_date ? new Date(po.order_date).toLocaleDateString() : 'N/A';
                        const expectedDate = po.expected_delivery ? new Date(po.expected_delivery).toLocaleDateString() : 'N/A';
                        
                        poHtml += `
                            <div class="card mb-3">
                                <div class="card-header bg-light">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <strong>${po.po_number}</strong>
                                        <span class="badge bg-${po.po_status === 'received' ? 'success' : (po.po_status === 'cancelled' ? 'danger' : 'warning')}">${po.po_status}</span>
                                    </div>
                                </div>
                                <div class="card-body">
                                    <div class="row mb-2">
                                        <div class="col-md-4"><small class="text-muted">Order Date:</small> ${orderDate}</div>
                                        <div class="col-md-4"><small class="text-muted">Expected:</small> ${expectedDate}</div>
                                        <div class="col-md-4"><small class="text-muted">Branch:</small> ${po.branch_name || 'N/A'}</div>
                                    </div>
                                    <div class="row">
                                        <div class="col-md-12">
                                            <small class="text-muted">Items:</small>
                                            <ul class="list-unstyled mt-1">
                        `;
                        
                        if (po.items && po.items.length > 0) {
                            po.items.forEach(item => {
                                poHtml += `
                                    <li class="mb-1">
                                        <span class="badge bg-secondary me-2">${item.item_code}</span>
                                        ${item.item_name} - ${item.quantity_ordered} x ₱${parseFloat(item.unit_price).toFixed(2)} = ₱${(item.quantity_ordered * item.unit_price).toFixed(2)}
                                    </li>
                                `;
                            });
                        } else {
                            poHtml += '<li class="text-muted">No items found</li>';
                        }
                        
                        poHtml += `
                                            </ul>
                                        </div>
                                    </div>
                                    <div class="mt-2 text-end">
                                        <strong>Total: ₱${parseFloat(po.total_amount).toFixed(2)}</strong>
                                    </div>
                                </div>
                            </div>
                        `;
                    });
                } else {
                    poHtml = '<div class="text-center py-4 text-muted">No purchase orders found for this supplier</div>';
                }
                
                document.getElementById('supplierPurchaseOrders').innerHTML = poHtml;
                
                // Show container, hide placeholder
                document.getElementById('supplierDetailsContainer').style.display = 'block';
                document.getElementById('noSupplierSelected').style.display = 'none';
            } else {
                Swal.fire('Error', data.message, 'error');
            }
        })
        .catch(error => {
            Swal.close();
            console.error('Error:', error);
            Swal.fire('Error', 'An error occurred while loading supplier details', 'error');
        });
    }

    // ========== LOW STOCK FUNCTIONS ==========
    function showLowStockModal() {
        showLoading();
        
        const formData = new FormData();
        formData.append('action', 'get_low_stock_items');
        
        fetch('current_inventory.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            Swal.close();
            
            if (data.success) {
                const items = data.items || [];
                
                if (items.length > 0) {
                    let itemsHtml = '';
                    items.forEach(item => {
                        const stockStatus = parseInt(item.stock) <= 0 ? 'Out of Stock' : 'Low Stock';
                        const statusClass = parseInt(item.stock) <= 0 ? 'stock-badge-danger' : 'stock-badge-warning';
                        
                        itemsHtml += `
                            <div class="low-stock-item">
                                <div class="low-stock-item-info">
                                    <div class="low-stock-item-name">${item.item_name}</div>
                                    <div class="low-stock-item-code">${item.item_code} | ${item.category || 'General'}</div>
                                </div>
                                <div class="low-stock-item-stats">
                                    <div class="low-stock-item-current">${item.stock} ${item.unit_type}</div>
                                    <div class="low-stock-item-reorder">Reorder: ${item.reorder_level}</div>
                                </div>
                                <div>
                                    <span class="stock-status-badge ${statusClass}">${stockStatus}</span>
                                </div>
                            </div>
                        `;
                    });
                    
                    document.getElementById('lowStockItemsContainer').innerHTML = itemsHtml;
                    document.getElementById('lowStockItemsContainer').style.display = 'block';
                    document.getElementById('noLowStockItems').style.display = 'none';
                } else {
                    document.getElementById('lowStockItemsContainer').style.display = 'none';
                    document.getElementById('noLowStockItems').style.display = 'block';
                }
                
                new bootstrap.Modal(document.getElementById('lowStockModal')).show();
            } else {
                Swal.fire('Error', data.message, 'error');
            }
        })
        .catch(error => {
            Swal.close();
            console.error('Error:', error);
            Swal.fire('Error', 'An error occurred while loading low stock items', 'error');
        });
    }

    // ========== OFFTAKE FUNCTIONS ==========
    function showOfftakeModal() {
        // Show modal first
        new bootstrap.Modal(document.getElementById('offtakeModal')).show();
        // Load default data (last 30 days)
        loadOfftakeData();
    }

    function loadOfftakeData() {
        const startDate = document.getElementById('offtakeStartDate').value;
        const endDate = document.getElementById('offtakeEndDate').value;
        
        if (!startDate || !endDate) {
            Swal.fire('Warning', 'Please select both start and end dates', 'warning');
            return;
        }
        
        if (new Date(startDate) > new Date(endDate)) {
            Swal.fire('Warning', 'Start date cannot be after end date', 'warning');
            return;
        }
        
        showLoading();
        
        const formData = new FormData();
        formData.append('action', 'get_offtake_data');
        formData.append('start_date', startDate);
        formData.append('end_date', endDate);
        
        fetch('current_inventory.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            Swal.close();
            
            if (data.success) {
                updateOfftakeUI(data);
            } else {
                Swal.fire('Error', data.message, 'error');
            }
        })
        .catch(error => {
            Swal.close();
            console.error('Error:', error);
            Swal.fire('Error', 'An error occurred while loading offtake data', 'error');
        });
    }

    function updateOfftakeUI(data) {
        const summary = data.summary;
        const dailyData = data.daily_data;
        const dateRange = data.date_range;
        
        // Update summary cards
        document.getElementById('summaryAvgDaily').textContent = summary.avg_daily.toFixed(1);
        document.getElementById('summaryTotalQty').textContent = summary.total_quantity.toLocaleString();
        document.getElementById('summaryActiveDays').textContent = summary.active_days;
        document.getElementById('summaryPerItem').textContent = summary.avg_per_item.toFixed(1);
        
        // Update date range display
        const startDate = new Date(dateRange.start).toLocaleDateString('en-US', {
            year: 'numeric', month: 'short', day: 'numeric'
        });
        const endDate = new Date(dateRange.end).toLocaleDateString('en-US', {
            year: 'numeric', month: 'short', day: 'numeric'
        });
        document.getElementById('dateRangeDisplay').innerHTML = `<strong>${startDate} - ${endDate}</strong>`;
        
        // Update table
        let tableHtml = '';
        let totalOrders = 0;
        let totalQty = 0;
        let totalAmount = 0;
        
        if (dailyData.length > 0) {
            dailyData.forEach(day => {
                const date = new Date(day.sale_date).toLocaleDateString('en-US', {
                    year: 'numeric',
                    month: 'short',
                    day: 'numeric'
                });
                totalOrders += parseInt(day.order_count);
                totalQty += parseInt(day.total_quantity);
                totalAmount += parseFloat(day.total_amount);
                
                tableHtml += `
                    <tr>
                        <td>${date}</td>
                        <td class="text-center">${day.order_count}</td>
                        <td class="text-center">${day.total_quantity}</td>
                        <td class="text-end">₱${parseFloat(day.total_amount).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}</td>
                    </tr>
                `;
            });
            
            // Add total row
            tableHtml += `
                <tr class="total-row">
                    <td><strong>TOTAL</strong></td>
                    <td class="text-center"><strong>${totalOrders}</strong></td>
                    <td class="text-center"><strong>${totalQty.toLocaleString()}</strong></td>
                    <td class="text-end"><strong>₱${totalAmount.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}</strong></td>
                </tr>
            `;
        } else {
            tableHtml = `
                <tr>
                    <td colspan="4" class="text-center py-4 text-muted">
                        <i class="bi bi-info-circle fs-4 d-block mb-2"></i>
                        No data found for the selected date range
                    </td>
                </tr>
            `;
        }
        document.getElementById('offtakeTableBody').innerHTML = tableHtml;
    }

    // ========== PRINT OFFTAKE REPORT ==========
    function printOfftakeReport() {
        // Show loading indicator on button
        const printBtn = document.querySelector('.btn-outline-primary[onclick="printOfftakeReport()"]');
        if (printBtn) {
            const originalText = printBtn.innerHTML;
            printBtn.innerHTML = '<i class="bi bi-printer"></i> Preparing...';
            printBtn.disabled = true;
        }

        // Get current filter values
        const startDate = document.getElementById('offtakeStartDate').value;
        const endDate = document.getElementById('offtakeEndDate').value;
        
        if (!startDate || !endDate) {
            Swal.fire('Warning', 'Please select both start and end dates', 'warning');
            if (printBtn) {
                printBtn.innerHTML = '<i class="bi bi-printer"></i> Print';
                printBtn.disabled = false;
            }
            return;
        }
        
        const filterData = {
            start_date: startDate,
            end_date: endDate
        };
        
        showLoading();
        
        // Fetch filtered data from server
        const formData = new FormData();
        formData.append('action', 'print_offtake');
        formData.append('filter_data', JSON.stringify(filterData));
        
        fetch('current_inventory.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            Swal.close();
            
            if (data.success) {
                const items = data.items;
                const summary = data.summary;
                const dateRange = data.date_range;
                
                if (items.length === 0) {
                    Swal.fire({
                        icon: 'warning',
                        title: 'No Data',
                        text: 'No offtake data matches the selected date range',
                        confirmButtonColor: '#0d6efd'
                    });
                    return;
                }
                
                // Generate compact HTML
                const htmlContent = generateOfftakePrintHTML(items, summary, dateRange, data.branch_name);
                
                // Use hidden iframe for printing
                const iframe = document.getElementById('printFrame');
                const iframeDoc = iframe.contentWindow.document;
                
                iframeDoc.open();
                iframeDoc.write(htmlContent);
                iframeDoc.close();
                
                // Restore button
                setTimeout(() => {
                    if (printBtn) {
                        printBtn.innerHTML = '<i class="bi bi-printer"></i> Print';
                        printBtn.disabled = false;
                    }
                }, 1000);
                
                // Trigger print dialog
                setTimeout(() => {
                    iframe.contentWindow.focus();
                    iframe.contentWindow.print();
                }, 250);
            } else {
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: 'Failed to load offtake data',
                    confirmButtonColor: '#0d6efd'
                });
                if (printBtn) {
                    printBtn.innerHTML = '<i class="bi bi-printer"></i> Print';
                    printBtn.disabled = false;
                }
            }
        })
        .catch(error => {
            Swal.close();
            console.error('Error:', error);
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: 'An error occurred while preparing print',
                confirmButtonColor: '#0d6efd'
            });
            if (printBtn) {
                printBtn.innerHTML = '<i class="bi bi-printer"></i> Print';
                printBtn.disabled = false;
            }
        });
    }

    // Compact HTML generator for offtake print
    function generateOfftakePrintHTML(items, summary, dateRange, branchName) {
        let tableRows = '';
        let totalOrders = 0;
        let totalQuantity = 0;
        let totalAmount = 0;
        
        items.forEach(item => {
            const date = new Date(item.sale_date).toLocaleDateString('en-US', {
                year: 'numeric', 
                month: 'short', 
                day: 'numeric'
            });
            totalOrders += parseInt(item.order_count);
            totalQuantity += parseInt(item.total_quantity);
            totalAmount += parseFloat(item.total_amount);
            
            tableRows += '<tr>';
            tableRows += `<td style="padding: 3px; border: 1px solid #000;">${date}</td>`;
            tableRows += `<td style="padding: 3px; border: 1px solid #000; text-align: center;">${item.order_count}</td>`;
            tableRows += `<td style="padding: 3px; border: 1px solid #000; text-align: center;">${item.total_quantity}</td>`;
            tableRows += `<td style="padding: 3px; border: 1px solid #000; text-align: right;">₱${parseFloat(item.total_amount).toFixed(2)}</td>`;
            tableRows += '</tr>';
        });
        
        const currentDate = new Date().toLocaleDateString('en-US', { 
            year: 'numeric', 
            month: 'short', 
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });
        
        const startDate = new Date(dateRange.start).toLocaleDateString('en-US', {
            year: 'numeric', month: 'short', day: 'numeric'
        });
        const endDate = new Date(dateRange.end).toLocaleDateString('en-US', {
            year: 'numeric', month: 'short', day: 'numeric'
        });
        
        return `
            <!DOCTYPE html>
            <html>
            <head>
                <meta charset="UTF-8">
                <title>Offtake Report</title>
                <style>
                    body { font-family: Arial, sans-serif; margin: 0; padding: 0; font-size: 9px; }
                    .print-container { max-width: 100%; margin: 0; }
                    .print-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 5px; border-bottom: 1px solid #000; padding-bottom: 3px; }
                    .logo-section { display: flex; align-items: center; gap: 5px; }
                    .company-logo { width: 30px; height: auto; }
                    .company-info h1 { font-size: 14px; margin: 0; font-weight: bold; }
                    .company-info p { font-size: 8px; margin: 0; }
                    .report-title h2 { font-size: 12px; margin: 0; }
                    .report-title .date-info { font-size: 8px; }
                    .summary-box { border: 1px solid #000; padding: 3px; margin-bottom: 5px; display: flex; }
                    .summary-item { flex: 1; text-align: center; border-right: 1px solid #000; }
                    .summary-item:last-child { border-right: none; }
                    .summary-label { font-size: 8px; font-weight: bold; }
                    .summary-value { font-size: 11px; font-weight: bold; }
                    table { width: 100%; border-collapse: collapse; font-size: 8px; }
                    th { border: 1px solid #000; padding: 3px; text-align: left; font-weight: bold; background: white !important; color: black !important; }
                    td { border: 1px solid #000; padding: 3px; }
                    .total-row { font-weight: bold; }
                    .print-footer { margin-top: 5px; border-top: 1px solid #000; padding-top: 3px; display: flex; justify-content: space-between; font-size: 8px; }
                </style>
            </head>
            <body>
                <div class="print-container">
                    <div class="print-header">
                        <div class="logo-section">
                            <img src="${logoBase64}" alt="AMGC Logo" class="company-logo">
                            <div class="company-info">
                                <h1>AMGC</h1>
                                <p>Offtake Report</p>
                            </div>
                        </div>
                        <div class="report-title">
                            <h2>AVERAGE DAILY OFFTAKE</h2>
                            <div class="date-info">${currentDate}</div>
                        </div>
                    </div>
                    
                    <div style="margin-bottom: 5px; font-size: 9px;">
                        <strong>Date Range:</strong> ${startDate} - ${endDate}
                    </div>
                    
                    <div class="summary-box">
                        <div class="summary-item"><div class="summary-label">Avg Daily</div><div class="summary-value">${summary.avg_daily.toFixed(1)}</div></div>
                        <div class="summary-item"><div class="summary-label">Total Qty</div><div class="summary-value">${summary.total_quantity.toLocaleString()}</div></div>
                        <div class="summary-item"><div class="summary-label">Active Days</div><div class="summary-value">${summary.active_days}</div></div>
                        <div class="summary-item"><div class="summary-label">Per Item</div><div class="summary-value">${summary.avg_per_item.toFixed(1)}</div></div>
                    </div>
                    
                    <table>
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th style="text-align: center;">Orders</th>
                                <th style="text-align: center;">Quantity</th>
                                <th style="text-align: right;">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${tableRows}
                            <tr class="total-row">
                                <td style="text-align: right;">TOTAL</td>
                                <td style="text-align: center;">${totalOrders}</td>
                                <td style="text-align: center;">${totalQuantity}</td>
                                <td style="text-align: right;">₱${totalAmount.toFixed(2)}</td>
                            </tr>
                        </tbody>
                    </table>
                    
                    <div class="print-footer">
                        <div>Generated: ${currentDate}</div>
                        <div>${document.querySelector('.user-name-sidebar')?.textContent || 'Branch Admin'}</div>
                    </div>
                </div>
            </body>
            </html>
        `;
    }

    function exportOfftakeToExcel() {
        const table = document.querySelector('#offtakeModal table');
        if (!table) return;
        
        const rows = document.querySelectorAll('#offtakeTableBody tr');
        if (rows.length === 0 || (rows.length === 1 && rows[0].querySelector('td[colspan]'))) {
            Swal.fire('Warning', 'No data to export', 'warning');
            return;
        }
        
        // Prepare data for Excel
        const excelData = [];
        
        // Add headers
        excelData.push(['Date', 'Orders', 'Quantity', 'Amount (₱)']);
        
        // Add data rows
        rows.forEach(row => {
            const cells = row.querySelectorAll('td');
            if (cells.length === 4 && !cells[0].hasAttribute('colspan')) {
                const rowData = [
                    cells[0].innerText,
                    cells[1].innerText,
                    cells[2].innerText,
                    cells[3].innerText.replace('₱', '').replace(/,/g, '')
                ];
                excelData.push(rowData);
            }
        });
        
        // Add summary row
        const summaryAvg = document.getElementById('summaryAvgDaily').textContent;
        const summaryTotal = document.getElementById('summaryTotalQty').textContent;
        const summaryDays = document.getElementById('summaryActiveDays').textContent;
        const summaryPerItem = document.getElementById('summaryPerItem').textContent;
        
        excelData.push([]);
        excelData.push(['SUMMARY']);
        excelData.push(['Avg Daily Offtake', summaryAvg]);
        excelData.push(['Total Quantity', summaryTotal]);
        excelData.push(['Active Days', summaryDays]);
        excelData.push(['Per Item Avg', summaryPerItem]);
        
        // Create workbook
        const wb = XLSX.utils.book_new();
        const ws = XLSX.utils.aoa_to_sheet(excelData);
        
        // Set column widths
        ws['!cols'] = [
            { wch: 15 },
            { wch: 10 },
            { wch: 12 },
            { wch: 15 }
        ];
        
        XLSX.utils.book_append_sheet(wb, ws, 'Offtake Report');
        
        // Generate filename
        const startDate = document.getElementById('offtakeStartDate').value;
        const endDate = document.getElementById('offtakeEndDate').value;
        const filename = `Offtake_Report_${startDate}_to_${endDate}.xlsx`;
        
        XLSX.writeFile(wb, filename);
        
        Swal.fire({
            icon: 'success',
            title: 'Export Complete',
            timer: 1500,
            showConfirmButton: false
        });
    }

    // ========== ITEM FUNCTIONS ==========
    document.addEventListener('DOMContentLoaded', function() {
        console.log("Current Inventory - Live Database Mode");
        console.log("Branch ID:", branchId);
        console.log("View All Branches:", viewAllBranches);
        console.log("Items Branch Column Exists:", itemsBranchColumnExists);
        
        initializeSidebar();
        
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
        
        // Fix modal backdrop issue
        const modals = ['supplierSelectorModal', 'lowStockModal', 'offtakeModal', 'itemModal', 'editItemModal', 'viewItemModal', 'deleteItemModal'];
        modals.forEach(modalId => {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.addEventListener('hidden.bs.modal', function () {
                    const backdrop = document.querySelector('.modal-backdrop');
                    if (backdrop) backdrop.remove();
                    document.body.classList.remove('modal-open');
                    document.body.style.removeProperty('padding-right');
                });
            }
        });
    });

    // ========== MODAL FUNCTIONS ==========
    
    // Show Add Item Modal
    function showAddItemModal() {
        document.getElementById('itemModalTitle').innerHTML = '<i class="bi bi-plus-circle me-2"></i>Add New Item';
        document.getElementById('itemForm').reset();
        document.getElementById('itemId').value = '';
        document.getElementById('itemCode').value = '<?= $next_item_code ?>';
        document.getElementById('status').value = 'active';
        new bootstrap.Modal(document.getElementById('itemModal')).show();
    }

    // View Item
    function viewItem(id) {
        showLoading();
        
        // Fetch item details via AJAX
        const formData = new FormData();
        formData.append('action', 'get_item');
        formData.append('item_id', id);
        
        fetch('current_inventory.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            Swal.close();
            
            if (data.success) {
                const item = data.item;
                
                // Generate image HTML
                const imageHtml = item.product_image_url 
                    ? `<img src="../uploads/products/${item.product_image_url}" alt="${item.item_name}" style="max-width: 200px; max-height: 200px; border-radius: 8px; margin-bottom: 15px;">`
                    : '<div class="text-center p-3 bg-light rounded"><i class="bi bi-image" style="font-size: 64px; color: #adb5bd;"></i><p class="mt-2 text-muted">No image available</p></div>';
                
                const content = document.getElementById('viewItemContent');
                content.innerHTML = `
                    <div class="col-md-12 text-center mb-3">
                        ${imageHtml}
                    </div>
                    <div class="col-md-6">
                        <table class="table table-sm table-borderless">
                            <tr>
                                <th width="40%">Item Code:</th>
                                <td><strong>${item.item_code}</strong></td>
                            </tr>
                            <tr>
                                <th>Item Name:</th>
                                <td>${item.item_name}</td>
                            </tr>
                            <tr>
                                <th>Category:</th>
                                <td>${item.category || 'Uncategorized'}</td>
                            </tr>
                            <tr>
                                <th>Description:</th>
                                <td>${item.description || 'No description'}</td>
                            </tr>
                            <tr>
                                <th>Status:</th>
                                <td>
                                    <span class="badge bg-${item.status === 'active' ? 'success' : item.status === 'inactive' ? 'secondary' : 'danger'}">
                                        ${item.status.charAt(0).toUpperCase() + item.status.slice(1)}
                                    </span>
                                </td>
                            </tr>
                            <tr>
                                <th>Reorder Level:</th>
                                <td>${item.reorder_level}</td>
                            </tr>
                            ${itemsBranchColumnExists ? `
                            <tr>
                                <th>Branch:</th>
                                <td>
                                    <span class="badge bg-info">Branch ${item.branch_id || 'N/A'}</span>
                                </td>
                            </tr>
                            ` : ''}
                        </table>
                    </div>
                    <div class="col-md-6">
                        <table class="table table-sm table-borderless">
                            <tr>
                                <th width="40%">Stock:</th>
                                <td>${Number(item.stock).toLocaleString()} ${item.unit_type}</td>
                            </tr>
                            <tr>
                                <th>Unit Price:</th>
                                <td>₱${Number(item.unit_price).toFixed(2)}</td>
                            </tr>
                            <tr>
                                <th>Stock Value:</th>
                                <td>₱${(Number(item.stock) * Number(item.unit_price)).toFixed(2)}</td>
                            </tr>
                            <tr>
                                <th>Created At:</th>
                                <td>${new Date(item.created_at).toLocaleString()}</td>
                            </tr>
                            <tr>
                                <th>Last Updated:</th>
                                <td>${new Date(item.updated_at).toLocaleString()}</td>
                            </tr>
                        </table>
                    </div>
                `;
                
                currentItemId = id;
                new bootstrap.Modal(document.getElementById('viewItemModal')).show();
            } else {
                Swal.fire('Error', data.message, 'error');
            }
        })
        .catch(error => {
            Swal.close();
            Swal.fire('Error', 'An error occurred while fetching item details', 'error');
        });
    }

    // Edit from View Modal
    function editFromView() {
        bootstrap.Modal.getInstance(document.getElementById('viewItemModal')).hide();
        setTimeout(() => {
            editItem(currentItemId);
        }, 300);
    }

    // Edit Item - FIXED: Convert unit_type from database to select value
    function editItem(id) {
        showLoading();
        
        // Fetch item details via AJAX
        const formData = new FormData();
        formData.append('action', 'get_item');
        formData.append('item_id', id);
        
        fetch('current_inventory.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            Swal.close();
            
            if (data.success) {
                const item = data.item;
                
                document.getElementById('editItemId').value = item.item_id;
                document.getElementById('editItemCode').value = item.item_code;
                document.getElementById('editItemName').value = item.item_name;
                document.getElementById('editDescription').value = item.description || '';
                document.getElementById('editCategory').value = item.category || '';
                document.getElementById('editStock').value = item.stock;
                
                // FIXED: Convert unit_type from database (inner_pack) to select value (inner-pack)
                let unitType = item.unit_type;
                if (unitType === 'inner_pack') {
                    unitType = 'inner-pack';
                }
                document.getElementById('editUnitType').value = unitType;
                
                document.getElementById('editUnitPrice').value = item.unit_price;
                document.getElementById('editReorderLevel').value = item.reorder_level;
                document.getElementById('editStatus').value = item.status;
                
                // Display current picture if exists
                if (item.product_image_url) {
                    const picDiv = document.getElementById('currentItemPictureDiv');
                    const picImg = document.getElementById('currentItemPicture');
                    picImg.src = '../uploads/products/' + item.product_image_url;
                    picDiv.style.display = 'block';
                } else {
                    document.getElementById('currentItemPictureDiv').style.display = 'none';
                }
                
                currentItemId = id;
                new bootstrap.Modal(document.getElementById('editItemModal')).show();
            } else {
                Swal.fire('Error', data.message, 'error');
            }
        })
        .catch(error => {
            Swal.close();
            Swal.fire('Error', 'An error occurred while fetching item details', 'error');
        });
    }

    // Save Item (Add) - FIXED VERSION
    function saveItem() {
        // Validate required fields
        const itemName = document.getElementById('itemName').value;
        const stock = document.getElementById('stock').value;
        const unitPrice = document.getElementById('unitPrice').value;
        const reorderLevel = document.getElementById('reorderLevel').value;
        const itemCode = document.getElementById('itemCode').value;
        
        if (!itemName) {
            Swal.fire('Warning', 'Item Name is required', 'warning');
            return;
        }
        
        if (!itemCode) {
            Swal.fire('Warning', 'Item Code is required', 'warning');
            return;
        }
        
        if (!stock || stock < 0) {
            Swal.fire('Warning', 'Valid Stock quantity is required', 'warning');
            return;
        }
        
        if (!unitPrice || unitPrice < 0) {
            Swal.fire('Warning', 'Valid Unit Price is required', 'warning');
            return;
        }
        
        if (!reorderLevel || reorderLevel < 0) {
            Swal.fire('Warning', 'Valid Reorder Level is required', 'warning');
            return;
        }
        
        showLoading();
        
        // Prepare form data - FIXED: Include all fields properly
        const formData = new FormData();
        formData.append('action', 'add_item');
        formData.append('item_code', document.getElementById('itemCode').value);
        formData.append('item_name', document.getElementById('itemName').value);
        formData.append('description', document.getElementById('description').value || '');
        formData.append('category', document.getElementById('category').value || 'General');
        formData.append('stock', document.getElementById('stock').value);
        formData.append('unit_type', document.getElementById('unitType').value);
        formData.append('unit_price', document.getElementById('unitPrice').value);
        formData.append('reorder_level', document.getElementById('reorderLevel').value);
        formData.append('status', document.getElementById('status').value || 'active');
        
        // Handle file upload - FIXED: Append file if exists
        const pictureInput = document.getElementById('itemPicture');
        if (pictureInput && pictureInput.files.length > 0) {
            formData.append('itemPicture', pictureInput.files[0]);
        }
        
        fetch('current_inventory.php', {
            method: 'POST',
            body: formData
        })
        .then(response => {
            if (!response.ok) {
                throw new Error('Network response was not ok');
            }
            return response.json();
        })
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
                    const modal = bootstrap.Modal.getInstance(document.getElementById('itemModal'));
                    if (modal) modal.hide();
                    location.reload();
                });
            } else {
                Swal.fire('Error', data.message, 'error');
            }
        })
        .catch(error => {
            Swal.close();
            console.error('Error:', error);
            Swal.fire('Error', 'An error occurred while saving the item: ' + error.message, 'error');
        });
    }

    // Update Item - FIXED VERSION
    function updateItem() {
        // Validate required fields
        const itemName = document.getElementById('editItemName').value;
        const stock = document.getElementById('editStock').value;
        const unitPrice = document.getElementById('editUnitPrice').value;
        const reorderLevel = document.getElementById('editReorderLevel').value;
        const itemCode = document.getElementById('editItemCode').value;
        
        if (!itemName) {
            Swal.fire('Warning', 'Item Name is required', 'warning');
            return;
        }
        
        if (!itemCode) {
            Swal.fire('Warning', 'Item Code is required', 'warning');
            return;
        }
        
        if (!stock || stock < 0) {
            Swal.fire('Warning', 'Valid Stock quantity is required', 'warning');
            return;
        }
        
        if (!unitPrice || unitPrice < 0) {
            Swal.fire('Warning', 'Valid Unit Price is required', 'warning');
            return;
        }
        
        if (!reorderLevel || reorderLevel < 0) {
            Swal.fire('Warning', 'Valid Reorder Level is required', 'warning');
            return;
        }
        
        showLoading();
        
        // Prepare form data - FIXED: Include all fields properly
        const formData = new FormData();
        formData.append('action', 'update_item');
        formData.append('item_id', document.getElementById('editItemId').value);
        formData.append('item_code', document.getElementById('editItemCode').value);
        formData.append('item_name', document.getElementById('editItemName').value);
        formData.append('description', document.getElementById('editDescription').value || '');
        formData.append('category', document.getElementById('editCategory').value || 'General');
        formData.append('stock', document.getElementById('editStock').value);
        formData.append('unit_type', document.getElementById('editUnitType').value);
        formData.append('unit_price', document.getElementById('editUnitPrice').value);
        formData.append('reorder_level', document.getElementById('editReorderLevel').value);
        formData.append('status', document.getElementById('editStatus').value || 'active');
        
        // Handle file upload - FIXED: Append file if exists
        const pictureInput = document.getElementById('editItemPicture');
        if (pictureInput && pictureInput.files.length > 0) {
            formData.append('editItemPicture', pictureInput.files[0]);
        }
        
        fetch('current_inventory.php', {
            method: 'POST',
            body: formData
        })
        .then(response => {
            if (!response.ok) {
                throw new Error('Network response was not ok');
            }
            return response.json();
        })
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
                    const modal = bootstrap.Modal.getInstance(document.getElementById('editItemModal'));
                    if (modal) modal.hide();
                    location.reload();
                });
            } else {
                Swal.fire('Error', data.message, 'error');
            }
        })
        .catch(error => {
            Swal.close();
            console.error('Error:', error);
            Swal.fire('Error', 'An error occurred while updating the item: ' + error.message, 'error');
        });
    }

    // Delete Item
    function deleteItem(id) {
        const row = document.querySelector(`.inventory-row[data-id="${id}"]`);
        if (!row) return;
        
        document.getElementById('deleteItemCode').textContent = row.dataset.code;
        currentItemId = id;
        new bootstrap.Modal(document.getElementById('deleteItemModal')).show();
    }

    // Confirm Delete
    function confirmDelete() {
        showLoading();
        
        const formData = new FormData();
        formData.append('action', 'delete_item');
        formData.append('item_id', currentItemId);
        
        fetch('current_inventory.php', {
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
                    location.reload();
                });
            } else {
                Swal.fire('Error', data.message, 'error');
            }
        })
        .catch(error => {
            Swal.close();
            Swal.fire('Error', 'An error occurred while deleting the item', 'error');
        });
    }

    // Filter low stock
    function filterLowStock() {
        document.getElementById('stockFilter').value = 'low';
        filterItems();
        bootstrap.Modal.getInstance(document.getElementById('lowStockModal')).hide();
    }

    // ========== EXCEL EXPORT FUNCTION ==========
    function exportToExcel() {
        let rows;
        if (currentView === 'category') {
            const activeTab = document.querySelector('#categoryView .tab-content.active');
            if (!activeTab) return;
            rows = activeTab.querySelectorAll('.inventory-row:not([style*="display: none"])');
        } else {
            const activeTab = document.querySelector('#supplierView .tab-content.active');
            if (!activeTab) return;
            rows = activeTab.querySelectorAll('.inventory-row:not([style*="display: none"])');
        }
        
        if (rows.length === 0) {
            Swal.fire('Warning', 'No items to export', 'warning');
            return;
        }
        
        // Prepare data array for Excel
        const excelData = [];
        
        // Add headers
        const headers = [
            'Item Name',
            'Category',
            ...(itemsBranchColumnExists && viewAllBranches ? ['Branch'] : []),
            'Stock',
            'Unit Type',
            'Status'
        ];
        excelData.push(headers);

        // Add data rows
        rows.forEach(row => {
            if (row.style.display !== 'none') {
                const name = row.dataset.name;
                const category = row.dataset.category || 'Uncategorized';
                const stock = parseInt(row.dataset.stock);
                const unit = row.dataset.unit;
                const status = row.dataset.status;
                const branch = row.dataset.branch;
                
                // FIXED: Convert unit type display
                let unitDisplay = unit;
                if (unitDisplay === 'inner_pack') unitDisplay = 'Inner Pack';
                else unitDisplay = unitDisplay.charAt(0).toUpperCase() + unitDisplay.slice(1);
                
                const rowData = [
                    name,
                    category,
                    ...(itemsBranchColumnExists && viewAllBranches ? [`Branch ${branch || 'N/A'}`] : []),
                    stock,
                    unitDisplay,
                    status.charAt(0).toUpperCase() + status.slice(1)
                ];
                
                excelData.push(rowData);
            }
        });

        // Create workbook and worksheet
        const wb = XLSX.utils.book_new();
        const ws = XLSX.utils.aoa_to_sheet(excelData);

        // Set column widths
        const colWidths = [
            { wch: 30 }, // Item Name
            { wch: 20 }, // Category
            ...(itemsBranchColumnExists && viewAllBranches ? [{ wch: 12 }] : []), // Branch
            { wch: 12 }, // Stock
            { wch: 12 }, // Unit Type
            { wch: 15 }  // Status
        ];
        ws['!cols'] = colWidths;

        // Add worksheet to workbook
        const sheetName = currentView === 'category' ? 'Current Inventory' : 'Inventory by Supplier';
        XLSX.utils.book_append_sheet(wb, ws, sheetName);

        // Generate filename with current date and branch info
        const date = new Date();
        const dateStr = date.toISOString().slice(0,10).replace(/-/g, '');
        let filename = `${sheetName}_${dateStr}`;
        if (itemsBranchColumnExists && !viewAllBranches) {
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

    // ========== STOCK UPDATE FUNCTION (called from sales order) ==========
    function updateStockFromSales(itemId, quantity, soId) {
        showLoading();
        
        const formData = new FormData();
        formData.append('action', 'update_stock_from_sales');
        formData.append('item_id', itemId);
        formData.append('quantity', quantity);
        formData.append('so_id', soId);
        
        fetch('current_inventory.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            Swal.close();
            
            if (data.success) {
                console.log('Stock updated successfully for item ' + itemId);
                // Optionally refresh the page or update the specific row
                // location.reload();
            } else {
                console.error('Failed to update stock:', data.message);
                Swal.fire('Error', data.message, 'error');
            }
        })
        .catch(error => {
            Swal.close();
            console.error('Error updating stock:', error);
        });
    }

    // ========== COPY SQL FUNCTION ==========
    function copySQL(table) {
        let sql = '';
        if (table === 'items') {
            sql = "ALTER TABLE items ADD COLUMN branch_id INT NULL;\nALTER TABLE items ADD FOREIGN KEY (branch_id) REFERENCES branches(branch_id);";
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

    // ========== LOGOUT FUNCTION ==========
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

    // ========== KEYBOARD SHORTCUTS ==========
    document.addEventListener('keydown', function(e) {
        if (e.ctrlKey && e.key === 'b' && window.innerWidth > 992) {
            e.preventDefault();
            toggleSidebar();
        } else if (e.ctrlKey && e.key === 'f') {
            e.preventDefault();
            document.getElementById('searchInput').focus();
        } else if (e.ctrlKey && e.key === 'n') {
            e.preventDefault();
            showAddItemModal();
        } else if (e.ctrlKey && e.key === '1') {
            e.preventDefault();
            toggleView('category');
        } else if (e.ctrlKey && e.key === '2') {
            e.preventDefault();
            toggleView('supplier');
        }
    });

    // ========== EXPOSE FUNCTION FOR SALES ORDER PAGE ==========
    // This makes the function available globally for other pages to call
    window.updateInventoryFromSales = updateStockFromSales;
    </script>
</body>
</html>