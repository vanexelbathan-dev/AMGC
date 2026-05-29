<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../config/database.php';
require_once '../config/session_handler.php';

requireLogin();
requireRole(['rolling']);

if (!$conn) {
    die('Database connection failed: ' . mysqli_connect_error());
}

$user_id = (int)($_SESSION['user_id'] ?? 0);
$user_name = trim(($_SESSION['first_name'] ?? 'Rolling') . ' ' . ($_SESSION['last_name'] ?? 'Account'));
$user_role = $_SESSION['role'] ?? 'rolling';
$branch_id = (int)($_SESSION['rolling_branch_id'] ?? $_SESSION['branch_id'] ?? 0);
$view_all_branches = false;

function h($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function moneyFmt($value) {
    return '₱' . number_format((float)$value, 2);
}

function tableExistsSafe(mysqli $conn, string $table): bool {
    $safe = $conn->real_escape_string($table);
    $res = $conn->query("SHOW TABLES LIKE '{$safe}'");
    return $res && $res->num_rows > 0;
}

function columnExistsSafe(mysqli $conn, string $table, string $column): bool {
    if (!tableExistsSafe($conn, $table)) return false;
    $safeTable = preg_replace('/[^A-Za-z0-9_]/', '', $table);
    $safeColumn = $conn->real_escape_string($column);
    $res = $conn->query("SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeColumn}'");
    return $res && $res->num_rows > 0;
}

function getUserInitials($name) {
    $parts = preg_split('/\s+/', trim((string)$name));
    $initials = '';
    foreach ($parts as $part) {
        if ($part !== '') $initials .= strtoupper(substr($part, 0, 1));
    }
    return $initials !== '' ? substr($initials, 0, 2) : 'RA';
}

function detectDateColumn(mysqli $conn, string $table, array $candidates): ?string {
    foreach ($candidates as $col) {
        if (columnExistsSafe($conn, $table, $col)) return $col;
    }
    return null;
}

function getBranchName(mysqli $conn, int $branch_id): string {
    if ($branch_id <= 0 || !tableExistsSafe($conn, 'branches')) return 'All Branches';
    $stmt = $conn->prepare('SELECT branch_name FROM branches WHERE branch_id = ? LIMIT 1');
    if (!$stmt) return 'Branch ' . $branch_id;
    $stmt->bind_param('i', $branch_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row['branch_name'] ?? ('Branch ' . $branch_id);
}

function resolveReportRange(string $period, string $baseDate, string $dateFrom, string $dateTo): array {
    $today = date('Y-m-d');
    $baseDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $baseDate) ? $baseDate : $today;
    $period = in_array($period, ['daily','weekly','monthly','custom'], true) ? $period : 'daily';

    if ($period === 'custom') {
        $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) ? $dateFrom : $today;
        $to = preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo) ? $dateTo : $from;
        if ($from > $to) { $tmp = $from; $from = $to; $to = $tmp; }
        return [$from, $to, 'Custom Date'];
    }

    if ($period === 'weekly') {
        $start = date('Y-m-d', strtotime('monday this week', strtotime($baseDate)));
        $end = date('Y-m-d', strtotime('sunday this week', strtotime($baseDate)));
        return [$start, $end, 'Weekly'];
    }

    if ($period === 'monthly') {
        $start = date('Y-m-01', strtotime($baseDate));
        $end = date('Y-m-t', strtotime($baseDate));
        return [$start, $end, 'Monthly'];
    }

    return [$baseDate, $baseDate, 'Daily'];
}

function rowsQuery(mysqli $conn, string $sql, string $types = '', array $params = []): array {
    $stmt = $conn->prepare($sql);
    if (!$stmt) return [];
    if ($types !== '' && !empty($params)) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();
    return $rows;
}

function fetchSalesOrderReport(mysqli $conn, int $user_id, int $branch_id, string $from, string $to): array {
    if (!tableExistsSafe($conn, 'sales_orders')) return [];
    $dateCol = detectDateColumn($conn, 'sales_orders', ['created_at','order_date','updated_at']);
    if (!$dateCol) return [];
    $hasInvoice = tableExistsSafe($conn, 'invoices') && columnExistsSafe($conn, 'invoices', 'so_id');
    $customerJoin = tableExistsSafe($conn, 'customers') ? "LEFT JOIN customers c ON c.customer_id = so.customer_id" : "";
    $invoiceJoin = $hasInvoice ? "LEFT JOIN invoices inv ON inv.so_id = so.so_id" : "";
    $customerName = tableExistsSafe($conn, 'customers') ? "COALESCE(c.customer_name, '')" : "''";
    $storeName = (tableExistsSafe($conn, 'customers') && columnExistsSafe($conn, 'customers', 'store_name')) ? "COALESCE(c.store_name, '')" : "''";
    $invoiceNumber = $hasInvoice ? "COALESCE(inv.invoice_number, '')" : "''";
    $invoiceStatus = $hasInvoice ? "COALESCE(inv.status, '')" : "''";
    $fulfillment = columnExistsSafe($conn, 'sales_orders', 'fulfillment_type') ? "COALESCE(so.fulfillment_type, '')" : "''";
    $paymentStatus = columnExistsSafe($conn, 'sales_orders', 'payment_status') ? "COALESCE(so.payment_status, '')" : "''";
    $branchCond = columnExistsSafe($conn, 'sales_orders', 'branch_id') && $branch_id > 0 ? " AND so.branch_id = ?" : "";
    $sql = "SELECT so.so_id, so.so_number, DATE(so.`$dateCol`) AS report_date, COALESCE(so.total_amount,0) AS amount,
                   COALESCE(so.order_status,'') AS status, $fulfillment AS fulfillment_type, $paymentStatus AS payment_status,
                   $customerName AS customer_name, $storeName AS store_name, $invoiceNumber AS invoice_number, $invoiceStatus AS invoice_status
            FROM sales_orders so
            $customerJoin
            $invoiceJoin
            WHERE so.created_by = ? $branchCond AND DATE(so.`$dateCol`) BETWEEN ? AND ?
            GROUP BY so.so_id
            ORDER BY so.`$dateCol` DESC, so.so_id DESC";
    $types = 'i'; $params = [$user_id];
    if ($branchCond) { $types .= 'i'; $params[] = $branch_id; }
    $types .= 'ss'; $params[] = $from; $params[] = $to;
    return rowsQuery($conn, $sql, $types, $params);
}

function fetchOrderItemsReport(mysqli $conn, int $user_id, int $branch_id, string $from, string $to): array {
    if (!tableExistsSafe($conn, 'sales_orders') || !tableExistsSafe($conn, 'sales_order_items')) return [];
    $dateCol = detectDateColumn($conn, 'sales_orders', ['created_at','order_date','updated_at']);
    if (!$dateCol) return [];
    $itemJoin = tableExistsSafe($conn, 'items') ? "LEFT JOIN items i ON i.item_id = soi.item_id" : "";
    $itemName = tableExistsSafe($conn, 'items') ? "COALESCE(i.item_name, CONCAT('Item #', soi.item_id))" : "CONCAT('Item #', soi.item_id)";
    $itemCode = tableExistsSafe($conn, 'items') && columnExistsSafe($conn, 'items', 'item_code') ? "COALESCE(i.item_code, '')" : "''";
    $branchCond = columnExistsSafe($conn, 'sales_orders', 'branch_id') && $branch_id > 0 ? " AND so.branch_id = ?" : "";
    $qtyCol = columnExistsSafe($conn, 'sales_order_items', 'quantity_ordered') ? 'quantity_ordered' : 'quantity';
    $priceCol = columnExistsSafe($conn, 'sales_order_items', 'unit_price') ? 'unit_price' : 'price';
    $unitCol = columnExistsSafe($conn, 'sales_order_items', 'unit_type') ? 'unit_type' : "''";
    $sql = "SELECT so.so_number, DATE(so.`$dateCol`) AS report_date, $itemCode AS item_code, $itemName AS item_name,
                   COALESCE(soi.`$qtyCol`,0) AS quantity, " . ($unitCol === "''" ? "''" : "COALESCE(soi.`$unitCol`, '')") . " AS unit_type,
                   COALESCE(soi.`$priceCol`,0) AS unit_price,
                   (COALESCE(soi.`$qtyCol`,0) * COALESCE(soi.`$priceCol`,0)) AS line_total
            FROM sales_order_items soi
            JOIN sales_orders so ON so.so_id = soi.so_id
            $itemJoin
            WHERE so.created_by = ? $branchCond AND DATE(so.`$dateCol`) BETWEEN ? AND ?
            ORDER BY so.`$dateCol` DESC, so.so_id DESC, soi.so_item_id ASC";
    $types = 'i'; $params = [$user_id];
    if ($branchCond) { $types .= 'i'; $params[] = $branch_id; }
    $types .= 'ss'; $params[] = $from; $params[] = $to;
    return rowsQuery($conn, $sql, $types, $params);
}

