<?php
require_once '../config/database.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

$user_id = (int)($_SESSION['user_id'] ?? 0);
$user_name = $_SESSION['user_name'] ?? ((($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '')) ?: 'Global');
$user_role = $_SESSION['role'] ?? 'global';
$view_all_branches = $_SESSION['view_all_branches'] ?? true;
$user_branch_id = (int)($_SESSION['branch_id'] ?? 0);

$branch_name = 'All Branches';
if (!$view_all_branches && $user_branch_id > 0) {
    $branch_stmt = $conn->prepare("SELECT branch_name FROM branches WHERE branch_id = ? LIMIT 1");
    if ($branch_stmt) {
        $branch_stmt->bind_param("i", $user_branch_id);
        $branch_stmt->execute();
        $branch_result = $branch_stmt->get_result();
        if ($branch_row = $branch_result->fetch_assoc()) {
            $branch_name = $branch_row['branch_name'];
        }
        $branch_stmt->close();
    }
}

$user_initials = '';
foreach (explode(' ', trim($user_name)) as $part) {
    if ($part !== '') {
        $user_initials .= strtoupper(substr($part, 0, 1));
    }
}
$user_initials = $user_initials ?: 'AD';

$active_period = $_GET['period'] ?? 'daily';
if (!in_array($active_period, ['daily', 'weekly', 'monthly', 'custom'], true)) {
    $active_period = 'daily';
}

$selected_date = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selected_date)) {
    $selected_date = date('Y-m-d');
}

$custom_start_date = $_GET['start_date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $custom_start_date)) {
    $custom_start_date = date('Y-m-d');
}

$custom_end_date = $_GET['end_date'] ?? $custom_start_date;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $custom_end_date)) {
    $custom_end_date = $custom_start_date;
}
if (strtotime($custom_start_date) > strtotime($custom_end_date)) {
    [$custom_start_date, $custom_end_date] = [$custom_end_date, $custom_start_date];
}

$selected_business_unit = trim($_GET['business_unit'] ?? '');
$selected_branch_filter_id = (int)($_GET['branch_id'] ?? 0);

$business_units = [];
$branch_options = [];
$branch_lookup = [];

$branch_options_sql = "SELECT branch_id, branch_name, business_unit
                       FROM branches
                       WHERE status = 'active'" . ((!$view_all_branches && $user_branch_id > 0) ? " AND branch_id = ?" : "") . "
                       ORDER BY NULLIF(TRIM(business_unit), '') ASC, branch_name ASC";
$branch_options_stmt = $conn->prepare($branch_options_sql);
if ($branch_options_stmt) {
    if (!$view_all_branches && $user_branch_id > 0) {
        $branch_options_stmt->bind_param('i', $user_branch_id);
    }
    $branch_options_stmt->execute();
    $branch_options_result = $branch_options_stmt->get_result();
    while ($branch_option = $branch_options_result->fetch_assoc()) {
        $branch_option['business_unit'] = trim((string)($branch_option['business_unit'] ?? ''));
        $branch_options[] = $branch_option;
        $branch_lookup[(int)$branch_option['branch_id']] = $branch_option;
        if ($branch_option['business_unit'] !== '') {
            $business_units[$branch_option['business_unit']] = $branch_option['business_unit'];
        }
    }
    $branch_options_stmt->close();
}
ksort($business_units);

if (!$view_all_branches && $user_branch_id > 0) {
    $selected_branch_filter_id = $user_branch_id;
}
if ($selected_branch_filter_id > 0 && !isset($branch_lookup[$selected_branch_filter_id])) {
    $selected_branch_filter_id = 0;
}
if ($selected_business_unit !== '' && !isset($business_units[$selected_business_unit])) {
    $selected_business_unit = '';
}

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

