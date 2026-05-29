<?php
require_once '../config/database.php';
require_once '../config/session_handler.php';

$user_id = $_SESSION['user_id'] ?? 0;
$user_name = isset($_SESSION['first_name']) ? trim($_SESSION['first_name'] . ' ' . ($_SESSION['last_name'] ?? '')) : 'Branch Admin';
$user_role = $_SESSION['role'] ?? 'branch_admin';
$branch_id = (int)($_SESSION['branch_id'] ?? 0);
$view_all_branches = $_SESSION['view_all_branches'] ?? false;

$user_initials = '';
foreach (explode(' ', trim($user_name)) as $part) {
    if ($part !== '') {
        $user_initials .= strtoupper(substr($part, 0, 1));
    }
}
if ($user_initials === '') {
    $user_initials = 'BA';
}

function tableExists(mysqli $conn, string $table): bool {
    $safe = $conn->real_escape_string($table);
    $result = $conn->query("SHOW TABLES LIKE '{$safe}'");
    return $result && $result->num_rows > 0;
}

function columnExists(mysqli $conn, string $table, string $column): bool {
    $safeTable = preg_replace('/[^A-Za-z0-9_]/', '', $table);
    $safeColumn = $conn->real_escape_string($column);
    $result = $conn->query("SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeColumn}'");
    return $result && $result->num_rows > 0;
}

function moneyFmt($amount): string {
    return '₱' . number_format((float)$amount, 2);
}

function bindAndRun(mysqli $conn, string $query, string $types = '', array $params = []): ?mysqli_result {
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        return null;
    }
    if ($types !== '' && !empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    if (!$stmt->execute()) {
        $stmt->close();
        return null;
    }
    $result = $stmt->get_result();
    $stmt->close();
    return $result;
}


function buildSalesOnlyWhere(mysqli $conn, string $soAlias = 'so'): string {
    $conditions = [];

    // Sales lang ang isasama. Hindi kasama beginning balance, quotation, cancelled, pending, processing, etc.
    if (columnExists($conn, 'sales_orders', 'document_type')) {
        $conditions[] = "AND (
            $soAlias.document_type IS NULL
            OR TRIM($soAlias.document_type) = ''
            OR UPPER(TRIM($soAlias.document_type)) IN ('SO', 'SALES', 'SALES ORDER', 'SALES_ORDER')
        )";
    }

    if (columnExists($conn, 'sales_orders', 'fulfillment_type')) {
        $conditions[] = "AND (
            $soAlias.fulfillment_type IS NULL
            OR TRIM($soAlias.fulfillment_type) = ''
            OR LOWER(TRIM($soAlias.fulfillment_type)) NOT IN ('beginning_balance', 'beginning balance')
        )";
    }

    if (columnExists($conn, 'sales_orders', 'order_status')) {
        $conditions[] = "AND LOWER(TRIM($soAlias.order_status)) IN ('confirmed', 'delivered')";
    }

    return implode("\n           ", $conditions);
}


function buildDashboardCogsSqlParts(mysqli $conn, string $salesAmountExpr, string $soAlias = 'so'): array {
    $joins = '';

    // 1) Main source: COGS saved in sales_orders
    $orderCogsExpr = columnExists($conn, 'sales_orders', 'cogs_amount')
        ? "COALESCE($soAlias.cogs_amount, 0)"
        : "0";

    // 2) Fallback source: summed COGS saved per item
    $itemCogsExpr = '0';
    if (tableExists($conn, 'sales_order_items') && columnExists($conn, 'sales_order_items', 'cogs_amount')) {
        $joins .= "
         LEFT JOIN (
             SELECT 
                 so_id, 
                 COALESCE(SUM(cogs_amount), 0) AS item_cogs_total
             FROM sales_order_items
             GROUP BY so_id
         ) item_cogs ON item_cogs.so_id = $soAlias.so_id";

        $itemCogsExpr = "COALESCE(item_cogs.item_cogs_total, 0)";
    }

    // 3) Last fallback: compute missing COGS from order item qty x ave_cost / inventory unit_cost.
    // This catches orders where both sales_orders.cogs_amount and sales_order_items.cogs_amount are still zero.
    $computedCogsExpr = '0';
    if (
        tableExists($conn, 'sales_order_items') &&
        tableExists($conn, 'item_unit_inventory') &&
        tableExists($conn, 'unit_types')
    ) {
        $qtyCol = columnExists($conn, 'sales_order_items', 'quantity_ordered') ? 'quantity_ordered' : 'quantity_delivered';
        $aveCostExpr = columnExists($conn, 'sales_order_items', 'ave_cost')
            ? "NULLIF(soi.ave_cost, 0)"
            : "NULL";
        $unitTypeExpr = columnExists($conn, 'sales_order_items', 'unit_type')
            ? "LOWER(TRIM(soi.unit_type))"
            : "''";

        $invBranchJoin = columnExists($conn, 'item_unit_inventory', 'branch_id')
            ? "AND (inv.branch_id = so_calc.branch_id OR inv.branch_id IS NULL)"
            : "";

        $utBranchJoin = columnExists($conn, 'unit_types', 'branch_id')
            ? "AND (ut.branch_id = so_calc.branch_id OR ut.branch_id IS NULL)"
            : "";

        $joins .= "
         LEFT JOIN (
             SELECT
                 soi.so_id,
                 COALESCE(SUM(
                     COALESCE(soi.$qtyCol, 0) *
                     COALESCE(
                         $aveCostExpr,
                         NULLIF(inv.unit_cost, 0),
                         0
                     )
                 ), 0) AS computed_cogs_total
             FROM sales_order_items soi
             LEFT JOIN sales_orders so_calc
                 ON so_calc.so_id = soi.so_id
             LEFT JOIN unit_types ut
                 ON LOWER(TRIM(ut.unit_type_name)) = $unitTypeExpr
                 $utBranchJoin
             LEFT JOIN item_unit_inventory inv
                 ON inv.item_id = soi.item_id
                 AND inv.unit_type_id = ut.unit_type_id
                 $invBranchJoin
             GROUP BY soi.so_id
         ) computed_cogs ON computed_cogs.so_id = $soAlias.so_id";

        $computedCogsExpr = "COALESCE(computed_cogs.computed_cogs_total, 0)";
    }

    $cogsExpr = "CASE
        WHEN $orderCogsExpr > 0 THEN $orderCogsExpr
        WHEN $itemCogsExpr > 0 THEN $itemCogsExpr
        WHEN $computedCogsExpr > 0 THEN $computedCogsExpr
        ELSE 0
    END";

    return [$joins, $cogsExpr];
}

function getDateRangeFromFilter(string $period, string $date, string $customStart, string $customEnd): array {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $date = date('Y-m-d');
    }

    if ($period === 'weekly') {
        $start = date('Y-m-d', strtotime('monday this week', strtotime($date)));
        $end = date('Y-m-d', strtotime($start . ' +7 days'));
        $label = date('M d', strtotime($start)) . ' - ' . date('M d, Y', strtotime($end . ' -1 day'));
    } elseif ($period === 'monthly') {
        $start = date('Y-m-01', strtotime($date));
        $end = date('Y-m-d', strtotime($start . ' +1 month'));
        $label = date('F Y', strtotime($start));
    } elseif ($period === 'custom') {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $customStart)) {
            $customStart = $date;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $customEnd)) {
            $customEnd = $customStart;
        }
        if (strtotime($customEnd) < strtotime($customStart)) {
            $tmp = $customStart;
            $customStart = $customEnd;
            $customEnd = $tmp;
        }
        $start = $customStart;
        $end = date('Y-m-d', strtotime($customEnd . ' +1 day'));
        $label = date('M d, Y', strtotime($customStart)) . ' - ' . date('M d, Y', strtotime($customEnd));
    } else {
        $period = 'daily';
        $start = $date;
        $end = date('Y-m-d', strtotime($date . ' +1 day'));
        $label = date('F d, Y', strtotime($date));
    }

    return [$period, $start . ' 00:00:00', $end . ' 00:00:00', $label, $date, $customStart, $customEnd];
}

$has_branches = tableExists($conn, 'branches');
$has_sales_orders = tableExists($conn, 'sales_orders');
$has_customers = tableExists($conn, 'customers');
$has_payments = tableExists($conn, 'payments');
$has_invoices = tableExists($conn, 'invoices');
$has_bank_transactions = tableExists($conn, 'bank_transactions');

$branch_name = 'Branch';
$business_unit = 'Unassigned';
if ($branch_id > 0 && $has_branches) {
    $branch_stmt = $conn->prepare("SELECT branch_name, business_unit FROM branches WHERE branch_id = ? LIMIT 1");
    if ($branch_stmt) {
        $branch_stmt->bind_param('i', $branch_id);
        $branch_stmt->execute();
        $branch_row = $branch_stmt->get_result()->fetch_assoc();
        if ($branch_row) {
            $branch_name = $branch_row['branch_name'] ?: $branch_name;
            $business_unit = trim((string)($branch_row['business_unit'] ?? '')) ?: 'Unassigned';
        }
        $branch_stmt->close();
    }
}

$active_period = $_GET['period'] ?? 'daily';
if (!in_array($active_period, ['daily', 'weekly', 'monthly', 'custom'], true)) {
    $active_period = 'daily';
}

$selected_date = $_GET['date'] ?? date('Y-m-d');
$custom_start = $_GET['custom_start'] ?? $selected_date;
$custom_end = $_GET['custom_end'] ?? $selected_date;
[$active_period, $start_datetime, $end_datetime, $period_label, $selected_date, $custom_start, $custom_end] = getDateRangeFromFilter($active_period, $selected_date, $custom_start, $custom_end);

$sales_amount = 0;
$sales_count = 0;
$cogs_amount = 0;
$gross_profit_amount = 0;
$collection_amount = 0;
$collection_count = 0;
$expenses_amount = 0;
$expense_role_totals = [
    'branch_manager' => 0,
    'sales_agent' => 0,
    'rolling' => 0,
    'unassigned' => 0
];

if ($has_sales_orders) {
    $salesDateExpr = columnExists($conn, 'sales_orders', 'order_date')
        ? "COALESCE(NULLIF(so.order_date, '0000-00-00 00:00:00'), so.created_at)"
        : "so.created_at";

    $salesAmountExpr = columnExists($conn, 'sales_orders', 'order_amount')
        ? "CASE WHEN so.order_amount > 0 THEN so.order_amount ELSE so.total_amount END"
        : "so.total_amount";

    [$cogsJoins, $cogsExpr] = buildDashboardCogsSqlParts($conn, $salesAmountExpr, 'so');
    $grossProfitExpr = "($salesAmountExpr - $cogsExpr)";
    $salesOnlyWhere = buildSalesOnlyWhere($conn, 'so');

    $salesSummary = bindAndRun(
        $conn,
        "SELECT COUNT(*) AS total_count,
                COALESCE(SUM($salesAmountExpr), 0) AS sales,
                COALESCE(SUM($cogsExpr), 0) AS cogs,
                COALESCE(SUM($grossProfitExpr), 0) AS gross_profit
         FROM sales_orders so
         $cogsJoins
         WHERE $salesDateExpr >= ?
           AND $salesDateExpr < ?
           $salesOnlyWhere
           AND so.branch_id = ?",
        'ssi',
        [$start_datetime, $end_datetime, $branch_id]
    );

    if ($salesSummary && ($row = $salesSummary->fetch_assoc())) {
        $sales_count = (int)($row['total_count'] ?? 0);
        $sales_amount = (float)($row['sales'] ?? 0);
        $cogs_amount = (float)($row['cogs'] ?? 0);
        $gross_profit_amount = (float)($row['gross_profit'] ?? ($sales_amount - $cogs_amount));
    }
}


if ($has_payments) {
    $paymentJoin = $has_invoices ? "LEFT JOIN invoices inv ON inv.invoice_id = p.invoice_id" : "";
    $paymentBranchWhere = $has_invoices ? "AND inv.branch_id = ?" : "";
    $paymentTypes = $has_invoices ? 'ssi' : 'ss';
    $paymentParams = $has_invoices ? [$start_datetime, $end_datetime, $branch_id] : [$start_datetime, $end_datetime];

    $paymentSummary = bindAndRun(
        $conn,
        "SELECT COUNT(*) AS total_count, COALESCE(SUM(p.amount), 0) AS total_amount
         FROM payments p
         $paymentJoin
         WHERE COALESCE(p.payment_date, p.created_at) >= ?
           AND COALESCE(p.payment_date, p.created_at) < ?
           AND (p.status IS NULL OR p.status = '' OR p.status = 'completed')
           $paymentBranchWhere",
        $paymentTypes,
        $paymentParams
    );

    if ($paymentSummary && ($row = $paymentSummary->fetch_assoc())) {
        $collection_count = (int)($row['total_count'] ?? 0);
        $collection_amount = (float)($row['total_amount'] ?? 0);
    }
}

if ($has_bank_transactions) {
    $expenseDateExpr = columnExists($conn, 'bank_transactions', 'transaction_date') ? 'bt.transaction_date' : 'bt.created_at';
    $expenseWhere = ["bt.transaction_type = 'withdrawal'", "$expenseDateExpr >= ?", "$expenseDateExpr < ?"];
    $expenseTypes = 'ss';
    $expenseParams = [$start_datetime, $end_datetime];

    if (columnExists($conn, 'bank_transactions', 'branch_id')) {
        $expenseWhere[] = 'bt.branch_id = ?';
        $expenseTypes .= 'i';
        $expenseParams[] = $branch_id;
    }
    if (columnExists($conn, 'bank_transactions', 'expense_account')) {
        $expenseWhere[] = "bt.expense_account IS NOT NULL";
        $expenseWhere[] = "TRIM(bt.expense_account) <> ''";
    }

    $expenseSummary = bindAndRun(
        $conn,
        "SELECT COALESCE(SUM(bt.amount), 0) AS expenses
         FROM bank_transactions bt
         WHERE " . implode(' AND ', $expenseWhere),
        $expenseTypes,
        $expenseParams
    );

    if ($expenseSummary && ($row = $expenseSummary->fetch_assoc())) {
        $expenses_amount = (float)($row['expenses'] ?? 0);
    }

    $expenseUserColumn = '';
    foreach (['created_by', 'user_id', 'encoded_by', 'added_by', 'prepared_by'] as $possibleUserColumn) {
        if (columnExists($conn, 'bank_transactions', $possibleUserColumn)) {
            $expenseUserColumn = $possibleUserColumn;
            break;
        }
    }

    if ($expenseUserColumn !== '' && tableExists($conn, 'users')) {
        $expenseRoleSummary = bindAndRun(
            $conn,
            "SELECT COALESCE(u.role, '') AS user_role,
                    COALESCE(SUM(bt.amount), 0) AS expenses
             FROM bank_transactions bt
             LEFT JOIN users u ON bt.`$expenseUserColumn` = u.user_id
             WHERE " . implode(' AND ', $expenseWhere) . "
             GROUP BY COALESCE(u.role, '')",
            $expenseTypes,
            $expenseParams
        );

        if ($expenseRoleSummary) {
            while ($expenseRoleRow = $expenseRoleSummary->fetch_assoc()) {
                $expenseRole = strtolower(trim((string)($expenseRoleRow['user_role'] ?? '')));
                $expenseValue = (float)($expenseRoleRow['expenses'] ?? 0);

                if (in_array($expenseRole, ['branch_admin', 'branch manager', 'branch_manager', 'manager'], true)) {
                    $expense_role_totals['branch_manager'] += $expenseValue;
                } elseif (in_array($expenseRole, ['sales', 'sales_agent', 'sales agent', 'sales_officer', 'sales officer', 'salesofficer'], true)) {
                    $expense_role_totals['sales_agent'] += $expenseValue;
                } elseif ($expenseRole === 'rolling') {
                    $expense_role_totals['rolling'] += $expenseValue;
                } else {
                    $expense_role_totals['unassigned'] += $expenseValue;
                }
            }
        }
    } else {
        $expense_role_totals['unassigned'] = $expenses_amount;
    }
}

$net_profit_amount = $gross_profit_amount - $expenses_amount;

// Get sales agents and their transaction data
$sales_agents = [];
$general_totals = ['sales' => 0, 'cogs' => 0, 'gross_profit' => 0];
$rolling_totals = ['sales' => 0, 'cogs' => 0, 'gross_profit' => 0, 'expenses' => 0, 'transactions' => 0];