function fetchCollectionsReport(mysqli $conn, int $user_id, int $branch_id, string $from, string $to): array {
    $rows = [];
    if (tableExistsSafe($conn, 'collection_records')) {
        $dateCol = detectDateColumn($conn, 'collection_records', ['collection_date','created_at','remitted_at']);
        if ($dateCol) {
            $customerJoin = tableExistsSafe($conn, 'customers') ? "LEFT JOIN customers c ON c.customer_id = COALESCE(NULLIF(cr.customer_id,0), i.customer_id)" : "";
            $invoiceJoin = tableExistsSafe($conn, 'invoices') ? "LEFT JOIN invoices i ON i.invoice_id = cr.invoice_id" : "";
            $customerName = tableExistsSafe($conn, 'customers') ? "COALESCE(c.customer_name, 'Unknown')" : "'Unknown'";
            $invoiceNo = tableExistsSafe($conn, 'invoices') ? "COALESCE(i.invoice_number, CONCAT('INV-', cr.invoice_id))" : "CONCAT('INV-', cr.invoice_id)";
            $branchCond = columnExistsSafe($conn, 'collection_records', 'branch_id') && $branch_id > 0 ? " AND (cr.branch_id = ? OR cr.branch_id = 0)" : "";
            $sql = "SELECT cr.record_id, DATE(cr.`$dateCol`) AS report_date, $invoiceNo AS invoice_number, $customerName AS customer_name,
                           COALESCE(cr.payment_method,'') AS payment_method, COALESCE(cr.reference_number, cr.check_number, '') AS reference_number,
                           COALESCE(cr.status,'') AS status, COALESCE(cr.amount,0) AS amount
                    FROM collection_records cr
                    $invoiceJoin
                    $customerJoin
                    WHERE cr.collector_user_id = ? $branchCond AND DATE(cr.`$dateCol`) BETWEEN ? AND ?
                    ORDER BY cr.`$dateCol` DESC, cr.record_id DESC";
            $types = 'i'; $params = [$user_id];
            if ($branchCond) { $types .= 'i'; $params[] = $branch_id; }
            $types .= 'ss'; $params[] = $from; $params[] = $to;
            $rows = array_merge($rows, rowsQuery($conn, $sql, $types, $params));
        }
    }

    if (tableExistsSafe($conn, 'payments')) {
        $dateCol = detectDateColumn($conn, 'payments', ['payment_date','created_at']);
        if ($dateCol) {
            $invoiceJoin = tableExistsSafe($conn, 'invoices') ? "LEFT JOIN invoices i ON i.invoice_id = p.invoice_id" : "";
            $customerJoin = tableExistsSafe($conn, 'customers') ? "LEFT JOIN customers c ON c.customer_id = COALESCE(NULLIF(p.customer_id,0), i.customer_id)" : "";
            $soJoin = (tableExistsSafe($conn, 'sales_orders') && tableExistsSafe($conn, 'invoices') && columnExistsSafe($conn, 'invoices', 'so_id')) ? "LEFT JOIN sales_orders so ON so.so_id = i.so_id" : "";
            $customerName = tableExistsSafe($conn, 'customers') ? "COALESCE(c.customer_name, 'Unknown')" : "'Unknown'";
            $invoiceNo = tableExistsSafe($conn, 'invoices') ? "COALESCE(i.invoice_number, CONCAT('INV-', p.invoice_id))" : "CONCAT('INV-', p.invoice_id)";
            $branchCond = ($branch_id > 0 && tableExistsSafe($conn, 'sales_orders') && tableExistsSafe($conn, 'invoices') && columnExistsSafe($conn, 'invoices', 'so_id')) ? " AND (so.branch_id = ? OR so.branch_id IS NULL)" : "";
            $sql = "SELECT p.payment_id AS record_id, DATE(p.`$dateCol`) AS report_date, $invoiceNo AS invoice_number, $customerName AS customer_name,
                           COALESCE(p.payment_method,'') AS payment_method, COALESCE(p.reference_number, p.check_number, '') AS reference_number,
                           COALESCE(p.status,'completed') AS status, COALESCE(p.amount,0) AS amount
                    FROM payments p
                    $invoiceJoin
                    $customerJoin
                    $soJoin
                    WHERE p.created_by = ? $branchCond AND DATE(p.`$dateCol`) BETWEEN ? AND ?
                    ORDER BY p.`$dateCol` DESC, p.payment_id DESC";
            $types = 'i'; $params = [$user_id];
            if ($branchCond) { $types .= 'i'; $params[] = $branch_id; }
            $types .= 'ss'; $params[] = $from; $params[] = $to;
            $paymentRows = rowsQuery($conn, $sql, $types, $params);

            foreach ($paymentRows as $pay) {
                $duplicate = false;
                foreach ($rows as $r) {
                    if (($r['invoice_number'] ?? '') === ($pay['invoice_number'] ?? '') && abs((float)$r['amount'] - (float)$pay['amount']) < 0.01 && ($r['report_date'] ?? '') === ($pay['report_date'] ?? '')) {
                        $duplicate = true;
                        break;
                    }
                }
                if (!$duplicate) $rows[] = $pay;
            }
        }
    }

    usort($rows, function($a, $b) {
        return strcmp(($b['report_date'] ?? ''), ($a['report_date'] ?? '')) ?: ((int)($b['record_id'] ?? 0) <=> (int)($a['record_id'] ?? 0));
    });
    return $rows;
}

function fetchExpensesReport(mysqli $conn, int $user_id, int $branch_id, string $from, string $to): array {
    $rows = [];

    if (tableExistsSafe($conn, 'rolling_expenses')) {
        $dateCol = detectDateColumn($conn, 'rolling_expenses', ['expense_date','transaction_date','created_at']);
        if ($dateCol) {
            $accountCol = columnExistsSafe($conn, 'rolling_expenses', 'expense_account') ? 'expense_account' : (columnExistsSafe($conn, 'rolling_expenses', 'category') ? 'category' : null);
            $descCol = columnExistsSafe($conn, 'rolling_expenses', 'description') ? 'description' : (columnExistsSafe($conn, 'rolling_expenses', 'remarks') ? 'remarks' : null);
            $payeeCol = columnExistsSafe($conn, 'rolling_expenses', 'payee') ? 'payee' : null;
            $amountCol = columnExistsSafe($conn, 'rolling_expenses', 'amount') ? 'amount' : null;
            $createdCol = columnExistsSafe($conn, 'rolling_expenses', 'created_by') ? 'created_by' : (columnExistsSafe($conn, 'rolling_expenses', 'user_id') ? 'user_id' : null);
            if ($amountCol && $createdCol) {
                $branchCond = columnExistsSafe($conn, 'rolling_expenses', 'branch_id') && $branch_id > 0 ? " AND (branch_id = ? OR branch_id = 0)" : "";
                $sql = "SELECT expense_id AS record_id, DATE(`$dateCol`) AS report_date,
                               " . ($accountCol ? "COALESCE(`$accountCol`, 'Rolling Expense')" : "'Rolling Expense'") . " AS expense_account,
                               " . ($payeeCol ? "COALESCE(`$payeeCol`, '')" : "''") . " AS payee,
                               " . ($descCol ? "COALESCE(`$descCol`, '')" : "''") . " AS description,
                               COALESCE(`$amountCol`,0) AS amount
                        FROM rolling_expenses
                        WHERE `$createdCol` = ? $branchCond AND DATE(`$dateCol`) BETWEEN ? AND ?
                        ORDER BY `$dateCol` DESC, expense_id DESC";
                $types = 'i'; $params = [$user_id];
                if ($branchCond) { $types .= 'i'; $params[] = $branch_id; }
                $types .= 'ss'; $params[] = $from; $params[] = $to;
                $rows = array_merge($rows, rowsQuery($conn, $sql, $types, $params));
            }
        }
    }

    if (tableExistsSafe($conn, 'bank_transactions')) {
        $branchCond = columnExistsSafe($conn, 'bank_transactions', 'branch_id') && $branch_id > 0 ? " AND bt.branch_id = ?" : "";
        $createdCol = columnExistsSafe($conn, 'bank_transactions', 'created_by') ? 'bt.created_by' : '0';
        $sql = "SELECT bt.transaction_id AS record_id, DATE(bt.transaction_date) AS report_date,
                       COALESCE(bt.expense_account, 'Expense') AS expense_account,
                       COALESCE(bt.payee, '') AS payee,
                       COALESCE(bt.description, '') AS description,
                       COALESCE(bt.amount,0) AS amount
                FROM bank_transactions bt
                WHERE bt.transaction_type = 'withdrawal'
                  AND TRIM(COALESCE(bt.expense_account,'')) <> ''
                  AND $createdCol = ? $branchCond
                  AND DATE(bt.transaction_date) BETWEEN ? AND ?
                ORDER BY bt.transaction_date DESC, bt.transaction_id DESC";
        $types = 'i'; $params = [$user_id];
        if ($branchCond) { $types .= 'i'; $params[] = $branch_id; }
        $types .= 'ss'; $params[] = $from; $params[] = $to;
        $bankRows = rowsQuery($conn, $sql, $types, $params);
        $rows = array_merge($rows, $bankRows);
    }

    usort($rows, function($a, $b) {
        return strcmp(($b['report_date'] ?? ''), ($a['report_date'] ?? '')) ?: ((int)($b['record_id'] ?? 0) <=> (int)($a['record_id'] ?? 0));
    });
    return $rows;
}

