<?php
require_once '../config/database.php';
require_once '../config/session_handler.php';

// Protect page - only Rolling Account role can access
requireLogin();
<<<<<<< HEAD
requireRole(['rolling']);
=======
requireRole(['rolling_account']);
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928

// Check if payments table exists, if not create it
$check_payments = $conn->query("SHOW TABLES LIKE 'payments'");
if (!$check_payments || $check_payments->num_rows === 0) {
    $conn->query("CREATE TABLE IF NOT EXISTS `payments` (
        `payment_id` int(11) NOT NULL AUTO_INCREMENT,
        `invoice_id` int(11) NOT NULL,
        `customer_id` int(11) NOT NULL,
        `payment_method` enum('cash','check','online_transfer') NOT NULL,
        `amount` decimal(12,2) NOT NULL,
        `payment_date` datetime NOT NULL,
        `reference_number` varchar(100) DEFAULT NULL,
        `check_date` date DEFAULT NULL,
        `bank_name` varchar(100) DEFAULT NULL,
        `bank_branch` varchar(100) DEFAULT NULL,
        `check_number` varchar(50) DEFAULT NULL,
        `cash_tendered` decimal(12,2) DEFAULT NULL,
        `cash_change` decimal(12,2) DEFAULT NULL,
        `status` enum('completed','pending','failed') DEFAULT 'completed',
        `created_by` int(11) NOT NULL,
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`payment_id`),
        KEY `invoice_id` (`invoice_id`),
        KEY `customer_id` (`customer_id`),
        KEY `created_by` (`created_by`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
}

// Get current user info and branch context
$user_id = $_SESSION['user_id'] ?? 0;
$user_name = isset($_SESSION['first_name']) ? $_SESSION['first_name'] . ' ' . $_SESSION['last_name'] : 'Rolling Account';
$user_role = $_SESSION['role'] ?? 'rolling_account';
$branch_id = $_SESSION['rolling_branch_id'] ?? $_SESSION['branch_id'] ?? 0;
$view_all_branches = false; // Rolling accounts are restricted to their assigned branch

// Check if branch_id column exists in sales_orders table
$so_branch_column_exists = false;
$check_column = $conn->query("SHOW COLUMNS FROM sales_orders LIKE 'branch_id'");
if ($check_column && $check_column->num_rows > 0) {
    $so_branch_column_exists = true;
}

// Check if branch_id column exists in customers table
$customers_branch_column_exists = false;
$check_customers_column = $conn->query("SHOW COLUMNS FROM customers LIKE 'branch_id'");
if ($check_customers_column && $check_customers_column->num_rows > 0) {
    $customers_branch_column_exists = true;
}

// Check if branch_id column exists in drivers table
$drivers_branch_column_exists = false;
$check_drivers_column = $conn->query("SHOW COLUMNS FROM drivers LIKE 'branch_id'");
if ($check_drivers_column && $check_drivers_column->num_rows > 0) {
    $drivers_branch_column_exists = true;
}

// Check if so_id column exists in invoices table
$invoice_so_column_exists = false;
$check_invoice_column = $conn->query("SHOW COLUMNS FROM invoices LIKE 'so_id'");
if ($check_invoice_column && $check_invoice_column->num_rows > 0) {
    $invoice_so_column_exists = true;
}

<<<<<<< HEAD

// Check if collection_records table/columns exist for Completed Today tab
$collection_records_exists = false;
$collection_has_remitted_at = false;
$collection_has_approved_at = false;
$collection_has_collection_date = false;
$collection_has_created_at = false;
$check_collection_table = $conn->query("SHOW TABLES LIKE 'collection_records'");
if ($check_collection_table && $check_collection_table->num_rows > 0) {
    $collection_records_exists = true;
    $check_collection_remitted = $conn->query("SHOW COLUMNS FROM collection_records LIKE 'remitted_at'");
    $collection_has_remitted_at = ($check_collection_remitted && $check_collection_remitted->num_rows > 0);
    $check_collection_approved = $conn->query("SHOW COLUMNS FROM collection_records LIKE 'approved_at'");
    $collection_has_approved_at = ($check_collection_approved && $check_collection_approved->num_rows > 0);
    $check_collection_date = $conn->query("SHOW COLUMNS FROM collection_records LIKE 'collection_date'");
    $collection_has_collection_date = ($check_collection_date && $check_collection_date->num_rows > 0);
    $check_collection_created = $conn->query("SHOW COLUMNS FROM collection_records LIKE 'created_at'");
    $collection_has_created_at = ($check_collection_created && $check_collection_created->num_rows > 0);
}

=======
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
// Check if trip_tickets has additional columns
$trip_has_so_id = false;
$check_trip_so = $conn->query("SHOW COLUMNS FROM trip_tickets LIKE 'so_id'");
if ($check_trip_so && $check_trip_so->num_rows > 0) {
    $trip_has_so_id = true;
}

$trip_has_picklist_id = false;
$check_trip_picklist = $conn->query("SHOW COLUMNS FROM trip_tickets LIKE 'picklist_id'");
if ($check_trip_picklist && $check_trip_picklist->num_rows > 0) {
    $trip_has_picklist_id = true;
}

// Check/create vehicles table and trip_tickets.vehicle_id for separate vehicle assignment
$conn->query("CREATE TABLE IF NOT EXISTS `vehicles` (
    `vehicle_id` int(11) NOT NULL AUTO_INCREMENT,
    `branch_id` int(11) NOT NULL,
    `vehicle_type` varchar(100) NOT NULL,
    `plate_number` varchar(50) NOT NULL,
    `status` enum('active','inactive') NOT NULL DEFAULT 'active',
    `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
    `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    PRIMARY KEY (`vehicle_id`),
    UNIQUE KEY `uniq_vehicle_branch_plate` (`branch_id`, `plate_number`),
    KEY `idx_vehicle_branch_status` (`branch_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

$trip_has_vehicle_id = false;
$check_trip_vehicle = $conn->query("SHOW COLUMNS FROM trip_tickets LIKE 'vehicle_id'");
if ($check_trip_vehicle && $check_trip_vehicle->num_rows > 0) {
    $trip_has_vehicle_id = true;
} else {
    $conn->query("ALTER TABLE trip_tickets ADD COLUMN vehicle_id int(11) NULL AFTER driver_id");
    $conn->query("ALTER TABLE trip_tickets ADD KEY idx_trip_vehicle_id (vehicle_id)");
    $check_trip_vehicle = $conn->query("SHOW COLUMNS FROM trip_tickets LIKE 'vehicle_id'");
    if ($check_trip_vehicle && $check_trip_vehicle->num_rows > 0) {
        $trip_has_vehicle_id = true;
    }
}

$vehicles_branch_condition = "";
if (!$view_all_branches && $branch_id > 0) {
    $vehicles_branch_condition = "AND branch_id = $branch_id";
}

// Check if inventory_transactions table exists
$inventory_transactions_exists = false;
$check_inv_trans = $conn->query("SHOW TABLES LIKE 'inventory_transactions'");
if ($check_inv_trans && $check_inv_trans->num_rows > 0) {
    $inventory_transactions_exists = true;
}

// Check if invoices status includes 'overdue'
$invoice_has_overdue = false;
$check_invoice_status = $conn->query("SHOW COLUMNS FROM invoices LIKE 'status'");
if ($check_invoice_status && $check_invoice_status->num_rows > 0) {
    $row = $check_invoice_status->fetch_assoc();
    $enum = $row['Type'];
    if (strpos($enum, 'overdue') === false) {
        // Provide alert later
    } else {
        $invoice_has_overdue = true;
    }
}

// Determine branch filter condition
$branch_condition = "";
if ($so_branch_column_exists && !$view_all_branches) {
    $branch_condition = "AND so.branch_id = $branch_id";
}
<<<<<<< HEAD
// Rolling account can only access sales orders encoded by the logged-in Rolling user.
$rolling_owner_condition = " AND so.created_by = " . intval($user_id);
$branch_condition .= $rolling_owner_condition;
=======
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928

$customers_branch_condition = "";
if ($customers_branch_column_exists && !$view_all_branches) {
    $customers_branch_condition = "AND branch_id = $branch_id";
}

$drivers_branch_condition = "";
if ($drivers_branch_column_exists && !$view_all_branches) {
    $drivers_branch_condition = "AND branch_id = $branch_id";
}

<<<<<<< HEAD

// ------------------------------------------------------------
// Rolling Serve/Deliver helpers
// ------------------------------------------------------------
if (!function_exists('rollingSoColumnExists')) {
    function rollingSoColumnExists($conn, $table, $column) {
        $table = preg_replace('/[^A-Za-z0-9_]/', '', $table);
        $column = $conn->real_escape_string($column);
        $res = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
        return ($res && $res->num_rows > 0);
    }
}

if (!function_exists('rollingSoTableExists')) {
    function rollingSoTableExists($conn, $table) {
        $table = $conn->real_escape_string($table);
        $res = $conn->query("SHOW TABLES LIKE '$table'");
        return ($res && $res->num_rows > 0);
    }
}

if (!function_exists('rollingSoInsertDynamic')) {
    function rollingSoInsertDynamic($conn, $table, $fields) {
        $safeTable = preg_replace('/[^A-Za-z0-9_]/', '', $table);
        $cols = [];
        $placeholders = [];
        $types = '';
        $values = [];

        foreach ($fields as $col => $val) {
            if (!rollingSoColumnExists($conn, $safeTable, $col)) continue;
            $cols[] = '`' . str_replace('`', '``', $col) . '`';
            if ($val === '__NOW__') {
                $placeholders[] = 'NOW()';
                continue;
            }
            $placeholders[] = '?';
            if (is_int($val)) $types .= 'i';
            elseif (is_float($val) || is_double($val)) $types .= 'd';
            else $types .= 's';
            $values[] = $val;
        }

        if (empty($cols)) {
            throw new Exception('No valid columns found for ' . $safeTable);
        }

        $sql = "INSERT INTO `$safeTable` (" . implode(',', $cols) . ") VALUES (" . implode(',', $placeholders) . ")";
        $stmt = $conn->prepare($sql);
        if (!$stmt) throw new Exception('Insert prepare failed for ' . $safeTable . ': ' . $conn->error);
        if (!empty($values)) $stmt->bind_param($types, ...$values);
        if (!$stmt->execute()) throw new Exception('Insert failed for ' . $safeTable . ': ' . $stmt->error);
        $id = (int)$conn->insert_id;
        $stmt->close();
        return $id;
    }
}

if (!function_exists('ensureRollingServeCollectionTables')) {
    function ensureRollingServeCollectionTables($conn) {
        $conn->query("CREATE TABLE IF NOT EXISTS `collection_records` (
            `record_id` INT AUTO_INCREMENT PRIMARY KEY,
            `invoice_id` INT NOT NULL DEFAULT 0,
            `customer_id` INT NOT NULL DEFAULT 0,
            `branch_id` INT NOT NULL DEFAULT 0,
            `collector_user_id` INT NOT NULL DEFAULT 0,
            `payment_method` VARCHAR(40) NOT NULL DEFAULT 'cash',
            `amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `collection_date` DATETIME NOT NULL,
            `reference_number` VARCHAR(100) DEFAULT NULL,
            `check_date` DATE DEFAULT NULL,
            `bank_name` VARCHAR(150) DEFAULT NULL,
            `bank_branch` VARCHAR(150) DEFAULT NULL,
            `check_number` VARCHAR(100) DEFAULT NULL,
            `cash_tendered` DECIMAL(12,2) DEFAULT NULL,
            `cash_change` DECIMAL(12,2) DEFAULT NULL,
            `notes` TEXT DEFAULT NULL,
            `status` VARCHAR(30) NOT NULL DEFAULT 'collected',
            `remitted_at` DATETIME DEFAULT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY `invoice_id` (`invoice_id`),
            KEY `collector_user_id` (`collector_user_id`),
            KEY `branch_id` (`branch_id`),
            KEY `status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        $extra = [
            'reference_number' => "ALTER TABLE collection_records ADD COLUMN reference_number VARCHAR(100) DEFAULT NULL AFTER collection_date",
            'check_date' => "ALTER TABLE collection_records ADD COLUMN check_date DATE DEFAULT NULL AFTER reference_number",
            'bank_name' => "ALTER TABLE collection_records ADD COLUMN bank_name VARCHAR(150) DEFAULT NULL AFTER check_date",
            'bank_branch' => "ALTER TABLE collection_records ADD COLUMN bank_branch VARCHAR(150) DEFAULT NULL AFTER bank_name",
            'check_number' => "ALTER TABLE collection_records ADD COLUMN check_number VARCHAR(100) DEFAULT NULL AFTER bank_branch",
            'cash_tendered' => "ALTER TABLE collection_records ADD COLUMN cash_tendered DECIMAL(12,2) DEFAULT NULL AFTER check_number",
            'cash_change' => "ALTER TABLE collection_records ADD COLUMN cash_change DECIMAL(12,2) DEFAULT NULL AFTER cash_tendered",
            'notes' => "ALTER TABLE collection_records ADD COLUMN notes TEXT DEFAULT NULL AFTER cash_change",
            'remitted_at' => "ALTER TABLE collection_records ADD COLUMN remitted_at DATETIME DEFAULT NULL AFTER status"
        ];
        foreach ($extra as $col => $sql) {
            if (!rollingSoColumnExists($conn, 'collection_records', $col)) @$conn->query($sql);
        }
        @$conn->query("ALTER TABLE collection_records MODIFY COLUMN status VARCHAR(30) NOT NULL DEFAULT 'collected'");
    }
}

=======
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
// ------------------------------------------------------------
// Helper: Recalculate customer credit_used based on all unpaid invoices
// ------------------------------------------------------------
function recalcCustomerCreditUsed($conn, $customer_id) {
    $sql = "SELECT COALESCE(SUM(total_amount), 0) AS total_unpaid
            FROM invoices
            WHERE customer_id = ?
            AND (
                status IS NULL
                OR TRIM(status) = ''
                OR status IN ('pending', 'overdue')
            )";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $customer_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res->fetch_assoc();
    $unpaid = floatval($row['total_unpaid'] ?? 0);

    $update = "UPDATE customers SET credit_used = ? WHERE customer_id = ?";
    $upd_stmt = $conn->prepare($update);
    $upd_stmt->bind_param("di", $unpaid, $customer_id);
    $upd_stmt->execute();

    return $unpaid;
}

// ------------------------------------------------------------
// Helper: Get latest active approved credit request
// ------------------------------------------------------------
function getActiveApprovedCreditRequest($conn, $customer_id) {
    $checkTable = $conn->query("SHOW TABLES LIKE 'credit_discount_requests'");
    if (!$checkTable || $checkTable->num_rows === 0) {
        return null;
    }

    $sql = "SELECT request_id, request_type, requested_credit_limit, requested_discount_percent,
                   credit_terms_days, effective_from, effective_until, created_at
            FROM credit_discount_requests
            WHERE customer_id = ?
              AND status = 'approved'
              AND (effective_from IS NULL OR effective_from <= NOW())
              AND (effective_until IS NULL OR effective_until >= NOW())
              AND request_type IN ('credit', 'credit_terms', 'both')
            ORDER BY
                CASE WHEN effective_from IS NULL THEN 1 ELSE 0 END ASC,
                effective_from DESC,
                created_at DESC,
                request_id DESC
            LIMIT 1";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param("i", $customer_id);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($row = $res->fetch_assoc()) {
        return $row;
    }

    return null;
}

// ------------------------------------------------------------
// Helper: Get customer's active approved credit terms
// ------------------------------------------------------------
function getCustomerCreditTerms($conn, $customer_id) {
    $active_request = getActiveApprovedCreditRequest($conn, $customer_id);
    if ($active_request && !empty($active_request['credit_terms_days'])) {
        return (int)$active_request['credit_terms_days'];
    }
    return 30;
}

// ------------------------------------------------------------
// Helper: Get effective credit limit
// ------------------------------------------------------------
function getEffectiveCustomerCreditLimit($conn, $customer_id) {
    $customer_limit = 0;

    $sql = "SELECT credit_limit FROM customers WHERE customer_id = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $customer_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($cust = $res->fetch_assoc()) {
        $customer_limit = floatval($cust['credit_limit'] ?? 0);
    }

    $active_request = getActiveApprovedCreditRequest($conn, $customer_id);
    if ($active_request && isset($active_request['requested_credit_limit']) && $active_request['requested_credit_limit'] !== null) {
        $requested_limit = floatval($active_request['requested_credit_limit']);
        if ($requested_limit > 0) {
            return $requested_limit;
        }
    }

    return $customer_limit;
}

// ------------------------------------------------------------
// Helper: Build customer credit snapshot
// ------------------------------------------------------------
function getCustomerCreditSnapshot($conn, $customer_id, $additional_amount = 0.00) {
    $credit_used = recalcCustomerCreditUsed($conn, $customer_id);
    $credit_limit = getEffectiveCustomerCreditLimit($conn, $customer_id);
    $projected_used = $credit_used + max(0, floatval($additional_amount));
    $remaining_credit = $credit_limit - $credit_used;
    $projected_remaining = $credit_limit - $projected_used;
    $has_limit = $credit_limit > 0;
    $is_over_limit_now = $has_limit && $credit_used > $credit_limit;
    $will_exceed_on_confirm = $has_limit && $projected_used > $credit_limit;

    return [
        'credit_limit' => $credit_limit,
        'credit_used' => $credit_used,
        'projected_credit_used' => $projected_used,
        'remaining_credit' => $remaining_credit,
        'projected_remaining_credit' => $projected_remaining,
        'is_over_limit_now' => $is_over_limit_now,
        'will_exceed_on_confirm' => $will_exceed_on_confirm,
        'active_request' => getActiveApprovedCreditRequest($conn, $customer_id)
    ];
}

// ------------------------------------------------------------
// Helper: Update overdue invoices
// ------------------------------------------------------------
function updateOverdueInvoices($conn) {
    $sql = "UPDATE invoices
            SET status = 'overdue'
            WHERE due_date < CURDATE()
              AND (
                    status IS NULL
                    OR TRIM(status) = ''
                    OR status = 'pending'
                  )";
    $conn->query($sql);
}

// Run overdue update at page start
updateOverdueInvoices($conn);

// ========== SYNC SALES ORDER STATUS WITH DELIVERY STATUS ==========
// Update sales order status to 'in_transit' if there's an in-transit delivery
$sync_in_transit = "
    UPDATE sales_orders so
    SET so.order_status = 'in_transit'
    WHERE so.order_status != 'delivered' 
      AND so.order_status != 'cancelled'
      AND EXISTS (
          SELECT 1 
          FROM deliveries d 
          WHERE d.so_id = so.so_id 
          AND d.delivery_status = 'in-transit'
      )
";
$conn->query($sync_in_transit);

// Also update sales order status to 'delivered' if delivery is completed
$sync_delivered = "
    UPDATE sales_orders so
    SET so.order_status = 'delivered'
    WHERE so.order_status != 'delivered'
      AND so.order_status != 'cancelled'
      AND EXISTS (
          SELECT 1 
          FROM deliveries d 
          WHERE d.so_id = so.so_id 
          AND d.delivery_status = 'delivered'
      )
";
$conn->query($sync_delivered);

// Calculate current week sales for default stat card (Monday to Saturday)
$current_week_sales_query = "
    SELECT COALESCE(SUM(total_amount), 0) as week_total 
    FROM sales_orders 
    WHERE order_status IN ('delivered', 'confirmed', 'processing', 'ready')
    AND YEARWEEK(created_at, 1) = YEARWEEK(NOW(), 1)
    AND DATE(created_at) <= CURDATE()
";

if ($so_branch_column_exists && !$view_all_branches && $branch_id > 0) {
    $current_week_sales_query .= " AND branch_id = $branch_id";
}
<<<<<<< HEAD
$current_week_sales_query .= " AND created_by = " . intval($user_id);
=======
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928

$current_week_sales_result = $conn->query($current_week_sales_query);
$current_week_sales = $current_week_sales_result->fetch_assoc()['week_total'] ?? 0;
$statCurrentWeekSales = '₱' . number_format((float)$current_week_sales, 2);

// Get current week start and end dates for display (Monday to Saturday)
$week_start = new DateTime();
$week_start->modify('monday this week');
$week_end = new DateTime();
$week_end->modify('saturday this week');
$week_start_str = $week_start->format('Y-m-d');
$week_end_str = $week_end->format('Y-m-d');

// Set default date range to current week (Monday to Saturday)
$default_start_date = $week_start_str;
$default_end_date = $week_end_str;

// ========== GET AVAILABLE DRIVERS FOR DROPDOWN ==========
$available_drivers_query = "
    SELECT 
        d.driver_id, 
        d.driver_name,
        d.status,
        (
            SELECT COUNT(*) 
            FROM pick_lists pl 
            JOIN sales_orders so ON pl.so_id = so.so_id 
            WHERE pl.driver_id = d.driver_id 
            AND so.order_status IN ('confirmed', 'processing', 'ready', 'in_transit')
            AND pl.pick_status NOT IN ('completed', 'cancelled')
        ) as pending_deliveries,
        (
            SELECT COUNT(*) 
            FROM trip_tickets tt 
            WHERE tt.driver_id = d.driver_id 
            AND tt.trip_status = 'in-progress'
        ) as active_trips
    FROM drivers d
    WHERE d.status = 'active'
";

if ($drivers_branch_column_exists && !$view_all_branches && $branch_id > 0) {
    $available_drivers_query .= " AND d.branch_id = $branch_id";
}

$available_drivers_query .= " HAVING active_trips = 0 ORDER BY pending_deliveries DESC, d.driver_name";

$available_drivers_result = $conn->query($available_drivers_query);
$available_drivers = $available_drivers_result ? $available_drivers_result->fetch_all(MYSQLI_ASSOC) : [];

$drivers_with_pending = array_filter($available_drivers, function($d) { 
    return $d['pending_deliveries'] > 0; 
});
$available_drivers_without_pending = array_filter($available_drivers, function($d) { 
    return $d['pending_deliveries'] == 0; 
});

// ========== GET AVAILABLE VEHICLES FOR DROPDOWN ==========
$available_vehicles_query = "
    SELECT 
        v.vehicle_id,
        v.vehicle_type,
        v.plate_number,
        v.status,
        (
            SELECT COUNT(*)
            FROM trip_tickets tt
            WHERE tt.vehicle_id = v.vehicle_id
            AND tt.trip_status = 'in-progress'
        ) as active_trips
    FROM vehicles v
    WHERE v.status = 'active'
    $vehicles_branch_condition
    HAVING active_trips = 0
    ORDER BY v.vehicle_type ASC, v.plate_number ASC
";
$available_vehicles_result = $conn->query($available_vehicles_query);
$available_vehicles = $available_vehicles_result ? $available_vehicles_result->fetch_all(MYSQLI_ASSOC) : [];

// ========== HANDLE AJAX REQUESTS ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    try {
<<<<<<< HEAD
        // View-only page: Rolling can view/print records only. No edit, delete, or invoice-status updates allowed.
        $readonly_actions = ['update_order', 'delete_order', 'update_invoice_status'];
        if (in_array($_POST['action'], $readonly_actions, true)) {
            echo json_encode([
                'success' => false,
                'message' => 'This page is view-only. Editing, deleting, and status updates are disabled for Rolling accounts.'
            ]);
            exit;
        }

        $conn->begin_transaction();

        // SERVE / DELIVER PENDING ORDER WITH COLLECTION RECORD
        if ($_POST['action'] === 'serve_deliver_order') {
            ensureRollingServeCollectionTables($conn);

            $so_id = (int)($_POST['so_id'] ?? 0);
            $payment_method = trim($_POST['payment_method'] ?? 'cash');
            $amount = isset($_POST['collection_amount']) ? (float)$_POST['collection_amount'] : 0.00;
            $reference_number = trim($_POST['reference_number'] ?? '');
            $check_date = trim($_POST['check_date'] ?? '');
            $bank_name = trim($_POST['bank_name'] ?? '');
            $bank_branch = trim($_POST['bank_branch'] ?? '');
            $check_number = trim($_POST['check_number'] ?? '');
            $cash_tendered = isset($_POST['cash_tendered']) && $_POST['cash_tendered'] !== '' ? (float)$_POST['cash_tendered'] : null;
            $cash_change = isset($_POST['cash_change']) && $_POST['cash_change'] !== '' ? (float)$_POST['cash_change'] : 0.00;

            if ($so_id <= 0) throw new Exception('Invalid sales order.');
            if (!in_array($payment_method, ['cash', 'check', 'online_transfer'], true)) {
                throw new Exception('Invalid payment method.');
            }
            if ($amount <= 0) {
                throw new Exception('Please enter the collected amount before serving this order.');
            }
            if ($payment_method === 'check') {
                if ($bank_name === '' || $check_number === '' || $check_date === '') {
                    throw new Exception('Bank name, check number, and check date are required for check payment.');
                }
                if ($reference_number === '') $reference_number = $check_number;
            }
            if ($payment_method === 'online_transfer') {
                if ($bank_name === '' || $reference_number === '') {
                    throw new Exception('Bank/Wallet and reference number are required for online transfer.');
                }
            }

            $order_query = "SELECT so.so_id, so.so_number, so.customer_id, so.branch_id, so.total_amount, so.order_status, so.created_by,
                                   c.customer_name
                            FROM sales_orders so
                            LEFT JOIN customers c ON c.customer_id = so.customer_id
                            WHERE so.so_id = ? AND so.created_by = ?";
            $types = 'ii';
            $params = [$so_id, $user_id];
            if ($so_branch_column_exists && !$view_all_branches) {
                $order_query .= " AND so.branch_id = ?";
                $types .= 'i';
                $params[] = $branch_id;
            }
            $order_query .= " LIMIT 1";
            $order_stmt = $conn->prepare($order_query);
            if (!$order_stmt) throw new Exception('Failed to prepare order lookup: ' . $conn->error);
            $order_stmt->bind_param($types, ...$params);
            $order_stmt->execute();
            $order = $order_stmt->get_result()->fetch_assoc();
            $order_stmt->close();

            if (!$order) throw new Exception('Sales order not found or access denied.');
            $current_status = strtolower(trim((string)$order['order_status']));
            if (in_array($current_status, ['delivered', 'completed', 'cancelled'], true)) {
                throw new Exception('This order is already delivered/completed/cancelled.');
            }

            $total_amount = (float)($order['total_amount'] ?? 0);
            $customer_id = (int)($order['customer_id'] ?? 0);
            $order_branch_id = (int)($order['branch_id'] ?? $branch_id);
            $so_number = (string)($order['so_number'] ?? ('SO-' . $so_id));

            if ($payment_method !== 'cash' && $amount > $total_amount + 0.01) {
                throw new Exception('Collected amount cannot be greater than the order total for check/online transfer.');
            }

            $invoice_id = 0;
            if ($invoice_so_column_exists) {
                $invoice_stmt = $conn->prepare("SELECT invoice_id, invoice_number, total_amount, status FROM invoices WHERE so_id = ? ORDER BY invoice_id DESC LIMIT 1");
                if ($invoice_stmt) {
                    $invoice_stmt->bind_param('i', $so_id);
                    $invoice_stmt->execute();
                    $invoice_row = $invoice_stmt->get_result()->fetch_assoc();
                    $invoice_stmt->close();
                    if ($invoice_row) $invoice_id = (int)$invoice_row['invoice_id'];
                }
            }

            if ($invoice_id <= 0 && rollingSoTableExists($conn, 'invoices')) {
                $invoice_number = 'INV-' . date('Ymd') . '-' . str_pad((string)$so_id, 5, '0', STR_PAD_LEFT);
                $invoice_id = rollingSoInsertDynamic($conn, 'invoices', [
                    'invoice_number' => $invoice_number,
                    'so_id' => $so_id,
                    'customer_id' => $customer_id,
                    'branch_id' => $order_branch_id,
                    'invoice_date' => date('Y-m-d'),
                    'due_date' => date('Y-m-d'),
                    'total_amount' => $total_amount,
                    'amount_paid' => 0.00,
                    'balance' => $total_amount,
                    'status' => 'pending',
                    'created_at' => '__NOW__',
                    'updated_at' => '__NOW__'
                ]);
            }

            if ($invoice_id <= 0) {
                throw new Exception('Invoice record not found and cannot be created.');
            }

            // Save a collection record only. Do NOT mark invoice as paid here.
            // Payment becomes official only after Rolling remits and Branch Admin approves.
            rollingSoInsertDynamic($conn, 'collection_records', [
                'invoice_id' => $invoice_id,
                'customer_id' => $customer_id,
                'branch_id' => $order_branch_id,
                'collector_user_id' => (int)$user_id,
                'payment_method' => $payment_method,
                'amount' => $amount,
                'collection_date' => '__NOW__',
                'reference_number' => $reference_number,
                'check_date' => $check_date !== '' ? $check_date : null,
                'bank_name' => $bank_name,
                'bank_branch' => $bank_branch,
                'check_number' => $check_number,
                'cash_tendered' => $payment_method === 'cash' ? $cash_tendered : null,
                'cash_change' => $payment_method === 'cash' ? $cash_change : null,
                'notes' => 'Serve/Deliver collection from Rolling Sales Order ' . $so_number,
                'status' => 'collected',
                'created_at' => '__NOW__'
            ]);

            $update_order = $conn->prepare("UPDATE sales_orders SET order_status = 'delivered', updated_at = NOW() WHERE so_id = ? AND created_by = ?");
            if (!$update_order) throw new Exception('Failed to prepare delivery update: ' . $conn->error);
            $update_order->bind_param('ii', $so_id, $user_id);
            if (!$update_order->execute()) throw new Exception('Failed to mark order as delivered.');
            $update_order->close();

            // Keep invoice as pending/partial until Branch Admin approves remittance.
            if (rollingSoTableExists($conn, 'invoices') && rollingSoColumnExists($conn, 'invoices', 'status')) {
                $keep_invoice = $conn->prepare("UPDATE invoices SET status = CASE WHEN status = 'cancelled' THEN 'cancelled' WHEN status = 'paid' THEN 'paid' ELSE 'pending' END" . (rollingSoColumnExists($conn, 'invoices', 'updated_at') ? ", updated_at = NOW()" : "") . " WHERE invoice_id = ?");
                if ($keep_invoice) {
                    $keep_invoice->bind_param('i', $invoice_id);
                    $keep_invoice->execute();
                    $keep_invoice->close();
                }
            }

            $conn->commit();
            echo json_encode([
                'success' => true,
                'message' => 'Order served and marked as delivered. Collection was saved for remittance approval.',
                'so_id' => $so_id,
                'invoice_id' => $invoice_id
            ]);
            exit;
        }
