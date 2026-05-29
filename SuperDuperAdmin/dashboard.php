<?php
require_once '../config/database.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit;
}

$user_id = (int)($_SESSION['user_id'] ?? 0);
$user_name = $_SESSION['user_name'] ?? ((($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '')) ?: 'Super Duper Admin');
$user_role = $_SESSION['role'] ?? '';

$user_initials = '';
foreach (explode(' ', trim($user_name)) as $part) {
    if ($part !== '') {
        $user_initials .= strtoupper(substr($part, 0, 1));
    }
}
$user_initials = $user_initials ?: 'SDA';

$active_period = $_GET['period'] ?? 'monthly';
if (!in_array($active_period, ['daily', 'weekly', 'monthly', 'yearly', 'custom'], true)) {
    $active_period = 'monthly';
}

$selected_date = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selected_date)) {
    $selected_date = date('Y-m-d');
}

$custom_start_date = $_GET['start_date'] ?? date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $custom_start_date)) {
    $custom_start_date = date('Y-m-01');
}

$custom_end_date = $_GET['end_date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $custom_end_date)) {
    $custom_end_date = date('Y-m-d');
}

if (strtotime($custom_start_date) > strtotime($custom_end_date)) {
    [$custom_start_date, $custom_end_date] = [$custom_end_date, $custom_start_date];
}

$selected_business_unit = trim($_GET['business_unit'] ?? '');
$selected_branch_filter_id = (int)($_GET['branch_id'] ?? 0);

function money($amount) {
    return '₱' . number_format((float)$amount, 2);
}

function bind_stmt_params(mysqli_stmt $stmt, string $types, array $params): void {
    if ($types === '' || empty($params)) {
        return;
    }
    $refs = [];
    $refs[] = $types;
    foreach ($params as $key => $value) {
        $refs[] = &$params[$key];
    }
    call_user_func_array([$stmt, 'bind_param'], $refs);
}

function db_table_exists(mysqli $conn, string $table): bool {
    $safe_table = $conn->real_escape_string($table);
    $result = $conn->query("SHOW TABLES LIKE '{$safe_table}'");
    return $result && $result->num_rows > 0;
}

function db_column_exists(mysqli $conn, string $table, string $column): bool {
    $safe_table = $conn->real_escape_string($table);
    $safe_column = $conn->real_escape_string($column);
    $result = $conn->query("SHOW COLUMNS FROM `{$safe_table}` LIKE '{$safe_column}'");
    return $result && $result->num_rows > 0;
}

function first_existing_column(mysqli $conn, string $table, array $columns): string {
    foreach ($columns as $column) {
        if (db_column_exists($conn, $table, $column)) {
            return $column;
        }
    }
    return '';
}

function sql_col(string $alias, string $column): string {
    return $alias . ".`" . str_replace("`", "``", $column) . "`";
}

function get_period_date_info(string $period, string $selected_date, string $custom_start_date = '', string $custom_end_date = ''): array {
    if ($period === 'weekly') {
        $week_start = date('Y-m-d', strtotime('monday this week', strtotime($selected_date)));
        $week_end = date('Y-m-d', strtotime('sunday this week', strtotime($selected_date)));
        return [
            'start' => $week_start,
            'end' => $week_end,
            'label' => date('M d', strtotime($week_start)) . ' - ' . date('M d, Y', strtotime($week_end))
        ];
    }

    if ($period === 'monthly') {
        $month_start = date('Y-m-01', strtotime($selected_date));
        $month_end = date('Y-m-t', strtotime($selected_date));
        return [
            'start' => $month_start,
            'end' => $month_end,
            'label' => date('F Y', strtotime($selected_date))
        ];
    }

    if ($period === 'yearly') {
        $year_start = date('Y-01-01', strtotime($selected_date));
        $year_end = date('Y-12-31', strtotime($selected_date));
        return [
            'start' => $year_start,
            'end' => $year_end,
            'label' => date('Y', strtotime($selected_date))
        ];
    }

    if ($period === 'custom') {
        return [
            'start' => $custom_start_date,
            'end' => $custom_end_date,
            'label' => date('M d, Y', strtotime($custom_start_date)) . ' - ' . date('M d, Y', strtotime($custom_end_date))
        ];
    }

    return [
        'start' => $selected_date,
        'end' => $selected_date,
        'label' => date('F d, Y', strtotime($selected_date))
    ];
}

// Get all business units and branches for filter
$business_units = [];
$branch_options = [];
$branch_lookup = [];

$branch_options_sql = "SELECT branch_id, branch_name, business_unit FROM branches WHERE status = 'active' ORDER BY NULLIF(TRIM(business_unit), '') ASC, branch_name ASC";
$branch_options_result = $conn->query($branch_options_sql);
if ($branch_options_result) {
    while ($branch_option = $branch_options_result->fetch_assoc()) {
        $branch_option['business_unit'] = trim((string)($branch_option['business_unit'] ?? ''));
        $branch_options[] = $branch_option;
        $branch_lookup[(int)$branch_option['branch_id']] = $branch_option;
        if ($branch_option['business_unit'] !== '') {
            $business_units[$branch_option['business_unit']] = $branch_option['business_unit'];
        }
    }
}
ksort($business_units);

if ($selected_branch_filter_id > 0 && !isset($branch_lookup[$selected_branch_filter_id])) {
    $selected_branch_filter_id = 0;
}
if ($selected_business_unit !== '' && !isset($business_units[$selected_business_unit])) {
    $selected_business_unit = '';
}

// Calculate period info
$current_period_info = get_period_date_info($active_period, $selected_date, $custom_start_date, $custom_end_date);
$start_date = $current_period_info['start'];
$end_date = $current_period_info['end'];
$period_label = $current_period_info['label'];

// ========================================
// GLOBAL SUMMARY STATS (All delivered SOs)
// ========================================

// Build WHERE clause for filters
$where_clauses = ["so.order_status = 'delivered'", "so.order_date IS NOT NULL", "so.order_date <> '0000-00-00 00:00:00'", "DATE(so.order_date) BETWEEN ? AND ?"];
$param_types = 'ss';
$params = [$start_date, $end_date];

if ($selected_business_unit !== '') {
    $where_clauses[] = "b.business_unit = ?";
    $param_types .= 's';
    $params[] = $selected_business_unit;
}
if ($selected_branch_filter_id > 0) {
    $where_clauses[] = "so.branch_id = ?";
    $param_types .= 'i';
    $params[] = $selected_branch_filter_id;
}

$where_sql = implode(' AND ', $where_clauses);

// Sales = Total SOs delivered
// COGS = Cost of all items delivered
// Gross Profit = Sales - COGS
$summary_sql = "SELECT 
    COALESCE(SUM(CASE WHEN so.order_amount > 0 THEN so.order_amount ELSE so.total_amount END), 0) AS sales,
    COALESCE(SUM(so.cogs_amount), 0) AS cogs,
    COALESCE(SUM(CASE
        WHEN so.gross_profit_amount <> 0 THEN so.gross_profit_amount
        WHEN so.gross_profit <> 0 THEN so.gross_profit
        ELSE (CASE WHEN so.order_amount > 0 THEN so.order_amount ELSE so.total_amount END) - so.cogs_amount
    END), 0) AS gross_profit,
    COUNT(*) AS total_orders
FROM sales_orders so
LEFT JOIN branches b ON so.branch_id = b.branch_id
WHERE $where_sql";

$summary_stmt = $conn->prepare($summary_sql);
$sales = $cogs = $gross_profit = $total_orders = 0;
if ($summary_stmt) {
    bind_stmt_params($summary_stmt, $param_types, $params);
    $summary_stmt->execute();
    $summary_row = $summary_stmt->get_result()->fetch_assoc();
    if ($summary_row) {
        $sales = (float)$summary_row['sales'];
        $cogs = (float)$summary_row['cogs'];
        $gross_profit = (float)$summary_row['gross_profit'];
        $total_orders = (int)$summary_row['total_orders'];
    }
    $summary_stmt->close();
}

// Expenses = Total expenses (bank_transactions with type withdrawal and expense_account)
$expense_where = ["bt.transaction_type = 'withdrawal'", "bt.transaction_date IS NOT NULL", "DATE(bt.transaction_date) BETWEEN ? AND ?", "bt.expense_account IS NOT NULL", "TRIM(bt.expense_account) <> ''"];
$expense_types = 'ss';
$expense_params = [$start_date, $end_date];

if ($selected_business_unit !== '' || $selected_branch_filter_id > 0) {
    $expense_where[] = "EXISTS (SELECT 1 FROM branches eb WHERE eb.branch_id = bt.branch_id" . 
        ($selected_business_unit !== '' ? " AND eb.business_unit = ?" : "") .
        ($selected_branch_filter_id > 0 ? " AND eb.branch_id = ?" : "") . ")";
    if ($selected_business_unit !== '') {
        $expense_types .= 's';
        $expense_params[] = $selected_business_unit;
    }
    if ($selected_branch_filter_id > 0) {
        $expense_types .= 'i';
        $expense_params[] = $selected_branch_filter_id;
    }
}

$expense_sql = "SELECT COALESCE(SUM(bt.amount), 0) AS expenses FROM bank_transactions bt WHERE " . implode(' AND ', $expense_where);
$expense_stmt = $conn->prepare($expense_sql);
$expenses = 0;
if ($expense_stmt) {
    bind_stmt_params($expense_stmt, $expense_types, $expense_params);
    $expense_stmt->execute();
    $expense_row = $expense_stmt->get_result()->fetch_assoc();
    $expenses = (float)($expense_row['expenses'] ?? 0);
    $expense_stmt->close();
}

$net_profit = $gross_profit - $expenses;
$gross_margin_percent = $sales > 0 ? ($gross_profit / $sales) * 100 : 0;
$expense_ratio_percent = $sales > 0 ? ($expenses / $sales) * 100 : 0;
$net_margin_percent = $sales > 0 ? ($net_profit / $sales) * 100 : 0;
$report_generated_at = date('m/d/Y h:i A');
$report_basis = $_GET['report_basis'] ?? 'accrual';
if (!in_array($report_basis, ['accrual', 'cash'], true)) { $report_basis = 'accrual'; }
$total_by = $_GET['total_by'] ?? 'business_unit';
if (!in_array($total_by, ['business_unit', 'branch', 'agent'], true)) { $total_by = 'business_unit'; }
$sort_by = $_GET['sort_by'] ?? 'default';
if (!in_array($sort_by, ['default', 'sales', 'gross_profit', 'expenses', 'net_profit', 'volume'], true)) { $sort_by = 'default'; }
$sort_dir = $_GET['sort_dir'] ?? 'asc';
if (!in_array($sort_dir, ['asc', 'desc'], true)) { $sort_dir = 'asc'; }

// ========================================
// PROFIT AND LOSS PER BUSINESS UNIT & BRANCH
// ========================================
$pnl_sql = "SELECT
    COALESCE(NULLIF(TRIM(b.business_unit), ''), 'Unassigned') AS business_unit,
    b.branch_id,
    b.branch_name,
    COALESCE(SUM(CASE WHEN so.order_amount > 0 THEN so.order_amount ELSE so.total_amount END), 0) AS sales,
    COALESCE(SUM(so.cogs_amount), 0) AS cogs,
    COALESCE(SUM(CASE
        WHEN so.gross_profit_amount <> 0 THEN so.gross_profit_amount
        WHEN so.gross_profit <> 0 THEN so.gross_profit
        ELSE (CASE WHEN so.order_amount > 0 THEN so.order_amount ELSE so.total_amount END) - so.cogs_amount
    END), 0) AS gross_profit,
    COALESCE(exp.expenses, 0) AS expenses,
    (COALESCE(SUM(CASE
        WHEN so.gross_profit_amount <> 0 THEN so.gross_profit_amount
        WHEN so.gross_profit <> 0 THEN so.gross_profit
        ELSE (CASE WHEN so.order_amount > 0 THEN so.order_amount ELSE so.total_amount END) - so.cogs_amount
    END), 0) - COALESCE(exp.expenses, 0)) AS net_profit
FROM branches b
LEFT JOIN sales_orders so
    ON so.branch_id = b.branch_id
    AND so.order_status = 'delivered'
    AND so.order_date IS NOT NULL
    AND so.order_date <> '0000-00-00 00:00:00'
    AND DATE(so.order_date) BETWEEN ? AND ?
LEFT JOIN (
    SELECT bt.branch_id, COALESCE(SUM(bt.amount), 0) AS expenses
    FROM bank_transactions bt
    WHERE bt.transaction_type = 'withdrawal'
        AND bt.transaction_date IS NOT NULL
        AND DATE(bt.transaction_date) BETWEEN ? AND ?
        AND bt.expense_account IS NOT NULL
        AND TRIM(bt.expense_account) <> ''
    GROUP BY bt.branch_id
) exp ON exp.branch_id = b.branch_id
WHERE b.status = 'active'";

$pnl_types = 'ssss';
$pnl_params = [$start_date, $end_date, $start_date, $end_date];

if ($selected_business_unit !== '') {
    $pnl_sql .= " AND b.business_unit = ?";
    $pnl_types .= 's';
    $pnl_params[] = $selected_business_unit;
}
if ($selected_branch_filter_id > 0) {
    $pnl_sql .= " AND b.branch_id = ?";
    $pnl_types .= 'i';
    $pnl_params[] = $selected_branch_filter_id;
}

$pnl_sql .= " GROUP BY b.branch_id, b.business_unit, b.branch_name, exp.expenses ORDER BY COALESCE(NULLIF(TRIM(b.business_unit), ''), 'Unassigned') ASC, b.branch_name ASC";

$pnl_stmt = $conn->prepare($pnl_sql);
$pnl_rows = [];
if ($pnl_stmt) {
    bind_stmt_params($pnl_stmt, $pnl_types, $pnl_params);
    $pnl_stmt->execute();
    $pnl_result = $pnl_stmt->get_result();
    while ($row = $pnl_result->fetch_assoc()) {
        $pnl_rows[] = $row;
    }
    $pnl_stmt->close();
}

// Group by business unit for subtotals
$pnl_by_bu = [];
foreach ($pnl_rows as $row) {
    $bu = $row['business_unit'];
    if (!isset($pnl_by_bu[$bu])) {
        $pnl_by_bu[$bu] = ['branches' => [], 'totals' => ['sales' => 0, 'cogs' => 0, 'gross_profit' => 0, 'expenses' => 0, 'net_profit' => 0]];
    }
    $pnl_by_bu[$bu]['branches'][] = $row;
    $pnl_by_bu[$bu]['totals']['sales'] += (float)$row['sales'];
    $pnl_by_bu[$bu]['totals']['cogs'] += (float)$row['cogs'];
    $pnl_by_bu[$bu]['totals']['gross_profit'] += (float)$row['gross_profit'];
    $pnl_by_bu[$bu]['totals']['expenses'] += (float)$row['expenses'];
    $pnl_by_bu[$bu]['totals']['net_profit'] += (float)$row['net_profit'];
}

