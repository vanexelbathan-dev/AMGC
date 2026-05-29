<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 0);  // prevent HTML errors from breaking AJAX JSON

// Log errors to file
ini_set('log_errors', 1);
ini_set('error_log', '../logs/php_errors.log');

require_once '../config/database.php';
require_once '../config/session_handler.php';

// Protect page - only Branch Admin role can access
requireLogin();
requireRole(['branch_admin']);

// Get current user info and branch context
$user_id = $_SESSION['user_id'] ?? 0;
$user_name = isset($_SESSION['first_name']) ? $_SESSION['first_name'] . ' ' . $_SESSION['last_name'] : 'Branch Admin';
$user_role = isset($_SESSION['role']) ? $_SESSION['role'] : 'branch_admin';
$branch_id = $_SESSION['branch_id'] ?? 0;
$view_all_branches = $_SESSION['view_all_branches'] ?? false;

// Get customer ID and name from URL parameters
$pre_selected_customer_id = isset($_GET['customer_id']) ? (int)$_GET['customer_id'] : 0;
$pre_selected_customer_name = isset($_GET['customer_name']) ? htmlspecialchars($_GET['customer_name']) : '';
$is_customer_locked = ($pre_selected_customer_id > 0); 

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
    $user_initials = 'BA';
}

// Check database connection
if (!$conn) {
    die("Database connection failed: " . mysqli_connect_error());
}


// ===== DISCOUNT/TOTAL COLUMN SAFETY =====
// Keeps this file working even if the newer discount columns are missing.
if (!function_exists('amgcColumnExists')) {
    function amgcColumnExists($conn, $table, $column) {
        $table = $conn->real_escape_string($table);
        $column = $conn->real_escape_string($column);
        $res = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
        return ($res && $res->num_rows > 0);
    }
}

if (!function_exists('amgcAddColumnIfMissing')) {
    function amgcAddColumnIfMissing($conn, $table, $column, $definition) {
        if (!amgcColumnExists($conn, $table, $column)) {
            @$conn->query("ALTER TABLE `$table` ADD COLUMN $definition");
        }
    }
}

amgcAddColumnIfMissing($conn, 'sales_orders', 'discount_percent', 'discount_percent DECIMAL(8,4) NOT NULL DEFAULT 0.0000 AFTER total_amount');
amgcAddColumnIfMissing($conn, 'sales_orders', 'discount_amount', 'discount_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER discount_percent');
amgcAddColumnIfMissing($conn, 'sales_orders', 'discount_calculation_type', "discount_calculation_type ENUM('percentage','amount_based') NOT NULL DEFAULT 'percentage' AFTER discount_amount");
amgcAddColumnIfMissing($conn, 'sales_orders', 'discount_based_amount', 'discount_based_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER discount_calculation_type');
amgcAddColumnIfMissing($conn, 'sales_orders', 'order_amount', 'order_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER discount_based_amount');
amgcAddColumnIfMissing($conn, 'sales_orders', 'total_discount_amount', 'total_discount_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER order_amount');

amgcAddColumnIfMissing($conn, 'sales_order_items', 'gross_price', 'gross_price DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER quantity_delivered');
amgcAddColumnIfMissing($conn, 'sales_order_items', 'discount_type', "discount_type ENUM('computed','percentage','peso') NOT NULL DEFAULT 'computed' AFTER gross_price");
amgcAddColumnIfMissing($conn, 'sales_order_items', 'discount_value', 'discount_value DECIMAL(12,4) NOT NULL DEFAULT 0.0000 AFTER discount_type');
amgcAddColumnIfMissing($conn, 'sales_order_items', 'discount_amount', 'discount_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER discount_value');
amgcAddColumnIfMissing($conn, 'sales_order_items', 'net_price', 'net_price DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER discount_amount');
amgcAddColumnIfMissing($conn, 'sales_order_items', 'order_amount', 'order_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER net_price');
amgcAddColumnIfMissing($conn, 'sales_order_items', 'total_discount', 'total_discount DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER order_amount');
amgcAddColumnIfMissing($conn, 'unit_types', 'uom_initial', 'uom_initial VARCHAR(20) DEFAULT NULL AFTER unit_type_name');

// ===== SI / TAX DETAILS SAFETY =====
amgcAddColumnIfMissing($conn, 'sales_orders', 'si_number', 'si_number VARCHAR(50) DEFAULT NULL AFTER so_number');
amgcAddColumnIfMissing($conn, 'sales_orders', 'document_type', "document_type ENUM('SO','SI') NOT NULL DEFAULT 'SO' AFTER si_number");
amgcAddColumnIfMissing($conn, 'sales_orders', 'atw_no', 'atw_no VARCHAR(50) DEFAULT NULL AFTER document_type');
amgcAddColumnIfMissing($conn, 'sales_orders', 'gatepass_no', 'gatepass_no VARCHAR(50) DEFAULT NULL AFTER atw_no');
amgcAddColumnIfMissing($conn, 'sales_orders', 'registered_business_name', 'registered_business_name VARCHAR(255) DEFAULT NULL AFTER gatepass_no');
amgcAddColumnIfMissing($conn, 'sales_orders', 'tin', 'tin VARCHAR(50) DEFAULT NULL AFTER registered_business_name');
amgcAddColumnIfMissing($conn, 'sales_orders', 'business_address', 'business_address TEXT DEFAULT NULL AFTER tin');
amgcAddColumnIfMissing($conn, 'invoices', 'si_number', 'si_number VARCHAR(50) DEFAULT NULL AFTER invoice_number');
amgcAddColumnIfMissing($conn, 'invoices', 'registered_business_name', 'registered_business_name VARCHAR(255) DEFAULT NULL AFTER si_number');
amgcAddColumnIfMissing($conn, 'invoices', 'tin', 'tin VARCHAR(50) DEFAULT NULL AFTER registered_business_name');
amgcAddColumnIfMissing($conn, 'invoices', 'business_address', 'business_address TEXT DEFAULT NULL AFTER tin');
amgcAddColumnIfMissing($conn, 'payments', 'si_number', 'si_number VARCHAR(50) DEFAULT NULL AFTER reference_number');
amgcAddColumnIfMissing($conn, 'payments', 'registered_business_name', 'registered_business_name VARCHAR(255) DEFAULT NULL AFTER si_number');
amgcAddColumnIfMissing($conn, 'payments', 'tin', 'tin VARCHAR(50) DEFAULT NULL AFTER registered_business_name');
amgcAddColumnIfMissing($conn, 'payments', 'business_address', 'business_address TEXT DEFAULT NULL AFTER tin');

// ===== BEYOND CREDIT LIMIT APPROVAL SAFETY =====
amgcAddColumnIfMissing($conn, 'sales_orders', 'beyond_credit_limit_allowed', 'beyond_credit_limit_allowed TINYINT(1) NOT NULL DEFAULT 0 AFTER business_address');
amgcAddColumnIfMissing($conn, 'sales_orders', 'beyond_credit_limit_explanation', 'beyond_credit_limit_explanation TEXT DEFAULT NULL AFTER beyond_credit_limit_allowed');
amgcAddColumnIfMissing($conn, 'sales_orders', 'beyond_credit_limit_acknowledged', 'beyond_credit_limit_acknowledged TINYINT(1) NOT NULL DEFAULT 0 AFTER beyond_credit_limit_explanation');
amgcAddColumnIfMissing($conn, 'sales_orders', 'beyond_credit_limit_allowed_by', 'beyond_credit_limit_allowed_by INT(11) DEFAULT NULL AFTER beyond_credit_limit_acknowledged');
amgcAddColumnIfMissing($conn, 'sales_orders', 'beyond_credit_limit_allowed_at', 'beyond_credit_limit_allowed_at DATETIME DEFAULT NULL AFTER beyond_credit_limit_allowed_by');
amgcAddColumnIfMissing($conn, 'sales_orders', 'beyond_credit_limit_snapshot', 'beyond_credit_limit_snapshot LONGTEXT DEFAULT NULL AFTER beyond_credit_limit_allowed_at');

// ===== OUTSTANDING BALANCE APPROVAL SAFETY =====
// For customers without credit limit but with existing outstanding balance.
amgcAddColumnIfMissing($conn, 'sales_orders', 'outstanding_balance_amount', 'outstanding_balance_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER beyond_credit_limit_snapshot');
amgcAddColumnIfMissing($conn, 'sales_orders', 'outstanding_balance_approval_required', 'outstanding_balance_approval_required TINYINT(1) NOT NULL DEFAULT 0 AFTER outstanding_balance_amount');
amgcAddColumnIfMissing($conn, 'sales_orders', 'outstanding_balance_approved', 'outstanding_balance_approved TINYINT(1) NOT NULL DEFAULT 0 AFTER outstanding_balance_approval_required');
amgcAddColumnIfMissing($conn, 'sales_orders', 'outstanding_balance_approved_by', 'outstanding_balance_approved_by INT(11) DEFAULT NULL AFTER outstanding_balance_approved');
amgcAddColumnIfMissing($conn, 'sales_orders', 'outstanding_balance_approved_at', 'outstanding_balance_approved_at DATETIME DEFAULT NULL AFTER outstanding_balance_approved_by');
amgcAddColumnIfMissing($conn, 'sales_orders', 'outstanding_balance_reason', 'outstanding_balance_reason TEXT DEFAULT NULL AFTER outstanding_balance_approved_at');
amgcAddColumnIfMissing($conn, 'sales_orders', 'outstanding_balance_snapshot', 'outstanding_balance_snapshot LONGTEXT DEFAULT NULL AFTER outstanding_balance_reason');



// ===== PICKUP PAYMENT / INVOICE HELPERS =====
function amgcOrderProductTableExists($conn, $table) {
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $res = $conn->query("SHOW TABLES LIKE '$table'");
    return ($res && $res->num_rows > 0);
}

function amgcOrderProductGetCreditTermsDays($conn, $customer_id) {
    $terms_days = 30;
    if (amgcOrderProductTableExists($conn, 'credit_discount_requests')) {
        $stmt = $conn->prepare("SELECT credit_terms_days FROM credit_discount_requests WHERE customer_id = ? AND status = 'approved' AND request_type IN ('credit_terms','both','credit') AND (effective_until IS NULL OR effective_until >= CURDATE()) ORDER BY approved_at DESC, request_id DESC LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $customer_id);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row && (int)($row['credit_terms_days'] ?? 0) > 0) {
                $terms_days = (int)$row['credit_terms_days'];
            }
        }
    }
    return $terms_days;
}

