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
// Get branch name for display
$branch_name = 'All Branches';
if (!$view_all_branches && $branch_id > 0) {
    $branch_query = "SELECT branch_name FROM branches WHERE branch_id = ?";
    $branch_stmt = $conn->prepare($branch_query);
    $branch_stmt->bind_param("i", $branch_id);
    $branch_stmt->execute();
    $branch_result = $branch_stmt->get_result();
    if ($branch_row = $branch_result->fetch_assoc()) {
        $branch_name = $branch_row['branch_name'];
    }
    $branch_stmt->close();
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
    $user_initials = 'SL';
}
// Get user ID for filtering
$user_id = getUserId();
$branch_id = getUserBranchId();

// Check if branch_id column exists in sales_orders table
$branch_column_exists = false;
$check_column = $conn->query("SHOW COLUMNS FROM sales_orders LIKE 'branch_id'");
if ($check_column && $check_column->num_rows > 0) {
    $branch_column_exists = true;
}

// Check if branch_id column exists in customers table
$customers_branch_column_exists = false;
$check_customers_column = $conn->query("SHOW COLUMNS FROM customers LIKE 'branch_id'");
if ($check_customers_column && $check_customers_column->num_rows > 0) {
    $customers_branch_column_exists = true;
}

// Check if pick_lists table exists for driver info
$pick_lists_exists = false;
$check_picklists = $conn->query("SHOW TABLES LIKE 'pick_lists'");
if ($check_picklists && $check_picklists->num_rows > 0) {
    $pick_lists_exists = true;
}

// Check if drivers table exists
$drivers_table_exists = false;
$check_drivers = $conn->query("SHOW TABLES LIKE 'drivers'");
if ($check_drivers && $check_drivers->num_rows > 0) {
    $drivers_table_exists = true;
}

<<<<<<< HEAD

// ===== SAFE COLUMN HELPERS FOR ORDER DETAILS TOTALS =====
// Used by Order Details modal so SUBTOTAL / DISCOUNT / GRAND TOTAL works
// even if some newer discount columns are not yet present in the database.
if (!function_exists('amgc_sales_column_exists')) {
    function amgc_sales_column_exists($conn, $table, $column) {
        $table = $conn->real_escape_string($table);
        $column = $conn->real_escape_string($column);
        $res = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
        return ($res && $res->num_rows > 0);
    }
}


