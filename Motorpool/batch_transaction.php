<?php
ob_start();

require_once '../config/database.php';
require_once '../config/session_handler.php';

if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Cache-Control: post-check=0, pre-check=0', false);
    header('Pragma: no-cache');
    header('Expires: 0');
}

$user_id = (int)($_SESSION['user_id'] ?? 0);
$user_role_raw = strtolower(trim((string)($_SESSION['role'] ?? '')));

if ($user_id <= 0) {
    header('Location: ../login.php');
    exit();
}

if ($user_role_raw !== 'motorpool') {
    if ($user_role_raw === 'motorpool') {
        header('Location: ../Branch_Admin/branchdashboard.php');
    } elseif ($user_role_raw === 'admin') {
        header('Location: ../Admin/dashboard.php');
    } else {
        header('Location: ../login.php');
    }
    exit();
}

$user_name = trim((string)($_SESSION['first_name'] ?? '') . ' ' . (string)($_SESSION['last_name'] ?? ''));
if ($user_name === '') {
    $user_name = 'Motorpool Account';
}
$user_role = 'motorpool';
$view_all_branches = false;
$_SESSION['view_all_branches'] = false;
$_SESSION['view_all_branhes'] = false;

function batch_motorpool_table_exists(mysqli $conn, string $table): bool {
    $table = $conn->real_escape_string($table);
    $res = $conn->query("SHOW TABLES LIKE '{$table}'");
    return $res && $res->num_rows > 0;
}

function batch_motorpool_column_exists(mysqli $conn, string $table, string $column): bool {
    if (!batch_motorpool_table_exists($conn, $table)) return false;
    $table = preg_replace('/[^A-Za-z0-9_]/', '', $table);
    $column = $conn->real_escape_string($column);
    $res = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
    return $res && $res->num_rows > 0;
}

function batch_get_motorpool_branch_context(mysqli $conn): array {
    $branchName = 'Motorpool';
    if (batch_motorpool_table_exists($conn, 'branches')) {
        $res = $conn->query("SELECT branch_id, branch_name FROM branches WHERE LOWER(TRIM(branch_name)) = 'motorpool' OR LOWER(TRIM(branch_name)) LIKE '%motorpool%' ORDER BY CASE WHEN LOWER(TRIM(branch_name)) = 'motorpool' THEN 0 ELSE 1 END, branch_id ASC LIMIT 1");
        if ($res && ($row = $res->fetch_assoc())) {
            return [(int)$row['branch_id'], trim((string)$row['branch_name']) ?: $branchName];
        }
        if (batch_motorpool_column_exists($conn, 'branches', 'branch_name')) {
            $stmt = $conn->prepare("INSERT INTO branches (branch_name) VALUES (?)");
            if ($stmt) {
                $stmt->bind_param('s', $branchName);
                if (@$stmt->execute()) {
                    $newId = (int)$conn->insert_id;
                    $stmt->close();
                    return [$newId, $branchName];
                }
                $stmt->close();
            }
        }
    }
    $fallback = (int)($_SESSION['branch_id'] ?? 0);
    return [$fallback, $branchName];
}

[$branch_id, $branch_name] = batch_get_motorpool_branch_context($conn);
$_SESSION['branch_id'] = $branch_id;
$view_all_brancher = false;

$name_parts = preg_split('/\s+/', trim($user_name));
$user_initials = '';
foreach ($name_parts as $part) {
    if ($part !== '') {
        $user_initials .= strtoupper(substr($part, 0, 1));
    }
    if (strlen($user_initials) >= 2) {
        break;
    }
}
$user_initials = $user_initials ?: 'MA';


// Fetch Chart of Accounts options for Batch Transaction account dropdowns
$view_all_branches = $_SESSION['view_all_branches'] ?? ($_SESSION['view_all_branhes'] ?? false);
$batch_accounts_by_type = [
    'Bank' => [],
    'Credit Card' => [],
    'Accounts Payable' => [],
    'Accounts Receivable' => []
];
$batch_all_account_options = [];

$chart_table_exists = false;
$check_chart_table = $conn->query("SHOW TABLES LIKE 'chart_of_accounts'");
if ($check_chart_table && $check_chart_table->num_rows > 0) {
    $chart_table_exists = true;
}

if ($chart_table_exists) {
    $wanted_types = array_keys($batch_accounts_by_type);
    $placeholders = implode(',', array_fill(0, count($wanted_types), '?'));
    $chart_sql = "SELECT account_id, branch_id, account_code, account_title, account_type, parent_account_id, description, balance
                  FROM chart_of_accounts
                  WHERE status = 'active'
                    AND account_type IN ($placeholders)";

    $params = $wanted_types;
    $types = str_repeat('s', count($wanted_types));

    if (!$view_all_branches && (int)$branch_id > 0) {
        $chart_sql .= " AND (branch_id = ? OR branch_id = 0 OR branch_id IS NULL)";
        $params[] = (int)$branch_id;
        $types .= 'i';
    }

    $chart_sql .= " ORDER BY account_type ASC, account_code ASC, account_title ASC";
    $stmt = $conn->prepare($chart_sql);
    if ($stmt) {
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $account_type = trim((string)($row['account_type'] ?? ''));
            if (!array_key_exists($account_type, $batch_accounts_by_type)) {
                continue;
            }

            $account_code = trim((string)($row['account_code'] ?? ''));
            $account_title = trim((string)($row['account_title'] ?? ''));
            if ($account_title === '') {
                continue;
            }

            $label = $account_code !== '' ? $account_code . ' · ' . $account_title : $account_title;
            $batch_accounts_by_type[$account_type][] = [
                'id' => (int)($row['account_id'] ?? 0),
                'label' => $label,
                'title' => $account_title,
                'code' => $account_code,
                'type' => $account_type,
                'parent_account_id' => isset($row['parent_account_id']) ? (int)$row['parent_account_id'] : 0,
                'description' => trim((string)($row['description'] ?? '')),
                'balance' => isset($row['balance']) ? (float)$row['balance'] : 0.00
            ];
        }

        $stmt->close();
    }
}

if ($chart_table_exists) {
    $all_chart_sql = "SELECT account_id, branch_id, account_code, account_title, account_type, parent_account_id, description, balance
                      FROM chart_of_accounts
                      WHERE status = 'active'";
    $all_params = [];
    $all_types = '';

    if (!$view_all_branches && (int)$branch_id > 0) {
        $all_chart_sql .= " AND (branch_id = ? OR branch_id = 0 OR branch_id IS NULL)";
        $all_params[] = (int)$branch_id;
        $all_types .= 'i';
    }

    $all_chart_sql .= " ORDER BY account_type ASC, account_code ASC, account_title ASC";
    $all_stmt = $conn->prepare($all_chart_sql);
    if ($all_stmt) {
        if ($all_types !== '') {
            $all_stmt->bind_param($all_types, ...$all_params);
        }
        $all_stmt->execute();
        $all_result = $all_stmt->get_result();
        while ($row = $all_result->fetch_assoc()) {
            $account_code = trim((string)($row['account_code'] ?? ''));
            $account_title = trim((string)($row['account_title'] ?? ''));
            $account_type = trim((string)($row['account_type'] ?? ''));
            if ($account_title === '') {
                continue;
            }

            $label = $account_code !== '' ? $account_code . ' · ' . $account_title : $account_title;
            $batch_all_account_options[] = [
                'id' => (int)($row['account_id'] ?? 0),
                'label' => $label,
                'title' => $account_title,
                'code' => $account_code,
                'type' => $account_type,
                'parent_account_id' => isset($row['parent_account_id']) ? (int)$row['parent_account_id'] : 0,
                'description' => trim((string)($row['description'] ?? '')),
                'balance' => isset($row['balance']) ? (float)$row['balance'] : 0.00
            ];
        }
        $all_stmt->close();
    }
}

function batch_table_exists($conn, $table) {
    if (!$conn) return false;
    $table = $conn->real_escape_string($table);
    $res = $conn->query("SHOW TABLES LIKE '{$table}'");
    return $res && $res->num_rows > 0;
}

function batch_column_exists($conn, $table, $column) {
    if (!$conn || !batch_table_exists($conn, $table)) return false;
    $table = $conn->real_escape_string($table);
    $column = $conn->real_escape_string($column);
    $res = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
    return $res && $res->num_rows > 0;
}


function batch_document_date_key($dateValue = '') {
    $dateValue = trim((string)$dateValue);
    if ($dateValue === '') {
        return date('Ymd');
    }

    $time = strtotime($dateValue);
    return $time ? date('Ymd', $time) : date('Ymd');
}

function batch_document_suffix_from_input($value) {
    $value = trim((string)$value);
    if ($value === '') {
        return '';
    }

    if (preg_match('/(\d+)$/', $value, $matches)) {
        return $matches[1];
    }

    $digits = preg_replace('/\D+/', '', $value);
    return trim((string)$digits);
}

function batch_format_manual_document_number($prefix, $dateValue, $manualValue, $paddingLength = 5) {
    $prefix = strtoupper(preg_replace('/[^A-Z0-9]/i', '', (string)$prefix));
    $dateKey = batch_document_date_key($dateValue);
    $suffix = batch_document_suffix_from_input($manualValue);

    if ($suffix === '') {
        return '';
    }

    return $prefix . '-' . $dateKey . '-' . str_pad(substr($suffix, -$paddingLength), $paddingLength, '0', STR_PAD_LEFT);
}

function batch_next_document_number_by_date($conn, $table, $column, $prefix, $dateValue, &$counters, $paddingLength = 5) {
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$table);
    $column = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$column);
    $prefix = strtoupper(preg_replace('/[^A-Z0-9]/i', '', (string)$prefix));
    $dateKey = batch_document_date_key($dateValue);

    if ($table === '' || $column === '' || $prefix === '') {
        return $prefix . '-' . $dateKey . '-' . str_pad((string)mt_rand(1, (10 ** min($paddingLength, 6)) - 1), $paddingLength, '0', STR_PAD_LEFT);
    }

    $base = $prefix . '-' . $dateKey . '-';
    $counterKey = $table . '|' . $column . '|' . $base . '|' . $paddingLength;

    if (!isset($counters[$counterKey])) {
        $lastNumber = '';
        if (batch_table_exists($conn, $table) && batch_column_exists($conn, $table, $column)) {
            $like = $base . '%';
            $sql = "SELECT `{$column}` AS document_number
                    FROM `{$table}`
                    WHERE `{$column}` LIKE ?
                    ORDER BY `{$column}` DESC
                    LIMIT 1";
            $stmt = $conn->prepare($sql);
            if ($stmt) {
                $stmt->bind_param('s', $like);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                $lastNumber = trim((string)($row['document_number'] ?? ''));
            }
        }

        $lastSequence = 0;
        if ($lastNumber !== '' && preg_match('/-(\d+)$/', $lastNumber, $matches)) {
            $lastSequence = (int)$matches[1];
        }
        $counters[$counterKey] = $lastSequence;
    }

    $counters[$counterKey]++;
    return $base . str_pad((string)$counters[$counterKey], $paddingLength, '0', STR_PAD_LEFT);
}

function batch_next_document_number($conn, $table, $column, $prefix, &$counters) {
    return batch_next_document_number_by_date($conn, $table, $column, $prefix, date('Y-m-d'), $counters, 5);
}

function batch_peek_next_document_number($conn, $table, $column, $prefix) {
    $previewCounters = [];
    return batch_next_document_number_by_date($conn, $table, $column, $prefix, date('Y-m-d'), $previewCounters, 5);
}

function batch_document_number_exists($conn, $table, $column, $number, $excludeColumn = '', $excludeId = 0) {
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$table);
    $column = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$column);
    $excludeColumn = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$excludeColumn);
    $number = trim((string)$number);

    if ($table === '' || $column === '' || $number === '' || !batch_table_exists($conn, $table) || !batch_column_exists($conn, $table, $column)) {
        return false;
    }

    $sql = "SELECT 1 FROM `{$table}` WHERE `{$column}` = ?";
    $params = [$number];
    $types = 's';

    if ($excludeColumn !== '' && (int)$excludeId > 0 && batch_column_exists($conn, $table, $excludeColumn)) {
        $sql .= " AND `{$excludeColumn}` <> ?";
        $params[] = (int)$excludeId;
        $types .= 'i';
    }

    $sql .= " LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $exists = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $exists;
}

function batch_create_sales_order_for_invoice($conn, $soNumber, $siNumber, $customerId, $branchId, $orderDate, $amount, $userId, $remarks = '') {
    if (!batch_table_exists($conn, 'sales_orders')) {
        return 0;
    }

    $soNumber = trim((string)$soNumber);
    $siNumber = trim((string)$siNumber);
    if ($soNumber === '') {
        return 0;
    }

    if (batch_document_number_exists($conn, 'sales_orders', 'so_number', $soNumber)) {
        throw new Exception('SO number already exists: ' . $soNumber);
    }

    if ($siNumber !== '' && batch_column_exists($conn, 'sales_orders', 'si_number') && batch_document_number_exists($conn, 'sales_orders', 'si_number', $siNumber)) {
        throw new Exception('SI number already exists: ' . $siNumber);
    }

    $orderDateTime = date('Y-m-d H:i:s', strtotime((string)$orderDate));
    $insertData = [];
    $insertData['so_number'] = $soNumber;
    if (batch_column_exists($conn, 'sales_orders', 'si_number')) {
        $insertData['si_number'] = $siNumber !== '' ? $siNumber : null;
    }
    if (batch_column_exists($conn, 'sales_orders', 'document_type')) {
        $insertData['document_type'] = $siNumber !== '' ? 'SI' : 'SO';
    }
    $insertData['customer_id'] = (int)$customerId;
    if (batch_column_exists($conn, 'sales_orders', 'branch_id')) {
        $insertData['branch_id'] = (int)$branchId;
    }
    $insertData['order_date'] = $orderDateTime;
    $insertData['total_amount'] = (float)$amount;
    if (batch_column_exists($conn, 'sales_orders', 'order_amount')) {
        $insertData['order_amount'] = (float)$amount;
    }
    if (batch_column_exists($conn, 'sales_orders', 'order_status')) {
        $insertData['order_status'] = 'pending';
    }
    if (batch_column_exists($conn, 'sales_orders', 'status')) {
        $insertData['status'] = 'pending';
    }
    if (batch_column_exists($conn, 'sales_orders', 'payment_status')) {
        $insertData['payment_status'] = 'unpaid';
    }
    if (batch_column_exists($conn, 'sales_orders', 'remarks')) {
        $insertData['remarks'] = $remarks;
    }
    if (batch_column_exists($conn, 'sales_orders', 'created_by')) {
        $insertData['created_by'] = (int)$userId;
    }
    if (batch_column_exists($conn, 'sales_orders', 'created_at')) {
        $insertData['created_at'] = date('Y-m-d H:i:s');
    }

    $columns = array_keys($insertData);
    $placeholders = implode(',', array_fill(0, count($columns), '?'));
    $sql = "INSERT INTO sales_orders (`" . implode('`,`', $columns) . "`) VALUES ($placeholders)";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception('Failed to prepare sales order insert: ' . $conn->error);
    }

    $types = '';
    $values = [];
    foreach ($insertData as $value) {
        if (is_int($value)) {
            $types .= 'i';
        } elseif (is_float($value)) {
            $types .= 'd';
        } else {
            $types .= 's';
        }
        $values[] = $value;
    }

    $stmt->bind_param($types, ...$values);
    if (!$stmt->execute()) {
        throw new Exception('Failed to save sales order number: ' . $stmt->error);
    }
    $soId = (int)$stmt->insert_id;
    $stmt->close();
    return $soId;
}

function batch_get_customer_terms_from_credit_requests($conn, $customerId) {
    $customerId = (int)$customerId;
    if ($customerId <= 0 || !batch_table_exists($conn, 'credit_discount_requests')) {
        return '';
    }

    if (!batch_column_exists($conn, 'credit_discount_requests', 'credit_terms_days')) {
        return '';
    }

    $orderBy = '';
    if (batch_column_exists($conn, 'credit_discount_requests', 'approved_at')) {
        $orderBy .= ' approved_at DESC,';
    }
    if (batch_column_exists($conn, 'credit_discount_requests', 'request_id')) {
        $orderBy .= ' request_id DESC,';
    }
    $orderBy = trim($orderBy, ', ');
    if ($orderBy === '') {
        $orderBy = 'credit_terms_days DESC';
    }

    $sql = "SELECT credit_terms_days
            FROM credit_discount_requests
            WHERE customer_id = ?
              AND credit_terms_days IS NOT NULL
              AND credit_terms_days > 0";

    if (batch_column_exists($conn, 'credit_discount_requests', 'status')) {
        $sql .= " AND LOWER(status) = 'approved'";
    }

    if (batch_column_exists($conn, 'credit_discount_requests', 'request_type')) {
        $sql .= " AND LOWER(request_type) IN ('credit_terms', 'both', 'credit')";
    }

    if (batch_column_exists($conn, 'credit_discount_requests', 'effective_until')) {
        $sql .= " AND (effective_until IS NULL OR effective_until = '0000-00-00' OR effective_until >= CURDATE())";
    }

    $sql .= " ORDER BY {$orderBy} LIMIT 1";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return '';
    }

    $stmt->bind_param('i', $customerId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $days = (int)($row['credit_terms_days'] ?? 0);
    return $days > 0 ? 'Net ' . $days : '';
}

function batch_get_check_payee_options($conn, $view_all_branches, $branch_id) {
    $options = [];
    $seen = [];

    $formatRole = function($role) {
        $role = trim((string)$role);
        if ($role === '') return '';
        $roleKey = strtolower(str_replace(['-', ' '], '_', $role));
        $roleLabels = [
            'delivery' => 'Driver',
            'driver' => 'Driver',
            'sales' => 'Sales',
            'warehouse' => 'Warehouse',
            'warehouseman' => 'Warehouseman',
            'rolling' => 'Rolling',
            'motorpool' => 'Motorpool',
            'global' => 'Global'
        ];
        return $roleLabels[$roleKey] ?? ucwords(str_replace('_', ' ', $roleKey));
    };

    $isAdminRole = function($role) {
        $roleKey = strtolower(str_replace(['-', ' '], '_', trim((string)$role)));
        return in_array($roleKey, ['admin', 'motorpool', 'super_duper_admin'], true) || str_contains($roleKey, 'admin');
    };

    $addOption = function($type, $id, $name, $address = '', $role = '', $customerGroup = '') use (&$options, &$seen, $formatRole, $isAdminRole) {
        $type = trim((string)$type);
        $name = trim((string)$name);
        $role = trim((string)$role);
        $customerGroup = trim((string)$customerGroup);
        if ($name === '') return;
        if (strcasecmp($type, 'Employee') === 0 && $isAdminRole($role)) return;

        $key = strtolower($type . '|' . $name . '|' . $role . '|' . $customerGroup);
        if (isset($seen[$key])) return;
        $seen[$key] = true;

        $roleLabel = (strcasecmp($type, 'Employee') === 0) ? $formatRole($role) : '';
        $badgeLabel = '';
        if (strcasecmp($type, 'Customer') === 0) {
            $badgeLabel = $customerGroup !== '' ? $customerGroup : 'No Group';
        } elseif (strcasecmp($type, 'Employee') === 0) {
            $badgeLabel = $roleLabel !== '' ? $roleLabel : 'Employee';
        } else {
            $badgeLabel = $type;
        }

        $options[] = [
            'type' => $type,
            'id' => (int)$id,
            'name' => $name,
            'address' => trim((string)$address),
            'role' => $roleLabel,
            'customer_group' => $customerGroup,
            'badge' => $badgeLabel,
            'label' => $name
        ];
    };

    if (batch_table_exists($conn, 'suppliers')) {
        $nameColumn = batch_column_exists($conn, 'suppliers', 'supplier_name') ? 'supplier_name' : (batch_column_exists($conn, 'suppliers', 'name') ? 'name' : '');
        if ($nameColumn !== '') {
            $addressParts = [];
            foreach (['full_address', 'address', 'street_address'] as $col) {
                if (batch_column_exists($conn, 'suppliers', $col)) $addressParts[] = "NULLIF(TRIM(`{$col}`), '')";
            }
            $addressSelect = !empty($addressParts) ? "COALESCE(" . implode(', ', $addressParts) . ", '')" : "''";
            $sql = "SELECT supplier_id AS id, `{$nameColumn}` AS name, {$addressSelect} AS address FROM suppliers WHERE TRIM(COALESCE(`{$nameColumn}`, '')) <> ''";
            if (batch_column_exists($conn, 'suppliers', 'status')) {
                $sql .= " AND (status IS NULL OR status = '' OR LOWER(status) = 'active')";
            }
            if (!$view_all_branches && (int)$branch_id > 0 && batch_column_exists($conn, 'suppliers', 'branch_id')) {
                $sql .= " AND (branch_id = ? OR branch_id = 0 OR branch_id IS NULL)";
            }
            $sql .= " ORDER BY `{$nameColumn}` ASC";
            $stmt = $conn->prepare($sql);
            if ($stmt) {
                if (!$view_all_branches && (int)$branch_id > 0 && batch_column_exists($conn, 'suppliers', 'branch_id')) {
                    $bid = (int)$branch_id;
                    $stmt->bind_param('i', $bid);
                }
                $stmt->execute();
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()) {
                    $addOption('Supplier', $row['id'] ?? 0, $row['name'] ?? '', $row['address'] ?? '');
                }
                $stmt->close();
            }
        }
    }

    if (batch_table_exists($conn, 'customers')) {
        $nameColumn = batch_column_exists($conn, 'customers', 'customer_name') ? 'customer_name' : (batch_column_exists($conn, 'customers', 'name') ? 'name' : '');
        if ($nameColumn !== '') {
            $addressParts = [];
            foreach (['full_address', 'address'] as $col) {
                if (batch_column_exists($conn, 'customers', $col)) $addressParts[] = "NULLIF(TRIM(`{$col}`), '')";
            }
            $addressSelect = !empty($addressParts) ? "COALESCE(" . implode(', ', $addressParts) . ", '')" : "''";
            $groupSelect = batch_column_exists($conn, 'customers', 'customer_group') ? ", customer_group" : ", '' AS customer_group";
            $sql = "SELECT customer_id AS id, `{$nameColumn}` AS name, {$addressSelect} AS address {$groupSelect} FROM customers WHERE TRIM(COALESCE(`{$nameColumn}`, '')) <> ''";
            if (batch_column_exists($conn, 'customers', 'status')) {
                $sql .= " AND (status IS NULL OR status = '' OR LOWER(status) = 'active')";
            }
            if (!$view_all_branches && (int)$branch_id > 0 && batch_column_exists($conn, 'customers', 'branch_id')) {
                $sql .= " AND branch_id = ?";
            }
            $sql .= " ORDER BY `{$nameColumn}` ASC";
            $stmt = $conn->prepare($sql);
            if ($stmt) {
                if (!$view_all_branches && (int)$branch_id > 0 && batch_column_exists($conn, 'customers', 'branch_id')) {
                    $bid = (int)$branch_id;
                    $stmt->bind_param('i', $bid);
                }
                $stmt->execute();
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()) {
                    $addOption('Customer', $row['id'] ?? 0, $row['name'] ?? '', $row['address'] ?? '', '', $row['customer_group'] ?? '');
                }
                $stmt->close();
            }
        }
    }

    if (batch_table_exists($conn, 'employees')) {
        $nameColumn = batch_column_exists($conn, 'employees', 'employee_name') ? 'employee_name' : (batch_column_exists($conn, 'employees', 'name') ? 'name' : '');
        if ($nameColumn !== '') {
            $roleColumn = '';
            foreach (['role', 'employee_role', 'position', 'designation', 'job_title'] as $candidateRoleColumn) {
                if (batch_column_exists($conn, 'employees', $candidateRoleColumn)) {
                    $roleColumn = $candidateRoleColumn;
                    break;
                }
            }
            $roleSelect = $roleColumn !== '' ? ", `{$roleColumn}` AS role_name" : ", '' AS role_name";
            $sql = "SELECT employee_id AS id, `{$nameColumn}` AS name, '' AS address {$roleSelect} FROM employees WHERE TRIM(COALESCE(`{$nameColumn}`, '')) <> ''";
            if (batch_column_exists($conn, 'employees', 'status')) {
                $sql .= " AND (status IS NULL OR status = '' OR LOWER(status) = 'active')";
            }
            if (!$view_all_branches && (int)$branch_id > 0 && batch_column_exists($conn, 'employees', 'branch_id')) {
                $sql .= " AND branch_id = ?";
            }
            $sql .= " ORDER BY `{$nameColumn}` ASC";
            $stmt = $conn->prepare($sql);
            if ($stmt) {
                if (!$view_all_branches && (int)$branch_id > 0 && batch_column_exists($conn, 'employees', 'branch_id')) {
                    $bid = (int)$branch_id;
                    $stmt->bind_param('i', $bid);
                }
                $stmt->execute();
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()) {
                    $addOption('Employee', $row['id'] ?? 0, $row['name'] ?? '', $row['address'] ?? '', $row['role_name'] ?? '');
                }
                $stmt->close();
            }
        }
    } elseif (batch_table_exists($conn, 'users')) {
        $roleSelect = batch_column_exists($conn, 'users', 'role') ? ', role AS role_name' : ", '' AS role_name";
        $sql = "SELECT user_id AS id, TRIM(CONCAT(COALESCE(first_name, ''), ' ', COALESCE(last_name, ''))) AS name, '' AS address {$roleSelect}
                FROM users
                WHERE TRIM(CONCAT(COALESCE(first_name, ''), ' ', COALESCE(last_name, ''))) <> ''";
        if (batch_column_exists($conn, 'users', 'role')) {
            $sql .= " AND LOWER(role) NOT IN ('admin', 'motorpool', 'super_duper_admin') AND LOWER(role) NOT LIKE '%admin%'";
        }
        if (batch_column_exists($conn, 'users', 'status')) {
            $sql .= " AND (status IS NULL OR status = '' OR LOWER(status) = 'active')";
        }
        if (!$view_all_branches && (int)$branch_id > 0 && batch_column_exists($conn, 'users', 'branch_id')) {
            $sql .= " AND branch_id = ?";
        }
        $sql .= " ORDER BY first_name ASC, last_name ASC";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            if (!$view_all_branches && (int)$branch_id > 0 && batch_column_exists($conn, 'users', 'branch_id')) {
                $bid = (int)$branch_id;
                $stmt->bind_param('i', $bid);
            }
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $addOption('Employee', $row['id'] ?? 0, $row['name'] ?? '', $row['address'] ?? '', $row['role_name'] ?? '');
            }
            $stmt->close();
        }
    }

    usort($options, function($a, $b) {
        $typeCompare = strcmp($a['type'], $b['type']);
        if ($typeCompare !== 0) return $typeCompare;
        return strcmp($a['name'], $b['name']);
    });

    return $options;
}