function amgcOrderProductFindOrCreateInvoice($conn, $so_id, $customer_id, $branch_id, $total_amount, $user_id, $mark_paid = false) {
    if (!amgcOrderProductTableExists($conn, 'invoices')) {
        throw new Exception('Invoices table not found. Please restore invoices table first.');
    }

    $existing_id = 0;
    if (amgcColumnExists($conn, 'invoices', 'so_id')) {
        $existing = $conn->prepare("SELECT invoice_id FROM invoices WHERE so_id = ? LIMIT 1");
        if ($existing) {
            $existing->bind_param('i', $so_id);
            $existing->execute();
            $existing_row = $existing->get_result()->fetch_assoc();
            $existing->close();
            if ($existing_row) $existing_id = (int)$existing_row['invoice_id'];
        }
    }

    if ($existing_id > 0) {
        if ($mark_paid) {
            $paid_at = date('Y-m-d H:i:s');
            $update = $conn->prepare("UPDATE invoices SET amount_paid = ?, balance = 0, status = 'paid', paid_at = ?, paid_by = ?, updated_at = NOW() WHERE invoice_id = ?");
            if ($update) {
                $update->bind_param('dsii', $total_amount, $paid_at, $user_id, $existing_id);
                $update->execute();
                $update->close();
            }
        }
        return $existing_id;
    }

    $invoice_number = 'INV-' . date('Ymd') . '-' . str_pad($so_id, 5, '0', STR_PAD_LEFT);
    $invoice_date = date('Y-m-d');
    $due_date = date('Y-m-d', strtotime('+' . amgcOrderProductGetCreditTermsDays($conn, $customer_id) . ' days'));
    $amount_paid = $mark_paid ? $total_amount : 0.00;
    $balance = $mark_paid ? 0.00 : $total_amount;
    $status = $mark_paid ? 'paid' : 'pending';
    $paid_at = $mark_paid ? date('Y-m-d H:i:s') : null;
    $paid_by = $mark_paid ? $user_id : null;

    $stmt = $conn->prepare("INSERT INTO invoices (invoice_number, so_id, customer_id, branch_id, invoice_date, due_date, total_amount, amount_paid, balance, status, paid_at, paid_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    if (!$stmt) throw new Exception('Failed to prepare invoice insert: ' . $conn->error);
    $stmt->bind_param('siiissdddssi', $invoice_number, $so_id, $customer_id, $branch_id, $invoice_date, $due_date, $total_amount, $amount_paid, $balance, $status, $paid_at, $paid_by);
    if (!$stmt->execute()) throw new Exception('Failed to create invoice: ' . $stmt->error);
    $invoice_id = (int)$conn->insert_id;
    $stmt->close();
    return $invoice_id;
}

function amgcOrderProductInsertPayment($conn, $invoice_id, $so_id, $customer_id, $branch_id, $user_id, $payment_method, $amount, $reference_number = null, $check_date = null, $bank_name = null, $bank_branch = null, $check_number = null, $cash_tendered = null, $cash_change = null) {
    if (!amgcOrderProductTableExists($conn, 'payments')) {
        throw new Exception('Payments table not found. Please restore payments table first.');
    }
    $stmt = $conn->prepare("INSERT INTO payments (invoice_id, so_id, customer_id, branch_id, payment_method, amount, payment_date, reference_number, check_date, bank_name, bank_branch, check_number, cash_tendered, cash_change, status, created_by) VALUES (?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?, ?, ?, ?, ?, 'completed', ?)");
    if (!$stmt) throw new Exception('Failed to prepare payment insert: ' . $conn->error);
    $stmt->bind_param('iiiisdsssssddi', $invoice_id, $so_id, $customer_id, $branch_id, $payment_method, $amount, $reference_number, $check_date, $bank_name, $bank_branch, $check_number, $cash_tendered, $cash_change, $user_id);
    if (!$stmt->execute()) throw new Exception('Failed to save payment: ' . $stmt->error);
    $payment_id = (int)$conn->insert_id;
    $stmt->close();
    return $payment_id;
}



// ===== CREDIT LIMIT APPROVAL HELPERS =====
function amgcOrderProductGetActiveApprovedCreditRequest($conn, $customer_id) {
    if (!amgcOrderProductTableExists($conn, 'credit_discount_requests')) return null;
    $sql = "SELECT request_id, request_type, requested_credit_limit, requested_discount_percent,
                   credit_terms_days, effective_from, effective_until, created_at
            FROM credit_discount_requests
            WHERE customer_id = ?
              AND status = 'approved'
              AND (effective_from IS NULL OR effective_from <= NOW())
              AND (effective_until IS NULL OR effective_until >= NOW())
              AND request_type IN ('credit', 'credit_terms', 'both')
            ORDER BY CASE WHEN effective_from IS NULL THEN 1 ELSE 0 END ASC,
                     effective_from DESC, created_at DESC, request_id DESC
            LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return null;
    $stmt->bind_param("i", $customer_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function amgcOrderProductGetEffectiveCustomerCreditLimit($conn, $customer_id) {
    $customer_id = (int)$customer_id;
    if ($customer_id <= 0) return 0.00;

    $customer_limit = 0.00;
    if (amgcColumnExists($conn, 'customers', 'credit_limit')) {
        $stmt = $conn->prepare("SELECT credit_limit FROM customers WHERE customer_id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("i", $customer_id);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $customer_limit = (float)($row['credit_limit'] ?? 0);
        }
    }

    $active_request = amgcOrderProductGetActiveApprovedCreditRequest($conn, $customer_id);
    if ($active_request && isset($active_request['requested_credit_limit']) && (float)$active_request['requested_credit_limit'] > 0) {
        return (float)$active_request['requested_credit_limit'];
    }

    return $customer_limit;
}

function amgcOrderProductRecalcCustomerCreditUsed($conn, $customer_id) {
    $customer_id = (int)$customer_id;
    if ($customer_id <= 0 || !amgcOrderProductTableExists($conn, 'sales_orders')) return 0.00;

    if (amgcOrderProductTableExists($conn, 'invoices')) {
        $sql = "
            SELECT COALESCE(SUM(unpaid_amount), 0) AS total_unpaid
            FROM (
                SELECT GREATEST(
                    CASE
                        WHEN LOWER(TRIM(COALESCE(so.payment_status, 'unpaid'))) IN ('paid', 'completed') THEN 0
                        WHEN LOWER(TRIM(COALESCE(so.order_status, ''))) IN ('pending', 'cancelled') THEN 0
                        WHEN inv.invoice_id IS NOT NULL THEN
                            CASE
                                WHEN LOWER(TRIM(COALESCE(inv.status, 'pending'))) = 'paid' THEN 0
                                ELSE GREATEST(COALESCE(inv.balance, 0), COALESCE(inv.total_amount, so.total_amount, 0) - COALESCE(inv.amount_paid, 0), 0)
                            END
                        ELSE COALESCE(NULLIF(so.total_amount, 0), so.order_amount, 0)
                    END, 0
                ) AS unpaid_amount
                FROM sales_orders so
                LEFT JOIN (
                    SELECT so_id, MAX(invoice_id) AS invoice_id,
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

                SELECT CASE
                    WHEN LOWER(TRIM(COALESCE(status, 'pending'))) = 'paid' THEN 0
                    WHEN LOWER(TRIM(COALESCE(status, ''))) = 'cancelled' THEN 0
                    ELSE GREATEST(COALESCE(balance, 0), COALESCE(total_amount, 0) - COALESCE(amount_paid, 0), 0)
                END AS unpaid_amount
                FROM invoices
                WHERE customer_id = ?
                  AND (so_id IS NULL OR so_id = 0)
            ) unpaid_rows";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("ii", $customer_id, $customer_id);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $unpaid = max(0, (float)($row['total_unpaid'] ?? 0));
        } else {
            $unpaid = 0.00;
        }
    } else {
        $stmt = $conn->prepare("
            SELECT COALESCE(SUM(GREATEST(COALESCE(NULLIF(total_amount, 0), order_amount, 0), 0)), 0) AS total_unpaid
            FROM sales_orders
            WHERE customer_id = ?
              AND LOWER(TRIM(COALESCE(order_status, ''))) NOT IN ('pending', 'cancelled')
              AND LOWER(TRIM(COALESCE(payment_status, 'unpaid'))) NOT IN ('paid', 'completed')
        ");
        if ($stmt) {
            $stmt->bind_param("i", $customer_id);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $unpaid = max(0, (float)($row['total_unpaid'] ?? 0));
        } else {
            $unpaid = 0.00;
        }
    }

    if (amgcColumnExists($conn, 'customers', 'credit_used')) {
        $limit = amgcOrderProductGetEffectiveCustomerCreditLimit($conn, $customer_id);
        $credit_used_to_save = $limit > 0 ? $unpaid : 0.00;
        $upd = $conn->prepare("UPDATE customers SET credit_used = ? WHERE customer_id = ?");
        if ($upd) {
            $upd->bind_param("di", $credit_used_to_save, $customer_id);
            $upd->execute();
            $upd->close();
        }
    }

    return $unpaid;
}

function amgcOrderProductGetCustomerCreditSnapshot($conn, $customer_id, $additional_amount = 0.00) {
    $credit_used = amgcOrderProductRecalcCustomerCreditUsed($conn, $customer_id);
    $credit_limit = amgcOrderProductGetEffectiveCustomerCreditLimit($conn, $customer_id);
    $projected_used = $credit_used + max(0, (float)$additional_amount);
    $has_limit = $credit_limit > 0;

    return [
        'credit_limit' => $credit_limit,
        'credit_used' => $credit_used,
        'projected_credit_used' => $projected_used,
        'remaining_credit' => $credit_limit - $credit_used,
        'projected_remaining_credit' => $credit_limit - $projected_used,
        'is_over_limit_now' => $has_limit && $credit_used > $credit_limit,
        'will_exceed_on_confirm' => $has_limit && $projected_used > $credit_limit,
        'has_credit_limit' => $has_limit,
        'active_request' => amgcOrderProductGetActiveApprovedCreditRequest($conn, $customer_id)
    ];
}


// ===== DELIVERY ASSIGNMENT HELPERS (match sales_order.php process) =====
function amgcOrderProductEnsureDeliveryTables($conn) {
    if (!amgcOrderProductTableExists($conn, 'vehicles')) {
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
    }

    if (amgcOrderProductTableExists($conn, 'trip_tickets')) {
        if (!amgcColumnExists($conn, 'trip_tickets', 'vehicle_id')) {
            @$conn->query("ALTER TABLE trip_tickets ADD COLUMN vehicle_id int(11) NULL AFTER driver_id");
            @$conn->query("ALTER TABLE trip_tickets ADD KEY idx_trip_vehicle_id (vehicle_id)");
        }
        if (!amgcColumnExists($conn, 'trip_tickets', 'so_id')) {
            @$conn->query("ALTER TABLE trip_tickets ADD COLUMN so_id int(11) NULL AFTER trip_number");
            @$conn->query("ALTER TABLE trip_tickets ADD KEY idx_trip_so_id (so_id)");
        }
        if (!amgcColumnExists($conn, 'trip_tickets', 'picklist_id')) {
            @$conn->query("ALTER TABLE trip_tickets ADD COLUMN picklist_id int(11) NULL AFTER so_id");
            @$conn->query("ALTER TABLE trip_tickets ADD KEY idx_trip_picklist_id (picklist_id)");
        }
    }
}

function amgcOrderProductCreateDeliveryTripTicket($conn, $so_id, $picklist_id, $driver_id, $vehicle_id, $branch_id, $user_id) {
    if (!amgcOrderProductTableExists($conn, 'trip_tickets')) {
        throw new Exception('Trip tickets table not found. Please restore trip_tickets table first.');
    }

    $trip_ticket_number = 'TT-' . date('Ymd') . '-' . str_pad((string)$so_id, 5, '0', STR_PAD_LEFT);
    $trip_date = date('Y-m-d');

    $trip_fields = ['trip_number', 'driver_id', 'branch_id', 'trip_date', 'trip_status', 'created_by', 'created_at'];
    $trip_placeholders = ['?', '?', '?', '?', "'planned'", '?', 'NOW()'];
    $trip_types = 'siisi';
    $trip_values = [$trip_ticket_number, $driver_id, $branch_id, $trip_date, $user_id];

    if (amgcColumnExists($conn, 'trip_tickets', 'vehicle_id')) {
        $trip_fields[] = 'vehicle_id';
        $trip_placeholders[] = '?';
        $trip_types .= 'i';
        $trip_values[] = $vehicle_id;
    }
    if (amgcColumnExists($conn, 'trip_tickets', 'so_id')) {
        $trip_fields[] = 'so_id';
        $trip_placeholders[] = '?';
        $trip_types .= 'i';
        $trip_values[] = $so_id;
    }
    if (amgcColumnExists($conn, 'trip_tickets', 'picklist_id')) {
        $trip_fields[] = 'picklist_id';
        $trip_placeholders[] = '?';
        $trip_types .= 'i';
        $trip_values[] = $picklist_id;
    }

    $trip_sql = "INSERT INTO trip_tickets (" . implode(', ', $trip_fields) . ") VALUES (" . implode(', ', $trip_placeholders) . ")";
    $trip_stmt = $conn->prepare($trip_sql);
    if (!$trip_stmt) {
        throw new Exception('Failed to prepare trip ticket insert: ' . $conn->error);
    }
    $trip_stmt->bind_param($trip_types, ...$trip_values);
    if (!$trip_stmt->execute()) {
        throw new Exception('Failed to create trip ticket: ' . $trip_stmt->error);
    }
    $trip_id = (int)$conn->insert_id;
    $trip_stmt->close();
    return $trip_id;
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

/**
 * Get default UOM info for an item, same source used by Sales orderproduct.
 */
function getItemDefaultUOMInfo($conn, $item_id, $branch_id = 0, $items_branch_column_exists = false) {
    $query = "
        SELECT ut.unit_type_name, COALESCE(ut.quantity_smallest_pack, 1) as multiplier, ut.unit_type_id
        FROM items i
        JOIN unit_types ut ON i.default_unit_type_id = ut.unit_type_id
        WHERE i.item_id = ? AND ut.status = 'active'
        LIMIT 1
    ";
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        return ['unit_type_name' => 'Piece', 'multiplier' => 1, 'unit_type_id' => 0];
    }
    $stmt->bind_param('i', $item_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    if ($row) {
        return [
            'unit_type_name' => $row['unit_type_name'],
            'multiplier' => (int)($row['multiplier'] ?? 1),
            'unit_type_id' => (int)$row['unit_type_id']
        ];
    }

    $fallback_query = "
        SELECT ut.unit_type_name, COALESCE(ut.quantity_smallest_pack, 1) as multiplier, ut.unit_type_id
        FROM item_unit_pricing iup
        JOIN unit_types ut ON iup.unit_type_id = ut.unit_type_id
        WHERE iup.item_id = ? AND ut.status = 'active'
        LIMIT 1
    ";
    $stmt2 = $conn->prepare($fallback_query);
    if ($stmt2) {
        $stmt2->bind_param('i', $item_id);
        $stmt2->execute();
        $result2 = $stmt2->get_result();
        $row2 = $result2 ? $result2->fetch_assoc() : null;
        $stmt2->close();
        if ($row2) {
            return [
                'unit_type_name' => $row2['unit_type_name'],
                'multiplier' => (int)($row2['multiplier'] ?? 1),
                'unit_type_id' => (int)$row2['unit_type_id']
            ];
        }
    }

    return ['unit_type_name' => 'Piece', 'multiplier' => 1, 'unit_type_id' => 0];
}

// Get all items
$items = [];

if ($items_branch_column_exists) {
    if ($view_all_branches) {
        $items_query = "SELECT i.item_id, i.item_code, i.item_name, i.description, i.category, 
                    i.stock, i.unit_type, i.unit_price, i.price_case, i.price_inner_pack, 
                    i.price_box, i.price_carton, i.reorder_level, i.status,
                    i.product_image_url,
                    b.branch_name
                    FROM items i
                    LEFT JOIN branches b ON i.branch_id = b.branch_id
                    WHERE i.status = 'active'
                    ORDER BY i.category ASC, i.item_name ASC";
    } else {
        $items_query = "SELECT i.item_id, i.item_code, i.item_name, i.description, i.category, 
                    i.stock, i.unit_type, i.unit_price, i.price_case, i.price_inner_pack, 
                    i.price_box, i.price_carton, i.reorder_level, i.status,
                    i.product_image_url,
                    b.branch_name
                    FROM items i
                    LEFT JOIN branches b ON i.branch_id = b.branch_id
                    WHERE i.status = 'active' AND i.branch_id = $branch_id
                    ORDER BY i.category ASC, i.item_name ASC";
    }
} else {
    $items_query = "SELECT i.item_id, i.item_code, i.item_name, i.description, i.category, 
                i.stock, i.unit_type, i.unit_price, i.price_case, i.price_inner_pack, 
                i.price_box, i.price_carton, i.reorder_level, i.status,
                i.product_image_url
                FROM items i
                WHERE i.status = 'active'
                ORDER BY i.category ASC, i.item_name ASC";
}

$items_result = $conn->query($items_query);
if ($items_result) {
    $items = $items_result->fetch_all(MYSQLI_ASSOC);
} else {
    error_log("Items query error: " . $conn->error);
}

// Get all unit types and quantities for each item
$all_items_unit_types = getAllItemsUnitTypes($conn, $items, $branch_id, $items_branch_column_exists, $view_all_branches);

// Get all unique categories
$categories = array_unique(array_column($items, 'category'));
$categories = array_filter($categories);
sort($categories);

// Get all customers
$customers = [];

if ($branch_column_exists) {
    if ($view_all_branches) {
        $customers_query = "SELECT c.customer_id, c.customer_name, c.email, c.phone_number, c.address, c.city,
                        c.price_level
                        FROM customers c
                        LEFT JOIN branches b ON c.branch_id = b.branch_id
                        WHERE c.status = 'active'
                        ORDER BY c.customer_name ASC";
    } else {
        $customers_query = "SELECT c.customer_id, c.customer_name, c.email, c.phone_number, c.address, c.city,
                        c.price_level
                        FROM customers c
                        WHERE c.status = 'active' AND c.branch_id = $branch_id
                        ORDER BY c.customer_name ASC";
    }
} else {
    $customers_query = "SELECT customer_id, customer_name, email, phone_number, address, city, price_level
                    FROM customers
                    WHERE status = 'active'
                    ORDER BY customer_name ASC";
}

$customers_result = $conn->query($customers_query);
if ($customers_result) {
    $customers = $customers_result->fetch_all(MYSQLI_ASSOC);
} else {
    error_log("Customers query error: " . $conn->error);
}


// Get active branch drivers for delivery assignment
$delivery_drivers = [];
$delivery_drivers_query = "SELECT driver_id, driver_name, license_number, contact_number, vehicle_type, vehicle_plate_number FROM drivers WHERE status = 'active'";
if (!$view_all_branches) {
    $delivery_drivers_query .= " AND (branch_id = " . (int)$branch_id . " OR branch_id IS NULL)";
}
$delivery_drivers_query .= " ORDER BY driver_name ASC";
$delivery_drivers_result = $conn->query($delivery_drivers_query);
if ($delivery_drivers_result) {
    $delivery_drivers = $delivery_drivers_result->fetch_all(MYSQLI_ASSOC);
} else {
    error_log("Drivers query error: " . $conn->error);
}

// Get active branch vehicles for delivery assignment
$delivery_vehicles = [];
$delivery_vehicles_query = "SELECT vehicle_id, vehicle_type, plate_number FROM vehicles WHERE status = 'active'";
if (!$view_all_branches) {
    $delivery_vehicles_query .= " AND branch_id = " . (int)$branch_id;
}
$delivery_vehicles_query .= " ORDER BY vehicle_type ASC, plate_number ASC";
$delivery_vehicles_result = $conn->query($delivery_vehicles_query);
if ($delivery_vehicles_result) {
    $delivery_vehicles = $delivery_vehicles_result->fetch_all(MYSQLI_ASSOC);
} else {
    error_log("Vehicles query error: " . $conn->error);
}

// Build inventory array with per-item UOM stock, same source used by Sales orderproduct.
// This makes Branch orderproduct stock display accurate because it reads item_unit_inventory.
$inventory_data = [];
foreach ($items as $item) {
    $default_info = getItemDefaultUOMInfo($conn, $item['item_id'], $branch_id, $items_branch_column_exists);
    $default_multiplier = max(1, (int)($default_info['multiplier'] ?? 1));
    $default_unit_name = $default_info['unit_type_name'] ?? ($item['unit_type'] ?? 'Piece');

    $unit_stocks = [];
    $default_stock = 0.0;
    $stock_smallest = 0.0;

    $stock_stmt = $conn->prepare("
        SELECT ut.unit_type_name, ut.quantity_smallest_pack, iui.current_inventory
        FROM item_unit_inventory iui
        JOIN unit_types ut ON iui.unit_type_id = ut.unit_type_id
        WHERE iui.item_id = ?
        ORDER BY ut.is_default_uom DESC, ut.unit_type_name ASC
    ");
    if ($stock_stmt) {
        $item_id_for_stock = (int)$item['item_id'];
        $stock_stmt->bind_param('i', $item_id_for_stock);
        $stock_stmt->execute();
        $stock_res = $stock_stmt->get_result();
        while ($stock_row = $stock_res->fetch_assoc()) {
            $ut_name = trim((string)($stock_row['unit_type_name'] ?? ''));
            if ($ut_name === '') continue;
            $current_inventory = (float)($stock_row['current_inventory'] ?? 0);
            $qty_smallest_pack = max(1, (int)($stock_row['quantity_smallest_pack'] ?? 1));
            $unit_stocks[$ut_name] = $current_inventory;
            if (strcasecmp($ut_name, $default_unit_name) === 0) {
                $default_stock = $current_inventory;
                $stock_smallest = $current_inventory * $qty_smallest_pack;
            }
        }
        $stock_stmt->close();
    }

    if ($default_stock == 0.0 && !empty($unit_stocks)) {
        foreach ($unit_stocks as $ut_name => $ut_stock) {
            $default_stock = (float)$ut_stock;
            $conv = max(1, (int)($all_items_unit_types[$item['item_id']][$ut_name] ?? 1));
            $stock_smallest = $default_stock * $conv;
            break;
        }
    }

    if (empty($unit_stocks)) {
        $fallback_stock = (float)($item['stock'] ?? 0);
        $unit_stocks[$default_unit_name] = $fallback_stock;
        $default_stock = $fallback_stock;
        $stock_smallest = $fallback_stock * $default_multiplier;
    }

    $inventory_data[] = [
        'id' => (int)$item['item_id'],
        'name' => $item['item_name'],
        'sku' => $item['item_code'],
        'category' => !empty($item['category']) ? $item['category'] : 'Uncategorized',
        'unit_price' => (float)$item['unit_price'],
        'price_case' => isset($item['price_case']) ? (float)$item['price_case'] : null,
        'price_inner_pack' => isset($item['price_inner_pack']) ? (float)$item['price_inner_pack'] : null,
        'price_box' => isset($item['price_box']) ? (float)$item['price_box'] : null,
        'price_carton' => isset($item['price_carton']) ? (float)$item['price_carton'] : null,
        'stock' => (float)$stock_smallest,
        'default_stock' => (float)$default_stock,
        'stock_smallest' => (float)$stock_smallest,
        'stock_in_default_uom' => (float)$default_stock,
        'raw_stock' => (float)$default_stock,
        'unit_stocks' => $unit_stocks,
        'unit_type' => $item['unit_type'] ?? $default_unit_name,
        'default_unit_type_name' => $default_unit_name,
        'default_unit_multiplier' => $default_multiplier,
        'default_unit_type_id' => $default_info['unit_type_id'],
        'image' => $item['product_image_url'] ?? null
    ];
}
$inventory_json = json_encode($inventory_data);

function getItemUnitQuantity($conn, $item_id, $unit_type_name, $branch_id = 0, $items_branch_column_exists = false, $view_all_branches = false) {
    $unit_type_name = trim((string)$unit_type_name);
    if ($item_id <= 0 || $unit_type_name === '') {
        return 1;
    }

    $query = "
        SELECT COALESCE(ut.quantity_smallest_pack, 1) AS quantity_smallest_pack
        FROM unit_types ut
        WHERE ut.unit_type_name = ?
          AND ut.status = 'active'
    ";

    if ($items_branch_column_exists && !$view_all_branches) {
        $query .= " AND (ut.branch_id = ? OR ut.branch_id IS NULL)";
    }

    $query .= " ORDER BY ut.is_default_uom DESC LIMIT 1";

    $stmt = $conn->prepare($query);
    if (!$stmt) {
        return 1;
    }

    if ($items_branch_column_exists && !$view_all_branches) {
        $stmt->bind_param('si', $unit_type_name, $branch_id);
    } else {
        $stmt->bind_param('s', $unit_type_name);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    $qty = (int)($row['quantity_smallest_pack'] ?? 1);
    return $qty > 0 ? $qty : 1;
}

function getAllItemsUnitTypes($conn, $items_array, $branch_id = 0, $items_branch_column_exists = false, $view_all_branches = false) {
    $unit_types_by_item = [];
    
    foreach ($items_array as $item) {
        $item_id = $item['item_id'];
        
        // Simplified query - remove DISTINCT and get ALL unit types
        $query = "
            SELECT ut.unit_type_name, COALESCE(ut.quantity_smallest_pack, 1) AS quantity_smallest_pack
            FROM item_unit_pricing iup
            JOIN unit_types ut ON iup.unit_type_id = ut.unit_type_id
            WHERE iup.item_id = ? AND ut.status = 'active'
        ";
        
        if ($items_branch_column_exists && !$view_all_branches) {
            $query .= " AND (ut.branch_id = ? OR ut.branch_id IS NULL)";
        }
        
        $query .= " ORDER BY ut.is_default_uom DESC, ut.quantity_smallest_pack ASC";
        
        $stmt = $conn->prepare($query);
        if (!$stmt) {
            error_log("Prepare failed for item $item_id: " . $conn->error);
            continue;
        }
        
        if ($items_branch_column_exists && !$view_all_branches) {
            $stmt->bind_param('ii', $item_id, $branch_id);
        } else {
            $stmt->bind_param('i', $item_id);
        }
        
        if (!$stmt->execute()) {
            error_log("Execute failed for item $item_id: " . $stmt->error);
            continue;
        }
        
        $result = $stmt->get_result();
        
        $conversions = [];
        while ($row = $result->fetch_assoc()) {
            $unit_name = $row['unit_type_name'];
            $conversions[$unit_name] = (int)$row['quantity_smallest_pack'];
        }
        
        // Kung walang nahanap sa item_unit_pricing, gamitin ang default unit_type from items table
        if (empty($conversions)) {
            $default_unit = $item['unit_type'] ?? 'piece';
            $conversions[$default_unit] = 1;
            error_log("No unit types found for item {$item['item_name']} (ID: $item_id), using default: $default_unit");
        }
        
        $unit_types_by_item[$item_id] = $conversions;
        $stmt->close();
    }
    
    return $unit_types_by_item;
}

// Handle order submission via AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_order') {
    header('Content-Type: application/json');
    
    try {
        if (!$conn) {
            throw new Exception("Database connection failed");
        }
        
        $conn->begin_transaction();
        
        $customer_id = isset($_POST['customer_id']) ? (int)$_POST['customer_id'] : 0;
        $customer_name = isset($_POST['customer_name']) ? trim($_POST['customer_name']) : '';
        $email = isset($_POST['email']) ? trim($_POST['email']) : '';
        $phone = isset($_POST['phone']) ? trim($_POST['phone']) : '';
        $address = isset($_POST['address']) ? trim($_POST['address']) : '';
        $items_data = isset($_POST['items']) ? json_decode($_POST['items'], true) : [];
        $discount_percent = isset($_POST['discount_percent']) ? (float)$_POST['discount_percent'] : 0;
        $discount_calculation_type = isset($_POST['discount_calculation_type']) ? trim($_POST['discount_calculation_type']) : 'percentage';
        $discount_based_amount = isset($_POST['discount_based_amount']) ? (float)$_POST['discount_based_amount'] : 0;
        $agent_location = isset($_POST['agent_location']) ? trim($_POST['agent_location']) : '';
        $order_status = isset($_POST['order_status']) ? trim($_POST['order_status']) : 'pending'; // Get order status from POST
        $fulfillment_type = isset($_POST['fulfillment_type']) ? trim($_POST['fulfillment_type']) : 'pickup';
        $collect_payment = isset($_POST['collect_payment']) && (string)$_POST['collect_payment'] === '1';
        $delivery_driver_mode = isset($_POST['delivery_driver_mode']) ? trim($_POST['delivery_driver_mode']) : 'select';
        $delivery_driver_id = isset($_POST['delivery_driver_id']) ? (int)$_POST['delivery_driver_id'] : 0;
        $new_driver_first_name = trim($_POST['new_driver_first_name'] ?? '');
        $new_driver_last_name = trim($_POST['new_driver_last_name'] ?? '');
        $new_driver_name = trim($_POST['new_driver_name'] ?? '');
        if ($new_driver_name === '' && ($new_driver_first_name !== '' || $new_driver_last_name !== '')) {
            $new_driver_name = trim($new_driver_first_name . ' ' . $new_driver_last_name);
        }
        $new_driver_license = trim($_POST['new_driver_license'] ?? '');
        $new_driver_license_expiry = !empty($_POST['new_driver_license_expiry']) ? trim($_POST['new_driver_license_expiry']) : null;
        $new_driver_contact = trim($_POST['new_driver_contact'] ?? '');
        $new_driver_email = trim($_POST['new_driver_email'] ?? '');
        $new_driver_password = trim($_POST['new_driver_password'] ?? '');
        $delivery_vehicle_mode = isset($_POST['delivery_vehicle_mode']) ? trim($_POST['delivery_vehicle_mode']) : 'select';
        $delivery_vehicle_id = isset($_POST['delivery_vehicle_id']) ? (int)$_POST['delivery_vehicle_id'] : 0;
        $new_vehicle_type = trim($_POST['new_vehicle_type'] ?? '');
        $new_vehicle_plate = trim($_POST['new_vehicle_plate'] ?? '');
        $assigned_vehicle_type = '';
        $assigned_vehicle_plate = '';
        $payment_method = isset($_POST['payment_method']) ? trim($_POST['payment_method']) : 'cash';
        $cash_tendered = isset($_POST['cash_tendered']) && $_POST['cash_tendered'] !== '' ? (float)$_POST['cash_tendered'] : null;
        $cash_change = null;
        $reference_number = null;
        $check_date = null;
        $bank_name = null;
        $bank_branch = null;
        $check_number = null;

        if (!in_array($fulfillment_type, ['pickup', 'delivery'], true)) {
            $fulfillment_type = 'pickup';
        }
        // All placed orders should be confirmed immediately, even when stock is low.
        // For pickup orders with collected payment, mark it delivered right away.
        $order_status = ($fulfillment_type === 'pickup' && $collect_payment) ? 'delivered' : 'confirmed';
        if (!in_array($payment_method, ['cash', 'check', 'online_transfer'], true)) {
            $payment_method = 'cash';
        }

        if (!in_array($discount_calculation_type, ['percentage', 'amount_based'], true)) {
            $discount_calculation_type = 'percentage';
        }
        
        if (empty($items_data)) {
            throw new Exception("No items in cart");
        }
        
        $user_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
        $branch_id = isset($_SESSION['branch_id']) ? (int)$_SESSION['branch_id'] : 0;
        $view_all_branches = isset($_SESSION['view_all_branches']) ? $_SESSION['view_all_branches'] : false;
        
        if ($user_id === 0) {
            throw new Exception("User session invalid. Please log in again.");
        }
        
        // Create/update customer
        if ($customer_id === 0 && !empty($customer_name)) {
            if ($branch_column_exists && !$view_all_branches) {
                $check_sql = "SELECT customer_id FROM customers WHERE customer_name = ? AND branch_id = ? AND status = 'active'";
                $check_stmt = $conn->prepare($check_sql);
                $check_stmt->bind_param('si', $customer_name, $branch_id);
            } else {
                $check_sql = "SELECT customer_id FROM customers WHERE customer_name = ? AND status = 'active'";
                $check_stmt = $conn->prepare($check_sql);
                $check_stmt->bind_param('s', $customer_name);
            }
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            
            if ($check_result->num_rows > 0) {
                $existing_customer = $check_result->fetch_assoc();
                $customer_id = $existing_customer['customer_id'];
                
                $update_sql = "UPDATE customers SET email = ?, phone_number = ?, address = ? WHERE customer_id = ?";
                $update_stmt = $conn->prepare($update_sql);
                $update_stmt->bind_param('sssi', $email, $phone, $address, $customer_id);
                $update_stmt->execute();
            } else {
                $customer_code = 'CUST-' . date('Ymd') . '-' . str_pad(rand(1000, 9999), 4, '0', STR_PAD_LEFT);
                
                if ($branch_column_exists && !$view_all_branches) {
                    $sql_new_cust = "INSERT INTO customers (customer_name, customer_code, email, phone_number, address, status, branch_id) 
                                VALUES (?, ?, ?, ?, ?, 'active', ?)";
                    $stmt_new_cust = $conn->prepare($sql_new_cust);
                    $stmt_new_cust->bind_param('sssssi', $customer_name, $customer_code, $email, $phone, $address, $branch_id);
                } else {
                    $sql_new_cust = "INSERT INTO customers (customer_name, customer_code, email, phone_number, address, status) 
                                VALUES (?, ?, ?, ?, ?, 'active')";
                    $stmt_new_cust = $conn->prepare($sql_new_cust);
                    $stmt_new_cust->bind_param('sssss', $customer_name, $customer_code, $email, $phone, $address);
                }
                $stmt_new_cust->execute();
                $customer_id = $stmt_new_cust->insert_id;
            }
        }
        
        if ($customer_id === 0) {
            throw new Exception("Customer is required");
        }

        // If an existing customer has no saved address, allow the address typed in this order form
        // to be saved directly to that customer's record. This prevents blank-address customers
        // from being blocked during delivery scheduling.
        if ($customer_id > 0 && $address !== '') {
            $customer_address_sql = "SELECT address FROM customers WHERE customer_id = ?";
            $customer_address_params = [$customer_id];
            $customer_address_types = 'i';

            if ($branch_column_exists && !$view_all_branches) {
                $customer_address_sql .= " AND branch_id = ?";
                $customer_address_params[] = $branch_id;
                $customer_address_types .= 'i';
            }

            $customer_address_sql .= " LIMIT 1";
            $customer_address_stmt = $conn->prepare($customer_address_sql);
            if ($customer_address_stmt) {
                $customer_address_stmt->bind_param($customer_address_types, ...$customer_address_params);
                $customer_address_stmt->execute();
                $customer_address_row = $customer_address_stmt->get_result()->fetch_assoc();
                $customer_address_stmt->close();

                $current_saved_address = trim((string)($customer_address_row['address'] ?? ''));
                if ($current_saved_address === '' || $current_saved_address === '-') {
                    $update_customer_address_sql = "UPDATE customers SET address = ? WHERE customer_id = ?";
                    $update_customer_address_params = [$address, $customer_id];
                    $update_customer_address_types = 'si';

                    if ($branch_column_exists && !$view_all_branches) {
                        $update_customer_address_sql .= " AND branch_id = ?";
                        $update_customer_address_params[] = $branch_id;
                        $update_customer_address_types .= 'i';
                    }

                    $update_customer_address_stmt = $conn->prepare($update_customer_address_sql);
                    if ($update_customer_address_stmt) {
                        $update_customer_address_stmt->bind_param($update_customer_address_types, ...$update_customer_address_params);
                        $update_customer_address_stmt->execute();
                        $update_customer_address_stmt->close();
                    }
                }
            }
        }
        
        if ($fulfillment_type === 'delivery') {
            amgcOrderProductEnsureDeliveryTables($conn);

            if ($delivery_vehicle_mode === 'new') {
                if ($new_vehicle_type === '' || $new_vehicle_plate === '') {
                    throw new Exception('Vehicle type and plate number are required.');
                }
                $check_vehicle = $conn->prepare("SELECT vehicle_id FROM vehicles WHERE plate_number = ? AND branch_id = ? LIMIT 1");
                if (!$check_vehicle) throw new Exception('Database prepare error while checking vehicle.');
                $check_vehicle->bind_param('si', $new_vehicle_plate, $branch_id);
                $check_vehicle->execute();
                $existing_vehicle = $check_vehicle->get_result()->fetch_assoc();
                $check_vehicle->close();
                if ($existing_vehicle) {
                    $delivery_vehicle_id = (int)$existing_vehicle['vehicle_id'];
                } else {
                    $vehicle_status = 'active';
                    $insert_vehicle = $conn->prepare("INSERT INTO vehicles (branch_id, vehicle_type, plate_number, status, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())");
                    if (!$insert_vehicle) throw new Exception('Database prepare error while adding vehicle.');
                    $insert_vehicle->bind_param('isss', $branch_id, $new_vehicle_type, $new_vehicle_plate, $vehicle_status);
                    if (!$insert_vehicle->execute()) throw new Exception('Failed to add vehicle: ' . $insert_vehicle->error);
                    $delivery_vehicle_id = (int)$conn->insert_id;
                    $insert_vehicle->close();
                }
            }

            if ($delivery_vehicle_id > 0) {
                $vehicle_stmt = $conn->prepare("SELECT vehicle_type, plate_number FROM vehicles WHERE vehicle_id = ? AND status = 'active' LIMIT 1");
                if (!$vehicle_stmt) throw new Exception('Database prepare error while loading vehicle.');
                $vehicle_stmt->bind_param('i', $delivery_vehicle_id);
                $vehicle_stmt->execute();
                $vehicle_row = $vehicle_stmt->get_result()->fetch_assoc();
                $vehicle_stmt->close();
                if (!$vehicle_row) throw new Exception('Selected vehicle was not found.');
                $assigned_vehicle_type = (string)$vehicle_row['vehicle_type'];
                $assigned_vehicle_plate = (string)$vehicle_row['plate_number'];
            } else {
                throw new Exception('Please select or add a vehicle.');
            }

            if ($delivery_driver_mode === 'new') {
                if ($new_driver_first_name === '' || $new_driver_last_name === '' || $new_driver_email === '' || $new_driver_password === '' || $new_driver_license === '') {
                    throw new Exception('First name, last name, email, password, and license number are required.');
                }
                $new_driver_name = trim($new_driver_first_name . ' ' . $new_driver_last_name);
                if (!filter_var($new_driver_email, FILTER_VALIDATE_EMAIL)) {
                    throw new Exception('Invalid driver email address.');
                }
                $check_license = $conn->prepare("SELECT driver_id FROM drivers WHERE license_number = ? LIMIT 1");
                if (!$check_license) throw new Exception('Database prepare error while checking license.');
                $check_license->bind_param('s', $new_driver_license);
                $check_license->execute();
                if ($check_license->get_result()->num_rows > 0) {
                    $check_license->close();
                    throw new Exception('License number already exists.');
                }
                $check_license->close();

                $check_email = $conn->prepare("SELECT user_id FROM users WHERE email = ? LIMIT 1");
                if (!$check_email) throw new Exception('Database prepare error while checking email.');
                $check_email->bind_param('s', $new_driver_email);
                $check_email->execute();
                if ($check_email->get_result()->num_rows > 0) {
                    $check_email->close();
                    throw new Exception('Driver email already exists.');
                }
                $check_email->close();

                $driver_status = 'active';
                $insert_driver = $conn->prepare("INSERT INTO drivers (driver_name, license_number, license_expiry, contact_number, status, branch_id, vehicle_type, vehicle_plate_number, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
                if (!$insert_driver) throw new Exception('Database prepare error while adding driver.');
                $insert_driver->bind_param('sssssiss', $new_driver_name, $new_driver_license, $new_driver_license_expiry, $new_driver_contact, $driver_status, $branch_id, $assigned_vehicle_type, $assigned_vehicle_plate);
                if (!$insert_driver->execute()) throw new Exception('Failed to add driver: ' . $insert_driver->error);
                $delivery_driver_id = (int)$conn->insert_id;
                $insert_driver->close();

                $first_name = $new_driver_first_name;
                $last_name = $new_driver_last_name;
                $password_hash = password_hash($new_driver_password, PASSWORD_DEFAULT);
                $profile_picture = null;
                $insert_user = $conn->prepare("INSERT INTO users (email, password_hash, first_name, last_name, role, branch_id, driver_id, contact_number, profile_picture, status, created_at, updated_at) VALUES (?, ?, ?, ?, 'delivery', ?, ?, ?, ?, 'active', NOW(), NOW())");
                if (!$insert_user) throw new Exception('Database prepare error while creating driver account.');
                $insert_user->bind_param('ssssiiss', $new_driver_email, $password_hash, $first_name, $last_name, $branch_id, $delivery_driver_id, $new_driver_contact, $profile_picture);
                if (!$insert_user->execute()) throw new Exception('Failed to create driver user account: ' . $insert_user->error);
                $new_user_id = (int)$conn->insert_id;
                $insert_user->close();

                $link_driver_user = $conn->prepare("UPDATE drivers SET user_id = ? WHERE driver_id = ?");
                if ($link_driver_user) {
                    $link_driver_user->bind_param('ii', $new_user_id, $delivery_driver_id);
                    $link_driver_user->execute();
                    $link_driver_user->close();
                }
            }

            if ($delivery_driver_id <= 0) {
                throw new Exception('Please select or add a driver.');
            }
            $driver_stmt = $conn->prepare("SELECT driver_id FROM drivers WHERE driver_id = ? AND status = 'active' LIMIT 1");
            if (!$driver_stmt) throw new Exception('Database prepare error while loading driver.');
            $driver_stmt->bind_param('i', $delivery_driver_id);
            $driver_stmt->execute();
            if ($driver_stmt->get_result()->num_rows === 0) {
                $driver_stmt->close();
                throw new Exception('Selected driver was not found.');
            }
            $driver_stmt->close();

            $update_driver_vehicle = $conn->prepare("UPDATE drivers SET vehicle_type = ?, vehicle_plate_number = ?, updated_at = NOW() WHERE driver_id = ?");
            if ($update_driver_vehicle) {
                $update_driver_vehicle->bind_param('ssi', $assigned_vehicle_type, $assigned_vehicle_plate, $delivery_driver_id);
                $update_driver_vehicle->execute();
                $update_driver_vehicle->close();
            }
        }

        // Calculate subtotal
        $subtotal = 0;
        foreach ($items_data as $item) {
            $subtotal += $item['price'] * $item['quantity'];
        }
        
        if ($discount_calculation_type === 'amount_based') {
            $discount_amount = max(0, min($subtotal, $discount_based_amount));
            $discount_percent = $subtotal > 0 ? (($discount_amount / $subtotal) * 100) : 0;
        } else {
            $discount_percent = max(0, min(100, $discount_percent));
            $discount_amount = $subtotal * ($discount_percent / 100);
            $discount_based_amount = 0;
        }
        $total_amount = max(0, $subtotal - $discount_amount);


        $document_type = (isset($_POST['document_type']) && strtoupper(trim($_POST['document_type'])) === 'SI') ? 'SI' : 'SO';
        $si_number = trim($_POST['si_number'] ?? '');
        $atw_no = trim($_POST['atw_no'] ?? '');
        $gatepass_no = trim($_POST['gatepass_no'] ?? '');
        $registered_business_name = trim($_POST['registered_business_name'] ?? '');
        $tin = trim($_POST['tin'] ?? '');
        $business_address = trim($_POST['business_address'] ?? '');
        $beyond_credit_explanation = trim($_POST['beyond_credit_explanation'] ?? '');
        $beyond_credit_acknowledged = isset($_POST['beyond_credit_acknowledged']) && (string)$_POST['beyond_credit_acknowledged'] === '1';
        $beyond_credit_required = false;
        $beyond_credit_snapshot_json = null;
        $outstanding_balance_explanation = trim($_POST['outstanding_balance_explanation'] ?? '');
        $outstanding_balance_acknowledged = isset($_POST['outstanding_balance_acknowledged']) && (string)$_POST['outstanding_balance_acknowledged'] === '1';
        $outstanding_balance_required = false;
        $outstanding_balance_snapshot_json = null;
        $outstanding_balance_amount_to_save = 0.00;

        // Same approval flow as Sales Order, but ONLY for delivery orders.
        // Pickup / walk-in orders should not show the credit-limit approval form.
        if ($fulfillment_type === 'delivery') {
            $credit_snapshot = amgcOrderProductGetCustomerCreditSnapshot($conn, $customer_id, $total_amount);

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

                $beyond_credit_snapshot_json = json_encode([
                    'credit_limit' => $credit_snapshot['credit_limit'],
                    'credit_used_before_confirmation' => $credit_snapshot['credit_used'],
                    'order_amount' => $total_amount,
                    'projected_credit_used' => $credit_snapshot['projected_credit_used'],
                    'projected_remaining_credit' => $credit_snapshot['projected_remaining_credit'],
                    'allowed_by' => $user_id,
                    'allowed_at' => date('Y-m-d H:i:s')
                ], JSON_UNESCAPED_UNICODE);
            }
        }

        // Outstanding Balance approval flow:
        // If the customer has NO credit limit but has an existing outstanding balance,
        // require an approval form before confirming this new order.
        $outstanding_snapshot = amgcOrderProductGetCustomerCreditSnapshot($conn, $customer_id, 0);
        if (!$outstanding_snapshot['has_credit_limit'] && (float)$outstanding_snapshot['credit_used'] > 0) {
            $outstanding_balance_required = true;
            $outstanding_balance_amount_to_save = (float)$outstanding_snapshot['credit_used'];
            $outstanding_html = '<div class="text-start">' .
                          '<p class="mb-2"><strong>This customer has an existing outstanding balance and no credit limit.</strong></p>' .
                          '<p class="mb-2 text-muted">Please provide an explanation and tick the acknowledgement box to continue confirmation.</p>' .
                          '<hr class="my-2">' .
                          '<div class="d-flex justify-content-between mb-1"><span>Credit Limit:</span><span class="fw-bold text-muted">No Credit Limit</span></div>' .
                          '<div class="d-flex justify-content-between mb-1"><span>Current Outstanding Balance:</span><span class="fw-bold text-danger">₱' . number_format($outstanding_balance_amount_to_save, 2) . '</span></div>' .
                          '<div class="d-flex justify-content-between mb-1"><span>This Order Amount:</span><span class="fw-bold">₱' . number_format($total_amount, 2) . '</span></div>' .
                          '</div>';

            if ($outstanding_balance_explanation === '' || !$outstanding_balance_acknowledged) {
                throw new Exception(json_encode([
                    'type' => 'outstanding_balance_required',
                    'title' => 'Outstanding Balance Approval Required',
                    'html' => $outstanding_html,
                    'outstanding_balance' => $outstanding_balance_amount_to_save,
                    'order_amount' => $total_amount
                ]));
            }

            $outstanding_balance_snapshot_json = json_encode([
                'credit_limit' => 0,
                'outstanding_balance_before_confirmation' => $outstanding_balance_amount_to_save,
                'order_amount' => $total_amount,
                'approved_by' => $user_id,
                'approved_at' => date('Y-m-d H:i:s')
            ], JSON_UNESCAPED_UNICODE);
        }
        
        $manual_so_suffix = isset($_POST['so_suffix']) ? trim($_POST['so_suffix']) : '';
        if ($atw_no === '' || $gatepass_no === '') {
            throw new Exception('ATW No. and Gatepass No. are required.');
        }
        if (!preg_match('/^\d{1,6}$/', $atw_no) || !preg_match('/^\d{1,6}$/', $gatepass_no)) {
            throw new Exception('ATW No. and Gatepass No. must be numbers only with a maximum of 6 digits.');
        }

        if ($document_type === 'SI') {
            if ($manual_so_suffix === '') {
                $manual_so_suffix = substr((string)time(), -6);
            }
            if ($si_number === '') {
                throw new Exception('Please enter SI number.');
            }
            if ($registered_business_name === '' || $tin === '' || $business_address === '') {
                throw new Exception('Registered Business Name, TIN, and Address are required when SI is selected.');
            }
        }
        
        
        if (!preg_match('/^\d{5,6}$/', $manual_so_suffix)) {
            throw new Exception("Invalid SO number. Please enter the last 5 to 6 digits only.");
        }
        
        $so_number = 'SO-' . date('Ymd') . '-' . $manual_so_suffix;
        
        // Prevent duplicate SO number
        $check_so_stmt = $conn->prepare("SELECT so_id FROM sales_orders WHERE so_number = ? LIMIT 1");
        if (!$check_so_stmt) {
            throw new Exception('Database prepare error while checking SO number');
        }
        $check_so_stmt->bind_param('s', $so_number);
        $check_so_stmt->execute();
        $check_so_result = $check_so_stmt->get_result();
        if ($check_so_result && $check_so_result->num_rows > 0) {
            $check_so_stmt->close();
            throw new Exception("SO number already exists. Please enter another SO number.");
        }
        $check_so_stmt->close();
        if ($document_type === 'SI' && $si_number !== '') {
            $check_si_stmt = $conn->prepare("SELECT so_id FROM sales_orders WHERE si_number = ? LIMIT 1");
            if ($check_si_stmt) {
                $check_si_stmt->bind_param('s', $si_number);
                $check_si_stmt->execute();
                $check_si_result = $check_si_stmt->get_result();
                if ($check_si_result && $check_si_result->num_rows > 0) {
                    $check_si_stmt->close();
                    throw new Exception('SI number already exists. Please enter another SI number.');
                }
                $check_si_stmt->close();
            }
        }
        
        $order_date = date('Y-m-d H:i:s');
        
        // Check sales_orders table columns
        $columns_check = $conn->query("SHOW COLUMNS FROM sales_orders");
        $columns = [];
        while ($col = $columns_check->fetch_assoc()) {
            $columns[] = $col['Field'];
        }
        $has_discount_column = in_array('discount_percent', $columns);
        $has_discount_amount_column = in_array('discount_amount', $columns);
        $has_discount_calculation_type_column = in_array('discount_calculation_type', $columns);
        $has_discount_based_amount_column = in_array('discount_based_amount', $columns);
        $has_order_amount_column = in_array('order_amount', $columns);
        $has_total_discount_amount_column = in_array('total_discount_amount', $columns);
        $has_agent_location_column = in_array('agent_location', $columns);
        $has_fulfillment_type_column = in_array('fulfillment_type', $columns);
        $has_payment_status_column = in_array('payment_status', $columns);
        $has_si_number_column = in_array('si_number', $columns);
        $has_document_type_column = in_array('document_type', $columns);
        $has_atw_no_column = in_array('atw_no', $columns);
        $has_gatepass_no_column = in_array('gatepass_no', $columns);
        $has_registered_business_name_column = in_array('registered_business_name', $columns);
        $has_tin_column = in_array('tin', $columns);
        $has_business_address_column = in_array('business_address', $columns);
        $has_beyond_credit_limit_allowed_column = in_array('beyond_credit_limit_allowed', $columns);
        $has_beyond_credit_limit_explanation_column = in_array('beyond_credit_limit_explanation', $columns);
        $has_beyond_credit_limit_acknowledged_column = in_array('beyond_credit_limit_acknowledged', $columns);
        $has_beyond_credit_limit_allowed_by_column = in_array('beyond_credit_limit_allowed_by', $columns);
        $has_beyond_credit_limit_allowed_at_column = in_array('beyond_credit_limit_allowed_at', $columns);
        $has_beyond_credit_limit_snapshot_column = in_array('beyond_credit_limit_snapshot', $columns);
        $has_outstanding_balance_amount_column = in_array('outstanding_balance_amount', $columns);
        $has_outstanding_balance_approval_required_column = in_array('outstanding_balance_approval_required', $columns);
        $has_outstanding_balance_approved_column = in_array('outstanding_balance_approved', $columns);
        $has_outstanding_balance_approved_by_column = in_array('outstanding_balance_approved_by', $columns);
        $has_outstanding_balance_approved_at_column = in_array('outstanding_balance_approved_at', $columns);
        $has_outstanding_balance_reason_column = in_array('outstanding_balance_reason', $columns);
        $has_outstanding_balance_snapshot_column = in_array('outstanding_balance_snapshot', $columns);
        
        $insert_fields = ['so_number', 'customer_id', 'branch_id', 'order_date', 'total_amount', 'order_status', 'created_by'];
        $insert_placeholders = ['?', '?', '?', '?', '?', '?', '?'];
        $insert_types = 'siisdss';
        $insert_values = [$so_number, $customer_id, $branch_id, $order_date, $total_amount, $order_status, $user_id];
        
        if ($has_discount_column) {
            $insert_fields[] = 'discount_percent';
            $insert_placeholders[] = '?';
            $insert_types .= 'd';
            $insert_values[] = $discount_percent;
        }
        if ($has_discount_amount_column) {
            $insert_fields[] = 'discount_amount';
            $insert_placeholders[] = '?';
            $insert_types .= 'd';
            $insert_values[] = $discount_amount;
        }
        if ($has_discount_calculation_type_column) {
            $insert_fields[] = 'discount_calculation_type';
            $insert_placeholders[] = '?';
            $insert_types .= 's';
            $insert_values[] = $discount_calculation_type;
        }
        if ($has_discount_based_amount_column) {
            $insert_fields[] = 'discount_based_amount';
            $insert_placeholders[] = '?';
            $insert_types .= 'd';
            $insert_values[] = $discount_based_amount;
        }
        if ($has_order_amount_column) {
            $insert_fields[] = 'order_amount';
            $insert_placeholders[] = '?';
            $insert_types .= 'd';
            $insert_values[] = $total_amount;
        }
        if ($has_total_discount_amount_column) {
            $insert_fields[] = 'total_discount_amount';
            $insert_placeholders[] = '?';
            $insert_types .= 'd';
            $insert_values[] = $discount_amount;
        }
        
        if ($has_agent_location_column && !empty($agent_location)) {
            $insert_fields[] = 'agent_location';
            $insert_placeholders[] = '?';
            $insert_types .= 's';
            $insert_values[] = $agent_location;
        }
        if ($has_fulfillment_type_column) {
            $insert_fields[] = 'fulfillment_type';
            $insert_placeholders[] = '?';
            $insert_types .= 's';
            $insert_values[] = $fulfillment_type;
        }
        if ($has_payment_status_column) {
            $insert_fields[] = 'payment_status';
            $insert_placeholders[] = '?';
            $insert_types .= 's';
            $insert_values[] = $collect_payment ? 'paid' : 'unpaid';
        }
        if ($has_si_number_column) { $insert_fields[] = 'si_number'; $insert_placeholders[] = '?'; $insert_types .= 's'; $insert_values[] = ($document_type === 'SI' ? $si_number : null); }
        if ($has_document_type_column) { $insert_fields[] = 'document_type'; $insert_placeholders[] = '?'; $insert_types .= 's'; $insert_values[] = $document_type; }
        if ($has_atw_no_column) { $insert_fields[] = 'atw_no'; $insert_placeholders[] = '?'; $insert_types .= 's'; $insert_values[] = $atw_no; }
        if ($has_gatepass_no_column) { $insert_fields[] = 'gatepass_no'; $insert_placeholders[] = '?'; $insert_types .= 's'; $insert_values[] = $gatepass_no; }
        if ($has_registered_business_name_column) { $insert_fields[] = 'registered_business_name'; $insert_placeholders[] = '?'; $insert_types .= 's'; $insert_values[] = ($document_type === 'SI' ? $registered_business_name : null); }
        if ($has_tin_column) { $insert_fields[] = 'tin'; $insert_placeholders[] = '?'; $insert_types .= 's'; $insert_values[] = ($document_type === 'SI' ? $tin : null); }
        if ($has_business_address_column) { $insert_fields[] = 'business_address'; $insert_placeholders[] = '?'; $insert_types .= 's'; $insert_values[] = ($document_type === 'SI' ? $business_address : null); }
        if ($beyond_credit_required) {
            if ($has_beyond_credit_limit_allowed_column) { $insert_fields[] = 'beyond_credit_limit_allowed'; $insert_placeholders[] = '?'; $insert_types .= 'i'; $insert_values[] = 1; }
            if ($has_beyond_credit_limit_explanation_column) { $insert_fields[] = 'beyond_credit_limit_explanation'; $insert_placeholders[] = '?'; $insert_types .= 's'; $insert_values[] = $beyond_credit_explanation; }
            if ($has_beyond_credit_limit_acknowledged_column) { $insert_fields[] = 'beyond_credit_limit_acknowledged'; $insert_placeholders[] = '?'; $insert_types .= 'i'; $insert_values[] = 1; }
            if ($has_beyond_credit_limit_allowed_by_column) { $insert_fields[] = 'beyond_credit_limit_allowed_by'; $insert_placeholders[] = '?'; $insert_types .= 'i'; $insert_values[] = $user_id; }
            if ($has_beyond_credit_limit_allowed_at_column) { $insert_fields[] = 'beyond_credit_limit_allowed_at'; $insert_placeholders[] = 'NOW()'; }
            if ($has_beyond_credit_limit_snapshot_column) { $insert_fields[] = 'beyond_credit_limit_snapshot'; $insert_placeholders[] = '?'; $insert_types .= 's'; $insert_values[] = $beyond_credit_snapshot_json; }
        }
                if ($outstanding_balance_required) {
            if ($has_outstanding_balance_amount_column) { $insert_fields[] = 'outstanding_balance_amount'; $insert_placeholders[] = '?'; $insert_types .= 'd'; $insert_values[] = $outstanding_balance_amount_to_save; }
            if ($has_outstanding_balance_approval_required_column) { $insert_fields[] = 'outstanding_balance_approval_required'; $insert_placeholders[] = '?'; $insert_types .= 'i'; $insert_values[] = 1; }
            if ($has_outstanding_balance_approved_column) { $insert_fields[] = 'outstanding_balance_approved'; $insert_placeholders[] = '?'; $insert_types .= 'i'; $insert_values[] = 1; }
            if ($has_outstanding_balance_approved_by_column) { $insert_fields[] = 'outstanding_balance_approved_by'; $insert_placeholders[] = '?'; $insert_types .= 'i'; $insert_values[] = $user_id; }
            if ($has_outstanding_balance_approved_at_column) { $insert_fields[] = 'outstanding_balance_approved_at'; $insert_placeholders[] = 'NOW()'; }
            if ($has_outstanding_balance_reason_column) { $insert_fields[] = 'outstanding_balance_reason'; $insert_placeholders[] = '?'; $insert_types .= 's'; $insert_values[] = $outstanding_balance_explanation; }
            if ($has_outstanding_balance_snapshot_column) { $insert_fields[] = 'outstanding_balance_snapshot'; $insert_placeholders[] = '?'; $insert_types .= 's'; $insert_values[] = $outstanding_balance_snapshot_json; }
        }
        
        $sql = "INSERT INTO sales_orders (" . implode(', ', $insert_fields) . ") VALUES (" . implode(', ', $insert_placeholders) . ")";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($insert_types, ...$insert_values);
        $stmt->execute();
        $so_id = $stmt->insert_id;
        $pick_list_id = 0;
        $trip_ticket_id = 0;
        
        if ($fulfillment_type === 'delivery') {
            $pick_list_number = 'PL-' . date('Ymd') . '-' . str_pad((string)$so_id, 5, '0', STR_PAD_LEFT);
            $pick_status = 'open';
            $pick_stmt = $conn->prepare("INSERT INTO pick_lists (pick_list_number, so_id, branch_id, driver_id, pick_date, pick_status, created_at, updated_at) VALUES (?, ?, ?, ?, CURDATE(), ?, NOW(), NOW())");
            if (!$pick_stmt) {
                throw new Exception('Failed to prepare pick list insert: ' . $conn->error);
            }
            $pick_stmt->bind_param('siiis', $pick_list_number, $so_id, $branch_id, $delivery_driver_id, $pick_status);
            if (!$pick_stmt->execute()) {
                throw new Exception('Failed to create delivery pick list: ' . $pick_stmt->error);
            }
            $pick_list_id = (int)$conn->insert_id;
            $pick_stmt->close();
        }

        // Insert order items and deduct inventory.
        // Save line-level gross/net/discount values so displays/reports stay accurate.
        $soi_columns_check = $conn->query("SHOW COLUMNS FROM sales_order_items");
        $soi_columns = [];
        if ($soi_columns_check) {
            while ($col = $soi_columns_check->fetch_assoc()) {
                $soi_columns[] = $col['Field'];
            }
        }
        $has_soi_gross_price = in_array('gross_price', $soi_columns, true);
        $has_soi_discount_type = in_array('discount_type', $soi_columns, true);
        $has_soi_discount_value = in_array('discount_value', $soi_columns, true);
        $has_soi_discount_amount = in_array('discount_amount', $soi_columns, true);
        $has_soi_net_price = in_array('net_price', $soi_columns, true);
        $has_soi_order_amount = in_array('order_amount', $soi_columns, true);
        $has_soi_total_discount = in_array('total_discount', $soi_columns, true);
        
        $updated_stock_data = [];
        
        foreach ($items_data as $item) {
            $item_id = (int)$item['id'];
            $quantity = (int)$item['quantity'];
            $unit_price = (float)$item['price'];
            $unit_type = isset($item['unit_type']) ? $item['unit_type'] : 'piece';
            
            $pieces_multiplier = getItemUnitQuantity($conn, $item_id, $unit_type, $branch_id, $items_branch_column_exists, $view_all_branches);
            $pieces_to_deduct = $quantity * $pieces_multiplier;
            
            $line_gross_price = $unit_price;
            $line_gross_total = $line_gross_price * $quantity;
            if ($discount_calculation_type === 'percentage') {
                $line_discount_type = $discount_percent > 0 ? 'percentage' : 'computed';
                $line_discount_value = $discount_percent;
                $line_discount_per_unit = $line_gross_price * ($discount_percent / 100);
            } else {
                $line_discount_type = 'computed';
                $line_discount_total = $subtotal > 0 ? ($discount_amount * ($line_gross_total / $subtotal)) : 0;
                $line_discount_total = max(0, min($line_gross_total, $line_discount_total));
                $line_discount_per_unit = $quantity > 0 ? ($line_discount_total / $quantity) : 0;
                $line_discount_value = $line_discount_per_unit;
            }
            $line_discount_per_unit = max(0, min($line_gross_price, $line_discount_per_unit));
            $line_net_price = max(0, $line_gross_price - $line_discount_per_unit);
            $line_order_amount = $line_net_price * $quantity;
            $line_total_discount = $line_discount_per_unit * $quantity;

            $item_fields = ['so_id', 'item_id', 'unit_type', 'quantity_ordered', 'unit_price'];
            $item_placeholders = ['?', '?', '?', '?', '?'];
            $item_types = 'iisid';
            $item_values = [$so_id, $item_id, $unit_type, $quantity, $line_net_price];
            if ($has_soi_gross_price) { $item_fields[] = 'gross_price'; $item_placeholders[] = '?'; $item_types .= 'd'; $item_values[] = $line_gross_price; }
            if ($has_soi_discount_type) { $item_fields[] = 'discount_type'; $item_placeholders[] = '?'; $item_types .= 's'; $item_values[] = $line_discount_type; }
            if ($has_soi_discount_value) { $item_fields[] = 'discount_value'; $item_placeholders[] = '?'; $item_types .= 'd'; $item_values[] = $line_discount_value; }
            if ($has_soi_discount_amount) { $item_fields[] = 'discount_amount'; $item_placeholders[] = '?'; $item_types .= 'd'; $item_values[] = $line_discount_per_unit; }
            if ($has_soi_net_price) { $item_fields[] = 'net_price'; $item_placeholders[] = '?'; $item_types .= 'd'; $item_values[] = $line_net_price; }
            if ($has_soi_order_amount) { $item_fields[] = 'order_amount'; $item_placeholders[] = '?'; $item_types .= 'd'; $item_values[] = $line_order_amount; }
            if ($has_soi_total_discount) { $item_fields[] = 'total_discount'; $item_placeholders[] = '?'; $item_types .= 'd'; $item_values[] = $line_total_discount; }

            $sql_items = "INSERT INTO sales_order_items (" . implode(', ', $item_fields) . ") VALUES (" . implode(', ', $item_placeholders) . ")";
            $stmt_items = $conn->prepare($sql_items);
            if (!$stmt_items) {
                throw new Exception('Failed to prepare order item insert: ' . $conn->error);
            }
            $stmt_items->bind_param($item_types, ...$item_values);
            if (!$stmt_items->execute()) {
                throw new Exception('Failed to save order item: ' . $stmt_items->error);
            }
            $stmt_items->close();

            // Match sales_order.php assignment flow: every delivery pick list must have its pick items.
            if ($fulfillment_type === 'delivery' && $pick_list_id > 0) {
                $pick_item_stmt = $conn->prepare("INSERT INTO pick_list_items (pick_list_id, item_id, quantity_to_pick) VALUES (?, ?, ?)");
                if (!$pick_item_stmt) {
                    throw new Exception('Failed to prepare pick list item insert: ' . $conn->error);
                }
                $pick_item_stmt->bind_param('iii', $pick_list_id, $item_id, $quantity);
                if (!$pick_item_stmt->execute()) {
                    throw new Exception('Failed to create pick list item: ' . $pick_item_stmt->error);
                }
                $pick_item_stmt->close();
            }
            
            // Deduct immediately for every placed order.
            // If ordered quantity is greater than available stock, inventory is allowed to go negative.
            $stock_stmt = $conn->prepare("
                SELECT iui.inventory_id, iui.current_inventory, ut.unit_type_id, ut.unit_type_name
                FROM item_unit_inventory iui
                JOIN unit_types ut ON iui.unit_type_id = ut.unit_type_id
                WHERE iui.item_id = ? AND LOWER(ut.unit_type_name) = LOWER(?)
                LIMIT 1
            ");
            if (!$stock_stmt) {
                throw new Exception('Database prepare error while fetching unit inventory');
            }
            $stock_stmt->bind_param('is', $item_id, $unit_type);
            $stock_stmt->execute();
            $stock_result = $stock_stmt->get_result();
            $stock_row = $stock_result ? $stock_result->fetch_assoc() : null;
            $stock_stmt->close();

            if (!$stock_row) {
                $unit_lookup_stmt = $conn->prepare("SELECT unit_type_id, unit_type_name FROM unit_types WHERE LOWER(unit_type_name) = LOWER(?) AND status = 'active' LIMIT 1");
                $unit_type_id_for_inventory = 0;
                $unit_type_name_for_inventory = $unit_type;
                if ($unit_lookup_stmt) {
                    $unit_lookup_stmt->bind_param('s', $unit_type);
                    $unit_lookup_stmt->execute();
                    $unit_lookup_result = $unit_lookup_stmt->get_result();
                    if ($unit_lookup_row = $unit_lookup_result->fetch_assoc()) {
                        $unit_type_id_for_inventory = (int)$unit_lookup_row['unit_type_id'];
                        $unit_type_name_for_inventory = $unit_lookup_row['unit_type_name'];
                    }
                    $unit_lookup_stmt->close();
                }

                if ($unit_type_id_for_inventory <= 0) {
                    throw new Exception("Unit type inventory setup not found for '$unit_type'. Please register this UoM first.");
                }

                $create_inventory_stmt = $conn->prepare("
                    INSERT INTO item_unit_inventory (item_id, unit_type_id, current_inventory, beginning_inventory, as_of_date, unit_cost, created_at, updated_at)
                    VALUES (?, ?, 0, 0, CURDATE(), 0, NOW(), NOW())
                    ON DUPLICATE KEY UPDATE updated_at = NOW()
                ");
                if (!$create_inventory_stmt) {
                    throw new Exception('Database prepare error while creating unit inventory row');
                }
                $create_inventory_stmt->bind_param('ii', $item_id, $unit_type_id_for_inventory);
                $create_inventory_stmt->execute();
                $create_inventory_stmt->close();

                $stock_stmt = $conn->prepare("
                    SELECT iui.inventory_id, iui.current_inventory, ut.unit_type_id, ut.unit_type_name
                    FROM item_unit_inventory iui
                    JOIN unit_types ut ON iui.unit_type_id = ut.unit_type_id
                    WHERE iui.item_id = ? AND iui.unit_type_id = ?
                    LIMIT 1
                ");
                if (!$stock_stmt) {
                    throw new Exception('Database prepare error while refetching created unit inventory');
                }
                $stock_stmt->bind_param('ii', $item_id, $unit_type_id_for_inventory);
                $stock_stmt->execute();
                $stock_result = $stock_stmt->get_result();
                $stock_row = $stock_result ? $stock_result->fetch_assoc() : null;
                $stock_stmt->close();

                if (!$stock_row) {
                    throw new Exception("Unable to create inventory row for unit type: '$unit_type_name_for_inventory'");
                }
            }

            $current_unit_stock = (float)($stock_row['current_inventory'] ?? 0);

            // Allow confirmed orders even when stock is low.
            // This keeps the order process from being blocked by zero/insufficient stock.
            $new_unit_stock = $current_unit_stock - $quantity;

            $update_stmt = $conn->prepare("UPDATE item_unit_inventory SET current_inventory = ?, updated_at = NOW() WHERE inventory_id = ?");
            if (!$update_stmt) {
                throw new Exception('Database prepare error while updating unit inventory');
            }
            $inventory_id = (int)$stock_row['inventory_id'];
            $update_stmt->bind_param('di', $new_unit_stock, $inventory_id);
            $update_stmt->execute();
            $update_stmt->close();

            // Keep the legacy items.stock column in sync too.
            // This also allows negative stock in item master stock when the order exceeds availability.
            if ($items_branch_column_exists && !$view_all_branches) {
                $item_stock_update = $conn->prepare("UPDATE items SET stock = COALESCE(stock, 0) - ? WHERE item_id = ? AND branch_id = ?");
                if ($item_stock_update) {
                    $item_stock_update->bind_param('iii', $pieces_to_deduct, $item_id, $branch_id);
                    $item_stock_update->execute();
                    $item_stock_update->close();
                }
            } else {
                $item_stock_update = $conn->prepare("UPDATE items SET stock = COALESCE(stock, 0) - ? WHERE item_id = ?");
                if ($item_stock_update) {
                    $item_stock_update->bind_param('ii', $pieces_to_deduct, $item_id);
                    $item_stock_update->execute();
                    $item_stock_update->close();
                }
            }

            $updated_stock_data[] = [
                'item_id' => $item_id,
                'unit_type' => $stock_row['unit_type_name'],
                'new_stock' => $new_unit_stock
            ];
        }
        
        $invoice_id = 0;
        $payment_id = 0;
        if ($fulfillment_type === 'pickup') {
            if ($collect_payment) {
                if ($payment_method === 'cash') {
                    if ($cash_tendered === null || $cash_tendered <= 0) {
                        throw new Exception('Cash tendered is required.');
                    }
                    if ($cash_tendered + 0.009 < $total_amount) {
                        throw new Exception('Cash tendered cannot be lower than grand total.');
                    }
                    $cash_change = max($cash_tendered - $total_amount, 0);
                } elseif ($payment_method === 'check') {
                    $check_date = trim($_POST['check_date'] ?? '');
                    $bank_name = trim($_POST['bank_name'] ?? '');
                    $bank_branch = trim($_POST['bank_branch'] ?? '');
                    $check_number = trim($_POST['check_number'] ?? '');
                    if ($check_date === '' || $bank_name === '' || $bank_branch === '' || $check_number === '') {
                        throw new Exception('All check details are required.');
                    }
                    $reference_number = $check_number;
                } elseif ($payment_method === 'online_transfer') {
                    $reference_number = trim($_POST['reference_number'] ?? '');
                    $bank_name = trim($_POST['online_bank_name'] ?? '');
                    $bank_branch = trim($_POST['online_bank_branch'] ?? '');
                    if ($reference_number === '' || $bank_name === '') {
                        throw new Exception('Reference number and Bank/Wallet are required.');
                    }
                }

                $invoice_id = amgcOrderProductFindOrCreateInvoice($conn, $so_id, $customer_id, $branch_id, $total_amount, $user_id, true);
                $payment_id = amgcOrderProductInsertPayment($conn, $invoice_id, $so_id, $customer_id, $branch_id, $user_id, $payment_method, $total_amount, $reference_number, $check_date, $bank_name, $bank_branch, $check_number, $cash_tendered, $cash_change);

                // Pickup means the customer personally received/collected the order.
                // Once payment is collected, the sales order should no longer stay confirmed.
                $mark_pickup_delivered_stmt = $conn->prepare("UPDATE sales_orders SET order_status = 'delivered', payment_status = 'paid' WHERE so_id = ?");
                if ($mark_pickup_delivered_stmt) {
                    $mark_pickup_delivered_stmt->bind_param('i', $so_id);
                    $mark_pickup_delivered_stmt->execute();
                    $mark_pickup_delivered_stmt->close();
                }
            } else {
                $invoice_id = amgcOrderProductFindOrCreateInvoice($conn, $so_id, $customer_id, $branch_id, $total_amount, $user_id, false);
            }
        }

        if ($fulfillment_type === 'delivery') {
            // Same confirmation output as sales_order.php: pending invoice + planned trip ticket.
            $invoice_id = amgcOrderProductFindOrCreateInvoice($conn, $so_id, $customer_id, $branch_id, $total_amount, $user_id, false);
            $trip_ticket_id = amgcOrderProductCreateDeliveryTripTicket($conn, $so_id, $pick_list_id, $delivery_driver_id, $delivery_vehicle_id, $branch_id, $user_id);
        }

        amgcOrderProductRecalcCustomerCreditUsed($conn, $customer_id);
        
        $conn->commit();
        
        echo json_encode([
            'success' => true, 
            'message' => $fulfillment_type === 'pickup' ? ($collect_payment ? 'Pickup order delivered and payment sent to undeposited payments!' : 'Pickup order confirmed. Payment is now available in Collections.') : 'Delivery order confirmed and assigned successfully!', 
            'so_number' => $so_number,
            'so_id' => $so_id,
            'invoice_id' => $invoice_id,
            'payment_id' => $payment_id,
            'pick_list_id' => $pick_list_id,
            'trip_ticket_id' => $trip_ticket_id,
            'fulfillment_type' => $fulfillment_type,
            'payment_collected' => $collect_payment,
            'updated_stock' => $updated_stock_data,
            'discount_percent' => $discount_percent,
            'discount_calculation_type' => $discount_calculation_type,
            'discount_based_amount' => $discount_based_amount,
            'discount_amount' => $discount_amount,
            'total_amount' => $total_amount
        ]);
        exit;
        
    } catch (Exception $e) {
        $conn->rollback();
        error_log("Order submission error: " . $e->getMessage());
        $error_message = $e->getMessage();
        if (strpos($error_message, '{"type":"credit_limit_required"') === 0 || strpos($error_message, '{"type":"credit_limit_error"') === 0 || strpos($error_message, '{"type":"outstanding_balance_required"') === 0) {
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

// AJAX handler to get approved discount for a customer
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'get_customer_discount') {
    header('Content-Type: application/json');
    
    try {
        $customer_id = (int)$_POST['customer_id'];
        
        if (!$view_all_branches && $branch_column_exists) {
            $check_sql = "SELECT customer_id FROM customers WHERE customer_id = ? AND branch_id = ?";
            $check_stmt = $conn->prepare($check_sql);
            $check_stmt->bind_param('ii', $customer_id, $branch_id);
            $check_stmt->execute();
            if ($check_stmt->get_result()->num_rows === 0) {
                throw new Exception("Customer not found or access denied");
            }
        }
        
        $cdr_columns_result = $conn->query("SHOW COLUMNS FROM credit_discount_requests");
        $cdr_columns = [];
        if ($cdr_columns_result) {
            while ($col = $cdr_columns_result->fetch_assoc()) {
                $cdr_columns[] = $col['Field'];
            }
        }
        $select_discount_type = in_array('discount_calculation_type', $cdr_columns, true) ? "discount_calculation_type" : "'percentage' AS discount_calculation_type";
        $select_discount_based_amount = in_array('discount_based_amount', $cdr_columns, true) ? "discount_based_amount" : "0 AS discount_based_amount";
        $select_calculated_discount_amount = in_array('calculated_discount_amount', $cdr_columns, true) ? "calculated_discount_amount" : "0 AS calculated_discount_amount";
        
        $query = "SELECT requested_discount_percent,
                         $select_discount_type,
                         $select_discount_based_amount,
                         $select_calculated_discount_amount
                  FROM credit_discount_requests 
                  WHERE customer_id = ? 
                    AND status = 'approved' 
                    AND (request_type = 'discount' OR request_type = 'both')
                    AND (effective_until IS NULL OR effective_until > NOW())
                  ORDER BY approved_at DESC, request_id DESC
                  LIMIT 1";
        $stmt = $conn->prepare($query);
        $stmt->bind_param('i', $customer_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $discount = 0;
        $discount_type = 'percentage';
        $discount_based_amount = 0;
        $calculated_discount_amount = 0;
        if ($row = $result->fetch_assoc()) {
            $discount = (float)($row['requested_discount_percent'] ?? 0);
            $discount_type = $row['discount_calculation_type'] ?? 'percentage';
            $discount_based_amount = (float)($row['discount_based_amount'] ?? 0);
            $calculated_discount_amount = (float)($row['calculated_discount_amount'] ?? 0);
            if (!in_array($discount_type, ['percentage', 'amount_based'], true)) {
                $discount_type = 'percentage';
            }
        }
        
        echo json_encode([
            'success' => true,
            'discount' => $discount,
            'discount_type' => $discount_type,
            'discount_based_amount' => $discount_based_amount,
            'calculated_discount_amount' => $calculated_discount_amount
        ]);
        exit;
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
        exit;
    }
}

// AJAX handler to get approved credit terms for a customer
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'get_customer_credit_terms') {
    header('Content-Type: application/json');
    
    try {
        $customer_id = (int)$_POST['customer_id'];
        
        if (!$view_all_branches && $branch_column_exists) {
            $check_sql = "SELECT customer_id FROM customers WHERE customer_id = ? AND branch_id = ?";
            $check_stmt = $conn->prepare($check_sql);
            $check_stmt->bind_param('ii', $customer_id, $branch_id);
            $check_stmt->execute();
            if ($check_stmt->get_result()->num_rows === 0) {
                throw new Exception("Customer not found or access denied");
            }
        }
        
        $query = "SELECT requested_credit_limit, credit_terms_days 
                  FROM credit_discount_requests 
                  WHERE customer_id = ? 
                    AND status = 'approved' 
                    AND (request_type = 'credit_terms' OR request_type = 'both')
                  ORDER BY approved_at DESC 
                  LIMIT 1";
        $stmt = $conn->prepare($query);
        $stmt->bind_param('i', $customer_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $credit_limit = 0;
        $credit_terms_days = 0;
        
        if ($row = $result->fetch_assoc()) {
            $credit_limit = (float)($row['requested_credit_limit'] ?? 0);
            $credit_terms_days = (int)($row['credit_terms_days'] ?? 0);
        }
        
        echo json_encode([
            'success' => true,
            'credit_limit' => $credit_limit,
            'credit_terms_days' => $credit_terms_days
        ]);
        exit;
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
        exit;
    }
}

// AJAX handler to get customer outstanding balance snapshot for Review & Confirm modal
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'get_customer_outstanding_snapshot') {
    header('Content-Type: application/json');

    try {
        $customer_id = (int)($_POST['customer_id'] ?? 0);
        $order_amount = (float)($_POST['order_amount'] ?? 0);

        if ($customer_id <= 0) {
            throw new Exception('Invalid customer.');
        }

        if (!$view_all_branches && $branch_column_exists) {
            $check_sql = "SELECT customer_id FROM customers WHERE customer_id = ? AND branch_id = ?";
            $check_stmt = $conn->prepare($check_sql);
            $check_stmt->bind_param('ii', $customer_id, $branch_id);
            $check_stmt->execute();
            if ($check_stmt->get_result()->num_rows === 0) {
                throw new Exception('Customer not found or access denied');
            }
            $check_stmt->close();
        }

        $snapshot = amgcOrderProductGetCustomerCreditSnapshot($conn, $customer_id, $order_amount);
        echo json_encode([
            'success' => true,
            'has_credit_limit' => (bool)$snapshot['has_credit_limit'],
            'credit_limit' => (float)$snapshot['credit_limit'],
            'outstanding_balance' => (float)$snapshot['credit_used'],
            'order_amount' => $order_amount,
            'projected_credit_used' => (float)$snapshot['projected_credit_used'],
            'requires_outstanding_approval' => (!$snapshot['has_credit_limit'] && (float)$snapshot['credit_used'] > 0)
        ]);
        exit;
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

// ============= HANDLE GET ORDER DETAILS (for modal) =============
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'get_order_details') {
    header('Content-Type: application/json');
    
    try {
        $order_id = (int)$_POST['order_id'];
        
        // Get order details
        $sql = "SELECT 
                    so.so_id,
                    so.so_number,
                    so.document_type,
                    so.atw_no,
                    so.gatepass_no,
                    so.order_date,
                    so.total_amount,
                    COALESCE(so.outstanding_balance_amount, 0) AS outstanding_balance_amount,
                    COALESCE(so.outstanding_balance_approval_required, 0) AS outstanding_balance_approval_required,
                    COALESCE(so.outstanding_balance_approved, 0) AS outstanding_balance_approved,
                    so.outstanding_balance_approved_at,
                    so.outstanding_balance_reason,
                    TRIM(CONCAT(COALESCE(obau.first_name, ''), ' ', COALESCE(obau.last_name, ''))) AS outstanding_balance_approved_by_name,
                    COALESCE(so.discount_percent, 0) AS discount_percent,
                    COALESCE(so.discount_amount, 0) AS discount_amount,
                    COALESCE(so.discount_calculation_type, 'percentage') AS discount_calculation_type,
                    COALESCE(so.discount_based_amount, 0) AS discount_based_amount,
                    COALESCE(so.total_discount_amount, 0) AS total_discount_amount,
                    (
                        SELECT COALESCE(SUM(soi_sub.quantity_ordered * COALESCE(NULLIF(soi_sub.gross_price, 0), NULLIF(soi_sub.unit_price, 0), 0)), 0)
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
                LEFT JOIN users obau ON so.outstanding_balance_approved_by = obau.user_id
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
                        COALESCE(soi.gross_price, 0) AS gross_price,
                        COALESCE(soi.net_price, soi.unit_price, 0) AS net_price,
                        COALESCE(soi.order_amount, 0) AS order_amount,
                        COALESCE(soi.total_discount, 0) AS total_discount,
                        COALESCE(soi.discount_amount, 0) AS discount_amount,
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
        exit;
        
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
        
        $sql = "SELECT 
                    soi.so_item_id,
                    soi.so_id,
                    soi.item_id,
                    soi.quantity_ordered,
                    soi.quantity_delivered,
                    soi.unit_price,
                    soi.unit_type,
                    so.so_number,
                    so.document_type,
                    so.atw_no,
                    so.gatepass_no,
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
        
        $driver_query = "SELECT d.driver_name, d.vehicle_plate_number, d.vehicle_type FROM pick_lists pl JOIN drivers d ON pl.driver_id = d.driver_id WHERE pl.so_id = ? LIMIT 1";
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
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}
// Handle cancel order (restore stock)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'cancel_order') {
    header('Content-Type: application/json');
    
    try {
        $order_id = isset($_POST['order_id']) ? (int)$_POST['order_id'] : 0;
        
        if ($order_id <= 0) {
            throw new Exception("Invalid order ID");
        }
        
        $conn->begin_transaction();
        
        // Check if order exists and belongs to this branch
        if ($items_branch_column_exists && !$view_all_branches) {
            $check_sql = "SELECT so_id, order_status FROM sales_orders WHERE so_id = ? AND branch_id = ?";
            $check_stmt = $conn->prepare($check_sql);
            $check_stmt->bind_param('ii', $order_id, $branch_id);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            
            if ($check_result->num_rows === 0) {
                throw new Exception("Order not found or access denied");
            }
            
            $order = $check_result->fetch_assoc();
            if ($order['order_status'] === 'cancelled') {
                throw new Exception("Order is already cancelled");
            }
        }
        
        // Get items and restore stock
       $items_sql = "SELECT item_id, quantity_ordered, unit_type FROM sales_order_items WHERE so_id = ?";
        $items_stmt = $conn->prepare($items_sql);
        $items_stmt->bind_param('i', $order_id);
        $items_stmt->execute();
        $items_result = $items_stmt->get_result();
        $order_items = $items_result->fetch_all(MYSQLI_ASSOC);
        
        foreach ($order_items as $item) {
            $item_id = (int)$item['item_id'];
            $quantity = (int)$item['quantity_ordered'];
$unit_type = $item['unit_type'] ?? 'piece';
$pieces_multiplier = getItemUnitQuantity($conn, $item_id, $unit_type, $branch_id, $items_branch_column_exists, $view_all_branches);
$quantity = $quantity * $pieces_multiplier;
            
            if ($items_branch_column_exists && !$view_all_branches) {
                $update_sql = "UPDATE items SET stock = COALESCE(stock, 0) + ? WHERE item_id = ? AND branch_id = ?";
                $update_stmt = $conn->prepare($update_sql);
                $update_stmt->bind_param('iii', $quantity, $item_id, $branch_id);
            } else {
                $update_sql = "UPDATE items SET stock = COALESCE(stock, 0) + ? WHERE item_id = ?";
                $update_stmt = $conn->prepare($update_sql);
                $update_stmt->bind_param('ii', $quantity, $item_id);
            }
            $update_stmt->execute();
            $update_stmt->close();
        }
        
        // Update order status
        $update_sql = "UPDATE sales_orders SET order_status = 'cancelled' WHERE so_id = ?";
        $update_stmt = $conn->prepare($update_sql);
        $update_stmt->bind_param('i', $order_id);
        $update_stmt->execute();
        
        $conn->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Order cancelled and stock restored successfully'
        ]);
        exit;
        
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
        exit;
    }
}
// Handle AJAX request to get product unit types
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'get_product_unit_types') {
    header('Content-Type: application/json');
    
    try {
        $product_id = isset($_POST['product_id']) ? (int)$_POST['product_id'] : 0;
        $price_level = isset($_POST['price_level']) ? trim($_POST['price_level']) : 'Standard';
        
        if ($product_id <= 0) {
            throw new Exception("Invalid product ID");
        }
        
        $unit_types_query = "
            SELECT unit_type_id, unit_type_name, uom_initial, unit_price, quantity_smallest_pack
            FROM (
                SELECT 
                    ut.unit_type_id,
                    ut.unit_type_name,
                    ut.uom_initial,
                    iup.unit_price,
                    COALESCE(ut.quantity_smallest_pack, 1) AS quantity_smallest_pack,
                    ROW_NUMBER() OVER (
                        PARTITION BY ut.unit_type_id
                        ORDER BY 
                            CASE 
                                WHEN iup.price_level = ? THEN 0
                                ELSE 1
                            END,
                            CASE 
                                WHEN iup.effective_date IS NULL THEN 1
                                WHEN iup.effective_date <= CURDATE() THEN 0
                                ELSE 2
                            END,
                            CASE WHEN iup.effective_date <= CURDATE() THEN iup.effective_date END DESC,
                            CASE WHEN iup.effective_date > CURDATE() THEN iup.effective_date END ASC,
                            iup.pricing_id DESC
                    ) AS rn,
                    ut.is_default_uom
                FROM item_unit_pricing iup
                JOIN unit_types ut ON iup.unit_type_id = ut.unit_type_id
                WHERE iup.item_id = ? AND ut.status = 'active' AND (iup.price_level = ? OR iup.price_level = 'Standard')
            ) ranked_unit_types
            WHERE rn = 1
            ORDER BY is_default_uom DESC, quantity_smallest_pack ASC, unit_type_name ASC
        ";
        
        $unit_types_stmt = $conn->prepare($unit_types_query);
        $unit_types_stmt->bind_param('sis', $price_level, $product_id, $price_level);
        $unit_types_stmt->execute();
        $unit_types_result = $unit_types_stmt->get_result();
        $unit_types = $unit_types_result->fetch_all(MYSQLI_ASSOC);
        
        // Debug: Log kung may nahanap
        error_log("Product ID: $product_id, Found " . count($unit_types) . " unit types");
        
        echo json_encode([
            'success' => true,
            'unit_types' => $unit_types
        ]);
        exit;
        
    } catch (Exception $e) {
        error_log("get_product_unit_types error: " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
        exit;
    }
}

// AJAX handler for product details
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'get_product_details') {
    header('Content-Type: application/json');
    
    try {
        $product_id = isset($_POST['product_id']) ? (int)$_POST['product_id'] : 0;
        $price_level = isset($_POST['price_level']) ? trim($_POST['price_level']) : 'Standard';
        
        if ($product_id <= 0) {
            throw new Exception("Invalid product ID");
        }
        
        if ($items_branch_column_exists && !$view_all_branches) {
            $product_query = "SELECT i.item_id, i.item_code, i.item_name, i.description, i.category, 
                            i.stock, i.unit_type, i.unit_price, i.price_case, i.price_inner_pack, 
                            i.price_box, i.price_carton, i.reorder_level, i.status,
                            i.product_image_url,
                            b.branch_name
                            FROM items i
                            LEFT JOIN branches b ON i.branch_id = b.branch_id
                            WHERE i.item_id = ? AND i.branch_id = ?";
            $stmt = $conn->prepare($product_query);
            $stmt->bind_param('ii', $product_id, $branch_id);
        } else {
            $product_query = "SELECT i.item_id, i.item_code, i.item_name, i.description, i.category, 
                            i.stock, i.unit_type, i.unit_price, i.price_case, i.price_inner_pack, 
                            i.price_box, i.price_carton, i.reorder_level, i.status,
                            i.product_image_url
                            FROM items i
                            WHERE i.item_id = ?";
            $stmt = $conn->prepare($product_query);
            $stmt->bind_param('i', $product_id);
        }
        
        $stmt->execute();
        $result = $stmt->get_result();
        $product = $result->fetch_assoc();
        
        if (!$product) {
            throw new Exception("Product not found");
        }
        
        // Get unit types with pricing
        $unit_types_query = "
            SELECT unit_type_id, unit_type_name, uom_initial, unit_price, quantity_smallest_pack
            FROM (
                SELECT 
                    ut.unit_type_id,
                    ut.unit_type_name,
                    ut.uom_initial,
                    iup.unit_price,
                    COALESCE(ut.quantity_smallest_pack, 1) AS quantity_smallest_pack,
                    ROW_NUMBER() OVER (
                        PARTITION BY ut.unit_type_id
                        ORDER BY 
                            CASE 
                                WHEN iup.price_level = ? THEN 0
                                ELSE 1
                            END,
                            CASE 
                                WHEN iup.effective_date IS NULL THEN 1
                                WHEN iup.effective_date <= CURDATE() THEN 0
                                ELSE 2
                            END,
                            CASE WHEN iup.effective_date <= CURDATE() THEN iup.effective_date END DESC,
                            CASE WHEN iup.effective_date > CURDATE() THEN iup.effective_date END ASC,
                            iup.pricing_id DESC
                    ) AS rn,
                    ut.is_default_uom
                FROM item_unit_pricing iup
                JOIN unit_types ut ON iup.unit_type_id = ut.unit_type_id
                WHERE iup.item_id = ? AND ut.status = 'active' AND (iup.price_level = ? OR iup.price_level = 'Standard')
            ) ranked_unit_types
            WHERE rn = 1
            ORDER BY is_default_uom DESC, quantity_smallest_pack ASC, unit_type_name ASC
        ";
        
        $unit_types_stmt = $conn->prepare($unit_types_query);
        $unit_types_stmt->bind_param('sis', $price_level, $product_id, $price_level);
        $unit_types_stmt->execute();
        $unit_types_result = $unit_types_stmt->get_result();
        $unit_types = $unit_types_result->fetch_all(MYSQLI_ASSOC);
        
        // Get images
        $images_query = "SELECT image_id, image_path, image_order, is_primary FROM item_images WHERE item_id = ? ORDER BY is_primary DESC, image_order ASC";
        $images_stmt = $conn->prepare($images_query);
        $images_stmt->bind_param('i', $product_id);
        $images_stmt->execute();
        $images_result = $images_stmt->get_result();
        $images = $images_result->fetch_all(MYSQLI_ASSOC);
        
        // Get order history
        $history_query = "SELECT so.so_number, so.order_date, c.customer_name, so.order_status,
                        soi.quantity_ordered, soi.unit_type, soi.unit_price,
                        (soi.quantity_ordered * soi.unit_price) as total_price
                        FROM sales_order_items soi
                        JOIN sales_orders so ON soi.so_id = so.so_id
                        JOIN customers c ON so.customer_id = c.customer_id
                        WHERE soi.item_id = ?";
        
        if ($items_branch_column_exists && !$view_all_branches) {
            $history_query .= " AND so.branch_id = ?";
            $history_query .= " ORDER BY so.order_date DESC LIMIT 50";
            $history_stmt = $conn->prepare($history_query);
            $history_stmt->bind_param('ii', $product_id, $branch_id);
        } else {
            $history_query .= " ORDER BY so.order_date DESC LIMIT 50";
            $history_stmt = $conn->prepare($history_query);
            $history_stmt->bind_param('i', $product_id);
        }
        
        $history_stmt->execute();
        $history_result = $history_stmt->get_result();
        $order_history = $history_result->fetch_all(MYSQLI_ASSOC);
        
        echo json_encode([
            'success' => true,
            'product' => $product,
            'unit_types' => $unit_types,
            'images' => $images,
            'order_history' => $order_history
        ]);
        exit;
        
    } catch (Exception $e) {
        error_log("get_product_details error: " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order Product - Branch Admin</title>
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
    <!-- SweetAlert2 -->
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
        <!-- Session Checker -->
    <script src="../js/session-checker.js"></script>
    <style>
        /* FIXED LAYOUT - Only product table scrolls */
html, body {
    height: 100vh;
    overflow: hidden;
    margin: 0;
    padding: 0;
}

body {
    display: flex;
    flex-direction: column;
    background: #f5f5f5;
}

/* Main content wrapper */
.main-content {
    flex: 1;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    padding: 0 15px 15px 15px !important;
    min-height: 0;
}

/* Navbar - fixed at top */
.navbar-top {
    flex-shrink: 0;
    margin-bottom: 15px;
}

/* Category tabs container - fixed height, no scroll */
.category-tabs-container {
    flex-shrink: 0;
    margin-bottom: 15px;
}

/* Products section - takes remaining space and enables scrolling */
.products-section {
    flex: 1;
    display: flex;
    flex-direction: column;
    min-height: 0;
    margin-top: 0;
    overflow: hidden;
}

/* Product action bar - fixed */
.product-action-bar {
    flex-shrink: 0;
    margin-bottom: 15px;
}

.product-table-container {
    flex: 1;
    overflow-y: auto !important;
    overflow-x: auto !important;
    min-height: 0;
    max-height: calc(100vh - 250px); /* fallback para sigurado */
    padding-bottom: 120px; /* para di matakpan ng mobile bottom bar */
}

/* Make table take full width */
.product-table {
    width: 100%;
    border-collapse: collapse;
    min-width: 700px;
}

/* Sticky header for product table - stays on top when scrolling */
.product-table thead {
    position: sticky;
    top: 0;
    z-index: 10;
    background: #047857;
}

/* Ensure table body rows are properly displayed */
.product-table tbody {
    display: table-row-group;
}

/* Remove extra padding from main-content on mobile */
@media (max-width: 768px) {
    .main-content {
        padding: 6px 10px 10px 10px !important;
    }
    
    .product-table-container {
        max-height: none;
    }
    
    /* Make product images smaller on mobile */
    .product-thumbnail {
        width: 50px !important;
        height: 50px !important;
    }
    
    .product-name {
        font-size: 14px !important;
    }
    
    .unit-btn {
        padding: 2px 5px !important;
        font-size: 11px !important;
        min-width: 30px !important;
        min-height: 30px !important;
    }
    
    .qty-input {
        width: 60px !important;
        height: 32px !important;
        font-size: 12px !important;
    }
    
    .price-input {
        width: 90px !important;
        height: 32px !important;
        font-size: 12px !important;
    }
}

/* Ensure sidebar overlay doesn't cause body scroll */
.sidebar-overlay {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.5);
    z-index: 999;
    display: none;
}

.sidebar-overlay.active {
    display: block;
}

/* Mobile nav fix */
.mobile-nav {
    flex-shrink: 0;
}
        * {
            box-sizing: border-box;
        }

        /* Cart Item Styling */
        .cart-item {
            background: #F5F5F5;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 10px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-left: 4px solid #047857;
        }
        
/* Cart Icon Button in Header */
.navbar-top .btn-success {
    background: #047857;
    border: none;
    padding: 8px 12px;
    border-radius: 8px;
    font-size: 18px;
    flex-shrink: 0;
    min-width: 40px;
    min-height: 40px;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    position: relative;
}

.navbar-top .btn-success .badge {
    font-size: 10px;
    padding: 3px 5px;
    top: -5px;
    right: -5px;
    background: #1B5E20 !important;
    border: 2px solid #FFFFFF;
    /* Add these lines to center the count */
    display: inline-flex !important;
    align-items: center;
    justify-content: center;
    text-align: center;
    line-height: 1;
    min-width: 18px;
}

/* Optional: para sa 3-digit numbers (100+) */
.navbar-top .btn-success .badge.badge-large {
    padding: 3px 6px;
    font-size: 9px;
}
        
        /* Category Tabs */
        .category-tabs-container {
            background: #FFFFFF;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            margin-bottom: 20px;
            padding: 15px 15px 0 15px;
        }
        
        .tabs-header {
            display: flex;
            align-items: center;
            gap: 15px;
            border-bottom: 2px solid #e0e0e0;
            padding-bottom: 10px;
        }
        
        .tabs-scroll {
            flex: 1;
            overflow-x: auto;
            overflow-y: hidden;
            white-space: nowrap;
            padding-bottom: 5px;
        }
        
        .category-tabs {
            display: inline-flex;
            gap: 5px;
        }
        
        .tab-btn {
            padding: 8px 20px;
            border: none;
            background: none;
            color: #666;
            font-weight: 600;
            font-size: 14px;
            border-radius: 20px;
            cursor: pointer;
            transition: all 0.2s;
            white-space: nowrap;
        }
        
        .tab-btn:hover {
            background: #F5F5F5;
            color: #047857;
        }
        
        .tab-btn.active {
            background: #047857;
            color: #FFFFFF;
        }
        
        /* Search */
        .search-wrapper {
            position: relative;
            min-width: 250px;
        }
        
        .search-icon {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #999;
            font-size: 14px;
        }
        
        .search-input {
            width: 100%;
            padding: 10px 15px 10px 40px;
            border: 1px solid #e0e0e0;
            border-radius: 25px;
            font-size: 14px;
            transition: all 0.2s;
        }
        
        .search-input:focus {
            outline: none;
            border-color: #2E7D32;
            box-shadow: 0 0 0 3px rgba(46,125,50,0.1);
        }
        
        .search-reset {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #999;
            font-size: 18px;
            cursor: pointer;
            display: none;
        }
        
        .search-reset.visible {
            display: block;
        }

        .product-action-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 1rem;
            flex-wrap: wrap;
        }

        .product-action-bar .search-wrapper {
            flex: 1 1 280px;
            max-width: 420px;
            min-width: 240px;
        }

        .product-action-bar .btn-success {
            flex: 0 0 auto;
            white-space: nowrap;
        }

        .selected-customer-display {
            min-width: 260px;
            max-width: 360px;
            padding: 8px 12px;
            border: 1px solid #d1fae5;
            border-radius: 12px;
            background: #ecfdf5;
            color: #065f46;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .selected-customer-display i {
            font-size: 20px;
            color: #047857;
        }

        .selected-customer-label {
            display: block;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .03em;
            color: #047857;
            line-height: 1.1;
        }

        .selected-customer-name {
            display: block;
            font-size: 14px;
            font-weight: 700;
            color: #064e3b;
            line-height: 1.25;
            word-break: break-word;
        }

        @media (max-width: 768px) {
            .product-action-bar {
                align-items: stretch;
            }
            .product-action-bar .search-wrapper,
            .product-action-bar .btn-success,
            .selected-customer-display {
                width: 100%;
                max-width: none;
            }
        }
        
        /* Product Table */
        .product-table-container {
            background: #FFFFFF;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            overflow-x: auto;
            margin-bottom: 20px;
        }
        
        .product-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 700px;
        }
        
        .product-table thead {
            background: #047857;
            color: #FFFFFF;
        }
        
        .product-table th {
            padding: 10px 8px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            text-align: center;
            vertical-align: middle;
        }
        
        .product-table td {
            padding: 8px 6px;
            font-size: 12px;
            border-bottom: 1px solid #e0e0e0;
            vertical-align: middle;
            cursor: pointer;
        }
        
        .product-table td:first-child,
        .product-table td:nth-child(3),
        .product-table td:nth-child(4),
        .product-table td:nth-child(5) {
            cursor: pointer;
        }
        
        .product-table th:nth-child(1) { width: 8%; }
        .product-table th:nth-child(2) { width: 30%; }
        .product-table th:nth-child(3) { width: 22%; }
        .product-table th:nth-child(4) { width: 18%; }
        .product-table th:nth-child(5) { width: 10%; }
        
        .product-table td:nth-child(2) { text-align: left; }
        .product-table td:not(:nth-child(2)) { text-align: center; }
        
        /* Product image */
        .product-thumbnail {
            width: 80px;
            height: 80px;
            object-fit: cover;
            border-radius: 4px;
            border: 1px solid #e0e0e0;
            background: #F5F5F5;
        }
        
        .product-info {
            display: flex;
            flex-direction: column;
        }
        
        .product-name {
            font-weight: 600;
            color: #212121;
            font-size: 18px;
            margin-bottom: 4px;
        }
        
        .stock-info {
            font-size: 15px;
            color: #2E7D32;
            font-weight: 600;
        }
        
        .stock-warning {
            color: #dc3545 !important;
            font-weight: 600;
        }
        
        /* Unit buttons */
        .unit-buttons {
            display: flex;
            gap: 3px;
            flex-wrap: wrap;
            justify-content: center;
        }
        
        .unit-btn {
            background: #FFFFFF;
            border: 1px solid #e0e0e0;
            border-radius: 4px;
            padding: 4px 8px;
            font-size: 15px;
            font-weight: 600;
            color: #212121;
            min-width: 38px;
            min-height: 40px;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .unit-btn:hover {
            background: #e0e0e0;
        }
        
        .unit-btn.active {
            background: #047857;
            color: #FFFFFF;
            border-color: #047857;
        }
        
        /* Quantity controls */
        .quantity-controls {
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .qty-input {
            width: 80px;
            height: 40px;
            text-align: center;
            border: 1px solid #e0e0e0;
            border-radius: 4px;
            font-size: 15px;
            padding: 0 4px;
        }
        
        .qty-input:focus {
            outline: none;
            border-color: #047857;
        }
        
        /* Price cell */
        .price-cell {
            font-weight: 700;
            color: #047857;
            font-size: 12px;
        }
        
        .price-input {
            width: 100px;
            height: 40px;
            text-align: right;
        }
        
        /* Toast notification - CENTERED */
        .toast-notification {
            position: fixed;
            top: 20px;
            left: 50%;
            transform: translateX(-50%);
            background: #ecfdf5;
            color: #065f46;
            border: 1px solid #d1fae5;
            padding: 12px 24px;
            border-radius: 8px;
            z-index: 9999;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            font-size: 14px;
            animation: slideInDown 0.3s ease;
            white-space: nowrap;
        }

        @keyframes slideInDown {
            from {
                transform: translateX(-50%) translateY(-100%);
                opacity: 0;
            }
            to {
                transform: translateX(-50%) translateY(0);
                opacity: 1;
            }
        }

        .toast-notification.fade-out {
            animation: fadeOut 0.3s ease forwards;
        }

        @keyframes fadeOut {
            to {
                opacity: 0;
                visibility: hidden;
            }
        }

        /* Para sa mobile - adjust padding at font size */
        @media (max-width: 576px) {
            .toast-notification {
                padding: 10px 16px;
                font-size: 12px;
                white-space: nowrap;
                top: 15px;
            }
        }

        /* Para sa sobrang habang message - gawing multi-line */
        @media (max-width: 480px) {
            .toast-notification {
                white-space: normal;
                max-width: 90%;
                text-align: center;
                line-height: 1.4;
            }
        }
        
        /* Navbar */
        .navbar-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            padding: 12px 20px;
            margin-bottom: 20px;
            background: #FFFFFF;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }
        
        
        .mobile-toggle-btn {
            display: none;
        }
        
        /* Modal styles */
        .modal-header {
            background: #047857;
            color: #FFFFFF;
            border: none;
            padding: 12px 20px;
        }
        
        .modal-header .btn-close {
            filter: brightness(0) invert(1);
            opacity: 0.8;
        }
        
        .customer-selection {
            background: #F5F5F5;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
            border-left: 4px solid #2E7D32;
        }
        
        .discount-line {
            color: #dc3545;
            font-weight: 500;
        }
        
        .credit-terms-line {
            color: #17a2b8;
            font-weight: 500;
            border-top: 1px dashed #e0e0e0;
            padding-top: 10px;
            margin-top: 10px;
        }
        
        /* Product info modal */
        .product-info-container {
            padding: 20px;
        }
        
        .product-header-section {
            display: flex;
            gap: 20px;
            margin-bottom: 20px;
            background: #F8F9FA;
            padding: 20px;
            border-radius: 10px;
        }
        
        .product-image-large {
            width: 150px;
            height: 150px;
            object-fit: cover;
            border-radius: 10px;
            border: 2px solid #047857;
            background: #FFFFFF;
            padding: 3px;
        }
        
        .info-row {
            display: flex;
            align-items: baseline;
            padding: 8px 0;
            border-bottom: 1px dashed #e0e0e0;
            font-size: 13px;
        }
        
        .info-label {
            width: 100px;
            font-weight: 600;
            color: #212121;
        }
        
        .info-value {
            flex: 1;
            color: #047857;
            font-weight: 600;
        }
        
        .price-tag {
            font-size: 20px;
            font-weight: 700;
        }
        
        .stock-tag {
            background: #047857;
            color: #FFFFFF;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
        }
        
        .loading-state {
            text-align: center;
            padding: 50px;
        }
        
        .history-table {
            width: 100%;
            font-size: 12px;
            border-collapse: collapse;
        }
        
        .history-table th {
            background: #F8F9FA;
            padding: 10px;
            text-align: center;
        }
        
        .history-table td {
            padding: 8px;
            text-align: center;
            border-bottom: 1px solid #e0e0e0;
        }
        
        .status-badge {
            padding: 3px 8px;
            border-radius: 15px;
            font-size: 10px;
            font-weight: 600;
            display: inline-block;
        }
        
        .status-pending { background: #ffc107; color: #212121; }
        .status-completed { background: #2E7D32; color: #FFFFFF; }
        .status-cancelled { background: #dc3545; color: #FFFFFF; }
        
        /* No results */
        .no-results {
            text-align: center;
            padding: 40px;
            background: #FFFFFF;
            border-radius: 10px;
            color: #666;
        }
        
        .no-results i {
            font-size: 48px;
            color: #ccc;
            margin-bottom: 10px;
        }
        
        /* Alert */
        .alert-info {
            background-color: #F5F5F5;
            border-color: #e0e0e0;
            color: #212121;
            border-radius: 8px;
            margin-bottom: 15px;
            font-size: 13px;
        }
        
        /* Hide mobile-specific elements */
        .mobile-price-display,
        .mobile-unit-qty-container,
        .mobile-only {
            display: none !important;
        }
        
        /* Row hover effect */
        .product-table tbody tr:hover {
            background-color: #f8f9fa;
        }
        /* Currency input styling */
.currency-input {
    text-align: right;
    font-weight: 500;
}

.currency-display {
    font-weight: 600;
    letter-spacing: 0.3px;
}

/* Number input spinner hide - keep inputs usable but remove up/down arrows */
input[type="number"] {
    -moz-appearance: textfield;
    appearance: textfield;
}

input[type="number"]::-webkit-inner-spin-button,
input[type="number"]::-webkit-outer-spin-button {
    -webkit-appearance: none;
    margin: 0;
    display: none;
}
/* ===== RECEIPT TABLE STYLES ===== */
.receipt-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 12px;
    table-layout: fixed;
}

.receipt-table th,
.receipt-table td {
    padding: 10px 6px;
    border-bottom: 1px solid #e0e0e0;
    vertical-align: middle;
    word-wrap: break-word;
}

.receipt-table th {
    background: #f8f9fa;
    font-weight: 600;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.3px;
    color: #666;
}

/* Fixed column widths */
.receipt-table th:nth-child(1), 
.receipt-table td:nth-child(1) { 
    width: 30%; 
    text-align: left; 
}

.receipt-table th:nth-child(2), 
.receipt-table td:nth-child(2) { 
    width: 12%; 
    text-align: center; 
}

.receipt-table th:nth-child(3), 
.receipt-table td:nth-child(3) { 
    width: 18%; 
    text-align: center; 
}

.receipt-table th:nth-child(4), 
.receipt-table td:nth-child(4) { 
    width: 15%; 
    text-align: right; 
}

.receipt-table th:nth-child(5), 
.receipt-table td:nth-child(5) { 
    width: 18%; 
    text-align: right; 
}

.receipt-table th:nth-child(6), 
.receipt-table td:nth-child(6) { 
    width: 7%; 
    text-align: center; 
}

/* Price and Total cell styling - same color */
.receipt-table td:nth-child(4) span,
.receipt-table td:nth-child(5) span,
.receipt-table td:nth-child(5) strong {
    font-weight: 600;
    color: #047857;
}
.receipt-table td:nth-child(5) span,
.receipt-table td:nth-child(5) strong {
    font-weight: 700;
    color: #047857;
}

/* Quantity input styling */
.review-qty-input {
    width: 90%;
    max-width: 80px;
    text-align: center;
    padding: 6px 4px;
    border: 1px solid #ddd;
    border-radius: 4px;
    font-size: 12px;
    box-sizing: border-box;
}

.review-qty-input:focus {
    outline: none;
    border-color: #047857 !important;
    box-shadow: 0 0 0 2px rgba(4, 120, 87, 0.1);
}

/* Pieces small text */
.pieces-small {
    font-size: 9px;
    color: #999;
    display: block;
    text-align: center;
    margin-top: 4px;
}

/* Product name cell */
.product-name-cell {
    font-weight: 600;
    color: #212121;
    font-size: 12px;
    word-break: break-word;
}

/* Delete button */
.delete-item-btn {
    background: none;
    border: none;
    color: #dc3545;
    font-size: 16px;
    cursor: pointer;
    padding: 4px 8px;
    border-radius: 4px;
    transition: all 0.2s;
}

.delete-item-btn:hover {
    background: #dc3545;
    color: white;
}

/* Mobile responsive */
@media (max-width: 768px) {
    .receipt-table {
        font-size: 10px;
    }
    
    .receipt-table th,
    .receipt-table td {
        padding: 8px 3px;
    }
    
    .receipt-table th {
        font-size: 9px;
        padding: 6px 3px;
    }
    
    .receipt-table th:nth-child(1), 
    .receipt-table td:nth-child(1) { width: 28%; }
    
    .receipt-table th:nth-child(2), 
    .receipt-table td:nth-child(2) { width: 10%; }
    
    .receipt-table th:nth-child(3), 
    .receipt-table td:nth-child(3) { width: 22%; }
    
    .receipt-table th:nth-child(4), 
    .receipt-table td:nth-child(4) { width: 15%; }
    
    .receipt-table th:nth-child(5), 
    .receipt-table td:nth-child(5) { width: 18%; }
    
    .receipt-table th:nth-child(6), 
    .receipt-table td:nth-child(6) { width: 10%; }
    
    .review-qty-input {
        width: 80%;
        min-width: 50px;
        font-size: 10px !important;
        padding: 4px 2px !important;
    }
    
    .product-name-cell {
        font-size: 10px;
    }
    
    .pieces-small {
        font-size: 7px;
        margin-top: 2px;
    }
    
    .delete-item-btn {
        padding: 2px 4px;
        font-size: 12px;
    }
    
    /* Price and Total - mobile */
    .receipt-table td:nth-child(4) span,
    .receipt-table td:nth-child(5) span,
    .receipt-table td:nth-child(5) strong {
        font-size: 10px;
    }
}

/* Extra small devices */
@media (max-width: 480px) {
    .receipt-table th,
    .receipt-table td {
        padding: 6px 2px;
    }
    
    .receipt-table th {
        font-size: 8px;
    }
    
    .product-name-cell {
        font-size: 9px;
    }
    
    .review-qty-input {
        font-size: 9px !important;
        min-width: 45px;
    }
    
    /* Price and Total - extra small */
    .receipt-table td:nth-child(4) span,
    .receipt-table td:nth-child(5) span,
    .receipt-table td:nth-child(5) strong {
        font-size: 9px;
    }
}
/* Order Alert Animations - remove order-alert-title reference */
@keyframes slideInUp {
    from {
        transform: translateY(30px);
        opacity: 0;
    }
    to {
        transform: translateY(0);
        opacity: 1;
    }
}

@keyframes pulse {
    0% { transform: scale(1); }
    50% { transform: scale(1.05); }
    100% { transform: scale(1); }
}

.swal2-popup.animated-order-alert {
    animation: slideInUp 0.4s ease-out;
    border-radius: 20px;
    padding: 20px;
}

.swal2-popup.animated-order-alert .swal2-title {
    font-size: 22px;
    font-weight: 700;
    color: #212121;
}

.swal2-popup.animated-order-alert .swal2-icon.swal2-success {
    animation: pulse 0.5s ease;
    border-color: #047857;
}

.swal2-popup.animated-order-alert .swal2-icon.swal2-success .swal2-success-ring {
    border-color: #047857;
}

.swal2-popup.animated-order-alert .swal2-icon.swal2-success [class^='swal2-success-line'] {
    background-color: #047857;
}

.order-confirm-btn {
    background: linear-gradient(135deg, #047857 0%, #065f46 100%) !important;
    border: none !important;
    font-weight: 600 !important;
    padding: 12px 24px !important;
    border-radius: 50px !important;
    transition: all 0.3s ease !important;
}

.order-confirm-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(4, 120, 87, 0.3);
}

.order-cancel-btn {
    background: #f8f9fa !important;
    color: #6c757d !important;
    border: 1px solid #e0e0e0 !important;
    font-weight: 600 !important;
    padding: 12px 24px !important;
    border-radius: 50px !important;
    transition: all 0.3s ease !important;
}

.order-cancel-btn:hover {
    background: #e9ecef !important;
    transform: translateY(-2px);
}


/* ===== PRODUCT LOADING + DISCOUNT SUMMARY FIX ===== */
.product-table.loading-products thead {
    display: none;
}
.product-loading-row td {
    padding: 0 !important;
    border: none !important;
    background: transparent !important;
}
.product-loading-panel {
    width: 100%;
    min-height: 255px;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 0.65rem;
    background: linear-gradient(135deg, #ffffff, #f8fff9);
    border: 1px solid #d1fae5;
    border-radius: 16px;
    box-shadow: 0 4px 15px rgba(4, 120, 87, 0.08), inset 0 0 0 1px rgba(68, 211, 78, 0.08);
    padding: 1.4rem 1rem;
}
.product-loading-logo {
    width: 58px;
    height: 58px;
    border-radius: 50%;
    background: linear-gradient(135deg, #047857, #44D34E);
    display: flex;
    align-items: center;
    justify-content: center;
    color: #ffffff;
    font-size: 1.55rem;
    box-shadow: 0 8px 22px rgba(4, 120, 87, 0.24);
    position: relative;
}
.product-loading-logo::after {
    content: '';
    position: absolute;
    inset: -6px;
    border-radius: 50%;
    border: 3px solid rgba(68, 211, 78, 0.22);
    border-top-color: #047857;
    animation: productSpin 0.9s linear infinite;
}
.product-loading-title {
    margin: 0;
    font-weight: 800;
    color: #052A47;
    font-size: 1rem;
    text-align: center;
}
.product-loading-subtitle {
    margin: 0;
    max-width: 520px;
    color: #64748b;
    font-size: 0.84rem;
    text-align: center;
    line-height: 1.45;
}
.product-skeleton-list {
    width: min(620px, 100%);
    margin-top: 0.45rem;
    display: grid;
    gap: 0.55rem;
}
.product-skeleton-item {
    display: grid;
    grid-template-columns: 46px 1fr 74px;
    gap: 0.7rem;
    align-items: center;
    background: #ffffff;
    border: 1px solid #edf2f7;
    border-radius: 13px;
    padding: 0.65rem;
}
.skeleton-block {
    background: linear-gradient(90deg, #eef2f7 25%, #f8fafc 50%, #eef2f7 75%);
    background-size: 200% 100%;
    animation: productSkeleton 1.2s ease-in-out infinite;
    border-radius: 10px;
    min-height: 14px;
}
.skeleton-img { width: 46px; height: 46px; border-radius: 12px; }
.skeleton-line-lg { height: 15px; width: 82%; }
.skeleton-line-sm { height: 11px; width: 52%; margin-top: 8px; }
.skeleton-pill { height: 28px; border-radius: 999px; }
@keyframes productSpin { to { transform: rotate(360deg); } }
@keyframes productSkeleton { 0% { background-position: 200% 0; } 100% { background-position: -200% 0; } }

.order-review-summary {
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    background: #ffffff;
    padding: 0.75rem;
}
.order-review-summary .summary-line {
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
.order-review-summary .summary-line:last-child { margin-bottom: 0; }
.order-review-summary .summary-line span { font-weight: 700; }
.order-review-summary .summary-line strong { font-weight: 800; white-space: nowrap; }
.order-review-summary .discount-line strong { color: #dc3545; }
.order-review-summary .grand-total-line {
    background: #d1fae5;
    border-top: 2px solid #44D34E;
}
.order-review-summary .grand-total-line span,
.order-review-summary .grand-total-line strong { color: #047857; }
.order-review-summary .discount-note {
    display: block;
    font-size: 0.72rem;
    color: #64748b;
    margin-top: 0.15rem;
}
@media (max-width: 576px) {
    .product-table-container { min-height: auto !important; }
    .product-loading-panel {
        min-height: 220px;
        border-radius: 14px;
        padding: 1.05rem 0.75rem;
        gap: 0.5rem;
    }
    .product-loading-logo { width: 48px; height: 48px; font-size: 1.3rem; }
    .product-loading-logo::after { inset: -5px; border-width: 2px; }
    .product-loading-title { font-size: 0.92rem; }
    .product-loading-subtitle { font-size: 0.76rem; line-height: 1.35; padding: 0 0.25rem; }
    .product-skeleton-list { gap: 0.45rem; margin-top: 0.35rem; }
    .product-skeleton-item {
        grid-template-columns: 38px 1fr;
        gap: 0.55rem;
        padding: 0.55rem;
        border-radius: 12px;
    }
    .product-skeleton-item .skeleton-pill { display: none; }
    .skeleton-img { width: 38px; height: 38px; border-radius: 10px; }
    .skeleton-line-lg { height: 13px; width: 90%; }
    .skeleton-line-sm { height: 10px; width: 60%; margin-top: 7px; }
    .order-review-summary { padding: 0.65rem; }
    .order-review-summary .summary-line { font-size: 0.86rem; padding: 0.6rem 0.75rem; }
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

#orderDetailsModal .modal-header .btn-close::before {
    font-size: 1rem !important;
    font-weight: bold !important;
    color: white !important;
    font-family: system-ui, -apple-system, sans-serif !important;
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
    
/* ===== ORDER DETAILS TOTALS SUMMARY ===== */
.order-totals-summary {
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    background: #ffffff;
    padding: 0;
    margin-top: 1rem;
    width: 100%;
    overflow: hidden;
}

.order-totals-summary .order-total-line {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0.85rem 1.25rem;
    border-bottom: 1px solid #e9ecef;
    background: #ffffff;
}

.order-totals-summary .order-total-line:last-child {
    border-bottom: none;
}

.order-totals-summary .order-total-line span {
    font-weight: 600;
    color: #4b5563;
    font-size: 0.9rem;
}

.order-totals-summary .order-total-line strong {
    font-weight: 700;
    font-size: 0.95rem;
    color: #1f2937;
}

.order-totals-summary .subtotal-summary-line {
    background: #fafafa;
}

.order-totals-summary .discount-summary-line {
    background: #fef2f2;
}

.order-totals-summary .discount-summary-line strong {
    color: #dc2626;
}

.order-totals-summary .grand-total-summary-line {
    background: #d1fae5;
}

.order-totals-summary .grand-total-summary-line span,
.order-totals-summary .grand-total-summary-line strong {
    color: #047857;
    font-weight: 800;
    font-size: 1rem;
}

/* Para hindi mag-break ang content */
.order-totals-summary .order-total-line span {
    flex-shrink: 0;
}

.order-totals-summary .order-total-line strong {
    text-align: right;
    margin-left: 1rem;
}

@media (max-width: 767px) {
    .order-totals-summary {
        margin-top: 0.75rem;
    }
    
    .order-totals-summary .order-total-line {
        padding: 0.75rem 1rem;
    }
    
    .order-totals-summary .order-total-line span {
        font-size: 0.85rem;
    }
    
    .order-totals-summary .order-total-line strong {
        font-size: 0.9rem;
    }
}

@media (max-width: 480px) {
    .order-totals-summary .order-total-line {
        padding: 0.65rem 0.85rem;
    }
    
    .order-totals-summary .order-total-line span {
        font-size: 0.8rem;
    }
    
    .order-totals-summary .order-total-line strong {
        font-size: 0.85rem;
    }
}
/* Hide table header when no results */
.product-table.no-results-mode thead {
    display: none;
}

/* Mobile Bottom Navigation Styles */
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

/* Small mobile adjustments */
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
                        <li class="nav-item dropdown-nav">
                            <a class="nav-link" href="#" onclick="toggleSidebarDropdown(event, 'warehouseMenu')">
                                <i class="bi bi-shop"></i>
                                <span class="nav-text">Warehouse</span>
                                <i class="bi bi-chevron-down dropdown-arrow"></i>
                            </a>
                            <div class="collapse" id="warehouseMenu">
                                <ul class="nav flex-column ps-4">
                                    <li class="nav-item"><a class="nav-link" href="current_inventory.php"><i class="bi bi-bar-chart-line"></i><span class="nav-text">Current Inventory</span></a></li>
                                    <li class="nav-item"><a class="nav-link" href="bad_orders.php"><i class="bi bi-recycle"></i><span class="nav-text">Bad Orders</span></a></li>
                                    <li class="nav-item"><a class="nav-link" href="pick_list_items.php"><i class="bi bi-list-check"></i><span class="nav-text">Pick List Items</span></a></li>
                                    <li class="nav-item"><a class="nav-link" href="pick_list_items.php"><i class="bi bi-list-check"></i><span class="nav-text">Pick List Items</span></a></li>
                                                                <li class="nav-item">
                                    <a class="nav-link" href="warehouses.php">
                                    <i class="bi bi-shop"></i>
                                    <span class="nav-text">Warehouses</span></a>
                                </li>
                                </ul>
                            </div>
                        </li>
                        <li class="nav-item dropdown-nav">
                            <a class="nav-link" href="#" onclick="toggleSidebarDropdown(event, 'supplierMenu')">
                                <i class="bi bi-building"></i>
                                <span class="nav-text">Supplier</span>
                                <i class="bi bi-chevron-down dropdown-arrow"></i>
                            </a>
                            <div class="collapse" id="supplierMenu">
                                <ul class="nav flex-column ps-4">
                                    <li class="nav-item"><a class="nav-link" href="purchase_order.php"><i class="bi bi-box"></i><span class="nav-text">Recieve Inventory</span></a></li>
                                    <li class="nav-item"><a class="nav-link" href="supplier.php"><i class="bi bi-people"></i><span class="nav-text">Supplier List</span></a></li>
                                </ul>
                            </div>
                        </li>
                        <li class="nav-item dropdown-nav">
                            <a class="nav-link" href="#" onclick="toggleSidebarDropdown(event, 'customerMenu')">
                                <i class="bi bi-people"></i>
                                <span class="nav-text">Customer</span>
                                <i class="bi bi-chevron-down dropdown-arrow"></i>
                            </a>
                            <div class="collapse" id="customerMenu">
                                <ul class="nav flex-column ps-4">
                                    <li class="nav-item"><a class="nav-link" href="customer_list.php"><i class="bi bi-person-badge"></i><span class="nav-text">Customer List</span></a></li>
                                    <li class="nav-item"><a class="nav-link" href="approve_credit_requests.php"><i class="bi bi-pencil-square"></i><span class="nav-text">Approved Credit Request</span></a></li>
                                    <li class="nav-item"><a class="nav-link" href="sales_order.php"><i class="bi bi-cart"></i><span class="nav-text">Sales Order</span></a></li>
                                    <li class="nav-item"><a class="nav-link" href="collections.php">
                <i class="bi bi-cash-stack"></i>
                    <span class="nav-text">Collections</span>
                </a>
            </li>
                                </ul>
                            </div>
                        </li>
                        <li class="nav-item dropdown-nav">
                            <a class="nav-link" href="#" onclick="toggleSidebarDropdown(event, 'deliveryMenu')">
                                <i class="bi bi-truck"></i>
                                <span class="nav-text">Delivery</span>
                                <i class="bi bi-chevron-down dropdown-arrow"></i>
                            </a>
                            <div class="collapse" id="deliveryMenu">
                                <ul class="nav flex-column ps-4">
                                    <li class="nav-item"><a class="nav-link" href="trip_tickets.php"><i class="bi bi-ticket-perforated"></i><span class="nav-text">Trip Tickets</span></a></li>
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
                        <li class="nav-item"><a class="nav-link" href="drivers.php"><i class="bi bi-people-fill"></i><span class="nav-text">Users</span></a></li>
                        
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

        <!-- Main Content Area -->
        <div class="main-content">
            <div class="navbar-top">
                <button class="mobile-toggle-btn" id="mobileToggleBtn">
                    <i class="bi bi-list"></i>
                </button>
                <div class="page-title">
                    <h2>Order Products</h2>
                    <p>Select products and quantities to create an order</p>
                </div>
                <button class="btn btn-success position-relative" type="button" onclick="viewCart()" title="View Cart">
                    <i class="bi bi-cart3"></i>
                    <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill" id="cartBadge" style="display: none;">
                        <span id="cartItemCount">0</span>
                    </span>
                </button>
            </div>

            <!-- Category Tabs with Search -->
            <div class="category-tabs-container">
                <div class="tabs-header">
                    <div class="tabs-scroll">
                        <div class="category-tabs" id="categoryTabs">
                            <button class="tab-btn active" onclick="filterByCategory('all')">All Products</button>
                            <?php foreach ($categories as $category): ?>
                                <button class="tab-btn" onclick="filterByCategory('<?php echo htmlspecialchars($category); ?>')">
                                    <?php echo htmlspecialchars($category); ?>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="selected-customer-display" id="selectedCustomerDisplay">
                        <i class="bi bi-person-badge"></i>
                        <div>
                            <span class="selected-customer-label">Customer</span>
                            <span class="selected-customer-name" id="selectedCustomerNameDisplay"><?php echo $is_customer_locked && !empty($pre_selected_customer_name) ? $pre_selected_customer_name : 'No customer selected'; ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Products Section -->
            <div class="col-12 products-section">
                <div class="product-action-bar">
                    <div class="search-wrapper">
                        <i class="bi bi-search search-icon"></i>
                        <input type="text" class="search-input" id="searchInput" placeholder="Search products...">
                        <button class="search-reset" id="searchReset" onclick="resetSearch()"><i class="bi bi-x"></i></button>
                    </div>
                    <button class="btn btn-success" id="bulkAddToCartBtn" onclick="bulkAddToCart()">
            <i class="bi bi-cart-plus"></i> Add All to Cart
        </button>
                </div>
                <div class="product-table-container">
                    <table class="product-table" id="productsTable">
                        <thead>
                            <tr>
                                <th>Image</th>
                                <th>Product</th>
                                <th>Unit</th>
                                <th>Qty</th>
                                <th>Price</th>
                            </tr>
                        </thead>
                        <tbody id="productsContainer">
                            <tr class="product-loading-row" id="productLoadingRow">
                                <td colspan="5">
                                    <div class="product-loading-panel">
                                        <div class="product-loading-logo"><i class="bi bi-box-seam"></i></div>
                                        <p class="product-loading-title">Loading products...</p>
                                        <p class="product-loading-subtitle">Preparing product prices, UoM, stock, and images.</p>
                                        <div class="product-skeleton-list">
                                            <div class="product-skeleton-item"><div class="skeleton-block skeleton-img"></div><div><div class="skeleton-block skeleton-line-lg"></div><div class="skeleton-block skeleton-line-sm"></div></div><div class="skeleton-block skeleton-pill"></div></div>
                                            <div class="product-skeleton-item"><div class="skeleton-block skeleton-img"></div><div><div class="skeleton-block skeleton-line-lg"></div><div class="skeleton-block skeleton-line-sm"></div></div><div class="skeleton-block skeleton-pill"></div></div>
                                            <div class="product-skeleton-item"><div class="skeleton-block skeleton-img"></div><div><div class="skeleton-block skeleton-line-lg"></div><div class="skeleton-block skeleton-line-sm"></div></div><div class="skeleton-block skeleton-pill"></div></div>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Product Info Modal -->
    <div class="modal fade" id="productInfoModal" tabindex="-1">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-box-seam me-2"></i><span id="modalProductName">Product Details</span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-0">
                    <div class="product-info-container">
                        <div id="loadingState" class="loading-state">
                            <div class="spinner-border text-success mb-3" role="status">
                                <span class="visually-hidden">Loading...</span>
                            </div>
                            <p>Loading product details...</p>
                        </div>
                        <div id="productContent" style="display: none;">
                            <div class="product-header-section">
                                <img id="modalProductImage" src="" alt="Product Image" class="product-image-large">
                                <div class="product-basic-info">
                                    <div class="info-row">
                                        <span class="info-label">Code:</span>
                                        <span class="info-value" id="modalProductCode">-</span>
                                    </div>
                                    <div class="info-row">
                                        <span class="info-label">Category:</span>
                                        <span class="info-value" id="modalProductCategory">-</span>
                                    </div>
                                    <div class="info-row">
                                        <span class="info-label">Price:</span>
                                        <span class="info-value price-tag" id="modalProductPrice">₱0.00</span>
                                    </div>
                                    <div class="info-row">
                                        <span class="info-label">Stock:</span>
                                        <span class="info-value"><span class="stock-tag" id="modalProductStock">0 pcs</span></span>
                                    </div>
                                </div>
                            </div>
                            <div class="px-3 mb-3">
                                <h6 class="fw-bold text-success">Description</h6>
                                <p class="text-muted" id="modalProductDescription">-</p>
                            </div>
                            <h6 class="fw-bold text-success px-3"><i class="bi bi-clock-history"></i> Order History</h6>
                            <div class="table-responsive px-3 pb-3">
                                <table class="history-table">
                                    <thead>
                                        <tr><th>Date</th><th>Order #</th><th>Customer</th><th>Unit</th><th>Qty</th><th>Status</th></tr>
                                    </thead>
                                    <tbody id="modalOrderHistory">
                                        <tr><td colspan="6" class="text-center">No order history</td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Cart Modal (Review) -->
<div class="modal fade" id="cartModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-cart-check"></i> Review & Confirm Order</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="customer-selection">
                    <h6><i class="bi bi-person-check"></i> Select Customer</h6>
                    <select class="form-select" id="modalCustomerSelect" <?php echo $is_customer_locked ? 'disabled' : ''; ?>>
                        <option value="">-- Choose Customer --</option>
                        <?php foreach ($customers as $customer): ?>
                            <option value="<?php echo $customer['customer_id']; ?>" 
                                    data-email="<?php echo htmlspecialchars($customer['email'] ?? ''); ?>"
                                    data-phone="<?php echo htmlspecialchars($customer['phone_number'] ?? ''); ?>"
                                    data-address="<?php echo htmlspecialchars($customer['address'] ?? ''); ?>"
                                    data-price-level="<?php echo htmlspecialchars($customer['price_level'] ?? 'Standard'); ?>"
                                    <?php echo ($pre_selected_customer_id == $customer['customer_id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($customer['customer_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($is_customer_locked): ?>
                    <input type="hidden" id="lockedCustomerId" value="<?php echo $pre_selected_customer_id; ?>">
                    <input type="hidden" id="lockedCustomerName" value="<?php echo $pre_selected_customer_name; ?>">
                    <input type="hidden" id="lockedCustomerEmail" value="<?php 
                        foreach ($customers as $customer) {
                            if ($customer['customer_id'] == $pre_selected_customer_id) {
                                echo htmlspecialchars($customer['email'] ?? '');
                                break;
                            }
                        }
                    ?>">
                    <input type="hidden" id="lockedCustomerPhone" value="<?php 
                        foreach ($customers as $customer) {
                            if ($customer['customer_id'] == $pre_selected_customer_id) {
                                echo htmlspecialchars($customer['phone_number'] ?? '');
                                break;
                            }
                        }
                    ?>">
                    <input type="hidden" id="lockedCustomerAddress" value="<?php 
                        foreach ($customers as $customer) {
                            if ($customer['customer_id'] == $pre_selected_customer_id) {
                                echo htmlspecialchars($customer['address'] ?? '');
                                break;
                            }
                        }
                    ?>">
                    <input type="hidden" id="lockedPriceLevel" value="<?php 
                        foreach ($customers as $customer) {
                            if ($customer['customer_id'] == $pre_selected_customer_id) {
                                echo htmlspecialchars($customer['price_level'] ?? 'Standard');
                                break;
                            }
                        }
                    ?>">
                    <div class="alert alert-info mt-2 mb-0 py-2">
                        <i class="bi bi-info-circle"></i> Customer is locked. Please go back to Customer List to change customer.
                    </div>
                    <?php endif; ?>
                </div>

                <h6 class="mb-3">Order Items</h6>
                <div id="reviewItems" class="mb-4"></div>

                <h6 class="mb-3">Delivery Information</h6>
                <div class="alert bg-light">
                    <p class="mb-2"><strong>Customer:</strong> <span id="reviewCustomer">-</span></p>
                    <p class="mb-2"><strong>Email:</strong> <span id="reviewEmail">-</span></p>
                    <p class="mb-2"><strong>Phone:</strong> <span id="reviewPhone">-</span></p>
                    <p class="mb-0"><strong>Address:</strong> <span id="reviewAddress">-</span></p>
                </div>
                <div id="reviewOutstandingBalanceCard" class="alert alert-warning mb-3" style="display:none; border-left:4px solid #f59e0b; border-radius:10px;">
                    <div class="d-flex align-items-start gap-2">
                        <i class="bi bi-exclamation-triangle-fill text-warning"></i>
                        <div class="w-100">
                            <div class="fw-bold">Outstanding Balance</div>
                            <div class="small text-muted" id="reviewOutstandingBalanceText">No outstanding balance.</div>
                        </div>
                    </div>
                </div>

                <div id="customerAddressInputGroup" class="mb-3" style="display:none;">
                    <label for="customerAddressInput" class="form-label small fw-semibold mb-1">Customer Address</label>
                    <textarea class="form-control form-control-sm" id="customerAddressInput" rows="2" placeholder="Type customer address here"></textarea>
                    <small class="text-muted">This will be saved to the selected customer's address after submitting the order.</small>
                </div>

                <!-- Delivery Type Selection -->
<div id="deliveryTypeSection">
    <h6 class="mb-3"><i class="bi bi-truck"></i> Delivery Type</h6>
    <div class="alert bg-light mb-3">
        <div class="row">
            <div class="col-md-6">
                <div class="form-check mb-2">
                    <input class="form-check-input" type="radio" name="deliveryType" id="deliveryPickup" value="pickup" checked>
                    <label class="form-check-label" for="deliveryPickup">
                        <i class="bi bi-box-seam"></i> Pick Up
                        <small class="d-block text-muted">Customer will pick up the order</small>
                    </label>
                </div>
            </div>
            <div class="col-md-6">
                <div class="form-check mb-2">
                    <input class="form-check-input" type="radio" name="deliveryType" id="deliveryDeliver" value="delivery">
                    <label class="form-check-label" for="deliveryDeliver">
                        <i class="bi bi-truck"></i> Delivery
                        <small class="d-block text-muted">Order will be delivered to customer</small>
                    </label>
                </div>
            </div>
        </div>
    </div>
</div>

                <div id="deliveryAssignmentSection" class="mb-3" style="display:none;">
                    <h6 class="mb-3"><i class="bi bi-person-badge"></i> Driver & Vehicle Assignment</h6>
                    <div class="alert bg-light mb-3">
                        <div class="row g-2">
                            <div class="col-md-6">
                                <label class="form-label small mb-1">Driver</label>
                                <select class="form-select form-select-sm" id="deliveryDriverSelect">
                                    <option value="">-- Select Driver --</option>
                                    <?php foreach ($delivery_drivers as $driver): ?>
                                        <option value="<?php echo (int)$driver['driver_id']; ?>"
                                                data-vehicle-type="<?php echo htmlspecialchars($driver['vehicle_type'] ?? ''); ?>"
                                                data-plate="<?php echo htmlspecialchars($driver['vehicle_plate_number'] ?? ''); ?>">
                                            <?php echo htmlspecialchars($driver['driver_name']); ?><?php echo !empty($driver['license_number']) ? ' - ' . htmlspecialchars($driver['license_number']) : ''; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small mb-1">Vehicle</label>
                                <select class="form-select form-select-sm" id="deliveryVehicleSelect">
                                    <option value="">-- Select Vehicle --</option>
                                    <?php foreach ($delivery_vehicles as $vehicle): ?>
                                        <option value="<?php echo (int)$vehicle['vehicle_id']; ?>">
                                            <?php echo htmlspecialchars($vehicle['vehicle_type']); ?> - <?php echo htmlspecialchars($vehicle['plate_number']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="row g-2 mt-2">
                            <div class="col-md-6">
                                <button type="button" class="btn btn-sm btn-outline-success w-100" onclick="toggleNewDriverFields()">
                                    <i class="bi bi-person-plus"></i> Add New Driver
                                </button>
                            </div>
                            <div class="col-md-6">
                                <button type="button" class="btn btn-sm btn-outline-success w-100" onclick="toggleNewVehicleFields()">
                                    <i class="bi bi-truck-front"></i> Add New Vehicle
                                </button>
                            </div>
                        </div>

                        <div id="newDriverFields" class="row g-3 mt-2" style="display:none;">
                            <div class="col-md-6">
                                <label class="form-label small mb-1">First Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control form-control-sm" id="newDriverFirstName" placeholder="First name">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small mb-1">Last Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control form-control-sm" id="newDriverLastName" placeholder="Last name">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small mb-1">Email <span class="text-danger">*</span></label>
                                <input type="email" class="form-control form-control-sm" id="newDriverEmail" placeholder="driver@amgc.com">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small mb-1">Password <span class="text-danger">*</span></label>
                                <input type="password" class="form-control form-control-sm" id="newDriverPassword" placeholder="Password">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small mb-1">Contact Number</label>
                                <input type="text" class="form-control form-control-sm" id="newDriverContact" placeholder="Contact number">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small mb-1">License Number <span class="text-danger">*</span></label>
                                <input type="text" class="form-control form-control-sm" id="newDriverLicense" placeholder="License number">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small mb-1">License Expiry</label>
                                <input type="date" class="form-control form-control-sm" id="newDriverLicenseExpiry">
                            </div>
                        </div>

                        <div id="newVehicleFields" class="row g-2 mt-2" style="display:none;">
                            <div class="col-md-6"><label class="form-label small mb-1">Vehicle Type</label><input type="text" class="form-control form-control-sm" id="newVehicleType" placeholder="Truck, Van, L300"></div>
                            <div class="col-md-6"><label class="form-label small mb-1">Plate Number</label><input type="text" class="form-control form-control-sm" id="newVehiclePlate" placeholder="Plate number"></div>
                        </div>
                    </div>
                </div>

                <div id="pickupPaymentSection" class="mb-3">
                    <h6 class="mb-3"><i class="bi bi-cash-stack"></i> Pick Up Payment</h6>
                    <div class="alert bg-light mb-3">
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" id="collectPickupPayment">
                            <label class="form-check-label" for="collectPickupPayment">
                                Collect payment now
                                
                            </label>
                        </div>
                        <div id="pickupPaymentFields" style="display:none;">
                            <div class="row g-2">
                                <div class="col-md-6">
                                    <label class="form-label small mb-1">Payment Method</label>
                                    <select class="form-select form-select-sm" id="pickupPaymentMethod">
                                        <option value="cash">Cash</option>
                                        <option value="check">Check</option>
                                        <option value="online_transfer">Online Transfer</option>
                                    </select>
                                </div>
                                <div class="col-md-6 pickup-cash-field">
                                    <label class="form-label small mb-1">Cash Tendered</label>
                                    <input type="text" inputmode="decimal" class="form-control form-control-sm no-spinner money-input" id="pickupCashTendered" placeholder="Amount received">
                                    <small class="text-muted">Change: <span id="pickupCashChange">₱0.00</span></small>
                                </div>
                            </div>
                            <div class="row g-2 mt-2 pickup-check-fields" style="display:none;">
                                <div class="col-md-6"><label class="form-label small mb-1">Check Date</label><input type="date" class="form-control form-control-sm" id="pickupCheckDate"></div>
                                <div class="col-md-6"><label class="form-label small mb-1">Check Number</label><input type="text" class="form-control form-control-sm" id="pickupCheckNumber"></div>
                                <div class="col-md-6"><label class="form-label small mb-1">Bank Name</label><input type="text" class="form-control form-control-sm" id="pickupBankName"></div>
                                <div class="col-md-6"><label class="form-label small mb-1">Bank Branch</label><input type="text" class="form-control form-control-sm" id="pickupBankBranch"></div>
                            </div>
                            <div class="row g-2 mt-2 pickup-online-fields" style="display:none;">
                                <div class="col-md-6"><label class="form-label small mb-1">Reference Number</label><input type="text" class="form-control form-control-sm" id="pickupReferenceNumber"></div>
                                <div class="col-md-6"><label class="form-label small mb-1">Bank/Wallet</label><input type="text" class="form-control form-control-sm" id="pickupOnlineBankName" placeholder="GCash, Maya, BPI, etc."></div>
                                <div class="col-md-12"><label class="form-label small mb-1">Branch / Account Note</label><input type="text" class="form-control form-control-sm" id="pickupOnlineBankBranch" placeholder="Optional"></div>
                            </div>
                        </div>
                    </div>
                </div>


                <div class="mb-3" id="documentTypeSection">
                    <h6 class="mb-3"><i class="bi bi-receipt"></i> Document Type</h6>
                    <div class="alert bg-light mb-3">
                        <div class="row g-2">
                            <div class="col-md-6">
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="documentType" id="docTypeSO" value="SO" checked onchange="toggleSIDetails()">
                                    <label class="form-check-label" for="docTypeSO">SO <small class="d-block text-muted">Manual SO number, no SI required</small></label>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="documentType" id="docTypeSI" value="SI" onchange="toggleSIDetails()">
                                    <label class="form-check-label" for="docTypeSI">SI <small class="d-block text-muted">SO will auto-generate</small></label>
                                </div>
                            </div>
                        </div>
                        <div class="row g-2 mt-2">
                            <div class="col-md-6">
                                <label class="form-label small mb-1">ATW No. <span class="text-danger">*</span></label>
                                <input type="text" class="form-control form-control-sm" id="atwNo" name="atw_no" placeholder="Enter ATW No." maxlength="6" pattern="[0-9]{1,6}" inputmode="numeric" oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0, 6)" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small mb-1">Gatepass No. <span class="text-danger">*</span></label>
                                <input type="text" class="form-control form-control-sm" id="gatepassNo" name="gatepass_no" placeholder="Enter Gatepass No." maxlength="6" pattern="[0-9]{1,6}" inputmode="numeric" oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0, 6)" required>
                            </div>
                        </div>
                        <div id="siDetailsFields" class="row g-2 mt-2" style="display:none;">
                            <div class="col-md-6"><label class="form-label small mb-1">SI Number <span class="text-danger si-required-marker" style="display:none;">*</span></label><input type="text" class="form-control form-control-sm" id="siNumber" name="si_number" placeholder="Enter SI number"></div>
                            <div class="col-md-6"><label class="form-label small mb-1">Registered Business Name <span class="text-danger si-required-marker" style="display:none;">*</span></label><input type="text" class="form-control form-control-sm" id="registeredBusinessName" name="registered_business_name" placeholder="Business name"></div>
                            <div class="col-md-6"><label class="form-label small mb-1">TIN <span class="text-danger si-required-marker" style="display:none;">*</span></label><input type="text" class="form-control form-control-sm" id="businessTin" name="tin" placeholder="TIN"></div>
                            <div class="col-md-6"><label class="form-label small mb-1">Address <span class="text-danger si-required-marker" style="display:none;">*</span></label><input type="text" class="form-control form-control-sm" id="businessAddress" name="business_address" placeholder="Business address"></div>
                        </div>
                    </div>
                </div>

                <h6 class="mb-3">Order Total</h6>
                <div class="order-review-summary">
                    <div class="summary-line">
                        <span>SUBTOTAL</span>
                        <strong id="reviewSubtotal">₱0.00</strong>
                    </div>
                    <div class="summary-line discount-line" id="reviewDiscountLine" style="display: none;">
                        <span>DISCOUNT <small id="reviewDiscountNote" class="discount-note"></small></span>
                        <strong id="reviewDiscount">-₱0.00</strong>
                    </div>
                    <div class="summary-line grand-total-line">
                        <span>GRAND TOTAL</span>
                        <strong id="reviewTotal" class="text-success">₱0.00</strong>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-success" id="confirmOrderBtn" onclick="submitOrder()">Confirm & Submit</button>
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
                    <i class="bi bi-box"></i><span>Receive Inventory</span>
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
                <button type="button" class="btn btn-danger" id="cancelOrderBtn" style="display: none;" onclick="cancelOrderFromOrderProduct()">Cancel Order</button>
                <button type="button" class="btn btn-primary" id="printOrderFromDetails" style="display: none;" onclick="printOrderFromOrderProduct()">Print Order</button>
            </div>
        </div>
    </div>
</div>

    <!-- JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
   <script>
    // Inventory data from PHP (reads item_unit_inventory, same as Sales orderproduct)
    const inventory = <?php echo $inventory_json; ?>;
    
    const productUnitTypes = {};
    const productImages_data = {};
    const productUnitConversions = <?php echo json_encode($all_items_unit_types); ?>;
    
    let cart = [];
    let activeUnitTypes = {};
    let toastTimeout = null;
    let currentFilter = 'all';
    let searchTerm = '';
    let customerDiscount = {
        percent: 0,
        type: 'percentage',
        basedAmount: 0,
        calculatedAmount: 0
    };
    let customerCreditSnapshot = {
        hasCreditLimit: false,
        creditLimit: 0,
        outstandingBalance: 0,
        orderAmount: 0,
        requiresOutstandingApproval: false
    };
    
    // ============= CURRENCY FORMATTING FUNCTIONS =============
    
    // Format number to currency with comma separators (for display)
    function formatCurrency(amount) {
        if (amount === null || amount === undefined || isNaN(amount)) return '₱0.00';
        return '₱' + parseFloat(amount).toLocaleString('en-PH', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }
    
    // Format number with commas only (no peso sign)

    function getCartSubtotal() {
        return cart.reduce((s, i) => s + (parseFloat(i.price) || 0) * (parseFloat(i.quantity) || 0), 0);
    }

    function getCartTotal() {
        return computeCartDiscount(getCartSubtotal()).total;
    }

    function computeCartDiscount(subtotal = getCartSubtotal()) {
        const type = customerDiscount.type || 'percentage';
        let amount = 0;
        let note = '';
        if (type === 'amount_based') {
            amount = Math.max(0, Math.min(subtotal, parseFloat(customerDiscount.basedAmount || customerDiscount.calculatedAmount || 0)));
            note = 'Amount-based';
        } else {
            const percent = Math.max(0, Math.min(100, parseFloat(customerDiscount.percent || 0)));
            amount = subtotal * (percent / 100);
            note = percent > 0 ? `${percent.toFixed(2).replace(/\.00$/, '')}%` : '';
        }
        return { amount, note, total: Math.max(0, subtotal - amount), type };
    }

    function updateReviewTotals() {
        const subtotal = getCartSubtotal();
        const discount = computeCartDiscount(subtotal);
        const subtotalEl = document.getElementById('reviewSubtotal');
        const discountLine = document.getElementById('reviewDiscountLine');
        const discountEl = document.getElementById('reviewDiscount');
        const discountNoteEl = document.getElementById('reviewDiscountNote');
        const totalEl = document.getElementById('reviewTotal');
        if (subtotalEl) subtotalEl.textContent = formatCurrency(subtotal);
        if (totalEl) totalEl.textContent = formatCurrency(discount.total);
        if (discountLine && discountEl) {
            if (discount.amount > 0) {
                discountLine.style.display = '';
                discountEl.textContent = '-' + formatCurrency(discount.amount);
                if (discountNoteEl) discountNoteEl.textContent = discount.note;
            } else {
                discountLine.style.display = 'none';
                discountEl.textContent = '-₱0.00';
                if (discountNoteEl) discountNoteEl.textContent = '';
            }
        }
        const selectedCustomerIdForOutstanding = getSelectedCustomerIdForReview();
        if (selectedCustomerIdForOutstanding) {
            window.clearTimeout(window.orderProductOutstandingTimer);
            window.orderProductOutstandingTimer = window.setTimeout(() => loadCustomerOutstandingSnapshot(selectedCustomerIdForOutstanding), 250);
        } else {
            customerCreditSnapshot = { hasCreditLimit: false, creditLimit: 0, outstandingBalance: 0, orderAmount: 0, requiresOutstandingApproval: false };
            updateOutstandingBalanceDisplay();
        }
    }


    function getSelectedCustomerIdForReview() {
        const select = document.getElementById('modalCustomerSelect');
        const lockedCustomerId = document.getElementById('lockedCustomerId')?.value || '';
        return select?.value ? parseInt(select.value) : parseInt(lockedCustomerId || 0);
    }

    function updateOutstandingBalanceDisplay() {
        const card = document.getElementById('reviewOutstandingBalanceCard');
        const textEl = document.getElementById('reviewOutstandingBalanceText');
        if (!card || !textEl) return;

        const outstanding = parseFloat(customerCreditSnapshot.outstandingBalance || 0) || 0;
        const hasLimit = !!customerCreditSnapshot.hasCreditLimit;
        if (outstanding > 0) {
            card.style.display = '';
            if (hasLimit) {
                textEl.innerHTML = `Current outstanding balance: <strong>${formatCurrency(outstanding)}</strong>. Credit limit: <strong>${formatCurrency(customerCreditSnapshot.creditLimit || 0)}</strong>.`;
            } else {
                textEl.innerHTML = `Current outstanding balance: <strong>${formatCurrency(outstanding)}</strong>. This customer has <strong>no credit limit</strong>, so approval will be required when confirming this order.`;
            }
        } else {
            card.style.display = 'none';
            textEl.textContent = 'No outstanding balance.';
        }
    }

    function loadCustomerOutstandingSnapshot(customerId) {
        if (!customerId) {
            customerCreditSnapshot = { hasCreditLimit: false, creditLimit: 0, outstandingBalance: 0, orderAmount: 0, requiresOutstandingApproval: false };
            updateOutstandingBalanceDisplay();
            return Promise.resolve();
        }
        const subtotal = getCartSubtotal();
        const discount = computeCartDiscount(subtotal);
        const formData = new FormData();
        formData.append('action', 'get_customer_outstanding_snapshot');
        formData.append('customer_id', customerId);
        formData.append('order_amount', discount.total || 0);

        return fetch(window.location.href, { method: 'POST', body: formData })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    customerCreditSnapshot = {
                        hasCreditLimit: !!data.has_credit_limit,
                        creditLimit: parseFloat(data.credit_limit || 0),
                        outstandingBalance: parseFloat(data.outstanding_balance || 0),
                        orderAmount: parseFloat(data.order_amount || 0),
                        requiresOutstandingApproval: !!data.requires_outstanding_approval
                    };
                } else {
                    customerCreditSnapshot = { hasCreditLimit: false, creditLimit: 0, outstandingBalance: 0, orderAmount: 0, requiresOutstandingApproval: false };
                }
                updateOutstandingBalanceDisplay();
            })
            .catch(() => {
                customerCreditSnapshot = { hasCreditLimit: false, creditLimit: 0, outstandingBalance: 0, orderAmount: 0, requiresOutstandingApproval: false };
                updateOutstandingBalanceDisplay();
            });
    }

    function formatNumberWithCommas(amount) {
        if (amount === null || amount === undefined || isNaN(amount)) return '0.00';
        return parseFloat(amount).toLocaleString('en-PH', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }
    
    // Format stock display with commas
    
        function getUomInitial(unitType) {
            if (!unitType) return '';
            const initial = (unitType.uom_initial || '').toString().trim();
            if (initial) return initial.toUpperCase();
            const name = (unitType.unit_type_name || '').toString().trim();
            return name ? name.substring(0, 2).toUpperCase() : '';
        }

        function getUomDisplayName(unitType) {
            if (!unitType) return '';
            const name = (unitType.unit_type_name || '').toString().trim();
            const initial = (unitType.uom_initial || '').toString().trim().toUpperCase();
            return initial && initial !== name.toUpperCase() ? `${name} (${initial})` : name;
        }
function formatStockDisplay(stockValue, unitType) {
        const rounded = Math.floor(stockValue * 100) / 100;
        const formattedStock = rounded.toLocaleString('en-PH', {
            minimumFractionDigits: 0,
            maximumFractionDigits: 2
        });
        if (rounded < 0) return `<span class="stock-warning">${formattedStock} ${unitType}</span>`;
        return `${formattedStock} ${unitType}`;
    }
    
    // Helper functions
    function showToast(msg) {
        if (toastTimeout) clearTimeout(toastTimeout);
        const existing = document.querySelector('.toast-notification');
        if (existing) existing.remove();
        const toast = document.createElement('div');
        toast.className = 'toast-notification';
        toast.innerHTML = `<i class="bi bi-check-circle-fill me-2"></i>${msg}`;
        document.body.appendChild(toast);
        toastTimeout = setTimeout(() => {
            toast.classList.add('fade-out');
            setTimeout(() => toast.remove(), 300);
        }, 2000);
    }
    
    function normalizeUnitName(unitType) {
        return String(unitType || '').trim().toLowerCase();
    }

    function getUnitConversion(productId, unitType) {
        const wanted = normalizeUnitName(unitType);
        const conversions = productUnitConversions[productId] || {};

        for (const [unitName, multiplier] of Object.entries(conversions)) {
            if (normalizeUnitName(unitName) === wanted) {
                return parseFloat(multiplier) || 1;
            }
        }

        const defaults = { 'piece': 1, 'case': 12, 'inner-pack': 6, 'box': 24, 'carton': 48 };
        return defaults[wanted] || 1;
    }

    function getProductUnitStock(product, unitType) {
        if (!product) return null;
        const wanted = normalizeUnitName(unitType);
        const unitStocks = product.unit_stocks || {};

        for (const [stockUnit, stockQty] of Object.entries(unitStocks)) {
            if (normalizeUnitName(stockUnit) === wanted) {
                return Number(stockQty) || 0;
            }
        }

        return null;
    }

    function getCartQuantityForUnit(productId, unitType) {
        const wanted = normalizeUnitName(unitType);
        return cart
            .filter(i => Number(i.id) === Number(productId) && normalizeUnitName(i.unit_type) === wanted)
            .reduce((total, item) => total + (Number(item.quantity) || 0), 0);
    }
    
    function getAvailableStock(productId, unitType = null) {
        const p = inventory.find(p => Number(p.id) === Number(productId));
        if (!p) return 0;

        const selectedUnit = unitType || activeUnitTypes[productId] || p.default_unit_type_name || p.unit_type || 'piece';
        const exactUnitStock = getProductUnitStock(p, selectedUnit);

        // item_unit_inventory keeps stock per UoM. Display the selected UoM's own stock,
        // then subtract only quantities already added to cart with the same UoM.
        if (exactUnitStock !== null) {
            return exactUnitStock - getCartQuantityForUnit(productId, selectedUnit);
        }

        // Fallback for older records without item_unit_inventory rows.
        const baseStock = Number(p.stock_smallest ?? p.stock ?? 0);
        const inCartSmallest = cart
            .filter(i => Number(i.id) === Number(productId))
            .reduce((t, i) => t + ((Number(i.quantity) || 0) * getUnitConversion(i.id, i.unit_type)), 0);
        return (baseStock - inCartSmallest) / getUnitConversion(productId, selectedUnit);
    }

    function getUnitStockForOrder(productId, unitType) {
        return getAvailableStock(productId, unitType);
    }

    function validatePickupStockBeforeSubmit() {
        const grouped = {};

        cart.forEach(item => {
            const key = `${item.id}__${String(item.unit_type || '').trim().toLowerCase()}`;
            if (!grouped[key]) {
                grouped[key] = {
                    id: item.id,
                    name: item.name,
                    unit_type: item.unit_type,
                    quantity: 0
                };
            }
            grouped[key].quantity += Number(item.quantity) || 0;
        });

        for (const item of Object.values(grouped)) {
            const available = getUnitStockForOrder(item.id, item.unit_type);
            if (available <= 0) {
                showToast(`Item "${item.name}" has 0 stock for ${item.unit_type}. Pickup order cannot continue.`);
                return false;
            }
            if (available < item.quantity) {
                showToast(`Item "${item.name}" stock is not enough. Available: ${available}, Ordered: ${item.quantity}`);
                return false;
            }
        }

        return true;
    }
    
    function getProductById(id) { 
        return inventory.find(p => p.id === id); 
    }
    
    function updateCartBadge() {
        const badge = document.getElementById('cartBadge');
        const countSpan = document.getElementById('cartItemCount');
        const total = cart.reduce((s, i) => s + i.quantity, 0);
        if (badge && countSpan) {
            if (total > 0) { 
                countSpan.textContent = total; 
                badge.style.display = 'inline-block'; 
            } else { 
                badge.style.display = 'none'; 
                countSpan.textContent = '0'; 
            }
        }
        const subtotal = cart.reduce((s, i) => s + i.price * i.quantity, 0);
        const totalQty = cart.reduce((s, i) => s + i.quantity, 0);
        
        const subtotalEl = document.getElementById('cartModalSubtotal');
        const totalItemsEl = document.getElementById('cartModalTotalItems');
        const totalPriceEl = document.getElementById('cartModalTotalPrice');
        
        if (subtotalEl) subtotalEl.textContent = formatCurrency(subtotal);
        if (totalItemsEl) totalItemsEl.textContent = totalQty;
        if (totalPriceEl) totalPriceEl.textContent = formatCurrency(computeCartDiscount(subtotal).total);
        updateReviewTotals();
    }
    
    function loadProductUnitTypes(productId, priceLevel = 'Standard') {
        return new Promise((resolve) => {
            const formData = new FormData();
            formData.append('action', 'get_product_unit_types');
            formData.append('product_id', productId);
            formData.append('price_level', priceLevel);
            fetch(window.location.href, { method: 'POST', body: formData })
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.unit_types && data.unit_types.length > 0) {
                        const uniqueUnitTypes = [];
                        const seenUnitTypes = new Set();
                        data.unit_types.forEach(ut => {
                            const unitName = (ut.unit_type_name || '').trim();
                            if (!unitName || seenUnitTypes.has(unitName)) return;
                            seenUnitTypes.add(unitName);
                            uniqueUnitTypes.push(ut);
                        });
                        productUnitTypes[productId] = uniqueUnitTypes;
                        const smallestUnit = uniqueUnitTypes.find(ut => parseInt(ut.quantity_smallest_pack) === 1) || uniqueUnitTypes[0];
                        activeUnitTypes[productId] = smallestUnit.unit_type_name;
                        productUnitConversions[productId] = {};
                        uniqueUnitTypes.forEach(ut => {
                            productUnitConversions[productId][ut.unit_type_name] = parseInt(ut.quantity_smallest_pack) || 1;
                        });
                    }
                    resolve();
                })
                .catch(() => resolve());
        });
    }
    
    function loadAllProductUnitTypes() {
        const promises = inventory.map(product => loadProductUnitTypes(product.id, 'Standard'));
        return Promise.all(promises);
    }
    
    function loadProductImages(productId) {
        return new Promise((resolve) => {
            const formData = new FormData();
            formData.append('action', 'get_product_details');
            formData.append('product_id', productId);
            formData.append('price_level', 'Standard');
            fetch(window.location.href, { method: 'POST', body: formData })
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.images && data.images.length > 0) {
                        productImages_data[productId] = data.images;
                    }
                    resolve();
                })
                .catch(() => resolve());
        });
    }
    
    function loadAllProductImages() {
        const promises = inventory.map(product => loadProductImages(product.id));
        return Promise.all(promises);
    }
    
    function getSelectedPriceLevel() {
        const customerSelect = document.getElementById('modalCustomerSelect');
        if (!customerSelect) return 'Standard';
        const selectedOption = customerSelect.options[customerSelect.selectedIndex];
        return selectedOption ? selectedOption.dataset.priceLevel || 'Standard' : 'Standard';
    }

    function updateSelectedCustomerDisplay(name) {
        const display = document.getElementById('selectedCustomerNameDisplay');
        if (!display) return;
        const cleanName = (name || '').trim();
        display.textContent = cleanName || 'No customer selected';
    }
    

    function getProductsLoadingHtml(message = 'Loading products...', subtitle = 'Preparing product prices, UoM, stock, and images.') {
        return `
            <tr class="product-loading-row" id="productLoadingRow">
                <td colspan="5">
                    <div class="product-loading-panel">
                        <div class="product-loading-logo"><i class="bi bi-box-seam"></i></div>
                        <p class="product-loading-title">${escapeHtml(message)}</p>
                        <p class="product-loading-subtitle">${escapeHtml(subtitle)}</p>
                        <div class="product-skeleton-list">
                            <div class="product-skeleton-item"><div class="skeleton-block skeleton-img"></div><div><div class="skeleton-block skeleton-line-lg"></div><div class="skeleton-block skeleton-line-sm"></div></div><div class="skeleton-block skeleton-pill"></div></div>
                            <div class="product-skeleton-item"><div class="skeleton-block skeleton-img"></div><div><div class="skeleton-block skeleton-line-lg"></div><div class="skeleton-block skeleton-line-sm"></div></div><div class="skeleton-block skeleton-pill"></div></div>
                            <div class="product-skeleton-item"><div class="skeleton-block skeleton-img"></div><div><div class="skeleton-block skeleton-line-lg"></div><div class="skeleton-block skeleton-line-sm"></div></div><div class="skeleton-block skeleton-pill"></div></div>
                        </div>
                    </div>
                </td>
            </tr>`;
    }

    function showProductsLoading(message = 'Loading products...', subtitle = 'Preparing product prices, UoM, stock, and images.') {
        const table = document.getElementById('productsTable');
        const container = document.getElementById('productsContainer');
        if (table) table.classList.add('loading-products');
        if (container) container.innerHTML = getProductsLoadingHtml(message, subtitle);
    }

    function hideProductsLoading() {
        const table = document.getElementById('productsTable');
        if (table) table.classList.remove('loading-products');
    }

    function reloadProductPrices(priceLevel) {
        showProductsLoading('Updating products...', 'Applying customer price level and approved discount.');
        const promises = inventory.map(product => loadProductUnitTypes(product.id, priceLevel));
        return Promise.all(promises).then(() => { hideProductsLoading(); renderProducts(); });
    }
    
    function setupSearch() {
        const searchInput = document.getElementById('searchInput');
        const resetBtn = document.getElementById('searchReset');
        searchInput.addEventListener('input', function() {
            searchTerm = this.value.toLowerCase();
            resetBtn.classList.toggle('visible', searchTerm.length > 0);
            filterProducts();
        });
    }
    
    function resetSearch() {
        const searchInput = document.getElementById('searchInput');
        const resetBtn = document.getElementById('searchReset');
        searchInput.value = '';
        searchTerm = '';
        resetBtn.classList.remove('visible');
        filterProducts();
    }
    
    function filterByCategory(category) {
        currentFilter = category;
        document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
        event.target.classList.add('active');
        filterProducts();
    }
    
    function filterProducts() {
    const container = document.getElementById('productsContainer');
    if (!container) return;
    hideProductsLoading();
    
    const filtered = inventory.filter(product => {
        if (currentFilter !== 'all' && product.category !== currentFilter) {
            return false;
        }
        if (searchTerm) {
            return product.name.toLowerCase().includes(searchTerm) || 
                product.sku.toLowerCase().includes(searchTerm);
        }
        return true;
    });
    
    if (filtered.length === 0) {
        // HIDE the table header when no products found
        const table = document.getElementById('productsTable');
        if (table) {
            table.classList.add('no-results-mode');
        }
        
        container.innerHTML = `
            <tr>
                <td colspan="6" class="text-center p-4">
                    <i class="bi bi-search fs-1 d-block mb-2" style="color: #ccc;"></i>
                    <p class="text-muted">No products found matching your criteria</p>
                </td>
            </tr>
        `;
        return;
    }
    
    // SHOW header again when there are results
    const table = document.getElementById('productsTable');
    if (table) {
        table.classList.remove('no-results-mode');
    }
    
    renderFilteredProducts(filtered);
}
    
    function renderFilteredProducts(filteredInventory) {
        const container = document.getElementById('productsContainer');
        let html = '';
        filteredInventory.forEach(p => {
            const unitTypes = productUnitTypes[p.id] || [];
            
            if (!activeUnitTypes[p.id]) {
                if (unitTypes.length > 0) activeUnitTypes[p.id] = unitTypes[0].unit_type_name;
                else activeUnitTypes[p.id] = p.default_unit_type_name || p.unit_type || 'piece';
            }

            const activeUnit = activeUnitTypes[p.id] || p.default_unit_type_name || p.unit_type || 'piece';
            const convertedStock = getAvailableStock(p.id, activeUnit);
            
            const placeholder = 'data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%2240%22 height=%2240%22%3E%3Crect fill=%22%23e0e0e0%22 width=%2240%22 height=%2240%22/%3E%3Ctext x=%2250%25%22 y=%2250%25%22 text-anchor=%22middle%22 dy=%22.3em%22 fill=%22%23999%22 font-size=%229%22%3ENo%3C/text%3E%3C/svg%3E';
            let img = placeholder;
            const productImages = productImages_data[p.id] || [];
            if (productImages.length > 0) {
                const primaryImage = productImages.find(img => img.is_primary) || productImages[0];
                img = '../uploads/products/' + primaryImage.image_path;
            } else if (p.image) { img = '../uploads/products/' + p.image; }
            
            let currPrice = p.unit_price, currUnit = 'piece';
            const currType = activeUnitTypes[p.id];
            if (unitTypes.length > 0) {
                const currentUT = unitTypes.find(ut => ut.unit_type_name === currType);
                if (currentUT) { currPrice = parseFloat(currentUT.unit_price); currUnit = currentUT.unit_type_name; }
            }
            
            let unitButtonsHtml = '';
            let unitDropdownOptions = '';
            if (unitTypes.length > 0) {
                unitTypes.forEach(ut => {
                    const shortLabel = getUomInitial(ut);
                    const isActive = activeUnitTypes[p.id] === ut.unit_type_name ? 'active' : '';
                    unitButtonsHtml += `<button class="unit-btn ${isActive}" data-product-id="${p.id}" data-unit-type="${ut.unit_type_name}" onclick="event.stopPropagation(); setActiveUnit(${p.id}, '${ut.unit_type_name}')">${shortLabel}</button>`;
                    unitDropdownOptions += `<option value="${ut.unit_type_name}" ${isActive ? 'selected' : ''}>${getUomDisplayName(ut)}</option>`;
                });
            } else {
                const opts = [
                    { type: 'piece', label: 'PC', fullLabel: 'Piece', avail: true },
                    { type: 'inner-pack', label: 'IP', fullLabel: 'Inner Pack', avail: p.price_inner_pack !== null },
                    { type: 'case', label: 'CS', fullLabel: 'Case', avail: p.price_case !== null },
                    { type: 'box', label: 'BX', fullLabel: 'Box', avail: p.price_box !== null },
                    { type: 'carton', label: 'CTN', fullLabel: 'Carton', avail: p.price_carton !== null }
                ];
                opts.forEach(o => {
                    if (o.avail) {
                        unitButtonsHtml += `<button class="unit-btn ${activeUnitTypes[p.id] === o.type ? 'active' : ''}" data-product-id="${p.id}" data-unit-type="${o.type}" onclick="event.stopPropagation(); setActiveUnit(${p.id}, '${o.type}')">${o.label}</button>`;
                        unitDropdownOptions += `<option value="${o.type}" ${activeUnitTypes[p.id] === o.type ? 'selected' : ''}>${o.fullLabel} (${o.label})</option>`;
                    }
                });
            }
            
            const stockDisplay = formatStockDisplay(convertedStock, activeUnit);
            html += `<tr id="row-${p.id}" onclick="showProductInfo(${p.id})" style="cursor: pointer;">
                <td class="product-image-cell"><img src="${img}" class="product-thumbnail" onerror="this.src='${placeholder}'"></td>
                <td class="product-cell"><div class="product-info"><span class="product-name">${p.name}</span><span id="stock-${p.id}" class="${convertedStock < 0 ? 'stock-warning' : 'stock-info'}">Stock: ${stockDisplay}</span>
                <div class="mobile-price-display" onclick="event.stopPropagation();"><span class="mobile-price-label">Price:</span><div class="input-group input-group-sm" style="width: auto;"><span class="input-group-text" style="padding: 2px 6px;">₱</span><input type="number" class="form-control mobile-price-input" id="mobile-price-input-${p.id}" value="${currPrice.toFixed(2)}" min="0" step="0.01" style="width: 90px; text-align: right;" onclick="event.stopPropagation();"></div><span class="mobile-price-unit" id="mobile-unit-${p.id}">/${currUnit}</span></div></div></td>
                <td class="unit-column"><div class="unit-buttons desktop-only">${unitButtonsHtml}</div>
                <div class="mobile-unit-qty-container mobile-only"><select class="unit-dropdown" id="unit-dropdown-${p.id}" onchange="event.stopPropagation(); setActiveUnitFromDropdown(${p.id}, this.value)" onclick="event.stopPropagation()">${unitDropdownOptions}</select>
                <div class="quantity-controls"><button class="qty-btn" onclick="event.stopPropagation(); decQty(${p.id})"><i class="bi bi-dash"></i></button><input type="number" class="qty-input" id="qty-${p.id}" min="0" value="0" onchange="validateQuantity(${p.id})" onclick="event.stopPropagation()"><button class="qty-btn" onclick="event.stopPropagation(); incQty(${p.id})"><i class="bi bi-plus"></i></button></div></div></td>
                <td class="qty-column">
                    <div class="quantity-controls desktop-only">
                        <input type="number" class="qty-input" id="qty-desktop-${p.id}" min="0" value="0" placeholder="0" onchange="validateQuantityDesktop(${p.id})" onclick="event.stopPropagation(); clearZeroOnFocus(this)" onfocus="clearZeroOnFocus(this)" onblur="restoreZeroIfEmpty(this)">
                    </div>
                </td>
                <td class="price-cell desktop-price-cell" id="price-display-${p.id}" onclick="event.stopPropagation()">
                    <div class="input-group input-group-sm" style="width: 130px;">
                        <span class="input-group-text">₱</span>
                        <input type="number" class="form-control price-input" id="price-${p.id}" value="${currPrice.toFixed(2)}" min="0" step="0.01" onclick="event.stopPropagation()">
                    </div>
                    <small class="d-block text-muted" style="font-size: 0.75rem; color: #2E7D32 !important;">/${currUnit}</small>
                </td>
              </tr>`;
        });
        container.innerHTML = html;
    }
    
    function clearZeroOnFocus(input) {
        if (input.value === '0') {
            input.value = '';
        }
    }
    
    function restoreZeroIfEmpty(input) {
        if (input.value === '' || input.value === null) {
            input.value = '0';
        }
    }
    
    function renderProducts() { 
        filterProducts(); 
    }
    
    function setActiveUnit(pid, type) {
        activeUnitTypes[pid] = type;
        const qtyInput = document.getElementById(`qty-${pid}`);
        if (qtyInput) qtyInput.value = 0;
        const desktopQtyInput = document.getElementById(`qty-desktop-${pid}`);
        if (desktopQtyInput) desktopQtyInput.value = 0;
        const convertedStock = getAvailableStock(pid, type);
        const stockEl = document.getElementById(`stock-${pid}`);
        if (stockEl) { 
            stockEl.innerHTML = `Stock: ${formatStockDisplay(convertedStock, type)}`; 
            stockEl.className = convertedStock < 0 ? 'stock-warning' : 'stock-info'; 
        }
        const product = getProductById(pid);
        const unitTypes = productUnitTypes[pid] || [];
        let currPrice = product.unit_price;
        if (unitTypes.length > 0) { 
            const currentUT = unitTypes.find(ut => ut.unit_type_name === type); 
            if (currentUT) currPrice = parseFloat(currentUT.unit_price); 
        } else { 
            if (type === 'case' && product.price_case) currPrice = product.price_case; 
            else if (type === 'inner-pack' && product.price_inner_pack) currPrice = product.price_inner_pack; 
            else if (type === 'box' && product.price_box) currPrice = product.price_box; 
            else if (type === 'carton' && product.price_carton) currPrice = product.price_carton; 
        }
        
        const priceInput = document.getElementById(`price-${pid}`);
        if (priceInput) priceInput.value = currPrice.toFixed(2);
        const mobilePriceInput = document.getElementById(`mobile-price-input-${pid}`);
        if (mobilePriceInput) mobilePriceInput.value = currPrice.toFixed(2);
        
        const priceCell = document.getElementById(`price-display-${pid}`);
        if (priceCell) {
            const unitLabel = priceCell.querySelector('small');
            if (unitLabel) unitLabel.textContent = `/${type}`;
        }
        
        const mobileUnitSpan = document.getElementById(`mobile-unit-${pid}`);
        if (mobileUnitSpan) mobileUnitSpan.textContent = `/${type}`;
        
        document.querySelectorAll(`[data-product-id="${pid}"]`).forEach(btn => { 
            btn.classList.remove('active'); 
            if (btn.getAttribute('data-unit-type') === type) btn.classList.add('active'); 
        });
    }
    
    function validateQuantity(pid) { 
        const inp = document.getElementById(`qty-${pid}`); 
        if (!inp) return 0; 
        let v = parseInt(inp.value) || 0; 
        if (v < 0) v = 0; 
        inp.value = v; 
        return v; 
    }
    
    function validateQuantityDesktop(pid) {
        const desktopInp = document.getElementById(`qty-desktop-${pid}`);
        if (!desktopInp) return 0;
        let v = parseInt(desktopInp.value) || 0;
        if (v < 0) v = 0;
        desktopInp.value = v;
        const mobileInp = document.getElementById(`qty-${pid}`);
        if (mobileInp) mobileInp.value = v;
        return v;
    }
    
    function bulkAddToCart() {
        let itemsAdded = 0;
        const allProducts = document.querySelectorAll('#productsContainer tr');
        allProducts.forEach(row => {
            const rowId = row.id;
            if (!rowId) return;
            const pid = parseInt(rowId.replace('row-', ''));
            const p = getProductById(pid);
            if (!p) return;
            const type = activeUnitTypes[pid] || 'piece';
            const qtyInput = document.getElementById(`qty-${pid}`);
            const desktopQtyInput = document.getElementById(`qty-desktop-${pid}`);
            const qty = parseInt((qtyInput?.value && qtyInput.value !== '0') ? qtyInput.value : desktopQtyInput?.value) || 0;
            if (qty > 0) {
                const price = parseFloat(document.getElementById(`price-${pid}`)?.value || document.getElementById(`mobile-price-input-${pid}`)?.value) || p.unit_price;
                const existing = cart.find(i => Number(i.id) === Number(pid) && normalizeUnitName(i.unit_type) === normalizeUnitName(type));
                if (existing) { existing.quantity += qty; existing.price = price; }
                else { cart.push({ id: pid, name: p.name, price, quantity: qty, sku: p.sku, unit_type: type }); }
                if (qtyInput) qtyInput.value = '0';
                if (desktopQtyInput) desktopQtyInput.value = '0';
                itemsAdded++;
            }
        });
        if (itemsAdded === 0) { showToast('Please enter quantity for at least one item'); return; }
        updateCartBadge();
        renderProducts();
        showToast(`Added ${itemsAdded} product(s) to cart!`);
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
    
    // Check if customer is walk-in and hide delivery options
    function checkIfWalkinCustomer() {
        const select = document.getElementById('modalCustomerSelect');
        const deliveryTypeSection = document.getElementById('deliveryTypeSection');
        
        if (!select || !deliveryTypeSection) return;
        
        const isLocked = <?php echo $is_customer_locked ? 'true' : 'false'; ?>;
        let isWalkin = false;
        
        if (isLocked) {
            // Check locked customer
            const lockedCustomerName = document.getElementById('lockedCustomerName')?.value || '';
            isWalkin = (lockedCustomerName.toLowerCase() === 'walk-in customer');
        } else {
            const selectedOption = select.options[select.selectedIndex];
            if (selectedOption && selectedOption.value) {
                const customerName = selectedOption.text.split('(')[0].trim().toLowerCase();
                isWalkin = (customerName === 'walk-in customer');
            }
        }
        
        if (isWalkin) {
            // For walk-in customer, hide delivery section and force pickup
            deliveryTypeSection.style.display = 'none';
            const pickupRadio = document.getElementById('deliveryPickup');
            if (pickupRadio) pickupRadio.checked = true;
            const deliveryAddressGroup = document.getElementById('deliveryAddressGroup');
            if (deliveryAddressGroup) deliveryAddressGroup.style.display = 'none';
        updateDeliveryAssignmentVisibility();
        } else {
            // For regular customers, show delivery section
            deliveryTypeSection.style.display = 'block';
        }
        updatePickupPaymentVisibility();
    }
    
    function normalizeAddressValue(value) {
        const address = (value || '').trim();
        if (!address || address === '-' || address.toLowerCase() === 'no address available') return '';
        return address;
    }

    function getSelectedCustomerAddress() {
        const select = document.getElementById('modalCustomerSelect');
        if (select) {
            const selectedOption = select.options[select.selectedIndex];
            if (selectedOption && selectedOption.value) {
                return normalizeAddressValue(selectedOption.dataset.address || '');
            }
        }
        return normalizeAddressValue(document.getElementById('lockedCustomerAddress')?.value || '');
    }

    function getTypedCustomerAddress() {
        const deliveryInput = document.getElementById('deliveryAddressInput');
        const customerInput = document.getElementById('customerAddressInput');
        return normalizeAddressValue(deliveryInput?.value || customerInput?.value || '');
    }

    function setCustomerAddressEverywhere(address) {
        const cleanAddress = normalizeAddressValue(address);
        const select = document.getElementById('modalCustomerSelect');
        if (select) {
            const selectedOption = select.options[select.selectedIndex];
            if (selectedOption && selectedOption.value && cleanAddress) {
                selectedOption.dataset.address = cleanAddress;
            }
        }
        const lockedAddress = document.getElementById('lockedCustomerAddress');
        if (lockedAddress && cleanAddress) lockedAddress.value = cleanAddress;
        document.getElementById('reviewAddress').textContent = cleanAddress || '-';
        updateDeliveryAddressDisplay();
    }

    function updateCustomerAddressInputVisibility() {
        const group = document.getElementById('customerAddressInputGroup');
        const input = document.getElementById('customerAddressInput');
        const deliveryInput = document.getElementById('deliveryAddressInput');
        const currentAddress = getSelectedCustomerAddress();
        const hasCustomer = !!(document.getElementById('modalCustomerSelect')?.value || document.getElementById('lockedCustomerId')?.value);

        if (group) {
            group.style.display = hasCustomer && !currentAddress ? 'block' : 'none';
        }
        if (input) {
            input.required = hasCustomer && !currentAddress;
            if (currentAddress) input.value = '';
        }
        if (deliveryInput) {
            deliveryInput.style.display = hasCustomer && !currentAddress ? 'block' : 'none';
            deliveryInput.required = false;
            if (currentAddress) deliveryInput.value = '';
        }
    }

    function updateDeliveryAddressDisplay() {
        const deliveryAddressDisplay = document.getElementById('deliveryAddressDisplay');
        if (!deliveryAddressDisplay) return;

        const select = document.getElementById('modalCustomerSelect');
        let customerName = '';
        if (select) {
            const selectedOption = select.options[select.selectedIndex];
            if (selectedOption && selectedOption.value) {
                customerName = selectedOption.text.split('(')[0].trim();
            }
        }
        if (!customerName) customerName = document.getElementById('lockedCustomerName')?.value || '';

        const savedAddress = getSelectedCustomerAddress();
        const typedAddress = getTypedCustomerAddress();
        const address = savedAddress || typedAddress;

        if (address) {
            deliveryAddressDisplay.innerHTML = `${customerName ? '<strong>' + escapeHtml(customerName) + '</strong><br>' : ''}${escapeHtml(address)}`;
        } else {
            deliveryAddressDisplay.innerHTML = '<span class="text-warning">No address on file. Please type the customer address below.</span>';
        }
    }

    // Setup delivery type listeners
    function setupDeliveryTypeListeners() {
        const pickupRadio = document.getElementById('deliveryPickup');
        const deliverRadio = document.getElementById('deliveryDeliver');
        const deliveryAddressGroup = document.getElementById('deliveryAddressGroup');
        
        if (pickupRadio) {
            pickupRadio.addEventListener('change', function() {
                if (this.checked) {
                    if (deliveryAddressGroup) deliveryAddressGroup.style.display = 'none';
                    updatePickupPaymentVisibility();
                    updateDeliveryAssignmentVisibility();
                }
            });
        }
        if (deliverRadio) {
            deliverRadio.addEventListener('change', function() {
                if (this.checked) {
                    const collectPickupPayment = document.getElementById('collectPickupPayment');
                    if (collectPickupPayment) collectPickupPayment.checked = false;
                    updatePickupPaymentVisibility();
                    updateDeliveryAssignmentVisibility();
                    updateCustomerAddressInputVisibility();
                    updateDeliveryAddressDisplay();
                    if (deliveryAddressGroup) deliveryAddressGroup.style.display = 'block';
                }
            });
        }
        updateDeliveryAssignmentVisibility();
    }

    function toggleNewDriverFields() {
        const box = document.getElementById('newDriverFields');
        if (!box) return;
        const willShow = box.style.display === 'none' || !box.style.display;
        box.style.display = willShow ? 'flex' : 'none';
        const select = document.getElementById('deliveryDriverSelect');
        if (willShow && select) select.value = '';
    }

    function toggleNewVehicleFields() {
        const box = document.getElementById('newVehicleFields');
        if (!box) return;
        const willShow = box.style.display === 'none' || !box.style.display;
        box.style.display = willShow ? 'flex' : 'none';
        const select = document.getElementById('deliveryVehicleSelect');
        if (willShow && select) select.value = '';
    }

    function updateDeliveryAssignmentVisibility() {
        const section = document.getElementById('deliveryAssignmentSection');
        const selectedDeliveryType = document.querySelector('input[name="deliveryType"]:checked')?.value || 'pickup';
        if (section) section.style.display = selectedDeliveryType === 'delivery' ? 'block' : 'none';
    }

    function updatePickupPaymentVisibility() {
        const section = document.getElementById('pickupPaymentSection');
        const fields = document.getElementById('pickupPaymentFields');
        const collect = document.getElementById('collectPickupPayment');
        const method = document.getElementById('pickupPaymentMethod')?.value || 'cash';
        const selectedDeliveryType = document.querySelector('input[name="deliveryType"]:checked')?.value || 'pickup';
        const shouldShow = selectedDeliveryType === 'pickup';

        if (!shouldShow) {
            if (collect) collect.checked = false;
            if (fields) fields.style.display = 'none';
            if (section) section.style.display = 'none';
            return;
        }

        if (section) section.style.display = 'block';
        if (fields) fields.style.display = (collect && collect.checked) ? 'block' : 'none';

        document.querySelectorAll('.pickup-cash-field').forEach(el => el.style.display = method === 'cash' ? '' : 'none');
        document.querySelectorAll('.pickup-check-fields').forEach(el => el.style.display = method === 'check' ? '' : 'none');
        document.querySelectorAll('.pickup-online-fields').forEach(el => el.style.display = method === 'online_transfer' ? '' : 'none');

        const total = getCartTotal();
        const tenderedInput = document.getElementById('pickupCashTendered');
        const tendered = parseFloat(String(tenderedInput?.value || '0').replace(/[^0-9.]/g, '')) || 0;
        const change = Math.max(tendered - total, 0);
        const changeEl = document.getElementById('pickupCashChange');
        if (changeEl) changeEl.textContent = formatCurrency(change);
    }

    function setupPickupPaymentListeners() {
        document.getElementById('collectPickupPayment')?.addEventListener('change', updatePickupPaymentVisibility);
        document.getElementById('pickupPaymentMethod')?.addEventListener('change', updatePickupPaymentVisibility);
        document.getElementById('pickupCashTendered')?.addEventListener('input', updatePickupPaymentVisibility);
        updatePickupPaymentVisibility();
    }
    
    function viewCart() {
        if (!cart.length) { 
            showToast('Cart is empty'); 
            return; 
        }
        
        const reviewDiv = document.getElementById('reviewItems');
        
        // Build receipt table with editable quantity inputs
        let html = '<table class="receipt-table">';
        html += '<thead><tr>';
        html += '<th>Product</th>';
        html += '<th>Unit</th>';
        html += '<th>Qty</th>';
        html += '<th>Price</th>';
        html += '<th>Total</th>';
        html += '<th></th>';
        html += '<tr></thead><tbody>';
        
        cart.forEach((i) => { 
            const pieces = i.quantity * getUnitConversion(i.id, i.unit_type);
            const qtyInputId = `review_qty_${i.id}_${i.unit_type.replace(/\s/g, '_')}`;
            const totalSpanId = `review_total_${i.id}_${i.unit_type.replace(/\s/g, '_')}`;
            
            html += `<tr id="review-row-${i.id}-${i.unit_type.replace(/\s/g, '_')}">
                <td class="product-name-cell">${escapeHtml(i.name)}</td>
                <td class="unit-cell"><span>${escapeHtml(i.unit_type)}</span></td>
                <td class="qty-cell">
                    <input type="number" id="${qtyInputId}" class="review-qty-input" value="${i.quantity}" min="1" 
                           onchange="updateReviewQuantityFromInput(${i.id}, '${escapeHtml(i.unit_type)}', this.value)">
                    <div class="pieces-small">(${pieces} pcs)</div>
                </td>
                <td class="price-cell"><span>${formatCurrency(i.price)}</span></td>
                <td class="total-cell"><span id="${totalSpanId}"><strong>${formatCurrency(i.price * i.quantity)}</strong></span></td>
                <td class="action-cell">
                    <button class="delete-item-btn" onclick="removeFromCartAndRefresh(${i.id}, '${escapeHtml(i.unit_type)}')">
                        <i class="bi bi-trash"></i>
                    </button>
                </td>
            </tr>`;
        });
        
        html += '</tbody>';
        reviewDiv.innerHTML = html;
        
        updateReviewTotals();
        
        // Reset delivery type to pickup
        const pickupRadio = document.getElementById('deliveryPickup');
        const deliveryAddressGroup = document.getElementById('deliveryAddressGroup');
        if (pickupRadio) pickupRadio.checked = true;
        if (deliveryAddressGroup) deliveryAddressGroup.style.display = 'none';
        
        const select = document.getElementById('modalCustomerSelect');
        const isLocked = <?php echo $is_customer_locked ? 'true' : 'false'; ?>;
        
        if (select) {
            if (!isLocked) {
                const newSelect = select.cloneNode(true);
                select.parentNode.replaceChild(newSelect, select);
                const preSelectedCustomerId = <?php echo $pre_selected_customer_id; ?>;
                if (preSelectedCustomerId > 0) {
                    const option = Array.from(newSelect.options).find(opt => opt.value == preSelectedCustomerId);
                    if (option) newSelect.value = preSelectedCustomerId;
                }
                newSelect.addEventListener('change', function() {
                    handleCustomerChange(this);
                    checkIfWalkinCustomer();
                });
                if (preSelectedCustomerId > 0) {
                    handleCustomerChange(newSelect);
                    checkIfWalkinCustomer();
                }
            } else {
                const lockedCustomerId = document.getElementById('lockedCustomerId')?.value;
                const lockedCustomerName = document.getElementById('lockedCustomerName')?.value;
                const lockedCustomerEmail = document.getElementById('lockedCustomerEmail')?.value;
                const lockedCustomerPhone = document.getElementById('lockedCustomerPhone')?.value;
                const lockedCustomerAddress = document.getElementById('lockedCustomerAddress')?.value;
                const lockedPriceLevel = document.getElementById('lockedPriceLevel')?.value;
                
                if (lockedCustomerId) {
                    document.getElementById('reviewCustomer').textContent = lockedCustomerName || '-';
                    updateSelectedCustomerDisplay(lockedCustomerName || '');
                    document.getElementById('reviewEmail').textContent = lockedCustomerEmail || '-';
                    document.getElementById('reviewPhone').textContent = lockedCustomerPhone || '-';
                    document.getElementById('reviewAddress').textContent = lockedCustomerAddress || '-';
                    
                    if (lockedPriceLevel) {
                        reloadProductPrices(lockedPriceLevel);
                    }
                    loadCustomerDiscount(lockedCustomerId);
                    loadCustomerOutstandingSnapshot(lockedCustomerId);
                }
                checkIfWalkinCustomer();
            }
        }
        
        new bootstrap.Modal(document.getElementById('cartModal')).show();
    }

    function updateReviewQuantityFromInput(itemId, unitType, newValue) {
        const cartIndex = cart.findIndex(i => i.id === itemId && i.unit_type === unitType);
        if (cartIndex === -1) return;
        
        let newQty = parseInt(newValue);
        if (isNaN(newQty) || newQty < 1) {
            newQty = 1;
        }
        
        cart[cartIndex].quantity = newQty;
        
        const qtyInputId = `review_qty_${itemId}_${unitType.replace(/\s/g, '_')}`;
        const qtyInput = document.getElementById(qtyInputId);
        if (qtyInput) qtyInput.value = newQty;
        
        const totalSpanId = `review_total_${itemId}_${unitType.replace(/\s/g, '_')}`;
        const price = cart[cartIndex].price;
        const totalSpan = document.getElementById(totalSpanId);
        if (totalSpan) {
            totalSpan.innerHTML = `<strong>${formatCurrency(price * newQty)}</strong>`;
        }
        
        const pieces = newQty * getUnitConversion(itemId, unitType);
        const row = document.getElementById(`review-row-${itemId}-${unitType.replace(/\s/g, '_')}`);
        if (row) {
            const piecesSpan = row.querySelector('.pieces-small');
            if (piecesSpan) {
                piecesSpan.textContent = `(${pieces} pcs)`;
            }
        }
        
        updateReviewTotals();
        updateCartBadge();
    }
    
    function loadCustomerDiscount(customerId) {
        if (!customerId) {
            customerDiscount = { percent: 0, type: 'percentage', basedAmount: 0, calculatedAmount: 0 };
            customerCreditSnapshot = { hasCreditLimit: false, creditLimit: 0, outstandingBalance: 0, orderAmount: 0, requiresOutstandingApproval: false };
            updateOutstandingBalanceDisplay();
            updateReviewTotals();
            return Promise.resolve();
        }
        const formData = new FormData();
        formData.append('action', 'get_customer_discount');
        formData.append('customer_id', customerId);
        return fetch(window.location.href, { method: 'POST', body: formData })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    customerDiscount = {
                        percent: parseFloat(data.discount || 0),
                        type: data.discount_type || 'percentage',
                        basedAmount: parseFloat(data.discount_based_amount || 0),
                        calculatedAmount: parseFloat(data.calculated_discount_amount || 0)
                    };
                } else {
                    customerDiscount = { percent: 0, type: 'percentage', basedAmount: 0, calculatedAmount: 0 };
                }
                updateReviewTotals();
            })
            .catch(() => {
                customerDiscount = { percent: 0, type: 'percentage', basedAmount: 0, calculatedAmount: 0 };
                updateReviewTotals();
            });
    }

    function handleCustomerChange(selectElement) {
        const opt = selectElement.options[selectElement.selectedIndex];
        if (opt && opt.value) {
            const selectedCustomerName = opt.text.split('(')[0].trim();
            document.getElementById('reviewCustomer').textContent = selectedCustomerName;
            updateSelectedCustomerDisplay(selectedCustomerName);
            document.getElementById('reviewEmail').textContent = opt.dataset.email || '-';
            document.getElementById('reviewPhone').textContent = opt.dataset.phone || '-';
            document.getElementById('reviewAddress').textContent = normalizeAddressValue(opt.dataset.address || '') || '-';
            const priceLevel = opt.dataset.priceLevel || 'Standard';
            reloadProductPrices(priceLevel);
            loadCustomerDiscount(opt.value);
            loadCustomerOutstandingSnapshot(opt.value);
            
            // Update delivery address display if deliver is selected
            const deliverRadio = document.getElementById('deliveryDeliver');
            if (deliverRadio && deliverRadio.checked) {
                updateCustomerAddressInputVisibility();
                updateDeliveryAddressDisplay();
            }
            
            // Check if walk-in and hide delivery options
            checkIfWalkinCustomer();
            updateCustomerAddressInputVisibility();
        } else {
            document.getElementById('reviewCustomer').textContent = '-';
            updateSelectedCustomerDisplay('');
            document.getElementById('reviewEmail').textContent = '-';
            document.getElementById('reviewPhone').textContent = '-';
            document.getElementById('reviewAddress').textContent = '-';
            
            customerDiscount = { percent: 0, type: 'percentage', basedAmount: 0, calculatedAmount: 0 };
            updateReviewTotals();
            // Show delivery section for no selection
            const deliveryTypeSection = document.getElementById('deliveryTypeSection');
            if (deliveryTypeSection) deliveryTypeSection.style.display = 'block';
            updateCustomerAddressInputVisibility();
        }
    }
    
    function removeFromCartAndRefresh(id, unit_type) {
        cart = cart.filter(i => !(i.id === id && i.unit_type === unit_type));
        updateCartBadge();
        
        const cartModal = document.getElementById('cartModal');
        if (cartModal && cartModal.classList.contains('show')) {
            const reviewDiv = document.getElementById('reviewItems');
            
            if (cart.length === 0) {
                reviewDiv.innerHTML = '<p class="text-muted text-center">No items in cart</p>';
                setTimeout(() => {
                    const modal = bootstrap.Modal.getInstance(cartModal);
                    if (modal) modal.hide();
                }, 1500);
            } else {
                // Rebuild the table
                let html = '<table class="receipt-table"><thead><tr>';
                html += '<th>Product</th><th>Unit</th><th>Qty</th><th>Price</th><th>Total</th><th></th>';
                html += '</table></thead><tbody>';
                
                cart.forEach(i => {
                    const pieces = i.quantity * getUnitConversion(i.id, i.unit_type);
                    const qtyInputId = `review_qty_${i.id}_${i.unit_type.replace(/\s/g, '_')}`;
                    const totalSpanId = `review_total_${i.id}_${i.unit_type.replace(/\s/g, '_')}`;
                    
                    html += `<tr id="review-row-${i.id}-${i.unit_type.replace(/\s/g, '_')}">
                        <td class="product-name-cell">${escapeHtml(i.name)}</td>
                        <td class="unit-cell"><span>${escapeHtml(i.unit_type)}</span></td>
                        <td class="qty-cell">
                            <input type="number" id="${qtyInputId}" class="review-qty-input" value="${i.quantity}" min="1" 
                                   onchange="updateReviewQuantityFromInput(${i.id}, '${escapeHtml(i.unit_type)}', this.value)">
                            <div class="pieces-small">(${pieces} pcs)</div>
                        </td>
                        <td class="price-cell"><span>${formatCurrency(i.price)}</span></td>
                        <td class="total-cell"><span id="${totalSpanId}"><strong>${formatCurrency(i.price * i.quantity)}</strong></span></td>
                        <td class="action-cell">
                            <button class="delete-item-btn" onclick="removeFromCartAndRefresh(${i.id}, '${escapeHtml(i.unit_type)}')">
                                <i class="bi bi-trash"></i>
                            </button>
                        </td>
                    </tr>`;
                });
                html += '</tbody>';
                reviewDiv.innerHTML = html;
                
                updateReviewTotals();
            }
        }
        
        renderProducts();
        showToast('Item removed from cart');
    }
    
    function clearCart() {
        if (cart.length === 0) { showToast('Cart is already empty'); return; }
        Swal.fire({ title: 'Clear Cart?', text: 'Are you sure you want to remove all items from your cart?', icon: 'warning', showCancelButton: true, confirmButtonColor: '#dc3545', cancelButtonColor: '#6c757d', confirmButtonText: 'Yes, Clear' }).then((result) => {
            if (result.isConfirmed) {
                cart = [];
                updateCartBadge();
                document.getElementById('reviewItems').innerHTML = '<p class="text-muted text-center">No items in cart</p>';
                customerDiscount = { percent: 0, type: 'percentage', basedAmount: 0, calculatedAmount: 0 };
                updateReviewTotals();
                document.getElementById('reviewCustomer').textContent = '-';
                document.getElementById('reviewEmail').textContent = '-';
                document.getElementById('reviewPhone').textContent = '-';
                document.getElementById('reviewAddress').textContent = '-';
                const customerSelect = document.getElementById('modalCustomerSelect');
                if (customerSelect) customerSelect.value = '';
                // Reset delivery type
                const pickupRadio = document.getElementById('deliveryPickup');
                if (pickupRadio) pickupRadio.checked = true;
                const deliveryAddressGroup = document.getElementById('deliveryAddressGroup');
                if (deliveryAddressGroup) deliveryAddressGroup.style.display = 'none';
                const cartModal = bootstrap.Modal.getInstance(document.getElementById('cartModal'));
                if (cartModal) cartModal.hide();
                renderProducts();
                showToast('Cart cleared successfully');
            }
        });
    }
    
    function showSONumberForm(soPrefix, callback) {
        // Generate auto-SO number
        const autoSoSuffix = String(Date.now()).slice(-6);

        const cartModalEl = document.getElementById('cartModal');
        const cartModalInstance = cartModalEl ? bootstrap.Modal.getInstance(cartModalEl) : null;

        const showReviewAgain = () => {
            if (cartModalEl) {
                bootstrap.Modal.getOrCreateInstance(cartModalEl, {
                    backdrop: 'static',
                    keyboard: false
                }).show();
            }
        };

        const openSoModal = () => {
            // Remove existing SO modal first
            const existingModal = document.getElementById('soNumberModal');
            if (existingModal) {
                const oldModalInstance = bootstrap.Modal.getInstance(existingModal);
                if (oldModalInstance) oldModalInstance.dispose();
                existingModal.remove();
            }

            // Create modal HTML
            const modalHtml = `
                <div class="modal fade so-number-modal" id="soNumberModal" tabindex="-1" aria-labelledby="soNumberModalLabel" aria-hidden="true">
                    <div class="modal-dialog modal-dialog-centered">
                        <div class="modal-content" style="border:0; border-radius:4px; overflow:hidden; box-shadow:0 18px 45px rgba(0,0,0,.35);">
                            <div class="modal-header" style="background:#047857; color:#ffffff; border-bottom:0; padding:18px 22px;">
                                <h5 class="modal-title d-flex align-items-center gap-2 mb-0" id="soNumberModalLabel" style="font-weight:700;">
                                    <i class="bi bi-receipt"></i>
                                    <span>SO Number</span>
                                </h5>
                                <button type="button"
                                        class="btn-close btn-close-white"
                                        id="soCloseBtn"
                                        aria-label="Close"
                                        style="opacity:1; box-shadow:none; filter:brightness(0) invert(1);">
                                </button>
                            </div>

                            <div class="modal-body text-center" style="padding:34px 36px 28px;">
                                <p class="mb-4" style="color:#6b7280; font-weight:600;">
                                    SO Prefix: <span style="color:#374151;">${soPrefix}</span>
                                </p>

                                <div class="mb-4">
                                    <h6 class="mb-3" style="font-weight:700; color:#6b7280;">Auto-generated SO Number</h6>
                                    <div class="d-flex justify-content-center align-items-center flex-wrap gap-3">
                                        <div style="display:inline-block; padding:12px 18px; background:#f1f3f5; border-radius:8px; font-weight:800; color:#047857; font-size:20px; letter-spacing:2px;">
                                            ${soPrefix}<span id="autoSoNumber">${autoSoSuffix}</span>
                                        </div>
                                        <button type="button" class="btn btn-success btn-sm" id="useAutoBtn" style="background:#059669; border-color:#059669; padding:9px 14px;">
                                            Use Auto-generated
                                        </button>
                                    </div>
                                </div>

                                <hr style="margin:26px 0;">

                                <div>
                                    <h6 class="mb-3" style="font-weight:700; color:#6b7280;">Or Enter Custom SO Number</h6>

                                    <div style="display:inline-block; padding:12px 18px; background:#f1f3f5; border-radius:8px; font-weight:800; color:#047857; margin-bottom:18px; font-size:20px; letter-spacing:2px;">
                                        ${soPrefix}<span id="soPreviewDigits">_____</span>
                                    </div>

                                    <div>
                                        <input type="text"
                                               id="soNumberInput"
                                               class="form-control form-control-lg text-center"
                                               placeholder="Last 5 to 6 numbers (optional)"
                                               style="font-size:18px; font-weight:700; letter-spacing:2px; height:58px; border-radius:6px;"
                                               autocomplete="off">

                                        <small class="form-text text-muted mt-2 d-block">
                                            Type the last 5 to 6 digits only, or leave blank to use auto-generated.
                                        </small>

                                        <div id="soError" class="alert alert-danger mt-3" style="display:none;"></div>
                                    </div>
                                </div>
                            </div>

                            <div class="modal-footer" style="border-top:1px solid #e5e7eb; padding:18px 22px;">
                                <button type="button" class="btn btn-secondary" id="soCancelBtn" style="padding:9px 16px;">
                                    Cancel
                                </button>
                                <button type="button" class="btn btn-success" id="soConfirmBtn" style="background:#059669; border-color:#059669; padding:9px 16px;">
                                    Continue
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            `;

            document.body.insertAdjacentHTML('beforeend', modalHtml);

            const soModalEl = document.getElementById('soNumberModal');

            const modal = new bootstrap.Modal(soModalEl, {
                backdrop: 'static',
                keyboard: false
            });

            const input = document.getElementById('soNumberInput');
            const preview = document.getElementById('soPreviewDigits');
            const errorDiv = document.getElementById('soError');
            const confirmBtn = document.getElementById('soConfirmBtn');
            const useAutoBtn = document.getElementById('useAutoBtn');
            const closeBtn = document.getElementById('soCloseBtn');
            const cancelBtn = document.getElementById('soCancelBtn');

            let isClosing = false;

            const closeAndCallback = (value, reopenReview = false) => {
                if (isClosing) return;
                isClosing = true;

                soModalEl.addEventListener('hidden.bs.modal', () => {
                    const modalInstance = bootstrap.Modal.getInstance(soModalEl);
                    if (modalInstance) modalInstance.dispose();

                    soModalEl.remove();

                    if (reopenReview) {
                        showReviewAgain();
                    }

                    callback(value);
                }, { once: true });

                modal.hide();
            };

            // Handle input - only allow numbers
            input.addEventListener('input', function() {
                this.value = this.value.replace(/\D/g, '').slice(0, 6);
                preview.textContent = this.value || '_____';
                errorDiv.style.display = 'none';
            });

            // Handle Enter key
            input.addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    confirmBtn.click();
                }
            });

            // Handle auto-generated button
            useAutoBtn.addEventListener('click', function() {
                input.value = '';
                preview.textContent = '_____';
                errorDiv.style.display = 'none';
                closeAndCallback(autoSoSuffix, false);
            });

            // Handle confirm button
            confirmBtn.addEventListener('click', function() {
                const value = input.value.trim();

                // If empty, use auto-generated
                if (value === '') {
                    closeAndCallback(autoSoSuffix, false);
                    return;
                }

                // If not empty, validate it's 5-6 digits
                if (!/^\d{5,6}$/.test(value)) {
                    errorDiv.textContent = 'Please enter 5 to 6 numbers only, or leave blank for auto-generated.';
                    errorDiv.style.display = 'block';
                    input.focus();
                    return;
                }

                closeAndCallback(value, false);
            });

            // Handle cancel / close: show Review & Confirm modal again
            closeBtn.addEventListener('click', function() {
                closeAndCallback(null, true);
            });

            cancelBtn.addEventListener('click', function() {
                closeAndCallback(null, true);
            });

            modal.show();

            setTimeout(() => {
                input.focus();
            }, 300);
        };

        // Hide Review & Confirm modal first before opening SO Number modal
        if (cartModalEl && cartModalEl.classList.contains('show')) {
            cartModalEl.addEventListener('hidden.bs.modal', openSoModal, { once: true });

            if (cartModalInstance) {
                cartModalInstance.hide();
            } else {
                bootstrap.Modal.getOrCreateInstance(cartModalEl).hide();
            }
        } else {
            openSoModal();
        }
    }

    

    function toggleSIDetails() {
        const isSI = document.querySelector('input[name="documentType"]:checked')?.value === 'SI';
        const box = document.getElementById('siDetailsFields');
        if (box) box.style.display = isSI ? 'flex' : 'none';

        ['siNumber', 'registeredBusinessName', 'businessTin', 'businessAddress'].forEach(id => {
            const input = document.getElementById(id);
            if (!input) return;
            input.required = isSI;
            input.setAttribute('aria-required', isSI ? 'true' : 'false');
            if (!isSI) input.classList.remove('is-invalid');
        });

        document.querySelectorAll('.si-required-marker').forEach(marker => {
            marker.style.display = isSI ? 'inline' : 'none';
        });
    }

    document.addEventListener('DOMContentLoaded', toggleSIDetails);


    function showBeyondCreditApprovalModal(data, onApprove, onCancel) {
        Swal.fire({
            icon: 'warning',
            title: data.title || 'Beyond Credit Limit Approval Required',
            html: `
                ${data.html || ''}
                <div class="text-start mt-3">
                    <label class="form-label fw-bold" for="beyondCreditExplanationInput">Explanation <span class="text-danger">*</span></label>
                    <textarea id="beyondCreditExplanationInput" class="form-control" rows="4" placeholder="Enter reason why this order is being allowed."></textarea>
                    <div class="form-check mt-3">
                        <input class="form-check-input" type="checkbox" value="1" id="beyondCreditAcknowledgeInput">
                        <label class="form-check-label fw-semibold" for="beyondCreditAcknowledgeInput">
                            I understand this order requires approval, I am allowing this order to proceed.
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
            allowEscapeKey: true,
            keydownListenerCapture: true,
            didOpen: () => {
                const input = document.getElementById('beyondCreditExplanationInput');
                if (input) setTimeout(() => input.focus(), 80);
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
                onApprove(result.value.explanation);
            } else if (typeof onCancel === 'function') {
                onCancel();
            }
        });
    }

    function submitOrder() {
const select = document.getElementById('modalCustomerSelect');
const lockedCustomerId = document.getElementById('lockedCustomerId')?.value || '';
const custId = select?.value ? parseInt(select.value) : parseInt(lockedCustomerId || 0);
const opt = select?.options[select.selectedIndex];

if (!custId) {
    showToast('Please select a customer');
    return;
}
    
    // Check if customer is walk-in
    let customerName = '';
    let isWalkin = false;
    
    if (opt && opt.value) {
        customerName = opt.text.split('(')[0].trim().toLowerCase();
        isWalkin = (customerName === 'walk-in customer');
    } else {
        const lockedCustomerName = document.getElementById('lockedCustomerName')?.value || '';
        isWalkin = (lockedCustomerName.toLowerCase() === 'walk-in customer');
    }
    
    let deliveryType = 'pickup';
    let deliveryAddress = '';
    
    if (!isWalkin) {
        deliveryType = document.querySelector('input[name="deliveryType"]:checked')?.value || 'pickup';
        
        if (deliveryType === 'delivery') {
            deliveryAddress = getSelectedCustomerAddress() || getTypedCustomerAddress();
            
            if (!deliveryAddress) {
                showToast('Please type the customer address before scheduling delivery.');
                const addressInput = document.getElementById('deliveryAddressInput') || document.getElementById('customerAddressInput');
                if (addressInput) addressInput.focus();
                return;
            }
            setCustomerAddressEverywhere(deliveryAddress);
        } else {
            deliveryAddress = getSelectedCustomerAddress() || getTypedCustomerAddress();
            if (deliveryAddress) setCustomerAddressEverywhere(deliveryAddress);
        }
    }
    
    let allPricesValid = true;
    for (let i = 0; i < cart.length; i++) {
        const cartItem = cart[i];
        const product = inventory.find(p => p.id === cartItem.id);
        const productUnits = productUnitTypes[cartItem.id] || [];
        let standardPrice = cartItem.price;

        const matchedUnit = productUnits.find(ut =>
            String(ut.unit_type_name || '').trim().toLowerCase() === String(cartItem.unit_type || '').trim().toLowerCase()
        );

        if (matchedUnit && matchedUnit.unit_price !== undefined && matchedUnit.unit_price !== null) {
            standardPrice = parseFloat(matchedUnit.unit_price) || cartItem.price;
        } else if (product) {
            const defaultUnitName = String(product.default_unit_type_name || product.unit_type || '').trim().toLowerCase();
            const cartUnitName = String(cartItem.unit_type || '').trim().toLowerCase();

            if (defaultUnitName === cartUnitName) {
                standardPrice = parseFloat(product.unit_price || cartItem.price) || cartItem.price;
            }
        }

        if (parseFloat(cartItem.price) < parseFloat(standardPrice)) {
            showToast(`Item "${cartItem.name}" price is below standard price ${formatCurrency(standardPrice)}`);
            allPricesValid = false;
            break;
        }
    }
    if (!allPricesValid) return;

    // Low or zero stock should not block order placement.
    // The order will still be submitted and saved as confirmed.

    const documentType = document.querySelector('input[name="documentType"]:checked')?.value || 'SO';
    const siNumber = (document.getElementById('siNumber')?.value || '').trim();
    const atwNo = (document.getElementById('atwNo')?.value || '').trim();
    const gatepassNo = (document.getElementById('gatepassNo')?.value || '').trim();
    const registeredBusinessName = (document.getElementById('registeredBusinessName')?.value || '').trim();
    const businessTin = (document.getElementById('businessTin')?.value || '').trim();
    const businessAddress = (document.getElementById('businessAddress')?.value || '').trim();
    const requiredDocumentFields = [
        { id: 'atwNo', value: atwNo },
        { id: 'gatepassNo', value: gatepassNo }
    ];
    const missingDocumentField = requiredDocumentFields.find(field => !field.value);
    if (missingDocumentField) {
        requiredDocumentFields.forEach(field => {
            const input = document.getElementById(field.id);
            if (input) input.classList.toggle('is-invalid', !field.value);
        });
        showToast('Please enter ATW No. and Gatepass No.');
        document.getElementById(missingDocumentField.id)?.focus();
        return;
    }
    requiredDocumentFields.forEach(field => document.getElementById(field.id)?.classList.remove('is-invalid'));

    const documentNumberPattern = /^\d{1,6}$/;
    if (!documentNumberPattern.test(atwNo) || !documentNumberPattern.test(gatepassNo)) {
        requiredDocumentFields.forEach(field => {
            const input = document.getElementById(field.id);
            if (input) input.classList.toggle('is-invalid', !documentNumberPattern.test(field.value));
        });
        showToast('ATW No. and Gatepass No. must be numbers only with a maximum of 6 digits.');
        (!documentNumberPattern.test(atwNo) ? document.getElementById('atwNo') : document.getElementById('gatepassNo'))?.focus();
        return;
    }

    if (documentType === 'SI') {
        const requiredSiFields = [
            { id: 'siNumber', value: siNumber },
            { id: 'registeredBusinessName', value: registeredBusinessName },
            { id: 'businessTin', value: businessTin },
            { id: 'businessAddress', value: businessAddress }
        ];
        const missingField = requiredSiFields.find(field => !field.value);
        if (missingField) {
            requiredSiFields.forEach(field => {
                const input = document.getElementById(field.id);
                if (input) input.classList.toggle('is-invalid', !field.value);
            });
            showToast('Please complete SI number, registered business name, TIN, and address.');
            document.getElementById(missingField.id)?.focus();
            return;
        }
    }
    
    const items = cart.map(i => ({ id: i.id, name: i.name, price: i.price, quantity: i.quantity, sku: i.sku, unit_type: i.unit_type }));
    const subtotal = getCartSubtotal();
    const discountDetails = computeCartDiscount(subtotal);
    const orderStatus = (isWalkin || deliveryType === 'pickup') ? 'delivered' : 'pending';
    
    const collectPickupPayment = (isWalkin || deliveryType === 'pickup') && document.getElementById('collectPickupPayment')?.checked;
    const pickupPaymentMethod = document.getElementById('pickupPaymentMethod')?.value || 'cash';
    const grandTotalForPayment = discountDetails.total;

    if (collectPickupPayment) {
        if (pickupPaymentMethod === 'cash') {
            const cashTendered = parseFloat(document.getElementById('pickupCashTendered')?.value || '0');
            if (cashTendered <= 0) { showToast('Cash tendered is required'); return; }
            if (cashTendered + 0.009 < grandTotalForPayment) { showToast('Cash tendered cannot be lower than grand total'); return; }
        } else if (pickupPaymentMethod === 'check') {
            if (!document.getElementById('pickupCheckDate')?.value || !document.getElementById('pickupCheckNumber')?.value.trim() || !document.getElementById('pickupBankName')?.value.trim() || !document.getElementById('pickupBankBranch')?.value.trim()) {
                showToast('All check details are required');
                return;
            }
        } else if (pickupPaymentMethod === 'online_transfer') {
            if (!document.getElementById('pickupReferenceNumber')?.value.trim() || !document.getElementById('pickupOnlineBankName')?.value.trim()) {
                showToast('Reference number and Bank/Wallet are required');
                return;
            }
        }
    }

    const isDeliveryOrder = !isWalkin && deliveryType === 'delivery';
    const newDriverVisible = document.getElementById('newDriverFields')?.style.display !== 'none';
    const newVehicleVisible = document.getElementById('newVehicleFields')?.style.display !== 'none';
    if (isDeliveryOrder) {
        if (newDriverVisible) {
            if (!document.getElementById('newDriverFirstName')?.value.trim() || !document.getElementById('newDriverLastName')?.value.trim() || !document.getElementById('newDriverEmail')?.value.trim() || !document.getElementById('newDriverPassword')?.value.trim() || !document.getElementById('newDriverLicense')?.value.trim()) {
                showToast('Please complete the new driver credentials.');
                return;
            }
        } else if (!document.getElementById('deliveryDriverSelect')?.value) {
            showToast('Please select a driver or add a new driver.');
            return;
        }

        if (newVehicleVisible) {
            if (!document.getElementById('newVehicleType')?.value.trim() || !document.getElementById('newVehiclePlate')?.value.trim()) {
                showToast('Please complete the new vehicle details.');
                return;
            }
        } else if (!document.getElementById('deliveryVehicleSelect')?.value) {
            showToast('Please select a vehicle or add a new vehicle.');
            return;
        }
    }

    const todayDate = new Date();
    const y = todayDate.getFullYear();
    const m = String(todayDate.getMonth() + 1).padStart(2, '0');
    const d = String(todayDate.getDate()).padStart(2, '0');
    const soDatePart = `${y}${m}${d}`;
    const soPrefix = `SO-${soDatePart}-`;

    const autoSoSuffix = String(Date.now()).slice(-6);
    const submitWithSoSuffix = (manualSoSuffix, approvalExplanation = '', approvalAcknowledged = false, approvalType = 'credit_limit') => {
        if (!manualSoSuffix) return;
        const btn = document.getElementById('confirmOrderBtn');
        const orig = btn.innerHTML;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Processing...';
        btn.disabled = true;
        
        const postData = { 
            action: 'submit_order', 
            customer_id: custId, 
           customer_name: (opt && opt.value)
            ? opt.text.split('(')[0].trim()
            : (document.getElementById('lockedCustomerName')?.value || ''),
            email: opt?.dataset?.email || document.getElementById('lockedCustomerEmail')?.value || '', 
            phone: opt?.dataset?.phone || document.getElementById('lockedCustomerPhone')?.value || '', 
            address: deliveryAddress || getTypedCustomerAddress() || getSelectedCustomerAddress(), 
            items: JSON.stringify(items), 
            discount_percent: customerDiscount.type === 'percentage' ? (customerDiscount.percent || 0) : 0,
            discount_calculation_type: customerDiscount.type || 'percentage',
            discount_based_amount: customerDiscount.type === 'amount_based' ? (customerDiscount.basedAmount || customerDiscount.calculatedAmount || 0) : 0,
            agent_location: deliveryType === 'delivery' ? deliveryAddress : '',
            order_status: orderStatus,
            fulfillment_type: (isWalkin || deliveryType === 'pickup') ? 'pickup' : 'delivery',
            delivery_driver_mode: newDriverVisible ? 'new' : 'select',
            delivery_driver_id: document.getElementById('deliveryDriverSelect')?.value || '',
            new_driver_first_name: document.getElementById('newDriverFirstName')?.value || '',
            new_driver_last_name: document.getElementById('newDriverLastName')?.value || '',
            new_driver_name: `${document.getElementById('newDriverFirstName')?.value || ''} ${document.getElementById('newDriverLastName')?.value || ''}`.trim(),
            new_driver_license: document.getElementById('newDriverLicense')?.value || '',
            new_driver_license_expiry: document.getElementById('newDriverLicenseExpiry')?.value || '',
            new_driver_contact: document.getElementById('newDriverContact')?.value || '',
            new_driver_email: document.getElementById('newDriverEmail')?.value || '',
            new_driver_password: document.getElementById('newDriverPassword')?.value || '',
            delivery_vehicle_mode: newVehicleVisible ? 'new' : 'select',
            delivery_vehicle_id: document.getElementById('deliveryVehicleSelect')?.value || '',
            new_vehicle_type: document.getElementById('newVehicleType')?.value || '',
            new_vehicle_plate: document.getElementById('newVehiclePlate')?.value || '',
            collect_payment: collectPickupPayment ? '1' : '0',
            payment_method: pickupPaymentMethod,
            cash_tendered: document.getElementById('pickupCashTendered')?.value || '',
            check_date: document.getElementById('pickupCheckDate')?.value || '',
            check_number: document.getElementById('pickupCheckNumber')?.value || '',
            bank_name: document.getElementById('pickupBankName')?.value || '',
            bank_branch: document.getElementById('pickupBankBranch')?.value || '',
            reference_number: document.getElementById('pickupReferenceNumber')?.value || '',
            online_bank_name: document.getElementById('pickupOnlineBankName')?.value || '',
            online_bank_branch: document.getElementById('pickupOnlineBankBranch')?.value || '',
            document_type: documentType,
            si_number: siNumber,
            atw_no: atwNo,
            gatepass_no: gatepassNo,
            registered_business_name: registeredBusinessName,
            tin: businessTin,
            business_address: businessAddress,
            so_suffix: manualSoSuffix,
            beyond_credit_explanation: approvalType === 'credit_limit' ? (approvalExplanation || '') : '',
            beyond_credit_acknowledged: (approvalType === 'credit_limit' && approvalAcknowledged) ? '1' : '0',
            outstanding_balance_explanation: approvalType === 'outstanding_balance' ? (approvalExplanation || '') : '',
            outstanding_balance_acknowledged: (approvalType === 'outstanding_balance' && approvalAcknowledged) ? '1' : '0'
        };
        
        const formBody = Object.keys(postData).map(key => encodeURIComponent(key) + '=' + encodeURIComponent(postData[key])).join('&');
        fetch(window.location.href, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' }, body: formBody })
            .then(r => r.text()).then(t => { if (!t || t.trim().startsWith('<')) throw new Error('Invalid response'); return JSON.parse(t); })
            .then(d => {
                // Hide modal first
                const cartModal = bootstrap.Modal.getInstance(document.getElementById('cartModal'));
                if (cartModal) cartModal.hide();
                
                if (d.success) {
                    if (d.updated_stock) {
                        d.updated_stock.forEach(i => {
                            const p = inventory.find(p => p.id === i.item_id);
                            if (!p) return;
                            if (!p.unit_stocks) p.unit_stocks = {};
                            p.unit_stocks[i.unit_type] = Number(i.new_stock || 0);
                            if (String(i.unit_type).toLowerCase() === String(p.default_unit_type_name || '').toLowerCase()) {
                                p.default_stock = Number(i.new_stock || 0);
                                p.stock_in_default_uom = Number(i.new_stock || 0);
                                p.raw_stock = Number(i.new_stock || 0);
                            }
                            p.stock_smallest = Object.keys(p.unit_stocks).reduce((total, unitName) => {
                                return total + (Number(p.unit_stocks[unitName] || 0) * getUnitConversion(p.id, unitName));
                            }, 0);
                            p.stock = p.stock_smallest;
                        });
                    }
                    cart = [];
                    updateCartBadge();
                    
                    // Calculate totals
                    const totalAmount = d.total_amount || discountDetails.total;
                    const itemCount = items.reduce((sum, item) => sum + item.quantity, 0);
                    const discountAmount = d.discount_amount || discountDetails.amount || 0;
                    
                    // Build HTML content for alert
                    let alertHtml = `
                        <div style="text-align: left; margin-top: 15px; padding: 15px; background: #f8f9fa; border-radius: 12px;">
                            <div style="display: flex; justify-content: space-between; margin-bottom: 10px; padding-bottom: 8px; border-bottom: 1px solid #e0e0e0;">
                                <span style="font-weight: 600; color: #555;">Order Number:</span>
                                <span style="color: #047857; font-weight: 700;">${d.so_number}</span>
                            </div>
                            <div style="display: flex; justify-content: space-between; margin-bottom: 10px; padding-bottom: 8px; border-bottom: 1px solid #e0e0e0;">
                                <span style="font-weight: 600; color: #555;">Customer:</span>
                                <span style="color: #333;">${escapeHtml(postData.customer_name)}</span>
                            </div>
                            <div style="display: flex; justify-content: space-between; margin-bottom: 10px; padding-bottom: 8px; border-bottom: 1px solid #e0e0e0;">
                                <span style="font-weight: 600; color: #555;">Items:</span>
                                <span style="color: #333;">${itemCount} pcs</span>
                            </div>
                            <div style="display: flex; justify-content: space-between; margin-bottom: 10px; padding-bottom: 8px; border-bottom: 1px solid #e0e0e0;">
                                <span style="font-weight: 600; color: #555;">Subtotal:</span>
                                <span style="color: #333; font-weight: 700;">${formatCurrency(subtotal)}</span>
                            </div>
                            ${discountAmount > 0 ? `
                            <div style="display: flex; justify-content: space-between; margin-bottom: 10px; padding-bottom: 8px; border-bottom: 1px solid #e0e0e0;">
                                <span style="font-weight: 600; color: #555;">Discount:</span>
                                <span style="color: #dc3545; font-weight: 700;">-${formatCurrency(discountAmount)}</span>
                            </div>` : ''}
                            <div style="display: flex; justify-content: space-between; margin-bottom: 10px; padding-bottom: 8px; border-bottom: 1px solid #e0e0e0;">
                                <span style="font-weight: 600; color: #555;">Grand Total:</span>
                                <span style="color: #047857; font-weight: 700; font-size: 18px;">${formatCurrency(totalAmount)}</span>
                            </div>
                            ${!isWalkin && deliveryType === 'delivery' ? `
                            <div style="display: flex; justify-content: space-between; margin-top: 8px;">
                                <span style="font-weight: 600; color: #555;"><i class="bi bi-geo-alt"></i> Delivery:</span>
                                <span style="color: #666; font-size: 12px;">${escapeHtml(deliveryAddress)}</span>
                            </div>
                            ` : ''}
                            ${isWalkin ? `
                            <div style="margin-top: 10px; padding: 8px; background: #e8f5e9; border-radius: 8px; text-align: center;">
                                <i class="bi bi-check-circle-fill" style="color: #047857;"></i>
                                <span style="color: #047857; font-size: 12px;"> Walk-in order completed</span>
                            </div>
                            ` : deliveryType === 'pickup' ? `
                            <div style="margin-top: 10px; padding: 8px; background: #e3f2fd; border-radius: 8px; text-align: center;">
                                <i class="bi bi-box-seam" style="color: #2196F3;"></i>
                                <span style="color: #2196F3; font-size: 12px;"> Ready for pickup at branch</span>
                            </div>
                            ` : ''}
                        </div>
                    `;
                    
                    // Show alert
                    Swal.fire({
                        icon: 'success',
                        title: isWalkin ? 'Order Completed!' : 'Order Submitted!',
                        html: alertHtml,
                        showDenyButton: true,
                    
                        confirmButtonText: 'View Order',
                        denyButtonText: 'New Order',
                    
                        confirmButtonColor: '#047857',
                        denyButtonColor: '#649f86',
                        cancelButtonColor: '#6c757d',
                    
                        background: '#ffffff',
                        backdrop: `rgba(0,0,0,0.4)`,
                        allowOutsideClick: false,
                    
                        customClass: {
                            popup: 'animated-order-alert',
                            confirmButton: 'order-confirm-btn',
                            denyButton: 'order-confirm-btn',
                            cancelButton: 'order-cancel-btn'
                        }
                    
                    }).then((result) => {
                    
                        // VIEW ORDER
                        if (result.isConfirmed) {
                    
                            Swal.close();
                    
                            setTimeout(() => {
                    
                                const swalBackdrop =
                                    document.querySelector('.swal2-container');
                    
                                if (swalBackdrop) {
                                    swalBackdrop.remove();
                                }
                    
                                document.body.classList.remove(
                                    'swal2-shown'
                                );
                    
                                document.body.classList.remove(
                                    'swal2-height-auto'
                                );
                    
                                setTimeout(() => {
                                    viewOrderFromOrderProduct(d.so_id);
                                },100);
                    
                            },300);
                    
                        }
                    
                        // NEW ORDER
                        else if (result.isDenied) {
                    
                            window.location.href =
                                'customer_list.php';
                    
                        }
                    
                        // CLOSE
                        else {
                    
                            location.reload();
                    
                        }
                    
                    });
                } else {
                    if (d.type === 'credit_limit_required' || d.type === 'outstanding_balance_required') {
                        btn.innerHTML = orig;
                        btn.disabled = false;
                        const cartModalEl = document.getElementById('cartModal');
                        const cartModalInstance = cartModalEl ? bootstrap.Modal.getInstance(cartModalEl) : null;
                        const openApproval = () => showBeyondCreditApprovalModal(
                            d,
                            (explanation) => submitWithSoSuffix(manualSoSuffix, explanation, true, d.type === 'outstanding_balance_required' ? 'outstanding_balance' : 'credit_limit'),
                            () => {
                                if (cartModalEl && !cartModalEl.classList.contains('show')) {
                                    bootstrap.Modal.getOrCreateInstance(cartModalEl, { keyboard: true }).show();
                                }
                            }
                        );

                        if (cartModalInstance && cartModalEl.classList.contains('show')) {
                            cartModalEl.addEventListener('hidden.bs.modal', openApproval, { once: true });
                            cartModalInstance.hide();
                        } else {
                            openApproval();
                        }
                        return;
                    }

                    Swal.fire({ 
                        icon: 'error', 
                        title: d.type === 'credit_limit_error' ? (d.title || 'Credit Limit Exceeded') : (d.type === 'outstanding_balance_required' ? (d.title || 'Outstanding Balance Approval Required') : 'Order Failed'), 
                        html: d.html || escapeHtml(d.message || 'Failed to submit order. Please try again.'),
                        confirmButtonColor: '#dc3545'
                    }).then(() => {
                        // Keep the user on this page, so they can correct the SO number or order details
                    });
                }
                btn.innerHTML = orig;
                btn.disabled = false;
            })
            .catch(e => { 
                console.error('Submit error:', e); 
                Swal.fire({ 
                    icon: 'error', 
                    title: 'Connection Error', 
                    text: 'Unable to connect to server. Please check your internet connection and try again.',
                    confirmButtonColor: '#dc3545'
                }).then(() => {
                    // Keep the user on this page
                });
                btn.innerHTML = orig; 
                btn.disabled = false; 
            });
    };

    if (documentType === 'SI') {
        submitWithSoSuffix(autoSoSuffix);
    } else {
        showSONumberForm(soPrefix, (manualSoSuffix) => submitWithSoSuffix(manualSoSuffix));
    }
}
    
    function showProductInfo(pid) {
        const modal = new bootstrap.Modal(document.getElementById('productInfoModal'));
        document.getElementById('loadingState').style.display = 'block';
        document.getElementById('productContent').style.display = 'none';
        modal.show();
        const fd = new FormData();
        fd.append('action', 'get_product_details');
        fd.append('product_id', pid);
        fd.append('price_level', getSelectedPriceLevel());
        fetch(window.location.href, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    const p = data.product;
                    document.getElementById('modalProductName').textContent = p.item_name || 'Product';
                    const placeholder = 'data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%22120%22 height=%22120%22%3E%3Crect fill=%22%23e0e0e0%22 width=%22120%22 height=%22120%22/%3E%3Ctext x=%2250%25%22 y=%2250%25%22 text-anchor=%22middle%22 dy=%22.3em%22 fill=%22%23999%22 font-size=%2212%22%3ENo Image%3C/text%3E%3C/svg%3E';
                    let mainImg = placeholder;
                    if (data.images && data.images.length > 0) {
                        const primaryImage = data.images.find(img => img.is_primary) || data.images[0];
                        mainImg = '../uploads/products/' + primaryImage.image_path;
                    } else if (p.product_image_url) { mainImg = '../uploads/products/' + p.product_image_url; }
                    document.getElementById('modalProductImage').src = mainImg;
                    document.getElementById('modalProductCode').textContent = p.item_code || '-';
                    document.getElementById('modalProductCategory').textContent = p.category || '-';
                    document.getElementById('modalProductDescription').textContent = p.description || '-';
                    document.getElementById('modalProductPrice').textContent = formatCurrency(parseFloat(p.unit_price || 0));
                    const unitType = activeUnitTypes[pid] || 'piece';
                    const conversion = getUnitConversion(pid, unitType);
                    const convertedStock = getAvailableStock(pid) / conversion;
                    document.getElementById('modalProductStock').innerHTML = formatStockDisplay(convertedStock, unitType);
                    let histHtml = '';
                    if (data.order_history && data.order_history.length) {
                        data.order_history.forEach(o => {
                            const d = new Date(o.order_date).toLocaleDateString();
                            const sc = o.order_status === 'pending' ? 'status-pending' : (o.order_status === 'cancelled' ? 'status-cancelled' : 'status-completed');
                            histHtml += `<tr><td>${d}</td><td>${o.so_number}</td><td>${o.customer_name}</td><td>${o.unit_type}</td><td>${o.quantity_ordered}</td><td><span class="status-badge ${sc}">${o.order_status}</span></td></tr>`;
                        });
                    } else { histHtml = '<tr><td colspan="6" class="text-center">No history</td></tr>'; }
                    document.getElementById('modalOrderHistory').innerHTML = histHtml;
                    document.getElementById('loadingState').style.display = 'none';
                    document.getElementById('productContent').style.display = 'block';
                } else { showToast('Error loading product details'); modal.hide(); }
            })
            .catch(e => { console.error('Error:', e); showToast('Error loading details'); modal.hide(); });
    }
    
    // Sidebar functions
    function toggleSidebarDropdown(event, targetId) {
        event.preventDefault(); event.stopPropagation();
        const target = document.getElementById(targetId);
        const btn = event.currentTarget;
        const arrow = btn.querySelector('.dropdown-arrow');
        const sidebar = document.getElementById('sidebar');
        if (sidebar.classList.contains('collapsed')) {
            sidebar.classList.remove('collapsed');
            localStorage.setItem('sidebarCollapsed', 'false');
            setTimeout(() => { document.querySelectorAll('.sidebar .collapse.show').forEach(collapse => { if (collapse.id !== targetId) collapse.classList.remove('show'); }); target.classList.add('show'); if (arrow) arrow.style.transform = 'translateY(-50%) rotate(180deg)'; }, 50);
            return;
        }
        if (target.classList.contains('show')) { target.classList.remove('show'); if (arrow) arrow.style.transform = 'translateY(-50%) rotate(0deg)'; }
        else { document.querySelectorAll('.sidebar .collapse.show').forEach(collapse => collapse.classList.remove('show')); target.classList.add('show'); if (arrow) arrow.style.transform = 'translateY(-50%) rotate(180deg)'; }
    }
    
    function closeAllMobileDropdowns() {
        document.querySelectorAll('.mobile-nav .more-dropdown, .more-dropdown').forEach(function(dropdown) {
            dropdown.classList.remove('show');
        });

        document.querySelectorAll('.mobile-nav .more-btn, .more-btn').forEach(function(btn) {
            btn.classList.remove('active');
            btn.setAttribute('aria-expanded', 'false');
        });
    }

    function toggleMobileDropdown(event, dropdownId) {
        if (event) {
            event.preventDefault();
            event.stopPropagation();
        }

        const dropdown = document.getElementById(dropdownId);
        const btn = event ? event.currentTarget : null;

        if (!dropdown) return false;

        const isOpen = dropdown.classList.contains('show');
        closeAllMobileDropdowns();

        if (!isOpen) {
            dropdown.classList.add('show');
            if (btn) {
                btn.classList.add('active');
                btn.setAttribute('aria-expanded', 'true');
            }
        }

        return false;
    }

    /* Compatibility for existing onclick="toggleDropdown(event, '...')" buttons */
    function toggleDropdown(event, dropdownId) {
        return toggleMobileDropdown(event, dropdownId);
    }

    window.closeAllMobileDropdowns = closeAllMobileDropdowns;
    window.toggleMobileDropdown = toggleMobileDropdown;
    window.toggleDropdown = toggleDropdown;
    
    function toggleSidebar() {
        const s = document.getElementById('sidebar');
        if (window.innerWidth <= 992) {
            s.classList.toggle('active');
            if (!document.querySelector('.sidebar-overlay')) { const o = document.createElement('div'); o.className = 'sidebar-overlay'; document.body.appendChild(o); o.addEventListener('click', closeMobileSidebar); setTimeout(() => o.classList.add('active'), 10); }
        } else {
            s.classList.toggle('collapsed');
            localStorage.setItem('sidebarCollapsed', s.classList.contains('collapsed'));
            document.querySelectorAll('.nav-text').forEach(t => t.style.display = s.classList.contains('collapsed') ? 'none' : 'inline-block');
            document.querySelector('.main-content').style.marginLeft = s.classList.contains('collapsed') ? '80px' : '250px';
        }
    }
    
    function closeMobileSidebar() { document.getElementById('sidebar').classList.remove('active'); const o = document.querySelector('.sidebar-overlay'); if (o) { o.classList.remove('active'); setTimeout(() => o.remove(), 300); } }
    
    function initializeSidebar() {
        const s = document.getElementById('sidebar');
        if (window.innerWidth > 992) {
            const saved = localStorage.getItem('sidebarCollapsed') === 'true';
            s.classList.toggle('collapsed', saved);
            document.querySelectorAll('.nav-text').forEach(t => t.style.display = saved ? 'none' : 'inline-block');
            document.querySelector('.main-content').style.marginLeft = saved ? '80px' : '250px';
        } else { s.classList.remove('active', 'collapsed'); document.querySelectorAll('.nav-text').forEach(t => t.style.display = 'inline-block'); document.querySelector('.main-content').style.marginLeft = '0'; }
    }
    
    function handleSidebarResize() {
        const s = document.getElementById('sidebar');
        const o = document.querySelector('.sidebar-overlay');
        if (window.innerWidth > 992) { if (o) o.remove(); s.classList.remove('active'); const saved = localStorage.getItem('sidebarCollapsed') === 'true'; s.classList.toggle('collapsed', saved); document.querySelectorAll('.nav-text').forEach(t => t.style.display = saved ? 'none' : 'inline-block'); document.querySelector('.main-content').style.marginLeft = saved ? '80px' : '250px'; }
        else { s.classList.remove('collapsed'); document.querySelectorAll('.nav-text').forEach(t => t.style.display = 'inline-block'); document.querySelector('.main-content').style.marginLeft = '0'; }
    }
    
    function showProfileModal() { new bootstrap.Modal(document.getElementById('profileModal')).show(); }
    
    function confirmLogout() {
        const modal = bootstrap.Modal.getInstance(document.getElementById('profileModal'));
        if (modal) modal.hide();
        Swal.fire({ title: 'Are you sure?', text: 'You will be logged out', icon: 'question', showCancelButton: true, confirmButtonColor: '#07d826', confirmButtonText: 'Yes, logout' }).then((result) => { if (result.isConfirmed) { localStorage.removeItem('sidebarCollapsed'); window.location.href = '../logout.php'; } });
    }
    
    function logout() { confirmLogout(); }
    
    function setActiveMobileNav() {
        const mobileNav = document.getElementById('mobileNav');
        if (!mobileNav) return;

        const currentPage = window.location.pathname.split('/').pop();

        mobileNav.querySelectorAll('.nav-link, .dropdown-item').forEach(function(item) {
            item.classList.remove('active', 'has-active');
        });

        mobileNav.querySelectorAll('.dropdown-item').forEach(function(item) {
            const href = item.getAttribute('href');
            if (href && href === currentPage) {
                item.classList.add('active');

                const parentDropdown = item.closest('.dropdown-more');
                if (parentDropdown) {
                    const parentBtn = parentDropdown.querySelector('.more-btn');
                    if (parentBtn) parentBtn.classList.add('has-active');
                }
            }
        });

        mobileNav.querySelectorAll('.nav-link:not(.more-btn):not(.logout-btn)').forEach(function(item) {
            const href = item.getAttribute('href');
            if (href && href === currentPage) {
                item.classList.add('active');
            }
        });
    }

    function initMobileNav() {
        const mobileNav = document.getElementById('mobileNav');
        if (!mobileNav) return;

        mobileNav.style.display = window.innerWidth <= 992 ? 'block' : 'none';
        setActiveMobileNav();
    }
    
    document.addEventListener('DOMContentLoaded', function() {
        initializeSidebar();
        initMobileNav();

        document.addEventListener('click', function(e) {
            if (!e.target.closest('.mobile-nav')) {
                closeAllMobileDropdowns();
            }
        });

        document.querySelectorAll('.mobile-nav .dropdown-item').forEach(function(item) {
            item.addEventListener('click', closeAllMobileDropdowns);
        });

        document.getElementById('mobileToggleBtn')?.addEventListener('click', (e) => { e.stopPropagation(); toggleSidebar(); });
        document.getElementById('desktopToggleBtn')?.addEventListener('click', (e) => { e.stopPropagation(); toggleSidebar(); });
        document.querySelectorAll('.sidebar .nav-link').forEach(l => { l.addEventListener('click', () => { if (window.innerWidth <= 992) closeMobileSidebar(); }); });
        window.addEventListener('resize', function() { handleSidebarResize(); initMobileNav(); });
        showProductsLoading('Loading products...', 'Preparing product prices, UoM, stock, and images.');
        
        // Check if customer is locked and get their price level
        const lockedCustomerId = document.getElementById('lockedCustomerId')?.value || '';
        const lockedPriceLevelField = document.getElementById('lockedPriceLevel');
        const customerPriceLevel = (lockedPriceLevelField && lockedPriceLevelField.value) ? lockedPriceLevelField.value : 'Standard';
        
        // If customer is locked with a non-standard price level, use that price level
        const initialPriceLevel = (lockedCustomerId && customerPriceLevel !== 'Standard') ? customerPriceLevel : 'Standard';
        
        // Load products with the correct price level from the start
        const promises = inventory.map(product => loadProductUnitTypes(product.id, initialPriceLevel));
        Promise.all(promises)
            .then(() => loadAllProductImages())
            .then(() => {
                hideProductsLoading();
                renderProducts();
                updateCartBadge();
                setupSearch();
                if (lockedCustomerId) loadCustomerDiscount(lockedCustomerId);
            })
            .catch(() => {
                hideProductsLoading();
                renderProducts();
                updateCartBadge();
                setupSearch();
            });
        
        // Setup delivery type listeners
        setupDeliveryTypeListeners();
        setupPickupPaymentListeners();
        updateCustomerAddressInputVisibility();
        updateDeliveryAddressDisplay();
        ['customerAddressInput', 'deliveryAddressInput'].forEach(function(inputId) {
            const addressInput = document.getElementById(inputId);
            if (addressInput) {
                addressInput.addEventListener('input', function() {
                    const typedAddress = normalizeAddressValue(this.value);
                    const pairedInputId = inputId === 'customerAddressInput' ? 'deliveryAddressInput' : 'customerAddressInput';
                    const pairedInput = document.getElementById(pairedInputId);
                    if (pairedInput && pairedInput.value !== this.value) pairedInput.value = this.value;
                    document.getElementById('reviewAddress').textContent = typedAddress || '-';
                    updateDeliveryAddressDisplay();
                });
            }
        });
    });
    
    // ============= ORDER DETAILS FUNCTIONS =============
    let currentOrderIdFromOrderProduct = null;

    // Helper function for status badge class
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

    // Function to view order details in modal
    function viewOrderFromOrderProduct(orderId) {
        currentOrderIdFromOrderProduct = orderId;
        const modalElement = document.getElementById('orderDetailsModal');
        const modal = new bootstrap.Modal(modalElement);
        const orderDetailsContent = document.getElementById('orderDetailsContent');
        const printBtn = document.getElementById('printOrderFromDetails');
        const cancelBtn = document.getElementById('cancelOrderBtn');

        if (printBtn) printBtn.style.display = 'none';
        if (cancelBtn) cancelBtn.style.display = 'none';

        if (orderDetailsContent) {
            orderDetailsContent.innerHTML = `
                <div class="loading-state text-center py-5">
                    <div class="spinner-border text-success" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="mt-3 text-muted mb-0">Loading order details...</p>
                </div>
            `;
        }

        modal.show();

        fetch('orderproduct.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: 'action=get_order_details&order_id=' + encodeURIComponent(orderId)
        })
        .then(response => {
            if (!response.ok) throw new Error('Network response was not ok');
            return response.json();
        })
        .then(data => {
            if (!data.success) {
                orderDetailsContent.innerHTML = `
                    <div class="error-state text-center py-5">
                        <i class="bi bi-exclamation-triangle fs-1 text-danger"></i>
                        <p class="mt-3 mb-0">${escapeHtml(data.message || 'Error loading order details.')}</p>
                    </div>
                `;
                if (printBtn) printBtn.style.display = 'none';
                if (cancelBtn) cancelBtn.style.display = 'none';
                return;
            }

            const order = data.order || {};
            const items = Array.isArray(data.items) ? data.items : [];
            const invoice = data.invoice || null;
            const documents = data.documents || {};

            const formattedDate = order.order_date
                ? new Date(order.order_date).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' })
                : 'N/A';
            const statusBadge = getOrderStatusBadgeClass(order.order_status || 'pending');
            const statusText = getOrderStatusText(order.order_status || 'pending');
            const encodedBy = order.created_by || order.encoded_by || 'System';
            const documentType = order.document_type || 'SO';
            const atwNo = String(order.atw_no || '').trim();
            const gatepassNo = String(order.gatepass_no || '').trim();
            const orderSINumber = String(order.si_number || (invoice && invoice.si_number) || '').trim();
            const registeredBusinessName = String(order.registered_business_name || (invoice && invoice.registered_business_name) || '').trim();
            const tin = String(order.tin || (invoice && invoice.tin) || '').trim();
            const businessAddress = String(order.business_address || (invoice && invoice.business_address) || '').trim();

            let rowsHtml = '';
            let computedSubtotal = 0;
            let computedGrandTotal = 0;
            let computedDiscount = 0;

            if (items.length > 0) {
                items.forEach(item => {
                    const qty = parseFloat(item.quantity_ordered || item.quantity || 0) || 0;
                    const grossPrice = parseFloat(item.gross_price || item.unit_price || item.net_price || 0) || 0;
                    const netPrice = parseFloat(item.net_price || item.unit_price || grossPrice || 0) || 0;
                    const lineSubtotal = qty * grossPrice;
                    const lineTotal = parseFloat(item.order_amount || item.line_total || (qty * netPrice) || 0) || 0;
                    const savedLineDiscount = parseFloat(item.total_discount || item.discount_amount || 0) || 0;
                    const lineDiscount = savedLineDiscount > 0 ? savedLineDiscount : Math.max(0, lineSubtotal - lineTotal);

                    computedSubtotal += lineSubtotal;
                    computedGrandTotal += lineTotal;
                    computedDiscount += lineDiscount;

                    rowsHtml += `
                        <tr style="border-bottom: 1px solid #f0f0f0;">
                            <td style="padding: 10px 12px; vertical-align: middle;">${escapeHtml(item.item_code || '')}</td>
                            <td style="padding: 10px 12px; vertical-align: middle;"><strong>${escapeHtml(item.item_name || '')}</strong></td>
                            <td style="padding: 10px 12px; vertical-align: middle; text-align: center;">${escapeHtml(item.unit_type || 'N/A')}</td>
                            <td style="padding: 10px 12px; vertical-align: middle; text-align: center;">${Number.isInteger(qty) ? parseInt(qty) : qty}</td>
                            <td style="padding: 10px 12px; vertical-align: middle; text-align: right; color: #6c757d; font-weight: 500;">${formatCurrency(grossPrice)}</td>
                            <td style="padding: 10px 12px; vertical-align: middle; text-align: right; font-weight: 700; color: #212529;">${formatCurrency(lineSubtotal)}</td>
                        </tr>
                    `;
                });
            } else {
                rowsHtml = `<tr><td colspan="6" class="text-center text-muted py-4">No items found for this order</td></tr>`;
            }

            const headerDiscount = parseFloat(order.total_discount_amount || order.discount_amount || 0) || 0;
            const headerGrandTotal = parseFloat(order.total_amount || order.order_amount || 0) || 0;
            const finalDiscount = headerDiscount > 0 ? headerDiscount : computedDiscount;
            const finalGrandTotal = headerGrandTotal > 0 ? headerGrandTotal : computedGrandTotal;
            const finalSubtotal = (parseFloat(order.order_subtotal || 0) || computedSubtotal || (finalGrandTotal + finalDiscount));

            const siDetailsHtml = (orderSINumber || registeredBusinessName || tin || businessAddress) ? `
                <div class="card mb-3">
                    <div class="card-header bg-light"><h6 class="mb-0 fw-bold"><i class="bi bi-receipt-cutoff me-2"></i>SI Details</h6></div>
                    <div class="card-body">
                        <table class="table table-sm table-borderless mb-0">
                            ${orderSINumber ? `<tr><td width="40%">SI Number:</td><td><strong>${escapeHtml(orderSINumber)}</strong></td></tr>` : ''}
                            ${registeredBusinessName ? `<tr><td width="40%">Registered Business Name:</td><td><strong>${escapeHtml(registeredBusinessName)}</strong></td></tr>` : ''}
                            ${tin ? `<tr><td width="40%">TIN:</td><td>${escapeHtml(tin)}</td></tr>` : ''}
                            ${businessAddress ? `<tr><td width="40%">Business Address:</td><td>${escapeHtml(businessAddress)}</td></tr>` : ''}
                        </table>
                    </div>
                </div>
            ` : '';

            const outstandingAmount = parseFloat(order.outstanding_balance_amount || 0) || 0;
            const outstandingRequired = parseInt(order.outstanding_balance_approval_required || 0) === 1;
            const outstandingApproved = parseInt(order.outstanding_balance_approved || 0) === 1;
            const outstandingApprovedBy = String(order.outstanding_balance_approved_by_name || '').trim();
            const outstandingApprovedAt = order.outstanding_balance_approved_at ? new Date(order.outstanding_balance_approved_at).toLocaleString('en-US', { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' }) : '';
            const outstandingReason = String(order.outstanding_balance_reason || '').trim();
            const outstandingApprovalHtml = (outstandingRequired || outstandingAmount > 0) ? `
                <div class="card mb-3">
                    <div class="card-header bg-light"><h6 class="mb-0 fw-bold"><i class="bi bi-exclamation-triangle me-2"></i>Outstanding Balance Approval</h6></div>
                    <div class="card-body">
                        <table class="table table-sm table-borderless mb-0">
                            <tr><td width="40%">Outstanding Balance:</td><td><strong class="text-danger">${formatCurrency(outstandingAmount)}</strong></td></tr>
                            <tr><td width="40%">Approval Status:</td><td><span class="badge ${outstandingApproved ? 'bg-success' : 'bg-warning text-dark'}">${outstandingApproved ? 'Approved' : 'Required'}</span></td></tr>
                            ${outstandingApprovedBy ? `<tr><td width="40%">Approved By:</td><td><strong>${escapeHtml(outstandingApprovedBy)}</strong></td></tr>` : ''}
                            ${outstandingApprovedAt ? `<tr><td width="40%">Approved Date:</td><td>${escapeHtml(outstandingApprovedAt)}</td></tr>` : ''}
                            ${outstandingReason ? `<tr><td width="40%">Reason:</td><td>${escapeHtml(outstandingReason)}</td></tr>` : ''}
                        </table>
                    </div>
                </div>
            ` : '';

            const orderInfoHtml = `
                <div class="row mt-2">
                    <div class="col-md-12">
                        <div class="card mb-3">
                            <div class="card-header bg-light"><h6 class="mb-0 fw-bold"><i class="bi bi-receipt me-2"></i>Order Information</h6></div>
                            <div class="card-body">
                                <table class="table table-sm table-borderless mb-0">
                                    <tr><td width="40%">Order Number:</td><td><strong>${escapeHtml(order.so_number || '')}</strong></td></tr>
                                    <tr><td width="40%">Document Type:</td><td><strong>${escapeHtml(documentType)}</strong></td></tr>
                                    ${orderSINumber ? `<tr><td width="40%">SI Number:</td><td><strong>${escapeHtml(orderSINumber)}</strong></td></tr>` : ''}
                                    <tr><td width="40%">ATW No.:</td><td><strong>${escapeHtml(atwNo || '-')}</strong></td></tr>
                                    <tr><td width="40%">Gatepass No.:</td><td><strong>${escapeHtml(gatepassNo || '-')}</strong></td></tr>
                                    <tr><td width="40%">Order Date:</td><td>${formattedDate}</td></tr>
                                    <tr><td width="40%">Customer:</td><td><strong>${escapeHtml(order.customer_name || 'N/A')}</strong></td></tr>
                                    ${order.address ? `<tr><td width="40%">Address:</td><td>${escapeHtml(order.address)}</td></tr>` : ''}
                                    ${order.phone_number ? `<tr><td width="40%">Contact:</td><td>${escapeHtml(order.phone_number)}</td></tr>` : ''}
                                    ${order.branch_name ? `<tr><td width="40%">Branch:</td><td><span class="badge bg-info">${escapeHtml(order.branch_name)}</span></td></tr>` : ''}
                                    <tr><td width="40%">Order Status:</td><td><span class="badge ${statusBadge}">${statusText}</span></td></tr>
                                    <tr><td width="40%">Encoded By:</td><td><strong>${escapeHtml(encodedBy)}</strong></td></tr>
                                    ${invoice && invoice.invoice_status ? `<tr><td width="40%">Payment Status:</td><td><span class="badge ${invoice.invoice_status === 'paid' ? 'bg-success' : (invoice.invoice_status === 'overdue' ? 'bg-danger' : 'bg-warning text-dark')}">${escapeHtml(invoice.invoice_status === 'overdue' ? 'Overdue' : invoice.invoice_status)}</span></td></tr>` : ''}
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            `;

            const orderSummaryHtml = `
                <div class="row">
                    <div class="col-md-12">
                        <div class="card mb-3">
                            <div class="card-header bg-light"><h6 class="mb-0 fw-bold"><i class="bi bi-calculator me-2"></i>Order Summary</h6></div>
                            <div class="card-body">
                                <table class="table table-sm table-borderless mb-0">
                                    <tr><td width="40%">Total Items:</td><td>${order.total_items || items.length || 0}</td></tr>
                                    <tr><td width="40%">Total Quantity:</td><td>${order.total_quantity || items.reduce((sum, item) => sum + (parseFloat(item.quantity_ordered || 0) || 0), 0)}</td></tr>
                                    <tr><td width="40%">Subtotal:</td><td>${formatCurrency(finalSubtotal)}</td></tr>
                                    <tr><td width="40%">Discount:</td><td class="text-danger">-${formatCurrency(finalDiscount)}</td></tr>
                                    <tr><td width="40%">Grand Total:</td><td class="fw-bold fs-5 text-success">${formatCurrency(finalGrandTotal)}</td></tr>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            `;

            let documentsHtml = '';
            if (documents.pick_list_number || documents.driver_name || documents.vehicle || documents.trip_ticket_number || order.assigned_driver) {
                documentsHtml = `
                    <div class="card mb-3">
                        <div class="card-header bg-light"><h6 class="mb-0 fw-bold"><i class="bi bi-file-earmark-text me-2"></i>Generated Documents</h6></div>
                        <div class="card-body">
                            <div class="row g-2">
                                ${documents.pick_list_number ? `<div class="col-md-6"><div class="border rounded p-2 bg-light"><small class="text-muted">Pick List</small><br><strong>${escapeHtml(documents.pick_list_number)}</strong></div></div>` : ''}
                                ${(documents.driver_name || order.assigned_driver) ? `<div class="col-md-6"><div class="border rounded p-2 bg-light"><small class="text-muted">Assigned Driver</small><br><strong><i class="bi bi-person-badge"></i> ${escapeHtml(documents.driver_name || order.assigned_driver)}</strong></div></div>` : ''}
                                ${documents.vehicle ? `<div class="col-md-6"><div class="border rounded p-2 bg-light"><small class="text-muted">Assigned Vehicle</small><br><strong><i class="bi bi-truck"></i> ${escapeHtml(documents.vehicle)}</strong></div></div>` : ''}
                                ${documents.trip_ticket_number ? `<div class="col-md-6"><div class="border rounded p-2 bg-light"><small class="text-muted">Trip Ticket</small><br><strong>${escapeHtml(documents.trip_ticket_number)}</strong></div></div>` : ''}
                            </div>
                        </div>
                    </div>
                `;
            }

            const orderItemsHtml = `
                <div class="card mt-3">
                    <div class="card-header bg-light"><h6 class="mb-0 fw-bold"><i class="bi bi-box-seam me-2"></i>Order Items</h6></div>
                    <div class="card-body p-0">
                        <div class="table-responsive" style="max-height: 420px; overflow-y: auto;">
                            <table style="width: 100%; border-collapse: collapse; font-size: 0.85rem; background: white; border: 1px solid #dee2e6; border-radius: 8px; overflow: hidden;">
                                <thead>
                                    <tr style="background-color: #f8f9fa;">
                                        <th style="padding: 10px 12px; font-weight: 600; border-bottom: 1px solid #dee2e6; text-align: left;">Code</th>
                                        <th style="padding: 10px 12px; font-weight: 600; border-bottom: 1px solid #dee2e6; text-align: left;">Product</th>
                                        <th style="padding: 10px 12px; font-weight: 600; border-bottom: 1px solid #dee2e6; text-align: center;">Unit</th>
                                        <th style="padding: 10px 12px; font-weight: 600; border-bottom: 1px solid #dee2e6; text-align: center;">Qty</th>
                                        <th style="padding: 10px 12px; font-weight: 600; border-bottom: 1px solid #dee2e6; text-align: right;">Unit Price</th>
                                        <th style="padding: 10px 12px; font-weight: 600; border-bottom: 1px solid #dee2e6; text-align: right;">Total</th>
                                    </tr>
                                </thead>
                                <tbody>${rowsHtml}</tbody>
                                <tfoot>
                                    <tr style="background-color: #ffffff; border-top: 2px solid #dee2e6;">
                                        <td colspan="5" style="padding: 9px 12px; text-align: right; font-weight: 700; color: #212529;">SUBTOTAL</td>
                                        <td style="padding: 9px 12px; text-align: right; font-weight: 700; color: #212529;">${formatCurrency(finalSubtotal)}</td>
                                    </tr>
                                    <tr style="background-color: #ffffff;">
                                        <td colspan="5" style="padding: 9px 12px; text-align: right; font-weight: 700; color: #dc3545;">DISCOUNT</td>
                                        <td style="padding: 9px 12px; text-align: right; font-weight: 700; color: #dc3545;">-${formatCurrency(finalDiscount)}</td>
                                    </tr>
                                    <tr style="background-color: #f8f9fa; border-top: 1px solid #dee2e6;">
                                        <td colspan="5" style="padding: 10px 12px; text-align: right; font-weight: 800; color: #047857;">GRAND TOTAL</td>
                                        <td style="padding: 10px 12px; text-align: right; font-weight: 800; color: #047857; font-size: 1rem;">${formatCurrency(finalGrandTotal)}</td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>
            `;

            orderDetailsContent.innerHTML = siDetailsHtml + orderInfoHtml + orderSummaryHtml + documentsHtml + orderItemsHtml;

            if (printBtn) printBtn.style.display = 'inline-block';
            if (cancelBtn) cancelBtn.style.display = (String(order.order_status || '').toLowerCase() === 'pending') ? 'inline-block' : 'none';
        })
        .catch(error => {
            console.error('Error fetching order details:', error);
            if (orderDetailsContent) {
                orderDetailsContent.innerHTML = `
                    <div class="error-state text-center py-5">
                        <i class="bi bi-exclamation-triangle fs-1 text-danger"></i>
                        <p class="mt-3 mb-0">Failed to load order details. Please try again.</p>
                    </div>
                `;
            }
            if (printBtn) printBtn.style.display = 'none';
            if (cancelBtn) cancelBtn.style.display = 'none';
        });
    }

    function printOrderFromOrderProduct() {
        if (currentOrderIdFromOrderProduct) {
            printSingleOrderFromOrderProduct(currentOrderIdFromOrderProduct);
            const modal = bootstrap.Modal.getInstance(document.getElementById('orderDetailsModal'));
            if (modal) modal.hide();
        }
    }

    function printSingleOrderFromOrderProduct(orderId) {
        const printBtn = document.querySelector('#printOrderFromDetails');
        if (printBtn) {
            printBtn.innerHTML = '<i class="bi bi-printer"></i> Printing...';
            printBtn.disabled = true;
        }
        
        const formData = new FormData();
        formData.append('action', 'print_order');
        formData.append('so_id', orderId);
        
        fetch('orderproduct.php', { method: 'POST', body: formData })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const htmlContent = generateSingleOrderHTML(data.order, data.items, data.driver);
                    const iframe = document.getElementById('printFrame') || createPrintFrame();
                    const iframeDoc = iframe.contentWindow.document;
                    iframeDoc.open();
                    iframeDoc.write(htmlContent);
                    iframeDoc.close();
                    setTimeout(() => iframe.contentWindow.print(), 250);
                } else {
                    Swal.fire('Error', 'Failed to load order details', 'error');
                }
                if (printBtn) {
                    printBtn.innerHTML = '<i class="bi bi-printer"></i> Print Order';
                    printBtn.disabled = false;
                }
            })
            .catch(error => {
                Swal.fire('Error', 'Network error: ' + error.message, 'error');
                if (printBtn) {
                    printBtn.innerHTML = '<i class="bi bi-printer"></i> Print Order';
                    printBtn.disabled = false;
                }
            });
    }

    // Cancel order from modal
    function cancelOrderFromOrderProduct() {
        if (!currentOrderIdFromOrderProduct) {
            Swal.fire('Error', 'No order selected', 'error');
            return;
        }

        Swal.fire({
            title: 'Cancel Order?',
            text: 'This action cannot be undone.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc3545',
            confirmButtonText: 'Yes, cancel it'
        }).then((result) => {
            if (result.isConfirmed) {
                const cancelBtn = document.getElementById('cancelOrderBtn');
                if (cancelBtn) cancelBtn.disabled = true;

                fetch('orderproduct.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
                    body: 'action=cancel_order&order_id=' + currentOrderIdFromOrderProduct
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        Swal.fire('Success!', 'Order cancelled successfully', 'success');
                        const modal = bootstrap.Modal.getInstance(document.getElementById('orderDetailsModal'));
                        if (modal) modal.hide();
                        currentOrderIdFromOrderProduct = null;
                    } else {
                        Swal.fire('Error', data.message || 'Failed to cancel order', 'error');
                        if (cancelBtn) cancelBtn.disabled = false;
                    }
                })
                .catch(error => {
                    Swal.fire('Error', 'Error cancelling order: ' + error.message, 'error');
                    if (cancelBtn) cancelBtn.disabled = false;
                });
            }
        });
    }

    function createPrintFrame() {
        let iframe = document.getElementById('printFrame');
        if (!iframe) {
            iframe = document.createElement('iframe');
            iframe.id = 'printFrame';
            iframe.style.position = 'absolute';
            iframe.style.left = '-9999px';
            iframe.style.top = '-9999px';
            document.body.appendChild(iframe);
        }
        return iframe;
    }

    function generateSingleOrderHTML(order, items, driver) {
        let itemsHtml = '';
        let totalQty = 0;
        let computedTotal = 0;

        const formatReceiptNumber = (value) => {
            const number = parseFloat(value) || 0;
            return number.toLocaleString('en-US', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        };

        if (items && items.length > 0) {
            itemsHtml = items.map(item => {
                const qty = parseFloat(item.quantity_ordered) || 0;
                const price = parseFloat(item.unit_price) || 0;
                const subtotal = qty * price;
                totalQty += qty;
                computedTotal += subtotal;

                return `
                    <tr>
                        <td colspan="4" class="item-name">${escapeHtml(item.item_name || '')}</td>
                    </tr>
                    <tr class="item-details">
                        <td></td>
                        <td class="text-center">${qty.toLocaleString('en-US')}</td>
                        <td class="text-right">${formatReceiptNumber(price)}</td>
                        <td class="text-right">${formatReceiptNumber(subtotal)}</td>
                    </tr>
                `;
            }).join('');
        } else {
            itemsHtml = '<tr><td colspan="4" style="text-align:center;padding:8px 0;">No items</td></tr>';
        }

        const createdByName = order
            ? (order.first_name ? `${order.first_name} ${order.last_name || ''}`.trim() : 'Branch Admin')
            : 'Branch Admin';

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
        const rawSINumber = order ? String(order.si_number || '').trim() : '';
        const formattedSINumber = rawSINumber ? escapeHtml(rawSINumber.toUpperCase().startsWith('SI:') ? rawSINumber : 'SI:' + rawSINumber) : '';
        const siReceiptLine = formattedSINumber ? `<div class="receipt-no"> ${formattedSINumber}</div>` : '';
        const atwNo = order ? escapeHtml(String(order.atw_no || '').trim()) : '';
        const gatepassNo = order ? escapeHtml(String(order.gatepass_no || '').trim()) : '';
        const atwGatepassLine = (atwNo || gatepassNo)
            ? `<div class="receipt-no" style="display:flex;justify-content:center;gap:8px;"><span>ATW: ${atwNo || '-'}</span><span>Gatepass: ${gatepassNo || '-'}</span></div>`
            : '';
        const orderStatus = order ? getOrderStatusText(order.order_status || '') : '';
        const dbTotal = order ? parseFloat(order.order_total || order.total_amount || 0) : 0;
        const totalAmount = dbTotal > 0 ? dbTotal : computedTotal;
        const driverName = driver
            ? escapeHtml(driver.driver_name || 'No Driver')
            : escapeHtml(order?.assigned_driver && order.assigned_driver !== 'No Driver' ? order.assigned_driver : 'No Driver');
        const vehicleText = driver && (driver.vehicle_type || driver.plate_number || driver.vehicle_plate_number)
            ? escapeHtml(`${driver.vehicle_type || ''}${driver.vehicle_type && (driver.plate_number || driver.vehicle_plate_number) ? ' - ' : ''}${driver.plate_number || driver.vehicle_plate_number || ''}`)
            : '';
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
.company-name {
    font-size: 16px;
    font-weight: 700;
    letter-spacing: 0.5px;
}
.receipt-title {
    font-size: 12px;
    font-weight: 700;
    text-transform: uppercase;
}
.receipt-no {
    font-size: 9px;
    margin-top: 1px;
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
@media print {
    html, body {
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
                <div class="receipt-title">Sales Order Receipt</div>
                <div class="receipt-no">${receiptNumber}</div>
                ${siReceiptLine}
                ${atwGatepassLine}
                <div>${printDate}</div>
            </div>

            <div class="receipt-info">
                <div class="info-line"><span class="info-label">Date:</span> <span class="info-value">${formattedDate}</span></div>
                <div class="info-line"><span class="info-label">Status:</span> <span class="info-value">${orderStatus}</span></div>
                <div class="info-line"><span class="info-label">Customer:</span> <span class="info-value">${customerName}</span></div>
                <div class="info-line"><span class="info-label">Driver:</span> <span class="info-value">${driverName}</span></div>
                ${vehicleText ? `<div class="info-line"><span class="info-label">Vehicle:</span> <span class="info-value">${vehicleText}</span></div>` : ''}
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

            <div class="receipt-total">TOTAL: ${formatCurrency(totalAmount)}</div>

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
    // ===== EXPAND CUSTOMER DROPDOWN ON PAGE LOAD =====
function expandCustomerDropdown() {
    // Get the customer menu dropdown
    const customerMenu = document.getElementById('customerMenu');
    const customerNavLink = document.querySelector('.sidebar .nav-link[href="#"]');
    
    // Find the Customer nav link (the one with onclick="toggleSidebarDropdown(event, 'customerMenu')")
    const allNavLinks = document.querySelectorAll('.sidebar .nav-link');
    let customerLink = null;
    
    for (let link of allNavLinks) {
        const onclickAttr = link.getAttribute('onclick');
        if (onclickAttr && onclickAttr.includes('customerMenu')) {
            customerLink = link;
            break;
        }
    }
    
    if (customerMenu && !customerMenu.classList.contains('show')) {
        // Add show class to expand the dropdown
        customerMenu.classList.add('show');
        
        // Rotate the arrow icon if it exists
        if (customerLink) {
            const arrow = customerLink.querySelector('.dropdown-arrow');
            if (arrow) {
                arrow.style.transform = 'translateY(-50%) rotate(180deg)';
            }
        }
    }
}

// Call this after DOM is loaded and after sidebar initialization
document.addEventListener('DOMContentLoaded', function() {
    // Your existing DOMContentLoaded code...
    // Add this line at the end of your DOMContentLoaded function:
    setTimeout(expandCustomerDropdown, 150);
});

</script>
</body>
</html>