function fetchInventoryReceiveReport(mysqli $conn, int $user_id, int $branch_id, string $from, string $to): array {
    $rows = [];
    if (tableExistsSafe($conn, 'rolling_inventory_transfers') && tableExistsSafe($conn, 'rolling_inventory_transfer_items')) {
        $dateCol = detectDateColumn($conn, 'rolling_inventory_transfers', ['receive_date','created_at']);
        if ($dateCol) {
            $itemJoin = tableExistsSafe($conn, 'items') ? "LEFT JOIN items i ON i.item_id = rti.item_id" : "";
            $itemName = tableExistsSafe($conn, 'items') ? "COALESCE(i.item_name, CONCAT('Item #', rti.item_id))" : "CONCAT('Item #', rti.item_id)";
            $itemCode = tableExistsSafe($conn, 'items') && columnExistsSafe($conn, 'items', 'item_code') ? "COALESCE(i.item_code, '')" : "''";
            $branchCond = columnExistsSafe($conn, 'rolling_inventory_transfers', 'rolling_branch_id') && $branch_id > 0 ? " AND rt.rolling_branch_id = ?" : "";
            $sql = "SELECT rt.transfer_id, COALESCE(rt.transfer_number, CONCAT('RT-', rt.transfer_id)) AS reference_number,
                           DATE(rt.`$dateCol`) AS report_date, $itemCode AS item_code, $itemName AS item_name,
                           COALESCE(rti.unit_type_name, '') AS unit_type, COALESCE(rti.quantity_received,0) AS quantity,
                           COALESCE(rt.status, 'received') AS status
                    FROM rolling_inventory_transfer_items rti
                    JOIN rolling_inventory_transfers rt ON rt.transfer_id = rti.transfer_id
                    $itemJoin
                    WHERE rt.received_by = ? $branchCond AND DATE(rt.`$dateCol`) BETWEEN ? AND ?
                    ORDER BY rt.`$dateCol` DESC, rt.transfer_id DESC, rti.transfer_item_id ASC";
            $types = 'i'; $params = [$user_id];
            if ($branchCond) { $types .= 'i'; $params[] = $branch_id; }
            $types .= 'ss'; $params[] = $from; $params[] = $to;
            $rows = rowsQuery($conn, $sql, $types, $params);
        }
    }

    if (empty($rows) && tableExistsSafe($conn, 'inventory_transactions')) {
        $dateCol = detectDateColumn($conn, 'inventory_transactions', ['created_at','transaction_date']);
        $qtyCol = columnExistsSafe($conn, 'inventory_transactions', 'quantity_changed') ? 'quantity_changed' : (columnExistsSafe($conn, 'inventory_transactions', 'quantity') ? 'quantity' : null);
        if ($dateCol && $qtyCol) {
            $itemJoin = tableExistsSafe($conn, 'items') ? "LEFT JOIN items i ON i.item_id = it.item_id" : "";
            $itemName = tableExistsSafe($conn, 'items') ? "COALESCE(i.item_name, CONCAT('Item #', it.item_id))" : "CONCAT('Item #', it.item_id)";
            $itemCode = tableExistsSafe($conn, 'items') && columnExistsSafe($conn, 'items', 'item_code') ? "COALESCE(i.item_code, '')" : "''";
            $branchCond = columnExistsSafe($conn, 'inventory_transactions', 'branch_id') && $branch_id > 0 ? " AND it.branch_id = ?" : "";
            $createdCond = columnExistsSafe($conn, 'inventory_transactions', 'created_by') ? " AND it.created_by = ?" : "";
            $sql = "SELECT it.transaction_id AS transfer_id, COALESCE(it.reference_type, 'Inventory') AS reference_number,
                           DATE(it.`$dateCol`) AS report_date, $itemCode AS item_code, $itemName AS item_name,
                           '' AS unit_type, COALESCE(it.`$qtyCol`,0) AS quantity, COALESCE(it.transaction_type, '') AS status
                    FROM inventory_transactions it
                    $itemJoin
                    WHERE COALESCE(it.`$qtyCol`,0) > 0 $createdCond $branchCond AND DATE(it.`$dateCol`) BETWEEN ? AND ?
                    ORDER BY it.`$dateCol` DESC, it.transaction_id DESC";
            $types = ''; $params = [];
            if ($createdCond) { $types .= 'i'; $params[] = $user_id; }
            if ($branchCond) { $types .= 'i'; $params[] = $branch_id; }
            $types .= 'ss'; $params[] = $from; $params[] = $to;
            $rows = rowsQuery($conn, $sql, $types, $params);
        }
    }
    return $rows;
}

function fetchCustomerReport(mysqli $conn, int $user_id, int $branch_id, string $from, string $to): array {
    if (!tableExistsSafe($conn, 'customers')) return [];
    $dateCol = detectDateColumn($conn, 'customers', ['created_at','updated_at']);
    if (!$dateCol) return [];
    $createdCond = columnExistsSafe($conn, 'customers', 'created_by') ? " AND created_by = ?" : "";
    $branchCond = columnExistsSafe($conn, 'customers', 'branch_id') && $branch_id > 0 ? " AND branch_id = ?" : "";
    $storeCol = columnExistsSafe($conn, 'customers', 'store_name') ? 'store_name' : null;
    $phoneCol = columnExistsSafe($conn, 'customers', 'phone_number') ? 'phone_number' : (columnExistsSafe($conn, 'customers', 'phone') ? 'phone' : null);
    $sql = "SELECT customer_id, DATE(`$dateCol`) AS report_date, COALESCE(customer_code, '') AS customer_code,
                   COALESCE(customer_name, '') AS customer_name,
                   " . ($storeCol ? "COALESCE(`$storeCol`, '')" : "''") . " AS store_name,
                   " . ($phoneCol ? "COALESCE(`$phoneCol`, '')" : "''") . " AS phone_number,
                   COALESCE(address, '') AS address, COALESCE(status, '') AS status
            FROM customers
            WHERE 1=1 $createdCond $branchCond AND DATE(`$dateCol`) BETWEEN ? AND ?
            ORDER BY `$dateCol` DESC, customer_id DESC";
    $types = ''; $params = [];
    if ($createdCond) { $types .= 'i'; $params[] = $user_id; }
    if ($branchCond) { $types .= 'i'; $params[] = $branch_id; }
    $types .= 'ss'; $params[] = $from; $params[] = $to;
    return rowsQuery($conn, $sql, $types, $params);
}

