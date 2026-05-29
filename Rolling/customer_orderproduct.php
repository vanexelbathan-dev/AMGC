<?php
require_once '../config/database.php';
require_once '../config/session_handler.php';

// Protect page - only Rolling Account role can access
requireLogin();
requireRole(['rolling']);


// ================================
// CUSTOMER DETAILS HELPERS
// Outstanding Balance + Total Oil Volume for Rolling customer modal
// ================================
function amgcRollingTableExists($conn, $table) {
    $table = $conn->real_escape_string($table);
    $res = $conn->query("SHOW TABLES LIKE '{$table}'");
    return $res && $res->num_rows > 0;
}

function amgcRollingColumnExists($conn, $table, $column) {
    if (!amgcRollingTableExists($conn, $table)) return false;
    $table = $conn->real_escape_string($table);
    $column = $conn->real_escape_string($column);
    $res = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
    return $res && $res->num_rows > 0;
}

function amgcRollingGetCustomerOutstandingBalance($conn, $customer_id, $user_id, $branch_id = 0, $branch_column_exists = false) {
    $customer_id = (int)$customer_id;
    $user_id = (int)$user_id;
    $branch_id = (int)$branch_id;
    if ($customer_id <= 0 || $user_id <= 0 || !amgcRollingTableExists($conn, 'sales_orders')) return 0.00;

    $hasInvoices = amgcRollingTableExists($conn, 'invoices');
    $hasInvoiceSo = $hasInvoices && amgcRollingColumnExists($conn, 'invoices', 'so_id');
    $hasOrderAmount = amgcRollingColumnExists($conn, 'sales_orders', 'order_amount');
    $soAmountExpr = $hasOrderAmount ? "COALESCE(NULLIF(so.total_amount, 0), so.order_amount, 0)" : "COALESCE(so.total_amount, 0)";

    $types = 'ii';
    $params = [$customer_id, $user_id];
    $branchFilter = '';
    if ($branch_column_exists && $branch_id > 0 && amgcRollingColumnExists($conn, 'sales_orders', 'branch_id')) {
        $branchFilter = ' AND so.branch_id = ?';
        $types .= 'i';
        $params[] = $branch_id;
    }

    if ($hasInvoiceSo) {
        $sql = "
            SELECT COALESCE(SUM(unpaid_amount), 0) AS total_unpaid
            FROM (
                SELECT
                    GREATEST(
                        CASE
                            WHEN LOWER(TRIM(COALESCE(so.payment_status, 'unpaid'))) IN ('paid', 'completed') THEN 0
                            WHEN LOWER(TRIM(COALESCE(so.order_status, ''))) IN ('pending', 'cancelled') THEN 0
                            WHEN inv.invoice_id IS NOT NULL THEN
                                CASE
                                    WHEN LOWER(TRIM(COALESCE(inv.status, 'pending'))) = 'paid' THEN 0
                                    ELSE GREATEST(COALESCE(inv.balance, 0), COALESCE(inv.total_amount, {$soAmountExpr}, 0) - COALESCE(inv.amount_paid, 0), 0)
                                END
                            ELSE {$soAmountExpr}
                        END,
                        0
                    ) AS unpaid_amount
                FROM sales_orders so
                LEFT JOIN (
                    SELECT
                        so_id,
                        MAX(invoice_id) AS invoice_id,
                        SUM(COALESCE(total_amount, 0)) AS total_amount,
                        SUM(COALESCE(amount_paid, 0)) AS amount_paid,
                        SUM(COALESCE(balance, 0)) AS balance,
                        CASE
                            WHEN SUM(CASE WHEN LOWER(TRIM(COALESCE(status, 'pending'))) <> 'paid' THEN 1 ELSE 0 END) = 0 THEN 'paid'
                            WHEN SUM(CASE WHEN LOWER(TRIM(COALESCE(status, 'pending'))) = 'overdue' THEN 1 ELSE 0 END) > 0 THEN 'overdue'
                            ELSE 'pending'
                        END AS status
                    FROM invoices
                    WHERE so_id IS NOT NULL AND so_id > 0
                    GROUP BY so_id
                ) inv ON inv.so_id = so.so_id
                WHERE so.customer_id = ?
                  AND so.created_by = ?
                  {$branchFilter}
            ) unpaid_rows
        ";
    } else {
        $sql = "
            SELECT COALESCE(SUM(
                CASE
                    WHEN LOWER(TRIM(COALESCE(so.payment_status, 'unpaid'))) IN ('paid', 'completed') THEN 0
                    WHEN LOWER(TRIM(COALESCE(so.order_status, ''))) IN ('pending', 'cancelled') THEN 0
                    ELSE {$soAmountExpr}
                END
            ), 0) AS total_unpaid
            FROM sales_orders so
            WHERE so.customer_id = ?
              AND so.created_by = ?
              {$branchFilter}
        ";
    }

    $stmt = $conn->prepare($sql);
    if (!$stmt) return 0.00;
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    return max(0, (float)($row['total_unpaid'] ?? 0));
}

function amgcRollingGetCustomerOilVolume($conn, $customer_id, $user_id, $branch_id = 0, $branch_column_exists = false) {
    $customer_id = (int)$customer_id;
    $user_id = (int)$user_id;
    $branch_id = (int)$branch_id;
    if ($customer_id <= 0 || $user_id <= 0) return 0.00;
    if (!amgcRollingTableExists($conn, 'sales_orders') || !amgcRollingTableExists($conn, 'sales_order_items') || !amgcRollingTableExists($conn, 'items')) return 0.00;
    if (!amgcRollingColumnExists($conn, 'items', 'volume') || !amgcRollingColumnExists($conn, 'items', 'category')) return 0.00;

    $types = 'ii';
    $params = [$customer_id, $user_id];
    $branchFilter = '';
    if ($branch_column_exists && $branch_id > 0 && amgcRollingColumnExists($conn, 'sales_orders', 'branch_id')) {
        $branchFilter = ' AND so.branch_id = ?';
        $types .= 'i';
        $params[] = $branch_id;
    }

    $sql = "
        SELECT COALESCE(SUM(
            CASE
                WHEN LOWER(TRIM(COALESCE(i.category, ''))) = 'oil'
                 AND TRIM(COALESCE(i.volume, '')) <> ''
                 AND REPLACE(TRIM(i.volume), ',', '') REGEXP '^[0-9]+(\\.[0-9]+)?$'
                THEN CAST(REPLACE(TRIM(i.volume), ',', '') AS DECIMAL(12,4)) * COALESCE(soi.quantity_ordered, 0)
                ELSE 0
            END
        ), 0) AS total_oil_volume
        FROM sales_order_items soi
        INNER JOIN sales_orders so ON so.so_id = soi.so_id
        INNER JOIN items i ON i.item_id = soi.item_id
        WHERE so.customer_id = ?
          AND so.created_by = ?
          AND LOWER(TRIM(COALESCE(so.order_status, ''))) NOT IN ('pending', 'cancelled')
          {$branchFilter}
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) return 0.00;
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    return max(0, (float)($row['total_oil_volume'] ?? 0));
}


// ============= AJAX: GET CUSTOMER DETAILS (Rolling-safe) =============
// Same-file endpoint para hindi na mag-fail kapag ang filename ay customer.php/customer_orderproduct.php.
// Supports:
//   GET  ?ajax_customer_details=1&id=123
//   GET  ?action=get_customer_details&id=123
//   POST action=get_customer_details&customer_id=123
$amgc_is_customer_details_request = false;
if ($_SERVER['REQUEST_METHOD'] === 'GET' && (isset($_GET['ajax_customer_details']) || (($_GET['action'] ?? '') === 'get_customer_details'))) {
    $amgc_is_customer_details_request = true;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'get_customer_details')) {
    $amgc_is_customer_details_request = true;
}

if ($amgc_is_customer_details_request) {
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: application/json');

    try {
        $customer_id = (int)($_GET['id'] ?? $_GET['customer_id'] ?? $_POST['id'] ?? $_POST['customer_id'] ?? 0);
        $ajax_user_id = (int)($_SESSION['user_id'] ?? 0);
        $ajax_branch_id = (int)($_SESSION['rolling_branch_id'] ?? $_SESSION['branch_id'] ?? 0);

        if ($customer_id <= 0 || $ajax_user_id <= 0) {
            throw new Exception('Invalid customer request.');
        }

        $branch_column_exists_ajax = false;
        $check_branch_col_ajax = $conn->query("SHOW COLUMNS FROM customers LIKE 'branch_id'");
        if ($check_branch_col_ajax && $check_branch_col_ajax->num_rows > 0) {
            $branch_column_exists_ajax = true;
        }

        $created_by_column_exists_ajax = false;
        $check_created_by_ajax = $conn->query("SHOW COLUMNS FROM customers LIKE 'created_by'");
        if ($check_created_by_ajax && $check_created_by_ajax->num_rows > 0) {
            $created_by_column_exists_ajax = true;
        }

        $invoices_table_exists_ajax = false;
        $check_invoices_ajax = $conn->query("SHOW TABLES LIKE 'invoices'");
        if ($check_invoices_ajax && $check_invoices_ajax->num_rows > 0) {
            $invoices_table_exists_ajax = true;
        }

        $invoice_so_col_exists_ajax = false;
        if ($invoices_table_exists_ajax) {
            $check_invoice_so_ajax = $conn->query("SHOW COLUMNS FROM invoices LIKE 'so_id'");
            if ($check_invoice_so_ajax && $check_invoice_so_ajax->num_rows > 0) {
                $invoice_so_col_exists_ajax = true;
            }
        }

        $customer_sql = "SELECT c.* FROM customers c WHERE c.customer_id = ?";
        $customer_types = 'i';
        $customer_params = [$customer_id];

        // Rolling can view/edit only customers registered by the logged-in Rolling account.
        if ($created_by_column_exists_ajax) {
            $customer_sql .= " AND c.created_by = ?";
            $customer_types .= 'i';
            $customer_params[] = $ajax_user_id;
        }

        if ($branch_column_exists_ajax && $ajax_branch_id > 0) {
            $customer_sql .= " AND c.branch_id = ?";
            $customer_types .= 'i';
            $customer_params[] = $ajax_branch_id;
        }
        $customer_sql .= " LIMIT 1";

        $customer_stmt = $conn->prepare($customer_sql);
        if (!$customer_stmt) {
            throw new Exception('Database prepare error: ' . $conn->error);
        }
        $customer_stmt->bind_param($customer_types, ...$customer_params);
        $customer_stmt->execute();
        $customer_result = $customer_stmt->get_result();
        $customer = $customer_result ? $customer_result->fetch_assoc() : null;

        if (!$customer) {
            throw new Exception('Customer not found or access denied.');
        }

        $customer['outstanding_balance'] = amgcRollingGetCustomerOutstandingBalance($conn, $customer_id, $ajax_user_id, $ajax_branch_id, $branch_column_exists_ajax);
        $customer['total_oil_volume'] = amgcRollingGetCustomerOilVolume($conn, $customer_id, $ajax_user_id, $ajax_branch_id, $branch_column_exists_ajax);

        $orders = [];
        if ($invoice_so_col_exists_ajax) {
            $orders_sql = "SELECT
                    so.so_id,
                    so.so_number,
                    COALESCE(so.created_at, so.order_date) AS order_date,
                    so.total_amount,
                    so.order_status,
                    COALESCE(inv.status, '') AS invoice_status,
                    COALESCE(inv.invoice_number, '') AS invoice_number
                FROM sales_orders so
                LEFT JOIN invoices inv ON inv.so_id = so.so_id
                WHERE so.customer_id = ?";
        } else {
            $orders_sql = "SELECT
                    so.so_id,
                    so.so_number,
                    COALESCE(so.created_at, so.order_date) AS order_date,
                    so.total_amount,
                    so.order_status,
                    '' AS invoice_status,
                    '' AS invoice_number
                FROM sales_orders so
                WHERE so.customer_id = ?";
        }

        $orders_types = 'i';
        $orders_params = [$customer_id];

        // Customer modal must only show orders made by this Rolling user.
        $orders_sql .= " AND so.created_by = ?";
        $orders_types .= 'i';
        $orders_params[] = $ajax_user_id;

        if ($branch_column_exists_ajax && $ajax_branch_id > 0) {
            $orders_sql .= " AND so.branch_id = ?";
            $orders_types .= 'i';
            $orders_params[] = $ajax_branch_id;
        }
        $orders_sql .= " ORDER BY COALESCE(so.created_at, so.order_date) DESC, so.so_id DESC";

        $orders_stmt = $conn->prepare($orders_sql);
        if ($orders_stmt) {
            $orders_stmt->bind_param($orders_types, ...$orders_params);
            $orders_stmt->execute();
            $orders_result = $orders_stmt->get_result();
            if ($orders_result) {
                while ($order = $orders_result->fetch_assoc()) {
                    $orders[] = $order;
                }
            }
        }

        $customer['orders'] = $orders;

        echo json_encode([
            'success' => true,
            'customer' => $customer
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
    exit;
}

// ============= HANDLE GET ORDER DETAILS (for modal) =============
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'get_order_details') {
    header('Content-Type: application/json');
    
    try {
        $order_id = (int)$_POST['order_id'];
        
        // Check permissions - user can only view orders from their branch or all if admin
        $branch_id = $_SESSION['rolling_branch_id'] ?? $_SESSION['branch_id'] ?? 0;
        $view_all_branches = false;
        $user_id = $_SESSION['user_id'] ?? 0;
        
        // Build query with proper permissions
        if ($view_all_branches) {
            $check_sql = "SELECT so_id FROM sales_orders WHERE so_id = ? AND created_by = ?";
            $check_stmt = $conn->prepare($check_sql);
            $check_stmt->bind_param('ii', $order_id, $user_id);
        } else {
            $check_sql = "SELECT so_id FROM sales_orders WHERE so_id = ? AND branch_id = ? AND created_by = ?";
            $check_stmt = $conn->prepare($check_sql);
            $check_stmt->bind_param('iii', $order_id, $branch_id, $user_id);
        }
        
        if (!$check_stmt) {
            throw new Exception("Database prepare error");
        }
        
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows === 0) {
            echo json_encode(['success' => false, 'message' => 'Order not found or access denied']);
            exit;
        }
        
        // Get order details
        $sql = "SELECT 
                    so.so_id,
                    so.so_number,
                    so.order_date,
                    so.total_amount,
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
        $stmt->bind_param('i', $order_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            echo json_encode(['success' => false, 'message' => 'Order not found']);
            exit;
        }
        
        $order = $result->fetch_assoc();
        
        // Get order items
        $items_sql = "SELECT 
                        soi.so_item_id,
                        soi.so_id,
                        soi.item_id,
                        soi.quantity_ordered,
                        soi.quantity_delivered,
                        soi.unit_price,
                        soi.line_total,
                        i.item_name,
                        i.item_code,
                        soi.unit_type
                     FROM sales_order_items soi
                     JOIN items i ON soi.item_id = i.item_id
                     WHERE soi.so_id = ?
                     ORDER BY soi.so_item_id";
        $items_stmt = $conn->prepare($items_sql);
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

// ============= HANDLE CANCEL ORDER =============
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'cancel_order') {
    header('Content-Type: application/json');
    
    try {
        $order_id = (int)$_POST['order_id'];
        $branch_id = $_SESSION['rolling_branch_id'] ?? $_SESSION['branch_id'] ?? 0;
        $view_all_branches = false;
        $user_id = $_SESSION['user_id'] ?? 0;
        
        // Verify permissions
        if ($view_all_branches) {
            $check_sql = "SELECT so_id FROM sales_orders WHERE so_id = ? AND created_by = ?";
            $check_stmt = $conn->prepare($check_sql);
            $check_stmt->bind_param('ii', $order_id, $user_id);
        } else {
            $check_sql = "SELECT so_id FROM sales_orders WHERE so_id = ? AND branch_id = ? AND created_by = ?";
            $check_stmt = $conn->prepare($check_sql);
            $check_stmt->bind_param('iii', $order_id, $branch_id, $user_id);
        }
        
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows === 0) {
            echo json_encode(['success' => false, 'message' => 'Order not found or access denied']);
            exit;
        }
        
        // Start transaction
        $conn->begin_transaction();
        
        // Delete order items
        $delete_items_sql = "DELETE FROM sales_order_items WHERE so_id = ?";
        $delete_items_stmt = $conn->prepare($delete_items_sql);
        $delete_items_stmt->bind_param('i', $order_id);
        $delete_items_stmt->execute();
        
        // Delete pick lists
        $delete_picklist_sql = "DELETE FROM pick_lists WHERE so_id = ?";
        $delete_picklist_stmt = $conn->prepare($delete_picklist_sql);
        $delete_picklist_stmt->bind_param('i', $order_id);
        $delete_picklist_stmt->execute();
        
        // Delete order
        $delete_order_sql = "DELETE FROM sales_orders WHERE so_id = ?";
        $delete_order_stmt = $conn->prepare($delete_order_sql);
        $delete_order_stmt->bind_param('i', $order_id);
        $delete_order_stmt->execute();
        
        $conn->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Order cancelled successfully'
        ]);
        
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// ============= HANDLE PRINT ORDER =============
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'print_order') {
    header('Content-Type: application/json');
    
    try {
        $so_id = (int)$_POST['so_id'];
        $branch_id = $_SESSION['rolling_branch_id'] ?? $_SESSION['branch_id'] ?? 0;
        $view_all_branches = false;
        $user_id = $_SESSION['user_id'] ?? 0;
        
        // Verify order access
        if ($view_all_branches) {
            $check_sql = "SELECT so_id FROM sales_orders WHERE so_id = ? AND created_by = ?";
            $check_stmt = $conn->prepare($check_sql);
            $check_stmt->bind_param('ii', $so_id, $user_id);
        } else {
            $check_sql = "SELECT so_id FROM sales_orders WHERE so_id = ? AND branch_id = ? AND created_by = ?";
            $check_stmt = $conn->prepare($check_sql);
            $check_stmt->bind_param('iii', $so_id, $branch_id, $user_id);
        }
        
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows === 0) {
            throw new Exception('Order not found or access denied');
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
        
        // Get driver details separately
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
// Get current user info and branch context
$user_id = $_SESSION['user_id'] ?? 0;
$user_name = isset($_SESSION['first_name']) ? $_SESSION['first_name'] . ' ' . $_SESSION['last_name'] : 'Rolling Account';
$user_role = isset($_SESSION['role']) ? $_SESSION['role'] : 'rolling_account';
$branch_id = $_SESSION['rolling_branch_id'] ?? $_SESSION['branch_id'] ?? 0;
$view_all_branches = false; // Rolling accounts are restricted to their assigned branch

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
    $user_initials = 'RA';
}

// ============= HANDLE RSR (ROUTE SALES REPORT) SUBMISSION =============
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_rsr') {
    header('Content-Type: application/json');
    
    try {
        $customer_id = isset($_POST['customer_id']) ? intval($_POST['customer_id']) : null;
        $rsr_date = isset($_POST['rsr_date']) ? $_POST['rsr_date'] : null;
        $status = isset($_POST['status']) ? $_POST['status'] : null;
        $remarks = isset($_POST['remarks']) ? $_POST['remarks'] : '';
        $store_name = isset($_POST['store_name']) ? $_POST['store_name'] : '';
        $address = isset($_POST['address']) ? $_POST['address'] : '';
        
        // Validate required fields
        if (!$customer_id || !$rsr_date || !$status) {
            throw new Exception('Missing required fields');
        }
        
        // Check if rsr_reports table exists, if not create it
        $table_check = $conn->query("SHOW TABLES LIKE 'rsr_reports'");
        if ($table_check->num_rows == 0) {
            // Create table if it doesn't exist
            $create_table = "CREATE TABLE rsr_reports (
                rsr_id INT AUTO_INCREMENT PRIMARY KEY,
                customer_id INT NOT NULL,
                rsr_date DATE NOT NULL,
                store_name VARCHAR(255),
                address TEXT,
                status VARCHAR(50) NOT NULL,
                remarks TEXT,
                reported_by INT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (customer_id) REFERENCES customers(customer_id) ON DELETE CASCADE,
                FOREIGN KEY (reported_by) REFERENCES users(user_id) ON DELETE SET NULL
            )";
            
            if (!$conn->query($create_table)) {
                throw new Exception('Failed to create RSR table: ' . $conn->error);
            }
        }
        
        // Insert RSR record with the user_id from session
        $insert_query = "INSERT INTO rsr_reports (customer_id, rsr_date, store_name, address, status, remarks, reported_by) 
                        VALUES (?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $conn->prepare($insert_query);
        if (!$stmt) {
            throw new Exception('Prepare failed: ' . $conn->error);
        }
        
        // Bind parameters: i=integer, s=string
        $stmt->bind_param("isssssi", $customer_id, $rsr_date, $store_name, $address, $status, $remarks, $user_id);
        
        if (!$stmt->execute()) {
            throw new Exception('Execute failed: ' . $stmt->error);
        }
        
        $stmt->close();
        
        echo json_encode([
            'success' => true,
            'message' => 'Route Sales Report saved successfully',
            'reported_by_user' => $user_name
        ]);
        
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
    exit;
}

// Function to generate unique customer code
function generateCustomerCode($conn) {
    $prefix = 'CUST-';
    $year = date('Y');
    $month = date('m');
    
    // Get the latest customer code for this year/month
    $query = "SELECT customer_code FROM customers 
              WHERE customer_code LIKE '$prefix$year$month%' 
              ORDER BY customer_code DESC LIMIT 1";
    $result = $conn->query($query);
    
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $last_code = $row['customer_code'];
        // Extract the sequence number
        $sequence = intval(substr($last_code, -4)) + 1;
    } else {
        $sequence = 1;
    }
    
    // Format: CUST-YYYYMM-XXXX
    $new_code = $prefix . $year . $month . '-' . str_pad($sequence, 4, '0', STR_PAD_LEFT);
    return $new_code;
}

// Check if branch_id column exists in customers table
$branch_column_exists = false;
$check_column = $conn->query("SHOW COLUMNS FROM customers LIKE 'branch_id'");
if ($check_column && $check_column->num_rows > 0) {
    $branch_column_exists = true;
}

