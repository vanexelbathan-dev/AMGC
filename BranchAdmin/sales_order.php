<?php
require_once '../config/database.php';
require_once '../config/session_handler.php';

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

// Check if order_status enum needs 'in_transit' status
$check_enum = $conn->query("SHOW COLUMNS FROM sales_orders LIKE 'order_status'");
if ($check_enum && $check_enum->num_rows > 0) {
    $row = $check_enum->fetch_assoc();
    $enum_value = $row['Type'];
    if (strpos($enum_value, "in_transit") === false) {
        // Add 'in_transit' to the enum
        $alter_sql = "ALTER TABLE sales_orders MODIFY COLUMN order_status ENUM('pending','confirmed','processing','ready','in_transit','delivered','cancelled') DEFAULT 'pending'";
        $conn->query($alter_sql);
    }
}

// Get current user info and branch context
$user_id = $_SESSION['user_id'] ?? 0;
$user_name = isset($_SESSION['first_name']) ? $_SESSION['first_name'] . ' ' . $_SESSION['last_name'] : 'Branch Admin';
$user_role = $_SESSION['role'] ?? 'branch_admin';
$branch_id = $_SESSION['branch_id'] ?? 0;
$view_all_branches = $_SESSION['view_all_branches'] ?? false;


// Safe HTML escape helper to prevent PHP 8.1+ deprecated warnings when values are NULL.
if (!function_exists('amgc_h')) {
    function amgc_h($value) {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}


// ===== SI / TAX DETAILS SAFETY =====
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
        if (!amgcColumnExists($conn, $table, $column)) { @$conn->query("ALTER TABLE `$table` ADD COLUMN $definition"); }
    }
}
amgcAddColumnIfMissing($conn, 'sales_orders', 'si_number', 'si_number VARCHAR(50) DEFAULT NULL AFTER so_number');
amgcAddColumnIfMissing($conn, 'sales_orders', 'document_type', "document_type ENUM('SO','SI') NOT NULL DEFAULT 'SO' AFTER si_number");
amgcAddColumnIfMissing($conn, 'sales_orders', 'atw_no', 'atw_no VARCHAR(6) DEFAULT NULL AFTER document_type');
amgcAddColumnIfMissing($conn, 'sales_orders', 'gatepass_no', 'gatepass_no VARCHAR(6) DEFAULT NULL AFTER atw_no');
amgcAddColumnIfMissing($conn, 'sales_orders', 'registered_business_name', 'registered_business_name VARCHAR(255) DEFAULT NULL AFTER document_type');
amgcAddColumnIfMissing($conn, 'sales_orders', 'tin', 'tin VARCHAR(50) DEFAULT NULL AFTER registered_business_name');
amgcAddColumnIfMissing($conn, 'sales_orders', 'business_address', 'business_address TEXT DEFAULT NULL AFTER tin');
amgcAddColumnIfMissing($conn, 'invoices', 'si_number', 'si_number VARCHAR(50) DEFAULT NULL AFTER invoice_number');
amgcAddColumnIfMissing($conn, 'invoices', 'registered_business_name', 'registered_business_name VARCHAR(255) DEFAULT NULL AFTER si_number');
amgcAddColumnIfMissing($conn, 'invoices', 'tin', 'tin VARCHAR(50) DEFAULT NULL AFTER registered_business_name');
amgcAddColumnIfMissing($conn, 'invoices', 'business_address', 'business_address TEXT DEFAULT NULL AFTER tin');

// Branch name is needed by AJAX print actions before the later page-render section.
$branch_name = 'All Branches';
if (!$view_all_branches && (int)$branch_id > 0) {
    $branch_stmt_early = $conn->prepare("SELECT branch_name FROM branches WHERE branch_id = ? LIMIT 1");
    if ($branch_stmt_early) {
        $branch_stmt_early->bind_param('i', $branch_id);
        $branch_stmt_early->execute();
        $branch_result_early = $branch_stmt_early->get_result();
        if ($branch_row_early = $branch_result_early->fetch_assoc()) {
            $branch_name = $branch_row_early['branch_name'] ?? 'Branch ' . $branch_id;
        } else {
            $branch_name = 'Branch ' . $branch_id;
        }
        $branch_stmt_early->close();
    } else {
        $branch_name = 'Branch ' . $branch_id;
    }
}


// ===== ACCURATE SALES ORDER COMPUTATION SUPPORT =====
// FIXED: Approved discount from credit_discount_requests is saved at order header,
// then applied consistently to each sales_order_items row during view/export/recompute.
if (!function_exists('amgcColumnExists')) {
    function amgcColumnExists($conn, $table, $column) {
        $table = $conn->real_escape_string($table);
        $column = $conn->real_escape_string($column);
        $res = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
        return ($res && $res->num_rows > 0);
    }
}

function amgcAddColumnIfMissing($conn, $table, $column, $definition) {
    if (!amgcColumnExists($conn, $table, $column)) {
        @$conn->query("ALTER TABLE `$table` ADD COLUMN $definition");
    }
}

// Header/order computed + approved discount columns.
// FIXED: sales_orders in your current database has no discount_percent column, so create it before any query uses so.discount_percent.
amgcAddColumnIfMissing($conn, 'sales_orders', 'discount_percent', 'discount_percent DECIMAL(8,4) NOT NULL DEFAULT 0.0000 AFTER total_amount');
amgcAddColumnIfMissing($conn, 'sales_orders', 'discount_amount', 'discount_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER discount_percent');
amgcAddColumnIfMissing($conn, 'sales_orders', 'discount_calculation_type', "discount_calculation_type ENUM('percentage','amount_based') NOT NULL DEFAULT 'percentage' AFTER discount_amount");
amgcAddColumnIfMissing($conn, 'sales_orders', 'discount_based_amount', 'discount_based_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER discount_calculation_type');
amgcAddColumnIfMissing($conn, 'sales_orders', 'order_amount', 'order_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER discount_based_amount');
amgcAddColumnIfMissing($conn, 'sales_orders', 'total_discount_amount', 'total_discount_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER order_amount');
amgcAddColumnIfMissing($conn, 'sales_orders', 'cogs_amount', 'cogs_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER total_discount_amount');
amgcAddColumnIfMissing($conn, 'sales_orders', 'gross_profit_amount', 'gross_profit_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER cogs_amount');
// Used by Collections > Add Beginning Balance. These records must affect outstanding balance,
// but must not appear as regular Sales Orders in this page.
amgcAddColumnIfMissing($conn, 'sales_orders', 'fulfillment_type', "fulfillment_type VARCHAR(30) NOT NULL DEFAULT 'delivery' AFTER order_status");
// Approval tracking for customers who exceed their approved credit limit.
amgcAddColumnIfMissing($conn, 'sales_orders', 'beyond_credit_limit_allowed', 'beyond_credit_limit_allowed TINYINT(1) NOT NULL DEFAULT 0 AFTER fulfillment_type');
amgcAddColumnIfMissing($conn, 'sales_orders', 'beyond_credit_limit_explanation', 'beyond_credit_limit_explanation TEXT DEFAULT NULL AFTER beyond_credit_limit_allowed');
amgcAddColumnIfMissing($conn, 'sales_orders', 'beyond_credit_limit_acknowledged', 'beyond_credit_limit_acknowledged TINYINT(1) NOT NULL DEFAULT 0 AFTER beyond_credit_limit_explanation');
amgcAddColumnIfMissing($conn, 'sales_orders', 'beyond_credit_limit_allowed_by', 'beyond_credit_limit_allowed_by INT(11) DEFAULT NULL AFTER beyond_credit_limit_acknowledged');
amgcAddColumnIfMissing($conn, 'sales_orders', 'beyond_credit_limit_allowed_at', 'beyond_credit_limit_allowed_at DATETIME DEFAULT NULL AFTER beyond_credit_limit_allowed_by');
amgcAddColumnIfMissing($conn, 'sales_orders', 'beyond_credit_limit_snapshot', 'beyond_credit_limit_snapshot LONGTEXT DEFAULT NULL AFTER beyond_credit_limit_allowed_at');
// Approval tracking for customers with NO credit limit but with existing outstanding balance.
amgcAddColumnIfMissing($conn, 'sales_orders', 'outstanding_balance_approval_allowed', 'outstanding_balance_approval_allowed TINYINT(1) NOT NULL DEFAULT 0 AFTER beyond_credit_limit_snapshot');
amgcAddColumnIfMissing($conn, 'sales_orders', 'outstanding_balance_approval_explanation', 'outstanding_balance_approval_explanation TEXT DEFAULT NULL AFTER outstanding_balance_approval_allowed');
amgcAddColumnIfMissing($conn, 'sales_orders', 'outstanding_balance_approval_acknowledged', 'outstanding_balance_approval_acknowledged TINYINT(1) NOT NULL DEFAULT 0 AFTER outstanding_balance_approval_explanation');
amgcAddColumnIfMissing($conn, 'sales_orders', 'outstanding_balance_approval_by', 'outstanding_balance_approval_by INT(11) DEFAULT NULL AFTER outstanding_balance_approval_acknowledged');
amgcAddColumnIfMissing($conn, 'sales_orders', 'outstanding_balance_approval_at', 'outstanding_balance_approval_at DATETIME DEFAULT NULL AFTER outstanding_balance_approval_by');
amgcAddColumnIfMissing($conn, 'sales_orders', 'outstanding_balance_approval_snapshot', 'outstanding_balance_approval_snapshot LONGTEXT DEFAULT NULL AFTER outstanding_balance_approval_at');

// Line/item computed columns. Item/customer details still come from existing tables.
amgcAddColumnIfMissing($conn, 'sales_order_items', 'gross_price', 'gross_price DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER quantity_delivered');
amgcAddColumnIfMissing($conn, 'sales_order_items', 'discount_type', "discount_type ENUM('computed','percentage','peso') NOT NULL DEFAULT 'computed' AFTER gross_price");
amgcAddColumnIfMissing($conn, 'sales_order_items', 'discount_value', 'discount_value DECIMAL(12,4) NOT NULL DEFAULT 0.0000 AFTER discount_type');
amgcAddColumnIfMissing($conn, 'sales_order_items', 'discount_amount', 'discount_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER discount_value');
amgcAddColumnIfMissing($conn, 'sales_order_items', 'net_price', 'net_price DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER discount_amount');
amgcAddColumnIfMissing($conn, 'sales_order_items', 'order_amount', 'order_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER net_price');
amgcAddColumnIfMissing($conn, 'sales_order_items', 'total_discount', 'total_discount DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER order_amount');
amgcAddColumnIfMissing($conn, 'sales_order_items', 'ave_cost', 'ave_cost DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER total_discount');
amgcAddColumnIfMissing($conn, 'sales_order_items', 'cogs_amount', 'cogs_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER ave_cost');
amgcAddColumnIfMissing($conn, 'sales_order_items', 'gross_profit', 'gross_profit DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER cogs_amount');

// Backfill only missing values. Do not overwrite rows that already have saved gross/net discount values.
$conn->query("UPDATE sales_order_items SET net_price = unit_price WHERE (net_price = 0 OR net_price IS NULL) AND unit_price > 0");
$conn->query("UPDATE sales_order_items SET gross_price = unit_price WHERE (gross_price = 0 OR gross_price IS NULL) AND unit_price > 0");

function amgcCalculateSalesLine($qty, $grossPrice, $discountType, $discountValue, $netPrice, $aveCost) {
    $qty = (float)$qty;
    $grossPrice = (float)$grossPrice;
    $discountValue = (float)$discountValue;
    $netPrice = (float)$netPrice;
    $aveCost = (float)$aveCost;
    $discountType = strtolower(trim((string)$discountType));

    if ($grossPrice <= 0 && $netPrice > 0) {
        $grossPrice = $netPrice;
    }

    if ($discountType === 'percentage') {
        $rate = ($discountValue > 1) ? ($discountValue / 100) : $discountValue;
        $rate = max(0, min(1, $rate));
        $discountPerUnit = $grossPrice * $rate;
        $netPrice = max(0, $grossPrice - $discountPerUnit);
    } elseif ($discountType === 'peso') {
        // peso means discount_value is the NET peso price per unit.
        $discountPerUnit = max(0, $grossPrice - $discountValue);
        $netPrice = max(0, $discountValue);
    } else {
        // computed means gross_price and net_price are already the source of truth.
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
        'gross_profit' => $grossProfit,
        'discount_per_unit' => $discountPerUnit
    ];
}