if ($has_sales_orders) {
    $agentSalesDateExpr = columnExists($conn, 'sales_orders', 'order_date')
        ? "COALESCE(NULLIF(so.order_date, '0000-00-00 00:00:00'), so.created_at)"
        : "so.created_at";

    $agentSalesAmountExpr = columnExists($conn, 'sales_orders', 'order_amount')
        ? "CASE WHEN so.order_amount > 0 THEN so.order_amount ELSE so.total_amount END"
        : "so.total_amount";

    [$agentCogsJoins, $agentCogsExpr] = buildDashboardCogsSqlParts($conn, $agentSalesAmountExpr, 'so');
    $agentSalesOnlyWhere = buildSalesOnlyWhere($conn, 'so');

    // Get all accounts (sales rolling) with transactions in this branch/period
    $agentQuery = bindAndRun(
        $conn,
        "SELECT COALESCE(so.created_by, 0) as agent_id,
                COALESCE(u.first_name, 'Unknown') as first_name,
                COALESCE(u.last_name, '') as last_name,
                COALESCE(u.role, '') as user_role,
                COUNT(*) AS transaction_count,
                COALESCE(SUM($agentSalesAmountExpr), 0) AS sales,
                COALESCE(SUM($agentCogsExpr), 0) AS cogs
         FROM sales_orders so
         LEFT JOIN users u ON so.created_by = u.user_id
         $agentCogsJoins
         WHERE $agentSalesDateExpr >= ?
           AND $agentSalesDateExpr < ?
           $agentSalesOnlyWhere
           AND so.branch_id = ?
         GROUP BY agent_id, u.first_name, u.last_name, u.role
         ORDER BY SUM($agentSalesAmountExpr) DESC",
        'ssi',
        [$start_datetime, $end_datetime, $branch_id]
    );

    if ($agentQuery) {
        while ($row = $agentQuery->fetch_assoc()) {
            $agent_id = $row['agent_id'];
            $first_name = trim($row['first_name'] ?? '');
            $last_name = trim($row['last_name'] ?? '');
            $agent_name = $first_name . ($last_name ? ' ' . $last_name : '');
            if (!$agent_name) $agent_name = 'Unknown Account';
            
            $agent_sales_amount = (float)($row['sales'] ?? 0);
            $agent_cogs_amount = (float)($row['cogs'] ?? 0);
            $agent_gross_profit = $agent_sales_amount - $agent_cogs_amount;
            $agent_role = strtolower(trim((string)($row['user_role'] ?? '')));
            $agent_role_label = $agent_role !== '' ? ucwords(str_replace('_', ' ', $agent_role)) : 'Sales Account';
            $transaction_count = (int)($row['transaction_count'] ?? 0);

            $sales_agents[] = [
                'id' => $agent_id,
                'name' => $agent_name,
                'role' => $agent_role,
                'role_label' => $agent_role_label,
                'sales' => $agent_sales_amount,
                'cogs' => $agent_cogs_amount,
                'gross_profit' => $agent_gross_profit,
                'transactions' => $transaction_count
            ];

            if ($agent_role === 'rolling') {
                $rolling_totals['sales'] += $agent_sales_amount;
                $rolling_totals['cogs'] += $agent_cogs_amount;
                $rolling_totals['gross_profit'] += $agent_gross_profit;
                $rolling_totals['transactions'] += $transaction_count;
            }

            $general_totals['sales'] += $agent_sales_amount;
            $general_totals['cogs'] += $agent_cogs_amount;
            $general_totals['gross_profit'] += $agent_gross_profit;
        }
    }
}


$branch_manager_totals = ['sales' => 0, 'cogs' => 0, 'gross_profit' => 0, 'expenses' => 0, 'transactions' => 0];
$sales_agent_totals = ['sales' => 0, 'cogs' => 0, 'gross_profit' => 0, 'expenses' => 0, 'transactions' => 0];

foreach ($sales_agents as $agent) {
    $agentRoleForColumn = strtolower(trim((string)($agent['role'] ?? '')));
    $targetTotals = null;

    if (in_array($agentRoleForColumn, ['branch_admin', 'branch manager', 'branch_manager'], true)) {
        $targetTotals = 'branch_manager_totals';
    } elseif (in_array($agentRoleForColumn, ['sales', 'sales_agent', 'sales agent', 'sales_officer', 'sales officer', 'salesofficer'], true)) {
        $targetTotals = 'sales_agent_totals';
    }

    if ($targetTotals === 'branch_manager_totals') {
        $branch_manager_totals['sales'] += (float)$agent['sales'];
        $branch_manager_totals['cogs'] += (float)$agent['cogs'];
        $branch_manager_totals['gross_profit'] += (float)$agent['gross_profit'];
        $branch_manager_totals['transactions'] += (int)$agent['transactions'];
    } elseif ($targetTotals === 'sales_agent_totals') {
        $sales_agent_totals['sales'] += (float)$agent['sales'];
        $sales_agent_totals['cogs'] += (float)$agent['cogs'];
        $sales_agent_totals['gross_profit'] += (float)$agent['gross_profit'];
        $sales_agent_totals['transactions'] += (int)$agent['transactions'];
    }
}

$assignedExpensesTotal = $expense_role_totals['branch_manager'] + $expense_role_totals['sales_agent'] + $expense_role_totals['rolling'];
$unassignedExpenses = max(0, $expenses_amount - $assignedExpensesTotal);

$branch_manager_totals['expenses'] = (float)$expense_role_totals['branch_manager'];
$sales_agent_totals['expenses'] = (float)$expense_role_totals['sales_agent'];
$rolling_totals['expenses'] = (float)$expense_role_totals['rolling'];

if ($unassignedExpenses > 0) {
    $allocationBase = max(0, (float)$branch_manager_totals['gross_profit'])
        + max(0, (float)$sales_agent_totals['gross_profit'])
        + max(0, (float)$rolling_totals['gross_profit']);

    if ($allocationBase > 0) {
        $branch_manager_totals['expenses'] += $unassignedExpenses * (max(0, (float)$branch_manager_totals['gross_profit']) / $allocationBase);
        $sales_agent_totals['expenses'] += $unassignedExpenses * (max(0, (float)$sales_agent_totals['gross_profit']) / $allocationBase);
        $rolling_totals['expenses'] += $unassignedExpenses * (max(0, (float)$rolling_totals['gross_profit']) / $allocationBase);
    } else {
        $branch_manager_totals['expenses'] += $unassignedExpenses;
    }
}