// Check if created_by column exists in customers table (needed so Rolling only sees own customers)
$customers_created_by_column_exists = false;
$check_customer_created_by = $conn->query("SHOW COLUMNS FROM customers LIKE 'created_by'");
if ($check_customer_created_by && $check_customer_created_by->num_rows > 0) {
    $customers_created_by_column_exists = true;
} else {
    $conn->query("ALTER TABLE customers ADD COLUMN created_by int(11) DEFAULT NULL AFTER city_code");
    $check_customer_created_by = $conn->query("SHOW COLUMNS FROM customers LIKE 'created_by'");
    if ($check_customer_created_by && $check_customer_created_by->num_rows > 0) {
        $customers_created_by_column_exists = true;
    }
}

// Philippine Regions data
$regions = [
    'NCR' => 'National Capital Region',
    'CAR' => 'Cordillera Administrative Region',
    'Region I' => 'Ilocos Region',
    'Region II' => 'Cagayan Valley',
    'Region III' => 'Central Luzon',
    'Region IV-A' => 'CALABARZON',
    'Region IV-B' => 'MIMAROPA',
    'Region V' => 'Bicol Region',
    'Region VI' => 'Western Visayas',
    'Region VII' => 'Central Visayas',
    'Region VIII' => 'Eastern Visayas',
    'Region IX' => 'Zamboanga Peninsula',
    'Region X' => 'Northern Mindanao',
    'Region XI' => 'Davao Region',
    'Region XII' => 'SOCCSKSARGEN',
    'Region XIII' => 'Caraga',
    'BARMM' => 'Bangsamoro Autonomous Region in Muslim Mindanao'
];

// Provinces data by region
$provinces = [
    'NCR' => ['Metro Manila'],
    'CAR' => ['Abra', 'Apayao', 'Benguet', 'Ifugao', 'Kalinga', 'Mountain Province'],
    'Region I' => ['Ilocos Norte', 'Ilocos Sur', 'La Union', 'Pangasinan'],
    'Region II' => ['Batanes', 'Cagayan', 'Isabela', 'Nueva Vizcaya', 'Quirino'],
    'Region III' => ['Aurora', 'Bataan', 'Bulacan', 'Nueva Ecija', 'Pampanga', 'Tarlac', 'Zambales'],
    'Region IV-A' => ['Batangas', 'Cavite', 'Laguna', 'Quezon', 'Rizal'],
    'Region IV-B' => ['Marinduque', 'Occidental Mindoro', 'Oriental Mindoro', 'Palawan', 'Romblon'],
    'Region V' => ['Albay', 'Camarines Norte', 'Camarines Sur', 'Catanduanes', 'Masbate', 'Sorsogon'],
    'Region VI' => ['Aklan', 'Antique', 'Capiz', 'Guimaras', 'Iloilo', 'Negros Occidental'],
    'Region VII' => ['Bohol', 'Cebu', 'Negros Oriental', 'Siquijor'],
    'Region VIII' => ['Biliran', 'Eastern Samar', 'Leyte', 'Northern Samar', 'Samar', 'Southern Leyte'],
    'Region IX' => ['Zamboanga del Norte', 'Zamboanga del Sur', 'Zamboanga Sibugay'],
    'Region X' => ['Bukidnon', 'Camiguin', 'Lanao del Norte', 'Misamis Occidental', 'Misamis Oriental'],
    'Region XI' => ['Davao de Oro', 'Davao del Norte', 'Davao del Sur', 'Davao Occidental', 'Davao Oriental'],
    'Region XII' => ['Cotabato', 'Sarangani', 'South Cotabato', 'Sultan Kudarat'],
    'Region XIII' => ['Agusan del Norte', 'Agusan del Sur', 'Dinagat Islands', 'Surigao del Norte', 'Surigao del Sur'],
    'BARMM' => ['Basilan', 'Lanao del Sur', 'Maguindanao', 'Sulu', 'Tawi-Tawi']
];

// Sort provinces alphabetically for each region
foreach ($provinces as $region => $province_list) {
    sort($provinces[$region]);
}

// COMPLETE CITIES/MUNICIPALITIES DATA (fallback kung mag-fail ang API)
$cities = [
    'Metro Manila' => ['Manila', 'Quezon City', 'Caloocan', 'Las Piñas', 'Makati', 'Malabon', 'Mandaluyong', 'Marikina', 'Muntinlupa', 'Navotas', 'Parañaque', 'Pasay', 'Pasig', 'San Juan', 'Taguig', 'Valenzuela', 'Pateros'],
    'Abra' => ['Bangued', 'Boliney', 'Bucay', 'Bucloc', 'Daguioman', 'Danglas', 'Dolores', 'La Paz', 'Lacub', 'Lagangilang', 'Lagayan', 'Langiden', 'Licuan-Baay', 'Luba', 'Malibcong', 'Manabo', 'Peñarrubia', 'Pidigan', 'Pilar', 'Sallapadan', 'San Isidro', 'San Juan', 'San Quintin', 'Tayum', 'Tineg', 'Tubo', 'Villaviciosa'],
    'Apayao' => ['Calanasan', 'Conner', 'Flora', 'Kabugao', 'Luna', 'Pudtol', 'Santa Marcela'],
    'Benguet' => ['Atok', 'Baguio', 'Bakun', 'Bokod', 'Buguias', 'Itogon', 'Kabayan', 'Kapangan', 'Kibungan', 'La Trinidad', 'Mankayan', 'Sablan', 'Tuba', 'Tublay'],
    'Ifugao' => ['Aguinaldo', 'Alfonso Lista', 'Asipulo', 'Banaue', 'Hingyon', 'Hungduan', 'Kiangan', 'Lagawe', 'Lamut', 'Mayoyao', 'Tinoc'],
    'Kalinga' => ['Balbalan', 'Lubuagan', 'Pasil', 'Pinukpuk', 'Rizal', 'Tanudan', 'Tinglayan'],
    'Mountain Province' => ['Barlig', 'Bauko', 'Besao', 'Bontoc', 'Natonin', 'Paracelis', 'Sabangan', 'Sadanga', 'Sagada', 'Tadian'],
    'Ilocos Norte' => ['Adams', 'Bacarra', 'Badoc', 'Bangui', 'Banna', 'Batac', 'Burgos', 'Carasi', 'Currimao', 'Dingras', 'Dumalneg', 'Laoag', 'Marcos', 'Nueva Era', 'Pagudpud', 'Paoay', 'Pasuquin', 'Piddig', 'Pinili', 'San Nicolas', 'Sarrat', 'Solsona', 'Vintar'],
    'Ilocos Sur' => ['Alilem', 'Banayoyo', 'Bantay', 'Burgos', 'Cabugao', 'Candon', 'Caoayan', 'Cervantes', 'Galimuyod', 'Gregorio Del Pilar', 'Lidlidda', 'Magsingal', 'Nagbukel', 'Narvacan', 'Quirino', 'Salcedo', 'San Emilio', 'San Esteban', 'San Ildefonso', 'San Juan', 'San Vicente', 'Santa', 'Santa Catalina', 'Santa Cruz', 'Santa Lucia', 'Santa Maria', 'Santiago', 'Santo Domingo', 'Sigay', 'Sinait', 'Sugpon', 'Suyo', 'Tagudin', 'Vigan'],
    'La Union' => ['Agoo', 'Aringay', 'Bacnotan', 'Bagulin', 'Balaoan', 'Bangar', 'Bauang', 'Burgos', 'Caba', 'Luna', 'Naguilian', 'Pugo', 'Rosario', 'San Fernando', 'San Gabriel', 'San Juan', 'Santo Tomas', 'Santol', 'Sudipen', 'Tubao'],
    'Pangasinan' => ['Agno', 'Aguilar', 'Alaminos', 'Alcala', 'Anda', 'Asingan', 'Balungao', 'Bani', 'Basista', 'Bautista', 'Bayambang', 'Binalonan', 'Binmaley', 'Bolinao', 'Bugallon', 'Burgos', 'Calasiao', 'Dagupan', 'Dasol', 'Infanta', 'Labrador', 'Laoac', 'Lingayen', 'Mabini', 'Malasiqui', 'Manaoag', 'Mangaldan', 'Mangatarem', 'Mapandan', 'Natividad', 'Pozorrubio', 'Rosales', 'San Carlos', 'San Fabian', 'San Jacinto', 'San Manuel', 'San Nicolas', 'San Quintin', 'Santa Barbara', 'Santa Maria', 'Santo Tomas', 'Sison', 'Sual', 'Tayug', 'Umingan', 'Urbiztondo', 'Urdaneta', 'Villasis'],
    'Batanes' => ['Basco', 'Itbayat', 'Ivana', 'Mahatao', 'Sabtang', 'Uyugan'],
    'Cagayan' => ['Abulug', 'Alcala', 'Allacapan', 'Amulung', 'Aparri', 'Baggao', 'Ballesteros', 'Buguey', 'Calayan', 'Camalaniugan', 'Claveria', 'Enrile', 'Gattaran', 'Gonzaga', 'Iguig', 'Lal-lo', 'Lasam', 'Pamplona', 'Peñablanca', 'Piat', 'Rizal', 'Sanchez-Mira', 'Santa Ana', 'Santa Praxedes', 'Santa Teresita', 'Santo Niño', 'Solana', 'Tuao', 'Tuguegarao'],
    'Isabela' => ['Alicia', 'Angadanan', 'Aurora', 'Benito Soliven', 'Burgos', 'Cabagan', 'Cabatuan', 'Cauayan', 'Cordon', 'Delfin Albano', 'Dinapigue', 'Divilacan', 'Echague', 'Gamu', 'Ilagan', 'Jones', 'Luna', 'Maconacon', 'Mallig', 'Naguilian', 'Palanan', 'Quezon', 'Quirino', 'Ramon', 'Reina Mercedes', 'Roxas', 'San Agustin', 'San Guillermo', 'San Isidro', 'San Manuel', 'San Mariano', 'San Mateo', 'San Pablo', 'Santa Maria', 'Santiago', 'Santo Tomas', 'Tumauini'],
    'Nueva Vizcaya' => ['Alfonso Castaneda', 'Ambaguio', 'Aritao', 'Bagabag', 'Bambang', 'Bayombong', 'Diadi', 'Dupax del Norte', 'Dupax del Sur', 'Kasibu', 'Kayapa', 'Quezon', 'Santa Fe', 'Solano', 'Villaverde'],
    'Quirino' => ['Aglipay', 'Cabarroguis', 'Diffun', 'Maddela', 'Nagtipunan', 'Saguday'],
    'Aurora' => ['Baler', 'Casiguran', 'Dilasag', 'Dinalungan', 'Dingalan', 'Dipaculao', 'Maria Aurora', 'San Luis'],
    'Bataan' => ['Abucay', 'Bagac', 'Balanga', 'Dinalupihan', 'Hermosa', 'Limay', 'Mariveles', 'Morong', 'Orani', 'Orion', 'Pilar', 'Samal'],
    'Bulacan' => ['Angat', 'Balagtas', 'Baliuag', 'Bocaue', 'Bulakan', 'Bustos', 'Calumpit', 'Doña Remedios Trinidad', 'Guiguinto', 'Hagonoy', 'Malolos', 'Marilao', 'Meycauayan', 'Norzagaray', 'Obando', 'Pandi', 'Paombong', 'Plaridel', 'Pulilan', 'San Ildefonso', 'San Jose Del Monte', 'San Miguel', 'San Rafael', 'Santa Maria'],
    'Nueva Ecija' => ['Aliaga', 'Bongabon', 'Cabanatuan', 'Cabiao', 'Carranglan', 'Cuyapo', 'Gabaldon', 'Gapan', 'General Mamerto Natividad', 'General Tinio', 'Guimba', 'Jaen', 'Laur', 'Licab', 'Llanera', 'Lupao', 'Muñoz', 'Nampicuan', 'Palayan', 'Pantabangan', 'Peñaranda', 'Quezon', 'Rizal', 'San Antonio', 'San Isidro', 'San Jose', 'San Leonardo', 'Santa Rosa', 'Santo Domingo', 'Talavera', 'Talugtug', 'Zaragoza'],
    'Pampanga' => ['Angeles', 'Apalit', 'Arayat', 'Bacolor', 'Candaba', 'Floridablanca', 'Guagua', 'Lubao', 'Mabalacat', 'Macabebe', 'Magalang', 'Masantol', 'Mexico', 'Minalin', 'Porac', 'San Fernando', 'San Luis', 'San Simon', 'Santa Ana', 'Santa Rita', 'Santo Tomas', 'Sasmuan'],
    'Tarlac' => ['Anao', 'Bamban', 'Camiling', 'Capas', 'Concepcion', 'Gerona', 'La Paz', 'Mayantoc', 'Moncada', 'Paniqui', 'Pura', 'Ramos', 'San Clemente', 'San Jose', 'San Manuel', 'Santa Ignacia', 'Tarlac', 'Victoria'],
    'Zambales' => ['Botolan', 'Cabangan', 'Candelaria', 'Castillejos', 'Iba', 'Masinloc', 'Olongapo', 'Palauig', 'San Antonio', 'San Felipe', 'San Marcelino', 'San Narciso', 'Santa Cruz', 'Subic'],
    'Batangas' => ['Agoncillo', 'Alitagtag', 'Balayan', 'Balete', 'Batangas City', 'Bauan', 'Calaca', 'Calatagan', 'Cuenca', 'Ibaan', 'Laurel', 'Lemery', 'Lian', 'Lipa', 'Lobo', 'Mabini', 'Malvar', 'Mataasnakahoy', 'Nasugbu', 'Padre Garcia', 'Rosario', 'San Jose', 'San Juan', 'San Luis', 'San Nicolas', 'San Pascual', 'Santa Teresita', 'Santo Tomas', 'Taal', 'Talisay', 'Tanauan', 'Taysan', 'Tingloy', 'Tuy'],
    'Cavite' => ['Alfonso', 'Amadeo', 'Bacoor', 'Carmona', 'Cavite City', 'Dasmariñas', 'General Emilio Aguinaldo', 'General Mariano Alvarez', 'General Trias', 'Imus', 'Indang', 'Kawit', 'Magallanes', 'Maragondon', 'Mendez', 'Naic', 'Noveleta', 'Rosario', 'Silang', 'Tagaytay', 'Tanza', 'Ternate', 'Trece Martires'],
    'Laguna' => ['Alaminos', 'Bay', 'Biñan', 'Cabuyao', 'Calamba', 'Calauan', 'Cavinti', 'Famy', 'Kalayaan', 'Liliw', 'Los Baños', 'Luisiana', 'Lumban', 'Mabitac', 'Magdalena', 'Majayjay', 'Nagcarlan', 'Paete', 'Pagsanjan', 'Pakil', 'Pangil', 'Pila', 'Rizal', 'San Pablo', 'San Pedro', 'Santa Cruz', 'Santa Maria', 'Santa Rosa', 'Siniloan', 'Victoria'],
    'Quezon' => ['Agdangan', 'Alabat', 'Atimonan', 'Buenavista', 'Burdeos', 'Calauag', 'Candelaria', 'Catanauan', 'Dolores', 'General Luna', 'General Nakar', 'Guinayangan', 'Gumaca', 'Infanta', 'Jomalig', 'Lopez', 'Lucban', 'Lucena', 'Macalelon', 'Mauban', 'Mulanay', 'Padre Burgos', 'Pagbilao', 'Panukulan', 'Patnanungan', 'Perez', 'Pitogo', 'Plaridel', 'Polillo', 'Quezon', 'Real', 'Sampaloc', 'San Andres', 'San Antonio', 'San Francisco', 'San Narciso', 'Sariaya', 'Tagkawayan', 'Tayabas', 'Tiaong', 'Unisan'],
    'Rizal' => ['Angono', 'Antipolo', 'Baras', 'Binangonan', 'Cainta', 'Cardona', 'Jala-Jala', 'Morong', 'Pililla', 'Rodriguez', 'San Mateo', 'Tanay', 'Taytay', 'Teresa'],
    'Marinduque' => ['Boac', 'Buenavista', 'Gasan', 'Mogpog', 'Santa Cruz', 'Torrijos'],
    'Occidental Mindoro' => ['Abra de Ilog', 'Calintaan', 'Looc', 'Lubang', 'Magsaysay', 'Mamburao', 'Paluan', 'Rizal', 'Sablayan', 'San Jose', 'Santa Cruz'],
    'Oriental Mindoro' => ['Baco', 'Bansud', 'Bongabong', 'Bulalacao', 'Calapan', 'Gloria', 'Mansalay', 'Naujan', 'Pinamalayan', 'Pola', 'Puerto Galera', 'Roxas', 'San Teodoro', 'Socorro', 'Victoria'],
    'Palawan' => ['Aborlan', 'Agutaya', 'Araceli', 'Balabac', 'Bataraza', 'Brookes Point', 'Busuanga', 'Cagayancillo', 'Coron', 'Culion', 'Cuyo', 'Dumaran', 'El Nido', 'Kalayaan', 'Linapacan', 'Magsaysay', 'Narra', 'Puerto Princesa', 'Quezon', 'Rizal', 'Roxas', 'San Vicente', 'Sofronio Española', 'Taytay'],
    'Romblon' => ['Alcantara', 'Banton', 'Cajidiocan', 'Calatrava', 'Concepcion', 'Corcuera', 'Ferrol', 'Looc', 'Magdiwang', 'Odiongan', 'Romblon', 'San Agustin', 'San Andres', 'San Fernando', 'San Jose', 'Santa Fe', 'Santa Maria'],
    'Albay' => ['Bacacay', 'Camalig', 'Daraga', 'Guinobatan', 'Jovellar', 'Legazpi', 'Libon', 'Ligao', 'Malilipot', 'Malinao', 'Manito', 'Oas', 'Pio Duran', 'Polangui', 'Rapu-Rapu', 'Santo Domingo', 'Tabaco', 'Tiwi'],
    'Camarines Norte' => ['Basud', 'Capalonga', 'Daet', 'Jose Panganiban', 'Labo', 'Mercedes', 'Paracale', 'San Lorenzo Ruiz', 'San Vicente', 'Santa Elena', 'Talisay', 'Vinzons'],
    'Camarines Sur' => ['Baao', 'Balatan', 'Bato', 'Bombon', 'Buhi', 'Bula', 'Cabusao', 'Calabanga', 'Camaligan', 'Canaman', 'Caramoan', 'Del Gallego', 'Gainza', 'Garchitorena', 'Goa', 'Iriga', 'Lagonoy', 'Libmanan', 'Lupi', 'Magarao', 'Milaor', 'Minalabac', 'Nabua', 'Naga', 'Ocampo', 'Pamplona', 'Pasacao', 'Pili', 'Presentacion', 'Ragay', 'Sagñay', 'San Fernando', 'San Jose', 'Sipocot', 'Siruma', 'Tigaon', 'Tinambac'],
    'Catanduanes' => ['Bagamanoc', 'Baras', 'Bato', 'Caramoran', 'Gigmoto', 'Pandan', 'Panganiban', 'San Andres', 'San Miguel', 'Viga', 'Virac'],
    'Masbate' => ['Aroroy', 'Baleno', 'Balud', 'Batuan', 'Cataingan', 'Cawayan', 'Claveria', 'Dimasalang', 'Esperanza', 'Mandaon', 'Masbate City', 'Milagros', 'Mobo', 'Monreal', 'Palanas', 'Pio V. Corpuz', 'Placer', 'San Fernando', 'San Jacinto', 'San Pascual', 'Uson'],
    'Sorsogon' => ['Barcelona', 'Bulan', 'Bulusan', 'Casiguran', 'Castilla', 'Donsol', 'Gubat', 'Irosin', 'Juban', 'Magallanes', 'Matnog', 'Pilar', 'Prieto Diaz', 'Santa Magdalena', 'Sorsogon City'],
    'Aklan' => ['Altavas', 'Balete', 'Banga', 'Batan', 'Buruanga', 'Ibajay', 'Kalibo', 'Lezo', 'Libacao', 'Madalag', 'Makato', 'Malay', 'Malinao', 'Nabas', 'New Washington', 'Numancia', 'Tangalan'],
    'Antique' => ['Anini-y', 'Barbaza', 'Belison', 'Bugasong', 'Caluya', 'Culasi', 'Hamtic', 'Laua-an', 'Libertad', 'Pandan', 'Patnongon', 'San Jose', 'San Remigio', 'Sebaste', 'Sibalom', 'Tibiao', 'Tobias Fornier', 'Valderrama'],
    'Capiz' => ['Cuartero', 'Dao', 'Dumalag', 'Dumarao', 'Ivisan', 'Jamindan', 'Ma-ayon', 'Mambusao', 'Panay', 'Panitan', 'Pilar', 'Pontevedra', 'President Roxas', 'Roxas City', 'Sapi-an', 'Sigma', 'Tapaz'],
    'Guimaras' => ['Buenavista', 'Jordan', 'Nueva Valencia', 'San Lorenzo', 'Sibunag'],
    'Iloilo' => ['Ajuy', 'Alimodian', 'Anilao', 'Badiangan', 'Balasan', 'Banate', 'Barotac Nuevo', 'Barotac Viejo', 'Batad', 'Bingawan', 'Cabatuan', 'Calinog', 'Carles', 'Concepcion', 'Dingle', 'Dueñas', 'Dumangas', 'Estancia', 'Guimbal', 'Igbaras', 'Iloilo City', 'Janiuay', 'Lambunao', 'Leganes', 'Lemery', 'Leon', 'Maasin', 'Miagao', 'Mina', 'New Lucena', 'Oton', 'Passi', 'Pavia', 'Pototan', 'San Dionisio', 'San Enrique', 'San Joaquin', 'San Miguel', 'San Rafael', 'Santa Barbara', 'Sara', 'Tigbauan', 'Tubungan', 'Zarraga'],
    'Negros Occidental' => ['Bacolod', 'Bago', 'Binalbagan', 'Cadiz', 'Calatrava', 'Candoni', 'Cauayan', 'Enrique B. Magalona', 'Escalante', 'Himamaylan', 'Hinigaran', 'Hinoba-an', 'Ilog', 'Isabela', 'Kabankalan', 'La Carlota', 'La Castellana', 'Manapla', 'Moises Padilla', 'Murcia', 'Pontevedra', 'Pulupandan', 'Sagay', 'Salvador Benedicto', 'San Carlos', 'San Enrique', 'Silay', 'Sipalay', 'Talisay', 'Toboso', 'Valladolid', 'Victorias'],
    'Bohol' => ['Alburquerque', 'Alicia', 'Anda', 'Antequera', 'Baclayon', 'Balilihan', 'Batuan', 'Bien Unido', 'Bilar', 'Buenavista', 'Calape', 'Candijay', 'Carmen', 'Catigbian', 'Clarin', 'Corella', 'Cortes', 'Dagohoy', 'Danao', 'Dauis', 'Dimiao', 'Duero', 'Garcia Hernandez', 'Getafe', 'Guindulman', 'Inabanga', 'Jagna', 'Lila', 'Loay', 'Loboc', 'Loon', 'Mabini', 'Maribojoc', 'Panglao', 'Pilar', 'President Carlos P. Garcia', 'Sagbayan', 'San Isidro', 'San Miguel', 'Sevilla', 'Sierra Bullones', 'Sikatuna', 'Tagbilaran', 'Talibon', 'Trinidad', 'Tubigon', 'Ubay', 'Valencia'],
    'Cebu' => ['Alcantara', 'Alcoy', 'Alegria', 'Aloguinsan', 'Argao', 'Asturias', 'Badian', 'Balamban', 'Bantayan', 'Barili', 'Bogo', 'Boljoon', 'Borbon', 'Carcar', 'Carmen', 'Catmon', 'Cebu City', 'Compostela', 'Consolacion', 'Cordova', 'Daanbantayan', 'Dalaguete', 'Danao', 'Dumanjug', 'Ginatilan', 'Lapu-Lapu', 'Liloan', 'Madridejos', 'Malabuyoc', 'Mandaue', 'Medellin', 'Minglanilla', 'Moalboal', 'Naga', 'Oslob', 'Pilar', 'Pinamungajan', 'Poro', 'Ronda', 'Samboan', 'San Fernando', 'San Francisco', 'San Remigio', 'Santa Fe', 'Santander', 'Sibonga', 'Sogod', 'Tabogon', 'Tabuelan', 'Talisay', 'Toledo', 'Tuburan', 'Tudela'],
    'Negros Oriental' => ['Amlan', 'Ayungon', 'Bacong', 'Bais', 'Basay', 'Bayawan', 'Bindoy', 'Canlaon', 'Dauin', 'Dumaguete', 'Guihulngan', 'Jimalalud', 'La Libertad', 'Mabinay', 'Manjuyod', 'Pamplona', 'San Jose', 'Santa Catalina', 'Siaton', 'Sibulan', 'Tanjay', 'Tayasan', 'Valencia', 'Vallehermoso', 'Zamboanguita'],
    'Siquijor' => ['Enrique Villanueva', 'Larena', 'Lazi', 'Maria', 'San Juan', 'Siquijor'],
    'Biliran' => ['Almeria', 'Biliran', 'Cabucgayan', 'Caibiran', 'Culaba', 'Kawayan', 'Maripipi', 'Naval'],
    'Eastern Samar' => ['Arteche', 'Balangiga', 'Balangkayan', 'Borongan', 'Can-avid', 'Dolores', 'General MacArthur', 'Giporlos', 'Guiuan', 'Hernani', 'Jipapad', 'Lawaan', 'Llorente', 'Maslog', 'Maydolong', 'Mercedes', 'Oras', 'Quinapondan', 'Salcedo', 'San Julian', 'San Policarpo', 'Sulat', 'Taft'],
    'Leyte' => ['Abuyog', 'Alangalang', 'Albuera', 'Babatngon', 'Barugo', 'Bato', 'Baybay', 'Burauen', 'Calubian', 'Capoocan', 'Carigara', 'Dagami', 'Dulag', 'Hilongos', 'Hindang', 'Inopacan', 'Isabel', 'Jaro', 'Javier', 'Julita', 'Kananga', 'La Paz', 'Leyte', 'MacArthur', 'Mahaplag', 'Matag-ob', 'Matalom', 'Mayorga', 'Ormoc', 'Palo', 'Palompon', 'Pastrana', 'San Isidro', 'San Miguel', 'Santa Fe', 'Tabango', 'Tabontabon', 'Tacloban', 'Tanauan', 'Tolosa', 'Tunga', 'Villaba'],
    'Northern Samar' => ['Allen', 'Biri', 'Bobon', 'Capul', 'Catarman', 'Catubig', 'Gamay', 'Laoang', 'Lapinig', 'Las Navas', 'Lavezares', 'Lope de Vega', 'Mapanas', 'Mondragon', 'Palapag', 'Pambujan', 'Rosario', 'San Antonio', 'San Isidro', 'San Jose', 'San Roque', 'San Vicente', 'Silvino Lobos', 'Victoria'],
    'Samar' => ['Almagro', 'Basey', 'Calbayog', 'Calbiga', 'Catbalogan', 'Daram', 'Gandara', 'Hinabangan', 'Jiabong', 'Marabut', 'Matuguinao', 'Motiong', 'Pagsanghan', 'Paranas', 'Pinabacdao', 'San Jorge', 'San Jose de Buan', 'San Sebastian', 'Santa Margarita', 'Santa Rita', 'Santo Niño', 'Tagapul-an', 'Talalora', 'Tarangnan', 'Villareal', 'Zumarraga'],
    'Southern Leyte' => ['Anahawan', 'Bontoc', 'Hinunangan', 'Hinundayan', 'Libagon', 'Liloan', 'Limasawa', 'Maasin', 'Macrohon', 'Malitbog', 'Padre Burgos', 'Pintuyan', 'Saint Bernard', 'San Francisco', 'San Juan', 'San Ricardo', 'Silago', 'Sogod', 'Tomas Oppus'],
    'Zamboanga del Norte' => ['Baliguian', 'Dapitan', 'Dipolog', 'Godod', 'Gutalac', 'Jose Dalman', 'Kalawit', 'Katipunan', 'La Libertad', 'Labason', 'Leon B. Postigo', 'Liloy', 'Manukan', 'Mutia', 'Piñan', 'Polanco', 'President Manuel A. Roxas', 'Rizal', 'Salug', 'Sergio Osmeña Sr.', 'Siayan', 'Sibuco', 'Sibutad', 'Sindangan', 'Siocon', 'Sirawai', 'Tampilisan'],
    'Zamboanga del Sur' => ['Aurora', 'Bayog', 'Dimataling', 'Dinas', 'Dumalinao', 'Dumingag', 'Guipos', 'Josefina', 'Kumalarang', 'Labangan', 'Lakewood', 'Lapuyan', 'Mahayag', 'Margosatubig', 'Midsalip', 'Molave', 'Pagadian', 'Pitogo', 'Ramon Magsaysay', 'San Miguel', 'San Pablo', 'Sominot', 'Tabina', 'Tambulig', 'Tigbao', 'Tukuran', 'Vincenzo A. Sagun', 'Zamboanga City'],
    'Zamboanga Sibugay' => ['Alicia', 'Buug', 'Diplahan', 'Imelda', 'Ipil', 'Kabasalan', 'Mabuhay', 'Malangas', 'Naga', 'Olutanga', 'Payao', 'Roseller Lim', 'Siay', 'Talusan', 'Titay', 'Tungawan'],
    'Bukidnon' => ['Baungon', 'Cabanglasan', 'Damulog', 'Dangcagan', 'Don Carlos', 'Impasugong', 'Kadingilan', 'Kalilangan', 'Kibawe', 'Kitaotao', 'Lantapan', 'Libona', 'Malaybalay', 'Malitbog', 'Manolo Fortich', 'Maramag', 'Pangantucan', 'Quezon', 'San Fernando', 'Sumilao', 'Talakag', 'Valencia'],
    'Camiguin' => ['Catarman', 'Guinsiliban', 'Mahinog', 'Mambajao', 'Sagay'],
    'Lanao del Norte' => ['Bacolod', 'Baloi', 'Baroy', 'Iligan', 'Kapatagan', 'Kauswagan', 'Kolambugan', 'Lala', 'Linamon', 'Magsaysay', 'Maigo', 'Matungao', 'Munai', 'Nunungan', 'Pantao Ragat', 'Pantar', 'Poona Piagapo', 'Salvador', 'Sapad', 'Sultan Naga Dimaporo', 'Tagoloan', 'Tangcal', 'Tubod'],
    'Misamis Occidental' => ['Aloran', 'Baliangao', 'Bonifacio', 'Calamba', 'Clarin', 'Concepcion', 'Don Victoriano Chiongbian', 'Jimenez', 'Lopez Jaena', 'Oroquieta', 'Ozamiz', 'Panaon', 'Plaridel', 'Sapang Dalaga', 'Sinacaban', 'Tangub', 'Tudela'],
    'Misamis Oriental' => ['Alubijid', 'Balingasag', 'Balingoan', 'Binuangan', 'Cagayan de Oro', 'Claveria', 'El Salvador', 'Gingoog', 'Gitagum', 'Initao', 'Jasaan', 'Kinoguitan', 'Lagonglong', 'Laguindingan', 'Libertad', 'Lugait', 'Magsaysay', 'Manticao', 'Medina', 'Naawan', 'Opol', 'Salay', 'Sugbongcogon', 'Tagoloan', 'Talisayan', 'Villanueva'],
    'Davao de Oro' => ['Compostela', 'Laak', 'Mabini', 'Maco', 'Maragusan', 'Mawab', 'Monkayo', 'Montevista', 'Nabunturan', 'New Bataan', 'Pantukan'],
    'Davao del Norte' => ['Asuncion', 'Braulio E. Dujali', 'Carmen', 'Kapalong', 'New Corella', 'Panabo', 'Samal', 'San Isidro', 'Santo Tomas', 'Tagum', 'Talaingod'],
    'Davao del Sur' => ['Bansalan', 'Davao City', 'Digos', 'Hagonoy', 'Kiblawan', 'Magsaysay', 'Malalag', 'Matanao', 'Padada', 'Santa Cruz', 'Sulop'],
    'Davao Occidental' => ['Don Marcelino', 'Jose Abad Santos', 'Malita', 'Santa Maria', 'Sarangani'],
    'Davao Oriental' => ['Baganga', 'Banga', 'Boston', 'Caraga', 'Cateel', 'Governor Generoso', 'Lupon', 'Manay', 'Mati', 'San Isidro', 'Tarragona'],
    'Cotabato' => ['Alamada', 'Aleosan', 'Antipas', 'Arakan', 'Banisilan', 'Carmen', 'Kabacan', 'Kidapawan', 'Libungan', "M'lang", 'Magpet', 'Makilala', 'Matalam', 'Midsayap', 'Pigcawayan', 'Pikit', 'President Roxas', 'Tulunan'],
    'Sarangani' => ['Alabel', 'Glan', 'Kiamba', 'Maasim', 'Maitum', 'Malapatan', 'Malungon'],
    'South Cotabato' => ['Banga', 'General Santos', 'Koronadal', 'Lake Sebu', 'Norala', 'Polomolok', 'Santo Niño', 'Surallah', "T'boli", 'Tampakan', 'Tantangan', 'Tupi'],
    'Sultan Kudarat' => ['Bagumbayan', 'Columbio', 'Esperanza', 'Isulan', 'Kalamansig', 'Lambayong', 'Lebak', 'Lutayan', 'Palimbang', 'President Quirino', 'Senator Ninoy Aquino', 'Tacurong'],
    'Agusan del Norte' => ['Buenavista', 'Butuan', 'Cabadbaran', 'Carmen', 'Jabonga', 'Kitcharao', 'Las Nieves', 'Magallanes', 'Nasipit', 'Remedios T. Romualdez', 'Santiago', 'Tubay'],
    'Agusan del Sur' => ['Bayugan', 'Bunawan', 'Esperanza', 'La Paz', 'Loreto', 'Prosperidad', 'Rosario', 'San Francisco', 'San Luis', 'Santa Josefa', 'Sibagat', 'Talacogon', 'Trento', 'Veruela'],
    'Dinagat Islands' => ['Basilisa', 'Cagdianao', 'Dinagat', 'Libjo', 'Loreto', 'San Jose', 'Tubajon'],
    'Surigao del Norte' => ['Alegria', 'Bacuag', 'Burgos', 'Claver', 'Dapa', 'Del Carmen', 'General Luna', 'Gigaquit', 'Mainit', 'Malimono', 'Pilar', 'Placer', 'San Benito', 'San Francisco', 'San Isidro', 'Santa Monica', 'Sison', 'Socorro', 'Surigao City', 'Tagana-an', 'Tubod'],
    'Surigao del Sur' => ['Barobo', 'Bayabas', 'Bislig', 'Cagwait', 'Cantilan', 'Carmen', 'Carrascal', 'Cortes', 'Hinatuan', 'Lanuza', 'Lianga', 'Lingig', 'Madrid', 'Marihatag', 'San Agustin', 'San Miguel', 'Tagbina', 'Tago', 'Tandag'],
    'Basilan' => ['Akbar', 'Al-Barka', 'Hadji Mohammad Ajul', 'Hadji Muhtamad', 'Isabela', 'Lamitan', 'Lantawan', 'Maluso', 'Sumisip', 'Tabuan-Lasa', 'Tipo-Tipo', 'Tuburan', 'Ungkaya Pukan'],
    'Lanao del Sur' => ['Amai Manabilang', 'Bacolod-Kalawi', 'Balabagan', 'Balindong', 'Bayang', 'Binidayan', 'Buadiposo-Buntong', 'Bubong', 'Butig', 'Calanogas', 'Ditsaan-Ramain', 'Ganassi', 'Kapai', 'Kapatagan', 'Lumba-Bayabao', 'Lumbaca-Unayan', 'Lumbatan', 'Lumbayanague', 'Madalum', 'Madamba', 'Maguing', 'Malabang', 'Marantao', 'Marawi', 'Marogong', 'Masiu', 'Mulondo', 'Pagayawan', 'Piagapo', 'Poona Bayabao', 'Pualas', 'Saguiaran', 'Sultan Dumalondong', 'Tagoloan II', 'Tamparan', 'Taraka', 'Tubaran', 'Tugaya', 'Wao'],
    'Maguindanao' => ['Ampatuan', 'Barira', 'Buldon', 'Buluan', 'Datu Abdullah Sangki', 'Datu Anggal Midtimbang', 'Datu Blah T. Sinsuat', 'Datu Hoffer Ampatuan', 'Datu Montawal', 'Datu Odin Sinsuat', 'Datu Paglas', 'Datu Piang', 'Datu Salibo', 'Datu Saudi-Ampatuan', 'Datu Unsay', 'General Salipada K. Pendatun', 'Guindulungan', 'Kabuntalan', 'Mamasapano', 'Mangudadatu', 'Matanog', 'Northern Kabuntalan', 'Pagalungan', 'Paglat', 'Pandag', 'Parang', 'Rajah Buayan', 'Shariff Aguak', 'Shariff Saydona Mustapha', 'South Upi', 'Sultan Kudarat', 'Sultan Mastura', 'Sultan sa Barongis', 'Talayan', 'Upi'],
    'Sulu' => ['Hadji Panglima Tahil', 'Indanan', 'Jolo', 'Kalingalan Caluang', 'Lugus', 'Luuk', 'Maimbung', 'Old Panamao', 'Omar', 'Pandami', 'Panglima Estino', 'Pangutaran', 'Parang', 'Pata', 'Patikul', 'Siasi', 'Talipao', 'Tapul'],
    'Tawi-Tawi' => ['Bongao', 'Languyan', 'Mapun', 'Panglima Sugala', 'Sapa-Sapa', 'Sibutu', 'Simunul', 'Sitangkai', 'South Ubian', 'Tandubas', 'Turtle Islands']
];