=======
        $conn->begin_transaction();
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
        
        // UPDATE SALES ORDER
        if ($_POST['action'] === 'update_order') {
            $so_id = (int)$_POST['so_id'];
            $created_at = $_POST['created_at'];
            $order_status = $_POST['order_status'];
            $total_amount = (float)$_POST['total_amount'];
            $selected_driver_id = isset($_POST['driver_id']) && !empty($_POST['driver_id']) ? (int)$_POST['driver_id'] : null;
            $selected_vehicle_id = isset($_POST['vehicle_id']) && !empty($_POST['vehicle_id']) ? (int)$_POST['vehicle_id'] : null;
            
<<<<<<< HEAD
            $status_query = "SELECT order_status, customer_id, branch_id, so_number FROM sales_orders WHERE so_id = ? AND created_by = ?";
            $status_stmt = $conn->prepare($status_query);
            $status_stmt->bind_param("ii", $so_id, $user_id);
            $status_stmt->execute();
            $order_info = $status_stmt->get_result()->fetch_assoc();
            if (!$order_info) {
                throw new Exception('Order not found or access denied');
            }
=======
            $status_query = "SELECT order_status, customer_id, branch_id, so_number FROM sales_orders WHERE so_id = ?";
            $status_stmt = $conn->prepare($status_query);
            $status_stmt->bind_param("i", $so_id);
            $status_stmt->execute();
            $order_info = $status_stmt->get_result()->fetch_assoc();
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
            $old_status = $order_info['order_status'];
            $order_branch_id = $order_info['branch_id'];
            $customer_id = $order_info['customer_id'];
            
            if ($so_branch_column_exists && !$view_all_branches) {
<<<<<<< HEAD
                $check_query = "SELECT so_id FROM sales_orders WHERE so_id = ? AND branch_id = ? AND created_by = ?";
                $check_stmt = $conn->prepare($check_query);
                $check_stmt->bind_param("iii", $so_id, $branch_id, $user_id);
=======
                $check_query = "SELECT so_id FROM sales_orders WHERE so_id = ? AND branch_id = ?";
                $check_stmt = $conn->prepare($check_query);
                $check_stmt->bind_param("ii", $so_id, $branch_id);
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                
                if ($check_result->num_rows === 0) {
                    throw new Exception('Order not found or access denied');
                }
            }
            
            $beyond_credit_required = false;
            $beyond_credit_explanation = trim($_POST['beyond_credit_explanation'] ?? '');
            $beyond_credit_acknowledged = isset($_POST['beyond_credit_acknowledged']) && $_POST['beyond_credit_acknowledged'] === '1';
            $beyond_credit_snapshot = null;

            // If status is being changed to confirmed, check effective credit limit including this order amount.
            // Beyond-limit orders are allowed only after explanation + acknowledgement.
            if ($order_status === 'confirmed' && $old_status === 'pending') {
                $credit_snapshot = getCustomerCreditSnapshot($conn, $customer_id, $total_amount);

                if ($credit_snapshot['will_exceed_on_confirm']) {
                    $beyond_credit_required = true;
                    $active_limit_text = $credit_snapshot['active_request'] && isset($credit_snapshot['active_request']['requested_credit_limit'])
                        ? '<div class="small text-muted mt-2">Active approved credit request applied.</div>'
                        : '';

                    $credit_html = '<div class="text-start">' .
                                  '<p class="mb-2"><strong>This order is beyond the customer credit limit.</strong></p>' .
                                  '<p class="mb-2 text-muted">Please provide an explanation and tick the acknowledgement box to continue confirmation.</p>' .
                                  '<hr class="my-2">' .
                                  '<div class="d-flex justify-content-between mb-1"><span>Credit Limit:</span><span class="fw-bold">₱' . number_format($credit_snapshot['credit_limit'], 2) . '</span></div>' .
                                  '<div class="d-flex justify-content-between mb-1"><span>Current Credit Used:</span><span class="fw-bold text-danger">₱' . number_format($credit_snapshot['credit_used'], 2) . '</span></div>' .
                                  '<div class="d-flex justify-content-between mb-1"><span>This Order Amount:</span><span class="fw-bold">₱' . number_format($total_amount, 2) . '</span></div>' .
                                  '<div class="d-flex justify-content-between pt-1 mt-1 border-top"><span class="fw-bold">Projected Credit Used:</span><span class="fw-bold text-danger">₱' . number_format($credit_snapshot['projected_credit_used'], 2) . '</span></div>' .
                                  $active_limit_text .
                                  '</div>';

                    if ($beyond_credit_explanation === '' || !$beyond_credit_acknowledged) {
                        throw new Exception(json_encode([
                            'type' => 'credit_limit_required',
                            'title' => 'Beyond Credit Limit Approval Required',
                            'html' => $credit_html,
                            'credit_limit' => $credit_snapshot['credit_limit'],
                            'credit_used' => $credit_snapshot['credit_used'],
                            'projected_credit_used' => $credit_snapshot['projected_credit_used']
                        ]));
                    }

                    $beyond_credit_snapshot = json_encode([
                        'credit_limit' => $credit_snapshot['credit_limit'],
                        'credit_used_before_confirmation' => $credit_snapshot['credit_used'],
                        'order_amount' => $total_amount,
                        'projected_credit_used' => $credit_snapshot['projected_credit_used'],
                        'projected_remaining_credit' => $credit_snapshot['projected_remaining_credit'],
                        'confirmed_by' => $user_id,
                        'confirmed_at' => date('Y-m-d H:i:s')
                    ], JSON_UNESCAPED_UNICODE);
                }
            }
            
            if ($beyond_credit_required) {
                $update_query = "UPDATE sales_orders 
                               SET created_at = ?, order_status = ?, total_amount = ?, updated_at = NOW(),
                                   beyond_credit_limit_allowed = 1,
                                   beyond_credit_limit_explanation = ?,
                                   beyond_credit_limit_acknowledged = 1,
                                   beyond_credit_limit_allowed_by = ?,
                                   beyond_credit_limit_allowed_at = NOW(),
                                   beyond_credit_limit_snapshot = ?
                               WHERE so_id = ?";
                $update_stmt = $conn->prepare($update_query);
                $update_stmt->bind_param("ssdsisi", $created_at, $order_status, $total_amount, $beyond_credit_explanation, $user_id, $beyond_credit_snapshot, $so_id);
            } else {
                $update_query = "UPDATE sales_orders 
                               SET created_at = ?, order_status = ?, total_amount = ?, updated_at = NOW() 
                               WHERE so_id = ?";
                $update_stmt = $conn->prepare($update_query);
                $update_stmt->bind_param("ssdi", $created_at, $order_status, $total_amount, $so_id);
            }
                        if (!$update_stmt->execute()) {
                throw new Exception('Failed to update sales order');
            }
            
            if ($order_status === 'confirmed' && $old_status === 'pending') {
                if (!$selected_driver_id) {
                    throw new Exception('Please select a driver for this delivery');
                }
                if (!$selected_vehicle_id) {
                    throw new Exception('Please select a vehicle for this delivery');
                }

                $check_driver_query = "SELECT driver_id, driver_name FROM drivers WHERE driver_id = ? AND status = 'active'";
                if ($drivers_branch_column_exists && !$view_all_branches) {
                    $check_driver_query .= " AND branch_id = ?";
                    $check_driver_stmt = $conn->prepare($check_driver_query);
                    $check_driver_stmt->bind_param("ii", $selected_driver_id, $order_branch_id);
                } else {
                    $check_driver_stmt = $conn->prepare($check_driver_query);
                    $check_driver_stmt->bind_param("i", $selected_driver_id);
                }
                
                $check_driver_stmt->execute();
                $driver_result = $check_driver_stmt->get_result();
                
                if ($driver_result->num_rows === 0) {
                    throw new Exception('Selected driver is not available or does not belong to this branch');
                }
                
                $driver_data = $driver_result->fetch_assoc();
                $driver_name = $driver_data['driver_name'];

                $check_vehicle_query = "SELECT vehicle_id, vehicle_type, plate_number FROM vehicles WHERE vehicle_id = ? AND status = 'active'";
                if (!$view_all_branches) {
                    $check_vehicle_query .= " AND branch_id = ?";
                    $check_vehicle_stmt = $conn->prepare($check_vehicle_query);
                    $check_vehicle_stmt->bind_param("ii", $selected_vehicle_id, $order_branch_id);
                } else {
                    $check_vehicle_stmt = $conn->prepare($check_vehicle_query);
                    $check_vehicle_stmt->bind_param("i", $selected_vehicle_id);
                }
                $check_vehicle_stmt->execute();
                $vehicle_result = $check_vehicle_stmt->get_result();
                if ($vehicle_result->num_rows === 0) {
                    throw new Exception('Selected vehicle is not available or does not belong to this branch');
                }
                $vehicle_data = $vehicle_result->fetch_assoc();
                $vehicle_display = trim(($vehicle_data['vehicle_type'] ?? '') . ' - ' . ($vehicle_data['plate_number'] ?? ''));

                $pick_list_number = 'PL-' . date('Ymd') . '-' . str_pad($so_id, 5, '0', STR_PAD_LEFT);
                
                $picklist_query = "INSERT INTO pick_lists (pick_list_number, so_id, branch_id, driver_id, pick_status, created_at) 
                                  VALUES (?, ?, ?, ?, 'open', NOW())";
                $picklist_stmt = $conn->prepare($picklist_query);
                $picklist_stmt->bind_param("siii", $pick_list_number, $so_id, $order_branch_id, $selected_driver_id);
                
                if (!$picklist_stmt->execute()) {
                    throw new Exception('Failed to create pick list');
                }
                $picklist_id = $conn->insert_id;
                
                $items_query = "SELECT item_id, quantity_ordered FROM sales_order_items WHERE so_id = ?";
                $items_stmt = $conn->prepare($items_query);
                $items_stmt->bind_param("i", $so_id);
                $items_stmt->execute();
                $items_result = $items_stmt->get_result();
                
                $pick_items_query = "INSERT INTO pick_list_items (pick_list_id, item_id, quantity_to_pick) VALUES (?, ?, ?)";
                $pick_items_stmt = $conn->prepare($pick_items_query);
                
                while ($item = $items_result->fetch_assoc()) {
                    $pick_items_stmt->bind_param("iii", $picklist_id, $item['item_id'], $item['quantity_ordered']);
                    $pick_items_stmt->execute();
                }
                
                if ($invoice_so_column_exists) {
                    $invoice_number = 'INV-' . date('Ymd') . '-' . str_pad($so_id, 5, '0', STR_PAD_LEFT);
                    $invoice_date = date('Y-m-d');
                    $terms_days = getCustomerCreditTerms($conn, $customer_id);
                    $due_date = date('Y-m-d', strtotime("+$terms_days days"));
                    
                    $invoice_query = "INSERT INTO invoices (invoice_number, so_id, customer_id, branch_id, invoice_date, due_date, total_amount, status) 
                                     VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')";
                    $invoice_stmt = $conn->prepare($invoice_query);
                    $invoice_stmt->bind_param("siiissd", $invoice_number, $so_id, $customer_id, $order_branch_id, $invoice_date, $due_date, $total_amount);
                    
                    if (!$invoice_stmt->execute()) {
                        throw new Exception('Failed to create invoice');
                    }
                    
                    recalcCustomerCreditUsed($conn, $customer_id);
                }
                
                $trip_ticket_number = 'TT-' . date('Ymd') . '-' . str_pad($so_id, 5, '0', STR_PAD_LEFT);
                $trip_date = date('Y-m-d');
                
                $trip_fields = "trip_number, driver_id, branch_id, trip_date, trip_status, created_by, created_at";
                $trip_values = "?, ?, ?, ?, 'planned', ?, NOW()";
                $trip_types = "siisi";
                $trip_params = [$trip_ticket_number, $selected_driver_id, $order_branch_id, $trip_date, $user_id];

                if ($trip_has_vehicle_id) {
                    $trip_fields .= ", vehicle_id";
                    $trip_values .= ", ?";
                    $trip_types .= "i";
                    $trip_params[] = $selected_vehicle_id;
                }

                if ($trip_has_so_id) {
                    $trip_fields .= ", so_id";
                    $trip_values .= ", ?";
                    $trip_types .= "i";
                    $trip_params[] = $so_id;
                }
                
                if ($trip_has_picklist_id) {
                    $trip_fields .= ", picklist_id";
                    $trip_values .= ", ?";
                    $trip_types .= "i";
                    $trip_params[] = $picklist_id;
                }
                
                $trip_ticket_query = "INSERT INTO trip_tickets ($trip_fields) VALUES ($trip_values)";
                $trip_ticket_stmt = $conn->prepare($trip_ticket_query);
                $trip_ticket_stmt->bind_param($trip_types, ...$trip_params);
                
                if (!$trip_ticket_stmt->execute()) {
                    throw new Exception('Failed to create trip ticket: ' . $trip_ticket_stmt->error);
                }
                
                $items_query = "SELECT item_id, quantity_ordered FROM sales_order_items WHERE so_id = ?";
                $items_stmt = $conn->prepare($items_query);
                $items_stmt->bind_param("i", $so_id);
                $items_stmt->execute();
                $items_result = $items_stmt->get_result();
                
                while ($item = $items_result->fetch_assoc()) {
                    $item_id = $item['item_id'];
                    $quantity = $item['quantity_ordered'];
                    
                    $stock_query = "SELECT stock FROM items WHERE item_id = ?";
                    $stock_stmt = $conn->prepare($stock_query);
                    $stock_stmt->bind_param("i", $item_id);
                    $stock_stmt->execute();
                    $stock_result = $stock_stmt->get_result();
                    $current_stock = $stock_result->fetch_assoc()['stock'];
                    
                    if ($current_stock < $quantity) {
                        throw new Exception("Insufficient stock for item ID: $item_id. Available: $current_stock, Required: $quantity");
                    }
                    
                    $new_stock = $current_stock - $quantity;
                    $update_stock_query = "UPDATE items SET stock = ?, updated_at = NOW() WHERE item_id = ?";
                    $update_stock_stmt = $conn->prepare($update_stock_query);
                    $update_stock_stmt->bind_param("ii", $new_stock, $item_id);
                    
                    if (!$update_stock_stmt->execute()) {
                        throw new Exception("Failed to update stock for item ID: $item_id");
                    }
                    
                    if ($inventory_transactions_exists) {
                        $trans_query = "INSERT INTO inventory_transactions 
                                       (branch_id, item_id, transaction_type, quantity_changed, reference_type, reference_id, created_by, created_at) 
                                       VALUES (?, ?, 'out', ?, 'sales_order', ?, ?, NOW())";
                        $trans_stmt = $conn->prepare($trans_query);
                        $trans_stmt->bind_param("iiiii", $order_branch_id, $item_id, $quantity, $so_id, $user_id);
                        $trans_stmt->execute();
                    }
                }
            }
            
            if ($order_status === 'delivered' && $old_status !== 'delivered') {
                $update_pl_query = "UPDATE pick_lists SET pick_status = 'completed', updated_at = NOW() WHERE so_id = ?";
                $update_pl_stmt = $conn->prepare($update_pl_query);
                $update_pl_stmt->bind_param("i", $so_id);
                $update_pl_stmt->execute();
                
                if ($trip_has_so_id) {
                    $update_tt_query = "UPDATE trip_tickets SET trip_status = 'completed', updated_at = NOW() WHERE so_id = ?";
                    $update_tt_stmt = $conn->prepare($update_tt_query);
                    $update_tt_stmt->bind_param("i", $so_id);
                    $update_tt_stmt->execute();
                }
                
                if ($invoice_so_column_exists) {
                    $update_invoice_query = "UPDATE invoices
                                            SET status = CASE
                                                WHEN status = 'cancelled' THEN 'cancelled'
                                                WHEN status = 'paid' THEN 'paid'
                                                WHEN due_date < CURDATE() THEN 'overdue'
                                                ELSE 'pending'
                                            END
                                            WHERE so_id = ?";
                    $update_invoice_stmt = $conn->prepare($update_invoice_query);
                    $update_invoice_stmt->bind_param("i", $so_id);
                    $update_invoice_stmt->execute();

                    recalcCustomerCreditUsed($conn, $customer_id);
                }
            }
            
            if ($order_status === 'cancelled' && $old_status !== 'cancelled') {
                $update_pl_query = "UPDATE pick_lists SET pick_status = 'cancelled', updated_at = NOW() WHERE so_id = ?";
                $update_pl_stmt = $conn->prepare($update_pl_query);
                $update_pl_stmt->bind_param("i", $so_id);
                $update_pl_stmt->execute();
                
                if ($trip_has_so_id) {
                    $update_tt_query = "UPDATE trip_tickets SET trip_status = 'cancelled', updated_at = NOW() WHERE so_id = ?";
                    $update_tt_stmt = $conn->prepare($update_tt_query);
                    $update_tt_stmt->bind_param("i", $so_id);
                    $update_tt_stmt->execute();
                }
                
                if ($invoice_so_column_exists) {
                    $update_invoice_query = "UPDATE invoices SET status = 'cancelled' WHERE so_id = ?";
                    $update_invoice_stmt = $conn->prepare($update_invoice_query);
                    $update_invoice_stmt->bind_param("i", $so_id);
                    $update_invoice_stmt->execute();
                    
                    recalcCustomerCreditUsed($conn, $customer_id);
                }
            }
            
            $conn->commit();
            
            $response_message = 'Sales order updated successfully';
            $generated_docs = [];
            
            if ($order_status === 'confirmed' && $old_status === 'pending') {
                $response_message = $beyond_credit_required ? 'Order confirmed successfully with beyond credit limit acknowledgement. Pick List and Trip Ticket have been generated. Inventory has been updated.' : 'Order confirmed successfully! Pick List, Trip Ticket have been generated. Inventory has been updated.';
                $generated_docs = [
                    'picklist' => $pick_list_number,
                    'trip_ticket' => $trip_ticket_number,
                    'driver_id' => $selected_driver_id,
                    'driver_name' => $driver_name,
                    'vehicle_id' => $selected_vehicle_id,
                    'vehicle' => $vehicle_display
                ];
                
                if ($invoice_so_column_exists) {
                    $response_message = $beyond_credit_required ? 'Order confirmed successfully with beyond credit limit acknowledgement. Pick List, Invoice, and Trip Ticket have been generated. Inventory has been updated.' : 'Order confirmed successfully! Pick List, Invoice, and Trip Ticket have been generated. Inventory has been updated.';
                    $generated_docs['invoice'] = $invoice_number;
                }
            }
            
            echo json_encode([
                'success' => true,
                'message' => $response_message,
                'generated_docs' => $generated_docs
            ]);
            exit;
        }
        
        // GET AVAILABLE DRIVERS
        elseif ($_POST['action'] === 'get_available_drivers') {
            $branch_id_param = (int)$_POST['branch_id'];
            
            $query = "
                SELECT 
                    d.driver_id, 
                    d.driver_name,
                    d.status,
                    (
                        SELECT COUNT(*) 
                        FROM pick_lists pl 
                        JOIN sales_orders so ON pl.so_id = so.so_id 
                        WHERE pl.driver_id = d.driver_id 
                        AND so.order_status IN ('confirmed', 'processing', 'ready', 'in_transit')
                        AND pl.pick_status NOT IN ('completed', 'cancelled')
                    ) as pending_deliveries,
                    (
                        SELECT COUNT(*) 
                        FROM trip_tickets tt 
                        WHERE tt.driver_id = d.driver_id 
                        AND tt.trip_status = 'in-progress'
                    ) as active_trips
                FROM drivers d
                WHERE d.status = 'active'
                AND d.branch_id = ?
                HAVING active_trips = 0
                ORDER BY pending_deliveries DESC, d.driver_name
            ";
            
            $stmt = $conn->prepare($query);
            $stmt->bind_param("i", $branch_id_param);
            $stmt->execute();
            $result = $stmt->get_result();
            $drivers = $result->fetch_all(MYSQLI_ASSOC);
            
            echo json_encode([
                'success' => true,
                'drivers' => $drivers
            ]);
            exit;
        }
        
        // GET AVAILABLE VEHICLES
        elseif ($_POST['action'] === 'get_available_vehicles') {
            $branch_id_param = (int)$_POST['branch_id'];

            $query = "
                SELECT 
                    v.vehicle_id,
                    v.vehicle_type,
                    v.plate_number,
                    v.status,
                    (
                        SELECT COUNT(*)
                        FROM trip_tickets tt
                        WHERE tt.vehicle_id = v.vehicle_id
                        AND tt.trip_status = 'in-progress'
                    ) as active_trips
                FROM vehicles v
                WHERE v.status = 'active'
                AND v.branch_id = ?
                HAVING active_trips = 0
                ORDER BY v.vehicle_type ASC, v.plate_number ASC
            ";

            $stmt = $conn->prepare($query);
            $stmt->bind_param("i", $branch_id_param);
            $stmt->execute();
            $result = $stmt->get_result();
            $vehicles = $result->fetch_all(MYSQLI_ASSOC);

            echo json_encode([
                'success' => true,
                'vehicles' => $vehicles
            ]);
            exit;
        }

        // DELETE SALES ORDER
        elseif ($_POST['action'] === 'delete_order') {
            $so_id = (int)$_POST['so_id'];
            
            if ($so_branch_column_exists && !$view_all_branches) {
<<<<<<< HEAD
                $check_query = "SELECT so_id FROM sales_orders WHERE so_id = ? AND branch_id = ? AND created_by = ?";
                $check_stmt = $conn->prepare($check_query);
                $check_stmt->bind_param("iii", $so_id, $branch_id, $user_id);
=======
                $check_query = "SELECT so_id FROM sales_orders WHERE so_id = ? AND branch_id = ?";
                $check_stmt = $conn->prepare($check_query);
                $check_stmt->bind_param("ii", $so_id, $branch_id);
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                
                if ($check_result->num_rows === 0) {
                    throw new Exception('Order not found or access denied');
                }
            }
            
            $check_picklist_query = "SELECT COUNT(*) as count FROM pick_lists WHERE so_id = ?";
            $check_picklist_stmt = $conn->prepare($check_picklist_query);
            $check_picklist_stmt->bind_param("i", $so_id);
            $check_picklist_stmt->execute();
            $picklist_count = $check_picklist_stmt->get_result()->fetch_assoc()['count'];
            
            if ($picklist_count > 0) {
                throw new Exception('Cannot delete order with existing pick lists');
            }
            
            if ($invoice_so_column_exists) {
                $check_invoice_query = "SELECT COUNT(*) as count FROM invoices WHERE so_id = ?";
                $check_invoice_stmt = $conn->prepare($check_invoice_query);
                $check_invoice_stmt->bind_param("i", $so_id);
                $check_invoice_stmt->execute();
                $invoice_count = $check_invoice_stmt->get_result()->fetch_assoc()['count'];
                
                if ($invoice_count > 0) {
                    throw new Exception('Cannot delete order with existing invoices');
                }
            }
            
            if ($trip_has_so_id) {
                $check_trip_query = "SELECT COUNT(*) as count FROM trip_tickets WHERE so_id = ?";
                $check_trip_stmt = $conn->prepare($check_trip_query);
                $check_trip_stmt->bind_param("i", $so_id);
                $check_trip_stmt->execute();
                $trip_count = $check_trip_stmt->get_result()->fetch_assoc()['count'];
                
                if ($trip_count > 0) {
                    throw new Exception('Cannot delete order with existing trip tickets');
                }
            }
            
            $delete_items_query = "DELETE FROM sales_order_items WHERE so_id = ?";
            $delete_items_stmt = $conn->prepare($delete_items_query);
            $delete_items_stmt->bind_param("i", $so_id);
            $delete_items_stmt->execute();
            
<<<<<<< HEAD
            $delete_order_query = "DELETE FROM sales_orders WHERE so_id = ? AND created_by = ?";
            $delete_order_stmt = $conn->prepare($delete_order_query);
            $delete_order_stmt->bind_param("ii", $so_id, $user_id);
=======
            $delete_order_query = "DELETE FROM sales_orders WHERE so_id = ?";
            $delete_order_stmt = $conn->prepare($delete_order_query);
            $delete_order_stmt->bind_param("i", $so_id);
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
            
            if (!$delete_order_stmt->execute()) {
                throw new Exception('Failed to delete sales order');
            }
            
            $conn->commit();
            
            echo json_encode([
                'success' => true,
                'message' => 'Sales order deleted successfully'
            ]);
            exit;
        }
        
        // GET SALES ORDER DETAILS
        elseif ($_POST['action'] === 'get_order') {
            $so_id = (int)$_POST['so_id'];
            
            $query = "
                SELECT 
                    so.*,
                    c.customer_name,
                    c.customer_id,
                    c.address,
                    c.phone_number as contact_number,
                    c.email,
                    c.credit_limit,
                    c.credit_used,
                    b.branch_name,
                    u.first_name,
                    u.last_name,
                    CONCAT(u.first_name, ' ', u.last_name) as encoded_by,
                    COUNT(soi.so_item_id) as total_items,
                    SUM(soi.quantity_ordered) as total_quantity
                FROM sales_orders so
                JOIN customers c ON so.customer_id = c.customer_id
                LEFT JOIN branches b ON so.branch_id = b.branch_id
                LEFT JOIN users u ON so.created_by = u.user_id
                LEFT JOIN sales_order_items soi ON so.so_id = soi.so_id
                WHERE so.so_id = ?
<<<<<<< HEAD
                  AND so.created_by = ?
=======
                GROUP BY so.so_id
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
            ";
            
            if ($so_branch_column_exists && !$view_all_branches) {
                $query .= " AND so.branch_id = ?";
<<<<<<< HEAD
                $query .= " GROUP BY so.so_id";
                $stmt = $conn->prepare($query);
                $stmt->bind_param("iii", $so_id, $user_id, $branch_id);
            } else {
                $query .= " GROUP BY so.so_id";
                $stmt = $conn->prepare($query);
                $stmt->bind_param("ii", $so_id, $user_id);
=======
                $stmt = $conn->prepare($query);
                $stmt->bind_param("ii", $so_id, $branch_id);
            } else {
                $stmt = $conn->prepare($query);
                $stmt->bind_param("i", $so_id);
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
            }
            
            $stmt->execute();
            $result = $stmt->get_result();
            $order = $result->fetch_assoc();
            
            if ($order) {
                $credit_snapshot = getCustomerCreditSnapshot($conn, $order['customer_id']);
                $credit_limit = floatval($credit_snapshot['credit_limit'] ?? 0);
                $credit_used = floatval($credit_snapshot['credit_used'] ?? 0);
                $outstanding_balance = $credit_limit - $credit_used;
                $is_over_limit = !empty($credit_snapshot['is_over_limit_now']);
                
                $payment_terms_days = getCustomerCreditTerms($conn, $order['customer_id']);
                
                $active_request = getActiveApprovedCreditRequest($conn, $order['customer_id']);
                $has_credit_terms = false;
                $payment_terms_text = "Cash on Delivery";
                
                if ($active_request && !empty($active_request['credit_terms_days'])) {
                    $has_credit_terms = true;
                    $payment_terms_text = "Net " . $payment_terms_days . " days";
                } elseif ($credit_limit > 0) {
                    $has_credit_terms = true;
                    $payment_terms_text = "Net " . $payment_terms_days . " days";
                }
                
                $items_query = "
                    SELECT 
                        soi.*,
                        i.item_code,
                        i.item_name,
                        soi.unit_type,
                        i.price_case,
                        i.price_inner_pack,
                        i.price_box,
                        i.price_carton
                    FROM sales_order_items soi
                    JOIN items i ON soi.item_id = i.item_id
                    WHERE soi.so_id = ?
                ";
                $items_stmt = $conn->prepare($items_query);
                $items_stmt->bind_param("i", $so_id);
                $items_stmt->execute();
                $items_result = $items_stmt->get_result();
                $items = $items_result->fetch_all(MYSQLI_ASSOC);
                
                $documents = [];
                
                $pl_query = "SELECT pick_list_number, driver_id FROM pick_lists WHERE so_id = ? LIMIT 1";
                $pl_stmt = $conn->prepare($pl_query);
                $pl_stmt->bind_param("i", $so_id);
                $pl_stmt->execute();
                $pl_result = $pl_stmt->get_result();
                if ($pl_row = $pl_result->fetch_assoc()) {
                    $documents['pick_list_number'] = $pl_row['pick_list_number'];
                    $documents['picklist_driver_id'] = $pl_row['driver_id'];
                    
                    if (!empty($pl_row['driver_id'])) {
                        $driver_query = "SELECT driver_name FROM drivers WHERE driver_id = ?";
                        $driver_stmt = $conn->prepare($driver_query);
                        $driver_stmt->bind_param("i", $pl_row['driver_id']);
                        $driver_stmt->execute();
                        $driver_result = $driver_stmt->get_result();
                        $driver = $driver_result->fetch_assoc();
                        $documents['driver_name'] = $driver['driver_name'] ?? 'Unknown Driver';
                    }
                }
                
                if ($trip_has_so_id) {
                    $tt_query = "SELECT tt.trip_number" . ($trip_has_vehicle_id ? ", tt.vehicle_id, v.vehicle_type, v.plate_number" : "") . "
                                 FROM trip_tickets tt" . ($trip_has_vehicle_id ? " LEFT JOIN vehicles v ON tt.vehicle_id = v.vehicle_id" : "") . "
                                 WHERE tt.so_id = ? LIMIT 1";
                    $tt_stmt = $conn->prepare($tt_query);
                    $tt_stmt->bind_param("i", $so_id);
                    $tt_stmt->execute();
                    $tt_result = $tt_stmt->get_result();
                    if ($tt_row = $tt_result->fetch_assoc()) {
                        $documents['trip_ticket_number'] = $tt_row['trip_number'];
                        if (!empty($tt_row['vehicle_id'])) {
                            $documents['vehicle_id'] = $tt_row['vehicle_id'];
                            $documents['vehicle'] = trim(($tt_row['vehicle_type'] ?? '') . ' - ' . ($tt_row['plate_number'] ?? ''));
                        }
                    }
                }
                
                $invoice = null;
                if ($invoice_so_column_exists) {
                    $invoice_query = "SELECT i.invoice_id, i.invoice_number, i.status as invoice_status, i.due_date, p.payment_date as collected_at,
                                         CONCAT(COALESCE(u.first_name, ''), CASE WHEN u.last_name IS NOT NULL AND u.last_name != '' THEN CONCAT(' ', u.last_name) ELSE '' END) AS collected_by
                                  FROM invoices i
                                  LEFT JOIN (SELECT invoice_id, MAX(payment_id) AS latest_payment_id FROM payments GROUP BY invoice_id) lp ON i.invoice_id = lp.invoice_id
                                  LEFT JOIN payments p ON lp.latest_payment_id = p.payment_id
                                  LEFT JOIN users u ON p.created_by = u.user_id
                                  WHERE i.so_id = ?
                                  LIMIT 1";
                    $invoice_stmt = $conn->prepare($invoice_query);
                    $invoice_stmt->bind_param("i", $so_id);
                    $invoice_stmt->execute();
                    $invoice_result = $invoice_stmt->get_result();
                    $invoice = $invoice_result->fetch_assoc();
                }
                
                echo json_encode([
                    'success' => true,
                    'order' => $order,
                    'items' => $items,
                    'documents' => $documents,
                    'invoice' => $invoice,
                    'outstanding_balance' => $outstanding_balance,
                    'credit_limit' => $credit_limit,
                    'credit_used' => $credit_used,
                    'is_over_limit' => $is_over_limit,
                    'payment_terms_days' => $payment_terms_days,
                    'payment_terms_text' => $payment_terms_text
                ]);
            } else {
                throw new Exception('Sales order not found');
            }
            exit;
        }
        
        // GET DETAILED ORDER ITEMS
        elseif ($_POST['action'] === 'get_order_items_detailed') {
            $so_id = (int)$_POST['so_id'];
            
            $query = "
                SELECT 
                    soi.*,
                    so.so_number,
                    so.created_at as order_date,
                    so.order_status,
                    so.total_amount as order_total,
                    c.customer_name,
                    c.customer_id,
                    b.branch_name,
                    b.branch_id,
                    i.item_code,
                    i.item_name,
                    i.unit_type as item_unit_type
                FROM sales_order_items soi
                JOIN sales_orders so ON soi.so_id = so.so_id
                JOIN customers c ON so.customer_id = c.customer_id
                LEFT JOIN branches b ON so.branch_id = b.branch_id
                JOIN items i ON soi.item_id = i.item_id
                WHERE soi.so_id = ?
<<<<<<< HEAD
                  AND so.created_by = ?
=======
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
            ";
            
            if ($so_branch_column_exists && !$view_all_branches) {
                $query .= " AND so.branch_id = ?";
                $stmt = $conn->prepare($query);
<<<<<<< HEAD
                $stmt->bind_param("iii", $so_id, $user_id, $branch_id);
            } else {
                $stmt = $conn->prepare($query);
                $stmt->bind_param("ii", $so_id, $user_id);
=======
                $stmt->bind_param("ii", $so_id, $branch_id);
            } else {
                $stmt = $conn->prepare($query);
                $stmt->bind_param("i", $so_id);
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
            }
            
            $stmt->execute();
            $result = $stmt->get_result();
            $items = $result->fetch_all(MYSQLI_ASSOC);
            
            echo json_encode([
                'success' => true,
                'items' => $items
            ]);
            exit;
        }
        
        // GET ALL ORDER ITEMS FOR EXPORT/PRINT WITH FILTERS
        elseif ($_POST['action'] === 'get_all_order_items') {
            $filter_data = json_decode($_POST['filter_data'] ?? '{}', true);
            
            $query = "
                SELECT 
                    soi.so_item_id,
                    soi.so_id,
                    soi.item_id,
                    soi.quantity_ordered,
                    soi.quantity_delivered,
                    soi.unit_price,
                    soi.unit_type,
                    so.so_number,
                    so.created_at as order_date,
                    so.order_status,
                    so.total_amount as order_total,
                    c.customer_name,
                    c.customer_id,
                    b.branch_name,
                    b.branch_id,
                    i.item_code,
                    i.item_name
                FROM sales_order_items soi
                JOIN sales_orders so ON soi.so_id = so.so_id
                JOIN customers c ON so.customer_id = c.customer_id
                LEFT JOIN branches b ON so.branch_id = b.branch_id
                JOIN items i ON soi.item_id = i.item_id
                WHERE 1=1
<<<<<<< HEAD
                  AND so.created_by = " . intval($user_id) . "
=======
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
            ";
            
            if (!empty($filter_data['status']) && $filter_data['status'] !== '') {
                $query .= " AND so.order_status = '" . $conn->real_escape_string($filter_data['status']) . "'";
            }
            
            if (!empty($filter_data['customer']) && $filter_data['customer'] !== '') {
                $query .= " AND c.customer_name = '" . $conn->real_escape_string($filter_data['customer']) . "'";
            }
            
            if (!empty($filter_data['search']) && $filter_data['search'] !== '') {
                $search = $conn->real_escape_string($filter_data['search']);
                $query .= " AND (so.so_number LIKE '%$search%' OR c.customer_name LIKE '%$search%' OR i.item_name LIKE '%$search%' OR i.item_code LIKE '%$search%')";
            }
            
            if (!empty($filter_data['start_date']) && !empty($filter_data['end_date'])) {
                $start_date = $conn->real_escape_string($filter_data['start_date']);
                $end_date = $conn->real_escape_string($filter_data['end_date']);
                $query .= " AND DATE(so.created_at) BETWEEN '$start_date' AND '$end_date'";
            }
            
            if ($so_branch_column_exists && !$view_all_branches) {
                $query .= " AND so.branch_id = $branch_id";
            }
            
            $query .= " ORDER BY so.created_at DESC, so.so_id DESC, soi.so_item_id";
            
            $result = $conn->query($query);
            $items = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
            
            echo json_encode([
                'success' => true,
                'items' => $items,
                'branch_name' => $branch_id ? ('Branch ' . $branch_id) : 'All Branches',
                'view_all' => $view_all_branches
            ]);
            exit;
        }
        
        // EXPORT ALL ORDERS TO EXCEL
        elseif ($_POST['action'] === 'export_all_orders') {
            $start_date = isset($_POST['start_date']) ? $_POST['start_date'] : '';
            $end_date = isset($_POST['end_date']) ? $_POST['end_date'] : '';
            $status = isset($_POST['status']) ? $_POST['status'] : '';
            $customer = isset($_POST['customer']) ? $_POST['customer'] : '';
            $search = isset($_POST['search']) ? $_POST['search'] : '';
            
            $query = "
                SELECT 
                    DATE(so.created_at) as date_encoded,
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
                WHERE 1=1
<<<<<<< HEAD
                  AND so.created_by = " . intval($user_id) . "
=======
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
            ";
            
            if (!empty($start_date) && !empty($end_date)) {
                $query .= " AND DATE(so.created_at) BETWEEN '" . $conn->real_escape_string($start_date) . "' AND '" . $conn->real_escape_string($end_date) . "'";
            }
            if (!empty($status)) {
                $query .= " AND so.order_status = '" . $conn->real_escape_string($status) . "'";
            }
            if (!empty($customer)) {
                $query .= " AND c.customer_name = '" . $conn->real_escape_string($customer) . "'";
            }
            if (!empty($search)) {
                $search_escaped = $conn->real_escape_string($search);
                $query .= " AND (so.so_number LIKE '%$search_escaped%' OR c.customer_name LIKE '%$search_escaped%' OR i.item_name LIKE '%$search_escaped%' OR i.item_code LIKE '%$search_escaped%')";
            }
            
            if ($so_branch_column_exists && !$view_all_branches) {
                $query .= " AND so.branch_id = $branch_id";
            }
            
            $query .= " ORDER BY so.created_at DESC, so.so_id, soi.so_item_id";
            
            $result = $conn->query($query);
            if (!$result) {
                echo json_encode(['success' => false, 'message' => 'Query failed: ' . $conn->error]);
                exit;
            }
            
            $data = $result->fetch_all(MYSQLI_ASSOC);
            echo json_encode(['success' => true, 'data' => $data]);
            exit;
        }
        
        // PRINT ALL ORDERS (HTML)
        elseif ($_POST['action'] === 'print_all_orders') {
            $start_date = isset($_POST['start_date']) ? $_POST['start_date'] : '';
            $end_date = isset($_POST['end_date']) ? $_POST['end_date'] : '';
            $status = isset($_POST['status']) ? $_POST['status'] : '';
            $customer = isset($_POST['customer']) ? $_POST['customer'] : '';
            $search = isset($_POST['search']) ? $_POST['search'] : '';
            
            $query = "
                SELECT 
                    DATE(so.created_at) as date_encoded,
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
                WHERE 1=1
<<<<<<< HEAD
                  AND so.created_by = " . intval($user_id) . "
=======
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
            ";
            
            if (!empty($start_date) && !empty($end_date)) {
                $query .= " AND DATE(so.created_at) BETWEEN '" . $conn->real_escape_string($start_date) . "' AND '" . $conn->real_escape_string($end_date) . "'";
            }
            if (!empty($status)) {
                $query .= " AND so.order_status = '" . $conn->real_escape_string($status) . "'";
            }
            if (!empty($customer)) {
                $query .= " AND c.customer_name = '" . $conn->real_escape_string($customer) . "'";
            }
            if (!empty($search)) {
                $search_escaped = $conn->real_escape_string($search);
                $query .= " AND (so.so_number LIKE '%$search_escaped%' OR c.customer_name LIKE '%$search_escaped%' OR i.item_name LIKE '%$search_escaped%' OR i.item_code LIKE '%$search_escaped%')";
            }
            if ($so_branch_column_exists && !$view_all_branches) {
                $query .= " AND so.branch_id = $branch_id";
            }
            $query .= " ORDER BY so.created_at DESC, so.so_id, soi.so_item_id";
            
            $result = $conn->query($query);
            $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
            
            $branch_name_display = $branch_id ? ($view_all_branches ? 'All Branches' : htmlspecialchars($branch_name)) : 'All Branches';
            $logo_html = '';
            if (!empty($logo_base64)) {
                $logo_html = '<img src="' . $logo_base64 . '" alt="Logo" style="height: 60px; margin-bottom: 10px;">';
            }
            
            $html = '<!DOCTYPE html>
            <html>
            <head>
                <meta charset="UTF-8">
                <title>All Sales Orders Report</title>
                <style>
<<<<<<< HEAD
        /* Rolling Sales Order is view-only */
        .btn-edit,
        .btn-delete,
        #editFromViewBtn,
        #deleteFromViewBtn,
        #editOrderModal {
            display: none !important;
        }