function fetchRSRReport(mysqli $conn, int $user_id, int $branch_id, string $from, string $to): array {
    if (!tableExistsSafe($conn, 'rsr_reports')) return [];
    $dateCol = detectDateColumn($conn, 'rsr_reports', ['rsr_date','created_at']);
    if (!$dateCol) return [];
    $customerJoin = tableExistsSafe($conn, 'customers') ? "LEFT JOIN customers c ON c.customer_id = r.customer_id" : "";
    $customerName = tableExistsSafe($conn, 'customers') ? "COALESCE(c.customer_name, '')" : "''";
    $branchCond = '';
    if ($branch_id > 0 && tableExistsSafe($conn, 'customers') && columnExistsSafe($conn, 'customers', 'branch_id')) {
        $branchCond = " AND (c.branch_id = ? OR c.branch_id IS NULL)";
    }
    $reportedCol = columnExistsSafe($conn, 'rsr_reports', 'reported_by') ? 'reported_by' : (columnExistsSafe($conn, 'rsr_reports', 'created_by') ? 'created_by' : null);
    $reportedCond = $reportedCol ? " AND r.`$reportedCol` = ?" : "";
    $sql = "SELECT r.rsr_id, DATE(r.`$dateCol`) AS report_date, $customerName AS customer_name,
                   COALESCE(r.store_name, '') AS store_name, COALESCE(r.address, '') AS address,
                   COALESCE(r.status, '') AS status, COALESCE(r.remarks, '') AS remarks
            FROM rsr_reports r
            $customerJoin
            WHERE 1=1 $reportedCond $branchCond AND DATE(r.`$dateCol`) BETWEEN ? AND ?
            ORDER BY r.`$dateCol` DESC, r.rsr_id DESC";
    $types = ''; $params = [];
    if ($reportedCond) { $types .= 'i'; $params[] = $user_id; }
    if ($branchCond) { $types .= 'i'; $params[] = $branch_id; }
    $types .= 'ss'; $params[] = $from; $params[] = $to;
    return rowsQuery($conn, $sql, $types, $params);
}

$branch_name = getBranchName($conn, $branch_id);
$user_initials = getUserInitials($user_name);

$report_type = $_GET['report_type'] ?? 'all';
$allowed_report_types = ['all','sales_orders','order_items','collections','expenses','inventory_receive','customers','rsr'];
if (!in_array($report_type, $allowed_report_types, true)) $report_type = 'all';

$period = $_GET['period'] ?? 'daily';
$base_date = $_GET['base_date'] ?? date('Y-m-d');
$date_from_input = $_GET['date_from'] ?? date('Y-m-d');
$date_to_input = $_GET['date_to'] ?? date('Y-m-d');
[$date_from, $date_to, $period_label] = resolveReportRange($period, $base_date, $date_from_input, $date_to_input);

$salesOrders = fetchSalesOrderReport($conn, $user_id, $branch_id, $date_from, $date_to);
$orderItems = fetchOrderItemsReport($conn, $user_id, $branch_id, $date_from, $date_to);
$collections = fetchCollectionsReport($conn, $user_id, $branch_id, $date_from, $date_to);
$expenses = fetchExpensesReport($conn, $user_id, $branch_id, $date_from, $date_to);
$inventoryReceives = fetchInventoryReceiveReport($conn, $user_id, $branch_id, $date_from, $date_to);
$customers = fetchCustomerReport($conn, $user_id, $branch_id, $date_from, $date_to);
$rsrRows = fetchRSRReport($conn, $user_id, $branch_id, $date_from, $date_to);

$totalSales = array_sum(array_map(fn($r) => (float)($r['amount'] ?? 0), $salesOrders));
$totalCollections = array_sum(array_map(fn($r) => (float)($r['amount'] ?? 0), $collections));
$totalExpenses = array_sum(array_map(fn($r) => (float)($r['amount'] ?? 0), $expenses));
$totalReceivedQty = array_sum(array_map(fn($r) => (float)($r['quantity'] ?? 0), $inventoryReceives));
$totalActivities = count($salesOrders) + count($collections) + count($expenses) + count($inventoryReceives) + count($customers) + count($rsrRows);

function shouldShowSection(string $current, string $section): bool {
    return $current === $section;
}

function addAllActivityRow(array &$allActivities, string $date, string $activity, string $reference, string $details, string $status = '', ?float $amount = null, string $quantity = ''): void {
    $allActivities[] = [
        'report_date' => $date,
        'activity' => $activity,
        'reference' => $reference,
        'details' => $details,
        'status' => $status,
        'amount' => $amount,
        'quantity' => $quantity
    ];
}

$allActivities = [];

foreach ($salesOrders as $r) {
    $details = trim(($r['customer_name'] ?? '') . (($r['store_name'] ?? '') !== '' ? ' / ' . $r['store_name'] : ''));
    if (($r['fulfillment_type'] ?? '') !== '') $details .= ($details !== '' ? ' | ' : '') . 'Fulfillment: ' . $r['fulfillment_type'];
    if (($r['invoice_number'] ?? '') !== '') $details .= ($details !== '' ? ' | ' : '') . 'Invoice: ' . $r['invoice_number'];
    addAllActivityRow($allActivities, (string)($r['report_date'] ?? ''), 'Sales Order', (string)($r['so_number'] ?? ''), $details, (string)($r['status'] ?? ''), (float)($r['amount'] ?? 0), '');
}

foreach ($collections as $r) {
    $details = trim(($r['customer_name'] ?? '') . ' | Method: ' . strtoupper(str_replace('_', ' ', (string)($r['payment_method'] ?? ''))));
    if (($r['reference_number'] ?? '') !== '') $details .= ' | Ref: ' . $r['reference_number'];
    addAllActivityRow($allActivities, (string)($r['report_date'] ?? ''), 'Collection', (string)($r['invoice_number'] ?? ''), $details, (string)($r['status'] ?? ''), (float)($r['amount'] ?? 0), '');
}

foreach ($expenses as $r) {
    $details = trim(($r['expense_account'] ?? 'Expense') . (($r['payee'] ?? '') !== '' ? ' / ' . $r['payee'] : ''));
    if (($r['description'] ?? '') !== '') $details .= ($details !== '' ? ' | ' : '') . $r['description'];
    addAllActivityRow($allActivities, (string)($r['report_date'] ?? ''), 'Expense', (string)($r['record_id'] ?? ''), $details, '', (float)($r['amount'] ?? 0), '');
}

foreach ($inventoryReceives as $r) {
    $details = trim(($r['item_code'] ?? '') . (($r['item_name'] ?? '') !== '' ? ' - ' . $r['item_name'] : ''));
    $qtyText = number_format((float)($r['quantity'] ?? 0), 2) . (($r['unit_type'] ?? '') !== '' ? ' ' . $r['unit_type'] : '');
    addAllActivityRow($allActivities, (string)($r['report_date'] ?? ''), 'Receive Inventory', (string)($r['reference_number'] ?? ''), $details, (string)($r['status'] ?? ''), null, $qtyText);
}

foreach ($customers as $r) {
    $details = trim(($r['customer_name'] ?? '') . (($r['store_name'] ?? '') !== '' ? ' / ' . $r['store_name'] : ''));
    if (($r['phone_number'] ?? '') !== '') $details .= ($details !== '' ? ' | ' : '') . 'Phone: ' . $r['phone_number'];
    addAllActivityRow($allActivities, (string)($r['report_date'] ?? ''), 'Customer Registered', (string)($r['customer_code'] ?? ''), $details, (string)($r['status'] ?? ''), null, '');
}

foreach ($rsrRows as $r) {
    $details = trim(($r['customer_name'] ?? '') . (($r['store_name'] ?? '') !== '' ? ' / ' . $r['store_name'] : ''));
    if (($r['remarks'] ?? '') !== '') $details .= ($details !== '' ? ' | ' : '') . $r['remarks'];
    addAllActivityRow($allActivities, (string)($r['report_date'] ?? ''), 'Route Sales Report', 'RSR-' . (string)($r['rsr_id'] ?? ''), $details, (string)($r['status'] ?? ''), null, '');
}

usort($allActivities, function($a, $b) {
    $dateCmp = strcmp((string)($b['report_date'] ?? ''), (string)($a['report_date'] ?? ''));
    if ($dateCmp !== 0) return $dateCmp;
    return strcasecmp((string)($a['activity'] ?? ''), (string)($b['activity'] ?? ''));
});