// AJAX: Generate report data with complete payment transaction details for dashboard report
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'generate_dashboard_report_data') {
    header('Content-Type: application/json');

    try {
        $report_category = $_POST['report_category'] ?? 'sales';
        $date_from = $_POST['date_from'] ?? date('Y-m-d');
        $date_to = $_POST['date_to'] ?? date('Y-m-d');

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) {
            throw new Exception('Invalid date range.');
        }

        $from_dt = $date_from . ' 00:00:00';
        $to_dt = date('Y-m-d', strtotime($date_to . ' +1 day')) . ' 00:00:00';

        $soDateExpr = columnExists($conn, 'sales_orders', 'order_date')
            ? "COALESCE(NULLIF(so.order_date, '0000-00-00 00:00:00'), so.created_at)"
            : "so.created_at";
        $reportSalesOnlyWhere = buildSalesOnlyWhere($conn, 'so');

        $selectParts = [
            "so.so_id",
            columnExists($conn, 'sales_orders', 'so_number') ? "so.so_number" : "CAST(so.so_id AS CHAR) AS so_number",
            "$soDateExpr AS order_date",
            columnExists($conn, 'sales_orders', 'order_status') ? "so.order_status" : "'' AS order_status",
            columnExists($conn, 'sales_orders', 'total_amount') ? "COALESCE(so.total_amount, 0) AS total_amount" : "0 AS total_amount",
            columnExists($conn, 'sales_orders', 'order_amount') ? "COALESCE(NULLIF(so.order_amount, 0), so.total_amount, 0) AS order_amount" : (columnExists($conn, 'sales_orders', 'total_amount') ? "COALESCE(so.total_amount, 0) AS order_amount" : "0 AS order_amount"),
            columnExists($conn, 'sales_orders', 'document_type') ? "COALESCE(so.document_type, '') AS document_type" : "'' AS document_type",
            columnExists($conn, 'sales_orders', 'si_number') ? "COALESCE(so.si_number, '') AS si_number" : "'' AS si_number",
            "COALESCE(c.customer_name, 'Walk-in Customer') AS customer_name",
            columnExists($conn, 'customers', 'customer_code') ? "COALESCE(c.customer_code, '') AS customer_code" : "'' AS customer_code",
            columnExists($conn, 'customers', 'store_name') ? "COALESCE(c.store_name, '') AS store_name" : "'' AS store_name",
            columnExists($conn, 'customers', 'address') ? "COALESCE(c.address, '') AS customer_address" : "'' AS customer_address"
        ];

        $orderSql = "SELECT " . implode(",\n               ", $selectParts) . "
            FROM sales_orders so
            LEFT JOIN customers c ON so.customer_id = c.customer_id
            WHERE $soDateExpr >= ?
              AND $soDateExpr < ?
              $reportSalesOnlyWhere
              AND so.branch_id = ?
            ORDER BY $soDateExpr ASC, so.so_id ASC";

        $orderStmt = $conn->prepare($orderSql);
        if (!$orderStmt) {
            throw new Exception('Order query error: ' . $conn->error);
        }
        $orderStmt->bind_param('ssi', $from_dt, $to_dt, $branch_id);
        $orderStmt->execute();
        $ordersResult = $orderStmt->get_result();

        $orders = [];

        $hasSalesOrderItems = tableExists($conn, 'sales_order_items');
        $hasItemsTable = tableExists($conn, 'items');
        $hasInvoicesTable = tableExists($conn, 'invoices');
        $hasPaymentsTable = tableExists($conn, 'payments');
        $invoiceHasSoId = $hasInvoicesTable && columnExists($conn, 'invoices', 'so_id');
        $paymentHasInvoiceId = $hasPaymentsTable && columnExists($conn, 'payments', 'invoice_id');
        $paymentHasSoId = $hasPaymentsTable && columnExists($conn, 'payments', 'so_id');

        while ($order = $ordersResult->fetch_assoc()) {
            $soId = (int)$order['so_id'];

            $items = [];
            if ($hasSalesOrderItems && $hasItemsTable) {
                $qtyExpr = columnExists($conn, 'sales_order_items', 'quantity_ordered') ? "COALESCE(soi.quantity_ordered, 0)" : "0";
                $unitPriceExpr = columnExists($conn, 'sales_order_items', 'unit_price') ? "COALESCE(soi.unit_price, 0)" : "0";
                $unitTypeExpr = columnExists($conn, 'sales_order_items', 'unit_type') ? "COALESCE(soi.unit_type, '')" : "''";
                $itemCodeExpr = columnExists($conn, 'items', 'item_code') ? "COALESCE(i.item_code, '')" : "''";
                $itemNameExpr = columnExists($conn, 'items', 'item_name') ? "COALESCE(i.item_name, '')" : "''";
                $volumeExpr = columnExists($conn, 'items', 'volume') ? "COALESCE(i.volume, 0)" : "0";

                $itemsSql = "SELECT 
                        $itemNameExpr AS item_name,
                        $itemCodeExpr AS item_code,
                        $qtyExpr AS quantity,
                        $unitPriceExpr AS unit_price,
                        $unitTypeExpr AS unit_type,
                        $volumeExpr AS volume
                    FROM sales_order_items soi
                    LEFT JOIN items i ON soi.item_id = i.item_id
                    WHERE soi.so_id = ?
                    ORDER BY soi.so_item_id ASC";
                $itemsStmt = $conn->prepare($itemsSql);
                if ($itemsStmt) {
                    $itemsStmt->bind_param('i', $soId);
                    $itemsStmt->execute();
                    $items = $itemsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
                    $itemsStmt->close();
                }
            }

            $invoiceAmount = (float)($order['order_amount'] ?? $order['total_amount'] ?? 0);
            $invoiceNumber = '';
            $paymentAmount = 0.0;
            $paymentMethod = '';
            $paymentStatus = '';
            $paymentDetails = [
                'check_no' => '',
                'check_date' => '',
                'bank' => '',
                'branch' => '',
                'bank_wallet' => '',
                'reference_no' => ''
            ];

            if ($hasInvoicesTable && $invoiceHasSoId) {
                $invSelect = ["invoice_id"];
                $invSelect[] = columnExists($conn, 'invoices', 'invoice_number') ? "COALESCE(invoice_number, '') AS invoice_number" : "'' AS invoice_number";
                $invSelect[] = columnExists($conn, 'invoices', 'total_amount') ? "COALESCE(total_amount, 0) AS invoice_total" : "0 AS invoice_total";
                $invSelect[] = columnExists($conn, 'invoices', 'balance_amount') ? "COALESCE(balance_amount, 0) AS invoice_balance" : "0 AS invoice_balance";
                $invSelect[] = columnExists($conn, 'invoices', 'status') ? "COALESCE(status, '') AS invoice_status" : "'' AS invoice_status";

                $invStmt = $conn->prepare("SELECT " . implode(',', $invSelect) . " FROM invoices WHERE so_id = ? ORDER BY invoice_id DESC LIMIT 1");
                if ($invStmt) {
                    $invStmt->bind_param('i', $soId);
                    $invStmt->execute();
                    $inv = $invStmt->get_result()->fetch_assoc();
                    if ($inv) {
                        $invoiceNumber = (string)($inv['invoice_number'] ?? '');
                        if ((float)($inv['invoice_total'] ?? 0) > 0) {
                            $invoiceAmount = (float)$inv['invoice_total'];
                        }
                        $paymentStatus = (string)($inv['invoice_status'] ?? '');
                    }
                    $invStmt->close();
                }
            }

            if ($hasPaymentsTable) {
                $paymentWhere = '';
                $paymentTypes = '';
                $paymentParams = [];

                if ($paymentHasInvoiceId && $hasInvoicesTable && $invoiceHasSoId) {
                    $paymentWhere = "p.invoice_id IN (SELECT invoice_id FROM invoices WHERE so_id = ?)";
                    $paymentTypes = 'i';
                    $paymentParams = [$soId];
                } elseif ($paymentHasSoId) {
                    $paymentWhere = "p.so_id = ?";
                    $paymentTypes = 'i';
                    $paymentParams = [$soId];
                }

                if ($paymentWhere !== '') {
                    $payCols = ["p.*"];
                    $paySql = "SELECT " . implode(',', $payCols) . " FROM payments p WHERE $paymentWhere ORDER BY " .
                        (columnExists($conn, 'payments', 'payment_date') ? "COALESCE(p.payment_date, p.created_at)" : "p.created_at") .
                        " DESC";

                    $payStmt = $conn->prepare($paySql);
                    if ($payStmt) {
                        $payStmt->bind_param($paymentTypes, ...$paymentParams);
                        $payStmt->execute();
                        $payRows = $payStmt->get_result()->fetch_all(MYSQLI_ASSOC);
                        $payStmt->close();

                        foreach ($payRows as $idx => $pay) {
                            $amt = 0;
                            foreach (['amount', 'payment_amount', 'paid_amount'] as $amountCol) {
                                if (isset($pay[$amountCol]) && is_numeric($pay[$amountCol])) {
                                    $amt = (float)$pay[$amountCol];
                                    break;
                                }
                            }
                            $paymentAmount += $amt;

                            if ($idx === 0) {
                                foreach (['payment_method', 'method', 'payment_type', 'type'] as $methodCol) {
                                    if (!empty($pay[$methodCol])) {
                                        $paymentMethod = (string)$pay[$methodCol];
                                        break;
                                    }
                                }

                                foreach (['status', 'payment_status'] as $statusCol) {
                                    if (!empty($pay[$statusCol])) {
                                        $paymentStatus = (string)$pay[$statusCol];
                                        break;
                                    }
                                }

                                foreach (['check_no', 'check_number', 'cheque_no', 'cheque_number', 'reference_number', 'ref_no'] as $col) {
                                    if (!empty($pay[$col])) {
                                        $paymentDetails['check_no'] = (string)$pay[$col];
                                        break;
                                    }
                                }

                                foreach (['check_date', 'cheque_date', 'date_check'] as $col) {
                                    if (!empty($pay[$col]) && $pay[$col] !== '0000-00-00') {
                                        $paymentDetails['check_date'] = (string)$pay[$col];
                                        break;
                                    }
                                }

                                foreach (['bank', 'bank_name', 'check_bank', 'cheque_bank', 'payment_bank'] as $col) {
                                    if (!empty($pay[$col])) {
                                        $paymentDetails['bank'] = (string)$pay[$col];
                                        break;
                                    }
                                }

                                foreach (['branch', 'bank_branch', 'check_branch', 'cheque_branch', 'branch_name'] as $col) {
                                    if (!empty($pay[$col])) {
                                        $paymentDetails['branch'] = (string)$pay[$col];
                                        break;
                                    }
                                }

                                foreach (['bank_wallet', 'wallet', 'wallet_name', 'online_bank', 'bank_name', 'bank'] as $col) {
                                    if (!empty($pay[$col])) {
                                        $paymentDetails['bank_wallet'] = (string)$pay[$col];
                                        break;
                                    }
                                }

                                foreach (['reference_number', 'payment_reference', 'ref_no', 'transaction_reference', 'transaction_no', 'online_reference'] as $col) {
                                    if (!empty($pay[$col])) {
                                        $paymentDetails['reference_no'] = (string)$pay[$col];
                                        break;
                                    }
                                }
                            }
                        }
                    }
                }
            }

            if ($paymentMethod === '') {
                $paymentMethod = $paymentAmount > 0 ? 'Paid' : 'Unpaid';
            }

            $balanceAmount = max(0, $invoiceAmount - $paymentAmount);
            if ($paymentAmount <= 0 && strtolower(trim($paymentStatus)) === 'unpaid') {
                $balanceAmount = $invoiceAmount;
            }

            $order['items'] = $items;
            $order['invoice_number'] = $invoiceNumber;
            $order['invoice_amount'] = $invoiceAmount;
            $order['payment_amount'] = $paymentAmount;
            $order['balance'] = $balanceAmount;
            $order['payment_method'] = $paymentMethod;
            $order['payment_status'] = $paymentStatus ?: ($paymentAmount > 0 ? 'paid' : 'unpaid');
            $order['check_no'] = $paymentDetails['check_no'];
            $order['check_date'] = $paymentDetails['check_date'];
            $order['bank'] = $paymentDetails['bank'];
            $order['bank_branch'] = $paymentDetails['branch'];
            $order['bank_wallet'] = $paymentDetails['bank_wallet'];
            $order['reference_number'] = $paymentDetails['reference_no'];

            $orders[] = $order;
        }

        echo json_encode([
            'success' => true,
            'data' => [
                'orders' => $orders,
                'report_category' => $report_category
            ]
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}


?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Branch Dashboard - <?php echo htmlspecialchars($branch_name); ?></title>
    <link rel="icon" type="image/png" href="../Pictures/favicon-96x96.png" sizes="96x96" />
    <link rel="stylesheet" href="../css/current_inventory.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <script src="../js/session-checker.js"></script>
<style>
    :root {
        --primary-green: #44D34E;
        --secondary-green: #44D34E;
        --light-green: #d1fae5;
        --dark-green: #047857;
        --dark-color: #052A47;
        --light-color: #f9fafb;
        --table-hover: #f1f5f9;
        --indent-bullet: #44D34E;
    }

    body {
        background: #f8fafc;
        color: #1e293b;
    }

    .main-content {
        padding: 1.5rem;
    }

    .navbar-top {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 1.5rem;
        gap: 1rem;
    }

    .mobile-menu-btn {
        display: none;
        background: transparent;
        border: none;
        font-size: 1.5rem;
        color: #052A47;
    }

    .btn-amgc-primary {
        background: linear-gradient(135deg, #047857 0%, #44D34E 100%);
        color: #fff;
        border: none;
        border-radius: 10px;
        min-height: 44px;
    }

    .btn-amgc-primary:hover {
        color: #fff;
        opacity: .95;
    }

    .btn-amgc-dark {
        background: #052A47;
        color: #fff;
        border: none;
        border-radius: 10px;
        min-height: 44px;
    }

    .btn-amgc-dark:hover {
        color: #fff;
        opacity: .96;
    }

    .stat-card {
        background: linear-gradient(135deg, #047857, #059669);
        border: none;
        border-radius: 18px;
        padding: 1rem;
        color: white;
        box-shadow: 0 6px 18px rgba(4, 120, 87, .18);
        height: 100%;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
    }

    .stat-card i {
        font-size: 1.85rem;
    }

    .stat-value {
        font-size: 1.45rem;
        line-height: 1.1;
    }

    .stat-label {
        font-size: .78rem;
        opacity: .95;
        text-transform: uppercase;
        letter-spacing: .4px;
    }

    .stat-note {
        font-size: .75rem;
        opacity: .9;
        margin-top: .25rem;
    }

    .form-card,
    .table-card {
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 16px;
        box-shadow: 0 6px 18px rgba(15, 23, 42, .06);
        overflow: hidden;
    }

    .card-header-amgc {
        padding: 1rem 1.25rem;
        border-bottom: 1px solid #e2e8f0;
        background: #047857;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
    }

    .card-header-amgc h5 {
        margin: 0;
        color: #fff;
    }

    .table thead th {
        background:#047857!important;
        color: white !important;
        font-size: .78rem;
        text-transform: uppercase;
        letter-spacing: .35px;
        border: 0 !important;
    }

    .table tbody td {
        vertical-align: middle;
        font-size: .88rem;
    }

    .search-box {
        position: relative;
    }

    .search-box i {
        position: absolute;
        top: 50%;
        left: .75rem;
        transform: translateY(-50%);
        color: #94a3b8;
    }

    .search-box input {
        padding-left: 2.25rem;
        border-radius: 10px;
    }

    .badge-soft-success {
        background: #d1fae5;
        color: #047857;
        border: 1px solid rgba(4, 120, 87, .15);
    }

    .badge-soft-warning {
        background: #fff7ed;
        color: #c2410c;
        border: 1px solid rgba(194, 65, 12, .12);
    }

    .badge-soft-danger {
        background: #fee2e2;
        color: #b91c1c;
        border: 1px solid rgba(185, 28, 28, .12);
    }

    .badge-soft-info {
        background: #dbeafe;
        color: #1d4ed8;
        border: 1px solid rgba(29, 78, 216, .12);
    }

    .filter-card {
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 16px;
        box-shadow: 0 6px 18px rgba(15, 23, 42, .06);
        padding: 1rem 1.25rem;
        margin-bottom: 1.25rem;
    }

    .form-control,
    .form-select {
        border-radius: 10px;
        min-height: 44px;
    }

    .empty-state {
        padding: 3rem 1rem;
        text-align: center;
        color: #64748b;
    }

    .empty-state i {
        font-size: 2.5rem;
        color: #94a3b8;
    }

    /* Plain simple table styling */
    .vertical-summary-table {
        width: 100%;
        border-collapse: collapse;
        background: white;
    }

    .vertical-summary-table thead {
        background: #f5f5f5;
        border-bottom: 2px solid #ddd;
    }

    .vertical-summary-table thead th {
        padding: 0.75rem;
        font-weight: 600;
        text-align: center;
        font-size: 0.9rem;
        color: #333;
        border-right: 1px solid #ddd;
    }

    .vertical-summary-table thead th:last-child {
        border-right: none;
    }

    .vertical-summary-table tbody th,
    .vertical-summary-table tbody td {
        padding: 0.75rem;
        border-bottom: 1px solid #eee;
        font-size: 0.9rem;
    }

    .vertical-summary-table tbody th {
        text-align: left;
        font-weight: 600;
        color: #333;
        background: #f9f9f9;
        width: 20%;
        border-right: 1px solid #ddd;
    }

    .vertical-summary-table tbody td {
        text-align: right;
        color: #555;
        background: white;
        border-right: 1px solid #eee;
    }

    .vertical-summary-table tbody td:last-child {
        border-right: none;
        background: #f9f9f9;
        font-weight: 600;
        color: #333;
    }

    .vertical-summary-table tbody tr:hover {
        background: #f5f5f5;
    }

    .vertical-summary-table tbody tr:hover td:last-child {
        background: #efefef;
    }

    /* Indentation for child rows (Sales, COGS, Gross Profit, Expenses, Net Profit) */
    .vertical-summary-table tbody tr.indent-row th {
        padding-left: 2.5rem;
        position: relative;
    }

    /* Optional visual bullet for indented rows */
    .vertical-summary-table tbody tr.indent-row th::before {
        content: "▹";
        position: absolute;
        left: 1rem;
        color: var(--indent-bullet);
        font-size: 0.9rem;
        opacity: 0.8;
    }

    /* Profit/Loss color overrides */
    .profit-positive {
        color: #047857 !important;
        font-weight: 700;
    }

    .profit-negative {
        color: #dc2626 !important;
        font-weight: 700;
    }

    .summary-role-badge {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        margin-top: .25rem;
        padding: .12rem .45rem;
        border-radius: 999px;
        font-size: .68rem;
        font-weight: 700;
        background: #d1fae5;
        color: #047857;
        border: 1px solid rgba(4, 120, 87, .16);
        text-transform: uppercase;
        letter-spacing: .3px;
        white-space: nowrap;
    }

    /* Responsive adjustments */
    @media (max-width: 768px) {
        .main-content {
            padding: 1rem;
        }

        .mobile-menu-btn {
            display: block;
        }

        .navbar-top {
            align-items: flex-start;
        }

        .page-title h2 {
            font-size: 1.35rem;
        }

        .header-date-pill {
            font-size: .95rem;
            padding: .5rem .8rem;
        }

        .stat-card {
            padding: .85rem;
        }

        .stat-value {
            font-size: 1.15rem;
        }

        .filter-card .row {
            gap: .75rem;
        }

        .table-responsive {
            border: 0;
        }

        .vertical-summary-table tbody tr.indent-row th {
            padding-left: 1.75rem;
        }

        .vertical-summary-table tbody tr.indent-row th::before {
            left: 0.75rem;
            font-size: 0.7rem;
        }

        /* Mobile card layout for the summary table */
        .vertical-summary-table thead {
            display: none;
        }

        .vertical-summary-table tbody tr {
            display: block;
            border: 1px solid #e5e7eb;
            border-radius: 14px;
            margin-bottom: 0.85rem;
            padding: 0.5rem 0;
            background: #fff;
        }

        .vertical-summary-table tbody th,
        .vertical-summary-table tbody td {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 1rem;
            padding: 0.6rem 1rem;
            border: 0 !important;
            width: 100%;
        }

        .vertical-summary-table tbody th::before {
            display: none;
        }

        .vertical-summary-table tbody th {
            text-transform: none;
            font-weight: 700;
            background: transparent;
            border-right: 0;
            font-size: 1rem;
        }

        .vertical-summary-table tbody td {
            font-weight: 600;
            justify-content: flex-end;
        }
    }

    .period-pill {
        display: inline-flex;
        align-items: center;
        gap: .4rem;
        padding: .35rem .7rem;
        border-radius: 999px;
        background: var(--light-green);
        color: var(--dark-green);
        font-size: .8rem;
        font-weight: 700;
        white-space: nowrap;
    }

    .header-date-pill {
        font-size: 1.05rem;
        padding: .6rem 1rem;
        box-shadow: 0 6px 16px rgba(4, 120, 87, .12);
    }

    .header-date-pill i {
        font-size: 1.05rem;
    }

    .custom-date-group {
        display: none;
    }

    .custom-date-group.show {
        display: block;
    }

    #singleDateGroup.hide-date {
        display: none;
    }

    /* Generate Report Button and Modal Styles */
    .btn-generate-report {
        background: linear-gradient(135deg, #059669 0%, #10b981 100%);
        border: 2px solid #10b981 !important;
        color: #fff;
        border: none;
        border-radius: 10px;
        padding: 0.5rem 1rem;
        font-size: 1rem;
        font-weight: 600;
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        transition: all 0.2s ease;
    }

    .btn-generate-report:active {
    /* Remove blue outline/shadow */
    outline: none !important;
    box-shadow: none !important;
}

/* Para sa focus state din (kapag na-click or na-tab) */
.btn-generate-report:focus {
    outline: none !important;
    box-shadow: none !important;
}

/* Para sa active state */
.btn-generate-report:active:focus {
    outline: none !important;
    box-shadow: none !important;
}
    .btn-generate-report:hover {
        color: #fff;
        opacity: 0.95;
        transform: translateY(-1px);
    }

    .report-modal .modal-header {
        background: linear-gradient(135deg, #047857 0%, #059669 100%);
        color: #fff;
        border-bottom: none;
    }

    .report-modal .modal-header .btn-close {
        filter: brightness(0) invert(1);
    }

    .report-modal .modal-body {
        padding: 1.5rem;
    }

    .report-modal .form-label {
        font-weight: 600;
        color: #333;
        margin-bottom: 0.5rem;
    }

    .report-modal .form-select,
    .report-modal .form-control {
        border-radius: 10px;
        min-height: 44px;
        border: 1px solid #e2e8f0;
    }

    .report-modal .form-select:focus,
    .report-modal .form-control:focus {
        border-color: #047857;
        box-shadow: 0 0 0 0.2rem rgba(4, 120, 87, 0.15);
    }

    .report-modal .modal-footer {
        border-top: 1px solid #e2e8f0;
        padding: 1rem 1.5rem;
    }

    /* Report Preview Styles */
    .report-preview {
        display: none;
        margin-top: 1.5rem;
    }

    .report-preview.show {
        display: block;
    }

    .report-preview-header {
        text-align: center;
        padding: 1rem;
        background: #f8fafc;
        border-radius: 10px 10px 0 0;
        border: 1px solid #e2e8f0;
        border-bottom: none;
    }

    .report-preview-header h4 {
        margin: 0;
        color: #052A47;
        font-weight: 700;
    }

    .report-preview-header p {
        margin: 0.25rem 0 0;
        color: #64748b;
        font-size: 1rem;
    }

    .report-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 1rem;
        border: 1px solid #e2e8f0;
    }

    .report-table thead th {
        background: #047857;
        color: #fff;
        padding: 1px !important;
        text-align: center;
        font-weight: 600;
        font-size: 0.9rem;
        text-transform: uppercase;
        border: 1px solid #047857;
    }

    .report-table tbody td {
        padding: 1px !important;
        border: 1px solid #e2e8f0;
        vertical-align: middle;
    }

    .report-table tbody tr:nth-child(even) {
        background: #f8fafc;
    }

    .report-table tbody tr:hover {
        background: #f1f5f9;
    }

    .report-table .text-center {
        text-align: center;
    }

    .report-table .text-right {
        text-align: right;
    }

    .report-table .text-left {
        text-align: left;
    }

    .report-table .item-row td:first-child {
        padding-left: 1.5rem;
    }

    .report-table .so-details {
        font-size: 0.96rem;
        padding: 0.75rem !important;
        line-height: 1.4;
    }

    .report-table .payment-details {
        padding: 0.5rem !important;
    }

    .report-table tbody td strong {
        font-weight: 700;
    }

    .report-totals {
        background: #f1f5f9;
        border: 1px solid #e2e8f0;
        border-top: 2px solid #047857;
        padding: 1rem;
        border-radius: 0 0 10px 10px;
    }

    .report-totals-row {
        display: flex;
        justify-content: space-between;
        padding: 0.5rem 0;
        border-bottom: 1px dashed #cbd5e1;
    }

    .report-totals-row:last-child {
        border-bottom: none;
        padding-top: 0.75rem;
        margin-top: 0.5rem;
        border-top: 2px solid #047857;
    }

    .report-totals-row .label {
        font-weight: 600;
        color: #333;
    }

    .report-totals-row .value {
        font-weight: 700;
        color: #047857;
    }

    .report-totals-row.grand-total .label,
    .report-totals-row.grand-total .value {
        font-size: 1.1rem;
        color: #052A47;
    }

    .btn-print-report {
        background: linear-gradient(135deg, #047857 0%, #44D34E 100%);
        color: #fff;
        border: none;
        border-radius: 10px;
        padding: 0.6rem 1.5rem;
        font-weight: 600;
    }

    .btn-print-report:hover {
        color: #fff;
        opacity: 0.95;
    }


    .report-table-wrapper {
        overflow-x: auto;
        border: 1px solid #e2e8f0;
        border-radius: 0 0 10px 10px;
    }

    .report-table.pivot-report-table {
        min-width: 1200px;
        font-size: 0.78rem;
    }

    .report-table.pivot-report-table thead th {
        white-space: nowrap;
        vertical-align: middle;
    }

    .report-table.pivot-report-table tbody td {
        vertical-align: top;
        white-space: normal;
    }

    .report-table .count-cell {
        text-align: center;
        font-weight: 700;
        width: 42px;
    }

    .report-table .so-details-cell {
        min-width: 155px;
        line-height: 1.25;
    }

    .report-table .si-detail-line {
        font-weight: 400;
        color: #444;
    }

    .report-table .product-col {
        min-width: 65px;
        text-align: center;
    }

    .report-table .unit-price-cell {
        min-width: 80px;
        line-height: 1.35;
        text-align: center;
    }

    .report-table .payment-cell {
        min-width: 170px;
        line-height: 1.35;
        text-align: left;
        white-space: normal !important;
    }

    .report-table .money-cell {
        min-width: 130px;
        text-align: center;
        white-space: nowrap !important;
    }

    .report-table .summary-row td {
        background: #f8fafc;
        font-weight: 700;
    }

    .report-table .volume-row td {
        background: #ffffff;
        font-weight: 600;
    }

    .print-report-header,
    .print-report-footer {
        display: none;
    }

    @media print {
        @page {
            size: A4 landscape;
            margin: 30mm 5mm 14mm 5mm;
        }

        html,
        body {
            width: 100% !important;
            height: auto !important;
            margin: 0 !important;
            padding: 0 !important;
            background: #fff !important;
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
        }

        body * {
            visibility: hidden !important;
        }

        #reportPrintArea,
        #reportPrintArea * {
            visibility: visible !important;
        }

        #reportPrintArea {
            position: static !important;
            width: 100% !important;
            max-width: 100% !important;
            margin: 0 !important;
            padding: 0 !important;
            background: #fff !important;
            box-sizing: border-box !important;
        }

        .print-report-header {
            display: flex !important;
            position: fixed !important;
            top: 5mm !important;
            left: 5mm !important;
            right: 5mm !important;
            height: 21mm !important;
            align-items: center !important;
            justify-content: center !important;
            gap: 9px !important;
            padding: 0 0 5px 0 !important;
            margin: 0 !important;
            border-bottom: 2px solid #047857 !important;
            text-align: center !important;
            background: #fff !important;
            box-sizing: border-box !important;
            z-index: 9999 !important;
        }

        .print-report-logo {
            width: 42px !important;
            height: 42px !important;
            object-fit: contain !important;
            flex: 0 0 auto !important;
        }

        .report-preview-header {
            display: block !important;
            width: auto !important;
            border: none !important;
            border-radius: 0 !important;
            background: transparent !important;
            padding: 0 !important;
            margin: 0 !important;
            text-align: left !important;
        }

        .report-preview-header h4 {
            font-size: 14px !important;
            margin: 0 0 2px 0 !important;
            color: #052A47 !important;
            font-weight: 800 !important;
            line-height: 1.1 !important;
            text-align: left !important;
        }

        .report-preview-header p {
            font-size: 8.8px !important;
            margin: 1px 0 !important;
            color: #334155 !important;
            line-height: 1.15 !important;
            text-align: left !important;
        }

        .table-responsive {
            width: 100% !important;
            max-width: 100% !important;
            overflow: visible !important;
            margin: 0 !important;
            padding: 0 !important;
        }

        .report-table,
        .report-table.pivot-report-table {
            width: 100% !important;
            max-width: 100% !important;
            min-width: 0 !important;
            margin: 0 !important;
            border-collapse: collapse !important;
            table-layout: fixed !important;
            font-size: 8px !important;
            page-break-inside: auto !important;
        }

        .report-table thead {
            display: table-header-group !important;
        }

        .report-table tfoot {
            display: table-footer-group !important;
        }

        .report-table tr {
            page-break-inside: avoid !important;
            break-inside: avoid !important;
            page-break-after: auto !important;
        }

        .report-table thead th,
        .report-table tbody td {
            padding: 2px 2px !important;
            border: 0.55px solid #94a3b8 !important;
            vertical-align: middle !important;
            line-height: 1.05 !important;
            text-align: center !important;
            box-sizing: border-box !important;
            white-space: normal !important;
            word-break: normal !important;
            overflow-wrap: anywhere !important;
        }

        .report-table thead th {
            background: #047857 !important;
            color: #fff !important;
            font-size: 6.3px !important;
            font-weight: 800 !important;
            text-transform: uppercase !important;
        }

        .report-table .count-cell,
        .report-table .count-head {
            width: 2.6% !important;
            min-width: 0 !important;
            font-weight: 700 !important;
        }

        .report-table .so-details-cell,
        .report-table .so-details-head {
            width: 9.5% !important;
            min-width: 0 !important;
            max-width: none !important;
            text-align: left !important;
        }

        .report-table .product-col {
            width: 4% !important;
            min-width: 0 !important;
            max-width: none !important;
            text-align: center !important;
        }

        .report-table .unit-price-cell,
        .report-table .unit-price-head {
            width: 4.2% !important;
            min-width: 0 !important;
            max-width: none !important;
            text-align: center !important;
        }

        .report-table .money-cell,
        .report-table .invoice-head,
        .report-table .payment-head,
        .report-table .balance-head {
            width: 6.2% !important;
            min-width: 0 !important;
            max-width: none !important;
            text-align: center !important;
            white-space: normal !important;
        }

        .report-table .payment-cell,
        .report-table .payment-details-head {
            width: 8.8% !important;
            min-width: 0 !important;
            max-width: none !important;
            text-align: left !important;
            line-height: 1.2 !important;
            white-space: normal !important;
        }

        .report-table .summary-row td {
            background: #f1f5f9 !important;
            font-weight: 800 !important;
        }

        .report-table .volume-row td {
            background: #fff !important;
            font-weight: 700 !important;
        }

        .print-report-footer {
            display: flex !important;
            position: fixed !important;
            left: 5mm !important;
            right: 5mm !important;
            bottom: 4mm !important;
            height: 7mm !important;
            justify-content: space-between !important;
            align-items: center !important;
            gap: 1rem !important;
            margin: 0 !important;
            padding-top: 3px !important;
            border-top: 1px solid #94a3b8 !important;
            font-size: 8.5px !important;
            color: #334155 !important;
            background: #fff !important;
            box-sizing: border-box !important;
            z-index: 9999 !important;
        }

        .no-print,
        .modal-footer,
        .btn-close,
        .modal-backdrop {
            display: none !important;
        }
    }    
            /* Dashboard Filter Card Styles - gaya ng supplier.php */
        .supplier-filter-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
            border: 1px solid #e2e8f0;
            margin-bottom: 1.5rem;
            overflow: hidden;
        }

        .supplier-filter-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.875rem 1.25rem;
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
            cursor: pointer;
        }

        .supplier-filter-header h5 {
            margin: 0;
            font-size: 0.95rem;
            font-weight: 600;
            color: #1e293b;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .supplier-filter-header h5 i {
            color: #44d34e;
            font-size: 1rem;
        }

        .supplier-filter-toggle-btn {
            background: transparent;
            border: none;
            color: #64748b;
            width: 32px;
            height: 32px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .supplier-filter-toggle-btn i {
            font-size: 1rem;
            transition: transform 0.3s ease;
        }

        .supplier-filter-toggle-btn:hover {
            background: rgba(68, 211, 78, 0.1);
        }

        .supplier-filter-content {
            transition: all 0.3s ease-in-out;
            overflow: hidden;
        }

        .supplier-filter-content.collapsed {
            display: none;
        }

        .supplier-filter-content:not(.collapsed) {
            display: block;
            padding: 1.25rem;
        }

        .supplier-filter-one-line {
            display: flex;
            align-items: flex-end;
            gap: 1rem;
            flex-wrap: nowrap;
        }

        .filter-item {
            flex: 1;
            min-width: 0;
        }

        .filter-actions-item {
            flex-shrink: 0;
        }

        .supplier-filter-label {
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 0.35rem;
            font-size: 0.7rem;
            display: block;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .invisible-label {
            visibility: hidden;
        }

        .supplier-filter-select,
        .supplier-filter-input {
            width: 100%;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 0.5rem 0.75rem;
            font-size: 1rem;
            transition: all 0.2s ease;
            background-color: #fff;
            height: 40px;
        }

        .supplier-filter-select:focus,
        .supplier-filter-input:focus {
            border-color: #44d34e;
            outline: none;
            box-shadow: 0 0 0 3px rgba(68, 211, 78, 0.1);
        }

        .custom-date-group {
            display: none;
        }

        .custom-date-group.show {
            display: block;
        }

        #singleDateGroup.hide-date {
            display: none;
        }

        /* Mobile: stack vertically */
        @media (max-width: 992px) {
            .supplier-filter-one-line {
                flex-direction: column;
                align-items: stretch;
                gap: 0.75rem;
            }
            
            .filter-item,
            .filter-actions-item {
                width: 100%;
            }
            
            .invisible-label {
                display: none;
            }
        }

        @media (max-width: 768px) {
            .supplier-filter-content:not(.collapsed) {
                padding: 1rem;
            }
            
            .supplier-filter-select,
            .supplier-filter-input {
                height: 36px;
                font-size: 0.96rem;
            }
        }
        /* Para ma-fix ang modal at hindi mag-scroll ang background */
.modal.show {
    overflow-y: auto !important;
    padding-right: 0 !important;
}

.modal-dialog {
    margin: 1.75rem auto;
    pointer-events: auto;
}

/* Para sa modal body lang ang mag-scroll, hindi ang buong modal */
.report-modal .modal-content {
    max-height: 90vh;
    display: flex;
    flex-direction: column;
}

.report-modal .modal-body {
    flex: 1;
    overflow-y: auto;
    max-height: calc(90vh - 120px); /* I-adjust depende sa header at footer height */
    padding: 1.5rem;
}

/* Siguraduhin na ang modal header at footer ay fixed sa loob ng modal */
.report-modal .modal-header {
    flex-shrink: 0;
}

.report-modal .modal-footer {
    flex-shrink: 0;
}

/* I-adjust ang report preview area para mag-scroll ng maayos */
.report-modal .report-preview {
    margin-top: 1.5rem;
}

.report-modal .table-responsive {
    overflow-x: auto;
    max-height: 500px;
    overflow-y: auto;
}
/* Palitan ito para sa modal width */
.report-modal .modal-dialog {
    max-width: 95vw; /* Palitan mula sa default na modal-xl */
    width: 95vw;
    margin: 1.75rem auto;
}

/* Para sa height ng modal */
.report-modal .modal-content {
    max-height: 90vh; /* Pwede mong dagdagan mula 90vh */
    min-height: 80vh; /* Minimum height kung gusto mo */
    display: flex;
    flex-direction: column;
}


    /* Branch Profit Summary chart view */
    .summary-view-toggle {
        display: inline-flex;
        gap: .35rem;
        background: rgba(255,255,255,.12);
        border: 1px solid rgba(255,255,255,.25);
        border-radius: 10px;
        padding: .25rem;
    }

    .summary-view-btn {
        border: none;
        background: transparent;
        color: #fff;
        border-radius: 8px;
        padding: .42rem .75rem;
        font-size: .85rem;
        font-weight: 700;
        display: inline-flex;
        align-items: center;
        gap: .35rem;
        transition: all .2s ease;
    }

    .summary-view-btn:hover,
    .summary-view-btn.active {
        background: #fff;
        color: #047857;
    }

    .summary-view-panel {
        display: block;
        padding: 1.25rem;
        background: #fff;
    }

    .summary-view-panel + .summary-view-panel {
        border-top: 1px solid #e2e8f0;
        padding-top: 1.35rem;
    }

    .summary-view-panel.active {
        display: block;
    }

    .summary-chart-grid {
        display: grid;
        grid-template-columns: minmax(0, 1.35fr) minmax(320px, .85fr);
        gap: 1rem;
        align-items: stretch;
    }

    .summary-chart-card {
        border: 1px solid #e2e8f0;
        border-radius: 16px;
        background: #ffffff;
        padding: 1rem;
        min-height: 390px;
        box-shadow: 0 4px 12px rgba(15, 23, 42, .04);
        position: relative;
        overflow: hidden;
    }

    .summary-chart-title {
        color: #052A47;
        font-size: 1rem;
        font-weight: 800;
        margin-bottom: .35rem;
        display: flex;
        align-items: center;
        gap: .45rem;
    }

    .summary-chart-subtitle {
        color: #64748b;
        font-size: .82rem;
        margin-bottom: .75rem;
    }

    .summary-chart-wrap {
        position: relative;
        width: 100%;
        height: 320px;
    }

    .summary-chart-loading {
        position: absolute;
        inset: 0;
        background: #ffffff;
        padding: 1rem;
        z-index: 5;
        display: none;
    }

    .summary-chart-card.loading .summary-chart-loading {
        display: block;
    }

    .skeleton-line,
    .skeleton-chart-bar,
    .skeleton-pie {
        background: linear-gradient(90deg, #f1f5f9 25%, #e2e8f0 37%, #f1f5f9 63%);
        background-size: 400% 100%;
        animation: skeletonPulse 1.2s ease-in-out infinite;
        border-radius: 10px;
    }

    .skeleton-line.title {
        width: 55%;
        height: 18px;
        margin-bottom: .75rem;
    }

    .skeleton-line.sub {
        width: 72%;
        height: 12px;
        margin-bottom: 1.25rem;
    }

    .skeleton-bars {
        height: 255px;
        display: flex;
        align-items: end;
        gap: .75rem;
        padding-top: 1rem;
    }

    .skeleton-chart-bar {
        flex: 1;
        min-width: 24px;
    }

    .skeleton-pie-wrap {
        height: 255px;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .skeleton-pie {
        width: 190px;
        height: 190px;
        border-radius: 50%;
    }

    @keyframes skeletonPulse {
        0% { background-position: 100% 50%; }
        100% { background-position: 0 50%; }
    }



    /* Pivot-style chart metric selector */
    .summary-chart-controls {
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: .75rem;
        padding: .85rem 1rem;
        margin-bottom: 1rem;
        border: 1px solid #e2e8f0;
        border-radius: 14px;
        background: #f8fafc;
    }

    .summary-chart-control-title {
        display: inline-flex;
        align-items: center;
        gap: .4rem;
        color: #052A47;
        font-size: .9rem;
        font-weight: 800;
        white-space: nowrap;
    }

    .summary-chart-metric-options {
        display: flex;
        flex-wrap: wrap;
        gap: .5rem;
        align-items: center;
    }

    .summary-chart-metric-chip {
        display: inline-flex;
        align-items: center;
        gap: .35rem;
        padding: .42rem .65rem;
        border: 1px solid #d1fae5;
        border-radius: 999px;
        background: #fff;
        color: #052A47;
        font-size: .8rem;
        font-weight: 700;
        cursor: pointer;
        user-select: none;
        transition: all .2s ease;
    }

    .summary-chart-metric-chip:hover {
        border-color: #44D34E;
        background: #ecfdf5;
    }

    .summary-chart-metric-chip input {
        accent-color: #047857;
        margin: 0;
    }

    .summary-chart-action-btn {
        border: 1px solid #047857;
        background: #fff;
        color: #047857;
        border-radius: 999px;
        padding: .42rem .75rem;
        font-size: .78rem;
        font-weight: 800;
        white-space: nowrap;
        transition: all .2s ease;
    }

    .summary-chart-action-btn:hover {
        background: #047857;
        color: #fff;
    }

    .summary-chart-empty {
        display: none;
        height: 100%;
        min-height: 220px;
        align-items: center;
        justify-content: center;
        text-align: center;
        color: #64748b;
        font-weight: 700;
        border: 1px dashed #cbd5e1;
        border-radius: 14px;
        background: #f8fafc;
        padding: 1rem;
    }

    .summary-chart-wrap.is-empty canvas {
        display: none !important;
    }

    .summary-chart-wrap.is-empty .summary-chart-empty {
        display: flex;
    }

    @media (max-width: 768px) {
        .summary-view-toggle {
            width: 100%;
            justify-content: center;
            flex-wrap: wrap;
        }

        .summary-view-btn {
            flex: 1;
            justify-content: center;
        }

        .summary-chart-grid {
            grid-template-columns: 1fr;
        }

        .summary-chart-wrap {
            height: 280px;
        }
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
                                    <a class="nav-link active" href="branchdashboard.php">
                                        <i class="bi bi-speedometer2"></i>
                                        <span class="nav-text">Dashboard</span>
                                    </a>
                                </li>
                    <!-- Warehouse Dropdown -->
                    <li class="nav-item dropdown-nav">
                        <a class="nav-link" href="#" onclick="toggleSidebarDropdown(event, 'warehouseMenu')">
                            <i class="bi bi-shop"></i>
                            <span class="nav-text">Warehouse</span>
                            <i class="bi bi-chevron-down dropdown-arrow"></i>
                        </a>
                    
                        <div class="collapse" id="warehouseMenu">
                            <ul class="nav flex-column ps-4">
                                <li class="nav-item">
                                    <a class="nav-link" href="current_inventory.php">
                                        <i class="bi bi-bar-chart-line"></i>
                                        <span class="nav-text">Current Inventory</span>
                                    </a>
                                </li>

                                <li class="nav-item">
                                    <a class="nav-link" href="bad_orders.php">
                                        <i class="bi bi-recycle"></i>
                                        <span class="nav-text">Bad Orders</span>
                                    </a>
                                </li>

                                <li class="nav-item">
                                    <a class="nav-link" href="pick_list_items.php">
                                        <i class="bi bi-list-check"></i>
                                        <span class="nav-text">Pick List Items</span>
                                    </a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link" href="warehouses.php">
                                    <i class="bi bi-shop"></i>
                                    <span class="nav-text">Warehouses</span></a>
                                </li>
                            </ul>
                        </div>
                    </li>

                    <!-- Supplier Dropdown -->
                    <li class="nav-item dropdown-nav">
                        <a class="nav-link" href="#" onclick="toggleSidebarDropdown(event, 'supplierMenu')">
                            <i class="bi bi-building"></i>
                            <span class="nav-text">Supplier</span>
                            <i class="bi bi-chevron-down dropdown-arrow"></i>
                        </a>

                        <div class="collapse" id="supplierMenu">
                            <ul class="nav flex-column ps-4">
                                <li class="nav-item">
                                    <a class="nav-link" href="purchase_order.php">
                                        <i class="bi bi-box"></i>
                                        <span class="nav-text">Receive Inventory</span>
                                    </a>
                                </li>

                                <li class="nav-item">
                                    <a class="nav-link" href="supplier.php">
                                        <i class="bi bi-people"></i>
                                        <span class="nav-text">Supplier List</span>
                                    </a>
                                </li>
                            </ul>
                        </div>
                    </li>

                    <!-- Customer Dropdown -->
                    <li class="nav-item dropdown-nav">
                        <a class="nav-link" href="#" onclick="toggleSidebarDropdown(event, 'customerMenu')">
                            <i class="bi bi-people"></i>
                            <span class="nav-text">Customer</span>
                            <i class="bi bi-chevron-down dropdown-arrow"></i>
                        </a>

                        <div class="collapse" id="customerMenu">
                            <ul class="nav flex-column ps-4">
                                <li class="nav-item">
                                    <a class="nav-link" href="customer_list.php">
                                        <i class="bi bi-person-badge"></i>
                                        <span class="nav-text">Customer List</span>
                                    </a>
                                </li>

                                <li class="nav-item">
                                    <a class="nav-link" href="approve_credit_requests.php">
                                        <i class="bi bi-pencil-square"></i>
                                        <span class="nav-text">Approve Credit Request</span>
                                    </a>
                                </li>

                                <li class="nav-item">
                                    <a class="nav-link" href="sales_order.php">
                                        <i class="bi bi-cart"></i>
                                        <span class="nav-text">Sales Order</span>
                                    </a>
                                </li>

                                <li class="nav-item">
                                    <a class="nav-link" href="collections.php">
                                        <i class="bi bi-cash-stack"></i>
                                        <span class="nav-text">Collections</span>
                                    </a>
                                </li>
                            </ul>
                        </div>
                    </li>

                    <!-- Delivery Dropdown -->
                    <li class="nav-item dropdown-nav">
                        <a class="nav-link" href="#" onclick="toggleSidebarDropdown(event, 'deliveryMenu')">
                            <i class="bi bi-truck"></i>
                            <span class="nav-text">Delivery</span>
                            <i class="bi bi-chevron-down dropdown-arrow"></i>
                        </a>

                        <div class="collapse" id="deliveryMenu">
                            <ul class="nav flex-column ps-4">
                                <li class="nav-item">
                                    <a class="nav-link" href="trip_tickets.php">
                                        <i class="bi bi-ticket-perforated"></i>
                                        <span class="nav-text">Trip Tickets</span>
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

                    <!-- Users -->
                    <li class="nav-item">
                        <a class="nav-link" href="drivers.php">
                            <i class="bi bi-people-fill"></i>
                            <span class="nav-text">Users</span>
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
        <div class="navbar-top">
            <button class="mobile-menu-btn" id="mobileMenuBtn"><i class="bi bi-list"></i></button>
            <div class="page-title">
                <h2>Dashboard</h2>
                <p>Sales, COGS, gross profit, expenses, and net profit summary</p>
            </div>
            <span class="period-pill header-date-pill"><i class="bi bi-calendar3"></i><?php echo htmlspecialchars($period_label); ?></span>
        </div>

        <?php if (!$has_sales_orders || !$has_payments || !$has_bank_transactions): ?>
            <div class="alert alert-warning">
                <strong>Database notice:</strong> Make sure these tables exist: <code>sales_orders</code>, <code>payments</code>, and <code>bank_transactions</code>.
            </div>
        <?php endif; ?>

        <div class="row g-2 g-md-3 mb-4">
            <div class="col-12 col-md-4">
                <div class="stat-card">
                    <div>
                        <div class="stat-label">Sales</div>
                        <div class="stat-value"><?php echo moneyFmt($sales_amount); ?></div>
                        <div class="stat-note"><?php echo number_format($sales_count); ?> sales transaction(s)</div>
                    </div>
                    <i class="bi bi-cart-check"></i>
                </div>
            </div>
            <div class="col-12 col-md-4">
                <div class="stat-card">
                    <div>
                        <div class="stat-label">Collection</div>
                        <div class="stat-value"><?php echo moneyFmt($collection_amount); ?></div>
                        <div class="stat-note"><?php echo number_format($collection_count); ?> completed collection(s)</div>
                    </div>
                    <i class="bi bi-cash-coin"></i>
                </div>
            </div>
            <div class="col-12 col-md-4">
                <div class="stat-card">
                    <div>
                        <div class="stat-label">Expenses</div>
                        <div class="stat-value"><?php echo moneyFmt($expenses_amount); ?></div>
                        <div class="stat-note">Recorded expense withdrawals</div>
                    </div>
                    <i class="bi bi-receipt"></i>
                </div>
            </div>
        </div>

                <!-- FILTER SECTION - COLLAPSIBLE (gaya ng supplier.php) -->
        <div class="supplier-filter-card mb-4">
            <div class="supplier-filter-header">
                <h5>
                    <i class="bi bi-funnel"></i> Filter Dashboard
                </h5>
                <button class="supplier-filter-toggle-btn" type="button" id="dashboardFilterToggleBtn" aria-expanded="false">
                    <i class="bi bi-chevron-down" id="dashboardFilterIcon"></i>
                </button>
            </div>
            
            <div class="supplier-filter-content collapsed" id="dashboardFilterContent">
                <form method="GET" class="dashboard-filter-form">
                    <div class="supplier-filter-one-line">
                        <!-- Period Filter -->
                        <div class="filter-item">
                            <label class="supplier-filter-label">PERIOD</label>
                            <select class="supplier-filter-select" name="period" id="periodFilter">
                                <option value="daily" <?php echo $active_period === 'daily' ? 'selected' : ''; ?>>Daily</option>
                                <option value="weekly" <?php echo $active_period === 'weekly' ? 'selected' : ''; ?>>Weekly</option>
                                <option value="monthly" <?php echo $active_period === 'monthly' ? 'selected' : ''; ?>>Monthly</option>
                                <option value="custom" <?php echo $active_period === 'custom' ? 'selected' : ''; ?>>Custom Date</option>
                            </select>
                        </div>
                        
                        <!-- Single Date (for daily/weekly/monthly) -->
                        <div class="filter-item" id="singleDateGroup">
                            <label class="supplier-filter-label">DATE</label>
                            <input type="date" class="supplier-filter-input" name="date" value="<?php echo htmlspecialchars($selected_date); ?>">
                        </div>
                        
                        <!-- Custom Date Range -->
                        <div class="filter-item custom-date-group" id="customStartGroup">
                            <label class="supplier-filter-label">START DATE</label>
                            <input type="date" class="supplier-filter-input" name="custom_start" value="<?php echo htmlspecialchars($custom_start); ?>">
                        </div>
                        
                        <div class="filter-item custom-date-group" id="customEndGroup">
                            <label class="supplier-filter-label">END DATE</label>
                            <input type="date" class="supplier-filter-input" name="custom_end" value="<?php echo htmlspecialchars($custom_end); ?>">
                        </div>
                        
                        <!-- Apply Button -->
                        <div class="filter-actions-item">
                            <label class="supplier-filter-label invisible-label">ACTION</label>
                            <button class="btn-primary w-100" type="submit" style="white-space: nowrap;">
                                <i class="bi bi-funnel me-1"></i> Apply
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <div class="table-card">
            <div class="card-header-amgc flex-wrap">
                <div>
                    <h5>Branch Profit Summary by Sales Account</h5>
                </div>
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <button type="button" class="btn btn-generate-report" data-bs-toggle="modal" data-bs-target="#generateReportModal">
                        <i class="bi bi-file-earmark-text"></i> Generate Report
                    </button>
                </div>
            </div>
            <?php if (empty($sales_agents)): ?>
                <div style="padding: 2rem; text-align: center; color: #999;">
                    <p>No transactions found for this period.</p>
                </div>
            <?php else: ?>
            <?php
                $summary_chart_metrics = [
                    ['key' => 'sales', 'label' => 'Sales Amount', 'value' => (float)$general_totals['sales']],
                    ['key' => 'cogs', 'label' => 'Cost of Goods', 'value' => (float)$general_totals['cogs']],
                    ['key' => 'gross_profit', 'label' => 'Gross Profit', 'value' => (float)$general_totals['gross_profit']],
                    ['key' => 'expenses', 'label' => 'Operating Expenses', 'value' => (float)$expenses_amount],
                    ['key' => 'net_profit', 'label' => 'Net Profit', 'value' => (float)($general_totals['gross_profit'] - $expenses_amount)]
                ];
            ?>
            <div class="summary-view-panel active" id="summaryChartPanel">
                <div class="summary-chart-controls">
                    <div class="summary-chart-control-title">
                        <i class="bi bi-sliders"></i>
                        Select metrics for chart
                    </div>
                    <div class="summary-chart-metric-options" id="summaryMetricOptions">
                        <?php foreach ($summary_chart_metrics as $metricIndex => $metric): ?>
                            <label class="summary-chart-metric-chip">
                                <input type="checkbox" class="summary-metric-checkbox" value="<?php echo (int)$metricIndex; ?>" checked>
                                <span><?php echo htmlspecialchars($metric['label']); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="summary-chart-action-btn" id="summarySelectAllBtn">
                        <i class="bi bi-check2-square me-1"></i>Select All
                    </button>
                </div>
                <div class="summary-chart-grid">
                    <div class="summary-chart-card loading" id="summaryBarCard">
                        <div class="summary-chart-loading">
                            <div class="skeleton-line title"></div>
                            <div class="skeleton-line sub"></div>
                            <div class="skeleton-bars">
                                <div class="skeleton-chart-bar" style="height:68%;"></div>
                                <div class="skeleton-chart-bar" style="height:48%;"></div>
                                <div class="skeleton-chart-bar" style="height:82%;"></div>
                                <div class="skeleton-chart-bar" style="height:58%;"></div>
                                <div class="skeleton-chart-bar" style="height:74%;"></div>
                            </div>
                        </div>
                        <div class="summary-chart-title"><i class="bi bi-bar-chart-fill"></i> Branch Profit Summary Metrics</div>
                        <div class="summary-chart-subtitle">Bar chart based on the Metric rows and General Total column of the table.</div>
                        <div class="summary-chart-wrap">
                            <canvas id="summaryBarChart"></canvas>
                            <div class="summary-chart-empty">Select at least one metric to show the chart.</div>
                        </div>
                    </div>

                    <div class="summary-chart-card loading" id="summaryPieCard">
                        <div class="summary-chart-loading">
                            <div class="skeleton-line title"></div>
                            <div class="skeleton-line sub"></div>
                            <div class="skeleton-pie-wrap">
                                <div class="skeleton-pie"></div>
                            </div>
                        </div>
                        <div class="summary-chart-title"><i class="bi bi-pie-chart-fill"></i> Branch Profit Summary Metrics</div>
                        <div class="summary-chart-subtitle">Pie chart based on the Metric rows and General Total column of the table.</div>
                        <div class="summary-chart-wrap">
                            <canvas id="summaryPieChart"></canvas>
                            <div class="summary-chart-empty">Select at least one metric to show the chart.</div>
                        </div>
                    </div>
                </div>
            </div>


            <div class="summary-view-panel active" id="summaryTablePanel">
                <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 vertical-summary-table">
                    <thead>
                        <tr>
                            <th>Metric</th>
                            <th>Branch Manager<br><small>(<?php echo number_format($branch_manager_totals['transactions']); ?> txn)</small></th>
                            <th>Sales Agent<br><small>(<?php echo number_format($sales_agent_totals['transactions']); ?> txn)</small></th>
                            <th>Rolling<br><small>(<?php echo number_format($rolling_totals['transactions']); ?> txn)</small></th>
                            <th>General Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <th>Sales Amount</th>
                            <td><?php echo moneyFmt($branch_manager_totals['sales']); ?></td>
                            <td><?php echo moneyFmt($sales_agent_totals['sales']); ?></td>
                            <td><?php echo moneyFmt($rolling_totals['sales']); ?></td>
                            <td><?php echo moneyFmt($general_totals['sales']); ?></td>
                        </tr>
                        <tr>
                            <th>Cost of Goods</th>
                            <td><?php echo moneyFmt($branch_manager_totals['cogs']); ?></td>
                            <td><?php echo moneyFmt($sales_agent_totals['cogs']); ?></td>
                            <td><?php echo moneyFmt($rolling_totals['cogs']); ?></td>
                            <td><?php echo moneyFmt($general_totals['cogs']); ?></td>
                        </tr>
                        <tr>
                            <th>Gross Profit</th>
                            <td><?php echo moneyFmt($branch_manager_totals['gross_profit']); ?></td>
                            <td><?php echo moneyFmt($sales_agent_totals['gross_profit']); ?></td>
                            <td><?php echo moneyFmt($rolling_totals['gross_profit']); ?></td>
                            <td><?php echo moneyFmt($general_totals['gross_profit']); ?></td>
                        </tr>
                        <tr>
                            <th>Operating Expenses</th>
                            <td><?php echo moneyFmt($branch_manager_totals['expenses']); ?></td>
                            <td><?php echo moneyFmt($sales_agent_totals['expenses']); ?></td>
                            <td><?php echo moneyFmt($rolling_totals['expenses']); ?></td>
                            <td><?php echo moneyFmt($expenses_amount); ?></td>
                        </tr>
                        <tr>
                            <th>Net Profit</th>
                            <td><?php echo moneyFmt($branch_manager_totals['gross_profit'] - $branch_manager_totals['expenses']); ?></td>
                            <td><?php echo moneyFmt($sales_agent_totals['gross_profit'] - $sales_agent_totals['expenses']); ?></td>
                            <td><?php echo moneyFmt($rolling_totals['gross_profit'] - $rolling_totals['expenses']); ?></td>
                            <td><?php echo moneyFmt($general_totals['gross_profit'] - $expenses_amount); ?></td>
                        </tr>
                    </tbody>
                </table>
                </div>
            </div>

            <script>
                window.branchSummaryMetricData = <?php echo json_encode($summary_chart_metrics, JSON_NUMERIC_CHECK); ?>;
            </script>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Mobile Bottom Navigation - Clean Version (No Arrows) -->
<div class="mobile-nav" id="mobileNav">
    <ul class="nav">
        <!-- Dashboard -->
        <li class="nav-item">
            <a class="nav-link active" href="branchdashboard.php">
                <i class="bi bi-speedometer2"></i>
                <span>Dashboard</span>
            </a>
        </li>

        <!-- Warehouse Dropdown -->
        <li class="nav-item dropdown-more" id="warehouseMobileDropdown">
            <a class="nav-link more-btn" href="#" onclick="toggleMobileDropdown(event, 'warehouseMobileMenu')">
                <i class="bi bi-shop"></i>
                <span>Warehouse</span>
            </a>
            <div class="more-dropdown" id="warehouseMobileMenu">
                <a href="current_inventory.php" class="dropdown-item">
                    <i class="bi bi-bar-chart-line"></i><span>Current Inventory</span>
                </a>
                <a href="bad_orders.php" class="dropdown-item">
                    <i class="bi bi-recycle"></i><span>Bad Orders</span>
                </a>
                <a href="pick_list_items.php" class="dropdown-item">
                    <i class="bi bi-list-check"></i><span>Pick List Items</span>
                </a>
                <a href="warehouses.php" class="dropdown-item">
                    <i class="bi bi-shop"></i><span>Warehouses</span>
                </a>
            </div>
        </li>

        <!-- Supplier Dropdown -->
        <li class="nav-item dropdown-more" id="supplierMobileDropdown">
            <a class="nav-link more-btn" href="#" onclick="toggleMobileDropdown(event, 'supplierMobileMenu')">
                <i class="bi bi-building"></i>
                <span>Supplier</span>
            </a>
            <div class="more-dropdown" id="supplierMobileMenu">
                <a href="purchase_order.php" class="dropdown-item">
                    <i class="bi bi-box"></i><span>Receive Inventory</span>
                </a>
                <a href="supplier.php" class="dropdown-item">
                    <i class="bi bi-people"></i><span>Supplier List</span>
                </a>
            </div>
        </li>

        <!-- Customer Dropdown -->
        <li class="nav-item dropdown-more" id="customerMobileDropdown">
            <a class="nav-link more-btn" href="#" onclick="toggleMobileDropdown(event, 'customerMobileMenu')">
                <i class="bi bi-people"></i>
                <span>Customer</span>
            </a>
            <div class="more-dropdown" id="customerMobileMenu">
                <a href="customer_list.php" class="dropdown-item">
                    <i class="bi bi-person-badge"></i><span>Customer List</span>
                </a>
                <a href="approve_credit_requests.php" class="dropdown-item">
                    <i class="bi bi-pencil-square"></i><span>Approve Credit Request</span>
                </a>
                <a href="sales_order.php" class="dropdown-item">
                    <i class="bi bi-cart"></i><span>Sales Order</span>
                </a>
                <a href="collections.php" class="dropdown-item">
                    <i class="bi bi-cash-stack"></i><span>Collections</span>
                </a>
            </div>
        </li>

        <!-- Delivery Dropdown -->
        <li class="nav-item dropdown-more" id="deliveryMobileDropdown">
            <a class="nav-link more-btn" href="#" onclick="toggleMobileDropdown(event, 'deliveryMobileMenu')">
                <i class="bi bi-truck"></i>
                <span>Delivery</span>
            </a>
            <div class="more-dropdown" id="deliveryMobileMenu">
                <a href="trip_tickets.php" class="dropdown-item">
                    <i class="bi bi-ticket-perforated"></i><span>Trip Tickets</span>
                </a>
            </div>
        </li>

        <!-- Banking Dropdown -->
        <li class="nav-item dropdown-more" id="bankingMobileDropdown">
            <a class="nav-link more-btn" href="#" onclick="toggleMobileDropdown(event, 'bankingMobileMenu')">
                <i class="bi bi-bank2"></i>
                <span>Banking</span>
            </a>
            <div class="more-dropdown" id="bankingMobileMenu">
                <a href="deposit.php" class="dropdown-item">
                    <i class="bi bi-arrow-down-circle"></i><span>Deposit</span>
                </a>
                <a href="Withdrawal.php" class="dropdown-item">
                    <i class="bi bi-arrow-up-circle"></i><span>Withdrawal</span>
                </a>
                <a href="bank_statement.php" class="dropdown-item">
                    <i class="bi bi-receipt"></i><span>Bank Statement</span>
                </a>
                <a href="expenses.php" class="dropdown-item">
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
            <a class="nav-link" href="drivers.php">
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


<!-- Generate Report Modal -->
<div class="modal fade report-modal" id="generateReportModal" tabindex="-1" aria-labelledby="generateReportModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="generateReportModalLabel"><i class="bi bi-file-earmark-text me-2"></i>Generate Report</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Report Category</label>
                        <select class="form-select" id="reportCategory">
                            <option value="sales">Sales Report</option>
                            <option value="cogs">COGS Report</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">From Date</label>
                        <input type="date" class="form-control" id="reportDateFrom" value="<?php echo htmlspecialchars($selected_date); ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">To Date</label>
                        <input type="date" class="form-control" id="reportDateTo" value="<?php echo htmlspecialchars($selected_date); ?>">
                    </div>
                </div>
                <div class="text-end mt-3">
                    <button type="button" class="btn btn-amgc-primary" onclick="generateReport()">
                        <i class="bi bi-arrow-clockwise me-1"></i> Generate
                    </button>
                </div>

                <!-- Report Preview Area -->
                <div class="report-preview" id="reportPreviewArea">
                    <div id="reportPrintArea">
                        <div class="print-report-header">
                            <img src="../Pictures/amgc3DLogo.png" alt="AMGC Logo" class="print-report-logo">
                            <div class="report-preview-header">
                                <h4 id="reportTitle">Sales Report</h4>
                                <p id="reportDateRange">Date Range: </p>
                                <p id="reportBranchInfo"><?php echo htmlspecialchars($branch_name); ?></p>
                            </div>
                        </div>
                        <div class="table-responsive">
                            <table class="report-table" id="reportTable">
                                <thead id="reportTableHead">
                                    <!-- Dynamic header -->
                                </thead>
                                <tbody id="reportTableBody">
                                    <!-- Dynamic body -->
                                </tbody>
                            </table>
                        </div>
                        <div class="print-report-footer">
                            <span>Printed by: <strong><?php echo htmlspecialchars($user_name); ?></strong></span>
                            <span id="printedDateTime">Printed on: </span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-print-report" id="printReportBtn" onclick="printReport()" style="display: none;">
                    <i class="bi bi-printer me-1"></i> Print Report
                </button>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>

function formatPesoForChart(value) {
    const amount = Number(value || 0);
    return '₱' + amount.toLocaleString('en-PH', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
}

let summaryBarChartInstance = null;
let summaryPieChartInstance = null;
const summaryChartColors = ['#047857', '#44D34E', '#052A47', '#16a34a', '#22c55e']; // fallback only
const summaryMetricColorMap = {
    sales: '#047857',
    cogs: '#44D34E',
    gross_profit: '#052A47',
    expenses: '#f97316',
    net_profit: '#2563eb'
};

function getSelectedSummaryMetricIndexes() {
    const checkboxes = document.querySelectorAll('.summary-metric-checkbox');
    return Array.from(checkboxes)
        .filter(checkbox => checkbox.checked)
        .map(checkbox => Number(checkbox.value));
}

function getSummaryMetricPayload() {
    const rows = Array.isArray(window.branchSummaryMetricData) ? window.branchSummaryMetricData : [];
    const selectedIndexes = getSelectedSummaryMetricIndexes();
    const selectedRows = rows.filter((row, index) => selectedIndexes.includes(index));
    const labels = selectedRows.map(row => row.label);
    const values = selectedRows.map(row => Number(row.value || 0));
    const colors = selectedRows.map(row => summaryMetricColorMap[row.key] || summaryChartColors[0]);
    return { labels, values, colors };
}

function setSummaryChartEmptyState(isEmpty) {
    const barWrap = document.getElementById('summaryBarChart')?.closest('.summary-chart-wrap');
    const pieWrap = document.getElementById('summaryPieChart')?.closest('.summary-chart-wrap');

    if (barWrap) barWrap.classList.toggle('is-empty', isEmpty);
    if (pieWrap) pieWrap.classList.toggle('is-empty', isEmpty);
}

function updateSummaryCharts() {
    const { labels, values, colors } = getSummaryMetricPayload();
    const isEmpty = !labels.length;
    setSummaryChartEmptyState(isEmpty);

    if (summaryBarChartInstance) {
        summaryBarChartInstance.data.labels = labels;
        summaryBarChartInstance.data.datasets[0].data = values;
        summaryBarChartInstance.data.datasets[0].backgroundColor = colors;
        summaryBarChartInstance.data.datasets[0].borderColor = colors;
        summaryBarChartInstance.update();
    }

    if (summaryPieChartInstance) {
        summaryPieChartInstance.data.labels = labels;
        summaryPieChartInstance.data.datasets[0].data = values.map(value => Math.abs(value));
        summaryPieChartInstance.data.datasets[0].actualValues = values;
        summaryPieChartInstance.data.datasets[0].backgroundColor = colors;
        summaryPieChartInstance.update();
    }
}

function initSummaryMetricControls() {
    document.querySelectorAll('.summary-metric-checkbox').forEach(checkbox => {
        checkbox.addEventListener('change', updateSummaryCharts);
    });

    const selectAllBtn = document.getElementById('summarySelectAllBtn');
    if (selectAllBtn) {
        selectAllBtn.addEventListener('click', function() {
            document.querySelectorAll('.summary-metric-checkbox').forEach(checkbox => {
                checkbox.checked = true;
            });
            updateSummaryCharts();
        });
    }
}

function initSummaryCharts() {
    if (typeof Chart === 'undefined') {
        return;
    }

    const { labels, values, colors } = getSummaryMetricPayload();
    const rows = Array.isArray(window.branchSummaryMetricData) ? window.branchSummaryMetricData : [];
    if (!rows.length) {
        return;
    }

    const barCanvas = document.getElementById('summaryBarChart');
    const pieCanvas = document.getElementById('summaryPieChart');

    if (barCanvas && !summaryBarChartInstance) {
        summaryBarChartInstance = new Chart(barCanvas, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{
                    label: 'General Total',
                    data: values,
                    backgroundColor: colors,
                    borderColor: colors,
                    borderWidth: 1,
                    borderRadius: 0,
                    categoryPercentage: 1.0,
                    barPercentage: 1.0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                layout: { padding: 0 },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return formatPesoForChart(context.raw);
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return '₱' + Number(value).toLocaleString('en-PH');
                            }
                        }
                    },
                    x: {
                        ticks: {
                            color: '#052A47',
                            font: { weight: '700' }
                        }
                    }
                }
            }
        });
    }

    if (pieCanvas && !summaryPieChartInstance) {
        const pieValues = values.map(value => Math.abs(value));
        summaryPieChartInstance = new Chart(pieCanvas, {
            type: 'pie',
            data: {
                labels: labels,
                datasets: [{
                    data: pieValues,
                    actualValues: values,
                    backgroundColor: colors,
                    borderColor: '#ffffff',
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            color: '#052A47',
                            font: { weight: '700' }
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                const actual = context.dataset.actualValues[context.dataIndex] || 0;
                                return context.label + ': ' + formatPesoForChart(actual);
                            }
                        }
                    }
                }
            }
        });
    }

    updateSummaryCharts();
}