// ========================================
// VOLUME PER BUSINESS UNIT, BRANCH, AGENT (QTY)
// ========================================
$volume_sql = "SELECT
    COALESCE(NULLIF(TRIM(b.business_unit), ''), 'Unassigned') AS business_unit,
    b.branch_name,
    CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, '')) AS agent_name,
    COALESCE(SUM(soi.quantity_ordered), 0) AS total_qty,
    COUNT(DISTINCT so.so_id) AS total_orders
FROM sales_orders so
LEFT JOIN branches b ON so.branch_id = b.branch_id
LEFT JOIN users u ON so.created_by = u.user_id
LEFT JOIN sales_order_items soi ON so.so_id = soi.so_id
WHERE so.order_status = 'delivered'
    AND so.order_date IS NOT NULL
    AND so.order_date <> '0000-00-00 00:00:00'
    AND DATE(so.order_date) BETWEEN ? AND ?";

$volume_types = 'ss';
$volume_params = [$start_date, $end_date];

if ($selected_business_unit !== '') {
    $volume_sql .= " AND b.business_unit = ?";
    $volume_types .= 's';
    $volume_params[] = $selected_business_unit;
}
if ($selected_branch_filter_id > 0) {
    $volume_sql .= " AND so.branch_id = ?";
    $volume_types .= 'i';
    $volume_params[] = $selected_branch_filter_id;
}

$volume_sql .= " GROUP BY b.business_unit, b.branch_name, u.user_id ORDER BY COALESCE(NULLIF(TRIM(b.business_unit), ''), 'Unassigned') ASC, b.branch_name ASC, total_qty DESC";

$volume_stmt = $conn->prepare($volume_sql);
$volume_rows = [];
if ($volume_stmt) {
    bind_stmt_params($volume_stmt, $volume_types, $volume_params);
    $volume_stmt->execute();
    $volume_result = $volume_stmt->get_result();
    while ($row = $volume_result->fetch_assoc()) {
        $volume_rows[] = $row;
    }
    $volume_stmt->close();
}

// Group volume by BU > Branch > Agent
$volume_by_bu = [];
foreach ($volume_rows as $row) {
    $bu = $row['business_unit'];
    $branch = $row['branch_name'];
    if (!isset($volume_by_bu[$bu])) {
        $volume_by_bu[$bu] = ['branches' => [], 'total_qty' => 0, 'total_orders' => 0];
    }
    if (!isset($volume_by_bu[$bu]['branches'][$branch])) {
        $volume_by_bu[$bu]['branches'][$branch] = ['agents' => [], 'total_qty' => 0, 'total_orders' => 0];
    }
    $volume_by_bu[$bu]['branches'][$branch]['agents'][] = $row;
    $volume_by_bu[$bu]['branches'][$branch]['total_qty'] += (int)$row['total_qty'];
    $volume_by_bu[$bu]['branches'][$branch]['total_orders'] += (int)$row['total_orders'];
    $volume_by_bu[$bu]['total_qty'] += (int)$row['total_qty'];
    $volume_by_bu[$bu]['total_orders'] += (int)$row['total_orders'];
}

// Prepare chart data for P&L by BU
$pnl_chart_labels = [];
$pnl_chart_sales = [];
$pnl_chart_cogs = [];
$pnl_chart_gross = [];
$pnl_chart_expenses = [];
$pnl_chart_net = [];
foreach ($pnl_by_bu as $bu_name => $bu_data) {
    $pnl_chart_labels[] = $bu_name;
    $pnl_chart_sales[] = round($bu_data['totals']['sales'], 2);
    $pnl_chart_cogs[] = round($bu_data['totals']['cogs'], 2);
    $pnl_chart_gross[] = round($bu_data['totals']['gross_profit'], 2);
    $pnl_chart_expenses[] = round($bu_data['totals']['expenses'], 2);
    $pnl_chart_net[] = round($bu_data['totals']['net_profit'], 2);
}

// Prepare chart data for Volume by BU
$volume_chart_labels = [];
$volume_chart_qty = [];
$volume_chart_orders = [];
foreach ($volume_by_bu as $bu_name => $bu_data) {
    $volume_chart_labels[] = $bu_name;
    $volume_chart_qty[] = $bu_data['total_qty'];
    $volume_chart_orders[] = $bu_data['total_orders'];
}

// Prepare chart data for Volume by Branch (top 10)
$volume_branch_labels = [];
$volume_branch_qty = [];
$branch_volume_temp = [];
foreach ($volume_by_bu as $bu_data) {
    foreach ($bu_data['branches'] as $branch_name => $branch_data) {
        $branch_volume_temp[$branch_name] = ($branch_volume_temp[$branch_name] ?? 0) + $branch_data['total_qty'];
    }
}
arsort($branch_volume_temp);
$branch_volume_temp = array_slice($branch_volume_temp, 0, 10, true);
foreach ($branch_volume_temp as $branch_name => $qty) {
    $volume_branch_labels[] = $branch_name;
    $volume_branch_qty[] = $qty;
}

// Prepare chart data for Volume by Agent (top 10)
$volume_agent_labels = [];
$volume_agent_qty = [];
$agent_volume_temp = [];
foreach ($volume_rows as $row) {
    $agent_name = trim($row['agent_name']) ?: 'Unknown';
    $agent_volume_temp[$agent_name] = ($agent_volume_temp[$agent_name] ?? 0) + (int)$row['total_qty'];
}
arsort($agent_volume_temp);
$agent_volume_temp = array_slice($agent_volume_temp, 0, 10, true);
foreach ($agent_volume_temp as $agent_name => $qty) {
    $volume_agent_labels[] = $agent_name;
    $volume_agent_qty[] = $qty;
}

// ========================================
// COGS DETAILS BY SOURCE / ITEM
// QuickBooks-style drilldown for Cost of Goods Sold
// ========================================
$cogs_detail_rows = [];
$cogs_detail_total = 0;

