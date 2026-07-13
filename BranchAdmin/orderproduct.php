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

// Get customer ID and name from URL parameters
$pre_selected_customer_id = isset($_GET['customer_id']) ? (int)$_GET['customer_id'] : 0;
$pre_selected_customer_name = isset($_GET['customer_name']) ? htmlspecialchars($_GET['customer_name']) : '';
$lock_customer_from_url = isset($_GET['lock_customer']) && (string)$_GET['lock_customer'] === '1';
$is_customer_locked = ($pre_selected_customer_id > 0 && $lock_customer_from_url); 

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
amgcAddColumnIfMissing($conn, 'sales_orders', 'billing_type', "billing_type ENUM('invoice','credit') NOT NULL DEFAULT 'invoice' AFTER document_type");
amgcAddColumnIfMissing($conn, 'sales_orders', 'is_recurring', 'is_recurring TINYINT(1) NOT NULL DEFAULT 0 AFTER billing_type');
amgcAddColumnIfMissing($conn, 'sales_orders', 'recurring_every', 'recurring_every INT(11) DEFAULT NULL AFTER is_recurring');
amgcAddColumnIfMissing($conn, 'sales_orders', 'recurring_period', "recurring_period ENUM('day','week','month','year') DEFAULT NULL AFTER recurring_every");
amgcAddColumnIfMissing($conn, 'sales_orders', 'recurring_until', 'recurring_until DATE DEFAULT NULL AFTER recurring_period');
amgcAddColumnIfMissing($conn, 'sales_orders', 'recurrence_group', 'recurrence_group VARCHAR(64) DEFAULT NULL AFTER recurring_until');
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
amgcAddColumnIfMissing($conn, 'sales_orders', 'si_attachments', 'si_attachments LONGTEXT DEFAULT NULL AFTER business_address');
amgcAddColumnIfMissing($conn, 'invoices', 'si_attachments', 'si_attachments LONGTEXT DEFAULT NULL AFTER business_address');
amgcAddColumnIfMissing($conn, 'payments', 'si_attachments', 'si_attachments LONGTEXT DEFAULT NULL AFTER business_address');

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




// ===== RECURRING INVOICE TASK SCHEDULE HELPERS =====
// Uses the same user_tasks / user_task_assignees structure used by tasks.php.
function amgcOrderProductEnsureTaskScheduleTables($conn) {
    $conn->query("CREATE TABLE IF NOT EXISTS `user_tasks` (
        `task_id` INT NOT NULL AUTO_INCREMENT,
        `branch_id` INT DEFAULT NULL,
        `created_by` INT NOT NULL,
        `title` VARCHAR(255) NOT NULL,
        `description` TEXT DEFAULT NULL,
        `task_date` DATE NOT NULL,
        `task_time` TIME NOT NULL,
        `due_datetime` DATETIME NOT NULL,
        `reminder_days` INT NOT NULL DEFAULT 1,
        `status` ENUM('pending','in_progress','completed','cancelled') NOT NULL DEFAULT 'pending',
        `priority` ENUM('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
        `is_recurring` TINYINT(1) NOT NULL DEFAULT 0,
        `recurrence_interval` INT DEFAULT NULL,
        `recurrence_unit` ENUM('day','week','month','year') DEFAULT NULL,
        `recurrence_until` DATE DEFAULT NULL,
        `recurrence_group` VARCHAR(64) DEFAULT NULL,
        `source_type` VARCHAR(50) DEFAULT NULL,
        `source_id` INT DEFAULT NULL,
        `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`task_id`),
        KEY `idx_tasks_branch_due` (`branch_id`,`due_datetime`),
        KEY `idx_tasks_status` (`status`),
        KEY `idx_tasks_created_by` (`created_by`),
        KEY `idx_tasks_source` (`source_type`,`source_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $conn->query("CREATE TABLE IF NOT EXISTS `user_task_assignees` (
        `id` INT NOT NULL AUTO_INCREMENT,
        `task_id` INT NOT NULL,
        `user_id` INT NOT NULL,
        `notify_seen` TINYINT(1) NOT NULL DEFAULT 0,
        `seen_at` DATETIME DEFAULT NULL,
        `assignee_status` ENUM('pending','in_progress','completed','cancelled') NOT NULL DEFAULT 'pending',
        `assignee_note` TEXT DEFAULT NULL,
        `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uniq_task_user` (`task_id`,`user_id`),
        KEY `idx_assignee_user` (`user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $taskColumns = [
        'priority' => "ENUM('low','normal','high','urgent') NOT NULL DEFAULT 'normal' AFTER `status`",
        'is_recurring' => "TINYINT(1) NOT NULL DEFAULT 0 AFTER `priority`",
        'recurrence_interval' => "INT DEFAULT NULL AFTER `is_recurring`",
        'recurrence_unit' => "ENUM('day','week','month','year') DEFAULT NULL AFTER `recurrence_interval`",
        'recurrence_until' => "DATE DEFAULT NULL AFTER `recurrence_unit`",
        'recurrence_group' => "VARCHAR(64) DEFAULT NULL AFTER `recurrence_until`",
        'source_type' => "VARCHAR(50) DEFAULT NULL AFTER `recurrence_group`",
        'source_id' => "INT DEFAULT NULL AFTER `source_type`"
    ];
    foreach ($taskColumns as $column => $definition) {
        if (!amgcColumnExists($conn, 'user_tasks', $column)) {
            @$conn->query("ALTER TABLE `user_tasks` ADD COLUMN `{$column}` {$definition}");
        }
    }
    $assigneeColumns = [
        'notify_seen' => "TINYINT(1) NOT NULL DEFAULT 0 AFTER `user_id`",
        'seen_at' => "DATETIME DEFAULT NULL AFTER `notify_seen`",
        'assignee_status' => "ENUM('pending','in_progress','completed','cancelled') NOT NULL DEFAULT 'pending' AFTER `seen_at`",
        'assignee_note' => "TEXT DEFAULT NULL AFTER `assignee_status`",
        'updated_at' => "TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `assignee_note`"
    ];
    foreach ($assigneeColumns as $column => $definition) {
        if (!amgcColumnExists($conn, 'user_task_assignees', $column)) {
            @$conn->query("ALTER TABLE `user_task_assignees` ADD COLUMN `{$column}` {$definition}");
        }
    }
}

function amgcOrderProductCreateRecurringInvoiceTasks($conn, $soId, $soNumber, $customerName, $branchId, $userId, $startDate, $every, $period, $untilDate, $recurrenceGroup) {
    $soId = (int)$soId;
    $branchId = (int)$branchId;
    $userId = (int)$userId;
    $every = max(1, (int)$every);
    $period = strtolower(trim((string)$period));
    $startDate = substr(trim((string)$startDate), 0, 10);
    $untilDate = substr(trim((string)$untilDate), 0, 10);
    if ($soId <= 0 || $userId <= 0 || $startDate === '' || $untilDate === '' || !in_array($period, ['day','week','month','year'], true)) return 0;

    amgcOrderProductEnsureTaskScheduleTables($conn);

    // Prevent duplicated reminders when the same request is retried.
    $delete = $conn->prepare("DELETE FROM user_tasks WHERE source_type='recurring_invoice' AND source_id=?");
    if ($delete) { $delete->bind_param('i', $soId); $delete->execute(); $delete->close(); }

    try {
        $date = new DateTime($startDate);
        $until = new DateTime($untilDate);
    } catch (Exception $dateError) {
        throw new Exception('Invalid recurring invoice schedule date.');
    }
    if ($until < $date) {
        throw new Exception('Recurring invoice Until Date cannot be earlier than the invoice date.');
    }
    if ($period === 'day') $interval = new DateInterval('P' . $every . 'D');
    elseif ($period === 'week') $interval = new DateInterval('P' . ($every * 7) . 'D');
    elseif ($period === 'month') $interval = new DateInterval('P' . $every . 'M');
    else $interval = new DateInterval('P' . $every . 'Y');

    // The current invoice is already created, so reminders begin on the next occurrence.
    $date->add($interval);
    $created = 0;
    $taskTime = '08:00:00';
    $titleCustomer = trim((string)$customerName) !== '' ? trim((string)$customerName) : 'Customer';
    $title = 'Recurring Invoice - ' . $titleCustomer . ' (' . $soNumber . ')';
    $description = 'Scheduled recurring invoice based on ' . $soNumber . '. Open Create Invoice, review the customer and order details, then create the next invoice.';
    $priority = 'normal';
    $reminderDays = 1;
    $isRecurring = 1;
    $sourceType = 'recurring_invoice';

    $insert = $conn->prepare("INSERT INTO user_tasks
        (branch_id, created_by, title, description, task_date, task_time, due_datetime, reminder_days, priority, is_recurring, recurrence_interval, recurrence_unit, recurrence_until, recurrence_group, source_type, source_id)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    if (!$insert) throw new Exception('Unable to prepare recurring invoice task schedule: ' . $conn->error);
    $assign = $conn->prepare("INSERT IGNORE INTO user_task_assignees (task_id, user_id) VALUES (?, ?)");
    if (!$assign) { $insert->close(); throw new Exception('Unable to prepare recurring invoice assignee: ' . $conn->error); }

    while ($date <= $until && $created < 370) {
        $taskDate = $date->format('Y-m-d');
        $dueDateTime = $taskDate . ' ' . $taskTime;
        $insert->bind_param('iisssssisiissssi', $branchId, $userId, $title, $description, $taskDate, $taskTime, $dueDateTime, $reminderDays, $priority, $isRecurring, $every, $period, $untilDate, $recurrenceGroup, $sourceType, $soId);
        if (!$insert->execute()) {
            $message = $insert->error;
            $assign->close();
            $insert->close();
            throw new Exception('Unable to save recurring invoice task: ' . $message);
        }
        $taskId = (int)$conn->insert_id;
        $assign->bind_param('ii', $taskId, $userId);
        if (!$assign->execute()) {
            $message = $assign->error;
            $assign->close(); $insert->close();
            throw new Exception('Unable to assign recurring invoice task: ' . $message);
        }
        $created++;
        $date->add($interval);
    }
    $assign->close();
    $insert->close();
    return $created;
}

// ===== PICKUP PAYMENT / INVOICE HELPERS =====
function amgcOrderProductTableExists($conn, $table) {
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $res = $conn->query("SHOW TABLES LIKE '$table'");
    return ($res && $res->num_rows > 0);
}

function amgcOrderProductNormalizeSIAttachments($raw) {
    if (empty($raw)) return [];
    $decoded = json_decode((string)$raw, true);
    if (is_array($decoded)) {
        $clean = [];
        foreach ($decoded as $file) {
            if (!is_array($file)) continue;
            $path = trim((string)($file['path'] ?? ''));
            if ($path === '') continue;
            $clean[] = [
                'name' => trim((string)($file['name'] ?? basename($path))),
                'path' => $path,
                'uploaded_at' => trim((string)($file['uploaded_at'] ?? ''))
            ];
        }
        return $clean;
    }
    $raw = trim((string)$raw);
    return $raw !== '' ? [['name' => basename($raw), 'path' => $raw, 'uploaded_at' => '']] : [];
}

function amgcOrderProductSaveSIAttachments($so_id) {
    $saved = [];
    if (!isset($_FILES['si_attachments'])) return $saved;

    $files = $_FILES['si_attachments'];
    $names = is_array($files['name'] ?? null) ? $files['name'] : [$files['name'] ?? ''];
    $tmpNames = is_array($files['tmp_name'] ?? null) ? $files['tmp_name'] : [$files['tmp_name'] ?? ''];
    $errors = is_array($files['error'] ?? null) ? $files['error'] : [$files['error'] ?? UPLOAD_ERR_NO_FILE];
    $sizes = is_array($files['size'] ?? null) ? $files['size'] : [$files['size'] ?? 0];

    $uploadDir = __DIR__ . '/../uploads/si_attachments';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true)) {
        throw new Exception('Unable to create SI attachment upload folder.');
    }

    $allowedExtensions = ['pdf','jpg','jpeg','png','webp','doc','docx','xls','xlsx'];
    $maxSize = 15 * 1024 * 1024;

    foreach ($names as $idx => $originalName) {
        $originalName = trim((string)$originalName);
        $error = (int)($errors[$idx] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE || $originalName === '') continue;
        if ($error !== UPLOAD_ERR_OK) throw new Exception('Failed to upload one SI attachment.');

        $size = (int)($sizes[$idx] ?? 0);
        if ($size > $maxSize) throw new Exception('Each SI attachment must not exceed 15MB.');

        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($extension, $allowedExtensions, true)) {
            throw new Exception('Invalid SI attachment type. Allowed: PDF, images, Word, and Excel files.');
        }

        $safeBase = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', pathinfo($originalName, PATHINFO_FILENAME));
        $safeBase = trim($safeBase, '._-');
        if ($safeBase === '') $safeBase = 'si_attachment';
        $fileName = 'SI_' . (int)$so_id . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '_' . $safeBase . '.' . $extension;
        $targetPath = $uploadDir . '/' . $fileName;
        if (!move_uploaded_file($tmpNames[$idx], $targetPath)) {
            throw new Exception('Unable to save SI attachment.');
        }

        $saved[] = [
            'name' => $originalName,
            'path' => '../uploads/si_attachments/' . $fileName,
            'uploaded_at' => date('Y-m-d H:i:s')
        ];
    }

    return $saved;
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





// ===== ACCOUNTING / JOURNAL POSTING HELPERS =====
// This connects saved invoices from Order Product to Chart of Accounts and Journal Entries.
function amgcOrderProductEnsureAccountingTables($conn) {
    $conn->query("CREATE TABLE IF NOT EXISTS `chart_of_accounts` (
        `account_id` INT(11) NOT NULL AUTO_INCREMENT,
        `branch_id` INT(11) DEFAULT NULL,
        `parent_account_id` INT(11) DEFAULT NULL,
        `account_code` VARCHAR(50) DEFAULT NULL,
        `account_title` VARCHAR(255) NOT NULL,
        `account_type` VARCHAR(100) NOT NULL,
        `description` TEXT DEFAULT NULL,
        `balance` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
        `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
        `created_by` INT(11) DEFAULT NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`account_id`),
        KEY `idx_chart_accounts_branch_id` (`branch_id`),
        KEY `idx_chart_accounts_type` (`account_type`),
        KEY `idx_chart_accounts_status` (`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

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
        `account_id` INT(11) NOT NULL,
        `branch_id` INT(11) NOT NULL DEFAULT 0,
        `transaction_date` DATE NOT NULL,
        `transaction_type` VARCHAR(100) NOT NULL,
        `transaction_no` VARCHAR(100) DEFAULT NULL,
        `reference_no` VARCHAR(100) DEFAULT NULL,
        `memo` TEXT DEFAULT NULL,
        `account_name` VARCHAR(255) DEFAULT NULL,
        `counterparty` VARCHAR(255) DEFAULT NULL,
        `debit` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
        `credit` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
        `balance_after` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
        `source_table` VARCHAR(100) DEFAULT NULL,
        `source_id` INT(11) DEFAULT NULL,
        `created_by` INT(11) DEFAULT NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`transaction_id`),
        KEY `idx_cat_account_id` (`account_id`),
        KEY `idx_cat_branch_id` (`branch_id`),
        KEY `idx_cat_source` (`source_table`, `source_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    $neededColumns = [
        'counterparty' => "ALTER TABLE `chart_account_transactions` ADD COLUMN `counterparty` VARCHAR(255) DEFAULT NULL AFTER `account_name`",
        'source_table' => "ALTER TABLE `chart_account_transactions` ADD COLUMN `source_table` VARCHAR(100) DEFAULT NULL AFTER `balance_after`",
        'source_id' => "ALTER TABLE `chart_account_transactions` ADD COLUMN `source_id` INT(11) DEFAULT NULL AFTER `source_table`",
        'created_by' => "ALTER TABLE `chart_account_transactions` ADD COLUMN `created_by` INT(11) DEFAULT NULL AFTER `source_id`",
        'created_at' => "ALTER TABLE `chart_account_transactions` ADD COLUMN `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER `created_by`"
    ];
    foreach ($neededColumns as $column => $sql) {
        if (!amgcColumnExists($conn, 'chart_account_transactions', $column)) {
            @$conn->query($sql);
        }
    }
}

function amgcOrderProductGetOrCreateAccount($conn, $title, $type, $branch_id, $user_id) {
    amgcOrderProductEnsureAccountingTables($conn);
    $branch_id = (int)$branch_id;
    $user_id = (int)$user_id;

    $sql = "SELECT account_id, account_title, account_type, COALESCE(balance,0) AS balance FROM chart_of_accounts WHERE status = 'active' AND account_title = ?";
    if ($branch_id > 0 && amgcColumnExists($conn, 'chart_of_accounts', 'branch_id')) {
        $sql .= " AND (branch_id = ? OR branch_id = 0 OR branch_id IS NULL) ORDER BY CASE WHEN branch_id = ? THEN 0 WHEN branch_id IS NULL THEN 1 ELSE 2 END, account_id ASC LIMIT 1";
        $stmt = $conn->prepare($sql);
        if ($stmt) $stmt->bind_param('sii', $title, $branch_id, $branch_id);
    } else {
        $sql .= " ORDER BY account_id ASC LIMIT 1";
        $stmt = $conn->prepare($sql);
        if ($stmt) $stmt->bind_param('s', $title);
    }
    if ($stmt) {
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) return $row;
    }

    $description = 'Auto-created for Order Product invoice posting.';
    $account_code = '';
    $target_branch = $branch_id > 0 ? $branch_id : null;
    $balance = 0.00;
    $parent = null;
    $insert = $conn->prepare("INSERT INTO chart_of_accounts (branch_id, parent_account_id, account_code, account_title, account_type, description, balance, status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, 'active', ?)");
    if (!$insert) throw new Exception('Unable to create chart account: ' . $conn->error);
    $insert->bind_param('iissssdi', $target_branch, $parent, $account_code, $title, $type, $description, $balance, $user_id);
    if (!$insert->execute()) throw new Exception('Unable to create chart account: ' . $insert->error);
    $account_id = (int)$conn->insert_id;
    $insert->close();
    return ['account_id' => $account_id, 'account_title' => $title, 'account_type' => $type, 'balance' => 0.00];
}

function amgcOrderProductAccountNewBalance($account_type, $current_balance, $debit, $credit) {
    $normalDebitTypes = ['Bank', 'Accounts Receivable', 'Other Current Asset', 'Fixed Asset', 'Other Asset', 'Cost of Goods Sold', 'Expense', 'Other Expense'];
    if (in_array($account_type, $normalDebitTypes, true)) {
        return (float)$current_balance + (float)$debit - (float)$credit;
    }
    return (float)$current_balance - (float)$debit + (float)$credit;
}

function amgcOrderProductInsertAccountingLine($conn, $journal_id, $account, $branch_id, $entry_date, $entry_no, $reference_no, $memo, $counterparty, $debit, $credit, $source_table, $source_id, $user_id) {
    $account_id = (int)$account['account_id'];
    $account_title = (string)$account['account_title'];
    $account_type = (string)$account['account_type'];
    $debit = round((float)$debit, 2);
    $credit = round((float)$credit, 2);
    if ($account_id <= 0 || ($debit <= 0 && $credit <= 0)) return;

    $current_balance = (float)($account['balance'] ?? 0);
    $new_balance = amgcOrderProductAccountNewBalance($account_type, $current_balance, $debit, $credit);

    $upd = $conn->prepare("UPDATE chart_of_accounts SET balance = ? WHERE account_id = ?");
    if (!$upd) throw new Exception('Unable to update chart account balance: ' . $conn->error);
    $upd->bind_param('di', $new_balance, $account_id);
    if (!$upd->execute()) throw new Exception('Unable to update chart account balance: ' . $upd->error);
    $upd->close();

    $detail = $conn->prepare("INSERT INTO journal_entry_details (journal_id, account_id, account_title, debit, credit, memo, counterparty) VALUES (?, ?, ?, ?, ?, ?, ?)");
    if (!$detail) throw new Exception('Unable to save journal detail: ' . $conn->error);
    $detail->bind_param('iisddss', $journal_id, $account_id, $account_title, $debit, $credit, $memo, $counterparty);
    if (!$detail->execute()) throw new Exception('Unable to save journal detail: ' . $detail->error);
    $detail->close();

    $cat = $conn->prepare("INSERT INTO chart_account_transactions (account_id, branch_id, transaction_date, transaction_type, transaction_no, reference_no, memo, account_name, counterparty, debit, credit, balance_after, source_table, source_id, created_by) VALUES (?, ?, ?, 'Create Invoice', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    if (!$cat) throw new Exception('Unable to save chart account transaction: ' . $conn->error);
    $cat->bind_param('iissssssdddsii', $account_id, $branch_id, $entry_date, $entry_no, $reference_no, $memo, $account_title, $counterparty, $debit, $credit, $new_balance, $source_table, $source_id, $user_id);
    if (!$cat->execute()) throw new Exception('Unable to save chart account transaction: ' . $cat->error);
    $cat->close();
}


function amgcOrderProductFirstExistingColumn($conn, $table, $columns) {
    foreach ($columns as $column) {
        if (amgcColumnExists($conn, $table, $column)) {
            return $column;
        }
    }
    return '';
}

function amgcOrderProductGetItemCostForAccounting($conn, $item_id, $unit_type, $branch_id = 0) {
    $item_id = (int)$item_id;
    $branch_id = (int)$branch_id;
    $unit_type = trim((string)$unit_type);
    if ($item_id <= 0) return 0.00;

    // 1) Primary source: item_unit_inventory for the exact selected UoM.
    if (amgcOrderProductTableExists($conn, 'item_unit_inventory') && amgcOrderProductTableExists($conn, 'unit_types')) {
        $cost_col = amgcOrderProductFirstExistingColumn($conn, 'item_unit_inventory', ['unit_cost', 'ave_cost', 'average_cost', 'cost', 'purchase_cost']);
        if ($cost_col !== '') {
            $branch_filter = '';
            if ($branch_id > 0 && amgcColumnExists($conn, 'item_unit_inventory', 'branch_id')) {
                $branch_filter = " AND (iui.branch_id = " . (int)$branch_id . " OR iui.branch_id = 0 OR iui.branch_id IS NULL)";
            }
            $sql = "SELECT COALESCE(NULLIF(iui.`$cost_col`, 0), 0) AS unit_cost
                    FROM item_unit_inventory iui
                    JOIN unit_types ut ON iui.unit_type_id = ut.unit_type_id
                    WHERE iui.item_id = ?" . ($unit_type !== '' ? " AND LOWER(ut.unit_type_name) = LOWER(?)" : "") . $branch_filter . "
                    ORDER BY CASE WHEN COALESCE(iui.`$cost_col`,0) > 0 THEN 0 ELSE 1 END, iui.inventory_id DESC
                    LIMIT 1";
            $stmt = $conn->prepare($sql);
            if ($stmt) {
                if ($unit_type !== '') $stmt->bind_param('is', $item_id, $unit_type);
                else $stmt->bind_param('i', $item_id);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                $cost = (float)($row['unit_cost'] ?? 0);
                if ($cost > 0) return $cost;
            }

            // If exact UoM has no cost, use the average cost of any costed inventory row for the item.
            $sql = "SELECT AVG(NULLIF(`$cost_col`, 0)) AS avg_cost FROM item_unit_inventory WHERE item_id = ?";
            if ($branch_id > 0 && amgcColumnExists($conn, 'item_unit_inventory', 'branch_id')) {
                $sql .= " AND (branch_id = " . (int)$branch_id . " OR branch_id = 0 OR branch_id IS NULL)";
            }
            $stmt = $conn->prepare($sql);
            if ($stmt) {
                $stmt->bind_param('i', $item_id);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                $cost = (float)($row['avg_cost'] ?? 0);
                if ($cost > 0) return $cost;
            }
        }
    }

    // 2) Fallback: item_unit_pricing if your cost is stored with unit pricing.
    if (amgcOrderProductTableExists($conn, 'item_unit_pricing')) {
        $cost_col = amgcOrderProductFirstExistingColumn($conn, 'item_unit_pricing', ['unit_cost', 'ave_cost', 'average_cost', 'cost', 'purchase_cost', 'purchase_price']);
        if ($cost_col !== '') {
            $join = amgcOrderProductTableExists($conn, 'unit_types') && amgcColumnExists($conn, 'item_unit_pricing', 'unit_type_id');
            $sql = $join
                ? "SELECT COALESCE(NULLIF(iup.`$cost_col`,0),0) AS unit_cost FROM item_unit_pricing iup JOIN unit_types ut ON iup.unit_type_id = ut.unit_type_id WHERE iup.item_id = ?" . ($unit_type !== '' ? " AND LOWER(ut.unit_type_name) = LOWER(?)" : "") . " ORDER BY CASE WHEN COALESCE(iup.`$cost_col`,0) > 0 THEN 0 ELSE 1 END LIMIT 1"
                : "SELECT COALESCE(NULLIF(`$cost_col`,0),0) AS unit_cost FROM item_unit_pricing WHERE item_id = ? ORDER BY CASE WHEN COALESCE(`$cost_col`,0) > 0 THEN 0 ELSE 1 END LIMIT 1";
            $stmt = $conn->prepare($sql);
            if ($stmt) {
                if ($join && $unit_type !== '') $stmt->bind_param('is', $item_id, $unit_type);
                else $stmt->bind_param('i', $item_id);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                $cost = (float)($row['unit_cost'] ?? 0);
                if ($cost > 0) return $cost;
            }
        }
    }

    // 3) Last fallback: item master cost columns.
    if (amgcOrderProductTableExists($conn, 'items')) {
        $cost_col = amgcOrderProductFirstExistingColumn($conn, 'items', ['ave_cost', 'average_cost', 'unit_cost', 'item_cost', 'cost', 'purchase_cost', 'purchase_price', 'buying_price']);
        if ($cost_col !== '') {
            $sql = "SELECT COALESCE(NULLIF(`$cost_col`,0),0) AS unit_cost FROM items WHERE item_id = ? LIMIT 1";
            $stmt = $conn->prepare($sql);
            if ($stmt) {
                $stmt->bind_param('i', $item_id);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                $cost = (float)($row['unit_cost'] ?? 0);
                if ($cost > 0) return $cost;
            }
        }
    }

    return 0.00;
}

function amgcOrderProductPostInvoiceAccounting($conn, $so_id, $invoice_id, $customer_id, $branch_id, $user_id, $total_amount, $total_cogs, $document_no = '') {
    $so_id = (int)$so_id;
    $invoice_id = (int)$invoice_id;
    $customer_id = (int)$customer_id;
    $branch_id = (int)$branch_id;
    $user_id = (int)$user_id;
    $total_amount = round((float)$total_amount, 2);
    $total_cogs = round(max(0, (float)$total_cogs), 2);
    if ($so_id <= 0 || $total_amount <= 0) return;

    amgcOrderProductEnsureAccountingTables($conn);

    $dup = $conn->prepare("SELECT transaction_id FROM chart_account_transactions WHERE source_table = 'sales_orders' AND source_id = ? LIMIT 1");
    if ($dup) {
        $dup->bind_param('i', $so_id);
        $dup->execute();
        $existing = $dup->get_result()->fetch_assoc();
        $dup->close();
        if ($existing) return;
    }

    $customer_name = '';
    $cust = $conn->prepare("SELECT customer_name FROM customers WHERE customer_id = ? LIMIT 1");
    if ($cust) {
        $cust->bind_param('i', $customer_id);
        $cust->execute();
        $row = $cust->get_result()->fetch_assoc();
        $cust->close();
        $customer_name = trim((string)($row['customer_name'] ?? ''));
    }

    $ar = amgcOrderProductGetOrCreateAccount($conn, 'Accounts Receivable', 'Accounts Receivable', $branch_id, $user_id);
    $sales = amgcOrderProductGetOrCreateAccount($conn, 'Sales', 'Income', $branch_id, $user_id);
    $cogs = amgcOrderProductGetOrCreateAccount($conn, 'Cost of Goods Sold', 'Cost of Goods Sold', $branch_id, $user_id);
    $inventory = amgcOrderProductGetOrCreateAccount($conn, 'Inventory', 'Other Current Asset', $branch_id, $user_id);

    $entry_no = 'INV-' . date('Ymd') . '-' . str_pad((string)$so_id, 5, '0', STR_PAD_LEFT);
    $entry_date = date('Y-m-d');
    $reference_no = trim($document_no) !== '' ? trim($document_no) : ('SO #' . $so_id);
    $memo = 'Invoice posted from Order Product';

    $header = $conn->prepare("INSERT INTO journal_entries (entry_no, journal_date, attachment_path, branch_id, created_by) VALUES (?, ?, NULL, ?, ?)");
    if (!$header) throw new Exception('Unable to save invoice journal header: ' . $conn->error);
    $header->bind_param('ssii', $entry_no, $entry_date, $branch_id, $user_id);
    if (!$header->execute()) throw new Exception('Unable to save invoice journal header: ' . $header->error);
    $journal_id = (int)$conn->insert_id;
    $header->close();

    amgcOrderProductInsertAccountingLine($conn, $journal_id, $ar, $branch_id, $entry_date, $entry_no, $reference_no, $memo, $customer_name, $total_amount, 0, 'sales_orders', $so_id, $user_id);
    amgcOrderProductInsertAccountingLine($conn, $journal_id, $sales, $branch_id, $entry_date, $entry_no, $reference_no, $memo, $customer_name, 0, $total_amount, 'sales_orders', $so_id, $user_id);

    if ($total_cogs > 0) {
        amgcOrderProductInsertAccountingLine($conn, $journal_id, $cogs, $branch_id, $entry_date, $entry_no, $reference_no, $memo, $customer_name, $total_cogs, 0, 'sales_orders', $so_id, $user_id);
        amgcOrderProductInsertAccountingLine($conn, $journal_id, $inventory, $branch_id, $entry_date, $entry_no, $reference_no, $memo, $customer_name, 0, $total_cogs, 'sales_orders', $so_id, $user_id);
    }
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

// Customer dropdown group source. It will use the first existing column below.
$customer_group_column = amgcOrderProductFirstExistingColumn($conn, 'customers', ['customer_group', 'group_name', 'customer_type', 'group_type', 'classification']);
$customer_group_select_sql = $customer_group_column !== ''
    ? "COALESCE(NULLIF(TRIM(c.`$customer_group_column`), ''), 'General') AS customer_group"
    : "'General' AS customer_group";

if ($branch_column_exists) {
    if ($view_all_branches) {
        $customers_query = "SELECT c.customer_id, c.customer_name, c.email, c.phone_number, c.address, c.city,
                        c.price_level,
                        $customer_group_select_sql
                        FROM customers c
                        LEFT JOIN branches b ON c.branch_id = b.branch_id
                        WHERE c.status = 'active'
                        ORDER BY customer_group ASC, c.customer_name ASC";
    } else {
        $customers_query = "SELECT c.customer_id, c.customer_name, c.email, c.phone_number, c.address, c.city,
                        c.price_level,
                        $customer_group_select_sql
                        FROM customers c
                        WHERE c.status = 'active' AND c.branch_id = $branch_id
                        ORDER BY customer_group ASC, c.customer_name ASC";
    }
} else {
    $customers_query = "SELECT c.customer_id, c.customer_name, c.email, c.phone_number, c.address, c.city, c.price_level,
                    $customer_group_select_sql
                    FROM customers c
                    WHERE c.status = 'active'
                    ORDER BY customer_group ASC, c.customer_name ASC";
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

// Get active Motorpool-registered vehicles for delivery assignment.
// Only vehicles registered in Motorpool should appear during order confirmation.
$delivery_vehicles = [];
if (amgcOrderProductTableExists($conn, 'motorpool_vehicles')) {
    $motorpoolVehicleColumns = [];
    $motorpoolVehicleColumnsResult = $conn->query("SHOW COLUMNS FROM motorpool_vehicles");
    if ($motorpoolVehicleColumnsResult) {
        while ($mvCol = $motorpoolVehicleColumnsResult->fetch_assoc()) {
            $motorpoolVehicleColumns[] = $mvCol['Field'];
        }
    }

    $motorpoolIdExpr = in_array('id', $motorpoolVehicleColumns, true) ? 'id' : (in_array('vehicle_id', $motorpoolVehicleColumns, true) ? 'vehicle_id' : '0');
    $motorpoolTypeParts = [];
    foreach (['vehicle_type', 'vehicle_category', 'make_brand', 'classification', 'body_type'] as $typeCol) {
        if (in_array($typeCol, $motorpoolVehicleColumns, true)) {
            $motorpoolTypeParts[] = "NULLIF(TRIM(`$typeCol`), '')";
        }
    }
    $motorpoolTypeExpr = !empty($motorpoolTypeParts) ? 'COALESCE(' . implode(', ', $motorpoolTypeParts) . ", 'Vehicle')" : "'Vehicle'";
    $motorpoolPlateExpr = in_array('plate_no', $motorpoolVehicleColumns, true) ? 'plate_no' : (in_array('plate_number', $motorpoolVehicleColumns, true) ? 'plate_number' : "''");
    $motorpoolBranchCondition = "";
    if (!$view_all_branches && $branch_id > 0 && in_array('branch_id', $motorpoolVehicleColumns, true)) {
        $motorpoolBranchCondition = " WHERE branch_id = " . (int)$branch_id;
    }

    $delivery_vehicles_query = "SELECT `$motorpoolIdExpr` AS vehicle_id, $motorpoolTypeExpr AS vehicle_type, `$motorpoolPlateExpr` AS plate_number FROM motorpool_vehicles $motorpoolBranchCondition ORDER BY vehicle_type ASC, plate_number ASC";
    $delivery_vehicles_result = $conn->query($delivery_vehicles_query);
    if ($delivery_vehicles_result) {
        $delivery_vehicles = $delivery_vehicles_result->fetch_all(MYSQLI_ASSOC);
    } else {
        error_log("Motorpool vehicles query error: " . $conn->error);
    }
} else {
    error_log("motorpool_vehicles table was not found. Delivery vehicle list is empty.");
}

// ===== SALES ORDER TAB EDIT MODAL: DRIVER / VEHICLE DROPDOWN DATA =====
// Same purpose as sales_order.php: group drivers by pending deliveries and load Motorpool vehicles.
$available_drivers = [];
if (amgcOrderProductTableExists($conn, 'drivers')) {
    $available_drivers_query = "
        SELECT
            d.driver_id,
            d.driver_name,
            COALESCE(d.status, 'active') AS status,
            (
                SELECT COUNT(*)
                FROM pick_lists pl
                JOIN sales_orders so ON pl.so_id = so.so_id
                WHERE pl.driver_id = d.driver_id
                  AND so.order_status IN ('confirmed', 'processing', 'ready', 'in_transit')
                  AND pl.pick_status NOT IN ('completed', 'cancelled')
            ) AS pending_deliveries,
            (
                SELECT COUNT(*)
                FROM trip_tickets tt
                WHERE tt.driver_id = d.driver_id
                  AND tt.trip_status = 'in-progress'
            ) AS active_trips
        FROM drivers d
        WHERE COALESCE(d.status, 'active') = 'active'
    ";
    if (!$view_all_branches && $branch_id > 0 && amgcColumnExists($conn, 'drivers', 'branch_id')) {
        $available_drivers_query .= " AND (d.branch_id = " . (int)$branch_id . " OR d.branch_id IS NULL OR d.branch_id = 0)";
    }
    $available_drivers_query .= " HAVING active_trips = 0 ORDER BY pending_deliveries DESC, d.driver_name ASC";
    $available_drivers_result = $conn->query($available_drivers_query);
    if ($available_drivers_result) {
        $available_drivers = $available_drivers_result->fetch_all(MYSQLI_ASSOC);
    }
}
$drivers_with_pending = array_values(array_filter($available_drivers, function($d) {
    return (int)($d['pending_deliveries'] ?? 0) > 0;
}));
$available_drivers_without_pending = array_values(array_filter($available_drivers, function($d) {
    return (int)($d['pending_deliveries'] ?? 0) === 0;
}));

$available_vehicles = [];
if (amgcOrderProductTableExists($conn, 'motorpool_vehicles')) {
    $mv_cols = [];
    $mv_cols_res = $conn->query("SHOW COLUMNS FROM motorpool_vehicles");
    if ($mv_cols_res) { while ($c = $mv_cols_res->fetch_assoc()) $mv_cols[] = $c['Field']; }
    $mv_id_expr = in_array('id', $mv_cols, true) ? 'id' : (in_array('vehicle_id', $mv_cols, true) ? 'vehicle_id' : '0');
    $mv_type_parts = [];
    foreach (['vehicle_type','vehicle_category','make_brand','classification','body_type'] as $col) {
        if (in_array($col, $mv_cols, true)) $mv_type_parts[] = "NULLIF(TRIM(`$col`), '')";
    }
    $mv_type_expr = $mv_type_parts ? 'COALESCE(' . implode(', ', $mv_type_parts) . ", 'Motorpool Vehicle')" : "'Motorpool Vehicle'";
    $mv_plate_expr = in_array('plate_no', $mv_cols, true) ? 'plate_no' : (in_array('plate_number', $mv_cols, true) ? 'plate_number' : (in_array('vehicle_id', $mv_cols, true) ? 'vehicle_id' : "''"));
    $mv_status_condition = in_array('status', $mv_cols, true) ? " AND LOWER(TRIM(COALESCE(status, 'active'))) = 'active'" : "";
    $mv_branch_condition = (!$view_all_branches && $branch_id > 0 && in_array('branch_id', $mv_cols, true)) ? " AND COALESCE(branch_id, 0) = " . (int)$branch_id : "";
    $available_vehicles_query = "
        SELECT `$mv_id_expr` AS vehicle_id,
               $mv_type_expr AS vehicle_type,
               COALESCE(NULLIF(TRIM(`$mv_plate_expr`), ''), CONCAT('Vehicle #', `$mv_id_expr`)) AS plate_number
        FROM motorpool_vehicles
        WHERE 1=1 $mv_status_condition $mv_branch_condition
        ORDER BY plate_number ASC, vehicle_type ASC
    ";
    $available_vehicles_result = $conn->query($available_vehicles_query);
    if ($available_vehicles_result) {
        $available_vehicles = $available_vehicles_result->fetch_all(MYSQLI_ASSOC);
    }
} else {
    $available_vehicles = $delivery_vehicles ?? [];
}

// Robust fallback for embedded Sales Order edit modal dropdowns.
// If the stricter sales_order.php-style filters return empty because of branch/status column differences,
// still show active driver and Motorpool vehicle choices instead of an empty dropdown.
if (empty($available_drivers) && !empty($delivery_drivers)) {
    $available_drivers = array_map(function($d) {
        $d['pending_deliveries'] = $d['pending_deliveries'] ?? 0;
        $d['active_trips'] = $d['active_trips'] ?? 0;
        return $d;
    }, $delivery_drivers);
    $drivers_with_pending = [];
    $available_drivers_without_pending = $available_drivers;
}
if (empty($available_vehicles) && !empty($delivery_vehicles)) {
    $available_vehicles = $delivery_vehicles;
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
        $billing_type = isset($_POST['billing_type']) && strtolower(trim($_POST['billing_type'])) === 'credit' ? 'credit' : 'invoice';
        $is_credit_order = ($billing_type === 'credit');
        $is_recurring = isset($_POST['is_recurring']) && (string)$_POST['is_recurring'] === '1' ? 1 : 0;
        $recurring_every = $is_recurring ? max(1, (int)($_POST['recurring_every'] ?? 1)) : null;
        $recurring_period = $is_recurring ? strtolower(trim((string)($_POST['recurring_period'] ?? 'month'))) : null;
        $recurring_until = $is_recurring ? trim((string)($_POST['recurring_until'] ?? '')) : '';
        if ($is_recurring && !in_array($recurring_period, ['day', 'week', 'month', 'year'], true)) {
            throw new Exception('Please select a valid recurring period.');
        }
        if ($is_recurring && $recurring_until === '') {
            throw new Exception('Until Date is required for a recurring invoice.');
        }
        if ($is_recurring) {
            $recurring_until_ts = strtotime($recurring_until);
            if ($recurring_until_ts === false) {
                throw new Exception('Please select a valid Until Date.');
            }
            if ($recurring_until_ts < strtotime(date('Y-m-d'))) {
                throw new Exception('Until Date cannot be earlier than today.');
            }
        }
        $recurring_until_for_bind = ($is_recurring && $recurring_until !== '') ? $recurring_until : null;
        $recurrence_group = $is_recurring ? ('INV-REC-' . date('YmdHis') . '-' . mt_rand(1000, 9999)) : null;
        $collect_payment = !$is_credit_order && isset($_POST['collect_payment']) && (string)$_POST['collect_payment'] === '1';
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
        $payment_amount = 0.00;

        if (!in_array($fulfillment_type, ['pickup', 'delivery'], true)) {
            $fulfillment_type = 'pickup';
        }
        if ($is_credit_order) {
            $fulfillment_type = 'pickup';
            $collect_payment = false;
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
                throw new Exception('Please select a vehicle registered in Motorpool. Adding a vehicle from this page is not allowed.');
            }

            if ($delivery_vehicle_id > 0) {
                if (!amgcOrderProductTableExists($conn, 'motorpool_vehicles')) {
                    throw new Exception('Motorpool vehicles table was not found. Please register the vehicle in Motorpool first.');
                }

                $motorpoolVehicleColumns = [];
                $motorpoolVehicleColumnsResult = $conn->query("SHOW COLUMNS FROM motorpool_vehicles");
                if ($motorpoolVehicleColumnsResult) {
                    while ($mvCol = $motorpoolVehicleColumnsResult->fetch_assoc()) {
                        $motorpoolVehicleColumns[] = $mvCol['Field'];
                    }
                }

                $motorpoolIdCol = in_array('id', $motorpoolVehicleColumns, true) ? 'id' : (in_array('vehicle_id', $motorpoolVehicleColumns, true) ? 'vehicle_id' : '');
                if ($motorpoolIdCol === '') {
                    throw new Exception('Motorpool vehicle ID column was not found.');
                }

                $motorpoolTypeParts = [];
                foreach (['vehicle_type', 'vehicle_category', 'make_brand', 'classification', 'body_type'] as $typeCol) {
                    if (in_array($typeCol, $motorpoolVehicleColumns, true)) {
                        $motorpoolTypeParts[] = "NULLIF(TRIM(`$typeCol`), '')";
                    }
                }
                $motorpoolTypeExpr = !empty($motorpoolTypeParts) ? 'COALESCE(' . implode(', ', $motorpoolTypeParts) . ", 'Vehicle')" : "'Vehicle'";
                $motorpoolPlateExpr = in_array('plate_no', $motorpoolVehicleColumns, true) ? 'plate_no' : (in_array('plate_number', $motorpoolVehicleColumns, true) ? 'plate_number' : "''");

                $vehicleSql = "SELECT $motorpoolTypeExpr AS vehicle_type, `$motorpoolPlateExpr` AS plate_number FROM motorpool_vehicles WHERE `$motorpoolIdCol` = ?";
                if (!$view_all_branches && $branch_id > 0 && in_array('branch_id', $motorpoolVehicleColumns, true)) {
                    $vehicleSql .= " AND branch_id = " . (int)$branch_id;
                }
                $vehicleSql .= " LIMIT 1";

                $vehicle_stmt = $conn->prepare($vehicleSql);
                if (!$vehicle_stmt) throw new Exception('Database prepare error while loading Motorpool vehicle.');
                $vehicle_stmt->bind_param('i', $delivery_vehicle_id);
                $vehicle_stmt->execute();
                $vehicle_row = $vehicle_stmt->get_result()->fetch_assoc();
                $vehicle_stmt->close();

                if (!$vehicle_row) {
                    throw new Exception('Selected vehicle was not found in registered Motorpool vehicles.');
                }

                $assigned_vehicle_type = (string)$vehicle_row['vehicle_type'];
                $assigned_vehicle_plate = (string)$vehicle_row['plate_number'];
            } else {
                throw new Exception('Please select a registered Motorpool vehicle.');
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
        $atw_no = $is_credit_order ? '' : trim($_POST['atw_no'] ?? '');
        $gatepass_no = $is_credit_order ? '' : trim($_POST['gatepass_no'] ?? '');
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
        if (!$is_credit_order) {
            if ($gatepass_no === '') {
                throw new Exception('Gatepass No. is required.');
            }
            if (($atw_no !== '' && !preg_match('/^\d{1,6}$/', $atw_no)) || !preg_match('/^\d{1,6}$/', $gatepass_no)) {
                throw new Exception('ATW No. and Gatepass No. must be numbers only with a maximum of 6 digits.');
            }
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
        
        
        if (!preg_match('/^\d{1,6}$/', $manual_so_suffix)) {
            throw new Exception("Invalid SO number. Please enter numbers only with a maximum of 6 digits.");
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
        $has_billing_type_column = in_array('billing_type', $columns);
        $has_is_recurring_column = in_array('is_recurring', $columns);
        $has_recurring_every_column = in_array('recurring_every', $columns);
        $has_recurring_period_column = in_array('recurring_period', $columns);
        $has_recurring_until_column = in_array('recurring_until', $columns);
        $has_recurrence_group_column = in_array('recurrence_group', $columns);
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
        if ($has_billing_type_column) { $insert_fields[] = 'billing_type'; $insert_placeholders[] = '?'; $insert_types .= 's'; $insert_values[] = $billing_type; }
        if ($has_is_recurring_column) { $insert_fields[] = 'is_recurring'; $insert_placeholders[] = '?'; $insert_types .= 'i'; $insert_values[] = $is_recurring; }
        if ($has_recurring_every_column) { $insert_fields[] = 'recurring_every'; $insert_placeholders[] = '?'; $insert_types .= 'i'; $insert_values[] = $recurring_every; }
        if ($has_recurring_period_column) { $insert_fields[] = 'recurring_period'; $insert_placeholders[] = '?'; $insert_types .= 's'; $insert_values[] = $recurring_period; }
        if ($has_recurring_until_column) { $insert_fields[] = 'recurring_until'; $insert_placeholders[] = '?'; $insert_types .= 's'; $insert_values[] = $recurring_until_for_bind; }
        if ($has_recurrence_group_column) { $insert_fields[] = 'recurrence_group'; $insert_placeholders[] = '?'; $insert_types .= 's'; $insert_values[] = $recurrence_group; }
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
        $has_soi_ave_cost = in_array('ave_cost', $soi_columns, true);
        $has_soi_cogs_amount = in_array('cogs_amount', $soi_columns, true);
        $has_soi_gross_profit = in_array('gross_profit', $soi_columns, true);
        
        $updated_stock_data = [];
        $total_cogs = 0.00;
        
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
            $saved_so_item_id = (int)$conn->insert_id;
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
                SELECT iui.inventory_id, iui.current_inventory, COALESCE(iui.unit_cost, 0) AS unit_cost, ut.unit_type_id, ut.unit_type_name
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
                    SELECT iui.inventory_id, iui.current_inventory, COALESCE(iui.unit_cost, 0) AS unit_cost, ut.unit_type_id, ut.unit_type_name
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

            $line_unit_cost = (float)($stock_row['unit_cost'] ?? 0);
            if ($line_unit_cost <= 0) {
                $line_unit_cost = amgcOrderProductGetItemCostForAccounting($conn, $item_id, $unit_type, $branch_id);
            }
            $line_cogs_amount = round(max(0, $quantity * $line_unit_cost), 2);
            $line_gross_profit = round($line_order_amount - $line_cogs_amount, 2);
            $total_cogs += $line_cogs_amount;

            if ($saved_so_item_id > 0 && ($has_soi_ave_cost || $has_soi_cogs_amount || $has_soi_gross_profit)) {
                $cost_update_fields = [];
                $cost_update_types = '';
                $cost_update_values = [];
                if ($has_soi_ave_cost) { $cost_update_fields[] = 'ave_cost = ?'; $cost_update_types .= 'd'; $cost_update_values[] = $line_unit_cost; }
                if ($has_soi_cogs_amount) { $cost_update_fields[] = 'cogs_amount = ?'; $cost_update_types .= 'd'; $cost_update_values[] = $line_cogs_amount; }
                if ($has_soi_gross_profit) { $cost_update_fields[] = 'gross_profit = ?'; $cost_update_types .= 'd'; $cost_update_values[] = $line_gross_profit; }
                if (!empty($cost_update_fields)) {
                    $cost_update_types .= 'i';
                    $cost_update_values[] = $saved_so_item_id;
                    $cost_update_sql = 'UPDATE sales_order_items SET ' . implode(', ', $cost_update_fields) . ' WHERE so_item_id = ?';
                    $cost_update_stmt = $conn->prepare($cost_update_sql);
                    if ($cost_update_stmt) {
                        $cost_update_stmt->bind_param($cost_update_types, ...$cost_update_values);
                        $cost_update_stmt->execute();
                        $cost_update_stmt->close();
                    }
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
                    $bank_name = null;
                    $bank_branch = trim($_POST['bank_branch'] ?? '');
                    $check_number = trim($_POST['check_number'] ?? '');
                    $payment_amount = isset($_POST['payment_amount']) ? (float)preg_replace('/[^0-9.]/', '', (string)$_POST['payment_amount']) : 0.00;
                    if ($check_date === '' || $bank_branch === '' || $check_number === '') {
                        throw new Exception('All check details are required.');
                    }
                    if ($payment_amount <= 0) {
                        throw new Exception('Payment Amount is required.');
                    }
                    if (abs($payment_amount - $total_amount) > 0.01) {
                        throw new Exception('Payment Amount must be equal to the grand total.');
                    }
                    $reference_number = $check_number;
                } elseif ($payment_method === 'online_transfer') {
                    $reference_number = trim($_POST['reference_number'] ?? '');
                    $bank_name = trim($_POST['online_bank_name'] ?? '');
                    $bank_branch = trim($_POST['online_bank_branch'] ?? '');
                    $payment_amount = isset($_POST['payment_amount']) ? (float)preg_replace('/[^0-9.]/', '', (string)$_POST['payment_amount']) : 0.00;
                    if ($reference_number === '' || $bank_name === '') {
                        throw new Exception('Reference number and Bank/Wallet are required.');
                    }
                    if ($payment_amount <= 0) {
                        throw new Exception('Payment Amount is required.');
                    }
                    if (abs($payment_amount - $total_amount) > 0.01) {
                        throw new Exception('Payment Amount must be equal to the grand total.');
                    }
                }

                if ($payment_method === 'cash') {
                    $payment_amount = $total_amount;
                }

                $invoice_id = amgcOrderProductFindOrCreateInvoice($conn, $so_id, $customer_id, $branch_id, $total_amount, $user_id, true);
                $payment_id = amgcOrderProductInsertPayment($conn, $invoice_id, $so_id, $customer_id, $branch_id, $user_id, $payment_method, $payment_amount, $reference_number, $check_date, $bank_name, $bank_branch, $check_number, $cash_tendered, $cash_change);

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

        if ($invoice_id > 0) {
            $accounting_document_no = ($document_type === 'SI' && $si_number !== '') ? $si_number : $so_number;
            amgcOrderProductPostInvoiceAccounting($conn, $so_id, $invoice_id, $customer_id, $branch_id, $user_id, $total_amount, $total_cogs, $accounting_document_no);
        }

        amgcOrderProductRecalcCustomerCreditUsed($conn, $customer_id);

        $recurring_task_count = 0;
        if ($is_recurring) {
            $recurring_task_count = amgcOrderProductCreateRecurringInvoiceTasks(
                $conn,
                $so_id,
                $so_number,
                $customer_name,
                $branch_id,
                $user_id,
                substr($order_date, 0, 10),
                $recurring_every,
                $recurring_period,
                $recurring_until_for_bind,
                $recurrence_group
            );
        }
        
        $conn->commit();
        
        echo json_encode([
            'success' => true, 
            'message' => ($fulfillment_type === 'pickup' ? ($collect_payment ? 'Pickup order delivered and payment sent to undeposited payments!' : 'Pickup order confirmed. Payment is now available in Collections.') : 'Delivery order confirmed and assigned successfully!') . ($recurring_task_count > 0 ? ' ' . $recurring_task_count . ' recurring invoice schedule(s) were added to Tasks.' : ''), 
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
            'total_amount' => $total_amount,
            'recurring_task_count' => $recurring_task_count
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



// Compatibility aliases for Sales Order tab actions copied from sales_order.php
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_order') {
    $_POST['action'] = 'delete_sales_order_from_tab';
    if (isset($_POST['order_id']) && !isset($_POST['so_id'])) {
        $_POST['so_id'] = $_POST['order_id'];
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_order') {
    $_POST['action'] = 'update_sales_order_from_tab';
    if (isset($_POST['order_id']) && !isset($_POST['so_id'])) {
        $_POST['so_id'] = $_POST['order_id'];
    }
}

// ============= HANDLE UPDATE / DELETE SALES ORDER FROM EMBEDDED TAB =============
// Dedicated SI update from Sales Order tab action button.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_sales_order_si_from_tab') {
    header('Content-Type: application/json');
    try {
        $conn->begin_transaction();
        $so_id = (int)($_POST['so_id'] ?? 0);
        $si_number = trim($_POST['si_number'] ?? '');
        $registered_business_name = trim($_POST['registered_business_name'] ?? '');
        $tin = trim($_POST['tin'] ?? '');
        $business_address = trim($_POST['business_address'] ?? '');
        if ($so_id <= 0) throw new Exception('Invalid sales order.');
        if ($si_number === '' || $registered_business_name === '' || $tin === '' || $business_address === '') {
            throw new Exception('Please complete SI Number, Registered Business Name, TIN, and Address.');
        }
        $check_stmt = $conn->prepare("SELECT so_id, order_status, branch_id, si_number FROM sales_orders WHERE so_id = ? LIMIT 1");
        if (!$check_stmt) throw new Exception('Prepare failed: ' . $conn->error);
        $check_stmt->bind_param('i', $so_id);
        $check_stmt->execute();
        $existing = $check_stmt->get_result()->fetch_assoc();
        $check_stmt->close();
        if (!$existing) throw new Exception('Sales order not found.');
        if (!$view_all_branches && amgcColumnExists($conn, 'sales_orders', 'branch_id') && (int)$branch_id > 0 && (int)($existing['branch_id'] ?? 0) !== (int)$branch_id) {
            throw new Exception('Order not found or access denied.');
        }
        $current_status = strtolower(trim((string)($existing['order_status'] ?? 'pending')));
        if (!in_array($current_status, ['pending','confirmed','delivered'], true)) {
            throw new Exception('SI can only be added to Pending, Confirmed, or Delivered sales orders.');
        }
        $existing_si_number = trim((string)($existing['si_number'] ?? ''));
        if ($existing_si_number !== '') {
            throw new Exception('This sales order already has an SI number and can no longer be edited.');
        }
        $si_attachments = amgcOrderProductSaveSIAttachments($so_id);
        if (empty($si_attachments)) {
            throw new Exception('SI Attachments are required. Please upload at least one SI attachment.');
        }
        $si_attachments_json = json_encode($si_attachments, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (amgcColumnExists($conn, 'sales_orders', 'si_number')) {
            $dup_stmt = $conn->prepare("SELECT so_id FROM sales_orders WHERE si_number = ? AND so_id <> ? LIMIT 1");
            if ($dup_stmt) {
                $dup_stmt->bind_param('si', $si_number, $so_id);
                $dup_stmt->execute();
                $dup_row = $dup_stmt->get_result()->fetch_assoc();
                $dup_stmt->close();
                if ($dup_row) throw new Exception('SI number already exists. Please enter another SI number.');
            }
        }
        $fields = []; $types = ''; $values = [];
        foreach (['document_type'=>'SI','si_number'=>$si_number,'registered_business_name'=>$registered_business_name,'tin'=>$tin,'business_address'=>$business_address] as $col => $val) {
            if (amgcColumnExists($conn, 'sales_orders', $col)) { $fields[] = "$col = ?"; $types .= 's'; $values[] = $val; }
        }
        if ($si_attachments_json !== null && amgcColumnExists($conn, 'sales_orders', 'si_attachments')) { $fields[] = "si_attachments = ?"; $types .= 's'; $values[] = $si_attachments_json; }
        if (empty($fields)) throw new Exception('SI columns are not available in sales_orders table.');
        $types .= 'i'; $values[] = $so_id;
        $stmt = $conn->prepare('UPDATE sales_orders SET ' . implode(', ', $fields) . ' WHERE so_id = ?');
        if (!$stmt) throw new Exception('Prepare SI update failed: ' . $conn->error);
        $stmt->bind_param($types, ...$values);
        if (!$stmt->execute()) throw new Exception('Failed to save SI details: ' . $stmt->error);
        $stmt->close();
        foreach (['invoices','payments'] as $targetTable) {
            if (amgcOrderProductTableExists($conn, $targetTable) && amgcColumnExists($conn, $targetTable, 'so_id')) {
                $tFields = []; $tTypes = ''; $tValues = [];
                foreach (['si_number'=>$si_number,'registered_business_name'=>$registered_business_name,'tin'=>$tin,'business_address'=>$business_address] as $col => $val) {
                    if (amgcColumnExists($conn, $targetTable, $col)) { $tFields[] = "$col = ?"; $tTypes .= 's'; $tValues[] = $val; }
                }
                if ($si_attachments_json !== null && amgcColumnExists($conn, $targetTable, 'si_attachments')) { $tFields[] = "si_attachments = ?"; $tTypes .= 's'; $tValues[] = $si_attachments_json; }
                if (!empty($tFields)) {
                    $tTypes .= 'i'; $tValues[] = $so_id;
                    $tStmt = $conn->prepare('UPDATE ' . $targetTable . ' SET ' . implode(', ', $tFields) . ' WHERE so_id = ?');
                    if ($tStmt) { $tStmt->bind_param($tTypes, ...$tValues); $tStmt->execute(); $tStmt->close(); }
                }
            }
        }
        $conn->commit();
        echo json_encode(['success' => true, 'message' => 'SI details saved successfully.']);
        exit;
    } catch (Throwable $e) {
        try { $conn->rollback(); } catch (Throwable $rollbackError) {}
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_sales_order_from_tab') {
    header('Content-Type: application/json');

    try {
        $conn->begin_transaction();

        $so_id = (int)($_POST['so_id'] ?? 0);
        $order_date = trim($_POST['order_date'] ?? ($_POST['created_at'] ?? ''));
        $order_status = strtolower(trim($_POST['order_status'] ?? 'pending'));
        $si_number = trim($_POST['si_number'] ?? '');
        $registered_business_name = trim($_POST['registered_business_name'] ?? '');
        $tin = trim($_POST['tin'] ?? '');
        $business_address = trim($_POST['business_address'] ?? '');
        $edited_items_json = trim($_POST['edited_items'] ?? '');

        if ($so_id <= 0) {
            throw new Exception('Invalid sales order.');
        }
        if ($order_date === '') {
            $order_date = date('Y-m-d');
        }
        if (!in_array($order_status, ['pending','confirmed','processing','ready','in_transit','delivered','cancelled'], true)) {
            $order_status = 'pending';
        }

        $check_sql = "SELECT so_id, order_status, branch_id, si_number, registered_business_name, tin, business_address FROM sales_orders WHERE so_id = ? LIMIT 1";
        $check_stmt = $conn->prepare($check_sql);
        if (!$check_stmt) throw new Exception('Prepare failed: ' . $conn->error);
        $check_stmt->bind_param('i', $so_id);
        $check_stmt->execute();
        $existing = $check_stmt->get_result()->fetch_assoc();
        $check_stmt->close();
        if (!$existing) throw new Exception('Sales order not found.');

        if (!$view_all_branches && amgcColumnExists($conn, 'sales_orders', 'branch_id') && (int)$branch_id > 0) {
            if ((int)($existing['branch_id'] ?? 0) !== (int)$branch_id) {
                throw new Exception('Order not found or access denied.');
            }
        }

        $items = json_decode($edited_items_json, true);
        if (is_array($items)) {
            $total_amount = 0.00;
            foreach ($items as $item) {
                $so_item_id = (int)($item['so_item_id'] ?? 0);
                $qty = max(0, (float)($item['quantity_ordered'] ?? 0));
                $price = max(0, (float)($item['unit_price'] ?? 0));
                if ($so_item_id <= 0) continue;
                $line_total = $qty * $price;
                $total_amount += $line_total;

                $fields = ['quantity_ordered = ?', 'unit_price = ?'];
                $types = 'dd';
                $values = [$qty, $price];

                if (amgcColumnExists($conn, 'sales_order_items', 'gross_price')) { $fields[] = 'gross_price = ?'; $types .= 'd'; $values[] = $price; }
                if (amgcColumnExists($conn, 'sales_order_items', 'net_price')) { $fields[] = 'net_price = ?'; $types .= 'd'; $values[] = $price; }
                if (amgcColumnExists($conn, 'sales_order_items', 'order_amount')) { $fields[] = 'order_amount = ?'; $types .= 'd'; $values[] = $line_total; }
                if (amgcColumnExists($conn, 'sales_order_items', 'line_total')) { $fields[] = 'line_total = ?'; $types .= 'd'; $values[] = $line_total; }
                if (amgcColumnExists($conn, 'sales_order_items', 'discount_amount')) { $fields[] = 'discount_amount = 0'; }
                if (amgcColumnExists($conn, 'sales_order_items', 'total_discount')) { $fields[] = 'total_discount = 0'; }

                $types .= 'ii';
                $values[] = $so_item_id;
                $values[] = $so_id;
                $update_item_sql = 'UPDATE sales_order_items SET ' . implode(', ', $fields) . ' WHERE so_item_id = ? AND so_id = ?';
                $update_item_stmt = $conn->prepare($update_item_sql);
                if (!$update_item_stmt) throw new Exception('Prepare item update failed: ' . $conn->error);
                $update_item_stmt->bind_param($types, ...$values);
                if (!$update_item_stmt->execute()) throw new Exception('Failed to update item: ' . $update_item_stmt->error);
                $update_item_stmt->close();
            }

            $order_fields = ['total_amount = ?'];
            $order_types = 'd';
            $order_values = [$total_amount];
            if (amgcColumnExists($conn, 'sales_orders', 'order_amount')) { $order_fields[] = 'order_amount = ?'; $order_types .= 'd'; $order_values[] = $total_amount; }
            if (amgcColumnExists($conn, 'sales_orders', 'discount_amount')) { $order_fields[] = 'discount_amount = 0'; }
            if (amgcColumnExists($conn, 'sales_orders', 'total_discount_amount')) { $order_fields[] = 'total_discount_amount = 0'; }
            $order_types .= 'i';
            $order_values[] = $so_id;
            $update_total_stmt = $conn->prepare('UPDATE sales_orders SET ' . implode(', ', $order_fields) . ' WHERE so_id = ?');
            if ($update_total_stmt) {
                $update_total_stmt->bind_param($order_types, ...$order_values);
                $update_total_stmt->execute();
                $update_total_stmt->close();
            }
        }

        // SI details are intentionally not updated here.
        // Once an SI number is saved from the dedicated SI action button, it becomes locked.
        $fields = ['order_date = ?', 'order_status = ?'];
        $types = 'ss';
        $values = [$order_date, $order_status];
        $types .= 'i';
        $values[] = $so_id;
        $update_order_sql = 'UPDATE sales_orders SET ' . implode(', ', $fields) . ' WHERE so_id = ?';
        $update_order_stmt = $conn->prepare($update_order_sql);
        if (!$update_order_stmt) throw new Exception('Prepare order update failed: ' . $conn->error);
        $update_order_stmt->bind_param($types, ...$values);
        if (!$update_order_stmt->execute()) throw new Exception('Failed to update sales order: ' . $update_order_stmt->error);
        $update_order_stmt->close();

        // Same confirmation behavior as sales_order.php: driver and vehicle are required when confirming a pending order.
        $old_status = strtolower(trim((string)($existing['order_status'] ?? 'pending')));
        $selected_driver_id = 0;
        foreach (['driver_id', 'delivery_driver_id', 'edit_driver_id'] as $driver_post_key) {
            if (isset($_POST[$driver_post_key]) && $_POST[$driver_post_key] !== '') {
                $selected_driver_id = (int)$_POST[$driver_post_key];
                break;
            }
        }
        $selected_vehicle_id = 0;
        foreach (['vehicle_id', 'delivery_vehicle_id', 'edit_vehicle_id'] as $vehicle_post_key) {
            if (isset($_POST[$vehicle_post_key]) && $_POST[$vehicle_post_key] !== '') {
                $selected_vehicle_id = (int)$_POST[$vehicle_post_key];
                break;
            }
        }
        $order_branch_id = (int)($existing['branch_id'] ?? $branch_id);

        $fulfillment_type = 'delivery';
        if (amgcColumnExists($conn, 'sales_orders', 'fulfillment_type')) {
            $fulfillment_stmt = $conn->prepare("SELECT COALESCE(NULLIF(TRIM(fulfillment_type), ''), 'delivery') AS fulfillment_type FROM sales_orders WHERE so_id = ? LIMIT 1");
            if ($fulfillment_stmt) {
                $fulfillment_stmt->bind_param('i', $so_id);
                $fulfillment_stmt->execute();
                $fulfillment_row = $fulfillment_stmt->get_result()->fetch_assoc();
                $fulfillment_stmt->close();
                $fulfillment_type = strtolower(trim((string)($fulfillment_row['fulfillment_type'] ?? 'delivery')));
            }
        }
        $requires_delivery_assignment = !in_array($fulfillment_type, ['pickup', 'pick_up', 'walk-in', 'walkin'], true);

        if ($order_status === 'confirmed' && $old_status === 'pending' && $requires_delivery_assignment) {
            if ($selected_driver_id <= 0) {
                throw new Exception('Please select a driver for this delivery.');
            }
            if ($selected_vehicle_id <= 0) {
                throw new Exception('Please select a vehicle for this delivery.');
            }

            if (amgcOrderProductTableExists($conn, 'drivers')) {
                $driver_check_sql = "SELECT driver_id FROM drivers WHERE driver_id = ? AND COALESCE(status, 'active') = 'active'";
                if (!$view_all_branches && $order_branch_id > 0 && amgcColumnExists($conn, 'drivers', 'branch_id')) {
                    $driver_check_sql .= " AND (branch_id = ? OR branch_id IS NULL OR branch_id = 0)";
                    $driver_check_stmt = $conn->prepare($driver_check_sql);
                    $driver_check_stmt->bind_param('ii', $selected_driver_id, $order_branch_id);
                } else {
                    $driver_check_stmt = $conn->prepare($driver_check_sql);
                    $driver_check_stmt->bind_param('i', $selected_driver_id);
                }
                if ($driver_check_stmt) {
                    $driver_check_stmt->execute();
                    if ($driver_check_stmt->get_result()->num_rows === 0) {
                        throw new Exception('Selected driver is not available or does not belong to this branch.');
                    }
                    $driver_check_stmt->close();
                }
            }

            $picklist_id = 0;
            if (amgcOrderProductTableExists($conn, 'pick_lists')) {
                $existing_pick_stmt = $conn->prepare("SELECT pick_list_id FROM pick_lists WHERE so_id = ? ORDER BY pick_list_id DESC LIMIT 1");
                if ($existing_pick_stmt) {
                    $existing_pick_stmt->bind_param('i', $so_id);
                    $existing_pick_stmt->execute();
                    $pick_row = $existing_pick_stmt->get_result()->fetch_assoc();
                    $existing_pick_stmt->close();
                    $picklist_id = (int)($pick_row['pick_list_id'] ?? 0);
                }
                if ($picklist_id <= 0) {
                    $pick_list_number = 'PL-' . date('Ymd') . '-' . str_pad((string)$so_id, 5, '0', STR_PAD_LEFT);
                    $picklist_stmt = $conn->prepare("INSERT INTO pick_lists (pick_list_number, so_id, branch_id, driver_id, pick_status, created_at) VALUES (?, ?, ?, ?, 'open', NOW())");
                    if ($picklist_stmt) {
                        $picklist_stmt->bind_param('siii', $pick_list_number, $so_id, $order_branch_id, $selected_driver_id);
                        if (!$picklist_stmt->execute()) throw new Exception('Failed to create pick list: ' . $picklist_stmt->error);
                        $picklist_id = (int)$conn->insert_id;
                        $picklist_stmt->close();
                    }
                }
                if ($picklist_id > 0 && amgcOrderProductTableExists($conn, 'pick_list_items')) {
                    $del_pick_items = $conn->prepare("DELETE FROM pick_list_items WHERE pick_list_id = ?");
                    if ($del_pick_items) { $del_pick_items->bind_param('i', $picklist_id); $del_pick_items->execute(); $del_pick_items->close(); }
                    $ins_pick_items = $conn->prepare("INSERT INTO pick_list_items (pick_list_id, item_id, quantity_to_pick) SELECT ?, item_id, quantity_ordered FROM sales_order_items WHERE so_id = ? AND quantity_ordered > 0");
                    if ($ins_pick_items) { $ins_pick_items->bind_param('ii', $picklist_id, $so_id); $ins_pick_items->execute(); $ins_pick_items->close(); }
                }
            }

            if (amgcOrderProductTableExists($conn, 'trip_tickets')) {
                $existing_trip_stmt = $conn->prepare("SELECT trip_id FROM trip_tickets WHERE so_id = ? LIMIT 1");
                $has_existing_trip = false;
                if ($existing_trip_stmt) {
                    $existing_trip_stmt->bind_param('i', $so_id);
                    $existing_trip_stmt->execute();
                    $has_existing_trip = (bool)$existing_trip_stmt->get_result()->fetch_assoc();
                    $existing_trip_stmt->close();
                }
                if (!$has_existing_trip) {
                    $trip_ticket_number = 'TT-' . date('Ymd') . '-' . str_pad((string)$so_id, 5, '0', STR_PAD_LEFT);
                    $trip_date = date('Y-m-d');
                    $trip_fields = ['trip_number', 'driver_id', 'branch_id', 'trip_date', 'trip_status', 'created_by', 'created_at'];
                    $trip_placeholders = ['?', '?', '?', '?', "'planned'", '?', 'NOW()'];
                    $trip_types = 'siisi';
                    $trip_values = [$trip_ticket_number, $selected_driver_id, $order_branch_id, $trip_date, $user_id];
                    if (amgcColumnExists($conn, 'trip_tickets', 'vehicle_id')) { $trip_fields[] = 'vehicle_id'; $trip_placeholders[] = '?'; $trip_types .= 'i'; $trip_values[] = $selected_vehicle_id; }
                    if (amgcColumnExists($conn, 'trip_tickets', 'so_id')) { $trip_fields[] = 'so_id'; $trip_placeholders[] = '?'; $trip_types .= 'i'; $trip_values[] = $so_id; }
                    if (amgcColumnExists($conn, 'trip_tickets', 'picklist_id')) { $trip_fields[] = 'picklist_id'; $trip_placeholders[] = '?'; $trip_types .= 'i'; $trip_values[] = $picklist_id; }
                    $trip_sql = 'INSERT INTO trip_tickets (' . implode(', ', $trip_fields) . ') VALUES (' . implode(', ', $trip_placeholders) . ')';
                    $trip_stmt = $conn->prepare($trip_sql);
                    if ($trip_stmt) {
                        $trip_stmt->bind_param($trip_types, ...$trip_values);
                        if (!$trip_stmt->execute()) throw new Exception('Failed to create trip ticket: ' . $trip_stmt->error);
                        $trip_stmt->close();
                    }
                }
            }
        }

        if (function_exists('amgcSyncSalesOrderComputedSnapshots')) {
            @amgcSyncSalesOrderComputedSnapshots($conn, $so_id);
        }

        $conn->commit();
        echo json_encode(['success' => true, 'message' => 'Sales order updated successfully.']);
        exit;
    } catch (Throwable $e) {
        if ($conn) { @$conn->rollback(); }
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_sales_order_from_tab') {
    header('Content-Type: application/json');

    try {
        $conn->begin_transaction();
        $so_id = (int)($_POST['so_id'] ?? 0);
        if ($so_id <= 0) throw new Exception('Invalid sales order.');

        $check_stmt = $conn->prepare("SELECT so_id, order_status, branch_id FROM sales_orders WHERE so_id = ? LIMIT 1");
        if (!$check_stmt) throw new Exception('Prepare failed: ' . $conn->error);
        $check_stmt->bind_param('i', $so_id);
        $check_stmt->execute();
        $order = $check_stmt->get_result()->fetch_assoc();
        $check_stmt->close();
        if (!$order) throw new Exception('Sales order not found.');
        if (strtolower(trim($order['order_status'] ?? '')) !== 'pending') {
            throw new Exception('Only pending sales orders can be deleted.');
        }
        if (!$view_all_branches && amgcColumnExists($conn, 'sales_orders', 'branch_id') && (int)$branch_id > 0 && (int)($order['branch_id'] ?? 0) !== (int)$branch_id) {
            throw new Exception('Order not found or access denied.');
        }

        foreach (['pick_lists', 'invoices', 'trip_tickets'] as $tbl) {
            if (amgcOrderProductTableExists($conn, $tbl) && amgcColumnExists($conn, $tbl, 'so_id')) {
                $chk = $conn->prepare("SELECT COUNT(*) AS cnt FROM `$tbl` WHERE so_id = ?");
                if ($chk) {
                    $chk->bind_param('i', $so_id);
                    $chk->execute();
                    $cnt = (int)($chk->get_result()->fetch_assoc()['cnt'] ?? 0);
                    $chk->close();
                    if ($cnt > 0) throw new Exception('Cannot delete order with existing related records.');
                }
            }
        }

        $del_items = $conn->prepare('DELETE FROM sales_order_items WHERE so_id = ?');
        if ($del_items) { $del_items->bind_param('i', $so_id); $del_items->execute(); $del_items->close(); }
        $del_order = $conn->prepare('DELETE FROM sales_orders WHERE so_id = ?');
        if (!$del_order) throw new Exception('Prepare delete failed: ' . $conn->error);
        $del_order->bind_param('i', $so_id);
        if (!$del_order->execute()) throw new Exception('Failed to delete sales order.');
        $del_order->close();

        $conn->commit();
        echo json_encode(['success' => true, 'message' => 'Sales order deleted successfully.']);
        exit;
    } catch (Throwable $e) {
        if ($conn) { @$conn->rollback(); }
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

// ============= HANDLE GET ORDER DETAILS (for modal) =============
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'get_order_details') {
    header('Content-Type: application/json');

    try {
        $order_id = (int)($_POST['order_id'] ?? 0);
        if ($order_id <= 0) {
            throw new Exception('Invalid order ID.');
        }

        $customer_group_select = amgcColumnExists($conn, 'customers', 'customer_group') ? "c.customer_group" : "'' AS customer_group";
        $credit_limit_select = amgcColumnExists($conn, 'customers', 'credit_limit') ? "COALESCE(c.credit_limit, 0) AS credit_limit" : "0 AS credit_limit";
        $credit_used_select = amgcColumnExists($conn, 'customers', 'credit_used') ? "COALESCE(c.credit_used, 0) AS credit_used" : "0 AS credit_used";
        $fulfillment_select = amgcColumnExists($conn, 'sales_orders', 'fulfillment_type') ? "so.fulfillment_type" : "'pickup' AS fulfillment_type";
        $billing_select = amgcColumnExists($conn, 'sales_orders', 'billing_type') ? "so.billing_type" : "'invoice' AS billing_type";

        $sql = "SELECT
                    so.so_id,
                    so.so_number,
                    so.document_type,
                    so.si_number,
                    so.registered_business_name,
                    so.tin,
                    so.business_address,
                    so.atw_no,
                    so.gatepass_no,
                    so.order_date,
                    so.created_at,
                    so.total_amount,
                    so.order_amount,
                    $fulfillment_select,
                    $billing_select,
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
                    so.payment_status,
                    so.branch_id,
                    c.customer_id,
                    c.customer_name,
                    c.store_name,
                    c.customer_code,
                    c.email,
                    c.phone_number,
                    c.address,
                    $customer_group_select,
                    $credit_limit_select,
                    $credit_used_select,
                    TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) as created_by,
                    b.branch_name
                FROM sales_orders so
                LEFT JOIN customers c ON so.customer_id = c.customer_id
                LEFT JOIN users u ON so.created_by = u.user_id
                LEFT JOIN users obau ON so.outstanding_balance_approved_by = obau.user_id
                LEFT JOIN branches b ON so.branch_id = b.branch_id
                WHERE so.so_id = ?";

        $stmt = $conn->prepare($sql);
        if (!$stmt) throw new Exception('Prepare failed: ' . $conn->error);
        $stmt->bind_param('i', $order_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows === 0) {
            echo json_encode(['success' => false, 'message' => 'Order not found']);
            exit;
        }
        $order = $result->fetch_assoc();
        $stmt->close();

        if (!$view_all_branches && amgcColumnExists($conn, 'sales_orders', 'branch_id') && (int)$branch_id > 0 && (int)($order['branch_id'] ?? 0) !== (int)$branch_id) {
            throw new Exception('Order not found or access denied.');
        }

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
        if (!$items_stmt) throw new Exception('Unable to load order items: ' . $conn->error);
        $items_stmt->bind_param('i', $order_id);
        $items_stmt->execute();
        $items = $items_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $items_stmt->close();

        $invoice = null;
        if (amgcOrderProductTableExists($conn, 'invoices')) {
            $invoice_so_filter = amgcColumnExists($conn, 'invoices', 'so_id') ? "i.so_id = ?" : "1=0";
            $inv_sql = "SELECT i.* FROM invoices i WHERE $invoice_so_filter ORDER BY i.invoice_id DESC LIMIT 1";
            $inv_stmt = $conn->prepare($inv_sql);
            if ($inv_stmt) {
                $inv_stmt->bind_param('i', $order_id);
                $inv_stmt->execute();
                $invoice = $inv_stmt->get_result()->fetch_assoc() ?: null;
                $inv_stmt->close();
            }
        }

        $payments = [];
        $payment_total = 0.00;
        if (amgcOrderProductTableExists($conn, 'payments')) {
            $payment_filters = [];
            if ($invoice && isset($invoice['invoice_id']) && amgcColumnExists($conn, 'payments', 'invoice_id')) {
                $payment_filters[] = "invoice_id = " . (int)$invoice['invoice_id'];
            }
            if (amgcColumnExists($conn, 'payments', 'so_id')) {
                $payment_filters[] = "so_id = " . (int)$order_id;
            }
            if (!empty($payment_filters)) {
                $pay_sql = "SELECT * FROM payments WHERE (" . implode(' OR ', $payment_filters) . ") ORDER BY payment_date DESC, payment_id DESC";
                $pay_res = $conn->query($pay_sql);
                if ($pay_res) {
                    while ($pay = $pay_res->fetch_assoc()) {
                        $payments[] = $pay;
                        if (strtolower(trim((string)($pay['status'] ?? 'completed'))) === 'completed') {
                            $payment_total += (float)($pay['amount'] ?? 0);
                        }
                    }
                }
            }
        }

        $documents = [];
        if (amgcOrderProductTableExists($conn, 'pick_lists')) {
            $pick_sql = "SELECT pl.*,
                            d.driver_name,
                            d.vehicle_type,
                            d.vehicle_plate_number
                         FROM pick_lists pl
                         LEFT JOIN drivers d ON pl.driver_id = d.driver_id
                         WHERE pl.so_id = ?
                         ORDER BY pl.pick_list_id DESC LIMIT 1";
            $pick_stmt = $conn->prepare($pick_sql);
            if ($pick_stmt) {
                $pick_stmt->bind_param('i', $order_id);
                $pick_stmt->execute();
                $pick = $pick_stmt->get_result()->fetch_assoc();
                $pick_stmt->close();
                if ($pick) {
                    $documents['pick_list_number'] = $pick['pick_list_number'] ?? $pick['pick_number'] ?? ('PL-' . ($pick['pick_list_id'] ?? ''));
                    $documents['driver_id'] = (int)($pick['driver_id'] ?? 0);
                    $documents['driver_name'] = $pick['driver_name'] ?? '';
                    $documents['vehicle'] = trim(($pick['vehicle_type'] ?? '') . ' ' . ($pick['vehicle_plate_number'] ?? ''));
                }
            }
        }
        if (amgcOrderProductTableExists($conn, 'trip_tickets') && amgcColumnExists($conn, 'trip_tickets', 'so_id')) {
            $trip_sql = "SELECT tt.*, d.driver_name,
                            COALESCE(v.plate_number, mv.plate_no, d.vehicle_plate_number, '') AS vehicle_plate,
                            COALESCE(v.vehicle_type, mv.vehicle_type, mv.vehicle_category, d.vehicle_type, '') AS vehicle_type
                         FROM trip_tickets tt
                         LEFT JOIN drivers d ON tt.driver_id = d.driver_id
                         LEFT JOIN vehicles v ON tt.vehicle_id = v.vehicle_id
                         LEFT JOIN motorpool_vehicles mv ON tt.vehicle_id = mv.id
                         WHERE tt.so_id = ?
                         ORDER BY tt.trip_id DESC LIMIT 1";
            $trip_stmt = $conn->prepare($trip_sql);
            if ($trip_stmt) {
                $trip_stmt->bind_param('i', $order_id);
                $trip_stmt->execute();
                $trip = $trip_stmt->get_result()->fetch_assoc();
                $trip_stmt->close();
                if ($trip) {
                    $documents['trip_ticket_number'] = $trip['trip_number'] ?? ('TT-' . ($trip['trip_id'] ?? ''));
                    if (!empty($trip['driver_id'])) $documents['driver_id'] = (int)$trip['driver_id'];
                    if (!empty($trip['vehicle_id'])) $documents['vehicle_id'] = (int)$trip['vehicle_id'];
                    if (!empty($trip['driver_name'])) $documents['driver_name'] = $trip['driver_name'];
                    $vehicleDisplay = trim(($trip['vehicle_type'] ?? '') . ' ' . ($trip['vehicle_plate'] ?? ''));
                    if ($vehicleDisplay !== '') $documents['vehicle'] = $vehicleDisplay;
                }
            }
        }

        $order['payment_total'] = $payment_total;
        $order['balance_due'] = max(0, (float)($order['total_amount'] ?? 0) - $payment_total);
        $order['si_attachments_list'] = amgcOrderProductNormalizeSIAttachments($order['si_attachments'] ?? '');
        if ($invoice) {
            $invoice['si_attachments_list'] = amgcOrderProductNormalizeSIAttachments($invoice['si_attachments'] ?? '');
            if (empty($order['si_attachments_list']) && !empty($invoice['si_attachments_list'])) {
                $order['si_attachments_list'] = $invoice['si_attachments_list'];
            }
        }

        echo json_encode([
            'success' => true,
            'order' => $order,
            'items' => $items,
            'invoice' => $invoice,
            'payments' => $payments,
            'documents' => $documents
        ]);
        exit;

    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}



// ============= HANDLE EXPORT ALL SALES ORDERS (same data/export logic as sales_order.php, embedded for Sales Order tab) =============
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'export_all_orders') {
    header('Content-Type: application/json');

    try {
        if (!isset($hide_beginning_balance_orders_condition)) {
            $hide_beginning_balance_orders_condition = "AND LOWER(TRIM(COALESCE(so.fulfillment_type, ''))) <> 'beginning_balance'";
        }
        if (!isset($so_branch_column_exists)) {
            $so_branch_column_exists = amgcColumnExists($conn, 'sales_orders', 'branch_id');
        }

        if (function_exists('amgcSyncSalesOrderComputedSnapshots')) {
            amgcSyncSalesOrderComputedSnapshots($conn);
        }

        $start_date = isset($_POST['start_date']) ? trim((string)$_POST['start_date']) : '';
        $end_date = isset($_POST['end_date']) ? trim((string)$_POST['end_date']) : '';
        $status = isset($_POST['status']) ? trim((string)$_POST['status']) : '';
        $customer = isset($_POST['customer']) ? trim((string)$_POST['customer']) : '';
        $search = isset($_POST['search']) ? trim((string)$_POST['search']) : '';

        $soi_gross_price_expr = amgcColumnExists($conn, 'sales_order_items', 'gross_price') ? 'soi.gross_price' : '0';
        $soi_net_price_expr = amgcColumnExists($conn, 'sales_order_items', 'net_price') ? 'soi.net_price' : '0';
        $soi_order_amount_expr = amgcColumnExists($conn, 'sales_order_items', 'order_amount') ? 'soi.order_amount' : '0';
        $soi_total_discount_expr = amgcColumnExists($conn, 'sales_order_items', 'total_discount') ? 'soi.total_discount' : '0';
        $soi_cogs_expr = amgcColumnExists($conn, 'sales_order_items', 'cogs_amount') ? 'soi.cogs_amount' : '0';
        $soi_gross_profit_expr = amgcColumnExists($conn, 'sales_order_items', 'gross_profit') ? 'soi.gross_profit' : '0';
        $soi_discount_type_expr = amgcColumnExists($conn, 'sales_order_items', 'discount_type') ? 'soi.discount_type' : "'computed'";
        $soi_discount_value_expr = amgcColumnExists($conn, 'sales_order_items', 'discount_value') ? 'soi.discount_value' : '0';
        $soi_ave_cost_expr = amgcColumnExists($conn, 'sales_order_items', 'ave_cost') ? 'soi.ave_cost' : '0';
        $effective_order_date_expr = "DATE(COALESCE(so.created_at, so.order_date, NOW()))";

        $query = "
            SELECT
                DATE(COALESCE(so.created_at, so.order_date)) as date_encoded,
                so.so_number as so_order_number,
                COALESCE(c.customer_code, '') as customer_code,
                COALESCE(c.store_name, '') as store_name,
                COALESCE(c.customer_name, '') as customer_name,
                COALESCE(i.item_code, '') as item_code,
                COALESCE(i.item_name, '') as item_description,
                COALESCE(soi.unit_type, '') as unit_of_measurement,
                soi.quantity_ordered as quantity,
                COALESCE(NULLIF($soi_gross_price_expr, 0), (
                    SELECT iup.unit_price
                    FROM item_unit_pricing iup
                    LEFT JOIN unit_types utp ON iup.unit_type_id = utp.unit_type_id
                    WHERE iup.item_id = soi.item_id
                      AND LOWER(TRIM(utp.unit_type_name)) = LOWER(TRIM(soi.unit_type))
                      AND (iup.effective_date IS NULL OR iup.effective_date <= $effective_order_date_expr)
                      AND (iup.effective_until IS NULL OR iup.effective_until >= $effective_order_date_expr)
                    ORDER BY COALESCE(iup.effective_date, '1900-01-01') DESC, iup.pricing_id DESC
                    LIMIT 1
                ), COALESCE(NULLIF($soi_net_price_expr, 0), soi.unit_price, i.unit_price, 0)) as gross_price,
                COALESCE(NULLIF($soi_net_price_expr, 0), soi.unit_price, i.unit_price, 0) as net_price,
                COALESCE($soi_order_amount_expr, 0) as saved_order_amount,
                COALESCE($soi_total_discount_expr, 0) as saved_total_discount,
                COALESCE($soi_cogs_expr, 0) as saved_cogs,
                COALESCE($soi_gross_profit_expr, 0) as saved_gross_profit,
                COALESCE($soi_discount_type_expr, 'computed') as discount_type,
                COALESCE($soi_discount_value_expr, 0) as discount_value,
                COALESCE(NULLIF($soi_ave_cost_expr, 0), (
                    SELECT AVG(iui.unit_cost)
                    FROM item_unit_inventory iui
                    LEFT JOIN unit_types utc ON iui.unit_type_id = utc.unit_type_id
                    WHERE iui.item_id = soi.item_id
                      AND LOWER(TRIM(utc.unit_type_name)) = LOWER(TRIM(soi.unit_type))
                      AND DATE(iui.updated_at) BETWEEN DATE_SUB($effective_order_date_expr, INTERVAL 30 DAY) AND $effective_order_date_expr
                ), (
                    SELECT AVG(iui2.unit_cost)
                    FROM item_unit_inventory iui2
                    LEFT JOIN unit_types utc2 ON iui2.unit_type_id = utc2.unit_type_id
                    WHERE iui2.item_id = soi.item_id
                      AND LOWER(TRIM(utc2.unit_type_name)) = LOWER(TRIM(soi.unit_type))
                ), 0) as ave_cost,
                CONCAT(COALESCE(u.first_name, ''), CASE WHEN u.last_name IS NOT NULL AND u.last_name != '' THEN CONCAT(' ', u.last_name) ELSE '' END) as encoded_by
            FROM sales_orders so
            JOIN customers c ON so.customer_id = c.customer_id
            JOIN sales_order_items soi ON so.so_id = soi.so_id
            JOIN items i ON soi.item_id = i.item_id
            LEFT JOIN users u ON so.created_by = u.user_id
            WHERE 1=1
            $hide_beginning_balance_orders_condition
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
            $query .= " AND so.branch_id = " . (int)$branch_id;
        }
        $query .= " ORDER BY so.created_at DESC, so.so_id, soi.so_item_id";

        $result = $conn->query($query);
        if (!$result) {
            echo json_encode(['success' => false, 'message' => 'Query failed: ' . $conn->error]);
            exit;
        }

        $data = $result->fetch_all(MYSQLI_ASSOC);
        foreach ($data as &$row) {
            if (function_exists('amgcCalculateSalesLine')) {
                $calc = amgcCalculateSalesLine(
                    $row['quantity'] ?? 0,
                    $row['gross_price'] ?? 0,
                    $row['discount_type'] ?? 'computed',
                    $row['discount_value'] ?? 0,
                    $row['net_price'] ?? 0,
                    $row['ave_cost'] ?? 0
                );
                $row['gross_price'] = $calc['gross_price'];
                $row['discount'] = $calc['discount'];
                $row['net_price'] = $calc['net_price'];
                $row['order_amount'] = isset($row['saved_order_amount']) && (float)$row['saved_order_amount'] != 0 ? (float)$row['saved_order_amount'] : $calc['order_amount'];
                $row['total_discount'] = isset($row['saved_total_discount']) && (float)$row['saved_total_discount'] != 0 ? (float)$row['saved_total_discount'] : $calc['total_discount'];
                $row['ave_cost'] = $calc['ave_cost'];
                $row['cogs'] = isset($row['saved_cogs']) && (float)$row['saved_cogs'] != 0 ? (float)$row['saved_cogs'] : $calc['cogs'];
                $row['gross_profit'] = isset($row['saved_gross_profit']) && (float)$row['saved_gross_profit'] != 0 ? (float)$row['saved_gross_profit'] : $calc['gross_profit'];
            } else {
                $row['discount'] = max(0, (float)$row['gross_price'] - (float)$row['net_price']);
                $row['order_amount'] = (float)$row['quantity'] * (float)$row['net_price'];
                $row['total_discount'] = (float)$row['quantity'] * (float)$row['discount'];
                $row['cogs'] = (float)$row['quantity'] * (float)$row['ave_cost'];
                $row['gross_profit'] = (float)$row['order_amount'] - (float)$row['cogs'];
            }
        }
        unset($row);

        echo json_encode(['success' => true, 'data' => $data]);
        exit;
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

// ============= HANDLE PRINT ALL SALES ORDERS (copied style from sales_order.php, embedded for Sales Order tab) =============
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'print_all_orders') {
    header('Content-Type: application/json');

    try {
        if (!function_exists('op_so_print_h')) {
            function op_so_print_h($value) {
                return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
            }
        }
        if (!function_exists('op_so_print_calc_line')) {
            function op_so_print_calc_line($qty, $grossPrice, $discountType, $discountValue, $netPrice, $aveCost) {
                $qty = (float)$qty;
                $grossPrice = (float)$grossPrice;
                $discountValue = (float)$discountValue;
                $netPrice = (float)$netPrice;
                $aveCost = (float)$aveCost;
                $discountType = strtolower(trim((string)$discountType));

                if ($grossPrice <= 0 && $netPrice > 0) $grossPrice = $netPrice;

                if ($discountType === 'percentage') {
                    $rate = ($discountValue > 1) ? ($discountValue / 100) : $discountValue;
                    $rate = max(0, min(1, $rate));
                    $discountPerUnit = $grossPrice * $rate;
                    $netPrice = max(0, $grossPrice - $discountPerUnit);
                } elseif ($discountType === 'peso') {
                    $discountPerUnit = max(0, $grossPrice - $discountValue);
                    $netPrice = max(0, $discountValue);
                } else {
                    $discountPerUnit = max(0, $grossPrice - $netPrice);
                }

                $orderAmount = $qty * $netPrice;
                $totalDiscount = $qty * $discountPerUnit;
                $cogs = $qty * $aveCost;
                $grossProfit = $orderAmount - $cogs;

                return [
                    'gross_price' => $grossPrice,
                    'discount' => $discountValue > 0 ? $discountValue : $discountPerUnit,
                    'net_price' => $netPrice,
                    'order_amount' => $orderAmount,
                    'total_discount' => $totalDiscount,
                    'ave_cost' => $aveCost,
                    'cogs' => $cogs,
                    'gross_profit' => $grossProfit
                ];
            }
        }

        $start_date = trim((string)($_POST['start_date'] ?? ''));
        $end_date = trim((string)($_POST['end_date'] ?? ''));
        $status = trim((string)($_POST['status'] ?? ''));
        $customer = trim((string)($_POST['customer'] ?? ''));
        $search = trim((string)($_POST['search'] ?? ''));

        $so_branch_exists_for_print = function_exists('amgcColumnExists') ? amgcColumnExists($conn, 'sales_orders', 'branch_id') : false;
        $so_fulfillment_exists_for_print = function_exists('amgcColumnExists') ? amgcColumnExists($conn, 'sales_orders', 'fulfillment_type') : false;

        $query = "
            SELECT
                DATE(COALESCE(so.created_at, so.order_date)) as date_encoded,
                so.so_number as so_order_number,
                COALESCE(c.customer_code, '') as customer_code,
                COALESCE(c.store_name, '') as store_name,
                COALESCE(c.customer_name, '') as customer_name,
                COALESCE(i.item_code, '') as item_code,
                COALESCE(i.item_name, '') as item_description,
                COALESCE(soi.unit_type, '') as unit_of_measurement,
                COALESCE(soi.quantity_ordered, 0) as quantity,
                COALESCE(NULLIF(soi.gross_price, 0), COALESCE(NULLIF(soi.unit_price, 0), NULLIF(soi.net_price, 0), 0)) as gross_price,
                COALESCE(NULLIF(soi.net_price, 0), soi.unit_price, 0) as net_price,
                COALESCE(soi.order_amount, 0) as saved_order_amount,
                COALESCE(soi.total_discount, 0) as saved_total_discount,
                COALESCE(soi.cogs_amount, 0) as saved_cogs,
                COALESCE(soi.gross_profit, 0) as saved_gross_profit,
                COALESCE(soi.discount_type, 'computed') as discount_type,
                COALESCE(soi.discount_value, 0) as discount_value,
                COALESCE(NULLIF(soi.ave_cost, 0), (
                    SELECT AVG(iui.unit_cost)
                    FROM item_unit_inventory iui
                    LEFT JOIN unit_types utc ON iui.unit_type_id = utc.unit_type_id
                    WHERE iui.item_id = soi.item_id
                      AND LOWER(TRIM(utc.unit_type_name)) = LOWER(TRIM(soi.unit_type))
                      AND DATE(iui.updated_at) BETWEEN DATE_SUB(CURDATE(), INTERVAL 30 DAY) AND CURDATE()
                ), (
                    SELECT AVG(iui2.unit_cost)
                    FROM item_unit_inventory iui2
                    LEFT JOIN unit_types utc2 ON iui2.unit_type_id = utc2.unit_type_id
                    WHERE iui2.item_id = soi.item_id
                      AND LOWER(TRIM(utc2.unit_type_name)) = LOWER(TRIM(soi.unit_type))
                ), 0) as ave_cost,
                CONCAT(COALESCE(u.first_name, ''), CASE WHEN u.last_name IS NOT NULL AND u.last_name != '' THEN CONCAT(' ', u.last_name) ELSE '' END) as encoded_by
            FROM sales_orders so
            JOIN customers c ON so.customer_id = c.customer_id
            JOIN sales_order_items soi ON so.so_id = soi.so_id
            JOIN items i ON soi.item_id = i.item_id
            LEFT JOIN users u ON so.created_by = u.user_id
            WHERE 1=1
        ";

        if ($so_fulfillment_exists_for_print) {
            $query .= " AND LOWER(TRIM(COALESCE(so.fulfillment_type, ''))) <> 'beginning_balance'";
        }
        if ($start_date !== '' && $end_date !== '') {
            $query .= " AND DATE(COALESCE(so.created_at, so.order_date)) BETWEEN '" . $conn->real_escape_string($start_date) . "' AND '" . $conn->real_escape_string($end_date) . "'";
        }
        if ($status !== '') {
            $query .= " AND so.order_status = '" . $conn->real_escape_string($status) . "'";
        }
        if ($customer !== '') {
            $query .= " AND c.customer_name = '" . $conn->real_escape_string($customer) . "'";
        }
        if ($search !== '') {
            $search_escaped = $conn->real_escape_string($search);
            $query .= " AND (so.so_number LIKE '%$search_escaped%' OR c.customer_name LIKE '%$search_escaped%' OR i.item_name LIKE '%$search_escaped%' OR i.item_code LIKE '%$search_escaped%')";
        }
        if ($so_branch_exists_for_print && empty($view_all_branches) && (int)$branch_id > 0) {
            $query .= " AND so.branch_id = " . (int)$branch_id;
        }
        $query .= " ORDER BY COALESCE(so.created_at, so.order_date) DESC, so.so_id, soi.so_item_id";

        $result = $conn->query($query);
        if (!$result) throw new Exception('Print query failed: ' . $conn->error);

        $rows = $result->fetch_all(MYSQLI_ASSOC);
        foreach ($rows as &$row) {
            $calc = op_so_print_calc_line(
                $row['quantity'] ?? 0,
                $row['gross_price'] ?? 0,
                $row['discount_type'] ?? 'computed',
                $row['discount_value'] ?? 0,
                $row['net_price'] ?? 0,
                $row['ave_cost'] ?? 0
            );
            $row['gross_price'] = $calc['gross_price'];
            $row['discount'] = $calc['discount'];
            $row['net_price'] = $calc['net_price'];
            $row['order_amount'] = isset($row['saved_order_amount']) && (float)$row['saved_order_amount'] != 0 ? (float)$row['saved_order_amount'] : $calc['order_amount'];
            $row['total_discount'] = isset($row['saved_total_discount']) && (float)$row['saved_total_discount'] != 0 ? (float)$row['saved_total_discount'] : $calc['total_discount'];
            $row['ave_cost'] = $calc['ave_cost'];
            $row['cogs'] = isset($row['saved_cogs']) && (float)$row['saved_cogs'] != 0 ? (float)$row['saved_cogs'] : $calc['cogs'];
            $row['gross_profit'] = isset($row['saved_gross_profit']) && (float)$row['saved_gross_profit'] != 0 ? (float)$row['saved_gross_profit'] : $calc['gross_profit'];
        }
        unset($row);

        $print_branch_name = 'All Branches';
        if (empty($view_all_branches) && (int)$branch_id > 0) {
            $print_branch_name = $branch_name ?? ('Branch ' . (int)$branch_id);
        }

        $logo_base64_print = '';
        $logo_path_print = '../Pictures/amgc3DLogo.png';
        if (file_exists($logo_path_print)) {
            $logo_base64_print = 'data:image/png;base64,' . base64_encode(file_get_contents($logo_path_print));
        }
        $logo_html = $logo_base64_print !== '' ? '<img src="' . $logo_base64_print . '" alt="Logo">' : '';

        $html = '<!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <title>All Sales Orders Report</title>
            <style>
                @page { size: A4 landscape; margin: 8mm; }
                * { box-sizing: border-box; }
                body { font-family: Arial, sans-serif; margin: 10px; font-size: 10.5px; line-height: 1.22; color: #111827; background: #fff; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
                .header { display: flex; align-items: center; justify-content: center; gap: 9px; text-align: center; margin-bottom: 10px; padding-bottom: 7px; border-bottom: 2px solid #047857; }
                .header img { height: 42px !important; width: 42px !important; object-fit: contain; margin: 0 !important; flex: 0 0 auto; }
                .header h1 { margin: 0 0 2px 0; font-size: 15px; line-height: 1.1; font-weight: 800; color: #052A47; }
                .header p { margin: 1px 0; color: #334155; font-size: 8.8px; line-height: 1.15; font-weight: 600; }
                .print-title-text { text-align: left; }
                table { width: 100%; border-collapse: collapse; margin-top: 8px; table-layout: fixed; }
                th { border: 0.55px solid #94a3b8; padding: 4px 3px; text-align: center; vertical-align: middle; background: #047857; font-size: 7.2px; line-height: 1.1; font-weight: 400; color: #fff; text-transform: uppercase; overflow-wrap: anywhere; word-break: normal; }
                td { border: 0.55px solid #94a3b8; padding: 4px 3px; text-align: center; vertical-align: middle; font-size: 7.4px; line-height: 1.12; color: #111827; overflow-wrap: anywhere; word-break: normal; }
                tbody tr:nth-child(even) { background: #f8fafc; }
                th:nth-child(1), td:nth-child(1) { width: 5.5%; }
                th:nth-child(2), td:nth-child(2) { width: 9.2%; }
                td:nth-child(2) { font-weight: 700; }
                th:nth-child(3), td:nth-child(3) { width: 7.2%; }
                td:nth-child(3) { font-weight: 700; }
                th:nth-child(4), td:nth-child(4) { width: 6.8%; text-align: left; }
                th:nth-child(5), td:nth-child(5) { width: 6.8%; text-align: left; }
                th:nth-child(6), td:nth-child(6) { width: 4.2%; font-size: 6.8px; padding-left: 2px; padding-right: 2px; }
                th:nth-child(7), td:nth-child(7) { width: 5.2%; font-size: 6.8px; padding-left: 2px; padding-right: 2px; text-align: left; }
                th:nth-child(8), td:nth-child(8) { width: 5.2%; }
                th:nth-child(9), td:nth-child(9) { width: 4.5%; }
                th:nth-child(10), td:nth-child(10), th:nth-child(11), td:nth-child(11), th:nth-child(12), td:nth-child(12), th:nth-child(13), td:nth-child(13), th:nth-child(14), td:nth-child(14), th:nth-child(15), td:nth-child(15), th:nth-child(16), td:nth-child(16), th:nth-child(17), td:nth-child(17) { width: 5.4%; }
                th:nth-child(18), td:nth-child(18) { width: 6.4%; }
                table thead th { font-weight: 400 !important; }
                .footer { margin-top: 10px; padding-top: 6px; border-top: 1px solid #94a3b8; text-align: center; font-size: 8.5px; color: #334155; font-weight: 600; }
                @media print { body { margin: 0; } .header { margin-bottom: 8px; padding-bottom: 5px; } table { page-break-inside: auto; } thead { display: table-header-group; } tr { page-break-inside: avoid; break-inside: avoid; } .no-print { display: none; } }
            </style>
        

<style id="amgc-order-details-true-fullscreen-final">
/* Invoice Details modal only: true fullscreen, fit to screen, no internal scroll. */
#orderDetailsModal.modal {
    padding: 0 !important;
}
#orderDetailsModal .modal-dialog,
#orderDetailsModal .modal-dialog.order-details-wide-modal,
#orderDetailsModal .modal-fullscreen {
    position: fixed !important;
    inset: 0 !important;
    width: 100vw !important;
    max-width: 100vw !important;
    min-width: 100vw !important;
    height: 100vh !important;
    max-height: 100vh !important;
    min-height: 100vh !important;
    margin: 0 !important;
    padding: 0 !important;
    transform: none !important;
}
#orderDetailsModal .modal-content {
    width: 100vw !important;
    height: 100vh !important;
    min-height: 100vh !important;
    max-height: 100vh !important;
    border: 0 !important;
    border-radius: 0 !important;
    display: flex !important;
    flex-direction: column !important;
    overflow: hidden !important;
    background: #fff !important;
}
#orderDetailsModal .modal-header {
    height: 46px !important;
    min-height: 46px !important;
    max-height: 46px !important;
    flex: 0 0 46px !important;
    padding: 8px 16px !important;
    background: linear-gradient(90deg, #047857 0%, #36d34a 100%) !important;
    color: #fff !important;
    border: 0 !important;
}
#orderDetailsModal .modal-title {
    font-size: 1.22rem !important;
    line-height: 1.1 !important;
    font-weight: 800 !important;
}
#orderDetailsModal .btn-close {
    width: 34px !important;
    height: 34px !important;
    padding: 0 !important;
    margin: 0 !important;
    border-radius: 50% !important;
    background-color: rgba(255,255,255,.45) !important;
    opacity: 1 !important;
}
#orderDetailsModal .modal-body {
    flex: 1 1 auto !important;
    height: calc(100vh - 46px) !important;
    max-height: calc(100vh - 46px) !important;
    min-height: 0 !important;
    overflow: hidden !important;
    overflow-y: hidden !important;
    overflow-x: hidden !important;
    padding: 0 !important;
    background: #fff !important;
}
#orderDetailsModal .modal-footer { display: none !important; }
#orderDetailsModal .op-invoice-form-view {
    height: 100% !important;
    min-height: 0 !important;
    width: 100% !important;
    display: flex !important;
    flex-direction: column !important;
    background: #fff !important;
    font-size: clamp(13px, .82vw, 16px) !important;
}
#orderDetailsModal .op-invoice-main {
    flex: 1 1 auto !important;
    min-height: 0 !important;
    height: 100% !important;
    padding: clamp(8px, .7vw, 14px) clamp(14px, 1vw, 22px) !important;
    display: grid !important;
    grid-template-rows: auto auto 1fr !important;
    row-gap: clamp(7px, .55vw, 11px) !important;
    overflow: hidden !important;
}
#orderDetailsModal .op-invoice-sheet-head {
    display: grid !important;
    grid-template-columns: minmax(420px, 1fr) minmax(760px, 1.45fr) !important;
    gap: clamp(12px, 1vw, 22px) !important;
    align-items: start !important;
    min-height: 0 !important;
}
#orderDetailsModal .op-invoice-title {
    font-size: clamp(2.25rem, 2.35vw, 3.15rem) !important;
    line-height: .95 !important;
    margin: 0 0 clamp(6px, .45vw, 10px) !important;
    font-weight: 300 !important;
}
#orderDetailsModal .op-customer-readonly {
    max-width: none !important;
    padding: clamp(7px, .55vw, 10px) clamp(9px, .7vw, 12px) !important;
    line-height: 1.12 !important;
    font-size: clamp(13px, .82vw, 15px) !important;
}
#orderDetailsModal .op-customer-readonly .name {
    font-size: clamp(1rem, .98vw, 1.25rem) !important;
    margin-bottom: 3px !important;
}
#orderDetailsModal .op-right-fields {
    display: grid !important;
    grid-template-columns: repeat(4, minmax(130px, 1fr)) !important;
    gap: clamp(6px, .45vw, 10px) clamp(10px, .8vw, 16px) !important;
}
#orderDetailsModal .op-field label,
#orderDetailsModal .op-section-label {
    font-size: clamp(.72rem, .68vw, .84rem) !important;
    margin-bottom: 2px !important;
    line-height: 1.05 !important;
    font-weight: 800 !important;
}
#orderDetailsModal .op-detail-control,
#orderDetailsModal .op-detail-select {
    height: clamp(28px, 2.1vh, 36px) !important;
    min-height: clamp(28px, 2.1vh, 36px) !important;
    padding: 3px 8px !important;
    font-size: clamp(13px, .82vw, 16px) !important;
}
#orderDetailsModal .op-items-wrap {
    margin-top: 0 !important;
    min-height: 0 !important;
    overflow: hidden !important;
}
#orderDetailsModal .op-items-table {
    table-layout: fixed !important;
    width: 100% !important;
    font-size: clamp(13px, .82vw, 16px) !important;
}
#orderDetailsModal .op-items-table thead th {
    padding: clamp(5px, .36vw, 8px) 8px !important;
    font-size: clamp(.78rem, .75vw, .95rem) !important;
    line-height: 1.1 !important;
}
#orderDetailsModal .op-items-table td {
    height: auto !important;
    min-height: 0 !important;
    padding: clamp(4px, .34vw, 7px) 8px !important;
    line-height: 1.18 !important;
    font-size: clamp(13px, .82vw, 16px) !important;
}
#orderDetailsModal .op-invoice-muted,
#orderDetailsModal .small {
    font-size: clamp(11px, .68vw, 13px) !important;
    line-height: 1.05 !important;
}
#orderDetailsModal .op-lower-area {
    display: grid !important;
    grid-template-columns: minmax(620px, 1fr) minmax(330px, 430px) !important;
    gap: clamp(14px, 1vw, 24px) !important;
    margin-top: 0 !important;
    min-height: 0 !important;
    align-items: start !important;
    overflow: hidden !important;
}
#orderDetailsModal .op-message-box {
    min-height: clamp(30px, 3.3vh, 44px) !important;
    height: clamp(30px, 3.3vh, 44px) !important;
    padding: 4px 8px !important;
    resize: none !important;
    font-size: clamp(13px, .8vw, 15px) !important;
}
#orderDetailsModal .op-payment-box {
    margin-top: clamp(6px, .45vw, 10px) !important;
    padding: clamp(7px, .55vw, 10px) clamp(10px, .7vw, 14px) !important;
}
#orderDetailsModal .op-payment-toggle-line {
    margin-bottom: 5px !important;
    font-size: clamp(.74rem, .72vw, .9rem) !important;
}
#orderDetailsModal .op-payment-detail-line {
    gap: 10px !important;
}
#orderDetailsModal .op-summary-box {
    width: 100% !important;
    max-width: 420px !important;
    margin-left: auto !important;
    font-size: clamp(13px, .82vw, 16px) !important;
}
#orderDetailsModal .op-summary-box > div {
    margin-bottom: clamp(4px, .35vw, 7px) !important;
    gap: 12px !important;
    grid-template-columns: 1fr minmax(110px, 150px) !important;
}
#orderDetailsModal .op-balance-due span,
#orderDetailsModal .op-balance-due strong {
    font-size: clamp(1rem, 1.08vw, 1.35rem) !important;
}
#orderDetailsModal .op-detail-footer {
    margin-top: clamp(8px, .65vw, 14px) !important;
    gap: 10px !important;
}
#orderDetailsModal .op-detail-footer .btn {
    min-width: clamp(100px, 7vw, 130px) !important;
    min-height: clamp(34px, 3vh, 42px) !important;
    padding: 5px 14px !important;
    font-size: clamp(13px, .84vw, 16px) !important;
    font-weight: 800 !important;
}
@media (max-width: 1280px) {
    #orderDetailsModal .op-invoice-sheet-head { grid-template-columns: minmax(340px, .9fr) minmax(620px, 1.35fr) !important; }
    #orderDetailsModal .op-right-fields { grid-template-columns: repeat(4, minmax(100px, 1fr)) !important; }
    #orderDetailsModal .op-lower-area { grid-template-columns: minmax(520px, 1fr) minmax(280px, 360px) !important; }
}
@media (max-height: 760px) {
    #orderDetailsModal .modal-header { height: 40px !important; min-height: 40px !important; flex-basis: 40px !important; padding: 6px 14px !important; }
    #orderDetailsModal .modal-body { height: calc(100vh - 40px) !important; max-height: calc(100vh - 40px) !important; }
    #orderDetailsModal .op-invoice-main { padding-top: 6px !important; padding-bottom: 6px !important; row-gap: 5px !important; }
    #orderDetailsModal .op-invoice-title { font-size: 2rem !important; margin-bottom: 4px !important; }
    #orderDetailsModal .op-customer-readonly { padding-top: 5px !important; padding-bottom: 5px !important; line-height: 1.05 !important; }
    #orderDetailsModal .op-detail-control,
    #orderDetailsModal .op-detail-select { height: 26px !important; min-height: 26px !important; }
    #orderDetailsModal .op-message-box { height: 28px !important; min-height: 28px !important; }
}
</style>


<style id="amgc-swal-click-safe-fix">
/* AMGC FIX: keep Order Submitted SweetAlert above invoice/modal layers and clickable */
.swal2-container,
.swal2-container.swal2-center,
.swal2-container:has(.animated-order-alert),
.swal2-container:has(.outstanding-approval-swal) {
    z-index: 2147483000 !important;
    pointer-events: auto !important;
}

.swal2-popup,
.swal2-popup.animated-order-alert,
.swal2-popup.outstanding-approval-swal,
.swal2-actions,
.swal2-styled,
.swal2-confirm,
.swal2-deny,
.swal2-cancel {
    pointer-events: auto !important;
}

.swal2-popup.animated-order-alert .swal2-actions {
    position: relative !important;
    z-index: 2147483001 !important;
}

.swal2-popup.animated-order-alert .swal2-confirm {
    cursor: pointer !important;
    opacity: 1 !important;
}
</style>







</head>
        <body>
            <div class="header">' . $logo_html . '<div class="print-title-text"><h1>Sales Orders Report</h1><p>Branch: ' . op_so_print_h($print_branch_name) . '</p><p>Generated: ' . date('Y-m-d H:i:s') . '</p></div></div>
            <table>
                <thead>
                    <tr>
                        <th>Date Encoded</th><th>SO Order Number</th><th>Customer Code</th><th>Store Name</th><th>Customer Name</th><th>Item Code</th><th>Item Description</th><th>Unit of Measurement</th><th>Quantity</th><th>Gross Price</th><th>Discount</th><th>Net Price</th><th>Order Amount</th><th>Total Discount</th><th>Ave. Cost</th><th>COGS</th><th>Gross Profit</th><th>Encoded by</th>
                    </tr>
                </thead>
                <tbody>';

        foreach ($rows as $row) {
            $html .= '<tr>
                <td>' . op_so_print_h($row['date_encoded'] ?? '') . '</td>
                <td>' . op_so_print_h($row['so_order_number'] ?? '') . '</td>
                <td>' . op_so_print_h($row['customer_code'] ?? '') . '</td>
                <td>' . op_so_print_h($row['store_name'] ?? '') . '</td>
                <td>' . op_so_print_h($row['customer_name'] ?? '') . '</td>
                <td>' . op_so_print_h($row['item_code'] ?? '') . '</td>
                <td>' . op_so_print_h($row['item_description'] ?? '') . '</td>
                <td>' . op_so_print_h($row['unit_of_measurement'] ?? '') . '</td>
                <td>' . number_format((float)($row['quantity'] ?? 0), 2) . '</td>
                <td>' . number_format((float)($row['gross_price'] ?? 0), 2) . '</td>
                <td>' . number_format((float)($row['discount'] ?? 0), 2) . '</td>
                <td>' . number_format((float)($row['net_price'] ?? 0), 2) . '</td>
                <td>' . number_format((float)($row['order_amount'] ?? 0), 2) . '</td>
                <td>' . number_format((float)($row['total_discount'] ?? 0), 2) . '</td>
                <td>' . number_format((float)($row['ave_cost'] ?? 0), 2) . '</td>
                <td>' . number_format((float)($row['cogs'] ?? 0), 2) . '</td>
                <td>' . number_format((float)($row['gross_profit'] ?? 0), 2) . '</td>
                <td>' . op_so_print_h($row['encoded_by'] ?? '') . '</td>
            </tr>';
        }

        $html .= '</tbody></table><div class="footer">Total records: ' . count($rows) . '</div>
<style>
/* Clean invoice details view controls */
#orderDetailsModal .op-invoice-topbar { display: none !important; }
#orderDetailsModal .modal-footer.d-none { display: none !important; }
#orderDetailsModal .op-detail-footer { gap: 10px !important; justify-content: flex-end !important; }
#orderDetailsModal .op-detail-footer .btn { min-width: 118px !important; }
</style>



<style>
/* FINAL OVERRIDE: Invoice Details modal only. Does not affect Create Invoice tab. */
#orderDetailsModal.show .modal-dialog.order-details-wide-modal,
#orderDetailsModal .modal-dialog.order-details-wide-modal,
#orderDetailsModal .modal-dialog {
    width: 100vw !important;
    max-width: 100vw !important;
    min-width: 100vw !important;
    height: 100vh !important;
    max-height: 100vh !important;
    margin: 0 !important;
    padding: 0 !important;
    transform: none !important;
}
#orderDetailsModal .modal-content {
    width: 100vw !important;
    height: 100vh !important;
    min-height: 100vh !important;
    max-height: 100vh !important;
    border-radius: 0 !important;
    border: 0 !important;
    overflow: hidden !important;
    font-size: 16px !important;
}
#orderDetailsModal .modal-header {
    flex: 0 0 auto !important;
    height: 54px !important;
    min-height: 54px !important;
    padding: 10px 18px !important;
}
#orderDetailsModal .modal-title {
    font-size: 1.35rem !important;
    font-weight: 800 !important;
}
#orderDetailsModal .btn-close {
    transform: scale(1.15) !important;
}
#orderDetailsModal .modal-body {
    flex: 1 1 auto !important;
    height: calc(100vh - 54px) !important;
    max-height: calc(100vh - 54px) !important;
    overflow-y: auto !important;
    overflow-x: hidden !important;
    padding: 0 !important;
    background: #fff !important;
}
#orderDetailsModal .modal-footer {
    display: none !important;
}
#orderDetailsModal .op-invoice-form-view {
    width: 100% !important;
    max-width: none !important;
    min-height: calc(100vh - 54px) !important;
    font-size: 16px !important;
}
#orderDetailsModal .op-invoice-topbar {
    display: none !important;
}
#orderDetailsModal .op-invoice-main {
    width: 100% !important;
    max-width: none !important;
    padding: 18px 26px 20px !important;
}
#orderDetailsModal .op-invoice-sheet-head {
    display: grid !important;
    grid-template-columns: minmax(460px, 0.95fr) minmax(920px, 1.55fr) !important;
    gap: 20px !important;
    align-items: start !important;
}
#orderDetailsModal .op-right-fields {
    display: grid !important;
    grid-template-columns: repeat(4, minmax(150px, 1fr)) !important;
    gap: 12px 16px !important;
}
#orderDetailsModal .op-invoice-title {
    font-size: 3.4rem !important;
    line-height: 1 !important;
    margin: 8px 0 14px !important;
    font-weight: 400 !important;
}
#orderDetailsModal .op-customer-info-box,
#orderDetailsModal .op-customer-info-box * {
    font-size: 1rem !important;
    line-height: 1.18 !important;
}
#orderDetailsModal .op-detail-label {
    font-size: .9rem !important;
    margin-bottom: 5px !important;
    font-weight: 800 !important;
}
#orderDetailsModal .op-detail-control,
#orderDetailsModal .op-detail-select {
    min-height: 40px !important;
    height: 40px !important;
    padding: 7px 11px !important;
    font-size: 1rem !important;
}
#orderDetailsModal .op-invoice-items-table {
    font-size: 1rem !important;
    margin-top: 12px !important;
}
#orderDetailsModal .op-invoice-items-table thead th {
    font-size: 1rem !important;
    padding: 8px 10px !important;
    font-weight: 800 !important;
}
#orderDetailsModal .op-invoice-items-table td,
#orderDetailsModal .op-invoice-items-table tfoot td {
    padding: 8px 10px !important;
    font-size: 1rem !important;
}
#orderDetailsModal .op-message-box {
    min-height: 46px !important;
    font-size: 1rem !important;
}
#orderDetailsModal .op-payment-view-box {
    padding: 12px 14px !important;
    font-size: 1rem !important;
}
#orderDetailsModal .op-summary-box,
#orderDetailsModal .op-summary-box * {
    font-size: 1rem !important;
}
#orderDetailsModal .op-summary-box .op-balance-due,
#orderDetailsModal .op-summary-box .balance-due,
#orderDetailsModal .op-summary-box strong {
    font-size: 1.25rem !important;
}
#orderDetailsModal .op-details-action-bar,
#orderDetailsModal .op-invoice-actions {
    margin-top: 12px !important;
}
#orderDetailsModal .op-details-action-bar .btn,
#orderDetailsModal .op-invoice-actions .btn {
    min-width: 120px !important;
    min-height: 40px !important;
    font-size: 1rem !important;
    font-weight: 700 !important;
}
@media (max-width: 1400px) {
    #orderDetailsModal .op-invoice-sheet-head {
        grid-template-columns: minmax(380px, .95fr) minmax(720px, 1.4fr) !important;
    }
    #orderDetailsModal .op-right-fields {
        grid-template-columns: repeat(3, minmax(140px, 1fr)) !important;
    }
}
@media (max-width: 992px) {
    #orderDetailsModal .op-invoice-sheet-head {
        grid-template-columns: 1fr !important;
    }
    #orderDetailsModal .op-right-fields {
        grid-template-columns: repeat(2, minmax(120px, 1fr)) !important;
    }
    #orderDetailsModal .op-invoice-title {
        font-size: 2.6rem !important;
    }
}
</style>

</body></html>';
        echo json_encode(['success' => true, 'html' => $html]);
    } catch (Throwable $e) {
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


// ===== EMBEDDED SALES ORDER TAB DATA =====
// This keeps the Sales Order list inside orderproduct.php without iframe/include.
$op_sales_orders = [];
$op_sales_order_error = '';
try {
    $op_so_branch_exists = amgcColumnExists($conn, 'sales_orders', 'branch_id');
    $op_so_fulfillment_exists = amgcColumnExists($conn, 'sales_orders', 'fulfillment_type');
    $op_so_si_exists = amgcColumnExists($conn, 'sales_orders', 'si_number');
    $op_so_order_amount_exists = amgcColumnExists($conn, 'sales_orders', 'order_amount');
    $op_so_discount_exists = amgcColumnExists($conn, 'sales_orders', 'total_discount_amount');
    $op_so_gp_exists = amgcColumnExists($conn, 'sales_orders', 'gross_profit_amount');
    $op_so_cogs_exists = amgcColumnExists($conn, 'sales_orders', 'cogs_amount');
    $op_customers_branch_exists = amgcColumnExists($conn, 'customers', 'branch_id');

    $op_si_select = $op_so_si_exists ? "so.si_number" : "NULL AS si_number";
    $op_branch_select = $op_so_branch_exists ? "so.branch_id" : "0 AS branch_id";
    $op_order_amount_select = $op_so_order_amount_exists ? "COALESCE(NULLIF(so.order_amount,0), so.total_amount, 0) AS display_total" : "COALESCE(so.total_amount,0) AS display_total";
    $op_discount_select = $op_so_discount_exists ? "COALESCE(so.total_discount_amount,0) AS total_discount_amount" : "0 AS total_discount_amount";
    $op_gp_select = $op_so_gp_exists ? "COALESCE(so.gross_profit_amount,0) AS gross_profit_amount" : "0 AS gross_profit_amount";
    $op_cogs_select = $op_so_cogs_exists ? "COALESCE(so.cogs_amount,0) AS cogs_amount" : "0 AS cogs_amount";

    $op_branch_join = $op_so_branch_exists ? "LEFT JOIN branches b ON so.branch_id = b.branch_id" : "";
    $op_where = "WHERE 1=1";
    if ($op_so_fulfillment_exists) {
        $op_where .= " AND LOWER(TRIM(COALESCE(so.fulfillment_type,''))) <> 'beginning_balance'";
    }
    if (!$view_all_branches && (int)$branch_id > 0) {
        if ($op_so_branch_exists) {
            $op_where .= " AND (so.branch_id = " . (int)$branch_id . " OR so.branch_id IS NULL OR so.branch_id = 0)";
        } elseif ($op_customers_branch_exists) {
            $op_where .= " AND (c.branch_id = " . (int)$branch_id . " OR c.branch_id IS NULL OR c.branch_id = 0)";
        }
    }

    $op_sales_order_sql = "
        SELECT
            so.so_id,
            so.customer_id,
            so.so_number,
            $op_si_select,
            so.order_date,
            COALESCE(so.order_status, 'pending') AS order_status,
            COALESCE(so.payment_status, 'unpaid') AS payment_status,
            $op_branch_select,
            $op_order_amount_select,
            $op_discount_select,
            $op_cogs_select,
            $op_gp_select,
            c.customer_code,
            c.customer_name,
            c.store_name,
            COALESCE(b.branch_name, '') AS branch_name,
            TRIM(CONCAT(COALESCE(u.first_name,''), ' ', COALESCE(u.last_name,''))) AS encoded_by,
            (SELECT COALESCE(SUM(quantity_ordered),0) FROM sales_order_items soi WHERE soi.so_id = so.so_id) AS total_qty,
            (SELECT COUNT(*) FROM sales_order_items soi2 WHERE soi2.so_id = so.so_id) AS item_count
        FROM sales_orders so
        LEFT JOIN customers c ON so.customer_id = c.customer_id
        LEFT JOIN users u ON so.created_by = u.user_id
        $op_branch_join
        $op_where
        ORDER BY so.order_date DESC, so.so_id DESC
        LIMIT 500
    ";
    $op_sales_order_result = $conn->query($op_sales_order_sql);
    if ($op_sales_order_result) {
        $op_sales_orders = $op_sales_order_result->fetch_all(MYSQLI_ASSOC);
    } else {
        $op_sales_order_error = $conn->error;
        error_log("Embedded Sales Order query error: " . $conn->error);
    }
} catch (Throwable $e) {
    $op_sales_order_error = $e->getMessage();
    error_log("Embedded Sales Order tab error: " . $e->getMessage());
}

$op_sales_order_count = count($op_sales_orders);
$op_sales_order_pending_count = count(array_filter($op_sales_orders, function($row) {
    return strtolower(trim((string)($row['order_status'] ?? ''))) === 'pending';
}));
$op_sales_order_confirmed_count = count(array_filter($op_sales_orders, function($row) {
    return in_array(strtolower(trim((string)($row['order_status'] ?? ''))), ['confirmed','processing','ready','in_transit','delivered'], true);
}));

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
    <link href="https://fonts.googleapis.com/css2?family=Tenor+Sans&family=Alice&display=swap" rel="stylesheet">
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
    body.order-product-invoice-style .invoice-lower-fields {
        grid-template-columns: repeat(2, minmax(130px, 1fr)) !important;
    }
    body.order-product-invoice-style .invoice-lower-fields .invoice-fulfillment-field,
    body.order-product-invoice-style .invoice-lower-fields .invoice-atw-field,
    body.order-product-invoice-style .invoice-lower-fields .invoice-gatepass-field,
    body.order-product-invoice-style .invoice-lower-fields .invoice-delivery-type-field {
        grid-column: auto;
    }
}

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

        #productSearch:-webkit-autofill,
        #productSearch:-webkit-autofill:hover,
        #productSearch:-webkit-autofill:focus {
            -webkit-text-fill-color: #111827;
            transition: background-color 9999s ease-in-out 0s;
            box-shadow: 0 0 0 1000px #ffffff inset;
            border: 1px solid #e0e0e0;
            border-radius: 25px;
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

/* ===== FIX: SweetAlert approval/success modals should not create page scrollbars ===== */
body.swal2-shown,
body.swal2-height-auto {
    overflow: hidden !important;
}

.swal2-container {
    overflow: hidden !important;
    padding: 12px !important;
}

.swal2-popup.outstanding-approval-swal {
    width: min(520px, calc(100vw - 32px)) !important;
    max-height: calc(100vh - 32px) !important;
    overflow: hidden !important;
    padding: 1.1rem 1.25rem !important;
    border-radius: 12px !important;
}

.swal2-popup.outstanding-approval-swal .swal2-icon {
    margin: 0.25rem auto 0.75rem !important;
    width: 4rem !important;
    height: 4rem !important;
}

.swal2-popup.outstanding-approval-swal .swal2-title {
    font-size: 1.45rem !important;
    line-height: 1.15 !important;
    padding: 0 !important;
    margin: 0 0 0.75rem !important;
}

.swal2-popup.outstanding-approval-swal .swal2-html-container {
    margin: 0 !important;
    padding: 0 !important;
    max-height: none !important;
    overflow: visible !important;
}

.swal2-popup.outstanding-approval-swal .outstanding-approval-body {
    font-size: 0.95rem !important;
    line-height: 1.25 !important;
}

.swal2-popup.outstanding-approval-swal .outstanding-approval-summary p {
    margin-bottom: 0.45rem !important;
}

.swal2-popup.outstanding-approval-swal .outstanding-approval-summary hr {
    margin: 0.55rem 0 !important;
}

.swal2-popup.outstanding-approval-swal textarea {
    min-height: 74px !important;
    height: 74px !important;
    resize: none !important;
}

.swal2-popup.outstanding-approval-swal .form-check {
    margin-top: 0.65rem !important;
}

.swal2-popup.outstanding-approval-swal .swal2-actions {
    margin-top: 0.9rem !important;
}

.swal2-popup.outstanding-approval-swal .swal2-styled {
    padding: 0.6rem 1rem !important;
    font-size: 0.95rem !important;
}

.swal2-popup.animated-order-alert {
    width: min(520px, calc(100vw - 32px)) !important;
    max-height: calc(100vh - 32px) !important;
    overflow: hidden !important;
}

.swal2-popup.animated-order-alert .swal2-html-container {
    overflow: visible !important;
}

.swal2-container:has(.animated-order-alert),
.swal2-container:has(.outstanding-approval-swal) {
    overflow: hidden !important;
}

.swal2-popup.animated-order-alert,
.swal2-popup.outstanding-approval-swal {
    box-sizing: border-box !important;
}

.swal2-popup.outstanding-approval-swal {
    width: min(500px, calc(100vw - 40px)) !important;
    max-height: calc(100vh - 40px) !important;
}

.swal2-popup.outstanding-approval-swal .swal2-html-container {
    overflow-x: hidden !important;
}

.swal2-popup.outstanding-approval-swal .outstanding-approval-body {
    max-width: 100% !important;
    overflow-x: hidden !important;
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


/* Recurring invoice / schedule - aligned with ATW, Gatepass and Delivery Type */
.invoice-recurring-lower-slot {
    grid-column: 1 / 3;
    min-width: 0;
    align-self: start;
}

.order-recurring-section {
    width: 100%;
    max-width: 100%;
    margin: 0;
    padding: 6px 7px;
    border: 1px solid #dfe7ec;
    background: #f8fafc;
    border-radius: 5px;
    box-sizing: border-box;
}

.invoice-recurring-section {
    width: 100%;
    max-width: 100%;
    margin: 0;
}

.classic-recurring-section {
    width: 100%;
    max-width: 100%;
    margin: 6px 0 8px;
}

.order-recurring-section:has(.order-recurring-toggle input:checked) .order-recurring-fields {
    display: grid !important;
}

.order-recurring-section:has(.order-recurring-toggle input:not(:checked)) .order-recurring-fields {
    display: none !important;
}

.order-recurring-toggle {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    margin: 0;
    color: #052a47;
    font-size: 10px;
    line-height: 1.15;
    font-weight: 700;
    cursor: pointer;
    user-select: none;
}

.order-recurring-toggle input {
    width: 13px;
    height: 13px;
    margin: 0;
    cursor: pointer;
}

.order-recurring-fields {
    display: grid;
    grid-template-columns: 72px minmax(120px, 1fr) minmax(145px, 1.15fr);
    gap: 6px;
    align-items: stretch;
    margin-top: 6px;
}

.order-recurring-field {
    min-width: 0;
    padding: 5px 6px 6px;
    border: 1px solid #d8e1e7;
    border-radius: 4px;
    background: #ffffff;
    box-sizing: border-box;
}

.order-recurring-fields[hidden] {
    display: none !important;
}

.order-recurring-field label {
    display: block;
    margin: 0 0 3px;
    color: #475569;
    font-size: 8px;
    line-height: 1.05;
    font-weight: 700;
    text-transform: uppercase;
}

.order-recurring-example {
    grid-column: 1 / -1;
    margin: 0;
    padding: 1px 2px 0;
    color: #6b7280;
    font-size: 9px;
    line-height: 1.25;
}

/* Keep ATW, Gatepass and Delivery Type aligned at the top even when recurring fields expand. */
body.order-product-invoice-style .invoice-lower-fields {
    align-items: start !important;
}

body.order-product-invoice-style .invoice-atw-field,
body.order-product-invoice-style .invoice-gatepass-field,
body.order-product-invoice-style .invoice-delivery-type-field {
    align-self: start !important;
}

@media (max-width: 768px) {
    .invoice-recurring-lower-slot {
        grid-column: 1 / -1;
    }

    .order-recurring-fields {
        grid-template-columns: 1fr;
    }

    .order-recurring-example {
        grid-column: 1;
    }
}

.order-recurring-field input,
.order-recurring-field select {
    width: 100%;
    height: 28px;
    min-height: 28px;
    padding: 3px 7px;
    border: 1px solid #cfd8df;
    border-radius: 4px;
    background: #fff;
    color: #111827;
    font-size: 11px;
}
@media (max-width: 900px) {
    .invoice-recurring-section,
    .classic-recurring-section {
        width: 455px;
        max-width: 100%;
        margin-left: 0;
    }
}
@media (max-width: 576px) {
    .order-recurring-fields {
        grid-template-columns: 1fr;
        gap: 6px;
    }
    .order-recurring-field {
        padding: 6px 7px 7px;
    }
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

    
/* ===== AMGC ORDER PRODUCT DUAL STYLE UPDATE ===== */
.order-style-navbar-dropdown {
    margin-left: 8px;
    margin-right: 10px;
    flex-shrink: 0;
}
.order-style-menu-btn {
    width: 38px;
    height: 38px;
    border-radius: 10px;
    border: 1px solid #d1d5db;
    background: #ffffff;
    color: #052A47;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 2px 8px rgba(5, 42, 71, 0.08);
}
.order-style-menu-btn:hover,
.order-style-menu-btn:focus {
    background: #f3f4f6;
    color: #047857;
}
.order-style-menu {
    min-width: 185px;
    border-radius: 10px;
    border: 1px solid #e5e7eb;
    box-shadow: 0 12px 28px rgba(5, 42, 71, 0.14);
    padding: 6px;
}
.order-style-menu .style-menu-item {
    border-radius: 8px;
    font-size: 0.86rem;
    font-weight: 700;
    color: #374151;
    padding: 9px 10px;
}
.order-style-menu .style-menu-item.active,
.order-style-menu .style-menu-item:active {
    background: #047857;
    color: #ffffff;
}
body.order-product-invoice-style .classic-cart-btn {
    display: none !important;
}
body.order-product-classic-style .classic-cart-btn {
    display: inline-flex !important;
}
.invoice-style-workspace {
    display: none;
    background: #ffffff;
    border-radius: 10px;
    border: 1px solid #d7dce2;
    box-shadow: 0 10px 24px rgba(5, 42, 71, 0.07);
    overflow: hidden;
}
body.order-product-invoice-style .invoice-style-workspace {
    display: block;
}
body.order-product-invoice-style .category-tabs-container,
body.order-product-invoice-style .products-section {
    display: none !important;
}
body.order-product-classic-style .invoice-style-workspace {
    display: none !important;
}
.invoice-blue-strip {
    background: #047857;
    padding: 5px 10px;
    display: grid;
    grid-template-columns: minmax(260px, 1fr) auto minmax(260px, 1fr);
    gap: 18px;
    align-items: center;
    color: #ffffff;
    font-size: 12px;
}
.invoice-strip-field {
    display: grid;
    grid-template-columns: auto 1fr;
    align-items: center;
    gap: 6px;
}
.invoice-strip-field label {
    margin: 0;
    font-weight: 700;
    white-space: nowrap;
}
.invoice-strip-field select {
    height: 27px;
    border-radius: 2px;
    border: 1px solid #b8c1cc;
    font-size: 12px;
    padding: 2px 6px;
}

.invoice-credit-check-wrap {
    height: 27px;
    width: 32px;
    min-width: 32px;
    border-radius: 2px;
    border: 1px solid #b8c1cc;
    background: #ffffff;
    color: #052A47;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 0;
    font-size: 12px;
    font-weight: 600;
}
.invoice-credit-check-wrap input {
    width: 14px;
    height: 14px;
    margin: 0;
    accent-color: #047857;
}
.invoice-sheet {
    padding: 20px 26px 18px;
}
.invoice-sheet-head {
    display: grid;
    grid-template-columns: 1fr 455px;
    gap: 30px;
}
.invoice-title {
    font-size: 38px;
    font-weight: 400;
    color: #444;
    margin: 0;
}
.invoice-right-fields {
    display: grid;
    grid-template-columns: 110px 110px;
    gap: 24px 26px;
    justify-content: end;
}
.invoice-lower-fields {
    display: grid;
    grid-template-columns: repeat(5, minmax(120px, 1fr));
    gap: 14px 16px;
    align-items: end;
    margin-top: 28px;
}
.invoice-lower-fields .invoice-mini-field {
    min-width: 0;
}
.invoice-lower-fields .invoice-fulfillment-field {
    min-width: 0;
}

/* Keep the document fields grouped on the right side of the lower invoice row.
   Driver and Vehicle stay on the left when Delivery is selected.
   ATW, Gatepass, and Delivery Type always stay beside each other on the right. */
.invoice-lower-fields .invoice-atw-field {
    grid-column: 3 / 4;
}
.invoice-lower-fields .invoice-gatepass-field {
    grid-column: 4 / 5;
}
.invoice-lower-fields .invoice-delivery-type-field {
    grid-column: 5 / 6;
}
.invoice-mini-field label,
.invoice-message label {
    display: block;
    font-size: 10px;
    color: #6b7280;
    font-weight: 700;
    text-transform: uppercase;
    margin-bottom: 3px;
}
.invoice-mini-field input,
.invoice-mini-field select {
    height: 25px;
    border: 1px solid #cfd4da;
    background: #f2f2f2;
    border-radius: 3px;
    padding: 3px 6px;
    font-size: 12px;
    width: 100%;
}
.invoice-table-wrap {
    margin-top: 32px;
    border: 1px solid #d9dee4;
    max-height: 255px;
    overflow-y: auto;
}
.invoice-entry-table {
    width: 100%;
    border-collapse: collapse;
    table-layout: fixed;
    font-size: 12px;
}
.invoice-entry-table thead th {
    background: #ffffff;
    color: #8a8f98;
    font-weight: 500;
    text-transform: uppercase;
    border-bottom: 1px solid #d9dee4;
    border-right: 1px solid #e2e6ea;
    padding: 4px;
    height: 20px;
}
.invoice-entry-table tbody tr:nth-child(odd) td {
    background: #E6F4E6;
}
.invoice-entry-table tbody tr:nth-child(even) td {
    background: #ffffff;
}
.invoice-entry-table td {
    border-right: 1px solid #e2e6ea;
    height: 27px;
    padding: 0;
}
.invoice-entry-table select,
.invoice-entry-table input {
    width: 100%;
    height: 27px;
    border: 0;
    background: transparent;
    padding: 3px 6px;
    font-size: 12px;
    outline: 0;
}
.invoice-entry-table input[readonly] {
    color: #333;
}

body.order-product-invoice-style .invoice-entry-table .invoice-qty,
body.order-product-invoice-style .invoice-entry-table .invoice-price,
body.order-product-invoice-style .invoice-entry-table .invoice-amount {
    text-align: right;
}

body.order-product-invoice-style .invoice-entry-table .invoice-price {
    background: transparent !important;
    color: #111827;
    font-weight: 600;
    border: 0 !important;
    box-shadow: none !important;
}
body.order-product-invoice-style .invoice-entry-table .invoice-price:focus {
    outline: 1px solid #047857;
    background: transparent !important;
    box-shadow: none !important;
}
body.order-product-invoice-style .invoice-entry-table .invoice-price::-webkit-inner-spin-button,
body.order-product-invoice-style .invoice-entry-table .invoice-price::-webkit-outer-spin-button {
    opacity: 0;
}
.invoice-bottom-area {
    display: grid;
    grid-template-columns: minmax(420px, 520px) 380px;
    justify-content: space-between;
    gap: 30px;
    margin-top: 22px;
}
.invoice-message textarea {
    width: 100%;
    height: 42px;
    border: 1px solid #cfd4da;
    background: #efefef;
    resize: none;
    border-radius: 3px;
}
.invoice-totals {
    width: 100%;
}
.invoice-total-line {
    display: grid;
    grid-template-columns: 1fr 115px;
    gap: 18px;
    align-items: center;
    min-height: 24px;
    font-size: 12px;
}
.invoice-total-line span:first-child {
    text-align: right;
    color: #5f6570;
    font-weight: 700;
    text-transform: uppercase;
}
.invoice-total-line strong,
.invoice-total-line span:last-child {
    text-align: right;
}
.invoice-balance-line {
    font-size: 16px;
}
.invoice-actions {
    display: flex;
    justify-content: flex-end;
    gap: 14px;
    margin-top: 12px;
}
.invoice-actions .btn {
    min-width: 105px;
    border-radius: 3px;
    padding: 5px 12px;
    font-weight: 700;
    font-size: 12px;
}
.invoice-actions .btn-primary {
    background: #3f78d6;
    border-color: #3f78d6;
}
.invoice-help-note {
    font-size: 11px;
    color: #6b7280;
    margin-top: 8px;
}

.invoice-payment-panel {
    margin-top: 14px;
    padding: 14px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
}
.invoice-payment-toggle-row {
    display: flex;
    align-items: center;
    gap: 10px;
}
.invoice-payment-fields {
    margin-top: 12px;
}
.invoice-payment-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 10px;
    margin-top: 10px;
}
.invoice-payment-grid label {
    display: block;
    margin-bottom: 4px;
    color: #052A47;
    font-size: 12px;
    font-weight: 700;
}
.invoice-payment-full {
    grid-column: 1 / -1;
}
.invoice-payment-panel .form-check-input:checked {
    background-color: #0d6efd;
    border-color: #0d6efd;
}
@media (max-width: 992px) {
    .invoice-blue-strip,
    .invoice-sheet-head,
    .invoice-bottom-area {
        grid-template-columns: 1fr;
        gap: 12px;
    }

    .invoice-payment-grid {
        grid-template-columns: 1fr !important;
    }
    .invoice-right-fields {
        grid-template-columns: repeat(2, minmax(120px, 1fr));
        justify-content: stretch;
    }
    .invoice-table-wrap {
        margin-top: 28px;
    }
    .invoice-title {
        font-size: 30px;
    }
}



/* ===== FIX: navbar style dropdown + default style scrolling ===== */
.navbar-top {
    position: relative;
    overflow: visible !important;
    z-index: 50;
}
.order-style-navbar-dropdown {
    position: relative;
}
.order-style-navbar-dropdown .dropdown-menu,
.order-style-menu {
    position: absolute !important;
    top: calc(100% + 8px) !important;
    left: 0 !important;
    right: auto !important;
    transform: none !important;
    z-index: 99999 !important;
    min-width: 190px;
    max-width: 220px;
    background: #ffffff;
}
.order-style-navbar-dropdown .dropdown-menu.show {
    display: block !important;
}
body.order-product-invoice-style .main-content {
    overflow-y: auto !important;
    overflow-x: hidden !important;
    min-height: 0;
}
body.order-product-invoice-style .invoice-style-workspace {
    flex-shrink: 0;
    margin-bottom: 24px;
}
body.order-product-invoice-style .invoice-table-wrap {
    max-height: none !important;
    overflow-y: visible !important;
}
body.order-product-invoice-style .invoice-sheet {
    min-height: 720px;
}
@media (max-width: 768px) {
    body.order-product-invoice-style .main-content {
        padding-bottom: 95px !important;
    }
}

        .locked-customer-select,
        .locked-customer-select:disabled {
            cursor: not-allowed;
            opacity: 1;
            background-color: #f3f4f6 !important;
            color: #111827 !important;
        }

/* Invoice-style Order Details modal */
#orderDetailsModal .modal-dialog {
    max-width: 98vw;
}
#orderDetailsModal .modal-content {
    border-radius: 4px;
    overflow: hidden;
    font-family: 'Inter', Arial, Helvetica, sans-serif;
    border: 1px solid #d7e1ea;
}
#orderDetailsModal .modal-header {
    background: #047857;
    color: #fff;
    border-bottom: 0;
    padding: 10px 18px;
}
#orderDetailsModal .modal-title {
    font-size: 1.05rem;
    font-weight: 800;
    letter-spacing: .02em;
}
#orderDetailsModal .modal-body {
    background: #fff;
    padding: 0;
}
#orderDetailsModal .modal-footer {
    display: none;
}
.op-invoice-detail-view {
    color: #052A47;
    font-size: 14px;
}
.op-invoice-topbar {
    display: grid;
    grid-template-columns: minmax(280px, 1.4fr) minmax(120px, .45fr) minmax(280px, 1.2fr);
    gap: 14px;
    align-items: center;
    background: #047857;
    padding: 8px 12px;
    color: #fff;
}
.op-top-field {
    display: grid;
    grid-template-columns: auto 1fr;
    align-items: center;
    gap: 8px;
    font-weight: 800;
    min-width: 0;
}
.op-top-field span {
    white-space: nowrap;
    font-size: .78rem;
    text-transform: uppercase;
}
.op-top-field div {
    background: #fff;
    color: #0f172a;
    min-height: 34px;
    border-radius: 2px;
    padding: 7px 10px;
    font-weight: 500;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.op-invoice-main {
    padding: 22px 26px 26px;
}
.op-invoice-title {
    font-size: 2.55rem;
    line-height: 1;
    font-weight: 300;
    color: #334155;
    margin-top: 8px;
}
.op-section-label,
#orderDetailsModal label {
    font-size: .76rem;
    color: #64748b;
    font-weight: 800;
    text-transform: uppercase;
    margin-bottom: 6px;
}
.op-readonly-input,
.op-message-box {
    border: 1px solid #cfd8e3;
    border-radius: 5px;
    min-height: 38px;
    background: #f8fafc;
    color: #0f172a;
    font-size: .9rem;
}
.op-customer-box,
.op-invoice-panel {
    border: 1px solid #d7e1ea;
    border-radius: 8px;
    background: #f8fafc;
    padding: 12px 14px;
}
.op-customer-box div {
    margin-bottom: 5px;
}
.op-customer-name {
    font-size: 1rem;
    font-weight: 800;
    color: #047857;
    margin-bottom: 8px !important;
}
.op-readonly-field {
    border: 1px solid #d7e1ea;
    border-radius: 6px;
    background: #fff;
    padding: 9px 10px;
    min-height: 58px;
}
.op-readonly-field small {
    display: block;
    color: #64748b;
    font-weight: 700;
    text-transform: uppercase;
    font-size: .68rem;
    margin-bottom: 2px;
}
.op-invoice-items-wrap {
    border: 1px solid #d7e1ea;
    border-radius: 4px;
    overflow: hidden;
}
.op-invoice-items-table {
    width: 100%;
    border-collapse: collapse;
    font-size: .92rem;
}
.op-invoice-items-table thead th {
    background: #047857;
    color: #fff;
    padding: 11px 12px;
    font-size: .8rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: .02em;
    border-right: 1px solid rgba(255,255,255,.2);
}
.op-invoice-items-table td {
    padding: 10px 12px;
    min-height: 36px;
    border-right: 1px solid #d7e1ea;
    border-bottom: 1px solid #e5e7eb;
    vertical-align: middle;
}
.op-invoice-items-table tbody tr.op-invoice-alt-row,
.op-invoice-items-table tbody tr:nth-child(even) {
    background: #e8f5e9;
}
.op-invoice-items-table tfoot td {
    background: #047857;
    color: #fff;
    padding: 10px 12px;
    font-weight: 800;
}
.op-invoice-muted {
    color: #64748b;
    font-size: .82rem;
    margin-top: 2px;
}
.op-message-box {
    min-height: 58px;
    resize: none;
}
.op-payment-line,
.op-summary-box > div {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    margin-bottom: 9px;
}
.op-payment-line {
    border-bottom: 1px dashed #d7e1ea;
    padding-bottom: 7px;
    font-size: .88rem;
}
.op-invoice-empty-line {
    color: #64748b;
    font-size: .88rem;
}
.op-summary-box {
    width: min(360px, 100%);
    margin-top: 4px;
    font-size: .93rem;
}
.op-summary-box span {
    color: #475569;
    font-weight: 800;
    text-transform: uppercase;
}
.op-summary-box strong {
    color: #0f172a;
    font-weight: 700;
}
.op-summary-box .op-balance-due {
    margin-top: 6px;
    padding-top: 8px;
    border-top: 1px solid #d7e1ea;
}
.op-summary-box .op-balance-due span {
    font-size: 1.05rem;
}
.op-summary-box .op-balance-due strong {
    font-size: 1.2rem;
    font-weight: 900;
}

.op-invoice-form-view {
    color: #334155;
    font-size: 14px;
    background: #fff;
}
.op-invoice-form-view .op-invoice-topbar {
    display: grid;
    grid-template-columns: minmax(420px, 1fr) 140px minmax(420px, .9fr);
    gap: 22px;
    align-items: center;
    padding: 7px 12px;
    background: #047857;
    color: #fff;
}
.op-invoice-form-view .op-top-field {
    display: grid;
    grid-template-columns: auto 1fr;
    align-items: center;
    gap: 8px;
    font-weight: 800;
    text-transform: uppercase;
}
.op-invoice-form-view .op-top-field span {
    font-size: .78rem;
    white-space: nowrap;
}
.op-invoice-form-view .op-detail-control,
.op-invoice-form-view .op-detail-select {
    width: 100%;
    min-height: 33px;
    border: 1px solid #cfd8e3;
    border-radius: 3px;
    background: #fff;
    color: #111827;
    padding: 5px 10px;
    font-size: .92rem;
    font-weight: 500;
}
.op-invoice-form-view .op-detail-select {
    appearance: auto;
}
.op-invoice-form-view .op-detail-control[readonly],
.op-invoice-form-view .op-detail-select:disabled,
.op-invoice-form-view textarea[readonly] {
    opacity: 1;
    cursor: default;
}
.op-invoice-form-view .op-credit-box {
    width: 35px;
    height: 34px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #fff;
    border-radius: 3px;
}
.op-invoice-form-view .op-credit-box input {
    width: 18px;
    height: 18px;
}
.op-invoice-form-view .op-invoice-main {
    padding: 26px 26px 22px;
}
.op-invoice-form-view .op-invoice-sheet-head {
    display: grid;
    grid-template-columns: 1fr minmax(680px, 700px);
    gap: 26px;
    align-items: start;
}
.op-invoice-form-view .op-invoice-title {
    font-size: 3rem;
    line-height: 1;
    font-weight: 300;
    color: #334155;
    margin: 12px 0 22px;
}
.op-invoice-form-view .op-customer-readonly {
    border: 1px solid #d7e1ea;
    border-radius: 4px;
    padding: 10px 12px;
    background: #f8fafc;
    max-width: 650px;
}
.op-invoice-form-view .op-customer-readonly .name {
    font-weight: 800;
    color: #052A47;
    margin-bottom: 4px;
}
.op-invoice-form-view .op-right-fields {
    display: grid;
    grid-template-columns: repeat(4, minmax(110px, 1fr));
    gap: 22px 24px;
    align-items: end;
}
.op-invoice-form-view .op-field label,
.op-invoice-form-view .op-section-label {
    display: block;
    font-size: .76rem;
    color: #64748b;
    font-weight: 800;
    text-transform: uppercase;
    margin-bottom: 6px;
}
.op-invoice-form-view .op-field-wide {
    grid-column: span 2;
}
.op-invoice-form-view .op-items-wrap {
    margin-top: 26px;
    border: 1px solid #d7e1ea;
    border-radius: 0;
    overflow: hidden;
}
.op-invoice-form-view .op-items-table {
    width: 100%;
    border-collapse: collapse;
    table-layout: fixed;
}
.op-invoice-form-view .op-items-table thead th {
    background: #047857;
    color: #fff;
    padding: 10px 7px;
    font-size: .8rem;
    font-weight: 800;
    text-transform: uppercase;
    border-right: 1px solid rgba(255,255,255,.18);
}
.op-invoice-form-view .op-items-table td {
    height: 34px;
    padding: 7px 7px;
    border-right: 1px solid #d7e1ea;
    border-bottom: 1px solid #d7e1ea;
    vertical-align: middle;
    color: #052A47;
}
.op-invoice-form-view .op-items-table tbody tr:nth-child(odd) {
    background: #e8f5e9;
}
.op-invoice-form-view .op-items-table tfoot td {
    background: #047857;
    color: #fff;
    font-weight: 800;
    height: 38px;
}
.op-invoice-form-view .op-lower-area {
    display: grid;
    grid-template-columns: minmax(420px, 650px) 1fr;
    gap: 40px;
    margin-top: 22px;
}
.op-invoice-form-view .op-message-box {
    min-height: 54px;
    border-radius: 4px;
    background: #f3f4f6;
}
.op-invoice-form-view .op-payment-box {
    margin-top: 18px;
    border: 1px solid #d7e1ea;
    border-radius: 8px;
    background: #f8fafc;
    padding: 14px 16px;
}
.op-invoice-form-view .op-payment-toggle-line {
    display: flex;
    align-items: center;
    gap: 10px;
    font-weight: 800;
    color: #475569;
    text-transform: uppercase;
    font-size: .78rem;
    margin-bottom: 12px;
}
.op-invoice-form-view .op-payment-detail-line {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 14px;
}
.op-invoice-form-view .op-summary-box {
    width: 320px;
    margin-left: auto;
    font-size: .92rem;
}
.op-invoice-form-view .op-summary-box > div {
    display: grid;
    grid-template-columns: 1fr 120px;
    gap: 20px;
    align-items: center;
    margin-bottom: 8px;
}
.op-invoice-form-view .op-summary-box span {
    color: #475569;
    font-weight: 800;
    text-transform: uppercase;
    text-align: right;
}
.op-invoice-form-view .op-summary-box strong {
    text-align: right;
    color: #111827;
    font-weight: 600;
}
.op-invoice-form-view .op-balance-due span,
.op-invoice-form-view .op-balance-due strong {
    font-size: 1.15rem;
    font-weight: 900;
}
.op-invoice-form-view .op-detail-footer {
    display: flex;
    justify-content: flex-end;
    gap: 16px;
    margin-top: 18px;
}
.op-invoice-form-view .op-detail-footer .btn {
    min-width: 132px;
    font-weight: 800;
}

@media (max-width: 900px) {
    .op-invoice-topbar {
        grid-template-columns: 1fr;
    }
    .op-invoice-title {
        font-size: 2rem;
    }
    .op-invoice-main {
        padding: 18px;
    }
}

</style>

<style id="amgc-default-style-driver-plate-layout-fix">
/* Added Driver and Plate No. row in Default Invoice Style */
body.order-product-invoice-style .invoice-sheet-head {
    grid-template-columns: 1fr minmax(520px, 560px) !important;
    align-items: start !important;
}

body.order-product-invoice-style .invoice-right-fields {
    grid-template-columns: repeat(4, minmax(92px, 1fr)) !important;
    gap: 22px 24px !important;
    justify-content: end !important;
    align-items: end !important;
}

body.order-product-invoice-style .invoice-mini-field input,
body.order-product-invoice-style .invoice-mini-field select {
    width: 100% !important;
    min-width: 0 !important;
}

body.order-product-invoice-style .invoice-table-wrap {
    margin-top: 26px !important;
}

@media (max-width: 1200px) {
    body.order-product-invoice-style .invoice-lower-fields {
        grid-template-columns: repeat(5, minmax(110px, 1fr)) !important;
    }
}

@media (max-width: 1200px) {
    body.order-product-invoice-style .invoice-sheet-head {
        grid-template-columns: 1fr !important;
    }

    body.order-product-invoice-style .invoice-right-fields {
        grid-template-columns: repeat(2, minmax(120px, 1fr)) !important;
        justify-content: stretch !important;
        margin-top: 18px !important;
    }
}

@media (max-width: 576px) {
    body.order-product-invoice-style .invoice-right-fields {
        grid-template-columns: 1fr !important;
    }
}


/* ===== Customer List style main tabs for Order Product ===== */
.order-main-tab-pane{display:none;}
.order-main-tab-pane.active{display:block;}
.op-main-tabs-wrap{background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:10px;margin:0 0 14px 0;box-shadow:0 2px 10px rgba(5,42,71,.05);}
.op-main-tabs{display:flex;gap:8px;align-items:center;flex-wrap:wrap;}
.op-main-tab-btn{border:1px solid #d1d5db;background:#fff;color:#052A47;font-weight:700;border-radius:12px;padding:9px 14px;display:inline-flex;align-items:center;gap:8px;transition:all .2s ease;}
.op-main-tab-btn:hover{background:#f3f4f6;color:#047857;}
.op-main-tab-btn.active{background:linear-gradient(135deg,#047857 0%,#44D34E 100%);color:#fff;border-color:#047857;box-shadow:0 4px 12px rgba(4,120,87,.25);}
.op-main-tab-btn .count-badge{background:#eafbea;border:1px solid #bbf7d0;color:#047857;min-width:26px;height:22px;border-radius:999px;display:inline-flex;align-items:center;justify-content:center;font-size:.75rem;}
.op-main-tab-btn.active .count-badge{background:rgba(255,255,255,.22);border-color:rgba(255,255,255,.35);color:#fff;}
.op-sales-order-section{background:#fff;border:0;border-radius:16px;box-shadow:none;display:flex;flex-direction:column;min-height:calc(100vh - 230px);overflow:visible;gap:16px;}
.op-so-card{background:#fff;border-radius:16px;}
.op-so-filter-card{border:1px solid #bbf7d0;border-radius:16px;background:#fff;box-shadow:0 4px 14px rgba(4,120,87,.06);overflow:hidden;}
.op-so-filter-header{width:100%;border:0;background:#fff;color:#052A47;padding:18px 20px;display:flex;align-items:center;justify-content:space-between;font-weight:700;font-size:1rem;cursor:pointer;}
.op-so-filter-title{display:flex;align-items:center;gap:12px;}
.op-so-filter-icon{width:36px;height:36px;border-radius:10px;background:#dcfce7;color:#22c55e;display:inline-flex;align-items:center;justify-content:center;}
.op-so-filter-chevron{color:#047857;font-size:1.15rem;transition:transform .2s ease;}
.op-so-filter-chevron{transform:rotate(0deg);}
.op-so-filter-card:not(.collapsed) .op-so-filter-chevron{transform:rotate(180deg);}
.op-so-filter-body{border-top:1px solid #bbf7d0;margin:0 16px 0;padding:14px 0 12px;display:block;}
.op-so-toolbar{padding:14px 0 10px;border-bottom:1px solid #e5e7eb;display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap;}
.op-so-search-wrap{flex:1 1 430px;max-width:520px;}
.op-so-limit-wrap{display:flex;align-items:center;gap:8px;flex:0 0 auto;color:#052A47;font-weight:400;font-size:.88rem;white-space:nowrap;}
.op-so-limit-wrap .form-select{width:86px;height:42px;border-radius:9px;border:1px solid #d1d5db;box-shadow:none;font-weight:400;color:#052A47;padding:6px 28px 6px 10px;}
.op-so-limit-wrap .form-select:focus{border-color:#22c55e;box-shadow:0 0 0 3px rgba(34,197,94,.14);}
.op-so-pagination{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px 0 0;color:#052A47;font-weight:400;font-size:.88rem;flex-wrap:wrap;}
.op-so-pagination-controls{display:flex;align-items:center;gap:6px;flex-wrap:wrap;}
.op-so-pagination .btn{height:34px;min-width:38px;border-radius:8px;border:1px solid #d1d5db;background:#fff;color:#052A47;font-weight:400;padding:0 10px;display:inline-flex;align-items:center;justify-content:center;}
.op-so-pagination .btn:hover:not(:disabled){background:#ecfdf5;border-color:#22c55e;color:#047857;}
.op-so-pagination .btn:disabled{opacity:.45;cursor:not-allowed;}
#opSoPageIndicator{padding:0 8px;color:#047857;font-weight:400;}

/* Sales Order pagination/plain text override */
.op-so-limit-wrap,
.op-so-limit-wrap label,
.op-so-limit-wrap span,
.op-so-limit-wrap .form-select,
.op-so-pagination,
.op-so-pagination-info,
.op-so-pagination-controls,
.op-so-pagination .btn,
#opSoPageIndicator {
    font-weight: 400 !important;
}

.op-so-search-wrap .input-group{border:1px solid #d1d5db;border-radius:10px;overflow:hidden;background:#fff;}
.op-so-search-wrap .input-group-text{border:0;background:#ecfdf5;color:#047857;font-size:1.15rem;padding:0 14px;}
.op-so-search-wrap .form-control{border:0;box-shadow:none;height:48px;font-size:.95rem;}
.op-so-advanced-filters{display:grid;grid-template-columns:minmax(130px,.75fr) minmax(130px,.75fr) minmax(150px,.9fr) minmax(190px,1.25fr);gap:10px;align-items:end;width:100%;padding-top:2px;}
.op-so-field{display:flex;flex-direction:column;gap:5px;min-width:0;}
.op-so-field label{margin:0;color:#052A47;font-size:.74rem;font-weight:700;text-transform:uppercase;letter-spacing:.01em;white-space:nowrap;display:flex;align-items:center;gap:4px;}
.op-so-field label i{color:#22c55e;font-size:.82rem;}
.op-so-advanced-filters .form-select,.op-so-advanced-filters .form-control{height:40px;border-radius:9px;font-size:.82rem;min-width:0;max-width:none;border:1px solid #d1d5db;box-shadow:none;padding:7px 10px;}
.op-so-advanced-filters .form-select{padding-right:28px;}
.op-so-advanced-filters .form-select:focus,.op-so-advanced-filters .form-control:focus{border-color:#22c55e;box-shadow:0 0 0 3px rgba(34,197,94,.14);}
.op-so-filter-buttons{display:flex;gap:8px;align-items:center;justify-content:flex-end;grid-column:1 / -1;margin-top:2px;}
.op-so-advanced-filters .btn{height:38px;border-radius:9px;font-weight:700;padding:0 14px;font-size:.82rem;display:inline-flex;align-items:center;justify-content:center;gap:6px;white-space:nowrap;}
.op-so-advanced-filters .btn-success{background:linear-gradient(135deg,#047857 0%,#44D34E 100%);border-color:#047857;color:#fff;}
.op-so-advanced-filters .btn-light{background:#fff;border:1px solid #d1d5db;color:#052A47;}
.op-so-advanced-filters .btn-clear{background:#fff;border:1px solid #d1d5db;color:#ef4444;}
@media(max-width:1100px){.op-so-advanced-filters{grid-template-columns:repeat(4,minmax(0,1fr));gap:8px}.op-so-filter-buttons{justify-content:flex-end}}
@media(max-width:760px){.op-so-advanced-filters{grid-template-columns:1fr 1fr}.op-so-filter-buttons{grid-column:1 / -1;flex-wrap:wrap}.op-so-filter-buttons .btn{flex:1 1 auto}}
@media(max-width:480px){.op-so-advanced-filters{grid-template-columns:1fr}.op-so-filter-buttons{justify-content:stretch}.op-so-filter-buttons .btn{width:100%}}
.op-so-actions{display:flex;gap:10px;flex-wrap:wrap;justify-content:flex-end;margin-left:auto;}
.op-so-actions .btn{height:45px;border-radius:6px;font-weight:700;padding:0 18px;font-size:.95rem;display:inline-flex;align-items:center;gap:8px;}
.op-so-actions .btn-success,.op-so-actions .btn-outline-success{background:#10b981;border-color:#10b981;color:#fff;}
.op-so-actions .btn-outline-secondary{background:#fff;border-color:#d1d5db;color:#052A47;}
.op-so-table-wrap{overflow:auto;flex:1;min-height:0;border-radius:14px;}
.op-so-table{width:100%;border-collapse:separate;border-spacing:0;font-size:.84rem;}
.op-so-table thead th{position:sticky;top:0;z-index:5;background:#047857;color:#fff;padding:13px 10px;white-space:nowrap;font-weight:800;border:none;text-align:center;text-transform:uppercase;}
.op-so-table tbody td{padding:12px 10px;border-bottom:1px solid #eef2f7;vertical-align:middle;white-space:nowrap;text-align:center;}
.op-so-table tbody tr:hover{background:#f8fafc;}
.op-so-table tbody tr.op-so-clickable-row{cursor:pointer;}
.op-so-table tbody tr.op-so-clickable-row:hover{background:#ecfdf5;}
.op-so-money{text-align:center!important;font-weight:700;color:#052A47;}
.op-so-status{border-radius:999px;padding:4px 10px;font-size:.73rem;font-weight:800;display:inline-flex;align-items:center;text-transform:capitalize;}
.op-so-status.pending{background:#fef3c7;color:#92400e;}
.op-so-status.confirmed,.op-so-status.processing,.op-so-status.ready,.op-so-status.in_transit{background:#dbeafe;color:#1e40af;}
.op-so-status.delivered{background:#dcfce7;color:#166534;}
.op-so-status.cancelled{background:#fee2e2;color:#991b1b;}


/* ===== Sales Order tab font/table cleanup to match sales_order.php ===== */
#salesOrderTabContent,
#salesOrderTabContent .op-so-filter-card,
#salesOrderTabContent .op-so-toolbar,
#salesOrderTabContent .op-so-table,
#salesOrderTabContent .op-so-table th,
#salesOrderTabContent .op-so-table td,
#salesOrderTabContent .btn,
#salesOrderTabContent input,
#salesOrderTabContent select,
#salesOrderTabContent label {
    font-family: system-ui, -apple-system, sans-serif !important;
    letter-spacing: 0.01em;
}

#salesOrderTabContent .op-so-table-wrap {
    overflow: auto !important;
    border-radius: 14px !important;
    border: 1px solid #d9f7e5 !important;
    background: #fff !important;
    box-shadow: 0 4px 14px rgba(4,120,87,.06) !important;
}

#salesOrderTabContent .op-so-table {
    width: 100% !important;
    border-collapse: collapse !important;
    border-spacing: 0 !important;
    margin: 0 !important;
    font-size: 0.9rem !important;
    color: #052A47 !important;
}

#salesOrderTabContent .op-so-table thead th {
    background: #047857 !important;
    color: #ffffff !important;
    padding: 0.85rem 1.25rem !important;
    font-size: 0.8rem !important;
    font-weight: 600 !important;
    text-transform: uppercase !important;
    white-space: nowrap !important;
    text-align: center !important;
    vertical-align: middle !important;
    border: none !important;
    letter-spacing: 0.02em !important;
}

#salesOrderTabContent .op-so-table tbody tr {
    background: #ffffff !important;
    border-bottom: 1px solid #d9f7e5 !important;
    transition: background-color .18s ease !important;
}

#salesOrderTabContent .op-so-table tbody tr:hover {
    background: #ecfdf5 !important;
}

#salesOrderTabContent .op-so-table tbody td {
    padding: 0.85rem 1.25rem !important;
    font-size: 0.9rem !important;
    font-weight: 400 !important;
    vertical-align: middle !important;
    border-bottom: 1px solid #d9f7e5 !important;
    color: #052A47 !important;
    text-align: center !important;
    white-space: nowrap !important;
}

#salesOrderTabContent .op-so-table tbody td:first-child {
    font-weight: 700 !important;
    color: #047857 !important;
}

#salesOrderTabContent .op-so-money {
    font-family: system-ui, -apple-system, sans-serif !important;
    font-weight: 700 !important;
    color: #052A47 !important;
}

#salesOrderTabContent .op-so-status {
    font-family: system-ui, -apple-system, sans-serif !important;
    border-radius: 999px !important;
    padding: 0.35rem 0.75rem !important;
    font-size: 0.76rem !important;
    font-weight: 700 !important;
    text-transform: capitalize !important;
}

#salesOrderTabContent .action-buttons {
    display: flex !important;
    justify-content: center !important;
    align-items: center !important;
    gap: 0.5rem !important;
}

#salesOrderTabContent .btn-action {
    width: 34px !important;
    height: 34px !important;
    border-radius: 8px !important;
    font-size: 0.9rem !important;
}

#salesOrderTabContent .op-so-empty {
    font-family: system-ui, -apple-system, sans-serif !important;
    color: #64748b !important;
    padding: 2rem !important;
}
.op-so-action-btn{width:32px;height:32px;border-radius:9px;display:inline-flex;align-items:center;justify-content:center;}
.op-so-empty{padding:44px 16px;text-align:center;color:#6b7280;}
@media(max-width:1100px){.op-so-actions{justify-content:flex-start;margin-left:0}.op-so-search-wrap{max-width:none}.op-so-limit-wrap{order:3}}
@media(max-width:768px){.op-so-toolbar{align-items:stretch}.op-so-actions{width:100%}.op-so-actions .btn{width:100%;justify-content:center}.op-so-limit-wrap{width:100%;justify-content:flex-start}.op-so-pagination{align-items:flex-start}.op-so-pagination-controls{width:100%;justify-content:flex-start}}

/* Sales Order tab action buttons, same behavior/style names as sales_order.php */
.action-buttons{display:flex;align-items:center;justify-content:center;gap:6px;flex-wrap:nowrap;}
.btn-action{width:32px;height:32px;border:0;border-radius:9px;display:inline-flex;align-items:center;justify-content:center;font-size:.9rem;transition:all .18s ease;cursor:pointer;}
.btn-action:hover{transform:translateY(-1px);box-shadow:0 4px 10px rgba(0,0,0,.12);}
.btn-si{background:#0ea5e9;color:#fff;}
.btn-si:hover{background:#0284c7;color:#fff;}
.btn-view{background:#e0f2fe;color:#0369a1;}
.btn-print{background:#dcfce7;color:#047857;}
.btn-edit{background:#fef3c7;color:#b45309;}
.btn-delete{background:#fee2e2;color:#b91c1c;}

/* ===== AMGC grouped dropdown + invoice selected category fix ===== */
#modalCustomerSelect option.dropdown-group-header-option,
#invoiceCustomerSelect option.dropdown-group-header-option,
#modalCustomerSelect option.customer-group-header-option,
#invoiceCustomerSelect option.customer-group-header-option,
.invoice-item-select option.invoice-product-category-header-option {
    background-color: #E7F4E8 !important;
    color: #00785F !important;
    font-weight: 800 !important;
    font-style: normal !important;
    padding: 4px 12px !important;
    text-transform: none !important;
}

#modalCustomerSelect option:not(.dropdown-group-header-option),
#invoiceCustomerSelect option:not(.dropdown-group-header-option),
.invoice-item-select option:not(.invoice-product-category-header-option) {
    background-color: #ffffff !important;
    color: #003B65 !important;
    font-weight: 400 !important;
    padding-left: 12px !important;
}

body.order-product-invoice-style #invoiceItemsBody .invoice-product-cell {
    position: relative !important;
    padding-right: 0 !important;
}

body.order-product-invoice-style #invoiceItemsBody .invoice-product-wrap {
    position: relative !important;
    width: 100% !important;
}

body.order-product-invoice-style #invoiceItemsBody .invoice-product-cell .invoice-item-select {
    width: 100% !important;
    padding-right: 130px !important;
}

body.order-product-invoice-style #invoiceItemsBody .invoice-selected-category {
    display: none;
    position: absolute !important;
    right: 28px !important;
    top: 50% !important;
    transform: translateY(-50%) !important;
    max-width: 115px !important;
    overflow: hidden !important;
    text-overflow: ellipsis !important;
    white-space: nowrap !important;
    pointer-events: none !important;
    color: #00785F !important;
    background: transparent !important;
    font-size: 11px !important;
    font-weight: 800 !important;
    line-height: 1 !important;
    text-align: right !important;
}

body.order-product-invoice-style #invoiceItemsBody .invoice-selected-category.show {
    display: block !important;
}

.product-category-header-row td {
    background: #E7F4E8 !important;
    color: #00785F !important;
    border-top: 2px solid #2C6FA3 !important;
    font-weight: 800 !important;
    padding: 4px 12px !important;
}

</style>
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>


<style id="amgc-recurring-actual-fixed-v12">
/* ================= ACTUAL PAGE RECURRING LAYOUT ================= */

/* Invoice lower row: recurring + ATW + Gatepass + Delivery Type in one fixed row. */
body.order-product-invoice-style .invoice-lower-fields {
    display: grid !important;
    grid-template-columns: 2fr 1fr 1fr 1fr !important;
    column-gap: 16px !important;
    row-gap: 8px !important;
    align-items: start !important;
    margin-top: 28px !important;
}

/* Recurring panel occupies the left wide column. */
body.order-product-invoice-style:not(.credit-recurring-mode) .invoice-recurring-lower-slot {
    display: block !important;
    grid-column: 1 !important;
    grid-row: 1 !important;
    width: 100% !important;
    min-width: 0 !important;
    margin: 0 !important;
    padding: 0 !important;
    align-self: start !important;
}

/* Put the INPUT boxes of ATW/Gatepass/Delivery Type level with the recurring card top.
   Their labels remain immediately above their inputs. */
body.order-product-invoice-style:not(.credit-recurring-mode) .invoice-atw-field {
    grid-column: 2 !important;
    grid-row: 1 !important;
}
body.order-product-invoice-style:not(.credit-recurring-mode) .invoice-gatepass-field {
    grid-column: 3 !important;
    grid-row: 1 !important;
}
body.order-product-invoice-style:not(.credit-recurring-mode) .invoice-delivery-type-field {
    grid-column: 4 !important;
    grid-row: 1 !important;
}

body.order-product-invoice-style:not(.credit-recurring-mode) .invoice-atw-field,
body.order-product-invoice-style:not(.credit-recurring-mode) .invoice-gatepass-field,
body.order-product-invoice-style:not(.credit-recurring-mode) .invoice-delivery-type-field {
    align-self: start !important;
    margin-top: -15px !important;
    min-width: 0 !important;
}

/* Hide driver and vehicle in the compact invoice header row unless another script explicitly shows them. */
body.order-product-invoice-style .invoice-lower-fields > .invoice-delivery-field {
    display: none;
}

/* Recurring card dimensions. */
body.order-product-invoice-style .invoice-recurring-section {
    width: 100% !important;
    max-width: none !important;
    margin: 0 !important;
    box-sizing: border-box !important;
}

/* CREDIT MODE:
   recurring panel is placed on a NEW ROW directly below Date through Due Date. */
body.order-product-invoice-style .invoice-right-fields {
    align-items: start !important;
}

body.order-product-invoice-style .invoice-recurring-credit-slot {
    display: none !important;
    grid-column: 1 / -1 !important;
    grid-row: 2 !important;
    width: 100% !important;
    min-width: 0 !important;
    margin: 0 !important;
    padding: 0 !important;
}

body.order-product-invoice-style.credit-recurring-mode .invoice-recurring-credit-slot {
    display: block !important;
}

body.order-product-invoice-style.credit-recurring-mode .invoice-recurring-credit-slot .invoice-recurring-section {
    width: 100% !important;
    max-width: none !important;
    margin: 0 !important;
}

body.order-product-invoice-style.credit-recurring-mode .invoice-recurring-lower-slot {
    display: none !important;
}

/* Hide the lower ATW/Gatepass/Delivery row in Credit mode. */
body.order-product-invoice-style.credit-recurring-mode .invoice-lower-fields {
    display: none !important;
}

/* Compact details inside recurring card. */
body.order-product-invoice-style .order-recurring-fields {
    grid-template-columns: 72px minmax(125px, 1fr) minmax(150px, 1.2fr) !important;
    gap: 6px !important;
}
body.order-product-invoice-style .order-recurring-example {
    grid-column: 1 / -1 !important;
}

/* Mobile fallback. */
@media (max-width: 900px) {
    body.order-product-invoice-style .invoice-lower-fields {
        grid-template-columns: 1fr !important;
    }

    body.order-product-invoice-style:not(.credit-recurring-mode) .invoice-recurring-lower-slot,
    body.order-product-invoice-style:not(.credit-recurring-mode) .invoice-atw-field,
    body.order-product-invoice-style:not(.credit-recurring-mode) .invoice-gatepass-field,
    body.order-product-invoice-style:not(.credit-recurring-mode) .invoice-delivery-type-field {
        grid-column: 1 !important;
        grid-row: auto !important;
        margin-top: 0 !important;
    }

    body.order-product-invoice-style .order-recurring-fields {
        grid-template-columns: 1fr !important;
    }
}
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
                <!-- Dashboard -->
                <li class="nav-item">
                    <a class="nav-link" href="branchdashboard.php">
                        <i class="bi bi-speedometer2"></i>
                        <span class="nav-text">Dashboard</span>
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
                                <a class="nav-link active" href="orderproduct.php">
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
                                <a class="nav-link" href="customer_list.php">
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
                            
                            <li class="nav-item active">
                                <a class="nav-link" href="transferfunds.php">
                                    <i class="bi bi-arrow-left-right"></i>
                                    <span class="nav-text">Transfer Funds</span>
                                </a>
                            </li>
                            
                            <li class="nav-item" hidden>
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

        <!-- Main Content Area -->
        <div class="main-content">
            <div class="navbar-top">
                <button class="mobile-toggle-btn" id="mobileToggleBtn">
                    <i class="bi bi-list"></i>
                </button>

                <div class="dropdown order-style-navbar-dropdown">
                    <button class="btn order-style-menu-btn" type="button" id="orderStyleMenuBtn" data-bs-toggle="dropdown" aria-expanded="false" title="Order Product Style">
                        <i class="bi bi-three-dots-vertical"></i>
                    </button>
                    <ul class="dropdown-menu order-style-menu" aria-labelledby="orderStyleMenuBtn">
                        <li>
                            <button type="button" class="dropdown-item style-menu-item" id="invoiceStyleBtn" onclick="setOrderProductStyle('invoice')">
                                <i class="bi bi-receipt me-2"></i> Default Style
                            </button>
                        </li>
                        <li>
                            <button type="button" class="dropdown-item style-menu-item" id="classicStyleBtn" onclick="setOrderProductStyle('classic')">
                                <i class="bi bi-grid me-2"></i> Classic Style
                            </button>
                        </li>
                    </ul>
                </div>

                <div class="page-title">
                    <h2>Order Products</h2>
                    <p>Select products and quantities to create an order</p>
                </div>
                <button class="btn btn-success position-relative classic-cart-btn" id="classicCartBtn" type="button" onclick="viewCart()" title="View Cart">
                    <i class="bi bi-cart3"></i>
                    <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill" id="cartBadge" style="display: none;">
                        <span id="cartItemCount">0</span>
                    </span>
                </button>
            </div>


            <div class="op-main-tabs-wrap no-print">
                <div class="op-main-tabs" role="tablist" aria-label="Order Product Tabs">
                    <button type="button" class="op-main-tab-btn active" id="opCreateOrderTabBtn" data-tab="createOrderTabContent" onclick="switchOrderProductMainTab('createOrderTabContent')">
                        <i class="bi bi-cart-plus"></i><span>Create Invoice</span>
                    </button>
                    <button type="button" class="op-main-tab-btn" id="opSalesOrderTabBtn" data-tab="salesOrderTabContent" onclick="switchOrderProductMainTab('salesOrderTabContent')">
                        <i class="bi bi-receipt-cutoff"></i><span>Sales Order</span><span class="count-badge"><?php echo (int)$op_sales_order_count; ?></span>
                    </button>
                </div>
            </div>

            <div class="order-main-tab-pane active" id="createOrderTabContent">
            <!-- Default Invoice Style Workspace -->
            <div class="invoice-style-workspace" id="invoiceStyleWorkspace">
                <div class="invoice-blue-strip">
                    <div class="invoice-strip-field">
                        <label for="invoiceCustomerSelect">CUSTOMER:</label>
                        <select id="invoiceCustomerSelect" <?php echo $is_customer_locked ? 'disabled' : ''; ?>></select>
                    </div>
                    <div class="invoice-strip-field invoice-credit-field">
                        <label for="invoiceCreditCheckbox">CREDIT:</label>
                        <div class="invoice-credit-check-wrap">
                            <input type="checkbox" id="invoiceCreditCheckbox" onchange="toggleBillingTypeFields(); syncInvoiceFieldsToOriginalForm();">
                        </div>
                    </div>
                    <div class="invoice-strip-field">
                        <label for="invoiceReceivableAccount">ACCOUNTS RECEIVABLE</label>
                        <select id="invoiceReceivableAccount">
                            <option value="Accounts Receivable">Accounts Receivable</option>
                        </select>
                    </div>
                </div>

                <div class="invoice-sheet">
                    <div class="invoice-sheet-head">
                        <div>
                            <h1 class="invoice-title" id="invoiceDocumentTitle">Invoice</h1>
                        </div>
                        <div class="invoice-right-fields">
                            <div class="invoice-mini-field">
                                <label for="invoiceOrderDate">Date</label>
                                <input type="date" id="invoiceOrderDate">
                            </div>
                            <div class="invoice-mini-field">
                                <label for="invoiceNumberVisual">Invoice #</label>
                                <input type="text" id="invoiceNumberVisual" placeholder="Auto">
                            </div>
                            <div class="invoice-mini-field">
                                <label for="invoiceTerms">Terms</label>
                                <select id="invoiceTerms">
                                    <option value=""></option>
                                    <option value="Due on receipt">Due on receipt</option>
                                    <option value="Net 7">Net 7</option>
                                    <option value="Net 15">Net 15</option>
                                    <option value="Net 30">Net 30</option>
                                </select>
                            </div>
                            <div class="invoice-mini-field">
                                <label for="invoiceDueDate">Due Date</label>
                                <input type="date" id="invoiceDueDate">
                            </div>
                            <div id="invoiceRecurringCreditSlot" class="invoice-recurring-credit-slot"></div>

                        </div>
                    </div>

                    <div class="invoice-lower-fields">
                        <div class="invoice-recurring-lower-slot">
                            <div class="order-recurring-section invoice-recurring-section" id="invoiceRecurringSection">
                        <label class="order-recurring-toggle" for="invoiceRecurringEnabled">
                            <input type="checkbox" id="invoiceRecurringEnabled" onchange="toggleOrderRecurring('invoice')" onclick="toggleOrderRecurring('invoice')">
                            <span>Recurring invoice / schedule</span>
                        </label>
                        <div class="order-recurring-fields" id="invoiceRecurringFields" style="display:none;">
                            <div class="order-recurring-field">
                                <label for="invoiceRecurringEvery">Every</label>
                                <input type="number" id="invoiceRecurringEvery" min="1" step="1" value="1">
                            </div>
                            <div class="order-recurring-field">
                                <label for="invoiceRecurringPeriod">Period</label>
                                <select id="invoiceRecurringPeriod">
                                    <option value="day">Day(s)</option>
                                    <option value="week">Week(s)</option>
                                    <option value="month" selected>Month(s)</option>
                                    <option value="year">Year(s)</option>
                                </select>
                            </div>
                            <div class="order-recurring-field">
                                <label for="invoiceRecurringUntil">Until Date</label>
                                <input type="date" id="invoiceRecurringUntil">
                            </div>
                            <div class="order-recurring-example">Example: Every 1 month until Dec 31, 2026 for recurring invoice reminders.</div>
                        </div>
                    </div>
                        </div>
                        <div class="invoice-mini-field invoice-delivery-field">
                            <label for="invoiceDriverSelect">Driver</label>
                            <select id="invoiceDriverSelect">
                                <option value=""></option>
                                <?php foreach ($delivery_drivers as $driver): ?>
                                    <option value="<?php echo (int)$driver['driver_id']; ?>"
                                            data-vehicle-type="<?php echo htmlspecialchars($driver['vehicle_type'] ?? ''); ?>"
                                            data-plate="<?php echo htmlspecialchars($driver['vehicle_plate_number'] ?? ''); ?>">
                                        <?php echo htmlspecialchars($driver['driver_name']); ?><?php echo !empty($driver['license_number']) ? ' - ' . htmlspecialchars($driver['license_number']) : ''; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="invoice-mini-field invoice-delivery-field">
                            <label for="invoiceVehicleSelect">Vehicle</label>
                            <select id="invoiceVehicleSelect">
                                <option value=""></option>
                                <?php foreach ($delivery_vehicles as $vehicle): ?>
                                    <option value="<?php echo (int)$vehicle['vehicle_id']; ?>">
                                        <?php echo htmlspecialchars(trim(($vehicle['vehicle_type'] ?? 'Vehicle') . ' - ' . ($vehicle['plate_number'] ?? ''))); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="invoice-mini-field invoice-atw-field">
                            <label for="invoiceAtwNo">ATW No.</label>
                            <input type="text" id="invoiceAtwNo" maxlength="6" inputmode="numeric" placeholder="Optional">
                        </div>
                        <div class="invoice-mini-field invoice-gatepass-field">
                            <label for="invoiceGatepassNo">Gatepass No.</label>
                            <input type="text" id="invoiceGatepassNo" maxlength="6" inputmode="numeric" placeholder="Required">
                        </div>
                        <div class="invoice-mini-field invoice-fulfillment-field invoice-delivery-type-field">
                            <label for="invoiceDeliveryType">Delivery Type</label>
                            <select id="invoiceDeliveryType" name="invoiceDeliveryType" class="invoice-input">
                                <option value="pickup" selected>Pick Up</option>
                                <option value="delivery">Delivery</option>
                            </select>
                        </div>
                    </div>




                    <div class="invoice-table-wrap">
                        <table class="invoice-entry-table">
                            <thead>
                                <tr>
                                    <th style="width: 34%;">PRODUCT</th>
                                    <th style="width: 18%;">UNIT</th>
                                    <th style="width: 12%;">QTY</th>
                                    <th style="width: 18%;">PRICE</th>
                                    <th style="width: 18%;">TOTAL</th>
                                </tr>
                            </thead>
                            <tbody id="invoiceItemsBody"></tbody>
                        </table>
                    </div>

                    <div class="invoice-bottom-area">
                        <div class="invoice-message">
                            <label for="invoiceCustomerMessage">Customer Message</label>
                            <textarea id="invoiceCustomerMessage"></textarea>
                            <div class="invoice-payment-panel" id="invoicePaymentPanel">
                                <div class="invoice-payment-toggle-row">
                                    <div class="form-check form-switch mb-0">
                                        <input class="form-check-input" type="checkbox" id="invoiceCollectPayment">
                                        <label class="form-check-label" for="invoiceCollectPayment">Collect payment now</label>
                                    </div>
                                </div>
                                <div id="invoicePaymentFields" class="invoice-payment-fields" style="display:none;">
                                    <div class="invoice-payment-grid">
                                        <div>
                                            <label for="invoicePaymentMethod">Payment Method</label>
                                            <select id="invoicePaymentMethod" class="form-select form-select-sm">
                                                <option value="cash">Cash</option>
                                                <option value="check">Check</option>
                                                <option value="online_transfer">Online Transfer</option>
                                            </select>
                                        </div>
                                        <div class="invoice-cash-field">
                                            <label for="invoiceCashTendered">Cash Tendered</label>
                                            <input type="text" inputmode="decimal" class="form-control form-control-sm no-spinner money-input" id="invoiceCashTendered" placeholder="Amount received">
                                            <small class="text-muted">Change: <span id="invoiceCashChange">₱0.00</span></small>
                                        </div>
                                    </div>
                                    <div class="invoice-payment-grid invoice-check-fields" style="display:none;">
                                        <div><label for="invoiceCheckDate">Check Date</label><input type="date" class="form-control form-control-sm" id="invoiceCheckDate"></div>
                                        <div><label for="invoiceCheckNumber">Check Number</label><input type="text" class="form-control form-control-sm" id="invoiceCheckNumber"></div>
                                        <div><label for="invoiceCheckPaymentAmount">Payment Amount (₱)</label><input type="text" inputmode="decimal" class="form-control form-control-sm no-spinner money-input" id="invoiceCheckPaymentAmount" placeholder="0.00"></div>
                                        <div class="invoice-payment-full"><label for="invoiceBankBranch">Bank/Branch</label><input type="text" class="form-control form-control-sm" id="invoiceBankBranch" placeholder="e.g. BDO - Tanauan Branch"></div>
                                    </div>
                                    <div class="invoice-payment-grid invoice-online-fields" style="display:none;">
                                        <div><label for="invoiceReferenceNumber">Reference Number</label><input type="text" class="form-control form-control-sm" id="invoiceReferenceNumber"></div>
                                        <div><label for="invoiceOnlineBankName">Bank/Wallet</label><input type="text" class="form-control form-control-sm" id="invoiceOnlineBankName" placeholder="GCash, Maya, BPI, etc."></div>
                                        <div><label for="invoiceOnlinePaymentAmount">Payment Amount (₱)</label><input type="text" inputmode="decimal" class="form-control form-control-sm no-spinner money-input" id="invoiceOnlinePaymentAmount" placeholder="0.00"></div>
                                        <div class="invoice-payment-full"><label for="invoiceOnlineBankBranch">Branch / Account Note</label><input type="text" class="form-control form-control-sm" id="invoiceOnlineBankBranch" placeholder="Optional"></div>
                                    </div>
                                </div>
                            </div>
                            <div class="invoice-help-note">Default style only changes the layout. The same order saving function is still used.</div>
                        </div>
                        <div>
                            <div class="invoice-totals">
                                <div class="invoice-total-line">
                                    <span id="invoiceDiscountLabel">(0.0%)</span>
                                    <span id="invoiceDiscountAmount">0.00</span>
                                </div>
                                <div class="invoice-total-line">
                                    <span>Total</span>
                                    <span id="invoiceTotalAmount">0.00</span>
                                </div>
                                <div class="invoice-total-line">
                                    <span>Payments Applied</span>
                                    <span>0.00</span>
                                </div>
                                <div class="invoice-total-line invoice-balance-line">
                                    <span>Balance Due</span>
                                    <strong id="invoiceBalanceDue">0.00</strong>
                                </div>
                            </div>

                            <div class="invoice-actions">
                                <button type="button" class="btn btn-light border" id="invoiceSaveCloseBtn" onclick="invoiceSaveAndClose()">Save & Close</button>
                                <button type="button" class="btn btn-primary" id="invoiceSaveNewBtn" onclick="invoiceSaveAndNew()">Save & New</button>
                                <button type="button" class="btn btn-light border" onclick="clearInvoiceStyleRows()">Clear</button>
                            </div>
                        </div>
                    </div>
                </div>
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
                    <div aria-hidden="true" style="position:absolute;left:-10000px;top:auto;width:1px;height:1px;overflow:hidden;">
                        <input type="text" name="username" autocomplete="username" tabindex="-1">
                        <input type="password" name="password" autocomplete="current-password" tabindex="-1">
                        <input type="email" name="email" autocomplete="email" tabindex="-1">
                    </div>
                    <div class="search-wrapper">
                        <i class="bi bi-search search-icon"></i>
                        <input
                            type="text"
                            id="productSearch"
                            name="product_keyword_search_<?php echo (int)$user_id; ?>_<?php echo time(); ?>"
                            class="search-input"
                            placeholder="Search products..."
                            autocomplete="off"
                            autocorrect="off"
                            autocapitalize="off"
                            spellcheck="false"
                            inputmode="search"
                            data-form-type="other"
                            data-lpignore="true"
                            data-1p-ignore="true"
                            data-bwignore="true"
                            data-no-autofill="true"
/>
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
            </div><!-- /createOrderTabContent -->

            <div class="order-main-tab-pane" id="salesOrderTabContent">
            <div class="op-sales-order-section" id="opSalesOrderSection">
                <div class="op-so-card">
                    <div class="op-so-filter-card no-print collapsed" id="opSoFilterCard">
                        <button type="button" class="op-so-filter-header" onclick="toggleOpSalesOrderFilters()">
                            <span class="op-so-filter-title">
                                <span class="op-so-filter-icon"><i class="bi bi-funnel-fill"></i></span>
                                <span>Search &amp; Filters</span>
                            </span>
                            <i class="bi bi-chevron-down op-so-filter-chevron"></i>
                        </button>

                        <div class="op-so-filter-body" id="opSoFilterBody" style="display:none;">
                            <?php
                                $op_so_customer_filter_options = [];
                                if (!empty($op_sales_orders)) {
                                    foreach ($op_sales_orders as $op_so_filter_order) {
                                        $op_so_filter_customer_id = (int)($op_so_filter_order['customer_id'] ?? 0);
                                        $op_so_filter_customer_name = trim((string)($op_so_filter_order['customer_name'] ?? ''));
                                        if ($op_so_filter_customer_id > 0 && $op_so_filter_customer_name !== '') {
                                            $op_so_customer_filter_options[$op_so_filter_customer_id] = $op_so_filter_customer_name;
                                        }
                                    }
                                    asort($op_so_customer_filter_options);
                                }
                            ?>
                            <div class="op-so-advanced-filters">
                                <div class="op-so-field date-field">
                                    <label for="opSoStartDate"><i class="bi bi-calendar"></i> From:</label>
                                    <input type="date" class="form-control" id="opSoStartDate" onchange="filterEmbeddedSalesOrders()" title="Start Date">
                                </div>
                                <div class="op-so-field date-field">
                                    <label for="opSoEndDate"><i class="bi bi-calendar"></i> To:</label>
                                    <input type="date" class="form-control" id="opSoEndDate" onchange="filterEmbeddedSalesOrders()" title="End Date">
                                </div>
                                <div class="op-so-field select-field">
                                    <label for="opSoStatus"><i class="bi bi-flag"></i> STATUS</label>
                                    <select class="form-select" id="opSoStatus" onchange="filterEmbeddedSalesOrders()">
                                        <option value="">All Status</option>
                                        <option value="pending">Pending</option>
                                        <option value="confirmed">Confirmed</option>
                                        <option value="processing">Processing</option>
                                        <option value="ready">Ready</option>
                                        <option value="in_transit">In Transit</option>
                                        <option value="delivered">Delivered</option>
                                        <option value="cancelled">Cancelled</option>
                                    </select>
                                </div>
                                <div class="op-so-field select-field">
                                    <label for="opSoCustomer"><i class="bi bi-people"></i> CUSTOMER</label>
                                    <select class="form-select" id="opSoCustomer" onchange="filterEmbeddedSalesOrders()">
                                        <option value="">All Customers</option>
                                        <?php foreach ($op_so_customer_filter_options as $op_so_filter_customer_id => $op_so_filter_customer_name): ?>
                                            <option value="<?php echo (int)$op_so_filter_customer_id; ?>"><?php echo htmlspecialchars($op_so_filter_customer_name); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="op-so-filter-buttons">
                                    <button type="button" class="btn btn-success" onclick="filterEmbeddedSalesOrders()">
                                        <i class="bi bi-funnel"></i> Apply
                                    </button>
                                    <button type="button" class="btn btn-light" onclick="setEmbeddedSalesOrderWeekFilter()">
                                        <i class="bi bi-calendar-week"></i> Week
                                    </button>
                                    <button type="button" class="btn btn-clear" onclick="resetEmbeddedSalesOrderFilters()">
                                        <i class="bi bi-x-circle"></i> Clear
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="op-so-toolbar no-print">
                        <div class="op-so-search-wrap">
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-search"></i></span>
                                <input type="text" class="form-control" id="opSoSearch" placeholder="Search order number or customer..." oninput="filterEmbeddedSalesOrders()">
                            </div>
                        </div>

                        <div class="op-so-limit-wrap">
                            <label for="opSoPageLength">Show</label>
                            <select class="form-select" id="opSoPageLength" onchange="changeEmbeddedSalesOrderPageLength(this.value)">
                                <option value="10" selected>10</option>
                                <option value="20">20</option>
                                <option value="50">50</option>
                                <option value="100">100</option>
                            </select>
                            <span>entries</span>
                        </div>

                        <div class="op-so-actions">
                            <button type="button" class="btn btn-success" onclick="printEmbeddedSalesOrders()">
                                <i class="bi bi-printer"></i> Print All Orders
                            </button>
                            <button type="button" class="btn btn-success" onclick="exportEmbeddedSalesOrders()">
                                <i class="bi bi-file-earmark-excel"></i> Export to Excel
                            </button>
                        </div>
                    </div>

                    <?php if (!empty($op_sales_order_error)): ?>
                        <div class="alert alert-warning m-3 mb-0">
                            <i class="bi bi-exclamation-triangle"></i>
                            Sales Order data could not be loaded: <?php echo htmlspecialchars($op_sales_order_error); ?>
                        </div>
                    <?php endif; ?>

                    <div class="op-so-table-wrap">
                        <table class="op-so-table" id="opSalesOrderTable">
                            <thead>
                                <tr>
                                    <th>Order No.</th>
                                    <th>Date</th>
                                    <th>Customer</th>
                                    <th class="text-end">Order Amount</th>
                                    <th>Order Status</th>
                                    <th class="text-center no-print">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($op_sales_orders)): ?>
                                    <tr class="op-so-empty-row">
                                        <td colspan="6">
                                            <div class="op-so-empty">
                                                <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                                                No sales orders found.
                                            </div>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($op_sales_orders as $order): 
                                        $status = strtolower(trim((string)($order['order_status'] ?? 'pending')));
                                        $orderDateRaw = $order['order_date'] ?? '';
                                        $orderDateDisplay = $orderDateRaw ? date('M d, Y', strtotime($orderDateRaw)) : '-';
                                        $orderDateFilter = $orderDateRaw ? date('Y-m-d', strtotime($orderDateRaw)) : '';
                                        $hasOrderSI = trim((string)($order['si_number'] ?? '')) !== '';
                                        $searchBlob = strtolower(
                                            ($order['so_number'] ?? '') . ' ' .
                                            ($order['customer_code'] ?? '') . ' ' .
                                            ($order['store_name'] ?? '') . ' ' .
                                            ($order['customer_name'] ?? '') . ' ' .
                                            ($order['branch_name'] ?? '') . ' ' .
                                            ($order['encoded_by'] ?? '')
                                        );
                                    ?>
                                        <tr class="op-so-clickable-row" data-status="<?php echo htmlspecialchars($status); ?>" data-date="<?php echo htmlspecialchars($orderDateFilter); ?>" data-customer="<?php echo (int)($order['customer_id'] ?? 0); ?>" data-search="<?php echo htmlspecialchars($searchBlob); ?>" onclick="openSalesOrderRowDetails(event, <?php echo (int)$order['so_id']; ?>)">
                                            <td class="fw-bold text-success"><?php echo htmlspecialchars($order['so_number'] ?? ''); ?></td>
                                            <td><?php echo htmlspecialchars($orderDateDisplay); ?></td>
                                            <td><?php echo htmlspecialchars($order['customer_name'] ?? 'Walk-in Customer'); ?></td>
                                            <td class="op-so-money text-end">₱<?php echo number_format((float)($order['display_total'] ?? 0), 2); ?></td>
                                            <td><span class="op-so-status <?php echo htmlspecialchars($status); ?>"><?php echo htmlspecialchars(str_replace('_', ' ', $status)); ?></span></td>
                                            <td class="no-print text-center">
                                                <div class="action-buttons d-flex gap-1 justify-content-center">
                                                    <button type="button" class="btn-action btn-print op-so-action-btn" title="Print Order" onclick="printSingleOrder(<?php echo (int)$order['so_id']; ?>)">
                                                        <i class="bi bi-printer"></i>
                                                    </button>
                                                    <?php if (in_array($status, ['pending', 'confirmed', 'delivered'], true) && !$hasOrderSI): ?>
                                                        <button type="button" class="btn-action btn-si op-so-action-btn" title="Issue SI" onclick="openSIActionModal(<?php echo (int)$order['so_id']; ?>)">
                                                            <i class="bi bi-receipt-cutoff"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                    <?php if ($status === 'pending'): ?>
                                                        <button type="button" class="btn-action btn-edit op-so-action-btn" title="Edit" onclick="editOrder(<?php echo (int)$order['so_id']; ?>)">
                                                            <i class="bi bi-pencil"></i>
                                                        </button>
                                                        <button type="button" class="btn-action btn-delete op-so-action-btn" title="Delete" onclick="deleteOrder(<?php echo (int)$order['so_id']; ?>)">
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
                    </div>
                    <div class="op-so-pagination no-print" id="opSoPagination">
                        <div class="op-so-pagination-info" id="opSoPaginationInfo">Showing 0 to 0 of 0 entries</div>
                        <div class="op-so-pagination-controls">
                            <button type="button" class="btn" id="opSoFirstPage" onclick="setEmbeddedSalesOrderPage(1)">First</button>
                            <button type="button" class="btn" id="opSoPrevPage" onclick="setEmbeddedSalesOrderPage(opSoCurrentPage - 1)"><i class="bi bi-chevron-left"></i></button>
                            <span id="opSoPageIndicator">Page 1 of 1</span>
                            <button type="button" class="btn" id="opSoNextPage" onclick="setEmbeddedSalesOrderPage(opSoCurrentPage + 1)"><i class="bi bi-chevron-right"></i></button>
                            <button type="button" class="btn" id="opSoLastPage" onclick="setEmbeddedSalesOrderPage(opSoTotalPages || 1)">Last</button>
                        </div>
                    </div>
                </div>
            </div>
            </div><!-- /salesOrderTabContent -->
        </div>
    </div>

    <!-- Product Info Modal -->
    <div class="modal fade" id="productInfoModal" tabindex="-1">
        <div class="modal-dialog modal-xl order-details-wide-modal">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-box-seam me-2"></i><span id="modalProductName">Product Details</span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" onclick="amgcCloseOrderDetailsModal && amgcCloseOrderDetailsModal()"></button>
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
                    <select class="form-select grouped-native-select" id="modalCustomerSelect" <?php echo $is_customer_locked ? 'disabled' : ''; ?>>
                        <option value="">-- Choose Customer --</option>
                        <?php
                        $customers_by_group = [];
                        foreach ($customers as $customer) {
                            $group_name = trim((string)($customer['customer_group'] ?? ''));
                            if ($group_name === '') { $group_name = 'General'; }
                            if (!isset($customers_by_group[$group_name])) { $customers_by_group[$group_name] = []; }
                            $customers_by_group[$group_name][] = $customer;
                        }
                        ksort($customers_by_group, SORT_NATURAL | SORT_FLAG_CASE);
                        ?>
                        <?php foreach ($customers_by_group as $group_name => $group_customers): ?>
                            <option value="" class="customer-group-header-option dropdown-group-header-option" disabled><?php echo htmlspecialchars($group_name); ?></option>
                            <?php foreach ($group_customers as $customer): ?>
                                <option value="<?php echo $customer['customer_id']; ?>"
                                        data-email="<?php echo htmlspecialchars($customer['email'] ?? ''); ?>"
                                        data-phone="<?php echo htmlspecialchars($customer['phone_number'] ?? ''); ?>"
                                        data-address="<?php echo htmlspecialchars($customer['address'] ?? ''); ?>"
                                        data-price-level="<?php echo htmlspecialchars($customer['price_level'] ?? 'Standard'); ?>"
                                        data-customer-group="<?php echo htmlspecialchars($group_name); ?>"
                                        <?php echo ($pre_selected_customer_id == $customer['customer_id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($customer['customer_name']); ?></option>
                            <?php endforeach; ?>
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

                        <div id="newVehicleFields" class="row g-2 mt-2" style="display:none;"></div>
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
                                <div class="col-md-6"><label class="form-label small mb-1">Payment Amount (₱)</label><input type="text" inputmode="decimal" class="form-control form-control-sm no-spinner money-input" id="pickupCheckPaymentAmount" placeholder="0.00"></div>
                                <div class="col-md-12"><label class="form-label small mb-1">Bank/Branch</label><input type="text" class="form-control form-control-sm" id="pickupBankBranch" placeholder="e.g. BDO - Tanauan Branch"></div>
                            </div>
                            <div class="row g-2 mt-2 pickup-online-fields" style="display:none;">
                                <div class="col-md-6"><label class="form-label small mb-1">Reference Number</label><input type="text" class="form-control form-control-sm" id="pickupReferenceNumber"></div>
                                <div class="col-md-6"><label class="form-label small mb-1">Bank/Wallet</label><input type="text" class="form-control form-control-sm" id="pickupOnlineBankName" placeholder="GCash, Maya, BPI, etc."></div>
                                <div class="col-md-6"><label class="form-label small mb-1">Payment Amount (₱)</label><input type="text" inputmode="decimal" class="form-control form-control-sm no-spinner money-input" id="pickupOnlinePaymentAmount" placeholder="0.00"></div>
                                <div class="col-md-12"><label class="form-label small mb-1">Branch / Account Note</label><input type="text" class="form-control form-control-sm" id="pickupOnlineBankBranch" placeholder="Optional"></div>
                            </div>
                        </div>
                    </div>
                </div>


                <div class="mb-3" id="documentTypeSection">
                    <h6 class="mb-3"><i class="bi bi-receipt"></i> Document Type</h6>
                    <div class="alert bg-light mb-3">
                        <div class="row g-2" style="display:none;">
                            <div class="col-md-6">
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="billingType" id="billingTypeInvoice" value="invoice" checked onchange="toggleBillingTypeFields()">
                                    <label class="form-check-label" for="billingTypeInvoice">Invoice <small class="d-block text-muted">Regular invoice transaction</small></label>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="billingType" id="billingTypeCredit" value="credit" onchange="toggleBillingTypeFields()">
                                    <label class="form-check-label" for="billingTypeCredit">Credit <small class="d-block text-muted">Credit transaction, no payment collection</small></label>
                                </div>
                            </div>
                        </div>
                        <div class="row g-2 mt-2">
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
                        <div class="row g-2 mt-2" id="documentTransportFields">
                            <div class="col-md-4">
                                <label class="form-label small mb-1">ATW No.</label>
                                <input type="text" class="form-control form-control-sm" id="atwNo" name="atw_no" placeholder="Enter ATW No. (Optional)" maxlength="6" pattern="[0-9]{0,6}" inputmode="numeric" oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0, 6)">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small mb-1">Gatepass No. <span class="text-danger">*</span></label>
                                <input type="text" class="form-control form-control-sm" id="gatepassNo" name="gatepass_no" placeholder="Enter Gatepass No." maxlength="6" pattern="[0-9]{1,6}" inputmode="numeric" oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0, 6)" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small mb-1">Delivery Type</label>
                                <select class="form-select form-select-sm" name="deliveryType" id="deliveryType">
                                    <option value="pickup" selected>Pick Up</option>
                                    <option value="delivery">Delivery</option>
                                </select>
                            </div>
                        </div>
                        <div class="order-recurring-section classic-recurring-section mb-3">
                    <label class="order-recurring-toggle" for="orderRecurringEnabled">
                        <input type="checkbox" id="orderRecurringEnabled" onchange="toggleOrderRecurring('order')" onclick="toggleOrderRecurring('order')">
                        <span>Recurring invoice / schedule</span>
                    </label>
                    <div class="order-recurring-fields" id="orderRecurringFields" style="display:none;">
                        <div class="order-recurring-field">
                            <label for="orderRecurringEvery">Every</label>
                            <input type="number" id="orderRecurringEvery" min="1" step="1" value="1">
                        </div>
                        <div class="order-recurring-field">
                            <label for="orderRecurringPeriod">Period</label>
                            <select id="orderRecurringPeriod">
                                <option value="day">Day(s)</option>
                                <option value="week">Week(s)</option>
                                <option value="month" selected>Month(s)</option>
                                <option value="year">Year(s)</option>
                            </select>
                        </div>
                        <div class="order-recurring-field">
                            <label for="orderRecurringUntil">Until Date</label>
                            <input type="date" id="orderRecurringUntil">
                        </div>
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
<div class="modal fade no-print" id="orderDetailsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered order-details-wide-modal">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-receipt-cutoff me-2"></i>Invoice Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="orderDetailsContent">
                <!-- Content loaded via AJAX -->
            </div>
            <div class="modal-footer d-none">
                <button type="button" class="btn btn-danger" id="cancelOrderBtn" style="display: none;" onclick="cancelOrderFromOrderProduct()">Cancel Order</button>
                <button type="button" class="btn btn-primary" id="printOrderFromDetails" style="display: none;" onclick="printOrderFromOrderProduct()">Print Order</button>
            </div>
        </div>
    </div>
</div>

<!-- SI Attachment Preview Modal -->
<div class="modal fade no-print" id="siAttachmentPreviewModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-paperclip me-2"></i><span id="siAttachmentPreviewTitle">SI Attachment</span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-0" id="siAttachmentPreviewBody" style="height:75vh; background:#f8f9fa; overflow:auto;">
                <div class="d-flex align-items-center justify-content-center h-100 text-muted">Select an attachment to preview.</div>
            </div>
            <div class="modal-footer">
                <a href="#" id="siAttachmentDownloadBtn" class="btn btn-outline-success" download><i class="bi bi-download me-1"></i>Download</a>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

    <!-- JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
   <script>
    function openSIAttachmentPreview(filePath, fileName) {
        filePath = String(filePath || '').trim();
        fileName = String(fileName || 'SI Attachment').trim();
        if (!filePath) return;

        const titleEl = document.getElementById('siAttachmentPreviewTitle');
        const bodyEl = document.getElementById('siAttachmentPreviewBody');
        const downloadBtn = document.getElementById('siAttachmentDownloadBtn');
        if (!titleEl || !bodyEl || !downloadBtn) return;

        titleEl.textContent = fileName;
        downloadBtn.href = filePath;
        downloadBtn.setAttribute('download', fileName);

        const cleanPath = filePath.split('?')[0].toLowerCase();
        const imageExt = ['.jpg', '.jpeg', '.png', '.gif', '.webp', '.bmp'];
        const officeExt = ['.doc', '.docx', '.xls', '.xlsx'];

        if (imageExt.some(ext => cleanPath.endsWith(ext))) {
            bodyEl.innerHTML = `<div class="w-100 h-100 d-flex align-items-center justify-content-center p-3"><img src="${escapeHtml(filePath)}" alt="${escapeHtml(fileName)}" class="img-fluid rounded shadow-sm" style="max-height:72vh;"></div>`;
        } else if (cleanPath.endsWith('.pdf')) {
            bodyEl.innerHTML = `<iframe src="${escapeHtml(filePath)}" title="${escapeHtml(fileName)}" style="width:100%; height:75vh; border:0;"></iframe>`;
        } else if (officeExt.some(ext => cleanPath.endsWith(ext))) {
            bodyEl.innerHTML = `<div class="d-flex flex-column align-items-center justify-content-center h-100 text-center p-4"><i class="bi bi-file-earmark-text" style="font-size:3rem;"></i><h5 class="mt-3 mb-2">Preview is not available for this file type.</h5><p class="text-muted mb-3">Click Download below to view this attachment.</p><a href="${escapeHtml(filePath)}" class="btn btn-success" download="${escapeHtml(fileName)}"><i class="bi bi-download me-1"></i>Download Attachment</a></div>`;
        } else {
            bodyEl.innerHTML = `<iframe src="${escapeHtml(filePath)}" title="${escapeHtml(fileName)}" style="width:100%; height:75vh; border:0;"></iframe>`;
        }

        const modalEl = document.getElementById('siAttachmentPreviewModal');
        const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
        modal.show();
    }

    document.addEventListener('click', function(e) {
        const btn = e.target.closest('.si-attachment-view-btn');
        if (!btn) return;
        e.preventDefault();
        openSIAttachmentPreview(btn.getAttribute('data-file-path'), btn.getAttribute('data-file-name'));
    });

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

        if (document.getElementById('invoiceCollectPayment')?.checked) {
            const method = document.getElementById('invoicePaymentMethod')?.value || 'cash';
            const subtotal = getCartSubtotal();
            const discount = computeCartDiscount(subtotal);
            const total = discount.total;

            if (method === 'cash') {
                const cashTendered = parseFloat(String(document.getElementById('invoiceCashTendered')?.value || '0').replace(/[^0-9.]/g, '')) || 0;
                if (cashTendered <= 0) {
                    showToast('Cash tendered is required');
                    document.getElementById('invoiceCashTendered')?.focus();
                    return false;
                }
                if (cashTendered + 0.009 < total) {
                    showToast('Cash tendered cannot be lower than grand total');
                    document.getElementById('invoiceCashTendered')?.focus();
                    return false;
                }
            } else if (method === 'check') {
                const paymentAmount = parseFloat(String(document.getElementById('invoiceCheckPaymentAmount')?.value || '0').replace(/[^0-9.]/g, '')) || 0;
                if (!document.getElementById('invoiceCheckDate')?.value || !document.getElementById('invoiceCheckNumber')?.value.trim() || !document.getElementById('invoiceBankBranch')?.value.trim()) {
                    showToast('All check details are required');
                    return false;
                }
                if (paymentAmount <= 0) {
                    showToast('Payment Amount is required');
                    document.getElementById('invoiceCheckPaymentAmount')?.focus();
                    return false;
                }
                if (Math.abs(paymentAmount - total) > 0.01) {
                    showToast('Payment Amount must be equal to the grand total');
                    document.getElementById('invoiceCheckPaymentAmount')?.focus();
                    return false;
                }
            } else if (method === 'online_transfer') {
                const paymentAmount = parseFloat(String(document.getElementById('invoiceOnlinePaymentAmount')?.value || '0').replace(/[^0-9.]/g, '')) || 0;
                if (!document.getElementById('invoiceReferenceNumber')?.value.trim() || !document.getElementById('invoiceOnlineBankName')?.value.trim()) {
                    showToast('Reference number and Bank/Wallet are required');
                    return false;
                }
                if (paymentAmount <= 0) {
                    showToast('Payment Amount is required');
                    document.getElementById('invoiceOnlinePaymentAmount')?.focus();
                    return false;
                }
                if (Math.abs(paymentAmount - total) > 0.01) {
                    showToast('Payment Amount must be equal to the grand total');
                    document.getElementById('invoiceOnlinePaymentAmount')?.focus();
                    return false;
                }
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
    
    function protectProductSearchFromGoogleAutofill() {
        const searchInput = document.getElementById('productSearch');
        if (!searchInput) return;

        const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        const clearAutofilledEmail = () => {
            const value = (searchInput.value || '').trim();
            if (emailPattern.test(value)) {
                searchInput.value = '';
                searchTerm = '';
                const resetBtn = document.getElementById('searchReset');
                if (resetBtn) resetBtn.classList.remove('visible');
                if (typeof filterProducts === 'function') filterProducts();
            }
        };

        searchInput.setAttribute('autocomplete', 'off');
        searchInput.setAttribute('data-form-type', 'other');
        searchInput.setAttribute('data-lpignore', 'true');
        searchInput.setAttribute('data-1p-ignore', 'true');
        searchInput.setAttribute('data-bwignore', 'true');
        searchInput.setAttribute('data-no-autofill', 'true');

        searchInput.addEventListener('input', clearAutofilledEmail);
        searchInput.addEventListener('change', clearAutofilledEmail);
        searchInput.addEventListener('focus', () => setTimeout(clearAutofilledEmail, 0));
        window.addEventListener('pageshow', clearAutofilledEmail);

        const autofillObserver = new MutationObserver(clearAutofilledEmail);
        autofillObserver.observe(searchInput, {
            attributes: true,
            attributeFilter: ['value', 'autocomplete', 'name']
        });

        [0, 50, 150, 300, 700, 1200, 2000].forEach(delay => {
            setTimeout(clearAutofilledEmail, delay);
        });

        requestAnimationFrame(clearAutofilledEmail);
    }

    function setupSearch() {
        protectProductSearchFromGoogleAutofill();
        const searchInput = document.getElementById('productSearch');
        const resetBtn = document.getElementById('searchReset');
        if (!searchInput) return;
        searchInput.addEventListener('input', function() {
            searchTerm = this.value.toLowerCase();
            resetBtn.classList.toggle('visible', searchTerm.length > 0);
            filterProducts();
        });
    }
    
    function resetSearch() {
        const searchInput = document.getElementById('productSearch');
        const resetBtn = document.getElementById('searchReset');
        if (!searchInput) return;
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
                <div class="mobile-price-display" onclick="event.stopPropagation();"><span class="mobile-price-label">Price:</span><div class="input-group input-group-sm" style="width: auto;"><span class="input-group-text" style="padding: 2px 6px;">₱</span><input type="text" inputmode="decimal" class="form-control mobile-price-input" id="mobile-price-input-${p.id}" value="${currPrice.toFixed(2)}" style="width: 90px; text-align: right;" onclick="event.stopPropagation();" autocomplete="off"></div><span class="mobile-price-unit" id="mobile-unit-${p.id}">/${currUnit}</span></div></div></td>
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
                        <input type="text" inputmode="decimal" class="form-control price-input" id="price-${p.id}" value="${currPrice.toFixed(2)}" onclick="event.stopPropagation()" autocomplete="off">
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
        if (str === null || str === undefined) return '';
        return String(str).replace(/[&<>]/g, function(m) {
            if (m === '&') return '&amp;';
            if (m === '<') return '&lt;';
            if (m === '>') return '&gt;';
            return m;
        });
    }
    
    function getDeliveryTypeValue() {
        const dropdown = document.getElementById('deliveryType');
        if (dropdown) return dropdown.value || 'pickup';
        const checkedRadio = document.querySelector('input[name="deliveryType"]:checked');
        return checkedRadio ? checkedRadio.value : 'pickup';
    }

    function setDeliveryTypeValue(value) {
        const normalized = value === 'delivery' ? 'delivery' : 'pickup';
        const dropdown = document.getElementById('deliveryType');
        if (dropdown) dropdown.value = normalized;
        const pickupRadio = document.getElementById('deliveryPickup');
        const deliverRadio = document.getElementById('deliveryDeliver');
        if (pickupRadio) pickupRadio.checked = normalized === 'pickup';
        if (deliverRadio) deliverRadio.checked = normalized === 'delivery';

        const help = document.getElementById('deliveryTypeHelp');
        if (help) {
            help.textContent = normalized === 'delivery'
                ? 'Order will be delivered to customer'
                : 'Customer will pick up the order';
        }
    }

    function getInvoiceDeliveryTypeValue() {
        const dropdown = document.getElementById('invoiceDeliveryType');
        if (dropdown) return dropdown.value || 'pickup';
        return getInvoiceDeliveryTypeValue();
    }

    function setInvoiceDeliveryTypeValue(value) {
        const normalized = value === 'delivery' ? 'delivery' : 'pickup';
        const dropdown = document.getElementById('invoiceDeliveryType');
        if (dropdown) dropdown.value = normalized;
        const pickupRadio = document.getElementById('invoiceDeliveryPickup');
        const deliverRadio = document.getElementById('invoiceDeliveryDeliver');
        if (pickupRadio) pickupRadio.checked = normalized === 'pickup';
        if (deliverRadio) deliverRadio.checked = normalized === 'delivery';
    }

    function refreshDeliveryTypeDependentFields() {
        const selectedDeliveryType = getDeliveryTypeValue();
        const deliveryAddressGroup = document.getElementById('deliveryAddressGroup');
        if (deliveryAddressGroup) {
            deliveryAddressGroup.style.display = selectedDeliveryType === 'delivery' ? 'block' : 'none';
        }
        if (selectedDeliveryType === 'delivery') {
            const collectPickupPayment = document.getElementById('collectPickupPayment');
            if (collectPickupPayment) collectPickupPayment.checked = false;
            updateCustomerAddressInputVisibility();
            updateDeliveryAddressDisplay();
        }
        updatePickupPaymentVisibility();
        updateDeliveryAssignmentVisibility();
    }

    // Check if customer is walk-in and hide delivery options
    function checkIfWalkinCustomer() {
        const select = document.getElementById('modalCustomerSelect');
        const deliveryTypeSection = document.getElementById('deliveryTypeSection');
        const deliveryTypeDropdown = document.getElementById('deliveryType');
        
        if (!select) return;
        
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
            // For walk-in customer, force pickup and disable the dropdown
            if (deliveryTypeSection) deliveryTypeSection.style.display = 'none';
            if (deliveryTypeDropdown) deliveryTypeDropdown.disabled = true;
            setDeliveryTypeValue('pickup');
            const deliveryAddressGroup = document.getElementById('deliveryAddressGroup');
            if (deliveryAddressGroup) deliveryAddressGroup.style.display = 'none';
        updateDeliveryAssignmentVisibility();
        } else {
            // For regular customers, enable delivery type dropdown
            if (deliveryTypeSection) deliveryTypeSection.style.display = 'block';
            if (deliveryTypeDropdown) deliveryTypeDropdown.disabled = false;
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
        const dropdown = document.getElementById('deliveryType');
        if (dropdown) {
            dropdown.addEventListener('change', function() {
                setDeliveryTypeValue(this.value);
                refreshDeliveryTypeDependentFields();
                toggleBillingTypeFields();
        syncOriginalDeliveryTypeToInvoice();
            });
        }

        document.querySelectorAll('input[name="deliveryType"]').forEach(el => {
            el.addEventListener('change', function() {
                if (this.checked) {
                    setDeliveryTypeValue(this.value);
                    refreshDeliveryTypeDependentFields();
                    syncOriginalDeliveryTypeToInvoice();
                }
            });
        });

        setDeliveryTypeValue(getDeliveryTypeValue());
        refreshDeliveryTypeDependentFields();
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
        const selectedDeliveryType = getDeliveryTypeValue();
        if (section) section.style.display = selectedDeliveryType === 'delivery' ? 'block' : 'none';
    }

    function updatePickupPaymentVisibility() {
        const section = document.getElementById('pickupPaymentSection');
        const fields = document.getElementById('pickupPaymentFields');
        const collect = document.getElementById('collectPickupPayment');
        const method = document.getElementById('pickupPaymentMethod')?.value || 'cash';
        const selectedDeliveryType = getDeliveryTypeValue();
        const shouldShow = selectedDeliveryType === 'pickup' && !isCreditOrderSelected();

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


    function updateInvoicePaymentVisibility() {
        const collect = document.getElementById('invoiceCollectPayment');
        const fields = document.getElementById('invoicePaymentFields');
        const method = document.getElementById('invoicePaymentMethod')?.value || 'cash';
        const isCredit = isCreditOrderSelected();
        const panel = document.getElementById('invoicePaymentPanel');
        if (panel) panel.style.display = isCredit ? 'none' : '';
        if (isCredit && collect) collect.checked = false;

        if (fields) fields.style.display = (!isCredit && collect && collect.checked) ? 'block' : 'none';
        document.querySelectorAll('.invoice-cash-field').forEach(el => el.style.display = method === 'cash' ? '' : 'none');
        document.querySelectorAll('.invoice-check-fields').forEach(el => el.style.display = method === 'check' ? 'grid' : 'none');
        document.querySelectorAll('.invoice-online-fields').forEach(el => el.style.display = method === 'online_transfer' ? 'grid' : 'none');

        const subtotal = getCartSubtotal();
        const discount = computeCartDiscount(subtotal);
        const total = discount.total;
        const tenderedInput = document.getElementById('invoiceCashTendered');
        const tendered = parseFloat(String(tenderedInput?.value || '0').replace(/[^0-9.]/g, '')) || 0;
        const change = Math.max(tendered - total, 0);
        const changeEl = document.getElementById('invoiceCashChange');
        if (changeEl) changeEl.textContent = formatCurrency(change);
    }

    function setupInvoicePaymentListeners() {
        document.getElementById('invoiceCollectPayment')?.addEventListener('change', updateInvoicePaymentVisibility);
        document.getElementById('invoicePaymentMethod')?.addEventListener('change', updateInvoicePaymentVisibility);
        document.getElementById('invoiceCashTendered')?.addEventListener('input', updateInvoicePaymentVisibility);
        updateInvoicePaymentVisibility();
    }

    function syncInvoicePaymentToOriginalForm() {
        const collect = document.getElementById('invoiceCollectPayment');
        if (!collect) return;

        const originalCollect = document.getElementById('collectPickupPayment');
        const originalMethod = document.getElementById('pickupPaymentMethod');
        const originalCash = document.getElementById('pickupCashTendered');
        const originalCheckDate = document.getElementById('pickupCheckDate');
        const originalCheckNumber = document.getElementById('pickupCheckNumber');
        const originalBankBranch = document.getElementById('pickupBankBranch');
        const originalCheckPaymentAmount = document.getElementById('pickupCheckPaymentAmount');
        const originalReference = document.getElementById('pickupReferenceNumber');
        const originalOnlineBankName = document.getElementById('pickupOnlineBankName');
        const originalOnlinePaymentAmount = document.getElementById('pickupOnlinePaymentAmount');
        const originalOnlineBankBranch = document.getElementById('pickupOnlineBankBranch');

        if (originalCollect) originalCollect.checked = isCreditOrderSelected() ? false : collect.checked;
        if (originalMethod) originalMethod.value = document.getElementById('invoicePaymentMethod')?.value || 'cash';
        if (originalCash) originalCash.value = document.getElementById('invoiceCashTendered')?.value || '';
        if (originalCheckDate) originalCheckDate.value = document.getElementById('invoiceCheckDate')?.value || '';
        if (originalCheckNumber) originalCheckNumber.value = document.getElementById('invoiceCheckNumber')?.value || '';
        if (originalBankBranch) originalBankBranch.value = document.getElementById('invoiceBankBranch')?.value || '';
        if (originalCheckPaymentAmount) originalCheckPaymentAmount.value = document.getElementById('invoiceCheckPaymentAmount')?.value || '';
        if (originalReference) originalReference.value = document.getElementById('invoiceReferenceNumber')?.value || '';
        if (originalOnlineBankName) originalOnlineBankName.value = document.getElementById('invoiceOnlineBankName')?.value || '';
        if (originalOnlinePaymentAmount) originalOnlinePaymentAmount.value = document.getElementById('invoiceOnlinePaymentAmount')?.value || '';
        if (originalOnlineBankBranch) originalOnlineBankBranch.value = document.getElementById('invoiceOnlineBankBranch')?.value || '';

        if (typeof updatePickupPaymentVisibility === 'function') updatePickupPaymentVisibility();
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
                setDeliveryTypeValue('pickup');
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

    

    document.addEventListener('DOMContentLoaded', function () {
        const applyRecurringPosition = function () {
            positionInvoiceRecurringSection();
            window.requestAnimationFrame(positionInvoiceRecurringSection);
        };

        applyRecurringPosition();

        const creditCheckbox = document.getElementById('invoiceCreditCheckbox');
        if (creditCheckbox) {
            creditCheckbox.addEventListener('change', applyRecurringPosition);
            creditCheckbox.addEventListener('click', applyRecurringPosition);
        }
    });

    function getBillingTypeValue() {
        const creditCheckbox = document.getElementById('invoiceCreditCheckbox');
        if (creditCheckbox) {
            return creditCheckbox.checked ? 'credit' : 'invoice';
        }
        return document.querySelector('input[name="billingType"]:checked')?.value === 'credit' ? 'credit' : 'invoice';
    }

    function setBillingTypeValue(value) {
        const normalized = value === 'credit' ? 'credit' : 'invoice';
        const creditCheckbox = document.getElementById('invoiceCreditCheckbox');
        if (creditCheckbox) creditCheckbox.checked = normalized === 'credit';
        const invoiceRadio = document.getElementById('billingTypeInvoice');
        const creditRadio = document.getElementById('billingTypeCredit');
        if (invoiceRadio) invoiceRadio.checked = normalized === 'invoice';
        if (creditRadio) creditRadio.checked = normalized === 'credit';
    }

    function isCreditOrderSelected() {
        return getBillingTypeValue() === 'credit';
    }

    function updateInvoiceDocumentTitle() {
        const titleEl = document.getElementById('invoiceDocumentTitle');
        if (!titleEl) return;
        titleEl.textContent = isCreditOrderSelected() ? 'Credit' : 'Invoice';
    }

    function positionInvoiceRecurringSection() {
        const section = document.getElementById('invoiceRecurringSection');
        const invoiceSlot = document.querySelector('.invoice-recurring-lower-slot');
        const creditSlot = document.getElementById('invoiceRecurringCreditSlot');

        if (!section || !invoiceSlot || !creditSlot) return;

        const creditMode = isCreditOrderSelected();
        document.body.classList.toggle('credit-recurring-mode', creditMode);

        const destination = creditMode ? creditSlot : invoiceSlot;
        if (section.parentElement !== destination) {
            destination.appendChild(section);
        }
    }

    function toggleBillingTypeFields() {
        updateInvoiceDocumentTitle();
        positionInvoiceRecurringSection();
        const isCredit = isCreditOrderSelected();
        const transportFields = document.getElementById('documentTransportFields');
        if (transportFields) transportFields.style.display = isCredit ? 'none' : 'flex';

        ['atwNo', 'gatepassNo', 'invoiceAtwNo', 'invoiceGatepassNo'].forEach(id => {
            const input = document.getElementById(id);
            if (!input) return;
            if (isCredit) {
                input.value = '';
                input.classList.remove('is-invalid');
            }
        });

        const gatepass = document.getElementById('gatepassNo');
        if (gatepass) gatepass.required = !isCredit;

        const deliveryType = document.getElementById('deliveryType');
        if (deliveryType && isCredit) {
            deliveryType.value = 'pickup';
            setDeliveryTypeValue('pickup');
        }
        const invoiceDeliveryType = document.getElementById('invoiceDeliveryType');
        if (invoiceDeliveryType && isCredit) {
            invoiceDeliveryType.value = 'pickup';
            setInvoiceDeliveryTypeValue('pickup');
        }

        const pickupPaymentSection = document.getElementById('pickupPaymentSection');
        const pickupPaymentFields = document.getElementById('pickupPaymentFields');
        const collectPickupPayment = document.getElementById('collectPickupPayment');
        if (collectPickupPayment && isCredit) collectPickupPayment.checked = false;
        if (pickupPaymentSection) pickupPaymentSection.style.display = isCredit ? 'none' : '';
        if (pickupPaymentFields && isCredit) pickupPaymentFields.style.display = 'none';

        const invoicePaymentPanel = document.getElementById('invoicePaymentPanel');
        const invoicePaymentFields = document.getElementById('invoicePaymentFields');
        const invoiceCollectPayment = document.getElementById('invoiceCollectPayment');
        if (invoiceCollectPayment && isCredit) invoiceCollectPayment.checked = false;
        if (invoicePaymentPanel) invoicePaymentPanel.style.display = isCredit ? 'none' : '';
        if (invoicePaymentFields && isCredit) invoicePaymentFields.style.display = 'none';

        updateInvoiceDeliveryFieldVisibility?.();
        refreshDeliveryTypeDependentFields?.();
        updatePickupPaymentVisibility?.();
        updateInvoicePaymentVisibility?.();
        setTimeout(positionInvoiceRecurringSection, 0);
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

    document.addEventListener('DOMContentLoaded', function() { toggleSIDetails(); toggleBillingTypeFields(); updateInvoiceDocumentTitle(); setupRecurringScheduleControls(); });


    function showBeyondCreditApprovalModal(data, onApprove, onCancel) {
        Swal.fire({
            icon: 'warning',
            title: data.title || 'Beyond Credit Limit Approval Required',
            html: `
                <div class="outstanding-approval-body">
                    <div class="outstanding-approval-summary">
                        ${data.html || ''}
                    </div>
                    <div class="text-start mt-3">
                        <label class="form-label fw-bold" for="beyondCreditExplanationInput">Explanation <span class="text-danger">*</span></label>
                        <textarea id="beyondCreditExplanationInput" class="form-control" rows="3" placeholder="Enter reason why this order is being allowed."></textarea>
                        <div class="form-check mt-3">
                            <input class="form-check-input" type="checkbox" value="1" id="beyondCreditAcknowledgeInput">
                            <label class="form-check-label fw-semibold" for="beyondCreditAcknowledgeInput">
                                I understand this order requires approval, I am allowing this order to proceed.
                            </label>
                        </div>
                    </div>
                </div>
            `,
            width: '560px',
            showCancelButton: true,
            confirmButtonText: 'Allow & Confirm Order',
            cancelButtonText: 'Cancel',
            confirmButtonColor: '#047857',
            cancelButtonColor: '#6c757d',
            focusConfirm: false,
            allowEscapeKey: true,
            keydownListenerCapture: true,
            customClass: {
                popup: 'outstanding-approval-swal'
            },
            didOpen: () => {
                // Keep this approval modal compact and prevent page/right/bottom scrollbars.
                const popup = Swal.getPopup();
                if (popup) {
                    popup.style.maxHeight = 'calc(100vh - 32px)';
                    popup.style.overflow = 'hidden';
                }

                const htmlContainer = Swal.getHtmlContainer();
                if (htmlContainer) {
                    htmlContainer.style.maxHeight = 'none';
                    htmlContainer.style.overflow = 'visible';
                    htmlContainer.style.paddingRight = '0';
                }

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


    // Global digit sanitizer used by both Classic and Default invoice style.
    // This is intentionally placed before submitOrder so Save & Close / Save & New
    // will not fail when the Default Style calls it.
    function sanitizeDigits(value, maxLen) {
        return String(value || '').replace(/\D/g, '').slice(0, maxLen || 6);
    }

    
function validateInvoiceStylePricesBeforeSubmit() {
    const activeOrderStyle = (typeof window.getOrderProductStyle === 'function') ? window.getOrderProductStyle() : 'classic';
    if (activeOrderStyle !== 'invoice') return true;

    const rows = Array.from(document.querySelectorAll('#invoiceItemsBody tr[data-invoice-row]'));
    for (const row of rows) {
        const productId = row.querySelector('.invoice-item-select')?.value || '';
        const unitName = row.querySelector('.invoice-unit-select')?.value || '';
        const qty = parseInt(row.querySelector('.invoice-qty')?.value || '0', 10) || 0;
        const priceInput = row.querySelector('.invoice-price');
        if (!productId || !unitName || qty <= 0) continue;

        const minPrice = getInvoiceUnitPrice(productId, unitName);
        const raw = String(priceInput?.value || '').replace(/,/g, '');
        const typedPrice = parseFloat(raw);

        if (raw === '' || isNaN(typedPrice)) {
            priceInput?.classList.add('is-invalid');
            showToast('Please enter a price before saving the invoice.');
            priceInput?.focus();
            return false;
        }

        if (typedPrice + 0.009 < minPrice) {
            priceInput?.classList.add('is-invalid');
            showToast(`Price cannot be lower than declared price ${formatCurrency(minPrice)}.`);
            priceInput?.focus();
            return false;
        }
        priceInput?.classList.remove('is-invalid');
    }
    return true;
}


function toggleOrderRecurring(prefix) {
    const checkbox = document.getElementById(prefix + 'RecurringEnabled');
    const fields = document.getElementById(prefix + 'RecurringFields');
    const until = document.getElementById(prefix + 'RecurringUntil');
    if (!checkbox || !fields) return;

    const enabled = checkbox.checked === true;
    fields.hidden = false;
    fields.style.setProperty('display', enabled ? 'grid' : 'none', 'important');
    fields.classList.toggle('is-open', enabled);
    fields.setAttribute('aria-hidden', enabled ? 'false' : 'true');

    if (until) {
        until.required = enabled;
        const baseDate = document.getElementById('invoiceOrderDate')?.value
            || document.getElementById('orderDate')?.value
            || new Date().toISOString().slice(0, 10);
        until.min = baseDate;
    }
}

function setupRecurringScheduleControls() {
    ['invoice', 'order'].forEach(prefix => {
        const checkbox = document.getElementById(prefix + 'RecurringEnabled');
        if (!checkbox) return;
        checkbox.removeEventListener('change', checkbox._amgcRecurringHandler || (() => {}));
        checkbox._amgcRecurringHandler = () => toggleOrderRecurring(prefix);
        checkbox.addEventListener('change', checkbox._amgcRecurringHandler);
        toggleOrderRecurring(prefix);
    });
}

function getRecurringSchedulePayload() {
    const useInvoiceStyle = (typeof window.getOrderProductStyle === 'function')
        ? window.getOrderProductStyle() === 'invoice'
        : !!document.getElementById('invoiceRecurringEnabled');
    const prefix = useInvoiceStyle ? 'invoiceRecurring' : 'orderRecurring';
    const enabled = !!document.getElementById(prefix + 'Enabled')?.checked;
    return {
        is_recurring: enabled ? '1' : '0',
        recurring_every: enabled ? (document.getElementById(prefix + 'Every')?.value || '1') : '',
        recurring_period: enabled ? (document.getElementById(prefix + 'Period')?.value || 'month') : '',
        recurring_until: enabled ? (document.getElementById(prefix + 'Until')?.value || '') : ''
    };
}

function validateRecurringSchedule() {
    const schedule = getRecurringSchedulePayload();
    if (schedule.is_recurring !== '1') return schedule;
    const every = parseInt(schedule.recurring_every || '0', 10);
    if (!every || every < 1) { showToast('Every must be at least 1.'); return false; }
    if (!['day', 'week', 'month', 'year'].includes(schedule.recurring_period)) { showToast('Please select a valid recurring period.'); return false; }
    if (!schedule.recurring_until) { showToast('Please select the Until Date.'); return false; }
    const invoiceDate = document.getElementById('invoiceOrderDate')?.value || new Date().toISOString().slice(0, 10);
    if (schedule.recurring_until < invoiceDate) { showToast('Until Date cannot be earlier than the invoice date.'); return false; }
    return schedule;
}

function submitOrder() {
    const recurringSchedule = validateRecurringSchedule();
    if (recurringSchedule === false) return;
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
        deliveryType = getDeliveryTypeValue();
        
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

    const billingType = getBillingTypeValue();
    const isCreditOrder = billingType === 'credit';
    const documentType = document.querySelector('input[name="documentType"]:checked')?.value || 'SO';
    const siNumber = (document.getElementById('siNumber')?.value || '').trim();
    const atwNo = (document.getElementById('atwNo')?.value || '').trim();
    const gatepassNo = (document.getElementById('gatepassNo')?.value || '').trim();
    const registeredBusinessName = (document.getElementById('registeredBusinessName')?.value || '').trim();
    const businessTin = (document.getElementById('businessTin')?.value || '').trim();
    const businessAddress = (document.getElementById('businessAddress')?.value || '').trim();
    const documentFields = [
        { id: 'atwNo', value: atwNo, required: false },
        { id: 'gatepassNo', value: gatepassNo, required: !isCreditOrder }
    ];

    if (!isCreditOrder && !gatepassNo) {
        documentFields.forEach(field => {
            const input = document.getElementById(field.id);
            if (input) input.classList.toggle('is-invalid', field.required && !field.value);
        });
        showToast('Please enter Gatepass No.');
        document.getElementById('gatepassNo')?.focus();
        return;
    }

    const documentNumberPattern = /^\d{1,6}$/;
    if (!isCreditOrder && ((atwNo && !documentNumberPattern.test(atwNo)) || !documentNumberPattern.test(gatepassNo))) {
        documentFields.forEach(field => {
            const input = document.getElementById(field.id);
            const invalid = field.value ? !documentNumberPattern.test(field.value) : field.required;
            if (input) input.classList.toggle('is-invalid', invalid);
        });
        showToast('ATW No. and Gatepass No. must be numbers only with a maximum of 6 digits.');
        (atwNo && !documentNumberPattern.test(atwNo) ? document.getElementById('atwNo') : document.getElementById('gatepassNo'))?.focus();
        return;
    }
    documentFields.forEach(field => document.getElementById(field.id)?.classList.remove('is-invalid'));

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
    
    const collectPickupPayment = !isCreditOrder && (isWalkin || deliveryType === 'pickup') && document.getElementById('collectPickupPayment')?.checked;
    const pickupPaymentMethod = document.getElementById('pickupPaymentMethod')?.value || 'cash';
    const grandTotalForPayment = discountDetails.total;

    if (collectPickupPayment) {
        if (pickupPaymentMethod === 'cash') {
            const cashTendered = parseFloat(document.getElementById('pickupCashTendered')?.value || '0');
            if (cashTendered <= 0) { showToast('Cash tendered is required'); return; }
            if (cashTendered + 0.009 < grandTotalForPayment) { showToast('Cash tendered cannot be lower than grand total'); return; }
        } else if (pickupPaymentMethod === 'check') {
            const paymentAmount = parseFloat(String(document.getElementById('pickupCheckPaymentAmount')?.value || '0').replace(/[^0-9.]/g, '')) || 0;
            if (!document.getElementById('pickupCheckDate')?.value || !document.getElementById('pickupCheckNumber')?.value.trim() || !document.getElementById('pickupBankBranch')?.value.trim()) {
                showToast('All check details are required');
                return;
            }
            if (paymentAmount <= 0) { showToast('Payment Amount is required'); document.getElementById('pickupCheckPaymentAmount')?.focus(); return; }
            if (Math.abs(paymentAmount - grandTotalForPayment) > 0.01) { showToast('Payment Amount must be equal to the grand total'); document.getElementById('pickupCheckPaymentAmount')?.focus(); return; }
        } else if (pickupPaymentMethod === 'online_transfer') {
            const paymentAmount = parseFloat(String(document.getElementById('pickupOnlinePaymentAmount')?.value || '0').replace(/[^0-9.]/g, '')) || 0;
            if (!document.getElementById('pickupReferenceNumber')?.value.trim() || !document.getElementById('pickupOnlineBankName')?.value.trim()) {
                showToast('Reference number and Bank/Wallet are required');
                return;
            }
            if (paymentAmount <= 0) { showToast('Payment Amount is required'); document.getElementById('pickupOnlinePaymentAmount')?.focus(); return; }
            if (Math.abs(paymentAmount - grandTotalForPayment) > 0.01) { showToast('Payment Amount must be equal to the grand total'); document.getElementById('pickupOnlinePaymentAmount')?.focus(); return; }
        }
    }

    const isDeliveryOrder = !isWalkin && deliveryType === 'delivery';
    const newDriverVisible = document.getElementById('newDriverFields')?.style.display !== 'none';
    const newVehicleVisible = false;
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

        if (!document.getElementById('deliveryVehicleSelect')?.value) {
            showToast('Please select a registered Motorpool vehicle.');
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
        const invoiceSubmitModeForLoading = window.invoiceOrderSubmitMode || '';
        const invoiceSaveCloseBtn = document.getElementById('invoiceSaveCloseBtn');
        const invoiceSaveNewBtn = document.getElementById('invoiceSaveNewBtn');
        const activeInvoiceSubmitBtn = invoiceSubmitModeForLoading === 'close'
            ? invoiceSaveCloseBtn
            : (invoiceSubmitModeForLoading === 'new' ? invoiceSaveNewBtn : null);
        const buttonsToLock = [btn, invoiceSaveCloseBtn, invoiceSaveNewBtn].filter(Boolean);
        const submitLoadingHtml = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Saving...';

        buttonsToLock.forEach(button => {
            if (!button.dataset.originalHtml) {
                button.dataset.originalHtml = button.innerHTML;
            }
            button.disabled = true;
        });

        if (btn) {
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Processing...';
        }
        if (activeInvoiceSubmitBtn) {
            activeInvoiceSubmitBtn.innerHTML = submitLoadingHtml;
        }

        const restoreSubmitButtons = () => {
            buttonsToLock.forEach(button => {
                button.disabled = false;
                if (button.dataset.originalHtml) {
                    button.innerHTML = button.dataset.originalHtml;
                }
            });
        };
        
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
            fulfillment_type: isCreditOrder ? 'pickup' : ((isWalkin || deliveryType === 'pickup') ? 'pickup' : 'delivery'),
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
            collect_payment: (!isCreditOrder && collectPickupPayment) ? '1' : '0',
            payment_method: pickupPaymentMethod,
            cash_tendered: document.getElementById('pickupCashTendered')?.value || '',
            check_date: document.getElementById('pickupCheckDate')?.value || '',
            check_number: document.getElementById('pickupCheckNumber')?.value || '',
            bank_name: '',
            bank_branch: document.getElementById('pickupBankBranch')?.value || '',
            payment_amount: pickupPaymentMethod === 'check'
                ? (document.getElementById('pickupCheckPaymentAmount')?.value || '')
                : (pickupPaymentMethod === 'online_transfer' ? (document.getElementById('pickupOnlinePaymentAmount')?.value || '') : ''),
            reference_number: document.getElementById('pickupReferenceNumber')?.value || '',
            online_bank_name: document.getElementById('pickupOnlineBankName')?.value || '',
            online_bank_branch: document.getElementById('pickupOnlineBankBranch')?.value || '',
            document_type: documentType,
            billing_type: billingType,
            is_recurring: recurringSchedule.is_recurring,
            recurring_every: recurringSchedule.recurring_every,
            recurring_period: recurringSchedule.recurring_period,
            recurring_until: recurringSchedule.recurring_until,
            si_number: siNumber,
            atw_no: isCreditOrder ? '' : atwNo,
            gatepass_no: isCreditOrder ? '' : gatepassNo,
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
                // Keep order/invoice modal open after saving. User will close it manually.
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
                    const invoiceSubmitMode = window.invoiceOrderSubmitMode || '';
                    const isInvoiceSaveClose = invoiceSubmitMode === 'close';
                    const isInvoiceSaveNew = invoiceSubmitMode === 'new';

                    if (isInvoiceSaveClose || isInvoiceSaveNew) {
                        window.invoiceOrderSubmitMode = '';
                        Swal.fire({
                            icon: 'success',
                            title: isWalkin ? 'Order Completed!' : 'Order Submitted!',
                            html: alertHtml + `
                                <div style="margin-top: 14px; padding: 10px; background: #e8f5e9; border-radius: 10px; color: #047857; font-weight: 700;">
                                    Saved successfully. Use the Close button when you are done.
                                </div>
                            `,
                            confirmButtonText: 'OK',
                            confirmButtonColor: '#047857',
                            background: '#ffffff',
                            backdrop: `rgba(0,0,0,0.4)`,
                            allowOutsideClick: false,
                            allowEscapeKey: true,
                            allowEnterKey: true,
                            focusConfirm: true,
                            heightAuto: false,
                            scrollbarPadding: false,
                            target: document.body,
                            customClass: {
                                popup: 'animated-order-alert',
                                confirmButton: 'order-confirm-btn'
                            },
                            didOpen: () => {
                                const swalContainer = document.querySelector('.swal2-container');
                                const okButton = Swal.getConfirmButton();
                                if (swalContainer) {
                                    swalContainer.style.zIndex = '2147483000';
                                    swalContainer.style.pointerEvents = 'auto';
                                }
                                if (okButton) {
                                    okButton.disabled = false;
                                    okButton.style.pointerEvents = 'auto';
                                    okButton.style.cursor = 'pointer';
                                    okButton.focus();

                                    // AMGC FIX: Bootstrap modal/focus layers can sometimes keep the SweetAlert
                                    // visible after OK is clicked. Force-close and clean only the SweetAlert layer.
                                    okButton.onclick = function() {
                                        setTimeout(function() {
                                            if (typeof Swal !== 'undefined' && Swal.isVisible()) {
                                                Swal.close({ isConfirmed: true, value: true });
                                            }
                                            document.querySelectorAll('.swal2-container').forEach(function(el) { el.remove(); });
                                            document.body.classList.remove('swal2-shown', 'swal2-height-auto');
                                        }, 0);
                                    };
                                }
                            }
                        }).then(() => {
                            window.invoiceOrderSubmitMode = '';
                            if (typeof window.clearOrderProductFieldsAfterSubmit === 'function') {
                                window.clearOrderProductFieldsAfterSubmit();
                            }
                            const swalContainer = document.querySelector('.swal2-container');
                            if (swalContainer && !Swal.isVisible()) {
                                swalContainer.remove();
                            }
                            document.body.classList.remove('swal2-shown', 'swal2-height-auto');
                        });
                    } else {
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
                            allowEscapeKey: true,
                            allowEnterKey: true,
                            focusConfirm: true,
                            target: document.body,
                            didOpen: () => {
                                const swalContainer = document.querySelector('.swal2-container');
                                const confirmButton = Swal.getConfirmButton();
                                const denyButton = Swal.getDenyButton();
                                if (swalContainer) {
                                    swalContainer.style.zIndex = '2147483000';
                                    swalContainer.style.pointerEvents = 'auto';
                                }
                                [confirmButton, denyButton].forEach((btn) => {
                                    if (btn) {
                                        btn.disabled = false;
                                        btn.style.pointerEvents = 'auto';
                                        btn.style.cursor = 'pointer';
                                    }
                                });

                                // AMGC FIX: make sure the success alert disappears immediately on OK/View Order.
                                if (confirmButton) {
                                    confirmButton.onclick = function() {
                                        setTimeout(function() {
                                            if (typeof Swal !== 'undefined' && Swal.isVisible()) {
                                                Swal.close({ isConfirmed: true, value: true });
                                            }
                                        }, 0);
                                    };
                                }
                            },

                            customClass: {
                                popup: 'animated-order-alert',
                                confirmButton: 'order-confirm-btn',
                                denyButton: 'order-confirm-btn',
                                cancelButton: 'order-cancel-btn'
                            }

                        }).then((result) => {

                            if (typeof window.clearOrderProductFieldsAfterSubmit === 'function') {
                                window.clearOrderProductFieldsAfterSubmit();
                            }

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

                            // CLOSE / dismissed: stay on the invoice/order modal. Manual close button na lang.
                            else {
                                window.invoiceOrderSubmitMode = '';
                            }

                        });
                    }

                } else {
                    if (d.type === 'credit_limit_required' || d.type === 'outstanding_balance_required') {
                        restoreSubmitButtons();
                        const cartModalEl = document.getElementById('cartModal');
                        const cartModalInstance = cartModalEl ? bootstrap.Modal.getInstance(cartModalEl) : null;
                        const openApproval = () => showBeyondCreditApprovalModal(
                            d,
                            (explanation) => submitWithSoSuffix(manualSoSuffix, explanation, true, d.type === 'outstanding_balance_required' ? 'outstanding_balance' : 'credit_limit'),
                            () => {
                                // Default Style uses the invoice layout directly.
                                // Kapag kinancel ang Outstanding Balance / Credit approval,
                                // huwag ibalik o ipakita ang Classic Review & Confirm modal.
                                const currentStyle = (typeof window.getOrderProductStyle === 'function') ? window.getOrderProductStyle() : activeOrderStyle;
                                if (currentStyle === 'invoice') {
                                    if (cartModalEl && cartModalEl.classList.contains('show')) {
                                        bootstrap.Modal.getInstance(cartModalEl)?.hide();
                                    }
                                    document.querySelectorAll('.modal-backdrop').forEach(backdrop => backdrop.remove());
                                    document.body.classList.remove('modal-open');
                                    document.body.style.removeProperty('overflow');
                                    document.body.style.removeProperty('padding-right');
                                    return;
                                }

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
                restoreSubmitButtons();
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
                restoreSubmitButtons(); 
            });
    };

    const invoiceNumberVisualValue = sanitizeDigits(document.getElementById('invoiceNumberVisual')?.value || '', 6);
    const activeOrderStyle = (typeof window.getOrderProductStyle === 'function') ? window.getOrderProductStyle() : 'classic';

    if (documentType === 'SI') {
        submitWithSoSuffix(invoiceNumberVisualValue || autoSoSuffix);
    } else if (activeOrderStyle === 'invoice') {
        // Default Style uses the Invoice # field directly.
        // If blank, it will still use the existing auto-generated suffix.
        submitWithSoSuffix(invoiceNumberVisualValue || autoSoSuffix);
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
    
    // Keep Employees and Tasks badges synchronized with the dropdown state.
    function updateEmployeesTaskBadge() {
        const employeesMenu = document.getElementById('employeesMenu');
        const employeesDropdown = employeesMenu
            ? employeesMenu.closest('.employees-dropdown')
            : null;

        if (!employeesMenu || !employeesDropdown) {
            return;
        }

        const isOpen = employeesMenu.classList.contains('show');
        const parentBadge = employeesDropdown.querySelector('.task-parent-badge');
        const childBadge = employeesDropdown.querySelector('.task-child-badge');

        employeesDropdown.classList.toggle('employees-menu-open', isOpen);

        if (parentBadge) {
            parentBadge.style.display = isOpen ? 'none' : 'inline-flex';
        }

        if (childBadge) {
            childBadge.style.display = isOpen ? 'inline-flex' : 'none';
        }
    }

    window.updateEmployeesTaskBadge = updateEmployeesTaskBadge;

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
            setTimeout(() => {
                document.querySelectorAll('.sidebar .collapse.show').forEach(collapse => {
                    if (collapse.id !== targetId) {
                        collapse.classList.remove('show');
                    }
                });

                target.classList.add('show');

                if (typeof window.updateEmployeesTaskBadge === 'function') {
                    window.updateEmployeesTaskBadge();
                }

                if (arrow) {
                    arrow.style.transform = 'translateY(-50%) rotate(180deg)';
                }
            }, 50);
            return;
        }
        if (target.classList.contains('show')) { target.classList.remove('show'); if (arrow) arrow.style.transform = 'translateY(-50%) rotate(0deg)'; }
        else { document.querySelectorAll('.sidebar .collapse.show').forEach(collapse => collapse.classList.remove('show')); target.classList.add('show'); if (typeof window.updateEmployeesTaskBadge === 'function') { window.updateEmployeesTaskBadge(); } if (arrow) arrow.style.transform = 'translateY(-50%) rotate(180deg)'; }
        if (typeof window.updateEmployeesTaskBadge === 'function') {
            window.updateEmployeesTaskBadge();
        }
    }
    

    document.addEventListener('DOMContentLoaded', function () {
        if (typeof window.updateEmployeesTaskBadge === 'function') {
            window.updateEmployeesTaskBadge();
        }

        const employeesMenu = document.getElementById('employeesMenu');

        if (employeesMenu && !employeesMenu.dataset.taskBadgeObserverAttached) {
            employeesMenu.dataset.taskBadgeObserverAttached = '1';

            const taskBadgeObserver = new MutationObserver(function () {
                if (typeof window.updateEmployeesTaskBadge === 'function') {
                    window.updateEmployeesTaskBadge();
                }
            });

            taskBadgeObserver.observe(employeesMenu, {
                attributes: true,
                attributeFilter: ['class']
            });
        }
    });

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
            setupInvoicePaymentListeners();
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

            const customerGroup = String(order.customer_group || '').trim();
            const contactNumber = String(order.phone_number || '').trim();
            const customerAddress = String(order.address || '').trim();
            const invoiceNumber = invoice ? String(invoice.si_number || invoice.invoice_number || '').trim() : orderSINumber;
            const termsDays = invoice && invoice.due_date && invoice.invoice_date
                ? Math.max(0, Math.round((new Date(invoice.due_date) - new Date(invoice.invoice_date)) / (1000 * 60 * 60 * 24)))
                : '';
            const dueDate = invoice && invoice.due_date ? invoice.due_date : '';
            const payments = Array.isArray(data.payments) ? data.payments : [];
            const paymentTotal = parseFloat(order.payment_total || 0) || 0;
            const balanceDue = parseFloat(order.balance_due || (finalGrandTotal - paymentTotal)) || 0;
            const deliveryType = String(order.fulfillment_type || '').toLowerCase() === 'delivery' ? 'Delivery' : 'Pick Up';
            const creditLimit = parseFloat(order.credit_limit || 0) || 0;
            const outstandingBalance = Math.max(parseFloat(order.outstanding_balance_amount || 0) || 0, parseFloat(order.credit_used || 0) || 0);
            const customerDisplay = order.store_name || order.customer_name || '';
            const docTitle = (documentType === 'SI' || invoiceNumber) ? 'Invoice' : 'Sales Order';
            const cleanDateForInput = order.order_date ? String(order.order_date).substring(0, 10) : '';
            const cleanDueForInput = dueDate ? String(dueDate).substring(0, 10) : cleanDateForInput;
            const termsText = termsDays ? (termsDays + ' days') : (invoice && invoice.terms ? invoice.terms : '');

            let invoiceRowsHtml = '';
            let totalQtyForDisplay = 0;
            if (items.length > 0) {
                items.forEach((item, idx) => {
                    const qty = parseFloat(item.quantity_ordered || item.quantity || 0) || 0;
                    const unitPrice = parseFloat(item.net_price || item.unit_price || item.gross_price || 0) || 0;
                    const lineTotal = parseFloat(item.order_amount || item.line_total || (qty * unitPrice) || 0) || 0;
                    totalQtyForDisplay += qty;
                    invoiceRowsHtml += `
                        <tr class="${idx % 2 === 1 ? 'op-invoice-alt-row' : ''}">
                            <td>
                                <strong>${escapeHtml(item.item_name || item.item_description || '')}</strong>
                                <div class="op-invoice-muted">${escapeHtml(item.item_code || '')}</div>
                            </td>
                            <td class="text-center">${escapeHtml(item.unit_type || '')}</td>
                            <td class="text-center">${Number.isInteger(qty) ? parseInt(qty) : qty}</td>
                            <td class="text-end">${formatCurrency(unitPrice)}</td>
                            <td class="text-end fw-semibold">${formatCurrency(lineTotal)}</td>
                        </tr>
                    `;
                });
            }
            while (items.length > 0 && invoiceRowsHtml.split('<tr').length <= 10) {
                invoiceRowsHtml += `<tr><td>&nbsp;</td><td></td><td></td><td></td><td></td></tr>`;
            }
            if (!items.length) {
                for (let i = 0; i < 10; i++) {
                    invoiceRowsHtml += `<tr class="${i % 2 === 1 ? 'op-invoice-alt-row' : ''}"><td>&nbsp;</td><td></td><td></td><td></td><td></td></tr>`;
                }
            }

            const paymentsHtml = payments.length ? payments.map(pay => {
                const payDate = pay.payment_date ? new Date(pay.payment_date).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' }) : '-';
                const method = String(pay.payment_method || '').replace('_', ' ');
                const ref = pay.reference_number || pay.check_number || pay.si_number || '-';
                return `<div class="op-payment-line"><span>${escapeHtml(method || 'Payment')} • ${escapeHtml(payDate)} • Ref: ${escapeHtml(ref)}</span><strong>${formatCurrency(pay.amount || 0)}</strong></div>`;
            }).join('') : `<div class="op-invoice-empty-line">No payment recorded</div>`;

            const buildSIAttachmentsHtml = (attachments) => {
                if (!Array.isArray(attachments) || attachments.length === 0) {
                    return '<div class="text-muted small">No SI attachment uploaded.</div>';
                }
                return `<div class="d-flex flex-column gap-2">${attachments.map((file, index) => {
                    const fileName = String(file.name || ('SI Attachment ' + (index + 1))).trim();
                    const filePath = String(file.path || '').trim();
                    const uploadedAt = String(file.uploaded_at || '').trim();
                    if (!filePath) return '';
                    return `<button type="button" class="btn btn-sm btn-outline-success text-start si-attachment-view-btn" data-file-path="${escapeHtml(filePath)}" data-file-name="${escapeHtml(fileName)}"><i class="bi bi-paperclip me-1"></i>${escapeHtml(fileName)}${uploadedAt ? `<span class="d-block text-muted small">${escapeHtml(uploadedAt)}</span>` : ''}</button>`;
                }).join('')}</div>`;
            };
            const siAttachments = Array.isArray(order.si_attachments_list) && order.si_attachments_list.length ? order.si_attachments_list : (invoice && Array.isArray(invoice.si_attachments_list) ? invoice.si_attachments_list : []);

            const deliveryInfoHtml = (documents.pick_list_number || documents.driver_name || documents.vehicle || documents.trip_ticket_number || order.assigned_driver) ? `
                <div class="op-invoice-panel mt-3">
                    <div class="op-section-label">DELIVERY INFORMATION</div>
                    <div class="row g-2">
                        <div class="col-md-6"><div class="op-readonly-field"><small>Driver</small><strong>${escapeHtml(documents.driver_name || order.assigned_driver || '-')}</strong></div></div>
                        <div class="col-md-6"><div class="op-readonly-field"><small>Vehicle</small><strong>${escapeHtml(documents.vehicle || '-')}</strong></div></div>
                        <div class="col-md-6"><div class="op-readonly-field"><small>Trip Ticket No.</small><strong>${escapeHtml(documents.trip_ticket_number || '-')}</strong></div></div>
                        <div class="col-md-6"><div class="op-readonly-field"><small>Pick List No.</small><strong>${escapeHtml(documents.pick_list_number || '-')}</strong></div></div>
                    </div>
                </div>
            ` : '';

            const siDetailsReadonlyHtml = (orderSINumber || registeredBusinessName || tin || businessAddress || siAttachments.length) ? `
                <div class="op-invoice-panel mt-3">
                    <div class="op-section-label">SI DETAILS</div>
                    <div class="row g-2">
                        <div class="col-md-6"><div class="op-readonly-field"><small>SI Number</small><strong>${escapeHtml(orderSINumber || '-')}</strong></div></div>
                        <div class="col-md-6"><div class="op-readonly-field"><small>Registered Business Name</small><strong>${escapeHtml(registeredBusinessName || '-')}</strong></div></div>
                        <div class="col-md-4"><div class="op-readonly-field"><small>TIN</small><strong>${escapeHtml(tin || '-')}</strong></div></div>
                        <div class="col-md-8"><div class="op-readonly-field"><small>Business Address</small><strong>${escapeHtml(businessAddress || '-')}</strong></div></div>
                        <div class="col-12"><div class="op-readonly-field"><small>SI Attachments</small>${buildSIAttachmentsHtml(siAttachments)}</div></div>
                    </div>
                </div>
            ` : '';

            const invoiceDateDisplay = cleanDateForInput || '-';
            const dueDateDisplay = cleanDueForInput || invoiceDateDisplay;
            const paymentMethodDisplay = payments.length ? String(payments[0].payment_method || '').replace('_', ' ') : '-';
            const cashTenderedDisplay = payments.length ? formatCurrency(payments[0].cash_tendered || payments[0].amount || 0) : '-';
            const showCredit = String(order.billing_type || '').toLowerCase() === 'credit';
            const arLabel = 'Accounts Receivable';
            const customerMessage = order.customer_message || order.remarks || order.notes || '';
            const detailSoId = Number(order.so_id || orderId) || 0;
            const detailStatusLower = String(order.order_status || '').toLowerCase().trim();
            const isDetailDelivered = detailStatusLower === 'delivered' || detailStatusLower === 'completed';
            const detailFooterButtons = isDetailDelivered
                ? `
                    <button type="button" class="btn btn-light border" data-bs-dismiss="modal" onclick="amgcCloseOrderDetailsModal && amgcCloseOrderDetailsModal()">Close</button>
                    <button type="button" class="btn btn-success" onclick="printSingleOrderFromOrderProduct(${detailSoId})"><i class="bi bi-printer me-1"></i> Print</button>
                `
                : `
                    <button type="button" class="btn btn-light border" data-bs-dismiss="modal" onclick="amgcCloseOrderDetailsModal && amgcCloseOrderDetailsModal()">Close</button>
                    <button type="button" class="btn btn-success" onclick="editOrderFromDetails(${detailSoId})"><i class="bi bi-pencil-square me-1"></i> Edit Order</button>
                    <button type="button" class="btn btn-danger" onclick="deleteOrderFromDetails(${detailSoId})"><i class="bi bi-trash me-1"></i> Delete Order</button>
                `;

            orderDetailsContent.innerHTML = `
                <div class="op-invoice-form-view">
                    <div class="op-invoice-main">
                        <div class="op-invoice-sheet-head">
                            <div>
                                <div class="op-invoice-title">Invoice</div>
                                <div class="op-customer-readonly">
                                    <div class="name">${escapeHtml(customerDisplay || '-')}</div>
                                    <div><strong>Contact No:</strong> ${escapeHtml(contactNumber || '-')}</div>
                                    <div><strong>Address:</strong> ${escapeHtml(customerAddress || '-')}</div>
                                    <div><strong>Customer Group:</strong> ${escapeHtml(customerGroup || '-')}</div>
                                    <div><strong>Credit Limit:</strong> ${formatCurrency(creditLimit)}</div>
                                    <div><strong>Outstanding Balance:</strong> ${formatCurrency(outstandingBalance)}</div>
                                </div>
                            </div>

                            <div class="op-right-fields">
                                <div class="op-field">
                                    <label>DATE</label>
                                    <input class="op-detail-control" value="${escapeHtml(invoiceDateDisplay)}" readonly>
                                </div>
                                <div class="op-field">
                                    <label>INVOICE #</label>
                                    <input class="op-detail-control" value="${escapeHtml(invoiceNumber || 'Auto')}" readonly>
                                </div>
                                <div class="op-field">
                                    <label>TERMS</label>
                                    <select class="op-detail-select" disabled><option>${escapeHtml(termsText || '-')}</option></select>
                                </div>
                                <div class="op-field">
                                    <label>DUE DATE</label>
                                    <input class="op-detail-control" value="${escapeHtml(dueDateDisplay)}" readonly>
                                </div>
                                <div class="op-field op-field-wide">
                                    <label>ATW NO.</label>
                                    <input class="op-detail-control" value="${escapeHtml(atwNo || 'Optional')}" readonly>
                                </div>
                                <div class="op-field op-field-wide">
                                    <label>GATEPASS NO.</label>
                                    <input class="op-detail-control" value="${escapeHtml(gatepassNo || 'Required')}" readonly>
                                </div>
                                <div class="op-field op-field-wide">
                                    <label>SO #</label>
                                    <input class="op-detail-control" value="${escapeHtml(order.so_number || '-')}" readonly>
                                </div>
                                <div class="op-field op-field-wide">
                                    <label>DELIVERY TYPE</label>
                                    <select class="op-detail-select" disabled><option>${escapeHtml(deliveryType)}</option></select>
                                </div>
                            </div>
                        </div>

                        <div class="op-items-wrap">
                            <table class="op-items-table">
                                <thead>
                                    <tr>
                                        <th style="width:34%;">PRODUCT</th>
                                        <th style="width:18%;">UNIT</th>
                                        <th style="width:12%;">QTY</th>
                                        <th style="width:18%;">PRICE</th>
                                        <th style="width:18%;">TOTAL</th>
                                    </tr>
                                </thead>
                                <tbody>${invoiceRowsHtml}</tbody>
                            </table>
                        </div>

                        <div class="op-lower-area">
                            <div>
                                <div class="op-section-label">CUSTOMER MESSAGE</div>
                                <textarea class="form-control op-message-box" readonly>${escapeHtml(customerMessage)}</textarea>

                                <div class="op-payment-box">
                                    <div class="op-payment-toggle-line">
                                        <input type="checkbox" ${payments.length ? 'checked' : ''} disabled>
                                        <span>COLLECT PAYMENT NOW</span>
                                    </div>
                                    <div class="op-payment-detail-line">
                                        <div class="op-field">
                                            <label>PAYMENT METHOD</label>
                                            <select class="op-detail-select" disabled><option>${escapeHtml(paymentMethodDisplay)}</option></select>
                                        </div>
                                        <div class="op-field">
                                            <label>CASH TENDERED</label>
                                            <input class="op-detail-control" value="${escapeHtml(cashTenderedDisplay)}" readonly>
                                            <div class="small text-muted mt-1">Change: ${formatCurrency(payments.length ? (payments[0].cash_change || 0) : 0)}</div>
                                        </div>
                                    </div>
                                </div>

                                ${deliveryInfoHtml}
                                ${siDetailsReadonlyHtml}
                            </div>

                            <div>
                                <div class="op-summary-box">
                                    <div><span>(${(parseFloat(order.discount_percent || 0) || 0).toFixed(1)}%)</span><strong>${formatCurrency(finalDiscount)}</strong></div>
                                    <div><span>TOTAL</span><strong>${formatCurrency(finalGrandTotal)}</strong></div>
                                    <div><span>PAYMENTS APPLIED</span><strong>${formatCurrency(paymentTotal)}</strong></div>
                                    <div class="op-balance-due"><span>BALANCE DUE</span><strong>${formatCurrency(balanceDue)}</strong></div>
                                </div>

                                <div class="op-detail-footer">
                                    ${detailFooterButtons}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            `;

            if (printBtn) printBtn.style.display = 'none';
            if (cancelBtn) cancelBtn.style.display = 'none';
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

    function editOrderFromDetails(orderId) {
        const detailsModalEl = document.getElementById('orderDetailsModal');
        const openEditModal = function() {
            document.body.classList.remove('modal-open');
            document.querySelectorAll('.modal-backdrop').forEach(backdrop => backdrop.remove());

            if (typeof editOrder === 'function') {
                editOrder(orderId);
                return;
            }

            if (typeof editOrderFromOrderProduct === 'function') {
                editOrderFromOrderProduct(orderId);
            }
        };

        if (detailsModalEl) {
            const detailsModal = bootstrap.Modal.getInstance(detailsModalEl) || bootstrap.Modal.getOrCreateInstance(detailsModalEl);
            detailsModalEl.addEventListener('hidden.bs.modal', openEditModal, { once: true });
            detailsModal.hide();

            setTimeout(() => {
                if (!detailsModalEl.classList.contains('show')) {
                    openEditModal();
                }
            }, 350);
        } else {
            openEditModal();
        }
    }

    function deleteOrderFromDetails(orderId) {
        const detailsModal = bootstrap.Modal.getInstance(document.getElementById('orderDetailsModal'));
        if (detailsModal) detailsModal.hide();
        deleteOrderFromOrderProduct(orderId);
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


    function editOrderFromOrderProduct(orderId) {
        const modalEl = document.getElementById('opEditSalesOrderModal');
        const body = document.getElementById('opEditSalesOrderItemsBody');
        if (!modalEl || !body) return;
        body.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-4">Loading...</td></tr>';
        bootstrap.Modal.getOrCreateInstance(modalEl).show();

        fetch('orderproduct.php', {
            method: 'POST',
            headers: {'Content-Type':'application/x-www-form-urlencoded','X-Requested-With':'XMLHttpRequest'},
            body: 'action=get_order_details&order_id=' + encodeURIComponent(orderId)
        })
        .then(r => r.json())
        .then(data => {
            if (!data.success) throw new Error(data.message || 'Unable to load sales order.');
            const order = data.order || {};
            document.getElementById('opEditSoId').value = order.so_id || orderId;
            document.getElementById('opEditSoNumber').value = order.so_number || '';
            document.getElementById('opEditOrderDate').value = (order.order_date || '').substring(0, 10) || new Date().toISOString().substring(0, 10);
            document.getElementById('opEditOrderStatus').value = (order.order_status || 'pending').toLowerCase();
            document.getElementById('opEditSiNumber').value = order.si_number || '';
            document.getElementById('opEditRegisteredBusinessName').value = order.registered_business_name || '';
            document.getElementById('opEditTin').value = order.tin || '';
            document.getElementById('opEditBusinessAddress').value = order.business_address || '';
            renderEditOrderItemsFromOrderProduct(data.items || []);
        })
        .catch(err => {
            body.innerHTML = '<tr><td colspan="5" class="text-center text-danger py-4">' + escapeHtml(err.message || 'Failed to load order.') + '</td></tr>';
        });
    }

    function renderEditOrderItemsFromOrderProduct(items) {
        const body = document.getElementById('opEditSalesOrderItemsBody');
        if (!body) return;
        if (!items.length) {
            body.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-4">No items found.</td></tr>';
            recalcEditOrderFromOrderProductTotal();
            return;
        }
        body.innerHTML = items.map(item => {
            const qty = parseFloat(item.quantity_ordered || 0) || 0;
            const price = parseFloat(item.net_price || item.unit_price || item.gross_price || 0) || 0;
            const name = item.item_name || item.item_description || item.item_code || 'Item';
            const unit = item.unit_type || '';
            return `
                <tr data-so-item-id="${escapeHtml(item.so_item_id || '')}">
                    <td class="text-center"><strong>${escapeHtml(name)}</strong><div class="small text-muted">${escapeHtml(item.item_code || '')}</div></td>
                    <td class="text-center">${escapeHtml(unit)}</td>
                    <td><input type="number" min="0" step="0.01" class="form-control form-control-sm text-center op-edit-item-qty" value="${qty}" oninput="recalcEditOrderFromOrderProductTotal()"></td>
                    <td><input type="number" min="0" step="0.01" class="form-control form-control-sm text-end op-edit-item-price" value="${price.toFixed(2)}" oninput="recalcEditOrderFromOrderProductTotal()"></td>
                    <td class="text-end fw-bold op-edit-item-total">${formatCurrency(qty * price)}</td>
                </tr>
            `;
        }).join('');
        recalcEditOrderFromOrderProductTotal();
    }

    function recalcEditOrderFromOrderProductTotal() {
        let total = 0;
        document.querySelectorAll('#opEditSalesOrderItemsBody tr[data-so-item-id]').forEach(row => {
            const qty = parseFloat(row.querySelector('.op-edit-item-qty')?.value || 0) || 0;
            const price = parseFloat(row.querySelector('.op-edit-item-price')?.value || 0) || 0;
            const lineTotal = qty * price;
            total += lineTotal;
            const totalCell = row.querySelector('.op-edit-item-total');
            if (totalCell) totalCell.textContent = formatCurrency(lineTotal);
        });
        const grand = document.getElementById('opEditSalesOrderGrandTotal');
        if (grand) grand.textContent = formatCurrency(total);
    }

    function getEditedOrderItemsFromOrderProduct() {
        const items = [];
        document.querySelectorAll('#opEditSalesOrderItemsBody tr[data-so-item-id]').forEach(row => {
            items.push({
                so_item_id: row.getAttribute('data-so-item-id'),
                quantity_ordered: parseFloat(row.querySelector('.op-edit-item-qty')?.value || 0) || 0,
                unit_price: parseFloat(row.querySelector('.op-edit-item-price')?.value || 0) || 0
            });
        });
        return items;
    }

    function saveEditedOrderFromOrderProduct(event) {
        event.preventDefault();
        const soId = document.getElementById('opEditSoId')?.value || '';
        const formData = new FormData();
        formData.append('action', 'update_sales_order_from_tab');
        formData.append('so_id', soId);
        formData.append('order_date', document.getElementById('opEditOrderDate')?.value || '');
        formData.append('order_status', document.getElementById('opEditOrderStatus')?.value || 'pending');
        formData.append('si_number', document.getElementById('opEditSiNumber')?.value || '');
        formData.append('registered_business_name', document.getElementById('opEditRegisteredBusinessName')?.value || '');
        formData.append('tin', document.getElementById('opEditTin')?.value || '');
        formData.append('business_address', document.getElementById('opEditBusinessAddress')?.value || '');
        formData.append('edited_items', JSON.stringify(getEditedOrderItemsFromOrderProduct()));

        fetch('orderproduct.php', { method: 'POST', body: formData, headers: {'X-Requested-With':'XMLHttpRequest'} })
            .then(r => r.json())
            .then(data => {
                if (!data.success) throw new Error(data.message || 'Failed to update sales order.');
                Swal.fire({icon:'success', title:'Updated', text:data.message || 'Sales order updated successfully.', timer:1300, showConfirmButton:false})
                    .then(() => window.location.href = 'orderproduct.php?tab=salesOrder');
            })
            .catch(err => Swal.fire('Error', err.message || 'Failed to update sales order.', 'error'));
    }

    function deleteOrderFromOrderProduct(orderId) {
        Swal.fire({
            title: 'Delete Sales Order?',
            text: 'Only pending sales orders without related documents can be deleted.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc3545',
            confirmButtonText: 'Yes, delete it'
        }).then(result => {
            if (!result.isConfirmed) return;
            const formData = new FormData();
            formData.append('action', 'delete_sales_order_from_tab');
            formData.append('so_id', orderId);
            fetch('orderproduct.php', { method: 'POST', body: formData, headers: {'X-Requested-With':'XMLHttpRequest'} })
                .then(r => r.json())
                .then(data => {
                    if (!data.success) throw new Error(data.message || 'Failed to delete sales order.');
                    Swal.fire({icon:'success', title:'Deleted', text:data.message || 'Sales order deleted successfully.', timer:1300, showConfirmButton:false})
                        .then(() => window.location.href = 'orderproduct.php?tab=salesOrder');
                })
                .catch(err => Swal.fire('Error', err.message || 'Failed to delete sales order.', 'error'));
        });
    }


    // ===== Sales Order tab function aliases =====
    // These keep the Sales Order tab using the same function names as sales_order.php.
    function viewOrder(id) {
        return viewOrderFromOrderProduct(id);
    }

    function printSingleOrder(id) {
        return printSingleOrderFromOrderProduct(id);
    }

    function editOrder(id) {
        return editOrderFromOrderProduct(id);
    }

    
// Extra safety: bind the Update Order button and form submit directly.
// This fixes cases where inline onclick does not fire because of modal/form/script loading order.
document.addEventListener('DOMContentLoaded', function() {
    const updateBtn = document.getElementById('updateOrderBtn');
    if (updateBtn) {
        updateBtn.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            updateOrder();
        });
    }
    const editForm = document.getElementById('editOrderForm');
    if (editForm) {
        editForm.addEventListener('submit', function(e) {
            e.preventDefault();
            updateOrder();
        });
    }
});

function deleteOrder(id) {
        return deleteOrderFromOrderProduct(id);
    }

    function editFromView() {
        const modal = bootstrap.Modal.getInstance(document.getElementById('orderDetailsModal'));
        if (modal) modal.hide();
        setTimeout(() => {
            if (currentOrderIdFromOrderProduct) editOrder(currentOrderIdFromOrderProduct);
        }, 250);
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


/* ===== AMGC REAL FINAL FIX: DEFAULT STYLE FULL PAGE SCROLL =====
   This fixes the cut bottom issue in Default Invoice Style.
   Default style now uses normal page scrolling, while Classic keeps the old cart/table behavior.
*/
html,
body {
    width: 100% !important;
    max-width: 100% !important;
    height: auto !important;
    min-height: 100% !important;
    overflow-x: hidden !important;
    overflow-y: auto !important;
}

#appPage {
    width: 100% !important;
    height: auto !important;
    min-height: 100vh !important;
    max-height: none !important;
    overflow: visible !important;
}

body.order-product-invoice-style,
body.order-product-invoice-style #appPage {
    height: auto !important;
    min-height: 100vh !important;
    max-height: none !important;
    overflow-x: hidden !important;
    overflow-y: auto !important;
}

body.order-product-invoice-style .main-content {
    height: auto !important;
    min-height: 100vh !important;
    max-height: none !important;
    overflow: visible !important;
    display: block !important;
    padding-bottom: 170px !important;
}

body.order-product-invoice-style .navbar-top {
    position: relative !important;
    z-index: 10000 !important;
    overflow: visible !important;
}

body.order-product-invoice-style .order-style-navbar-dropdown,
body.order-product-invoice-style .order-style-navbar-dropdown .dropdown-menu,
body.order-product-invoice-style .order-style-menu {
    overflow: visible !important;
    z-index: 10001 !important;
}

body.order-product-invoice-style .order-style-navbar-dropdown .dropdown-menu,
body.order-product-invoice-style .order-style-menu {
    position: absolute !important;
    top: calc(100% + 8px) !important;
    left: 0 !important;
    right: auto !important;
    transform: none !important;
}

body.order-product-invoice-style .invoice-style-workspace {
    display: block !important;
    height: auto !important;
    min-height: auto !important;
    max-height: none !important;
    overflow: visible !important;
    margin-bottom: 170px !important;
}

body.order-product-invoice-style .invoice-sheet {
    height: auto !important;
    min-height: auto !important;
    max-height: none !important;
    overflow: visible !important;
    padding-bottom: 140px !important;
}

body.order-product-invoice-style .invoice-table-wrap {
    max-height: none !important;
    height: auto !important;
    overflow-y: visible !important;
    overflow-x: auto !important;
}

body.order-product-invoice-style .invoice-bottom-area {
    margin-bottom: 80px !important;
}

body.order-product-invoice-style .classic-cart-btn,
body.order-product-invoice-style [data-cart-button="true"] {
    display: none !important;
}

body.order-product-classic-style {
    height: 100vh !important;
    overflow: hidden !important;
}

body.order-product-classic-style #appPage {
    height: 100vh !important;
    max-height: 100vh !important;
    overflow: hidden !important;
}

body.order-product-classic-style .main-content {
    height: 100vh !important;
    max-height: 100vh !important;
    overflow: hidden !important;
    display: flex !important;
    flex-direction: column !important;
}

body.order-product-classic-style .product-table-container {
    overflow-y: auto !important;
    overflow-x: auto !important;
    padding-bottom: 130px !important;
}

@media (max-width: 992px) {
    body.order-product-invoice-style .main-content {
        padding-bottom: 190px !important;
    }
    body.order-product-invoice-style .invoice-sheet {
        min-width: 980px;
        padding-bottom: 170px !important;
    }
    body.order-product-invoice-style .invoice-style-workspace {
        overflow-x: auto !important;
        margin-bottom: 190px !important;
    }
}

</style>

<style id="amgc-default-invoice-table-green-final-override">
/* FINAL OVERRIDE: Default Style invoice table alternating green/white rows */
body.order-product-invoice-style .invoice-entry-table tbody tr:nth-child(odd),
body.order-product-invoice-style .invoice-entry-table tbody tr:nth-child(odd) td {
    background-color: #E6F4E6 !important;
}

body.order-product-invoice-style .invoice-entry-table tbody tr:nth-child(even),
body.order-product-invoice-style .invoice-entry-table tbody tr:nth-child(even) td {
    background-color: #FFFFFF !important;
}

body.order-product-invoice-style .invoice-entry-table tbody td,
body.order-product-invoice-style .invoice-entry-table tbody select,
body.order-product-invoice-style .invoice-entry-table tbody input {
    background: transparent !important;
    background-color: transparent !important;
}

body.order-product-invoice-style .invoice-entry-table tbody tr:nth-child(odd) select,
body.order-product-invoice-style .invoice-entry-table tbody tr:nth-child(odd) input {
    background-color: transparent !important;
}

body.order-product-invoice-style .invoice-entry-table tbody tr:nth-child(even) select,
body.order-product-invoice-style .invoice-entry-table tbody tr:nth-child(even) input {
    background-color: transparent !important;
}

/* Sales Order tab action buttons, same behavior/style names as sales_order.php */
.action-buttons{display:flex;align-items:center;justify-content:center;gap:6px;flex-wrap:nowrap;}
.btn-action{width:32px;height:32px;border:0;border-radius:9px;display:inline-flex;align-items:center;justify-content:center;font-size:.9rem;transition:all .18s ease;cursor:pointer;}
.btn-action:hover{transform:translateY(-1px);box-shadow:0 4px 10px rgba(0,0,0,.12);}
.btn-view{background:#e0f2fe;color:#0369a1;}
.btn-print{background:#dcfce7;color:#047857;}
.btn-edit{background:#fef3c7;color:#b45309;}
.btn-delete{background:#fee2e2;color:#b91c1c;}
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

<style id="amgc-default-invoice-delivery-type-fix">
/* Default Invoice Style: Pick Up / Delivery with conditional Driver and Vehicle fields */
body.order-product-invoice-style .invoice-right-fields {
    grid-template-columns: repeat(5, minmax(92px, 1fr)) !important;
    gap: 18px 20px !important;
}

body.order-product-invoice-style .invoice-fulfillment-field {
    min-width: 190px;
}

body.order-product-invoice-style .invoice-delivery-type-options {
    display: flex;
    gap: 10px;
    align-items: center;
    height: 32px;
}

body.order-product-invoice-style .invoice-delivery-type-option {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    margin: 0;
    font-size: 0.86rem;
    font-weight: 600;
    color: #052A47;
    cursor: pointer;
    white-space: nowrap;
}

body.order-product-invoice-style .invoice-delivery-type-option input {
    width: 16px !important;
    height: 16px !important;
    margin: 0 !important;
    accent-color: #2563eb;
}

body.order-product-invoice-style .invoice-delivery-field[style*="display: none"] {
    display: none !important;
}

@media (max-width: 1200px) {
    body.order-product-invoice-style .invoice-sheet-head {
        grid-template-columns: 1fr minmax(620px, 1fr) !important;
    }
    body.order-product-invoice-style .invoice-right-fields {
        grid-template-columns: repeat(4, minmax(150px, 1fr)) !important;
    }
    body.order-product-invoice-style .invoice-fulfillment-field {
        grid-column: span 2;
    }
}

@media (max-width: 768px) {
    body.order-product-invoice-style .invoice-right-fields {
        grid-template-columns: repeat(2, minmax(130px, 1fr)) !important;
    }
    body.order-product-invoice-style .invoice-fulfillment-field {
        grid-column: span 2;
    }
}
</style>


<style id="amgc-orderproduct-top-spacing-fix">
/* Final visible spacing above the Order Products card */
.main-content,
body.order-product-invoice-style .main-content,
body.order-product-classic-style .main-content {
    padding-top: 18px !important;
}

.navbar-top {
    margin-top: 0 !important;
    margin-bottom: 16px !important;
}

@media (max-width: 992px) {
    .main-content,
    .sidebar.collapsed ~ .main-content,
    body.order-product-invoice-style .main-content,
    body.order-product-classic-style .main-content {
        padding-top: 14px !important;
    }
}
</style>


<style>
/* Wider invoice-format Order Details modal so the form fits like the invoice page */
#orderDetailsModal .order-details-wide-modal,
#orderDetailsModal .modal-dialog {
    width: calc(100vw - 24px) !important;
    max-width: calc(100vw - 24px) !important;
    height: calc(100vh - 24px) !important;
    max-height: calc(100vh - 24px) !important;
    margin: 12px auto !important;
}
#orderDetailsModal .modal-content {
    width: 100% !important;
    height: 100% !important;
    max-height: none !important;
    border-radius: 8px !important;
}
#orderDetailsModal .modal-header {
    padding: .55rem .85rem !important;
}
#orderDetailsModal .modal-body {
    overflow-y: auto !important;
    overflow-x: hidden !important;
    padding: 0 !important;
    background: #fff !important;
}
#orderDetailsModal .op-invoice-form-view .op-invoice-main {
    padding: 18px 22px 16px !important;
}
#orderDetailsModal .op-invoice-form-view .op-invoice-sheet-head {
    grid-template-columns: minmax(360px, 1fr) minmax(760px, 820px) !important;
    gap: 18px !important;
}
#orderDetailsModal .op-invoice-form-view .op-right-fields {
    grid-template-columns: repeat(4, minmax(120px, 1fr)) !important;
    gap: 14px 16px !important;
}
#orderDetailsModal .op-invoice-form-view .op-invoice-title {
    font-size: 2.75rem !important;
    margin: 8px 0 14px !important;
}
#orderDetailsModal .op-invoice-form-view .op-detail-control,
#orderDetailsModal .op-invoice-form-view .op-detail-select {
    min-height: 32px !important;
    padding: 4px 9px !important;
    font-size: .9rem !important;
}
#orderDetailsModal .op-invoice-items-table thead th,
#orderDetailsModal .op-invoice-items-table td,
#orderDetailsModal .op-invoice-items-table tfoot td {
    padding: 7px 9px !important;
}
#orderDetailsModal .op-invoice-items-table {
    font-size: .9rem !important;
}
#orderDetailsModal .op-message-box {
    min-height: 44px !important;
}
#orderDetailsModal .op-payment-view-box {
    padding: 12px 14px !important;
}
#orderDetailsModal .op-summary-box > div {
    margin-bottom: 5px !important;
}
#orderDetailsModal .op-invoice-topbar {
    padding: 6px 10px !important;
}
#orderDetailsModal .op-invoice-form-view .op-invoice-topbar {
    grid-template-columns: minmax(430px, 1fr) 150px minmax(430px, .95fr) !important;
    gap: 18px !important;
}
@media (max-width: 1200px) {
    #orderDetailsModal .op-invoice-form-view .op-invoice-sheet-head {
        grid-template-columns: 1fr !important;
    }
    #orderDetailsModal .op-invoice-form-view .op-right-fields {
        grid-template-columns: repeat(3, minmax(130px, 1fr)) !important;
    }
}
@media (max-width: 768px) {
    #orderDetailsModal .order-details-wide-modal,
    #orderDetailsModal .modal-dialog {
        width: calc(100vw - 8px) !important;
        max-width: calc(100vw - 8px) !important;
        height: calc(100vh - 8px) !important;
        max-height: calc(100vh - 8px) !important;
        margin: 4px auto !important;
    }
    #orderDetailsModal .op-invoice-form-view .op-right-fields {
        grid-template-columns: 1fr 1fr !important;
    }
}
</style>

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
    document.addEventListener('DOMContentLoaded', function () {
    updateEmployeesTaskBadge();
});

/* ===== AMGC ORDER PRODUCT DUAL STYLE UPDATE SCRIPT ===== */
(function() {
    const ORDER_STYLE_KEY = 'amgc_order_product_style';

    function getStyleFromUrl() {
        const params = new URLSearchParams(window.location.search);
        const style = (params.get('style') || '').toLowerCase();
        if (style === 'classic' || style === 'current') return 'classic';
        if (style === 'invoice' || style === 'default') return 'invoice';
        return '';
    }

    window.getOrderProductStyle = function() {
        return getStyleFromUrl() || localStorage.getItem(ORDER_STYLE_KEY) || 'invoice';
    };

    window.setOrderProductStyle = function(style) {
        style = style === 'classic' ? 'classic' : 'invoice';
        localStorage.setItem(ORDER_STYLE_KEY, style);
        applyOrderProductStyle(style);
    };

    function applyOrderProductStyle(style) {
        document.body.classList.toggle('order-product-classic-style', style === 'classic');
        document.body.classList.toggle('order-product-invoice-style', style !== 'classic');

        const invoiceBtn = document.getElementById('invoiceStyleBtn');
        const classicBtn = document.getElementById('classicStyleBtn');
        if (invoiceBtn) invoiceBtn.classList.toggle('active', style !== 'classic');
        if (classicBtn) classicBtn.classList.toggle('active', style === 'classic');

        if (style !== 'classic') {
            buildInvoiceStyleRows();
            syncInvoiceCustomerOptions();
            updateInvoiceTotals();
        }
    }

    function sanitizeDigits(value, maxLen) {
        return String(value || '').replace(/\D/g, '').slice(0, maxLen || 6);
    }

    function todayYmd() {
        const d = new Date();
        return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
    }

    function addDaysYmd(days) {
        const d = new Date();
        d.setDate(d.getDate() + Number(days || 0));
        return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
    }

    function getInvoiceProductOptions(selectedId) {
        let html = '<option value=""></option>';
        const groupedProducts = {};
        [...inventory].sort((a, b) => {
            const catA = String(a.category || 'General').toLowerCase();
            const catB = String(b.category || 'General').toLowerCase();
            if (catA !== catB) return catA.localeCompare(catB);
            return String(a.name || '').localeCompare(String(b.name || ''));
        }).forEach(p => {
            const category = String(p.category || 'General').trim() || 'General';
            if (!groupedProducts[category]) groupedProducts[category] = [];
            groupedProducts[category].push(p);
        });

        Object.keys(groupedProducts).sort((a, b) => a.localeCompare(b)).forEach(category => {
            html += `<option value="" class="invoice-product-category-header-option dropdown-group-header-option" disabled>${escapeHtml(category)}</option>`;
            groupedProducts[category].forEach(p => {
                html += `<option value="${p.id}" data-category="${escapeHtml(category)}" ${String(selectedId || '') === String(p.id) ? 'selected' : ''}>${escapeHtml(p.name || '')}</option>`;
            });
        });
        return html;
    }

    function getInvoiceProductCategory(productId) {
        const product = inventory.find(p => Number(p.id) === Number(productId));
        return product && String(product.category || '').trim() ? String(product.category).trim() : 'General';
    }

    function setInvoiceRowCategory(row, productId) {
        const categoryEl = row?.querySelector('.invoice-selected-category');
        if (!categoryEl) return;
        if (!productId) {
            categoryEl.textContent = '';
            categoryEl.classList.remove('show');
            return;
        }
        categoryEl.textContent = getInvoiceProductCategory(productId);
        categoryEl.classList.add('show');
    }

    function getInvoiceUnitOptions(productId, selectedUnit) {
        const product = inventory.find(p => Number(p.id) === Number(productId));
        if (!product) return '<option value=""></option>';

        const units = productUnitTypes[productId] && productUnitTypes[productId].length
            ? productUnitTypes[productId]
            : [{ unit_type_name: product.default_unit_type_name || product.unit_type || 'Piece', unit_price: product.unit_price || 0 }];

        let html = '';
        units.forEach((ut, idx) => {
            const name = ut.unit_type_name || product.default_unit_type_name || product.unit_type || 'Piece';
            const selected = selectedUnit ? String(selectedUnit).toLowerCase() === String(name).toLowerCase() : idx === 0;
            html += `<option value="${escapeHtml(name)}" ${selected ? 'selected' : ''}>${escapeHtml(name)}</option>`;
        });
        return html || '<option value="Piece">Piece</option>';
    }

    function getInvoiceUnitPrice(productId, unitName) {
        const product = inventory.find(p => Number(p.id) === Number(productId));
        if (!product) return 0;

        const units = productUnitTypes[productId] || [];
        const matched = units.find(ut => String(ut.unit_type_name || '').toLowerCase() === String(unitName || '').toLowerCase());
        if (matched) return parseFloat(matched.unit_price || 0) || 0;

        return parseFloat(product.unit_price || 0) || 0;
    }

    function getNextInvoiceRowIndex() {
        const rows = document.querySelectorAll('#invoiceItemsBody tr[data-invoice-row]');
        let maxIndex = -1;
        rows.forEach(row => {
            const index = parseInt(row.dataset.invoiceRow || '-1', 10);
            if (!isNaN(index) && index > maxIndex) maxIndex = index;
        });
        return maxIndex + 1;
    }

    function getInvoiceRowHtml(index) {
        return `
            <tr data-invoice-row="${index}">
                <td class="invoice-product-cell">
                    <div class="invoice-product-wrap">
                        <select class="invoice-item-select grouped-native-select" onchange="invoiceRowProductChanged(${index})">
                            ${getInvoiceProductOptions('')}
                        </select>
                        <span class="invoice-selected-category"></span>
                    </div>
                </td>
                <td><select class="invoice-unit-select" onchange="invoiceRowUnitChanged(${index})"><option value=""></option></select></td>
                <td><input type="number" class="invoice-qty" min="0" step="1" value="" oninput="invoiceRowQtyChanged(${index})"></td>
                <td><input type="text" inputmode="decimal" class="invoice-price" value="" data-min-price="0" oninput="invoiceRowPriceChanged(${index})" onblur="invoiceRowPriceBlur(${index})" autocomplete="off"></td>
                <td><input type="text" class="invoice-amount" readonly></td>
            </tr>
        `;
    }

    function appendInvoiceStyleRow() {
        const body = document.getElementById('invoiceItemsBody');
        if (!body) return;
        body.insertAdjacentHTML('beforeend', getInvoiceRowHtml(getNextInvoiceRowIndex()));
    }

    function ensureInvoiceStyleHasBlankRow() {
        const body = document.getElementById('invoiceItemsBody');
        if (!body) return;
        const rows = Array.from(body.querySelectorAll('tr[data-invoice-row]'));
        if (!rows.length) {
            appendInvoiceStyleRow();
            return;
        }

        const hasBlankRow = rows.some(row => {
            const productId = row.querySelector('.invoice-item-select')?.value || '';
            const qty = parseInt(row.querySelector('.invoice-qty')?.value || '0', 10) || 0;
            const unitName = row.querySelector('.invoice-unit-select')?.value || '';
            return !productId && qty <= 0 && !unitName;
        });

        if (!hasBlankRow) appendInvoiceStyleRow();
    }

    function buildInvoiceStyleRows() {
        const body = document.getElementById('invoiceItemsBody');
        if (!body || body.dataset.ready === '1') return;

        let rows = '';
        for (let i = 0; i < 10; i++) {
            rows += getInvoiceRowHtml(i);
        }
        body.innerHTML = rows;
        body.dataset.ready = '1';
    }

    window.invoiceRowProductChanged = function(index) {
        const row = document.querySelector(`[data-invoice-row="${index}"]`);
        if (!row) return;

        const productId = row.querySelector('.invoice-item-select')?.value || '';
        const unitSelect = row.querySelector('.invoice-unit-select');
        const qty = row.querySelector('.invoice-qty');
        const priceInput = row.querySelector('.invoice-price');
        const amount = row.querySelector('.invoice-amount');

        if (!productId) {
            setInvoiceRowCategory(row, '');
            if (unitSelect) unitSelect.innerHTML = '<option value=""></option>';
            if (qty) qty.value = '';
            if (priceInput) priceInput.value = '';
            if (amount) amount.value = '';
            syncInvoiceRowsToCart();
            ensureInvoiceStyleHasBlankRow();
            return;
        }

        setInvoiceRowCategory(row, productId);
        if (unitSelect) {
            unitSelect.innerHTML = getInvoiceUnitOptions(productId, '');
        }
        if (qty && !qty.value) qty.value = 1;
        if (priceInput) priceInput.value = '';

        invoiceRecomputeRow(index, true);
        ensureInvoiceStyleHasBlankRow();
    };

    window.invoiceRowUnitChanged = function(index) {
        const row = document.querySelector(`[data-invoice-row="${index}"]`);
        if (!row) return;
        const productId = row.querySelector('.invoice-item-select')?.value || '';
        setInvoiceRowCategory(row, productId);
        const unitName = row.querySelector('.invoice-unit-select')?.value || '';
        const priceInput = row.querySelector('.invoice-price');
        const defaultPrice = getInvoiceUnitPrice(productId, unitName);
        if (priceInput) {
            priceInput.dataset.minPrice = String(defaultPrice || 0);
            priceInput.value = defaultPrice > 0 ? defaultPrice.toFixed(2) : '';
            priceInput.classList.remove('is-invalid');
        }
        invoiceRecomputeRow(index, true);
        ensureInvoiceStyleHasBlankRow();
    };

    window.invoiceRowQtyChanged = function(index) {
        const row = document.querySelector(`[data-invoice-row="${index}"]`);
        const qty = row?.querySelector('.invoice-qty');
        if (qty) {
            let v = parseInt(qty.value || '0', 10);
            if (isNaN(v) || v < 0) v = 0;
            qty.value = v || '';
        }
        invoiceRecomputeRow(index, false);
        ensureInvoiceStyleHasBlankRow();
    };

    function sanitizeDecimalInputWithCaret(input) {
        if (!input) return '';

        const originalValue = String(input.value || '');
        const originalCaret = typeof input.selectionStart === 'number' ? input.selectionStart : originalValue.length;
        let cleaned = '';
        let dotSeen = false;
        let newCaret = 0;

        for (let i = 0; i < originalValue.length; i++) {
            const ch = originalValue.charAt(i);
            let keep = false;

            if (ch >= '0' && ch <= '9') {
                keep = true;
            } else if (ch === '.' && !dotSeen) {
                keep = true;
                dotSeen = true;
            }

            if (keep) cleaned += ch;
            if (i < originalCaret && keep) newCaret++;
        }

        if (input.value !== cleaned) {
            input.value = cleaned;
            requestAnimationFrame(() => {
                try {
                    input.setSelectionRange(newCaret, newCaret);
                } catch (e) {}
            });
        }

        return cleaned;
    }

    window.invoiceRowPriceChanged = function(index) {
        const row = document.querySelector(`[data-invoice-row="${index}"]`);
        const priceInput = row?.querySelector('.invoice-price');
        if (priceInput) {
            const rawValue = sanitizeDecimalInputWithCaret(priceInput);

            const productId = row?.querySelector('.invoice-item-select')?.value || '';
            const unitName = row?.querySelector('.invoice-unit-select')?.value || '';
            const minPrice = getInvoiceUnitPrice(productId, unitName);
            priceInput.dataset.minPrice = String(minPrice || 0);
            const typedPrice = parseFloat(rawValue);
            if (rawValue !== '' && rawValue !== '.' && !isNaN(typedPrice) && typedPrice + 0.009 < minPrice) {
                priceInput.classList.add('is-invalid');
            } else {
                priceInput.classList.remove('is-invalid');
            }
        }
        invoiceRecomputeRow(index, false);
        ensureInvoiceStyleHasBlankRow();
    };

    window.invoiceRowPriceBlur = function(index) {
        const row = document.querySelector(`[data-invoice-row="${index}"]`);
        const priceInput = row?.querySelector('.invoice-price');
        if (!priceInput) return;

        const productId = row?.querySelector('.invoice-item-select')?.value || '';
        const unitName = row?.querySelector('.invoice-unit-select')?.value || '';
        if (!productId || !unitName) return;

        const minPrice = getInvoiceUnitPrice(productId, unitName);
        priceInput.dataset.minPrice = String(minPrice || 0);
        const raw = String(priceInput.value || '').replace(/,/g, '');

        if (raw === '' || raw === '.') {
            priceInput.classList.remove('is-invalid');
            invoiceRecomputeRow(index, false);
            return;
        }

        const typedPrice = parseFloat(raw);
        if (isNaN(typedPrice) || typedPrice + 0.009 < minPrice) {
            priceInput.value = minPrice > 0 ? minPrice.toFixed(2) : '';
            priceInput.classList.remove('is-invalid');
            showToast(`Price cannot be lower than declared price ${formatCurrency(minPrice)}.`);
        } else {
            priceInput.value = typedPrice.toFixed(2);
            priceInput.classList.remove('is-invalid');
        }
        invoiceRecomputeRow(index, false);
    };

    function getInvoiceEditedPrice(row, productId, unitName) {
        const priceInput = row?.querySelector('.invoice-price');
        const raw = String(priceInput?.value || '').replace(/,/g, '');
        if (raw === '') return NaN;
        const editedPrice = parseFloat(raw);
        if (!isNaN(editedPrice) && editedPrice >= 0) return editedPrice;
        return NaN;
    }

    function invoiceRecomputeRow(index, resetPriceToDefault = false) {
        const row = document.querySelector(`[data-invoice-row="${index}"]`);
        if (!row) return;

        const productId = row.querySelector('.invoice-item-select')?.value || '';
        const unitName = row.querySelector('.invoice-unit-select')?.value || '';
        const qty = parseInt(row.querySelector('.invoice-qty')?.value || '0', 10) || 0;
        const priceInput = row.querySelector('.invoice-price');
        const amountInput = row.querySelector('.invoice-amount');

        if (!productId || !unitName) {
            if (amountInput) amountInput.value = '';
            syncInvoiceRowsToCart();
            return;
        }

        const defaultPrice = getInvoiceUnitPrice(productId, unitName);
        if (priceInput) {
            priceInput.dataset.minPrice = String(defaultPrice || 0);
            if (resetPriceToDefault) {
                priceInput.value = defaultPrice > 0 ? defaultPrice.toFixed(2) : '';
                priceInput.classList.remove('is-invalid');
            }
        }

        const price = getInvoiceEditedPrice(row, productId, unitName);
        if (isNaN(price)) {
            if (amountInput) amountInput.value = '';
            syncInvoiceRowsToCart();
            return;
        }

        if (price + 0.009 < defaultPrice) {
            if (priceInput) priceInput.classList.add('is-invalid');
            if (amountInput) amountInput.value = '';
            syncInvoiceRowsToCart();
            return;
        }

        const amount = qty * price;
        if (amountInput) amountInput.value = amount > 0 ? amount.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) : '';

        syncInvoiceRowsToCart();
    }

    window.syncInvoiceRowsToCart = function() {
        const rows = document.querySelectorAll('#invoiceItemsBody tr[data-invoice-row]');
        const invoiceItems = [];

        rows.forEach(row => {
            const productId = row.querySelector('.invoice-item-select')?.value || '';
            const qty = parseInt(row.querySelector('.invoice-qty')?.value || '0', 10) || 0;
            const unitName = row.querySelector('.invoice-unit-select')?.value || '';
            if (!productId || qty <= 0 || !unitName) return;

            const product = inventory.find(p => Number(p.id) === Number(productId));
            if (!product) return;

            const price = getInvoiceEditedPrice(row, productId, unitName);
            const minPrice = getInvoiceUnitPrice(productId, unitName);
            const priceInput = row.querySelector('.invoice-price');
            if (isNaN(price)) return;
            if (price + 0.009 < minPrice) {
                if (priceInput) priceInput.classList.add('is-invalid');
                return;
            }
            if (priceInput) priceInput.classList.remove('is-invalid');
            invoiceItems.push({
                id: Number(product.id),
                name: product.name,
                price: price,
                quantity: qty,
                sku: product.sku,
                unit_type: unitName
            });
        });

        cart = invoiceItems;
        updateCartBadge();
        updateInvoiceTotals();
    };

    function updateInvoiceTotals() {
        const subtotal = getCartSubtotal();
        const discount = computeCartDiscount(subtotal);
        const discountLabel = document.getElementById('invoiceDiscountLabel');
        const discountAmount = document.getElementById('invoiceDiscountAmount');
        const totalAmount = document.getElementById('invoiceTotalAmount');
        const balanceDue = document.getElementById('invoiceBalanceDue');

        if (discountLabel) discountLabel.textContent = discount.note ? `(${discount.note})` : '(0.0%)';
        if (discountAmount) discountAmount.textContent = discount.amount > 0 ? discount.amount.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) : '0.00';
        if (totalAmount) totalAmount.textContent = discount.total.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        if (balanceDue) balanceDue.textContent = discount.total.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        if (typeof toggleBillingTypeFields === 'function') toggleBillingTypeFields();
        if (typeof updateInvoicePaymentVisibility === 'function') updateInvoicePaymentVisibility();
    }

    function syncInvoiceCustomerOptions() {
        const source = document.getElementById('modalCustomerSelect');
        const target = document.getElementById('invoiceCustomerSelect');
        if (!source || !target) return;

        const isLocked = <?php echo $is_customer_locked ? 'true' : 'false'; ?>;
        const lockedCustomerId = document.getElementById('lockedCustomerId')?.value || '';
        const lockedCustomerName = document.getElementById('lockedCustomerName')?.value || '';

        if (!target.dataset.loaded) {
            target.innerHTML = source.innerHTML;
            target.dataset.loaded = '1';

            if (isLocked && lockedCustomerId) {
                target.value = lockedCustomerId;
                target.disabled = true;
                target.classList.add('locked-customer-select');
            } else {
                target.value = source.value || '';
                target.disabled = false;
                target.addEventListener('change', function() {
                    source.value = this.value;
                    source.dispatchEvent(new Event('change', { bubbles: true }));
                    if (this.value) {
                        loadCustomerDiscount(this.value);
                        loadCustomerOutstandingSnapshot(this.value);
                    }
                    const selectedDisplay = document.getElementById('selectedCustomerNameDisplay');
                    if (selectedDisplay) {
                        const opt = this.options[this.selectedIndex];
                        selectedDisplay.textContent = opt && opt.value ? opt.text.trim() : 'No customer selected';
                    }
                });
            }
        }

        if (isLocked && lockedCustomerId) {
            target.value = lockedCustomerId;
            target.disabled = true;
            if (source.value !== lockedCustomerId) {
                source.value = lockedCustomerId;
            }
            const selectedDisplay = document.getElementById('selectedCustomerNameDisplay');
            if (selectedDisplay) selectedDisplay.textContent = lockedCustomerName || 'No customer selected';
            return;
        }

        if (source.value && target.value !== source.value) {
            target.value = source.value;
        }
    }

    function updateInvoiceDeliveryFieldVisibility() {
        const selectedType = getInvoiceDeliveryTypeValue();
        const isCredit = isCreditOrderSelected();
        const isDelivery = !isCredit && selectedType === 'delivery';

        document.querySelectorAll('.invoice-delivery-field').forEach(field => {
            field.style.display = isDelivery ? '' : 'none';
        });
        document.querySelectorAll('.invoice-atw-field, .invoice-gatepass-field, .invoice-fulfillment-field').forEach(field => {
            field.style.display = isCredit ? 'none' : '';
        });

        if (!isDelivery) {
            const invoiceDriver = document.getElementById('invoiceDriverSelect');
            const invoiceVehicle = document.getElementById('invoiceVehicleSelect');
            const originalDriver = document.getElementById('deliveryDriverSelect');
            const originalVehicle = document.getElementById('deliveryVehicleSelect');

            if (invoiceDriver) invoiceDriver.value = '';
            if (invoiceVehicle) invoiceVehicle.value = '';
            if (originalDriver) originalDriver.value = '';
            if (originalVehicle) originalVehicle.value = '';
        }
    }

    function syncInvoiceDeliveryTypeToOriginal() {
        const selectedType = getInvoiceDeliveryTypeValue();
        setDeliveryTypeValue(selectedType);

        if (typeof refreshDeliveryTypeDependentFields === 'function') {
            refreshDeliveryTypeDependentFields();
        }
    }

    function syncOriginalDeliveryTypeToInvoice() {
        const selectedOriginal = getDeliveryTypeValue();
        setInvoiceDeliveryTypeValue(selectedOriginal);
        updateInvoiceDeliveryFieldVisibility();
    }

    function syncInvoiceFieldsToOriginalForm() {
        const customerSelect = document.getElementById('invoiceCustomerSelect');
        const modalCustomer = document.getElementById('modalCustomerSelect');
        const isLocked = <?php echo $is_customer_locked ? 'true' : 'false'; ?>;
        const lockedCustomerId = document.getElementById('lockedCustomerId')?.value || '';
        if (customerSelect && modalCustomer) {
            if (isLocked && lockedCustomerId) {
                customerSelect.value = lockedCustomerId;
                customerSelect.disabled = true;
                modalCustomer.value = lockedCustomerId;
            } else {
                modalCustomer.value = customerSelect.value;
                modalCustomer.dispatchEvent(new Event('change', { bubbles: true }));
            }
        }

        const isCredit = isCreditOrderSelected();
        const atw = isCredit ? '' : sanitizeDigits(document.getElementById('invoiceAtwNo')?.value || '', 6);
        const gatepass = isCredit ? '' : sanitizeDigits(document.getElementById('invoiceGatepassNo')?.value || '', 6);

        const originalAtw = document.getElementById('atwNo');
        const originalGatepass = document.getElementById('gatepassNo');
        if (originalAtw) originalAtw.value = atw;
        if (originalGatepass) originalGatepass.value = gatepass;

        const invoiceNo = document.getElementById('invoiceNumberVisual');
        if (invoiceNo) invoiceNo.value = sanitizeDigits(invoiceNo.value || '', 6);

        syncInvoiceDeliveryTypeToOriginal();
        updateInvoiceDeliveryFieldVisibility();

        const selectedType = getInvoiceDeliveryTypeValue();
        const invoiceDriver = document.getElementById('invoiceDriverSelect');
        const invoiceVehicle = document.getElementById('invoiceVehicleSelect');
        const originalDriver = document.getElementById('deliveryDriverSelect');
        const originalVehicle = document.getElementById('deliveryVehicleSelect');

        if (selectedType === 'delivery') {
            if (invoiceDriver && originalDriver) originalDriver.value = invoiceDriver.value || '';
            if (invoiceVehicle && originalVehicle) originalVehicle.value = invoiceVehicle.value || '';
        } else {
            if (invoiceDriver) invoiceDriver.value = '';
            if (invoiceVehicle) invoiceVehicle.value = '';
            if (originalDriver) originalDriver.value = '';
            if (originalVehicle) originalVehicle.value = '';
        }

        syncInvoicePaymentToOriginalForm();
        syncInvoiceRowsToCart();
    }

    function validateInvoiceBeforeSubmit() {
        syncInvoiceFieldsToOriginalForm();

        if (!cart.length) {
            showToast('Please add at least one item.');
            return false;
        }

        const customerId = document.getElementById('invoiceCustomerSelect')?.value || document.getElementById('modalCustomerSelect')?.value || '';
        if (!customerId) {
            showToast('Please select a customer.');
            document.getElementById('invoiceCustomerSelect')?.focus();
            return false;
        }

        const isCredit = isCreditOrderSelected();
        const atw = document.getElementById('atwNo')?.value || '';
        const gatepass = document.getElementById('gatepassNo')?.value || '';
        const documentNumberPattern = /^\d{1,6}$/;
        if (!isCredit && !gatepass) {
            showToast('Please enter Gatepass No.');
            document.getElementById('invoiceGatepassNo')?.focus();
            return false;
        }
        if (!isCredit && ((atw && !documentNumberPattern.test(atw)) || !documentNumberPattern.test(gatepass))) {
            showToast('Please enter valid ATW No. and Gatepass No. numbers.');
            if (atw && !documentNumberPattern.test(atw)) document.getElementById('invoiceAtwNo')?.focus();
            else document.getElementById('invoiceGatepassNo')?.focus();
            return false;
        }

        return true;
    }

    window.invoiceSaveAndClose = function() {
        if (!validateInvoiceBeforeSubmit()) return;
        window.invoiceOrderSubmitMode = 'close';
        submitOrder();
    };

    window.invoiceSaveAndNew = function() {
        if (!validateInvoiceBeforeSubmit()) return;
        window.invoiceOrderSubmitMode = 'new';
        submitOrder();
    };

    function resetInvoiceStyleForNextOrder() {
        const isLocked = <?php echo $is_customer_locked ? 'true' : 'false'; ?>;
        const lockedCustomerId = document.getElementById('lockedCustomerId')?.value || '';

        const body = document.getElementById('invoiceItemsBody');
        if (body) {
            let rowsHtml = '';
            for (let i = 0; i < 10; i++) {
                rowsHtml += getInvoiceRowHtml(i);
            }
            body.innerHTML = rowsHtml;
            body.dataset.ready = '1';
        }

        cart = [];
        customerDiscount = { percent: 0, type: 'percentage', basedAmount: 0, calculatedAmount: 0 };

        const invoiceCustomer = document.getElementById('invoiceCustomerSelect');
        const modalCustomer = document.getElementById('modalCustomerSelect');
        if (isLocked && lockedCustomerId) {
            if (invoiceCustomer) {
                invoiceCustomer.value = lockedCustomerId;
                invoiceCustomer.disabled = true;
            }
            if (modalCustomer) modalCustomer.value = lockedCustomerId;
            loadCustomerDiscount(lockedCustomerId);
            loadCustomerOutstandingSnapshot(lockedCustomerId);
        } else {
            if (invoiceCustomer) {
                invoiceCustomer.value = '';
                invoiceCustomer.disabled = false;
            }
            if (modalCustomer) modalCustomer.value = '';
            const selectedDisplay = document.getElementById('selectedCustomerNameDisplay');
            if (selectedDisplay) selectedDisplay.textContent = 'No customer selected';
        }

        const today = todayYmd();
        const invoiceDate = document.getElementById('invoiceOrderDate');
        const invoiceDueDate = document.getElementById('invoiceDueDate');
        if (invoiceDate) invoiceDate.value = today;
        if (invoiceDueDate) invoiceDueDate.value = today;

        ['invoiceNumberVisual', 'invoiceAtwNo', 'invoiceGatepassNo', 'atwNo', 'gatepassNo', 'siNumber', 'registeredBusinessName', 'businessTin', 'businessAddress'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.value = '';
        });

        const terms = document.getElementById('invoiceTerms');
        if (terms) terms.value = '';

        ['invoiceRecurringEnabled', 'orderRecurringEnabled'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.checked = false;
        });
        ['invoiceRecurringEvery', 'orderRecurringEvery'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.value = '1';
        });
        ['invoiceRecurringPeriod', 'orderRecurringPeriod'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.value = 'month';
        });
        ['invoiceRecurringUntil', 'orderRecurringUntil'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.value = '';
        });
        ['invoiceRecurringFields', 'orderRecurringFields'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.hidden = true;
        });
        toggleOrderRecurring('invoice');
        toggleOrderRecurring('order');

        setBillingTypeValue('invoice');
        const soRadio = document.querySelector('input[name="documentType"][value="SO"]');
        if (soRadio) soRadio.checked = true;
        if (typeof toggleSIDetails === 'function') toggleSIDetails();

        setInvoiceDeliveryTypeValue('pickup');
        setDeliveryTypeValue('pickup');
        ['invoiceDriverSelect', 'invoiceVehicleSelect', 'deliveryDriverSelect', 'deliveryVehicleSelect'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.value = '';
        });
        updateInvoiceDeliveryFieldVisibility();
        if (typeof refreshDeliveryTypeDependentFields === 'function') refreshDeliveryTypeDependentFields();

        const invoiceCollect = document.getElementById('invoiceCollectPayment');
        const pickupCollect = document.getElementById('collectPickupPayment');
        if (invoiceCollect) invoiceCollect.checked = false;
        if (pickupCollect) pickupCollect.checked = false;

        const invoiceMethod = document.getElementById('invoicePaymentMethod');
        const pickupMethod = document.getElementById('pickupPaymentMethod');
        if (invoiceMethod) invoiceMethod.value = 'cash';
        if (pickupMethod) pickupMethod.value = 'cash';
        [
            'invoiceCashTendered', 'pickupCashTendered',
            'invoiceCheckDate', 'invoiceCheckNumber', 'invoiceCheckPaymentAmount', 'invoiceBankBranch',
            'invoiceReferenceNumber', 'invoiceOnlineBankName', 'invoiceOnlinePaymentAmount', 'invoiceOnlineBankBranch',
            'pickupCheckDate', 'pickupCheckNumber', 'pickupCheckPaymentAmount', 'pickupBankBranch',
            'pickupReferenceNumber', 'pickupOnlineBankName', 'pickupOnlinePaymentAmount', 'pickupOnlineBankBranch',
            'productSearch', 'opSoSearch'
        ].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.value = '';
        });
        const searchReset = document.getElementById('searchReset');
        if (searchReset) searchReset.style.display = 'none';
        const cashChange = document.getElementById('invoiceCashChange');
        if (cashChange) cashChange.textContent = '₱0.00';

        const reviewItems = document.getElementById('reviewItems');
        if (reviewItems) reviewItems.innerHTML = '<p class="text-muted text-center">No items in cart</p>';
        ['reviewCustomer', 'reviewEmail', 'reviewPhone', 'reviewAddress'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.textContent = '-';
        });

        updateCartBadge();
        updateInvoiceTotals();
        if (typeof updateReviewTotals === 'function') updateReviewTotals();
        if (typeof toggleBillingTypeFields === 'function') toggleBillingTypeFields();
        if (typeof updateInvoicePaymentVisibility === 'function') updateInvoicePaymentVisibility();
        if (typeof updatePickupPaymentVisibility === 'function') updatePickupPaymentVisibility();
        if (typeof renderProducts === 'function') renderProducts();
    }

    window.clearOrderProductFieldsAfterSubmit = function() {
        try {
            if (typeof resetInvoiceStyleForNextOrder === 'function') {
                resetInvoiceStyleForNextOrder();
            }

            document.querySelectorAll('.modal.show').forEach(modalEl => {
                const modalInstance = bootstrap.Modal.getInstance(modalEl);
                if (modalInstance) modalInstance.hide();
            });
            document.querySelectorAll('.modal-backdrop').forEach(backdrop => backdrop.remove());
            document.body.classList.remove('modal-open');
            document.body.style.removeProperty('overflow');
            document.body.style.removeProperty('padding-right');

            cart = [];
            customerDiscount = { percent: 0, type: 'percentage', basedAmount: 0, calculatedAmount: 0 };
            customerCreditSnapshot = { hasCreditLimit: false, creditLimit: 0, outstandingBalance: 0, orderAmount: 0, requiresOutstandingApproval: false };

            const reviewItems = document.getElementById('reviewItems');
            if (reviewItems) reviewItems.innerHTML = '<p class="text-muted text-center">No items in cart</p>';
            ['reviewCustomer', 'reviewEmail', 'reviewPhone', 'reviewAddress'].forEach(id => {
                const el = document.getElementById(id);
                if (el) el.textContent = '-';
            });

            const selectedDisplay = document.getElementById('selectedCustomerNameDisplay');
            if (selectedDisplay) selectedDisplay.textContent = 'No customer selected';

            ['modalCustomerSelect', 'invoiceCustomerSelect', 'deliveryDriverSelect', 'deliveryVehicleSelect', 'invoiceDriverSelect', 'invoiceVehicleSelect'].forEach(id => {
                const el = document.getElementById(id);
                if (el && !el.disabled) el.value = '';
            });

            ['siAttachmentInput', 'si_attachments'].forEach(id => {
                const fileInput = document.getElementById(id);
                if (fileInput) fileInput.value = '';
            });

            if (typeof updateCartBadge === 'function') updateCartBadge();
            if (typeof updateReviewTotals === 'function') updateReviewTotals();
            if (typeof updateInvoiceTotals === 'function') updateInvoiceTotals();
            if (typeof updateOutstandingBalanceDisplay === 'function') updateOutstandingBalanceDisplay();
            if (typeof updateInvoicePaymentVisibility === 'function') updateInvoicePaymentVisibility();
            if (typeof updatePickupPaymentVisibility === 'function') updatePickupPaymentVisibility();
            if (typeof renderProducts === 'function') renderProducts();
        } catch (resetError) {
            console.warn('Unable to clear Order Product fields after submit:', resetError);
        }
    };

    window.clearInvoiceStyleRows = function() {
        resetInvoiceStyleForNextOrder();
    };

    window.resetOrderFormForNewInvoice = function() {
        resetInvoiceStyleForNextOrder();
    };

    document.addEventListener('DOMContentLoaded', function() {
        const dateInput = document.getElementById('invoiceOrderDate');
        const dueInput = document.getElementById('invoiceDueDate');
        if (dateInput && !dateInput.value) dateInput.value = todayYmd();
        if (dueInput && !dueInput.value) dueInput.value = todayYmd();

        ['invoiceAtwNo', 'invoiceGatepassNo', 'invoiceNumberVisual'].forEach(id => {
            const el = document.getElementById(id);
            if (!el) return;
            el.addEventListener('input', function() {
                this.value = sanitizeDigits(this.value, 6);
                syncInvoiceFieldsToOriginalForm();
            });
        });

        ['invoiceDriverSelect', 'invoiceVehicleSelect'].forEach(id => {
            const el = document.getElementById(id);
            if (!el) return;
            el.addEventListener('change', function() {
                syncInvoiceFieldsToOriginalForm();
            });
        });

        const invoiceCreditCheckbox = document.getElementById('invoiceCreditCheckbox');
        if (invoiceCreditCheckbox) {
            invoiceCreditCheckbox.addEventListener('change', function() {
                setBillingTypeValue(this.checked ? 'credit' : 'invoice');
                syncInvoiceFieldsToOriginalForm();
                toggleBillingTypeFields();
                updateInvoiceDocumentTitle();
            });
        }

        document.querySelectorAll('input[name="billingType"]').forEach(el => {
            el.addEventListener('change', function() {
                if (this.checked) {
                    setBillingTypeValue(this.value);
                    syncInvoiceFieldsToOriginalForm();
                    toggleBillingTypeFields();
                    updateInvoiceDocumentTitle();
                }
            });
        });

        const invoiceDeliveryTypeSelect = document.getElementById('invoiceDeliveryType');
        if (invoiceDeliveryTypeSelect) {
            invoiceDeliveryTypeSelect.addEventListener('change', function() {
                setInvoiceDeliveryTypeValue(this.value);
                updateInvoiceDeliveryFieldVisibility();
                syncInvoiceFieldsToOriginalForm();
            });
        }

        document.querySelectorAll('input[name="invoiceDeliveryType"]').forEach(el => {
            el.addEventListener('change', function() {
                if (this.checked) {
                    setInvoiceDeliveryTypeValue(this.value);
                    updateInvoiceDeliveryFieldVisibility();
                    syncInvoiceFieldsToOriginalForm();
                }
            });
        });

        const originalDeliveryTypeSelect = document.getElementById('deliveryType');
        if (originalDeliveryTypeSelect) {
            originalDeliveryTypeSelect.addEventListener('change', function() {
                setDeliveryTypeValue(this.value);
                refreshDeliveryTypeDependentFields();
                syncOriginalDeliveryTypeToInvoice();
            });
        }

        document.querySelectorAll('input[name="deliveryType"]').forEach(el => {
            el.addEventListener('change', function() {
                if (this.checked) {
                    setDeliveryTypeValue(this.value);
                    refreshDeliveryTypeDependentFields();
                    syncOriginalDeliveryTypeToInvoice();
                }
            });
        });

        syncOriginalDeliveryTypeToInvoice();

        setTimeout(function() {
            syncOriginalDeliveryTypeToInvoice();
            applyOrderProductStyle(getOrderProductStyle());
        }, 250);
    });

    document.addEventListener('customerDiscountLoaded', updateInvoiceTotals);
})();

</script>


<!-- AMGC FORCE SCROLL FIX - DEFAULT/CLASSIC ORDER PRODUCT -->
<style id="amgc-force-scroll-fix">
/*
   Final scroll fix:
   The page previously had mixed rules: body hidden, main-content visible,
   and invoice area with fixed heights. This makes the main content the only
   scrolling container so the bottom buttons/totals will always be reachable.
*/
html,
body {
    width: 100% !important;
    height: 100% !important;
    min-height: 100% !important;
    max-height: 100% !important;
    overflow: hidden !important;
    margin: 0 !important;
    padding: 0 !important;
}

#appPage {
    width: 100% !important;
    height: 100dvh !important;
    min-height: 100dvh !important;
    max-height: 100dvh !important;
    overflow: hidden !important;
    display: block !important;
    position: relative !important;
}

.sidebar {
    position: fixed !important;
    top: 0 !important;
    left: 0 !important;
    bottom: 0 !important;
    height: 100dvh !important;
    max-height: 100dvh !important;
    overflow: hidden !important;
    z-index: 1000 !important;
}

.sidebar-content {
    overflow-y: auto !important;
    overflow-x: hidden !important;
    min-height: 0 !important;
}

.main-content {
    position: fixed !important;
    top: 0 !important;
    right: 0 !important;
    bottom: 0 !important;
    left: 250px !important;
    width: auto !important;
    height: auto !important;
    min-height: 0 !important;
    max-height: none !important;
    margin-left: 0 !important;
    overflow-y: auto !important;
    overflow-x: hidden !important;
    display: block !important;
    padding: 18px 18px 230px 18px !important;
    -webkit-overflow-scrolling: touch !important;
    overscroll-behavior: contain !important;
    background: #f5f5f5 !important;
}

.sidebar.collapsed ~ .main-content {
    left: 80px !important;
    margin-left: 0 !important;
}

.navbar-top {
    position: relative !important;
    z-index: 2000 !important;
    overflow: visible !important;
    margin: 0 0 16px 0 !important;
    flex-shrink: 0 !important;
}

.order-style-navbar-dropdown,
.order-style-navbar-dropdown .dropdown,
.order-style-navbar-dropdown .dropdown-menu,
.order-style-menu {
    overflow: visible !important;
    z-index: 999999 !important;
}

.order-style-navbar-dropdown .dropdown-menu,
.order-style-menu {
    position: absolute !important;
    top: calc(100% + 8px) !important;
    left: 0 !important;
    right: auto !important;
    transform: none !important;
    min-width: 190px !important;
    background: #ffffff !important;
    border: 1px solid #d7dce2 !important;
    box-shadow: 0 12px 28px rgba(5, 42, 71, 0.18) !important;
}

body.order-product-invoice-style .classic-cart-btn {
    display: none !important;
}

body.order-product-classic-style .classic-cart-btn {
    display: inline-flex !important;
}

body.order-product-invoice-style .invoice-style-workspace {
    display: block !important;
    width: 100% !important;
    max-width: 100% !important;
    height: auto !important;
    min-height: auto !important;
    max-height: none !important;
    overflow: visible !important;
    margin-bottom: 50px !important;
}

body.order-product-invoice-style .invoice-sheet {
    height: auto !important;
    min-height: 760px !important;
    max-height: none !important;
    overflow: visible !important;
    padding-bottom: 80px !important;
}

body.order-product-invoice-style .invoice-table-wrap {
    max-height: none !important;
    overflow-y: visible !important;
    overflow-x: auto !important;
}

body.order-product-invoice-style .invoice-bottom-area,
body.order-product-invoice-style .invoice-actions,
body.order-product-invoice-style .invoice-totals {
    position: relative !important;
    z-index: 1 !important;
}

body.order-product-classic-style .products-section {
    flex: 1 1 auto !important;
    height: auto !important;
    min-height: 0 !important;
    overflow: hidden !important;
    display: flex !important;
    flex-direction: column !important;
}

body.order-product-classic-style .product-table-container {
    flex: 1 1 auto !important;
    min-height: 0 !important;
    max-height: calc(100dvh - 275px) !important;
    overflow-y: auto !important;
    overflow-x: auto !important;
    padding-bottom: 40px !important;
    -webkit-overflow-scrolling: touch !important;
}

@media (max-width: 992px) {
    .sidebar {
        transform: translateX(-100%);
    }
    .sidebar.active {
        transform: translateX(0);
    }
    .main-content,
    .sidebar.collapsed ~ .main-content {
        left: 0 !important;
        padding: 0 12px 240px 12px !important;
    }
}

@media (max-width: 768px) {
    .invoice-blue-strip,
    .invoice-sheet-head,
    .invoice-bottom-area {
        grid-template-columns: 1fr !important;
        gap: 14px !important;
    }
    .invoice-right-fields {
        grid-template-columns: repeat(2, minmax(130px, 1fr)) !important;
        justify-content: stretch !important;
    }
    body.order-product-invoice-style .invoice-sheet {
        min-height: 920px !important;
        padding-bottom: 120px !important;
    }
}
</style>

<script id="amgc-force-scroll-script">
document.addEventListener('DOMContentLoaded', function () {
    const mainContent = document.querySelector('.main-content');
    const sidebar = document.getElementById('sidebar');

    function forceScrollableLayout() {
        if (!mainContent) return;
        mainContent.style.position = 'fixed';
        mainContent.style.top = '0';
        mainContent.style.right = '0';
        mainContent.style.bottom = '0';
        mainContent.style.height = 'auto';
        mainContent.style.maxHeight = 'none';
        mainContent.style.overflowY = 'auto';
        mainContent.style.overflowX = 'hidden';
        mainContent.style.marginLeft = '0';

        if (window.innerWidth <= 992) {
            mainContent.style.left = '0';
        } else if (sidebar && sidebar.classList.contains('collapsed')) {
            mainContent.style.left = '80px';
        } else {
            mainContent.style.left = '250px';
        }
    }

    forceScrollableLayout();
    window.addEventListener('resize', forceScrollableLayout);

    const desktopToggle = document.getElementById('desktopToggleBtn');
    if (desktopToggle) {
        desktopToggle.addEventListener('click', function () {
            setTimeout(forceScrollableLayout, 80);
            setTimeout(forceScrollableLayout, 250);
        });
    }

    const styleButtons = document.querySelectorAll('#invoiceStyleBtn, #classicStyleBtn');
    styleButtons.forEach(function(btn) {
        btn.addEventListener('click', function() {
            setTimeout(function() {
                forceScrollableLayout();
                if (mainContent) mainContent.scrollTop = 0;
            }, 80);
        });
    });
});
</script>

<style id="amgc-default-invoice-delivery-type-fix">
/* Default Invoice Style: Pick Up / Delivery with conditional Driver and Vehicle fields */
body.order-product-invoice-style .invoice-right-fields {
    grid-template-columns: repeat(5, minmax(92px, 1fr)) !important;
    gap: 18px 20px !important;
}

body.order-product-invoice-style .invoice-fulfillment-field {
    min-width: 190px;
}

body.order-product-invoice-style .invoice-delivery-type-options {
    display: flex;
    gap: 10px;
    align-items: center;
    height: 32px;
}

body.order-product-invoice-style .invoice-delivery-type-option {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    margin: 0;
    font-size: 0.86rem;
    font-weight: 600;
    color: #052A47;
    cursor: pointer;
    white-space: nowrap;
}

body.order-product-invoice-style .invoice-delivery-type-option input {
    width: 16px !important;
    height: 16px !important;
    margin: 0 !important;
    accent-color: #2563eb;
}

body.order-product-invoice-style .invoice-delivery-field[style*="display: none"] {
    display: none !important;
}

@media (max-width: 1200px) {
    body.order-product-invoice-style .invoice-sheet-head {
        grid-template-columns: 1fr minmax(620px, 1fr) !important;
    }
    body.order-product-invoice-style .invoice-right-fields {
        grid-template-columns: repeat(4, minmax(150px, 1fr)) !important;
    }
    body.order-product-invoice-style .invoice-fulfillment-field {
        grid-column: span 2;
    }
}

@media (max-width: 768px) {
    body.order-product-invoice-style .invoice-right-fields {
        grid-template-columns: repeat(2, minmax(130px, 1fr)) !important;
    }
    body.order-product-invoice-style .invoice-fulfillment-field {
        grid-column: span 2;
    }
}
</style>



<!-- AMGC FINAL NAVBAR TOP GAP FIX -->
<style id="amgc-navbar-top-gap-final-fix">
/* This is placed last so it overrides the force-scroll layout. */
.main-content {
    padding-top: 18px !important;
}

body.order-product-invoice-style .main-content,
body.order-product-classic-style .main-content {
    padding-top: 18px !important;
}

.navbar-top {
    margin-top: 0 !important;
    margin-bottom: 16px !important;
}

@media (max-width: 992px) {
    .main-content,
    body.order-product-invoice-style .main-content,
    body.order-product-classic-style .main-content {
        padding-top: 14px !important;
    }
}
</style>


<!-- AMGC CLASSIC TABLE SCROLL RESTORE FIX -->
<style id="amgc-final-classic-style-scroll-fix">
/* Classic Style scroll fix
   Keeps navbar, tabs, category bar, and action bar visible while the product table/content scrolls correctly. */
body.order-product-classic-style,
body.order-product-classic-style #appPage {
    height: 100dvh !important;
    max-height: 100dvh !important;
    overflow: hidden !important;
}

body.order-product-classic-style .main-content {
    position: fixed !important;
    top: 0 !important;
    right: 0 !important;
    bottom: 0 !important;
    left: 250px !important;
    width: auto !important;
    height: auto !important;
    max-height: none !important;
    min-height: 0 !important;
    display: flex !important;
    flex-direction: column !important;
    overflow: hidden !important;
    padding: 18px 18px 18px 18px !important;
    margin-left: 0 !important;
}

.sidebar.collapsed ~ .main-content {
    left: 80px !important;
}

body.order-product-classic-style .navbar-top,
body.order-product-classic-style .op-main-tabs-wrap,
body.order-product-classic-style .category-tabs-container,
body.order-product-classic-style .product-action-bar {
    flex: 0 0 auto !important;
}

body.order-product-classic-style #createOrderTabContent.order-main-tab-pane.active {
    display: flex !important;
    flex-direction: column !important;
    flex: 1 1 auto !important;
    min-height: 0 !important;
    overflow: hidden !important;
}

body.order-product-classic-style #salesOrderTabContent.order-main-tab-pane.active {
    display: flex !important;
    flex-direction: column !important;
    flex: 1 1 auto !important;
    min-height: 0 !important;
    overflow: auto !important;
    padding-bottom: 35px !important;
    -webkit-overflow-scrolling: touch !important;
}

body.order-product-classic-style .invoice-style-workspace {
    display: none !important;
}

body.order-product-classic-style .category-tabs-container {
    display: block !important;
}

body.order-product-classic-style .products-section {
    flex: 1 1 auto !important;
    min-height: 0 !important;
    max-height: none !important;
    overflow: hidden !important;
    display: flex !important;
    flex-direction: column !important;
}

body.order-product-classic-style .product-table-container {
    flex: 1 1 auto !important;
    min-height: 0 !important;
    max-height: none !important;
    height: auto !important;
    overflow-y: auto !important;
    overflow-x: auto !important;
    padding-bottom: 45px !important;
    -webkit-overflow-scrolling: touch !important;
    overscroll-behavior: contain !important;
}

body.order-product-classic-style .product-table thead {
    position: sticky !important;
    top: 0 !important;
    z-index: 25 !important;
}

@media (max-width: 992px) {
    body.order-product-classic-style .main-content,
    .sidebar.collapsed ~ .main-content {
        left: 0 !important;
        padding: 14px 12px 95px 12px !important;
    }

    body.order-product-classic-style .product-table-container {
        padding-bottom: 90px !important;
    }
}
</style>

<script id="op-main-tabs-script">
function switchOrderProductMainTab(tabId){
    document.querySelectorAll('.order-main-tab-pane').forEach(function(pane){pane.classList.remove('active');});
    document.querySelectorAll('.op-main-tab-btn').forEach(function(btn){btn.classList.remove('active');});
    var pane=document.getElementById(tabId); if(pane) pane.classList.add('active');
    var btn=document.querySelector('.op-main-tab-btn[data-tab="'+tabId+'"]'); if(btn) btn.classList.add('active');
    var title=document.querySelector('.navbar-top .page-title h2');
    var sub=document.querySelector('.navbar-top .page-title p');
    if(tabId==='salesOrderTabContent'){
        if(title) title.textContent='Sales Orders';
        if(sub) sub.textContent='Manage and track all sales orders';
        filterEmbeddedSalesOrders();
    } else {
        if(title) title.textContent='Order Products';
        if(sub) sub.textContent='Select products and quantities to create an order';
    }
}
function toggleOpSalesOrderFilters(){
    var card=document.getElementById('opSoFilterCard');
    var body=document.getElementById('opSoFilterBody');
    if(!card || !body) return;
    var collapsed=card.classList.toggle('collapsed');
    body.style.display=collapsed?'none':'';
}
function setEmbeddedSalesOrderWeekFilter(){
    var start=document.getElementById('opSoStartDate');
    var end=document.getElementById('opSoEndDate');
    var now=new Date();
    var day=now.getDay();
    var diffToMonday=(day===0?-6:1-day);
    var monday=new Date(now);
    monday.setDate(now.getDate()+diffToMonday);
    var sunday=new Date(monday);
    sunday.setDate(monday.getDate()+6);
    function fmt(d){
        var m=String(d.getMonth()+1).padStart(2,'0');
        var dd=String(d.getDate()).padStart(2,'0');
        return d.getFullYear()+'-'+m+'-'+dd;
    }
    if(start) start.value=fmt(monday);
    if(end) end.value=fmt(sunday);
    filterEmbeddedSalesOrders();
}

function openSalesOrderRowDetails(event, orderId){
    if(event && event.target && event.target.closest('button, a, input, select, textarea, .btn, .dropdown-menu')) return;
    viewOrder(orderId);
}

var opSoCurrentPage = 1;
var opSoTotalPages = 1;
var opSoPageLength = 10;

function getEmbeddedSalesOrderPageLength(){
    var select = document.getElementById('opSoPageLength');
    var value = parseInt(select?.value || '10', 10);
    if (![10, 20, 50, 100].includes(value)) value = 10;
    opSoPageLength = value;
    return value;
}

function changeEmbeddedSalesOrderPageLength(value){
    opSoPageLength = parseInt(value || '10', 10);
    if (![10, 20, 50, 100].includes(opSoPageLength)) opSoPageLength = 10;
    opSoCurrentPage = 1;
    filterEmbeddedSalesOrders(false);
}

function setEmbeddedSalesOrderPage(page){
    page = parseInt(page || 1, 10);
    if (page < 1) page = 1;
    if (page > opSoTotalPages) page = opSoTotalPages;
    opSoCurrentPage = page;
    filterEmbeddedSalesOrders(false);
}

function filterEmbeddedSalesOrders(resetPage){
    var table=document.getElementById('opSalesOrderTable'); if(!table) return;
    if (resetPage !== false) opSoCurrentPage = 1;

    var keyword=(document.getElementById('opSoSearch')?.value||'').trim().toLowerCase();
    var status=(document.getElementById('opSoStatus')?.value||'').trim().toLowerCase();
    var customer=(document.getElementById('opSoCustomer')?.value||'').trim();
    var startDate=document.getElementById('opSoStartDate')?.value||'';
    var endDate=document.getElementById('opSoEndDate')?.value||'';
    var pageLength=getEmbeddedSalesOrderPageLength();
    var matchedRows=[];

    table.querySelectorAll('tbody tr[data-search]').forEach(function(row){
        var rowSearch=row.getAttribute('data-search')||'';
        var rowStatus=row.getAttribute('data-status')||'';
        var rowCustomer=row.getAttribute('data-customer')||'';
        var rowDate=row.getAttribute('data-date')||'';
        var show=true;
        if(keyword && rowSearch.indexOf(keyword)===-1) show=false;
        if(status && rowStatus!==status) show=false;
        if(customer && rowCustomer!==customer) show=false;
        if(startDate && rowDate && rowDate<startDate) show=false;
        if(endDate && rowDate && rowDate>endDate) show=false;
        row.style.display='none';
        if(show) matchedRows.push(row);
    });

    var total=matchedRows.length;
    opSoTotalPages=Math.max(1, Math.ceil(total / pageLength));
    if(opSoCurrentPage > opSoTotalPages) opSoCurrentPage = opSoTotalPages;
    if(opSoCurrentPage < 1) opSoCurrentPage = 1;

    var startIndex=(opSoCurrentPage - 1) * pageLength;
    var endIndex=Math.min(startIndex + pageLength, total);
    matchedRows.slice(startIndex, endIndex).forEach(function(row){
        row.style.display='';
    });

    var empty=table.querySelector('.op-so-empty-row');
    if(empty) empty.style.display=total===0?'':'none';

    var info=document.getElementById('opSoPaginationInfo');
    if(info){
        if(total===0){
            info.textContent='Showing 0 to 0 of 0 entries';
        } else {
            info.textContent='Showing ' + (startIndex + 1) + ' to ' + endIndex + ' of ' + total + ' entries';
        }
    }

    var indicator=document.getElementById('opSoPageIndicator');
    if(indicator) indicator.textContent='Page ' + opSoCurrentPage + ' of ' + opSoTotalPages;

    var first=document.getElementById('opSoFirstPage');
    var prev=document.getElementById('opSoPrevPage');
    var next=document.getElementById('opSoNextPage');
    var last=document.getElementById('opSoLastPage');
    if(first) first.disabled = opSoCurrentPage <= 1 || total === 0;
    if(prev) prev.disabled = opSoCurrentPage <= 1 || total === 0;
    if(next) next.disabled = opSoCurrentPage >= opSoTotalPages || total === 0;
    if(last) last.disabled = opSoCurrentPage >= opSoTotalPages || total === 0;
}
function resetEmbeddedSalesOrderFilters(){
    ['opSoSearch','opSoStatus','opSoCustomer','opSoStartDate','opSoEndDate'].forEach(function(id){var el=document.getElementById(id); if(el) el.value='';});
    opSoCurrentPage = 1;
    filterEmbeddedSalesOrders(false);
}

window.addEventListener('DOMContentLoaded', function(){
    if (document.getElementById('opSalesOrderTable')) {
        filterEmbeddedSalesOrders(false);
    }
});
function exportEmbeddedSalesOrders(){
    const startDate = document.getElementById('opSoStartDate')?.value || '';
    const endDate = document.getElementById('opSoEndDate')?.value || '';
    const status = document.getElementById('opSoStatus')?.value || '';
    const customer = document.getElementById('opSoCustomer')?.value || '';
    const search = document.getElementById('opSoSearch')?.value || '';

    if (typeof XLSX === 'undefined') {
        Swal.fire('Error', 'Excel export library is still loading. Please try again.', 'error');
        return;
    }

    if (typeof showLoading === 'function') {
        showLoading();
    } else if (typeof Swal !== 'undefined') {
        Swal.fire({title:'Preparing export...', allowOutsideClick:false, didOpen:()=>Swal.showLoading()});
    }

    const formData = new FormData();
    formData.append('action', 'export_all_orders');
    formData.append('start_date', startDate);
    formData.append('end_date', endDate);
    formData.append('status', status);
    formData.append('customer', customer);
    formData.append('search', search);

    fetch('orderproduct.php', { method: 'POST', body: formData })
        .then(response => response.text())
        .then(text => {
            let data;
            try {
                data = JSON.parse(text);
            } catch (e) {
                throw new Error(text.substring(0, 300) || 'Invalid server response');
            }
            if (typeof Swal !== 'undefined') Swal.close();
            if (data.success && data.data && data.data.length > 0) {
                const headers = [
                    'Date Encoded', 'SO Order Number', 'Customer Code', 'Store Name',
                    'Customer Name', 'Item Code', 'Item Description', 'Unit of Measurement',
                    'Quantity', 'Gross Price', 'Discount', 'Net Price', 'Order Amount',
                    'Total Discount', 'Ave. Cost', 'COGS', 'Gross Profit', 'Encoded by'
                ];
                const rows = data.data.map(row => [
                    row.date_encoded || '',
                    row.so_order_number || '',
                    row.customer_code || '',
                    row.store_name || '',
                    row.customer_name || '',
                    row.item_code || '',
                    row.item_description || '',
                    row.unit_of_measurement || '',
                    Number(row.quantity || 0),
                    Number(row.gross_price || 0),
                    Number(row.discount || 0),
                    Number(row.net_price || 0),
                    Number(row.order_amount || 0),
                    Number(row.total_discount || 0),
                    Number(row.ave_cost || 0),
                    Number(row.cogs || 0),
                    Number(row.gross_profit || 0),
                    row.encoded_by || ''
                ]);

                const wsData = [headers, ...rows];
                const ws = XLSX.utils.aoa_to_sheet(wsData);
                const moneyCols = ['J','K','L','M','N','O','P','Q'];
                moneyCols.forEach(col => {
                    for (let r = 2; r <= wsData.length; r++) {
                        const cell = ws[`${col}${r}`];
                        if (cell) cell.z = '#,##0.00';
                    }
                });
                for (let r = 2; r <= wsData.length; r++) {
                    const cell = ws[`I${r}`];
                    if (cell) cell.z = '#,##0.00';
                }
                ws['!cols'] = headers.map(h => ({ wch: Math.max(14, h.length + 2) }));
                const wb = XLSX.utils.book_new();
                XLSX.utils.book_append_sheet(wb, ws, 'Sales Orders');
                XLSX.writeFile(wb, `sales_orders_${new Date().toISOString().slice(0,19).replace(/[:T]/g,'-')}.xlsx`);
                Swal.fire('Success', 'Export completed', 'success');
            } else if (data.success && (!data.data || data.data.length === 0)) {
                Swal.fire('Info', 'No orders found for the selected filters', 'info');
            } else {
                Swal.fire('Error', data.message || 'Export failed', 'error');
            }
        })
        .catch(error => {
            if (typeof Swal !== 'undefined') Swal.close();
            Swal.fire('Error', error.message || 'An error occurred during export', 'error');
        });
}
function printEmbeddedSalesOrders(){
    const startDate = document.getElementById('opSoStartDate')?.value || '';
    const endDate = document.getElementById('opSoEndDate')?.value || '';
    const status = document.getElementById('opSoStatus')?.value || '';
    const customer = document.getElementById('opSoCustomer')?.value || '';
    const search = document.getElementById('opSoSearch')?.value || '';

    if (typeof showLoading === 'function') {
        showLoading();
    } else if (typeof Swal !== 'undefined') {
        Swal.fire({title:'Preparing print...', allowOutsideClick:false, didOpen:()=>Swal.showLoading()});
    }

    const formData = new FormData();
    formData.append('action', 'print_all_orders');
    formData.append('start_date', startDate);
    formData.append('end_date', endDate);
    formData.append('status', status);
    formData.append('customer', customer);
    formData.append('search', search);

    fetch('orderproduct.php', { method: 'POST', body: formData })
        .then(response => response.text())
        .then(text => {
            let data;
            try {
                data = JSON.parse(text);
            } catch (e) {
                throw new Error(text.substring(0, 300) || 'Invalid server response');
            }
            if (typeof Swal !== 'undefined') Swal.close();
            if (data.success && data.html) {
                const iframe = document.getElementById('printFrame') || createPrintFrame();
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
            if (typeof Swal !== 'undefined') Swal.close();
            Swal.fire('Error', error.message || 'An error occurred while preparing print', 'error');
        });
}
</script>

<!-- Edit Sales Order Modal for embedded Sales Order tab -->

<script>
// ===== ORIGINAL SALES_ORDER.PHP EDIT MODAL/FUNCTIONS FOR EMBEDDED SALES ORDER TAB =====
// Uses the same modal IDs and function names from sales_order.php, but posts back to orderproduct.php.
let currentOrderId = window.currentOrderId || null;
let currentEditOrderData = null;

function onOrderStatusChange() {
    const status = document.getElementById('editOrderStatus')?.value || '';
    const driverContainer = document.getElementById('driverSelectionContainer');
    const vehicleContainer = document.getElementById('vehicleSelectionContainer');
    const paymentNotice = document.getElementById('paymentNotice');
    if (driverContainer) driverContainer.style.display = ['confirmed','processing','ready','in_transit','delivered'].includes(status) ? 'block' : 'none';
    if (vehicleContainer) vehicleContainer.style.display = ['confirmed','processing','ready','in_transit','delivered'].includes(status) ? 'block' : 'none';
    if (paymentNotice) paymentNotice.style.display = status === 'delivered' ? 'block' : 'none';
}

function toggleEditSIFields() {
    const enabled = document.getElementById('enableSIFields')?.checked;
    const fields = document.getElementById('editSIFields');
    if (fields) fields.style.display = enabled ? 'flex' : 'none';
}

function recalculateEditOrderItemsTotal() {
    let totalQty = 0;
    let totalAmount = 0;
    document.querySelectorAll('#editOrderItemsTableBody tr[data-so-item-id]').forEach(row => {
        const qtyEl = row.querySelector('.edit-item-qty');
        const priceEl = row.querySelector('.edit-item-price');
        let qty = parseFloat(qtyEl?.value || 0) || 0;
        const maxQty = parseFloat(qtyEl?.dataset.maxQty || qty) || qty;
        if (qty > maxQty) {
            qty = maxQty;
            qtyEl.value = maxQty;
            Swal.fire('Warning', 'Quantity cannot be higher than the original ordered quantity.', 'warning');
        }
        if (qty < 0) {
            qty = 0;
            qtyEl.value = 0;
        }
        let price = parseFloat(priceEl?.value || 0) || 0;
        if (price < 0) {
            price = 0;
            priceEl.value = '0.00';
        }
        const subtotal = qty * price;
        totalQty += qty;
        totalAmount += subtotal;
        const subtotalCell = row.querySelector('.edit-item-subtotal');
        if (subtotalCell) subtotalCell.textContent = formatCurrency(subtotal);
    });
    const qtyCell = document.getElementById('editItemsTotalQty');
    const amtCell = document.getElementById('editItemsTotalAmount');
    const totalInput = document.getElementById('editTotalAmount');
    if (qtyCell) qtyCell.textContent = Number.isInteger(totalQty) ? String(totalQty) : totalQty.toFixed(2);
    if (amtCell) amtCell.textContent = formatCurrency(totalAmount);
    if (totalInput) totalInput.value = totalAmount.toFixed(2);
}

function getEditedOrderItemsPayload() {
    const items = [];
    document.querySelectorAll('#editOrderItemsTableBody tr[data-so-item-id]').forEach(row => {
        items.push({
            so_item_id: row.getAttribute('data-so-item-id'),
            quantity_ordered: parseFloat(row.querySelector('.edit-item-qty')?.value || 0) || 0,
            unit_price: parseFloat(row.querySelector('.edit-item-price')?.value || 0) || 0
        });
    });
    return items;
}

function renderEditOrderItemsTable(items) {
    const tbody = document.getElementById('editOrderItemsTableBody');
    if (!tbody) return;
    if (!items || !items.length) {
        tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-3">No items found.</td></tr>';
        recalculateEditOrderItemsTotal();
        return;
    }
    tbody.innerHTML = items.map(item => {
        const soItemId = item.so_item_id || '';
        const qty = parseFloat(item.quantity_ordered || item.quantity || 0) || 0;
        const originalQty = qty;
        const unitPrice = parseFloat(item.net_price || item.unit_price || item.gross_price || 0) || 0;
        const subtotal = qty * unitPrice;
        const itemName = item.item_name || item.item_description || 'Item';
        const itemCode = item.item_code ? `<div class="small text-muted">${escapeHtml(item.item_code)}</div>` : '';
        return `
            <tr data-so-item-id="${escapeHtml(soItemId)}">
                <td><div class="fw-semibold">${escapeHtml(itemName)}</div>${itemCode}</td>
                <td class="text-center">${escapeHtml(item.unit_type || '')}</td>
                <td class="text-center">
                    <input type="text" inputmode="numeric" class="form-control form-control-sm edit-item-qty" value="${qty}" data-max-qty="${originalQty}" oninput="recalculateEditOrderItemsTotal()" autocomplete="off">
                </td>
                <td class="text-center"><input type="text" inputmode="decimal" class="form-control form-control-sm edit-item-price" value="${unitPrice.toFixed(2)}" oninput="recalculateEditOrderItemsTotal()" autocomplete="off"></td>
                <td class="text-end fw-bold edit-item-subtotal">${formatCurrency(subtotal)}</td>
            </tr>`;
    }).join('');
    recalculateEditOrderItemsTotal();
}

function openSIActionModal(orderId) {
    const modalEl = document.getElementById('siActionModal');
    if (!modalEl) return;
    const form = document.getElementById('siActionForm');
    if (form) form.reset();
    document.getElementById('siActionSoId').value = orderId || '';
    document.getElementById('siActionSoNumber').value = 'Loading...';
    document.getElementById('siActionCustomerName').value = 'Loading...';
    bootstrap.Modal.getOrCreateInstance(modalEl, { keyboard: true }).show();
    const formData = new FormData();
    formData.append('action', 'get_order_details');
    formData.append('order_id', orderId);
    fetch('orderproduct.php', { method: 'POST', body: formData, headers: {'X-Requested-With':'XMLHttpRequest'} })
        .then(async response => {
            const text = await response.text();
            try { return JSON.parse(text); } catch (e) { throw new Error(text ? text.substring(0, 300) : 'Invalid server response.'); }
        })
        .then(data => {
            if (!data.success) throw new Error(data.message || 'Unable to load sales order.');
            const order = data.order || {};
            const invoice = data.invoice || {};
            const status = String(order.order_status || 'pending').toLowerCase();
            if (!['pending', 'confirmed', 'delivered'].includes(status)) {
                bootstrap.Modal.getInstance(modalEl)?.hide();
                Swal.fire('Not allowed', 'SI can only be added to Pending, Confirmed, or Delivered sales orders.', 'warning');
                return;
            }
            const existingSINumber = String(order.si_number || invoice.si_number || '').trim();
            if (existingSINumber !== '') {
                bootstrap.Modal.getInstance(modalEl)?.hide();
                Swal.fire('SI Locked', 'This sales order already has an SI number and can no longer be edited.', 'info');
                return;
            }
            document.getElementById('siActionSoId').value = order.so_id || orderId;
            document.getElementById('siActionSoNumber').value = order.so_number || '';
            document.getElementById('siActionCustomerName').value = order.customer_name || 'Walk-in Customer';
            document.getElementById('siActionNumber').value = order.si_number || invoice.si_number || '';
            document.getElementById('siActionRegisteredBusinessName').value = order.registered_business_name || invoice.registered_business_name || '';
            document.getElementById('siActionTin').value = order.tin || invoice.tin || '';
            document.getElementById('siActionBusinessAddress').value = order.business_address || invoice.business_address || '';
        })
        .catch(error => {
            bootstrap.Modal.getInstance(modalEl)?.hide();
            Swal.fire('Error', error.message || 'Failed to load SI details.', 'error');
        });
}

function saveSIActionFromSalesOrder(event) {
    event.preventDefault();
    const soId = document.getElementById('siActionSoId')?.value || '';
    const siNumber = document.getElementById('siActionNumber')?.value.trim() || '';
    const registeredBusinessName = document.getElementById('siActionRegisteredBusinessName')?.value.trim() || '';
    const tin = document.getElementById('siActionTin')?.value.trim() || '';
    const businessAddress = document.getElementById('siActionBusinessAddress')?.value.trim() || '';
    if (!soId || !siNumber || !registeredBusinessName || !tin || !businessAddress) {
        Swal.fire('Missing SI details', 'Please complete SI Number, Registered Business Name, TIN, and Address.', 'warning');
        return;
    }
    const attachmentInput = document.getElementById('siActionAttachments');
    if (!attachmentInput || !attachmentInput.files || attachmentInput.files.length === 0) {
        Swal.fire('Missing SI Attachments', 'Please upload at least one SI attachment before saving.', 'warning');
        return;
    }
    const saveBtn = document.getElementById('saveSIActionBtn');
    if (saveBtn) {
        saveBtn.disabled = true;
        saveBtn.dataset.originalText = saveBtn.innerHTML;
        saveBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Saving...';
    }
    const formData = new FormData();
    formData.append('action', 'update_sales_order_si_from_tab');
    formData.append('so_id', soId);
    formData.append('si_number', siNumber);
    formData.append('registered_business_name', registeredBusinessName);
    formData.append('tin', tin);
    formData.append('business_address', businessAddress);
    if (attachmentInput && attachmentInput.files && attachmentInput.files.length) {
        Array.from(attachmentInput.files).forEach(file => formData.append('si_attachments[]', file));
    }
    fetch('orderproduct.php', { method: 'POST', body: formData, headers: {'X-Requested-With':'XMLHttpRequest'} })
        .then(async response => {
            const text = await response.text();
            try { return JSON.parse(text); } catch (e) { throw new Error(text ? text.substring(0, 300) : 'Invalid server response.'); }
        })
        .then(data => {
            if (saveBtn) { saveBtn.disabled = false; saveBtn.innerHTML = saveBtn.dataset.originalText || 'Save SI'; }
            if (!data.success) throw new Error(data.message || 'Failed to save SI details.');
            const modal = bootstrap.Modal.getInstance(document.getElementById('siActionModal'));
            if (modal) modal.hide();
            Swal.fire({ icon: 'success', title: 'SI Saved', text: data.message || 'SI details saved successfully.', timer: 1300, showConfirmButton: false })
                .then(() => window.location.href = 'orderproduct.php?tab=salesOrder');
        })
        .catch(error => {
            if (saveBtn) { saveBtn.disabled = false; saveBtn.innerHTML = saveBtn.dataset.originalText || 'Save SI'; }
            Swal.fire('Error', error.message || 'Failed to save SI details.', 'error');
        });
}

function editOrder(id) {
    currentOrderId = id;
    const modalEl = document.getElementById('editOrderModal');
    const tbody = document.getElementById('editOrderItemsTableBody');
    if (tbody) tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-3">Loading items...</td></tr>';
    if (modalEl) bootstrap.Modal.getOrCreateInstance(modalEl, { keyboard: true }).show();

    const formData = new FormData();
    formData.append('action', 'get_order_details');
    formData.append('order_id', id);

    fetch('orderproduct.php', { method: 'POST', body: formData, headers: {'X-Requested-With':'XMLHttpRequest'} })
        .then(async response => {
            const text = await response.text();
            try { return JSON.parse(text); }
            catch (e) { throw new Error(text ? text.substring(0, 300) : 'Invalid server response.'); }
        })
        .then(data => {
            if (!data.success) throw new Error(data.message || 'Unable to load sales order.');
            const order = data.order || {};
            const invoice = data.invoice || {};
            const documents = data.documents || {};
            currentEditOrderData = data;
            document.getElementById('editOrderId').value = order.so_id || id;
            document.getElementById('editOrderNumber').value = order.so_number || '';
            document.getElementById('editOrderDate').value = (order.order_date || '').substring(0, 10) || new Date().toISOString().substring(0, 10);
            document.getElementById('editCustomerName').value = order.customer_name || 'Walk-in Customer';
            document.getElementById('editOrderStatus').value = (order.order_status || 'pending').toLowerCase();
            document.getElementById('editTotalAmount').value = parseFloat(order.total_amount || order.order_amount || 0).toFixed(2);

            const outstandingAmount = parseFloat(order.outstanding_balance_amount || 0) || 0;
            const outstandingBox = document.getElementById('editOutstandingBalanceBox');
            if (outstandingBox) outstandingBox.style.display = outstandingAmount > 0 ? 'block' : 'none';
            const outstandingText = document.getElementById('editOutstandingBalanceAmount');
            if (outstandingText) outstandingText.textContent = formatCurrency(outstandingAmount);

            const driverSelect = document.getElementById('editDriverSelect');
            const vehicleSelect = document.getElementById('editVehicleSelect');
            if (driverSelect) driverSelect.value = documents.driver_id || order.driver_id || '';
            if (vehicleSelect) vehicleSelect.value = documents.vehicle_id || order.vehicle_id || '';
            try { if (window.jQuery && driverSelect && jQuery(driverSelect).data('select2')) jQuery(driverSelect).trigger('change'); } catch(e) {}
            try { if (window.jQuery && vehicleSelect && jQuery(vehicleSelect).data('select2')) jQuery(vehicleSelect).trigger('change'); } catch(e) {}

            renderEditOrderItemsTable(data.items || []);
            onOrderStatusChange();
        })
        .catch(error => {
            if (tbody) tbody.innerHTML = `<tr><td colspan="5" class="text-center text-danger py-3">${escapeHtml(error.message || 'Failed to load order.')}</td></tr>`;
            Swal.fire('Error', error.message || 'Failed to load sales order.', 'error');
        });
}

function updateOrder() {
    const orderId = document.getElementById('editOrderId')?.value || currentOrderId;
    const orderDate = document.getElementById('editOrderDate')?.value || '';
    const orderStatus = document.getElementById('editOrderStatus')?.value || 'pending';
    const totalAmount = document.getElementById('editTotalAmount')?.value || '0';
    const items = getEditedOrderItemsPayload();

    if (!orderId) { Swal.fire('Error', 'Invalid sales order.', 'error'); return; }
    if (!orderDate) { Swal.fire('Warning', 'Order date is required.', 'warning'); return; }
    if (!items.length) { Swal.fire('Warning', 'Sales order must have at least one item.', 'warning'); return; }

    const updateBtn = document.getElementById('updateOrderBtn');
    if (updateBtn) {
        updateBtn.disabled = true;
        updateBtn.dataset.originalText = updateBtn.innerHTML;
        updateBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Updating...';
    }
    if (typeof Swal !== 'undefined') {
        Swal.fire({
            title: 'Updating order...',
            text: 'Please wait while the sales order is being saved.',
            allowOutsideClick: false,
            allowEscapeKey: false,
            didOpen: () => Swal.showLoading()
        });
    }
    const formData = new FormData();
    formData.append('action', 'update_order');
    formData.append('so_id', orderId);
    formData.append('order_date', orderDate);
    formData.append('created_at', orderDate);
    formData.append('order_status', orderStatus);
    formData.append('total_amount', totalAmount);
    formData.append('edited_items', JSON.stringify(items));
    formData.append('driver_id', document.getElementById('editDriverSelect')?.value || '');
    formData.append('vehicle_id', document.getElementById('editVehicleSelect')?.value || '');

    // SI is intentionally not submitted here. Use the Sales Order action button to add/edit SI details.

    fetch('orderproduct.php', { method: 'POST', body: formData, headers: {'X-Requested-With':'XMLHttpRequest'} })
        .then(async response => {
            const text = await response.text();
            try { return JSON.parse(text); }
            catch (e) { throw new Error(text ? text.substring(0, 300) : 'Invalid server response.'); }
        })
        .then(data => {
            if (typeof Swal !== 'undefined') Swal.close();
            if (updateBtn) {
                updateBtn.disabled = false;
                updateBtn.innerHTML = updateBtn.dataset.originalText || 'Update Order';
            }
            if (data.success) {
                const modal = bootstrap.Modal.getInstance(document.getElementById('editOrderModal'));
                if (modal) modal.hide();
                Swal.fire({ icon: 'success', title: 'Updated!', text: data.message || 'Sales order updated successfully.', timer: 1500, showConfirmButton: false })
                    .then(() => window.location.href = 'orderproduct.php?tab=salesOrder');
            } else {
                Swal.fire('Error', data.message || 'Failed to update sales order.', 'error');
            }
        })
        .catch(error => {
            if (typeof Swal !== 'undefined') Swal.close();
            if (updateBtn) {
                updateBtn.disabled = false;
                updateBtn.innerHTML = updateBtn.dataset.originalText || 'Update Order';
            }
            Swal.fire('Error', error.message || 'An error occurred while updating the order.', 'error');
        });
}

function deleteOrder(id) {
    currentOrderId = id;
    const orderCell = document.querySelector(`button[onclick="deleteOrder(${id})"]`)?.closest('tr')?.querySelector('td');
    const orderNo = orderCell ? orderCell.textContent.trim() : ('#' + id);
    const deleteNo = document.getElementById('deleteOrderNumber');
    if (deleteNo) deleteNo.textContent = orderNo;
    bootstrap.Modal.getOrCreateInstance(document.getElementById('deleteOrderModal'), { keyboard: true }).show();
}

function confirmDelete() {
    if (!currentOrderId) { Swal.fire('Error', 'Invalid sales order.', 'error'); return; }
    showLoading();
    const formData = new FormData();
    formData.append('action', 'delete_order');
    formData.append('so_id', currentOrderId);
    fetch('orderproduct.php', { method: 'POST', body: formData, headers: {'X-Requested-With':'XMLHttpRequest'} })
        .then(async response => {
            const text = await response.text();
            try { return JSON.parse(text); }
            catch (e) { throw new Error(text ? text.substring(0, 300) : 'Invalid server response.'); }
        })
        .then(data => {
            Swal.close();
            if (data.success) {
                const modal = bootstrap.Modal.getInstance(document.getElementById('deleteOrderModal'));
                if (modal) modal.hide();
                Swal.fire({ icon: 'success', title: 'Deleted!', text: data.message || 'Sales order deleted successfully.', timer: 1500, showConfirmButton: false })
                    .then(() => window.location.href = 'orderproduct.php?tab=salesOrder');
            } else {
                Swal.fire('Error', data.message || 'Failed to delete sales order.', 'error');
            }
        })
        .catch(() => { Swal.close(); Swal.fire('Error', 'An error occurred while deleting the order.', 'error'); });
}

function editFromView() {
    const modal = bootstrap.Modal.getInstance(document.getElementById('orderDetailsModal'));
    if (modal) modal.hide();
    setTimeout(() => { if (window.currentOrderIdFromOrderProduct || currentOrderId) editOrder(window.currentOrderIdFromOrderProduct || currentOrderId); }, 250);
}
</script>


    <style>
        /* Embedded Sales Order Edit modal, copied visually from sales_order.php */
        #editOrderModal .modal-dialog { max-width: 1120px; }
        #editOrderModal .modal-header {
            background: linear-gradient(135deg, #047857 0%, #008060 100%) !important;
            color: #fff !important;
            border-bottom: none;
            border-radius: 14px 14px 0 0;
        }
        #editOrderModal .modal-content {
            border: none;
            border-radius: 14px;
            overflow: hidden;
        }
        #editOrderModal .modal-body { background: #f8fafc; }
        #editOrderModal .form-label {
            font-weight: 700;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: .02em;
            font-size: .85rem;
        }
        #editOrderModal .form-control,
        #editOrderModal .form-select {
            border-radius: 12px;
            border: 1px solid #d9e2ec;
            min-height: 52px;
        }
        #editOrderModal .edit-si-card {
            border: 1px solid #d9e2ec !important;
            background: #fff !important;
            border-radius: 12px !important;
        }
        #editOrderModal .edit-order-items-table {
            border-collapse: separate;
            border-spacing: 0;
            overflow: hidden;
            border: 1px solid #bdebd5;
            border-radius: 5px;
        }
        #editOrderModal .edit-order-items-table thead th {
            background: #047857 !important;
            color: #fff !important;
            border: none !important;
            padding: 18px 26px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .02em;
            text-align: center;
        }
        #editOrderModal .edit-order-items-table thead th:first-child { text-align: left; }
        #editOrderModal .edit-order-items-table tbody td {
            border: none !important;
            border-bottom: 1px solid #bdebd5 !important;
            padding: 18px 26px;
            background: #ffffff;
            vertical-align: middle;
        }
        #editOrderModal .edit-order-items-table tbody tr:nth-child(even) td {
            background: #d6f8e7 !important;
        }
        #editOrderModal .edit-order-items-table .edit-item-qty,
        #editOrderModal .edit-order-items-table .edit-item-price {
            min-height: 50px;
            max-width: 95px;
            margin: 0 auto;
            text-align: center !important;
            border-radius: 12px;
            background: #fff;
        }
        #editOrderModal .edit-order-items-table tfoot th {
            background: #047857 !important;
            color: #fff !important;
            border: none !important;
            padding: 10px 6px;
            font-weight: 800;
            text-align: center !important;
            font-size: 1rem;
        }
        #editOrderModal #editTotalAmount {
            background: #fff;
            border-radius: 14px;
            min-height: 62px;
            font-size: 1rem;
        }
        #editOrderModal .modal-footer {
            border-top: none;
            background: #f8fafc;
            padding: 18px 28px;
        }
        #editOrderModal .modal-footer .btn {
            border-radius: 12px;
            padding: 12px 28px;
            font-weight: 700;
        }
        #editOrderModal #updateOrderBtn {
            background: linear-gradient(135deg, #047857 0%, #22c55e 100%) !important;
            border: none !important;
        }
    </style>

    <!-- ADD / EDIT SI MODAL -->
    <div class="modal fade no-print" id="siActionModal" tabindex="-1" data-bs-backdrop="true" data-bs-keyboard="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-receipt-cutoff me-2"></i>Issue SI</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="siActionForm" onsubmit="saveSIActionFromSalesOrder(event)">
                    <div class="modal-body">
                        <input type="hidden" id="siActionSoId">
                        <div class="alert alert-info py-2 mb-3">
                            <i class="bi bi-info-circle"></i> SI can be added only once for Pending, Confirmed, and Delivered sales orders. Once saved, SI details are locked.
                        </div>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label small mb-1">SO Number</label>
                                <input type="text" class="form-control form-control-sm" id="siActionSoNumber" readonly>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small mb-1">Customer</label>
                                <input type="text" class="form-control form-control-sm" id="siActionCustomerName" readonly>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small mb-1">SI Number <span class="text-danger">*</span></label>
                                <input type="text" class="form-control form-control-sm" id="siActionNumber" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small mb-1">Registered Business Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control form-control-sm" id="siActionRegisteredBusinessName" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small mb-1">TIN <span class="text-danger">*</span></label>
                                <input type="text" class="form-control form-control-sm" id="siActionTin" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small mb-1">Business Address <span class="text-danger">*</span></label>
                                <input type="text" class="form-control form-control-sm" id="siActionBusinessAddress" required>
                            </div>
                            <div class="col-12">
                                <label class="form-label small mb-1">SI Attachments <span class="text-danger">*</span></label>
                                <input type="file" class="form-control form-control-sm" id="siActionAttachments" multiple required accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx,.xls,.xlsx">
                                <small class="text-muted">Required. Allowed: PDF, images, Word, and Excel files. Max 15MB each.</small>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success" id="saveSIActionBtn"><i class="bi bi-save me-1"></i>Save SI</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- EDIT ORDER MODAL -->
    <div class="modal fade no-print" id="editOrderModal" tabindex="-1" data-bs-backdrop="true" data-bs-keyboard="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>Edit Sales Order</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="editOrderForm">
                        <input type="hidden" id="editOrderId">
                        <?php if (!empty($orderproduct_so_branch_column_exists) && !$view_all_branches): ?>
                            <input type="hidden" name="branch_id" value="<?= (int)$branch_id ?>">
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
                                    <option value="confirmed">Confirm Order (Generate Documents)</option>
                                    <option value="delivered">Mark as Delivered</option>
                                    <option value="cancelled">Cancel Order</option>
                                </select>
                            </div>
                            <div class="col-md-12" id="editOutstandingBalanceBox" style="display:none;">
                                <div class="alert alert-warning mb-0 d-flex align-items-start gap-2">
                                    <i class="bi bi-exclamation-triangle-fill mt-1"></i>
                                    <div>
                                        <div class="fw-bold">Customer has no credit limit and has an outstanding balance.</div>
                                        <div class="small">Outstanding Balance: <span id="editOutstandingBalanceAmount" class="fw-bold">₱0.00</span></div>
                                    </div>
                                </div>
                            </div>
                            <!-- SI details moved to the Sales Order action button. -->
                            
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

                            <div class="col-12">
                                <label class="form-label">Ordered Items</label>
                                <div class="table-responsive">
                                    <table class="table table-sm align-middle mb-0 edit-order-items-table">
                                        <thead>
                                            <tr>
                                                <th style="width: 44%;">Item</th>
                                                <th class="text-center" style="width: 14%;">Unit</th>
                                                <th class="text-end" style="width: 14%;">Quantity</th>
                                                <th class="text-end" style="width: 14%;">Price</th>
                                                <th class="text-end" style="width: 14%;">Subtotal</th>
                                            </tr>
                                        </thead>
                                        <tbody id="editOrderItemsTableBody">
                                            <tr>
                                                <td colspan="5" class="text-center text-muted py-3">Loading items...</td>
                                            </tr>
                                        </tbody>
                                        <tfoot>
                                            <tr>
                                                <th colspan="2" class="text-end">Total</th>
                                                <th class="text-end" id="editItemsTotalQty">0</th>
                                                <th></th>
                                                <th class="text-end" id="editItemsTotalAmount">₱0.00</th>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                            </div>
                            <div class="col-md-4 ms-auto">
                                <label for="editTotalAmount" class="form-label">Total Amount (₱) *</label>
                                <input type="number" class="form-control" id="editTotalAmount" step="0.01" min="0" required readonly>
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
                    <button type="button" class="btn btn-primary" onclick="updateOrder(); return false;" id="updateOrderBtn">Update Order</button>
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




<style id="amgc-create-invoice-button-final-fix-css">
#invoiceSaveCloseBtn,
#invoiceSaveNewBtn {
    pointer-events: auto !important;
    position: relative !important;
    z-index: 50 !important;
    opacity: 1 !important;
    cursor: pointer !important;
}
.invoice-actions {
    position: relative !important;
    z-index: 50 !important;
    pointer-events: auto !important;
}
</style>

<script id="amgc-create-invoice-button-final-fix">
(function () {
    function amgcShowOrderProductMessage(message) {
        if (typeof showToast === 'function') {
            showToast(message);
            return;
        }
        if (window.Swal && typeof Swal.fire === 'function') {
            Swal.fire({ icon: 'warning', title: 'Notice', text: message, confirmButtonColor: '#047857' });
            return;
        }
        alert(message);
    }

    function amgcEnableCreateInvoiceButtons() {
        ['invoiceSaveCloseBtn', 'invoiceSaveNewBtn'].forEach(function (id) {
            var btn = document.getElementById(id);
            if (!btn) return;
            btn.disabled = false;
            btn.removeAttribute('disabled');
            btn.style.pointerEvents = 'auto';
            btn.style.opacity = '1';
            btn.style.cursor = 'pointer';
        });
    }

    function amgcRunInvoiceSync() {
        try { if (typeof syncInvoiceFieldsToOriginalForm === 'function') syncInvoiceFieldsToOriginalForm(); } catch (e) { console.warn(e); }
        try { if (typeof syncInvoiceRowsToCart === 'function') syncInvoiceRowsToCart(); } catch (e) { console.warn(e); }
        try { if (typeof syncInvoicePaymentToOriginalForm === 'function') syncInvoicePaymentToOriginalForm(); } catch (e) { console.warn(e); }
        try { if (typeof updateCartBadge === 'function') updateCartBadge(); } catch (e) { console.warn(e); }
    }

    function amgcBasicInvoiceValidate() {
        amgcRunInvoiceSync();

        var customerId = '';
        var invoiceCustomer = document.getElementById('invoiceCustomerSelect');
        var modalCustomer = document.getElementById('modalCustomerSelect');
        var lockedCustomer = document.getElementById('lockedCustomerId');
        if (invoiceCustomer && invoiceCustomer.value) customerId = invoiceCustomer.value;
        if (!customerId && modalCustomer && modalCustomer.value) customerId = modalCustomer.value;
        if (!customerId && lockedCustomer && lockedCustomer.value) customerId = lockedCustomer.value;

        if (!customerId) {
            amgcShowOrderProductMessage('Please select a customer.');
            if (invoiceCustomer) invoiceCustomer.focus();
            return false;
        }

        var hasItems = false;
        try {
            if (typeof cart !== 'undefined' && Array.isArray(cart) && cart.length > 0) hasItems = true;
        } catch (e) {}
        if (!hasItems) {
            var invoiceRows = document.querySelectorAll('#invoiceItemsBody tr');
            invoiceRows.forEach(function (row) {
                var product = row.querySelector('.invoice-product-select, select[data-field="product"], select');
                var qty = row.querySelector('.invoice-qty-input, input[data-field="qty"], input[name*="qty"], input[type="number"]');
                if (product && product.value && qty && parseFloat(qty.value || '0') > 0) hasItems = true;
            });
        }
        if (!hasItems) {
            amgcShowOrderProductMessage('Please add at least one item.');
            return false;
        }

        return true;
    }

    function amgcSubmitInvoice(mode) {
        amgcEnableCreateInvoiceButtons();
        window.invoiceOrderSubmitMode = mode;

        if (!amgcBasicInvoiceValidate()) {
            window.invoiceOrderSubmitMode = '';
            amgcEnableCreateInvoiceButtons();
            return;
        }

        try {
            if (typeof submitOrder === 'function') {
                submitOrder();
                return;
            }
        } catch (err) {
            console.error('Create Invoice submit error:', err);
            amgcEnableCreateInvoiceButtons();
            amgcShowOrderProductMessage(err && err.message ? err.message : 'Unable to submit order.');
            return;
        }

        amgcShowOrderProductMessage('Submit function was not loaded. Please refresh the page and try again.');
    }

    window.invoiceSaveAndClose = function () {
        amgcSubmitInvoice('close');
    };

    window.invoiceSaveAndNew = function () {
        amgcSubmitInvoice('new');
    };

    document.addEventListener('DOMContentLoaded', amgcEnableCreateInvoiceButtons);
    window.addEventListener('load', amgcEnableCreateInvoiceButtons);

    document.addEventListener('click', function (event) {
        var btn = event.target && event.target.closest ? event.target.closest('#invoiceSaveCloseBtn, #invoiceSaveNewBtn') : null;
        if (!btn) return;
        event.preventDefault();
        event.stopPropagation();
        if (event.stopImmediatePropagation) event.stopImmediatePropagation();
        amgcEnableCreateInvoiceButtons();
        amgcSubmitInvoice(btn.id === 'invoiceSaveCloseBtn' ? 'close' : 'new');
    }, true);
})();
</script>


<!-- AMGC FIX: Invoice Details Modal clean centered view only -->
<style id="amgc-order-details-clean-centered-modal-final">
/* Invoice Details modal only. Create Invoice tab and other modals are untouched. */
#orderDetailsModal.show {
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    padding: 16px !important;
    overflow-x: hidden !important;
    overflow-y: auto !important;
}

#orderDetailsModal.modal {
    overflow-x: hidden !important;
    overflow-y: auto !important;
}

#orderDetailsModal .modal-dialog,
#orderDetailsModal .modal-dialog.order-details-wide-modal,
#orderDetailsModal .modal-fullscreen {
    position: relative !important;
    inset: auto !important;
    width: min(1180px, 96vw) !important;
    max-width: min(1180px, 96vw) !important;
    min-width: 0 !important;
    height: auto !important;
    min-height: 0 !important;
    max-height: 92vh !important;
    margin: 0 auto !important;
    padding: 0 !important;
    transform: none !important;
}

#orderDetailsModal .modal-content {
    width: 100% !important;
    height: auto !important;
    min-height: 0 !important;
    max-height: 92vh !important;
    border: 0 !important;
    border-radius: 14px !important;
    display: flex !important;
    flex-direction: column !important;
    overflow: hidden !important;
    background: #ffffff !important;
    box-shadow: 0 18px 50px rgba(15, 23, 42, .28) !important;
}

#orderDetailsModal .modal-header {
    flex: 0 0 auto !important;
    height: 48px !important;
    min-height: 48px !important;
    padding: 8px 16px !important;
    background: linear-gradient(90deg, #047857 0%, #36d34a 100%) !important;
    color: #ffffff !important;
    border: 0 !important;
}

#orderDetailsModal .modal-title {
    font-size: 1.08rem !important;
    line-height: 1.1 !important;
    font-weight: 500 !important;
}

#orderDetailsModal .btn-close {
    width: 30px !important;
    height: 30px !important;
    padding: 0 !important;
    margin: 0 !important;
    border-radius: 50% !important;
    background-color: rgba(255,255,255,.45) !important;
    opacity: 1 !important;
    transition: background-color .16s ease, transform .16s ease, opacity .16s ease !important;
}

#orderDetailsModal .btn-close:hover {
    background-color: rgba(255,255,255,.7) !important;
    transform: scale(1.04) !important;
}

#orderDetailsModal .modal-body,
#orderDetailsModal #orderDetailsContent {
    flex: 1 1 auto !important;
    width: 100% !important;
    height: auto !important;
    min-height: 0 !important;
    max-height: calc(92vh - 48px) !important;
    overflow-x: hidden !important;
    overflow-y: auto !important;
    padding: 0 !important;
    background: #ffffff !important;
}

#orderDetailsModal .modal-footer {
    display: none !important;
}

#orderDetailsModal .op-invoice-form-view {
    width: 100% !important;
    height: auto !important;
    min-height: 0 !important;
    overflow: visible !important;
    background: #ffffff !important;
    display: block !important;
    position: relative !important;
    font-size: 13px !important;
}

#orderDetailsModal .op-invoice-main {
    width: 100% !important;
    height: auto !important;
    min-height: 0 !important;
    transform: none !important;
    padding: 18px 22px 16px !important;
    display: block !important;
    overflow: visible !important;
    box-sizing: border-box !important;
}

#orderDetailsModal .op-invoice-sheet-head {
    display: grid !important;
    grid-template-columns: minmax(260px, .85fr) minmax(520px, 1.15fr) !important;
    gap: 16px !important;
    align-items: start !important;
}

#orderDetailsModal .op-invoice-title {
    font-size: 2.35rem !important;
    line-height: 1 !important;
    margin: 0 0 8px !important;
    font-weight: 300 !important;
}

#orderDetailsModal .op-customer-readonly {
    max-width: none !important;
    padding: 8px 10px !important;
    line-height: 1.15 !important;
    font-size: .9rem !important;
}

#orderDetailsModal .op-customer-readonly .name {
    font-size: 1.08rem !important;
    margin-bottom: 3px !important;
}

#orderDetailsModal .op-right-fields {
    display: grid !important;
    grid-template-columns: repeat(4, minmax(150px, 1fr)) !important;
    gap: 8px 12px !important;
}

#orderDetailsModal .op-field label,
#orderDetailsModal .op-section-label {
    font-size: .75rem !important;
    margin-bottom: 3px !important;
    line-height: 1 !important;
    font-weight: 500 !important;
}

#orderDetailsModal .op-detail-control,
#orderDetailsModal .op-detail-select {
    height: 32px !important;
    min-height: 32px !important;
    padding: 4px 8px !important;
    font-size: .88rem !important;
    line-height: 1.1 !important;
}

#orderDetailsModal .op-items-wrap,
#orderDetailsModal .op-invoice-items-wrap {
    height: auto !important;
    min-height: 0 !important;
    max-height: none !important;
    overflow: visible !important;
    margin-top: 12px !important;
}

#orderDetailsModal .op-items-table,
#orderDetailsModal .op-invoice-items-table {
    width: 100% !important;
    height: auto !important;
    table-layout: fixed !important;
    font-size: .86rem !important;
    margin: 0 !important;
}

#orderDetailsModal .op-items-table thead th,
#orderDetailsModal .op-invoice-items-table thead th {
    padding: 6px 7px !important;
    font-size: .78rem !important;
    line-height: 1.1 !important;
    white-space: normal !important;
    font-weight: 500 !important;
}

#orderDetailsModal .op-items-table td,
#orderDetailsModal .op-invoice-items-table td,
#orderDetailsModal .op-invoice-items-table tfoot td {
    height: auto !important;
    min-height: 0 !important;
    padding: 6px 7px !important;
    line-height: 1.15 !important;
    font-size: .86rem !important;
    white-space: normal !important;
    word-break: normal !important;
    overflow-wrap: anywhere !important;
}

#orderDetailsModal .op-invoice-muted,
#orderDetailsModal .small {
    font-size: .78rem !important;
    line-height: 1.1 !important;
}

#orderDetailsModal .op-lower-area {
    display: grid !important;
    grid-template-columns: minmax(0, 1fr) minmax(280px, 350px) !important;
    gap: 16px !important;
    margin-top: 14px !important;
    min-height: 0 !important;
    align-items: start !important;
    overflow: visible !important;
}

#orderDetailsModal .op-lower-area > div:first-child,
#orderDetailsModal .op-lower-area > div:last-child {
    min-width: 0 !important;
}

#orderDetailsModal .op-message-box {
    min-height: 42px !important;
    height: 42px !important;
    padding: 5px 8px !important;
    resize: none !important;
    font-size: .86rem !important;
    line-height: 1.15 !important;
}

#orderDetailsModal .op-payment-box,
#orderDetailsModal .op-payment-view-box {
    width: 100% !important;
    margin-top: 8px !important;
    padding: 9px 11px !important;
}

#orderDetailsModal .op-payment-toggle-line {
    margin-bottom: 6px !important;
    font-size: .82rem !important;
    line-height: 1.1 !important;
}

#orderDetailsModal .op-payment-detail-line {
    display: grid !important;
    grid-template-columns: minmax(180px, 1fr) minmax(210px, 1fr) !important;
    gap: 10px !important;
    align-items: start !important;
}

#orderDetailsModal .op-payment-detail-line .op-field {
    min-width: 0 !important;
    width: 100% !important;
    margin: 0 !important;
}

#orderDetailsModal .op-payment-detail-line .op-detail-control,
#orderDetailsModal .op-payment-detail-line .op-detail-select {
    width: 100% !important;
    display: block !important;
}

#orderDetailsModal .op-summary-box {
    width: 100% !important;
    max-width: 350px !important;
    margin-left: auto !important;
    font-size: .88rem !important;
}

#orderDetailsModal .op-summary-box > div {
    margin-bottom: 5px !important;
    gap: 8px !important;
    grid-template-columns: 1fr minmax(90px, 125px) !important;
    line-height: 1.1 !important;
}

#orderDetailsModal .op-balance-due span,
#orderDetailsModal .op-balance-due strong {
    font-size: 1.06rem !important;
    line-height: 1.1 !important;
}

#orderDetailsModal .op-detail-footer {
    margin-top: 12px !important;
    gap: 8px !important;
    display: flex !important;
    flex-wrap: wrap !important;
    justify-content: flex-end !important;
}

#orderDetailsModal .op-detail-footer .btn {
    min-width: 100px !important;
    min-height: 34px !important;
    padding: 6px 12px !important;
    font-size: .88rem !important;
    font-weight: 500 !important;
    line-height: 1.1 !important;
    border-radius: 7px !important;
    transition: transform .16s ease, box-shadow .16s ease, background-color .16s ease, border-color .16s ease !important;
}

#orderDetailsModal .op-detail-footer .btn:hover {
    transform: translateY(-1px) !important;
    box-shadow: 0 5px 12px rgba(15, 23, 42, .14) !important;
}

@media (max-width: 1100px) {
    #orderDetailsModal.show {
        align-items: flex-start !important;
    }

    #orderDetailsModal .modal-dialog,
    #orderDetailsModal .modal-dialog.order-details-wide-modal,
    #orderDetailsModal .modal-fullscreen {
        width: 96vw !important;
        max-width: 96vw !important;
        margin-top: 10px !important;
        margin-bottom: 10px !important;
    }

    #orderDetailsModal .op-invoice-sheet-head {
        grid-template-columns: 1fr !important;
    }

    #orderDetailsModal .op-right-fields {
        grid-template-columns: repeat(2, minmax(130px, 1fr)) !important;
    }

    #orderDetailsModal .op-lower-area {
        grid-template-columns: 1fr !important;
    }

    #orderDetailsModal .op-summary-box {
        margin-left: 0 !important;
        max-width: none !important;
    }
}
</style>

<script id="amgc-order-details-smooth-buttons-final-script">
(function () {
    function cleanupModalState() {
        var visibleModal = document.querySelector('.modal.show');
        if (!visibleModal) {
            document.body.classList.remove('modal-open');
            document.body.style.removeProperty('overflow');
            document.body.style.removeProperty('padding-right');
            document.querySelectorAll('.modal-backdrop').forEach(function (backdrop) {
                backdrop.remove();
            });
        }
    }

    function closeOrderDetailsModal() {
        var modalEl = document.getElementById('orderDetailsModal');
        if (!modalEl) return;

        try {
            var modalInstance = bootstrap.Modal.getInstance(modalEl) || bootstrap.Modal.getOrCreateInstance(modalEl, {
                backdrop: true,
                keyboard: true,
                focus: true
            });
            modalInstance.hide();
        } catch (err) {
            modalEl.classList.remove('show');
            modalEl.style.display = 'none';
            modalEl.setAttribute('aria-hidden', 'true');
            modalEl.removeAttribute('aria-modal');
            modalEl.removeAttribute('role');
            cleanupModalState();
        }
    }

    window.amgcCloseOrderDetailsModal = closeOrderDetailsModal;

    document.addEventListener('click', function (event) {
        var modalEl = document.getElementById('orderDetailsModal');
        if (!modalEl || !modalEl.classList.contains('show')) return;

        var closeBtn = event.target.closest('#orderDetailsModal .btn-close, #orderDetailsModal .op-detail-footer .btn-light');
        if (!closeBtn) return;

        event.preventDefault();
        closeOrderDetailsModal();
    });

    document.addEventListener('hidden.bs.modal', function (event) {
        if (event.target && event.target.id === 'orderDetailsModal') {
            setTimeout(cleanupModalState, 40);
        }
    });
})();
</script>



<!-- AMGC INVOICE DETAILS MODAL CLEAN SIZE + SMOOTH BUTTON FIX -->
<style id="amgc-order-details-clean-modal-final-fix">
/* Invoice Details modal in Sales Order tab only: clean centered modal, not fullscreen. */
#orderDetailsModal .modal-dialog,
#orderDetailsModal .modal-dialog.order-details-wide-modal,
#orderDetailsModal.show .modal-dialog.order-details-wide-modal {
    width: min(1440px, calc(100vw - 28px)) !important;
    max-width: min(1440px, calc(100vw - 28px)) !important;
    min-width: 0 !important;
    height: auto !important;
    max-height: calc(100dvh - 28px) !important;
    margin: 14px auto !important;
    padding: 0 !important;
    position: relative !important;
    inset: auto !important;
    transform: none !important;
}

#orderDetailsModal .modal-content {
    width: 100% !important;
    height: auto !important;
    min-height: 0 !important;
    max-height: calc(100dvh - 28px) !important;
    border-radius: 18px !important;
    border: 0 !important;
    overflow: hidden !important;
    display: flex !important;
    flex-direction: column !important;
    box-shadow: 0 18px 46px rgba(5, 42, 71, .24) !important;
    background: #fff !important;
}

#orderDetailsModal .modal-header {
    height: 52px !important;
    min-height: 52px !important;
    flex: 0 0 52px !important;
    padding: 10px 18px !important;
    background: linear-gradient(135deg, #047857 0%, #44D34E 100%) !important;
    color: #fff !important;
    border-bottom: 0 !important;
}

#orderDetailsModal .modal-title,
#orderDetailsModal .modal-header .modal-title {
    color: #fff !important;
    font-size: 1.08rem !important;
    font-weight: 500 !important;
    line-height: 1.2 !important;
}

#orderDetailsModal .modal-body,
#orderDetailsModal #orderDetailsContent {
    flex: 1 1 auto !important;
    height: auto !important;
    min-height: 0 !important;
    max-height: calc(100dvh - 80px) !important;
    overflow-y: auto !important;
    overflow-x: hidden !important;
    padding: 0 !important;
    background: #f8fafc !important;
    -webkit-overflow-scrolling: touch !important;
}

#orderDetailsModal .modal-footer {
    display: none !important;
}

#orderDetailsModal .op-invoice-form-view {
    width: 100% !important;
    max-width: none !important;
    min-height: 0 !important;
    height: auto !important;
    font-size: 14px !important;
    background: #f8fafc !important;
}

#orderDetailsModal .op-invoice-main {
    width: 100% !important;
    max-width: 1368px !important;
    margin: 0 auto !important;
    padding: 14px 20px 16px !important;
    background: #fff !important;
}

#orderDetailsModal .op-invoice-sheet-head {
    display: grid !important;
    grid-template-columns: minmax(360px, .82fr) minmax(720px, 1.18fr) !important;
    gap: 14px !important;
    align-items: start !important;
}

#orderDetailsModal .op-right-fields {
    display: grid !important;
    grid-template-columns: repeat(4, minmax(150px, 1fr)) !important;
    gap: 8px 12px !important;
}

#orderDetailsModal .op-invoice-title {
    font-size: clamp(2rem, 3vw, 3rem) !important;
    line-height: .95 !important;
    margin: 4px 0 10px !important;
    font-weight: 400 !important;
}

#orderDetailsModal .op-detail-label,
#orderDetailsModal .op-field label,
#orderDetailsModal .op-section-label {
    font-size: .78rem !important;
    font-weight: 400 !important;
    margin-bottom: 3px !important;
}

#orderDetailsModal .op-detail-control,
#orderDetailsModal .op-detail-select {
    height: 32px !important;
    min-height: 32px !important;
    padding: 5px 8px !important;
    font-size: .86rem !important;
}

#orderDetailsModal .op-invoice-items-table,
#orderDetailsModal .op-items-table {
    font-size: .86rem !important;
    margin-top: 8px !important;
}

#orderDetailsModal .op-invoice-items-table thead th,
#orderDetailsModal .op-items-table thead th {
    font-size: .82rem !important;
    padding: 6px 7px !important;
    font-weight: 500 !important;
}

#orderDetailsModal .op-invoice-items-table td,
#orderDetailsModal .op-invoice-items-table tfoot td,
#orderDetailsModal .op-items-table td {
    padding: 6px 7px !important;
    font-size: .85rem !important;
}

#orderDetailsModal .op-lower-area {
    display: grid !important;
    grid-template-columns: minmax(620px, 1fr) minmax(360px, 400px) !important;
    gap: 14px !important;
    margin-top: 10px !important;
    align-items: start !important;
}

#orderDetailsModal .op-message-box {
    min-height: 38px !important;
    height: auto !important;
    font-size: .86rem !important;
}

#orderDetailsModal .op-payment-box,
#orderDetailsModal .op-payment-view-box,
#orderDetailsModal .op-summary-box {
    font-size: .86rem !important;
}

#orderDetailsModal .op-detail-footer,
#orderDetailsModal .op-details-action-bar,
#orderDetailsModal .op-invoice-actions {
    gap: 10px !important;
    justify-content: flex-end !important;
    flex-wrap: nowrap !important;
    margin-top: 10px !important;
}

#orderDetailsModal .op-detail-footer .btn,
#orderDetailsModal .op-details-action-bar .btn,
#orderDetailsModal .op-invoice-actions .btn {
    min-width: 120px !important;
    min-height: 34px !important;
    height: 34px !important;
    font-size: .9rem !important;
    font-weight: 400 !important;
    border-radius: 8px !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 6px !important;
    transition: background-color .16s ease, border-color .16s ease, transform .08s ease, box-shadow .16s ease !important;
}

#orderDetailsModal .op-detail-footer .btn:hover,
#orderDetailsModal .op-details-action-bar .btn:hover,
#orderDetailsModal .op-invoice-actions .btn:hover {
    transform: translateY(-1px) !important;
}

#orderDetailsModal .op-detail-footer .btn:active,
#orderDetailsModal .op-details-action-bar .btn:active,
#orderDetailsModal .op-invoice-actions .btn:active {
    transform: translateY(0) !important;
}

#orderDetailsModal .modal-header .btn-close,
#orderDetailsModal .btn-close {
    width: 32px !important;
    height: 32px !important;
    min-width: 32px !important;
    border-radius: 50% !important;
    opacity: 1 !important;
    margin: 0 0 0 auto !important;
    padding: 0 !important;
    background-color: rgba(255,255,255,.22) !important;
    background-size: 12px 12px !important;
    transition: background-color .16s ease, transform .08s ease !important;
}

#orderDetailsModal .modal-header .btn-close:hover,
#orderDetailsModal .btn-close:hover {
    background-color: rgba(255,255,255,.36) !important;
    transform: none !important;
}

#orderDetailsModal .modal-header .btn-close:active,
#orderDetailsModal .btn-close:active {
    transform: scale(.96) !important;
}



/* Wider fit patch: prevent invoice fields and action buttons from wrapping/cutting off. */
#orderDetailsModal .op-detail-control,
#orderDetailsModal .op-detail-select {
    white-space: nowrap !important;
    overflow: hidden !important;
    text-overflow: ellipsis !important;
}

#orderDetailsModal .op-detail-footer .btn,
#orderDetailsModal .op-details-action-bar .btn,
#orderDetailsModal .op-invoice-actions .btn {
    white-space: nowrap !important;
    flex: 0 0 auto !important;
}

#orderDetailsModal .op-summary-box {
    min-width: 360px !important;
    width: 100% !important;
    max-width: 400px !important;
    margin-left: auto !important;
}

#orderDetailsModal .op-summary-box > div {
    grid-template-columns: minmax(145px, 1fr) minmax(105px, 150px) !important;
    column-gap: 10px !important;
}

@media (max-width: 992px) {
    #orderDetailsModal .modal-dialog,
    #orderDetailsModal .modal-dialog.order-details-wide-modal {
        width: calc(100vw - 20px) !important;
        max-width: calc(100vw - 20px) !important;
        margin: 10px auto !important;
        max-height: calc(100dvh - 20px) !important;
    }

    #orderDetailsModal .modal-content {
        max-height: calc(100dvh - 20px) !important;
    }

    #orderDetailsModal .modal-body,
    #orderDetailsModal #orderDetailsContent {
        max-height: calc(100dvh - 72px) !important;
    }

    #orderDetailsModal .op-invoice-sheet-head,
    #orderDetailsModal .op-lower-area {
        grid-template-columns: 1fr !important;
    }

    #orderDetailsModal .op-right-fields {
        grid-template-columns: repeat(2, minmax(110px, 1fr)) !important;
    }
}
</style>

<script id="amgc-order-details-button-smooth-fix">
(function(){
    function cleanupModalBackdrops(){
        if (!document.querySelector('.modal.show')) {
            document.body.classList.remove('modal-open');
            document.body.style.removeProperty('overflow');
            document.body.style.removeProperty('padding-right');
            document.querySelectorAll('.modal-backdrop').forEach(function(backdrop){ backdrop.remove(); });
        }
    }

    document.addEventListener('click', function(e){
        var closeBtn = e.target.closest('#orderDetailsModal [data-bs-dismiss="modal"], #orderDetailsModal .btn-close, #orderDetailsModal .op-close-details-btn');
        if (!closeBtn) return;
        var modalEl = document.getElementById('orderDetailsModal');
        if (!modalEl) return;
        e.preventDefault();
        var modalInstance = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl, {backdrop:true, keyboard:true});
        modalInstance.hide();
        window.setTimeout(cleanupModalBackdrops, 220);
    }, true);

    var modalEl = document.getElementById('orderDetailsModal');
    if (modalEl && !modalEl.dataset.smoothCloseFixed) {
        modalEl.dataset.smoothCloseFixed = '1';
        modalEl.addEventListener('hidden.bs.modal', cleanupModalBackdrops);
    }
})();
</script>
</body>
</html>

<!-- AMGC FINAL PATCH: Invoice Details modal only, click-safe and slightly wider -->
<style id="amgc-order-details-click-safe-only-final">
body.amgc-order-details-open .modal-backdrop,
body.amgc-order-details-open .modal-backdrop.show {
    z-index: 2090 !important;
    pointer-events: auto !important;
}

#orderDetailsModal {
    z-index: 2100 !important;
    pointer-events: auto !important;
}

#orderDetailsModal.show {
    display: block !important;
    pointer-events: auto !important;
}

#orderDetailsModal .modal-dialog,
#orderDetailsModal .modal-dialog.order-details-wide-modal,
#orderDetailsModal.show .modal-dialog.order-details-wide-modal {
    width: min(1460px, calc(100vw - 16px)) !important;
    max-width: min(1460px, calc(100vw - 16px)) !important;
    margin: 8px auto !important;
    height: auto !important;
    max-height: calc(100dvh - 16px) !important;
    transform: none !important;
    pointer-events: auto !important;
}

#orderDetailsModal .modal-content {
    max-height: calc(100dvh - 16px) !important;
    border-radius: 16px !important;
    pointer-events: auto !important;
}

#orderDetailsModal .modal-header {
    height: 50px !important;
    min-height: 50px !important;
    flex-basis: 50px !important;
    pointer-events: auto !important;
}

#orderDetailsModal .modal-body,
#orderDetailsModal #orderDetailsContent {
    max-height: calc(100dvh - 66px) !important;
    overflow-y: auto !important;
    overflow-x: hidden !important;
    pointer-events: auto !important;
}

#orderDetailsModal .op-invoice-form-view,
#orderDetailsModal .op-invoice-main,
#orderDetailsModal .op-invoice-sheet-head,
#orderDetailsModal .op-lower-area,
#orderDetailsModal .op-detail-footer,
#orderDetailsModal .op-details-action-bar,
#orderDetailsModal .op-invoice-actions {
    pointer-events: auto !important;
}

#orderDetailsModal .op-invoice-main {
    max-width: 1418px !important;
    padding: 12px 18px 14px !important;
}

#orderDetailsModal .op-invoice-sheet-head {
    grid-template-columns: minmax(390px, .86fr) minmax(760px, 1.14fr) !important;
    gap: 14px !important;
}

#orderDetailsModal .op-right-fields {
    grid-template-columns: repeat(4, minmax(160px, 1fr)) !important;
    gap: 8px 12px !important;
}

#orderDetailsModal .op-detail-control,
#orderDetailsModal .op-detail-select {
    height: 34px !important;
    min-height: 34px !important;
    font-size: .9rem !important;
    pointer-events: auto !important;
}

#orderDetailsModal .op-lower-area {
    grid-template-columns: minmax(660px, 1fr) minmax(390px, 420px) !important;
    gap: 16px !important;
}

#orderDetailsModal .op-summary-box {
    min-width: 390px !important;
    max-width: 420px !important;
}

#orderDetailsModal .op-detail-footer,
#orderDetailsModal .op-details-action-bar,
#orderDetailsModal .op-invoice-actions {
    display: flex !important;
    flex-direction: row !important;
    align-items: center !important;
    justify-content: flex-end !important;
    flex-wrap: nowrap !important;
    gap: 10px !important;
}

#orderDetailsModal button,
#orderDetailsModal .btn,
#orderDetailsModal .btn-close,
#orderDetailsModal .op-detail-footer .btn,
#orderDetailsModal .op-details-action-bar .btn,
#orderDetailsModal .op-invoice-actions .btn {
    position: relative !important;
    z-index: 5 !important;
    pointer-events: auto !important;
    cursor: pointer !important;
}

#orderDetailsModal .op-detail-footer .btn,
#orderDetailsModal .op-details-action-bar .btn,
#orderDetailsModal .op-invoice-actions .btn {
    min-width: 126px !important;
    height: 36px !important;
    min-height: 36px !important;
    white-space: nowrap !important;
    flex: 0 0 auto !important;
}

#orderDetailsModal .modal-header .btn-close,
#orderDetailsModal .btn-close {
    z-index: 20 !important;
}

@media (max-width: 1200px) {
    #orderDetailsModal .op-invoice-sheet-head,
    #orderDetailsModal .op-lower-area {
        grid-template-columns: 1fr !important;
    }
    #orderDetailsModal .op-right-fields {
        grid-template-columns: repeat(2, minmax(160px, 1fr)) !important;
    }
    #orderDetailsModal .op-summary-box {
        max-width: none !important;
        width: 100% !important;
        min-width: 0 !important;
    }
}
</style>

<script id="amgc-order-details-click-safe-only-final-script">
(function () {
    function getOrderDetailsModal() {
        return document.getElementById('orderDetailsModal');
    }

    function cleanupOrderDetailsState() {
        var modalEl = getOrderDetailsModal();
        var isOrderDetailsOpen = modalEl && modalEl.classList.contains('show');
        if (!isOrderDetailsOpen) {
            document.body.classList.remove('amgc-order-details-open');
        }
    }

    function closeOrderDetailsModalSafely() {
        var modalEl = getOrderDetailsModal();
        if (!modalEl) return;
        try {
            var instance = bootstrap.Modal.getInstance(modalEl) || bootstrap.Modal.getOrCreateInstance(modalEl, {
                backdrop: true,
                keyboard: true,
                focus: true
            });
            instance.hide();
        } catch (err) {
            modalEl.classList.remove('show');
            modalEl.style.display = 'none';
            modalEl.setAttribute('aria-hidden', 'true');
            modalEl.removeAttribute('aria-modal');
            modalEl.removeAttribute('role');
            document.body.classList.remove('modal-open', 'amgc-order-details-open');
            document.body.style.removeProperty('overflow');
            document.body.style.removeProperty('padding-right');
            document.querySelectorAll('.modal-backdrop').forEach(function (backdrop) {
                backdrop.remove();
            });
        }
    }

    window.amgcCloseOrderDetailsModal = closeOrderDetailsModalSafely;

    document.addEventListener('shown.bs.modal', function (event) {
        if (event.target && event.target.id === 'orderDetailsModal') {
            document.body.classList.add('amgc-order-details-open');
        }
    });

    document.addEventListener('hidden.bs.modal', function (event) {
        if (event.target && event.target.id === 'orderDetailsModal') {
            window.setTimeout(function () {
                document.body.classList.remove('amgc-order-details-open');
                if (!document.querySelector('.modal.show')) {
                    document.body.classList.remove('modal-open');
                    document.body.style.removeProperty('overflow');
                    document.body.style.removeProperty('padding-right');
                    document.querySelectorAll('.modal-backdrop').forEach(function (backdrop) {
                        backdrop.remove();
                    });
                }
            }, 80);
        }
    });

    document.addEventListener('click', function (event) {
        var modalEl = getOrderDetailsModal();
        if (!modalEl || !modalEl.classList.contains('show')) return;

        var closeBtn = event.target.closest('#orderDetailsModal .btn-close, #orderDetailsModal .op-detail-footer .btn-light, #orderDetailsModal [data-amgc-close-order-details]');
        if (!closeBtn) return;

        event.preventDefault();
        event.stopPropagation();
        closeOrderDetailsModalSafely();
    }, true);

    window.setInterval(cleanupOrderDetailsState, 1000);
})();


// ===== AMGC Journal Auto Open/Edit Patch =====
(function(){
    const params = new URLSearchParams(window.location.search);
    if (params.get('from_journal_entries') !== '1' && params.get('journal_edit') !== '1') return;
    function run(){
        const id = params.get('so_id') || params.get('sales_order_id') || params.get('source_id') || params.get('invoice_id') || '';
        if(!id) return;
        if (typeof editOrder === 'function') { editOrder(id); return; }
        if (typeof editOrderFromOrderProduct === 'function') { editOrderFromOrderProduct(id); return; }
        if (window.Swal) Swal.fire('Transaction opened','Source page opened but edit function was not ready.','info');
    }
    document.addEventListener('DOMContentLoaded', ()=>setTimeout(run, 900));
})();

</script>

<!-- AMGC PATCH: SI Attachment Preview modal must always appear above Invoice Details modal -->
<style id="amgc-si-attachment-modal-on-top-final">
/* Keep Invoice Details open, but make SI Attachment Preview the front modal. */
#siAttachmentPreviewModal {
    z-index: 2400 !important;
    pointer-events: auto !important;
}

#siAttachmentPreviewModal.show {
    display: block !important;
    pointer-events: auto !important;
}

#siAttachmentPreviewModal .modal-dialog {
    z-index: 2405 !important;
    pointer-events: auto !important;
}

#siAttachmentPreviewModal .modal-content,
#siAttachmentPreviewModal .modal-header,
#siAttachmentPreviewModal .modal-body,
#siAttachmentPreviewModal .modal-footer,
#siAttachmentPreviewModal button,
#siAttachmentPreviewModal .btn,
#siAttachmentPreviewModal iframe,
#siAttachmentPreviewModal img {
    pointer-events: auto !important;
}

/* Backdrop created for the SI Attachment modal should sit above Invoice Details but below SI modal. */
body.amgc-si-attachment-open .modal-backdrop:last-of-type,
body.amgc-si-attachment-open .modal-backdrop.show:last-of-type {
    z-index: 2390 !important;
    pointer-events: auto !important;
}

/* Invoice Details stays underneath while attachment preview is open. */
body.amgc-si-attachment-open #orderDetailsModal {
    z-index: 2100 !important;
}
</style>

<script id="amgc-si-attachment-modal-on-top-final-script">
(function () {
    function setSIAttachmentModalOnTop() {
        var modalEl = document.getElementById('siAttachmentPreviewModal');
        if (!modalEl) return;

        modalEl.style.zIndex = '2400';
        document.body.classList.add('amgc-si-attachment-open');

        window.setTimeout(function () {
            var backdrops = document.querySelectorAll('.modal-backdrop');
            if (backdrops.length) {
                backdrops[backdrops.length - 1].style.zIndex = '2390';
            }
            modalEl.style.zIndex = '2400';
        }, 10);
    }

    function restoreInvoiceDetailsAfterAttachmentClose() {
        document.body.classList.remove('amgc-si-attachment-open');
        var orderModal = document.getElementById('orderDetailsModal');
        if (orderModal && orderModal.classList.contains('show')) {
            document.body.classList.add('modal-open', 'amgc-order-details-open');
            document.body.style.overflow = 'hidden';
        }
    }

        if (event.target && event.target.id === 'siAttachmentPreviewModal') {
            setSIAttachmentModalOnTop();
        }
    });

    document.addEventListener('shown.bs.modal', function (event) {
        if (event.target && event.target.id === 'siAttachmentPreviewModal') {
            setSIAttachmentModalOnTop();
        }
    });

    document.addEventListener('hidden.bs.modal', function (event) {
        if (event.target && event.target.id === 'siAttachmentPreviewModal') {
            window.setTimeout(restoreInvoiceDetailsAfterAttachmentClose, 30);
        }
    });
})();
</script>