// Sort cities alphabetically for each province
foreach ($cities as $province => $city_list) {
    sort($cities[$province]);
}

// Handle Add Customer
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'add_customer') {
        $customer_name = trim($_POST['customer_name']);
        $contact_person = trim($_POST['contact_person']);
        $email = trim($_POST['email']);
        $phone = trim($_POST['phone_number']);
        $price_level = trim($_POST['price_level'] ?? '');
        $region = trim($_POST['region']);
        $province = trim($_POST['province']);
        $city = trim($_POST['city']);
        $city_code = trim($_POST['city_code'] ?? '');
        $barangay = trim($_POST['barangay']);
        $latitude = trim($_POST['latitude']);
        $longitude = trim($_POST['longitude']);
        $store_name = trim($_POST['store_name'] ?? '');
        $status = 'active';
        $store_image = '';
        
        // Handle store image upload
        if (isset($_FILES['store_image']) && $_FILES['store_image']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = '../uploads/store_images/';
            
            // Create directory if it doesn't exist
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            
            $file_name = $_FILES['store_image']['name'];
            $file_tmp = $_FILES['store_image']['tmp_name'];
            $file_size = $_FILES['store_image']['size'];
            
            // Get file extension
            $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
            $allowed_ext = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            
            if (!in_array($file_ext, $allowed_ext)) {
                $error = 'Invalid file type. Only JPG, PNG, GIF, and WebP are allowed.';
            } elseif ($file_size > 20971520) { // 5MB limit
                $error = 'File size exceeds 20MB limit.';
            } else {
                // Generate unique filename
                $new_file_name = 'store_' . uniqid() . '.' . $file_ext;
                $upload_path = $upload_dir . $new_file_name;
                
                if (move_uploaded_file($file_tmp, $upload_path)) {
                    $store_image = $new_file_name;
                } else {
                    $error = 'Error uploading file.';
                }
            }
        }
        
        // Combine address components into single address field
        $address_parts = [];
        if (!empty($barangay)) $address_parts[] = $barangay;
        if (!empty($city)) $address_parts[] = $city;
        if (!empty($province)) $address_parts[] = $province;
        if (!empty($region)) $address_parts[] = $region;
        $address = implode(', ', $address_parts);
        
        // Auto-generate customer code
        $customer_code = generateCustomerCode($conn);

        if (empty($customer_name) || empty($price_level) || empty($region) || empty($province) || empty($city) || empty($barangay)) {
            $error = 'Please complete all required fields including Price Level, Region, Province, City/Municipality, and Barangay.';
        } elseif (empty($error)) {
            if ($branch_column_exists) {
                $sql = "INSERT INTO customers (
                            customer_name, customer_code, contact_person, email, phone_number, address,
                            region, province, city, barangay, price_level,
                            latitude, longitude, store_name, store_image, status, branch_id, city_code, created_by
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param(
                    'ssssssssssssssssis' . 'i',
                    $customer_name, $customer_code, $contact_person, $email, $phone, $address,
                    $region, $province, $city, $barangay, $price_level,
                    $latitude, $longitude, $store_name, $store_image, $status, $branch_id, $city_code, $user_id
                );
            } else {
                $sql = "INSERT INTO customers (
                            customer_name, customer_code, contact_person, email, phone_number, address,
                            region, province, city, barangay, price_level,
                            latitude, longitude, store_name, store_image, status, city_code, created_by
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param(
                    'sssssssssssssssss' . 'i',
                    $customer_name, $customer_code, $contact_person, $email, $phone, $address,
                    $region, $province, $city, $barangay, $price_level,
                    $latitude, $longitude, $store_name, $store_image, $status, $city_code, $user_id
                );
            }

            if ($stmt && $stmt->execute()) {
                $success = 'Customer added successfully! Customer Code: ' . $customer_code;
            } else {
                $error = 'Error adding customer: ' . ($stmt ? $stmt->error : $conn->error);
            }
        }
    
    
    }
    // Handle Update Customer
    if (isset($_POST['action']) && $_POST['action'] === 'update_customer') {
        $customer_id = (int)$_POST['customer_id'];

        // Rolling can only update customers that they personally registered.
        $ownership_query = "SELECT customer_id, store_image FROM customers WHERE customer_id = ? AND created_by = ?";
        $ownership_types = 'ii';
        $ownership_params = [$customer_id, $user_id];
        if ($branch_column_exists && !$view_all_branches && $branch_id > 0) {
            $ownership_query .= " AND branch_id = ?";
            $ownership_types .= 'i';
            $ownership_params[] = $branch_id;
        }
        $ownership_query .= " LIMIT 1";
        $ownership_stmt = $conn->prepare($ownership_query);
        if (!$ownership_stmt) {
            $error = 'Prepare Error: ' . $conn->error;
        } else {
            $ownership_stmt->bind_param($ownership_types, ...$ownership_params);
            $ownership_stmt->execute();
            $ownership_result = $ownership_stmt->get_result();
            $owned_customer = $ownership_result ? $ownership_result->fetch_assoc() : null;
            if (!$owned_customer) {
                $error = 'Customer not found or access denied.';
            }
        }

        $customer_name = trim($_POST['customer_name']);
        $customer_code = trim($_POST['customer_code']);
        $contact_person = trim($_POST['contact_person']);
        $email = trim($_POST['email']);
        $phone = trim($_POST['phone_number']);
        $price_level = trim($_POST['price_level'] ?? '');
        $region = trim($_POST['region']);
        $province = trim($_POST['province']);
        $city = trim($_POST['city']);
        $city_code = trim($_POST['city_code'] ?? '');
        $barangay = trim($_POST['barangay']);
        $latitude = trim($_POST['latitude']);
        $longitude = trim($_POST['longitude']);
        $status = trim($_POST['status']);
        $store_name = trim($_POST['store_name'] ?? '');
        $store_image = isset($_POST['existing_store_image']) ? trim($_POST['existing_store_image']) : '';

        // Handle store image upload
        if (isset($_FILES['store_image']) && $_FILES['store_image']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = '../uploads/store_images/';
            
            // Create directory if it doesn't exist
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            
            $file_name = $_FILES['store_image']['name'];
            $file_tmp = $_FILES['store_image']['tmp_name'];
            $file_size = $_FILES['store_image']['size'];
            
            // Get file extension
            $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
            $allowed_ext = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            
            if (!in_array($file_ext, $allowed_ext)) {
                $error = 'Invalid file type. Only JPG, PNG, GIF, and WebP are allowed.';
            } elseif ($file_size > 20971520) { // 5MB limit
                $error = 'File size exceeds 20MB limit.';
            } else {
                // Generate unique filename
                $new_file_name = 'store_' . uniqid() . '.' . $file_ext;
                $upload_path = $upload_dir . $new_file_name;
                
                if (move_uploaded_file($file_tmp, $upload_path)) {
                    // Delete old image if exists
                    if (!empty($store_image) && file_exists($upload_dir . $store_image)) {
                        unlink($upload_dir . $store_image);
                    }
                    $store_image = $new_file_name;
                } else {
                    $error = 'Error uploading file.';
                }
            }
        } else {
            // If no new file uploaded, keep the existing image from the owned customer record
            if (!empty($owned_customer) && isset($owned_customer['store_image'])) {
                $store_image = $owned_customer['store_image'] ?? '';
            }
        }

        // Combine address components into single address field
        $address_parts = [];
        if (!empty($barangay)) $address_parts[] = $barangay;
        if (!empty($city)) $address_parts[] = $city;
        if (!empty($province)) $address_parts[] = $province;
        if (!empty($region)) $address_parts[] = $region;
        $address = implode(', ', $address_parts);

        if (empty($customer_name) || empty($customer_code) || empty($price_level) || empty($region) || empty($province) || empty($city) || empty($barangay)) {
            $error = 'Please complete all required fields including Price Level, Region, Province, City/Municipality, and Barangay.';
        } elseif (empty($error)) {
            $sql = "UPDATE customers SET 
                    customer_name = ?,
                    customer_code = ?,
                    contact_person = ?,
                    email = ?,
                    phone_number = ?,
                    address = ?,
                    region = ?,
                    province = ?,
                    city = ?,
                    barangay = ?,
                    price_level = ?,
                    latitude = ?,
                    longitude = ?,
                    store_name = ?,
                    store_image = ?,
                    status = ?,
                    city_code = ?,
                    updated_at = NOW()
                    WHERE customer_id = ? AND created_by = ?";
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                $error = 'Prepare Error: ' . $conn->error;
            } else {
                $bind_result = $stmt->bind_param(
'sssssssssssssssssii',
$customer_name,$customer_code,$contact_person,$email,$phone,$address,
$region,$province,$city,$barangay,$price_level,
$latitude,$longitude,$store_name,$store_image,$status,$city_code,$customer_id,$user_id
                );
                if (!$bind_result) {
                    $error = 'Bind Error: ' . $stmt->error;
                } else if ($stmt->execute()) {
                    $success = 'Customer updated successfully!';
                    echo '<script>window.location.href = "customer.php?success=updated";</script>';
                    exit();
                } else {
                    $error = 'Error updating customer: ' . $stmt->error;
                }
            }
        }
    }
}