function amgcSyncSalesOrderComputedSnapshots($conn, $specific_so_id = null) {
    $where = "WHERE 1=1";
    if ($specific_so_id !== null) {
        $where .= " AND so.so_id = " . (int)$specific_so_id;
    }

    $sql = "
        SELECT
            so.so_id,
            so.created_at,
            so.order_date,
            so.so_number,
            so.created_by,
            COALESCE(so.discount_percent, 0) AS header_discount_percent,
            COALESCE(so.discount_amount, 0) AS header_discount_amount,
            COALESCE(so.discount_calculation_type, 'percentage') AS header_discount_calculation_type,
            COALESCE(so.discount_based_amount, 0) AS header_discount_based_amount,
            (
                SELECT COALESCE(SUM(soi2.quantity_ordered * COALESCE(NULLIF(soi2.gross_price, 0), NULLIF(soi2.unit_price, 0), NULLIF(soi2.net_price, 0), 0)), 0)
                FROM sales_order_items soi2
                WHERE soi2.so_id = so.so_id
            ) AS order_gross_subtotal,
            c.customer_code,
            c.store_name,
            COALESCE(c.customer_name, '') as customer_name,
            CONCAT(COALESCE(u.first_name, ''), CASE WHEN u.last_name IS NOT NULL AND u.last_name != '' THEN CONCAT(' ', u.last_name) ELSE '' END) AS encoded_by,
            soi.so_item_id,
            soi.item_id,
            COALESCE(i.item_code, '') as item_code,
            i.item_name AS item_description,
            soi.unit_type,
            soi.quantity_ordered,
            COALESCE(NULLIF(soi.gross_price, 0), (
                SELECT iup.unit_price
                FROM item_unit_pricing iup
                LEFT JOIN unit_types utp ON iup.unit_type_id = utp.unit_type_id
                WHERE iup.item_id = soi.item_id
                  AND LOWER(TRIM(utp.unit_type_name)) = LOWER(TRIM(soi.unit_type))
                  AND (iup.effective_date IS NULL OR iup.effective_date <= DATE(COALESCE(so.created_at, so.order_date, NOW())))
                  AND (iup.effective_until IS NULL OR iup.effective_until >= DATE(COALESCE(so.created_at, so.order_date, NOW())))
                ORDER BY COALESCE(iup.effective_date, '1900-01-01') DESC, iup.pricing_id DESC
                LIMIT 1
            ), COALESCE(NULLIF(soi.unit_price, 0), NULLIF(soi.net_price, 0), 0)) AS gross_price,
            COALESCE(soi.discount_type, 'computed') AS discount_type,
            COALESCE(soi.discount_value, 0) AS discount_value,
            COALESCE(NULLIF(soi.net_price, 0), soi.unit_price, 0) AS net_price,
            COALESCE(NULLIF(soi.ave_cost, 0), (
                SELECT AVG(iui.unit_cost)
                FROM item_unit_inventory iui
                LEFT JOIN unit_types utc ON iui.unit_type_id = utc.unit_type_id
                WHERE iui.item_id = soi.item_id
                  AND LOWER(TRIM(utc.unit_type_name)) = LOWER(TRIM(soi.unit_type))
                  AND DATE(iui.updated_at) BETWEEN DATE_SUB(DATE(COALESCE(so.created_at, so.order_date, NOW())), INTERVAL 30 DAY) AND DATE(COALESCE(so.created_at, so.order_date, NOW()))
            ), (
                SELECT AVG(iui2.unit_cost)
                FROM item_unit_inventory iui2
                LEFT JOIN unit_types utc2 ON iui2.unit_type_id = utc2.unit_type_id
                WHERE iui2.item_id = soi.item_id
                  AND LOWER(TRIM(utc2.unit_type_name)) = LOWER(TRIM(soi.unit_type))
            ), 0) AS ave_cost
        FROM sales_orders so
        JOIN customers c ON so.customer_id = c.customer_id
        JOIN sales_order_items soi ON so.so_id = soi.so_id
        JOIN items i ON soi.item_id = i.item_id
        LEFT JOIN users u ON so.created_by = u.user_id
        $where
    ";

    $res = $conn->query($sql);
    if (!$res) {
        error_log('amgcSyncSalesOrderComputedSnapshots query error: ' . $conn->error);
        return false;
    }

    $line_update = $conn->prepare("UPDATE sales_order_items SET
        gross_price = ?,
        discount_type = ?,
        discount_value = ?,
        discount_amount = ?,
        net_price = ?,
        order_amount = ?,
        total_discount = ?,
        ave_cost = ?,
        cogs_amount = ?,
        gross_profit = ?
        WHERE so_item_id = ?");

    $orderTotals = [];

    while ($row = $res->fetch_assoc()) {
        $qty = (float)($row['quantity_ordered'] ?? 0);
        $grossPrice = (float)($row['gross_price'] ?? 0);
        $grossSubtotal = (float)($row['order_gross_subtotal'] ?? 0);
        $headerType = strtolower(trim((string)($row['header_discount_calculation_type'] ?? 'percentage')));
        $headerPercent = (float)($row['header_discount_percent'] ?? 0);
        $headerDiscountAmount = (float)($row['header_discount_amount'] ?? 0);
        $headerBasedAmount = (float)($row['header_discount_based_amount'] ?? 0);

        // Source of truth: approved discount saved on sales_orders header.
        // This prevents branch-admin recompute/export from changing approved discounts.
        $effectiveDiscountType = strtolower((string)($row['discount_type'] ?? 'computed'));
        $effectiveDiscountValue = (float)($row['discount_value'] ?? 0);
        $effectiveNetPrice = (float)($row['net_price'] ?? 0);

        if ($headerType === 'percentage' && $headerPercent > 0) {
            $effectiveDiscountType = 'percentage';
            $effectiveDiscountValue = $headerPercent;
            $effectiveNetPrice = max(0, $grossPrice - ($grossPrice * (min(100, max(0, $headerPercent)) / 100)));
        } elseif ($headerType === 'amount_based' && ($headerDiscountAmount > 0 || $headerBasedAmount > 0)) {
            $fixedHeaderDiscount = $headerDiscountAmount > 0 ? $headerDiscountAmount : $headerBasedAmount;
            $lineGrossTotal = $grossPrice * $qty;
            $lineDiscountTotal = $grossSubtotal > 0 ? ($fixedHeaderDiscount * ($lineGrossTotal / $grossSubtotal)) : 0;
            $lineDiscountTotal = max(0, min($lineGrossTotal, $lineDiscountTotal));
            $discountPerUnit = $qty > 0 ? ($lineDiscountTotal / $qty) : 0;
            $effectiveDiscountType = 'computed';
            $effectiveDiscountValue = $discountPerUnit;
            $effectiveNetPrice = max(0, $grossPrice - $discountPerUnit);
        }

        $calc = amgcCalculateSalesLine(
            $qty,
            $grossPrice,
            $effectiveDiscountType,
            $effectiveDiscountValue,
            $effectiveNetPrice,
            $row['ave_cost']
        );

        $so_id = (int)$row['so_id'];
        if (!isset($orderTotals[$so_id])) {
            $orderTotals[$so_id] = [
                'order_amount' => 0,
                'total_discount' => 0,
                'cogs' => 0,
                'gross_profit' => 0
            ];
        }

        $orderTotals[$so_id]['order_amount'] += $calc['order_amount'];
        $orderTotals[$so_id]['total_discount'] += $calc['total_discount'];
        $orderTotals[$so_id]['cogs'] += $calc['cogs'];
        $orderTotals[$so_id]['gross_profit'] += $calc['gross_profit'];

        if ($line_update) {
            $soItemId = (int)$row['so_item_id'];
            $discountAmountPerUnit = (float)$calc['discount_per_unit'];
            $gross = (float)$calc['gross_price'];
            $discType = $effectiveDiscountType;
            $discValue = (float)$calc['discount'];
            $net = (float)$calc['net_price'];
            $orderAmount = (float)$calc['order_amount'];
            $totalDiscount = (float)$calc['total_discount'];
            $aveCost = (float)$calc['ave_cost'];
            $cogs = (float)$calc['cogs'];
            $grossProfit = (float)$calc['gross_profit'];

            $line_update->bind_param(
                "dsddddddddi",
                $gross,
                $discType,
                $discValue,
                $discountAmountPerUnit,
                $net,
                $orderAmount,
                $totalDiscount,
                $aveCost,
                $cogs,
                $grossProfit,
                $soItemId
            );
            $line_update->execute();
        }
    }

    if ($line_update) {
        $line_update->close();
    }

    $order_update = $conn->prepare("UPDATE sales_orders SET
        total_amount = ?,
        order_amount = ?,
        total_discount_amount = ?,
        discount_amount = ?,
        cogs_amount = ?,
        gross_profit_amount = ?
        WHERE so_id = ?");

    if ($order_update) {
        foreach ($orderTotals as $so_id => $totals) {
            $orderAmount = (float)$totals['order_amount'];
            $totalDiscount = (float)$totals['total_discount'];
            $cogs = (float)$totals['cogs'];
            $grossProfit = (float)$totals['gross_profit'];
            $id = (int)$so_id;

            $order_update->bind_param(
                "ddddddi",
                $orderAmount,
                $orderAmount,
                $totalDiscount,
                $totalDiscount,
                $cogs,
                $grossProfit,
                $id
            );
            $order_update->execute();
        }
        $order_update->close();
    }

    return true;
}

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


// Motorpool registered vehicles are the only valid delivery vehicles.
// The legacy `vehicles` table is kept for old data, but new confirmation dropdowns use `motorpool_vehicles`.
$motorpool_vehicles_table_exists = false;
$check_motorpool_vehicles = $conn->query("SHOW TABLES LIKE 'motorpool_vehicles'");
if ($check_motorpool_vehicles && $check_motorpool_vehicles->num_rows > 0) {
    $motorpool_vehicles_table_exists = true;
}

$motorpool_vehicle_has_status = false;
$check_motorpool_vehicle_status = $conn->query("SHOW COLUMNS FROM motorpool_vehicles LIKE 'status'");
if ($check_motorpool_vehicle_status && $check_motorpool_vehicle_status->num_rows > 0) {
    $motorpool_vehicle_has_status = true;
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

// Hide beginning balance records from the Sales Order table/reports.
// They are still counted in customer outstanding balance through invoices/recalcCustomerCreditUsed().
$hide_beginning_balance_orders_condition = "AND LOWER(TRIM(COALESCE(so.fulfillment_type, ''))) <> 'beginning_balance'";

$customers_branch_condition = "";
if ($customers_branch_column_exists && !$view_all_branches) {
    $customers_branch_condition = "AND branch_id = $branch_id";
}

$drivers_branch_condition = "";
if ($drivers_branch_column_exists && !$view_all_branches) {
    $drivers_branch_condition = "AND branch_id = $branch_id";
}


// ========== ROLLING AS DELIVERY DRIVER SUPPORT ==========
// Branch Admin can assign a Rolling account as a delivery driver. When selected,
// stock is deducted from the Rolling account's received inventory, not from Branch Admin stock.
function amgcGetUserColumns(mysqli $conn): array {
    static $cols = null;
    if ($cols !== null) return $cols;
    $cols = [];
    $res = $conn->query("SHOW COLUMNS FROM users");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $cols[] = $row['Field'];
        }
    }
    return $cols;
}

function amgcEnsureDriverRollingColumns(mysqli $conn): void {
    if (!amgcColumnExists($conn, 'drivers', 'user_id')) {
        @$conn->query("ALTER TABLE drivers ADD COLUMN user_id INT(11) DEFAULT NULL AFTER driver_name");
    }
    if (!amgcColumnExists($conn, 'drivers', 'is_rolling_driver')) {
        @$conn->query("ALTER TABLE drivers ADD COLUMN is_rolling_driver TINYINT(1) NOT NULL DEFAULT 0 AFTER user_id");
    }
}

function amgcBuildRollingUsersBranchCondition(mysqli $conn, int $branch_id): string {
    $cols = amgcGetUserColumns($conn);
    $parts = [];
    if (in_array('rolling_branch_id', $cols, true)) $parts[] = "COALESCE(u.rolling_branch_id, 0) = " . (int)$branch_id;
    if (in_array('branch_id', $cols, true)) $parts[] = "COALESCE(u.branch_id, 0) = " . (int)$branch_id;
    return !empty($parts) ? ' AND (' . implode(' OR ', $parts) . ')' : '';
}

function amgcGetRollingUsersForDriverSelect(mysqli $conn, int $branch_id): array {
    // Make sure optional driver-link columns exist before using them.
    // If the host blocks ALTER, the query below still falls back safely.
    amgcEnsureDriverRollingColumns($conn);
    $cols = amgcGetUserColumns($conn);
    if (!in_array('user_id', $cols, true) || !in_array('role', $cols, true)) return [];

    $nameExpr = "TRIM(CONCAT(COALESCE(u.first_name,''), ' ', COALESCE(u.last_name,'')))";
    if (!in_array('first_name', $cols, true) || !in_array('last_name', $cols, true)) {
        if (in_array('full_name', $cols, true)) {
            $nameExpr = "COALESCE(u.full_name, '')";
        } elseif (in_array('username', $cols, true)) {
            $nameExpr = "COALESCE(u.username, '')";
        } elseif (in_array('email', $cols, true)) {
            $nameExpr = "COALESCE(u.email, '')";
        } else {
            $nameExpr = "CONCAT('Rolling User #', u.user_id)";
        }
    }

    $statusCondition = '';
    if (in_array('status', $cols, true)) {
        $statusCondition = " AND (u.status IS NULL OR LOWER(TRIM(u.status)) IN ('active','enabled','approved'))";
    }
    $branchCondition = amgcBuildRollingUsersBranchCondition($conn, $branch_id);

    $sql = "SELECT u.user_id, $nameExpr AS rolling_name
            FROM users u
            WHERE LOWER(TRIM(u.role)) IN ('rolling', 'rolling_account')
            $statusCondition
            $branchCondition
            ORDER BY rolling_name ASC, u.user_id ASC";
    $res = $conn->query($sql);
    if (!$res) return [];

    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $rollingUserId = (int)($row['user_id'] ?? 0);
        if ($rollingUserId <= 0) continue;
        $rollingName = trim((string)($row['rolling_name'] ?? ''));
        if ($rollingName === '') $rollingName = 'Rolling User #' . $rollingUserId;

        $pending = 0;
        $driverUserCondition = amgcColumnExists($conn, 'drivers', 'user_id') ? "COALESCE(d.user_id, 0) = $rollingUserId" : "1=0";
        $driverRollingCondition = amgcColumnExists($conn, 'drivers', 'is_rolling_driver') ? "AND COALESCE(d.is_rolling_driver, 0) = 1" : "";
        $pendingSql = "SELECT COUNT(*) AS cnt
                       FROM pick_lists pl
                       JOIN sales_orders so ON so.so_id = pl.so_id
                       JOIN drivers d ON d.driver_id = pl.driver_id
                       WHERE $driverUserCondition
                         $driverRollingCondition
                         AND so.order_status IN ('confirmed','processing','ready','in_transit')
                         AND pl.pick_status NOT IN ('completed','cancelled')";
        $pendingRes = $conn->query($pendingSql);
        if ($pendingRes && $p = $pendingRes->fetch_assoc()) {
            $pending = (int)($p['cnt'] ?? 0);
        }

        $rows[] = [
            'driver_id' => 'rolling:' . $rollingUserId,
            'driver_value' => 'rolling:' . $rollingUserId,
            'driver_name' => $rollingName . ' (Rolling)',
            'pending_deliveries' => $pending,
            'active_trips' => 0,
            'driver_type' => 'rolling',
            'rolling_user_id' => $rollingUserId,
            'status' => 'active'
        ];
    }
    return $rows;
}

function amgcParseSelectedDriver($selected_driver_raw): array {
    $raw = trim((string)$selected_driver_raw);
    if (stripos($raw, 'rolling:') === 0) {
        return ['type' => 'rolling', 'id' => (int)substr($raw, 8), 'raw' => $raw];
    }
    if (stripos($raw, 'driver:') === 0) {
        return ['type' => 'driver', 'id' => (int)substr($raw, 7), 'raw' => $raw];
    }
    return ['type' => 'driver', 'id' => (int)$raw, 'raw' => $raw];
}

function amgcEnsureRollingDriverRow(mysqli $conn, int $rolling_user_id, int $branch_id): array {
    if ($rolling_user_id <= 0) throw new Exception('Invalid Rolling driver selected.');
    amgcEnsureDriverRollingColumns($conn);

    $cols = amgcGetUserColumns($conn);
    $nameExpr = "TRIM(CONCAT(COALESCE(first_name,''), ' ', COALESCE(last_name,'')))";
    if (!in_array('first_name', $cols, true) || !in_array('last_name', $cols, true)) {
        if (in_array('full_name', $cols, true)) $nameExpr = "COALESCE(full_name, '')";
        elseif (in_array('username', $cols, true)) $nameExpr = "COALESCE(username, '')";
        elseif (in_array('email', $cols, true)) $nameExpr = "COALESCE(email, '')";
        else $nameExpr = "CONCAT('Rolling User #', user_id)";
    }

    $stmt = $conn->prepare("SELECT user_id, $nameExpr AS rolling_name FROM users WHERE user_id = ? AND LOWER(TRIM(role)) IN ('rolling','rolling_account') LIMIT 1");
    if (!$stmt) throw new Exception('Unable to validate selected Rolling driver.');
    $stmt->bind_param('i', $rolling_user_id);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$user) throw new Exception('Selected Rolling account was not found or is not a Rolling user.');

    $driverName = trim((string)($user['rolling_name'] ?? ''));
    if ($driverName === '') $driverName = 'Rolling User #' . $rolling_user_id;

    $findSql = null;
    if (amgcColumnExists($conn, 'drivers', 'user_id')) {
        $findSql = "SELECT driver_id, driver_name FROM drivers WHERE user_id = ?" . (amgcColumnExists($conn, 'drivers', 'is_rolling_driver') ? " AND is_rolling_driver = 1" : "") . " LIMIT 1";
    }
    $find = $findSql ? $conn->prepare($findSql) : false;
    if ($find) {
        $find->bind_param('i', $rolling_user_id);
        $find->execute();
        $existing = $find->get_result()->fetch_assoc();
        $find->close();
        if ($existing) {
            $driver_id = (int)$existing['driver_id'];
            $updateParts = ["driver_name = '" . $conn->real_escape_string($driverName) . "'"];
            if (amgcColumnExists($conn, 'drivers', 'status')) $updateParts[] = "status = 'active'";
            if (amgcColumnExists($conn, 'drivers', 'branch_id')) $updateParts[] = "branch_id = " . (int)$branch_id;
            if (amgcColumnExists($conn, 'drivers', 'is_rolling_driver')) $updateParts[] = "is_rolling_driver = 1";
            @$conn->query("UPDATE drivers SET " . implode(', ', $updateParts) . " WHERE driver_id = " . $driver_id);
            return ['driver_id' => $driver_id, 'driver_name' => $driverName . ' (Rolling)', 'rolling_user_id' => $rolling_user_id];
        }
    }

    $driverCols = [];
    $driverColsRes = $conn->query("SHOW COLUMNS FROM drivers");
    if ($driverColsRes) {
        while ($c = $driverColsRes->fetch_assoc()) $driverCols[] = $c['Field'];
    }

    $fields = [];
    $placeholders = [];
    $types = '';
    $values = [];
    $add = function($field, $type, $value) use (&$fields, &$placeholders, &$types, &$values) {
        $fields[] = $field;
        $placeholders[] = '?';
        $types .= $type;
        $values[] = $value;
    };

    if (in_array('driver_name', $driverCols, true)) $add('driver_name', 's', $driverName);
    if (in_array('user_id', $driverCols, true)) $add('user_id', 'i', $rolling_user_id);
    if (in_array('is_rolling_driver', $driverCols, true)) $add('is_rolling_driver', 'i', 1);
    if (in_array('branch_id', $driverCols, true)) $add('branch_id', 'i', $branch_id);
    if (in_array('status', $driverCols, true)) $add('status', 's', 'active');
    if (in_array('driver_code', $driverCols, true)) $add('driver_code', 's', 'ROLL-' . $rolling_user_id);
    if (in_array('contact_number', $driverCols, true)) $add('contact_number', 's', '');
    if (in_array('vehicle_type', $driverCols, true)) $add('vehicle_type', 's', 'Rolling');
    if (in_array('vehicle_plate_number', $driverCols, true)) $add('vehicle_plate_number', 's', 'ROLLING');
    if (in_array('created_at', $driverCols, true)) {
        $fields[] = 'created_at';
        $placeholders[] = 'NOW()';
    }

    if (empty($fields)) throw new Exception('Drivers table has no compatible columns for Rolling assignment.');

    $sql = "INSERT INTO drivers (`" . implode('`,`', $fields) . "`) VALUES (" . implode(',', $placeholders) . ")";
    $stmt = $conn->prepare($sql);
    if (!$stmt) throw new Exception('Unable to create Rolling driver record: ' . $conn->error);
    if ($types !== '') $stmt->bind_param($types, ...$values);
    if (!$stmt->execute()) throw new Exception('Failed to create Rolling driver record: ' . $stmt->error);
    $driver_id = (int)$conn->insert_id;
    $stmt->close();

    return ['driver_id' => $driver_id, 'driver_name' => $driverName . ' (Rolling)', 'rolling_user_id' => $rolling_user_id];
}

function amgcResolveOrderItemUnitTypeId(mysqli $conn, int $item_id, string $unit_type_name): int {
    $unit_type_name = trim($unit_type_name);
    if ($item_id <= 0 || $unit_type_name === '') return 0;
    $stmt = $conn->prepare("SELECT ut.unit_type_id
        FROM item_unit_pricing iup
        JOIN unit_types ut ON ut.unit_type_id = iup.unit_type_id
        WHERE iup.item_id = ? AND LOWER(TRIM(ut.unit_type_name)) = LOWER(TRIM(?))
        ORDER BY ut.is_default_uom DESC, ut.unit_type_id ASC
        LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('is', $item_id, $unit_type_name);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) return (int)$row['unit_type_id'];
    }
    $stmt = $conn->prepare("SELECT unit_type_id FROM unit_types WHERE LOWER(TRIM(unit_type_name)) = LOWER(TRIM(?)) AND status = 'active' LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('s', $unit_type_name);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) return (int)$row['unit_type_id'];
    }
    return 0;
}