function batch_get_bill_vendor_options($conn, $view_all_branches, $branch_id) {
    $options = [];
    $seen = [];

    if (!batch_table_exists($conn, 'suppliers')) {
        return $options;
    }

    $nameColumn = batch_column_exists($conn, 'suppliers', 'supplier_name') ? 'supplier_name' : (batch_column_exists($conn, 'suppliers', 'name') ? 'name' : '');
    if ($nameColumn === '') {
        return $options;
    }

    $selectParts = ["supplier_id AS id", "`{$nameColumn}` AS name"];
    $selectParts[] = batch_column_exists($conn, 'suppliers', 'supplier_code') ? "supplier_code" : "'' AS supplier_code";
    $selectParts[] = batch_column_exists($conn, 'suppliers', 'payment_terms') ? "payment_terms" : "'' AS payment_terms";

    $addressParts = [];
    foreach (['full_address', 'address', 'street_address'] as $col) {
        if (batch_column_exists($conn, 'suppliers', $col)) {
            $addressParts[] = "NULLIF(TRIM(`{$col}`), '')";
        }
    }
    $selectParts[] = !empty($addressParts) ? "COALESCE(" . implode(', ', $addressParts) . ", '') AS address" : "'' AS address";

    $sql = "SELECT " . implode(', ', $selectParts) . "
            FROM suppliers
            WHERE TRIM(COALESCE(`{$nameColumn}`, '')) <> ''";

    if (batch_column_exists($conn, 'suppliers', 'status')) {
        $sql .= " AND (status IS NULL OR status = '' OR LOWER(status) = 'active')";
    }

    $hasBranchFilter = (!$view_all_branches && (int)$branch_id > 0 && batch_column_exists($conn, 'suppliers', 'branch_id'));
    if ($hasBranchFilter) {
        $sql .= " AND (branch_id = ? OR branch_id = 0 OR branch_id IS NULL)";
    }

    $sql .= " ORDER BY `{$nameColumn}` ASC";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return $options;
    }

    if ($hasBranchFilter) {
        $bid = (int)$branch_id;
        $stmt->bind_param('i', $bid);
    }

    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $name = trim((string)($row['name'] ?? ''));
        if ($name === '') {
            continue;
        }

        $key = strtolower($name);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;

        $options[] = [
            'type' => 'Supplier',
            'id' => (int)($row['id'] ?? 0),
            'name' => $name,
            'label' => $name,
            'supplier_code' => trim((string)($row['supplier_code'] ?? '')),
            'payment_terms' => trim((string)($row['payment_terms'] ?? '')),
            'address' => trim((string)($row['address'] ?? ''))
        ];
    }
    $stmt->close();

    return $options;
}


function batch_get_invoice_customer_options($conn, $view_all_branches, $branch_id) {
    $options = [];
    $seen = [];

    if (!batch_table_exists($conn, 'customers')) {
        return $options;
    }

    $nameColumn = batch_column_exists($conn, 'customers', 'customer_name') ? 'customer_name' : (batch_column_exists($conn, 'customers', 'name') ? 'name' : '');
    if ($nameColumn === '') {
        return $options;
    }

    $selectParts = ["customer_id AS id", "`{$nameColumn}` AS name"];
    $selectParts[] = batch_column_exists($conn, 'customers', 'customer_group') ? "customer_group" : "'' AS customer_group";
    $selectParts[] = batch_column_exists($conn, 'customers', 'price_level') ? "price_level" : "'' AS price_level";
    $selectParts[] = batch_column_exists($conn, 'customers', 'email') ? "email" : "'' AS email";
    $selectParts[] = batch_column_exists($conn, 'customers', 'phone_number') ? "phone_number" : "'' AS phone_number";

    $termsColumn = '';
    foreach (['payment_terms', 'terms', 'customer_terms', 'credit_terms'] as $candidateTermsColumn) {
        if (batch_column_exists($conn, 'customers', $candidateTermsColumn)) {
            $termsColumn = $candidateTermsColumn;
            break;
        }
    }
    $selectParts[] = $termsColumn !== '' ? "`{$termsColumn}` AS payment_terms" : "'' AS payment_terms";

    $addressParts = [];
    foreach (['full_address', 'address', 'business_address'] as $col) {
        if (batch_column_exists($conn, 'customers', $col)) {
            $addressParts[] = "NULLIF(TRIM(`{$col}`), '')";
        }
    }
    $selectParts[] = !empty($addressParts) ? "COALESCE(" . implode(', ', $addressParts) . ", '') AS address" : "'' AS address";

    $sql = "SELECT " . implode(', ', $selectParts) . "
            FROM customers
            WHERE TRIM(COALESCE(`{$nameColumn}`, '')) <> ''";

    if (batch_column_exists($conn, 'customers', 'status')) {
        $sql .= " AND (status IS NULL OR status = '' OR LOWER(status) = 'active')";
    }

    $hasBranchFilter = (!$view_all_branches && (int)$branch_id > 0 && batch_column_exists($conn, 'customers', 'branch_id'));
    if ($hasBranchFilter) {
        $sql .= " AND branch_id = ?";
    }

    $sql .= " ORDER BY `{$nameColumn}` ASC";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return $options;
    }

    if ($hasBranchFilter) {
        $bid = (int)$branch_id;
        $stmt->bind_param('i', $bid);
    }

    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $name = trim((string)($row['name'] ?? ''));
        if ($name === '') {
            continue;
        }

        $key = strtolower($name . '|' . ($row['customer_group'] ?? ''));
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;

        $group = trim((string)($row['customer_group'] ?? ''));
        $paymentTerms = trim((string)($row['payment_terms'] ?? ''));
        if ($paymentTerms === '') {
            $paymentTerms = batch_get_customer_terms_from_credit_requests($conn, (int)($row['id'] ?? 0));
        }
        $options[] = [
            'type' => 'Customer',
            'id' => (int)($row['id'] ?? 0),
            'name' => $name,
            'label' => $name,
            'customer_group' => $group,
            'badge' => $group !== '' ? $group : 'No Group',
            'price_level' => trim((string)($row['price_level'] ?? '')),
            'payment_terms' => $paymentTerms,
            'terms' => $paymentTerms,
            'email' => trim((string)($row['email'] ?? '')),
            'phone_number' => trim((string)($row['phone_number'] ?? '')),
            'address' => trim((string)($row['address'] ?? ''))
        ];
    }
    $stmt->close();

    return $options;
}

function batch_get_invoice_item_options($conn, $view_all_branches, $branch_id) {
    $options = [];
    $seen = [];

    if (!batch_table_exists($conn, 'items')) {
        return $options;
    }

    $columns = [];
    $colsResult = $conn->query("SHOW COLUMNS FROM items");
    if ($colsResult) {
        while ($col = $colsResult->fetch_assoc()) {
            if (!empty($col['Field'])) {
                $columns[] = $col['Field'];
            }
        }
    }

    $hasColumn = function($column) use ($columns) {
        return in_array($column, $columns, true);
    };

    $selectParts = [];
    $selectParts[] = $hasColumn('item_id') ? 'item_id AS id' : '0 AS id';
    $selectParts[] = $hasColumn('item_name') ? 'item_name AS name' : ($hasColumn('name') ? 'name' : "'' AS name");
    $selectParts[] = $hasColumn('item_code') ? 'item_code' : "'' AS item_code";
    $selectParts[] = $hasColumn('category') ? 'category' : "'' AS category";
    $selectParts[] = $hasColumn('unit_type') ? 'unit_type' : "'' AS unit_type";
    $selectParts[] = $hasColumn('description') ? 'description' : "'' AS description";
    $selectParts[] = $hasColumn('unit_price') ? 'unit_price' : '0 AS unit_price';

    $sql = 'SELECT ' . implode(', ', $selectParts) . ' FROM items WHERE 1=1';

    if ($hasColumn('status')) {
        $sql .= " AND (status IS NULL OR status = '' OR LOWER(status) = 'active')";
    }

    $hasBranchFilter = (!$view_all_branches && (int)$branch_id > 0 && $hasColumn('branch_id'));
    if ($hasBranchFilter) {
        $sql .= ' AND branch_id = ?';
    }

    $orderColumn = $hasColumn('item_name') ? 'item_name' : ($hasColumn('name') ? 'name' : 'id');
    $sql .= " ORDER BY category ASC, {$orderColumn} ASC";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return $options;
    }

    if ($hasBranchFilter) {
        $bid = (int)$branch_id;
        $stmt->bind_param('i', $bid);
    }

    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $name = trim((string)($row['name'] ?? ''));
        if ($name === '') {
            continue;
        }

        $itemCode = trim((string)($row['item_code'] ?? ''));
        $category = trim((string)($row['category'] ?? ''));
        $key = strtolower($name . '|' . $itemCode . '|' . $category);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;

        $options[] = [
            'id' => (int)($row['id'] ?? 0),
            'name' => $name,
            'label' => $name,
            'item_code' => $itemCode,
            'category' => $category,
            'badge' => $category !== '' ? $category : 'Item',
            'unit_type' => trim((string)($row['unit_type'] ?? '')),
            'description' => trim((string)($row['description'] ?? '')),
            'unit_price' => (float)($row['unit_price'] ?? 0)
        ];
    }
    $stmt->close();

    return $options;
}

function batch_resolve_customer_id_by_name($conn, $customerName, $branchId = 0, $viewAllBranches = false) {
    if (!batch_table_exists($conn, 'customers')) {
        return 0;
    }

    $customerName = trim((string)$customerName);
    if ($customerName === '') {
        return 0;
    }

    $nameColumn = batch_column_exists($conn, 'customers', 'customer_name') ? 'customer_name' : (batch_column_exists($conn, 'customers', 'name') ? 'name' : '');
    if ($nameColumn === '') {
        return 0;
    }

    $sql = "SELECT customer_id
            FROM customers
            WHERE LOWER(TRIM(`{$nameColumn}`)) = LOWER(TRIM(?))";

    if (batch_column_exists($conn, 'customers', 'status')) {
        $sql .= " AND (status IS NULL OR status = '' OR LOWER(status) = 'active')";
    }

    if (!$viewAllBranches && (int)$branchId > 0 && batch_column_exists($conn, 'customers', 'branch_id')) {
        $sql .= " AND branch_id = ? ORDER BY customer_id ASC LIMIT 1";
        $stmt = $conn->prepare($sql);
        if (!$stmt) return 0;
        $bid = (int)$branchId;
        $stmt->bind_param('si', $customerName, $bid);
    } else {
        $sql .= " ORDER BY customer_id ASC LIMIT 1";
        $stmt = $conn->prepare($sql);
        if (!$stmt) return 0;
        $stmt->bind_param('s', $customerName);
    }

    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ? (int)($row['customer_id'] ?? 0) : 0;
}

function batch_resolve_item_by_name($conn, $itemName, $branchId = 0, $viewAllBranches = false) {
    if (!batch_table_exists($conn, 'items')) {
        return null;
    }

    $itemName = trim((string)$itemName);
    if ($itemName === '') {
        return null;
    }

    $nameColumn = batch_column_exists($conn, 'items', 'item_name') ? 'item_name' : (batch_column_exists($conn, 'items', 'name') ? 'name' : '');
    if ($nameColumn === '') {
        return null;
    }

    $selectParts = [
        batch_column_exists($conn, 'items', 'item_id') ? 'item_id AS item_id' : '0 AS item_id',
        "`{$nameColumn}` AS item_name"
    ];
    $selectParts[] = batch_column_exists($conn, 'items', 'unit_type') ? 'unit_type' : "'' AS unit_type";
    $selectParts[] = batch_column_exists($conn, 'items', 'unit_price') ? 'unit_price' : '0 AS unit_price';
    $selectParts[] = batch_column_exists($conn, 'items', 'description') ? 'description' : "'' AS description";

    $sql = "SELECT " . implode(', ', $selectParts) . "
            FROM items
            WHERE LOWER(TRIM(`{$nameColumn}`)) = LOWER(TRIM(?))";

    if (batch_column_exists($conn, 'items', 'status')) {
        $sql .= " AND (status IS NULL OR status = '' OR LOWER(status) = 'active')";
    }

    if (!$viewAllBranches && (int)$branchId > 0 && batch_column_exists($conn, 'items', 'branch_id')) {
        $sql .= " AND branch_id = ? ORDER BY item_id ASC LIMIT 1";
        $stmt = $conn->prepare($sql);
        if (!$stmt) return null;
        $bid = (int)$branchId;
        $stmt->bind_param('si', $itemName, $bid);
    } else {
        $sql .= " ORDER BY item_id ASC LIMIT 1";
        $stmt = $conn->prepare($sql);
        if (!$stmt) return null;
        $stmt->bind_param('s', $itemName);
    }

    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

function batch_qty_value($value) {
    $value = str_replace([',', ' '], '', (string)$value);
    if (!is_numeric($value)) {
        return 1;
    }

    $qty = (float)$value;
    return $qty > 0 ? $qty : 1;
}

function batch_save_invoice_sales_order_item($conn, $soId, $row, $branchId, $viewAllBranches, $lineAmount) {
    if ((int)$soId <= 0 || !batch_table_exists($conn, 'sales_order_items')) {
        return;
    }

    $itemName = batch_text_value($row, 'Item');
    if ($itemName === '') {
        return;
    }

    $item = batch_resolve_item_by_name($conn, $itemName, $branchId, $viewAllBranches);
    if (!$item || (int)($item['item_id'] ?? 0) <= 0) {
        throw new Exception('Item "' . $itemName . '" was not found. Please select an active item from the Item dropdown.');
    }

    $qty = batch_qty_value($row['Qty'] ?? ($row['Quantity'] ?? 1));
    $rate = batch_money_value($row['Rate'] ?? '');
    if ($rate <= 0 && $qty > 0) {
        $rate = abs((float)$lineAmount) / $qty;
    }
    if ($rate <= 0) {
        $rate = (float)($item['unit_price'] ?? 0);
    }

    $lineAmount = abs((float)$lineAmount);
    if ($lineAmount <= 0) {
        $lineAmount = $qty * $rate;
    }

    $unitType = trim((string)($item['unit_type'] ?? ''));
    if ($unitType === '') {
        $unitType = 'Piece';
    }

    $insertData = [];
    if (batch_column_exists($conn, 'sales_order_items', 'so_id')) {
        $insertData['so_id'] = (int)$soId;
    }
    if (batch_column_exists($conn, 'sales_order_items', 'item_id')) {
        $insertData['item_id'] = (int)($item['item_id'] ?? 0);
    }
    if (batch_column_exists($conn, 'sales_order_items', 'item_name')) {
        $insertData['item_name'] = $itemName;
    }
    if (batch_column_exists($conn, 'sales_order_items', 'unit_type')) {
        $insertData['unit_type'] = $unitType;
    }
    if (batch_column_exists($conn, 'sales_order_items', 'quantity_ordered')) {
        $insertData['quantity_ordered'] = $qty;
    } elseif (batch_column_exists($conn, 'sales_order_items', 'quantity')) {
        $insertData['quantity'] = $qty;
    } elseif (batch_column_exists($conn, 'sales_order_items', 'qty')) {
        $insertData['qty'] = $qty;
    }
    if (batch_column_exists($conn, 'sales_order_items', 'quantity_delivered')) {
        $insertData['quantity_delivered'] = 0;
    }
    if (batch_column_exists($conn, 'sales_order_items', 'unit_price')) {
        $insertData['unit_price'] = $rate;
    }
    if (batch_column_exists($conn, 'sales_order_items', 'gross_price')) {
        $insertData['gross_price'] = $rate;
    }
    if (batch_column_exists($conn, 'sales_order_items', 'net_price')) {
        $insertData['net_price'] = $rate;
    }
    if (batch_column_exists($conn, 'sales_order_items', 'order_amount')) {
        $insertData['order_amount'] = $lineAmount;
    }
    if (batch_column_exists($conn, 'sales_order_items', 'discount_type')) {
        $insertData['discount_type'] = 'computed';
    }
    if (batch_column_exists($conn, 'sales_order_items', 'discount_value')) {
        $insertData['discount_value'] = 0.0000;
    }
    if (batch_column_exists($conn, 'sales_order_items', 'discount_amount')) {
        $insertData['discount_amount'] = 0.00;
    }
    if (batch_column_exists($conn, 'sales_order_items', 'total_discount')) {
        $insertData['total_discount'] = 0.00;
    }

    if (empty($insertData) || !isset($insertData['so_id']) || !isset($insertData['item_id'])) {
        return;
    }

    $columns = array_keys($insertData);
    $placeholders = implode(',', array_fill(0, count($columns), '?'));
    $sql = "INSERT INTO sales_order_items (`" . implode('`,`', $columns) . "`) VALUES ($placeholders)";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception('Failed to prepare invoice item insert: ' . $conn->error);
    }

    $types = '';
    $values = [];
    foreach ($insertData as $value) {
        if (is_int($value)) {
            $types .= 'i';
        } elseif (is_float($value)) {
            $types .= 'd';
        } else {
            $types .= 's';
        }
        $values[] = $value;
    }

    $stmt->bind_param($types, ...$values);
    if (!$stmt->execute()) {
        throw new Exception('Failed to save invoice item quantity: ' . $stmt->error);
    }
    $stmt->close();
}



