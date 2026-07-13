<?php
ob_start();

require_once '../config/database.php';
require_once '../config/session_handler.php';

// Get current user info and branch context
$user_id = $_SESSION['user_id'] ?? 0;
$user_name = isset($_SESSION['first_name']) ? $_SESSION['first_name'] . ' ' . $_SESSION['last_name'] : 'Motorpool';
$user_role = $_SESSION['role'] ?? 'motorpool';
$branch_id = $_SESSION['branch_id'] ?? 0;
$view_all_branches = $_SESSION['view_all_branches'] ?? false;


// ===== MOTORPOOL DATA SCOPE FIX =====
// Motorpool uses shared tables, so scope by users.role='motorpool' instead of branch_id alone.
$view_all_branches = false;
function mpDataScopeUserSubquery() { return "SELECT user_id FROM users WHERE role = 'motorpool'"; }
function mpCustomerScope($alias = 'c') { $p = $alias !== '' ? $alias . '.' : ''; return " AND {$p}created_by IN (" . mpDataScopeUserSubquery() . ")"; }
function mpSalesOrderScope($alias = 'so') { $p = $alias !== '' ? $alias . '.' : ''; return " AND {$p}created_by IN (" . mpDataScopeUserSubquery() . ")"; }
function mpPaymentScope($alias = 'p') { $p = $alias !== '' ? $alias . '.' : ''; return " AND {$p}created_by IN (" . mpDataScopeUserSubquery() . ")"; }
function mpInvoiceScope($invoiceAlias = 'i', $salesOrderAlias = 'so', $customerAlias = 'c') {
    $so = $salesOrderAlias !== '' ? $salesOrderAlias . '.' : '';
    $c = $customerAlias !== '' ? $customerAlias . '.' : '';
    return " AND (({$so}created_by IS NOT NULL AND {$so}created_by IN (" . mpDataScopeUserSubquery() . ")) OR ({$c}created_by IS NOT NULL AND {$c}created_by IN (" . mpDataScopeUserSubquery() . ")))";
}
// ===== END MOTORPOOL DATA SCOPE FIX =====
// Helper: ensure banks table exists and fetch active ONLINE TRANSFER sub accounts for dropdown
function createBanksTableIfNeeded($conn) {
    $conn->query("CREATE TABLE IF NOT EXISTS `banks` (
        `bank_id` int(11) NOT NULL AUTO_INCREMENT,
        `branch_id` int(11) NOT NULL DEFAULT 0,
        `bank_name` varchar(150) NOT NULL,
        `account_name` varchar(150) DEFAULT NULL,
        `account_number` varchar(100) DEFAULT NULL,
        `bank_branch` varchar(150) DEFAULT NULL,
        `status` enum('active','inactive') NOT NULL DEFAULT 'active',
        `parent_bank_id` int(11) DEFAULT NULL,
        `created_by` int(11) NOT NULL DEFAULT 0,
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`bank_id`),
        KEY `branch_id` (`branch_id`),
        KEY `status` (`status`),
        KEY `parent_bank_id` (`parent_bank_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    $col = $conn->query("SHOW COLUMNS FROM `banks` LIKE 'parent_bank_id'");
    if (!$col || $col->num_rows === 0) {
        @$conn->query("ALTER TABLE `banks` ADD COLUMN `parent_bank_id` int(11) DEFAULT NULL AFTER `status`");
        @$conn->query("ALTER TABLE `banks` ADD INDEX `parent_bank_id` (`parent_bank_id`)");
    }
}

function createBankPaymentMethodsTableIfNeeded($conn) {
    $conn->query("CREATE TABLE IF NOT EXISTS `bank_payment_methods` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `bank_id` int(11) NOT NULL,
        `payment_method` enum('check','online_transfer','cash') NOT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `unique_bank_method` (`bank_id`,`payment_method`),
        KEY `bank_id` (`bank_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
}

function getOnlineTransferSubAccountsForDropdown($conn, $view_all_branches, $branch_id) {
    createBanksTableIfNeeded($conn);
    createBankPaymentMethodsTableIfNeeded($conn);

    $sql = "SELECT DISTINCT
                b.bank_id,
                b.bank_name,
                b.account_name,
                b.account_number,
                b.bank_branch,
                b.parent_bank_id,
                pb.bank_name AS parent_bank_name
            FROM banks b
            LEFT JOIN banks pb ON pb.bank_id = b.parent_bank_id
            INNER JOIN bank_payment_methods bpm
                ON bpm.bank_id = b.bank_id
               AND bpm.payment_method = 'online_transfer'
            WHERE b.status = 'active'
              AND b.parent_bank_id IS NOT NULL";
    if (!$view_all_branches && $branch_id > 0) $sql .= " AND (b.branch_id = ? OR b.branch_id = 0)";
    $sql .= " ORDER BY COALESCE(pb.bank_name, b.bank_name) ASC, b.bank_name ASC, b.account_number ASC";

    $rows = [];
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        if (!$view_all_branches && $branch_id > 0) $stmt->bind_param('i', $branch_id);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }

    foreach ($rows as &$row) {
        $label_parts = [];
        if (!empty($row['parent_bank_name'])) $label_parts[] = $row['parent_bank_name'];
        if (!empty($row['bank_name'])) $label_parts[] = $row['bank_name'];
        $label = implode(' / ', $label_parts);
        if (!empty($row['account_number'])) $label .= ' - ' . $row['account_number'];
        $row['display_name'] = trim($label) !== '' ? $label : ($row['bank_name'] ?? '');
    }
    unset($row);

    return $rows;
}

$registered_banks = getOnlineTransferSubAccountsForDropdown($conn, $view_all_branches, $branch_id);
$banks_json = json_encode($registered_banks);


// ========== COLLECTION ASSIGNMENT HELPERS ==========
function collectionTableExists($conn, $table) {
    $table = $conn->real_escape_string($table);
    $res = $conn->query("SHOW TABLES LIKE '{$table}'");
    return $res && $res->num_rows > 0;
}

function collectionColumnExists($conn, $table, $column) {
    if (!collectionTableExists($conn, $table)) return false;
    $table = $conn->real_escape_string($table);
    $column = $conn->real_escape_string($column);
    $res = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
    return $res && $res->num_rows > 0;
}

function ensureCollectionAssignmentsTable($conn) {
    $conn->query("CREATE TABLE IF NOT EXISTS `collection_assignments` (
        `assignment_id` int(11) NOT NULL AUTO_INCREMENT,
        `invoice_id` int(11) NOT NULL,
        `customer_id` int(11) NOT NULL,
        `branch_id` int(11) NOT NULL DEFAULT 0,
        `assigned_user_id` int(11) NOT NULL,
        `assigned_by` int(11) NOT NULL DEFAULT 0,
        `collection_date` date DEFAULT NULL,
        `notes` text DEFAULT NULL,
        `status` enum('active','completed','cancelled') NOT NULL DEFAULT 'active',
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
        PRIMARY KEY (`assignment_id`),
        KEY `invoice_id` (`invoice_id`),
        KEY `customer_id` (`customer_id`),
        KEY `assigned_user_id` (`assigned_user_id`),
        KEY `branch_id` (`branch_id`),
        KEY `status` (`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    $safe_alters = [
        'invoice_id' => "ALTER TABLE collection_assignments ADD COLUMN invoice_id int(11) NOT NULL DEFAULT 0 AFTER assignment_id",
        'customer_id' => "ALTER TABLE collection_assignments ADD COLUMN customer_id int(11) NOT NULL DEFAULT 0 AFTER invoice_id",
        'branch_id' => "ALTER TABLE collection_assignments ADD COLUMN branch_id int(11) NOT NULL DEFAULT 0 AFTER customer_id",
        'assigned_user_id' => "ALTER TABLE collection_assignments ADD COLUMN assigned_user_id int(11) NOT NULL DEFAULT 0 AFTER branch_id",
        'assigned_by' => "ALTER TABLE collection_assignments ADD COLUMN assigned_by int(11) NOT NULL DEFAULT 0 AFTER assigned_user_id",
        'collection_date' => "ALTER TABLE collection_assignments ADD COLUMN collection_date date DEFAULT NULL AFTER assigned_by",
        'notes' => "ALTER TABLE collection_assignments ADD COLUMN notes text DEFAULT NULL AFTER collection_date",
        'status' => "ALTER TABLE collection_assignments ADD COLUMN status enum('active','completed','cancelled') NOT NULL DEFAULT 'active' AFTER notes",
        'created_at' => "ALTER TABLE collection_assignments ADD COLUMN created_at timestamp NOT NULL DEFAULT current_timestamp() AFTER status",
        'updated_at' => "ALTER TABLE collection_assignments ADD COLUMN updated_at timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp() AFTER created_at"
    ];

    foreach ($safe_alters as $col => $sql) {
        if (!collectionColumnExists($conn, 'collection_assignments', $col)) {
            @$conn->query($sql);
        }
    }

    @$conn->query("ALTER TABLE collection_assignments MODIFY COLUMN status VARCHAR(30) NOT NULL DEFAULT 'active'");
}

// ========== COLLECTION REMITTANCE TABLE ==========
function ensureCollectionRemittancesTable($conn) {
    $conn->query("CREATE TABLE IF NOT EXISTS `collection_remittances` (
        `remittance_id` int(11) NOT NULL AUTO_INCREMENT,
        `invoice_id` int(11) NOT NULL,
        `customer_id` int(11) NOT NULL,
        `branch_id` int(11) NOT NULL DEFAULT 0,
        `collector_user_id` int(11) NOT NULL,
        `payment_method` enum('cash','check','online_transfer') NOT NULL,
        `amount` decimal(12,2) NOT NULL,
        `collection_date` datetime NOT NULL,
        `remittance_date` datetime NOT NULL,
        `reference_number` varchar(100) DEFAULT NULL,
        `check_date` date DEFAULT NULL,
        `bank_name` varchar(100) DEFAULT NULL,
        `bank_branch` varchar(100) DEFAULT NULL,
        `check_number` varchar(50) DEFAULT NULL,
        `cash_tendered` decimal(12,2) DEFAULT NULL,
        `cash_change` decimal(12,2) DEFAULT NULL,
        `notes` text DEFAULT NULL,
        `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
        `approved_by` int(11) DEFAULT NULL,
        `approved_at` datetime DEFAULT NULL,
        `rejection_reason` text DEFAULT NULL,
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`remittance_id`),
        KEY `invoice_id` (`invoice_id`),
        KEY `customer_id` (`customer_id`),
        KEY `collector_user_id` (`collector_user_id`),
        KEY `branch_id` (`branch_id`),
        KEY `status` (`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
}


// ========== COLLECTION RECORDS COMPATIBILITY TABLE ==========
// Sales/sales_collections.php saves collections here first, then changes status to remitted.
// Branch Admin must read those remitted records and approve them into payments.
function ensureCollectionRecordsTable($conn) {
    $conn->query("CREATE TABLE IF NOT EXISTS `collection_records` (
        `record_id` INT AUTO_INCREMENT PRIMARY KEY,
        `invoice_id` INT NOT NULL,
        `customer_id` INT NOT NULL,
        `branch_id` INT NOT NULL DEFAULT 0,
        `collector_user_id` INT NOT NULL,
        `payment_method` VARCHAR(30) NOT NULL,
        `amount` DECIMAL(12,2) NOT NULL,
        `collection_date` DATETIME NOT NULL,
        `reference_number` VARCHAR(100) DEFAULT NULL,
        `check_date` DATE DEFAULT NULL,
        `bank_name` VARCHAR(150) DEFAULT NULL,
        `bank_branch` VARCHAR(150) DEFAULT NULL,
        `check_number` VARCHAR(100) DEFAULT NULL,
        `cash_tendered` DECIMAL(12,2) DEFAULT NULL,
        `cash_change` DECIMAL(12,2) DEFAULT NULL,
        `attachment_path` VARCHAR(500) DEFAULT NULL,
        `attachment_name` VARCHAR(255) DEFAULT NULL,
        `notes` TEXT DEFAULT NULL,
        `status` VARCHAR(30) NOT NULL DEFAULT 'collected',
        `remitted_at` DATETIME DEFAULT NULL,
        `approved_by` INT DEFAULT NULL,
        `approved_at` DATETIME DEFAULT NULL,
        `rejection_reason` TEXT DEFAULT NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY `invoice_id` (`invoice_id`),
        KEY `collector_user_id` (`collector_user_id`),
        KEY `branch_id` (`branch_id`),
        KEY `status` (`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    $safe_alters = [
        'approved_by' => "ALTER TABLE collection_records ADD COLUMN approved_by INT DEFAULT NULL AFTER remitted_at",
        'approved_at' => "ALTER TABLE collection_records ADD COLUMN approved_at DATETIME DEFAULT NULL AFTER approved_by",
        'rejection_reason' => "ALTER TABLE collection_records ADD COLUMN rejection_reason TEXT DEFAULT NULL AFTER approved_at",
        'attachment_path' => "ALTER TABLE collection_records ADD COLUMN attachment_path VARCHAR(500) DEFAULT NULL AFTER cash_change",
        'attachment_name' => "ALTER TABLE collection_records ADD COLUMN attachment_name VARCHAR(255) DEFAULT NULL AFTER attachment_path",
        'notes' => "ALTER TABLE collection_records ADD COLUMN notes TEXT DEFAULT NULL AFTER attachment_name",
        'remitted_at' => "ALTER TABLE collection_records ADD COLUMN remitted_at DATETIME DEFAULT NULL AFTER status"
    ];
    foreach ($safe_alters as $col => $sql) {
        if (!collectionColumnExists($conn, 'collection_records', $col)) {
            @$conn->query($sql);
        }
    }

    // Convert ENUM to VARCHAR so approved/rejected statuses from Branch Admin will not fail.
    @$conn->query("ALTER TABLE collection_records MODIFY COLUMN status VARCHAR(30) NOT NULL DEFAULT 'collected'");
}


function ensureCollectionInvoiceReturnsTable($conn) {
    $conn->query("CREATE TABLE IF NOT EXISTS `collection_invoice_returns` (
        `return_id` INT AUTO_INCREMENT PRIMARY KEY,
        `assignment_id` INT NOT NULL DEFAULT 0,
        `invoice_id` INT NOT NULL,
        `customer_id` INT NOT NULL DEFAULT 0,
        `branch_id` INT NOT NULL DEFAULT 0,
        `returned_by` INT NOT NULL,
        `returned_to` INT DEFAULT NULL,
        `return_reason` TEXT DEFAULT NULL,
        `attachment_path` VARCHAR(500) DEFAULT NULL,
        `attachment_name` VARCHAR(255) DEFAULT NULL,
        `status` VARCHAR(30) NOT NULL DEFAULT 'returned',
        `reviewed_by` INT DEFAULT NULL,
        `reviewed_at` DATETIME DEFAULT NULL,
        `rejection_reason` TEXT DEFAULT NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY `assignment_id` (`assignment_id`),
        KEY `invoice_id` (`invoice_id`),
        KEY `returned_by` (`returned_by`),
        KEY `branch_id` (`branch_id`),
        KEY `status` (`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    $safe_alters = [
        'attachment_path' => "ALTER TABLE collection_invoice_returns ADD COLUMN attachment_path VARCHAR(500) DEFAULT NULL AFTER return_reason",
        'attachment_name' => "ALTER TABLE collection_invoice_returns ADD COLUMN attachment_name VARCHAR(255) DEFAULT NULL AFTER attachment_path",
        'status' => "ALTER TABLE collection_invoice_returns ADD COLUMN status VARCHAR(30) NOT NULL DEFAULT 'returned'",
        'reviewed_by' => "ALTER TABLE collection_invoice_returns ADD COLUMN reviewed_by INT DEFAULT NULL AFTER status",
        'reviewed_at' => "ALTER TABLE collection_invoice_returns ADD COLUMN reviewed_at DATETIME DEFAULT NULL AFTER reviewed_by",
        'rejection_reason' => "ALTER TABLE collection_invoice_returns ADD COLUMN rejection_reason TEXT DEFAULT NULL AFTER reviewed_at",
        'created_at' => "ALTER TABLE collection_invoice_returns ADD COLUMN created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP"
    ];

    foreach ($safe_alters as $col => $sql) {
        if (!collectionColumnExists($conn, 'collection_invoice_returns', $col)) {
            @$conn->query($sql);
        }
    }
}

function getAssignableCollectorsForCollections($conn, $view_all_branches, $branch_id) {
    $branch_id = (int)$branch_id;
    $has_user_branch = collectionColumnExists($conn, 'users', 'branch_id');
    $has_user_driver_id = collectionColumnExists($conn, 'users', 'driver_id');
    $has_drivers_table = collectionTableExists($conn, 'drivers');
    $has_driver_branch = $has_drivers_table && collectionColumnExists($conn, 'drivers', 'branch_id');

    $driver_join = ($has_user_driver_id && $has_driver_branch)
        ? "LEFT JOIN drivers d ON d.driver_id = u.driver_id"
        : "";

    $sql = "SELECT DISTINCT u.user_id, u.first_name, u.last_name, u.role
            FROM users u
            $driver_join
            WHERE u.status = 'active'
              AND u.role IN ('sales', 'delivery')";

    $needBranchParam = false;
    if ($branch_id > 0 && ($has_user_branch || $has_driver_branch)) {
        $branchParts = [];
        if ($has_user_branch) {
            $branchParts[] = "u.branch_id = ?";
        }
        if ($has_driver_branch && $has_user_driver_id) {
            $branchParts[] = "d.branch_id = ?";
        }
        if (!empty($branchParts)) {
            $sql .= " AND (" . implode(' OR ', $branchParts) . ")";
            $needBranchParam = true;
        }
    }

    $sql .= " ORDER BY FIELD(u.role, 'sales', 'delivery'), u.first_name ASC, u.last_name ASC";

    $rows = [];
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        if ($needBranchParam) {
            if ($has_user_branch && $has_driver_branch && $has_user_driver_id) {
                $stmt->bind_param('ii', $branch_id, $branch_id);
            } else {
                $stmt->bind_param('i', $branch_id);
            }
        }
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res) $rows = $res->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
    return $rows;
}

function enrichInvoicesWithCollectorAssignments($conn, &$invoices) {
    ensureCollectionAssignmentsTable($conn);
    if (!is_array($invoices) || count($invoices) === 0) return;

    foreach ($invoices as &$invoice) {
        $invoice['assigned_to_name'] = '';
        $invoice['assigned_to_role'] = '';
        $invoice['assigned_user_id'] = '';
        $invoice['collection_date'] = '';
        $invoice_id = (int)($invoice['invoice_id'] ?? 0);
        if ($invoice_id <= 0) continue;

        $sql = "SELECT ca.assigned_user_id, ca.collection_date,
                       u.first_name, u.last_name, u.role
                FROM collection_assignments ca
                LEFT JOIN users u ON u.user_id = ca.assigned_user_id
                WHERE ca.invoice_id = ?
                  AND ca.status IN ('active','assigned')
                ORDER BY ca.assignment_id DESC
                LIMIT 1";
        $stmt = $conn->prepare($sql);
        if (!$stmt) continue;
        $stmt->bind_param('i', $invoice_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row) {
            $name = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
            $invoice['assigned_to_name'] = $name;
            $invoice['assigned_to_role'] = $row['role'] ?? '';
            $invoice['assigned_user_id'] = $row['assigned_user_id'] ?? '';
            $invoice['collection_date'] = $row['collection_date'] ?? '';
        }
    }
    unset($invoice);
}


function enrichInvoicesWithBeginningBalanceAttachments($conn, &$invoices) {
    ensureBeginningBalanceAttachmentsTable($conn);
    if (!is_array($invoices) || count($invoices) === 0) return;

    $invoiceIds = [];
    foreach ($invoices as $invoice) {
        $invoice_id = (int)($invoice['invoice_id'] ?? 0);
        if ($invoice_id > 0) $invoiceIds[$invoice_id] = $invoice_id;
    }

    foreach ($invoices as &$invoice) {
        $invoice['attachments'] = [];
    }
    unset($invoice);

    if (empty($invoiceIds)) return;

    $placeholders = implode(',', array_fill(0, count($invoiceIds), '?'));
    $types = str_repeat('i', count($invoiceIds));
    $values = array_values($invoiceIds);

    $sql = "SELECT attachment_id, invoice_id, file_name, stored_name, file_path, file_type, file_size, created_at
            FROM beginning_balance_attachments
            WHERE invoice_id IN ($placeholders)
            ORDER BY attachment_id ASC";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return;
    collectionBindParamsDynamic($stmt, $types, $values);
    $stmt->execute();
    $res = $stmt->get_result();

    $byInvoice = [];
    while ($row = $res->fetch_assoc()) {
        $iid = (int)($row['invoice_id'] ?? 0);
        if (!isset($byInvoice[$iid])) $byInvoice[$iid] = [];
        $byInvoice[$iid][] = $row;
    }
    $stmt->close();

    foreach ($invoices as &$invoice) {
        $iid = (int)($invoice['invoice_id'] ?? 0);
        $invoice['attachments'] = $byInvoice[$iid] ?? [];
    }
    unset($invoice);
}

function saveCollectionAssignment($conn, $invoice_id, $customer_id, $branch_id, $assigned_user_id, $assigned_by, $collection_date = '') {
    ensureCollectionAssignmentsTable($conn);

    $invoice_id = (int)$invoice_id;
    $customer_id = (int)$customer_id;
    $branch_id = (int)$branch_id;
    $assigned_user_id = (int)$assigned_user_id;
    $assigned_by = (int)$assigned_by;
    $collection_date = !empty($collection_date) ? $collection_date : date('Y-m-d');

    if ($invoice_id <= 0 || $customer_id <= 0 || $assigned_user_id <= 0) {
        throw new Exception('Invalid collection assignment data');
    }

    $cancel = $conn->prepare("UPDATE collection_assignments SET status = 'cancelled', updated_at = NOW() WHERE invoice_id = ? AND status IN ('active','assigned')");
    if ($cancel) {
        $cancel->bind_param('i', $invoice_id);
        $cancel->execute();
        $cancel->close();
    }

    $stmt = $conn->prepare("INSERT INTO collection_assignments (invoice_id, customer_id, branch_id, assigned_user_id, assigned_by, collection_date, status) VALUES (?, ?, ?, ?, ?, ?, 'active')");
    if (!$stmt) throw new Exception('Failed to prepare collection assignment: ' . $conn->error);
    $stmt->bind_param('iiiiis', $invoice_id, $customer_id, $branch_id, $assigned_user_id, $assigned_by, $collection_date);
    if (!$stmt->execute()) throw new Exception('Failed to save collection assignment: ' . $stmt->error);
    $stmt->close();
}

function saveMultipleCollectionAssignments($conn, $invoice_ids, $assigned_user_id, $assigned_by, $collection_date = '') {
    ensureCollectionAssignmentsTable($conn);
    
    $assigned_user_id = (int)$assigned_user_id;
    $assigned_by = (int)$assigned_by;
    $collection_date = !empty($collection_date) ? $collection_date : date('Y-m-d');
    
    if ($assigned_user_id <= 0) {
        throw new Exception('Please select a collector');
    }
    
    $conn->begin_transaction();
    
    try {
        foreach ($invoice_ids as $invoice_data) {
            $invoice_id = (int)$invoice_data['invoice_id'];
            $customer_id = (int)$invoice_data['customer_id'];
            $branch_id = (int)$invoice_data['branch_id'];
            
            if ($invoice_id <= 0) continue;
            
            $cancel = $conn->prepare("UPDATE collection_assignments SET status = 'cancelled', updated_at = NOW() WHERE invoice_id = ? AND status IN ('active','assigned')");
            if ($cancel) {
                $cancel->bind_param('i', $invoice_id);
                $cancel->execute();
                $cancel->close();
            }
            
            $stmt = $conn->prepare("INSERT INTO collection_assignments (invoice_id, customer_id, branch_id, assigned_user_id, assigned_by, collection_date, status) VALUES (?, ?, ?, ?, ?, ?, 'active')");
            if (!$stmt) throw new Exception('Failed to prepare collection assignment: ' . $conn->error);
            $stmt->bind_param('iiiiis', $invoice_id, $customer_id, $branch_id, $assigned_user_id, $assigned_by, $collection_date);
            if (!$stmt->execute()) throw new Exception('Failed to save collection assignment: ' . $stmt->error);
            $stmt->close();
        }
        
        $conn->commit();
        return true;
    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }
}

ensureCollectionAssignmentsTable($conn);
ensureCollectionRemittancesTable($conn);
ensureCollectionRecordsTable($conn);
ensureCollectionInvoiceReturnsTable($conn);
ensureBeginningBalanceAttachmentsTable($conn);
ensureSalesInvoiceFieldsForCollections($conn);
$assignable_collectors = getAssignableCollectorsForCollections($conn, $view_all_branches, $branch_id);

// Disable error output to prevent HTML in JSON
error_reporting(0);
ini_set('display_errors', 0);

// Check if payments table exists, if not create it
$payments_table_exists = false;
$check_payments = $conn->query("SHOW TABLES LIKE 'payments'");
if ($check_payments && $check_payments->num_rows > 0) {
    $payments_table_exists = true;
} else {
    $create_sql = "CREATE TABLE IF NOT EXISTS `payments` (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    if ($conn->query($create_sql)) $payments_table_exists = true;
}

// Check if branch_id column exists in customers table
$customers_branch_column_exists = false;
$check_customers_column = $conn->query("SHOW COLUMNS FROM customers LIKE 'branch_id'");
if ($check_customers_column && $check_customers_column->num_rows > 0) {
    $customers_branch_column_exists = true;
}

$customers_group_column_exists = collectionColumnExists($conn, 'customers', 'customer_group');

$customers_branch_condition = "AND created_by IN (SELECT user_id FROM users WHERE role = 'motorpool')";

// Get customers with pending/overdue invoices
$customer_group_select_for_customer_list = $customers_group_column_exists
    ? "COALESCE(NULLIF(TRIM(c.customer_group), ''), 'Ungrouped') AS customer_group"
    : "'Ungrouped' AS customer_group";

$all_customers_query = "SELECT DISTINCT c.customer_id, c.customer_name, c.credit_limit, c.credit_used, {$customer_group_select_for_customer_list}
                        FROM customers c
                        WHERE c.status = 'active' AND c.created_by IN (SELECT user_id FROM users WHERE role = 'motorpool')
                        AND EXISTS (
                            SELECT 1
                            FROM invoices i
                            LEFT JOIN (
                                SELECT invoice_id, COALESCE(SUM(amount), 0) AS total_paid
                                FROM payments
                                WHERE (status IS NULL OR status = 'completed') AND created_by IN (SELECT user_id FROM users WHERE role = 'motorpool')
                                GROUP BY invoice_id
                            ) pay ON pay.invoice_id = i.invoice_id
                            WHERE i.customer_id = c.customer_id
                              AND (
                                  CASE
                                      WHEN COALESCE(i.balance, 0) > 0 AND COALESCE(pay.total_paid, 0) <= 0
                                          THEN COALESCE(i.balance, 0)
                                      ELSE GREATEST(COALESCE(i.total_amount, 0) - COALESCE(pay.total_paid, 0), 0)
                                  END
                              ) > 0.009
                              AND (
                                  i.status IS NULL
                                  OR TRIM(i.status) = ''
                                  OR LOWER(TRIM(i.status)) NOT IN ('paid','completed','cancelled','canceled','void','voided','failed')
                              )
                        )
                        $customers_branch_condition
                        ORDER BY c.customer_name";
$customers_result = $conn->query($all_customers_query);
$all_customers = $customers_result ? $customers_result->fetch_all(MYSQLI_ASSOC) : [];


// Customers registered under the current branch for Beginning Balance / existing utang entry
$branch_customer_group_select = $customers_group_column_exists
    ? "customer_group,"
    : "NULL AS customer_group,";

$branch_customers_query = "SELECT customer_id, customer_name, store_name, customer_code, {$branch_customer_group_select} credit_limit, credit_used
                           FROM customers
                           WHERE status = 'active'
                           $customers_branch_condition
                           ORDER BY customer_name ASC";
$branch_customers_result = $conn->query($branch_customers_query);
$branch_customers = $branch_customers_result ? $branch_customers_result->fetch_all(MYSQLI_ASSOC) : [];

function recalcCustomerCreditUsed($conn, $customer_id) {
    $sql = "SELECT COALESCE(SUM(GREATEST(i.total_amount - COALESCE(p.total_paid, 0), 0)), 0) AS total_unpaid
            FROM invoices i
            LEFT JOIN (
                SELECT invoice_id, COALESCE(SUM(amount), 0) AS total_paid
                FROM payments
                WHERE (status IS NULL OR status = 'completed') AND created_by IN (SELECT user_id FROM users WHERE role = 'motorpool')
                GROUP BY invoice_id
            ) p ON i.invoice_id = p.invoice_id
            WHERE i.customer_id = ?
            AND (
                i.status IS NULL
                OR TRIM(i.status) = ''
                OR i.status IN ('pending', 'overdue')
            )";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return 0;
    $stmt->bind_param("i", $customer_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res->fetch_assoc();
    $unpaid = floatval($row['total_unpaid'] ?? 0);
    $stmt->close();
    $update = "UPDATE customers SET credit_used = ? WHERE customer_id = ?";
    $upd_stmt = $conn->prepare($update);
    if ($upd_stmt) {
        $upd_stmt->bind_param("di", $unpaid, $customer_id);
        $upd_stmt->execute();
        $upd_stmt->close();
    }
    return $unpaid;
}

function collectionsGetCompletedPaymentsTotal($conn, $invoice_id) {
    $invoice_id = (int)$invoice_id;
    if ($invoice_id <= 0 || !collectionTableExists($conn, 'payments')) return 0.0;

    $stmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) AS total_paid FROM payments WHERE invoice_id = ? AND created_by IN (SELECT user_id FROM users WHERE role = 'motorpool') AND (status IS NULL OR status = 'completed')");
    if (!$stmt) return 0.0;
    $stmt->bind_param('i', $invoice_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (float)($row['total_paid'] ?? 0);
}

function collectionsSyncInvoicePaymentTotals($conn, $invoice_id, $invoice_total = null, $fallback_status = 'pending') {
    $invoice_id = (int)$invoice_id;
    if ($invoice_id <= 0) return ['paid' => 0.0, 'balance' => 0.0, 'status' => 'pending'];

    if ($invoice_total === null) {
        $stmt = $conn->prepare("SELECT COALESCE(total_amount, 0) AS total_amount, COALESCE(status, 'pending') AS status FROM invoices WHERE invoice_id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $invoice_id);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $invoice_total = (float)($row['total_amount'] ?? 0);
            $fallback_status = (string)($row['status'] ?? $fallback_status);
        } else {
            $invoice_total = 0.0;
        }
    }

    $paid = min((float)$invoice_total, collectionsGetCompletedPaymentsTotal($conn, $invoice_id));
    $balance = max((float)$invoice_total - $paid, 0);
    $status = ($balance <= 0.009) ? 'paid' : ((strtolower(trim((string)$fallback_status)) === 'overdue') ? 'overdue' : 'pending');

    $upd = $conn->prepare("UPDATE invoices SET amount_paid = ?, balance = ?, status = ? WHERE invoice_id = ?");
    if ($upd) {
        $upd->bind_param('ddsi', $paid, $balance, $status, $invoice_id);
        $upd->execute();
        $upd->close();
    }

    return ['paid' => $paid, 'balance' => $balance, 'status' => $status];
}

function enrichInvoicesWithPaymentBalances($conn, &$invoices) {
    if (!is_array($invoices) || count($invoices) === 0) return;

    foreach ($invoices as &$invoice) {
        $invoice_id = (int)($invoice['invoice_id'] ?? 0);
        // Important: some SELECT queries already return total_amount as the remaining balance.
        // Always use original_total_amount when available, so partial payments are not deducted twice.
        $original_total = isset($invoice['original_total_amount'])
            ? (float)$invoice['original_total_amount']
            : (float)($invoice['total_amount'] ?? 0);
        $paid_amount = collectionsGetCompletedPaymentsTotal($conn, $invoice_id);
        $balance_amount = max($original_total - $paid_amount, 0);

        $invoice['original_total_amount'] = $original_total;
        $invoice['paid_amount'] = $paid_amount;
        $invoice['balance_amount'] = $balance_amount;
        $invoice['stored_balance'] = $balance_amount;
        $invoice['total_amount'] = $balance_amount;
    }
    unset($invoice);
}


function ensureBeginningBalanceAttachmentsTable($conn) {
    $conn->query("CREATE TABLE IF NOT EXISTS `beginning_balance_attachments` (
        `attachment_id` INT NOT NULL AUTO_INCREMENT,
        `invoice_id` INT NOT NULL,
        `so_id` INT DEFAULT NULL,
        `customer_id` INT NOT NULL,
        `branch_id` INT NOT NULL DEFAULT 0,
        `file_name` VARCHAR(255) NOT NULL,
        `stored_name` VARCHAR(255) NOT NULL,
        `file_path` VARCHAR(500) NOT NULL,
        `file_type` VARCHAR(120) DEFAULT NULL,
        `file_size` INT DEFAULT 0,
        `uploaded_by` INT NOT NULL DEFAULT 0,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`attachment_id`),
        KEY `invoice_id` (`invoice_id`),
        KEY `so_id` (`so_id`),
        KEY `customer_id` (`customer_id`),
        KEY `branch_id` (`branch_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
}

function saveBeginningBalanceAttachments($conn, $invoice_id, $so_id, $customer_id, $branch_id, $uploaded_by) {
    ensureBeginningBalanceAttachmentsTable($conn);

    if (empty($_FILES['attachments']) || empty($_FILES['attachments']['name'])) {
        return 0;
    }

    // Files are saved here:
    // Project root / uploads / beginning_balances
    // Example if this file is BranchAdmin/collections.php:
    // ../uploads/beginning_balances/
    $project_root = realpath(dirname(__DIR__));
    if ($project_root === false) {
        $project_root = dirname(__DIR__);
    }

    $upload_dir = rtrim($project_root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'beginning_balances' . DIRECTORY_SEPARATOR;
    $public_dir = '../uploads/beginning_balances/';

    if (!is_dir($upload_dir)) {
        if (!@mkdir($upload_dir, 0775, true) && !is_dir($upload_dir)) {
            throw new Exception('Unable to create attachment upload folder: uploads/beginning_balances');
        }
    }

    @chmod($upload_dir, 0775);

    if (!is_writable($upload_dir)) {
        throw new Exception('Attachment upload folder is not writable: uploads/beginning_balances');
    }

    $allowed_ext = ['jpg','jpeg','png','gif','webp','pdf','doc','docx','xls','xlsx','csv','txt'];
    $saved_count = 0;

    // Normalize $_FILES so it works whether one file input or many file inputs are submitted.
    $names = $_FILES['attachments']['name'];
    $tmp_names = $_FILES['attachments']['tmp_name'];
    $errors = $_FILES['attachments']['error'];
    $sizes = $_FILES['attachments']['size'];
    $types = $_FILES['attachments']['type'];

    if (!is_array($names)) {
        $names = [$names];
        $tmp_names = [$tmp_names];
        $errors = [$errors];
        $sizes = [$sizes];
        $types = [$types];
    }

    for ($i = 0; $i < count($names); $i++) {
        $upload_error = $errors[$i] ?? UPLOAD_ERR_NO_FILE;
        $original_name = basename((string)($names[$i] ?? ''));

        if ($upload_error === UPLOAD_ERR_NO_FILE || $original_name === '') {
            continue;
        }

        if ($upload_error !== UPLOAD_ERR_OK) {
            throw new Exception('Failed to upload attachment: ' . $original_name . ' (error code: ' . $upload_error . ')');
        }

        $tmp_file = $tmp_names[$i] ?? '';
        if ($tmp_file === '' || !is_uploaded_file($tmp_file)) {
            throw new Exception('Invalid uploaded attachment: ' . $original_name);
        }

        $file_size = (int)($sizes[$i] ?? 0);
        if ($file_size > 10 * 1024 * 1024) {
            throw new Exception('Attachment is too large. Maximum allowed size is 10MB per file: ' . $original_name);
        }

        $ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
        if ($ext === '' || !in_array($ext, $allowed_ext, true)) {
            throw new Exception('Invalid attachment type: ' . $original_name);
        }

        $safe_original_name = preg_replace('/[^A-Za-z0-9._\- ]+/', '_', $original_name);
        if ($safe_original_name === '' || $safe_original_name === null) {
            $safe_original_name = 'attachment.' . $ext;
        }

        $stored_name = 'bb_' . (int)$invoice_id . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
        $target_path = $upload_dir . $stored_name;

        if (!move_uploaded_file($tmp_file, $target_path)) {
            throw new Exception('Unable to save attachment to uploads/beginning_balances: ' . $original_name);
        }

        @chmod($target_path, 0664);

        $public_path = $public_dir . $stored_name;
        $file_type = $types[$i] ?? null;

        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $detected_type = finfo_file($finfo, $target_path);
                if (!empty($detected_type)) {
                    $file_type = $detected_type;
                }
                finfo_close($finfo);
            }
        }

        $stmt = $conn->prepare("INSERT INTO beginning_balance_attachments
            (invoice_id, so_id, customer_id, branch_id, file_name, stored_name, file_path, file_type, file_size, uploaded_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        if (!$stmt) {
            @unlink($target_path);
            throw new Exception('Failed to prepare attachment insert: ' . $conn->error);
        }

        $stmt->bind_param(
            'iiiissssii',
            $invoice_id,
            $so_id,
            $customer_id,
            $branch_id,
            $safe_original_name,
            $stored_name,
            $public_path,
            $file_type,
            $file_size,
            $uploaded_by
        );

        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            @unlink($target_path);
            throw new Exception('Failed to save attachment record: ' . $error);
        }

        $stmt->close();
        $saved_count++;
    }

    return $saved_count;
}


function collectionIndexExists($conn, $table, $index_name) {
    if (!collectionTableExists($conn, $table)) return false;

    $table = $conn->real_escape_string($table);
    $index_name = $conn->real_escape_string($index_name);
    $res = $conn->query("SHOW INDEX FROM `{$table}` WHERE Key_name = '{$index_name}'");

    return $res && $res->num_rows > 0;
}

function ensureSalesInvoiceFieldsForCollections($conn) {
    // Safe compatibility only. No indexes are created here, so existing database indexes will not duplicate.
    $safeColumns = [
        'si_number' => "VARCHAR(100) NULL",
        'registered_business_name' => "VARCHAR(255) NULL",
        'tin' => "VARCHAR(50) NULL",
        'business_address' => "TEXT NULL",
        'remarks' => "TEXT NULL"
    ];

    foreach (['sales_orders', 'invoices'] as $table) {
        if (!collectionTableExists($conn, $table)) continue;
        foreach ($safeColumns as $column => $definition) {
            if (!collectionColumnExists($conn, $table, $column)) {
                @ $conn->query("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
            }
        }

        // Existing DB cleanup: move old tin_number data into tin, then remove tin_number.
        if (collectionColumnExists($conn, $table, 'tin_number')) {
            if (collectionColumnExists($conn, $table, 'tin')) {
                @ $conn->query("UPDATE `{$table}` SET `tin` = `tin_number` WHERE (`tin` IS NULL OR TRIM(`tin`) = '') AND `tin_number` IS NOT NULL AND TRIM(`tin_number`) <> ''");
            }
            @ $conn->query("ALTER TABLE `{$table}` DROP COLUMN `tin_number`");
        }
    }

    return true;
}

function collectionBindParamsDynamic($stmt, $types, &$values) {
    if ($types === '' || empty($values)) return true;
    $refs = [];
    $refs[] = $types;
    foreach ($values as $key => &$value) {
        $refs[] = &$value;
    }
    return call_user_func_array([$stmt, 'bind_param'], $refs);
}

function collectionInsertDynamic($conn, $table, $columns, $values, $types) {
    $safeColumns = [];
    foreach ($columns as $column) {
        $safeColumns[] = '`' . str_replace('`', '', $column) . '`';
    }
    $placeholders = implode(', ', array_fill(0, count($safeColumns), '?'));
    $sql = "INSERT INTO `{$table}` (" . implode(', ', $safeColumns) . ") VALUES ({$placeholders})";
    $stmt = $conn->prepare($sql);
    if (!$stmt) throw new Exception('Failed to prepare ' . $table . ' insert: ' . $conn->error);
    if (!collectionBindParamsDynamic($stmt, $types, $values)) {
        throw new Exception('Failed to bind ' . $table . ' insert values: ' . $stmt->error);
    }
    if (!$stmt->execute()) throw new Exception('Failed to save ' . $table . ': ' . $stmt->error);
    $insert_id = (int)$conn->insert_id;
    $stmt->close();
    return $insert_id;
}


// ========== RECEIVE PAYMENT ACCOUNTING HELPERS ==========
// Receive Payment should post once only:
//   Debit  Undeposited Funds
//   Credit Receivable Account / Accounts Receivable
function collectionsEnsureReceivePaymentAccountingTables($conn) {
    $conn->query("CREATE TABLE IF NOT EXISTS `journal_entries` (
        `journal_id` INT(11) NOT NULL AUTO_INCREMENT,
        `entry_no` VARCHAR(100) NOT NULL,
        `journal_date` DATE NOT NULL,
        `attachment_path` TEXT DEFAULT NULL,
        `branch_id` INT(11) NOT NULL DEFAULT 0,
        `created_by` INT(11) NOT NULL DEFAULT 0,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`journal_id`),
        KEY `entry_no` (`entry_no`),
        KEY `branch_id` (`branch_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    $conn->query("CREATE TABLE IF NOT EXISTS `journal_entry_details` (
        `detail_id` INT(11) NOT NULL AUTO_INCREMENT,
        `journal_id` INT(11) NOT NULL,
        `account_id` INT(11) NOT NULL DEFAULT 0,
        `account_title` VARCHAR(255) NOT NULL,
        `debit` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
        `credit` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
        `memo` TEXT DEFAULT NULL,
        `counterparty` VARCHAR(255) DEFAULT NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`detail_id`),
        KEY `journal_id` (`journal_id`),
        KEY `account_id` (`account_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    $conn->query("CREATE TABLE IF NOT EXISTS `chart_account_transactions` (
        `transaction_id` INT(11) NOT NULL AUTO_INCREMENT,
        `account_id` INT(11) NOT NULL DEFAULT 0,
        `branch_id` INT(11) NOT NULL DEFAULT 0,
        `transaction_date` DATE DEFAULT NULL,
        `transaction_type` VARCHAR(100) DEFAULT NULL,
        `transaction_no` VARCHAR(100) DEFAULT NULL,
        `reference_no` VARCHAR(100) DEFAULT NULL,
        `memo` TEXT DEFAULT NULL,
        `account_name` VARCHAR(255) DEFAULT NULL,
        `debit` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
        `credit` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
        `balance_after` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
        `source_table` VARCHAR(100) DEFAULT NULL,
        `source_id` INT(11) DEFAULT NULL,
        `counterparty` VARCHAR(255) DEFAULT NULL,
        `created_by` INT(11) NOT NULL DEFAULT 0,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`transaction_id`),
        KEY `account_id` (`account_id`),
        KEY `branch_id` (`branch_id`),
        KEY `source_table_id` (`source_table`, `source_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    $extra = [
        'payments' => [
            'source_table' => "ALTER TABLE `payments` ADD COLUMN `source_table` VARCHAR(100) DEFAULT NULL AFTER `created_by`",
            'source_id' => "ALTER TABLE `payments` ADD COLUMN `source_id` INT(11) DEFAULT NULL AFTER `source_table`"
        ],
        'chart_account_transactions' => [
            'counterparty' => "ALTER TABLE `chart_account_transactions` ADD COLUMN `counterparty` VARCHAR(255) DEFAULT NULL AFTER `source_id`"
        ]
    ];
    foreach ($extra as $table => $cols) {
        if (!collectionTableExists($conn, $table)) continue;
        foreach ($cols as $col => $sql) {
            if (!collectionColumnExists($conn, $table, $col)) @ $conn->query($sql);
        }
    }
}

function collectionsGetCustomerNameForAccounting($conn, $customer_id) {
    $customer_id = (int)$customer_id;
    if ($customer_id <= 0 || !collectionTableExists($conn, 'customers')) return '';
    $stmt = $conn->prepare("SELECT customer_name FROM customers WHERE customer_id = ? LIMIT 1");
    if (!$stmt) return '';
    $stmt->bind_param('i', $customer_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return trim((string)($row['customer_name'] ?? ''));
}


function collectionsGetInvoiceNumberForAccounting($conn, $invoice_id) {
    $invoice_id = (int)$invoice_id;
    if ($invoice_id <= 0 || !collectionTableExists($conn, 'invoices')) return '';

    $numberExprParts = [];
    foreach (['invoice_number', 'si_number'] as $col) {
        if (collectionColumnExists($conn, 'invoices', $col)) {
            $numberExprParts[] = "NULLIF(TRIM(`$col`), '')";
        }
    }

    if (!empty($numberExprParts)) {
        $numberExpr = 'COALESCE(' . implode(', ', $numberExprParts) . ', CONCAT(\'Invoice #\', invoice_id))';
    } else {
        $numberExpr = "CONCAT('Invoice #', invoice_id)";
    }

    $stmt = $conn->prepare("SELECT {$numberExpr} AS invoice_no FROM invoices WHERE invoice_id = ? LIMIT 1");
    if (!$stmt) return 'Invoice #' . $invoice_id;
    $stmt->bind_param('i', $invoice_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $invoiceNo = trim((string)($row['invoice_no'] ?? ''));
    return $invoiceNo !== '' ? $invoiceNo : ('Invoice #' . $invoice_id);
}

function collectionsRepairReceivePaymentInvoiceMemos($conn) {
    if (!collectionTableExists($conn, 'chart_account_transactions') || !collectionTableExists($conn, 'payments') || !collectionTableExists($conn, 'invoices')) {
        return;
    }
    collectionsEnsureReceivePaymentAccountingTables($conn);

    $invoiceNoParts = [];
    foreach (['invoice_number', 'si_number'] as $invoiceNoCol) {
        if (collectionColumnExists($conn, 'invoices', $invoiceNoCol)) {
            $invoiceNoParts[] = "NULLIF(TRIM(i.`$invoiceNoCol`), '')";
        }
    }
    $invoiceNoParts[] = "CONCAT('Invoice #', i.invoice_id)";
    $invoiceNoExpr = 'COALESCE(' . implode(', ', $invoiceNoParts) . ')';

    @ $conn->query("UPDATE chart_account_transactions cat
        JOIN payments p ON p.payment_id = cat.source_id
        JOIN invoices i ON i.invoice_id = p.invoice_id
        SET cat.memo = CONCAT('Receive Payment for Invoice ', {$invoiceNoExpr}),
            cat.reference_no = {$invoiceNoExpr}
        WHERE cat.source_table = 'payments'
          AND cat.transaction_type = 'Receive Payment'
          AND cat.source_id IS NOT NULL
          AND (cat.memo REGEXP 'Receive Payment for Invoice #[0-9]+$' OR cat.reference_no REGEXP '^Payment #[0-9]+$')");

    if (collectionTableExists($conn, 'journal_entries') && collectionTableExists($conn, 'journal_entry_details')) {
        @ $conn->query("UPDATE journal_entry_details jed
            JOIN journal_entries je ON je.journal_id = jed.journal_id
            JOIN chart_account_transactions cat ON cat.transaction_no = je.entry_no
                AND cat.source_table = 'payments'
                AND cat.transaction_type = 'Receive Payment'
                AND cat.source_id IS NOT NULL
            JOIN payments p ON p.payment_id = cat.source_id
            JOIN invoices i ON i.invoice_id = p.invoice_id
            SET jed.memo = CONCAT('Receive Payment for Invoice ', {$invoiceNoExpr})
            WHERE jed.memo REGEXP 'Receive Payment for Invoice #[0-9]+$'");
    }
}

function collectionsFindOrCreateAccount($conn, $titles, $type, $branch_id, $user_id) {
    if (!is_array($titles)) $titles = [$titles];
    $branch_id = (int)$branch_id;
    foreach ($titles as $title) {
        $title = trim((string)$title);
        if ($title === '') continue;
        $sql = "SELECT account_id, account_title, balance FROM chart_of_accounts WHERE status = 'active' AND account_title = ?";
        if ($branch_id > 0 && collectionColumnExists($conn, 'chart_of_accounts', 'branch_id')) {
            $sql .= " AND (branch_id = ? OR branch_id = 0 OR branch_id IS NULL) ORDER BY CASE WHEN branch_id = ? THEN 0 ELSE 1 END, account_id ASC LIMIT 1";
            $stmt = $conn->prepare($sql);
            if ($stmt) $stmt->bind_param('sii', $title, $branch_id, $branch_id);
        } else {
            $sql .= " ORDER BY account_id ASC LIMIT 1";
            $stmt = $conn->prepare($sql);
            if ($stmt) $stmt->bind_param('s', $title);
        }
        if (!$stmt) continue;
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) return $row;
    }

    $title = trim((string)$titles[0]);
    if ($title === '') throw new Exception('Missing account title for accounting entry.');
    $target_branch = $branch_id > 0 ? $branch_id : null;
    $description = 'Auto-created by Receive Payment accounting posting.';
    $balance = 0.00;
    $account_code = '';
    $parent = null;
    $stmt = $conn->prepare("INSERT INTO chart_of_accounts (branch_id, parent_account_id, account_code, account_title, account_type, description, balance, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    if (!$stmt) throw new Exception('Failed to create chart account: ' . $conn->error);
    $stmt->bind_param('iissssdi', $target_branch, $parent, $account_code, $title, $type, $description, $balance, $user_id);
    if (!$stmt->execute()) throw new Exception('Failed to create chart account ' . $title . ': ' . $stmt->error);
    $id = (int)$conn->insert_id;
    $stmt->close();
    return ['account_id' => $id, 'account_title' => $title, 'balance' => 0.00];
}

function collectionsNextReceivePaymentEntryNo($conn) {
    $prefix = 'RP-' . date('Ymd') . '-';
    $next = 1;
    foreach ([['journal_entries','entry_no'], ['chart_account_transactions','transaction_no']] as $src) {
        [$table, $col] = $src;
        if (!collectionTableExists($conn, $table) || !collectionColumnExists($conn, $table, $col)) continue;
        $like = $conn->real_escape_string($prefix . '%');
        $res = $conn->query("SELECT `$col` AS no FROM `$table` WHERE `$col` LIKE '$like' ORDER BY `$col` DESC LIMIT 1");
        if ($res && ($row = $res->fetch_assoc())) {
            $n = (int)substr((string)$row['no'], strlen($prefix));
            if ($n >= $next) $next = $n + 1;
        }
    }
    return $prefix . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
}

function collectionsUpdateAccountBalance($conn, $account_id, $debit, $credit) {
    $account_id = (int)$account_id;
    $debit = (float)$debit;
    $credit = (float)$credit;
    $stmt = $conn->prepare("UPDATE chart_of_accounts SET balance = COALESCE(balance, 0) + ? - ? WHERE account_id = ?");
    if (!$stmt) throw new Exception('Failed to prepare COA balance update: ' . $conn->error);
    $stmt->bind_param('ddi', $debit, $credit, $account_id);
    if (!$stmt->execute()) throw new Exception('Failed to update COA balance: ' . $stmt->error);
    $stmt->close();

    $stmt2 = $conn->prepare("SELECT COALESCE(balance, 0) AS balance FROM chart_of_accounts WHERE account_id = ? LIMIT 1");
    if (!$stmt2) return 0.00;
    $stmt2->bind_param('i', $account_id);
    $stmt2->execute();
    $row = $stmt2->get_result()->fetch_assoc();
    $stmt2->close();
    return (float)($row['balance'] ?? 0);
}



function collectionsGetCurrentChartAccountBalance($conn, $account_id) {
    $account_id = (int)$account_id;
    if ($account_id <= 0) return 0.00;
    $stmt = $conn->prepare("SELECT COALESCE(balance, 0) AS balance FROM chart_of_accounts WHERE account_id = ? LIMIT 1");
    if (!$stmt) return 0.00;
    $stmt->bind_param('i', $account_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (float)($row['balance'] ?? 0);
}


function collectionsRecalculateCollectionLinkedCoaBalances($conn, $branch_id, $user_id = 0) {
    // Collection source of truth:
    // Accounts Receivable = total invoice amount less completed payments.
    // Undeposited Funds = completed customer payments not yet moved by a deposit module.
    // This prevents old duplicate journal/COA postings from making AR negative.
    if (!collectionTableExists($conn, 'chart_of_accounts') || !collectionTableExists($conn, 'invoices')) return;

    $branch_id = (int)$branch_id;
    $user_id = (int)$user_id;

    $receivable = collectionsFindOrCreateAccount($conn, ['Accounts Receivable', 'Receivable Account'], 'Accounts Receivable', $branch_id, $user_id);
    $undeposited = collectionsFindOrCreateAccount($conn, ['Undeposited Funds'], 'Other Current Asset', $branch_id, $user_id);

    $invoiceBranchFilter = '';
    $paymentBranchJoin = '';
    $paymentBranchFilter = '';
    if ($branch_id > 0) {
        if (collectionColumnExists($conn, 'invoices', 'branch_id')) {
            $invoiceBranchFilter = " AND (i.branch_id = {$branch_id})";
        }
        if (collectionColumnExists($conn, 'payments', 'branch_id')) {
            $paymentBranchFilter = " AND (p.branch_id = {$branch_id} OR p.branch_id IS NULL OR p.branch_id = 0)";
        } elseif (collectionTableExists($conn, 'invoices') && collectionColumnExists($conn, 'invoices', 'branch_id')) {
            $paymentBranchJoin = " LEFT JOIN invoices pi ON pi.invoice_id = p.invoice_id";
            $paymentBranchFilter = " AND (pi.branch_id = {$branch_id} OR pi.branch_id IS NULL OR pi.branch_id = 0)";
        }
    }

    $arSql = "
        SELECT COALESCE(SUM(GREATEST(COALESCE(i.total_amount, 0) - COALESCE(pay.total_paid, 0), 0)), 0) AS ar_balance
        FROM invoices i
        LEFT JOIN (
            SELECT invoice_id, COALESCE(SUM(amount), 0) AS total_paid
            FROM payments
            WHERE status IS NULL OR status = 'completed'
            GROUP BY invoice_id
        ) pay ON pay.invoice_id = i.invoice_id
        WHERE (
            i.status IS NULL
            OR TRIM(i.status) = ''
            OR LOWER(TRIM(i.status)) NOT IN ('cancelled','canceled','void','voided','failed')
        )
        {$invoiceBranchFilter}";
    $arResult = $conn->query($arSql);
    $arBalance = 0.00;
    if ($arResult && ($arRow = $arResult->fetch_assoc())) {
        $arBalance = round((float)($arRow['ar_balance'] ?? 0), 2);
    }

    $undepositedBalance = 0.00;
    if (collectionTableExists($conn, 'payments')) {
        $ufSql = "
            SELECT COALESCE(SUM(p.amount), 0) AS uf_balance
            FROM payments p
            {$paymentBranchJoin}
            WHERE (p.status IS NULL OR p.status = 'completed')
              {$paymentBranchFilter}";
        $ufResult = $conn->query($ufSql);
        if ($ufResult && ($ufRow = $ufResult->fetch_assoc())) {
            $undepositedBalance = round((float)($ufRow['uf_balance'] ?? 0), 2);
        }
    }

    $upd = $conn->prepare("UPDATE chart_of_accounts SET balance = ? WHERE account_id = ?");
    if ($upd) {
        $arAccountId = (int)$receivable['account_id'];
        $upd->bind_param('di', $arBalance, $arAccountId);
        $upd->execute();

        $ufAccountId = (int)$undeposited['account_id'];
        $upd->bind_param('di', $undepositedBalance, $ufAccountId);
        $upd->execute();
        $upd->close();
    }
}



function collectionsPostReceivePaymentAccounting($conn, $payment_id, $invoice_id, $customer_id, $branch_id, $user_id, $amount, $payment_date = null) {
    $payment_id = (int)$payment_id;
    $invoice_id = (int)$invoice_id;
    $customer_id = (int)$customer_id;
    $branch_id = (int)$branch_id;
    $user_id = (int)$user_id;
    $amount = round((float)$amount, 2);
    if ($payment_id <= 0 || $invoice_id <= 0 || $customer_id <= 0 || $amount <= 0) return;

    collectionsEnsureReceivePaymentAccountingTables($conn);

    // Strong duplicate guard: one journal posting only for each payment row.
    if (collectionTableExists($conn, 'chart_account_transactions')) {
        $dup = $conn->prepare("SELECT transaction_id FROM chart_account_transactions WHERE source_table = 'payments' AND source_id = ? AND transaction_type = 'Receive Payment' LIMIT 1");
        if ($dup) {
            $dup->bind_param('i', $payment_id);
            $dup->execute();
            $exists = $dup->get_result()->fetch_assoc();
            $dup->close();
            if ($exists) return;
        }
    }

    $date = $payment_date ? date('Y-m-d', strtotime($payment_date)) : date('Y-m-d');
    $entryNo = collectionsNextReceivePaymentEntryNo($conn);
    $customerName = collectionsGetCustomerNameForAccounting($conn, $customer_id);
    $invoiceNo = collectionsGetInvoiceNumberForAccounting($conn, $invoice_id);
    $referenceNo = $invoiceNo;
    $memo = 'Receive Payment for Invoice ' . $invoiceNo;

    $undeposited = collectionsFindOrCreateAccount($conn, ['Undeposited Funds'], 'Other Current Asset', $branch_id, $user_id);
    $receivable = collectionsFindOrCreateAccount($conn, ['Accounts Receivable', 'Receivable Account'], 'Accounts Receivable', $branch_id, $user_id);

    $h = $conn->prepare("INSERT INTO journal_entries (entry_no, journal_date, attachment_path, branch_id, created_by) VALUES (?, ?, NULL, ?, ?)");
    if (!$h) throw new Exception('Failed to prepare receive payment journal header: ' . $conn->error);
    $h->bind_param('ssii', $entryNo, $date, $branch_id, $user_id);
    if (!$h->execute()) throw new Exception('Failed to save receive payment journal header: ' . $h->error);
    $journalId = (int)$conn->insert_id;
    $h->close();

    $detail = $conn->prepare("INSERT INTO journal_entry_details (journal_id, account_id, account_title, debit, credit, memo, counterparty) VALUES (?, ?, ?, ?, ?, ?, ?)");
    if (!$detail) throw new Exception('Failed to prepare receive payment journal details: ' . $conn->error);

    $lines = [
        ['account' => $undeposited, 'debit' => $amount, 'credit' => 0.00],
        ['account' => $receivable, 'debit' => 0.00, 'credit' => $amount]
    ];

    $cat = $conn->prepare("INSERT INTO chart_account_transactions (account_id, branch_id, transaction_date, transaction_type, transaction_no, reference_no, memo, account_name, debit, credit, balance_after, source_table, source_id, counterparty, created_by) VALUES (?, ?, ?, 'Receive Payment', ?, ?, ?, ?, ?, ?, ?, 'payments', ?, ?, ?)");
    if (!$cat) throw new Exception('Failed to prepare receive payment COA transaction: ' . $conn->error);

    // Set Accounts Receivable and Undeposited Funds using source-of-truth totals
    // before recording balance_after in the quick report rows.
    collectionsRecalculateCollectionLinkedCoaBalances($conn, $branch_id, $user_id);

    foreach ($lines as $line) {
        $acc = $line['account'];
        $aid = (int)$acc['account_id'];
        $title = (string)$acc['account_title'];
        $debit = (float)$line['debit'];
        $credit = (float)$line['credit'];
        // Do not increment/decrement AR directly here. The COA balances are recalculated from
        // invoices + payments to prevent double deduction and negative receivables.
        $balanceAfter = collectionsGetCurrentChartAccountBalance($conn, $aid);

        $detail->bind_param('iisddss', $journalId, $aid, $title, $debit, $credit, $memo, $customerName);
        if (!$detail->execute()) throw new Exception('Failed to save receive payment journal line: ' . $detail->error);

        $cat->bind_param('iisssssdddisi', $aid, $branch_id, $date, $entryNo, $referenceNo, $memo, $title, $debit, $credit, $balanceAfter, $payment_id, $customerName, $user_id);
        if (!$cat->execute()) throw new Exception('Failed to save receive payment COA transaction: ' . $cat->error);
    }
    $detail->close();
    $cat->close();
}

// Fix old Receive Payment rows that were saved as Invoice #<database id>.
collectionsRepairReceivePaymentInvoiceMemos($conn);

// Customer add helpers for Beginning Balance modal
function collectionsGenerateCustomerCode($conn) {
    $prefix = 'CUST-';
    $year = date('Y');
    $month = date('m');
    $like = $prefix . $year . $month . '%';
    $stmt = $conn->prepare("SELECT customer_code FROM customers WHERE customer_code LIKE ? AND created_by IN (SELECT user_id FROM users WHERE role = 'motorpool') ORDER BY customer_code DESC LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('s', $like);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($row = $res->fetch_assoc()) {
            $stmt->close();
            $sequence = intval(substr((string)$row['customer_code'], -4)) + 1;
            return $prefix . $year . $month . '-' . str_pad($sequence, 4, '0', STR_PAD_LEFT);
        }
        $stmt->close();
    }
    return $prefix . $year . $month . '-0001';
}

function collectionsBuildCustomerAddress($region, $province, $city, $barangay) {
    $parts = [];
    if (trim((string)$barangay) !== '') $parts[] = trim((string)$barangay);
    if (trim((string)$city) !== '') $parts[] = trim((string)$city);
    if (trim((string)$province) !== '') $parts[] = trim((string)$province);
    if (trim((string)$region) !== '') $parts[] = trim((string)$region);
    return implode(', ', $parts);
}

$bb_preview_customer_code = collectionsGenerateCustomerCode($conn);
$preview_code = $bb_preview_customer_code;

if (!isset($regions) || !is_array($regions)) {
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


}

// Customer groups and price levels for Add Customer modal copied from customer_list.php flow
// Branch admins should only see groups/levels created under their own branch.
$customer_groups = [];
if (collectionColumnExists($conn, 'customers', 'customer_group')) {
    $group_sql = "SELECT DISTINCT customer_group
                  FROM customers
                  WHERE customer_group IS NOT NULL
                    AND TRIM(customer_group) <> ''";

    if ($customers_branch_column_exists && !$view_all_branches && $branch_id > 0) {
        $group_sql .= " AND branch_id = ?";
    }

    $group_sql .= " ORDER BY customer_group ASC";

    $group_stmt = $conn->prepare($group_sql);
    if ($group_stmt) {
        if ($customers_branch_column_exists && !$view_all_branches && $branch_id > 0) {
            $safe_branch_id = (int)$branch_id;
            $group_stmt->bind_param('i', $safe_branch_id);
        }
        $group_stmt->execute();
        $group_result = $group_stmt->get_result();
        if ($group_result) {
            while ($group_row = $group_result->fetch_assoc()) {
                $customer_groups[] = $group_row['customer_group'];
            }
        }
        $group_stmt->close();
    }
}
$customer_groups = array_values(array_unique(array_filter($customer_groups)));
natcasesort($customer_groups);
$customer_groups = array_values($customer_groups);

$price_levels = ['Standard', 'Premium', 'Wholesale'];
$price_level_sql = "SELECT DISTINCT price_level
                    FROM customers
                    WHERE price_level IS NOT NULL
                      AND TRIM(price_level) <> ''";
if ($customers_branch_column_exists && !$view_all_branches && $branch_id > 0) {
    $price_level_sql .= " AND branch_id = ?";
}
$price_level_sql .= " ORDER BY price_level ASC";

$price_level_stmt = $conn->prepare($price_level_sql);
if ($price_level_stmt) {
    if ($customers_branch_column_exists && !$view_all_branches && $branch_id > 0) {
        $safe_branch_id = (int)$branch_id;
        $price_level_stmt->bind_param('i', $safe_branch_id);
    }
    $price_level_stmt->execute();
    $price_level_result = $price_level_stmt->get_result();
    if ($price_level_result && $price_level_result->num_rows > 0) {
        $price_levels = [];
        while ($price_row = $price_level_result->fetch_assoc()) {
            $price_levels[] = $price_row['price_level'];
        }
        if (!in_array('Standard', $price_levels, true)) array_unshift($price_levels, 'Standard');
    }
    $price_level_stmt->close();
}


// ========== HANDLE AJAX REQUESTS ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json; charset=utf-8');

    $raw_input = file_get_contents('php://input');
    $json = json_decode($raw_input, true);
    $action = $_POST['action'] ?? ($json['action'] ?? null);

    if (!$action) {
        echo json_encode(['success' => false, 'message' => 'Invalid request: action missing']);
        exit;
    }

    try {
        // AMGC_COLLECTIONS_JOURNAL_EDIT_PATCH_V9
        if ($action === 'update_journal_payment') {
            $data = is_array($json) ? $json : $_POST;
            $payment_id = (int)($data['payment_id'] ?? 0);
            $remittances_data = $data['remittances'] ?? [];
            $remit = (is_array($remittances_data) && isset($remittances_data[0]) && is_array($remittances_data[0])) ? $remittances_data[0] : [];

            if ($payment_id <= 0) throw new Exception('Invalid payment record selected for update.');
            if (empty($remit)) throw new Exception('No payment data submitted.');

            $payment_method = trim((string)($remit['payment_method'] ?? ''));
            $amount = round((float)($remit['amount'] ?? 0), 2);
            $payment_date = trim((string)($remit['collection_date'] ?? date('Y-m-d H:i:s')));
            $payment_date = date('Y-m-d H:i:s', strtotime($payment_date ?: date('Y-m-d H:i:s')));

            if (!in_array($payment_method, ['cash', 'check', 'online_transfer'], true)) throw new Exception('Invalid payment method selected.');
            if ($amount <= 0) throw new Exception('Payment amount must be greater than zero.');

            if (!collectionTableExists($conn, 'payments')) throw new Exception('Payments table not found.');
            $old_stmt = $conn->prepare("SELECT p.*, i.total_amount, i.branch_id AS invoice_branch_id FROM payments p LEFT JOIN invoices i ON i.invoice_id = p.invoice_id WHERE p.payment_id = ? LIMIT 1");
            if (!$old_stmt) throw new Exception('Failed to prepare payment lookup: ' . $conn->error);
            $old_stmt->bind_param('i', $payment_id);
            $old_stmt->execute();
            $old_payment = $old_stmt->get_result()->fetch_assoc();
            $old_stmt->close();
            if (!$old_payment) throw new Exception('Payment record not found.');

            $invoice_id = (int)($old_payment['invoice_id'] ?? 0);
            $customer_id = (int)($old_payment['customer_id'] ?? 0);
            $invoice_total = (float)($old_payment['total_amount'] ?? 0);
            if ($invoice_id <= 0 || $customer_id <= 0) throw new Exception('Payment is not linked to a valid invoice/customer.');

            $other_stmt = $conn->prepare("SELECT COALESCE(SUM(amount),0) AS other_paid FROM payments WHERE invoice_id = ? AND payment_id <> ? AND (status IS NULL OR status = 'completed')");
            if (!$other_stmt) throw new Exception('Failed to prepare payment total lookup: ' . $conn->error);
            $other_stmt->bind_param('ii', $invoice_id, $payment_id);
            $other_stmt->execute();
            $other_row = $other_stmt->get_result()->fetch_assoc();
            $other_stmt->close();
            $other_paid = (float)($other_row['other_paid'] ?? 0);
            $max_allowed = max($invoice_total - $other_paid, 0);
            if ($invoice_total > 0 && $amount > ($max_allowed + 0.009)) {
                throw new Exception('Payment amount cannot be greater than the remaining balance. Remaining balance: ₱' . number_format($max_allowed, 2));
            }

            $reference_number = !empty($remit['reference_number']) ? trim((string)$remit['reference_number']) : null;
            $check_date = !empty($remit['check_date']) ? trim((string)$remit['check_date']) : null;
            $bank_name = !empty($remit['bank_name']) ? trim((string)$remit['bank_name']) : null;
            $bank_branch = !empty($remit['bank_branch']) ? trim((string)$remit['bank_branch']) : null;
            $check_number = !empty($remit['check_number']) ? trim((string)$remit['check_number']) : null;
            $cash_tendered = isset($remit['cash_tendered']) && $remit['cash_tendered'] !== '' ? (float)$remit['cash_tendered'] : null;
            $cash_change = isset($remit['cash_change']) && $remit['cash_change'] !== '' ? (float)$remit['cash_change'] : null;

            if ($payment_method === 'check') {
                if ($check_date === null || $bank_name === null || $check_number === null) throw new Exception('Please fill all check details.');
                if ($bank_branch === null) $bank_branch = $bank_name;
                $reference_number = $check_number;
                $cash_tendered = null;
                $cash_change = null;
            } elseif ($payment_method === 'online_transfer') {
                if ($reference_number === null || $bank_name === null) throw new Exception('Please select Bank/Wallet and enter reference number for online transfer.');
                $check_date = null;
                $bank_branch = null;
                $check_number = null;
                $cash_tendered = null;
                $cash_change = null;
            } else {
                $reference_number = null;
                $check_date = null;
                $bank_name = null;
                $bank_branch = null;
                $check_number = null;
                $cash_tendered = null;
                $cash_change = null;
            }

            collectionsEnsureReceivePaymentAccountingTables($conn);
            $conn->begin_transaction();
            try {
                $upd = $conn->prepare("UPDATE payments SET payment_method = ?, amount = ?, payment_date = ?, reference_number = ?, check_date = ?, bank_name = ?, bank_branch = ?, check_number = ?, cash_tendered = ?, cash_change = ?, status = 'completed' WHERE payment_id = ?");
                if (!$upd) throw new Exception('Failed to prepare payment update: ' . $conn->error);
                $upd->bind_param('sdssssssddi', $payment_method, $amount, $payment_date, $reference_number, $check_date, $bank_name, $bank_branch, $check_number, $cash_tendered, $cash_change, $payment_id);
                if (!$upd->execute()) throw new Exception('Failed to update payment: ' . $upd->error);
                $upd->close();

                if (collectionTableExists($conn, 'chart_account_transactions')) {
                    $del = $conn->prepare("DELETE FROM chart_account_transactions WHERE source_table = 'payments' AND source_id = ? AND transaction_type = 'Receive Payment'");
                    if ($del) { $del->bind_param('i', $payment_id); $del->execute(); $del->close(); }
                }

                $posting_branch_id = (int)($old_payment['invoice_branch_id'] ?? $branch_id);
                if ($posting_branch_id <= 0) $posting_branch_id = (int)$branch_id;
                collectionsPostReceivePaymentAccounting($conn, $payment_id, $invoice_id, $customer_id, $posting_branch_id, $user_id, $amount, $payment_date);
                collectionsSyncInvoicePaymentTotals($conn, $invoice_id, $invoice_total, 'pending');
                recalcCustomerCreditUsed($conn, $customer_id);
                collectionsRecalculateCollectionLinkedCoaBalances($conn, $posting_branch_id, $user_id);
                $conn->commit();

                echo json_encode(['success' => true, 'message' => 'Payment updated successfully.']);
                exit;
            } catch (Throwable $e) {
                $conn->rollback();
                throw $e;
            }
        }

        // Generate customer code for Add Customer inside Beginning Balance modal
        if ($action === 'generate_customer_code' || $action === 'generate_code') {
            echo json_encode(['success' => true, 'customer_code' => collectionsGenerateCustomerCode($conn)]);
            exit;
        }

        // Add customer from Beginning Balance modal
        if ($action === 'add_customer_from_beginning_balance' || $action === 'add_customer') {
            $customer_code = trim($_POST['customer_code'] ?? '');
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
            $status = 'active';
            $store_image = '';
            $latitude = null;
            $longitude = null;

            if ($customer_name === '') throw new Exception('Customer name is required');
            if ($customer_code === '') $customer_code = collectionsGenerateCustomerCode($conn);

            $dup_stmt = $conn->prepare("SELECT customer_id FROM customers WHERE customer_code = ? AND created_by IN (SELECT user_id FROM users WHERE role = 'motorpool') LIMIT 1");
            if ($dup_stmt) {
                $dup_stmt->bind_param('s', $customer_code);
                $dup_stmt->execute();
                $dup = $dup_stmt->get_result()->fetch_assoc();
                $dup_stmt->close();
                if ($dup) $customer_code = collectionsGenerateCustomerCode($conn);
            }

            if (isset($_FILES['store_image']) && $_FILES['store_image']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = '../uploads/store_images/';
                if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
                $file_name = $_FILES['store_image']['name'];
                $file_tmp = $_FILES['store_image']['tmp_name'];
                $file_size = (int)$_FILES['store_image']['size'];
                $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
                $allowed_ext = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                if (!in_array($file_ext, $allowed_ext, true)) throw new Exception('Invalid store image type');
                if ($file_size > 5242880) throw new Exception('Store image must not exceed 5MB');
                $new_file_name = 'store_' . uniqid('', true) . '.' . $file_ext;
                if (!move_uploaded_file($file_tmp, $upload_dir . $new_file_name)) throw new Exception('Failed to upload store image');
                $store_image = $new_file_name;
            }

            $address = collectionsBuildCustomerAddress($region, $province, $city, $barangay);
            $target_branch_id = 0;
            if (!$view_all_branches && $branch_id > 0) {
                $target_branch_id = (int)$branch_id;
            } elseif ($view_all_branches && isset($_POST['branch_id'])) {
                $target_branch_id = (int)$_POST['branch_id'];
            }

            $conn->begin_transaction();
            $insert_sql = "INSERT INTO customers (
                customer_code, customer_name, contact_person, email, phone_number, address,
                region, province, city, barangay, price_level, store_name, customer_group, store_image,
                city_code, latitude, longitude, status, branch_id, created_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($insert_sql);
            if (!$stmt) throw new Exception('Failed to prepare customer insert: ' . $conn->error);
            $stmt->bind_param(
                'ssssssssssssssssssii',
                $customer_code, $customer_name, $contact_person, $email, $phone, $address,
                $region, $province, $city, $barangay, $price_level, $store_name, $customer_group, $store_image,
                $city_code, $latitude, $longitude, $status, $target_branch_id, $user_id
            );
            if (!$stmt->execute()) throw new Exception('Failed to add customer: ' . $stmt->error);
            $new_customer_id = (int)$conn->insert_id;
            $stmt->close();
            $conn->commit();

            echo json_encode([
                'success' => true,
                'message' => 'Customer added successfully',
                'customer' => [
                    'customer_id' => $new_customer_id,
                    'customer_name' => $customer_name,
                    'store_name' => $store_name,
                    'customer_code' => $customer_code,
                    'customer_group' => $customer_group,
                    'branch_id' => $target_branch_id
                ],
                'next_customer_code' => collectionsGenerateCustomerCode($conn)
            ]);
            exit;
        }

        // ADMIN: Get pending remittances (collections waiting for approval)
        if ($action === 'get_pending_remittances') {
            $sql = "SELECT cr.record_id AS remittance_id,
                           cr.invoice_id, cr.customer_id, cr.branch_id, cr.collector_user_id,
                           cr.payment_method, cr.amount, cr.collection_date,
                           COALESCE(cr.remitted_at, cr.created_at) AS remittance_date,
                           cr.reference_number, cr.check_date, cr.bank_name, cr.bank_branch,
                           cr.check_number, cr.cash_tendered, cr.cash_change, cr.attachment_path, cr.attachment_name, cr.notes,
                           cr.status,
                           i.invoice_number, i.invoice_date, i.due_date, i.total_amount,
                           c.customer_name,
                           u.first_name as collector_first, u.last_name as collector_last
                    FROM collection_records cr
                    LEFT JOIN invoices i ON cr.invoice_id = i.invoice_id
                    LEFT JOIN customers c ON cr.customer_id = c.customer_id
                    LEFT JOIN users u ON cr.collector_user_id = u.user_id
                    WHERE c.created_by IN (SELECT user_id FROM users WHERE role = 'motorpool') AND cr.status = 'remitted'";
            
            if (!$view_all_branches && $branch_id > 0) {
                $sql .= " AND cr.branch_id = " . intval($branch_id);
            }
            
            $sql .= " ORDER BY COALESCE(cr.remitted_at, cr.created_at) DESC";
            
            $result = $conn->query($sql);
            $remittances = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
            
            echo json_encode(['success' => true, 'remittances' => $remittances]);
            exit;
        }
        
        // ADMIN: Approve remittance from Sales Collection remitted records
        elseif ($action === 'approve_remittance') {
            $remittance_id = (int)($json['remittance_id'] ?? ($_POST['remittance_id'] ?? 0));
            if ($remittance_id <= 0) {
                throw new Exception('Invalid remittance ID');
            }

            $remit_sql = "SELECT cr.record_id AS remittance_id,
                                 cr.invoice_id, cr.customer_id, cr.branch_id, cr.collector_user_id,
                                 cr.payment_method, cr.amount, cr.collection_date,
                                 cr.reference_number, cr.check_date, cr.bank_name, cr.bank_branch,
                                 cr.check_number, cr.cash_tendered, cr.cash_change, cr.notes,
                                 i.total_amount AS invoice_total, i.balance AS invoice_balance, i.amount_paid AS invoice_amount_paid, i.status AS invoice_status,
                                 COALESCE(pay.total_paid, 0) AS completed_payment_total
                          FROM collection_records cr
                          LEFT JOIN invoices i ON i.invoice_id = cr.invoice_id
                          LEFT JOIN (
                              SELECT invoice_id, COALESCE(SUM(amount), 0) AS total_paid
                              FROM payments
                              WHERE status IS NULL OR status = 'completed'
                              GROUP BY invoice_id
                          ) pay ON pay.invoice_id = cr.invoice_id
                          WHERE cr.record_id = ? AND cr.status = 'remitted'
                          LIMIT 1";
            $remit_stmt = $conn->prepare($remit_sql);
            if (!$remit_stmt) throw new Exception('Failed to prepare remittance lookup: ' . $conn->error);
            $remit_stmt->bind_param('i', $remittance_id);
            $remit_stmt->execute();
            $remittance = $remit_stmt->get_result()->fetch_assoc();
            $remit_stmt->close();

            if (!$remittance) {
                throw new Exception('Remittance not found or already processed');
            }

            if (!$view_all_branches && $branch_id > 0 && (int)$remittance['branch_id'] > 0 && (int)$remittance['branch_id'] !== (int)$branch_id) {
                throw new Exception('This remittance does not belong to your branch');
            }

            $invoice_id = (int)$remittance['invoice_id'];
            $customer_id = (int)$remittance['customer_id'];
            $amount = (float)$remittance['amount'];
            if ($invoice_id <= 0 || $customer_id <= 0 || $amount <= 0) {
                throw new Exception('Invalid remittance data');
            }

            collectionsEnsureReceivePaymentAccountingTables($conn);
            $conn->begin_transaction();

            // Prevent double-posting the same approved collection record.
            $dup_stmt = $conn->prepare("SELECT payment_id FROM payments WHERE created_by IN (SELECT user_id FROM users WHERE role = 'motorpool') AND source_table = 'collection_records' AND source_id = ? LIMIT 1");
            if ($dup_stmt) {
                $dup_stmt->bind_param('i', $remittance_id);
                $dup_stmt->execute();
                $dup_row = $dup_stmt->get_result()->fetch_assoc();
                $dup_stmt->close();
                if ($dup_row) {
                    throw new Exception('This remittance was already posted as payment. Please refresh.');
                }
            }

            $insert_payment = "INSERT INTO payments
                               (invoice_id, customer_id, payment_method, amount, payment_date,
                                reference_number, check_date, bank_name, bank_branch, check_number,
                                cash_tendered, cash_change, status, created_by, source_table, source_id)
                               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'completed', ?, 'collection_records', ?)";
            $stmt = $conn->prepare($insert_payment);
            if (!$stmt) throw new Exception('Failed to prepare payment insert: ' . $conn->error);
            $payment_date = date('Y-m-d H:i:s');
            $stmt->bind_param(
                "iisdssssssddii",
                $invoice_id,
                $customer_id,
                $remittance['payment_method'],
                $amount,
                $payment_date,
                $remittance['reference_number'],
                $remittance['check_date'],
                $remittance['bank_name'],
                $remittance['bank_branch'],
                $remittance['check_number'],
                $remittance['cash_tendered'],
                $remittance['cash_change'],
                $user_id,
                $remittance_id
            );
            if (!$stmt->execute()) throw new Exception('Failed to save payment: ' . $stmt->error);
            $payment_id = (int)$conn->insert_id;
            $stmt->close();

            $invoice_total = (float)($remittance['invoice_total'] ?? 0);
            $old_paid_from_payments = (float)($remittance['completed_payment_total'] ?? 0);
            // Payments table is the source of truth. Do not use stored invoice.balance here,
            // because subtracting from balance and also summing payments causes double deduction.
            $old_remaining = max($invoice_total - $old_paid_from_payments, 0);
            if ($amount > ($old_remaining + 0.009)) {
                throw new Exception('Payment amount cannot be greater than the remaining balance. Remaining balance: ₱' . number_format($old_remaining, 2));
            }

            collectionsPostReceivePaymentAccounting($conn, $payment_id, $invoice_id, $customer_id, (int)$remittance['branch_id'], $user_id, $amount, $payment_date);
            $sync = collectionsSyncInvoicePaymentTotals($conn, $invoice_id, $invoice_total, $remittance['invoice_status'] ?? 'pending');
            $new_status = $sync['status'];
            $total_paid = $sync['paid'];

            $update_record = $conn->prepare("UPDATE collection_records SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE record_id = ? AND status = 'remitted'");
            if (!$update_record) throw new Exception('Failed to prepare remittance update: ' . $conn->error);
            $update_record->bind_param('ii', $user_id, $remittance_id);
            $update_record->execute();
            if ($update_record->affected_rows <= 0) throw new Exception('Remittance was already processed. Please refresh.');
            $update_record->close();

            if ($new_status === 'paid') {
                $complete_assign = $conn->prepare("UPDATE collection_assignments SET status = 'completed', updated_at = NOW() WHERE invoice_id = ? AND status IN ('active','assigned')");
                if ($complete_assign) {
                    $complete_assign->bind_param('i', $invoice_id);
                    $complete_assign->execute();
                    $complete_assign->close();
                }
            }

            recalcCustomerCreditUsed($conn, $customer_id);
            collectionsRecalculateCollectionLinkedCoaBalances($conn, (int)$remittance['branch_id'], $user_id);
            $conn->commit();

            $remaining_balance = max($invoice_total - $total_paid, 0);
            echo json_encode([
                'success' => true,
                'message' => $new_status === 'paid'
                    ? 'Remittance approved. Invoice is now fully paid.'
                    : 'Remittance approved as partial payment. Remaining balance: ₱' . number_format($remaining_balance, 2)
            ]);
            exit;
        }
        
        // ADMIN: Reject remittance from Sales Collection remitted records
        elseif ($action === 'reject_remittance') {
            $remittance_id = (int)($json['remittance_id'] ?? ($_POST['remittance_id'] ?? 0));
            $rejection_reason = trim($json['rejection_reason'] ?? ($_POST['rejection_reason'] ?? ''));
            if ($remittance_id <= 0) {
                throw new Exception('Invalid remittance ID');
            }
            if ($rejection_reason === '') {
                throw new Exception('Please provide a reason for rejection');
            }

            $update_stmt = $conn->prepare("UPDATE collection_records SET status = 'rejected', rejection_reason = ?, approved_by = ?, approved_at = NOW() WHERE record_id = ? AND status = 'remitted'");
            if (!$update_stmt) throw new Exception('Failed to prepare rejection: ' . $conn->error);
            $update_stmt->bind_param('sii', $rejection_reason, $user_id, $remittance_id);
            if (!$update_stmt->execute()) throw new Exception('Failed to reject remittance: ' . $update_stmt->error);
            if ($update_stmt->affected_rows <= 0) throw new Exception('Remittance not found or already processed');
            $update_stmt->close();

            echo json_encode(['success' => true, 'message' => 'Remittance rejected']);
            exit;
        }
        
        // ADMIN: Approve returned invoice ticket
        elseif ($action === 'approve_return_ticket') {
            $return_id = (int)($json['return_id'] ?? ($_POST['return_id'] ?? 0));
            if ($return_id <= 0) throw new Exception('Invalid return ticket ID');

            $ret_stmt = $conn->prepare("SELECT * FROM collection_invoice_returns WHERE return_id = ? AND status IN ('returned','pending') LIMIT 1");
            if (!$ret_stmt) throw new Exception('Failed to prepare return lookup: ' . $conn->error);
            $ret_stmt->bind_param('i', $return_id);
            $ret_stmt->execute();
            $return_ticket = $ret_stmt->get_result()->fetch_assoc();
            $ret_stmt->close();

            if (!$return_ticket) throw new Exception('Return ticket not found or already processed');
            if (!$view_all_branches && $branch_id > 0 && (int)$return_ticket['branch_id'] > 0 && (int)$return_ticket['branch_id'] !== (int)$branch_id) {
                throw new Exception('This return ticket does not belong to your branch');
            }

            $conn->begin_transaction();
            $upd_ret = $conn->prepare("UPDATE collection_invoice_returns SET status = 'approved', reviewed_by = ?, reviewed_at = NOW() WHERE return_id = ? AND status IN ('returned','pending')");
            if (!$upd_ret) throw new Exception('Failed to prepare approve return: ' . $conn->error);
            $upd_ret->bind_param('ii', $user_id, $return_id);
            $upd_ret->execute();
            if ($upd_ret->affected_rows <= 0) throw new Exception('Return ticket was already processed. Please refresh.');
            $upd_ret->close();

            $assignment_id = (int)($return_ticket['assignment_id'] ?? 0);
            if ($assignment_id > 0) {
                $cancel_assign = $conn->prepare("UPDATE collection_assignments SET status = 'cancelled', updated_at = NOW() WHERE assignment_id = ? AND status = 'returned'");
                if ($cancel_assign) {
                    $cancel_assign->bind_param('i', $assignment_id);
                    $cancel_assign->execute();
                    $cancel_assign->close();
                }
            }

            $conn->commit();
            echo json_encode(['success' => true, 'message' => 'Return ticket approved. Invoice is now available for reassignment.']);
            exit;
        }

        // ADMIN: Reject returned invoice ticket
        elseif ($action === 'reject_return_ticket') {
            $return_id = (int)($json['return_id'] ?? ($_POST['return_id'] ?? 0));
            $rejection_reason = trim($json['rejection_reason'] ?? ($_POST['rejection_reason'] ?? ''));
            if ($return_id <= 0) throw new Exception('Invalid return ticket ID');
            if ($rejection_reason === '') throw new Exception('Please provide a reason for rejection');

            $ret_stmt = $conn->prepare("SELECT * FROM collection_invoice_returns WHERE return_id = ? AND status IN ('returned','pending') LIMIT 1");
            if (!$ret_stmt) throw new Exception('Failed to prepare return lookup: ' . $conn->error);
            $ret_stmt->bind_param('i', $return_id);
            $ret_stmt->execute();
            $return_ticket = $ret_stmt->get_result()->fetch_assoc();
            $ret_stmt->close();

            if (!$return_ticket) throw new Exception('Return ticket not found or already processed');
            if (!$view_all_branches && $branch_id > 0 && (int)$return_ticket['branch_id'] > 0 && (int)$return_ticket['branch_id'] !== (int)$branch_id) {
                throw new Exception('This return ticket does not belong to your branch');
            }

            $conn->begin_transaction();
            $upd_ret = $conn->prepare("UPDATE collection_invoice_returns SET status = 'rejected', rejection_reason = ?, reviewed_by = ?, reviewed_at = NOW() WHERE return_id = ? AND status IN ('returned','pending')");
            if (!$upd_ret) throw new Exception('Failed to prepare reject return: ' . $conn->error);
            $upd_ret->bind_param('sii', $rejection_reason, $user_id, $return_id);
            $upd_ret->execute();
            if ($upd_ret->affected_rows <= 0) throw new Exception('Return ticket was already processed. Please refresh.');
            $upd_ret->close();

            $assignment_id = (int)($return_ticket['assignment_id'] ?? 0);
            $invoice_id = (int)($return_ticket['invoice_id'] ?? 0);
            $customer_id = (int)($return_ticket['customer_id'] ?? 0);
            $return_branch_id = (int)($return_ticket['branch_id'] ?? 0);
            $returned_by = (int)($return_ticket['returned_by'] ?? 0);
            $reactivated_rows = 0;

            // Reject means the ticket is NOT accepted by admin, so the invoice must go back
            // to the exact collector who returned it.
            if ($assignment_id > 0) {
                $reactivate = $conn->prepare("UPDATE collection_assignments
                                             SET status = 'active', updated_at = NOW()
                                             WHERE assignment_id = ?");
                if (!$reactivate) throw new Exception('Failed to prepare assignment reactivation: ' . $conn->error);
                $reactivate->bind_param('i', $assignment_id);
                if (!$reactivate->execute()) throw new Exception('Failed to reactivate assignment: ' . $reactivate->error);
                $reactivated_rows = $reactivate->affected_rows;
                $reactivate->close();
            }

            // Fallback for older records where assignment_id was not saved correctly.
            if ($reactivated_rows <= 0 && $invoice_id > 0 && $returned_by > 0) {
                $reactivate2 = $conn->prepare("UPDATE collection_assignments
                                              SET status = 'active', updated_at = NOW()
                                              WHERE invoice_id = ?
                                                AND assigned_user_id = ?
                                                AND status IN ('returned','cancelled','','inactive')");
                if ($reactivate2) {
                    $reactivate2->bind_param('ii', $invoice_id, $returned_by);
                    if (!$reactivate2->execute()) throw new Exception('Failed to reactivate collector assignment: ' . $reactivate2->error);
                    $reactivated_rows = $reactivate2->affected_rows;
                    $reactivate2->close();
                }
            }

            // Last fallback: recreate assignment so it always appears again in collector collections.
            if ($reactivated_rows <= 0 && $invoice_id > 0 && $customer_id > 0 && $returned_by > 0) {
                $insert_assign = $conn->prepare("INSERT INTO collection_assignments
                    (invoice_id, customer_id, branch_id, assigned_user_id, assigned_by, collection_date, notes, status)
                    VALUES (?, ?, ?, ?, ?, CURDATE(), ?, 'active')");
                if (!$insert_assign) throw new Exception('Failed to recreate assignment: ' . $conn->error);
                $note = 'Return ticket rejected by Branch Admin; invoice returned to collector automatically.';
                $insert_assign->bind_param('iiiiis', $invoice_id, $customer_id, $return_branch_id, $returned_by, $user_id, $note);
                if (!$insert_assign->execute()) throw new Exception('Failed to return invoice to collector: ' . $insert_assign->error);
                $insert_assign->close();
            }

            $conn->commit();
            echo json_encode(['success' => true, 'message' => 'Return ticket rejected. Invoice is now back to the assigned collector.']);
            exit;
        }

        // COLLECTOR / BRANCH ADMIN: Direct payment collection (no approval needed)
        elseif ($action === 'submit_remittance') {
            $data = is_array($json) ? $json : $_POST;
            if (!$data || !is_array($data)) throw new Exception('Invalid payment data received');

            $remittances_data = $data['remittances'] ?? [];
            if (empty($remittances_data) || !is_array($remittances_data)) {
                throw new Exception('No payment data submitted');
            }

            collectionsEnsureReceivePaymentAccountingTables($conn);
            $conn->begin_transaction();
            $success_count = 0;
            $total_collected_amount = 0.0;

            foreach ($remittances_data as $remit) {
                $invoice_id = (int)($remit['invoice_id'] ?? 0);
                $customer_id = (int)($remit['customer_id'] ?? 0);
                $payment_method = trim($remit['payment_method'] ?? '');
                $amount = (float)($remit['amount'] ?? 0);
                $collection_date = trim($remit['collection_date'] ?? date('Y-m-d H:i:s'));
                $payment_date = date('Y-m-d H:i:s', strtotime($collection_date ?: date('Y-m-d H:i:s')));

                if ($invoice_id <= 0) throw new Exception('Invalid invoice selected');
                if (!in_array($payment_method, ['cash', 'check', 'online_transfer'], true)) throw new Exception('Invalid payment method selected');
                if ($amount <= 0) throw new Exception('Payment amount must be greater than zero');

                $invoice_stmt = $conn->prepare("SELECT i.invoice_id, i.customer_id, i.branch_id, i.total_amount, i.amount_paid, i.balance, i.status,
                                                      COALESCE(pay.total_paid, 0) AS completed_payment_total
                                               FROM invoices i
                                               LEFT JOIN (
                                                   SELECT invoice_id, COALESCE(SUM(amount), 0) AS total_paid
                                                   FROM payments
                                                   WHERE status IS NULL OR status = 'completed'
                                                   GROUP BY invoice_id
                                               ) pay ON pay.invoice_id = i.invoice_id
                                               WHERE i.invoice_id = ?
                                               LIMIT 1");
                if (!$invoice_stmt) throw new Exception('Failed to prepare invoice lookup: ' . $conn->error);
                $invoice_stmt->bind_param('i', $invoice_id);
                $invoice_stmt->execute();
                $invoice_row = $invoice_stmt->get_result()->fetch_assoc();
                $invoice_stmt->close();

                if (!$invoice_row) throw new Exception('Invoice not found');
                if (($invoice_row['status'] ?? '') === 'paid') throw new Exception('This invoice is already fully paid');

                if ($customer_id <= 0) $customer_id = (int)($invoice_row['customer_id'] ?? 0);
                if ($customer_id <= 0) throw new Exception('Invalid customer selected');

                $invoice_total = (float)($invoice_row['total_amount'] ?? 0);
                // Payments table is the source of truth for partial payments.
                // Do not use stored invoice.balance here because it can already be net of previous payments.
                $already_paid = (float)($invoice_row['completed_payment_total'] ?? 0);
                $remaining_balance = max($invoice_total - $already_paid, 0);
                if ($remaining_balance <= 0.009) throw new Exception('This invoice is already fully paid');
                if ($amount > ($remaining_balance + 0.009)) {
                    throw new Exception('Payment amount cannot be greater than the remaining balance. Remaining balance: ₱' . number_format($remaining_balance, 2));
                }

                // Prevent accidental double-click/double-submit from deducting the same partial payment twice.
                $recent_dup = $conn->prepare("SELECT payment_id FROM payments WHERE invoice_id = ? AND customer_id = ? AND amount = ? AND payment_method = ? AND created_by = ? AND payment_date >= DATE_SUB(NOW(), INTERVAL 10 SECOND) LIMIT 1");
                if ($recent_dup) {
                    $recent_dup->bind_param('iidsi', $invoice_id, $customer_id, $amount, $payment_method, $user_id);
                    $recent_dup->execute();
                    $recent_row = $recent_dup->get_result()->fetch_assoc();
                    $recent_dup->close();
                    if ($recent_row) {
                        throw new Exception('Duplicate payment submit detected. Please refresh the invoice list.');
                    }
                }

                $reference_number = !empty($remit['reference_number']) ? trim($remit['reference_number']) : null;
                $check_date = !empty($remit['check_date']) ? trim($remit['check_date']) : null;
                $bank_name = !empty($remit['bank_name']) ? trim($remit['bank_name']) : null;
                $bank_branch = !empty($remit['bank_branch']) ? trim($remit['bank_branch']) : null;
                $check_number = !empty($remit['check_number']) ? trim($remit['check_number']) : null;
                $cash_tendered = isset($remit['cash_tendered']) && $remit['cash_tendered'] !== '' ? (float)$remit['cash_tendered'] : null;
                $cash_change = isset($remit['cash_change']) && $remit['cash_change'] !== '' ? (float)$remit['cash_change'] : null;

                if ($payment_method === 'check') {
                    if ($check_date === null || $bank_name === null || $bank_branch === null || $check_number === null) {
                        throw new Exception('Please fill all check details');
                    }
                    $reference_number = $check_number;
                } elseif ($payment_method === 'online_transfer') {
                    if ($reference_number === null || $bank_name === null) {
                        throw new Exception('Please select Bank/Wallet and enter reference number for online transfer');
                    }
                    $check_date = null;
                    $bank_branch = null;
                    $check_number = null;
                    $cash_tendered = null;
                    $cash_change = null;
                } else {
                    $reference_number = null;
                    $check_date = null;
                    $bank_name = null;
                    $bank_branch = null;
                    $check_number = null;
                    $cash_tendered = null;
                    $cash_change = null;
                }

                $insert_payment = "INSERT INTO payments
                                   (invoice_id, customer_id, payment_method, amount, payment_date,
                                    reference_number, check_date, bank_name, bank_branch, check_number,
                                    cash_tendered, cash_change, status, created_by)
                                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'completed', ?)";
                $stmt = $conn->prepare($insert_payment);
                if (!$stmt) throw new Exception('Failed to prepare payment insert: ' . $conn->error);
                $stmt->bind_param(
                    'iisdssssssddi',
                    $invoice_id,
                    $customer_id,
                    $payment_method,
                    $amount,
                    $payment_date,
                    $reference_number,
                    $check_date,
                    $bank_name,
                    $bank_branch,
                    $check_number,
                    $cash_tendered,
                    $cash_change,
                    $user_id
                );
                if (!$stmt->execute()) throw new Exception('Failed to save payment: ' . $stmt->error);
                $payment_id = (int)$conn->insert_id;
                $stmt->close();

                $posting_branch_id = (int)($invoice_row['branch_id'] ?? $branch_id);
                if ($posting_branch_id <= 0) $posting_branch_id = (int)$branch_id;
                collectionsPostReceivePaymentAccounting($conn, $payment_id, $invoice_id, $customer_id, $posting_branch_id, $user_id, $amount, $payment_date);

                $sync = collectionsSyncInvoicePaymentTotals($conn, $invoice_id, $invoice_total, $invoice_row['status'] ?? 'pending');
                $new_status = $sync['status'];

                if ($new_status === 'paid') {
                    $complete_assign = $conn->prepare("UPDATE collection_assignments
                                                       SET status = 'completed', updated_at = NOW()
                                                       WHERE invoice_id = ?
                                                         AND status IN ('active','assigned')");
                    if ($complete_assign) {
                        $complete_assign->bind_param('i', $invoice_id);
                        $complete_assign->execute();
                        $complete_assign->close();
                    }
                }

                recalcCustomerCreditUsed($conn, $customer_id);
                collectionsRecalculateCollectionLinkedCoaBalances($conn, $posting_branch_id, $user_id);
                $success_count++;
                $total_collected_amount += $amount;
            }

            if ($success_count <= 0) {
                throw new Exception('No payment was collected');
            }

            $conn->commit();

            echo json_encode([
                'success' => true,
                'message' => $success_count . ' payment(s) collected successfully. Total collected: ₱' . number_format($total_collected_amount, 2)
            ]);
            exit;
        }
        
        // BRANCH ADMIN: Add beginning balance / existing customer debt as ready-for-collection invoice
        elseif ($action === 'add_beginning_balance') {
            $data = $_POST;
            if (empty($data) && is_array($json)) $data = $json;
            if (!$data || !is_array($data)) throw new Exception('Invalid beginning balance data received');

            $customer_id = (int)($data['customer_id'] ?? 0);
            $document_type = strtolower(trim($data['document_type'] ?? 'so'));
            if (!in_array($document_type, ['so', 'si'], true)) $document_type = 'so';

            // SO uses the same generated format as the system: SO-YYYYMMDD-xxxxx.
            // SI does NOT use SI-YYYYMMDD format. It is saved exactly as encoded by the user.
            // Rule: if SI is encoded, an SO is still auto-generated internally for the sales_orders record.
            // Rule: if SO only is encoded, SI stays blank and is not auto-generated.
            $document_number_raw = trim($data['document_number'] ?? ($data['document_digits'] ?? ''));
            $document_digits = preg_replace('/\D+/', '', $document_number_raw);
            $so_digits = $document_type === 'so' ? preg_replace('/\D+/', '', trim($data['so_digits'] ?? $document_digits)) : '';
            $si_number_input = $document_type === 'si' ? trim($data['si_number'] ?? $document_number_raw) : '';
            $registered_business_name = trim($data['registered_business_name'] ?? '');
            $tin = trim($data['tin'] ?? ($data['tin_number'] ?? ''));
            $business_address = trim($data['business_address'] ?? '');
            $amount = (float)str_replace(',', '', trim($data['amount'] ?? '0'));

            $due_date = trim($data['due_date'] ?? '');
            if ($due_date === '') {
                $due_date = date('Y-m-d');
            }

            // Beginning Balance invoice date = due date
            $invoice_date = $due_date;

            $remarks = trim($data['remarks'] ?? 'Beginning balance');

            if ($customer_id <= 0) throw new Exception('Please select a customer');
            if ($document_type === 'so') {
                if (!preg_match('/^\d{5,6}$/', $so_digits)) throw new Exception('SO number must be 5 to 6 numbers only');
                $si_number_input = '';
                $registered_business_name = '';
                $tin = '';
                $business_address = '';
            } else {
                if ($si_number_input === '') throw new Exception('Please enter SI Number');
                if (strlen($si_number_input) > 100) throw new Exception('SI Number is too long');
                if ($registered_business_name === '') throw new Exception('Please enter Registered Business Name');
                if ($tin === '') throw new Exception('Please enter TIN');
                if ($business_address === '') throw new Exception('Please enter Address');
            }
            if ($amount <= 0) throw new Exception('Beginning balance amount must be greater than zero');

            if ($due_date === '') $due_date = $invoice_date;

            $cust_sql = "SELECT customer_id, customer_name, branch_id FROM customers WHERE customer_id = ? AND status = 'active' AND created_by IN (SELECT user_id FROM users WHERE role = 'motorpool')";
            if ($customers_branch_column_exists && !$view_all_branches && $branch_id > 0) {
                $cust_sql .= " AND branch_id = " . intval($branch_id);
            }
            $cust_sql .= " LIMIT 1";
            $cust_stmt = $conn->prepare($cust_sql);
            if (!$cust_stmt) throw new Exception('Failed to prepare customer lookup: ' . $conn->error);
            $cust_stmt->bind_param('i', $customer_id);
            $cust_stmt->execute();
            $customer = $cust_stmt->get_result()->fetch_assoc();
            $cust_stmt->close();
            if (!$customer) throw new Exception('Customer not found in this branch');

            $date_part = date('Ymd', strtotime($invoice_date));
            $si_number = $document_type === 'si' ? $si_number_input : null;
            $auto_so_digits = substr(date('His') . random_int(1000, 9999), 0, 6);
            $so_number = 'SO-' . $date_part . '-' . ($document_type === 'so' ? $so_digits : $auto_so_digits);
            $invoice_digits = $document_type === 'so'
                ? $so_digits
                : substr(date('His') . random_int(1000, 9999), 0, 6);
            $invoice_number = 'INV-' . $date_part . '-' . $invoice_digits;
            $effective_branch_id = (!$view_all_branches && $branch_id > 0) ? (int)$branch_id : (int)($customer['branch_id'] ?? 0);

            // Keep invoice number hidden from the modal, but still ensure the required invoice_number is unique.
            for ($try = 0; $try < 10; $try++) {
                $dup_stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM invoices WHERE invoice_number = ?");
                if (!$dup_stmt) throw new Exception('Failed to prepare duplicate invoice check: ' . $conn->error);
                $dup_stmt->bind_param('s', $invoice_number);
                $dup_stmt->execute();
                $dup_row = $dup_stmt->get_result()->fetch_assoc();
                $dup_stmt->close();
                if ((int)($dup_row['cnt'] ?? 0) === 0) break;
                $invoice_digits = substr(date('His') . random_int(1000, 9999), 0, 6);
                $invoice_number = 'INV-' . $date_part . '-' . $invoice_digits;
            }

            // For SI entries, the SO is generated internally and hidden from the form.
            // Keep checking uniqueness because sales_orders.so_number is used as a record reference.
            for ($try = 0; $try < 10; $try++) {
                $dup_so_stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM sales_orders WHERE so_number = ?");
                if (!$dup_so_stmt) throw new Exception('Failed to prepare duplicate SO check: ' . $conn->error);
                $dup_so_stmt->bind_param('s', $so_number);
                $dup_so_stmt->execute();
                $dup_so_row = $dup_so_stmt->get_result()->fetch_assoc();
                $dup_so_stmt->close();
                if ((int)($dup_so_row['cnt'] ?? 0) === 0) break;
                if ($document_type === 'so') throw new Exception('SO number already exists: ' . $so_number);
                $auto_so_digits = substr(date('His') . random_int(1000, 9999), 0, 6);
                $so_number = 'SO-' . $date_part . '-' . $auto_so_digits;
            }

            $sales_orders_has_si = collectionColumnExists($conn, 'sales_orders', 'si_number');
            $invoices_has_si = collectionColumnExists($conn, 'invoices', 'si_number');
            $sales_orders_has_registered_business = collectionColumnExists($conn, 'sales_orders', 'registered_business_name');
            $sales_orders_has_tin = collectionColumnExists($conn, 'sales_orders', 'tin');
            $sales_orders_has_business_address = collectionColumnExists($conn, 'sales_orders', 'business_address');
            $invoices_has_registered_business = collectionColumnExists($conn, 'invoices', 'registered_business_name');
            $invoices_has_tin = collectionColumnExists($conn, 'invoices', 'tin');
            $invoices_has_business_address = collectionColumnExists($conn, 'invoices', 'business_address');
            $sales_orders_has_remarks = collectionColumnExists($conn, 'sales_orders', 'remarks');
            $invoices_has_remarks = collectionColumnExists($conn, 'invoices', 'remarks');

            if ($si_number !== null && ($sales_orders_has_si || $invoices_has_si)) {
                $parts = [];
                $values = [];
                $types = '';
                if ($invoices_has_si) {
                    $parts[] = "(SELECT COUNT(*) FROM invoices WHERE si_number = ?)";
                    $values[] = $si_number;
                    $types .= 's';
                }
                if ($sales_orders_has_si) {
                    $parts[] = "(SELECT COUNT(*) FROM sales_orders WHERE si_number = ?)";
                    $values[] = $si_number;
                    $types .= 's';
                }
                $dup_si_stmt = $conn->prepare("SELECT " . implode(' + ', $parts) . " AS cnt");
                if (!$dup_si_stmt) throw new Exception('Failed to prepare duplicate SI check: ' . $conn->error);
                collectionBindParamsDynamic($dup_si_stmt, $types, $values);
                $dup_si_stmt->execute();
                $dup_si_row = $dup_si_stmt->get_result()->fetch_assoc();
                $dup_si_stmt->close();
                if ((int)($dup_si_row['cnt'] ?? 0) > 0) throw new Exception('SI number already exists: ' . $si_number);
            }

            $conn->begin_transaction();

            $order_date = date('Y-m-d H:i:s', strtotime($invoice_date . ' ' . date('H:i:s')));
            $so_id = null;

            $so_columns = ['so_number'];
            $so_values = [$so_number];
            $so_types = 's';
            if ($sales_orders_has_si) {
                $so_columns[] = 'si_number';
                $so_values[] = $si_number;
                $so_types .= 's';
            }
            if ($sales_orders_has_registered_business) {
                $so_columns[] = 'registered_business_name';
                $so_values[] = $document_type === 'si' && $registered_business_name !== '' ? $registered_business_name : null;
                $so_types .= 's';
            }
            if ($sales_orders_has_tin) {
                $so_columns[] = 'tin';
                $so_values[] = $document_type === 'si' && $tin !== '' ? $tin : null;
                $so_types .= 's';
            }
            if ($sales_orders_has_business_address) {
                $so_columns[] = 'business_address';
                $so_values[] = $document_type === 'si' && $business_address !== '' ? $business_address : null;
                $so_types .= 's';
            }
            if ($sales_orders_has_remarks) {
                $so_columns[] = 'remarks';
                $so_values[] = $remarks !== '' ? $remarks : null;
                $so_types .= 's';
            }
            $so_columns = array_merge($so_columns, [
                'customer_id', 'branch_id', 'order_date', 'delivery_date', 'total_amount',
                'order_amount', 'gross_profit', 'gross_profit_amount', 'order_status',
                'fulfillment_type', 'payment_status', 'created_by'
            ]);
            $so_values = array_merge($so_values, [
                $customer_id, $effective_branch_id, $order_date, $invoice_date, $amount,
                $amount, $amount, $amount, 'delivered', 'beginning_balance', 'unpaid', $user_id
            ]);
            $so_types .= 'iissddddsssi';
            $so_id = collectionInsertDynamic($conn, 'sales_orders', $so_columns, $so_values, $so_types);

            $inv_columns = ['invoice_number'];
            $inv_values = [$invoice_number];
            $inv_types = 's';
            if ($invoices_has_si) {
                $inv_columns[] = 'si_number';
                $inv_values[] = $si_number;
                $inv_types .= 's';
            }
            if ($invoices_has_registered_business) {
                $inv_columns[] = 'registered_business_name';
                $inv_values[] = $registered_business_name !== '' ? $registered_business_name : null;
                $inv_types .= 's';
            }
            if ($invoices_has_tin) {
                $inv_columns[] = 'tin';
                $inv_values[] = $tin !== '' ? $tin : null;
                $inv_types .= 's';
            }
            if ($invoices_has_business_address) {
                $inv_columns[] = 'business_address';
                $inv_values[] = $business_address !== '' ? $business_address : null;
                $inv_types .= 's';
            }
            if ($invoices_has_remarks) {
                $inv_columns[] = 'remarks';
                $inv_values[] = $remarks !== '' ? $remarks : null;
                $inv_types .= 's';
            }
            $inv_columns = array_merge($inv_columns, [
                'so_id', 'customer_id', 'branch_id', 'invoice_date', 'due_date',
                'total_amount', 'amount_paid', 'balance', 'status'
            ]);
            $zero_paid = 0.0;
            $pending_status = 'pending';
            $inv_values = array_merge($inv_values, [
                $so_id, $customer_id, $effective_branch_id, $invoice_date, $due_date,
                $amount, $zero_paid, $amount, $pending_status
            ]);
            $inv_types .= 'iiissddds';
            $invoice_id = collectionInsertDynamic($conn, 'invoices', $inv_columns, $inv_values, $inv_types);

            $attachment_count = saveBeginningBalanceAttachments($conn, $invoice_id, $so_id, $customer_id, $effective_branch_id, (int)$user_id);

            recalcCustomerCreditUsed($conn, $customer_id);
            collectionsRecalculateCollectionLinkedCoaBalances($conn, (int)$remittance['branch_id'], $user_id);
            $conn->commit();

            echo json_encode([
                'success' => true,
                'message' => 'Beginning balance saved and added to Customer Invoices (Ready for Collection).' . ($attachment_count > 0 ? ' Attachments uploaded: ' . $attachment_count . '.' : ''),
                'invoice_id' => $invoice_id,
                'invoice_number' => $invoice_number,
                'so_number' => $so_number,
                'si_number' => $si_number,
                'remarks' => $remarks,
                'registered_business_name' => $registered_business_name,
                'tin' => $tin,
                'business_address' => $business_address,
                'attachments' => $attachment_count
            ]);
            exit;
        }

        // BRANCH ADMIN: Update beginning balance records only.
        elseif ($action === 'update_beginning_balance') {
            $data = $_POST;
            if (empty($data) && is_array($json)) $data = $json;
            if (!$data || !is_array($data)) throw new Exception('Invalid beginning balance update data received');

            $invoice_id = (int)($data['invoice_id'] ?? 0);
            $customer_id = (int)($data['customer_id'] ?? 0);
            $document_type = strtolower(trim($data['document_type'] ?? 'so'));
            if (!in_array($document_type, ['so', 'si'], true)) $document_type = 'so';

            $document_number_raw = trim($data['document_number'] ?? ($data['document_digits'] ?? ''));
            $document_digits = preg_replace('/\D+/', '', $document_number_raw);
            $so_digits = $document_type === 'so' ? preg_replace('/\D+/', '', trim($data['so_digits'] ?? $document_digits)) : '';
            $si_number = $document_type === 'si' ? trim($data['si_number'] ?? $document_number_raw) : null;
            $registered_business_name = trim($data['registered_business_name'] ?? '');
            $tin = trim($data['tin'] ?? ($data['tin_number'] ?? ''));
            $business_address = trim($data['business_address'] ?? '');
            $amount = (float)str_replace(',', '', trim($data['amount'] ?? '0'));
            $due_date = trim($data['due_date'] ?? '');
            if ($due_date === '') $due_date = date('Y-m-d');
            $invoice_date = $due_date;
            $remarks = trim($data['remarks'] ?? 'Beginning balance');

            if ($invoice_id <= 0) throw new Exception('Invalid beginning balance record');
            if ($customer_id <= 0) throw new Exception('Please select a customer');
            if ($document_type === 'so') {
                if (!preg_match('/^\d{5,6}$/', $so_digits)) throw new Exception('SO number must be 5 to 6 numbers only');
                $si_number = null;
                $registered_business_name = '';
                $tin = '';
                $business_address = '';
            } else {
                if (!$si_number) throw new Exception('Please enter SI Number');
                if (!$registered_business_name) throw new Exception('Please enter Registered Business Name');
                if (!$tin) throw new Exception('Please enter TIN');
                if (!$business_address) throw new Exception('Please enter Address');
            }
            if ($amount <= 0) throw new Exception('Beginning balance amount must be greater than zero');

            $lookup_sql = "SELECT i.invoice_id, i.so_id, i.customer_id, i.invoice_number,
                                  COALESCE(i.amount_paid, 0) AS amount_paid,
                                  COALESCE(so.fulfillment_type, '') AS fulfillment_type
                           FROM invoices i
                           LEFT JOIN sales_orders so ON so.so_id = i.so_id
                           WHERE i.invoice_id = ?
                             AND LOWER(TRIM(COALESCE(so.fulfillment_type, ''))) = 'beginning_balance'
                           LIMIT 1";
            $lookup_stmt = $conn->prepare($lookup_sql);
            if (!$lookup_stmt) throw new Exception('Failed to prepare beginning balance lookup: ' . $conn->error);
            $lookup_stmt->bind_param('i', $invoice_id);
            $lookup_stmt->execute();
            $existing = $lookup_stmt->get_result()->fetch_assoc();
            $lookup_stmt->close();
            if (!$existing) throw new Exception('Only beginning balance records can be edited. Sales/order records are not editable here.');

            $cust_sql = "SELECT customer_id, customer_name, branch_id FROM customers WHERE customer_id = ? AND status = 'active' AND created_by IN (SELECT user_id FROM users WHERE role = 'motorpool')";
            if ($customers_branch_column_exists && !$view_all_branches && $branch_id > 0) $cust_sql .= " AND branch_id = " . intval($branch_id);
            $cust_sql .= " LIMIT 1";
            $cust_stmt = $conn->prepare($cust_sql);
            if (!$cust_stmt) throw new Exception('Failed to prepare customer lookup: ' . $conn->error);
            $cust_stmt->bind_param('i', $customer_id);
            $cust_stmt->execute();
            $customer = $cust_stmt->get_result()->fetch_assoc();
            $cust_stmt->close();
            if (!$customer) throw new Exception('Customer not found in this branch');

            $date_part = date('Ymd', strtotime($invoice_date));
            $so_id = (int)($existing['so_id'] ?? 0);
            $effective_branch_id = (!$view_all_branches && $branch_id > 0) ? (int)$branch_id : (int)($customer['branch_id'] ?? 0);

            if ($document_type === 'so') {
                $so_number = 'SO-' . $date_part . '-' . $so_digits;
                $invoice_number = 'INV-' . $date_part . '-' . $so_digits;
            } else {
                $so_number = 'SO-' . $date_part . '-' . substr(date('His') . random_int(1000, 9999), 0, 6);
                if ($so_id > 0) {
                    $so_stmt = $conn->prepare("SELECT so_number FROM sales_orders WHERE so_id = ? LIMIT 1");
                    if ($so_stmt) {
                        $so_stmt->bind_param('i', $so_id);
                        $so_stmt->execute();
                        $so_row = $so_stmt->get_result()->fetch_assoc();
                        $so_stmt->close();
                        if (!empty($so_row['so_number'])) $so_number = $so_row['so_number'];
                    }
                }
                $invoice_number = $existing['invoice_number'] ?: ('INV-' . $date_part . '-' . substr(date('His') . random_int(1000, 9999), 0, 6));
            }

            $dup_inv_stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM invoices WHERE invoice_number = ? AND invoice_id <> ?");
            if (!$dup_inv_stmt) throw new Exception('Failed to prepare duplicate invoice check: ' . $conn->error);
            $dup_inv_stmt->bind_param('si', $invoice_number, $invoice_id);
            $dup_inv_stmt->execute();
            $dup_inv = $dup_inv_stmt->get_result()->fetch_assoc();
            $dup_inv_stmt->close();
            if ((int)($dup_inv['cnt'] ?? 0) > 0) throw new Exception('Invoice number already exists: ' . $invoice_number);

            if ($so_id > 0) {
                $dup_so_stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM sales_orders WHERE so_number = ? AND so_id <> ?");
                if (!$dup_so_stmt) throw new Exception('Failed to prepare duplicate SO check: ' . $conn->error);
                $dup_so_stmt->bind_param('si', $so_number, $so_id);
                $dup_so_stmt->execute();
                $dup_so = $dup_so_stmt->get_result()->fetch_assoc();
                $dup_so_stmt->close();
                if ((int)($dup_so['cnt'] ?? 0) > 0) throw new Exception('SO number already exists: ' . $so_number);
            }

            $conn->begin_transaction();
            $paid_amount = (float)($existing['amount_paid'] ?? 0);
            $pay_stmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) AS total_paid FROM payments WHERE invoice_id = ? AND created_by IN (SELECT user_id FROM users WHERE role = 'motorpool') AND (status IS NULL OR status = 'completed')");
            if ($pay_stmt) {
                $pay_stmt->bind_param('i', $invoice_id);
                $pay_stmt->execute();
                $pay_row = $pay_stmt->get_result()->fetch_assoc();
                $pay_stmt->close();
                $paid_amount = max($paid_amount, (float)($pay_row['total_paid'] ?? 0));
            }
            $new_balance = max($amount - $paid_amount, 0);
            $new_status = $new_balance <= 0.009 ? 'paid' : 'pending';

            $invoice_sets = ['invoice_number = ?', 'customer_id = ?', 'branch_id = ?', 'invoice_date = ?', 'due_date = ?', 'total_amount = ?', 'amount_paid = ?', 'balance = ?', 'status = ?'];
            $invoice_values = [$invoice_number, $customer_id, $effective_branch_id, $invoice_date, $due_date, $amount, $paid_amount, $new_balance, $new_status];
            $invoice_types = 'siissddds';
            foreach (['si_number' => $si_number, 'registered_business_name' => ($document_type === 'si' ? $registered_business_name : null), 'tin' => ($document_type === 'si' ? $tin : null), 'business_address' => ($document_type === 'si' ? $business_address : null), 'remarks' => ($remarks !== '' ? $remarks : null)] as $column => $value) {
                if (collectionColumnExists($conn, 'invoices', $column)) {
                    $invoice_sets[] = "`$column` = ?";
                    $invoice_values[] = $value;
                    $invoice_types .= 's';
                }
            }
            $invoice_values[] = $invoice_id;
            $invoice_types .= 'i';
            $invoice_stmt = $conn->prepare("UPDATE invoices SET " . implode(', ', $invoice_sets) . " WHERE invoice_id = ?");
            if (!$invoice_stmt) throw new Exception('Failed to prepare invoice update: ' . $conn->error);
            collectionBindParamsDynamic($invoice_stmt, $invoice_types, $invoice_values);
            if (!$invoice_stmt->execute()) throw new Exception('Failed to update beginning balance invoice: ' . $invoice_stmt->error);
            $invoice_stmt->close();

            if ($so_id > 0) {
                $order_date = date('Y-m-d H:i:s', strtotime($invoice_date . ' ' . date('H:i:s')));
                $so_sets = ['so_number = ?', 'customer_id = ?', 'branch_id = ?', 'order_date = ?', 'delivery_date = ?', 'total_amount = ?', 'order_amount = ?', 'gross_profit = ?', 'gross_profit_amount = ?', "order_status = 'delivered'", "fulfillment_type = 'beginning_balance'"];
                $so_values = [$so_number, $customer_id, $effective_branch_id, $order_date, $invoice_date, $amount, $amount, $amount, $amount];
                $so_types = 'siissdddd';
                foreach (['si_number' => $si_number, 'registered_business_name' => ($document_type === 'si' ? $registered_business_name : null), 'tin' => ($document_type === 'si' ? $tin : null), 'business_address' => ($document_type === 'si' ? $business_address : null), 'remarks' => ($remarks !== '' ? $remarks : null)] as $column => $value) {
                    if (collectionColumnExists($conn, 'sales_orders', $column)) {
                        $so_sets[] = "`$column` = ?";
                        $so_values[] = $value;
                        $so_types .= 's';
                    }
                }
                $so_values[] = $so_id;
                $so_types .= 'i';
                $so_stmt = $conn->prepare("UPDATE sales_orders SET " . implode(', ', $so_sets) . " WHERE so_id = ? AND LOWER(TRIM(COALESCE(fulfillment_type, ''))) = 'beginning_balance'");
                if (!$so_stmt) throw new Exception('Failed to prepare sales order update: ' . $conn->error);
                collectionBindParamsDynamic($so_stmt, $so_types, $so_values);
                if (!$so_stmt->execute()) throw new Exception('Failed to update beginning balance sales order: ' . $so_stmt->error);
                $so_stmt->close();
            }

            $attachment_count = 0;
            if (!empty($_FILES['attachments']) && !empty($_FILES['attachments']['name'])) {
                $names = $_FILES['attachments']['name'];
                if (!is_array($names)) $names = [$names];
                $has_new_attachment = false;
                foreach ($names as $name) {
                    if (trim((string)$name) !== '') { $has_new_attachment = true; break; }
                }
                if ($has_new_attachment) $attachment_count = saveBeginningBalanceAttachments($conn, $invoice_id, $so_id, $customer_id, $effective_branch_id, (int)$user_id);
            }

            recalcCustomerCreditUsed($conn, $customer_id);
            if ((int)($existing['customer_id'] ?? 0) !== $customer_id) recalcCustomerCreditUsed($conn, (int)$existing['customer_id']);
            $conn->commit();

            echo json_encode(['success' => true, 'message' => 'Beginning balance record updated successfully.' . ($attachment_count > 0 ? ' New attachments uploaded: ' . $attachment_count . '.' : ''), 'invoice_id' => $invoice_id]);
            exit;
        }

        // Get all pending invoices (with balance info)
        elseif ($action === 'get_all_pending_invoices') {
            $start_date = trim($_POST['start_date'] ?? ($json['start_date'] ?? ''));
            $end_date = trim($_POST['end_date'] ?? ($json['end_date'] ?? ''));
            $search_query = trim($_POST['search_query'] ?? ($json['search_query'] ?? ''));

            $invoice_si_select = collectionColumnExists($conn, 'invoices', 'si_number')
                ? (collectionColumnExists($conn, 'sales_orders', 'si_number') ? "COALESCE(i.si_number, so.si_number) AS si_number" : "i.si_number AS si_number")
                : (collectionColumnExists($conn, 'sales_orders', 'si_number') ? "so.si_number AS si_number" : "NULL AS si_number");
            $invoice_registered_business_select = collectionColumnExists($conn, 'invoices', 'registered_business_name')
                ? (collectionColumnExists($conn, 'sales_orders', 'registered_business_name') ? "COALESCE(i.registered_business_name, so.registered_business_name) AS registered_business_name" : "i.registered_business_name AS registered_business_name")
                : (collectionColumnExists($conn, 'sales_orders', 'registered_business_name') ? "so.registered_business_name AS registered_business_name" : "NULL AS registered_business_name");
            $invoice_tin_select = collectionColumnExists($conn, 'invoices', 'tin')
                ? (collectionColumnExists($conn, 'sales_orders', 'tin') ? "COALESCE(i.tin, so.tin) AS tin" : "i.tin AS tin")
                : (collectionColumnExists($conn, 'sales_orders', 'tin') ? "so.tin AS tin" : "NULL AS tin");
            $invoice_business_address_select = collectionColumnExists($conn, 'invoices', 'business_address')
                ? (collectionColumnExists($conn, 'sales_orders', 'business_address') ? "COALESCE(i.business_address, so.business_address) AS business_address" : "i.business_address AS business_address")
                : (collectionColumnExists($conn, 'sales_orders', 'business_address') ? "so.business_address AS business_address" : "NULL AS business_address");
            $invoice_remarks_select = collectionColumnExists($conn, 'invoices', 'remarks')
                ? (collectionColumnExists($conn, 'sales_orders', 'remarks') ? "COALESCE(i.remarks, so.remarks) AS remarks" : "i.remarks AS remarks")
                : (collectionColumnExists($conn, 'sales_orders', 'remarks') ? "so.remarks AS remarks" : "NULL AS remarks");
            $sql = "SELECT i.invoice_id, i.invoice_number, {$invoice_si_select}, i.invoice_date, i.due_date,
                           CASE
                               WHEN COALESCE(i.balance, 0) > 0 AND COALESCE(pay.total_paid, 0) <= 0
                                   THEN COALESCE(i.balance, 0)
                               ELSE GREATEST(COALESCE(i.total_amount, 0) - COALESCE(pay.total_paid, 0), 0)
                           END AS total_amount,
                           COALESCE(i.total_amount, 0) AS original_total_amount,
                           COALESCE(pay.total_paid, 0) AS paid_amount,
                           COALESCE(i.balance, 0) AS stored_balance,
                           i.status, i.customer_id,
                           COALESCE(so.order_status, '') AS order_status, COALESCE(so.fulfillment_type, '') AS order_type, so.so_number, {$invoice_registered_business_select}, {$invoice_tin_select}, {$invoice_business_address_select}, {$invoice_remarks_select}, COALESCE(i.branch_id, so.branch_id, c.branch_id, 0) AS branch_id,
                           c.customer_name, c.credit_limit, c.credit_used,
                           COALESCE(NULLIF(TRIM(c.customer_group), ''), 'Ungrouped') AS customer_group
                    FROM invoices i
                    LEFT JOIN sales_orders so ON i.so_id = so.so_id
                    LEFT JOIN customers c ON i.customer_id = c.customer_id
                    LEFT JOIN (
                        SELECT invoice_id, COALESCE(SUM(amount), 0) AS total_paid
                        FROM payments
                        WHERE (status IS NULL OR status = 'completed') AND created_by IN (SELECT user_id FROM users WHERE role = 'motorpool')
                        GROUP BY invoice_id
                    ) pay ON pay.invoice_id = i.invoice_id
                    WHERE c.status = 'active' AND c.created_by IN (SELECT user_id FROM users WHERE role = 'motorpool')
                      AND (
                          CASE
                              WHEN COALESCE(i.balance, 0) > 0 AND COALESCE(pay.total_paid, 0) <= 0
                                  THEN COALESCE(i.balance, 0)
                              ELSE GREATEST(COALESCE(i.total_amount, 0) - COALESCE(pay.total_paid, 0), 0)
                          END
                      ) > 0.009
                      AND (
                          i.status IS NULL
                          OR TRIM(i.status) = ''
                          OR LOWER(TRIM(i.status)) NOT IN ('paid','completed','cancelled','canceled','void','voided','failed')
                      )";
            $filter_values = [];
            $filter_types = '';
            if (!empty($start_date) && !empty($end_date)) {
                $sql .= " AND DATE(i.invoice_date) BETWEEN ? AND ?";
                $filter_values[] = $start_date;
                $filter_values[] = $end_date;
                $filter_types .= 'ss';
            }
            if ($search_query !== '') {
                $search_fields = [
                    "COALESCE(c.customer_name, '')",
                    "COALESCE(NULLIF(TRIM(c.customer_group), ''), 'Ungrouped')",
                    "COALESCE(i.invoice_number, '')",
                    "COALESCE(i.status, '')",
                    "COALESCE(so.so_number, '')",
                    "DATE_FORMAT(i.invoice_date, '%Y-%m-%d')",
                    "DATE_FORMAT(i.due_date, '%Y-%m-%d')"
                ];
                if (collectionColumnExists($conn, 'invoices', 'si_number')) $search_fields[] = "COALESCE(i.si_number, '')";
                if (collectionColumnExists($conn, 'sales_orders', 'si_number')) $search_fields[] = "COALESCE(so.si_number, '')";
                if (collectionColumnExists($conn, 'invoices', 'registered_business_name')) $search_fields[] = "COALESCE(i.registered_business_name, '')";
                if (collectionColumnExists($conn, 'sales_orders', 'registered_business_name')) $search_fields[] = "COALESCE(so.registered_business_name, '')";
                if (collectionColumnExists($conn, 'invoices', 'tin')) $search_fields[] = "COALESCE(i.tin, '')";
                if (collectionColumnExists($conn, 'sales_orders', 'tin')) $search_fields[] = "COALESCE(so.tin, '')";
                if (collectionColumnExists($conn, 'invoices', 'remarks')) $search_fields[] = "COALESCE(i.remarks, '')";
                if (collectionColumnExists($conn, 'sales_orders', 'remarks')) $search_fields[] = "COALESCE(so.remarks, '')";

                $sql .= " AND (" . implode(' LIKE ? OR ', $search_fields) . " LIKE ?)";
                $like_search = '%' . $search_query . '%';
                foreach ($search_fields as $_field) {
                    $filter_values[] = $like_search;
                    $filter_types .= 's';
                }
            }
            if (!$view_all_branches && $branch_id > 0) $sql .= " AND COALESCE(i.branch_id, so.branch_id, c.branch_id, 0) = " . intval($branch_id);
            $sql .= " ORDER BY i.invoice_date DESC";

            $stmt = $conn->prepare($sql);
            if (!$stmt) throw new Exception('Failed to prepare invoice query: ' . $conn->error);
            if (!empty($filter_values)) collectionBindParamsDynamic($stmt, $filter_types, $filter_values);
            $stmt->execute();
            $invoices = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            // Check for pending remittances to exclude them from being collected again
            foreach ($invoices as &$invoice) {
                $pending_remit_sql = "SELECT COUNT(*) as cnt FROM collection_records WHERE invoice_id = ? AND status = 'remitted'";
                $pending_stmt = $conn->prepare($pending_remit_sql);
                $pending_stmt->bind_param('i', $invoice['invoice_id']);
                $pending_stmt->execute();
                $pending_result = $pending_stmt->get_result()->fetch_assoc();
                $invoice['has_pending_remittance'] = ($pending_result['cnt'] > 0);
                $pending_stmt->close();
                
                $payment_sql = "SELECT p.*, u.first_name, u.last_name 
                                FROM payments p
                                LEFT JOIN users u ON p.created_by = u.user_id
                                WHERE p.invoice_id = ?
                                ORDER BY p.payment_date DESC LIMIT 1";
                $payment_stmt = $conn->prepare($payment_sql);
                if ($payment_stmt) {
                    $payment_stmt->bind_param("i", $invoice['invoice_id']);
                    $payment_stmt->execute();
                    $invoice['payment'] = $payment_stmt->get_result()->fetch_assoc();
                    $payment_stmt->close();
                } else $invoice['payment'] = null;
            }

            enrichInvoicesWithPaymentBalances($conn, $invoices);
            enrichInvoicesWithCollectorAssignments($conn, $invoices);
            enrichInvoicesWithBeginningBalanceAttachments($conn, $invoices);

            echo json_encode(['success' => true, 'invoices' => $invoices]);
            exit;
        }
        
        // Get specific customer invoices
        elseif ($action === 'get_all_invoices') {
            $customer_id = (int)($_POST['customer_id'] ?? ($json['customer_id'] ?? 0));
            $start_date = trim($_POST['start_date'] ?? ($json['start_date'] ?? ''));
            $end_date = trim($_POST['end_date'] ?? ($json['end_date'] ?? ''));

            if (!$customer_id) throw new Exception('Invalid customer');

            $invoice_si_select = collectionColumnExists($conn, 'invoices', 'si_number')
                ? (collectionColumnExists($conn, 'sales_orders', 'si_number') ? "COALESCE(i.si_number, so.si_number) AS si_number" : "i.si_number AS si_number")
                : (collectionColumnExists($conn, 'sales_orders', 'si_number') ? "so.si_number AS si_number" : "NULL AS si_number");
            $invoice_registered_business_select = collectionColumnExists($conn, 'invoices', 'registered_business_name')
                ? (collectionColumnExists($conn, 'sales_orders', 'registered_business_name') ? "COALESCE(i.registered_business_name, so.registered_business_name) AS registered_business_name" : "i.registered_business_name AS registered_business_name")
                : (collectionColumnExists($conn, 'sales_orders', 'registered_business_name') ? "so.registered_business_name AS registered_business_name" : "NULL AS registered_business_name");
            $invoice_tin_select = collectionColumnExists($conn, 'invoices', 'tin')
                ? (collectionColumnExists($conn, 'sales_orders', 'tin') ? "COALESCE(i.tin, so.tin) AS tin" : "i.tin AS tin")
                : (collectionColumnExists($conn, 'sales_orders', 'tin') ? "so.tin AS tin" : "NULL AS tin");
            $invoice_business_address_select = collectionColumnExists($conn, 'invoices', 'business_address')
                ? (collectionColumnExists($conn, 'sales_orders', 'business_address') ? "COALESCE(i.business_address, so.business_address) AS business_address" : "i.business_address AS business_address")
                : (collectionColumnExists($conn, 'sales_orders', 'business_address') ? "so.business_address AS business_address" : "NULL AS business_address");
            $invoice_remarks_select = collectionColumnExists($conn, 'invoices', 'remarks')
                ? (collectionColumnExists($conn, 'sales_orders', 'remarks') ? "COALESCE(i.remarks, so.remarks) AS remarks" : "i.remarks AS remarks")
                : (collectionColumnExists($conn, 'sales_orders', 'remarks') ? "so.remarks AS remarks" : "NULL AS remarks");
            $sql = "SELECT i.invoice_id, i.invoice_number, {$invoice_si_select}, i.invoice_date, i.due_date,
                           CASE
                               WHEN COALESCE(i.balance, 0) > 0 AND COALESCE(pay.total_paid, 0) <= 0
                                   THEN COALESCE(i.balance, 0)
                               ELSE GREATEST(COALESCE(i.total_amount, 0) - COALESCE(pay.total_paid, 0), 0)
                           END AS total_amount,
                           COALESCE(i.total_amount, 0) AS original_total_amount,
                           COALESCE(pay.total_paid, 0) AS paid_amount,
                           COALESCE(i.balance, 0) AS stored_balance,
                           i.status,
                           COALESCE(so.order_status, '') AS order_status, COALESCE(so.fulfillment_type, '') AS order_type, so.so_number, {$invoice_registered_business_select}, {$invoice_tin_select}, {$invoice_business_address_select}, {$invoice_remarks_select},
                           c.customer_name, c.customer_id,
                           COALESCE(NULLIF(TRIM(c.customer_group), ''), 'Ungrouped') AS customer_group
                    FROM invoices i
                    LEFT JOIN sales_orders so ON i.so_id = so.so_id
                    LEFT JOIN customers c ON i.customer_id = c.customer_id
                    LEFT JOIN (
                        SELECT invoice_id, COALESCE(SUM(amount), 0) AS total_paid
                        FROM payments
                        WHERE (status IS NULL OR status = 'completed') AND created_by IN (SELECT user_id FROM users WHERE role = 'motorpool')
                        GROUP BY invoice_id
                    ) pay ON pay.invoice_id = i.invoice_id
                    WHERE i.customer_id = ?
                    AND (
                        CASE
                            WHEN COALESCE(i.balance, 0) > 0 AND COALESCE(pay.total_paid, 0) <= 0
                                THEN COALESCE(i.balance, 0)
                            ELSE GREATEST(COALESCE(i.total_amount, 0) - COALESCE(pay.total_paid, 0), 0)
                        END
                    ) > 0.009
                    AND (
                        i.status IS NULL
                        OR TRIM(i.status) = ''
                        OR LOWER(TRIM(i.status)) NOT IN ('paid','completed','cancelled','canceled','void','voided','failed')
                    )";
            if (!empty($start_date) && !empty($end_date)) $sql .= " AND DATE(i.invoice_date) BETWEEN ? AND ?";
            $sql .= " ORDER BY i.invoice_date DESC";

            $stmt = $conn->prepare($sql);
            if (!$stmt) throw new Exception('Failed to prepare invoice query: ' . $conn->error);
            if (!empty($start_date) && !empty($end_date)) $stmt->bind_param("iss", $customer_id, $start_date, $end_date);
            else $stmt->bind_param("i", $customer_id);
            $stmt->execute();
            $invoices = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            $credit_sql = "SELECT credit_limit, credit_used FROM customers WHERE customer_id = ?";
            $credit_stmt = $conn->prepare($credit_sql);
            $credit_stmt->bind_param("i", $customer_id);
            $credit_stmt->execute();
            $credit_data = $credit_stmt->get_result()->fetch_assoc() ?: [];
            $credit_stmt->close();
            $credit_limit = (float)($credit_data['credit_limit'] ?? 0);
            $credit_used = recalcCustomerCreditUsed($conn, $customer_id);

            foreach ($invoices as &$invoice) {
                // Check for pending remittance
                $pending_remit_sql = "SELECT COUNT(*) as cnt FROM collection_records WHERE invoice_id = ? AND status = 'remitted'";
                $pending_stmt = $conn->prepare($pending_remit_sql);
                $pending_stmt->bind_param('i', $invoice['invoice_id']);
                $pending_stmt->execute();
                $pending_result = $pending_stmt->get_result()->fetch_assoc();
                $invoice['has_pending_remittance'] = ($pending_result['cnt'] > 0);
                $pending_stmt->close();
                
                $payment_sql = "SELECT p.*, u.first_name, u.last_name 
                                FROM payments p
                                LEFT JOIN users u ON p.created_by = u.user_id
                                WHERE p.invoice_id = ?
                                ORDER BY p.payment_date DESC LIMIT 1";
                $payment_stmt = $conn->prepare($payment_sql);
                if ($payment_stmt) {
                    $payment_stmt->bind_param("i", $invoice['invoice_id']);
                    $payment_stmt->execute();
                    $invoice['payment'] = $payment_stmt->get_result()->fetch_assoc();
                    $payment_stmt->close();
                } else $invoice['payment'] = null;
            }

            enrichInvoicesWithPaymentBalances($conn, $invoices);
            enrichInvoicesWithCollectorAssignments($conn, $invoices);
            enrichInvoicesWithBeginningBalanceAttachments($conn, $invoices);

            echo json_encode([
                'success' => true,
                'invoices' => $invoices,
                'credit_limit' => $credit_limit,
                'credit_used' => $credit_used,
                'available_credit' => $credit_limit - $credit_used
            ]);
            exit;
        }
        
        // Assign collector to multiple invoices
        elseif ($action === 'assign_multiple_collectors') {
            $data = is_array($json) ? $json : $_POST;
            if (!$data || !is_array($data)) throw new Exception('Invalid assignment data received');
            
            $assigned_user_id = (int)($data['assigned_user_id'] ?? ($data['collector_id'] ?? 0));
            $collection_date = trim($data['collection_date'] ?? date('Y-m-d'));
            $selected_invoices = $data['selected_invoices'] ?? [];
            
            if (empty($selected_invoices) || !is_array($selected_invoices)) {
                throw new Exception('No invoices selected for assignment');
            }
            
            if ($assigned_user_id <= 0) {
                throw new Exception('Please select a collector');
            }
            
            // Validate collector
            $has_user_branch = collectionColumnExists($conn, 'users', 'branch_id');
            $has_user_driver_id = collectionColumnExists($conn, 'users', 'driver_id');
            $has_driver_branch = collectionTableExists($conn, 'drivers') && collectionColumnExists($conn, 'drivers', 'branch_id');
            $driver_join = ($has_user_driver_id && $has_driver_branch) ? "LEFT JOIN drivers d ON d.driver_id = u.driver_id" : "";
            $collector_sql = "SELECT DISTINCT u.user_id
                              FROM users u
                              $driver_join
                              WHERE u.user_id = ?
                                AND u.status = 'active'
                                AND u.role IN ('sales','delivery')";
            $branchParams = [];
            if ($branch_id > 0 && ($has_user_branch || ($has_driver_branch && $has_user_driver_id))) {
                $branchParts = [];
                if ($has_user_branch) {
                    $branchParts[] = "u.branch_id = ?";
                    $branchParams[] = $branch_id;
                }
                if ($has_driver_branch && $has_user_driver_id) {
                    $branchParts[] = "d.branch_id = ?";
                    $branchParams[] = $branch_id;
                }
                if (!empty($branchParts)) $collector_sql .= " AND (" . implode(' OR ', $branchParts) . ")";
            }
            
            $collector_stmt = $conn->prepare($collector_sql);
            if (!$collector_stmt) throw new Exception('Failed to prepare collector lookup: ' . $conn->error);
            if (count($branchParams) === 2) {
                $collector_stmt->bind_param('iii', $assigned_user_id, $branchParams[0], $branchParams[1]);
            } elseif (count($branchParams) === 1) {
                $collector_stmt->bind_param('ii', $assigned_user_id, $branchParams[0]);
            } else {
                $collector_stmt->bind_param('i', $assigned_user_id);
            }
            $collector_stmt->execute();
            $collector_exists = $collector_stmt->get_result()->fetch_assoc();
            $collector_stmt->close();
            if (!$collector_exists) throw new Exception('Selected collector must be an active Sales Agent or Driver registered in your branch.');
            
            $invoice_ids_for_assignment = [];
            foreach ($selected_invoices as $inv) {
                $invoice_ids_for_assignment[] = [
                    'invoice_id' => $inv['invoice_id'],
                    'customer_id' => $inv['customer_id'],
                    'branch_id' => $inv['branch_id']
                ];
            }
            
            saveMultipleCollectionAssignments($conn, $invoice_ids_for_assignment, $assigned_user_id, (int)$user_id, $collection_date);
            
            echo json_encode(['success' => true, 'message' => count($selected_invoices) . ' invoice(s) assigned to collector successfully.']);
            exit;
        }
        
        // Assign collector to single invoice
        elseif ($action === 'assign_collector') {
            $data = is_array($json) ? $json : $_POST;
            if (!$data || !is_array($data)) throw new Exception('Invalid assignment data received');

            $invoice_id = (int)($data['invoice_id'] ?? 0);
            $assigned_user_id = (int)($data['assigned_user_id'] ?? ($data['collector_id'] ?? 0));
            $collection_date = trim($data['collection_date'] ?? '');

            if ($invoice_id <= 0) throw new Exception('Invalid invoice selected');
            if ($assigned_user_id <= 0) throw new Exception('Please select a collector');

            $inv_sql = "SELECT i.invoice_id, i.customer_id, i.status, COALESCE(i.total_amount, 0) AS total_amount, COALESCE(so.branch_id, c.branch_id, 0) AS source_branch_id
                        FROM invoices i
                        LEFT JOIN sales_orders so ON i.so_id = so.so_id
                        LEFT JOIN customers c ON i.customer_id = c.customer_id
                        WHERE i.invoice_id = ?
                        AND c.created_by IN (SELECT user_id FROM users WHERE role = 'motorpool')
                        LIMIT 1";
            $inv_stmt = $conn->prepare($inv_sql);
            if (!$inv_stmt) throw new Exception('Failed to prepare invoice lookup: ' . $conn->error);
            $inv_stmt->bind_param('i', $invoice_id);
            $inv_stmt->execute();
            $invoice = $inv_stmt->get_result()->fetch_assoc();
            $inv_stmt->close();

            if (!$invoice) throw new Exception('Invoice not found');
            if (($invoice['status'] ?? '') === 'paid') throw new Exception('This invoice is already paid');

            if (!$view_all_branches && $branch_id > 0) {
                $invoice_branch_id = (int)($invoice['source_branch_id'] ?? 0);
                if ($invoice_branch_id > 0 && $invoice_branch_id !== (int)$branch_id) {
                    throw new Exception('Invoice does not belong to your branch');
                }
            }

            $has_user_branch = collectionColumnExists($conn, 'users', 'branch_id');
            $has_user_driver_id = collectionColumnExists($conn, 'users', 'driver_id');
            $has_driver_branch = collectionTableExists($conn, 'drivers') && collectionColumnExists($conn, 'drivers', 'branch_id');
            $driver_join = ($has_user_driver_id && $has_driver_branch) ? "LEFT JOIN drivers d ON d.driver_id = u.driver_id" : "";
            $collector_sql = "SELECT DISTINCT u.user_id
                              FROM users u
                              $driver_join
                              WHERE u.user_id = ?
                                AND u.status = 'active'
                                AND u.role IN ('sales','delivery')";
            $branchParams = [];
            if ($branch_id > 0 && ($has_user_branch || ($has_driver_branch && $has_user_driver_id))) {
                $branchParts = [];
                if ($has_user_branch) {
                    $branchParts[] = "u.branch_id = ?";
                    $branchParams[] = $branch_id;
                }
                if ($has_driver_branch && $has_user_driver_id) {
                    $branchParts[] = "d.branch_id = ?";
                    $branchParams[] = $branch_id;
                }
                if (!empty($branchParts)) $collector_sql .= " AND (" . implode(' OR ', $branchParts) . ")";
            }

            $collector_stmt = $conn->prepare($collector_sql);
            if (!$collector_stmt) throw new Exception('Failed to prepare collector lookup: ' . $conn->error);
            if (count($branchParams) === 2) {
                $collector_stmt->bind_param('iii', $assigned_user_id, $branchParams[0], $branchParams[1]);
            } elseif (count($branchParams) === 1) {
                $collector_stmt->bind_param('ii', $assigned_user_id, $branchParams[0]);
            } else {
                $collector_stmt->bind_param('i', $assigned_user_id);
            }
            $collector_stmt->execute();
            $collector_exists = $collector_stmt->get_result()->fetch_assoc();
            $collector_stmt->close();
            if (!$collector_exists) throw new Exception('Selected collector must be an active Sales Agent or Driver registered in your branch.');

            $conn->begin_transaction();
            $effective_branch_id = (!$view_all_branches && $branch_id > 0) ? (int)$branch_id : (int)($invoice['source_branch_id'] ?? 0);
            saveCollectionAssignment($conn, $invoice_id, (int)$invoice['customer_id'], $effective_branch_id, $assigned_user_id, (int)$user_id, $collection_date);
            $conn->commit();

            echo json_encode(['success' => true, 'message' => 'Collector assigned successfully.']);
            exit;
        }

        echo json_encode(['success' => false, 'message' => 'Invalid action']);
        exit;
    } catch (Exception $e) {
        if (isset($conn) && $conn instanceof mysqli) $conn->rollback();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

// Load default pending invoices for initial view
$default_invoices_query = "SELECT i.invoice_id, i.invoice_number, i.invoice_date, i.due_date,
                           CASE
                               WHEN COALESCE(i.balance, 0) > 0 AND COALESCE(pay.total_paid, 0) <= 0
                                   THEN COALESCE(i.balance, 0)
                               ELSE GREATEST(COALESCE(i.total_amount, 0) - COALESCE(pay.total_paid, 0), 0)
                           END AS total_amount,
                           COALESCE(i.total_amount, 0) AS original_total_amount,
                           COALESCE(pay.total_paid, 0) AS paid_amount,
                           COALESCE(i.balance, 0) AS stored_balance,
                           i.status, i.customer_id,
                           COALESCE(so.order_status, '') AS order_status, COALESCE(so.fulfillment_type, '') AS order_type, so.so_number, COALESCE(so.branch_id, c.branch_id, 0) AS branch_id,
                           c.customer_name,
                           COALESCE(NULLIF(TRIM(c.customer_group), ''), 'Ungrouped') AS customer_group
                    FROM invoices i
                    LEFT JOIN sales_orders so ON i.so_id = so.so_id
                    LEFT JOIN customers c ON i.customer_id = c.customer_id
                    LEFT JOIN (
                        SELECT invoice_id, COALESCE(SUM(amount), 0) AS total_paid
                        FROM payments
                        WHERE (status IS NULL OR status = 'completed') AND created_by IN (SELECT user_id FROM users WHERE role = 'motorpool')
                        GROUP BY invoice_id
                    ) pay ON pay.invoice_id = i.invoice_id
                    WHERE c.status = 'active' AND c.created_by IN (SELECT user_id FROM users WHERE role = 'motorpool')
                    AND (
                        CASE
                            WHEN COALESCE(i.balance, 0) > 0 AND COALESCE(pay.total_paid, 0) <= 0
                                THEN COALESCE(i.balance, 0)
                            ELSE GREATEST(COALESCE(i.total_amount, 0) - COALESCE(pay.total_paid, 0), 0)
                        END
                    ) > 0.009
                    AND (
                        i.status IS NULL
                        OR TRIM(i.status) = ''
                        OR LOWER(TRIM(i.status)) NOT IN ('paid','completed','cancelled','canceled','void','voided','failed')
                    )
                    AND NOT EXISTS (
                        SELECT 1 FROM collection_records cr
                        WHERE cr.invoice_id = i.invoice_id AND cr.status = 'remitted'
                    )";
if ($customers_branch_column_exists && !$view_all_branches && $branch_id > 0) {
    $default_invoices_query .= " AND c.branch_id = " . intval($branch_id);
}
$default_invoices_query .= " ORDER BY i.invoice_date DESC";
$default_invoices_result = $conn->query($default_invoices_query);
$default_invoices = $default_invoices_result ? $default_invoices_result->fetch_all(MYSQLI_ASSOC) : [];
enrichInvoicesWithPaymentBalances($conn, $default_invoices);
enrichInvoicesWithCollectorAssignments($conn, $default_invoices);

$customer_group_tabs = [];
$total_group_receivables = 0.0;
foreach ($default_invoices as $default_invoice_for_group) {
    $group_name = trim((string)($default_invoice_for_group['customer_group'] ?? ''));
    if ($group_name === '') $group_name = 'Ungrouped';

    if (!isset($customer_group_tabs[$group_name])) {
        $customer_group_tabs[$group_name] = [
            'group_name' => $group_name,
            'count' => 0,
            'total' => 0.0
        ];
    }

    $amount_for_group = (float)($default_invoice_for_group['total_amount'] ?? 0);
    $customer_group_tabs[$group_name]['count']++;
    $customer_group_tabs[$group_name]['total'] += $amount_for_group;
    $total_group_receivables += $amount_for_group;
}
ksort($customer_group_tabs, SORT_NATURAL | SORT_FLAG_CASE);

// Get pending remittances for display
$pending_remittances_query = "SELECT cr.record_id AS remittance_id,
                              cr.invoice_id, cr.customer_id, cr.branch_id, cr.collector_user_id,
                              cr.payment_method, cr.amount, cr.collection_date,
                              COALESCE(cr.remitted_at, cr.created_at) AS remittance_date,
                              cr.reference_number, cr.check_date, cr.bank_name, cr.bank_branch,
                              cr.check_number, cr.cash_tendered, cr.cash_change, cr.attachment_path, cr.attachment_name, cr.notes,
                              cr.status,
                              i.invoice_number, i.invoice_date, i.due_date, i.total_amount,
                              c.customer_name,
                              u.first_name as collector_first, u.last_name as collector_last
                       FROM collection_records cr
                       LEFT JOIN invoices i ON cr.invoice_id = i.invoice_id
                       LEFT JOIN customers c ON cr.customer_id = c.customer_id
                       LEFT JOIN users u ON cr.collector_user_id = u.user_id
                       WHERE c.created_by IN (SELECT user_id FROM users WHERE role = 'motorpool') AND cr.status = 'remitted'";
if (!$view_all_branches && $branch_id > 0) {
    $pending_remittances_query .= " AND cr.branch_id = " . intval($branch_id);
}
$pending_remittances_query .= " ORDER BY COALESCE(cr.remitted_at, cr.created_at) DESC";
$pending_remittances_result = $conn->query($pending_remittances_query);
$pending_remittances = $pending_remittances_result ? $pending_remittances_result->fetch_all(MYSQLI_ASSOC) : [];

// Returned invoice tickets from collectors
$returned_invoices_query = "SELECT cir.return_id, cir.assignment_id, cir.invoice_id, cir.customer_id, cir.branch_id,
                                   cir.returned_by, cir.returned_to, cir.return_reason, cir.attachment_path, cir.attachment_name,
                                   cir.status, cir.created_at,
                                   i.invoice_number, i.total_amount, i.status AS invoice_status,
                                   COALESCE(pay.total_paid, 0) AS paid_amount,
                                   GREATEST(COALESCE(i.total_amount, 0) - COALESCE(pay.total_paid, 0), 0) AS balance_amount,
                                   c.customer_name,
                                   u.first_name AS returned_first, u.last_name AS returned_last
                            FROM collection_invoice_returns cir
                            LEFT JOIN invoices i ON i.invoice_id = cir.invoice_id
                            LEFT JOIN (
                                SELECT invoice_id, COALESCE(SUM(amount), 0) AS total_paid
                                FROM payments
                                WHERE (status IS NULL OR status = 'completed') AND created_by IN (SELECT user_id FROM users WHERE role = 'motorpool')
                                GROUP BY invoice_id
                            ) pay ON pay.invoice_id = cir.invoice_id
                            LEFT JOIN customers c ON c.customer_id = cir.customer_id
                            LEFT JOIN users u ON u.user_id = cir.returned_by
                            WHERE cir.status IN ('returned','pending')";
if (!$view_all_branches && $branch_id > 0) {
    $returned_invoices_query .= " AND cir.branch_id = " . intval($branch_id);
}
$returned_invoices_query .= " ORDER BY cir.created_at DESC";
$returned_invoices_result = $conn->query($returned_invoices_query);
$returned_invoices = $returned_invoices_result ? $returned_invoices_result->fetch_all(MYSQLI_ASSOC) : [];


// ================= PRINTABLE COLLECTION REPORT DATA =================
// This report is branch-scoped. It combines:
// 1) approved/collected collection_records from Sales Agents and Drivers
// 2) direct completed payments encoded by Branch Admin, excluding payments already tied to approved collection_records
$collection_report_rows = [];
$collection_report_totals = [
    'all' => 0.00,
    'branch_admin' => 0.00,
    'sales' => 0.00,
    'delivery' => 0.00
];
$collection_report_collectors = [];

$report_branch_condition_cr = '';
$report_branch_condition_payment = '';
if (!$view_all_branches && $branch_id > 0) {
    $safe_branch_id = intval($branch_id);
    $report_branch_condition_cr = " AND cr.branch_id = {$safe_branch_id}";
    $report_branch_condition_payment = " AND COALESCE(so.branch_id, c.branch_id, 0) = {$safe_branch_id}";
}

$collection_records_report_query = "SELECT
        cr.record_id AS source_id,
        'collection_records' AS source_table,
        cr.invoice_id,
        cr.customer_id,
        cr.branch_id,
        cr.collector_user_id AS collector_user_id,
        cr.payment_method,
        cr.amount,
        cr.collection_date,
        cr.reference_number,
        cr.check_date,
        cr.bank_name,
        cr.bank_branch,
        cr.check_number,
        cr.notes,
        cr.status AS collection_status,
        i.invoice_number,
        c.customer_name,
        u.first_name,
        u.last_name,
        u.role
    FROM collection_records cr
    LEFT JOIN invoices i ON i.invoice_id = cr.invoice_id
    LEFT JOIN sales_orders so ON so.so_id = i.so_id
    LEFT JOIN customers c ON c.customer_id = cr.customer_id
    LEFT JOIN users u ON u.user_id = cr.collector_user_id
    WHERE c.created_by IN (SELECT user_id FROM users WHERE role = 'motorpool') AND cr.status IN ('approved','collected')
    {$report_branch_condition_cr}
    ORDER BY cr.collection_date DESC";
$collection_records_report_result = $conn->query($collection_records_report_query);
if ($collection_records_report_result) {
    while ($row = $collection_records_report_result->fetch_assoc()) {
        $collector_name = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
        if ($collector_name === '') $collector_name = 'Unknown Collector';
        $role = $row['role'] ?? '';
        $role_label = $role === 'delivery' ? 'Driver' : ($role === 'sales' ? 'Sales Agent' : ($role === 'branch_admin' ? 'Branch Admin' : ucfirst($role ?: 'Collector')));
        $amount = (float)($row['amount'] ?? 0);

        $report_row = [
            'source_id' => $row['source_id'],
            'source_table' => $row['source_table'],
            'invoice_number' => $row['invoice_number'] ?? '',
            'customer_name' => $row['customer_name'] ?? '',
            'collector_user_id' => (int)($row['collector_user_id'] ?? 0),
            'collector_name' => $collector_name,
            'role' => $role,
            'role_label' => $role_label,
            'payment_method' => $row['payment_method'] ?? '',
            'amount' => $amount,
            'collection_date' => $row['collection_date'] ?? '',
            'reference_number' => $row['reference_number'] ?? '',
            'check_date' => $row['check_date'] ?? '',
            'bank_name' => $row['bank_name'] ?? '',
            'bank_branch' => $row['bank_branch'] ?? '',
            'check_number' => $row['check_number'] ?? '',
            'notes' => $row['notes'] ?? '',
            'collection_status' => $row['collection_status'] ?? ''
        ];
        $collection_report_rows[] = $report_row;

        $collection_report_totals['all'] += $amount;
        if (isset($collection_report_totals[$role])) $collection_report_totals[$role] += $amount;

        $collector_key = (int)$report_row['collector_user_id'];
        if ($collector_key > 0) {
            $collection_report_collectors[$collector_key] = [
                'user_id' => $collector_key,
                'name' => $collector_name,
                'role' => $role,
                'role_label' => $role_label
            ];
        }
    }
}

$direct_payments_report_query = "SELECT
        p.payment_id AS source_id,
        'payments' AS source_table,
        p.invoice_id,
        p.customer_id,
        COALESCE(so.branch_id, c.branch_id, 0) AS branch_id,
        p.created_by AS collector_user_id,
        p.payment_method,
        p.amount,
        p.payment_date AS collection_date,
        p.reference_number,
        p.check_date,
        p.bank_name,
        p.bank_branch,
        p.check_number,
        NULL AS notes,
        p.status AS collection_status,
        i.invoice_number,
        c.customer_name,
        u.first_name,
        u.last_name,
        u.role
    FROM payments p
    LEFT JOIN invoices i ON i.invoice_id = p.invoice_id
    LEFT JOIN sales_orders so ON so.so_id = i.so_id
    LEFT JOIN customers c ON c.customer_id = p.customer_id
    LEFT JOIN users u ON u.user_id = p.created_by
    WHERE (p.status IS NULL OR p.status = 'completed')
      AND NOT EXISTS (
          SELECT 1
          FROM collection_records crx
          WHERE crx.invoice_id = p.invoice_id
            AND crx.customer_id = p.customer_id
            AND crx.amount = p.amount
            AND crx.status IN ('approved','collected')
      )
      {$report_branch_condition_payment}
    ORDER BY p.payment_date DESC";
$direct_payments_report_result = $conn->query($direct_payments_report_query);
if ($direct_payments_report_result) {
    while ($row = $direct_payments_report_result->fetch_assoc()) {
        $collector_name = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
        if ($collector_name === '') $collector_name = 'Unknown Collector';
        $role = $row['role'] ?? '';
        $role_label = $role === 'delivery' ? 'Driver' : ($role === 'sales' ? 'Sales Agent' : ($role === 'branch_admin' ? 'Branch Admin' : ucfirst($role ?: 'Collector')));
        $amount = (float)($row['amount'] ?? 0);

        $report_row = [
            'source_id' => $row['source_id'],
            'source_table' => $row['source_table'],
            'invoice_number' => $row['invoice_number'] ?? '',
            'customer_name' => $row['customer_name'] ?? '',
            'collector_user_id' => (int)($row['collector_user_id'] ?? 0),
            'collector_name' => $collector_name,
            'role' => $role,
            'role_label' => $role_label,
            'payment_method' => $row['payment_method'] ?? '',
            'amount' => $amount,
            'collection_date' => $row['collection_date'] ?? '',
            'reference_number' => $row['reference_number'] ?? '',
            'check_date' => $row['check_date'] ?? '',
            'bank_name' => $row['bank_name'] ?? '',
            'bank_branch' => $row['bank_branch'] ?? '',
            'check_number' => $row['check_number'] ?? '',
            'notes' => $row['notes'] ?? '',
            'collection_status' => $row['collection_status'] ?? ''
        ];
        $collection_report_rows[] = $report_row;

        $collection_report_totals['all'] += $amount;
        if (isset($collection_report_totals[$role])) $collection_report_totals[$role] += $amount;

        $collector_key = (int)$report_row['collector_user_id'];
        if ($collector_key > 0) {
            $collection_report_collectors[$collector_key] = [
                'user_id' => $collector_key,
                'name' => $collector_name,
                'role' => $role,
                'role_label' => $role_label
            ];
        }
    }
}

usort($collection_report_rows, function($a, $b) {
    return strtotime($b['collection_date'] ?? '') <=> strtotime($a['collection_date'] ?? '');
});

uasort($collection_report_collectors, function($a, $b) {
    $roleCompare = strcmp($a['role_label'] ?? '', $b['role_label'] ?? '');
    if ($roleCompare !== 0) return $roleCompare;
    return strcmp($a['name'] ?? '', $b['name'] ?? '');
});

$collection_report_json = json_encode(array_values($collection_report_rows), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
$collection_report_totals_json = json_encode($collection_report_totals, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);

// Statistics calculations
// Fixed stat cards: compute receivables from invoice balance, not only from strict status text.
// Some records may have status values other than pending/overdue, so paid/cancelled/void records are excluded instead.
$branch_filter_sql = '';
if (!$view_all_branches && $branch_id > 0) {
    $safe_branch_id = (int)$branch_id;
    $branch_filter_sql = " AND COALESCE(i.branch_id, so.branch_id, c.branch_id, 0) = {$safe_branch_id}";
}

$receivables_query = "SELECT
                            i.invoice_id,
                            i.invoice_number,
                            i.invoice_date,
                            i.due_date,
                            COALESCE(i.total_amount, 0) AS original_total_amount,
                            CASE
                                WHEN COALESCE(i.balance, 0) > 0 AND COALESCE(pay.total_paid, 0) <= 0
                                    THEN COALESCE(i.balance, 0)
                                ELSE GREATEST(COALESCE(i.total_amount, 0) - COALESCE(pay.total_paid, 0), 0)
                            END AS total_amount,
                            COALESCE(pay.total_paid, 0) AS paid_amount,
                            COALESCE(i.balance, 0) AS stored_balance,
                            i.status,
                            i.remarks,
                            i.paid_at,
                            COALESCE(so.order_status, '') AS order_status,
                            so.delivery_date,
                            pay.last_payment_date AS actual_payment_date,
                            COALESCE(i.branch_id, so.branch_id, c.branch_id, 0) AS branch_id
                      FROM invoices i
                      LEFT JOIN sales_orders so ON i.so_id = so.so_id
                      LEFT JOIN customers c ON i.customer_id = c.customer_id
                      LEFT JOIN (
                          SELECT invoice_id,
                                 COALESCE(SUM(amount), 0) AS total_paid,
                                 MAX(payment_date) AS last_payment_date
                          FROM payments
                          WHERE status IS NULL OR status = 'completed'
                          GROUP BY invoice_id
                      ) pay ON pay.invoice_id = i.invoice_id
                      WHERE c.status = 'active' AND c.created_by IN (SELECT user_id FROM users WHERE role = 'motorpool')
                        AND (
                            CASE
                                WHEN COALESCE(i.balance, 0) > 0 AND COALESCE(pay.total_paid, 0) <= 0
                                    THEN COALESCE(i.balance, 0)
                                ELSE GREATEST(COALESCE(i.total_amount, 0) - COALESCE(pay.total_paid, 0), 0)
                            END
                        ) > 0.009
                        AND (
                            i.status IS NULL
                            OR TRIM(i.status) = ''
                            OR LOWER(TRIM(i.status)) NOT IN ('paid','completed','cancelled','canceled','void','voided','failed')
                        )
                        {$branch_filter_sql}
                      ORDER BY i.invoice_date DESC";
$receivables_result = $conn->query($receivables_query);
$receivables = $receivables_result ? $receivables_result->fetch_all(MYSQLI_ASSOC) : [];

$total_receivables = 0;
$overdue_receivables = 0;
$aging_1_7 = 0;
$aging_8_14 = 0;
$aging_15_21 = 0;
$aging_22_28 = 0;
$aging_beyond_28 = 0;
$total_days_outstanding = 0;
$count_unpaid = 0;

$current_date = new DateTime(date('Y-m-d'));
foreach ($receivables as $inv) {
    $amount = (float)($inv['total_amount'] ?? 0);
    if ($amount <= 0.009) continue;

    $total_receivables += $amount;

    $invoice_date_value = trim((string)($inv['invoice_date'] ?? ''));
    $invoice_date = $invoice_date_value !== '' ? new DateTime(date('Y-m-d', strtotime($invoice_date_value))) : null;
    if ($invoice_date) {
        $days_outstanding = $invoice_date <= $current_date ? $current_date->diff($invoice_date)->days : 0;
        $total_days_outstanding += $days_outstanding;
        $count_unpaid++;
    }

    $due_date_value = trim((string)($inv['due_date'] ?? ''));
    $due_date = $due_date_value !== '' ? new DateTime(date('Y-m-d', strtotime($due_date_value))) : null;
    if ($due_date && $due_date < $current_date) {
        $overdue_receivables += $amount;
    }

    // Aging Breakdown must include ALL unpaid receivables, including Beginning Balance.
    // It is grouped by days outstanding from invoice_date, not only by due_date/overdue date.
    $aging_days = isset($days_outstanding) ? (int)$days_outstanding : 0;
    if ($aging_days <= 7) $aging_1_7 += $amount;
    elseif ($aging_days <= 14) $aging_8_14 += $amount;
    elseif ($aging_days <= 21) $aging_15_21 += $amount;
    elseif ($aging_days <= 28) $aging_22_28 += $amount;
    else $aging_beyond_28 += $amount;
}
$avg_collection_days = $count_unpaid > 0 ? round($total_days_outstanding / $count_unpaid) : 0;

$user_initials = '';
if (!empty($user_name)) {
    $name_parts = explode(' ', $user_name);
    foreach ($name_parts as $part) if (!empty($part)) $user_initials .= strtoupper(substr($part, 0, 1));
}
if (empty($user_initials)) $user_initials = 'BA';

$branch_name = 'All Branches';
if (!$view_all_branches && $branch_id > 0) {
    $branch_query = "SELECT branch_name FROM branches WHERE branch_id = ?";
    $branch_stmt = $conn->prepare($branch_query);
    if ($branch_stmt) {
        $branch_stmt->bind_param("i", $branch_id);
        $branch_stmt->execute();
        $branch_result = $branch_stmt->get_result();
        if ($branch_row = $branch_result->fetch_assoc()) $branch_name = $branch_row['branch_name'];
        $branch_stmt->close();
    }
}

// AMGC_COLLECTIONS_JOURNAL_EDIT_PATCH_V9
// AMGC_COLLECTIONS_SELECTED_PAYMENT_PATCH_V10
$amgc_journal_collection_edit_data = null;
$amgc_is_journal_collection_edit = isset($_GET['from_journal_entries']) && (
    (isset($_GET['journal_edit']) && (string)$_GET['journal_edit'] === '1') ||
    (isset($_GET['journal_edit_granted']) && (string)$_GET['journal_edit_granted'] === '1') ||
    (isset($_GET['edit_payment']) && (string)$_GET['edit_payment'] === '1')
);
if ($amgc_is_journal_collection_edit && collectionTableExists($conn, 'payments')) {
    $source_table_for_journal = strtolower(trim((string)($_GET['source_table'] ?? '')));
    $payment_id_param = (int)($_GET['payment_id'] ?? 0);
    $record_id_param = (int)($_GET['record_id'] ?? 0);
    $source_id_param = (int)($_GET['source_id'] ?? 0);
    $journal_payment_id = 0;
    $journal_transaction_id_param = (int)($_GET['journal_transaction_id'] ?? $_GET['transaction_id'] ?? 0);
    $selected_transaction_type_param = strtolower(trim((string)($_GET['selected_transaction_type'] ?? $_GET['transaction_type'] ?? '')));

    if ($payment_id_param > 0 || $source_table_for_journal === 'payments') {
        $journal_payment_id = $payment_id_param > 0 ? $payment_id_param : $source_id_param;
    }

    if ($journal_payment_id <= 0 && ($record_id_param > 0 || $source_table_for_journal === 'collection_records')) {
        $record_lookup_id = $record_id_param > 0 ? $record_id_param : $source_id_param;
        if ($record_lookup_id > 0) {
            if (collectionColumnExists($conn, 'payments', 'source_table') && collectionColumnExists($conn, 'payments', 'source_id')) {
                $lookup_stmt = $conn->prepare("SELECT payment_id FROM payments WHERE created_by IN (SELECT user_id FROM users WHERE role = 'motorpool') AND source_table = 'collection_records' AND source_id = ? ORDER BY payment_id DESC LIMIT 1");
                if ($lookup_stmt) {
                    $lookup_stmt->bind_param('i', $record_lookup_id);
                    $lookup_stmt->execute();
                    $lookup_row = $lookup_stmt->get_result()->fetch_assoc();
                    $lookup_stmt->close();
                    $journal_payment_id = (int)($lookup_row['payment_id'] ?? 0);
                }
            }

            // AMGC_COLLECTIONS_SELECTED_PAYMENT_PATCH_V10
            // Older approved collections did not always save payments.source_table/source_id.
            // Match only the selected collection record to its actual payment row.
            if ($journal_payment_id <= 0 && collectionTableExists($conn, 'collection_records')) {
                $lookup_stmt = $conn->prepare("SELECT p.payment_id
                    FROM collection_records cr
                    INNER JOIN payments p ON p.invoice_id = cr.invoice_id
                        AND p.customer_id = cr.customer_id
                        AND ABS(COALESCE(p.amount,0) - COALESCE(cr.amount,0)) < 0.01
                        AND (
                            COALESCE(p.reference_number,'') = COALESCE(cr.reference_number,'')
                            OR COALESCE(cr.reference_number,'') = ''
                            OR COALESCE(p.reference_number,'') = ''
                        )
                    WHERE cr.record_id = ?
                    ORDER BY p.payment_id DESC
                    LIMIT 1");
                if ($lookup_stmt) {
                    $lookup_stmt->bind_param('i', $record_lookup_id);
                    $lookup_stmt->execute();
                    $lookup_row = $lookup_stmt->get_result()->fetch_assoc();
                    $lookup_stmt->close();
                    $journal_payment_id = (int)($lookup_row['payment_id'] ?? 0);
                }
            }
        }
    }

    if ($journal_payment_id <= 0 && $source_id_param > 0) {
        $journal_payment_id = $source_id_param;
    }

    if ($journal_payment_id > 0) {
        $journal_sql = "SELECT p.*, i.invoice_number, i.total_amount, i.amount_paid, i.balance, i.status AS invoice_status,
                               COALESCE(pay.other_paid, 0) AS other_paid
                        FROM payments p
                        LEFT JOIN invoices i ON i.invoice_id = p.invoice_id
                        LEFT JOIN (
                            SELECT invoice_id, SUM(amount) AS other_paid
                            FROM payments
                            WHERE payment_id <> ? AND (status IS NULL OR status = 'completed')
                            GROUP BY invoice_id
                        ) pay ON pay.invoice_id = p.invoice_id
                        WHERE p.payment_id = ? LIMIT 1";
        $journal_stmt = $conn->prepare($journal_sql);
        if ($journal_stmt) {
            $journal_stmt->bind_param('ii', $journal_payment_id, $journal_payment_id);
            $journal_stmt->execute();
            $journal_row = $journal_stmt->get_result()->fetch_assoc();
            $journal_stmt->close();
            if ($journal_row) {
                $journal_total = (float)($journal_row['total_amount'] ?? 0);
                $journal_other_paid = (float)($journal_row['other_paid'] ?? 0);
                $journal_current_amount = (float)($journal_row['amount'] ?? 0);
                $amgc_journal_collection_edit_data = [
                    'payment_id' => (int)$journal_row['payment_id'],
                    'invoice_id' => (int)$journal_row['invoice_id'],
                    'customer_id' => (int)$journal_row['customer_id'],
                    'invoice_number' => (string)($journal_row['invoice_number'] ?? ''),
                    'amount_due' => max($journal_total - $journal_other_paid, $journal_current_amount),
                    'amount' => $journal_current_amount,
                    'payment_method' => (string)($journal_row['payment_method'] ?? 'cash'),
                    'reference_number' => (string)($journal_row['reference_number'] ?? ''),
                    'check_date' => (string)($journal_row['check_date'] ?? ''),
                    'bank_name' => (string)($journal_row['bank_name'] ?? ''),
                    'bank_branch' => (string)($journal_row['bank_branch'] ?? ''),
                    'check_number' => (string)($journal_row['check_number'] ?? ''),
                    'cash_tendered' => (string)($journal_row['cash_tendered'] ?? ''),
                    'cash_change' => (string)($journal_row['cash_change'] ?? '')
                ];
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Collections - Motorpool</title>
    <link rel="icon" type="image/png" href="../Pictures/favicon-96x96.png" sizes="96x96" />
    <link rel="icon" type="image/svg+xml" href="../Pictures/favicon.svg" />
    <link rel="shortcut icon" href="../Pictures/favicon.ico" />
    <link rel="apple-touch-icon" sizes="180x180" href="../Pictures/apple-touch-icon.png" />
    <link rel="manifest" href="../Pictures/site.webmanifest" />
    <link rel="stylesheet" href="../css/current_inventory.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
        <!-- Session Checker -->
    <script src="../js/session-checker.js"></script>
    <!-- Semantic UI CSS -->
<!-- Semantic UI CSS removed to avoid sidebar/font conflicts -->
<!-- Semantic UI JS uses the jQuery already loaded above. Do not load jQuery twice. -->
<script src="https://cdn.jsdelivr.net/npm/semantic-ui@2.5.0/dist/semantic.min.js"></script>
      <style>
        /* All original CSS remains */
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

        body {
            background-color: #f4f6f9;
            font-family: 'Alice', 'Segoe UI', sans-serif;
        }

        .main-content {
            margin-left: 260px;
            padding: 20px;
            transition: all 0.3s;
        }

        .navbar-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
            background: white;
            padding: 12px 20px;
            border-radius: 16px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }

        .mobile-menu-btn {
            display: none;
            background: none;
            border: none;
            font-size: 1.5rem;
            color: var(--green);
        }

        @media (max-width: 992px) {
            .sidebar {
                transform: translateX(-100%);
            }
            .sidebar.active {
                transform: translateX(0);
            }
            .main-content {
                margin-left: 0;
            }
            .mobile-menu-btn {
                display: block;
            }
        }

        .form-card {
            background: white;
            border-radius: 20px;
            padding: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            margin-bottom: 24px;
        }

        .filter-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
        }

        .filter-header h5 {
            margin: 0;
            font-weight: 600;
        }

        .data-table {
            background: white;
            border-radius: 20px;
            padding: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }

        .credit-summary {
            background: linear-gradient(135deg, #e8f5e9, #c8e6c9);
            border-radius: 16px;
            padding: 16px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
        }

        .credit-item {
            background: white;
            padding: 8px 16px;
            border-radius: 30px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }

        .btn-pay {
            background: var(--green);
            color: white;
            border: none;
            border-radius: 50%;
            width: 32px;
            height: 32px;
            padding: 0;
            font-size: 0.85rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
        }

        .btn-pay:hover {
            background: var(--green-haze);
            color: white;
        }

        .btn-action-icon {
            width: 32px;
            height: 32px;
            padding: 0 !important;
            border-radius: 50% !important;
            display: inline-flex !important;
            align-items: center;
            justify-content: center;
            font-size: 0.85rem !important;
            line-height: 1 !important;
        }

        .invoice-action-buttons {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
            align-items: center;
            justify-content: center;
        }

        .table th,
        .table td,
        table th,
        table td {
            text-align: center !important;
            vertical-align: middle !important;
        }

        .table td.text-end,
        table td.text-end,
        .table th.text-end,
        table th.text-end {
            text-align: center !important;
        }

        .btn-approve {
            background: #28a745;
            color: white;
            border: none;
            border-radius: 30px;
            padding: 6px 16px;
            font-size: 0.75rem;
            transition: all 0.2s;
        }
        
        .btn-approve:hover {
            background: #218838;
        }
        
        .btn-reject {
            background: #dc3545;
            color: white;
            border: none;
            border-radius: 30px;
            padding: 6px 16px;
            font-size: 0.75rem;
            transition: all 0.2s;
            margin-left: 5px;
        }
        
        .btn-reject:hover {
            background: #c82333;
        }
        
        .badge-pending-remit {
            background: #ffc107;
            color: #212121;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.65rem;
            font-weight: 600;
        }

        .badge-overdue {
            background: #dc3545;
            color: white;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.7rem;
        }

        .badge-pending {
            background: #ffc107;
            color: #212121;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.7rem;
        }

        .badge-paid {
            background: #28a745;
            color: white;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.7rem;
        }

        .no-data {
            text-align: center;
            padding: 40px;
            color: #6c757d;
        }


        /* Skeleton Loading */
        .skeleton-loading {
            position: relative;
            overflow: hidden;
            background: #edf2ef;
            border-radius: 10px;
            color: transparent !important;
            pointer-events: none;
        }

        .skeleton-loading::after {
            content: '';
            position: absolute;
            inset: 0;
            transform: translateX(-100%);
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.75), transparent);
            animation: skeletonShimmer 1.15s infinite;
        }

        @keyframes skeletonShimmer {
            100% { transform: translateX(100%); }
        }

        .skeleton-line {
            display: block;
            width: 100%;
            height: 14px;
            border-radius: 999px;
            background: #edf2ef;
            position: relative;
            overflow: hidden;
        }

        .skeleton-line::after {
            content: '';
            position: absolute;
            inset: 0;
            transform: translateX(-100%);
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.75), transparent);
            animation: skeletonShimmer 1.15s infinite;
        }

        .skeleton-pill {
            display: inline-block;
            width: 76px;
            height: 24px;
            border-radius: 999px;
            background: #edf2ef;
            position: relative;
            overflow: hidden;
        }

        .skeleton-pill::after {
            content: '';
            position: absolute;
            inset: 0;
            transform: translateX(-100%);
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.75), transparent);
            animation: skeletonShimmer 1.15s infinite;
        }

        .skeleton-circle {
            display: inline-block;
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: #edf2ef;
            position: relative;
            overflow: hidden;
        }

        .skeleton-circle::after {
            content: '';
            position: absolute;
            inset: 0;
            transform: translateX(-100%);
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.75), transparent);
            animation: skeletonShimmer 1.15s infinite;
        }

        .skeleton-row td {
            padding-top: 14px !important;
            padding-bottom: 14px !important;
        }

        .customer-group-tabs.skeleton-tabs {
            gap: 10px;
        }

        .customer-group-tabs.skeleton-tabs .skeleton-tab {
            width: 170px;
            height: 46px;
            border-radius: 14px;
            background: #edf2ef;
            position: relative;
            overflow: hidden;
        }

        .customer-group-tabs.skeleton-tabs .skeleton-tab::after {
            content: '';
            position: absolute;
            inset: 0;
            transform: translateX(-100%);
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.75), transparent);
            animation: skeletonShimmer 1.15s infinite;
        }

        .payment-method-option {
            cursor: pointer;
            padding: 12px;
            border: 2px solid #e9ecef;
            border-radius: 12px;
            text-align: center;
            transition: all 0.2s;
        }
        .payment-method-option:hover {
            border-color: var(--green);
            background: #f8f9fa;
        }
        .payment-method-option.active {
            border-color: var(--green);
            background: #e8f5e9;
        }

        .payment-method-option.active i,
        .payment-method-option.active span {
            color: var(--green) !important;
        }

        .payment-method-option i,
        .payment-method-option span {
            transition: color 0.2s ease;
        }
        .payment-method-option i {
            font-size: 1.8rem;
            display: block;
            margin-bottom: 8px;
        }
        .payment-detail-group {
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #e9ecef;
        }

        .invoice-row {
            cursor: pointer;
            transition: all 0.2s;
        }

        .invoice-row:hover {
            background-color: #f5f5f5;
        }

        .payment-details-modal .modal-body {
            padding: 20px;
        }

        .payment-detail-card {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 15px;
            margin-bottom: 15px;
        }

        .payment-detail-label {
            font-weight: 600;
            color: #495057;
            font-size: 0.85rem;
            margin-bottom: 5px;
        }

        .payment-detail-value {
            font-size: 1rem;
            color: #212121;
        }

        @media (max-width: 768px) {
            .credit-summary {
                flex-direction: column;
                gap: 12px;
                align-items: stretch;
            }
        }

        .mobile-nav {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: white;
            box-shadow: 0 -2px 10px rgba(0,0,0,0.1);
            padding: 8px 12px;
            z-index: 1000;
            display: none;
        }

        @media (max-width: 992px) {
            .mobile-nav {
                display: block;
            }
            body {
                padding-bottom: 70px;
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
            font-size: 0.75rem;
            position: relative;
        }

        .mobile-nav .nav-link i {
            font-size: 1.3rem;
            margin-bottom: 4px;
        }

        .mobile-nav .nav-link.active {
            color: #2E7D32;
        }

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

        .dropdown-more {
            position: relative;
        }

        .more-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 4px;
            cursor: pointer;
        }

        .more-dropdown {
            position: absolute;
            bottom: 100%;
            right: 0;
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.15);
            min-width: 180px;
            display: none;
            margin-bottom: 8px;
            border: 1px solid rgba(0,0,0,0.08);
            z-index: 1000;
        }

        .more-dropdown.show {
            display: block;
        }

        .more-dropdown .dropdown-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 16px;
            color: #333;
            text-decoration: none;
            border-bottom: 1px solid #f0f0f0;
        }

        .more-dropdown .dropdown-item:last-child {
            border-bottom: none;
        }

        .more-dropdown .dropdown-item:hover {
            background: #f5f5f5;
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

        .customer-selector {
            margin-bottom: 20px;
        }
        
        .filter-row {
            display: flex;
            gap: 15px;
            align-items: flex-end;
            flex-wrap: wrap;
        }
        
        .filter-group {
            flex: 1;
            min-width: 150px;
        }
        
        .filter-group label {
            font-size: 0.8rem;
            font-weight: 600;
            margin-bottom: 5px;
            display: block;
        }
        
        .filter-group select, .filter-group input {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #ced4da;
            border-radius: 8px;
        }
        
        /* Select2 customization */
        .select2-container--default .select2-selection--single {
            border: 1px solid #ced4da;
            border-radius: 8px;
            height: 42px;
            padding: 5px;
        }
        
        .select2-container--default .select2-selection--single .select2-selection__rendered {
            line-height: 30px;
        }
        
        .select2-container--default .select2-selection--single .select2-selection__arrow {
            height: 40px;
        }
        
        .select2-dropdown {
            border-radius: 8px;
            border-color: #ced4da;
        }
        
        /* Loading overlay */
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5);
            z-index: 9999;
            display: none;
            justify-content: center;
            align-items: center;
        }
        
        .loading-spinner {
            width: 50px;
            height: 50px;
            border: 5px solid #f3f3f3;
            border-top: 5px solid #2E7D32;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        /* Filter Section Styles */
        .supplier-filter-card {
            background: white;
            border-radius: 12px;
            padding: 16px 20px;
            margin-bottom: 20px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.03);
        }

        .supplier-filter-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            cursor: pointer;
        }

        .supplier-filter-header h5 {
            font-size: 0.9rem;
            font-weight: 600;
            margin: 0;
            color: #333;
        }

        .supplier-filter-header h5 i {
            margin-right: 8px;
            color: #047857;
        }

        .supplier-filter-toggle-btn {
            background: none;
            border: none;
            color: #666;
            cursor: pointer;
            padding: 4px 8px;
            transition: transform 0.3s ease;
        }

        .supplier-filter-toggle-btn i {
            font-size: 1rem;
        }

        .supplier-filter-content {
            transition: all 0.3s ease;
            overflow: hidden;
        }

        .supplier-filter-content.collapsed {
            display: none;
        }

        .supplier-filter-one-line {
            display: flex;
            gap: 16px;
            align-items: flex-end;
            margin-top: 16px;
            flex-wrap: wrap;
        }

        .filter-item {
            flex: 1;
            min-width: 160px;
        }

        .filter-item.search-item {
            min-width: 200px;
        }

        .supplier-filter-label {
            font-size: 0.65rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #666;
            margin-bottom: 4px;
            display: block;
        }

        .supplier-filter-select,
        .supplier-filter-input {
            width: 100%;
            padding: 8px 12px;
            font-size: 0.85rem;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            background: white;
            transition: all 0.2s;
        }

        .supplier-filter-select:focus,
        .supplier-filter-input:focus {
            outline: none;
            border-color: #047857;
            box-shadow: 0 0 0 2px rgba(4,120,87,0.1);
        }

        .supplier-search-wrapper {
            position: relative;
        }

        .supplier-search-wrapper .search-icon {
            position: absolute;
            left: 10px;
            top: 50%;
            transform: translateY(-50%);
            color: #999;
            font-size: 0.9rem;
        }

        .supplier-search-wrapper .supplier-filter-input {
            padding-left: 32px;
        }

        /* Select2 customization */
        .select2-container--default .select2-selection--single {
            height: 38px !important;
            padding: 0 8px !important;
            border: 1px solid #e0e0e0 !important;
            border-radius: 8px !important;
        }

        .select2-container--default .select2-selection--single .select2-selection__rendered {
            line-height: 36px !important;
            font-size: 0.85rem !important;
        }

        .select2-container--default .select2-selection--single .select2-selection__arrow {
            height: 36px !important;
        }

        .select2-dropdown {
            border-radius: 8px !important;
            border-color: #e0e0e0 !important;
        }

        @media (max-width: 768px) {
            .supplier-filter-one-line {
                flex-direction: column;
                gap: 12px;
            }
            
            .filter-item {
                width: 100%;
            }
        }
        
        /* Table Styles */
        .data-table {
            background: transparent !important;
            border-radius: 0 !important;
            padding: 0 !important;
            box-shadow: none !important;
        }

        .table {
            background: white !important;
            border-radius: 0 !important;
            overflow: hidden !important;
            box-shadow: 0 1px 2px rgba(0,0,0,0.03) !important;
        }

        .table thead th {
            background: #047857 !important;
            border-bottom: 1px solid #e9ecef !important;
            font-weight: 600 !important;
            font-size: 0.75rem !important;
            text-transform: uppercase !important;
            letter-spacing: 0.5px !important;
            color: #ffffff !important;
            padding: 10px 12px !important;
        }

        .table td {
            padding: 10px 12px !important;
            vertical-align: middle !important;
            border-bottom: 1px solid #f0f2f4 !important;
            font-size: 0.8rem !important;
        }

        .table tr:hover {
            background-color: #f8f9fa !important;
        }

        /* Checkbox column */
        .checkbox-column {
            width: 40px !important;
            text-align: center !important;
        }
        
        .select-all-checkbox {
            cursor: pointer;
        }
        
        .row-checkbox {
            cursor: pointer;
            width: 18px;
            height: 18px;
        }
        
        /* Batch assign bar */
        .batch-assign-bar {
            background: linear-gradient(135deg, #047857, #059669);
            color: white;
            padding: 10px 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .batch-assign-bar .selected-count {
            font-weight: 600;
        }
        
        .batch-assign-bar .btn-assign-batch {
            background: white;
            color: #047857;
            border: none;
            border-radius: 30px;
            padding: 8px 20px;
            font-weight: 600;
            transition: all 0.2s;
        }
        
        .batch-assign-bar .btn-assign-batch:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        
        .batch-assign-bar .btn-clear-selection {
            background: rgba(255,255,255,0.2);
            color: white;
            border: 1px solid rgba(255,255,255,0.3);
            border-radius: 30px;
            padding: 6px 16px;
            transition: all 0.2s;
        }
        
        .batch-assign-bar .btn-clear-selection:hover {
            background: rgba(255,255,255,0.3);
        }
        
        /* Pending Remittances Section */
        .pending-remittances-section {
            background: white;
            border-radius: 16px;
            margin-bottom: 30px;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }
        
        .section-header {
            background: linear-gradient(135deg, #047857, #047857);
            color: white;
            padding: 12px 20px;
            font-weight: 600;
        }
        
        .section-header i {
            margin-right: 8px;
        }
        
        .remittance-row {
            border-bottom: 1px solid #f0f2f4;
            transition: background 0.2s;
        }
        
        .remittance-row:hover {
            background: #f8f9fa;
        }
        
        .remittance-actions {
            white-space: nowrap;
        }
        
        /* Assigned collector badge */
        .assigned-collector-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            background: #e8f5e9;
            color: #047857;
            border-radius: 20px;
            padding: 4px 10px;
            font-size: 0.72rem;
            font-weight: 600;
            white-space: nowrap;
        }
        
        .assigned-date-small {
            display: block;
            font-size: 0.65rem;
            color: #6c757d;
            margin-top: 2px;
        }
        
        /* Remittance card for mobile */
        .remittance-card {
            background: white;
            border-radius: 12px;
            padding: 15px;
            margin-bottom: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
            border-left: 4px solid #ff9800;
        }
        
        .remittance-card .remittance-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
            flex-wrap: wrap;
        }
        
        /* Quick Stats Cards */
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

        .stat-card.overdue {
            background: linear-gradient(135deg, #047857, #059669) !important;
            border: none !important;
        }

        .stat-card.aging {
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
        
        /* Aging Modal Styles */
        #agingModal .modal-dialog {
            margin: 1rem auto !important;
            max-width: 550px !important;
        }

        @media (max-width: 768px) {
            #agingModal .modal-dialog {
                margin: 0.75rem auto !important;
                max-width: calc(100% - 1.5rem) !important;
                width: calc(100% - 1.5rem) !important;
            }
        }

        #agingModal .modal-content {
            border: none !important;
            border-radius: 24px !important;
            overflow: hidden !important;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.2) !important;
            max-height: 90vh !important;
            display: flex !important;
            flex-direction: column !important;
        }

        #agingModal .modal-header {
            background: linear-gradient(135deg, #047857 0%, #44D34E 100%) !important;
            color: white !important;
            border-bottom: none !important;
            padding: 1rem 1.25rem !important;
            flex-shrink: 0 !important;
        }

        #agingModal .modal-header .modal-title {
            font-weight: 600 !important;
            font-size: 1.1rem !important;
            display: flex !important;
            align-items: center !important;
            gap: 8px !important;
            color: white !important;
        }

        #agingModal .modal-header .btn-close {
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
            background-image: none !important;
        }

        #agingModal .modal-body {
            padding: 1.25rem !important;
            overflow-y: auto !important;
            flex: 1 !important;
            background: #f8fafc !important;
        }

        .aging-item {
            background: white !important;
            border-radius: 12px !important;
            padding: 0.875rem 1rem !important;
            margin-bottom: 0.75rem !important;
            transition: all 0.2s ease !important;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.03) !important;
            cursor: pointer;
        }

        .aging-item:hover {
            background: #f1f5f9 !important;
            transform: translateX(2px) !important;
        }

        .aging-item.active-aging-item {
            background: #ecfdf5 !important;
            border: 1px solid #047857 !important;
            box-shadow: 0 10px 24px rgba(4, 120, 87, 0.16) !important;
            transform: translateX(3px) !important;
        }

        .aging-item.active-aging-item .aging-amount {
            color: #047857 !important;
            font-weight: 800 !important;
        }
        
        .range-badge {
            display: inline-block !important;
            padding: 0.25rem 0.6rem !important;
            font-size: 0.7rem !important;
            font-weight: 600 !important;
            border-radius: 20px !important;
            color: white !important;
        }
        
        .bg-warning {
            background-color: #2dc937 !important;
        }

        .bg-orange {
            background-color: #99c140 !important;
        }

        .bg-info {
            background-color: #e7b416 !important;
        }

        .bg-danger {
            background-color: #db7b2b !important;
        }

        .bg-dark {
            background-color: #cc3232 !important;
        }
        
        .invoice-detail-item {
            background: white;
            border-radius: 12px;
            padding: 12px 15px;
            margin-bottom: 10px;
            border-left: 4px solid;
            transition: all 0.2s ease;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }
        
        @media (max-width: 576px) {
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
            }
            
            .stat-card .stat-value {
                display: block !important;
                text-align: center !important;
                font-size: 1.2rem !important;
                font-weight: bold !important;
                line-height: 1.2 !important;
                margin: 0.2rem 0 !important;
            }
            
            .stat-card .stat-label {
                display: block !important;
                text-align: center !important;
                font-size: 0.7rem !important;
                font-weight: 500 !important;
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


        .collection-report-print-btn {
            background: transparent;
            color: #fff;
            border-style: solid;
            border-color: white;
            border-radius: 30px;
            padding: 9px 18px;
            font-size: 0.9rem;
            font-weight: 600;
            box-shadow: 0 2px 6px rgba(46, 125, 50, 0.25);
            transition: all 0.2s ease;
            white-space: nowrap;
        }

        .collection-report-print-btn:hover {
            color: #fff;
            transform: translateY(-1px);
        }

        .collection-report-filter-card {
            background: #f8fafc;
            border: 1px solid #e9ecef;
            border-radius: 14px;
            padding: 14px;
            margin-bottom: 14px;
        }

        .collection-report-summary-card {
            background: #fff;
            border: 1px solid #d9d9d9;
            border-radius: 0;
            padding: 8px 10px;
            height: 100%;
        }

        .collection-report-summary-card .label {
            font-size: 0.72rem;
            text-transform: uppercase;
            color: #333;
            font-weight: 700;
            letter-spacing: 0.2px;
        }

        .collection-report-summary-card .value {
            font-size: 1rem;
            color: #111;
            font-weight: 700;
            margin-top: 2px;
        }

        .collection-report-preview-box {
            background: #fff;
            border: 1px solid #d9d9d9;
            border-radius: 0;
            padding: 18px;
            max-height: 65vh;
            overflow-y: auto;
            color: #111;
        }

        .plain-report-header {
            text-align: center;
            margin-bottom: 14px;
            color: #111;
        }

        .plain-report-header h4 {
            margin: 0 0 4px 0;
            color: #111;
            font-size: 18px;
            font-weight: 700;
            letter-spacing: 0.2px;
        }

        .plain-report-header .report-title {
            margin: 0 0 8px 0;
            font-size: 14px;
            font-weight: 700;
            text-transform: uppercase;
        }

        .plain-report-meta {
            width: 100%;
            margin-bottom: 12px;
            border-collapse: collapse;
            font-size: 12px;
        }

        .plain-report-meta td {
            padding: 2px 4px;
            border: none;
            color: #111;
        }

        .plain-report-summary {
            margin: 8px 0 12px 0;
            padding: 0;
            font-size: 12px;
            color: #111;
        }

        .plain-summary-table,
        .plain-report-table {
            width: 100%;
            border-collapse: collapse;
            color: #111;
        }

        .plain-summary-table {
            margin-bottom: 14px;
            font-size: 12px;
        }

        .plain-summary-table th,
        .plain-summary-table td,
        .plain-report-table th,
        .plain-report-table td {
            border: 1px solid #333;
            padding: 6px 7px;
            background: #fff;
            color: #111;
        }

        .plain-summary-table th,
        .plain-report-table th {
            font-weight: 700;
            text-align: left;
        }

        .plain-report-table {
            font-size: 11px;
        }

        .plain-report-table tfoot th {
            font-weight: 700;
        }

        .print-only-area {
            display: none;
        }

        @media print {
            body * {
                visibility: hidden !important;
            }
            #collectionReportPrintable,
            #collectionReportPrintable * {
                visibility: visible !important;
            }
            #collectionReportPrintable {
                display: block !important;
                position: absolute;
                left: 0;
                top: 0;
                width: 100%;
                padding: 12px;
                background: #fff !important;
                color: #111 !important;
                font-family: Arial, sans-serif;
            }
            .no-print,
            .modal-backdrop,
            .modal-footer,
            .btn-close {
                display: none !important;
            }
            .collection-report-preview-box {
                max-height: none !important;
                overflow: visible !important;
                border: none !important;
                padding: 0 !important;
                background: #fff !important;
            }
            .plain-report-header h4 {
                font-size: 16px !important;
            }
            .plain-report-meta,
            .plain-summary-table {
                font-size: 11px !important;
            }
            .plain-report-table {
                font-size: 10px !important;
            }
            .plain-summary-table th,
            .plain-summary-table td,
            .plain-report-table th,
            .plain-report-table td {
                border: 1px solid #333 !important;
                background: #fff !important;
                color: #111 !important;
                padding: 5px !important;
            }
        }
        /* Approve All Button */
.btn-approve-all {
    background: white;
    color: #047857;
    border: none;
    border-radius: 30px;
    padding: 6px 18px;
    font-weight: 600;
    font-size: 0.8rem;
    transition: all 0.2s;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.btn-approve-all:hover {
    background: #f0fdf4;
    transform: translateY(-1px);
    box-shadow: 0 2px 6px rgba(0,0,0,0.1);
}

@media (max-width: 576px) {
    .btn-approve-all {
        padding: 4px 12px;
        font-size: 0.7rem;
    }
}
    
        /* Customer Group Tabs */
        .customer-group-tabs-wrap {
            padding: 1rem 1rem 0;
            background: #fff;
            border-bottom: 1px solid #e5e7eb;
        }

        .customer-group-tabs {
            display: flex;
            gap: 0.65rem;
            overflow-x: auto;
            padding-bottom: 0.8rem;
            scrollbar-width: thin;
        }

        .customer-group-tab {
            border: 1px solid #d1fae5;
            background: #f0fdf4;
            color: #047857;
            border-radius: 999px;
            padding: 0.55rem 0.9rem;
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
            font-weight: 700;
            font-size: 0.85rem;
            white-space: nowrap;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .customer-group-tab:hover {
            background: #dcfce7;
            border-color: #44D34E;
            transform: translateY(-1px);
        }

        .customer-group-tab.active {
            background: linear-gradient(135deg, #047857 0%, #44D34E 100%);
            border-color: #047857;
            color: #fff;
            box-shadow: 0 8px 18px rgba(4, 120, 87, 0.18);
        }

        .customer-group-tab .group-count {
            background: rgba(255, 255, 255, 0.7);
            color: #047857;
            border-radius: 999px;
            padding: 0.1rem 0.45rem;
            font-size: 0.75rem;
            line-height: 1.2;
        }

        .customer-group-tab.active .group-count {
            background: rgba(255, 255, 255, 0.22);
            color: #fff;
        }

        .customer-group-tab .group-total {
            font-size: 0.75rem;
            opacity: 0.85;
            font-weight: 600;
        }

        .customer-group-empty-note {
            color: #6b7280;
            font-size: 0.85rem;
            padding-bottom: 0.8rem;
        }

        /* Beginning Balance customer custom searchable dropdown */
        .bb-customer-picker {
            position: relative;
            width: 100%;
        }
        .bb-customer-picker .bb-customer-input {
            width: 100%;
            height: 38px;
            border: 1px solid #ced4da;
            border-radius: .375rem;
            padding: .375rem 2.25rem .375rem .75rem;
            background: #fff;
            color: #212529;
        }
        .bb-customer-picker .bb-customer-input:focus {
            border-color: #2E7D32;
            box-shadow: 0 0 0 .2rem rgba(46,125,50,.15);
            outline: 0;
        }
        .bb-customer-picker .bb-customer-icon {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #666;
            pointer-events: none;
        }
        .bb-customer-menu {
            display: none;
            position: absolute;
            z-index: 2000;
            left: 0;
            right: 0;
            top: calc(100% + 4px);
            max-height: 260px;
            overflow-y: auto;
            background: #fff;
            border: 1px solid #ced4da;
            border-radius: .5rem;
            box-shadow: 0 8px 24px rgba(0,0,0,.12);
        }
        .bb-customer-menu.show {
            display: block;
        }
        .bb-customer-item {
            padding: 10px 12px;
            cursor: pointer;
            border-bottom: 1px solid #f1f1f1;
            color: #212529;
            background: #fff;
        }
        .bb-customer-main {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
        }
        .bb-customer-group-badge {
            display: inline-flex;
            align-items: center;
            max-width: 160px;
            padding: 2px 8px;
            border-radius: 999px;
            background: #e8f5e9;
            color: #2E7D32;
            border: 1px solid rgba(46,125,50,.18);
            font-size: 0.75rem;
            font-weight: 700;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .bb-customer-subtext {
            margin-top: 2px;
            color: #6c757d;
            font-size: 0.78rem;
        }
        .bb-customer-item:last-child {
            border-bottom: 0;
        }
        .bb-customer-item:hover,
        .bb-customer-item.active {
            background: #eaf5ea;
            color: #1B5E20;
        }
        .bb-customer-empty {
            padding: 10px 12px;
            color: #6c757d;
            display: none;
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


        /* Customer Payment modal */
        .customer-payment-modal {
            font-family: 'Alice', 'Segoe UI', sans-serif;
        }
        .customer-payment-modal *:not(.bi):not(.bi::before):not(.bi::after) {
            font-family: inherit !important;
        }
        .customer-payment-modal .bi,
        .customer-payment-modal .bi::before,
        .customer-payment-modal .bi::after {
            font-family: "bootstrap-icons" !important;
        }
        .customer-payment-modal .modal-dialog { max-width: 96vw; }
        .customer-payment-modal .modal-content {
            border: 0;
            border-radius: 14px;
            overflow: hidden;
            box-shadow: 0 18px 45px rgba(5, 42, 71, 0.18);
        }
        .customer-payment-modal .modal-header {
            min-height: auto;
            padding: 6px 12px;
            background: #f8fafc;
            border-bottom: 1px solid #e5e7eb;
        }
        .customer-payment-modal .modal-title {
            margin: 0;
            color: #000000;
            font-size: 15px;
            line-height: 1.15;
            font-weight: 600;
            letter-spacing: -0.01em;
            display: flex;
            align-items: center;
            gap: 7px;
        }
        .customer-payment-modal .cp-close-x {
            width: 30px;
            height: 30px;
            padding: 0;
            margin: 0;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            background: #ffffff;
            color: #000000;
            font-size: 22px;
            line-height: 1;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            opacity: 1;
            cursor: pointer;
        }
        .customer-payment-modal .cp-close-x:hover {
            background: #eafaf0;
            border-color: #44D34E;
            color: #047857;
        }
        .customer-payment-box { 
            background: #f8fafc; 
            border: 1px solid #e5e7eb; 
            border-radius: 14px; 
            overflow: hidden; 
        }
        .cp-entry-grid { 
            display:grid; 
            grid-template-columns: 1fr; 
            gap:12px; 
            align-items:start; 
            max-width: 460px; 
        }
        .cp-form-area { 
            max-width: 460px; 
            margin-left: 0; 
        }
        .cp-form-row { 
            display:grid; 
            grid-template-columns: 150px 300px; 
            gap:10px; 
            align-items:center; 
            margin-bottom:12px; 
        }
        .cp-form-row.cp-amount-row { 
            align-items:start; 
        }
        .cp-form-row.cp-amount-row .cp-label { 
            padding-top:0; 
            min-height:34px; 
            display:flex; 
            align-items:center; 
            justify-content:flex-start; 
            white-space:nowrap; 
        }
        .cp-field-wrap { 
            min-width:0; 
        }
        .customer-payment-title { 
            font-size: 22px; 
            line-height: 1.1; 
            font-weight: 600; 
            color: #444; 
            letter-spacing: -0.2px; 
        }
        .cp-label { 
            font-size: 12px; 
            font-weight: 600; 
            color: #6b7280; 
            text-transform: uppercase; 
            margin-bottom: 0; 
            line-height:1.2; 
            white-space:nowrap; 
            text-align:left !important; 
        }
        .cp-input, .cp-select { 
            border: 1px solid #cbd5e1; 
            border-radius: 7px; 
            height: 36px; 
            font-size: 13px; 
            background:#fff; 
        }
        .cp-customer-picker { 
            position: relative; 
            width: 100%; 
        }
        .cp-customer-toggle {
            width: 100%;
            height: 36px;
            border: 1px solid #cbd5e1;
            border-radius: 7px;
            background: #ffffff;
            font-size: 13px;
            color: #111827;
            padding: 0 34px 0 12px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            text-align: left;
            cursor: text;
        }
        .cp-customer-toggle:focus-within {
            border-color: #44D34E;
            box-shadow: 0 0 0 .16rem rgba(68,211,78,.16);
            outline: none;
        }
        .cp-customer-search {
            width: 100%;
            height: 100%;
            border: 0;
            outline: 0;
            background: transparent;
            font-size: 13px;
            color: #111827;
            padding: 0;
            min-width: 0;
        }
        .cp-customer-search::placeholder { 
            color: #6b7280; 
            opacity: 1; 
        }
        .cp-customer-toggle .cp-customer-toggle-icon {
            position: absolute;
            right: 12px;
            color: #6b7280;
            pointer-events: none;
        }
        .cp-customer-menu {
            position: absolute;
            top: calc(100% + 4px);
            left: 0;
            right: 0;
            z-index: 1085;
            background: #ffffff;
            border: 1px solid #d1d5db;
            border-radius: 9px;
            box-shadow: 0 12px 28px rgba(5, 42, 71, 0.16);
            max-height: 245px;
            overflow-y: auto;
            padding: 5px;
            display: none;
        }
        .cp-customer-menu.show { display: block; }
        .cp-customer-option {
            width: 100%;
            border: 0;
            background: transparent;
            border-radius: 7px;
            padding: 8px 10px;
            font-size: 13px;
            color: #111827;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            cursor: pointer;
            text-align: left;
        }
        .cp-customer-option:hover,
        .cp-customer-option.active {
            background: #eafaf0;
            color: #047857;
        }
        .cp-customer-option-name {
            min-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            font-weight: 500;
        }
        .cp-customer-option-group {
            flex: 0 0 auto;
            max-width: 45%;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            color: #047857;
            font-size: 12px;
            font-weight: 600;
            text-align: right;
        }
        .cp-amount-input { 
            text-align: right; 
            font-size: 14px; 
            font-weight: 600; 
        }
        .cp-method-grid-three { 
            grid-template-columns: repeat(3, minmax(0, 1fr)) !important; 
        }
        .cp-method-grid { 
            display: grid; 
            grid-template-columns: repeat(5, minmax(82px, 1fr)); 
            border: 1px solid #d1d5db; 
            border-radius: 8px; 
            overflow: hidden; 
            background: #fff; 
        }
        .cp-method-btn { 
            min-height: 68px; 
            border: 0; 
            border-right: 1px solid #d1d5db; 
            background: linear-gradient(#ffffff, #f3f4f6); 
            color: #374151; 
            font-weight: 600; 
            font-size: 12px; 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            flex-direction: column; 
            gap: 6px; 
        }
        .cp-method-btn:last-child { 
            border-right: 0; 
        }
        .cp-method-btn i { 
            font-size: 18px; 
            color: #64748b; 
        }
        .cp-method-btn.active { 
            background: linear-gradient(#ecfdf5, #d1fae5); 
            color: #047857; 
            box-shadow: inset 0 0 0 2px #44D34E; 
        }
        .cp-method-btn.active i { 
            color: #047857; 
        }
        .cp-link { 
            color: #2563eb; 
            font-size: 14px; 
            text-decoration: none; 
            font-weight: 500; 
        }
        .cp-balance-box { 
            text-align: right; 
            font-family: inherit; 
            font-size: 27px; 
            color: #6b7280; 
            font-weight: bold; 
            display:flex; 
            flex-direction:column; 
            align-items:flex-end; 
            justify-content:flex-end; 
            gap:4px; 
            line-height:1.2; 
            letter-spacing:0; 
        }
        .cp-balance-box .amount { 
            min-width: 0; 
            display: block; 
            margin-left: 0; 
            color: #212529; 
            font-family: inherit; 
            font-size: 31px; 
            font-weight: bold; 
            line-height:1.2; 
        }
        .customer-payment-modal .cp-payment-top-layout { 
            flex-wrap: nowrap; 
            align-items:flex-start !important; 
        }
        .customer-payment-modal .cp-left-fields-col { 
            flex: 0 0 500px; 
            width: 500px; 
            max-width: 500px; 
        }
        .customer-payment-modal .cp-methods-balance-col { 
            flex: 1 1 auto; 
            min-width: 0; 
            position: relative; 
            padding-right: 260px; 
            min-height: 88px; 
        }
        .customer-payment-modal .cp-methods-balance-col .cp-balance-box { 
            position:absolute; 
            top:0; 
            right:0; 
            width:auto; 
            margin-bottom:0 !important; 
        }
        .customer-payment-modal .cp-methods-balance-col .cp-method-grid { 
            width: 570px; 
            max-width: 570px; 
            margin-left:0; 
        }
        .customer-payment-modal .cp-methods-balance-col .cp-method-btn { 
            min-height:68px; 
        }
        @media (max-width: 1199.98px) {
            .customer-payment-modal .cp-payment-top-layout { 
                flex-wrap: wrap; 
            }
            .customer-payment-modal .cp-left-fields-col,
            .customer-payment-modal .cp-methods-balance-col { 
                flex: 0 0 100%; 
                width:100%; 
                max-width:100%; 
            }
            .customer-payment-modal .cp-methods-balance-col { 
                padding-right:0; 
                min-height:0; 
            }
            .customer-payment-modal .cp-methods-balance-col .cp-balance-box { 
                position:static; 
                width:100%; 
                margin-bottom:18px !important; 
                justify-content:flex-end; 
            }
            .customer-payment-modal .cp-methods-balance-col .cp-method-grid { 
                width:100%; 
                max-width:570px; 
            }
        }
        .cp-table-wrap { 
            border: 1px solid #cbd5e1; 
            background: #fff; 
            overflow: auto; 
            max-height: 430px; 
        }
        .cp-table { 
            margin-bottom: 0; 
            min-width: 950px; 
        }
        .cp-table thead th { 
            position: sticky; 
            top: 0; 
            z-index: 2; 
            background: #047857; 
            color: #ffffff; 
            font-size: 12px; 
            font-weight: 600; 
            text-transform: uppercase; 
            border-bottom: 1px solid #047857; 
            padding: 10px 12px; 
        }
        .cp-table tbody tr:nth-child(odd) { 
            background: #ffffff; 
        }
        .cp-table tbody tr:nth-child(even) { 
            background: #eafaf0; 
        }
        .cp-table td { 
            vertical-align: middle; 
            border-color: #cdebd9; 
            font-size: 13px; 
            color:#052A47; 
            padding: 10px 12px; 
        }
        .cp-payment-cell input { 
            text-align: right; 
            font-weight: 600; 
            border-radius: 6px; 
            height: 30px; 
            font-size: 13px; 
        }
        .cp-message-row { 
            min-height: 150px; 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            color: #6b7280; 
            font-size: 13px; 
            font-weight: 500; 
            background: repeating-linear-gradient(0deg, #ffffff 0, #ffffff 38px, #eafaf0 38px, #eafaf0 76px); 
        }
        .cp-total-row { 
            background: #fff !important; 
            font-weight: 600; 
            border-top: 2px solid #9ca3af; 
        }
        .cp-bottom-panel { 
            display:grid; 
            grid-template-columns: 1fr 360px; 
            border:1px solid #cbd5e1; 
            border-radius:6px; 
            background:#fff; 
            margin-top:10px; 
            overflow:hidden; 
        }
        .cp-memo-box { 
            padding:10px 12px; 
            border-right:1px solid #e5e7eb; 
        }
        .cp-memo-box .cp-label { 
            display:block; 
            margin-bottom:6px; 
        }
        .cp-memo-input { 
            width:100%; 
            height:34px; 
            border:1px solid #cbd5e1; 
            border-radius:4px; 
            padding:6px 9px; 
            font-size:13px; 
            resize:none; 
        }
        .cp-summary-box { 
            padding:10px 14px;
        }
        .cp-summary-title { 
            font-size:13px; 
            font-weight:700; 
            color:#111827; 
            text-transform:uppercase; 
            margin-bottom:8px; 
        }
        .cp-summary-line { 
            display:flex; 
            justify-content:space-between; 
            align-items:center; 
            gap:12px; 
            font-size:12px; 
            color:#6b7280; 
            padding:5px 0; 
        }
        .cp-summary-line .value { 
            color:#111827; 
            font-weight:600; 
            min-width:95px; 
            text-align:right; 
        }
        @media (max-width: 768px) { 
            .cp-bottom-panel { 
                grid-template-columns: 1fr; 
            } 
            .cp-memo-box { 
                border-right:0; 
                border-bottom:1px solid #e5e7eb; 
            } 
        }
        .cp-detail-panel { 
            margin-top:12px; 
            font-size:13px; 
            min-width:0; 
            max-width:570px; 
            padding-top:0; 
        }
        .cp-detail-panel .payment-detail-title { 
            display:none; 
        }
        .cp-detail-panel .cp-form-row { 
            grid-template-columns: 150px 300px; 
            gap:10px; 
            align-items:center; 
            margin-bottom:12px; 
        }
        .cp-detail-panel .cp-field-wrap { 
            width:300px; 
            max-width:300px; 
        }
        .cp-detail-panel .form-control { 
            width:300px; 
            max-width:300px; 
            height:36px; 
            border:1px solid #cbd5e1; 
            border-radius:7px; 
            font-size:13px; 
            background:#fff; 
        }
        .cp-detail-panel .form-control:focus, .cp-input:focus, 
        .cp-select:focus, .cp-row-payment:focus { 
            border-color:#44D34E; 
            box-shadow:0 0 0 .16rem rgba(68,211,78,.16); 
        }
        .cp-unapplied-row { 
            display:none; 
            margin-top:5px; 
            font-size:12px; 
            font-weight:600; 
            color:#047857; 
        }
        #cpCashChangeRow { 
            margin-top:6px; 
            margin-bottom:0; 
            font-size:12px; 
            color:#6b7280; 
            line-height:1.2; 
        }
        #cpCashChangeDisplay { 
            color:#047857; 
            font-weight:600; 
        }
        .cp-row-check { 
            width:16px; 
            height:16px; 
            cursor:pointer; 
            accent-color:#047857; 
        }
        .cp-table tbody tr.cp-applied-row { 
            background:#ecfdf5 !important; 
        }
        .cp-payment-cell input { 
            background:#ffffff; 
        }
        @media (max-width: 992px) { 
            .cp-entry-grid { 
                grid-template-columns:1fr; 
                gap:0; 
                max-width:460px; 
            } 
        }
        @media (max-width: 768px) { 
            .customer-payment-title { 
                font-size: 21px; 
            } 
            .cp-form-row, .cp-detail-panel .cp-form-row { 
                grid-template-columns:1fr; 
                gap:5px; 
            } 
            .cp-form-row.cp-amount-row .cp-label { 
                padding-top:0; 
                min-height:auto; 
                justify-content:flex-start; 
            } 
            .cp-label { 
                text-align:left; 
            } 
            .cp-method-grid { 
                grid-template-columns: repeat(2, 1fr); 
            } 
            .cp-method-btn { 
                border-bottom: 1px solid #d1d5db; 
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

            <span class="nav-text">Motorpool</span>
        </h3>
    </div>

    <div class="sidebar-content">
        <div class="sidebar-menu">
            <ul class="nav flex-column">
                <!-- Dashboard -->
                <li class="nav-item">
                    <a class="nav-link" href="motorpool_inventory.php">
                        <i class="bi bi-box-seam"></i>
                        <span class="nav-text">Current Inventory</span>
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
                                <a class="nav-link" href="enterbills.php">
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
                                <a class="nav-link" href="vendors.php">
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
                                <a class="nav-link active" href="collections.php">
                                    <i class="bi bi-cash-stack"></i>
                                    <span class="nav-text">Receive Payment</span>
                                </a>
                            </li>

                            <li class="nav-item">
                                <a class="nav-link" href="customer_list.php">
                                    <i class="bi bi-person-badge"></i>
                                    <span class="nav-text">Customer List</span>
                                </a>
                            </li>
                        </ul>
                    </div>
                </li>

                <!-- Employees Dropdown -->
                <li class="nav-item dropdown-nav">
                    <a class="nav-link" href="#" onclick="toggleSidebarDropdown(event, 'employeesMenu')">
                        <i class="bi bi-briefcase"></i>
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
                                <a class="nav-link" href="withdrawal.php">
                                    <i class="bi bi-journal-check"></i>
                                    <span class="nav-text">Write Checks</span>
                                </a>
                            </li>
                            
                            <li class="nav-item active">
                                <a class="nav-link" href="transferfunds.php">
                                    <i class="bi bi-arrow-left-right"></i>
                                    <span class="nav-text">Transfer Funds</span>
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
                                <a class="nav-link" href="chartofaccounts.php">
                                    <i class="bi bi-graph-up"></i>
                                    <span class="nav-text">Chart of Accounts</span>
                                </a>
                            </li>

                            <li class="nav-item">
                                <a class="nav-link" href="request_handler.php">
                                    <i class="bi bi-clipboard"></i>
                                    <span class="nav-text">RIS Monitoring</span>
                                </a>
                            </li>

                            <li class="nav-item">
                                <a class="nav-link" href="motorpool.php">
                                    <i class="bi bi-truck"></i>
                                    <span class="nav-text">Vehicle Profile</span>
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

    <div class="main-content" id="mainContent">
        <div class="navbar-top no-print">
            <button class="mobile-menu-btn" id="mobileMenuBtn"><i class="bi bi-list"></i></button>
            <div class="page-title"><h2>Collections</h2><p>Record customer payments and manage receivables</p></div>
        </div>

        <!-- Quick Stats Cards -->
        <div class="row stat-card-row g-2 g-md-3 mb-4">
            <div class="col-12 col-sm-6 col-lg-3"><div class="stat-card total h-100"><i class="bi bi-cash-stack stat-icon"></i>
            <div class="stat-content"><div class="stat-value">₱<?= number_format($total_receivables, 2) ?></div>
            <div class="stat-label">Total Receivables</div><small class="d-block">Unpaid Receivables</small></div></div></div>
            <div class="col-12 col-sm-6 col-lg-3"><div class="stat-card pending h-100"><i class="bi bi-clock-history stat-icon"></i><div class="stat-content"><div class="stat-value"><?= $avg_collection_days ?> days</div><div class="stat-label">Average Collection Period</div><small class="d-block">Days invoice to today (unpaid)</small></div></div></div>
            <div class="col-12 col-sm-6 col-lg-3"><div class="stat-card overdue h-100"><i class="bi bi-exclamation-triangle stat-icon"></i><div class="stat-content"><div class="stat-value">₱<?= number_format($overdue_receivables, 2) ?></div><div class="stat-label">Overdue Receivables</div><small class="d-block">Past due date only</small></div></div></div>
            <div class="col-12 col-sm-6 col-lg-3"><div class="stat-card aging h-100" id="agingCardBtn" style="cursor: pointer;"><i class="bi bi-pie-chart stat-icon"></i><div class="stat-content"><div class="stat-value">₱<?= number_format(($aging_1_7 + $aging_8_14 + $aging_15_21 + $aging_22_28 + $aging_beyond_28), 2) ?></div><div class="stat-label">Aging Breakdown</div><small class="d-block">Click to view details</small></div></div></div>
        </div>

        <!-- PENDING REMITTANCES SECTION (FOR ADMIN APPROVAL) - WITHOUT CHECKBOXES -->
<?php if (!empty($pending_remittances)): ?>
<div class="pending-remittances-section mb-4">

    <div class="section-header d-flex justify-content-between align-items-center"
         data-bs-toggle="collapse"
         data-bs-target="#pendingRemittancesCollapse"
         style="cursor:pointer;">

        <div>
            <i class="bi bi-clock-history me-2"></i>
            Pending Remittances (Awaiting Your Approval)
            <span class="badge bg-light text-dark ms-2">
                <?= count($pending_remittances) ?>
            </span>
        </div>

        <div class="d-flex align-items-center gap-2">
            <button class="btn-approve-all"
                    id="approveAllRemittancesBtn"
                    onclick="event.stopPropagation(); approveAllRemittances();">
                <i class="bi bi-check-all me-1"></i>
                Approve All (<?= count($pending_remittances) ?>)
            </button>

            <i class="bi bi-chevron-down"></i>
        </div>
    </div>

    <div class="collapse" id="pendingRemittancesCollapse">

        <!-- Desktop Table View -->
        <div class="table-responsive d-none d-md-block">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>Collector</th>
                    <th>Customer</th>
                    <th>Invoice #</th>
                    <th>Amount</th>
                    <th>Payment Method</th>
                    <th>Collection Date</th>
                    <th>Attachment</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($pending_remittances as $remit): ?>
                <tr class="remittance-row" data-remittance-id="<?= $remit['remittance_id'] ?>" data-photo="<?= htmlspecialchars($remit['attachment_path'] ?? '') ?>" data-title="Remittance - <?= htmlspecialchars($remit['invoice_number'] ?? '') ?>">
                    <td><?= htmlspecialchars(($remit['collector_first'] ?? '') . ' ' . ($remit['collector_last'] ?? '')) ?></td>
                    <td><?= htmlspecialchars($remit['customer_name'] ?? 'Unknown') ?></td>
                    <td><?= htmlspecialchars($remit['invoice_number'] ?? '') ?></td>
                    <td class="text-end fw-bold text-success">₱<?= number_format($remit['amount'], 2) ?></td>
                    <td><span class="badge bg-info"><?= ucfirst(str_replace('_', ' ', $remit['payment_method'])) ?></span></td>
                    <td><?= date('M d, Y', strtotime($remit['collection_date'])) ?></td>
                    <td>
                        <?php if (!empty($remit['attachment_path'])): ?>
                            <span class="badge bg-primary" style="cursor: pointer;" onclick="event.stopPropagation(); openAttachmentPhotoModal('<?= htmlspecialchars($remit['attachment_path']) ?>', 'Remittance - <?= htmlspecialchars($remit['invoice_number'] ?? '') ?>')"><i class="bi bi-image"></i> View</span>
                        <?php else: ?>
                            <span class="text-muted small">No photo</span>
                        <?php endif; ?>
                    </td>
                    <td class="remittance-actions">
                        <button class="btn-approve" onclick="event.stopPropagation(); approveRemittance(<?= $remit['remittance_id'] ?>)"><i class="bi bi-check-lg"></i> Approve</button>
                        <button class="btn-reject" onclick="event.stopPropagation(); rejectRemittance(<?= $remit['remittance_id'] ?>)"><i class="bi bi-x-lg"></i> Reject</button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
    
    <!-- Mobile Cards View for pending remittances -->
    <div class="d-md-none p-3">
        <?php foreach ($pending_remittances as $remit): ?>
        <div class="remittance-card" data-remittance-id="<?= $remit['remittance_id'] ?>" data-photo="<?= htmlspecialchars($remit['attachment_path'] ?? '') ?>" data-title="Remittance - <?= htmlspecialchars($remit['invoice_number'] ?? '') ?>">
            <div class="remittance-header">
                <strong><i class="bi bi-person-badge"></i> <?= htmlspecialchars(($remit['collector_first'] ?? '') . ' ' . ($remit['collector_last'] ?? '')) ?></strong>
                <span class="badge bg-info"><?= ucfirst(str_replace('_', ' ', $remit['payment_method'])) ?></span>
            </div>
            <div><i class="bi bi-building"></i> <?= htmlspecialchars($remit['customer_name'] ?? 'Unknown') ?></div>
            <div><i class="bi bi-receipt"></i> Invoice: <?= htmlspecialchars($remit['invoice_number'] ?? '') ?></div>
            <div class="fw-bold text-success mt-1">₱<?= number_format($remit['amount'], 2) ?></div>
            <div class="text-muted small"><i class="bi bi-calendar"></i> Collected: <?= date('M d, Y', strtotime($remit['collection_date'])) ?></div>
            <div class="mt-1">
                <?php if (!empty($remit['attachment_path'])): ?>
                    <span class="badge bg-primary" style="cursor: pointer;" onclick="event.stopPropagation(); openAttachmentPhotoModal('<?= htmlspecialchars($remit['attachment_path']) ?>', 'Remittance - <?= htmlspecialchars($remit['invoice_number'] ?? '') ?>')"><i class="bi bi-image"></i> View Photo</span>
                <?php else: ?>
                    <span class="text-muted small"><i class="bi bi-image"></i> No attachment</span>
                <?php endif; ?>
            </div>
            <div class="mt-2 d-flex gap-2">
                <button class="btn-approve btn-sm" onclick="approveRemittance(<?= $remit['remittance_id'] ?>)"><i class="bi bi-check-lg"></i> Approve</button>
                <button class="btn-reject btn-sm" onclick="rejectRemittance(<?= $remit['remittance_id'] ?>)"><i class="bi bi-x-lg"></i> Reject</button>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    
    <div class="alert alert-warning m-3 small" style="background: #fff8e7; border-color: #fed7aa; color: #92400e;">
        <i class="bi bi-info-circle me-1"></i>
        Review each remittance carefully. Approving will record the payment and update the invoice.
    </div>
</div>
<?php endif; ?>

        <!-- RETURNED INVOICE TICKETS SECTION -->
        <?php if (!empty($returned_invoices)): ?>
        <div class="pending-remittances-section mb-4">
            <div class="section-header" style="background:#052A47;">
                <i class="bi bi-arrow-return-left"></i> Returned Invoice Tickets
                <span class="badge bg-light text-dark ms-2"><?= count($returned_invoices) ?></span>
            </div>
            <div class="table-responsive d-none d-md-block">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Returned By</th>
                            <th>Customer</th>
                            <th>Invoice #</th>
                            <th>Amount</th>
                            <th>Reason</th>
                            <th>Returned Date</th>
                            <th>Photo</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($returned_invoices as $ret): ?>
                        <tr class="return-row" data-photo="<?= htmlspecialchars($ret['attachment_path'] ?? '') ?>" data-title="Return Ticket - <?= htmlspecialchars($ret['invoice_number'] ?? '') ?>">
                            <td><?= htmlspecialchars(trim(($ret['returned_first'] ?? '') . ' ' . ($ret['returned_last'] ?? ''))) ?></td>
                            <td><?= htmlspecialchars($ret['customer_name'] ?? 'Unknown') ?></td>
                            <td><?= htmlspecialchars($ret['invoice_number'] ?? '') ?></td>
                            <td class="text-end"><strong class="text-danger">₱<?= number_format((float)($ret["balance_amount"] ?? 0), 2) ?></strong><div class="small text-muted">Paid: ₱<?= number_format((float)($ret["paid_amount"] ?? 0), 2) ?></div></td>
                            <td><?= htmlspecialchars($ret['return_reason'] ?? '') ?></td>
                            <td><?= date('M d, Y', strtotime($ret['created_at'])) ?></td>
                            <td>
                                <?php if (!empty($ret['attachment_path'])): ?>
                                    <span class="badge bg-primary"><i class="bi bi-image"></i> Click row</span>
                                <?php else: ?>
                                    <span class="text-muted small">No photo</span>
                                <?php endif; ?>
                            </td>
                            <td class="remittance-actions" onclick="event.stopPropagation();">
                                <button class="btn-approve" onclick="approveReturnTicket(<?= $ret['return_id'] ?>)"><i class="bi bi-check-lg"></i> Approve</button>
                                <button class="btn-reject" onclick="rejectReturnTicket(<?= $ret['return_id'] ?>)"><i class="bi bi-x-lg"></i> Reject</button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="d-md-none p-3">
                <?php foreach ($returned_invoices as $ret): ?>
                <div class="remittance-card return-row" data-photo="<?= htmlspecialchars($ret['attachment_path'] ?? '') ?>" data-title="Return Ticket - <?= htmlspecialchars($ret['invoice_number'] ?? '') ?>">
                    <div class="remittance-header">
                        <strong><i class="bi bi-person-badge"></i> <?= htmlspecialchars(trim(($ret['returned_first'] ?? '') . ' ' . ($ret['returned_last'] ?? ''))) ?></strong>
                        <span class="badge bg-dark">Returned</span>
                    </div>
                    <div><i class="bi bi-building"></i> <?= htmlspecialchars($ret['customer_name'] ?? 'Unknown') ?></div>
                    <div><i class="bi bi-receipt"></i> Invoice: <?= htmlspecialchars($ret['invoice_number'] ?? '') ?></div>
                    <div class="fw-bold mt-1 text-danger">Remaining: ₱<?= number_format((float)($ret["balance_amount"] ?? 0), 2) ?></div><div class="text-muted small">Paid: ₱<?= number_format((float)($ret["paid_amount"] ?? 0), 2) ?></div>
                    <div class="text-muted small mt-1"><i class="bi bi-chat-left-text"></i> <?= htmlspecialchars($ret['return_reason'] ?? '') ?></div>
                    <div class="text-muted small"><i class="bi bi-calendar"></i> Returned: <?= date('M d, Y', strtotime($ret['created_at'])) ?></div>
                    <div class="mt-1">
                        <?php if (!empty($ret['attachment_path'])): ?>
                            <span class="badge bg-primary"><i class="bi bi-image"></i> Tap card to view photo</span>
                        <?php else: ?>
                            <span class="text-muted small"><i class="bi bi-image"></i> No attachment</span>
                        <?php endif; ?>
                    </div>
                    <div class="mt-2 d-flex gap-2" onclick="event.stopPropagation();">
                        <button class="btn-approve btn-sm" onclick="approveReturnTicket(<?= $ret['return_id'] ?>)"><i class="bi bi-check-lg"></i> Approve</button>
                        <button class="btn-reject btn-sm" onclick="rejectReturnTicket(<?= $ret['return_id'] ?>)"><i class="bi bi-x-lg"></i> Reject</button>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
        <!-- Batch Assign Bar (shown when invoices are selected) -->
        <div id="batchAssignBar" class="batch-assign-bar" style="display: none;">
            <div class="d-flex align-items-center gap-3">
                <i class="bi bi-check2-square fs-5"></i>
                <span class="selected-count"><span id="selectedCount">0</span> invoice(s) selected</span>
            </div>
            <div class="d-flex gap-2">
                <button class="btn-clear-selection" id="clearSelectionBtn"><i class="bi bi-x-lg"></i> Clear</button>
                <button class="btn-assign-batch" id="batchAssignBtn"><i class="bi bi-person-plus"></i> Assign to Collector</button>
            </div>
        </div>

        <!-- Customer Selection and Filters -->
        <div class="supplier-filter-card mb-4">
            <div class="supplier-filter-header"><h5><i class="bi bi-funnel"></i> Filter Invoices</h5></div>
            <div class="supplier-filter-content" id="invoiceFilterContent">
                <div class="supplier-filter-one-line">
                    <div class="filter-item" style="flex: 2;">
                        <label class="supplier-filter-label">GLOBAL SEARCH</label>
                        <input type="hidden" id="customerSelect" value="">
                        <div class="supplier-search-wrapper">
                            <i class="bi bi-search search-icon"></i>
                            <input type="text" id="invoiceGlobalSearch" class="supplier-filter-input" placeholder="Search customer, customer group, invoice no., SI, SO, status, remarks..." autocomplete="off">
                        </div>
                    </div>
                    <div class="filter-item"><label class="supplier-filter-label">DATE FROM</label><input type="date" class="supplier-filter-input" id="dateFrom"></div>
                    <div class="filter-item"><label class="supplier-filter-label">DATE TO</label><input type="date" class="supplier-filter-input" id="dateTo"></div>
                </div>
            </div>
        </div>

        <!-- Credit Summary (shown only when a specific customer is selected) -->
        <div id="creditSummary" class="credit-summary" style="display: none;"><div class="credit-item"><strong>Credit Limit:</strong> <span id="creditLimit">0.00</span></div><div class="credit-item"><strong>Outstanding Balance:</strong> <span id="outstandingBalance">0.00</span></div><div class="credit-item"><strong>Available Credit:</strong> <span id="availableCredit">0.00</span></div></div>

        <!-- Invoices Table -->
        <div class="data-table">
            <div class="table-header">
                <h5 class="mb-0">
                    <i class="bi bi-receipt"></i> Customer Invoices (Ready for Collection)
                </h5>
                <div class="d-flex gap-2 flex-wrap justify-content-end">
                    <button type="button" class="btn btn-success" id="openBeginningBalanceBtn" style="border-radius: 10px; font-weight: 600;">
                        <i class="bi bi-plus-circle me-1"></i> Add Beginning Balance
                    </button>
                    <button type="button" class="btn btn-primary" id="openCustomerPaymentBtn" style="border-radius: 10px; font-weight: 600; background:#052A47; border-color:#052A47;">
                        <i class="bi bi-cash-coin me-1"></i> Customer Payment
                    </button>
                    <button type="button" class="collection-report-print-btn" id="openCollectionReportFilterBtn">
                        <i class="bi bi-printer me-1"></i> Print Collection Report
                    </button>
                </div>
            </div>
            <div class="customer-group-tabs-wrap" id="customerGroupTabsWrap">
                <div class="customer-group-tabs" id="customerGroupTabs">
                    <button type="button" class="customer-group-tab active" data-group="all">
                        <i class="bi bi-people"></i>
                        <span>All Groups</span>
                        <span class="group-count"><?= count($default_invoices) ?></span>
                        <span class="group-total">₱<?= number_format($total_group_receivables, 2) ?></span>
                    </button>
                    <?php foreach ($customer_group_tabs as $tab): ?>
                        <button type="button" class="customer-group-tab" data-group="<?= htmlspecialchars($tab['group_name'], ENT_QUOTES) ?>">
                            <i class="bi bi-tag"></i>
                            <span><?= htmlspecialchars($tab['group_name']) ?></span>
                            <span class="group-count"><?= (int)$tab['count'] ?></span>
                            <span class="group-total">₱<?= number_format((float)$tab['total'], 2) ?></span>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-hover" id="invoicesTable">
                    <thead>
                        <tr>
                            <th class="checkbox-column"><input type="checkbox" id="selectAllCheckbox" class="select-all-checkbox"></th>
                            <th>Customer</th>
                            <th>Invoice #</th>
                            <th>Date</th>
                            <th>SO #</th>
                            <th>Amount Due</th>
                            <th>Status</th>
                            <th>Assigned To</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody id="invoicesTableBody">
                        <?php if (empty($default_invoices)): ?>
                            <tr>
                                <td colspan="9" class="text-center text-muted">No pending invoices found</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($default_invoices as $invoice): 
                                $invoiceDate = $invoice['invoice_date'] ? date('Y-m-d', strtotime($invoice['invoice_date'])) : '-';
                                $amountDue = number_format($invoice['total_amount'] ?? 0, 2);
                                $statusClass = $invoice['status'] === 'overdue' ? 'badge-overdue' : 'badge-pending';
                                $statusText = $invoice['status'] === 'overdue' ? 'Overdue' : 'Pending';
                                $orderStatusText = $invoice['order_status'] ?? 'Unknown';
                                $customerName = htmlspecialchars($invoice['customer_name'] ?? 'Unknown');
                                $orderStatusLower = strtolower($orderStatusText);
                                $orderTypeLower = strtolower(trim($invoice['order_type'] ?? $invoice['fulfillment_type'] ?? ''));
                                $isPickupInvoice = in_array($orderTypeLower, ['pickup','pick_up','customer_pickup','store_pickup','branch_pickup','pick-up','for_pickup'], true);
                                $isCollectibleInvoice = $orderStatusLower === 'delivered' || $isPickupInvoice || $orderTypeLower === 'beginning_balance';
                                $paymentButton = $isCollectibleInvoice ? '<div class="invoice-action-buttons"><button class="btn-pay btn-action-icon" title="Record Payment" aria-label="Record Payment" onclick="event.stopPropagation(); openPaymentModal(' . $invoice['invoice_id'] . ', \'' . addslashes($invoice['invoice_number']) . '\', ' . ($invoice['total_amount'] ?? 0) . ')"><i class="bi bi-cash-stack"></i></button></div>' : ($orderStatusLower === 'confirmed' ? '<span class="text-muted small">Await Delivery</span>' : '<span class="text-muted small">Not Ready</span>');
                                $assignedName = trim($invoice['assigned_to_name'] ?? '');
                                if ($assignedName !== '') {
                                    $paymentButton = '<span class="text-muted small"><i class="bi bi-person-check me-1"></i>Assigned</span>';
                                }
                                $assignedRole = ($invoice['assigned_to_role'] ?? '') === 'delivery' ? 'Driver' : (($invoice['assigned_to_role'] ?? '') === 'sales' ? 'Sales Agent' : '');
                                $assignedDate = !empty($invoice['collection_date']) ? date('M d, Y', strtotime($invoice['collection_date'])) : '';
                                $assignedCell = $assignedName !== ''
                                    ? '<span class="assigned-collector-badge"><i class="bi bi-person-check"></i>' . htmlspecialchars($assignedName) . '</span><span class="assigned-date-small">' . htmlspecialchars($assignedRole . ($assignedDate ? ' • ' . $assignedDate : '')) . '</span>'
                                    : '<span class="text-muted small"><i class="bi bi-dash-circle me-1"></i>Unassigned</span>';
                            ?>
                                <tr class="invoice-row" data-invoice-id="<?= $invoice['invoice_id'] ?>" data-customer-id="<?= $invoice['customer_id'] ?>" data-branch-id="<?= $invoice['branch_id'] ?? 0 ?>" data-customer-group="<?= htmlspecialchars($invoice['customer_group'] ?? 'Ungrouped', ENT_QUOTES) ?>">
                                    <td class="checkbox-column"><input type="checkbox" class="row-checkbox" data-invoice-id="<?= $invoice['invoice_id'] ?>" data-customer-id="<?= $invoice['customer_id'] ?>" data-branch-id="<?= $invoice['branch_id'] ?? 0 ?>"></td>
                                    <td><strong><?= $customerName ?></strong></td>
                                    <td><?= htmlspecialchars($invoice['invoice_number'] ?? '') ?></td>
                                    <td><?= $invoiceDate ?></td>
                                    <td><?= htmlspecialchars($invoice['so_number'] ?? '-') ?></td>
                                    <td class="text-end fw-bold">₱<?= $amountDue ?></td>
                                    <td><span class="<?= $statusClass ?>"><?= $statusText ?></span></td>
                                    <td><?= $assignedCell ?></td>
                                    <td><?= $paymentButton ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Beginning Balance Modal -->
        <div class="modal fade" id="beginningBalanceModal" tabindex="-1" data-bs-backdrop="static">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header bg-success text-white">
                        <h5 class="modal-title">Add Beginning Balance</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-12">
    <label class="form-label fw-bold">Customer</label>
    <div class="d-flex gap-2">
        <div id="bbCustomerDropdownWrap" class="bb-customer-picker" style="flex: 1; min-height: 38px;">
            <input type="hidden" name="customer_id" id="bbCustomerId">
            <input type="hidden" id="bbEditInvoiceId" value="">
            <input type="text" id="bbCustomerSearch" class="bb-customer-input" placeholder="-- Select registered customer --" autocomplete="off">
            <i class="bi bi-chevron-down bb-customer-icon"></i>
            <div class="bb-customer-menu" id="bbCustomerMenu">
                <?php foreach ($branch_customers as $customer): ?>
                    <?php
                        $bb_customer_group = trim((string)($customer['customer_group'] ?? ''));
                        if ($bb_customer_group === '') $bb_customer_group = 'Ungrouped';
                        $bb_customer_base_label = $customer['customer_name'] . (!empty($customer['store_name']) ? ' - ' . $customer['store_name'] : '') . (!empty($customer['customer_code']) ? ' (' . $customer['customer_code'] . ')' : '');
                        $bb_customer_label = $bb_customer_base_label . ' - ' . $bb_customer_group;
                    ?>
                    <div class="bb-customer-item" data-value="<?= (int)$customer['customer_id'] ?>" data-label="<?= htmlspecialchars($bb_customer_label, ENT_QUOTES) ?>">
                        <div class="bb-customer-main">
                            <strong><?= htmlspecialchars($bb_customer_base_label) ?></strong>
                            <span class="bb-customer-group-badge"><?= htmlspecialchars($bb_customer_group) ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
                <div class="bb-customer-empty" id="bbCustomerEmpty">No customer found</div>
            </div>
        </div>
        <button type="button" class="btn btn-success" id="openBbAddCustomerBtn" style="white-space: nowrap; height: 38px;">
            <i class="bi bi-plus-lg"></i> Add Customer
        </button>
    </div>
</div>
                            <div class="col-md-12">
                                <label class="form-label fw-bold d-block mb-2">Beginning Balance Document</label>
                                <div class="btn-group w-100" role="group" aria-label="Beginning Balance Document Type">
                                    <input type="radio" class="btn-check" name="bbDocumentType" id="bbDocTypeSo" value="so" autocomplete="off" checked>
                                    <label class="btn btn-outline-success" for="bbDocTypeSo">SO</label>
                                    <input type="radio" class="btn-check" name="bbDocumentType" id="bbDocTypeSi" value="si" autocomplete="off">
                                    <label class="btn btn-outline-success" for="bbDocTypeSi">SI</label>
                                </div>
                            </div>
                           <div class="col-md-6">
    <label class="form-label fw-bold" id="bbDocumentDigitsLabel">SO Number <span class="text-danger">*</span></label>
    <div class="input-group">
        <span class="input-group-text" id="bbDocumentPrefix">SO-<?= date('Ymd') ?>-</span>
        <input type="text" class="form-control" id="bbDocumentDigits" inputmode="numeric" maxlength="6" placeholder="5 to 6 digits" required style="min-width: 0;">
    </div>
    <div class="form-text" id="bbDocumentDigitsHelp">Last 5 to 6 digits only.</div>
</div>
                            <div class="col-md-12" id="bbSiBusinessFields" style="display:none;">
                                <div class="card border-0 bg-light">
                                    <div class="card-body p-3">
                                        <div class="row g-3">
                                            <div class="col-md-12">
                                                <label class="form-label fw-bold">Registered Business Name <span class="text-danger">*</span></label>
                                                <input type="text" class="form-control" id="bbRegisteredBusinessName" placeholder="Registered Business Name" required>
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label fw-bold">TIN <span class="text-danger">*</span></label>
                                                <input type="text" class="form-control" id="bbTinNumber" placeholder="TIN" required>
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label fw-bold">Address <span class="text-danger">*</span></label>
                                                <input type="text" class="form-control" id="bbBusinessAddress" placeholder="Address" required>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <input type="hidden" id="bbInvoiceDigits" value="">
                            <input type="hidden" id="bbSoDigits" value="">
                            <input type="hidden" id="bbSiDigits" value="">
                            <input type="hidden" id="bbGeneratedInvoice" value="">
                            <input type="hidden" id="bbGeneratedSo" value="">
                            <input type="hidden" id="bbGeneratedSi" value="">
                            <input type="hidden" id="bbInvoiceDate" value="<?= date('Y-m-d') ?>">
                            <div class="col-md-6">
                                <label class="form-label fw-bold">Date</label>
                                <input type="date" class="form-control" id="bbDueDate" value="<?= date('Y-m-d') ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold">Beginning Balance Amount (₱) <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="bbAmount" inputmode="decimal" placeholder="0.00" required>
                            </div>

                            <div class="col-md-12">
                                <label class="form-label fw-bold">Attachments <span class="text-muted fw-normal">(Optional)</span></label>
                                <div id="bbAttachmentsContainer" class="d-flex flex-column gap-2">
                                    <div class="bb-attachment-row border rounded p-2 bg-light">
                                        <div class="input-group">
                                            <input type="file" class="form-control bb-attachment-input" name="attachments[]" accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.doc,.docx,.xls,.xlsx,.csv,.txt">
                                            <button type="button" class="btn btn-outline-danger bb-remove-attachment-btn" style="display:none;">
                                                <i class="bi bi-x-lg"></i>
                                            </button>
                                        </div>
                                        <div class="bb-attachment-preview mt-2"></div>
                                    </div>
                                </div>
                                <button type="button" class="btn btn-outline-success btn-sm mt-2" id="addBeginningBalanceAttachmentBtn">
                                    <i class="bi bi-plus-circle me-1"></i> Add Attachment
                                </button>
                                <div class="form-text">Optional. Allowed: images, PDF, Word, Excel, CSV, TXT. Max 10MB each file.</div>
                            </div>
                            <div class="col-md-12">
                                <label class="form-label fw-bold">Remarks</label>
                                <textarea class="form-control" id="bbRemarks" rows="2" placeholder="Optional notes">Beginning balance</textarea>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" id="saveCloseBeginningBalanceBtn"><i class="bi bi-save me-1"></i> Save & Close</button>
                        <button type="button" class="btn btn-success" id="saveBeginningBalanceBtn"><i class="bi bi-save me-1"></i> Save & New</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Add Customer Modal for Beginning Balance -->
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
    


        <!-- Customer Payment Modal -->
        <div class="modal fade customer-payment-modal" id="customerPaymentModal" tabindex="-1" data-bs-backdrop="static">
            <div class="modal-dialog modal-fullscreen-xl-down modal-xl modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="bi bi-cash-coin"></i>Customer Payment</h5>
                        <button type="button" class="cp-close-x" data-bs-dismiss="modal" aria-label="Close">&times;</button>
                    </div>
                    <div class="modal-body p-2 p-md-3">
                        <div class="customer-payment-box p-3">
                            <div class="row g-3 align-items-start cp-payment-top-layout">
                                <div class="col-lg-auto cp-left-fields-col">
                                    <div class="customer-payment-title mb-3">Customer Payment</div>
                                    <div class="cp-entry-grid">
                                    <div class="cp-form-area">
                                        <div class="cp-form-row">
                                            <label class="cp-label">Received From</label>
                                            <div class="cp-field-wrap">
                                                <select class="form-select cp-select d-none" id="cpCustomerSelect">
                                                    <option value="">Select customer</option>
                                                    <?php foreach ($branch_customers as $cust): ?>
                                                        <?php
                                                            $cp_customer_name = trim((string)($cust['customer_name'] ?? ''));
                                                            $cp_customer_group = trim((string)($cust['customer_group'] ?? ''));
                                                            if ($cp_customer_group === '') $cp_customer_group = 'Ungrouped';
                                                        ?>
                                                        <option value="<?= (int)$cust['customer_id'] ?>" data-group="<?= htmlspecialchars($cp_customer_group, ENT_QUOTES) ?>"><?= htmlspecialchars($cp_customer_name) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <div class="cp-customer-picker" id="cpCustomerPicker">
                                                    <div class="cp-customer-toggle" id="cpCustomerToggle" aria-expanded="false">
                                                        <input type="text" class="cp-customer-search" id="cpCustomerSearch" placeholder="Select customer" autocomplete="off">
                                                        <i class="bi bi-chevron-down cp-customer-toggle-icon"></i>
                                                    </div>
                                                    <div class="cp-customer-menu" id="cpCustomerMenu">
                                                        <?php foreach ($branch_customers as $cust): ?>
                                                            <?php
                                                                $cp_customer_name = trim((string)($cust['customer_name'] ?? ''));
                                                                $cp_customer_group = trim((string)($cust['customer_group'] ?? ''));
                                                                if ($cp_customer_group === '') $cp_customer_group = 'Ungrouped';
                                                            ?>
                                                            <button type="button"
                                                                    class="cp-customer-option"
                                                                    data-value="<?= (int)$cust['customer_id'] ?>"
                                                                    data-label="<?= htmlspecialchars($cp_customer_name, ENT_QUOTES) ?>"
                                                                    data-group="<?= htmlspecialchars($cp_customer_group, ENT_QUOTES) ?>">
                                                                <span class="cp-customer-option-name"><?= htmlspecialchars($cp_customer_name) ?></span>
                                                                <span class="cp-customer-option-group"><?= htmlspecialchars($cp_customer_group) ?></span>
                                                            </button>
                                                        <?php endforeach; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="cp-form-row cp-amount-row">
                                            <label class="cp-label" id="cpPaymentAmountLabel">Payment Amount</label>
                                            <div class="cp-field-wrap">
                                                <input type="text" class="form-control cp-input cp-amount-input" id="cpPaymentAmount" value="0.00" inputmode="decimal">
                                            </div>
                                        </div>
                                        <div class="cp-form-row">
                                            <label class="cp-label">Date</label>
                                            <div class="cp-field-wrap"><input type="date" class="form-control cp-input" id="cpPaymentDate" value="<?= date('Y-m-d') ?>"></div>
                                        </div>
                                        <div id="cpLeftPaymentDetails" style="display:none;"></div>
                                    </div>
                                    </div>
                                </div>
                                <div class="col-lg cp-methods-balance-col">
                                    <div class="cp-balance-box mb-4">CUSTOMER BALANCE <span class="amount" id="cpCustomerBalance">0.00</span></div>
                                    <div class="cp-method-grid cp-method-grid-three">
                                        <button type="button" class="cp-method-btn active" data-method="cash"><i class="bi bi-cash-stack"></i><span>CASH</span></button>
                                        <button type="button" class="cp-method-btn" data-method="check"><i class="bi bi-check2-circle"></i><span>CHECK</span></button>
                                        <button type="button" class="cp-method-btn" data-method="online_transfer"><i class="bi bi-globe2"></i><span>ONLINE<br>TRANSFER</span></button>
                                    </div>
                                    <div class="cp-detail-panel" id="cpPaymentDetails" style="display:none;"></div>
                                </div>
                            </div>
                            <div class="cp-table-wrap">
                                <table class="table cp-table" id="cpInvoicesTable">
                                    <thead>
                                        <tr>
                                            <th style="width:38px;"><input type="checkbox" id="cpSelectAll"></th>
                                            <th>Date</th>
                                            <th>Number</th>
                                            <th class="text-end">Orig. Amt.</th>
                                            <th class="text-end">Amt. Due</th>
                                            <th class="text-end">Payment</th>
                                        </tr>
                                    </thead>
                                    <tbody id="cpInvoicesBody">
                                        <tr><td colspan="6" class="p-0"><div class="cp-message-row">Select the customer or job in the Received From field</div></td></tr>
                                    </tbody>
                                    <tfoot>
                                        <tr class="cp-total-row">
                                            <td colspan="3" class="text-end">Totals</td>
                                            <td class="text-end" id="cpOrigTotal">0.00</td>
                                            <td class="text-end" id="cpDueTotal">0.00</td>
                                            <td class="text-end" id="cpPayTotal">0.00</td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                            <div class="cp-bottom-panel">
                                <div class="cp-memo-box">
                                    <label class="cp-label" for="cpMemo">Memo</label>
                                    <textarea class="cp-memo-input" id="cpMemo" placeholder="Enter memo (optional)..."></textarea>
                                </div>
                                <div class="cp-summary-box">
                                    <div class="cp-summary-title">Amounts for Selected Invoices</div>
                                    <div class="cp-summary-line"><span>Amount Due</span><span class="value" id="cpSummaryAmountDue">0.00</span></div>
                                    <div class="cp-summary-line"><span>Total Payment</span><span class="value" id="cpSummaryTotalPayment">0.00</span></div>
                                    <div class="cp-summary-line"><span>Change</span><span class="value" id="cpSummaryChange">0.00</span></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-success" id="cpSaveCloseBtn"><i class="bi bi-save me-1"></i>Save & Close</button>
                        <button type="button" class="btn btn-primary" id="cpSaveNewBtn" style="background:#052A47;border-color:#052A47;"><i class="bi bi-save2 me-1"></i>Save & New</button>
                        <button type="button" class="btn btn-outline-secondary" id="cpClearBtn"><i class="bi bi-eraser me-1"></i>Clear</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Payment Modal (For recording payment which creates remittance for approval) -->
        <div class="modal fade" id="paymentModal" tabindex="-1" data-bs-backdrop="static"><div class="modal-dialog modal-lg"><div class="modal-content"><div class="modal-header bg-success text-white"><h5 class="modal-title"><i class="bi bi-cash-stack me-2"></i>Record Payment</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><input type="hidden" id="payInvoiceId"><input type="hidden" id="payInvoiceAmount"><div class="row mb-4"><div class="col-md-6"><div class="invoice-summary-card"><div class="invoice-summary-label">Invoice Number</div><div class="invoice-summary-value" id="payInvoiceNumber">-</div></div></div><div class="col-md-6"><div class="invoice-summary-card"><div class="invoice-summary-label">Amount Due</div><div class="invoice-summary-value text-success" id="payAmountDue">₱0.00</div></div></div></div>
        <div class="alert alert-warning mb-3">
            <i class="bi bi-info-circle me-1"></i>
            <strong>Note:</strong> Recording a payment will submit it for your approval. Once approved, the invoice will be marked as paid.
        </div>
        <div class="form-card p-3 mb-3" style="background:#f8fafc;border-radius:12px;border:1px solid #e9ecef;">
            <label class="fw-bold mb-2"><i class="bi bi-person-check me-1"></i>Assign Collector (if not assigned yet)</label>
            <select class="form-select" id="payAssignCollectorSelect">
                <option value="">No change / keep current</option>
                <?php foreach ($assignable_collectors as $collector): ?>
                    <?php $collectorRole = ($collector['role'] ?? '') === 'delivery' ? 'Driver' : 'Sales Agent'; ?>
                    <option value="<?= (int)$collector['user_id'] ?>">
                        <?= htmlspecialchars(trim(($collector['first_name'] ?? '') . ' ' . ($collector['last_name'] ?? ''))) ?> - <?= $collectorRole ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div id="paymentFormSection">
        <label class="fw-bold mb-2">Payment Method</label><div class="row g-3 mb-4"><div class="col-md-4"><div class="payment-method-option" data-method="cash"><i class="bi bi-cash-stack"></i><span>Cash</span></div></div><div class="col-md-4"><div class="payment-method-option" data-method="check"><i class="bi bi-check2-circle"></i><span>Check</span></div></div><div class="col-md-4"><div class="payment-method-option" data-method="online_transfer"><i class="bi bi-globe2"></i><span>Online Transfer</span></div></div></div><div id="paymentDetailsContainer"></div><div id="cashFields" style="display: none;"><div class="mb-3"><label class="form-label">Payment Amount (₱)</label><input type="text" class="form-control" id="cashTendered" placeholder="Enter partial or full cash payment" inputmode="decimal"><div class="form-text">Change: <span id="cashChangeDisplay">₱0.00</span></div></div></div><div id="otherAmountFields" style="display: none;"><div class="mb-3"><label class="form-label">Payment Amount (₱)</label><input type="text" class="form-control format-number" id="paymentAmount" placeholder="Enter partial or full payment amount"></div></div></div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="button" class="btn btn-warning" id="submitPaymentBtn"><i class="bi bi-send"></i> Submit for Approval</button></div></div></div></div>

        <!-- Batch Assign Modal -->
        <div class="modal fade" id="batchAssignModal" tabindex="-1" data-bs-backdrop="static">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="bi bi-person-plus me-2"></i>Assign Multiple Invoices</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label fw-bold"><i class="bi bi-list-check me-1"></i>Selected Invoices</label>
                            <div id="selectedInvoicesList" class="selected-invoices-list"></div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold"><i class="bi bi-person-badge me-1"></i>Assign to Collector</label>
                            <select class="form-select" id="batchCollectorSelect">
                                <option value="">-- Select Collector --</option>
                                <?php foreach ($assignable_collectors as $collector): ?>
                                    <?php $collectorRole = ($collector['role'] ?? '') === 'delivery' ? 'Driver' : 'Sales Agent'; ?>
                                    <option value="<?= (int)$collector['user_id'] ?>">
                                        <?= htmlspecialchars(trim(($collector['first_name'] ?? '') . ' ' . ($collector['last_name'] ?? ''))) ?> - <?= $collectorRole ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold"><i class="bi bi-calendar me-1"></i>Collection Date</label>
                            <input type="date" class="form-control" id="batchCollectionDate" value="<?= date('Y-m-d') ?>">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-success" id="confirmBatchAssignBtn"><i class="bi bi-check-circle"></i> Assign</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Reject Remittance Modal -->
        <div class="modal fade" id="rejectRemittanceModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header bg-danger text-white">
                        <h5 class="modal-title"><i class="bi bi-exclamation-triangle me-2"></i>Reject Remittance</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" id="rejectRemittanceId">
                        <div class="mb-3">
                            <label class="form-label fw-bold">Reason for Rejection</label>
                            <textarea class="form-control" id="rejectionReason" rows="3" placeholder="Please provide a reason for rejecting this remittance..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-danger" id="confirmRejectBtn"><i class="bi bi-x-lg"></i> Confirm Rejection</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Aging Breakdown Modal -->
        <div class="modal fade" id="agingModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">
                            <i class="bi bi-pie-chart me-2"></i>Receivables Aging Analysis
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    
                    <div class="modal-body" style="min-height: 450px; max-height: 70vh; overflow-y: auto;">
                        <div id="agingMainView" style="display: block;">
                            <div class="row g-3 mb-4">
                                <div class="col-6">
                                    <div class="summary-card total-summary">
                                        <div class="summary-icon"><i class="bi bi-cash-stack"></i></div>
                                        <div class="summary-content">
                                            <div class="summary-label">Total Receivables</div>
                                            <div class="summary-value">₱<?= number_format($total_receivables, 2) ?></div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="summary-card overdue-summary">
                                        <div class="summary-icon"><i class="bi bi-exclamation-triangle"></i></div>
                                        <div class="summary-content">
                                            <div class="summary-label">Overdue Amount</div>
                                            <div class="summary-value" id="agingOverdueAmount">₱<?= number_format($overdue_receivables, 2) ?></div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="section-title">
                                <i class="bi bi-calendar-range"></i>
                                <span>Aging Breakdown</span>
                            </div>

                            <div class="aging-item clickable" data-days-range="0-7" data-min-days="0" data-max-days="7">
                                <div class="aging-header">
                                    <div class="aging-range">
                                        <span class="range-badge bg-warning text-dark">0 - 7 days</span>
                                        <span class="percentage"><?= $total_receivables > 0 ? round(($aging_1_7 / $total_receivables) * 100) : 0 ?>%</span>
                                    </div>
                                    <div class="aging-amount">₱<?= number_format($aging_1_7, 2) ?></div>
                                </div>
                                <div class="progress"><div class="progress-bar bg-warning" style="width: <?= $total_receivables > 0 ? ($aging_1_7 / $total_receivables * 100) : 0 ?>%"></div></div>
                            </div>

                            <div class="aging-item clickable" data-days-range="8-14" data-min-days="8" data-max-days="14">
                                <div class="aging-header">
                                    <div class="aging-range">
                                        <span class="range-badge bg-orange">8 - 14 days</span>
                                        <span class="percentage"><?= $total_receivables > 0 ? round(($aging_8_14 / $total_receivables) * 100) : 0 ?>%</span>
                                    </div>
                                    <div class="aging-amount">₱<?= number_format($aging_8_14, 2) ?></div>
                                </div>
                                <div class="progress"><div class="progress-bar bg-orange" style="width: <?= $total_receivables > 0 ? ($aging_8_14 / $total_receivables * 100) : 0 ?>%"></div></div>
                            </div>

                            <div class="aging-item clickable" data-days-range="15-21" data-min-days="15" data-max-days="21">
                                <div class="aging-header">
                                    <div class="aging-range">
                                        <span class="range-badge bg-info">15 - 21 days</span>
                                        <span class="percentage"><?= $total_receivables > 0 ? round(($aging_15_21 / $total_receivables) * 100) : 0 ?>%</span>
                                    </div>
                                    <div class="aging-amount">₱<?= number_format($aging_15_21, 2) ?></div>
                                </div>
                                <div class="progress"><div class="progress-bar bg-info" style="width: <?= $total_receivables > 0 ? ($aging_15_21 / $total_receivables * 100) : 0 ?>%"></div></div>
                            </div>

                            <div class="aging-item clickable" data-days-range="22-28" data-min-days="22" data-max-days="28">
                                <div class="aging-header">
                                    <div class="aging-range">
                                        <span class="range-badge bg-danger">22 - 28 days</span>
                                        <span class="percentage"><?= $total_receivables > 0 ? round(($aging_22_28 / $total_receivables) * 100) : 0 ?>%</span>
                                    </div>
                                    <div class="aging-amount">₱<?= number_format($aging_22_28, 2) ?></div>
                                </div>
                                <div class="progress"><div class="progress-bar bg-danger" style="width: <?= $total_receivables > 0 ? ($aging_22_28 / $total_receivables * 100) : 0 ?>%"></div></div>
                            </div>

                            <div class="aging-item clickable" data-days-range="28+" data-min-days="29" data-max-days="999">
                                <div class="aging-header">
                                    <div class="aging-range">
                                        <span class="range-badge bg-dark">Beyond 28 days</span>
                                        <span class="percentage"><?= $total_receivables > 0 ? round(($aging_beyond_28 / $total_receivables) * 100) : 0 ?>%</span>
                                    </div>
                                    <div class="aging-amount">₱<?= number_format($aging_beyond_28, 2) ?></div>
                                </div>
                                <div class="progress"><div class="progress-bar bg-dark" style="width: <?= $total_receivables > 0 ? ($aging_beyond_28 / $total_receivables * 100) : 0 ?>%"></div></div>
                            </div>

                            <div class="legend-container">
                                <div class="legend-title"><i class="bi bi-info-circle-fill"></i><span>Aging based on overdue days from due date</span></div>
                                <div class="legend-badges">
                                    <span class="legend-badge bg-warning text-dark">1-7d</span>
                                    <span class="legend-badge bg-orange">8-14d</span>
                                    <span class="legend-badge bg-info">15-21d</span>
                                    <span class="legend-badge bg-danger">22-28d</span>
                                    <span class="legend-badge bg-dark">&gt;28d</span>
                                </div>
                            </div>
                        </div>

                        <div id="agingDetailView" style="display: none;">
                            <div class="d-flex align-items-center mb-3 sticky-top bg-light p-2 rounded" style="background: #f8fafc; position: sticky; top: -1.25rem; margin-top: -1rem; padding-top: 1rem; z-index: 10;">
                                <button type="button" class="btn btn-sm btn-outline-secondary me-3" id="backToAgingBtn" style="border-radius: 30px;">
                                    <i class="bi bi-arrow-left"></i> Back
                                </button>
                                <h6 class="mb-0" id="detailViewTitle">Receivables (0-7 days outstanding)</h6>
                            </div>
                            <div id="detailInvoicesList">
                                <div class="text-center text-muted py-4">Loading...</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>



        <!-- Collection Report Filter Modal -->
        <div class="modal fade" id="collectionReportFilterModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-lg">
                <div class="modal-content">
                    <div class="modal-header" style="background:#052A47;color:#fff;">
                        <h5 class="modal-title"><i class="bi bi-funnel me-2"></i>Collection Report Filter</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="collection-report-filter-card">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Report Type</label>
                                    <select class="form-select" id="collectionReportTypeFilter">
                                        <option value="all">ALL Collections</option>
                                        <option value="branch_admin">Branch Admin Collections</option>
                                        <option value="sales">Sales Agent Collections</option>
                                        <option value="delivery">Driver Collections</option>
                                        <option value="specific">Specific Collector Name</option>
                                    </select>
                                </div>
                                <div class="col-md-6" id="collectionSpecificCollectorWrap" style="display:none;">
                                    <label class="form-label fw-bold">Sales Agent / Driver / Branch Admin Name</label>
                                    <select class="form-select" id="collectionSpecificCollectorFilter">
                                        <option value="">-- Select Collector --</option>
                                        <?php foreach ($collection_report_collectors as $collector): ?>
                                            <option value="<?= (int)$collector['user_id'] ?>" data-role="<?= htmlspecialchars($collector['role']) ?>">
                                                <?= htmlspecialchars($collector['name']) ?> - <?= htmlspecialchars($collector['role_label']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Start Date</label>
                                    <input type="date" class="form-control" id="collectionReportStartDate">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">End Date</label>
                                    <input type="date" class="form-control" id="collectionReportEndDate">
                                </div>
                            </div>
                        </div>
                        <div class="alert alert-info mb-0">
                            <i class="bi bi-info-circle me-1"></i>
                            Select ALL or specific collector before print preview. This report is only for the branch: <strong><?= htmlspecialchars($branch_name) ?></strong>.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-success" id="viewCollectionReportPreviewBtn">
                            <i class="bi bi-printer me-1"></i> Print Preview
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Hidden simple print area for Collection Report -->
        <div class="print-only-area" id="collectionReportPrintable">
            <div id="collectionReportPreviewContent"></div>
        </div>

        <!-- Payment Details Modal -->
        <div class="modal fade" id="paymentDetailsModal" tabindex="-1"><div class="modal-dialog modal-md"><div class="modal-content"><div class="modal-header bg-info text-white"><h5 class="modal-title"><i class="bi bi-info-circle me-2"></i>Payment Details</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body payment-details-modal"><div class="payment-detail-card"><div class="row mb-2"><div class="col-5 payment-detail-label">Payment Method:</div><div class="col-7 payment-detail-value" id="detailPaymentMethod">-</div></div><div class="row mb-2" id="detailCheckDateRow" style="display: none;"><div class="col-5 payment-detail-label">Check Date:</div><div class="col-7 payment-detail-value" id="detailCheckDate">-</div></div><div class="row mb-2" id="detailBankNameRow" style="display: none;"><div class="col-5 payment-detail-label">Bank:</div><div class="col-7 payment-detail-value" id="detailBankName">-</div></div><div class="row mb-2" id="detailBankBranchRow" style="display: none;"><div class="col-5 payment-detail-label">Branch:</div><div class="col-7 payment-detail-value" id="detailBankBranch">-</div></div><div class="row mb-2" id="detailCheckNumberRow" style="display: none;"><div class="col-5 payment-detail-label">Check No.:</div><div class="col-7 payment-detail-value" id="detailCheckNumber">-</div></div><div class="row mb-2"><div class="col-5 payment-detail-label">Amount:</div><div class="col-7 payment-detail-value fw-bold text-success" id="detailAmount">-</div></div><div class="row mb-2" id="detailRefNoRow" style="display: none;"><div class="col-5 payment-detail-label">Ref. No.:</div><div class="col-7 payment-detail-value" id="detailRefNo">-</div></div><div class="row mb-2"><div class="col-5 payment-detail-label">Payment Date:</div><div class="col-7 payment-detail-value" id="detailPaymentDate">-</div></div><div class="row mb-2"><div class="col-5 payment-detail-label">Received By:</div><div class="col-7 payment-detail-value" id="detailReceivedBy">-</div></div></div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button></div></div></div></div>

      <!-- Mobile Bottom Navigation -->
    <div class="mobile-nav" id="mobileNav">
        <ul class="nav">
            <li class="nav-item">
                <a class="nav-link" href="motorpool_inventory.php">
                    <i class="bi bi-box-seam"></i>
                    <span>Inventory</span>
                </a>
            </li>

            <li class="nav-item dropdown-more">
                <a class="nav-link more-btn" href="#" onclick="toggleMobileDropdown(event, 'vendorMobileMenu')">
                    <i class="bi bi-building"></i>
                    <span>Vendor</span>
                </a>
                <div class="more-dropdown" id="vendorMobileMenu">
                    <a class="dropdown-item" href="enterbills.php"><i
                            class="bi bi-file-earmark-text"></i><span>Enter Bills</span></a>
                    <a class="dropdown-item" href="paybills.php"><i class="bi bi-currency-dollar"></i><span>Pay
                            Bills</span></a>
                    <a class="dropdown-item" href="vendors.php"><i class="bi bi-shop"></i><span>Vendor List</span></a>
                </div>
            </li>

            <li class="nav-item dropdown-more">
                <a class="nav-link more-btn active" href="#" onclick="toggleMobileDropdown(event, 'customerMobileMenu')">
                    <i class="bi bi-people-fill"></i>
                    <span>Customer</span>
                </a>
                <div class="more-dropdown" id="customerMobileMenu">
                    <a class="dropdown-item" href="orderproduct.php"><i class="bi bi-receipt"></i><span>Create
                            Invoice</span></a>
                    <a class="dropdown-item active" href="collections.php"><i class="bi bi-cash-stack"></i><span>Receive
                            Payment</span></a>
                    <a class="dropdown-item" href="customer_list.php"><i class="bi bi-person-badge"></i><span>Customer
                            List</span></a>
                </div>
            </li>

            <li class="nav-item dropdown-more">
                <a class="nav-link more-btn" href="#" onclick="toggleMobileDropdown(event, 'employeeMobileMenu')">
                    <i class="bi bi-briefcase"></i>
                    <span>Employees</span>
                </a>
                <div class="more-dropdown" id="employeeMobileMenu">
                    <a class="dropdown-item" href="employeelist.php"><i class="bi bi-receipt"></i><span>Employee
                            List</span></a>
                    <a class="dropdown-item" href="employee.php"><i class="bi bi-cash-stack"></i><span>Enter
                            Time</span></a>
                </div>
            </li>

            <li class="nav-item dropdown-more">
                <a class="nav-link more-btn" href="#" onclick="toggleMobileDropdown(event, 'bankingMobileMenu')">
                    <i class="bi bi-bank2"></i>
                    <span>Banking</span>
                </a>
                <div class="more-dropdown" id="bankingMobileMenu">
                    <a class="dropdown-item" href="deposit.php"><i class="bi bi-bank"></i><span>Record
                            Deposit</span></a>
                    <a class="dropdown-item" href="withdrawal.php"><i class="bi bi-journal-check"></i><span>Write
                            Checks</span></a>
                    <a class="dropdown-item" href="transferfunds.php"><i
                            class="bi bi-arrow-left-right"></i><span>Transfer Funds</span></a>
                </div>
            </li>


            <li class="nav-item dropdown-more">
                <a class="nav-link more-btn" href="#" onclick="toggleMobileDropdown(event, 'companyMobileMenu')">
                    <i class="bi bi-building"></i>
                    <span>Company</span>
                </a>
                <div class="more-dropdown" id="companyMobileMenu">
                    <a class="dropdown-item" href="chartofaccounts.php"><i class="bi bi-graph-up"></i><span>Chart of
                            Accounts</span></a>
                    <a class="dropdown-item" href="request_handler.php"><i class="bi bi-clipboard"></i><span>RIS
                            Monitoring</span></a>
                    <a class="dropdown-item" href="motorpool.php"><i class="bi bi-truck"></i><span>Vehicle
                            Profile</span></a>
                    <a class="dropdown-item" href="central_warehouse.php"><i class="bi bi-box-seam"></i><span>Central
                            Warehouse</span></a>
                </div>
            </li>

            <li class="nav-item dropdown-more">
                <a class="nav-link more-btn" href="#" onclick="toggleMobileDropdown(event, 'accountingMobileMenu')">
                    <i class="bi bi-graph-up"></i>
                    <span>Accounting</span>
                </a>
                <div class="more-dropdown" id="accountingMobileMenu">
                    <a class="dropdown-item" href="journal_entries.php"><i class="bi bi-journal"></i><span>Journal
                            Entries</span></a>
                    <a class="dropdown-item" href="batch_transaction.php"><i class="bi bi-collection"></i><span>Batch
                            Transactions</span></a>
                    <a class="dropdown-item" href="item_adjustment.php"><i class="bi bi-sliders"></i><span>Item
                            Adjustment</span></a>
                </div>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="it_request.php">
                    <i class="bi bi-headset"></i>
                    <span>IT Request</span>
                </a>
            </li>


            <li class="nav-item" id="profileMobileBtn">
                <a href="#" class="nav-link" data-bs-toggle="modal" data-bs-target="#profileModal">
                    <i class="bi bi-box-arrow-right"></i>
                    <span>Logout</span>
                </a>
            </li>
        </ul>
    </div>

    <!-- Mobile Profile Modal -->
    <div class="modal fade" id="profileModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-person-circle me-2"></i>User Profile</h5><button
                        type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body text-center">
                    <div class="user-avatar-large mb-3"><?php echo $user_initials; ?></div>
                    <h4 class="mb-1"><?php echo htmlspecialchars($user_name); ?></h4>
                    <p class="text-muted mb-3"><span class="badge bg-success"><?php echo ucfirst($user_role); ?></span>
                    </p><?php if (!$view_all_branches && $branch_id > 0): ?>
                        <div class="branch-info mb-3"><i
                                class="bi bi-building me-1"></i><span><?php echo htmlspecialchars($branch_name); ?></span>
                        </div><?php endif; ?>
                    <button class="btn btn-danger btn-lg w-100"
                        onclick="confirmLogout()"><i class="bi bi-box-arrow-right me-2"></i>Logout</button>
                </div>
            </div>
        </div>
    </div>

        <!-- Attachment Photo Modal -->
        <div class="modal fade" id="attachmentPhotoModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-lg">
                <div class="modal-content" style="border-radius:18px;overflow:hidden;">
                    <div class="modal-header" style="background:#052A47;color:#fff;">
                        <h5 class="modal-title" id="attachmentPhotoTitle"><i class="bi bi-image me-2"></i>Attachment Photo</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body text-center bg-light">
                        <img id="attachmentPhotoImg" src="" alt="Attachment" class="img-fluid rounded shadow-sm" style="max-height:70vh;object-fit:contain;">
                    </div>
                </div>
            </div>
        </div>

        <!-- Reject Return Ticket Modal -->
        <div class="modal fade" id="rejectReturnTicketModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content" style="border-radius:18px;overflow:hidden;">
                    <div class="modal-header bg-danger text-white">
                        <h5 class="modal-title"><i class="bi bi-x-circle me-2"></i>Reject Return Ticket</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" id="rejectReturnTicketId">
                        <label class="form-label fw-bold">Reason for rejection</label>
                        <textarea class="form-control" id="returnRejectionReason" rows="4" placeholder="Enter reason why this return ticket is rejected..."></textarea>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-danger" id="confirmRejectReturnBtn"><i class="bi bi-x-lg me-1"></i>Reject Return</button>
                    </div>
                </div>
            </div>
        </div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Pass online transfer sub accounts to JS
var registeredBanks = <?php echo $banks_json; ?>;

// Helper to generate ONLINE TRANSFER Bank/Wallet dropdown.
// Only registered sub accounts tagged as online_transfer are shown here.
function getOnlineTransferAccountSelectHtml(nameAttr, required = true) {
    var req = required ? 'required' : '';
    var html = '<select class="form-control" id="' + nameAttr + '" name="' + nameAttr + '" ' + req + '>';
    if (!registeredBanks || registeredBanks.length === 0) {
        html += '<option value="">-- No online transfer sub accounts --</option>';
    } else {
        html += '<option value="">-- Select Bank/Wallet --</option>';
        for (var i = 0; i < registeredBanks.length; i++) {
            var displayName = registeredBanks[i].display_name || registeredBanks[i].bank_name || '';
            html += '<option value="' + escapeHtml(displayName) + '" data-bank-id="' + escapeHtml(String(registeredBanks[i].bank_id || '')) + '" data-bank-branch="' + escapeHtml(registeredBanks[i].bank_branch || '') + '">' + escapeHtml(displayName) + '</option>';
        }
    }
    html += '</select>';
    return html;
}

// ========== GLOBAL VARIABLES ==========
let selectedPaymentMethod = 'cash';
// AMGC_COLLECTIONS_JOURNAL_EDIT_PATCH_V9
window.AMGC_COLLECTIONS_JOURNAL_EDIT = <?php echo json_encode($amgc_journal_collection_edit_data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
let currentInvoices = [];
let selectedInvoices = new Map();
let currentCustomerGroupFilter = 'all';

// ========== REMITTANCE FUNCTIONS ==========
async function approveRemittance(remittanceId) {
    const result = await Swal.fire({
        title: 'Approve Remittance?',
        text: 'This will record the remitted amount as payment. Partial payment will keep the invoice pending; full payment will mark it paid.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#28a745',
        confirmButtonText: 'Yes, Approve',
        cancelButtonText: 'Cancel'
    });
    
    if (result.isConfirmed) {
        Swal.fire({ title: 'Processing...', text: 'Please wait', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        
        try {
            const response = await fetch(window.location.href, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'approve_remittance',
                    remittance_id: remittanceId
                })
            });
            const text = await response.text();
            let data;
            try { data = JSON.parse(text); } catch (e) {
                console.error('Raw response:', text);
                Swal.close();
                Swal.fire('Error', 'Server returned invalid response.', 'error');
                return;
            }
            Swal.close();
            if (data.success) {
                Swal.fire('Success', data.message, 'success').then(() => {
                    location.reload();
                });
            } else {
                Swal.fire('Error', data.message || 'Approval failed', 'error');
            }
        } catch (error) {
            Swal.close();
            console.error(error);
            Swal.fire('Error', 'Approval failed: ' + error.message, 'error');
        }
    }
}

function rejectRemittance(remittanceId) {
    document.getElementById('rejectRemittanceId').value = remittanceId;
    document.getElementById('rejectionReason').value = '';
    new bootstrap.Modal(document.getElementById('rejectRemittanceModal')).show();
}

document.getElementById('confirmRejectBtn')?.addEventListener('click', async function() {
    const remittanceId = document.getElementById('rejectRemittanceId').value;
    const rejectionReason = document.getElementById('rejectionReason').value;
    
    if (!rejectionReason.trim()) {
        Swal.fire('Error', 'Please provide a reason for rejection', 'error');
        return;
    }
    
    Swal.fire({ title: 'Processing...', text: 'Please wait', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
    
    try {
        const response = await fetch(window.location.href, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'reject_remittance',
                remittance_id: remittanceId,
                rejection_reason: rejectionReason
            })
        });
        const text = await response.text();
        let data;
        try { data = JSON.parse(text); } catch (e) {
            console.error('Raw response:', text);
            Swal.close();
            Swal.fire('Error', 'Server returned invalid response.', 'error');
            return;
        }
        Swal.close();
        if (data.success) {
            bootstrap.Modal.getInstance(document.getElementById('rejectRemittanceModal'))?.hide();
            Swal.fire('Success', data.message, 'success').then(() => {
                location.reload();
            });
        } else {
            Swal.fire('Error', data.message || 'Rejection failed', 'error');
        }
    } catch (error) {
        Swal.close();
        console.error(error);
        Swal.fire('Error', 'Rejection failed: ' + error.message, 'error');
    }
});

function cleanupBootstrapModals(){
    document.querySelectorAll('.modal-backdrop').forEach(el => el.remove());
    if (!document.querySelector('.modal.show')) {
        document.body.classList.remove('modal-open');
        document.body.style.removeProperty('overflow');
        document.body.style.removeProperty('padding-right');
    }
}

function openAttachmentPhotoModal(photoPath, titleText) {
    if (!photoPath || String(photoPath).trim() === '') {
        Swal.fire('No Photo', 'No attachment photo uploaded for this record.', 'info');
        return;
    }
    Swal.close();
    cleanupBootstrapModals();
    const modalEl = document.getElementById('attachmentPhotoModal');
    document.getElementById('attachmentPhotoTitle').innerHTML = '<i class="bi bi-image me-2"></i>' + escapeHtml(titleText || 'Attachment Photo');
    document.getElementById('attachmentPhotoImg').src = photoPath;
    bootstrap.Modal.getOrCreateInstance(modalEl).show();
}

async function approveReturnTicket(returnId) {
    const result = await Swal.fire({
        title: 'Approve Return Ticket?',
        text: 'This will accept the returned invoice ticket and make the invoice available for reassignment.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#28a745',
        confirmButtonText: 'Yes, Approve',
        cancelButtonText: 'Cancel'
    });
    if (!result.isConfirmed) return;
    Swal.fire({ title: 'Processing...', text: 'Please wait', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
    try {
        const response = await fetch(window.location.href, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'approve_return_ticket', return_id: returnId })
        });
        const text = await response.text();
        let data;
        try { data = JSON.parse(text); } catch (e) { console.error('Raw response:', text); throw new Error('Invalid server response'); }
        Swal.close();
        if (data.success) Swal.fire('Success', data.message, 'success').then(() => location.reload());
        else Swal.fire('Error', data.message || 'Approval failed', 'error');
    } catch (error) {
        Swal.close();
        Swal.fire('Error', error.message || 'Approval failed', 'error');
    }
}

function rejectReturnTicket(returnId) {
    document.getElementById('rejectReturnTicketId').value = returnId;
    document.getElementById('returnRejectionReason').value = '';
    new bootstrap.Modal(document.getElementById('rejectReturnTicketModal')).show();
}

document.getElementById('confirmRejectReturnBtn')?.addEventListener('click', async function() {
    const returnId = document.getElementById('rejectReturnTicketId').value;
    const reason = document.getElementById('returnRejectionReason').value.trim();
    if (!reason) { Swal.fire('Error', 'Please provide a reason for rejection', 'error'); return; }
    bootstrap.Modal.getInstance(document.getElementById('rejectReturnTicketModal'))?.hide();
    cleanupBootstrapModals();
    Swal.fire({ title: 'Processing...', text: 'Please wait', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
    try {
        const response = await fetch(window.location.href, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'reject_return_ticket', return_id: returnId, rejection_reason: reason })
        });
        const text = await response.text();
        let data;
        try { data = JSON.parse(text); } catch (e) { console.error('Raw response:', text); throw new Error('Invalid server response'); }
        Swal.close();
        if (data.success) {
            bootstrap.Modal.getInstance(document.getElementById('rejectReturnTicketModal'))?.hide();
            Swal.fire('Success', data.message, 'success').then(() => location.reload());
        } else Swal.fire('Error', data.message || 'Rejection failed', 'error');
    } catch (error) {
        Swal.close();
        Swal.fire('Error', error.message || 'Rejection failed', 'error');
    }
});
$(document).ready(function() {
    $(document).on('click', '.remittance-row, .return-row', function(e) {
        if ($(e.target).closest('button, a, input, select, textarea').length) return;
        openAttachmentPhotoModal($(this).data('photo') || '', $(this).data('title') || 'Attachment Photo');
    });
    // Invoice filter uses a global search field across customer, group, invoice, SI, SO, status, and remarks.

    // Sidebar toggle
    const mobileMenuBtn = document.getElementById('mobileMenuBtn');
    if (mobileMenuBtn) mobileMenuBtn.addEventListener('click', function() { const sidebar = document.getElementById('sidebar'); if (sidebar) { if (window.innerWidth <= 992) { sidebar.classList.toggle('active'); if (!document.querySelector('.sidebar-overlay')) { const overlay = document.createElement('div'); overlay.className = 'sidebar-overlay'; document.body.appendChild(overlay); overlay.addEventListener('click', function() { sidebar.classList.remove('active'); overlay.classList.remove('active'); setTimeout(() => overlay.remove(), 300); }); setTimeout(() => overlay.classList.add('active'), 10); } } else toggleSidebar(); } });
    const desktopToggleBtn = document.getElementById('desktopToggleBtn'); if (desktopToggleBtn) desktopToggleBtn.addEventListener('click', function(e) { e.stopPropagation(); toggleSidebar(); });
    const sidebar = document.getElementById('sidebar'); if (sidebar && window.innerWidth > 992) { if (localStorage.getItem('sidebarCollapsed') === 'true') sidebar.classList.add('collapsed'); else sidebar.classList.remove('collapsed'); }
    setActiveSidebarItem();

    // Global invoice search and date filters
    const refreshInvoiceFilters = debounceInvoiceGlobalSearch(function() {
        const dateFrom = $('#dateFrom').val();
        const dateTo = $('#dateTo').val();
        const searchTerm = $('#invoiceGlobalSearch').val();
        clearAllSelections();
        loadAllPendingInvoices(dateFrom, dateTo, searchTerm);
    }, 350);
    $('#invoiceGlobalSearch').on('input', refreshInvoiceFilters);
    $('#dateFrom, #dateTo').on('change', function() {
        const dateFrom = $('#dateFrom').val();
        const dateTo = $('#dateTo').val();
        const searchTerm = $('#invoiceGlobalSearch').val();
        clearAllSelections();
        loadAllPendingInvoices(dateFrom, dateTo, searchTerm);
    });

    // Select All checkbox
    $('#selectAllCheckbox').on('change', function() {
        const isChecked = $(this).prop('checked');
        $('.row-checkbox').each(function() {
            $(this).prop('checked', isChecked);
            const invoiceId = $(this).data('invoice-id');
            const customerId = $(this).data('customer-id');
            const branchId = $(this).data('branch-id');
            if (isChecked) {
                if (!selectedInvoices.has(invoiceId)) {
                    selectedInvoices.set(invoiceId, { invoice_id: invoiceId, customer_id: customerId, branch_id: branchId });
                }
            } else {
                selectedInvoices.delete(invoiceId);
            }
        });
        updateBatchAssignBar();
    });

    // Row checkbox change
    $(document).on('change', '.row-checkbox', function() {
        const invoiceId = $(this).data('invoice-id');
        const customerId = $(this).data('customer-id');
        const branchId = $(this).data('branch-id');
        if ($(this).prop('checked')) {
            if (!selectedInvoices.has(invoiceId)) {
                selectedInvoices.set(invoiceId, { invoice_id: invoiceId, customer_id: customerId, branch_id: branchId });
            }
        } else {
            selectedInvoices.delete(invoiceId);
        }
        const totalCheckboxes = $('.row-checkbox').length;
        const checkedCheckboxes = $('.row-checkbox:checked').length;
        $('#selectAllCheckbox').prop('checked', totalCheckboxes > 0 && checkedCheckboxes === totalCheckboxes);
        updateBatchAssignBar();
    });

    // Clear selection button
    $('#clearSelectionBtn').on('click', function() {
        clearAllSelections();
    });

    // Batch assign button
    $('#batchAssignBtn').on('click', function() {
        if (selectedInvoices.size === 0) {
            Swal.fire('Info', 'No invoices selected', 'info');
            return;
        }
        
        const listContainer = $('#selectedInvoicesList');
        listContainer.empty();
        selectedInvoices.forEach((invoice, id) => {
            const row = $(`.row-checkbox[data-invoice-id="${id}"]`).closest('tr');
            const invoiceData = currentInvoices.find(inv => String(inv.invoice_id) === String(id)) || {};

            const invoiceNumber = row.find('td:eq(2)').text().trim() || invoiceData.invoice_number || ('Invoice #' + id);
            const amountText = row.find('td:eq(5)').text().trim() || ('₱' + formatMoney(invoiceData.total_amount || invoiceData.balance_amount || 0));
            const customerName = row.find('td:eq(1)').text().trim() || invoiceData.customer_name || 'Unknown Customer';

            listContainer.append(`
                <div class="selected-invoice-item d-flex justify-content-between align-items-center gap-2">
                    <div>
                        <i class="bi bi-receipt me-2"></i>
                        <strong>${escapeHtml(invoiceNumber)}</strong>
                        <span class="text-muted mx-1">-</span>
                        <span class="fw-bold text-success">${escapeHtml(amountText)}</span>
                        <span class="text-muted mx-1">-</span>
                        <span>${escapeHtml(customerName)}</span>
                    </div>
                </div>
            `);
        });
        
        $('#batchCollectorSelect').val('');
        $('#batchCollectionDate').val(new Date().toISOString().slice(0, 10));
        
        const batchModal = new bootstrap.Modal(document.getElementById('batchAssignModal'));
        batchModal.show();
    });

    // Confirm batch assign
    $('#confirmBatchAssignBtn').on('click', async function() {
        const collectorId = $('#batchCollectorSelect').val();
        const collectionDate = $('#batchCollectionDate').val();
        
        if (!collectorId) {
            Swal.fire('Error', 'Please select a collector', 'error');
            return;
        }
        
        const selectedInvoicesArray = Array.from(selectedInvoices.values());
        
        Swal.fire({ title: 'Assigning collectors...', text: 'Please wait', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        
        try {
            const response = await fetch(window.location.href, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'assign_multiple_collectors',
                    assigned_user_id: collectorId,
                    collector_id: collectorId,
                    collection_date: collectionDate,
                    selected_invoices: selectedInvoicesArray
                })
            });
            const text = await response.text();
            let data;
            try { data = JSON.parse(text); } catch (e) {
                console.error('Raw response:', text);
                Swal.close();
                Swal.fire('Error', 'Server returned invalid response.', 'error');
                return;
            }
            Swal.close();
            if (data.success) {
                Swal.fire('Success', data.message, 'success').then(() => {
                    bootstrap.Modal.getInstance(document.getElementById('batchAssignModal'))?.hide();
                    clearAllSelections();
                    const dateFrom = $('#dateFrom').val();
                    const dateTo = $('#dateTo').val();
                    const searchTerm = getInvoiceGlobalSearchTerm();
                    loadAllPendingInvoices(dateFrom, dateTo, searchTerm);
                });
            } else {
                Swal.fire('Error', data.message || 'Assignment failed', 'error');
            }
        } catch (error) {
            Swal.close();
            console.error(error);
            Swal.fire('Error', 'Assignment failed: ' + error.message, 'error');
        }
    });

    // Payment method selection
    document.querySelectorAll('.payment-method-option').forEach(opt => opt.addEventListener('click', function() { document.querySelectorAll('.payment-method-option').forEach(o => o.classList.remove('active')); this.classList.add('active'); selectedPaymentMethod = this.dataset.method; updatePaymentDetailsForm(); }));

    // Cash tendered events
    const cashTendered = document.getElementById('cashTendered');
    if (cashTendered) { 
        cashTendered.addEventListener('input', function(e) { 
            let value = this.value; 
            let cleanValue = value.replace(/[^\d.]/g, ''); 
            let parts = cleanValue.split('.'); 
            if (parts.length > 2) cleanValue = parts[0] + '.' + parts.slice(1).join(''); 
            if (parts.length === 2 && parts[1].length > 2) cleanValue = parts[0] + '.' + parts[1].substring(0, 2); 
            this.setAttribute('data-raw', cleanValue); 
            if (this.value !== cleanValue) this.value = cleanValue; 
            const amountDue = parseFloat(document.getElementById('payInvoiceAmount')?.value) || 0; 
            const tendered = parseFloat(cleanValue) || 0; 
            const change = tendered > amountDue ? tendered - amountDue : 0; 
            const changeDisplay = document.getElementById('cashChangeDisplay'); 
            if (changeDisplay) { 
                changeDisplay.innerText = '₱' + change.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); 
                changeDisplay.style.color = tendered <= 0 ? '#6c757d' : '#28a745'; 
            } 
        }); 
        cashTendered.addEventListener('blur', function() { 
            let rawValue = this.getAttribute('data-raw') || this.value.replace(/[^\d.]/g, ''); 
            if (rawValue && !isNaN(parseFloat(rawValue))) { 
                let num = parseFloat(rawValue); 
                this.value = num.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); 
                this.setAttribute('data-raw', num.toString()); 
            } else if (!rawValue || rawValue === '') { 
                this.value = ''; 
                this.setAttribute('data-raw', ''); 
                const changeDisplay = document.getElementById('cashChangeDisplay'); 
                if (changeDisplay) { 
                    changeDisplay.innerText = '₱0.00'; 
                    changeDisplay.style.color = '#6c757d'; 
                } 
            } 
        }); 
        cashTendered.addEventListener('focus', function() { 
            let rawValue = this.getAttribute('data-raw') || this.value.replace(/[^\d.]/g, ''); 
            if (rawValue && !isNaN(parseFloat(rawValue))) this.value = parseFloat(rawValue).toString(); 
            else this.value = ''; 
            this.setSelectionRange(this.value.length, this.value.length); 
        }); 
    }

    // Payment amount input
    const paymentAmount = document.getElementById('paymentAmount');
    if (paymentAmount) { 
        paymentAmount.addEventListener('input', function(e) { 
            let value = this.value; 
            let cleanValue = value.replace(/[^\d.]/g, ''); 
            let parts = cleanValue.split('.'); 
            if (parts.length > 2) cleanValue = parts[0] + '.' + parts.slice(1).join(''); 
            if (parts.length === 2 && parts[1].length > 2) cleanValue = parts[0] + '.' + parts[1].substring(0, 2); 
            this.setAttribute('data-raw', cleanValue); 
            if (this.value !== cleanValue) this.value = cleanValue; 
        }); 
        paymentAmount.addEventListener('blur', function() { 
            let rawValue = this.getAttribute('data-raw') || this.value.replace(/[^\d.]/g, ''); 
            if (rawValue && !isNaN(parseFloat(rawValue))) { 
                let num = parseFloat(rawValue); 
                this.value = num.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); 
                this.setAttribute('data-raw', num.toString()); 
            } else if (!rawValue || rawValue === '') { 
                this.value = ''; 
                this.setAttribute('data-raw', ''); 
            } 
        }); 
        paymentAmount.addEventListener('focus', function() { 
            let rawValue = this.getAttribute('data-raw') || this.value.replace(/[^\d.]/g, ''); 
            if (rawValue && !isNaN(parseFloat(rawValue))) this.value = parseFloat(rawValue).toString(); 
            else this.value = ''; 
            this.setSelectionRange(this.value.length, this.value.length); 
        }); 
    }

    document.getElementById('submitPaymentBtn').addEventListener('click', submitRemittance);
    const payAssignCollectorSelect = document.getElementById('payAssignCollectorSelect');
    if (payAssignCollectorSelect) {
        payAssignCollectorSelect.addEventListener('change', togglePaymentOrAssignMode);
    }

    $('input[name="bbDocumentType"]').on('change', updateBeginningBalanceDocumentType);
    $('#bbDocumentDigits').on('input', function(){
        const docType = $('input[name="bbDocumentType"]:checked').val() || 'so';
        if (docType === 'so') this.value = (this.value || '').replace(/\D/g, '').slice(0, 6);
        else this.value = (this.value || '').slice(0, 100);
    });
    updateBeginningBalanceDocumentType();
    loadAllPendingInvoices();
});

function clearAllSelections() {
    selectedInvoices.clear();
    $('.row-checkbox').prop('checked', false);
    $('#selectAllCheckbox').prop('checked', false);
    updateBatchAssignBar();
}

function updateBatchAssignBar() {
    const count = selectedInvoices.size;
    const batchBar = $('#batchAssignBar');
    if (count > 0) {
        batchBar.show();
        $('#selectedCount').text(count);
    } else {
        batchBar.hide();
    }
}

// ========== SIDEBAR FUNCTIONS ==========
function toggleSidebar() { const sidebar = document.getElementById('sidebar'); if (!sidebar) return; if (window.innerWidth > 992) { sidebar.classList.toggle('collapsed'); localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed')); if (!sidebar.classList.contains('collapsed')) setTimeout(() => expandActiveDropdownContainers(), 150); } else { sidebar.classList.toggle('active'); let overlay = document.querySelector('.sidebar-overlay'); if (!overlay && sidebar.classList.contains('active')) { overlay = document.createElement('div'); overlay.className = 'sidebar-overlay'; document.body.appendChild(overlay); overlay.addEventListener('click', function() { sidebar.classList.remove('active'); overlay.classList.remove('active'); setTimeout(() => overlay.remove(), 300); }); setTimeout(() => overlay.classList.add('active'), 10); } else if (overlay && !sidebar.classList.contains('active')) { overlay.classList.remove('active'); setTimeout(() => overlay.remove(), 300); } } }
function toggleSidebarDropdown(event, targetId) { event.preventDefault(); event.stopPropagation(); const target = document.getElementById(targetId); const btn = event.currentTarget; const arrow = btn ? btn.querySelector('.dropdown-arrow') : null; const sidebar = document.getElementById('sidebar'); if (!target || !sidebar) return; if (sidebar.classList.contains('collapsed') && window.innerWidth > 992) { sidebar.classList.remove('collapsed'); localStorage.setItem('sidebarCollapsed', 'false'); setTimeout(() => { document.querySelectorAll('.sidebar .collapse.show').forEach(collapse => { if (collapse.id !== targetId) collapse.classList.remove('show'); }); target.classList.add('show'); if (arrow) arrow.style.transform = 'translateY(-50%) rotate(180deg)'; }, 50); return; } if (target.classList.contains('show')) { target.classList.remove('show'); if (arrow) arrow.style.transform = 'translateY(-50%) rotate(0deg)'; } else { document.querySelectorAll('.sidebar .collapse.show').forEach(collapse => collapse.classList.remove('show')); document.querySelectorAll('.sidebar .dropdown-nav > .nav-link .dropdown-arrow').forEach(arrowIcon => { arrowIcon.style.transform = 'translateY(-50%) rotate(0deg)'; }); target.classList.add('show'); if (arrow) arrow.style.transform = 'translateY(-50%) rotate(180deg)'; } }
function toggleDropdown(event, dropdownId) { event.preventDefault(); event.stopPropagation(); const dropdown = document.getElementById(dropdownId); const btn = event.currentTarget; if (!dropdown || !btn) return; if (dropdown.classList.contains('show')) { dropdown.classList.remove('show'); btn.classList.remove('active'); } else { ['inventoryDropdownMenu', 'salesDropdownMenu', 'purchaseDropdownMenu', 'moreDropdownMenu'].forEach(id => { const d = document.getElementById(id); if (d && d !== dropdown) d.classList.remove('show'); }); document.querySelectorAll('.more-btn').forEach(b => b.classList.remove('active')); dropdown.classList.add('show'); btn.classList.add('active'); setTimeout(() => { const closeHandler = function(e) { if (!dropdown.contains(e.target) && !btn.contains(e.target)) { dropdown.classList.remove('show'); btn.classList.remove('active'); document.removeEventListener('click', closeHandler); } }; document.addEventListener('click', closeHandler); }, 100); } }
function setActiveSidebarItem() { const currentPage = window.location.pathname.split('/').pop(); const sidebarLinks = document.querySelectorAll('.sidebar .nav-link'); sidebarLinks.forEach(link => link.classList.remove('active')); sidebarLinks.forEach(link => { const href = link.getAttribute('href'); if (href === currentPage) { link.classList.add('active'); const collapseDiv = link.closest('.collapse'); if (collapseDiv) { collapseDiv.classList.add('show'); const parentNav = collapseDiv.closest('.dropdown-nav'); if (parentNav) { const parentLink = parentNav.querySelector(':scope > .nav-link'); if (parentLink) { const arrow = parentLink.querySelector('.dropdown-arrow'); if (arrow) arrow.style.transform = 'translateY(-50%) rotate(180deg)'; } } } } }); }
function expandActiveDropdownContainers() { document.querySelectorAll('.sidebar .dropdown-nav').forEach(dropdownNav => { const activeLink = dropdownNav.querySelector('.nav-link.active'); if (activeLink) { const collapseDiv = dropdownNav.querySelector('.collapse'); if (collapseDiv && !collapseDiv.classList.contains('show')) { collapseDiv.classList.add('show'); const parentLink = dropdownNav.querySelector(':scope > .nav-link'); if (parentLink) { const arrow = parentLink.querySelector('.dropdown-arrow'); if (arrow) arrow.style.transform = 'translateY(-50%) rotate(180deg)'; } } } }); }
function showProfileModal() { const modalElement = document.getElementById('profileModal'); if (modalElement) new bootstrap.Modal(modalElement).show(); }
function confirmLogout() { const modal = bootstrap.Modal.getInstance(document.getElementById('profileModal')); if (modal) modal.hide(); Swal.fire({ title: 'Are you sure?', text: 'You will be logged out', icon: 'question', showCancelButton: true, confirmButtonColor: '#07d826', confirmButtonText: 'Yes, logout', cancelButtonText: 'Cancel' }).then((result) => { if (result.isConfirmed) { localStorage.removeItem('sidebarCollapsed'); window.location.href = '../logout.php'; } }); }
function logout() { confirmLogout(); }
window.addEventListener('scroll', function() { ['inventoryDropdownMenu', 'salesDropdownMenu', 'purchaseDropdownMenu', 'moreDropdownMenu'].forEach(id => { const d = document.getElementById(id); if (d) d.classList.remove('show'); }); document.querySelectorAll('.sidebar .more-btn').forEach(btn => btn.classList.remove('active')); if (typeof window.setActiveMobileNav === 'function') window.setActiveMobileNav(); });


// ========== MOBILE BOTTOM NAV FIX ========== 
(function () {
    function mobileCurrentPage() {
        return ((window.location.pathname.split('/').pop() || '').split('?')[0] || '').trim();
    }

    function normalizeMobileHref(link) {
        const raw = (link && link.getAttribute('href')) ? link.getAttribute('href') : '';
        if (raw === '#' || raw.toLowerCase().startsWith('javascript:')) return '';
        return ((raw.split('/').pop() || '').split('?')[0] || '').trim();
    }

    function getMobileDropdownButton(menu) {
        if (!menu) return null;
        const wrapper = menu.closest('.mobile-nav .dropdown-more');
        if (wrapper) {
            return wrapper.querySelector(':scope > .nav-link.more-btn, :scope > .nav-link[onclick*="toggleMobileDropdown"]');
        }
        return document.querySelector('.mobile-nav .more-btn[onclick*="' + menu.id + '"], .mobile-nav .nav-link[onclick*="' + menu.id + '"]');
    }

    window.setActiveMobileNav = function () {
        const currentPage = mobileCurrentPage();

        document.querySelectorAll('.mobile-nav .nav-link, .mobile-nav .dropdown-item').forEach(function (link) {
            link.classList.remove('active');
            if (link.classList.contains('more-btn')) {
                link.setAttribute('aria-expanded', 'false');
            }
        });

        document.querySelectorAll('.mobile-nav .nav-link:not(.more-btn)').forEach(function (link) {
            const href = normalizeMobileHref(link);
            if (href && href === currentPage) {
                link.classList.add('active');
            }
        });

        document.querySelectorAll('.mobile-nav .more-dropdown .dropdown-item').forEach(function (item) {
            const href = normalizeMobileHref(item);
            if (href && href === currentPage) {
                item.classList.add('active');
                const menu = item.closest('.more-dropdown');
                const btn = getMobileDropdownButton(menu);
                if (btn) {
                    btn.classList.add('active');
                    btn.setAttribute('aria-expanded', menu && menu.classList.contains('show') ? 'true' : 'false');
                }
            }
        });
    };

    window.closeAllMobileDropdowns = function () {
        document.querySelectorAll('.mobile-nav .more-dropdown').forEach(function (dropdown) {
            dropdown.classList.remove('show');
        });
        window.setActiveMobileNav();
    };

    window.toggleMobileDropdown = function (event, dropdownId) {
        if (event) {
            event.preventDefault();
            event.stopPropagation();
        }

        const dropdown = document.getElementById(dropdownId);
        const btn = event && event.currentTarget ? event.currentTarget : getMobileDropdownButton(dropdown);
        if (!dropdown) return false;

        const alreadyOpen = dropdown.classList.contains('show');
        window.closeAllMobileDropdowns();

        if (!alreadyOpen) {
            dropdown.classList.add('show');
            if (btn) {
                btn.classList.add('active');
                btn.setAttribute('aria-expanded', 'true');
            }
        } else {
            window.setActiveMobileNav();
        }

        return false;
    };

    window.toggleDropdown = function (event, dropdownId) {
        return window.toggleMobileDropdown(event, dropdownId);
    };

    const originalShowProfileModal = window.showProfileModal;
    window.showProfileModal = function (event) {
        if (event) {
            event.preventDefault();
            event.stopPropagation();
        }

        if (typeof window.closeAllMobileDropdowns === 'function') {
            window.closeAllMobileDropdowns();
        }

        const modal = document.getElementById('profileModal');
        if (modal && window.bootstrap) {
            bootstrap.Modal.getOrCreateInstance(modal).show();
        } else if (typeof originalShowProfileModal === 'function') {
            originalShowProfileModal();
        }

        return false;
    };

    document.addEventListener('click', function (e) {
        const profileBtn = e.target.closest('#profileMobileBtn .nav-link, .mobile-nav [data-bs-target="#profileModal"]');
        if (profileBtn) {
            window.closeAllMobileDropdowns();
            return;
        }

        if (!e.target.closest('.mobile-nav')) {
            window.closeAllMobileDropdowns();
        }
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            window.closeAllMobileDropdowns();
        }
    });

    document.addEventListener('DOMContentLoaded', function () {
        window.setActiveMobileNav();

        document.querySelectorAll('.mobile-nav .dropdown-item').forEach(function (item) {
            item.addEventListener('click', function () {
                window.closeAllMobileDropdowns();
            });
        });
    });

    window.addEventListener('pageshow', window.setActiveMobileNav);
})();

// ========== CUSTOMER GROUP FILTER FUNCTIONS ==========
function normalizeCustomerGroupName(groupName) {
    const value = String(groupName || '').trim();
    return value || 'Ungrouped';
}

function formatGroupCurrency(value) {
    return '₱' + (parseFloat(value || 0)).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function getFilteredInvoicesByCustomerGroup(invoices) {
    const rows = Array.isArray(invoices) ? invoices : [];
    if (!currentCustomerGroupFilter || currentCustomerGroupFilter === 'all') return rows;
    return rows.filter(inv => normalizeCustomerGroupName(inv.customer_group) === currentCustomerGroupFilter);
}

function buildCustomerGroupTabs(invoices) {
    clearCustomerGroupTabsSkeleton();
    const tabsWrap = document.getElementById('customerGroupTabsWrap');
    const tabs = document.getElementById('customerGroupTabs');
    if (!tabsWrap || !tabs) return;

    const rows = Array.isArray(invoices) ? invoices : [];
    const groups = {};
    let totalAmount = 0;

    rows.forEach(inv => {
        const groupName = normalizeCustomerGroupName(inv.customer_group);
        const amount = parseFloat(inv.total_amount || 0) || 0;
        if (!groups[groupName]) groups[groupName] = { name: groupName, count: 0, total: 0 };
        groups[groupName].count += 1;
        groups[groupName].total += amount;
        totalAmount += amount;
    });

    let html = `
        <button type="button" class="customer-group-tab ${currentCustomerGroupFilter === 'all' ? 'active' : ''}" data-group="all">
            <i class="bi bi-people"></i>
            <span>All Groups</span>
            <span class="group-count">${rows.length}</span>
            <span class="group-total">${formatGroupCurrency(totalAmount)}</span>
        </button>
    `;

    Object.keys(groups).sort((a, b) => a.localeCompare(b)).forEach(groupName => {
        const group = groups[groupName];
        const active = currentCustomerGroupFilter === groupName ? 'active' : '';
        html += `
            <button type="button" class="customer-group-tab ${active}" data-group="${escapeHtml(groupName)}">
                <i class="bi bi-tag"></i>
                <span>${escapeHtml(groupName)}</span>
                <span class="group-count">${group.count}</span>
                <span class="group-total">${formatGroupCurrency(group.total)}</span>
            </button>
        `;
    });

    tabs.innerHTML = html;

    if (rows.length === 0) {
        tabsWrap.style.display = 'none';
    } else {
        tabsWrap.style.display = '';
    }
}

function renderInvoicesWithActiveCustomerGroup(mode = 'all') {
    const filteredInvoices = getFilteredInvoicesByCustomerGroup(currentInvoices);
    if (mode === 'customer') {
        renderCustomerInvoicesTable(filteredInvoices);
    } else {
        renderPendingInvoicesTable(filteredInvoices);
    }
}

document.addEventListener('click', function(e) {
    const tab = e.target.closest('.customer-group-tab');
    if (!tab) return;

    currentCustomerGroupFilter = tab.getAttribute('data-group') || 'all';
    document.querySelectorAll('.customer-group-tab').forEach(btn => btn.classList.remove('active'));
    tab.classList.add('active');

    clearAllSelections();
    const hasSpecificCustomer = !!($('#customerSelect').val());
    renderInvoicesWithActiveCustomerGroup(hasSpecificCustomer ? 'customer' : 'all');
});


function renderInvoiceTableSkeleton(rowCount = 6) {
    const tbody = document.getElementById('invoicesTableBody');
    if (!tbody) return;

    const widths = ['24px', '82%', '68%', '58%', '54%', '72%', '76px', '84%', '32px'];
    let html = '';
    for (let i = 0; i < rowCount; i++) {
        html += '<tr class="skeleton-row">';
        widths.forEach((width, index) => {
            const shape = index === 0 || index === 8
                ? `<span class="skeleton-circle" style="width:${index === 0 ? '18px' : '32px'};height:${index === 0 ? '18px' : '32px'};"></span>`
                : index === 6
                    ? '<span class="skeleton-pill"></span>'
                    : `<span class="skeleton-line" style="width:${width};"></span>`;
            html += `<td>${shape}</td>`;
        });
        html += '</tr>';
    }
    tbody.innerHTML = html;
}

function renderCustomerGroupTabsSkeleton(count = 4) {
    const tabsWrap = document.getElementById('customerGroupTabsWrap');
    const tabs = document.getElementById('customerGroupTabs');
    if (!tabsWrap || !tabs) return;
    tabsWrap.style.display = '';
    let html = '';
    for (let i = 0; i < count; i++) html += '<span class="skeleton-tab"></span>';
    tabs.classList.add('skeleton-tabs');
    tabs.innerHTML = html;
}

function clearCustomerGroupTabsSkeleton() {
    const tabs = document.getElementById('customerGroupTabs');
    if (tabs) tabs.classList.remove('skeleton-tabs');
}

function showCollectionsSkeletonLoading(showCredit = false) {
    clearAllSelections();
    renderCustomerGroupTabsSkeleton();
    renderInvoiceTableSkeleton();

    const summaryDiv = document.getElementById('creditSummary');
    if (summaryDiv) {
        if (showCredit) {
            summaryDiv.style.display = 'flex';
            summaryDiv.querySelectorAll('.credit-item').forEach(item => item.classList.add('skeleton-loading'));
        } else {
            summaryDiv.style.display = 'none';
            summaryDiv.querySelectorAll('.credit-item').forEach(item => item.classList.remove('skeleton-loading'));
        }
    }
}

function hideCollectionsSkeletonLoading() {
    clearCustomerGroupTabsSkeleton();
    const summaryDiv = document.getElementById('creditSummary');
    if (summaryDiv) summaryDiv.querySelectorAll('.credit-item').forEach(item => item.classList.remove('skeleton-loading'));
}

// ========== COLLECTIONS FUNCTIONS ==========
function debounceInvoiceGlobalSearch(callback, delay = 350) {
    let timer = null;
    return function(...args) {
        clearTimeout(timer);
        timer = setTimeout(() => callback.apply(this, args), delay);
    };
}

function getInvoiceGlobalSearchTerm() {
    const input = document.getElementById('invoiceGlobalSearch');
    return input ? input.value.trim() : '';
}

function loadAllPendingInvoices(dateFrom = '', dateTo = '', searchTerm = '') {
    showCollectionsSkeletonLoading(false);
    const formData = new FormData();
    formData.append('action', 'get_all_pending_invoices');
    if (dateFrom) formData.append('start_date', dateFrom);
    if (dateTo) formData.append('end_date', dateTo);
    const globalSearch = (searchTerm !== undefined && searchTerm !== null) ? String(searchTerm).trim() : getInvoiceGlobalSearchTerm();
    if (globalSearch) formData.append('search_query', globalSearch);

    fetch(window.location.href, { method: 'POST', body: formData })
        .then(async response => {
            const text = await response.text();
            try {
                return JSON.parse(text);
            } catch (e) {
                console.error('Invalid JSON response:', text.substring(0, 500));
                throw new Error('Invalid server response');
            }
        })
        .then(data => {
            hideCollectionsSkeletonLoading();
            if (data.success) {
                currentInvoices = data.invoices || [];
                currentCustomerGroupFilter = 'all';
                buildCustomerGroupTabs(currentInvoices);
                renderPendingInvoicesTable(getFilteredInvoicesByCustomerGroup(currentInvoices));
                const summaryDiv = document.getElementById('creditSummary');
                if (summaryDiv) summaryDiv.style.display = 'none';
                clearAllSelections();
            } else {
                Swal.fire('Error', data.message || 'Failed to load invoices', 'error');
            }
        })
        .catch(error => {
            hideCollectionsSkeletonLoading();
            console.error(error);
            Swal.fire('Error', error.message || 'Failed to load invoices', 'error');
        });
}
function loadSpecificCustomerInvoices(customerId, dateFrom = '', dateTo = '') {
    showCollectionsSkeletonLoading(true);
    const formData = new FormData();
    formData.append('action', 'get_all_invoices');
    formData.append('customer_id', customerId);
    if (dateFrom) formData.append('start_date', dateFrom);
    if (dateTo) formData.append('end_date', dateTo);

    fetch(window.location.href, { method: 'POST', body: formData })
        .then(async response => {
            const text = await response.text();
            try {
                return JSON.parse(text);
            } catch (e) {
                console.error('Invalid JSON response:', text.substring(0, 500));
                throw new Error('Invalid server response');
            }
        })
        .then(data => {
            hideCollectionsSkeletonLoading();
            if (data.success) {
                currentInvoices = data.invoices || [];
                currentCustomerGroupFilter = 'all';
                buildCustomerGroupTabs(currentInvoices);
                renderCustomerInvoicesTable(getFilteredInvoicesByCustomerGroup(currentInvoices));
                updateCreditSummary(data.credit_limit, data.credit_used);
                clearAllSelections();
            } else {
                Swal.fire('Error', data.message || 'Failed to load invoices', 'error');
            }
        })
        .catch(error => {
            hideCollectionsSkeletonLoading();
            console.error(error);
            Swal.fire('Error', error.message || 'Failed to load invoices', 'error');
        });
}
function updateCreditSummary(limit, used) { const summaryDiv = document.getElementById('creditSummary'); if (summaryDiv) { limit = parseFloat(limit) || 0; used = parseFloat(used) || 0; const available = limit - used; document.getElementById('creditLimit').innerHTML = '₱' + limit.toLocaleString(undefined, { minimumFractionDigits: 2 }); document.getElementById('outstandingBalance').innerHTML = '₱' + used.toLocaleString(undefined, { minimumFractionDigits: 2 }); document.getElementById('availableCredit').innerHTML = '₱' + available.toLocaleString(undefined, { minimumFractionDigits: 2 }); summaryDiv.style.display = 'flex'; } }

function renderAssignedCollector(inv) {
    if (inv && inv.assigned_to_name) {
        const role = inv.assigned_to_role === 'delivery' ? 'Driver' : (inv.assigned_to_role === 'sales' ? 'Sales Agent' : '');
        const date = inv.collection_date ? new Date(inv.collection_date).toLocaleDateString() : '';
        return `<span class="assigned-collector-badge"><i class="bi bi-person-check"></i>${escapeHtml(inv.assigned_to_name)}</span><span class="assigned-date-small">${escapeHtml(role + (date ? ' • ' + date : ''))}</span>`;
    }
    return '<span class="text-muted small"><i class="bi bi-dash-circle me-1"></i>Unassigned</span>';
}

function renderPendingInvoicesTable(invoices) { 
    const tbody = document.getElementById('invoicesTableBody'); 
    if (!tbody) return; 
    if (!invoices || invoices.length === 0) { 
        tbody.innerHTML = '<tr><td colspan="9" class="text-center text-muted">No pending invoices found</td></tr>'; 
        return; 
    } 
    let html = ''; 
    invoices.forEach(inv => { 
        const invoiceDate = inv.invoice_date ? new Date(inv.invoice_date).toLocaleDateString() : '-'; 
        const amountDue = parseFloat(inv.total_amount || 0).toLocaleString(undefined, { minimumFractionDigits: 2 }); 
        const statusClass = inv.status === 'overdue' ? 'badge-overdue' : 'badge-pending'; 
        const statusText = inv.status === 'overdue' ? 'Overdue' : 'Pending'; 
        const customerName = escapeHtml(inv.customer_name || 'Unknown'); 
        const assignedCell = renderAssignedCollector(inv);
        const actionButton = buildInvoiceActionButtons(inv); 
        const isChecked = selectedInvoices.has(inv.invoice_id);
        html += `<tr class="invoice-row" data-invoice-id="${inv.invoice_id}" data-customer-id="${inv.customer_id}" data-branch-id="${inv.branch_id || 0}" data-customer-group="${escapeHtml(normalizeCustomerGroupName(inv.customer_group))}">
            <td class="checkbox-column"><input type="checkbox" class="row-checkbox" data-invoice-id="${inv.invoice_id}" data-customer-id="${inv.customer_id}" data-branch-id="${inv.branch_id || 0}" ${isChecked ? 'checked' : ''}></td>
            <td><strong>${customerName}</strong></td>
            <td>${escapeHtml(inv.invoice_number || '')}</td>
            <td>${invoiceDate}</td>
            <td>${escapeHtml(inv.so_number || '-')}</td>
            <td class="text-end fw-bold">₱${amountDue}</td>
            <td><span class="${statusClass}">${statusText}</span></td>
            <td>${assignedCell}</td>
            <td>${actionButton}</td>
        </tr>`; 
    }); 
    tbody.innerHTML = html; 
    
    $('.row-checkbox').off('change').on('change', function() {
        const invoiceId = $(this).data('invoice-id');
        const customerId = $(this).data('customer-id');
        const branchId = $(this).data('branch-id');
        if ($(this).prop('checked')) {
            if (!selectedInvoices.has(invoiceId)) {
                selectedInvoices.set(invoiceId, { invoice_id: invoiceId, customer_id: customerId, branch_id: branchId });
            }
        } else {
            selectedInvoices.delete(invoiceId);
        }
        const totalCheckboxes = $('.row-checkbox').length;
        const checkedCheckboxes = $('.row-checkbox:checked').length;
        $('#selectAllCheckbox').prop('checked', totalCheckboxes > 0 && checkedCheckboxes === totalCheckboxes);
        updateBatchAssignBar();
    });
    
    $('.invoice-row').off('click').on('click', function(e) { 
        if ($(e.target).hasClass('btn-pay') || $(e.target).closest('.btn-pay').length || $(e.target).closest('.btn-edit-bb').length || $(e.target).hasClass('row-checkbox') || $(e.target).closest('.row-checkbox').length) return; 
        const invoiceId = $(this).data('invoice-id'); 
        const invoice = currentInvoices.find(inv => inv.invoice_id == invoiceId); 
        if (invoice) showInvoiceDetails(invoice); 
    }); 
}

function renderCustomerInvoicesTable(invoices) { 
    const tbody = document.getElementById('invoicesTableBody'); 
    if (!tbody) return; 
    if (!invoices || invoices.length === 0) { 
        tbody.innerHTML = '<tr><td colspan="9" class="text-center text-muted">No pending invoices found for this customer</td></tr>'; 
        return; 
    } 
    let html = ''; 
    invoices.forEach(inv => { 
        const invoiceDate = inv.invoice_date ? new Date(inv.invoice_date).toLocaleDateString() : '-'; 
        const amountDue = parseFloat(inv.total_amount || 0).toLocaleString(undefined, { minimumFractionDigits: 2 }); 
        const statusClass = inv.status === 'overdue' ? 'badge-overdue' : 'badge-pending'; 
        const statusText = inv.status === 'overdue' ? 'Overdue' : 'Pending'; 
        const assignedCell = renderAssignedCollector(inv);
        const actionButton = buildInvoiceActionButtons(inv); 
        const isChecked = selectedInvoices.has(inv.invoice_id);
        html += `<tr class="invoice-row" data-invoice-id="${inv.invoice_id}" data-customer-id="${inv.customer_id}" data-customer-group="${escapeHtml(normalizeCustomerGroupName(inv.customer_group))}">
            <td class="checkbox-column"><input type="checkbox" class="row-checkbox" data-invoice-id="${inv.invoice_id}" data-customer-id="${inv.customer_id}" ${isChecked ? 'checked' : ''}></td>
            <td>${escapeHtml(inv.customer_name || '-')}</td>
            <td>${escapeHtml(inv.invoice_number || '')}</td>
            <td>${invoiceDate}</td>
            <td>${escapeHtml(inv.so_number || '-')}</td>
            <td class="text-end fw-bold">₱${amountDue}</td>
            <td><span class="${statusClass}">${statusText}</span></td>
            <td>${assignedCell}</td>
            <td>${actionButton}</td>
        </tr>`; 
    }); 
    tbody.innerHTML = html; 
    
    $('.row-checkbox').off('change').on('change', function() {
        const invoiceId = $(this).data('invoice-id');
        const customerId = $(this).data('customer-id');
        if ($(this).prop('checked')) {
            if (!selectedInvoices.has(invoiceId)) {
                selectedInvoices.set(invoiceId, { invoice_id: invoiceId, customer_id: customerId, branch_id: 0 });
            }
        } else {
            selectedInvoices.delete(invoiceId);
        }
        const totalCheckboxes = $('.row-checkbox').length;
        const checkedCheckboxes = $('.row-checkbox:checked').length;
        $('#selectAllCheckbox').prop('checked', totalCheckboxes > 0 && checkedCheckboxes === totalCheckboxes);
        updateBatchAssignBar();
    });
    
    $('.invoice-row').off('click').on('click', function(e) { 
        if ($(e.target).hasClass('btn-pay') || $(e.target).closest('.btn-pay').length || $(e.target).closest('.btn-edit-bb').length || $(e.target).hasClass('row-checkbox') || $(e.target).closest('.row-checkbox').length) return; 
        const invoiceId = $(this).data('invoice-id'); 
        const invoice = currentInvoices.find(inv => inv.invoice_id == invoiceId); 
        if (invoice) showInvoiceDetails(invoice); 
    }); 
}



function isImageAttachment(fileName, fileType) {
    const type = String(fileType || '').toLowerCase();
    const name = String(fileName || '').toLowerCase();
    return type.startsWith('image/') || /\.(jpg|jpeg|png|gif|webp)$/i.test(name);
}

function buildInvoiceAttachmentsHtml(invoice) {
    const attachments = Array.isArray(invoice.attachments) ? invoice.attachments : [];
    if (!attachments.length) return '';

    const items = attachments.map((att, index) => {
        const filePath = att.file_path || '';
        const fileName = att.file_name || att.stored_name || ('Attachment ' + (index + 1));
        const safePath = escapeHtml(filePath);
        const safeName = escapeHtml(fileName);
        if (isImageAttachment(fileName, att.file_type)) {
            return `
                <button type="button" class="btn p-0 border-0 bg-transparent text-start me-2 mb-2" onclick="openAttachmentPhotoModal('${safePath}', '${safeName.replace(/'/g, '&#39;')}')">
                    <img src="${safePath}" alt="${safeName}" class="rounded border" style="width:92px;height:92px;object-fit:cover;">
                    <div class="small text-muted mt-1" style="max-width:92px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${safeName}</div>
                </button>`;
        }
        return `
            <a href="${safePath}" target="_blank" class="btn btn-sm btn-outline-secondary me-2 mb-2">
                <i class="bi bi-paperclip me-1"></i>${safeName}
            </a>`;
    }).join('');

    return `
        <div class="mt-3 pt-3 border-top">
            <h6 class="fw-bold mb-2"><i class="bi bi-paperclip me-1"></i>Attachments</h6>
            <div class="d-flex flex-wrap align-items-start">${items}</div>
        </div>`;
}

function showInvoiceDetails(invoice) {
    if (!invoice) { Swal.fire('Info', 'No invoice details available', 'info'); return; }
    const fmtMoney = (value) => '₱' + (parseFloat(value || 0)).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const fmtDate = (value) => value ? new Date(value).toLocaleDateString() : '-';
    const payment = invoice.payment || null;
    const paymentHtml = payment ? `
        <div class="mt-3 pt-3 border-top">
            <h6 class="fw-bold mb-2"><i class="bi bi-cash-stack me-1"></i>Latest Payment</h6>
            <div class="row g-2 small">
                <div class="col-md-6"><strong>Method:</strong> ${escapeHtml((payment.payment_method || '-').toUpperCase())}</div>
                <div class="col-md-6"><strong>Amount:</strong> ${fmtMoney(payment.amount)}</div>
                <div class="col-md-6"><strong>Date:</strong> ${payment.payment_date ? new Date(payment.payment_date).toLocaleString() : '-'}</div>
                <div class="col-md-6"><strong>Reference:</strong> ${escapeHtml(payment.reference_number || payment.check_number || '-')}</div>
            </div>
        </div>` : '';
    const siHtml = invoice.si_number ? `
        <div class="mt-3 pt-3 border-top">
            <h6 class="fw-bold mb-2"><i class="bi bi-receipt-cutoff me-1"></i>SI Details</h6>
            <div class="row g-2 small">
                <div class="col-md-6"><strong>SI Number:</strong> ${escapeHtml(invoice.si_number || '-')}</div>
                <div class="col-md-6"><strong>Registered Business Name:</strong> ${escapeHtml(invoice.registered_business_name || '-')}</div>
                <div class="col-md-6"><strong>TIN:</strong> ${escapeHtml(invoice.tin || '-')}</div>
                <div class="col-md-6"><strong>Address:</strong> ${escapeHtml(invoice.business_address || '-')}</div>
            </div>
        </div>` : '';
    const attachmentsHtml = buildInvoiceAttachmentsHtml(invoice);
    const canEditBeginningBalance = isBeginningBalanceInvoice(invoice);
    Swal.fire({
        title: 'Invoice Details',
        width: 760,
        html: `
            <div class="text-start">
                <div class="row g-2 small">
                    <div class="col-md-6"><strong>Customer:</strong> ${escapeHtml(invoice.customer_name || '-')}</div>
                    <div class="col-md-6"><strong>Status:</strong> ${escapeHtml(invoice.status || '-')}</div>
                    <div class="col-md-6"><strong>Invoice #:</strong> ${escapeHtml(invoice.invoice_number || '-')}</div>
                    <div class="col-md-6"><strong>SO #:</strong> ${escapeHtml(invoice.so_number || '-')}</div>
                    <div class="col-md-6"><strong>Invoice Date:</strong> ${fmtDate(invoice.invoice_date)}</div>
                    <div class="col-md-6"><strong>Date:</strong> ${fmtDate(invoice.due_date)}</div>
                    <div class="col-md-6"><strong>Original Amount:</strong> ${fmtMoney(invoice.original_total_amount || invoice.total_amount)}</div>
                    <div class="col-md-6"><strong>Amount Due:</strong> ${fmtMoney(invoice.total_amount)}</div>
                    <div class="col-md-6"><strong>Paid Amount:</strong> ${fmtMoney(invoice.paid_amount)}</div>
                    <div class="col-md-6"><strong>Assigned To:</strong> ${escapeHtml(invoice.assigned_to_name || 'Unassigned')}</div>
                    <div class="col-md-12"><strong>Remarks:</strong> ${escapeHtml(invoice.remarks || '-')}</div>
                </div>
                ${siHtml}
                ${attachmentsHtml}
                ${paymentHtml}
            </div>
        `,
        confirmButtonColor: '#2E7D32',
        confirmButtonText: 'OK',
        showDenyButton: canEditBeginningBalance,
        denyButtonText: '<i class="bi bi-pencil-square me-1"></i>Edit Record',
        denyButtonColor: '#2E7D32'
    }).then((result) => {
        if (result.isDenied && canEditBeginningBalance) {
            openEditBeginningBalanceRecord(invoice.invoice_id);
        }
    });
}

function showPaymentDetails(payment) { if (!payment) { Swal.fire('Info', 'No payment details available', 'info'); return; } document.getElementById('detailPaymentMethod').textContent = payment.payment_method ? payment.payment_method.toUpperCase() : '-'; document.getElementById('detailAmount').textContent = '₱' + parseFloat(payment.amount || 0).toLocaleString(undefined, { minimumFractionDigits: 2 }); document.getElementById('detailPaymentDate').textContent = payment.payment_date ? new Date(payment.payment_date).toLocaleString() : '-'; const receivedBy = payment.first_name ? payment.first_name + ' ' + (payment.last_name || '') : (payment.created_by || '-'); document.getElementById('detailReceivedBy').textContent = receivedBy; const detailCheckDateRow = document.getElementById('detailCheckDateRow'); const detailBankNameRow = document.getElementById('detailBankNameRow'); const detailBankBranchRow = document.getElementById('detailBankBranchRow'); const detailCheckNumberRow = document.getElementById('detailCheckNumberRow'); const detailRefNoRow = document.getElementById('detailRefNoRow'); if (payment.payment_method === 'check') { if (detailCheckDateRow) detailCheckDateRow.style.display = ''; if (detailBankNameRow) detailBankNameRow.style.display = ''; if (detailBankBranchRow) detailBankBranchRow.style.display = ''; if (detailCheckNumberRow) detailCheckNumberRow.style.display = ''; if (detailRefNoRow) detailRefNoRow.style.display = 'none'; document.getElementById('detailCheckDate').textContent = payment.check_date || '-'; document.getElementById('detailBankName').textContent = payment.bank_name || '-'; document.getElementById('detailBankBranch').textContent = payment.bank_branch || '-'; document.getElementById('detailCheckNumber').textContent = payment.check_number || '-'; } else if (payment.payment_method === 'online_transfer') { if (detailCheckDateRow) detailCheckDateRow.style.display = 'none'; if (detailBankNameRow) detailBankNameRow.style.display = ''; if (detailBankBranchRow) detailBankBranchRow.style.display = 'none'; if (detailCheckNumberRow) detailCheckNumberRow.style.display = 'none'; if (detailRefNoRow) detailRefNoRow.style.display = ''; document.getElementById('detailBankName').textContent = payment.bank_name || '-'; document.getElementById('detailRefNo').textContent = payment.reference_number || '-'; } else { if (detailCheckDateRow) detailCheckDateRow.style.display = 'none'; if (detailBankNameRow) detailBankNameRow.style.display = 'none'; if (detailBankBranchRow) detailBankBranchRow.style.display = 'none'; if (detailCheckNumberRow) detailCheckNumberRow.style.display = 'none'; if (detailRefNoRow) detailRefNoRow.style.display = 'none'; } new bootstrap.Modal(document.getElementById('paymentDetailsModal')).show(); }

function updatePaymentDetailsForm() {
    const container = document.getElementById('paymentDetailsContainer');
    const cashFields = document.getElementById('cashFields');
    const otherAmountFields = document.getElementById('otherAmountFields');
    const paymentAmountField = document.getElementById('paymentAmount');
    if (cashFields) cashFields.style.display = 'none';
    if (otherAmountFields) otherAmountFields.style.display = 'none';
    if (container) container.innerHTML = '';
    if (selectedPaymentMethod === 'cash') {
        if (cashFields) cashFields.style.display = 'block';
        if (paymentAmountField) { paymentAmountField.removeAttribute('required'); paymentAmountField.value = ''; }
        const cashTenderedInput = document.getElementById('cashTendered');
        if (cashTenderedInput) { cashTenderedInput.value = ''; cashTenderedInput.setAttribute('data-raw', ''); }
        const changeDisplay = document.getElementById('cashChangeDisplay');
        if (changeDisplay) { changeDisplay.innerText = '₱0.00'; changeDisplay.style.color = '#6c757d'; }
    } else if (selectedPaymentMethod === 'check') {
        if (otherAmountFields) otherAmountFields.style.display = 'block';
        if (container) {
            container.innerHTML = `<div class="payment-detail-group"><div class="row g-3">
                <div class="col-md-6"><label class="form-label">Check Date *</label><input type="date" class="form-control" id="checkDate" required></div>
                <div class="col-md-6"><label class="form-label">Check No. *</label><input type="text" class="form-control" id="checkNumber" required></div>
                <div class="col-md-12"><label class="form-label">Bank Name / Branch *</label><input type="text" class="form-control" id="bankNameBranch" placeholder="Type bank name / branch" required></div>
            </div></div>`;
        }
        if (paymentAmountField) {
            paymentAmountField.setAttribute('required', 'required');
            const payInvoiceAmount = document.getElementById('payInvoiceAmount');
            if (payInvoiceAmount) { const amount = parseFloat(payInvoiceAmount.value) || 0; paymentAmountField.value = amount.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); paymentAmountField.setAttribute('data-raw', amount.toString()); }
        }
    } else if (selectedPaymentMethod === 'online_transfer') {
        if (otherAmountFields) otherAmountFields.style.display = 'block';
        if (container) {
            var onlineTransferSelectHtml = getOnlineTransferAccountSelectHtml('bankWallet', true);
            container.innerHTML = `<div class="payment-detail-group"><div class="row g-3">
                <div class="col-md-6"><label class="form-label">Bank/Wallet *</label>${onlineTransferSelectHtml}</div>
                <div class="col-md-6"><label class="form-label">Reference No. *</label><input type="text" class="form-control" id="referenceNumber" required></div>
            </div></div>`;
        }
        if (paymentAmountField) {
            paymentAmountField.setAttribute('required', 'required');
            const payInvoiceAmount = document.getElementById('payInvoiceAmount');
            if (payInvoiceAmount) { const amount = parseFloat(payInvoiceAmount.value) || 0; paymentAmountField.value = amount.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); paymentAmountField.setAttribute('data-raw', amount.toString()); }
        }
    }
}

function togglePaymentOrAssignMode() {
    const collectorSelect = document.getElementById('payAssignCollectorSelect');
    const paymentFormSection = document.getElementById('paymentFormSection');
    const submitBtn = document.getElementById('submitPaymentBtn');

    if (!collectorSelect || !paymentFormSection || !submitBtn) return;

    if (collectorSelect.value) {
        paymentFormSection.style.display = 'none';
        submitBtn.classList.remove('btn-warning');
        submitBtn.classList.add('btn-success');
        submitBtn.innerHTML = '<i class="bi bi-person-check"></i> Assign Collector';
    } else {
        paymentFormSection.style.display = '';
        submitBtn.classList.remove('btn-success');
        submitBtn.classList.add('btn-warning');
        submitBtn.innerHTML = '<i class="bi bi-send"></i> Submit for Approval';
    }
}
async function submitRemittance() {
    const invoiceId = document.getElementById('payInvoiceId').value;
    const amountDue = parseFloat(document.getElementById('payInvoiceAmount').value);

    if (!invoiceId || isNaN(amountDue) || amountDue <= 0) {
        Swal.fire('Error', 'Invalid invoice data', 'error');
        return;
    }

    const collectorSelect = document.getElementById('payAssignCollectorSelect');

    // If Branch Admin selected a collector, this is ASSIGN ONLY.
    // Do not record payment/remittance here. The assigned Sales/Driver will collect and remit later.
    if (collectorSelect && collectorSelect.value) {
        const collectionDate = new Date().toISOString().slice(0, 10);
        const assignData = {
            action: 'assign_collector',
            invoice_id: invoiceId,
            assigned_user_id: collectorSelect.value,
            collector_id: collectorSelect.value,
            collection_date: collectionDate
        };

        Swal.fire({ title: 'Assigning collector...', text: 'Please wait', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

        try {
            const response = await fetch(window.location.href, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(assignData)
            });
            const text = await response.text();
            let data;
            try {
                data = JSON.parse(text);
            } catch (e) {
                console.error('Raw response:', text);
                Swal.close();
                Swal.fire('Error', 'Server returned invalid response.', 'error');
                return;
            }

            Swal.close();
            if (data.success) {
                Swal.fire('Success', data.message || 'Collector assigned successfully.', 'success').then(() => {
                    bootstrap.Modal.getInstance(document.getElementById('paymentModal'))?.hide();
                    const dateFrom = $('#dateFrom').val();
                    const dateTo = $('#dateTo').val();
                    const searchTerm = getInvoiceGlobalSearchTerm();
                    clearAllSelections();
                    loadAllPendingInvoices(dateFrom, dateTo, searchTerm);
                });
            } else {
                Swal.fire('Error', data.message || 'Failed to assign collector', 'error');
            }
        } catch (error) {
            Swal.close();
            console.error(error);
            Swal.fire('Error', 'Failed to assign collector: ' + error.message, 'error');
        }
        return;
    }

    let amount = 0;

    if (selectedPaymentMethod === 'cash') {
        const cashTenderedInput = document.getElementById('cashTendered');
        let rawValue = cashTenderedInput.getAttribute('data-raw');
        if (!rawValue) rawValue = cashTenderedInput.value.replace(/[^\d.]/g, '');
        const cashAmount = parseFloat(rawValue);

        if (isNaN(cashAmount) || cashAmount <= 0) {
            Swal.fire('Error', 'Please enter a valid cash payment amount', 'error');
            return;
        }
        if (cashAmount > amountDue) {
            Swal.fire('Error', 'Payment amount cannot be greater than the remaining balance', 'error');
            return;
        }
        amount = cashAmount;
    } else {
        const paymentAmountInput = document.getElementById('paymentAmount');
        let rawValue = paymentAmountInput.getAttribute('data-raw');
        if (!rawValue) rawValue = paymentAmountInput.value.replace(/[^\d.]/g, '');
        const paymentAmountValue = parseFloat(rawValue);

        if (isNaN(paymentAmountValue) || paymentAmountValue <= 0) {
            Swal.fire('Error', 'Please enter a valid payment amount', 'error');
            return;
        }
        if (paymentAmountValue > amountDue) {
            Swal.fire('Error', 'Payment amount cannot be greater than the remaining balance', 'error');
            return;
        }
        amount = paymentAmountValue;
    }

    let remittanceData = {
        action: (window.AMGC_COLLECTIONS_JOURNAL_EDIT ? 'update_journal_payment' : 'submit_remittance'),
        payment_id: (window.AMGC_COLLECTIONS_JOURNAL_EDIT ? window.AMGC_COLLECTIONS_JOURNAL_EDIT.payment_id : ''),
        remittances: [{
            invoice_id: invoiceId,
            customer_id: 0,
            payment_method: selectedPaymentMethod,
            amount: amount,
            collection_date: new Date().toISOString().slice(0, 19).replace('T', ' ')
        }]
    };

    const currentInvoice = (typeof currentInvoices !== 'undefined' && Array.isArray(currentInvoices)) ? currentInvoices.find(inv => inv.invoice_id == invoiceId) : null;
    if (currentInvoice) {
        remittanceData.remittances[0].customer_id = currentInvoice.customer_id;
    } else if (window.AMGC_COLLECTIONS_JOURNAL_EDIT) {
        remittanceData.remittances[0].customer_id = window.AMGC_COLLECTIONS_JOURNAL_EDIT.customer_id || 0;
    }

    if (selectedPaymentMethod === 'cash') {
        remittanceData.remittances[0].cash_tendered = null;
        remittanceData.remittances[0].cash_change = null;
    } else if (selectedPaymentMethod === 'check') {
        remittanceData.remittances[0].check_date = document.getElementById('checkDate').value;
        const bankNameBranch = document.getElementById('bankNameBranch')?.value || '';
        remittanceData.remittances[0].bank_name = bankNameBranch;
        remittanceData.remittances[0].bank_branch = bankNameBranch;
        remittanceData.remittances[0].check_number = document.getElementById('checkNumber').value;
        remittanceData.remittances[0].reference_number = document.getElementById('checkNumber').value;
        if (!remittanceData.remittances[0].check_date || !bankNameBranch || !remittanceData.remittances[0].check_number) {
            Swal.fire('Error', 'Please fill all check details', 'error');
            return;
        }
    } else if (selectedPaymentMethod === 'online_transfer') {
        remittanceData.remittances[0].reference_number = document.getElementById('referenceNumber').value;
        remittanceData.remittances[0].bank_name = document.getElementById('bankWallet').value;
        if (!remittanceData.remittances[0].reference_number || !remittanceData.remittances[0].bank_name) {
            Swal.fire('Error', 'Please fill all transfer details', 'error');
            return;
        }
    }

    Swal.fire({ title: 'Collecting payment...', text: 'Please wait', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

    try {
        const response = await fetch(window.location.href, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(remittanceData)
        });
        const text = await response.text();
        let data;
        try {
            data = JSON.parse(text);
        } catch (e) {
            console.error('Raw response:', text);
            Swal.close();
            Swal.fire('Error', 'Server returned invalid response.', 'error');
            return;
        }
        Swal.close();
        if (data.success) {
            Swal.fire({ icon: 'success', title: 'Collected!', text: data.message || 'Payment collected successfully.', confirmButtonColor: '#2E7D32' }).then(() => {
                bootstrap.Modal.getInstance(document.getElementById('paymentModal'))?.hide();
                const dateFrom = $('#dateFrom').val();
                const dateTo = $('#dateTo').val();
                const searchTerm = getInvoiceGlobalSearchTerm();
                clearAllSelections();
                loadAllPendingInvoices(dateFrom, dateTo, searchTerm);
                setTimeout(() => location.reload(), 1500);
            });
        } else {
            Swal.fire('Error', data.message || 'Failed to collect payment', 'error');
        }
    } catch (error) {
        Swal.close();
        console.error(error);
        Swal.fire('Error', 'Failed to collect payment: ' + error.message, 'error');
    }
}
function openPaymentModal(invoiceId, invoiceNumber, amountDue) {
    document.getElementById('payInvoiceId').value = invoiceId;
    document.getElementById('payInvoiceNumber').innerText = invoiceNumber;
    document.getElementById('payInvoiceAmount').value = amountDue;
    document.getElementById('payAmountDue').innerHTML = '₱' + Number(amountDue).toLocaleString(undefined, { minimumFractionDigits: 2 });
    selectedPaymentMethod = 'cash';
    const currentInvoice = (typeof currentInvoices !== 'undefined' && Array.isArray(currentInvoices)) ? currentInvoices.find(inv => String(inv.invoice_id) === String(invoiceId)) : null;
    const collectorSelect = document.getElementById('payAssignCollectorSelect');
    if (collectorSelect) collectorSelect.value = currentInvoice && currentInvoice.assigned_user_id ? currentInvoice.assigned_user_id : '';
    document.querySelectorAll('.payment-method-option').forEach(opt => { if (opt.dataset.method === 'cash') opt.classList.add('active'); else opt.classList.remove('active'); });
    updatePaymentDetailsForm();
    togglePaymentOrAssignMode();
    new bootstrap.Modal(document.getElementById('paymentModal')).show();
}


// AMGC_COLLECTIONS_JOURNAL_EDIT_PATCH_V9
function amgcSetCollectionPaymentAmount(input, value) {
    if (!input) return;
    const num = parseFloat(value || 0) || 0;
    input.value = num.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    input.setAttribute('data-raw', String(num));
    input.dispatchEvent(new Event('input', { bubbles: true }));
    input.dispatchEvent(new Event('change', { bubbles: true }));
}

function amgcSelectPaymentMethod(method) {
    selectedPaymentMethod = method || 'cash';
    document.querySelectorAll('.payment-method-option').forEach(opt => {
        opt.classList.toggle('active', opt.dataset.method === selectedPaymentMethod);
    });
    updatePaymentDetailsForm();
}

function amgcOpenJournalPaymentEditor() {
    const data = window.AMGC_COLLECTIONS_JOURNAL_EDIT;
    if (!data || !data.payment_id) return;

    document.getElementById('payInvoiceId').value = data.invoice_id || '';
    document.getElementById('payInvoiceNumber').innerText = data.invoice_number || ('Payment #' + data.payment_id);
    document.getElementById('payInvoiceAmount').value = parseFloat(data.amount_due || data.amount || 0) || 0;
    document.getElementById('payAmountDue').innerHTML = '₱' + Number(data.amount_due || data.amount || 0).toLocaleString(undefined, { minimumFractionDigits: 2 });

    const collectorSelect = document.getElementById('payAssignCollectorSelect');
    if (collectorSelect) {
        collectorSelect.value = '';
        collectorSelect.disabled = true;
        const section = collectorSelect.closest('.mb-3, .col-md-12, div');
        if (section) section.style.display = 'none';
    }

    amgcSelectPaymentMethod(data.payment_method || 'cash');

    setTimeout(() => {
        if (selectedPaymentMethod === 'cash') {
            amgcSetCollectionPaymentAmount(document.getElementById('cashTendered'), data.amount);
        } else {
            amgcSetCollectionPaymentAmount(document.getElementById('paymentAmount'), data.amount);
            if (selectedPaymentMethod === 'check') {
                const checkDate = document.getElementById('checkDate');
                const bankNameBranch = document.getElementById('bankNameBranch');
                const checkNumber = document.getElementById('checkNumber');
                if (checkDate) checkDate.value = data.check_date || '';
                if (bankNameBranch) bankNameBranch.value = data.bank_name || data.bank_branch || '';
                if (checkNumber) checkNumber.value = data.check_number || data.reference_number || '';
            }
            if (selectedPaymentMethod === 'online_transfer') {
                const referenceNumber = document.getElementById('referenceNumber');
                const bankWallet = document.getElementById('bankWallet');
                if (referenceNumber) referenceNumber.value = data.reference_number || '';
                if (bankWallet) bankWallet.value = data.bank_name || '';
            }
        }

        const submitBtn = document.getElementById('submitPaymentBtn');
        if (submitBtn) {
            submitBtn.classList.remove('btn-warning');
            submitBtn.classList.add('btn-success');
            submitBtn.innerHTML = '<i class="bi bi-save"></i> Update Payment';
            submitBtn.disabled = false;
        }

        const modalEl = document.getElementById('paymentModal');
        if (modalEl) bootstrap.Modal.getOrCreateInstance(modalEl, { backdrop: 'static' }).show();
    }, 80);
}

document.addEventListener('DOMContentLoaded', function() {
    if (window.AMGC_COLLECTIONS_JOURNAL_EDIT) {
        setTimeout(amgcOpenJournalPaymentEditor, 350);
    }
});

function escapeHtml(str) { if (!str) return ''; return String(str).replace(/[&<>]/g, function(m) { if (m === '&') return '&amp;'; if (m === '<') return '&lt;'; if (m === '>') return '&gt;'; return m; }); }
function escapeJsString(str) { if (!str) return ''; return String(str).replace(/\\/g, '\\\\').replace(/'/g, "\\'").replace(/"/g, '\\"').replace(/\r/g, '\\r').replace(/\n/g, '\\n'); }


function isBeginningBalanceInvoice(inv) {
    if (!inv) return false;
    const orderType = String(inv.order_type || inv.fulfillment_type || '').toLowerCase().trim();
    const remarks = String(inv.remarks || '').toLowerCase();
    return orderType === 'beginning_balance' || remarks.includes('beginning balance');
}

function isPickupInvoice(inv) {
    if (!inv) return false;
    const orderType = String(inv.order_type || inv.fulfillment_type || '').toLowerCase().trim();
    return ['pickup', 'pick_up', 'customer_pickup', 'store_pickup', 'branch_pickup', 'pick-up', 'for_pickup'].includes(orderType);
}

function isCollectibleInvoice(inv) {
    if (!inv) return false;
    const orderStatusLower = String(inv.order_status || '').toLowerCase().trim();
    return orderStatusLower === 'delivered' || isPickupInvoice(inv) || isBeginningBalanceInvoice(inv);
}

function buildInvoiceActionButtons(inv) {
    const orderStatusLower = String(inv.order_status || '').toLowerCase().trim();
    if (inv.assigned_user_id || inv.assigned_to_name) {
        return '<span class="text-muted small"><i class="bi bi-person-check me-1"></i>Assigned</span>';
    }
    if (isCollectibleInvoice(inv)) {
        const recordPaymentBtn = `<button class="btn-pay btn-action-icon" title="Record Payment" aria-label="Record Payment" onclick="event.stopPropagation(); openPaymentModal(${inv.invoice_id}, '${escapeJsString(inv.invoice_number)}', ${parseFloat(inv.total_amount || 0)})"><i class="bi bi-cash-stack"></i></button>`;
        return `<div class="invoice-action-buttons">${recordPaymentBtn}</div>`;
    }
    if (orderStatusLower === 'confirmed') return '<span class="text-muted small">Await Delivery</span>';
    return '<span class="text-muted small">Not Ready</span>';
}

// Filter section is always visible.
$('#invoiceFilterContent').removeClass('collapsed');

// Aging card click
$('#agingCardBtn').on('click', function() { $('#agingModal').modal('show'); });

// Aging Modal Functionality
$(document).ready(function() {
    $(document).on('click', '.remittance-row, .return-row', function(e) {
        if ($(e.target).closest('button, a, input, select, textarea').length) return;
        openAttachmentPhotoModal($(this).data('photo') || '', $(this).data('title') || 'Attachment Photo');
    });

    let agingInvoicesData = [];

    $('#agingModal').on('show.bs.modal', function() {
        fetchAgingInvoices();
    });

    $('#agingModal').on('hidden.bs.modal', function() {
        $('#agingMainView').show();
        $('#agingDetailView').hide();
        $('.aging-item.clickable').removeClass('active-aging-item');
        $('#detailInvoicesList').html('<div class="text-center text-muted py-4">Loading...</div>');
    });

    function normalizeDateOnly(dateValue) {
        if (!dateValue) return null;
        const date = new Date(dateValue);
        if (isNaN(date.getTime())) return null;
        date.setHours(0, 0, 0, 0);
        return date;
    }

    function getDaysOutstanding(inv) {
        const invoiceDate = normalizeDateOnly(inv.invoice_date);
        const today = new Date();
        today.setHours(0, 0, 0, 0);
        if (!invoiceDate || invoiceDate > today) return 0;
        return Math.floor((today - invoiceDate) / (1000 * 60 * 60 * 24));
    }

    function fetchAgingInvoices() {
        const formData = new FormData();
        formData.append('action', 'get_all_pending_invoices');

        fetch(window.location.href, { method: 'POST', body: formData })
            .then(async response => {
                const text = await response.text();
                try {
                    return JSON.parse(text);
                } catch (e) {
                    console.error('Invalid JSON response:', text.substring(0, 500));
                    throw new Error('Invalid server response');
                }
            })
            .then(data => {
                if (data.success && data.invoices) {
                    agingInvoicesData = data.invoices.filter(inv => parseFloat(inv.total_amount || 0) > 0);
                }
            })
            .catch(error => {
                console.error('Error fetching aging invoices:', error);
            });
    }

    $('.aging-item.clickable').on('click', function() {
        const minDays = parseInt($(this).data('min-days'), 10) || 0;
        const maxDays = parseInt($(this).data('max-days'), 10) || 999;
        const rangeText = $(this).find('.aging-range .range-badge').text().trim();

        $('.aging-item.clickable').removeClass('active-aging-item');
        $(this).addClass('active-aging-item');

        $('#detailViewTitle').text('Receivables (' + rangeText + ' outstanding)');

        const filteredInvoices = agingInvoicesData.filter(inv => {
            const daysOutstanding = getDaysOutstanding(inv);
            if (maxDays === 999) return daysOutstanding >= minDays;
            return daysOutstanding >= minDays && daysOutstanding <= maxDays;
        });

        renderDetailInvoices(filteredInvoices);

        $('#agingMainView').hide();
        $('#agingDetailView').show();

        const modalBody = $('#agingModal .modal-body');
        if (modalBody.length) modalBody.scrollTop(0);
    });

    $('#backToAgingBtn').on('click', function() {
        $('.aging-item.clickable').removeClass('active-aging-item');
        $('#agingMainView').show();
        $('#agingDetailView').hide();

        const modalBody = $('#agingModal .modal-body');
        if (modalBody.length) modalBody.scrollTop(0);
    });

    function renderDetailInvoices(invoices) {
        const container = $('#detailInvoicesList');
        if (!invoices || invoices.length === 0) {
            container.html('<div class="text-center text-muted py-4"><i class="bi bi-inbox fs-1 d-block mb-2"></i>No receivables in this range</div>');
            return;
        }

        let html = '';
        invoices.forEach(inv => {
            const dueDate = inv.due_date ? new Date(inv.due_date).toLocaleDateString() : '-';
            const invoiceDate = inv.invoice_date ? new Date(inv.invoice_date).toLocaleDateString() : '-';
            const daysOutstanding = getDaysOutstanding(inv);
            const isBeginningBalance = String(inv.remarks || '').toLowerCase().includes('beginning balance');

            let borderColor = '';
            let statusBadge = '';
            if (daysOutstanding <= 7) {
                borderColor = '#2dc937';
                statusBadge = '<span class="badge" style="background:#2dc937; color:white;">0-7 days</span>';
            } else if (daysOutstanding <= 14) {
                borderColor = '#99c140';
                statusBadge = '<span class="badge" style="background:#99c140; color:white;">8-14 days</span>';
            } else if (daysOutstanding <= 21) {
                borderColor = '#e7b416';
                statusBadge = '<span class="badge" style="background:#e7b416; color:white;">15-21 days</span>';
            } else if (daysOutstanding <= 28) {
                borderColor = '#db7b2b';
                statusBadge = '<span class="badge" style="background:#db7b2b; color:white;">22-28 days</span>';
            } else {
                borderColor = '#cc3232';
                statusBadge = '<span class="badge" style="background:#cc3232; color:white;">Beyond 28 days</span>';
            }

            const beginningBadge = isBeginningBalance
                ? '<span class="badge bg-success-subtle text-success border border-success">Beginning Balance</span>'
                : '';

            html += `
                <div class="invoice-detail-item" style="border-left-color: ${borderColor};">
                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                        <div class="flex-grow-1">
                            <div class="d-flex align-items-center gap-2 flex-wrap">
                                <span class="invoice-detail-customer">${escapeHtml(inv.customer_name || 'Unknown')}</span>
                                ${statusBadge}
                                ${beginningBadge}
                            </div>
                            <div class="invoice-detail-number mt-1">
                                <i class="bi bi-receipt me-1"></i>${escapeHtml(inv.invoice_number || '')}
                                ${inv.si_number ? '<span class="ms-2"><i class="bi bi-file-earmark-text me-1"></i>SI: ' + escapeHtml(inv.si_number) + '</span>' : ''}
                                ${inv.so_number ? '<span class="ms-2"><i class="bi bi-truck me-1"></i>SO: ' + escapeHtml(inv.so_number) + '</span>' : ''}
                            </div>
                            <div class="invoice-detail-date mt-1">
                                <i class="bi bi-calendar me-1"></i>Invoice: ${invoiceDate} | Due: ${dueDate} |
                                <strong>${daysOutstanding} day(s) outstanding</strong>
                            </div>
                        </div>
                        <div class="text-end">
                            <div class="invoice-detail-amount">₱${parseFloat(inv.total_amount || 0).toLocaleString(undefined, { minimumFractionDigits: 2 })}</div>
                        </div>
                    </div>
                </div>
            `;
        });

        container.html(html);
    }
});
document.addEventListener('DOMContentLoaded', function(){
    ['attachmentPhotoModal','rejectReturnTicketModal'].forEach(function(id){
        const modalEl = document.getElementById(id);
        if (modalEl) modalEl.addEventListener('hidden.bs.modal', function(){
            if (id === 'attachmentPhotoModal') { const img = document.getElementById('attachmentPhotoImg'); if (img) img.removeAttribute('src'); }
            cleanupBootstrapModals();
        });
    });
});





// Safe searchable dropdown for the invoice customer filter.
function getInvoiceCustomerFilterElements() {
    return {
        wrap: document.getElementById('invoiceCustomerDropdownWrap'),
        hidden: document.getElementById('customerSelect'),
        search: document.getElementById('invoiceCustomerSearch'),
        menu: document.getElementById('invoiceCustomerMenu'),
        empty: document.getElementById('invoiceCustomerEmpty')
    };
}

function showInvoiceCustomerFilterMenu() {
    const { menu } = getInvoiceCustomerFilterElements();
    if (menu) menu.classList.add('show');
}

function hideInvoiceCustomerFilterMenu() {
    const { menu } = getInvoiceCustomerFilterElements();
    if (menu) menu.classList.remove('show');
}

function filterInvoiceCustomerFilterItems() {
    const { search, menu, empty } = getInvoiceCustomerFilterElements();
    if (!search || !menu) return;

    const keyword = normalizeBeginningBalanceText(search.value);
    let visibleCount = 0;

    menu.querySelectorAll('.bb-customer-item').forEach(function(item) {
        const label = normalizeBeginningBalanceText(item.getAttribute('data-label') || item.textContent);
        const visible = !keyword || label.includes(keyword);
        item.style.display = visible ? '' : 'none';
        if (visible) visibleCount++;
    });

    if (empty) empty.style.display = visibleCount ? 'none' : 'block';
}

function setInvoiceCustomerFilter(value, label, triggerChange = true) {
    const { hidden, search, menu } = getInvoiceCustomerFilterElements();
    if (hidden) hidden.value = value ? String(value) : '';
    if (search) search.value = value ? (label || '') : '';
    if (menu) {
        menu.querySelectorAll('.bb-customer-item').forEach(function(item) {
            item.classList.toggle('active', String(item.getAttribute('data-value')) === String(value || ''));
        });
    }
    hideInvoiceCustomerFilterMenu();
    if (triggerChange && hidden) $(hidden).trigger('change');
}

function initInvoiceCustomerFilterDropdown() {
    const { wrap, hidden, search, menu } = getInvoiceCustomerFilterElements();
    if (!wrap || !hidden || !search || !menu) return;
    if (wrap.dataset.ready === '1') {
        filterInvoiceCustomerFilterItems();
        return;
    }
    wrap.dataset.ready = '1';

    search.addEventListener('focus', function() {
        filterInvoiceCustomerFilterItems();
        showInvoiceCustomerFilterMenu();
    });

    search.addEventListener('click', function() {
        filterInvoiceCustomerFilterItems();
        showInvoiceCustomerFilterMenu();
    });

    search.addEventListener('input', function() {
        hidden.value = '';
        filterInvoiceCustomerFilterItems();
        showInvoiceCustomerFilterMenu();
    });

    search.addEventListener('keydown', function(e) {
        const visibleItems = Array.from(menu.querySelectorAll('.bb-customer-item')).filter(item => item.style.display !== 'none');
        if (!visibleItems.length) return;
        let currentIndex = visibleItems.findIndex(item => item.classList.contains('active'));

        if (e.key === 'ArrowDown') {
            e.preventDefault();
            currentIndex = currentIndex < visibleItems.length - 1 ? currentIndex + 1 : 0;
            visibleItems.forEach(item => item.classList.remove('active'));
            visibleItems[currentIndex].classList.add('active');
            visibleItems[currentIndex].scrollIntoView({ block: 'nearest' });
            showInvoiceCustomerFilterMenu();
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            currentIndex = currentIndex > 0 ? currentIndex - 1 : visibleItems.length - 1;
            visibleItems.forEach(item => item.classList.remove('active'));
            visibleItems[currentIndex].classList.add('active');
            visibleItems[currentIndex].scrollIntoView({ block: 'nearest' });
            showInvoiceCustomerFilterMenu();
        } else if (e.key === 'Enter') {
            const selected = visibleItems[currentIndex >= 0 ? currentIndex : 0];
            if (selected) {
                e.preventDefault();
                setInvoiceCustomerFilter(selected.getAttribute('data-value'), selected.getAttribute('data-label') || selected.textContent.trim());
            }
        } else if (e.key === 'Escape') {
            hideInvoiceCustomerFilterMenu();
        }
    });

    menu.addEventListener('mousedown', function(e) {
        const item = e.target.closest('.bb-customer-item');
        if (!item) return;
        e.preventDefault();
        setInvoiceCustomerFilter(item.getAttribute('data-value'), item.getAttribute('data-label') || item.textContent.trim());
    });

    document.addEventListener('mousedown', function(e) {
        if (!wrap.contains(e.target)) hideInvoiceCustomerFilterMenu();
    });

    filterInvoiceCustomerFilterItems();
}

// Safe searchable dropdown for Beginning Balance customer.
// This avoids Semantic UI / Select2 conflict by using Select2 only when it is available,
// and falling back to the normal select field when the plugin is blocked or not loaded.
function getBeginningBalanceCustomerElements() {
    return {
        wrap: document.getElementById('bbCustomerDropdownWrap'),
        hidden: document.getElementById('bbCustomerId'),
        search: document.getElementById('bbCustomerSearch'),
        menu: document.getElementById('bbCustomerMenu'),
        empty: document.getElementById('bbCustomerEmpty')
    };
}

function normalizeBeginningBalanceText(value) {
    return String(value || '').toLowerCase().replace(/\s+/g, ' ').trim();
}

function showBeginningBalanceCustomerMenu() {
    const { menu } = getBeginningBalanceCustomerElements();
    if (menu) menu.classList.add('show');
}

function hideBeginningBalanceCustomerMenu() {
    const { menu } = getBeginningBalanceCustomerElements();
    if (menu) menu.classList.remove('show');
}

function filterBeginningBalanceCustomers() {
    const { search, menu, empty } = getBeginningBalanceCustomerElements();
    if (!search || !menu) return;

    const keyword = normalizeBeginningBalanceText(search.value);
    let visibleCount = 0;

    menu.querySelectorAll('.bb-customer-item').forEach(function(item) {
        const label = normalizeBeginningBalanceText(item.getAttribute('data-label') || item.textContent);
        const visible = !keyword || label.includes(keyword);
        item.style.display = visible ? '' : 'none';
        if (visible) visibleCount++;
    });

    if (empty) empty.style.display = visibleCount ? 'none' : 'block';
}

function setBeginningBalanceCustomer(value, label) {
    const { hidden, search, menu } = getBeginningBalanceCustomerElements();
    if (hidden) hidden.value = value ? String(value) : '';
    if (search) search.value = label || '';
    if (menu) {
        menu.querySelectorAll('.bb-customer-item').forEach(function(item) {
            item.classList.toggle('active', String(item.getAttribute('data-value')) === String(value));
        });
    }
    hideBeginningBalanceCustomerMenu();
}

function initBeginningBalanceCustomerDropdown() {
    const { wrap, hidden, search, menu } = getBeginningBalanceCustomerElements();
    if (!wrap || !hidden || !search || !menu) return;
    if (wrap.dataset.ready === '1') {
        filterBeginningBalanceCustomers();
        return;
    }
    wrap.dataset.ready = '1';

    search.addEventListener('focus', function() {
        filterBeginningBalanceCustomers();
        showBeginningBalanceCustomerMenu();
    });

    search.addEventListener('click', function() {
        filterBeginningBalanceCustomers();
        showBeginningBalanceCustomerMenu();
    });

    search.addEventListener('input', function() {
        hidden.value = '';
        filterBeginningBalanceCustomers();
        showBeginningBalanceCustomerMenu();
    });

    search.addEventListener('keydown', function(e) {
        const visibleItems = Array.from(menu.querySelectorAll('.bb-customer-item')).filter(item => item.style.display !== 'none');
        if (!visibleItems.length) return;
        let currentIndex = visibleItems.findIndex(item => item.classList.contains('active'));

        if (e.key === 'ArrowDown') {
            e.preventDefault();
            currentIndex = currentIndex < visibleItems.length - 1 ? currentIndex + 1 : 0;
            visibleItems.forEach(item => item.classList.remove('active'));
            visibleItems[currentIndex].classList.add('active');
            visibleItems[currentIndex].scrollIntoView({ block: 'nearest' });
            showBeginningBalanceCustomerMenu();
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            currentIndex = currentIndex > 0 ? currentIndex - 1 : visibleItems.length - 1;
            visibleItems.forEach(item => item.classList.remove('active'));
            visibleItems[currentIndex].classList.add('active');
            visibleItems[currentIndex].scrollIntoView({ block: 'nearest' });
            showBeginningBalanceCustomerMenu();
        } else if (e.key === 'Enter') {
            const selected = visibleItems[currentIndex >= 0 ? currentIndex : 0];
            if (selected) {
                e.preventDefault();
                setBeginningBalanceCustomer(selected.getAttribute('data-value'), selected.getAttribute('data-label') || selected.textContent.trim());
            }
        } else if (e.key === 'Escape') {
            hideBeginningBalanceCustomerMenu();
        }
    });

    menu.addEventListener('mousedown', function(e) {
        const item = e.target.closest('.bb-customer-item');
        if (!item) return;
        e.preventDefault();
        setBeginningBalanceCustomer(item.getAttribute('data-value'), item.getAttribute('data-label') || item.textContent.trim());
    });

    document.addEventListener('mousedown', function(e) {
        if (!wrap.contains(e.target)) hideBeginningBalanceCustomerMenu();
    });

    filterBeginningBalanceCustomers();
}

function clearBeginningBalanceDropdown() {
    const { hidden, search, menu } = getBeginningBalanceCustomerElements();
    if (hidden) hidden.value = '';
    if (search) search.value = '';
    if (menu) {
        menu.querySelectorAll('.bb-customer-item').forEach(function(item) {
            item.style.display = '';
            item.classList.remove('active');
        });
    }
    hideBeginningBalanceCustomerMenu();
}

// ========== BEGINNING BALANCE FUNCTIONS ==========
function beginningBalanceDatePart() {
    const dateValue = document.getElementById('bbInvoiceDate')?.value || new Date().toISOString().slice(0, 10);
    return dateValue.replace(/-/g, '');
}

function onlyFiveToSixDigitInput(input) {
    if (!input) return '';
    const digits = (input.value || '').replace(/\D/g, '').slice(0, 6);
    if (input.value !== digits) input.value = digits;
    return digits;
}

function updateBeginningBalanceNumbers() {
    const invoiceDigitsInput = document.getElementById('bbInvoiceDigits');
    const soDigitsInput = document.getElementById('bbSoDigits');
    const siDigitsInput = document.getElementById('bbSiDigits');
    const invoiceDigits = onlyFiveToSixDigitInput(invoiceDigitsInput);
    const soDigits = onlyFiveToSixDigitInput(soDigitsInput);
    const siDigits = onlyFiveToSixDigitInput(siDigitsInput);
    const datePart = beginningBalanceDatePart();
    const soPrefix = 'SO-' + datePart + '-';
    const invPrefix = 'INV-' + datePart + '-';
    const soPrefixEl = document.getElementById('bbSoPrefix');
    const invPrefixEl = document.getElementById('bbInvoicePrefix');
    const soEl = document.getElementById('bbGeneratedSo');
    const invEl = document.getElementById('bbGeneratedInvoice');
    const siEl = document.getElementById('bbGeneratedSi');
    if (soPrefixEl) soPrefixEl.textContent = soPrefix;
    if (invPrefixEl) invPrefixEl.textContent = invPrefix;
    if (soEl) soEl.value = soPrefix + soDigits;
    if (invEl) invEl.value = invPrefix + invoiceDigits;
    if (siEl) siEl.value = siDigits || '';
}

function resetBeginningBalanceForm() {
    beginningBalanceEditMode = false;
    beginningBalanceEditInvoiceId = '';
    $('#bbEditInvoiceId').val('');
    if (typeof setBeginningBalanceModalMode === 'function') setBeginningBalanceModalMode('add');
    const today = new Date().toISOString().slice(0, 10);
    clearBeginningBalanceDropdown();
    $('#bbDocTypeSo').prop('checked', true);
    $('#bbDocumentDigits').val('');
    $('#bbRegisteredBusinessName').val('');
    $('#bbTinNumber').val('');
    $('#bbBusinessAddress').val('');
    $('#bbInvoiceDigits').val('');
    $('#bbSoDigits').val('');
    $('#bbSiDigits').val('');
    $('#bbInvoiceDate').val(today);
    $('#bbDueDate').val(today);
    $('#bbAmount').val('');
    $('#bbRemarks').val('Beginning balance');
    resetBeginningBalanceAttachmentRows();
    updateBeginningBalanceDocumentType();
    updateBeginningBalanceNumbers();
}

function formatBeginningBalanceAmountInput() {
    const input = document.getElementById('bbAmount');
    if (!input) return;
    let value = input.value.replace(/,/g, '').replace(/[^\d.]/g, '');
    const parts = value.split('.');
    if (parts.length > 2) value = parts[0] + '.' + parts.slice(1).join('');
    const cleanParts = value.split('.');
    if (cleanParts[0]) cleanParts[0] = Number(cleanParts[0]).toLocaleString('en-US');
    input.value = cleanParts.join('.');
}
function createBeginningBalanceAttachmentRow(showRemove = true) {
    const row = document.createElement('div');
    row.className = 'bb-attachment-row border rounded p-2 bg-light';
    row.innerHTML = `
        <div class="input-group">
            <input type="file" class="form-control bb-attachment-input" name="attachments[]" accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.doc,.docx,.xls,.xlsx,.csv,.txt">
            <button type="button" class="btn btn-outline-danger bb-remove-attachment-btn" ${showRemove ? '' : 'style="display:none;"'}>
                <i class="bi bi-x-lg"></i>
            </button>
        </div>
        <div class="bb-attachment-preview mt-2"></div>
    `;
    const input = row.querySelector('.bb-attachment-input');
    if (input) input.addEventListener('change', function(){ updateBeginningBalanceAttachmentPreview(row, input); });
    const removeBtn = row.querySelector('.bb-remove-attachment-btn');
    if (removeBtn) {
        removeBtn.addEventListener('click', function(){
            row.remove();
            normalizeBeginningBalanceAttachmentButtons();
        });
    }
    return row;
}

function updateBeginningBalanceAttachmentPreview(row, input) {
    const preview = row ? row.querySelector('.bb-attachment-preview') : null;
    if (!preview) return;
    preview.innerHTML = '';
    const file = input && input.files && input.files[0] ? input.files[0] : null;
    if (!file) return;

    const fileName = escapeHtml(file.name || 'Attachment');
    const fileSize = file.size ? (file.size / 1024).toFixed(1) + ' KB' : '';
    const isImage = file.type && file.type.startsWith('image/');

    if (isImage) {
        const objectUrl = URL.createObjectURL(file);
        preview.innerHTML = `
            <button type="button" class="btn p-0 border-0 bg-transparent text-start bb-preview-image-btn" title="Click to view image">
                <img src="${objectUrl}" alt="${fileName}" class="rounded border" style="width:90px;height:90px;object-fit:cover;">
            </button>
            <div class="small mt-1 text-muted"><i class="bi bi-paperclip me-1"></i>${fileName}${fileSize ? ' (' + escapeHtml(fileSize) + ')' : ''}</div>
        `;
        const btn = preview.querySelector('.bb-preview-image-btn');
        if (btn) btn.addEventListener('click', function(){ openAttachmentPhotoModal(objectUrl, file.name || 'Attachment Photo'); });
    } else {
        preview.innerHTML = `
            <div class="d-flex align-items-center gap-2 small text-muted">
                <i class="bi bi-file-earmark-text fs-5"></i>
                <span>${fileName}${fileSize ? ' (' + escapeHtml(fileSize) + ')' : ''}</span>
            </div>
        `;
    }
}

function bindBeginningBalanceAttachmentPreviews() {
    document.querySelectorAll('#bbAttachmentsContainer .bb-attachment-row').forEach(row => {
        const input = row.querySelector('.bb-attachment-input');
        if (input && !input.dataset.previewBound) {
            input.dataset.previewBound = '1';
            input.addEventListener('change', function(){ updateBeginningBalanceAttachmentPreview(row, input); });
        }
    });
}

function normalizeBeginningBalanceAttachmentButtons() {
    const rows = document.querySelectorAll('#bbAttachmentsContainer .bb-attachment-row');
    rows.forEach((row, index) => {
        const removeBtn = row.querySelector('.bb-remove-attachment-btn');
        if (removeBtn) removeBtn.style.display = rows.length > 1 ? '' : 'none';
    });
}

function resetBeginningBalanceAttachmentRows() {
    const container = document.getElementById('bbAttachmentsContainer');
    if (!container) return;
    container.innerHTML = '';
    container.appendChild(createBeginningBalanceAttachmentRow(false));
    normalizeBeginningBalanceAttachmentButtons();
}

function addBeginningBalanceAttachmentRow() {
    const container = document.getElementById('bbAttachmentsContainer');
    if (!container) return;
    container.appendChild(createBeginningBalanceAttachmentRow(true));
    normalizeBeginningBalanceAttachmentButtons();
}

document.addEventListener('DOMContentLoaded', function(){
    bindBeginningBalanceAttachmentPreviews();
});



function updateBeginningBalanceDocumentType() {
    const docType = $('input[name="bbDocumentType"]:checked').val() || 'so';
    const today = new Date().toISOString().slice(0, 10).replace(/-/g, '');
    const label = document.getElementById('bbDocumentDigitsLabel');
    const prefix = document.getElementById('bbDocumentPrefix');
    const help = document.getElementById('bbDocumentDigitsHelp');
    const input = document.getElementById('bbDocumentDigits');
    const businessFields = document.getElementById('bbSiBusinessFields');
    if (businessFields) businessFields.style.display = docType === 'si' ? '' : 'none';
    ['bbRegisteredBusinessName', 'bbTinNumber', 'bbBusinessAddress'].forEach(function(fieldId) {
        const field = document.getElementById(fieldId);
        if (!field) return;
        if (docType === 'si') field.setAttribute('required', 'required');
        else field.removeAttribute('required');
    });
    if (label) label.innerHTML = docType === 'si' ? 'SI Number <span class="text-danger">*</span>' : 'SO Number <span class="text-danger">*</span>';
    if (prefix) {
        if (docType === 'si') {
            prefix.textContent = '';
            prefix.style.display = 'none';
        } else {
            prefix.textContent = 'SO-' + today + '-';
            prefix.style.display = '';
        }
    }
    if (help) help.textContent = docType === 'si' ? 'Required. Enter the exact SI Number.' : 'Required. Last 5 to 6 digits only.';
    if (input) {
        input.value = '';
        input.placeholder = docType === 'si' ? 'Enter SI Number' : '5 to 6 digits';
        input.removeAttribute('maxlength');
        input.setAttribute('inputmode', docType === 'si' ? 'text' : 'numeric');
        if (docType === 'so') input.setAttribute('maxlength', '6');
        input.setAttribute('required', 'required');
    }
}


function showLoading() {
    Swal.fire({ title: 'Processing...', text: 'Please wait', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
}

const branchId = <?php echo (int)$branch_id; ?>;
const viewAllBranches = <?php echo $view_all_branches ? 'true' : 'false'; ?>;
const customersBranchColumnExists = <?php echo $customers_branch_column_exists ? 'true' : 'false'; ?>;
const provincesByRegion = <?php echo json_encode($provinces ?? []); ?>;
const citiesByProvince = <?php echo json_encode($cities ?? []); ?>;
let cityCodeList = [];
let cityCodeCache = {};

function normalizeLocationName(value) {
    return String(value || '')
        .toLowerCase()
        .replace(/\s+/g, ' ')
        .replace(/ city$/i, '')
        .replace(/ municipality$/i, '')
        .replace(/city of\s+/g, '')
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

function updateAddressPreview() {
    const regionSelect = document.getElementById('addRegion');
    const provinceSelect = document.getElementById('addProvince');
    const citySelect = document.getElementById('addCity');
    const barangaySelect = document.querySelector('.barangay-select');
    const barangayInput = document.getElementById('barangayInput');
    const region = regionSelect ? (regionSelect.options[regionSelect.selectedIndex]?.text || '') : '';
    const province = provinceSelect ? (provinceSelect.value || '') : '';
    const city = citySelect ? (citySelect.value || '') : '';
    let barangay = '';
    if (barangaySelect && !barangaySelect.disabled && barangaySelect.value) barangay = barangaySelect.value;
    else if (barangayInput && barangayInput.value) barangay = barangayInput.value;
    const parts = [];
    if (barangay) parts.push(barangay);
    if (city) parts.push(city);
    if (province) parts.push(province);
    if (region && region !== 'Select Region') parts.push(region);
    const previewSpan = document.getElementById('fullAddressPreview');
    if (previewSpan) previewSpan.textContent = parts.join(', ') || 'Not yet specified';
}

function toggleCustomerGroupInput(mode) {
    const select = document.getElementById(mode + 'CustomerGroupSelect');
    const input = document.getElementById(mode + 'CustomerGroup');
    if (!select || !input) return;
    if (select.value === '__new__') {
        input.classList.remove('d-none');
        input.focus();
    } else {
        input.classList.add('d-none');
        input.value = select.value || '';
    }
}

function setCustomerGroupValue(mode, value) {
    const select = document.getElementById(mode + 'CustomerGroupSelect');
    const input = document.getElementById(mode + 'CustomerGroup');
    if (!select || !input) return;
    const option = Array.from(select.options).find(opt => opt.value === value);
    if (option) {
        select.value = value;
        input.classList.add('d-none');
        input.value = value;
    } else if (value) {
        select.value = '__new__';
        input.classList.remove('d-none');
        input.value = value;
    } else {
        select.value = '';
        input.classList.add('d-none');
        input.value = '';
    }
}

function getCustomerGroupValue(mode) {
    const select = document.getElementById(mode + 'CustomerGroupSelect');
    const input = document.getElementById(mode + 'CustomerGroup');
    if (!select) return input ? input.value.trim() : '';
    if (select.value === '__new__') return input ? input.value.trim() : '';
    return select.value.trim();
}

function populateBarangayManualInput() {
    const container = document.getElementById('barangayFieldContainer');
    const toggleBtn = document.getElementById('manualBarangayToggle');
    if (!container) return;
    container.innerHTML = '<input type="text" class="form-control" name="barangay" id="barangayInput" placeholder="Type barangay name">';
    const input = document.getElementById('barangayInput');
    if (input) input.addEventListener('input', updateAddressPreview);
    if (toggleBtn) toggleBtn.style.display = 'none';
}

function resetBarangaySelect() {
    const container = document.getElementById('barangayFieldContainer');
    const toggleBtn = document.getElementById('manualBarangayToggle');
    if (!container) return;
    container.innerHTML = '<select class="form-select barangay-select" name="barangay" disabled><option value="">Select City/Municipality first</option></select>';
    if (toggleBtn) toggleBtn.style.display = 'none';
}

function populateBarangaySelect(selectElem, barangays) {
    if (!selectElem || !barangays || !barangays.length) return false;
    selectElem.innerHTML = '<option value="">Select Barangay</option>';
    barangays.forEach(name => {
        const option = document.createElement('option');
        option.value = name;
        option.textContent = name;
        selectElem.appendChild(option);
    });
    selectElem.disabled = false;
    selectElem.onchange = updateAddressPreview;
    return true;
}

const localBarangaysByMunicipality = {
    'batangas|taal': ['Apacay','Balisong','Bihis','Bolbok','Buli','Butong','Carasuche','Cawit','Caysasay','Cubamba','Cultihan','Gahol','Halang','Iba','Ilog','Imamawo','Ipil','Luntal','Mahabang Lodlod','Niogan','Pansol','Poblacion 1','Poblacion 2','Poblacion 3','Poblacion 4','Poblacion 5','Poblacion 6','Poblacion 7','Poblacion 8','Poblacion 9','Poblacion 10','Poblacion 11','Poblacion 12','Poblacion 13','Poblacion 14','Pook','Seiran','Talisay','Tierra Alta','Tulo'],
    'batangas|lemery': ['Anak-Dagat','Arumahan','Ayao-iyao','Bagong Pook','Bagong Sikat','Balanga','Bukal','Cahilan I','Cahilan II','Dayapan','Dita','Gulod','Lucky','Maguihan','Mahabang Dahilig','Mahayahay','Maigsing Dahilig','Maligaya','Malinis','Masalisi','Mataas Na Bayan','Matingain I','Matingain II','Mayasang','Niugan','Nonong Casto','Palanas','Payapa Ibaba','Payapa Ilaya','District I','District II','District III','District IV','Rizal','Sambal Ibaba','Sambal Ilaya','San Isidro Ibaba','San Isidro Itaas','Sangalang','Talaga','Tubigan','Tubuan','Wawa Ibaba','Wawa Ilaya','Sinisian East','Sinisian West'],
    'batangas|lipa': ['Poblacion Barangay 1','Poblacion Barangay 2','Poblacion Barangay 3','Poblacion Barangay 4','Poblacion Barangay 5','Poblacion Barangay 6','Poblacion Barangay 7','Poblacion Barangay 8','Poblacion Barangay 9','Barangay 10','Barangay 11','Barangay 12','Adya','Anilao','Anilao-Labac','Antipolo del Norte','Antipolo del Sur','Bagong Pook','Balintawak','Banaybanay','Bolbok','Bugtong na Pulo','Bulacnin','Bulaklakan','Calamias','Cumba','Dagatan','Duhatan','Halang','Inosloban','Kayumanggi','Latag','Lodlod','Lumbang','Mabini','Malagonlong','Malitlit','Marauoy','Mataas na Lupa','Munting Pulo','Pagolingin Bata','Pagolingin East','Pagolingin West','Pangao','Pinagkawitan','Pinagtongulan','Plaridel','Quezon','Rizal','Sabang','Sampaguita','San Benito','San Carlos','San Celestino','San Francisco','San Guillermo','San Jose','San Lucas','San Salvador','San Sebastian','Santo Niño','Santo Toribio','Sapac','Sico','Talisay','Tambo','Tangob','Tanguay','Tibig','Tipacan']
};

function getLocalBarangaysForLocation(provinceName, cityName) {
    const key = `${normalizeLocationName(provinceName)}|${normalizeLocationName(cityName)}`;
    return localBarangaysByMunicipality[key] || null;
}

function convertToManualBarangay(message) {
    const container = document.getElementById('barangayFieldContainer');
    const toggleBtn = document.getElementById('manualBarangayToggle');
    if (!container) return;
    container.innerHTML = '<input type="text" class="form-control" name="barangay" id="barangayInput" placeholder="Enter Barangay name">' +
        '<small class="text-muted d-block mt-1">' + (message || 'Manual entry enabled.') + '</small>';
    const input = document.getElementById('barangayInput');
    if (input) input.addEventListener('input', updateAddressPreview);
    if (toggleBtn) toggleBtn.style.display = 'none';
}

function convertToSelectBarangay() {
    resetBarangaySelect();
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
    return matches.length === 1 ? matches[0].code : '';
}

function loadCityCodes() {
    fetch('https://psgc.gitlab.io/api/cities-municipalities.json')
        .then(response => response.json())
        .then(cities => {
            cityCodeCache = {};
            cityCodeList = [];
            cities.forEach(city => {
                const cityRecord = {
                    code: city.code,
                    name: city.name,
                    provinceName: city.provinceName || '',
                    regionName: city.regionName || '',
                    regionCode: city.regionCode || '',
                    nameKey: normalizeLocationName(city.name),
                    provinceKey: normalizeLocationName(city.provinceName || ''),
                    regionKey: normalizeLocationName(city.regionName || ''),
                    regionCodeKey: normalizeLocationName(city.regionCode || '')
                };
                cityCodeList.push(cityRecord);
                if (!cityCodeCache[cityRecord.nameKey]) cityCodeCache[cityRecord.nameKey] = [];
                cityCodeCache[cityRecord.nameKey].push(cityRecord);
                if (cityRecord.provinceKey) cityCodeCache[`${cityRecord.provinceKey}|${cityRecord.nameKey}`] = cityRecord.code;
                if (cityRecord.provinceKey && cityRecord.regionCodeKey) cityCodeCache[`${cityRecord.regionCodeKey}|${cityRecord.provinceKey}|${cityRecord.nameKey}`] = cityRecord.code;
            });
        }).catch(() => {});
}

function initAddLocationDropdowns() {
    const regionSelect = document.getElementById('addRegion');
    const provinceSelect = document.getElementById('addProvince');
    const citySelect = document.getElementById('addCity');
    const cityCodeInput = document.getElementById('cityCode');
    const toggleBtn = document.getElementById('manualBarangayToggle');
    if (!regionSelect || !provinceSelect || !citySelect) return;

    regionSelect.onchange = function() {
        const region = this.value;
        provinceSelect.innerHTML = '<option value="">Select Province</option>';
        citySelect.innerHTML = '<option value="">Select City/Municipality</option>';
        provinceSelect.disabled = !region;
        citySelect.disabled = true;
        if (cityCodeInput) cityCodeInput.value = '';
        resetBarangaySelect();
        if (region && provincesByRegion[region]) {
            provincesByRegion[region].forEach(province => {
                const option = document.createElement('option');
                option.value = province;
                option.textContent = province;
                provinceSelect.appendChild(option);
            });
        }
        updateAddressPreview();
    };

    provinceSelect.onchange = function() {
        const province = this.value;
        citySelect.innerHTML = '<option value="">Select City/Municipality</option>';
        citySelect.disabled = !province;
        if (cityCodeInput) cityCodeInput.value = '';
        resetBarangaySelect();
        if (province && citiesByProvince[province]) {
            citiesByProvince[province].forEach(city => {
                const option = document.createElement('option');
                option.value = city;
                option.textContent = city;
                citySelect.appendChild(option);
            });
        }
        updateAddressPreview();
    };

    citySelect.onchange = function() {
        const province = provinceSelect.value;
        const city = this.value;
        const selectedOption = this.options[this.selectedIndex];
        const optionCityCode = selectedOption && selectedOption.dataset ? (selectedOption.dataset.code || '') : '';
        const foundCityCode = optionCityCode || getCityCodeFromCache(city, province, regionSelect.value);
        if (cityCodeInput) cityCodeInput.value = foundCityCode;

        resetBarangaySelect();
        const barangaySelect = document.querySelector('.barangay-select');
        if (!barangaySelect) return;

        if (!city) {
            updateAddressPreview();
            return;
        }

        barangaySelect.innerHTML = '<option value="">Loading barangays...</option>';
        barangaySelect.disabled = true;
        if (toggleBtn) {
            toggleBtn.style.display = 'block';
            toggleBtn.onclick = function() {
                convertToManualBarangay('Manual entry mode - please type barangay name');
            };
        }

        const localBarangays = getLocalBarangaysForLocation(province, city);
        if (localBarangays && barangaySelect) {
            populateBarangaySelect(barangaySelect, localBarangays);
            if (toggleBtn) toggleBtn.style.display = 'block';
            updateAddressPreview();
            return;
        }

        if (!foundCityCode) {
            barangaySelect.innerHTML = '<option value="">No barangay data - use manual entry</option>';
            barangaySelect.disabled = true;
            updateAddressPreview();
            return;
        }

        fetch(`https://psgc.gitlab.io/api/cities-municipalities/${foundCityCode}/barangays.json`)
            .then(response => {
                if (!response.ok) throw new Error('Unable to load barangays');
                return response.json();
            })
            .then(barangays => {
                if (!barangays || !barangays.length) {
                    barangaySelect.innerHTML = '<option value="">No barangays found - use manual entry</option>';
                    barangaySelect.disabled = true;
                    if (toggleBtn) toggleBtn.style.display = 'block';
                    updateAddressPreview();
                    return;
                }
                const barangayNames = barangays.map(item => item.name || item).filter(Boolean).sort((a, b) => a.localeCompare(b));
                populateBarangaySelect(barangaySelect, barangayNames);
                if (toggleBtn) toggleBtn.style.display = 'block';
                updateAddressPreview();
            })
            .catch(() => {
                barangaySelect.innerHTML = '<option value="">Failed to load - use manual entry</option>';
                barangaySelect.disabled = true;
                if (toggleBtn) toggleBtn.style.display = 'block';
                updateAddressPreview();
            });
    };

    if (toggleBtn) toggleBtn.onclick = populateBarangayManualInput;
    [regionSelect, provinceSelect, citySelect].forEach(el => el.addEventListener('change', updateAddressPreview));
}

function refreshCustomerCode() {
    showLoading();
    const formData = new FormData();
    formData.append('action', 'generate_code');
    fetch(window.location.href, { method: 'POST', body: formData, credentials: 'same-origin' })
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

let reopenBeginningBalanceAfterCustomerModal = false;

function getOrCreateBootstrapModal(modalEl, options = {}) {
    if (!modalEl || typeof bootstrap === 'undefined' || !bootstrap.Modal) return null;
    return bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl, options);
}

function showBeginningBalanceModalAgain() {
    const beginningBalanceModalEl = document.getElementById('beginningBalanceModal');
    const addCustomerModalEl = document.getElementById('addCustomerModal');
    if (!beginningBalanceModalEl) return;

    if (typeof cleanupBootstrapModals === 'function') cleanupBootstrapModals();
    document.body.classList.add('modal-open');

    const activeAddCustomerModal = addCustomerModalEl && addCustomerModalEl.classList.contains('show');
    if (activeAddCustomerModal) return;

    const beginningBalanceModal = getOrCreateBootstrapModal(beginningBalanceModalEl, { backdrop: 'static' });
    if (beginningBalanceModal) {
        beginningBalanceModal.show();
        setTimeout(function () {
            if (typeof initBeginningBalanceCustomerDropdown === 'function') initBeginningBalanceCustomerDropdown();
        }, 150);
    }
}

function prepareAddCustomerFormForBeginningBalance() {
    const form = document.getElementById('addCustomerForm');
    if (form) form.reset();
    setCustomerGroupValue('add', '');
    document.getElementById('customerCodePreview').innerHTML = '<?php echo $preview_code; ?> <i class="bi bi-arrow-repeat refresh-code" onclick="refreshCustomerCode()" title="Generate new code"></i>';
    document.getElementById('customerCodeInput').value = '<?php echo $preview_code; ?>';
    const regionSelect = document.getElementById('addRegion');
    const provinceSelect = document.getElementById('addProvince');
    const citySelect = document.getElementById('addCity');
    if (regionSelect) regionSelect.value = '';
    if (provinceSelect) { provinceSelect.innerHTML = '<option value="">Select Province</option>'; provinceSelect.disabled = true; }
    if (citySelect) { citySelect.innerHTML = '<option value="">Select City/Municipality</option>'; citySelect.disabled = true; }
    resetBarangaySelect();
    initAddLocationDropdowns();
}

function showAddCustomerModal() {
    prepareAddCustomerFormForBeginningBalance();

    const beginningBalanceModalEl = document.getElementById('beginningBalanceModal');
    const addCustomerModalEl = document.getElementById('addCustomerModal');
    if (!addCustomerModalEl) return;

    const addCustomerModal = getOrCreateBootstrapModal(addCustomerModalEl, { backdrop: 'static' });
    reopenBeginningBalanceAfterCustomerModal = !!(beginningBalanceModalEl && beginningBalanceModalEl.classList.contains('show'));

    if (reopenBeginningBalanceAfterCustomerModal && beginningBalanceModalEl) {
        const beginningBalanceModal = getOrCreateBootstrapModal(beginningBalanceModalEl, { backdrop: 'static' });
        beginningBalanceModalEl.addEventListener('hidden.bs.modal', function openCustomerAfterBeginningHidden() {
            if (typeof cleanupBootstrapModals === 'function') cleanupBootstrapModals();
            if (addCustomerModal) addCustomerModal.show();
        }, { once: true });
        if (beginningBalanceModal) beginningBalanceModal.hide();
        return;
    }

    if (addCustomerModal) addCustomerModal.show();
}

function appendCustomerGroupOptionForCurrentBranch(groupName) {
    groupName = String(groupName || '').trim();
    if (!groupName) return;

    const select = document.getElementById('addCustomerGroupSelect');
    if (!select) return;

    const exists = Array.from(select.options).some(opt => String(opt.value).trim().toLowerCase() === groupName.toLowerCase());
    if (exists) return;

    const newOptionMarker = Array.from(select.options).find(opt => opt.value === '__new__');
    const option = document.createElement('option');
    option.value = groupName;
    option.textContent = groupName;
    select.insertBefore(option, newOptionMarker || null);
}

function appendNewBeginningBalanceCustomer(customer) {
    if (!customer || !customer.customer_id) return;
    const { menu } = getBeginningBalanceCustomerElements();
    if (!menu) return;

    const customerName = String(customer.customer_name || '').trim();
    const storeName = String(customer.store_name || '').trim();
    const customerCode = String(customer.customer_code || '').trim();
    const customerGroup = String(customer.customer_group || getCustomerGroupValue('add') || 'Ungrouped').trim() || 'Ungrouped';

    let baseLabel = customerName || 'Customer';
    if (storeName) baseLabel += ' - ' + storeName;
    if (customerCode) baseLabel += ' (' + customerCode + ')';

    const fullLabel = baseLabel + ' - ' + customerGroup;
    const value = String(customer.customer_id);

    let item = menu.querySelector('.bb-customer-item[data-value="' + CSS.escape(value) + '"]');
    if (!item) {
        item = document.createElement('div');
        item.className = 'bb-customer-item';
        item.setAttribute('data-value', value);
        const empty = document.getElementById('bbCustomerEmpty');
        menu.insertBefore(item, empty || null);
    }

    item.setAttribute('data-label', fullLabel);
    item.innerHTML = `
        <div class="bb-customer-main">
            <strong>${escapeHtml(baseLabel)}</strong>
            <span class="bb-customer-group-badge">${escapeHtml(customerGroup)}</span>
        </div>
    `;

    initBeginningBalanceCustomerDropdown();
    setBeginningBalanceCustomer(value, fullLabel);
}


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
    formData.append('city_code', document.getElementById('cityCode').value || '');
    const barangaySelect = document.querySelector('.barangay-select');
    const barangayInput = document.getElementById('barangayInput');
    let barangay = '';
    if (barangaySelect && !barangaySelect.disabled && barangaySelect.value) barangay = barangaySelect.value;
    else if (barangayInput && barangayInput.value) barangay = barangayInput.value;
    formData.append('barangay', barangay);
    formData.append('status', 'active');
    const addStoreImageInput = document.querySelector('#addCustomerForm input[name="store_image"]');
    if (addStoreImageInput && addStoreImageInput.files && addStoreImageInput.files[0]) {
        formData.append('store_image', addStoreImageInput.files[0]);
    }
    if (customersBranchColumnExists && !viewAllBranches && branchId > 0) formData.append('branch_id', branchId);

    fetch(window.location.href, { method: 'POST', body: formData, credentials: 'same-origin' })
        .then(async res => {
            const text = await res.text();
            try { return JSON.parse(text); } catch (e) { console.error('Invalid JSON response:', text.substring(0, 500)); throw new Error('Invalid server response'); }
        })
        .then(data => {
            Swal.close();
            if (data.success) {
                const modalEl = document.getElementById('addCustomerModal');
                const modal = bootstrap.Modal.getInstance(modalEl);
                const savedCustomer = data.customer || {
                    customer_id: data.customer_id,
                    customer_name: customerName,
                    store_name: document.getElementById('addStoreName').value,
                    customer_code: document.getElementById('customerCodeInput').value,
                    customer_group: getCustomerGroupValue('add') || 'Ungrouped'
                };
                appendCustomerGroupOptionForCurrentBranch(savedCustomer.customer_group || getCustomerGroupValue('add'));
                appendNewBeginningBalanceCustomer(savedCustomer);
                if (modal) modal.hide();
                Swal.fire({ icon: 'success', title: 'Success!', text: data.message || 'Customer added successfully', timer: 900, showConfirmButton: false }).then(function () {
                    if (reopenBeginningBalanceAfterCustomerModal) showBeginningBalanceModalAgain();
                });
            } else {
                Swal.fire('Error', data.message || 'Failed to add customer', 'error');
            }
        }).catch(error => { Swal.close(); Swal.fire('Error', error.message || 'Failed to add customer', 'error'); });
}

loadCityCodes();


let beginningBalanceEditMode = false;
let beginningBalanceEditInvoiceId = '';

function setBeginningBalanceModalMode(mode) {
    const isEdit = mode === 'edit';
    beginningBalanceEditMode = isEdit;
    const title = document.querySelector('#beginningBalanceModal .modal-title');
    const saveCloseBtn = document.getElementById('saveCloseBeginningBalanceBtn');
    const saveNewBtn = document.getElementById('saveBeginningBalanceBtn');
    const addCustomerBtn = document.getElementById('openBbAddCustomerBtn');
    const attachmentInputs = document.querySelectorAll('.bb-attachment-input');
    if (title) title.textContent = isEdit ? 'Edit Beginning Balance' : 'Add Beginning Balance';
    if (saveCloseBtn) saveCloseBtn.innerHTML = isEdit ? '<i class="bi bi-save me-1"></i> Update & Close' : '<i class="bi bi-save me-1"></i> Save & Close';
    if (saveNewBtn) saveNewBtn.style.display = isEdit ? 'none' : '';
    if (addCustomerBtn) addCustomerBtn.style.display = isEdit ? 'none' : '';
    attachmentInputs.forEach(input => {
        if (isEdit) input.removeAttribute('required');
        else input.setAttribute('required', 'required');
    });
}

function extractLastDigitsFromDocumentNumber(value) {
    const match = String(value || '').match(/(\d{5,6})$/);
    return match ? match[1] : '';
}

function openEditBeginningBalanceRecord(invoiceId) {
    const invoice = currentInvoices.find(inv => String(inv.invoice_id) === String(invoiceId));
    if (!invoice) { Swal.fire('Error', 'Record not found in the current table.', 'error'); return; }
    if (!isBeginningBalanceInvoice(invoice)) { Swal.fire('Not allowed', 'Only beginning balance records can be edited here.', 'warning'); return; }
    resetBeginningBalanceForm();
    setBeginningBalanceModalMode('edit');
    beginningBalanceEditInvoiceId = String(invoice.invoice_id || '');
    $('#bbEditInvoiceId').val(beginningBalanceEditInvoiceId);
    setBeginningBalanceCustomer(invoice.customer_id || '', (invoice.customer_name || '') + (invoice.customer_group ? ' - ' + invoice.customer_group : ''));
    const hasSi = String(invoice.si_number || '').trim() !== '';
    $('#bbDocTypeSi').prop('checked', hasSi);
    $('#bbDocTypeSo').prop('checked', !hasSi);
    updateBeginningBalanceDocumentType();
    if (hasSi) {
        $('#bbDocumentDigits').val(invoice.si_number || '');
        $('#bbRegisteredBusinessName').val(invoice.registered_business_name || '');
        $('#bbTinNumber').val(invoice.tin || '');
        $('#bbBusinessAddress').val(invoice.business_address || '');
    } else {
        $('#bbDocumentDigits').val(extractLastDigitsFromDocumentNumber(invoice.so_number || invoice.invoice_number || ''));
    }
    const recordDate = invoice.due_date || invoice.invoice_date || new Date().toISOString().slice(0, 10);
    $('#bbInvoiceDate').val(String(recordDate).slice(0, 10));
    $('#bbDueDate').val(String(recordDate).slice(0, 10));
    $('#bbAmount').val(Number(invoice.original_total_amount || invoice.total_amount || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }));
    $('#bbRemarks').val(invoice.remarks || 'Beginning balance');
    updateBeginningBalanceNumbers();
    const existingAttachments = Array.isArray(invoice.attachments) ? invoice.attachments : [];
    if (existingAttachments.length) {
        const firstPreview = document.querySelector('#bbAttachmentsContainer .bb-attachment-preview');
        if (firstPreview) firstPreview.innerHTML = '<div class="small text-muted mb-1">Existing attachment(s) will remain. Upload new file(s) only if you want to add more.</div>' + buildInvoiceAttachmentsHtml(invoice);
    }
    const modalEl = document.getElementById('beginningBalanceModal');
    const modal = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl, { backdrop: 'static' });
    modal.show();
}

function saveBeginningBalance(closeAfterSave = false) {
    const customerId = $('#bbCustomerId').val();
    const documentType = $('input[name="bbDocumentType"]:checked').val() || 'so';
    const documentNumber = ($('#bbDocumentDigits').val() || '').trim();
    const documentDigits = documentNumber.replace(/\D/g, '');
    const invoiceDigits = /^\d{5,6}$/.test(documentDigits) ? documentDigits : '';
    const soDigits = documentType === 'so' ? documentDigits : '';
    const siNumber = documentType === 'si' ? documentNumber : '';
    const registeredBusinessName = ($('#bbRegisteredBusinessName').val() || '').trim();
    const tinNumber = ($('#bbTinNumber').val() || '').trim();
    const businessAddress = ($('#bbBusinessAddress').val() || '').trim();
    const amount = ($('#bbAmount').val() || '').replace(/,/g, '');
    const invoiceDate = new Date().toISOString().slice(0, 10);
    const dueDate = $('#bbDueDate').val();
    const remarks = $('#bbRemarks').val() || '';
    const editInvoiceId = $('#bbEditInvoiceId').val() || beginningBalanceEditInvoiceId || '';
    const isEditMode = beginningBalanceEditMode && editInvoiceId;
    const attachmentInputs = document.querySelectorAll('.bb-attachment-input');

    if (!customerId) { Swal.fire('Required', 'Please select a customer.', 'warning'); return; }
    if (documentType === 'so' && !/^\d{5,6}$/.test(documentDigits)) { Swal.fire('Required', 'SO number must be 5 to 6 numbers only.', 'warning'); return; }
    if (documentType === 'si' && !siNumber) { Swal.fire('Required', 'Please enter SI Number.', 'warning'); return; }
    if (documentType === 'si' && !registeredBusinessName) { Swal.fire('Required', 'Please enter Registered Business Name.', 'warning'); return; }
    if (documentType === 'si' && !tinNumber) { Swal.fire('Required', 'Please enter TIN.', 'warning'); return; }
    if (documentType === 'si' && !businessAddress) { Swal.fire('Required', 'Please enter Address.', 'warning'); return; }
    if (!amount || parseFloat(amount) <= 0) { Swal.fire('Required', 'Please enter a valid beginning balance amount.', 'warning'); return; }

    const formData = new FormData();
    formData.append('action', isEditMode ? 'update_beginning_balance' : 'add_beginning_balance');
    if (isEditMode) formData.append('invoice_id', editInvoiceId);
    formData.append('customer_id', customerId);
    formData.append('document_type', documentType);
    formData.append('document_number', documentNumber);
    formData.append('document_digits', documentDigits);
    formData.append('invoice_digits', invoiceDigits);
    formData.append('so_digits', soDigits);
    formData.append('si_number', siNumber);
    formData.append('registered_business_name', documentType === 'si' ? registeredBusinessName : '');
    formData.append('tin', documentType === 'si' ? tinNumber : '');
    formData.append('business_address', documentType === 'si' ? businessAddress : '');
    formData.append('amount', amount);
    formData.append('invoice_date', invoiceDate);
    formData.append('due_date', dueDate);
    formData.append('remarks', remarks);
    attachmentInputs.forEach(input => {
        if (input.files && input.files.length > 0) {
            Array.from(input.files).forEach(file => formData.append('attachments[]', file));
        }
    });

    Swal.fire({ title: 'Saving...', text: 'Please wait', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

    fetch(window.location.href, {
        method: 'POST',
        body: formData,
        credentials: 'same-origin'
    }).then(async response => {
        const text = await response.text();
        try { return JSON.parse(text); } catch (e) { console.error('Invalid JSON response:', text.substring(0, 500)); throw new Error('Invalid server response'); }
    }).then(data => {
        Swal.close();
        if (data.success) {
            const successTitle = isEditMode ? 'Updated!' : 'Saved!';
            const successText = data.message || (isEditMode ? 'Beginning balance record updated.' : 'Beginning balance saved.');
            resetBeginningBalanceForm();

            Swal.fire({ icon: 'success', title: successTitle, text: successText, confirmButtonColor: '#2E7D32' }).then(() => {
                const dateFrom = $('#dateFrom').val();
                const dateTo = $('#dateTo').val();
                const searchTerm = getInvoiceGlobalSearchTerm();
                loadAllPendingInvoices(dateFrom, dateTo, searchTerm);

                if (closeAfterSave) {
                    const modalEl = document.getElementById('beginningBalanceModal');
                    if (modalEl) {
                        const modalInstance = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
                        modalInstance.hide();
                    }
                }
            });
        } else {
            Swal.fire('Error', data.message || 'Failed to save beginning balance.', 'error');
        }
    }).catch(error => {
        Swal.close();
        console.error(error);
        Swal.fire('Error', error.message || 'Failed to save beginning balance.', 'error');
    });
}

document.addEventListener('DOMContentLoaded', function(){
    const openBtn = document.getElementById('openBeginningBalanceBtn');
    if (openBtn) openBtn.addEventListener('click', function(){ resetBeginningBalanceForm();
        reopenBeginningBalanceAfterCustomerModal = false;
        resetBeginningBalanceForm();
        new bootstrap.Modal(document.getElementById('beginningBalanceModal')).show();
    });
    ['bbInvoiceDigits','bbSoDigits','bbSiDigits','bbInvoiceDate'].forEach(function(id){
        const el = document.getElementById(id);
        if (el) el.addEventListener('input', updateBeginningBalanceNumbers);
        if (el) el.addEventListener('change', updateBeginningBalanceNumbers);
    });
    const amountInput = document.getElementById('bbAmount');
    if (amountInput) amountInput.addEventListener('input', formatBeginningBalanceAmountInput);
    const addAttachmentBtn = document.getElementById('addBeginningBalanceAttachmentBtn');
    if (addAttachmentBtn) addAttachmentBtn.addEventListener('click', addBeginningBalanceAttachmentRow);
    normalizeBeginningBalanceAttachmentButtons();
    const saveBtn = document.getElementById('saveBeginningBalanceBtn');
    if (saveBtn) saveBtn.addEventListener('click', function() { saveBeginningBalance(false); });
    const saveCloseBtn = document.getElementById('saveCloseBeginningBalanceBtn');
    if (saveCloseBtn) saveCloseBtn.addEventListener('click', function() { saveBeginningBalance(true); });
    const openBbAddCustomerBtn = document.getElementById('openBbAddCustomerBtn');
    if (openBbAddCustomerBtn) openBbAddCustomerBtn.addEventListener('click', showAddCustomerModal);
    initBeginningBalanceCustomerDropdown();
    const beginningBalanceModalEl = document.getElementById('beginningBalanceModal');
    if (beginningBalanceModalEl) {
        beginningBalanceModalEl.addEventListener('shown.bs.modal', initBeginningBalanceCustomerDropdown);
    }
    const addCustomerModalEl = document.getElementById('addCustomerModal');
    if (addCustomerModalEl) {
        addCustomerModalEl.addEventListener('hidden.bs.modal', function () {
            if (typeof cleanupBootstrapModals === 'function') cleanupBootstrapModals();
            if (reopenBeginningBalanceAfterCustomerModal && !Swal.isVisible()) {
                showBeginningBalanceModalAgain();
            }
        });
    }
    initAddLocationDropdowns();
});

const collectionReportRows = <?= $collection_report_json ?: '[]' ?>;
const collectionReportTotals = <?= $collection_report_totals_json ?: '{}' ?>;

function formatReportCurrency(value) {
    return '₱' + Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function formatReportDate(value) {
    if (!value) return '-';
    const date = new Date(String(value).replace(' ', 'T'));
    if (isNaN(date.getTime())) return value;
    return date.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: '2-digit' });
}

function normalizeReportText(value) {
    return value ? String(value).replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase()) : '-';
}

function buildCollectionTransactionDetails(row) {
    const method = String(row.payment_method || '').toLowerCase();
    const details = [];

    if (method === 'check') {
        if (row.check_number) details.push('Check No.: ' + row.check_number);
        if (row.check_date) details.push('Check Date: ' + formatReportDate(row.check_date));
        if (row.bank_name) details.push('Bank: ' + row.bank_name);
        if (row.bank_branch) details.push('Branch: ' + row.bank_branch);
        if (row.notes) details.push('Notes: ' + row.notes);
        return details.length ? details.join(' | ') : '-';
    }

    if (method === 'online_transfer') {
        if (row.bank_name) details.push('Bank/Wallet: ' + row.bank_name);
        if (row.reference_number) details.push('Ref No.: ' + row.reference_number);
        if (row.notes) details.push('Notes: ' + row.notes);
        return details.length ? details.join(' | ') : '-';
    }

    if (row.notes) details.push('Notes: ' + row.notes);
    return details.length ? details.join(' | ') : '-';
}


function computeCollectionPaymentMethodTotals(rows) {
    return (rows || []).reduce((totals, row) => {
        const method = String(row.payment_method || '').toLowerCase();
        const amount = Number(row.amount || 0) || 0;
        if (method === 'cash') totals.cash += amount;
        else if (method === 'online_transfer') totals.online_transfer += amount;
        else if (method === 'check') totals.check += amount;
        return totals;
    }, { cash: 0, online_transfer: 0, check: 0 });
}

function getCollectionReportFilteredRows() {
    const type = document.getElementById('collectionReportTypeFilter')?.value || 'all';
    const collectorId = document.getElementById('collectionSpecificCollectorFilter')?.value || '';
    const startDate = document.getElementById('collectionReportStartDate')?.value || '';
    const endDate = document.getElementById('collectionReportEndDate')?.value || '';

    return collectionReportRows.filter(row => {
        const rowRole = row.role || '';
        const rowCollectorId = String(row.collector_user_id || '');
        const rowDate = row.collection_date ? String(row.collection_date).substring(0, 10) : '';

        if (type === 'branch_admin' && rowRole !== 'branch_admin') return false;
        if (type === 'sales' && rowRole !== 'sales') return false;
        if (type === 'delivery' && rowRole !== 'delivery') return false;
        if (type === 'specific' && collectorId && rowCollectorId !== String(collectorId)) return false;
        if (type === 'specific' && !collectorId) return false;
        if (startDate && rowDate && rowDate < startDate) return false;
        if (endDate && rowDate && rowDate > endDate) return false;

        return true;
    });
}

function getCollectionReportFilterTitle() {
    const typeSelect = document.getElementById('collectionReportTypeFilter');
    const type = typeSelect?.value || 'all';
    if (type === 'specific') {
        const collectorSelect = document.getElementById('collectionSpecificCollectorFilter');
        const selectedText = collectorSelect && collectorSelect.selectedIndex >= 0 ? collectorSelect.options[collectorSelect.selectedIndex].text : '';
        return selectedText || 'Specific Collector';
    }
    return typeSelect && typeSelect.selectedIndex >= 0 ? typeSelect.options[typeSelect.selectedIndex].text : 'ALL Collections';
}

function buildCollectionReportPreview() {
    const rows = getCollectionReportFilteredRows();
    const startDate = document.getElementById('collectionReportStartDate')?.value || '';
    const endDate = document.getElementById('collectionReportEndDate')?.value || '';
    const reportTitle = getCollectionReportFilterTitle();
    const totalAmount = rows.reduce((sum, row) => sum + Number(row.amount || 0), 0);
    const salesTotal = rows.filter(row => row.role === 'sales').reduce((sum, row) => sum + Number(row.amount || 0), 0);
    const driverTotal = rows.filter(row => row.role === 'delivery').reduce((sum, row) => sum + Number(row.amount || 0), 0);
    const adminTotal = rows.filter(row => row.role === 'branch_admin').reduce((sum, row) => sum + Number(row.amount || 0), 0);
    const methodTotals = computeCollectionPaymentMethodTotals(rows);
    const dateRangeText = startDate || endDate ? `${startDate || 'Beginning'} to ${endDate || 'Today'}` : 'All Dates';

    let html = `
        <div class="plain-report-header">
            <h4>A. MACALINDONG DEVELOPMENT CORP.</h4>
            <div class="report-title">Collection Report</div>
        </div>

        <table class="plain-report-meta">
            <tr>
                <td><strong>Branch:</strong> <?= htmlspecialchars($branch_name, ENT_QUOTES) ?></td>
                <td><strong>Filter:</strong> ${escapeHtml(reportTitle)}</td>
            </tr>
            <tr>
                <td><strong>Date Range:</strong> ${escapeHtml(dateRangeText)}</td>
                <td><strong>Printed Date:</strong> ${new Date().toLocaleString()}</td>
            </tr>
            <tr>
                <td><strong>Printed By:</strong> <?= htmlspecialchars($user_name, ENT_QUOTES) ?></td>
                <td><strong>Total Records:</strong> ${rows.length}</td>
            </tr>
        </table>

        <div class="plain-report-summary">
            <strong>Cash:</strong> ${formatReportCurrency(methodTotals.cash)} &nbsp; | &nbsp;
            <strong>Online Transfer:</strong> ${formatReportCurrency(methodTotals.online_transfer)} &nbsp; | &nbsp;
            <strong>Check:</strong> ${formatReportCurrency(methodTotals.check)}
        </div>

        <div class="plain-report-summary">
            <strong>Total Collection:</strong> ${formatReportCurrency(totalAmount)} &nbsp; | &nbsp;
            <strong>Branch Admin:</strong> ${formatReportCurrency(adminTotal)} &nbsp; | &nbsp;
            <strong>Sales Agents:</strong> ${formatReportCurrency(salesTotal)} &nbsp; | &nbsp;
            <strong>Drivers:</strong> ${formatReportCurrency(driverTotal)}
        </div>

        <table class="plain-report-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Invoice #</th>
                    <th>Customer</th>
                    <th>Collected By</th>
                    <th>Method</th>
                    <th>Reference</th>
                    <th>Transaction Details</th>
                    <th style="text-align:right;">Amount</th>
                </tr>
            </thead>
            <tbody>
    `;

    if (!rows.length) {
        html += `<tr><td colspan="8" style="text-align:center;padding:14px;">No collection records found for selected filter.</td></tr>`;
    } else {
        rows.forEach(row => {
            html += `
                <tr>
                    <td>${escapeHtml(formatReportDate(row.collection_date))}</td>
                    <td>${escapeHtml(row.invoice_number || '-')}</td>
                    <td>${escapeHtml(row.customer_name || '-')}</td>
                    <td>${escapeHtml(row.collector_name || '-')}</td>
                    <td>${escapeHtml(normalizeReportText(row.payment_method))}</td>
                    <td>${escapeHtml(row.reference_number || row.check_number || '-')}</td>
                    <td>${escapeHtml(buildCollectionTransactionDetails(row))}</td>
                    <td style="text-align:right;">${formatReportCurrency(row.amount)}</td>
                </tr>
            `;
        });
    }

    html += `
            </tbody>
        </table>

        <table class="plain-report-table" style="margin-top:10px; page-break-inside:avoid; break-inside:avoid;">
            <tbody>
                <tr>
                    <th style="text-align:right;">TOTAL</th>
                    <th style="width:160px;text-align:right;white-space:nowrap;">${formatReportCurrency(totalAmount)}</th>
                </tr>
            </tbody>
        </table>
    `;

    document.getElementById('collectionReportPreviewContent').innerHTML = html;
}

$(document).ready(function() {
    $('#openCollectionReportFilterBtn').on('click', function() {
        $('#collectionReportFilterModal').modal('show');
    });

    $('#collectionReportTypeFilter').on('change', function() {
        const isSpecific = $(this).val() === 'specific';
        $('#collectionSpecificCollectorWrap').toggle(isSpecific);
        if (!isSpecific) $('#collectionSpecificCollectorFilter').val('');
    });

    $('#viewCollectionReportPreviewBtn').on('click', function() {
        const reportType = $('#collectionReportTypeFilter').val();
        if (reportType === 'specific' && !$('#collectionSpecificCollectorFilter').val()) {
            Swal.fire('Required', 'Please select a specific Sales Agent, Driver, or Branch Admin.', 'warning');
            return;
        }
        buildCollectionReportPreview();
        $('#collectionReportFilterModal').modal('hide');
        setTimeout(function() {
            window.print();
        }, 450);
    });
});
// Function to approve ALL remittances
async function approveAllRemittances() {
    const remittanceIds = <?php echo json_encode(array_column($pending_remittances, 'remittance_id')); ?>;
    
    if (remittanceIds.length === 0) {
        Swal.fire('Info', 'No pending remittances to approve', 'info');
        return;
    }
    
    const totalAmount = <?php echo array_sum(array_column($pending_remittances, 'amount')); ?>;
    
    const result = await Swal.fire({
        title: 'Approve All Remittances?',
        html: `You are about to approve <strong>${remittanceIds.length}</strong> remittance(s) totaling <strong>₱${totalAmount.toLocaleString(undefined, {minimumFractionDigits: 2})}</strong>.<br><br>This will record all payments and update the invoices accordingly.`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#28a745',
        confirmButtonText: 'Yes, Approve All',
        cancelButtonText: 'Cancel'
    });
    
    if (!result.isConfirmed) return;
    
    Swal.fire({ title: 'Processing...', text: 'Please wait', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
    
    let successCount = 0;
    let failCount = 0;
    let errors = [];
    
    for (const remittanceId of remittanceIds) {
        try {
            const response = await fetch(window.location.href, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'approve_remittance',
                    remittance_id: remittanceId
                })
            });
            const text = await response.text();
            let data;
            try { data = JSON.parse(text); } catch (e) {
                console.error('Raw response:', text);
                failCount++;
                errors.push(`Remittance #${remittanceId}: Invalid server response`);
                continue;
            }
            
            if (data.success) {
                successCount++;
            } else {
                failCount++;
                errors.push(`Remittance #${remittanceId}: ${data.message || 'Approval failed'}`);
            }
        } catch (error) {
            failCount++;
            errors.push(`Remittance #${remittanceId}: ${error.message}`);
        }
    }
    
    Swal.close();
    
    if (successCount > 0) {
        let message = `${successCount} remittance(s) approved successfully.`;
        if (failCount > 0) {
            message += ` ${failCount} failed.`;
        }
        Swal.fire('Completed', message, successCount > 0 ? 'success' : 'error').then(() => {
            location.reload();
        });
    } else {
        Swal.fire('Error', 'Failed to approve remittances: ' + errors.join(', '), 'error');
    }
}

// Global Esc shortcut: close the topmost open Bootstrap modal.
// This works for all modals on this page, including Add Beginning Balance,
// payment, aging, assignment, attachment preview, and report filter modals.
document.addEventListener('keydown', function(event) {
    if (event.key !== 'Escape') return;

    const activeTag = (document.activeElement && document.activeElement.tagName || '').toLowerCase();
    const isTyping = ['input', 'textarea', 'select'].includes(activeTag) || (document.activeElement && document.activeElement.isContentEditable);

    // Allow Esc to close the modal even while typing, but do not interfere with SweetAlert dialogs.
    if (document.querySelector('.swal2-container.swal2-shown')) return;

    const openModals = Array.from(document.querySelectorAll('.modal.show'));
    if (openModals.length === 0) return;

    event.preventDefault();
    event.stopPropagation();

    const topModal = openModals[openModals.length - 1];
    const instance = bootstrap.Modal.getInstance(topModal) || new bootstrap.Modal(topModal);
    instance.hide();
});



// ========== Customer Payment Modal ==========
let cpInvoices = [];
let cpSelectedMethod = 'cash';
let cpTopAmountManual = false;

function cpMoney(value) {
    return (parseFloat(value || 0)).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
function cpRawMoney(value) {
    return parseFloat(String(value || '').replace(/[^0-9.]/g, '')) || 0;
}
function cpSetTopAmount(value, manual = false) {
    const amount = document.getElementById('cpPaymentAmount');
    if (!amount) return;
    amount.value = cpMoney(value);
    cpTopAmountManual = manual && cpRawMoney(value) > 0;
}
function cpSetToday() {
    const dateInput = document.getElementById('cpPaymentDate');
    if (dateInput && !dateInput.value) dateInput.value = new Date().toISOString().slice(0, 10);
}
function cpRenderPaymentDetails() {
    const leftPanel = document.getElementById('cpLeftPaymentDetails');
    const panel = document.getElementById('cpPaymentDetails');
    const amountLabel = document.getElementById('cpPaymentAmountLabel');
    const cashChangeRow = document.getElementById('cpCashChangeRow');
    const unappliedRow = document.getElementById('cpUnappliedRow');
    if (!panel || !leftPanel) return;

    if (amountLabel) {
        amountLabel.textContent = 'Payment Amount';
    }
    if (cashChangeRow) cashChangeRow.style.display = cpSelectedMethod === 'cash' ? '' : 'none';
    if (unappliedRow) unappliedRow.style.display = cpSelectedMethod === 'cash' ? 'none' : '';

    if (cpSelectedMethod === 'check') {
        leftPanel.style.display = 'none';
        leftPanel.innerHTML = '';
        panel.style.display = 'block';
        panel.innerHTML = `
            <div class="cp-form-row"><label class="cp-label">Check Date</label><div class="cp-field-wrap"><input type="date" class="form-control cp-input" id="cpCheckDate" value="${document.getElementById('cpPaymentDate')?.value || new Date().toISOString().slice(0,10)}" required></div></div>
            <div class="cp-form-row"><label class="cp-label">Check No.</label><div class="cp-field-wrap"><input type="text" class="form-control" id="cpCheckNumber" placeholder="Enter check number" required></div></div>
            <div class="cp-form-row"><label class="cp-label">Bank Name / Branch</label><div class="cp-field-wrap"><input type="text" class="form-control" id="cpBankNameBranch" placeholder="Type bank name / branch" required></div></div>`;
    } else if (cpSelectedMethod === 'online_transfer') {
        leftPanel.style.display = 'none';
        leftPanel.innerHTML = '';
        panel.style.display = 'block';
        panel.innerHTML = `
            <div class="cp-form-row"><label class="cp-label">Bank/Wallet</label><div class="cp-field-wrap"><input type="text" class="form-control" id="cpBankWallet" placeholder="Type bank or wallet" required></div></div>
            <div class="cp-form-row"><label class="cp-label">Reference No.</label><div class="cp-field-wrap"><input type="text" class="form-control" id="cpReferenceNumber" placeholder="Enter reference number" required></div></div>`;
    } else {
        leftPanel.style.display = 'none';
        leftPanel.innerHTML = '';
        panel.style.display = 'none';
        panel.innerHTML = '';
    }

    cpUpdateTotals(false);
}
function cpClearTable(message = 'Select the customer or job in the Received From field') {
    cpInvoices = [];
    const tbody = document.getElementById('cpInvoicesBody');
    if (tbody) tbody.innerHTML = `<tr><td colspan="6" class="p-0"><div class="cp-message-row">${message}</div></td></tr>`;
    ['cpOrigTotal','cpDueTotal','cpPayTotal','cpSummaryAmountDue','cpSummaryTotalPayment','cpSummaryChange'].forEach(id => { const el = document.getElementById(id); if (el) el.textContent = '0.00'; });
    const bal = document.getElementById('cpCustomerBalance'); if (bal) bal.textContent = '0.00';
    cpSetTopAmount(0, false);
    const all = document.getElementById('cpSelectAll'); if (all) all.checked = false;
    cpUpdateTotals(false);
}
function cpIsCollectibleInvoice(inv) {
    const orderStatus = String(inv.order_status || '').toLowerCase().trim();
    const orderType = String(inv.order_type || inv.fulfillment_type || '').toLowerCase().trim();
    const invoiceStatus = String(inv.status || '').toLowerCase().trim();
    const pickupTypes = ['pickup', 'pick_up', 'customer_pickup', 'store_pickup', 'branch_pickup', 'pick-up', 'for_pickup'];
    const blockedStatuses = ['paid', 'completed', 'cancelled', 'canceled', 'void', 'voided', 'failed'];
    const hasBalance = parseFloat(inv.total_amount || 0) > 0;

    if (!hasBalance || blockedStatuses.includes(invoiceStatus)) return false;
    return orderStatus === 'delivered' || pickupTypes.includes(orderType) || orderType === 'beginning_balance';
}

function cpRenderInvoices(invoices) {
    cpInvoices = (invoices || []).filter(inv => cpIsCollectibleInvoice(inv));
    const tbody = document.getElementById('cpInvoicesBody');
    if (!tbody) return;
    if (!cpInvoices.length) {
        cpClearTable('No collectible unpaid sales orders found for this customer');
        return;
    }
    let origTotal = 0, dueTotal = 0;
    tbody.innerHTML = cpInvoices.map(inv => {
        const due = parseFloat(inv.total_amount || 0);
        const orig = parseFloat(inv.original_total_amount || inv.total_amount || 0);
        origTotal += orig; dueTotal += due;
        const date = inv.invoice_date ? String(inv.invoice_date).slice(0, 10) : '-';
        const num = inv.so_number || inv.invoice_number || '-';
        return `<tr data-invoice-id="${inv.invoice_id}">
            <td><input type="checkbox" class="cp-row-check" data-invoice-id="${inv.invoice_id}"></td>
            <td>${escapeHtml(date)}</td>
            <td>${escapeHtml(num)}</td>
            <td class="text-end">${cpMoney(orig)}</td>
            <td class="text-end">${cpMoney(due)}</td>
            <td class="cp-payment-cell"><input type="text" class="form-control cp-row-payment" data-invoice-id="${inv.invoice_id}" data-due="${due}" value="0.00" inputmode="decimal"></td>
        </tr>`;
    }).join('');
    document.getElementById('cpOrigTotal').textContent = cpMoney(origTotal);
    document.getElementById('cpDueTotal').textContent = cpMoney(dueTotal);
    document.getElementById('cpCustomerBalance').textContent = cpMoney(dueTotal);
    const all = document.getElementById('cpSelectAll'); if (all) all.checked = false;
    cpSetTopAmount(0, false);
    cpUpdateTotals(false);
}
function cpUpdateTotals(syncTopAmount = false) {
    let total = 0;
    let selectedDue = 0;

    document.querySelectorAll('.cp-row-payment').forEach(input => {
        const amount = cpRawMoney(input.value);
        const due = parseFloat(input.dataset.due || 0);
        total += amount;

        const row = input.closest('tr');
        if (row) row.classList.toggle('cp-applied-row', amount > 0);

        const chk = document.querySelector(`.cp-row-check[data-invoice-id="${input.dataset.invoiceId}"]`);
        if ((chk && chk.checked) || amount > 0) selectedDue += due;
    });

    const payTotal = document.getElementById('cpPayTotal');
    if (payTotal) payTotal.textContent = cpMoney(total);

    const amount = document.getElementById('cpPaymentAmount');
    if (amount && syncTopAmount && !cpTopAmountManual && document.activeElement !== amount) {
        amount.value = cpMoney(total);
    }

    const entered = cpRawMoney(amount?.value || 0);
    const summaryPaymentAmount = entered > 0 ? entered : total;
    const change = total > 0 ? Math.max(summaryPaymentAmount - total, 0) : 0;

    const summaryDue = document.getElementById('cpSummaryAmountDue');
    if (summaryDue) summaryDue.textContent = cpMoney(selectedDue);

    const summaryPayment = document.getElementById('cpSummaryTotalPayment');
    if (summaryPayment) summaryPayment.textContent = cpMoney(summaryPaymentAmount);

    const summaryChange = document.getElementById('cpSummaryChange');
    if (summaryChange) summaryChange.textContent = cpMoney(change);

    const all = document.getElementById('cpSelectAll');
    if (all) {
        const checks = Array.from(document.querySelectorAll('.cp-row-check'));
        all.checked = checks.length > 0 && checks.every(chk => chk.checked);
        all.indeterminate = checks.some(chk => chk.checked) && !all.checked;
    }
}
function cpAllocateCheckedRowsFromTop() {
    let topAmount = cpRawMoney(document.getElementById('cpPaymentAmount')?.value || 0);
    let remaining = topAmount;

    document.querySelectorAll('.cp-row-payment').forEach(input => {
        const chk = document.querySelector(`.cp-row-check[data-invoice-id="${input.dataset.invoiceId}"]`);
        const checked = chk?.checked;
        const due = parseFloat(input.dataset.due || 0);

        if (checked && remaining > 0) {
            const pay = Math.min(due, remaining);
            input.value = cpMoney(pay);
            remaining -= pay;

            if (pay <= 0 && chk) chk.checked = false;
        } else {
            input.value = '0.00';
            if (checked && chk) chk.checked = false;
        }
    });

    cpUpdateTotals(false);
}
function cpHandleRowCheck(checkbox) {
    const input = document.querySelector(`.cp-row-payment[data-invoice-id="${checkbox.dataset.invoiceId}"]`);
    const topAmount = cpRawMoney(document.getElementById('cpPaymentAmount')?.value || 0);
    const currentRowPayment = cpRawMoney(input?.value || 0);

    if (!checkbox.checked) {
        if (input) input.value = '0.00';
        cpUpdateTotals(true);
        return;
    }

    // Do not allow checking a row if the row payment is 0
    // and there is no top Payment Amount available to allocate.
    if (currentRowPayment <= 0 && topAmount <= 0) {
        checkbox.checked = false;
        if (input) input.value = '0.00';
        cpUpdateTotals(false);
        Swal.fire('Payment Required', 'Enter an amount in the Payment column or in the top Payment Amount before checking this sales order.', 'warning');
        return;
    }

    // If there is a top Payment Amount, keep the same allocation process:
    // apply it to checked rows only up to the available amount.
    if (topAmount > 0) {
        cpAllocateCheckedRowsFromTop();
        if (!checkbox.checked) {
            Swal.fire('Payment Limit Reached', 'The top Payment Amount can no longer cover this sales order.', 'warning');
        }
        return;
    }

    cpUpdateTotals(true);
}
function cpHandleSelectAll(checked) {
    if (!checked) {
        document.querySelectorAll('.cp-row-check').forEach(chk => { chk.checked = false; });
        cpTopAmountManual = false;
        cpSetTopAmount(0, false);
        document.querySelectorAll('.cp-row-payment').forEach(input => { input.value = '0.00'; });
        cpUpdateTotals(false);
        return;
    }

    const topAmount = cpRawMoney(document.getElementById('cpPaymentAmount')?.value || 0);

    if (topAmount <= 0) {
        document.querySelectorAll('.cp-row-check').forEach(chk => { chk.checked = false; });
        cpUpdateTotals(false);
        Swal.fire('Payment Required', 'Enter a top Payment Amount before using Select All.', 'warning');
        return;
    }

    document.querySelectorAll('.cp-row-check').forEach(chk => { chk.checked = true; });
    cpAllocateCheckedRowsFromTop();
}
async function cpLoadCustomerInvoices(customerId) {
    if (!customerId) { cpClearTable(); return; }
    cpClearTable('Loading unpaid sales orders...');
    const formData = new FormData();
    formData.append('action', 'get_all_invoices');
    formData.append('customer_id', customerId);
    try {
        const res = await fetch(window.location.href, { method: 'POST', body: formData });
        const text = await res.text();
        const data = JSON.parse(text);
        if (!data.success) throw new Error(data.message || 'Failed to load unpaid sales orders');
        cpRenderInvoices(data.invoices || []);
    } catch (e) {
        console.error(e);
        cpClearTable('Failed to load unpaid sales orders');
        Swal.fire('Error', e.message || 'Failed to load unpaid sales orders', 'error');
    }
}
function cpCloseCustomerDropdown() {
    const menu = document.getElementById('cpCustomerMenu');
    const toggle = document.getElementById('cpCustomerToggle');
    if (menu) menu.classList.remove('show');
    if (toggle) toggle.setAttribute('aria-expanded', 'false');
}

function cpFilterCustomerOptions() {
    const search = document.getElementById('cpCustomerSearch');
    const menu = document.getElementById('cpCustomerMenu');
    if (!search || !menu) return;

    const keyword = String(search.value || '').toLowerCase().replace(/\s+/g, ' ').trim();
    let visibleCount = 0;

    menu.querySelectorAll('.cp-customer-option').forEach(item => {
        const label = String((item.dataset.label || '') + ' ' + (item.dataset.group || '')).toLowerCase().replace(/\s+/g, ' ').trim();
        const isVisible = !keyword || label.includes(keyword);
        item.style.display = isVisible ? '' : 'none';
        if (isVisible) visibleCount++;
    });

    let empty = menu.querySelector('.cp-customer-empty');
    if (!empty) {
        empty = document.createElement('div');
        empty.className = 'cp-customer-empty';
        empty.style.cssText = 'display:none;padding:9px 10px;font-size:13px;color:#6b7280;text-align:center;';
        empty.textContent = 'No customer found';
        menu.appendChild(empty);
    }
    empty.style.display = visibleCount ? 'none' : 'block';
}

function cpSetCustomerPickerValue(value, triggerChange = true) {
    const select = document.getElementById('cpCustomerSelect');
    const search = document.getElementById('cpCustomerSearch');
    const items = document.querySelectorAll('.cp-customer-option');
    const stringValue = String(value || '');
    let label = '';

    items.forEach(item => {
        const isActive = String(item.dataset.value || '') === stringValue;
        item.classList.toggle('active', isActive);
        if (isActive) label = item.dataset.label || item.querySelector('.cp-customer-option-name')?.textContent?.trim() || label;
        item.style.display = '';
    });

    if (search) search.value = label;
    if (select && select.value !== stringValue) select.value = stringValue;

    cpCloseCustomerDropdown();

    if (triggerChange && select) {
        select.dispatchEvent(new Event('change', { bubbles: true }));
    }
}

function initCpCustomerPicker() {
    const picker = document.getElementById('cpCustomerPicker');
    const toggle = document.getElementById('cpCustomerToggle');
    const search = document.getElementById('cpCustomerSearch');
    const menu = document.getElementById('cpCustomerMenu');
    const select = document.getElementById('cpCustomerSelect');
    if (!picker || !toggle || !search || !menu || picker.dataset.ready === '1') return;

    picker.dataset.ready = '1';

    function openMenu() {
        cpFilterCustomerOptions();
        menu.classList.add('show');
        toggle.setAttribute('aria-expanded', 'true');
    }

    toggle.addEventListener('click', function(e) {
        e.stopPropagation();
        search.focus();
        openMenu();
    });

    search.addEventListener('focus', openMenu);
    search.addEventListener('input', function() {
        if (select) select.value = '';
        menu.querySelectorAll('.cp-customer-option').forEach(item => item.classList.remove('active'));
        cpFilterCustomerOptions();
        menu.classList.add('show');
        toggle.setAttribute('aria-expanded', 'true');
    });

    search.addEventListener('keydown', function(e) {
        const visibleItems = Array.from(menu.querySelectorAll('.cp-customer-option')).filter(item => item.style.display !== 'none');
        if (e.key === 'Escape') {
            cpCloseCustomerDropdown();
            return;
        }
        if (!visibleItems.length) return;

        let currentIndex = visibleItems.findIndex(item => item.classList.contains('active'));
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            currentIndex = currentIndex < visibleItems.length - 1 ? currentIndex + 1 : 0;
            visibleItems.forEach(item => item.classList.remove('active'));
            visibleItems[currentIndex].classList.add('active');
            visibleItems[currentIndex].scrollIntoView({ block: 'nearest' });
            openMenu();
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            currentIndex = currentIndex > 0 ? currentIndex - 1 : visibleItems.length - 1;
            visibleItems.forEach(item => item.classList.remove('active'));
            visibleItems[currentIndex].classList.add('active');
            visibleItems[currentIndex].scrollIntoView({ block: 'nearest' });
            openMenu();
        } else if (e.key === 'Enter') {
            e.preventDefault();
            const selected = visibleItems[currentIndex >= 0 ? currentIndex : 0];
            if (selected) cpSetCustomerPickerValue(selected.dataset.value || '', true);
        }
    });

    menu.querySelectorAll('.cp-customer-option').forEach(item => {
        item.addEventListener('mousedown', function(e) {
            e.preventDefault();
            e.stopPropagation();
            cpSetCustomerPickerValue(this.dataset.value || '', true);
        });
    });

    document.addEventListener('mousedown', function(e) {
        if (!picker.contains(e.target)) cpCloseCustomerDropdown();
    });

    cpFilterCustomerOptions();
}

function cpResetForm(keepOpen = true) {
    const customer = document.getElementById('cpCustomerSelect'); if (customer) customer.value = '';
    cpSetCustomerPickerValue('', false);
    const memo = document.getElementById('cpMemo'); if (memo) memo.value = '';
    cpSetTopAmount(0, false);
    cpSelectedMethod = 'cash';
    document.querySelectorAll('.cp-method-btn').forEach(btn => btn.classList.toggle('active', btn.dataset.method === 'cash'));
    cpSetToday();
    cpRenderPaymentDetails();
    cpClearTable();
}
async function cpSave(closeAfter = true) {
    const customerId = document.getElementById('cpCustomerSelect')?.value || '';
    if (!customerId) { Swal.fire('Error', 'Please select a customer first.', 'error'); return; }
    const rows = [];
    document.querySelectorAll('.cp-row-payment').forEach(input => {
        const amount = cpRawMoney(input.value);
        const due = parseFloat(input.dataset.due || 0);
        if (amount > 0) rows.push({ invoice_id: input.dataset.invoiceId, amount, due });
    });
    if (!rows.length) { Swal.fire('Error', 'Please enter payment amount for at least one sales order.', 'error'); return; }
    for (const row of rows) {
        if (row.amount > row.due + 0.009) { Swal.fire('Error', 'Payment cannot be greater than amount due.', 'error'); return; }
    }
    const paymentDate = document.getElementById('cpPaymentDate')?.value || new Date().toISOString().slice(0,10);
    const payload = { action: 'submit_remittance', remittances: [] };
    for (const row of rows) {
        const memoText = document.getElementById('cpMemo')?.value || '';
        const item = { invoice_id: row.invoice_id, customer_id: customerId, payment_method: cpSelectedMethod, amount: row.amount, collection_date: paymentDate + ' 00:00:00', notes: memoText };
        if (cpSelectedMethod === 'cash') {
            item.cash_tendered = cpRawMoney(document.getElementById('cpPaymentAmount')?.value || 0);
            item.cash_change = Math.max(item.cash_tendered - rows.reduce((sum, r) => sum + r.amount, 0), 0);
        } else if (cpSelectedMethod === 'check') {
            item.check_date = document.getElementById('cpCheckDate')?.value || paymentDate;
            const bankNameBranch = document.getElementById('cpBankNameBranch')?.value || '';
            item.bank_name = bankNameBranch;
            item.bank_branch = bankNameBranch;
            item.check_number = document.getElementById('cpCheckNumber')?.value || '';
            item.reference_number = item.check_number;
            if (!bankNameBranch || !item.check_number) { Swal.fire('Error', 'Please complete check details.', 'error'); return; }
        } else if (cpSelectedMethod === 'online_transfer') {
            item.bank_name = document.getElementById('cpBankWallet')?.value || '';
            item.reference_number = document.getElementById('cpReferenceNumber')?.value || '';
            if (!item.bank_name || !item.reference_number) { Swal.fire('Error', 'Please complete bank/wallet and reference number.', 'error'); return; }
        }
        payload.remittances.push(item);
    }
    Swal.fire({ title: 'Saving customer payment...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
    try {
        const res = await fetch(window.location.href, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) });
        const text = await res.text();
        const data = JSON.parse(text);
        Swal.close();
        if (!data.success) { Swal.fire('Error', data.message || 'Failed to save payment.', 'error'); return; }
        Swal.fire('Success', data.message || 'Customer payment saved.', 'success').then(() => {
            if (closeAfter) bootstrap.Modal.getInstance(document.getElementById('customerPaymentModal'))?.hide();
            cpResetForm(!closeAfter);
            const dateFrom = $('#dateFrom').val();
            const dateTo = $('#dateTo').val();
            const searchTerm = getInvoiceGlobalSearchTerm();
            clearAllSelections();
            loadAllPendingInvoices(dateFrom, dateTo, searchTerm);
        });
    } catch (e) {
        Swal.close(); console.error(e); Swal.fire('Error', e.message || 'Failed to save payment.', 'error');
    }
}

document.addEventListener('DOMContentLoaded', function() {
    initCpCustomerPicker();
    const openBtn = document.getElementById('openCustomerPaymentBtn');
    if (openBtn) openBtn.addEventListener('click', function() { cpResetForm(); new bootstrap.Modal(document.getElementById('customerPaymentModal')).show(); });
    const customer = document.getElementById('cpCustomerSelect');
    if (customer) customer.addEventListener('change', function() {
        cpSetCustomerPickerValue(this.value, false);
        cpLoadCustomerInvoices(this.value);
    });
    document.querySelectorAll('.cp-method-btn').forEach(btn => btn.addEventListener('click', function() {
        document.querySelectorAll('.cp-method-btn').forEach(b => b.classList.remove('active'));
        this.classList.add('active'); cpSelectedMethod = this.dataset.method || 'cash'; cpRenderPaymentDetails();
    }));
    document.addEventListener('change', function(e) {
        if (e.target && e.target.id === 'cpSelectAll') cpHandleSelectAll(e.target.checked);
        if (e.target && e.target.classList.contains('cp-row-check')) cpHandleRowCheck(e.target);
    });
    document.addEventListener('input', function(e) {
        if (e.target && e.target.classList.contains('cp-row-payment')) {
            const val = cpRawMoney(e.target.value);
            const due = parseFloat(e.target.dataset.due || 0);
            if (val > due) e.target.value = cpMoney(due);
            const chk = document.querySelector(`.cp-row-check[data-invoice-id="${e.target.dataset.invoiceId}"]`);
            if (chk) chk.checked = cpRawMoney(e.target.value) > 0;
            cpTopAmountManual = false;
            cpUpdateTotals(true);
        }
        if (e.target && e.target.id === 'cpPaymentAmount') {
            cpTopAmountManual = cpRawMoney(e.target.value) > 0;
            cpAllocateCheckedRowsFromTop();
        }
    });
    document.addEventListener('blur', function(e) {
        if (e.target && e.target.classList.contains('cp-row-payment')) e.target.value = cpMoney(cpRawMoney(e.target.value));
        if (e.target && e.target.id === 'cpPaymentAmount') { e.target.value = cpMoney(cpRawMoney(e.target.value)); cpAllocateCheckedRowsFromTop(); }
    }, true);
    const saveClose = document.getElementById('cpSaveCloseBtn'); if (saveClose) saveClose.addEventListener('click', () => cpSave(true));
    const saveNew = document.getElementById('cpSaveNewBtn'); if (saveNew) saveNew.addEventListener('click', () => cpSave(false));
    const clearBtn = document.getElementById('cpClearBtn'); if (clearBtn) clearBtn.addEventListener('click', () => cpResetForm());
});


</script>
</body>
</html>