function loadSummaryChartsWithSkeleton() {
    const barCard = document.getElementById('summaryBarCard');
    const pieCard = document.getElementById('summaryPieCard');

    if (barCard) barCard.classList.add('loading');
    if (pieCard) pieCard.classList.add('loading');

    setTimeout(function() {
        initSummaryCharts();
        if (summaryBarChartInstance) summaryBarChartInstance.resize();
        if (summaryPieChartInstance) summaryPieChartInstance.resize();
        if (barCard) barCard.classList.remove('loading');
        if (pieCard) pieCard.classList.remove('loading');
    }, 450);
}

document.addEventListener('DOMContentLoaded', function() {
    initSummaryMetricControls();
    loadSummaryChartsWithSkeleton();
});

// ========== SIDEBAR DROPDOWN FUNCTIONS ==========
function toggleSidebarDropdown(event, targetId) {
    event.preventDefault();
    event.stopPropagation();
    
    const target = document.getElementById(targetId);
    const btn = event.currentTarget;
    const arrow = btn.querySelector('.dropdown-arrow');
    const sidebar = document.getElementById('sidebar');
    
    // If sidebar is collapsed, expand it first then open dropdown
    if (sidebar && sidebar.classList.contains('collapsed')) {
        sidebar.classList.remove('collapsed');
        localStorage.setItem('sidebarCollapsed', 'false');
        
        setTimeout(() => {
            // Close all other dropdowns
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
            
            if (target) target.classList.add('show');
            if (arrow) arrow.style.transform = 'translateY(-50%) rotate(180deg)';
        }, 50);
        return;
    }
    
    // Normal behavior
    if (target && target.classList.contains('show')) {
        target.classList.remove('show');
        if (arrow) arrow.style.transform = 'translateY(-50%) rotate(0deg)';
    } else {
        // Close all other dropdowns
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
        
        if (target) target.classList.add('show');
        if (arrow) arrow.style.transform = 'translateY(-50%) rotate(180deg)';
    }
}