if (db_table_exists($conn, 'sales_order_items')) {
    $item_name_col = first_existing_column($conn, 'sales_order_items', ['item_name', 'product_name', 'description', 'item_description', 'product_description', 'name']);
    $product_id_col = first_existing_column($conn, 'sales_order_items', ['product_id', 'item_id', 'inventory_id', 'current_inventory_id', 'stock_id']);
    $qty_col = first_existing_column($conn, 'sales_order_items', ['quantity_ordered', 'quantity', 'qty', 'order_qty']);
    $unit_col = first_existing_column($conn, 'sales_order_items', ['unit', 'uom', 'unit_type', 'unit_name']);
    $unit_cost_col = first_existing_column($conn, 'sales_order_items', ['unit_cost', 'cost_price', 'item_cost', 'purchase_price', 'average_cost']);
    $total_cost_col = first_existing_column($conn, 'sales_order_items', ['total_cost', 'cogs_amount', 'cogs', 'total_cogs', 'cost_total']);

    $item_expr = $item_name_col !== ''
        ? sql_col('soi', $item_name_col)
        : ($product_id_col !== '' ? "CONCAT('Item #', " . sql_col('soi', $product_id_col) . ")" : "'Item / Product'");

    $qty_expr = $qty_col !== '' ? "COALESCE(" . sql_col('soi', $qty_col) . ", 0)" : "0";
    $unit_expr = $unit_col !== '' ? sql_col('soi', $unit_col) : "''";

    if ($unit_cost_col !== '') {
        $unit_cost_expr = "COALESCE(" . sql_col('soi', $unit_cost_col) . ", 0)";
    } elseif ($total_cost_col !== '' && $qty_col !== '') {
        $unit_cost_expr = "CASE WHEN {$qty_expr} > 0 THEN COALESCE(" . sql_col('soi', $total_cost_col) . ", 0) / {$qty_expr} ELSE 0 END";
    } else {
        $unit_cost_expr = "0";
    }

    if ($total_cost_col !== '') {
        $line_cogs_expr = "COALESCE(" . sql_col('soi', $total_cost_col) . ", 0)";
    } elseif ($unit_cost_col !== '' && $qty_col !== '') {
        $line_cogs_expr = "({$unit_cost_expr} * {$qty_expr})";
    } else {
        $line_cogs_expr = "0";
    }

    $cogs_where = [
        "so.order_status = 'delivered'",
        "so.order_date IS NOT NULL",
        "so.order_date <> '0000-00-00 00:00:00'",
        "DATE(so.order_date) BETWEEN ? AND ?"
    ];
    $cogs_types = 'ss';
    $cogs_params = [$start_date, $end_date];

    if ($selected_business_unit !== '') {
        $cogs_where[] = "b.business_unit = ?";
        $cogs_types .= 's';
        $cogs_params[] = $selected_business_unit;
    }
    if ($selected_branch_filter_id > 0) {
        $cogs_where[] = "so.branch_id = ?";
        $cogs_types .= 'i';
        $cogs_params[] = $selected_branch_filter_id;
    }

    $customer_join = db_table_exists($conn, 'customers') ? "LEFT JOIN customers c ON so.customer_id = c.customer_id" : "";
    $customer_select = db_table_exists($conn, 'customers') && db_column_exists($conn, 'customers', 'customer_name')
        ? "COALESCE(c.customer_name, '')"
        : "''";

    $cogs_detail_sql = "SELECT
        so.so_id,
        COALESCE(so.so_number, CONCAT('SO-', so.so_id)) AS reference_no,
        so.order_date,
        COALESCE(NULLIF(TRIM(b.business_unit), ''), 'Unassigned') AS business_unit,
        COALESCE(b.branch_name, '') AS branch_name,
        TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) AS agent_name,
        {$customer_select} AS customer_name,
        {$item_expr} AS item_name,
        {$unit_expr} AS unit_name,
        {$qty_expr} AS qty,
        {$unit_cost_expr} AS unit_cost,
        {$line_cogs_expr} AS line_cogs,
        COALESCE(so.cogs_amount, 0) AS order_cogs
    FROM sales_orders so
    INNER JOIN sales_order_items soi ON so.so_id = soi.so_id
    LEFT JOIN branches b ON so.branch_id = b.branch_id
    LEFT JOIN users u ON so.created_by = u.user_id
    {$customer_join}
    WHERE " . implode(' AND ', $cogs_where) . "
    ORDER BY so.order_date DESC, so.so_id DESC";

    $cogs_detail_stmt = $conn->prepare($cogs_detail_sql);
    if ($cogs_detail_stmt) {
        bind_stmt_params($cogs_detail_stmt, $cogs_types, $cogs_params);
        $cogs_detail_stmt->execute();
        $cogs_detail_result = $cogs_detail_stmt->get_result();
        while ($row = $cogs_detail_result->fetch_assoc()) {
            $row['line_cogs'] = (float)($row['line_cogs'] ?? 0);
            $row['unit_cost'] = (float)($row['unit_cost'] ?? 0);
            $row['qty'] = (float)($row['qty'] ?? 0);
            $cogs_detail_total += $row['line_cogs'];
            $cogs_detail_rows[] = $row;
        }
        $cogs_detail_stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Super Duper Admin - Dashboard</title>
    <link rel="icon" type="image/png" href="../Pictures/favicon-96x96.png" sizes="96x96" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root {
            --green: #2E7D32;
            --green-haze: #1B5E20;
            --deep-sea: #0D4C14;
            --forest-green: #1B4D1F;
            --yellow: #FFC107;
            --white: #FFFFFF;
            --light-gray: #F5F5F5;
            --black: #212121;
            --primary-green: #44D34E;
            --secondary-green: #44D34E;
            --light-green: #d1fae5;
            --dark-green: #047857;
            --dark-color: #052A47;
            --light-color: #f9fafb;
        }

        body {
            background: #f8fafc;
            font-family: 'Inter', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            color: var(--dark-color);
            margin: 0;
            padding: 0;
        }

        .main-content {
            padding: 1.5rem;
            min-height: 100vh;
            max-width: 1600px;
            margin: 0 auto;
        }

        .period-pill {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.5rem 1rem;
            border-radius: 999px;
            background: rgba(4, 120, 87, 0.1);
            color: #047857;
            font-size: 0.85rem;
            font-weight: 700;
            white-space: nowrap;
        }

        .form-card {
            background: #fff;
            border-radius: 20px;
            padding: 1.25rem;
            box-shadow: 0 8px 20px -5px rgba(4, 120, 87, 0.12);
            border: 1px solid rgba(68, 211, 78, 0.2);
            margin-bottom: 1.25rem;
        }

        .form-card h5 {
            color: var(--dark-green);
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 1rem;
            padding-bottom: 0.65rem;
            border-bottom: 2px solid rgba(68, 211, 78, 0.2);
        }

        .form-card h5 i {
            color: var(--primary-green);
            background: rgba(68, 211, 78, 0.1);
            padding: 0.45rem;
            border-radius: 10px;
        }

        .btn-amgc-primary {
            background: linear-gradient(135deg, #047857 0%, #44D34E 100%);
            color: #fff;
            border: none;
            border-radius: 10px;
            min-height: 44px;
            padding: 0.55rem 1rem;
        }

        .btn-amgc-primary:hover {
            color: #fff;
            opacity: 0.95;
        }

        .form-control,
        .form-select {
            border-radius: 10px;
            border: 1px solid rgba(5, 42, 71, 0.15);
            min-height: 44px;
            color: var(--dark-color);
        }

        .form-control:focus,
        .form-select:focus {
            border-color: var(--primary-green);
            box-shadow: 0 0 0 0.2rem rgba(68, 211, 78, 0.15);
        }

/* ===== STAT CARDS ===== */
.stat-card-row {
    margin-bottom: 1.5rem;
}

.stat-card {
    background: transparent !important;
    border: none !important;
    box-shadow: 0 4px 10px rgba(0, 0, 0, 0.08) !important;
    min-height: auto !important;
    height: auto !important;
    padding: 0.8rem !important;
    transition: transform 0.2s ease, box-shadow 0.2s ease !important;
    cursor: default !important;
    border-radius: 20px !important;
}

/* Gradient backgrounds for each stat type */
.stat-card.sales,
.stat-card.cogs,
.stat-card.gross,
.stat-card.expenses,
.stat-card.net-profit {
    background: linear-gradient(135deg, #047857, #059669) !important;
}

.stat-card.net-loss {
    background: linear-gradient(135deg, #dc2626, #ef4444) !important;
}

/* Force text colors to white */
.stat-card .stat-value,
.stat-card .stat-label,
.stat-card .stat-content,
.stat-card small,
.stat-card small i,
.stat-card i,
.stat-card .stat-icon,
.stat-card i.bi,
.stat-card .bi {
    color: white !important;
}

.stat-card .stat-content,
.stat-card .stat-icon {
    background: transparent !important;
}

.stat-card:hover {
    transform: translateY(-2px) !important;
    box-shadow: 0 6px 15px rgba(0, 0, 0, 0.15) !important;
}

/* Desktop: Horizontal layout */
@media (min-width: 992px) {
    .stat-card {
        display: flex !important;
        flex-direction: row !important;
        align-items: center !important;
        text-align: left !important;
        padding: 1rem !important;
        min-height: 110px;
    }
    
    .stat-card i,
    .stat-card .stat-icon {
        flex-shrink: 0;
        margin: 0 0.75rem 0 0 !important;
        font-size: 1.8rem !important;
    }
    
    .stat-card .stat-content {
        flex: 1;
        min-width: 0;
    }
    
    .stat-card .stat-value {
        font-size: 1.2rem !important;
        font-weight: bold !important;
        margin-bottom: 0.1rem !important;
        word-break: break-word;
    }
    
    .stat-card .stat-label {
        font-size: 0.7rem !important;
        font-weight: 500 !important;
        word-break: break-word;
    }
    
    .stat-card small {
        display: block !important;
        font-size: 0.6rem !important;
        opacity: 0.85;
    }
}

/* Mobile: Square cards with centered icon */
@media (max-width: 991px) {
    .stat-card {
        display: flex !important;
        flex-direction: column !important;
        align-items: center !important;
        text-align: center !important;
        justify-content: center !important;
        padding: 0.75rem !important;
        min-height: 120px;
    }
    
    .stat-card i,
    .stat-card .stat-icon {
        font-size: 1.5rem !important;
        margin-bottom: 0.4rem !important;
    }
    
    .stat-card .stat-value {
        font-size: 1rem !important;
        font-weight: bold !important;
        word-break: break-word;
    }
    
    .stat-card .stat-label {
        font-size: 0.6rem !important;
        font-weight: 500 !important;
        word-break: break-word;
    }
    
    .stat-card small {
        display: none !important;
    }
}

/* Mobile small (below 576px) */
@media (max-width: 575px) {
    .stat-card {
        padding: 0.6rem !important;
        min-height: 110px;
    }
    
    .stat-card i,
    .stat-card .stat-icon {
        font-size: 1.3rem !important;
        margin-bottom: 0.3rem !important;
    }
    
    .stat-card .stat-value {
        font-size: 0.85rem !important;
    }
    
    .stat-card .stat-label {
        font-size: 0.55rem !important;
    }
}

/* Extra small mobile (below 400px) */
@media (max-width: 399px) {
    .stat-card {
        padding: 0.5rem !important;
        min-height: 100px;
    }
    
    .stat-card i,
    .stat-card .stat-icon {
        font-size: 1.1rem !important;
        margin-bottom: 0.25rem !important;
    }
    
    .stat-card .stat-value {
        font-size: 0.75rem !important;
    }
    
    .stat-card .stat-label {
        font-size: 0.5rem !important;
    }
}

/* Landscape mode */
@media (max-height: 500px) and (orientation: landscape) {
    .stat-card {
        flex-direction: row !important;
        align-items: center !important;
        text-align: left !important;
        padding: 0.4rem !important;
        min-height: 70px;
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
        font-size: 0.55rem !important;
    }
    
    .stat-card small {
        display: none !important;
    }
}
/* ===== FIX PARA SA AMOUNT VALUES SA MOBILE - NO SCROLL, NO BREAK ===== */

/* Para sa lahat ng screen sizes - pipigilan ang scroll at break */
.stat-card .stat-value {
    white-space: nowrap !important;
    overflow-x: hidden !important;
    overflow-y: hidden !important;
    max-width: 100% !important;
    display: block !important;
    font-weight: bold !important;
    text-overflow: clip !important;
}

/* Desktop (992px and above) */
@media (min-width: 992px) {
    .stat-card .stat-value {
        font-size: 1.2rem !important;
    }
}

/* Laptop (768px - 991px) */
@media (min-width: 768px) and (max-width: 991px) {
    .stat-card .stat-value {
        font-size: 0.9rem !important;
        text-align: center !important;
    }
}

/* Mobile (576px - 767px) - liliit ang font para hindi mag-scroll */
@media (min-width: 576px) and (max-width: 767px) {
    .stat-card .stat-value {
        font-size: 0.75rem !important;
        text-align: center !important;
    }
}

/* Mobile small (below 575px) - mas liliit ang font */
@media (max-width: 575px) {
    .stat-card .stat-value {
        font-size: 0.65rem !important;
        text-align: center !important;
    }
}

/* Extra small mobile (below 450px) */
@media (max-width: 449px) {
    .stat-card .stat-value {
        font-size: 0.6rem !important;
    }
}

/* Very small mobile (below 380px) */
@media (max-width: 379px) {
    .stat-card .stat-value {
        font-size: 0.55rem !important;
    }
}

/* Extremely small (below 330px) */
@media (max-width: 329px) {
    .stat-card .stat-value {
        font-size: 0.5rem !important;
    }
}

/* Landscape mode */
@media (max-height: 500px) and (orientation: landscape) {
    .stat-card .stat-value {
        font-size: 0.65rem !important;
    }
}

/* Siguraduhin na ang container ay may sapat na space */
.stat-card .stat-content {
    min-width: 0 !important;
    overflow: hidden !important;
    width: 100% !important;
}

/* Para maging maganda ang spacing ng amount at label */
.stat-card .stat-value {
    margin-bottom: 0.15rem !important;
    line-height: 1.2 !important;
}
/* ===== FIX PARA PANTAY NA HEIGHT NG STAT CARDS ===== */

/* Siguraduhin na ang lahat ng card ay may pantay na height */
.stat-card-row {
    display: flex;
    flex-wrap: wrap;
    align-items: stretch;
}

.stat-card {
    height: 100% !important;
    display: flex !important;
    flex-direction: column !important;
}

/* Para sa desktop - horizontal layout pero pantay ang height */
@media (min-width: 992px) {
    .stat-card {
        display: flex !important;
        flex-direction: row !important;
        align-items: center !important;
        min-height: 120px !important;
        height: auto !important;
    }
    
    .stat-card .stat-content {
        flex: 1;
        min-width: 0;
    }
}

/* Para sa mobile - vertical layout na pantay ang height */
@media (max-width: 991px) {
    .stat-card {
        min-height: 90px !important;
        height: 100% !important;
        display: flex !important;
        flex-direction: column !important;
        justify-content: center !important;
    }
}

/* Siguraduhin na ang bawat col ay stretch para pantay ang mga card */
.stat-card-row > [class*="col-"] {
    display: flex;
    flex-direction: column;
}

/* Para sa amount value - para hindi mag-iba ang line height */
.stat-card .stat-value {
    line-height: 1.3 !important;
    margin-bottom: 0.25rem !important;
}

/* Para sa label - fixed line height para pantay */
.stat-card .stat-label {
    line-height: 1.3 !important;
    min-height: 2.6em !important; /* Para sa 2 lines */
}

/* Para sa small text - kung meron */
.stat-card small {
    line-height: 1.2 !important;
    margin-top: 0.1rem !important;
}

        /* CHART CARDS */
        .chart-card {
            background: #fff;
            border-radius: 20px;
            box-shadow: 0 8px 20px -5px rgba(4, 120, 87, 0.12);
            border: 1px solid rgba(68, 211, 78, 0.18);
            overflow: hidden;
            margin-bottom: 1.25rem;
            height: 100%;
        }

        .chart-header {
            padding: 1rem 1.25rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            background: linear-gradient(135deg, #047857 0%, #059669 100%);
        }

        .chart-header h5 {
            margin: 0;
            color: #fff;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 1rem;
        }

        .chart-body {
            padding: 1.25rem;
            position: relative;
            min-height: 300px;
        }

        .chart-body canvas {
            max-height: 350px;
        }

        /* DATA TABLE STYLES */
        .data-table {
            background: #fff;
            border-radius: 20px;
            box-shadow: 0 8px 20px -5px rgba(4, 120, 87, 0.12);
            border: 1px solid rgba(68, 211, 78, 0.18);
            overflow: hidden;
            margin-bottom: 1.25rem;
        }

        .table-header {
            padding: 1rem 1.25rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            background: linear-gradient(135deg, #047857 0%, #059669 100%);
        }

        .table-header h5 {
            margin: 0;
            color: #fff;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .table thead th {
            background: #047857 !important;
            color: #fff !important;
            font-size: 0.78rem;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            border: none;
            white-space: nowrap;
        }

        .table tbody td {
            vertical-align: middle;
            color: #334155;
            font-size: 0.875rem;
        }

        .profit-positive {
            color: #047857 !important;
            font-weight: 700;
        }

        .profit-negative {
            color: #dc2626 !important;
            font-weight: 700;
        }

        .subtotal-row {
            background: rgba(68, 211, 78, 0.08) !important;
            font-weight: 700;
        }

        .subtotal-row td {
            border-top: 2px solid rgba(68, 211, 78, 0.3);
        }

        .grand-total-row {
            background: linear-gradient(135deg, rgba(4, 120, 87, 0.15), rgba(68, 211, 78, 0.2)) !important;
            font-weight: 800;
        }

        .grand-total-row td {
            border-top: 3px solid #047857;
            color: #052A47;
        }

        .section-toggle {
            cursor: pointer;
            user-select: none;
        }

        .section-toggle:hover {
            background: rgba(68, 211, 78, 0.05);
        }

        .section-toggle i.bi-chevron-down {
            transition: transform 0.2s ease;
        }

        .section-toggle.collapsed i.bi-chevron-down {
            transform: rotate(-90deg);
        }

        .child-row {
            background: #fafbfc;
        }

        .child-row td:first-child {
            padding-left: 2rem;
        }

        .agent-row td:first-child {
            padding-left: 3.5rem;
        }

        .badge-bu {
            background: linear-gradient(135deg, #047857, #059669);
            color: #fff;
            padding: 0.35rem 0.65rem;
            border-radius: 999px;
            font-size: 0.72rem;
            font-weight: 600;
        }

        /* RESPONSIVE */
        @media (max-width: 991px) {
            .main-content {
                padding: 1rem;
            }
            .chart-body {
                min-height: 250px;
            }
        }

        @media (max-width: 575px) {
            .navbar-top {
                flex-direction: column;
                text-align: center;
            }

            .user-info {
                flex-direction: column;
            }
        }
        /* ===== RESPONSIVE FILTER PANEL - SAME STYLE ON DESKTOP, TABLET, AND MOBILE ===== */
        .filter-open-card {
            display: block;
            width: 100%;
            background: #fff;
            border: 1px solid rgba(4, 120, 87, 0.12);
            border-radius: 18px;
            box-shadow: 0 8px 20px -8px rgba(4, 120, 87, 0.18);
            padding: 0.9rem 1rem;
            margin-bottom: 1rem;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .filter-open-card:hover {
            border-color: rgba(4, 120, 87, 0.25);
            box-shadow: 0 10px 24px -10px rgba(4, 120, 87, 0.25);
        }

        .filter-open-card .filter-summary {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
        }

        .filter-open-card .filter-summary-text {
            min-width: 0;
        }

        .filter-open-card .filter-summary-title {
            display: flex;
            align-items: center;
            gap: 0.45rem;
            font-weight: 800;
            color: #052A47;
            font-size: 0.95rem;
        }

        .filter-open-card .filter-summary-subtitle {
            color: #64748b;
            font-size: 0.78rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 100%;
        }

        .filter-count-badge {
            min-width: 20px;
            height: 20px;
            padding: 0 0.35rem;
            border-radius: 999px;
            background: #047857;
            color: #fff;
            font-size: 0.7rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
        }

        .filter-open-arrow {
            width: 38px;
            height: 38px;
            border-radius: 12px;
            background: rgba(4, 120, 87, 0.08);
            color: #047857;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .filter-panel.offcanvas.offcanvas-bottom {
            position: fixed;
            left: 0;
            right: 0;
            bottom: 0;
            top: auto;
            width: 100vw;
            max-width: 100vw;
            height: 92vh !important;
            transform: translateY(100%);
            background: #fff;
            border: none;
            border-radius: 26px 26px 0 0;
            box-shadow: 0 -10px 35px rgba(15, 23, 42, 0.18);
            padding: 0;
            margin: 0;
            overflow: hidden;
        }

        .filter-panel.offcanvas.show {
            transform: translateY(0) !important;
        }

        .filter-panel .offcanvas-body {
            padding: 0;
            display: flex;
            flex-direction: column;
            height: 100%;
            overflow: hidden;
            min-height: 0;
        }

        .filter-panel form {
            display: flex;
            flex-direction: column;
            flex: 1 1 auto;
            height: 100%;
            min-height: 0;
            overflow: hidden;
        }

        .filter-mobile-header {
            display: grid;
            grid-template-columns: 48px 1fr 72px;
            align-items: center;
            min-height: 58px;
            padding: 0 0.8rem;
            border-bottom: 1px solid #e5e7eb;
            flex-shrink: 0;
        }

        .filter-mobile-title {
            text-align: center;
            font-weight: 900;
            color: #0f172a;
        }

        .filter-mobile-reset {
            text-align: right;
            color: #64748b;
            text-decoration: none;
            font-size: 0.86rem;
            font-weight: 700;
        }

        .filter-close-btn {
            width: 38px;
            height: 38px;
            border: none;
            background: transparent;
            color: #0f172a;
            font-size: 1.25rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .filter-panel-title {
            display: none;
        }

        .filter-scroll-area {
            overflow-y: auto;
            overflow-x: hidden;
            -webkit-overflow-scrolling: touch;
            overscroll-behavior: contain;
            touch-action: pan-y;
            padding: 0 0 1rem 0;
            flex: 1 1 auto;
            min-height: 0;
            height: auto;
        }

        .filter-section {
            padding: 1rem 0.9rem;
            border-bottom: 1px solid #e2e8f0;
        }

        .filter-section:last-of-type {
            border-bottom: none;
        }

        .filter-section-title {
            font-size: 0.82rem;
            font-weight: 800;
            color: #475569;
            margin-bottom: 0.75rem;
            letter-spacing: 0.01em;
        }

        .period-buttons-group {
            display: grid;
            grid-template-columns: repeat(5, minmax(0, 1fr));
            gap: 0.5rem;
            width: 100%;
            overflow: visible;
        }

        .period-buttons-group::-webkit-scrollbar {
            display: none;
        }

        .period-btn {
            min-width: 0;
            width: 100%;
            min-height: 64px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 0.35rem;
            padding: 0.6rem 0.35rem;
            border: 1.5px solid #cbd5e1;
            background: #fff;
            border-radius: 10px;
            font-weight: 800;
            font-size: 0.76rem;
            color: #0f172a;
            transition: all 0.2s ease;
            cursor: pointer;
            text-align: center;
            white-space: nowrap;
        }

        .period-btn i {
            font-size: 1rem;
            color: #334155;
        }

        .period-btn span {
            display: block;
            font-size: 0.72rem;
            line-height: 1;
            white-space: nowrap;
        }

        .period-btn.active {
            border-color: #047857;
            color: #047857;
            background: rgba(4, 120, 87, 0.06);
            box-shadow: 0 8px 16px -12px rgba(4, 120, 87, 0.55);
        }

        .period-btn.active i {
            color: #047857;
        }

        .period-btn:hover:not(.active) {
            border-color: #94a3b8;
            background: #f8fafc;
        }

        .date-field-group {
            background: transparent;
            padding: 0;
            border-radius: 0;
            margin-bottom: 0;
            border: none;
        }

        .date-field-group .form-label,
        .filter-label {
            font-size: 0.82rem;
            margin-bottom: 0.45rem;
            color: #0f172a;
            font-weight: 700;
        }

        .filter-help-text {
            color: #64748b;
            font-size: 0.75rem;
        }

        .filter-panel .form-select,
        .filter-panel .form-control {
            border-radius: 12px;
            border: 1px solid #cbd5e1;
            transition: all 0.2s ease;
            min-height: 44px;
        }

        .filter-panel .form-select:focus,
        .filter-panel .form-control:focus {
            border-color: #047857;
            box-shadow: 0 0 0 3px rgba(4, 120, 87, 0.1);
        }

        .filter-panel .input-group-text {
            background: #f8fafc;
            border: 1px solid #cbd5e1;
            border-right: none;
            color: #64748b;
            border-radius: 12px 0 0 12px;
        }

        .filter-panel .input-group .form-control {
            border-left: none;
            border-radius: 0 12px 12px 0;
        }

        .active-filters {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            padding: 0.75rem;
            border-radius: 14px;
            margin-top: 0.75rem;
        }

        .filters-label {
            font-size: 0.72rem;
            font-weight: 800;
            color: #047857;
            margin-bottom: 0.5rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .filter-chips {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        .filter-chip {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            background: #fff;
            padding: 0.42rem 0.65rem;
            border-radius: 30px;
            font-size: 0.72rem;
            font-weight: 700;
            color: #1e293b;
            border: 1px solid #e2e8f0;
        }

        .filter-chip i:first-child {
            color: #047857;
            font-size: 0.72rem;
        }

        .remove-filter {
            background: none;
            border: none;
            padding: 0;
            margin: 0;
            color: #ef4444;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
        }

        .filter-actions {
            position: relative;
            flex: 0 0 auto;
            left: auto;
            right: auto;
            bottom: auto;
            margin: 0;
            padding: 0.75rem 0.9rem calc(0.75rem + env(safe-area-inset-bottom));
            background: #fff;
            border-top: 1px solid #e5e7eb;
            box-shadow: 0 -8px 18px rgba(15, 23, 42, 0.08);
            z-index: 5;
        }

        .filter-actions .row {
            display: flex;
            flex-direction: row-reverse;
        }

        .filter-actions .col-6:first-child,
        .filter-actions .col-6:last-child {
            width: 50%;
        }

        .btn-amgc-primary.filter-submit-btn,
        .btn-filter-reset {
            min-height: 48px;
            border-radius: 14px;
            font-weight: 800;
        }

        .btn-filter-reset {
            display: flex;
            align-items: center;
            justify-content: center;
            border: 1px solid #cbd5e1;
            color: #475569;
            background: #fff;
        }

        @media (min-width: 768px) {
            .filter-open-card {
                max-width: 100%;
                margin-left: 0;
                margin-right: 0;
            }

            .filter-panel.offcanvas.offcanvas-bottom {
                width: min(980px, calc(100vw - 48px));
                max-width: calc(100vw - 48px);
                height: 88vh !important;
                left: 50%;
                right: auto;
                transform: translate(-50%, 100%);
                border-radius: 26px 26px 0 0;
            }

            .filter-panel.offcanvas.show {
                transform: translate(-50%, 0) !important;
            }

            .filter-scroll-area,
            .filter-actions,
            .filter-mobile-header {
                max-width: none;
                margin-left: 0;
                margin-right: 0;
                width: 100%;
            }
        }

        @media (max-width: 420px) {
            .filter-open-card {
                padding: 0.75rem;
            }

            .filter-open-card .filter-summary-title {
                font-size: 0.9rem;
            }

            .filter-open-card .filter-summary-subtitle {
                max-width: 190px;
                font-size: 0.74rem;
            }

            .period-buttons-group {
                gap: 0.28rem;
            }

            .period-btn {
                min-width: 0;
                min-height: 56px;
                padding: 0.45rem 0.16rem;
                font-size: 0.62rem;
                border-radius: 8px;
            }

            .period-btn i {
                font-size: 0.82rem;
            }

            .period-btn span {
                font-size: 0.56rem;
            }
        }
                /* ===== NAVBAR STYLES - MOBILE FIRST (CLEAN VERSION) ===== */
        .navbar-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1.5rem;
            background: #fff;
            padding: 1rem;
            border-radius: 20px;
            box-shadow: 0 8px 20px -5px rgba(4, 120, 87, 0.12);
            border: 1px solid rgba(68, 211, 78, 0.18);
        }

        /* Left Section - Title, Subtitle, Date pill (stacked vertically) */
        .page-title-section {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            text-align: left;
        }

        .page-title {
            text-align: left;
        }

        .page-title h2 {
            margin: 0;
            color: var(--dark-green);
            font-size: 1.2rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            justify-content: flex-start;
        }

        .page-title h2 i {
            font-size: 1.3rem;
        }

        .page-title .title-text {
            font-weight: 700;
        }

        .page-title .title-subtitle {
            margin: 0;
            margin-top: 0.2rem;
            color: #64748b;
            font-size: 0.7rem;
            text-align: left;
        }

        /* Period Pill - below the title */
        .period-pill {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.35rem 0.8rem;
            border-radius: 999px;
            background: rgba(4, 120, 87, 0.1);
            color: #047857;
            font-size: 0.75rem;
            font-weight: 600;
            width: fit-content;
        }

        .period-pill i {
            font-size: 0.75rem;
        }

        .period-text {
            white-space: nowrap;
        }

        /* Right Section - User Avatar only */
        .user-info {
            position: relative;
            display: flex;
            align-items: center;
            flex-shrink: 0;
            cursor: pointer;
        }

        .user-avatar {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            background: linear-gradient(135deg, #047857, #059669);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 1rem;
            cursor: pointer;
            transition: all 0.2s ease;
            box-shadow: 0 2px 8px rgba(4, 120, 87, 0.2);
        }

        .user-avatar:hover {
            transform: scale(1.05);
            box-shadow: 0 4px 12px rgba(4, 120, 87, 0.3);
        }

        /* Hide user name - only show avatar */
        .user-name {
            display: none;
        }

        /* User Dropdown Menu */
        .user-dropdown-menu {
            position: absolute;
            top: 100%;
            right: 0;
            z-index: 1000;
            display: none;
            min-width: 220px;
            padding: 0.5rem 0;
            border: none;
            border-radius: 16px;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1), 0 8px 10px -6px rgba(0, 0, 0, 0.02);
            margin-top: 0.5rem;
            background: #fff;
        }

        .user-dropdown-menu.show {
            display: block;
        }

        .dropdown-header {
            padding: 0.75rem 1rem;
            text-align: center;
            border-bottom: 1px solid #e5e7eb;
        }

        .dropdown-header strong {
            display: block;
            color: #0f172a;
            font-size: 0.9rem;
            margin-bottom: 0.25rem;
        }

        .dropdown-header small {
            color: #64748b;
            font-size: 0.7rem;
        }

        .dropdown-item {
            padding: 0.6rem 1rem;
            font-size: 0.85rem;
            color: #334155;
            transition: all 0.2s ease;
            cursor: pointer;
            background: none;
            border: none;
            width: 100%;
            text-align: left;
        }

        .dropdown-item:hover {
            background: rgba(4, 120, 87, 0.08);
            color: #047857;
        }

        .dropdown-item i {
            width: 1.25rem;
        }

        /* ===== RESPONSIVE - NO BREAKING OF LAYOUT ===== */
        
        /* Tablet - just increase sizes, keep horizontal layout */
        @media (min-width: 768px) {
            .navbar-top {
                padding: 1.25rem 1.5rem;
            }
            
            .page-title h2 {
                font-size: 1.4rem;
            }
            
            .page-title h2 i {
                font-size: 1.5rem;
            }
            
            .page-title .title-subtitle {
                font-size: 0.8rem;
            }
            
            .period-pill {
                padding: 0.4rem 1rem;
                font-size: 0.8rem;
            }
            
            .period-pill i {
                font-size: 0.85rem;
            }
            
            .user-avatar {
                width: 48px;
                height: 48px;
                font-size: 1.1rem;
            }
        }

        /* Desktop */
        @media (min-width: 992px) {
            .page-title h2 {
                font-size: 1.5rem;
            }
            
            .user-avatar {
                width: 52px;
                height: 52px;
                font-size: 1.2rem;
            }
        }
        
        /* Mobile - KEEP HORIZONTAL LAYOUT, just scale down */
        @media (max-width: 480px) {
            .navbar-top {
                padding: 0.75rem;
            }
            
            .page-title h2 {
                font-size: 0.95rem;
            }
            
            .page-title h2 i {
                font-size: 1rem;
            }
            
            .page-title .title-subtitle {
                font-size: 0.6rem;
            }
            
            .period-pill {
                padding: 0.25rem 0.6rem;
                font-size: 0.65rem;
            }
            
            .period-pill i {
                font-size: 0.65rem;
            }
            
            .user-avatar {
                width: 36px;
                height: 36px;
                font-size: 0.85rem;
            }
        }
        
        /* Very small - hide subtitle to save space */
        @media (max-width: 380px) {
            .page-title .title-subtitle {
                display: none;
            }
            
            .page-title h2 {
                font-size: 0.85rem;
            }
            
            .period-pill {
                padding: 0.2rem 0.5rem;
                font-size: 0.6rem;
            }
            
            .user-avatar {
                width: 32px;
                height: 32px;
                font-size: 0.75rem;
            }
        }


        /* ===== MOBILE NAVBAR MATCHED TO REFERENCE ===== */
        @media (max-width: 575px) {
            .main-content {
                padding: 0.75rem;
            }

            .navbar-top {
                position: relative;
                display: flex !important;
                flex-direction: row !important;
                align-items: flex-start !important;
                justify-content: space-between !important;
                gap: 0.75rem;
                min-height: 172px;
                padding: 1.35rem 1.05rem !important;
                margin-bottom: 1rem;
                border-radius: 28px !important;
                background: #fff;
                border: 1px solid rgba(68, 211, 78, 0.25);
                box-shadow: 0 8px 22px -12px rgba(4, 120, 87, 0.35);
                text-align: left !important;
            }

            .page-title-section {
                flex: 1 1 auto;
                min-width: 0;
                display: flex;
                flex-direction: column;
                align-items: flex-start;
                gap: 0.75rem;
                padding-right: 0.5rem;
            }

            .page-title h2 {
                font-size: 1.12rem !important;
                line-height: 1.2;
                gap: 0.55rem;
                color: #047857;
                white-space: nowrap;
            }

            .page-title h2 i {
                font-size: 1.15rem !important;
                flex-shrink: 0;
            }

            .page-title .title-text {
                overflow: hidden;
                text-overflow: ellipsis;
            }

            .page-title .title-subtitle {
                display: block !important;
                margin-top: 0.5rem;
                color: #64748b;
                font-size: 0.76rem !important;
                line-height: 1.25;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
                max-width: calc(100vw - 120px);
            }

            .navbar-top .period-pill {
                margin-top: 0.35rem;
                padding: 0.5rem 0.85rem !important;
                border-radius: 999px;
                background: rgba(4, 120, 87, 0.08);
                color: #047857;
                font-size: 0.82rem !important;
                font-weight: 800;
                width: fit-content;
            }

            .navbar-top .period-pill i {
                font-size: 0.82rem !important;
            }

            .navbar-top .user-info {
                flex: 0 0 auto;
                align-self: flex-start;
                display: flex !important;
                flex-direction: row !important;
                align-items: center;
            }

            .navbar-top .user-avatar {
                width: 52px !important;
                height: 52px !important;
                font-size: 1.05rem !important;
                border-radius: 50%;
                background: linear-gradient(135deg, #047857, #059669);
                color: #fff;
                box-shadow: 0 4px 12px rgba(4, 120, 87, 0.22);
            }
        }

        @media (max-width: 380px) {
            .navbar-top {
                min-height: 100px;
                padding: 1.15rem 0.9rem !important;
            }

            .page-title h2 {
                font-size: 1rem !important;
            }

            .page-title .title-subtitle {
                font-size: 0.68rem !important;
                max-width: calc(100vw - 108px);
            }

            .navbar-top .period-pill {
                font-size: 0.75rem !important;
                padding: 0.42rem 0.75rem !important;
            }

            .navbar-top .user-avatar {
                width: 46px !important;
                height: 46px !important;
                font-size: 0.95rem !important;
            }
        }


        /* ===== QUICKBOOKS-LIKE REPORT GENERATOR ===== */
        .qb-toolbar {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 18px;
            margin-bottom: 1rem;
            overflow-x: auto;
            scrollbar-width: thin;
        }

        .qb-toolbar .btn {
            white-space: nowrap;
            border-radius: 10px;
            font-weight: 700;
            min-height: 40px;
        }

        .qb-modify-card,
        .qb-report-card {
            background: #fff;
            border: 1px solid rgba(68, 211, 78, 0.18);
            border-radius: 20px;
            box-shadow: 0 8px 20px -8px rgba(4, 120, 87, 0.16);
            margin-bottom: 1.25rem;
            overflow: hidden;
        }

        .qb-modify-header,
        .qb-report-titlebar {
            padding: 1rem 1.25rem;
            background: linear-gradient(135deg, #047857 0%, #059669 100%);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
        }

        .qb-modify-header h5,
        .qb-report-titlebar h5 {
            margin: 0;
            font-size: 1rem;
            font-weight: 800;
        }

        .qb-modify-body {
            padding: 1rem;
        }

        .qb-report-tabs {
            display: flex;
            gap: 0.5rem;
            padding: 0.75rem;
            border-bottom: 1px solid #e2e8f0;
            overflow-x: auto;
            background: #f8fafc;
        }

        .qb-report-tab {
            border: 1px solid #cbd5e1;
            background: #fff;
            color: #334155;
            border-radius: 999px;
            padding: 0.48rem 0.85rem;
            font-size: 0.8rem;
            font-weight: 800;
            white-space: nowrap;
        }

        .qb-report-tab.active,
        .qb-report-tab:hover {
            border-color: #047857;
            color: #047857;
            background: rgba(4, 120, 87, 0.08);
        }

        .qb-report-header-print {
            padding: 1.25rem 1.25rem 0.75rem;
            text-align: center;
            border-bottom: 1px solid #e2e8f0;
        }

        .qb-company-name {
            font-weight: 900;
            font-size: 1.15rem;
            color: #0f172a;
            text-transform: uppercase;
        }

        .qb-report-name {
            font-weight: 800;
            color: #334155;
            margin-top: 0.25rem;
        }

        .qb-report-meta {
            color: #64748b;
            font-size: 0.8rem;
            margin-top: 0.25rem;
        }

        .qb-report-body {
            padding: 1rem 1.25rem 1.25rem;
        }

        .qb-summary-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 0.75rem;
            margin-bottom: 1rem;
        }

        .qb-summary-box {
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            padding: 0.85rem;
            background: #f8fafc;
        }

        .qb-summary-label {
            color: #64748b;
            font-size: 0.72rem;
            font-weight: 800;
            text-transform: uppercase;
        }

        .qb-summary-value {
            color: #052A47;
            font-size: 1.05rem;
            font-weight: 900;
            margin-top: 0.25rem;
        }

        .qb-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.86rem;
        }

        .qb-table th {
            background: #e5e7eb;
            color: #0f172a;
            padding: 0.55rem 0.7rem;
            border: 1px solid #d1d5db;
            font-weight: 900;
            white-space: nowrap;
        }

        .qb-table td {
            padding: 0.52rem 0.7rem;
            border: 1px solid #e5e7eb;
            color: #334155;
            vertical-align: middle;
        }

        .qb-table .qb-group-row td {
            background: #f1f5f9;
            color: #0f172a;
            font-weight: 900;
        }

        .qb-table .qb-total-row td {
            background: rgba(4, 120, 87, 0.08);
            color: #052A47;
            font-weight: 900;
            border-top: 2px solid #047857;
        }

        .qb-table .qb-grand-row td {
            background: #047857;
            color: #fff;
            font-weight: 900;
            border-color: #047857;
        }

        .qb-amount {
            text-align: right;
            font-variant-numeric: tabular-nums;
            white-space: nowrap;
        }

        .qb-muted {
            color: #64748b;
            font-size: 0.78rem;
        }

        .qb-modal .modal-content {
            border: 0;
            border-radius: 0;
            overflow: hidden;
        }

        .qb-modal .modal-header {
            background: #0f172a;
            color: #fff;
            padding: 0.65rem 1rem;
        }

        .qb-modal .modal-title {
            font-size: 0.95rem;
            font-weight: 800;
        }

        .qb-modal .nav-tabs .nav-link {
            color: #334155;
            font-weight: 800;
        }

        .qb-modal .nav-tabs .nav-link.active {
            color: #047857;
        }

        .qb-column-list {
            max-height: 190px;
            overflow-y: auto;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 0.75rem;
        }

        .report-hidden {
            display: none !important;
        }

        @media (max-width: 575px) {
            .qb-toolbar {
                gap: 0.4rem;
                padding: 0.6rem;
            }

            .qb-toolbar .btn {
                min-height: 38px;
                font-size: 0.74rem;
                padding: 0.4rem 0.55rem;
            }

            .qb-summary-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .qb-report-body {
                padding: 0.85rem;
            }

            .qb-table {
                font-size: 0.78rem;
            }
        }

        @media print {
            body {
                background: #fff !important;
            }
            .no-print,
            .filter-open-card,
            .filter-panel,
            .stat-card-row,
            .navbar-top,
            .qb-toolbar,
            .qb-report-tabs,
            .chart-card:not(.print-chart) {
                display: none !important;
            }
            .main-content {
                padding: 0 !important;
                max-width: none !important;
            }
            .qb-report-card,
            .data-table {
                box-shadow: none !important;
                border: none !important;
                border-radius: 0 !important;
                page-break-inside: avoid;
            }
            .qb-report-titlebar {
                display: none !important;
            }
            .qb-table th,
            .qb-table td {
                border-color: #999 !important;
            }
        }

    </style>
</head>
<body>
    <div class="main-content">
        <!-- HEADER -->
                <!-- HEADER - FULLY RESPONSIVE WITH LEFT/RIGHT LAYOUT -->
        <div class="navbar-top">
            <div class="page-title-section">
                <div class="page-title">
                    <h2><i class="bi bi-shield-lock-fill"></i> <span class="title-text">Super Duper Admin</span></h2>
                    <p class="title-subtitle">Comprehensive P&L and Volume Analysis</p>
                </div>
                <div class="period-pill">
                    <i class="bi bi-calendar3"></i> 
                    <span class="period-text"><?php echo htmlspecialchars($period_label); ?></span>
                </div>
            </div>
            <div class="user-info">
                <div class="user-avatar" id="userAvatarBtn" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <?php echo htmlspecialchars($user_initials); ?>
                </div>
                <div class="user-name"><?php echo htmlspecialchars($user_name); ?></div>
                <div class="user-dropdown-menu dropdown-menu dropdown-menu-end">
                    <div class="dropdown-header">
                        <strong><?php echo htmlspecialchars($user_name); ?></strong>
                        <small><?php echo htmlspecialchars($user_role); ?></small>
                    </div>
                    <div class="dropdown-divider"></div>
                    <button class="dropdown-item" onclick="logout()">
                        <i class="bi bi-box-arrow-right me-2"></i> Logout
                    </button>
                </div>
            </div>
        </div>

        <!-- SUMMARY STAT CARDS -->
<div class="row stat-card-row g-1 g-sm-2 mb-4 no-print">
    <!-- Stat 1: Sales (Delivered) -->
    <div class="col">
        <div class="stat-card sales">
            <i class="bi bi-cash-stack stat-icon"></i>
            <div class="stat-content">
                <div class="stat-value"><?php echo money($sales); ?></div>
                <div class="stat-label">Sales (Delivered)</div>
                <small><?php echo number_format($total_orders); ?> order/s delivered</small>
            </div>
        </div>
    </div>

    <!-- Stat 2: COGS -->
    <div class="col">
        <div class="stat-card cogs">
            <i class="bi bi-box-seam stat-icon"></i>
            <div class="stat-content">
                <div class="stat-value"><?php echo money($cogs); ?></div>
                <div class="stat-label">COGS</div>
                <small>Cost of Goods Sold</small>
            </div>
        </div>
    </div>

    <!-- Stat 3: Gross Profit -->
    <div class="col">
        <div class="stat-card gross">
            <i class="bi bi-graph-up-arrow stat-icon"></i>
            <div class="stat-content">
                <div class="stat-value"><?php echo money($gross_profit); ?></div>
                <div class="stat-label">Gross Profit</div>
                <small>Sales - COGS</small>
            </div>
        </div>
    </div>

    <!-- Stat 4: Expenses -->
    <div class="col">
        <div class="stat-card expenses">
            <i class="bi bi-receipt stat-icon"></i>
            <div class="stat-content">
                <div class="stat-value"><?php echo money($expenses); ?></div>
                <div class="stat-label">Expenses</div>
                <small>Total Expenses</small>
            </div>
        </div>
    </div>

    <!-- Stat 5: Net Profit -->
    <div class="col">
        <div class="stat-card <?php echo $net_profit >= 0 ? 'net-profit' : 'net-loss'; ?>">
            <i class="bi bi-wallet2 stat-icon"></i>
            <div class="stat-content">
                <div class="stat-value"><?php echo money($net_profit); ?></div>
                <div class="stat-label">Net Profit</div>
                <small>Gross Profit - Expenses</small>
            </div>
        </div>
    </div>
</div>
        <?php
        $active_filter_count = 0;
        if ($active_period !== 'monthly') $active_filter_count++;
        if ($selected_business_unit !== '') $active_filter_count++;
        if ($selected_branch_filter_id > 0) $active_filter_count++;
        ?>

        <!-- FILTER TRIGGER -->
        <div class="filter-open-card" role="button" tabindex="0" data-bs-toggle="offcanvas" data-bs-target="#dashboardFilterPanel" aria-controls="dashboardFilterPanel">
            <div class="filter-summary">
                <div class="filter-summary-text">
                    <div class="filter-summary-title">
                        <i class="bi bi-sliders"></i>
                        Filters
                        <?php if ($active_filter_count > 0): ?>
                            <span class="filter-count-badge"><?php echo (int)$active_filter_count; ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="filter-summary-subtitle"><?php echo htmlspecialchars($period_label); ?></div>
                </div>
                <span class="filter-open-arrow"><i class="bi bi-chevron-up"></i></span>
            </div>
        </div>

        <!-- FILTER PANEL -->
        <div class="filter-panel offcanvas offcanvas-bottom" tabindex="-1" id="dashboardFilterPanel" aria-labelledby="dashboardFilterPanelLabel">
            <div class="offcanvas-body">
                <div class="filter-mobile-header">
                    <button type="button" class="filter-close-btn" data-bs-dismiss="offcanvas" aria-label="Close">
                        <i class="bi bi-x-lg"></i>
                    </button>
                    <div class="filter-mobile-title" id="dashboardFilterPanelLabel">
                        Filters
                        <?php if ($active_filter_count > 0): ?>
                            <span class="filter-count-badge"><?php echo (int)$active_filter_count; ?></span>
                        <?php endif; ?>
                    </div>
                    <a href="?period=monthly" class="filter-mobile-reset">Reset</a>
                </div>

                <div class="filter-panel-title">
                    <i class="bi bi-funnel"></i> Filter Dashboard
                </div>

                <form method="GET" id="filterForm">
                    <input type="hidden" name="period" id="periodInput" value="<?php echo htmlspecialchars($active_period); ?>">

                    <div class="filter-scroll-area">
                        <div class="filter-section">
                            <div class="filter-section-title">Period</div>
                            <div class="period-buttons-group">
                                <button type="button" class="period-btn <?php echo $active_period === 'daily' ? 'active' : ''; ?>" data-period="daily">
                                    <i class="bi bi-calendar-day"></i>
                                    <span>Daily</span>
                                </button>
                                <button type="button" class="period-btn <?php echo $active_period === 'weekly' ? 'active' : ''; ?>" data-period="weekly">
                                    <i class="bi bi-calendar-week"></i>
                                    <span>Weekly</span>
                                </button>
                                <button type="button" class="period-btn <?php echo $active_period === 'monthly' ? 'active' : ''; ?>" data-period="monthly">
                                    <i class="bi bi-calendar-month"></i>
                                    <span>Monthly</span>
                                </button>
                                <button type="button" class="period-btn <?php echo $active_period === 'yearly' ? 'active' : ''; ?>" data-period="yearly">
                                    <i class="bi bi-calendar4"></i>
                                    <span>Yearly</span>
                                </button>
                                <button type="button" class="period-btn <?php echo $active_period === 'custom' ? 'active' : ''; ?>" data-period="custom">
                                    <i class="bi bi-calendar-range"></i>
                                    <span>Custom</span>
                                </button>
                            </div>
                        </div>

                        <div class="filter-section" id="dateFieldsContainer">
                            <div id="singleDateContainer" class="date-field-group <?php echo $active_period === 'custom' ? 'd-none' : ''; ?>">
                                <label class="form-label">
                                    <i class="bi bi-calendar3 me-1"></i>
                                    <?php
                                    if ($active_period === 'daily') echo 'Select Date';
                                    elseif ($active_period === 'weekly') echo 'Week of';
                                    elseif ($active_period === 'yearly') echo 'Select Year';
                                    else echo 'Select Month';
                                    ?>
                                </label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-calendar"></i></span>
                                    <input type="date" name="date" class="form-control" value="<?php echo htmlspecialchars($selected_date); ?>">
                                </div>
                                <small class="filter-help-text d-block mt-1">
                                    <?php
                                    if ($active_period === 'daily') echo 'Choose a specific day';
                                    elseif ($active_period === 'weekly') echo 'Pick any day in the week';
                                    elseif ($active_period === 'yearly') echo 'Pick any day in the year';
                                    else echo 'Select a date within the month';
                                    ?>
                                </small>
                            </div>

                            <div id="customDateContainer" class="date-field-group <?php echo $active_period !== 'custom' ? 'd-none' : ''; ?>">
                                <div class="filter-section-title mb-2">Date Range</div>
                                <div class="row g-2">
                                    <div class="col-6">
                                        <label class="filter-label">From</label>
                                        <div class="input-group">
                                            <span class="input-group-text"><i class="bi bi-calendar-start"></i></span>
                                            <input type="date" name="start_date" class="form-control" value="<?php echo htmlspecialchars($custom_start_date); ?>">
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <label class="filter-label">To</label>
                                        <div class="input-group">
                                            <span class="input-group-text"><i class="bi bi-calendar-end"></i></span>
                                            <input type="date" name="end_date" class="form-control" value="<?php echo htmlspecialchars($custom_end_date); ?>">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="filter-section">
                            <div class="filter-section-title">Business filters</div>
                            <div class="row g-2">
                                <div class="col-12 col-md-6">
                                    <label class="filter-label">
                                        <i class="bi bi-building me-1"></i> Business Unit
                                    </label>
                                    <select name="business_unit" class="form-select" id="businessUnitSelect">
                                        <option value="">All Business Units</option>
                                        <?php foreach ($business_units as $bu): ?>
                                            <option value="<?php echo htmlspecialchars($bu); ?>" <?php echo $selected_business_unit === $bu ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($bu); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-12 col-md-6">
                                    <label class="filter-label">
                                        <i class="bi bi-shop me-1"></i> Branch
                                    </label>
                                    <select name="branch_id" class="form-select" id="branchSelect">
                                        <option value="0">All Branches</option>
                                        <?php foreach ($branch_options as $branch_option): ?>
                                            <option value="<?php echo (int)$branch_option['branch_id']; ?>" <?php echo $selected_branch_filter_id === (int)$branch_option['branch_id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($branch_option['branch_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <?php if ($selected_business_unit !== '' || $selected_branch_filter_id > 0 || $active_period !== 'monthly'): ?>
                            <div class="filter-section">
                                <div class="active-filters mt-0">
                                    <div class="filters-label">Active Filters</div>
                                    <div class="filter-chips">
                                        <?php if ($active_period !== 'monthly'): ?>
                                            <span class="filter-chip">
                                                <i class="bi bi-calendar"></i>
                                                <?php
                                                if ($active_period === 'daily') echo date('M d, Y', strtotime($selected_date));
                                                elseif ($active_period === 'weekly') echo date('M d', strtotime($start_date)) . ' - ' . date('M d, Y', strtotime($end_date));
                                                elseif ($active_period === 'monthly') echo date('F Y', strtotime($selected_date));
                                                elseif ($active_period === 'yearly') echo date('Y', strtotime($selected_date));
                                                else echo date('M d, Y', strtotime($custom_start_date)) . ' - ' . date('M d, Y', strtotime($custom_end_date));
                                                ?>
                                                <button type="button" class="remove-filter" data-filter="period">
                                                    <i class="bi bi-x-circle"></i>
                                                </button>
                                            </span>
                                        <?php endif; ?>

                                        <?php if ($selected_business_unit !== ''): ?>
                                            <span class="filter-chip">
                                                <i class="bi bi-building"></i>
                                                <?php echo htmlspecialchars($selected_business_unit); ?>
                                                <button type="button" class="remove-filter" data-filter="business_unit">
                                                    <i class="bi bi-x-circle"></i>
                                                </button>
                                            </span>
                                        <?php endif; ?>

                                        <?php if ($selected_branch_filter_id > 0): ?>
                                            <span class="filter-chip">
                                                <i class="bi bi-shop"></i>
                                                <?php
                                                $branch_name = '';
                                                foreach ($branch_options as $bo) {
                                                    if ((int)$bo['branch_id'] === $selected_branch_filter_id) {
                                                        $branch_name = $bo['branch_name'];
                                                        break;
                                                    }
                                                }
                                                echo htmlspecialchars($branch_name);
                                                ?>
                                                <button type="button" class="remove-filter" data-filter="branch_id">
                                                    <i class="bi bi-x-circle"></i>
                                                </button>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="filter-actions">
                        <div class="row g-2">
                            <div class="col-6">
                                <button type="submit" class="btn btn-amgc-primary filter-submit-btn w-100">
                                    <i class="bi bi-check2-circle"></i> Apply
                                </button>
                            </div>
                            <div class="col-6">
                                <a href="?period=monthly" class="btn btn-outline-secondary btn-filter-reset w-100" id="resetFiltersBtn">
                                    Reset
                                </a>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>



        <!-- QUICKBOOKS-LIKE REPORT GENERATOR TOOLBAR -->
        <div class="qb-toolbar no-print">
            <button type="button" class="btn btn-light border" data-bs-toggle="modal" data-bs-target="#modifyReportModal">
                <i class="bi bi-sliders"></i> Modify Report
            </button>
            <button type="button" class="btn btn-light border" data-open-qb-view="cogs">
                <i class="bi bi-box-seam"></i> COGS Details
            </button>
            <button type="button" class="btn btn-light border" onclick="window.print()">
                <i class="bi bi-printer"></i> Print
            </button>
            <button type="button" class="btn btn-light border" id="exportQuickbooksCsvBtn">
                <i class="bi bi-file-earmark-excel"></i> Excel / CSV
            </button>
            <button type="button" class="btn btn-light border" id="emailReportBtn">
                <i class="bi bi-envelope"></i> E-mail
            </button>
            <button type="button" class="btn btn-light border" id="toggleReportHeaderBtn">
                <i class="bi bi-layout-text-window"></i> Hide Header
            </button>
            <button type="button" class="btn btn-amgc-primary" onclick="window.location.reload()">
                <i class="bi bi-arrow-clockwise"></i> Refresh
            </button>
        </div>

        <!-- QUICKBOOKS-LIKE REPORT VIEW SWITCHER -->
        <div class="qb-report-card no-print">
            <div class="qb-report-titlebar">
                <h5><i class="bi bi-journal-text me-2"></i> Report Center</h5>
                <span class="qb-muted" style="color:#e2e8f0;">Choose a report view like QuickBooks.</span>
            </div>
            <div class="qb-report-tabs">
                <button type="button" class="qb-report-tab active" data-qb-view="all">All Reports</button>
                <button type="button" class="qb-report-tab" data-qb-view="summary">Profit &amp; Loss</button>
                <button type="button" class="qb-report-tab" data-qb-view="detail">Transaction Detail</button>
                <button type="button" class="qb-report-tab" data-qb-view="cogs">COGS Details</button>
                <button type="button" class="qb-report-tab" data-qb-view="graphs">Graphs</button>
                <button type="button" class="qb-report-tab" data-qb-view="volume">Volume</button>
            </div>
        </div>

        <!-- QUICKBOOKS-LIKE PROFIT AND LOSS REPORT -->
        <div class="qb-report-card qb-section summary-report" id="profitLossReport">
            <div class="qb-report-titlebar no-print">
                <h5><i class="bi bi-currency-exchange me-2"></i> Profit and Loss</h5>
                <span class="qb-muted" style="color:#e2e8f0;"><?php echo htmlspecialchars($period_label); ?></span>
            </div>
            <div class="qb-report-header-print" id="qbPrintableHeader">
                <div class="qb-company-name">Profit and Loss</div>
                <div class="qb-report-meta"><?php echo date('m/d/Y', strtotime($start_date)); ?> to <?php echo date('m/d/Y', strtotime($end_date)); ?> | Basis: <?php echo ucfirst(htmlspecialchars($report_basis)); ?></div>
                <div class="qb-report-meta">Generated: <?php echo htmlspecialchars($report_generated_at); ?></div>
            </div>
            <div class="qb-report-body">
                <div class="qb-summary-grid">
                    <div class="qb-summary-box">
                        <div class="qb-summary-label">Gross Margin</div>
                        <div class="qb-summary-value"><?php echo number_format($gross_margin_percent, 2); ?>%</div>
                    </div>
                    <div class="qb-summary-box">
                        <div class="qb-summary-label">Expense Ratio</div>
                        <div class="qb-summary-value"><?php echo number_format($expense_ratio_percent, 2); ?>%</div>
                    </div>
                    <div class="qb-summary-box">
                        <div class="qb-summary-label">Net Margin</div>
                        <div class="qb-summary-value"><?php echo number_format($net_margin_percent, 2); ?>%</div>
                    </div>
                    <div class="qb-summary-box">
                        <div class="qb-summary-label">Delivered Orders</div>
                        <div class="qb-summary-value"><?php echo number_format($total_orders); ?></div>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="qb-table" id="quickbooksPnlTable">
                        <tbody>
                            <tr class="qb-group-row"><td colspan="2">Income</td></tr>
                            <tr><td>Sales from Delivered Orders</td><td class="qb-amount"><?php echo money($sales); ?></td></tr>
                            <tr class="qb-total-row"><td>Total Income</td><td class="qb-amount"><?php echo money($sales); ?></td></tr>
                            <tr class="qb-group-row"><td colspan="2">Cost of Goods Sold</td></tr>
                            <tr>
                                <td>
                                    Cost of Goods Sold
                                    <button type="button" class="btn btn-sm btn-outline-success ms-2 no-print" data-open-qb-view="cogs">
                                        <i class="bi bi-search"></i> View Details
                                    </button>
                                </td>
                                <td class="qb-amount"><?php echo money($cogs); ?></td>
                            </tr>
                            <tr class="qb-total-row"><td>Total COGS</td><td class="qb-amount"><?php echo money($cogs); ?></td></tr>
                            <tr class="qb-total-row"><td>Gross Profit</td><td class="qb-amount <?php echo $gross_profit < 0 ? 'profit-negative' : 'profit-positive'; ?>"><?php echo money($gross_profit); ?></td></tr>
                            <tr class="qb-group-row"><td colspan="2">Expenses</td></tr>
                            <tr><td>Operating Expenses</td><td class="qb-amount"><?php echo money($expenses); ?></td></tr>
                            <tr class="qb-total-row"><td>Total Expenses</td><td class="qb-amount"><?php echo money($expenses); ?></td></tr>
                            <tr class="qb-grand-row"><td>Net Profit</td><td class="qb-amount"><?php echo money($net_profit); ?></td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="qb-muted mt-3">This report is generated from delivered sales orders, recorded COGS, and bank withdrawals with expense accounts.</div>
            </div>
        </div>

        <!-- QUICKBOOKS-LIKE TRANSACTION DETAIL BY ACCOUNT -->
        <div class="qb-report-card qb-section detail-report" id="transactionDetailReport">
            <div class="qb-report-titlebar no-print">
                <h5><i class="bi bi-list-columns-reverse me-2"></i> Transaction Detail by Account</h5>
                <span class="qb-muted" style="color:#e2e8f0;">Grouped by Business Unit and Branch</span>
            </div>
            <div class="qb-report-header-print">
                <div class="qb-company-name">Transaction Detail by Account</div>
                <div class="qb-report-meta"><?php echo date('m/d/Y', strtotime($start_date)); ?> to <?php echo date('m/d/Y', strtotime($end_date)); ?> | Total By: <?php echo ucwords(str_replace('_', ' ', htmlspecialchars($total_by))); ?></div>
            </div>
            <div class="qb-report-body">
                <div class="table-responsive">
                    <table class="qb-table" id="quickbooksDetailTable">
                        <thead>
                            <tr>
                                <th>Account / Group</th>
                                <th>Branch</th>
                                <th class="qb-amount">Sales</th>
                                <th class="qb-amount">COGS</th>
                                <th class="qb-amount">Gross Profit</th>
                                <th class="qb-amount">Expenses</th>
                                <th class="qb-amount">Net Profit</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($pnl_by_bu)): ?>
                                <tr><td colspan="7" class="text-center text-muted py-4">No transactions found for the selected report range.</td></tr>
                            <?php else: ?>
                                <?php foreach ($pnl_by_bu as $bu_name => $bu_data): ?>
                                    <tr class="qb-group-row">
                                        <td colspan="7"><?php echo htmlspecialchars($bu_name); ?></td>
                                    </tr>
                                    <?php foreach ($bu_data['branches'] as $branch): ?>
                                        <tr>
                                            <td>Delivered Sales / Expenses</td>
                                            <td><?php echo htmlspecialchars($branch['branch_name']); ?></td>
                                            <td class="qb-amount"><?php echo money($branch['sales']); ?></td>
                                            <td class="qb-amount"><?php echo money($branch['cogs']); ?></td>
                                            <td class="qb-amount <?php echo (float)$branch['gross_profit'] < 0 ? 'profit-negative' : 'profit-positive'; ?>"><?php echo money($branch['gross_profit']); ?></td>
                                            <td class="qb-amount"><?php echo money($branch['expenses']); ?></td>
                                            <td class="qb-amount <?php echo (float)$branch['net_profit'] < 0 ? 'profit-negative' : 'profit-positive'; ?>"><?php echo money($branch['net_profit']); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <tr class="qb-total-row">
                                        <td>Total <?php echo htmlspecialchars($bu_name); ?></td>
                                        <td></td>
                                        <td class="qb-amount"><?php echo money($bu_data['totals']['sales']); ?></td>
                                        <td class="qb-amount"><?php echo money($bu_data['totals']['cogs']); ?></td>
                                        <td class="qb-amount"><?php echo money($bu_data['totals']['gross_profit']); ?></td>
                                        <td class="qb-amount"><?php echo money($bu_data['totals']['expenses']); ?></td>
                                        <td class="qb-amount"><?php echo money($bu_data['totals']['net_profit']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <tr class="qb-grand-row">
                                    <td>Grand Total</td>
                                    <td></td>
                                    <td class="qb-amount"><?php echo money($sales); ?></td>
                                    <td class="qb-amount"><?php echo money($cogs); ?></td>
                                    <td class="qb-amount"><?php echo money($gross_profit); ?></td>
                                    <td class="qb-amount"><?php echo money($expenses); ?></td>
                                    <td class="qb-amount"><?php echo money($net_profit); ?></td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>


        <!-- QUICKBOOKS-LIKE COGS DETAIL DRILLDOWN -->
        <div class="qb-report-card qb-section cogs-report report-hidden" id="cogsDetailReport">
            <div class="qb-report-titlebar no-print">
                <h5><i class="bi bi-box-seam me-2"></i> Cost of Goods Sold Details</h5>
                <span class="qb-muted" style="color:#e2e8f0;">Item-level source details</span>
            </div>
            <div class="qb-report-header-print">
                <div class="qb-company-name">Cost of Goods Sold Details</div>
                <div class="qb-report-meta"><?php echo date('m/d/Y', strtotime($start_date)); ?> to <?php echo date('m/d/Y', strtotime($end_date)); ?> | Source: Delivered Sales Orders</div>
            </div>
            <div class="qb-report-body">
                <div class="qb-summary-grid">
                    <div class="qb-summary-box">
                        <div class="qb-summary-label">Summary COGS</div>
                        <div class="qb-summary-value"><?php echo money($cogs); ?></div>
                    </div>
                    <div class="qb-summary-box">
                        <div class="qb-summary-label">Detail Total</div>
                        <div class="qb-summary-value"><?php echo money($cogs_detail_total); ?></div>
                    </div>
                    <div class="qb-summary-box">
                        <div class="qb-summary-label">Detail Lines</div>
                        <div class="qb-summary-value"><?php echo number_format(count($cogs_detail_rows)); ?></div>
                    </div>
                    <div class="qb-summary-box">
                        <div class="qb-summary-label">Difference</div>
                        <div class="qb-summary-value"><?php echo money($cogs - $cogs_detail_total); ?></div>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="qb-table" id="quickbooksCogsDetailTable">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Source / Ref</th>
                                <th>Business Unit</th>
                                <th>Branch</th>
                                <th>Agent</th>
                                <th>Customer</th>
                                <th>Item</th>
                                <th>Unit</th>
                                <th class="qb-amount">Qty</th>
                                <th class="qb-amount">Unit Cost</th>
                                <th class="qb-amount">Line COGS</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($cogs_detail_rows)): ?>
                                <tr>
                                    <td colspan="11" class="text-center text-muted py-4">
                                        No COGS item details found for the selected report range.
                                        <?php if ((float)$cogs != 0): ?>
                                            <br><span class="qb-muted">May summary COGS na <?php echo money($cogs); ?>, pero walang item-level cost column na nakita sa sales_order_items table.</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php
                                $current_cogs_group = '';
                                $group_cogs_total = 0;
                                foreach ($cogs_detail_rows as $index => $cogs_row):
                                    $group_key = trim(($cogs_row['business_unit'] ?? 'Unassigned') . ' / ' . ($cogs_row['branch_name'] ?? ''));
                                    if ($current_cogs_group !== $group_key):
                                        if ($current_cogs_group !== ''):
                                ?>
                                            <tr class="qb-total-row">
                                                <td colspan="10">Subtotal <?php echo htmlspecialchars($current_cogs_group); ?></td>
                                                <td class="qb-amount"><?php echo money($group_cogs_total); ?></td>
                                            </tr>
                                <?php
                                        endif;
                                        $current_cogs_group = $group_key;
                                        $group_cogs_total = 0;
                                ?>
                                        <tr class="qb-group-row">
                                            <td colspan="11"><?php echo htmlspecialchars($current_cogs_group); ?></td>
                                        </tr>
                                <?php
                                    endif;
                                    $group_cogs_total += (float)$cogs_row['line_cogs'];
                                ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars(date('m/d/Y', strtotime($cogs_row['order_date']))); ?></td>
                                        <td>
                                            <span class="fw-bold"><?php echo htmlspecialchars($cogs_row['reference_no']); ?></span>
                                            <div class="qb-muted">Sales Order #<?php echo (int)$cogs_row['so_id']; ?></div>
                                        </td>
                                        <td><?php echo htmlspecialchars($cogs_row['business_unit']); ?></td>
                                        <td><?php echo htmlspecialchars($cogs_row['branch_name']); ?></td>
                                        <td><?php echo htmlspecialchars(trim($cogs_row['agent_name']) ?: 'Unassigned'); ?></td>
                                        <td><?php echo htmlspecialchars(trim($cogs_row['customer_name']) ?: 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($cogs_row['item_name']); ?></td>
                                        <td><?php echo htmlspecialchars($cogs_row['unit_name']); ?></td>
                                        <td class="qb-amount"><?php echo number_format((float)$cogs_row['qty'], 2); ?></td>
                                        <td class="qb-amount"><?php echo money($cogs_row['unit_cost']); ?></td>
                                        <td class="qb-amount"><?php echo money($cogs_row['line_cogs']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if ($current_cogs_group !== ''): ?>
                                    <tr class="qb-total-row">
                                        <td colspan="10">Subtotal <?php echo htmlspecialchars($current_cogs_group); ?></td>
                                        <td class="qb-amount"><?php echo money($group_cogs_total); ?></td>
                                    </tr>
                                <?php endif; ?>
                                <tr class="qb-grand-row">
                                    <td colspan="10">Grand Total COGS Details</td>
                                    <td class="qb-amount"><?php echo money($cogs_detail_total); ?></td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- MODIFY REPORT MODAL -->
        <div class="modal fade qb-modal" id="modifyReportModal" tabindex="-1" aria-labelledby="modifyReportModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
                <div class="modal-content">
                    <form method="GET" id="quickbooksModifyForm">
                        <div class="modal-header">
                            <h5 class="modal-title" id="modifyReportModalLabel">Modify Report: Dashboard Financial Reports</h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <ul class="nav nav-tabs mb-3" role="tablist">
                                <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#qbDisplayTab" type="button">Display</button></li>
                                <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#qbFiltersTab" type="button">Filters</button></li>
                                <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#qbHeaderTab" type="button">Header/Footer</button></li>
                                <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#qbFontsTab" type="button">Fonts &amp; Numbers</button></li>
                            </ul>
                            <div class="tab-content">
                                <div class="tab-pane fade show active" id="qbDisplayTab">
                                    <div class="row g-3">
                                        <div class="col-md-4">
                                            <label class="form-label fw-bold">Report Date Range</label>
                                            <select name="period" class="form-select">
                                                <option value="daily" <?php echo $active_period === 'daily' ? 'selected' : ''; ?>>Daily</option>
                                                <option value="weekly" <?php echo $active_period === 'weekly' ? 'selected' : ''; ?>>Weekly</option>
                                                <option value="monthly" <?php echo $active_period === 'monthly' ? 'selected' : ''; ?>>Monthly</option>
                                                <option value="yearly" <?php echo $active_period === 'yearly' ? 'selected' : ''; ?>>Yearly</option>
                                                <option value="custom" <?php echo $active_period === 'custom' ? 'selected' : ''; ?>>Custom</option>
                                            </select>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label fw-bold">From</label>
                                            <input type="date" name="start_date" class="form-control" value="<?php echo htmlspecialchars($custom_start_date); ?>">
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label fw-bold">To</label>
                                            <input type="date" name="end_date" class="form-control" value="<?php echo htmlspecialchars($custom_end_date); ?>">
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label fw-bold">Main Date</label>
                                            <input type="date" name="date" class="form-control" value="<?php echo htmlspecialchars($selected_date); ?>">
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label fw-bold">Report Basis</label>
                                            <div class="d-flex gap-3 align-items-center pt-2">
                                                <label><input type="radio" name="report_basis" value="accrual" <?php echo $report_basis === 'accrual' ? 'checked' : ''; ?>> Accrual</label>
                                                <label><input type="radio" name="report_basis" value="cash" <?php echo $report_basis === 'cash' ? 'checked' : ''; ?>> Cash</label>
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label fw-bold">Total By</label>
                                            <select name="total_by" class="form-select">
                                                <option value="business_unit" <?php echo $total_by === 'business_unit' ? 'selected' : ''; ?>>Business Unit</option>
                                                <option value="branch" <?php echo $total_by === 'branch' ? 'selected' : ''; ?>>Branch</option>
                                                <option value="agent" <?php echo $total_by === 'agent' ? 'selected' : ''; ?>>Agent</option>
                                            </select>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label fw-bold">Columns</label>
                                            <div class="qb-column-list">
                                                <label class="d-block"><input type="checkbox" checked> Account / Group</label>
                                                <label class="d-block"><input type="checkbox" checked> Branch</label>
                                                <label class="d-block"><input type="checkbox" checked> Sales</label>
                                                <label class="d-block"><input type="checkbox" checked> COGS</label>
                                                <label class="d-block"><input type="checkbox" checked> Gross Profit</label>
                                                <label class="d-block"><input type="checkbox" checked> Expenses</label>
                                                <label class="d-block"><input type="checkbox" checked> Net Profit</label>
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label fw-bold">Sort By</label>
                                            <select name="sort_by" class="form-select">
                                                <option value="default" <?php echo $sort_by === 'default' ? 'selected' : ''; ?>>Default</option>
                                                <option value="sales" <?php echo $sort_by === 'sales' ? 'selected' : ''; ?>>Sales</option>
                                                <option value="gross_profit" <?php echo $sort_by === 'gross_profit' ? 'selected' : ''; ?>>Gross Profit</option>
                                                <option value="expenses" <?php echo $sort_by === 'expenses' ? 'selected' : ''; ?>>Expenses</option>
                                                <option value="net_profit" <?php echo $sort_by === 'net_profit' ? 'selected' : ''; ?>>Net Profit</option>
                                                <option value="volume" <?php echo $sort_by === 'volume' ? 'selected' : ''; ?>>Volume</option>
                                            </select>
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label fw-bold">Sort In</label>
                                            <select name="sort_dir" class="form-select">
                                                <option value="asc" <?php echo $sort_dir === 'asc' ? 'selected' : ''; ?>>Ascending order</option>
                                                <option value="desc" <?php echo $sort_dir === 'desc' ? 'selected' : ''; ?>>Descending order</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                                <div class="tab-pane fade" id="qbFiltersTab">
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <label class="form-label fw-bold">Business Unit</label>
                                            <select name="business_unit" class="form-select">
                                                <option value="">All Business Units</option>
                                                <?php foreach ($business_units as $bu): ?>
                                                    <option value="<?php echo htmlspecialchars($bu); ?>" <?php echo $selected_business_unit === $bu ? 'selected' : ''; ?>><?php echo htmlspecialchars($bu); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label fw-bold">Branch</label>
                                            <select name="branch_id" class="form-select">
                                                <option value="0">All Branches</option>
                                                <?php foreach ($branch_options as $branch_option): ?>
                                                    <option value="<?php echo (int)$branch_option['branch_id']; ?>" <?php echo $selected_branch_filter_id === (int)$branch_option['branch_id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($branch_option['branch_name']); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-12">
                                            <div class="alert alert-light border mb-0">Use these filters to generate a report similar to QuickBooks. The result below updates after you click OK.</div>
                                        </div>
                                    </div>
                                </div>
                                <div class="tab-pane fade" id="qbHeaderTab">
                                    <div class="row g-3">
                                        <div class="col-md-6"><label class="form-label fw-bold">Report Title</label><input type="text" class="form-control" value="Profit and Loss"></div>
                                        <div class="col-md-6"><label class="form-label fw-bold">Company Name</label><input type="text" class="form-control" value="Super Duper Admin Dashboard"></div>
                                        <div class="col-12"><label class="form-label fw-bold">Footer Note</label><input type="text" class="form-control" value="Generated from delivered sales, COGS, and recorded expenses."></div>
                                    </div>
                                </div>
                                <div class="tab-pane fade" id="qbFontsTab">
                                    <div class="row g-3">
                                        <div class="col-md-4"><label class="form-label fw-bold">Amount Format</label><select class="form-select"><option>₱1,234.00</option><option>1,234.00</option></select></div>
                                        <div class="col-md-4"><label class="form-label fw-bold">Negative Numbers</label><select class="form-select"><option>-₱1,234.00</option><option>(₱1,234.00)</option></select></div>
                                        <div class="col-md-4"><label class="form-label fw-bold">Font Size</label><select class="form-select"><option>Normal</option><option>Compact</option><option>Large</option></select></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                            <a href="?period=monthly" class="btn btn-outline-secondary">Revert</a>
                            <button type="submit" class="btn btn-amgc-primary px-4">OK</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- CHARTS ROW 1: P&L Overview -->
        <div class="row g-3 mb-3 qb-section graphs-report">
            <div class="col-xl-8">
                <div class="chart-card">
                    <div class="chart-header">
                        <h5><i class="bi bi-bar-chart-fill me-2"></i> Profit & Loss by Business Unit</h5>
                    </div>
                    <div class="chart-body">
                        <canvas id="pnlBarChart"></canvas>
                    </div>
                </div>
            </div>
            <div class="col-xl-4">
                <div class="chart-card">
                    <div class="chart-header">
                        <h5><i class="bi bi-pie-chart-fill me-2"></i> Net Profit Distribution</h5>
                    </div>
                    <div class="chart-body">
                        <canvas id="netProfitPieChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- CHARTS ROW 2: Volume Analysis -->
        <div class="row g-3 mb-3 qb-section graphs-report volume-report">
            <div class="col-xl-4">
                <div class="chart-card">
                    <div class="chart-header">
                        <h5><i class="bi bi-box-seam me-2"></i> Volume by Business Unit</h5>
                    </div>
                    <div class="chart-body">
                        <canvas id="volumeBuChart"></canvas>
                    </div>
                </div>
            </div>
            <div class="col-xl-4">
                <div class="chart-card">
                    <div class="chart-header">
                        <h5><i class="bi bi-building me-2"></i> Top 10 Branches by Volume</h5>
                    </div>
                    <div class="chart-body">
                        <canvas id="volumeBranchChart"></canvas>
                    </div>
                </div>
            </div>
            <div class="col-xl-4">
                <div class="chart-card">
                    <div class="chart-header">
                        <h5><i class="bi bi-people-fill me-2"></i> Top 10 Agents by Volume</h5>
                    </div>
                    <div class="chart-body">
                        <canvas id="volumeAgentChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- P&L DATA TABLE -->
        <div class="data-table qb-section summary-report detail-report">
            <div class="table-header">
                <h5><i class="bi bi-currency-exchange me-2"></i> Profit & Loss per Business Unit / Branch</h5>
                <span class="period-pill" style="background: rgba(255,255,255,0.2); color: #fff;"><i class="bi bi-calendar3"></i> <?php echo htmlspecialchars($period_label); ?></span>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th style="width: 25%;">Business Unit / Branch</th>
                            <th class="text-end">Sales</th>
                            <th class="text-end">COGS</th>
                            <th class="text-end">Gross Profit</th>
                            <th class="text-end">Expenses</th>
                            <th class="text-end">Net Profit</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $grand_sales = $grand_cogs = $grand_gp = $grand_exp = $grand_np = 0;
                        if (empty($pnl_by_bu)): ?>
                            <tr><td colspan="6" class="text-center py-4 text-muted fw-bold">No P&L data found for the selected filter.</td></tr>
                        <?php else: ?>
                            <?php foreach ($pnl_by_bu as $bu_name => $bu_data): ?>
                                <tr class="subtotal-row section-toggle" data-bs-toggle="collapse" data-bs-target="#pnl-<?php echo md5($bu_name); ?>">
                                    <td><i class="bi bi-chevron-down me-2"></i><span class="badge-bu"><?php echo htmlspecialchars($bu_name); ?></span></td>
                                    <td class="text-end"><?php echo money($bu_data['totals']['sales']); ?></td>
                                    <td class="text-end"><?php echo money($bu_data['totals']['cogs']); ?></td>
                                    <td class="text-end <?php echo $bu_data['totals']['gross_profit'] < 0 ? 'profit-negative' : 'profit-positive'; ?>"><?php echo money($bu_data['totals']['gross_profit']); ?></td>
                                    <td class="text-end"><?php echo money($bu_data['totals']['expenses']); ?></td>
                                    <td class="text-end <?php echo $bu_data['totals']['net_profit'] < 0 ? 'profit-negative' : 'profit-positive'; ?>"><?php echo money($bu_data['totals']['net_profit']); ?></td>
                                </tr>
                                <tbody class="collapse show" id="pnl-<?php echo md5($bu_name); ?>">
                                    <?php foreach ($bu_data['branches'] as $branch): ?>
                                        <tr class="child-row">
                                            <td><?php echo htmlspecialchars($branch['branch_name']); ?></td>
                                            <td class="text-end"><?php echo money($branch['sales']); ?></td>
                                            <td class="text-end"><?php echo money($branch['cogs']); ?></td>
                                            <td class="text-end <?php echo (float)$branch['gross_profit'] < 0 ? 'profit-negative' : 'profit-positive'; ?>"><?php echo money($branch['gross_profit']); ?></td>
                                            <td class="text-end"><?php echo money($branch['expenses']); ?></td>
                                            <td class="text-end <?php echo (float)$branch['net_profit'] < 0 ? 'profit-negative' : 'profit-positive'; ?>"><?php echo money($branch['net_profit']); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <?php 
                                $grand_sales += $bu_data['totals']['sales'];
                                $grand_cogs += $bu_data['totals']['cogs'];
                                $grand_gp += $bu_data['totals']['gross_profit'];
                                $grand_exp += $bu_data['totals']['expenses'];
                                $grand_np += $bu_data['totals']['net_profit'];
                                ?>
                            <?php endforeach; ?>
                            <tr class="grand-total-row">
                                <td><strong><i class="bi bi-calculator me-2"></i>GRAND TOTAL</strong></td>
                                <td class="text-end"><strong><?php echo money($grand_sales); ?></strong></td>
                                <td class="text-end"><strong><?php echo money($grand_cogs); ?></strong></td>
                                <td class="text-end <?php echo $grand_gp < 0 ? 'profit-negative' : 'profit-positive'; ?>"><strong><?php echo money($grand_gp); ?></strong></td>
                                <td class="text-end"><strong><?php echo money($grand_exp); ?></strong></td>
                                <td class="text-end <?php echo $grand_np < 0 ? 'profit-negative' : 'profit-positive'; ?>"><strong><?php echo money($grand_np); ?></strong></td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- VOLUME DATA TABLE -->
        <div class="data-table">
            <div class="table-header">
                <h5><i class="bi bi-box-seam me-2"></i> Volume per Business Unit / Branch / Agent (Qty)</h5>
                <span class="period-pill" style="background: rgba(255,255,255,0.2); color: #fff;"><i class="bi bi-calendar3"></i> <?php echo htmlspecialchars($period_label); ?></span>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th style="width: 40%;">Business Unit / Branch / Agent</th>
                            <th class="text-end">Total Qty Sold</th>
                            <th class="text-end">Total Orders</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $grand_vol_qty = $grand_vol_orders = 0;
                        if (empty($volume_by_bu)): ?>
                            <tr><td colspan="3" class="text-center py-4 text-muted fw-bold">No volume data found for the selected filter.</td></tr>
                        <?php else: ?>
                            <?php foreach ($volume_by_bu as $bu_name => $bu_data): ?>
                                <tr class="subtotal-row section-toggle" data-bs-toggle="collapse" data-bs-target="#vol-<?php echo md5($bu_name); ?>">
                                    <td><i class="bi bi-chevron-down me-2"></i><span class="badge-bu"><?php echo htmlspecialchars($bu_name); ?></span></td>
                                    <td class="text-end"><?php echo number_format($bu_data['total_qty']); ?></td>
                                    <td class="text-end"><?php echo number_format($bu_data['total_orders']); ?></td>
                                </tr>
                                <tbody class="collapse show" id="vol-<?php echo md5($bu_name); ?>">
                                    <?php foreach ($bu_data['branches'] as $branch_name => $branch_data): ?>
                                        <tr class="child-row section-toggle" data-bs-toggle="collapse" data-bs-target="#vol-branch-<?php echo md5($bu_name . $branch_name); ?>">
                                            <td><i class="bi bi-chevron-down me-2"></i><?php echo htmlspecialchars($branch_name); ?></td>
                                            <td class="text-end"><?php echo number_format($branch_data['total_qty']); ?></td>
                                            <td class="text-end"><?php echo number_format($branch_data['total_orders']); ?></td>
                                        </tr>
                                        <tbody class="collapse show" id="vol-branch-<?php echo md5($bu_name . $branch_name); ?>">
                                            <?php foreach ($branch_data['agents'] as $agent): ?>
                                                <tr class="agent-row">
                                                    <td><i class="bi bi-person me-1 text-muted"></i><?php echo htmlspecialchars(trim($agent['agent_name']) ?: 'Unknown Agent'); ?></td>
                                                    <td class="text-end"><?php echo number_format($agent['total_qty']); ?></td>
                                                    <td class="text-end"><?php echo number_format($agent['total_orders']); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    <?php endforeach; ?>
                                </tbody>
                                <?php 
                                $grand_vol_qty += $bu_data['total_qty'];
                                $grand_vol_orders += $bu_data['total_orders'];
                                ?>
                            <?php endforeach; ?>
                            <tr class="grand-total-row">
                                <td><strong><i class="bi bi-calculator me-2"></i>GRAND TOTAL</strong></td>
                                <td class="text-end"><strong><?php echo number_format($grand_vol_qty); ?></strong></td>
                                <td class="text-end"><strong><?php echo number_format($grand_vol_orders); ?></strong></td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Chart.js global defaults
        Chart.defaults.font.family = "'Inter', 'Segoe UI', sans-serif";
        Chart.defaults.color = '#334155';

        // Color palette
        const chartColors = {
            green: '#047857',
            lightGreen: '#44D34E',
            teal: '#059669',
            blue: '#0284c7',
            amber: '#d97706',
            red: '#dc2626',
            purple: '#7c3aed',
            pink: '#db2777',
            slate: '#64748b'
        };

        const colorPalette = [
            chartColors.green,
            chartColors.lightGreen,
            chartColors.teal,
            chartColors.blue,
            chartColors.amber,
            chartColors.purple,
            chartColors.pink,
            chartColors.slate,
            chartColors.red
        ];

        // P&L Bar Chart
        const pnlLabels = <?php echo json_encode($pnl_chart_labels); ?>;
        const pnlSales = <?php echo json_encode($pnl_chart_sales); ?>;
        const pnlCogs = <?php echo json_encode($pnl_chart_cogs); ?>;
        const pnlGross = <?php echo json_encode($pnl_chart_gross); ?>;
        const pnlExpenses = <?php echo json_encode($pnl_chart_expenses); ?>;
        const pnlNet = <?php echo json_encode($pnl_chart_net); ?>;

        new Chart(document.getElementById('pnlBarChart'), {
            type: 'bar',
            data: {
                labels: pnlLabels,
                datasets: [
                    {
                        label: 'Sales',
                        data: pnlSales,
                        backgroundColor: chartColors.green,
                        borderRadius: 4
                    },
                    {
                        label: 'COGS',
                        data: pnlCogs,
                        backgroundColor: chartColors.amber,
                        borderRadius: 4
                    },
                    {
                        label: 'Gross Profit',
                        data: pnlGross,
                        backgroundColor: chartColors.teal,
                        borderRadius: 4
                    },
                    {
                        label: 'Expenses',
                        data: pnlExpenses,
                        backgroundColor: chartColors.red,
                        borderRadius: 4
                    },
                    {
                        label: 'Net Profit',
                        data: pnlNet,
                        backgroundColor: chartColors.blue,
                        borderRadius: 4
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top',
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return context.dataset.label + ': ₱' + context.raw.toLocaleString('en-PH', {minimumFractionDigits: 2});
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return '₱' + (value / 1000).toFixed(0) + 'K';
                            }
                        }
                    }
                }
            }
        });

        // Net Profit Pie Chart
        const netProfitData = pnlNet.map((val, idx) => ({ label: pnlLabels[idx], value: Math.max(0, val) })).filter(d => d.value > 0);
        new Chart(document.getElementById('netProfitPieChart'), {
            type: 'doughnut',
            data: {
                labels: netProfitData.map(d => d.label),
                datasets: [{
                    data: netProfitData.map(d => d.value),
                    backgroundColor: colorPalette.slice(0, netProfitData.length),
                    borderWidth: 2,
                    borderColor: '#fff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            padding: 15,
                            usePointStyle: true
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                const percentage = ((context.raw / total) * 100).toFixed(1);
                                return context.label + ': ₱' + context.raw.toLocaleString('en-PH', {minimumFractionDigits: 2}) + ' (' + percentage + '%)';
                            }
                        }
                    }
                }
            }
        });

        // Volume by BU Chart
        const volumeBuLabels = <?php echo json_encode($volume_chart_labels); ?>;
        const volumeBuQty = <?php echo json_encode($volume_chart_qty); ?>;

        new Chart(document.getElementById('volumeBuChart'), {
            type: 'bar',
            data: {
                labels: volumeBuLabels,
                datasets: [{
                    label: 'Quantity Sold',
                    data: volumeBuQty,
                    backgroundColor: colorPalette.slice(0, volumeBuLabels.length),
                    borderRadius: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                indexAxis: 'y',
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    x: {
                        beginAtZero: true
                    }
                }
            }
        });

        // Volume by Branch Chart
        const volumeBranchLabels = <?php echo json_encode($volume_branch_labels); ?>;
        const volumeBranchQty = <?php echo json_encode($volume_branch_qty); ?>;

        new Chart(document.getElementById('volumeBranchChart'), {
            type: 'bar',
            data: {
                labels: volumeBranchLabels,
                datasets: [{
                    label: 'Quantity Sold',
                    data: volumeBranchQty,
                    backgroundColor: chartColors.teal,
                    borderRadius: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                indexAxis: 'y',
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    x: {
                        beginAtZero: true
                    }
                }
            }
        });

        // Volume by Agent Chart
        const volumeAgentLabels = <?php echo json_encode($volume_agent_labels); ?>;
        const volumeAgentQty = <?php echo json_encode($volume_agent_qty); ?>;

        new Chart(document.getElementById('volumeAgentChart'), {
            type: 'bar',
            data: {
                labels: volumeAgentLabels,
                datasets: [{
                    label: 'Quantity Sold',
                    data: volumeAgentQty,
                    backgroundColor: chartColors.blue,
                    borderRadius: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                indexAxis: 'y',
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    x: {
                        beginAtZero: true
                    }
                }
            }
        });

        // Period filter tabs
        const filterForm = document.getElementById('filterForm');
        const periodInput = document.getElementById('periodInput');
        const periodButtons = document.querySelectorAll('.period-btn');
        const singleDateContainer = document.getElementById('singleDateContainer');
        const customDateContainer = document.getElementById('customDateContainer');
        const singleDateInput = document.querySelector('input[name="date"]');
        const singleDateLabel = singleDateContainer?.querySelector('.form-label');
        const singleDateHelp = singleDateContainer?.querySelector('small');

        function setPeriod(period, submitNow = false) {
            if (!periodInput) return;

            periodInput.value = period;

            periodButtons.forEach(btn => {
                btn.classList.toggle('active', btn.dataset.period === period);
            });

            if (period === 'custom') {
                singleDateContainer?.classList.add('d-none');
                customDateContainer?.classList.remove('d-none');
            } else {
                singleDateContainer?.classList.remove('d-none');
                customDateContainer?.classList.add('d-none');
            }

            if (singleDateInput) {
                singleDateInput.type = 'date';
            }

            if (singleDateLabel && singleDateHelp) {
                if (period === 'daily') {
                    singleDateLabel.innerHTML = '<i class="bi bi-calendar3"></i> Select Date';
                    singleDateHelp.textContent = 'Choose a specific day';
                } else if (period === 'weekly') {
                    singleDateLabel.innerHTML = '<i class="bi bi-calendar3"></i> Week of';
                    singleDateHelp.textContent = 'Pick any day in the week';
                } else if (period === 'yearly') {
                    singleDateLabel.innerHTML = '<i class="bi bi-calendar3"></i> Select Year';
                    singleDateHelp.textContent = 'Pick any day in the year';
                } else if (period === 'monthly') {
                    singleDateLabel.innerHTML = '<i class="bi bi-calendar3"></i> Select Month';
                    singleDateHelp.textContent = 'Select month and year';
                }
            }

            if (submitNow && filterForm) {
                filterForm.requestSubmit ? filterForm.requestSubmit() : filterForm.submit();
            }
        }

        periodButtons.forEach(btn => {
            btn.addEventListener('click', function() {
                setPeriod(this.dataset.period, true);
            });
        });

        setPeriod(periodInput?.value || 'monthly', false);

        document.querySelectorAll('.filter-open-card[role="button"]').forEach(card => {
            card.addEventListener('keydown', function(e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    this.click();
                }
            });
        });


        document.querySelectorAll('.remove-filter').forEach(button => {
            button.addEventListener('click', function() {
                const filter = this.dataset.filter;

                if (filter === 'period') {
                    setPeriod('monthly', true);
                    return;
                }

                if (filter === 'business_unit') {
                    const businessUnitSelect = document.getElementById('businessUnitSelect');
                    if (businessUnitSelect) businessUnitSelect.value = '';
                }

                if (filter === 'branch_id') {
                    const branchSelect = document.getElementById('branchSelect');
                    if (branchSelect) branchSelect.value = '0';
                }

                if (filterForm) {
                    filterForm.requestSubmit ? filterForm.requestSubmit() : filterForm.submit();
                }
            });
        });



        // QuickBooks-like report view switcher
        const qbReportTabs = document.querySelectorAll('.qb-report-tab');
        const qbSections = document.querySelectorAll('.qb-section');

        function setQbReportView(view) {
            qbReportTabs.forEach(btn => btn.classList.toggle('active', btn.dataset.qbView === view));
            qbSections.forEach(section => {
                const show = view === 'all' || section.classList.contains(view + '-report');
                section.classList.toggle('report-hidden', !show);
            });
        }

        qbReportTabs.forEach(btn => {
            btn.addEventListener('click', function() {
                setQbReportView(this.dataset.qbView || 'all');
            });
        });

        document.querySelectorAll('[data-open-qb-view]').forEach(btn => {
            btn.addEventListener('click', function() {
                const view = this.dataset.openQbView || 'all';
                setQbReportView(view);
                document.getElementById('cogsDetailReport')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
            });
        });

        function tableToCsv(table) {
            const rows = [];
            table.querySelectorAll('tr').forEach(tr => {
                const row = [];
                tr.querySelectorAll('th,td').forEach(cell => row.push(cell.innerText.trim()));
                rows.push(row);
            });
            return rows;
        }

        function downloadCsv(filename, rows) {
            const csvContent = rows.map(row => row.map(value => '"' + String(value).replace(/"/g, '""') + '"').join(',')).join('\n');
            const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
            const link = document.createElement('a');
            const url = URL.createObjectURL(blob);
            link.setAttribute('href', url);
            link.setAttribute('download', filename);
            link.style.visibility = 'hidden';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            URL.revokeObjectURL(url);
        }

        document.getElementById('exportQuickbooksCsvBtn')?.addEventListener('click', function() {
            const rows = [
                ['Super Duper Admin Dashboard'],
                ['Profit and Loss / Transaction Detail'],
                ['Period', <?php echo json_encode($period_label); ?>],
                ['Generated', <?php echo json_encode($report_generated_at); ?>],
                [],
                ...tableToCsv(document.getElementById('quickbooksPnlTable')),
                [],
                ...tableToCsv(document.getElementById('quickbooksDetailTable')),
                [],
                ['Cost of Goods Sold Details'],
                ...tableToCsv(document.getElementById('quickbooksCogsDetailTable'))
            ];
            downloadCsv('quickbooks-style-report-<?php echo date('Ymd', strtotime($start_date)); ?>-<?php echo date('Ymd', strtotime($end_date)); ?>.csv', rows);
        });

        document.getElementById('emailReportBtn')?.addEventListener('click', function() {
            const subject = encodeURIComponent('Financial Report - <?php echo addslashes($period_label); ?>');
            const body = encodeURIComponent('Attached/printed report details for <?php echo addslashes($period_label); ?>. Please export the CSV or print this page from the dashboard.');
            window.location.href = 'mailto:?subject=' + subject + '&body=' + body;
        });

        document.getElementById('toggleReportHeaderBtn')?.addEventListener('click', function() {
            document.querySelectorAll('.qb-report-header-print').forEach(header => header.classList.toggle('d-none'));
            this.innerHTML = document.querySelector('.qb-report-header-print')?.classList.contains('d-none')
                ? '<i class="bi bi-layout-text-window"></i> Show Header'
                : '<i class="bi bi-layout-text-window"></i> Hide Header';
        });

        // Collapsible toggle icons
        document.querySelectorAll('.section-toggle').forEach(toggle => {
            toggle.addEventListener('click', function() {
                this.classList.toggle('collapsed');
            });
        });
         // Logout function
        function logout() {
            Swal.fire({
                title: 'Logout',
                text: 'Are you sure you want to logout?',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#07d826',
                cancelButtonColor: '#6b7280',
                confirmButtonText: 'Yes, logout',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = '../logout.php';
                }
            });
        }

        // User dropdown toggle functionality
        const userAvatarBtn = document.getElementById('userAvatarBtn');
        const userDropdownMenu = document.querySelector('.user-dropdown-menu');

        if (userAvatarBtn && userDropdownMenu) {
            userAvatarBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                userDropdownMenu.classList.toggle('show');
            });

            // Close dropdown when clicking outside
            document.addEventListener('click', function(event) {
                if (!userAvatarBtn.contains(event.target) && !userDropdownMenu.contains(event.target)) {
                    userDropdownMenu.classList.remove('show');
                }
            });

            // Close dropdown on escape key
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    userDropdownMenu.classList.remove('show');
                }
            });
        }
    </script>
</body>
</html>