// ========== NEW: EXPORT ALL ORDERS TO EXCEL (REQUIRED FORMAT) ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'export_all_orders') {
=======
// Handle AJAX request for Excel export data
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'export_excel_data') {
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
    header('Content-Type: application/json');
    
    try {
        // Get filter parameters
        $status = isset($_POST['status']) && !empty($_POST['status']) && $_POST['status'] !== 'all' ? $_POST['status'] : '';
        $customer = isset($_POST['customer']) && !empty($_POST['customer']) ? $_POST['customer'] : '';
        $start_date = isset($_POST['start_date']) && !empty($_POST['start_date']) ? $_POST['start_date'] : '';
        $end_date = isset($_POST['end_date']) && !empty($_POST['end_date']) ? $_POST['end_date'] : '';
        $search = isset($_POST['search']) && !empty($_POST['search']) ? $_POST['search'] : '';
        
        // Build WHERE conditions
        $where_conditions = ["1=1"];
        $params = [];
        $param_types = "";
        
        // Branch filter
        if ($branch_column_exists && !$view_all_branches) {
            $where_conditions[] = "so.branch_id = ?";
            $params[] = $branch_id;
            $param_types .= "i";
        }
        
<<<<<<< HEAD
        // Created by filter - regular users only see their own orders
=======
        // Created by filter - regular users only see their own orders, admins see all
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
        if (!$view_all_branches) {
            $where_conditions[] = "so.created_by = ?";
            $params[] = $user_id;
            $param_types .= "i";
        }
        
        // Status filter
        if (!empty($status)) {
            $where_conditions[] = "so.order_status = ?";
            $params[] = $status;
            $param_types .= "s";
        }
        
        // Customer filter
        if (!empty($customer)) {
            $where_conditions[] = "c.customer_name = ?";
            $params[] = $customer;
            $param_types .= "s";
        }
        
<<<<<<< HEAD
        // Date range filter
        if (!empty($start_date)) {
            $where_conditions[] = "DATE(so.order_date) >= ?";
            $params[] = $start_date;
            $param_types .= "s";
        }
        if (!empty($end_date)) {
            $where_conditions[] = "DATE(so.order_date) <= ?";
            $params[] = $end_date;
            $param_types .= "s";
        }
        
        // Search filter
        if (!empty($search)) {
            $search_param = "%" . $search . "%";
            $where_conditions[] = "(so.so_number LIKE ? OR c.customer_name LIKE ? OR i.item_name LIKE ? OR i.item_code LIKE ?)";
            $params[] = $search_param;
            $params[] = $search_param;
            $params[] = $search_param;
            $params[] = $search_param;
            $param_types .= "ssss";
        }
        
        $sql = "SELECT 
                    DATE(so.order_date) as date_encoded,
                    so.so_number as so_order_number,
                    COALESCE(c.customer_code, '') as customer_code,
                    COALESCE(c.store_name, '') as store_name,
                    c.customer_name,
                    i.item_code,
                    i.item_name as item_description,
                    0 as discount,
                    soi.unit_price as net_price,
                    0 as total_discount,
                    CONCAT(u.first_name, ' ', u.last_name) as encoded_by
                FROM sales_orders so
                JOIN customers c ON so.customer_id = c.customer_id
                JOIN sales_order_items soi ON so.so_id = soi.so_id
                JOIN items i ON soi.item_id = i.item_id
                JOIN users u ON so.created_by = u.user_id
                WHERE " . implode(" AND ", $where_conditions) . "
                ORDER BY so.order_date DESC, so.so_id, soi.so_item_id";
        
        if (!empty($params)) {
            $stmt = $conn->prepare($sql);
            if ($param_types && count($params) > 0) {
                $stmt->bind_param($param_types, ...$params);
            }
        } else {
            $stmt = $conn->prepare($sql);
        }
        
        if (!$stmt) {
            throw new Exception("Database prepare error: " . $conn->error);
        }
        
        $stmt->execute();
        $result = $stmt->get_result();
        $data = $result->fetch_all(MYSQLI_ASSOC);
        
        echo json_encode(['success' => true, 'data' => $data]);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// ========== NEW: PRINT ALL ORDERS (REQUIRED FORMAT HTML) ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'print_all_orders') {
    header('Content-Type: application/json');
    
    try {
        // Get filter parameters
        $status = isset($_POST['status']) && !empty($_POST['status']) && $_POST['status'] !== 'all' ? $_POST['status'] : '';
        $customer = isset($_POST['customer']) && !empty($_POST['customer']) ? $_POST['customer'] : '';
        $start_date = isset($_POST['start_date']) && !empty($_POST['start_date']) ? $_POST['start_date'] : '';
        $end_date = isset($_POST['end_date']) && !empty($_POST['end_date']) ? $_POST['end_date'] : '';
        $search = isset($_POST['search']) && !empty($_POST['search']) ? $_POST['search'] : '';
        
        $where_conditions = ["1=1"];
        $params = [];
        $param_types = "";
        
        if ($branch_column_exists && !$view_all_branches) {
            $where_conditions[] = "so.branch_id = ?";
            $params[] = $branch_id;
            $param_types .= "i";
        }
        if (!$view_all_branches) {
            $where_conditions[] = "so.created_by = ?";
            $params[] = $user_id;
            $param_types .= "i";
        }
        if (!empty($status)) {
            $where_conditions[] = "so.order_status = ?";
            $params[] = $status;
            $param_types .= "s";
        }
        if (!empty($customer)) {
            $where_conditions[] = "c.customer_name = ?";
            $params[] = $customer;
            $param_types .= "s";
        }
        if (!empty($start_date)) {
            $where_conditions[] = "DATE(so.order_date) >= ?";
            $params[] = $start_date;
            $param_types .= "s";
        }
        if (!empty($end_date)) {
            $where_conditions[] = "DATE(so.order_date) <= ?";
            $params[] = $end_date;
            $param_types .= "s";
        }
        if (!empty($search)) {
            $search_param = "%" . $search . "%";
            $where_conditions[] = "(so.so_number LIKE ? OR c.customer_name LIKE ? OR i.item_name LIKE ? OR i.item_code LIKE ?)";
            $params[] = $search_param;
            $params[] = $search_param;
            $params[] = $search_param;
            $params[] = $search_param;
            $param_types .= "ssss";
        }
        
        $sql = "SELECT 
                    DATE(so.order_date) as date_encoded,
                    so.so_number as so_order_number,
                    COALESCE(c.customer_code, '') as customer_code,
                    COALESCE(c.store_name, '') as store_name,
                    c.customer_name,
                    i.item_code,
                    i.item_name as item_description,
                    0 as discount,
                    soi.unit_price as net_price,
                    0 as total_discount,
                    CONCAT(u.first_name, ' ', u.last_name) as encoded_by
                FROM sales_orders so
                JOIN customers c ON so.customer_id = c.customer_id
                JOIN sales_order_items soi ON so.so_id = soi.so_id
                JOIN items i ON soi.item_id = i.item_id
                JOIN users u ON so.created_by = u.user_id
                WHERE " . implode(" AND ", $where_conditions) . "
                ORDER BY so.order_date DESC, so.so_id, soi.so_item_id";
        
        if (!empty($params)) {
            $stmt = $conn->prepare($sql);
            if ($param_types && count($params) > 0) {
                $stmt->bind_param($param_types, ...$params);
            }
        } else {
            $stmt = $conn->prepare($sql);
        }
        
        if (!$stmt) {
            throw new Exception("Database prepare error: " . $conn->error);
        }
        
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = $result->fetch_all(MYSQLI_ASSOC);
        
        // Build HTML for printing
        $branch_name_display = $branch_id ? ($view_all_branches ? 'All Branches' : htmlspecialchars($branch_name)) : 'All Branches';
        $logo_base64 = '';
        $logo_path = '../Pictures/amgc3DLogo.png';
        if (file_exists($logo_path)) {
            $image_data = file_get_contents($logo_path);
            $logo_base64 = 'data:image/png;base64,' . base64_encode($image_data);
        }
        $logo_html = !empty($logo_base64) ? '<img src="' . $logo_base64 . '" alt="Logo" style="height: 60px; margin-bottom: 10px;">' : '';
        
        $html = '<!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <title>All Sales Orders Report</title>
            <style>
                body { font-family: Arial, sans-serif; margin: 20px; font-size: 12px; }
                .header { text-align: center; margin-bottom: 20px; }
                .header h1 { margin: 5px 0; font-size: 20px; }
                .header p { margin: 2px 0; color: #555; }
                table { width: 100%; border-collapse: collapse; margin-top: 15px; }
                th, td { border: 1px solid #000; padding: 6px 8px; text-align: left; vertical-align: top; }
                th { background: #f2f2f2; font-weight: bold; }
                .footer { margin-top: 20px; text-align: center; font-size: 10px; color: #777; }
                @media print {
                    body { margin: 0; }
                    .no-print { display: none; }
                }
            

/* ===== ORDER DETAILS MODAL TOTALS FIX =====
   Totals are separate from the responsive table so mobile will not render
   SUBTOTAL / DISCOUNT / GRAND TOTAL as item cards. */
.order-totals-summary {
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    background: #ffffff;
    padding: 0.75rem;
    margin-left: auto;
    max-width: 360px;
}
.order-totals-summary .order-total-line {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 1rem;
    padding: 0.65rem 0.85rem;
    border-radius: 10px;
    margin-bottom: 0.45rem;
    background: #f8fafc;
    color: #052A47;
}
.order-totals-summary .order-total-line:last-child {
    margin-bottom: 0;
}
.order-totals-summary .order-total-line span {
    font-weight: 700;
    letter-spacing: 0.02em;
}
.order-totals-summary .order-total-line strong {
    font-weight: 800;
    white-space: nowrap;
}
.order-totals-summary .discount-line strong {
    color: #dc3545;
}
.order-totals-summary .grand-total-line {
    background: #d1fae5;
    border-top: 2px solid #44D34E;
}
.order-totals-summary .grand-total-line span,
.order-totals-summary .grand-total-line strong {
    color: #047857;
}
@media (max-width: 767px) {
    .order-totals-summary {
        max-width: 100%;
        width: 100%;
        margin-top: 0.75rem !important;
        padding: 0.65rem;
    }
    .order-totals-summary .order-total-line {
        font-size: 0.86rem;
        padding: 0.6rem 0.75rem;
    }
}
</style>
        </head>
        <body>
            <div class="header">
                ' . $logo_html . '
                <h1>Sales Orders Report</h1>
                <p>Branch: ' . $branch_name_display . '</p>
                <p>Generated: ' . date('Y-m-d H:i:s') . '</p>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>Date Encoded</th>
                        <th>SO Order Number</th>
                        <th>Customer Code</th>
                        <th>Store Name</th>
                        <th>Customer Name</th>
                        <th>Item Code</th>
                        <th>Item Description</th>
                        <th>Discount</th>
                        <th>Net Price</th>
                        <th>Total Discount</th>
                        <th>Encoded by</th>
                    </tr>
                </thead>
                <tbody>';
        
        foreach ($rows as $row) {
            $html .= '<tr>
                <td>' . htmlspecialchars($row['date_encoded']) . '</td>
                <td>' . htmlspecialchars($row['so_order_number']) . '</td>
                <td>' . htmlspecialchars($row['customer_code']) . '</td>
                <td>' . htmlspecialchars($row['store_name']) . '</td>
                <td>' . htmlspecialchars($row['customer_name']) . '</td>
                <td>' . htmlspecialchars($row['item_code']) . '</td>
                <td>' . htmlspecialchars($row['item_description']) . '</td>
                <td>₱' . number_format((float)$row['discount'], 2) . '</td>
                <td>₱' . number_format((float)$row['net_price'], 2) . '</td>
                <td>₱' . number_format((float)$row['total_discount'], 2) . '</td>
                <td>' . htmlspecialchars($row['encoded_by']) . '</td>
            </tr>';
        }
        
        $html .= '</tbody>
            </table>
            <div class="footer">
                Total records: ' . count($rows) . '
            </div>
        </body>
        </html>';
        
        echo json_encode(['success' => true, 'html' => $html]);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// Handle AJAX request for Excel export data (legacy - kept for compatibility, but export_all_orders is now used)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'export_excel_data') {
    header('Content-Type: application/json');
    
    try {
        // Get filter parameters
        $status = isset($_POST['status']) && !empty($_POST['status']) && $_POST['status'] !== 'all' ? $_POST['status'] : '';
        $customer = isset($_POST['customer']) && !empty($_POST['customer']) ? $_POST['customer'] : '';
        $start_date = isset($_POST['start_date']) && !empty($_POST['start_date']) ? $_POST['start_date'] : '';
        $end_date = isset($_POST['end_date']) && !empty($_POST['end_date']) ? $_POST['end_date'] : '';
        $search = isset($_POST['search']) && !empty($_POST['search']) ? $_POST['search'] : '';
        
        // Build WHERE conditions
        $where_conditions = ["1=1"];
        $params = [];
        $param_types = "";
        
        // Branch filter
        if ($branch_column_exists && !$view_all_branches) {
            $where_conditions[] = "so.branch_id = ?";
            $params[] = $branch_id;
            $param_types .= "i";
        }
        
        // Created by filter - regular users only see their own orders
        if (!$view_all_branches) {
            $where_conditions[] = "so.created_by = ?";
            $params[] = $user_id;
            $param_types .= "i";
        }
        
        // Status filter
        if (!empty($status)) {
            $where_conditions[] = "so.order_status = ?";
            $params[] = $status;
            $param_types .= "s";
        }
        
        // Customer filter
        if (!empty($customer)) {
            $where_conditions[] = "c.customer_name = ?";
            $params[] = $customer;
            $param_types .= "s";
        }
        
=======
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
        // Date range filter
        if (!empty($start_date)) {
            $where_conditions[] = "DATE(so.order_date) >= ?";
            $params[] = $start_date;
            $param_types .= "s";
        }
        
        if (!empty($end_date)) {
            $where_conditions[] = "DATE(so.order_date) <= ?";
            $params[] = $end_date;
            $param_types .= "s";
        }
        
        // Search filter
        if (!empty($search)) {
            $search_param = "%" . $search . "%";
            $where_conditions[] = "(so.so_number LIKE ? OR c.customer_name LIKE ? OR i.item_name LIKE ? OR i.item_code LIKE ?)";
            $params[] = $search_param;
            $params[] = $search_param;
            $params[] = $search_param;
            $params[] = $search_param;
            $param_types .= "ssss";
        }
        
        // Query to get individual order items for Excel export - with customer_code, store_name, and created_by
        $sql = "SELECT 
                    so.so_id,
                    so.so_number,
                    so.order_date,
                    so.total_amount as order_total,
                    so.order_status,
                    so.branch_id,
                    c.customer_id,
                    c.customer_name,
                    c.customer_code,
                    c.store_name,
                    c.email,
                    c.phone_number,
                    c.address,
                    c.city,
                    b.branch_name,
                    soi.so_item_id,
                    soi.item_id,
                    soi.quantity_ordered,
                    soi.unit_price,
                    soi.line_total,
                    i.item_name,
                    i.item_code,
                    i.category,
                    soi.unit_type,
                    COALESCE(d.driver_name, 'No Driver') as assigned_driver,
                    CONCAT(u.first_name, ' ', u.last_name) as created_by_name
                FROM sales_orders so
                LEFT JOIN customers c ON so.customer_id = c.customer_id
                LEFT JOIN branches b ON so.branch_id = b.branch_id
                LEFT JOIN sales_order_items soi ON so.so_id = soi.so_id
                LEFT JOIN items i ON soi.item_id = i.item_id
                LEFT JOIN pick_lists pl ON so.so_id = pl.so_id
                LEFT JOIN drivers d ON pl.driver_id = d.driver_id
                LEFT JOIN users u ON so.created_by = u.user_id
                WHERE " . implode(" AND ", $where_conditions) . "
                ORDER BY so.order_date DESC, so.so_id ASC, soi.so_item_id ASC";
        
        // Prepare and execute
        if (!empty($params)) {
            $stmt = $conn->prepare($sql);
            if ($param_types && count($params) > 0) {
                $stmt->bind_param($param_types, ...$params);
            }
        } else {
            $stmt = $conn->prepare($sql);
        }
        
        if (!$stmt) {
            throw new Exception("Database prepare error: " . $conn->error);
        }
        
        $stmt->execute();
        $result = $stmt->get_result();
        $items = $result->fetch_all(MYSQLI_ASSOC);
        
        echo json_encode([
            'success' => true,
            'items' => $items
        ]);
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
    exit;
}

<<<<<<< HEAD
// Handle AJAX request for printing all orders (legacy - kept for compatibility)
=======
// Handle AJAX request for printing all orders
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'get_all_order_items') {
    header('Content-Type: application/json');
    
    try {
        $filter_data = json_decode($_POST['filter_data'] ?? '{}', true);
        
        $where_conditions = ["1=1"];
        $params = [];
        $param_types = "";
        
        // Branch filter
        if ($branch_column_exists && !$view_all_branches) {
            $where_conditions[] = "so.branch_id = ?";
            $params[] = $branch_id;
            $param_types .= "i";
        }
        
        // Created by filter - regular users only see their own orders, admins see all
        if (!$view_all_branches) {
            $where_conditions[] = "so.created_by = ?";
            $params[] = $user_id;
            $param_types .= "i";
        }
        
        // Status filter
        if (!empty($filter_data['status']) && $filter_data['status'] !== '') {
            $where_conditions[] = "so.order_status = ?";
            $params[] = $filter_data['status'];
            $param_types .= "s";
        }
        
        // Customer filter
        if (!empty($filter_data['customer']) && $filter_data['customer'] !== '') {
            $where_conditions[] = "c.customer_name = ?";
            $params[] = $filter_data['customer'];
            $param_types .= "s";
        }
        
        // Search filter
        if (!empty($filter_data['search']) && $filter_data['search'] !== '') {
            $search = "%" . $filter_data['search'] . "%";
            $where_conditions[] = "(so.so_number LIKE ? OR c.customer_name LIKE ? OR i.item_name LIKE ? OR i.item_code LIKE ?)";
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
            $param_types .= "ssss";
        }
        
        // Date range filter
        if (!empty($filter_data['start_date']) && !empty($filter_data['end_date'])) {
            $where_conditions[] = "DATE(so.order_date) BETWEEN ? AND ?";
            $params[] = $filter_data['start_date'];
            $params[] = $filter_data['end_date'];
            $param_types .= "ss";
        }
        
        $sql = "SELECT 
                    soi.so_item_id,
                    soi.so_id,
                    soi.item_id,
                    soi.quantity_ordered,
                    soi.quantity_delivered,
                    soi.unit_price,
                    soi.unit_type,
                    soi.line_total,
                    so.so_number,
                    so.order_date,
                    so.order_status,
                    so.total_amount as order_total,
                    c.customer_name,
                    c.customer_id,
                    b.branch_name,
                    b.branch_id,
                    i.item_code,
                    i.item_name,
                    i.category,
                    COALESCE(d.driver_name, 'No Driver') as assigned_driver
                FROM sales_order_items soi
                JOIN sales_orders so ON soi.so_id = so.so_id
                JOIN customers c ON so.customer_id = c.customer_id
                LEFT JOIN branches b ON so.branch_id = b.branch_id
                LEFT JOIN pick_lists pl ON so.so_id = pl.so_id
                LEFT JOIN drivers d ON pl.driver_id = d.driver_id
                JOIN items i ON soi.item_id = i.item_id
                WHERE " . implode(" AND ", $where_conditions) . "
                ORDER BY so.order_date DESC, so.so_id DESC, soi.so_item_id";
        
        if (!empty($params)) {
            $stmt = $conn->prepare($sql);
            if ($param_types && count($params) > 0) {
                $stmt->bind_param($param_types, ...$params);
            }
        } else {
            $stmt = $conn->prepare($sql);
        }
        
        if (!$stmt) {
            throw new Exception("Database prepare error: " . $conn->error);
        }
        
        $stmt->execute();
        $result = $stmt->get_result();
        $items = $result->fetch_all(MYSQLI_ASSOC);
        
        echo json_encode([
            'success' => true,
            'items' => $items,
            'branch_name' => $branch_id ? ('Branch ' . $branch_id) : 'All Branches',
            'view_all' => $view_all_branches
        ]);
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
    exit;
}

// Handle AJAX request for printing single order with driver info
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'print_order') {
    header('Content-Type: application/json');
    
    try {
        $so_id = (int)$_POST['so_id'];
        
        // Verify order access permissions
        if (!$view_all_branches) {
            // Regular user - must be their order
            $check_sql = "SELECT so_id FROM sales_orders WHERE so_id = ? AND branch_id = ? AND created_by = ?";
            $check_stmt = $conn->prepare($check_sql);
            $check_stmt->bind_param('iii', $so_id, $branch_id, $user_id);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            
            if ($check_result->num_rows === 0) {
                throw new Exception('Order not found or access denied');
            }
        } else if ($branch_column_exists) {
            // Admin can access any order, just verify it exists
            $check_sql = "SELECT so_id FROM sales_orders WHERE so_id = ?";
            $check_stmt = $conn->prepare($check_sql);
            $check_stmt->bind_param('i', $so_id);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            
            if ($check_result->num_rows === 0) {
                throw new Exception('Order not found');
            }
        }
        
        $sql = "SELECT 
                    soi.so_item_id,
                    soi.so_id,
                    soi.item_id,
                    soi.quantity_ordered,
                    soi.quantity_delivered,
                    soi.unit_price,
                    soi.unit_type,
                    so.so_number,
                    so.order_date,
                    so.order_status,
                    so.total_amount as order_total,
                    c.customer_name,
                    c.customer_id,
                    c.address,
                    c.phone_number as contact_number,
                    c.email,
                    b.branch_name,
                    b.address as branch_address,
                    b.contact_number as branch_contact,
                    u.first_name,
                    u.last_name,
                    i.item_code,
                    i.item_name,
                    COALESCE(d.driver_name, 'No Driver') as assigned_driver,
                    d.vehicle_plate_number,
                    d.vehicle_type
                FROM sales_order_items soi
                JOIN sales_orders so ON soi.so_id = so.so_id
                JOIN customers c ON so.customer_id = c.customer_id
                LEFT JOIN branches b ON so.branch_id = b.branch_id
                LEFT JOIN users u ON so.created_by = u.user_id
                LEFT JOIN pick_lists pl ON so.so_id = pl.so_id
                LEFT JOIN drivers d ON pl.driver_id = d.driver_id
                JOIN items i ON soi.item_id = i.item_id
                WHERE soi.so_id = ?
                ORDER BY soi.so_item_id";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $so_id);
        $stmt->execute();
        $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        
        // Get driver details separately if available (for complete info)
        $driver_query = "
            SELECT d.driver_name, d.vehicle_plate_number, d.vehicle_type
            FROM pick_lists pl
            JOIN drivers d ON pl.driver_id = d.driver_id
            WHERE pl.so_id = ?
            LIMIT 1
        ";
        $driver_stmt = $conn->prepare($driver_query);
        $driver_stmt->bind_param("i", $so_id);
        $driver_stmt->execute();
        $driver = $driver_stmt->get_result()->fetch_assoc();
        
        // Get order summary from first item
        $order_summary = !empty($items) ? $items[0] : null;
<<<<<<< HEAD
        if (!$order_summary || !in_array(strtolower((string)$order_summary['order_status']), ['pending', 'confirmed'], true)) {
            throw new Exception('Print receipt is only available for Pending and Confirmed orders.');
        }
=======
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
        
        echo json_encode([
            'success' => true,
            'order' => $order_summary,
            'items' => $items,
            'driver' => $driver
        ]);
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
    exit;
}

// Detect AJAX request
$is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
           strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';

// Handle AJAX requests - return ONLY JSON
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'];
    
    if ($action === 'get_order_details' && isset($_POST['order_id'])) {
        $order_id = (int)$_POST['order_id'];
        
        try {
            // Verify order access permissions
            if (!$view_all_branches) {
                // Regular user - must be their order
                $check_sql = "SELECT so_id FROM sales_orders WHERE so_id = ? AND branch_id = ? AND created_by = ?";
                $check_stmt = $conn->prepare($check_sql);
                if (!$check_stmt) {
                    throw new Exception("Database prepare error");
                }
                $check_stmt->bind_param('iii', $order_id, $branch_id, $user_id);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                
                if ($check_result->num_rows === 0) {
                    echo json_encode(['success' => false, 'message' => 'Order not found or access denied']);
                    exit;
                }
            } else if ($branch_column_exists) {
                // Admin can access any order, just verify it exists
                $check_sql = "SELECT so_id FROM sales_orders WHERE so_id = ?";
                $check_stmt = $conn->prepare($check_sql);
                if (!$check_stmt) {
                    throw new Exception("Database prepare error");
                }
                $check_stmt->bind_param('i', $order_id);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                
                if ($check_result->num_rows === 0) {
                    echo json_encode(['success' => false, 'message' => 'Order not found']);
                    exit;
                }
            }
            
<<<<<<< HEAD
            // Get order details with driver info, store_name, and saved discount totals.
            // Column-safe expressions prevent Unknown column errors on older databases.
            $so_discount_percent_expr = amgc_sales_column_exists($conn, 'sales_orders', 'discount_percent') ? "COALESCE(so.discount_percent, 0)" : "0";
            $so_discount_amount_expr = amgc_sales_column_exists($conn, 'sales_orders', 'discount_amount') ? "COALESCE(so.discount_amount, 0)" : "0";
            $so_total_discount_expr = amgc_sales_column_exists($conn, 'sales_orders', 'total_discount_amount') ? "COALESCE(so.total_discount_amount, 0)" : "0";
            $so_discount_type_expr = amgc_sales_column_exists($conn, 'sales_orders', 'discount_calculation_type') ? "COALESCE(so.discount_calculation_type, 'percentage')" : "'percentage'";
            $so_discount_based_expr = amgc_sales_column_exists($conn, 'sales_orders', 'discount_based_amount') ? "COALESCE(so.discount_based_amount, 0)" : "0";

            $soi_gross_price_expr_for_subtotal = amgc_sales_column_exists($conn, 'sales_order_items', 'gross_price')
                ? "COALESCE(NULLIF(soi_sub.gross_price, 0), NULLIF(soi_sub.unit_price, 0), 0)"
                : "COALESCE(NULLIF(soi_sub.unit_price, 0), 0)";

=======
            // Get order details with driver info and store_name
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
            $sql = "SELECT 
                        so.so_id,
                        so.so_number,
                        so.order_date,
                        so.total_amount,
                        ($so_discount_percent_expr) AS discount_percent,
                        ($so_discount_amount_expr) AS discount_amount,
                        ($so_total_discount_expr) AS total_discount_amount,
                        ($so_discount_type_expr) AS discount_calculation_type,
                        ($so_discount_based_expr) AS discount_based_amount,
                        (
                            SELECT COALESCE(SUM(soi_sub.quantity_ordered * $soi_gross_price_expr_for_subtotal), 0)
                            FROM sales_order_items soi_sub
                            WHERE soi_sub.so_id = so.so_id
                        ) AS order_subtotal,
                        so.order_status,
                        so.branch_id,
                        c.customer_name,
                        c.store_name,
                        c.customer_code,
                        c.email,
                        c.phone_number,
                        c.address,
                        u.first_name as created_by,
                        b.branch_name,
                        COALESCE(d.driver_name, 'No Driver') as assigned_driver
                    FROM sales_orders so
                    LEFT JOIN customers c ON so.customer_id = c.customer_id
                    LEFT JOIN users u ON so.created_by = u.user_id
                    LEFT JOIN branches b ON so.branch_id = b.branch_id
                    LEFT JOIN pick_lists pl ON so.so_id = pl.so_id
                    LEFT JOIN drivers d ON pl.driver_id = d.driver_id
                    WHERE so.so_id = ?";
            
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                throw new Exception("Database prepare error");
            }
            $stmt->bind_param('i', $order_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows === 0) {
                echo json_encode(['success' => false, 'message' => 'Order not found']);
                exit;
            }
            
            $order = $result->fetch_assoc();
            
            // Get order items with column-safe gross/net/discount fields.
            $soi_gross_price_expr = amgc_sales_column_exists($conn, 'sales_order_items', 'gross_price') ? "COALESCE(soi.gross_price, 0)" : "0";
            $soi_net_price_expr = amgc_sales_column_exists($conn, 'sales_order_items', 'net_price') ? "COALESCE(soi.net_price, soi.unit_price, 0)" : "COALESCE(soi.unit_price, 0)";
            $soi_order_amount_expr = amgc_sales_column_exists($conn, 'sales_order_items', 'order_amount') ? "COALESCE(soi.order_amount, 0)" : "0";
            $soi_total_discount_expr = amgc_sales_column_exists($conn, 'sales_order_items', 'total_discount') ? "COALESCE(soi.total_discount, 0)" : "0";
            $soi_discount_amount_expr = amgc_sales_column_exists($conn, 'sales_order_items', 'discount_amount') ? "COALESCE(soi.discount_amount, 0)" : "0";

            $items_sql = "SELECT 
                            soi.so_item_id,
                            soi.so_id,
                            soi.item_id,
                            soi.quantity_ordered,
                            soi.quantity_delivered,
                            soi.unit_price,
                            soi.line_total,
                            ($soi_gross_price_expr) AS gross_price,
                            ($soi_net_price_expr) AS net_price,
                            ($soi_order_amount_expr) AS order_amount,
                            ($soi_total_discount_expr) AS total_discount,
                            ($soi_discount_amount_expr) AS discount_amount,
                            i.item_name,
                            i.item_code,
                            soi.unit_type
                         FROM sales_order_items soi
                         JOIN items i ON soi.item_id = i.item_id
                         WHERE soi.so_id = ?
                         ORDER BY soi.so_item_id";
            $items_stmt = $conn->prepare($items_sql);
            if (!$items_stmt) {
                throw new Exception("Database prepare error");
            }
            $items_stmt->bind_param('i', $order_id);
            $items_stmt->execute();
            $items_result = $items_stmt->get_result();
            $items = $items_result->fetch_all(MYSQLI_ASSOC);
            
            echo json_encode([
                'success' => true,
                'order' => $order,
                'items' => $items
            ]);
            
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
    
    // Handle cancel order action
    if ($action === 'cancel_order' && isset($_POST['order_id'])) {
        $order_id = (int)$_POST['order_id'];
        
        try {
            // Verify order belongs to user (regular users can only cancel their own orders)
            if (!$view_all_branches) {
                // Regular user - must be their order
                $check_sql = "SELECT so_id FROM sales_orders WHERE so_id = ? AND branch_id = ? AND created_by = ?";
                $check_stmt = $conn->prepare($check_sql);
                if (!$check_stmt) {
                    throw new Exception("Database error");
                }
                $check_stmt->bind_param('iii', $order_id, $branch_id, $user_id);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                
                if ($check_result->num_rows === 0) {
                    echo json_encode(['success' => false, 'message' => 'Order not found or access denied']);
                    exit;
                }
            } else if ($branch_column_exists) {
                // Admin can access any order, but just verify it exists
                $check_sql = "SELECT so_id FROM sales_orders WHERE so_id = ?";
                $check_stmt = $conn->prepare($check_sql);
                if (!$check_stmt) {
                    throw new Exception("Database error");
                }
                $check_stmt->bind_param('i', $order_id);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                
                if ($check_result->num_rows === 0) {
                    echo json_encode(['success' => false, 'message' => 'Order not found']);
                    exit;
                }
            }
            
            // Start transaction
            $conn->begin_transaction();
            
            // Delete related records first (order items)
            $delete_items_sql = "DELETE FROM sales_order_items WHERE so_id = ?";
            $delete_items_stmt = $conn->prepare($delete_items_sql);
            if (!$delete_items_stmt) {
                throw new Exception("Database error: " . $conn->error);
            }
            $delete_items_stmt->bind_param('i', $order_id);
            if (!$delete_items_stmt->execute()) {
                throw new Exception("Failed to delete order items: " . $delete_items_stmt->error);
            }
            
            // Delete pick lists if exists
            $delete_picklist_sql = "DELETE FROM pick_lists WHERE so_id = ?";
            $delete_picklist_stmt = $conn->prepare($delete_picklist_sql);
            if (!$delete_picklist_stmt) {
                throw new Exception("Database error: " . $conn->error);
            }
            $delete_picklist_stmt->bind_param('i', $order_id);
            if (!$delete_picklist_stmt->execute()) {
                throw new Exception("Failed to delete pick lists: " . $delete_picklist_stmt->error);
            }
            
            // Delete the order itself
            $delete_order_sql = "DELETE FROM sales_orders WHERE so_id = ?";
            $delete_order_stmt = $conn->prepare($delete_order_sql);
            if (!$delete_order_stmt) {
                throw new Exception("Database error: " . $conn->error);
            }
            $delete_order_stmt->bind_param('i', $order_id);
            if (!$delete_order_stmt->execute()) {
                throw new Exception("Failed to delete order: " . $delete_order_stmt->error);
            }
            
            // Commit transaction
            $conn->commit();
            
            echo json_encode([
                'success' => true,
                'message' => 'Order cancelled successfully'
            ]);
            
        } catch (Exception $e) {
            // Rollback on error
            $conn->rollback();
            echo json_encode([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
        exit;
    }
    
    // Invalid action
    echo json_encode(['success' => false, 'message' => 'Invalid action']);
    exit;
}

// If it's an AJAX request but no valid action, return error
if ($is_ajax) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

// Handle search and filters
$where_conditions = ["1=1"];
$params = [];
$param_types = "";

// Branch filter - only apply if branch column exists
if ($branch_column_exists) {
    if ($view_all_branches) {
        // Admin sees all branches - no filter needed
    } else {
        // Regular user sees only their branch
        $where_conditions[] = "so.branch_id = ?";
        $params[] = $branch_id;
        $param_types .= "i";
    }
}

// Created by filter - regular users only see their own orders, admins see all
if (!$view_all_branches) {
    $where_conditions[] = "so.created_by = ?";
    $params[] = $user_id;
    $param_types .= "i";
}

// Status filter
if (isset($_GET['status']) && !empty($_GET['status']) && $_GET['status'] !== 'all') {
    $where_conditions[] = "so.order_status = ?";
    $params[] = $_GET['status'];
    $param_types .= "s";
}

// Date range filter
if (isset($_GET['start_date']) && !empty($_GET['start_date'])) {
    $where_conditions[] = "DATE(so.order_date) >= ?";
    $params[] = $_GET['start_date'];
    $param_types .= "s";
}

if (isset($_GET['end_date']) && !empty($_GET['end_date'])) {
    $where_conditions[] = "DATE(so.order_date) <= ?";
    $params[] = $_GET['end_date'];
    $param_types .= "s";
}

// Search by order number or customer name
if (isset($_GET['search']) && !empty($_GET['search'])) {
    $search = "%" . $_GET['search'] . "%";
    $where_conditions[] = "(so.so_number LIKE ? OR c.customer_name LIKE ?)";
    $params[] = $search;
    $params[] = $search;
    $param_types .= "ss";
}

// Build query with branch information and driver info
$sql = "SELECT 
            so.so_id,
            so.so_number,
            so.order_date,
            so.total_amount,
            so.order_status,
            so.branch_id,
            c.customer_name,
            b.branch_name,
            (SELECT COUNT(*) FROM sales_order_items WHERE so_id = so.so_id) as item_count,
            (SELECT GROUP_CONCAT(i.item_name SEPARATOR ', ') 
             FROM sales_order_items soi 
             JOIN items i ON soi.item_id = i.item_id 
             WHERE soi.so_id = so.so_id) as item_names,
            (SELECT GROUP_CONCAT(CONCAT(i.item_name, ' (', soi.quantity_ordered, ' ', soi.unit_type, ')') SEPARATOR ', ') 
             FROM sales_order_items soi 
             JOIN items i ON soi.item_id = i.item_id 
             WHERE soi.so_id = so.so_id) as item_names_with_qty,
            COALESCE(d.driver_name, 'No Driver') as assigned_driver
        FROM sales_orders so
        LEFT JOIN customers c ON so.customer_id = c.customer_id
        LEFT JOIN branches b ON so.branch_id = b.branch_id
        LEFT JOIN pick_lists pl ON so.so_id = pl.so_id
        LEFT JOIN drivers d ON pl.driver_id = d.driver_id
        WHERE " . implode(" AND ", $where_conditions) . "
        ORDER BY so.order_date DESC";

// Prepare and execute
if (!empty($params)) {
    $stmt = $conn->prepare($sql);
    if ($param_types && count($params) > 0) {
        $stmt->bind_param($param_types, ...$params);
    }
} else {
    $stmt = $conn->prepare($sql);
}
$stmt->execute();
$result = $stmt->get_result();
$orders = $result->fetch_all(MYSQLI_ASSOC);

// Get order statistics - branch and user specific
if ($branch_column_exists && !$view_all_branches) {
    // Regular user - see only their orders
    $stats_sql = "SELECT 
                    COUNT(*) as total_orders,
                    SUM(CASE WHEN order_status = 'pending' THEN 1 ELSE 0 END) as pending,
                    SUM(CASE WHEN order_status = 'processing' THEN 1 ELSE 0 END) as processing,
                    SUM(CASE WHEN order_status = 'shipped' THEN 1 ELSE 0 END) as shipped,
                    SUM(CASE WHEN order_status = 'delivered' THEN 1 ELSE 0 END) as delivered,
                    SUM(CASE WHEN order_status = 'cancelled' THEN 1 ELSE 0 END) as cancelled,
                    COALESCE(SUM(total_amount), 0) as total_revenue
                  FROM sales_orders 
                  WHERE branch_id = ? AND created_by = ?";
    $stats_stmt = $conn->prepare($stats_sql);
    $stats_stmt->bind_param('ii', $branch_id, $user_id);
} else {
    // Admin sees all orders
    $stats_sql = "SELECT 
                    COUNT(*) as total_orders,
                    SUM(CASE WHEN order_status = 'pending' THEN 1 ELSE 0 END) as pending,
                    SUM(CASE WHEN order_status = 'processing' THEN 1 ELSE 0 END) as processing,
                    SUM(CASE WHEN order_status = 'shipped' THEN 1 ELSE 0 END) as shipped,
                    SUM(CASE WHEN order_status = 'delivered' THEN 1 ELSE 0 END) as delivered,
                    SUM(CASE WHEN order_status = 'cancelled' THEN 1 ELSE 0 END) as cancelled,
                    COALESCE(SUM(total_amount), 0) as total_revenue
                  FROM sales_orders";
    $stats_stmt = $conn->prepare($stats_sql);
}
$stats_stmt->execute();
$stats_result = $stats_stmt->get_result();
$stats = $stats_result->fetch_assoc();

// Get unique customers for filter
$customers_query = "SELECT customer_id, customer_name FROM customers WHERE status = 'active' ORDER BY customer_name";
$customers_result = $conn->query($customers_query);
$customers = $customers_result ? $customers_result->fetch_all(MYSQLI_ASSOC) : [];

// Get base64 encoded logo for printing
$logo_path = '../Pictures/amgc3DLogo.png';
$logo_base64 = '';
if (file_exists($logo_path)) {
    $image_data = file_get_contents($logo_path);
    $logo_base64 = 'data:image/png;base64,' . base64_encode($image_data);
}

// Helper functions
function getOrderStatusBadge($status) {
    return match($status) {
        'pending' => 'badge bg-warning text-dark',
<<<<<<< HEAD
        'confirmed' => 'badge bg-secondary text-white',
=======
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
        'processing' => 'badge bg-info text-white',
        'shipped' => 'badge bg-primary text-white',
        'delivered' => 'badge bg-success text-white',
        'cancelled' => 'badge bg-danger text-white',
        default => 'badge bg-secondary text-white'
    };
}

function getOrderStatusText($status) {
    return match($status) {
        'pending' => 'Pending',
<<<<<<< HEAD
        'confirmed' => 'Confirmed',
=======
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
        'processing' => 'Processing',
        'shipped' => 'Shipped',
        'delivered' => 'Delivered',
        'cancelled' => 'Cancelled',
        default => ucfirst($status)
    };
}

function formatDate($dateStr) {
    if (!$dateStr) return '';
    $date = new DateTime($dateStr);
    return $date->format('M d, Y');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sales Orders - Sales Dashboard</title>
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
    <!-- Google Fonts - Tenor Sans and Alice -->
    <link href="https://fonts.googleapis.com/css2?family=Tenor+Sans&family=Alice&display=swap" rel="stylesheet">
    <!-- SweetAlert2 -->
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <!-- SheetJS for Excel export -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
<<<<<<< HEAD
        <!-- Session Checker -->
    <script src="../js/session-checker.js"></script>
=======
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
    <style>
        /* Brand Colors - Keep UI exactly the same */
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
        
        /* Driver badge styling */
        .driver-badge {
            background-color: #e3f2fd;
            color: #0d6efd;
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .driver-badge i {
            font-size: 12px;
        }
        
        /* No driver badge styling */
        .no-driver-badge {
            background-color: #f8f9fa;
            color: #6c757d;
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            border: 1px dashed #6c757d;
        }
        .no-driver-badge i {
            font-size: 12px;
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
.stat-card.total {
    background: linear-gradient(135deg, #047857, #059669) !important;
}

.stat-card.pending {
    background: linear-gradient(135deg, #047857, #059669) !important;
}

.stat-card.sales {
    background: linear-gradient(135deg, #047857, #059669) !important;
}

.stat-card.complete {
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
    
    /* Badge styling for mobile */
    .stat-card .badge {
        display: inline-block !important;
        font-size: 0.5rem !important;
        padding: 0.2rem 0.4rem !important;
        margin-top: 0.2rem !important;
        text-align: center !important;
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
        padding: 0.3rem !important;
    }
    
    .stat-card i,
    .stat-card .stat-icon {
        font-size: 1.2rem !important;
        margin-bottom: 0.2rem !important;
    }
    
    .stat-card .stat-value {
        font-size: 0.9rem !important;
    }
    
    .stat-card .stat-label {
        font-size: 0.55rem !important;
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
/* ===== IMPROVED MOBILE TEXT RESPONSIVENESS ===== */
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
        font-size: 1rem !important; /* Reduced from 1.2rem */
        font-weight: bold !important;
        line-height: 1.2 !important;
        margin: 0.2rem 0 !important;
        width: 100% !important;
        word-break: break-word !important; /* Para mag-break ang mahabang numbers */
        overflow-wrap: break-word !important;
    }
    
    .stat-card .stat-label {
        display: block !important;
        text-align: center !important;
        font-size: 0.65rem !important; /* Reduced from 0.7rem */
        font-weight: 500 !important;
        width: 100% !important;
        word-break: break-word !important;
        white-space: normal !important; /* Para mag-wrap ang text */
        line-height: 1.3 !important;
    }
    
    /* Hide the branch name on mobile to save space */
    .stat-card small {
        display: none !important;
    }
}

/* For extra small devices (phones below 576px) */
@media (max-width: 576px) {
    .stat-card {
        padding: 0.3rem !important;
    }
    
    .stat-card i,
    .stat-card .stat-icon {
        font-size: 1.3rem !important;
        margin-bottom: 0.2rem !important;
    }
    
    .stat-card .stat-value {
        font-size: 0.85rem !important; /* Smaller font for very small screens */
    }
    
    .stat-card .stat-label {
        font-size: 0.55rem !important;
    }
}

/* For very small devices (below 400px) */
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

/* Para sa 2-line text sa label (e.g., "Total Orders" -> pwedeng mag-break) */
.stat-card .stat-label {
    display: -webkit-box !important;
    -webkit-line-clamp: 2 !important;
    -webkit-box-orient: vertical !important;
    overflow: hidden !important;
    text-overflow: ellipsis !important;
}

        /* OPTIMIZED PRINT STYLES - BLACK AND WHITE, MINIMAL WHITESPACE */
        @media print {
            @page {
                size: landscape;
                margin: 0.3in;
            }
            
            body {
                background: #fff;
                font-family: Arial, Helvetica, sans-serif;
                font-size: 10px;
                line-height: 1.2;
            }
            
            .no-print, .sidebar, .navbar-top, .stat-card, .card-header, 
            .btn, .modal, .action-buttons, .mobile-toggle-btn, 
            .desktop-toggle-btn, .logout-btn-sidebar {
                display: none !important;
            }
            
            .main-content {
                margin: 0 !important;
                padding: 0 !important;
                width: 100% !important;
            }
            
            .print-container {
                padding: 10px;
            }
            
            .print-header {
                display: flex;
                align-items: center;
                justify-content: space-between;
                margin-bottom: 10px;
                padding-bottom: 5px;
                border-bottom: 1px solid #000;
            }
            
            .logo-section {
                display: flex;
                align-items: center;
                gap: 10px;
            }
            
            .company-logo {
                width: 40px;
                height: auto;
            }
            
            .company-info h1 {
                font-size: 18px;
                font-weight: bold;
                margin: 0;
                color: #000;
            }
            
            .company-info p {
                font-size: 8px;
                margin: 0;
                color: #333;
            }
            
            .report-title h2 {
                font-size: 16px;
                font-weight: bold;
                margin: 0;
                color: #000;
            }
            
            .report-title .date-info {
                font-size: 8px;
                color: #333;
            }
            
            .summary-box {
                border: 1px solid #000;
                padding: 8px;
                margin-bottom: 10px;
                display: flex;
                background: #f9f9f9;
            }
            
            .summary-item {
                text-align: center;
                flex: 1;
                border-right: 1px solid #000;
            }
            
            .summary-item:last-child {
                border-right: none;
            }
            
            .summary-label {
                font-size: 8px;
                font-weight: bold;
                text-transform: uppercase;
                margin-bottom: 2px;
            }
            
            .summary-value {
                font-size: 12px;
                font-weight: bold;
            }
            
            table {
                width: 100%;
                border-collapse: collapse;
                margin: 8px 0;
            }
            
            th {
                background: #f0f0f0 !important;
                color: #000 !important;
                border: 1px solid #000;
                padding: 4px;
                font-size: 9px;
                font-weight: bold;
            }
            
            td {
                border: 1px solid #000;
                padding: 3px;
                font-size: 9px;
            }
            
            tr:nth-child(even) {
                background: #f9f9f9;
            }
            
            .total-row {
                background: #e0e0e0 !important;
                font-weight: bold;
            }
            
            .total-row td {
                color: #000;
                border: 1px solid #000;
            }
            
            .status-badge-print {
                border: 1px solid #000;
                padding: 2px 5px;
                font-size: 8px;
                font-weight: bold;
                background: #fff;
            }
            
            .print-footer {
                margin-top: 15px;
                padding-top: 5px;
                border-top: 1px solid #000;
                display: flex;
                justify-content: space-between;
                font-size: 8px;
            }
            
            .signature-line {
                width: 120px;
                border-bottom: 1px solid #000;
                margin-top: 3px;
            }
        }

        /* Action Buttons Styling */
        .action-buttons {
            display: flex;
            gap: 5px;
        }
        
        .btn-action {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
            border: 1px solid transparent;
        }
        
        .btn-view {
            background-color: #e3f2fd;
            color: #1976d2;
            border-color: #bbdefb;
        }
        
        .btn-view:hover {
            background-color: #bbdefb;
            transform: translateY(-2px);
        }
        
        .btn-print {
            background-color: #e8f5e9;
            color: var(--green);
            border-color: #c8e6c9;
        }
        
        .btn-print:hover {
            background-color: #c8e6c9;
            transform: translateY(-2px);
        }
        
        /* Item names tooltip */
        .item-names-tooltip {
            cursor: help;
            border-bottom: 1px dotted #999;
            max-width: 200px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            display: inline-block;
        }
        
        /* For Excel export - we want full text, but this is just for display */
        .full-item-names {
            display: none;
        }
        
        /* Loading overlay */
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(255, 255, 255, 0.8);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 9999;
            flex-direction: column;
        }
        
        .loading-spinner {
            width: 50px;
            height: 50px;
            border: 5px solid #f3f3f3;
            border-top: 5px solid var(--green);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* Print frame */
        #printFrame {
            position: absolute;
            left: -9999px;
            top: -9999px;
            width: 1px;
            height: 1px;
            opacity: 0;
            pointer-events: none;
        }

        /* Date range summary */
        .date-range-summary {
            background: linear-gradient(135deg, #e3f2fd, #bbdefb);
            border-left: 4px solid #1976d2;
            border-radius: 8px;
            padding: 12px 16px;
            margin-bottom: 20px;
            font-size: 14px;
        }
        .date-range-summary i {
            color: #1976d2;
            margin-right: 8px;
        }
        .date-range-summary strong {
            color: #0d47a1;
        }
        
        /* Stat card hover effect */
        .stat-card {
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        
        /* Date filter styling */
        .date-filter-container {
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
        }
        .date-input-group {
            display: flex;
            align-items: center;
            gap: 5px;
        }
        .date-input-group label {
            font-size: 12px;
            font-weight: 600;
            color: #495057;
            margin-bottom: 0;
            white-space: nowrap;
        }
        .date-input-group input {
            width: 140px;
            padding: 8px 12px;
            border: 1px solid #ced4da;
            border-radius: 4px;
            font-size: 13px;
        }
        .date-filter-btn {
            padding: 8px 16px;
            background-color: #1976d2;
            color: white;
            border: none;
            border-radius: 4px;
            font-size: 13px;
            cursor: pointer;
            transition: background-color 0.2s;
        }
        .date-filter-btn:hover {
            background-color: #0d47a1;
        }
        .date-clear-btn {
            padding: 8px 16px;
            background-color: #6c757d;
            color: white;
            border: none;
            border-radius: 4px;
            font-size: 13px;
            cursor: pointer;
            transition: background-color 0.2s;
        }
        .date-clear-btn:hover {
            background-color: #5a6268;
        }
        .date-reset-btn {
            padding: 8px 16px;
            background-color: #2E7D32;
            color: white;
            border: none;
            border-radius: 4px;
            font-size: 13px;
            cursor: pointer;
            transition: background-color 0.2s;
        }
        .date-reset-btn:hover {
            background-color: #1B5E20;
        }
        /* ===== FILTER SECTION STYLES (Like Picklist) ===== */

/* Form Card */
.form-card {
    background: white;
    border-radius: 16px;
    padding: 1.25rem;
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.04);
    border: 1px solid #d1fae5;
    margin-bottom: 1.5rem;
    transition: all 0.3s ease;
    width: 100%;
}

.form-card:hover {
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.06);
}

/* Filter Header */
.filter-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    width: 100%;
    cursor: pointer;
}

.filter-header h5 {
    margin-bottom: 0 !important;
    padding-bottom: 0 !important;
    font-size: 1rem;
    font-weight: 600;
    color: #1e293b;
    display: flex;
    align-items: center;
    gap: 8px;
}

.filter-header h5 i {
    color: #047857;
    background: rgba(4, 120, 87, 0.1);
    padding: 0.5rem;
    border-radius: 10px;
    font-size: 1rem;
}

/* Filter toggle button */
.filter-toggle-btn {
    background: transparent;
    border: none;
    width: 36px;
    height: 36px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s ease;
    cursor: pointer;
    padding: 0;
}

.filter-toggle-btn i {
    font-size: 1.2rem;
    transition: transform 0.3s ease;
    color: #047857;
}

.filter-toggle-btn:hover {
    background: rgba(4, 120, 87, 0.1);
}

.filter-toggle-btn[aria-expanded="true"] i {
    transform: rotate(180deg);
}

/* Filter content */
.filter-content {
    transition: all 0.3s ease-in-out;
    overflow: hidden;
}

.filter-content.collapsed {
    display: none;
}

/* Add border line when filter is expanded */
.filter-header + .filter-content:not(.collapsed) {
    border-top: 2px solid rgba(4, 120, 87, 0.15);
    margin-top: 1rem;
    padding-top: 1rem;
}

/* Form Labels */
.form-label {
    font-weight: 600;
    color: #1e293b;
    margin-bottom: 0.4rem;
    font-size: 0.8rem;
    display: flex;
    align-items: center;
    gap: 0.3rem;
}

.form-label i {
    color: #047857;
    font-size: 0.85rem;
}

/* Form Controls */
.form-control,
.form-select {
    border: 1px solid #e5e7eb;
    border-radius: 10px;
    padding: 0.6rem 0.85rem;
    font-size: 0.9rem;
    background-color: #fff;
    width: 100%;
    transition: all 0.2s ease;
}

.form-control:focus,
.form-select:focus {
    border-color: #44D34E;
    box-shadow: 0 0 0 3px rgba(68, 211, 78, 0.15);
    outline: none;
}

/* Action Buttons */
.date-filter-btn,
.date-reset-btn,
.date-clear-btn {
    padding: 0.6rem 1rem;
    font-size: 0.85rem;
    font-weight: 500;
    border-radius: 10px;
    transition: all 0.2s ease;
    height: 42px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.3rem;
    cursor: pointer;
    border: none;
}

.date-filter-btn {
    background: linear-gradient(135deg, #047857, #059669);
    color: white;
}

.date-filter-btn:hover {
    background: linear-gradient(135deg, #065f46, #047857);
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(4, 120, 87, 0.25);
}

.date-reset-btn {
    background: white;
    border: 1px solid #d1fae5;
    color: #047857;
}

.date-reset-btn:hover {
    background: #d1fae5;
    border-color: #047857;
    transform: translateY(-1px);
}

.date-clear-btn {
    background: white;
    border: 1px solid #fee2e2;
    color: #dc2626;
}

.date-clear-btn:hover {
    background: #fee2e2;
    border-color: #dc2626;
    transform: translateY(-1px);
}

/* Date Range Summary */
.date-range-summary {
    background: #e8f0fe;
    border-left: 4px solid #667eea;
    padding: 0.75rem 1rem;
    border-radius: 8px;
    font-size: 0.875rem;
    color: #2c3e50;
    margin-bottom: 1rem;
}

.date-range-summary i {
    margin-right: 0.5rem;
    color: #667eea;
}

/* ===== MOBILE RESPONSIVE ===== */

/* Mobile (max-width: 767px) */
@media (max-width: 767px) {
    .form-card {
        padding: 0.85rem;
    }
    
    .filter-header h5 {
        font-size: 0.85rem;
    }
    
    .filter-header h5 i {
        padding: 0.35rem;
        font-size: 0.8rem;
    }
    
    .filter-toggle-btn {
        width: 30px;
        height: 30px;
    }
    
    .filter-toggle-btn i {
        font-size: 0.9rem;
    }
    
    /* Date inputs magkatabi sa mobile */
    .row.g-3.mb-3 .col-6 {
        padding-left: 0.5rem;
        padding-right: 0.5rem;
    }
    
    .form-label {
        font-size: 0.7rem;
        margin-bottom: 0.3rem;
    }
    
    .form-control,
    .form-select {
        padding: 0.45rem 0.65rem;
        font-size: 0.8rem;
    }
    
    /* Buttons magkakatabi sa mobile */
    .date-filter-btn,
    .date-reset-btn,
    .date-clear-btn {
        padding: 0.45rem 0.5rem;
        font-size: 0.75rem;
        height: 36px;
    }
    
    .date-filter-btn i,
    .date-reset-btn i,
    .date-clear-btn i {
        font-size: 0.75rem;
    }
}

/* Extra Small Mobile (max-width: 480px) */
@media (max-width: 480px) {
    .form-card {
        padding: 0.7rem;
    }
    
    .form-label {
        font-size: 0.65rem;
    }
    
    .form-control,
    .form-select {
        padding: 0.4rem 0.55rem;
        font-size: 0.75rem;
    }
    
    .date-filter-btn,
    .date-reset-btn,
    .date-clear-btn {
        padding: 0.4rem 0.4rem;
        font-size: 0.7rem;
        height: 34px;
    }
    
    .date-filter-btn i,
    .date-reset-btn i,
    .date-clear-btn i {
        font-size: 0.7rem;
    }
}

/* Very Small (max-width: 380px) */
@media (max-width: 380px) {
    .date-filter-btn,
    .date-reset-btn,
    .date-clear-btn {
        padding: 0.35rem 0.3rem;
        font-size: 0.65rem;
        height: 32px;
    }
    
    /* Hide button text, show only icons */
    .date-filter-btn,
    .date-reset-btn,
    .date-clear-btn {
        font-size: 0;
    }
    
    .date-filter-btn i,
    .date-reset-btn i,
    .date-clear-btn i {
        font-size: 0.8rem;
        margin: 0;
    }
}
/* ===== ACTION BUTTONS STYLES ===== */

/* Action Buttons Container */
.mb-3.d-flex.gap-2 {
    display: flex;
    gap: 0.75rem;
    margin-bottom: 1rem !important;
}

/* Action Buttons */
.mb-3.d-flex.gap-2 .btn {
    padding: 0.6rem 1rem;
    font-size: 0.85rem;
    font-weight: 500;
    border-radius: 10px;
    transition: all 0.2s ease;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    white-space: nowrap;
}

/* Print Button */
.btn-primary {
    background: linear-gradient(135deg, #047857, #059669);
    border: none;
    color: white;
}

.btn-primary:hover {
    background: linear-gradient(135deg, #065f46, #047857);
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(4, 120, 87, 0.25);
}

/* Export Button */
.btn-success {
    background: linear-gradient(135deg, #059669, #10b981);
    border: none;
    color: white;
}

.btn-success:hover {
    background: linear-gradient(135deg, #047857, #059669);
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(5, 150, 105, 0.25);
}

/* Refresh Button - No Background */
.btn-info {
    background: transparent !important;
    border: 2px solid #16be00;
    color: #16be00;
}

.btn-info:hover {
    background: linear-gradient(135deg, #74e90e, #16be00) !important;
    border: 2px solid #22ff04;
    color: white;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(14, 165, 233, 0.25);
}

/* ===== RESPONSIVE ===== */

/* Tablet (768px - 991px) */
@media (min-width: 768px) and (max-width: 991px) {
    .mb-3.d-flex.gap-2 {
        gap: 0.6rem;
    }
    
    .mb-3.d-flex.gap-2 .btn {
        padding: 0.5rem 0.85rem;
        font-size: 0.8rem;
    }
    
    .mb-3.d-flex.gap-2 .btn i {
        font-size: 0.8rem;
    }
}

/* Mobile (max-width: 767px) */
@media (max-width: 767px) {
    .mb-3.d-flex.gap-2 {
        gap: 0.5rem;
        flex-wrap: wrap;
    }
    
    .mb-3.d-flex.gap-2 .btn {
        flex: 1;
        min-width: calc(33.333% - 0.5rem);
        justify-content: center;
        padding: 0.45rem 0.5rem;
        font-size: 0.75rem;
        white-space: nowrap;
    }
    
    .mb-3.d-flex.gap-2 .btn i {
        font-size: 0.75rem;
    }
}

/* Small Mobile (max-width: 480px) */
@media (max-width: 480px) {
    .mb-3.d-flex.gap-2 {
        gap: 0.4rem;
    }
    
    .mb-3.d-flex.gap-2 .btn {
        padding: 0.4rem 0.4rem;
        font-size: 0.7rem;
    }
    
    .mb-3.d-flex.gap-2 .btn i {
        font-size: 0.7rem;
    }
    
    /* Hide text on very small screens, show only icons */
    .mb-3.d-flex.gap-2 .btn span {
        display: none;
    }
    
    .mb-3.d-flex.gap-2 .btn i {
        margin: 0;
        font-size: 0.9rem;
    }
}

/* Extra Small (max-width: 380px) */
@media (max-width: 380px) {
    .mb-3.d-flex.gap-2 {
        gap: 0.3rem;
    }
    
    .mb-3.d-flex.gap-2 .btn {
        padding: 0.35rem 0.35rem;
    }
    
    .mb-3.d-flex.gap-2 .btn i {
        font-size: 0.85rem;
    }
}

/* Landscape mode on mobile */
@media (max-width: 767px) and (orientation: landscape) {
    .mb-3.d-flex.gap-2 {
        flex-wrap: nowrap;
    }
    
    .mb-3.d-flex.gap-2 .btn {
        padding: 0.4rem 0.6rem;
        font-size: 0.7rem;
    }
    
    .mb-3.d-flex.gap-2 .btn span {
        display: inline;
    }
    
    .mb-3.d-flex.gap-2 .btn i {
        font-size: 0.7rem;
        margin-right: 0.3rem;
    }
}
/* ===== MOBILE CARD VIEW - DRIVER SA RIGHT SIDE ===== */

@media (max-width: 767px) {
    /* Hide table header */
    .table-responsive table thead {
        display: none;
    }
    
    /* Make table blocks */
    .table-responsive table,
    .table-responsive table tbody,
    .table-responsive table tr,
    .table-responsive table td {
        display: block;
        width: 100%;
    }
    
    /* Card styling - two columns */
    .table-responsive table tr {
        margin-bottom: 0.75rem;
        background: white;
        border-radius: 12px;
        box-shadow: 0 1px 4px rgba(0, 0, 0, 0.05);
        padding: 0.75rem;
        cursor: pointer;
        border: 1px solid #f0f0f0;
        display: grid;
        grid-template-columns: 1fr auto;
        gap: 0.2rem 0.75rem;
        align-items: baseline;
    }
    
    /* Hide all cells by default */
    .table-responsive table td {
        display: none !important;
    }
    
    /* ===== LEFT COLUMN ===== */
    
    /* Order # - row 1 */
    .table-responsive table td[data-label="Order #"] {
        display: block !important;
        grid-column: 1;
        grid-row: 1;
        font-size: 0.85rem;
        font-weight: 700;
        color: #1e293b;
        padding: 0;
        border: none;
    }
    
    .table-responsive table td[data-label="Order #"] .badge {
        background: rgba(15, 245, 11, 0.1) !important;
        padding: 0;
        font-size: 0.85rem;
        font-weight: 700;
        color: #065f46 !important;
    }
    
    /* Customer - row 2 */
    .table-responsive table td[data-label="Customer"] {
        display: block !important;
        grid-column: 1;
        grid-row: 2;
        font-size: 0.75rem;
        font-weight: 500;
        color: #334155;
        padding: 0;
        border: none;
    }
    
    /* Items - row 3 */
    .table-responsive table td[data-label="Items"] {
        display: block !important;
        grid-column: 1;
        grid-row: 3;
        font-size: 0.65rem;
        color: #94a3b8;
        padding: 0;
        border: none;
    }
    
    /* Date - row 4 */
    .table-responsive table td[data-label="Date"] {
        display: block !important;
        grid-column: 1;
        grid-row: 4;
        font-size: 0.65rem;
        color: #94a3b8;
        padding: 0;
        border: none;
        line-height: 1.3;
    }
    
    .table-responsive table td[data-label="Date"] br {
        display: none;
    }
    
    .table-responsive table td[data-label="Date"] .text-muted {
        display: block;
        font-size: 0.65rem;
        color: #94a3b8;
    }
    
    /* ===== RIGHT COLUMN ===== */
    
    /* Status - row 1 (katabi ng Order #) */
    .table-responsive table td[data-label="Status"] {
        display: block !important;
        grid-column: 2;
        grid-row: 1;
        text-align: right;
        padding: 0;
        border: none;
    }
    
    .table-responsive table td[data-label="Status"] .badge {
        background: transparent !important;
        padding: 0;
        font-size: 0.7rem;
        font-weight: 500;
    }
    
    .table-responsive table td[data-label="Status"] .badge.bg-warning {
        color: #f59e0b !important;
        background: rgba(245, 158, 11, 0.1) !important;
    }
    .table-responsive table td[data-label="Status"] .badge.bg-info {
        color: #0ea5e9 !important;
        background: rgba(14, 165, 233, 0.1) !important;
    }
    .table-responsive table td[data-label="Status"] .badge.bg-primary {
        color: #3b82f6 !important;
        background: rgba(59, 130, 246, 0.1) !important;
    }
    .table-responsive table td[data-label="Status"] .badge.bg-success {
        color: #10b981 !important;
        background: rgba(16, 185, 129, 0.1) !important;
    }
    .table-responsive table td[data-label="Status"] .badge.bg-danger {
        color: #ef4444 !important;
        background: rgba(239, 68, 68, 0.1) !important;
    }
    
<<<<<<< HEAD

    .table-responsive table td[data-label="Status"] .badge.status-badge {
        padding: 5px 9px !important;
        font-size: 0.7rem !important;
        font-weight: 700 !important;
        border-radius: 999px !important;
    }

    .table-responsive table td[data-label="Status"] .badge.status-confirmed {
        color: #ffffff !important;
        background: #0d6efd !important;
        border-color: #0d6efd !important;
    }

    .table-responsive table td[data-label="Status"] .badge.status-delivered {
        color: #ffffff !important;
        background: #198754 !important;
        border-color: #198754 !important;
    }

    .table-responsive table td[data-label="Status"] .badge.status-pending {
        color: #000000 !important;
        background: #ffc107 !important;
        border-color: #ffc107 !important;
    }

=======
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
    /* Driver - row 2 (katabi ng Customer) */
    .table-responsive table td[data-label="Driver"] {
        display: block !important;
        grid-column: 2;
        grid-row: 2;
        text-align: right;
        font-size: 0.7rem;
        color: #64748b;
        padding: 0;
        border: none;
    }
    
    .table-responsive table td[data-label="Driver"] .driver-badge,
    .table-responsive table td[data-label="Driver"] .no-driver-badge {
        background: transparent;
        padding: 0;
        font-size: 0.7rem;
        color: #64748b;
        border: none;
    }
    
    /* Price - row 3 (katabi ng Items) */
    .table-responsive table td[data-label="Total Amount"] {
        display: block !important;
        grid-column: 2;
        grid-row: 3;
        text-align: right;
        padding: 0;
        border: none;
    }
    
    .table-responsive table td[data-label="Total Amount"] strong {
        font-size: 0.85rem;
        font-weight: 700;
        color: #059669;
    }
    
    /* Tap to view - row 4 (katabi ng Date/Time) */
    .table-responsive table td[data-label="Actions"] {
        display: block !important;
        grid-column: 2;
        grid-row: 4;
        text-align: right;
        padding: 0;
        border: none;
    }
    
    .table-responsive table td[data-label="Actions"] .action-buttons {
        display: none;
    }
    
    .table-responsive table td[data-label="Actions"]:after {
        content: "tap to view";
        font-size: 0.55rem;
        color: #94a3b8;
    }
    
    /* Hide Branch */
    .table-responsive table td[data-label="Branch"] {
        display: none !important;
    }
}
<<<<<<< HEAD


/* ===== ORDER DETAILS MODAL TOTALS - SAME LAYOUT AS CUSTOMER PAGE ===== */
.order-totals-summary {
    margin-top: 14px;
    padding: 12px;
    background: #f8fafc;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    max-width: none;
    width: 100%;
    margin-left: 0;
}
.order-totals-summary .order-total-line {
    display: flex !important;
    justify-content: space-between !important;
    align-items: center !important;
    gap: 12px;
    padding: 8px 10px;
    border-bottom: 1px solid #e5e7eb;
    font-size: 0.85rem;
    background: transparent !important;
    border-radius: 0;
    margin-bottom: 0;
}
.order-totals-summary .order-total-line:last-child {
    border-bottom: none;
}
.order-total-label {
    font-weight: 700;
    color: #1f2937;
    text-transform: uppercase;
    letter-spacing: 0.3px;
}
.order-total-value {
    font-weight: 700;
    color: #111827;
    text-align: right;
    white-space: nowrap;
}
.order-total-line.discount .order-total-value {
    color: #dc2626;
}
.order-total-line.grand-total {
    margin-top: 4px;
    background: #ecfdf5 !important;
    border-radius: 10px;
    border-bottom: none;
}
.order-total-line.grand-total .order-total-label,
.order-total-line.grand-total .order-total-value {
    color: #047857;
    font-weight: 800;
}
@media (max-width: 767px) {
    .order-totals-summary {
        margin-top: 12px;
        padding: 10px;
        border-radius: 12px;
        width: 100%;
    }
    .order-totals-summary .order-total-line {
        padding: 8px 6px;
        font-size: 0.8rem;
        gap: 8px;
    }
    .order-total-label {
        font-size: 0.76rem;
    }
    .order-total-value {
        font-size: 0.82rem;
    }
}


/* ===== ORDER DETAILS MODAL MOBILE TABLE FIX =====
   Keep order items as a real table on mobile. This prevents the global
   mobile card layout from adding Product/Unit/Quantity labels inside td. */
@media (max-width: 767px) {
    #orderDetailsModal .items-section .table-responsive {
        width: 100%;
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }

    #orderDetailsModal .items-section .table-responsive table,
    #orderDetailsModal .items-section .table-responsive table tbody,
    #orderDetailsModal .items-section .table-responsive table tr,
    #orderDetailsModal .items-section .table-responsive table td,
    #orderDetailsModal .items-section .table-responsive table th {
        display: revert !important;
        width: auto !important;
    }

    #orderDetailsModal .items-section .table-responsive table thead {
        display: table-header-group !important;
    }

    #orderDetailsModal .items-section .table-responsive table {
        display: table !important;
        min-width: 560px;
        border-collapse: collapse;
    }

    #orderDetailsModal .items-section .table-responsive table tbody {
        display: table-row-group !important;
    }

    #orderDetailsModal .items-section .table-responsive table tr {
        display: table-row !important;
        margin: 0 !important;
        padding: 0 !important;
        background: transparent !important;
        border-radius: 0 !important;
        box-shadow: none !important;
        border: none !important;
        cursor: default !important;
    }

    #orderDetailsModal .items-section .table-responsive table th,
    #orderDetailsModal .items-section .table-responsive table td {
        display: table-cell !important;
        padding: 0.65rem 0.75rem !important;
        border-bottom: 1px solid #e5e7eb !important;
        vertical-align: top !important;
        text-align: left !important;
        white-space: nowrap;
    }

    #orderDetailsModal .items-section .table-responsive table th:first-child,
    #orderDetailsModal .items-section .table-responsive table td:first-child {
        white-space: normal;
        min-width: 170px;
    }

    #orderDetailsModal .items-section .table-responsive table td::before,
    #orderDetailsModal .items-section .table-responsive table td::after {
        content: none !important;
        display: none !important;
    }
}
=======
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
    </style>
</head>
<body>
    <!-- AGAD NA LOADING - BAGO MAG-RENDER ANG PAGE -->
<div id="preLoader" style="
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.4);
    backdrop-filter: blur(8px);
    display: flex;
    justify-content: center;
    align-items: center;
    z-index: 999999;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
">
    <div style="
        background: white;
        border-radius: 28px;
        width: 280px;
        padding: 32px 24px;
        text-align: center;
        box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
    ">
        <div style="width: 70px; height: 70px; margin: 0 auto 20px auto; position: relative;">
            <svg width="70" height="70" viewBox="0 0 70 70" style="transform: rotate(-90deg);">
                <circle cx="35" cy="35" r="30" fill="none" stroke="#e5e7eb" stroke-width="4"/>
                <circle cx="35" cy="35" r="30" fill="none" stroke="#059669" stroke-width="4" 
                        stroke-dasharray="188.5" stroke-dashoffset="188.5"
                        style="animation: preloaderCircle 1s linear infinite"/>
            </svg>
        </div>
        <div style="font-size: 18px; font-weight: 600; color: #1f2937; margin-bottom: 8px;">
            Opening Order
        </div>
        <div style="font-size: 13px; color: #6c757d; margin-bottom: 16px;">
            Loading order details...
        </div>
        <div style="display: flex; justify-content: center; gap: 6px;">
            <div style="width: 6px; height: 6px; background: #059669; border-radius: 50%; animation: preloaderDot 1.4s ease-in-out infinite;"></div>
            <div style="width: 6px; height: 6px; background: #059669; border-radius: 50%; animation: preloaderDot 1.4s ease-in-out 0.2s infinite;"></div>
            <div style="width: 6px; height: 6px; background: #059669; border-radius: 50%; animation: preloaderDot 1.4s ease-in-out 0.4s infinite;"></div>
        </div>
    </div>
</div>

<style>
    @keyframes preloaderCircle {
        0% { stroke-dashoffset: 188.5; }
        50% { stroke-dashoffset: 47.125; }
        100% { stroke-dashoffset: 188.5; }
    }
    @keyframes preloaderDot {
        0%, 60%, 100% { transform: scale(0.8); opacity: 0.5; }
        30% { transform: scale(1.2); opacity: 1; }
    }
</style>

<script>
(function() {
    const urlParams = new URLSearchParams(window.location.search);
    const orderId = urlParams.get('view_order');
    
    if (orderId && !isNaN(parseInt(orderId))) {
        const pendingOrderId = parseInt(orderId);
        window.history.replaceState({}, document.title, window.location.pathname);
        
        function hidePreloader() {
            const preloader = document.getElementById('preLoader');
            if (preloader) {
                preloader.style.transition = 'opacity 0.2s ease';
                preloader.style.opacity = '0';
                setTimeout(() => preloader.remove(), 200);
            }
        }
        
        function openOrder() {
            if (typeof viewOrderDetails === 'function') {
                hidePreloader();
                setTimeout(() => viewOrderDetails(pendingOrderId), 100);
                return true;
            }
            return false;
        }
        
        let attempts = 0;
        function tryOpen() {
            attempts++;
            if (openOrder()) return;
            if (attempts < 30) setTimeout(tryOpen, 100);
            else hidePreloader();
        }
        setTimeout(tryOpen, 200);
        setTimeout(hidePreloader, 5000);
    } else {
        // No order to open, just hide preloader
        const preloader = document.getElementById('preLoader');
        if (preloader) preloader.remove();
    }
})();
</script>
    <!-- MAIN APPLICATION -->
    <div id="appPage">
        <!-- Sidebar - No Print -->
        <div class="sidebar no-print" id="sidebar">
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
                        <a class="nav-link" href="returnedmerchandise.php">
                            <i class="bi bi-arrow-counterclockwise"></i>
                            <span class="nav-text">Returned Merchandise</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="sales_order.php">
                            <i class="bi bi-list-check"></i>
                            <span class="nav-text">Sales Orders</span>
                        </a>
                    </li>
<<<<<<< HEAD
                     <li class="nav-item">
                         <a class="nav-link" href="sales_collections.php">
                             <i class="bi bi-cash-stack"></i>
                             <span class="nav-text">Collections</span>
                        </a>
                    </li>
=======
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
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
            <!-- Header Section with User Info and Logout - No Print -->
            <div class="navbar-top no-print">
                <button class="mobile-toggle-btn" id="mobileToggleBtn">
                    <i class="bi bi-list"></i>
                </button>
                <div class="page-title">
                    <h2>Sales Orders</h2>
                    <p>View and manage customer orders</p>
                </div>
            </div>

            <!-- Branch Info Alert (if no branch_id column) -->
            <?php if (!$branch_column_exists): ?>
                <div class="alert alert-info alert-dismissible fade show" role="alert">
                    <i class="bi bi-info-circle"></i> 
                    <strong>Branch filtering for sales orders not yet set up.</strong> Please run this SQL in phpMyAdmin to enable branch-specific order data:
                    <br><br>
                    <code>ALTER TABLE sales_orders ADD COLUMN branch_id INT NULL;</code>
                    <br>
                    <code>ALTER TABLE sales_orders ADD FOREIGN KEY (branch_id) REFERENCES branches(branch_id);</code>
                    <br><br>
                    <button type="button" class="btn btn-sm btn-primary" onclick="copySQL()">
                        <i class="bi bi-files"></i> Copy SQL
                    </button>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <script>
                    function copySQL() {
                        const sql = "ALTER TABLE sales_orders ADD COLUMN branch_id INT NULL;\nALTER TABLE sales_orders ADD FOREIGN KEY (branch_id) REFERENCES branches(branch_id);";
                        navigator.clipboard.writeText(sql).then(() => {
                            alert('SQL copied to clipboard!');
                        });
                    }
                </script>
            <?php endif; ?>

            <?php if (!$customers_branch_column_exists): ?>
                <div class="alert alert-info alert-dismissible fade show" role="alert">
                    <i class="bi bi-info-circle"></i> 
                    <strong>Branch filtering for customers not yet set up.</strong> Please run this SQL in phpMyAdmin to enable branch-specific customer data:
                    <br><br>
                    <code>ALTER TABLE customers ADD COLUMN branch_id INT NULL;</code>
                    <br>
                    <code>ALTER TABLE customers ADD FOREIGN KEY (branch_id) REFERENCES branches(branch_id);</code>
                    <br><br>
                    <button type="button" class="btn btn-sm btn-primary" onclick="copyCustomersSQL()">
                        <i class="bi bi-files"></i> Copy SQL
                    </button>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <script>
                    function copyCustomersSQL() {
                        const sql = "ALTER TABLE customers ADD COLUMN branch_id INT NULL;\nALTER TABLE customers ADD FOREIGN KEY (branch_id) REFERENCES branches(branch_id);";
                        navigator.clipboard.writeText(sql).then(() => {
                            alert('SQL copied to clipboard!');
                        });
                    }
                </script>
            <?php endif; ?>

           <!-- Statistics Cards - gaya ng customer.php - No Print -->
<div class="row stat-card-row g-1 g-sm-2 mb-4 no-print">
    <!-- Stat 1: Total Orders -->
    <div class="col">
        <div class="stat-card total">
            <i class="bi bi-receipt stat-icon"></i>
            <div class="stat-content">
                <div class="stat-value"><?php echo $stats['total_orders'] ?? 0; ?></div>
                <div class="stat-label">Total Orders</div>
                <?php if ($branch_column_exists && !$view_all_branches): ?>
                    <small class="d-block"><?php echo htmlspecialchars($branch_name ?? 'Your Branch'); ?></small>
                <?php else: ?>
                    <small class="d-block">All branches</small>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Stat 2: Pending -->
    <div class="col">
        <div class="stat-card pending">
            <i class="bi bi-clock stat-icon"></i>
            <div class="stat-content">
                <div class="stat-value"><?php echo $stats['pending'] ?? 0; ?></div>
                <div class="stat-label">Pending</div>
            </div>
        </div>
    </div>

    <!-- Stat 3: Processing -->
    <div class="col">
        <div class="stat-card sales">
            <i class="bi bi-gear stat-icon"></i>
            <div class="stat-content">
                <div class="stat-value"><?php echo $stats['processing'] ?? 0; ?></div>
                <div class="stat-label">Processing</div>
            </div>
        </div>
    </div>

    <!-- Stat 4: Total Revenue -->
    <div class="col">
        <div class="stat-card complete">
            <i class="bi bi-cash-stack stat-icon"></i>
            <div class="stat-content">
                <div class="stat-value">₱<?php echo number_format($stats['total_revenue'] ?? 0, 2); ?></div>
                <div class="stat-label">Total Revenue</div>
            </div>
        </div>
    </div>
</div>
           <!-- Search and Filter - No Print (Styled like picklist) -->
<div class="form-card mb-4 no-print">
    <div class="filter-header">
        <h5>
            <i class="bi bi-funnel"></i> Filter Sales Orders
        </h5>
        <button class="filter-toggle-btn" type="button" id="filterToggleBtn" aria-expanded="false">
            <i class="bi bi-chevron-down" id="filterIcon"></i>
        </button>
    </div>
    
    <!-- Add 'collapsed' class here directly para closed agad kahit naglo-load pa -->
    <div class="filter-content collapsed" id="filterContent">
        <!-- Row 1: Date Inputs (magkatabi) -->
        <div class="row g-3 mb-3">
            <div class="col-6">
                <label for="startDate" class="form-label">From:</label>
                <input type="date" class="form-control" id="startDate" 
                       value="<?php echo isset($_GET['start_date']) ? $_GET['start_date'] : ''; ?>">
            </div>
            <div class="col-6">
                <label for="endDate" class="form-label">To:</label>
                <input type="date" class="form-control" id="endDate" 
                       value="<?php echo isset($_GET['end_date']) ? $_GET['end_date'] : ''; ?>">
            </div>
        </div>
        
        <!-- Row 2: Action Buttons (magkakatabi) -->
        <div class="row g-2 mb-4">
            <div class="col-4">
                <button class="date-filter-btn w-100" onclick="applyDateRangeFilter()">
                    <i class="bi bi-funnel"></i> <span>Apply</span>
                </button>
            </div>
            <div class="col-4">
                <button class="date-reset-btn w-100" onclick="resetDateFilter()">
                    <i class="bi bi-calendar-week"></i> <span>Reset</span>
                </button>
            </div>
            <div class="col-4">
                <button class="date-clear-btn w-100" onclick="clearAllFilters()">
                    <i class="bi bi-x-circle"></i> <span>Clear</span>
                </button>
            </div>
        </div>
        
        <!-- Row 3: Status, Customer, Search Filters -->
        <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label">
                    <i class="bi bi-flag"></i> Status Filter
                </label>
                <select class="form-select" id="statusFilter">
                    <option value="">All Status</option>
                    <option value="pending" <?php echo (isset($_GET['status']) && $_GET['status'] === 'pending') ? 'selected' : ''; ?>>Pending</option>
                    <option value="processing" <?php echo (isset($_GET['status']) && $_GET['status'] === 'processing') ? 'selected' : ''; ?>>Processing</option>
                    <option value="shipped" <?php echo (isset($_GET['status']) && $_GET['status'] === 'shipped') ? 'selected' : ''; ?>>Shipped</option>
                    <option value="delivered" <?php echo (isset($_GET['status']) && $_GET['status'] === 'delivered') ? 'selected' : ''; ?>>Delivered</option>
                    <option value="cancelled" <?php echo (isset($_GET['status']) && $_GET['status'] === 'cancelled') ? 'selected' : ''; ?>>Cancelled</option>
                </select>
            </div>
            
            <div class="col-md-4">
                <label class="form-label">
                    <i class="bi bi-people"></i> Customer Filter
                </label>
                <select class="form-select" id="customerFilter">
                    <option value="">All Customers</option>
                    <?php foreach ($customers as $customer): ?>
                        <option value="<?php echo htmlspecialchars($customer['customer_name']); ?>">
                            <?php echo htmlspecialchars($customer['customer_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="col-md-4">
                <label class="form-label">
                    <i class="bi bi-search"></i> Search
                </label>
                <input type="text" class="form-control" id="searchInput" 
                       placeholder="Order #, Customer, or Item..." 
                       value="<?php echo isset($_GET['search']) ? htmlspecialchars($_GET['search']) : ''; ?>">
            </div>
        </div>
    </div>
</div>
            <!-- Action Buttons - No Print -->
            <div class="mb-3 d-flex gap-2 no-print">
                <button class="btn btn-primary" onclick="printAllOrders()">
                    <i class="bi bi-printer"></i> Print All Orders
                </button>
                <button class="btn btn-success" onclick="exportToExcel()">
                    <i class="bi bi-file-earmark-excel"></i> Export to Excel
                </button>
                <button class="btn btn-info" onclick="refreshOrders()">
                    <i class="bi bi-arrow-clockwise"></i> Refresh
                </button>
            </div>

            <!-- Orders Table - DESIGN GAYA NG CUSTOMER.PHP -->
            <div class="card">
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0" id="ordersTable">
                            <thead class="table-light">
                                <tr>
                                    <th>Order #</th>
                                    <th>Date</th>
                                    <th>Customer</th>
                                    <?php if ($branch_column_exists && $view_all_branches): ?>
                                        <th>Branch</th>
                                    <?php endif; ?>
                                    <th>Items</th>
                                    <th>Assigned Driver</th>
                                    <th>Total Amount</th>
                                    <th>Status</th>
                                    <th class="no-print">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
    <?php if (empty($orders)): ?>
        <tr>
            <td colspan="<?php echo ($branch_column_exists && $view_all_branches) ? '10' : '9'; ?>" class="text-center text-muted py-4">
                <i class="bi bi-inbox" style="font-size: 2rem;"></i><br>
                No orders found
                <?php if ($branch_column_exists && !$view_all_branches): ?>
                    <br><small>No orders for your branch yet</small>
                <?php endif; ?>
            </td>
        </tr>
    <?php else: ?>
        <?php foreach ($orders as $order): ?>
<<<<<<< HEAD
            <tr class="sales-order-row"
                onclick="handleOrderRowClick(event, <?php echo (int)$order['so_id']; ?>)"
=======
            <tr class="sales-order-row" 
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
                data-id="<?php echo $order['so_id']; ?>"
                data-order-number="<?php echo htmlspecialchars($order['so_number']); ?>"
                data-customer="<?php echo htmlspecialchars($order['customer_name'] ?? ''); ?>"
                data-status="<?php echo $order['order_status']; ?>"
                data-date="<?php echo $order['order_date']; ?>"
                data-amount="<?php echo $order['total_amount']; ?>"
                data-driver="<?php echo htmlspecialchars($order['assigned_driver'] ?? 'No Driver'); ?>">
                
                <td data-label="Order #">
                    <span class="badge bg-light text-dark"><?php echo htmlspecialchars($order['so_number']); ?></span>
                </td>
                
                <td data-label="Date">
                    <?php echo date('M d, Y', strtotime($order['order_date'])); ?><br>
                    <small class="text-muted"><?php echo date('h:i A', strtotime($order['order_date'])); ?></small>
                </td>
                
                <td data-label="Customer">
                    <?php echo htmlspecialchars($order['customer_name'] ?? 'Walk-in Customer'); ?>
                </td>
                
                <?php if ($branch_column_exists && $view_all_branches): ?>
                    <td data-label="Branch">
                        <span class="badge bg-info">
                            <?php echo htmlspecialchars($order['branch_name'] ?? 'Branch ' . $order['branch_id']); ?>
                        </span>
                    </td>
                <?php endif; ?>
                
                <td data-label="Items">
                    <span class="item-names-tooltip" title="<?php echo htmlspecialchars($order['item_names'] ?? ''); ?>">
                        <?php echo $order['item_count'] ?? 0; ?> item(s)
                    </span>
                    <span class="full-item-names"><?php echo htmlspecialchars($order['item_names_with_qty'] ?? ''); ?></span>
                </td>
                
                <td data-label="Driver">
                    <?php if (!empty($order['assigned_driver']) && $order['assigned_driver'] !== 'No Driver'): ?>
                        <span class="driver-badge">
                            <i class="bi bi-truck"></i> <?php echo htmlspecialchars($order['assigned_driver']); ?>
                        </span>
                    <?php else: ?>
                        <span class="no-driver-badge">
                            <i class="bi bi-person-x"></i> No Driver
                        </span>
                    <?php endif; ?>
                </td>
                
                <td data-label="Total Amount">
                    <strong class="text-success">₱<?php echo number_format($order['total_amount'], 2); ?></strong>
                </td>
                
                <td data-label="Status">
                    <span class="<?php echo getOrderStatusBadge($order['order_status']); ?>">
                        <?php echo getOrderStatusText($order['order_status']); ?>
                    </span>
                </td>
                
                <td data-label="Actions" class="no-print">
                    <div class="action-buttons">
<<<<<<< HEAD
                        <?php if (in_array(strtolower((string)$order['order_status']), ['pending', 'confirmed'], true)): ?>
                            <button class="btn-action btn-print" onclick="event.stopPropagation(); printSingleOrder(<?php echo $order['so_id']; ?>)" title="Print Order">
                                <i class="bi bi-printer"></i>
                            </button>
                        <?php endif; ?>
=======
                        <button class="btn-action btn-view" onclick="viewOrderDetails(<?php echo $order['so_id']; ?>)" title="View Details">
                            <i class="bi bi-eye"></i>
                        </button>
                        <button class="btn-action btn-print" onclick="printSingleOrder(<?php echo $order['so_id']; ?>)" title="Print Order">
                            <i class="bi bi-printer"></i>
                        </button>
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
    <?php endif; ?>
</tbody>
                        </table>
                    </div>
                </div>
            </div>
            
            <!-- PRINT VERSION - HIDDEN SA SCREEN -->
            <div id="printContainer" style="display: none;"></div>
        </div>
    </div>

    <!-- Order Details Modal - No Print -->
    <div class="modal fade no-print" id="orderDetailsModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Order Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="orderDetailsContent">
                    <!-- Content loaded via AJAX -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-danger" id="cancelOrderBtn" style="display: none;" onclick="cancelOrder()">Cancel Order</button>
                    <button type="button" class="btn btn-primary" id="printOrderFromDetails" style="display: none;" onclick="printFromDetails()">Print Order</button>
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
                <a class="nav-link" href="returnedmerchandise.php">
                    <i class="bi bi-arrow-counterclockwise"></i>
                    <span>Returns</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link active" href="sales_order.php">
                    <i class="bi bi-list-check"></i>
                    <span>Sales Orders</span>
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
                        <span><?php echo htmlspecialchars($branch_name); ?></span>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Logout Button -->
                    <button class="btn btn-danger btn-lg w-100" onclick="confirmLogout()">
                        <i class="bi bi-box-arrow-right me-2"></i>Logout
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Loading Overlay -->
    <div id="loadingOverlay" class="loading-overlay" style="display: none;">
        <div class="loading-spinner"></div>
        <div class="mt-3 text-success">Processing...</div>
    </div>

    <!-- Print Frame (hidden) -->
    <iframe id="printFrame" name="printFrame"></iframe>

    <!-- JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <!-- jQuery (required for AJAX) -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
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

        // ========== AUTO FORMAT CURRENCY WITH COMMAS ==========
        function formatNumberWithCommas(number) {
            if (number === null || number === undefined || number === '') return '0';
            let num = typeof number === 'string' ? parseFloat(number) : number;
            if (isNaN(num)) return '0';
            return num.toLocaleString('en-US', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        }

        function formatCurrency(number) {
            if (number === null || number === undefined || number === '') return '₱0.00';
            let num = typeof number === 'string' ? parseFloat(number) : number;
            if (isNaN(num)) return '₱0.00';
            return '₱' + num.toLocaleString('en-US', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        }

        function formatNumberWhole(number) {
            if (number === null || number === undefined || number === '') return '0';
            let num = typeof number === 'string' ? parseInt(number) : number;
            if (isNaN(num)) return '0';
            return num.toLocaleString('en-US');
        }

        // Format all currency displays on page
        function formatAllCurrencies() {
            document.querySelectorAll('#ordersTable tbody td[data-label="Total Amount"] strong, .stat-value').forEach(el => {
                let text = el.innerText;
                let cleanText = text.replace(/[₱,]/g, '');
                let num = parseFloat(cleanText);
                if (!isNaN(num) && cleanText !== '') {
                    if (text.includes('₱')) {
                        el.innerText = formatCurrency(num);
                    } else {
                        el.innerText = formatNumberWithCommas(num);
                    }
                }
            });
        }

        // Branch context variables
        const branchId = <?php echo $branch_id; ?>;
        const viewAllBranches = <?php echo $view_all_branches ? 'true' : 'false'; ?>;
        const branchColumnExists = <?php echo $branch_column_exists ? 'true' : 'false'; ?>;
        const logoBase64 = '<?php echo $logo_base64; ?>';

        // Global variables
        let currentOrderId = null;
        let activeDateRange = { start: '', end: '' };

        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            console.log("Sales Orders page loaded!");
            console.log("Branch ID:", branchId);
            console.log("View All Branches:", viewAllBranches);
            console.log("Branch Column Exists:", branchColumnExists);
            
            // Initialize sidebar
            initializeSidebar();
            
            // Format all currencies
            setTimeout(formatAllCurrencies, 500);
            
            // Setup mobile toggle buttons
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

            // Setup event listeners for filters
            setupEventListeners();
        });

        // Setup event listeners
        function setupEventListeners() {
            // Search functionality
            const searchInput = document.getElementById('searchInput');
            if (searchInput) {
                searchInput.addEventListener('keyup', debounce(function() {
                    applyFilters();
                }, 300));
            }

            // Status filter
            const statusFilter = document.getElementById('statusFilter');
            if (statusFilter) {
                statusFilter.addEventListener('change', function() {
                    applyFilters();
                });
            }

            // Customer filter
            const customerFilter = document.getElementById('customerFilter');
            if (customerFilter) {
                customerFilter.addEventListener('change', function() {
                    applyFilters();
                });
            }
        }

        // Debounce function
        function debounce(func, wait) {
            let timeout;
            return function executedFunction(...args) {
                const later = () => {
                    clearTimeout(timeout);
                    func(...args);
                };
                clearTimeout(timeout);
                timeout = setTimeout(later, wait);
            };
        }

        // Apply filters to table
        function applyFilters() {
            const status = document.getElementById('statusFilter')?.value || '';
            const customer = document.getElementById('customerFilter')?.value || '';
            const search = document.getElementById('searchInput')?.value.toLowerCase();
            const startDate = activeDateRange.start;
            const endDate = activeDateRange.end;
            
            // Create date objects for comparison
            const startDateTime = startDate ? new Date(startDate) : null;
            const endDateTime = endDate ? new Date(endDate) : null;
            if (endDateTime) {
                endDateTime.setHours(23, 59, 59, 999);
            }
            
            let visibleCount = 0;
            let totalAmount = 0;
            
            document.querySelectorAll('.sales-order-row').forEach(row => {
                const orderNumber = row.dataset.orderNumber?.toLowerCase() || '';
                const rowCustomer = row.dataset.customer?.toLowerCase() || '';
                const rowStatus = row.dataset.status || '';
                const rowDateStr = row.dataset.date || '';
                const amount = parseFloat(row.dataset.amount) || 0;
                
                // Parse order date
                let orderDate = null;
                let isInDateRange = true;
                
                if (rowDateStr) {
                    orderDate = new Date(rowDateStr);
                    
                    // Apply date range filter only if active
                    if (startDateTime && endDateTime) {
                        isInDateRange = orderDate >= startDateTime && orderDate <= endDateTime;
                    }
                }
                
                const matchesSearch = search === '' || orderNumber.includes(search) || rowCustomer.includes(search);
                const matchesStatus = status === '' || rowStatus === status;
                const matchesCustomer = customer === '' || row.dataset.customer === customer;
                
                const showRow = matchesSearch && matchesStatus && matchesCustomer && isInDateRange;
                row.style.display = showRow ? '' : 'none';
                
                if (showRow) {
                    visibleCount++;
                    totalAmount += amount;
                }
            });
            
<<<<<<< HEAD
            // Update date range summary with formatted currency
=======
            // Update date range summary
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
            updateDateRangeSummary(startDate, endDate, visibleCount, totalAmount);
        }

        // Apply date range filter
        function applyDateRangeFilter() {
            const startDate = document.getElementById('startDate').value;
            const endDate = document.getElementById('endDate').value;
            
            if (!startDate || !endDate) {
                Swal.fire('Warning', 'Please select both start and end dates', 'warning');
                return;
            }
            
            if (new Date(startDate) > new Date(endDate)) {
                Swal.fire('Warning', 'Start date cannot be after end date', 'warning');
                return;
            }
            
            activeDateRange = { start: startDate, end: endDate };
            applyFilters();
        }

        // Reset date filter
        function resetDateFilter() {
            document.getElementById('startDate').value = '';
            document.getElementById('endDate').value = '';
            activeDateRange = { start: '', end: '' };
            applyFilters();
        }

        // Clear all filters
        function clearAllFilters() {
            document.getElementById('startDate').value = '';
            document.getElementById('endDate').value = '';
            document.getElementById('statusFilter').value = '';
            document.getElementById('customerFilter').value = '';
            document.getElementById('searchInput').value = '';
            
            activeDateRange = { start: '', end: '' };
            applyFilters();
        }

        // Update date range summary
        function updateDateRangeSummary(startDate, endDate, visibleCount, totalAmount) {
            const summaryDiv = document.getElementById('dateRangeSummary');
            const summaryText = document.getElementById('dateRangeSummaryText');
            
            if (!startDate || !endDate) {
<<<<<<< HEAD
                if (summaryDiv) summaryDiv.style.display = 'none';
=======
                summaryDiv.style.display = 'none';
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
                return;
            }
            
            const start = new Date(startDate);
            const end = new Date(endDate);
            const options = { year: 'numeric', month: 'long', day: 'numeric' };
            const startStr = start.toLocaleDateString('en-US', options);
            const endStr = end.toLocaleDateString('en-US', options);
            
<<<<<<< HEAD
            if (summaryText) {
                summaryText.innerHTML = `<strong>${startStr} - ${endStr}:</strong> ${visibleCount} orders, Total: ${formatCurrency(totalAmount)}`;
            }
            if (summaryDiv) summaryDiv.style.display = 'block';
=======
            summaryText.innerHTML = `<strong>${startStr} - ${endStr}:</strong> ${visibleCount} orders, Total: ₱${totalAmount.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
            summaryDiv.style.display = 'block';
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
        }

        // Refresh orders
        function refreshOrders() {
            location.reload();
        }

        // Show loading
        function showLoading() {
            document.getElementById('loadingOverlay').style.display = 'flex';
        }

        // Hide loading
        function hideLoading() {
            document.getElementById('loadingOverlay').style.display = 'none';
        }

<<<<<<< HEAD
        // View order details with improved layout and formatted currency
        function viewOrderDetails(orderId) {
            currentOrderId = orderId;
            const modal = new bootstrap.Modal(document.getElementById('orderDetailsModal'));
            
            // Show loading
            const orderDetailsContent = document.getElementById('orderDetailsContent');
            if (orderDetailsContent) {
                orderDetailsContent.innerHTML = `
                    <div class="loading-state">
                        <div class="spinner-border text-success" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                        <p class="mt-3 text-muted">Loading order details...</p>
                    </div>
                `;
            }
            
            modal.show();
            
            // Fetch order details via AJAX
            fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: 'action=get_order_details&order_id=' + orderId
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok');
                }
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    const order = data.order;
                    const items = data.items;
                    
                    // Build items table HTML with formatted currency
                    let itemsHtml = '';
                    let subTotal = 0;
                    let grandTotal = parseFloat(order.total_amount) || 0;
                    let savedDiscountAmount = parseFloat(order.total_discount_amount || order.discount_amount || 0) || 0;
                    
                    if (items && items.length > 0) {
                        items.forEach(item => {
                            const qty = parseFloat(item.quantity_ordered) || 0;
                            const grossPrice = parseFloat(item.gross_price) > 0 ? parseFloat(item.gross_price) : (parseFloat(item.unit_price) || 0);
                            const netPrice = parseFloat(item.net_price) > 0 ? parseFloat(item.net_price) : (parseFloat(item.unit_price) || 0);
                            const lineSubtotal = qty * grossPrice;
                            const lineTotal = parseFloat(item.order_amount) > 0 ? parseFloat(item.order_amount) : (parseFloat(item.line_total) || (qty * netPrice));
                            subTotal += lineSubtotal;
                            itemsHtml += `
                                <tr>
                                    <td>
                                        <strong>${escapeHtml(item.item_name)}</strong><br>
                                        <small class="text-muted">${escapeHtml(item.item_code)}</small>
                                    </td>
                                    <td class="text-center">${escapeHtml(item.unit_type || 'N/A')}</td>
                                    <td class="text-center">${parseInt(item.quantity_ordered)}</td>
                                    <td class="text-end">${formatCurrency(grossPrice)}</td>
                                    <td class="text-end">${formatCurrency(lineSubtotal)}</td>
                                </tr>
                            `;
                        });
                    } else {
                        itemsHtml = `
                            <tr>
                                <td colspan="5" class="text-center text-muted py-4">
                                    <i class="bi bi-box"></i> No items found for this order
                                 </td>
                            </tr>
                        `;
                    }
                    
                    // Use database subtotal when available; fallback to item subtotal.
                    const dbSubtotal = parseFloat(order.order_subtotal || 0) || 0;
                    if (dbSubtotal > 0) {
                        subTotal = dbSubtotal;
                    }

                    if (grandTotal <= 0) {
                        grandTotal = Math.max(0, subTotal - savedDiscountAmount);
                    }

                    let discountAmount = savedDiscountAmount;
                    if (discountAmount <= 0 && subTotal > grandTotal) {
                        discountAmount = subTotal - grandTotal;
                    }
                    discountAmount = Math.max(0, discountAmount);
                    grandTotal = Math.max(0, subTotal - discountAmount);
                    
                    // Build complete modal content with formatted currency
                    if (orderDetailsContent) {
                        orderDetailsContent.innerHTML = `
                            <div class="order-details-card">
                                <!-- Order Header Section -->
                                <div class="order-header-section">
                                    <div class="order-badge">
                                        <i class="bi bi-receipt"></i>
                                        <span>Order Information</span>
                                    </div>
                                    <div class="order-number">${escapeHtml(order.so_number)}</div>
                                </div>
                                
                                <!-- Order Info Grid (2 columns) -->
                                <div class="order-info-grid">
                                    <div class="order-info-item">
                                        <div class="order-info-label">
                                            <i class="bi bi-calendar3"></i> Order Date
                                        </div>
                                        <div class="order-info-value">${new Date(order.order_date).toLocaleString()}</div>
                                    </div>
                                    <div class="order-info-item">
                                        <div class="order-info-label">
                                            <i class="bi bi-flag"></i> Status
                                        </div>
                                        <div class="order-info-value">
                                            <span class="badge ${getOrderStatusBadgeClass(order.order_status)}">${getOrderStatusText(order.order_status)}</span>
                                        </div>
                                    </div>
                                    <div class="order-info-item">
                                        <div class="order-info-label">
                                            <i class="bi bi-building"></i> Branch
                                        </div>
                                        <div class="order-info-value">${escapeHtml(order.branch_name || 'N/A')}</div>
                                    </div>
                                    <div class="order-info-item">
                                        <div class="order-info-label">
                                            <i class="bi bi-person"></i> Created By
                                        </div>
                                        <div class="order-info-value">${escapeHtml(order.created_by || 'System')}</div>
                                    </div>
                                    <div class="order-info-item">
                                        <div class="order-info-label">
                                            <i class="bi bi-truck"></i> Assigned Driver
                                        </div>
                                        <div class="order-info-value">
                                            ${order.assigned_driver && order.assigned_driver !== 'No Driver' ? 
                                                `<span class="driver-badge-modal"><i class="bi bi-person-badge"></i> ${escapeHtml(order.assigned_driver)}</span>` : 
                                                `<span class="text-muted"><i class="bi bi-person-x"></i> No Driver Assigned</span>`}
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Customer Information Section -->
                                <div class="customer-section">
                                    <h6>
                                        <i class="bi bi-person-badge"></i> Customer Information
                                    </h6>
                                    <div class="customer-info-card">
                                        <div class="customer-detail-row">
                                            <span class="customer-detail-label">Customer Name:</span>
                                            <span class="customer-detail-value">${escapeHtml(order.customer_name || 'N/A')}</span>
                                        </div>
                                        <div class="customer-detail-row">
                                            <span class="customer-detail-label">Store Name:</span>
                                            <span class="customer-detail-value">${escapeHtml(order.store_name || 'N/A')}</span>
                                        </div>
                                        <div class="customer-detail-row">
                                            <span class="customer-detail-label">Customer Code:</span>
                                            <span class="customer-detail-value">${escapeHtml(order.customer_code || 'N/A')}</span>
                                        </div>
                                        <div class="customer-detail-row">
                                            <span class="customer-detail-label">Email:</span>
                                            <span class="customer-detail-value">${escapeHtml(order.email || 'N/A')}</span>
                                        </div>
                                        <div class="customer-detail-row">
                                            <span class="customer-detail-label">Phone:</span>
                                            <span class="customer-detail-value">${escapeHtml(order.phone_number || 'N/A')}</span>
                                        </div>
                                        <div class="customer-detail-row">
                                            <span class="customer-detail-label">Address:</span>
                                            <span class="customer-detail-value">${escapeHtml(order.address || 'N/A')}</span>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Order Items Section -->
                                <div class="items-section">
                                    <h6>
                                        <i class="bi bi-box-seam"></i> Order Items
                                    </h6>
                                    <div class="table-responsive">
                                        <table class="items-table">
                                            <thead>
                                                <tr>
                                                    <th>Product</th>
                                                    <th class="text-center">Unit</th>
                                                    <th class="text-center">Quantity</th>
                                                    <th class="text-end">Unit Price</th>
                                                    <th class="text-end">Total</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                ${itemsHtml}

                                            </tbody>
                                        </table>
                                    </div>
                                    <div class="order-totals-summary">
                                        <div class="order-total-line subtotal">
                                            <span class="order-total-label">Subtotal</span>
                                            <span class="order-total-value">${formatCurrency(subTotal)}</span>
                                        </div>
                                        <div class="order-total-line discount">
                                            <span class="order-total-label">Discount</span>
                                            <span class="order-total-value">-${formatCurrency(discountAmount).replace('₱-', '₱')}</span>
                                        </div>
                                        <div class="order-total-line grand-total">
                                            <span class="order-total-label">Grand Total</span>
                                            <span class="order-total-value">${formatCurrency(grandTotal)}</span>
                                        </div>
                                    </div>
                                </div>
=======
        // View order details with improved layout
function viewOrderDetails(orderId) {
    currentOrderId = orderId;
    const modal = new bootstrap.Modal(document.getElementById('orderDetailsModal'));
    
    // Show loading
    const orderDetailsContent = document.getElementById('orderDetailsContent');
    if (orderDetailsContent) {
        orderDetailsContent.innerHTML = `
            <div class="loading-state">
                <div class="spinner-border text-success" role="status">
                    <span class="visually-hidden">Loading...</span>
                </div>
                <p class="mt-3 text-muted">Loading order details...</p>
            </div>
        `;
    }
    
    modal.show();
    
    // Fetch order details via AJAX
    fetch('', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: 'action=get_order_details&order_id=' + orderId
    })
    .then(response => {
        if (!response.ok) {
            throw new Error('Network response was not ok');
        }
        return response.json();
    })
    .then(data => {
        if (data.success) {
            const order = data.order;
            const items = data.items;
            
            // Build items table HTML
            let itemsHtml = '';
            let grandTotal = 0;
            
            if (items && items.length > 0) {
                items.forEach(item => {
                    const total = parseFloat(item.line_total) || (parseFloat(item.quantity_ordered) * parseFloat(item.unit_price));
                    grandTotal += total;
                    itemsHtml += `
                        <tr>
                            <td data-label="Product">
                                <strong>${escapeHtml(item.item_name)}</strong><br>
                                <small class="text-muted">${escapeHtml(item.item_code)}</small>
                            </td>
                            <td data-label="Unit" class="text-center">${escapeHtml(item.unit_type || 'N/A')}</td>
                            <td data-label="Quantity" class="text-center">${parseInt(item.quantity_ordered)}</td>
                            <td data-label="Unit Price" class="text-end">₱${parseFloat(item.unit_price).toFixed(2)}</td>
                            <td data-label="Total" class="text-end">₱${total.toFixed(2)}</td>
                        </tr>
                    `;
                });
            } else {
                itemsHtml = `
                    <tr>
                        <td colspan="5" class="text-center text-muted py-4">
                            <i class="bi bi-box"></i> No items found for this order
                        </td>
                    </tr>
                `;
            }
            
            // Build complete modal content
            if (orderDetailsContent) {
                orderDetailsContent.innerHTML = `
                    <div class="order-details-card">
                        <!-- Order Header Section -->
                        <div class="order-header-section">
                            <div class="order-badge">
                                <i class="bi bi-receipt"></i>
                                <span>Order Information</span>
                            </div>
                            <div class="order-number">${escapeHtml(order.so_number)}</div>
                        </div>
                        
                        <!-- Order Info Grid (2 columns) -->
                        <div class="order-info-grid">
                            <div class="order-info-item">
                                <div class="order-info-label">
                                    <i class="bi bi-calendar3"></i> Order Date
                                </div>
                                <div class="order-info-value">${new Date(order.order_date).toLocaleString()}</div>
                            </div>
                            <div class="order-info-item">
                                <div class="order-info-label">
                                    <i class="bi bi-flag"></i> Status
                                </div>
                                <div class="order-info-value">
                                    <span class="badge ${getOrderStatusBadgeClass(order.order_status)}">${getOrderStatusText(order.order_status)}</span>
                                </div>
                            </div>
                            <div class="order-info-item">
                                <div class="order-info-label">
                                    <i class="bi bi-building"></i> Branch
                                </div>
                                <div class="order-info-value">${escapeHtml(order.branch_name || 'N/A')}</div>
                            </div>
                            <div class="order-info-item">
                                <div class="order-info-label">
                                    <i class="bi bi-person"></i> Created By
                                </div>
                                <div class="order-info-value">${escapeHtml(order.created_by || 'System')}</div>
                            </div>
                            <div class="order-info-item">
                                <div class="order-info-label">
                                    <i class="bi bi-truck"></i> Assigned Driver
                                </div>
                                <div class="order-info-value">
                                    ${order.assigned_driver && order.assigned_driver !== 'No Driver' ? 
                                        `<span class="driver-badge-modal"><i class="bi bi-person-badge"></i> ${escapeHtml(order.assigned_driver)}</span>` : 
                                        `<span class="text-muted"><i class="bi bi-person-x"></i> No Driver Assigned</span>`}
                                </div>
                            </div>
                        </div>
                        
                        <!-- Customer Information Section -->
                        <div class="customer-section">
                            <h6>
                                <i class="bi bi-person-badge"></i> Customer Information
                            </h6>
                            <div class="customer-info-card">
                                <div class="customer-detail-row">
                                    <span class="customer-detail-label">Customer Name:</span>
                                    <span class="customer-detail-value">${escapeHtml(order.customer_name || 'N/A')}</span>
                                </div>
                                <div class="customer-detail-row">
                                    <span class="customer-detail-label">Store Name:</span>
                                    <span class="customer-detail-value">${escapeHtml(order.store_name || 'N/A')}</span>
                                </div>
                                <div class="customer-detail-row">
                                    <span class="customer-detail-label">Customer Code:</span>
                                    <span class="customer-detail-value">${escapeHtml(order.customer_code || 'N/A')}</span>
                                </div>
                                <div class="customer-detail-row">
                                    <span class="customer-detail-label">Email:</span>
                                    <span class="customer-detail-value">${escapeHtml(order.email || 'N/A')}</span>
                                </div>
                                <div class="customer-detail-row">
                                    <span class="customer-detail-label">Phone:</span>
                                    <span class="customer-detail-value">${escapeHtml(order.phone_number || 'N/A')}</span>
                                </div>
                                <div class="customer-detail-row">
                                    <span class="customer-detail-label">Address:</span>
                                    <span class="customer-detail-value">${escapeHtml(order.address || 'N/A')}</span>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Order Items Section -->
                        <div class="items-section">
                            <h6>
                                <i class="bi bi-box-seam"></i> Order Items
                            </h6>
                            <div class="table-responsive">
                                <table class="items-table">
                                    <thead>
                                        <tr>
                                            <th>Product</th>
                                            <th class="text-center">Unit</th>
                                            <th class="text-center">Quantity</th>
                                            <th class="text-end">Unit Price</th>
                                            <th class="text-end">Total</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        ${itemsHtml}
                                        <tr class="total-row">
                                            <td colspan="4" class="text-end fw-bold">Grand Total</td>
                                            <td class="text-end fw-bold text-success">₱${grandTotal.toFixed(2)}</td>
                                        </tr>
                                    </tbody>
                                </table>
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
                            </div>
                        </div>
                    </div>
                `;
            }
            
            // Show print and cancel buttons
            const printButton = document.getElementById('printOrderFromDetails');
            if (printButton) printButton.style.display = 'inline-block';
            const cancelButton = document.getElementById('cancelOrderBtn');
            if (cancelButton) cancelButton.style.display = 'inline-block';
        } else {
            if (orderDetailsContent) {
                orderDetailsContent.innerHTML = `
                    <div class="error-state">
                        <i class="bi bi-exclamation-triangle fs-1"></i>
                        <p class="mt-3">${escapeHtml(data.message || 'Error loading order details.')}</p>
                    </div>
                `;
            }
            const printButton = document.getElementById('printOrderFromDetails');
            if (printButton) printButton.style.display = 'none';
        }
    })
    .catch(error => {
        console.error('Error:', error);
        if (orderDetailsContent) {
            orderDetailsContent.innerHTML = `
                <div class="error-state">
                    <i class="bi bi-wifi-off fs-1"></i>
                    <p class="mt-3">Network error: ${escapeHtml(error.message)}</p>
                    <button class="btn btn-outline-danger mt-2" onclick="viewOrderDetails(${orderId})">
                        <i class="bi bi-arrow-repeat"></i> Try Again
                    </button>
                </div>
            `;
        }
        const printButton = document.getElementById('printOrderFromDetails');
        if (printButton) printButton.style.display = 'none';
    });
}

// Helper function to escape HTML
function escapeHtml(str) {
    if (!str) return '';
    return str
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

// Helper function for status badge class (for modal)
function getOrderStatusBadgeClass(status) {
    switch(status) {
        case 'pending': return 'bg-warning text-dark';
        case 'processing': return 'bg-info text-white';
        case 'shipped': return 'bg-primary text-white';
        case 'delivered': return 'bg-success text-white';
        case 'cancelled': return 'bg-danger text-white';
        default: return 'bg-secondary text-white';
    }
}

// Helper function for status text
function getOrderStatusText(status) {
    switch(status) {
        case 'pending': return 'Pending';
        case 'processing': return 'Processing';
        case 'shipped': return 'Shipped';
        case 'delivered': return 'Delivered';
        case 'cancelled': return 'Cancelled';
        default: return status || 'Unknown';
    }
}

        // Print all orders (with current filters)
        function printAllOrders() {
            const filterData = {
                status: document.getElementById('statusFilter').value,
                customer: document.getElementById('customerFilter').value,
                search: document.getElementById('searchInput').value,
                start_date: activeDateRange.start,
                end_date: activeDateRange.end
            };
            
            // Show loading on button
            const printBtn = document.querySelector('.btn-primary[onclick="printAllOrders()"]');
            if (printBtn) {
                const originalText = printBtn.innerHTML;
                printBtn.innerHTML = '<i class="bi bi-printer"></i> Preparing...';
                printBtn.disabled = true;
            }
            
            showLoading();
            
            const formData = new FormData();
            formData.append('action', 'get_all_order_items');
            formData.append('filter_data', JSON.stringify(filterData));
            
            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                hideLoading();
                
                if (data.success) {
                    const items = data.items;
                    
                    if (items.length === 0) {
                        Swal.fire('Warning', 'No orders match the current filters', 'warning');
                        return;
                    }
                    
<<<<<<< HEAD
                    // Show print and cancel buttons
                    const printButton = document.getElementById('printOrderFromDetails');
                    const canPrintOrder = ['pending', 'confirmed'].includes(String(order.order_status || '').toLowerCase());
                    if (printButton) printButton.style.display = canPrintOrder ? 'inline-block' : 'none';
                    const cancelButton = document.getElementById('cancelOrderBtn');
                    if (cancelButton) cancelButton.style.display = 'inline-block';
                } else {
                    if (orderDetailsContent) {
                        orderDetailsContent.innerHTML = `
                            <div class="error-state">
                                <i class="bi bi-exclamation-triangle fs-1"></i>
                                <p class="mt-3">${escapeHtml(data.message || 'Error loading order details.')}</p>
                            </div>
                        `;
=======
                    // Generate compact HTML with each item as a separate row
                    const htmlContent = generateAllOrdersHTML(items);
                    
                    // Use hidden iframe for printing
                    const iframe = document.getElementById('printFrame');
                    const iframeDoc = iframe.contentWindow.document;
                    
                    iframeDoc.open();
                    iframeDoc.write(htmlContent);
                    iframeDoc.close();
                    
                    // Restore button
                    setTimeout(() => {
                        if (printBtn) {
                            printBtn.innerHTML = '<i class="bi bi-printer"></i> Print All Orders';
                            printBtn.disabled = false;
                        }
                    }, 1000);
                    
                    // Trigger print dialog
                    setTimeout(() => {
                        iframe.contentWindow.focus();
                        iframe.contentWindow.print();
                    }, 250);
                } else {
                    Swal.fire('Error', data.message || 'Failed to load order data', 'error');
                    if (printBtn) {
                        printBtn.innerHTML = '<i class="bi bi-printer"></i> Print All Orders';
                        printBtn.disabled = false;
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
                    }
                }
            })
            .catch(error => {
                hideLoading();
                console.error('Error:', error);
<<<<<<< HEAD
                if (orderDetailsContent) {
                    orderDetailsContent.innerHTML = `
                        <div class="error-state">
                            <i class="bi bi-wifi-off fs-1"></i>
                            <p class="mt-3">Network error: ${escapeHtml(error.message)}</p>
                            <button class="btn btn-outline-danger mt-2" onclick="viewOrderDetails(${orderId})">
                                <i class="bi bi-arrow-repeat"></i> Try Again
                            </button>
                        </div>
                    `;
=======
                Swal.fire('Error', 'Network error: ' + error.message, 'error');
                if (printBtn) {
                    printBtn.innerHTML = '<i class="bi bi-printer"></i> Print All Orders';
                    printBtn.disabled = false;
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
                }
            });
        }

<<<<<<< HEAD
        // Helper function to escape HTML
        function escapeHtml(str) {
            if (!str) return '';
            return str
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        }

        // Helper function for status badge class (for modal)
        function getOrderStatusBadgeClass(status) {
            switch(status) {
                case 'pending': return 'bg-warning text-dark';
                case 'confirmed': return 'bg-secondary text-white';
                case 'processing': return 'bg-info text-white';
                case 'shipped': return 'bg-primary text-white';
                case 'delivered': return 'bg-success text-white';
                case 'cancelled': return 'bg-danger text-white';
                default: return 'bg-secondary text-white';
            }
        }

        // Helper function for status text
        function getOrderStatusText(status) {
            switch(status) {
                case 'pending': return 'Pending';
                case 'confirmed': return 'Confirmed';
                case 'processing': return 'Processing';
                case 'shipped': return 'Shipped';
                case 'delivered': return 'Delivered';
                case 'cancelled': return 'Cancelled';
                default: return status || 'Unknown';
            }
        }

        // Print all orders (with current filters) - using new print_all_orders action
        function printAllOrders() {
            const status = document.getElementById('statusFilter')?.value || '';
            const customer = document.getElementById('customerFilter')?.value || '';
            const search = document.getElementById('searchInput')?.value || '';
            const startDate = activeDateRange.start;
            const endDate = activeDateRange.end;
=======
        // Print single order
        function printSingleOrder(orderId) {
            currentOrderId = orderId;
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
            
            const printBtn = document.querySelector('.btn-primary[onclick="printAllOrders()"]');
            if (printBtn) {
                printBtn.innerHTML = '<i class="bi bi-printer"></i> Preparing...';
                printBtn.disabled = true;
            }
            
            showLoading();
            
            const formData = new FormData();
<<<<<<< HEAD
            formData.append('action', 'print_all_orders');
            formData.append('status', status);
            formData.append('customer', customer);
            formData.append('search', search);
            formData.append('start_date', startDate);
            formData.append('end_date', endDate);
=======
            formData.append('action', 'print_order');
            formData.append('so_id', orderId);
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
            
            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                hideLoading();
                
<<<<<<< HEAD
                if (data.success && data.html) {
                    const iframe = document.getElementById('printFrame');
                    const iframeDoc = iframe.contentWindow.document;
                    iframeDoc.open();
                    iframeDoc.write(data.html);
                    iframeDoc.close();
                    setTimeout(() => {
                        iframe.contentWindow.focus();
                        iframe.contentWindow.print();
                    }, 250);
                } else {
                    Swal.fire('Error', data.message || 'Failed to generate print view', 'error');
                }
                if (printBtn) {
                    printBtn.innerHTML = '<i class="bi bi-printer"></i> Print All Orders';
                    printBtn.disabled = false;
                }
            })
            .catch(error => {
                hideLoading();
                console.error('Error:', error);
                Swal.fire('Error', 'Network error: ' + error.message, 'error');
                if (printBtn) {
                    printBtn.innerHTML = '<i class="bi bi-printer"></i> Print All Orders';
                    printBtn.disabled = false;
                }
            });
        }

        // Print single order (unchanged, uses existing action)
        function printSingleOrder(orderId) {
            currentOrderId = orderId;
            
            const printBtn = event ? event.target.closest('button') : null;
            if (printBtn) {
                printBtn.innerHTML = '<i class="bi bi-printer"></i>';
                printBtn.disabled = true;
            }
            
            showLoading();
            
            const formData = new FormData();
            formData.append('action', 'print_order');
            formData.append('so_id', orderId);
            
            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                hideLoading();
                
=======
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
                if (data.success) {
                    const order = data.order;
                    const items = data.items;
                    const driver = data.driver || null;
                    
<<<<<<< HEAD
                    const htmlContent = generateSingleOrderHTML(order, items, driver);
=======
                    // Generate compact HTML with each item as a separate row
                    const htmlContent = generateSingleOrderHTML(order, items, driver);
                    
                    // Use hidden iframe for printing
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
                    const iframe = document.getElementById('printFrame');
                    const iframeDoc = iframe.contentWindow.document;
                    
                    iframeDoc.open();
                    iframeDoc.write(htmlContent);
                    iframeDoc.close();
                    
<<<<<<< HEAD
=======
                    // Restore button
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
                    setTimeout(() => {
                        if (printBtn) {
                            printBtn.innerHTML = '<i class="bi bi-printer"></i>';
                            printBtn.disabled = false;
                        }
                    }, 1000);
                    
<<<<<<< HEAD
=======
                    // Trigger print dialog
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
                    setTimeout(() => {
                        iframe.contentWindow.focus();
                        iframe.contentWindow.print();
                    }, 250);
                } else {
                    Swal.fire('Error', 'Failed to load order details', 'error');
                    if (printBtn) {
                        printBtn.innerHTML = '<i class="bi bi-printer"></i>';
                        printBtn.disabled = false;
                    }
                }
            })
            .catch(error => {
                hideLoading();
                console.error('Error:', error);
                Swal.fire('Error', 'Network error: ' + error.message, 'error');
                if (printBtn) {
                    printBtn.innerHTML = '<i class="bi bi-printer"></i>';
                    printBtn.disabled = false;
<<<<<<< HEAD
=======
                }
            });
        }

        // Generate HTML for all orders (with driver column)
        function generateAllOrdersHTML(items) {
            let tableRows = '';
            let totalAmount = 0;
            let totalQuantity = 0;
            
            items.forEach((item) => {
                const subtotal = item.quantity_ordered * item.unit_price;
                totalAmount += subtotal;
                totalQuantity += parseInt(item.quantity_ordered);
                
                tableRows += '<tr>';
                tableRows += `<td style="padding: 3px; border: 1px solid #000;">${item.so_number}</td>`;
                tableRows += `<td style="padding: 3px; border: 1px solid #000;">${item.order_date ? formatDate(item.order_date) : ''}</td>`;
                tableRows += `<td style="padding: 3px; border: 1px solid #000;">${item.customer_name}</td>`;
                if (branchColumnExists && viewAllBranches) {
                    tableRows += `<td style="padding: 3px; border: 1px solid #000;">${item.branch_name || 'Branch ' + item.branch_id}</td>`;
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
                }
                tableRows += `<td style="padding: 3px; border: 1px solid #000; text-align: center;">${item.item_code}</td>`;
                tableRows += `<td style="padding: 3px; border: 1px solid #000;">${item.item_name}</td>`;
                tableRows += `<td style="padding: 3px; border: 1px solid #000; text-align: center;">${item.unit_type || 'N/A'}</td>`;
                tableRows += `<td style="padding: 3px; border: 1px solid #000; text-align: center;">${item.quantity_ordered}</td>`;
                tableRows += `<td style="padding: 3px; border: 1px solid #000; text-align: right;">₱${parseFloat(item.unit_price).toFixed(2)}</td>`;
                tableRows += `<td style="padding: 3px; border: 1px solid #000; text-align: right;">₱${parseFloat(subtotal).toFixed(2)}</td>`;
                tableRows += `<td style="padding: 3px; border: 1px solid #000;">${item.assigned_driver || 'No Driver'}</td>`;
                tableRows += '</tr>';
            });
<<<<<<< HEAD
        }

        function formatReceiptAmount(amount) {
            const number = parseFloat(amount || 0);
            return number.toLocaleString('en-US', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        }

        // Generate thermal receipt HTML for single order, same receipt format style as fordelivery.php
        function generateSingleOrderHTML(order, items, driver) {
            let itemsHtml = '';
            let totalQty = 0;
            let totalAmount = 0;

            if (items && items.length > 0) {
                itemsHtml = items.map(item => {
                    const qty = parseFloat(item.quantity_ordered) || 0;
                    const price = parseFloat(item.unit_price) || 0;
                    const subtotal = qty * price;
                    totalQty += qty;
                    totalAmount += subtotal;
                    return `
                        <tr>
                            <td colspan="4" class="item-name">${escapeHtml(item.item_name || '')}</td>
                        </tr>
                        <tr class="item-details">
                            <td></td>
                            <td class="text-center">${qty.toLocaleString('en-US')}</td>
                            <td class="text-right">${formatReceiptAmount(price)}</td>
                            <td class="text-right">${formatReceiptAmount(subtotal)}</td>
                        </tr>
                    `;
                }).join('');
            } else {
                itemsHtml = '<tr><td colspan="4" style="text-align:center;padding:8px;">No items</td></tr>';
            }

            const createdByName = order ? (order.first_name ? `${order.first_name} ${order.last_name || ''}` : 'Sales User') : 'Sales User';
            const orderDateObj = order && order.order_date ? new Date(order.order_date) : new Date();
            const formattedDate = orderDateObj.toLocaleString('en-PH', {
                year: 'numeric',
                month: '2-digit',
                day: '2-digit',
                hour: '2-digit',
                minute: '2-digit',
                hour12: true
            });
            const printDate = new Date().toLocaleString('en-PH', {
                year: 'numeric',
                month: '2-digit',
                day: '2-digit',
                hour: '2-digit',
                minute: '2-digit',
                hour12: true
            });

            const customerName = order ? escapeHtml(order.customer_name || 'Walk-in Customer') : '';
            const orderNumber = order ? escapeHtml(order.so_number || '') : '';
            const orderStatus = order ? getOrderStatusText(order.order_status) : '';
            const dbTotal = order ? parseFloat(order.order_total || order.total_amount || 0) : 0;
            if (dbTotal > 0) totalAmount = dbTotal;
            const driverName = driver ? escapeHtml(driver.driver_name || 'No Driver') : (order?.assigned_driver !== 'No Driver' ? escapeHtml(order?.assigned_driver || 'No Driver') : 'No Driver');
            const branchName = order ? escapeHtml(order.branch_name || '') : '';
            const receiptNumber = orderNumber || ('ORDER-' + String(order?.so_id || '').padStart(4, '0'));

            return `
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Print Receipt ${orderNumber}</title>
<link href="https://fonts.googleapis.com/css2?family=Roboto+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
@page { size: 80mm auto; margin: 0; }
html, body {
    width: 80mm;
    margin: 0;
    padding: 0;
    background: #fff;
    color: #000;
    font-family: "Roboto Mono", monospace;
}
.print-wrapper {
    width: 80mm;
    margin: 0 auto;
    padding: 0;
    text-align: center;
}
.thermal-receipt {
    display: inline-block;
    width: 72mm;
    margin: 0 auto;
    padding: 3mm;
    box-sizing: border-box;
    background: #fff;
    color: #000;
    font-family: "Roboto Mono", monospace;
    font-size: 10px;
    line-height: 1.35;
    text-align: left;
    border: none !important;
    box-shadow: none !important;
}
.receipt-header {
    text-align: center;
    margin-bottom: 5px;
    padding-bottom: 5px;
    border-bottom: 1px dashed #000;
}
.company-name { font-size: 16px; font-weight: 700; letter-spacing: 0.5px; }
.receipt-title { font-size: 12px; font-weight: 700; }
.receipt-no { font-size: 9px; }
.receipt-info {
    margin: 5px 0;
    padding: 0;
    background: transparent;
    font-size: 9px;
}
.info-line { display: block; margin: 2px 0; }
.info-label { font-weight: 700; }
.info-value { font-weight: 400; }
.items-table {
    width: 100%;
    margin: 5px 0;
    border-collapse: collapse;
    font-size: 9px;
}
.items-table th {
    padding: 3px 0;
    border-bottom: 1px solid #000;
    font-weight: 700;
}
.items-table td { padding: 2px 0; vertical-align: top; }
.item-name {
    font-size: 9px;
    font-weight: 500;
    word-break: break-word;
    padding-top: 5px;
}
.item-details td {
    border-bottom: 1px dotted #999;
    padding-bottom: 5px;
    font-size: 9px;
}
.item-code { color: #333; max-width: 18mm; word-break: break-word; }
.text-center { text-align: center; }
.text-right { text-align: right; }
.receipt-total {
    margin-top: 6px;
    padding-top: 5px;
    border-top: 1px solid #000;
    text-align: right;
    font-size: 12px;
    font-weight: 700;
}
.receipt-footer {
    margin-top: 10px;
    text-align: center;
    font-size: 9px;
    padding-bottom: 25px;
}
</style>
</head>
<body>
    <div class="print-wrapper">
        <div class="thermal-receipt">
            <div class="receipt-header">
                <div class="company-name">AMGC</div>
                <div class="receipt-title">SALES ORDER RECEIPT</div>
                <div class="receipt-no">${receiptNumber}</div>
                <div>${printDate}</div>
            </div>

            <div class="receipt-info">
                <div class="info-line"><span class="info-label">Date:</span> <span class="info-value">${formattedDate}</span></div>
                <div class="info-line"><span class="info-label">Status:</span> <span class="info-value">${orderStatus}</span></div>
                <div class="info-line"><span class="info-label">Customer:</span> <span class="info-value">${customerName}</span></div>
                <div class="info-line"><span class="info-label">Driver:</span> <span class="info-value">${driverName}</span></div>
                ${branchName ? `<div class="info-line"><span class="info-label">Branch:</span> <span class="info-value">${branchName}</span></div>` : ''}
            </div>

            <table class="items-table">
                <thead>
                    <tr>
                        <th>Item</th>
                        <th class="text-center">Qty</th>
                        <th class="text-right">Price</th>
                        <th class="text-right">Total</th>
                    </tr>
                </thead>
                <tbody>
                    ${itemsHtml}
                </tbody>
            </table>

            <div class="receipt-total">
                TOTAL: ${formatCurrency(totalAmount)}
            </div>

            <div class="receipt-footer">
                Created by: ${escapeHtml(createdByName)}<br>
                *** Thank you! ***
            </div>
        </div>
    </div>
<script>
window.onload = function () {
    setTimeout(function () {
        window.focus();
        window.print();
    }, 500);
};
<\/script>
</body>
</html>
            `;
        }

=======
            
            const currentDate = new Date().toLocaleDateString('en-US', { 
                year: 'numeric', 
                month: 'short', 
                day: 'numeric' 
            });
            
            const colCount = branchColumnExists && viewAllBranches ? 12 : 11;
            const summaryColspan = branchColumnExists && viewAllBranches ? 9 : 8;
            
            return `
                <!DOCTYPE html>
                <html>
                <head>
                    <meta charset="UTF-8">
                    <title>Sales Orders - Detailed</title>
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
                        th { border: 1px solid #000; padding: 3px; text-align: left; font-weight: bold; }
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
                                    <p>Sales Orders - Detailed</p>
                                </div>
                            </div>
                            <div class="report-title">
                                <h2>SALES ORDERS</h2>
                                <div class="date-info">${currentDate}</div>
                            </div>
                        </div>
                        
                        <div class="summary-box">
                            <div class="summary-item"><div class="summary-label">Total Items</div><div class="summary-value">${items.length}</div></div>
                            <div class="summary-item"><div class="summary-label">Total Qty</div><div class="summary-value">${totalQuantity}</div></div>
                            <div class="summary-item"><div class="summary-label">Total Amount</div><div class="summary-value">₱${totalAmount.toFixed(2)}</div></div>
                            <div class="summary-item"><div class="summary-label">Branch</div><div class="summary-value">${!viewAllBranches && branchId > 0 ? `Branch ${branchId}` : 'All'}</div></div>
                        </div>
                        
                        <table>
                            <thead>
                                <tr>
                                    <th>Order #</th>
                                    <th>Date</th>
                                    <th>Customer</th>
                                    ${branchColumnExists && viewAllBranches ? '<th>Branch</th>' : ''}
                                    <th>Item Code</th>
                                    <th>Item Name</th>
                                    <th style="text-align: center;">Unit</th>
                                    <th style="text-align: center;">Qty</th>
                                    <th style="text-align: right;">Unit Price</th>
                                    <th style="text-align: right;">Subtotal</th>
                                    <th>Driver</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${tableRows}
                                <tr class="total-row">
                                    <td colspan="${summaryColspan}" style="text-align: right;">TOTAL</td>
                                    <td style="text-align: center;">${totalQuantity}</td>
                                    <td style="text-align: right;">₱${totalAmount.toFixed(2)}</td>
                                    <td></td>
                                </tr>
                            </tbody>
                        </table>
                        
                        <div class="print-footer">
                            <div>Generated: ${currentDate}</div>
                            <div>${document.querySelector('.user-name-sidebar')?.textContent || 'Sales User'}</div>
                        </div>
                    </div>
                </body>
                </html>
            `;
        }

        // Generate HTML for single order (with driver info)
        function generateSingleOrderHTML(order, items, driver) {
            let itemsHtml = '';
            let totalQty = 0;
            
            if (items && items.length > 0) {
                itemsHtml = items.map(item => {
                    const subtotal = item.quantity_ordered * item.unit_price;
                    totalQty += parseInt(item.quantity_ordered);
                    return `
                        <tr>
                            <td style="padding: 3px; border: 1px solid #000;">${item.item_code}</td>
                            <td style="padding: 3px; border: 1px solid #000;">${item.item_name}</td>
                            <td style="padding: 3px; border: 1px solid #000; text-align: center;">${item.unit_type || ''}</td>
                            <td style="padding: 3px; border: 1px solid #000; text-align: center;">${item.quantity_ordered}</td>
                            <td style="padding: 3px; border: 1px solid #000; text-align: right;">₱${parseFloat(item.unit_price).toFixed(2)}</td>
                            <td style="padding: 3px; border: 1px solid #000; text-align: right;">₱${parseFloat(subtotal).toFixed(2)}</td>
                        </tr>
                    `;
                }).join('');
            }
            
            const createdByName = order ? (order.first_name ? `${order.first_name} ${order.last_name || ''}` : 'Sales User') : 'Sales User';
            const currentDate = new Date().toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
            const orderDate = order ? (order.order_date ? new Date(order.order_date).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' }) : currentDate) : currentDate;
            const customerName = order ? order.customer_name : '';
            const orderNumber = order ? order.so_number : '';
            const orderStatus = order ? order.order_status : '';
            const totalAmount = order ? order.order_total : 0;
            const driverName = driver ? driver.driver_name : (order?.assigned_driver !== 'No Driver' ? order?.assigned_driver : 'No Driver');
            
            return `
                <!DOCTYPE html>
                <html>
                <head>
                    <meta charset="UTF-8">
                    <title>Order #${orderNumber}</title>
                    <style>
                        body { font-family: Arial, sans-serif; margin: 0; padding: 0; font-size: 10px; }
                        .print-container { max-width: 100%; margin: 0; }
                        .print-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 5px; border-bottom: 1px solid #000; padding-bottom: 3px; }
                        .logo-section { display: flex; align-items: center; gap: 5px; }
                        .company-logo { width: 30px; height: auto; }
                        .company-info h1 { font-size: 14px; margin: 0; font-weight: bold; }
                        .company-info p { font-size: 8px; margin: 0; }
                        .report-title h2 { font-size: 12px; margin: 0; }
                        .report-title .date-info { font-size: 8px; }
                        .customer-section { border: 1px solid #000; padding: 5px; margin-bottom: 5px; }
                        .section-title { font-size: 10px; font-weight: bold; margin-bottom: 3px; border-bottom: 1px solid #000; }
                        .info-row { display: flex; font-size: 9px; margin-bottom: 2px; }
                        .info-label { width: 80px; font-weight: bold; }
                        .info-value { flex: 1; }
                        table { width: 100%; border-collapse: collapse; font-size: 9px; }
                        th { border: 1px solid #000; padding: 3px; text-align: left; font-weight: bold; }
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
                                    <p>Sales Order</p>
                                </div>
                            </div>
                            <div class="report-title">
                                <h2>${orderNumber}</h2>
                                <div class="date-info">${currentDate}</div>
                            </div>
                        </div>
                        
                        <div class="customer-section">
                            <div class="section-title">Order Info</div>
                            <div style="display: flex; flex-wrap: wrap;">
                                <div class="info-row" style="width: 50%;"><span class="info-label">Date:</span><span class="info-value">${orderDate}</span></div>
                                <div class="info-row" style="width: 50%;"><span class="info-label">Status:</span><span class="info-value">${orderStatus}</span></div>
                                <div class="info-row" style="width: 50%;"><span class="info-label">Customer:</span><span class="info-value">${customerName}</span></div>
                                <div class="info-row" style="width: 50%;"><span class="info-label">Driver:</span><span class="info-value">${driverName}</span></div>
                            </div>
                        </div>
                        
                        <div class="section-title">Items</div>
                        <table>
                            <thead>
                                <tr>
                                    <th>Item Code</th>
                                    <th>Item Name</th>
                                    <th style="text-align: center;">Unit</th>
                                    <th style="text-align: center;">Qty</th>
                                    <th style="text-align: right;">Unit Price</th>
                                    <th style="text-align: right;">Subtotal</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${itemsHtml}
                                <tr class="total-row">
                                    <td colspan="3" style="text-align: right;">TOTAL</td>
                                    <td style="text-align: center;">${totalQty}</td>
                                    <td></td>
                                    <td style="text-align: right;">₱${parseFloat(totalAmount).toFixed(2)}</td>
                                </tr>
                            </tbody>
                        </table>
                        
                        <div class="print-footer">
                            <div>Created by: ${createdByName}</div>
                            <div>${currentDate}</div>
                        </div>
                    </div>
                </body>
                </html>
            `;
        }

>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
        // Print from details modal
        function printFromDetails() {
            if (currentOrderId) {
                printSingleOrder(currentOrderId);
                const modal = bootstrap.Modal.getInstance(document.getElementById('orderDetailsModal'));
                if (modal) modal.hide();
            }
        }

<<<<<<< HEAD
        // Cancel order function (unchanged)
=======
        // Cancel order function
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
        function cancelOrder() {
            if (!currentOrderId) {
                Swal.fire('Error', 'No order selected', 'error');
                return;
            }

<<<<<<< HEAD
=======
            // Show confirmation dialog
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
            Swal.fire({
                title: 'Cancel Order?',
                text: 'Are you sure you want to cancel this order? This action cannot be undone.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc3545',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Yes, cancel it',
                cancelButtonText: 'No, keep it'
            }).then((result) => {
                if (result.isConfirmed) {
<<<<<<< HEAD
                    const cancelBtn = document.getElementById('cancelOrderBtn');
                    if (cancelBtn) cancelBtn.disabled = true;

=======
                    // Disable button to prevent double clicks
                    const cancelBtn = document.getElementById('cancelOrderBtn');
                    if (cancelBtn) cancelBtn.disabled = true;

                    // Send cancel request
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
                    fetch('', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        body: 'action=cancel_order&order_id=' + currentOrderId
                    })
<<<<<<< HEAD
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
=======
                    .then(response => {
                        if (!response.ok) {
                            throw new Error('Network response was not ok');
                        }
                        return response.json();
                    })
                    .then(data => {
                        if (data.success) {
                            // Show success toast
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
                            Swal.fire({
                                title: 'Success!',
                                text: 'Order cancelled successfully',
                                icon: 'success',
                                timer: 2000,
                                showConfirmButton: false
                            });
<<<<<<< HEAD
                            const modal = bootstrap.Modal.getInstance(document.getElementById('orderDetailsModal'));
                            if (modal) modal.hide();
                            setTimeout(() => refreshOrders(), 500);
=======

                            // Close modal
                            const modal = bootstrap.Modal.getInstance(document.getElementById('orderDetailsModal'));
                            if (modal) modal.hide();

                            // Refresh the orders table
                            setTimeout(() => {
                                refreshOrders();
                            }, 500);

>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
                            currentOrderId = null;
                        } else {
                            Swal.fire('Error', data.message || 'Failed to cancel order', 'error');
                            if (cancelBtn) cancelBtn.disabled = false;
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        Swal.fire('Error', 'Error cancelling order: ' + error.message, 'error');
                        if (cancelBtn) cancelBtn.disabled = false;
                    });
                }
            });
        }

<<<<<<< HEAD
        // Export to Excel with new format using export_all_orders action
=======
        // Export to Excel with new format
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
        function exportToExcel() {
            const status = document.getElementById('statusFilter')?.value || '';
            const customer = document.getElementById('customerFilter')?.value || '';
            const search = document.getElementById('searchInput')?.value || '';
            const startDate = activeDateRange.start;
            const endDate = activeDateRange.end;
            
            showLoading();
            
            const formData = new FormData();
            formData.append('action', 'export_all_orders');
            formData.append('status', status);
            formData.append('customer', customer);
            formData.append('search', search);
            formData.append('start_date', startDate);
            formData.append('end_date', endDate);
            
            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                hideLoading();
                
<<<<<<< HEAD
                if (data.success && data.data && data.data.length > 0) {
                    const headers = [
                        'Date Encoded',
                        'SO Order Number',
                        'Customer Code',
                        'Store Name',
                        'Customer Name',
                        'Item Code',
                        'Item Description',
                        'Discount',
                        'Net Price',
                        'Total Discount',
                        'Encoded by'
                    ];
                    const rows = data.data.map(row => [
                        row.date_encoded,
                        row.so_order_number,
                        row.customer_code,
                        row.store_name,
                        row.customer_name,
                        row.item_code,
                        row.item_description,
                        row.discount,
                        row.net_price,
                        row.total_discount,
                        row.encoded_by
                    ]);
                    
                    const wsData = [headers, ...rows];
                    const ws = XLSX.utils.aoa_to_sheet(wsData);
                    const wb = XLSX.utils.book_new();
                    XLSX.utils.book_append_sheet(wb, ws, 'Sales Orders Export');
                    
                    const date = new Date();
                    const dateStr = date.toISOString().slice(0,10).replace(/-/g, '');
                    let filename = `Sales_Orders_Export_${dateStr}`;
                    if (!viewAllBranches && branchId > 0) {
                        filename += `_Branch_${branchId}`;
                    }
                    filename += '.xlsx';
                    
                    XLSX.writeFile(wb, filename);
                    Swal.fire({
                        icon: 'success',
                        title: 'Export Complete',
                        text: 'Excel export completed successfully!',
                        timer: 2000,
                        showConfirmButton: false
                    });
                } else if (data.success && (!data.data || data.data.length === 0)) {
                    Swal.fire('Warning', 'No data to export', 'warning');
                } else {
                    Swal.fire('Error', data.message || 'Export failed', 'error');
=======
                if (data.success && data.items && data.items.length > 0) {
                    generateExcelFile(data.items);
                } else {
                    Swal.fire('Warning', 'No data to export', 'warning');
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
                }
            })
            .catch(error => {
                hideLoading();
                console.error('Error:', error);
                Swal.fire('Error', 'Error exporting data: ' + error.message, 'error');
            });
        }

<<<<<<< HEAD
=======
        // Generate Excel file with new format: Customer Code, Store Name, Customer Name, Item Code, Item Description, Discount, Net Price, Total Discount, Date Encoded, Encoded by
        function generateExcelFile(items) {
            // Prepare data array for Excel - NEW FORMAT
            const excelData = [];
            
            // Add headers - NEW COLUMNS
            const headers = [
                'Customer Code',
                'Store Name',
                'Customer Name',
                'Item Code',
                'Item Description',
                'Discount',
                'Net Price',
                'Total Discount',
                'Date Encoded',
                'Encoded by'
            ];
            excelData.push(headers);

            // Add data rows
            items.forEach(item => {
                // Calculate discount (assuming discount is 0 for now - can be modified based on credit_discount_requests)
                const discount = 0;
                const netPrice = parseFloat(item.unit_price) || 0;
                const totalDiscount = (item.quantity_ordered * netPrice * discount) / 100;
                
                // Get encoded by (created by user)
                const encodedBy = item.created_by_name || 'Sales User';
                
                // Get store name (if available from customers table)
                const storeName = item.store_name || '';
                
                // Get customer code (if available)
                const customerCode = item.customer_code || '';
                
                const rowData = [
                    customerCode,                                    // Customer Code
                    storeName,                                       // Store Name
                    item.customer_name || '',                       // Customer Name
                    item.item_code || '',                           // Item Code
                    item.item_name || '',                           // Item Description
                    discount,                                        // Discount
                    netPrice,                                        // Net Price
                    totalDiscount,                                   // Total Discount
                    item.order_date ? formatDateForExport(item.order_date) : '', // Date Encoded
                    encodedBy                                        // Encoded by
                ];
                
                excelData.push(rowData);
            });

            // Add summary row at the bottom
            const totalQty = items.reduce((sum, item) => sum + parseInt(item.quantity_ordered), 0);
            const totalRevenue = items.reduce((sum, item) => sum + (parseFloat(item.quantity_ordered) * parseFloat(item.unit_price)), 0);
            
            const summaryRow = [
                'TOTAL',
                '',
                '',
                '',
                '',
                '',
                '',
                totalRevenue.toFixed(2),
                '',
                ''
            ];
            excelData.push(['']); // Empty row for spacing
            excelData.push(summaryRow);
            
            // Add additional summary info
            const summaryInfoRow = [
                `Total Items: ${totalQty}`,
                `Total Orders: ${new Set(items.map(i => i.so_id)).size}`,
                `Total Revenue: ₱${totalRevenue.toFixed(2)}`,
                `Generated: ${new Date().toLocaleString()}`,
                '',
                '',
                '',
                '',
                '',
                ''
            ];
            excelData.push(summaryInfoRow);

            // Create workbook and worksheet
            const wb = XLSX.utils.book_new();
            const ws = XLSX.utils.aoa_to_sheet(excelData);

            // Set column widths for new columns
            const colWidths = [
                { wch: 15 }, // Customer Code
                { wch: 25 }, // Store Name
                { wch: 30 }, // Customer Name
                { wch: 15 }, // Item Code
                { wch: 35 }, // Item Description
                { wch: 10 }, // Discount
                { wch: 12 }, // Net Price
                { wch: 15 }, // Total Discount
                { wch: 15 }, // Date Encoded
                { wch: 20 }  // Encoded by
            ];
            ws['!cols'] = colWidths;

            // Add worksheet to workbook
            XLSX.utils.book_append_sheet(wb, ws, 'Sales Orders Export');

            // Generate filename with current date
            const date = new Date();
            const dateStr = date.toISOString().slice(0,10).replace(/-/g, '');
            let filename = `Sales_Orders_Export_${dateStr}`;
            if (!viewAllBranches && branchId > 0) {
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

        // Helper function to format date for Excel export
        function formatDateForExport(dateStr) {
            if (!dateStr) return '';
            const date = new Date(dateStr);
            return date.toLocaleDateString('en-US', { year: 'numeric', month: '2-digit', day: '2-digit' });
        }

>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
        // Helper function to format date
        function formatDate(dateStr) {
            if (!dateStr) return '';
            const date = new Date(dateStr);
            return date.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
        }

        // Logout function
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
            else if (e.ctrlKey && e.key === 'r' && !e.target.matches('input, textarea')) {
                e.preventDefault();
                refreshOrders();
            }
            else if (e.ctrlKey && e.key === 'p' && !e.target.matches('input, textarea')) {
                e.preventDefault();
                printAllOrders();
            }
            else if (e.ctrlKey && e.key === 'e' && !e.target.matches('input, textarea')) {
                e.preventDefault();
                exportToExcel();
            }
        });
<<<<<<< HEAD
        
        // ============= MOBILE NAVIGATION FUNCTION =============
=======
                // ============= MOBILE NAVIGATION FUNCTION =============
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
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

        // Profile Modal Functions
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
<<<<<<< HEAD
        
        // Collapsible Filter Toggle - Default Closed
        document.addEventListener('DOMContentLoaded', function() {
            const filterToggleBtn = document.getElementById('filterToggleBtn');
            const filterContent = document.getElementById('filterContent');
            
            if (filterToggleBtn && filterContent) {
                // Default: collapsed (closed)
                filterContent.classList.add('collapsed');
                filterToggleBtn.setAttribute('aria-expanded', 'false');
                
                // Toggle on click
                filterToggleBtn.addEventListener('click', function() {
                    const isExpanded = filterToggleBtn.getAttribute('aria-expanded') === 'true';
                    
                    if (isExpanded) {
                        // Close
                        filterContent.classList.add('collapsed');
                        filterToggleBtn.setAttribute('aria-expanded', 'false');
                    } else {
                        // Open
                        filterContent.classList.remove('collapsed');
                        filterToggleBtn.setAttribute('aria-expanded', 'true');
                    }
                });
            }
        });
        
        // Tap/click row to view order details
        function handleOrderRowClick(event, orderId) {
            const ignoredSelector = 'button, a, input, select, textarea, label, .btn, .btn-action, .action-buttons';
            if (event.target.closest(ignoredSelector)) {
                return;
            }
            if (orderId && typeof viewOrderDetails === 'function') {
                viewOrderDetails(orderId);
            }
        }

        // ========== ALIAS FOR AUTO-OPEN SCRIPT ==========
        // Your auto-open script uses viewOrderDetails, but the actual function is viewOrder
        // This creates the alias so the auto-open works
        if (typeof viewOrder === 'function' && typeof viewOrderDetails === 'undefined') {
            window.viewOrderDetails = viewOrder;
            console.log('viewOrderDetails alias created for auto-open functionality');
        }

        // Optional: Also check if you need viewOrderDetails for other purposes
        if (typeof viewOrderDetails === 'undefined' && typeof viewOrder === 'undefined') {
            console.warn('Neither viewOrder nor viewOrderDetails function found');
        }

=======
        // Collapsible Filter Toggle - Default Closed
document.addEventListener('DOMContentLoaded', function() {
    const filterToggleBtn = document.getElementById('filterToggleBtn');
    const filterContent = document.getElementById('filterContent');
    
    if (filterToggleBtn && filterContent) {
        // Default: collapsed (closed)
        filterContent.classList.add('collapsed');
        filterToggleBtn.setAttribute('aria-expanded', 'false');
        
        // Toggle on click
        filterToggleBtn.addEventListener('click', function() {
            const isExpanded = filterToggleBtn.getAttribute('aria-expanded') === 'true';
            
            if (isExpanded) {
                // Close
                filterContent.classList.add('collapsed');
                filterToggleBtn.setAttribute('aria-expanded', 'false');
            } else {
                // Open
                filterContent.classList.remove('collapsed');
                filterToggleBtn.setAttribute('aria-expanded', 'true');
            }
        });
    }
});
// Tap to view functionality para sa mobile card view
document.addEventListener('DOMContentLoaded', function() {
    // Kunin lahat ng order rows
    const orderRows = document.querySelectorAll('.sales-order-row');
    
    orderRows.forEach(row => {
        row.addEventListener('click', function(e) {
            // Para sa mobile lang (width <= 767px)
            if (window.innerWidth <= 767) {
                // Kunin ang order ID mula sa data-id attribute
                const orderId = this.dataset.id;
                
                if (orderId) {
                    // Tawagin ang viewOrderDetails function
                    viewOrderDetails(orderId);
                }
            }
        });
    });
});
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
    </script>
</body>
</html>