// ========== LOGOUT FUNCTION ==========
function logout() {
    window.location.href = '../logout.php';
}

// ========== SIDEBAR ELEMENTS ==========
const sidebar = document.getElementById('sidebar');
const mobileMenuBtn = document.getElementById('mobileMenuBtn');
const desktopToggleBtn = document.getElementById('desktopToggleBtn');

// ========== MOBILE MENU TOGGLE ==========
if (mobileMenuBtn) {
    mobileMenuBtn.addEventListener('click', function() {
        if (window.innerWidth <= 992) {
            sidebar?.classList.toggle('active');
            let overlay = document.querySelector('.sidebar-overlay');
            if (!overlay && sidebar?.classList.contains('active')) {
                overlay = document.createElement('div');
                overlay.className = 'sidebar-overlay';
                document.body.appendChild(overlay);
                overlay.addEventListener('click', function() {
                    sidebar.classList.remove('active');
                    overlay.classList.remove('active');
                    setTimeout(() => overlay.remove(), 300);
                });
                setTimeout(() => overlay.classList.add('active'), 10);
            }
        } else {
            toggleSidebarDesktop();
        }
    });
}

// ========== DESKTOP SIDEBAR COLLAPSE ==========
function toggleSidebarDesktop() {
    if (!sidebar) return;
    
    const wasCollapsed = sidebar.classList.contains('collapsed');
    sidebar.classList.toggle('collapsed');
    localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
    
    if (wasCollapsed && !sidebar.classList.contains('collapsed')) {
        sidebar.style.width = '';
        setTimeout(() => {
            expandActiveDropdownContainers();
        }, 150);
    } else if (!wasCollapsed && sidebar.classList.contains('collapsed')) {
        // Close all dropdowns when collapsing - reset arrows
        document.querySelectorAll('.sidebar .collapse.show').forEach(collapse => {
            collapse.classList.remove('show');
            const parentBtn = document.querySelector(`[onclick*="${collapse.id}"]`);
            if (parentBtn) {
                const arrow = parentBtn.querySelector('.dropdown-arrow');
                if (arrow) arrow.style.transform = 'translateY(-50%) rotate(0deg)';
            }
        });
    }
}