=======
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
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
<<<<<<< HEAD
                

/* Serve / Deliver action for Rolling Pending Today */
.btn-serve-deliver {
    background: linear-gradient(135deg, #047857, #059669) !important;
    color: #fff !important;
    border: none !important;
}
.btn-serve-deliver:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 10px rgba(4, 120, 87, 0.25);
}
.serve-payment-options {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}
.serve-payment-option {
    border: 1px solid #d1d5db;
    background: #ffffff;
    color: #374151;
    border-radius: 12px;
    padding: 10px 14px;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.2s ease;
}
.serve-payment-option.active,
.serve-payment-option:hover {
    background: linear-gradient(135deg, #047857, #059669);
    border-color: #047857;
    color: #ffffff;
}
</style>
=======
                </style>
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
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
                    <td>' . number_format($row['discount'], 2) . '</td>
                    <td>' . number_format($row['net_price'], 2) . '</td>
                    <td>' . number_format($row['total_discount'], 2) . '</td>
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
            exit;
        }
        
        // PRINT SINGLE ORDER (detailed receipt)
        elseif ($_POST['action'] === 'print_order') {
            $so_id = (int)$_POST['so_id'];
            
            $query = "
                SELECT 
                    soi.so_item_id,
                    soi.so_id,
                    soi.item_id,
                    soi.quantity_ordered,
                    soi.quantity_delivered,
                    soi.unit_price,
                    soi.unit_type,
                    so.so_number,
                    so.created_at as order_date,
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
                    i.item_name
                FROM sales_order_items soi
                JOIN sales_orders so ON soi.so_id = so.so_id
                JOIN customers c ON so.customer_id = c.customer_id
                LEFT JOIN branches b ON so.branch_id = b.branch_id
                LEFT JOIN users u ON so.created_by = u.user_id
                JOIN items i ON soi.item_id = i.item_id
                WHERE soi.so_id = ?
<<<<<<< HEAD
                  AND so.created_by = ?
=======
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
                ORDER BY soi.so_item_id
            ";
            
            $stmt = $conn->prepare($query);
<<<<<<< HEAD
            $stmt->bind_param("ii", $so_id, $user_id);
=======
            $stmt->bind_param("i", $so_id);
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
            $stmt->execute();
            $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            
            $driver_query = "
                SELECT d.driver_name, v.vehicle_type, v.plate_number
                FROM pick_lists pl
                JOIN drivers d ON pl.driver_id = d.driver_id
                LEFT JOIN trip_tickets tt ON tt.so_id = pl.so_id
                LEFT JOIN vehicles v ON tt.vehicle_id = v.vehicle_id
                WHERE pl.so_id = ?
                LIMIT 1
            ";
            $driver_stmt = $conn->prepare($driver_query);
            $driver_stmt->bind_param("i", $so_id);
            $driver_stmt->execute();
            $driver = $driver_stmt->get_result()->fetch_assoc();
            
            $order_summary = !empty($items) ? $items[0] : null;
            
            echo json_encode([
                'success' => true,
                'order' => $order_summary,
                'items' => $items,
                'driver' => $driver
            ]);
            exit;
        }
        
        // GET INVOICE DETAILS
        elseif ($_POST['action'] === 'get_invoice') {
            if (!$invoice_so_column_exists) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Invoice functionality not available. Please run SQL to add relationship: ALTER TABLE invoices ADD COLUMN so_id INT NULL;'
                ]);
                exit;
            }
            
            $so_id = (int)$_POST['so_id'];
            
<<<<<<< HEAD
            $query = "SELECT inv.* FROM invoices inv JOIN sales_orders so ON inv.so_id = so.so_id WHERE inv.so_id = ? AND so.created_by = ?";
            $stmt = $conn->prepare($query);
            $stmt->bind_param("ii", $so_id, $user_id);
=======
            $query = "SELECT * FROM invoices WHERE so_id = ?";
            $stmt = $conn->prepare($query);
            $stmt->bind_param("i", $so_id);
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
            $stmt->execute();
            $result = $stmt->get_result();
            $invoice = $result->fetch_assoc();
            
            if ($invoice) {
                echo json_encode([
                    'success' => true,
                    'invoice' => $invoice
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'message' => 'Invoice not found'
                ]);
            }
            exit;
        }
        
        // UPDATE INVOICE STATUS
        elseif ($_POST['action'] === 'update_invoice_status') {
            if (!$invoice_so_column_exists) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Invoice functionality not available'
                ]);
                exit;
            }
            
            $invoice_id = (int)$_POST['invoice_id'];
            $status = $_POST['status'];
            
            $update_query = "UPDATE invoices SET status = ? WHERE invoice_id = ?";
            $update_stmt = $conn->prepare($update_query);
            $update_stmt->bind_param("si", $status, $invoice_id);
            
            if ($update_stmt->execute()) {
                $cust_sql = "SELECT customer_id FROM invoices WHERE invoice_id = ?";
                $cust_stmt = $conn->prepare($cust_sql);
                $cust_stmt->bind_param("i", $invoice_id);
                $cust_stmt->execute();
                $cust_res = $cust_stmt->get_result();
                if ($cust_row = $cust_res->fetch_assoc()) {
                    recalcCustomerCreditUsed($conn, $cust_row['customer_id']);
                }
                echo json_encode([
                    'success' => true,
                    'message' => 'Invoice status updated successfully'
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'message' => 'Failed to update invoice status'
                ]);
            }
            exit;
        }
        
        // CHECK STOCK AVAILABILITY
        elseif ($_POST['action'] === 'check_stock') {
            $so_id = (int)$_POST['so_id'];
            
            $items_query = "
                SELECT 
                    soi.item_id,
                    soi.quantity_ordered,
                    i.item_code,
                    i.item_name,
                    i.stock as available_stock
                FROM sales_order_items soi
<<<<<<< HEAD
                JOIN sales_orders so ON soi.so_id = so.so_id
                JOIN items i ON soi.item_id = i.item_id
                WHERE soi.so_id = ?
                  AND so.created_by = ?
            ";
            $items_stmt = $conn->prepare($items_query);
            $items_stmt->bind_param("ii", $so_id, $user_id);
=======
                JOIN items i ON soi.item_id = i.item_id
                WHERE soi.so_id = ?
            ";
            $items_stmt = $conn->prepare($items_query);
            $items_stmt->bind_param("i", $so_id);
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
            $items_stmt->execute();
            $items = $items_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            
            $insufficient_items = [];
            foreach ($items as $item) {
                if ($item['available_stock'] < $item['quantity_ordered']) {
                    $insufficient_items[] = [
                        'item_code' => $item['item_code'],
                        'item_name' => $item['item_name'],
                        'required' => $item['quantity_ordered'],
                        'available' => $item['available_stock']
                    ];
                }
            }
            
            if (empty($insufficient_items)) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Stock is sufficient',
                    'sufficient' => true
                ]);
            } else {
                echo json_encode([
                    'success' => true,
                    'message' => 'Some items have insufficient stock',
                    'sufficient' => false,
                    'insufficient_items' => $insufficient_items
                ]);
            }
            exit;
        }
        
    } catch (Exception $e) {
        $conn->rollback();
        $error_message = $e->getMessage();
        
        if (strpos($error_message, '{"type":"credit_limit_error"') === 0 || strpos($error_message, '{"type":"credit_limit_required"') === 0) {
            echo $error_message;
        } else {
            echo json_encode([
                'success' => false,
                'message' => $error_message
            ]);
        }
        exit;
    }
}

// FETCH SALES ORDERS WITH CUSTOMER, ITEM COUNTS, AND INVOICE DATA
<<<<<<< HEAD
$collection_date_expr_parts = [];
if ($collection_records_exists) {
    if ($collection_has_approved_at) $collection_date_expr_parts[] = "cr.approved_at";
    if ($collection_has_remitted_at) $collection_date_expr_parts[] = "cr.remitted_at";
    if ($collection_has_collection_date) $collection_date_expr_parts[] = "cr.collection_date";
    if ($collection_has_created_at) $collection_date_expr_parts[] = "cr.created_at";
}
$collection_date_expr = !empty($collection_date_expr_parts) ? "COALESCE(" . implode(',', $collection_date_expr_parts) . ")" : "NULL";

$invoice_select_sql = "NULL as invoice_id, NULL as invoice_number, NULL as invoice_status, NULL as collection_status, NULL as collected_by_name, NULL as collected_at";
$invoice_join_sql = "";
if ($invoice_so_column_exists) {
    $invoice_select_sql = "inv.invoice_id,
        inv.invoice_number,
        COALESCE(NULLIF(TRIM(inv.status), ''), NULLIF(TRIM(coll.latest_collection_status), ''), '') as invoice_status,
        COALESCE(NULLIF(TRIM(coll.latest_collection_status), ''), '') as collection_status,
        COALESCE(pay.collected_by_name, coll.collected_by_name) as collected_by_name,
        COALESCE(pay.payment_date, coll.collection_date, inv.updated_at, so.updated_at, so.created_at) as collected_at";

    $invoice_join_sql = "LEFT JOIN invoices inv ON so.so_id = inv.so_id
       LEFT JOIN (
           SELECT p1.invoice_id, p1.payment_date, CONCAT(COALESCE(u.first_name, ''), CASE WHEN u.last_name IS NOT NULL AND u.last_name != '' THEN CONCAT(' ', u.last_name) ELSE '' END) AS collected_by_name
           FROM payments p1
           LEFT JOIN users u ON p1.created_by = u.user_id
           INNER JOIN (SELECT invoice_id, MAX(payment_id) AS latest_payment_id FROM payments GROUP BY invoice_id) p2
               ON p1.payment_id = p2.latest_payment_id
       ) pay ON inv.invoice_id = pay.invoice_id";

    if ($collection_records_exists) {
        $invoice_join_sql .= "
       LEFT JOIN (
           SELECT
               cr.invoice_id,
               MAX($collection_date_expr) AS collection_date,
               SUBSTRING_INDEX(GROUP_CONCAT(COALESCE(NULLIF(TRIM(cr.status), ''), 'collected') ORDER BY $collection_date_expr DESC SEPARATOR '||'), '||', 1) AS latest_collection_status,
               SUBSTRING_INDEX(GROUP_CONCAT(CONCAT(COALESCE(u.first_name, ''), CASE WHEN u.last_name IS NOT NULL AND u.last_name != '' THEN CONCAT(' ', u.last_name) ELSE '' END) ORDER BY $collection_date_expr DESC SEPARATOR '||'), '||', 1) AS collected_by_name
           FROM collection_records cr
           LEFT JOIN users u ON cr.collector_user_id = u.user_id
           WHERE cr.invoice_id IS NOT NULL AND cr.invoice_id > 0
           GROUP BY cr.invoice_id
       ) coll ON inv.invoice_id = coll.invoice_id";
    } else {
        $invoice_join_sql .= " LEFT JOIN (SELECT NULL AS invoice_id, NULL AS collection_date, NULL AS latest_collection_status, NULL AS collected_by_name) coll ON 1=0";
    }
}

=======
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
$sales_query = "
    SELECT 
        so.so_id,
        so.so_number,
        so.created_at,
        so.total_amount,
        so.order_status,
        so.branch_id,
        c.customer_name,
        c.customer_id,
        c.credit_limit,
        c.credit_used,
        b.branch_name,
        u.first_name,
        u.last_name,
        COUNT(DISTINCT soi.so_item_id) as total_items,
        SUM(soi.quantity_ordered) as total_quantity,
<<<<<<< HEAD
        " . $invoice_select_sql . ",
=======
        " . ($invoice_so_column_exists ? "inv.invoice_id, inv.invoice_number, inv.status as invoice_status, pay.collected_by_name, pay.payment_date as collected_at" : "NULL as invoice_id, NULL as invoice_number, NULL as invoice_status, NULL as collected_by_name, NULL as collected_at") . ",
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
        (SELECT driver_name FROM drivers WHERE driver_id = pl.driver_id LIMIT 1) as assigned_driver
    FROM sales_orders so
    JOIN customers c ON so.customer_id = c.customer_id
    LEFT JOIN branches b ON so.branch_id = b.branch_id
    LEFT JOIN users u ON so.created_by = u.user_id
    LEFT JOIN sales_order_items soi ON so.so_id = soi.so_id
    LEFT JOIN pick_lists pl ON so.so_id = pl.so_id
<<<<<<< HEAD
    " . $invoice_join_sql . "
=======
    " . ($invoice_so_column_exists ? "LEFT JOIN invoices inv ON so.so_id = inv.so_id
       LEFT JOIN (
           SELECT p1.invoice_id, p1.payment_date, CONCAT(COALESCE(u.first_name, ''), CASE WHEN u.last_name IS NOT NULL AND u.last_name != '' THEN CONCAT(' ', u.last_name) ELSE '' END) AS collected_by_name
           FROM payments p1
           LEFT JOIN users u ON p1.created_by = u.user_id
           INNER JOIN (SELECT invoice_id, MAX(payment_id) AS latest_payment_id FROM payments GROUP BY invoice_id) p2
               ON p1.payment_id = p2.latest_payment_id
       ) pay ON inv.invoice_id = pay.invoice_id" : "") . "
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
    WHERE 1=1
    $branch_condition
    GROUP BY so.so_id
    ORDER BY so.created_at DESC, so.so_id DESC
";
$sales_result = $conn->query($sales_query);
if (!$sales_result) {
    die("Query failed: " . $conn->error);
}
$sales_orders = $sales_result->fetch_all(MYSQLI_ASSOC);

// CALCULATE STATISTICS
$total_orders = count($sales_orders);
$pending_orders = count(array_filter($sales_orders, fn($so) => $so['order_status'] === 'pending'));
$processing_orders = count(array_filter($sales_orders, fn($so) => $so['order_status'] === 'processing'));
$ready_orders = count(array_filter($sales_orders, fn($so) => $so['order_status'] === 'ready'));
$in_transit_orders = count(array_filter($sales_orders, fn($so) => $so['order_status'] === 'in_transit'));
$delivered_orders = count(array_filter($sales_orders, fn($so) => $so['order_status'] === 'delivered'));
$cancelled_orders = count(array_filter($sales_orders, fn($so) => $so['order_status'] === 'cancelled'));

$statTotalOrders = $total_orders;
$statPendingOrders = $pending_orders;
$statCompletedOrders = $delivered_orders;

// Get unique customers for filter
<<<<<<< HEAD
$customers_query = "SELECT DISTINCT c.customer_id, c.customer_name
    FROM customers c
    JOIN sales_orders so ON so.customer_id = c.customer_id
    WHERE c.status = 'active'
      AND so.created_by = " . intval($user_id) . " " . ($customers_branch_column_exists && !$view_all_branches ? "AND c.branch_id = " . intval($branch_id) : "") . "
    ORDER BY c.customer_name";
=======
$customers_query = "SELECT customer_id, customer_name FROM customers WHERE status = 'active' $customers_branch_condition ORDER BY customer_name";
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
$customers_result = $conn->query($customers_query);
$customers = $customers_result->fetch_all(MYSQLI_ASSOC);

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
        'confirmed' => 'badge bg-info text-white',
        'processing' => 'badge bg-primary text-white',
        'ready' => 'badge bg-info text-white',
        'in_transit' => 'badge bg-primary text-white',
        'delivered' => 'badge bg-success text-white',
        'cancelled' => 'badge bg-danger text-white',
        default => 'badge bg-secondary text-white'
    };
}

function getOrderStatusText($status) {
    return match($status) {
        'pending' => 'Pending',
        'confirmed' => 'Confirmed',
        'processing' => 'Processing',
        'ready' => 'For Delivery',
        'in_transit' => 'In Transit',
        'delivered' => 'Delivered',
        'cancelled' => 'Cancelled',
        default => ucfirst($status)
    };
}

function getPaymentStatus($order_status, $invoice_status = null) {
    if ($order_status === 'cancelled') return ['status' => 'Cancelled', 'class' => 'bg-danger'];

    if ($invoice_status) {
        return match($invoice_status) {
            'paid' => ['status' => 'Paid', 'class' => 'bg-success'],
            'pending' => ['status' => 'Pending', 'class' => 'bg-warning text-dark'],
            'overdue' => ['status' => 'Overdue', 'class' => 'bg-danger'],
            'cancelled' => ['status' => 'Cancelled', 'class' => 'bg-danger'],
            default => ['status' => 'Pending', 'class' => 'bg-warning text-dark']
        };
    }
    
    return ['status' => 'Pending', 'class' => 'bg-warning text-dark'];
}