// Get customers - Rolling can only see customers they personally registered
$customers = [];
$own_customer_condition = "c.status = 'active' AND c.created_by = " . intval($user_id);
if ($branch_column_exists && !$view_all_branches && $branch_id > 0) {
    $own_customer_condition .= " AND c.branch_id = " . intval($branch_id);
}

$query = "SELECT c.*, COUNT(so.so_id) as total_orders 
          FROM customers c 
          LEFT JOIN sales_orders so ON c.customer_id = so.customer_id AND so.created_by = " . intval($user_id) . "
          WHERE $own_customer_condition
          GROUP BY c.customer_id 
          ORDER BY c.created_at DESC";

$result = $conn->query($query);
if ($result) {
    $customers = $result->fetch_all(MYSQLI_ASSOC);
}

// Get stats - only customers registered by this Rolling account
$total_customers = 0;
$active_customers = 0;
$total_orders = 0;

$customer_stats_condition = "created_by = " . intval($user_id);
if ($branch_column_exists && !$view_all_branches && $branch_id > 0) {
    $customer_stats_condition .= " AND branch_id = " . intval($branch_id);
}

$stats_query = "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active
                FROM customers
                WHERE $customer_stats_condition";

$stats_result = $conn->query($stats_query);
if ($stats_result) {
    $stats = $stats_result->fetch_assoc();
    $total_customers = $stats['total'] ?? 0;
    $active_customers = $stats['active'] ?? 0;
}

// ========== Get total orders count for this Rolling account's own customers/orders ==========
$orders_query = "SELECT COUNT(so.so_id) as total_orders 
                 FROM sales_orders so 
                 INNER JOIN customers c ON so.customer_id = c.customer_id 
                 WHERE so.created_by = " . intval($user_id) . "
                 AND c.created_by = " . intval($user_id);
if ($branch_column_exists && !$view_all_branches && $branch_id > 0) {
    $orders_query .= " AND c.branch_id = " . intval($branch_id);
}

$orders_result = $conn->query($orders_query);
if ($orders_result) {
    $orders_stats = $orders_result->fetch_assoc();
    $total_orders = $orders_stats['total_orders'] ?? 0;
}

// Generate a preview code for the modal
$preview_code = generateCustomerCode($conn);

if (!isset($error)) $error = '';
if (!isset($success)) $success = '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Customer - Rolling Account</title>
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
    <!-- Leaflet CSS for Maps -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <!-- SweetAlert2 -->
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
        <!-- Session Checker -->
    <script src="../js/session-checker.js"></script>
    <style>
       /* ===== STAT CARDS STYLING ===== */

/* Base stat card styling */
.stat-card {
    background: transparent !important;
    border: none !important;
    box-shadow: 0 4px 10px rgba(0, 0, 0, 0.08) !important;
    min-height: auto !important;
    height: auto !important;
    padding: 0.8rem !important;
    transition: transform 0.2s ease, box-shadow 0.2s ease !important;
    cursor: default !important;
    border-radius: 12px !important;
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
        font-size: 0.9rem !important;
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

/* Para sa 2-line text sa label */
.stat-card .stat-label {
    display: -webkit-box !important;
    -webkit-line-clamp: 2 !important;
    -webkit-box-orient: vertical !important;
    overflow: hidden !important;
    text-overflow: ellipsis !important;
}
        
        /* Map Styles */
        #locationMap, #editLocationMap {
            height: 300px;
            border-radius: 8px;
            margin-bottom: 15px;
            border: 1px solid #dee2e6;
        }
        
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
        
        .btn-edit {
            background-color: #f3e5f5;
            color: #7b1fa2;
            border-color: #e1bee7;
        }
        
        .btn-edit:hover {
            background-color: #e1bee7;
            transform: translateY(-2px);
        }
        
        .btn-location {
            background-color: #e8f5e9;
            color: #388e3c;
            border-color: #c8e6c9;
        }
        
        .btn-location:hover {
            background-color: #c8e6c9;
            transform: translateY(-2px);
        }
        
        .btn-cart {
            background-color: #fff3e0;
            color: #ed6c02;
            border-color: #ffe0b2;
        }
        
        .btn-cart:hover {
            background-color: #ffe0b2;
            transform: translateY(-2px);
        }
        
        /* Map Modal */
        .map-modal .modal-dialog {
            max-width: 800px;
        }
        
        #viewLocationMap {
            height: 400px;
            border-radius: 8px;
            margin-bottom: 15px;
        }
        
        /* Location Coordinates Input */
        .coordinates-container {
            display: flex;
            gap: 10px;
            margin-bottom: 10px;
        }
        
        .coordinates-container .form-group {
            flex: 1;
        }
        
        .location-buttons {
            display: flex;
            gap: 10px;
            margin-bottom: 15px;
        }
        
        .location-info {
            background-color: #f8f9fa;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 15px;
            font-size: 0.9em;
        }
        
        /* Auto-generated code styling */
        .code-preview {
            background-color: #e9ecef;
            border: 1px solid #ced4da;
            border-radius: 5px;
            padding: 10px 15px;
            font-family: monospace;
            font-size: 1.1em;
            color: #0d6efd;
            font-weight: bold;
        }
        
        .code-label {
            font-size: 0.85em;
            color: #6c757d;
            margin-bottom: 5px;
        }
        
        .refresh-code {
            cursor: pointer;
            color: #0d6efd;
            margin-left: 10px;
        }
        
        .refresh-code:hover {
            color: #0a58ca;
        }
        
        /* Branch badge */
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

        /* Address preview styling */
        .address-preview {
            background-color: #f8f9fa;
            border-left: 3px solid #0d6efd;
            padding: 10px 15px;
            margin-top: 10px;
            border-radius: 0 5px 5px 0;
            font-size: 0.95em;
        }
        
        .form-section-title {
            font-size: 1rem;
            font-weight: 600;
            color: #495057;
            margin-bottom: 15px;
            padding-bottom: 8px;
            border-bottom: 2px solid #e9ecef;
        }

        /* Loading indicator */
        .loading-spinner {
            display: inline-block;
            width: 1rem;
            height: 1rem;
            border: 2px solid #f3f3f3;
            border-top: 2px solid #3498db;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* Manual entry toggle button */
        .manual-toggle-btn {
            font-size: 0.8rem;
            margin-top: 5px;
            cursor: pointer;
            color: #0d6efd;
        }
        
        .manual-toggle-btn:hover {
            text-decoration: underline;
        }

        /* Clickable image styles */
        .clickable-image {
            cursor: pointer;
            transition: transform 0.2s, opacity 0.2s;
            border-radius: 8px;
        }
        
        .clickable-image:hover {
            transform: scale(1.05);
            opacity: 0.9;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
        }
        
        /* Image Modal - Responsive at X button sa loob ng picture */
        .image-modal .modal-dialog {
            max-width: 95%;
            width: auto;
            margin: 1rem auto;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: calc(100% - 2rem);
        }
        
        .image-modal .modal-content {
            background: transparent;
            border: none;
            box-shadow: none;
            position: relative;
            display: inline-block;
            width: auto;
        }
        
        .image-modal .modal-body {
            text-align: center;
            padding: 0;
            background: transparent;
            position: relative;
            display: inline-block;
        }
        
        /* Image container for proper positioning */
        .image-container {
            position: relative;
            display: inline-block;
            max-width: 90vw;
            max-height: 85vh;
        }
        
        .full-size-image {
            max-width: 90vw;
            max-height: 85vh;
            width: auto;
            height: auto;
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.3);
            background: #fff;
            object-fit: contain;
            display: block;
        }
        
        /* X button sa loob ng picture - upper right corner */
        .image-close-btn {
            position: absolute;
            top: 10px;
            right: 10px;
            background: rgba(0,0,0,0.6);
            color: white;
            border: none;
            border-radius: 50%;
            width: 36px;
            height: 36px;
            font-size: 20px;
            cursor: pointer;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 10;
            backdrop-filter: blur(4px);
        }
        
        .image-close-btn:hover {
            background: rgba(0,0,0,0.9);
            transform: scale(1.05);
        }
        
        /* Mobile adjustments for image modal */
        @media (max-width: 768px) {
            .full-size-image {
                max-width: 95vw;
                max-height: 80vh;
                border-radius: 8px;
            }
            
            .image-close-btn {
                top: 8px;
                right: 8px;
                width: 32px;
                height: 32px;
                font-size: 18px;
            }
            
            .image-modal .modal-dialog {
                margin: 0.5rem auto;
            }
        }
        
        @media (max-width: 576px) {
            .full-size-image {
                max-width: 98vw;
                max-height: 75vh;
            }
            
            .image-close-btn {
                top: 6px;
                right: 6px;
                width: 30px;
                height: 30px;
                font-size: 16px;
            }
        }
        
        /* Store image thumbnail styles */
        .store-image-thumb {
            max-width: 150px;
            max-height: 120px;
            border-radius: 8px;
            cursor: pointer;
            transition: transform 0.2s, opacity 0.2s;
            object-fit: cover;
        }
        
        .store-image-thumb:hover {
            transform: scale(1.05);
            opacity: 0.9;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        
        @media (max-width: 768px) {
            .store-image-thumb {
                max-width: 120px;
                max-height: 100px;
            }
        }

        /* Customer Details Card Styles */
        .customer-details-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            margin-bottom: 20px;
            overflow: hidden;
        }
        
        /* Store Image Section - NASA TAAS TULAD NG PICTURE */
        .store-image-section {
            background: #f8f9fa;
            padding: 20px;
            text-align: center;
            border-bottom: 1px solid #e9ecef;
        }
        
        .store-image {
            max-width: 100%;
            max-height: 250px;
            border-radius: 8px;
            cursor: pointer;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            transition: transform 0.2s;
        }
        
        .store-image:hover {
            transform: scale(1.02);
        }
        
        /* Customer Info Grid - DALAWANG COLUMN */
        .customer-info-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 16px;
            padding: 20px;
        }
        
        .customer-info-item {
            display: flex;
            flex-direction: column;
        }
        
        .customer-info-label {
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #6c757d;
            margin-bottom: 4px;
        }
        
        .customer-info-value {
            font-size: 0.95rem;
            font-weight: 500;
            color: #2c3e50;
            word-break: break-word;
        }
        
        /* View Map button sa loob ng location */
        .btn-view-map {
            background-color: #e8f5e9;
            color: #388e3c;
            border: 1px solid #c8e6c9;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.75rem;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            margin-top: 5px;
            cursor: pointer;
            transition: all 0.2s;
            width: fit-content;
        }
        
        .btn-view-map:hover {
            background-color: #c8e6c9;
            transform: translateY(-1px);
        }
        
        .btn-view-map i {
            font-size: 0.8rem;
        }
        
        /* Orders Section */
        .orders-section {
            margin-top: 0;
            border-top: 1px solid #e9ecef;
            padding: 20px;
        }
        
        .orders-section h6 {
            font-weight: 600;
            margin-bottom: 15px;
            color: #2c3e50;
        }
        
        .orders-table {
            font-size: 0.85rem;
        }
        
        .orders-table th {
            background-color: #f8f9fa;
            font-weight: 600;
        }
        
        .badge-order-status {
            padding: 4px 8px;
            font-size: 0.7rem;
        }
        
        .no-orders {
            text-align: center;
            padding: 30px;
            color: #6c757d;
            background: #f8f9fa;
            border-radius: 8px;
        }
        
        @media (max-width: 576px) {
            .customer-info-grid {
                grid-template-columns: 1fr;
                gap: 12px;
                padding: 15px;
            }
            
            .store-image {
                max-height: 180px;
            }
            
            .orders-table {
                font-size: 0.7rem;
            }
            
            .orders-table th, .orders-table td {
                padding: 8px 4px;
            }
        }
        /* ===== CUSTOM ADD CUSTOMER BUTTON ===== */