function batch_ensure_check_tables($conn) {
    $conn->query("CREATE TABLE IF NOT EXISTS `bank_transactions` (
        `transaction_id` int(11) NOT NULL AUTO_INCREMENT,
        `branch_id` int(11) NOT NULL DEFAULT 0,
        `transaction_type` enum('deposit','withdrawal') NOT NULL,
        `transaction_date` datetime NOT NULL,
        `reference_number` varchar(100) DEFAULT NULL,
        `check_number` varchar(100) DEFAULT NULL,
        `bank_name` varchar(150) DEFAULT NULL,
        `bank_id` int(11) DEFAULT NULL,
        `description` text DEFAULT NULL,
        `expense_account` varchar(150) DEFAULT NULL,
        `payee` varchar(150) DEFAULT NULL,
        `amount` decimal(12,2) NOT NULL DEFAULT 0.00,
        `created_by` int(11) NOT NULL DEFAULT 0,
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`transaction_id`),
        KEY `branch_id` (`branch_id`),
        KEY `transaction_type` (`transaction_type`),
        KEY `transaction_date` (`transaction_date`),
        KEY `bank_id` (`bank_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    $bankNeeded = [
        'reference_number' => "ALTER TABLE `bank_transactions` ADD COLUMN `reference_number` varchar(100) DEFAULT NULL AFTER `transaction_date`",
        'check_number' => "ALTER TABLE `bank_transactions` ADD COLUMN `check_number` varchar(100) DEFAULT NULL AFTER `reference_number`",
        'bank_name' => "ALTER TABLE `bank_transactions` ADD COLUMN `bank_name` varchar(150) DEFAULT NULL AFTER `check_number`",
        'bank_id' => "ALTER TABLE `bank_transactions` ADD COLUMN `bank_id` int(11) DEFAULT NULL AFTER `bank_name`",
        'description' => "ALTER TABLE `bank_transactions` ADD COLUMN `description` text DEFAULT NULL AFTER `bank_id`",
        'expense_account' => "ALTER TABLE `bank_transactions` ADD COLUMN `expense_account` varchar(150) DEFAULT NULL AFTER `description`",
        'payee' => "ALTER TABLE `bank_transactions` ADD COLUMN `payee` varchar(150) DEFAULT NULL AFTER `expense_account`",
        'amount' => "ALTER TABLE `bank_transactions` ADD COLUMN `amount` decimal(12,2) NOT NULL DEFAULT 0.00 AFTER `payee`",
        'created_by' => "ALTER TABLE `bank_transactions` ADD COLUMN `created_by` int(11) NOT NULL DEFAULT 0 AFTER `amount`"
    ];
    foreach ($bankNeeded as $col => $sql) {
        if (!batch_column_exists($conn, 'bank_transactions', $col)) {
            @$conn->query($sql);
        }
    }

    $conn->query("CREATE TABLE IF NOT EXISTS `chart_account_transactions` (
        `transaction_id` int(11) NOT NULL AUTO_INCREMENT,
        `account_id` int(11) NOT NULL,
        `branch_id` int(11) NOT NULL DEFAULT 0,
        `transaction_date` date DEFAULT NULL,
        `transaction_type` varchar(80) NOT NULL,
        `transaction_no` varchar(100) DEFAULT NULL,
        `reference_no` varchar(100) DEFAULT NULL,
        `memo` text DEFAULT NULL,
        `account_name` varchar(255) DEFAULT NULL,
        `counterparty` varchar(255) DEFAULT NULL,
        `debit` decimal(15,2) NOT NULL DEFAULT 0.00,
        `credit` decimal(15,2) NOT NULL DEFAULT 0.00,
        `balance_after` decimal(15,2) NOT NULL DEFAULT 0.00,
        `source_table` varchar(100) DEFAULT NULL,
        `source_id` int(11) DEFAULT NULL,
        `created_by` int(11) NOT NULL DEFAULT 0,
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`transaction_id`),
        KEY `idx_cat_account` (`account_id`),
        KEY `idx_cat_source` (`source_table`, `source_id`),
        KEY `idx_cat_date` (`transaction_date`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    $catNeeded = [
        'transaction_date' => "ALTER TABLE `chart_account_transactions` ADD COLUMN `transaction_date` date DEFAULT NULL AFTER `branch_id`",
        'transaction_type' => "ALTER TABLE `chart_account_transactions` ADD COLUMN `transaction_type` varchar(80) NOT NULL DEFAULT '' AFTER `transaction_date`",
        'transaction_no' => "ALTER TABLE `chart_account_transactions` ADD COLUMN `transaction_no` varchar(100) DEFAULT NULL AFTER `transaction_type`",
        'reference_no' => "ALTER TABLE `chart_account_transactions` ADD COLUMN `reference_no` varchar(100) DEFAULT NULL AFTER `transaction_no`",
        'memo' => "ALTER TABLE `chart_account_transactions` ADD COLUMN `memo` text DEFAULT NULL AFTER `reference_no`",
        'account_name' => "ALTER TABLE `chart_account_transactions` ADD COLUMN `account_name` varchar(255) DEFAULT NULL AFTER `memo`",
        'counterparty' => "ALTER TABLE `chart_account_transactions` ADD COLUMN `counterparty` varchar(255) DEFAULT NULL AFTER `account_name`",
        'debit' => "ALTER TABLE `chart_account_transactions` ADD COLUMN `debit` decimal(15,2) NOT NULL DEFAULT 0.00 AFTER `account_name`",
        'credit' => "ALTER TABLE `chart_account_transactions` ADD COLUMN `credit` decimal(15,2) NOT NULL DEFAULT 0.00 AFTER `debit`",
        'balance_after' => "ALTER TABLE `chart_account_transactions` ADD COLUMN `balance_after` decimal(15,2) NOT NULL DEFAULT 0.00 AFTER `credit`",
        'source_table' => "ALTER TABLE `chart_account_transactions` ADD COLUMN `source_table` varchar(100) DEFAULT NULL AFTER `balance_after`",
        'source_id' => "ALTER TABLE `chart_account_transactions` ADD COLUMN `source_id` int(11) DEFAULT NULL AFTER `source_table`",
        'created_by' => "ALTER TABLE `chart_account_transactions` ADD COLUMN `created_by` int(11) NOT NULL DEFAULT 0 AFTER `source_id`"
    ];
    foreach ($catNeeded as $col => $sql) {
        if (!batch_column_exists($conn, 'chart_account_transactions', $col)) {
            @$conn->query($sql);
        }
    }
}

function batch_normalize_account_label($value) {
    $value = trim((string)$value);
    if ($value === '') return '';
    if (strpos($value, ' · ') !== false) {
        $value = trim(substr($value, strpos($value, ' · ') + 3));
    }
    if (strpos($value, ' - ') !== false) {
        $parts = explode(' - ', $value, 2);
        if (count($parts) === 2 && trim($parts[1]) !== '') {
            $value = trim($parts[1]);
        }
    }
    return $value;
}

function batch_resolve_chart_account_by_title($conn, $accountTitle, $branchId = 0) {
    if (!batch_table_exists($conn, 'chart_of_accounts')) return null;
    $accountTitle = batch_normalize_account_label($accountTitle);
    if ($accountTitle === '') return null;

    $sql = "SELECT account_id, account_title, account_type, balance
            FROM chart_of_accounts
            WHERE status = 'active'
              AND LOWER(TRIM(account_title)) = LOWER(TRIM(?))";

    if ((int)$branchId > 0 && batch_column_exists($conn, 'chart_of_accounts', 'branch_id')) {
        $sql .= " AND (branch_id = ? OR branch_id = 0 OR branch_id IS NULL)
                  ORDER BY CASE WHEN branch_id = ? THEN 0 ELSE 1 END, account_title ASC LIMIT 1";
        $stmt = $conn->prepare($sql);
        if (!$stmt) return null;
        $bid = (int)$branchId;
        $stmt->bind_param('sii', $accountTitle, $bid, $bid);
    } else {
        $sql .= " ORDER BY account_title ASC LIMIT 1";
        $stmt = $conn->prepare($sql);
        if (!$stmt) return null;
        $stmt->bind_param('s', $accountTitle);
    }

    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function batch_resolve_bank_account_by_id($conn, $accountId, $branchId = 0) {
    if (!batch_table_exists($conn, 'chart_of_accounts')) return null;
    $accountId = (int)$accountId;
    if ($accountId <= 0) return null;

    $sql = "SELECT account_id, account_title, account_type, balance
            FROM chart_of_accounts
            WHERE account_id = ? AND account_type = 'Bank' AND status = 'active'";
    if ((int)$branchId > 0 && batch_column_exists($conn, 'chart_of_accounts', 'branch_id')) {
        $sql .= " AND (branch_id = ? OR branch_id = 0 OR branch_id IS NULL) LIMIT 1";
        $stmt = $conn->prepare($sql);
        if (!$stmt) return null;
        $bid = (int)$branchId;
        $stmt->bind_param('ii', $accountId, $bid);
    } else {
        $sql .= " LIMIT 1";
        $stmt = $conn->prepare($sql);
        if (!$stmt) return null;
        $stmt->bind_param('i', $accountId);
    }

    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}


function batch_resolve_chart_account_by_titles_or_type($conn, $titles = [], $types = [], $branchId = 0) {
    if (!batch_table_exists($conn, 'chart_of_accounts')) return null;

    foreach ((array)$titles as $title) {
        $account = batch_resolve_chart_account_by_title($conn, $title, $branchId);
        if ($account) return $account;
    }

    $cleanTypes = [];
    foreach ((array)$types as $type) {
        $type = trim((string)$type);
        if ($type !== '') $cleanTypes[] = $type;
    }
    if (empty($cleanTypes)) return null;

    $placeholders = implode(',', array_fill(0, count($cleanTypes), '?'));
    $sql = "SELECT account_id, account_title, account_type, balance
            FROM chart_of_accounts
            WHERE status = 'active'
              AND account_type IN ($placeholders)";
    $params = $cleanTypes;
    $bindTypes = str_repeat('s', count($cleanTypes));

    if ((int)$branchId > 0 && batch_column_exists($conn, 'chart_of_accounts', 'branch_id')) {
        $sql .= " AND (branch_id = ? OR branch_id = 0 OR branch_id IS NULL)
                  ORDER BY CASE WHEN branch_id = ? THEN 0 ELSE 1 END, account_title ASC LIMIT 1";
        $params[] = (int)$branchId;
        $params[] = (int)$branchId;
        $bindTypes .= 'ii';
    } else {
        $sql .= " ORDER BY account_title ASC LIMIT 1";
    }

    $stmt = $conn->prepare($sql);
    if (!$stmt) return null;
    $stmt->bind_param($bindTypes, ...$params);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function batch_debit_increases($accountType) {
    $type = strtolower(trim((string)$accountType));
    $creditNormal = ['accounts payable', 'credit card', 'other current liability', 'long term liability', 'equity', 'income', 'other income'];
    return !in_array($type, $creditNormal, true);
}

function batch_post_chart_entry($conn, $account, $branchId, $transactionDate, $transactionType, $transactionNo, $referenceNo, $memo, $sourceTable, $sourceId, $debit, $credit, $createdBy, $counterparty = '') {
    batch_ensure_check_tables($conn);

    $accountId = (int)($account['account_id'] ?? 0);
    if ($accountId <= 0) return;

    $debit = round((float)$debit, 2);
    $credit = round((float)$credit, 2);
    if ($debit <= 0 && $credit <= 0) return;

    $debitIncreases = batch_debit_increases($account['account_type'] ?? '');
    $delta = $debitIncreases ? ($debit - $credit) : ($credit - $debit);

    $update = $conn->prepare("UPDATE chart_of_accounts SET balance = COALESCE(balance,0) + ? WHERE account_id = ?");
    if (!$update) throw new Exception('Failed to update Chart of Accounts balance: ' . $conn->error);
    $update->bind_param('di', $delta, $accountId);
    if (!$update->execute()) throw new Exception('Failed to update Chart of Accounts balance: ' . $update->error);
    $update->close();

    $balanceAfter = 0.00;
    $balStmt = $conn->prepare("SELECT balance FROM chart_of_accounts WHERE account_id = ? LIMIT 1");
    if ($balStmt) {
        $balStmt->bind_param('i', $accountId);
        $balStmt->execute();
        $balRow = $balStmt->get_result()->fetch_assoc();
        $balanceAfter = (float)($balRow['balance'] ?? 0);
        $balStmt->close();
    }

    $transactionDateOnly = date('Y-m-d', strtotime((string)$transactionDate));
    $accountName = (string)($account['account_title'] ?? '');
    $branchId = (int)$branchId;
    $sourceId = (int)$sourceId;
    $createdBy = (int)$createdBy;
    $counterparty = trim((string)$counterparty);

    $insert = $conn->prepare("INSERT INTO chart_account_transactions
        (account_id, branch_id, transaction_date, transaction_type, transaction_no, reference_no, memo, account_name, counterparty, debit, credit, balance_after, source_table, source_id, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    if (!$insert) throw new Exception('Failed to save Chart of Accounts transaction: ' . $conn->error);
    $insert->bind_param('iisssssssdddsii', $accountId, $branchId, $transactionDateOnly, $transactionType, $transactionNo, $referenceNo, $memo, $accountName, $counterparty, $debit, $credit, $balanceAfter, $sourceTable, $sourceId, $createdBy);
    if (!$insert->execute()) throw new Exception('Failed to save Chart of Accounts transaction: ' . $insert->error);
    $insert->close();
}

function batch_save_check_like_withdrawal($conn, $branchId, $userId, $bankAccountId, $row, $accountLabel = '') {
    batch_ensure_check_tables($conn);

    $transactionDate = batch_parse_date_value(batch_text_value($row, 'Date')) . ' 00:00:00';
    $checkNumber = batch_text_value($row, 'Number');
    $referenceNumber = $checkNumber !== '' ? $checkNumber : batch_ref_number('CHK');
    $payee = batch_text_value($row, 'Payee');
    $expenseAccountLabel = batch_text_value($row, 'Account');
    $memo = batch_text_value($row, 'Memo');
    $amount = abs(batch_money_value($row['Amount'] ?? ($row['*Amount'] ?? '')));

    if ($bankAccountId <= 0) throw new Exception('Please select a Bank account for Checks.');
    if ($payee === '') throw new Exception('Payee is required for Checks.');
    if ($expenseAccountLabel === '') throw new Exception('Account is required for Checks.');
    if ($amount <= 0) throw new Exception('Amount must be greater than zero for Checks.');

    $bankAccount = batch_resolve_bank_account_by_id($conn, $bankAccountId, $branchId);
    if (!$bankAccount) throw new Exception('Selected bank account was not found in Chart of Accounts.');
    $bankName = trim((string)($bankAccount['account_title'] ?? $accountLabel));

    $expenseAccount = batch_resolve_chart_account_by_title($conn, $expenseAccountLabel, $branchId);
    if (!$expenseAccount) {
        throw new Exception('Expense account "' . $expenseAccountLabel . '" was not found in Chart of Accounts. Please use an active account title.');
    }
    $expenseLabelForBankTx = trim((string)($expenseAccount['account_title'] ?? $expenseAccountLabel));

    $stmt = $conn->prepare("INSERT INTO bank_transactions
        (branch_id, transaction_type, transaction_date, reference_number, check_number, bank_name, bank_id, description, expense_account, payee, amount, created_by)
        VALUES (?, 'withdrawal', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    if (!$stmt) throw new Exception('Failed to prepare check transaction: ' . $conn->error);
    $stmt->bind_param('issssisssdi', $branchId, $transactionDate, $referenceNumber, $checkNumber, $bankName, $bankAccountId, $memo, $expenseLabelForBankTx, $payee, $amount, $userId);
    if (!$stmt->execute()) throw new Exception('Failed to save check transaction: ' . $stmt->error);
    $transactionId = (int)$stmt->insert_id;
    $stmt->close();

    $transactionNo = $referenceNumber !== '' ? $referenceNumber : 'WDL-' . str_pad((string)$transactionId, 6, '0', STR_PAD_LEFT);

    batch_post_chart_entry($conn, $bankAccount, $branchId, $transactionDate, 'Check', $transactionNo, $referenceNumber, $memo, 'bank_transactions', $transactionId, 0.00, $amount, $userId, $payee);
    batch_post_chart_entry($conn, $expenseAccount, $branchId, $transactionDate, 'Check', $transactionNo, $referenceNumber, $memo, 'bank_transactions', $transactionId, $amount, 0.00, $userId, $payee);

    return $transactionId;
}

$batch_check_payee_options = batch_get_check_payee_options($conn, $view_all_branches, $branch_id);
$batch_bill_vendor_options = batch_get_bill_vendor_options($conn, $view_all_branches, $branch_id);
$batch_invoice_customer_options = batch_get_invoice_customer_options($conn, $view_all_branches, $branch_id);
$batch_invoice_item_options = batch_get_invoice_item_options($conn, $view_all_branches, $branch_id);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_chart_account') {
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/json');

    $account_title = trim((string)($_POST['account_title'] ?? ''));
    $account_code = trim((string)($_POST['account_code'] ?? ''));
    $account_type = trim((string)($_POST['account_type'] ?? ''));
    $parent_account_id = (int)($_POST['parent_account_id'] ?? 0);
    $account_description = trim((string)($_POST['description'] ?? ''));
    $balance_raw = str_replace([',', '₱', '$', ' '], '', (string)($_POST['balance'] ?? '0'));
    $account_balance = is_numeric($balance_raw) ? (float)$balance_raw : 0.00;
    $valid_account_types = ['Bank', 'Credit Card', 'Accounts Payable', 'Accounts Receivable'];

    if ($account_title === '') {
        echo json_encode(['success' => false, 'message' => 'Account name is required.']);
        exit;
    }

    if (!in_array($account_type, $valid_account_types, true)) {
        echo json_encode(['success' => false, 'message' => 'Invalid account type.']);
        exit;
    }

    $chart_table_exists = false;
    $check_chart_table = $conn->query("SHOW TABLES LIKE 'chart_of_accounts'");
    if ($check_chart_table && $check_chart_table->num_rows > 0) {
        $chart_table_exists = true;
    }

    if (!$chart_table_exists) {
        echo json_encode(['success' => false, 'message' => 'chart_of_accounts table was not found.']);
        exit;
    }

    $existing_sql = "SELECT account_id FROM chart_of_accounts WHERE account_title = ? AND account_type = ? AND status = 'active'";
    $existing_params = [$account_title, $account_type];
    $existing_types = 'ss';

    if (!$view_all_branches && (int)$branch_id > 0) {
        $existing_sql .= " AND (branch_id = ? OR branch_id = 0 OR branch_id IS NULL)";
        $existing_params[] = (int)$branch_id;
        $existing_types .= 'i';
    }

    $existing_stmt = $conn->prepare($existing_sql);
    if ($existing_stmt) {
        $existing_stmt->bind_param($existing_types, ...$existing_params);
        $existing_stmt->execute();
        $existing_result = $existing_stmt->get_result();
        if ($existing_result && $existing_result->num_rows > 0) {
            $existing_stmt->close();
            echo json_encode(['success' => false, 'message' => 'This account already exists in Chart of Accounts.']);
            exit;
        }
        $existing_stmt->close();
    }

    $columns_result = $conn->query("SHOW COLUMNS FROM chart_of_accounts");
    $available_columns = [];
    if ($columns_result) {
        while ($col = $columns_result->fetch_assoc()) {
            $available_columns[] = $col['Field'];
        }
    }

    $insert_data = [];
    if (in_array('branch_id', $available_columns, true)) {
        $insert_data['branch_id'] = (int)$branch_id;
    }
    if (in_array('parent_account_id', $available_columns, true)) {
        $insert_data['parent_account_id'] = $parent_account_id > 0 ? $parent_account_id : null;
    }
    if (in_array('account_code', $available_columns, true)) {
        $insert_data['account_code'] = $account_code;
    }
    if (in_array('account_title', $available_columns, true)) {
        $insert_data['account_title'] = $account_title;
    }
    if (in_array('account_name', $available_columns, true) && !in_array('account_title', $available_columns, true)) {
        $insert_data['account_name'] = $account_title;
    }
    if (in_array('account_type', $available_columns, true)) {
        $insert_data['account_type'] = $account_type;
    }
    if (in_array('type', $available_columns, true) && !in_array('account_type', $available_columns, true)) {
        $insert_data['type'] = $account_type;
    }
    if (in_array('description', $available_columns, true)) {
        $insert_data['description'] = $account_description;
    }
    if (in_array('balance', $available_columns, true)) {
        $insert_data['balance'] = $account_balance;
    }
    if (in_array('status', $available_columns, true)) {
        $insert_data['status'] = 'active';
    }
    if (in_array('created_by', $available_columns, true)) {
        $insert_data['created_by'] = (int)$user_id;
    }
    if (in_array('created_at', $available_columns, true)) {
        $insert_data['created_at'] = date('Y-m-d H:i:s');
    }

    if (!in_array('account_title', $available_columns, true) && !in_array('account_name', $available_columns, true)) {
        echo json_encode(['success' => false, 'message' => 'No account title/name column found in chart_of_accounts.']);
        exit;
    }

    if (!in_array('account_type', $available_columns, true) && !in_array('type', $available_columns, true)) {
        echo json_encode(['success' => false, 'message' => 'No account type column found in chart_of_accounts.']);
        exit;
    }

    try {
        $columns = array_keys($insert_data);
        $placeholders = implode(',', array_fill(0, count($columns), '?'));
        $sql = "INSERT INTO chart_of_accounts (" . implode(',', $columns) . ") VALUES ($placeholders)";
        $stmt = $conn->prepare($sql);

        if (!$stmt) {
            throw new Exception($conn->error);
        }

        $types = '';
        $values = [];
        foreach ($insert_data as $value) {
            if (is_int($value)) {
                $types .= 'i';
            } elseif (is_float($value)) {
                $types .= 'd';
            } else {
                $types .= 's';
            }
            $values[] = $value;
        }

        $stmt->bind_param($types, ...$values);
        if (!$stmt->execute()) {
            throw new Exception($stmt->error);
        }

        $new_id = $stmt->insert_id;
        $stmt->close();

        $label = $account_code !== '' ? $account_code . ' · ' . $account_title : $account_title;

        echo json_encode([
            'success' => true,
            'message' => 'Account added successfully.',
            'account' => [
                'id' => (int)$new_id,
                'label' => $label,
                'title' => $account_title,
                'code' => $account_code,
                'type' => $account_type
            ]
        ]);
        exit;
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'message' => 'Unable to add account: ' . $e->getMessage()]);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_batch_transactions') {
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/json');

    $transaction_type = trim((string)($_POST['transaction_type'] ?? ''));
    $account_id = (int)($_POST['account_id'] ?? 0);
    $account_label = trim((string)($_POST['account_label'] ?? ''));
    $rows_json = (string)($_POST['rows'] ?? '[]');
    $rows = json_decode($rows_json, true);

    if (!is_array($rows)) {
        echo json_encode(['success' => false, 'message' => 'Invalid transaction rows.']);
        exit;
    }

    function batch_parse_date_value($value) {
        $value = trim((string)$value);
        if ($value === '') {
            return date('Y-m-d');
        }

        $formats = ['m/d/Y', 'Y-m-d', 'm-d-Y', 'd/m/Y'];
        foreach ($formats as $format) {
            $date = DateTime::createFromFormat($format, $value);
            if ($date instanceof DateTime) {
                return $date->format('Y-m-d');
            }
        }

        $time = strtotime($value);
        return $time ? date('Y-m-d', $time) : date('Y-m-d');
    }

    function batch_money_value($value) {
        $value = str_replace([',', '₱', '$', ' '], '', (string)$value);
        return is_numeric($value) ? (float)$value : 0.0;
    }

    function batch_text_value($row, $key) {
        return trim((string)($row[$key] ?? ''));
    }

    function batch_ref_number($prefix) {
        return $prefix . '-' . date('Ymd-His') . '-' . mt_rand(100, 999);
    }

    $saved = 0;
    $errors = [];
    $batch_document_counters = [];

    try {
        $conn->begin_transaction();

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $amount_raw = $row['Amount'] ?? ($row['*Amount'] ?? '');
            $amount = batch_money_value($amount_raw);

            $has_value = false;
            foreach ($row as $cell_value) {
                if (trim((string)$cell_value) !== '') {
                    $has_value = true;
                    break;
                }
            }

            if (!$has_value || abs($amount) <= 0) {
                continue;
            }

            if ($transaction_type === 'checks') {
                batch_save_check_like_withdrawal($conn, (int)$branch_id, (int)$user_id, (int)$account_id, $row, $account_label);
                $saved++;
                continue;
            }

            if ($transaction_type === 'deposits') {
                $date = batch_parse_date_value(batch_text_value($row, 'Date')) . ' 00:00:00';
                $reference_number = batch_text_value($row, 'Check No.') ?: batch_ref_number('DEP');
                $payee = batch_text_value($row, 'Received From');
                $expense_account = batch_text_value($row, 'Account From');
                $description = batch_text_value($row, 'Memo');

                batch_ensure_check_tables($conn);
                $stmt = $conn->prepare("INSERT INTO bank_transactions
                    (branch_id, transaction_type, transaction_date, reference_number, bank_name, bank_id, description, expense_account, payee, amount, created_by)
                    VALUES (?, 'deposit', ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                if (!$stmt) {
                    throw new Exception($conn->error);
                }
                $abs_amount = abs($amount);
                $stmt->bind_param(
                    "isssisssdi",
                    $branch_id,
                    $date,
                    $reference_number,
                    $account_label,
                    $account_id,
                    $description,
                    $expense_account,
                    $payee,
                    $abs_amount,
                    $user_id
                );
                if (!$stmt->execute()) {
                    throw new Exception($stmt->error);
                }
                $transactionId = (int)$stmt->insert_id;
                $stmt->close();

                $bankAccount = batch_resolve_bank_account_by_id($conn, (int)$account_id, (int)$branch_id);
                if (!$bankAccount) {
                    throw new Exception('Selected bank account was not found in Chart of Accounts.');
                }
                $fromAccount = batch_resolve_chart_account_by_title($conn, $expense_account, (int)$branch_id);
                if (!$fromAccount && $expense_account !== '') {
                    throw new Exception('Account From "' . $expense_account . '" was not found in Chart of Accounts.');
                }
                $transactionNo = $reference_number !== '' ? $reference_number : 'DEP-' . str_pad((string)$transactionId, 6, '0', STR_PAD_LEFT);
                $memoForLedger = $description !== '' ? $description : 'Deposit';

                batch_post_chart_entry($conn, $bankAccount, (int)$branch_id, $date, 'Deposit', $transactionNo, $reference_number, $memoForLedger, 'bank_transactions', $transactionId, $abs_amount, 0.00, (int)$user_id, $payee);
                if ($fromAccount) {
                    batch_post_chart_entry($conn, $fromAccount, (int)$branch_id, $date, 'Deposit', $transactionNo, $reference_number, $memoForLedger, 'bank_transactions', $transactionId, 0.00, $abs_amount, (int)$user_id, $payee);
                }

                $saved++;
                continue;
            }

            if ($transaction_type === 'bills' || $transaction_type === 'credit_card') {
                $date = batch_parse_date_value(batch_text_value($row, 'Date'));
                $refPrefix = $transaction_type === 'credit_card' ? 'CC' : 'BILL';
                $ref_no = batch_text_value($row, 'Ref No.') ?: batch_text_value($row, 'Ref') ?: batch_ref_number($refPrefix);
                $vendor = batch_text_value($row, 'Vendor') ?: batch_text_value($row, 'Payee');
                $terms = batch_text_value($row, 'Terms');
                $bill_due = batch_parse_date_value(batch_text_value($row, 'Bill Due') ?: $date);
                $account = batch_text_value($row, 'Account');
                $memo = batch_text_value($row, 'Memo');
                $transaction_kind = $amount < 0 ? 'credit' : 'bill';
                $abs_amount = abs($amount);
                $status = $transaction_kind === 'credit' ? 'paid' : 'unpaid';

                if ($vendor === '') {
                    throw new Exception(($transaction_type === 'credit_card' ? 'Vendor is required for Credit Card Charges & Credits.' : 'Vendor is required for Bills & Bill Credits.'));
                }
                if ($account === '') {
                    throw new Exception('Account is required.');
                }
                if ($account_label === '') {
                    throw new Exception($transaction_type === 'credit_card' ? 'Please select a Credit Card account.' : 'Please select an A/P account.');
                }

                $stmt = $conn->prepare("INSERT INTO billexpenses
                    (expense_no, branch_id, expense_date, transaction_type, bill_received, vendor_name, terms, ref_no, bill_due, account, payable_account, amount, total_amount, balance, memo, status, created_by)
                    VALUES (?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                if (!$stmt) {
                    throw new Exception($conn->error);
                }

                // Bills & Bill Credits and Credit Card Charges & Credits use the same Enter Bills engine.
                // The difference is the selected payable account:
                // - Bills use an Accounts Payable account.
                // - Credit Card Charges & Credits use a Credit Card account.
                $balance = $transaction_kind === 'credit' ? 0.00 : $abs_amount;
                $stmt->bind_param(
                    "sissssssssdddssi",
                    $ref_no,
                    $branch_id,
                    $date,
                    $transaction_kind,
                    $vendor,
                    $terms,
                    $ref_no,
                    $bill_due,
                    $account,
                    $account_label,
                    $abs_amount,
                    $abs_amount,
                    $balance,
                    $memo,
                    $status,
                    $user_id
                );
                if (!$stmt->execute()) {
                    throw new Exception($stmt->error);
                }
                $billId = (int)$stmt->insert_id;
                $stmt->close();

                $detailAccount = batch_resolve_chart_account_by_title($conn, $account, (int)$branch_id);
                if (!$detailAccount) {
                    throw new Exception('Account "' . $account . '" was not found in Chart of Accounts.');
                }
                $payableAccount = batch_resolve_chart_account_by_title($conn, $account_label, (int)$branch_id);
                if (!$payableAccount) {
                    $payableAccount = batch_resolve_chart_account_by_titles_or_type($conn, [$account_label], [$transaction_type === 'credit_card' ? 'Credit Card' : 'Accounts Payable'], (int)$branch_id);
                }
                if (!$payableAccount) {
                    throw new Exception('Payable/Credit Card account "' . $account_label . '" was not found in Chart of Accounts.');
                }

                $ledgerType = $transaction_type === 'credit_card'
                    ? ($transaction_kind === 'credit' ? 'Credit Card Credit' : 'Credit Card Charge')
                    : ($transaction_kind === 'credit' ? 'Credit' : 'Bill');
                $sourceNo = $ref_no;

                if ($transaction_kind === 'credit') {
                    batch_post_chart_entry($conn, $payableAccount, (int)$branch_id, $date, $ledgerType, $sourceNo, $ref_no, $memo, 'billexpenses', $billId, $abs_amount, 0.00, (int)$user_id, $vendor);
                    batch_post_chart_entry($conn, $detailAccount, (int)$branch_id, $date, $ledgerType, $sourceNo, $ref_no, $memo, 'billexpenses', $billId, 0.00, $abs_amount, (int)$user_id, $vendor);
                } else {
                    batch_post_chart_entry($conn, $detailAccount, (int)$branch_id, $date, $ledgerType, $sourceNo, $ref_no, $memo, 'billexpenses', $billId, $abs_amount, 0.00, (int)$user_id, $vendor);
                    batch_post_chart_entry($conn, $payableAccount, (int)$branch_id, $date, $ledgerType, $sourceNo, $ref_no, $memo, 'billexpenses', $billId, 0.00, $abs_amount, (int)$user_id, $vendor);
                }

                $saved++;
                continue;
            }

            if ($transaction_type === 'invoices') {
                $date = batch_parse_date_value(batch_text_value($row, 'Date'));
                $manual_number = batch_text_value($row, 'Number');
                $customer_name = batch_text_value($row, 'Customer');
                $customer_id = batch_resolve_customer_id_by_name($conn, $customer_name, (int)$branch_id, (bool)$view_all_branches);
                $memo = batch_text_value($row, 'Description') ?: batch_text_value($row, 'Memo');
                $item_name = batch_text_value($row, 'Item');
                if ($item_name !== '' && stripos($memo, $item_name) === false) {
                    $memo = trim($memo !== '' ? ($memo . ' - ' . $item_name) : $item_name);
                }
                $due_date = batch_parse_date_value(batch_text_value($row, 'Due Date') ?: $date);
                $abs_amount = abs($amount);

                if ($customer_name === '') {
                    throw new Exception('Customer is required for Invoices & Credit Memos.');
                }
                if ($customer_id <= 0) {
                    throw new Exception('Customer "' . $customer_name . '" was not found. Please select an active customer from the Customer dropdown.');
                }

                $invoice_so_id_to_save_item = 0;
                $ledger_source_table = '';
                $ledger_source_id = 0;
                $ledger_transaction_no = '';
                $ledger_reference_no = '';
                $ledger_memo = $memo;

                if ($amount < 0) {
                    $number = $manual_number !== ''
                        ? batch_format_manual_document_number('CM', $date, $manual_number, 5)
                        : batch_next_document_number_by_date($conn, 'credit_memos', 'reference_number', 'CM', $date, $batch_document_counters, 5);

                    if (batch_document_number_exists($conn, 'credit_memos', 'reference_number', $number)) {
                        throw new Exception('Credit memo number already exists: ' . $number);
                    }

                    $credit_date = $date . ' 00:00:00';
                    $stmt = $conn->prepare("INSERT INTO credit_memos
                        (branch_id, customer_id, amount, credit_date, reference_number, description, status, created_by)
                        VALUES (?, ?, ?, ?, ?, ?, 'unapplied', ?)");
                    if (!$stmt) {
                        throw new Exception($conn->error);
                    }
                    $description = trim(($customer_name !== '' ? $customer_name . ' - ' : '') . $memo);
                    $stmt->bind_param("iidsssi", $branch_id, $customer_id, $abs_amount, $credit_date, $number, $description, $user_id);
                    $ledger_source_table = 'credit_memos';
                    $ledger_transaction_no = $number;
                    $ledger_reference_no = $number;
                    $ledger_memo = $description !== '' ? $description : 'Credit memo from Batch Transaction';
                } else {
                    // Number column in the Batch Transaction table is the SI number.
                    // If the user types a suffix, save it as INV-YYYYMMDD-00000.
                    // If blank, do not save any SI number.
                    // SO number is always auto-generated as SO-YYYYMMDD-000000.
                    $si_number = $manual_number !== ''
                        ? batch_format_manual_document_number('INV', $date, $manual_number, 5)
                        : '';

                    $so_number = batch_next_document_number_by_date($conn, 'sales_orders', 'so_number', 'SO', $date, $batch_document_counters, 6);

                    $invoice_number = $si_number !== '' ? $si_number : $so_number;

                    if ($si_number !== '' && batch_document_number_exists($conn, 'invoices', 'invoice_number', $invoice_number)) {
                        throw new Exception('SI number already exists: ' . $invoice_number);
                    }

                    if ($si_number === '' && batch_document_number_exists($conn, 'invoices', 'invoice_number', $invoice_number)) {
                        throw new Exception('Invoice/SO number already exists: ' . $invoice_number);
                    }

                    $remarks = trim(($customer_name !== '' ? $customer_name . ' - ' : '') . $memo);
                    $so_id = batch_create_sales_order_for_invoice($conn, $so_number, $si_number, $customer_id, (int)$branch_id, $date, $abs_amount, (int)$user_id, $remarks);
                    $invoice_so_id_to_save_item = (int)$so_id;

                    $invoiceFields = ['invoice_number'];
                    $invoicePlaceholders = ['?'];
                    $invoiceTypes = 's';
                    $invoiceValues = [$invoice_number];

                    if ($si_number !== '' && batch_column_exists($conn, 'invoices', 'si_number')) {
                        $invoiceFields[] = 'si_number';
                        $invoicePlaceholders[] = '?';
                        $invoiceTypes .= 's';
                        $invoiceValues[] = $si_number;
                    }
                    if ($so_id > 0 && batch_column_exists($conn, 'invoices', 'so_id')) {
                        $invoiceFields[] = 'so_id';
                        $invoicePlaceholders[] = '?';
                        $invoiceTypes .= 'i';
                        $invoiceValues[] = $so_id;
                    }

                    $invoiceFields = array_merge($invoiceFields, ['customer_id', 'branch_id', 'invoice_date', 'due_date', 'total_amount', 'amount_paid', 'balance', 'status', 'remarks']);
                    $invoicePlaceholders = array_merge($invoicePlaceholders, ['?', '?', '?', '?', '?', '0.00', '?', "'pending'", '?']);
                    $invoiceTypes .= 'iissdds';
                    $invoiceValues[] = $customer_id;
                    $invoiceValues[] = (int)$branch_id;
                    $invoiceValues[] = $date;
                    $invoiceValues[] = $due_date;
                    $invoiceValues[] = $abs_amount;
                    $invoiceValues[] = $abs_amount;
                    $invoiceValues[] = $remarks;

                    $stmt = $conn->prepare("INSERT INTO invoices (`" . implode('`,`', $invoiceFields) . "`) VALUES (" . implode(',', $invoicePlaceholders) . ")");
                    if (!$stmt) {
                        throw new Exception($conn->error);
                    }
                    $stmt->bind_param($invoiceTypes, ...$invoiceValues);
                    $ledger_source_table = $so_id > 0 ? 'sales_orders' : 'invoices';
                    $ledger_source_id = (int)$so_id;
                    $ledger_transaction_no = $invoice_number;
                    $ledger_reference_no = $so_number;
                    $ledger_memo = $remarks !== '' ? $remarks : 'Invoice from Batch Transaction';
                }

                if (!$stmt->execute()) {
                    throw new Exception($stmt->error);
                }
                $insertedDocumentId = (int)$stmt->insert_id;
                $stmt->close();

                if ($ledger_source_id <= 0) {
                    $ledger_source_id = $insertedDocumentId;
                }

                if ($amount >= 0 && $invoice_so_id_to_save_item > 0) {
                    batch_save_invoice_sales_order_item($conn, $invoice_so_id_to_save_item, $row, (int)$branch_id, (bool)$view_all_branches, $abs_amount);
                }

                $arAccount = batch_resolve_chart_account_by_titles_or_type($conn, ['Accounts Receivable', 'Receivable Account'], ['Accounts Receivable'], (int)$branch_id);
                if (!$arAccount) {
                    throw new Exception('Accounts Receivable account was not found in Chart of Accounts.');
                }
                $salesAccount = batch_resolve_chart_account_by_titles_or_type($conn, ['Sales', 'Sales Revenue', 'Sales Income'], ['Income'], (int)$branch_id);
                if (!$salesAccount) {
                    throw new Exception('Sales/Income account was not found in Chart of Accounts.');
                }

                if ($amount < 0) {
                    batch_post_chart_entry($conn, $salesAccount, (int)$branch_id, $date, 'Customer Credit', $ledger_transaction_no, $ledger_reference_no, $ledger_memo, $ledger_source_table, $ledger_source_id, $abs_amount, 0.00, (int)$user_id, $customer_name);
                    batch_post_chart_entry($conn, $arAccount, (int)$branch_id, $date, 'Customer Credit', $ledger_transaction_no, $ledger_reference_no, $ledger_memo, $ledger_source_table, $ledger_source_id, 0.00, $abs_amount, (int)$user_id, $customer_name);
                } else {
                    batch_post_chart_entry($conn, $arAccount, (int)$branch_id, $date, 'Create Invoice', $ledger_transaction_no, $ledger_reference_no, $ledger_memo, $ledger_source_table, $ledger_source_id, $abs_amount, 0.00, (int)$user_id, $customer_name);
                    batch_post_chart_entry($conn, $salesAccount, (int)$branch_id, $date, 'Create Invoice', $ledger_transaction_no, $ledger_reference_no, $ledger_memo, $ledger_source_table, $ledger_source_id, 0.00, $abs_amount, (int)$user_id, $customer_name);
                }

                $saved++;
                continue;
            }

        }

        if ($saved <= 0) {
            $conn->rollback();
            echo json_encode(['success' => false, 'message' => 'No valid rows to save. Please enter an amount first.']);
            exit;
        }

        $conn->commit();
        echo json_encode(['success' => true, 'saved' => $saved, 'message' => $saved . ' transaction row(s) saved successfully.']);
        exit;
    } catch (Throwable $e) {
        if ($conn->errno === 0) {
            // keep going to rollback
        }
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => 'Save failed: ' . $e->getMessage()]);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Batch Transaction - Motorpool</title>
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
    <script src="../js/session-checker.js"></script>

    <style>
        :root {
            --primary-green: #44D34E;
            --quickbooks-green: #44D34E;
            --dark-green: #047857;
            --dark-color: #052A47;
            --line: #d8e4d2;
            --stripe: #eef9ee;
            --stripe-strong: #dff4df;
            --soft-white: #f8faf8;
        }

        html,
        body {
            height: 100%;
            overflow: hidden;
        }

        body {
            background: #f4f7fb;
        }

        .main-content {
            overflow: hidden !important;
        }

        .batch-wrap {
            padding: 10px 10px 8px;
            height: calc(100vh - 112px);
            overflow: hidden;
        }

        .batch-window {
            background: #fff;
            height: 100%;
            box-shadow: 0 10px 30px rgba(5, 42, 71, .08);
            overflow: hidden;
            display: flex;
            flex-direction: column;
            border-radius: 8px;
        }

        .batch-toolbar {
            display: grid;
            grid-template-columns: 105px minmax(260px, 360px) 90px minmax(285px, 390px) 1fr 205px;
            align-items: center;
            gap: 7px;
            padding: 16px 8px 10px;
            flex-shrink: 0;
            background: #fff;
        }

        .batch-label {
            color: #052A47;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            white-space: nowrap;
        }

        .qb-select {
            height: 30px;
            border: 1px solid #d8e4d2;
            border-radius: 5px;
            padding: 2px 8px;
            font-size: 14px;
            background: #fff;
            color: #111;
            outline: none;
            width: 100%;
        }

        .qb-select:focus {
            border-color: var(--primary-green);
            box-shadow: 0 0 0 2px rgba(68, 211, 78, .15);
        }

        .account-select-group {
            display: grid;
            grid-template-columns: 1fr 32px;
            gap: 5px;
            align-items: center;
            min-width: 0;
        }

        .add-account-btn {
            height: 30px;
            width: 32px;
            border: 1px solid #d8e4d2;
            border-radius: 5px;
            background: #fff;
            color: var(--dark-green);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            cursor: pointer;
        }

        .add-account-btn:hover {
            border-color: var(--primary-green);
            background: #eef9ee;
        }

        .qb-select option:checked,
        .qb-select option:hover {
            background: var(--dark-green);
            color: #fff;
        }

        .customize-btn,
        .qb-btn {
            border: 1px solid #d0d0d0;
            background: linear-gradient(#fff, #e9e9e9);
            color: #4a4a4a;
            font-weight: 700;
            border-radius: 2px;
            height: 34px;
            padding: 0 18px;
            font-size: 13px;
            box-shadow: inset 0 1px 0 rgba(255,255,255,.8);
        }

        .customize-btn {
            justify-self: end;
            width: 200px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }


        .customize-btn {
            justify-self: end;
            width: 200px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

       .batch-grid-box {
        margin: 0 8px;
        border: 1px solid var(--line);
        border-radius: 8px;
        flex: 0 0 485px;
        height: 485px;
        min-height: 485px;
        max-height: 485px;
        overflow-y: hidden;
        overflow-x: hidden;
        background: #fff;
    }

        .batch-table {
            width: 100%;
            min-width: 100%;
            max-width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
            font-size: var(--batch-cell-font-size, 12px);
        }

        .batch-table col {
            width: auto;
        }

        .batch-table th {
           height: 24px;
            font-size: 12px;
            font-weight: 400;
            color: #777;
            text-transform: uppercase;
            border-right: 1px solid #d7d7d7;
            border-bottom: 1px solid #cfcfcf;
            padding: 0 6px;
            background: #fff;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            position: sticky;
            top: 0;
            z-index: 5;
        }

        .batch-table td {
            height: 23px;
            border-right: 1px solid var(--line);
            padding: 0 var(--batch-cell-padding-x, 6px);
            color: #000;
            overflow: hidden;
            font-size: var(--batch-cell-font-size, 12px);
        }

        .batch-table tbody tr:nth-child(odd) td {
            background: #ffffff;
        }

        .batch-table tbody tr:nth-child(even) td {
            background: #eef9ee;
        }
        .batch-table input,
        .batch-table select {
            width: 100% !important;
            height: 22px;
            border: 0;
            outline: none;
            background: transparent;
            font-size: var(--batch-cell-font-size, 12px);
            padding: 0 3px;
        }

        .batch-table input[type="date"] {
            cursor: pointer;
            color: #111;
            position: relative;
        }

        .batch-table input[type="date"].date-empty:not(:focus)::-webkit-datetime-edit,
        .batch-table input[type="date"].date-empty:not(:focus)::-webkit-datetime-edit-fields-wrapper,
        .batch-table input[type="date"].date-empty:not(:focus)::-webkit-datetime-edit-text,
        .batch-table input[type="date"].date-empty:not(:focus)::-webkit-datetime-edit-month-field,
        .batch-table input[type="date"].date-empty:not(:focus)::-webkit-datetime-edit-day-field,
        .batch-table input[type="date"].date-empty:not(:focus)::-webkit-datetime-edit-year-field {
            color: transparent;
        }

        .batch-table input[type="date"].date-empty:not(:focus) {
            color: transparent;
        }

        .batch-table input[type="date"]::-webkit-calendar-picker-indicator {
            cursor: pointer;
            opacity: .85;
        }

        .batch-table input[type="date"].date-empty::-webkit-calendar-picker-indicator {
            opacity: .85;
        }

        .batch-table input[type="date"]:focus,
        .batch-table input[type="date"].date-has-value {
            color: #111;
        }

        .batch-table tbody tr:focus-within td {
        outline: 1px solid #9deaa2;
        outline-offset: -1px;
    }

        .num-cell input,
        .amount-input {
            text-align: right;
        }

        .batch-footer {
            padding: 8px 8px 10px;
            flex-shrink: 0;
            background: #fff;
        }

        .bottom-grid {
            display: grid;
            grid-template-columns: 1fr 1fr 140px 120px 140px 120px;
            align-items: center;
            gap: 8px;
        }


        .footer-label {
            text-align: right;
            color: #052A47;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .footer-amount {
            text-align: right;
            font-size: 15px;
            font-weight: 700;
            color: #000;
        }

        .negative-note {
            font-size: 11px;
            color: #000;
            font-weight: 600;
            margin-top: 6px;
        }

        .action-row {
            display: flex;
            justify-content: flex-end;
            gap: 8px;
            padding-top: 8px;
        }

        .save-btn {
            height: 34px;
            min-width: 210px;
            border: none;
            color: #fff;
            font-weight: 700;
            font-size: 14px;
            border-radius: 5px;
            background: linear-gradient(135deg, #44D34E, #047857);
            box-shadow: 0 4px 12px rgba(4, 120, 87, .18);
            transition: .18s ease;
        }

        .save-btn:hover {
            opacity: .95;
            transform: translateY(-1px);
        }

        .clear-btn,
        .close-btn {
            min-width: 145px;
        }

        .batch-toolbar .select2-container {
            width: 100% !important;
            min-width: 0 !important;
            height: 30px !important;
            font-size: 14px !important;
            display: block !important;
            line-height: 1 !important;
        }

        .batch-toolbar .select2-container .select2-selection--single {
            height: 30px !important;
            min-height: 30px !important;
            border: 1px solid #d8e4d2 !important;
            border-radius: 5px !important;
            background: #fff !important;
            outline: none !important;
            box-shadow: none !important;
            display: flex !important;
            align-items: center !important;
            position: relative !important;
        }

        .batch-toolbar .select2-container--default.select2-container--focus .select2-selection--single,
        .batch-toolbar .select2-container--default.select2-container--open .select2-selection--single {
            border-color: var(--primary-green) !important;
            box-shadow: 0 0 0 2px rgba(68, 211, 78, .15) !important;
        }

        .batch-toolbar .select2-container--default .select2-selection--single .select2-selection__rendered {
            color: #111 !important;
            height: 100% !important;
            line-height: normal !important;
            padding-left: 20px !important;
            padding-right: 30px !important;
            font-size: 14px !important;
            display: flex !important;
            align-items: center !important;
            width: 100% !important;
        }

        .batch-toolbar .select2-container--default .select2-selection--single .select2-selection__arrow {
            height: 100% !important;
            width: 24px !important;
            right: 6px !important;
            top: 0 !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
        }

        .batch-toolbar .select2-container--default .select2-selection--single .select2-selection__arrow b {
            border-color: #777 transparent transparent transparent !important;
            border-width: 5px 4px 0 4px !important;
            margin-left: 0 !important;
            margin-top: 0 !important;
            position: static !important;
        }

        .select2-container--open .select2-dropdown {
            border: 1px solid #d8e4d2 !important;
            border-radius: 5px !important;
            overflow: hidden !important;
            box-shadow: 0 8px 18px rgba(5, 42, 71, .12) !important;
        }

        .select2-container--default .select2-results__option {
            font-size: 14px !important;
            padding: 6px 10px !important;
            color: #111 !important;
            background: #fff !important;
        }

        .select2-container--default .select2-results__option--selected {
            background: #eef9ee !important;
            color: var(--dark-green) !important;
            font-weight: 600 !important;
        }

        .select2-container--default .select2-results__option--highlighted.select2-results__option--selectable,
        .select2-container--default .select2-results__option--highlighted {
            background: var(--dark-green) !important;
            color: #fff !important;
        }


        .customize-modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.35);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 9999;
            padding: 18px;
        }

        .customize-modal-overlay.show {
            display: flex;
        }

        .customize-modal {
            width: min(760px, 96vw);
            max-height: 88vh;
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 18px 45px rgba(5, 42, 71, .22);
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }

        .customize-modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 14px 16px;
            border-bottom: 1px solid var(--line);
            background: #f8faf8;
        }

        .customize-modal-title {
            margin: 0;
            font-size: 16px;
            font-weight: 700;
            color: var(--dark-color);
        }

        .customize-close-btn {
            border: 0;
            background: transparent;
            font-size: 22px;
            line-height: 1;
            color: #555;
            cursor: pointer;
        }

        .customize-modal-body {
            padding: 14px 16px;
            overflow: auto;
        }

        .customize-helper {
            font-size: 12px;
            color: #555;
            margin-bottom: 12px;
        }

        .customize-columns-layout {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
        }

        .customize-panel {
            border: 1px solid var(--line);
            border-radius: 8px;
            overflow: hidden;
            background: #fff;
        }

        .customize-panel-title {
            padding: 9px 10px;
            background: #eef9ee;
            color: var(--dark-green);
            font-weight: 700;
            font-size: 13px;
            border-bottom: 1px solid var(--line);
        }

        .customize-list {
            min-height: 245px;
            max-height: 315px;
            overflow: auto;
            padding: 8px;
        }

        .customize-item {
            display: flex;
            align-items: center;
            gap: 7px;
            padding: 7px 8px;
            border: 1px solid #e5eee5;
            border-radius: 6px;
            margin-bottom: 6px;
            background: #fff;
            font-size: 13px;
        }

        .customize-item.selected {
            background: #fbfffb;
        }

        .customize-item-name {
            flex: 1;
            color: #111;
            font-weight: 500;
        }

        .customize-mini-btn {
            border: 1px solid #d0d0d0;
            background: linear-gradient(#fff, #eeeeee);
            color: #444;
            border-radius: 4px;
            min-width: 28px;
            height: 25px;
            font-size: 12px;
            line-height: 1;
        }

        .customize-mini-btn:hover {
            border-color: var(--dark-green);
            color: var(--dark-green);
        }

        .customize-modal-footer {
            display: flex;
            justify-content: flex-end;
            gap: 8px;
            padding: 12px 16px;
            border-top: 1px solid var(--line);
            background: #fff;
        }

        .customize-apply-btn {
        height: 42px;
        min-width: 190px;
        padding: 0 24px;
        border: none;
        color: #fff;
        font-weight: 600;
        font-size: 15px;
        border-radius: 8px;
        white-space: nowrap;
        background: linear-gradient(135deg, #44D34E, #047857);
        display: inline-flex;
        align-items: center;
        justify-content: center;
    }

    .customize-secondary-btn {
        height: 42px;
        min-width: 140px;
        padding: 0 24px;
        border: 1px solid #d0d0d0;
        background: linear-gradient(#fff, #e9e9e9);
        color: #4a4a4a;
        font-weight: 600;
        font-size: 15px;
        border-radius: 8px;
        white-space: nowrap;
        display: inline-flex;
        align-items: center;
        justify-content: center;
    }


        .add-account-modal {
            width: min(760px, 96vw);
            max-height: 88vh;
            background: #fff;
            border-radius: 10px;
        }

        .add-account-modal .customize-modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 14px 16px;
            border-bottom: 1px solid var(--line);
            background: #f8faf8;
        }

        .add-account-modal .customize-modal-title {
            margin: 0;
            color: var(--dark-color);
            display: flex;
            align-items: center;
            gap: 7px;
            font-size: 16px;
            font-weight: 700;
        }

        .add-account-modal .customize-close-btn {
            color: #555;
            font-size: 22px;
            font-weight: 400;
        }

        .add-account-modal .customize-modal-body {
            padding: 14px 16px;
        }

        .coa-form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
        }

        .coa-field-full {
            grid-column: 1 / -1;
        }

        .coa-form-label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: #052A47;
            margin-bottom: 6px;
        }

        .coa-required {
            color: #dc3545;
        }

        .coa-control {
            width: 100%;
            min-height: 38px;
            border: 1px solid #d5e2ef;
            border-radius: 8px;
            padding: 7px 10px;
            font-size: 14px;
            color: #30363d;
            background: #fff;
            outline: none;
        }

        .coa-control:focus {
            border-color: var(--primary-green);
            box-shadow: 0 0 0 2px rgba(68, 211, 78, .14);
        }

        textarea.coa-control {
            min-height: 86px;
            resize: vertical;
        }

        .coa-help {
            color: #6c757d;
            font-size: 12px;
            margin-top: 5px;
            line-height: 1.45;
        }

        .add-account-modal .customize-modal-footer {
            display: flex;
            justify-content: flex-end;
            gap: 8px;
            padding: 12px 16px;
            border-top: 1px solid var(--line);
            background: #fff;
        }

        .coa-save-btn {
            height: 34px;
            min-width: 130px;
            border: none;
            color: #fff;
            font-weight: 700;
            font-size: 13px;
            border-radius: 5px;
            padding: 0 14px;
            background: linear-gradient(135deg, #44D34E, #047857);
        }

        .coa-cancel-btn {
            height: 34px;
            min-width: 110px;
            border: 1px solid #d0d0d0;
            background: linear-gradient(#fff, #e9e9e9);
            color: #4a4a4a;
            font-weight: 700;
            border-radius: 5px;
            font-size: 13px;
            padding: 0 14px;
        }

        .add-account-modal .form-control:focus {
            border-color: var(--primary-green);
            box-shadow: 0 0 0 2px rgba(68, 211, 78, .15);
        }



        .payee-search-input,
        .vendor-search-input,
        .customer-search-input,
        .item-search-input {
            width: 100%;
            height: 22px;
            border: 0;
            outline: none;
            background: transparent;
            font-size: var(--batch-cell-font-size, 12px);
            padding: 0 3px;
            color: #000;
        }

        .batch-payee-dropdown {
            position: fixed;
            display: none;
            min-width: 260px;
            max-width: 390px;
            max-height: 320px;
            overflow-y: auto;
            overflow-x: hidden;
            overscroll-behavior: contain;
            scroll-behavior: auto;
            background: #fff;
            border: 1px solid #777;
            border-right: 6px solid var(--primary-green);
            box-shadow: 0 6px 16px rgba(5, 42, 71, .14);
            z-index: 10050;
            color: #000;
            font-size: 12px;
            scrollbar-width: thin;
            scrollbar-color: var(--primary-green) #e8f7e8;
        }

        .batch-payee-dropdown.show {
            display: block;
        }

        .batch-payee-group {
            padding: 6px 10px 4px;
            font-weight: 700;
            color: #000;
            background: #fff;
            position: sticky;
            top: 0;
            z-index: 1;
        }

        .batch-payee-option {
            padding: 5px 10px 5px 28px;
            cursor: pointer;
            color: #000;
            background: #fff;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
        }

        .batch-payee-name {
            min-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .batch-payee-badge {
            flex: 0 0 auto;
            max-width: 125px;
            padding: 2px 7px;
            border-radius: 999px;
            background: #eef9ee;
            color: #047857;
            border: 1px solid #bdeebf;
            font-size: 10px;
            font-weight: 700;
            line-height: 1.2;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .batch-payee-option:hover,
        .batch-payee-option.active {
            background: #eef9ee;
            color: #047857;
        }

        .batch-payee-empty {
            padding: 8px 12px;
            color: #777;
            background: #fff;
        }

        .batch-payee-dropdown::-webkit-scrollbar {
            width: 8px;
        }

        .batch-payee-dropdown::-webkit-scrollbar-thumb {
            background: var(--primary-green);
            border-radius: 10px;
        }

        .batch-payee-dropdown::-webkit-scrollbar-track {
            background: #e8f7e8;
        }

        @media (max-width: 720px) {
            .customize-columns-layout {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 1200px) {
            .batch-toolbar {
                grid-template-columns: 115px 1fr 105px 1fr 170px;
            }

            .batch-toolbar span {
                display: none;
            }

            .customize-btn {
                width: 170px;
            }
        }
    

        .sidebar.collapsed .nav-link.active,
        .sidebar.collapsed .nav-item.active > .nav-link,
        .sidebar.collapsed .dropdown-nav > .nav-link.active {
            border-left: none !important;
            border-inline-start: none !important;
            box-shadow: none !important;
        }

        .sidebar.collapsed .nav-link.active::before,
        .sidebar.collapsed .nav-link.active::after,
        .sidebar.collapsed .nav-item.active > .nav-link::before,
        .sidebar.collapsed .nav-item.active > .nav-link::after,
        .sidebar.collapsed .dropdown-nav > .nav-link.active::before,
        .sidebar.collapsed .dropdown-nav > .nav-link.active::after {
            display: none !important;
            content: none !important;
            border: 0 !important;
            box-shadow: none !important;
        }

        .sidebar.collapsed .nav-link.active {
            margin-left: 0 !important;
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

    html,
    body {
        overflow-x: hidden;
    }

    .main-content {
        padding-bottom: 95px !important;
        min-height: calc(100vh - 95px);
        box-sizing: border-box;
    }

    .batch-wrap {
        height: calc(100dvh - 112px - 95px) !important;
        padding-bottom: 0 !important;
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

/* Keep the profile modal and backdrop above the fixed mobile navigation. */
#profileModal {
    z-index: 20050 !important;
}

#profileModal .modal-dialog {
    z-index: 20051 !important;
}

.modal-backdrop {
    z-index: 20040 !important;
}

body.modal-open {
    overflow: hidden !important;
}

@media (max-width: 992px) {
    #openProfileModalBtn {
        appearance: none;
        -webkit-appearance: none;
        color: inherit;
        font: inherit;
        cursor: pointer;
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
                <li class="nav-item">
                    <a class="nav-link" href="motorpool_inventory.php">
                        <i class="bi bi-box-seam"></i>
                        <span class="nav-text">Current Inventory</span>
                    </a>
                </li>
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
                                <a class="nav-link active" href="batch_transaction.php">
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
        <div class="navbar-top not-print">
            <div class="page-title">
                <h2>Batch Transaction</h2>
                <p>Manage your batch transactions here.</p>
            </div>
        </div>

        <div class="batch-wrap">
            <div class="batch-window">
                <div class="batch-toolbar">
                    <label class="batch-label" for="transactionType">Transaction Type</label>
                    <select class="qb-select" id="transactionType">
                        <option value="checks" selected>Checks</option>
                        <option value="deposits">Deposits</option>
                        <option value="credit_card">Credit Card Charges &amp; Credits</option>
                        <option value="bills">Bills &amp; Bill Credits</option>
                        <option value="invoices">Invoices &amp; Credit Memos</option>
                    </select>

                    <label class="batch-label" id="accountLabel" for="accountSelect">Bank Account</label>
                    <select class="qb-select" id="accountSelect"></select>

                    <span></span>
                    <button type="button" class="customize-btn" onclick="openCustomizeColumns()">Customize Columns</button>
                </div>

                <div class="batch-grid-box">
                    <table class="batch-table" id="batchTable">
                        <thead id="tableHead"></thead>
                        <tbody id="tableBody"></tbody>
                    </table>
                </div>

                <div class="batch-footer">
                    <div class="bottom-grid" id="summaryGrid">
                        <div></div>
                        <div></div>
                        <div></div>
                        <div></div>
                        <div class="footer-label single-total-label">Total:</div>
                        <div class="footer-amount" id="singleTotal">₱0.00</div>
                    </div>
                    <div class="negative-note d-none" id="negativeNote">*Enter credit or refund amounts &amp; quantities as a negative value.</div>
                    <div class="action-row">
                        <button type="button" class="save-btn" onclick="saveTransactions()">Save Transactions</button>
                        <button type="button" class="qb-btn clear-btn" onclick="clearRows()">Clear</button>
                        <button type="button" class="qb-btn close-btn" onclick="history.back()">Close</button>
                    </div>
                </div>
            </div>
        </div>
    </div>


    <div class="customize-modal-overlay" id="addAccountModal">
        <div class="customize-modal add-account-modal">
            <div class="customize-modal-header">
                <h5 class="customize-modal-title"><i class="bi bi-plus-circle"></i> Add Account</h5>
                <button type="button" class="customize-close-btn" onclick="closeAddAccountModal()">&times;</button>
            </div>
            <div class="customize-modal-body">
                <div class="coa-form-grid">
                    <div>
                        <label class="coa-form-label" for="newAccountCode">Account Code</label>
                        <input type="text" class="coa-control" id="newAccountCode" placeholder="Example: 10100">
                    </div>
                    <div>
                        <label class="coa-form-label" for="newAccountTitle">Account Title <span class="coa-required">*</span></label>
                        <input type="text" class="coa-control" id="newAccountTitle" placeholder="Example: Checking">
                    </div>
                    <div>
                        <label class="coa-form-label" for="newAccountType">Account Type <span class="coa-required">*</span></label>
                        <select class="coa-control" id="newAccountType" onchange="renderParentAccountOptions()">
                            <option value="">Select account type</option>
                            <option value="Bank">Bank</option>
                            <option value="Credit Card">Credit Card</option>
                            <option value="Accounts Payable">Accounts Payable</option>
                            <option value="Accounts Receivable">Accounts Receivable</option>
                        </select>
                    </div>
                    <div>
                        <label class="coa-form-label" for="newParentAccount">Parent Account</label>
                        <select class="coa-control" id="newParentAccount">
                            <option value="">Main account</option>
                        </select>
                        <div class="coa-help">Only accounts with the same account type can be selected as parent.</div>
                    </div>
                    <div class="coa-field-full">
                        <label class="coa-form-label" for="newAccountDescription">Description</label>
                        <textarea class="coa-control" id="newAccountDescription" placeholder="Enter account description or notes"></textarea>
                    </div>
                    <div>
                        <label class="coa-form-label" for="newAccountBalance">Balance</label>
                        <input type="number" step="0.01" class="coa-control" id="newAccountBalance" value="0.00" placeholder="0.00">
                    </div>
                </div>
            </div>
            <div class="customize-modal-footer">
                <button type="button" class="coa-cancel-btn" onclick="closeAddAccountModal()">Cancel</button>
                <button type="button" class="coa-save-btn" onclick="saveNewChartAccount()"><i class="bi bi-box-arrow-down"></i> Save Account</button>
            </div>
        </div>
    </div>

    <div class="customize-modal-overlay" id="customizeModal">
        <div class="customize-modal">
            <div class="customize-modal-header">
                <h5 class="customize-modal-title">Customize Columns</h5>
                <button type="button" class="customize-close-btn" onclick="closeCustomizeColumns()">&times;</button>
            </div>
            <div class="customize-modal-body">
                <div class="customize-helper">
                    Select the variables you want to place in the table. You can also change their position using the up/down buttons.
                </div>
                <div class="customize-columns-layout">
                    <div class="customize-panel">
                        <div class="customize-panel-title">Available Variables</div>
                        <div class="customize-list" id="availableColumnsList"></div>
                    </div>
                    <div class="customize-panel">
                        <div class="customize-panel-title">Selected Columns / Column Position</div>
                        <div class="customize-list" id="selectedColumnsList"></div>
                    </div>
                </div>
            </div>
            <div class="customize-modal-footer">
                <button type="button" class="customize-secondary-btn" onclick="resetCustomizeColumns()">Reset Default</button>
                <button type="button" class="customize-secondary-btn" onclick="closeCustomizeColumns()">Cancel</button>
                <button type="button" class="customize-apply-btn" onclick="applyCustomizeColumns()">Apply Columns</button>
            </div>
        </div>
    </div>

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
                <a class="nav-link more-btn" href="#" onclick="toggleMobileDropdown(event, 'customerMobileMenu')">
                    <i class="bi bi-people-fill"></i>
                    <span>Customer</span>
                </a>
                <div class="more-dropdown" id="customerMobileMenu">
                    <a class="dropdown-item" href="orderproduct.php"><i class="bi bi-receipt"></i><span>Create
                            Invoice</span></a>
                    <a class="dropdown-item" href="collections.php"><i class="bi bi-cash-stack"></i><span>Receive
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
                <a class="nav-link more-btn active" href="#" onclick="toggleMobileDropdown(event, 'accountingMobileMenu')">
                    <i class="bi bi-graph-up"></i>
                    <span>Accounting</span>
                </a>
                <div class="more-dropdown" id="accountingMobileMenu">
                    <a class="dropdown-item" href="journal_entries.php"><i class="bi bi-journal"></i><span>Journal
                            Entries</span></a>
                    <a class="dropdown-item active" href="batch_transaction.php"><i class="bi bi-collection"></i><span>Batch
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
                <button type="button"
                        id="openProfileModalBtn"
                        class="nav-link border-0 bg-transparent w-100"
                        aria-label="Open user profile">
                    <i class="bi bi-box-arrow-right"></i>
                    <span>Logout</span>
                </button>
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

</div>


    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
let batchAccountsByType = <?php echo json_encode($batch_accounts_by_type, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
let batchAllAccountOptions = <?php echo json_encode($batch_all_account_options, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
let batchCheckPayeeOptions = <?php echo json_encode($batch_check_payee_options, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
let batchBillVendorOptions = <?php echo json_encode($batch_bill_vendor_options, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
let batchInvoiceCustomerOptions = <?php echo json_encode($batch_invoice_customer_options, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
let batchInvoiceItemOptions = <?php echo json_encode($batch_invoice_item_options, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;

function currentDateInputValue() {
    const today = new Date();
    const year = today.getFullYear();
    const month = String(today.getMonth() + 1).padStart(2, '0');
    const day = String(today.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
}

const transactionConfigs = {
    checks: {
        accountLabel: 'Bank Account',
        accountType: 'Bank',
        headers: ['Date', 'Number', 'Payee', 'Account', 'Amount', 'Memo'],
        totals: 'single',
        note: false
    },
    deposits: {
        accountLabel: 'Deposit To',
        accountType: 'Bank',
        headers: ['Date', 'Received From', 'Account From', 'Memo', 'Check No.', 'Amount'],
        defaultValues: {'Date': currentDateInputValue(), 'Memo': 'Deposit'},
        totals: 'single',
        note: false
    },
    credit_card: {
        accountLabel: 'Credit Card',
        accountType: 'Credit Card',
        headers: ['Date', 'Ref No.', 'Vendor', 'Terms', 'Bill Due', 'Account', '*Amount', 'Memo'],
        defaultValues: {'Date': currentDateInputValue()},
        totals: 'chargeCredit',
        note: true
    },
    bills: {
        accountLabel: 'A/P Account',
        accountType: 'Accounts Payable',
        headers: ['Date', 'Ref No.', 'Vendor', 'Terms', 'Bill Due', 'Account', '*Amount', 'Memo'],
        defaultValues: {'Date': currentDateInputValue()},
        totals: 'chargeCredit',
        note: true
    },
    invoices: {
        accountLabel: 'A/R Account',
        accountType: 'Accounts Receivable',
        headers: ['Date', 'Number', 'Customer', 'Terms', '*Amount', 'Description', 'Item', 'Qty', 'Rate', 'Tax Code', 'Tax Item', 'Tax Amount'],
        totals: 'chargeCredit',
        note: true
    }
};

const customizeColumnsByType = {
    checks: {
        selected: ['Date', 'Number', 'Payee', 'Account', 'Amount', 'Memo'],
        available: ['Cleared', 'Billable?', 'Class', 'Customer', 'Item', 'Cost', 'Qty']
    },
    deposits: {
        selected: ['Date', 'Received From', 'Account From', 'Memo', 'Check No.', 'Amount'],
        available: ['Cleared', 'Class', 'Payment Method']
    },
    credit_card: {
        selected: ['Date', 'Ref No.', 'Vendor', 'Terms', 'Bill Due', 'Account', '*Amount', 'Memo'],
        available: ['Billable?', 'Class', 'Customer', 'Item', 'Cost', 'Qty']
    },
    bills: {
        selected: ['Date', 'Ref No.', 'Vendor', 'Terms', 'Bill Due', 'Account', '*Amount', 'Memo'],
        available: ['Billable?', 'Class', 'Customer', 'Item', 'Cost', 'Qty']
    },
    invoices: {
        selected: ['Date', 'Number', 'Customer', 'Terms', '*Amount', 'Description', 'Item', 'Qty', 'Rate', 'Tax Code', 'Tax Item', 'Tax Amount'],
        available: ['Due Date', 'Sales Rep', 'Class', 'To Print', 'To Be Emailed', 'Price Level', 'Template', 'Online Pay']
    }
};

const allColumnVariables = [...new Set(
    Object.values(customizeColumnsByType).flatMap(group => [...group.selected, ...group.available])
)];

let customizeDraftColumns = [];

function getStorageKey(type) {
    return 'batchTransactionColumns_' + type;
}

function normalizeInvoiceHeaders(headers) {
    const canonicalMap = {
        'Customer:Job': 'Customer',
        'Amount': '*Amount'
    };

    let normalized = (Array.isArray(headers) ? headers : [])
        .map(column => canonicalMap[column] || column)
        .filter(column => String(column || '').trim() !== '');

    // Keep Customer visible for Invoices & Credit Memos because saving needs it.
    if (!normalized.includes('Customer')) {
        const numberIndex = normalized.indexOf('Number');
        normalized.splice(numberIndex >= 0 ? numberIndex + 1 : 2, 0, 'Customer');
    }

    return [...new Set(normalized)];
}

function getActiveHeaders(config, type = null) {
    const selectedType = type || document.getElementById('transactionType')?.value || 'checks';
    const typeColumns = customizeColumnsByType[selectedType] || {selected: config.headers, available: []};
    const baseColumns = Array.isArray(config?.headers) ? config.headers : [];
    const allowedColumns = [...new Set([
        ...baseColumns,
        ...typeColumns.selected,
        ...typeColumns.available,
        'Customer',
        'Customer:Job',
        '*Amount',
        'Amount'
    ])];
    const saved = localStorage.getItem(getStorageKey(selectedType));

    if (saved) {
        try {
            const parsed = JSON.parse(saved);
            if (Array.isArray(parsed) && parsed.length > 0) {
                const cleaned = parsed
                    .map(column => selectedType === 'invoices' ? (column === 'Customer:Job' ? 'Customer' : (column === 'Amount' ? '*Amount' : column)) : column)
                    .filter(column => allowedColumns.includes(column));
                if (cleaned.length > 0) {
                    return selectedType === 'invoices' ? normalizeInvoiceHeaders(cleaned) : [...new Set(cleaned)];
                }
            }
        } catch (error) {
            localStorage.removeItem(getStorageKey(selectedType));
        }
    }

    return selectedType === 'invoices' ? normalizeInvoiceHeaders([...typeColumns.selected]) : [...typeColumns.selected];
}

function renderCustomizeLists() {
    const availableList = document.getElementById('availableColumnsList');
    const selectedList = document.getElementById('selectedColumnsList');
    const selectedType = document.getElementById('transactionType').value;
    const typeColumns = customizeColumnsByType[selectedType] || {available: []};

    const config = transactionConfigs[selectedType] || {headers: []};
    const allColumnsForType = [...new Set([
        ...(config.headers || []),
        ...(typeColumns.selected || []),
        ...(typeColumns.available || [])
    ])].map(column => selectedType === 'invoices' && column === 'Customer:Job' ? 'Customer' : column);
    const availableColumns = [...new Set(allColumnsForType)].filter(column => !customizeDraftColumns.includes(column));

    availableList.innerHTML = availableColumns.length
        ? availableColumns.map(column => `
            <div class="customize-item">
                <span class="customize-item-name">${escapeHtml(column)}</span>
                <button type="button" class="customize-mini-btn" onclick="addCustomizeColumn('${escapeJs(column)}')">Add</button>
            </div>
        `).join('')
        : '<div class="customize-item"><span class="customize-item-name">No more variables available.</span></div>';

    selectedList.innerHTML = customizeDraftColumns.length
        ? customizeDraftColumns.map((column, index) => `
            <div class="customize-item selected">
                <span class="customize-item-name">${index + 1}. ${escapeHtml(column)}</span>
                <button type="button" class="customize-mini-btn" onclick="moveCustomizeColumn(${index}, -1)" title="Move up">↑</button>
                <button type="button" class="customize-mini-btn" onclick="moveCustomizeColumn(${index}, 1)" title="Move down">↓</button>
                <button type="button" class="customize-mini-btn" onclick="removeCustomizeColumn(${index})">Remove</button>
            </div>
        `).join('')
        : '<div class="customize-item"><span class="customize-item-name">No selected columns.</span></div>';
}
function openCustomizeColumns() {
    const selectedType = document.getElementById('transactionType').value;
    const config = transactionConfigs[selectedType];

    customizeDraftColumns = getActiveHeaders(config, selectedType);
    renderCustomizeLists();

    document.getElementById('customizeModal').classList.add('show');
}

function closeCustomizeColumns() {
    document.getElementById('customizeModal').classList.remove('show');
}

function addCustomizeColumn(column) {
    if (!customizeDraftColumns.includes(column)) {
        customizeDraftColumns.push(column);
    }
    renderCustomizeLists();
}

function removeCustomizeColumn(index) {
    customizeDraftColumns.splice(index, 1);
    renderCustomizeLists();
}

function moveCustomizeColumn(index, direction) {
    const newIndex = index + direction;
    if (newIndex < 0 || newIndex >= customizeDraftColumns.length) {
        return;
    }

    const temp = customizeDraftColumns[index];
    customizeDraftColumns[index] = customizeDraftColumns[newIndex];
    customizeDraftColumns[newIndex] = temp;

    renderCustomizeLists();
}

function resetCustomizeColumns() {
    const selectedType = document.getElementById('transactionType').value;
    const typeColumns = customizeColumnsByType[selectedType] || {selected: []};

    customizeDraftColumns = selectedType === 'invoices' ? normalizeInvoiceHeaders([...typeColumns.selected]) : [...typeColumns.selected];
    localStorage.removeItem(getStorageKey(selectedType));
    renderCustomizeLists();
}

function applyCustomizeColumns() {
    if (customizeDraftColumns.length === 0) {
        Swal.fire({
            icon: 'warning',
            title: 'No columns selected',
            text: 'Please select at least one column.',
            confirmButtonColor: '#047857'
        });
        return;
    }

    const selectedType = document.getElementById('transactionType').value;
    const columnsToSave = selectedType === 'invoices'
        ? normalizeInvoiceHeaders(customizeDraftColumns)
        : [...new Set(customizeDraftColumns)];

    customizeDraftColumns = [...columnsToSave];
    localStorage.setItem(getStorageKey(selectedType), JSON.stringify(columnsToSave));

    closeCustomizeColumns();
    loadTransactionType();
}

function escapeJs(value) {
    return String(value ?? '').replace(/\\/g, '\\\\').replace(/'/g, "\\'");
}

function money(value) {
    const amount = Number(value || 0);
    return '₱' + amount.toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2});
}

function initTransactionTypeSelect() {
    if (typeof $ === 'undefined' || typeof $.fn.select2 === 'undefined') {
        return;
    }

    const $transactionType = $('#transactionType');

    if (!$transactionType.hasClass('select2-hidden-accessible')) {
        $transactionType.select2({
            minimumResultsForSearch: Infinity,
            width: '100%'
        });
    }
}

function initAccountSelect() {
    if (typeof $ === 'undefined' || typeof $.fn.select2 === 'undefined') {
        return;
    }

    const $accountSelect = $('#accountSelect');

    if ($accountSelect.hasClass('select2-hidden-accessible')) {
        $accountSelect.select2('destroy');
    }

    $accountSelect.select2({
        minimumResultsForSearch: Infinity,
        width: '100%'
    });

    $accountSelect.off('select2:select.addAccount').on('select2:select.addAccount', function (event) {
        if (event.params && event.params.data && event.params.data.id === '__add_new_account__') {
            openAddAccountModal();
        }
    });
}
function escapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function getAccountsForConfig(config) {
    const list = batchAccountsByType[config.accountType] || [];
    return Array.isArray(list) ? list : [];
}

function renderAccountSelect(config) {
    const accountSelect = document.getElementById('accountSelect');

    if (typeof $ !== 'undefined' && $('#accountSelect').hasClass('select2-hidden-accessible')) {
        $('#accountSelect').select2('destroy');
    }

    const accounts = getAccountsForConfig(config);
    if (accounts.length > 0) {
        accountSelect.innerHTML = `<option value="__add_new_account__" data-add-new="1">+ Add Account</option>` + accounts.map(account => {
            const label = escapeHtml(account.label || account.title || '');
            const value = escapeHtml(account.id || account.label || account.title || '');
            return `<option value="${value}" data-account-type="${escapeHtml(account.type || config.accountType)}">${label}</option>`;
        }).join('');
        accountSelect.disabled = false;
        accountSelect.selectedIndex = accounts.length > 0 ? 1 : 0;
    } else {
        accountSelect.innerHTML = `
            <option value="__add_new_account__" data-add-new="1">+ Add Account</option>
            <option value="" disabled>No active ${escapeHtml(config.accountType)} account found</option>
        `;
        accountSelect.disabled = false;
        accountSelect.selectedIndex = 1;
    }

    document.getElementById('accountLabel').textContent = config.accountLabel;
}
function getColumnWidth(header) {
    const name = String(header || '').replace('*', '').trim().toLowerCase();

    const wideColumns = {
        'customer': 220,
        'customer:job': 220,
        'received from': 220,
        'payee': 220,
        'vendor': 220,
        'item': 230,
        'description': 260,
        'memo': 240,
        'account': 220,
        'account from': 220,
        'bill due': 135,
        'due date': 135,
        'date': 135,
        'number': 150,
        'ref no.': 150,
        'check no.': 150,
        'terms': 140,
        'amount': 145,
        'tax amount': 145,
        'rate': 120,
        'qty': 90,
        'tax code': 130,
        'tax item': 150,
        'sales rep': 160,
        'price level': 150,
        'template': 150,
        'class': 150
    };

    return wideColumns[name] || 150;
}

function applyResponsiveTableWidth(headers) {
    const table = document.getElementById('batchTable');
    const gridBox = document.querySelector('.batch-grid-box');
    if (!table || !gridBox) {
        return;
    }

    const visibleHeaders = Array.isArray(headers) ? headers : [];
    const columnCount = Math.max(visibleHeaders.length, 1);

    const oldColGroup = table.querySelector('colgroup');
    if (oldColGroup) {
        oldColGroup.remove();
    }

    const getColumnWeight = (header) => {
        const name = String(header || '').replace('*', '').trim().toLowerCase();

        if (['date', 'due date', 'bill due'].includes(name)) return 1.05;
        if (['qty'].includes(name)) return .70;
        if (['rate', 'amount', 'tax amount', 'cost'].includes(name)) return .95;
        if (['number', 'ref no.', 'check no.', 'terms', 'tax code', 'tax item'].includes(name)) return 1.00;
        if (['customer', 'customer:job', 'received from', 'payee', 'vendor', 'item', 'account', 'account from'].includes(name)) return 1.35;
        if (['description', 'memo'].includes(name)) return 1.55;

        return 1.00;
    };

    const weights = visibleHeaders.map(getColumnWeight);
    const totalWeight = weights.reduce((sum, weight) => sum + weight, 0) || columnCount;

    const colGroup = document.createElement('colgroup');
    weights.forEach(weight => {
        const col = document.createElement('col');
        col.style.width = ((weight / totalWeight) * 100).toFixed(4) + '%';
        colGroup.appendChild(col);
    });
    table.insertBefore(colGroup, table.firstChild);

    table.style.width = '100%';
    table.style.minWidth = '100%';
    table.style.maxWidth = '100%';

    const compactFont = columnCount >= 10 ? '10px' : (columnCount >= 8 ? '11px' : '12px');
    const compactPadding = columnCount >= 10 ? '2px' : (columnCount >= 8 ? '3px' : '6px');

    table.style.setProperty('--batch-cell-font-size', compactFont);
    table.style.setProperty('--batch-cell-padding-x', compactPadding);

    gridBox.style.overflowX = 'hidden';
}

function renderHeaders(config) {
    const tableHead = document.getElementById('tableHead');
    const selectedType = document.getElementById('transactionType').value;
    const headers = getActiveHeaders(config, selectedType);
    applyResponsiveTableWidth(headers);
    tableHead.innerHTML = '<tr>' + headers.map(header => `<th>${escapeHtml(header)}</th>`).join('') + '</tr>';
}

function isDateColumn(header) {
    const normalized = String(header || '').trim().toLowerCase();
    return normalized === 'date' || normalized === 'due date' || normalized === 'bill due';
}

function buildPayeeOptionsHtml() {
    const options = Array.isArray(batchCheckPayeeOptions) ? batchCheckPayeeOptions : [];
    if (!options.length) {
        return '<option value="">No payee found</option>';
    }

    let html = '<option value="">Select Payee</option>';
    let currentGroup = '';

    options.forEach(option => {
        const type = option.type || 'Payee';
        if (type !== currentGroup) {
            if (currentGroup !== '') {
                html += '</optgroup>';
            }
            currentGroup = type;
            html += `<optgroup label="${escapeHtml(type)}">`;
        }

        const label = option.label || option.name || '';
        html += `<option value="${escapeHtml(label)}">${escapeHtml(label)}</option>`;
    });

    if (currentGroup !== '') {
        html += '</optgroup>';
    }

    return html;
}


let activeBatchAccountInput = null;

function getBatchAccountDropdownEl() {
    let dropdown = document.getElementById('batchAccountDropdown');
    if (!dropdown) {
        dropdown = document.createElement('div');
        dropdown.id = 'batchAccountDropdown';
        dropdown.className = 'batch-payee-dropdown';
        document.body.appendChild(dropdown);
    }
    return dropdown;
}

function groupBatchAccountOptions(options) {
    return options.reduce((groups, option) => {
        const type = option.type || 'Other Account';
        if (!groups[type]) {
            groups[type] = [];
        }
        groups[type].push(option);
        return groups;
    }, {});
}

function renderBatchAccountDropdownOptions(input) {
    const dropdown = getBatchAccountDropdownEl();
    const query = String(input.value || '').trim().toLowerCase();
    const allOptions = Array.isArray(batchAllAccountOptions) ? batchAllAccountOptions : [];
    const filtered = allOptions.filter(option => {
        const label = String(option.label || option.title || '').toLowerCase();
        const title = String(option.title || '').toLowerCase();
        const code = String(option.code || '').toLowerCase();
        const type = String(option.type || '').toLowerCase();
        return query === '' || label.includes(query) || title.includes(query) || code.includes(query) || type.includes(query);
    });

    if (!filtered.length) {
        dropdown.innerHTML = '<div class="batch-payee-empty">No account found</div>';
        return;
    }

    const grouped = groupBatchAccountOptions(filtered);
    dropdown.innerHTML = Object.keys(grouped).map(type => {
        const items = grouped[type].map(option => {
            const label = option.label || option.title || '';
            const title = option.title || label;
            const badge = option.type || 'Account';
            return `<div class="batch-payee-option" data-value="${escapeHtml(title)}" data-label="${escapeHtml(label)}" data-account-id="${escapeHtml(option.id || '')}">
                <span class="batch-payee-name">${escapeHtml(label)}</span>
                ${badge ? `<span class="batch-payee-badge">${escapeHtml(badge)}</span>` : ''}
            </div>`;
        }).join('');
        return `<div class="batch-payee-group">${escapeHtml(type)}</div>${items}`;
    }).join('');

    dropdown.querySelectorAll('.batch-payee-option').forEach(optionEl => {
        optionEl.addEventListener('mousedown', function(event) {
            event.preventDefault();
            if (!activeBatchAccountInput) {
                return;
            }
            activeBatchAccountInput.value = this.dataset.value || this.dataset.label || this.textContent.trim();
            activeBatchAccountInput.dataset.accountId = this.dataset.accountId || '';
            handleBatchCellInput(activeBatchAccountInput);
            closeBatchAccountDropdown();
        });
    });
}

function showBatchAccountDropdown(input) {
    closeAllBatchLookupDropdowns('account');
    activeBatchAccountInput = input;
    const dropdown = getBatchAccountDropdownEl();
    renderBatchAccountDropdownOptions(input);

    const rect = input.getBoundingClientRect();
    dropdown.style.left = `${rect.left}px`;
    dropdown.style.top = `${rect.bottom + 1}px`;
    dropdown.style.width = `${Math.max(rect.width, 360)}px`;
    dropdown.style.display = 'block';
    dropdown.classList.add('show');
    setupBatchDropdownScrollLock(dropdown);
}

function closeBatchAccountDropdown() {
    const dropdown = document.getElementById('batchAccountDropdown');
    if (dropdown) {
        dropdown.classList.remove('show');
        dropdown.style.display = 'none';
    }
    activeBatchAccountInput = null;
}

let activePayeeInput = null;

function getPayeeDropdownEl() {
    let dropdown = document.getElementById('batchPayeeDropdown');
    if (!dropdown) {
        dropdown = document.createElement('div');
        dropdown.id = 'batchPayeeDropdown';
        dropdown.className = 'batch-payee-dropdown';
        document.body.appendChild(dropdown);
    }
    return dropdown;
}

function groupPayeeOptions(options) {
    return options.reduce((groups, option) => {
        const type = option.type || 'Payee';
        if (!groups[type]) {
            groups[type] = [];
        }
        groups[type].push(option);
        return groups;
    }, {});
}

function renderPayeeDropdownOptions(input) {
    const dropdown = getPayeeDropdownEl();
    const query = String(input.value || '').trim().toLowerCase();
    const allOptions = Array.isArray(batchCheckPayeeOptions) ? batchCheckPayeeOptions : [];
    const filtered = allOptions.filter(option => {
        const label = String(option.label || option.name || '').toLowerCase();
        const type = String(option.type || '').toLowerCase();
        const badge = String(option.badge || option.customer_group || option.role || '').toLowerCase();
        return query === '' || label.includes(query) || type.includes(query) || badge.includes(query);
    });

    if (!filtered.length) {
        dropdown.innerHTML = '<div class="batch-payee-empty">No payee found</div>';
        return;
    }

    const grouped = groupPayeeOptions(filtered);
    dropdown.innerHTML = Object.keys(grouped).map(type => {
        const items = grouped[type].map(option => {
            const label = option.label || option.name || '';
            const badge = option.badge || option.customer_group || option.role || option.type || '';
            return `<div class="batch-payee-option" data-value="${escapeHtml(label)}">
                <span class="batch-payee-name">${escapeHtml(label)}</span>
                ${badge ? `<span class="batch-payee-badge">${escapeHtml(badge)}</span>` : ''}
            </div>`;
        }).join('');
        return `<div class="batch-payee-group">${escapeHtml(type)}</div>${items}`;
    }).join('');

    dropdown.querySelectorAll('.batch-payee-option').forEach(optionEl => {
        optionEl.addEventListener('mousedown', function(event) {
            event.preventDefault();
            if (!activePayeeInput) {
                return;
            }
            activePayeeInput.value = this.dataset.value || this.textContent.trim();
            handleBatchCellInput(activePayeeInput);
            closePayeeDropdown();
        });
    });
}


function closeAllBatchLookupDropdowns(exceptType = '') {
    if (exceptType !== 'payee') closePayeeDropdown();
    if (exceptType !== 'vendor') closeVendorDropdown();
    if (exceptType !== 'customer') closeCustomerDropdown();
    if (exceptType !== 'item') closeItemDropdown();
    if (exceptType !== 'account') closeBatchAccountDropdown();
}

function showPayeeDropdown(input) {
    closeAllBatchLookupDropdowns('payee');
    activePayeeInput = input;
    const dropdown = getPayeeDropdownEl();
    renderPayeeDropdownOptions(input);

    const rect = input.getBoundingClientRect();
    dropdown.style.left = `${rect.left}px`;
    dropdown.style.top = `${rect.bottom + 1}px`;
    dropdown.style.width = `${Math.max(rect.width, 360)}px`;
    dropdown.style.display = 'block';
    dropdown.classList.add('show');
    setupBatchDropdownScrollLock(dropdown);
}

function closePayeeDropdown() {
    const dropdown = document.getElementById('batchPayeeDropdown');
    if (dropdown) {
        dropdown.classList.remove('show');
        dropdown.style.display = 'none';
    }
    activePayeeInput = null;
}



let activeVendorInput = null;

function getVendorDropdownEl() {
    let dropdown = document.getElementById('batchVendorDropdown');
    if (!dropdown) {
        dropdown = document.createElement('div');
        dropdown.id = 'batchVendorDropdown';
        dropdown.className = 'batch-payee-dropdown';
        document.body.appendChild(dropdown);
    }
    return dropdown;
}

function renderVendorDropdownOptions(input) {
    const dropdown = getVendorDropdownEl();
    const query = String(input.value || '').trim().toLowerCase();
    const allOptions = Array.isArray(batchBillVendorOptions) ? batchBillVendorOptions : [];
    const filtered = allOptions.filter(option => {
        const label = String(option.label || option.name || '').toLowerCase();
        const code = String(option.supplier_code || '').toLowerCase();
        return query === '' || label.includes(query) || code.includes(query);
    });

    if (!filtered.length) {
        dropdown.innerHTML = '<div class="batch-payee-empty">No vendor found</div>';
        return;
    }

    const items = filtered.map(option => {
        const label = option.label || option.name || '';
        const terms = option.payment_terms || '';
        return `<div class="batch-payee-option" data-value="${escapeHtml(label)}" data-terms="${escapeHtml(terms)}">${escapeHtml(label)}</div>`;
    }).join('');

    dropdown.innerHTML = `<div class="batch-payee-group">Supplier</div>${items}`;

    dropdown.querySelectorAll('.batch-payee-option').forEach(optionEl => {
        optionEl.addEventListener('mousedown', function(event) {
            event.preventDefault();
            if (!activeVendorInput) {
                return;
            }
            activeVendorInput.value = this.dataset.value || this.textContent.trim();

            const row = activeVendorInput.closest('tr');
            const terms = this.dataset.terms || '';
            if (row && terms !== '') {
                const headers = getCurrentHeaders();
                const termsIndex = headers.indexOf('Terms');
                if (termsIndex >= 0 && row.children[termsIndex]) {
                    const termsInput = row.children[termsIndex].querySelector('input, select');
                    if (termsInput && termsInput.value.trim() === '') {
                        termsInput.value = terms;
                    }
                }
            }

            handleBatchCellInput(activeVendorInput);
            closeVendorDropdown();
        });
    });
}

function showVendorDropdown(input) {
    closeAllBatchLookupDropdowns('vendor');
    activeVendorInput = input;
    const dropdown = getVendorDropdownEl();
    renderVendorDropdownOptions(input);

    const rect = input.getBoundingClientRect();
    dropdown.style.left = `${rect.left}px`;
    dropdown.style.top = `${rect.bottom + 1}px`;
    dropdown.style.width = `${Math.max(rect.width, 360)}px`;
    dropdown.style.display = 'block';
    dropdown.classList.add('show');
    setupBatchDropdownScrollLock(dropdown);
}

function closeVendorDropdown() {
    const dropdown = document.getElementById('batchVendorDropdown');
    if (dropdown) {
        dropdown.classList.remove('show');
        dropdown.style.display = 'none';
    }
    activeVendorInput = null;
}


let activeCustomerInput = null;

function getCustomerDropdownEl() {
    let dropdown = document.getElementById('batchCustomerDropdown');
    if (!dropdown) {
        dropdown = document.createElement('div');
        dropdown.id = 'batchCustomerDropdown';
        dropdown.className = 'batch-payee-dropdown';
        document.body.appendChild(dropdown);
    }
    return dropdown;
}

function renderCustomerDropdownOptions(input) {
    const dropdown = getCustomerDropdownEl();
    const query = String(input.value || '').trim().toLowerCase();
    const allOptions = Array.isArray(batchInvoiceCustomerOptions) ? batchInvoiceCustomerOptions : [];
    const filtered = allOptions.filter(option => {
        const label = String(option.label || option.name || '').toLowerCase();
        const group = String(option.customer_group || option.badge || '').toLowerCase();
        const priceLevel = String(option.price_level || '').toLowerCase();
        const terms = String(option.payment_terms || option.terms || option.customer_terms || option.credit_terms || '').toLowerCase();
        return query === '' || label.includes(query) || group.includes(query) || priceLevel.includes(query) || terms.includes(query);
    });

    if (!filtered.length) {
        dropdown.innerHTML = '<div class="batch-payee-empty">No customer found</div>';
        return;
    }

    const items = filtered.map(option => {
        const label = option.label || option.name || '';
        const badge = option.badge || option.customer_group || 'No Group';
        const terms = option.payment_terms || option.terms || option.customer_terms || option.credit_terms || '';
        return `<div class="batch-payee-option" data-value="${escapeHtml(label)}" data-customer-id="${escapeHtml(option.id || '')}" data-terms="${escapeHtml(terms)}">
            <span class="batch-payee-name">${escapeHtml(label)}</span>
            ${badge ? `<span class="batch-payee-badge">${escapeHtml(badge)}</span>` : ''}
        </div>`;
    }).join('');

    dropdown.innerHTML = `<div class="batch-payee-group">Customer</div>${items}`;

    dropdown.querySelectorAll('.batch-payee-option').forEach(optionEl => {
        optionEl.addEventListener('mousedown', function(event) {
            event.preventDefault();
            if (!activeCustomerInput) {
                return;
            }
            activeCustomerInput.value = this.dataset.value || this.textContent.trim();
            activeCustomerInput.dataset.customerId = this.dataset.customerId || '';

            const row = activeCustomerInput.closest('tr');
            const terms = this.dataset.terms || '';
            if (row && terms !== '') {
                const headers = getCurrentHeaders();
                const termsIndex = headers.findIndex(header => String(header || '').trim().toLowerCase() === 'terms');
                if (termsIndex >= 0 && row.children[termsIndex]) {
                    const termsInput = row.children[termsIndex].querySelector('input, select');
                    if (termsInput) {
                        termsInput.value = terms;
                        termsInput.dispatchEvent(new Event('input', { bubbles: true }));
                        termsInput.dispatchEvent(new Event('change', { bubbles: true }));
                    }
                }
            }

            handleBatchCellInput(activeCustomerInput);
            closeCustomerDropdown();
        });
    });
}

function showCustomerDropdown(input) {
    closeAllBatchLookupDropdowns('customer');
    activeCustomerInput = input;
    const dropdown = getCustomerDropdownEl();
    renderCustomerDropdownOptions(input);

    const rect = input.getBoundingClientRect();
    dropdown.style.left = `${rect.left}px`;
    dropdown.style.top = `${rect.bottom + 1}px`;
    dropdown.style.width = `${Math.max(rect.width, 360)}px`;
    dropdown.style.display = 'block';
    dropdown.classList.add('show');
    setupBatchDropdownScrollLock(dropdown);
}

function closeCustomerDropdown() {
    const dropdown = document.getElementById('batchCustomerDropdown');
    if (dropdown) {
        dropdown.classList.remove('show');
        dropdown.style.display = 'none';
    }
    activeCustomerInput = null;
}

let activeItemInput = null;

function getItemDropdownEl() {
    let dropdown = document.getElementById('batchItemDropdown');
    if (!dropdown) {
        dropdown = document.createElement('div');
        dropdown.id = 'batchItemDropdown';
        dropdown.className = 'batch-payee-dropdown';
        document.body.appendChild(dropdown);
    }
    return dropdown;
}

function renderItemDropdownOptions(input) {
    const dropdown = getItemDropdownEl();
    const query = String(input.value || '').trim().toLowerCase();
    const allOptions = Array.isArray(batchInvoiceItemOptions) ? batchInvoiceItemOptions : [];
    const filtered = allOptions.filter(option => {
        const label = String(option.label || option.name || '').toLowerCase();
        const code = String(option.item_code || '').toLowerCase();
        const category = String(option.category || option.badge || '').toLowerCase();
        return query === '' || label.includes(query) || code.includes(query) || category.includes(query);
    });

    if (!filtered.length) {
        dropdown.innerHTML = '<div class="batch-payee-empty">No item found</div>';
        return;
    }

    const items = filtered.map(option => {
        const label = option.label || option.name || '';
        const badge = option.badge || option.category || 'Item';
        const description = option.description || '';
        return `<div class="batch-payee-option" data-value="${escapeHtml(label)}" data-item-id="${escapeHtml(option.id || '')}" data-rate="${escapeHtml(option.unit_price || '')}" data-description="${escapeHtml(description)}">
            <span class="batch-payee-name">${escapeHtml(label)}</span>
            ${badge ? `<span class="batch-payee-badge">${escapeHtml(badge)}</span>` : ''}
        </div>`;
    }).join('');

    dropdown.innerHTML = `<div class="batch-payee-group">Item</div>${items}`;

    dropdown.querySelectorAll('.batch-payee-option').forEach(optionEl => {
        optionEl.addEventListener('mousedown', function(event) {
            event.preventDefault();
            if (!activeItemInput) {
                return;
            }
            activeItemInput.value = this.dataset.value || this.textContent.trim();
            activeItemInput.dataset.itemId = this.dataset.itemId || '';

            const row = activeItemInput.closest('tr');
            const rate = this.dataset.rate || '';
            const description = this.dataset.description || '';
            if (row) {
                const headers = getCurrentHeaders();

                const descriptionIndex = headers.indexOf('Description');
                if (descriptionIndex >= 0 && row.children[descriptionIndex]) {
                    const descriptionInput = row.children[descriptionIndex].querySelector('input, select');
                    if (descriptionInput) {
                        descriptionInput.value = description || activeItemInput.value;
                    }
                }

                const qtyIndex = headers.indexOf('Qty');
                if (qtyIndex >= 0 && row.children[qtyIndex]) {
                    const qtyInput = row.children[qtyIndex].querySelector('input, select');
                    if (qtyInput && qtyInput.value.trim() === '') {
                        qtyInput.value = '1';
                    }
                }

                const rateIndex = headers.indexOf('Rate');
                if (rateIndex >= 0 && row.children[rateIndex]) {
                    const rateInput = row.children[rateIndex].querySelector('input, select');
                    if (rateInput && rate !== '') {
                        rateInput.value = rate;
                    }
                }

                recalcInvoiceRowAmount(row);
            }

            handleBatchCellInput(activeItemInput);
            closeItemDropdown();
        });
    });
}

function showItemDropdown(input) {
    closeAllBatchLookupDropdowns('item');
    activeItemInput = input;
    const dropdown = getItemDropdownEl();
    renderItemDropdownOptions(input);

    const rect = input.getBoundingClientRect();
    dropdown.style.left = `${rect.left}px`;
    dropdown.style.top = `${rect.bottom + 1}px`;
    dropdown.style.width = `${Math.max(rect.width, 360)}px`;
    dropdown.style.display = 'block';
    dropdown.classList.add('show');
    setupBatchDropdownScrollLock(dropdown);
}

function closeItemDropdown() {
    const dropdown = document.getElementById('batchItemDropdown');
    if (dropdown) {
        dropdown.classList.remove('show');
        dropdown.style.display = 'none';
    }
    activeItemInput = null;
}

document.addEventListener('mousedown', function(event) {
    const payeeDropdown = document.getElementById('batchPayeeDropdown');
    const vendorDropdown = document.getElementById('batchVendorDropdown');
    const customerDropdown = document.getElementById('batchCustomerDropdown');
    const itemDropdown = document.getElementById('batchItemDropdown');
    const accountDropdown = document.getElementById('batchAccountDropdown');

    if (event.target.classList && (event.target.classList.contains('payee-search-input') || event.target.classList.contains('vendor-search-input') || event.target.classList.contains('customer-search-input') || event.target.classList.contains('item-search-input') || event.target.classList.contains('account-search-input'))) {
        return;
    }

    if (payeeDropdown && !payeeDropdown.contains(event.target)) {
        closePayeeDropdown();
    }

    if (vendorDropdown && !vendorDropdown.contains(event.target)) {
        closeVendorDropdown();
    }

    if (customerDropdown && !customerDropdown.contains(event.target)) {
        closeCustomerDropdown();
    }

    if (itemDropdown && !itemDropdown.contains(event.target)) {
        closeItemDropdown();
        closeBatchAccountDropdown();
    }

    if (accountDropdown && !accountDropdown.contains(event.target)) {
        closeBatchAccountDropdown();
    }
});

document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        closePayeeDropdown();
        closeVendorDropdown();
        closeCustomerDropdown();
        closeItemDropdown();
        closeBatchAccountDropdown();
    }
});

function setupBatchDropdownScrollLock(dropdown) {
    if (!dropdown || dropdown.dataset.scrollLocked === '1') {
        return;
    }

    dropdown.dataset.scrollLocked = '1';

    dropdown.addEventListener('wheel', function(event) {
        const canScroll = dropdown.scrollHeight > dropdown.clientHeight;
        if (!canScroll) {
            return;
        }

        const delta = event.deltaY;
        const atTop = dropdown.scrollTop <= 0;
        const atBottom = Math.ceil(dropdown.scrollTop + dropdown.clientHeight) >= dropdown.scrollHeight;

        if ((delta < 0 && atTop) || (delta > 0 && atBottom)) {
            event.preventDefault();
        }

        event.stopPropagation();
    }, { passive: false });

    dropdown.addEventListener('touchmove', function(event) {
        event.stopPropagation();
    }, { passive: true });

    dropdown.addEventListener('scroll', function(event) {
        event.stopPropagation();
    });
}

window.addEventListener('scroll', function(event) {
    const payeeDropdown = document.getElementById('batchPayeeDropdown');
    const vendorDropdown = document.getElementById('batchVendorDropdown');
    const customerDropdown = document.getElementById('batchCustomerDropdown');
    const itemDropdown = document.getElementById('batchItemDropdown');
    const accountDropdown = document.getElementById('batchAccountDropdown');
    const target = event.target;

    if ((payeeDropdown && payeeDropdown.contains(target)) || (vendorDropdown && vendorDropdown.contains(target)) || (customerDropdown && customerDropdown.contains(target)) || (itemDropdown && itemDropdown.contains(target)) || (accountDropdown && accountDropdown.contains(target))) {
        return;
    }

    closePayeeDropdown();
    closeVendorDropdown();
    closeCustomerDropdown();
    closeItemDropdown();
    closeBatchAccountDropdown();
}, true);

function getCurrentHeaders() {
    const selectedType = document.getElementById('transactionType')?.value || 'checks';
    const config = transactionConfigs[selectedType];
    return config ? getActiveHeaders(config, selectedType) : [];
}

function updateBatchDateInputState(input) {
    if (!input || input.type !== 'date') {
        return;
    }

    const hasValue = String(input.value || '').trim() !== '';
    input.classList.toggle('date-empty', !hasValue);
    input.classList.toggle('date-has-value', hasValue);
}

function initializeBatchDateInputs(scope = document) {
    const container = scope && typeof scope.querySelectorAll === 'function' ? scope : document;
    container.querySelectorAll('.batch-table input[type="date"], input.batch-date-input').forEach(updateBatchDateInputState);
}


function isBatchAccountColumn(header, selectedType = '') {
    const cleanHeader = String(header || '').replace('*', '').trim().toLowerCase();
    const type = String(selectedType || document.getElementById('transactionType')?.value || '').trim().toLowerCase();

    // Use the same Chart of Accounts lookup dropdown for these Batch Transaction account cells.
    // Cell stays blank until clicked/typed; no default option is inserted.
    if (cleanHeader === 'account from') {
        return type === 'deposits';
    }

    if (cleanHeader === 'account') {
        return ['checks', 'credit_card', 'bills'].includes(type);
    }

    return false;
}

function buildBatchRowHtml(headers, config, rowIndex = 0) {
    const selectedType = document.getElementById('transactionType')?.value || 'checks';
    const cells = headers.map((header) => {
        const value = config.defaultValues && Object.prototype.hasOwnProperty.call(config.defaultValues, header) && rowIndex === 0
            ? config.defaultValues[header]
            : '';
        const cleanHeader = header.replace('*', '').trim();
        const isTotalAmountColumn = cleanHeader === 'Amount';
        const isMoneyInput = isTotalAmountColumn || ['Cost', 'Rate', 'Tax Amount'].includes(header);
        const isDate = isDateColumn(header);
        const inputType = isDate ? 'date' : 'text';
        const classes = [
            isTotalAmountColumn ? 'amount-input' : '',
            isMoneyInput && !isTotalAmountColumn ? 'money-input' : '',
            isDate ? 'date-input' : ''
        ].filter(Boolean).join(' ');
        const inputMode = isMoneyInput ? ' inputmode="decimal"' : '';

        if (selectedType === 'checks' && header === 'Payee') {
            return `<td>
                <input type="text" class="payee-search-input" data-batch-lookup="payee" autocomplete="off" onfocus="showPayeeDropdown(this)" oninput="showPayeeDropdown(this); handleBatchCellInput(this)" onchange="handleBatchCellInput(this)">
            </td>`;
        }

        if ((selectedType === 'bills' || selectedType === 'credit_card') && header === 'Vendor') {
            return `<td>
                <input type="text" class="vendor-search-input" data-batch-lookup="vendor" autocomplete="off" onfocus="showVendorDropdown(this)" oninput="showVendorDropdown(this); handleBatchCellInput(this)" onchange="handleBatchCellInput(this)">
            </td>`;
        }

        if (selectedType === 'invoices' && header === 'Customer') {
            return `<td>
                <input type="text" class="customer-search-input" data-batch-lookup="customer" autocomplete="off" onfocus="showCustomerDropdown(this)" oninput="showCustomerDropdown(this); handleBatchCellInput(this)" onchange="handleBatchCellInput(this)">
            </td>`;
        }

        if (selectedType === 'invoices' && header === 'Item') {
            return `<td>
                <input type="text" class="item-search-input" data-batch-lookup="item" autocomplete="off" onfocus="showItemDropdown(this)" oninput="showItemDropdown(this); handleBatchCellInput(this)" onchange="syncInvoiceItemDependentFields(this, true); handleBatchCellInput(this)">
            </td>`;
        }

        if (isBatchAccountColumn(header, selectedType)) {
            return `<td>
                <input type="text" class="account-search-input" data-batch-lookup="account" autocomplete="off" value="" onfocus="showBatchAccountDropdown(this)" onclick="showBatchAccountDropdown(this)" oninput="showBatchAccountDropdown(this); handleBatchCellInput(this)" onchange="handleBatchCellInput(this)">
            </td>`;
        }

        const inputEvents = isDate
            ? 'oninput="updateBatchDateInputState(this); handleBatchCellInput(this)" onchange="updateBatchDateInputState(this); handleBatchCellInput(this)" onclick="this.showPicker?.()"'
            : 'oninput="handleBatchCellInput(this)" onchange="handleBatchCellInput(this)"';

        return `<td class="${isMoneyInput ? 'num-cell' : ''}">
            <input type="${inputType}" ${classes ? `class="${classes}${isDate ? ' batch-date-input' : ''}"` : (isDate ? 'class="batch-date-input"' : '')}${inputMode} value="${escapeHtml(value)}" ${inputEvents}>
        </td>`;
    }).join('');

    return `<tr>${cells}</tr>`;
}

function appendTransactionRows(count = 1, shouldScroll = false) {
    const selectedType = document.getElementById('transactionType').value;
    const config = transactionConfigs[selectedType];
    if (!config) {
        return;
    }

    const tableBody = document.getElementById('tableBody');
    const headers = getActiveHeaders(config, selectedType);
    applyResponsiveTableWidth(headers);
    const currentRows = tableBody.querySelectorAll('tr').length;
    let html = '';

    for (let r = 0; r < count; r++) {
        html += buildBatchRowHtml(headers, config, currentRows + r);
    }

    tableBody.insertAdjacentHTML('beforeend', html);
    initializeBatchDateInputs(tableBody);
    updateTableScroll();

    if (shouldScroll) {
        const gridBox = document.querySelector('.batch-grid-box');
        if (gridBox) {
            gridBox.scrollTop = gridBox.scrollHeight;
        }
    }
}

function rowHasValue(row) {
    return Array.from(row.querySelectorAll('input, select')).some(input => input.value.trim() !== '');
}

function parseBatchNumber(value) {
    const cleaned = String(value || '').replace(/,/g, '').replace(/₱/g, '').trim();
    const parsed = parseFloat(cleaned);
    return Number.isFinite(parsed) ? parsed : 0;
}

function formatBatchNumber(value) {
    const parsed = parseFloat(value);
    if (!Number.isFinite(parsed)) {
        return '';
    }
    return parsed.toFixed(2);
}


function syncInvoiceItemDependentFields(input, forceUpdate = false) {
    const selectedType = document.getElementById('transactionType')?.value || '';
    if (selectedType !== 'invoices' || !input) {
        return;
    }

    const row = input.closest('tr');
    if (!row) {
        return;
    }

    const headers = getCurrentHeaders();
    const itemValue = String(input.value || '').trim();
    const descriptionIndex = headers.indexOf('Description');
    const rateIndex = headers.indexOf('Rate');

    const descriptionInput = descriptionIndex >= 0 && row.children[descriptionIndex]
        ? row.children[descriptionIndex].querySelector('input, select')
        : null;

    const rateInput = rateIndex >= 0 && row.children[rateIndex]
        ? row.children[rateIndex].querySelector('input, select')
        : null;

    if (itemValue === '') {
        input.dataset.itemId = '';
        if (descriptionInput) {
            descriptionInput.value = '';
        }
        if (rateInput) {
            rateInput.value = '';
        }
        recalcInvoiceRowAmount(row);
        return;
    }

    const options = Array.isArray(batchInvoiceItemOptions) ? batchInvoiceItemOptions : [];
    const matchedItem = options.find(option => {
        const label = String(option.label || option.name || '').trim();
        return label.toLowerCase() === itemValue.toLowerCase();
    });

    if (!matchedItem) {
        if (forceUpdate && descriptionInput) {
            descriptionInput.value = '';
        }
        return;
    }

    input.dataset.itemId = matchedItem.id || '';

    if (descriptionInput && (forceUpdate || descriptionInput.value.trim() === '')) {
        descriptionInput.value = matchedItem.description || itemValue;
    }

    if (rateInput && (forceUpdate || rateInput.value.trim() === '')) {
        const rate = matchedItem.unit_price || '';
        if (rate !== '') {
            rateInput.value = rate;
        }
    }

    recalcInvoiceRowAmount(row);
}

function recalcInvoiceRowAmount(row) {
    const selectedType = document.getElementById('transactionType')?.value || '';
    if (selectedType !== 'invoices' || !row) {
        return;
    }

    const headers = getCurrentHeaders();
    const qtyIndex = headers.indexOf('Qty');
    const rateIndex = headers.indexOf('Rate');
    const amountIndex = headers.findIndex(header => header.toLowerCase().includes('amount'));

    if (qtyIndex < 0 || rateIndex < 0 || amountIndex < 0) {
        return;
    }

    const qtyInput = row.children[qtyIndex]?.querySelector('input, select');
    const rateInput = row.children[rateIndex]?.querySelector('input, select');
    const amountInput = row.children[amountIndex]?.querySelector('input, select');

    if (!qtyInput || !rateInput || !amountInput) {
        return;
    }

    const qty = parseBatchNumber(qtyInput.value);
    const rate = parseBatchNumber(rateInput.value);

    if (qty > 0 && rate > 0) {
        amountInput.value = formatBatchNumber(qty * rate);
    }
}

function handleBatchCellInput(input) {
    updateBatchDateInputState(input);
    const row = input.closest('tr');
    if (row) {
        const headers = getCurrentHeaders();
        const cellIndex = Array.from(row.children).findIndex(cell => cell.contains(input));
        const header = cellIndex >= 0 ? headers[cellIndex] : '';

        if (document.getElementById('transactionType')?.value === 'invoices' && header === 'Item') {
            syncInvoiceItemDependentFields(input, false);
        }

        if (document.getElementById('transactionType')?.value === 'invoices' && ['Qty', 'Rate'].includes(header)) {
            recalcInvoiceRowAmount(row);
        }
    }

    updateTotals();

    
    const tableBody = document.getElementById('tableBody');
    if (!row || !tableBody) {
        return;
    }

    const rows = Array.from(tableBody.querySelectorAll('tr'));
    const rowIndex = rows.indexOf(row);

    if (rowIndex === rows.length - 1 && rowHasValue(row)) {
        appendTransactionRows(1, true);
    }
}

function updateTableScroll() {
    const tableBox = document.querySelector('.batch-grid-box');
    const rowCount = document.querySelectorAll('#tableBody tr').length;

    if (!tableBox) {
        return;
    }

    tableBox.style.overflowY = rowCount > 20 ? 'auto' : 'hidden';
}

function renderRows(config) {
    const tableBody = document.getElementById('tableBody');
    const selectedType = document.getElementById('transactionType').value;
    const headers = getActiveHeaders(config, selectedType);
    applyResponsiveTableWidth(headers);
    const rows = [];

    for (let r = 0; r < 20; r++) {
        rows.push(buildBatchRowHtml(headers, config, r));
    }

    tableBody.innerHTML = rows.join('');
    initializeBatchDateInputs(tableBody);
    updateTableScroll();
}

function renderTotals(config) {
    const summaryGrid = document.getElementById('summaryGrid');
    const negativeNote = document.getElementById('negativeNote');

    if (config.totals === 'chargeCredit') {
        summaryGrid.innerHTML = `
            <div></div>
            <div></div>
            <div class="footer-label">Total Charges:</div>
            <div class="footer-amount" id="chargeTotal">₱0.00</div>
            <div class="footer-label">Total Credits:</div>
            <div class="footer-amount" id="creditTotal">₱0.00</div>
        `;
    } else {
        summaryGrid.innerHTML = `
            <div></div>
            <div></div>
            <div></div>
            <div></div>
            <div class="footer-label">Total:</div>
            <div class="footer-amount" id="singleTotal">₱0.00</div>
        `;
    }

    negativeNote.classList.toggle('d-none', !config.note);
}


function refreshBatchTableWidth() {
    const selectedType = document.getElementById('transactionType')?.value || 'checks';
    const config = transactionConfigs[selectedType];
    if (!config) return;
    applyResponsiveTableWidth(getActiveHeaders(config, selectedType));
}

window.addEventListener('resize', refreshBatchTableWidth);

function loadTransactionType() {
    const selectedType = document.getElementById('transactionType').value;

    const config = transactionConfigs[selectedType];

    if (!config) {
        return;
    }

    renderAccountSelect(config);
    renderHeaders(config);
    renderRows(config);
    renderTotals(config);
    updateTotals();
    initAccountSelect();
}

function updateTotals() {
    const selectedType = document.getElementById('transactionType').value;
    const config = transactionConfigs[selectedType];
    if (!config) {
        return;
    }
    const amountInputs = document.querySelectorAll('.amount-input');
    let total = 0;
    let charges = 0;
    let credits = 0;

    amountInputs.forEach(input => {
        const value = parseFloat(String(input.value).replace(/,/g, '')) || 0;
        total += value;
        if (value < 0) {
            credits += Math.abs(value);
        } else {
            charges += value;
        }
    });

    if (config.totals === 'chargeCredit') {
        const chargeTotal = document.getElementById('chargeTotal');
        const creditTotal = document.getElementById('creditTotal');
        if (chargeTotal) chargeTotal.textContent = money(charges);
        if (creditTotal) creditTotal.textContent = money(credits);
    } else {
        const singleTotal = document.getElementById('singleTotal');
        if (singleTotal) singleTotal.textContent = money(total);
    }
}

function closeAllBatchDropdowns() {
    document.querySelectorAll('.batch-payee-dropdown, .batch-vendor-dropdown, .batch-customer-dropdown, .batch-item-dropdown, #batchAccountDropdown').forEach(dropdown => {
        dropdown.classList.remove('show');
        dropdown.style.display = 'none';
    });

    if (typeof closePayeeDropdown === 'function') closePayeeDropdown();
    if (typeof closeVendorDropdown === 'function') closeVendorDropdown();
    if (typeof closeCustomerDropdown === 'function') closeCustomerDropdown();
    if (typeof closeItemDropdown === 'function') closeItemDropdown();
    if (typeof closeBatchAccountDropdown === 'function') closeBatchAccountDropdown();

    if (typeof $ !== 'undefined') {
        $('.select2-hidden-accessible').select2('close');
    }
}

function resetBatchFormAfterSave() {
    closeAllBatchDropdowns();

    const transactionType = document.getElementById('transactionType');
    if (transactionType) {
        transactionType.value = 'checks';
        if (typeof $ !== 'undefined' && $('#transactionType').hasClass('select2-hidden-accessible')) {
            $('#transactionType').val('checks').trigger('change.select2');
        }
    }

    loadTransactionType();

    setTimeout(() => {
        if (typeof $ !== 'undefined' && $('#transactionType').hasClass('select2-hidden-accessible')) {
            $('#transactionType').val('checks').trigger('change.select2');
        }
        closeAllBatchDropdowns();
    }, 50);
}

function clearRows() {
    resetBatchFormAfterSave();
}


function getCurrentAccountType() {
    const selectedType = document.getElementById('transactionType').value;
    const config = transactionConfigs[selectedType];
    return config ? config.accountType : 'Bank';
}

function renderParentAccountOptions() {
    const accountType = document.getElementById('newAccountType').value.trim();
    const parentSelect = document.getElementById('newParentAccount');
    const accounts = batchAccountsByType[accountType] || [];

    parentSelect.innerHTML = '<option value="">Main account</option>' + accounts.map(account => {
        const label = escapeHtml(account.label || account.title || '');
        const value = escapeHtml(account.id || '');
        return `<option value="${value}">${label}</option>`;
    }).join('');
}

function openAddAccountModal() {
    const accountType = getCurrentAccountType();
    const accountSelect = document.getElementById('accountSelect');

    if (accountSelect && accountSelect.value === '__add_new_account__') {
        const firstRealOption = Array.from(accountSelect.options).find(option => option.value !== '__add_new_account__' && option.value !== '');
        accountSelect.value = firstRealOption ? firstRealOption.value : '';
        if (typeof $ !== 'undefined' && $('#accountSelect').hasClass('select2-hidden-accessible')) {
            $('#accountSelect').val(accountSelect.value).trigger('change.select2');
        }
    }

    document.getElementById('newAccountType').value = accountType;
    document.getElementById('newAccountCode').value = '';
    document.getElementById('newAccountTitle').value = '';
    document.getElementById('newAccountDescription').value = '';
    document.getElementById('newAccountBalance').value = '0.00';
    renderParentAccountOptions();
    document.getElementById('addAccountModal').classList.add('show');

    setTimeout(() => {
        const input = document.getElementById('newAccountTitle');
        if (input) input.focus();
    }, 100);
}

function closeAddAccountModal() {
    document.getElementById('addAccountModal').classList.remove('show');
}

function saveNewChartAccount() {
    const accountType = document.getElementById('newAccountType').value.trim();
    const parentAccountId = document.getElementById('newParentAccount').value.trim();
    const accountCode = document.getElementById('newAccountCode').value.trim();
    const accountTitle = document.getElementById('newAccountTitle').value.trim();
    const description = document.getElementById('newAccountDescription').value.trim();
    const balance = document.getElementById('newAccountBalance').value.trim() || '0.00';

    if (!accountType) {
        Swal.fire({
            icon: 'warning',
            title: 'Account type required',
            text: 'Please select an account type.',
            confirmButtonColor: '#047857'
        });
        return;
    }

    if (!accountTitle) {
        Swal.fire({
            icon: 'warning',
            title: 'Account title required',
            text: 'Please enter an account title.',
            confirmButtonColor: '#047857'
        });
        return;
    }

    const formData = new FormData();
    formData.append('action', 'save_chart_account');
    formData.append('account_type', accountType);
    formData.append('parent_account_id', parentAccountId);
    formData.append('account_code', accountCode);
    formData.append('account_title', accountTitle);
    formData.append('description', description);
    formData.append('balance', balance);

    Swal.fire({
        title: 'Saving account...',
        text: 'Please wait while the account is being added.',
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading()
    });

    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (!data.success) {
            throw new Error(data.message || 'Unable to add account.');
        }

        if (!batchAccountsByType[accountType]) {
            batchAccountsByType[accountType] = [];
        }

        batchAccountsByType[accountType].push(data.account);
        if (!Array.isArray(batchAllAccountOptions)) {
            batchAllAccountOptions = [];
        }
        batchAllAccountOptions.push(data.account);
        closeAddAccountModal();

        const selectedType = document.getElementById('transactionType').value;
        const config = transactionConfigs[selectedType];
        renderAccountSelect(config);
        initAccountSelect();

        const accountSelect = document.getElementById('accountSelect');
        accountSelect.value = String(data.account.id);

        if (typeof $ !== 'undefined' && $('#accountSelect').hasClass('select2-hidden-accessible')) {
            $('#accountSelect').val(String(data.account.id)).trigger('change.select2');
        }

        Swal.fire({
            icon: 'success',
            title: 'Account Added',
            text: data.message || 'Account saved to Chart of Accounts.',
            confirmButtonColor: '#047857'
        });
    })
    .catch(error => {
        Swal.fire({
            icon: 'error',
            title: 'Add account failed',
            text: error.message || 'Something went wrong while adding the account.',
            confirmButtonColor: '#047857'
        });
    });
}

function collectTransactionRows() {
    const headers = Array.from(document.querySelectorAll('#tableHead th')).map(th => th.textContent.trim());
    const rows = [];

    document.querySelectorAll('#tableBody tr').forEach(tr => {
        const inputs = tr.querySelectorAll('input, select');
        const row = {};
        let hasValue = false;

        headers.forEach((header, index) => {
            const value = inputs[index] ? inputs[index].value.trim() : '';
            row[header] = value;
            if (value !== '') {
                hasValue = true;
            }
        });

        if (hasValue) {
            rows.push(row);
        }
    });

    return rows;
}

function saveTransactions() {
    const selectedType = document.getElementById('transactionType').value;
    const accountSelect = document.getElementById('accountSelect');
    const selectedOption = accountSelect.options[accountSelect.selectedIndex];
    const rows = collectTransactionRows();

    if (!accountSelect.value || accountSelect.value === '__add_new_account__') {
        Swal.fire({
            icon: 'warning',
            title: 'Account required',
            text: 'Please select or add an account before saving.',
            confirmButtonColor: '#047857'
        });
        return;
    }

    if (!rows.length) {
        Swal.fire({
            icon: 'warning',
            title: 'No transactions',
            text: 'Please enter at least one transaction row before saving.',
            confirmButtonColor: '#047857'
        });
        return;
    }

    const rowsWithAmount = rows.filter(row => {
        const amountValue = row['Amount'] || row['*Amount'] || '';
        const cleanedAmount = String(amountValue).replace(/[,₱$ ]/g, '');
        return cleanedAmount !== '' && !isNaN(parseFloat(cleanedAmount)) && parseFloat(cleanedAmount) !== 0;
    });

    if (!rowsWithAmount.length) {
        Swal.fire({
            icon: 'warning',
            title: 'Amount required',
            text: 'Please enter an amount before saving.',
            confirmButtonColor: '#047857'
        });
        return;
    }

    const formData = new FormData();
    formData.append('action', 'save_batch_transactions');
    formData.append('transaction_type', selectedType);
    formData.append('account_id', accountSelect.value || '0');
    formData.append('account_label', selectedOption ? selectedOption.textContent.trim() : '');
    formData.append('rows', JSON.stringify(rowsWithAmount));

    Swal.fire({
        title: 'Saving...',
        text: 'Please wait while the transactions are being saved.',
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading()
    });

    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (!data.success) {
            throw new Error(data.message || 'Unable to save transactions.');
        }

        Swal.fire({
            icon: 'success',
            title: 'Saved',
            text: data.message || 'Transactions saved successfully.',
            confirmButtonColor: '#047857'
        }).then(() => {
            resetBatchFormAfterSave();

            // Replace the URL with a cache-busted copy so normal refresh will no longer
            // restore the stale dropdown state from the browser cache.
            if (window.history && window.history.replaceState) {
                const cleanUrl = window.location.pathname + '?bt_refresh=' + Date.now();
                window.history.replaceState(null, document.title, cleanUrl);
            }
        });
    })
    .catch(error => {
        Swal.fire({
            icon: 'error',
            title: 'Save failed',
            text: error.message || 'Something went wrong while saving.',
            confirmButtonColor: '#047857'
        });
    });
}

function toggleSidebarDropdown(event, menuId) {
    event.preventDefault();
    const menu = document.getElementById(menuId);
    if (menu) {
        menu.classList.toggle('show');
    }
}


function setActiveSidebarItem() {
    const currentPage = window.location.pathname.split('/').pop();

    document.querySelectorAll('.sidebar .nav-link').forEach(link => {
        link.classList.remove('active');
    });

    document.querySelectorAll('.sidebar .nav-link').forEach(link => {
        const href = link.getAttribute('href');
        if (href === currentPage) {
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

function updateDropdownParentActiveState() {
    const sidebar = document.getElementById('sidebar');
    if (!sidebar) return;

    const isCollapsed = sidebar.classList.contains('collapsed');

    document.querySelectorAll('.sidebar .dropdown-nav').forEach(dropdownNav => {
        const parentLink = dropdownNav.querySelector(':scope > .nav-link');
        const activeChild = dropdownNav.querySelector(':scope .collapse .nav-link.active');

        if (!parentLink) return;

        if (isCollapsed && activeChild) {
            parentLink.classList.add('active');
        } else {
            parentLink.classList.remove('active');
        }
    });
}

function clearDropdownParentActiveState() {
    document.querySelectorAll('.sidebar .dropdown-nav > .nav-link').forEach(parentLink => {
        parentLink.classList.remove('active');
    });
}

function expandActiveDropdownContainers() {
    const sidebar = document.getElementById('sidebar');
    if (!sidebar) return;

    document.querySelectorAll('.sidebar .dropdown-nav').forEach(dropdownNav => {
        const activeChild = dropdownNav.querySelector(':scope .collapse .nav-link.active');
        const collapseDiv = dropdownNav.querySelector(':scope .collapse');
        const parentLink = dropdownNav.querySelector(':scope > .nav-link');

        if (activeChild && collapseDiv) {
            collapseDiv.classList.add('show');

            if (parentLink) {
                const arrow = parentLink.querySelector('.dropdown-arrow');
                if (arrow) {
                    arrow.style.transform = 'translateY(-50%) rotate(180deg)';
                }

                if (sidebar.classList.contains('collapsed')) {
                    parentLink.classList.add('active');
                } else {
                    parentLink.classList.remove('active');
                }
            }
        }
    });
}

function applySidebarLayoutState() {
    const sidebar = document.getElementById('sidebar');
    const mainContent = document.getElementById('mainContent');

    if (!sidebar || !mainContent) return;

    if (window.innerWidth <= 992) {
        mainContent.classList.remove('sidebar-expanded', 'sidebar-collapsed');
        document.querySelectorAll('.nav-text').forEach(text => {
            text.style.display = '';
        });
        return;
    }

    const isCollapsed = sidebar.classList.contains('collapsed');

    mainContent.classList.toggle('sidebar-collapsed', isCollapsed);
    mainContent.classList.toggle('sidebar-expanded', !isCollapsed);

    document.querySelectorAll('.nav-text').forEach(text => {
        text.style.display = isCollapsed ? 'none' : 'inline-block';
    });

    if (!isCollapsed) {
        clearDropdownParentActiveState();
    }

    updateDropdownParentActiveState();
}

function closeMobileSidebar() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.querySelector('.sidebar-overlay');

    if (sidebar) {
        sidebar.classList.remove('active');
    }

    if (overlay) {
        overlay.classList.remove('active');
        setTimeout(() => overlay.remove(), 300);
    }
}

function toggleSidebar() {
    const sidebar = document.getElementById('sidebar');
    if (!sidebar) return;

    const isMobile = window.innerWidth <= 992;

    if (isMobile) {
        sidebar.classList.toggle('active');

        if (sidebar.classList.contains('active') && !document.querySelector('.sidebar-overlay')) {
            const overlay = document.createElement('div');
            overlay.className = 'sidebar-overlay';
            document.body.appendChild(overlay);
            overlay.addEventListener('click', closeMobileSidebar);
            setTimeout(() => overlay.classList.add('active'), 10);
        }

        if (!sidebar.classList.contains('active')) {
            closeMobileSidebar();
        }

        return;
    }

    sidebar.classList.toggle('collapsed');
    localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed') ? 'true' : 'false');

    if (sidebar.classList.contains('collapsed')) {
        document.querySelectorAll('.sidebar .collapse.show').forEach(collapse => {
            collapse.classList.remove('show');

            const parentBtn = document.querySelector(`[onclick*="${collapse.id}"]`);
            if (parentBtn) {
                const arrow = parentBtn.querySelector('.dropdown-arrow');
                if (arrow) arrow.style.transform = 'translateY(-50%) rotate(0deg)';
            }
        });
    } else {
        clearDropdownParentActiveState();
        setActiveSidebarItem();
        setTimeout(() => {
            expandActiveDropdownContainers();
            updateDropdownParentActiveState();
        }, 120);
    }

    applySidebarLayoutState();
}
window.toggleSidebar = toggleSidebar;

function initializeSidebar() {
    const sidebar = document.getElementById('sidebar');
    if (!sidebar) return;

    if (window.innerWidth > 992) {
        const savedCollapsed = localStorage.getItem('sidebarCollapsed') === 'true';
        sidebar.classList.toggle('collapsed', savedCollapsed);
    } else {
        sidebar.classList.remove('collapsed');
    }

    applySidebarLayoutState();
}

document.addEventListener('DOMContentLoaded', function() {
    const sidebar = document.getElementById('sidebar');

    initializeSidebar();
    setActiveSidebarItem();
    if (sidebar && !sidebar.classList.contains('collapsed')) {
        clearDropdownParentActiveState();
    }
    expandActiveDropdownContainers();
    updateDropdownParentActiveState();
    applySidebarLayoutState();

    document.querySelectorAll('.sidebar .collapse').forEach(collapse => {
        collapse.addEventListener('click', function(e) {
            e.stopPropagation();
        });
    });

    const desktopToggleBtn = document.getElementById('desktopToggleBtn');
    if (desktopToggleBtn) {
        desktopToggleBtn.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            toggleSidebar();
        });
    }

    const mobileMenuBtn = document.getElementById('mobileMenuBtn');
    if (mobileMenuBtn) {
        mobileMenuBtn.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            toggleSidebar();
        });
    }

    document.querySelectorAll('.sidebar .nav-link').forEach(link => {
        link.addEventListener('click', function() {
            if (window.innerWidth <= 992) {
                closeMobileSidebar();
            }
        });
    });

    document.addEventListener('click', function(event) {
        const sidebar = document.getElementById('sidebar');
        const mobileMenuBtn = document.getElementById('mobileMenuBtn');

        if (
            window.innerWidth <= 992 &&
            sidebar &&
            sidebar.classList.contains('active') &&
            !sidebar.contains(event.target) &&
            (!mobileMenuBtn || !mobileMenuBtn.contains(event.target))
        ) {
            closeMobileSidebar();
        }
    });

    window.addEventListener('resize', function() {
        const sidebar = document.getElementById('sidebar');

        if (!sidebar) return;

        if (window.innerWidth > 992) {
            closeMobileSidebar();

            const savedCollapsed = localStorage.getItem('sidebarCollapsed') === 'true';
            sidebar.classList.toggle('collapsed', savedCollapsed);
        } else {
            sidebar.classList.remove('collapsed');
        }

        applySidebarLayoutState();
    });
});

function confirmLogout() {
    const modalEl = document.getElementById('profileModal');

    if (modalEl && typeof bootstrap !== 'undefined' && bootstrap.Modal) {
        const modal = bootstrap.Modal.getInstance(modalEl);
        if (modal) modal.hide();
    }

    localStorage.removeItem('sidebarCollapsed');

    if (typeof Swal !== 'undefined') {
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
                window.location.href = '../logout.php';
            }
        });
    } else {
        if (confirm('Are you sure you want to logout?')) {
            window.location.href = '../logout.php';
        }
    }
}

function logout() {
    confirmLogout();
}


document.addEventListener('DOMContentLoaded', function () {
    initTransactionTypeSelect();

    if (typeof $ !== 'undefined') {
        $('#transactionType').off('change.batchTransaction').on('change.batchTransaction', function () {
            closeAllBatchDropdowns();
            loadTransactionType();
        });
    } else {
        document.getElementById('transactionType').addEventListener('change', function () {
            closeAllBatchDropdowns();
            loadTransactionType();
        });
    }

    const transactionType = document.getElementById('transactionType');
    if (transactionType && !transactionConfigs[transactionType.value]) {
        transactionType.value = 'checks';
    }

    loadTransactionType();
    initializeBatchDateInputs();
    closeAllBatchDropdowns();
});

window.addEventListener('pageshow', function (event) {
    if (event.persisted) {
        closeAllBatchDropdowns();
        loadTransactionType();
    }
});

document.addEventListener('DOMContentLoaded', function () {
    const sidebar = document.querySelector('.sidebar-content');
    const activeLink = document.querySelector('.sidebar .nav-link.active');

    if (!sidebar || !activeLink) return;

    const collapse = activeLink.closest('.collapse');
    if (collapse) {
        collapse.classList.add('show');

        const trigger = document.querySelector(
            `[onclick*="${collapse.id}"]`
        );

        if (trigger) {
            trigger.setAttribute('aria-expanded', 'true');

            const arrow = trigger.querySelector('.dropdown-arrow');
            if (arrow) {
                arrow.style.transform = 'rotate(180deg)';
            }
        }
    }

    setTimeout(() => {
        activeLink.scrollIntoView({
            behavior: 'smooth',
            block: 'center'
        });
    }, 200);
});

// ========== MOBILE BOTTOM NAVBAR FUNCTIONS ==========
function closeAllMobileDropdowns() {
    document.querySelectorAll('.mobile-nav .more-dropdown.show').forEach(function (dropdown) {
        dropdown.classList.remove('show');
    });
    document.querySelectorAll('.mobile-nav .more-btn.active').forEach(function (btn) {
        const menuId = (btn.getAttribute('onclick') || '').match(/'([^']+)'/);
        const menu = menuId ? document.getElementById(menuId[1]) : null;
        const hasActiveChild = menu && menu.querySelector('.dropdown-item.active');
        if (!hasActiveChild) btn.classList.remove('active');
    });
}

function toggleMobileDropdown(event, dropdownId) {
    if (event) {
        event.preventDefault();
        event.stopPropagation();
    }
    const dropdown = document.getElementById(dropdownId);
    const btn = event ? event.currentTarget : document.querySelector(`[onclick*="${dropdownId}"]`);
    if (!dropdown || !btn) return false;

    if (dropdown.classList.contains('show')) {
        dropdown.classList.remove('show');
        if (!dropdown.querySelector('.dropdown-item.active')) btn.classList.remove('active');
    } else {
        closeAllMobileDropdowns();
        dropdown.classList.add('show');
        btn.classList.add('active');
    }
    return false;
}

function setActiveMobileNav() {
    const currentPage = window.location.pathname.split('/').pop() || 'request_handler.php';
    document.querySelectorAll('.mobile-nav .nav-link, .mobile-nav .dropdown-item').forEach(function (link) {
        link.classList.remove('active');
    });
    document.querySelectorAll('.mobile-nav .nav-link, .mobile-nav .dropdown-item').forEach(function (link) {
        const href = (link.getAttribute('href') || '').split('?')[0];
        if (href === currentPage) {
            link.classList.add('active');
            const parentDropdown = link.closest('.more-dropdown');
            if (parentDropdown) {
                const parentBtn = document.querySelector(`[onclick*="${parentDropdown.id}"]`);
                if (parentBtn) parentBtn.classList.add('active');
            }
        }
    });
}

document.addEventListener('DOMContentLoaded', function () {
    const profileBtn = document.getElementById('openProfileModalBtn');
    const profileModalElement = document.getElementById('profileModal');

    if (!profileBtn || !profileModalElement) {
        return;
    }

    profileBtn.addEventListener('click', function (event) {
        event.preventDefault();
        event.stopPropagation();

        closeAllMobileDropdowns();

        if (typeof bootstrap === 'undefined' || !bootstrap.Modal) {
            console.error('Bootstrap Modal is unavailable. Check bootstrap.bundle.min.js.');
            return;
        }

        const profileModal = bootstrap.Modal.getOrCreateInstance(
            profileModalElement,
            {
                backdrop: true,
                keyboard: true,
                focus: true
            }
        );

        profileModal.show();
    });

    profileModalElement.addEventListener('shown.bs.modal', function () {
        profileModalElement.style.zIndex = '20050';

        const backdrops = document.querySelectorAll('.modal-backdrop');
        const latestBackdrop = backdrops[backdrops.length - 1];
        if (latestBackdrop) {
            latestBackdrop.style.zIndex = '20040';
        }
    });
});
document.addEventListener('click', function (e) {
    if (!e.target.closest('.mobile-nav .dropdown-more')) closeAllMobileDropdowns();
});

document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeAllMobileDropdowns();
});
</script>
</body>
</html>