if (desktopToggleBtn) {
    desktopToggleBtn.addEventListener('click', toggleSidebarDesktop);
}

// ========== RESTORE SIDEBAR STATE ON LOAD ==========
document.addEventListener('DOMContentLoaded', function() {
    // Restore sidebar state
    if (sidebar && window.innerWidth > 992) {
        const isCollapsed = localStorage.getItem('sidebarCollapsed') === 'true';
        if (isCollapsed) {
            sidebar.classList.add('collapsed');
        } else {
            sidebar.classList.remove('collapsed');
        }
    }
    
    setActiveSidebarItem();
    
    if (sidebar && !sidebar.classList.contains('collapsed')) {
        expandActiveDropdownContainers();
    }
    
    // Prevent dropdown from closing when clicking inside
    document.querySelectorAll('.sidebar .collapse').forEach(collapse => {
        collapse.addEventListener('click', function(e) {
            e.stopPropagation();
        });
    });
    
    // Close sidebar when clicking outside on mobile
    document.addEventListener('click', function(e) {
        if (window.innerWidth <= 992 && sidebar && sidebar.classList.contains('active') && 
            !sidebar.contains(e.target) && mobileMenuBtn && !mobileMenuBtn.contains(e.target)) {
            sidebar.classList.remove('active');
            const overlay = document.querySelector('.sidebar-overlay');
            if (overlay) overlay.remove();
        }
    });
    
    window.addEventListener('resize', function() {
        if (window.innerWidth > 992 && sidebar) {
            sidebar.classList.remove('active');
            const overlay = document.querySelector('.sidebar-overlay');
            if (overlay) overlay.remove();
        }
    });
});