function get_profit_summary(mysqli $conn, string $period, string $selected_date, bool $view_all_branches, int $user_branch_id, string $custom_start_date = '', string $custom_end_date = ''): array {
    $period_info = get_period_date_info($period, $selected_date, $custom_start_date ?: $selected_date, $custom_end_date ?: $selected_date);
    $start_date = $period_info['start'];
    $end_date = $period_info['end'];
    $label = $period_info['label'];

    $branch_sales = (!$view_all_branches && $user_branch_id > 0) ? " AND so.branch_id = ?" : "";
    $branch_expenses = (!$view_all_branches && $user_branch_id > 0) ? " AND bt.branch_id = ?" : "";

    $sales_sql = "SELECT
                    COALESCE(SUM(CASE WHEN so.order_amount > 0 THEN so.order_amount ELSE so.total_amount END), 0) AS sales,
                    COALESCE(SUM(so.cogs_amount), 0) AS cogs,
                    COALESCE(SUM(CASE
                        WHEN so.gross_profit_amount <> 0 THEN so.gross_profit_amount
                        WHEN so.gross_profit <> 0 THEN so.gross_profit
                        ELSE (CASE WHEN so.order_amount > 0 THEN so.order_amount ELSE so.total_amount END) - so.cogs_amount
                    END), 0) AS gross_profit,
                    COUNT(*) AS total_orders
                  FROM sales_orders so
                  WHERE so.order_status <> 'cancelled'
                    AND so.order_date IS NOT NULL
                    AND so.order_date <> '0000-00-00 00:00:00'
                    AND DATE(so.order_date) BETWEEN ? AND ?
                    $branch_sales";

    $sales_stmt = $conn->prepare($sales_sql);
    if (!$sales_stmt) {
        return ['label' => $label, 'sales' => 0, 'cogs' => 0, 'gross_profit' => 0, 'expenses' => 0, 'net_profit' => 0, 'orders' => 0];
    }
    if (!$view_all_branches && $user_branch_id > 0) {
        $sales_stmt->bind_param('ssi', $start_date, $end_date, $user_branch_id);
    } else {
        $sales_stmt->bind_param('ss', $start_date, $end_date);
    }
    $sales_stmt->execute();
    $sales_row = $sales_stmt->get_result()->fetch_assoc() ?: [];
    $sales_stmt->close();

    $expense_sql = "SELECT COALESCE(SUM(bt.amount), 0) AS expenses
                    FROM bank_transactions bt
                    WHERE bt.transaction_type = 'withdrawal'
                      AND bt.transaction_date IS NOT NULL
                      AND DATE(bt.transaction_date) BETWEEN ? AND ?
                      AND bt.expense_account IS NOT NULL
                      AND TRIM(bt.expense_account) <> ''
                      $branch_expenses";

    $expense_stmt = $conn->prepare($expense_sql);
    $expenses = 0;
    if ($expense_stmt) {
        if (!$view_all_branches && $user_branch_id > 0) {
            $expense_stmt->bind_param('ssi', $start_date, $end_date, $user_branch_id);
        } else {
            $expense_stmt->bind_param('ss', $start_date, $end_date);
        }
        $expense_stmt->execute();
        $expense_row = $expense_stmt->get_result()->fetch_assoc() ?: [];
        $expenses = (float)($expense_row['expenses'] ?? 0);
        $expense_stmt->close();
    }

    $sales = (float)($sales_row['sales'] ?? 0);
    $cogs = (float)($sales_row['cogs'] ?? 0);
    $gross_profit = (float)($sales_row['gross_profit'] ?? ($sales - $cogs));

    return [
        'label' => $label,
        'sales' => $sales,
        'cogs' => $cogs,
        'gross_profit' => $gross_profit,
        'expenses' => $expenses,
        'net_profit' => $gross_profit - $expenses,
        'orders' => (int)($sales_row['total_orders'] ?? 0)
    ];
}