function amgcGetRollingAvailableQty(mysqli $conn, int $rolling_user_id, int $branch_id, int $item_id, int $unit_type_id, string $unit_type_name): float {
    $stmt = $conn->prepare("SELECT COALESCE(SUM(riti.quantity_received), 0) AS qty
        FROM rolling_inventory_transfer_items riti
        JOIN rolling_inventory_transfers rit ON rit.transfer_id = riti.transfer_id
        WHERE rit.received_by = ?
          AND rit.rolling_branch_id = ?
          AND riti.item_id = ?
          AND (riti.unit_type_id = ? OR LOWER(TRIM(COALESCE(riti.unit_type_name,''))) = LOWER(TRIM(?)))");
    if (!$stmt) return 0.0;
    $stmt->bind_param('iiiis', $rolling_user_id, $branch_id, $item_id, $unit_type_id, $unit_type_name);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (float)($row['qty'] ?? 0);
}

function amgcDeductRollingStock(mysqli $conn, int $rolling_user_id, int $branch_id, int $item_id, int $unit_type_id, string $unit_type_name, float $qty_to_deduct): void {
    $remaining = abs($qty_to_deduct);
    if ($remaining <= 0) return;

    $stmt = $conn->prepare("SELECT riti.transfer_item_id, riti.quantity_received
        FROM rolling_inventory_transfer_items riti
        JOIN rolling_inventory_transfers rit ON rit.transfer_id = riti.transfer_id
        WHERE rit.received_by = ?
          AND rit.rolling_branch_id = ?
          AND riti.item_id = ?
          AND (riti.unit_type_id = ? OR LOWER(TRIM(COALESCE(riti.unit_type_name,''))) = LOWER(TRIM(?)))
          AND COALESCE(riti.quantity_received, 0) > 0
        ORDER BY rit.receive_date ASC, riti.transfer_item_id ASC");
    if (!$stmt) throw new Exception('Unable to prepare Rolling inventory deduction.');
    $stmt->bind_param('iiiis', $rolling_user_id, $branch_id, $item_id, $unit_type_id, $unit_type_name);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    foreach ($rows as $row) {
        if ($remaining <= 0) break;
        $transfer_item_id = (int)$row['transfer_item_id'];
        $current = (float)$row['quantity_received'];
        $take = min($current, $remaining);
        $newQty = $current - $take;
        $upd = $conn->prepare("UPDATE rolling_inventory_transfer_items SET quantity_received = ? WHERE transfer_item_id = ?");
        if (!$upd) throw new Exception('Unable to update Rolling inventory row.');
        $upd->bind_param('di', $newQty, $transfer_item_id);
        if (!$upd->execute()) throw new Exception('Failed to deduct Rolling inventory: ' . $upd->error);
        $upd->close();
        $remaining -= $take;
    }

    if ($remaining > 0.0001) {
        throw new Exception('Rolling inventory deduction was incomplete. Please check received stock.');
    }
}

function amgcCheckRollingStockForOrder(mysqli $conn, int $so_id, int $rolling_user_id, int $branch_id): array {
    $items_stmt = $conn->prepare("SELECT soi.item_id, soi.quantity_ordered, soi.unit_type, COALESCE(i.item_code,'') AS item_code, i.item_name
        FROM sales_order_items soi
        JOIN items i ON i.item_id = soi.item_id
        WHERE soi.so_id = ?");
    if (!$items_stmt) return [];
    $items_stmt->bind_param('i', $so_id);
    $items_stmt->execute();
    $items = $items_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $items_stmt->close();

    $insufficient = [];
    foreach ($items as $item) {
        $item_id = (int)$item['item_id'];
        $qty = (float)$item['quantity_ordered'];
        $unit_type_name = trim((string)($item['unit_type'] ?? ''));
        $unit_type_id = amgcResolveOrderItemUnitTypeId($conn, $item_id, $unit_type_name);
        $available = amgcGetRollingAvailableQty($conn, $rolling_user_id, $branch_id, $item_id, $unit_type_id, $unit_type_name);
        if ($available + 0.0001 < $qty) {
            $insufficient[] = [
                'item_code' => $item['item_code'],
                'item_name' => $item['item_name'],
                'unit_type' => $unit_type_name,
                'required' => $qty,
                'available' => $available
            ];
        }
    }
    return $insufficient;
}


function amgcSalesOrderStockAlreadyDeducted(mysqli $conn, int $so_id): bool {
    if ($so_id <= 0) return false;

    $itExists = $conn->query("SHOW TABLES LIKE 'inventory_transactions'");
    if (!$itExists || $itExists->num_rows === 0) {
        return false;
    }

    $stmt = $conn->prepare("SELECT COUNT(*) AS cnt
        FROM inventory_transactions
        WHERE reference_id = ?
          AND reference_type IN ('sales_order', 'rolling_delivery_sales_order')
          AND transaction_type = 'out'
          AND quantity_changed < 0");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('i', $so_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return ((int)($row['cnt'] ?? 0)) > 0;
}

function amgcDeductBranchAdminUnitStock(mysqli $conn, int $branch_id, int $item_id, int $unit_type_id, string $unit_type_name, float $qty_to_deduct, int $so_id, int $created_by): void {
    $qty_to_deduct = abs((float)$qty_to_deduct);
    if ($qty_to_deduct <= 0) return;

    $current_inventory = null;
    $inventory_id = 0;

    if ($unit_type_id > 0) {
        $stmt = $conn->prepare("SELECT iui.inventory_id, COALESCE(iui.current_inventory, 0) AS current_inventory
            FROM item_unit_inventory iui
            JOIN items i ON i.item_id = iui.item_id
            WHERE iui.item_id = ?
              AND iui.unit_type_id = ?
              AND (? <= 0 OR i.branch_id = ?)
            LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('iiii', $item_id, $unit_type_id, $branch_id, $branch_id);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row) {
                $inventory_id = (int)$row['inventory_id'];
                $current_inventory = (float)$row['current_inventory'];
            }
        }
    }

    if (!$inventory_id && $unit_type_name !== '') {
        $stmt = $conn->prepare("SELECT iui.inventory_id, COALESCE(iui.current_inventory, 0) AS current_inventory
            FROM item_unit_inventory iui
            JOIN unit_types ut ON ut.unit_type_id = iui.unit_type_id
            JOIN items i ON i.item_id = iui.item_id
            WHERE iui.item_id = ?
              AND LOWER(TRIM(ut.unit_type_name)) = LOWER(TRIM(?))
              AND (? <= 0 OR i.branch_id = ?)
            LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('isii', $item_id, $unit_type_name, $branch_id, $branch_id);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row) {
                $inventory_id = (int)$row['inventory_id'];
                $current_inventory = (float)$row['current_inventory'];
            }
        }
    }

    if ($inventory_id > 0) {
        $new_inventory = ((float)$current_inventory) - $qty_to_deduct;
        $upd = $conn->prepare("UPDATE item_unit_inventory SET current_inventory = ?, updated_at = NOW() WHERE inventory_id = ?");
        if (!$upd) throw new Exception('Unable to prepare Branch Admin unit stock deduction.');
        $upd->bind_param('di', $new_inventory, $inventory_id);
        if (!$upd->execute()) throw new Exception('Failed to deduct Branch Admin unit stock: ' . $upd->error);
        $upd->close();
    }

    // Keep legacy item stock summary in sync as fallback/summary. Negative stock is allowed by your current sales-order flow.
    $stock_stmt = $conn->prepare("SELECT COALESCE(stock, 0) AS stock FROM items WHERE item_id = ? LIMIT 1");
    if ($stock_stmt) {
        $stock_stmt->bind_param('i', $item_id);
        $stock_stmt->execute();
        $stock_row = $stock_stmt->get_result()->fetch_assoc();
        $stock_stmt->close();
        $item_stock = (float)($stock_row['stock'] ?? 0);
        $new_stock = $item_stock - $qty_to_deduct;
        $upd_item = $conn->prepare("UPDATE items SET stock = ?, updated_at = NOW() WHERE item_id = ?");
        if ($upd_item) {
            $upd_item->bind_param('di', $new_stock, $item_id);
            $upd_item->execute();
            $upd_item->close();
        }
    }

    $itExists = $conn->query("SHOW TABLES LIKE 'inventory_transactions'");
    if ($itExists && $itExists->num_rows > 0) {
        $trans = $conn->prepare("INSERT INTO inventory_transactions
            (branch_id, item_id, transaction_type, quantity_changed, reference_type, reference_id, created_by, created_at)
            VALUES (?, ?, 'out', ?, 'sales_order', ?, ?, NOW())");
        if ($trans) {
            $outQty = -abs($qty_to_deduct);
            $trans->bind_param('iidii', $branch_id, $item_id, $outQty, $so_id, $created_by);
            $trans->execute();
            $trans->close();
        }
    }
}

function amgcDeductRollingInventoryForSalesOrder(mysqli $conn, int $so_id, int $rolling_user_id, int $branch_id, int $created_by): void {
    // Hybrid behavior for Branch Admin assigning a Rolling account as delivery driver:
    // - If Rolling has stock for the ordered item/UoM, deduct from Rolling first.
    // - If Rolling has no stock or only partial stock, deduct the remaining quantity from Branch Admin stock.
    // This keeps the order as a normal delivery while using Rolling inventory only when available.
    $items_stmt = $conn->prepare("SELECT soi.item_id, soi.quantity_ordered, soi.unit_type
        FROM sales_order_items soi
        WHERE soi.so_id = ?");
    if (!$items_stmt) throw new Exception('Unable to load order items for stock deduction.');
    $items_stmt->bind_param('i', $so_id);
    $items_stmt->execute();
    $items = $items_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $items_stmt->close();

    foreach ($items as $item) {
        $item_id = (int)$item['item_id'];
        $qty = abs((float)$item['quantity_ordered']);
        $unit_type_name = trim((string)($item['unit_type'] ?? ''));
        if ($item_id <= 0 || $qty <= 0) continue;

        $unit_type_id = amgcResolveOrderItemUnitTypeId($conn, $item_id, $unit_type_name);
        $rolling_available = amgcGetRollingAvailableQty($conn, $rolling_user_id, $branch_id, $item_id, $unit_type_id, $unit_type_name);

        $deduct_from_rolling = min($qty, max(0, $rolling_available));
        $deduct_from_branch = $qty - $deduct_from_rolling;

        if ($deduct_from_rolling > 0.0001) {
            amgcDeductRollingStock($conn, $rolling_user_id, $branch_id, $item_id, $unit_type_id, $unit_type_name, $deduct_from_rolling);

            $itExists = $conn->query("SHOW TABLES LIKE 'inventory_transactions'");
            if ($itExists && $itExists->num_rows > 0) {
                $trans = $conn->prepare("INSERT INTO inventory_transactions
                    (branch_id, item_id, transaction_type, quantity_changed, reference_type, reference_id, created_by, created_at)
                    VALUES (?, ?, 'out', ?, 'rolling_delivery_sales_order', ?, ?, NOW())");
                if ($trans) {
                    $outQty = -abs($deduct_from_rolling);
                    $trans->bind_param('iidii', $branch_id, $item_id, $outQty, $so_id, $created_by);
                    $trans->execute();
                    $trans->close();
                }
            }
        }

        if ($deduct_from_branch > 0.0001) {
            amgcDeductBranchAdminUnitStock($conn, $branch_id, $item_id, $unit_type_id, $unit_type_name, $deduct_from_branch, $so_id, $created_by);
        }
    }
}


// ------------------------------------------------------------
// Helper: Apply edited sales order items from the Edit Order modal
// ------------------------------------------------------------
function amgcApplyEditedSalesOrderItems(mysqli $conn, int $so_id, string $items_json): float {
    $items = json_decode($items_json, true);
    if (!is_array($items) || empty($items)) return -1;

    $current_stmt = $conn->prepare("SELECT so_item_id, item_id, unit_type, quantity_ordered AS original_quantity_ordered, COALESCE(ave_cost, 0) AS ave_cost FROM sales_order_items WHERE so_id = ?");
    if (!$current_stmt) throw new Exception('Unable to load existing order items.');
    $current_stmt->bind_param('i', $so_id);
    $current_stmt->execute();
    $current_result = $current_stmt->get_result();
    $current_items = [];
    while ($row = $current_result->fetch_assoc()) $current_items[(int)$row['so_item_id']] = $row;
    $current_stmt->close();

    $update_item_stmt = $conn->prepare("UPDATE sales_order_items SET
        quantity_ordered = ?, quantity_delivered = ?, unit_price = ?, gross_price = ?,
        discount_type = 'computed', discount_value = 0, discount_amount = 0,
        net_price = ?, order_amount = ?, total_discount = 0, cogs_amount = ?, gross_profit = ?
        WHERE so_item_id = ? AND so_id = ?");
    if (!$update_item_stmt) throw new Exception('Unable to prepare order item update: ' . $conn->error);

    $total_amount = 0.0; $total_qty = 0; $updated_ids = [];
    foreach ($items as $item) {
        $so_item_id = (int)($item['so_item_id'] ?? 0);
        if ($so_item_id <= 0 || !isset($current_items[$so_item_id])) continue;

        $qty = (float)($item['quantity_ordered'] ?? 0);
        $price = (float)($item['unit_price'] ?? 0);
        $original_qty = (float)($current_items[$so_item_id]['original_quantity_ordered'] ?? 0);
        if ($qty < 0) throw new Exception('Quantity cannot be negative.');
        if ($qty > $original_qty) throw new Exception('Quantity cannot be higher than the original ordered quantity.');
        if ($price < 0) throw new Exception('Price cannot be negative.');

        $qty_int = (int)floor($qty);
        $original_qty_int = (int)floor($original_qty);
        if ($qty_int > $original_qty_int) $qty_int = $original_qty_int;
        $price = round($price, 2);
        $subtotal = round($qty_int * $price, 2);
        $ave_cost = (float)($current_items[$so_item_id]['ave_cost'] ?? 0);
        $cogs = round($qty_int * $ave_cost, 2);
        $gross_profit = round($subtotal - $cogs, 2);

        $update_item_stmt->bind_param('iiddddddii', $qty_int, $qty_int, $price, $price, $price, $subtotal, $cogs, $gross_profit, $so_item_id, $so_id);
        if (!$update_item_stmt->execute()) throw new Exception('Failed to update order item: ' . $update_item_stmt->error);

        $updated_ids[] = $so_item_id;
        $total_amount += $subtotal;
        $total_qty += $qty_int;
    }
    $update_item_stmt->close();

    if (empty($updated_ids)) throw new Exception('Please keep at least one valid item in the order.');
    if ($total_qty <= 0) throw new Exception('At least one item quantity must be greater than zero.');

    $picklist_check = $conn->prepare("SELECT pick_list_id FROM pick_lists WHERE so_id = ? ORDER BY pick_list_id DESC LIMIT 1");
    if ($picklist_check) {
        $picklist_check->bind_param('i', $so_id);
        $picklist_check->execute();
        $picklist_row = $picklist_check->get_result()->fetch_assoc();
        $picklist_check->close();

        if ($picklist_row) {
            $picklist_id = (int)$picklist_row['pick_list_id'];
            $delete_pick_items = $conn->prepare("DELETE FROM pick_list_items WHERE pick_list_id = ?");
            if ($delete_pick_items) {
                $delete_pick_items->bind_param('i', $picklist_id);
                $delete_pick_items->execute();
                $delete_pick_items->close();
            }

            $insert_pick_item = $conn->prepare("INSERT INTO pick_list_items (pick_list_id, item_id, quantity_to_pick)
                SELECT ?, item_id, quantity_ordered FROM sales_order_items WHERE so_id = ? AND quantity_ordered > 0");
            if ($insert_pick_item) {
                $insert_pick_item->bind_param('ii', $picklist_id, $so_id);
                $insert_pick_item->execute();
                $insert_pick_item->close();
            }
        }
    }

    $order_update = $conn->prepare("UPDATE sales_orders SET total_amount = ?, order_amount = ?, total_discount_amount = 0, discount_amount = 0, updated_at = NOW() WHERE so_id = ?");
    if ($order_update) {
        $order_update->bind_param('ddi', $total_amount, $total_amount, $so_id);
        $order_update->execute();
        $order_update->close();
    }
    return $total_amount;
}

// ------------------------------------------------------------
// Helper: Deduct stock only after final delivered quantities are confirmed
// ------------------------------------------------------------
function amgcDeductFinalDeliveredStock(mysqli $conn, int $so_id, int $branch_id, int $created_by): void {
    if ($so_id <= 0 || amgcSalesOrderStockAlreadyDeducted($conn, $so_id)) return;

    $rolling_user_id = 0;
    $driver_stmt = $conn->prepare("SELECT d.user_id, COALESCE(d.is_rolling_driver, 0) AS is_rolling_driver
        FROM pick_lists pl LEFT JOIN drivers d ON d.driver_id = pl.driver_id
        WHERE pl.so_id = ? ORDER BY pl.pick_list_id DESC LIMIT 1");
    if ($driver_stmt) {
        $driver_stmt->bind_param('i', $so_id);
        $driver_stmt->execute();
        $driver_row = $driver_stmt->get_result()->fetch_assoc();
        $driver_stmt->close();
        if ($driver_row && (int)($driver_row['is_rolling_driver'] ?? 0) === 1 && (int)($driver_row['user_id'] ?? 0) > 0) {
            $rolling_user_id = (int)$driver_row['user_id'];
        }
    }

    if ($rolling_user_id > 0) {
        amgcDeductRollingInventoryForSalesOrder($conn, $so_id, $rolling_user_id, $branch_id, $created_by);
        return;
    }

    $items_stmt = $conn->prepare("SELECT item_id, quantity_ordered, unit_type FROM sales_order_items WHERE so_id = ? AND quantity_ordered > 0");
    if (!$items_stmt) throw new Exception('Unable to load final delivered items for stock deduction.');
    $items_stmt->bind_param('i', $so_id);
    $items_stmt->execute();
    $items_result = $items_stmt->get_result();

    while ($item = $items_result->fetch_assoc()) {
        $item_id = (int)$item['item_id'];
        $quantity = abs((float)$item['quantity_ordered']);
        $unit_type_name = trim((string)($item['unit_type'] ?? ''));
        if ($item_id <= 0 || $quantity <= 0) continue;
        $unit_type_id = amgcResolveOrderItemUnitTypeId($conn, $item_id, $unit_type_name);
        amgcDeductBranchAdminUnitStock($conn, $branch_id, $item_id, $unit_type_id, $unit_type_name, $quantity, $so_id, $created_by);
    }
    $items_stmt->close();
}

// ------------------------------------------------------------
// Helper: Recalculate customer credit_used based on all unpaid invoices
// ------------------------------------------------------------
function recalcCustomerCreditUsed($conn, $customer_id) {
    $customer_id = (int)$customer_id;
    if ($customer_id <= 0) return 0.00;

    // Outstanding/Credit Used source of truth:
    // 1) Every sales order that is not fully paid, not cancelled, and not pending.
    //    Pending sales orders are NOT counted yet in outstanding balance.
    // 2) Beginning balance / manual invoices that have no linked sales order.
    // 3) Amount is never negative.
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

    // Keep existing customers.credit_used synced only for customers with credit limit.
    // Customers with no credit limit should not display Credit Used.
    $limit = getEffectiveCustomerCreditLimit($conn, $customer_id);
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
        'has_credit_limit' => $has_limit,
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
    AND LOWER(TRIM(COALESCE(fulfillment_type, ''))) <> 'beginning_balance'
    AND YEARWEEK(created_at, 1) = YEARWEEK(NOW(), 1)
    AND DATE(created_at) <= CURDATE()
";

if ($so_branch_column_exists && !$view_all_branches && $branch_id > 0) {
    $current_week_sales_query .= " AND branch_id = $branch_id";
}

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
foreach ($available_drivers as &$amgc_driver_row) {
    $amgc_driver_row['driver_type'] = 'driver';
    $amgc_driver_row['driver_value'] = 'driver:' . (int)$amgc_driver_row['driver_id'];
}
unset($amgc_driver_row);
$available_drivers = array_merge($available_drivers, amgcGetRollingUsersForDriverSelect($conn, (int)$branch_id));

$drivers_with_pending = array_filter($available_drivers, function($d) { 
    return $d['pending_deliveries'] > 0; 
});
$available_drivers_without_pending = array_filter($available_drivers, function($d) { 
    return $d['pending_deliveries'] == 0; 
});

// ========== GET AVAILABLE MOTORPOOL REGISTERED VEHICLES FOR DROPDOWN ==========
$available_vehicles = [];
if ($motorpool_vehicles_table_exists) {
    $motorpool_status_condition = $motorpool_vehicle_has_status ? "AND LOWER(TRIM(COALESCE(mv.status, 'active'))) = 'active'" : "";
    $motorpool_branch_condition = (!$view_all_branches && $branch_id > 0) ? "AND COALESCE(mv.branch_id, 0) = " . (int)$branch_id : "";

    $available_vehicles_query = "
        SELECT
            mv.id AS vehicle_id,
            COALESCE(NULLIF(mv.vehicle_type, ''), NULLIF(mv.vehicle_category, ''), 'Motorpool Vehicle') AS vehicle_type,
            COALESCE(NULLIF(mv.plate_no, ''), NULLIF(mv.vehicle_id, ''), CONCAT('Vehicle #', mv.id)) AS plate_number,
            COALESCE(mv.status, 'active') AS status,
            COALESCE(mv.make_brand, '') AS make_brand,
            COALESCE(mv.vehicle_id, '') AS motorpool_vehicle_code,
            (
                SELECT COUNT(*)
                FROM trip_tickets tt
                WHERE tt.vehicle_id = mv.id
                  AND tt.trip_status = 'in-progress'
            ) AS active_trips
        FROM motorpool_vehicles mv
        WHERE 1=1
          $motorpool_status_condition
          $motorpool_branch_condition
        HAVING active_trips = 0
        ORDER BY mv.plate_no ASC, mv.vehicle_type ASC, mv.make_brand ASC
    ";
    $available_vehicles_result = $conn->query($available_vehicles_query);
    $available_vehicles = $available_vehicles_result ? $available_vehicles_result->fetch_all(MYSQLI_ASSOC) : [];
}

// ========== HANDLE AJAX REQUESTS ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    try {
        $conn->begin_transaction();
        
        // UPDATE SALES ORDER
        if ($_POST['action'] === 'update_order') {
            $so_id = (int)$_POST['so_id'];
            $created_at = $_POST['created_at'];
            $order_status = $_POST['order_status'];
            $si_number = trim($_POST['si_number'] ?? '');
            $registered_business_name = trim($_POST['registered_business_name'] ?? '');
            $tin = trim($_POST['tin'] ?? '');
            $business_address = trim($_POST['business_address'] ?? '');
            $total_amount = (float)$_POST['total_amount'];
            $selected_driver_raw = isset($_POST['driver_id']) ? trim((string)$_POST['driver_id']) : '';
            $selected_driver_info = amgcParseSelectedDriver($selected_driver_raw);
            $selected_driver_id = $selected_driver_info['id'] > 0 ? $selected_driver_info['id'] : null;
            $selected_driver_type = $selected_driver_info['type'];
            $selected_rolling_user_id = $selected_driver_type === 'rolling' ? (int)$selected_driver_info['id'] : 0;
            $selected_vehicle_id = isset($_POST['vehicle_id']) && !empty($_POST['vehicle_id']) ? (int)$_POST['vehicle_id'] : null;
            
            $status_query = "SELECT order_status, customer_id, branch_id, so_number, si_number, registered_business_name, tin, business_address FROM sales_orders WHERE so_id = ? FOR UPDATE";
            $status_stmt = $conn->prepare($status_query);
            $status_stmt->bind_param("i", $so_id);
            $status_stmt->execute();
            $order_info = $status_stmt->get_result()->fetch_assoc();
            $old_status = $order_info['order_status'];
            $order_branch_id = $order_info['branch_id'];
            $customer_id = $order_info['customer_id'];

            // SI details are optional, but if the order already has SI data, keep it editable and saved.
            $has_posted_si_details = isset($_POST['si_number']) || isset($_POST['registered_business_name']) || isset($_POST['tin']) || isset($_POST['business_address']);
            if (!$has_posted_si_details) {
                $si_number = trim((string)($order_info['si_number'] ?? ''));
                $registered_business_name = trim((string)($order_info['registered_business_name'] ?? ''));
                $tin = trim((string)($order_info['tin'] ?? ''));
                $business_address = trim((string)($order_info['business_address'] ?? ''));
            }
            $has_si_details = ($si_number !== '' || $registered_business_name !== '' || $tin !== '' || $business_address !== '');
            $document_type_update = $has_si_details ? 'SI' : 'SO';

            // IMPORTANT: Do not trust total_amount posted by the browser modal.
            // It may still contain the gross/no-discount total. Recompute from saved
            // sales_orders discount header + sales_order_items before confirming/invoicing.
            amgcSyncSalesOrderComputedSnapshots($conn, $so_id);
            $accurate_total_stmt = $conn->prepare("SELECT total_amount FROM sales_orders WHERE so_id = ? LIMIT 1");
            if ($accurate_total_stmt) {
                $accurate_total_stmt->bind_param('i', $so_id);
                $accurate_total_stmt->execute();
                $accurate_total_row = $accurate_total_stmt->get_result()->fetch_assoc();
                if ($accurate_total_row) {
                    $total_amount = (float)($accurate_total_row['total_amount'] ?? $total_amount);
                }
                $accurate_total_stmt->close();
            }
            
            if ($so_branch_column_exists && !$view_all_branches) {
                $check_query = "SELECT so_id FROM sales_orders WHERE so_id = ? AND branch_id = ?";
                $check_stmt = $conn->prepare($check_query);
                $check_stmt->bind_param("ii", $so_id, $branch_id);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                
                if ($check_result->num_rows === 0) {
                    throw new Exception('Order not found or access denied');
                }
            }
            
            $edited_items_json = isset($_POST['edited_items']) ? trim((string)$_POST['edited_items']) : '';
            if ($order_status === 'delivered' && $old_status !== 'delivered' && $edited_items_json !== '') {
                $edited_total_amount = amgcApplyEditedSalesOrderItems($conn, $so_id, $edited_items_json);
                if ($edited_total_amount >= 0) {
                    $total_amount = $edited_total_amount;
                }

                amgcSyncSalesOrderComputedSnapshots($conn, $so_id);
                $accurate_total_stmt = $conn->prepare("SELECT total_amount FROM sales_orders WHERE so_id = ? LIMIT 1");
                if ($accurate_total_stmt) {
                    $accurate_total_stmt->bind_param('i', $so_id);
                    $accurate_total_stmt->execute();
                    $accurate_total_row = $accurate_total_stmt->get_result()->fetch_assoc();
                    if ($accurate_total_row) {
                        $total_amount = (float)($accurate_total_row['total_amount'] ?? $total_amount);
                    }
                    $accurate_total_stmt->close();
                }
            }

            $beyond_credit_required = false;
            $beyond_credit_explanation = trim($_POST['beyond_credit_explanation'] ?? '');
            $beyond_credit_acknowledged = isset($_POST['beyond_credit_acknowledged']) && $_POST['beyond_credit_acknowledged'] === '1';
            $beyond_credit_snapshot = null;

            $outstanding_balance_required = false;
            $outstanding_balance_explanation = trim($_POST['outstanding_balance_explanation'] ?? '');
            $outstanding_balance_acknowledged = isset($_POST['outstanding_balance_acknowledged']) && $_POST['outstanding_balance_acknowledged'] === '1';
            $outstanding_balance_snapshot = null;

            // If status is being changed to confirmed or delivered, check credit/outstanding rules.
            // Pending sales orders are not counted in outstanding yet. Existing unpaid invoices/orders are checked first.
            if ((($order_status === 'confirmed' && $old_status === 'pending') || ($order_status === 'delivered' && $old_status !== 'delivered'))) {
                $additional_for_credit_check = ($old_status === 'pending') ? $total_amount : 0;
                $credit_snapshot = getCustomerCreditSnapshot($conn, $customer_id, $additional_for_credit_check);
                $current_outstanding = max(0, (float)($credit_snapshot['credit_used'] ?? 0));
                $projected_outstanding = $current_outstanding + max(0, (float)$additional_for_credit_check);

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
                            'success' => false,
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
                } elseif (empty($credit_snapshot['has_credit_limit']) && $current_outstanding > 0) {
                    $outstanding_balance_required = true;
                    $outstanding_html = '<div class="text-start">' .
                                  '<p class="mb-2"><strong>This customer has no credit limit but has an outstanding balance.</strong></p>' .
                                  '<p class="mb-2 text-muted">Please provide an explanation and tick the acknowledgement box to continue confirmation.</p>' .
                                  '<hr class="my-2">' .
                                  '<div class="d-flex justify-content-between mb-1"><span>Credit Limit:</span><span class="fw-bold text-muted">No Credit Limit</span></div>' .
                                  '<div class="d-flex justify-content-between mb-1"><span>Current Outstanding Balance:</span><span class="fw-bold text-danger">₱' . number_format($current_outstanding, 2) . '</span></div>' .
                                  '<div class="d-flex justify-content-between mb-1"><span>This Order Amount:</span><span class="fw-bold">₱' . number_format($total_amount, 2) . '</span></div>' .
                                  '<div class="d-flex justify-content-between pt-1 mt-1 border-top"><span class="fw-bold">Projected Outstanding Balance:</span><span class="fw-bold text-danger">₱' . number_format($projected_outstanding, 2) . '</span></div>' .
                                  '</div>';

                    if ($outstanding_balance_explanation === '' || !$outstanding_balance_acknowledged) {
                        throw new Exception(json_encode([
                            'success' => false,
                            'type' => 'outstanding_balance_required',
                            'title' => 'Outstanding Balance Approval Required',
                            'html' => $outstanding_html,
                            'outstanding_balance' => $current_outstanding,
                            'order_amount' => $total_amount,
                            'projected_outstanding_balance' => $projected_outstanding
                        ]));
                    }

                    $outstanding_balance_snapshot = json_encode([
                        'credit_limit' => 0,
                        'outstanding_balance_before_confirmation' => $current_outstanding,
                        'order_amount' => $total_amount,
                        'projected_outstanding_balance' => $projected_outstanding,
                        'confirmed_by' => $user_id,
                        'confirmed_at' => date('Y-m-d H:i:s')
                    ], JSON_UNESCAPED_UNICODE);
                }
            }
            
            if ($beyond_credit_required) {
                $update_query = "UPDATE sales_orders 
                               SET created_at = ?, order_status = ?, total_amount = ?, updated_at = NOW(),
                                   si_number = ?, document_type = ?, registered_business_name = ?, tin = ?, business_address = ?,
                                   beyond_credit_limit_allowed = 1,
                                   beyond_credit_limit_explanation = ?,
                                   beyond_credit_limit_acknowledged = 1,
                                   beyond_credit_limit_allowed_by = ?,
                                   beyond_credit_limit_allowed_at = NOW(),
                                   beyond_credit_limit_snapshot = ?
                               WHERE so_id = ?";
                $update_stmt = $conn->prepare($update_query);
                $update_stmt->bind_param("ssdssssssisi", $created_at, $order_status, $total_amount, $si_number, $document_type_update, $registered_business_name, $tin, $business_address, $beyond_credit_explanation, $user_id, $beyond_credit_snapshot, $so_id);
            } elseif ($outstanding_balance_required) {
                $update_query = "UPDATE sales_orders 
                               SET created_at = ?, order_status = ?, total_amount = ?, updated_at = NOW(),
                                   si_number = ?, document_type = ?, registered_business_name = ?, tin = ?, business_address = ?,
                                   outstanding_balance_approval_allowed = 1,
                                   outstanding_balance_approval_explanation = ?,
                                   outstanding_balance_approval_acknowledged = 1,
                                   outstanding_balance_approval_by = ?,
                                   outstanding_balance_approval_at = NOW(),
                                   outstanding_balance_approval_snapshot = ?
                               WHERE so_id = ?";
                $update_stmt = $conn->prepare($update_query);
                $update_stmt->bind_param("ssdssssssisi", $created_at, $order_status, $total_amount, $si_number, $document_type_update, $registered_business_name, $tin, $business_address, $outstanding_balance_explanation, $user_id, $outstanding_balance_snapshot, $so_id);
            } else {
                $update_query = "UPDATE sales_orders 
                               SET created_at = ?, order_status = ?, total_amount = ?, updated_at = NOW(),
                                   si_number = ?, document_type = ?, registered_business_name = ?, tin = ?, business_address = ?
                               WHERE so_id = ?";
                $update_stmt = $conn->prepare($update_query);
                $update_stmt->bind_param("ssdsssssi", $created_at, $order_status, $total_amount, $si_number, $document_type_update, $registered_business_name, $tin, $business_address, $so_id);
            }
                        if (!$update_stmt->execute()) {
                throw new Exception('Failed to update sales order');
            }
            amgcSyncSalesOrderComputedSnapshots($conn, $so_id);
            
            if ($order_status === 'confirmed' && $old_status === 'pending') {
                if (!$selected_driver_id) {
                    throw new Exception('Please select a driver for this delivery');
                }
                if (!$selected_vehicle_id) {
                    throw new Exception('Please select a vehicle for this delivery');
                }

                $is_rolling_driver_assignment = ($selected_driver_type === 'rolling');
                if ($is_rolling_driver_assignment) {
                    $rolling_driver_info = amgcEnsureRollingDriverRow($conn, $selected_rolling_user_id, (int)$order_branch_id);
                    $selected_driver_id = (int)$rolling_driver_info['driver_id'];
                    $driver_name = $rolling_driver_info['driver_name'];
                } else {
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
                }

                if (!$motorpool_vehicles_table_exists) {
                    throw new Exception('No Motorpool registered vehicle table was found. Please register vehicles in Motorpool first.');
                }

                $motorpool_status_condition = $motorpool_vehicle_has_status ? " AND LOWER(TRIM(COALESCE(status, 'active'))) = 'active'" : "";
                $check_vehicle_query = "
                    SELECT
                        id AS vehicle_id,
                        COALESCE(NULLIF(vehicle_type, ''), NULLIF(vehicle_category, ''), 'Motorpool Vehicle') AS vehicle_type,
                        COALESCE(NULLIF(plate_no, ''), NULLIF(vehicle_id, ''), CONCAT('Vehicle #', id)) AS plate_number,
                        COALESCE(make_brand, '') AS make_brand
                    FROM motorpool_vehicles
                    WHERE id = ?
                    $motorpool_status_condition
                ";
                if (!$view_all_branches) {
                    $check_vehicle_query .= " AND COALESCE(branch_id, 0) = ?";
                    $check_vehicle_stmt = $conn->prepare($check_vehicle_query);
                    $check_vehicle_stmt->bind_param("ii", $selected_vehicle_id, $order_branch_id);
                } else {
                    $check_vehicle_stmt = $conn->prepare($check_vehicle_query);
                    $check_vehicle_stmt->bind_param("i", $selected_vehicle_id);
                }
                $check_vehicle_stmt->execute();
                $vehicle_result = $check_vehicle_stmt->get_result();
                if ($vehicle_result->num_rows === 0) {
                    throw new Exception('Selected vehicle is not registered in Motorpool or does not belong to this branch');
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
                    
                    $invoice_query = "INSERT INTO invoices (invoice_number, si_number, registered_business_name, tin, business_address, so_id, customer_id, branch_id, invoice_date, due_date, total_amount, status) 
                                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')";
                    $invoice_stmt = $conn->prepare($invoice_query);
                    $invoice_stmt->bind_param("sssssiiissd", $invoice_number, $si_number, $registered_business_name, $tin, $business_address, $so_id, $customer_id, $order_branch_id, $invoice_date, $due_date, $total_amount);
                    
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
                
                // Inventory is no longer deducted during confirmation.
                // Final quantity and price can still be edited when the order is marked as Delivered.
                // Stock is deducted once using the final delivered quantities.
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
                
                amgcDeductFinalDeliveredStock($conn, $so_id, (int)$order_branch_id, (int)$user_id);
                
                if ($invoice_so_column_exists) {
                    // Mark as Delivered should NOT auto-collect payment.
                    // It only makes sure the invoice exists and stays pending/overdue,
                    // so it will appear in collections.php under Customer Invoices (Ready for Collection), but stay on this page after update.
                    $existing_invoice_id = 0;
                    $existing_invoice_number = '';
                    $find_invoice_stmt = $conn->prepare("SELECT invoice_id, invoice_number FROM invoices WHERE so_id = ? LIMIT 1");
                    if ($find_invoice_stmt) {
                        $find_invoice_stmt->bind_param("i", $so_id);
                        $find_invoice_stmt->execute();
                        $find_invoice_result = $find_invoice_stmt->get_result();
                        if ($find_invoice_row = $find_invoice_result->fetch_assoc()) {
                            $existing_invoice_id = (int)$find_invoice_row['invoice_id'];
                            $existing_invoice_number = (string)$find_invoice_row['invoice_number'];
                        }
                        $find_invoice_stmt->close();
                    }

                    if ($existing_invoice_id <= 0) {
                        $delivered_invoice_number = 'INV-' . date('Ymd') . '-' . str_pad($so_id, 5, '0', STR_PAD_LEFT);
                        $invoice_date = date('Y-m-d');
                        $terms_days = getCustomerCreditTerms($conn, $customer_id);
                        $due_date = date('Y-m-d', strtotime("+$terms_days days"));

                        $create_invoice_query = "INSERT INTO invoices (invoice_number, si_number, registered_business_name, tin, business_address, so_id, customer_id, branch_id, invoice_date, due_date, total_amount, status)
                                                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')";
                        $create_invoice_stmt = $conn->prepare($create_invoice_query);
                        if (!$create_invoice_stmt) {
                            throw new Exception('Failed to prepare invoice creation: ' . $conn->error);
                        }
                        $create_invoice_stmt->bind_param("sssssiiissd", $delivered_invoice_number, $si_number, $registered_business_name, $tin, $business_address, $so_id, $customer_id, $order_branch_id, $invoice_date, $due_date, $total_amount);
                        if (!$create_invoice_stmt->execute()) {
                            throw new Exception('Failed to create invoice for collection: ' . $create_invoice_stmt->error);
                        }
                        $create_invoice_stmt->close();
                    } else {
                        $delivered_invoice_number = $existing_invoice_number;
                    }

                    $update_invoice_query = "UPDATE invoices
                                            SET total_amount = ?,
                                                si_number = ?,
                                                registered_business_name = ?,
                                                tin = ?,
                                                business_address = ?,
                                                status = CASE
                                                    WHEN status = 'cancelled' THEN 'cancelled'
                                                    WHEN status = 'paid' THEN 'paid'
                                                    WHEN due_date < CURDATE() THEN 'overdue'
                                                    ELSE 'pending'
                                                END
                                            WHERE so_id = ?";
                    $update_invoice_stmt = $conn->prepare($update_invoice_query);
                    if (!$update_invoice_stmt) {
                        throw new Exception('Failed to prepare invoice update: ' . $conn->error);
                    }
                    $update_invoice_stmt->bind_param("dssssi", $total_amount, $si_number, $registered_business_name, $tin, $business_address, $so_id);
                    if (!$update_invoice_stmt->execute()) {
                        throw new Exception('Failed to update invoice for collection: ' . $update_invoice_stmt->error);
                    }
                    $update_invoice_stmt->close();

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
                $response_message = $beyond_credit_required ? 'Order confirmed successfully with beyond credit limit acknowledgement. Pick List and Trip Ticket have been generated.' : 'Order confirmed successfully! Pick List and Trip Ticket have been generated.';
                $generated_docs = [
                    'picklist' => $pick_list_number,
                    'trip_ticket' => $trip_ticket_number,
                    'driver_id' => $selected_driver_id,
                    'driver_name' => $driver_name,
                    'vehicle_id' => $selected_vehicle_id,
                    'vehicle' => $vehicle_display
                ];
                
                if ($invoice_so_column_exists) {
                    $response_message = $beyond_credit_required ? 'Order confirmed successfully with beyond credit limit acknowledgement. Pick List, Invoice, and Trip Ticket have been generated.' : 'Order confirmed successfully! Pick List, Invoice, and Trip Ticket have been generated.';
                    $generated_docs['invoice'] = $invoice_number;
                }
            }
            

            if ($order_status === 'delivered' && $old_status !== 'delivered') {
                $response_message = 'Order marked as delivered. Final item quantities were saved, stock was deducted once, and invoice is now ready for collection in Customer Invoices.';
                if (!empty($delivered_invoice_number)) {
                    $generated_docs['invoice'] = $delivered_invoice_number;
                }
            }
            
            echo json_encode([
                'success' => true,
                'message' => $response_message,
                'generated_docs' => $generated_docs,
                'redirect_url' => ''
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
            foreach ($drivers as &$amgc_driver_row) {
                $amgc_driver_row['driver_type'] = 'driver';
                $amgc_driver_row['driver_value'] = 'driver:' . (int)$amgc_driver_row['driver_id'];
            }
            unset($amgc_driver_row);
            $drivers = array_merge($drivers, amgcGetRollingUsersForDriverSelect($conn, (int)$branch_id_param));
            
            echo json_encode([
                'success' => true,
                'drivers' => $drivers
            ]);
            exit;
        }
        
        // GET AVAILABLE MOTORPOOL REGISTERED VEHICLES
        elseif ($_POST['action'] === 'get_available_vehicles') {
            $branch_id_param = (int)$_POST['branch_id'];
            $vehicles = [];

            if ($motorpool_vehicles_table_exists) {
                $motorpool_status_condition = $motorpool_vehicle_has_status ? "AND LOWER(TRIM(COALESCE(mv.status, 'active'))) = 'active'" : "";

                $query = "
                    SELECT
                        mv.id AS vehicle_id,
                        COALESCE(NULLIF(mv.vehicle_type, ''), NULLIF(mv.vehicle_category, ''), 'Motorpool Vehicle') AS vehicle_type,
                        COALESCE(NULLIF(mv.plate_no, ''), NULLIF(mv.vehicle_id, ''), CONCAT('Vehicle #', mv.id)) AS plate_number,
                        COALESCE(mv.status, 'active') AS status,
                        COALESCE(mv.make_brand, '') AS make_brand,
                        COALESCE(mv.vehicle_id, '') AS motorpool_vehicle_code,
                        (
                            SELECT COUNT(*)
                            FROM trip_tickets tt
                            WHERE tt.vehicle_id = mv.id
                              AND tt.trip_status = 'in-progress'
                        ) AS active_trips
                    FROM motorpool_vehicles mv
                    WHERE COALESCE(mv.branch_id, 0) = ?
                      $motorpool_status_condition
                    HAVING active_trips = 0
                    ORDER BY mv.plate_no ASC, mv.vehicle_type ASC, mv.make_brand ASC
                ";

                $stmt = $conn->prepare($query);
                $stmt->bind_param("i", $branch_id_param);
                $stmt->execute();
                $result = $stmt->get_result();
                $vehicles = $result->fetch_all(MYSQLI_ASSOC);
            }

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
                $check_query = "SELECT so_id FROM sales_orders WHERE so_id = ? AND branch_id = ?";
                $check_stmt = $conn->prepare($check_query);
                $check_stmt->bind_param("ii", $so_id, $branch_id);
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
            
            $delete_order_query = "DELETE FROM sales_orders WHERE so_id = ?";
            $delete_order_stmt = $conn->prepare($delete_order_query);
            $delete_order_stmt->bind_param("i", $so_id);
            
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
                    COALESCE(c.customer_name, '') as customer_name,
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
                    TRIM(CONCAT(COALESCE(bcu.first_name, ''), CASE WHEN bcu.last_name IS NOT NULL AND bcu.last_name != '' THEN CONCAT(' ', bcu.last_name) ELSE '' END)) AS beyond_credit_approver,
                    TRIM(CONCAT(COALESCE(obu.first_name, ''), CASE WHEN obu.last_name IS NOT NULL AND obu.last_name != '' THEN CONCAT(' ', obu.last_name) ELSE '' END)) AS outstanding_balance_approval_approver,
                    COUNT(soi.so_item_id) as total_items,
                    SUM(soi.quantity_ordered) as total_quantity
                FROM sales_orders so
                JOIN customers c ON so.customer_id = c.customer_id
                LEFT JOIN branches b ON so.branch_id = b.branch_id
                LEFT JOIN (
                    SELECT so_id, MAX(NULLIF(si_number, '')) AS si_number
                    FROM invoices
                    WHERE NULLIF(si_number, '') IS NOT NULL
                    GROUP BY so_id
                ) inv_si ON inv_si.so_id = so.so_id
                LEFT JOIN users u ON so.created_by = u.user_id
                LEFT JOIN users bcu ON so.beyond_credit_limit_allowed_by = bcu.user_id
                LEFT JOIN users obu ON so.outstanding_balance_approval_by = obu.user_id
                LEFT JOIN sales_order_items soi ON so.so_id = soi.so_id
                WHERE so.so_id = ?
                GROUP BY so.so_id
            ";
            
            if ($so_branch_column_exists && !$view_all_branches) {
                $query .= " AND so.branch_id = ?";
                $stmt = $conn->prepare($query);
                $stmt->bind_param("ii", $so_id, $branch_id);
            } else {
                $stmt = $conn->prepare($query);
                $stmt->bind_param("i", $so_id);
            }
            
            $stmt->execute();
            $result = $stmt->get_result();
            $order = $result->fetch_assoc();
            
            if ($order) {
                $credit_snapshot = getCustomerCreditSnapshot($conn, $order['customer_id']);
                $credit_limit = floatval($credit_snapshot['credit_limit'] ?? 0);
                $credit_used = floatval($credit_snapshot['credit_used'] ?? 0);
                $outstanding_balance = max(0, $credit_used);
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
                        COALESCE(i.item_code, '') as item_code,
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
                    $tt_query = "SELECT tt.trip_number" . ($trip_has_vehicle_id ? ", tt.vehicle_id, COALESCE(NULLIF(v.vehicle_type, ''), NULLIF(v.vehicle_category, ''), 'Motorpool Vehicle') AS vehicle_type, COALESCE(NULLIF(v.plate_no, ''), NULLIF(v.vehicle_id, ''), CONCAT('Vehicle #', v.id)) AS plate_number" : "") . "
                                 FROM trip_tickets tt" . ($trip_has_vehicle_id ? " LEFT JOIN motorpool_vehicles v ON tt.vehicle_id = v.id" : "") . "
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
                    $invoice_query = "SELECT i.invoice_id, i.invoice_number, i.si_number, i.registered_business_name, i.tin, i.business_address, i.status as invoice_status, i.due_date, p.payment_date as collected_at,
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
                    'has_credit_limit' => ($credit_limit > 0),
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
                    COALESCE(so.atw_no, '') AS atw_no,
                    COALESCE(so.gatepass_no, '') AS gatepass_no,
                    COALESCE(NULLIF(so.si_number, ''), NULLIF(inv_si.si_number, ''), '') AS si_number,
                    so.created_at as order_date,
                    so.order_status,
                    so.total_amount as order_total,
                    COALESCE(c.customer_name, '') as customer_name,
                    c.customer_id,
                    b.branch_name,
                    b.branch_id,
                    COALESCE(i.item_code, '') as item_code,
                    i.item_name,
                    i.unit_type as item_unit_type
                FROM sales_order_items soi
                JOIN sales_orders so ON soi.so_id = so.so_id
                JOIN customers c ON so.customer_id = c.customer_id
                LEFT JOIN branches b ON so.branch_id = b.branch_id
                JOIN items i ON soi.item_id = i.item_id
                WHERE soi.so_id = ?
            ";
            
            if ($so_branch_column_exists && !$view_all_branches) {
                $query .= " AND so.branch_id = ?";
                $stmt = $conn->prepare($query);
                $stmt->bind_param("ii", $so_id, $branch_id);
            } else {
                $stmt = $conn->prepare($query);
                $stmt->bind_param("i", $so_id);
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
                    COALESCE(so.atw_no, '') AS atw_no,
                    COALESCE(so.gatepass_no, '') AS gatepass_no,
                    COALESCE(NULLIF(so.si_number, ''), NULLIF(inv_si.si_number, ''), '') AS si_number,
                    so.created_at as order_date,
                    so.order_status,
                    so.total_amount as order_total,
                    COALESCE(c.customer_name, '') as customer_name,
                    c.customer_id,
                    b.branch_name,
                    b.branch_id,
                    COALESCE(i.item_code, '') as item_code,
                    i.item_name
                FROM sales_order_items soi
                JOIN sales_orders so ON soi.so_id = so.so_id
                JOIN customers c ON so.customer_id = c.customer_id
                LEFT JOIN branches b ON so.branch_id = b.branch_id
                JOIN items i ON soi.item_id = i.item_id
                WHERE 1=1
                $hide_beginning_balance_orders_condition
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
            amgcSyncSalesOrderComputedSnapshots($conn);
            $start_date = isset($_POST['start_date']) ? $_POST['start_date'] : '';
            $end_date = isset($_POST['end_date']) ? $_POST['end_date'] : '';
            $status = isset($_POST['status']) ? $_POST['status'] : '';
            $customer = isset($_POST['customer']) ? $_POST['customer'] : '';
            $search = isset($_POST['search']) ? $_POST['search'] : '';
            
            // Build the print query using only columns that really exist.
            // This prevents the generic “An error occurred while preparing print” when the DB patch has not been applied yet.
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
                $query .= " AND so.branch_id = $branch_id";
            }
            
            $query .= " ORDER BY so.created_at DESC, so.so_id, soi.so_item_id";
            
            $result = $conn->query($query);
            if (!$result) {
                echo json_encode(['success' => false, 'message' => 'Query failed: ' . $conn->error]);
                exit;
            }
            
            $data = $result->fetch_all(MYSQLI_ASSOC);
            foreach ($data as &$row) {
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
            }
            unset($row);
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
                    DATE(COALESCE(so.created_at, so.order_date)) as date_encoded,
                    so.so_number as so_order_number,
                    COALESCE(c.customer_code, '') as customer_code,
                    COALESCE(c.store_name, '') as store_name,
                    COALESCE(c.customer_name, '') as customer_name,
                    COALESCE(i.item_code, '') as item_code,
                    COALESCE(i.item_name, '') as item_description,
                    COALESCE(soi.unit_type, '') as unit_of_measurement,
                    soi.quantity_ordered as quantity,
                    COALESCE(NULLIF(soi.gross_price, 0), (
                        SELECT iup.unit_price
                        FROM item_unit_pricing iup
                        LEFT JOIN unit_types utp ON iup.unit_type_id = utp.unit_type_id
                        WHERE iup.item_id = soi.item_id
                          AND LOWER(TRIM(utp.unit_type_name)) = LOWER(TRIM(soi.unit_type))
                          AND (iup.effective_date IS NULL OR iup.effective_date <= DATE(so.created_at))
                          AND (iup.effective_until IS NULL OR iup.effective_until >= DATE(so.created_at))
                        ORDER BY COALESCE(iup.effective_date, '1900-01-01') DESC, iup.pricing_id DESC
                        LIMIT 1
                    ), COALESCE(NULLIF(soi.net_price, 0), soi.unit_price)) as gross_price,
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
                $query .= " AND so.branch_id = $branch_id";
            }
            $query .= " ORDER BY so.created_at DESC, so.so_id, soi.so_item_id";
            
            $result = $conn->query($query);
            if (!$result) {
                echo json_encode(['success' => false, 'message' => 'Print query failed: ' . $conn->error]);
                exit;
            }
            $rows = $result->fetch_all(MYSQLI_ASSOC);
            foreach ($rows as &$row) {
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
            }
            unset($row);
            
            $branch_name_display = ((int)$branch_id > 0) ? ($view_all_branches ? 'All Branches' : amgc_h($branch_name ?? ('Branch ' . $branch_id))) : 'All Branches';
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
                    @page {
                        size: A4 landscape;
                        margin: 8mm;
                    }

                    * {
                        box-sizing: border-box;
                    }

                    body {
                        font-family: Arial, sans-serif;
                        margin: 10px;
                        font-size: 10.5px;
                        line-height: 1.22;
                        color: #111827;
                        background: #fff;
                        -webkit-print-color-adjust: exact;
                        print-color-adjust: exact;
                    }

                    .header {
                        display: flex;
                        align-items: center;
                        justify-content: center;
                        gap: 9px;
                        text-align: center;
                        margin-bottom: 10px;
                        padding-bottom: 7px;
                        border-bottom: 2px solid #047857;
                    }

                    .header img {
                        height: 42px !important;
                        width: 42px !important;
                        object-fit: contain;
                        margin: 0 !important;
                        flex: 0 0 auto;
                    }

                    .header h1 {
                        margin: 0 0 2px 0;
                        font-size: 15px;
                        line-height: 1.1;
                        font-weight: 800;
                        color: #052A47;
                    }

                    .header p {
                        margin: 1px 0;
                        color: #334155;
                        font-size: 8.8px;
                        line-height: 1.15;
                        font-weight: 600;
                    }

                    .print-title-text {
                        text-align: left;
                    }

                    table {
                        width: 100%;
                        border-collapse: collapse;
                        margin-top: 8px;
                        table-layout: fixed;
                    }

                    th {
                        border: 0.55px solid #94a3b8;
                        padding: 4px 3px;
                        text-align: center;
                        vertical-align: middle;
                        background: #047857;
                        font-size: 7.2px;
                        line-height: 1.1;
                        font-weight: 400;
                        color: #fff;
                        text-transform: uppercase;
                        overflow-wrap: anywhere;
                        word-break: normal;
                    }

                    td {
                        border: 0.55px solid #94a3b8;
                        padding: 4px 3px;
                        text-align: center;
                        vertical-align: middle;
                        font-size: 7.4px;
                        line-height: 1.12;
                        color: #111827;
                        overflow-wrap: anywhere;
                        word-break: normal;
                    }

                    tbody tr:nth-child(even) {
                        background: #f8fafc;
                    }

                    /* Width tuning for Print All Orders only */
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
                    th:nth-child(10), td:nth-child(10),
                    th:nth-child(11), td:nth-child(11),
                    th:nth-child(12), td:nth-child(12),
                    th:nth-child(13), td:nth-child(13),
                    th:nth-child(14), td:nth-child(14),
                    th:nth-child(15), td:nth-child(15),
                    th:nth-child(16), td:nth-child(16),
                    th:nth-child(17), td:nth-child(17) { width: 5.4%; }
                    th:nth-child(18), td:nth-child(18) { width: 6.4%; }

                    table thead th {
                        font-weight: 400 !important;
                    }

                    .footer {
                        margin-top: 10px;
                        padding-top: 6px;
                        border-top: 1px solid #94a3b8;
                        text-align: center;
                        font-size: 8.5px;
                        color: #334155;
                        font-weight: 600;
                    }

                    @media print {
                        body {
                            margin: 0;
                        }

                        .header {
                            margin-bottom: 8px;
                            padding-bottom: 5px;
                        }

                        table {
                            page-break-inside: auto;
                        }

                        thead {
                            display: table-header-group;
                        }

                        tr {
                            page-break-inside: avoid;
                            break-inside: avoid;
                        }

                        .no-print {
                            display: none;
                        }
                    }
                </style>
            </head>
            <body>
                <div class="header">
                    ' . $logo_html . '
                    <div class="print-title-text">
                        <h1>Sales Orders Report</h1>
                        <p>Branch: ' . $branch_name_display . '</p>
                        <p>Generated: ' . date('Y-m-d H:i:s') . '</p>
                    </div>
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
                            <th>Unit of Measurement</th>
                            <th>Quantity</th>
                            <th>Gross Price</th>
                            <th>Discount</th>
                            <th>Net Price</th>
                            <th>Order Amount</th>
                            <th>Total Discount</th>
                            <th>Ave. Cost</th>
                            <th>COGS</th>
                            <th>Gross Profit</th>
                            <th>Encoded by</th>
                        </tr>
                    </thead>
                    <tbody>';
            
            foreach ($rows as $row) {
                $html .= '<tr>
                    <td>' . amgc_h($row['date_encoded'] ?? '') . '</td>
                    <td>' . amgc_h($row['so_order_number'] ?? '') . '</td>
                    <td>' . amgc_h($row['customer_code'] ?? '') . '</td>
                    <td>' . amgc_h($row['store_name'] ?? '') . '</td>
                    <td>' . amgc_h($row['customer_name'] ?? '') . '</td>
                    <td>' . amgc_h($row['item_code'] ?? '') . '</td>
                    <td>' . amgc_h($row['item_description'] ?? '') . '</td>
                    <td>' . amgc_h($row['unit_of_measurement'] ?? '') . '</td>
                    <td>' . number_format($row['quantity'], 2) . '</td>
                    <td>' . number_format($row['gross_price'], 2) . '</td>
                    <td>' . number_format($row['discount'], 2) . '</td>
                    <td>' . number_format($row['net_price'], 2) . '</td>
                    <td>' . number_format($row['order_amount'], 2) . '</td>
                    <td>' . number_format($row['total_discount'], 2) . '</td>
                    <td>' . number_format($row['ave_cost'], 2) . '</td>
                    <td>' . number_format($row['cogs'], 2) . '</td>
                    <td>' . number_format($row['gross_profit'], 2) . '</td>
                    <td>' . amgc_h($row['encoded_by'] ?? '') . '</td>
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
                    COALESCE(so.atw_no, '') AS atw_no,
                    COALESCE(so.gatepass_no, '') AS gatepass_no,
                    COALESCE(NULLIF(so.si_number, ''), NULLIF(inv_si.si_number, ''), '') AS si_number,
                    so.created_at as order_date,
                    so.order_status,
                    so.total_amount as order_total,
                    COALESCE(c.customer_name, '') as customer_name,
                    c.customer_id,
                    c.address,
                    c.phone_number as contact_number,
                    c.email,
                    b.branch_name,
                    b.address as branch_address,
                    b.contact_number as branch_contact,
                    u.first_name,
                    u.last_name,
                    COALESCE(i.item_code, '') as item_code,
                    i.item_name
                FROM sales_order_items soi
                JOIN sales_orders so ON soi.so_id = so.so_id
                JOIN customers c ON so.customer_id = c.customer_id
                LEFT JOIN branches b ON so.branch_id = b.branch_id
                LEFT JOIN (
                    SELECT so_id, MAX(NULLIF(si_number, '')) AS si_number
                    FROM invoices
                    WHERE NULLIF(si_number, '') IS NOT NULL
                    GROUP BY so_id
                ) inv_si ON inv_si.so_id = so.so_id
                LEFT JOIN users u ON so.created_by = u.user_id
                JOIN items i ON soi.item_id = i.item_id
                WHERE soi.so_id = ?
                ORDER BY soi.so_item_id
            ";
            
            $stmt = $conn->prepare($query);
            $stmt->bind_param("i", $so_id);
            $stmt->execute();
            $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            
            $driver_query = "
                SELECT d.driver_name, COALESCE(NULLIF(v.vehicle_type, ''), NULLIF(v.vehicle_category, ''), 'Motorpool Vehicle') AS vehicle_type, COALESCE(NULLIF(v.plate_no, ''), NULLIF(v.vehicle_id, ''), CONCAT('Vehicle #', v.id)) AS plate_number
                FROM pick_lists pl
                JOIN drivers d ON pl.driver_id = d.driver_id
                LEFT JOIN trip_tickets tt ON tt.so_id = pl.so_id
                LEFT JOIN motorpool_vehicles v ON tt.vehicle_id = v.id
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
            
            $query = "SELECT * FROM invoices WHERE so_id = ?";
            $stmt = $conn->prepare($query);
            $stmt->bind_param("i", $so_id);
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
            $driver_raw = isset($_POST['driver_id']) ? trim((string)$_POST['driver_id']) : '';
            $driver_info = amgcParseSelectedDriver($driver_raw);

            if ($driver_info['type'] === 'rolling' && $driver_info['id'] > 0) {
                // Rolling can be assigned even if the item is not in the Rolling inventory.
                // Actual deduction is handled on confirmation by amgcDeductRollingInventoryForSalesOrder():
                // it deducts Rolling stock if available, then Branch Admin stock for the remaining quantity.
                echo json_encode([
                    'success' => true,
                    'message' => 'Rolling can be assigned. Stock will be deducted from Rolling if available; otherwise from Branch Admin stock.',
                    'sufficient' => true,
                    'insufficient_items' => [],
                    'stock_source' => 'rolling_branch_hybrid'
                ]);
                exit;
            }
            
            $items_query = "
                SELECT 
                    soi.item_id,
                    soi.quantity_ordered,
                    COALESCE(i.item_code, '') as item_code,
                    i.item_name,
                    i.stock as available_stock
                FROM sales_order_items soi
                JOIN items i ON soi.item_id = i.item_id
                WHERE soi.so_id = ?
            ";
            $items_stmt = $conn->prepare($items_query);
            $items_stmt->bind_param("i", $so_id);
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
                    'sufficient' => true,
                    'stock_source' => 'branch'
                ]);
            } else {
                echo json_encode([
                    'success' => true,
                    'message' => 'Some items have insufficient stock',
                    'sufficient' => false,
                    'insufficient_items' => $insufficient_items,
                    'stock_source' => 'branch'
                ]);
            }
            exit;
        }
        
    } catch (Exception $e) {
        $conn->rollback();
        $error_message = $e->getMessage();
        
        if (strpos($error_message, '{"type":"credit_limit_error"') === 0 || strpos($error_message, '{"type":"credit_limit_required"') === 0 || strpos($error_message, '{"success":false,"type":"credit_limit_required"') === 0 || strpos($error_message, '{"success":false,"type":"outstanding_balance_required"') === 0 || strpos($error_message, '{"type":"outstanding_balance_required"') === 0) {
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

// Save/refresh computed sales order snapshots before displaying UI table.
amgcSyncSalesOrderComputedSnapshots($conn);

// FETCH SALES ORDERS WITH CUSTOMER, ITEM COUNTS, AND INVOICE DATA
$sales_query = "
    SELECT 
        so.so_id,
        so.so_number,
        so.created_at,
        so.total_amount,
        so.order_status,
        so.branch_id,
        COALESCE(c.customer_name, '') as customer_name,
        c.customer_id,
        c.credit_limit,
        c.credit_used,
        b.branch_name,
        u.first_name,
        u.last_name,
        COUNT(DISTINCT soi.so_item_id) as total_items,
        SUM(soi.quantity_ordered) as total_quantity,
        COALESCE(NULLIF(so.order_amount, 0), SUM(COALESCE(soi.order_amount, soi.quantity_ordered * COALESCE(NULLIF(soi.net_price, 0), soi.unit_price)))) as order_amount,
        COALESCE(NULLIF(so.cogs_amount, 0), SUM(COALESCE(soi.cogs_amount, soi.quantity_ordered * COALESCE(soi.ave_cost, 0)))) as cogs_amount,
        COALESCE(so.gross_profit_amount, COALESCE(NULLIF(so.order_amount, 0), SUM(COALESCE(soi.order_amount, soi.quantity_ordered * COALESCE(NULLIF(soi.net_price, 0), soi.unit_price)))) - COALESCE(NULLIF(so.cogs_amount, 0), SUM(COALESCE(soi.cogs_amount, soi.quantity_ordered * COALESCE(soi.ave_cost, 0))))) as gross_profit,
        " . ($invoice_so_column_exists ? "inv.invoice_id, inv.invoice_number, inv.status as invoice_status, pay.collected_by_name, pay.payment_date as collected_at" : "NULL as invoice_id, NULL as invoice_number, NULL as invoice_status, NULL as collected_by_name, NULL as collected_at") . ",
        (SELECT driver_name FROM drivers WHERE driver_id = pl.driver_id LIMIT 1) as assigned_driver
    FROM sales_orders so
    JOIN customers c ON so.customer_id = c.customer_id
    LEFT JOIN branches b ON so.branch_id = b.branch_id
    LEFT JOIN users u ON so.created_by = u.user_id
    LEFT JOIN sales_order_items soi ON so.so_id = soi.so_id
    LEFT JOIN pick_lists pl ON so.so_id = pl.so_id
    " . ($invoice_so_column_exists ? "LEFT JOIN invoices inv ON so.so_id = inv.so_id
       LEFT JOIN (
           SELECT p1.invoice_id, p1.payment_date, CONCAT(COALESCE(u.first_name, ''), CASE WHEN u.last_name IS NOT NULL AND u.last_name != '' THEN CONCAT(' ', u.last_name) ELSE '' END) AS collected_by_name
           FROM payments p1
           LEFT JOIN users u ON p1.created_by = u.user_id
           INNER JOIN (SELECT invoice_id, MAX(payment_id) AS latest_payment_id FROM payments GROUP BY invoice_id) p2
               ON p1.payment_id = p2.latest_payment_id
       ) pay ON inv.invoice_id = pay.invoice_id" : "") . "
    WHERE 1=1
    $hide_beginning_balance_orders_condition
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
$customers_query = "SELECT customer_id, customer_name FROM customers WHERE status = 'active' $customers_branch_condition ORDER BY customer_name";
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

.edit-order-items-table th,
.edit-order-items-table td {
    white-space: nowrap;
    font-size: 0.88rem;
}

.edit-order-items-table td:first-child,
.edit-order-items-table th:first-child {
    white-space: normal;
    min-width: 180px;
}

@media (max-width: 768px) {
    .edit-order-items-table th,
    .edit-order-items-table td {
        font-size: 0.78rem;
        padding: 0.35rem 0.45rem;
    }
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
/* Alternative - cleaner footer style */
tfoot.table-light {
    background: #048964 !important;
    background-image: none !important;
    border-top: 2px solid #dee2e6;
}

/* Para sa dark mode kung meron */
tfoot.table-light th {
    background: transparent !important;
    color: #212529;
}
/* Action Bar with Search - Search Left, Buttons Right */
.action-bar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 1rem;
    margin-bottom: 1.25rem;
    flex-wrap: wrap;
}

.action-buttons-group {
    display: flex;
    gap: 0.75rem;
    flex-wrap: wrap;
}

.search-bar-wrapper {
    width: 400px;
    flex-shrink: 0;
}

.search-bar-wrapper .input-group {
    border-radius: 8px;
    overflow: hidden;
    border: 1px solid #dee2e6;
    background: white;
}

.search-bar-wrapper .input-group-text {
    background: #ecfdf5;
    border: none;
    color: #2aaa06;
    padding: 0.45rem 0.75rem;
}

.search-bar-wrapper .form-control {
    border: none;
    padding: 0.45rem 0.75rem;
    font-size: 0.85rem;
}

.search-bar-wrapper .form-control:focus {
    outline: none;
    box-shadow: none;
    border-color: #047857;
}

@media (max-width: 768px) {
    .action-bar {
        flex-direction: column;
        align-items: stretch;
    }
    .action-buttons-group {
        justify-content: center;
    }
    .search-bar-wrapper {
        width: 100%;
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

                <!-- Vendor Dropdown -->
                <li class="nav-item dropdown-nav">
                    <a class="nav-link" href="#" onclick="toggleSidebarDropdown(event, 'supplierMenu')">
                        <i class="bi bi-people"></i>
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
                                    <i class="bi bi-people"></i>
                                    <span class="nav-text">Vendor List</span>
                                </a>
                            </li>
                        </ul>
                    </div>
                </li>

                <!-- Customer Dropdown -->
                <li class="nav-item dropdown-nav">
                    <a class="nav-link" href="#" onclick="toggleSidebarDropdown(event, 'customerMenu')">
                        <i class="bi bi-people"></i>
                        <span class="nav-text">Customers</span>
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
                                    <span class="nav-text">Approve Credit Limit & Terms</span>
                                </a>
                            </li>

                            <li class="nav-item">
                                <a class="nav-link active" href="sales_order.php">
                                    <i class="bi bi-cart"></i>
                                    <span class="nav-text">Sales Order</span>
                                </a>
                            </li>

                            <li class="nav-item">
                                <a class="nav-link" href="collections.php">
                                    <i class="bi bi-cash-stack"></i>
                                    <span class="nav-text">Receive Payment</span>
                                </a>
                            </li>
                        </ul>
                    </div>
                </li>

                <!-- Employees Dropdown -->
                <li class="nav-item dropdown-nav">
                    <a class="nav-link" href="#" onclick="toggleSidebarDropdown(event, 'employeesMenu')">
                        <i class="bi bi-people"></i>
                        <span class="nav-text">Employees</span>
                        <i class="bi bi-chevron-down dropdown-arrow"></i>
                    </a>

                    <div class="collapse" id="employeesMenu">
                        <ul class="nav flex-column ps-4">
                            <li class="nav-item">
                                <a class="nav-link" href="employeelist.php">
                                    <i class="bi bi-people"></i>
                                    <span class="nav-text">Employee List</span>
                                </a>
                            </li>

                            <li class="nav-item">
                                <a class="nav-link" href="employee.php">
                                    <i class="bi bi-clock"></i>
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
                            <!-- Dashboard -->
                            <li class="nav-item">
                                <a class="nav-link" href="branchdashboard.php">
                                    <i class="bi bi-speedometer2"></i>
                                    <span class="nav-text">Dashboard</span>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" href="current_inventory.php">
                                    <i class="bi bi-box"></i>
                                    <span class="nav-text">Items</span>
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
                                <a class="nav-link" href="batch_transaction.php">
                                    <i class="bi bi-collection"></i>
                                    <span class="nav-text">Batch Transaction</span>
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
<div class="form-card mb-4" id="filterCard">
    <div class="filter-header" id="filterHeader" style="cursor: pointer;">
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

                <!-- Action Bar with Search (Left) and Buttons (Right) -->
<div class="action-bar no-print">
    <div class="search-bar-wrapper">
        <div class="input-group">
            <span class="input-group-text"><i class="bi bi-search"></i></span>
            <input type="text" class="form-control" id="searchInput" placeholder="Search order number or customer..." onkeyup="applyManualFilters()">
        </div>
    </div>
    <div class="action-buttons-group">
        <button class="btn btn-primary" onclick="printAllOrders()">
            <i class="bi bi-printer"></i> Print All Orders
        </button>
        <button class="btn btn-success" onclick="exportToExcel()">
            <i class="bi bi-file-earmark-excel"></i> Export to Excel
        </button>
    </div>
</div>

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
                                    <th>Order Amount</th>
                                    <th>Gross Profit</th>
                                    <th>Order Status</th>
                                    <th class="no-print">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="salesOrdersTableBody">
                                <?php if (empty($sales_orders)): ?>
                                <tr>
                                    <td colspan="<?= ($so_branch_column_exists && $view_all_branches) ? '10' : '9' ?>" class="text-center py-4">
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
                                        data-order-amount="<?= $order['order_amount'] ?? 0 ?>"
                                        data-gross-profit="<?= $order['gross_profit'] ?? 0 ?>"
                                        data-credit-limit="<?= $order['credit_limit'] ?? 0 ?>"
                                        data-credit-used="<?= $order['credit_used'] ?? 0 ?>">
                                        <td><strong><?= htmlspecialchars($order['so_number']) ?></strong></td>
                                        <td><?= formatDate($order['created_at']) ?></td>
                                        <td><?= htmlspecialchars($order['customer_name']) ?></td>
                                        <?php if ($so_branch_column_exists && $view_all_branches): ?>
                                            <td>
                                                <span class="badge bg-info">
                                                    <?= htmlspecialchars($order['branch_name'] ?? 'Branch ' . $order['branch_id']) ?>
                                                </span>
                                            </td>
                                        <?php endif; ?>
                                        <td class="text-center"><?= $order['total_items'] ?? 0 ?></td>
                                        <td class="text-center"><?= $order['total_quantity'] ?? 0 ?></td>
                                        <td class="text-end">₱<?= number_format($order['order_amount'] ?? 0, 2) ?></td>
                                        <td class="text-end">₱<?= number_format($order['gross_profit'] ?? 0, 2) ?></td>
                                        <td>
                                            <span class="<?= getOrderStatusBadge($order['order_status']) ?>">
                                                <?= getOrderStatusText($order['order_status']) ?>
                                            </span>
                                        </td>
                                        <td class="no-print">
                                            <div class="action-buttons">
                                                <button class="btn-action btn-view" onclick="viewOrder(<?= $order['so_id'] ?>)" title="View">
                                                    <i class="bi bi-eye"></i>
                                                </button>
                                                <button class="btn-action btn-print" onclick="printSingleOrder(<?= $order['so_id'] ?>)" title="Print Order">
                                                    <i class="bi bi-printer"></i>
                                                </button>
                                                <?php if ($order['order_status'] == 'pending'): ?>
                                                    <button class="btn-action btn-edit" onclick="editOrder(<?= $order['so_id'] ?>)" title="Edit">
                                                        <i class="bi bi-pencil"></i>
                                                    </button>
                                                    <button class="btn-action btn-delete" onclick="deleteOrder(<?= $order['so_id'] ?>)" title="Delete">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                <?php endif; ?>
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
                <button type="button" class="btn btn-warning" onclick="editFromView()" id="editFromViewBtn">Edit Order</button>
                <button type="button" class="btn btn-danger" onclick="deleteFromView()" id="deleteFromViewBtn" style="display: none;">Delete Order</button>
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
                                    <option value="confirmed">Confirm Order (Generate Documents)</option>
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
                            <div class="col-md-12">
                                <div class="border rounded p-3 bg-light">
                                    <div class="form-check form-switch mb-2" id="editSIToggleContainer">
                                        <input class="form-check-input" type="checkbox" id="enableSIFields" onchange="toggleEditSIFields()">
                                        <label class="form-check-label fw-semibold" for="enableSIFields">Add SI details (optional)</label>
                                    </div>
                                    <div id="editSIFields" class="row g-2" style="display:none;">
                                        <div class="col-md-6"><label class="form-label small mb-1">SI Number *</label><input type="text" class="form-control form-control-sm" id="editSINumber"></div>
                                        <div class="col-md-6"><label class="form-label small mb-1">Registered Business Name *</label><input type="text" class="form-control form-control-sm" id="editRegisteredBusinessName"></div>
                                        <div class="col-md-6"><label class="form-label small mb-1">TIN *</label><input type="text" class="form-control form-control-sm" id="editBusinessTin"></div>
                                        <div class="col-md-6"><label class="form-label small mb-1">Address *</label><input type="text" class="form-control form-control-sm" id="editBusinessAddress"></div>
                                    </div>
                                </div>
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

                            <div class="col-12">
                                <label class="form-label">Ordered Items</label>
                                <div class="table-responsive border rounded">
                                    <table class="table table-sm table-bordered align-middle mb-0 edit-order-items-table">
                                        <thead class="table-light">
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
                                        <tfoot class="table-light">
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

        if (typeof removeAllBackdrops === 'function') {
            removeAllBackdrops();
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
                'date' => $order['created_at'],
                'dateDisplay' => formatDate($order['created_at']),
                'amount' => floatval($order['total_amount']),
                'orderAmount' => floatval($order['order_amount'] ?? 0),
                'grossProfit' => floatval($order['gross_profit'] ?? 0),
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
            const colspan = hasBranchColumn ? 10 : 9;
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
            row.setAttribute('data-order-amount', order.orderAmount || 0);
            row.setAttribute('data-gross-profit', order.grossProfit || 0);
            
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
                <td class="text-end">${formatCurrency(order.orderAmount || 0)}<\/td>
                <td class="text-end">${formatCurrency(order.grossProfit || 0)}<\/td>
                <td><span class="${statusBadgeClass}">${statusText}</span><\/td>
                <td class="no-print">
                    <div class="action-buttons">
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
        const noFilters = searchTerm === '' && statusFilter === '' && customerFilter === '' && !hasDateFilter;
        
        if (noFilters) {
            currentDisplayData = [...MASTER_ORDER_DATA];
            renderTable(currentDisplayData);
            const totalAmount = currentDisplayData.reduce((sum, order) => sum + order.amount, 0);
            updateTotalAmountDisplay(totalAmount);
            updateDateRangeSummary('', '', currentDisplayData.length, totalAmount);
            updatePeriodSalesStat('', '', 0, true);
            updateActiveFiltersBadge();
            console.log('No filters - showing all orders from MASTER');
            return;
        }
        
        const filteredOrders = MASTER_ORDER_DATA.filter(order => {
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
        
        if (MASTER_ORDER_DATA && MASTER_ORDER_DATA.length > 0) {
            currentDisplayData = [...MASTER_ORDER_DATA];
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
                    const rollingDrivers = data.drivers.filter(d => d.driver_type === 'rolling');
                    const regularDrivers = data.drivers.filter(d => d.driver_type !== 'rolling');
                    const withPending = regularDrivers.filter(d => d.pending_deliveries > 0);
                    const withoutPending = regularDrivers.filter(d => d.pending_deliveries == 0);
                    
                    if (rollingDrivers.length > 0) {
                        const group = $('<optgroup label="Rolling Accounts (deducts Rolling stock)">');
                        rollingDrivers.forEach(driver => {
                            const option = new Option(driver.driver_name, driver.driver_value || driver.driver_id);
                            $(option).data('pending', driver.pending_deliveries || 0).data('driver-type', 'rolling');
                            group.append(option);
                        });
                        select.append(group);
                    }
                    if (withPending.length > 0) {
                        const group = $('<optgroup label="Drivers with existing deliveries (can be assigned)">');
                        withPending.forEach(driver => {
                            const option = new Option(driver.driver_name, driver.driver_value || ('driver:' + driver.driver_id));
                            $(option).data('pending', driver.pending_deliveries).data('driver-type', 'driver');
                            group.append(option);
                        });
                        select.append(group);
                    }
                    if (withoutPending.length > 0) {
                        const group = $('<optgroup label="Available Drivers">');
                        withoutPending.forEach(driver => {
                            const option = new Option(driver.driver_name, driver.driver_value || ('driver:' + driver.driver_id));
                            $(option).data('pending', 0).data('driver-type', 'driver');
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
    
    function checkStockBeforeConfirm(soId, driverId = '') {
        showLoading();
        const formData = new FormData();
        formData.append('action', 'check_stock');
        formData.append('so_id', soId);
        if (driverId) formData.append('driver_id', driverId);
        
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
                const hasCreditLimit = (parseInt(data.has_credit_limit || 0) === 1 || data.has_credit_limit === true || String(data.has_credit_limit).toLowerCase() === 'true' || parseFloat(creditLimit || 0) > 0);
                const encodedBy = order.encoded_by || 'N/A';
                const paymentTermsText = data.payment_terms_text || 'Cash on Delivery';
                const beyondCreditApprover = order.beyond_credit_approver || data.beyond_credit_approver || '';
                const outstandingApprovalApprover = order.outstanding_balance_approval_approver || data.outstanding_balance_approval_approver || '';
                
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
                        ${hasCreditLimit ? `
                        <div class="credit-info-row">
                            <div class="credit-item"><span>Credit Limit:</span> <span>${formatCurrency(creditLimit)}</span></div>
                            <div class="credit-item"><span>Credit Used:</span> <span>${formatCurrency(creditUsed)}</span></div>
                        </div>` : ''}
                        ${!hasCreditLimit && outstandingBalance > 0 ? '' : ''}
                        ${isOverLimit ? '<div class="mt-2 text-danger small"><i class="bi bi-exclamation-circle"></i> Customer has exceeded credit limit! New orders are blocked.</div>' : ''}
                    </div>
                `;
                
                // ========== SIMPLE & CLEAN ITEMS TABLE - SA LOOB NG MODAL ==========
                // Requested format: show totals as SUBTOTAL / DISCOUNT / GRAND TOTAL,
                // not as a per-item Discount column.
                let itemsHtml = '';
                let subtotalAmount = 0;
                let computedGrandTotal = 0;
                let computedDiscountAmount = 0;
                
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
                    
                    items.forEach((item) => {
                        const qty = parseFloat(item.quantity_ordered || 0);
                        const grossPrice = parseFloat(item.gross_price || item.unit_price || item.net_price || 0);
                        const netPrice = parseFloat(item.net_price || item.unit_price || grossPrice || 0);
                        const lineSubtotal = parseFloat((grossPrice * qty) || 0);
                        const lineTotal = parseFloat(item.order_amount || (netPrice * qty) || 0);
                        const savedLineDiscount = parseFloat(item.total_discount || 0);
                        const lineDiscount = savedLineDiscount > 0 ? savedLineDiscount : Math.max(0, lineSubtotal - lineTotal);

                        subtotalAmount += lineSubtotal;
                        computedGrandTotal += lineTotal;
                        computedDiscountAmount += lineDiscount;

                        itemsHtml += `
                            <tr style="border-bottom: 1px solid #f0f0f0;">
                                <td style="padding: 10px 12px; vertical-align: middle;">${escapeHtml(item.item_code)}</td>
                                <td style="padding: 10px 12px; vertical-align: middle;">${escapeHtml(item.item_name)}</td>
                                <td style="padding: 10px 12px; vertical-align: middle; text-align: center;">${escapeHtml(item.unit_type || 'pcs')}</td>
                                <td style="padding: 10px 12px; vertical-align: middle; text-align: center;">${qty}</td>
                                <td style="padding: 10px 12px; vertical-align: middle; text-align: right; color: #6c757d; font-weight: 500;">${formatCurrency(grossPrice)}</td>
                                <td style="padding: 10px 12px; vertical-align: middle; text-align: right; font-weight: 700; color: #212529;">${formatCurrency(lineSubtotal)}</td>
                            </tr>
                        `;
                    });

                    // Header values are the source of truth when available.
                    // This keeps the display aligned with the discounted total saved in DB.
                    const headerDiscount = parseFloat(order.total_discount_amount || order.discount_amount || 0);
                    const headerGrandTotal = parseFloat(order.total_amount || 0);
                    const finalDiscountAmount = headerDiscount > 0 ? headerDiscount : computedDiscountAmount;
                    const finalGrandTotal = headerGrandTotal > 0 ? headerGrandTotal : computedGrandTotal;
                    const finalSubtotal = subtotalAmount > 0 ? subtotalAmount : (finalGrandTotal + finalDiscountAmount);
                    
                    // Totals in requested format: SUBTOTAL / DISCOUNT / GRAND TOTAL
                    itemsHtml += `
                                </tbody>
                                <tfoot>
                                    <tr style="background-color: #ffffff; border-top: 2px solid #dee2e6;">
                                        <td colspan="5" style="padding: 9px 12px; text-align: right; font-weight: 700; color: #212529;">SUBTOTAL</td>
                                        <td style="padding: 9px 12px; text-align: right; font-weight: 700; color: #212529;">${formatCurrency(finalSubtotal)}</td>
                                    </tr>
                                    <tr style="background-color: #ffffff;">
                                        <td colspan="5" style="padding: 9px 12px; text-align: right; font-weight: 700; color: #dc3545;">DISCOUNT</td>
                                        <td style="padding: 9px 12px; text-align: right; font-weight: 700; color: #dc3545;">-${formatCurrency(finalDiscountAmount)}</td>
                                    </tr>
                                    <tr style="background-color: #f8f9fa; border-top: 1px solid #dee2e6;">
                                        <td colspan="5" style="padding: 10px 12px; text-align: right; font-weight: 800; color: #047857;">GRAND TOTAL</td>
                                        <td style="padding: 10px 12px; text-align: right; font-weight: 800; color: #047857; font-size: 1rem;">${formatCurrency(finalGrandTotal)}</td>
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

                let outstandingApprovalHtml = '';
                if (parseInt(order.outstanding_balance_approval_allowed || 0) === 1) {
                    outstandingApprovalHtml = `
                        <div class="card mb-3 border-warning">
                            <div class="card-header bg-warning text-dark">
                                <h6 class="mb-0 fw-bold"><i class="bi bi-exclamation-triangle-fill me-2"></i>Outstanding Balance Approval</h6>
                            </div>
                            <div class="card-body">
                                <div class="alert alert-warning mb-3">
                                    <strong>I understand that this customer has no credit limit and has an outstanding balance. I am allowing this order to proceed.</strong>
                                </div>
                                <table class="table table-sm table-borderless mb-0">
                                    <tr><td width="40%">Outstanding Balance:</td><td><strong>${formatCurrency(outstandingBalance)}</strong></td></tr>
                                    <tr><td width="40%">Explanation:</td><td><strong>${escapeHtml(order.outstanding_balance_approval_explanation || 'N/A')}</strong></td></tr>
                                    ${outstandingApprovalApprover ? `<tr><td width="40%">Allowed By:</td><td>${escapeHtml(outstandingApprovalApprover)}</td></tr>` : ''}
                                    ${order.outstanding_balance_approval_at ? `<tr><td width="40%">Allowed At:</td><td>${escapeHtml(order.outstanding_balance_approval_at)}</td></tr>` : ''}
                                </table>
                            </div>
                        </div>
                    `;
                }
                const orderSINumber = (order.si_number || (invoice && invoice.si_number) || '').trim();
                const orderRegisteredBusinessName = (order.registered_business_name || (invoice && invoice.registered_business_name) || '').trim();
                const orderTin = (order.tin || (invoice && invoice.tin) || '').trim();
                const orderBusinessAddress = (order.business_address || (invoice && invoice.business_address) || '').trim();

                let siDetailsHtml = '';
                if (orderSINumber || orderRegisteredBusinessName || orderTin || orderBusinessAddress) {
                    siDetailsHtml = `
                        <div class="card mb-3">
                            <div class="card-header bg-light"><h6 class="mb-0 fw-bold"><i class="bi bi-receipt-cutoff me-2"></i>SI Details</h6></div>
                            <div class="card-body">
                                <table class="table table-sm table-borderless mb-0">
                                    ${orderSINumber ? `<tr><td width="40%">SI Number:</td><td><strong>${escapeHtml(orderSINumber)}</strong></td></tr>` : ''}
                                    ${orderRegisteredBusinessName ? `<tr><td width="40%">Registered Business Name:</td><td><strong>${escapeHtml(orderRegisteredBusinessName)}</strong></td></tr>` : ''}
                                    ${orderTin ? `<tr><td width="40%">TIN:</td><td>${escapeHtml(orderTin)}</td></tr>` : ''}
                                    ${orderBusinessAddress ? `<tr><td width="40%">Business Address:</td><td>${escapeHtml(orderBusinessAddress)}</td></tr>` : ''}
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
                                            ${orderSINumber ? `<tr><td width="40%">SI Number:</div><td><strong>${escapeHtml(orderSINumber)}</strong></div></tr>` : ''}
                                            ${order.atw_no ? `<tr><td width="40%">ATW No.:</div><td><strong>${escapeHtml(order.atw_no)}</strong></div></tr>` : ''}
                                            ${order.gatepass_no ? `<tr><td width="40%">Gatepass No.:</div><td><strong>${escapeHtml(order.gatepass_no)}</strong></div></tr>` : ''}
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
                    viewContent.innerHTML = outstandingHtml + beyondCreditHtml + outstandingApprovalHtml + siDetailsHtml + encodedByHtml + orderSummaryHtml + orderItemsHtml + documentsHtml;
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
    
    function recalculateEditOrderItemsTotal() {
        const rows = document.querySelectorAll('#editOrderItemsTableBody tr[data-so-item-id]');
        let totalQty = 0;
        let totalAmount = 0;

        rows.forEach(row => {
            const qtyInput = row.querySelector('.edit-item-qty');
            const priceInput = row.querySelector('.edit-item-price');
            const subtotalCell = row.querySelector('.edit-item-subtotal');

            let qty = parseFloat((qtyInput?.value || '0').replace(/[^0-9.]/g, '')) || 0;
            const maxQty = parseFloat(row.dataset.originalQty || '0') || 0;
            qty = Math.max(0, Math.min(qty, maxQty));
            qty = Math.floor(qty);
            if (qtyInput && qtyInput.value !== String(qty)) qtyInput.value = String(qty);

            let price = parseFloat((priceInput?.value || '0').replace(/[^0-9.]/g, '')) || 0;
            price = Math.max(0, price);
            const subtotal = qty * price;

            if (subtotalCell) subtotalCell.textContent = formatCurrency(subtotal);
            totalQty += qty;
            totalAmount += subtotal;
        });

        const totalQtyCell = document.getElementById('editItemsTotalQty');
        const totalAmountCell = document.getElementById('editItemsTotalAmount');
        const totalAmountInput = document.getElementById('editTotalAmount');

        if (totalQtyCell) totalQtyCell.textContent = formatNumberWithCommas(totalQty);
        if (totalAmountCell) totalAmountCell.textContent = formatCurrency(totalAmount);
        if (totalAmountInput) totalAmountInput.value = totalAmount.toFixed(2);
    }

    function getEditedOrderItemsPayload() {
        const rows = document.querySelectorAll('#editOrderItemsTableBody tr[data-so-item-id]');
        return Array.from(rows).map(row => {
            let qty = parseFloat((row.querySelector('.edit-item-qty')?.value || '0').replace(/[^0-9.]/g, '')) || 0;
            const maxQty = parseFloat(row.dataset.originalQty || '0') || 0;
            qty = Math.max(0, Math.min(Math.floor(qty), maxQty));
            let price = parseFloat((row.querySelector('.edit-item-price')?.value || '0').replace(/[^0-9.]/g, '')) || 0;
            price = Math.max(0, price);
            return {
                so_item_id: parseInt(row.dataset.soItemId || '0', 10),
                item_id: parseInt(row.dataset.itemId || '0', 10),
                quantity_ordered: qty,
                unit_price: price
            };
        }).filter(item => item.so_item_id > 0);
    }

    function renderEditOrderItemsTable(items) {
        const tbody = document.getElementById('editOrderItemsTableBody');
        const totalQtyCell = document.getElementById('editItemsTotalQty');
        const totalAmountCell = document.getElementById('editItemsTotalAmount');
        if (!tbody) return;

        if (!Array.isArray(items) || items.length === 0) {
            tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-3">No ordered items found.</td></tr>';
            if (totalQtyCell) totalQtyCell.textContent = '0';
            if (totalAmountCell) totalAmountCell.textContent = formatCurrency(0);
            const totalAmountInput = document.getElementById('editTotalAmount');
            if (totalAmountInput) totalAmountInput.value = '0.00';
            return;
        }

        tbody.innerHTML = items.map(item => {
            const itemName = escapeHtml(item.item_name || 'Unknown Item');
            const itemCode = escapeHtml(item.item_code || '');
            const unitType = escapeHtml(item.unit_type || item.item_unit_type || '');
            const qty = parseFloat(item.quantity_ordered || 0) || 0;
            const originalQty = qty;
            const unitPrice = parseFloat(item.net_price || item.unit_price || item.gross_price || 0) || 0;
            const subtotal = qty * unitPrice;
            const soItemId = parseInt(item.so_item_id || 0, 10);
            const itemId = parseInt(item.item_id || 0, 10);

            return `
                <tr data-so-item-id="${soItemId}" data-item-id="${itemId}" data-original-qty="${originalQty}">
                    <td>
                        <div class="fw-semibold text-dark">${itemName}</div>
                        ${itemCode ? `<small class="text-muted">${itemCode}</small>` : ''}
                    </td>
                    <td class="text-center">${unitType || '-'}</td>
                    <td class="text-end">
                        <input type="text" inputmode="numeric" class="form-control form-control-sm text-end edit-item-qty" value="${qty}" data-max-qty="${originalQty}" oninput="recalculateEditOrderItemsTotal()" autocomplete="off">
                    </td>
                    <td class="text-end">
                        <input type="text" inputmode="decimal" class="form-control form-control-sm text-end edit-item-price" value="${unitPrice.toFixed(2)}" oninput="recalculateEditOrderItemsTotal()" autocomplete="off">
                    </td>
                    <td class="text-end fw-semibold edit-item-subtotal">${formatCurrency(subtotal)}</td>
                </tr>
            `;
        }).join('');

        recalculateEditOrderItemsTotal();
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
                    const editOutstandingBalance = parseFloat(data.outstanding_balance || 0);
                    const editHasCreditLimit = (parseInt(data.has_credit_limit || 0) === 1 || data.has_credit_limit === true || String(data.has_credit_limit).toLowerCase() === 'true' || parseFloat(data.credit_limit || 0) > 0);
                    const editOutstandingBox = document.getElementById('editOutstandingBalanceBox');
                    const editOutstandingAmount = document.getElementById('editOutstandingBalanceAmount');
                    if (editOutstandingAmount) editOutstandingAmount.textContent = formatCurrency(editOutstandingBalance);
                    if (editOutstandingBox) editOutstandingBox.style.display = (!editHasCreditLimit && editOutstandingBalance > 0) ? '' : 'none';
                    document.getElementById('editOrderStatus').value = order.order_status;
                    renderEditOrderItemsTable(data.items || []);
                    document.getElementById('editTotalAmount').value = order.total_amount;

                    const invoice = data.invoice || {};
                    const editSINumber = (order.si_number || invoice.si_number || '').trim();
                    const editRegisteredBusinessName = (order.registered_business_name || invoice.registered_business_name || '').trim();
                    const editTin = (order.tin || invoice.tin || '').trim();
                    const editBusinessAddress = (order.business_address || invoice.business_address || '').trim();
                    const hasExistingSI = !!(editSINumber || editRegisteredBusinessName || editTin || editBusinessAddress);
                    const siToggleContainer = document.getElementById('editSIToggleContainer');
                    const siToggle = document.getElementById('enableSIFields');
                    const siFieldsBox = document.getElementById('editSIFields');
                    if (document.getElementById('editSINumber')) document.getElementById('editSINumber').value = editSINumber;
                    if (document.getElementById('editRegisteredBusinessName')) document.getElementById('editRegisteredBusinessName').value = editRegisteredBusinessName;
                    if (document.getElementById('editBusinessTin')) document.getElementById('editBusinessTin').value = editTin;
                    if (document.getElementById('editBusinessAddress')) document.getElementById('editBusinessAddress').value = editBusinessAddress;
                    if (siFieldsBox) siFieldsBox.dataset.existingSi = hasExistingSI ? '1' : '0';
                    if (siToggleContainer) siToggleContainer.style.display = hasExistingSI ? 'none' : '';
                    if (siToggle) siToggle.checked = hasExistingSI;
                    if (siFieldsBox) siFieldsBox.style.display = hasExistingSI ? 'flex' : 'none';
                    
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
                    
                    // If customer is already over limit or has no credit limit with outstanding balance, keep confirmation available but require explanation/acknowledgement on update.
                    if (!editHasCreditLimit && editOutstandingBalance > 0) {
                        Swal.fire({
                            icon: 'warning',
                            title: 'Customer Has Outstanding Balance',
                            text: 'This customer has no credit limit but has an outstanding balance. You may still confirm this order, but an explanation and acknowledgement will be required.',
                            confirmButtonColor: '#f59e0b'
                        });
                    } else if (data.is_over_limit) {
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
                        document.getElementById('editOrderStatus').innerHTML = `<option value="pending">Pending</option><option value="confirmed">Confirm Order (Generate Documents)</option><option value="delivered">Mark as Delivered</option><option value="cancelled">Cancel Order</option>`;
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
        const editedItems = getEditedOrderItemsPayload();
        const totalQty = editedItems.reduce((sum, item) => sum + (parseFloat(item.quantity_ordered) || 0), 0);
        const computedTotal = editedItems.reduce((sum, item) => sum + ((parseFloat(item.quantity_ordered) || 0) * (parseFloat(item.unit_price) || 0)), 0);
        if (editedItems.length === 0 || totalQty <= 0) {
            Swal.fire('Warning', 'Please keep at least one item with quantity greater than zero.', 'warning');
            return;
        }
        if (computedTotal < 0) { Swal.fire('Warning', 'Valid Total Amount is required', 'warning'); return; }
        if (orderStatus === 'confirmed') {
            if (!selectedDriver) { Swal.fire('Warning', 'Please select a driver for this delivery', 'warning'); return; }
            if (!selectedVehicle) { Swal.fire('Warning', 'Please select a vehicle for this delivery', 'warning'); return; }
            checkStockBeforeConfirm(orderId, selectedDriver).then(proceed => { if (proceed) proceedWithUpdate(orderId, orderDate, orderStatus, computedTotal.toFixed(2), selectedDriver, selectedVehicle); });
        } else { proceedWithUpdate(orderId, orderDate, orderStatus, computedTotal.toFixed(2), null, null); }
    }

    function toggleEditSIFields() {
    const enabled = document.getElementById('enableSIFields').checked;
    const fields = document.getElementById('editSIFields');
    if(fields){ fields.style.display = enabled ? 'flex' : 'none';}
    ['editSINumber','editRegisteredBusinessName','editBusinessTin','editBusinessAddress'].forEach(id=>{
        const el=document.getElementById(id);
        if(el) el.required=enabled;
    });
}

    function proceedWithUpdate(orderId, orderDate, orderStatus, totalAmount, driverId, vehicleId, beyondExplanation = '', beyondAcknowledged = false, approvalType = 'credit') {
        showLoading();
        const formData = new FormData();
        formData.append('action', 'update_order');
        formData.append('so_id', orderId);
        formData.append('created_at', orderDate);
        formData.append('order_status', orderStatus);
        formData.append('total_amount', totalAmount);
        formData.append('edited_items', JSON.stringify(getEditedOrderItemsPayload()));
        if (driverId) formData.append('driver_id', driverId);
        if (vehicleId) formData.append('vehicle_id', vehicleId);
        if (beyondExplanation && approvalType === 'outstanding') {
            formData.append('outstanding_balance_explanation', beyondExplanation);
            if (beyondAcknowledged) formData.append('outstanding_balance_acknowledged', '1');
        } else {
            if (beyondExplanation) formData.append('beyond_credit_explanation', beyondExplanation);
            if (beyondAcknowledged) formData.append('beyond_credit_acknowledged', '1');
        }
        const editSIFieldsBox = document.getElementById('editSIFields');
        const shouldSubmitSIFields = (editSIFieldsBox?.dataset.existingSi === '1') || document.getElementById('enableSIFields')?.checked;
        if (shouldSubmitSIFields) {
            formData.append('si_number', document.getElementById('editSINumber')?.value || '');
            formData.append('registered_business_name', document.getElementById('editRegisteredBusinessName')?.value || '');
            formData.append('tin', document.getElementById('editBusinessTin')?.value || '');
            formData.append('business_address', document.getElementById('editBusinessAddress')?.value || '');
        }

        fetch('sales_order.php', { method: 'POST', body: formData })
            .then(response => response.json())
            .then(data => {
                Swal.close();
                if (data.success) {
                    Swal.fire({ icon: 'success', title: 'Success!', text: data.message, timer: 2000, showConfirmButton: false }).then(() => { 
                        const modal = bootstrap.Modal.getInstance(document.getElementById('editOrderModal'));
                        if (modal) modal.hide();
                        if (data.redirect_url) {
                            window.location.href = data.redirect_url;
                        } else {
                            location.reload();
                        }
                    });
                } else {
                    if (data.type === 'credit_limit_required' || data.type === 'outstanding_balance_required') {
                        const editOrderModalEl = document.getElementById('editOrderModal');
                        const editOrderModal = editOrderModalEl ? bootstrap.Modal.getInstance(editOrderModalEl) : null;
                        const showBeyondCreditApprovalModal = () => {
                            const isOutstandingApproval = data.type === 'outstanding_balance_required';
                            Swal.fire({
                                icon: 'warning',
                                title: data.title || (isOutstandingApproval ? 'Outstanding Balance Approval Required' : 'Beyond Credit Limit Approval Required'),
                                html: `
                                    ${data.html || ''}
                                    <div class="text-start mt-3">
                                        <label class="form-label fw-bold" for="beyondCreditExplanationInput">Explanation <span class="text-danger">*</span></label>
                                        <textarea id="beyondCreditExplanationInput" class="form-control" rows="4" placeholder="Enter reason why this order is being allowed..."></textarea>
                                        <div class="form-check mt-3">
                                            <input class="form-check-input" type="checkbox" value="1" id="beyondCreditAcknowledgeInput">
                                            <label class="form-check-label fw-semibold" for="beyondCreditAcknowledgeInput">
                                                ${isOutstandingApproval ? 'I understand that this customer has no credit limit and has an outstanding balance. I am allowing this order to proceed.' : 'I understand that this order is beyond credit limit. I am allowing this order to proceed.'}
                                            </label>
                                        </div>
                                    </div>
                                `,
                                showCancelButton: true,
                                confirmButtonText: isOutstandingApproval ? 'Allow & Confirm Order' : 'Allow & Confirm Order',
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
                                    proceedWithUpdate(orderId, orderDate, orderStatus, totalAmount, driverId, vehicleId, result.value.explanation, true, data.type === 'outstanding_balance_required' ? 'outstanding' : 'credit');
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
                        'Customer Name', 'Item Code', 'Item Description', 'Unit of Measurement',
                        'Quantity', 'Gross Price', 'Discount', 'Net Price', 'Order Amount',
                        'Total Discount', 'Ave. Cost', 'COGS', 'Gross Profit', 'Encoded by'
                    ];
                    const rows = data.data.map(row => [
                        row.date_encoded,
                        row.so_order_number,
                        row.customer_code,
                        row.store_name,
                        row.customer_name,
                        row.item_code,
                        row.item_description,
                        row.unit_of_measurement,
                        Number(row.quantity || 0),
                        Number(row.gross_price || 0),
                        Number(row.discount || 0),
                        Number(row.net_price || 0),
                        Number(row.order_amount || 0),
                        Number(row.total_discount || 0),
                        Number(row.ave_cost || 0),
                        Number(row.cogs || 0),
                        Number(row.gross_profit || 0),
                        row.encoded_by
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
            .then(response => response.text())
            .then(text => {
                let data;
                try {
                    data = JSON.parse(text);
                } catch (e) {
                    throw new Error(text.substring(0, 300) || 'Invalid server response');
                }
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
                Swal.fire('Error', error.message || 'An error occurred while preparing print', 'error');
            });
    }
    
    // ===== PRINT SINGLE ORDER (THERMAL RECEIPT ONLY) =====
    function printSingleOrder(orderId) {
        currentOrderId = orderId;
        const printBtn = event && event.target ? event.target.closest('button') : null;

        if (printBtn) {
            printBtn.innerHTML = '<i class="bi bi-printer"></i>';
            printBtn.disabled = true;
        }

        showLoading();

        const formData = new FormData();
        formData.append('action', 'print_order');
        formData.append('so_id', orderId);

        fetch('sales_order.php', { method: 'POST', body: formData })
            .then(response => response.json())
            .then(data => {
                Swal.close();

                if (data.success) {
                    const htmlContent = generateSingleOrderThermalHTML(data.order, data.items, data.driver || null);
                    const iframe = document.getElementById('printFrame');
                    const iframeDoc = iframe.contentWindow.document;

                    iframeDoc.open();
                    iframeDoc.write(htmlContent);
                    iframeDoc.close();

                    setTimeout(() => {
                        if (printBtn) {
                            printBtn.innerHTML = '<i class="bi bi-printer"></i>';
                            printBtn.disabled = false;
                        }
                    }, 800);

                    setTimeout(() => {
                        iframe.contentWindow.focus();
                        iframe.contentWindow.print();
                    }, 250);
                } else {
                    Swal.fire('Error', data.message || 'Failed to load order details', 'error');
                    if (printBtn) {
                        printBtn.innerHTML = '<i class="bi bi-printer"></i>';
                        printBtn.disabled = false;
                    }
                }
            })
            .catch(error => {
                Swal.close();
                Swal.fire('Error', error.message || 'Network error while preparing print', 'error');
                if (printBtn) {
                    printBtn.innerHTML = '<i class="bi bi-printer"></i>';
                    printBtn.disabled = false;
                }
            });
    }

    // Thermal receipt HTML for solo order print only.
    // Print all orders is intentionally untouched.
    function generateSingleOrderThermalHTML(order, items, driver) {
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
        const orderStatus = order ? getStatusText(order.order_status || '') : '';
        const dbTotal = order ? parseFloat(order.order_total || order.total_amount || 0) : 0;
        const totalAmount = dbTotal > 0 ? dbTotal : computedTotal;
        const driverName = driver
            ? escapeHtml(driver.driver_name || 'No Driver')
            : escapeHtml(order?.assigned_driver && order.assigned_driver !== 'No Driver' ? order.assigned_driver : 'No Driver');
        const vehicleText = driver && (driver.vehicle_type || driver.plate_number)
            ? escapeHtml(`${driver.vehicle_type || ''}${driver.vehicle_type && driver.plate_number ? ' - ' : ''}${driver.plate_number || ''}`)
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
.item-code {
    color: #333;
    max-width: 18mm;
    word-break: break-word;
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
.receipt-summary {
    margin-top: 3px;
    text-align: right;
    font-size: 9px;
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
        if (orderId && typeof viewOrder === 'function') viewOrder(orderId);
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
        ['viewOrderModal', 'editOrderModal', 'deleteOrderModal', 'stockWarningModal'].forEach(modalId => {
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
    // Filter toggle functionality - ENTIRE HEADER CLICKABLE
document.addEventListener('DOMContentLoaded', function() {
    const filterHeader = document.getElementById('filterHeader');
    const filterContent = document.getElementById('filterContent');
    const filterToggleBtn = document.getElementById('filterToggleBtn');
    const filterIcon = document.getElementById('filterIcon');
    
    if (filterHeader && filterContent) {
        // Set initial state based on localStorage
        const savedState = localStorage.getItem('filterSectionExpanded');
        const isInitiallyExpanded = savedState === 'true';
        
        if (!isInitiallyExpanded) {
            filterContent.classList.add('collapsed');
            if (filterToggleBtn) filterToggleBtn.setAttribute('aria-expanded', 'false');
            if (filterIcon) {
                filterIcon.classList.remove('bi-chevron-up');
                filterIcon.classList.add('bi-chevron-down');
            }
        } else {
            filterContent.classList.remove('collapsed');
            if (filterToggleBtn) filterToggleBtn.setAttribute('aria-expanded', 'true');
            if (filterIcon) {
                filterIcon.classList.remove('bi-chevron-down');
                filterIcon.classList.add('bi-chevron-up');
            }
        }
        
        // Make the entire header clickable
        filterHeader.addEventListener('click', function(e) {
            // Don't toggle if clicking on the button itself (to avoid double toggle)
            if (e.target.closest('.filter-toggle-btn')) {
                e.stopPropagation();
            }
            toggleFilterContent();
        });
        
        // Also keep the button click as a fallback
        if (filterToggleBtn) {
            filterToggleBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                toggleFilterContent();
            });
        }
    }
    
    function toggleFilterContent() {
        const isExpanded = !filterContent.classList.contains('collapsed');
        
        if (isExpanded) {
            // Collapse
            filterContent.classList.add('collapsed');
            if (filterToggleBtn) filterToggleBtn.setAttribute('aria-expanded', 'false');
            if (filterIcon) {
                filterIcon.classList.remove('bi-chevron-up');
                filterIcon.classList.add('bi-chevron-down');
            }
            localStorage.setItem('filterSectionExpanded', 'false');
        } else {
            // Expand
            filterContent.classList.remove('collapsed');
            if (filterToggleBtn) filterToggleBtn.setAttribute('aria-expanded', 'true');
            if (filterIcon) {
                filterIcon.classList.remove('bi-chevron-down');
                filterIcon.classList.add('bi-chevron-up');
            }
            localStorage.setItem('filterSectionExpanded', 'true');
        }
    }
});
</script>
</body>
</html>