// ========== SET ACTIVE SIDEBAR ITEM ==========
function setActiveSidebarItem() {
    const currentPage = window.location.pathname.split('/').pop();
    
    document.querySelectorAll('.sidebar .nav-link').forEach(link => {
        link.classList.remove('active');
    });
    
    document.querySelectorAll('.sidebar .nav-link').forEach(link => {
        const href = link.getAttribute('href');
        if (href === currentPage || (currentPage === '' && href === 'branchdashboard.php')) {
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

// ========== EXPAND ACTIVE DROPDOWN CONTAINERS ==========
function expandActiveDropdownContainers() {
    const sidebarEl = document.getElementById('sidebar');
    if (!sidebarEl || sidebarEl.classList.contains('collapsed')) return;
    
    document.querySelectorAll('.sidebar .dropdown-nav').forEach(dropdownNav => {
        const activeLink = dropdownNav.querySelector('.nav-link.active');
        
        if (activeLink) {
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

// ========== PERIOD FILTER FUNCTIONS ==========
const periodFilter = document.getElementById('periodFilter');
const customStartGroup = document.getElementById('customStartGroup');
const customEndGroup = document.getElementById('customEndGroup');
const singleDateGroup = document.getElementById('singleDateGroup');

function toggleCustomDates() {
    const isCustom = periodFilter && periodFilter.value === 'custom';
    if (customStartGroup) customStartGroup.classList.toggle('show', isCustom);
    if (customEndGroup) customEndGroup.classList.toggle('show', isCustom);
    if (singleDateGroup) singleDateGroup.classList.toggle('hide-date', isCustom);
}

if (periodFilter) {
    periodFilter.addEventListener('change', toggleCustomDates);
    document.addEventListener('DOMContentLoaded', toggleCustomDates);
}

// ========== GENERATE REPORT FUNCTIONS ==========
function generateReport() {
    const reportCategory = document.getElementById('reportCategory').value;
    const dateFrom = document.getElementById('reportDateFrom').value;
    const dateTo = document.getElementById('reportDateTo').value;

    if (!dateFrom || !dateTo) {
        Swal.fire('Error', 'Please select both From and To dates.', 'error');
        return;
    }

    if (new Date(dateFrom) > new Date(dateTo)) {
        Swal.fire('Error', 'From date cannot be later than To date.', 'error');
        return;
    }

    Swal.fire({
        title: 'Generating Report...',
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading()
    });

    $.ajax({
        url: 'branchdashboard.php',
        type: 'POST',
        data: {
            action: 'generate_dashboard_report_data',
            report_type: 'detailed',
            report_category: reportCategory,
            date_from: dateFrom,
            date_to: dateTo,
            branch_id: <?php echo $branch_id; ?>
        },
        dataType: 'json',
        success: function(response) {
            Swal.close();
            if (response.success) {
                renderReport(response.data, reportCategory, dateFrom, dateTo);
            } else {
                Swal.fire('Error', response.message || 'Failed to generate report.', 'error');
            }
        },
        error: function() {
            Swal.close();
            Swal.fire('Error', 'Failed to connect to server.', 'error');
        }
    });
}

function renderReport(data, reportCategory, dateFrom, dateTo) {
    const previewArea = document.getElementById('reportPreviewArea');
    const reportTitle = document.getElementById('reportTitle');
    const reportDateRange = document.getElementById('reportDateRange');
    const tableHead = document.getElementById('reportTableHead');
    const tableBody = document.getElementById('reportTableBody');
    const reportTotals = document.getElementById('reportTotals');
    const reportTable = document.getElementById('reportTable');

    const categoryText = reportCategory === 'sales' ? 'Sales Report' : 'COGS Report';
    reportTitle.textContent = categoryText;

    const fromDate = new Date(dateFrom).toLocaleDateString('en-PH', { year: 'numeric', month: 'long', day: 'numeric' });
    const toDate = new Date(dateTo).toLocaleDateString('en-PH', { year: 'numeric', month: 'long', day: 'numeric' });
    reportDateRange.textContent = `Date Range: ${fromDate} - ${toDate}`;

    if (reportTable) {
        reportTable.classList.add('pivot-report-table');
    }

    renderPivotReport(data || {}, tableHead, tableBody, reportTotals, reportCategory);
    previewArea.classList.add('show');
}

function renderPivotReport(data, tableHead, tableBody, reportTotals, reportCategory) {
    const orders = Array.isArray(data.orders) ? data.orders : [];
    const productMap = new Map();

    orders.forEach(order => {
        const items = Array.isArray(order.items) ? order.items : [];
        items.forEach(item => {
            const name = cleanText(getFirstValue(item, ['item_name', 'product_name', 'name', 'description']), 'Unknown Item');
            const itemCode = cleanText(getFirstValue(item, ['item_code', 'code', 'sku', 'product_code']), '');
            const displayCode = itemCode || name;
            const key = displayCode.toLowerCase();

            if (!productMap.has(key)) {
                productMap.set(key, {
                    key: key,
                    label: displayCode,
                    name: name
                });
            }
        });
    });

    const products = Array.from(productMap.values()).sort((a, b) => a.label.localeCompare(b.label));
    const totalColumns = 7 + products.length;

    tableHead.innerHTML = `
        <tr>
            <th class="count-head">#</th>
            <th class="so-details-head">SO Details</th>
            ${products.map(product => `<th class="product-col">${escapeHtml(product.label)}</th>`).join('')}
            <th class="unit-price-head">Unit Price</th>
            <th class="invoice-head">Invoice Amount</th>
            <th class="payment-head">Payment Amount</th>
            <th class="balance-head">Balance</th>
            <th class="payment-details-head">Payment Details</th>
        </tr>
    `;

    if (orders.length === 0) {
        tableBody.innerHTML = `<tr><td colspan="${totalColumns}" class="text-center">No data found for the selected date range.</td></tr>`;
        if (reportTotals) reportTotals.innerHTML = '';
        return;
    }

    const itemTotals = {};
    const unitVolumes = {};
    const totalVolumes = {};
    let totalInvoice = 0;
    let totalPayment = 0;
    let totalBalance = 0;
    let totalCash = 0;
    let totalCheque = 0;
    let totalOnline = 0;

    let bodyHtml = '';

    orders.forEach((order, index) => {
        const items = Array.isArray(order.items) ? order.items : [];
        const itemByName = {};

        items.forEach(item => {
            const name = cleanText(getFirstValue(item, ['item_name', 'product_name', 'name', 'description']), 'Unknown Item');
            const itemCode = cleanText(getFirstValue(item, ['item_code', 'code', 'sku', 'product_code']), '');
            const displayCode = itemCode || name;
            const key = displayCode.toLowerCase();
            const qty = parseNumber(getFirstValue(item, ['quantity', 'qty', 'ordered_qty', 'delivered_qty']));
            const price = parseNumber(getFirstValue(item, ['unit_price', 'price', 'selling_price', 'item_price']));
            // Unit volume must come from items.volume only.
            // Do not parse product name like PALM 1.5x7, because the saved DB volume is the correct value.
            const rawVolume = getFirstValue(item, ['volume']);
            const uom = cleanText(getFirstValue(item, ['uom', 'unit', 'unit_type', 'unit_name']), '');
            const isOil = isOilProduct(name, item, rawVolume);
            const volume = isOil ? parseItemTableVolume(rawVolume) : 0;

            if (!itemByName[key]) {
                itemByName[key] = { name, qty: 0, price, volume, uom };
            }

            itemByName[key].qty += qty;
            if (!itemByName[key].price && price) itemByName[key].price = price;
            if (!itemByName[key].volume && volume) itemByName[key].volume = volume;
            if (!itemByName[key].uom && uom) itemByName[key].uom = uom;

            itemTotals[key] = (itemTotals[key] || 0) + qty;
            if (isOil && volume) {
                unitVolumes[key] = volume;
                totalVolumes[key] = (totalVolumes[key] || 0) + (qty * volume);
            }
        });

        const invoiceAmount = parseNumber(getFirstValue(order, ['invoice_amount', 'order_price', 'order_amount', 'total_amount']));
        const paymentAmount = parseNumber(getFirstValue(order, ['payment_amount', 'payment_price', 'paid_amount', 'amount_paid']));
        let balanceAmount = parseNumber(getFirstValue(order, ['balance', 'remaining_balance']));
        const cashAmount = parseNumber(getFirstValue(order, ['cash_payment', 'cash_amount']));
        const chequeAmount = parseNumber(getFirstValue(order, ['cheque_payment', 'check_payment', 'cheque_amount', 'check_amount']));
        const onlineAmount = parseNumber(getFirstValue(order, ['online_payment', 'online_banking_payment', 'bank_transfer_payment', 'online_amount']));
        const paymentMethod = resolvePaymentMethod(order, cashAmount, chequeAmount, onlineAmount);
        const paymentStatus = cleanText(getFirstValue(order, ['payment_status', 'collection_status', 'invoice_status']), '').toLowerCase().trim();

        if (paymentMethod.toLowerCase().trim() === 'unpaid' || paymentStatus === 'unpaid' || paymentStatus === 'not paid') {
            balanceAmount = invoiceAmount;
        }

        totalInvoice += invoiceAmount;
        totalPayment += paymentAmount;
        totalBalance += balanceAmount;
        totalCash += cashAmount;
        totalCheque += chequeAmount;
        totalOnline += onlineAmount;

        const soNumber = cleanText(getFirstValue(order, ['so_number', 'sales_order_no', 'order_number']), '');
        const siNumber = resolveSiNumber(order);
        const customerName = cleanText(getFirstValue(order, ['customer_name', 'client_name']), 'Walk-in Customer');
        const plateNumber = cleanText(getFirstValue(order, ['plate_number', 'vehicle_plate', 'plate_no', 'truck_plate', 'vehicle_no']), '');
        const driverName = cleanText(getFirstValue(order, ['driver_name', 'driver', 'delivery_driver', 'assigned_driver', 'driver_fullname']), '');
        const pickupDelivered = resolvePickupDelivery(order, plateNumber, driverName, paymentMethod);
        const isDeliveryOrder = pickupDelivered.toLowerCase().includes('delivery') || pickupDelivered.toLowerCase().includes('delivered');
        const paymentDetails = formatPaymentTransactionDetails(order, paymentMethod);

        bodyHtml += `
            <tr>
                <td class="count-cell">${index + 1}</td>
                <td class="so-details-cell">
                    <strong>${escapeHtml(soNumber)}</strong>
                    ${siNumber ? `<br><span class="si-detail-line">${escapeHtml(siNumber)} (SI)</span>` : ''}<br>
                    ${escapeHtml(customerName)}<br>
                    ${escapeHtml(pickupDelivered)}<br>
                    ${isDeliveryOrder ? `${escapeHtml(formatPlateDriver(plateNumber, driverName))}` : ''}
                </td>
                ${products.map(product => {
                    const item = itemByName[product.key];
                    return `<td class="product-col">${item && item.qty ? formatQty(item.qty) : ''}</td>`;
                }).join('')}
                <td class="unit-price-cell">${renderUnitPrices(products, itemByName)}</td>
                <td class="money-cell">${formatMoney(invoiceAmount)}</td>
                <td class="money-cell">${formatMoney(paymentAmount)}</td>
                <td class="money-cell">${formatMoney(balanceAmount)}</td>
                <td class="payment-cell">
                    
                    ${escapeHtml(paymentMethod)}
                    ${paymentDetails ? `<br>${paymentDetails}` : ''}
                </td>
            </tr>
        `;
    });

    bodyHtml += `
        <tr class="summary-row">
            <td></td>
            <td>Totals</td>
            ${products.map(product => {
                const key = product.key;
                return `<td class="product-col">${formatQty(itemTotals[key] || 0)}</td>`;
            }).join('')}
            <td></td>
            <td class="money-cell">${formatMoney(totalInvoice)}</td>
            <td class="money-cell">${formatMoney(totalPayment)}</td>
            <td class="money-cell">${formatMoney(totalBalance)}</td>
            <td></td>
        </tr>
        <tr class="volume-row">
            <td></td>
            <td>Unit Volume</td>
            ${products.map(product => {
                const key = product.key;
                return `<td class="product-col">${unitVolumes[key] ? formatQty(unitVolumes[key]) : ''}</td>`;
            }).join('')}
            <td colspan="5"></td>
        </tr>
        <tr class="summary-row">
            <td></td>
            <td>Total Volume</td>
            ${products.map(product => {
                const key = product.key;
                return `<td class="product-col">${totalVolumes[key] ? formatQty(totalVolumes[key]) : ''}</td>`;
            }).join('')}
           
            <td><strong>${formatQty(Object.values(totalVolumes).reduce((sum, value) => sum + value, 0))} kg</strong></td>
             <td colspan="4"></td>
        </tr>
    `;

    tableBody.innerHTML = bodyHtml;

    if (reportTotals) {
        reportTotals.innerHTML = '';
        reportTotals.style.display = 'none';
    }
}



function parseItemTableVolume(value) {
    const raw = String(value ?? '').trim();
    if (!raw || raw.toLowerCase() === 'null') return 0;

    // items.volume is already the saved oil volume.
    // Examples from the database: 3.24, 3.84, 9.94, 14.2, 17.
    const normalized = raw.replace(/,/g, '.');
    const match = normalized.match(/-?\d+(?:\.\d+)?/);
    if (!match) return 0;

    const number = parseFloat(match[0]);
    return Number.isFinite(number) && number > 0 ? number : 0;
}

function isOilProduct(productName, item = {}, rawVolume = '') {
    const savedVolume = parseItemTableVolume(rawVolume || item.volume);
    if (savedVolume > 0) return true;

    const haystack = [
        productName,
        item.category,
        item.product_category,
        item.item_category,
        item.item_type,
        item.type,
        item.oil_type
    ].map(value => String(value || '').toLowerCase()).join(' ');

    return haystack.includes('oil') ||
           haystack.includes('lubricant') ||
           haystack.includes('engine oil') ||
           haystack.includes('gear oil');
}

function renderUnitPrices(products, itemByName) {
    const prices = [];

    products.forEach(product => {
        const item = itemByName[product.key];

        // may qty at price lang
        if (item && item.qty > 0 && item.price > 0) {
            prices.push(formatMoney(item.price));
        }
    });

    // kapag higit sa 1 item → blank
    if (prices.length !== 1) {
        return '';
    }

    // 1 item lang → price lang ipakita
    return prices[0];
}



function formatReportDate(value) {
    const raw = cleanText(value, '');
    if (!raw || raw === '0000-00-00' || raw === '0000-00-00 00:00:00') return '';
    const date = new Date(raw);
    if (Number.isNaN(date.getTime())) return raw;
    return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
}

function formatPaymentTransactionDetails(order, paymentMethod) {
    const method = cleanText(paymentMethod, '').toLowerCase();

    const checkNo = cleanText(getFirstValue(order, ['check_no', 'check_number', 'cheque_no', 'cheque_number']), '');
    const checkDate = cleanText(getFirstValue(order, ['check_date', 'cheque_date']), '');
    const bank = cleanText(getFirstValue(order, ['bank', 'bank_name', 'check_bank', 'cheque_bank']), '');
    const branch = cleanText(getFirstValue(order, ['bank_branch', 'branch', 'check_branch', 'cheque_branch']), '');

    const bankWallet = cleanText(getFirstValue(order, ['bank_wallet', 'wallet', 'wallet_name', 'online_bank', 'bank_name', 'bank']), '');
    const refNo = cleanText(getFirstValue(order, ['reference_number', 'payment_reference', 'ref_no', 'transaction_reference', 'transaction_no']), '');

    const lines = [];

    if (method.includes('check') || method.includes('cheque')) {
        if (checkNo) lines.push(`Check No.: ${escapeHtml(checkNo)}`);
        if (checkDate) lines.push(`Check Date: ${escapeHtml(formatReportDate(checkDate))}`);
        if (bank) lines.push(`Bank: ${escapeHtml(bank)}`);
        if (branch) lines.push(`Branch: ${escapeHtml(branch)}`);
        return lines.join('<br>');
    }

    if (method.includes('online') || method.includes('transfer') || method.includes('bank')) {
        if (bankWallet) lines.push(`Bank/Wallet: ${escapeHtml(bankWallet)}`);
        if (refNo) lines.push(`Ref No.: ${escapeHtml(refNo)}`);
        return lines.join('<br>');
    }

    return '';
}


function resolveSiNumber(order) {
    const directSi = cleanText(getFirstValue(order, [
        'si_number',
        'so_si_number',
        'sales_order_si_number',
        'sales_invoice_number',
        'sales_invoice_no',
        'sales_invoice',
        'si_no'
    ]), '');

    if (directSi) {
        return directSi;
    }

    const documentType = cleanText(getFirstValue(order, [
        'document_type',
        'doc_type',
        'order_document_type'
    ]), '').toLowerCase().trim();

    if (documentType === 'si' || documentType === 'sales invoice') {
        return cleanText(getFirstValue(order, [
            'invoice_number',
            'invoice_no',
            'document_number'
        ]), '');
    }

    if (order && typeof order.invoice === 'object') {
        const nestedSi = cleanText(getFirstValue(order.invoice, [
            'si_number',
            'sales_invoice_number',
            'sales_invoice_no',
            'si_no'
        ]), '');
        if (nestedSi) return nestedSi;
    }

    return '';
}


function resolvePickupDelivery(order, plateNumber = '', driverName = '', paymentMethod = '') {
    const raw = cleanText(getFirstValue(order, [
        'pickup_or_delivered',
        'pickup_or_delivery',
        'delivery_type',
        'delivery_method',
        'fulfillment_type',
        'shipping_method',
        'order_type',
        'transaction_type',
        'mode_of_delivery',
        'delivery_status',
        'status'
    ]), '');

    const value = raw.toLowerCase().replace(/[_-]+/g, ' ').trim();
    const paymentValue = cleanText(paymentMethod, '').toLowerCase().trim();
    const explicitPaymentValue = cleanText(getFirstValue(order, ['payment_method', 'payment_type', 'method', 'payment_status', 'collection_status']), '').toLowerCase().trim();

    if (paymentValue === 'unpaid' || explicitPaymentValue === 'unpaid' || explicitPaymentValue === 'not paid') {
        return 'Delivery';
    }

    if (value.includes('deliver') || value.includes('for delivery') || value.includes('delivered')) {
        return 'Delivery';
    }

    if (value.includes('pick') || value === 'pickup' || value === 'pick up') {
        return 'Pick Up';
    }

    if (String(getFirstValue(order, ['is_delivery', 'for_delivery'], '')).toLowerCase() === '1' ||
        String(getFirstValue(order, ['is_delivery', 'for_delivery'], '')).toLowerCase() === 'yes' ||
        String(getFirstValue(order, ['is_delivery', 'for_delivery'], '')).toLowerCase() === 'true') {
        return 'Delivery';
    }

    if (String(getFirstValue(order, ['is_pickup', 'pickup'], '')).toLowerCase() === '1' ||
        String(getFirstValue(order, ['is_pickup', 'pickup'], '')).toLowerCase() === 'yes' ||
        String(getFirstValue(order, ['is_pickup', 'pickup'], '')).toLowerCase() === 'true') {
        return 'Pick Up';
    }

    if (plateNumber || driverName) {
        return 'Delivery';
    }

    return 'Pick Up';
}

function resolvePaymentMethod(order, cashAmount, chequeAmount, onlineAmount) {
    const explicit = cleanText(getFirstValue(order, ['payment_method', 'payment_type', 'method']), '');
    const paymentStatus = cleanText(getFirstValue(order, ['payment_status', 'collection_status', 'invoice_status']), '').toLowerCase().trim();

    if (explicit) return explicit;
    if (cashAmount > 0) return 'Cash';
    if (chequeAmount > 0) return 'Cheque';
    if (onlineAmount > 0) return 'Online Banking';
    if (paymentStatus === 'unpaid' || paymentStatus === 'not paid') return 'Unpaid';
    return 'Unpaid';
}

function getFirstValue(source, keys) {
    if (!source || typeof source !== 'object') return '';
    for (const key of keys) {
        if (source[key] !== undefined && source[key] !== null && source[key] !== '') {
            return source[key];
        }
    }
    return '';
}

function cleanText(value, fallback = '') {
    const text = String(value ?? '').trim();
    return text || fallback;
}

function parseNumber(value) {
    const number = parseFloat(String(value ?? '0').replace(/,/g, ''));
    return Number.isFinite(number) ? number : 0;
}

function formatQty(value) {
    const number = parseNumber(value);
    if (Number.isInteger(number)) {
        return number.toLocaleString('en-US');
    }
    return number.toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 2 });
}

function formatPlateDriver(plateNumber, driverName) {
    if (plateNumber && driverName) return `${plateNumber} | ${driverName}`;
    return plateNumber || driverName || 'N/A';
}

function formatMoney(amount) {
    return '₱' + parseNumber(amount).toLocaleString('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text ?? '';
    return div.innerHTML;
}

function getReportColumnCount() {
    const headerRow = document.querySelector('#reportTableHead tr');
    return headerRow ? headerRow.children.length : 1;
}

function buildPrintableReportHtml() {
    const reportTable = document.getElementById('reportTable');
    const reportTitle = document.getElementById('reportTitle')?.textContent || 'Sales Report';
    const reportDateRange = document.getElementById('reportDateRange')?.textContent || '';
    const reportBranchInfo = document.getElementById('reportBranchInfo')?.textContent || '';
    const columnCount = getReportColumnCount();
    const now = new Date();
    const printedDate = now.toLocaleString('en-PH', {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        hour12: true
    });

    if (!reportTable || !reportTable.querySelector('tbody tr')) {
        Swal.fire('Error', 'Please generate the report first before printing.', 'error');
        return '';
    }

    const clonedTable = reportTable.cloneNode(true);
    clonedTable.removeAttribute('id');
    clonedTable.className = 'print-table';

    const oldColgroup = clonedTable.querySelector('colgroup');
    if (oldColgroup) {
        oldColgroup.remove();
    }

    const firstHeaderRow = clonedTable.querySelector('thead tr:not(.print-title-row)');
    if (firstHeaderRow) {
        const colgroup = document.createElement('colgroup');
        firstHeaderRow.querySelectorAll('th').forEach(th => {
            const col = document.createElement('col');

            if (th.classList.contains('count-head')) {
                col.style.width = '2.6%';
            } else if (th.classList.contains('so-details-head')) {
                col.style.width = '9.5%';
            } else if (th.classList.contains('product-col')) {
                col.style.width = '3.5%';
            } else if (th.classList.contains('unit-price-head')) {
                col.style.width = '4.2%';
            } else if (th.classList.contains('invoice-head') || th.classList.contains('payment-head') || th.classList.contains('balance-head')) {
                col.style.width = '6.2%';
            } else if (th.classList.contains('payment-details-head')) {
                col.style.width = '8.8%';
            }

            colgroup.appendChild(col);
        });
        clonedTable.insertBefore(colgroup, clonedTable.firstChild);
    }

    clonedTable.querySelectorAll('.count-cell, .count-head').forEach(cell => {
        cell.style.width = '2.6%';
        cell.style.maxWidth = '2.6%';
    });

    clonedTable.querySelectorAll('.so-details-cell, .so-details-head').forEach(cell => {
        cell.style.width = '9.5%';
        cell.style.maxWidth = '9.5%';
        cell.style.textAlign = 'left';
    });

    clonedTable.querySelectorAll('.product-col').forEach(cell => {
        cell.style.width = '3.5%';
        cell.style.maxWidth = '3.5%';
    });

    clonedTable.querySelectorAll('.unit-price-cell, .unit-price-head').forEach(cell => {
        cell.style.width = '4.2%';
        cell.style.maxWidth = '4.2%';
        cell.style.textAlign = 'center';
    });

    clonedTable.querySelectorAll('.money-cell, .invoice-head, .payment-head, .balance-head').forEach(cell => {
        cell.style.width = '6.2%';
        cell.style.maxWidth = '6.2%';
        cell.style.textAlign = 'center';
    });

    clonedTable.querySelectorAll('.payment-cell, .payment-details-head').forEach(cell => {
        cell.style.width = '8.8%';
        cell.style.maxWidth = '8.8%';
        cell.style.textAlign = 'left';
        cell.style.lineHeight = '1.25';
    });

    const thead = clonedTable.querySelector('thead') || clonedTable.createTHead();
    const headerRow = document.createElement('tr');
    headerRow.className = 'print-title-row';
    headerRow.innerHTML = `
        <th colspan="${columnCount}">
            <div class="print-title-box">
                <img src="../Pictures/amgc3DLogo.png" alt="AMGC Logo" class="print-logo">
                <div class="print-title-text">
                    <div class="print-main-title">${escapeHtml(reportTitle)}</div>
                    <div class="print-subtitle">${escapeHtml(reportDateRange)}</div>
                    <div class="print-subtitle">${escapeHtml(reportBranchInfo)}</div>
                </div>
            </div>
        </th>
    `;
    thead.insertBefore(headerRow, thead.firstChild);

    const tfoot = clonedTable.createTFoot();
    tfoot.innerHTML = `
        <tr class="print-footer-row">
            <td colspan="${columnCount}">
                <div class="print-footer-box">
                    <span>Printed by: <strong><?php echo htmlspecialchars($user_name); ?></strong></span>
                    <span>Printed on: <strong>${escapeHtml(printedDate)}</strong></span>
                </div>
            </td>
        </tr>
    `;

    return `<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title></title>
<style>
@page {
    size: A4 landscape;
    margin: 7mm 6mm 8mm 6mm;
}

* {
    box-sizing: border-box;
}

html,
body {
    margin: 0;
    padding: 0;
    background: #fff;
    color: #052A47;
    font-family: Arial, Helvetica, sans-serif;
    -webkit-print-color-adjust: exact;
    print-color-adjust: exact;
}

.print-table {
    width: 100%;
    max-width: 100%;
    border-collapse: collapse;
    table-layout: fixed;
    font-size: 9px;
    line-height: 1.18;
}

.print-table thead {
    display: table-header-group;
}

.print-table tfoot {
    display: table-footer-group;
}

.print-table tr {
    page-break-inside: avoid;
    break-inside: avoid;
}

.print-table th,
.print-table td {
    border: 0.55px solid #94a3b8;
    padding: 2px 2px;
    text-align: center;
    vertical-align: middle;
    white-space: normal;
    overflow-wrap: anywhere;
    word-break: normal;
}

.print-table thead tr:not(.print-title-row) th {
    background: #047857;
    color: #fff;
    font-size: 8px;
    font-weight: 800;
    text-transform: uppercase;
}

.print-title-row th {
    border: none !important;
    padding: 0 0 4mm 0 !important;
    background: #fff !important;
}

.print-title-box {
    width: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding-bottom: 3mm;
    border-bottom: 2px solid #047857;
}

.print-logo {
    width: 36px;
    height: 36px;
    object-fit: contain;
}

.print-title-text {
    text-align: left;
}

.print-main-title {
    font-size: 17px;
    font-weight: 800;
    color: #052A47;
    line-height: 1.1;
}

.print-subtitle {
    font-size: 10px;
    font-weight: 600;
    color: #334155;
    line-height: 1.2;
}

.print-footer-row td {
    border: none !important;
    padding: 4mm 0 0 0 !important;
    background: #fff !important;
}

.print-footer-box {
    display: flex;
    justify-content: space-between;
    width: 100%;
    border-top: 1px solid #8aa0bd;
    padding-top: 2mm;
    font-size: 10px;
    color: #334155;
}

.count-cell,
.count-head {
    width: 2.6% !important;
    max-width: 2.6% !important;
}

.so-details-cell,
.so-details-head {
    width: 9.5% !important;
    max-width: 9.5% !important;
    text-align: left !important;
    line-height: 1.08 !important;
}

.product-col {
    width: 3.5% !important;
    max-width: 3.5% !important;
    text-align: center !important;
    padding-left: 1px !important;
    padding-right: 1px !important;
}

.unit-price-cell,
.unit-price-head {
    width: 4.2% !important;
    max-width: 4.2% !important;
    text-align: center !important;
    font-size: 8px !important;
}

.money-cell,
.invoice-head,
.payment-head,
.balance-head {
    width: 6.2% !important;
    max-width: 6.2% !important;
    text-align: center !important;
    white-space: normal !important;
    font-size: 8.2px !important;
}

.payment-cell,
.payment-details-head {
    width: 8.8% !important;
    max-width: 8.8% !important;
    text-align: left !important;
    line-height: 1.25 !important;
    white-space: normal !important;
}

.summary-row td {
    background: #f1f5f9 !important;
    font-weight: 800 !important;
}

.volume-row td {
    background: #fff !important;
    font-weight: 700 !important;
}

@media print {
    html,
    body {
        width: 100%;
    }
}
</style>
</head>
<body>
${clonedTable.outerHTML}
</body>
</html>`;
}

function printReport() {
    const html = buildPrintableReportHtml();
    if (!html) return;

    const printWindow = window.open('', '_blank', 'width=1200,height=800');
    if (!printWindow) {
        Swal.fire('Error', 'Please allow pop-ups so the report can open for printing.', 'error');
        return;
    }

    printWindow.document.open();
    printWindow.document.write(html);
    printWindow.document.close();

    printWindow.onload = function() {
        printWindow.focus();
        setTimeout(function() {
            printWindow.print();
            setTimeout(function() {
                printWindow.close();
            }, 500);
        }, 300);
    };
}
// Dashboard Filter Toggle Functionality
document.addEventListener('DOMContentLoaded', function() {
    const filterHeader = document.querySelector('.supplier-filter-header');
    const filterToggleBtn = document.getElementById('dashboardFilterToggleBtn');
    const filterContent = document.getElementById('dashboardFilterContent');
    const filterIcon = document.getElementById('dashboardFilterIcon');

    if (filterHeader && filterContent && filterIcon) {
        filterHeader.addEventListener('click', function(e) {
            const isCollapsed = filterContent.classList.contains('collapsed');

            filterContent.classList.toggle('collapsed', !isCollapsed);
            filterIcon.classList.toggle('bi-chevron-down', !isCollapsed);
            filterIcon.classList.toggle('bi-chevron-up', isCollapsed);

            if (filterToggleBtn) {
                filterToggleBtn.setAttribute('aria-expanded', isCollapsed ? 'true' : 'false');
            }
        });
    }
});

// Sa loob ng generateReport() function, pagkatapos ng renderReport():
function generateReport() {
    const reportCategory = document.getElementById('reportCategory').value;
    const dateFrom = document.getElementById('reportDateFrom').value;
    const dateTo = document.getElementById('reportDateTo').value;

    if (!dateFrom || !dateTo) {
        Swal.fire('Error', 'Please select both From and To dates.', 'error');
        return;
    }

    if (new Date(dateFrom) > new Date(dateTo)) {
        Swal.fire('Error', 'From date cannot be later than To date.', 'error');
        return;
    }

    Swal.fire({
        title: 'Generating Report...',
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading()
    });

    $.ajax({
        url: 'branchdashboard.php',
        type: 'POST',
        data: {
            action: 'generate_dashboard_report_data',
            report_type: 'detailed',
            report_category: reportCategory,
            date_from: dateFrom,
            date_to: dateTo,
            branch_id: <?php echo $branch_id; ?>
        },
        dataType: 'json',
        success: function(response) {
            Swal.close();
            if (response.success) {
                renderReport(response.data, reportCategory, dateFrom, dateTo);
                // Show the Print Report button after successful generation
                const printBtn = document.getElementById('printReportBtn');
                if (printBtn) {
                    printBtn.style.display = 'inline-flex';
                }
            } else {
                Swal.fire('Error', response.message || 'Failed to generate report.', 'error');
                // Hide print button if generation fails
                const printBtn = document.getElementById('printReportBtn');
                if (printBtn) {
                    printBtn.style.display = 'none';
                }
            }
        },
        error: function() {
            Swal.close();
            Swal.fire('Error', 'Failed to connect to server.', 'error');
            // Hide print button on error
            const printBtn = document.getElementById('printReportBtn');
            if (printBtn) {
                printBtn.style.display = 'none';
            }
        }
    });
}

// I-reset ang modal kapag binuksan ulit
document.getElementById('generateReportModal').addEventListener('show.bs.modal', function() {
    // I-hide ang print button
    const printBtn = document.getElementById('printReportBtn');
    if (printBtn) {
        printBtn.style.display = 'none';
    }
    
    // I-hide ang report preview
    const previewArea = document.getElementById('reportPreviewArea');
    if (previewArea) {
        previewArea.classList.remove('show');
    }
    
    // I-reset ang report table
    const tableHead = document.getElementById('reportTableHead');
    const tableBody = document.getElementById('reportTableBody');
    if (tableHead) tableHead.innerHTML = '';
    if (tableBody) tableBody.innerHTML = '';
});


function showProfileModal() { 
    cleanupModalBackdrops();
    new bootstrap.Modal(document.getElementById('profileModal')).show(); 
}

function confirmLogout() {
    // Close the modal first
    const modal = bootstrap.Modal.getInstance(document.getElementById('profileModal'));
    if (modal) {
        modal.hide();
    }
    
    // Show confirmation dialog
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

// ========== MOBILE BOTTOM NAVIGATION FUNCTIONS ==========
// Close all mobile dropdowns
function closeAllMobileDropdowns() {
    const dropdowns = [
        'warehouseMobileMenu', 'supplierMobileMenu', 'customerMobileMenu', 
        'deliveryMobileMenu', 'bankingMobileMenu'
    ];
    dropdowns.forEach(id => {
        const dropdown = document.getElementById(id);
        if (dropdown) dropdown.classList.remove('show');
    });
    document.querySelectorAll('.more-btn').forEach(btn => btn.classList.remove('active'));
}

function toggleMobileDropdown(event, dropdownId) {
    event.preventDefault();
    event.stopPropagation();
    
    const dropdown = document.getElementById(dropdownId);
    const btn = event.currentTarget;
    
    if (!dropdown) {
        console.error('Dropdown not found:', dropdownId);
        return;
    }
    
    if (dropdown.classList.contains('show')) {
        // Close dropdown
        dropdown.classList.remove('show');
        btn.classList.remove('active');
    } else {
        // Close all other dropdowns first
        closeAllMobileDropdowns();
        
        // Open this dropdown
        dropdown.classList.add('show');
        btn.classList.add('active');
        
        // Close dropdown when clicking outside
        setTimeout(() => {
            const closeHandler = (e) => {
                if (!dropdown.contains(e.target) && !btn.contains(e.target)) {
                    dropdown.classList.remove('show');
                    btn.classList.remove('active');
                    document.removeEventListener('click', closeHandler);
                }
            };
            document.addEventListener('click', closeHandler);
        }, 100);
    }
}

// Set active state for mobile navigation
function setActiveMobileNav() {
    const currentPage = window.location.pathname.split('/').pop();
    
    // Remove active class from all
    document.querySelectorAll('.mobile-nav .nav-link, .mobile-nav .dropdown-item').forEach(link => {
        link.classList.remove('active');
    });
    
    // Find and activate matching link
    document.querySelectorAll('.mobile-nav .nav-link, .mobile-nav .dropdown-item').forEach(link => {
        const href = link.getAttribute('href');
        if (href === currentPage) {
            link.classList.add('active');
            
            // Highlight parent dropdown button if exists
            const parentDropdown = link.closest('.more-dropdown');
            if (parentDropdown) {
                const parentBtn = document.querySelector(`[onclick*="${parentDropdown.id}"]`);
                if (parentBtn) parentBtn.classList.add('active');
            }
        }
    });
}

// Close dropdowns on escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeAllMobileDropdowns();
    }
});

// Initialize mobile nav on page load
document.addEventListener('DOMContentLoaded', function() {
    setActiveMobileNav();
    
    // Close dropdowns when clicking outside
    document.addEventListener('click', function(e) {
        if (!e.target.closest('.dropdown-more')) {
            closeAllMobileDropdowns();
        }
    });
});
</script>
</body>
</html>