$logo_path = '../Pictures/amgc3DLogo.png';
$logo_base64 = '';
if (file_exists($logo_path)) {
    $logo_base64 = 'data:image/png;base64,' . base64_encode(file_get_contents($logo_path));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Reports - AMGC Rolling</title>
<link rel="icon" type="image/png" href="../Pictures/favicon-96x96.png" sizes="96x96" />
<link rel="stylesheet" href="../css/current_inventory.css">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
:root{--primary-green:#44D34E;--secondary-green:#44D34E;--light-green:#d1fae5;--dark-green:#047857;--dark-color:#052A47;--light-color:#f9fafb}
body{background:#f4f6f9;font-family:'Segoe UI',sans-serif}.main-content{margin-left:260px;padding:20px}.navbar-top{display:flex;align-items:center;gap:1rem;justify-content:space-between;margin-bottom:24px;background:#fff;padding:14px 20px;border-radius:16px;box-shadow:0 1px 3px rgba(0,0,0,.05)}.mobile-toggle-btn{display:none;border:none;background:transparent;color:var(--dark-color);font-size:1.6rem}.page-title h2{margin:0;color:var(--dark-color);font-weight:800}.page-title p{margin:0;color:#64748b}.report-card{background:#fff;border-radius:18px;border:1px solid #edf2f7;box-shadow:0 8px 20px rgba(15,23,42,.05);overflow:hidden;margin-bottom:1rem}.report-card-header{padding:1rem 1.25rem;border-bottom:1px solid #eef2f7;display:flex;justify-content:space-between;align-items:center;gap:1rem;flex-wrap:wrap}.report-card-body{padding:1rem 1.25rem}.filter-card{position:sticky;top:10px;z-index:20}.filter-header{display:flex;justify-content:space-between;align-items:center;padding:1rem 1.25rem;border-bottom:1px solid #eef2f7}.filter-toggle-btn{border:none;background:#ecfdf5;color:#047857;border-radius:999px;width:40px;height:40px}.filter-content{overflow:hidden;transition:max-height .25s ease,padding .25s ease}.filter-content.collapsed{max-height:0;padding:0 1.25rem}.filter-content.expanded{max-height:900px;padding:1rem 1.25rem}.stat-card{background:linear-gradient(135deg,#047857,#059669)!important;border:none!important;border-radius:16px!important;box-shadow:0 4px 10px rgba(0,0,0,.08)!important;padding:1rem!important;color:#fff!important;min-height:110px;display:flex;gap:.75rem;align-items:flex-start}.stat-card *{color:#fff!important}.stat-icon{font-size:1.55rem}.stat-value{font-size:1.25rem;font-weight:800;line-height:1.1}.stat-label{font-size:.78rem;font-weight:600}.table thead th{background:#f8fafc;color:#052A47;border-bottom:2px solid #e5e7eb;white-space:nowrap}.table td{vertical-align:middle}.badge-soft{border-radius:999px;padding:.25rem .6rem;background:#ecfdf5;color:#047857;border:1px solid #a7f3d0;font-weight:700}.btn-amgc-primary{background:linear-gradient(135deg,#047857,#44D34E);color:#fff;border:none;border-radius:999px;padding:.55rem 1rem;font-weight:700;box-shadow:0 4px 10px rgba(4,120,87,.18)}.btn-amgc-dark{background:linear-gradient(135deg,#052A47,#047857);color:#fff;border:none;border-radius:999px;padding:.55rem 1rem;font-weight:700}.btn-amgc-light{background:#ecfdf5;color:#047857;border:1px solid #a7f3d0;border-radius:999px;padding:.55rem 1rem;font-weight:700}.form-control,.form-select{border-radius:12px;min-height:44px}.empty-state{text-align:center;color:#64748b;padding:2rem}.print-only{display:none}.mobile-nav{position:fixed;bottom:0;left:0;right:0;background:#fff;box-shadow:0 -2px 10px rgba(0,0,0,.1);padding:8px 12px;z-index:1000;display:none}.mobile-nav .nav{display:flex;justify-content:space-around;margin:0;padding:0;list-style:none}.mobile-nav .nav-link{display:flex;flex-direction:column;align-items:center;padding:6px 4px;color:#6c757d;text-decoration:none;font-size:.72rem}.mobile-nav .nav-link i{font-size:1.25rem;margin-bottom:4px}.mobile-nav .nav-link.active{color:#047857}.dropdown-more{position:relative}.more-dropdown{position:absolute;bottom:100%;right:0;background:#fff;border-radius:12px;box-shadow:0 4px 20px rgba(0,0,0,.15);min-width:205px;display:none;margin-bottom:8px;z-index:1100}.more-dropdown.show{display:block}.more-dropdown .dropdown-item{display:flex;align-items:center;gap:12px;padding:12px 16px;color:#333;text-decoration:none;border-bottom:1px solid #f0f0f0;font-size:.85rem}.more-dropdown .dropdown-item:last-child{border-bottom:none}@media(max-width:992px){.sidebar{transform:translateX(-100%)}.sidebar.active{transform:translateX(0)}.main-content{margin-left:0;padding:14px}.mobile-toggle-btn{display:block}.mobile-nav{display:block}body{padding-bottom:76px}.filter-card{top:0}.stat-card{aspect-ratio:1/1;align-items:center;justify-content:center;text-align:center;flex-direction:column}.stat-card small{display:none!important}}
/* ===== Responsive Reports UI Improvements ===== */
.mobile-nav .nav-item{flex:1;text-align:center;min-width:0}
.mobile-nav .nav-link span{white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:100%}
.more-dropdown .dropdown-item.active{background:#ecfdf5;color:#047857;font-weight:700}
.logout-dropdown-item{width:100%;background:#fff;border:0;text-align:left;font-family:inherit}
.logout-dropdown-item:hover{background:#fef2f2!important;color:#b91c1c!important}
.report-card .table-responsive{width:100%;overflow-x:auto;-webkit-overflow-scrolling:touch}
.report-card table{min-width:760px}
.filter-card .row>[class*=col-]{min-width:0}
@media(max-width:768px){
    body{background:#f8fafc}
    .main-content{padding:10px!important}
    .navbar-top{align-items:flex-start;gap:.75rem;padding:12px 14px;margin-bottom:14px;border-radius:14px;flex-wrap:wrap}
    .navbar-top .page-title{flex:1 1 calc(100% - 48px);min-width:0}
    .navbar-top .page-title h2{font-size:1.25rem;line-height:1.2}
    .navbar-top .page-title p{font-size:.78rem;line-height:1.25}
    .navbar-top .btn{width:100%;justify-content:center;margin-top:2px}
    .report-card{border-radius:14px;margin-bottom:.85rem}
    .report-card-header{padding:.85rem .9rem;align-items:flex-start}
    .report-card-header h5{font-size:.95rem;line-height:1.3}
    .report-card-body{padding:.75rem .85rem}
    .filter-header{padding:.85rem .95rem}
    .filter-header h5{font-size:.95rem}
    .filter-content.expanded{padding:.85rem .95rem;max-height:1200px}
    .filter-content.collapsed{padding:0 .95rem}
    .form-control,.form-select{min-height:42px;font-size:.9rem}
    .report-card table{min-width:720px}
    .table th,.table td{font-size:.78rem;padding:.45rem .5rem}
    .more-dropdown{right:4px;min-width:215px;bottom:calc(100% + 6px)}
    .more-dropdown .dropdown-item{font-size:.86rem;padding:13px 15px}
}
@media(max-width:430px){
    .mobile-nav{padding:7px 6px}
    .mobile-nav .nav-link{font-size:.64rem;padding:5px 2px}
    .mobile-nav .nav-link i{font-size:1.12rem;margin-bottom:3px}
    .main-content{padding:8px!important}
    .report-card table{min-width:680px}
}
@media print{
    @page{size:A4 portrait;margin:12mm 10mm 14mm 10mm}
    *{-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important}
    html,body{background:#fff!important;color:#000!important;padding:0!important;margin:0!important;font-family:Arial,Helvetica,sans-serif!important;font-size:10px!important;line-height:1.25!important}
    .no-print,.sidebar,.mobile-nav,.navbar-top,.filter-card,.btn,.stat-card-row{display:none!important}
    .bi{display:none!important}
    .main-content{margin:0!important;padding:0!important;width:100%!important}
    .print-only{display:block!important}
    .print-report-header{text-align:center;border-bottom:2px solid #000;padding-bottom:8px;margin-bottom:10px}
    .print-logo{height:50px;width:auto;margin:0 auto 4px auto;display:block}
    .print-company{font-size:14px;font-weight:800;letter-spacing:.03em;text-transform:uppercase;color:#000!important;margin:0}
    .print-title{font-size:12px;font-weight:800;text-transform:uppercase;color:#000!important;margin:2px 0 4px 0}
    .print-meta{font-size:9px!important;color:#000!important;margin:1px 0!important}
    .report-card{box-shadow:none!important;border:1px solid #999!important;border-radius:0!important;margin-bottom:8px!important;break-inside:avoid;background:#fff!important;overflow:visible!important}
    .report-card-header{background:#fff!important;border-bottom:1px solid #999!important;border-radius:0!important;padding:6px 8px!important;display:flex!important;align-items:center!important;justify-content:space-between!important;gap:8px!important}
    .report-card-header h5{font-size:11px!important;margin:0!important;color:#000!important;font-weight:800!important}
    .report-card-body{padding:6px 8px!important;background:#fff!important}
    .badge-soft{background:#fff!important;border:1px solid #999!important;color:#000!important;border-radius:0!important;padding:1px 5px!important;font-size:8px!important;font-weight:700!important}
    .text-muted,.small{color:#000!important}
    .row.g-3{display:grid!important;grid-template-columns:repeat(4,1fr)!important;gap:6px!important;margin:0!important}
    .row.g-3>[class*=col-]{width:auto!important;max-width:none!important;padding:0!important;font-size:9px!important}
    .table-responsive{overflow:visible!important}
    table.table{width:100%!important;border-collapse:collapse!important;margin:0!important;page-break-inside:auto!important}
    .table thead{display:table-header-group!important}
    .table tr{page-break-inside:avoid!important;break-inside:avoid!important}
    .table th,.table td{border:1px solid #999!important;padding:4px 5px!important;font-size:8.8px!important;vertical-align:top!important;color:#000!important;background:#fff!important}
    .table thead th{background:#fff!important;color:#000!important;border-bottom:1px solid #000!important;font-weight:800!important;text-transform:uppercase;white-space:normal!important}
    .table tfoot th{background:#fff!important;color:#000!important;font-weight:800!important}
    .fw-bold{font-weight:800!important}.text-end{text-align:right!important}.text-center{text-align:center!important}.text-danger{color:#000!important}
    .empty-state{padding:10px!important;text-align:center!important;color:#000!important;font-size:9px!important}
    a[href]:after{content:''!important}
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
.stat-card {
    background: linear-gradient(135deg, #047857, #059669) !important;
}

.stat-card.pending {
    background: linear-gradient(135deg, #047857, #059669) !important;
}

.stat-card.sales {
    background: linear-gradient(135deg, #047857, #059669) !important;
}

.stat-card.offtake-card {
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


</style>
</head>
<body>
<div id="appPage">
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
                        <a class="nav-link active" href="reports.php">
                            <i class="bi bi-file-earmark-bar-graph"></i>
                            <span class="nav-text">Reports</span>
                        </a>
                    </li>
                </ul>
            </div>
        </div>
        
        <div class="sidebar-footer">
            <div class="user-profile-sidebar">
                <div class="user-avatar-sidebar"><?php echo h($user_initials); ?></div>
                <div class="user-details-sidebar">
                    <span class="user-name-sidebar"><?php echo h($user_name); ?></span>
                    <span class="user-role-sidebar"><?php echo h(ucfirst($user_role)); ?></span>
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
            <button class="mobile-toggle-btn" id="mobileToggleBtn"><i class="bi bi-list"></i></button>
            <div class="page-title"><h2>Reports</h2><p>Generate simple reports for all Rolling activities</p></div>
            <button class="btn btn-amgc-dark" onclick="window.print()"><i class="bi bi-printer"></i> Print Report</button>
        </div>

        <div class="print-only print-report-header">
            <?php if (!empty($logo_base64)): ?>
                <img src="<?php echo $logo_base64; ?>" alt="AMGC Logo" class="print-logo">
            <?php endif; ?>
            <div class="print-company">A. MACALINDONG DEVELOPMENT CORP.</div>
            <div class="print-title">ROLLING ACTIVITY REPORT</div>
            <div class="print-meta"><strong>Branch:</strong> <?php echo h($branch_name); ?> &nbsp; | &nbsp; <strong>Rolling:</strong> <?php echo h($user_name); ?></div>
            <div class="print-meta"><strong>Report Type:</strong> <?php echo h(ucwords(str_replace('_',' ', $report_type))); ?> &nbsp; | &nbsp; <strong>Period:</strong> <?php echo h($period_label); ?> &nbsp; | &nbsp; <?php echo h(date('M d, Y', strtotime($date_from))); ?> - <?php echo h(date('M d, Y', strtotime($date_to))); ?></div>
            <div class="print-meta"><strong>Generated:</strong> <?php echo h(date('M d, Y h:i A')); ?></div>
        </div>

        <!-- Statistics Cards - gaya ng customer.php - No Print -->
<div class="row stat-card-row g-1 g-sm-2 mb-4 no-print">
    <!-- Stat 1: Total Activities -->
    <div class="col">
        <div class="stat-card total">
            <i class="bi bi-list-check stat-icon"></i>
            <div class="stat-content">
                <div class="stat-value"><?php echo number_format($totalActivities); ?></div>
                <div class="stat-label">Total Activities</div>
                <small class="d-block">Within selected period</small>
            </div>
        </div>
    </div>

    <!-- Stat 2: Sales Orders -->
    <div class="col">
        <div class="stat-card sales">
            <i class="bi bi-cart-check stat-icon"></i>
            <div class="stat-content">
                <div class="stat-value"><?php echo moneyFmt($totalSales); ?></div>
                <div class="stat-label">Sales Orders</div>
                <small class="d-block"><?php echo count($salesOrders); ?> records</small>
            </div>
        </div>
    </div>

    <!-- Stat 3: Collections -->
    <div class="col">
        <div class="stat-card collections">
            <i class="bi bi-cash-stack stat-icon"></i>
            <div class="stat-content">
                <div class="stat-value"><?php echo moneyFmt($totalCollections); ?></div>
                <div class="stat-label">Collections</div>
                <small class="d-block"><?php echo count($collections); ?> records</small>
            </div>
        </div>
    </div>

    <!-- Stat 4: Expenses -->
    <div class="col">
        <div class="stat-card expenses">
            <i class="bi bi-wallet2 stat-icon"></i>
            <div class="stat-content">
                <div class="stat-value"><?php echo moneyFmt($totalExpenses); ?></div>
                <div class="stat-label">Expenses</div>
                <small class="d-block"><?php echo count($expenses); ?> records</small>
            </div>
        </div>
    </div>

    <!-- Stat 5: Received Quantity -->
    <div class="col">
        <div class="stat-card received">
            <i class="bi bi-box-arrow-in-down stat-icon"></i>
            <div class="stat-content">
                <div class="stat-value"><?php echo number_format($totalReceivedQty, 2); ?></div>
                <div class="stat-label">Received Qty</div>
                <small class="d-block"><?php echo count($inventoryReceives); ?> rows</small>
            </div>
        </div>
    </div>
</div>

        <div class="report-card filter-card no-print mb-4">
            <div class="filter-header">
                <h5 class="mb-0"><i class="bi bi-funnel me-2"></i>Filter Report</h5>
                <button class="filter-toggle-btn" type="button" id="filterToggleBtn" aria-expanded="false"><i class="bi bi-chevron-down" id="filterIcon"></i></button>
            </div>
            <div class="filter-content collapsed" id="filterContent">
                <form method="GET" id="reportFilterForm">
                    <div class="row g-3">
                        <div class="col-12 col-lg-3"><label class="form-label">Report Type</label><select name="report_type" class="form-select"><option value="all" <?php echo $report_type==='all'?'selected':''; ?>>All Activities</option><option value="sales_orders" <?php echo $report_type==='sales_orders'?'selected':''; ?>>Sales Orders</option><option value="order_items" <?php echo $report_type==='order_items'?'selected':''; ?>>Order Items</option><option value="collections" <?php echo $report_type==='collections'?'selected':''; ?>>Collections</option><option value="expenses" <?php echo $report_type==='expenses'?'selected':''; ?>>Expenses</option><option value="inventory_receive" <?php echo $report_type==='inventory_receive'?'selected':''; ?>>Receive Inventory</option><option value="customers" <?php echo $report_type==='customers'?'selected':''; ?>>Customers</option><option value="rsr" <?php echo $report_type==='rsr'?'selected':''; ?>>Route Sales Report</option></select></div>
                        <div class="col-12 col-lg-3"><label class="form-label">Period</label><select name="period" id="periodSelect" class="form-select"><option value="daily" <?php echo $period==='daily'?'selected':''; ?>>Daily</option><option value="weekly" <?php echo $period==='weekly'?'selected':''; ?>>Weekly</option><option value="monthly" <?php echo $period==='monthly'?'selected':''; ?>>Monthly</option><option value="custom" <?php echo $period==='custom'?'selected':''; ?>>Custom Date</option></select></div>
                        <div class="col-12 col-lg-3 period-base"><label class="form-label">Date</label><input type="date" name="base_date" class="form-control" value="<?php echo h($base_date); ?>"></div>
                        <div class="col-12 col-lg-3 custom-date"><label class="form-label">Date From</label><input type="date" name="date_from" class="form-control" value="<?php echo h($date_from); ?>"></div>
                        <div class="col-12 col-lg-3 custom-date"><label class="form-label">Date To</label><input type="date" name="date_to" class="form-control" value="<?php echo h($date_to); ?>"></div>
                        <div class="col-12 d-flex gap-2 flex-wrap"><button class="btn btn-amgc-primary" type="submit"><i class="bi bi-file-earmark-bar-graph"></i> Generate Report</button><a href="reports.php" class="btn btn-amgc-light"><i class="bi bi-arrow-clockwise"></i> Today Default</a><button class="btn btn-amgc-dark" type="button" onclick="window.print()"><i class="bi bi-printer"></i> Print</button></div>
                    </div>
                </form>
            </div>
        </div>

        <?php if ($report_type === 'all'): ?>
        <div class="report-card">
            <div class="report-card-header"><h5 class="mb-0"><i class="bi bi-list-check me-2"></i>All Activities</h5><span class="badge-soft"><?php echo count($allActivities); ?> records</span></div>
            <div class="report-card-body table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Activity</th>
                            <th>Reference</th>
                            <th>Details</th>
                            <th>Status</th>
                            <th class="text-end">Amount / Qty</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($allActivities): foreach ($allActivities as $r): ?>
                        <tr>
                            <td><?php echo h($r['report_date']); ?></td>
                            <td><strong><?php echo h($r['activity']); ?></strong></td>
                            <td><?php echo h($r['reference'] ?: '-'); ?></td>
                            <td><?php echo h($r['details'] ?: '-'); ?></td>
                            <td><?php echo h($r['status'] ?: '-'); ?></td>
                            <td class="text-end fw-bold">
                                <?php
                                    if ($r['amount'] !== null) {
                                        echo moneyFmt($r['amount']);
                                    } elseif (!empty($r['quantity'])) {
                                        echo h($r['quantity']);
                                    } else {
                                        echo '-';
                                    }
                                ?>
                            </td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="6" class="empty-state">No activities found for the selected date.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <?php if (shouldShowSection($report_type, 'sales_orders')): ?>
        <div class="report-card">
            <div class="report-card-header"><h5 class="mb-0"><i class="bi bi-cart-check me-2"></i>Sales Orders</h5><span class="badge-soft"><?php echo count($salesOrders); ?> records</span></div>
            <div class="report-card-body table-responsive">
                <table class="table table-hover align-middle"><thead><tr><th>Date</th><th>SO No.</th><th>Customer</th><th>Store</th><th>Fulfillment</th><th>Order Status</th><th>Invoice</th><th class="text-end">Amount</th></tr></thead><tbody>
                <?php if ($salesOrders): foreach ($salesOrders as $r): ?><tr><td><?php echo h($r['report_date']); ?></td><td><?php echo h($r['so_number']); ?></td><td><?php echo h($r['customer_name']); ?></td><td><?php echo h($r['store_name']); ?></td><td><?php echo h($r['fulfillment_type'] ?: '-'); ?></td><td><?php echo h($r['status']); ?></td><td><?php echo h(($r['invoice_number'] ?: '-') . (($r['invoice_status'] ?? '') ? ' / ' . $r['invoice_status'] : '')); ?></td><td class="text-end fw-bold"><?php echo moneyFmt($r['amount']); ?></td></tr><?php endforeach; else: ?><tr><td colspan="8" class="empty-state">No sales orders found.</td></tr><?php endif; ?>
                </tbody><tfoot><tr><th colspan="7" class="text-end">TOTAL</th><th class="text-end"><?php echo moneyFmt($totalSales); ?></th></tr></tfoot></table>
            </div>
        </div>
        <?php endif; ?>

        <?php if (shouldShowSection($report_type, 'order_items')): ?>
        <div class="report-card">
            <div class="report-card-header"><h5 class="mb-0"><i class="bi bi-box-seam me-2"></i>Order Items</h5><span class="badge-soft"><?php echo count($orderItems); ?> rows</span></div>
            <div class="report-card-body table-responsive"><table class="table table-hover align-middle"><thead><tr><th>Date</th><th>SO No.</th><th>Item Code</th><th>Item</th><th>UoM</th><th class="text-end">Qty</th><th class="text-end">Price</th><th class="text-end">Line Total</th></tr></thead><tbody>
                <?php if ($orderItems): foreach ($orderItems as $r): ?><tr><td><?php echo h($r['report_date']); ?></td><td><?php echo h($r['so_number']); ?></td><td><?php echo h($r['item_code']); ?></td><td><?php echo h($r['item_name']); ?></td><td><?php echo h($r['unit_type']); ?></td><td class="text-end"><?php echo number_format((float)$r['quantity'],2); ?></td><td class="text-end"><?php echo moneyFmt($r['unit_price']); ?></td><td class="text-end fw-bold"><?php echo moneyFmt($r['line_total']); ?></td></tr><?php endforeach; else: ?><tr><td colspan="8" class="empty-state">No order items found.</td></tr><?php endif; ?>
            </tbody></table></div>
        </div>
        <?php endif; ?>

        <?php if (shouldShowSection($report_type, 'collections')): ?>
        <div class="report-card">
            <div class="report-card-header"><h5 class="mb-0"><i class="bi bi-cash-stack me-2"></i>Collections</h5><span class="badge-soft"><?php echo count($collections); ?> records</span></div>
            <div class="report-card-body table-responsive"><table class="table table-hover align-middle"><thead><tr><th>Date</th><th>Invoice</th><th>Customer</th><th>Method</th><th>Reference</th><th>Status</th><th class="text-end">Amount</th></tr></thead><tbody>
                <?php if ($collections): foreach ($collections as $r): ?><tr><td><?php echo h($r['report_date']); ?></td><td><?php echo h($r['invoice_number']); ?></td><td><?php echo h($r['customer_name']); ?></td><td><?php echo h(strtoupper(str_replace('_',' ', $r['payment_method']))); ?></td><td><?php echo h($r['reference_number'] ?: '-'); ?></td><td><?php echo h($r['status']); ?></td><td class="text-end fw-bold"><?php echo moneyFmt($r['amount']); ?></td></tr><?php endforeach; else: ?><tr><td colspan="7" class="empty-state">No collection records found.</td></tr><?php endif; ?>
            </tbody><tfoot><tr><th colspan="6" class="text-end">TOTAL</th><th class="text-end"><?php echo moneyFmt($totalCollections); ?></th></tr></tfoot></table></div>
        </div>
        <?php endif; ?>

        <?php if (shouldShowSection($report_type, 'expenses')): ?>
        <div class="report-card">
            <div class="report-card-header"><h5 class="mb-0"><i class="bi bi-wallet2 me-2"></i>Expenses</h5><span class="badge-soft"><?php echo count($expenses); ?> records</span></div>
            <div class="report-card-body table-responsive"><table class="table table-hover align-middle"><thead><tr><th>Date</th><th>Expense Account</th><th>Payee</th><th>Description</th><th class="text-end">Amount</th></tr></thead><tbody>
                <?php if ($expenses): foreach ($expenses as $r): ?><tr><td><?php echo h($r['report_date']); ?></td><td><?php echo h($r['expense_account']); ?></td><td><?php echo h($r['payee'] ?: '-'); ?></td><td><?php echo h($r['description'] ?: '-'); ?></td><td class="text-end fw-bold text-danger"><?php echo moneyFmt($r['amount']); ?></td></tr><?php endforeach; else: ?><tr><td colspan="5" class="empty-state">No expenses found.</td></tr><?php endif; ?>
            </tbody><tfoot><tr><th colspan="4" class="text-end">TOTAL</th><th class="text-end"><?php echo moneyFmt($totalExpenses); ?></th></tr></tfoot></table></div>
        </div>
        <?php endif; ?>

        <?php if (shouldShowSection($report_type, 'inventory_receive')): ?>
        <div class="report-card">
            <div class="report-card-header"><h5 class="mb-0"><i class="bi bi-box-arrow-in-down me-2"></i>Receive Inventory</h5><span class="badge-soft"><?php echo count($inventoryReceives); ?> rows</span></div>
            <div class="report-card-body table-responsive"><table class="table table-hover align-middle"><thead><tr><th>Date</th><th>Reference</th><th>Item Code</th><th>Item</th><th>UoM</th><th class="text-end">Qty Received</th><th>Status</th></tr></thead><tbody>
                <?php if ($inventoryReceives): foreach ($inventoryReceives as $r): ?><tr><td><?php echo h($r['report_date']); ?></td><td><?php echo h($r['reference_number']); ?></td><td><?php echo h($r['item_code']); ?></td><td><?php echo h($r['item_name']); ?></td><td><?php echo h($r['unit_type'] ?: '-'); ?></td><td class="text-end fw-bold"><?php echo number_format((float)$r['quantity'],2); ?></td><td><?php echo h($r['status']); ?></td></tr><?php endforeach; else: ?><tr><td colspan="7" class="empty-state">No receive inventory records found.</td></tr><?php endif; ?>
            </tbody></table></div>
        </div>
        <?php endif; ?>

        <?php if (shouldShowSection($report_type, 'customers')): ?>
        <div class="report-card">
            <div class="report-card-header"><h5 class="mb-0"><i class="bi bi-people me-2"></i>Customers Registered</h5><span class="badge-soft"><?php echo count($customers); ?> records</span></div>
            <div class="report-card-body table-responsive"><table class="table table-hover align-middle"><thead><tr><th>Date</th><th>Customer Code</th><th>Customer</th><th>Store</th><th>Phone</th><th>Address</th><th>Status</th></tr></thead><tbody>
                <?php if ($customers): foreach ($customers as $r): ?><tr><td><?php echo h($r['report_date']); ?></td><td><?php echo h($r['customer_code']); ?></td><td><?php echo h($r['customer_name']); ?></td><td><?php echo h($r['store_name']); ?></td><td><?php echo h($r['phone_number']); ?></td><td><?php echo h($r['address']); ?></td><td><?php echo h($r['status']); ?></td></tr><?php endforeach; else: ?><tr><td colspan="7" class="empty-state">No registered customers found.</td></tr><?php endif; ?>
            </tbody></table></div>
        </div>
        <?php endif; ?>

        <?php if (shouldShowSection($report_type, 'rsr')): ?>
        <div class="report-card">
            <div class="report-card-header"><h5 class="mb-0"><i class="bi bi-file-earmark-text me-2"></i>Route Sales Reports</h5><span class="badge-soft"><?php echo count($rsrRows); ?> records</span></div>
            <div class="report-card-body table-responsive"><table class="table table-hover align-middle"><thead><tr><th>Date</th><th>Customer</th><th>Store</th><th>Address</th><th>Status</th><th>Remarks</th></tr></thead><tbody>
                <?php if ($rsrRows): foreach ($rsrRows as $r): ?><tr><td><?php echo h($r['report_date']); ?></td><td><?php echo h($r['customer_name']); ?></td><td><?php echo h($r['store_name']); ?></td><td><?php echo h($r['address']); ?></td><td><?php echo h($r['status']); ?></td><td><?php echo h($r['remarks']); ?></td></tr><?php endforeach; else: ?><tr><td colspan="6" class="empty-state">No route sales reports found.</td></tr><?php endif; ?>
            </tbody></table></div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Mobile Bottom Navigation -->
<div class="mobile-nav" id="mobileNav">
    <ul class="nav">
        <li class="nav-item"><a class="nav-link" href="current_inventory.php"><i class="bi bi-box-seam"></i><span>Inventory</span></a></li>
        <li class="nav-item"><a class="nav-link" href="customer_orderproduct.php"><i class="bi bi-person-plus"></i><span>Orders</span></a></li>
        <li class="nav-item"><a class="nav-link" href="collections.php"><i class="bi bi-cash-stack"></i><span>Collections</span></a></li>
        <li class="nav-item"><a class="nav-link" href="sales_order.php"><i class="bi bi-cart"></i><span>Sales</span></a></li>
        <li class="nav-item dropdown-more" id="moreDropdown">
            <a class="nav-link active more-btn" href="#" onclick="toggleDropdown(event,'moreDropdownMenu')"><i class="bi bi-three-dots"></i><span>More</span></a>
            <div class="more-dropdown" id="moreDropdownMenu">
                <a href="purchase_order.php" class="dropdown-item"><i class="bi bi-truck"></i><span>Receive Inventory</span></a>
                <a href="expenses.php" class="dropdown-item"><i class="bi bi-wallet2"></i><span>Expenses</span></a>
                <a href="reports.php" class="dropdown-item active"><i class="bi bi-file-earmark-bar-graph"></i><span>Reports</span></a>
                <button type="button" class="dropdown-item logout-dropdown-item" onclick="logout()"><i class="bi bi-box-arrow-right"></i><span>Logout</span></button>
            </div>
        </li>
    </ul>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function logout(){Swal.fire({title:'Are you sure?',text:'You will be logged out of the system',icon:'question',showCancelButton:true,confirmButtonColor:'#047857',cancelButtonColor:'#6c757d',confirmButtonText:'Yes, logout'}).then((r)=>{if(r.isConfirmed)window.location.href='../logout.php';});}
function toggleSidebar(){const s=document.getElementById('sidebar');if(!s)return;if(window.innerWidth<=992){s.classList.toggle('active')}else{s.classList.toggle('collapsed');localStorage.setItem('sidebarCollapsed',s.classList.contains('collapsed'))}}
function toggleDropdown(e,id){e.preventDefault();e.stopPropagation();document.querySelectorAll('.more-dropdown').forEach(d=>{if(d.id!==id)d.classList.remove('show')});const el=document.getElementById(id);if(el)el.classList.toggle('show')}
function updatePeriodFields(){const period=document.getElementById('periodSelect')?.value||'daily';document.querySelectorAll('.custom-date').forEach(el=>el.style.display=period==='custom'?'block':'none');document.querySelectorAll('.period-base').forEach(el=>el.style.display=period==='custom'?'none':'block')}
document.addEventListener('DOMContentLoaded',function(){const sidebar=document.getElementById('sidebar');if(sidebar&&window.innerWidth>992&&localStorage.getItem('sidebarCollapsed')==='true')sidebar.classList.add('collapsed');document.getElementById('mobileToggleBtn')?.addEventListener('click',toggleSidebar);document.getElementById('desktopToggleBtn')?.addEventListener('click',toggleSidebar);const filterBtn=document.getElementById('filterToggleBtn');const filterContent=document.getElementById('filterContent');const filterIcon=document.getElementById('filterIcon');filterBtn?.addEventListener('click',function(){const collapsed=filterContent.classList.contains('collapsed');filterContent.classList.toggle('collapsed',!collapsed);filterContent.classList.toggle('expanded',collapsed);filterBtn.setAttribute('aria-expanded',collapsed?'true':'false');filterIcon.classList.toggle('bi-chevron-down',!collapsed);filterIcon.classList.toggle('bi-chevron-up',collapsed);});document.getElementById('periodSelect')?.addEventListener('change',updatePeriodFields);updatePeriodFields();document.addEventListener('click',function(e){if(!e.target.closest('.dropdown-more'))document.querySelectorAll('.more-dropdown').forEach(d=>d.classList.remove('show'));if(window.innerWidth<=992&&sidebar&&sidebar.classList.contains('active')&&!sidebar.contains(e.target)&&!e.target.closest('#mobileToggleBtn'))sidebar.classList.remove('active');});});
</script>
</body>
</html>