function formatDate($dateStr) {
    if (!$dateStr) return '';
    $date = new DateTime($dateStr);
    return $date->format('M d, Y');
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

// Get user's branch name for display
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sales Orders - Branch Admin</title>
    <link rel="icon" type="image/png" href="../Pictures/favicon-96x96.png" sizes="96x96" />
    <link rel="icon" type="image/svg+xml" href="../Pictures/favicon.svg" />
    <link rel="shortcut icon" href="../Pictures/favicon.ico" />
    <link rel="apple-touch-icon" sizes="180x180" href="../Pictures/apple-touch-icon.png" />
    <link rel="manifest" href="../Pictures/site.webmanifest" />
    <link rel="stylesheet" href="../css/current_inventory.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://fonts.googleapis.com/css2?family=Tenor+Sans&family=Alice&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
        <!-- Session Checker -->
    <script src="../js/session-checker.js"></script>
    
    <style>
        /* All original CSS remains exactly the same as in the previous version */
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

        .outstanding-balance-card {
            background: linear-gradient(135deg, #fff3e0, #ffe0b2);
            border-left: 4px solid #f57c00;
            border-radius: 12px;
            padding: 15px;
            margin-bottom: 15px;
        }
        
        .outstanding-balance-card .label {
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #e65100;
            font-weight: 600;
            margin-bottom: 5px;
        }
        
        .outstanding-balance-card .amount {
            font-size: 1.3rem;
            font-weight: 700;
            color: #e65100;
        }
        
        .outstanding-balance-card.warning {
            background: linear-gradient(135deg, #ffebee, #ffcdd2);
            border-left-color: #d32f2f;
        }
        
        .outstanding-balance-card.warning .label,
        .outstanding-balance-card.warning .amount {
            color: #c62828;
        }
        
        .outstanding-balance-card i {
            font-size: 1.3rem;
            margin-right: 12px;
            color: #f57c00;
        }
        
        .outstanding-balance-card.warning i {
            color: #d32f2f;
        }
        
        .credit-info-row {
            display: flex;
            justify-content: space-between;
            margin-top: 8px;
            padding-top: 8px;
            border-top: 1px solid rgba(0,0,0,0.05);
        }
        
        .credit-info-row .credit-item {
            font-size: 0.7rem;
        }
        
        .credit-info-row .credit-item span:first-child {
            color: #6c757d;
        }
        
        .credit-info-row .credit-item span:last-child {
            font-weight: 600;
            color: #212121;
        }

        .branch-badge {
            background-color: #e7f1ff;
            color: #0d6efd;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
            margin-left: 5px;
        }
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
        
        .available-badge {
            background-color: #d4edda;
            color: #155724;
            padding: 2px 6px;
            border-radius: 12px;
            font-size: 10px;
            margin-left: 5px;
        }
        
        .busy-badge {
            background-color: #f8d7da;
            color: #721c24;
            padding: 2px 6px;
            border-radius: 12px;
            font-size: 10px;
            margin-left: 5px;
        }

.select2-container--default .select2-selection--single {
    height: 38px;
    border: 1px solid #ced4da;
    border-radius: 4px;
    background-color: white;
}

.select2-container--default .select2-selection--single .select2-selection__rendered {
    line-height: 38px;
    padding-left: 12px;
    color: #212121;
}

.select2-container--default .select2-selection--single .select2-selection__arrow {
    height: 36px;
}

/* FIXED: Highlighted option - BLACK text on green background */
.select2-container--default .select2-results__option--highlighted.select2-results__option--selectable {
    background-color: #2E7D32 !important;
    color: #000000 !important;
}

/* Driver option text when highlighted - BLACK */
.select2-container--default .select2-results__option--highlighted .driver-option .driver-name,
.select2-container--default .select2-results__option--highlighted .driver-option .driver-vehicle {
    color: #000000 !important;
    font-weight: 600 !important;
}

/* Badges inside highlighted option */
.select2-container--default .select2-results__option--highlighted .driver-option .pending-count,
.select2-container--default .select2-results__option--highlighted .driver-option .available-badge {
    background-color: #FFC107 !important;
    color: #000000 !important;
    font-weight: 700 !important;
}

/* Selected option (not highlighted) */
.select2-container--default .select2-results__option--selected {
    background-color: #e8f5e9 !important;
    color: #1B5E20 !important;
}

/* Selected + Highlighted combination */
.select2-container--default .select2-results__option--selected.select2-results__option--highlighted {
    background-color: #2E7D32 !important;
    color: #000000 !important;
}

.select2-container--default .select2-results__group {
    background-color: #f5f5f5;
    color: #0D4C14;
    font-weight: 600;
    padding: 8px 12px;
    border-bottom: 1px solid #dee2e6;
}

.select2-container--default .select2-results__option {
    padding: 8px 12px;
}

        .driver-option {
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .driver-option .driver-name {
            font-weight: 500;
            color: #212121;
        }

        .driver-option .driver-vehicle {
            font-size: 11px;
            color: #6c757d;
            margin-left: 8px;
        }

        .driver-option .pending-count {
            background-color: #FFC107;
            color: #212121;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 600;
        }

        .select2-container--default .select2-results__option--highlighted .driver-option .driver-name,
        .select2-container--default .select2-results__option--highlighted .driver-option .driver-vehicle,
        .select2-container--default .select2-results__option--highlighted .driver-option .pending-count {
            color: white;
        }

        .select2-container--default .select2-results__option--highlighted .driver-option .pending-count {
            background-color: rgba(255,255,255,0.3);
            color: white;
        }

        .driver-info-tooltip {
            font-size: 11px;
            padding: 4px 8px;
            background-color: #f8f9fa;
            border-radius: 4px;
            margin-top: 4px;
        }

        .driver-info-tooltip i {
            color: #2E7D32;
            margin-right: 4px;
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
            background-color: #fff3e0;
            color: #f57c00;
            border-color: #ffe0b2;
        }
        
        .btn-edit:hover {
            background-color: #ffe0b2;
            transform: translateY(-2px);
        }
        
        .btn-delete {
            background-color: #ffebee;
            color: #d32f2f;
            border-color: #ffcdd2;
        }
        
        .btn-delete:hover {
            background-color: #ffcdd2;
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
        
        .db-fix-card {
            background: #fff3cd;
            border: 1px solid #ffe69c;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .db-fix-card pre {
            background: #212529;
            color: #fff;
            padding: 15px;
            border-radius: 5px;
            overflow-x: auto;
        }
        
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
            
            #printFrame .status-badge-print,
            #printFrame .branch-badge-print,
            #printFrame .driver-badge-print {
                background: white !important;
                border: 1px solid #000 !important;
                color: black !important;
                padding: 2px 6px;
            }
        }
        
        #printFrame {
            position: absolute;
            left: -9999px;
            top: -9999px;
            width: 1px;
            height: 1px;
            opacity: 0;
            pointer-events: none;
        }
<<<<<<< HEAD

        .print-preview-modal .modal-content {
            border: none;
            border-radius: 18px;
            overflow: hidden;
            box-shadow: 0 18px 45px rgba(5, 42, 71, 0.22);
        }

        .print-preview-modal .modal-header {
            background: linear-gradient(135deg, #052A47, #0f4c75);
            color: #ffffff;
            border-bottom: none;
        }

        .print-preview-modal .modal-body {
            background: #f8fafc;
            padding: 1rem;
        }

        .print-preview-frame-wrap {
            background: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 14px;
            padding: 12px;
            display: flex;
            justify-content: center;
            overflow: auto;
            max-height: 72vh;
        }

        #printPreviewFrame {
            width: 86mm;
            min-height: 600px;
            border: 1px dashed #cbd5e1;
            background: #ffffff;
            border-radius: 8px;
        }
=======
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
        
        .sidebar, .navbar-top, .footer, .action-buttons, 
        .btn, .table-header .btn, .form-card, 
        .mobile-menu-btn, #desktopToggleBtn, .sidebar-footer,
        .stat-card, .alert, .badge:not(.print-badge), .branch-badge,
        .modal, .data-table .table-header button,
        .filter-section, .row.g-3.mb-4, #dashboardSubtitle,
        .db-fix-card, .stock-warning, #dashboardContent .row:first-child,
        .search-box, select, option, .data-table .table-header .d-flex,
        .data-table .table-header .btn, .page-title p,
        .modal-backdrop, .modal, .btn-action, .btn-group,
        .page-title i, .action-buttons, .btn-success, .btn-primary,
        .no-print {
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        
    
        @media (max-width: 992px) {
            .navbar-top .mobile-menu-btn {
                display: none !important;
            }
        }
        
        @media (max-width: 992px) {
            .sidebar {
                display: none;
            }
            
            .sidebar.active {
                display: block;
                position: fixed;
                top: 0;
                left: 0;
                width: 280px;
                height: 100%;
                z-index: 1050;
                background: white;
                box-shadow: 2px 0 10px rgba(0,0,0,0.1);
            }
            
            .sidebar-overlay {
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background: rgba(0,0,0,0.5);
                z-index: 1040;
                display: none;
            }
            
            .sidebar-overlay.active {
                display: block;
            }
        }
        
        .mobile-nav {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: white;
            box-shadow: 0 -2px 10px rgba(0, 0, 0, 0.1);
            padding: 8px 12px;
            z-index: 1000;
            display: none;
        }

        @media (max-width: 992px) {
            .mobile-nav {
                display: block;
            }
            
            body {
<<<<<<< HEAD
                padding-bottom: 76px;
=======
                padding-bottom: 70px;
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
            }
        }

        .mobile-nav .nav {
            display: flex;
            justify-content: space-around;
            align-items: center;
            margin: 0;
            padding: 0;
            list-style: none;
        }

        .mobile-nav .nav-item {
            flex: 1;
            text-align: center;
        }

        .mobile-nav .nav-link {
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 6px 4px;
            color: #6c757d;
            text-decoration: none;
            transition: all 0.2s;
            font-size: 0.75rem;
            position: relative;
        }

        .mobile-nav .nav-link i {
            font-size: 1.3rem;
            margin-bottom: 4px;
        }

        .mobile-nav .nav-link span {
            font-size: 0.7rem;
        }

        .mobile-nav .nav-link.active {
            color: #2E7D32;
        }

<<<<<<< HEAD
        .mobile-nav .nav-link.has-active {
            color: #2E7D32;
        }

=======
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
        .mobile-nav .nav-link.active::after {
            content: '';
            position: absolute;
            bottom: -2px;
            left: 50%;
            transform: translateX(-50%);
            width: 30px;
            height: 2px;
            background-color: #2E7D32;
            border-radius: 2px;
        }

        .mobile-nav .nav-link:hover {
            color: #2E7D32;
            background: transparent;
        }

        .dropdown-more {
            position: relative;
        }

        .more-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 4px;
            cursor: pointer;
            position: relative;
        }

        .more-btn i:first-child {
            font-size: 1.2rem;
        }

        .more-btn .dropdown-arrow {
            transition: transform 0.2s ease;
            font-size: 0.7rem;
            margin-left: 2px;
        }

        .more-btn.active .dropdown-arrow {
            transform: rotate(180deg);
        }

        .more-dropdown {
            position: absolute;
            bottom: 100%;
            right: 0;
            transform: translateY(-10px);
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
            min-width: 180px;
            z-index: 1000;
            display: none;
            opacity: 0;
            transition: opacity 0.2s ease, transform 0.2s ease;
            margin-bottom: 8px;
            overflow: hidden;
            border: 1px solid rgba(0, 0, 0, 0.08);
        }

        @media (max-width: 480px) {
            .more-dropdown {
                right: -10px;
                min-width: 170px;
            }
            
            .more-dropdown::before {
                right: 20px;
                left: auto;
                transform: translateX(0) rotate(45deg);
            }
        }

        .more-dropdown.show {
            display: block;
            opacity: 1;
            transform: translateY(0);
        }

        .more-dropdown .dropdown-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 16px;
            color: #333;
            text-decoration: none;
            transition: background 0.2s ease;
            border-bottom: 1px solid #f0f0f0;
            white-space: nowrap;
        }

        .more-dropdown .dropdown-item:last-child {
            border-bottom: none;
        }

        .more-dropdown .dropdown-item:hover {
            background: #f5f5f5;
        }

<<<<<<< HEAD
        .more-dropdown .dropdown-item.active {
            background: #ecfdf5;
            color: #047857;
            font-weight: 700;
        }

        .more-dropdown .dropdown-item.active i {
            color: #047857;
        }

=======
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
        .more-dropdown .dropdown-item i {
            width: 20px;
            font-size: 1rem;
            color: #666;
        }

        .more-dropdown .dropdown-item span {
            font-size: 0.85rem;
            font-weight: 500;
        }

        .more-dropdown .dropdown-divider {
            height: 1px;
            background: #e5e5e5;
            margin: 4px 0;
        }

        .more-dropdown .logout-item {
            color: #dc3545;
        }

        .more-dropdown .logout-item i {
            color: #dc3545;
        }

        .more-dropdown .logout-item:hover {
            background: #fff5f5;
        }

        .more-dropdown::before {
            content: '';
            position: absolute;
            bottom: -6px;
            right: 15px;
            width: 12px;
            height: 12px;
            background: white;
            border-right: 1px solid rgba(0, 0, 0, 0.08);
            border-bottom: 1px solid rgba(0, 0, 0, 0.08);
            transform: rotate(45deg);
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

.stat-card.purple-gradient {
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
    
    .stat-card .badge {
        display: inline-block !important;
        font-size: 0.5rem !important;
        padding: 0.2rem 0.4rem !important;
        margin-top: 0.2rem !important;
        text-align: center !important;
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

.stat-card-row {
    margin-bottom: 1.5rem;
}

.stat-card:hover {
    transform: translateY(-2px) !important;
    box-shadow: 0 6px 15px rgba(0, 0, 0, 0.15) !important;
}
/* ===== SALES ORDERS TABLE - MOBILE CARD VIEW ONLY ===== */
@media (max-width: 768px) {
    #salesOrdersTable,
    #salesOrdersTable tbody,
    #salesOrdersTable tr,
    #salesOrdersTable td,
    #salesOrdersTable th {
        display: block !important;
        width: 100% !important;
        box-sizing: border-box !important;
    }
    
    #salesOrdersTable thead {
        display: none !important;
    }
    
    #salesOrdersTable tbody tr {
        display: block !important;
        background: white !important;
        border-radius: 16px !important;
        margin-bottom: 16px !important;
        padding: 16px !important;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08) !important;
        border: 1px solid #e9ecef !important;
        position: relative !important;
        width: 100% !important;
        transition: all 0.2s ease !important;
        cursor: pointer !important;
    }
    
    #salesOrdersTable tbody tr:active {
        transform: scale(0.98) !important;
        background: #f8f9fa !important;
    }
    
    #salesOrdersTable tbody tr td {
        display: none !important;
    }
    
    #salesOrdersTable tbody tr td:first-child {
        display: flex !important;
        position: relative !important;
        top: auto !important;
        left: auto !important;
        padding: 0 0 8px 0 !important;
        margin: 0 !important;
        border-bottom: 1px solid #e9ecef !important;
        justify-content: flex-start !important;
    }
    
    #salesOrdersTable tbody tr td:first-child strong {
        font-size: 14px !important;
        font-weight: 700 !important;
        color: #1f2937 !important;
        display: block !important;
        background: transparent !important;
        padding: 0 !important;
    }
    
    #salesOrdersTable tbody tr td:nth-child(3) {
        display: flex !important;
        padding: 12px 0 8px 0 !important;
        margin: 0 !important;
        justify-content: flex-start !important;
        gap: 8px !important;
    }
    
    #salesOrdersTable tbody tr td:nth-child(3)::before {
        content: "Customer:" !important;
        font-weight: 500 !important;
        color: #6c757d !important;
        font-size: 13px !important;
    }
    
    #salesOrdersTable tbody tr td:nth-child(3) span {
        font-weight: 600 !important;
        color: #1f2937 !important;
        font-size: 13px !important;
    }
    
    #salesOrdersTable tbody tr td:nth-child(9) {
        display: flex !important;
        padding: 8px 0 4px 0 !important;
        margin: 0 !important;
        justify-content: flex-start !important;
        gap: 8px !important;
        align-items: center !important;
    }
    
    #salesOrdersTable tbody tr td:nth-child(9)::before {
        content: "Status:" !important;
        font-weight: 500 !important;
        color: #6c757d !important;
        font-size: 13px !important;
    }
    
    #salesOrdersTable tbody tr td:nth-child(9) .badge {
        padding: 4px 12px !important;
        font-size: 11px !important;
        min-width: 80px !important;
        text-align: center !important;
        display: inline-flex !important;
        align-items: center !important;
        justify-content: center !important;
        gap: 4px !important;
        border-radius: 20px !important;
        font-weight: 500 !important;
        margin-left: 0 !important;
    }
    
    #salesOrdersTable tbody tr td:nth-child(2),
    #salesOrdersTable tbody tr td:nth-child(4),
    #salesOrdersTable tbody tr td:nth-child(5),
    #salesOrdersTable tbody tr td:nth-child(6),
    #salesOrdersTable tbody tr td:nth-child(7),
    #salesOrdersTable tbody tr td:nth-child(8),
    #salesOrdersTable tbody tr td:nth-child(10),
    #salesOrdersTable tbody tr td:nth-child(11),
    #salesOrdersTable tbody tr td:last-child {
        display: none !important;
    }
    
    #salesOrdersTable tbody tr::after {
        content: "Tap to view details" !important;
        position: absolute !important;
        bottom: 12px !important;
        right: 12px !important;
        font-size: 9px !important;
        color: #9ca3af !important;
        background: #f8f9fa !important;
        padding: 4px 10px !important;
        border-radius: 20px !important;
        display: inline-flex !important;
        align-items: center !important;
        gap: 4px !important;
        z-index: 1 !important;
        pointer-events: none !important;
    }
    
    #salesOrdersTable tbody tr::before {
        content: "\f282" !important;
        font-family: "bootstrap-icons" !important;
        position: absolute !important;
        bottom: 12px !important;
        left: 12px !important;
        font-size: 12px !important;
        color: #9ca3af !important;
        z-index: 1 !important;
        pointer-events: none !important;
    }
}

@media (max-width: 480px) {
    #salesOrdersTable tbody tr {
        padding: 14px !important;
    }
    
    #salesOrdersTable tbody tr td:first-child strong {
        font-size: 13px !important;
    }
    
    #salesOrdersTable tbody tr td:nth-child(3) {
        padding: 10px 0 6px 0 !important;
    }
    
    #salesOrdersTable tbody tr td:nth-child(3)::before,
    #salesOrdersTable tbody tr td:nth-child(9)::before {
        font-size: 12px !important;
    }
    
    #salesOrdersTable tbody tr td:nth-child(3) span {
        font-size: 12px !important;
    }
    
    #salesOrdersTable tbody tr td:nth-child(9) .badge {
        padding: 3px 10px !important;
        font-size: 10px !important;
        min-width: 70px !important;
    }
    
    #salesOrdersTable tbody tr::after {
        font-size: 8px !important;
        bottom: 10px !important;
        right: 10px !important;
        padding: 3px 8px !important;
    }
    
    #salesOrdersTable tbody tr::before {
        font-size: 10px !important;
        bottom: 10px !important;
        left: 10px !important;
    }
}

@media (max-width: 380px) {
    #salesOrdersTable tbody tr td:first-child strong {
        font-size: 12px !important;
    }
    
    #salesOrdersTable tbody tr td:nth-child(3)::before,
    #salesOrdersTable tbody tr td:nth-child(9)::before {
        font-size: 11px !important;
    }
    
    #salesOrdersTable tbody tr td:nth-child(3) span {
        font-size: 11px !important;
    }
    
    #salesOrdersTable tbody tr td:nth-child(9) .badge {
        padding: 2px 8px !important;
        font-size: 9px !important;
        min-width: 65px !important;
    }
}

@media (max-width: 768px) and (orientation: landscape) {
    #salesOrdersTable tbody {
        display: flex !important;
        flex-wrap: wrap !important;
        gap: 12px !important;
    }
    
    #salesOrdersTable tbody tr {
        display: inline-block !important;
        width: calc(50% - 6px) !important;
        margin: 0 !important;
        vertical-align: top !important;
    }
    
    #salesOrdersTable tbody tr::before,
    #salesOrdersTable tbody tr::after {
        display: none !important;
    }
}

@media (min-width: 769px) {
    #salesOrdersTable {
        display: table !important;
        width: 100% !important;
        border-collapse: collapse !important;
    }
    
    #salesOrdersTable thead {
        display: table-header-group !important;
    }
    
    #salesOrdersTable thead tr {
        display: table-row !important;
    }
    
    #salesOrdersTable thead th {
        display: table-cell !important;
        background: var(--dark-green) !important;
        color: white !important;
        padding: 0.85rem 1.25rem !important;
        font-size: 0.8rem !important;
        font-weight: 600 !important;
        text-transform: uppercase !important;
        white-space: nowrap !important;
    }
    
    #salesOrdersTable tbody {
        display: table-row-group !important;
    }
    
    #salesOrdersTable tbody tr {
        display: table-row !important;
        background: white !important;
        border-bottom: 1px solid var(--light-green) !important;
        cursor: default !important;
        padding: 0 !important;
        margin: 0 !important;
        box-shadow: none !important;
        border-radius: 0 !important;
    }
    
    #salesOrdersTable tbody tr:hover {
        background-color: var(--light-green) !important;
    }
    
    #salesOrdersTable tbody tr td {
        display: table-cell !important;
        padding: 0.85rem 1.25rem !important;
        font-size: 0.9rem !important;
        vertical-align: middle !important;
        border-bottom: 1px solid var(--light-green) !important;
    }
    
    #salesOrdersTable tbody tr::before,
    #salesOrdersTable tbody tr::after {
        display: none !important;
        content: none !important;
    }
    
    #salesOrdersTable tbody tr td::before {
        display: none !important;
        content: none !important;
    }
    
    #salesOrdersTable tbody tr td {
        display: table-cell !important;
    }
    
    #salesOrdersTable tbody tr td:first-child {
        position: static !important;
        padding: 0.85rem 1.25rem !important;
        border-bottom: 1px solid var(--light-green) !important;
    }
    
    #salesOrdersTable tbody tr td:nth-child(3) {
        display: table-cell !important;
        padding: 0.85rem 1.25rem !important;
    }
    
    #salesOrdersTable tbody tr td:nth-child(9) {
        display: table-cell !important;
        padding: 0.85rem 1.25rem !important;
    }
    
    #salesOrdersTable tbody tr td:nth-child(2),
    #salesOrdersTable tbody tr td:nth-child(4),
    #salesOrdersTable tbody tr td:nth-child(5),
    #salesOrdersTable tbody tr td:nth-child(6),
    #salesOrdersTable tbody tr td:nth-child(7),
    #salesOrdersTable tbody tr td:nth-child(8),
    #salesOrdersTable tbody tr td:nth-child(10),
    #salesOrdersTable tbody tr td:nth-child(11),
    #salesOrdersTable tbody tr td:last-child {
        display: table-cell !important;
    }
    
    #salesOrdersTable tbody tr td:last-child .action-buttons {
        display: flex !important;
        gap: 0.5rem !important;
        justify-content: flex-start !important;
    }
    
    #salesOrdersTable tbody tr td:last-child .btn-action {
        width: 32px !important;
        height: 32px !important;
        display: inline-flex !important;
    }
    
    #salesOrdersTable tbody tr td:last-child .btn-action.btn-view,
    #salesOrdersTable tbody tr td:last-child .btn-action.btn-edit,
    #salesOrdersTable tbody tr td:last-child .btn-action.btn-delete,
    #salesOrdersTable tbody tr td:last-child .btn-action.btn-print {
        display: inline-flex !important;
    }
}

.filter-content {
    display: none !important;
}

.filter-content:not(.collapsed) {
    display: block !important;
}

.filter-toggle-btn[aria-expanded="false"] i {
    transform: rotate(0deg) !important;
}

.filter-toggle-btn[aria-expanded="true"] i {
    transform: rotate(180deg) !important;
}

.select2-container--default .select2-dropdown {
    z-index: 9999 !important;
}

.select2-container--default .select2-selection--single {
    border-radius: 10px !important;
    border: 1.5px solid #e9ecef !important;
    padding: 0.4rem 0.875rem !important;
    height: auto !important;
}

.select2-container--default .select2-selection--single .select2-selection__rendered {
    line-height: 1.5 !important;
    color: #212529 !important;
}

.select2-container--default .select2-selection--single .select2-selection__arrow {
    height: 100% !important;
    top: 0 !important;
    right: 8px !important;
}

.modal-open .select2-container--open {
    z-index: 9999 !important;
}

.modal-open .select2-dropdown {
    z-index: 9999 !important;
}

.select2-container--default .select2-search--dropdown .select2-search__field {
    border-radius: 8px !important;
    border: 1px solid #e9ecef !important;
    padding: 8px 12px !important;
}

.select2-container--default .select2-search--dropdown .select2-search__field:focus {
    border-color: #44D34E !important;
    box-shadow: 0 0 0 2px rgba(68, 211, 78, 0.1) !important;
    outline: none !important;
}

#editOrderModal .select2-container--default .select2-results__option {
    padding: 10px 16px !important;
    font-size: 0.85rem !important;
    color: #1f2937 !important;
    background: #ffffff !important;
    transition: all 0.15s ease !important;
}

#editOrderModal .select2-container--default .select2-results__option--highlighted.select2-results__option--selectable {
    background: #e8f5e9 !important;
    color: #000000 !important;
    font-weight: 700 !important;
}

#editOrderModal .select2-container--default .select2-results__option--selected {
    background: #f0fdf4 !important;
    color: #047857 !important;
    font-weight: 600 !important;
    position: relative !important;
}

#editOrderModal .select2-container--default .select2-results__option--selected.select2-results__option--highlighted {
    background: #dcfce7 !important;
    color: #000000 !important;
    font-weight: 700 !important;
}

#editOrderModal .select2-container--default .select2-results__option--disabled {
    color: #9ca3af !important;
    cursor: not-allowed !important;
}

#editOrderModal .select2-container--default .select2-results__group {
    background: #f8fafc !important;
    color: #475569 !important;
    font-size: 0.7rem !important;
    font-weight: 700 !important;
    letter-spacing: 0.5px !important;
    text-transform: uppercase !important;
    padding: 10px 16px 6px 16px !important;
    border-bottom: 1px solid #e9ecef !important;
}

#editOrderModal .select2-container--default .select2-results__option .badge,
#editOrderModal .select2-container--default .select2-results__option span .badge {
    display: inline-block !important;
    background: #fef3c7 !important;
    color: #92400e !important;
    font-size: 0.7rem !important;
    font-weight: 600 !important;
    padding: 2px 8px !important;
    border-radius: 20px !important;
    margin-left: 8px !important;
}

#editOrderModal .select2-container--default .select2-results__option .available-badge,
#editOrderModal .select2-container--default .select2-results__option span .available-badge {
    display: inline-block !important;
    background: #dcfce7 !important;
    color: #166534 !important;
    font-size: 0.7rem !important;
    font-weight: 600 !important;
    padding: 2px 8px !important;
    border-radius: 20px !important;
    margin-left: 8px !important;
}

#editOrderModal .select2-container--default .select2-results__option--highlighted .badge {
    background: #fde68a !important;
    color: #000000 !important;
    font-weight: 700 !important;
}

#editOrderModal .select2-container--default .select2-results__option--highlighted .available-badge {
    background: #bbf7d0 !important;
    color: #000000 !important;
    font-weight: 700 !important;
}
/* ===== ORDER DETAILS MODAL - ITEMS TABLE STYLES (gaya ng customer.php) ===== */

/* Items table inside modal */
.items-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.85rem;
    background: white;
    border: 1px solid #dee2e6;
}

.items-table thead th {
    background-color: #f8f9fa;
    padding: 10px 12px;
    font-weight: 600;
    border-bottom: 2px solid #dee2e6;
    border-right: 1px solid #dee2e6;
    color: #212529;
}

.items-table thead th:last-child {
    border-right: none;
}

.items-table tbody td {
    padding: 8px 12px;
    border-bottom: 1px solid #e9ecef;
    border-right: 1px solid #e9ecef;
    vertical-align: middle;
}

.items-table tbody td:last-child {
    border-right: none;
}

.items-table tbody tr:last-child td {
    border-bottom: none;
}

.items-table tbody tr:hover td {
    background-color: #f5f5f5;
}

/* Grand Total row - walang background, green ang amount */
.items-table .total-row td {
    background-color: transparent !important;
    font-weight: bold;
    border-top: 1px solid #dee2e6;
}

/* Green color para sa Grand Total amount (last cell) */
.items-table .total-row td:last-child {
    color: #2E7D32 !important;
    font-weight: 700;
}

/* Responsive para sa mobile */
@media (max-width: 768px) {
    .items-table thead {
        display: none;
    }
    
    .items-table tbody tr {
        display: block;
        margin-bottom: 1rem;
        border: 1px solid #dee2e6;
        border-radius: 8px;
        overflow: hidden;
    }
    
    .items-table tbody td {
        display: flex;
        justify-content: space-between;
        align-items: center;
        border: none;
        border-bottom: 1px solid #f0f0f0;
        padding: 8px 12px;
        text-align: right;
    }
    
    .items-table tbody td:last-child {
        border-bottom: none;
    }
    
    .items-table tbody td::before {
        content: attr(data-label);
        font-weight: 600;
        color: #2E7D32;
        text-align: left;
        width: 40%;
    }
    
    .items-table .total-row td {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 10px 12px;
        background-color: transparent !important;
    }
    
    .items-table .total-row td:last-child {
        color: #2E7D32 !important;
    }
}
<<<<<<< HEAD
    


/* Serve / Deliver payment modal - polished Rolling UI */
#serveDeliverModal .modal-content {
    border: 0;
    border-radius: 18px;
    overflow: hidden;
    box-shadow: 0 18px 45px rgba(15, 23, 42, 0.25);
}
#serveDeliverModal .modal-header {
    background: linear-gradient(135deg, #047857, #22c55e) !important;
    padding: 1rem 1.25rem;
}
#serveDeliverModal .modal-body {
    background: #f8fafc;
    padding: 1.25rem;
}
#serveDeliverModal .modal-footer {
    background: #ffffff;
    border-top: 1px solid #e5e7eb;
}
.serve-summary-card,
.serve-payment-card {
    background: #ffffff;
    border: 1px solid #e5e7eb;
    border-radius: 16px;
    padding: 1rem;
    box-shadow: 0 4px 14px rgba(15, 23, 42, 0.06);
}
#serveDeliverModal .form-label {
    color: #052A47;
    font-weight: 700;
    margin-bottom: 0.45rem;
}
#serveDeliverModal .form-control,
#serveDeliverModal .form-select {
    border-radius: 10px;
    border: 1px solid #cbd5e1;
    min-height: 46px;
    font-size: 0.95rem;
}
#serveDeliverModal .form-control:focus,
#serveDeliverModal .form-select:focus {
    border-color: #047857;
    box-shadow: 0 0 0 0.18rem rgba(4, 120, 87, 0.12);
}
#serveDeliverModal .form-control[readonly] {
    background-color: #eef2f7;
    color: #0f172a;
    font-weight: 600;
}
.serve-payment-options {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 0.75rem;
    width: 100%;
}
.serve-payment-option {
    appearance: none;
    border: 1px solid #d1d5db !important;
    background: #ffffff !important;
    color: #374151 !important;
    border-radius: 14px !important;
    padding: 0.85rem 0.9rem !important;
    font-weight: 700 !important;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.45rem;
    transition: all 0.2s ease;
    line-height: 1.2;
    box-shadow: 0 2px 8px rgba(15, 23, 42, 0.05);
}
.serve-payment-option i {
    font-size: 1.05rem;
}
.serve-payment-option.active,
.serve-payment-option:hover,
.serve-payment-option:focus {
    background: linear-gradient(135deg, #047857, #22c55e) !important;
    border-color: #047857 !important;
    color: #ffffff !important;
    outline: none !important;
    box-shadow: 0 6px 16px rgba(4, 120, 87, 0.25) !important;
    transform: translateY(-1px);
}
.serve-payment-help {
    color: #64748b;
    font-size: 0.82rem;
    margin-top: 0.45rem;
}
#serveDeliverSubmitBtn {
    background: linear-gradient(135deg, #047857, #22c55e) !important;
    border: none !important;
    border-radius: 10px;
    font-weight: 700;
    padding: 0.65rem 1rem;
}
#serveDeliverSubmitBtn:hover {
    box-shadow: 0 6px 16px rgba(4, 120, 87, 0.25);
    transform: translateY(-1px);
}
@media (max-width: 768px) {
    .serve-payment-options {
        grid-template-columns: 1fr;
    }
    #serveDeliverModal .modal-dialog {
        margin: 0.75rem;
    }
}

/* Sales Order Tabs - gaya ng current_inventory category tabs */
.sales-order-tabs {
    width: 100%;
    margin-bottom: 1rem;
}

/* Category Tabs Container - responsive, walang scroll bar */
.category-tabs {
    display: flex;
    flex-wrap: wrap;  /* Wrap sa halip na scroll */
    gap: 0.5rem;
    padding-bottom: 0.5rem;
    /* Remove overflow properties - walang scroll bar */
    overflow-x: visible;
    overflow-y: visible;
}

/* RECTANGLE shape with border radius - HINDI OBLONG */
.category-tab {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    padding: 0.5rem 1rem;
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 10px !important;
    font-size: 0.85rem;
    font-weight: 500;
    color: #475569;
    cursor: pointer;
    transition: all 0.2s ease;
    box-shadow: 0 1px 2px rgba(0,0,0,0.05);
    /* Pwedeng mag-stretch o mag-shrink */
    flex: 1 1 auto;
    min-width: fit-content;
    text-align: center;
    white-space: nowrap;
}

.category-tab i {
    font-size: 0.9rem;
}

.category-tab .tab-badge {
    background: #f1f5f9;
    color: #475569;
    padding: 0.15rem 0.45rem;
    border-radius: 30px;
    font-size: 0.7rem;
    font-weight: 600;
    margin-left: 0.3rem;
}