/* Override btn-primary for Add Customer button only */
.btn-primary,
button[data-bs-target="#addCustomerModal"],
.btn-add-customer {
    background: linear-gradient(135deg, #059669, #047857) !important;
    border: none !important;
    border-radius: 8px !important;
    padding: 0.625rem 1rem !important;
    font-weight: 500 !important;
    font-size: 0.875rem !important;
    transition: all 0.3s ease !important;
    box-shadow: 0 2px 4px rgba(5, 150, 105, 0.2) !important;
}

/* Hover effect */
.btn-primary:hover,
button[data-bs-target="#addCustomerModal"]:hover,
.btn-add-customer:hover {
    background: linear-gradient(135deg, #047857, #065f46) !important;
    transform: translateY(-2px) !important;
    box-shadow: 0 6px 16px rgba(5, 150, 105, 0.3) !important;
}

/* Active/Click effect */
.btn-primary:active,
button[data-bs-target="#addCustomerModal"]:active,
.btn-add-customer:active {
    transform: translateY(0) !important;
    box-shadow: 0 2px 8px rgba(5, 150, 105, 0.2) !important;
    background: linear-gradient(135deg, #065f46, #064e3b) !important;
}

/* Focus effect */
.btn-primary:focus,
button[data-bs-target="#addCustomerModal"]:focus,
.btn-add-customer:focus {
    box-shadow: 0 0 0 3px rgba(5, 150, 105, 0.3) !important;
    outline: none !important;
}

/* Disabled state */
.btn-primary:disabled,
button[data-bs-target="#addCustomerModal"]:disabled {
    background: linear-gradient(135deg, #9ca3af, #6b7280) !important;
    opacity: 0.6 !important;
    cursor: not-allowed !important;
    transform: none !important;
}

/* Button icon styling */
.btn-primary i,
button[data-bs-target="#addCustomerModal"] i,
.btn-add-customer i {
    margin-right: 0.5rem;
    font-size: 1rem;
    vertical-align: middle;
}

/* ===== ADD BUTTON WRAPPER - OUTSIDE FILTER ===== */
.add-button-wrapper {
    margin-bottom: 1.25rem;
    text-align: right;
}

.add-button-wrapper .btn-primary {
    background: linear-gradient(135deg, #059669, #047857) !important;
    border-radius: 10px !important;
    padding: 0.7rem 1.5rem !important;
    font-weight: 600 !important;
    font-size: 0.95rem !important;
    box-shadow: 0 4px 8px rgba(5, 150, 105, 0.25) !important;
}

.add-button-wrapper .btn-primary:hover {
    transform: translateY(-2px) !important;
    box-shadow: 0 8px 18px rgba(5, 150, 105, 0.35) !important;
}

@media (max-width: 768px) {
    .add-button-wrapper {
        margin-bottom: 1rem;
        text-align: center;
    }
    
    .add-button-wrapper .btn-primary {
        width: 100%;
        padding: 0.6rem 1rem !important;
    }
}

/* ===== SIMPLIFIED CUSTOMER CARDS ===== */

.customer-cards-container {
    display: grid;
    grid-template-columns: 1fr;
    gap: 0.75rem;
    padding: 0.5rem 0;
}

/* Desktop: 3 columns */
@media (min-width: 992px) {
    .customer-cards-container {
        grid-template-columns: repeat(3, 1fr);
        gap: 1rem;
    }
}

/* Tablet: 2 columns */
@media (min-width: 768px) and (max-width: 991px) {
    .customer-cards-container {
        grid-template-columns: repeat(2, 1fr);
        gap: 0.875rem;
    }
}

/* Card styling */
.customer-card {
    background: white;
    border-radius: 12px;
    padding: 0.875rem 1rem;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
    transition: all 0.2s ease;
    border: 1px solid #e5e7eb;
    position: relative;
    display: flex;
    flex-direction: column;
}

.customer-card:hover {
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
    transform: translateY(-1px);
}

/* Top row - code and status */
.card-top {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 0.5rem;
}

.customer-code {
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
    padding: 0.2rem 0.5rem;
    border-radius: 20px;
    font-size: 0.65rem;
    font-weight: 600;
}

.status-active {
    background: #d1fae5;
    color: #065f46;
}

.status-inactive {
    background: #fee2e2;
    color: #991b1b;
}

.status-pending {
    background: #fed7aa;
    color: #92400e;
}

/* Customer name */
.customer-name {
    font-size: 1rem;
    font-weight: 700;
    color: #1f2937;
    margin-bottom: 0.25rem;
}

/* Phone number */
.customer-phone {
    font-size: 0.8rem;
    color: #4b5563;
    margin-bottom: 0.25rem;
}

/* Address */
.customer-address {
    font-size: 0.7rem;
    color: #9ca3af;
    margin-bottom: 0.5rem;
    line-height: 1.3;
}

/* Bottom row */
.card-bottom {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-top: 0.5rem;
    padding-top: 0.5rem;
    border-top: 1px solid #f0f0f0;
}

.orders-count {
    font-size: 0.7rem;
    color: #059669;
    font-weight: 500;
}

.orders-count i {
    font-size: 0.65rem;
}

.btn-view {
    background: none;
    border: none;
    color: #059669;
    font-size: 0.7rem;
    font-weight: 500;
    cursor: pointer;
    padding: 0.25rem 0.5rem;
    border-radius: 6px;
    transition: all 0.2s ease;
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
}

.btn-view:hover {
    background: #ecfdf5;
    color: #047857;
}

.btn-view i {
    font-size: 0.65rem;
}
/* Action button - subtle background, walang border */
.btn-action.btn-view {
    background: #f0fdf4; /* Very light green */
    border: none;
    color: #059669;
    font-size: 0.7rem;
    font-weight: 500;
    cursor: pointer;
    padding: 0.2rem 0.6rem;
    border-radius: 6px;
    transition: all 0.2s ease;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.2rem;
    white-space: nowrap;
    min-width: fit-content;
    line-height: 1.2;
    height: auto;
}

.btn-action.btn-view:hover {
    background: #dcfce7; /* Medyo dark sa hover */
    color: #047857;
}

@media (max-width: 768px) {
    .btn-action.btn-view {
        background: #f0fdf4;
        padding: 0.15rem 0.5rem;
        font-size: 0.65rem;
    }
}

@media (max-width: 480px) {
    .btn-action.btn-view {
        background: #f0fdf4;
        padding: 0.12rem 0.4rem;
        font-size: 0.6rem;
    }
}

/* Action buttons */
.card-actions {
    position: absolute;
    right: 1rem;
    top: 50%;
    transform: translateY(-50%);
    display: flex;
    gap: 0.375rem;
    opacity: 0;
    visibility: hidden;
    transition: all 0.2s ease;
    background: white;
    padding: 0.25rem;
    border-radius: 8px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
}

.customer-card:hover .card-actions {
    opacity: 1;
    visibility: visible;
}

.icon-btn {
    width: 28px;
    height: 28px;
    border-radius: 6px;
    border: none;
    cursor: pointer;
    transition: all 0.2s ease;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}

.edit-btn {
    background: #f3e5f5;
    color: #7b1fa2;
}

.edit-btn:hover {
    background: #e1bee7;
    transform: scale(1.05);
}

.location-btn {
    background: #e8f5e9;
    color: #388e3c;
}

.location-btn:hover {
    background: #c8e6c9;
    transform: scale(1.05);
}

.call-btn {
    background: #e3f2fd;
    color: #1976d2;
}

.call-btn:hover {
    background: #bbdefb;
    transform: scale(1.05);
}

.cart-btn {
    background: #fff3e0;
    color: #ed6c02;
}

.cart-btn:hover {
    background: #ffe0b2;
    transform: scale(1.05);
}

.icon-btn i {
    font-size: 0.8rem;
}

/* Mobile adjustments */
@media (max-width: 576px) {
    .customer-card {
        padding: 0.75rem;
    }
    
    .customer-name {
        font-size: 0.9rem;
    }
    
    .customer-phone {
        font-size: 0.75rem;
    }
    
    .customer-address {
        font-size: 0.65rem;
    }
    
    /* On mobile, show action buttons at the bottom */
    .card-actions {
        position: static;
        transform: none;
        opacity: 1;
        visibility: visible;
        justify-content: flex-end;
        margin-top: 0.5rem;
        padding-top: 0.5rem;
        border-top: 1px solid #f0f0f0;
        background: transparent;
        box-shadow: none;
    }
    
    .card-bottom {
        margin-bottom: 0.5rem;
    }
}

/* Empty state */
.empty-state {
    text-align: center;
    padding: 2rem;
    color: #6b7280;
    grid-column: 1 / -1;
}

.empty-state i {
    font-size: 2.5rem;
    margin-bottom: 0.75rem;
    display: block;
}

.empty-state p {
    font-size: 0.875rem;
}
/* ===== ORDER DETAILS MODAL STYLES (from sales_order.php) ===== */
.order-details-card {
    background: white;
    border-radius: 12px;
    overflow: hidden;
}

.order-header-section {
    background: linear-gradient(135deg, #f8f9fa, #e9ecef);
    padding: 16px 20px;
    border-bottom: 2px solid #059669;
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 8px;
}

.order-badge {
    background: #059669;
    color: #059669;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 500;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    width: fit-content;
}

.order-number {
    font-size: 1.25rem;
    font-weight: 700;
    color: #1f2937;
    margin: 0;
}

.order-info-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 12px;
    padding: 16px 20px;
    background: white;
    border-bottom: 1px solid #e5e7eb;
}

.order-info-item {
    display: flex;
    flex-direction: column;
}

.order-info-label {
    font-size: 0.7rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #6c757d;
    margin-bottom: 4px;
    display: flex;
    align-items: center;
    gap: 4px;
}

.order-info-value {
    font-size: 0.9rem;
    font-weight: 500;
    color: #2c3e50;
}

.order-info-value .badge {
    font-size: 0.75rem;
    padding: 4px 10px;
}

.customer-section {
    padding: 16px 20px;
    background: #f8f9fa;
    border-bottom: 1px solid #e5e7eb;
}

.customer-section h6, .items-section h6 {
    font-size: 0.85rem;
    font-weight: 600;
    color: #1f2937;
    margin-bottom: 12px;
    display: flex;
    align-items: center;
    gap: 6px;
}

.customer-info-card {
    background: white;
    border-radius: 8px;
    padding: 12px;
}

.customer-detail-row {
    display: flex;
    flex-wrap: wrap;
    margin-bottom: 8px;
    font-size: 0.8rem;
}

.customer-detail-label {
    width: 120px;
    font-weight: 600;
    color: #6c757d;
}

.customer-detail-value {
    flex: 1;
    color: #2c3e50;
}

.items-section {
    padding: 16px 20px;
}

.items-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.8rem;
}

.items-table th,
.items-table td {
    padding: 10px 8px;
    border-bottom: 1px solid #e5e7eb;
}

.items-table th {
    background: #f8f9fa;
    font-weight: 600;
    color: #1f2937;
}

.total-row td {
    border-top: 2px solid #e5e7eb;
    font-weight: 700;
    background: #e5e7eb;
}

.driver-badge-modal {
    background: #e3f2fd;
    color: #0d6efd;
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 500;
    display: inline-flex;
    align-items: center;
    gap: 4px;
}

@media (max-width: 576px) {
    .order-info-grid {
        grid-template-columns: 1fr;
        gap: 10px;
        padding: 12px 16px;
    }
    
    .customer-detail-row {
        flex-direction: column;
        margin-bottom: 10px;
    }
    
    .customer-detail-label {
        width: 100%;
        margin-bottom: 2px;
    }
    
    .order-header-section {
        flex-direction: column;
        text-align: center;
        gap: 10px;
    }
    
    .items-table th,
    .items-table td {
        padding: 6px 4px;
        font-size: 0.7rem;
    }
    
    .customer-section,
    .items-section {
        padding: 12px 16px;
    }
}
    
/* ===== Rolling mobile More navigation + responsive fixes ===== */
.mobile-nav{position:fixed;bottom:0;left:0;right:0;background:#fff;box-shadow:0 -2px 10px rgba(0,0,0,.1);padding:8px 10px;z-index:1000;display:none;}
.mobile-nav .nav{display:flex;align-items:center;justify-content:space-around;margin:0;padding:0;list-style:none;}
.mobile-nav .nav-item{flex:1;text-align:center;min-width:0;}
.mobile-nav .nav-link{display:flex;flex-direction:column;align-items:center;justify-content:center;padding:6px 3px;color:#6c757d;text-decoration:none;font-size:.68rem;line-height:1.1;position:relative;gap:3px;}
.mobile-nav .nav-link i{font-size:1.22rem;margin-bottom:2px;}
.mobile-nav .nav-link.active{color:#047857;font-weight:700;}
.mobile-nav .nav-link.active::after{content:'';position:absolute;bottom:-2px;left:50%;transform:translateX(-50%);width:28px;height:2px;background:#047857;border-radius:2px;}
.dropdown-more{position:relative;}
.more-dropdown{position:absolute;bottom:100%;right:0;background:#fff;border-radius:14px;box-shadow:0 4px 20px rgba(0,0,0,.16);min-width:220px;display:none;margin-bottom:10px;z-index:1100;overflow:hidden;border:1px solid #eef2f7;}
.more-dropdown.show{display:block;}
.more-dropdown .dropdown-item{display:flex;align-items:center;gap:12px;padding:12px 15px;color:#333;text-decoration:none;border-bottom:1px solid #f0f0f0;font-size:.86rem;background:#fff;}
.more-dropdown .dropdown-item:last-child{border-bottom:none;}
.more-dropdown .dropdown-item:hover{background:#f8fafc;color:#047857;}
.more-dropdown .logout-item{color:#dc2626;}
@media(max-width:992px){
    body{padding-bottom:78px;}
    .main-content{margin-left:0!important;padding:14px!important;}
    .mobile-nav{display:block;}
    .navbar-top{align-items:flex-start;gap:.75rem;padding:12px 14px;border-radius:14px;}
    .page-title h2{font-size:1.25rem;margin-bottom:2px;}
    .page-title p{font-size:.82rem;margin:0;}
    .mobile-toggle-btn{display:block!important;}
    .form-card,.card-box{border-radius:14px;}
    .table-responsive{overflow-x:auto;-webkit-overflow-scrolling:touch;}
    .modal-dialog{margin:.75rem;}
}
@media(max-width:576px){
    .mobile-nav .nav-link span{font-size:.62rem;}
    .mobile-nav .nav-link i{font-size:1.12rem;}
    .more-dropdown{right:-6px;min-width:210px;}
    .add-button-wrapper .btn,.btn{white-space:normal;}
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
                    <span class="nav-text">Rolling Account</span>
                </h3>
            </div>

            <div class="sidebar-content">
                <div class="sidebar-menu">
                    <ul class="nav flex-column">
                        <li class="nav-item">
                            <a class="nav-link" href="current_inventory.php">
                                <i class="bi bi-box-seam"></i>
                                <span class="nav-text">Current Inventory</span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link active" href="customer_orderproduct.php">
                                <i class="bi bi-people"></i>
                                <span class="nav-text">Orders</span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="collections.php">
                                <i class="bi bi-cash-stack"></i>
                                <span class="nav-text">Collections</span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="sales_order.php">
                                <i class="bi bi-cart"></i>
                                <span class="nav-text">Sales Orders</span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="purchase_order.php">
                                <i class="bi bi-truck"></i>
                                <span class="nav-text">Receive Inventory</span>
                            </a>
                        </li>
                        <li class="nav-item">
                    <a class="nav-link" href="expenses.php">
                        <i class="bi bi-receipt-cutoff"></i>
                        <span class="nav-text">Expenses</span>
                    </a>
                </li>
                <li class="nav-item">
                        <a class="nav-link" href="reports.php">
                            <i class="bi bi-file-earmark-bar-graph"></i>
                            <span class="nav-text">Reports</span>
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
                        <span class="user-role-sidebar"><?php echo ucfirst(str_replace('_', ' ', $user_role)); ?></span>
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
                    <h2>Customer Information</h2>
                    <p>Manage customer database and details</p>
                </div>
            </div>

            <!-- Branch Info Alert (if no branch_id column) -->
            <?php if (!$branch_column_exists): ?>
                <div class="alert alert-info alert-dismissible fade show" role="alert">
                    <i class="bi bi-info-circle"></i> 
                    <strong>Branch filtering not yet set up.</strong> Please run this SQL in phpMyAdmin to enable branch-specific customer data:
                    <br><br>
                    <code>ALTER TABLE customers ADD COLUMN branch_id INT NULL;</code>
                    <br>
                    <code>ALTER TABLE customers ADD FOREIGN KEY (branch_id) REFERENCES branches(branch_id);</code>
                    <br><br>
                    <button type="button" class="btn btn-sm btn-primary" onclick="copySQL()">
                        <i class="bi bi-files"></i> Copy SQL
                    </button>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <script>
                    function copySQL() {
                        const sql = "ALTER TABLE customers ADD COLUMN branch_id INT NULL;\nALTER TABLE customers ADD FOREIGN KEY (branch_id) REFERENCES branches(branch_id);";
                        navigator.clipboard.writeText(sql).then(() => {
                            alert('SQL copied to clipboard!');
                        });
                    }
                </script>
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

            <!-- Customer Stats - gaya ng style sa ibaba -->
<div class="row stat-card-row g-1 g-sm-2 mb-4">
    <!-- Total Customers -->
    <div class="col">
        <div class="stat-card total">
            <i class="bi bi-people stat-icon"></i>
            <div class="stat-content">
                <div class="stat-value"><?php echo $total_customers; ?></div>
                <div class="stat-label">Total Customers</div>
                <?php if ($branch_column_exists && !$view_all_branches): ?>
                    <small class="d-block"><?php echo htmlspecialchars($branch_name ?? 'Your Branch'); ?></small>
                <?php else: ?>
                    <small class="d-block">All branches</small>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Active Customers -->
    <div class="col">
        <div class="stat-card complete">
            <i class="bi bi-check-circle stat-icon"></i>
            <div class="stat-content">
                <div class="stat-value"><?php echo $active_customers; ?></div>
                <div class="stat-label">Active Customers</div>
            </div>
        </div>
    </div>

    <!-- Total Orders (branch-filtered) -->
    <div class="col">
        <div class="stat-card sales">
            <i class="bi bi-bag-check stat-icon"></i>
            <div class="stat-content">
                <div class="stat-value"><?php echo $total_orders; ?></div>
                <div class="stat-label">Total Orders</div>
                <?php if ($branch_column_exists && !$view_all_branches): ?>
                    <small class="d-block"><?php echo htmlspecialchars($branch_name ?? 'Your Branch'); ?></small>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

           <!-- Search and Filter - WITHOUT ADD BUTTON -->
<div class="form-card mb-4">
    <div class="filter-header">
        <h5>
            <i class="bi bi-search"></i> Search & Filter Customers
        </h5>
        <button class="filter-toggle-btn" type="button" id="filterToggleBtn" aria-expanded="false">
            <i class="bi bi-chevron-down" id="filterIcon"></i>
        </button>
    </div>
    
    <div class="filter-content collapsed" id="filterContent">
        <div class="row g-3 align-items-end">
            <!-- Search Input -->
            <div class="col-12 col-sm-12 col-md-8">
                <label class="form-label">
                    <i class="bi bi-search"></i> Search Customer
                </label>
                <div class="input-group">
                    <input type="text" class="form-control" id="searchInput" placeholder="Name, email, or phone...">
                </div>
            </div>
            
            <!-- Status Filter -->
            <div class="col-12 col-sm-6 col-md-4">
                <label class="form-label">
                    <i class="bi bi-funnel"></i> Status
                </label>
                <select class="form-select" id="statusFilter">
                    <option value="">All Status</option>
                    <option value="Active">Active</option>
                    <option value="Inactive">Inactive</option>
                    <option value="Pending">Pending</option>
                </select>
            </div>
        </div>
    </div>
</div>

            <!-- ADD NEW CUSTOMER BUTTON - MOVED OUTSIDE FILTER, ABOVE CARDS -->
            <div class="add-button-wrapper">
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addCustomerModal">
                    <i class="bi bi-plus-lg"></i> Add New Customer
                </button>
            </div>

            <!-- Customer Cards - Simplified Design -->
<div class="customer-cards-container" id="customerCardsContainer">
    <?php if (count($customers) > 0): ?>
        <?php foreach ($customers as $customer): ?>
            <div class="customer-card" data-customer-id="<?php echo $customer['customer_id']; ?>">
                <!-- Top row: Code and Status -->
                <div class="card-top">
                    <span class="customer-code"><?php echo htmlspecialchars($customer['customer_code']); ?></span>
                    <?php
                    $status_badge = [
                        'active' => 'status-active',
                        'inactive' => 'status-inactive',
                        'pending' => 'status-pending'
                    ];
                    $status_class = $status_badge[$customer['status']] ?? 'status-active';
                    ?>
                    <span class="status-badge <?php echo $status_class; ?>">
                        <?php echo ucfirst($customer['status']); ?>
                    </span>
                </div>
                
                <!-- Customer Name / Company -->
                <div class="customer-name">
                    <?php echo htmlspecialchars($customer['customer_name']); ?>
                </div>
                
                <!-- Phone Number -->
                <div class="customer-phone">
                    <?php echo htmlspecialchars($customer['phone_number'] ?: 'No phone number'); ?>
                </div>
                
                <!-- Address (shortened) -->
                <div class="customer-address">
                    <?php 
                    // Use the combined address field
                    $display_address = $customer['address'] ?? '';
                    // Shorten address if too long
                    $short_address = strlen($display_address) > 40 ? substr($display_address, 0, 37) . '...' : $display_address;
                    echo htmlspecialchars($short_address ?: 'No address');
                    ?>
                </div>
                
                <!-- Bottom row: Orders count and View button -->
                <div class="card-bottom">
                    <span class="orders-count">
                        <i class="bi bi-bag"></i> <?php echo $customer['total_orders'] ?? 0; ?> orders
                    </span>
                    <button class="btn-view" onclick="viewCustomerDetails(<?php echo $customer['customer_id']; ?>)">
                        tap to view <i class="bi bi-chevron-right"></i>
                    </button>
                </div>
                
                <!-- Action buttons -->
                <div class="card-actions">
                    <button class="icon-btn edit-btn" onclick="editCustomer(<?php echo $customer['customer_id']; ?>)" title="Edit">
                        <i class="bi bi-pencil"></i>
                    </button>
                    <?php if (!empty($customer['phone_number'])): ?>
                        <button class="icon-btn call-btn" onclick="callCustomer('<?php echo htmlspecialchars($customer['phone_number']); ?>')" title="Call">
                            <i class="bi bi-telephone"></i>
                        </button>
                    <?php endif; ?>
                    <?php if (!empty($customer['latitude']) && !empty($customer['longitude'])): ?>
                        <button class="icon-btn location-btn" onclick="viewLocationOnMap(<?php echo $customer['customer_id']; ?>, '<?php echo htmlspecialchars($customer['customer_name']); ?>', <?php echo $customer['latitude']; ?>, <?php echo $customer['longitude']; ?>)" title="Location">
                            <i class="bi bi-geo-alt"></i>
                        </button>
                    <?php endif; ?>
                    <button class="icon-btn cart-btn" onclick="orderProduct(<?php echo $customer['customer_id']; ?>)" title="Order">
                        <i class="bi bi-cart"></i>
                    </button>
                </div>
            </div>
        <?php endforeach; ?>
    <?php else: ?>
        <div class="empty-state">
            <i class="bi bi-people"></i>
            <p>No customers found</p>
        </div>
    <?php endif; ?>
</div>

    <!-- Add Customer Modal - MODERN DESIGN -->
<div class="modal fade" id="addCustomerModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-person-plus-fill"></i> Add New Customer
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="addCustomerForm" enctype="multipart/form-data">
                <input type="hidden" name="action" value="add_customer">
                <div class="modal-body">
                    <!-- Auto-generated Customer Code Display -->
                    <div class="row mb-4">
                        <div class="col-12">
                            <div class="code-label">
                                <i class="bi bi-upc-scan"></i> Customer Code (Auto-generated)
                            </div>
                            <div class="code-preview" id="customerCodePreview">
                                <?php echo $preview_code; ?>
                                <i class="bi bi-arrow-repeat refresh-code" onclick="refreshCustomerCode()" title="Generate new code"></i>
                            </div>
                            <input type="hidden" name="customer_code" id="customerCodeInput" value="<?php echo $preview_code; ?>">
                            <small class="text-muted"><i class="bi bi-info-circle"></i> This code will be automatically generated</small>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">
                                <i class="bi bi-person-badge"></i> Customer Name <span class="text-danger">*</span>
                            </label>
                            <input type="text" class="form-control" name="customer_name" required placeholder="Enter full name">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">
                                <i class="bi bi-person-circle"></i> Contact Person
                            </label>
                            <input type="text" class="form-control" name="contact_person" placeholder="Enter contact person name">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">
                                <i class="bi bi-envelope"></i> Email <span class="text-danger"></span>
                            </label>
                            <input type="email" class="form-control" name="email" placeholder="customer@example.com or kahit wala po na ilagay">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">
                                <i class="bi bi-telephone"></i> Phone
                            </label>
                            <input type="tel" class="form-control" name="phone_number" placeholder="+63 XXX XXX XXXX">
                        </div>
                    </div>

                    <!-- Store Information Section -->
                    <h6 class="form-section-title">
                        <i class="bi bi-shop"></i> Store Information
                    </h6>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">
                                <i class="bi bi-building"></i> Store Name
                            </label>
                            <input type="text" class="form-control" name="store_name" placeholder="Store or business name">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">
                                <i class="bi bi-image"></i> Store Footage/Picture
                            </label>
                            <input type="file" class="form-control" name="store_image" accept="image/*">
                            <small class="text-muted"><i class="bi bi-info-circle"></i> JPG, PNG, GIF or WebP (Max 5MB)</small>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">
                                <i class="bi bi-tag"></i> Price Level <span class="text-danger">*</span>
                            </label>
                            <select class="form-select" name="price_level" id="priceLevelDropdown" required>
                                <option value="">Select Price Level</option>
                            </select>
                            <small class="text-muted"><i class="bi bi-info-circle"></i> Select applicable price level</small>
                        </div>
                    </div>

                    <!-- Location Section -->
                    <h6 class="form-section-title">
                        <i class="bi bi-geo-alt"></i> Address Information
                    </h6>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">
                                <i class="bi bi-map"></i> Region <span class="text-danger">*</span>
                            </label>
                            <select class="form-select region-select" name="region" required>
                                <option value="">Select Region</option>
                                <?php foreach ($regions as $region_code => $region_name): ?>
                                    <option value="<?php echo $region_code; ?>"><?php echo $region_name; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">
                                <i class="bi bi-pin-map"></i> Province <span class="text-danger">*</span>
                            </label>
                            <select class="form-select province-select" name="province" required disabled>
                                <option value="">Select Province</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">
                                <i class="bi bi-building"></i> City/Municipality <span class="text-danger">*</span>
                            </label>
                            <select class="form-select city-select" name="city" required disabled>
                                <option value="">Select City/Municipality</option>
                            </select>
                            <input type="hidden" name="city_code" id="cityCode" value="">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">
                                <i class="bi bi-house"></i> Barangay <span class="text-danger">*</span>
                            </label>
                            <div id="barangayFieldContainer">
                                <select class="form-select barangay-select" name="barangay" required disabled>
                                    <option value="">Select City/Municipality first</option>
                                </select>
                            </div>
                            <div class="mt-1 d-flex align-items-center">
                                <span class="loading-spinner" style="display: none;"></span>
                                <small class="api-status text-muted ms-2"></small>
                            </div>
                            <div class="manual-toggle-btn" id="manualBarangayToggle" style="display: none;">
                                <i class="bi bi-pencil-square"></i> Can't find barangay? Click to type manually
                            </div>
                        </div>
                    </div>
                    
                    <!-- Address Preview -->
                    <div class="address-preview" id="addressPreview">
                        <i class="bi bi-info-circle"></i> Full address will be: 
                        <strong><span id="fullAddressPreview">Not yet specified</span></strong>
                    </div>
                    
                    <!-- Map Location Section -->
                    <h6 class="form-section-title mt-4">
                        <i class="bi bi-map"></i> Geographic Location
                    </h6>
                    <div class="location-info">
                        <i class="bi bi-info-circle"></i> Pag-open ng modal, automatic na kukunin ang exact device location. Maaari pa ring i-click ang map o mag-enter manually kung kailangan palitan.
                    </div>
                    
                    <div id="locationMap"></div>
                    
                    <div class="row mt-3">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">
                                <i class="bi bi-arrow-up"></i> Latitude
                            </label>
                            <input type="text" class="form-control" name="latitude" id="latitudeInput" placeholder="Auto-detecting..." value="">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">
                                <i class="bi bi-arrow-right"></i> Longitude
                            </label>
                            <input type="text" class="form-control" name="longitude" id="longitudeInput" placeholder="Auto-detecting..." value="">
                        </div>
                    </div>
                    
                    <div class="location-buttons">
                        <button type="button" class="btn btn-outline-secondary" onclick="getCurrentLocation()">
                            <i class="bi bi-geo-alt"></i> Use My Location
                        </button>
                    </div>
                    
                    <?php if (!$branch_column_exists): ?>
                        <input type="hidden" name="branch_id" value="<?php echo $branch_id; ?>">
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-lg"></i> Cancel
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save"></i> Add Customer
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

    <!-- View Customer Details Modal -->
<div class="modal fade" id="viewCustomerModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Customer Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="customerDetailsContent">
                <!-- Content loaded via AJAX -->
            </div>
            <div class="modal-footer">
                <!-- Dito lalagay ang buttons dynamically via JavaScript -->
            </div>
        </div>
    </div>
</div>

    <!-- Edit Customer Modal -->
    <div class="modal fade" id="editCustomerModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Customer</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="editCustomerForm" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="update_customer">
                    <input type="hidden" name="customer_id" id="editCustomerId">
                    <input type="hidden" name="existing_store_image" id="existingStoreImage">
                    <div class="modal-body" id="editCustomerContent">
                        <!-- Content loaded via AJAX -->
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Customer</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- View Location Modal -->
    <div class="modal fade map-modal" id="viewLocationModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Customer Location</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <h6 id="locationCustomerName"></h6>
                    <div id="viewLocationMap"></div>
                    <div class="location-info">
                        <div class="row">
                            <div class="col-md-6">
                                <strong>Latitude:</strong> <span id="viewLatitude"></span>
                            </div>
                            <div class="col-md-6">
                                <strong>Longitude:</strong> <span id="viewLongitude"></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Route Sales Report (RSR) Modal - STYLED -->
<div class="modal fade" id="rsrModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-file-earmark-text"></i> Route Sales Report (RSR)
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="rsrForm" method="POST" action="">
                <input type="hidden" name="action" value="save_rsr">
                <input type="hidden" name="customer_id" id="rsrCustomerId">
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">
                                <i class="bi bi-calendar"></i> Date <span class="text-danger">*</span>
                            </label>
                            <input type="date" class="form-control" id="rsrDate" name="rsr_date" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">
                                <i class="bi bi-shop"></i> Store Name <span class="text-danger">*</span>
                            </label>
                            <input type="text" class="form-control" id="rsrStoreName" name="store_name" readonly>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-12 mb-3">
                            <label class="form-label">
                                <i class="bi bi-geo-alt"></i> Address <span class="text-danger">*</span>
                            </label>
                            <input type="text" class="form-control" id="rsrAddress" name="address" readonly>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">
                                <i class="bi bi-check-circle"></i> Status <span class="text-danger">*</span>
                            </label>
                            <select class="form-select" id="rsrStatus" name="status" required>
                                <option value="">Select Status</option>
                                <option value="with_order">With Order</option>
                                <option value="no_order">No Order</option>
                                <option value="closed_unavailable">Closed / Unavailable</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-12 mb-3">
                            <label class="form-label">
                                <i class="bi bi-chat-dots"></i> Remarks
                            </label>
                            <textarea class="form-control" id="rsrRemarks" name="remarks" rows="4" placeholder="Add any additional remarks or notes..."></textarea>
                            <small class="text-muted">Optional - provide details about the visit</small>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-lg"></i> Cancel
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save"></i> Save Report
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

    <!-- Order Details Modal - katulad ng sa sales_order.php -->
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
                    <button type="button" class="btn btn-danger" id="cancelOrderBtn" style="display: none;" onclick="cancelOrderFromCustomer()">Cancel Order</button>
                    <button type="button" class="btn btn-primary" id="printOrderFromDetails" style="display: none;" onclick="printOrderFromCustomer()">Print Order</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Image Preview Modal for Full Size View - Responsive at X button sa loob -->
    <div class="modal fade image-modal" id="imagePreviewModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-body">
                    <div class="image-container">
                        <button type="button" class="image-close-btn" onclick="closeImageModal()">&times;</button>
                        <img id="fullSizeImage" class="full-size-image" src="" alt="Store Image">
                    </div>
                </div>
            </div>
        </div>
    </div>

<!-- Mobile Bottom Navigation -->
    <div class="mobile-nav" id="mobileNav">
        <ul class="nav">
            <li class="nav-item">
                <a class="nav-link" href="current_inventory.php">
                    <i class="bi bi-box-seam"></i><span>Inventory</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link active" href="customer_orderproduct.php">
                    <i class="bi bi-cart-plus"></i><span>Orders</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="collections.php">
                    <i class="bi bi-cash-stack"></i><span>Collect</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="sales_order.php">
                    <i class="bi bi-receipt"></i><span>Sales</span>
                </a>
            </li>
            <li class="nav-item dropdown-more" id="moreDropdown">
                <a class="nav-link more-btn" href="#" onclick="toggleDropdown(event, 'moreDropdownMenu')">
                    <i class="bi bi-three-dots"></i><span>More</span>
                </a>
                <div class="more-dropdown" id="moreDropdownMenu">
                    <a href="purchase_order.php" class="dropdown-item"><i class="bi bi-truck"></i><span>Receive Inventory</span></a>
                    <a href="expenses.php" class="dropdown-item"><i class="bi bi-receipt-cutoff"></i><span>Expenses</span></a>
                    <a href="reports.php" class="dropdown-item"><i class="bi bi-file-earmark-bar-graph"></i><span>Reports</span></a>
                    <a href="#" class="dropdown-item logout-item" onclick="logout(); return false;"><i class="bi bi-box-arrow-right"></i><span>Logout</span></a>
                </div>
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
                        <span class="badge bg-success"><?php echo ucfirst(str_replace('_', ' ', $user_role)); ?></span>
                    </p>
                    
                    <!-- Branch Info (if applicable) -->
                    <?php if (!$view_all_branches && $branch_id > 0): ?>
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

    <!-- JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <!-- Leaflet JS for Maps -->
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    
    <script>
        // Philippine location data (matching PHP arrays)
        const provincesByRegion = <?php echo json_encode($provinces); ?>;
        const citiesByProvince = <?php echo json_encode($cities); ?>;
        const regionsByRegion = <?php echo json_encode($regions); ?>;

        function toggleDropdown(event, dropdownId) {
            event.preventDefault();
            event.stopPropagation();
            const dropdown = document.getElementById(dropdownId);
            if (!dropdown) return;
            document.querySelectorAll('.more-dropdown').forEach(menu => {
                if (menu.id !== dropdownId) menu.classList.remove('show');
            });
            dropdown.classList.toggle('show');
        }

        document.addEventListener('click', function(e) {
            if (!e.target.closest('.dropdown-more')) {
                document.querySelectorAll('.more-dropdown').forEach(menu => menu.classList.remove('show'));
            }
        });

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

        // Map variables
        let map;
        let marker;
        let editMap;
        let editMarker;
        let viewMap;
        let viewMarker;

        // City code cache
        let cityCodeCache = null;

        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            console.log("Customer Management page loaded!");
            
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
            
            // Setup desktop toggle button
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

            // Initialize add customer map when modal is shown
            const addCustomerModal = document.getElementById('addCustomerModal');
            if (addCustomerModal) {
                addCustomerModal.addEventListener('shown.bs.modal', function() {
                    initAddCustomerMap();
                    initLocationDropdowns();
                    // Auto-get exact device location when Add Customer modal opens
                    setTimeout(function() {
                        getCurrentLocation(true);
                    }, 350);
                    // Pre-load city codes
                    if (!cityCodeCache) {
                        loadCityCodes();
                    }
                });
                
                addCustomerModal.addEventListener('hidden.bs.modal', function() {
                    if (map) {
                        map.remove();
                        map = null;
                        marker = null;
                    }
                });
            }

            // Initialize edit map when modal is shown
            const editCustomerModal = document.getElementById('editCustomerModal');
            if (editCustomerModal) {
                editCustomerModal.addEventListener('hidden.bs.modal', function() {
                    if (editMap) {
                        editMap.remove();
                        editMap = null;
                        editMarker = null;
                    }
                });
            }

            // Clean up view map when modal is hidden
            const viewLocationModal = document.getElementById('viewLocationModal');
            if (viewLocationModal) {
                viewLocationModal.addEventListener('hidden.bs.modal', function() {
                    if (viewMap) {
                        viewMap.remove();
                        viewMap = null;
                        viewMarker = null;
                    }
                });
            }
        });

        // Load all city codes for faster matching
        function loadCityCodes() {
            fetch('https://psgc.gitlab.io/api/cities-municipalities.json')
                .then(response => response.json())
                .then(cities => {
                    cityCodeCache = {};
                    cities.forEach(city => {
                        // Store multiple variations of city names
                        const normalized = city.name.toLowerCase()
                            .replace(/\s+/g, ' ')
                            .trim();
                            
                        cityCodeCache[normalized] = city.code;
                        
                        // Store without "City" suffix
                        if (normalized.endsWith(' city')) {
                            cityCodeCache[normalized.replace(' city', '')] = city.code;
                        }
                        
                        // Store without "Municipality" suffix
                        if (normalized.endsWith(' municipality')) {
                            cityCodeCache[normalized.replace(' municipality', '')] = city.code;
                        }
                        
                        // Store with common variations
                        const withoutSpecialChars = normalized.replace(/[^a-z0-9\s]/g, '');
                        cityCodeCache[withoutSpecialChars] = city.code;
                    });
                    console.log(`Loaded ${Object.keys(cityCodeCache).length} city variations`);
                })
                .catch(error => console.error('Failed to load city codes:', error));
        }

        // Convert barangay select to manual input
        function convertToManualBarangay(message) {
            const container = document.getElementById('barangayFieldContainer');
            const toggleBtn = document.getElementById('manualBarangayToggle');
            
            if (!container) return;
            
            const existingSelect = container.querySelector('select');
            if (!existingSelect) return;
            
            // Create manual input
            const input = document.createElement('input');
            input.type = 'text';
            input.className = 'form-control';
            input.name = 'barangay';
            input.id = 'barangayInput';
            input.placeholder = 'Enter Barangay name';
            input.required = true;
            
            // Add help text
            const helpText = document.createElement('small');
            helpText.className = 'text-muted d-block mt-1';
            helpText.innerHTML = message || '⚠ No data available. Please enter manually.';
            
            // Replace select with input
            
            container.appendChild(input);
            container.appendChild(helpText);
            
            // Hide toggle button
            if (toggleBtn) toggleBtn.style.display = 'none';
            
            input.addEventListener('input', updateAddressPreview);
        }

        // Convert back to select (if user wants to retry)
        function convertToSelectBarangay() {
            const container = document.getElementById('barangayFieldContainer');
            const toggleBtn = document.getElementById('manualBarangayToggle');
            
            if (!container) return;
            
            const existingInput = container.querySelector('input');
            
            // Create select
            const select = document.createElement('select');
            select.className = 'form-select barangay-select';
            select.name = 'barangay';
            select.required = true;
            select.disabled = true;
            select.innerHTML = '<option value="">Select City/Municipality first</option>';
            
            // Replace input with select
            container.innerHTML = '<label class="form-label"></label>';
            container.appendChild(select);
            
            // Hide toggle button initially
            if (toggleBtn) toggleBtn.style.display = 'none';
        }

        // Initialize location dropdowns with PSGC API
        function initLocationDropdowns() {
            console.log("Initializing location dropdowns with PSGC API");
            
            const regionSelect = document.querySelector('.region-select');
            const provinceSelect = document.querySelector('.province-select');
            const citySelect = document.querySelector('.city-select');
            const cityCodeInput = document.getElementById('cityCode');
            const apiStatus = document.querySelector('.api-status');
            const loadingSpinner = document.querySelector('.loading-spinner');
            const toggleBtn = document.getElementById('manualBarangayToggle');
            
            // Reset to select first
            convertToSelectBarangay();
            
            if (!regionSelect || !provinceSelect || !citySelect) {
                console.error("Could not find form elements");
                return;
            }
            
            // Clear dependent selects
            provinceSelect.innerHTML = '<option value="">Select Province</option>';
            citySelect.innerHTML = '<option value="">Select City/Municipality</option>';
            
            // Set initial disabled states
            provinceSelect.disabled = true;
            citySelect.disabled = true;
            
            // Region change handler
            regionSelect.addEventListener('change', function() {
                const region = this.value;
                
                provinceSelect.innerHTML = '<option value="">Select Province</option>';
                citySelect.innerHTML = '<option value="">Select City/Municipality</option>';
                citySelect.disabled = true;
                if (cityCodeInput) cityCodeInput.value = '';
                
                // Reset barangay field
                convertToSelectBarangay();
                if (toggleBtn) toggleBtn.style.display = 'none';
                
                if (region && provincesByRegion[region]) {
                    provinceSelect.disabled = false;
                    
                    provincesByRegion[region].forEach(province => {
                        const option = document.createElement('option');
                        option.value = province;
                        option.textContent = province;
                        provinceSelect.appendChild(option);
                    });
                } else {
                    provinceSelect.disabled = true;
                }
                
                updateAddressPreview();
            });
            
            // Province change handler
            provinceSelect.addEventListener('change', function() {
                const province = this.value;
                
                citySelect.innerHTML = '<option value="">Loading cities...</option>';
                citySelect.disabled = true;
                if (cityCodeInput) cityCodeInput.value = '';
                
                // Reset barangay field
                convertToSelectBarangay();
                if (toggleBtn) toggleBtn.style.display = 'none';
                
                if (!province) {
                    citySelect.innerHTML = '<option value="">Select City/Municipality</option>';
                    return;
                }
                
                // Try PSGC API first
                fetch('https://psgc.gitlab.io/api/cities-municipalities.json')
                    .then(response => response.json())
                    .then(allCities => {
                        // Filter cities by province
                        const filteredCities = allCities.filter(city => 
                            city.provinceName && city.provinceName.toLowerCase() === province.toLowerCase()
                        );
                        
                        citySelect.innerHTML = '<option value="">Select City/Municipality</option>';
                        
                        if (filteredCities.length > 0) {
                            filteredCities.sort((a, b) => a.name.localeCompare(b.name));
                            
                            filteredCities.forEach(city => {
                                const option = document.createElement('option');
                                option.value = city.name;
                                option.textContent = city.name;
                                option.dataset.code = city.code;
                                citySelect.appendChild(option);
                            });
                            
                            if (apiStatus) apiStatus.textContent = '✓ Using PSGC API';
                            citySelect.disabled = false;
                        } else {
                            // Fallback to local data
                            console.log('Falling back to local city data');
                            if (citiesByProvince[province]) {
                                citiesByProvince[province].forEach(city => {
                                    const option = document.createElement('option');
                                    option.value = city;
                                    option.textContent = city;
                                    citySelect.appendChild(option);
                                });
                                if (apiStatus) apiStatus.textContent = '⚠ Using local data (API limited)';
                                citySelect.disabled = false;
                            } else {
                                citySelect.innerHTML = '<option value="">No cities found</option>';
                                if (apiStatus) apiStatus.textContent = '✗ No city data available';
                            }
                        }
                    })
                    .catch(error => {
                        console.error('Error loading cities:', error);
                        // Fallback to local data
                        if (citiesByProvince[province]) {
                            citySelect.innerHTML = '<option value="">Select City/Municipality</option>';
                            citiesByProvince[province].forEach(city => {
                                const option = document.createElement('option');
                                option.value = city;
                                option.textContent = city;
                                citySelect.appendChild(option);
                            });
                            citySelect.disabled = false;
                            if (apiStatus) apiStatus.textContent = '⚠ Using local data (API unavailable)';
                        } else {
                            citySelect.innerHTML = '<option value="">No cities found</option>';
                        }
                    });
                
                updateAddressPreview();
            });
            
            // City change handler
            citySelect.addEventListener('change', function() {
                const selectedOption = this.options[this.selectedIndex];
                const cityName = this.value;
                const cityCode = selectedOption.dataset?.code;
                const barangaySelect = document.querySelector('.barangay-select');
                
                if (cityCodeInput) {
                    cityCodeInput.value = cityCode || '';
                }
                
                // Reset barangay field
                convertToSelectBarangay();
                const newBarangaySelect = document.querySelector('.barangay-select');
                
                if (!newBarangaySelect) return;
                
                newBarangaySelect.innerHTML = '<option value="">Loading barangays...</option>';
                newBarangaySelect.disabled = true;
                
                if (loadingSpinner) loadingSpinner.style.display = 'inline-block';
                if (apiStatus) apiStatus.textContent = 'Fetching barangays...';
                
                if (!cityName) {
                    newBarangaySelect.innerHTML = '<option value="">Select City/Municipality first</option>';
                    if (loadingSpinner) loadingSpinner.style.display = 'none';
                    if (toggleBtn) toggleBtn.style.display = 'none';
                    updateAddressPreview();
                    return;
                }
                
                // Function to handle successful barangay load
                function handleBarangaySuccess(barangays, source) {
                    newBarangaySelect.innerHTML = '<option value="">Select Barangay</option>';
                    
                    if (barangays.length === 0) {
                        newBarangaySelect.innerHTML = '<option value="">No barangays found</option>';
                        if (toggleBtn) toggleBtn.style.display = 'block';
                    } else {
                        barangays.sort((a, b) => a.name ? a.name.localeCompare(b.name) : a.localeCompare(b));
                        
                        barangays.forEach(item => {
                            const option = document.createElement('option');
                            const name = item.name || item;
                            option.value = name;
                            option.textContent = name;
                            newBarangaySelect.appendChild(option);
                        });
                        
                        newBarangaySelect.disabled = false;
                        if (toggleBtn) toggleBtn.style.display = 'block';
                    }
                    
                    if (loadingSpinner) loadingSpinner.style.display = 'none';
                    if (apiStatus) apiStatus.textContent = `✓ ${barangays.length} barangays loaded from ${source}`;
                }
                
                // Try to get barangays by code
                if (cityCode) {
                    fetch(`https://psgc.gitlab.io/api/cities-municipalities/${cityCode}/barangays.json`)
                        .then(response => response.json())
                        .then(barangays => {
                            handleBarangaySuccess(barangays, 'PSGC');
                        })
                        .catch(error => {
                            console.error('Error loading barangays:', error);
                            newBarangaySelect.innerHTML = '<option value="">Error loading barangays</option>';
                            if (loadingSpinner) loadingSpinner.style.display = 'none';
                            if (apiStatus) apiStatus.textContent = '✗ Failed to load barangays';
                            if (toggleBtn) toggleBtn.style.display = 'block';
                        });
                } else if (cityCodeCache) {
                    // Try to find city code in cache
                    const normalized = cityName.toLowerCase().trim();
                    const foundCode = cityCodeCache[normalized] || 
                                     cityCodeCache[normalized.replace(' city', '')] ||
                                     cityCodeCache[normalized.replace(' municipality', '')];
                    
                    if (foundCode) {
                        cityCodeInput.value = foundCode;
                        fetch(`https://psgc.gitlab.io/api/cities-municipalities/${foundCode}/barangays.json`)
                            .then(response => response.json())
                            .then(barangays => {
                                handleBarangaySuccess(barangays, 'PSGC (matched)');
                            })
                            .catch(error => {
                                console.error('Error loading barangays:', error);
                                newBarangaySelect.innerHTML = '<option value="">Error loading barangays</option>';
                                if (loadingSpinner) loadingSpinner.style.display = 'none';
                                if (apiStatus) apiStatus.textContent = '✗ Failed to load barangays';
                                if (toggleBtn) toggleBtn.style.display = 'block';
                            });
                    } else {
                        // No code found, offer manual entry
                        newBarangaySelect.innerHTML = '<option value="">No PSGC data for this city</option>';
                        if (loadingSpinner) loadingSpinner.style.display = 'none';
                        if (apiStatus) apiStatus.textContent = '⚠ No PSGC code found';
                        if (toggleBtn) toggleBtn.style.display = 'block';
                    }
                } else {
                    // No cache, offer manual entry
                    newBarangaySelect.innerHTML = '<option value="">Unable to load barangays</option>';
                    if (loadingSpinner) loadingSpinner.style.display = 'none';
                    if (apiStatus) apiStatus.textContent = '⚠ City code not available';
                    if (toggleBtn) toggleBtn.style.display = 'block';
                }
                
                updateAddressPreview();
            });
            
            // Manual toggle button click handler
            if (toggleBtn) {
                toggleBtn.addEventListener('click', function() {
                    convertToManualBarangay('Manual entry mode - please type barangay name');
                    if (apiStatus) apiStatus.textContent = '⌨️ Manual entry mode';
                });
            }
        }

        function updateAddressPreview() {
            const regionSelect = document.querySelector('.region-select');
            const provinceSelect = document.querySelector('.province-select');
            const citySelect = document.querySelector('.city-select');
            const barangaySelect = document.querySelector('.barangay-select');
            const barangayInput = document.getElementById('barangayInput');
            
            const region = regionSelect ? regionSelect.options[regionSelect.selectedIndex]?.text || '' : '';
            const province = provinceSelect ? provinceSelect.value || '' : '';
            const city = citySelect ? citySelect.value || '' : '';
            let barangay = '';
            
            if (barangaySelect && !barangaySelect.disabled) {
                barangay = barangaySelect.value || '';
            } else if (barangayInput) {
                barangay = barangayInput.value || '';
            }
            
            const parts = [];
            if (barangay) parts.push(barangay);
            if (city) parts.push(city);
            if (province) parts.push(province);
            if (region) parts.push(region);
            
            const fullAddress = parts.join(', ') || 'Not yet specified';
            const previewSpan = document.getElementById('fullAddressPreview');
            if (previewSpan) {
                previewSpan.textContent = fullAddress;
            }
        }

        // Setup event listeners for search and filter
function setupEventListeners() {
    const searchInput = document.getElementById('searchInput');
    if (searchInput) {
        searchInput.addEventListener('keyup', function() {
            const filter = this.value.toLowerCase();
            const cards = document.querySelectorAll('.customer-card');
            let visibleCount = 0;
            
            cards.forEach(card => {
                const text = card.textContent.toLowerCase();
                const shouldShow = text.includes(filter);
                card.style.display = shouldShow ? '' : 'none';
                if (shouldShow) visibleCount++;
            });
            
            // Show empty state if no results
            const container = document.getElementById('customerCardsContainer');
            const existingEmpty = container.querySelector('.empty-state:not(.permanent)');
            if (visibleCount === 0 && !existingEmpty) {
                const emptyDiv = document.createElement('div');
                emptyDiv.className = 'empty-state';
                emptyDiv.innerHTML = '<i class="bi bi-search"></i><p>No matching customers found</p>';
                container.appendChild(emptyDiv);
            } else if (visibleCount > 0 && existingEmpty) {
                existingEmpty.remove();
            }
        });
    }

    const statusFilter = document.getElementById('statusFilter');
    if (statusFilter) {
        statusFilter.addEventListener('change', function() {
            const filter = this.value.toLowerCase();
            const cards = document.querySelectorAll('.customer-card');
            let visibleCount = 0;
            
            cards.forEach(card => {
                const statusBadge = card.querySelector('.status-badge');
                if (!statusBadge) return;
                
                const status = statusBadge.textContent.toLowerCase();
                const shouldShow = (filter === '' || status === filter);
                card.style.display = shouldShow ? '' : 'none';
                if (shouldShow) visibleCount++;
            });
            
            // Show empty state if no results
            const container = document.getElementById('customerCardsContainer');
            const existingEmpty = container.querySelector('.empty-state:not(.permanent)');
            if (visibleCount === 0 && !existingEmpty) {
                const emptyDiv = document.createElement('div');
                emptyDiv.className = 'empty-state';
                emptyDiv.innerHTML = '<i class="bi bi-funnel"></i><p>No customers with this status</p>';
                container.appendChild(emptyDiv);
            } else if (visibleCount > 0 && existingEmpty) {
                existingEmpty.remove();
            }
        });
    }
}

        // Refresh customer code via AJAX
        function refreshCustomerCode() {
            fetch('generate_customer_code.php')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const customerCodePreview = document.getElementById('customerCodePreview');
                        if (customerCodePreview) {
                            customerCodePreview.innerHTML = data.code + ' <i class="bi bi-arrow-repeat refresh-code" onclick="refreshCustomerCode()" title="Generate new code"></i>';
                        }
                        const customerCodeInput = document.getElementById('customerCodeInput');
                        if (customerCodeInput) {
                            customerCodeInput.value = data.code;
                        }
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                });
        }

        // Initialize add customer map
        function initAddCustomerMap() {
            if (!document.getElementById('locationMap')) return;

            const latInput = document.getElementById('latitudeInput');
            const lngInput = document.getElementById('longitudeInput');
            const defaultLat = 14.5995;
            const defaultLng = 120.9842;
            const initialLat = latInput && latInput.value ? parseFloat(latInput.value) : defaultLat;
            const initialLng = lngInput && lngInput.value ? parseFloat(lngInput.value) : defaultLng;

            if (map) {
                map.remove();
                map = null;
                marker = null;
            }

            map = L.map('locationMap').setView([initialLat, initialLng], 13);

            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '© OpenStreetMap contributors'
            }).addTo(map);

            marker = L.marker([initialLat, initialLng], {
                draggable: true
            }).addTo(map);

            marker.on('dragend', function() {
                const position = marker.getLatLng();
                setAddCustomerLocation(position.lat, position.lng, false);
            });

            map.on('click', function(e) {
                setAddCustomerLocation(e.latlng.lat, e.latlng.lng, false);
            });

            if (latInput) latInput.addEventListener('change', updateMarkerFromInputs);
            if (lngInput) lngInput.addEventListener('change', updateMarkerFromInputs);

            setTimeout(function() {
                if (map) map.invalidateSize();
            }, 250);
        }

        function setAddCustomerLocation(lat, lng, moveMap = true) {
            const latInput = document.getElementById('latitudeInput');
            const lngInput = document.getElementById('longitudeInput');
            const cleanLat = parseFloat(lat);
            const cleanLng = parseFloat(lng);

            if (isNaN(cleanLat) || isNaN(cleanLng)) return;

            if (latInput) latInput.value = cleanLat.toFixed(6);
            if (lngInput) lngInput.value = cleanLng.toFixed(6);

            if (map && marker) {
                marker.setLatLng([cleanLat, cleanLng]);
                if (moveMap) {
                    map.setView([cleanLat, cleanLng], 17);
                }
            }
        }

        function updateMarkerFromInputs() {
            const latInput = document.getElementById('latitudeInput');
            const lngInput = document.getElementById('longitudeInput');
            if (!latInput || !lngInput) return;
            const lat = parseFloat(latInput.value);
            const lng = parseFloat(lngInput.value);
            if (!isNaN(lat) && !isNaN(lng)) {
                setAddCustomerLocation(lat, lng, true);
            }
        }

        function getCurrentLocation(autoRequest = false) {
            if (!navigator.geolocation) {
                if (!autoRequest) {
                    Swal.fire('Location Not Supported', 'Geolocation is not supported by your browser.', 'warning');
                }
                return;
            }

            const latInput = document.getElementById('latitudeInput');
            const lngInput = document.getElementById('longitudeInput');
            const oldLatPlaceholder = latInput ? latInput.placeholder : '';
            const oldLngPlaceholder = lngInput ? lngInput.placeholder : '';

            if (latInput) latInput.placeholder = 'Getting location...';
            if (lngInput) lngInput.placeholder = 'Getting location...';

            navigator.geolocation.getCurrentPosition(
                function(position) {
                    const lat = position.coords.latitude;
                    const lng = position.coords.longitude;
                    setAddCustomerLocation(lat, lng, true);

                    if (latInput) latInput.placeholder = oldLatPlaceholder || 'Latitude';
                    if (lngInput) lngInput.placeholder = oldLngPlaceholder || 'Longitude';

                    if (!autoRequest) {
                        Swal.fire({
                            icon: 'success',
                            title: 'Location Captured',
                            text: 'Device location has been set successfully.',
                            timer: 1200,
                            showConfirmButton: false
                        });
                    }
                },
                function(error) {
                    if (latInput) latInput.placeholder = oldLatPlaceholder || 'Latitude';
                    if (lngInput) lngInput.placeholder = oldLngPlaceholder || 'Longitude';

                    if (!autoRequest) {
                        Swal.fire('Unable to Get Location', error.message, 'warning');
                    } else {
                        console.warn('Automatic location request failed:', error.message);
                    }
                },
                {
                    enableHighAccuracy: true,
                    timeout: 15000,
                    maximumAge: 0
                }
            );
        }

        // Function to open full size image
        function openFullImage(imageSrc) {
            const fullSizeImage = document.getElementById('fullSizeImage');
            if (fullSizeImage) {
                fullSizeImage.src = imageSrc;
                const imageModal = new bootstrap.Modal(document.getElementById('imagePreviewModal'));
                imageModal.show();
            }
        }

        // Function to close image modal
        function closeImageModal() {
            const imageModal = bootstrap.Modal.getInstance(document.getElementById('imagePreviewModal'));
            if (imageModal) {
                imageModal.hide();
            }
        }

        // Escape HTML function
        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Format currency
        function formatCurrency(amount) {
            if (!amount) return '₱0.00';
            return '₱' + parseFloat(amount).toLocaleString('en-PH', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        }

        // Format date
        function formatDate(dateString) {
            if (!dateString) return 'N/A';
            const date = new Date(dateString);
            return date.toLocaleDateString('en-PH', {
                year: 'numeric',
                month: 'short',
                day: 'numeric'
            });
        }

        // Order Product function - redirect to rolling_orderproduct.php with customer ID
        function orderProduct(customerId) {
            // Get customer name for better UX
            fetch(window.location.pathname + '?ajax_customer_details=1&id=' + encodeURIComponent(customerId), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const customerName = data.customer.customer_name;
                        Swal.fire({
                            title: 'Create Order for ' + customerName + '?',
                            text: 'You will be redirected to the order page to create a new order for this customer.',
                            icon: 'question',
                            showCancelButton: true,
                            confirmButtonColor: '#059669',
                            cancelButtonColor: '#6c757d',
                            confirmButtonText: 'Yes, Create Order',
                            cancelButtonText: 'Cancel'
                        }).then((result) => {
                            if (result.isConfirmed) {
                                window.location.href = 'rolling_orderproduct.php?customer_id=' + customerId;
                            }
                        });
                    } else {
                        window.location.href = 'rolling_orderproduct.php?customer_id=' + customerId;
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    window.location.href = 'rolling_orderproduct.php?customer_id=' + customerId;
                });
        }

        function viewCustomerDetails(customerId) {
    fetch(window.location.pathname + '?ajax_customer_details=1&id=' + encodeURIComponent(customerId), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const customer = data.customer;
                const modal = new bootstrap.Modal(document.getElementById('viewCustomerModal'));
                const customerDetailsContent = document.getElementById('customerDetailsContent');
                
                // Build the customer details HTML - Store image at the TOP
                const imageHtml = customer.store_image ? 
                    `<div class="store-image-section">
                        <img src="../uploads/store_images/${escapeHtml(customer.store_image)}" 
                             alt="Store Image" 
                             class="store-image"
                             onclick="openFullImage('../uploads/store_images/${escapeHtml(customer.store_image)}')"
                             onerror="this.src='../Pictures/no-image.png'">
                        <small class="text-muted d-block mt-2">Click image to enlarge</small>
                    </div>` : 
                    `<div class="store-image-section">
                        <div class="text-muted py-4">
                            <i class="bi bi-image" style="font-size: 3rem;"></i>
                            <p class="mt-2 mb-0">No store image available</p>
                        </div>
                    </div>`;
                
                // Build orders HTML
                let ordersHtml = '';
                // Sa viewCustomerDetails function, palitan ang ordersHtml ng ganito:
if (customer.orders && customer.orders.length > 0) {
    ordersHtml = `
        <div class="orders-section">
            <h6><i class="bi bi-bag-check"></i> Order History (${customer.orders.length} orders)</h6>
            <div class="table-responsive">
                <table class="table table-hover orders-table">
                    <thead>
                        <tr>
                            <th>Order #</th>
                            <th>Order Date</th>
                            <th>Total Amount</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${customer.orders.map(order => `
                            <tr>
                                <td><span class="badge bg-secondary">${escapeHtml(order.so_number)}</span></td>
                                <td>${formatDate(order.order_date)}</span></td>
                                <td>${formatCurrency(order.total_amount)}</span></td>
                                <td><span class="badge ${order.order_status === 'delivered' ? 'bg-success' : order.order_status === 'cancelled' ? 'bg-danger' : 'bg-warning'} badge-order-status">${escapeHtml(order.order_status || 'Pending')}</span></td>
                                <td>
                                    <button class="btn-action btn-view" onclick="viewOrderFromCustomer(${order.so_id})">
                                         View Order
                                    </button>
                                 </span>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
            </div>
        </div>
    `;
}else {
                    ordersHtml = `
                        <div class="orders-section">
                            <h6><i class="bi bi-bag-check"></i> Order History</h6>
                            <div class="no-orders">
                                <i class="bi bi-inbox" style="font-size: 2rem;"></i>
                                <p class="mt-2 mb-0">No orders found for this customer</p>
                            </div>
                        </div>
                    `;
                }
                
                // Determine location display with View Map button
                let locationHtml = '';
                if (customer.latitude && customer.longitude) {
                    locationHtml = `
                        <div class="customer-info-value">
                            Lat: ${escapeHtml(customer.latitude)}<br>Lng: ${escapeHtml(customer.longitude)}
                    
                        </div>
                    `;
                } else {
                    locationHtml = `<div class="customer-info-value">N/A</div>`;
                }
                
                const outstandingBalance = parseFloat(customer.outstanding_balance || 0);
                const totalOilVolume = parseFloat(customer.total_oil_volume || 0);
                const oilVolumeHtml = totalOilVolume > 0 ? `
                            <div class="customer-info-item">
                                <span class="customer-info-label">Total Oil Volume Ordered</span>
                                <span class="customer-info-value" style="font-weight: 700; color: #1B5E20;">
                                    ${totalOilVolume.toLocaleString(undefined, { maximumFractionDigits: 2 })} kg
                                </span>
                            </div>` : '';

                customerDetailsContent.innerHTML = `
                    <div class="customer-details-card">
                        <!-- Store Image sa PINAKAITAAS -->
                        ${imageHtml}
                        
                        <!-- Customer Information Grid - Tamang pagkakasunod-sunod -->
                        <div class="customer-info-grid">
                            <div class="customer-info-item">
                                <span class="customer-info-label">Customer Code</span>
                                <span class="customer-info-value">${escapeHtml(customer.customer_code) || 'N/A'}</span>
                            </div>
                            <div class="customer-info-item">
                                <span class="customer-info-label">Store Name</span>
                                <span class="customer-info-value">${escapeHtml(customer.store_name) || 'N/A'}</span>
                            </div>
                            <div class="customer-info-item">
                                <span class="customer-info-label">Customer Name</span>
                                <span class="customer-info-value">${escapeHtml(customer.customer_name) || 'N/A'}</span>
                            </div>
                            <div class="customer-info-item">
                                <span class="customer-info-label">Contact Person</span>
                                <span class="customer-info-value">${escapeHtml(customer.contact_person) || 'N/A'}</span>
                            </div>
                            <div class="customer-info-item">
                                <span class="customer-info-label">Contact Number</span>
                                <span class="customer-info-value">${escapeHtml(customer.phone_number) || 'N/A'}</span>
                            </div>
                            <div class="customer-info-item">
                                <span class="customer-info-label">Email Address</span>
                                <span class="customer-info-value">${escapeHtml(customer.email) || 'N/A'}</span>
                            </div>
                            <div class="customer-info-item">
                                <span class="customer-info-label">Address</span>
                                <span class="customer-info-value">${escapeHtml(customer.address) || 'N/A'}</span>
                            </div>
                            <div class="customer-info-item">
                                <span class="customer-info-label">Status</span>
                                <span class="customer-info-value">
                                    <span class="badge ${customer.status === 'active' ? 'bg-success' : customer.status === 'inactive' ? 'bg-danger' : 'bg-warning'}">
                                        ${escapeHtml(customer.status) || 'N/A'}
                                    </span>
                                </span>
                            </div>
                            <div class="customer-info-item" style="background:#f8fff9; border:1px solid #d1e7dd; border-radius:10px; padding:12px;">
                                <span class="customer-info-label" style="color:#1B5E20;">Outstanding Balance</span>
                                <span class="customer-info-value" style="font-weight:700; color:#1B5E20;">
                                    ${formatCurrency(outstandingBalance)}
                                </span>
                            </div>
                            ${oilVolumeHtml}
                            <div class="customer-info-item">
                                <span class="customer-info-label">Location</span>
                                ${locationHtml}
                            </div>
                        </div>
                        
                        ${ordersHtml}
                    </div>
                `;
                
                // I-update ang modal footer - Request Credit/Discount button at RSR button
                const modalFooter = document.querySelector('#viewCustomerModal .modal-footer');
                if (modalFooter) {
                    modalFooter.innerHTML = `
                        <div class="d-flex gap-2 justify-content-end w-100">
                            <button class="btn btn-info" onclick="openRSRForm(${customer.customer_id}, '${escapeHtml(customer.store_name).replace(/'/g, "\\'")}', '${escapeHtml(customer.address).replace(/'/g, "\\'")}')">
                                <i class="bi bi-file-earmark-text"></i> Route Sales Report
                            </button>
                            <button class="btn btn-primary" onclick="createCreditRequest(${customer.customer_id}, '${escapeHtml(customer.customer_name).replace(/'/g, "\\'")}')">
                                <i class="bi bi-pencil-square"></i> Request Credit/Discount
                            </button>
                        </div>
                    `;
                }
                
                modal.show();
            } else {
                alert('Error loading customer details');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error loading customer details');
        });
}
/// Variables para sa order management
let currentOrderIdFromCustomer = null;

// Function to view order from customer details - open modal sa customer.php
function viewOrderFromCustomer(orderId) {
    currentOrderIdFromCustomer = orderId;
    const modal = new bootstrap.Modal(document.getElementById('orderDetailsModal'));
    
    // Show loading
    const orderDetailsContent = document.getElementById('orderDetailsContent');
    if (orderDetailsContent) {
        orderDetailsContent.innerHTML = `
            <div class="loading-state text-center py-5">
                <div class="spinner-border text-success" role="status">
                    <span class="visually-hidden">Loading...</span>
                </div>
                <p class="mt-3 text-muted">Loading order details...</p>
            </div>
        `;
    }
    
    modal.show();
    
    // Fetch order details via AJAX to same endpoint (customer.php can handle this)
    // Or we can use sales_order.php endpoint with CORS? Better to use same file.
    // We'll add handler in PHP for this.
    fetch('customer_orderproduct.php', {
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
                            <td data-label="Unit Price" class="text-end">${formatCurrency(item.unit_price)}</td>
                            <td data-label="Total" class="text-end">${formatCurrency(total)}</td>
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
                                            <td class="text-end fw-bold text-success">${formatCurrency(grandTotal)}</td>
                                        </tr>
                                    </tbody>
                                </table>
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
                    <div class="error-state text-center py-5">
                        <i class="bi bi-exclamation-triangle fs-1 text-danger"></i>
                        <p class="mt-3">${escapeHtml(data.message || 'Error loading order details.')}</p>
                    </div>
                `;
            }
            const printButton = document.getElementById('printOrderFromDetails');
            if (printButton) printButton.style.display = 'none';
            const cancelButton = document.getElementById('cancelOrderBtn');
            if (cancelButton) cancelButton.style.display = 'none';
        }
    })
    .catch(error => {
        console.error('Error:', error);
        if (orderDetailsContent) {
            orderDetailsContent.innerHTML = `
                <div class="error-state text-center py-5">
                    <i class="bi bi-wifi-off fs-1 text-danger"></i>
                    <p class="mt-3">Network error: ${escapeHtml(error.message)}</p>
                    <button class="btn btn-outline-danger mt-2" onclick="viewOrderFromCustomer(${orderId})">
                        <i class="bi bi-arrow-repeat"></i> Try Again
                    </button>
                </div>
            `;
        }
        const printButton = document.getElementById('printOrderFromDetails');
        if (printButton) printButton.style.display = 'none';
        const cancelButton = document.getElementById('cancelOrderBtn');
        if (cancelButton) cancelButton.style.display = 'none';
    });
}

// Print order from customer details modal
function printOrderFromCustomer() {
    if (currentOrderIdFromCustomer) {
        // Use the print function from sales_order.php logic
        printSingleOrderFromCustomer(currentOrderIdFromCustomer);
        const modal = bootstrap.Modal.getInstance(document.getElementById('orderDetailsModal'));
        if (modal) modal.hide();
    }
}

// Print single order function (similar to sales_order.php)
function printSingleOrderFromCustomer(orderId) {
    const printBtn = document.querySelector('#printOrderFromDetails');
    if (printBtn) {
        printBtn.innerHTML = '<i class="bi bi-printer"></i> Printing...';
        printBtn.disabled = true;
    }
    
    showLoading();
    
    const formData = new FormData();
    formData.append('action', 'print_order');
    formData.append('so_id', orderId);
    
    fetch('customer_orderproduct.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        hideLoading();
        
        if (data.success) {
            const order = data.order;
            const items = data.items;
            const driver = data.driver || null;
            
            const htmlContent = generateSingleOrderHTML(order, items, driver);
            const iframe = document.getElementById('printFrame') || createPrintFrame();
            const iframeDoc = iframe.contentWindow.document;
            
            iframeDoc.open();
            iframeDoc.write(htmlContent);
            iframeDoc.close();
            
            setTimeout(() => {
                if (printBtn) {
                    printBtn.innerHTML = '<i class="bi bi-printer"></i> Print Order';
                    printBtn.disabled = false;
                }
            }, 1000);
            
            setTimeout(() => {
                iframe.contentWindow.focus();
                iframe.contentWindow.print();
            }, 250);
        } else {
            Swal.fire('Error', 'Failed to load order details', 'error');
            if (printBtn) {
                printBtn.innerHTML = '<i class="bi bi-printer"></i> Print Order';
                printBtn.disabled = false;
            }
        }
    })
    .catch(error => {
        hideLoading();
        console.error('Error:', error);
        Swal.fire('Error', 'Network error: ' + error.message, 'error');
        if (printBtn) {
            printBtn.innerHTML = '<i class="bi bi-printer"></i> Print Order';
            printBtn.disabled = false;
        }
    });
}

// Cancel order from customer details modal
function cancelOrderFromCustomer() {
    if (!currentOrderIdFromCustomer) {
        Swal.fire('Error', 'No order selected', 'error');
        return;
    }

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
            const cancelBtn = document.getElementById('cancelOrderBtn');
            if (cancelBtn) cancelBtn.disabled = true;

            fetch('customer_orderproduct.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: 'action=cancel_order&order_id=' + currentOrderIdFromCustomer
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    Swal.fire({
                        title: 'Success!',
                        text: 'Order cancelled successfully',
                        icon: 'success',
                        timer: 2000,
                        showConfirmButton: false
                    });
                    const modal = bootstrap.Modal.getInstance(document.getElementById('orderDetailsModal'));
                    if (modal) modal.hide();
                    // Refresh the customer details view to show updated order status
                    setTimeout(() => {
                        if (currentCustomerId) {
                            viewCustomerDetails(currentCustomerId);
                        }
                    }, 500);
                    currentOrderIdFromCustomer = null;
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

// Helper function to create print frame if not exists
function createPrintFrame() {
    let iframe = document.getElementById('printFrame');
    if (!iframe) {
        iframe = document.createElement('iframe');
        iframe.id = 'printFrame';
        iframe.style.position = 'absolute';
        iframe.style.left = '-9999px';
        iframe.style.top = '-9999px';
        iframe.style.width = '1px';
        iframe.style.height = '1px';
        document.body.appendChild(iframe);
    }
    return iframe;
}

// Generate HTML for single order (copied from sales_order.php)
function generateSingleOrderHTML(order, items, driver) {
    let itemsHtml = '';
    let totalQty = 0;
    
    if (items && items.length > 0) {
        itemsHtml = items.map(item => {
            const subtotal = item.quantity_ordered * item.unit_price;
            totalQty += parseInt(item.quantity_ordered);
            return `
                <tr>
                    <td style="padding: 3px; border: 1px solid #000;">${escapeHtml(item.item_code)}</td>
                    <td style="padding: 3px; border: 1px solid #000;">${escapeHtml(item.item_name)}</td>
                    <td style="padding: 3px; border: 1px solid #000; text-align: center;">${escapeHtml(item.unit_type || '')}</td>
                    <td style="padding: 3px; border: 1px solid #000; text-align: center;">${item.quantity_ordered}</td>
                    <td style="padding: 3px; border: 1px solid #000; text-align: right;">${formatCurrency(item.unit_price)}</td>
                    <td style="padding: 3px; border: 1px solid #000; text-align: right;">${formatCurrency(subtotal)}</td>
                </tr>
            `;
        }).join('');
    }
    
    const createdByName = order ? (order.first_name ? `${order.first_name} ${order.last_name || ''}` : 'Sales User') : 'Sales User';
    const currentDate = new Date().toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
    const orderDate = order ? (order.order_date ? new Date(order.order_date).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' }) : currentDate) : currentDate;
    const customerName = order ? escapeHtml(order.customer_name) : '';
    const orderNumber = order ? escapeHtml(order.so_number) : '';
    const orderStatus = order ? order.order_status : '';
    const totalAmount = order ? order.order_total : 0;
    const driverName = driver ? escapeHtml(driver.driver_name) : (order?.assigned_driver !== 'No Driver' ? escapeHtml(order?.assigned_driver) : 'No Driver');
    
    // Get logo base64 from page variable or use default
    const logoBase64 = typeof window.logoBase64 !== 'undefined' ? window.logoBase64 : '';
    
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
                        <img src="${logoBase64}" alt="AMGC Logo" class="company-logo" onerror="this.style.display='none'">
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
                            <td style="text-align: right;">${formatCurrency(totalAmount)}</td>
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

// Track current customer ID for refresh
let currentCustomerId = null;

// Override viewCustomerDetails to track customer ID
const originalViewCustomerDetails = viewCustomerDetails;
viewCustomerDetails = function(customerId) {
    currentCustomerId = customerId;
    originalViewCustomerDetails(customerId);
};

// Show/hide loading overlay
function showLoading() {
    let overlay = document.getElementById('loadingOverlay');
    if (!overlay) {
        overlay = document.createElement('div');
        overlay.id = 'loadingOverlay';
        overlay.className = 'loading-overlay';
        overlay.innerHTML = '<div class="loading-spinner"></div><div class="mt-3 text-success">Processing...</div>';
        overlay.style.cssText = 'position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(255,255,255,0.8); display: flex; justify-content: center; align-items: center; z-index: 9999; flex-direction: column;';
        document.body.appendChild(overlay);
    }
    overlay.style.display = 'flex';
}

function hideLoading() {
    const overlay = document.getElementById('loadingOverlay');
    if (overlay) {
        overlay.style.display = 'none';
    }
}
// Function to redirect to credit request page with customer pre-selected
function createCreditRequest(customerId, customerName) {
    window.location.href = `credit_discount_request.php?customer_id=${customerId}&customer_name=${encodeURIComponent(customerName)}`;
}

        function initEditLocationDropdowns(customer) {
            const regionSelect = document.querySelector('.region-select-edit');
            const provinceSelect = document.querySelector('.province-select-edit');
            const citySelect = document.querySelector('.city-select-edit');
            const cityCodeInput = document.getElementById('cityCodeEdit');
            
            if (!regionSelect || !provinceSelect || !citySelect) return;
            
            // Set initial values
            if (customer.region && provincesByRegion[customer.region]) {
                regionSelect.value = customer.region;
                
                // Populate provinces
                provinceSelect.innerHTML = '<option value="">Select Province</option>';
                provincesByRegion[customer.region].forEach(province => {
                    const option = document.createElement('option');
                    option.value = province;
                    option.textContent = province;
                    if (customer.province === province) option.selected = true;
                    provinceSelect.appendChild(option);
                });
                provinceSelect.disabled = false;
                
                // Populate cities
                if (customer.province && citiesByProvince[customer.province]) {
                    citySelect.innerHTML = '<option value="">Select City/Municipality</option>';
                    citiesByProvince[customer.province].forEach(city => {
                        const option = document.createElement('option');
                        option.value = city;
                        option.textContent = city;
                        if (customer.city === city) option.selected = true;
                        citySelect.appendChild(option);
                    });
                    citySelect.disabled = false;
                }
            }
            
            // Set city code if exists
            if (customer.city_code && cityCodeInput) {
                cityCodeInput.value = customer.city_code;
            }
        }

        function editCustomer(customerId) {
            fetch(window.location.pathname + '?ajax_customer_details=1&id=' + encodeURIComponent(customerId), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const customer = data.customer;
                        const modal = new bootstrap.Modal(document.getElementById('editCustomerModal'));
                        const editCustomerId = document.getElementById('editCustomerId');
                        if (editCustomerId) editCustomerId.value = customerId;
                        
                        const existingStoreImage = document.getElementById('existingStoreImage');
                        if (existingStoreImage) existingStoreImage.value = customer.store_image || '';
                        
                        const editCustomerContent = document.getElementById('editCustomerContent');
                        if (editCustomerContent) {
                            editCustomerContent.innerHTML = `
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Customer Name *</label>
                                        <input type="text" class="form-control" name="customer_name" value="${escapeHtml(customer.customer_name)}" required>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Customer Code</label>
                                        <input type="text" class="form-control" name="customer_code" value="${escapeHtml(customer.customer_code)}" readonly>
                                        <small class="text-muted">Customer code cannot be changed</small>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Contact Person</label>
                                        <input type="text" class="form-control" name="contact_person" value="${escapeHtml(customer.contact_person)}">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Email</label>
                                        <input type="email" class="form-control" name="email" value="${escapeHtml(customer.email)}">
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Phone</label>
                                        <input type="tel" class="form-control" name="phone_number" value="${escapeHtml(customer.phone_number)}">
                                    </div>
                                </div>

                                <h6 class="form-section-title mt-3"><i class="bi bi-shop"></i> Store Information</h6>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Store Name</label>
                                        <input type="text" class="form-control" name="store_name" value="${escapeHtml(customer.store_name)}">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Store Footage/Picture</label>
                                        <input type="file" class="form-control" name="store_image" accept="image/*">
                                        ${customer.store_image ? `<small class="text-muted d-block mt-1">Current: <img src="../uploads/store_images/${escapeHtml(customer.store_image)}" alt="Store" class="store-image-thumb" style="max-width: 100px; max-height: 80px; cursor: pointer;" onclick="openFullImage('../uploads/store_images/${escapeHtml(customer.store_image)}')"></small><small class="text-muted mt-1 d-block">Leave empty to keep current image</small>` : '<small class="text-muted">JPG, PNG, GIF or WebP (Max 5MB)</small>'}
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Price Level</label>
                                        <select class="form-select" name="price_level" id="editPriceLevelDropdown" required>
                                            <option value="">Select Price Level</option>
                                        </select>
                                        <small class="text-muted">Select the applicable price level for this customer</small>
                                    </div>
                                </div>
                                
                                <h6 class="form-section-title mt-3"><i class="bi bi-geo-alt"></i> Address Information</h6>
                                
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Region *</label>
                                        <select class="form-select region-select-edit" name="region" required>
                                            <option value="">Select Region</option>
                                            ${Object.keys(provincesByRegion).map(code => 
                                                `<option value="${code}" ${customer.region === code ? 'selected' : ''}>${code}</option>`
                                            ).join('')}
                                        </select>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Province *</label>
                                        <select class="form-select province-select-edit" name="province" required>
                                            <option value="">Select Province</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">City/Municipality *</label>
                                        <select class="form-select city-select-edit" name="city" required>
                                            <option value="">Select City/Municipality</option>
                                        </select>
                                        <input type="hidden" name="city_code" id="cityCodeEdit" value="${escapeHtml(customer.city_code)}">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Barangay *</label>
                                        <input type="text" class="form-control" name="barangay" value="${escapeHtml(customer.barangay)}" required>
                                        <small class="text-muted">Enter barangay name</small>
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label">Status</label>
                                    <select class="form-select" name="status">
                                        <option value="active" ${customer.status === 'active' ? 'selected' : ''}>Active</option>
                                        <option value="inactive" ${customer.status === 'inactive' ? 'selected' : ''}>Inactive</option>
                                        <option value="pending" ${customer.status === 'pending' ? 'selected' : ''}>Pending</option>
                                    </select>
                                </div>
                                <div class="location-info">
                                    <small><i class="bi bi-info-circle"></i> Update location coordinates</small>
                                </div>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Latitude</label>
                                        <input type="text" class="form-control" name="latitude" id="editLatitude" value="${customer.latitude || '14.5995'}">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Longitude</label>
                                        <input type="text" class="form-control" name="longitude" id="editLongitude" value="${customer.longitude || '120.9842'}">
                                    </div>
                                </div>
                                <div id="editLocationMap" style="height: 250px; margin-bottom: 15px; border-radius: 8px;"></div>
                                <div class="location-buttons">
                                    <button type="button" class="btn btn-outline-secondary" onclick="getCurrentLocationForEdit()">
                                        <i class="bi bi-geo-alt"></i> Use My Location
                                    </button>
                                </div>
                            `;
                        }
                        modal.show();
                        setTimeout(() => {
                            populatePriceLevelDropdown('editPriceLevelDropdown', customer.price_level || 'Standard');
                            initEditCustomerMap(customer);
                            initEditLocationDropdowns(customer);
                            
                            // Add event listeners for region and province changes
                            const regionSelectEdit = document.querySelector('.region-select-edit');
                            const provinceSelectEdit = document.querySelector('.province-select-edit');
                            const citySelectEdit = document.querySelector('.city-select-edit');
                            
                            if (regionSelectEdit) {
                                regionSelectEdit.addEventListener('change', function() {
                                    const region = this.value;
                                    provinceSelectEdit.innerHTML = '<option value="">Select Province</option>';
                                    citySelectEdit.innerHTML = '<option value="">Select City/Municipality</option>';
                                    
                                    if (region && provincesByRegion[region]) {
                                        provinceSelectEdit.disabled = false;
                                        provincesByRegion[region].forEach(province => {
                                            const option = document.createElement('option');
                                            option.value = province;
                                            option.textContent = province;
                                            provinceSelectEdit.appendChild(option);
                                        });
                                    } else {
                                        provinceSelectEdit.disabled = true;
                                        citySelectEdit.disabled = true;
                                    }
                                });
                            }
                            
                            if (provinceSelectEdit) {
                                provinceSelectEdit.addEventListener('change', function() {
                                    const province = this.value;
                                    citySelectEdit.innerHTML = '<option value="">Select City/Municipality</option>';
                                    
                                    if (province && citiesByProvince[province]) {
                                        citySelectEdit.disabled = false;
                                        citiesByProvince[province].forEach(city => {
                                            const option = document.createElement('option');
                                            option.value = city;
                                            option.textContent = city;
                                            citySelectEdit.appendChild(option);
                                        });
                                        // Set the selected city if it matches
                                        if (customer.city && citiesByProvince[province].includes(customer.city)) {
                                            citySelectEdit.value = customer.city;
                                        }
                                    } else {
                                        citySelectEdit.disabled = true;
                                    }
                                });
                            }
                        }, 500);
                    } else {
                        alert('Error loading customer details');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error loading customer details');
                });
        }

        function initEditCustomerMap(customer) {
            setTimeout(() => {
                if (document.getElementById('editLocationMap')) {
                    const lat = parseFloat(customer.latitude) || 14.5995;
                    const lng = parseFloat(customer.longitude) || 120.9842;
                    editMap = L.map('editLocationMap').setView([lat, lng], 13);
                    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                        attribution: '© OpenStreetMap contributors'
                    }).addTo(editMap);
                    editMarker = L.marker([lat, lng], {
                        draggable: true
                    }).addTo(editMap);
                    editMarker.on('dragend', function(e) {
                        const position = editMarker.getLatLng();
                        const editLatitude = document.getElementById('editLatitude');
                        const editLongitude = document.getElementById('editLongitude');
                        if (editLatitude) editLatitude.value = position.lat.toFixed(6);
                        if (editLongitude) editLongitude.value = position.lng.toFixed(6);
                    });
                    editMap.on('click', function(e) {
                        editMarker.setLatLng(e.latlng);
                        const editLatitude = document.getElementById('editLatitude');
                        const editLongitude = document.getElementById('editLongitude');
                        if (editLatitude) editLatitude.value = e.latlng.lat.toFixed(6);
                        if (editLongitude) editLongitude.value = e.latlng.lng.toFixed(6);
                    });
                    const editLatitude = document.getElementById('editLatitude');
                    const editLongitude = document.getElementById('editLongitude');
                    if (editLatitude) editLatitude.addEventListener('change', updateEditMarkerFromInputs);
                    if (editLongitude) editLongitude.addEventListener('change', updateEditMarkerFromInputs);
                }
            }, 300);
        }

        function updateEditMarkerFromInputs() {
            const editLatitude = document.getElementById('editLatitude');
            const editLongitude = document.getElementById('editLongitude');
            if (!editLatitude || !editLongitude) return;
            const lat = parseFloat(editLatitude.value);
            const lng = parseFloat(editLongitude.value);
            if (!isNaN(lat) && !isNaN(lng) && editMap && editMarker) {
                editMarker.setLatLng([lat, lng]);
                editMap.setView([lat, lng], 13);
            }
        }

        function getCurrentLocationForEdit() {
            if (navigator.geolocation) {
                navigator.geolocation.getCurrentPosition(
                    function(position) {
                        const lat = position.coords.latitude;
                        const lng = position.coords.longitude;
                        const editLatitude = document.getElementById('editLatitude');
                        const editLongitude = document.getElementById('editLongitude');
                        if (editLatitude) editLatitude.value = lat.toFixed(6);
                        if (editLongitude) editLongitude.value = lng.toFixed(6);
                        if (editMap && editMarker) {
                            editMarker.setLatLng([lat, lng]);
                            editMap.setView([lat, lng], 13);
                        }
                    },
                    function(error) {
                        alert('Unable to get your location: ' + error.message);
                    }
                );
            } else {
                alert('Geolocation is not supported by your browser');
            }
        }

        function viewLocationOnMap(customerId, customerName, latitude, longitude) {
            const locationCustomerName = document.getElementById('locationCustomerName');
            const viewLatitude = document.getElementById('viewLatitude');
            const viewLongitude = document.getElementById('viewLongitude');
            if (locationCustomerName) locationCustomerName.textContent = customerName;
            if (viewLatitude) viewLatitude.textContent = latitude;
            if (viewLongitude) viewLongitude.textContent = longitude;
            const modal = new bootstrap.Modal(document.getElementById('viewLocationModal'));
            modal.show();
            setTimeout(() => {
                if (document.getElementById('viewLocationMap')) {
                    if (viewMap) {
                        viewMap.remove();
                    }
                    const lat = parseFloat(latitude);
                    const lng = parseFloat(longitude);
                    viewMap = L.map('viewLocationMap').setView([lat, lng], 15);
                    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                        attribution: '© OpenStreetMap contributors'
                    }).addTo(viewMap);
                    viewMarker = L.marker([lat, lng]).addTo(viewMap);
                    viewMarker.bindPopup(`<b>${escapeHtml(customerName)}</b><br>${lat.toFixed(6)}, ${lng.toFixed(6)}`).openPopup();
                }
            }, 300);
        }

        function callCustomer(phoneNumber) {
            // Remove any non-numeric characters except the leading + for international numbers
            const cleanedNumber = phoneNumber.replace(/\D/g, '');
            
            // Create tel: link and trigger it
            const telLink = 'tel:+63' + cleanedNumber.replace(/^0/, '');
            window.location.href = telLink;
        }

        function logout() {
            window.location.href = '../logout.php';
        }

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey && e.key === 'b' && window.innerWidth > 992) {
                e.preventDefault();
                toggleSidebar();
            } else if (e.key === 'Escape' && window.innerWidth <= 992) {
                closeMobileSidebar();
            } else if (e.ctrlKey && e.key === 'f') {
                e.preventDefault();
                const searchInput = document.getElementById('searchInput');
                if (searchInput) {
                    searchInput.focus();
                }
            } else if (e.ctrlKey && e.key === 'n') {
                e.preventDefault();
                const addButton = document.querySelector('[data-bs-target="#addCustomerModal"]');
                if (addButton) {
                    addButton.click();
                }
            } else if (e.key === 'Escape') {
                closeImageModal();
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
        // Filter Toggle Functionality
document.addEventListener('DOMContentLoaded', function() {
    const filterToggleBtn = document.getElementById('filterToggleBtn');
    const filterContent = document.getElementById('filterContent');
    
    if (filterToggleBtn && filterContent) {
        // Set initial state (collapsed by default)
        filterContent.classList.add('collapsed');
        filterToggleBtn.setAttribute('aria-expanded', 'false');
        
        // Toggle on click
        filterToggleBtn.addEventListener('click', function() {
            const isExpanded = filterToggleBtn.getAttribute('aria-expanded') === 'true';
            
            if (isExpanded) {
                filterContent.classList.add('collapsed');
                filterToggleBtn.setAttribute('aria-expanded', 'false');
            } else {
                filterContent.classList.remove('collapsed');
                filterToggleBtn.setAttribute('aria-expanded', 'true');
            }
        });
    }
});

// ============= LOAD PRICE LEVELS FROM DATABASE =============
document.addEventListener('DOMContentLoaded', function() {
    // Add slight delay to ensure DOM is fully ready
    setTimeout(() => {
        loadPriceLevels();
    }, 100);
});

// Function to populate price level dropdown
function populatePriceLevelDropdown(dropdownId, selectedValue = '') {
    const dropdown = document.getElementById(dropdownId);
    
    if (!dropdown) {
        console.log('[v0] Dropdown with ID "' + dropdownId + '" not found');
        return;
    }
    
    console.log('[v0] Loading price levels for dropdown: ' + dropdownId);
    
    const formData = new FormData();
    formData.append('action', 'get_price_levels');
    
    fetch('customer_orderproduct.php', { method: 'POST', body: formData })
        .then(response => {
            if (!response.ok) {
                throw new Error('HTTP error, status=' + response.status);
            }
            return response.json();
        })
        .then(data => {
            console.log('[v0] Price levels loaded for ' + dropdownId + ':', data);
            
            // Clear existing options except the first one
            while (dropdown.options.length > 1) {
                dropdown.remove(1);
            }
            
            if (data.success && Array.isArray(data.price_levels) && data.price_levels.length > 0) {
                data.price_levels.forEach(level => {
                    const option = document.createElement('option');
                    option.value = level;
                    option.textContent = level;
                    if (level === selectedValue) {
                        option.selected = true;
                    }
                    dropdown.appendChild(option);
                });
            } else {
                // Add default Standard option
                const option = document.createElement('option');
                option.value = 'Standard';
                option.textContent = 'Standard';
                if ('Standard' === selectedValue) {
                    option.selected = true;
                }
                dropdown.appendChild(option);
            }
        })
        .catch(error => {
            console.error('[v0] Error loading price levels for ' + dropdownId + ':', error);
            // Add default Standard option on error
            const option = document.createElement('option');
            option.value = 'Standard';
            option.textContent = 'Standard';
            if ('Standard' === selectedValue) {
                option.selected = true;
            }
            dropdown.appendChild(option);
        });
}

function loadPriceLevels() {
    populatePriceLevelDropdown('priceLevelDropdown');
    const editDropdown = document.getElementById('editPriceLevelDropdown');
    if (editDropdown) {
        populatePriceLevelDropdown('editPriceLevelDropdown', editDropdown.getAttribute('data-selected-value') || '');
    }
}

// ============= ROUTE SALES REPORT (RSR) FUNCTIONS =============
function openRSRForm(customerId, storeName, address) {
    // Set the date to today
    const today = new Date().toISOString().split('T')[0];
    document.getElementById('rsrDate').value = today;
    document.getElementById('rsrCustomerId').value = customerId;
    document.getElementById('rsrStoreName').value = storeName || 'N/A';
    document.getElementById('rsrAddress').value = address || 'N/A';
    document.getElementById('rsrStatus').value = '';
    document.getElementById('rsrRemarks').value = '';
    
    // Close the customer details modal
    const viewCustomerModal = bootstrap.Modal.getInstance(document.getElementById('viewCustomerModal'));
    if (viewCustomerModal) {
        viewCustomerModal.hide();
    }
    
    // Show RSR modal
    const rsrModal = new bootstrap.Modal(document.getElementById('rsrModal'));
    rsrModal.show();
}

// Handle RSR form submission
document.addEventListener('DOMContentLoaded', function() {
    const rsrForm = document.getElementById('rsrForm');
    if (rsrForm) {
        rsrForm.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const customerId = document.getElementById('rsrCustomerId').value;
            const rsrDate = document.getElementById('rsrDate').value;
            const status = document.getElementById('rsrStatus').value;
            const remarks = document.getElementById('rsrRemarks').value;
            const storeName = document.getElementById('rsrStoreName').value;
            const address = document.getElementById('rsrAddress').value;
            
            // Validate required fields
            if (!customerId || !rsrDate || !status) {
                alert('Please fill in all required fields');
                return;
            }
            
            // Create FormData object
            const formData = new FormData();
            formData.append('action', 'save_rsr');
            formData.append('customer_id', customerId);
            formData.append('rsr_date', rsrDate);
            formData.append('status', status);
            formData.append('remarks', remarks);
            formData.append('store_name', storeName);
            formData.append('address', address);
            
            // Submit the form via AJAX
            fetch('customer_orderproduct.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Show success message
                    Swal.fire({
                        title: 'Success!',
                        text: 'Route Sales Report has been saved successfully.',
                        icon: 'success',
                        confirmButtonColor: '#0d6efd',
                        confirmButtonText: 'OK'
                    }).then((result) => {
                        if (result.isConfirmed) {
                            // Close the modal
                            const rsrModal = bootstrap.Modal.getInstance(document.getElementById('rsrModal'));
                            if (rsrModal) {
                                rsrModal.hide();
                            }
                            // Reload the page
                            location.reload();
                        }
                    });
                } else {
                    alert('Error: ' + (data.message || 'Failed to save Route Sales Report'));
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred while saving the report');
            });
        });
    }
});
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
    </script>
</body>
</html>