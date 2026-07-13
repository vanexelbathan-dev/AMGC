<?php
// customer_list.php - Customer Management (Branch Admin - No Map/Location)

require_once '../config/database.php';
require_once '../config/session_handler.php';

// Get current user info and branch context
$user_id = $_SESSION['user_id'] ?? 0;
$user_name = isset($_SESSION['first_name']) ? $_SESSION['first_name'] . ' ' . $_SESSION['last_name'] : 'Branch Admin';
$user_role = $_SESSION['role'] ?? 'branch_admin';
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
    $user_initials = 'BA';
}

$task_badge_count = 0;

if (isset($conn) && !empty($_SESSION['user_id'])) {
    $uid = (int) $_SESSION['user_id'];

    $taskBadgeStmt = $conn->prepare("
        SELECT COUNT(DISTINCT t.task_id) AS total
        FROM user_tasks t
        INNER JOIN user_task_assignees a
            ON a.task_id = t.task_id
        WHERE a.user_id = ?
          AND a.assignee_status NOT IN ('completed', 'cancelled')
          AND NOW() >= DATE_SUB(
              t.due_datetime,
              INTERVAL COALESCE(t.reminder_days, 0) DAY
          )
    ");

    if ($taskBadgeStmt) {
        $taskBadgeStmt->bind_param('i', $uid);
        $taskBadgeStmt->execute();

        $taskBadgeResult = $taskBadgeStmt->get_result();
        $taskBadgeRow = $taskBadgeResult->fetch_assoc();

        $task_badge_count = (int) ($taskBadgeRow['total'] ?? 0);

        $taskBadgeStmt->close();
    }
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

// Check if customers table exists, create if not
$check_table = $conn->query("SHOW TABLES LIKE 'customers'");
if ($check_table && $check_table->num_rows == 0) {
    $create_table = "CREATE TABLE IF NOT EXISTS customers (
        customer_id INT AUTO_INCREMENT PRIMARY KEY,
        customer_code VARCHAR(50) NOT NULL UNIQUE,
        customer_name VARCHAR(150) NOT NULL,
        contact_person VARCHAR(150),
        email VARCHAR(100),
        phone_number VARCHAR(20),
        address TEXT,
        region VARCHAR(100),
        province VARCHAR(100),
        city VARCHAR(100),
        barangay VARCHAR(100),
        price_level VARCHAR(50) DEFAULT 'Standard',
        latitude VARCHAR(50),
        longitude VARCHAR(50),
        store_name VARCHAR(200),
        customer_group VARCHAR(100) DEFAULT NULL,
        store_image VARCHAR(255),
        city_code VARCHAR(50),
        tax_id VARCHAR(50),
        status ENUM('active','inactive','pending') DEFAULT 'active',
        branch_id INT,
        created_by INT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (branch_id) REFERENCES branches(branch_id) ON DELETE SET NULL,
        FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE SET NULL,
        INDEX idx_customer_code (customer_code),
        INDEX idx_customer_name (customer_name),
        INDEX idx_status (status),
        INDEX idx_branch (branch_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    
    if (!$conn->query($create_table)) {
        error_log("Failed to create customers table: " . $conn->error);
    }
}

// Check if branch_id column exists in customers table (for filtering)
$customers_branch_column_exists = false;
$check_branch_column = $conn->query("SHOW COLUMNS FROM customers LIKE 'branch_id'");
if ($check_branch_column && $check_branch_column->num_rows > 0) {
    $customers_branch_column_exists = true;
}


// ------------------------------------------------------------
// Credit helpers copied from sales_order.php logic
// Para same ang lalabas na outstanding balance sa Customer View at Sales Order View.
// ------------------------------------------------------------
function customerListRecalcCustomerCreditUsed($conn, $customer_id) {
    $customer_id = (int)$customer_id;
    if ($customer_id <= 0) return 0.00;

    // Same source of truth as sales_order.php:
    // - pending and cancelled sales orders are not counted yet
    // - unpaid confirmed/ready/in_transit/delivered orders are counted
    // - beginning balance/manual invoices without linked SO are counted
    // - outstanding amount is always positive or zero
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
                                ELSE GREATEST(COALESCE(inv.balance, 0), COALESCE(inv.total_amount, so.total_amount, 0) - COALESCE(inv.amount_paid, 0), 0)
                            END
                        ELSE COALESCE(NULLIF(so.total_amount, 0), so.order_amount, 0)
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

            UNION ALL

            SELECT
                CASE
                    WHEN LOWER(TRIM(COALESCE(status, 'pending'))) = 'paid' THEN 0
                    WHEN LOWER(TRIM(COALESCE(status, ''))) = 'cancelled' THEN 0
                    ELSE GREATEST(COALESCE(balance, 0), COALESCE(total_amount, 0) - COALESCE(amount_paid, 0), 0)
                END AS unpaid_amount
            FROM invoices
            WHERE customer_id = ?
              AND (so_id IS NULL OR so_id = 0)
        ) unpaid_rows
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) return 0.00;
    $stmt->bind_param("ii", $customer_id, $customer_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $unpaid = max(0, floatval($row['total_unpaid'] ?? 0));
    $stmt->close();

    // Save Credit Used only for customers with credit limit.
    // Outstanding Balance is still returned separately even without credit limit.
    $limit = customerListGetEffectiveCustomerCreditLimit($conn, $customer_id);
    $credit_used_to_save = $limit > 0 ? $unpaid : 0.00;
    $update = "UPDATE customers SET credit_used = ? WHERE customer_id = ?";
    $upd_stmt = $conn->prepare($update);
    if ($upd_stmt) {
        $upd_stmt->bind_param("di", $credit_used_to_save, $customer_id);
        $upd_stmt->execute();
        $upd_stmt->close();
    }

    return $unpaid;
}

function customerListTableExists($conn, $table) {
    $table = $conn->real_escape_string($table);
    $res = $conn->query("SHOW TABLES LIKE '{$table}'");
    return $res && $res->num_rows > 0;
}

function customerListColumnExists($conn, $table, $column) {
    if (!customerListTableExists($conn, $table)) return false;
    $table = $conn->real_escape_string($table);
    $column = $conn->real_escape_string($column);
    $res = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
    return $res && $res->num_rows > 0;
}

function customerListGetCustomerInvoiceHistory($conn, $customer_id, $branch_id = 0, $view_all_branches = false) {
    $customer_id = (int)$customer_id;
    if ($customer_id <= 0 || !customerListTableExists($conn, 'invoices')) return [];

    $hasPayments = customerListTableExists($conn, 'payments');
    $hasSalesOrders = customerListTableExists($conn, 'sales_orders');
    $hasSoNumber = $hasSalesOrders && customerListColumnExists($conn, 'sales_orders', 'so_number');
    $hasSoOrderStatus = $hasSalesOrders && customerListColumnExists($conn, 'sales_orders', 'order_status');
    $hasSoPaymentStatus = $hasSalesOrders && customerListColumnExists($conn, 'sales_orders', 'payment_status');

    $paymentJoin = $hasPayments ? "
        LEFT JOIN (
            SELECT invoice_id,
                   SUM(CASE WHEN LOWER(TRIM(COALESCE(status, 'completed'))) = 'completed' THEN COALESCE(amount, 0) ELSE 0 END) AS collected_amount,
                   MAX(payment_date) AS latest_payment_date,
                   GROUP_CONCAT(DISTINCT payment_method ORDER BY payment_method SEPARATOR ', ') AS payment_methods,
                   GROUP_CONCAT(DISTINCT NULLIF(reference_number, '') ORDER BY reference_number SEPARATOR ', ') AS payment_references
            FROM payments
            GROUP BY invoice_id
        ) p ON p.invoice_id = i.invoice_id
    " : "";
    $paymentSelect = $hasPayments
        ? "COALESCE(p.collected_amount, 0) AS collected_amount, p.latest_payment_date, p.payment_methods, p.payment_references"
        : "0 AS collected_amount, NULL AS latest_payment_date, NULL AS payment_methods, NULL AS payment_references";

    $soSelect = $hasSalesOrders ? ", so.so_number, so.order_status, so.payment_status" : ", NULL AS so_number, NULL AS order_status, NULL AS payment_status";
    $soJoin = $hasSalesOrders ? "LEFT JOIN sales_orders so ON so.so_id = i.so_id" : "";

    $where = "WHERE i.customer_id = ?";
    $types = "i";
    $params = [$customer_id];
    if (!$view_all_branches && (int)$branch_id > 0 && customerListColumnExists($conn, 'invoices', 'branch_id')) {
        $where .= " AND (i.branch_id = ? OR i.branch_id IS NULL OR i.branch_id = 0)";
        $types .= "i";
        $params[] = (int)$branch_id;
    }

    $sql = "
        SELECT
            i.invoice_id,
            i.invoice_number,
            i.si_number,
            i.so_id,
            i.invoice_date,
            i.due_date,
            i.total_amount,
            i.amount_paid,
            i.balance,
            i.status AS invoice_status,
            $paymentSelect
            $soSelect
        FROM invoices i
        $soJoin
        $paymentJoin
        $where
        ORDER BY COALESCE(i.invoice_date, DATE(i.created_at)) DESC, i.invoice_id DESC
        LIMIT 100
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) return [];
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $status = strtolower(trim((string)($row['invoice_status'] ?? 'pending')));
        $orderStatus = strtolower(trim((string)($row['order_status'] ?? '')));
        $paymentStatus = strtolower(trim((string)($row['payment_status'] ?? 'unpaid')));
        $total = (float)($row['total_amount'] ?? 0);
        $paid = max((float)($row['amount_paid'] ?? 0), (float)($row['collected_amount'] ?? 0));
        $balance = max((float)($row['balance'] ?? 0), $total - $paid, 0);
        if ($status === 'paid' || $status === 'cancelled' || in_array($paymentStatus, ['paid', 'completed'], true) || in_array($orderStatus, ['pending', 'cancelled'], true)) {
            $balance = 0.00;
        }
        $row['computed_paid_amount'] = $paid;
        $row['computed_balance'] = max(0, $balance);
        $row['display_document'] = trim((string)($row['si_number'] ?? '')) !== '' ? $row['si_number'] : $row['invoice_number'];
        $rows[] = $row;
    }
    $stmt->close();

    return $rows;
}

function customerListGetActiveApprovedCreditRequest($conn, $customer_id) {
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
    if (!$stmt) return null;
    $stmt->bind_param("i", $customer_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    return $row ?: null;
}

function customerListGetEffectiveCustomerCreditLimit($conn, $customer_id) {
    $customer_limit = 0.00;

    $sql = "SELECT credit_limit FROM customers WHERE customer_id = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("i", $customer_id);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($cust = $res->fetch_assoc()) {
            $customer_limit = floatval($cust['credit_limit'] ?? 0);
        }
        $stmt->close();
    }

    $active_request = customerListGetActiveApprovedCreditRequest($conn, $customer_id);
    if ($active_request && isset($active_request['requested_credit_limit']) && $active_request['requested_credit_limit'] !== null) {
        $requested_limit = floatval($active_request['requested_credit_limit']);
        if ($requested_limit > 0) {
            return $requested_limit;
        }
    }

    return $customer_limit;
}

function customerListGetCustomerCreditSnapshot($conn, $customer_id, $additional_amount = 0.00) {
    $credit_used = customerListRecalcCustomerCreditUsed($conn, $customer_id);
    $credit_limit = customerListGetEffectiveCustomerCreditLimit($conn, $customer_id);
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
        'active_request' => customerListGetActiveApprovedCreditRequest($conn, $customer_id)
    ];
}


function customerListGetCustomerOilVolume($conn, $customer_id, $branch_id = 0, $view_all_branches = false) {
    $customer_id = (int)$customer_id;
    if ($customer_id <= 0) return 0.00;
    if (!customerListTableExists($conn, 'sales_orders') || !customerListTableExists($conn, 'sales_order_items') || !customerListTableExists($conn, 'items')) {
        return 0.00;
    }
    if (!customerListColumnExists($conn, 'items', 'volume') || !customerListColumnExists($conn, 'items', 'category')) {
        return 0.00;
    }

    $quantityColumn = customerListColumnExists($conn, 'sales_order_items', 'quantity_ordered') ? 'quantity_ordered' : 'quantity';
    if (!customerListColumnExists($conn, 'sales_order_items', $quantityColumn)) {
        return 0.00;
    }

    $where = "WHERE so.customer_id = ?
              AND LOWER(TRIM(COALESCE(i.category, ''))) = 'oil'
              AND COALESCE(NULLIF(TRIM(i.volume), ''), '0') <> '0'
              AND LOWER(TRIM(COALESCE(so.order_status, ''))) NOT IN ('pending', 'cancelled')";
    $types = "i";
    $params = [$customer_id];

    if (!$view_all_branches && (int)$branch_id > 0 && customerListColumnExists($conn, 'sales_orders', 'branch_id')) {
        $where .= " AND (so.branch_id = ? OR so.branch_id IS NULL OR so.branch_id = 0)";
        $types .= "i";
        $params[] = (int)$branch_id;
    }

    $sql = "
        SELECT COALESCE(SUM(
            CAST(COALESCE(NULLIF(TRIM(i.volume), ''), '0') AS DECIMAL(12,4))
            * COALESCE(soi.`{$quantityColumn}`, 0)
        ), 0) AS total_oil_volume
        FROM sales_order_items soi
        INNER JOIN sales_orders so ON so.so_id = soi.so_id
        INNER JOIN items i ON i.item_id = soi.item_id
        $where
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) return 0.00;
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    return max(0, floatval($row['total_oil_volume'] ?? 0));
}

function customerListUpdateOverdueInvoices($conn) {
    $checkTable = $conn->query("SHOW TABLES LIKE 'invoices'");
    if (!$checkTable || $checkTable->num_rows === 0) return;

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

customerListUpdateOverdueInvoices($conn);

// ===== CUSTOMER GROUP COLUMN SAFETY =====
// Do NOT run ALTER TABLE during AJAX save/update because it can lock and cause endless Processing.
$customer_group_column_exists = false;
$check_customer_group_column = $conn->query("SHOW COLUMNS FROM customers LIKE 'customer_group'");
if ($check_customer_group_column && $check_customer_group_column->num_rows > 0) {
    $customer_group_column_exists = true;
} elseif ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    // One-time page load safety only. If hosting blocks ALTER, use the included SQL file.
    @$conn->query("ALTER TABLE customers ADD COLUMN customer_group VARCHAR(100) DEFAULT NULL AFTER store_name");
    $recheck_customer_group_column = $conn->query("SHOW COLUMNS FROM customers LIKE 'customer_group'");
    $customer_group_column_exists = ($recheck_customer_group_column && $recheck_customer_group_column->num_rows > 0);
}

// ===== CUSTOMER GROUP MASTER LIST SAFETY =====
// This lets the system save a new customer group even before any customer uses it.
$customer_groups_master_table_exists = false;
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    @$conn->query("CREATE TABLE IF NOT EXISTS customer_groups_master (
        group_id INT AUTO_INCREMENT PRIMARY KEY,
        group_name VARCHAR(100) NOT NULL,
        branch_id INT NULL,
        created_by INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_group_branch (group_name, branch_id),
        INDEX idx_group_name (group_name),
        INDEX idx_branch_id (branch_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
}
$check_customer_groups_master = $conn->query("SHOW TABLES LIKE 'customer_groups_master'");
if ($check_customer_groups_master && $check_customer_groups_master->num_rows > 0) {
    $customer_groups_master_table_exists = true;
}




// ===== MERGED CUSTOMER SAVE HANDLER FIX =====
// Handles Add/Edit before any tab-specific AJAX handler so customer saving will not be blocked after merge.
if (!function_exists('mergedCustomerColumnExists')) {
    function mergedCustomerColumnExists($conn, $column) {
        $column = $conn->real_escape_string($column);
        $res = $conn->query("SHOW COLUMNS FROM customers LIKE '{$column}'");
        return $res && $res->num_rows > 0;
    }
}

if (!function_exists('mergedGenerateCustomerCode')) {
    function mergedGenerateCustomerCode($conn) {
        $prefix = 'CUST-';
        $year = date('Y');
        $month = date('m');
        $like = $prefix . $year . $month . '%';
        $stmt = $conn->prepare("SELECT customer_code FROM customers WHERE customer_code LIKE ? ORDER BY customer_code DESC LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('s', $like);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result && $row = $result->fetch_assoc()) {
                $last_code = (string)($row['customer_code'] ?? '');
                $sequence = intval(substr($last_code, -4)) + 1;
                $stmt->close();
                return $prefix . $year . $month . '-' . str_pad((string)$sequence, 4, '0', STR_PAD_LEFT);
            }
            $stmt->close();
        }
        return $prefix . $year . $month . '-0001';
    }
}

if (!function_exists('mergedHandleStoreImageUpload')) {
    function mergedHandleStoreImageUpload($current_image = '') {
        if (!isset($_FILES['store_image']) || $_FILES['store_image']['error'] !== UPLOAD_ERR_OK) {
            return $current_image;
        }

        $upload_dir = '../uploads/store_images/';
        if (!is_dir($upload_dir)) {
            @mkdir($upload_dir, 0755, true);
        }

        $file_name = $_FILES['store_image']['name'] ?? '';
        $file_tmp = $_FILES['store_image']['tmp_name'] ?? '';
        $file_size = (int)($_FILES['store_image']['size'] ?? 0);
        $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
        $allowed_ext = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

        if (!in_array($file_ext, $allowed_ext, true)) {
            throw new Exception('Invalid store image type.');
        }
        if ($file_size > 5242880) {
            throw new Exception('Store image must not exceed 5MB.');
        }

        $new_file_name = 'store_' . uniqid('', true) . '.' . $file_ext;
        if (!move_uploaded_file($file_tmp, $upload_dir . $new_file_name)) {
            throw new Exception('Failed to upload store image.');
        }

        if ($current_image !== '' && file_exists($upload_dir . $current_image)) {
            @unlink($upload_dir . $current_image);
        }

        return $new_file_name;
    }
}

if (!function_exists('mergedEnsureCustomerGroupMaster')) {
    function mergedEnsureCustomerGroupMaster($conn, $group_name, $branch_id, $user_id) {
        $group_name = trim((string)$group_name);
        if ($group_name === '') return;

        $check_table = $conn->query("SHOW TABLES LIKE 'customer_groups_master'");
        if (!$check_table || $check_table->num_rows === 0) return;

        $stmt = $conn->prepare("SELECT group_id FROM customer_groups_master WHERE LOWER(TRIM(group_name)) = LOWER(TRIM(?)) AND (branch_id = ? OR branch_id IS NULL OR branch_id = 0) LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('si', $group_name, $branch_id);
            $stmt->execute();
            $res = $stmt->get_result();
            $exists = $res && $res->num_rows > 0;
            $stmt->close();
            if ($exists) return;
        }

        $ins = $conn->prepare("INSERT INTO customer_groups_master (group_name, branch_id, created_by) VALUES (?, ?, ?)");
        if ($ins) {
            $ins->bind_param('sii', $group_name, $branch_id, $user_id);
            @$ins->execute();
            $ins->close();
        }
    }
}

$merged_customer_save_actions = ['add_customer', 'update_customer_fast', 'update_customer'];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && in_array($_POST['action'], $merged_customer_save_actions, true)) {
    header('Content-Type: application/json');
    @set_time_limit(20);
    @$conn->query("SET SESSION innodb_lock_wait_timeout = 5");

    try {
        $action = $_POST['action'];
        $customer_id = (int)($_POST['customer_id'] ?? 0);
        $customer_name = trim($_POST['customer_name'] ?? '');
        $contact_person = trim($_POST['contact_person'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone_number'] ?? '');
        $price_level = trim($_POST['price_level'] ?? 'Standard');
        $region = trim($_POST['region'] ?? '');
        $province = trim($_POST['province'] ?? '');
        $city = trim($_POST['city'] ?? '');
        $city_code = trim($_POST['city_code'] ?? '');
        $barangay = trim($_POST['barangay'] ?? '');
        $store_name = trim($_POST['store_name'] ?? '');
        $customer_group = trim($_POST['customer_group'] ?? '');
        $status = trim($_POST['status'] ?? 'active');

        if ($customer_name === '') {
            throw new Exception('Customer name is required.');
        }
        if (!in_array($status, ['active', 'inactive', 'pending'], true)) {
            $status = 'active';
        }

        $address_parts = [];
        if ($barangay !== '') $address_parts[] = $barangay;
        if ($city !== '') $address_parts[] = $city;
        if ($province !== '') $address_parts[] = $province;
        if ($region !== '') $address_parts[] = $region;
        $address = implode(', ', $address_parts);

        $target_branch_id = 0;
        if (!$view_all_branches && (int)$branch_id > 0) {
            $target_branch_id = (int)$branch_id;
        } elseif (isset($_POST['branch_id']) && (int)$_POST['branch_id'] > 0) {
            $target_branch_id = (int)$_POST['branch_id'];
        }

        $conn->begin_transaction();

        if ($action === 'add_customer') {
            $customer_code = trim($_POST['customer_code'] ?? '');
            if ($customer_code === '') {
                $customer_code = mergedGenerateCustomerCode($conn);
            }

            $dup = $conn->prepare("SELECT customer_id FROM customers WHERE customer_code = ? LIMIT 1");
            if ($dup) {
                $dup->bind_param('s', $customer_code);
                $dup->execute();
                $dup_res = $dup->get_result();
                if ($dup_res && $dup_res->num_rows > 0) {
                    $customer_code = mergedGenerateCustomerCode($conn);
                }
                $dup->close();
            }

            $store_image = mergedHandleStoreImageUpload('');

            $data = [
                'customer_code' => $customer_code,
                'customer_name' => $customer_name,
                'contact_person' => $contact_person,
                'email' => $email,
                'phone_number' => $phone,
                'address' => $address,
                'region' => $region,
                'province' => $province,
                'city' => $city,
                'barangay' => $barangay,
                'price_level' => $price_level,
                'store_name' => $store_name,
                'customer_group' => $customer_group,
                'store_image' => $store_image,
                'city_code' => $city_code,
                'latitude' => null,
                'longitude' => null,
                'status' => 'active',
                'branch_id' => $target_branch_id > 0 ? $target_branch_id : null,
                'created_by' => (int)$user_id
            ];

            $columns = [];
            $values = [];
            $types = '';
            foreach ($data as $col => $val) {
                if (!mergedCustomerColumnExists($conn, $col)) continue;
                $columns[] = "`$col`";
                $values[] = $val;
                $types .= in_array($col, ['branch_id', 'created_by'], true) ? 'i' : 's';
            }

            if (empty($columns)) {
                throw new Exception('No valid customer columns found.');
            }

            $placeholders = implode(',', array_fill(0, count($columns), '?'));
            $sql = 'INSERT INTO customers (' . implode(',', $columns) . ') VALUES (' . $placeholders . ')';
            $stmt = $conn->prepare($sql);
            if (!$stmt) throw new Exception('Prepare add failed: ' . $conn->error);
            $stmt->bind_param($types, ...$values);
            if (!$stmt->execute()) throw new Exception('Failed to add customer: ' . $stmt->error);
            $stmt->close();

            if ($customer_group !== '') {
                mergedEnsureCustomerGroupMaster($conn, $customer_group, $target_branch_id, $user_id);
            }

            $conn->commit();
            echo json_encode(['success' => true, 'message' => 'Customer added successfully', 'customer_code' => $customer_code]);
            exit;
        }

        if ($customer_id <= 0) {
            throw new Exception('Invalid customer ID.');
        }

        $existing_image = trim($_POST['existing_store_image'] ?? '');
        if ($existing_image === '' && mergedCustomerColumnExists($conn, 'store_image')) {
            $img_stmt = $conn->prepare("SELECT store_image FROM customers WHERE customer_id = ? LIMIT 1");
            if ($img_stmt) {
                $img_stmt->bind_param('i', $customer_id);
                $img_stmt->execute();
                $img_res = $img_stmt->get_result();
                if ($img_res && $img_row = $img_res->fetch_assoc()) {
                    $existing_image = (string)($img_row['store_image'] ?? '');
                }
                $img_stmt->close();
            }
        }
        $store_image = mergedHandleStoreImageUpload($existing_image);

        $update_data = [
            'customer_name' => $customer_name,
            'contact_person' => $contact_person,
            'email' => $email,
            'phone_number' => $phone,
            'address' => $address,
            'region' => $region,
            'province' => $province,
            'city' => $city,
            'barangay' => $barangay,
            'price_level' => $price_level,
            'store_name' => $store_name,
            'customer_group' => $customer_group,
            'store_image' => $store_image,
            'city_code' => $city_code,
            'status' => $status
        ];

        $sets = [];
        $values = [];
        $types = '';
        foreach ($update_data as $col => $val) {
            if (!mergedCustomerColumnExists($conn, $col)) continue;
            $sets[] = "`$col` = ?";
            $values[] = $val;
            $types .= 's';
        }
        if (mergedCustomerColumnExists($conn, 'updated_at')) {
            $sets[] = "updated_at = NOW()";
        }
        if (empty($sets)) {
            throw new Exception('No valid customer fields to update.');
        }
        $values[] = $customer_id;
        $types .= 'i';

        $sql = 'UPDATE customers SET ' . implode(', ', $sets) . ' WHERE customer_id = ?';
        $stmt = $conn->prepare($sql);
        if (!$stmt) throw new Exception('Prepare update failed: ' . $conn->error);
        $stmt->bind_param($types, ...$values);
        if (!$stmt->execute()) throw new Exception('Failed to update customer: ' . $stmt->error);
        $affected = $stmt->affected_rows;
        $stmt->close();

        if ($customer_group !== '') {
            mergedEnsureCustomerGroupMaster($conn, $customer_group, $target_branch_id, $user_id);
        }

        $conn->commit();
        echo json_encode(['success' => true, 'message' => 'Customer updated successfully', 'affected_rows' => $affected]);
        exit;
    } catch (Throwable $e) {
        if (isset($conn) && $conn instanceof mysqli) {
            @$conn->rollback();
        }
        http_response_code(200);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

// ===== APPROVE CREDIT REQUESTS TAB LOGIC =====
// Enable error logging
ini_set('log_errors', 1);
ini_set('error_log', '../logs/approve_credit_errors.log');

// Check if credit_discount_requests table exists
$credit_requests_table_exists = false;
$check_credit_requests_table = $conn->query("SHOW TABLES LIKE 'credit_discount_requests'");
if ($check_credit_requests_table && $check_credit_requests_table->num_rows > 0) {
    $credit_requests_table_exists = true;
}

// ------------------------------------------------------------
// Check if attachments table exists
// ------------------------------------------------------------
$attachments_table_exists = false;
$check_attachments_table = $conn->query("SHOW TABLES LIKE 'credit_discount_attachments'");
if ($check_attachments_table && $check_attachments_table->num_rows > 0) {
    $attachments_table_exists = true;
}

// Check if branch_id column exists in customers table
$customers_branch_column_exists = false;
$check_customers_column = $conn->query("SHOW COLUMNS FROM customers LIKE 'branch_id'");
if ($check_customers_column && $check_customers_column->num_rows > 0) {
    $customers_branch_column_exists = true;
}

// Check if approved_by column exists and is valid foreign key
$approved_by_column_exists = false;
$check_approved_by = $conn->query("SHOW COLUMNS FROM credit_discount_requests LIKE 'approved_by'");
if ($check_approved_by && $check_approved_by->num_rows > 0) {
    $approved_by_column_exists = true;
}

// Check if effective_from and effective_until columns exist
$effective_columns_exist = false;
$check_effective_from = $conn->query("SHOW COLUMNS FROM credit_discount_requests LIKE 'effective_from'");
$check_effective_until = $conn->query("SHOW COLUMNS FROM credit_discount_requests LIKE 'effective_until'");
if ($check_effective_from && $check_effective_from->num_rows > 0 && 
    $check_effective_until && $check_effective_until->num_rows > 0) {
    $effective_columns_exist = true;
}

// Get base64 encoded logo for printing
$logo_path = '../Pictures/amgc3DLogo.png';
$logo_base64 = '';
if (file_exists($logo_path)) {
    $image_data = file_get_contents($logo_path);
    $logo_base64 = 'data:image/png;base64,' . base64_encode($image_data);
}

// Determine branch filter condition for customers
$branch_condition = "";
if ($customers_branch_column_exists && !$view_all_branches && $branch_id > 0) {
    $branch_condition = "AND c.branch_id = $branch_id";
}

// ========== HANDLE AJAX REQUESTS ==========
$credit_ajax_actions = ['approve_request', 'reject_request', 'view_request'];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && in_array($_POST['action'], $credit_ajax_actions, true)) {
    header('Content-Type: application/json');
    
    try {
        // Start transaction only for approve/reject. View request is read-only.
        if (in_array($_POST['action'], ['approve_request', 'reject_request'], true)) {
            $conn->begin_transaction();
        }
        
        // APPROVE REQUEST - WITH EXPIRATION DAYS
        if ($_POST['action'] === 'approve_request') {
            $request_id = (int)$_POST['request_id'];
            $admin_notes = trim($_POST['admin_notes'] ?? '');
            $expiration_days = (int)($_POST['expiration_days'] ?? 30); // Default 30 days
            
            // Validate expiration days
            if ($expiration_days <= 0) {
                $expiration_days = 30;
            }
            
            // Calculate effective from (now) and effective until (now + expiration days)
            $effective_from = date('Y-m-d H:i:s');
            $effective_until = date('Y-m-d H:i:s', strtotime("+{$expiration_days} days"));
            
            error_log("========== APPROVE REQUEST ATTEMPT ==========");
            error_log("Timestamp: " . date('Y-m-d H:i:s'));
            error_log("Request ID: " . $request_id);
            error_log("Admin User ID: " . $user_id);
            error_log("Expiration Days: " . $expiration_days);
            error_log("Effective From: " . $effective_from);
            error_log("Effective Until: " . $effective_until);
            
            // First, check if the request exists and get its current status
            $check_sql = "SELECT r.request_id, r.status, r.customer_id, r.request_type,
                                 r.requested_credit_limit, r.requested_discount_percent, r.credit_terms_days,
                                 c.customer_name, c.branch_id as customer_branch_id
                          FROM credit_discount_requests r
                          LEFT JOIN customers c ON r.customer_id = c.customer_id
                          WHERE r.request_id = ?";
            
            $check_stmt = $conn->prepare($check_sql);
            if (!$check_stmt) {
                throw new Exception("Prepare check failed: " . $conn->error);
            }
            
            $check_stmt->bind_param("i", $request_id);
            if (!$check_stmt->execute()) {
                throw new Exception("Execute check failed: " . $check_stmt->error);
            }
            
            $check_result = $check_stmt->get_result();
            
            if ($check_result->num_rows === 0) {
                throw new Exception("Request ID $request_id not found in database");
            }
            
            $request_data = $check_result->fetch_assoc();
            error_log("Found Request - Current Status: " . $request_data['status']);
            error_log("Customer: " . $request_data['customer_name']);
            
            // Verify branch access if not admin
            if (!$view_all_branches && $customers_branch_column_exists) {
                if (!isset($request_data['customer_branch_id']) || $request_data['customer_branch_id'] != $branch_id) {
                    throw new Exception("Access denied: This customer belongs to a different branch");
                }
            }
            
            // Check if already approved
            if ($request_data['status'] === 'approved') {
                echo json_encode([
                    'success' => true,
                    'message' => 'Request was already approved',
                    'already_approved' => true
                ]);
                $conn->commit();
                exit;
            }
            
            // Update with effective dates
            if ($effective_columns_exist && $approved_by_column_exists) {
                $update_sql = "UPDATE credit_discount_requests 
                               SET status = 'approved', 
                                   admin_notes = ?,
                                   effective_from = ?,
                                   effective_until = ?,
                                   approved_at = NOW(), 
                                   approved_by = ? 
                               WHERE request_id = ?";
                
                error_log("Using update with effective dates");
                error_log("SQL: " . $update_sql);
                
                $update_stmt = $conn->prepare($update_sql);
                if (!$update_stmt) {
                    throw new Exception("Prepare update failed: " . $conn->error);
                }
                
                $update_stmt->bind_param("sssii", $admin_notes, $effective_from, $effective_until, $user_id, $request_id);
                
            } elseif ($effective_columns_exist) {
                // Without approved_by column
                $update_sql = "UPDATE credit_discount_requests 
                               SET status = 'approved', 
                                   admin_notes = ?,
                                   effective_from = ?,
                                   effective_until = ?,
                                   approved_at = NOW()
                               WHERE request_id = ?";
                
                error_log("Using update without approved_by column");
                
                $update_stmt = $conn->prepare($update_sql);
                if (!$update_stmt) {
                    throw new Exception("Prepare update failed: " . $conn->error);
                }
                
                $update_stmt->bind_param("sssi", $admin_notes, $effective_from, $effective_until, $request_id);
                
            } elseif ($approved_by_column_exists) {
                // Without effective dates
                $update_sql = "UPDATE credit_discount_requests 
                               SET status = 'approved', 
                                   admin_notes = ?,
                                   approved_at = NOW(), 
                                   approved_by = ? 
                               WHERE request_id = ?";
                
                error_log("Using update without effective dates");
                
                $update_stmt = $conn->prepare($update_sql);
                if (!$update_stmt) {
                    throw new Exception("Prepare update failed: " . $conn->error);
                }
                
                $update_stmt->bind_param("sii", $admin_notes, $user_id, $request_id);
                
            } else {
                // Fallback
                $update_sql = "UPDATE credit_discount_requests 
                               SET status = 'approved', 
                                   admin_notes = ?,
                                   approved_at = NOW()
                               WHERE request_id = ?";
                
                error_log("Using fallback update");
                
                $update_stmt = $conn->prepare($update_sql);
                if (!$update_stmt) {
                    throw new Exception("Prepare update failed: " . $conn->error);
                }
                
                $update_stmt->bind_param("si", $admin_notes, $request_id);
            }
            
            if (!$update_stmt->execute()) {
                throw new Exception("Execute update failed: " . $update_stmt->error);
            }
            
            $affected_rows = $update_stmt->affected_rows;
            error_log("Affected rows: " . $affected_rows);
            
            // Commit the transaction
            $conn->commit();
            
            error_log("========== APPROVE REQUEST SUCCESS ==========");
            
            echo json_encode([
                'success' => true,
                'message' => 'Request approved successfully. Effective until: ' . date('M d, Y', strtotime($effective_until)),
                'affected_rows' => $affected_rows,
                'request_id' => $request_id,
                'new_status' => 'approved',
                'effective_until' => $effective_until
            ]);
            exit;
        }
        
        // REJECT REQUEST
        elseif ($_POST['action'] === 'reject_request') {
            $request_id = (int)$_POST['request_id'];
            $rejection_reason = trim($_POST['rejection_reason'] ?? '');
            
            error_log("========== REJECT REQUEST ATTEMPT ==========");
            error_log("Request ID: " . $request_id);
            error_log("Rejection Reason: " . $rejection_reason);
            
            // Check if request exists
            $check_sql = "SELECT request_id, status FROM credit_discount_requests WHERE request_id = ?";
            $check_stmt = $conn->prepare($check_sql);
            $check_stmt->bind_param("i", $request_id);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            
            if ($check_result->num_rows === 0) {
                throw new Exception("Request not found");
            }
            
            $request_data = $check_result->fetch_assoc();
            error_log("Current status: " . $request_data['status']);
            
            // Update to rejected
            if ($approved_by_column_exists) {
                $update_sql = "UPDATE credit_discount_requests 
                               SET status = 'rejected', 
                                   admin_notes = CONCAT(IFNULL(admin_notes,''), '\nRejected: ', ?),
                                   approved_at = NOW(), 
                                   approved_by = ? 
                               WHERE request_id = ?";
                $update_stmt = $conn->prepare($update_sql);
                $update_stmt->bind_param("sii", $rejection_reason, $user_id, $request_id);
            } else {
                $update_sql = "UPDATE credit_discount_requests 
                               SET status = 'rejected', 
                                   admin_notes = CONCAT(IFNULL(admin_notes,''), '\nRejected: ', ?),
                                   approved_at = NOW()
                               WHERE request_id = ?";
                $update_stmt = $conn->prepare($update_sql);
                $update_stmt->bind_param("si", $rejection_reason, $request_id);
            }
            
            if (!$update_stmt->execute()) {
                throw new Exception("Failed to reject request: " . $update_stmt->error);
            }
            
            $conn->commit();
            
            echo json_encode([
                'success' => true,
                'message' => 'Request rejected successfully'
            ]);
            exit;
        }
        
        // VIEW REQUEST DETAILS
        elseif ($_POST['action'] === 'view_request') {
            $request_id = (int)$_POST['request_id'];
            
            $query = "SELECT r.*, c.customer_name, c.customer_code, c.email, c.phone_number,
                             u.first_name, u.last_name, u.email as agent_email
                      FROM credit_discount_requests r
                      JOIN customers c ON r.customer_id = c.customer_id
                      JOIN users u ON r.agent_id = u.user_id
                      WHERE r.request_id = ?";
            
            if (!$view_all_branches && $customers_branch_column_exists) {
                $query .= " AND c.branch_id = ?";
                $stmt = $conn->prepare($query);
                $stmt->bind_param("ii", $request_id, $branch_id);
            } else {
                $stmt = $conn->prepare($query);
                $stmt->bind_param("i", $request_id);
            }
            
            $stmt->execute();
            $result = $stmt->get_result();
            $request = $result->fetch_assoc();
            
            if ($request) {
                // Get attachments for this request
                $attachments = [];
                if ($attachments_table_exists) {
                    $att_query = "SELECT * FROM credit_discount_attachments WHERE request_id = ? ORDER BY uploaded_at ASC";
                    $att_stmt = $conn->prepare($att_query);
                    $att_stmt->bind_param("i", $request_id);
                    $att_stmt->execute();
                    $att_result = $att_stmt->get_result();
                    while ($att = $att_result->fetch_assoc()) {
                        $attachments[] = $att;
                    }
                }
                
                echo json_encode([
                    'success' => true,
                    'request' => $request,
                    'attachments' => $attachments
                ]);
            } else {
                throw new Exception('Request not found');
            }
            exit;
        }
        
    } catch (Exception $e) {
        if (in_array($_POST['action'] ?? '', ['approve_request', 'reject_request'], true)) {
            @ $conn->rollback();
        }
        error_log("========== APPROVE REQUEST ERROR ==========");
        error_log("Error message: " . $e->getMessage());
        error_log("Error trace: " . $e->getTraceAsString());
        
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
        exit;
    }
}

// FETCH REQUESTS FROM DATABASE with branch filtering via customer
$requests_query = "
    SELECT 
        r.request_id,
        r.request_type,
        r.requested_credit_limit,
        r.requested_discount_percent,
        r.credit_terms_days,
        r.reason,
        r.status,
        r.admin_notes,
        r.created_at,
        r.updated_at,
        r.approved_at,
        r.effective_from,
        r.effective_until,
        c.customer_id,
        c.customer_name,
        c.customer_code,
        c.branch_id as customer_branch_id,
        CONCAT(u.first_name, ' ', u.last_name) as agent_name,
        u.email as agent_email
    FROM credit_discount_requests r
    JOIN customers c ON r.customer_id = c.customer_id
    LEFT JOIN users u ON r.agent_id = u.user_id
    WHERE 1=1
    $branch_condition
    ORDER BY r.created_at DESC, r.request_id DESC
";

$requests_result = $conn->query($requests_query);
if (!$requests_result) {
    $requests = [];
    error_log("Credit Discount Requests Query Error: " . $conn->error);
} else {
    $requests = $requests_result->fetch_all(MYSQLI_ASSOC);
}

// Get attachments for all requests
$attachments_by_request = [];
if ($attachments_table_exists && !empty($requests)) {
    $all_request_ids = array_column($requests, 'request_id');
    if (!empty($all_request_ids)) {
        $ids_string = implode(',', $all_request_ids);
        $attachment_query = "SELECT * FROM credit_discount_attachments WHERE request_id IN ($ids_string) ORDER BY uploaded_at ASC";
        $attachments_result = $conn->query($attachment_query);
        if ($attachments_result) {
            while ($attachment = $attachments_result->fetch_assoc()) {
                $attachments_by_request[$attachment['request_id']][] = $attachment;
            }
        }
    }
}

// CALCULATE STATISTICS
$total_requests = count($requests);
$pending_requests = count(array_filter($requests, fn($r) => $r['status'] === 'pending'));
$approved_requests = count(array_filter($requests, fn($r) => $r['status'] === 'approved'));
$rejected_requests = count(array_filter($requests, fn($r) => $r['status'] === 'rejected'));

// Helper functions
function getRequestStatusClass($status) {
    return match($status) {
        'pending' => 'status-pending',
        'approved' => 'status-approved',
        'rejected' => 'status-rejected',
        default => 'status-pending'
    };
}

function getRequestStatusText($status) {
    return match($status) {
        'pending' => 'Pending',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
        default => ucfirst($status)
    };
}

function getRequestTypeText($type) {
    return match($type) {
        'credit' => 'Credit Limit',
        'discount' => 'Discount',
        'both' => 'Credit & Discount',
        'credit_terms' => 'Credit Terms',
        default => ucfirst($type)
    };
}

function creditFormatDate($dateStr) {
    if (!$dateStr) return '';
    $date = new DateTime($dateStr);
    return $date->format('M d, Y H:i');
}

function isRequestExpired($effective_until) {
    if (!$effective_until) return false;
    return strtotime($effective_until) < time();
}


// FAST AJAX UPDATE HANDLER
// This runs before page-only queries like preview code and walk-in checks.
// It prevents the Edit Customer modal from getting stuck on Processing.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_customer_fast') {
    header('Content-Type: application/json');
    @set_time_limit(15);
    @$conn->query("SET SESSION innodb_lock_wait_timeout = 5");

    try {
        if (!$customer_group_column_exists) {
            throw new Exception('Missing database column: customer_group. Please run fix_customer_group_column.sql once.');
        }

        $customer_id = intval($_POST['customer_id'] ?? 0);
        $customer_name = trim($_POST['customer_name'] ?? '');
        $contact_person = trim($_POST['contact_person'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone_number'] ?? '');
        $price_level = trim($_POST['price_level'] ?? 'Standard');
        $region = trim($_POST['region'] ?? '');
        $province = trim($_POST['province'] ?? '');
        $city = trim($_POST['city'] ?? '');
        $city_code = trim($_POST['city_code'] ?? '');
        $barangay = trim($_POST['barangay'] ?? '');
        $store_name = trim($_POST['store_name'] ?? '');
        $customer_group = trim($_POST['customer_group'] ?? '');
        $status = trim($_POST['status'] ?? 'active');

        if ($customer_id <= 0) {
            throw new Exception('Invalid customer ID.');
        }
        if ($customer_name === '') {
            throw new Exception('Customer name is required.');
        }
        if (!in_array($status, ['active', 'pending', 'inactive'], true)) {
            $status = 'active';
        }

        $store_image = trim($_POST['existing_store_image'] ?? '');
        if (isset($_FILES['store_image']) && $_FILES['store_image']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = '../uploads/store_images/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            $file_name = $_FILES['store_image']['name'];
            $file_tmp = $_FILES['store_image']['tmp_name'];
            $file_size = $_FILES['store_image']['size'];
            $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
            $allowed_ext = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            if (!in_array($file_ext, $allowed_ext, true)) {
                throw new Exception('Invalid store image type.');
            }
            if ($file_size > 5242880) {
                throw new Exception('Store image must not exceed 5MB.');
            }
            $new_file_name = 'store_' . uniqid('', true) . '.' . $file_ext;
            if (!move_uploaded_file($file_tmp, $upload_dir . $new_file_name)) {
                throw new Exception('Failed to upload store image.');
            }
            if ($store_image !== '' && file_exists($upload_dir . $store_image)) {
                @unlink($upload_dir . $store_image);
            }
            $store_image = $new_file_name;
        } else {
            $old_img_stmt = $conn->prepare("SELECT store_image FROM customers WHERE customer_id = ? LIMIT 1");
            if ($old_img_stmt) {
                $old_img_stmt->bind_param('i', $customer_id);
                $old_img_stmt->execute();
                $old_img_result = $old_img_stmt->get_result();
                if ($old_img_row = $old_img_result->fetch_assoc()) {
                    $store_image = $old_img_row['store_image'] ?? $store_image;
                }
                $old_img_stmt->close();
            }
        }

        $address_parts = [];
        if ($barangay !== '') $address_parts[] = $barangay;
        if ($city !== '') $address_parts[] = $city;
        if ($province !== '') $address_parts[] = $province;
        if ($region !== '') $address_parts[] = $region;
        $address = implode(', ', $address_parts);

        $update_sql = "UPDATE customers SET
            customer_name = ?, contact_person = ?, email = ?, phone_number = ?, address = ?,
            region = ?, province = ?, city = ?, barangay = ?, price_level = ?,
            store_name = ?, customer_group = ?, store_image = ?, city_code = ?, status = ?,
            updated_at = NOW()
            WHERE customer_id = ?";
        $stmt = $conn->prepare($update_sql);
        if (!$stmt) {
            throw new Exception('Prepare failed: ' . $conn->error);
        }
        $stmt->bind_param(
            'sssssssssssssssi',
            $customer_name, $contact_person, $email, $phone, $address,
            $region, $province, $city, $barangay, $price_level,
            $store_name, $customer_group, $store_image, $city_code, $status, $customer_id
        );
        if (!$stmt->execute()) {
            throw new Exception('Failed to update customer: ' . $stmt->error);
        }
        $stmt->close();

        echo json_encode(['success' => true, 'message' => 'Customer updated successfully']);
        exit;
    } catch (Throwable $e) {
        http_response_code(200);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
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

// Function to generate unique customer code
function generateCustomerCode($conn) {
    $prefix = 'CUST-';
    $year = date('Y');
    $month = date('m');
    
    $query = "SELECT customer_code FROM customers 
              WHERE customer_code LIKE '$prefix$year$month%' 
              ORDER BY customer_code DESC LIMIT 1";
    $result = $conn->query($query);
    
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $last_code = $row['customer_code'];
        $sequence = intval(substr($last_code, -4)) + 1;
    } else {
        $sequence = 1;
    }
    
    return $prefix . $year . $month . '-' . str_pad($sequence, 4, '0', STR_PAD_LEFT);
}

// Generate a preview code for the modal
$preview_code = generateCustomerCode($conn);

// Check if walk-in customer exists for current branch, if not create it
function ensureWalkinCustomer($conn, $branch_id, $user_id) {
    if ($branch_id <= 0) return;
    
    $check_walkin = $conn->prepare("SELECT customer_id FROM customers WHERE customer_code = 'WALKIN-001' AND branch_id = ? LIMIT 1");
    $check_walkin->bind_param("i", $branch_id);
    $check_walkin->execute();
    $result = $check_walkin->get_result();
    
    if ($result->num_rows === 0) {
        $insert_walkin = $conn->prepare("INSERT INTO customers (customer_code, customer_name, email, phone_number, address, status, branch_id, created_by) 
                                         VALUES ('WALKIN-001', 'Walk-in Customer', 'walkin@example.com', 'N/A', 'Walk-in Customer - No fixed address', 'active', ?, ?)");
        $insert_walkin->bind_param("ii", $branch_id, $user_id);
        $insert_walkin->execute();
        $insert_walkin->close();
    }
    $check_walkin->close();
}

ensureWalkinCustomer($conn, $branch_id, $user_id);

// ========== HANDLE AJAX REQUESTS ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    $write_actions = ['add_customer', 'delete_customer', 'update_customer_group', 'create_customer_group'];
    $is_write_action = in_array($_POST['action'], $write_actions, true);
    
    try {
        if ($is_write_action) {
            $conn->begin_transaction();
        }
        
        // ADD CUSTOMER (based on customer.php but without latitude/longitude requirement)
        if ($_POST['action'] === 'add_customer') {
            $customer_code = trim($_POST['customer_code']);
            $customer_name = trim($_POST['customer_name']);
            $contact_person = trim($_POST['contact_person'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $phone = trim($_POST['phone_number'] ?? '');
            $price_level = trim($_POST['price_level'] ?? 'Standard');
            $region = trim($_POST['region'] ?? '');
            $province = trim($_POST['province'] ?? '');
            $city = trim($_POST['city'] ?? '');
            $city_code = trim($_POST['city_code'] ?? '');
            $barangay = trim($_POST['barangay'] ?? '');
            $store_name = trim($_POST['store_name'] ?? '');
            $customer_group = trim($_POST['customer_group'] ?? '');
            $status = 'active';
            $store_image = '';
            $latitude = null;   // IDAGDAG ITO
            $longitude = null;  // IDAGDAG ITO
            
            // Handle store image upload
            if (isset($_FILES['store_image']) && $_FILES['store_image']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = '../uploads/store_images/';
                
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }
                
                $file_name = $_FILES['store_image']['name'];
                $file_tmp = $_FILES['store_image']['tmp_name'];
                $file_size = $_FILES['store_image']['size'];
                $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
                $allowed_ext = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                
                if (in_array($file_ext, $allowed_ext) && $file_size <= 5242880) {
                    $new_file_name = 'store_' . uniqid() . '.' . $file_ext;
                    $upload_path = $upload_dir . $new_file_name;
                    if (move_uploaded_file($file_tmp, $upload_path)) {
                        $store_image = $new_file_name;
                    }
                }
            }
            
            // Combine address components
    $address_parts = [];
    if (!empty($barangay)) $address_parts[] = $barangay;
    if (!empty($city)) $address_parts[] = $city;
    if (!empty($province)) $address_parts[] = $province;
    if (!empty($region)) $address_parts[] = $region;
    $address = implode(', ', $address_parts);
    
    if (empty($customer_name)) {
        throw new Exception('Customer name is required');
    }
    
    $target_branch_id = null;
    if ($customers_branch_column_exists) {
        if (!$view_all_branches && $branch_id > 0) {
            $target_branch_id = $branch_id;
        } elseif ($view_all_branches && isset($_POST['branch_id']) && !empty($_POST['branch_id'])) {
            $target_branch_id = intval($_POST['branch_id']);
        }
    }
    
    // INSERT statement - may latitude at longitude
    $insert_sql = "INSERT INTO customers (
        customer_code, customer_name, contact_person, email, phone_number, address,
        region, province, city, barangay, price_level, store_name, customer_group, store_image, 
        city_code, latitude, longitude, status, branch_id, created_by
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    
    $stmt = $conn->prepare($insert_sql);
    $stmt->bind_param(
        "ssssssssssssssssssii",
        $customer_code, $customer_name, $contact_person, $email, $phone, $address,
        $region, $province, $city, $barangay, $price_level, $store_name, $customer_group, $store_image,
        $city_code, $latitude, $longitude, $status, $target_branch_id, $user_id
    );
    
    if (!$stmt->execute()) {
        throw new Exception('Failed to add customer: ' . $stmt->error);
    }
    
    $conn->commit();
    echo json_encode(['success' => true, 'message' => 'Customer added successfully']);
    exit;
}
        // UPDATE CUSTOMER
        elseif ($_POST['action'] === 'update_customer') {
            $customer_id = intval($_POST['customer_id']);
            $customer_name = trim($_POST['customer_name']);
            $contact_person = trim($_POST['contact_person'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $phone = trim($_POST['phone_number'] ?? '');
            $price_level = trim($_POST['price_level'] ?? 'Standard');
            $region = trim($_POST['region'] ?? '');
            $province = trim($_POST['province'] ?? '');
            $city = trim($_POST['city'] ?? '');
            $city_code = trim($_POST['city_code'] ?? '');
            $barangay = trim($_POST['barangay'] ?? '');
            $store_name = trim($_POST['store_name'] ?? '');
            $customer_group = trim($_POST['customer_group'] ?? '');
            $status = trim($_POST['status'] ?? 'active');
            if (!in_array($status, ['active', 'inactive', 'suspended'], true)) {
                $status = 'active';
            }
            $store_image = isset($_POST['existing_store_image']) ? trim($_POST['existing_store_image']) : '';
            
            // Handle store image upload
            if (isset($_FILES['store_image']) && $_FILES['store_image']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = '../uploads/store_images/';
                
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }
                
                $file_name = $_FILES['store_image']['name'];
                $file_tmp = $_FILES['store_image']['tmp_name'];
                $file_size = $_FILES['store_image']['size'];
                $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
                $allowed_ext = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                
                if (in_array($file_ext, $allowed_ext) && $file_size <= 5242880) {
                    $new_file_name = 'store_' . uniqid() . '.' . $file_ext;
                    $upload_path = $upload_dir . $new_file_name;
                    if (move_uploaded_file($file_tmp, $upload_path)) {
                        // Delete old image if exists
                        if (!empty($store_image) && file_exists($upload_dir . $store_image)) {
                            unlink($upload_dir . $store_image);
                        }
                        $store_image = $new_file_name;
                    }
                }
            } else {
                // Get existing image from database if not uploaded
                $old_img_query = "SELECT store_image FROM customers WHERE customer_id = ?";
                $old_img_stmt = $conn->prepare($old_img_query);
                $old_img_stmt->bind_param('i', $customer_id);
                $old_img_stmt->execute();
                $old_img_result = $old_img_stmt->get_result();
                if ($old_img_row = $old_img_result->fetch_assoc()) {
                    $store_image = $old_img_row['store_image'] ?? '';
                }
            }
            
            // Combine address components
            $address_parts = [];
            if (!empty($barangay)) $address_parts[] = $barangay;
            if (!empty($city)) $address_parts[] = $city;
            if (!empty($province)) $address_parts[] = $province;
            if (!empty($region)) $address_parts[] = $region;
            $address = implode(', ', $address_parts);
            
            if (empty($customer_name)) {
                throw new Exception('Customer name is required');
            }

            $customer_group_column_check = $conn->query("SHOW COLUMNS FROM customers LIKE 'customer_group'");
            if (!$customer_group_column_check || $customer_group_column_check->num_rows === 0) {
                throw new Exception('Missing database column: customer_group. Please run fix_customer_group_column.sql once.');
            }
            
            $update_sql = "UPDATE customers SET 
                customer_name = ?, contact_person = ?, email = ?, phone_number = ?, address = ?,
                region = ?, province = ?, city = ?, barangay = ?, price_level = ?,
                store_name = ?, customer_group = ?, store_image = ?, city_code = ?, status = ?,
                updated_at = NOW()
                WHERE customer_id = ?";
            
            $stmt = $conn->prepare($update_sql);
            $stmt->bind_param(
                "sssssssssssssssi",
                $customer_name, $contact_person, $email, $phone, $address,
                $region, $province, $city, $barangay, $price_level,
                $store_name, $customer_group, $store_image, $city_code, $status, $customer_id
            );
            
            if (!$stmt->execute()) {
                throw new Exception('Failed to update customer: ' . $stmt->error);
            }
            
            echo json_encode(['success' => true, 'message' => 'Customer updated successfully']);
            exit;
        }
        

        // CREATE CUSTOMER GROUP NAME
        elseif ($_POST['action'] === 'create_customer_group') {
            if (!$customer_group_column_exists) {
                throw new Exception('Missing database column: customer_group. Please run fix_customer_group_column.sql once.');
            }

            if (!$customer_groups_master_table_exists) {
                throw new Exception('Customer group master table is missing. Please refresh the page and try again.');
            }

            $new_group = trim($_POST['new_group'] ?? '');

            if ($new_group === '') {
                throw new Exception('Customer group name is required.');
            }

            if (mb_strlen($new_group, 'UTF-8') > 100) {
                throw new Exception('Customer group name must not exceed 100 characters.');
            }

            $target_group_branch_id = (!$view_all_branches && $customers_branch_column_exists && $branch_id > 0) ? (int)$branch_id : null;

            $duplicate_sql = "SELECT group_id FROM customer_groups_master WHERE LOWER(TRIM(group_name)) = LOWER(TRIM(?))";
            $duplicate_types = "s";
            $duplicate_params = [$new_group];
            if ($target_group_branch_id !== null) {
                $duplicate_sql .= " AND (branch_id = ? OR branch_id IS NULL OR branch_id = 0)";
                $duplicate_types .= "i";
                $duplicate_params[] = $target_group_branch_id;
            }
            $duplicate_sql .= " LIMIT 1";

            $duplicate_stmt = $conn->prepare($duplicate_sql);
            if (!$duplicate_stmt) {
                throw new Exception('Prepare failed: ' . $conn->error);
            }
            $duplicate_stmt->bind_param($duplicate_types, ...$duplicate_params);
            $duplicate_stmt->execute();
            $duplicate_result = $duplicate_stmt->get_result();
            $already_exists = $duplicate_result && $duplicate_result->num_rows > 0;
            $duplicate_stmt->close();

            if ($already_exists) {
                throw new Exception('Customer group already exists.');
            }

            $insert_group_sql = "INSERT INTO customer_groups_master (group_name, branch_id, created_by) VALUES (?, ?, ?)";
            $insert_group_stmt = $conn->prepare($insert_group_sql);
            if (!$insert_group_stmt) {
                throw new Exception('Prepare failed: ' . $conn->error);
            }
            $insert_group_stmt->bind_param('sii', $new_group, $target_group_branch_id, $user_id);
            if (!$insert_group_stmt->execute()) {
                throw new Exception('Failed to create customer group: ' . $insert_group_stmt->error);
            }
            $insert_group_stmt->close();

            $conn->commit();
            echo json_encode([
                'success' => true,
                'message' => 'Customer group added successfully.',
                'group_name' => $new_group
            ]);
            exit;
        }

        // UPDATE CUSTOMER GROUP NAME
        elseif ($_POST['action'] === 'update_customer_group') {
            if (!$customer_group_column_exists) {
                throw new Exception('Missing database column: customer_group. Please run fix_customer_group_column.sql once.');
            }

            $old_group = trim($_POST['old_group'] ?? '');
            $new_group = trim($_POST['new_group'] ?? '');

            if ($old_group === '') {
                throw new Exception('Original customer group is required.');
            }

            if ($new_group === '') {
                throw new Exception('New customer group name is required.');
            }

            if (mb_strlen($new_group, 'UTF-8') > 100) {
                throw new Exception('Customer group name must not exceed 100 characters.');
            }

            if (mb_strtolower($old_group, 'UTF-8') === mb_strtolower($new_group, 'UTF-8')) {
                $conn->commit();
                echo json_encode(['success' => true, 'message' => 'Customer group is already updated.']);
                exit;
            }

            $scope_sql = "";
            $check_types = "s";
            $check_params = [$old_group];

            if (!$view_all_branches && $customers_branch_column_exists && $branch_id > 0) {
                $scope_sql = " AND (branch_id = ? OR branch_id IS NULL OR branch_id = 0)";
                $check_types .= "i";
                $check_params[] = (int)$branch_id;
            }

            $check_sql = "SELECT COUNT(*) AS group_count FROM customers WHERE TRIM(customer_group) = ?" . $scope_sql;
            $check_stmt = $conn->prepare($check_sql);
            if (!$check_stmt) {
                throw new Exception('Prepare failed: ' . $conn->error);
            }
            $check_stmt->bind_param($check_types, ...$check_params);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            $group_count = (int)($check_result->fetch_assoc()['group_count'] ?? 0);
            $check_stmt->close();

            if ($group_count <= 0) {
                throw new Exception('Customer group not found.');
            }

            $update_types = "ss";
            $update_params = [$new_group, $old_group];

            if (!$view_all_branches && $customers_branch_column_exists && $branch_id > 0) {
                $update_types .= "i";
                $update_params[] = (int)$branch_id;
            }

            $update_group_sql = "UPDATE customers
                                 SET customer_group = ?, updated_at = NOW()
                                 WHERE TRIM(customer_group) = ?" . $scope_sql;
            $group_stmt = $conn->prepare($update_group_sql);
            if (!$group_stmt) {
                throw new Exception('Prepare failed: ' . $conn->error);
            }
            $group_stmt->bind_param($update_types, ...$update_params);
            if (!$group_stmt->execute()) {
                throw new Exception('Failed to update customer group: ' . $group_stmt->error);
            }

            $affected_rows = $group_stmt->affected_rows;
            $group_stmt->close();

            if ($customer_groups_master_table_exists) {
                $master_scope_sql = "";
                $master_types = "ss";
                $master_params = [$new_group, $old_group];
                if (!$view_all_branches && $customers_branch_column_exists && $branch_id > 0) {
                    $master_scope_sql = " AND (branch_id = ? OR branch_id IS NULL OR branch_id = 0)";
                    $master_types .= "i";
                    $master_params[] = (int)$branch_id;
                }
                $master_update_sql = "UPDATE customer_groups_master SET group_name = ?, updated_at = NOW() WHERE TRIM(group_name) = ?" . $master_scope_sql;
                $master_update_stmt = $conn->prepare($master_update_sql);
                if ($master_update_stmt) {
                    $master_update_stmt->bind_param($master_types, ...$master_params);
                    $master_update_stmt->execute();
                    $master_update_stmt->close();
                }
            }

            $conn->commit();
            echo json_encode([
                'success' => true,
                'message' => 'Customer group updated successfully.',
                'updated_customers' => $affected_rows
            ]);
            exit;
        }

        // DELETE CUSTOMER
        elseif ($_POST['action'] === 'delete_customer') {
            $customer_id = intval($_POST['customer_id']);
            
            $check_orders = $conn->prepare("SELECT COUNT(*) as order_count FROM sales_orders WHERE customer_id = ?");
            $check_orders->bind_param("i", $customer_id);
            $check_orders->execute();
            $order_result = $check_orders->get_result();
            $order_count = $order_result->fetch_assoc()['order_count'];
            $check_orders->close();
            
            if ($order_count > 0) {
                $stmt = $conn->prepare("UPDATE customers SET status = 'inactive' WHERE customer_id = ?");
                $stmt->bind_param("i", $customer_id);
                $message = "Customer has existing sales orders. Status changed to inactive instead.";
            } else {
                $stmt = $conn->prepare("DELETE FROM customers WHERE customer_id = ?");
                $stmt->bind_param("i", $customer_id);
                $message = "Customer deleted successfully";
            }
            
            if (!$stmt->execute()) {
                throw new Exception('Failed to delete customer: ' . $stmt->error);
            }
            
            $conn->commit();
            echo json_encode(['success' => true, 'message' => $message]);
            exit;
        }
        
        // GET CUSTOMER DETAILS
        elseif ($_POST['action'] === 'get_customer') {
            $customer_id = intval($_POST['customer_id']);
            
            $stmt = $conn->prepare("SELECT c.*, u.first_name, u.last_name, b.branch_name 
                                   FROM customers c
                                   LEFT JOIN users u ON c.created_by = u.user_id
                                   LEFT JOIN branches b ON c.branch_id = b.branch_id
                                   WHERE c.customer_id = ?");
            
            if (!$stmt) {
                echo json_encode(['success' => false, 'message' => 'Prepare failed: ' . $conn->error]);
                exit;
            }
            
            $stmt->bind_param("i", $customer_id);
            
            if (!$stmt->execute()) {
                echo json_encode(['success' => false, 'message' => 'Execute failed: ' . $stmt->error]);
                exit;
            }
            
            $result = $stmt->get_result();
            
            if ($row = $result->fetch_assoc()) {
                $row['created_by_name'] = ($row['first_name'] ? $row['first_name'] . ' ' . $row['last_name'] : 'System');

                // Same calculation as sales_order.php view modal
                $credit_snapshot = customerListGetCustomerCreditSnapshot($conn, $customer_id);
                $row['credit_limit'] = floatval($credit_snapshot['credit_limit'] ?? 0);
                $row['credit_used'] = floatval($credit_snapshot['credit_used'] ?? 0);
                $row['outstanding_balance'] = max(0, floatval($credit_snapshot['credit_used'] ?? 0));
                $row['has_credit_limit'] = (floatval($credit_snapshot['credit_limit'] ?? 0) > 0);
                $row['display_credit_used'] = $row['has_credit_limit'] ? max(0, floatval($credit_snapshot['credit_used'] ?? 0)) : 0;
                $row['is_over_limit'] = !empty($credit_snapshot['is_over_limit_now']);
                $row['invoice_history'] = customerListGetCustomerInvoiceHistory($conn, $customer_id, $branch_id, $view_all_branches);
                $row['total_oil_volume'] = customerListGetCustomerOilVolume($conn, $customer_id, $branch_id, $view_all_branches);

                echo json_encode(['success' => true, 'customer' => $row]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Customer not found']);
            }
            exit;
        }
        
        // GENERATE CUSTOMER CODE
        elseif ($_POST['action'] === 'generate_code') {
            $customer_code = generateCustomerCode($conn);
            echo json_encode(['success' => true, 'customer_code' => $customer_code]);
            exit;
        }
        
        // GET CUSTOMER ORDERS
        elseif ($_POST['action'] === 'get_customer_orders') {
            $customer_id = intval($_POST['customer_id']);
            
            $orders_sql = "SELECT so_id, so_number, si_number, order_date, total_amount, order_status
                          FROM sales_orders 
                          WHERE customer_id = ?
                          ORDER BY order_date DESC 
                          LIMIT 20";
            $orders_stmt = $conn->prepare($orders_sql);
            $orders_stmt->bind_param('i', $customer_id);
            $orders_stmt->execute();
            $orders_result = $orders_stmt->get_result();
            $orders = [];
            
            while ($order = $orders_result->fetch_assoc()) {
                $orders[] = $order;
            }
            
            echo json_encode([
                'success' => true,
                'orders' => $orders
            ]);
            exit;
        }
        
    } catch (Exception $e) {
        if (isset($is_write_action) && $is_write_action) {
            $conn->rollback();
        }
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

// FETCH CUSTOMERS
if ($view_all_branches) {
    $customers_query = "SELECT c.*, b.branch_name, 
                        (SELECT COUNT(*) FROM sales_orders WHERE customer_id = c.customer_id) as total_orders,
                        CASE WHEN c.customer_code = 'WALKIN-001' THEN 0 ELSE 1 END as sort_order
                        FROM customers c
                        LEFT JOIN branches b ON c.branch_id = b.branch_id
                        WHERE c.status = 'active'
                        ORDER BY sort_order ASC, c.customer_name ASC";
} else {
    $customers_query = "SELECT c.*, b.branch_name, 
                        (SELECT COUNT(*) FROM sales_orders WHERE customer_id = c.customer_id) as total_orders,
                        CASE WHEN c.customer_code = 'WALKIN-001' THEN 0 ELSE 1 END as sort_order
                        FROM customers c
                        LEFT JOIN branches b ON c.branch_id = b.branch_id
                        WHERE c.status = 'active'
                        AND (c.branch_id = " . intval($branch_id) . " OR c.branch_id IS NULL OR c.branch_id = 0)
                        ORDER BY sort_order ASC, c.customer_name ASC";
}
$customers_result = $conn->query($customers_query);
$customers = $customers_result ? $customers_result->fetch_all(MYSQLI_ASSOC) : [];

// FETCH EXISTING CUSTOMER GROUPS FOR TYPE-AHEAD DROPDOWN
$customer_groups = [];
$customer_group_map = [];

$addCustomerGroupToMap = function($groupName) use (&$customer_group_map) {
    $raw_group = trim((string)$groupName);
    if ($raw_group !== '') {
        $normalized_group_key = mb_strtolower($raw_group, 'UTF-8');
        if (!isset($customer_group_map[$normalized_group_key])) {
            $customer_group_map[$normalized_group_key] = $raw_group;
        }
    }
};

if ($view_all_branches) {
    $groups_query = "SELECT DISTINCT customer_group FROM customers WHERE customer_group IS NOT NULL AND customer_group != '' ORDER BY customer_group ASC";
    $groups_result = $conn->query($groups_query);
} else {
    $groups_query = "SELECT DISTINCT customer_group FROM customers WHERE customer_group IS NOT NULL AND customer_group != '' AND (branch_id = ? OR branch_id IS NULL OR branch_id = 0) ORDER BY customer_group ASC";
    $groups_stmt = $conn->prepare($groups_query);
    $groups_stmt->bind_param('i', $branch_id);
    $groups_stmt->execute();
    $groups_result = $groups_stmt->get_result();
}
if ($groups_result) {
    while ($group_row = $groups_result->fetch_assoc()) {
        $addCustomerGroupToMap($group_row['customer_group'] ?? '');
    }
}

if ($customer_groups_master_table_exists) {
    if ($view_all_branches || !$customers_branch_column_exists || (int)$branch_id <= 0) {
        $master_groups_result = $conn->query("SELECT group_name FROM customer_groups_master WHERE group_name IS NOT NULL AND TRIM(group_name) != '' ORDER BY group_name ASC");
    } else {
        $master_groups_stmt = $conn->prepare("SELECT group_name FROM customer_groups_master WHERE group_name IS NOT NULL AND TRIM(group_name) != '' AND (branch_id = ? OR branch_id IS NULL OR branch_id = 0) ORDER BY group_name ASC");
        $master_groups_stmt->bind_param('i', $branch_id);
        $master_groups_stmt->execute();
        $master_groups_result = $master_groups_stmt->get_result();
    }
    if ($master_groups_result) {
        while ($master_group_row = $master_groups_result->fetch_assoc()) {
            $addCustomerGroupToMap($master_group_row['group_name'] ?? '');
        }
    }
}
$customer_groups = array_values($customer_group_map);
natcasesort($customer_groups);
$customer_groups = array_values($customer_groups);

$customer_group_counts = [];
if (!empty($customer_groups)) {
    if ($view_all_branches || !$customers_branch_column_exists || (int)$branch_id <= 0) {
        $counts_query = "SELECT TRIM(customer_group) AS customer_group, COUNT(*) AS customer_count
                         FROM customers
                         WHERE customer_group IS NOT NULL AND TRIM(customer_group) != ''
                         GROUP BY TRIM(customer_group)";
        $counts_result = $conn->query($counts_query);
    } else {
        $counts_query = "SELECT TRIM(customer_group) AS customer_group, COUNT(*) AS customer_count
                         FROM customers
                         WHERE customer_group IS NOT NULL AND TRIM(customer_group) != ''
                           AND (branch_id = ? OR branch_id IS NULL OR branch_id = 0)
                         GROUP BY TRIM(customer_group)";
        $counts_stmt = $conn->prepare($counts_query);
        $counts_stmt->bind_param('i', $branch_id);
        $counts_stmt->execute();
        $counts_result = $counts_stmt->get_result();
    }

    if ($counts_result) {
        while ($count_row = $counts_result->fetch_assoc()) {
            $count_group_name = trim($count_row['customer_group'] ?? '');
            if ($count_group_name !== '') {
                $customer_group_counts[mb_strtolower($count_group_name, 'UTF-8')] = (int)($count_row['customer_count'] ?? 0);
            }
        }
    }
}


// ============= HANDLE GET ORDER DETAILS (for modal) =============
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'get_order_details') {
    header('Content-Type: application/json');
    
    try {
        $order_id = (int)$_POST['order_id'];
        
        // Check permissions - branch admin can view orders from their branch
        $branch_id = $_SESSION['branch_id'] ?? 0;
        $view_all_branches = $_SESSION['view_all_branches'] ?? false;
        
        // Build query with proper permissions
        if ($view_all_branches) {
            $check_sql = "SELECT so_id FROM sales_orders WHERE so_id = ?";
            $check_stmt = $conn->prepare($check_sql);
            $check_stmt->bind_param('i', $order_id);
        } else {
            $check_sql = "SELECT so_id FROM sales_orders WHERE so_id = ? AND branch_id = ?";
            $check_stmt = $conn->prepare($check_sql);
            $check_stmt->bind_param('ii', $order_id, $branch_id);
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
                    COALESCE(so.order_amount, 0) AS order_amount,
                    COALESCE(so.discount_amount, 0) AS discount_amount,
                    COALESCE(so.total_discount_amount, 0) AS total_discount_amount,
                    COALESCE(so.discount_percent, 0) AS discount_percent,
                    COALESCE(so.discount_calculation_type, 'percentage') AS discount_calculation_type,
                    COALESCE(so.discount_based_amount, 0) AS discount_based_amount,
                    so.order_status,
                    so.branch_id,
                    so.customer_id,
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
                        COALESCE(soi.gross_price, soi.unit_price, 0) AS gross_price,
                        COALESCE(soi.discount_amount, 0) AS discount_amount,
                        COALESCE(soi.net_price, soi.unit_price, 0) AS net_price,
                        COALESCE(soi.order_amount, soi.line_total, soi.quantity_ordered * soi.unit_price, 0) AS order_amount,
                        COALESCE(soi.total_discount, 0) AS total_discount,
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
        
        // ===== GET ACTIVE DISCOUNT FOR THIS ORDER =====
$discount_percent = 0;

// Check if credit_discount_requests table exists
$check_table = $conn->query("SHOW TABLES LIKE 'credit_discount_requests'");
if ($check_table && $check_table->num_rows > 0) {
    $customer_id = $order['customer_id'] ?? 0;
    $order_date = $order['order_date'] ?? date('Y-m-d H:i:s');
    
    // I-print para sa debugging (temporary)
    error_log("Customer ID: " . $customer_id);
    error_log("Order Date: " . $order_date);
    
    $discount_query = "SELECT requested_discount_percent 
                       FROM credit_discount_requests 
                       WHERE customer_id = ? 
                       AND status = 'approved' 
                       AND request_type IN ('discount', 'both')
                       AND (effective_from IS NULL OR effective_from <= ?)
                       AND (effective_until IS NULL OR effective_until >= ?)
                       ORDER BY 
                           CASE WHEN effective_from IS NULL THEN 1 ELSE 0 END ASC,
                           effective_from DESC,
                           created_at DESC
                       LIMIT 1";
    
    $discount_stmt = $conn->prepare($discount_query);
    if ($discount_stmt) {
        $discount_stmt->bind_param("iss", $customer_id, $order_date, $order_date);
        $discount_stmt->execute();
        $discount_result = $discount_stmt->get_result();
        if ($discount_row = $discount_result->fetch_assoc()) {
            $discount_percent = floatval($discount_row['requested_discount_percent'] ?? 0);
            error_log("Found discount: " . $discount_percent . "%");
        } else {
            error_log("No discount found for customer_id: " . $customer_id);
        }
        $discount_stmt->close();
    }
}
        // ==============================================
        
        // Prepare the response
        $response = [
            'success' => true,
            'order' => $order,
            'items' => $items,
            'discount_percent' => $discount_percent
        ];
        
        echo json_encode($response);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}
// ============= HANDLE PRINT ORDER =============
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'print_order') {
    header('Content-Type: application/json');
    
    try {
        $so_id = (int)$_POST['so_id'];
        $branch_id = $_SESSION['branch_id'] ?? 0;
        $view_all_branches = $_SESSION['view_all_branches'] ?? false;
        
        // Verify order access
        if ($view_all_branches) {
            $check_sql = "SELECT so_id FROM sales_orders WHERE so_id = ?";
            $check_stmt = $conn->prepare($check_sql);
            $check_stmt->bind_param('i', $so_id);
        } else {
            $check_sql = "SELECT so_id FROM sales_orders WHERE so_id = ? AND branch_id = ?";
            $check_stmt = $conn->prepare($check_sql);
            $check_stmt->bind_param('ii', $so_id, $branch_id);
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


// Get price levels from database (for dropdown)
$price_levels = ['Standard', 'Premium', 'Wholesale'];
$check_price_levels = $conn->query("SELECT DISTINCT price_level FROM item_unit_pricing WHERE price_level IS NOT NULL AND price_level != ''");
if ($check_price_levels && $check_price_levels->num_rows > 0) {
    $price_levels = [];
    while ($row = $check_price_levels->fetch_assoc()) {
        $price_levels[] = $row['price_level'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Customer List - Branch Admin</title>
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
    <!-- Leaflet CSS for Maps -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <!-- SheetJS for Credit Requests Export -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <!-- SweetAlert2 -->
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
        <!-- Session Checker -->
    <script src="../js/session-checker.js"></script>
    <style>
        /* ===== CUSTOMER CARDS ===== */
        .customer-cards-container {
            display: grid;
            grid-template-columns: 1fr;
            gap: 0.75rem;
            padding: 0.5rem 0;
        }

        @media (min-width: 992px) {
            .customer-cards-container {
                grid-template-columns: repeat(3, 1fr);
                gap: 1rem;
            }
        }

        @media (min-width: 768px) and (max-width: 991px) {
            .customer-cards-container {
                grid-template-columns: repeat(2, 1fr);
                gap: 0.875rem;
            }
        }

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

        .status-active { background: #d1fae5; color: #065f46; }
        .status-inactive { background: #fee2e2; color: #991b1b; }
        .status-pending { background: #fed7aa; color: #92400e; }

        .customer-name {
            font-size: 1rem;
            font-weight: 700;
            color: #1f2937;
            margin-bottom: 0.25rem;
        }

        .customer-phone {
            font-size: 0.8rem;
            color: #4b5563;
            margin-bottom: 0.25rem;
        }

        .customer-address {
            font-size: 0.7rem;
            color: #9ca3af;
            margin-bottom: 0.5rem;
            line-height: 1.3;
        }

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

        .edit-btn { background: #f3e5f5; color: #7b1fa2; }
        .edit-btn:hover { background: #e1bee7; transform: scale(1.05); }
        .call-btn { background: #e3f2fd; color: #1976d2; }
        .call-btn:hover { background: #bbdefb; transform: scale(1.05); }
        .cart-btn { background: #fff3e0; color: #ed6c02; }
        .cart-btn:hover { background: #ffe0b2; transform: scale(1.05); }
        .delete-btn { background: #fee2e2; color: #dc2626; }
        .delete-btn:hover { background: #fecaca; transform: scale(1.05); }

        .icon-btn i {
            font-size: 0.8rem;
        }

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

        /* Filter Section */
        .filter-section {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
            margin-bottom: 25px;
            padding: 0;
            background: transparent;
            border-radius: 0;
        }

        .search-box {
            position: relative;
            flex: 1;
            min-width: 260px;
        }

        .search-box i {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #9ca3af;
            font-size: 1rem;
            pointer-events: none;
        }

        .search-box input {
            width: 100%;
            padding: 12px 16px 12px 42px;
            border: 1.5px solid #e5e7eb;
            border-radius: 12px;
            font-size: 0.9rem;
            background-color: #ffffff;
            transition: all 0.2s ease;
            outline: none;
            color: #1f2937;
        }

        .search-box input:focus {
            border-color: #059669;
            box-shadow: 0 0 0 3px rgba(5, 150, 105, 0.1);
        }

        .action-buttons-top {
            display: flex;
            gap: 12px;
            align-items: center;
        }

        .action-buttons-top .form-select {
            padding: 10px 32px 10px 16px;
            border: 1.5px solid #e5e7eb;
            border-radius: 12px;
            font-size: 0.9rem;
            background-color: #ffffff;
            color: #1f2937;
            font-weight: 500;
            cursor: pointer;
        }

        /* Add button */
        .add-button-wrapper {
            margin-bottom: 1.25rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 0.75rem;
            flex-wrap: wrap;
        }

        .add-button-left,
        .add-button-right {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            flex-wrap: wrap;
        }

        .add-button-left {
            justify-content: flex-start;
        }

        .add-button-right {
            justify-content: flex-end;
            margin-left: auto;
        }
        
        .btn-primary-custom {
            background: linear-gradient(135deg, #059669, #047857);
            border: none;
            border-radius: 10px;
            padding: 0.7rem 1.5rem;
            font-weight: 600;
            font-size: 0.95rem;
            color: white;
            box-shadow: 0 4px 8px rgba(5, 150, 105, 0.25);
            transition: all 0.3s ease;
        }
        
        .btn-primary-custom:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 18px rgba(5, 150, 105, 0.35);
            background: linear-gradient(135deg, #047857, #065f46);
        }
        
        .btn-primary-custom i {
            margin-right: 0.5rem;
        }

        @media (max-width: 768px) {
            .add-button-wrapper {
                margin-bottom: 1rem;
                justify-content: center;
                flex-direction: column;
                align-items: stretch;
            }
            .add-button-left,
            .add-button-right {
                width: 100%;
                justify-content: center;
                flex-direction: column;
                align-items: stretch;
                margin-left: 0;
            }
            .btn-primary-custom {
                width: 100%;
                padding: 0.6rem 1rem;
            }
            .filter-section {
                flex-direction: column;
                align-items: stretch;
            }
            .action-buttons-top {
                justify-content: center;
            }
        }

        /* Walk-in Customer Card Styling */
        .customer-card.walkin-card {
            background: linear-gradient(135deg, #fef9e6 0%, #fff8e6 100%);
            border-left: 4px solid #f59e0b;
            position: relative;
            overflow: hidden;
        }

        .customer-card.walkin-card::before {
            content: "WALK-IN";
            position: absolute;
            top: 8px;
            right: -25px;
            background: #f59e0b;
            color: white;
            font-size: 0.6rem;
            font-weight: bold;
            padding: 2px 25px;
            transform: rotate(45deg);
            z-index: 1;
            letter-spacing: 1px;
        }

        .walkin-badge {
            background: #f59e0b;
            color: white;
            font-size: 0.6rem;
            padding: 2px 8px;
            border-radius: 20px;
            margin-left: 8px;
        }

        /* Modal Styles */
        .code-preview {
            background-color: #e9ecef;
            border: 1px solid #ced4da;
            border-radius: 5px;
            padding: 10px 15px;
            font-family: monospace;
            font-size: 1.1em;
            color: #0d6efd;
            font-weight: bold;
            margin-bottom: 10px;
        }

        .refresh-code {
            cursor: pointer;
            margin-left: 10px;
        }

        .refresh-code:hover {
            color: #0a58ca;
        }

        .form-section-title {
            font-size: 1rem;
            font-weight: 600;
            color: #495057;
            margin: 20px 0 15px 0;
            padding-bottom: 8px;
            border-bottom: 2px solid #e9ecef;
        }

        .modal-header.bg-primary-custom {
            background: linear-gradient(135deg, #047857, #44D34E);
            color: white;
        }

        /* Navbar top */
        .navbar-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            padding: 12px 15px;
            margin-bottom: 15px;
            background: #FFFFFF;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }
        
        .mobile-menu-btn {
            display: none;
            background: none;
            border: none;
            font-size: 22px;
            color: #2E7D32;
            padding: 8px;
            border-radius: 8px;
            width: 40px;
            height: 40px;
            align-items: center;
            justify-content: center;
            cursor: pointer;
        }
        
        @media (max-width: 992px) {
            .mobile-menu-btn {
                display: flex;
            }
        }

        /* Address preview */
        .address-preview {
            background-color: #f8f9fa;
            border-left: 3px solid #0d6efd;
            padding: 10px 15px;
            margin-top: 10px;
            border-radius: 0 5px 5px 0;
            font-size: 0.95em;
        }

        /* Loading spinner */
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

        /* Manual toggle button */
        .manual-toggle-btn {
            font-size: 0.8rem;
            margin-top: 5px;
            cursor: pointer;
            color: #0d6efd;
        }
        
        .manual-toggle-btn:hover {
            text-decoration: underline;
        }

        /* Map container */
        #editLocationMap {
            height: 250px;
            border-radius: 12px;
            margin-bottom: 15px;
            border: 1px solid #e2e8f0;
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
/* ============================================ */
/* ===== ORDER DETAILS MODAL (Like Customer Modal) ===== */
/* ============================================ */

/* Base modal styles */
#orderDetailsModal .modal-dialog {
    margin: 1rem auto !important;
    max-width: 900px !important;
}

@media (max-width: 768px) {
    #orderDetailsModal .modal-dialog {
        margin: 0.75rem auto !important;
        max-width: calc(100% - 1.5rem) !important;
        width: calc(100% - 1.5rem) !important;
    }
}

@media (max-width: 576px) {
    #orderDetailsModal .modal-dialog {
        margin: 0.5rem auto !important;
        max-width: calc(100% - 1rem) !important;
        width: calc(100% - 1rem) !important;
    }
}

#orderDetailsModal .modal-content {
    border: none !important;
    border-radius: 24px !important;
    overflow: hidden !important;
    box-shadow: 0 20px 40px rgba(0, 0, 0, 0.2) !important;
    max-height: 90vh !important;
    display: flex !important;
    flex-direction: column !important;
}

#orderDetailsModal .modal-header {
    background: linear-gradient(135deg, #047857 0%, #44D34E 100%) !important;
    color: white !important;
    border-bottom: none !important;
    padding: 1rem 1.25rem !important;
    flex-shrink: 0 !important;
    position: relative !important;
}

@media (max-width: 768px) {
    #orderDetailsModal .modal-header {
        padding: 0.875rem 1rem !important;
    }
}

#orderDetailsModal .modal-header .modal-title {
    font-weight: 600 !important;
    font-size: 1.1rem !important;
    display: flex !important;
    align-items: center !important;
    gap: 8px !important;
    color: white !important;
}

@media (max-width: 576px) {
    #orderDetailsModal .modal-header .modal-title {
        font-size: 1rem !important;
    }
}

/* Close button - visible */
#orderDetailsModal .modal-header .btn-close {
    background: rgba(255, 255, 255, 0.25) !important;
    border-radius: 50% !important;
    width: 34px !important;
    height: 34px !important;
    padding: 0 !important;
    margin: -0.5rem -0.5rem -0.5rem auto !important;
    opacity: 1 !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    transition: all 0.2s ease !important;
}

@media (max-width: 576px) {
    #orderDetailsModal .modal-header .btn-close {
        width: 30px !important;
        height: 30px !important;
    }
}

@media (max-width: 576px) {
    #orderDetailsModal .modal-header .btn-close::before {
        font-size: 0.9rem !important;
    }
}

#orderDetailsModal .modal-header .btn-close:hover {
    background: rgba(255, 255, 255, 0.4) !important;
    transform: rotate(90deg) !important;
}

#orderDetailsModal .modal-header .btn-close {
    background-image: none !important;
}

#orderDetailsModal .modal-body {
    padding: 0 !important;
    overflow-y: auto !important;
    flex: 1 !important;
    background: #f8fafc !important;
}

/* Scrollbar */
#orderDetailsModal .modal-body::-webkit-scrollbar {
    width: 5px;
}

#orderDetailsModal .modal-body::-webkit-scrollbar-track {
    background: #f1f1f1;
    border-radius: 3px;
}

#orderDetailsModal .modal-body::-webkit-scrollbar-thumb {
    background: #44D34E;
    border-radius: 3px;
}

#orderDetailsModal .modal-footer {
    border-top: 1px solid #e9ecef !important;
    padding: 0.875rem 1.25rem !important;
    background: #ffffff !important;
    flex-shrink: 0 !important;
    gap: 0.75rem !important;
}

@media (max-width: 576px) {
    #orderDetailsModal .modal-footer {
        padding: 0.75rem 1rem !important;
        gap: 0.5rem !important;
    }
}

#orderDetailsModal .modal-footer .btn {
    padding: 0.5rem 1.25rem !important;
    font-size: 0.85rem !important;
    font-weight: 500 !important;
    border-radius: 8px !important;
    transition: all 0.2s ease !important;
}

@media (max-width: 576px) {
    #orderDetailsModal .modal-footer .btn {
        flex: 1 !important;
        padding: 0.45rem 0.5rem !important;
        font-size: 0.75rem !important;
        white-space: nowrap !important;
    }
}

#orderDetailsModal .modal-footer .btn-secondary {
    background: #6c757d !important;
    border: none !important;
    color: white !important;
}

#orderDetailsModal .modal-footer .btn-secondary:hover {
    background: #5a6268 !important;
    transform: translateY(-1px) !important;
}

#orderDetailsModal .modal-footer .btn-primary {
    background: linear-gradient(135deg, #059669, #047857) !important;
    border: none !important;
    color: white !important;
}

#orderDetailsModal .modal-footer .btn-primary:hover {
    transform: translateY(-1px) !important;
    box-shadow: 0 4px 12px rgba(5, 150, 105, 0.3) !important;
}

/* Order Details Card */
#orderDetailsModal .order-details-card {
    background: white !important;
    border-radius: 0 !important;
    margin-bottom: 0 !important;
    overflow: hidden !important;
}

/* Order Header Section */
#orderDetailsModal .order-header-section {
    background: linear-gradient(135deg, #f8f9fa, #e9ecef) !important;
    padding: 1.25rem !important;
    border-bottom: 1px solid #e9ecef !important;
}

@media (max-width: 576px) {
    #orderDetailsModal .order-header-section {
        padding: 1rem !important;
    }
}

#orderDetailsModal .order-badge {
    display: inline-flex !important;
    align-items: center !important;
    gap: 0.5rem !important;
    padding: 0.5rem 1rem !important;
    background: rgba(4, 120, 87, 0.1) !important;
    border-radius: 50px !important;
    margin-bottom: 0.75rem !important;
}

#orderDetailsModal .order-badge i {
    color: #047857 !important;
    font-size: 1.1rem !important;
}

#orderDetailsModal .order-number {
    font-size: 1.3rem !important;
    font-weight: 700 !important;
    color: #1f2937 !important;
    margin-bottom: 0.25rem !important;
}

@media (max-width: 576px) {
    #orderDetailsModal .order-number {
        font-size: 1rem !important;
    }
}

/* Order Info Grid */
#orderDetailsModal .order-info-grid {
    display: grid !important;
    grid-template-columns: repeat(2, 1fr) !important;
    gap: 0.875rem !important;
    padding: 1.25rem !important;
}

@media (max-width: 576px) {
    #orderDetailsModal .order-info-grid {
        grid-template-columns: 1fr !important;
        gap: 0.75rem !important;
        padding: 1rem !important;
    }
}

#orderDetailsModal .order-info-item {
    display: flex !important;
    flex-direction: column !important;
    background: #f8fafc !important;
    padding: 0.875rem !important;
    border-radius: 12px !important;
    transition: all 0.2s ease !important;
    border: 1px solid #e9ecef !important;
}

@media (max-width: 576px) {
    #orderDetailsModal .order-info-item {
        padding: 0.75rem !important;
    }
}

#orderDetailsModal .order-info-item:hover {
    background: #f1f5f9 !important;
    transform: translateX(2px) !important;
}

#orderDetailsModal .order-info-label {
    font-size: 0.7rem !important;
    text-transform: uppercase !important;
    letter-spacing: 0.5px !important;
    color: #6c757d !important;
    margin-bottom: 0.3rem !important;
    font-weight: 600 !important;
    display: flex !important;
    align-items: center !important;
    gap: 0.3rem !important;
}

@media (max-width: 576px) {
    #orderDetailsModal .order-info-label {
        font-size: 0.65rem !important;
    }
}

#orderDetailsModal .order-info-value {
    font-size: 0.9rem !important;
    font-weight: 500 !important;
    color: #1f2937 !important;
    word-break: break-word !important;
}

@media (max-width: 576px) {
    #orderDetailsModal .order-info-value {
        font-size: 0.85rem !important;
    }
}

#orderDetailsModal .order-info-value .badge {
    font-size: 0.7rem !important;
    padding: 0.25rem 0.5rem !important;
}

/* Driver Badge in Modal */
#orderDetailsModal .driver-badge-modal {
    background: #e8f5e9 !important;
    color: #388e3c !important;
    padding: 0.3rem 0.7rem !important;
    border-radius: 20px !important;
    font-size: 0.75rem !important;
    display: inline-flex !important;
    align-items: center !important;
    gap: 0.25rem !important;
}

/* Items Section */
#orderDetailsModal .items-section {
    margin-top: 0 !important;
    border-top: 1px solid #e9ecef !important;
    padding: 1.25rem !important;
    background: #ffffff !important;
}

@media (max-width: 576px) {
    #orderDetailsModal .items-section {
        padding: 1rem !important;
    }
}

#orderDetailsModal .items-section h6 {
    font-weight: 600 !important;
    margin-bottom: 1rem !important;
    color: #1f2937 !important;
    font-size: 0.95rem !important;
    display: flex !important;
    align-items: center !important;
    gap: 0.5rem !important;
}

@media (max-width: 576px) {
    #orderDetailsModal .items-section h6 {
        font-size: 0.85rem !important;
        margin-bottom: 0.75rem !important;
    }
}

#orderDetailsModal .items-section h6 i {
    color: #44D34E !important;
}

/* Items Table - Desktop */
#orderDetailsModal .items-table {
    font-size: 0.85rem !important;
    margin-bottom: 0 !important;
    width: 100% !important;
    border-collapse: collapse !important;
}

#orderDetailsModal .items-table th {
    background-color: #f8f9fa !important;
    font-weight: 600 !important;
    padding: 0.75rem !important;
    border-bottom: 2px solid #e9ecef !important;
    color: #1f2937 !important;
}

#orderDetailsModal .items-table td {
    padding: 0.75rem !important;
    vertical-align: middle !important;
    border-bottom: 1px solid #e9ecef !important;
}

#orderDetailsModal .items-table .total-row {
    background-color: #f8f9fa !important;
    font-weight: 600 !important;
}

/* Items Table - Mobile Card View */
@media (max-width: 576px) {
    #orderDetailsModal .items-table thead {
        display: none !important;
    }
    
    #orderDetailsModal .items-table tbody tr {
        display: block !important;
        background: #f8fafc !important;
        border-radius: 12px !important;
        margin-bottom: 0.75rem !important;
        padding: 0.75rem !important;
        border: 1px solid #e9ecef !important;
    }
    
    #orderDetailsModal .items-table tbody td {
        display: flex !important;
        align-items: center !important;
        justify-content: space-between !important;
        padding: 0.5rem 0 !important;
        border: none !important;
        border-bottom: 1px solid #e9ecef !important;
        font-size: 0.75rem !important;
    }
    
    #orderDetailsModal .items-table tbody td:last-child {
        border-bottom: none !important;
        padding-bottom: 0 !important;
    }
    
    #orderDetailsModal .items-table tbody td:first-child::before {
        content: "Product:" !important;
        font-weight: 600 !important;
        color: #6c757d !important;
        min-width: 90px !important;
        display: inline-block !important;
        font-size: 0.7rem !important;
    }
    
    #orderDetailsModal .items-table tbody td:nth-child(2)::before {
        content: "Unit:" !important;
        font-weight: 600 !important;
        color: #6c757d !important;
        min-width: 90px !important;
        display: inline-block !important;
        font-size: 0.7rem !important;
    }
    
    #orderDetailsModal .items-table tbody td:nth-child(3)::before {
        content: "Quantity:" !important;
        font-weight: 600 !important;
        color: #6c757d !important;
        min-width: 90px !important;
        display: inline-block !important;
        font-size: 0.7rem !important;
    }
    
    #orderDetailsModal .items-table tbody td:nth-child(4)::before {
        content: "Unit Price:" !important;
        font-weight: 600 !important;
        color: #6c757d !important;
        min-width: 90px !important;
        display: inline-block !important;
        font-size: 0.7rem !important;
    }
    
    #orderDetailsModal .items-table tbody td:nth-child(5)::before {
        content: "Total:" !important;
        font-weight: 600 !important;
        color: #6c757d !important;
        min-width: 90px !important;
        display: inline-block !important;
        font-size: 0.7rem !important;
    }
    
    #orderDetailsModal .items-table tbody td {
        text-align: right !important;
        justify-content: flex-end !important;
        gap: 0.5rem !important;
    }
    
    #orderDetailsModal .items-table tbody tr.total-row td {
        justify-content: flex-end !important;
        background: #e8f5e9 !important;
        border-radius: 8px !important;
        margin-top: 0.5rem !important;
        font-weight: 600 !important;
    }
    
    #orderDetailsModal .items-table tbody tr.total-row td::before {
        content: "Grand Total:" !important;
        font-weight: 600 !important;
        color: #2e7d32 !important;
    }
}

/* Customer Info Section in Modal */
#orderDetailsModal .customer-section {
    background: #ffffff !important;
    border-top: 1px solid #e9ecef !important;
    padding: 1.25rem !important;
}

@media (max-width: 576px) {
    #orderDetailsModal .customer-section {
        padding: 1rem !important;
    }
}

#orderDetailsModal .customer-section h6 {
    font-weight: 600 !important;
    margin-bottom: 1rem !important;
    color: #1f2937 !important;
    font-size: 0.95rem !important;
    display: flex !important;
    align-items: center !important;
    gap: 0.5rem !important;
}

#orderDetailsModal .customer-section h6 i {
    color: #44D34E !important;
}

#orderDetailsModal .customer-info-card {
    background: #f8fafc !important;
    border-radius: 12px !important;
    padding: 1rem !important;
    border: 1px solid #e9ecef !important;
}

@media (max-width: 576px) {
    #orderDetailsModal .customer-info-card {
        padding: 0.75rem !important;
    }
}

#orderDetailsModal .customer-detail-row {
    display: flex !important;
    margin-bottom: 0.5rem !important;
    font-size: 0.85rem !important;
}

@media (max-width: 576px) {
    #orderDetailsModal .customer-detail-row {
        flex-direction: column !important;
        margin-bottom: 0.75rem !important;
    }
}

#orderDetailsModal .customer-detail-label {
    width: 110px !important;
    font-weight: 600 !important;
    color: #6c757d !important;
    flex-shrink: 0 !important;
}

@media (max-width: 576px) {
    #orderDetailsModal .customer-detail-label {
        width: auto !important;
        margin-bottom: 0.25rem !important;
        font-size: 0.7rem !important;
    }
}

#orderDetailsModal .customer-detail-value {
    flex: 1 !important;
    color: #1f2937 !important;
    word-break: break-word !important;
}

/* Loading state */
#orderDetailsModal .loading-state {
    text-align: center !important;
    padding: 2rem !important;
}

#orderDetailsModal .loading-state .spinner-border {
    color: #44D34E !important;
}

/* Error state */
#orderDetailsModal .error-state {
    text-align: center !important;
    padding: 2rem !important;
    color: #dc2626 !important;
}

/* Landscape mode */
@media (max-height: 500px) and (orientation: landscape) {
    #orderDetailsModal .modal-content {
        max-height: 95vh !important;
    }
    
    #orderDetailsModal .order-info-grid {
        grid-template-columns: repeat(2, 1fr) !important;
        gap: 0.5rem !important;
        padding: 0.75rem !important;
    }
    
    #orderDetailsModal .order-header-section {
        padding: 0.75rem !important;
    }
    
    #orderDetailsModal .items-section,
    #orderDetailsModal .customer-section {
        padding: 0.75rem !important;
    }
    
    #orderDetailsModal .items-table tbody tr {
        margin-bottom: 0.5rem !important;
        padding: 0.5rem !important;
    }
}

        .customer-view-tabs {
            display: flex;
            gap: 10px;
            margin: 18px 0 20px;
            flex-wrap: wrap;
        }
        .customer-tab-btn {
            border: 1px solid #e5e7eb;
            background: #ffffff;
            color: #374151;
            border-radius: 12px;
            padding: 10px 16px;
            font-weight: 600;
            transition: all 0.2s ease;
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
        }
        .customer-tab-btn.active,
        .customer-tab-btn:hover {
            background: #059669;
            color: #ffffff;
            border-color: #059669;
        }
        .customer-group-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            width: fit-content;
            padding: 5px 10px;
            margin: 6px 0;
            border-radius: 999px;
            background: rgba(5, 150, 105, 0.10);
            color: #059669;
            font-size: 0.78rem;
            font-weight: 700;
        }
        .group-section {
            margin-bottom: 26px;
        }
        .group-section-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 12px;
            padding: 12px 15px;
            border-radius: 14px;
            background: #ffffff;
            border: 1px solid #e5e7eb;
            box-shadow: 0 2px 10px rgba(0,0,0,0.04);
        }
        .group-section-title {
            display: flex;
            align-items: center;
            gap: 8px;
            margin: 0;
            color: #111827;
            font-size: 1rem;
            font-weight: 700;
        }
        .group-section-count {
            font-size: 0.8rem;
            font-weight: 700;
            color: #059669;
            background: rgba(5, 150, 105, 0.10);
            padding: 5px 10px;
            border-radius: 999px;
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
/* ============================================ */
/* ===== CUSTOMER GROUPS MODAL - MODERN GREEN THEME ===== */
/* ============================================ */

#customerGroupsModal .modal-content {
    border: none !important;
    border-radius: 24px !important;
    overflow: hidden !important;
    box-shadow: 0 20px 40px rgba(0, 0, 0, 0.2) !important;
    max-height: 90vh !important;
    display: flex !important;
    flex-direction: column !important;
}

/* Modal Header */
#customerGroupsModal .modal-header.bg-primary-custom {
    background: linear-gradient(135deg, #047857 0%, #44D34E 100%) !important;
    border-bottom: none !important;
    padding: 1rem 1.25rem !important;
    flex-shrink: 0 !important;
    position: sticky !important;
    top: 0 !important;
    z-index: 10 !important;
}

@media (max-width: 768px) {
    #customerGroupsModal .modal-header.bg-primary-custom {
        padding: 0.875rem 1rem !important;
    }
}

@media (max-width: 576px) {
    #customerGroupsModal .modal-header.bg-primary-custom {
        padding: 0.75rem 0.875rem !important;
    }
}

#customerGroupsModal .modal-header .modal-title {
    font-weight: 600 !important;
    font-size: 1.1rem !important;
    display: flex !important;
    align-items: center !important;
    gap: 8px !important;
    color: white !important;
}

#customerGroupsModal .modal-header .modal-title i {
    color: white !important;
    font-size: 1.2rem !important;
}

@media (max-width: 576px) {
    #customerGroupsModal .modal-header .modal-title {
        font-size: 0.95rem !important;
    }
}

/* Close button */
#customerGroupsModal .modal-header .btn-close {
    background: rgba(255, 255, 255, 0.25) !important;
    border-radius: 50% !important;
    width: 34px !important;
    height: 34px !important;
    padding: 0 !important;
    margin: -0.5rem -0.5rem -0.5rem auto !important;
    opacity: 1 !important;
    transition: all 0.2s ease !important;
    background-image: none !important;
}

#customerGroupsModal .modal-header .btn-close::before {
    font-size: 1rem !important;
    font-weight: normal !important;
    color: white !important;
}

#customerGroupsModal .modal-header .btn-close:hover {
    background: rgba(255, 255, 255, 0.4) !important;
    transform: rotate(90deg) !important;
}

/* Modal Body */
#customerGroupsModal .modal-body {
    padding: 1.25rem !important;
    overflow-y: auto !important;
    flex: 1 !important;
    background: #f8fafc !important;
    max-height: calc(90vh - 130px) !important;
}

@media (max-width: 768px) {
    #customerGroupsModal .modal-body {
        padding: 1rem !important;
        max-height: calc(90vh - 120px) !important;
    }
}

@media (max-width: 576px) {
    #customerGroupsModal .modal-body {
        padding: 0.875rem !important;
        max-height: calc(90vh - 110px) !important;
    }
}

/* Scrollbar styling */
#customerGroupsModal .modal-body::-webkit-scrollbar {
    width: 5px;
}

#customerGroupsModal .modal-body::-webkit-scrollbar-track {
    background: #f1f1f1;
    border-radius: 3px;
}

#customerGroupsModal .modal-body::-webkit-scrollbar-thumb {
    background: #44D34E;
    border-radius: 3px;
}

/* Alert Info Styling */
#customerGroupsModal .alert-info {
    background: #e8f5e9 !important;
    border: none !important;
    border-left: 4px solid #059669 !important;
    border-radius: 12px !important;
    color: #065f46 !important;
    font-size: 0.75rem !important;
    padding: 0.75rem 1rem !important;
    margin-bottom: 1rem !important;
}

#customerGroupsModal .alert-info i {
    color: #059669 !important;
    font-size: 1rem !important;
}

/* ===== TABLE STYLES ===== */
#customerGroupsModal .table {
    margin-bottom: 0 !important;
    border-collapse: collapse !important;
}

#customerGroupsModal .table thead th {
    background: #f8fafc !important;
    color: #475569 !important;
    font-weight: 600 !important;
    font-size: 0.7rem !important;
    text-transform: uppercase !important;
    letter-spacing: 0.5px !important;
    padding: 0.85rem 1rem !important;
    border-bottom: 2px solid #e9ecef !important;
    border-top: none !important;
}

#customerGroupsModal .table thead th:first-child {
    text-align: left !important;
}

#customerGroupsModal .table thead th:nth-child(2) {
    text-align: center !important;
}

#customerGroupsModal .table thead th:last-child {
    text-align: right !important;
}

#customerGroupsModal .table tbody tr {
    border-bottom: 1px solid #e9ecef !important;
    transition: all 0.2s ease !important;
}

#customerGroupsModal .table tbody tr:hover {
    background-color: #f1f5f9 !important;
}

#customerGroupsModal .table tbody td {
    padding: 0.85rem 1rem !important;
    vertical-align: middle !important;
    color: #334155 !important;
    font-size: 0.85rem !important;
    border: none !important;
}

#customerGroupsModal .table tbody td:first-child {
    text-align: left !important;
}

#customerGroupsModal .table tbody td:nth-child(2) {
    text-align: center !important;
}

#customerGroupsModal .table tbody td:last-child {
    text-align: right !important;
}

/* ===== GROUP NAME - PLAIN TEXT, NO BADGE STYLE ===== */
#customerGroupsModal .table tbody td:first-child {
    font-weight: 500 !important;
    color: #1e293b !important;
    font-size: 0.85rem !important;
    background: transparent !important;
    padding: 0.85rem 1rem !important;
    border-radius: 0 !important;
}

/* ===== BADGE COUNT STYLING - IMPROVED ===== */
#customerGroupsModal .badge.bg-light {
    background: #e8f5e9 !important;
    color: #047857 !important;
    font-weight: 600 !important;
    padding: 0.3rem 0.8rem !important;
    border-radius: 50px !important;
    font-size: 0.75rem !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 0.25rem !important;
    min-width: 45px !important;
    border: 1px solid rgba(4, 120, 87, 0.2) !important;
}

/* ===== EDIT BUTTON - IMPROVED ===== */
#customerGroupsModal .customer-group-edit-btn {
    background: linear-gradient(135deg, #44D34E, #047857) !important;
    border: none !important;
    color: #fff !important;
    font-weight: 600 !important;
    border-radius: 8px !important;
    min-width: 70px !important;
    padding: 0.45rem 0.9rem !important;
    font-size: 0.7rem !important;
    transition: all 0.2s ease !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 0.4rem !important;
    cursor: pointer !important;
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05) !important;
}

#customerGroupsModal .customer-group-edit-btn i {
    font-size: 0.75rem !important;
    margin: 0 !important;
}

#customerGroupsModal .customer-group-edit-btn span {
    font-size: 0.7rem !important;
    font-weight: 600 !important;
}

#customerGroupsModal .customer-group-edit-btn:hover {
    background: linear-gradient(135deg, #3bc645, #036b4d) !important;
    color: #fff !important;
    transform: translateY(-1px) !important;
    box-shadow: 0 4px 12px rgba(4, 120, 87, 0.25) !important;
}

#customerGroupsModal .customer-group-edit-btn:active {
    transform: translateY(0px) !important;
}

/* Icon-only button on very small screens */
@media (max-width: 480px) {
    #customerGroupsModal .customer-group-edit-btn span {
        display: none !important;
    }
    
    #customerGroupsModal .customer-group-edit-btn {
        min-width: 36px !important;
        padding: 0.45rem !important;
        border-radius: 8px !important;
    }
    
    #customerGroupsModal .customer-group-edit-btn i {
        font-size: 0.9rem !important;
        margin: 0 !important;
    }
    
    #customerGroupsModal .badge.bg-light {
        min-width: 38px !important;
        padding: 0.25rem 0.6rem !important;
        font-size: 0.7rem !important;
    }
}

/* ===== EMPTY STATE STYLES ===== */
#customerGroupsModal .empty-state {
    padding: 2rem !important;
}

#customerGroupsModal .empty-state i {
    font-size: 3rem !important;
    color: #94a3b8 !important;
    opacity: 0.5 !important;
}

#customerGroupsModal .empty-state h6 {
    color: #1e293b !important;
    font-weight: 600 !important;
    margin-top: 0.75rem !important;
    margin-bottom: 0.25rem !important;
    font-size: 0.9rem !important;
}

#customerGroupsModal .empty-state p {
    color: #94a3b8 !important;
    font-size: 0.8rem !important;
}

/* ===== RESPONSIVE STYLES ===== */
@media (max-width: 768px) {
    #customerGroupsModal .table thead th,
    #customerGroupsModal .table tbody td {
        padding: 0.6rem 0.75rem !important;
        font-size: 0.8rem !important;
    }
    
    #customerGroupsModal .customer-group-edit-btn {
        min-width: 65px !important;
        padding: 0.4rem 0.7rem !important;
        font-size: 0.65rem !important;
    }
    
    #customerGroupsModal .badge.bg-light {
        padding: 0.25rem 0.7rem !important;
        font-size: 0.7rem !important;
        min-width: 42px !important;
    }
}

@media (max-width: 576px) {
    #customerGroupsModal .table thead th,
    #customerGroupsModal .table tbody td {
        padding: 0.5rem 0.6rem !important;
        font-size: 0.75rem !important;
    }
    
    #customerGroupsModal .table tbody td:first-child {
        font-size: 0.8rem !important;
    }
    
    #customerGroupsModal .customer-group-edit-btn {
        min-width: 55px !important;
        padding: 0.35rem 0.5rem !important;
    }
    
    #customerGroupsModal .badge.bg-light {
        padding: 0.2rem 0.5rem !important;
        font-size: 0.65rem !important;
        min-width: 35px !important;
    }
}

/* ===== ANIMATION ===== */


/* Keep Customer Groups modal height stable while adding rows */
#customerGroupsModal .modal-dialog {
    max-height: 90vh !important;
}

#customerGroupsModal .modal-body {
    overflow: hidden !important;
}

#customerGroupsModal .customer-groups-table-scroll {
    max-height: 380px !important;
    overflow-y: auto !important;
    overflow-x: auto !important;
    border-radius: 12px !important;
    background: #ffffff !important;
}

#customerGroupsModal .customer-groups-table-scroll thead th {
    position: sticky !important;
    top: 0 !important;
    z-index: 2 !important;
}

#customerGroupsModal .customer-groups-table-scroll::-webkit-scrollbar {
    width: 6px;
    height: 6px;
}

#customerGroupsModal .customer-groups-table-scroll::-webkit-scrollbar-thumb {
    background: #44D34E;
    border-radius: 10px;
}

@media (max-width: 768px) {
    #customerGroupsModal .customer-groups-table-scroll {
        max-height: 320px !important;
    }
}

@media (max-width: 576px) {
    #customerGroupsModal .customer-groups-table-scroll {
        max-height: 280px !important;
    }
}

#customerGroupsModal .customer-groups-footer {
    border-top: 1px solid #e5e7eb;
    justify-content: flex-start;
    padding: 16px 24px 20px;
}

#customerGroupsModal .customer-group-new-btn,
#customerGroupsModal .customer-group-save-btn {
    background: linear-gradient(135deg, #44D34E, #047857);
    border: none;
    color: #fff;
    font-weight: 700;
    border-radius: 10px;
    box-shadow: 0 8px 18px rgba(4, 120, 87, 0.18);
}

#customerGroupsModal .customer-group-new-btn:hover,
#customerGroupsModal .customer-group-save-btn:hover {
    color: #fff;
    transform: translateY(-1px);
    box-shadow: 0 10px 22px rgba(4, 120, 87, 0.25);
}

#customerGroupsModal .customer-group-inline-input {
    border: 1px solid #9ee7b7;
    border-radius: 10px;
    font-weight: 700;
    color: #052A47;
    min-height: 42px;
}

#customerGroupsModal .customer-group-inline-input:focus {
    border-color: #44D34E;
    box-shadow: 0 0 0 0.2rem rgba(68, 211, 78, 0.16);
}

#customerGroupsModal .customer-group-new-row td {
    background: #f0fdf4 !important;
}

#customerGroupsModal.fade .modal-dialog {
    transform: scale(0.95) !important;
    transition: transform 0.2s ease-out !important;
}

#customerGroupsModal.fade.show .modal-dialog {
    transform: scale(1) !important;
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
        
        /* Alert for missing table */
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
        
        /* Status badges */
        .status-badge {
            display: inline-block;
            padding: 5px 12px;
            font-size: 12px;
            font-weight: 500;
            border-radius: 20px;
            text-align: center;
            min-width: 85px;
        }
        
        .status-pending { background-color: #fff3cd; color: #856404; }
        .status-approved { background-color: #d4edda; color: #155724; }
        .status-rejected { background-color: #f8d7da; color: #721c24; }
        .status-expired { background-color: #e9ecef; color: #6c757d; }
        
        /* Request type badge */
        .type-badge {
            display: inline-block;
            padding: 4px 10px;
            font-size: 11px;
            font-weight: 500;
            border-radius: 4px;
        }
        
        .type-credit { background-color: #cce5ff; color: #004085; }
        .type-discount { background-color: #d4edda; color: #155724; }
        .type-both { background-color: #e2d5f2; color: #533f7c; }
        .type-credit_terms { background-color: #fff3cd; color: #856404; }
        
        /* Attachment styles */
        .attachment-list {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            justify-content: flex-start;
            margin-top: 8px;
        }
        .attachment-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background: #f0f0f0;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.7rem;
            color: #333;
            text-decoration: none;
            transition: all 0.2s;
            cursor: pointer;
        }
        .attachment-badge:hover {
            background: #e0e0e0;
            color: #000;
            transform: translateY(-1px);
        }
        .attachment-badge i {
            font-size: 0.75rem;
        }
        
        /* Table column widths */
        .col-id { width: 5%; }
        .col-date { width: 10%; }
        .col-customer { width: 13%; }
        .col-agent { width: 10%; }
        .col-type { width: 8%; }
        .col-credit { width: 8%; }
        .col-discount { width: 7%; }
        .col-terms { width: 7%; }
        .col-reason { width: 15%; }
        .col-status { width: 8%; }
        .col-validity { width: 12%; }
        .col-attachments { width: 8%; }
        .col-actions { width: 10%; text-align: center; }
        
        /* Filter section */
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
        
        .search-box {
            position: relative;
            min-width: 250px;
        }
        
        .search-box i {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #6c757d;
            font-size: 14px;
            z-index: 10;
            pointer-events: none;
        }
        
        .search-box input {
            width: 100%;
            padding: 8px 12px 8px 38px;
            border: 1px solid #ced4da;
            border-radius: 6px;
            height: 40px;
            font-size: 14px;
        }
        
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
            margin-bottom: 8px;
        }
        .btn-approve{
            color: #388e3c;
            background-color: #e8f5e9;
            border-color: #c8e6c9;
        }
        
        .btn-reject{
            color: #c30010;
            background-color: #ffcbd1;
            border-color: #f69697;
        }
             
        /* Modal styling */
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
        
        /* Expiration days input */
        .expiration-slider {
            margin: 15px 0;
        }

        .expiration-number-wrap {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .expiration-number-input {
            max-width: 160px;
        }

        .expiration-value {
            font-size: 14px;
            font-weight: bold;
            color: #0d6efd;
            white-space: nowrap;
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
        
        @media print {
            @page {
                size: landscape;
                margin: 0.3in;
            }
            body * { visibility: hidden; background: white !important; color: black !important; border-color: black !important; }
            #printFrame, #printFrame * { visibility: visible; }
            #printFrame { position: absolute; left: 0; top: 0; width: 100%; height: auto; border: none; }
            #printFrame img { filter: none !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            #printFrame * { background: white !important; color: black !important; border-color: #000 !important; box-shadow: none !important; text-shadow: none !important; }
            #printFrame table, #printFrame th, #printFrame td { border: 1px solid #000 !important; }
            #printFrame th { background: white !important; color: black !important; font-weight: bold; }
            #printFrame .summary-box, #printFrame .customer-section, #printFrame .total-row { background: white !important; border: 1px solid #000 !important; }
            #printFrame .badge { background: white !important; border: 1px solid #000 !important; color: black !important; padding: 2px 6px; }
        }
        /* ===== QUICK STATS CARDS STYLE - gaya ng nasa ibaba ===== */
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

/* Gradient backgrounds for each type */
.stat-card.total {
   background: linear-gradient(135deg, #047857, #059669) !important;
    border: none !important;
}

.stat-card.pending {
    background: linear-gradient(135deg, #047857, #059669) !important;
    border: none !important;
}

.stat-card.approved {
    background: linear-gradient(135deg, #047857, #059669) !important;
    border: none !important;
}

.stat-card.rejected {
    background: linear-gradient(135deg, #047857, #059669) !important;
    border: none !important;
}

/* Force text colors to white */
.stat-card .stat-value,
.stat-card .stat-label {
    color: white !important;
}

/* Remove any white background from icon */
.stat-card .stat-icon {
    background: transparent !important;
    color: white !important;
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
        white-space: normal !important;
        word-break: break-word !important;
        line-height: 1.2 !important;
    }
    
    /* Para sa "Total Requests" na mahaba */
    .stat-card.total .stat-label {
        font-size: 0.65rem !important;
        max-width: 100% !important;
        padding: 0 0.2rem !important;
    }
}

/* ===== DESKTOP: HORIZONTAL LAYOUT ===== */
@media (min-width: 992px) {
    .stat-card {
        align-items: center !important;
        text-align: left !important;
        padding: 1rem !important;
        aspect-ratio: auto !important;
        min-height: 100px !important;
        display: flex !important;
        flex-direction: row !important;
        justify-content: flex-start !important;
    }
    
    .stat-card i,
    .stat-card .stat-icon {
        margin: 0 0.75rem 0 0 !important;
        font-size: 2rem !important;
        display: inline-flex !important;
        align-items: center !important;
        justify-content: center !important;
        flex-shrink: 0 !important;
    }
    
    .stat-card .stat-value {
        font-size: 1.5rem !important;
        font-weight: bold !important;
        line-height: 1 !important;
        margin: 0 !important;
    }
    
    .stat-card .stat-label {
        font-size: 0.75rem !important;
        font-weight: 500 !important;
        white-space: nowrap !important;
        line-height: 1.2 !important;
        margin-top: 0.25rem !important;
    }
    
    /* Para sa desktop, hindi mag-break ang "Total Requests" */
    .stat-card.total .stat-label {
        white-space: nowrap !important;
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
    
    /* Para sa "Total Requests" sa tablet */
    .stat-card.total .stat-label {
        font-size: 0.55rem !important;
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
    
    /* Para sa "Total Requests" sa sobrang liit na screen */
    .stat-card.total .stat-label {
        font-size: 0.5rem !important;
    }
}

/* ===== HOVER EFFECT ===== */
.stat-card:hover {
    transform: translateY(-2px) !important;
    box-shadow: 0 6px 15px rgba(0, 0, 0, 0.15) !important;
}
/* ===== FILTER ACTION BUTTONS STYLE ===== */
.filter-actions {
    display: flex;
    gap: 0.5rem;
    justify-content: flex-end;
    flex-wrap: wrap;
    margin-top: 0 !important;
    padding-top: 0 !important;
}

.filter-actions .btn {
    padding: 0.35rem 0.85rem;
    font-size: 0.8rem;
    border-radius: 6px;
    transition: all 0.2s ease;
    font-weight: 500;
}

.filter-actions .btn-outline-primary {
    border: 1px solid #dee2e6;
    background: white;
    color: #0d6efd;
}

.filter-actions .btn-outline-primary:hover {
    background: #0d6efd;
    border-color: #0d6efd;
    color: white;
    transform: translateY(-1px);
    box-shadow: 0 2px 4px rgba(13, 110, 253, 0.2);
}

.filter-actions .btn-outline-success {
    border: 1px solid #dee2e6;
    background: white;
    color: #198754;
}

.filter-actions .btn-outline-success:hover {
    background: #198754;
    border-color: #198754;
    color: white;
    transform: translateY(-1px);
    box-shadow: 0 2px 4px rgba(25, 135, 84, 0.2);
}

.filter-actions .btn i {
    margin-right: 0.35rem;
    font-size: 0.75rem;
}

/* Alisin ang white space sa row na may buttons */
.filter-content .row:last-child {
    margin-top: 0 !important;
    padding-top: 0 !important;
}

/* Adjust ang spacing ng buong filter content */
.filter-content {
    padding: 1.25rem;
    padding-bottom: 0.75rem;
}

/* Bawasan ang gap sa pagitan ng rows */
.filter-content .row + .row {
    margin-top: 0 !important;
}

/* Mobile responsive */
@media (max-width: 768px) {
    .filter-actions {
        justify-content: stretch;
        margin-top: 0.25rem !important;
    }
    
    .filter-actions .btn {
        flex: 1;
        text-align: center;
        padding: 0.35rem 0.5rem;
        font-size: 0.75rem;
    }
}

/* ===== APPROVE CREDIT FILTER DEFAULT VISIBLE ===== */
#creditRequestsTabContent #filterCard{border:1px solid #d1fae5;background:#ffffff;border-radius:16px;box-shadow:0 8px 24px rgba(5,42,71,.06);margin-top:6px!important;margin-bottom:12px!important;}
#creditRequestsTabContent .filter-content{overflow:visible!important;max-height:none!important;opacity:1!important;padding:10px 16px!important;border-top:0!important;}
#creditRequestsTabContent .filter-content.collapsed{max-height:none!important;opacity:1!important;padding:10px 16px!important;border-top:0!important;}
#creditRequestsTabContent .filter-content.always-visible{display:block!important;}
#creditRequestsTabContent .filter-actions{display:flex;gap:.5rem;flex-wrap:wrap;margin-top:0!important;}
#creditRequestsTabContent .filter-actions .btn{height:42px;white-space:nowrap;}
#creditRequestsTabContent #filterCard .row{--bs-gutter-y:.55rem!important;}
#creditRequestsTabContent #filterCard .form-label{margin-bottom:5px!important;font-size:11px!important;line-height:1.1!important;}
#creditRequestsTabContent #filterCard .form-select,
#creditRequestsTabContent #filterCard .form-control{height:36px!important;min-height:36px!important;padding-top:6px!important;padding-bottom:6px!important;}
#creditRequestsTabContent #filterCard .filter-actions{align-items:end!important;padding-bottom:0!important;}


/* ===== PRINT / EXPORT BUTTONS SAME AS PLACE ORDER ===== */
#creditRequestsTabContent .filter-actions .filter-action-primary {
    background: linear-gradient(135deg, #059669, #047857) !important;
    border: none !important;
    border-radius: 10px !important;
    padding: 0.7rem 1.5rem !important;
    font-weight: 600 !important;
    font-size: 0.95rem !important;
    color: #ffffff !important;
    box-shadow: 0 4px 8px rgba(5, 150, 105, 0.25) !important;
    transition: all 0.3s ease !important;
    height: 42px !important;
    min-width: 98px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    white-space: nowrap;
}

#creditRequestsTabContent .filter-actions .filter-action-primary:hover {
    transform: translateY(-2px) !important;
    box-shadow: 0 8px 18px rgba(5, 150, 105, 0.35) !important;
    background: linear-gradient(135deg, #047857, #065f46) !important;
    color: #ffffff !important;
}

#creditRequestsTabContent .filter-actions .filter-action-primary i {
    margin-right: 0 !important;
    font-size: 0.95rem !important;
}

@media (max-width: 768px) {
    #creditRequestsTabContent .filter-actions .filter-action-primary {
        flex: 1;
        width: 100%;
        min-width: 0;
    }
}


/* ===== REQUEST FILTER ONE-LINE ALIGNMENT FIX ===== */
#creditRequestsTabContent #filterCard .request-filter-grid {
    display: grid !important;
    grid-template-columns: minmax(190px, 1fr) minmax(190px, 1fr) minmax(320px, 1.35fr) auto !important;
    gap: 12px 14px !important;
    align-items: end !important;
    width: 100% !important;
}

#creditRequestsTabContent #filterCard .filter-field {
    min-width: 0 !important;
}

#creditRequestsTabContent #filterCard .request-filter-actions {
    display: flex !important;
    flex-direction: row !important;
    flex-wrap: nowrap !important;
    justify-content: flex-end !important;
    align-items: flex-end !important;
    gap: 10px !important;
    margin-top: 0 !important;
    padding-bottom: 0 !important;
    white-space: nowrap !important;
}

#creditRequestsTabContent #filterCard .request-filter-actions .filter-action-primary {
    width: auto !important;
    min-width: 104px !important;
    height: 42px !important;
    flex: 0 0 auto !important;
}

@media (max-width: 1199.98px) {
    #creditRequestsTabContent #filterCard .request-filter-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
    }

    #creditRequestsTabContent #filterCard .filter-field-search,
    #creditRequestsTabContent #filterCard .request-filter-actions {
        grid-column: 1 / -1 !important;
    }

    #creditRequestsTabContent #filterCard .request-filter-actions {
        justify-content: flex-start !important;
    }
}

@media (max-width: 768px) {
    #creditRequestsTabContent #filterCard .request-filter-grid {
        grid-template-columns: 1fr !important;
    }

    #creditRequestsTabContent #filterCard .filter-field-search,
    #creditRequestsTabContent #filterCard .request-filter-actions {
        grid-column: auto !important;
    }

    #creditRequestsTabContent #filterCard .request-filter-actions {
        flex-wrap: wrap !important;
    }

    #creditRequestsTabContent #filterCard .request-filter-actions .filter-action-primary {
        flex: 1 1 calc(50% - 5px) !important;
        width: auto !important;
        min-width: 120px !important;
    }
}


/* ===== FORCE OVERRIDE FOR REQUESTS TABLE MOBILE VIEW ===== */
@media (max-width: 768px) {
    /* Force hide table header */
    #requestsTable thead,
    #requestsTable thead tr,
    #requestsTable thead th {
        display: none !important;
        visibility: hidden !important;
        opacity: 0 !important;
        height: 0 !important;
        padding: 0 !important;
        margin: 0 !important;
        border: none !important;
    }
    
    /* Force table to block display */
    #requestsTable,
    #requestsTable tbody,
    #requestsTable tbody tr {
        display: block !important;
        width: 100% !important;
        box-sizing: border-box !important;
    }
    
    /* Force each row as card */
    #requestsTable tbody tr.request-row {
        background: white !important;
        border-radius: 12px !important;
        margin-bottom: 12px !important;
        padding: 14px !important;
        padding-top: 12px !important;
        box-shadow: 0 1px 3px rgba(0,0,0,0.08) !important;
        border: 1px solid #e5e7eb !important;
        display: block !important;
        width: 100% !important;
        box-sizing: border-box !important;
        position: relative !important;
        cursor: pointer !important;
    }
    
    /* FORCE HIDE all original td elements */
    #requestsTable tbody tr.request-row td {
        display: none !important;
        padding: 0 !important;
        margin: 0 !important;
        border: none !important;
        background: transparent !important;
    }
    
    /* ===== DATE ===== */
    #requestsTable tbody tr.request-row td.col-date {
        display: block !important;
        font-size: 11px !important;
        color: #9ca3af !important;
        margin-bottom: 8px !important;
        padding: 0 !important;
        background: transparent !important;
        border: none !important;
        width: auto !important;
    }
    
    /* ===== STATUS BADGE ===== */
    #requestsTable tbody tr.request-row td.col-status {
        display: block !important;
        position: absolute !important;
        top: 12px !important;
        right: 12px !important;
        padding: 0 !important;
        background: transparent !important;
        z-index: 10 !important;
        width: auto !important;
        height: auto !important;
    }
    
    #requestsTable tbody tr.request-row td.col-status .status-badge {
        display: inline-block !important;
        font-size: 10px !important;
        padding: 4px 10px !important;
        border-radius: 20px !important;
        font-weight: 500 !important;
        margin: 0 !important;
        white-space: nowrap !important;
    }
    
    /* ===== CUSTOMER NAME ===== */
    #requestsTable tbody tr.request-row td.col-customer {
        display: block !important;
        padding: 0 !important;
        margin-top: 5px !important;
        margin-bottom: 4px !important;
        background: transparent !important;
        border: none !important;
        padding-right: 100px !important;
    }
    
    #requestsTable tbody tr.request-row td.col-customer strong {
        display: block !important;
        font-size: 15px !important;
        font-weight: 600 !important;
        color: #1f2937 !important;
        background: transparent !important;
    }
    
    /* ===== CUSTOMER CODE ===== */
    #requestsTable tbody tr.request-row td.col-customer small {
        display: block !important;
        font-size: 10px !important;
        color: #9ca3af !important;
        margin-top: 2px !important;
        background: transparent !important;
    }
    
    /* ===== AGENT ===== */
    #requestsTable tbody tr.request-row td.col-agent {
        display: block !important;
        font-size: 12px !important;
        color: #6c757d !important;
        margin-bottom: 8px !important;
        padding: 0 !important;
        background: transparent !important;
    }
    
    #requestsTable tbody tr.request-row td.col-agent::before {
        content: "Agent: ";
        font-weight: 600;
        color: #495057;
    }
    
    /* ===== TYPE BADGE ===== */
    #requestsTable tbody tr.request-row td.col-type {
        display: block !important;
        margin-bottom: 10px !important;
        padding: 0 !important;
        background: transparent !important;
    }
    
    #requestsTable tbody tr.request-row td.col-type .type-badge {
        display: inline-block !important;
        font-size: 10px !important;
        padding: 3px 10px !important;
        border-radius: 20px !important;
        font-weight: 500 !important;
    }
    
    /* ===== CREDIT ===== */
    #requestsTable tbody tr.request-row td.col-credit {
        display: inline-block !important;
        font-size: 12px !important;
        margin-right: 12px !important;
        margin-bottom: 6px !important;
        padding: 0 !important;
        background: transparent !important;
    }
    
    #requestsTable tbody tr.request-row td.col-credit::before {
        content: "Credit: ";
        font-weight: 600;
        color: #495057;
    }
    
    /* ===== DISCOUNT ===== */
    #requestsTable tbody tr.request-row td.col-discount {
        display: inline-block !important;
        font-size: 12px !important;
        margin-bottom: 6px !important;
        padding: 0 !important;
        background: transparent !important;
    }
    
    #requestsTable tbody tr.request-row td.col-discount::before {
        content: "Discount: ";
        font-weight: 600;
        color: #495057;
    }
    
    /* ===== TERMS ===== */
    #requestsTable tbody tr.request-row td.col-terms {
        display: block !important;
        font-size: 12px !important;
        margin-bottom: 6px !important;
        padding: 0 !important;
        background: transparent !important;
    }
    
    #requestsTable tbody tr.request-row td.col-terms::before {
        content: "Terms: ";
        font-weight: 600;
        color: #495057;
    }
    
    /* ===== VALIDITY ===== */
    #requestsTable tbody tr.request-row td.col-validity {
        display: block !important;
        font-size: 11px !important;
        color: #6c757d !important;
        margin-top: 8px !important;
        padding: 0 !important;
        background: transparent !important;
    }
    
    #requestsTable tbody tr.request-row td.col-validity::before {
        content: "Valid: ";
        font-weight: 600;
        color: #495057;
    }
    
    /* ===== TAP TO VIEW INDICATOR ===== */
    #requestsTable tbody tr.request-row::after {
        content: "Tap to view full details" !important;
        display: block !important;
        text-align: left !important;
        font-size: 9px !important;
        color: #9ca3af !important;
        margin-top: 10px !important;
        padding-top: 8px !important;
        border-top: 1px solid #f0f0f0 !important;
        clear: both !important;
    }
}

/* Extra small mobile adjustments */
@media (max-width: 480px) {
    #requestsTable tbody tr.request-row {
        padding: 10px !important;
        padding-top: 10px !important;
    }
    
    #requestsTable tbody tr.request-row td.col-status .status-badge {
        font-size: 9px !important;
        padding: 3px 8px !important;
    }
    
    #requestsTable tbody tr.request-row td.col-customer strong {
        font-size: 14px !important;
    }
    
    #requestsTable tbody tr.request-row td.col-customer {
        padding-right: 85px !important;
    }
    
    #requestsTable tbody tr.request-row::after {
        font-size: 8px !important;
        margin-top: 8px !important;
        padding-top: 6px !important;
    }
    
    #requestsTable tbody tr.request-row td.col-credit,
    #requestsTable tbody tr.request-row td.col-discount {
        font-size: 11px !important;
    }
    
    #requestsTable tbody tr.request-row td.col-terms {
        font-size: 11px !important;
    }
}

/* ===== MODERN VIEW REQUEST MODAL ===== */

/* Modal Container */
#viewRequestModal .modal-content {
    border: none !important;
    border-radius: 20px !important;
    overflow: hidden !important;
    box-shadow: 0 20px 40px rgba(0, 0, 0, 0.15) !important;
    max-height: 90vh !important;
    display: flex !important;
    flex-direction: column !important;
}

/* Modal Header */
#viewRequestModal .modal-header {
    background: linear-gradient(135deg, #047857, #44D34E) !important;
    color: white !important;
    border-bottom: none !important;
    padding: 1rem 1.5rem !important;
    flex-shrink: 0 !important;
}

#viewRequestModal .modal-header .modal-title {
    font-weight: 600 !important;
    font-size: 1.2rem !important;
    display: flex !important;
    align-items: center !important;
    gap: 8px !important;
    color: white !important;
}

#viewRequestModal .modal-header .modal-title i {
    font-size: 1.3rem !important;
    color: white !important;
}

#viewRequestModal .modal-header .btn-close {
    filter: brightness(0) invert(1) !important;
    opacity: 0.8 !important;
    background: transparent !important;
    transition: all 0.2s ease !important;
}

#viewRequestModal .modal-header .btn-close:hover {
    opacity: 1 !important;
    transform: rotate(90deg) !important;
}

/* Modal Body */
#viewRequestModal .modal-body {
    padding: 1.5rem !important;
    overflow-y: auto !important;
    flex: 1 !important;
    background: #f8fafc !important;
}

/* Scrollbar */
#viewRequestModal .modal-body::-webkit-scrollbar {
    width: 6px;
}

#viewRequestModal .modal-body::-webkit-scrollbar-track {
    background: #f1f1f1;
    border-radius: 3px;
}

#viewRequestModal .modal-body::-webkit-scrollbar-thumb {
    background: #44D34E;
    border-radius: 3px;
}

/* Modal Footer */
#viewRequestModal .modal-footer {
    border-top: 1px solid #e9ecef !important;
    padding: 1rem 1.5rem !important;
    background: white !important;
    flex-shrink: 0 !important;
    gap: 0.75rem !important;
}

#viewRequestModal .modal-footer .btn {
    padding: 0.5rem 1.25rem !important;
    font-size: 0.9rem !important;
    font-weight: 500 !important;
    border-radius: 8px !important;
    transition: all 0.2s ease !important;
}

#viewRequestModal .modal-footer .btn-secondary {
    background: #6c757d !important;
    border: none !important;
    color: white !important;
}

#viewRequestModal .modal-footer .btn-secondary:hover {
    background: #5a6268 !important;
    transform: translateY(-1px) !important;
}

#viewRequestModal .modal-footer .btn-success {
    background: linear-gradient(135deg, #047857, #059669) !important;
    border: none !important;
    color: white !important;
}

#viewRequestModal .modal-footer .btn-success:hover {
    transform: translateY(-1px) !important;
    box-shadow: 0 4px 12px rgba(5, 150, 105, 0.3) !important;
}

#viewRequestModal .modal-footer .btn-danger {
    background: linear-gradient(135deg, #dc3545, #c82333) !important;
    border: none !important;
    color: white !important;
}

#viewRequestModal .modal-footer .btn-danger:hover {
    transform: translateY(-1px) !important;
    box-shadow: 0 4px 12px rgba(220, 53, 69, 0.3) !important;
}

/* Info Cards inside Modal */
#viewRequestModal .info-card {
    background: white !important;
    border-radius: 12px !important;
    margin-bottom: 1rem !important;
    border: 1px solid #e9ecef !important;
    overflow: hidden !important;
    transition: all 0.2s ease !important;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05) !important;
}

#viewRequestModal .info-card:hover {
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08) !important;
    border-color: #d1fae5 !important;
}

#viewRequestModal .card-title {
    background: #f8fafc !important;
    border-bottom: 1px solid #e9ecef !important;
    padding: 0.875rem 1.25rem !important;
    font-size: 0.9rem !important;
    font-weight: 600 !important;
    color: #047857 !important;
    margin: 0 !important;
    display: flex !important;
    align-items: center !important;
    gap: 0.5rem !important;
}

#viewRequestModal .card-title i {
    color: #44D34E !important;
    font-size: 1rem !important;
}

#viewRequestModal .card-body {
    padding: 1rem 1.25rem !important;
    background: white !important;
}

/* Info Rows */
#viewRequestModal .info-row {
    display: flex !important;
    margin-bottom: 0.75rem !important;
    line-height: 1.4 !important;
}

#viewRequestModal .info-row:last-child {
    margin-bottom: 0 !important;
}

#viewRequestModal .info-label {
    width: 110px !important;
    flex-shrink: 0 !important;
    font-weight: 600 !important;
    color: #6c757d !important;
    font-size: 0.85rem !important;
}

#viewRequestModal .info-value {
    flex: 1 !important;
    color: #1f2937 !important;
    font-size: 0.85rem !important;
    word-break: break-word !important;
    font-weight: 500 !important;
}

/* Status Badge in Modal */
#viewRequestModal .status-badge {
    display: inline-block !important;
    padding: 0.25rem 0.75rem !important;
    font-size: 0.7rem !important;
    font-weight: 600 !important;
    border-radius: 20px !important;
}

/* Type Badge in Modal */
#viewRequestModal .type-badge {
    display: inline-block !important;
    padding: 4px 10px !important;
    font-size: 11px !important;
    font-weight: 500 !important;
    border-radius: 4px !important;
}

#viewRequestModal .type-credit {
    background-color: #cce5ff !important;
    color: #004085 !important;
}

#viewRequestModal .type-discount {
    background-color: #d4edda !important;
    color: #155724 !important;
}

#viewRequestModal .type-both {
    background-color: #e2d5f2 !important;
    color: #533f7c !important;
}

#viewRequestModal .type-credit_terms {
    background-color: #fff3cd !important;
    color: #856404 !important;
}

/* Attachment section in modal */
.attachment-section {
    background: white !important;
    border-radius: 12px !important;
    margin-top: 1rem !important;
    border: 1px solid #e9ecef !important;
    overflow: hidden !important;
}

.attachment-section .card-title {
    background: #f8fafc !important;
    border-bottom: 1px solid #e9ecef !important;
    padding: 0.875rem 1.25rem !important;
    font-size: 0.9rem !important;
    font-weight: 600 !important;
    color: #047857 !important;
    margin: 0 !important;
}

.attachment-section .card-body {
    padding: 1rem 1.25rem !important;
}

.attachment-list-modal {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
}

.attachment-item {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: #f0f0f0;
    padding: 6px 14px;
    border-radius: 25px;
    text-decoration: none;
    color: #333;
    font-size: 0.85rem;
    transition: all 0.2s;
}

.attachment-item:hover {
    background: #e0e0e0;
    transform: translateY(-1px);
    text-decoration: none;
    color: #000;
}

.attachment-item i {
    font-size: 1rem;
}

/* Two Column Layout */
@media (min-width: 768px) {
    #viewRequestModal .two-columns {
        display: flex !important;
        gap: 1rem !important;
    }
    
    #viewRequestModal .two-columns > div {
        flex: 1 !important;
    }
}

/* Mobile Responsive for Modal */
@media (max-width: 768px) {
    #viewRequestModal .modal-dialog {
        margin: 0.5rem !important;
        max-width: calc(100% - 1rem) !important;
    }
    
    #viewRequestModal .modal-header {
        padding: 0.85rem 1rem !important;
    }
    
    #viewRequestModal .modal-header .modal-title {
        font-size: 1rem !important;
    }
    
    #viewRequestModal .modal-body {
        padding: 1rem !important;
    }
    
    #viewRequestModal .modal-footer {
        padding: 0.75rem 1rem !important;
    }
    
    #viewRequestModal .modal-footer .btn {
        flex: 1 !important;
        padding: 0.45rem 0.75rem !important;
        font-size: 0.85rem !important;
    }
    
    #viewRequestModal .info-row {
        flex-direction: column !important;
        margin-bottom: 0.75rem !important;
    }
    
    #viewRequestModal .info-label {
        width: 100% !important;
        margin-bottom: 0.2rem !important;
        font-size: 0.7rem !important;
    }
    
    #viewRequestModal .info-value {
        font-size: 0.85rem !important;
    }
    
    #viewRequestModal .card-title {
        padding: 0.6rem 1rem !important;
        font-size: 0.8rem !important;
    }
    
    #viewRequestModal .card-body {
        padding: 0.75rem 1rem !important;
    }
}

@media (max-width: 480px) {
    #viewRequestModal .modal-footer {
        padding: 0.6rem 0.75rem !important;
    }
    
    #viewRequestModal .modal-footer .btn {
        font-size: 0.75rem !important;
        padding: 0.4rem 0.5rem !important;
    }
    
    #viewRequestModal .info-value {
        font-size: 0.8rem !important;
    }
    
    #viewRequestModal .card-body {
        padding: 0.6rem 0.75rem !important;
    }
}
/* Approval Modal Styling */
#approvalModal .modal-content {
    border: none !important;
    border-radius: 20px !important;
    overflow: hidden !important;
    box-shadow: 0 20px 40px rgba(0, 0, 0, 0.15) !important;
}

#approvalModal .modal-header {
    padding: 1rem 1.5rem !important;
    border-bottom: none !important;
}

#approvalModal .modal-header.bg-success {
    background: linear-gradient(135deg, #047857, #44D34E) !important;
}

#approvalModal .modal-header.bg-danger {
    background: linear-gradient(135deg, #dc3545, #f87171) !important;
}

#approvalModal .modal-header .modal-title {
    font-weight: 600 !important;
    font-size: 1.1rem !important;
    display: flex !important;
    align-items: center !important;
    gap: 8px !important;
    color: white !important;
}

#approvalModal .modal-header .btn-close {
    filter: brightness(0) invert(1) !important;
    opacity: 0.8 !important;
}

#approvalModal .modal-body {
    padding: 1.5rem !important;
    background: #f8fafc !important;
}

#approvalModal .modal-footer {
    border-top: 1px solid #e9ecef !important;
    padding: 1rem 1.5rem !important;
    background: white !important;
    gap: 0.75rem !important;
}

#approvalModal .modal-footer .btn {
    padding: 0.5rem 1.25rem !important;
    border-radius: 8px !important;
    font-weight: 500 !important;
}

.expiration-slider {
    background: white !important;
    border-radius: 12px !important;
    padding: 1rem !important;
    margin: 1rem 0 !important;
    border: 1px solid #e9ecef !important;
}

.expiration-number-wrap {
    display: flex !important;
    align-items: center !important;
    gap: 1rem !important;
    flex-wrap: wrap !important;
}

.expiration-number-input {
    max-width: 180px !important;
}

.expiration-value {
    font-weight: 600 !important;
    color: #047857 !important;
}
/* FORCE FILTER TO WORK ON MOBILE - CRITICAL FIX */
@media (max-width: 768px) {
    /* Ensure hidden rows are completely hidden */
    #requestsTable tbody tr.request-row[style*="display: none"],
    #requestsTable tbody tr.request-row[style*="display:none"] {
        display: none !important;
    }
    
    /* Ensure visible rows display as block */
    #requestsTable tbody tr.request-row:not([style*="display: none"]):not([style*="display:none"]) {
        display: block !important;
    }
}
/* File Preview Modal - Buttons anchored to image corners */
#fileViewModal .modal-dialog {
    margin: 0 auto;
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 100vh;
    max-width: none;
    width: auto;
}

#fileViewModal .modal-content {
    background: transparent !important;
    border: none !important;
    box-shadow: none !important;
    width: auto;
    margin: 0 auto;
}

#fileViewModal .modal-body {
    display: flex !important;
    justify-content: center !important;
    align-items: center !important;
    padding: 20px;
}

/* Attachment container */
#fileViewModal .attachment-container {
    display: flex;
    justify-content: center;
    align-items: center;
}

/* Attachment wrapper - position relative para sa absolute buttons */
#fileViewModal .attachment-wrapper {
    position: relative;
    display: inline-block;
    line-height: 0;
}

/* Close button - nasa top-right corner ng image mismo */
#fileViewModal .btn-close-attachment {
    position: absolute;
    top: 5px;
    right: 5px;
    width: 30px;
    height: 30px;
    background-color: rgba(0,0,0,0.6);
    border-radius: 50%;
    display: flex !important;
    align-items: center;
    justify-content: center;
    border: none;
    color: white;
    font-size: 12px;
    cursor: pointer;
    transition: all 0.2s ease;
    z-index: 10;
    padding: 0;
    margin: 0;
}

#fileViewModal .btn-close-attachment:hover {
    background-color: rgba(0,0,0,0.8);
    transform: scale(1.05);
}

/* Download button - nasa bottom-right corner ng image mismo */
#fileViewModal .btn-download-attachment {
    position: absolute;
    bottom: 5px;
    right: 5px;
    width: 30px;
    height: 30px;
    background-color: rgba(0,0,0,0.6);
    border-radius: 50%;
    display: flex !important;
    align-items: center;
    justify-content: center;
    text-decoration: none;
    color: white;
    font-size: 12px;
    transition: all 0.2s ease;
    z-index: 10;
}

#fileViewModal .btn-download-attachment:hover {
    background-color: rgba(0,0,0,0.8);
    transform: scale(1.05);
    color: white;
}

/* Attachment content - the actual image or PDF */
#fileViewModal .attachment-content {
    display: inline-block;
    line-height: 0;
}

/* For images inside modal */
#fileViewModal .attachment-content img {
    max-height: 85vh;
    max-width: 85vw;
    width: auto;
    height: auto;
    object-fit: contain;
    border-radius: 8px;
    box-shadow: 0 4px 30px rgba(0,0,0,0.3);
    display: block;
}

/* For PDF embeds */
#fileViewModal .attachment-content embed {
    width: 80vw;
    height: 80vh;
    border-radius: 8px;
    box-shadow: 0 4px 30px rgba(0,0,0,0.3);
    display: block;
}

/* For other file types */
#fileViewModal .attachment-content .alert {
    max-width: 500px;
    margin: 20px;
    display: block;
}
/* ===== MOBILE BOTTOM NAVIGATION - FIXED DROPDOWN ===== */


/* ===== CUSTOMER PAGE MAIN TABS ===== */
.customer-main-tabs{display:flex;gap:10px;flex-wrap:wrap;margin:0 0 18px;border-bottom:1px solid #e5e7eb;padding-bottom:10px}.customer-main-tab-btn{border:1px solid #d1d5db;background:#fff;color:#052A47;border-radius:10px;padding:10px 16px;font-weight:700;font-size:14px;display:inline-flex;align-items:center;gap:8px;transition:all .2s ease;position:relative}.customer-main-tab-btn:hover,.customer-main-tab-btn.active{background:linear-gradient(135deg,#047857,#059669);color:#fff;border-color:#047857}.customer-main-tab-badge{min-width:22px;height:22px;padding:0 7px;border-radius:999px;background:#ef4444;color:#fff;font-size:12px;font-weight:800;display:inline-flex;align-items:center;justify-content:center;line-height:1;box-shadow:0 2px 6px rgba(239,68,68,.25)}.customer-main-tab-btn.active .customer-main-tab-badge{background:#fff;color:#047857}.customer-main-tab-pane{display:none}.customer-main-tab-pane.active{display:block}#creditRequestsTabContent .table-container{background:#fff;border-radius:12px;overflow:auto;box-shadow:0 1px 3px rgba(0,0,0,.08)}#creditRequestsTabContent .custom-table{margin-bottom:0;min-width:980px}#creditRequestsTabContent .custom-table thead th{background:#052A47;color:#fff;font-size:12px;white-space:nowrap}#creditRequestsTabContent .custom-table tbody td{vertical-align:middle;font-size:13px}#creditRequestsTabContent .request-row{cursor:pointer}#creditRequestsTabContent .request-row:hover{background:#f8fafc}

/* Parent */
.sidebar .nav-link{
    position:relative;
}
.sidebar-parent-icon {
    position: relative;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 24px;
    height: 24px;
    flex: 0 0 24px;
}

.task-parent-badge {
    position: absolute;
    top: -10px;
    right: -3px;

    min-width: 17px;
    height: 17px;
    padding: 0 4px;

    border-radius: 999px;
    background: #ef4444;
    color: #fff;

    font-size: 10px;
    font-weight: 700;
    line-height: 1;

    display: inline-flex;
    align-items: center;
    justify-content: center;

    z-index: 30;
    pointer-events: none;
    box-sizing: border-box;
}

/* Badge sa Tasks child kapag open ang dropdown */
.task-child-badge {
    margin-left: auto;
    min-width: 18px;
    height: 18px;
    padding: 0 5px;
    border-radius: 999px;
    background: #ef4444;
    color: #fff;
    font-size: 11px;
    font-weight: 700;
    line-height: 1;
    display: none;
    align-items: center;
    justify-content: center;
}

/* Closed dropdown: parent badge visible */
.employees-dropdown .task-parent-badge {
    display: inline-flex;
}

/* Open dropdown: parent badge hidden */
.employees-dropdown.employees-menu-open .task-parent-badge {
    display: none;
}

/* Open dropdown: Tasks badge visible */
.employees-dropdown.employees-menu-open .task-child-badge {
    display: inline-flex;
}

/* Allow badge to extend outside icon */
.employees-dropdown > .nav-link,
.sidebar-parent-icon {
    overflow: visible !important;
}
</style>
</head>
<body>
    <div id="loadingOverlay" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(255,255,255,.8);z-index:9999;justify-content:center;align-items:center;"><div class="spinner-border text-primary" style="width:3rem;height:3rem;" role="status"><span class="visually-hidden">Loading...</span></div></div>
    <iframe id="printFrame" name="printFrame" style="position:absolute;left:-9999px;top:-9999px;width:1px;height:1px;opacity:0;pointer-events:none;"></iframe>
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
                <!-- Dashboard -->
                <li class="nav-item">
                    <a class="nav-link" href="branchdashboard.php">
                        <i class="bi bi-speedometer2"></i>
                        <span class="nav-text">Dashboard</span>
                    </a>
                </li>
                <!-- Tasks -->
                <li class="nav-item">
                    <a class="nav-link" href="tasks.php">
                        <i class="bi bi-calendar-check"></i>
                        <span class="nav-text">Tasks</span>
                    </a>
                </li>
                <!-- Vendor Dropdown -->
                <li class="nav-item dropdown-nav">
                    <a class="nav-link" href="#" onclick="toggleSidebarDropdown(event, 'supplierMenu')">
                        <i class="bi bi-building"></i>
                        <span class="nav-text">Vendor</span>
                        <i class="bi bi-chevron-down dropdown-arrow"></i>
                    </a>

                    <div class="collapse" id="supplierMenu">
                        <ul class="nav flex-column ps-4">
                            <li class="nav-item">
                                <a class="nav-link" href="purchase_order.php">
                                    <i class="bi bi-file-earmark-text"></i>
                                    <span class="nav-text">Enter Bills</span>
                                </a>
                            </li>

                            <li class="nav-item">
                                <a class="nav-link" href="paybills.php">
                                    <i class="bi bi-currency-dollar"></i>
                                    <span class="nav-text">Pay Bills</span>
                                </a>
                            </li>

                            <li class="nav-item">
                                <a class="nav-link" href="supplier.php">
                                    <i class="bi bi-shop"></i>
                                    <span class="nav-text">Vendor List</span>
                                </a>
                            </li>
                        </ul>
                    </div>
                </li>

                <!-- Customer Dropdown -->
                <li class="nav-item dropdown-nav">
                    <a class="nav-link" href="#" onclick="toggleSidebarDropdown(event, 'customerMenu')">
                        <i class="bi bi-people-fill"></i>
                        <span class="nav-text">Customers</span>
                        <i class="bi bi-chevron-down dropdown-arrow"></i>
                    </a>

                    <div class="collapse" id="customerMenu">
                        <ul class="nav flex-column ps-4">

                            <li class="nav-item">
                                <a class="nav-link" href="orderproduct.php">
                                    <i class="bi bi-receipt"></i>
                                    <span class="nav-text">Create Invoice</span>
                                </a>
                            </li>

                            <li class="nav-item">
                                <a class="nav-link" href="collections.php">
                                    <i class="bi bi-cash-stack"></i>
                                    <span class="nav-text">Receive Payment</span>
                                </a>
                            </li>
                            
                            <li class="nav-item">
                                <a class="nav-link active" href="customer_list.php">
                                    <i class="bi bi-person-badge"></i>
                                    <span class="nav-text">Customer List</span>
                                </a>
                            </li>
                        </ul>
                    </div>
                </li>

                <!-- Employees Dropdown -->
                <li class="nav-item dropdown-nav employees-dropdown">
                    <a class="nav-link"
                    href="#"
                    onclick="toggleSidebarDropdown(event, 'employeesMenu')">

                        <span class="sidebar-parent-icon">
                            <i class="bi bi-briefcase"></i>

                            <?php if ($task_badge_count > 0): ?>
                                <span class="task-parent-badge">
                                    <?= $task_badge_count ?>
                                </span>
                            <?php endif; ?>
                        </span>

                        <span class="nav-text">Employees</span>
                        <i class="bi bi-chevron-down dropdown-arrow"></i>
                    </a>

                    <div class="collapse" id="employeesMenu">
                        <ul class="nav flex-column ps-4">
                            <li class="nav-item">
                                <a class="nav-link" href="employeelist.php">
                                    <i class="bi bi-person-badge"></i>
                                    <span class="nav-text">Employee List</span>
                                </a>
                            </li>

                            <li class="nav-item">
                                <a class="nav-link" href="employee.php">
                                    <i class="bi bi-clock-history"></i>
                                    <span class="nav-text">Enter Time</span>
                                </a>
                            </li>

                            <li class="nav-item">
                                <a class="nav-link" href="tasks.php">
                                    <i class="bi bi-calendar-check"></i>
                                    <span class="nav-text">Tasks</span>

                                    <?php if ($task_badge_count > 0): ?>
                                        <span class="task-child-badge">
                                            <?= $task_badge_count ?>
                                        </span>
                                    <?php endif; ?>
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
                                    <i class="bi bi-bank"></i>
                                    <span class="nav-text">Record Deposit</span>
                                </a>
                            </li>

                            <li class="nav-item">
                                <a class="nav-link" href="Withdrawal.php">
                                    <i class="bi bi-journal-check"></i>
                                    <span class="nav-text">Write Checks</span>
                                </a>
                            </li>

                            <li class="nav-item">
                                <a class="nav-link" href="bank_statement.php">
                                    <i class="bi bi-receipt"></i>
                                    <span class="nav-text">Bank Statement</span>
                                </a>
                            </li>

                            <li class="nav-item" hidden>
                                <a class="nav-link" href="expenses.php">
                                    <i class="bi bi-cash-stack"></i>
                                    <span class="nav-text">Expenses</span>
                                </a>
                            </li>
                        </ul>
                    </div>
                </li>

                <!-- Company Dropdown -->
                <li class="nav-item dropdown-nav">
                    <a class="nav-link" href="#" onclick="toggleSidebarDropdown(event, 'warehouseMenu')">
                        <i class="bi bi-building"></i>
                        <span class="nav-text">Company</span>
                        <i class="bi bi-chevron-down dropdown-arrow"></i>
                    </a>

                    <div class="collapse" id="warehouseMenu">
                        <ul class="nav flex-column ps-4">
                            <li class="nav-item">
                                <a class="nav-link" href="current_inventory.php">
                                    <i class="bi bi-box"></i>
                                    <span class="nav-text">Items</span>
                                </a>
                            </li>
                            
                             <li class="nav-item">
                                <a class="nav-link" href="fixed_assets.php">
                                    <i class="bi bi-building"></i>
                                    <span class="nav-text">Fixed Assets</span>
                                </a>
                            </li>

                            <li class="nav-item">
                                <a class="nav-link" href="warehouses.php">
                                    <i class="bi bi-shop"></i>
                                    <span class="nav-text">Warehouses</span>
                                </a>
                            </li>

                            <li class="nav-item">
                                <a class="nav-link" href="chartofaccounts.php">
                                    <i class="bi bi-graph-up"></i>
                                    <span class="nav-text">Chart of Accounts</span>
                                </a>
                            </li>

                            <li class="nav-item" hidden>
                                <a class="nav-link" href="trip_tickets.php">
                                    <i class="bi bi-ticket-perforated"></i>
                                    <span class="nav-text">Trip Tickets</span>
                                </a>
                            </li>

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

                            <li class="nav-item">
                                <a class="nav-link" href="drivers.php">
                                    <i class="bi bi-people-fill"></i>
                                    <span class="nav-text">Users</span>
                                </a>
                            </li>
                        </ul>
                    </div>
                </li>
                <!-- Accounting Dropdown -->
                <li class="nav-item dropdown-nav">
                    <a class="nav-link" href="#" onclick="toggleSidebarDropdown(event, 'accountingMenu')">
                        <i class="bi bi-graph-up"></i>
                        <span class="nav-text">Accounting</span>
                        <i class="bi bi-chevron-down dropdown-arrow"></i>
                    </a>

                    <div class="collapse" id="accountingMenu">
                        <ul class="nav flex-column ps-4">
                            <li class="nav-item">
                                <a class="nav-link" href="journal_entries.php">
                                    <i class="bi bi-journal"></i>
                                    <span class="nav-text">Journal Entries</span>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" href="batch_transaction.php">
                                    <i class="bi bi-collection"></i>
                                    <span class="nav-text">Batch Transaction</span>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" href="item_adjustment.php">
                                    <i class="bi bi-sliders"></i>
                                    <span class="nav-text">Item Adjusment</span>
                                </a>
                            </li>
                        </ul>
                    </div>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="it_request.php">
                        <i class="bi bi-headset"></i>
                        <span class="nav-text">IT Requests</span>
                    </a>
                </li>
            </ul>
        </div>
    </div>

    <div class="sidebar-footer">
        <div class="user-profile-sidebar">
            <div class="user-avatar-sidebar">
                <?php echo htmlspecialchars($user_initials); ?>
            </div>

            <div class="user-details-sidebar">
                <span class="user-name-sidebar">
                    <?php echo htmlspecialchars($user_name); ?>
                </span>

                <span class="user-role-sidebar">
                    <?php echo htmlspecialchars(ucfirst($user_role)); ?>
                </span>
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
        <div class="page-content active">
            <!-- Navbar Top -->
            <div class="navbar-top">
                <button class="mobile-menu-btn" id="mobileMenuBtn">
                    <i class="bi bi-list"></i>
                </button>
                <div class="page-title">
                    <h2>Customer List</h2>
                    <p>Manage all customers</p>
                </div>
            </div>

            <!-- Main Tabs -->
            <div class="customer-main-tabs" id="customerMainTabs">
                <button type="button" class="customer-main-tab-btn active" data-tab="customerListTabContent" onclick="switchCustomerMainTab('customerListTabContent')"><i class="bi bi-person-badge"></i> Customer List</button>
                <button type="button" class="customer-main-tab-btn" data-tab="creditRequestsTabContent" onclick="switchCustomerMainTab('creditRequestsTabContent')"><i class="bi bi-pencil-square"></i> Approve Credit Request <?php if ((int)$pending_requests > 0): ?><span class="customer-main-tab-badge" id="pendingRequestsBadge"><?= (int)$pending_requests ?></span><?php endif; ?></button>
            </div>

            <div class="customer-main-tab-pane active" id="customerListTabContent">
            <!-- Filter Section -->
            <div class="filter-section">
                <div class="search-box">
                    <i class="bi bi-search"></i>
                    <input type="text" id="searchInput" placeholder="Search by name, code, email, phone, group..." onkeyup="filterCustomers()">
                </div>
                <div class="action-buttons-top">
                    <?php if ($customers_branch_column_exists && $view_all_branches): ?>
                    <select id="branchFilter" class="form-select" onchange="filterCustomers()">
                        <option value="all">All Branches</option>
                        <?php
                        $branches_query = "SELECT branch_id, branch_name FROM branches ORDER BY branch_name";
                        $branches_result = $conn->query($branches_query);
                        while ($branch = $branches_result->fetch_assoc()):
                        ?>
                        <option value="<?= $branch['branch_id'] ?>"><?= htmlspecialchars($branch['branch_name']) ?></option>
                        <?php endwhile; ?>
                    </select>
                    <?php endif; ?>
                </div>
            </div>

            <!-- CUSTOMER ACTION BUTTONS -->
            <div class="add-button-wrapper">
                <div class="add-button-left">
                    <button class="btn-primary-custom" onclick="showCustomerGroupsModal()">
                        <i class="bi bi-collection"></i> Customer Groups
                    </button>
                </div>
                <div class="add-button-right">
                    <button class="btn-primary-custom" onclick="openOrderProductFlexible()">
                        <i class="bi bi-cart-plus"></i> Place Order
                    </button>
                    <button class="btn-primary-custom" onclick="showAddCustomerModal()">
                        <i class="bi bi-plus-lg"></i> Add New Customer
                    </button>
                </div>
            </div>

            <!-- Customer Group Tabs -->
            <div class="customer-view-tabs" id="customerGroupTabs">
                <button type="button" class="customer-tab-btn active" data-group-filter="all" onclick="switchCustomerTab('all')">
                    <i class="bi bi-people"></i> All
                </button>
                <?php foreach ($customer_groups as $group_name): ?>
                    <?php $group_key = mb_strtolower(trim($group_name), 'UTF-8'); ?>
                    <button type="button" class="customer-tab-btn" data-group-filter="<?php echo htmlspecialchars($group_key); ?>" onclick="switchCustomerTab('<?php echo htmlspecialchars($group_key, ENT_QUOTES); ?>')">
                        <i class="bi bi-collection"></i> <?php echo htmlspecialchars($group_name); ?>
                    </button>
                <?php endforeach; ?>
            </div>

            <!-- Customer Cards Container -->
            <div class="customer-cards-container" id="customerCardsContainer">
                <?php if (count($customers) > 0): ?>
                    <?php foreach ($customers as $customer): ?>
                        <?php
                        $status_class = match($customer['status']) {
                            'active' => 'status-active',
                            'inactive' => 'status-inactive',
                            'pending' => 'status-pending',
                            default => 'status-active'
                        };
                        $phone = !empty($customer['phone_number'] ?? '');
                        $is_walkin = ($customer['customer_code'] === 'WALKIN-001');
                        $card_class = $is_walkin ? 'customer-card walkin-card' : 'customer-card';
                        ?>
                        <div class="<?php echo $card_class; ?>" 
                             data-customer-id="<?php echo $customer['customer_id']; ?>" 
                             data-customer-name="<?php echo htmlspecialchars($customer['customer_name']); ?>" 
                             data-customer-phone="<?php echo htmlspecialchars($phone); ?>" 
                             data-customer-code="<?php echo htmlspecialchars($customer['customer_code']); ?>" 
                             data-customer-status="<?php echo $customer['status']; ?>" 
                             data-customer-group="<?php echo htmlspecialchars($customer['customer_group'] ?? ''); ?>"
                             data-customer-group-key="<?php echo htmlspecialchars(mb_strtolower(trim($customer['customer_group'] ?? ''), 'UTF-8')); ?>"
                             data-customer-branch="<?php echo $customer['branch_id'] ?? ''; ?>"
                             data-is-walkin="<?php echo $is_walkin ? 'true' : 'false'; ?>">
                            <div class="card-top">
                                <span class="customer-code">
                                    <?php echo htmlspecialchars($customer['customer_code']); ?>
                                    <?php if ($is_walkin): ?>
                                        <span class="walkin-badge">Walk-in</span>
                                    <?php endif; ?>
                                </span>
                                <span class="status-badge <?php echo $status_class; ?>">
                                    <?php echo ucfirst($customer['status']); ?>
                                </span>
                            </div>
                            <div class="customer-name">
                                <?php echo htmlspecialchars($customer['customer_name']); ?>
                                <?php if ($is_walkin): ?>
                                    <i class="bi bi-person-walking" style="color: #f59e0b; font-size: 0.9rem;"></i>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($customer['customer_group'] ?? '')): ?>
                            <div class="customer-group-badge">
                                <i class="bi bi-collection"></i> <?php echo htmlspecialchars($customer['customer_group']); ?>
                            </div>
                            <?php endif; ?>
                            <div class="customer-phone">
                                <i class="bi bi-telephone"></i> <?php echo htmlspecialchars($phone ?: 'No phone number'); ?>
                            </div>
                            <div class="customer-address">
                                <i class="bi bi-geo-alt"></i> <?php 
                                $display_address = $customer['address'] ?? '';
                                $short_address = strlen($display_address) > 40 ? substr($display_address, 0, 37) . '...' : $display_address;
                                echo htmlspecialchars($short_address ?: 'No address');
                                ?>
                            </div>
                            <div class="card-bottom">
                                <span class="orders-count">
                                    <i class="bi bi-bag"></i> <?php echo $customer['total_orders'] ?? 0; ?> orders
                                </span>
                                <button class="btn-view" onclick="viewCustomerDetails(<?php echo $customer['customer_id']; ?>)">
                                    tap to view <i class="bi bi-chevron-right"></i>
                                </button>
                            </div>
                            <div class="card-actions">
                                <?php if (!$is_walkin): ?>
                                    <button class="icon-btn edit-btn" onclick="editCustomer(<?php echo $customer['customer_id']; ?>)" title="Edit">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                <?php endif; ?>
                                <?php if (!empty($phone) && !$is_walkin): ?>
                                    <a href="tel:<?php echo htmlspecialchars($phone); ?>" class="icon-btn call-btn" title="Call">
                                        <i class="bi bi-telephone"></i>
                                    </a>
                                <?php endif; ?>
                                <button class="icon-btn cart-btn" onclick="orderProduct(<?php echo $customer['customer_id']; ?>, '<?php echo htmlspecialchars(addslashes($customer['customer_name'])); ?>', <?php echo $is_walkin ? 'true' : 'false'; ?>)" title="Order">
                                    <i class="bi bi-cart"></i>
                                </button>
                                <?php if (!$is_walkin): ?>
                                    <button class="icon-btn delete-btn" onclick="deleteCustomer(<?php echo $customer['customer_id']; ?>)" title="Delete">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                <?php endif; ?>
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

            <!-- Dynamic group tabs filter the cards above -->
            </div>

            <div class="customer-main-tab-pane" id="creditRequestsTabContent">
                <!-- Table Missing Alert -->
                <?php if (!$credit_requests_table_exists): ?>
                    <div class="alert alert-info alert-dismissible fade show" role="alert">
                        <i class="bi bi-info-circle"></i> 
                        <strong>The <code>credit_discount_requests</code> table does not exist.</strong> 
                        <br><br>
                        <button type="button" class="btn btn-sm btn-primary" onclick="copySQL()">
                            <i class="bi bi-files"></i> Copy SQL
                        </button>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Add effective columns alert if missing -->
                <?php if ($credit_requests_table_exists && !$effective_columns_exist): ?>
                    <div class="alert alert-warning alert-dismissible fade show" role="alert">
                        <i class="bi bi-exclamation-triangle"></i> 
                        <strong>Database update recommended!</strong> The <code>effective_from</code> and <code>effective_until</code> columns are missing.
                        Please run the SQL below to add expiration date tracking:
                        <br><br>
                        <code class="small">ALTER TABLE `credit_discount_requests` ADD COLUMN `effective_from` datetime DEFAULT NULL AFTER `admin_notes`, ADD COLUMN `effective_until` datetime DEFAULT NULL AFTER `effective_from`;</code>
                        <br><br>
                        <button type="button" class="btn btn-sm btn-primary" onclick="copyEffectiveSQL()">
                            <i class="bi bi-files"></i> Copy SQL
                        </button>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Attachments Table Missing Alert -->
                <?php if ($credit_requests_table_exists && !$attachments_table_exists): ?>
                    <div class="alert alert-warning alert-dismissible fade show" role="alert">
                        <i class="bi bi-exclamation-triangle"></i> 
                        <strong>Attachments table missing!</strong> The <code>credit_discount_attachments</code> table does not exist.
                        Please run the SQL below to add attachment support:
                        <br><br>
                        <code class="small">CREATE TABLE `credit_discount_attachments` (
  `attachment_id` int(11) NOT NULL AUTO_INCREMENT,
  `request_id` int(11) NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `original_file_name` varchar(255) NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `file_size` int(11) DEFAULT NULL,
  `file_type` varchar(100) DEFAULT NULL,
  `uploaded_by` int(11) NOT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`attachment_id`),
  KEY `request_id` (`request_id`),
  KEY `uploaded_by` (`uploaded_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;</code>
                        <br><br>
                        <button type="button" class="btn btn-sm btn-primary" onclick="copyAttachmentsSQL()">
                            <i class="bi bi-files"></i> Copy SQL
                        </button>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Stats Section -->
                <div class="row stat-card-row g-1 g-sm-2 mb-4">
                    <!-- Stat 1: Total Requests -->
                    <div class="col">
                        <div class="stat-card total">
                            <i class="bi bi-inbox stat-icon"></i>
                            <div class="stat-content">
                                <div class="stat-value"><?= $total_requests ?></div>
                                <div class="stat-label">Total Requests</div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Stat 2: Pending -->
                    <div class="col">
                        <div class="stat-card pending">
                            <i class="bi bi-clock-history stat-icon"></i>
                            <div class="stat-content">
                                <div class="stat-value"><?= $pending_requests ?></div>
                                <div class="stat-label">Pending</div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Stat 3: Approved -->
                    <div class="col">
                        <div class="stat-card approved">
                            <i class="bi bi-check-circle stat-icon"></i>
                            <div class="stat-content">
                                <div class="stat-value"><?= $approved_requests ?></div>
                                <div class="stat-label">Approved</div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Stat 4: Rejected -->
                    <div class="col">
                        <div class="stat-card rejected">
                            <i class="bi bi-x-circle stat-icon"></i>
                            <div class="stat-content">
                                <div class="stat-value"><?= $rejected_requests ?></div>
                                <div class="stat-label">Rejected</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- FILTER SECTION - DEFAULT VISIBLE -->
                <div class="form-card mb-2" id="filterCard">
                    <div class="filter-content always-visible" id="filterContent">
                        <div class="request-filter-grid">
                            <div class="filter-field filter-field-status">
                                <label class="form-label">
                                    <i class="bi bi-flag"></i> Status
                                </label>
                                <select class="form-select" id="statusFilter" onchange="filterRequests()">
                                    <option value="all">All Status</option>
                                    <option value="pending">Pending</option>
                                    <option value="approved">Approved</option>
                                    <option value="rejected">Rejected</option>
                                </select>
                            </div>

                            <div class="filter-field filter-field-type">
                                <label class="form-label">
                                    <i class="bi bi-tag"></i> Type
                                </label>
                                <select class="form-select" id="typeFilter" onchange="filterRequests()">
                                    <option value="all">All Types</option>
                                    <option value="credit">Credit Limit</option>
                                    <option value="discount">Discount</option>
                                    <option value="both">Credit & Discount</option>
                                    <option value="credit_terms">Credit Terms</option>
                                </select>
                            </div>

                            <div class="filter-field filter-field-search">
                                <label class="form-label">
                                    <i class="bi bi-search"></i> Search
                                </label>
                                <div class="search-box-wrapper">
                                    <input type="text" class="form-control" id="creditSearchInput" placeholder="Search customer, agent, reason..." onkeyup="filterRequests()">
                                </div>
                            </div>

                            <div class="filter-actions request-filter-actions">
                                <button class="btn-primary-custom filter-action-primary" type="button" onclick="printRequests()">
                                    <i class="bi bi-printer"></i> Print
                                </button>
                                <button class="btn-primary-custom filter-action-primary" type="button" onclick="exportToExcel()">
                                    <i class="bi bi-file-earmark-excel"></i> Export
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Requests Table -->
                <div class="table-container">
                    <table class="table custom-table compact-table" id="requestsTable">
                        <thead>
                            <tr>
                                <th class="col-date">DATE</th>
                                <th class="col-customer">CUSTOMER</th>
                                <th class="col-agent">AGENT</th>
                                <th class="col-type">TYPE</th>
                                <th class="col-credit">CREDIT (₱)</th>
                                <th class="col-discount">DISCOUNT (%)</th>
                                <th class="col-terms">TERMS (DAYS)</th>
                                <th class="col-status">STATUS</th>
                                <th class="col-validity">VALIDITY</th>
                            </tr>
                        </thead>
                        <tbody id="requestsTableBody">
                            <?php if (empty($requests) && $credit_requests_table_exists): ?>
                            <tr>
                                <td colspan="9" class="empty-state-table">
                                    <i class="bi bi-inbox"></i>
                                    <h5>No Requests Found</h5>
                                    <p class="text-muted">There are no credit/discount requests to display.</p>
                                </td>
                            </tr>
                            <?php elseif (!$credit_requests_table_exists): ?>
                            <tr>
                                <td colspan="9" class="empty-state-table">
                                    <i class="bi bi-database"></i>
                                    <h5>Table Missing</h5>
                                    <p class="text-muted">The credit_discount_requests table does not exist.</p>
                                </td>
                            </tr>
                            <?php else: ?>
                                <?php foreach ($requests as $req): 
                                    $type_class = match($req['request_type']) {
                                        'credit' => 'type-credit',
                                        'discount' => 'type-discount',
                                        'both' => 'type-both',
                                        'credit_terms' => 'type-credit_terms',
                                        default => 'type-credit'
                                    };
                                    $is_expired = isRequestExpired($req['effective_until']);
                                    $status_display = $req['status'];
                                    if ($req['status'] === 'approved' && $is_expired) {
                                        $status_display = 'Expired';
                                    }
                                    $attachments = $attachments_by_request[$req['request_id']] ?? [];
                                ?>
                                <tr class="request-row" 
                                    data-id="<?= $req['request_id'] ?>"
                                    data-status="<?= $req['status'] ?>"
                                    data-type="<?= $req['request_type'] ?>"
                                    data-customer="<?= htmlspecialchars($req['customer_name']) ?>"
                                    data-agent="<?= htmlspecialchars($req['agent_name'] ?? '') ?>"
                                    data-credit="<?= $req['requested_credit_limit'] ?>"
                                    data-discount="<?= $req['requested_discount_percent'] ?>"
                                    data-terms="<?= $req['credit_terms_days'] ?>">
                                    <td class="col-date"><?= creditFormatDate($req['created_at']) ?></td>
                                    <td class="col-customer">
                                        <strong><?= htmlspecialchars($req['customer_name']) ?></strong>
                                        <br><small class="text-muted"><?= htmlspecialchars($req['customer_code']) ?></small>
                                    </td>
                                    <td class="col-agent"><?= htmlspecialchars($req['agent_name'] ?? 'N/A') ?></td>
                                    <td class="col-type">
                                        <span class="type-badge <?= $type_class ?>">
                                            <?= getRequestTypeText($req['request_type']) ?>
                                        </span>
                                    </td>
                                    <td class="col-credit">
                                        <?= $req['requested_credit_limit'] ? '₱' . number_format($req['requested_credit_limit'], 2) : '—' ?>
                                    </td>
                                    <td class="col-discount">
                                        <?= $req['requested_discount_percent'] ? $req['requested_discount_percent'] . '%' : '—' ?>
                                    </td>
                                    <td class="col-terms">
                                        <?= $req['credit_terms_days'] ? $req['credit_terms_days'] . ' days' : '—' ?>
                                    </td>
                                    <td class="col-status">
                                        <span class="status-badge <?= $req['status'] === 'approved' && $is_expired ? 'status-expired' : getRequestStatusClass($req['status']) ?>">
                                            <?= $status_display ?>
                                        </span>
                                    </td>
                                    <td class="col-validity">
                                        <?php if ($req['status'] === 'approved' && $req['effective_until']): ?>
                                            <small>Until:<br><?= date('M d, Y', strtotime($req['effective_until'])) ?></small>
                                        <?php elseif ($req['status'] === 'approved'): ?>
                                            <small>No expiry set</small>
                                        <?php else: ?>
                                            —
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- View Request Modal -->
<div class="modal fade" id="viewRequestModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-eye me-2"></i>Request Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="viewRequestContent">
                <!-- Content will be loaded here -->
            </div>
            <div class="modal-footer" id="modalFooterActions">
                <!-- Approve/Reject buttons will be dynamically added here for pending requests -->
            </div>
        </div>
    </div>
</div>
    <!-- Approve/Reject Modal -->
    <div class="modal fade" id="approvalModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header" id="approvalModalHeader">
                    <h5 class="modal-title" id="approvalModalTitle">Approve Request</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p id="approvalMessage">Approve this request?</p>
                    <?php if (!$view_all_branches && $customers_branch_column_exists): ?>
                       
                    <?php endif; ?>
                    <div id="approvalFields">
                        <div class="mb-3">
                            <label class="form-label">Admin Notes</label>
                            <textarea class="form-control" id="adminNotes" rows="3" placeholder="Enter any notes..."></textarea>
                        </div>
                        <div class="mb-3 expiration-slider">
                            <label class="form-label fw-bold">Validity Period (Days)</label>
                            <div class="expiration-number-wrap">
                                <div class="input-group expiration-number-input">
                                    <input 
                                        type="number" 
                                        class="form-control" 
                                        id="expirationDays" 
                                        min="1" 
                                        max="365" 
                                        value="30"
                                        oninput="updateExpirationValue(this.value)"
                                    >
                                    <span class="input-group-text">days</span>
                                </div>
                                <span class="expiration-value" id="expirationValue">30 days</span>
                            </div>
                            <small class="text-muted">Set how many days this approval will be valid. The customer can only use this until the expiration date.</small>
                        </div>
                    </div>
                    <div id="rejectionFields" style="display: none;">
                        <div class="mb-3">
                            <label class="form-label">Rejection Reason *</label>
                            <textarea class="form-control" id="rejectionReason" rows="3" placeholder="Enter reason for rejection..."></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-success" id="approveBtn" onclick="confirmApproval('approve')">Approve</button>
                    <button type="button" class="btn btn-danger" id="rejectBtn" onclick="confirmApproval('reject')" style="display: none;">Reject</button>
                </div>
            </div>
        </div>
    </div>

   <!-- File Preview Modal -->
<div class="modal fade" id="fileViewModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content bg-transparent border-0 shadow-none">
            <div class="modal-body p-0">
                <div class="attachment-container">
                    <div class="attachment-wrapper">
                        <!-- Close button -->
                        <button type="button" class="btn-close-attachment" data-bs-dismiss="modal" aria-label="Close">
                            <i class="bi bi-x-lg"></i>
                        </button>
                        <!-- Download button -->
                        <a href="#" id="downloadLink" class="btn-download-attachment" download>
                            <i class="bi bi-download"></i>
                        </a>
                        <!-- Content will be loaded here -->
                        <div class="attachment-content">
                            <div class="spinner-border text-light" role="status">
                                <span class="visually-hidden">Loading...</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>


   <!-- Mobile Bottom Navigation - Clean Version (No Arrows) -->
<div class="mobile-nav" id="mobileNav">
    <?php 
    $current_page = basename($_SERVER['PHP_SELF']);
    $is_warehouse_page = in_array($current_page, ['current_inventory.php', 'bad_orders.php', 'pick_list_items.php', 'warehouses.php']);
    $is_supplier_page = in_array($current_page, ['purchase_order.php', 'supplier.php']);
    $is_customer_page = in_array($current_page, ['customer_list.php', 'approve_credit_requests.php', 'sales_order.php', 'collections.php']);
    $is_delivery_page = ($current_page == 'trip_tickets.php');
    $is_banking_page = in_array($current_page, ['deposit.php', 'Withdrawal.php', 'bank_statement.php', 'expenses.php']);
    ?>
    <ul class="nav">
        <!-- Dashboard -->
        <li class="nav-item">
            <a class="nav-link <?php echo ($current_page == 'branchdashboard.php') ? 'active' : ''; ?>" href="branchdashboard.php">
                <i class="bi bi-speedometer2"></i>
                <span>Dashboard</span>
            </a>
        </li>

        <!-- Warehouse Dropdown -->
        <li class="nav-item dropdown-more" id="warehouseMobileDropdown">
            <a class="nav-link more-btn <?php echo $is_warehouse_page ? 'active' : ''; ?>" href="#" onclick="toggleMobileDropdown(event, 'warehouseMobileMenu')">
                <i class="bi bi-shop"></i>
                <span>Warehouse</span>
            </a>
            <div class="more-dropdown" id="warehouseMobileMenu">
                <a href="current_inventory.php" class="dropdown-item <?php echo ($current_page == 'current_inventory.php') ? 'active' : ''; ?>">
                    <i class="bi bi-bar-chart-line"></i><span>Current Inventory</span>
                </a>
                <a href="bad_orders.php" class="dropdown-item <?php echo ($current_page == 'bad_orders.php') ? 'active' : ''; ?>">
                    <i class="bi bi-recycle"></i><span>Bad Orders</span>
                </a>
                <a href="pick_list_items.php" class="dropdown-item <?php echo ($current_page == 'pick_list_items.php') ? 'active' : ''; ?>">
                    <i class="bi bi-list-check"></i><span>Pick List Items</span>
                </a>
                <a href="warehouses.php" class="dropdown-item <?php echo ($current_page == 'warehouses.php') ? 'active' : ''; ?>">
                    <i class="bi bi-shop"></i><span>Warehouses</span>
                </a>
            </div>
        </li>

        <!-- Supplier Dropdown -->
        <li class="nav-item dropdown-more" id="supplierMobileDropdown">
            <a class="nav-link more-btn <?php echo $is_supplier_page ? 'active' : ''; ?>" href="#" onclick="toggleMobileDropdown(event, 'supplierMobileMenu')">
                <i class="bi bi-building"></i>
                <span>Supplier</span>
            </a>
            <div class="more-dropdown" id="supplierMobileMenu">
                <a href="purchase_order.php" class="dropdown-item <?php echo ($current_page == 'purchase_order.php') ? 'active' : ''; ?>">
                    <i class="bi bi-file-earmark-text"></i><span>Enter Bills</span>
                </a>
                <a href="supplier.php" class="dropdown-item <?php echo ($current_page == 'supplier.php') ? 'active' : ''; ?>">
                    <i class="bi bi-people"></i><span>Supplier List</span>
                </a>
            </div>
        </li>

        <!-- Customer Dropdown -->
        <li class="nav-item dropdown-more" id="customerMobileDropdown">
            <a class="nav-link more-btn <?php echo $is_customer_page ? 'active' : ''; ?>" href="#" onclick="toggleMobileDropdown(event, 'customerMobileMenu')">
                <i class="bi bi-people"></i>
                <span>Customer</span>
            </a>
            <div class="more-dropdown" id="customerMobileMenu">
                <a href="customer_list.php" class="dropdown-item <?php echo ($current_page == 'customer_list.php') ? 'active' : ''; ?>">
                    <i class="bi bi-person-badge"></i><span>Customer List</span>
                </a>
                <a href="approve_credit_requests.php" class="dropdown-item <?php echo ($current_page == 'approve_credit_requests.php') ? 'active' : ''; ?>">
                    <i class="bi bi-pencil-square"></i><span>Approve Credit Request</span>
                </a>
                <a href="sales_order.php" class="dropdown-item <?php echo ($current_page == 'sales_order.php') ? 'active' : ''; ?>">
                    <i class="bi bi-cart"></i><span>Sales Order</span>
                </a>
                <a href="collections.php" class="dropdown-item <?php echo ($current_page == 'collections.php') ? 'active' : ''; ?>">
                    <i class="bi bi-cash-stack"></i><span>Collections</span>
                </a>
            </div>
        </li>

        <!-- Delivery Dropdown -->
        <li class="nav-item dropdown-more" id="deliveryMobileDropdown">
            <a class="nav-link more-btn <?php echo $is_delivery_page ? 'active' : ''; ?>" href="#" onclick="toggleMobileDropdown(event, 'deliveryMobileMenu')">
                <i class="bi bi-truck"></i>
                <span>Delivery</span>
            </a>
            <div class="more-dropdown" id="deliveryMobileMenu">
                <a href="trip_tickets.php" class="dropdown-item <?php echo ($current_page == 'trip_tickets.php') ? 'active' : ''; ?>">
                    <i class="bi bi-ticket-perforated"></i><span>Trip Tickets</span>
                </a>
            </div>
        </li>

        <!-- Banking Dropdown -->
        <li class="nav-item dropdown-more" id="bankingMobileDropdown">
            <a class="nav-link more-btn <?php echo $is_banking_page ? 'active' : ''; ?>" href="#" onclick="toggleMobileDropdown(event, 'bankingMobileMenu')">
                <i class="bi bi-bank2"></i>
                <span>Banking</span>
            </a>
            <div class="more-dropdown" id="bankingMobileMenu">
                <a href="deposit.php" class="dropdown-item <?php echo ($current_page == 'deposit.php') ? 'active' : ''; ?>">
                    <i class="bi bi-arrow-down-circle"></i><span>Deposit</span>
                </a>
                <a href="Withdrawal.php" class="dropdown-item <?php echo ($current_page == 'Withdrawal.php') ? 'active' : ''; ?>">
                    <i class="bi bi-arrow-up-circle"></i><span>Withdrawal</span>
                </a>
                <a href="bank_statement.php" class="dropdown-item <?php echo ($current_page == 'bank_statement.php') ? 'active' : ''; ?>">
                    <i class="bi bi-receipt"></i><span>Bank Statement</span>
                </a>
                <a href="expenses.php" class="dropdown-item <?php echo ($current_page == 'expenses.php') ? 'active' : ''; ?>">
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
            <a class="nav-link <?php echo ($current_page == 'drivers.php') ? 'active' : ''; ?>" href="drivers.php">
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


    <!-- ADD CUSTOMER MODAL (Based on customer.php but NO MAP) -->
    <div class="modal fade" id="addCustomerModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary-custom">
                    <h5 class="modal-title">
                        <i class="bi bi-person-plus-fill"></i> Add New Customer
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form id="addCustomerForm" onsubmit="return false;" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="add_customer">
                    <?php if ($customers_branch_column_exists && !$view_all_branches && $branch_id > 0): ?>
                        <input type="hidden" name="branch_id" value="<?= $branch_id ?>">
                    <?php endif; ?>
                    <div class="modal-body">
                        <!-- Customer Code -->
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
                                <small class="text-muted">This code will be automatically generated</small>
                            </div>
                        </div>
                        
                        <!-- Customer Name & Contact Person -->
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">
                                    <i class="bi bi-person-badge"></i> Customer Name <span class="text-danger">*</span>
                                </label>
                                <input type="text" class="form-control" name="customer_name" id="addCustomerName" required placeholder="Enter full name">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">
                                    <i class="bi bi-person-circle"></i> Contact Person
                                </label>
                                <input type="text" class="form-control" name="contact_person" id="addContactPerson" placeholder="Enter contact person name">
                            </div>
                        </div>
                        
                        <!-- Email & Phone -->
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">
                                    <i class="bi bi-envelope"></i> Email
                                </label>
                                <input type="email" class="form-control" name="email" id="addEmail" placeholder="customer@example.com">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">
                                    <i class="bi bi-telephone"></i> Phone Number
                                </label>
                                <input type="tel" class="form-control" name="phone_number" id="addPhoneNumber" placeholder="+63 XXX XXX XXXX">
                            </div>
                        </div>
                        
                        <!-- Store Information -->
                        <h6 class="form-section-title">
                            <i class="bi bi-shop"></i> Store Information
                        </h6>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">
                                    <i class="bi bi-building"></i> Store Name
                                </label>
                                <input type="text" class="form-control" name="store_name" id="addStoreName" placeholder="Store or business name">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">
                                    <i class="bi bi-image"></i> Store Image
                                </label>
                                <input type="file" class="form-control" name="store_image" accept="image/*">
                                <small class="text-muted">JPG, PNG, GIF or WebP (Max 5MB)</small>
                            </div>
                        </div>
                        
                        <!-- Price Level -->
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">
                                    <i class="bi bi-tag"></i> Price Level
                                </label>
                                <select class="form-select" name="price_level" id="addPriceLevel">
                                    <option value="Standard">Standard</option>
                                    <?php foreach ($price_levels as $level): ?>
                                        <?php if ($level !== 'Standard'): ?>
                                            <option value="<?php echo htmlspecialchars($level); ?>"><?php echo htmlspecialchars($level); ?></option>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </select>
                                <small class="text-muted">Select applicable price level for this customer</small>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">
                                    <i class="bi bi-collection"></i> Customer Group
                                </label>
                                <select class="form-select customer-group-select" id="addCustomerGroupSelect" onchange="toggleCustomerGroupInput('add')">
                                    <option value="">Select Customer Group</option>
                                    <?php foreach ($customer_groups as $group_name): ?>
                                        <option value="<?php echo htmlspecialchars($group_name); ?>"><?php echo htmlspecialchars($group_name); ?></option>
                                    <?php endforeach; ?>
                                    <option value="__new__">+ Add New Group</option>
                                </select>
                                <input type="text" class="form-control mt-2 d-none" name="customer_group" id="addCustomerGroup" placeholder="Type new customer group">
                                <small class="text-muted">Choose an existing group or add a new one.</small>
                            </div>
                        </div>
                        
                        <!-- Address Information -->
                        <h6 class="form-section-title">
                            <i class="bi bi-geo-alt"></i> Address Information
                        </h6>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">
                                    <i class="bi bi-map"></i> Region
                                </label>
                                <select class="form-select region-select" id="addRegion" name="region">
                                    <option value="">Select Region</option>
                                    <?php foreach ($regions as $region_code => $region_name): ?>
                                        <option value="<?php echo $region_code; ?>"><?php echo $region_name; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">
                                    <i class="bi bi-pin-map"></i> Province
                                </label>
                                <select class="form-select province-select" id="addProvince" name="province" disabled>
                                    <option value="">Select Province</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">
                                    <i class="bi bi-building"></i> City/Municipality
                                </label>
                                <select class="form-select city-select" id="addCity" name="city" disabled>
                                    <option value="">Select City/Municipality</option>
                                </select>
                                <input type="hidden" name="city_code" id="cityCode" value="">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">
                                    <i class="bi bi-house"></i> Barangay
                                </label>
                                <div id="barangayFieldContainer">
                                    <select class="form-select barangay-select" name="barangay" disabled>
                                        <option value="">Select City/Municipality first</option>
                                    </select>
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
                        
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="bi bi-x-lg"></i> Cancel
                        </button>
                        <button type="submit" class="btn btn-primary" onclick="saveAddCustomer()">
                            <i class="bi bi-save"></i> Add Customer
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    


    <!-- CUSTOMER GROUPS MODAL -->
    <div class="modal fade" id="customerGroupsModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header bg-primary-custom">
                    <h5 class="modal-title">
                        <i class="bi bi-collection"></i> Customer Groups
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info d-flex gap-2 align-items-start">
                        <i class="bi bi-info-circle mt-1"></i>
                        <div>
                            Edit the customer group name here if there is a typo. All customers under the selected group will be updated automatically.
                        </div>
                    </div>

                    <div class="table-responsive customer-groups-table-scroll">
                        <table class="table table-hover align-middle">
                            <thead>
                                <tr>
                                    <th>Customer Group</th>
                                    <th class="text-center" style="width: 140px;">Customers</th>
                                    <th class="text-end" style="width: 120px;">Action</th>
                                </tr>
                            </thead>
                            <tbody id="customerGroupsTableBody">
                                <?php if (count($customer_groups) > 0): ?>
                                    <?php foreach ($customer_groups as $group_name): ?>
                                        <?php
                                            $group_count_key = mb_strtolower(trim($group_name), 'UTF-8');
                                            $group_customer_count = $customer_group_counts[$group_count_key] ?? 0;
                                        ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($group_name); ?></strong>
                                            </td>
                                            <td class="text-center">
                                                <span class="badge bg-light text-dark border"><?php echo number_format($group_customer_count); ?></span>
                                            </td>
                                            <td class="text-end">
                                                <button type="button"
                                                        class="btn btn-sm btn-success customer-group-edit-btn"
                                                        onclick='openEditCustomerGroup(<?php echo json_encode($group_name); ?>)'>
                                                    <i class="bi bi-pencil-square"></i> Edit
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr id="noCustomerGroupsRow">
                                        <td colspan="3" class="text-center py-4 text-muted">
                                            <i class="bi bi-collection fs-1 d-block mb-2"></i>
                                            No customer groups found
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer customer-groups-footer">
                    <button type="button" class="btn btn-success customer-group-new-btn" onclick="addNewCustomerGroupRow()">
                        <i class="bi bi-plus-circle"></i> New Customer Group
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- EDIT CUSTOMER MODAL (Based on customer.php but NO MAP editing) -->
    <div class="modal fade" id="editCustomerModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary-custom">
                    <h5 class="modal-title">
                        <i class="bi bi-pencil-square"></i> Edit Customer
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form id="editCustomerForm" onsubmit="return false;" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="update_customer">
                    <input type="hidden" name="customer_id" id="editCustomerId">
                    <input type="hidden" name="existing_store_image" id="existingStoreImage">
                    <div class="modal-body">
                        <!-- Customer Code (readonly) -->
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Customer Code</label>
                                <input type="text" class="form-control" id="editCustomerCode" readonly style="background-color: #e9ecef;">
                                <small class="text-muted">Customer code cannot be changed</small>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Customer Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="customer_name" id="editCustomerName" required>
                            </div>
                        </div>
                        
                        <!-- Contact Person, Email, Phone -->
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Contact Person</label>
                                <input type="text" class="form-control" name="contact_person" id="editContactPerson">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Email</label>
                                <input type="email" class="form-control" name="email" id="editEmail">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Phone Number</label>
                                <input type="tel" class="form-control" name="phone_number" id="editPhoneNumber">
                            </div>
                        </div>
                        
                        <!-- Store Information -->
                        <h6 class="form-section-title">Store Information</h6>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Store Name</label>
                                <input type="text" class="form-control" name="store_name" id="editStoreName">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Store Image</label>
                                <input type="file" class="form-control" name="store_image" accept="image/*">
                                <div id="editStoreImagePreview" class="mt-2"></div>
                                <small class="text-muted">Leave empty to keep current image</small>
                            </div>
                        </div>
                        
                        <!-- Price Level and Customer Group -->
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Price Level</label>
                                <select class="form-select" name="price_level" id="editPriceLevel">
                                    <option value="Standard">Standard</option>
                                    <?php foreach ($price_levels as $level): ?>
                                        <?php if ($level !== 'Standard'): ?>
                                            <option value="<?php echo htmlspecialchars($level); ?>"><?php echo htmlspecialchars($level); ?></option>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Customer Group</label>
                                <select class="form-select customer-group-select" id="editCustomerGroupSelect" onchange="toggleCustomerGroupInput('edit')">
                                    <option value="">Select Customer Group</option>
                                    <?php foreach ($customer_groups as $group_name): ?>
                                        <option value="<?php echo htmlspecialchars($group_name); ?>"><?php echo htmlspecialchars($group_name); ?></option>
                                    <?php endforeach; ?>
                                    <option value="__new__">+ Add New Group</option>
                                </select>
                                <input type="text" class="form-control mt-2 d-none" name="customer_group" id="editCustomerGroup" placeholder="Type new customer group">
                                <small class="text-muted">Choose an existing group or add a new one.</small>
                            </div>
                        </div>
                        
                        <!-- Address Information -->
                        <h6 class="form-section-title">Address Information</h6>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Region</label>
                                <select class="form-select region-select-edit" id="editRegion" name="region">
                                    <option value="">Select Region</option>
                                    <?php foreach ($regions as $region_code => $region_name): ?>
                                        <option value="<?php echo $region_code; ?>"><?php echo $region_name; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Province</label>
                                <select class="form-select province-select-edit" id="editProvince" name="province" disabled>
                                    <option value="">Select Province</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">City/Municipality</label>
                                <select class="form-select city-select-edit" id="editCity" name="city" disabled>
                                    <option value="">Select City/Municipality</option>
                                </select>
                                <input type="hidden" name="city_code" id="editCityCode" value="">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Barangay</label>
                                <div id="editBarangayFieldContainer">
                                    <select class="form-select barangay-select-edit" name="barangay" disabled>
                                        <option value="">Select City/Municipality first</option>
                                    </select>
                                </div>
                                <div class="manual-toggle-btn" id="manualBarangayToggleEdit" style="display: none;">
                                    <i class="bi bi-pencil-square"></i> Can't find barangay? Click to type manually
                                </div>
                                <small class="text-muted api-status-edit"></small>
                            </div>
                        </div>
                        
                        <!-- Status -->
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Status</label>
                                <select class="form-select" name="status" id="editStatus">
                                    <option value="active">Active</option>
                                    <option value="pending">Pending</option>
                                    <option value="inactive">Inactive</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-primary" onclick="saveEditCustomer()">Update Customer</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- VIEW CUSTOMER MODAL -->
    <div class="modal fade" id="viewCustomerModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary-custom">
                    <h5 class="modal-title">Customer Details</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="viewCustomerContent"></div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-warning" id="editFromViewBtn" onclick="editFromView()">Edit Customer</button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- DELETE CONFIRMATION MODAL -->
    <div class="modal fade" id="deleteCustomerModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title">Confirm Delete</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to delete this customer?</p>
                    <p class="fw-bold" id="deleteCustomerName"></p>
                    <div class="alert alert-warning">If this customer has existing sales orders, it will be deactivated instead.</div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-danger" onclick="confirmDeleteCustomer()">Delete</button>
                </div>
            </div>
        </div>
    </div>

<!-- Order Details Modal -->
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
                <button type="button" class="btn btn-primary" id="printOrderFromDetails" style="display: none;" onclick="printOrderFromCustomer()">Print Order</button>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

    <!-- Leaflet JS -->
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    
   <script>
    // Global variables
    let currentCustomerId = null;
    let deleteCustomerId = null;
    let viewMap = null;
    let editMap = null;
    const branchId = <?php echo $branch_id; ?>;
    const viewAllBranches = <?php echo $view_all_branches ? 'true' : 'false'; ?>;
    const customersBranchColumnExists = <?php echo $customers_branch_column_exists ? 'true' : 'false'; ?>;
    

    function showCustomerGroupsModal() {
        const modalElement = document.getElementById('customerGroupsModal');
        if (!modalElement) return;
        cleanupNewCustomerGroupRow();
        const modal = bootstrap.Modal.getOrCreateInstance(modalElement);
        modal.show();
    }

    function cleanupNewCustomerGroupRow() {
        const row = document.getElementById('newCustomerGroupRow');
        if (row) row.remove();

        const tbody = document.getElementById('customerGroupsTableBody');
        if (tbody && tbody.children.length === 0) {
            tbody.innerHTML = `
                <tr id="noCustomerGroupsRow">
                    <td colspan="3" class="text-center py-4 text-muted">
                        <i class="bi bi-collection fs-1 d-block mb-2"></i>
                        No customer groups found
                    </td>
                </tr>
            `;
        }
    }

    const customerGroupsModalElementForReset = document.getElementById('customerGroupsModal');
    if (customerGroupsModalElementForReset) {
        customerGroupsModalElementForReset.addEventListener('hidden.bs.modal', function () {
            cleanupNewCustomerGroupRow();
        });
    }

    let hasNewCustomerGroupRow = false;

    function addNewCustomerGroupRow() {
        const tbody = document.getElementById('customerGroupsTableBody');
        if (!tbody) return;

        const existingInput = document.getElementById('newCustomerGroupRowInput');
        if (existingInput) {
            existingInput.focus();
            return;
        }

        const emptyRow = document.getElementById('noCustomerGroupsRow');
        if (emptyRow) emptyRow.remove();

        const row = document.createElement('tr');
        row.id = 'newCustomerGroupRow';
        row.className = 'customer-group-new-row';
        row.innerHTML = `
            <td>
                <input type="text" id="newCustomerGroupRowInput" class="form-control customer-group-inline-input" placeholder="Type new customer group" maxlength="100" autocomplete="off">
            </td>
            <td class="text-center">
                <span class="badge bg-light text-dark border">0</span>
            </td>
            <td class="text-end">
                <button type="button" class="btn btn-sm btn-success customer-group-save-btn" onclick="saveNewCustomerGroupRow()">
                    <i class="bi bi-check2"></i> Save
                </button>
            </td>
        `;
        tbody.appendChild(row);

        const input = document.getElementById('newCustomerGroupRowInput');
        if (input) {
            input.focus();
            input.addEventListener('keydown', function(event) {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    saveNewCustomerGroupRow();
                }
            });
        }
    }

    function saveNewCustomerGroupRow() {
        const input = document.getElementById('newCustomerGroupRowInput');
        const groupName = input ? input.value.trim() : '';

        if (!groupName) {
            Swal.fire('Error', 'Customer group name is required.', 'error');
            if (input) input.focus();
            return;
        }

        if (groupName.length > 100) {
            Swal.fire('Error', 'Customer group name must not exceed 100 characters.', 'error');
            if (input) input.focus();
            return;
        }

        const formData = new FormData();
        formData.append('action', 'create_customer_group');
        formData.append('new_group', groupName);

        fetch(window.location.href, {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const row = document.getElementById('newCustomerGroupRow');
                if (row) {
                    row.className = '';
                    row.innerHTML = `
                        <td><strong>${escapeHtml(groupName)}</strong></td>
                        <td class="text-center"><span class="badge bg-light text-dark border">0</span></td>
                        <td class="text-end">
                            <button type="button" class="btn btn-sm btn-success customer-group-edit-btn" onclick='openEditCustomerGroup(${JSON.stringify(groupName)})'>
                                <i class="bi bi-pencil-square"></i> Edit
                            </button>
                        </td>
                    `;
                    row.removeAttribute('id');
                }

                const showSuccessThenRefresh = () => {
                    Swal.fire({
                        icon: 'success',
                        title: 'Added',
                        text: data.message || 'Customer group added successfully.',
                        confirmButtonColor: '#047857',
                        confirmButtonText: 'OK'
                    }).then(() => {
                        window.location.reload();
                    });
                };

                const customerGroupsModalEl = document.getElementById('customerGroupsModal');
                const customerGroupsModal = customerGroupsModalEl ? bootstrap.Modal.getOrCreateInstance(customerGroupsModalEl) : null;

                if (customerGroupsModal && customerGroupsModalEl.classList.contains('show')) {
                    customerGroupsModalEl.addEventListener('hidden.bs.modal', function showAddedAlertOnce() {
                        customerGroupsModalEl.removeEventListener('hidden.bs.modal', showAddedAlertOnce);
                        showSuccessThenRefresh();
                    });
                    customerGroupsModal.hide();
                } else {
                    showSuccessThenRefresh();
                }
            } else {
                Swal.fire('Error', data.message || 'Failed to add customer group.', 'error');
            }
        })
        .catch(error => {
            console.error('Customer group create error:', error);
            Swal.fire('Error', 'Something went wrong while adding the customer group.', 'error');
        });
    }

    function openEditCustomerGroup(oldGroupName) {
        const currentName = String(oldGroupName || '').trim();

        if (!currentName) {
            Swal.fire('Error', 'Invalid customer group name.', 'error');
            return;
        }

        const customerGroupsModalEl = document.getElementById('customerGroupsModal');
        const customerGroupsModal = customerGroupsModalEl ? bootstrap.Modal.getOrCreateInstance(customerGroupsModalEl) : null;

        // Hide the Bootstrap modal first. This removes the Bootstrap focus trap
        // that prevents typing inside the SweetAlert input.
        if (customerGroupsModal) {
            customerGroupsModal.hide();
        }

        setTimeout(() => {
            Swal.fire({
                title: 'Edit Customer Group',
                html: `
                    <div class="text-start">
                        <label class="form-label">Current Customer Group</label>
                        <input type="text" class="form-control mb-3" value="${escapeHtml(currentName)}" readonly>
                        <label class="form-label">New Customer Group</label>
                        <input type="text" id="newCustomerGroupName" class="form-control" value="${escapeHtml(currentName)}" maxlength="100" autocomplete="off">
                        <small class="text-muted d-block mt-2">This will update all customers under this group.</small>
                    </div>
                `,
                showCancelButton: true,
                confirmButtonText: 'Save Changes',
                cancelButtonText: 'Cancel',
                confirmButtonColor: '#047857',
                allowOutsideClick: false,
                didOpen: () => {
                    const input = document.getElementById('newCustomerGroupName');
                    if (input) {
                        input.removeAttribute('readonly');
                        input.disabled = false;
                        input.focus();
                        input.select();
                    }
                },
                preConfirm: () => {
                    const input = document.getElementById('newCustomerGroupName');
                    const newName = input ? input.value.trim() : '';

                    if (!newName) {
                        Swal.showValidationMessage('Customer group name is required.');
                        return false;
                    }

                    if (newName.length > 100) {
                        Swal.showValidationMessage('Customer group name must not exceed 100 characters.');
                        return false;
                    }

                    return newName;
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    saveCustomerGroupEdit(currentName, result.value);
                } else if (customerGroupsModal) {
                    customerGroupsModal.show();
                }
            });
        }, 250);
    }

    function saveCustomerGroupEdit(oldGroupName, newGroupName) {
        const formData = new FormData();
        formData.append('action', 'update_customer_group');
        formData.append('old_group', oldGroupName);
        formData.append('new_group', newGroupName);

        Swal.fire({
            title: 'Saving...',
            text: 'Updating customer group name.',
            allowOutsideClick: false,
            allowEscapeKey: false,
            didOpen: () => Swal.showLoading()
        });

        fetch(window.location.href, {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                Swal.fire({
                    icon: 'success',
                    title: 'Updated',
                    text: data.message || 'Customer group updated successfully.',
                    confirmButtonColor: '#047857'
                }).then(() => {
                    window.location.reload();
                });
            } else {
                Swal.fire('Error', data.message || 'Failed to update customer group.', 'error');
            }
        })
        .catch(error => {
            console.error('Customer group update error:', error);
            Swal.fire('Error', 'Something went wrong while updating the customer group.', 'error');
        });
    }

    // Philippine location data
    const provincesByRegion = <?php echo json_encode($provinces); ?>;
    const citiesByProvince = <?php echo json_encode($cities); ?>;
    
    // City code cache
    // IMPORTANT: Some municipalities have duplicate names across provinces.
    // Example: Lemery exists in Batangas and Iloilo, so never resolve city code by name only.
    let cityCodeCache = null;
    let cityCodeList = [];


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


    function normalizeLocationName(value) {
        return String(value || '')
            .toLowerCase()
            .replace(/\s+/g, ' ')
            .replace(/ city$/i, '')
            .replace(/ municipality$/i, '')
            .trim();
    }

    function getSelectedProvinceForMode(mode = 'add') {
        const provinceSelect = document.getElementById(mode === 'edit' ? 'editProvince' : 'addProvince');
        return provinceSelect ? (provinceSelect.value || '') : '';
    }

    function getSelectedRegionForMode(mode = 'add') {
        const regionSelect = document.getElementById(mode === 'edit' ? 'editRegion' : 'addRegion');
        return regionSelect ? (regionSelect.value || '') : '';
    }



    // Local barangay override for Lemery, Batangas.
    // PSGC may return the wrong Lemery when code lookup is ambiguous, so this list is used first.
    const localBarangaysByMunicipality = {
        'batangas|lemery': [
            'Anak-Dagat', 'Arumahan', 'Ayao-iyao', 'Bagong Pook', 'Bagong Sikat', 'Balanga', 'Bukal',
            'Cahilan I', 'Cahilan II', 'Dayapan', 'Dita', 'Gulod', 'Lucky', 'Maguihan', 'Mahabang Dahilig',
            'Mahayahay', 'Maigsing Dahilig', 'Maligaya', 'Malinis', 'Masalisi', 'Mataas Na Bayan',
            'Matingain I', 'Matingain II', 'Mayasang', 'Niugan', 'Nonong Casto', 'Palanas',
            'Payapa Ibaba', 'Payapa Ilaya', 'District I', 'District II', 'District III', 'District IV',
            'Rizal', 'Sambal Ibaba', 'Sambal Ilaya', 'San Isidro Ibaba', 'San Isidro Itaas',
            'Sangalang', 'Talaga', 'Tubigan', 'Tubuan', 'Wawa Ibaba', 'Wawa Ilaya',
            'Sinisian East', 'Sinisian West'
        ],
        'batangas|lipa': [
            'Poblacion Barangay 1', 'Poblacion Barangay 2', 'Poblacion Barangay 3', 'Poblacion Barangay 4',
            'Poblacion Barangay 5', 'Poblacion Barangay 6', 'Poblacion Barangay 7', 'Poblacion Barangay 8',
            'Poblacion Barangay 9', 'Barangay 10', 'Barangay 11', 'Barangay 12', 'Adya', 'Anilao',
            'Anilao-Labac', 'Antipolo del Norte', 'Antipolo del Sur', 'Bagong Pook', 'Balintawak',
            'Banaybanay', 'Bolbok', 'Bugtong na Pulo', 'Bulacnin', 'Bulaklakan', 'Calamias', 'Cumba',
            'Dagatan', 'Duhatan', 'Halang', 'Inosloban', 'Kayumanggi', 'Latag', 'Lodlod', 'Lumbang',
            'Mabini', 'Malagonlong', 'Malitlit', 'Marauoy', 'Mataas na Lupa', 'Munting Pulo',
            'Pagolingin Bata', 'Pagolingin East', 'Pagolingin West', 'Pangao', 'Pinagkawitan',
            'Pinagtongulan', 'Plaridel', 'Quezon', 'Rizal', 'Sabang', 'Sampaguita', 'San Benito',
            'San Carlos', 'San Celestino', 'San Francisco', 'San Guillermo', 'San Jose', 'San Lucas',
            'San Salvador', 'San Sebastian', 'Santo Niño', 'Santo Toribio', 'Sapac', 'Sico', 'Talisay',
            'Tambo', 'Tangob', 'Tanguay', 'Tibig', 'Tipacan'
        ]
    };

    function getLocalBarangaysForLocation(provinceName, cityName) {
        const key = `${normalizeLocationName(provinceName)}|${normalizeLocationName(cityName)}`;
        return localBarangaysByMunicipality[key] || null;
    }

    function populateBarangaySelect(selectElem, barangays, selectedBarangay = '') {
        if (!selectElem || !barangays || !barangays.length) return false;
        selectElem.innerHTML = '<option value="">Select Barangay</option>';
        barangays.forEach(name => {
            const option = document.createElement('option');
            option.value = name;
            option.textContent = name;
            if (selectedBarangay && normalizeLocationName(selectedBarangay) === normalizeLocationName(name)) {
                option.selected = true;
            }
            selectElem.appendChild(option);
        });
        selectElem.disabled = false;
        selectElem.onchange = function() {
            if (typeof updateAddressPreview === 'function') {
                updateAddressPreview();
            }
        };
        return true;
    }

    function getCityCodeFromCache(cityName, provinceName = '', regionName = '') {
        if (!cityCodeList || !cityCodeList.length || !cityName) return '';

        const cityKey = normalizeLocationName(cityName);
        const provinceKey = normalizeLocationName(provinceName);
        const regionKey = normalizeLocationName(regionName);

        let matches = cityCodeList.filter(city => city.nameKey === cityKey);

        if (provinceKey) {
            const provinceMatches = matches.filter(city => city.provinceKey === provinceKey);
            if (provinceMatches.length === 1) return provinceMatches[0].code;
            if (provinceMatches.length > 1) matches = provinceMatches;
        }

        if (regionKey) {
            const regionMatches = matches.filter(city => city.regionKey === regionKey || city.regionCodeKey === regionKey);
            if (regionMatches.length === 1) return regionMatches[0].code;
        }

        // Safe fallback only when the city/municipality name is unique in PSGC data.
        return matches.length === 1 ? matches[0].code : '';
    }
    
    // Show loading
    function showLoading() {
        if (typeof Swal !== 'undefined') {
            Swal.fire({
                title: 'Processing...',
                text: 'Please wait',
                allowOutsideClick: false,
                allowEscapeKey: false,
                showConfirmButton: false,
                didOpen: () => Swal.showLoading()
            });
        } else {
            const overlay = document.getElementById('loadingOverlay');
            if (overlay) overlay.style.display = 'flex';
        }
    }

    function hideLoading() {
        const overlay = document.getElementById('loadingOverlay');
        if (overlay) overlay.style.display = 'none';
        if (typeof Swal !== 'undefined') Swal.close();
    }
    
    // Load city codes
    function loadCityCodes() {
        fetch('https://psgc.gitlab.io/api/cities-municipalities.json')
            .then(response => response.json())
            .then(cities => {
                cityCodeCache = {};
                cityCodeList = [];

                cities.forEach(city => {
                    const nameKey = normalizeLocationName(city.name);
                    const provinceKey = normalizeLocationName(city.provinceName || '');
                    const regionKey = normalizeLocationName(city.regionName || '');
                    const regionCodeKey = normalizeLocationName(city.regionCode || '');

                    const cityRecord = {
                        code: city.code,
                        name: city.name,
                        nameKey,
                        provinceName: city.provinceName || '',
                        provinceKey,
                        regionName: city.regionName || '',
                        regionKey,
                        regionCode: city.regionCode || '',
                        regionCodeKey
                    };

                    cityCodeList.push(cityRecord);

                    if (!cityCodeCache[nameKey]) cityCodeCache[nameKey] = [];
                    cityCodeCache[nameKey].push(cityRecord);

                    if (provinceKey) {
                        cityCodeCache[`${provinceKey}|${nameKey}`] = cityRecord.code;
                    }
                    if (provinceKey && regionCodeKey) {
                        cityCodeCache[`${regionCodeKey}|${provinceKey}|${nameKey}`] = cityRecord.code;
                    }
                });
                console.log('City codes loaded with province-aware matching');
            })
            .catch(error => console.error('Failed to load city codes:', error));
    }
    
    // Convert barangay select to manual input (Add Modal)
    function convertToManualBarangay(message) {
        const container = document.getElementById('barangayFieldContainer');
        const toggleBtn = document.getElementById('manualBarangayToggle');
        if (!container) return;
        const existingSelect = container.querySelector('select');
        if (!existingSelect) return;
        
        const input = document.createElement('input');
        input.type = 'text';
        input.className = 'form-control';
        input.name = 'barangay';
        input.id = 'barangayInput';
        input.placeholder = 'Enter Barangay name';
        input.required = false;
        
        const helpText = document.createElement('small');
        helpText.className = 'text-muted d-block mt-1';
        helpText.innerHTML = message || '⚠ No data available. Please enter manually.';
        
        container.innerHTML = '';
        container.appendChild(input);
        container.appendChild(helpText);
        if (toggleBtn) toggleBtn.style.display = 'none';
        input.addEventListener('input', updateAddressPreview);
    }
    
    // Convert barangay select to manual input (Edit Modal)
    function convertToManualBarangayEdit(message, value = '') {
        const container = document.getElementById('editBarangayFieldContainer');
        const toggleBtn = document.getElementById('manualBarangayToggleEdit');
        if (!container) return;
        const existingSelect = container.querySelector('select');
        if (!existingSelect) return;
        
        const input = document.createElement('input');
        input.type = 'text';
        input.className = 'form-control';
        input.name = 'barangay';
        input.id = 'editBarangayInput';
        input.placeholder = 'Enter Barangay name';
        input.value = value || '';
        
        const helpText = document.createElement('small');
        helpText.className = 'text-muted d-block mt-1';
        helpText.innerHTML = message || '⚠ No data available. Please enter manually.';
        
        container.innerHTML = '';
        container.appendChild(input);
        container.appendChild(helpText);
        if (toggleBtn) toggleBtn.style.display = 'none';
    }
    
    // Convert back to select (Add Modal)
    function convertToSelectBarangay() {
        const container = document.getElementById('barangayFieldContainer');
        const toggleBtn = document.getElementById('manualBarangayToggle');
        if (!container) return;
        
        const select = document.createElement('select');
        select.className = 'form-select barangay-select';
        select.name = 'barangay';
        select.required = false;
        select.disabled = true;
        select.innerHTML = '<option value="">Select City/Municipality first</option>';
        
        container.innerHTML = '';
        container.appendChild(select);
        if (toggleBtn) toggleBtn.style.display = 'none';
    }
    
    // Update address preview
    function updateAddressPreview() {
        const regionSelect = document.getElementById('addRegion');
        const provinceSelect = document.getElementById('addProvince');
        const citySelect = document.getElementById('addCity');
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
    
    // Initialize location dropdowns for Add Modal
    function initAddLocationDropdowns() {
        const regionSelect = document.getElementById('addRegion');
        const provinceSelect = document.getElementById('addProvince');
        const citySelect = document.getElementById('addCity');
        const cityCodeInput = document.getElementById('cityCode');
        const apiStatus = document.querySelector('.api-status');
        const loadingSpinner = document.querySelector('.loading-spinner');
        const toggleBtn = document.getElementById('manualBarangayToggle');
        
        if (!regionSelect || !provinceSelect || !citySelect) return;
        
        provinceSelect.innerHTML = '<option value="">Select Province</option>';
        citySelect.innerHTML = '<option value="">Select City/Municipality</option>';
        provinceSelect.disabled = true;
        citySelect.disabled = true;
        
        convertToSelectBarangay();
        
        regionSelect.onchange = function() {
            const region = this.value;
            provinceSelect.innerHTML = '<option value="">Select Province</option>';
            citySelect.innerHTML = '<option value="">Select City/Municipality</option>';
            citySelect.disabled = true;
            if (cityCodeInput) cityCodeInput.value = '';
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
        };
        
        provinceSelect.onchange = function() {
            const province = this.value;
            citySelect.innerHTML = '<option value="">Loading cities...</option>';
            citySelect.disabled = true;
            if (cityCodeInput) cityCodeInput.value = '';
            convertToSelectBarangay();
            if (toggleBtn) toggleBtn.style.display = 'none';
            
            if (!province) {
                citySelect.innerHTML = '<option value="">Select City/Municipality</option>';
                return;
            }
            
            fetch('https://psgc.gitlab.io/api/cities-municipalities.json')
                .then(response => response.json())
                .then(allCities => {
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
                    } else if (citiesByProvince[province]) {
                        citiesByProvince[province].forEach(city => {
                            const option = document.createElement('option');
                            option.value = city;
                            option.textContent = city;
                            citySelect.appendChild(option);
                        });
                        if (apiStatus) apiStatus.textContent = '⚠ Using local data';
                        citySelect.disabled = false;
                    } else {
                        citySelect.innerHTML = '<option value="">No cities found</option>';
                        if (apiStatus) apiStatus.textContent = '✗ No city data available';
                    }
                })
                .catch(error => {
                    console.error('Error loading cities:', error);
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
                    }
                });
            updateAddressPreview();
        };
        
        // City change handler - may fallback sa local data
citySelect.addEventListener('change', function() {
    const selectedOption = this.options[this.selectedIndex];
    const cityName = this.value;
    const cityCode = selectedOption.dataset?.code;
    const barangaySelect = document.querySelector('.barangay-select');
    const toggleBtn = document.getElementById('manualBarangayToggle');
    const loadingSpinner = document.querySelector('.loading-spinner');
    const apiStatus = document.querySelector('.api-status');
    const cityCodeInputElem = document.getElementById('cityCode');
    
    if (!barangaySelect) return;
    
    // RESET AGAD - clear loading state
    barangaySelect.innerHTML = '<option value="">Loading barangays...</option>';
    barangaySelect.disabled = true;
    
    if (loadingSpinner) loadingSpinner.style.display = 'inline-block';
    if (apiStatus) apiStatus.textContent = 'Fetching barangays...';
    if (toggleBtn) toggleBtn.style.display = 'none';
    
    if (!cityName) {
        barangaySelect.innerHTML = '<option value="">Select City/Municipality first</option>';
        barangaySelect.disabled = true;
        if (loadingSpinner) loadingSpinner.style.display = 'none';
        if (toggleBtn) toggleBtn.style.display = 'none';
        updateAddressPreview();
        return;
    }
    
    const localBarangays = getLocalBarangaysForLocation(getSelectedProvinceForMode('add'), cityName);
    if (localBarangays) {
        populateBarangaySelect(barangaySelect, localBarangays);
        if (cityCodeInputElem) cityCodeInputElem.value = '';
        if (loadingSpinner) loadingSpinner.style.display = 'none';
        if (toggleBtn) toggleBtn.style.display = 'none';
        if (apiStatus) apiStatus.textContent = `✓ ${localBarangays.length} barangays loaded from local ${getSelectedProvinceForMode('add')}, ${cityName} list`;
        updateAddressPreview();
        return;
    }

    // I-DISPLAY AGAD ANG MANUAL OPTION PARA MAY CHOICE ANG USER
    if (toggleBtn) {
        toggleBtn.style.display = 'block';
        toggleBtn.onclick = function() {
            convertToManualBarangay('Manual entry mode - please type barangay name');
            if (apiStatus) apiStatus.textContent = '⌨️ Manual entry mode';
        };
    }
    
    // Set timeout para kung matagal ang API
    const timeoutId = setTimeout(() => {
        if (loadingSpinner && loadingSpinner.style.display === 'inline-block') {
            barangaySelect.innerHTML = '<option value="">API timeout - use manual entry</option>';
            barangaySelect.disabled = true;
            if (toggleBtn) toggleBtn.style.display = 'block';
            if (loadingSpinner) loadingSpinner.style.display = 'none';
            if (apiStatus) apiStatus.textContent = '⚠ API timeout - click manual entry';
            updateAddressPreview();
        }
    }, 8000);
    
    function handleBarangaySuccess(barangays, source) {
        clearTimeout(timeoutId);
        barangaySelect.innerHTML = '<option value="">Select Barangay</option>';
        
        if (!barangays || barangays.length === 0) {
            convertToManualBarangay(`No barangays found (${source}). Please type barangay manually.`);
            if (apiStatus) apiStatus.textContent = `⚠ No barangays found (${source}) - manual entry enabled`;
        } else {
            barangays.sort((a, b) => (a.name || a).localeCompare(b.name || b));
            
            barangays.forEach(item => {
                const name = item.name || item;
                const option = document.createElement('option');
                option.value = name;
                option.textContent = name;
                barangaySelect.appendChild(option);
            });
            
            barangaySelect.disabled = false;
            barangaySelect.onchange = updateAddressPreview;
            if (toggleBtn) toggleBtn.style.display = 'block';
            if (apiStatus) apiStatus.textContent = `✓ ${barangays.length} barangays loaded from ${source}`;
        }
        
        // IMPORTANTE: SIGURADUHING NAAALIS ANG LOADING SPINNER
        if (loadingSpinner) {
            loadingSpinner.style.display = 'none';
        }
        
        updateAddressPreview();
    }
    
    function handleBarangayError(error) {
        clearTimeout(timeoutId);
        console.error('Error loading barangays:', error);
        barangaySelect.innerHTML = '<option value="">Failed to load - use manual entry</option>';
        barangaySelect.disabled = true;
        
        // IMPORTANTE: SIGURADUHING NAAALIS ANG LOADING SPINNER
        if (loadingSpinner) {
            loadingSpinner.style.display = 'none';
        }
        
        if (apiStatus) apiStatus.textContent = '✗ Failed to load barangays - use manual entry';
        if (toggleBtn) toggleBtn.style.display = 'block';
        updateAddressPreview();
    }
    
    // ===== SUBUKAN ANG PSGC API =====
    if (cityCode) {
        fetch(`https://psgc.gitlab.io/api/cities-municipalities/${cityCode}/barangays.json`)
            .then(response => {
                if (!response.ok) throw new Error('HTTP error ' + response.status);
                return response.json();
            })
            .then(barangays => handleBarangaySuccess(barangays, 'PSGC'))
            .catch(error => handleBarangayError(error));
    } 
    // ===== FALLBACK: GUMAMIT NG LOCAL BARANGAY DATA =====
    else if (cityCodeCache) {
        const selectedProvince = getSelectedProvinceForMode('add');
        const selectedRegion = getSelectedRegionForMode('add');
        const foundCode = getCityCodeFromCache(cityName, selectedProvince, selectedRegion);
        
        if (foundCode) {
            if (cityCodeInputElem) cityCodeInputElem.value = foundCode;
            fetch(`https://psgc.gitlab.io/api/cities-municipalities/${foundCode}/barangays.json`)
                .then(response => response.json())
                .then(barangays => handleBarangaySuccess(barangays, 'PSGC (province matched)'))
                .catch(error => handleBarangayError(error));
        } else {
            // WALANG CODE - OFFER MANUAL ENTRY AGAD
            barangaySelect.innerHTML = '<option value="">No PSGC data - use manual entry</option>';
            barangaySelect.disabled = true;
            if (loadingSpinner) loadingSpinner.style.display = 'none';
            if (apiStatus) apiStatus.textContent = '⚠ No PSGC code found - use manual entry';
            if (toggleBtn) toggleBtn.style.display = 'block';
            updateAddressPreview();
        }
    } 
    else {
        // WALANG CITY CODE CACHE - OFFER MANUAL ENTRY AGAD
        barangaySelect.innerHTML = '<option value="">City code not available - use manual entry</option>';
        barangaySelect.disabled = true;
        if (loadingSpinner) loadingSpinner.style.display = 'none';
        if (apiStatus) apiStatus.textContent = '⚠ City code not available - use manual entry';
        if (toggleBtn) toggleBtn.style.display = 'block';
        updateAddressPreview();
    }
});
        
        // I-SHOW AGAD ANG MANUAL TOGGLE BUTTON PARA MAY OPTION ANG USER
        if (toggleBtn) {
            toggleBtn.style.display = 'block';
            toggleBtn.onclick = function() {
                convertToManualBarangay('Manual entry mode - please type barangay name');
                if (apiStatus) apiStatus.textContent = '⌨️ Manual entry mode';
            };
        }
    }
    
    // Initialize location dropdowns for Edit Modal
    function initEditLocationDropdowns(selectedRegion, selectedProvince, selectedCity, selectedBarangay, selectedCityCode) {
        const regionSelect = document.getElementById('editRegion');
        const provinceSelect = document.getElementById('editProvince');
        const citySelect = document.getElementById('editCity');
        const cityCodeInput = document.getElementById('editCityCode');
        const barangayContainer = document.getElementById('editBarangayFieldContainer');
        const apiStatus = document.querySelector('.api-status-edit');
        const loadingSpinner = document.querySelector('.loading-spinner-edit');
        const toggleBtn = document.getElementById('manualBarangayToggleEdit');
        
        if (!regionSelect || !provinceSelect || !citySelect || !barangayContainer) return;
        
        provinceSelect.innerHTML = '<option value="">Select Province</option>';
        citySelect.innerHTML = '<option value="">Select City/Municipality</option>';
        provinceSelect.disabled = true;
        citySelect.disabled = true;
        
        // Create select element
        const barangaySelect = document.createElement('select');
        barangaySelect.className = 'form-select barangay-select-edit';
        barangaySelect.name = 'barangay';
        barangaySelect.disabled = true;
        barangaySelect.innerHTML = '<option value="">Select City/Municipality first</option>';
        barangayContainer.innerHTML = '';
        barangayContainer.appendChild(barangaySelect);
        
        if (toggleBtn) toggleBtn.style.display = 'none';
        if (cityCodeInput) cityCodeInput.value = selectedCityCode || '';
        
        // Set region and load provinces
        if (selectedRegion && provincesByRegion[selectedRegion]) {
            regionSelect.value = selectedRegion;
            provinceSelect.disabled = false;
            provincesByRegion[selectedRegion].forEach(province => {
                const option = document.createElement('option');
                option.value = province;
                option.textContent = province;
                if (province === selectedProvince) option.selected = true;
                provinceSelect.appendChild(option);
            });
            
            // Load cities if province selected
            if (selectedProvince) {
                citySelect.disabled = false;
                if (citiesByProvince[selectedProvince]) {
                    citiesByProvince[selectedProvince].forEach(city => {
                        const option = document.createElement('option');
                        option.value = city;
                        option.textContent = city;
                        if (city === selectedCity && selectedCityCode) option.dataset.code = selectedCityCode;
                        if (city === selectedCity) option.selected = true;
                        citySelect.appendChild(option);
                    });
                }
                
                // Load barangays if city selected
                if (selectedCity && !selectedCityCode && selectedBarangay) {
                    convertToManualBarangayEdit('No barangay dropdown data. You may edit the barangay manually.', selectedBarangay || '');
                    if (apiStatus) apiStatus.textContent = '⌨️ Manual barangay entry enabled';
                }
                const selectedLocalBarangays = getLocalBarangaysForLocation(selectedProvince, selectedCity);
                if (selectedCity && selectedLocalBarangays) {
                    populateBarangaySelect(barangaySelect, selectedLocalBarangays, selectedBarangay || '');
                    if (cityCodeInput) cityCodeInput.value = '';
                    if (toggleBtn) toggleBtn.style.display = 'none';
                    if (loadingSpinner) loadingSpinner.style.display = 'none';
                    if (apiStatus) apiStatus.textContent = `✓ ${selectedLocalBarangays.length} barangays loaded from local Lemery, Batangas list`;
                } else if (selectedCity && selectedCityCode) {
                    barangaySelect.disabled = true;
                    barangaySelect.innerHTML = '<option value="">Loading barangays...</option>';
                    if (loadingSpinner) loadingSpinner.style.display = 'inline-block';
                    
                    fetch(`https://psgc.gitlab.io/api/cities-municipalities/${selectedCityCode}/barangays.json`)
                        .then(response => response.json())
                        .then(barangays => {
                            barangaySelect.innerHTML = '<option value="">Select Barangay</option>';
                            if (barangays.length === 0) {
                                convertToManualBarangayEdit('No barangays found. Please type barangay manually.', selectedBarangay || '');
                            } else {
                                barangays.sort((a, b) => a.name.localeCompare(b.name));
                                barangays.forEach(barangay => {
                                    const option = document.createElement('option');
                                    option.value = barangay.name;
                                    option.textContent = barangay.name;
                                    if (barangay.name === selectedBarangay) option.selected = true;
                                    barangaySelect.appendChild(option);
                                });
                                barangaySelect.disabled = false;
                                if (toggleBtn) toggleBtn.style.display = 'block';
                            }
                            if (loadingSpinner) loadingSpinner.style.display = 'none';
                            if (apiStatus) apiStatus.textContent = `✓ ${barangays.length} barangays loaded`;
                        })
                        .catch(error => {
                            console.error('Error loading barangays:', error);
                            convertToManualBarangayEdit('Failed to load barangays. Please type barangay manually.', selectedBarangay || '');
                            if (loadingSpinner) loadingSpinner.style.display = 'none';
                            if (apiStatus) apiStatus.textContent = '✗ Failed to load barangays - manual entry enabled';
                        });
                }
            }
        } else {
            regionSelect.value = '';
        }
        
        // Event handlers
        regionSelect.onchange = function() {
            const region = this.value;
            provinceSelect.innerHTML = '<option value="">Select Province</option>';
            citySelect.innerHTML = '<option value="">Select City/Municipality</option>';
            citySelect.disabled = true;
            barangaySelect.innerHTML = '<option value="">Select City/Municipality first</option>';
            barangaySelect.disabled = true;
            if (cityCodeInput) cityCodeInput.value = '';
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
        };
        
        provinceSelect.onchange = function() {
            const province = this.value;
            citySelect.innerHTML = '<option value="">Loading cities...</option>';
            citySelect.disabled = true;
            barangaySelect.innerHTML = '<option value="">Select City/Municipality first</option>';
            barangaySelect.disabled = true;
            if (cityCodeInput) cityCodeInput.value = '';
            if (toggleBtn) toggleBtn.style.display = 'none';
            
            if (!province) {
                citySelect.innerHTML = '<option value="">Select City/Municipality</option>';
                return;
            }

            fetch('https://psgc.gitlab.io/api/cities-municipalities.json')
                .then(response => response.json())
                .then(allCities => {
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
                        citySelect.disabled = false;
                    } else if (citiesByProvince[province]) {
                        citiesByProvince[province].forEach(city => {
                            const option = document.createElement('option');
                            option.value = city;
                            option.textContent = city;
                            citySelect.appendChild(option);
                        });
                        citySelect.disabled = false;
                    } else {
                        citySelect.innerHTML = '<option value="">No cities found</option>';
                    }
                })
                .catch(error => {
                    console.error('Error loading cities:', error);
                    citySelect.innerHTML = '<option value="">Select City/Municipality</option>';
                    if (citiesByProvince[province]) {
                        citiesByProvince[province].forEach(city => {
                            const option = document.createElement('option');
                            option.value = city;
                            option.textContent = city;
                            citySelect.appendChild(option);
                        });
                        citySelect.disabled = false;
                    }
                });
        };
        
        citySelect.onchange = function() {
            const selectedOption = this.options[this.selectedIndex];
            const cityName = this.value;
            const cityCode = selectedOption.dataset?.code || (cityCodeInput ? cityCodeInput.value : '');
            
            barangaySelect.innerHTML = '<option value="">Loading barangays...</option>';
            barangaySelect.disabled = true;
            if (loadingSpinner) loadingSpinner.style.display = 'inline-block';
            if (apiStatus) apiStatus.textContent = 'Fetching barangays...';
            
            if (!cityName) {
                barangaySelect.innerHTML = '<option value="">Select City/Municipality first</option>';
                if (loadingSpinner) loadingSpinner.style.display = 'none';
                if (toggleBtn) toggleBtn.style.display = 'none';
                return;
            }
            
            const localBarangays = getLocalBarangaysForLocation(getSelectedProvinceForMode('edit'), cityName);
            if (localBarangays) {
                populateBarangaySelect(barangaySelect, localBarangays);
                if (cityCodeInput) cityCodeInput.value = '';
                if (toggleBtn) toggleBtn.style.display = 'none';
                if (loadingSpinner) loadingSpinner.style.display = 'none';
                if (apiStatus) apiStatus.textContent = `✓ ${localBarangays.length} barangays loaded from local ${getSelectedProvinceForMode('edit')}, ${cityName} list`;
                return;
            }

            function loadBarangays(code) {
                if (cityCodeInput) cityCodeInput.value = code;
                fetch(`https://psgc.gitlab.io/api/cities-municipalities/${code}/barangays.json`)
                    .then(response => response.json())
                    .then(barangays => {
                        barangaySelect.innerHTML = '<option value="">Select Barangay</option>';
                        if (barangays.length === 0) {
                            convertToManualBarangayEdit('No barangays found. Please type barangay manually.');
                        } else {
                            barangays.sort((a, b) => a.name.localeCompare(b.name));
                            barangays.forEach(barangay => {
                                const option = document.createElement('option');
                                option.value = barangay.name;
                                option.textContent = barangay.name;
                                barangaySelect.appendChild(option);
                            });
                            barangaySelect.disabled = false;
                            barangaySelect.onchange = updateAddressPreview;
                            if (toggleBtn) toggleBtn.style.display = 'block';
                        }
                        if (loadingSpinner) loadingSpinner.style.display = 'none';
                        if (apiStatus) apiStatus.textContent = `✓ ${barangays.length} barangays loaded`;
                    })
                    .catch(error => {
                        console.error('Error loading barangays:', error);
                        convertToManualBarangayEdit('Failed to load barangays. Please type barangay manually.');
                        if (loadingSpinner) loadingSpinner.style.display = 'none';
                        if (apiStatus) apiStatus.textContent = '✗ Failed to load barangays - manual entry enabled';
                    });
            }
            
            if (cityCode) {
                loadBarangays(cityCode);
            } else if (cityCodeCache) {
                const selectedProvince = getSelectedProvinceForMode('edit');
                const selectedRegion = getSelectedRegionForMode('edit');
                const foundCode = getCityCodeFromCache(cityName, selectedProvince, selectedRegion);
                if (foundCode) {
                    loadBarangays(foundCode);
                } else {
                    convertToManualBarangayEdit('No barangay data for this city. Please type barangay manually.');
                    if (loadingSpinner) loadingSpinner.style.display = 'none';
                    if (apiStatus) apiStatus.textContent = '⚠ No PSGC code found - manual entry enabled';
                }
            } else {
                convertToManualBarangayEdit('Unable to load barangays. Please type barangay manually.');
                if (loadingSpinner) loadingSpinner.style.display = 'none';
                if (apiStatus) apiStatus.textContent = '⚠ Unable to load barangays - manual entry enabled';
            }
        };
        
        if (toggleBtn) {
            toggleBtn.onclick = function() {
                convertToManualBarangayEdit('Manual entry mode - please type barangay name');
                if (apiStatus) apiStatus.textContent = '⌨️ Manual entry mode';
            };
        }
    }
    
    // Filter customers
    let activeCustomerGroupFilter = 'all';

    function normalizeGroupValue(value) {
        return (value || '').trim().toLocaleLowerCase();
    }

    function refreshGroupTabsFromCards() {
        const tabsContainer = document.getElementById('customerGroupTabs');
        if (!tabsContainer) return;

        const existingTabs = Array.from(tabsContainer.querySelectorAll('.customer-tab-btn'));
        const knownKeys = new Set(existingTabs.map(tab => tab.dataset.groupFilter || ''));
        const groups = new Map();

        document.querySelectorAll('#customerCardsContainer .customer-card').forEach(card => {
            if (card.dataset.isWalkin === 'true') return;
            const rawGroup = (card.dataset.customerGroup || '').trim();
            const key = normalizeGroupValue(card.dataset.customerGroupKey || rawGroup);
            if (!rawGroup || !key || groups.has(key) || knownKeys.has(key)) return;
            groups.set(key, rawGroup);
        });

        Array.from(groups.entries())
            .sort((a, b) => a[1].localeCompare(b[1], undefined, { sensitivity: 'base' }))
            .forEach(([key, label]) => {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'customer-tab-btn';
                btn.dataset.groupFilter = key;
                btn.innerHTML = `<i class="bi bi-collection"></i> ${escapeHtml(label)}`;
                btn.onclick = () => switchCustomerTab(key);
                tabsContainer.appendChild(btn);
            });
    }

    function filterCustomers() {
        const searchTerm = document.getElementById('searchInput').value.toLowerCase();
        const branchFilter = document.getElementById('branchFilter')?.value || 'all';
        const activeGroup = normalizeGroupValue(activeCustomerGroupFilter || 'all');
        const container = document.getElementById('customerCardsContainer');
        const cards = document.querySelectorAll('#customerCardsContainer .customer-card');
        let visibleCount = 0;
        let walkinCard = null;
        let otherCards = [];

        refreshGroupTabsFromCards();

        cards.forEach(card => {
            const isWalkin = card.dataset.isWalkin === 'true';
            if (isWalkin) {
                walkinCard = card;
            } else {
                otherCards.push(card);
            }
        });

        otherCards.forEach(card => {
            const code = card.dataset.customerCode?.toLowerCase() || '';
            const name = card.dataset.customerName?.toLowerCase() || '';
            const phone = card.dataset.customerPhone?.toLowerCase() || '';
            const group = card.dataset.customerGroup?.toLowerCase() || '';
            const groupKey = normalizeGroupValue(card.dataset.customerGroupKey || card.dataset.customerGroup || '');
            const cardBranch = card.dataset.customerBranch || '';

            let show = true;

            if (activeGroup !== 'all' && groupKey !== activeGroup) {
                show = false;
            }
            if (show && searchTerm && !code.includes(searchTerm) && !name.includes(searchTerm) && !phone.includes(searchTerm) && !group.includes(searchTerm)) {
                show = false;
            }
            if (show && branchFilter !== 'all' && customersBranchColumnExists && viewAllBranches && cardBranch != branchFilter) {
                show = false;
            }

            card.style.display = show ? '' : 'none';
            if (show) visibleCount++;
        });

        if (walkinCard) {
            const walkinCode = walkinCard.dataset.customerCode?.toLowerCase() || '';
            const walkinName = walkinCard.dataset.customerName?.toLowerCase() || '';
            const walkinPhone = walkinCard.dataset.customerPhone?.toLowerCase() || '';
            const walkinGroup = walkinCard.dataset.customerGroup?.toLowerCase() || '';
            const walkinGroupKey = normalizeGroupValue(walkinCard.dataset.customerGroupKey || walkinCard.dataset.customerGroup || '');

            let showWalkin = true;
            if (activeGroup !== 'all' && walkinGroupKey !== activeGroup) {
                showWalkin = false;
            }
            if (showWalkin && searchTerm && !walkinCode.includes(searchTerm) && !walkinName.includes(searchTerm) && !walkinPhone.includes(searchTerm) && !walkinGroup.includes(searchTerm)) {
                showWalkin = false;
            }

            walkinCard.style.display = showWalkin ? '' : 'none';
            if (showWalkin) visibleCount++;

            if (showWalkin && activeGroup === 'all' && container.firstChild !== walkinCard) {
                container.insertBefore(walkinCard, container.firstChild);
            }
        }

        const existingEmpty = container.querySelector('.empty-state:not(.permanent)');
        if (visibleCount === 0 && !existingEmpty && cards.length > 0) {
            const emptyDiv = document.createElement('div');
            emptyDiv.className = 'empty-state';
            emptyDiv.innerHTML = '<i class="bi bi-search"></i><p>No matching customers found</p>';
            container.appendChild(emptyDiv);
        } else if (visibleCount > 0 && existingEmpty) {
            existingEmpty.remove();
        }
    }

    function switchCustomerTab(groupFilter) {
        activeCustomerGroupFilter = normalizeGroupValue(groupFilter || 'all');

        document.querySelectorAll('#customerGroupTabs .customer-tab-btn').forEach(tab => {
            const tabFilter = normalizeGroupValue(tab.dataset.groupFilter || 'all');
            tab.classList.toggle('active', tabFilter === activeCustomerGroupFilter);
        });

        filterCustomers();
    }
    
    function toggleCustomerGroupInput(mode) {
        const select = document.getElementById(mode + 'CustomerGroupSelect');
        const input = document.getElementById(mode + 'CustomerGroup');
        if (!select || !input) return;

        if (select.value === '__new__') {
            input.classList.remove('d-none');
            input.value = input.value || '';
            setTimeout(() => input.focus(), 50);
        } else {
            input.classList.add('d-none');
            input.value = '';
        }
    }

    function setCustomerGroupValue(mode, value) {
        const select = document.getElementById(mode + 'CustomerGroupSelect');
        const input = document.getElementById(mode + 'CustomerGroup');
        const groupValue = (value || '').trim();
        if (!select || !input) return;

        const matchingOption = Array.from(select.options).find(option => option.value.toLowerCase() === groupValue.toLowerCase());
        if (groupValue !== '' && matchingOption && matchingOption.value !== '__new__') {
            select.value = matchingOption.value;
            input.value = '';
            input.classList.add('d-none');
        } else if (groupValue !== '') {
            select.value = '__new__';
            input.value = groupValue;
            input.classList.remove('d-none');
        } else {
            select.value = '';
            input.value = '';
            input.classList.add('d-none');
        }
    }

    function getCustomerGroupValue(mode) {
        const select = document.getElementById(mode + 'CustomerGroupSelect');
        const input = document.getElementById(mode + 'CustomerGroup');
        if (!select) return input ? input.value.trim() : '';
        if (select.value === '__new__') return input ? input.value.trim() : '';
        return select.value.trim();
    }

    // Refresh customer code
    function refreshCustomerCode() {
        showLoading();
        const formData = new FormData();
        formData.append('action', 'generate_code');
        fetch(window.location.href, { method: 'POST', body: formData })
            .then(res => res.json())
            .then(data => {
                Swal.close();
                if (data.success) {
                    document.getElementById('customerCodePreview').innerHTML = data.customer_code + ' <i class="bi bi-arrow-repeat refresh-code" onclick="refreshCustomerCode()" title="Generate new code"></i>';
                    document.getElementById('customerCodeInput').value = data.customer_code;
                } else {
                    Swal.fire('Error', data.message, 'error');
                }
            }).catch(() => { Swal.close(); Swal.fire('Error', 'Failed to generate code', 'error'); });
    }
    
    // Show add modal
    function showAddCustomerModal() {
        document.getElementById('addCustomerForm').reset();
        setCustomerGroupValue('add', '');
        document.getElementById('customerCodePreview').innerHTML = '<?php echo $preview_code; ?> <i class="bi bi-arrow-repeat refresh-code" onclick="refreshCustomerCode()" title="Generate new code"></i>';
        document.getElementById('customerCodeInput').value = '<?php echo $preview_code; ?>';
        
        const regionSelect = document.getElementById('addRegion');
        const provinceSelect = document.getElementById('addProvince');
        const citySelect = document.getElementById('addCity');
        if (regionSelect) regionSelect.value = '';
        if (provinceSelect) {
            provinceSelect.innerHTML = '<option value="">Select Province</option>';
            provinceSelect.disabled = true;
        }
        if (citySelect) {
            citySelect.innerHTML = '<option value="">Select City/Municipality</option>';
            citySelect.disabled = true;
        }
        
        initAddLocationDropdowns();
        new bootstrap.Modal(document.getElementById('addCustomerModal')).show();
    }
    
    // Save add customer
    function saveAddCustomer() {
        const customerName = document.getElementById('addCustomerName').value.trim();
        if (!customerName) {
            Swal.fire('Warning', 'Customer name is required', 'warning');
            return;
        }
        
        showLoading();
        const formData = new FormData();
        formData.append('action', 'add_customer');
        formData.append('customer_code', document.getElementById('customerCodeInput').value);
        formData.append('customer_name', customerName);
        formData.append('contact_person', document.getElementById('addContactPerson').value);
        formData.append('store_name', document.getElementById('addStoreName').value);
        formData.append('customer_group', getCustomerGroupValue('add'));
        formData.append('price_level', document.getElementById('addPriceLevel').value);
        formData.append('email', document.getElementById('addEmail').value);
        formData.append('phone_number', document.getElementById('addPhoneNumber').value);
        formData.append('region', document.getElementById('addRegion').value);
        formData.append('province', document.getElementById('addProvince').value);
        formData.append('city', document.getElementById('addCity').value);
        
        // Get barangay value (either from select or input)
        const barangaySelect = document.querySelector('.barangay-select');
        const barangayInput = document.getElementById('barangayInput');
        let barangay = '';
        if (barangaySelect && !barangaySelect.disabled && barangaySelect.value) {
            barangay = barangaySelect.value;
        } else if (barangayInput && barangayInput.value) {
            barangay = barangayInput.value;
        }
        formData.append('barangay', barangay);
        
        formData.append('status', 'active');
        const addStoreImageInput = document.querySelector('#addCustomerForm input[name="store_image"]');
        if (addStoreImageInput && addStoreImageInput.files && addStoreImageInput.files[0]) {
            formData.append('store_image', addStoreImageInput.files[0]);
        }
        
        if (customersBranchColumnExists && !viewAllBranches && branchId > 0) {
            formData.append('branch_id', branchId);
        }
        
        fetch(window.location.href, { method: 'POST', body: formData })
            .then(res => res.json())
            .then(data => {
                Swal.close();
                if (data.success) {
                    bootstrap.Modal.getInstance(document.getElementById('addCustomerModal')).hide();
                    Swal.fire({ icon: 'success', title: 'Success!', text: data.message, timer: 2000, showConfirmButton: false })
                        .then(() => location.reload());
                } else {
                    Swal.fire('Error', data.message, 'error');
                }
            }).catch(() => { Swal.close(); Swal.fire('Error', 'An error occurred', 'error'); });
    }
    
    function buildCustomerHistoryPagination(groupKey, totalRows, perPage = 10) {
        const totalPages = Math.ceil(totalRows / perPage);
        if (totalPages <= 1) return '';

        let items = '';
        const makeButton = (label, page, active = false, disabled = false, extraClass = '') => {
            const safePage = Math.max(1, Math.min(totalPages, Number(page) || 1));
            const disabledAttr = disabled ? 'disabled' : '';
            const disabledClass = disabled ? 'disabled' : '';
            return `<button type="button" class="btn btn-sm ${active ? 'btn-success' : 'btn-outline-success'} ${disabledClass} ${extraClass}" data-page-button="${safePage}" ${disabledAttr} onclick="setCustomerHistoryPage('${groupKey}', ${safePage})">${label}</button>`;
        };
        const makeEllipsis = () => `<span class="px-2 text-muted">...</span>`;

        items += makeButton('&lt;', 1, false, true, 'customer-history-prev-btn');

        if (totalPages <= 4) {
            for (let page = 1; page <= totalPages; page++) {
                items += makeButton(page, page, page === 1, false);
            }
        } else {
            items += makeButton(1, 1, true, false);
            items += makeButton(2, 2, false, false);
            items += makeButton(3, 3, false, false);
            items += makeEllipsis();
        }

        items += makeButton('&gt;', 2, false, false, 'customer-history-next-btn');

        return `<div class="d-flex justify-content-end align-items-center flex-wrap gap-1 mt-2 customer-history-pagination" data-page-controls="${groupKey}" data-total-pages="${totalPages}" data-current-page="1">${items}</div>`;
    }

    function setCustomerHistoryPage(groupKey, page) {
        const controls = document.querySelector(`[data-page-controls="${groupKey}"]`);
        const totalPages = controls ? Number(controls.getAttribute('data-total-pages')) || 1 : 1;
        const currentPage = Math.max(1, Math.min(totalPages, Number(page) || 1));

        document.querySelectorAll(`[data-page-group="${groupKey}"]`).forEach(row => {
            row.style.display = Number(row.getAttribute('data-page')) === currentPage ? '' : 'none';
        });

        if (!controls) return;
        controls.setAttribute('data-current-page', currentPage);

        const makeButton = (label, targetPage, active = false, disabled = false, extraClass = '') => {
            const safePage = Math.max(1, Math.min(totalPages, Number(targetPage) || 1));
            const disabledAttr = disabled ? 'disabled' : '';
            const disabledClass = disabled ? 'disabled' : '';
            return `<button type="button" class="btn btn-sm ${active ? 'btn-success' : 'btn-outline-success'} ${disabledClass} ${extraClass}" data-page-button="${safePage}" ${disabledAttr} onclick="setCustomerHistoryPage('${groupKey}', ${safePage})">${label}</button>`;
        };
        const makeEllipsis = () => `<span class="px-2 text-muted">...</span>`;

        let items = '';
        items += makeButton('&lt;', currentPage - 1, false, currentPage <= 1, 'customer-history-prev-btn');

        if (totalPages <= 4) {
            for (let pageNum = 1; pageNum <= totalPages; pageNum++) {
                items += makeButton(pageNum, pageNum, pageNum === currentPage, false);
            }
        } else {
            let startPage = Math.max(1, currentPage - 1);
            let endPage = Math.min(totalPages, startPage + 2);
            if (endPage - startPage < 2) startPage = Math.max(1, endPage - 2);

            for (let pageNum = startPage; pageNum <= endPage; pageNum++) {
                items += makeButton(pageNum, pageNum, pageNum === currentPage, false);
            }

            if (endPage < totalPages) {
                items += makeEllipsis();
            }
        }

        items += makeButton('&gt;', currentPage + 1, false, currentPage >= totalPages, 'customer-history-next-btn');
        controls.innerHTML = items;
    }

    function buildSoSiDisplay(soNumber, siNumber) {
        const so = escapeHtml(soNumber || '—');
        const rawSi = (siNumber && String(siNumber).trim() !== '') ? String(siNumber).trim() : '';
        const si = rawSi !== '' ? escapeHtml(rawSi.replace(/^SI-/i, '')) : '';
        return `<div><span class="badge bg-secondary">${so}</span>${si ? `<br><small class="text-muted">${si}</small>` : ''}</div>`;
    }


    window.customerInvoiceHistoryExports = window.customerInvoiceHistoryExports || {};

    function cleanExcelText(value) {
        return String(value ?? '')
            .replace(/<[^>]*>/g, '')
            .replace(/&nbsp;/g, ' ')
            .replace(/&amp;/g, '&')
            .replace(/&lt;/g, '<')
            .replace(/&gt;/g, '>')
            .replace(/&quot;/g, '"')
            .replace(/&#039;/g, "'")
            .trim();
    }

    function excelHtmlEscape(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function exportCustomerInvoiceHistoryToExcel(exportKey) {
        const payload = window.customerInvoiceHistoryExports && window.customerInvoiceHistoryExports[exportKey]
            ? window.customerInvoiceHistoryExports[exportKey]
            : null;

        if (!payload || !Array.isArray(payload.rows) || payload.rows.length === 0) {
            Swal.fire('No data', 'No Collection / Invoice History data to export.', 'info');
            return;
        }

        const customerName = cleanExcelText(payload.customer || 'Customer');
        const rows = payload.rows;
        const headers = ['Invoice', 'SO #', 'SI #', 'Date', 'Total', 'Collected', 'Balance', 'Status', 'Payment', 'Latest Payment Date'];

        let html = `
            <html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">
            <head>
                <meta charset="UTF-8">
                <!--[if gte mso 9]><xml><x:ExcelWorkbook><x:ExcelWorksheets><x:ExcelWorksheet><x:Name>Invoice History</x:Name><x:WorksheetOptions><x:DisplayGridlines/></x:WorksheetOptions></x:ExcelWorksheet></x:ExcelWorksheets></x:ExcelWorkbook></xml><![endif]-->
            </head>
            <body>
                <table border="1">
                    <tr><th colspan="10" style="font-size:16px;text-align:left;">Collection / Invoice History</th></tr>
                    <tr><th colspan="10" style="text-align:left;">Customer: ${excelHtmlEscape(customerName)}</th></tr>
                    <tr>${headers.map(h => `<th style="background:#1B5E20;color:#FFFFFF;">${excelHtmlEscape(h)}</th>`).join('')}</tr>
        `;

        rows.forEach(inv => {
            const rawSi = inv.si_number && String(inv.si_number).trim() !== '' ? String(inv.si_number).trim().replace(/^SI-/i, '') : '';
            const methodText = cleanExcelText((inv.payment_methods || '').replace(/_/g, ' ')) || '';
            const total = Number(inv.total_amount || 0).toFixed(2);
            const collected = Number(inv.computed_paid_amount || 0).toFixed(2);
            const balance = Number(inv.computed_balance || 0).toFixed(2);
            html += `
                <tr>
                    <td>${excelHtmlEscape(inv.invoice_number || '')}</td>
                    <td>${excelHtmlEscape(inv.so_number || '')}</td>
                    <td>${excelHtmlEscape(rawSi)}</td>
                    <td>${excelHtmlEscape(formatDate(inv.invoice_date))}</td>
                    <td style="mso-number-format:'0.00';">${total}</td>
                    <td style="mso-number-format:'0.00';">${collected}</td>
                    <td style="mso-number-format:'0.00';">${balance}</td>
                    <td>${excelHtmlEscape(inv.invoice_status || 'pending')}</td>
                    <td>${excelHtmlEscape(methodText)}</td>
                    <td>${excelHtmlEscape(inv.latest_payment_date ? formatDate(inv.latest_payment_date) : '')}</td>
                </tr>
            `;
        });

        html += `
                </table>
            </body>
            </html>
        `;

        const blob = new Blob(['\ufeff', html], { type: 'application/vnd.ms-excel;charset=utf-8;' });
        const safeCustomer = customerName.replace(/[^a-z0-9_-]+/gi, '_').replace(/^_+|_+$/g, '') || 'customer';
        const filename = `collection_invoice_history_${safeCustomer}_${new Date().toISOString().slice(0, 10)}.xls`;
        const link = document.createElement('a');
        const url = URL.createObjectURL(blob);
        link.href = url;
        link.download = filename;
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        URL.revokeObjectURL(url);
    }

    // View customer details
    function viewCustomerDetails(id) {
        showLoading();
        const formData = new FormData();
        formData.append('action', 'get_customer');
        formData.append('customer_id', id);
        fetch(window.location.href, { method: 'POST', body: formData })
            .then(res => res.json())
            .then(data => {
                Swal.close();
                if (data.success) {
                    const c = data.customer;
                    const createdDate = c.created_at ? new Date(c.created_at).toLocaleString() : 'N/A';
                    const phone = c.phone_number;
                    
                    const addressParts = [];
                    if (c.barangay) addressParts.push(c.barangay);
                    if (c.city) addressParts.push(c.city);
                    if (c.province) addressParts.push(c.province);
                    if (c.region) addressParts.push(c.region);
                    const fullAddress = addressParts.join(', ') || c.address || '—';
                    
                    const hasLocation = c.latitude && c.longitude && c.latitude != '0' && c.longitude != '0';
                    const mapId = 'viewLocationMap_' + c.customer_id;
                    
                    // Get orders for this customer
                    fetchOrdersForCustomer(id).then(ordersData => {
                        const orders = ordersData.orders || [];
                        
                        // Build Order History Table HTML
                        let ordersHtml = '';
                        if (orders && orders.length > 0) {
                            ordersHtml = `
                                <div class="orders-section" style="border-top: 1px solid #e9ecef; padding: 20px;">
                                    <h6><i class="bi bi-bag-check"></i> Order History (${orders.length} orders)</h6>
                                    <div class="table-responsive">
                                        <table class="table table-hover orders-table" style="font-size: 0.85rem; width: 100%;">
                                            <thead>
                                                <tr>
                                                    <th>SO & SI #</th>
                                                    <th>Order Date</th>
                                                    <th>Total Amount</th>
                                                    <th>Status</th>
                                                    <th>Action</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                ${orders.map((order, index) => `
                                                    <tr data-page-group="customer-orders-${id}" data-page="${Math.floor(index / 10) + 1}" style="${index >= 10 ? 'display:none;' : ''}">
                                                        <td>${buildSoSiDisplay(order.so_number, order.si_number)}</td>
                                                        <td>${formatDate(order.order_date)}</span></td>
                                                        <td>${formatCurrency(order.total_amount)}</span></td>
                                                        <td><span class="badge ${order.order_status === 'completed' ? 'bg-success' : order.order_status === 'cancelled' ? 'bg-danger' : 'bg-warning'} badge-order-status">${escapeHtml(order.order_status || 'Pending')}</span></td>
                                                        <td>
                                                            <button class="btn-action btn-view" onclick="viewOrderDetails(${order.so_id})">
                                                                View Order
                                                            </button>
                                                         </span>
                                                    </tr>
                                                `).join('')}
                                            </tbody>
                                        </table>
                                        ${buildCustomerHistoryPagination(`customer-orders-${id}`, orders.length, 10)}
                                    </div>
                                </div>
                            `;
                        } else {
                            ordersHtml = `
                                <div class="orders-section" style="border-top: 1px solid #e9ecef; padding: 20px;">
                                    <h6><i class="bi bi-bag-check"></i> Order History</h6>
                                    <div class="no-orders" style="text-align: center; padding: 30px; color: #6c757d; background: #f8f9fa; border-radius: 8px;">
                                        <i class="bi bi-inbox" style="font-size: 2rem;"></i>
                                        <p class="mt-2 mb-0">No orders found for this customer</p>
                                    </div>
                                </div>
                            `;
                        }
                        
                        const invoiceHistory = Array.isArray(c.invoice_history) ? c.invoice_history : [];
                        const invoiceExportKey = `customer-invoices-${id}`;
                        window.customerInvoiceHistoryExports[invoiceExportKey] = {
                            customer: c.customer_name || c.store_name || c.customer_code || 'Customer',
                            rows: invoiceHistory
                        };
                        let invoiceHistoryHtml = '';
                        if (invoiceHistory.length > 0) {
                            invoiceHistoryHtml = `
                                <div class="orders-section" style="border-top: 1px solid #e9ecef; padding: 20px;">
                                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
                                        <h6 class="mb-0"><i class="bi bi-receipt-cutoff"></i> Collection / Invoice History (${invoiceHistory.length})</h6>
                                        <button type="button" class="btn btn-success btn-sm" onclick="exportCustomerInvoiceHistoryToExcel('${invoiceExportKey}')">
                                            <i class="bi bi-file-earmark-excel"></i> Export Excel
                                        </button>
                                    </div>
                                    <div class="table-responsive">
                                        <table class="table table-hover orders-table" style="font-size: 0.85rem; width: 100%;">
                                            <thead>
                                                <tr>
                                                    <th>Invoice</th>
                                                    <th>SO & SI #</th>
                                                    <th>Date</th>
                                                    <th>Total</th>
                                                    <th>Collected</th>
                                                    <th>Balance</th>
                                                    <th>Status</th>
                                                    <th>Payment</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                ${invoiceHistory.map((inv, index) => {
                                                    const status = (inv.invoice_status || 'pending').toLowerCase();
                                                    const statusClass = status === 'paid' ? 'bg-success' : (status === 'cancelled' ? 'bg-danger' : (status === 'overdue' ? 'bg-danger' : 'bg-warning text-dark'));
                                                    const methodText = (inv.payment_methods || '').replace(/_/g, ' ') || '—';
                                                    const latestPayment = inv.latest_payment_date ? `<br><small class="text-muted">${formatDate(inv.latest_payment_date)}</small>` : '';
                                                    return `
                                                        <tr data-page-group="customer-invoices-${id}" data-page="${Math.floor(index / 10) + 1}" style="${index >= 10 ? 'display:none;' : ''}">
                                                            <td>${escapeHtml(inv.invoice_number || '—')}</td>
                                                            <td>${buildSoSiDisplay(inv.so_number, inv.si_number)}</td>
                                                            <td>${formatDate(inv.invoice_date)}</td>
                                                            <td>${formatCurrency(inv.total_amount || 0)}</td>
                                                            <td>${formatCurrency(inv.computed_paid_amount || 0)}</td>
                                                            <td><strong style="color:${Number(inv.computed_balance || 0) > 0 ? '#b02a37' : '#1B5E20'};">${formatCurrency(inv.computed_balance || 0)}</strong></td>
                                                            <td><span class="badge ${statusClass}">${escapeHtml(status || 'pending')}</span></td>
                                                            <td>${escapeHtml(methodText)}${latestPayment}</td>
                                                        </tr>
                                                    `;
                                                }).join('')}
                                            </tbody>
                                        </table>
                                        ${buildCustomerHistoryPagination(`customer-invoices-${id}`, invoiceHistory.length, 10)}
                                    </div>
                                </div>
                            `;
                        } else {
                            invoiceHistoryHtml = `
                                <div class="orders-section" style="border-top: 1px solid #e9ecef; padding: 20px;">
                                    <h6><i class="bi bi-receipt-cutoff"></i> Collection / Invoice History</h6>
                                    <div class="no-orders" style="text-align: center; padding: 30px; color: #6c757d; background: #f8f9fa; border-radius: 8px;">
                                        <i class="bi bi-file-earmark-text" style="font-size: 2rem;"></i>
                                        <p class="mt-2 mb-0">No invoice or collection history found for this customer</p>
                                    </div>
                                </div>
                            `;
                        }

                        const creditMetaText = c.has_credit_limit
                            ? 'Credit Used: ' + formatCurrency(c.display_credit_used || 0) + ' | Credit Limit: ' + formatCurrency(c.credit_limit || 0)
                            : 'No Credit Limit';

                        const totalOilVolume = parseFloat(c.total_oil_volume || 0);
                        const oilVolumeText = Number.isInteger(totalOilVolume)
                            ? totalOilVolume.toLocaleString()
                            : totalOilVolume.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                        const oilVolumeHtml = totalOilVolume > 0 ? `
                                    <div class="customer-info-item" style="background: #fffdf5; border: 1px solid #ffe8a1; border-radius: 10px; padding: 12px;">
                                        <span class="customer-info-label" style="font-size: 0.7rem; text-transform: uppercase; color: #8a6d1d;">Total Oil Volume Ordered</span>
                                        <span class="customer-info-value" style="font-size: 1rem; font-weight: 700; color: #8a6d1d;">${oilVolumeText} kg</span>
                                    </div>` : '';

                        const content = `
                            <div class="customer-details-card">
                                <div class="customer-info-grid" style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 16px; padding: 20px;">
                                    <div class="customer-info-item">
                                        <span class="customer-info-label" style="font-size: 0.7rem; text-transform: uppercase; color: #6c757d;">Customer Code</span>
                                        <span class="customer-info-value" style="font-size: 0.95rem; font-weight: 500;">${escapeHtml(c.customer_code)}</span>
                                    </div>
                                    <div class="customer-info-item">
                                        <span class="customer-info-label" style="font-size: 0.7rem; text-transform: uppercase; color: #6c757d;">Customer Name</span>
                                        <span class="customer-info-value" style="font-size: 0.95rem; font-weight: 500;">${escapeHtml(c.customer_name)}</span>
                                    </div>
                                    <div class="customer-info-item">
                                        <span class="customer-info-label" style="font-size: 0.7rem; text-transform: uppercase; color: #6c757d;">Email</span>
                                        <span class="customer-info-value" style="font-size: 0.95rem; font-weight: 500;">${escapeHtml(c.email || '—')}</span>
                                    </div>
                                    <div class="customer-info-item">
                                        <span class="customer-info-label" style="font-size: 0.7rem; text-transform: uppercase; color: #6c757d;">Phone</span>
                                        <span class="customer-info-value" style="font-size: 0.95rem; font-weight: 500;">${escapeHtml(phone || '—')}</span>
                                    </div>
                                    <div class="customer-info-item">
                                        <span class="customer-info-label" style="font-size: 0.7rem; text-transform: uppercase; color: #6c757d;">Address</span>
                                        <span class="customer-info-value" style="font-size: 0.95rem; font-weight: 500;">${escapeHtml(fullAddress)}</span>
                                    </div>
                                    <div class="customer-info-item">
                                        <span class="customer-info-label" style="font-size: 0.7rem; text-transform: uppercase; color: #6c757d;">Status</span>
                                        <span class="customer-info-value" style="font-size: 0.95rem; font-weight: 500;">
                                            <span class="badge ${c.status === 'active' ? 'bg-success' : (c.status === 'pending' ? 'bg-warning' : 'bg-danger')}">${c.status}</span>
                                        </span>
                                    </div>
                                    <div class="customer-info-item" style="background: ${c.is_over_limit ? '#fff5f5' : '#f8fff9'}; border: 1px solid ${c.is_over_limit ? '#f5c2c7' : '#d1e7dd'}; border-radius: 10px; padding: 12px;">
                                        <span class="customer-info-label" style="font-size: 0.7rem; text-transform: uppercase; color: ${c.is_over_limit ? '#b02a37' : '#1B5E20'};">Outstanding Balance</span>
                                        <span class="customer-info-value" style="font-size: 1rem; font-weight: 700; color: ${c.is_over_limit ? '#b02a37' : '#1B5E20'};">${formatCurrency(c.outstanding_balance || 0)}</span>
                                        <small style="display:block; margin-top:4px; color:#6c757d;">${escapeHtml(creditMetaText)}</small>
                                    </div>
                                    ${oilVolumeHtml}
                                    <div class="customer-info-item">
                                        <span class="customer-info-label" style="font-size: 0.7rem; text-transform: uppercase; color: #6c757d;">Created By</span>
                                        <span class="customer-info-value" style="font-size: 0.95rem; font-weight: 500;">${escapeHtml(c.created_by_name || 'System')}</span>
                                    </div>
                                    <div class="customer-info-item">
                                        <span class="customer-info-label" style="font-size: 0.7rem; text-transform: uppercase; color: #6c757d;">Created At</span>
                                        <span class="customer-info-value" style="font-size: 0.95rem; font-weight: 500;">${createdDate}</span>
                                    </div>
                                </div>
                                ${hasLocation ? `
                                <div class="orders-section" style="border-top: 1px solid #e9ecef; padding: 20px;">
                                    <h6><i class="bi bi-geo-alt-fill"></i> Location Map</h6>
                                    <div id="${mapId}" style="height: 300px; border-radius: 12px; margin-top: 10px; border: 1px solid #e2e8f0;"></div>
                                    <div class="row mt-3">
                                        <div class="col-md-6">
                                            <small><strong>Latitude:</strong> ${escapeHtml(c.latitude)}</small>
                                        </div>
                                        <div class="col-md-6">
                                            <small><strong>Longitude:</strong> ${escapeHtml(c.longitude)}</small>
                                        </div>
                                    </div>
                                </div>
                                ` : ''}
                                ${invoiceHistoryHtml}
                                ${ordersHtml}
                            </div>
                        `;
                        document.getElementById('viewCustomerContent').innerHTML = content;
                        currentCustomerId = id;
                        
                        // Initialize map if has location
                        if (hasLocation) {
                            setTimeout(() => {
                                const mapContainer = document.getElementById(mapId);
                                if (mapContainer) {
                                    if (viewMap) {
                                        viewMap.remove();
                                    }
                                    const lat = parseFloat(c.latitude);
                                    const lng = parseFloat(c.longitude);
                                    viewMap = L.map(mapId).setView([lat, lng], 15);
                                    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                                        attribution: '© OpenStreetMap contributors'
                                    }).addTo(viewMap);
                                    L.marker([lat, lng]).addTo(viewMap)
                                        .bindPopup(`<b>${escapeHtml(c.customer_name)}</b><br>${escapeHtml(fullAddress)}`)
                                        .openPopup();
                                }
                            }, 300);
                        }
                        
                        const isWalkin = (c.customer_code === 'WALKIN-001');
                        const editBtn = document.getElementById('editFromViewBtn');
                        if (editBtn) {
                            editBtn.style.display = isWalkin ? 'none' : 'inline-block';
                        }
                        
                        new bootstrap.Modal(document.getElementById('viewCustomerModal')).show();
                    });
                } else {
                    Swal.fire('Error', data.message, 'error');
                }
            }).catch(() => { Swal.close(); Swal.fire('Error', 'Failed to load customer', 'error'); });
    }
    
    // Function to fetch orders for a customer
    function fetchOrdersForCustomer(customerId) {
        return new Promise((resolve) => {
            const formData = new FormData();
            formData.append('action', 'get_customer_orders');
            formData.append('customer_id', customerId);
            
            fetch(window.location.href, { method: 'POST', body: formData })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        resolve(data);
                    } else {
                        resolve({ orders: [] });
                    }
                })
                .catch(() => {
                    resolve({ orders: [] });
                });
        });
    }
    
// Function to view order details (with discount display from database)
function viewOrderDetails(orderId) {
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
    
    // Fetch order details via AJAX
    fetch(window.location.href, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: 'action=get_order_details&order_id=' + orderId
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            const order = data.order;
            const items = data.items;
            
            // Build items table HTML
            let itemsHtml = '';
            let subTotal = 0;
            let discountAmount = 0;
            let grandTotal = parseFloat(order.total_amount) || 0;
            
            if (items && items.length > 0) {
                items.forEach(item => {
                    const qty = parseFloat(item.quantity_ordered) || 0;
                    const grossPrice = parseFloat(item.gross_price) || parseFloat(item.unit_price) || 0;
                    const total = parseFloat(item.order_amount) || parseFloat(item.line_total) || (qty * (parseFloat(item.net_price) || parseFloat(item.unit_price) || 0));
                    const grossLineTotal = qty * grossPrice;
                    subTotal += grossLineTotal;
                    itemsHtml += `
                        <tr>
                            <td data-label="Product">
                                <strong>${escapeHtml(item.item_name)}</strong><br>
                                <small class="text-muted">${escapeHtml(item.item_code)}</small>
                            </td>
                            <td data-label="Unit" class="text-center">${escapeHtml(item.unit_type || 'N/A')}</span></td>
                            <td data-label="Quantity" class="text-center">${parseInt(item.quantity_ordered)}</span></td>
                            <td data-label="Unit Price" class="text-end">${formatCurrency(grossPrice)}</span></td>
                            <td data-label="Total" class="text-end">${formatCurrency(total)}</span></td>
                        </tr>
                    `;
                });
            } else {
                itemsHtml = `
                    <tr>
                        <td colspan="5" class="text-center text-muted py-4">
                            <i class="bi bi-box"></i> No items found for this order
                        </span>
                    </tr>
                `;
            }
            
            // Totals summary: SUBTOTAL, DISCOUNT, GRAND TOTAL.
            // Grand total comes from saved discounted sales_orders.total_amount.
            // Discount comes from saved header discount/line discount, then fallback to subtotal - grand total.
            discountAmount = parseFloat(order.discount_amount) || parseFloat(order.total_discount_amount) || 0;
            if (discountAmount <= 0 && subTotal > 0 && grandTotal >= 0) {
                discountAmount = Math.max(0, subTotal - grandTotal);
            }
            if (grandTotal <= 0 && subTotal > 0) {
                grandTotal = Math.max(0, subTotal - discountAmount);
            }
            
            let discountLabel = '';
            if (discountAmount > 0) {
                discountLabel = `<span class="badge bg-success ms-2">Discount Applied</span>`;
            }
            
            // Build complete modal content - gaya ng customer.php/sales_order.php style
            orderDetailsContent.innerHTML = `
                <div class="order-details-card">
                    <!-- Order Header Section -->
                    <div class="order-header-section">
                        <div class="order-badge">
                            <i class="bi bi-receipt"></i>
                            <span>Order Information</span>
                        </div>
                        <div class="order-number">${escapeHtml(order.so_number)}${discountLabel}</div>
                    </div>
                    
                    <!-- Order Info Grid (2 columns) -->
                    <div class="order-info-grid">
                        <div class="order-info-item">
                            <div class="order-info-label">
                                <i class="bi bi-calendar3"></i> ORDER DATE
                            </div>
                            <div class="order-info-value">${new Date(order.order_date).toLocaleString()}</div>
                        </div>
                        <div class="order-info-item">
                            <div class="order-info-label">
                                <i class="bi bi-building"></i> BRANCH
                            </div>
                            <div class="order-info-value">${escapeHtml(order.branch_name || 'N/A')}</div>
                        </div>
                        <div class="order-info-item">
                            <div class="order-info-label">
                                <i class="bi bi-truck"></i> ASSIGNED DRIVER
                            </div>
                            <div class="order-info-value">
                                ${order.assigned_driver && order.assigned_driver !== 'No Driver' ? 
                                    `<span class="driver-badge-modal"><i class="bi bi-person-badge"></i> ${escapeHtml(order.assigned_driver)}</span>` : 
                                    `<span class="text-muted">No Driver Assigned</span>`}
                            </div>
                        </div>
                        <div class="order-info-item">
                            <div class="order-info-label">
                                <i class="bi bi-flag"></i> STATUS
                            </div>
                            <div class="order-info-value">
                                <span class="badge ${getOrderStatusBadgeClass(order.order_status)}">${getOrderStatusText(order.order_status)}</span>
                            </div>
                        </div>
                        <div class="order-info-item">
                            <div class="order-info-label">
                                <i class="bi bi-person"></i> CREATED BY
                            </div>
                            <div class="order-info-value">${escapeHtml(order.created_by || 'System')}</div>
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
                                <tfoot>
                                    <!-- SUB TOTAL ROW -->
                                    <tr>
                                        <td colspan="4" class="text-end fw-bold">SUB TOTAL</span>
                                        <td class="text-end fw-bold">${formatCurrency(subTotal)}</span>
                                    </tr>
                                    <!-- DISCOUNT ROW -->
                                    <tr style="background-color: #fff3cd;">
                                        <td colspan="4" class="text-end fw-bold">DISCOUNT</span>
                                        <td class="text-end fw-bold text-danger">-${formatCurrency(discountAmount)}</span>
                                    </tr>
                                    <!-- GRAND TOTAL ROW -->
                                    <tr style="background-color: #e8f5e9;">
                                        <td colspan="4" class="text-end fw-bold" style="font-size: 1rem;">GRAND TOTAL</span>
                                        <td class="text-end fw-bold text-success" style="font-size: 1rem;">${formatCurrency(grandTotal)}</span>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                        ${discountAmount > 0 ? `
                        <div class="alert alert-success mt-3">
                            <i class="bi bi-tag"></i> 
                            <strong>Discount applied!</strong> 
                            Subtotal: ${formatCurrency(subTotal)} → Grand Total: ${formatCurrency(grandTotal)}
                        </div>
                        ` : ''}
                    </div>
                </div>
            `;
            
            // Show print button only (no cancel for branch admin)
            const printButton = document.getElementById('printOrderFromDetails');
            if (printButton) printButton.style.display = 'inline-block';
            
        } else {
            orderDetailsContent.innerHTML = `
                <div class="error-state text-center py-5">
                    <i class="bi bi-exclamation-triangle fs-1 text-danger"></i>
                    <p class="mt-3">${escapeHtml(data.message || 'Error loading order details.')}</p>
                </div>
            `;
            const printButton = document.getElementById('printOrderFromDetails');
            if (printButton) printButton.style.display = 'none';
        }
    })
    .catch(error => {
        console.error('Error:', error);
        orderDetailsContent.innerHTML = `
            <div class="error-state text-center py-5">
                <i class="bi bi-wifi-off fs-1 text-danger"></i>
                <p class="mt-3">Network error: ${escapeHtml(error.message)}</p>
                <button class="btn btn-outline-danger mt-2" onclick="viewOrderDetails(${orderId})">
                    <i class="bi bi-arrow-repeat"></i> Try Again
                </button>
            </div>
        `;
        const printButton = document.getElementById('printOrderFromDetails');
        if (printButton) printButton.style.display = 'none';
    });
}

// Function to print order from customer list
function printOrderFromCustomer() {
    if (!currentOrderIdFromCustomer) {
        Swal.fire('Error', 'No order selected to print', 'error');
        return;
    }
    
    showLoading();
    const formData = new FormData();
    formData.append('action', 'print_order');
    formData.append('so_id', currentOrderIdFromCustomer);
    
    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        Swal.close();
        if (data.success && data.order) {
            // Open print window
            const printWindow = window.open('', '_blank');
            if (!printWindow) {
                Swal.fire('Error', 'Please allow pop-ups to print', 'error');
                return;
            }
            
            printWindow.document.write(`
                <!DOCTYPE html>
                <html>
                <head>
                    <title>Order ${escapeHtml(data.order.so_number)}</title>
                    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
                    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
                    <style>
                        body { padding: 20px; font-family: Arial, sans-serif; }
                        .print-header { text-align: center; margin-bottom: 30px; }
                        .order-info { margin-bottom: 20px; }
                        .order-info td { padding: 5px; }
                        .items-table { width: 100%; border-collapse: collapse; margin-top: 20px; }
                        .items-table th, .items-table td { border: 1px solid #ddd; padding: 8px; text-align: left; }
                        .items-table th { background-color: #f2f2f2; }
                        .text-end { text-align: right; }
                        .total-row { font-weight: bold; background-color: #f9f9f9; }
                        @media print {
                            body { margin: 0; padding: 15px; }
                            .no-print { display: none; }
                            button { display: none; }
                        }
                    </style>
                </head>
                <body>
                    <div class="print-header">
                        <h3>AMGC Sales Order</h3>
                        <p>${escapeHtml(data.order.so_number)}</p>
                    </div>
                    
                    <table class="order-info">
                        <tr><th style="width:150px">Order Date:</th><td>${new Date(data.order.order_date).toLocaleString()}</td></tr>
                        <tr><th>Branch:</th><td>${escapeHtml(data.order.branch_name || 'N/A')}</td></tr>
                        <tr><th>Customer:</th><td>${escapeHtml(data.order.customer_name || 'N/A')}</td></tr>
                        <tr><th>Address:</th><td>${escapeHtml(data.order.address || 'N/A')}</td></tr>
                        <tr><th>Status:</th><td>${escapeHtml(data.order.order_status || 'Pending')}</td></tr>
                        ${data.driver ? `<tr><th>Driver:</th><td>${escapeHtml(data.driver.driver_name)}</td></tr>` : ''}
                    </table>
                    
                    <table class="items-table">
                        <thead>
                            <tr><th>Item Code</th><th>Item Name</th><th>Unit</th><th>Quantity</th><th>Unit Price</th><th>Total</th></tr>
                        </thead>
                        <tbody>
                            ${data.items.map(item => `
                                <tr>
                                    <td>${escapeHtml(item.item_code)}</td>
                                    <td>${escapeHtml(item.item_name)}</td>
                                    <td>${escapeHtml(item.unit_type || 'N/A')}</td>
                                    <td>${parseInt(item.quantity_ordered)}</td>
                                    <td class="text-end">${formatCurrency(item.unit_price)}</td>
                                    <td class="text-end">${formatCurrency(item.quantity_ordered * item.unit_price)}</td>
                                </tr>
                            `).join('')}
                            <tr class="total-row">
                                <td colspan="5" class="text-end">Grand Total:</td>
                                <td class="text-end">${formatCurrency(data.order.order_total)}</td>
                            </tr>
                        </tbody>
                    </table>
                    
                    <div style="margin-top: 40px; text-align: center;">
                        <p>Thank you for your business!</p>
                    </div>
                    
                    <div class="no-print" style="margin-top: 20px; text-align: center;">
                        <button onclick="window.print();" class="btn btn-primary">Print</button>
                        <button onclick="window.close();" class="btn btn-secondary">Close</button>
                    </div>
                </body>
                </html>
            `);
            printWindow.document.close();
        } else {
            Swal.fire('Error', data.message || 'Failed to load order for printing', 'error');
        }
    })
    .catch(error => {
        Swal.close();
        Swal.fire('Error', 'Network error: ' + error.message, 'error');
    });
}
// Helper functions for status badges - IDAGDAG ITO sa JavaScript section
function getOrderStatusBadgeClass(status) {
    switch(status) {
        case 'pending': return 'bg-warning text-dark';
        case 'processing': return 'bg-info text-white';
        case 'shipped': return 'bg-primary text-white';
        case 'delivered': return 'bg-success text-white';
        case 'cancelled': return 'bg-danger text-white';
        case 'completed': return 'bg-success text-white';
        default: return 'bg-secondary text-white';
    }
}

function getOrderStatusText(status) {
    switch(status) {
        case 'pending': return 'Pending';
        case 'processing': return 'Processing';
        case 'shipped': return 'Shipped';
        case 'delivered': return 'Delivered';
        case 'cancelled': return 'Cancelled';
        case 'completed': return 'Completed';
        default: return status || 'Unknown';
    }
}

// Format currency helper function
function formatCurrency(amount) {
    if (!amount) return '₱0.00';
    return '₱' + parseFloat(amount).toLocaleString('en-PH', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
}

// Escape HTML helper function
function escapeHtml(str) {
    if (!str) return '';
    return str.replace(/[&<>]/g, function(m) {
        if (m === '&') return '&amp;';
        if (m === '<') return '&lt;';
        if (m === '>') return '&gt;';
        return m;
    });
}
    // Format date helper function
    function formatDate(dateString) {
        if (!dateString) return 'N/A';
        const date = new Date(dateString);
        return date.toLocaleDateString('en-PH', {
            year: 'numeric',
            month: 'short',
            day: 'numeric'
        });
    }
    
    // Format currency helper function
    function formatCurrency(amount) {
        if (!amount) return '₱0.00';
        return '₱' + parseFloat(amount).toLocaleString('en-PH', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }
    
    // Edit customer - COMPLETE FIX with all safety checks
    function editCustomer(id) {
        const card = document.querySelector(`.customer-card[data-customer-id="${id}"]`);
        if (card && card.dataset.isWalkin === 'true') {
            Swal.fire({
                icon: 'info',
                title: 'Walk-in Customer',
                text: 'Walk-in customer is a system default. You cannot edit it, but you can still create orders.',
                confirmButtonColor: '#059669'
            });
            return;
        }
        
        showLoading();
        const formData = new FormData();
        formData.append('action', 'get_customer');
        formData.append('customer_id', id);
        
        fetch(window.location.href, { method: 'POST', body: formData })
            .then(res => res.json())
            .then(data => {
                Swal.close();
                if (data.success) {
                    const c = data.customer;
                    
                    // Basic info - with existence checks
                    const setElemValue = (id, value) => {
                        const elem = document.getElementById(id);
                        if (elem) elem.value = value || '';
                    };
                    
                    setElemValue('editCustomerId', c.customer_id);
                    setElemValue('editCustomerCode', c.customer_code);
                    setElemValue('editCustomerName', c.customer_name);
                    setElemValue('editContactPerson', c.contact_person);
                    setCustomerGroupValue('edit', c.customer_group);
                    setElemValue('editStoreName', c.store_name);
                    setElemValue('editPriceLevel', c.price_level || 'Standard');
                    setElemValue('existingStoreImage', c.store_image);
                    setElemValue('editEmail', c.email);
                    setElemValue('editPhoneNumber', c.phone_number);
                    setElemValue('editNotes', c.notes);
                    setElemValue('editStatus', c.status);
                    setElemValue('editBarangay', c.barangay);
                    setElemValue('editLatitude', c.latitude);
                    setElemValue('editLongitude', c.longitude);
                    
                    // Initialize edit address dropdowns including barangay dropdown
                    initEditLocationDropdowns(
                        c.region || '',
                        c.province || '',
                        c.city || '',
                        c.barangay || '',
                        c.city_code || ''
                    );
                    
                    // Try to initialize map if has valid location
                    const hasLocation = c.latitude && c.longitude && c.latitude != '0' && c.longitude != '0' && c.latitude != '' && c.longitude != '';
                    if (hasLocation) {
                        setTimeout(() => {
                            const mapContainer = document.getElementById('editLocationMap');
                            if (mapContainer && typeof L !== 'undefined') {
                                if (editMap) {
                                    editMap.remove();
                                }
                                const lat = parseFloat(c.latitude);
                                const lng = parseFloat(c.longitude);
                                if (!isNaN(lat) && !isNaN(lng)) {
                                    editMap = L.map('editLocationMap').setView([lat, lng], 15);
                                    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                                        attribution: '© OpenStreetMap contributors'
                                    }).addTo(editMap);
                                    L.marker([lat, lng]).addTo(editMap)
                                        .bindPopup(`<b>${escapeHtml(c.customer_name)}</b>`)
                                        .openPopup();
                                }
                            }
                        }, 500);
                    }
                    
                    // Show modal
                    const modal = new bootstrap.Modal(document.getElementById('editCustomerModal'));
                    modal.show();
                } else {
                    Swal.fire('Error', data.message || 'Failed to load customer details', 'error');
                }
            }).catch(error => {
                Swal.close();
                console.error('Fetch error:', error);
                Swal.fire('Error', 'Network error: ' + error.message, 'error');
            });
    }
    
    // Save edit customer - FIXED
    function saveEditCustomer() {
        const getValue = (id, fallback = '') => {
            const el = document.getElementById(id);
            return el ? el.value : fallback;
        };

        const customerName = getValue('editCustomerName').trim();
        if (!customerName) {
            Swal.fire('Warning', 'Customer name is required', 'warning');
            return;
        }

        showLoading();

        try {
            const formData = new FormData();
            formData.append('action', 'update_customer_fast');
            formData.append('customer_id', getValue('editCustomerId'));
            formData.append('customer_name', customerName);
            formData.append('contact_person', getValue('editContactPerson'));
            formData.append('store_name', getValue('editStoreName'));
            formData.append('customer_group', getCustomerGroupValue('edit'));
            formData.append('price_level', getValue('editPriceLevel', 'Standard'));
            formData.append('existing_store_image', getValue('existingStoreImage'));
            formData.append('email', getValue('editEmail'));
            formData.append('phone_number', getValue('editPhoneNumber'));

            const regionVal = getValue('editRegion');
            formData.append('region', regionVal || '');

            const provinceVal = getValue('editProvince');
            formData.append('province', provinceVal || '');

            const cityVal = getValue('editCity');
            formData.append('city', cityVal || '');
            formData.append('city_code', getValue('editCityCode'));

            const barangaySelect = document.querySelector('.barangay-select-edit');
            const barangayInput = document.getElementById('editBarangayInput');
            let barangay = '';
            if (barangaySelect && !barangaySelect.disabled) {
                barangay = barangaySelect.value || '';
            } else if (barangayInput) {
                barangay = barangayInput.value || '';
            }
            formData.append('barangay', barangay);

            formData.append('notes', getValue('editNotes'));
            formData.append('status', getValue('editStatus', 'active'));

            const editStoreImageInput = document.querySelector('#editCustomerForm input[name="store_image"]');
            if (editStoreImageInput && editStoreImageInput.files && editStoreImageInput.files[0]) {
                formData.append('store_image', editStoreImageInput.files[0]);
            }

            const updateController = new AbortController();
            const updateTimeout = setTimeout(() => updateController.abort(), 20000);

            fetch(window.location.href, {
                method: 'POST',
                body: formData,
                signal: updateController.signal,
                cache: 'no-store'
            })
                .then(async res => {
                    clearTimeout(updateTimeout);
                    const text = await res.text();

                    let data;
                    try {
                        data = JSON.parse(text);
                    } catch (e) {
                        console.error('Raw update response:', text);
                        throw new Error('Invalid server response. Check PHP error log or database column setup.');
                    }

                    if (!res.ok) {
                        throw new Error(data.message || 'Server error while updating customer');
                    }

                    return data;
                })
                .then(data => {
                    Swal.close();
                    if (data.success) {
                        const editModal = bootstrap.Modal.getInstance(document.getElementById('editCustomerModal'));
                        if (editModal) editModal.hide();

                        Swal.fire({
                            icon: 'success',
                            title: 'Success!',
                            text: data.message || 'Customer updated successfully',
                            timer: 1500,
                            showConfirmButton: false
                        }).then(() => location.reload());
                    } else {
                        Swal.fire('Error', data.message || 'Failed to update customer', 'error');
                    }
                })
                .catch(error => {
                    Swal.close();
                    console.error('Update customer error:', error);
                    if (error.name === 'AbortError') {
                        Swal.fire('Error', 'Update request timed out. Please try again.', 'error');
                    } else {
                        Swal.fire('Error', error.message || 'An error occurred while updating customer', 'error');
                    }
                });
        } catch (error) {
            Swal.close();
            console.error('Update customer script error:', error);
            Swal.fire('Error', error.message || 'Update form error. Please check the edit modal fields.', 'error');
        }
    }
    
    function editFromView() {
        bootstrap.Modal.getInstance(document.getElementById('viewCustomerModal')).hide();
        setTimeout(() => editCustomer(currentCustomerId), 300);
    }
    
    function deleteCustomer(id) {
        const card = document.querySelector(`.customer-card[data-customer-id="${id}"]`);
        if (!card) return;
        
        if (card.dataset.isWalkin === 'true') {
            Swal.fire({
                icon: 'warning',
                title: 'Cannot Delete Walk-in Customer',
                text: 'Walk-in customer is a system default and cannot be deleted.',
                confirmButtonColor: '#059669'
            });
            return;
        }
        
        document.getElementById('deleteCustomerName').innerText = card.dataset.customerName;
        deleteCustomerId = id;
        new bootstrap.Modal(document.getElementById('deleteCustomerModal')).show();
    }
    
    function confirmDeleteCustomer() {
        showLoading();
        const formData = new FormData();
        formData.append('action', 'delete_customer');
        formData.append('customer_id', deleteCustomerId);
        fetch(window.location.href, { method: 'POST', body: formData })
            .then(res => res.json())
            .then(data => {
                Swal.close();
                if (data.success) {
                    bootstrap.Modal.getInstance(document.getElementById('deleteCustomerModal')).hide();
                    Swal.fire({ icon: 'success', title: 'Success!', text: data.message, timer: 2000, showConfirmButton: false })
                        .then(() => location.reload());
                } else {
                    Swal.fire('Error', data.message, 'error');
                }
            }).catch(() => { Swal.close(); Swal.fire('Error', 'An error occurred', 'error'); });
    }
    
    function openOrderProductFlexible() {
        Swal.fire({
            title: 'Create Order',
            text: 'You will be redirected to Order Products. You can select or change the customer from the dropdown.',
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#059669',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Continue',
            cancelButtonText: 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                window.location.href = 'orderproduct.php';
            }
        });
    }
    
    function orderProduct(customerId, customerName, isWalkin = false) {
        let titleText = isWalkin ? 'Create Walk-in Order' : 'Create Order for ' + customerName + '?';
        let confirmText = isWalkin ? 'Yes, Start Walk-in Order' : 'Yes, Create Order';
        
        Swal.fire({
            title: titleText,
            text: isWalkin ? 'Start a new walk-in order. Customer will be pre-selected.' : 'You will be redirected to the order page to create a new order for this customer.',
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#059669',
            cancelButtonColor: '#6c757d',
            confirmButtonText: confirmText,
            cancelButtonText: 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                window.location.href = 'orderproduct.php?customer_id=' + customerId + '&customer_name=' + encodeURIComponent(customerName) + '&lock_customer=1';
            }
        });
    }
    
    function escapeHtml(str) {
        if (!str) return '';
        return str.replace(/[&<>]/g, function(m) {
            if (m === '&') return '&amp;';
            if (m === '<') return '&lt;';
            if (m === '>') return '&gt;';
            return m;
        });
    }
    
    // ============= SIDEBAR FUNCTIONS =============
    function toggleSidebarDropdown(event, targetId) {
        event.preventDefault(); event.stopPropagation();
        const target = document.getElementById(targetId);
        const btn = event.currentTarget;
        const arrow = btn.querySelector('.dropdown-arrow');
        const sidebar = document.getElementById('sidebar');
        if (sidebar.classList.contains('collapsed')) {
            sidebar.classList.remove('collapsed');
            localStorage.setItem('sidebarCollapsed', 'false');
            setTimeout(() => {
                document.querySelectorAll('.sidebar .collapse.show').forEach(collapse => { if (collapse.id !== targetId) collapse.classList.remove('show'); });
                target.classList.add('show');
                updateEmployeesTaskBadge();
                if (arrow) arrow.style.transform = 'translateY(-50%) rotate(180deg)';
            }, 50);
            return;
        }
        if (target.classList.contains('show')) {
            target.classList.remove('show');
            if (arrow) arrow.style.transform = 'translateY(-50%) rotate(0deg)';
        } else {
            document.querySelectorAll('.sidebar .collapse.show').forEach(collapse => collapse.classList.remove('show'));
            target.classList.add('show');
            if (arrow) arrow.style.transform = 'translateY(-50%) rotate(180deg)';
        }
        updateEmployeesTaskBadge();
    }
    
    function toggleSidebar() {
        const sidebar = document.getElementById('sidebar');
        if (window.innerWidth <= 992) {
            sidebar.classList.toggle('active');
            let overlay = document.querySelector('.sidebar-overlay');
            if (!overlay) { overlay = document.createElement('div'); overlay.className = 'sidebar-overlay'; document.body.appendChild(overlay); overlay.addEventListener('click', function() { sidebar.classList.remove('active'); overlay.remove(); }); }
            setTimeout(() => overlay.classList.add('active'), 10);
        } else {
            const wasCollapsed = sidebar.classList.contains('collapsed');
            sidebar.classList.toggle('collapsed');
            localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
            if (wasCollapsed && !sidebar.classList.contains('collapsed')) setTimeout(() => { expandActiveDropdownContainers(); }, 150);
        }
    }
    
    function expandActiveDropdownContainers() {
        document.querySelectorAll('.sidebar .dropdown-nav').forEach(dropdownNav => {
            if (dropdownNav.querySelector('.nav-link.active')) {
                const collapseDiv = dropdownNav.querySelector('.collapse');
                if (collapseDiv && !collapseDiv.classList.contains('show')) {
                    collapseDiv.classList.add('show');
                    const parentLink = dropdownNav.querySelector(':scope > .nav-link');
                    if (parentLink) {
                        const arrow = parentLink.querySelector('.dropdown-arrow');
                        if (arrow) arrow.style.transform = 'translateY(-50%) rotate(180deg)';
                    }
                }
            }
        });
    }
    
    function setActiveSidebarItem() {
        const currentPage = window.location.pathname.split('/').pop();
        document.querySelectorAll('.sidebar .nav-link').forEach(link => link.classList.remove('active'));
        document.querySelectorAll('.sidebar .nav-link').forEach(link => {
            if (link.getAttribute('href') === currentPage) {
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
    
    function toggleDropdown(event, dropdownId) {
        event.preventDefault(); event.stopPropagation();
        const dropdown = document.getElementById(dropdownId);
        const btn = event.currentTarget;
        if (!dropdown) return;
        if (dropdown.classList.contains('show')) {
            dropdown.classList.remove('show');
            btn.classList.remove('active');
        } else {
            ['inventoryDropdownMenu', 'salesDropdownMenu', 'purchaseDropdownMenu', 'moreDropdownMenu'].forEach(id => { const d = document.getElementById(id); if (d && d !== dropdown) d.classList.remove('show'); });
            document.querySelectorAll('.more-btn').forEach(b => b.classList.remove('active'));
            dropdown.classList.add('show');
            btn.classList.add('active');
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
    }
function confirmLogout() {
        const modal = bootstrap.Modal.getInstance(document.getElementById('profileModal'));
        if (modal) modal.hide();
        Swal.fire({ title: 'Are you sure?', text: 'You will be logged out', icon: 'question', showCancelButton: true, confirmButtonColor: '#07d826', confirmButtonText: 'Yes, logout' }).then((result) => { if (result.isConfirmed) { localStorage.removeItem('sidebarCollapsed'); window.location.href = '../logout.php'; } });
    }
    
    function logout() { confirmLogout(); }
    
    document.addEventListener('DOMContentLoaded', function() {
        const sidebar = document.getElementById('sidebar');
        if (sidebar && window.innerWidth > 992) {
            if (localStorage.getItem('sidebarCollapsed') === 'true') sidebar.classList.add('collapsed');
            else sidebar.classList.remove('collapsed');
        }
        setActiveSidebarItem();
        document.getElementById('mobileMenuBtn').addEventListener('click', toggleSidebar);
        document.getElementById('desktopToggleBtn').addEventListener('click', toggleSidebar);
        
        // Load city codes
        loadCityCodes();
        
        // Initialize add modal location dropdowns
        initAddLocationDropdowns();
        
        // Ensure walk-in card is always first after page load
        setTimeout(() => {
            const container = document.getElementById('customerCardsContainer');
            const walkinCard = document.querySelector('.customer-card.walkin-card');
            if (container && walkinCard && container.firstChild !== walkinCard) {
                container.insertBefore(walkinCard, container.firstChild);
            }
        }, 100);
        document.addEventListener('DOMContentLoaded', function () {
    updateEmployeesTaskBadge();
});
    });
</script>

<script>
(function(){
    function initCreditRequestFilterDropdown(){
        const content = document.getElementById('filterContent');
        if (content) {
            content.classList.remove('collapsed');
            content.style.display = 'block';
            content.style.maxHeight = 'none';
            content.style.opacity = '1';
        }
    }
    if(document.readyState === 'loading'){
        document.addEventListener('DOMContentLoaded', initCreditRequestFilterDropdown);
    }else{
        initCreditRequestFilterDropdown();
    }
    window.initCreditRequestFilterDropdown = initCreditRequestFilterDropdown;
})();
</script>
<script>
function switchCustomerMainTab(tabId){document.querySelectorAll('.customer-main-tab-pane').forEach(p=>p.classList.remove('active'));document.querySelectorAll('.customer-main-tab-btn').forEach(b=>b.classList.remove('active'));const t=document.getElementById(tabId);if(t)t.classList.add('active');const b=document.querySelector(`.customer-main-tab-btn[data-tab="${tabId}"]`);if(b)b.classList.add('active');const title=document.querySelector('.page-title h2');const sub=document.querySelector('.page-title p');if(title&&sub){if(tabId==='creditRequestsTabContent'){title.textContent='Approve Credit/Discount Requests';sub.textContent='Review and approve/reject requests from sales agents';if(typeof initCreditRequestFilterDropdown==='function'){setTimeout(initCreditRequestFilterDropdown,0);}}else{title.textContent='Customer List';sub.textContent='Manage all customers';}}}
document.addEventListener('DOMContentLoaded',function(){if(window.location.hash==='#credit-requests'){switchCustomerMainTab('creditRequestsTabContent');}});
</script>
<script>
let selectedRequestId = null;
    let currentAction = null;
    const creditBranchId = <?php echo $branch_id; ?>;
    const creditViewAllBranches = <?php echo $view_all_branches ? 'true' : 'false'; ?>;
    const creditCustomersBranchColumnExists = <?php echo $customers_branch_column_exists ? 'true' : 'false'; ?>;
    const creditEffectiveColumnsExist = <?php echo $effective_columns_exist ? 'true' : 'false'; ?>;
    const creditLogoBase64 = '<?php echo $logo_base64; ?>';
    let globalScrollTimeout;

    // Update expiration value display
    function updateExpirationValue(value) {
        let num = parseInt(value, 10);
        if (isNaN(num) || num < 1) {
            num = 1;
        } else if (num > 365) {
            num = 365;
        }
        const input = document.getElementById('expirationDays');
        const label = document.getElementById('expirationValue');
        if (input) input.value = num;
        if (label) label.innerText = num + ' day' + (num > 1 ? 's' : '');
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

    function showLoading() {
        if (typeof Swal !== 'undefined') {
            Swal.fire({
                title: 'Processing...',
                text: 'Please wait',
                allowOutsideClick: false,
                allowEscapeKey: false,
                showConfirmButton: false,
                didOpen: () => Swal.showLoading()
            });
        } else {
            const overlay = document.getElementById('loadingOverlay');
            if (overlay) overlay.style.display = 'flex';
        }
    }

    function hideLoading() {
        const overlay = document.getElementById('loadingOverlay');
        if (overlay) overlay.style.display = 'none';
        if (typeof Swal !== 'undefined') Swal.close();
    }

    // ========== VIEW ATTACHMENT FUNCTION ==========
// ========== VIEW ATTACHMENT FUNCTION ==========
let fileViewModal;

function viewAttachment(filePath, fileName) {
    // Get the current view request modal instance
    const viewModalElement = document.getElementById('viewRequestModal');
    const viewModal = bootstrap.Modal.getInstance(viewModalElement);
    
    // Store the current content to restore later (optional)
    const currentContent = document.getElementById('viewRequestContent')?.innerHTML;
    if (currentContent) {
        sessionStorage.setItem('savedContent', currentContent);
    }
    
    // Hide the view request modal first with a class to prevent flicker
    if (viewModal) {
        viewModalElement.classList.add('hiding-for-attachment');
        viewModal.hide();
        setTimeout(() => {
            viewModalElement.classList.remove('hiding-for-attachment');
        }, 300);
    }
    
    // Store in session that we came from view modal
    sessionStorage.setItem('returnToViewModal', 'true');
    
    if (!fileViewModal) {
        fileViewModal = new bootstrap.Modal(document.getElementById('fileViewModal'));
    }
    
    const attachmentContent = document.querySelector('#fileViewModal .attachment-content');
    const downloadLink = document.getElementById('downloadLink');
    
    // Set download link
    downloadLink.href = filePath;
    downloadLink.download = fileName;
    
    const ext = fileName.split('.').pop().toLowerCase();
    
    // Clear previous content
    attachmentContent.innerHTML = '';
    
    if (['jpg', 'jpeg', 'png', 'gif'].includes(ext)) {
        const img = document.createElement('img');
        img.src = filePath;
        img.alt = fileName;
        img.style.opacity = '0';
        img.onload = function() {
            img.style.opacity = '1';
            console.log('Image loaded:', img.width, 'x', img.height);
        };
        attachmentContent.appendChild(img);
    } else if (ext === 'pdf') {
        const embed = document.createElement('embed');
        embed.src = filePath;
        embed.type = 'application/pdf';
        attachmentContent.appendChild(embed);
    } else {
        attachmentContent.innerHTML = `
            <div class="alert alert-info m-0">
                <i class="bi bi-info-circle me-2"></i> 
                This file type cannot be previewed directly. Please download to view.
            </div>
        `;
    }
    
    // Remove existing event listener to avoid duplicates
    document.getElementById('fileViewModal').removeEventListener('hidden.bs.modal', handleFileModalHidden);
    document.getElementById('fileViewModal').addEventListener('hidden.bs.modal', handleFileModalHidden);
    
    fileViewModal.show();
}

function handleFileModalHidden() {
    // Use requestAnimationFrame for smooth transition
    requestAnimationFrame(function() {
        // Clean up backdrops without flicker
        const backdrops = document.querySelectorAll('.modal-backdrop');
        if (backdrops.length > 1) {
            backdrops.forEach((backdrop, index) => {
                if (index < backdrops.length - 1 && backdrop.parentNode) {
                    backdrop.remove();
                }
            });
        }
        
        // Reset attachment content silently
        const attachmentContent = document.querySelector('#fileViewModal .attachment-content');
        if (attachmentContent) {
            attachmentContent.innerHTML = '<div class="spinner-border text-light" role="status"><span class="visually-hidden">Loading...</span></div>';
        }
        
        // Check if we need to return to view modal
        if (sessionStorage.getItem('returnToViewModal') === 'true') {
            sessionStorage.removeItem('returnToViewModal');
            
            // Get the view request modal element
            const viewModalElement = document.getElementById('viewRequestModal');
            if (viewModalElement) {
                // Use a very short delay and ensure no white flash
                setTimeout(function() {
                    // Ensure body has modal-open class
                    if (!document.body.classList.contains('modal-open')) {
                        document.body.classList.add('modal-open');
                    }
                    
                    // Show the modal without recreating the instance
                    const viewModal = bootstrap.Modal.getInstance(viewModalElement);
                    if (viewModal) {
                        viewModal.show();
                    } else {
                        new bootstrap.Modal(viewModalElement).show();
                    }
                    
                    // Ensure backdrop is correct
                    setTimeout(function() {
                        const modalBackdrop = document.querySelector('.modal-backdrop');
                        if (!modalBackdrop && viewModalElement.classList.contains('show')) {
                            const newBackdrop = document.createElement('div');
                            newBackdrop.className = 'modal-backdrop fade show';
                            document.body.appendChild(newBackdrop);
                        }
                    }, 50);
                }, 50);
            }
        } else {
            // If no modal to return to, just clean up
            const anyModalOpen = document.querySelector('.modal.show');
            if (!anyModalOpen) {
                if (backdrops.length > 0) {
                    backdrops.forEach(backdrop => {
                        if (backdrop && backdrop.parentNode) backdrop.remove();
                    });
                }
                document.body.classList.remove('modal-open');
                document.body.style.removeProperty('overflow');
                document.body.style.removeProperty('padding-right');
            }
        }
    });
}


    // ========== FILTER FUNCTION ==========
    function filterRequests() {
        console.log("filterRequests called");
        
        const statusFilter = document.getElementById('statusFilter');
        const typeFilter = document.getElementById('typeFilter');
        const searchInput = document.getElementById('creditSearchInput');
        
        const statusValue = statusFilter ? statusFilter.value : 'all';
        const typeValue = typeFilter ? typeFilter.value : 'all';
        const searchTerm = searchInput ? searchInput.value.toLowerCase().trim() : '';
        
        console.log("Filters - Status:", statusValue, "Type:", typeValue, "Search:", searchTerm);
        
        const rows = document.querySelectorAll('#requestsTable tbody tr.request-row');
        let visibleCount = 0;
        
        rows.forEach(row => {
            if (row.querySelector('.empty-state-table')) {
                row.style.display = '';
                return;
            }
            
            let showRow = true;
            const rowStatus = row.getAttribute('data-status') || '';
            const rowType = row.getAttribute('data-type') || '';
            
            const customerCell = row.querySelector('td.col-customer');
            let customerName = '';
            let customerCode = '';
            
            if (customerCell) {
                const strongElement = customerCell.querySelector('strong');
                if (strongElement) {
                    customerName = strongElement.innerText.toLowerCase();
                }
                const smallElement = customerCell.querySelector('small');
                if (smallElement) {
                    customerCode = smallElement.innerText.toLowerCase();
                }
            }
            
            const dataCustomer = (row.getAttribute('data-customer') || '').toLowerCase();
            const searchableCustomerText = customerName + ' ' + customerCode + ' ' + dataCustomer;
            
            let agentName = (row.getAttribute('data-agent') || '').toLowerCase();
            const agentCell = row.querySelector('td.col-agent');
            if (agentCell && !agentName) {
                agentName = agentCell.innerText.toLowerCase();
            }
            
            const rowCredit = row.getAttribute('data-credit');
            const rowDiscount = row.getAttribute('data-discount');
            const hasCredit = rowCredit && rowCredit !== '' && rowCredit !== 'null' && parseFloat(rowCredit) > 0;
            const hasDiscount = rowDiscount && rowDiscount !== '' && rowDiscount !== 'null' && parseFloat(rowDiscount) > 0;
            
            if (statusValue !== 'all' && rowStatus !== statusValue) {
                showRow = false;
            }
            
            if (showRow && typeValue !== 'all') {
                switch (typeValue) {
                    case 'credit':
                        showRow = hasCredit;
                        break;
                    case 'discount':
                        showRow = hasDiscount;
                        break;
                    case 'both':
                        showRow = hasCredit && hasDiscount;
                        break;
                    case 'credit_terms':
                        showRow = rowType === 'credit_terms';
                        break;
                    default:
                        showRow = true;
                }
            }
            
            if (showRow && searchTerm !== '') {
                const searchableText = searchableCustomerText + ' ' + agentName;
                if (!searchableText.includes(searchTerm)) {
                    showRow = false;
                }
            }
            
            if (showRow) {
                row.style.display = '';
                visibleCount++;
            } else {
                row.style.display = 'none';
            }
        });
        
        console.log("Visible rows:", visibleCount, "Total rows:", rows.length);
        showEmptyFilterMessage(visibleCount === 0 && rows.length > 0);
        
        // Reinitialize tap to view after filtering
        setTimeout(function() {
            setupTapToView();
        }, 100);
    }
    
    function showEmptyFilterMessage(show) {
        let emptyMsg = document.getElementById('filterEmptyMessage');
        
        if (show) {
            if (!emptyMsg) {
                const tableBody = document.querySelector('#requestsTable tbody');
                if (tableBody && !tableBody.querySelector('#filterEmptyMessage')) {
                    emptyMsg = document.createElement('tr');
                    emptyMsg.id = 'filterEmptyMessage';
                    emptyMsg.innerHTML = `
                        <td colspan="9" class="empty-state-table">
                            <i class="bi bi-search"></i>
                            <h5>No matching requests</h5>
                            <p class="text-muted">Try adjusting your filters or search term</p>
                        </td>
                    `;
                    tableBody.appendChild(emptyMsg);
                }
            } else {
                emptyMsg.style.display = '';
            }
        } else {
            if (emptyMsg) {
                emptyMsg.style.display = 'none';
            }
        }
    }

    // ========== RE-INITIALIZE FILTER EVENTS ==========
    function initFilterEvents() {
        console.log("Initializing filter events");
        
        const statusFilter = document.getElementById('statusFilter');
        const typeFilter = document.getElementById('typeFilter');
        const searchInput = document.getElementById('creditSearchInput');
        
        if (statusFilter) {
            statusFilter.removeAttribute('onchange');
            const newStatusFilter = statusFilter.cloneNode(true);
            statusFilter.parentNode.replaceChild(newStatusFilter, statusFilter);
            newStatusFilter.addEventListener('change', function() {
                filterRequests();
            });
        }
        
        if (typeFilter) {
            typeFilter.removeAttribute('onchange');
            const newTypeFilter = typeFilter.cloneNode(true);
            typeFilter.parentNode.replaceChild(newTypeFilter, typeFilter);
            newTypeFilter.addEventListener('change', function() {
                filterRequests();
            });
        }
        
        if (searchInput) {
            searchInput.removeAttribute('onkeyup');
            const newSearchInput = searchInput.cloneNode(true);
            searchInput.parentNode.replaceChild(newSearchInput, searchInput);
            newSearchInput.addEventListener('input', function() {
                filterRequests();
            });
            newSearchInput.addEventListener('keyup', function(e) {
                if (e.key === 'Enter') {
                    filterRequests();
                }
            });
        }
        
        console.log("Filter events initialized");
    }

    // ========== TAP TO VIEW - WORKS ON ALL DEVICES (Desktop & Mobile) ==========
    function setupTapToView() {
        const requestRows = document.querySelectorAll('#requestsTable tbody tr.request-row');
        
        requestRows.forEach(row => {
            // Skip empty state rows
            if (row.querySelector('.empty-state-table')) return;
            
            // Check if row is visible
            const isVisible = row.style.display !== 'none';
            
            if (isVisible) {
                // Remove existing listeners to avoid duplicates
                if (row.hasAttribute('data-tap-listener')) {
                    row.removeEventListener('click', handleRowClick);
                    row.removeAttribute('data-tap-listener');
                }
                
                // Add click listener to the entire row
                row.setAttribute('data-tap-listener', 'true');
                row.addEventListener('click', handleRowClick);
                row.style.cursor = 'pointer';
                
                // Add hover effect for desktop
                row.addEventListener('mouseenter', function() {
                    this.style.backgroundColor = '#f8f9fa';
                    this.style.transition = 'background-color 0.2s ease';
                });
                row.addEventListener('mouseleave', function() {
                    this.style.backgroundColor = '';
                });
            } else {
                // Remove listeners for hidden rows
                if (row.hasAttribute('data-tap-listener')) {
                    row.removeEventListener('click', handleRowClick);
                    row.removeAttribute('data-tap-listener');
                    row.style.cursor = '';
                }
            }
        });
    }

    // Handle row click
    function handleRowClick(event) {
        // Don't trigger if clicked on a button or inside a button
        if (event.target.closest('.btn-action') || 
            event.target.closest('.attachment-badge') || 
            event.target.closest('.btn')) {
            return;
        }
        
        const row = event.currentTarget;
        const requestId = row.getAttribute('data-id');
        if (requestId && typeof viewRequest === 'function') {
            event.preventDefault();
            viewRequest(requestId);
        }
    }

    // ========== VIEW REQUEST DETAILS ==========
    function viewRequest(id) {
        showLoading();
        
        const formData = new FormData();
        formData.append('action', 'view_request');
        formData.append('request_id', id);
        
        fetch(window.location.href, {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            hideLoading();
            
            if (data.success) {
                const r = data.request;
                const attachments = data.attachments || [];
                
                let typeText = r.request_type === 'credit' ? 'Credit Limit Increase' :
                               r.request_type === 'discount' ? 'Discount' :
                               r.request_type === 'both' ? 'Credit & Discount' :
                               r.request_type === 'credit_terms' ? 'Credit Terms' : 'Unknown';
                
                let creditHtml = r.requested_credit_limit ? '₱' + parseFloat(r.requested_credit_limit).toFixed(2) : '—';
                let discountHtml = r.requested_discount_percent ? r.requested_discount_percent + '%' : '—';
                let termsHtml = r.credit_terms_days ? r.credit_terms_days + ' days' : '—';
                let validityHtml = '—';
                if (r.effective_until) {
                    validityHtml = 'Until: ' + new Date(r.effective_until).toLocaleDateString();
                } else if (r.status === 'approved') {
                    validityHtml = 'No expiry set';
                }
                
                let statusClass = '';
                let isExpired = r.effective_until && new Date(r.effective_until) < new Date();
                if (r.status === 'pending') statusClass = 'status-pending';
                else if (r.status === 'approved' && isExpired) statusClass = 'status-expired';
                else if (r.status === 'approved') statusClass = 'status-approved';
                else statusClass = 'status-rejected';
                
                let statusDisplay = r.status;
                if (r.status === 'approved' && isExpired) statusDisplay = 'Expired';
                
                // Build attachments HTML
                let attachmentsHtml = '';
                if (attachments.length > 0) {
                    let attachmentItems = '';
                    attachments.forEach(att => {
                        const ext = att.original_file_name.split('.').pop().toLowerCase();
                        let iconClass = 'file-earmark-text';
                        if (['jpg', 'jpeg', 'png', 'gif'].includes(ext)) iconClass = 'file-earmark-image';
                        else if (ext === 'pdf') iconClass = 'file-earmark-pdf';
                        else if (['doc', 'docx'].includes(ext)) iconClass = 'file-earmark-word';
                        else if (['xls', 'xlsx'].includes(ext)) iconClass = 'file-earmark-excel';
                        
                        attachmentItems += `
                            <a href="#" class="attachment-item" onclick="viewAttachment('${att.file_path}', '${escapeHtml(att.original_file_name)}'); return false;">
                                <i class="bi bi-${iconClass}"></i>
                                ${escapeHtml(att.original_file_name)}
                                <small class="text-muted">(${(att.file_size / 1024).toFixed(1)} KB)</small>
                            </a>
                        `;
                    });
                    attachmentsHtml = `
                        <div class="attachment-section">
                            <div class="card-title">
                                <i class="bi bi-paperclip"></i> Supporting Documents (${attachments.length} files)
                            </div>
                            <div class="card-body">
                                <div class="attachment-list-modal">
                                    ${attachmentItems}
                                </div>
                            </div>
                        </div>
                    `;
                }
                
                let reasonHtml = escapeHtml(r.reason).replace(/\n/g, '<br>');
                if (!reasonHtml) reasonHtml = '—';
                
                const content = document.getElementById('viewRequestContent');
                content.innerHTML = `
                    <div class="row">
                        <div class="col-md-6">
                            <div class="info-card">
                                <div class="card-title">
                                    <i class="bi bi-info-circle"></i> Request Information
                                </div>
                                <div class="card-body">
                                    <div class="info-row">
                                        <div class="info-label">Request ID:</div>
                                        <div class="info-value">${r.request_id}</div>
                                    </div>
                                    <div class="info-row">
                                        <div class="info-label">Customer:</div>
                                        <div class="info-value"><strong>${escapeHtml(r.customer_name)}</strong><br><small>${escapeHtml(r.customer_code)}</small></div>
                                    </div>
                                    <div class="info-row">
                                        <div class="info-label">Agent:</div>
                                        <div class="info-value">${escapeHtml(r.first_name)} ${escapeHtml(r.last_name)}<br><small>${escapeHtml(r.agent_email)}</small></div>
                                    </div>
                                    <div class="info-row">
                                        <div class="info-label">Request Type:</div>
                                        <div class="info-value"><span class="type-badge ${r.request_type === 'credit' ? 'type-credit' : (r.request_type === 'discount' ? 'type-discount' : (r.request_type === 'both' ? 'type-both' : 'type-credit_terms'))}">${typeText}</span></div>
                                    </div>
                                    <div class="info-row">
                                        <div class="info-label">Credit Limit:</div>
                                        <div class="info-value">${creditHtml}</div>
                                    </div>
                                    <div class="info-row">
                                        <div class="info-label">Discount %:</div>
                                        <div class="info-value">${discountHtml}</div>
                                    </div>
                                    <div class="info-row">
                                        <div class="info-label">Credit Terms:</div>
                                        <div class="info-value">${termsHtml}</div>
                                    </div>
                                    <div class="info-row">
                                        <div class="info-label">Validity:</div>
                                        <div class="info-value">${validityHtml}</div>
                                    </div>
                                    <div class="info-row">
                                        <div class="info-label">Status:</div>
                                        <div class="info-value"><span class="status-badge ${statusClass}">${statusDisplay}</span></div>
                                    </div>
                                    <div class="info-row">
                                        <div class="info-label">Created:</div>
                                        <div class="info-value">${new Date(r.created_at).toLocaleString()}</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="info-card">
                                <div class="card-title">
                                    <i class="bi bi-chat-text"></i> Reason for Request
                                </div>
                                <div class="card-body">
                                    <div class="info-row">
                                        <div class="info-value">${reasonHtml}</div>
                                    </div>
                                    ${r.admin_notes ? `
                                    <div class="info-row mt-3">
                                        <div class="info-label">Admin Notes:</div>
                                        <div class="info-value">${escapeHtml(r.admin_notes).replace(/\n/g, '<br>')}</div>
                                    </div>
                                    ` : ''}
                                    ${r.approved_at ? `
                                    <div class="info-row mt-3">
                                        <div class="info-label">Approved at:</div>
                                        <div class="info-value">${new Date(r.approved_at).toLocaleString()}</div>
                                    </div>
                                    ` : ''}
                                </div>
                            </div>
                        </div>
                    </div>
                    ${attachmentsHtml}
                `;
                
                const modalFooter = document.getElementById('modalFooterActions');
                    if (modalFooter) {
                        // Clear existing buttons
                        modalFooter.innerHTML = '';
                        
                        if (r.status === 'pending') {
                            modalFooter.innerHTML += `
                                <button type="button" class="btn btn-danger" onclick="closeModalAndShowReject(${r.request_id})">
                                    <i class="bi bi-x-circle"></i> Reject
                                </button>
                                <button type="button" class="btn btn-success" onclick="closeModalAndShowApprove(${r.request_id})">
                                    <i class="bi bi-check-circle"></i> Approve
                                </button>
                            `;
                        }
                    }
                
                selectedRequestId = id;
                new bootstrap.Modal(document.getElementById('viewRequestModal')).show();
            } else {
                Swal.fire('Error', data.message, 'error');
            }
        })
        .catch(error => {
            hideLoading();
            Swal.fire('Error', 'An error occurred while fetching request details', 'error');
        });
    }

    function closeModalAndShowApprove(requestId) {
        const modal = bootstrap.Modal.getInstance(document.getElementById('viewRequestModal'));
        if (modal) {
            modal.hide();
        }
        setTimeout(() => {
            showApprovalModal(requestId, 'approve');
        }, 300);
    }

    function closeModalAndShowReject(requestId) {
        const modal = bootstrap.Modal.getInstance(document.getElementById('viewRequestModal'));
        if (modal) {
            modal.hide();
        }
        setTimeout(() => {
            showApprovalModal(requestId, 'reject');
        }, 300);
    }

    function escapeHtml(str) {
        if (!str) return '';
        return str.replace(/[&<>]/g, function(m) {
            if (m === '&') return '&amp;';
            if (m === '<') return '&lt;';
            if (m === '>') return '&gt;';
            return m;
        });
    }

    function showApprovalModal(id, action) {
        selectedRequestId = id;
        currentAction = action;
        
        const modalTitle = document.getElementById('approvalModalTitle');
        const modalHeader = document.getElementById('approvalModalHeader');
        const approvalMessage = document.getElementById('approvalMessage');
        const approvalFields = document.getElementById('approvalFields');
        const rejectionFields = document.getElementById('rejectionFields');
        const approveBtn = document.getElementById('approveBtn');
        const rejectBtn = document.getElementById('rejectBtn');
        
        if (action === 'approve') {
            modalTitle.textContent = 'Approve Request';
            modalHeader.className = 'modal-header bg-success text-white';
            approvalMessage.textContent = 'Approve this request?';
            approvalFields.style.display = 'block';
            rejectionFields.style.display = 'none';
            approveBtn.style.display = 'inline-block';
            rejectBtn.style.display = 'none';
            document.getElementById('adminNotes').value = '';
            document.getElementById('expirationDays').value = '30';
            updateExpirationValue(30);
        } else {
            modalTitle.textContent = 'Reject Request';
            modalHeader.className = 'modal-header bg-danger text-white';
            approvalMessage.textContent = 'Reject this request?';
            approvalFields.style.display = 'none';
            rejectionFields.style.display = 'block';
            approveBtn.style.display = 'none';
            rejectBtn.style.display = 'inline-block';
            document.getElementById('rejectionReason').value = '';
        }
        
        new bootstrap.Modal(document.getElementById('approvalModal')).show();
    }

    function confirmApproval(action) {
        if (action === 'approve') {
            const adminNotes = document.getElementById('adminNotes').value;
            let expirationDays = parseInt(document.getElementById('expirationDays').value, 10);

            if (isNaN(expirationDays) || expirationDays < 1) {
                Swal.fire('Warning', 'Please enter a valid number of days', 'warning');
                return;
            }

            if (expirationDays > 365) {
                expirationDays = 365;
                document.getElementById('expirationDays').value = 365;
                updateExpirationValue(365);
            }
            
            showLoading();
            
            const formData = new FormData();
            formData.append('action', 'approve_request');
            formData.append('request_id', selectedRequestId);
            formData.append('admin_notes', adminNotes);
            formData.append('expiration_days', expirationDays);
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                hideLoading();
                
                if (data.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Approved!',
                        text: data.message,
                        timer: 3000,
                        showConfirmButton: false
                    }).then(() => {
                        bootstrap.Modal.getInstance(document.getElementById('approvalModal')).hide();
                        location.reload();
                    });
                } else {
                    Swal.fire('Error', data.message, 'error');
                }
            })
            .catch(error => {
                hideLoading();
                console.error('Fetch error:', error);
                Swal.fire('Error', 'Network error: ' + error.message, 'error');
            });
        } else {
            const rejectionReason = document.getElementById('rejectionReason').value;
            
            if (!rejectionReason) {
                Swal.fire('Warning', 'Please enter a rejection reason', 'warning');
                return;
            }
            
            showLoading();
            
            const formData = new FormData();
            formData.append('action', 'reject_request');
            formData.append('request_id', selectedRequestId);
            formData.append('rejection_reason', rejectionReason);
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                hideLoading();
                
                if (data.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Rejected!',
                        text: data.message,
                        timer: 2000,
                        showConfirmButton: false
                    }).then(() => {
                        bootstrap.Modal.getInstance(document.getElementById('approvalModal')).hide();
                        location.reload();
                    });
                } else {
                    Swal.fire('Error', data.message, 'error');
                }
            })
            .catch(error => {
                hideLoading();
                Swal.fire('Error', 'An error occurred while rejecting request', 'error');
            });
        }
    }

    // ========== PRINT FUNCTION ==========
    function printRequests() {
        const printBtn = document.querySelector('.btn-outline-primary');
        if (printBtn && printBtn.innerText.includes('Print')) {
            const originalText = printBtn.innerHTML;
            printBtn.innerHTML = '<i class="bi bi-printer"></i> Preparing...';
            printBtn.disabled = true;
            
            setTimeout(() => {
                printBtn.innerHTML = originalText;
                printBtn.disabled = false;
            }, 2000);
        }

        const visibleRows = document.querySelectorAll('.request-row:not([style*="display: none"])');
        
        if (visibleRows.length === 0) {
            Swal.fire('Warning', 'No requests to print', 'warning');
            return;
        }
        
        let tableRows = '';
        let totalPending = 0, totalApproved = 0, totalRejected = 0;
        
        visibleRows.forEach(row => {
            const cells = row.querySelectorAll('td');
            let idx = 0;
            const id = cells[idx++]?.innerText || '';
            const date = cells[idx++]?.innerText || '';
            const customer = cells[idx]?.querySelector('strong')?.innerText || cells[idx]?.innerText || '';
            idx++;
            const agent = cells[idx++]?.innerText || '';
            const typeCell = cells[idx++]?.innerText.trim() || '';
            const credit = cells[idx++]?.innerText || '';
            const discount = cells[idx++]?.innerText || '';
            const terms = cells[idx++]?.innerText || '';
            const reason = cells[idx++]?.innerText || '';
            const status = cells[idx++]?.innerText || '';
            const validity = cells[idx++]?.innerText || '';
            
            if (status === 'Pending') totalPending++;
            else if (status === 'Approved') totalApproved++;
            else if (status === 'Rejected') totalRejected++;
            
            tableRows += '<tr>';
            tableRows += `<td style="padding: 3px; border: 1px solid #000;">${escapeHtml(id)}</td>`;
            tableRows += `<td style="padding: 3px; border: 1px solid #000;">${escapeHtml(date)}</td>`;
            tableRows += `<td style="padding: 3px; border: 1px solid #000;">${escapeHtml(customer)}</td>`;
            tableRows += `<td style="padding: 3px; border: 1px solid #000;">${escapeHtml(agent)}</td>`;
            tableRows += `<td style="padding: 3px; border: 1px solid #000;">${escapeHtml(typeCell)}</td>`;
            tableRows += `<td style="padding: 3px; border: 1px solid #000; text-align: right;">${escapeHtml(credit)}</td>`;
            tableRows += `<td style="padding: 3px; border: 1px solid #000; text-align: right;">${escapeHtml(discount)}</td>`;
            tableRows += `<td style="padding: 3px; border: 1px solid #000; text-align: right;">${escapeHtml(terms)}</td>`;
            tableRows += `<td style="padding: 3px; border: 1px solid #000;">${escapeHtml(reason)}</td>`;
            tableRows += `<td style="padding: 3px; border: 1px solid #000;">${escapeHtml(status)}</td>`;
            tableRows += `<td style="padding: 3px; border: 1px solid #000;">${escapeHtml(validity)}</td>`;
            tableRows += '</tr>';
        });
        
        const currentDate = new Date().toLocaleDateString('en-US', { 
            year: 'numeric', 
            month: 'short', 
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });
        
        const htmlContent = `<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Credit/Discount Requests</title><style>body{font-family:Arial;margin:0;padding:0;font-size:9px}.print-container{max-width:100%;margin:0}.print-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:5px;border-bottom:1px solid #000;padding-bottom:3px}.logo-section{display:flex;align-items:center;gap:5px}.company-logo{width:30px;height:auto}.company-info h1{font-size:14px;margin:0;font-weight:bold}.company-info p{font-size:8px;margin:0}.report-title h2{font-size:12px;margin:0}.report-title .date-info{font-size:8px}.summary-box{border:1px solid #000;padding:3px;margin-bottom:5px;display:flex}.summary-item{flex:1;text-align:center;border-right:1px solid #000}.summary-item:last-child{border-right:none}.summary-label{font-size:8px;font-weight:bold}.summary-value{font-size:11px;font-weight:bold}table{width:100%;border-collapse:collapse;font-size:8px}th{border:1px solid #000;padding:3px;text-align:left;font-weight:bold;background:white !important;color:black !important}td{border:1px solid #000;padding:3px}.print-footer{margin-top:5px;border-top:1px solid #000;padding-top:3px;display:flex;justify-content:space-between;font-size:8px}</style></head><body><div class="print-container"><div class="print-header"><div class="logo-section"><img src="${creditLogoBase64}" alt="AMGC Logo" class="company-logo"><div class="company-info"><h1>AMGC</h1><p>Requests Report</p></div></div><div class="report-title"><h2>CREDIT/DISCOUNT REQUESTS</h2><div class="date-info">${currentDate}</div></div></div><div class="summary-box"><div class="summary-item"><div class="summary-label">Total</div><div class="summary-value">${visibleRows.length}</div></div><div class="summary-item"><div class="summary-label">Pending</div><div class="summary-value">${totalPending}</div></div><div class="summary-item"><div class="summary-label">Approved</div><div class="summary-value">${totalApproved}</div></div><div class="summary-item"><div class="summary-label">Rejected</div><div class="summary-value">${totalRejected}</div></div><div class="summary-item"><div class="summary-label">Branch</div><div class="summary-value">${!creditViewAllBranches ? 'Branch ' + creditBranchId : 'All'}</div></div></div><table><thead><tr><th>ID</th><th>Date</th><th>Customer</th><th>Agent</th><th>Type</th><th style="text-align:right">Credit</th><th style="text-align:right">Discount</th><th style="text-align:right">Terms</th><th>Reason</th><th>Status</th><th>Validity</th></tr></thead><tbody>${tableRows}</tbody></table><div class="print-footer"><div>Generated: ${currentDate}</div><div>${document.querySelector('.user-name-sidebar')?.textContent || 'Branch Admin'}</div></div></div></body></html>`;
        
        hideLoading();
        
        const iframe = document.getElementById('printFrame');
        const iframeDoc = iframe.contentWindow.document;
        
        iframeDoc.open();
        iframeDoc.write(htmlContent);
        iframeDoc.close();
        
        setTimeout(() => {
            iframe.contentWindow.focus();
            iframe.contentWindow.print();
        }, 250);
    }

    // ========== EXCEL EXPORT ==========
    function exportToExcel() {
        const rows = document.querySelectorAll('.request-row:not([style*="display: none"])');
        if (rows.length === 0) {
            Swal.fire('Warning', 'No requests to export', 'warning');
            return;
        }
        
        const excelData = [['Request ID', 'Date', 'Customer', 'Agent', 'Type', 'Credit Limit (₱)', 'Discount (%)', 'Credit Terms (Days)', 'Reason', 'Status', 'Validity']];
        
        rows.forEach(row => {
            const cells = row.querySelectorAll('td');
            let idx = 0;
            const id = cells[idx++]?.innerText || '';
            const date = cells[idx++]?.innerText || '';
            const customer = cells[idx]?.querySelector('strong')?.innerText || cells[idx]?.innerText || '';
            idx++;
            const agent = cells[idx++]?.innerText || '';
            const type = cells[idx++]?.innerText || '';
            const credit = cells[idx++]?.innerText.replace('₱', '').replace(/,/g, '') || '';
            const discount = cells[idx++]?.innerText.replace('%', '') || '';
            const terms = cells[idx++]?.innerText.replace(' days', '') || '';
            const reason = cells[idx++]?.innerText || '';
            const status = cells[idx++]?.innerText || '';
            const validity = cells[idx++]?.innerText || '';
            
            excelData.push([id, date, customer, agent, type, credit, discount, terms, reason, status, validity]);
        });
        
        const wb = XLSX.utils.book_new();
        const ws = XLSX.utils.aoa_to_sheet(excelData);
        ws['!cols'] = [{ wch: 8 }, { wch: 15 }, { wch: 25 }, { wch: 20 }, { wch: 12 }, { wch: 15 }, { wch: 12 }, { wch: 15 }, { wch: 30 }, { wch: 12 }, { wch: 15 }];
        XLSX.utils.book_append_sheet(wb, ws, 'CreditDiscountRequests');
        
        const date = new Date();
        const dateStr = date.toISOString().slice(0,10).replace(/-/g, '');
        let filename = `Credit_Discount_Requests_${dateStr}`;
        if (!creditViewAllBranches) filename += `_Branch_${creditBranchId}`;
        filename += '.xlsx';
        
        XLSX.writeFile(wb, filename);
        Swal.fire({ icon: 'success', title: 'Export Complete', timer: 2000, showConfirmButton: false });
    }

    // ========== COPY SQL FUNCTIONS ==========
    function copySQL() {
        const sql = `CREATE TABLE IF NOT EXISTS \`credit_discount_requests\` (...)`;
        navigator.clipboard.writeText(sql).then(() => {
            Swal.fire({ icon: 'success', title: 'Copied!', timer: 1500, showConfirmButton: false });
        });
    }
    
    function copyEffectiveSQL() {
        const sql = "ALTER TABLE `credit_discount_requests` ADD COLUMN `effective_from` datetime DEFAULT NULL AFTER `admin_notes`, ADD COLUMN `effective_until` datetime DEFAULT NULL AFTER `effective_from`;";
        navigator.clipboard.writeText(sql).then(() => {
            Swal.fire({ icon: 'success', title: 'Copied!', timer: 1500, showConfirmButton: false });
        });
    }
    
    function copyAttachmentsSQL() {
        const sql = `CREATE TABLE \`credit_discount_attachments\` (
  \`attachment_id\` int(11) NOT NULL AUTO_INCREMENT,
  \`request_id\` int(11) NOT NULL,
  \`file_name\` varchar(255) NOT NULL,
  \`original_file_name\` varchar(255) NOT NULL,
  \`file_path\` varchar(500) NOT NULL,
  \`file_size\` int(11) DEFAULT NULL,
  \`file_type\` varchar(100) DEFAULT NULL,
  \`uploaded_by\` int(11) NOT NULL,
  \`uploaded_at\` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (\`attachment_id\`),
  KEY \`request_id\` (\`request_id\`),
  KEY \`uploaded_by\` (\`uploaded_by\`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;`;
        navigator.clipboard.writeText(sql).then(() => {
            Swal.fire({ icon: 'success', title: 'Copied!', timer: 1500, showConfirmButton: false });
        });
    }

    


    // ========== MERGED PAGE FUNCTION SAFETY FIX ==========
    // This keeps Customer List and Approve Credit Request functions working after merging.
    (function () {
        function closeAllLoaders() {
            const overlay = document.getElementById('loadingOverlay');
            if (overlay) overlay.style.display = 'none';
            if (typeof Swal !== 'undefined' && Swal.isVisible && Swal.isVisible()) {
                Swal.close();
            }
        }

        // Make inline onclick handlers work even if this script is inside merged tab content.
        if (typeof editCustomer === 'function') window.editCustomer = editCustomer;
        if (typeof saveEditCustomer === 'function') window.saveEditCustomer = saveEditCustomer;
        if (typeof deleteCustomer === 'function') window.deleteCustomer = deleteCustomer;
        if (typeof confirmDeleteCustomer === 'function') window.confirmDeleteCustomer = confirmDeleteCustomer;
        if (typeof viewRequest === 'function') window.viewRequest = viewRequest;
        if (typeof showApprovalModal === 'function') window.showApprovalModal = showApprovalModal;
        if (typeof confirmApproval === 'function') window.confirmApproval = confirmApproval;
        if (typeof closeModalAndShowApprove === 'function') window.closeModalAndShowApprove = closeModalAndShowApprove;
        if (typeof closeModalAndShowReject === 'function') window.closeModalAndShowReject = closeModalAndShowReject;
        if (typeof filterRequests === 'function') window.filterRequests = filterRequests;
        if (typeof printRequests === 'function') window.printRequests = printRequests;
        if (typeof exportRequests === 'function') window.exportRequests = exportRequests;
        if (typeof resetFilters === 'function') window.resetFilters = resetFilters;
        if (typeof viewAttachment === 'function') window.viewAttachment = viewAttachment;

        // Approve Credit Request row click fallback. This avoids lost listeners after tab switching/filtering.
        document.addEventListener('click', function (e) {
            const row = e.target.closest('#requestsTable tbody tr.request-row');
            if (!row) return;
            if (e.target.closest('button, a, input, select, textarea, .btn, .attachment-badge')) return;
            const requestId = row.getAttribute('data-id');
            if (requestId && typeof window.viewRequest === 'function') {
                e.preventDefault();
                window.viewRequest(requestId);
            }
        }, true);

        // Customer card row click fallback. Buttons inside the card will not trigger this.
        document.addEventListener('click', function (e) {
            const card = e.target.closest('.customer-card[data-customer-id]');
            if (!card) return;
            if (e.target.closest('button, a, input, select, textarea, .btn, .dropdown-menu, .dropdown-toggle')) return;
            const customerId = card.getAttribute('data-customer-id');
            if (customerId && typeof window.viewCustomer === 'function') {
                e.preventDefault();
                window.viewCustomer(customerId);
            }
        }, true);

        // Prevent loader from staying over an opened Bootstrap modal.
        document.addEventListener('shown.bs.modal', function () {
            closeAllLoaders();
        });

        window.addEventListener('unhandledrejection', closeAllLoaders);
        window.addEventListener('error', closeAllLoaders);
    })();
    function updateEmployeesTaskBadge() {
    const employeesMenu = document.getElementById('employeesMenu');
    const employeesDropdown = employeesMenu?.closest('.employees-dropdown');

    if (!employeesMenu || !employeesDropdown) return;

    employeesDropdown.classList.toggle(
        'employees-menu-open',
        employeesMenu.classList.contains('show')
    );
}
</script>
</body>
</html>