.category-tab.active {
    background: linear-gradient(135deg, #047857, #059669);
    border-color: #047857;
    color: white;
    box-shadow: 0 2px 6px rgba(4, 120, 87, 0.2);
}

.category-tab.active .tab-badge {
    background: rgba(255,255,255,0.2);
    color: white;
}

.category-tab:hover:not(.active) {
    background: #f8fafc;
    border-color: #cbd5e1;
    transform: translateY(-1px);
}

/* ===== MOBILE FIX - FIT SA SCREEN, WALANG SCROLL BAR ===== */
@media (max-width: 768px) {
    .category-tabs {
        gap: 0.4rem;
    }
    
    .category-tab {
        padding: 0.4rem 0.5rem;
        font-size: 0.7rem;
        gap: 0.3rem;
        border-radius: 8px !important;
        flex: 1 1 0;  /* Pahabain para magkasya sa container */
        min-width: 0;  /* Payagan ang pag-shrink */
        white-space: normal;  /* Pwedeng mag-wrap ang text kung kinakailangan */
        word-break: keep-all;
    }
    
    .category-tab i {
        font-size: 0.7rem;
    }
    
    .category-tab .tab-badge {
        font-size: 0.55rem;
        padding: 0.1rem 0.3rem;
        margin-left: 0.2rem;
    }
}

/* Para sa tatlong tabs - siguradong magkakasya */
@media (max-width: 550px) {
    .category-tab {
        flex: 1 1 0;
        min-width: 0;
        padding: 0.35rem 0.3rem;
        font-size: 0.68rem;
    }
    
    /* Siguraduhing hindi mag-wrap ang text sa tatlong tabs */
    .category-tab .tab-text {
        white-space: nowrap;
    }
}

/* Very small screens (below 400px) - adjust font/padding */
@media (max-width: 400px) {
    .category-tab {
        padding: 0.3rem 0.25rem;
        font-size: 0.6rem;
        border-radius: 6px !important;
        gap: 0.2rem;
    }
    
    .category-tab i {
        font-size: 0.6rem;
    }
    
    .category-tab .tab-badge {
        font-size: 0.5rem;
        padding: 0.05rem 0.25rem;
    }
}

/* Desktop - normal behavior */
@media (min-width: 769px) {
    .category-tab {
        padding: 0.5rem 1.25rem;
        font-size: 0.85rem;
        flex: 0 0 auto;  /* Hindi mag-stretch sa desktop */
        white-space: nowrap;
    }
}
</style>
=======
    </style>
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
</head>
<body>
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
                    <a class="nav-link" href="customer_orderproduct.php">
                        <i class="bi bi-person-plus"></i>
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
                    <a class="nav-link active" href="sales_order.php">
                        <i class="bi bi-cart"></i>
                        <span class="nav-text">Sales Orders</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="purchase_order.php">
                        <i class="bi bi-truck"></i>
<<<<<<< HEAD
                        <span class="nav-text">Recieve Inventory</span>
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
=======
                        <span class="nav-text">Purchase Orders</span>
                    </a>
                </li>
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
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
            <div id="dashboardContent" class="page-content active">
                <div class="navbar-top no-print">
                    <button class="mobile-menu-btn" id="mobileMenuBtn">
                        <i class="bi bi-list"></i>
                    </button>
                    
                    <div class="page-title">
                        <h2>Sales Orders</h2>
                        <p id="dashboardSubtitle">
                            Manage and track all sales orders
                        </p>
                    </div>
                </div>

                <!-- Database Fix Alert -->
                <?php if (!$invoice_so_column_exists): ?>
                <div class="db-fix-card no-print">
                    <div class="d-flex align-items-center mb-3">
                        <i class="bi bi-database fs-1 me-3 text-warning"></i>
                        <div>
                            <h4 class="mb-1 text-warning">Database Relationship Missing</h4>
                            <p class="mb-0 text-muted">The invoices table doesn't have a column linking to sales_orders.</p>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-8">
                            <p class="fw-bold mb-2">Run this SQL in phpMyAdmin to fix:</p>
                            <pre class="mb-3"><code>ALTER TABLE invoices ADD COLUMN so_id INT NULL;
ALTER TABLE invoices ADD FOREIGN KEY (so_id) REFERENCES sales_orders(so_id);</code></pre>
                        </div>
                        <div class="col-md-4 d-flex align-items-center">
                            <button class="btn btn-warning w-100" onclick="copyFixSQL()">
                                <i class="bi bi-files me-2"></i>Copy SQL
                            </button>
                        </div>
                    </div>
                    
                    <div class="alert alert-info mt-3 mb-0">
                        <i class="bi bi-info-circle me-2"></i>
                        <strong>Workaround Mode:</strong> Invoice features are currently disabled. The system will work normally for sales orders, pick lists, and trip tickets.
                    </div>
                </div>
                <?php endif; ?>

                <!-- Overdue status missing alert -->
                <?php if ($invoice_so_column_exists && !$invoice_has_overdue): ?>
                <div class="alert alert-warning alert-dismissible fade show no-print" role="alert">
                    <i class="bi bi-exclamation-triangle"></i> 
                    <strong>Invoice status 'overdue' not available.</strong> Please run:
                    <br><br>
                    <code>ALTER TABLE invoices MODIFY COLUMN status ENUM('pending','paid','cancelled','overdue') NOT NULL DEFAULT 'pending';</code>
                    <br><br>
                    <button class="btn btn-sm btn-warning" onclick="copyOverdueSQL()">Copy SQL</button>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <script>
                function copyOverdueSQL() {
                    navigator.clipboard.writeText("ALTER TABLE invoices MODIFY COLUMN status ENUM('pending','paid','cancelled','overdue') NOT NULL DEFAULT 'pending';");
                    Swal.fire({icon:'success', title:'Copied!', timer:1500, showConfirmButton:false});
                }
                </script>
                <?php endif; ?>

                <!-- Branch Info Alerts -->
                <?php if (!$so_branch_column_exists): ?>
                    <div class="alert alert-info alert-dismissible fade show no-print" role="alert">
                        <i class="bi bi-info-circle"></i> 
                        <strong>Branch filtering for sales orders not yet set up.</strong> Run this SQL:
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

                <?php if (!$customers_branch_column_exists): ?>
                    <div class="alert alert-info alert-dismissible fade show no-print" role="alert">
                        <i class="bi bi-info-circle"></i> 
                        <strong>Branch filtering for customers not yet set up.</strong> Run this SQL:
                        <br><br>
                        <code>ALTER TABLE customers ADD COLUMN branch_id INT NULL;</code>
                        <br>
                        <code>ALTER TABLE customers ADD FOREIGN KEY (branch_id) REFERENCES branches(branch_id);</code>
                        <br><br>
                        <button type="button" class="btn btn-sm btn-primary" onclick="copySQL('customers')">
                            <i class="bi bi-files"></i> Copy SQL
                        </button>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if (!$drivers_branch_column_exists): ?>
                    <div class="alert alert-info alert-dismissible fade show no-print" role="alert">
                        <i class="bi bi-info-circle"></i> 
                        <strong>Branch filtering for drivers not yet set up.</strong> Run this SQL:
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

                <!-- No Orders Warning -->
                <?php if (empty($sales_orders) && $so_branch_column_exists && !$view_all_branches): ?>
                    <div class="alert alert-warning no-print">
                        <i class="bi bi-exclamation-triangle"></i> 
                        No sales orders found for your branch.
                    </div>
                <?php endif; ?>
                
                <!-- Quick Stats -->
<div class="row stat-card-row g-1 g-sm-2 mb-4">
    <div class="col">
        <div class="stat-card total">
            <i class="bi bi-cart-check stat-icon"></i>
            <div class="stat-content">
                <div class="stat-value"><?= $statTotalOrders ?></div>
                <div class="stat-label">Total Orders</div>
                <?php if ($so_branch_column_exists && !$view_all_branches): ?>
                    <small class="d-block">Your branch</small>
                <?php else: ?>
                    <small class="d-block">All time sales orders</small>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <div class="col">
        <div class="stat-card pending">
            <i class="bi bi-clock-history stat-icon"></i>
            <div class="stat-content">
                <div class="stat-value"><?= $statPendingOrders ?></div>
                <div class="stat-label">Pending</div>
                <small class="d-block">Awaiting confirmation</small>
            </div>
        </div>
    </div>
    
<div class="col">
    <div class="stat-card purple-gradient">
        <i class="bi bi-calendar-week stat-icon"></i>
        <div class="stat-content">
            <div class="stat-value" id="dateRangeSalesValue"><?= $statCurrentWeekSales ?></div>
            <div class="stat-label" id="dateRangeSalesLabel">Period Sales</div>
            <small class="d-block" id="dateRangeSalesSubtitle">
                <span id="dateRangeText">Current week (Mon-Sat)</span>
            </small>
        </div>
    </div>
</div>
    
    <div class="col">
        <div class="stat-card complete">
            <i class="bi bi-check-circle stat-icon"></i>
            <div class="stat-content">
                <div class="stat-value"><?= $statCompletedOrders ?></div>
                <div class="stat-label">Completed</div>
                <small class="d-block">Successfully delivered</small>
            </div>
        </div>
    </div>
</div>

<!-- Search and Filter -->
<div class="form-card mb-4">
    <div class="filter-header">
        <h5>
            <i class="bi bi-funnel-fill"></i> Search & Filters
            <span class="filter-count-badge" id="filterActiveBadge" style="display: none;">
                <span id="activeFilterCount">0</span>
            </span>
        </h5>
        <button class="filter-toggle-btn" type="button" id="filterToggleBtn" aria-expanded="true">
            <i class="bi bi-chevron-down" id="filterIcon"></i>
        </button>
    </div>
    
    <div class="filter-content" id="filterContent">
        <div class="date-filter-container">
            <div class="date-input-group">
                <label><i class="bi bi-calendar"></i> From:</label>
                <input type="date" id="startDate" value="<?= $default_start_date ?>">
            </div>
            <div class="date-input-group">
                <label><i class="bi bi-calendar"></i> To:</label>
                <input type="date" id="endDate" value="<?= $default_end_date ?>">
            </div>
            
            <div class="button-row">
                <button class="date-filter-btn" onclick="applyManualDateRangeFilter()">
                    <i class="bi bi-funnel"></i> <span>Apply</span>
                </button>
                <button class="date-reset-week-btn" onclick="resetToCurrentWeek()">
                    <i class="bi bi-calendar-week"></i> <span>Week</span>
                </button>
                <button class="date-clear-btn" onclick="clearAllFilters()">
                    <i class="bi bi-x-circle"></i> <span>Clear</span>
                </button>
            </div>
        </div>
        
        <div class="row g-2 mt-2">
            <div class="col-12 col-md-4">
                <label class="form-label">
                    <i class="bi bi-search"></i> Search
                </label>
                <div class="search-box-wrapper">
                    <input type="text" class="form-control" id="searchInput" placeholder="Order number or customer..." onkeyup="applyManualFilters()">
                </div>
            </div>
            
            <div class="col-12 col-md-4">
                <label class="form-label">
                    <i class="bi bi-flag"></i> Status
                </label>
                <select class="form-select" id="statusFilter" onchange="applyManualFilters()">
                    <option value="">All Status</option>
                    <option value="pending">Pending</option>
                    <option value="confirmed">Confirmed</option>
                    <option value="processing">Processing</option>
                    <option value="ready">For Delivery</option>
                    <option value="in_transit">In Transit</option>
                    <option value="delivered">Delivered</option>
                    <option value="cancelled">Cancelled</option>
                </select>
            </div>
            
            <div class="col-12 col-md-4">
                <label class="form-label">
                    <i class="bi bi-people"></i> Customer
                </label>
                <select class="form-select" id="customerFilter" onchange="applyManualFilters()">
                    <option value="">All Customers</option>
                    <?php foreach ($customers as $customer): ?>
                        <option value="<?= htmlspecialchars($customer['customer_name']) ?>">
                            <?= htmlspecialchars($customer['customer_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </div>
</div>

                <!-- Action Buttons -->
                <div class="mb-3 d-flex gap-2 no-print">
                    <button class="btn btn-primary" onclick="printAllOrders()">
                        <i class="bi bi-printer"></i> Print All Orders
                    </button>
                    <button class="btn btn-success" onclick="exportToExcel()">
                        <i class="bi bi-file-earmark-excel"></i> Export to Excel
                    </button>
                </div>

<<<<<<< HEAD
                <!-- Sales Order Tabs - gaya ng current_inventory.php -->
<div class="sales-order-tabs no-print mb-3">
    <div class="category-tabs" id="salesOrderTabs">
        <div class="category-tab active" data-tab="historical" onclick="setSalesOrderTab('historical')">
            <i class="bi bi-clock-history"></i> Historical
            <span class="tab-badge" id="tabHistoricalCount">0</span>
        </div>
        <div class="category-tab" data-tab="completed_today" onclick="setSalesOrderTab('completed_today')">
            <i class="bi bi-check2-circle"></i> Completed Today
            <span class="tab-badge" id="tabCompletedTodayCount">0</span>
        </div>
        <div class="category-tab" data-tab="pending_today" onclick="setSalesOrderTab('pending_today')">
            <i class="bi bi-hourglass-split"></i> Pending Today
            <span class="tab-badge" id="tabPendingTodayCount">0</span>
        </div>
    </div>
    <div class="small text-muted mt-2" id="activeTabDescription">
        Showing all sales orders.
    </div>
</div>

=======
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
                <!-- Sales Orders Table -->
                <div class="data-table">
                    <div class="table-header d-flex justify-content-between align-items-center no-print">
                        <h5 class="mb-0">Sales Orders</h5>
                        <div class="d-flex gap-2 align-items-center">
                            <?php if ($so_branch_column_exists && $view_all_branches): ?>
                                <span class="badge bg-success">All Branches</span>
                            <?php endif; ?>
                            <span class="text-muted me-2">Total: ₱<?= number_format(array_sum(array_column($sales_orders, 'total_amount')), 2) ?></span>
                        </div>
                    </div>
                    <div class="table-container">
                        <table class="table custom-table compact-table" id="salesOrdersTable">
                            <thead>
                                <tr>
                                    <th>Order No.</th>
                                    <th>Date</th>
                                    <th>Customer</th>
                                    <?php if ($so_branch_column_exists && $view_all_branches): ?>
                                        <th>Branch</th>
                                    <?php endif; ?>
                                    <th>Items</th>
                                    <th>Qty</th>
                                    <th>Total Amount</th>
                                    <th>Order Status</th>
                                    <th class="no-print">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="salesOrdersTableBody">
                                <?php if (empty($sales_orders)): ?>
                                <tr>
                                    <td colspan="<?= ($so_branch_column_exists && $view_all_branches) ? '9' : '8' ?>" class="text-center py-4">
                                        <i class="bi bi-inbox fs-1 d-block text-muted mb-2"></i>
                                        <p class="text-muted mb-0">No sales orders found</p>
                                    </div>
                                </tr>
                                <?php else: ?>
                                    <?php foreach ($sales_orders as $order): ?>
                                    <tr class="sales-order-row" 
                                        data-id="<?= $order['so_id'] ?>"
                                        data-order-number="<?= htmlspecialchars($order['so_number']) ?>"
                                        data-customer="<?= htmlspecialchars($order['customer_name']) ?>"
                                        data-status="<?= $order['order_status'] ?>"
                                        data-date="<?= $order['created_at'] ?>"
                                        data-amount="<?= $order['total_amount'] ?>"
                                        data-items="<?= $order['total_items'] ?? 0 ?>"
                                        data-qty="<?= $order['total_quantity'] ?? 0 ?>"
                                        data-credit-limit="<?= $order['credit_limit'] ?? 0 ?>"
                                        data-credit-used="<?= $order['credit_used'] ?? 0 ?>">
                                        <td><strong><?= htmlspecialchars($order['so_number']) ?></strong></td>
                                        <td><?= formatDate($order['created_at']) ?></td>
                                        <td><?= htmlspecialchars($order['customer_name']) ?></div>
                                        <?php if ($so_branch_column_exists && $view_all_branches): ?>
                                            <td>
                                                <span class="badge bg-info">
                                                    <?= htmlspecialchars($order['branch_name'] ?? 'Branch ' . $order['branch_id']) ?>
                                                </span>
                                            </div>
                                        <?php endif; ?>
                                        <td class="text-center"><?= $order['total_items'] ?? 0 ?></div>
                                        <td class="text-center"><?= $order['total_quantity'] ?? 0 ?></div>
                                        <td class="text-end">₱<?= number_format($order['total_amount'] ?? 0, 2) ?></div>
                                        <td>
                                            <span class="<?= getOrderStatusBadge($order['order_status']) ?>">
                                                <?= getOrderStatusText($order['order_status']) ?>
                                            </span>
                                        </div>
                                        <td class="no-print">
                                            <div class="action-buttons">
                                                <button class="btn-action btn-view" onclick="viewOrder(<?= $order['so_id'] ?>)" title="View">
                                                    <i class="bi bi-eye"></i>
                                                </button>
                                                <button class="btn-action btn-print" onclick="printSingleOrder(<?= $order['so_id'] ?>)" title="Print Order">
                                                    <i class="bi bi-printer"></i>
                                                </button>
<<<<<<< HEAD
                                                <!-- View-only: edit/delete actions removed for Rolling -->
=======
                                                <?php if ($order['order_status'] == 'pending'): ?>
                                                    <button class="btn-action btn-edit" onclick="editOrder(<?= $order['so_id'] ?>)" title="Edit">
                                                        <i class="bi bi-pencil"></i>
                                                    </button>
                                                    <button class="btn-action btn-delete" onclick="deleteOrder(<?= $order['so_id'] ?>)" title="Delete">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                <?php endif; ?>
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
                                            </div>
                                        </div>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

     <!-- Mobile Bottom Navigation -->
    <div class="mobile-nav" id="mobileNav">
        <ul class="nav">
<<<<<<< HEAD
            <li class="nav-item">
                <a class="nav-link" href="current_inventory.php">
                    <i class="bi bi-box-seam"></i>
                    <span>Inventory</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="customer_orderproduct.php">
                    <i class="bi bi-person-plus"></i>
                    <span>Orders</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="collections.php">
                    <i class="bi bi-cash-stack"></i>
                    <span>Collect</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link active" href="sales_order.php">
                    <i class="bi bi-cart"></i>
                    <span>Sales</span>
                </a>
            </li>
            <li class="nav-item dropdown-more" id="moreDropdown">
                <a class="nav-link more-btn" href="#" onclick="toggleDropdown(event, 'moreDropdownMenu')">
                    <i class="bi bi-three-dots"></i>
                    <span>More</span>
                </a>
                <div class="more-dropdown" id="moreDropdownMenu">
                    <a href="purchase_order.php" class="dropdown-item">
                        <i class="bi bi-truck"></i>
                        <span>Receive Inventory</span>
                    </a>
                    <a href="expenses.php" class="dropdown-item">
                        <i class="bi bi-receipt-cutoff"></i>
                        <span>Expenses</span>
                    </a>
                    <a href="reports.php" class="dropdown-item">
                        <i class="bi bi-file-earmark-bar-graph"></i>
                        <span>Reports</span>
                    </a>
                    <div class="dropdown-divider"></div>
                    <a href="#" class="dropdown-item logout-item" onclick="logout(); return false;">
                        <i class="bi bi-box-arrow-right"></i>
                        <span>Logout</span>
                    </a>
                </div>
            </li>
=======
            <li class="nav-item dropdown-more" id="inventoryDropdown"><a class="nav-link more-btn" href="#" onclick="toggleDropdown(event, 'inventoryDropdownMenu')"><i class="bi bi-box-seam"></i><span>Inventory</span></a><div class="more-dropdown" id="inventoryDropdownMenu"><a href="current_inventory.php" class="dropdown-item"><i class="bi bi-bar-chart-line"></i><span>Current Inventory</span></a><a href="bad_orders.php" class="dropdown-item"><i class="bi bi-recycle"></i><span>Bad Orders</span></a></div></li>
            <li class="nav-item dropdown-more" id="salesDropdown"><a class="nav-link more-btn" href="#" onclick="toggleDropdown(event, 'salesDropdownMenu')"><i class="bi bi-cart"></i><span>Sales</span></a><div class="more-dropdown" id="salesDropdownMenu"><a href="sales_order.php" class="dropdown-item"><i class="bi bi-cart"></i><span>Sales Orders</span></a><a href="pick_list_items.php" class="dropdown-item"><i class="bi bi-list-check"></i><span>Pick Lists</span></a></div></li>
            <li class="nav-item dropdown-more" id="purchaseDropdown"><a class="nav-link more-btn" href="#" onclick="toggleDropdown(event, 'purchaseDropdownMenu')"><i class="bi bi-truck"></i><span>Purchase</span></a><div class="more-dropdown" id="purchaseDropdownMenu" style="right: 0 !important; left: auto !important;"><a href="purchase_order.php" class="dropdown-item"><i class="bi bi-box"></i><span>Purchase Orders</span></a><a href="supplier.php" class="dropdown-item"><i class="bi bi-building"></i><span>Suppliers</span></a></div></li>
            <li class="nav-item"><a class="nav-link" href="trip_tickets.php"><i class="bi bi-ticket-perforated"></i><span>Trips</span></a></li>
            <li class="nav-item dropdown-more" id="moreDropdown"><a class="nav-link more-btn" href="#" onclick="toggleDropdown(event, 'moreDropdownMenu')"><i class="bi bi-three-dots-vertical"></i><span>More</span></a><div class="more-dropdown" id="moreDropdownMenu"><a href="drivers.php" class="dropdown-item"><i class="bi bi-people"></i><span>Users</span></a><a href="approve_credit_requests.php" class="dropdown-item"><i class="bi bi-pencil-square"></i><span>Approve Requests</span></a><div class="dropdown-divider"></div><a href="#" class="dropdown-item logout-item" onclick="showProfileModal(); return false;"><i class="bi bi-box-arrow-right"></i><span>Logout</span></a></div></li>
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
        </ul>
    </div>

     <!-- Mobile Profile Modal -->
           <div class="modal fade" id="profileModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h5 class="modal-title"><i class="bi bi-person-circle me-2"></i>User Profile</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body text-center"><div class="user-avatar-large mb-3"><?php echo $user_initials; ?></div><h4 class="mb-1"><?php echo htmlspecialchars($user_name); ?></h4><p class="text-muted mb-3"><span class="badge bg-success"><?php echo ucfirst($user_role); ?></span></p><?php if (!$view_all_branches && $branch_id > 0): ?><div class="branch-info mb-3"><i class="bi bi-building me-1"></i><span><?php echo htmlspecialchars($branch_name); ?></span></div><?php endif; ?><div class="user-id text-muted small mb-4"></div><button class="btn btn-danger btn-lg w-100" onclick="confirmLogout()"><i class="bi bi-box-arrow-right me-2"></i>Logout</button></div></div></div></div>
    <!-- VIEW ORDER MODAL -->
<div class="modal fade no-print" id="viewOrderModal" tabindex="-1" data-bs-backdrop="true" data-bs-keyboard="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title"><i class="bi bi-eye me-2"></i>Sales Order Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div id="viewOrderContent"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" onclick="printOrder(currentOrderId)" id="printOrderBtn">Print Order</button>
<<<<<<< HEAD
                <!-- View-only: edit/delete buttons removed -->
            </div>
        </div>
    </div>
</div>

<!-- SERVE / DELIVER COLLECTION MODAL -->
<div class="modal fade no-print" id="serveDeliverModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="bi bi-truck me-2"></i>Serve / Deliver Order</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="serveOrderId">
                <input type="hidden" id="serveSelectedPaymentMethod" value="cash">

                <div class="alert alert-info mb-3">
                    <i class="bi bi-info-circle me-1"></i>
                    This will mark the order as <strong>Delivered</strong>. The collected payment will be saved as a <strong>collection record</strong> and will only become paid after you remit it and Branch Admin approves it.
                </div>

                <div class="serve-summary-card mb-3">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Order No.</label>
                            <input type="text" class="form-control" id="serveOrderNo" readonly>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Customer</label>
                            <input type="text" class="form-control" id="serveCustomerName" readonly>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Order Total</label>
                            <input type="text" class="form-control" id="serveOrderTotalDisplay" readonly>
                        </div>
                    </div>
                </div>

                <div class="serve-payment-card">
                    <div class="mb-3">
                        <label for="servePaymentMethodSelect" class="form-label fw-bold">Payment Method</label>
                        <select class="form-select" id="servePaymentMethodSelect" onchange="selectServePaymentMethod(this.value)">
                            <option value="cash" selected>Cash</option>
                            <option value="check">Check</option>
                            <option value="online_transfer">Online Transfer</option>
                        </select>
                        <div class="serve-payment-help mt-2">This collection will be saved for remittance. It will not mark the invoice as paid until Branch Admin approves the remittance.</div>
                    </div>

                    <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Collected Amount *</label>
                        <input type="number" class="form-control" id="serveCollectionAmount" step="0.01" min="0.01" oninput="recalcServeCashChange()">
                    </div>
                    <div class="col-md-4 serve-cash-field">
                        <label class="form-label">Cash Tendered</label>
                        <input type="number" class="form-control" id="serveCashTendered" step="0.01" min="0" oninput="recalcServeCashChange()">
                    </div>
                    <div class="col-md-4 serve-cash-field">
                        <label class="form-label">Change</label>
                        <input type="text" class="form-control" id="serveCashChangeDisplay" value="₱0.00" readonly>
                        <input type="hidden" id="serveCashChange" value="0">
                    </div>

                    <div class="col-md-4 serve-check-field d-none">
                        <label class="form-label">Check Date *</label>
                        <input type="date" class="form-control" id="serveCheckDate">
                    </div>
                    <div class="col-md-4 serve-check-field d-none">
                        <label class="form-label">Check Number *</label>
                        <input type="text" class="form-control" id="serveCheckNumber">
                    </div>
                    <div class="col-md-4 serve-check-field d-none">
                        <label class="form-label">Bank Name *</label>
                        <input type="text" class="form-control" id="serveCheckBankName">
                    </div>
                    <div class="col-md-4 serve-check-field d-none">
                        <label class="form-label">Bank Branch</label>
                        <input type="text" class="form-control" id="serveCheckBankBranch">
                    </div>

                    <div class="col-md-6 serve-online-field d-none">
                        <label class="form-label">Bank / Wallet *</label>
                        <input type="text" class="form-control" id="serveOnlineBankName">
                    </div>
                    <div class="col-md-6 serve-online-field d-none">
                        <label class="form-label">Reference Number *</label>
                        <input type="text" class="form-control" id="serveReferenceNumber">
                    </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-success" onclick="submitServeDeliverOrder()" id="serveDeliverSubmitBtn">
                    <i class="bi bi-check2-circle me-1"></i> Mark as Delivered & Save Collection
                </button>
=======
                <button type="button" class="btn btn-warning" onclick="editFromView()" id="editFromViewBtn">Edit Order</button>
                <button type="button" class="btn btn-danger" onclick="deleteFromView()" id="deleteFromViewBtn" style="display: none;">Delete Order</button>
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
            </div>
        </div>
    </div>
</div>

    <!-- EDIT ORDER MODAL -->
    <div class="modal fade no-print" id="editOrderModal" tabindex="-1" data-bs-backdrop="true" data-bs-keyboard="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>Edit Sales Order</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="editOrderForm">
                        <input type="hidden" id="editOrderId">
                        <?php if ($so_branch_column_exists && !$view_all_branches): ?>
                            <input type="hidden" name="branch_id" value="<?= $branch_id ?>">
                        <?php endif; ?>
                        
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="editOrderNumber" class="form-label">Order Number</label>
                                <input type="text" class="form-control" id="editOrderNumber" readonly>
                            </div>
                            <div class="col-md-6">
                                <label for="editOrderDate" class="form-label">Order Date *</label>
                                <input type="date" class="form-control" id="editOrderDate" required>
                            </div>
                            <div class="col-md-6">
                                <label for="editCustomerName" class="form-label">Customer</label>
                                <input type="text" class="form-control" id="editCustomerName" readonly>
                            </div>
                            <div class="col-md-6">
                                <label for="editOrderStatus" class="form-label">Order Status *</label>
                                <select class="form-select" id="editOrderStatus" onchange="onOrderStatusChange()" required>
                                    <option value="pending">Pending</option>
                                    <option value="confirmed">Confirm Order (Generate Documents & Deduct Stock)</option>
                                </select>
                            </div>
                            
                            <div class="col-md-6" id="driverSelectionContainer" style="display: none;">
                                <label for="editDriverSelect" class="form-label fw-bold">Select Driver *</label>
                                <select class="form-select select2-driver" id="editDriverSelect" style="width: 100%;">
                                    <option value="">-- Choose Driver --</option>

                                    <?php if (!empty($drivers_with_pending)): ?>
                                    <optgroup label="Drivers with existing deliveries (can be assigned)">
                                        <?php foreach ($drivers_with_pending as $driver): ?>
                                            <option value="<?= $driver['driver_id'] ?>" data-pending="<?= $driver['pending_deliveries'] ?>">
                                                <?= htmlspecialchars($driver['driver_name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                    <?php endif; ?>

                                    <?php if (!empty($available_drivers_without_pending)): ?>
                                    <optgroup label="Available Drivers">
                                        <?php foreach ($available_drivers_without_pending as $driver): ?>
                                            <option value="<?= $driver['driver_id'] ?>" data-pending="0">
                                                <?= htmlspecialchars($driver['driver_name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                    <?php endif; ?>
                                </select>
                                <div class="driver-info-tooltip">
                                    <i class="bi bi-info-circle"></i> 
                                    Drivers with existing deliveries can still be assigned. They will be delivered together in one trip.
                                </div>
                            </div>

                            <div class="col-md-6" id="vehicleSelectionContainer" style="display: none;">
                                <label for="editVehicleSelect" class="form-label fw-bold">Select Vehicle *</label>
                                <select class="form-select select2-vehicle" id="editVehicleSelect" style="width: 100%;">
                                    <option value="">-- Choose Vehicle --</option>
                                    <?php foreach ($available_vehicles as $vehicle): ?>
                                        <option value="<?= $vehicle['vehicle_id'] ?>"
                                                data-type="<?= htmlspecialchars($vehicle['vehicle_type']) ?>"
                                                data-plate="<?= htmlspecialchars($vehicle['plate_number']) ?>">
                                            <?= htmlspecialchars($vehicle['vehicle_type'] . ' - ' . $vehicle['plate_number']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-4">
                                <label for="editTotalItems" class="form-label">Items</label>
                                <input type="number" class="form-control" id="editTotalItems" readonly>
                            </div>
                            <div class="col-md-4">
                                <label for="editTotalQty" class="form-label">Total Quantity</label>
                                <input type="number" class="form-control" id="editTotalQty" readonly>
                            </div>
                            <div class="col-md-4">
                                <label for="editTotalAmount" class="form-label">Total Amount (₱) *</label>
                                <input type="number" class="form-control" id="editTotalAmount" step="0.01" min="0" required>
                            </div>
                        </div>
                        
                        <div class="alert alert-info mt-3" id="stockCheckMessage" style="display: none;">
                            <i class="bi bi-info-circle me-2"></i>
                            <span id="stockCheckText"></span>
                        </div>
                        
                        <div class="alert alert-warning mt-3" id="noDriversMessage" style="display: none;">
                            <i class="bi bi-exclamation-triangle me-2"></i>
                            <strong>No available drivers found for your branch.</strong> 
                            Please add drivers or mark existing drivers as active.
                        </div>
                        
                        <div class="alert alert-success mt-3" id="paymentNotice" style="display: none;">
                            <i class="bi bi-info-circle me-2"></i>
                            <span id="paymentNoticeText"></span>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="updateOrder()" id="updateOrderBtn">Update Order</button>
                </div>
            </div>
        </div>
    </div>

    <!-- DELETE CONFIRMATION MODAL -->
    <div class="modal fade no-print" id="deleteOrderModal" tabindex="-1" data-bs-backdrop="true" data-bs-keyboard="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title"><i class="bi bi-trash me-2"></i>Confirm Delete</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to delete this sales order?</p>
                    <p class="fw-bold" id="deleteOrderNumber"></p>
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        This action cannot be undone and will remove all associated order items.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-danger" onclick="confirmDelete()">Delete Order</button>
                </div>
            </div>
        </div>
    </div>

    <!-- STOCK WARNING MODAL -->
    <div class="modal fade no-print" id="stockWarningModal" tabindex="-1" data-bs-backdrop="true" data-bs-keyboard="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title"><i class="bi bi-exclamation-triangle me-2"></i>Insufficient Stock</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>The following items have insufficient stock:</p>
                    <div id="insufficientStockList"></div>
                    <p class="mt-3">Please update inventory or adjust quantities before confirming this order.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Print Frame (hidden) -->
    <iframe id="printFrame" name="printFrame"></iframe>

<<<<<<< HEAD
    <!-- PRINT PREVIEW MODAL -->
    <div class="modal fade no-print print-preview-modal" id="printPreviewModal" tabindex="-1" data-bs-backdrop="true" data-bs-keyboard="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-printer me-2"></i>Print Preview</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="print-preview-frame-wrap">
                        <iframe id="printPreviewFrame" name="printPreviewFrame"></iframe>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="printCurrentReceipt()">
                        <i class="bi bi-printer me-1"></i> Print
                    </button>
                </div>
            </div>
        </div>
    </div>

=======
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    
<script>
    // ========== GLOBAL VARIABLES ==========
    let currentOrderId = null;
    let currentBranchId = <?php echo $branch_id; ?>;
    const viewAllBranches = <?php echo $view_all_branches ? 'true' : 'false'; ?>;
    const soBranchColumnExists = <?php echo $so_branch_column_exists ? 'true' : 'false'; ?>;
    const invoiceSoColumnExists = <?php echo $invoice_so_column_exists ? 'true' : 'false'; ?>;
    const driversBranchColumnExists = <?php echo $drivers_branch_column_exists ? 'true' : 'false'; ?>;
    const logoBase64 = '<?php echo $logo_base64; ?>';
    let availableDrivers = <?= json_encode($available_drivers) ?>;
    let activeDateRange = { start: '', end: '' };
    let globalScrollTimeout;
    let isRendering = false;

    // ========== NUMBER FORMATTING FUNCTIONS ==========
    function formatNumberWithCommas(number) {
        if (number === null || number === undefined || number === '') return '0';
        let num = typeof number === 'string' ? parseFloat(number) : number;
        if (isNaN(num)) return '0';
        return num.toLocaleString('en-US');
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

    function formatAmount(number) {
        if (number === null || number === undefined || number === '') return '0.00';
        let num = typeof number === 'string' ? parseFloat(number) : number;
        if (isNaN(num)) return '0.00';
        return num.toLocaleString('en-US', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }

    function formatWholeNumber(number) {
        if (number === null || number === undefined || number === '') return '0';
        let num = typeof number === 'string' ? parseInt(number) : number;
        if (isNaN(num)) return '0';
        return num.toLocaleString('en-US');
    }

    // ========== MASTER DATA ==========
    const MASTER_ORDER_DATA = <?php 
        $orders_data = [];
        foreach ($sales_orders as $order) {
            $orders_data[] = [
                'id' => $order['so_id'],
                'orderNumber' => strtolower($order['so_number']),
                'orderNumberDisplay' => $order['so_number'],
                'customer' => strtolower($order['customer_name']),
                'customerDisplay' => $order['customer_name'],
                'status' => $order['order_status'],
<<<<<<< HEAD
                'invoiceStatus' => strtolower((string)($order['invoice_status'] ?? '')),
                'collectionStatus' => strtolower((string)($order['collection_status'] ?? '')),
                // Completed Today uses the sales order date and delivered/completed status only.
                                'completedDate' => !empty($order['collected_at']) ? $order['collected_at'] : $order['created_at'],
                'collectedAt' => $order['collected_at'] ?? '',
=======
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
                'date' => $order['created_at'],
                'dateDisplay' => formatDate($order['created_at']),
                'amount' => floatval($order['total_amount']),
                'items' => $order['total_items'] ?? 0,
                'qty' => $order['total_quantity'] ?? 0,
                'branchDisplay' => ($so_branch_column_exists && $view_all_branches) ? '<span class="badge bg-info">' . htmlspecialchars($order['branch_name'] ?? 'Branch ' . $order['branch_id']) . '</span>' : ''
            ];
        }
        echo json_encode($orders_data);
    ?>;
    
    let currentDisplayData = [];
    
    if (MASTER_ORDER_DATA && MASTER_ORDER_DATA.length > 0) {
        currentDisplayData = [...MASTER_ORDER_DATA];
        console.log('MASTER ORDER DATA loaded:', MASTER_ORDER_DATA.length, 'orders');
    }

<<<<<<< HEAD
    // ========== SERVE / DELIVER WITH COLLECTION ==========
    let currentServeOrder = null;
    let selectedServePaymentMethod = 'cash';

    function selectServePaymentMethod(method) {
        selectedServePaymentMethod = method || 'cash';
        const hidden = document.getElementById('serveSelectedPaymentMethod');
        if (hidden) hidden.value = selectedServePaymentMethod;

        const select = document.getElementById('servePaymentMethodSelect');
        if (select && select.value !== selectedServePaymentMethod) {
            select.value = selectedServePaymentMethod;
        }

        document.querySelectorAll('.serve-cash-field').forEach(el => el.classList.toggle('d-none', selectedServePaymentMethod !== 'cash'));
        document.querySelectorAll('.serve-check-field').forEach(el => el.classList.toggle('d-none', selectedServePaymentMethod !== 'check'));
        document.querySelectorAll('.serve-online-field').forEach(el => el.classList.toggle('d-none', selectedServePaymentMethod !== 'online_transfer'));
        recalcServeCashChange();
    }

    function recalcServeCashChange() {
        const amount = parseFloat(document.getElementById('serveCollectionAmount')?.value || '0') || 0;
        const tendered = parseFloat(document.getElementById('serveCashTendered')?.value || '0') || 0;
        const change = selectedServePaymentMethod === 'cash' && tendered > amount ? (tendered - amount) : 0;
        const hidden = document.getElementById('serveCashChange');
        const display = document.getElementById('serveCashChangeDisplay');
        if (hidden) hidden.value = change.toFixed(2);
        if (display) display.value = formatCurrency(change);
    }

    function openServeDeliverModal(orderId) {
        const order = MASTER_ORDER_DATA.find(o => parseInt(o.id) === parseInt(orderId));
        if (!order) {
            Swal.fire('Error', 'Order data not found.', 'error');
            return;
        }

        currentServeOrder = order;
        document.getElementById('serveOrderId').value = order.id;
        document.getElementById('serveOrderNo').value = order.orderNumberDisplay || order.orderNumber || '';
        document.getElementById('serveCustomerName').value = order.customerDisplay || '';
        document.getElementById('serveOrderTotalDisplay').value = formatCurrency(order.amount || 0);
        document.getElementById('serveCollectionAmount').value = parseFloat(order.amount || 0).toFixed(2);
        document.getElementById('serveCashTendered').value = parseFloat(order.amount || 0).toFixed(2);
        document.getElementById('serveCashChangeDisplay').value = formatCurrency(0);
        document.getElementById('serveCashChange').value = '0';
        ['serveReferenceNumber','serveCheckDate','serveCheckNumber','serveCheckBankName','serveCheckBankBranch','serveOnlineBankName'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.value = '';
        });

        selectServePaymentMethod('cash');
        const modal = new bootstrap.Modal(document.getElementById('serveDeliverModal'));
        modal.show();
    }

    function submitServeDeliverOrder() {
        if (!currentServeOrder) {
            Swal.fire('Error', 'No order selected.', 'error');
            return;
        }

        const amount = parseFloat(document.getElementById('serveCollectionAmount')?.value || '0') || 0;
        const orderTotal = parseFloat(currentServeOrder.amount || '0') || 0;
        if (amount <= 0) {
            Swal.fire('Error', 'Please enter collected amount.', 'error');
            return;
        }
        if (amount > orderTotal + 0.01) {
            Swal.fire('Error', 'Collected amount cannot be greater than the order total.', 'error');
            return;
        }
        if (selectedServePaymentMethod === 'check') {
            if (!document.getElementById('serveCheckDate')?.value || !document.getElementById('serveCheckNumber')?.value.trim() || !document.getElementById('serveCheckBankName')?.value.trim()) {
                Swal.fire('Error', 'Check date, check number, and bank name are required.', 'error');
                return;
            }
        }
        if (selectedServePaymentMethod === 'online_transfer') {
            if (!document.getElementById('serveOnlineBankName')?.value.trim() || !document.getElementById('serveReferenceNumber')?.value.trim()) {
                Swal.fire('Error', 'Bank/Wallet and reference number are required.', 'error');
                return;
            }
        }

        const fd = new FormData();
        fd.append('action', 'serve_deliver_order');
        fd.append('so_id', document.getElementById('serveOrderId').value);
        fd.append('payment_method', selectedServePaymentMethod);
        fd.append('collection_amount', amount.toFixed(2));
        fd.append('cash_tendered', document.getElementById('serveCashTendered')?.value || '');
        fd.append('cash_change', document.getElementById('serveCashChange')?.value || '0');

        if (selectedServePaymentMethod === 'check') {
            fd.append('check_date', document.getElementById('serveCheckDate')?.value || '');
            fd.append('check_number', document.getElementById('serveCheckNumber')?.value || '');
            fd.append('bank_name', document.getElementById('serveCheckBankName')?.value || '');
            fd.append('bank_branch', document.getElementById('serveCheckBankBranch')?.value || '');
            fd.append('reference_number', document.getElementById('serveCheckNumber')?.value || '');
        } else if (selectedServePaymentMethod === 'online_transfer') {
            fd.append('bank_name', document.getElementById('serveOnlineBankName')?.value || '');
            fd.append('reference_number', document.getElementById('serveReferenceNumber')?.value || '');
        }

        Swal.fire({
            title: 'Serve and Deliver Order?',
            html: 'This will mark the order as <strong>Delivered</strong> and save the collected amount for remittance. It will not be marked as Paid until Branch Admin approves the remittance.',
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#047857',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Yes, Serve / Deliver'
        }).then(result => {
            if (!result.isConfirmed) return;

            const btn = document.getElementById('serveDeliverSubmitBtn');
            if (btn) btn.disabled = true;
            Swal.fire({ title: 'Saving...', text: 'Please wait', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

            fetch('sales_order.php', { method: 'POST', body: fd })
                .then(response => response.json())
                .then(data => {
                    Swal.close();
                    if (btn) btn.disabled = false;
                    if (data.success) {
                        const modalEl = document.getElementById('serveDeliverModal');
                        const modal = bootstrap.Modal.getInstance(modalEl);
                        if (modal) modal.hide();
                        Swal.fire('Saved', data.message || 'Order served successfully.', 'success').then(() => location.reload());
                    } else {
                        Swal.fire('Error', data.message || 'Failed to serve order.', 'error');
                    }
                })
                .catch(error => {
                    Swal.close();
                    if (btn) btn.disabled = false;
                    Swal.fire('Error', 'Server returned invalid response.', 'error');
                });
        });
    }

=======
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
    // ========== RENDER TABLE ==========
    function renderTable(dataToRender) {
        if (isRendering) return;
        isRendering = true;
        
        const tbody = document.querySelector('#salesOrdersTable tbody');
        if (!tbody) {
            isRendering = false;
            return;
        }
        
        const hasBranchColumn = document.querySelector('#salesOrdersTable thead th:nth-child(4)')?.innerText === 'Branch';
        
        tbody.innerHTML = '';
        
        if (!dataToRender || dataToRender.length === 0) {
            const colspan = hasBranchColumn ? 9 : 8;
            tbody.innerHTML = '<tr><td colspan="' + colspan + '" class="text-center py-4">' +
                '<i class="bi bi-inbox fs-1 d-block text-muted mb-2"></i>' +
                '<p class="text-muted mb-0">No orders found matching your filters</p>' +
                '<\/td><\/tr>';
            isRendering = false;
            return;
        }
        
        dataToRender.forEach(order => {
            const row = document.createElement('tr');
            row.className = 'sales-order-row';
            
            row.setAttribute('data-id', order.id);
            row.setAttribute('data-order-number', order.orderNumber);
            row.setAttribute('data-customer', order.customer);
            row.setAttribute('data-status', order.status);
            row.setAttribute('data-date', order.date);
            row.setAttribute('data-amount', order.amount);
            row.setAttribute('data-items', order.items);
            row.setAttribute('data-qty', order.qty);
            
            const displayDate = order.dateDisplay || (order.date ? new Date(order.date).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' }) : '');
            
            let statusBadgeClass = 'badge bg-secondary text-white';
            let statusText = order.status || 'pending';
            switch(order.status) {
                case 'pending': statusBadgeClass = 'badge bg-warning text-dark'; statusText = 'Pending'; break;
                case 'confirmed': statusBadgeClass = 'badge bg-info text-white'; statusText = 'Confirmed'; break;
                case 'processing': statusBadgeClass = 'badge bg-primary text-white'; statusText = 'Processing'; break;
                case 'ready': statusBadgeClass = 'badge bg-info text-white'; statusText = 'For Delivery'; break;
                case 'in_transit': statusBadgeClass = 'badge bg-primary text-white'; statusText = 'In Transit'; break;
                case 'delivered': statusBadgeClass = 'badge bg-success text-white'; statusText = 'Delivered'; break;
                case 'cancelled': statusBadgeClass = 'badge bg-danger text-white'; statusText = 'Cancelled'; break;
            }
            
            let rowHtml = `
                <td><strong>${escapeHtml(order.orderNumberDisplay || order.orderNumber.toUpperCase())}</strong><\/td>
                <td>${displayDate}<\/td>
                <td>${escapeHtml(order.customerDisplay || order.customer)}<\/td>
            `;
            
            if (hasBranchColumn) {
                rowHtml += `<td>${order.branchDisplay || '<span class="badge bg-info">Branch</span>'}<\/td>`;
            }
            
            rowHtml += `
                <td class="text-center">${order.items || '-'}<\/td>
                <td class="text-center">${order.qty || '-'}<\/td>
                <td class="text-end">${formatCurrency(order.amount)}<\/td>
                <td><span class="${statusBadgeClass}">${statusText}</span><\/td>
                <td class="no-print">
                    <div class="action-buttons">
<<<<<<< HEAD
                        ${activeSalesOrderTab === 'pending_today' ? `
                            <button class="btn-action btn-serve-deliver" onclick="event.stopPropagation(); openServeDeliverModal(${order.id})" title="Serve / Deliver">
                                <i class="bi bi-truck"></i>
                            </button>
                        ` : `
                            <button class="btn-action btn-view" onclick="event.stopPropagation(); viewOrder(${order.id})" title="View">
                                <i class="bi bi-eye"></i>
                            </button>
                        `}
                        <button class="btn-action btn-print" onclick="event.stopPropagation(); printSingleOrder(${order.id})" title="Print Order">
                            <i class="bi bi-printer"></i>
                        </button>
=======
                        <button class="btn-action btn-print" onclick="printSingleOrder(${order.id})" title="Print Order">
                            <i class="bi bi-printer"></i>
                        </button>
                        ${order.status === 'pending' ? `
                            <button class="btn-action btn-edit" onclick="editOrder(${order.id})" title="Edit">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <button class="btn-action btn-delete" onclick="deleteOrder(${order.id})" title="Delete">
                                <i class="bi bi-trash"></i>
                            </button>
                        ` : ''}
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
                    </div>
                <\/td>
            `;
            
            row.innerHTML = rowHtml;
            tbody.appendChild(row);
        });
        
        attachRowClickEvents();
        handleSalesOrderTap();
        isRendering = false;
    }
    
<<<<<<< HEAD
    // ========== SALES ORDER TAB FILTERS ==========
    let activeSalesOrderTab = 'historical';

    function isSameLocalDate(dateValue, targetDate = new Date()) {
        if (!dateValue) return false;
        const d = new Date(dateValue);
        if (isNaN(d.getTime())) return false;
        return d.getFullYear() === targetDate.getFullYear()
            && d.getMonth() === targetDate.getMonth()
            && d.getDate() === targetDate.getDate();
    }

    function isCompletedTodayOrder(order) {
        const status = (order.status || '').toLowerCase().trim();
        return status === 'delivered' || status === 'completed';
    }

    function getCompletedDate(order) {
        // Requirement: Completed Today should show only records dated today.
        // Use the sales order date shown in the table, not invoice/payment date.
        return order.date;
    }

    function isPendingUndeliveredOrder(order) {
        const status = (order.status || '').toLowerCase();
        return status !== 'delivered' && status !== 'completed' && status !== 'cancelled';
    }

    function getActiveTabBaseData() {
        const today = new Date();
        if (activeSalesOrderTab === 'completed_today') {
            // Completed Today = delivered/completed status AND sales order date is today only.
            return MASTER_ORDER_DATA.filter(order => isCompletedTodayOrder(order) && isSameLocalDate(getCompletedDate(order), today));
        }
        if (activeSalesOrderTab === 'pending_today') {
            return MASTER_ORDER_DATA.filter(order => isPendingUndeliveredOrder(order) && isSameLocalDate(order.date, today));
        }
        return [...MASTER_ORDER_DATA];
    }

    function updateSalesOrderTabCounts() {
        const today = new Date();
        const historicalCount = MASTER_ORDER_DATA.length;
        const completedTodayCount = MASTER_ORDER_DATA.filter(order => isCompletedTodayOrder(order) && isSameLocalDate(getCompletedDate(order), today)).length;
        const pendingTodayCount = MASTER_ORDER_DATA.filter(order => isPendingUndeliveredOrder(order) && isSameLocalDate(order.date, today)).length;

        const historicalBadge = document.getElementById('tabHistoricalCount');
        const completedBadge = document.getElementById('tabCompletedTodayCount');
        const pendingBadge = document.getElementById('tabPendingTodayCount');
        if (historicalBadge) historicalBadge.textContent = historicalCount;
        if (completedBadge) completedBadge.textContent = completedTodayCount;
        if (pendingBadge) pendingBadge.textContent = pendingTodayCount;
    }

    function setSalesOrderTab(tabName) {
        activeSalesOrderTab = tabName || 'historical';

        document.querySelectorAll('#salesOrderTabs .nav-link').forEach(btn => btn.classList.remove('active'));
        const activeButtonMap = {
            historical: 'tabHistoricalBtn',
            completed_today: 'tabCompletedTodayBtn',
            pending_today: 'tabPendingTodayBtn'
        };
        const activeButton = document.getElementById(activeButtonMap[activeSalesOrderTab] || 'tabHistoricalBtn');
        if (activeButton) activeButton.classList.add('active');

        const description = document.getElementById('activeTabDescription');
        if (description) {
            if (activeSalesOrderTab === 'completed_today') {
                description.textContent = 'Showing today’s delivered/completed sales orders only.';
            } else if (activeSalesOrderTab === 'pending_today') {
                description.textContent = 'Showing today\'s sales orders that are not yet delivered.';
            } else {
                description.textContent = 'Showing all historical sales orders.';
            }
        }

        applyManualFilters();
        updateSalesOrderTabCounts();
    }

=======
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
    // ========== APPLY FILTERS ==========
    function applyManualFilters() {
        const searchTerm = document.getElementById('searchInput')?.value.toLowerCase().trim() || '';
        const statusFilter = document.getElementById('statusFilter')?.value || '';
        const customerFilter = document.getElementById('customerFilter')?.value.toLowerCase().trim() || '';
        
        const startDateInput = document.getElementById('startDate')?.value || '';
        const endDateInput = document.getElementById('endDate')?.value || '';
        
        let startDateTime = null;
        let endDateTime = null;
        
        if (startDateInput && endDateInput && startDateInput !== '' && endDateInput !== '') {
            startDateTime = new Date(startDateInput);
            endDateTime = new Date(endDateInput);
            endDateTime.setHours(23, 59, 59, 999);
            activeDateRange = { start: startDateInput, end: endDateInput };
        } else {
            startDateTime = null;
            endDateTime = null;
            activeDateRange = { start: '', end: '' };
        }
        
        const hasDateFilter = startDateTime !== null && endDateTime !== null;
<<<<<<< HEAD
        const tabBaseData = getActiveTabBaseData();
        const noFilters = searchTerm === '' && statusFilter === '' && customerFilter === '' && !hasDateFilter;
        
        if (noFilters) {
            currentDisplayData = [...tabBaseData];
=======
        const noFilters = searchTerm === '' && statusFilter === '' && customerFilter === '' && !hasDateFilter;
        
        if (noFilters) {
            currentDisplayData = [...MASTER_ORDER_DATA];
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
            renderTable(currentDisplayData);
            const totalAmount = currentDisplayData.reduce((sum, order) => sum + order.amount, 0);
            updateTotalAmountDisplay(totalAmount);
            updateDateRangeSummary('', '', currentDisplayData.length, totalAmount);
            updatePeriodSalesStat('', '', 0, true);
            updateActiveFiltersBadge();
            console.log('No filters - showing all orders from MASTER');
            return;
        }
        
<<<<<<< HEAD
        const filteredOrders = tabBaseData.filter(order => {
=======
        const filteredOrders = MASTER_ORDER_DATA.filter(order => {
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
            let isInDateRange = true;
            
            if (order.date && startDateTime && endDateTime) {
                const orderDate = new Date(order.date);
                isInDateRange = orderDate >= startDateTime && orderDate <= endDateTime;
            }
            
            const matchesSearch = searchTerm === '' || 
                                  order.orderNumber.includes(searchTerm) || 
                                  order.customer.includes(searchTerm);
            
            const matchesStatus = statusFilter === '' || order.status === statusFilter;
            const matchesCustomer = customerFilter === '' || order.customer === customerFilter;
            
            return matchesSearch && matchesStatus && matchesCustomer && isInDateRange;
        });
        
        currentDisplayData = [...filteredOrders];
        
        const visibleCount = filteredOrders.length;
        const totalAmount = filteredOrders.reduce((sum, order) => sum + order.amount, 0);
        const dateRangeTotal = filteredOrders.reduce((sum, order) => {
            let isInRange = true;
            if (startDateTime && endDateTime && order.date) {
                const orderDate = new Date(order.date);
                isInRange = orderDate >= startDateTime && orderDate <= endDateTime;
            }
            return sum + (isInRange ? order.amount : 0);
        }, 0);
        
        renderTable(filteredOrders);
        updateDateRangeSummary(startDateInput, endDateInput, visibleCount, totalAmount);
        updatePeriodSalesStat(startDateInput, endDateInput, dateRangeTotal);
        updateTotalAmountDisplay(totalAmount);
        updateActiveFiltersBadge();
        
        console.log(`Filter applied: ${visibleCount} orders shown, Total: ${formatCurrency(totalAmount)}`);
    }
    
    function clearAllFilters() {
        window.location.reload();
    }
    
    function updateTotalAmountDisplay(totalAmount) {
        const totalSpan = document.querySelector('.table-header .text-muted');
        if (totalSpan) {
            totalSpan.innerHTML = `Total: ${formatCurrency(totalAmount)}`;
        }
    }
    
    function applyManualDateRangeFilter() {
        const startDate = document.getElementById('startDate')?.value;
        const endDate = document.getElementById('endDate')?.value;
        
        if (!startDate || !endDate) {
            Swal.fire('Warning', 'Please select both start and end dates', 'warning');
            return;
        }
        
        if (new Date(startDate) > new Date(endDate)) {
            Swal.fire('Warning', 'Start date cannot be after end date', 'warning');
            return;
        }
        
        activeDateRange = { start: startDate, end: endDate };
        applyManualFilters();
    }
    
    function resetToCurrentWeek() {
        const today = new Date();
        const day = today.getDay();
        
        const monday = new Date(today);
        const diffToMonday = (day === 0 ? -6 : 1) - day;
        monday.setDate(today.getDate() + diffToMonday);
        monday.setHours(0, 0, 0, 0);
        
        const saturday = new Date(monday);
        saturday.setDate(monday.getDate() + 5);
        
        let endDate = today < saturday ? today : saturday;
        
        const formatDateFn = (date) => {
            return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
        };
        
        const startDateInput = document.getElementById('startDate');
        const endDateInput = document.getElementById('endDate');
        
        if (startDateInput) startDateInput.value = formatDateFn(monday);
        if (endDateInput) endDateInput.value = formatDateFn(endDate);
        
        activeDateRange = { start: formatDateFn(monday), end: formatDateFn(endDate) };
        applyManualFilters();
    }
    
    function updatePeriodSalesStat(startDate, endDate, totalAmount, isReset = false) {
        const salesValue = document.getElementById('dateRangeSalesValue');
        const salesLabel = document.getElementById('dateRangeSalesLabel');
        const dateRangeSubtitle = document.getElementById('dateRangeSalesSubtitle');
        
        if (!salesValue) return;
        
        if (isReset || !startDate || !endDate) {
            salesValue.textContent = <?= json_encode($statCurrentWeekSales) ?>;
            if (salesLabel) salesLabel.textContent = 'Period Sales';
            if (dateRangeSubtitle) dateRangeSubtitle.innerHTML = '<span id="dateRangeText">Current week (Mon-Sat)</span>';
            return;
        }
        salesValue.textContent = formatCurrency(totalAmount);
        
        const start = new Date(startDate);
        const end = new Date(endDate);
        const options = { month: 'short', day: 'numeric' };
        const startStr = start.toLocaleDateString('en-US', options);
        const endStr = end.toLocaleDateString('en-US', options);
        
        if (salesLabel) salesLabel.textContent = startDate === endDate ? 'Daily Sales' : 'Period Sales';
        if (dateRangeSubtitle) dateRangeSubtitle.innerHTML = startDate === endDate ? startStr : `${startStr} - ${endStr}`;
    }
    
    function updateDateRangeSummary(startDate, endDate, visibleCount, totalAmount) {
        const summaryDiv = document.getElementById('dateRangeSummary');
        const summaryText = document.getElementById('dateRangeSummaryText');
        
        if (!summaryDiv || !summaryText) return;
        
        if (!startDate || !endDate || startDate === '' || endDate === '') {
            summaryDiv.style.display = 'none';
            return;
        }
        
        try {
            const start = new Date(startDate);
            const end = new Date(endDate);
            
            if (isNaN(start.getTime()) || isNaN(end.getTime())) {
                summaryDiv.style.display = 'none';
                return;
            }
            
            const options = { year: 'numeric', month: 'long', day: 'numeric' };
            summaryText.innerHTML = `<strong>${start.toLocaleDateString('en-US', options)} - ${end.toLocaleDateString('en-US', options)}:</strong> ${visibleCount} orders, Total: ${formatCurrency(totalAmount)}`;
            summaryDiv.style.display = 'block';
        } catch(e) {
            summaryDiv.style.display = 'none';
        }
    }
    
    function updateActiveFiltersBadge() {
        let activeCount = 0;
        
        const startDate = document.getElementById('startDate')?.value;
        const endDate = document.getElementById('endDate')?.value;
        if (startDate && endDate && startDate !== '' && endDate !== '') activeCount++;
        
        const searchInput = document.getElementById('searchInput')?.value;
        if (searchInput && searchInput.trim() !== '') activeCount++;
        
        const statusFilter = document.getElementById('statusFilter')?.value;
        if (statusFilter && statusFilter !== '') activeCount++;
        
        const customerFilter = document.getElementById('customerFilter')?.value;
        if (customerFilter && customerFilter !== '') activeCount++;
        
        const badge = document.getElementById('filterActiveBadge');
        const countSpan = document.getElementById('activeFilterCount');
        
        if (badge && countSpan) {
            if (activeCount > 0) {
                countSpan.textContent = activeCount;
                badge.style.display = 'inline-flex';
            } else {
                badge.style.display = 'none';
            }
        }
        
        return activeCount;
    }
    
    // ========== DOM READY ==========
    document.addEventListener('DOMContentLoaded', function() {
        const startDateInput = document.getElementById('startDate');
        const endDateInput = document.getElementById('endDate');
        
        if (startDateInput) startDateInput.value = '';
        if (endDateInput) endDateInput.value = '';
        
        activeDateRange = { start: '', end: '' };
        initializeSidebar();
        
        if (typeof $ !== 'undefined' && $.fn.select2) {
            $('.select2-driver').select2({
                placeholder: 'Select a driver',
                allowClear: true,
                dropdownParent: $('#editOrderModal'),
                width: '100%',
                templateResult: formatDriverOption,
                templateSelection: formatDriverSelection,
                escapeMarkup: function(m) { return m; }
            });

            $('.select2-vehicle').select2({
                placeholder: 'Select a vehicle',
                allowClear: true,
                dropdownParent: $('#editOrderModal'),
                width: '100%'
            });
        }
        
        const searchInput = document.getElementById('searchInput');
        if (searchInput) searchInput.addEventListener('keyup', applyManualFilters);
        
        const statusFilter = document.getElementById('statusFilter');
        if (statusFilter) statusFilter.addEventListener('change', applyManualFilters);
        
        const customerFilter = document.getElementById('customerFilter');
        if (customerFilter) customerFilter.addEventListener('change', applyManualFilters);
        
        const applyDateBtn = document.querySelector('.date-filter-btn');
        if (applyDateBtn) applyDateBtn.onclick = function(e) { e.preventDefault(); applyManualDateRangeFilter(); };
        
        const resetWeekBtn = document.querySelector('.date-reset-week-btn');
        if (resetWeekBtn) resetWeekBtn.onclick = function(e) { e.preventDefault(); resetToCurrentWeek(); };
        
        const clearBtn = document.querySelector('.date-clear-btn');
        if (clearBtn) clearBtn.onclick = function(e) { e.preventDefault(); clearAllFilters(); };
        
        const mobileMenuBtn = document.getElementById('mobileMenuBtn');
        if (mobileMenuBtn) {
            mobileMenuBtn.addEventListener('click', function() {
                const sidebar = document.getElementById('sidebar');
                if (window.innerWidth <= 992) {
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
                if (window.innerWidth <= 992) closeMobileSidebar();
            });
        });
        
        document.addEventListener('click', function(event) {
            const sidebar = document.getElementById('sidebar');
            const mobileBtn = document.getElementById('mobileMenuBtn');
            const overlay = document.querySelector('.sidebar-overlay');
            if (window.innerWidth <= 992 && sidebar && sidebar.classList.contains('active') && 
                !sidebar.contains(event.target) && (!mobileBtn || !mobileBtn.contains(event.target)) &&
                (!overlay || !overlay.contains(event.target))) {
                closeMobileSidebar();
            }
        });
        
        window.addEventListener('resize', handleSidebarResize);
        setActiveMobileNav();
        
        const filterToggleBtn = document.getElementById('filterToggleBtn');
        const filterContent = document.getElementById('filterContent');
        
        if (filterToggleBtn && filterContent) {
            const savedState = localStorage.getItem('filterSectionExpanded');
            if (savedState === 'true') {
                filterContent.classList.remove('collapsed');
                filterToggleBtn.setAttribute('aria-expanded', 'true');
            } else {
                filterContent.classList.add('collapsed');
                filterToggleBtn.setAttribute('aria-expanded', 'false');
            }
            
            filterToggleBtn.addEventListener('click', function() {
                const isExpanded = filterToggleBtn.getAttribute('aria-expanded') === 'true';
                if (isExpanded) {
                    filterContent.classList.add('collapsed');
                    filterToggleBtn.setAttribute('aria-expanded', 'false');
                } else {
                    filterContent.classList.remove('collapsed');
                    filterToggleBtn.setAttribute('aria-expanded', 'true');
                }
                localStorage.setItem('filterSectionExpanded', !isExpanded);
            });
        }
        
        updateActiveFiltersBadge();
        handleSalesOrderTap();
        attachRowClickEvents();
        
<<<<<<< HEAD
        updateSalesOrderTabCounts();
        if (MASTER_ORDER_DATA && MASTER_ORDER_DATA.length > 0) {
            currentDisplayData = getActiveTabBaseData();
=======
        if (MASTER_ORDER_DATA && MASTER_ORDER_DATA.length > 0) {
            currentDisplayData = [...MASTER_ORDER_DATA];
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
            renderTable(currentDisplayData);
            updateTotalAmountDisplay(currentDisplayData.reduce((sum, o) => sum + o.amount, 0));
            console.log('Initial render from MASTER_ORDER_DATA:', MASTER_ORDER_DATA.length, 'orders');
        } else {
            renderTable([]);
            console.log('No orders found in MASTER_ORDER_DATA');
        }
        
        // Format all existing currencies on page
        setTimeout(function() {
            document.querySelectorAll('.stat-value').forEach(el => {
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
        }, 500);
    });
    
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
    
    function handleSidebarResize() {
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
    }
    
    function showLoading() {
        Swal.fire({
            title: 'Processing...',
            text: 'Please wait',
            allowOutsideClick: false,
            didOpen: () => Swal.showLoading()
        });
    }
    
    function escapeHtml(str) {
        if (!str) return '';
        return String(str).replace(/[&<>]/g, function(m) {
            if (m === '&') return '&amp;';
            if (m === '<') return '&lt;';
            if (m === '>') return '&gt;';
            return m;
        });
    }
    
    function removeAllBackdrops() {
        document.querySelectorAll('.modal-backdrop').forEach(backdrop => backdrop.remove());
        document.body.classList.remove('modal-open');
        document.body.style.overflow = '';
        document.body.style.paddingRight = '';
        document.body.style.position = '';
    }
    
    function getStatusBadge(status) { 
        const classes = { 
            'pending': 'badge bg-warning text-dark', 
            'confirmed': 'badge bg-info text-white', 
            'processing': 'badge bg-primary text-white', 
            'ready': 'badge bg-info text-white', 
            'in_transit': 'badge bg-primary text-white',
            'delivered': 'badge bg-success text-white', 
            'cancelled': 'badge bg-danger text-white' 
        }; 
        return classes[status] || 'badge bg-secondary text-white'; 
    }
    
    function getStatusText(status) { 
        const texts = { 
            'pending': 'Pending', 
            'confirmed': 'Confirmed', 
            'processing': 'Processing', 
            'ready': 'For Delivery', 
            'in_transit': 'In Transit',
            'delivered': 'Delivered', 
            'cancelled': 'Cancelled' 
        }; 
        return texts[status] || status; 
    }
    
    function formatDriverOption(driver) {
        if (!driver.id) return driver.text;
        const element = $(driver.element);
        const driverName = driver.text || '';
        const pending = element.data('pending') || 0;
        let badge = pending > 0 ? `<span class="pending-count ms-2">${pending} pending</span>` : `<span class="available-badge ms-2">Available</span>`;
        return $(`<div class="driver-option"><span class="driver-name">${driverName}</span>${badge}</div>`);
    }

    function formatDriverSelection(driver) {
        if (!driver.id) return driver.text;
        return driver.text || '';
    }

    function onOrderStatusChange() {
        const status = document.getElementById('editOrderStatus')?.value;
        const driverContainer = document.getElementById('driverSelectionContainer');
        const noDriversMsg = document.getElementById('noDriversMessage');
        const vehicleContainer = document.getElementById('vehicleSelectionContainer');
        const paymentNotice = document.getElementById('paymentNotice');
        const paymentNoticeText = document.getElementById('paymentNoticeText');
        
        if (status === 'confirmed') {
            if (availableDrivers && availableDrivers.length > 0) {
                if (driverContainer) driverContainer.style.display = 'block';
                if (vehicleContainer) vehicleContainer.style.display = 'block';
                if (noDriversMsg) noDriversMsg.style.display = 'none';
                if (paymentNotice) paymentNotice.style.display = 'none';
                if (typeof $ !== 'undefined') {
                    $('#editDriverSelect').trigger('change');
                    $('#editVehicleSelect').trigger('change');
                }
            } else {
                if (driverContainer) driverContainer.style.display = 'none';
                if (vehicleContainer) vehicleContainer.style.display = 'none';
                if (noDriversMsg) noDriversMsg.style.display = 'block';
                if (paymentNotice) paymentNotice.style.display = 'none';
            }
        } else if (status === 'delivered') {
            if (driverContainer) driverContainer.style.display = 'none';
            if (vehicleContainer) vehicleContainer.style.display = 'none';
            if (noDriversMsg) noDriversMsg.style.display = 'none';
            if (paymentNotice) paymentNotice.style.display = 'block';
            if (paymentNoticeText) paymentNoticeText.innerHTML = 'Marking this order as delivered will keep the payment status as <strong>Pending</strong> until the collection is recorded.';
        } else if (status === 'cancelled') {
            if (driverContainer) driverContainer.style.display = 'none';
            if (vehicleContainer) vehicleContainer.style.display = 'none';
            if (noDriversMsg) noDriversMsg.style.display = 'none';
            if (paymentNotice) paymentNotice.style.display = 'block';
            if (paymentNoticeText) paymentNoticeText.innerHTML = 'Cancelling this order will update payment status to <strong>Cancelled</strong>.';
        } else {
            if (driverContainer) driverContainer.style.display = 'none';
            if (vehicleContainer) vehicleContainer.style.display = 'none';
            if (noDriversMsg) noDriversMsg.style.display = 'none';
            if (paymentNotice) paymentNotice.style.display = 'none';
        }
    }
    
    function refreshAvailableDrivers() {
        showLoading();
        const formData = new FormData();
        formData.append('action', 'get_available_drivers');
        formData.append('branch_id', currentBranchId);
        
        fetch('sales_order.php', { method: 'POST', body: formData })
            .then(response => response.json())
            .then(data => {
                Swal.close();
                if (data.success) {
                    availableDrivers = data.drivers;
                    const select = $('#editDriverSelect');
                    select.empty().append('<option value="">-- Choose Driver --</option>');
                    const withPending = data.drivers.filter(d => d.pending_deliveries > 0);
                    const withoutPending = data.drivers.filter(d => d.pending_deliveries == 0);
                    
                    if (withPending.length > 0) {
                        const group = $('<optgroup label="Drivers with existing deliveries (can be assigned)">');
                        withPending.forEach(driver => {
                            const option = new Option(driver.driver_name, driver.driver_id);
                            $(option).data('pending', driver.pending_deliveries);
                            group.append(option);
                        });
                        select.append(group);
                    }
                    if (withoutPending.length > 0) {
                        const group = $('<optgroup label="Available Drivers">');
                        withoutPending.forEach(driver => {
                            const option = new Option(driver.driver_name, driver.driver_id);
                            $(option).data('pending', 0);
                            group.append(option);
                        });
                        select.append(group);
                    }
                    select.trigger('change');
                    onOrderStatusChange();
                }
            })
            .catch(error => { Swal.close(); console.error('Error refreshing drivers:', error); });
    }

    function refreshAvailableVehicles() {
        const formData = new FormData();
        formData.append('action', 'get_available_vehicles');
        formData.append('branch_id', currentBranchId);

        fetch('sales_order.php', { method: 'POST', body: formData })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const select = $('#editVehicleSelect');
                    select.empty().append('<option value="">-- Choose Vehicle --</option>');
                    data.vehicles.forEach(vehicle => {
                        const label = `${vehicle.vehicle_type} - ${vehicle.plate_number}`;
                        const option = new Option(label, vehicle.vehicle_id);
                        $(option).data('type', vehicle.vehicle_type).data('plate', vehicle.plate_number);
                        select.append(option);
                    });
                    select.trigger('change');
                }
            })
            .catch(error => { console.error('Error refreshing vehicles:', error); });
    }
    
    function checkStockBeforeConfirm(soId) {
        showLoading();
        const formData = new FormData();
        formData.append('action', 'check_stock');
        formData.append('so_id', soId);
        
        return fetch('sales_order.php', { method: 'POST', body: formData })
            .then(response => response.json())
            .then(data => {
                Swal.close();
                if (data.success) {
                    if (data.sufficient) {
                        const stockMsg = document.getElementById('stockCheckMessage');
                        const stockText = document.getElementById('stockCheckText');
                        if (stockMsg) stockMsg.style.display = 'block';
                        if (stockText) stockText.innerHTML = '<i class="bi bi-check-circle-fill text-success"></i> Stock is sufficient for all items.';
                        return true;
                    } else {
                        let html = '<ul class="list-group">';
                        data.insufficient_items.forEach(item => {
                            html += `<li class="list-group-item list-group-item-warning"><strong>${item.item_code}</strong> - ${item.item_name}<br>Required: ${item.required}, Available: ${item.available}</li>`;
                        });
                        html += '</ul>';
                        const stockList = document.getElementById('insufficientStockList');
                        if (stockList) stockList.innerHTML = html;
                        const modal = new bootstrap.Modal(document.getElementById('stockWarningModal'));
                        modal.show();
                        return false;
                    }
                } else {
                    Swal.fire('Error', data.message, 'error');
                    return false;
                }
            });
    }
    
    // ===== VIEW ORDER FUNCTION =====
function viewOrder(id) {
    showLoading();
    const formData = new FormData();
    formData.append('action', 'get_order');
    formData.append('so_id', id);
    
    fetch('sales_order.php', { method: 'POST', body: formData })
        .then(response => response.json())
        .then(data => {
            Swal.close();
            if (data.success) {
                const order = data.order, items = data.items, documents = data.documents || {}, invoice = data.invoice || null;
                const outstandingBalance = data.outstanding_balance || 0;
                const creditLimit = data.credit_limit || 0;
                const creditUsed = data.credit_used || 0;
                const isOverLimit = data.is_over_limit || false;
                const encodedBy = order.encoded_by || 'N/A';
                const paymentTermsText = data.payment_terms_text || 'Cash on Delivery';
                const beyondCreditApprover = data.beyond_credit_approver || '';
                
                const formattedDate = new Date(order.created_at).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
                const statusBadge = getStatusBadge(order.order_status);
                const statusText = getStatusText(order.order_status);
                
                const balanceClass = isOverLimit ? 'warning' : '';
                const balanceIcon = isOverLimit ? '<i class="bi bi-exclamation-triangle-fill"></i>' : '<i class="bi bi-credit-card"></i>';
                
                let outstandingHtml = `
                    <div class="outstanding-balance-card ${balanceClass}">
                        <div class="d-flex align-items-center">
                            ${balanceIcon}
                            <div class="flex-grow-1">
                                <div class="label">OUTSTANDING BALANCE</div>
                                <div class="amount">${formatCurrency(outstandingBalance)}</div>
                            </div>
                        </div>
                        <div class="credit-info-row">
                            <div class="credit-item"><span>Credit Limit:</span> <span>${formatCurrency(creditLimit)}</span></div>
                            <div class="credit-item"><span>Credit Used:</span> <span>${formatCurrency(creditUsed)}</span></div>
                        </div>
                        ${isOverLimit ? '<div class="mt-2 text-danger small"><i class="bi bi-exclamation-circle"></i> Customer has exceeded credit limit! New orders are blocked.</div>' : ''}
                    </div>
                `;
                
                // ========== SIMPLE & CLEAN ITEMS TABLE - SA LOOB NG MODAL ==========
                let itemsHtml = '';
                let grandTotal = 0;
                
                if (items && items.length > 0) {
                    itemsHtml = `
                        <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
                            <table style="width: 100%; border-collapse: collapse; font-size: 0.85rem; background: white; border: 1px solid #dee2e6; border-radius: 8px; overflow: hidden;">
                                <thead>
                                    <tr style="background-color: #f8f9fa;">
                                        <th style="padding: 10px 12px; font-weight: 600; border-bottom: 1px solid #dee2e6; text-align: left;">Item Code</th>
                                        <th style="padding: 10px 12px; font-weight: 600; border-bottom: 1px solid #dee2e6; text-align: left;">Item Name</th>
                                        <th style="padding: 10px 12px; font-weight: 600; border-bottom: 1px solid #dee2e6; text-align: center;">Unit</th>
                                        <th style="padding: 10px 12px; font-weight: 600; border-bottom: 1px solid #dee2e6; text-align: center;">Quantity</th>
                                        <th style="padding: 10px 12px; font-weight: 600; border-bottom: 1px solid #dee2e6; text-align: right;">Unit Price</th>
                                        <th style="padding: 10px 12px; font-weight: 600; border-bottom: 1px solid #dee2e6; text-align: right;">Total</th>
                                    </tr>
                                </thead>
                                <tbody>
                    `;
                    
                    items.forEach((item, index) => {
                        const subtotal = item.quantity_ordered * item.unit_price;
                        grandTotal += subtotal;
                        itemsHtml += `
                            <tr style="border-bottom: 1px solid #f0f0f0;">
                                <td style="padding: 10px 12px; vertical-align: middle;">${escapeHtml(item.item_code)}</div>
                                <td style="padding: 10px 12px; vertical-align: middle;">${escapeHtml(item.item_name)}</div>
                                <td style="padding: 10px 12px; vertical-align: middle; text-align: center;">${escapeHtml(item.unit_type || 'pcs')}</div>
                                <td style="padding: 10px 12px; vertical-align: middle; text-align: center;">${item.quantity_ordered}</div>
                                <td style="padding: 10px 12px; vertical-align: middle; text-align: right; color: #059669; font-weight: 500;">${formatCurrency(item.unit_price)}</div>
                                <td style="padding: 10px 12px; vertical-align: middle; text-align: right; font-weight: 500;">${formatCurrency(subtotal)}</div>
                            </tr>
                        `;
                    });
                    
                    // Grand Total sa loob ng table
                    itemsHtml += `
                                </tbody>
                                <tfoot>
                                    <tr style="background-color: #f8f9fa;">
                                        <td colspan="5" style="padding: 8px 12px; text-align: right; font-weight: 600;">GRAND TOTAL</div>
                                        <td style="padding: 8px 12px; text-align: right; font-weight: 700; color: #059669;">${formatCurrency(grandTotal)}</div>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    `;
                } else {
                    itemsHtml = '<div class="text-center py-4 text-muted">No items found for this order</div>';
                }
                
                let documentsHtml = '';
                if (documents.pick_list_number || documents.driver_name || documents.vehicle || invoice || documents.trip_ticket_number) {
                    documentsHtml = '<div class="mt-4"><h6 class="fw-bold mb-3">Generated Documents</h6><div class="row g-2">';
                    if (documents.pick_list_number) documentsHtml += '<div class="col-md-6"><div class="border rounded p-2 bg-light"><small class="text-muted">Pick List</small><br><strong>' + escapeHtml(documents.pick_list_number) + '</strong></div></div>';
                    if (documents.driver_name) documentsHtml += '<div class="col-md-6"><div class="border rounded p-2 bg-light"><small class="text-muted">Assigned Driver</small><br><strong><i class="bi bi-person-badge"></i> ' + escapeHtml(documents.driver_name) + '</strong></div></div>';
                    if (documents.vehicle) documentsHtml += '<div class="col-md-6"><div class="border rounded p-2 bg-light"><small class="text-muted">Assigned Vehicle</small><br><strong><i class="bi bi-truck"></i> ' + escapeHtml(documents.vehicle) + '</strong></div></div>';
                    if (invoice) {
                        let invoiceStatusClass = invoice.invoice_status === 'paid' ? 'success' : (invoice.invoice_status === 'cancelled' ? 'danger' : (invoice.invoice_status === 'overdue' ? 'danger' : 'warning'));
                        let invoiceStatusText = invoice.invoice_status === 'overdue' ? 'Overdue' : invoice.invoice_status;
                        documentsHtml += '<div class="col-md-6"><div class="border rounded p-2 bg-light"><small class="text-muted">Invoice</small><br><strong>' + escapeHtml(invoice.invoice_number) + '</strong><br><span class="badge bg-' + invoiceStatusClass + '">' + escapeHtml(invoiceStatusText) + '</span></div></div>';
                    }
                    if (documents.trip_ticket_number) documentsHtml += '<div class="col-md-6"><div class="border rounded p-2 bg-light"><small class="text-muted">Trip Ticket</small><br><strong>' + escapeHtml(documents.trip_ticket_number) + '</strong></div></div>';
                    documentsHtml += '</div></div>';
                }
                
                let beyondCreditHtml = '';
                if (parseInt(order.beyond_credit_limit_allowed || 0) === 1) {
                    beyondCreditHtml = `
                        <div class="card mb-3 border-warning">
                            <div class="card-header bg-warning text-dark">
                                <h6 class="mb-0 fw-bold"><i class="bi bi-exclamation-triangle-fill me-2"></i>Beyond Credit Limit Approval</h6>
                            </div>
                            <div class="card-body">
                                <div class="alert alert-warning mb-3">
                                    <strong>I understand that this order is beyond the customer's credit limit, I am allowing this order to proceed.</strong>
                                </div>
                                <table class="table table-sm table-borderless mb-0">
                                    <tr><td width="40%">Explanation:</td><td><strong>${escapeHtml(order.beyond_credit_limit_explanation || 'N/A')}</strong></td></tr>
                                    ${beyondCreditApprover ? `<tr><td width="40%">Allowed By:</td><td>${escapeHtml(beyondCreditApprover)}</td></tr>` : ''}
                                    ${order.beyond_credit_limit_allowed_at ? `<tr><td width="40%">Allowed At:</td><td>${escapeHtml(order.beyond_credit_limit_allowed_at)}</td></tr>` : ''}
                                </table>
                            </div>
                        </div>
                    `;
                }
                // Order Information Card
                const encodedByHtml = `
                        <div class="row mt-3">
                            <div class="col-md-12">
                                <div class="card mb-3">
                                    <div class="card-header bg-light"><h6 class="mb-0 fw-bold">Order Information</h6></div>
                                    <div class="card-body">
                                        <table class="table table-sm table-borderless mb-0">
                                            <tr><td width="40%">Order Number:</div><td><strong>${escapeHtml(order.so_number)}</strong></div></tr>
                                            <tr><td width="40%">Order Date:</div><td>${formattedDate}</div></tr>
                                            <tr><td width="40%">Customer:</div><td><strong>${escapeHtml(order.customer_name)}</strong></div></tr>
                                            ${order.address ? `<tr><td width="40%">Address:</div><td>${escapeHtml(order.address)}</div></tr>` : ''}
                                            ${order.contact_number ? `<tr><td width="40%">Contact:</div><td>${escapeHtml(order.contact_number)}</div></td>` : ''}
                                            ${order.branch_name ? `<tr><td width="40%">Branch:</div><td><span class="badge bg-info">${escapeHtml(order.branch_name)}</span></div></td>` : ''}
                                            <tr><td width="40%">Order Status:</div><td><span class="${statusBadge}">${statusText}</span></div></tr>
                                            <tr><td width="40%">Encoded By:</div><td><strong>${escapeHtml(encodedBy)}</strong></div></tr>
                                            <tr><td width="40%">Payment Terms:</div><td><strong>${escapeHtml(paymentTermsText)}</strong></div></tr>
                                            ${invoice && invoice.invoice_status ? `<tr><td width="40%">Payment Status:</div><td><span class="badge ${invoice.invoice_status === 'paid' ? 'bg-success' : (invoice.invoice_status === 'overdue' ? 'bg-danger' : (invoice.invoice_status === 'cancelled' ? 'bg-danger' : 'bg-warning text-dark'))}">${escapeHtml(invoice.invoice_status === 'overdue' ? 'Overdue' : invoice.invoice_status)}</span></div></tr>` : ''}
                                            ${invoice && invoice.collected_by ? `<tr><td width="40%">Collected By:</div><td><strong>${escapeHtml(invoice.collected_by)}</strong>${invoice.collected_at ? ` <small class="text-muted">(${escapeHtml(invoice.collected_at)})</small>` : ''}</div></tr>` : ''}
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    `;
                    
                
                // Order Summary Card (HIWALAY - nasa pagitan ng Order Information at Order Items)
                const orderSummaryHtml = `
                    <div class="row">
                        <div class="col-md-12">
                            <div class="card mb-3">
                                <div class="card-header bg-light"><h6 class="mb-0 fw-bold">Order Summary</h6></div>
                                <div class="card-body">
                                    <table class="table table-sm table-borderless mb-0">
                                        <tr><td width="40%">Total Items:</div><td>${order.total_items || 0}</div></tr>
                                        <tr><td width="40%">Total Quantity:</div><td>${order.total_quantity || 0}</div></tr>
                                        <tr><td width="40%">Total Amount:</div><td class="fw-bold fs-5 text-success">${formatCurrency(order.total_amount)}</div><tr>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                `;
                
                // Order Items Card
                const orderItemsHtml = `
                    <div class="card mt-3">
                        <div class="card-header bg-light"><h6 class="mb-0 fw-bold"><i class="bi bi-box-seam me-2"></i>Order Items</h6></div>
                        <div class="card-body p-0">
                            ${itemsHtml}
                        </div>
                    </div>
                `;
                
                const viewContent = document.getElementById('viewOrderContent');
                if (viewContent) {
                    viewContent.innerHTML = outstandingHtml + beyondCreditHtml + encodedByHtml + orderSummaryHtml + orderItemsHtml + documentsHtml;
                }
                
                currentOrderId = id;
                const editBtn = document.getElementById('editFromViewBtn');
                const printBtn = document.getElementById('printOrderBtn');
                const deleteBtn = document.getElementById('deleteFromViewBtn');
                
                if (editBtn && printBtn && deleteBtn) {
                    if (order.order_status === 'pending') { 
                        editBtn.style.display = 'inline-block'; 
                        printBtn.style.display = 'none';
                        deleteBtn.style.display = 'inline-block';
                    } else {
                        editBtn.style.display = 'none';
                        printBtn.style.display = 'inline-block';
                        deleteBtn.style.display = 'none';
                    }
                }
                
                const modalElement = document.getElementById('viewOrderModal');
                if (modalElement) {
                    const modal = new bootstrap.Modal(modalElement);
                    modal.show();
                    modalElement.addEventListener('hidden.bs.modal', function onHidden() {
                        removeAllBackdrops();
                        modalElement.removeEventListener('hidden.bs.modal', onHidden);
                    });
                }
            } else {
                Swal.fire('Error', data.message, 'error');
            }
        })
        .catch(error => { 
            Swal.close(); 
            Swal.fire('Error', 'An error occurred while fetching order details', 'error'); 
        });
}
    function editFromView() { 
        const modal = bootstrap.Modal.getInstance(document.getElementById('viewOrderModal')); 
        if (modal) modal.hide(); 
        setTimeout(() => editOrder(currentOrderId), 300); 
    }
    
    function deleteFromView() {
        const modalElement = document.getElementById('viewOrderModal');
        const modal = bootstrap.Modal.getInstance(modalElement);
        if (modal) modal.hide();
        setTimeout(() => {
            removeAllBackdrops();
            if (currentOrderId) deleteOrder(currentOrderId);
        }, 300);
    }
    
    function editOrder(id) {
        showLoading();
        const formData = new FormData();
        formData.append('action', 'get_order');
        formData.append('so_id', id);
        
        fetch('sales_order.php', { method: 'POST', body: formData })
            .then(response => response.json())
            .then(data => {
                Swal.close();
                if (data.success) {
                    const order = data.order;
                    const orderDate = order.created_at.split(' ')[0];
                    
                    document.getElementById('editOrderId').value = order.so_id;
                    document.getElementById('editOrderNumber').value = order.so_number;
                    document.getElementById('editOrderDate').value = orderDate;
                    document.getElementById('editCustomerName').value = order.customer_name;
                    document.getElementById('editOrderStatus').value = order.order_status;
                    document.getElementById('editTotalItems').value = order.total_items || 0;
                    document.getElementById('editTotalQty').value = order.total_quantity || 0;
                    document.getElementById('editTotalAmount').value = order.total_amount;
                    
                    if (typeof $ !== 'undefined') {
                        $('#editDriverSelect').val('').trigger('change');
                        $('#editVehicleSelect').val('').trigger('change');
                    }
                    currentOrderId = id;
                    
                    document.getElementById('stockCheckMessage').style.display = 'none';
                    document.getElementById('driverSelectionContainer').style.display = 'none';
                    document.getElementById('vehicleSelectionContainer').style.display = 'none';
                    document.getElementById('noDriversMessage').style.display = 'none';
                    document.getElementById('paymentNotice').style.display = 'none';
                    
                    // If customer is already over limit, keep confirmation available but require explanation/acknowledgement on update.
                    if (data.is_over_limit) {
                        Swal.fire({
                            icon: 'warning',
                            title: 'Customer is Over Credit Limit',
                            text: 'You may still confirm this order, but an explanation and acknowledgement will be required.',
                            confirmButtonColor: '#f59e0b'
                        });
                    }
                    
                    if (order.order_status !== 'pending') {
                        if (order.order_status === 'confirmed' || order.order_status === 'processing' || order.order_status === 'ready' || order.order_status === 'in_transit') {
                            document.getElementById('editOrderStatus').innerHTML = `<option value="confirmed" ${order.order_status === 'confirmed' ? 'selected' : ''}>Confirmed</option><option value="processing" ${order.order_status === 'processing' ? 'selected' : ''}>Processing</option><option value="ready" ${order.order_status === 'ready' ? 'selected' : ''}>For Delivery</option><option value="delivered">Mark as Delivered</option><option value="cancelled">Cancel Order</option>`;
                        } else if (order.order_status === 'delivered') {
                            document.getElementById('editOrderStatus').innerHTML = `<option value="delivered" selected>Delivered</option><option value="cancelled">Cancel Order</option>`;
                        } else if (order.order_status === 'cancelled') {
                            document.getElementById('editOrderStatus').innerHTML = `<option value="cancelled" selected>Cancelled</option>`;
                            document.getElementById('updateOrderBtn').disabled = true;
                        }
                        refreshAvailableDrivers();
                        refreshAvailableVehicles();
                    } else {
                        document.getElementById('editOrderStatus').innerHTML = `<option value="pending">Pending</option><option value="confirmed">Confirm Order (Generate Documents & Deduct Stock)</option><option value="delivered">Mark as Delivered</option><option value="cancelled">Cancel Order</option>`;
                        document.getElementById('editOrderStatus').disabled = false;
                        document.getElementById('updateOrderBtn').disabled = false;
                        refreshAvailableDrivers();
                        refreshAvailableVehicles();
                    }
                    const modal = new bootstrap.Modal(document.getElementById('editOrderModal'));
                    modal.show();
                } else Swal.fire('Error', data.message, 'error');
            })
            .catch(error => { Swal.close(); Swal.fire('Error', 'An error occurred while fetching order details', 'error'); });
    }
    
    function updateOrder() {
        const orderId = document.getElementById('editOrderId')?.value;
        const orderDate = document.getElementById('editOrderDate')?.value;
        const orderStatus = document.getElementById('editOrderStatus')?.value;
        const totalAmount = document.getElementById('editTotalAmount')?.value;
        const selectedDriver = document.getElementById('editDriverSelect')?.value;
        const selectedVehicle = document.getElementById('editVehicleSelect')?.value;

        if (!orderDate) { Swal.fire('Warning', 'Order Date is required', 'warning'); return; }
        if (!totalAmount || totalAmount < 0) { Swal.fire('Warning', 'Valid Total Amount is required', 'warning'); return; }
        if (orderStatus === 'confirmed') {
            if (!selectedDriver) { Swal.fire('Warning', 'Please select a driver for this delivery', 'warning'); return; }
            if (!selectedVehicle) { Swal.fire('Warning', 'Please select a vehicle for this delivery', 'warning'); return; }
            checkStockBeforeConfirm(orderId).then(proceed => { if (proceed) proceedWithUpdate(orderId, orderDate, orderStatus, totalAmount, selectedDriver, selectedVehicle); });
        } else { proceedWithUpdate(orderId, orderDate, orderStatus, totalAmount, null, null); }
    }

    function proceedWithUpdate(orderId, orderDate, orderStatus, totalAmount, driverId, vehicleId, beyondExplanation = '', beyondAcknowledged = false) {
        showLoading();
        const formData = new FormData();
        formData.append('action', 'update_order');
        formData.append('so_id', orderId);
        formData.append('created_at', orderDate);
        formData.append('order_status', orderStatus);
        formData.append('total_amount', totalAmount);
        if (driverId) formData.append('driver_id', driverId);
        if (vehicleId) formData.append('vehicle_id', vehicleId);
        if (beyondExplanation) formData.append('beyond_credit_explanation', beyondExplanation);
        if (beyondAcknowledged) formData.append('beyond_credit_acknowledged', '1');

        fetch('sales_order.php', { method: 'POST', body: formData })
            .then(response => response.json())
            .then(data => {
                Swal.close();
                if (data.success) {
                    Swal.fire({ icon: 'success', title: 'Success!', text: data.message, timer: 2000, showConfirmButton: false }).then(() => { 
                        const modal = bootstrap.Modal.getInstance(document.getElementById('editOrderModal'));
                        if (modal) modal.hide();
                        location.reload(); 
                    });
                } else {
                    if (data.type === 'credit_limit_required') {
                        const editOrderModalEl = document.getElementById('editOrderModal');
                        const editOrderModal = editOrderModalEl ? bootstrap.Modal.getInstance(editOrderModalEl) : null;
                        const showBeyondCreditApprovalModal = () => {
                            Swal.fire({
                                icon: 'warning',
                                title: data.title || 'Beyond Credit Limit Approval Required',
                                html: `
                                    ${data.html || ''}
                                    <div class="text-start mt-3">
                                        <label class="form-label fw-bold" for="beyondCreditExplanationInput">Explanation <span class="text-danger">*</span></label>
                                        <textarea id="beyondCreditExplanationInput" class="form-control" rows="4" placeholder="Enter reason why this beyond-credit-limit order is being allowed..."></textarea>
                                        <div class="form-check mt-3">
                                            <input class="form-check-input" type="checkbox" value="1" id="beyondCreditAcknowledgeInput">
                                            <label class="form-check-label fw-semibold" for="beyondCreditAcknowledgeInput">
                                                I understand that this order is beyond credit limit, I am allowing this order to proceed.
                                            </label>
                                        </div>
                                    </div>
                                `,
                                showCancelButton: true,
                                confirmButtonText: 'Allow & Confirm Order',
                                cancelButtonText: 'Cancel',
                                confirmButtonColor: '#047857',
                                cancelButtonColor: '#6c757d',
                                focusConfirm: false,
                                focusCancel: false,
                                allowEscapeKey: true,
                                keydownListenerCapture: true,
                                didOpen: () => {
                                    const input = document.getElementById('beyondCreditExplanationInput');
                                    if (input) {
                                        input.removeAttribute('readonly');
                                        input.removeAttribute('disabled');
                                        setTimeout(() => input.focus(), 80);
                                    }
                                },
                                preConfirm: () => {
                                    const explanation = document.getElementById('beyondCreditExplanationInput')?.value.trim() || '';
                                    const acknowledged = document.getElementById('beyondCreditAcknowledgeInput')?.checked || false;
                                    if (!explanation) {
                                        Swal.showValidationMessage('Explanation is required.');
                                        return false;
                                    }
                                    if (!acknowledged) {
                                        Swal.showValidationMessage('Please tick the acknowledgement checkbox.');
                                        return false;
                                    }
                                    return { explanation, acknowledged };
                                }
                            }).then(result => {
                                if (result.isConfirmed && result.value) {
                                    proceedWithUpdate(orderId, orderDate, orderStatus, totalAmount, driverId, vehicleId, result.value.explanation, true);
                                } else if (editOrderModalEl && !editOrderModalEl.classList.contains('show')) {
                                    bootstrap.Modal.getOrCreateInstance(editOrderModalEl, { keyboard: true }).show();
                                }
                            });
                        };

                        if (editOrderModal && editOrderModalEl.classList.contains('show')) {
                            editOrderModalEl.addEventListener('hidden.bs.modal', showBeyondCreditApprovalModal, { once: true });
                            editOrderModal.hide();
                        } else {
                            showBeyondCreditApprovalModal();
                        }
                    } else if (data.type === 'credit_limit_error') {
                        Swal.fire({
                            icon: 'error',
                            title: data.title || 'Credit Limit Exceeded',
                            html: data.html || data.message,
                            confirmButtonColor: '#d33',
                            confirmButtonText: 'OK'
                        });
                    } else {
                        Swal.fire('Error', data.message, 'error');
                    }
                }
            })
            .catch(error => { Swal.close(); Swal.fire('Error', 'An error occurred while updating the order', 'error'); });
    }
    
    function deleteOrder(id) {
        const row = document.querySelector(`.sales-order-row[data-id="${id}"]`);
        if (!row) return;
        document.getElementById('deleteOrderNumber').textContent = row.dataset.orderNumber;
        currentOrderId = id;
        const modal = new bootstrap.Modal(document.getElementById('deleteOrderModal'));
        modal.show();
    }
    
    function confirmDelete() {
        showLoading();
        const formData = new FormData();
        formData.append('action', 'delete_order');
        formData.append('so_id', currentOrderId);
        
        fetch('sales_order.php', { method: 'POST', body: formData })
            .then(response => response.json())
            .then(data => {
                Swal.close();
                if (data.success) {
                    Swal.fire({ icon: 'success', title: 'Deleted!', text: data.message, timer: 2000, showConfirmButton: false }).then(() => { 
                        const modal = bootstrap.Modal.getInstance(document.getElementById('deleteOrderModal'));
                        if (modal) modal.hide();
                        location.reload(); 
                    });
                } else Swal.fire('Error', data.message, 'error');
            })
            .catch(error => { Swal.close(); Swal.fire('Error', 'An error occurred while deleting the order', 'error'); });
    }
    
    // ===== EXPORT TO EXCEL =====
    function exportToExcel() {
        const startDate = document.getElementById('startDate')?.value || '';
        const endDate = document.getElementById('endDate')?.value || '';
        const status = document.getElementById('statusFilter')?.value || '';
        const customer = document.getElementById('customerFilter')?.value || '';
        const search = document.getElementById('searchInput')?.value || '';
        
        showLoading();
        const formData = new FormData();
        formData.append('action', 'export_all_orders');
        formData.append('start_date', startDate);
        formData.append('end_date', endDate);
        formData.append('status', status);
        formData.append('customer', customer);
        formData.append('search', search);
        
        fetch('sales_order.php', { method: 'POST', body: formData })
            .then(response => response.json())
            .then(data => {
                Swal.close();
                if (data.success && data.data && data.data.length > 0) {
                    const headers = [
                        'Date Encoded', 'SO Order Number', 'Customer Code', 'Store Name', 
                        'Customer Name', 'Item Code', 'Item Description', 'Discount', 
                        'Net Price', 'Total Discount', 'Encoded by'
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
                    XLSX.utils.book_append_sheet(wb, ws, 'Sales Orders');
                    XLSX.writeFile(wb, `sales_orders_${new Date().toISOString().slice(0,19)}.xlsx`);
                    Swal.fire('Success', 'Export completed', 'success');
                } else if (data.success && (!data.data || data.data.length === 0)) {
                    Swal.fire('Info', 'No orders found for the selected filters', 'info');
                } else {
                    Swal.fire('Error', data.message || 'Export failed', 'error');
                }
            })
            .catch(error => {
                Swal.close();
                Swal.fire('Error', 'An error occurred during export', 'error');
            });
    }
    
    // ===== PRINT ALL ORDERS (USING HIDDEN IFRAME) =====
    function printAllOrders() {
        const startDate = document.getElementById('startDate')?.value || '';
        const endDate = document.getElementById('endDate')?.value || '';
        const status = document.getElementById('statusFilter')?.value || '';
        const customer = document.getElementById('customerFilter')?.value || '';
        const search = document.getElementById('searchInput')?.value || '';
        
        showLoading();
        const formData = new FormData();
        formData.append('action', 'print_all_orders');
        formData.append('start_date', startDate);
        formData.append('end_date', endDate);
        formData.append('status', status);
        formData.append('customer', customer);
        formData.append('search', search);
        
        fetch('sales_order.php', { method: 'POST', body: formData })
            .then(response => response.json())
            .then(data => {
                Swal.close();
                if (data.success && data.html) {
                    const iframe = document.getElementById('printFrame');
                    const iframeDoc = iframe.contentWindow.document;
                    iframeDoc.open();
                    iframeDoc.write(data.html);
                    iframeDoc.close();
                    setTimeout(() => {
                        iframe.contentWindow.focus();
                        iframe.contentWindow.print();
                    }, 200);
                } else {
                    Swal.fire('Error', data.message || 'Failed to generate print view', 'error');
                }
            })
            .catch(error => {
                Swal.close();
                Swal.fire('Error', 'An error occurred while preparing print', 'error');
            });
    }
    
    // ===== PRINT SINGLE ORDER (DETAILED RECEIPT) =====
    function printSingleOrder(orderId) {
        showLoading();
        const formData = new FormData();
        formData.append('action', 'print_order');
        formData.append('so_id', orderId);
        
        fetch('sales_order.php', { method: 'POST', body: formData })
            .then(response => response.json())
            .then(data => {
                Swal.close();
                if (data.success) {
<<<<<<< HEAD
                    const order = data.order || null;
                    const items = data.items || [];
                    const html = generateRollingOrderReceiptHTML(order, items);
                    showReceiptPrintPreview(html);
=======
                    const order = data.order;
                    const items = data.items;
                    const driver = data.driver;
                    
                    // Build HTML for single order
                    const orderDate = order ? new Date(order.order_date).toLocaleString() : '';
                    const customerName = order ? order.customer_name : '';
                    const orderNumber = order ? order.so_number : '';
                    const orderStatus = order ? order.order_status : '';
                    const totalAmount = order ? order.order_total : 0;
                    const driverName = driver ? driver.driver_name : (order?.assigned_driver !== 'No Driver' ? order?.assigned_driver : 'No Driver');
                    const vehicleName = driver && driver.vehicle_type ? `${driver.vehicle_type} - ${driver.plate_number || ''}` : 'No Vehicle';
                    
                    let itemsHtml = '';
                    let totalQty = 0;
                    if (items && items.length > 0) {
                        items.forEach(item => {
                            const subtotal = item.quantity_ordered * item.unit_price;
                            totalQty += parseInt(item.quantity_ordered);
                            itemsHtml += `
                                <tr>
                                    <td style="padding: 3px; border: 1px solid #000;">${escapeHtml(item.item_code)}</div>
                                    <td style="padding: 3px; border: 1px solid #000;">${escapeHtml(item.item_name)}</div>
                                    <td style="padding: 3px; border: 1px solid #000; text-align: center;">${escapeHtml(item.unit_type || '')}</div>
                                    <td style="padding: 3px; border: 1px solid #000; text-align: center;">${item.quantity_ordered}</div>
                                    <td style="padding: 3px; border: 1px solid #000; text-align: right;">${formatCurrency(item.unit_price)}</div>
                                    <td style="padding: 3px; border: 1px solid #000; text-align: right;">${formatCurrency(subtotal)}</div>
                                </tr>
                            `;
                        });
                    }
                    
                    const statusText = getStatusText(orderStatus);
                    
                    const html = `
                        <!DOCTYPE html>
                        <html>
                        <head>
                            <meta charset="UTF-8">
                            <title>Order #${orderNumber}</title>
                            <style>
                                body { font-family: Arial, sans-serif; margin: 20px; font-size: 12px; }
                                .header { text-align: center; margin-bottom: 20px; }
                                .header h1 { margin: 5px 0; font-size: 18px; }
                                .order-info { border: 1px solid #000; padding: 10px; margin-bottom: 15px; }
                                .order-info-row { display: flex; margin-bottom: 5px; }
                                .order-info-label { width: 120px; font-weight: bold; }
                                .order-info-value { flex: 1; }
                                table { width: 100%; border-collapse: collapse; margin-top: 10px; }
                                th, td { border: 1px solid #000; padding: 6px; text-align: left; }
                                th { background: #f2f2f2; }
                                .total-row { font-weight: bold; }
                                .footer { margin-top: 20px; text-align: center; font-size: 10px; }
                            </style>
                        </head>
                        <body>
                            <div class="header">
                                <h1>Sales Order</h1>
                                <p>${orderNumber}</p>
                            </div>
                            <div class="order-info">
                                <div class="order-info-row"><div class="order-info-label">Order Date:</div><div class="order-info-value">${orderDate}</div></div>
                                <div class="order-info-row"><div class="order-info-label">Customer:</div><div class="order-info-value">${escapeHtml(customerName)}</div></div>
                                <div class="order-info-row"><div class="order-info-label">Status:</div><div class="order-info-value">${statusText}</div></div>
                                <div class="order-info-row"><div class="order-info-label">Driver:</div><div class="order-info-value">${escapeHtml(driverName)}</div></div>
                                <div class="order-info-row"><div class="order-info-label">Vehicle:</div><div class="order-info-value">${escapeHtml(vehicleName)}</div></div>
                            </div>
                            <table>
                                <thead>
                                    <tr><th>Item Code</th><th>Item Name</th><th>Unit</th><th>Qty</th><th>Unit Price</th><th>Subtotal</th></tr>
                                </thead>
                                <tbody>
                                    ${itemsHtml}
                                    <tr class="total-row"><td colspan="3" style="text-align: right;">TOTAL</div><td style="text-align: center;">${totalQty}</div><td></div><td style="text-align: right;">${formatCurrency(totalAmount)}</div></tr>
                                </tbody>
                            </table>
                            <div class="footer">Generated: ${new Date().toLocaleString()}</div>
                        </body>
                        </html>
                    `;
                    
                    const iframe = document.getElementById('printFrame');
                    const iframeDoc = iframe.contentWindow.document;
                    iframeDoc.open();
                    iframeDoc.write(html);
                    iframeDoc.close();
                    setTimeout(() => {
                        iframe.contentWindow.focus();
                        iframe.contentWindow.print();
                    }, 200);
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
                } else {
                    Swal.fire('Error', data.message || 'Failed to load order details', 'error');
                }
            })
            .catch(error => {
                Swal.close();
                Swal.fire('Error', 'An error occurred while preparing print', 'error');
            });
    }
<<<<<<< HEAD

    function writeHtmlToIframe(iframe, html) {
        const iframeDoc = iframe.contentWindow.document;
        iframeDoc.open();
        iframeDoc.write(html);
        iframeDoc.close();
    }

    function showReceiptPrintPreview(html) {
        const previewFrame = document.getElementById('printPreviewFrame');
        const printFrame = document.getElementById('printFrame');

        writeHtmlToIframe(previewFrame, html);
        writeHtmlToIframe(printFrame, html);

        const modalEl = document.getElementById('printPreviewModal');
        const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
        modal.show();
    }

    function printCurrentReceipt() {
        const iframe = document.getElementById('printFrame');
        if (!iframe || !iframe.contentWindow) {
            Swal.fire('Error', 'Print preview is not ready yet.', 'error');
            return;
        }

        setTimeout(() => {
            iframe.contentWindow.focus();
            iframe.contentWindow.print();
        }, 150);
    }

    function generateRollingOrderReceiptHTML(order, items) {
        let itemsHtml = '';
        let totalQty = 0;
        let totalAmount = 0;

        if (items && items.length > 0) {
            itemsHtml = items.map(item => {
                const qty = parseFloat(item.quantity_ordered || 0) || 0;
                const price = parseFloat(item.unit_price || 0) || 0;
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
                        <td class="text-right">${formatCurrency(price)}</td>
                        <td class="text-right">${formatCurrency(subtotal)}</td>
                    </tr>
                `;
            }).join('');
        } else {
            itemsHtml = '<tr><td colspan="4" style="text-align:center;padding:8px 0;">No items</td></tr>';
        }

        const dbTotal = order ? parseFloat(order.order_total || order.total_amount || 0) : 0;
        if (dbTotal > 0) totalAmount = dbTotal;

        const orderDateObj = order && order.order_date ? new Date(order.order_date) : new Date();
        const transactionDate = orderDateObj.toLocaleString('en-PH', {
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

        const orderNumber = order ? escapeHtml(order.so_number || '') : '';
        const customerName = order ? escapeHtml(order.customer_name || 'Walk-in Customer') : '';
        const agentName = order ? escapeHtml(`${order.first_name || ''} ${order.last_name || ''}`.trim() || 'Rolling Account') : 'Rolling Account';

        return `
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Print Receipt ${orderNumber}</title>
<link href="https://fonts.googleapis.com/css2?family=Roboto+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
@page {
    size: 80mm auto;
    margin: 0;
}
html,
body {
    width: 80mm;
    margin: 0;
    padding: 0;
    background: #fff;
    color: #000;
    font-family: "Roboto Mono", "Courier New", monospace;
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
    font-family: "Roboto Mono", "Courier New", monospace;
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
.company-name {
    font-size: 16px;
    font-weight: 700;
    letter-spacing: 0.5px;
}
.receipt-title {
    font-size: 12px;
    font-weight: 700;
}
.receipt-no,
.print-date {
    font-size: 9px;
}
.receipt-info {
    margin: 5px 0;
    padding: 0;
    background: transparent;
    font-size: 9px;
}
.info-line {
    display: block;
    margin: 2px 0;
    word-break: break-word;
}
.info-label {
    font-weight: 700;
}
.info-value {
    font-weight: 400;
}
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
.items-table td {
    padding: 2px 0;
    vertical-align: top;
}
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
.item-code {
    color: #333;
    max-width: 18mm;
    word-break: break-word;
}
.text-center {
    text-align: center;
}
.text-right {
    text-align: right;
}
.receipt-summary {
    margin-top: 6px;
    padding-top: 5px;
    border-top: 1px solid #000;
    font-size: 10px;
}
.summary-line {
    display: flex;
    justify-content: space-between;
    gap: 8px;
    margin: 2px 0;
}
.summary-line.total {
    font-size: 12px;
    font-weight: 700;
}
.receipt-footer {
    margin-top: 10px;
    text-align: center;
    font-size: 9px;
    padding-bottom: 25px;
}
@media print {
    html,
    body {
        width: 80mm;
        margin: 0 !important;
        padding: 0 !important;
    }
    .thermal-receipt {
        width: 72mm;
        padding: 3mm;
    }
}
</style>
</head>
<body>
    <div class="print-wrapper">
        <div class="thermal-receipt">
            <div class="receipt-header">
                <div class="company-name">AMGC</div>
                <div class="receipt-title">Sales Order - (Rolling)</div>
                <div class="receipt-no">SO# ${orderNumber}</div>
                <div class="print-date">${printDate}</div>
            </div>

            <div class="receipt-info">
                <div class="info-line"><span class="info-label">Date:</span> <span class="info-value">${transactionDate}</span></div>
                <div class="info-line"><span class="info-label">Customer Name:</span> <span class="info-value">${customerName}</span></div>
                <div class="info-line"><span class="info-label">Agent:</span> <span class="info-value">${agentName}</span></div>
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

            <div class="receipt-summary">
                <div class="summary-line total"><span></span><span>TOTAL:<span>${formatCurrency(totalAmount)}</span></div>
            </div>

            <div class="receipt-footer">
                *** Thank you! ***
            </div>
        </div>
    </div>
</body>
</html>
        `;
    }
=======
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
    
    function printOrder(id) { 
        if (id) printSingleOrder(id); 
        const modal = bootstrap.Modal.getInstance(document.getElementById('viewOrderModal')); 
        if (modal) modal.hide(); 
    }
    
    function refreshOrders() { location.reload(); }
    function formatDateFn(dateStr) { if (!dateStr) return ''; return new Date(dateStr).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' }); }
    
    function copyFixSQL() { navigator.clipboard.writeText("ALTER TABLE invoices ADD COLUMN so_id INT NULL;\nALTER TABLE invoices ADD FOREIGN KEY (so_id) REFERENCES sales_orders(so_id);").then(() => Swal.fire({ icon: 'success', title: 'Copied!', timer: 1500, showConfirmButton: false })); }
    
    function copySQL(table) { 
        let sql = table === 'sales_orders' ? "ALTER TABLE sales_orders ADD COLUMN branch_id INT NULL;\nALTER TABLE sales_orders ADD FOREIGN KEY (branch_id) REFERENCES branches(branch_id);" 
            : (table === 'customers' ? "ALTER TABLE customers ADD COLUMN branch_id INT NULL;\nALTER TABLE customers ADD FOREIGN KEY (branch_id) REFERENCES branches(branch_id);" 
            : "ALTER TABLE drivers ADD COLUMN branch_id INT NULL;\nALTER TABLE drivers ADD FOREIGN KEY (branch_id) REFERENCES branches(branch_id);"); 
        navigator.clipboard.writeText(sql).then(() => Swal.fire({ icon: 'success', title: 'Copied!', timer: 1500, showConfirmButton: false })); 
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
    
    function showProfileModal() { 
        cleanupModalBackdrops();
        new bootstrap.Modal(document.getElementById('profileModal')).show(); 
    }
    
    function confirmLogout() {
        const modal = bootstrap.Modal.getInstance(document.getElementById('profileModal'));
        if (modal) modal.hide();
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
    
    document.addEventListener('keydown', function(e) {
        if (e.ctrlKey && e.key === 'b' && window.innerWidth > 992) { e.preventDefault(); toggleSidebar(); }
        else if (e.key === 'Escape' && window.innerWidth <= 992) closeMobileSidebar();
        else if (e.ctrlKey && e.key === 'f' && !e.target.matches('input, textarea')) { e.preventDefault(); document.getElementById('searchInput')?.focus(); }
        else if (e.ctrlKey && e.key === 'r' && !e.target.matches('input, textarea')) { e.preventDefault(); refreshOrders(); }
        else if (e.ctrlKey && e.key === 'p' && !e.target.matches('input, textarea')) { e.preventDefault(); printAllOrders(); }
    });
    
    window.toggleDropdown = function(event, dropdownId) {
        event.preventDefault();
        event.stopPropagation();
        const dropdown = document.getElementById(dropdownId);
        const btn = event.currentTarget;
        if (!dropdown) return;
        if (dropdown.classList.contains('show')) {
            dropdown.classList.remove('show');
            btn.classList.remove('active');
        } else {
<<<<<<< HEAD
            ['moreDropdownMenu'].forEach(id => {
=======
            ['inventoryDropdownMenu', 'salesDropdownMenu', 'purchaseDropdownMenu', 'moreDropdownMenu'].forEach(id => {
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
                const d = document.getElementById(id);
                if (d && d !== dropdown) d.classList.remove('show');
            });
            document.querySelectorAll('.more-btn').forEach(b => b.classList.remove('active'));
            dropdown.classList.add('show');
            btn.classList.add('active');
            if (dropdownId === 'purchaseDropdownMenu') setTimeout(fixPurchaseDropdownPosition, 10);
            setTimeout(() => {
                document.addEventListener('click', function closeHandler(e) {
                    if (!dropdown.contains(e.target) && !btn.contains(e.target)) {
                        dropdown.classList.remove('show');
                        btn.classList.remove('active');
                        document.removeEventListener('click', closeHandler);
                    }
                });
            }, 100);
        }
    };
    
    function closeAllDropdowns() {
<<<<<<< HEAD
        ['moreDropdownMenu'].forEach(id => {
=======
        ['inventoryDropdownMenu', 'salesDropdownMenu', 'purchaseDropdownMenu', 'moreDropdownMenu'].forEach(id => {
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
            const dropdown = document.getElementById(id);
            if (dropdown) dropdown.classList.remove('show');
        });
        document.querySelectorAll('.more-btn').forEach(btn => btn.classList.remove('active'));
    }
    
    document.addEventListener('keydown', function(e) { if (e.key === 'Escape') closeAllDropdowns(); });
    
    window.addEventListener('scroll', function() {
        if (globalScrollTimeout) clearTimeout(globalScrollTimeout);
        globalScrollTimeout = setTimeout(() => closeAllDropdowns(), 150);
    });
    
    function fixPurchaseDropdownPosition() {
        const purchaseDropdown = document.querySelector('#purchaseDropdown .more-dropdown');
        if (purchaseDropdown) {
            purchaseDropdown.style.setProperty('right', '0', 'important');
            purchaseDropdown.style.setProperty('left', 'auto', 'important');
        }
    }
    
    window.addEventListener('resize', fixPurchaseDropdownPosition);
    
    const purchaseMenu = document.getElementById('purchaseDropdownMenu');
    if (purchaseMenu) {
        new MutationObserver(mutations => {
            mutations.forEach(mutation => {
                if (mutation.type === 'attributes' && mutation.attributeName === 'class' && purchaseMenu.classList.contains('show')) {
                    fixPurchaseDropdownPosition();
                }
            });
        }).observe(purchaseMenu, { attributes: true });
    }
    
    function setActiveMobileNav() {
        const currentPage = window.location.pathname.split('/').pop();
        document.querySelectorAll('.mobile-nav .nav-link, .more-btn, .dropdown-item, .has-active').forEach(el => el.classList.remove('active', 'has-active'));
        document.querySelectorAll('.mobile-nav .nav-link:not(.more-btn)').forEach(link => { if (link.getAttribute('href') === currentPage) link.classList.add('active'); });
        document.querySelectorAll('.more-dropdown .dropdown-item').forEach(item => {
            if (item.getAttribute('href') === currentPage) {
                item.classList.add('active');
                const parentDropdown = item.closest('.dropdown-more');
                if (parentDropdown) parentDropdown.querySelector('.more-btn')?.classList.add('has-active');
            }
        });
        if (currentPage === 'trip_tickets.php') document.querySelector('#mobileNav .nav-link[href="trip_tickets.php"]')?.classList.add('active');
    }
    
    function handleSalesOrderTap() {
        document.querySelectorAll('#salesOrdersTable tbody tr.sales-order-row').forEach(row => {
            row.removeEventListener('click', handleRowClick);
            row.addEventListener('click', handleRowClick);
        });
    }
    
    function handleRowClick(event) {
        if (event.target.closest('.action-buttons') || event.target.closest('.btn-action')) return;
        const orderId = event.currentTarget.getAttribute('data-id');
        if (orderId && typeof viewOrder === 'function') viewOrder(orderId);
    }
    
    function attachRowClickEvents() {
        document.querySelectorAll('#salesOrdersTable tbody tr.sales-order-row').forEach(row => {
            row.removeEventListener('click', rowClickHandler);
            row.addEventListener('click', rowClickHandler);
        });
    }
    
    function rowClickHandler(e) {
        if (e.target.closest('.btn-action')) return;
        const orderId = this.getAttribute('data-id');
<<<<<<< HEAD
        if (!orderId) return;
        if (activeSalesOrderTab === 'pending_today' && typeof openServeDeliverModal === 'function') {
            openServeDeliverModal(orderId);
            return;
        }
        if (typeof viewOrder === 'function') viewOrder(orderId);
=======
        if (orderId && typeof viewOrder === 'function') viewOrder(orderId);
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
    }
    
    function forceRemoveBackdrop() {
        document.querySelectorAll('.modal-backdrop').forEach(backdrop => backdrop.remove());
        document.body.classList.remove('modal-open');
        document.body.style.overflow = '';
        document.body.style.paddingRight = '';
        document.body.style.position = '';
    }
    
    setTimeout(function() {
        handleSalesOrderTap();
        attachRowClickEvents();
<<<<<<< HEAD
        ['viewOrderModal', 'serveDeliverModal', 'editOrderModal', 'deleteOrderModal', 'stockWarningModal'].forEach(modalId => {
=======
        ['viewOrderModal', 'editOrderModal', 'deleteOrderModal', 'stockWarningModal'].forEach(modalId => {
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
            const modalElement = document.getElementById(modalId);
            if (modalElement) {
                modalElement.addEventListener('hidden.bs.modal', forceRemoveBackdrop);
                modalElement.addEventListener('show.bs.modal', forceRemoveBackdrop);
            }
        });
        document.querySelectorAll('[data-bs-dismiss="modal"]').forEach(btn => btn.addEventListener('click', () => setTimeout(forceRemoveBackdrop, 100)));
        document.addEventListener('keydown', e => {
            if (e.key === 'Escape') {
                const openModal = document.querySelector('.modal.show');
                if (openModal) {
                    const instance = bootstrap.Modal.getInstance(openModal) || new bootstrap.Modal(openModal, { keyboard: true });
                    instance.hide();
                }
                setTimeout(forceRemoveBackdrop, 100);
            }
        });
        document.querySelectorAll('.modal').forEach(modal => modal.addEventListener('click', e => { if (e.target === modal) setTimeout(forceRemoveBackdrop, 100); }));
        document.addEventListener('click', e => { if (e.target.classList?.contains('modal-backdrop')) setTimeout(forceRemoveBackdrop, 100); });
    }, 100);
    
    // ========== SIDEBAR DROPDOWN HANDLING ==========

    // Toggle sidebar dropdown function - properly handles collapsed state
    function toggleSidebarDropdown(event, targetId) {
        event.preventDefault();
        event.stopPropagation();
        
        const target = document.getElementById(targetId);
        const btn = event.currentTarget;
        const arrow = btn.querySelector('.dropdown-arrow');
        const sidebar = document.getElementById('sidebar');
        
        // If sidebar is collapsed, expand it first then open dropdown
        if (sidebar.classList.contains('collapsed')) {
            // Expand the sidebar first
            sidebar.classList.remove('collapsed');
            localStorage.setItem('sidebarCollapsed', 'false');
            
            // Small delay to let CSS transition complete, then open dropdown
            setTimeout(() => {
                // Close all other dropdowns first
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
                
                // Open the clicked dropdown
                target.classList.add('show');
                if (arrow) arrow.style.transform = 'translateY(-50%) rotate(180deg)';
            }, 50);
            return;
        }
        
        // Normal behavior when sidebar is already expanded
        if (target.classList.contains('show')) {
            target.classList.remove('show');
            if (arrow) arrow.style.transform = 'translateY(-50%) rotate(0deg)';
        } else {
            // Close all other open dropdowns
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
    
    // Set active sidebar item based on current page
    function setActiveSidebarItem() {
        const currentPage = window.location.pathname.split('/').pop();
        
        // Remove active class from all nav links
        document.querySelectorAll('.sidebar .nav-link').forEach(link => {
            link.classList.remove('active');
        });
        
        // Find and activate the matching link
        document.querySelectorAll('.sidebar .nav-link').forEach(link => {
            const href = link.getAttribute('href');
            if (href === currentPage) {
                link.classList.add('active');
                
                // If this link is inside a dropdown, expand the dropdown
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
    
    // Update active state for dropdown parent when sidebar is collapsed
    function updateDropdownParentActiveState() {
        const sidebar = document.getElementById('sidebar');
        if (!sidebar) return;
        
        if (sidebar.classList.contains('collapsed')) {
            // Find all dropdown-nav items that have an active child link
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
    
    // Function to expand all dropdown containers that contain active links
    function expandActiveDropdownContainers() {
        const sidebar = document.getElementById('sidebar');
        if (!sidebar) return;
        
        // Find all dropdown-nav containers
        const dropdownNavs = document.querySelectorAll('.sidebar .dropdown-nav');
        
        dropdownNavs.forEach(dropdownNav => {
            // Check if this dropdown contains any active link
            const activeLink = dropdownNav.querySelector('.nav-link.active');
            
            if (activeLink) {
                // Find the collapse element inside this dropdown
                const collapseDiv = dropdownNav.querySelector('.collapse');
                
                if (collapseDiv && !collapseDiv.classList.contains('show')) {
                    // Open the dropdown
                    collapseDiv.classList.add('show');
                    
                    // Rotate the arrow of the parent link
                    const parentLink = dropdownNav.querySelector(':scope > .nav-link');
                    if (parentLink) {
                        const arrow = parentLink.querySelector('.dropdown-arrow');
                        if (arrow) {
                            arrow.style.transform = 'translateY(-50%) rotate(180deg)';
                        }
                        // Also add active class to parent if sidebar is collapsed
                        if (sidebar.classList.contains('collapsed')) {
                            parentLink.classList.add('active');
                        }
                    }
                }
            }
        });
    }
    
    // Toggle sidebar function (updated with proper behavior)
    function toggleSidebar() {
        const sidebar = document.getElementById('sidebar');
        const desktopToggleBtn = document.getElementById('desktopToggleBtn');
        
        if (window.innerWidth <= 992) {
            // Mobile behavior
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
            // Desktop behavior - toggle collapse
            const wasCollapsed = sidebar.classList.contains('collapsed');
            sidebar.classList.toggle('collapsed');
            localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
            
            // If expanding from collapsed state
            if (wasCollapsed && !sidebar.classList.contains('collapsed')) {
                // Remove any inline styles that might have been set by hover
                sidebar.style.width = '';
                
                // AFTER expanding, find any active child link and open its parent dropdown
                setTimeout(function() {
                    expandActiveDropdownContainers();
                }, 150);
            }
        }
    }
    
    // Initialize sidebar on DOM load
    document.addEventListener('DOMContentLoaded', function() {
        // Restore sidebar state from localStorage
        const sidebar = document.getElementById('sidebar');
        if (sidebar && window.innerWidth > 992) {
            const isCollapsed = localStorage.getItem('sidebarCollapsed') === 'true';
            if (isCollapsed) {
                sidebar.classList.add('collapsed');
            } else {
                sidebar.classList.remove('collapsed');
            }
        }
        
        // Set active sidebar item
        setActiveSidebarItem();
        
        // Update parent active states
        updateDropdownParentActiveState();
        
        // Prevent dropdown from closing when clicking inside it
        document.querySelectorAll('.sidebar .collapse').forEach(collapse => {
            collapse.addEventListener('click', function(e) {
                e.stopPropagation();
            });
        });
        
        // Handle desktop toggle button
        const desktopToggleBtn = document.getElementById('desktopToggleBtn');
        if (desktopToggleBtn) {
            desktopToggleBtn.addEventListener('click', function() {
                setTimeout(() => {
                    if (sidebar.classList.contains('collapsed')) {
                        // Close all dropdowns when collapsing
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
        
        // Handle mobile menu button
        const mobileMenuBtn = document.getElementById('mobileMenuBtn');
        if (mobileMenuBtn) {
            mobileMenuBtn.addEventListener('click', toggleSidebar);
        }
        
        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', function(e) {
            if (window.innerWidth <= 992 && sidebar && sidebar.classList.contains('active') && 
                !sidebar.contains(e.target) && mobileMenuBtn && !mobileMenuBtn.contains(e.target)) {
                sidebar.classList.remove('active');
                const overlay = document.querySelector('.sidebar-overlay');
                if (overlay) overlay.remove();
            }
        });
    });
<<<<<<< HEAD


    // ===== VIEW-ONLY LOCK FOR ROLLING =====
    // These overrides keep the page safe even if old buttons are cached by the browser.
    function editFromView() {
        Swal.fire('View Only', 'Editing is disabled for Rolling sales order records.', 'info');
    }
    function editOrder(id) {
        Swal.fire('View Only', 'Editing is disabled for Rolling sales order records.', 'info');
    }
    function updateOrder() {
        Swal.fire('View Only', 'Editing is disabled for Rolling sales order records.', 'info');
    }
    function proceedWithUpdate() {
        Swal.fire('View Only', 'Editing is disabled for Rolling sales order records.', 'info');
    }
    function deleteFromView() {
        Swal.fire('View Only', 'Deleting is disabled for Rolling sales order records.', 'info');
    }
    function deleteOrder(id) {
        Swal.fire('View Only', 'Deleting is disabled for Rolling sales order records.', 'info');
    }
// Update active tab UI (gaya ng current_inventory)
function updateActiveTabUI(tabName) {
    // Remove active class from all tabs
    document.querySelectorAll('#salesOrderTabs .category-tab').forEach(tab => {
        tab.classList.remove('active');
    });
    
    // Add active class to selected tab
    const activeTab = document.querySelector(`#salesOrderTabs .category-tab[data-tab="${tabName}"]`);
    if (activeTab) {
        activeTab.classList.add('active');
    }
}

// Modify your existing setSalesOrderTab function
function setSalesOrderTab(tabName) {
    activeSalesOrderTab = tabName || 'historical';
    
    // Update active UI
    updateActiveTabUI(activeSalesOrderTab);
    
    const description = document.getElementById('activeTabDescription');
    if (description) {
        if (activeSalesOrderTab === 'completed_today') {
            description.textContent = 'Showing today’s delivered/completed sales orders only.';
        } else if (activeSalesOrderTab === 'pending_today') {
            description.textContent = 'Showing today\'s sales orders that are not yet delivered.';
        } else {
            description.textContent = 'Showing all historical sales orders.';
        }
    }
    
    applyManualFilters();
    updateSalesOrderTabCounts();
}
=======
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
</script>
</body>
</html>