$daily = get_profit_summary($conn, 'daily', $selected_date, $view_all_branches, $user_branch_id);
$weekly = get_profit_summary($conn, 'weekly', $selected_date, $view_all_branches, $user_branch_id);
$monthly = get_profit_summary($conn, 'monthly', $selected_date, $view_all_branches, $user_branch_id);
$custom = get_profit_summary($conn, 'custom', $selected_date, $view_all_branches, $user_branch_id, $custom_start_date, $custom_end_date);
$current = ${$active_period};

$branch_sales_rows = [];
$current_period_info = get_period_date_info($active_period, $selected_date, $custom_start_date, $custom_end_date);
$branch_sales_start_date = $current_period_info['start'];
$branch_sales_end_date = $current_period_info['end'];
$branch_sales_date_label = $current_period_info['label'];

$branch_sales_where = ["b.status = 'active'"];
$branch_sales_types = '';
$branch_sales_params = [];

if (!$view_all_branches && $user_branch_id > 0) {
    $branch_sales_where[] = "b.branch_id = ?";
    $branch_sales_types .= 'i';
    $branch_sales_params[] = $user_branch_id;
}
if ($selected_business_unit !== '') {
    $branch_sales_where[] = "b.business_unit = ?";
    $branch_sales_types .= 's';
    $branch_sales_params[] = $selected_business_unit;
}
if ($selected_branch_filter_id > 0) {
    $branch_sales_where[] = "b.branch_id = ?";
    $branch_sales_types .= 'i';
    $branch_sales_params[] = $selected_branch_filter_id;
}

$branch_sales_sql = "SELECT
                        COALESCE(NULLIF(TRIM(b.business_unit), ''), 'Unassigned') AS business_unit,
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
                       AND so.order_status <> 'cancelled'
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
                     WHERE " . implode(' AND ', $branch_sales_where) . "
                     GROUP BY b.branch_id, b.business_unit, b.branch_name, exp.expenses
                     ORDER BY COALESCE(NULLIF(TRIM(b.business_unit), ''), 'Unassigned') ASC, b.branch_name ASC";

$branch_sales_stmt = $conn->prepare($branch_sales_sql);
if ($branch_sales_stmt) {
    $branch_sales_bind_types = 'ssss' . $branch_sales_types;
    $branch_sales_bind_params = [$branch_sales_start_date, $branch_sales_end_date, $branch_sales_start_date, $branch_sales_end_date];
    foreach ($branch_sales_params as $param) {
        $branch_sales_bind_params[] = $param;
    }
    bind_stmt_params($branch_sales_stmt, $branch_sales_bind_types, $branch_sales_bind_params);
    $branch_sales_stmt->execute();
    $branch_sales_result = $branch_sales_stmt->get_result();
    while ($row = $branch_sales_result->fetch_assoc()) {
        $branch_sales_rows[] = $row;
    }
    $branch_sales_stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Global - Dashboard</title>
    <link rel="icon" type="image/png" href="../Pictures/favicon-96x96.png" sizes="96x96" />
    <link rel="stylesheet" href="../css/global.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
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
        }

        .main-content {
            margin-left: 250px;
            padding: 1.5rem;
            min-height: 100vh;
            transition: all 0.3s ease;
        }

        .sidebar.collapsed ~ .main-content {
            margin-left: 80px;
        }

        .navbar-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1.5rem;
            gap: 1rem;
        }

        .mobile-toggle-btn {
            display: none;
            background: #fff;
            border: 1px solid rgba(68, 211, 78, 0.25);
            border-radius: 12px;
            color: var(--dark-color);
            height: 44px;
            width: 44px;
            font-size: 1.4rem;
            box-shadow: 0 8px 20px -10px rgba(5, 42, 71, 0.25);
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

        .btn-amgc-dark {
            background: #052A47;
            color: #fff;
            border: none;
            border-radius: 10px;
            min-height: 44px;
            padding: 0.55rem 1rem;
        }

        .btn-amgc-dark:hover {
            color: #fff;
            opacity: 0.96;
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

        /* ===== QUICK STATS CARDS ===== */
        .stat-card-row {
            margin-bottom: 1.25rem;
        }

        .stat-card {
            background: linear-gradient(135deg, #047857, #059669) !important;
            border: none !important;
            border-radius: 20px;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.08) !important;
            min-height: 120px;
            height: 100%;
            padding: 1rem !important;
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
            position: relative;
            overflow: hidden;
            transition: transform 0.2s ease, box-shadow 0.2s ease !important;
            cursor: default !important;
        }

        .stat-card:hover {
            transform: translateY(-2px) !important;
            box-shadow: 0 6px 15px rgba(0, 0, 0, 0.15) !important;
        }

        .stat-card .stat-icon {
            background: transparent !important;
            color: white !important;
            font-size: 1.6rem !important;
            width: auto;
            height: auto;
            flex: 0 0 auto;
            margin: 0;
            padding-top: 0.1rem;
        }

        .stat-card .stat-content {
            background: transparent !important;
            flex: 1;
            min-width: 0;
        }

        .stat-card .stat-value,
        .stat-card .stat-label,
        .stat-card .stat-sub,
        .stat-card small,
        .stat-card small i,
        .stat-card .badge {
            color: white !important;
        }

        .stat-card .stat-value {
            font-size: 1.4rem !important;
            line-height: 1.2;
            margin: 0 0 0.05rem 0;
            word-break: break-word;
        }

        .stat-card .stat-label {
            font-size: 0.75rem !important;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            margin-top: 0.1rem;
        }

        .stat-card .stat-sub {
            margin-top: 0.2rem !important;
            display: block !important;
            font-size: 0.65rem !important;
            opacity: 0.9 !important;
        }

        .profit-positive,
        .profit-negative {
            color: white !important;
        }

        .period-pill {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.35rem 0.7rem;
            border-radius: 999px;
            background: var(--light-green);
            color: var(--dark-green);
            font-size: 0.8rem;
        }

        .summary-box {
            border-radius: 16px;
            background: linear-gradient(135deg, rgba(4, 120, 87, 0.08), rgba(68, 211, 78, 0.12));
            border: 1px solid rgba(68, 211, 78, 0.22);
            padding: 1rem;
            height: 100%;
        }

        .summary-box .label {
            color: #64748b;
            font-size: 0.8rem;
            text-transform: uppercase;
        }

        .summary-box .value {
            font-size: 1.45rem;
            color: var(--dark-color);
        }

        .summary-box .profit-positive {
            color: #047857 !important;
        }

        .summary-box .profit-negative {
            color: #dc2626 !important;
        }

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
        }

        .table-header h5 {
            margin: 0;
            color: var(--dark-color);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .table thead th {
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
            white-space: nowrap;
        }

        .status-badge {
            padding: 0.35rem 0.65rem;
            border-radius: 999px;
            font-size: 0.72rem;
            text-transform: capitalize;
            background: #e2e8f0;
            color: #334155;
        }

        @media (max-width: 1199px) {
            .stat-card {
                min-height: 112px;
            }

            .stat-card .stat-value {
                font-size: 1.15rem !important;
            }
        }

        @media (max-width: 991px) {
            .main-content {
                margin-left: 0;
                padding: 1rem;
                padding-bottom: 5rem;
            }

            .mobile-toggle-btn {
                display: inline-flex;
                align-items: center;
                justify-content: center;
            }

            .navbar-top {
                align-items: flex-start;
            }

            .sidebar {
                transform: translateX(-100%);
            }

            .sidebar.active {
                transform: translateX(0);
            }

            .sidebar.collapsed ~ .main-content {
                margin-left: 0;
            }

            .table-responsive {
                font-size: 0.86rem;
            }

            .stat-card {
                aspect-ratio: 1 / 1 !important;
                display: flex !important;
                flex-direction: column !important;
                justify-content: center !important;
                align-items: center !important;
                text-align: center !important;
                padding: 0.5rem !important;
                min-height: auto;
            }

            .stat-card .stat-icon {
                display: block !important;
                text-align: center !important;
                margin: 0 auto 0.3rem auto !important;
                font-size: 1.6rem !important;
            }

            .stat-card .stat-content {
                width: 100% !important;
                text-align: center !important;
            }

            .stat-card .stat-value {
                display: block !important;
                text-align: center !important;
                font-size: 1.2rem !important;
                line-height: 1.2 !important;
                margin: 0.2rem 0 !important;
                width: 100% !important;
            }

            .stat-card .stat-label {
                display: block !important;
                text-align: center !important;
                font-size: 0.7rem !important;
                width: 100% !important;
            }

            .stat-card .stat-sub {
                display: none !important;
            }
        }

        @media (max-width: 399px) {
            .stat-card {
                padding: 0.3rem !important;
            }

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
    </style>
</head>
<body>
    <div id="appPage">
        <div class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <h3>
                    <button class="desktop-toggle-btn" id="desktopToggleBtn"><i class="bi bi-list" id="toggleIcon"></i></button>
                    <img src="../Pictures/amgc3DLogo.png" alt="Logo" class="logo-icon">
                    <span class="nav-text">Global</span>
                </h3>
            </div>
            <div class="sidebar-menu">
                <ul class="nav flex-column">
                    <li class="nav-item"><a class="nav-link active" href="dashboard.php"><i class="bi bi-speedometer2"></i><span class="nav-text">Dashboard</span></a></li>
                    <li class="nav-item"><a class="nav-link" href="sales_reports.php"><i class="bi bi-graph-up"></i><span class="nav-text">Sales Reports</span></a></li>
                    <li class="nav-item"><a class="nav-link" href="branch_records.php"><i class="bi bi-file-text"></i><span class="nav-text">Branch Records</span></a></li>
                    <li class="nav-item"><a class="nav-link" href="all_items.php"><i class="bi bi-box"></i><span class="nav-text">All Items</span></a></li>
                    <li class="nav-item"><a class="nav-link" href="location_verification.php"><i class="bi bi-geo-alt-fill"></i><span class="nav-text">Location Verification</span></a></li>
                    <li class="nav-item"><a class="nav-link" href="drivers.php"><i class="bi bi-people"></i><span class="nav-text">User Management</span></a></li>
                    <li class="nav-item"><a class="nav-link" href="trip_tickets.php"><i class="bi bi-ticket-perforated"></i><span class="nav-text">Trip Tickets</span></a></li>
                    <li class="nav-item"><a class="nav-link" href="driver_tracking.php"><i class="bi bi-geo-alt"></i><span class="nav-text">Driver Tracking</span></a></li>
                    <hr class="sidebar-divider">
                </ul>
            </div>
            <div class="sidebar-footer">
                <div class="user-profile-sidebar">
                    <div class="user-avatar-sidebar"><?php echo htmlspecialchars($user_initials); ?></div>
                    <div class="user-details-sidebar"><span class="user-name-sidebar"><?php echo htmlspecialchars($user_name); ?></span></div>
                </div>
                <button class="logout-btn-sidebar" onclick="logout()"><i class="bi bi-box-arrow-right"></i><span class="logout-text">Logout</span></button>
            </div>
        </div>

        <div class="main-content" id="mainContent">
            <div class="navbar-top">
                <button class="mobile-toggle-btn" id="mobileToggleBtn"><i class="bi bi-list"></i></button>
                <div class="page-title">
                    <h2>Dashboard</h2>
                    <p>Sales, COGS, gross profit, expenses, and net profit summary for <?php echo htmlspecialchars($branch_name); ?></p>
                </div>
                <span class="period-pill"><i class="bi bi-calendar3"></i><?php echo htmlspecialchars($current['label']); ?></span>
            </div>

            <div class="row g-3 stat-card-row">
                <div class="col-xl col-md-6">
                    <div class="stat-card">
                        <div class="stat-icon"><i class="bi bi-cash-stack"></i></div>
                        <div class="stat-content">
                            <div class="stat-label">Sales</div>
                            <div class="stat-value"><?php echo money($current['sales']); ?></div>
                            <div class="stat-sub"><?php echo (int)$current['orders']; ?> order/s</div>
                        </div>
                    </div>
                </div>
                <div class="col-xl col-md-6">
                    <div class="stat-card">
                        <div class="stat-icon"><i class="bi bi-box-seam"></i></div>
                        <div class="stat-content">
                            <div class="stat-label">COGS</div>
                            <div class="stat-value"><?php echo money($current['cogs']); ?></div>
                            <div class="stat-sub">Cost of Goods Sold</div>
                        </div>
                    </div>
                </div>
                <div class="col-xl col-md-6">
                    <div class="stat-card">
                        <div class="stat-icon"><i class="bi bi-graph-up-arrow"></i></div>
                        <div class="stat-content">
                            <div class="stat-label">Gross Profit</div>
                            <div class="stat-value"><?php echo money($current['gross_profit']); ?></div>
                            <div class="stat-sub">Sales minus COGS</div>
                        </div>
                    </div>
                </div>
                <div class="col-xl col-md-6">
                    <div class="stat-card">
                        <div class="stat-icon"><i class="bi bi-receipt"></i></div>
                        <div class="stat-content">
                            <div class="stat-label">Expenses</div>
                            <div class="stat-value"><?php echo money($current['expenses']); ?></div>
                            <div class="stat-sub">Recorded expense withdrawals</div>
                        </div>
                    </div>
                </div>
                <div class="col-xl col-md-6">
                    <div class="stat-card">
                        <div class="stat-icon"><i class="bi bi-wallet2"></i></div>
                        <div class="stat-content">
                            <div class="stat-label">Net Profit</div>
                            <div class="stat-value"><?php echo money($current['net_profit']); ?></div>
                            <div class="stat-sub">Gross profit minus expenses</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="form-card">
                <h5><i class="bi bi-calendar-range"></i> Net Profit Overview</h5>
                <div class="row g-3">
                    <div class="col-md-4"><div class="summary-box"><div class="label">Daily Net Profit</div><div class="value <?php echo $daily['net_profit'] < 0 ? 'profit-negative' : 'profit-positive'; ?>"><?php echo money($daily['net_profit']); ?></div><div class="stat-sub"><?php echo htmlspecialchars($daily['label']); ?></div></div></div>
                    <div class="col-md-4"><div class="summary-box"><div class="label">Weekly Net Profit</div><div class="value <?php echo $weekly['net_profit'] < 0 ? 'profit-negative' : 'profit-positive'; ?>"><?php echo money($weekly['net_profit']); ?></div><div class="stat-sub"><?php echo htmlspecialchars($weekly['label']); ?></div></div></div>
                    <div class="col-md-4"><div class="summary-box"><div class="label">Monthly Net Profit</div><div class="value <?php echo $monthly['net_profit'] < 0 ? 'profit-negative' : 'profit-positive'; ?>"><?php echo money($monthly['net_profit']); ?></div><div class="stat-sub"><?php echo htmlspecialchars($monthly['label']); ?></div></div></div>
                </div>
            </div>
            
                        <form method="GET" class="form-card">
                <h5><i class="bi bi-funnel"></i> Dashboard Filter</h5>
                <div class="row g-3 align-items-end">
                    <div class="col-xl-2 col-md-4">
                        <label class="form-label fw-bold">Period</label>
                        <select name="period" id="periodFilter" class="form-select">
                            <option value="daily" <?php echo $active_period === 'daily' ? 'selected' : ''; ?>>Daily</option>
                            <option value="weekly" <?php echo $active_period === 'weekly' ? 'selected' : ''; ?>>Weekly</option>
                            <option value="monthly" <?php echo $active_period === 'monthly' ? 'selected' : ''; ?>>Monthly</option>
                            <option value="custom" <?php echo $active_period === 'custom' ? 'selected' : ''; ?>>Custom Date</option>
                        </select>
                    </div>
                    <div class="col-xl-2 col-md-4" id="singleDateGroup">
                        <label class="form-label fw-bold">Date</label>
                        <input type="date" name="date" class="form-control" value="<?php echo htmlspecialchars($selected_date); ?>">
                    </div>
                    <div class="col-xl-2 col-md-4" id="customStartGroup">
                        <label class="form-label fw-bold">From Date</label>
                        <input type="date" name="start_date" class="form-control" value="<?php echo htmlspecialchars($custom_start_date); ?>">
                    </div>
                    <div class="col-xl-2 col-md-4" id="customEndGroup">
                        <label class="form-label fw-bold">To Date</label>
                        <input type="date" name="end_date" class="form-control" value="<?php echo htmlspecialchars($custom_end_date); ?>">
                    </div>
                    <div class="col-xl-3 col-md-4">
                        <label class="form-label fw-bold">Business Unit</label>
                        <select name="business_unit" id="businessUnitFilter" class="form-select">
                            <option value="">All Business Units</option>
                            <?php foreach ($business_units as $business_unit): ?>
                                <option value="<?php echo htmlspecialchars($business_unit); ?>" <?php echo $selected_business_unit === $business_unit ? 'selected' : ''; ?>><?php echo htmlspecialchars($business_unit); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-xl-3 col-md-6">
                        <label class="form-label fw-bold">Branch</label>
                        <select name="branch_id" id="branchFilter" class="form-select" <?php echo (!$view_all_branches && $user_branch_id > 0) ? 'disabled' : ''; ?>>
                            <option value="0" data-business-unit="">All Branches</option>
                            <?php foreach ($branch_options as $branch_option): ?>
                                <option value="<?php echo (int)$branch_option['branch_id']; ?>" data-business-unit="<?php echo htmlspecialchars($branch_option['business_unit']); ?>" <?php echo $selected_branch_filter_id === (int)$branch_option['branch_id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($branch_option['branch_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (!$view_all_branches && $user_branch_id > 0): ?>
                            <input type="hidden" name="branch_id" value="<?php echo (int)$user_branch_id; ?>">
                        <?php endif; ?>
                    </div>
                    <div class="col-xl-2 col-md-6 d-grid">
                        <button type="submit" class="btn btn-amgc-primary"><i class="bi bi-search"></i> Apply Filter</button>
                    </div>
                </div>
            </form>

            <div class="data-table">
                <div class="table-header">
                    <h5><i class="bi bi-building"></i> Sales Summary Per Branch</h5>
                    <span class="period-pill"><i class="bi bi-calendar3"></i><?php echo htmlspecialchars($branch_sales_date_label); ?></span>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Business Unit</th>
                                <th>Branches</th>
                                <th>Sales</th>
                                <th>COGS</th>
                                <th>Gross Profit</th>
                                <th>Expenses</th>
                                <th>Net Profit</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($branch_sales_rows)): ?>
                                <tr><td colspan="7" class="text-center py-4 text-muted fw-bold">No branch sales summary found for the selected filter.</td></tr>
                            <?php else: ?>
                                <?php foreach ($branch_sales_rows as $row): ?>
                                    <tr>
                                        <td class="fw-bold text-success"><?php echo htmlspecialchars($row['business_unit']); ?></td>
                                        <td><?php echo htmlspecialchars($row['branch_name']); ?></td>
                                        <td><?php echo money($row['sales']); ?></td>
                                        <td><?php echo money($row['cogs']); ?></td>
                                        <td class="<?php echo (float)$row['gross_profit'] < 0 ? 'profit-negative' : 'profit-positive'; ?> fw-bold"><?php echo money($row['gross_profit']); ?></td>
                                        <td><?php echo money($row['expenses']); ?></td>
                                        <td class="<?php echo (float)$row['net_profit'] < 0 ? 'profit-negative' : 'profit-positive'; ?> fw-bold"><?php echo money($row['net_profit']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const sidebar = document.getElementById('sidebar');
        const desktopToggleBtn = document.getElementById('desktopToggleBtn');
        const mobileToggleBtn = document.getElementById('mobileToggleBtn');
        const mainContent = document.getElementById('mainContent');

        function applySidebarState() {
            if (window.innerWidth > 991) {
                const savedCollapsed = localStorage.getItem('sidebarCollapsed') === 'true';
                sidebar.classList.toggle('collapsed', savedCollapsed);
                mainContent.style.marginLeft = savedCollapsed ? '80px' : '250px';
                document.querySelectorAll('.nav-text, .logout-text').forEach(el => el.style.display = savedCollapsed ? 'none' : 'inline-block');
            } else {
                sidebar.classList.remove('collapsed');
                mainContent.style.marginLeft = '0';
                document.querySelectorAll('.nav-text, .logout-text').forEach(el => el.style.display = 'inline-block');
            }
        }

        desktopToggleBtn?.addEventListener('click', function() {
            sidebar.classList.toggle('collapsed');
            localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
            applySidebarState();
        });

        mobileToggleBtn?.addEventListener('click', function() {
            sidebar.classList.toggle('active');
            if (sidebar.classList.contains('active')) {
                const overlay = document.createElement('div');
                overlay.className = 'sidebar-overlay';
                overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.35);z-index:998;';
                overlay.onclick = () => { sidebar.classList.remove('active'); overlay.remove(); };
                document.body.appendChild(overlay);
            } else {
                document.querySelector('.sidebar-overlay')?.remove();
            }
        });

        window.addEventListener('resize', applySidebarState);
        document.addEventListener('DOMContentLoaded', applySidebarState);


        const periodFilter = document.getElementById('periodFilter');
        const singleDateGroup = document.getElementById('singleDateGroup');
        const customStartGroup = document.getElementById('customStartGroup');
        const customEndGroup = document.getElementById('customEndGroup');

        function toggleDateFilters() {
            const isCustom = periodFilter && periodFilter.value === 'custom';
            if (singleDateGroup) singleDateGroup.style.display = isCustom ? 'none' : '';
            if (customStartGroup) customStartGroup.style.display = isCustom ? '' : 'none';
            if (customEndGroup) customEndGroup.style.display = isCustom ? '' : 'none';
        }

        periodFilter?.addEventListener('change', toggleDateFilters);
        document.addEventListener('DOMContentLoaded', toggleDateFilters);

        const businessUnitFilter = document.getElementById('businessUnitFilter');
        const branchFilter = document.getElementById('branchFilter');

        function filterBranchOptions() {
            if (!businessUnitFilter || !branchFilter || branchFilter.disabled) return;

            const selectedBusinessUnit = businessUnitFilter.value;
            Array.from(branchFilter.options).forEach(option => {
                if (option.value === '0') {
                    option.hidden = false;
                    return;
                }

                const optionBusinessUnit = option.getAttribute('data-business-unit') || '';
                option.hidden = selectedBusinessUnit !== '' && optionBusinessUnit !== selectedBusinessUnit;
            });

            const selectedOption = branchFilter.options[branchFilter.selectedIndex];
            if (selectedOption && selectedOption.hidden) {
                branchFilter.value = '0';
            }
        }

        businessUnitFilter?.addEventListener('change', filterBranchOptions);
        document.addEventListener('DOMContentLoaded', filterBranchOptions);

        function logout() {
            Swal.fire({
                title: 'Logout?',
                text: 'Are you sure you want to logout?',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#047857',
                cancelButtonColor: '#6b7280',
                confirmButtonText: 'Yes, logout'
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = '../logout.php';
                }
            });
        }
    </script>
</body>
</html>
