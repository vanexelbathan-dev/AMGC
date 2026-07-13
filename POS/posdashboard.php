<?php
/*
    POS Dashboard
    Upload path: POS/posdashboard.php
    Uses the same database connection style as BranchAdmin files.
*/

require_once '../config/database.php';
require_once '../config/session_handler.php';

if (function_exists('requireLogin')) {
    requireLogin();
}

if (function_exists('requireRole')) {
    requireRole(['cashier', 'branch_admin', 'admin', 'super_duper_admin']);
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection failed: BranchAdmin database connection was not loaded. Check ../config/database.php');
}

$conn->set_charset('utf8mb4');


// POS VAT defaults. Actual values are loaded from the current branch settings below.
$posVatRegistered = true;
$posVatRate = 0.12;
$posVatRatePercent = 12.00;

$userId = (int)($_SESSION['user_id'] ?? 0);
$branchId = (int)($_SESSION['branch_id'] ?? 1);
$userRole = strtolower(trim((string)($_SESSION['role'] ?? '')));
$canSwitchToBranchAdmin = in_array($userRole, ['branch_admin', 'admin', 'super_duper_admin'], true);
$cashierFirstName = '';
$cashierLastName = '';

if ($userId > 0) {
    $stmtUser = $conn->prepare("SELECT first_name, last_name, branch_id FROM users WHERE user_id = ? LIMIT 1");

    if ($stmtUser) {
        $stmtUser->bind_param('i', $userId);
        $stmtUser->execute();

        $userResult = $stmtUser->get_result();

        if ($userRow = $userResult->fetch_assoc()) {
            $cashierFirstName = trim((string)($userRow['first_name'] ?? ''));
            $cashierLastName = trim((string)($userRow['last_name'] ?? ''));

            if (!empty($userRow['branch_id'])) {
                $branchId = (int)$userRow['branch_id'];
                $_SESSION['branch_id'] = $branchId;
            }
        }

        $stmtUser->close();
    }
}

if ($cashierFirstName === '' && $cashierLastName === '') {
    $cashierFirstName = trim((string)($_SESSION['first_name'] ?? ''));
    $cashierLastName = trim((string)($_SESSION['last_name'] ?? ''));
}

$cashierName = trim($cashierFirstName . ' ' . $cashierLastName);

if ($cashierName === '') {
    $cashierName = 'Cashier';
}

function jsonExit(array $data): void
{
    echo json_encode($data);
    exit;
}

function fetchAllAssoc(mysqli_stmt $stmt): array
{
    $result = $stmt->get_result();

    if (!$result) {
        return [];
    }

    return $result->fetch_all(MYSQLI_ASSOC);
}


function columnExists(mysqli $conn, string $tableName, string $columnName): bool
{
    $safeTable = $conn->real_escape_string($tableName);
    $safeColumn = $conn->real_escape_string($columnName);
    $result = $conn->query("SHOW COLUMNS FROM `$safeTable` LIKE '$safeColumn'");
    return $result && $result->num_rows > 0;
}

function tableExists(mysqli $conn, string $tableName): bool
{
    $safeTable = $conn->real_escape_string($tableName);
    $result = $conn->query("SHOW TABLES LIKE '$safeTable'");
    return $result && $result->num_rows > 0;
}

function ensurePOSPaymentColumns(mysqli $conn): void
{
    if (!tableExists($conn, 'pos_sales')) {
        return;
    }

    if (!columnExists($conn, 'pos_sales', 'payment_reference_no')) {
        $conn->query("ALTER TABLE pos_sales ADD COLUMN payment_reference_no VARCHAR(120) DEFAULT NULL AFTER payment_method");
    }

    if (!columnExists($conn, 'pos_sales', 'check_no')) {
        $conn->query("ALTER TABLE pos_sales ADD COLUMN check_no VARCHAR(120) DEFAULT NULL AFTER payment_reference_no");
    }
}


function ensurePOSMultiPaymentTable(mysqli $conn): void
{
    $conn->query("CREATE TABLE IF NOT EXISTS pos_sale_payments (
        payment_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        sale_id INT NOT NULL,
        branch_id INT NOT NULL,
        payment_method VARCHAR(40) NOT NULL,
        amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
        reference_no VARCHAR(120) DEFAULT NULL,
        check_no VARCHAR(120) DEFAULT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_sale_id (sale_id),
        KEY idx_branch_date (branch_id, created_at),
        KEY idx_payment_method (payment_method)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
}


function ensurePOSSaleItemUOMColumns(mysqli $conn): void
{
    if (!tableExists($conn, 'pos_sale_items')) {
        return;
    }

    $columns = [
        'uom_id' => "INT DEFAULT NULL AFTER item_id",
        'uom_name' => "VARCHAR(100) DEFAULT NULL AFTER uom_id",
        'uom_initial' => "VARCHAR(50) DEFAULT NULL AFTER uom_name",
        'conversion_qty' => "DECIMAL(14,4) NOT NULL DEFAULT 1.0000 AFTER uom_initial"
    ];

    foreach ($columns as $column => $definition) {
        if (!columnExists($conn, 'pos_sale_items', $column)) {
            $conn->query("ALTER TABLE pos_sale_items ADD COLUMN `$column` $definition");
        }
    }
}



function ensurePOSLoyaltyTables(mysqli $conn): void
{
    if (tableExists($conn, 'customers')) {
        if (!columnExists($conn, 'customers', 'points_balance')) {
            $conn->query("ALTER TABLE customers ADD COLUMN points_balance DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER credit_used");
        }

        if (!columnExists($conn, 'customers', 'membership_status')) {
            $conn->query("ALTER TABLE customers ADD COLUMN membership_status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active' AFTER points_balance");
        }
    }

    if (tableExists($conn, 'items') && !columnExists($conn, 'items', 'points_eligible')) {
        $conn->query("ALTER TABLE items ADD COLUMN points_eligible TINYINT(1) NOT NULL DEFAULT 1 AFTER status");
    }

    if (tableExists($conn, 'pos_sales')) {
        $columns = [
            'customer_id' => "INT DEFAULT NULL AFTER cashier_user_id",
            'customer_code' => "VARCHAR(50) DEFAULT NULL AFTER customer_name",
            'points_redeemed' => "DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER discount_amount",
            'points_discount_amount' => "DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER points_redeemed",
            'points_eligible_amount' => "DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER points_discount_amount",
            'points_earned' => "DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER points_eligible_amount"
        ];

        foreach ($columns as $column => $definition) {
            if (!columnExists($conn, 'pos_sales', $column)) {
                $conn->query("ALTER TABLE pos_sales ADD COLUMN `$column` $definition");
            }
        }
    }

    $conn->query("CREATE TABLE IF NOT EXISTS customer_points_transactions (
        transaction_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        customer_id INT NOT NULL,
        customer_code VARCHAR(50) DEFAULT NULL,
        branch_id INT DEFAULT NULL,
        sale_id INT DEFAULT NULL,
        receipt_no VARCHAR(50) DEFAULT NULL,
        transaction_type ENUM('Earn','Redeem','Adjust') NOT NULL,
        points DECIMAL(14,2) NOT NULL DEFAULT 0.00,
        amount_value DECIMAL(14,2) NOT NULL DEFAULT 0.00,
        eligible_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
        remarks TEXT DEFAULT NULL,
        created_by INT DEFAULT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_customer_date (customer_id, created_at),
        KEY idx_sale_id (sale_id),
        KEY idx_transaction_type (transaction_type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
}

function getPOSCustomerByIdOrCode(mysqli $conn, int $branchId, ?int $customerId, string $customerCode = '', string $customerName = ''): ?array
{
    if (!tableExists($conn, 'customers')) {
        return null;
    }

    ensurePOSLoyaltyTables($conn);

    $customerCode = trim($customerCode);
    $customerName = trim($customerName);

    if ($customerId && $customerId > 0) {
        $stmt = $conn->prepare("SELECT customer_id, customer_name, customer_code, points_balance, membership_status FROM customers WHERE customer_id = ? AND status = 'active' AND (branch_id = ? OR branch_id IS NULL) LIMIT 1");

        if ($stmt) {
            $stmt->bind_param('ii', $customerId, $branchId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($row) {
                return $row;
            }
        }
    }

    if ($customerCode !== '') {
        $stmt = $conn->prepare("SELECT customer_id, customer_name, customer_code, points_balance, membership_status FROM customers WHERE customer_code = ? AND status = 'active' AND (branch_id = ? OR branch_id IS NULL) LIMIT 1");

        if ($stmt) {
            $stmt->bind_param('si', $customerCode, $branchId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($row) {
                return $row;
            }
        }
    }

    if ($customerName !== '' && strtolower($customerName) !== 'walk-in customer') {
        $stmt = $conn->prepare("SELECT customer_id, customer_name, customer_code, points_balance, membership_status FROM customers WHERE customer_name = ? AND status = 'active' AND (branch_id = ? OR branch_id IS NULL) ORDER BY customer_id ASC LIMIT 1");

        if ($stmt) {
            $stmt->bind_param('si', $customerName, $branchId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($row) {
                return $row;
            }
        }
    }

    return null;
}

function isPOSItemPointsEligible(mysqli $conn, int $itemId): bool
{
    if ($itemId <= 0 || !tableExists($conn, 'items')) {
        return true;
    }

    if (!columnExists($conn, 'items', 'points_eligible')) {
        return true;
    }

    $stmt = $conn->prepare("SELECT COALESCE(points_eligible, 1) AS points_eligible FROM items WHERE item_id = ? LIMIT 1");

    if (!$stmt) {
        return true;
    }

    $stmt->bind_param('i', $itemId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return ((int)($row['points_eligible'] ?? 1)) === 1;
}

function calculatePOSPointsEarned(float $eligibleAmount): float
{
    return floor(max(0, $eligibleAmount) / 250);
}



function getPOSPriceLevels(mysqli $conn): array
{
    $levels = [];

    if (tableExists($conn, 'item_unit_pricing') && columnExists($conn, 'item_unit_pricing', 'price_level')) {
        $result = $conn->query("
            SELECT DISTINCT TRIM(price_level) AS price_level
            FROM item_unit_pricing
            WHERE status = 'active'
                AND price_level IS NOT NULL
                AND TRIM(price_level) <> ''
            ORDER BY
                CASE
                    WHEN LOWER(TRIM(price_level)) IN ('walk in', 'walk-in') THEN 1
                    WHEN LOWER(TRIM(price_level)) = 'retail' THEN 2
                    WHEN LOWER(TRIM(price_level)) = 'standard' THEN 3
                    WHEN LOWER(TRIM(price_level)) = 'wholesale' THEN 4
                    ELSE 5
                END,
                price_level ASC
        ");

        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $level = trim((string)($row['price_level'] ?? ''));

                if ($level !== '' && !in_array($level, $levels, true)) {
                    $levels[] = $level;
                }
            }
        }
    }

    if (!$levels) {
        $levels[] = 'Walk In';
    }

    $hasWalkIn = false;

    foreach ($levels as $level) {
        if (in_array(strtolower(trim($level)), ['walk in', 'walk-in'], true)) {
            $hasWalkIn = true;
            break;
        }
    }

    if (!$hasWalkIn) {
        array_unshift($levels, 'Walk In');
    }

    return array_values(array_unique($levels));
}

function posNormalizePriceLevelKey(string $level): string
{
    return strtolower(preg_replace('/[^a-z0-9]+/i', '', trim($level)));
}

function posGetPriceForLevel(array $priceLevels, string $requestedLevel, float $fallbackPrice): float
{
    $requestedKey = posNormalizePriceLevelKey($requestedLevel);
    $standardKey = posNormalizePriceLevelKey('Standard');

    // Strict price-level filtering:
    // 1) Use the exact selected price level.
    // 2) If the selected level is missing, use Standard only.
    // 3) If Standard is also missing, use the item's base/unit price.
    // Do not fall back to Walk In/Retail/first available price because that can show the wrong price
    // while another price level is selected.
    foreach ($priceLevels as $storedLevel => $priceValue) {
        if (posNormalizePriceLevelKey((string)$storedLevel) === $requestedKey && is_numeric($priceValue)) {
            return round((float)$priceValue, 2);
        }
    }

    if ($requestedKey !== $standardKey) {
        foreach ($priceLevels as $storedLevel => $priceValue) {
            if (posNormalizePriceLevelKey((string)$storedLevel) === $standardKey && is_numeric($priceValue)) {
                return round((float)$priceValue, 2);
            }
        }
    }

    return round($fallbackPrice, 2);
}



function ensurePOSBranchSettingsColumns(mysqli $conn): void
{
    if (!columnExists($conn, 'branches', 'is_vat_registered')) {
        $conn->query("ALTER TABLE branches ADD COLUMN is_vat_registered TINYINT(1) NOT NULL DEFAULT 1");
    }

    if (!columnExists($conn, 'branches', 'vat_rate')) {
        $conn->query("ALTER TABLE branches ADD COLUMN vat_rate DECIMAL(5,2) NOT NULL DEFAULT 12.00");
    }

    $receiptColumns = [
        'receipt_logo_image' => "LONGTEXT DEFAULT NULL",
        'receipt_store_name' => "VARCHAR(255) DEFAULT NULL",
        'receipt_address' => "VARCHAR(255) DEFAULT NULL",
        'receipt_tin' => "VARCHAR(80) DEFAULT NULL",
        'receipt_serial_no' => "VARCHAR(120) DEFAULT NULL",
        'receipt_min_no' => "VARCHAR(120) DEFAULT NULL",
        'receipt_permit_no' => "VARCHAR(120) DEFAULT NULL",
        'receipt_accr_no' => "VARCHAR(120) DEFAULT NULL",
        'receipt_supplier_name' => "VARCHAR(255) DEFAULT NULL",
        'receipt_supplier_address' => "VARCHAR(255) DEFAULT NULL",
        'receipt_supplier_tin' => "VARCHAR(80) DEFAULT NULL",
        'receipt_footer_note' => "TEXT DEFAULT NULL",
        'receipt_thank_you_text' => "VARCHAR(255) DEFAULT NULL",
        'receipt_notice_text' => "VARCHAR(255) DEFAULT NULL"
    ];

    foreach ($receiptColumns as $column => $definition) {
        if (!columnExists($conn, 'branches', $column)) {
            $conn->query("ALTER TABLE branches ADD COLUMN `$column` $definition");
        }
    }

    // Make sure larger uploaded receipt logos can be stored safely.
    if (columnExists($conn, 'branches', 'receipt_logo_image')) {
        $conn->query("ALTER TABLE branches MODIFY COLUMN receipt_logo_image LONGTEXT DEFAULT NULL");
    }
}

function getPOSBranchSettings(mysqli $conn, int $branchId): array
{
    ensurePOSBranchSettingsColumns($conn);

    $settings = [
        'branch_name' => 'Store Counter 1',
        'is_vat_registered' => 1,
        'vat_rate' => 12.00,
        'receipt_logo_image' => '',
        'receipt_store_name' => '',
        'receipt_address' => '',
        'receipt_tin' => '',
        'receipt_serial_no' => '',
        'receipt_min_no' => '',
        'receipt_permit_no' => '',
        'receipt_accr_no' => '',
        'receipt_supplier_name' => '',
        'receipt_supplier_address' => '',
        'receipt_supplier_tin' => '',
        'receipt_footer_note' => '',
        'receipt_thank_you_text' => 'Thank You!',
        'receipt_notice_text' => 'This is not an official receipt.'
    ];

    $stmt = $conn->prepare("SELECT branch_name, is_vat_registered, vat_rate, receipt_logo_image, receipt_store_name, receipt_address, receipt_tin, receipt_serial_no, receipt_min_no, receipt_permit_no, receipt_accr_no, receipt_supplier_name, receipt_supplier_address, receipt_supplier_tin, receipt_footer_note, receipt_thank_you_text, receipt_notice_text FROM branches WHERE branch_id = ? LIMIT 1");

    if (!$stmt) {
        return $settings;
    }

    $stmt->bind_param('i', $branchId);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        $settings['branch_name'] = $row['branch_name'] ?: $settings['branch_name'];
        $settings['is_vat_registered'] = isset($row['is_vat_registered']) ? (int)$row['is_vat_registered'] : 1;
        $settings['vat_rate'] = isset($row['vat_rate']) ? (float)$row['vat_rate'] : 12.00;
        foreach (['receipt_logo_image','receipt_store_name','receipt_address','receipt_tin','receipt_serial_no','receipt_min_no','receipt_permit_no','receipt_accr_no','receipt_supplier_name','receipt_supplier_address','receipt_supplier_tin','receipt_footer_note','receipt_thank_you_text','receipt_notice_text'] as $receiptField) {
            $settings[$receiptField] = isset($row[$receiptField]) ? (string)$row[$receiptField] : '';
        }
    }

    $stmt->close();

    return $settings;
}

function ensurePOSAuxTables(mysqli $conn): void
{
    $conn->query("CREATE TABLE IF NOT EXISTS pos_cash_movements (
        movement_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        branch_id INT NOT NULL,
        cashier_user_id INT DEFAULT NULL,
        movement_type ENUM('cash_count','cash_transfer','drawer_open') NOT NULL,
        amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
        notes TEXT DEFAULT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_branch_date (branch_id, created_at),
        KEY idx_cashier (cashier_user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
}

function getOpenPOSShift(mysqli $conn, int $branchId, int $cashierUserId): ?array
{
    ensurePOSAuxTables($conn);

    $stmt = $conn->prepare("SELECT movement_id, amount, notes, created_at FROM pos_cash_movements WHERE branch_id = ? AND cashier_user_id = ? AND movement_type = 'cash_count' AND notes LIKE 'SHIFT_OPEN|%' ORDER BY created_at DESC, movement_id DESC LIMIT 1");

    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('ii', $branchId, $cashierUserId);
    $stmt->execute();
    $open = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$open) {
        return null;
    }

    $stmt = $conn->prepare("SELECT movement_id FROM pos_cash_movements WHERE branch_id = ? AND cashier_user_id = ? AND movement_type = 'cash_count' AND notes LIKE 'SHIFT_CLOSE|%' AND created_at >= ? ORDER BY created_at DESC, movement_id DESC LIMIT 1");

    if (!$stmt) {
        return null;
    }

    $openAt = (string)$open['created_at'];
    $stmt->bind_param('iis', $branchId, $cashierUserId, $openAt);
    $stmt->execute();
    $close = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($close) {
        return null;
    }

    return $open;
}

function getPOSShiftSummary(mysqli $conn, int $branchId, int $cashierUserId): array
{
    ensurePOSAuxTables($conn);
    ensurePOSMultiPaymentTable($conn);

    $open = getOpenPOSShift($conn, $branchId, $cashierUserId);

    if (!$open) {
        return [
            'is_open' => false,
            'shift_id' => null,
            'opened_at' => null,
            'beginning_cash' => 0.00,
            'cash_sales' => 0.00,
            'gcash_sales' => 0.00,
            'online_transfer_sales' => 0.00,
            'check_sales' => 0.00,
            'cash_transfer' => 0.00,
            'cash_transfer_rows' => [],
            'expected_cash' => 0.00
        ];
    }

    $openedAt = (string)$open['created_at'];
    $beginningCash = round((float)$open['amount'], 2);
    $cashSales = 0.00;
    $cashTransfer = 0.00;

    $stmt = $conn->prepare("SELECT COALESCE(SUM(psp.amount), 0) AS total_cash FROM pos_sale_payments psp INNER JOIN pos_sales ps ON ps.sale_id = psp.sale_id WHERE psp.branch_id = ? AND ps.cashier_user_id = ? AND ps.status = 'completed' AND psp.payment_method = 'Cash' AND psp.created_at >= ?");

    if ($stmt) {
        $stmt->bind_param('iis', $branchId, $cashierUserId, $openedAt);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $cashSales = round((float)($row['total_cash'] ?? 0), 2);
        $stmt->close();
    }

    $gcashSales = 0.00;
    $onlineTransferSales = 0.00;
    $checkSales = 0.00;

    $stmt = $conn->prepare("SELECT psp.payment_method, COALESCE(SUM(psp.amount), 0) AS total_amount FROM pos_sale_payments psp INNER JOIN pos_sales ps ON ps.sale_id = psp.sale_id WHERE psp.branch_id = ? AND ps.cashier_user_id = ? AND ps.status = 'completed' AND psp.created_at >= ? GROUP BY psp.payment_method");

    if ($stmt) {
        $stmt->bind_param('iis', $branchId, $cashierUserId, $openedAt);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $method = (string)($row['payment_method'] ?? '');
            $amount = round((float)($row['total_amount'] ?? 0), 2);

            if ($method === 'Cash') {
                $cashSales = $amount;
            } elseif ($method === 'GCash') {
                $gcashSales = $amount;
            } elseif ($method === 'Online Transfer') {
                $onlineTransferSales = $amount;
            } elseif ($method === 'Check') {
                $checkSales = $amount;
            }
        }

        $stmt->close();
    }

    $cashTransfer = 0.00;
    $cashTransferRows = [];
    $stmt = $conn->prepare("SELECT amount, notes, created_at FROM pos_cash_movements WHERE branch_id = ? AND cashier_user_id = ? AND movement_type = 'cash_transfer' AND created_at >= ? ORDER BY created_at ASC, movement_id ASC");

    if ($stmt) {
        $stmt->bind_param('iis', $branchId, $cashierUserId, $openedAt);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $rawNotes = trim((string)($row['notes'] ?? ''));
            $transferType = 'Pick-Up';
            $displayNotes = $rawNotes;

            if (stripos($rawNotes, 'TRANSFER_TYPE|Deposit|') === 0) {
                $transferType = 'Deposit';
                $displayNotes = trim(substr($rawNotes, strlen('TRANSFER_TYPE|Deposit|')));
            } elseif (stripos($rawNotes, 'TRANSFER_TYPE|Pick-Up|') === 0) {
                $transferType = 'Pick-Up';
                $displayNotes = trim(substr($rawNotes, strlen('TRANSFER_TYPE|Pick-Up|')));
            }

            $amount = round((float)($row['amount'] ?? 0), 2);
            $cashTransfer += ($transferType === 'Deposit') ? (-1 * $amount) : $amount;

            $cashTransferRows[] = [
                'amount' => $amount,
                'transfer_type' => $transferType,
                'notes' => $displayNotes,
                'created_at' => (string)($row['created_at'] ?? '')
            ];
        }

        $stmt->close();
    }

    $cashTransfer = round($cashTransfer, 2);
    $expectedCash = round($beginningCash + $cashSales - $cashTransfer, 2);

    return [
        'is_open' => true,
        'shift_id' => (int)$open['movement_id'],
        'opened_at' => $openedAt,
        'beginning_cash' => $beginningCash,
        'cash_sales' => $cashSales,
        'gcash_sales' => $gcashSales,
        'online_transfer_sales' => $onlineTransferSales,
        'check_sales' => $checkSales,
        'cash_transfer' => $cashTransfer,
        'cash_transfer_rows' => $cashTransferRows,
        'expected_cash' => $expectedCash
    ];
}

function getBranchItemStock(mysqli $conn, int $branchId, int $itemId): float
{
    $stmt = $conn->prepare("SELECT COALESCE(inv.quantity_on_hand, i.stock, 0) AS stock_qty FROM items i LEFT JOIN inventory inv ON inv.item_id = i.item_id AND inv.branch_id = ? WHERE i.item_id = ? LIMIT 1");
    if (!$stmt) {
        return 0;
    }
    $stmt->bind_param('ii', $branchId, $itemId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    return (float)($row['stock_qty'] ?? 0);
}


function getBranchItemUOMStock(mysqli $conn, int $branchId, int $itemId, ?int $uomId = null): float
{
    if ($itemId <= 0) {
        return 0;
    }

    if ($uomId && $uomId > 0 && tableExists($conn, 'item_unit_inventory')) {
        $stmt = $conn->prepare("SELECT current_inventory FROM item_unit_inventory WHERE item_id = ? AND unit_type_id = ? AND branch_id = ? AND status = 'active' ORDER BY inventory_id DESC LIMIT 1");

        if ($stmt) {
            $stmt->bind_param('iii', $itemId, $uomId, $branchId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($row) {
                return (float)($row['current_inventory'] ?? 0);
            }
        }
    }

    return getBranchItemStock($conn, $branchId, $itemId);
}

function adjustBranchUOMStock(mysqli $conn, int $branchId, int $itemId, ?int $uomId, float $qtyChange): void
{
    if ($itemId <= 0 || !$uomId || $uomId <= 0 || !tableExists($conn, 'item_unit_inventory')) {
        return;
    }

    $hasBranchId = columnExists($conn, 'item_unit_inventory', 'branch_id');
    $hasStatus = columnExists($conn, 'item_unit_inventory', 'status');
    $hasBeginningInventory = columnExists($conn, 'item_unit_inventory', 'beginning_inventory');
    $hasAsOfDate = columnExists($conn, 'item_unit_inventory', 'as_of_date');
    $hasTotalCost = columnExists($conn, 'item_unit_inventory', 'total_cost');

    // item_unit_inventory in this system has UNIQUE KEY (item_id, unit_type_id).
    // Do not insert another row for the same item + UOM + branch, because it triggers:
    // Duplicate entry 'item_id-unit_type_id' for key 'item_unit_inventory_unique'.
    $whereSql = "item_id = ? AND unit_type_id = ?";
    $types = 'dii';
    $params = [$qtyChange, $itemId, $uomId];

    if ($hasBranchId && $branchId > 0) {
        $whereSql .= " AND (branch_id = ? OR branch_id IS NULL OR branch_id = 0)";
        $types .= 'i';
        $params[] = $branchId;
    }

    if ($hasStatus) {
        $whereSql .= " AND (status IS NULL OR status = '' OR status = 'active')";
    }

    $stmt = $conn->prepare("UPDATE item_unit_inventory SET current_inventory = COALESCE(current_inventory, 0) + ?, updated_at = NOW() WHERE $whereSql LIMIT 1");

    if ($stmt) {
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();

        if ($affected > 0) {
            return;
        }
    }

    // Insert only when no item+UOM row exists yet. If another branch-less row exists,
    // ON DUPLICATE KEY UPDATE safely adjusts that existing row instead of crashing.
    $insertColumns = ['item_id', 'unit_type_id', 'current_inventory', 'unit_cost', 'created_at', 'updated_at'];
    $insertValues = ['?', '?', '?', '0', 'NOW()', 'NOW()'];
    $insertTypes = 'iid';
    $insertParams = [$itemId, $uomId, $qtyChange];

    if ($hasBranchId) {
        $insertColumns[] = 'branch_id';
        $insertValues[] = '?';
        $insertTypes .= 'i';
        $insertParams[] = $branchId;
    }

    if ($hasBeginningInventory) {
        $insertColumns[] = 'beginning_inventory';
        $insertValues[] = '0';
    }

    if ($hasAsOfDate) {
        $insertColumns[] = 'as_of_date';
        $insertValues[] = 'CURDATE()';
    }

    if ($hasTotalCost) {
        $insertColumns[] = 'total_cost';
        $insertValues[] = '0';
    }

    if ($hasStatus) {
        $insertColumns[] = 'status';
        $insertValues[] = "'active'";
    }

    $insertSql = "INSERT INTO item_unit_inventory (" . implode(', ', $insertColumns) . ") VALUES (" . implode(', ', $insertValues) . ")
        ON DUPLICATE KEY UPDATE
            current_inventory = COALESCE(current_inventory, 0) + VALUES(current_inventory),
            updated_at = NOW()";

    $stmt = $conn->prepare($insertSql);

    if ($stmt) {
        $stmt->bind_param($insertTypes, ...$insertParams);
        $stmt->execute();
        $stmt->close();
    }
}

function fetchPOSProductsForPOS(mysqli $conn, int $branchId, string $term = '', ?string $barcode = null, int $limit = 500, string $priceLevel = 'Walk In'): array
{
    $term = trim($term);
    $like = '%' . $term . '%';
    $limit = max(1, min(500, $limit));

    $sql = "
        SELECT 
            i.item_id,
            i.item_code,
            i.barcode,
            i.item_name,
            i.description,
            COALESCE(i.unit_price, 0) AS unit_price,
            COALESCE(i.points_eligible, 1) AS points_eligible,
            i.unit_type,
            i.default_unit_type_id,
            i.default_uom_id,
            i.smallest_uom_id,
            (
                SELECT NULLIF(ut.uom_initial, '')
                FROM unit_types ut
                WHERE (
                    ut.unit_type_id = i.default_uom_id
                    OR ut.unit_type_id = i.default_unit_type_id
                    OR ut.unit_type_name = i.unit_type
                    OR ut.unit_type_name = i.base_unit_type
                )
                AND (
                    ut.branch_id = i.branch_id
                    OR ut.branch_id IS NULL
                    OR ut.branch_id = 0
                )
                ORDER BY
                    CASE
                        WHEN ut.unit_type_id = i.default_uom_id THEN 1
                        WHEN ut.unit_type_id = i.default_unit_type_id THEN 2
                        WHEN ut.unit_type_name = i.unit_type THEN 3
                        ELSE 4
                    END,
                    ut.branch_id DESC
                LIMIT 1
            ) AS uom_initial,
            COALESCE(inv.quantity_on_hand, i.stock, 0) AS stock_qty
        FROM items i
        LEFT JOIN inventory inv 
            ON inv.item_id = i.item_id 
            AND inv.branch_id = ?
        WHERE i.status = 'active'
            AND (
                i.branch_id = ?
                OR inv.branch_id IS NOT NULL
            )
    ";

    $types = 'ii';
    $params = [$branchId, $branchId];

    if ($barcode !== null) {
        $sql .= "
            AND (
                i.barcode = ?
                OR i.item_code = ?
                " . (tableExists($conn, 'item_unit_types') ? "OR EXISTS (SELECT 1 FROM item_unit_types iut_scan WHERE iut_scan.item_id = i.item_id AND iut_scan.status = 'active' AND iut_scan.barcode = ?)" : "") . "
            )
            ORDER BY 
                CASE 
                    WHEN i.barcode = ? THEN 1
                    WHEN i.item_code = ? THEN 2
                    ELSE 3
                END,
                i.item_name ASC
            LIMIT 1
        ";

        if (tableExists($conn, 'item_unit_types')) {
            $types .= 'sssss';
            array_push($params, $barcode, $barcode, $barcode, $barcode, $barcode);
        } else {
            $types .= 'ssss';
            array_push($params, $barcode, $barcode, $barcode, $barcode);
        }
    } else {
        $hasItemUnitTypesForSearch = tableExists($conn, 'item_unit_types');

        $sql .= "
            AND (
                ? = ''
                OR i.item_code LIKE ?
                OR i.barcode LIKE ?
                OR i.item_name LIKE ?
                OR i.description LIKE ?
                " . ($hasItemUnitTypesForSearch ? "OR EXISTS (
                    SELECT 1
                    FROM item_unit_types iut_search
                    WHERE iut_search.item_id = i.item_id
                        AND iut_search.status = 'active'
                        AND (
                            iut_search.barcode LIKE ?
                            OR iut_search.unit_type_name LIKE ?
                            OR iut_search.uom_initial LIKE ?
                        )
                )" : "") . "
            )
            ORDER BY
                CASE
                    WHEN i.item_code = ? THEN 1
                    WHEN i.barcode = ? THEN 2
                    " . ($hasItemUnitTypesForSearch ? "WHEN EXISTS (
                        SELECT 1
                        FROM item_unit_types iut_exact
                        WHERE iut_exact.item_id = i.item_id
                            AND iut_exact.status = 'active'
                            AND iut_exact.barcode = ?
                    ) THEN 3" : "") . "
                    WHEN i.item_code LIKE ? THEN 4
                    WHEN i.barcode LIKE ? THEN 5
                    ELSE 6
                END,
                i.item_name ASC
            LIMIT $limit
        ";

        if ($hasItemUnitTypesForSearch) {
            $types .= 'sssssssssssss';
            array_push($params, $term, $like, $like, $like, $like, $like, $like, $like, $term, $term, $term, $like, $like);
        } else {
            $types .= 'sssssssss';
            array_push($params, $term, $like, $like, $like, $like, $term, $term, $like, $like);
        }
    }

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        throw new Exception($conn->error);
    }

    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $products = fetchAllAssoc($stmt);
    $stmt->close();

    if (!$products) {
        return [];
    }

    // When the cashier is typing/scanning in the QTY & BARCODE search box, the frontend
    // sends the value as `term` first and only sends `barcode` after Enter.
    // Use the exact typed/scanned term as a UOM-selection hint too, so the suggestion row
    // selects the matching per-item UOM barcode instead of falling back to another/default UOM.
    $uomSelectionBarcode = $barcode !== null ? $barcode : ($term !== '' ? $term : null);

    return attachPOSProductUOMs($conn, $branchId, $products, $uomSelectionBarcode, $priceLevel);
}

function attachPOSProductUOMs(mysqli $conn, int $branchId, array $products, ?string $barcode = null, string $priceLevel = 'Walk In'): array
{
    if (!$products) {
        return [];
    }

    $productMap = [];

    foreach ($products as $idx => $product) {
        $products[$idx]['uoms'] = [];
        $products[$idx]['selected_uom_key'] = '';
        $productMap[(int)$product['item_id']] = $idx;
    }

    $itemIds = array_keys($productMap);

    if (tableExists($conn, 'item_unit_types') && $itemIds) {
        $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
        $types = str_repeat('i', count($itemIds));
        $sql = "
            SELECT
                iut.item_id,
                iut.unit_type_id AS uom_id,
                iut.unit_type_name AS uom_name,
                COALESCE(NULLIF(ut.uom_initial, ''), NULLIF(iut.unit_type_name, ''), 'UoM') AS uom_initial,
                COALESCE(
                    (
                        SELECT iup.unit_price
                        FROM item_unit_pricing iup
                        WHERE iup.item_id = iut.item_id
                            AND iup.unit_type_id = iut.unit_type_id
                            AND iup.status = 'active'
                            AND (iup.effective_date IS NULL OR iup.effective_date <= CURDATE())
                            AND (iup.effective_until IS NULL OR iup.effective_until >= CURDATE())
                        ORDER BY
                            CASE
                                WHEN LOWER(TRIM(COALESCE(iup.price_level, ''))) = 'standard' THEN 1
                                ELSE 2
                            END,
                            iup.effective_date DESC,
                            iup.pricing_id DESC
                        LIMIT 1
                    ),
                    i.unit_price,
                    0
                ) AS unit_price,
                GREATEST(
                    COALESCE(NULLIF(iut.smallest_pack_quantity, 0), NULLIF(ut.quantity_smallest_pack, 0), NULLIF(ut.multiplier, 0), 1),
                    1
                ) AS conversion_qty,
                CASE
                    WHEN (
                        SELECT iui.current_inventory
                        FROM item_unit_inventory iui
                        WHERE iui.item_id = iut.item_id
                            AND iui.unit_type_id = iut.unit_type_id
                            AND iui.branch_id = ?
                            AND iui.status = 'active'
                        ORDER BY iui.inventory_id DESC
                        LIMIT 1
                    ) IS NOT NULL
                    THEN (
                        SELECT iui.current_inventory
                        FROM item_unit_inventory iui
                        WHERE iui.item_id = iut.item_id
                            AND iui.unit_type_id = iut.unit_type_id
                            AND iui.branch_id = ?
                            AND iui.status = 'active'
                        ORDER BY iui.inventory_id DESC
                        LIMIT 1
                    )
                    ELSE COALESCE(inv.quantity_on_hand, i.stock, 0) / GREATEST(COALESCE(NULLIF(iut.smallest_pack_quantity, 0), NULLIF(ut.quantity_smallest_pack, 0), NULLIF(ut.multiplier, 0), 1), 1)
                END AS stock_qty,
                COALESCE(iut.is_default_uom, 0) AS is_default_uom,
                COALESCE(iut.barcode, '') AS uom_barcode
            FROM item_unit_types iut
            INNER JOIN items i ON i.item_id = iut.item_id
            LEFT JOIN inventory inv
                ON inv.item_id = i.item_id
                AND inv.branch_id = ?
            LEFT JOIN unit_types ut
                ON (
                    ut.unit_type_id = iut.unit_type_id
                    OR ut.unit_type_name = iut.unit_type_name
                )
                AND (
                    ut.branch_id = i.branch_id
                    OR ut.branch_id IS NULL
                    OR ut.branch_id = 0
                )
            WHERE iut.status = 'active'
                AND iut.item_id IN ($placeholders)
            GROUP BY iut.item_id, iut.unit_type_id, iut.unit_type_name, iut.barcode, iut.smallest_pack_quantity, iut.is_default_uom
            ORDER BY iut.item_id ASC, iut.is_default_uom DESC, iut.unit_type_name ASC
        ";

        $stmt = $conn->prepare($sql);

        if ($stmt) {
            $bindTypes = 'iii' . $types;
            $bindParams = array_merge([$branchId, $branchId, $branchId], $itemIds);
            $stmt->bind_param($bindTypes, ...$bindParams);
            $stmt->execute();
            $result = $stmt->get_result();

            while ($row = $result ? $result->fetch_assoc() : null) {
                $itemId = (int)($row['item_id'] ?? 0);

                if (!isset($productMap[$itemId])) {
                    continue;
                }

                $idx = $productMap[$itemId];
                $uomId = (int)($row['uom_id'] ?? 0);
                $uomName = trim((string)($row['uom_name'] ?? ''));
                $uomInitial = trim((string)($row['uom_initial'] ?? '')) ?: $uomName;
                $uomKey = $uomId > 0 ? 'uom_' . $uomId : 'name_' . md5($uomName);

                $uomRow = [
                    'uom_key' => $uomKey,
                    'uom_id' => $uomId,
                    'uom_name' => $uomName ?: $uomInitial,
                    'uom_initial' => $uomInitial ?: $uomName,
                    'unit_price' => round((float)($row['unit_price'] ?? 0), 2),
                    'conversion_qty' => max(1, (float)($row['conversion_qty'] ?? 1)),
                    'stock_qty' => round((float)($row['stock_qty'] ?? 0), 4),
                    'is_default_uom' => (int)($row['is_default_uom'] ?? 0),
                    'barcode' => trim((string)($row['uom_barcode'] ?? '')),
                    'price_levels' => []
                ];

                $products[$idx]['uoms'][] = $uomRow;

                if ($barcode !== null && $uomRow['barcode'] !== '' && hash_equals($uomRow['barcode'], $barcode)) {
                    $products[$idx]['selected_uom_key'] = $uomKey;
                }
            }

            $stmt->close();
        }
    }


    if (tableExists($conn, 'item_unit_inventory') && $itemIds) {
        $missingItemIds = [];

        foreach ($products as $product) {
            $itemId = (int)($product['item_id'] ?? 0);
            $idx = $productMap[$itemId] ?? null;

            if ($idx !== null && empty($products[$idx]['uoms'])) {
                $missingItemIds[] = $itemId;
            }
        }

        if ($missingItemIds) {
            $placeholders = implode(',', array_fill(0, count($missingItemIds), '?'));
            $types = str_repeat('i', count($missingItemIds));
            $sql = "
                SELECT
                    iui.item_id,
                    iui.unit_type_id AS uom_id,
                    COALESCE(NULLIF(ut.unit_type_name, ''), i.unit_type, 'UoM') AS uom_name,
                    COALESCE(NULLIF(ut.uom_initial, ''), NULLIF(ut.unit_type_name, ''), i.unit_type, 'UoM') AS uom_initial,
                    COALESCE(
                        (
                            SELECT iup.unit_price
                            FROM item_unit_pricing iup
                            WHERE iup.item_id = iui.item_id
                                AND iup.unit_type_id = iui.unit_type_id
                                AND iup.status = 'active'
                                AND (iup.effective_date IS NULL OR iup.effective_date <= CURDATE())
                                AND (iup.effective_until IS NULL OR iup.effective_until >= CURDATE())
                            ORDER BY
                                CASE
                                    WHEN LOWER(TRIM(COALESCE(iup.price_level, ''))) = 'standard' THEN 1
                                    ELSE 2
                                END,
                                iup.effective_date DESC,
                                iup.pricing_id DESC
                            LIMIT 1
                        ),
                        i.unit_price,
                        0
                    ) AS unit_price,
                    GREATEST(COALESCE(NULLIF(ut.quantity_smallest_pack, 0), NULLIF(ut.multiplier, 0), 1), 1) AS conversion_qty,
                    iui.current_inventory AS stock_qty,
                    CASE
                        WHEN i.default_unit_type_id = iui.unit_type_id THEN 1
                        WHEN i.default_uom_id = iui.unit_type_id THEN 1
                        WHEN i.unit_type = ut.unit_type_name THEN 1
                        ELSE COALESCE(ut.is_default_uom, 0)
                    END AS is_default_uom,
                    COALESCE(ut.barcode, '') AS uom_barcode
                FROM item_unit_inventory iui
                INNER JOIN items i ON i.item_id = iui.item_id
                LEFT JOIN unit_types ut
                    ON ut.unit_type_id = iui.unit_type_id
                    AND (
                        ut.branch_id = i.branch_id
                        OR ut.branch_id IS NULL
                        OR ut.branch_id = 0
                    )
                WHERE iui.status = 'active'
                    AND iui.branch_id = ?
                    AND iui.item_id IN ($placeholders)
                ORDER BY iui.item_id ASC, is_default_uom DESC, uom_name ASC
            ";

            $stmt = $conn->prepare($sql);

            if ($stmt) {
                $bindTypes = 'i' . $types;
                $bindParams = array_merge([$branchId], $missingItemIds);
                $stmt->bind_param($bindTypes, ...$bindParams);
                $stmt->execute();
                $result = $stmt->get_result();

                while ($row = $result ? $result->fetch_assoc() : null) {
                    $itemId = (int)($row['item_id'] ?? 0);

                    if (!isset($productMap[$itemId])) {
                        continue;
                    }

                    $idx = $productMap[$itemId];
                    $uomId = (int)($row['uom_id'] ?? 0);
                    $uomName = trim((string)($row['uom_name'] ?? ''));
                    $uomInitial = trim((string)($row['uom_initial'] ?? '')) ?: $uomName;
                    $uomKey = $uomId > 0 ? 'uom_' . $uomId : 'name_' . md5($uomName);

                    $uomRow = [
                        'uom_key' => $uomKey,
                        'uom_id' => $uomId,
                        'uom_name' => $uomName ?: $uomInitial,
                        'uom_initial' => $uomInitial ?: $uomName,
                        'unit_price' => round((float)($row['unit_price'] ?? 0), 2),
                        'conversion_qty' => max(1, (float)($row['conversion_qty'] ?? 1)),
                        'stock_qty' => round((float)($row['stock_qty'] ?? 0), 4),
                        'is_default_uom' => (int)($row['is_default_uom'] ?? 0),
                        'barcode' => trim((string)($row['uom_barcode'] ?? '')),
                        'price_levels' => []
                    ];

                    $products[$idx]['uoms'][] = $uomRow;

                    if ($barcode !== null && $uomRow['barcode'] !== '' && hash_equals($uomRow['barcode'], $barcode)) {
                        $products[$idx]['selected_uom_key'] = $uomKey;
                    }
                }

                $stmt->close();
            }
        }
    }


    // Make sure every item has at least one POS UoM before attaching price levels.
    // This allows products without item_unit_types rows to still receive Standard/selected prices
    // from item_unit_pricing instead of falling back to items.unit_price, which may be a cost mirror.
    foreach ($products as $idx => $product) {
        if (empty($products[$idx]['uoms'])) {
            $uomName = trim((string)($product['unit_type'] ?? '')) ?: trim((string)($product['uom_initial'] ?? '')) ?: 'UoM';
            $uomInitial = trim((string)($product['uom_initial'] ?? '')) ?: $uomName;
            $products[$idx]['uoms'][] = [
                'uom_key' => 'default',
                'uom_id' => (int)($product['default_uom_id'] ?? $product['default_unit_type_id'] ?? 0),
                'uom_name' => $uomName,
                'uom_initial' => $uomInitial,
                'unit_price' => round((float)($product['unit_price'] ?? 0), 2),
                'conversion_qty' => 1,
                'stock_qty' => round((float)($product['stock_qty'] ?? 0), 4),
                'is_default_uom' => 1,
                'barcode' => trim((string)($product['barcode'] ?? '')),
                'price_levels' => []
            ];
        }
    }

    if (tableExists($conn, 'item_unit_pricing') && $itemIds) {
        $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
        $types = str_repeat('i', count($itemIds));
        $sql = "
            SELECT
                iup.item_id,
                iup.unit_type_id,
                TRIM(iup.price_level) AS price_level,
                iup.unit_price,
                COALESCE(NULLIF(ut.unit_type_name, ''), '') AS pricing_uom_name,
                COALESCE(NULLIF(ut.uom_initial, ''), '') AS pricing_uom_initial,
                COALESCE(NULLIF(ut.barcode, ''), '') AS pricing_uom_barcode
            FROM item_unit_pricing iup
            LEFT JOIN unit_types ut
                ON ut.unit_type_id = iup.unit_type_id
            WHERE iup.status = 'active'
                AND iup.item_id IN ($placeholders)
                AND iup.price_level IS NOT NULL
                AND TRIM(iup.price_level) <> ''
                AND (iup.effective_date IS NULL OR iup.effective_date <= CURDATE())
                AND (iup.effective_until IS NULL OR iup.effective_until >= CURDATE())
            ORDER BY
                iup.item_id ASC,
                iup.unit_type_id ASC,
                CASE
                    WHEN LOWER(TRIM(iup.price_level)) = LOWER(TRIM(?)) THEN 1
                    WHEN LOWER(TRIM(iup.price_level)) = 'standard' THEN 2
                    ELSE 3
                END,
                iup.effective_date DESC,
                iup.pricing_id DESC
        ";

        $stmt = $conn->prepare($sql);

        if ($stmt) {
            $bindTypes = $types . 's';
            $bindParams = array_merge($itemIds, [$priceLevel]);
            $stmt->bind_param($bindTypes, ...$bindParams);
            $stmt->execute();
            $result = $stmt->get_result();
            $seenPrices = [];

            while ($row = $result ? $result->fetch_assoc() : null) {
                $itemId = (int)($row['item_id'] ?? 0);
                $uomId = (int)($row['unit_type_id'] ?? 0);
                $priceLevelName = trim((string)($row['price_level'] ?? ''));
                $priceKey = posNormalizePriceLevelKey($priceLevelName);

                if ($itemId <= 0 || $priceLevelName === '' || $priceKey === '' || !isset($productMap[$itemId])) {
                    continue;
                }

                $pricingUomNameKey = posNormalizePriceLevelKey((string)($row['pricing_uom_name'] ?? ''));
                $pricingUomInitialKey = posNormalizePriceLevelKey((string)($row['pricing_uom_initial'] ?? ''));
                $pricingBarcode = trim((string)($row['pricing_uom_barcode'] ?? ''));
                $idx = $productMap[$itemId];
                $matchedUomIndex = null;

                foreach ($products[$idx]['uoms'] as $uomIndex => $uomRow) {
                    $rowUomId = (int)($uomRow['uom_id'] ?? 0);
                    $rowNameKey = posNormalizePriceLevelKey((string)($uomRow['uom_name'] ?? ''));
                    $rowInitialKey = posNormalizePriceLevelKey((string)($uomRow['uom_initial'] ?? ''));
                    $rowBarcode = trim((string)($uomRow['barcode'] ?? ''));

                    $idMatches = $uomId > 0 && $rowUomId > 0 && $rowUomId === $uomId;
                    $nameMatches = $pricingUomNameKey !== '' && ($pricingUomNameKey === $rowNameKey || $pricingUomNameKey === $rowInitialKey);
                    $initialMatches = $pricingUomInitialKey !== '' && ($pricingUomInitialKey === $rowNameKey || $pricingUomInitialKey === $rowInitialKey);
                    $barcodeMatches = $pricingBarcode !== '' && $rowBarcode !== '' && hash_equals($pricingBarcode, $rowBarcode);

                    if ($idMatches || $nameMatches || $initialMatches || $barcodeMatches) {
                        $matchedUomIndex = $uomIndex;
                        break;
                    }
                }

                // If there is only one POS UoM for this item, safely attach the price to it.
                if ($matchedUomIndex === null && count($products[$idx]['uoms']) === 1) {
                    $matchedUomIndex = 0;
                }

                if ($matchedUomIndex === null) {
                    continue;
                }

                $seenKey = $itemId . '|' . $matchedUomIndex . '|' . $priceKey;

                if (isset($seenPrices[$seenKey])) {
                    continue;
                }

                $seenPrices[$seenKey] = true;

                if (!isset($products[$idx]['uoms'][$matchedUomIndex]['price_levels']) || !is_array($products[$idx]['uoms'][$matchedUomIndex]['price_levels'])) {
                    $products[$idx]['uoms'][$matchedUomIndex]['price_levels'] = [];
                }

                $products[$idx]['uoms'][$matchedUomIndex]['price_levels'][$priceLevelName] = round((float)($row['unit_price'] ?? 0), 2);
            }

            $stmt->close();
        }
    }

    foreach ($products as $idx => $product) {
        foreach ($products[$idx]['uoms'] as $uomIndex => $uomRow) {
            $products[$idx]['uoms'][$uomIndex]['unit_price'] = posGetPriceForLevel(
                isset($uomRow['price_levels']) && is_array($uomRow['price_levels']) ? $uomRow['price_levels'] : [],
                $priceLevel,
                (float)($uomRow['unit_price'] ?? 0)
            );
        }
    }

    foreach ($products as $idx => $product) {

        $selectedKey = trim((string)($products[$idx]['selected_uom_key'] ?? ''));
        $selectedUom = null;

        foreach ($products[$idx]['uoms'] as $uomRow) {
            if ($selectedKey !== '' && $uomRow['uom_key'] === $selectedKey) {
                $selectedUom = $uomRow;
                break;
            }

            if (!$selectedUom && !empty($uomRow['is_default_uom'])) {
                $selectedUom = $uomRow;
            }
        }

        if (!$selectedUom) {
            $selectedUom = $products[$idx]['uoms'][0];
        }

        $products[$idx]['selected_uom_key'] = $selectedUom['uom_key'];
        $products[$idx]['uom_id'] = (int)($selectedUom['uom_id'] ?? 0);
        $products[$idx]['uom_name'] = (string)($selectedUom['uom_name'] ?? '');
        $products[$idx]['uom_initial'] = (string)($selectedUom['uom_initial'] ?? '');
        $products[$idx]['unit_price'] = (float)($selectedUom['unit_price'] ?? $products[$idx]['unit_price']);
        $products[$idx]['conversion_qty'] = max(1, (float)($selectedUom['conversion_qty'] ?? 1));
        $products[$idx]['stock_qty'] = (float)($selectedUom['stock_qty'] ?? $products[$idx]['stock_qty']);
    }

    return $products;
}


function adjustBranchStock(mysqli $conn, int $branchId, int $itemId, float $qtyChange, int $userId, string $referenceType, int $referenceId, float $unitCost = 0): void
{
    $stmt = $conn->prepare("UPDATE inventory SET quantity_on_hand = quantity_on_hand + ?, last_updated_by = ?, updated_at = NOW() WHERE branch_id = ? AND item_id = ?");
    if (!$stmt) {
        throw new Exception($conn->error);
    }
    $stmt->bind_param('diii', $qtyChange, $userId, $branchId, $itemId);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    if ($affected === 0) {
        $stmt = $conn->prepare("INSERT INTO inventory (branch_id, item_id, quantity_on_hand, quantity_reserved, last_updated_by, updated_at) VALUES (?, ?, ?, 0, ?, NOW())");
        if (!$stmt) {
            throw new Exception($conn->error);
        }
        $stmt->bind_param('iidi', $branchId, $itemId, $qtyChange, $userId);
        $stmt->execute();
        $stmt->close();
    }

    $stmt = $conn->prepare("UPDATE items SET stock = COALESCE(stock, 0) + ?, updated_by = ?, updated_at = NOW() WHERE item_id = ?");
    if (!$stmt) {
        throw new Exception($conn->error);
    }
    $stmt->bind_param('dii', $qtyChange, $userId, $itemId);
    $stmt->execute();
    $stmt->close();

    $transactionType = $qtyChange >= 0 ? 'return' : 'out';
    $totalCost = abs($qtyChange) * $unitCost;
    $stmt = $conn->prepare("INSERT INTO inventory_transactions (branch_id, item_id, transaction_type, quantity_changed, unit_cost, total_cost, reference_type, reference_id, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
    if (!$stmt) {
        throw new Exception($conn->error);
    }
    $stmt->bind_param('iisdddsii', $branchId, $itemId, $transactionType, $qtyChange, $unitCost, $totalCost, $referenceType, $referenceId, $userId);
    $stmt->execute();
    $stmt->close();
}


function ensurePOSSecurityTables(mysqli $conn): void
{
    $conn->query("CREATE TABLE IF NOT EXISTS pos_action_logs (
        log_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        branch_id INT NOT NULL,
        cashier_user_id INT NOT NULL,
        action_type VARCHAR(80) NOT NULL,
        reference_type VARCHAR(80) DEFAULT NULL,
        reference_id INT DEFAULT NULL,
        item_id INT DEFAULT NULL,
        item_name VARCHAR(255) DEFAULT NULL,
        quantity DECIMAL(14,2) DEFAULT NULL,
        amount DECIMAL(14,2) DEFAULT NULL,
        details TEXT DEFAULT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_branch_action_date (branch_id, action_type, created_at),
        KEY idx_cashier_date (cashier_user_id, created_at),
        KEY idx_reference (reference_type, reference_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
}

function logPOSAction(mysqli $conn, int $branchId, int $cashierUserId, string $actionType, ?string $referenceType = null, ?int $referenceId = null, ?int $itemId = null, ?string $itemName = null, ?float $quantity = null, ?float $amount = null, ?string $details = null): void
{
    ensurePOSSecurityTables($conn);

    $stmt = $conn->prepare("
        INSERT INTO pos_action_logs
        (
            branch_id,
            cashier_user_id,
            action_type,
            reference_type,
            reference_id,
            item_id,
            item_name,
            quantity,
            amount,
            details,
            created_at
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");

    if (!$stmt) {
        return;
    }

    $stmt->bind_param(
        'iissiisdds',
        $branchId,
        $cashierUserId,
        $actionType,
        $referenceType,
        $referenceId,
        $itemId,
        $itemName,
        $quantity,
        $amount,
        $details
    );

    $stmt->execute();
    $stmt->close();
}

function getUserPasswordHash(mysqli $conn, int $userId): ?string
{
    if ($userId <= 0) {
        return null;
    }

    $passwordColumns = ['password', 'user_password', 'password_hash', 'pass'];

    foreach ($passwordColumns as $column) {
        if (!columnExists($conn, 'users', $column)) {
            continue;
        }

        $stmt = $conn->prepare("SELECT `$column` AS password_value FROM users WHERE user_id = ? LIMIT 1");

        if (!$stmt) {
            continue;
        }

        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        $passwordValue = trim((string)($row['password_value'] ?? ''));

        if ($passwordValue !== '') {
            return $passwordValue;
        }
    }

    return null;
}

function verifyPasswordValue(string $enteredPassword, ?string $storedPassword): bool
{
    $enteredPassword = (string)$enteredPassword;
    $storedPassword = trim((string)($storedPassword ?? ''));

    if ($enteredPassword === '' || $storedPassword === '') {
        return false;
    }

    if (password_verify($enteredPassword, $storedPassword)) {
        return true;
    }

    return hash_equals($storedPassword, $enteredPassword);
}

function verifyCurrentCashierPassword(mysqli $conn, int $userId, string $enteredPassword): bool
{
    $enteredPassword = (string)$enteredPassword;

    if ($userId <= 0 || $enteredPassword === '') {
        return false;
    }

    return verifyPasswordValue($enteredPassword, getUserPasswordHash($conn, $userId));
}

function verifyBranchAdminPassword(mysqli $conn, int $branchId, string $enteredPassword): ?array
{
    $enteredPassword = (string)$enteredPassword;

    if ($enteredPassword === '') {
        return null;
    }

    $passwordColumns = ['password', 'user_password', 'password_hash', 'pass'];
    $roleColumnExists = columnExists($conn, 'users', 'role');
    $branchColumnExists = columnExists($conn, 'users', 'branch_id');
    $statusColumnExists = columnExists($conn, 'users', 'status');

    if (!$roleColumnExists) {
        return null;
    }

    foreach ($passwordColumns as $column) {
        if (!columnExists($conn, 'users', $column)) {
            continue;
        }

        $whereParts = ["LOWER(TRIM(role)) IN ('branch_admin', 'admin', 'super_duper_admin')", "`$column` IS NOT NULL", "TRIM(`$column`) <> ''"];
        $types = '';
        $params = [];

        if ($branchColumnExists) {
            $whereParts[] = "(LOWER(TRIM(role)) IN ('admin', 'super_duper_admin') OR branch_id = ?)";
            $types .= 'i';
            $params[] = $branchId;
        }

        if ($statusColumnExists) {
            $whereParts[] = "(status IS NULL OR TRIM(status) = '' OR LOWER(TRIM(status)) = 'active')";
        }

        $sql = "SELECT user_id, first_name, last_name, role, `$column` AS password_value FROM users WHERE " . implode(' AND ', $whereParts) . " ORDER BY CASE WHEN LOWER(TRIM(role)) = 'branch_admin' THEN 1 WHEN LOWER(TRIM(role)) = 'admin' THEN 2 ELSE 3 END, user_id ASC";
        $stmt = $conn->prepare($sql);

        if (!$stmt) {
            continue;
        }

        if ($types !== '') {
            $stmt->bind_param($types, ...$params);
        }

        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result ? $result->fetch_assoc() : null) {
            if (verifyPasswordValue($enteredPassword, (string)($row['password_value'] ?? ''))) {
                $stmt->close();
                return [
                    'user_id' => (int)($row['user_id'] ?? 0),
                    'name' => trim((string)($row['first_name'] ?? '') . ' ' . (string)($row['last_name'] ?? '')),
                    'role' => (string)($row['role'] ?? '')
                ];
            }
        }

        $stmt->close();
    }

    return null;
}


function getOrCreatePOSWalkInCustomerId(mysqli $conn, int $branchId, int $userId): int
{
    if (!tableExists($conn, 'customers')) {
        throw new Exception('Customers table not found. Cannot create POS walk-in sales order.');
    }

    $stmt = $conn->prepare("SELECT customer_id FROM customers WHERE branch_id = ? AND LOWER(TRIM(customer_name)) = 'walk-in customer' ORDER BY customer_id ASC LIMIT 1");

    if ($stmt) {
        $stmt->bind_param('i', $branchId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row && (int)($row['customer_id'] ?? 0) > 0) {
            return (int)$row['customer_id'];
        }
    }

    $customerName = 'Walk-in Customer';
    $customerCode = 'WALKIN-POS-' . $branchId;
    $priceLevel = 'Standard';
    $email = 'walkin@example.com';
    $phone = 'N/A';
    $address = 'Walk-in Customer - No fixed address';
    $status = 'active';
    $createdBy = $userId > 0 ? $userId : null;

    $stmt = $conn->prepare("INSERT INTO customers (customer_name, customer_code, price_level, email, phone_number, address, status, branch_id, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");

    if (!$stmt) {
        throw new Exception($conn->error);
    }

    $stmt->bind_param('sssssssii', $customerName, $customerCode, $priceLevel, $email, $phone, $address, $status, $branchId, $createdBy);
    $stmt->execute();
    $customerId = (int)$conn->insert_id;
    $stmt->close();

    if ($customerId <= 0) {
        throw new Exception('Unable to create Walk-in Customer record.');
    }

    return $customerId;
}

function createPOSSalesOrderFromSale(mysqli $conn, int $branchId, int $userId, int $customerId, string $customerName, float $subtotal, float $totalDiscount, float $amountDue, int $saleId, string $receiptNo, array $cleanItems): int
{
    if (!tableExists($conn, 'sales_orders')) {
        throw new Exception('sales_orders table not found.');
    }

    if (!tableExists($conn, 'sales_order_items')) {
        throw new Exception('sales_order_items table not found.');
    }

    // Use the actual selected POS customer for the Branch Admin Sales Order.
    // Previously this always used Walk-in Customer, so even selected members/customers
    // appeared as Walk-in in the Sales Order module.
    $actualCustomerId = 0;
    $customerName = trim((string)$customerName) !== '' ? trim((string)$customerName) : 'Walk-in Customer';

    if ($customerId > 0 && tableExists($conn, 'customers')) {
        $customerStmt = $conn->prepare("SELECT customer_id, customer_name FROM customers WHERE customer_id = ? AND status = 'active' AND (branch_id = ? OR branch_id IS NULL OR branch_id = 0) LIMIT 1");
        if ($customerStmt) {
            $customerStmt->bind_param('ii', $customerId, $branchId);
            $customerStmt->execute();
            $customerRow = $customerStmt->get_result()->fetch_assoc();
            $customerStmt->close();

            if ($customerRow && (int)($customerRow['customer_id'] ?? 0) > 0) {
                $actualCustomerId = (int)$customerRow['customer_id'];
                $dbCustomerName = trim((string)($customerRow['customer_name'] ?? ''));
                if ($dbCustomerName !== '') {
                    $customerName = $dbCustomerName;
                }
            }
        }
    }

    if ($actualCustomerId <= 0) {
        $actualCustomerId = getOrCreatePOSWalkInCustomerId($conn, $branchId, $userId);
        $customerName = 'Walk-in Customer';
    }

    $soNumber = 'SO-POS-' . date('Ymd-His') . '-' . $saleId;
    $documentType = 'SO';
    $billingType = 'invoice';
    $deliveryDate = date('Y-m-d');
    $orderStatus = 'delivered';
    $fulfillmentType = 'walk-in';
    $paymentStatus = 'paid';
    $remarks = 'Auto-created from POS sale ' . $receiptNo . ' for ' . $customerName;
    $createdBy = $userId > 0 ? $userId : 0;
    $grossProfit = $amountDue;
    $grossProfitAmount = $amountDue;
    $zero = 0.00;

    $stmt = $conn->prepare("
        INSERT INTO sales_orders
        (
            so_number,
            document_type,
            billing_type,
            registered_business_name,
            customer_id,
            branch_id,
            order_date,
            delivery_date,
            total_amount,
            discount_percent,
            discount_amount,
            discount_calculation_type,
            discount_based_amount,
            order_amount,
            total_discount,
            total_discount_amount,
            cogs_amount,
            gross_profit,
            gross_profit_amount,
            order_status,
            fulfillment_type,
            payment_status,
            created_by,
            created_at,
            updated_at,
            confirmed_at,
            confirmed_by,
            remarks
        )
        VALUES (?, ?, ?, ?, ?, ?, NOW(), ?, ?, 0, ?, 'amount_based', ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?, NOW(), NOW(), NOW(), ?, ?)
    ");

    if (!$stmt) {
        throw new Exception($conn->error);
    }

    $stmt->bind_param(
        'ssssiisddddddddsssiis',
        $soNumber,
        $documentType,
        $billingType,
        $customerName,
        $actualCustomerId,
        $branchId,
        $deliveryDate,
        $amountDue,
        $totalDiscount,
        $subtotal,
        $amountDue,
        $totalDiscount,
        $totalDiscount,
        $grossProfit,
        $grossProfitAmount,
        $orderStatus,
        $fulfillmentType,
        $paymentStatus,
        $createdBy,
        $createdBy,
        $remarks
    );

    $stmt->execute();
    $salesOrderId = (int)$conn->insert_id;
    $stmt->close();

    if ($salesOrderId <= 0) {
        throw new Exception('Unable to create POS sales order.');
    }

    $itemStmt = $conn->prepare("
        INSERT INTO sales_order_items
        (
            so_id,
            item_id,
            unit_type,
            quantity_ordered,
            quantity_delivered,
            gross_price,
            discount_type,
            discount_value,
            discount_amount,
            net_price,
            order_amount,
            total_discount,
            ave_cost,
            cogs_amount,
            gross_profit,
            ave_cost_snapshot,
            unit_price
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, ?, 0, ?)
    ");

    if (!$itemStmt) {
        throw new Exception($conn->error);
    }

    foreach ($cleanItems as $ci) {
        $itemId = (int)$ci['itemId'];
        $unitType = trim((string)($ci['uomName'] ?? '')) ?: (trim((string)($ci['uomInitial'] ?? '')) ?: 'piece');
        $quantityOrdered = max(1, (int)round((float)$ci['qty']));
        $quantityDelivered = $quantityOrdered;
        $grossPrice = round((float)$ci['price'], 2);
        $discountAmount = round((float)$ci['discountAmount'], 2);
        $discountType = $discountAmount > 0 ? 'peso' : 'computed';
        $discountValue = $discountAmount;
        $netPrice = round(max(0, $grossPrice - ($discountAmount / max(1, $quantityOrdered))), 2);
        $orderAmount = round((float)$ci['lineTotal'], 2);
        $lineDiscount = $discountAmount;
        $lineGrossProfit = $orderAmount;
        $unitPrice = $grossPrice;

        $itemStmt->bind_param(
            'iisiddsddddddd',
            $salesOrderId,
            $itemId,
            $unitType,
            $quantityOrdered,
            $quantityDelivered,
            $grossPrice,
            $discountType,
            $discountValue,
            $discountAmount,
            $netPrice,
            $orderAmount,
            $lineDiscount,
            $lineGrossProfit,
            $unitPrice
        );

        $itemStmt->execute();
    }

    $itemStmt->close();

    return $salesOrderId;
}


$branchSettings = getPOSBranchSettings($conn, $branchId);
$branchName = (string)($branchSettings['branch_name'] ?? 'Store Counter 1');
$posReceiptInfo = [
    'logo_image' => trim((string)($branchSettings['receipt_logo_image'] ?? '')),
    'store_name' => trim((string)($branchSettings['receipt_store_name'] ?? '')) ?: 'AMGC STORE',
    'address' => trim((string)($branchSettings['receipt_address'] ?? '')),
    'tin' => trim((string)($branchSettings['receipt_tin'] ?? '')),
    'serial_no' => trim((string)($branchSettings['receipt_serial_no'] ?? '')),
    'min_no' => trim((string)($branchSettings['receipt_min_no'] ?? '')),
    'permit_no' => trim((string)($branchSettings['receipt_permit_no'] ?? '')),
    'accr_no' => trim((string)($branchSettings['receipt_accr_no'] ?? '')),
    'supplier_name' => trim((string)($branchSettings['receipt_supplier_name'] ?? '')),
    'supplier_address' => trim((string)($branchSettings['receipt_supplier_address'] ?? '')),
    'supplier_tin' => trim((string)($branchSettings['receipt_supplier_tin'] ?? '')),
    'footer_note' => trim((string)($branchSettings['receipt_footer_note'] ?? '')) ?: 'Exchange of item for reasons other than those provided under the Consumer Act will only be allowed within 7 days from date of purchase. Please present this Official Receipt.',
    'thank_you_text' => trim((string)($branchSettings['receipt_thank_you_text'] ?? '')) ?: 'Thank You!',
    'notice_text' => trim((string)($branchSettings['receipt_notice_text'] ?? '')) ?: 'This is not an official receipt.'
];
$posVatRegistered = ((int)($branchSettings['is_vat_registered'] ?? 1)) === 1;
$posVatRatePercent = max(0, (float)($branchSettings['vat_rate'] ?? 12.00));
$posVatRate = $posVatRatePercent / 100;
ensurePOSLoyaltyTables($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    $raw = file_get_contents('php://input');
    $payload = json_decode($raw, true);

    if (!is_array($payload)) {
        jsonExit([
            'success' => false,
            'message' => 'Invalid request.'
        ]);
    }

    $action = $payload['action'] ?? '';

    if ($action === 'verify_cashier_password') {
        $password = (string)($payload['password'] ?? '');
        $actionType = trim((string)($payload['action_type'] ?? 'PASSWORD_APPROVAL'));

        if (!verifyCurrentCashierPassword($conn, $userId, $password)) {
            jsonExit([
                'success' => false,
                'message' => 'Incorrect password.'
            ]);
        }

        $itemId = isset($payload['item_id']) ? (int)$payload['item_id'] : null;
        $itemName = isset($payload['item_name']) ? trim((string)$payload['item_name']) : null;
        $quantity = isset($payload['quantity']) ? (float)$payload['quantity'] : null;
        $amount = isset($payload['amount']) ? (float)$payload['amount'] : null;
        $details = isset($payload['details']) ? trim((string)$payload['details']) : null;

        logPOSAction($conn, $branchId, $userId, $actionType, 'pos_cart', null, $itemId, $itemName, $quantity, $amount, $details);

        jsonExit([
            'success' => true,
            'message' => 'Password approved.'
        ]);
    }

    if ($action === 'verify_branch_admin_password') {
        $password = (string)($payload['password'] ?? '');
        $actionType = trim((string)($payload['action_type'] ?? 'BRANCH_ADMIN_APPROVAL'));

        $approver = verifyBranchAdminPassword($conn, $branchId, $password);

        if (!$approver) {
            jsonExit([
                'success' => false,
                'message' => 'Incorrect Branch Admin password.'
            ]);
        }

        $itemId = isset($payload['item_id']) ? (int)$payload['item_id'] : null;
        $itemName = isset($payload['item_name']) ? trim((string)$payload['item_name']) : null;
        $quantity = isset($payload['quantity']) ? (float)$payload['quantity'] : null;
        $amount = isset($payload['amount']) ? (float)$payload['amount'] : null;
        $details = isset($payload['details']) ? trim((string)$payload['details']) : null;
        $approverDetails = trim(($details !== '' ? $details . ' | ' : '') . 'Approved by Branch Admin: ' . (($approver['name'] ?? '') ?: ('User #' . (int)($approver['user_id'] ?? 0))));

        logPOSAction($conn, $branchId, $userId, $actionType, 'pos_admin_approval', (int)($approver['user_id'] ?? 0), $itemId, $itemName, $quantity, $amount, $approverDetails);

        jsonExit([
            'success' => true,
            'message' => 'Branch Admin password approved.',
            'approver' => [
                'name' => (string)($approver['name'] ?? ''),
                'role' => (string)($approver['role'] ?? '')
            ]
        ]);
    }

    if ($action === 'get_branch_settings') {
        $settings = getPOSBranchSettings($conn, $branchId);

        jsonExit([
            'success' => true,
            'branch_name' => $settings['branch_name'],
            'is_vat_registered' => (int)$settings['is_vat_registered'],
            'vat_rate' => (float)$settings['vat_rate'],
            'receipt_logo_image' => (string)($settings['receipt_logo_image'] ?? ''),
            'receipt_store_name' => (string)($settings['receipt_store_name'] ?? ''),
            'receipt_address' => (string)($settings['receipt_address'] ?? ''),
            'receipt_tin' => (string)($settings['receipt_tin'] ?? ''),
            'receipt_serial_no' => (string)($settings['receipt_serial_no'] ?? ''),
            'receipt_min_no' => (string)($settings['receipt_min_no'] ?? ''),
            'receipt_permit_no' => (string)($settings['receipt_permit_no'] ?? ''),
            'receipt_accr_no' => (string)($settings['receipt_accr_no'] ?? ''),
            'receipt_supplier_name' => (string)($settings['receipt_supplier_name'] ?? ''),
            'receipt_supplier_address' => (string)($settings['receipt_supplier_address'] ?? ''),
            'receipt_supplier_tin' => (string)($settings['receipt_supplier_tin'] ?? ''),
            'receipt_footer_note' => (string)($settings['receipt_footer_note'] ?? ''),
            'receipt_thank_you_text' => (string)($settings['receipt_thank_you_text'] ?? 'Thank You!'),
            'receipt_notice_text' => (string)($settings['receipt_notice_text'] ?? 'This is not an official receipt.')
        ]);
    }

    if ($action === 'save_branch_settings') {
        ensurePOSBranchSettingsColumns($conn);

        $isVatRegistered = !empty($payload['is_vat_registered']) ? 1 : 0;
        $vatRate = max(0, min(100, (float)($payload['vat_rate'] ?? 12.00)));

        if ($isVatRegistered === 0) {
            $vatRate = 0.00;
        }

        $receiptLogoImage = trim((string)($payload['receipt_logo_image'] ?? ''));

        if ($receiptLogoImage !== '' && strlen($receiptLogoImage) > (900 * 1024)) {
            jsonExit([
                'success' => false,
                'message' => 'Logo is still too large for the database packet limit. Please upload a smaller image or lower the image resolution.'
            ]);
        }
        $receiptStoreName = trim((string)($payload['receipt_store_name'] ?? ''));
        $receiptAddress = trim((string)($payload['receipt_address'] ?? ''));
        $receiptTin = trim((string)($payload['receipt_tin'] ?? ''));
        $receiptSerialNo = trim((string)($payload['receipt_serial_no'] ?? ''));
        $receiptMinNo = trim((string)($payload['receipt_min_no'] ?? ''));
        $receiptPermitNo = trim((string)($payload['receipt_permit_no'] ?? ''));
        $receiptAccrNo = trim((string)($payload['receipt_accr_no'] ?? ''));
        $receiptSupplierName = trim((string)($payload['receipt_supplier_name'] ?? ''));
        $receiptSupplierAddress = trim((string)($payload['receipt_supplier_address'] ?? ''));
        $receiptSupplierTin = trim((string)($payload['receipt_supplier_tin'] ?? ''));
        $receiptFooterNote = trim((string)($payload['receipt_footer_note'] ?? ''));
        $receiptThankYouText = trim((string)($payload['receipt_thank_you_text'] ?? 'Thank You!'));
        $receiptNoticeText = trim((string)($payload['receipt_notice_text'] ?? 'This is not an official receipt.'));

        $stmt = $conn->prepare("UPDATE branches SET is_vat_registered = ?, vat_rate = ?, receipt_logo_image = ?, receipt_store_name = ?, receipt_address = ?, receipt_tin = ?, receipt_serial_no = ?, receipt_min_no = ?, receipt_permit_no = ?, receipt_accr_no = ?, receipt_supplier_name = ?, receipt_supplier_address = ?, receipt_supplier_tin = ?, receipt_footer_note = ?, receipt_thank_you_text = ?, receipt_notice_text = ? WHERE branch_id = ?");

        if (!$stmt) {
            jsonExit([
                'success' => false,
                'message' => $conn->error
            ]);
        }

        $stmt->bind_param('idssssssssssssssi', $isVatRegistered, $vatRate, $receiptLogoImage, $receiptStoreName, $receiptAddress, $receiptTin, $receiptSerialNo, $receiptMinNo, $receiptPermitNo, $receiptAccrNo, $receiptSupplierName, $receiptSupplierAddress, $receiptSupplierTin, $receiptFooterNote, $receiptThankYouText, $receiptNoticeText, $branchId);

        try {
            $executeOk = $stmt->execute();
        } catch (mysqli_sql_exception $e) {
            $errorMessage = $e->getMessage();
            $stmt->close();

            if (stripos($errorMessage, 'max_allowed_packet') !== false || stripos($errorMessage, 'packet bigger') !== false) {
                $errorMessage = 'Logo image is too large for MySQL max_allowed_packet. The file was not saved. Please use a smaller logo or increase max_allowed_packet in MySQL.';
            }

            jsonExit([
                'success' => false,
                'message' => $errorMessage
            ]);
        }

        if (!$executeOk) {
            $errorMessage = $stmt->error ?: $conn->error ?: 'Unable to save branch settings.';
            $stmt->close();

            jsonExit([
                'success' => false,
                'message' => $errorMessage
            ]);
        }

        $stmt->close();

        jsonExit([
            'success' => true,
            'message' => 'Branch tax and receipt settings updated.',
            'is_vat_registered' => $isVatRegistered,
            'vat_rate' => $vatRate,
            'receipt_logo_image' => $receiptLogoImage,
            'receipt_store_name' => $receiptStoreName,
            'receipt_address' => $receiptAddress,
            'receipt_tin' => $receiptTin,
            'receipt_serial_no' => $receiptSerialNo,
            'receipt_min_no' => $receiptMinNo,
            'receipt_permit_no' => $receiptPermitNo,
            'receipt_accr_no' => $receiptAccrNo,
            'receipt_supplier_name' => $receiptSupplierName,
            'receipt_supplier_address' => $receiptSupplierAddress,
            'receipt_supplier_tin' => $receiptSupplierTin,
            'receipt_footer_note' => $receiptFooterNote,
            'receipt_thank_you_text' => $receiptThankYouText,
            'receipt_notice_text' => $receiptNoticeText
        ]);
    }

    if ($action === 'search_product') {
        $term = trim($payload['term'] ?? '');
        $priceLevel = trim((string)($payload['price_level'] ?? 'Walk In')) ?: 'Walk In';

        try {
            $products = fetchPOSProductsForPOS($conn, $branchId, $term, null, 500, $priceLevel);

            jsonExit([
                'success' => true,
                'products' => $products
            ]);
        } catch (Throwable $e) {
            jsonExit([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }

    if ($action === 'scan_barcode') {
        $barcode = trim((string)($payload['barcode'] ?? ''));
        $priceLevel = trim((string)($payload['price_level'] ?? 'Walk In')) ?: 'Walk In';

        if ($barcode === '') {
            jsonExit([
                'success' => false,
                'message' => 'No barcode scanned.'
            ]);
        }

        try {
            $products = fetchPOSProductsForPOS($conn, $branchId, '', $barcode, 1, $priceLevel);

            if (!$products) {
                jsonExit([
                    'success' => false,
                    'message' => 'Barcode not found.'
                ]);
            }

            jsonExit([
                'success' => true,
                'product' => $products[0]
            ]);
        } catch (Throwable $e) {
            jsonExit([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }

    if ($action === 'save_sale') {
        ensurePOSPaymentColumns($conn);
        ensurePOSMultiPaymentTable($conn);
        ensurePOSSaleItemUOMColumns($conn);
        ensurePOSLoyaltyTables($conn);

        $items = $payload['items'] ?? [];
        $customerName = trim($payload['customer_name'] ?? 'Walk-in Customer');
        $paymentsPayload = $payload['payments'] ?? [];
        $cleanPayments = [];

        if (is_array($paymentsPayload) && !empty($paymentsPayload)) {
            foreach ($paymentsPayload as $paymentRow) {
                if (!is_array($paymentRow)) {
                    continue;
                }

                $method = trim((string)($paymentRow['payment_method'] ?? ''));
                $amount = round(max(0, (float)($paymentRow['amount'] ?? 0)), 2);
                $referenceNo = trim((string)($paymentRow['reference_no'] ?? ''));
                $rowCheckNo = trim((string)($paymentRow['check_no'] ?? ''));

                if ($method === '' || $amount <= 0) {
                    continue;
                }

                if (!in_array($method, ['Cash', 'GCash', 'Online Transfer', 'Check'], true)) {
                    jsonExit([
                        'success' => false,
                        'message' => 'Invalid payment method.'
                    ]);
                }

                if (in_array($method, ['GCash', 'Online Transfer'], true) && $referenceNo === '') {
                    jsonExit([
                        'success' => false,
                        'message' => 'Reference number is required for ' . $method . '.'
                    ]);
                }

                if ($method === 'Check' && $rowCheckNo === '') {
                    jsonExit([
                        'success' => false,
                        'message' => 'Check No. is required for Check payment.'
                    ]);
                }

                $cleanPayments[] = [
                    'method' => $method,
                    'amount' => $amount,
                    'reference_no' => $referenceNo,
                    'check_no' => $rowCheckNo
                ];
            }
        }

        if (!$cleanPayments) {
            $legacyMethod = trim((string)($payload['payment_method'] ?? 'Cash')) ?: 'Cash';
            $legacyTendered = round(max(0, (float)($payload['tendered'] ?? 0)), 2);
            $legacyReferenceNo = trim((string)($payload['payment_reference_no'] ?? ''));
            $legacyCheckNo = trim((string)($payload['check_no'] ?? ''));

            if (in_array($legacyMethod, ['GCash', 'Online Transfer'], true) && $legacyReferenceNo === '') {
                jsonExit([
                    'success' => false,
                    'message' => 'Reference number is required for ' . $legacyMethod . '.'
                ]);
            }

            if ($legacyMethod === 'Check' && $legacyCheckNo === '') {
                jsonExit([
                    'success' => false,
                    'message' => 'Check No. is required for Check payment.'
                ]);
            }

            $cleanPayments[] = [
                'method' => $legacyMethod,
                'amount' => $legacyTendered,
                'reference_no' => $legacyReferenceNo,
                'check_no' => $legacyCheckNo
            ];
        }

        if (!$items || !is_array($items)) {
            jsonExit([
                'success' => false,
                'message' => 'No item in cart.'
            ]);
        }

        $subtotal = 0;
        $totalDiscount = 0;
        $cleanItems = [];

        foreach ($items as $row) {
            $itemId = (int)($row['item_id'] ?? 0);
            $qty = max(0, (float)($row['qty'] ?? 0));
            $price = max(0, (float)($row['price'] ?? 0));
            $discountAmount = max(0, (float)($row['discount_amount'] ?? 0));
            $uomId = isset($row['uom_id']) ? (int)$row['uom_id'] : null;
            $uomName = trim((string)($row['uom_name'] ?? ''));
            $uomInitial = trim((string)($row['uom_initial'] ?? ''));
            $conversionQty = max(1, (float)($row['conversion_qty'] ?? 1));
            $pointsEligible = isPOSItemPointsEligible($conn, $itemId);

            if ($itemId <= 0 || $qty <= 0) {
                continue;
            }

            $lineGross = $qty * $price;
            $lineTotal = max(0, $lineGross - $discountAmount);
            $inventoryQty = $qty * $conversionQty;

            $subtotal += $lineGross;
            $totalDiscount += $discountAmount;

            $cleanItems[] = [
                'itemId' => $itemId,
                'qty' => $qty,
                'inventoryQty' => $inventoryQty,
                'price' => $price,
                'discountAmount' => $discountAmount,
                'lineTotal' => $lineTotal,
                'uomId' => $uomId,
                'uomName' => $uomName,
                'uomInitial' => $uomInitial,
                'conversionQty' => $conversionQty,
                'pointsEligible' => $pointsEligible
            ];
        }

        if (!$cleanItems) {
            jsonExit([
                'success' => false,
                'message' => 'Invalid cart items.'
            ]);
        }

        $selectedCustomerId = isset($payload['customer_id']) ? (int)$payload['customer_id'] : 0;
        $selectedCustomerCode = trim((string)($payload['customer_code'] ?? ''));
        $selectedCustomer = getPOSCustomerByIdOrCode($conn, $branchId, $selectedCustomerId, $selectedCustomerCode, $customerName);

        if ($selectedCustomer) {
            $selectedCustomerId = (int)($selectedCustomer['customer_id'] ?? 0);
            $selectedCustomerCode = trim((string)($selectedCustomer['customer_code'] ?? ''));
            $customerName = trim((string)($selectedCustomer['customer_name'] ?? $customerName)) ?: $customerName;
        }

        $pointsBefore = $selectedCustomer ? round((float)($selectedCustomer['points_balance'] ?? 0), 2) : 0.00;
        $requestedRedeemPoints = round(max(0, (float)($payload['points_redeemed'] ?? 0)), 2);

        if (!$selectedCustomer && $requestedRedeemPoints > 0) {
            jsonExit([
                'success' => false,
                'message' => 'Please select a valid customer before redeeming points.'
            ]);
        }

        if ($selectedCustomer && strtolower((string)($selectedCustomer['membership_status'] ?? 'Active')) !== 'active' && $requestedRedeemPoints > 0) {
            jsonExit([
                'success' => false,
                'message' => 'Customer membership is inactive.'
            ]);
        }

        if ($requestedRedeemPoints > $pointsBefore) {
            jsonExit([
                'success' => false,
                'message' => 'Redeem points cannot exceed available customer points.'
            ]);
        }

        $amountBeforePoints = round($subtotal - $totalDiscount, 2);
        $pointsRedeemed = min($requestedRedeemPoints, $amountBeforePoints);
        $pointsDiscountAmount = round($pointsRedeemed, 2);
        $amountDue = round(max(0, $amountBeforePoints - $pointsDiscountAmount), 2);
        $tendered = round(array_sum(array_map(static fn($row) => (float)$row['amount'], $cleanPayments)), 2);

        if ($tendered < $amountDue) {
            jsonExit([
                'success' => false,
                'message' => 'Total payment is insufficient.'
            ]);
        }

        $paymentMethod = count($cleanPayments) > 1 ? 'Mixed' : $cleanPayments[0]['method'];
        $paymentReferenceNo = implode(', ', array_values(array_filter(array_map(static fn($row) => $row['reference_no'] ?? '', $cleanPayments))));
        $checkNo = implode(', ', array_values(array_filter(array_map(static fn($row) => $row['check_no'] ?? '', $cleanPayments))));

        $pointsEligibleAmountBeforeRedeem = 0.00;
        foreach ($cleanItems as $ci) {
            if (!empty($ci['pointsEligible'])) {
                $pointsEligibleAmountBeforeRedeem += round((float)$ci['lineTotal'], 2);
            }
        }

        $pointsEligibleAmount = round(max(0, $pointsEligibleAmountBeforeRedeem - $pointsDiscountAmount), 2);
        $pointsEarned = $selectedCustomer ? calculatePOSPointsEarned($pointsEligibleAmount) : 0.00;

        foreach ($cleanItems as $ci) {
            $currentStock = getBranchItemUOMStock($conn, $branchId, (int)$ci['itemId'], isset($ci['uomId']) ? (int)$ci['uomId'] : null);
            if ($currentStock < (float)$ci['qty']) {
                jsonExit([
                    'success' => false,
                    'message' => 'Insufficient stock for one or more selected items.'
                ]);
            }
        }

        try {
            $conn->begin_transaction();

            $receiptNo = 'POS-' . date('Ymd-His') . '-' . random_int(100, 999);
            $vatableSales = $posVatRegistered ? round($amountDue / (1 + $posVatRate), 2) : 0.00;
            $vatAmount = $posVatRegistered ? round($amountDue - $vatableSales, 2) : 0.00;
            $changeAmount = $tendered - $amountDue;
            $status = 'completed';
            $cashierUserId = $userId > 0 ? $userId : null;

            $stmt = $conn->prepare("
                INSERT INTO pos_sales
                (
                    receipt_no,
                    branch_id,
                    cashier_user_id,
                    customer_id,
                    customer_name,
                    customer_code,
                    subtotal,
                    discount_amount,
                    points_redeemed,
                    points_discount_amount,
                    points_eligible_amount,
                    points_earned,
                    vat_amount,
                    amount_due,
                    tendered_amount,
                    change_amount,
                    payment_method,
                    payment_reference_no,
                    check_no,
                    status,
                    created_at
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");

            if (!$stmt) {
                throw new Exception($conn->error);
            }

            $stmt->bind_param(
                'siiissddddddddddssss',
                $receiptNo,
                $branchId,
                $cashierUserId,
                $selectedCustomerId,
                $customerName,
                $selectedCustomerCode,
                $subtotal,
                $totalDiscount,
                $pointsRedeemed,
                $pointsDiscountAmount,
                $pointsEligibleAmount,
                $pointsEarned,
                $vatAmount,
                $amountDue,
                $tendered,
                $changeAmount,
                $paymentMethod,
                $paymentReferenceNo,
                $checkNo,
                $status
            );

            $stmt->execute();
            $saleId = (int)$conn->insert_id;

            if ($selectedCustomer && $selectedCustomerId > 0) {
                if ($pointsRedeemed > 0) {
                    $stmtPoints = $conn->prepare("INSERT INTO customer_points_transactions (customer_id, customer_code, branch_id, sale_id, receipt_no, transaction_type, points, amount_value, eligible_amount, remarks, created_by, created_at) VALUES (?, ?, ?, ?, ?, 'Redeem', ?, ?, 0, 'Redeemed as POS payment discount', ?, NOW())");
                    if (!$stmtPoints) {
                        throw new Exception($conn->error);
                    }
                    $negativePoints = -1 * $pointsRedeemed;
                    $stmtPoints->bind_param('isiisddi', $selectedCustomerId, $selectedCustomerCode, $branchId, $saleId, $receiptNo, $negativePoints, $pointsDiscountAmount, $userId);
                    $stmtPoints->execute();
                    $stmtPoints->close();
                }

                if ($pointsEarned > 0) {
                    $stmtPoints = $conn->prepare("INSERT INTO customer_points_transactions (customer_id, customer_code, branch_id, sale_id, receipt_no, transaction_type, points, amount_value, eligible_amount, remarks, created_by, created_at) VALUES (?, ?, ?, ?, ?, 'Earn', ?, 0, ?, 'Earned from POS eligible items', ?, NOW())");
                    if (!$stmtPoints) {
                        throw new Exception($conn->error);
                    }
                    $stmtPoints->bind_param('isiisddi', $selectedCustomerId, $selectedCustomerCode, $branchId, $saleId, $receiptNo, $pointsEarned, $pointsEligibleAmount, $userId);
                    $stmtPoints->execute();
                    $stmtPoints->close();
                }

                $stmtCustomerPoints = $conn->prepare("UPDATE customers SET points_balance = GREATEST(0, COALESCE(points_balance, 0) - ? + ?), updated_at = NOW() WHERE customer_id = ?");
                if (!$stmtCustomerPoints) {
                    throw new Exception($conn->error);
                }
                $stmtCustomerPoints->bind_param('ddi', $pointsRedeemed, $pointsEarned, $selectedCustomerId);
                $stmtCustomerPoints->execute();
                $stmtCustomerPoints->close();
            }

            $paymentStmt = $conn->prepare("
                INSERT INTO pos_sale_payments
                (
                    sale_id,
                    branch_id,
                    payment_method,
                    amount,
                    reference_no,
                    check_no,
                    created_at
                )
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ");

            if (!$paymentStmt) {
                throw new Exception($conn->error);
            }

            foreach ($cleanPayments as $paymentRow) {
                $paymentStmt->bind_param(
                    'iisdss',
                    $saleId,
                    $branchId,
                    $paymentRow['method'],
                    $paymentRow['amount'],
                    $paymentRow['reference_no'],
                    $paymentRow['check_no']
                );

                $paymentStmt->execute();
            }

            $paymentStmt->close();

            $posSalesOrderId = createPOSSalesOrderFromSale(
                $conn,
                $branchId,
                $userId,
                $selectedCustomerId,
                $customerName,
                (float)$subtotal,
                (float)$totalDiscount,
                (float)$amountDue,
                $saleId,
                $receiptNo,
                $cleanItems
            );

            $itemStmt = $conn->prepare("
                INSERT INTO pos_sale_items
                (
                    sale_id,
                    item_id,
                    uom_id,
                    uom_name,
                    uom_initial,
                    conversion_qty,
                    quantity,
                    unit_price,
                    discount_amount,
                    line_total
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            if (!$itemStmt) {
                throw new Exception($conn->error);
            }

            $txStmt = $conn->prepare("
                INSERT INTO inventory_transactions
                (
                    branch_id,
                    item_id,
                    transaction_type,
                    quantity_changed,
                    unit_cost,
                    total_cost,
                    reference_type,
                    reference_id,
                    created_by,
                    created_at
                )
                VALUES (?, ?, 'out', ?, ?, ?, 'pos_sale', ?, ?, NOW())
            ");

            if (!$txStmt) {
                throw new Exception($conn->error);
            }

            $stockStmt = $conn->prepare("
                UPDATE inventory
                SET 
                    quantity_on_hand = quantity_on_hand - ?,
                    last_updated_by = ?,
                    updated_at = NOW()
                WHERE branch_id = ?
                    AND item_id = ?
            ");

            if (!$stockStmt) {
                throw new Exception($conn->error);
            }

            $insertInvStmt = $conn->prepare("
                INSERT INTO inventory
                (
                    branch_id,
                    item_id,
                    quantity_on_hand,
                    quantity_reserved,
                    last_updated_by,
                    updated_at
                )
                VALUES (?, ?, ?, 0, ?, NOW())
            ");

            if (!$insertInvStmt) {
                throw new Exception($conn->error);
            }

            $itemStockStmt = $conn->prepare("
                UPDATE items
                SET 
                    stock = COALESCE(stock, 0) - ?,
                    updated_by = ?,
                    updated_at = NOW()
                WHERE item_id = ?
            ");

            if (!$itemStockStmt) {
                throw new Exception($conn->error);
            }

            foreach ($cleanItems as $ci) {
                $itemStmt->bind_param(
                    'iiissddddd',
                    $saleId,
                    $ci['itemId'],
                    $ci['uomId'],
                    $ci['uomName'],
                    $ci['uomInitial'],
                    $ci['conversionQty'],
                    $ci['qty'],
                    $ci['price'],
                    $ci['discountAmount'],
                    $ci['lineTotal']
                );

                $itemStmt->execute();

                adjustBranchUOMStock($conn, $branchId, (int)$ci['itemId'], isset($ci['uomId']) ? (int)$ci['uomId'] : null, -1 * (float)$ci['qty']);

                $stockStmt->bind_param(
                    'diii',
                    $ci['inventoryQty'],
                    $userId,
                    $branchId,
                    $ci['itemId']
                );

                $stockStmt->execute();

                if ($stockStmt->affected_rows === 0) {
                    $remainingQty = max(0, getBranchItemStock($conn, $branchId, (int)$ci['itemId']) - (float)$ci['inventoryQty']);

                    $insertInvStmt->bind_param(
                        'iidi',
                        $branchId,
                        $ci['itemId'],
                        $remainingQty,
                        $userId
                    );

                    $insertInvStmt->execute();
                }

                $itemStockStmt->bind_param(
                    'dii',
                    $ci['inventoryQty'],
                    $userId,
                    $ci['itemId']
                );

                $itemStockStmt->execute();

                $quantityChanged = -1 * $ci['inventoryQty'];

                $txStmt->bind_param(
                    'iidddii',
                    $branchId,
                    $ci['itemId'],
                    $quantityChanged,
                    $ci['price'],
                    $ci['lineTotal'],
                    $saleId,
                    $userId
                );

                $txStmt->execute();
            }

            $conn->commit();

            jsonExit([
                'success' => true,
                'message' => 'Sale saved.',
                'receipt_no' => $receiptNo,
                'or_no' => str_pad((string)$saleId, 10, '0', STR_PAD_LEFT),
                'sale_id' => $saleId,
                'sales_order_id' => $posSalesOrderId,
                'change' => $changeAmount,
                'customer_id' => $selectedCustomerId,
                'customer_code' => $selectedCustomerCode,
                'points_redeemed' => $pointsRedeemed,
                'points_discount_amount' => $pointsDiscountAmount,
                'points_eligible_amount' => $pointsEligibleAmount,
                'points_earned' => $pointsEarned,
                'points_balance' => $selectedCustomer ? max(0, $pointsBefore - $pointsRedeemed + $pointsEarned) : 0
            ]);
        } catch (Throwable $e) {
            $conn->rollback();

            jsonExit([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }


    if ($action === 'recent_sales') {
        $dateFilter = strtolower(trim((string)($payload['date_filter'] ?? 'today')));
        $allowedDateFilters = ['today', 'yesterday', 'last_7_days', 'this_month', 'all'];

        if (!in_array($dateFilter, $allowedDateFilters, true)) {
            $dateFilter = 'today';
        }

        $whereParts = ['ps.branch_id = ?'];
        $types = 'i';
        $params = [$branchId];

        if ($dateFilter === 'today') {
            $whereParts[] = 'DATE(ps.created_at) = CURDATE()';
        } elseif ($dateFilter === 'yesterday') {
            $whereParts[] = 'DATE(ps.created_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)';
        } elseif ($dateFilter === 'last_7_days') {
            $whereParts[] = 'DATE(ps.created_at) >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)';
        } elseif ($dateFilter === 'this_month') {
            $whereParts[] = 'YEAR(ps.created_at) = YEAR(CURDATE()) AND MONTH(ps.created_at) = MONTH(CURDATE())';
        }

        $limit = $dateFilter === 'all' ? 500 : 200;
        $sql = "
            SELECT
                ps.sale_id,
                ps.receipt_no,
                ps.customer_name,
                ps.subtotal,
                ps.discount_amount,
                COALESCE(ps.points_redeemed, 0) AS points_redeemed,
                COALESCE(ps.points_discount_amount, 0) AS points_discount_amount,
                COALESCE(ps.points_eligible_amount, 0) AS points_eligible_amount,
                COALESCE(ps.points_earned, 0) AS points_earned,
                ps.amount_due,
                ps.tendered_amount,
                ps.change_amount,
                ps.payment_method,
                ps.status,
                ps.created_at,
                COUNT(psi.sale_item_id) AS item_count
            FROM pos_sales ps
            LEFT JOIN pos_sale_items psi ON psi.sale_id = ps.sale_id
            WHERE " . implode(' AND ', $whereParts) . "
            GROUP BY ps.sale_id
            ORDER BY ps.created_at DESC
            LIMIT $limit
        ";

        $stmt = $conn->prepare($sql);

        if (!$stmt) {
            jsonExit(['success' => false, 'message' => $conn->error]);
        }

        $stmt->bind_param($types, ...$params);
        $stmt->execute();

        jsonExit([
            'success' => true,
            'date_filter' => $dateFilter,
            'sales' => fetchAllAssoc($stmt)
        ]);
    }

    if ($action === 'get_sale') {
        $saleId = (int)($payload['sale_id'] ?? 0);
        $stmt = $conn->prepare("SELECT * FROM pos_sales WHERE sale_id = ? AND branch_id = ? LIMIT 1");
        if (!$stmt) {
            jsonExit(['success' => false, 'message' => $conn->error]);
        }
        $stmt->bind_param('ii', $saleId, $branchId);
        $stmt->execute();
        $sale = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$sale) {
            jsonExit(['success' => false, 'message' => 'Sale not found.']);
        }
        $stmt = $conn->prepare("SELECT psi.*, i.item_name, i.item_code FROM pos_sale_items psi LEFT JOIN items i ON i.item_id = psi.item_id WHERE psi.sale_id = ? ORDER BY psi.sale_item_id ASC");
        if (!$stmt) {
            jsonExit(['success' => false, 'message' => $conn->error]);
        }
        $stmt->bind_param('i', $saleId);
        $stmt->execute();
        jsonExit(['success' => true, 'sale' => $sale, 'items' => fetchAllAssoc($stmt)]);
    }

    if ($action === 'void_sale' || $action === 'return_sale') {
        $saleId = (int)($payload['sale_id'] ?? 0);
        $approvalPassword = (string)($payload['password'] ?? '');

        $branchAdminApprover = verifyBranchAdminPassword($conn, $branchId, $approvalPassword);

        if (!$branchAdminApprover) {
            jsonExit([
                'success' => false,
                'message' => 'Incorrect Branch Admin password.'
            ]);
        }

        $newStatus = $action === 'void_sale' ? 'voided' : 'refunded';
        $referenceType = $action === 'void_sale' ? 'pos_void' : 'pos_return';

        try {
            $conn->begin_transaction();
            $stmt = $conn->prepare("SELECT * FROM pos_sales WHERE sale_id = ? AND branch_id = ? LIMIT 1 FOR UPDATE");
            if (!$stmt) {
                throw new Exception($conn->error);
            }
            $stmt->bind_param('ii', $saleId, $branchId);
            $stmt->execute();
            $sale = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$sale) {
                throw new Exception('Sale not found.');
            }
            if ($sale['status'] !== 'completed') {
                throw new Exception('Only completed sales can be voided or returned.');
            }

            $stmt = $conn->prepare("SELECT item_id, uom_id, quantity, unit_price, COALESCE(conversion_qty, 1) AS conversion_qty FROM pos_sale_items WHERE sale_id = ?");
            if (!$stmt) {
                throw new Exception($conn->error);
            }
            $stmt->bind_param('i', $saleId);
            $stmt->execute();
            $saleItems = fetchAllAssoc($stmt);
            $stmt->close();

            foreach ($saleItems as $row) {
                adjustBranchStock($conn, $branchId, (int)$row['item_id'], (float)$row['quantity'] * max(1, (float)($row['conversion_qty'] ?? 1)), $userId, $referenceType, $saleId, (float)$row['unit_price']);
                adjustBranchUOMStock($conn, $branchId, (int)$row['item_id'], isset($row['uom_id']) ? (int)$row['uom_id'] : null, (float)$row['quantity']);
            }

            $stmt = $conn->prepare("UPDATE pos_sales SET status = ? WHERE sale_id = ? AND branch_id = ?");
            if (!$stmt) {
                throw new Exception($conn->error);
            }
            $stmt->bind_param('sii', $newStatus, $saleId, $branchId);
            $stmt->execute();
            $stmt->close();

            $approvalLogDetails = 'Approved by Branch Admin: ' . (((string)($branchAdminApprover['name'] ?? '')) ?: ('User #' . (int)($branchAdminApprover['user_id'] ?? 0)));
            logPOSAction($conn, $branchId, $userId, strtoupper($action), 'pos_sale', $saleId, null, null, null, (float)($sale['amount_due'] ?? 0), $approvalLogDetails);

            $conn->commit();
            jsonExit(['success' => true, 'message' => $action === 'void_sale' ? 'Sale voided and stocks restored.' : 'Sale returned and stocks restored.']);
        } catch (Throwable $e) {
            $conn->rollback();
            jsonExit(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    if ($action === 'z_reading') {
        ensurePOSAuxTables($conn);
        $date = trim((string)($payload['date'] ?? date('Y-m-d')));
        $stmt = $conn->prepare("SELECT payment_method, COUNT(*) AS sale_count, SUM(amount_due) AS total_sales, SUM(discount_amount) AS total_discount, SUM(tendered_amount) AS total_tendered, SUM(change_amount) AS total_change FROM pos_sales WHERE branch_id = ? AND DATE(created_at) = ? AND status = 'completed' GROUP BY payment_method ORDER BY payment_method ASC");
        if (!$stmt) {
            jsonExit(['success' => false, 'message' => $conn->error]);
        }
        $stmt->bind_param('is', $branchId, $date);
        $stmt->execute();
        $rows = fetchAllAssoc($stmt);
        $stmt->close();

        $stmt = $conn->prepare("SELECT movement_type, COUNT(*) AS movement_count, SUM(amount) AS total_amount FROM pos_cash_movements WHERE branch_id = ? AND DATE(created_at) = ? GROUP BY movement_type");
        if (!$stmt) {
            jsonExit(['success' => false, 'message' => $conn->error]);
        }
        $stmt->bind_param('is', $branchId, $date);
        $stmt->execute();
        $movements = fetchAllAssoc($stmt);
        jsonExit(['success' => true, 'date' => $date, 'rows' => $rows, 'movements' => $movements]);
    }

    if ($action === 'get_shift_status') {
        $summary = getPOSShiftSummary($conn, $branchId, $userId);
        jsonExit([
            'success' => true,
            'shift' => $summary
        ]);
    }

    if ($action === 'open_shift') {
        ensurePOSAuxTables($conn);

        if (getOpenPOSShift($conn, $branchId, $userId)) {
            jsonExit([
                'success' => true,
                'message' => 'Shift is already open.',
                'shift' => getPOSShiftSummary($conn, $branchId, $userId)
            ]);
        }

        $amount = round(max(0, (float)($payload['amount'] ?? 0)), 2);
        $notes = 'SHIFT_OPEN|Beginning Cash';
        $cashierUserId = $userId > 0 ? $userId : null;

        $stmt = $conn->prepare("INSERT INTO pos_cash_movements (branch_id, cashier_user_id, movement_type, amount, notes, created_at) VALUES (?, ?, 'cash_count', ?, ?, NOW())");

        if (!$stmt) {
            jsonExit(['success' => false, 'message' => $conn->error]);
        }

        $stmt->bind_param('iids', $branchId, $cashierUserId, $amount, $notes);
        $stmt->execute();
        $stmt->close();

        jsonExit([
            'success' => true,
            'message' => 'POS shift opened.',
            'shift' => getPOSShiftSummary($conn, $branchId, $userId)
        ]);
    }

    if ($action === 'close_shift') {
        ensurePOSAuxTables($conn);

        $summary = getPOSShiftSummary($conn, $branchId, $userId);

        if (empty($summary['is_open'])) {
            jsonExit([
                'success' => false,
                'message' => 'No open POS shift found.'
            ]);
        }

        $actualCash = round(max(0, (float)($payload['actual_cash'] ?? 0)), 2);
        $userNotes = trim((string)($payload['notes'] ?? ''));
        $expectedCash = round((float)($summary['expected_cash'] ?? 0), 2);
        $variance = round($actualCash - $expectedCash, 2);
        $notes = 'SHIFT_CLOSE|Expected:' . number_format($expectedCash, 2, '.', '') . '|Variance:' . number_format($variance, 2, '.', '') . '|Notes:' . $userNotes;
        $cashierUserId = $userId > 0 ? $userId : null;

        $stmt = $conn->prepare("INSERT INTO pos_cash_movements (branch_id, cashier_user_id, movement_type, amount, notes, created_at) VALUES (?, ?, 'cash_count', ?, ?, NOW())");

        if (!$stmt) {
            jsonExit(['success' => false, 'message' => $conn->error]);
        }

        $stmt->bind_param('iids', $branchId, $cashierUserId, $actualCash, $notes);
        $stmt->execute();
        $stmt->close();

        $summary['actual_cash'] = $actualCash;
        $summary['variance'] = $variance;

        jsonExit([
            'success' => true,
            'message' => 'POS shift closed.',
            'shift' => $summary
        ]);
    }

    if ($action === 'cash_count' || $action === 'cash_transfer' || $action === 'drawer_open') {
        ensurePOSAuxTables($conn);
        $amount = (float)($payload['amount'] ?? 0);
        $notes = trim((string)($payload['notes'] ?? ''));
        $movementType = $action;
        if ($action !== 'drawer_open' && $amount <= 0) {
            jsonExit(['success' => false, 'message' => 'Amount must be greater than zero.']);
        }
        $stmt = $conn->prepare("INSERT INTO pos_cash_movements (branch_id, cashier_user_id, movement_type, amount, notes, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
        if (!$stmt) {
            jsonExit(['success' => false, 'message' => $conn->error]);
        }
        $cashierUserId = $userId > 0 ? $userId : null;
        $stmt->bind_param('iisds', $branchId, $cashierUserId, $movementType, $amount, $notes);
        $stmt->execute();
        jsonExit(['success' => true, 'message' => 'POS cash activity saved.']);
    }

    if ($action === 'search_customers') {
        $term = trim((string)($payload['term'] ?? ''));
        $like = '%' . $term . '%';
        ensurePOSLoyaltyTables($conn);
        $stmt = $conn->prepare("SELECT customer_id, customer_name, store_name, customer_code, phone_number, address, city, points_balance, membership_status FROM customers WHERE status = 'active' AND (branch_id = ? OR branch_id IS NULL) AND (? = '' OR customer_name LIKE ? OR store_name LIKE ? OR customer_code LIKE ? OR phone_number LIKE ?) ORDER BY CASE WHEN customer_code = ? THEN 0 WHEN phone_number = ? THEN 1 WHEN customer_name = ? THEN 2 ELSE 3 END, customer_name ASC LIMIT 50");
        if (!$stmt) {
            jsonExit(['success' => false, 'message' => $conn->error]);
        }
        $stmt->bind_param('issssssss', $branchId, $term, $like, $like, $like, $like, $term, $term, $term);
        $stmt->execute();
        jsonExit(['success' => true, 'customers' => fetchAllAssoc($stmt)]);
    }

    jsonExit([
        'success' => false,
        'message' => 'Invalid action.'
    ]);
}

$posPriceLevels = getPOSPriceLevels($conn);

$initialProducts = [];
try {
    $initialProducts = fetchPOSProductsForPOS($conn, $branchId, '', null, 500);
} catch (Throwable $e) {
    $initialProducts = [];
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>POS Dashboard</title>
<link rel="icon" type="image/png" href="../Pictures/favicon-96x96.png" sizes="96x96" />
<link rel="icon" type="image/svg+xml" href="../Pictures/favicon.svg" />
<link rel="shortcut icon" href="../Pictures/favicon.ico" />
<link rel="apple-touch-icon" sizes="180x180" href="../Pictures/apple-touch-icon.png" />
<link rel="manifest" href="../Pictures/site.webmanifest" />
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
/* SweetAlert2 offline/CDN-fail safety fallback.
   This prevents "Swal is not defined" when CDN resources cannot load. */
(function () {
    if (window.Swal) return;

    let activeResolve = null;
    let activePopup = null;
    let activeValidation = null;
    let activeOptions = null;

    function normalizeOptions(args) {
        if (args.length === 1 && typeof args[0] === 'object') {
            return Object.assign({}, args[0]);
        }

        return {
            title: args[0] || '',
            html: args[1] || '',
            text: args[1] || '',
            icon: args[2] || undefined
        };
    }

    function removePopup(result) {
        const wrap = document.getElementById('posSwalFallbackOverlay');
        if (wrap) wrap.remove();

        const resolve = activeResolve;
        activeResolve = null;
        activePopup = null;
        activeValidation = null;
        activeOptions = null;

        if (typeof resolve === 'function') {
            resolve(result || { isDismissed: true });
        }
    }

    function buildInput(options) {
        if (!options.input) return '';

        const type = options.input === 'number' ? 'number' : 'text';
        const placeholder = options.inputPlaceholder || '';
        const value = options.inputValue ?? '';
        const attrs = options.inputAttributes || {};
        const attrText = Object.keys(attrs).map(key => `${key}="${String(attrs[key]).replace(/"/g, '&quot;')}"`).join(' ');

        return `<input id="swal2-input" class="swal2-input pos-swal-fallback-input" type="${type}" value="${String(value).replace(/"/g, '&quot;')}" placeholder="${String(placeholder).replace(/"/g, '&quot;')}" ${attrText}>`;
    }

    async function confirmPopup() {
        if (!activeOptions) return;

        const options = activeOptions;

        try {
            let value = undefined;

            if (typeof options.preConfirm === 'function') {
                value = await options.preConfirm();

                if (value === false) {
                    return;
                }
            } else if (options.input) {
                value = document.getElementById('swal2-input')?.value ?? '';
            }

            removePopup({ isConfirmed: true, isDismissed: false, isDenied: false, value });
        } catch (error) {
            window.Swal.showValidationMessage(error && error.message ? error.message : 'Unable to process request.');
        }
    }

    window.Swal = {
        fire: function (...args) {
            const options = normalizeOptions(args);
            activeOptions = options;

            return new Promise(resolve => {
                activeResolve = resolve;

                const old = document.getElementById('posSwalFallbackOverlay');
                if (old) old.remove();

                const overlay = document.createElement('div');
                overlay.id = 'posSwalFallbackOverlay';
                overlay.style.cssText = 'position:fixed;inset:0;z-index:99999;background:rgba(0,0,0,.55);display:flex;align-items:center;justify-content:center;padding:18px;font-family:Inter,Arial,sans-serif;';

                const width = Number(options.width || 520);
                const popup = document.createElement('div');
                popup.className = 'swal2-popup pos-swal pos-swal-fallback';
                popup.style.cssText = `width:min(${width}px,96vw);max-height:92vh;overflow:auto;background:#fff;color:#0f172a;border-radius:18px;padding:24px;box-shadow:0 24px 70px rgba(0,0,0,.38);`;

                const iconMap = {
                    success: '✅',
                    error: '❌',
                    warning: '⚠️',
                    info: 'ℹ️',
                    question: '❔'
                };

                const showIcon = options.icon ? `<div style="font-size:42px;text-align:center;margin-bottom:8px;">${iconMap[options.icon] || ''}</div>` : '';
                const title = options.title ? `<h2 style="margin:0 0 14px;font-size:26px;font-weight:800;color:#052A47;">${options.title}</h2>` : '';
                const body = options.html ? `<div class="swal2-html-container">${options.html}</div>` : (options.text ? `<div class="swal2-html-container">${options.text}</div>` : '');
                const input = buildInput(options);
                const footerDisplay = options.showConfirmButton === false && options.showCancelButton !== true ? 'display:none;' : '';

                popup.innerHTML = `
                    ${showIcon}
                    ${title}
                    ${body}
                    ${input}
                    <div id="posSwalFallbackValidation" style="display:none;color:#dc2626;font-size:13px;margin-top:10px;font-weight:700;"></div>
                    <div class="swal2-actions" style="${footerDisplay}margin-top:18px;display:flex;gap:10px;justify-content:flex-end;flex-wrap:wrap;">
                        ${options.showCancelButton ? `<button type="button" id="posSwalFallbackCancel" class="swal2-cancel pos-cancel-btn" style="border:0;border-radius:12px;padding:12px 18px;font-weight:800;background:#64748b;color:#fff;cursor:pointer;">${options.cancelButtonText || 'Cancel'}</button>` : ''}
                        ${options.showConfirmButton === false ? '' : `<button type="button" id="posSwalFallbackConfirm" class="swal2-confirm pos-confirm-btn" style="border:0;border-radius:12px;padding:12px 22px;font-weight:800;background:#22c55e;color:#052A47;cursor:pointer;">${options.confirmButtonText || 'OK'}</button>`}
                    </div>
                `;

                overlay.appendChild(popup);
                document.body.appendChild(overlay);

                activePopup = popup;
                activeValidation = popup.querySelector('#posSwalFallbackValidation');

                const confirmBtn = popup.querySelector('#posSwalFallbackConfirm');
                const cancelBtn = popup.querySelector('#posSwalFallbackCancel');

                if (confirmBtn) {
                    confirmBtn.addEventListener('click', confirmPopup);
                }

                if (cancelBtn) {
                    cancelBtn.addEventListener('click', () => removePopup({ isConfirmed: false, isDismissed: true, isDenied: false }));
                }

                overlay.addEventListener('click', event => {
                    if (event.target === overlay && options.allowOutsideClick !== false) {
                        removePopup({ isConfirmed: false, isDismissed: true, isDenied: false });
                    }
                });

                document.addEventListener('keydown', function escHandler(event) {
                    if (!document.getElementById('posSwalFallbackOverlay')) {
                        document.removeEventListener('keydown', escHandler);
                        return;
                    }

                    if (event.key === 'Escape' && options.allowEscapeKey !== false) {
                        document.removeEventListener('keydown', escHandler);
                        removePopup({ isConfirmed: false, isDismissed: true, isDenied: false });
                    }

                    if (event.key === 'Enter' && options.input) {
                        event.preventDefault();
                        confirmPopup();
                    }
                });

                setTimeout(() => {
                    try {
                        if (typeof options.didOpen === 'function') options.didOpen(popup);
                        if (typeof options.willOpen === 'function') options.willOpen(popup);
                        const firstInput = popup.querySelector('input, textarea, select, button');
                        if (firstInput) firstInput.focus();
                    } catch (error) {
                        console.warn('SweetAlert fallback didOpen error:', error);
                    }
                }, 0);
            });
        },
        close: function () {
            removePopup({ isDismissed: true });
        },
        clickConfirm: confirmPopup,
        clickCancel: function () {
            removePopup({ isConfirmed: false, isDismissed: true });
        },
        showValidationMessage: function (message) {
            if (activeValidation) {
                activeValidation.textContent = message || 'Invalid input.';
                activeValidation.style.display = 'block';
            } else if (message) {
                alert(message);
            }
        },
        getPopup: function () {
            return activePopup;
        }
    };
})();
</script>


<style>
:root {
    --pos-bg: #053b33;
    --pos-bg-2: #075e49;
    --pos-bg-3: #0b7a55;
    --pos-navy: #052A47;
    --pos-navy-2: #06365c;
    --pos-accent: #44D34E;
    --pos-accent-2: #22c55e;
    --pos-red: #dc4a3a;
    --pos-orange: #f59e0b;
    --pos-blue: #0ea5e9;
    --pos-purple: #8b5cf6;
    --pos-white: #ffffff;
    --pos-soft: #f8fafc;
    --pos-card: rgba(255, 255, 255, .13);
    --pos-card-border: rgba(255, 255, 255, .20);
    --pos-text: #142033;
    --pos-muted: #64748b;
    --pos-line: #e2e8f0;
    --pos-shadow: 0 18px 45px rgba(0, 0, 0, .22);
}

* {
    box-sizing: border-box;
}

html,
body {
    width: 100%;
    height: 100%;
    margin: 0;
    background: #053b33;
    font-family: Inter, Arial, sans-serif;
    color: var(--pos-white);
    overflow: hidden;
}

.app {
    width: 100vw;
    height: 100vh;
    min-width: 1024px;
    background:
        radial-gradient(circle at top left, rgba(68, 211, 78, .28), transparent 32%),
        radial-gradient(circle at bottom right, rgba(14, 165, 233, .18), transparent 38%),
        linear-gradient(135deg, var(--pos-navy) 0%, var(--pos-bg) 42%, var(--pos-bg-2) 100%);
    display: flex;
    flex-direction: column;
    border: 1px solid rgba(0, 0, 0, .35);
}

.pos-header {
    height: 52px;
    flex: 0 0 52px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0 18px;
    background: rgba(5, 42, 71, .92);
    border-bottom: 1px solid rgba(255, 255, 255, .12);
    box-shadow: 0 8px 22px rgba(0, 0, 0, .18);
    position: relative;
}

 .pos-header-price-level {
    position: absolute;
    left: 50%;
    top: 50%;
    transform: translate(-50%, -50%);
    z-index: 3;
    display: flex;
    align-items: center;
    justify-content: center;
    pointer-events: none;
}

.topbar-price-level-row {
    height: 40px;
    margin: 0 !important;
    padding: 5px 8px 5px 12px;
    border-radius: 999px;
    background:
        linear-gradient(135deg, rgba(29, 185, 84, .22), rgba(14, 165, 233, .14)),
        rgba(255, 255, 255, .08);
    border: 1px solid rgba(139, 255, 190, .32);
    box-shadow:
        0 8px 22px rgba(0, 0, 0, .18),
        inset 0 1px rgba(255, 255, 255, .14);
    max-width: none !important;
    pointer-events: auto;
    display: flex;
    align-items: center;
    gap: 8px;
    backdrop-filter: blur(10px);
}

.topbar-price-level-row::before {
    content: "\f02b";
    font-family: "Font Awesome 6 Free";
    font-weight: 900;
    width: 24px;
    height: 24px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    color: #073b2f;
    background: linear-gradient(135deg, #bbf7d0, #5eead4);
    box-shadow: 0 4px 12px rgba(0, 0, 0, .18);
    font-size: 11px;
    flex: 0 0 24px;
}

.topbar-price-level-row label {
    color: #dfffee;
    font-size: 11px;
    font-weight: 900;
    letter-spacing: .55px;
    text-transform: uppercase;
    white-space: nowrap;
    margin: 0;
}

.topbar-price-level-row .price-level-select {
    width: 185px;
    height: 30px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 800;
    letter-spacing: .15px;
    padding: 0 34px 0 12px;
    color: #062f28;
    border: 1px solid rgba(255, 255, 255, .75);
    background:
        linear-gradient(45deg, transparent 50%, #0f5132 50%) calc(100% - 15px) 12px / 6px 6px no-repeat,
        linear-gradient(135deg, #ffffff, #dfffee);
    box-shadow: inset 0 1px 2px rgba(0, 0, 0, .08);
    outline: none;
    appearance: none;
    -webkit-appearance: none;
    cursor: pointer;
}

.topbar-price-level-row .price-level-select:focus {
    border-color: #86efac;
    box-shadow:
        0 0 0 3px rgba(34, 197, 94, .22),
        inset 0 1px 2px rgba(0, 0, 0, .08);
}

.topbar-price-level-row .price-level-select:hover {
    background:
        linear-gradient(45deg, transparent 50%, #064e3b 50%) calc(100% - 15px) 12px / 6px 6px no-repeat,
        linear-gradient(135deg, #ffffff, #ecfdf5);
}

.topbar-price-level-row .price-level-shortcut {
    font-size: 10px;
    font-weight: 900;
    padding: 5px 8px;
    white-space: nowrap;
    color: #d1fae5;
    border-radius: 999px;
    background: rgba(255, 255, 255, .10);
    border: 1px solid rgba(255, 255, 255, .14);
}

@media (max-width: 1280px) {
    .pos-header-price-level {
        left: 45%;
    }

    .topbar-price-level-row {
        padding-right: 7px;
        gap: 6px;
    }

    .topbar-price-level-row .price-level-select {
        width: 150px;
    }

    .topbar-price-level-row .price-level-shortcut {
        display: none;
    }
}

.cashier-name {
    font-size: 20px;
    font-weight: 700;
    letter-spacing: .2px;
}

.datebar {
    display: flex;
    align-items: center;
    gap: 12px;
    font-size: 13px;
    font-weight: 600;
    white-space: nowrap;
    color: #e6fff0;
}

.iconbtn {
    border: 0;
    color: #ffffff;
    width: 34px;
    height: 30px;
    border-radius: 8px;
    background: linear-gradient(135deg, #0ea5e9, #0369a1);
    cursor: pointer;
    box-shadow: 0 8px 16px rgba(0, 0, 0, .22), inset 0 1px rgba(255, 255, 255, .25);
}

.iconbtn.switch-admin {
    width: auto;
    min-width: 138px;
    padding: 0 12px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    font-size: 12px;
    font-weight: 800;
    letter-spacing: .2px;
    background: linear-gradient(135deg, var(--pos-accent), var(--pos-accent-2));
    color: #052A47;
}

.iconbtn.switch-admin i {
    font-size: 13px;
}

.iconbtn.exit {
    background: linear-gradient(135deg, #ef6b58, #b83228);
}

.pos-main {
    flex: 1 1 auto;
    min-height: 0;
    display: grid;
    grid-template-columns: minmax(460px, 56%) minmax(390px, 44%);
    gap: 22px;
    padding: 24px 18px 18px 34px;
}

.scan-panel {
    min-width: 0;
    display: flex;
    flex-direction: column;
    justify-content: center;
    padding: 8px 14px 8px 8px;
    border-radius: 22px;
    background: rgba(255, 255, 255, .08);
    border: 1px solid rgba(255, 255, 255, .14);
    box-shadow: var(--pos-shadow);
}

.scan-group {
    margin: 0 10px 24px;
}

.scan-group:last-child {
    margin-bottom: 0;
}

.scan-label {
    display: flex;
    align-items: center;
    justify-content: space-between;
    font-size: 18px;
    font-weight: 700;
    letter-spacing: .7px;
    margin-bottom: 10px;
    color: #f8fff9;
    text-shadow: 0 1px 2px rgba(0, 0,
.price-level-row {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 10px;
    max-width: 520px;
}

.price-level-row label {
    flex: 0 0 auto;
    font-size: 13px;
    font-weight: 800;
    letter-spacing: .5px;
    color: #f8fff9;
}

.price-level-select {
    width: 210px;
    height: 38px;
    border-radius: 10px;
    border: 1px solid rgba(255,255,255,.65);
    background: rgba(255,255,255,.96);
    color: #052A47;
    font-size: 13px;
    font-weight: 800;
    padding: 0 12px;
    outline: 0;
    box-shadow: inset 0 1px rgba(255,255,255,.85), 0 8px 16px rgba(0,0,0,.16);
}

.price-level-shortcut {
    flex: 0 0 auto;
    font-size: 11px;
    font-weight: 800;
    color: #d1fae5;
    background: rgba(255,255,255,.10);
    border: 1px solid rgba(255,255,255,.18);
    border-radius: 999px;
    padding: 6px 9px;
}

 0, .22);
}

.searchrow {
    width: 100%;
}

.scan-input,
.total-display {
    width: 100%;
    height: 78px;
    background: rgba(255, 255, 255, .96);
    border: 1px solid rgba(255, 255, 255, .88);
    outline: 0;
    color: #0f172a;
    font-size: 35px;
    font-weight: 700;
    padding: 0 20px;
    border-radius: 14px;
    box-shadow: inset 0 1px 4px rgba(15, 23, 42, .10), 0 14px 30px rgba(0, 0, 0, .18);
}

.scan-input::placeholder {
    color: #94a3b8;
    font-weight: 600;
}

.scan-input:focus {
    border-color: var(--pos-accent);
    box-shadow: 0 0 0 4px rgba(68, 211, 78, .28), inset 0 1px 4px rgba(15, 23, 42, .10), 0 14px 30px rgba(0, 0, 0, .18);
}

.scan-input.scan-ok {
    box-shadow: 0 0 0 4px rgba(68, 211, 78, .70), inset 0 1px 4px rgba(15, 23, 42, .10), 0 14px 30px rgba(0, 0, 0, .18);
}

.scan-input.scan-error {
    box-shadow: 0 0 0 4px rgba(220, 74, 58, .72), inset 0 1px 4px rgba(15, 23, 42, .10), 0 14px 30px rgba(0, 0, 0, .18);
}

.total-display {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    overflow: hidden;
    white-space: nowrap;
    color: #052A47;
    background: linear-gradient(180deg, #ffffff, #f1f5f9);
}

.scan-help {
    min-height: 18px;
    margin-top: 8px;
    color: #dfffee;
    font-size: 12px;
    font-weight: 600;
    text-align: right;
}

.pos-price-level-row {
    margin-top: 12px;
    margin-bottom: 6px;
    display: flex;
    align-items: center;
    gap: 10px;
    max-width: 520px;
}

.pos-price-level-row label {
    font-size: 15px;
    font-weight: 800;
    color: #f8fff9;
    margin: 0;
    white-space: nowrap;
    letter-spacing: .2px;
}

.pos-price-level-row .price-level-select {
    width: 180px;
    height: 38px;
}

.pos-price-level-row .price-level-shortcut {
    font-size: 13px;
    font-weight: 700;
    color: #dfffee;
    opacity: .88;
}

.cart-panel {
    background: rgba(255, 255, 255, .96);
    color: var(--pos-text);
    min-width: 0;
    height: 100%;
    border: 1px solid rgba(255, 255, 255, .75);
    display: flex;
    flex-direction: column;
    border-radius: 18px;
    overflow: hidden;
    box-shadow: var(--pos-shadow);
}

.cart-wrap {
    flex: 1 1 auto;
    overflow: auto;
    min-height: 0;
}

.cart-sale-summary {
    display: none;
    flex: 0 0 auto;
    background: linear-gradient(180deg, #ffffff, #f8fafc);
    color: #052A47;
    border-top: 1px solid #dbeafe;
    padding: 10px 18px 12px;
    font-size: 14px;
}

.cart-panel.sale-complete .cart-sale-summary {
    display: block;
}

.cart-sale-summary .summary-separator {
    border-top: 1px dashed rgba(5,42,71,.45);
    margin: 4px 0;
}

.cart-sale-summary .summary-line {
    display: grid;
    grid-template-columns: 1fr auto;
    align-items: center;
    gap: 16px;
    padding: 3px 0;
    font-weight: 600;
}

.cart-sale-summary .summary-line strong {
    font-weight: 700;
}

.cart-sale-summary .summary-hint {
    margin-top: 4px;
    text-align: right;
    color: #64748b;
    font-size: 11px;
    font-weight: 600;
}


.pos-table {
    width: 100%;
    border-collapse: collapse;
    table-layout: fixed;
    color: var(--pos-text);
}

.pos-table th {
    height: 42px;
    background: linear-gradient(180deg, #ffffff, #edf7f1);
    color: #0f2c3f;
    font-size: 12px;
    font-weight: 700;
    letter-spacing: .5px;
    text-align: center;
    border-bottom: 1px solid var(--pos-line);
    position: sticky;
    top: 0;
    z-index: 2;
}

.pos-table td {
    height: 40px;
    background: #ffffff;
    color: #111827;
    font-size: 13px;
    font-weight: 500;
    padding: 6px 9px;
    border-bottom: 1px solid #eef2f7;
    vertical-align: middle;
}

.pos-table tbody tr:nth-child(even) td {
    background: #f8fafc;
}

.pos-table tbody tr:hover td {
    background: #eefbf2;
}

.pos-table tbody tr.active td {
    background: #dcfce7;
    color: #052e16;
}

.pos-table .item {
    width: auto;
    text-align: left;
    word-break: break-word;
}

.item-uom {
    display: inline-block;
    margin-left: 10px;
    color: #0f2c3f;
    font-size: 12px;
    font-weight: 700;
    opacity: .75;
    white-space: nowrap;
}

.pos-table .qty {
    width: 80px;
    text-align: center;
}

.pos-table .price {
    width: 120px;
    text-align: right;
}

.pos-table .line-total {
    width: 140px;
    text-align: right;
    font-weight: 700;
}


.cart-uom-select {
    display: inline-block;
    margin-left: 8px;
    max-width: 120px;
    height: 24px;
    border: 1px solid #cbd5e1;
    border-radius: 7px;
    background: #f8fafc;
    color: #0f2c3f;
    font-size: 11px;
    font-weight: 800;
    padding: 1px 6px;
    outline: none;
}

.cart-uom-select:focus {
    border-color: var(--pos-accent-2);
    box-shadow: 0 0 0 2px rgba(34, 197, 94, .16);
}

.suggest-uoms {
    display: block;
    margin-top: 3px;
    color: #0f766e;
    font-size: 10px;
    font-weight: 800;
    overflow-wrap: anywhere;
}


.discount-note {
    display: block;
    margin-top: 3px;
    color: #dc2626;
    font-size: 10px;
    font-weight: 700;
}

.discount-note.order {
    color: #b45309;
}

.pos-actions{
    flex: 0 0 108px;
    background: rgba(5,42,71,.96);
    border-top: 1px solid rgba(255,255,255,.14);
    padding: 12px 14px;
    display: grid;
    grid-template-columns: repeat(17, minmax(58px,1fr));
    gap: 8px;
    overflow: hidden;
}

.tool,
.bbtn,
.tender {
    border: 1px solid rgba(255, 255, 255, .18);
    border-radius: 12px;
    color: #ffffff;
    font-size: 8.8px;
    font-weight: 700;
    cursor: pointer;
    min-height: 90px;
    background: linear-gradient(160deg, #16a34a, #047857);
    position: relative;
    padding: 6px 4px 7px;
    box-shadow: inset 0 1px rgba(255, 255, 255, .22), 0 8px 18px rgba(0, 0, 0, .28);
    line-height: 1.08;
    transition: transform .12s ease, filter .12s ease, box-shadow .12s ease;
}

.tool:hover,
.bbtn:hover,
.tender:hover {
    transform: translateY(-1px);
    filter: brightness(1.08);
    box-shadow: inset 0 1px rgba(255, 255, 255, .25), 0 12px 22px rgba(0, 0, 0, .32);
}

.tool span,
.bbtn .kbd {
    position: absolute;
    top: 6px;
    left: 7px;
    font-size: 8px;
    color: #eaffef;
    opacity: .95;
}

.tool i,
.bbtn i {
    display: block;
    font-size: 19px;
    margin: 25px auto 6px;
}

.bgreen {
    background: linear-gradient(160deg, #56d766, #12843a);
}

.bblue {
    background: linear-gradient(160deg, #38bdf8, #0369a1);
}

.byellow {
    background: linear-gradient(160deg, #fbbf24, #b45309);
}

.borange {
    background: linear-gradient(160deg, #fb923c, #c2410c);
}

.bviolet {
    background: linear-gradient(160deg, #a78bfa, #6d28d9);
}

.tender {
    background: linear-gradient(160deg, #111827, #030712);
    font-size: 16px;
}

.tender small {
    display: block;
    font-size: 9px;
    text-align: left;
}

.suggest {
    position: fixed;
    background: #f8fafc;
    color: #1f2933;
    z-index: 60;
    box-shadow: 0 18px 38px rgba(0, 0, 0, .34);
    display: none;
    max-height: 420px;
    overflow-y: auto;
    overflow-x: hidden;
    border: 1px solid rgba(15, 23, 42, .25);
    border-radius: 12px;
}

.suggest-header,
.suggest-item {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 104px 142px;
    gap: 10px;
    align-items: center;
    padding: 8px 14px 8px 10px;
}

.suggest-header {
    position: sticky;
    top: 0;
    background: var(--pos-navy);
    color: #ffffff;
    font-size: 11px;
    font-weight: 700;
    z-index: 2;
}

.suggest-item {
    min-height: 48px;
    border-bottom: 1px solid #e5e7eb;
    cursor: pointer;
    background: #ffffff;
}

.suggest-item:hover,
.suggest-item.active {
    background: #dcfce7;
}

.suggest-name {
    display: block;
    font-size: 13px;
    font-weight: 700;
    color: #111827;
    overflow-wrap: anywhere;
}

.suggest-code {
    display: block;
    margin-top: 2px;
    font-size: 10px;
    font-weight: 700;
    color: #64748b;
}

.suggest-price,
.suggest-stock {
    font-size: 12px;
    font-weight: 700;
    text-align: right;
    white-space: nowrap;
    overflow: visible;
}

.suggest-stock {
    padding-right: 6px;
}

.suggest-empty {
    padding: 14px;
    background: #fff;
    color: #4b5563;
    font-size: 13px;
    font-weight: 600;
    text-align: center;
}

.customer-suggest {
    z-index: 95;
    max-height: 320px;
}

.customer-suggest .suggest-header,
.customer-suggest .suggest-item {
    grid-template-columns: minmax(0, 1fr) 118px 130px;
}

.customer-suggest .suggest-item {
    min-height: 52px;
}

.customer-suggest .customer-store {
    display: block;
    margin-top: 2px;
    font-size: 10px;
    font-weight: 700;
    color: #0f766e;
    overflow-wrap: anywhere;
}

.modal {
    position: fixed;
    inset: 0;
    background: rgba(3, 7, 18, .62);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 80;
}

.modal.show {
    display: flex;
}

.box {
    width: 420px;
    background: #ffffff;
    border: 1px solid rgba(255, 255, 255, .45);
    border-radius: 18px;
    overflow: hidden;
    box-shadow: 0 24px 60px rgba(0, 0, 0, .40);
}

.box h3 {
    margin: 0;
    padding: 15px 18px;
    background: var(--pos-navy);
    color: #fff;
}

.box .content {
    padding: 18px;
    background: #f8fafc;
}

.field {
    margin-bottom: 12px;
}

.field label {
    display: block;
    font-size: 13px;
    margin-bottom: 6px;
    color: #0f172a;
    font-weight: 600;
}

.field input,
.field select {
    width: 100%;
    height: 40px;
    border: 1px solid #cbd5e1;
    background: #ffffff;
    border-radius: 10px;
    padding: 0 11px;
    font-size: 16px;
}

.modal-actions {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    padding: 13px 16px;
    background: #eef2f7;
}

.btn {
    border: 0;
    padding: 10px 16px;
    border-radius: 10px;
    font-weight: 700;
    cursor: pointer;
}

.btn.green {
    background: #44D34E;
    color: #063b12;
}

.btn.gray {
    background: #64748b;
    color: #fff;
}


.tender-box {
    width: 820px;
    max-width: 94vw;
    background: linear-gradient(135deg, #052A47 0%, #053b33 48%, #047857 100%);
    border: 1px solid rgba(68, 211, 78, .35);
}

.tender-box h3 {
    background: transparent;
    padding: 22px 26px 10px;
    font-size: 28px;
    font-weight: 700;
}

.tender-content {
    display: grid;
    grid-template-columns: minmax(300px, 1fr) 360px;
    gap: 24px;
    padding: 18px 26px 24px !important;
    background: transparent !important;
}

.tender-left {
    background: rgba(255,255,255,.12);
    border: 1px solid rgba(255,255,255,.18);
    border-radius: 18px;
    padding: 20px;
}

.tender-box .field label {
    color: #f8fff9;
    font-size: 14px;
}

.tender-box .field input,
.tender-box .field select {
    height: 54px;
    font-size: 20px;
    font-weight: 700;
    border-radius: 14px;
}

#tenderDue,
#tenderChange {
    color: #052A47;
    text-align: right;
}

#tenderedAmount {
    height: 68px;
    font-size: 34px;
    text-align: right;
    border-color: rgba(68, 211, 78, .55);
    box-shadow: 0 0 0 4px rgba(68, 211, 78, .16);
}

#tenderedAmount[readonly] {
    background: #e2e8f0;
    color: #334155;
    cursor: not-allowed;
    box-shadow: none;
}

.payment-breakdown-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 10px 12px;
}

.payment-breakdown-grid .field {
    margin-bottom: 4px;
}

.payment-breakdown-grid .field:nth-child(3),
.payment-breakdown-grid .field:nth-child(5),
.payment-breakdown-grid .field:nth-child(7) {
    grid-column: span 2;
}

.payment-amount-input.active-payment-input {
    border-color: var(--pos-accent);
    box-shadow: 0 0 0 4px rgba(68, 211, 78, .20);
}

.payment-summary-box {
    background: rgba(255,255,255,.92);
    color: #052A47;
    border-radius: 14px;
    padding: 12px 14px;
    margin: 8px 0 12px;
}

.payment-summary-line {
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 15px;
    font-weight: 700;
    padding: 4px 0;
}

.payment-summary-line b {
    font-size: 18px;
}

.tender-box {
    width: 760px;
}

.tender-content {
    grid-template-columns: minmax(320px, 1fr) 320px;
}

.tender-left {
    max-height: 74vh;
    overflow-y: auto;
    scrollbar-width: thin;
    scrollbar-color: rgba(255,255,255,.25) transparent;
}

/* Chrome / Edge */
.tender-left::-webkit-scrollbar {
    width: 5px;
}

.tender-left::-webkit-scrollbar-track {
    background: transparent;
}

.tender-left::-webkit-scrollbar-thumb {
    background: rgba(255,255,255,.25);
    border-radius: 20px;
}

.tender-left::-webkit-scrollbar-thumb:hover {
    background: rgba(255,255,255,.45);
}

.add-payment-btn {
    width: 100%;
    height: 46px;
    border: 1px solid rgba(68, 211, 78, .45);
    border-radius: 14px;
    background: rgba(68, 211, 78, .16);
    color: #ffffff;
    font-size: 15px;
    font-weight: 700;
    cursor: pointer;
    margin: 0 0 12px;
}

.add-payment-btn:hover {
    background: rgba(68, 211, 78, .25);
}

.customer-field {
    margin-bottom: 0;
}

.mixed-payment-panel {
    background: rgba(255, 255, 255, .10);
    border: 1px solid rgba(255, 255, 255, .18);
    border-radius: 14px;
    padding: 12px;
    margin: 2px 0 12px;
}

.mixed-payment-title {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    font-size: 14px;
    font-weight: 700;
    color: #ffffff;
    margin-bottom: 8px;
}

.mixed-payment-title button,
.mixed-payment-row button {
    border: 0;
    border-radius: 9px;
    background: rgba(220, 74, 58, .95);
    color: #ffffff;
    font-size: 11px;
    font-weight: 700;
    padding: 7px 9px;
    cursor: pointer;
}

.mixed-payment-row {
    display: grid;
    grid-template-columns: 1fr auto auto;
    gap: 8px;
    align-items: center;
    background: rgba(255, 255, 255, .92);
    color: #052A47;
    border-radius: 12px;
    padding: 9px 10px;
    margin-top: 7px;
    font-size: 13px;
    font-weight: 700;
}

.mixed-payment-row small {
    display: block;
    color: #64748b;
    font-size: 10px;
    margin-top: 2px;
}

#tenderedAmount.active-payment-input {
    border-color: var(--pos-accent);
    box-shadow: 0 0 0 4px rgba(68, 211, 78, .20);
}


.tender-keypad {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 14px;
    background: rgba(255,255,255,.12);
    border: 1px solid rgba(255,255,255,.18);
    border-radius: 18px;
    padding: 20px;
}

.tender-keypad button {
    height: 78px;
    border: 0;
    border-radius: 16px;
    background: linear-gradient(180deg, #ffffff, #e5e7eb);
    color: #052A47;
    font-size: 32px;
    font-weight: 700;
    cursor: pointer;
    box-shadow: inset 0 1px rgba(255,255,255,.8), 0 12px 22px rgba(0,0,0,.22);
}

.tender-keypad button:hover {
    filter: brightness(1.05);
    transform: translateY(-1px);
}

.tender-keypad .key-danger {
    background: linear-gradient(160deg, #ef6b58, #b83228);
    color: #ffffff;
}

.tender-keypad .key-clear {
    background: linear-gradient(160deg, #64748b, #334155);
    color: #ffffff;
}

.tender-keypad .key-ok {
    background: linear-gradient(160deg, #44D34E, #047857);
    color: #052A47;
}

.tender-keypad .key-clear-wide {
    grid-column: span 2;
    height: 58px;
    font-size: 20px;
}

.tender-keypad .key-cancel {
    grid-column: span 1;
    height: 58px;
    font-size: 18px;
}

.tender-keypad .key-wide {
    grid-column: span 3;
    height: 58px;
    font-size: 20px;
}

@media (max-width: 900px) {
    .tender-content {
        grid-template-columns: 1fr;
    }

    .tender-keypad button {
        height: 64px;
    }
}

.hidden-totals {
    display: none;
}

@media (max-width: 1200px) {
    .pos-main {
        grid-template-columns: 55% 45%;
        gap: 16px;
        padding-left: 22px;
    }

    .scan-input,
    .total-display {
        height: 68px;
        font-size: 30px;
    }

    .scan-group {
        margin-bottom: 18px;
    }

    .pos-actions {
        grid-template-columns: repeat(16, minmax(56px, 1fr));
    }

    .tool,
    .bbtn,
    .tender {
        min-height: 80px;
        font-size: 8.5px;
    }
}

/* Tender Payment compact no-scroll layout */
#tenderModal .tender-box {
    width: 860px !important;
    max-width: 96vw !important;
    max-height: 94vh !important;
}

#tenderModal .tender-box.mixed-payment-mode {
    width: 980px !important;
    max-width: 97vw !important;
}

#tenderModal .tender-box h3 {
    padding: 16px 24px 6px !important;
    font-size: 26px !important;
    line-height: 1.1 !important;
}

#tenderModal .tender-content {
    grid-template-columns: minmax(300px, 1fr) 330px !important;
    gap: 18px !important;
    padding: 14px 24px 22px !important;
    align-items: stretch !important;
}

#tenderModal .tender-left {
    max-height: none !important;
    overflow: visible !important;
    padding: 14px 16px !important;
}

#tenderModal .tender-box .field {
    margin-bottom: 8px !important;
}

#tenderModal .tender-box .field label {
    font-size: 13px !important;
    margin-bottom: 4px !important;
}

#tenderModal .tender-box .field input,
#tenderModal .tender-box .field select {
    height: 46px !important;
    font-size: 17px !important;
    border-radius: 12px !important;
}

#tenderModal #tenderDue,
#tenderModal #tenderChange {
    height: 50px !important;
    font-size: 22px !important;
}

#tenderModal #tenderedAmount {
    height: 56px !important;
    font-size: 26px !important;
}

#tenderModal .payment-summary-box {
    padding: 8px 12px !important;
    margin: 6px 0 8px !important;
    border-radius: 12px !important;
}

#tenderModal .payment-summary-line {
    font-size: 13px !important;
    padding: 3px 0 !important;
}

#tenderModal .payment-summary-line b {
    font-size: 16px !important;
}

#tenderModal .add-payment-btn {
    height: 40px !important;
    margin: 0 0 8px !important;
    font-size: 14px !important;
}

#tenderModal .mixed-payment-panel {
    padding: 9px !important;
    margin: 0 0 8px !important;
}

#tenderModal .mixed-payment-title {
    font-size: 13px !important;
    margin-bottom: 6px !important;
}

#tenderModal .mixed-payment-row {
    padding: 7px 9px !important;
    margin-top: 5px !important;
    font-size: 12px !important;
}

#tenderModal .tender-keypad {
    padding: 16px !important;
    gap: 12px !important;
    align-content: stretch !important;
}

#tenderModal .tender-keypad button {
    height: 70px !important;
    font-size: 28px !important;
    border-radius: 14px !important;
}

#tenderModal .tender-keypad .key-clear-wide,
#tenderModal .tender-keypad .key-cancel,
#tenderModal .tender-keypad .key-wide {
    height: 50px !important;
    font-size: 18px !important;
}

@media (max-height: 760px) {
    #tenderModal .tender-box h3 {
        padding: 12px 20px 4px !important;
        font-size: 22px !important;
    }

    #tenderModal .tender-content {
        padding: 10px 20px 16px !important;
        gap: 14px !important;
    }

    #tenderModal .tender-left {
        padding: 12px 14px !important;
    }

    #tenderModal .tender-box .field {
        margin-bottom: 6px !important;
    }

    #tenderModal .tender-box .field input,
    #tenderModal .tender-box .field select {
        height: 42px !important;
        font-size: 16px !important;
    }

    #tenderModal #tenderedAmount {
        height: 50px !important;
        font-size: 24px !important;
    }

    #tenderModal .tender-keypad button {
        height: 62px !important;
        font-size: 26px !important;
    }

    #tenderModal .tender-keypad .key-clear-wide,
    #tenderModal .tender-keypad .key-cancel,
    #tenderModal .tender-keypad .key-wide {
        height: 44px !important;
        font-size: 16px !important;
    }
}


/* Tender Payment fixed keypad + clean mixed-payment behavior */
#tenderModal .tender-box {
    width: 900px !important;
    max-width: 96vw !important;
    max-height: none !important;
}

#tenderModal .tender-box.mixed-payment-mode {
    width: 1040px !important;
    max-width: 97vw !important;
}

#tenderModal .tender-content {
    grid-template-columns: minmax(430px, 1fr) 350px !important;
    align-items: stretch !important;
}

#tenderModal .tender-left {
    max-height: none !important;
    overflow: hidden !important;
}

#tenderModal .tender-box.mixed-payment-mode .tender-left {
    max-height: 72vh !important;
    overflow-y: auto !important;
    padding-right: 12px !important;
}

#tenderModal .tender-keypad {
    align-self: stretch !important;
    height: auto !important;
    min-height: 100% !important;
    position: sticky !important;
    top: 0 !important;
}

#tenderModal .tender-extra-field {
    display: none;
}

#tenderModal .mixed-payment-panel {
    max-height: 150px;
    overflow-y: auto;
}

#tenderModal .customer-field input {
    height: 42px !important;
    font-size: 16px !important;
}

#tenderModal .payment-summary-box {
    margin-top: 8px !important;
}

#tenderModal .add-payment-btn[disabled] {
    opacity: .55;
    cursor: not-allowed;
}

@media (max-height: 780px) {
    #tenderModal .tender-box h3 {
        font-size: 24px !important;
        padding-top: 14px !important;
    }

    #tenderModal .tender-content {
        padding-top: 10px !important;
        padding-bottom: 14px !important;
    }

    #tenderModal .tender-keypad button {
        height: 58px !important;
        font-size: 24px !important;
    }

    #tenderModal .tender-keypad .key-clear-wide,
    #tenderModal .tender-keypad .key-cancel,
    #tenderModal .tender-keypad .key-wide {
        height: 42px !important;
        font-size: 15px !important;
    }
}


/* ===== FINAL TENDER PAYMENT CONSISTENT SIZE FIX ===== */
#tenderModal {
    padding: 16px !important;
}

#tenderModal .tender-box {
    width: 1300px !important;
    max-width: 96vw !important;
    height: 822px !important;
    max-height: 94vh !important;
    display: flex !important;
    flex-direction: column !important;
    overflow: hidden !important;
    background: linear-gradient(135deg, #052A47 0%, #053b33 48%, #047857 100%) !important;
    border: 1px solid rgba(68, 211, 78, .35) !important;
}

#tenderModal .tender-box.mixed-payment-mode {
    width: 1300px !important;
    max-width: 96vw !important;
    height: 822px !important;
    max-height: 94vh !important;
}

#tenderModal .tender-header {
    flex: 0 0 82px !important;
    display: flex !important;
    align-items: flex-start !important;
    justify-content: space-between !important;
    gap: 20px !important;
    padding: 20px 30px 10px !important;
    background: transparent !important;
}

#tenderModal .tender-header h3 {
    margin: 0 !important;
    padding: 0 !important;
    background: transparent !important;
    color: #ffffff !important;
    font-size: 32px !important;
    line-height: 1.12 !important;
    font-weight: 700 !important;
    white-space: nowrap !important;
}

#tenderModal .tender-customer-field {
    width: 360px !important;
    margin: 0 !important;
    text-align: left !important;
}

#tenderModal .tender-customer-field label {
    color: #f8fff9 !important;
    font-size: 13px !important;
    font-weight: 700 !important;
    margin: 0 0 6px !important;
    display: block !important;
}

#tenderModal .tender-customer-field input {
    width: 100% !important;
    height: 42px !important;
    border-radius: 12px !important;
    border: 1px solid rgba(255, 255, 255, .78) !important;
    background: rgba(255, 255, 255, .96) !important;
    color: #052A47 !important;
    font-size: 16px !important;
    font-weight: 700 !important;
    padding: 0 12px !important;
}

#tenderModal .tender-content {
    flex: 1 1 auto !important;
    min-height: 0 !important;
    height: auto !important;
    display: grid !important;
    grid-template-columns: minmax(560px, 1fr) 436px !important;
    gap: 24px !important;
    padding: 8px 30px 28px !important;
    align-items: stretch !important;
    overflow: hidden !important;
    background: transparent !important;
}

#tenderModal .tender-left {
    height: 100% !important;
    max-height: none !important;
    min-height: 0 !important;
    overflow-y: scroll !important;
    overflow-x: hidden !important;
    scrollbar-gutter: stable !important;
    padding: 20px !important;
    padding-right: 16px !important;
    background: rgba(255,255,255,.12) !important;
    border: 1px solid rgba(255,255,255,.18) !important;
    border-radius: 18px !important;
}

#tenderModal .tender-box.mixed-payment-mode .tender-left {
    height: 100% !important;
    max-height: none !important;
    overflow-y: scroll !important;
}

#tenderModal .tender-keypad {
    height: 100% !important;
    min-height: 0 !important;
    align-self: stretch !important;
    position: static !important;
    display: grid !important;
    grid-template-columns: repeat(3, 1fr) !important;
    gap: 14px !important;
    padding: 20px !important;
    background: rgba(255,255,255,.12) !important;
    border: 1px solid rgba(255,255,255,.18) !important;
    border-radius: 18px !important;
    align-content: stretch !important;
}

#tenderModal .tender-keypad button {
    height: auto !important;
    min-height: 78px !important;
}

#tenderModal .tender-keypad .key-clear-wide,
#tenderModal .tender-keypad .key-cancel,
#tenderModal .tender-keypad .key-wide {
    height: auto !important;
    min-height: 58px !important;
}

#tenderModal .mixed-payment-panel {
    max-height: none !important;
    overflow: visible !important;
}

@media (max-width: 1100px) {
    #tenderModal .tender-box,
    #tenderModal .tender-box.mixed-payment-mode {
        width: 96vw !important;
    }

    #tenderModal .tender-content {
        grid-template-columns: minmax(420px, 1fr) 360px !important;
        gap: 18px !important;
    }

    #tenderModal .tender-customer-field {
        width: 300px !important;
    }
}

@media (max-height: 760px) {
    #tenderModal .tender-box,
    #tenderModal .tender-box.mixed-payment-mode {
        height: 94vh !important;
    }

    #tenderModal .tender-header {
        flex-basis: 74px !important;
        padding: 14px 24px 8px !important;
    }

    #tenderModal .tender-header h3 {
        font-size: 26px !important;
    }

    #tenderModal .tender-customer-field input {
        height: 38px !important;
    }

    #tenderModal .tender-content {
        padding: 8px 24px 20px !important;
    }
}



/* POS main layout adjustment: top-aligned scanner + two-row action buttons */
.pos-main {
    gap: 24px !important;
    padding: 28px 20px 16px 40px !important;
}

.scan-panel {
    justify-content: flex-start !important;
    align-self: stretch !important;
    padding: 66px 22px 22px 22px !important;
}

.scan-group {
    margin: 0 0 22px !important;
}

.scan-input,
.total-display {
    height: 72px !important;
    font-size: 32px !important;
}

.scan-input::placeholder {
    font-size: 32px !important;
}

.scan-label {
    margin-bottom: 8px !important;
}

.scan-help {
    margin-top: 6px !important;
}

.pos-actions {
    flex: 0 0 176px !important;
    padding: 10px 16px 12px !important;
    display: grid !important;
    grid-template-columns: repeat(10, minmax(82px, 1fr)) !important;
    grid-template-rows: repeat(2, 74px) !important;
    gap: 9px !important;
    overflow: hidden !important;
}

.tool,
.bbtn,
.tender {
    width: 100% !important;
    height: 100% !important;
    min-height: 0 !important;
    padding: 6px 5px !important;
    font-size: 9px !important;
    line-height: 1.05 !important;
}

.tool i,
.bbtn i {
    font-size: 18px !important;
    margin: 20px auto 5px !important;
}

.tool span,
.bbtn .kbd {
    top: 6px !important;
    left: 8px !important;
    font-size: 8px !important;
}

.pos-actions .tool:nth-of-type(1) { grid-column: 1; grid-row: 1; }
.pos-actions .tool:nth-of-type(2) { grid-column: 2; grid-row: 1; }
.pos-actions .tool:nth-of-type(3) { grid-column: 3; grid-row: 1; }
.pos-actions .tool:nth-of-type(4) { grid-column: 4; grid-row: 1; }
.pos-actions .tool:nth-of-type(5) { grid-column: 5; grid-row: 1; }
.pos-actions .tool:nth-of-type(6) { grid-column: 6; grid-row: 1; }
.pos-actions .tool:nth-of-type(7) { grid-column: 7; grid-row: 1; }
.pos-actions .tool:nth-of-type(8) { grid-column: 8; grid-row: 1; }

.pos-actions .bbtn:nth-of-type(9)  { grid-column: 1; grid-row: 2; }
.pos-actions .bbtn:nth-of-type(10) { grid-column: 2; grid-row: 2; }
.pos-actions .bbtn:nth-of-type(11) { grid-column: 3; grid-row: 2; }
.pos-actions .bbtn:nth-of-type(12) { grid-column: 4; grid-row: 2; }
.pos-actions .bbtn:nth-of-type(13) { grid-column: 5; grid-row: 2; }
.pos-actions .bbtn:nth-of-type(14) { grid-column: 6; grid-row: 2; }
.pos-actions .bbtn:nth-of-type(15) { grid-column: 7; grid-row: 2; }

.pos-actions .tender {
    grid-column: 9 / 11 !important;
    grid-row: 1 / 3 !important;
    font-size: 28px !important;
    display: flex !important;
    flex-direction: column !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 6px !important;
}

.pos-actions .tender small {
    position: static !important;
    display: block !important;
    width: 100% !important;
    text-align: left !important;
    padding-left: 8px !important;
    font-size: 12px !important;
}

@media (max-width: 1200px) {
    .pos-actions {
        grid-template-columns: repeat(10, minmax(72px, 1fr)) !important;
        grid-template-rows: repeat(2, 68px) !important;
        flex-basis: 164px !important;
        gap: 8px !important;
    }

    .pos-actions .tender {
        font-size: 24px !important;
    }

    .scan-panel {
        padding-top: 46px !important;
    }
}


/* Global SweetAlert POS modal style - consistent with Tender, Discount, and Settings */
.swal2-container {
    z-index: 10000 !important;
    padding: 18px !important;
}

.swal2-popup.pos-swal {
    padding: 0 !important;
    border-radius: 22px !important;
    overflow: hidden !important;
    background: linear-gradient(135deg, #052A47 0%, #053b33 48%, #047857 100%) !important;
    border: 1px solid rgba(68, 211, 78, .35) !important;
    box-shadow: 0 24px 60px rgba(0, 0, 0, .40) !important;
    color: #ffffff !important;
}

.swal2-popup.pos-swal .swal2-title {
    margin: 0 !important;
    padding: 24px 30px 10px !important;
    color: #ffffff !important;
    font-size: 26px !important;
    font-weight: 700 !important;
    text-align: left !important;
}

.swal2-popup.pos-swal .swal2-html-container {
    width: auto !important;
    max-width: none !important;
    margin: 0 !important;
    padding: 14px 30px 10px !important;
    color: #f8fff9 !important;
    font-size: 15px !important;
    font-weight: 500 !important;
    text-align: left !important;
    overflow: visible !important;
}

.swal2-popup.pos-swal .swal2-icon {
    margin: 22px auto 0 !important;
    transform: scale(.86);
}

/* Direct SweetAlert input/textarea should never touch the modal edge */
.swal2-popup.pos-swal > .swal2-input,
.swal2-popup.pos-swal > .swal2-textarea,
.swal2-popup.pos-swal > .swal2-select {
    width: calc(100% - 60px) !important;
    max-width: calc(100% - 60px) !important;
    margin: 10px 30px 0 !important;
}

/* Inputs inside custom HTML use the modal body padding */
.swal2-popup.pos-swal .swal2-html-container input[type="text"],
.swal2-popup.pos-swal .swal2-html-container input[type="number"],
.swal2-popup.pos-swal .swal2-html-container input[type="password"],
.swal2-popup.pos-swal .swal2-html-container select,
.swal2-popup.pos-swal .swal2-html-container textarea {
    width: 100% !important;
    max-width: 100% !important;
    min-width: 0 !important;
    margin: 0 0 12px !important;
}

.swal2-popup.pos-swal .swal2-input,
.swal2-popup.pos-swal .swal2-textarea,
.swal2-popup.pos-swal input[type="text"],
.swal2-popup.pos-swal input[type="number"],
.swal2-popup.pos-swal input[type="password"],
.swal2-popup.pos-swal select,
.swal2-popup.pos-swal textarea {
    min-width: 0 !important;
    border: 0 !important;
    border-radius: 14px !important;
    background: #ffffff !important;
    color: #052A47 !important;
    font-size: 17px !important;
    font-weight: 600 !important;
    box-shadow: inset 0 1px 4px rgba(15, 23, 42, .10) !important;
}

.swal2-popup.pos-swal .swal2-input,
.swal2-popup.pos-swal input[type="text"],
.swal2-popup.pos-swal input[type="number"],
.swal2-popup.pos-swal input[type="password"],
.swal2-popup.pos-swal select {
    height: 52px !important;
    padding: 0 16px !important;
}

.swal2-popup.pos-swal .swal2-textarea,
.swal2-popup.pos-swal textarea {
    min-height: 110px !important;
    padding: 12px 16px !important;
    resize: none !important;
}

.swal2-popup.pos-swal label,
.swal2-popup.pos-swal small {
    color: #f8fff9 !important;
}

.swal2-popup.pos-swal hr {
    border: 0 !important;
    border-top: 1px solid rgba(255,255,255,.18) !important;
}

.swal2-popup.pos-swal .swal2-actions {
    margin: 0 !important;
    padding: 16px 30px 26px !important;
    justify-content: flex-end !important;
    gap: 10px !important;
}

.pos-confirm-btn,
.pos-cancel-btn,
.pos-deny-btn {
    border: 0 !important;
    border-radius: 12px !important;
    min-width: 120px !important;
    min-height: 44px !important;
    padding: 10px 16px !important;
    font-size: 15px !important;
    font-weight: 700 !important;
    cursor: pointer !important;
    box-shadow: inset 0 1px rgba(255,255,255,.22), 0 8px 18px rgba(0,0,0,.25) !important;
}

.pos-confirm-btn {
    background: linear-gradient(135deg, #44D34E, #159947) !important;
    color: #052A47 !important;
}

.pos-cancel-btn {
    background: linear-gradient(135deg, #64748b, #334155) !important;
    color: #ffffff !important;
}

.pos-deny-btn {
    background: linear-gradient(135deg, #ef6b58, #b83228) !important;
    color: #ffffff !important;
}

.swal2-popup.pos-swal .swal2-validation-message {
    margin: 12px 30px 0 !important;
    border-radius: 12px !important;
    background: rgba(255,255,255,.92) !important;
    color: #991b1b !important;
    font-weight: 600 !important;
}

.swal2-popup.pos-swal table {
    background: #ffffff !important;
    color: #142033 !important;
    border-radius: 14px !important;
    overflow: hidden !important;
}

.swal2-popup.pos-swal table th {
    background: linear-gradient(180deg, #ffffff, #edf7f1) !important;
    color: #0f2c3f !important;
    font-weight: 700 !important;
}

.swal2-popup.pos-swal table td {
    background: #ffffff !important;
    color: #142033 !important;
    font-weight: 500 !important;
}


/* Consistent inner spacing for custom SweetAlert content */
.swal2-popup.pos-swal .pos-modal-panel,
.swal2-popup.pos-swal .pos-modal-table-wrap,
.swal2-popup.pos-swal .pos-customer-list,
.swal2-popup.pos-swal .pos-report-grid {
    width: 100%;
    max-width: 100%;
    box-sizing: border-box;
}

.swal2-popup.pos-swal .pos-modal-table-wrap {
    margin: 2px 0 4px;
}

.swal2-popup.pos-swal .recent-sales-stable-height {
    min-height: 340px;
    height: 340px;
    max-height: 340px;
    overflow-y: auto;
}

/* Keep Sales Order modal visually the same height as Recent Sales modal. */
.swal2-popup.pos-swal #recentSalesModalBody,
.swal2-popup.pos-swal #salesOrderModalBody {
    min-height: 340px;
    height: 340px;
    max-height: 340px;
}


/* Larger Sales Order and Recent Sales modal */
.swal2-popup.pos-swal.sales-order-swal,
.swal2-popup.pos-swal.recent-sales-swal {
    width: 94vw !important;
    max-width: 94vw !important;
    height: auto !important;
    max-height: calc(100vh - 92px) !important;
    padding: 0 34px 26px !important;
    overflow: hidden !important;
    border-radius: 18px !important;
    box-sizing: border-box !important;
}

.swal2-popup.pos-swal.sales-order-swal .swal2-title,
.swal2-popup.pos-swal.recent-sales-swal .swal2-title {
    display: block !important;
    width: 100% !important;
    text-align: left !important;
    margin: 0 !important;
    padding: 28px 54px 20px 0 !important;
    line-height: 1.15 !important;
}

.swal2-popup.pos-swal.sales-order-swal .swal2-close,
.swal2-popup.pos-swal.recent-sales-swal .swal2-close {
    position: absolute !important;
    right: 18px !important;
    top: 16px !important;
    left: auto !important;
}

.swal2-popup.pos-swal.sales-order-swal .swal2-html-container,
.swal2-popup.pos-swal.recent-sales-swal .swal2-html-container {
    margin: 0 !important;
    padding: 0 !important;
    overflow: hidden !important;
    max-height: none !important;
}

.swal2-popup.pos-swal.sales-order-swal #salesOrderModalBody,
.swal2-popup.pos-swal.recent-sales-swal #recentSalesModalBody {
    min-height: 0 !important;
    height: auto !important;
    max-height: none !important;
}

.swal2-popup.pos-swal.sales-order-swal #salesOrderModalBody .pos-modal-table-wrap {
    height: 68vh !important;
    max-height: calc(100vh - 205px) !important;
    min-height: 430px !important;
    overflow-y: auto !important;
    border-radius: 14px !important;
}

/* Recent Sales uses the same popup size as Sales Order, but reserves space for the day filter. */
.swal2-popup.pos-swal.recent-sales-swal #recentSalesModalBody .pos-modal-table-wrap {
    height: calc(68vh - 150px) !important;
    max-height: calc(100vh - 360px) !important;
    min-height: 430px !important;
    overflow-y: auto !important;
    border-radius: 14px !important;
}

.swal2-popup.pos-swal.recent-sales-swal .pos-modal-panel {
    margin-bottom: 14px !important;
}


.swal2-popup.pos-swal .pos-modal-panel input,
.swal2-popup.pos-swal .pos-modal-panel textarea,
.swal2-popup.pos-swal .pos-modal-panel select {
    margin-bottom: 12px !important;
}

.swal2-popup.pos-swal .swal2-close {
    color: rgba(255,255,255,.88) !important;
    font-size: 34px !important;
    font-weight: 400 !important;
    width: 48px !important;
    height: 48px !important;
    right: 16px !important;
    top: 14px !important;
}

.swal2-popup.pos-swal .swal2-close:hover {
    color: #ffffff !important;
    background: rgba(255,255,255,.10) !important;
    border-radius: 12px !important;
}


/* Pick Item modal - clean fixed layout */
.swal2-popup.pos-swal.pick-item-swal {
    width: 640px !important;
    max-width: 94vw !important;
    height: 620px !important;
    max-height: calc(100vh - 36px) !important;
    padding: 0 !important;
    display: flex !important;
    flex-direction: column !important;
    align-items: stretch !important;
    justify-content: flex-start !important;
    overflow: hidden !important;
    border-radius: 18px !important;
}

.swal2-popup.pos-swal.pick-item-swal .swal2-title {
    flex: 0 0 auto !important;
    margin: 0 !important;
    padding: 22px 74px 12px 34px !important;
    min-height: 68px !important;
    display: flex !important;
    align-items: center !important;
    color: #ffffff !important;
    font-size: 27px !important;
    line-height: 1.1 !important;
    font-weight: 700 !important;
    text-align: left !important;
}

.swal2-popup.pos-swal.pick-item-swal .swal2-html-container {
    flex: 1 1 auto !important;
    width: 100% !important;
    max-width: none !important;
    min-height: 0 !important;
    margin: 0 !important;
    padding: 0 34px 26px !important;
    overflow: hidden !important;
    display: flex !important;
    flex-direction: column !important;
}

.swal2-popup.pos-swal.pick-item-swal .swal2-close {
    position: absolute !important;
    left: auto !important;
    right: 18px !important;
    top: 16px !important;
    width: 42px !important;
    height: 42px !important;
    color: rgba(255,255,255,.85) !important;
    font-size: 34px !important;
    line-height: 42px !important;
    border-radius: 12px !important;
    z-index: 5 !important;
}

.swal2-popup.pos-swal.pick-item-swal .swal2-close:hover {
    color: #ffffff !important;
    background: rgba(255,255,255,.10) !important;
    transform: none !important;
}

.pick-item-panel {
    width: 100%;
    height: 100%;
    min-height: 0;
    display: flex;
    flex-direction: column;
}

.pick-item-search {
    width: 100% !important;
    height: 54px !important;
    flex: 0 0 54px !important;
    margin: 0 0 12px !important;
    border: 1px solid rgba(226,232,240,.95) !important;
    border-radius: 14px !important;
    background: #ffffff !important;
    color: #052A47 !important;
    font-size: 17px !important;
    font-weight: 600 !important;
    padding: 0 16px !important;
    box-shadow: inset 0 1px 4px rgba(15,23,42,.08) !important;
}

.pick-item-search:focus {
    border-color: rgba(68,211,78,.85) !important;
    box-shadow: 0 0 0 4px rgba(68,211,78,.20), inset 0 1px 4px rgba(15,23,42,.08) !important;
}

.pick-item-list {
    flex: 1 1 auto;
    min-height: 0;
    overflow-y: auto;
    overflow-x: hidden;
    background: rgba(255,255,255,.10);
    border: 1px solid rgba(255,255,255,.16);
    border-radius: 16px;
    padding: 10px;
}

.pick-item-row {
    width: 100%;
    border: 1px solid rgba(226,232,240,.95);
    background: rgba(255,255,255,.96);
    color: #052A47;
    border-radius: 12px;
    min-height: 58px;
    padding: 10px 14px;
    margin: 0 0 8px;
    cursor: pointer;
    display: grid;
    grid-template-columns: minmax(0,1fr) auto;
    gap: 14px;
    align-items: center;
    text-align: left;
    box-shadow: 0 6px 14px rgba(0,0,0,.10);
}

.pick-item-row:last-child {
    margin-bottom: 0;
}

.pick-item-row:hover,
.pick-item-row.active {
    background: #dcfce7;
    border-color: rgba(68,211,78,.85);
}

.pick-item-name {
    display: block;
    font-size: 16px;
    font-weight: 700;
    line-height: 1.25;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.pick-item-meta {
    display: block;
    margin-top: 3px;
    color: #64748b;
    font-size: 11px;
    font-weight: 600;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.pick-item-price {
    font-size: 16px;
    font-weight: 700;
    white-space: nowrap;
}

.pick-price-level-badge {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    min-height: 38px;
    margin: 0 0 10px;
    padding: 8px 12px;
    border-radius: 12px;
    background: rgba(68,211,78,.16);
    border: 1px solid rgba(68,211,78,.32);
    color: #ffffff;
    font-size: 12px;
    font-weight: 700;
}

.pick-price-level-badge b {
    color: #bbf7d0;
    font-size: 14px;
    font-weight: 800;
}

.pick-item-uom-row {
    grid-template-columns: minmax(0,1fr) minmax(118px,auto);
}

.pick-item-left {
    min-width: 0;
}

.pick-item-right {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 3px;
}

.pick-item-stock {
    font-size: 11px;
    color: #047857;
    font-weight: 800;
    white-space: nowrap;
}

.pick-item-empty {
    min-height: 88px;
    background: rgba(255,255,255,.92);
    color: #475569;
    border-radius: 12px;
    padding: 28px 16px;
    text-align: center;
    font-weight: 600;
}

@media (max-height: 680px) {
    .swal2-popup.pos-swal.pick-item-swal {
        height: calc(100vh - 28px) !important;
    }

    .swal2-popup.pos-swal.pick-item-swal .swal2-title {
        min-height: 58px !important;
        padding-top: 18px !important;
        padding-bottom: 8px !important;
        font-size: 25px !important;
    }

    .swal2-popup.pos-swal.pick-item-swal .swal2-html-container {
        padding-bottom: 20px !important;
    }
}

/* Branch Settings modal - matches Tender/Discount POS theme */
.settings-swal.swal2-popup {
    width: 820px !important;
    max-width: 94vw !important;
    padding: 0 !important;
    border-radius: 18px !important;
    overflow: hidden !important;
    background: linear-gradient(135deg, #052A47 0%, #053b33 48%, #047857 100%) !important;
    border: 1px solid rgba(68, 211, 78, .35) !important;
    box-shadow: 0 24px 60px rgba(0, 0, 0, .40) !important;
    color: #ffffff !important;
}

.settings-swal .swal2-title {
    background: transparent !important;
    color: #ffffff !important;
    margin: 0 !important;
    padding: 22px 26px 10px !important;
    font-size: 28px !important;
    font-weight: 700 !important;
    text-align: left !important;
}

.settings-swal .swal2-html-container {
    margin: 0 !important;
    padding: 18px 26px 8px !important;
    background: transparent !important;
    color: #ffffff !important;
    text-align: left !important;
}

.settings-swal .settings-scroll {
    max-height: 66vh !important;
    overflow-y: auto !important;
    overflow-x: hidden !important;
    padding: 20px !important;
    padding-right: 12px !important;
    background: rgba(255,255,255,.12) !important;
    border: 1px solid rgba(255,255,255,.18) !important;
    border-radius: 18px !important;
    scrollbar-width: thin;
    scrollbar-color: rgba(255,255,255,.25) transparent;
}

.settings-swal .settings-scroll::-webkit-scrollbar {
    width: 5px;
}

.settings-swal .settings-scroll::-webkit-scrollbar-track {
    background: transparent;
}

.settings-swal .settings-scroll::-webkit-scrollbar-thumb {
    background: rgba(255,255,255,.25);
    border-radius: 20px;
}

.settings-swal label,
.settings-swal small {
    color: #f8fff9 !important;
}

.settings-swal small {
    opacity: .78 !important;
}

.settings-swal input[type="text"],
.settings-swal input[type="number"],
.settings-swal input:not([type]),
.settings-swal textarea,
.settings-swal .swal2-input,
.settings-swal .swal2-textarea {
    width: 100% !important;
    min-width: 0 !important;
    margin: 0 !important;
    border: 0 !important;
    border-radius: 14px !important;
    background: #ffffff !important;
    color: #052A47 !important;
    font-size: 18px !important;
    font-weight: 600 !important;
    box-shadow: inset 0 1px 4px rgba(15, 23, 42, .10) !important;
}

.settings-swal input[type="text"],
.settings-swal input[type="number"],
.settings-swal input:not([type]),
.settings-swal .swal2-input {
    height: 54px !important;
    padding: 0 16px !important;
}

.settings-swal textarea,
.settings-swal .swal2-textarea {
    min-height: 110px !important;
    padding: 12px 16px !important;
    resize: none !important;
}

.settings-swal input[type="file"] {
    width: 100% !important;
    margin: 0 !important;
    border-radius: 14px !important;
    background: #ffffff !important;
    color: #052A47 !important;
    font-weight: 600 !important;
    padding: 12px !important;
}



.settings-swal .logo-upload-card {
    display: grid;
    grid-template-columns: 1fr 180px;
    gap: 14px;
    align-items: stretch;
    margin-top: 8px;
}

.settings-swal .logo-upload-label {
    min-height: 116px;
    border: 2px dashed rgba(68, 211, 78, .75);
    border-radius: 16px;
    background: rgba(255,255,255,.92);
    color: #052A47 !important;
    cursor: pointer;
    display: flex !important;
    align-items: center;
    justify-content: center;
    flex-direction: column;
    gap: 8px;
    text-align: center;
    font-weight: 700 !important;
    box-shadow: inset 0 1px 4px rgba(15,23,42,.08), 0 8px 20px rgba(0,0,0,.12);
    transition: transform .12s ease, box-shadow .12s ease, border-color .12s ease;
}

.settings-swal .logo-upload-label:hover {
    transform: translateY(-1px);
    border-color: #44D34E;
    box-shadow: inset 0 1px 4px rgba(15,23,42,.08), 0 12px 24px rgba(0,0,0,.18);
}

.settings-swal .logo-upload-label i {
    width: 42px;
    height: 42px;
    border-radius: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: linear-gradient(160deg, #44D34E, #047857);
    color: #ffffff;
    font-size: 20px;
}

.settings-swal .logo-upload-label strong {
    display: block;
    font-size: 16px;
    color: #052A47;
}

.settings-swal .logo-upload-label span {
    display: block;
    font-size: 12px;
    color: #475569;
    font-weight: 600;
}

.settings-swal .logo-preview-box {
    min-height: 116px;
    border-radius: 16px;
    background: rgba(255,255,255,.92);
    border: 1px solid rgba(226,232,240,.95);
    padding: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-direction: column;
    gap: 8px;
    box-shadow: inset 0 1px 4px rgba(15,23,42,.08), 0 8px 20px rgba(0,0,0,.12);
}

.settings-swal .logo-preview-box img {
    width: 100%;
    max-width: 130px;
    height: 70px;
    object-fit: contain;
    display: block;
}

.settings-swal .logo-preview-empty {
    color: #64748b;
    font-weight: 700;
    font-size: 12px;
    text-align: center;
}

.settings-swal .logo-remove-row {
    display: flex !important;
    align-items: center;
    justify-content: center;
    gap: 7px;
    color: #475569 !important;
    font-size: 12px !important;
    font-weight: 700 !important;
}

.settings-swal .logo-remove-row input {
    width: 16px !important;
    height: 16px !important;
    min-width: 16px !important;
}

@media (max-width: 720px) {
    .settings-swal .logo-upload-card {
        grid-template-columns: 1fr;
    }
}

.settings-swal input[type="checkbox"] {
    accent-color: #44D34E;
}

.settings-swal hr {
    border: 0 !important;
    border-top: 1px solid rgba(255,255,255,.18) !important;
    margin: 16px 0 !important;
}

.settings-swal .swal2-actions {
    margin: 0 !important;
    padding: 16px 30px 26px !important;
    justify-content: flex-end !important;
    gap: 10px !important;
}

.settings-confirm-btn,
.settings-cancel-btn {
    border: 0 !important;
    border-radius: 12px !important;
    min-width: 130px !important;
    min-height: 46px !important;
    padding: 10px 16px !important;
    font-size: 15px !important;
    font-weight: 700 !important;
    cursor: pointer !important;
    box-shadow: inset 0 1px rgba(255,255,255,.22), 0 8px 18px rgba(0,0,0,.25) !important;
}

.settings-confirm-btn {
    background: linear-gradient(135deg, #44D34E, #159947) !important;
    color: #052A47 !important;
}

.settings-cancel-btn {
    background: linear-gradient(135deg, #64748b, #334155) !important;
    color: #ffffff !important;
}

.settings-swal .swal2-validation-message {
    margin: 12px 30px 0 !important;
    border-radius: 12px !important;
    font-weight: 600 !important;
}


/* Unified POS modal polish - cleaner SweetAlert and report modals */
.swal2-container {
    backdrop-filter: blur(1.5px);
}

.swal2-popup.pos-swal {
    border-radius: 18px !important;
    background: linear-gradient(135deg, #052A47 0%, #053b33 50%, #047857 100%) !important;
    border: 1px solid rgba(68, 211, 78, .32) !important;
    box-shadow: 0 24px 60px rgba(0, 0, 0, .38) !important;
    color: #ffffff !important;
}

.swal2-popup.pos-swal .swal2-title {
    padding: 24px 28px 12px !important;
    font-size: 27px !important;
    line-height: 1.1 !important;
    font-weight: 700 !important;
    color: #ffffff !important;
    text-align: left !important;
}

.swal2-popup.pos-swal .swal2-html-container {
    width: auto !important;
    margin: 0 !important;
    padding: 10px 28px 6px !important;
    color: #f8fff9 !important;
    text-align: left !important;
}

.swal2-popup.pos-swal .swal2-input,
.swal2-popup.pos-swal .swal2-textarea {
    width: calc(100% - 56px) !important;
    margin: 8px 28px 0 !important;
    border: 1px solid rgba(226, 232, 240, .95) !important;
    border-radius: 14px !important;
    background: #ffffff !important;
    color: #052A47 !important;
    font-size: 18px !important;
    font-weight: 500 !important;
    box-shadow: inset 0 1px 4px rgba(15, 23, 42, .08) !important;
}

.swal2-popup.pos-swal .swal2-input {
    height: 56px !important;
    padding: 0 16px !important;
}

.swal2-popup.pos-swal .swal2-textarea {
    min-height: 105px !important;
    padding: 14px 16px !important;
    resize: none !important;
}

.swal2-popup.pos-swal .swal2-input::placeholder,
.swal2-popup.pos-swal .swal2-textarea::placeholder {
    color: #94a3b8 !important;
    font-weight: 500 !important;
}

.swal2-popup.pos-swal .swal2-input:focus,
.swal2-popup.pos-swal .swal2-textarea:focus {
    border-color: rgba(68, 211, 78, .70) !important;
    box-shadow: 0 0 0 4px rgba(68, 211, 78, .18), inset 0 1px 4px rgba(15, 23, 42, .08) !important;
}

.swal2-popup.pos-swal .swal2-actions {
    margin: 0 !important;
    padding: 20px 28px 28px !important;
    justify-content: flex-end !important;
    gap: 12px !important;
}

.swal2-popup.pos-swal .swal2-close {
    color: rgba(255,255,255,.82) !important;
    font-size: 36px !important;
    width: 48px !important;
    height: 48px !important;
    top: 14px !important;
    right: 16px !important;
    transition: .15s ease !important;
}

.swal2-popup.pos-swal .swal2-close:hover {
    color: #ffffff !important;
    transform: scale(1.04);
}

.pos-confirm-btn,
.pos-cancel-btn,
.pos-deny-btn,
.pos-action-mini {
    border: 0 !important;
    border-radius: 13px !important;
    min-width: 132px !important;
    min-height: 48px !important;
    padding: 10px 18px !important;
    font-size: 16px !important;
    font-weight: 700 !important;
    cursor: pointer !important;
    box-shadow: inset 0 1px rgba(255,255,255,.22), 0 10px 18px rgba(0,0,0,.20) !important;
}

.pos-confirm-btn,
.pos-action-mini {
    background: linear-gradient(135deg, #44D34E, #16a34a) !important;
    color: #052A47 !important;
}

.pos-cancel-btn {
    background: linear-gradient(135deg, #64748b, #475569) !important;
    color: #ffffff !important;
}

.pos-deny-btn {
    background: linear-gradient(135deg, #ef6b58, #b83228) !important;
    color: #ffffff !important;
}

.pos-modal-panel {
    background: #ffffff;
    color: #142033;
    border-radius: 16px;
    padding: 16px;
    box-shadow: inset 0 0 0 1px #e2e8f0;
}

.pos-modal-table-wrap {
    max-height: 430px;
    overflow: auto;
    background: #ffffff;
    color: #142033;
    border-radius: 16px;
    border: 1px solid #e2e8f0;
}

.pos-modal-table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
    font-size: 13px;
    text-align: left;
    color: #142033;
}

.pos-modal-table th {
    position: sticky;
    top: 0;
    z-index: 1;
    background: linear-gradient(180deg, #ffffff, #edf7f1) !important;
    color: #0f2c3f !important;
    font-size: 12px;
    font-weight: 700 !important;
    padding: 10px 12px;
    border-bottom: 1px solid #e2e8f0;
}

.pos-modal-table td {
    background: #ffffff !important;
    color: #142033 !important;
    font-weight: 500 !important;
    padding: 9px 12px;
    border-bottom: 1px solid #eef2f7;
    vertical-align: middle;
}

.pos-modal-table tbody tr:nth-child(even) td {
    background: #f8fafc !important;
}

.pos-modal-table tbody tr:hover td {
    background: #ecfdf3 !important;
}

.pos-table-amount {
    text-align: right;
    white-space: nowrap;
}

.pos-customer-list {
    display: grid;
    gap: 10px;
    max-height: 360px;
    overflow: auto;
}

/* Keep Search Customer modal height stable while searching/filtering */
.customer-lookup-swal {
    max-height: 88vh !important;
}

.customer-lookup-swal .customer-lookup-panel {
    height: 510px !important;
    min-height: 510px !important;
    max-height: 510px !important;
    overflow: hidden !important;
    display: flex !important;
    flex-direction: column !important;
}

.customer-lookup-swal .customer-lookup-input {
    flex: 0 0 auto !important;
}

.customer-lookup-swal #swalCustomerSuggest,
.customer-lookup-swal .customer-lookup-results {
    height: 420px !important;
    min-height: 420px !important;
    max-height: 420px !important;
    overflow-y: auto !important;
    overflow-x: hidden !important;
    align-content: start !important;
}

.pos-customer-card {
    width: 100%;
    border: 1px solid #e2e8f0;
    background: #ffffff;
    color: #142033;
    border-radius: 14px;
    padding: 14px 16px;
    text-align: left;
    cursor: pointer;
    transition: .15s ease;
}

.pos-customer-card:hover,
.pos-customer-card.active {
    border-color: #44D34E;
    background: #ecfdf3;
    transform: translateY(-1px);
}

.pos-customer-card b {
    display: block;
    color: #052A47;
    font-size: 16px;
    font-weight: 700;
    margin-bottom: 4px;
}

.pos-customer-card small {
    display: block !important;
    color: #334155 !important;
    font-size: 13px !important;
    font-weight: 700 !important;
    line-height: 1.45 !important;
    opacity: 1 !important;
    visibility: visible !important;
}

.pos-customer-card .customer-info-line {
    display: flex !important;
    flex-wrap: wrap !important;
    gap: 6px !important;
    align-items: center !important;
    color: #334155 !important;
    opacity: 1 !important;
}

.pos-customer-card .customer-info-pill {
    display: inline-flex !important;
    align-items: center !important;
    color: #0f172a !important;
    background: #eaf7ef !important;
    border: 1px solid #b7efc5 !important;
    border-radius: 999px !important;
    padding: 2px 8px !important;
    font-size: 12px !important;
    font-weight: 800 !important;
    opacity: 1 !important;
}

.pos-customer-card .customer-info-muted {
    color: #475569 !important;
    font-weight: 700 !important;
    opacity: 1 !important;
}

.pos-report-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 10px;
    margin-bottom: 14px;
}

.pos-report-card {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    padding: 12px 14px;
    color: #142033;
}

.pos-report-card span {
    display: block;
    color: #64748b;
    font-size: 12px;
    font-weight: 600;
    margin-bottom: 5px;
}

.pos-report-card b {
    display: block;
    color: #052A47;
    font-size: 18px;
    font-weight: 700;
}

.pos-section-title {
    color: #052A47;
    font-size: 14px;
    font-weight: 700;
    margin: 14px 0 8px;
}

.pos-shift-help {
    margin-top: 12px;
    color: #334155 !important;
    font-size: 13px;
    font-weight: 600;
    line-height: 1.5;
}

.pos-shift-help strong {
    color: #052A47 !important;
}


/* Close Shift cash denomination form */
.pos-currency-line {
    display: flex;
    align-items: center;
    gap: 10px;
    background: #f8fafc;
    color: #052A47;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    padding: 12px 14px;
    margin: 12px 0 14px;
    font-size: 14px;
    font-weight: 700;
}

.pos-currency-check {
    width: 20px;
    height: 20px;
    border-radius: 6px;
    background: #16a34a;
    color: #ffffff;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 12px;
    flex: 0 0 auto;
}

.pos-cash-denomination-wrap {
    background: #ffffff;
    border: 1px solid #dbe5ec;
    border-radius: 16px;
    overflow: hidden;
    margin-top: 10px;
}

.pos-cash-denomination-title {
    display: flex;
    align-items: center;
    justify-content: space-between;
    background: #052A47;
    color: #ffffff;
    padding: 10px 14px;
    font-size: 14px;
    font-weight: 700;
}

.pos-cash-denomination-title small {
    color: #dfffee;
    font-size: 12px;
    font-weight: 600;
}

.pos-cash-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0;
}

.pos-cash-table {
    width: 100%;
    border-collapse: collapse;
    table-layout: fixed;
    border-radius: 0 !important;
}

.pos-cash-table:first-child {
    border-right: 1px solid #e2e8f0;
}

.pos-cash-table th,
.pos-cash-table td {
    height: 38px;
    padding: 6px 8px !important;
    border-bottom: 1px solid #e2e8f0;
    font-size: 13px;
}

.pos-cash-table th {
    text-align: center;
}

.pos-cash-table td:first-child {
    width: 85px;
    color: #052A47 !important;
    font-weight: 700 !important;
    text-align: right;
}

.pos-cash-table .qty-cell {
    width: 78px;
}

.pos-cash-table .amount-cell {
    width: 120px;
    text-align: right;
    color: #052A47 !important;
    font-weight: 700 !important;
}

.cash-denom-qty {
    width: 100% !important;
    height: 30px !important;
    margin: 0 !important;
    padding: 0 8px !important;
    border: 1px solid #cbd5e1 !important;
    border-radius: 8px !important;
    background: #ffffff !important;
    color: #052A47 !important;
    font-size: 14px !important;
    font-weight: 700 !important;
    text-align: center;
    box-shadow: none !important;
}

.cash-denom-qty:focus {
    border-color: #44D34E !important;
    box-shadow: 0 0 0 3px rgba(68, 211, 78, .18) !important;
}

.pos-cash-total-row {
    display: grid;
    grid-template-columns: 1fr 180px;
    align-items: center;
    gap: 12px;
    background: #052A47;
    color: #ffffff;
    padding: 12px 14px;
}

.pos-cash-total-row span {
    font-size: 14px;
    font-weight: 800;
    letter-spacing: .4px;
}

.close-shift-hidden-total {
    display: none !important;
}

.pos-cash-total-row input,
#shiftActualCash {
    width: 100% !important;
    height: 42px !important;
    margin: 0 !important;
    border-radius: 12px !important;
    border: 1px solid rgba(255,255,255,.65) !important;
    background: #ffffff !important;
    color: #052A47 !important;
    font-size: 18px !important;
    font-weight: 800 !important;
    text-align: right;
    padding: 0 12px !important;
}

.pos-variance-line {
    display: grid;
    grid-template-columns: 1fr 1fr 1fr;
    gap: 10px;
    margin-top: 12px;
}

.pos-variance-box {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    padding: 10px 12px;
}

.pos-variance-box span {
    display: block;
    color: #64748b;
    font-size: 11px;
    font-weight: 700;
    margin-bottom: 4px;
}

.pos-variance-box b {
    display: block;
    color: #052A47;
    font-size: 16px;
    font-weight: 800;
}

@media (max-width: 900px) {
    .pos-cash-grid,
    .pos-variance-line {
        grid-template-columns: 1fr;
    }

    .pos-cash-table:first-child {
        border-right: 0;
        border-bottom: 1px solid #e2e8f0;
    }
}

.swal2-popup.pos-swal .pos-modal-panel table {
    margin: 0 !important;
}

.swal2-popup.pos-swal .swal2-validation-message {
    margin: 12px 28px 0 !important;
    border-radius: 12px !important;
    background: #fff7ed !important;
    color: #9a3412 !important;
    font-weight: 600 !important;
}

@media (max-width: 900px) {
    .pos-report-grid {
        grid-template-columns: 1fr;
    }

    .swal2-popup.pos-swal .swal2-title {
        font-size: 24px !important;
    }
}

/* Fixed-height Close POS Shift modal: only the content area scrolls */
.swal2-popup.pos-swal.close-shift-swal {
    height: min(84vh, 730px) !important;
    max-height: 84vh !important;
    display: flex !important;
    flex-direction: column !important;
    overflow: hidden !important;
    padding-bottom: 12px !important;
}

.swal2-popup.pos-swal.close-shift-swal .swal2-title {
    flex: 0 0 auto !important;
    margin: 18px 28px 12px !important;
}

.swal2-popup.pos-swal.close-shift-swal .swal2-html-container {
    flex: 1 1 auto !important;
    min-height: 0 !important;
    overflow: hidden !important;
    margin: 0 28px !important;
    padding: 0 !important;
}

.swal2-popup.pos-swal.close-shift-swal .swal2-actions {
    flex: 0 0 auto !important;
    margin: 8px 0 6px !important;
    padding: 0 !important;
    min-height: 0 !important;
}

.swal2-popup.pos-swal.close-shift-swal .swal2-actions .swal2-confirm,
.swal2-popup.pos-swal.close-shift-swal .swal2-actions .swal2-cancel {
    height: 48px !important;
    min-height: 48px !important;
    padding: 0 26px !important;
    font-size: 15px !important;
    border-radius: 14px !important;
}

.close-shift-panel {
    height: 100%;
    min-height: 0;
    display: flex;
    flex-direction: column;
    overflow: hidden;
}

.pos-close-shift-static {
    flex: 0 0 auto;
}

.pos-close-shift-scroll {
    flex: 1 1 auto;
    min-height: 0;
    overflow: auto;
    padding-right: 6px;
    margin-top: 10px;
}

.close-shift-panel .pos-cash-denomination-wrap {
    margin-top: 0;
}

.close-shift-panel .pos-variance-line,
.close-shift-panel #shiftCloseNotes {
    flex: 0 0 auto;
}


/* ===== COMPACT NO-SCROLL CASH COUNT + CLOSE SHIFT MODALS ===== */
.swal2-popup.pos-swal.cash-count-swal,
.swal2-popup.pos-swal.close-shift-swal {
    width: min(94vw, 900px) !important;
    max-height: 96vh !important;
    height: auto !important;
    padding: 10px 14px 12px !important;
    overflow: hidden !important;
}

.swal2-popup.pos-swal.cash-count-swal {
    width: min(92vw, 780px) !important;
}

.swal2-popup.pos-swal.cash-count-swal .swal2-title,
.swal2-popup.pos-swal.close-shift-swal .swal2-title {
    margin: 6px 12px 8px !important;
    padding: 0 !important;
    font-size: 18px !important;
    line-height: 1.15 !important;
}

.swal2-popup.pos-swal.cash-count-swal .swal2-html-container,
.swal2-popup.pos-swal.close-shift-swal .swal2-html-container {
    margin: 0 !important;
    padding: 0 !important;
    overflow: hidden !important;
    max-height: none !important;
}

.swal2-popup.pos-swal.cash-count-swal .pos-modal-panel,
.swal2-popup.pos-swal.close-shift-swal .pos-modal-panel {
    padding: 0 !important;
    margin: 0 !important;
}

.swal2-popup.pos-swal.cash-count-swal .pos-currency-line,
.swal2-popup.pos-swal.close-shift-swal .pos-currency-line {
    padding: 6px 8px !important;
    margin: 4px 0 6px !important;
    border-radius: 9px !important;
    font-size: 11px !important;
    gap: 6px !important;
}

.swal2-popup.pos-swal.cash-count-swal .pos-currency-check,
.swal2-popup.pos-swal.close-shift-swal .pos-currency-check {
    width: 16px !important;
    height: 16px !important;
    border-radius: 5px !important;
    font-size: 9px !important;
}

.swal2-popup.pos-swal.cash-count-swal .pos-cash-denomination-wrap,
.swal2-popup.pos-swal.close-shift-swal .pos-cash-denomination-wrap {
    margin-top: 4px !important;
    border-radius: 10px !important;
}

.swal2-popup.pos-swal.cash-count-swal .pos-cash-denomination-title,
.swal2-popup.pos-swal.close-shift-swal .pos-cash-denomination-title {
    padding: 6px 8px !important;
    font-size: 12px !important;
    line-height: 1.15 !important;
}

.swal2-popup.pos-swal.cash-count-swal .pos-cash-denomination-title small,
.swal2-popup.pos-swal.close-shift-swal .pos-cash-denomination-title small {
    font-size: 10px !important;
}

.swal2-popup.pos-swal.cash-count-swal .pos-cash-table th,
.swal2-popup.pos-swal.cash-count-swal .pos-cash-table td,
.swal2-popup.pos-swal.close-shift-swal .pos-cash-table th,
.swal2-popup.pos-swal.close-shift-swal .pos-cash-table td {
    height: 22px !important;
    padding: 2px 5px !important;
    font-size: 10.5px !important;
    line-height: 1.05 !important;
}

.swal2-popup.pos-swal.cash-count-swal .pos-cash-table td:first-child,
.swal2-popup.pos-swal.close-shift-swal .pos-cash-table td:first-child {
    width: 66px !important;
}

.swal2-popup.pos-swal.cash-count-swal .pos-cash-table .qty-cell,
.swal2-popup.pos-swal.close-shift-swal .pos-cash-table .qty-cell {
    width: 54px !important;
}

.swal2-popup.pos-swal.cash-count-swal .pos-cash-table .amount-cell,
.swal2-popup.pos-swal.close-shift-swal .pos-cash-table .amount-cell {
    width: 82px !important;
}

.swal2-popup.pos-swal.cash-count-swal .cash-denom-qty,
.swal2-popup.pos-swal.close-shift-swal .cash-denom-qty {
    height: 19px !important;
    min-height: 19px !important;
    padding: 0 4px !important;
    border-radius: 5px !important;
    font-size: 10.5px !important;
}

.swal2-popup.pos-swal.cash-count-swal .pos-cash-total-row,
.swal2-popup.pos-swal.close-shift-swal .pos-cash-total-row {
    grid-template-columns: 1fr 135px !important;
    gap: 8px !important;
    padding: 6px 8px !important;
}

.swal2-popup.pos-swal.cash-count-swal .pos-cash-total-row span,
.swal2-popup.pos-swal.close-shift-swal .pos-cash-total-row span {
    font-size: 11px !important;
}

.swal2-popup.pos-swal.cash-count-swal .pos-cash-total-row input,
.swal2-popup.pos-swal.cash-count-swal #shiftActualCash,
.swal2-popup.pos-swal.close-shift-swal .pos-cash-total-row input,
.swal2-popup.pos-swal.close-shift-swal #shiftActualCash {
    height: 26px !important;
    min-height: 26px !important;
    border-radius: 7px !important;
    font-size: 12px !important;
    padding: 0 8px !important;
}

.swal2-popup.pos-swal.cash-count-swal #cashCountNotes,
.swal2-popup.pos-swal.close-shift-swal #shiftCloseNotes {
    height: 34px !important;
    min-height: 34px !important;
    max-height: 34px !important;
    margin: 6px 0 0 !important;
    padding: 6px 8px !important;
    font-size: 10.5px !important;
    line-height: 1.15 !important;
    resize: none !important;
}

.swal2-popup.pos-swal.close-shift-swal .pos-report-grid {
    gap: 6px !important;
}

.swal2-popup.pos-swal.close-shift-swal .pos-report-card {
    padding: 6px 7px !important;
    border-radius: 9px !important;
}

.swal2-popup.pos-swal.close-shift-swal .pos-report-card span {
    font-size: 9.5px !important;
    margin-bottom: 2px !important;
}

.swal2-popup.pos-swal.close-shift-swal .pos-report-card b {
    font-size: 12px !important;
}

.swal2-popup.pos-swal.close-shift-swal .pos-close-shift-scroll {
    overflow: hidden !important;
    padding-right: 0 !important;
    margin-top: 4px !important;
}

.swal2-popup.pos-swal.close-shift-swal .pos-close-shift-static {
    margin: 0 !important;
    padding: 0 !important;
}

.swal2-popup.pos-swal.cash-count-swal .swal2-actions,
.swal2-popup.pos-swal.close-shift-swal .swal2-actions {
    margin: 8px 0 0 !important;
    padding: 0 !important;
    min-height: 0 !important;
}

.swal2-popup.pos-swal.cash-count-swal .swal2-actions .swal2-confirm,
.swal2-popup.pos-swal.cash-count-swal .swal2-actions .swal2-cancel,
.swal2-popup.pos-swal.close-shift-swal .swal2-actions .swal2-confirm,
.swal2-popup.pos-swal.close-shift-swal .swal2-actions .swal2-cancel {
    height: 32px !important;
    min-height: 32px !important;
    padding: 0 14px !important;
    font-size: 11px !important;
    border-radius: 9px !important;
}

.swal2-popup.pos-swal.cash-count-swal .swal2-validation-message,
.swal2-popup.pos-swal.close-shift-swal .swal2-validation-message {
    margin: 6px 0 0 !important;
    padding: 6px 8px !important;
    font-size: 10.5px !important;
    border-radius: 8px !important;
}


/* ===== CURVE-SAFE SPACING FOR COMPACT CASH COUNT + CLOSE SHIFT MODALS ===== */
/* Konting inner spacing para hindi kainin ng rounded corners ang labels/cards/table. */
.swal2-popup.pos-swal.cash-count-swal,
.swal2-popup.pos-swal.close-shift-swal {
    padding: 12px 16px 13px !important;
}

.swal2-popup.pos-swal.cash-count-swal .swal2-html-container,
.swal2-popup.pos-swal.close-shift-swal .swal2-html-container {
    padding: 0 3px 2px !important;
}

.swal2-popup.pos-swal.cash-count-swal .pos-modal-panel,
.swal2-popup.pos-swal.close-shift-swal .pos-modal-panel {
    padding: 4px !important;
    border-radius: 14px !important;
}

.swal2-popup.pos-swal.cash-count-swal .pos-currency-line,
.swal2-popup.pos-swal.close-shift-swal .pos-currency-line {
    margin-left: 0 !important;
    margin-right: 0 !important;
}

.swal2-popup.pos-swal.cash-count-swal .pos-cash-denomination-wrap,
.swal2-popup.pos-swal.close-shift-swal .pos-cash-denomination-wrap {
    border-radius: 8px !important;
    margin-left: 0 !important;
    margin-right: 0 !important;
}

.swal2-popup.pos-swal.cash-count-swal .pos-cash-denomination-title,
.swal2-popup.pos-swal.close-shift-swal .pos-cash-denomination-title {
    padding-left: 10px !important;
    padding-right: 10px !important;
}

.swal2-popup.pos-swal.close-shift-swal .pos-report-grid {
    padding: 0 1px !important;
}

.swal2-popup.pos-swal.close-shift-swal .pos-report-card:first-child,
.swal2-popup.pos-swal.close-shift-swal .pos-report-card:nth-child(4n + 1) {
    margin-left: 1px !important;
}

.swal2-popup.pos-swal.close-shift-swal .pos-report-card:nth-child(4n),
.swal2-popup.pos-swal.close-shift-swal .pos-report-card:last-child {
    margin-right: 1px !important;
}

.swal2-popup.pos-swal.cash-count-swal #cashCountNotes,
.swal2-popup.pos-swal.close-shift-swal #shiftCloseNotes {
    width: calc(100% - 2px) !important;
    margin-left: 1px !important;
    margin-right: 1px !important;
}

/* ===== GLOBAL POS MODERN SCROLLBAR - CONSISTENT ALL SCROLLABLE AREAS ===== */
* {
    scrollbar-width: thin;
    scrollbar-color: rgba(5, 42, 71, .35) transparent;
}

*::-webkit-scrollbar {
    width: 6px;
    height: 6px;
}

*::-webkit-scrollbar-track {
    background: transparent;
}

*::-webkit-scrollbar-thumb {
    background: rgba(5, 42, 71, .32);
    border-radius: 999px;
    border: 1px solid rgba(255, 255, 255, .35);
    background-clip: padding-box;
}

*::-webkit-scrollbar-thumb:hover {
    background: rgba(5, 42, 71, .52);
}

*::-webkit-scrollbar-button {
    width: 0;
    height: 0;
    display: none;
}

*::-webkit-scrollbar-corner {
    background: transparent;
}

/* Main POS scrollable containers */
.cart-wrap,
.suggest,
.tender-left,
.settings-scroll,
.pos-modal-body,
.pos-table-wrap,
.sales-table-wrap,
.swal2-html-container {
    scrollbar-width: thin !important;
    scrollbar-color: rgba(5, 42, 71, .35) transparent !important;
    scrollbar-gutter: auto !important;
}

/* Dark/green modal panels use lighter thumb */
.tender-left,
.settings-scroll,
.pos-modal-body.dark-scroll {
    scrollbar-color: rgba(255, 255, 255, .25) transparent !important;
}

.tender-left::-webkit-scrollbar-thumb,
.settings-scroll::-webkit-scrollbar-thumb,
.pos-modal-body.dark-scroll::-webkit-scrollbar-thumb {
    background: rgba(255, 255, 255, .24) !important;
    border: 1px solid rgba(255, 255, 255, .16) !important;
    border-radius: 999px !important;
}

.tender-left::-webkit-scrollbar-thumb:hover,
.settings-scroll::-webkit-scrollbar-thumb:hover,
.pos-modal-body.dark-scroll::-webkit-scrollbar-thumb:hover {
    background: rgba(255, 255, 255, .40) !important;
}

</style>
</head>

<body>
<div class="app">
    <div class="pos-header">
        <div class="cashier-name"><?= htmlspecialchars($cashierName) ?></div>

        <div class="pos-header-price-level">
            <div class="price-level-row topbar-price-level-row">
                <label for="priceLevelSelect">Price Level</label>
                <select id="priceLevelSelect" class="price-level-select" onchange="changePriceLevel(this.value)">
                </select>
                <span class="price-level-shortcut">F9 / Ctrl+L</span>
            </div>
        </div>

        <div class="datebar">
            <span id="clock"></span>
            <button class="iconbtn" onclick="openBranchSettings()" title="Branch Settings"><i class="fa-solid fa-gear"></i></button>
            <?php if ($canSwitchToBranchAdmin): ?>
                <button class="iconbtn switch-admin" onclick="switchToBranchAdmin()" title="Switch to Branch Admin">
                    <i class="fa-solid fa-right-left"></i><span>Branch Admin</span>
                </button>
            <?php endif; ?>
            <button class="iconbtn exit" onclick="logoutPOS()" title="Logout"><i class="fa-solid fa-right-from-bracket"></i></button>
        </div>
    </div>

    <div class="pos-main">
        <div class="scan-panel">
            <div class="scan-group searcharea">
                <label class="scan-label" for="productSearch">QTY &amp; BARCODE</label>
                <div class="searchrow">
                    <input class="scan-input" id="productSearch" placeholder="Example: 2*BARCODE" autocomplete="off">
                </div>

                <div id="scanStatus" class="scan-help">Ready to scan. Format: QTY*BARCODE</div>
            </div>

            <div class="scan-group">
                <label class="scan-label">SUB TOTAL</label>
                <div class="total-display">₱ <span id="scanSubtotal">0.00</span></div>
            </div>


            <div class="scan-group">
                <label class="scan-label">TOTAL</label>
                <div class="total-display">₱ <span id="scanTotal">0.00</span></div>
            </div>

            <div class="hidden-totals">
                <span id="sumSubtotal">0.00</span>
                <span id="sumVatSales">0.00</span>
                <span id="sumDiscount">0.00</span>
                <span id="sumDue">0.00</span>
                <span id="sumItems">0</span>
                <span id="bigTotal">0.00</span>
            </div>
        </div>

        <div class="cart-panel">
            <div class="cart-wrap">
                <table class="pos-table">
                    <thead>
                        <tr>
                            <th>ITEM</th>
                            <th style="width:80px">QTY</th>
                            <th style="width:120px">PRICE</th>
                            <th style="width:140px">AMOUNT</th>
                        </tr>
                    </thead>
                    <tbody id="cartBody"></tbody>
                </table>
            </div>
            <div id="cartSaleSummary" class="cart-sale-summary"></div>
        </div>
    </div>

        <div class="pos-actions">
            <button class="tool" onclick="editItem()"><span>F1</span><i class="fa-solid fa-pen-to-square"></i>Edit Item</button>
            <button class="tool" onclick="clearCart()"><span>F2</span><i class="fa-solid fa-circle-xmark"></i>Cancel</button>
            <button class="tool" onclick="openDiscount()"><span>F3</span><i class="fa-solid fa-percent"></i>Discount</button>
            <button class="tool" onclick="showNotDone('Tag Order')"><span>F4</span><i class="fa-solid fa-cart-plus"></i>Tag Order</button>
            <button class="tool" onclick="window.openSalesOrder()"><span>F5</span><i class="fa-solid fa-ellipsis"></i>Sales Order</button>
            <button class="tool" onclick="changeQty()"><span>F6</span><i class="fa-solid fa-circle-plus"></i>+ / - Qty</button>
            <button class="tool" onclick="editPrice()"><span>F7</span><i class="fa-solid fa-tag"></i>Edit Price</button>
            <button class="tool borange" onclick="voidSelectedItem()"><span>F8</span><i class="fa-solid fa-trash"></i>Void Item</button>
            <button class="bbtn bgreen" onclick="openCashCount()"><span class="kbd">Ctrl1</span><i class="fa-solid fa-lock"></i>Cash count</button>
            <button class="bbtn bgreen" onclick="openCashTransfer()"><span class="kbd">Ctrl2</span><i class="fa-solid fa-hand-holding-dollar"></i>Cash transfer</button>
            <button class="bbtn bgreen" onclick="openPickItem()"><span class="kbd">Ctrl3</span><i class="fa-solid fa-hand-pointer"></i>Pick Item</button>
            <button class="bbtn bblue" onclick="openCustomerLookup()"><span class="kbd">Ctrl4</span><i class="fa-solid fa-user"></i>Customer</button>
            <button class="bbtn byellow" onclick="openRecentSales()"><span class="kbd">Ctrl7</span><i class="fa-regular fa-clock"></i>Recent Sales</button>
            <button class="bbtn borange" onclick="openVoidSale()"><span class="kbd">Ctrl8</span><i class="fa-solid fa-ban"></i>Void Sale</button>
            <button class="bbtn bviolet" onclick="openZReading()"><span class="kbd">Ctrl0</span><i class="fa-solid fa-cash-register"></i>Z-READING</button>
            <button class="bbtn bblue" onclick="startNewCustomer()"><span class="kbd">ESC</span><i class="fa-solid fa-user-plus"></i>New Transaction</button>
            <button class="tender" onclick="openTender()"><small>F12</small>Tender</button>
        </div>
</div>
<div id="suggest" class="suggest"></div>
<div id="customerSuggest" class="suggest customer-suggest"></div>
<div class="modal" id="tenderModal">
    <div class="box tender-box">
        <div class="tender-header">
            <h3>Tender Payment</h3>

            <div class="field tender-customer-field">
                <label>Customer Name</label>
                <input id="customerName" value="Walk-in Customer" autocomplete="off" onfocus="handleCustomerSearchFocus()" oninput="handleCustomerSearchInput(event)" onkeydown="handleCustomerSearchKeydown(event)">
            </div>
        </div>

        <div class="content tender-content">
            <div class="tender-left">
                <div class="field">
                    <label>Amount Due</label>
                    <input id="tenderDue" readonly>
                </div>

                <div class="field" id="pointsRedeemWrap">
                    <label>Redeem Points <small id="availablePointsText" style="font-weight:700;color:#FAF9F6;">Available: 0</small></label>
                    <input id="pointsRedeemInput" type="text" inputmode="decimal" autocomplete="off" placeholder="0" oninput="handlePointsRedeemInput()" onkeydown="handleTenderEnter(event)">
                </div>

                <div class="field">
                    <label>Payment Method</label>
                    <select id="paymentMethodSelect" onchange="handlePaymentMethodChange()" onkeydown="handleTenderEnter(event)">
                        <option value="Cash">Cash</option>
                        <option value="GCash">GCash</option>
                        <option value="Online Transfer">Online Transfer</option>
                        <option value="Check">Check</option>
                    </select>
                </div>

                <div class="field">
                    <label id="tenderAmountLabel">Tendered Amount</label>
                    <input id="tenderedAmount" class="payment-amount-input" type="text" inputmode="decimal" autocomplete="off" onfocus="setActivePaymentInput('tenderedAmount')" oninput="formatTenderAmountInput()" onkeydown="handleTenderEnter(event)">
                </div>

                <div class="field tender-extra-field" id="referenceFieldWrap" style="display:none;">
                    <label id="referenceFieldLabel">Reference No.</label>
                    <input id="paymentReferenceNo" autocomplete="off" onkeydown="handleTenderEnter(event)">
                </div>

                <div class="field tender-extra-field" id="checkFieldWrap" style="display:none;">
                    <label>Check No.</label>
                    <input id="checkNo" autocomplete="off" onkeydown="handleTenderEnter(event)">
                </div>

                <div class="field">
                    <label>Change</label>
                    <input id="tenderChange" readonly>
                </div>

                <div class="mixed-payment-panel" id="mixedPaymentPanel" style="display:none;">
                    <div class="mixed-payment-title">
                        <span>Mixed Payments</span>
                        <button type="button" onclick="clearMixedPayments()">Clear Mix</button>
                    </div>
                    <div id="mixedPaymentList" class="mixed-payment-list"></div>
                </div>

                <div class="payment-summary-box">
                    <div class="payment-summary-line"><span>Total Paid</span><b>₱ <span id="tenderTotalPaid">0.00</span></b></div>
                    <div class="payment-summary-line"><span>Balance</span><b>₱ <span id="tenderBalance">0.00</span></b></div>
                </div>

                <button type="button" class="add-payment-btn" onclick="addCurrentPaymentToMix()">
                    <i class="fa-solid fa-plus"></i> Add Payment Method
                </button>

            </div>

            <div class="tender-keypad" aria-label="Tender numpad">
                <button type="button" onclick="appendTenderKey('7')">7</button>
                <button type="button" onclick="appendTenderKey('8')">8</button>
                <button type="button" onclick="appendTenderKey('9')">9</button>
                <button type="button" onclick="appendTenderKey('4')">4</button>
                <button type="button" onclick="appendTenderKey('5')">5</button>
                <button type="button" onclick="appendTenderKey('6')">6</button>
                <button type="button" onclick="appendTenderKey('1')">1</button>
                <button type="button" onclick="appendTenderKey('2')">2</button>
                <button type="button" onclick="appendTenderKey('3')">3</button>
                <button type="button" onclick="appendTenderKey('0')">0</button>
                <button type="button" onclick="appendTenderKey('.')">.</button>
                <button type="button" class="key-danger" onclick="backspaceTenderKey()">Del</button>
                <button type="button" class="key-clear key-clear-wide" onclick="clearTenderKey()">Clear</button>
                <button type="button" class="key-danger key-cancel" onclick="closeTender()">Cancel</button>
                <button type="button" class="key-wide key-ok" onclick="saveSale()">Enter</button>
            </div>
        </div>
    </div>
</div>

<script>
const initialProducts = <?= json_encode($initialProducts, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
const posPriceLevels = <?= json_encode($posPriceLevels, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
const posBranchName = <?= json_encode($branchName, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
const posCashierName = <?= json_encode($cashierName, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
let posReceiptInfo = <?= json_encode($posReceiptInfo, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
let posVatRegistered = <?= $posVatRegistered ? 'true' : 'false' ?>;
let posVatRate = <?= json_encode($posVatRate) ?>;
let posVatRatePercent = <?= json_encode($posVatRatePercent) ?>;
let cart = [];
let activeIndex = -1;
let selectedCustomerName = 'Walk-in Customer';
let selectedCustomerId = 0;
let selectedCustomerCode = '';
let selectedCustomerPoints = 0;
let selectedPointsToRedeem = 0;
let completedSaleSummary = null;
let saleAwaitingNewCustomer = false;
let posShiftOpen = false;
let posShiftData = null;
let orderDiscount = {
    type: 'none',
    value: 0,
    amount: 0,
    label: ''
};

let currentPriceLevel = (Array.isArray(posPriceLevels) && posPriceLevels.find(level => ['walk in', 'walk-in'].includes(String(level).trim().toLowerCase())))
    || (Array.isArray(posPriceLevels) && posPriceLevels[0])
    || 'Walk In';


// Make every SweetAlert modal follow the POS modal style automatically.
if (window.Swal && !window.__posSwalStyled) {
    window.__posSwalStyled = true;
    const originalSwalFire = Swal.fire.bind(Swal);

    function mergeSweetAlertClasses(options) {
        const cfg = Object.assign({}, options || {});
        const current = cfg.customClass || {};

        cfg.customClass = Object.assign({}, current, {
            popup: [current.popup, 'pos-swal'].filter(Boolean).join(' '),
            confirmButton: [current.confirmButton, 'pos-confirm-btn'].filter(Boolean).join(' '),
            cancelButton: [current.cancelButton, 'pos-cancel-btn'].filter(Boolean).join(' '),
            denyButton: [current.denyButton, 'pos-deny-btn'].filter(Boolean).join(' ')
        });

        cfg.buttonsStyling = false;
        return cfg;
    }

    Swal.fire = function(...args) {
        if (args.length === 1 && typeof args[0] === 'object') {
            return originalSwalFire(mergeSweetAlertClasses(args[0]));
        }

        return originalSwalFire(mergeSweetAlertClasses({
            title: args[0] || '',
            html: args[1] || '',
            icon: args[2] || undefined
        }));
    };
}

const peso = new Intl.NumberFormat('en-PH', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2
});

function fmt(n) {
    return peso.format(Number(n || 0));
}

function formatSaleDateTime(dateString) {
    if (!dateString) {
        return '';
    }

    const rawValue = String(dateString).trim();
    if (rawValue === '') {
        return '';
    }

    // MySQL usually returns YYYY-MM-DD HH:MM:SS. Safari/Chrome parse it more safely with T separator.
    const normalizedValue = rawValue.includes(' ') && !rawValue.includes('T')
        ? rawValue.replace(' ', 'T')
        : rawValue;

    const date = new Date(normalizedValue);

    if (Number.isNaN(date.getTime())) {
        return rawValue;
    }

    return date.toLocaleString('en-PH', {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit'
    });
}

window.formatSaleDateTime = formatSaleDateTime;

function clock() {
    const now = new Date();

    document.getElementById('clock').textContent = now.toLocaleString('en-US', {
        weekday: 'long',
        year: 'numeric',
        month: 'long',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit'
    });
}

setInterval(clock, 30000);
clock();



function priceLevelKey(level) {
    return String(level || '').trim().toLowerCase().replace(/[^a-z0-9]+/g, '');
}

function getUomPriceForLevel(uom, level = currentPriceLevel) {
    const prices = uom && uom.price_levels && typeof uom.price_levels === 'object' ? uom.price_levels : {};
    const wantedKey = priceLevelKey(level);
    const standardKey = priceLevelKey('Standard');

    // Strict price-level filtering:
    // 1) Use the exact selected price level.
    // 2) If missing, use Standard only.
    // 3) If Standard is missing, use the item/UoM base price.
    // Do not fall back to Walk In/Retail/first available price when another level is selected.
    for (const [priceLevel, price] of Object.entries(prices)) {
        if (priceLevelKey(priceLevel) === wantedKey && Number(price) >= 0) {
            return Number(price);
        }
    }

    if (wantedKey !== standardKey) {
        for (const [priceLevel, price] of Object.entries(prices)) {
            if (priceLevelKey(priceLevel) === standardKey && Number(price) >= 0) {
                return Number(price);
            }
        }
    }

    return Number(uom && uom.unit_price ? uom.unit_price : 0);
}

function initPriceLevelDropdown() {
    const select = document.getElementById('priceLevelSelect');

    if (!select) {
        return;
    }

    const levels = (Array.isArray(posPriceLevels) && posPriceLevels.length ? posPriceLevels : ['Walk In'])
        .map(level => String(level || '').trim())
        .filter(Boolean);

    const uniqueLevels = [];

    levels.forEach(level => {
        if (!uniqueLevels.some(existing => priceLevelKey(existing) === priceLevelKey(level))) {
            uniqueLevels.push(level);
        }
    });

    if (!uniqueLevels.some(level => ['walk in', 'walk-in'].includes(level.trim().toLowerCase()))) {
        uniqueLevels.unshift('Walk In');
    }

    select.innerHTML = uniqueLevels.map(level => `<option value="${escapeHtml(level)}">${escapeHtml(level)}</option>`).join('');

    const defaultLevel = uniqueLevels.find(level => ['walk in', 'walk-in'].includes(level.trim().toLowerCase())) || uniqueLevels[0] || 'Walk In';
    currentPriceLevel = defaultLevel;
    select.value = defaultLevel;
}

function changePriceLevel(level) {
    const newLevel = String(level || 'Walk In').trim() || 'Walk In';
    currentPriceLevel = newLevel;

    cart.forEach(item => {
        const selectedUom = getCartUomOptions(item).find(u => String(u.uom_key) === String(item.uom_key || 'default')) || getDefaultProductUom(item);

        if (selectedUom) {
            item.price = getUomPriceForLevel(selectedUom, currentPriceLevel);
            item.price_level = currentPriceLevel;
        }
    });

    renderCart();

    const suggestBox = document.getElementById('suggest');
    if (suggestBox && suggestBox.style.display === 'block') {
        searchProduct();
    }

    setScanStatus('Price Level: ' + currentPriceLevel + '. Ready to scan.');
    focusBarcodeInput();
}

function cyclePriceLevel() {
    const select = document.getElementById('priceLevelSelect');

    if (!select || !select.options.length) {
        return;
    }

    const nextIndex = (select.selectedIndex + 1) % select.options.length;
    select.selectedIndex = nextIndex;
    changePriceLevel(select.value);
}


function normalizeProductUoms(product) {
    const rows = Array.isArray(product && product.uoms) ? product.uoms : [];
    const normalized = rows.map((uom, index) => {
        const key = String(uom.uom_key || (uom.uom_id ? 'uom_' + uom.uom_id : 'uom_index_' + index));
        const name = String(uom.uom_name || uom.uom_initial || product.unit_type || 'UoM');
        const initial = String(uom.uom_initial || name);
        return {
            uom_key: key,
            uom_id: Number(uom.uom_id || 0),
            uom_name: name,
            uom_initial: initial,
            price_levels: (uom.price_levels && typeof uom.price_levels === 'object') ? uom.price_levels : {},
            unit_price: getUomPriceForLevel(uom, currentPriceLevel),
            base_unit_price: Number(uom.unit_price ?? product.unit_price ?? 0),
            conversion_qty: Math.max(1, Number(uom.conversion_qty || 1)),
            stock_qty: Number(uom.stock_qty ?? product.stock_qty ?? 0),
            is_default_uom: Number(uom.is_default_uom || 0),
            barcode: String(uom.barcode || '')
        };
    });

    if (normalized.length > 0) {
        return normalized;
    }

    return [{
        uom_key: 'default',
        uom_id: Number(product.uom_id || 0),
        uom_name: String(product.uom_name || product.unit_type || product.uom_initial || 'UoM'),
        uom_initial: String(product.uom_initial || product.uom_name || product.unit_type || 'UoM'),
        price_levels: (product.price_levels && typeof product.price_levels === 'object') ? product.price_levels : {},
        unit_price: getUomPriceForLevel(product, currentPriceLevel),
        base_unit_price: Number(product.unit_price || 0),
        conversion_qty: Math.max(1, Number(product.conversion_qty || 1)),
        stock_qty: Number(product.stock_qty || 0),
        is_default_uom: 1,
        barcode: String(product.barcode || '')
    }];
}

function getDefaultProductUom(product) {
    const uoms = normalizeProductUoms(product);
    const selectedKey = String(product.selected_uom_key || '');
    return uoms.find(u => selectedKey && u.uom_key === selectedKey)
        || uoms.find(u => Number(u.is_default_uom || 0) === 1)
        || uoms[0];
}

function getCartUomOptions(item) {
    return Array.isArray(item.uoms) && item.uoms.length ? item.uoms : normalizeProductUoms(item);
}

function applyCartUom(item, uom) {
    if (!item || !uom) {
        return;
    }

    item.uom_key = String(uom.uom_key || 'default');
    item.uom_id = Number(uom.uom_id || 0);
    item.uom_name = String(uom.uom_name || uom.uom_initial || item.unit_type || 'UoM');
    item.uom_initial = String(uom.uom_initial || uom.uom_name || item.unit_type || 'UoM');
    item.conversion_qty = Math.max(1, Number(uom.conversion_qty || 1));
    item.price = getUomPriceForLevel(uom, currentPriceLevel);
    item.price_level = currentPriceLevel;
    item.stock_qty = Number(uom.stock_qty ?? item.stock_qty ?? 0);
}

function handleCartUomChange(index, uomKey) {
    const item = cart[index];

    if (!item) {
        return;
    }

    const selectedUom = getCartUomOptions(item).find(u => String(u.uom_key) === String(uomKey));

    if (!selectedUom) {
        return;
    }

    const newStockQty = Number(selectedUom.stock_qty || 0);

    if (Number(item.qty || 0) > newStockQty) {
        Swal.fire('Insufficient stock', `${escapeHtml(item.name)} has only ${fmt(newStockQty)} ${escapeHtml(selectedUom.uom_initial || selectedUom.uom_name || '')} available.`, 'warning');
        renderCart();
        return;
    }

    applyCartUom(item, selectedUom);
    renderCart();
}


function renderCart() {
    const body = document.getElementById('cartBody');
    body.innerHTML = '';

    cart.forEach((it, idx) => {
        const gross = getLineGross(it);
        const itemDiscount = getItemDiscount(it);
        const lineTotal = Math.max(0, gross - itemDiscount);
        const tr = document.createElement('tr');
        const discountText = itemDiscount > 0
            ? `<span class="discount-note">${escapeHtml(it.discount_label || 'Discount')} - ₱${fmt(itemDiscount)}</span>`
            : '';
        const uomText = ((it.uom_initial || it.uom_name || '').trim()
            ? `<span class="item-uom">${escapeHtml(it.uom_initial || it.uom_name || '')}</span>`
            : '');

        tr.className = idx === activeIndex ? 'active' : '';
        tr.onclick = () => {
            activeIndex = idx;
            renderCart();
        };

        tr.innerHTML = `
            <td class="item">${escapeHtml(it.name)}${uomText}${discountText}</td>
            <td class="qty">${fmt(it.qty)}</td>
            <td class="price">${fmt(it.price)}</td>
            <td class="line-total">${fmt(lineTotal)}</td>
        `;

        body.appendChild(tr);
    });

    updateTotals();
    renderCompletedSaleSummary();
}

function getPaymentSummaryLabel(payments, fallbackMethod) {
    const rows = Array.isArray(payments) ? payments.filter(row => Number(row.amount || 0) > 0) : [];

    if (rows.length === 0) {
        return fallbackMethod || 'PAYMENT';
    }

    if (rows.length === 1) {
        return String(rows[0].payment_method || fallbackMethod || 'PAYMENT').toUpperCase();
    }

    return 'MIXED PAYMENT';
}

function renderCompletedSaleSummary() {
    const panel = document.querySelector('.cart-panel');
    const box = document.getElementById('cartSaleSummary');

    if (!panel || !box) {
        return;
    }

    if (!completedSaleSummary) {
        panel.classList.remove('sale-complete');
        box.innerHTML = '';
        return;
    }

    const summary = completedSaleSummary;
    const paymentLabel = escapeHtml(getPaymentSummaryLabel(summary.payments, summary.paymentMethod));

    box.innerHTML = `
        <div class="summary-separator"></div>
        <div class="summary-line"><span>SUBTOTAL</span><strong>₱${fmt(summary.subtotal)}</strong></div>
        ${Number(summary.discount || 0) > 0 ? `<div class="summary-line"><span>${escapeHtml(summary.discountLabel || 'DISCOUNT')}</span><strong>- ₱${fmt(summary.discount)}</strong></div>` : ''}
        <div class="summary-line"><span>AMOUNT DUE</span><strong>₱${fmt(summary.total)}</strong></div>
        <div class="summary-line"><span>${paymentLabel}</span><strong>₱${fmt(summary.tendered)}</strong></div>
        <div class="summary-separator"></div>
        <div class="summary-line"><span>CHANGE</span><strong>₱${fmt(summary.change)}</strong></div>
        <div class="summary-separator"></div>
        <div class="summary-hint">Press ESC or New Transaction to clear and scan again.</div>
    `;

    panel.classList.add('sale-complete');
}

function resetSaleState() {
    completedSaleSummary = null;
    saleAwaitingNewCustomer = false;
}

function startNewCustomer(skipConfirm = false) {
    const doReset = () => {
        cart = [];
        activeIndex = -1;
        selectedCustomerName = 'Walk-in Customer';
        selectedCustomerId = 0;
        selectedCustomerCode = '';
        selectedCustomerPoints = 0;
        selectedPointsToRedeem = 0;
        orderDiscount = { type: 'none', value: 0, amount: 0, label: '' };
        resetSaleState();
        hideSuggestDropdown();

        const tenderCustomer = document.getElementById('customerName');
        if (tenderCustomer) {
            tenderCustomer.value = 'Walk-in Customer';
        }

        const searchInput = document.getElementById('productSearch');
        if (searchInput) {
            searchInput.value = '';
        }

        renderCart();
        focusBarcodeInput();
    };

    if (skipConfirm || saleAwaitingNewCustomer || completedSaleSummary || cart.length === 0) {
        doReset();
        return;
    }

    Swal.fire({
        title: 'Start new transaction?',
        text: 'This will clear the current cart and prepare the POS for the next sale.',
        icon: 'warning',
        showCancelButton: true
    }).then(result => {
        if (result.isConfirmed) {
            doReset();
        }
    });
}

function requireNewCustomerClear() {
    if (!saleAwaitingNewCustomer && !completedSaleSummary) {
        return true;
    }

    setScanStatus('Press ESC or New Transaction first', 'error');
    Swal.fire('Sale already completed', 'Press ESC or click New Transaction before scanning again.', 'info');
    return false;
}

function moveActiveRow(direction) {
    if (!cart.length) {
        return;
    }

    if (activeIndex < 0) {
        activeIndex = 0;
    } else {
        activeIndex += direction;
    }

    if (activeIndex < 0) {
        activeIndex = 0;
    }

    if (activeIndex > cart.length - 1) {
        activeIndex = cart.length - 1;
    }

    renderCart();
    scrollActiveRowIntoView();
}

function scrollActiveRowIntoView() {
    const activeRow = document.querySelector('#cartBody tr.active');

    if (!activeRow) {
        return;
    }

    activeRow.scrollIntoView({
        block: 'nearest',
        inline: 'nearest'
    });
}

function getLineGross(item) {
    return Number(item.qty || 0) * Number(item.price || 0);
}

function getItemDiscount(item) {
    return Math.min(getLineGross(item), Number(item.discount_amount || 0));
}

function getOrderGross() {
    return cart.reduce((sum, item) => sum + getLineGross(item), 0);
}

function getItemDiscountTotal() {
    return cart.reduce((sum, item) => sum + getItemDiscount(item), 0);
}

function getSubtotalAfterItemDiscount() {
    return Math.max(0, getOrderGross() - getItemDiscountTotal());
}

function getOrderDiscountAmount() {
    const base = getSubtotalAfterItemDiscount();

    if (!orderDiscount || orderDiscount.type === 'none') {
        return 0;
    }

    if (orderDiscount.type === 'amount') {
        return Math.min(base, Math.max(0, Number(orderDiscount.value || 0)));
    }

    if (orderDiscount.type === 'percentage' || orderDiscount.type === 'senior' || orderDiscount.type === 'pwd' || orderDiscount.type === 'employee') {
        return Math.min(base, base * (Math.max(0, Number(orderDiscount.value || 0)) / 100));
    }

    return 0;
}

function getTotalDiscountAmount() {
    return getItemDiscountTotal() + getOrderDiscountAmount();
}

function getOrderDiscountLabel() {
    if (!orderDiscount || orderDiscount.type === 'none' || getOrderDiscountAmount() <= 0) {
        return '';
    }

    return orderDiscount.label || 'Transaction Discount';
}

function updateTotals() {
    const subtotal = getOrderGross();
    const itemDiscount = getItemDiscountTotal();
    const orderDisc = getOrderDiscountAmount();
    const discount = itemDiscount + orderDisc;
    const due = Math.max(0, subtotal - discount);
    const count = cart.reduce((sum, item) => sum + Number(item.qty || 0), 0);
    const last = activeIndex >= 0 && cart[activeIndex]
        ? Math.max(0, getLineGross(cart[activeIndex]) - getItemDiscount(cart[activeIndex]))
        : 0;

    const setText = (id, value) => {
        const el = document.getElementById(id);
        if (el) {
            el.textContent = value;
        }
    };

    setText('scanSubtotal', fmt(last));
    setText('scanTotal', fmt(due));
    setText('sumSubtotal', fmt(subtotal));
    setText('sumVatSales', fmt(posVatRegistered ? (due / (1 + Number(posVatRate || 0))) : 0));
    setText('sumDiscount', fmt(discount));
    setText('sumDue', fmt(due));
    setText('sumItems', fmt(count));
    setText('bigTotal', fmt(due));
}

function escapeHtml(s) {
    return String(s).replace(/[&<>'"]/g, c => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        "'": '&#39;',
        '"': '&quot;'
    }[c]));
}

async function api(payload) {
    const res = await fetch(location.href, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify(payload)
    });

    const responseText = await res.text();

    try {
        return JSON.parse(responseText);
    } catch (error) {
        return {
            success: false,
            message: responseText ? responseText.slice(0, 350) : 'Server returned an invalid response while saving.'
        };
    }
}

function renderShiftStatusText() {
    if (!posShiftOpen || !posShiftData) {
        return 'No open POS shift.';
    }

    return `Shift Open • Beginning Cash: ₱${fmt(posShiftData.beginning_cash || 0)} • Expected Cash: ₱${fmt(posShiftData.expected_cash || 0)}`;
}

function requireOpenShift() {
    if (posShiftOpen) {
        return true;
    }

    setScanStatus('Open shift first before using POS', 'error');
    openShiftModal(false);
    return false;
}

async function refreshShiftStatus() {
    try {
        const data = await api({ action: 'get_shift_status' });

        if (data.success && data.shift) {
            posShiftData = data.shift;
            posShiftOpen = !!data.shift.is_open;
        } else {
            posShiftData = null;
            posShiftOpen = false;
        }
    } catch (error) {
        console.warn('Unable to load POS shift status.', error);
        posShiftData = null;
        posShiftOpen = false;
    }

    if (posShiftOpen) {
        setScanStatus(renderShiftStatusText(), 'ok');
    }

    return posShiftOpen;
}

async function openShiftModal(allowCancel = false) {
    if (posShiftOpen) {
        return true;
    }

    const result = await Swal.fire({
        title: 'Open POS Shift',
        html: `
            <div class="pos-modal-panel">
                <div class="pos-section-title" style="margin-top:0;">Beginning Cash / Cash on Hand</div>
                <input id="shiftOpeningCash" type="number" step="0.01" min="0" class="swal2-input" placeholder="Enter cash for change" style="margin:0;width:100%;">
                <div class="pos-shift-help">
                    Enter the starting cash available for change before beginning the shift.
                </div>
            </div>
        `,
        allowOutsideClick: false,
        allowEscapeKey: allowCancel,
        showCancelButton: allowCancel,
        confirmButtonText: 'Start Shift',
        cancelButtonText: 'Cancel',
        width: 640,
        didOpen: () => {
            const openingInput = document.getElementById('shiftOpeningCash');

            if (openingInput) {
                openingInput.focus();
                openingInput.select();

                openingInput.addEventListener('keydown', function(event) {
                    if (event.key === 'Enter') {
                        event.preventDefault();
                        Swal.clickConfirm();
                    }
                });
            }
        },
        preConfirm: () => {
            const amount = Number(document.getElementById('shiftOpeningCash').value || 0);

            if (!Number.isFinite(amount) || amount < 0) {
                Swal.showValidationMessage('Enter a valid amount.');
                return false;
            }

            return amount;
        }
    });

    if (!result.isConfirmed) {
        return false;
    }

    const data = await api({ action: 'open_shift', amount: result.value });

    if (!data.success) {
        await Swal.fire('Unable to open shift', data.message || 'Please try again.', 'error');
        return openShiftModal(allowCancel);
    }

    posShiftData = data.shift || null;
    posShiftOpen = true;
    setScanStatus(renderShiftStatusText(), 'ok');
    focusBarcodeInput();
    return true;
}

async function ensureShiftReady() {
    const isOpen = await refreshShiftStatus();

    if (!isOpen) {
        await openShiftModal(false);
    }
}

const shiftBillDenominations = [1000, 500, 200, 100, 50, 20];
const shiftCoinDenominations = [20, 10, 5, 1, 0.25, 0.10, 0.05];

function shiftDenomKey(value) {
    return String(value).replace('.', '_');
}

function renderCashBreakdownRows(type, denominations) {
    return denominations.map(value => {
        const key = `${type}_${shiftDenomKey(value)}`;
        const label = Number(value) >= 1 ? fmt(value).replace('.00', '') : fmt(value);

        return `
            <tr>
                <td>${label}</td>
                <td class="qty-cell"><input type="text" inputmode="numeric" class="cash-denom-qty" data-denom="${value}" data-type="${type}" data-key="${key}" autocomplete="off"></td>
                <td class="amount-cell" id="cashAmount_${key}">0.00</td>
            </tr>
        `;
    }).join('');
}

function buildCloseShiftDenominationNote() {
    const rows = [];

    document.querySelectorAll('.cash-denom-qty').forEach(input => {
        const qty = Number(String(input.value || '').replace(/,/g, '').replace(/\D/g, '') || 0);
        const denom = Number(input.dataset.denom || 0);
        const type = input.dataset.type === 'coins' ? 'Coins' : 'Bills';

        if (qty > 0 && denom > 0) {
            rows.push(`${type} ${denom} x ${qty} = ${fmt(denom * qty)}`);
        }
    });

    return rows.length ? `Cash Denomination: ${rows.join('; ')}` : 'Cash Denomination: No denomination entered';
}


function getCloseShiftDenominationDetails() {
    const rows = [];
    let total = 0;

    document.querySelectorAll('.cash-denom-qty').forEach(input => {
        const qty = Number(String(input.value || '').replace(/,/g, '').replace(/\D/g, '') || 0);
        const denom = Number(input.dataset.denom || 0);
        const type = input.dataset.type === 'coins' ? 'Coin' : 'Cash';
        const amount = qty * denom;

        if (denom > 0) {
            rows.push({ type, denom, qty, amount });
            total += amount;
        }
    });

    return { rows, total };
}

function buildShiftCloseReceiptHtml(shiftReceipt) {
    const width = 48;
    const now = new Date();
    const startDate = shiftReceipt.openedAt ? new Date(shiftReceipt.openedAt.replace(' ', 'T')) : null;
    const nowText = splitDateTime(now);
    const startText = startDate && !Number.isNaN(startDate.getTime()) ? splitDateTime(startDate) : null;
    const receiptInfo = posReceiptInfo || {};
    const logoImage = receiptInfo.logo_image || '';
    const storeName = receiptInfo.store_name || posBranchName || 'AMGC STORE';
    const address = receiptInfo.address || '';
    const tin = receiptInfo.tin || '';
    const serialNo = receiptInfo.serial_no || '';
    const minNo = receiptInfo.min_no || '';
    const cashRows = Array.isArray(shiftReceipt.denominations) ? shiftReceipt.denominations : [];
    const cashTotal = Number(shiftReceipt.actualCash || 0);
    const cashSales = Number(shiftReceipt.cashSales || 0);
    const cashTransfer = Number(shiftReceipt.cashTransfer || 0);
    const cashTransferRows = Array.isArray(shiftReceipt.cashTransferRows) ? shiftReceipt.cashTransferRows : [];
    const expectedCash = Number(shiftReceipt.expectedCash || 0);
    const beginningCash = Number(shiftReceipt.beginningCash || 0);
    const gcashSales = Number(shiftReceipt.gcashSales || 0);
    const onlineTransferSales = Number(shiftReceipt.onlineTransferSales || 0);
    const checkSales = Number(shiftReceipt.checkSales || 0);
    const grandTotalCount = cashTotal + gcashSales + onlineTransferSales + checkSales;
    const overShort = cashTotal - expectedCash;

    const safeLogoImage = String(logoImage || '').startsWith('data:image/')
        ? String(logoImage || '')
        : '';

    const logoHtml = safeLogoImage
        ? `<div class="receipt-logo"><img src="${safeLogoImage}" alt="Receipt Logo"></div>`
        : '';

    const headerInfoHtml = `
        <div class="receipt-header">
            ${logoHtml}
            <div class="receipt-store-name">${escapeHtml(storeName)}</div>
            ${address ? `<div>${escapeHtml(address)}</div>` : ''}
            ${tin ? `<div>VAT REG TIN #: ${escapeHtml(tin)}</div>` : ''}
            ${serialNo ? `<div>SERIAL #: ${escapeHtml(serialNo)}</div>` : ''}
            ${minNo ? `<div>MIN:${escapeHtml(minNo)}</div>` : ''}
        </div>
    `;

    const denomLines = cashRows.map(row => {
        const denomText = fmt(row.denom);
        const qtyText = fmt(row.qty);
        const totalText = moneyText(row.amount);
        return escapeHtml(denomText.padEnd(12, ' ') + qtyText.padStart(10, ' ') + totalText.padStart(18, ' '));
    }).join('\n');

    const cashTransferLines = cashTransferRows.length > 0
        ? cashTransferRows.map(row => {
            let rawNote = String(row.notes || '').trim();
            let transferType = String(row.transfer_type || '').trim();

            if (!transferType && rawNote.indexOf('TRANSFER_TYPE|') === 0) {
                const parts = rawNote.split('|');
                transferType = parts[1] || 'Pick-Up';
                rawNote = parts.slice(2).join('|').trim();
            }

            transferType = transferType || 'Pick-Up';
            const noteText = rawNote || (transferType === 'Deposit' ? 'Deposit' : 'Pick-Up');
            const labelText = `${transferType}: ${noteText}`;
            const amountText = (transferType === 'Deposit' ? '+' : '-') + moneyText(row.amount || 0);
            return receiptLine(labelText, amountText, width);
        }).join('\n')
        : (cashTransfer > 0 ? receiptLine('Cash Transfer', '-' + moneyText(cashTransfer), width) : receiptLine('Cash Transfer', moneyText(0), width));

    const receiptText = `${receiptLine('Cashier:' + (posCashierName || 'Cashier'), '', width)}
${receiptLine('Terminal:' + (posBranchName || 'Store Counter 1'), '', width)}
${receiptLine('Cash Count #', String(shiftReceipt.shiftId || ''), width)}
${receiptLine('Start shift', startText ? `${startText.dateText} ${startText.timeText}` : '', width)}
${receiptLine('End shift', `${nowText.dateText} ${nowText.timeText}`, width)}
${dashLine(width)}
${escapeHtml('Cash'.padEnd(12, ' ') + 'Qty'.padStart(10, ' ') + 'Total'.padStart(18, ' '))}
${dashLine(width)}
${denomLines}
${dashLine(width)}
${receiptLine('Grand total', moneyText(cashTotal), width)}
${dashLine(width)}
${centerText('CASH SUMMARY', width)}

${receiptLine('Opening amount', moneyText(beginningCash), width)}
${cashTransferLines}
${dashLine(width)}
${receiptLine('Cash Count', moneyText(cashTotal), width)}
${receiptLine('Expected Cash', moneyText(expectedCash), width)}
${receiptLine('Cash Sales', moneyText(cashSales), width)}
${dashLine(width)}
${centerText('OTHERS', width)}
${gcashSales > 0 ? receiptLine('GCash', moneyText(gcashSales), width) + '\n' : ''}${onlineTransferSales > 0 ? receiptLine('Online Transfer', moneyText(onlineTransferSales), width) + '\n' : ''}${checkSales > 0 ? receiptLine('Check', moneyText(checkSales), width) + '\n' : ''}${dashLine(width)}
${receiptLine('Grand Total Count', moneyText(grandTotalCount), width)}
${receiptLine(overShort >= 0 ? 'Over' : 'Short', moneyText(Math.abs(overShort)), width)}
${dashLine(width)}
${centerText('SHIFT REPORT', width)}`;

    return `<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Shift Report</title>
<style>
@page { size: 80mm auto; margin: 3mm; }
* { box-sizing: border-box; }
html, body { margin: 0; padding: 0; background: #fff; color: #000; }
body { font-family: "Courier New", "Courier", monospace; font-size: 10px; }
.receipt { width: 74mm; margin: 0 auto; padding: 2mm 0; }
.receipt-header { text-align: center; font-family: "Courier New", "Courier", monospace; font-size: 10px; line-height: 1.22; margin: 0 0 2mm 0; }
.receipt-store-name { font-weight: 700; text-transform: uppercase; }
.receipt-logo { text-align: center; margin: 0 0 1mm 0; }
.receipt-logo img { max-width: 26mm; max-height: 18mm; object-fit: contain; display: inline-block; }

pre {
    margin: 0;
    white-space: pre;
    font-family: "Courier New", "Courier", monospace;
    font-size: 10px;
    line-height: 1.22;
}

.receipt-footer-note {
    display: block;
    width: 100%;
    max-width: none;
    margin: 8px 0;
    padding: 0;
    text-align: justify;
    text-align-last: center;
    text-justify: inter-word;
    white-space: normal;
    font-family: "Courier New", "Courier", monospace;
    font-size: 10px;
    line-height: 1.35;
}

@media print {
    html, body { width: 80mm; }
    .receipt { width: 74mm; }
}
</style>
</head>
<body>
<div class="receipt">
    ${headerInfoHtml}
    <pre>${receiptText}</pre>
</div>
</body>
</html>`;
}

function printShiftCloseReceipt(shiftReceipt) {
    return new Promise((resolve, reject) => {
        showReceiptPrintStatus('Printing shift report...', 'printing');

        const previousFrame = document.getElementById('posShiftPrintFrame');

        if (previousFrame) {
            previousFrame.remove();
        }

        const frame = document.createElement('iframe');
        frame.id = 'posShiftPrintFrame';
        frame.title = 'Shift report print frame';
        frame.setAttribute('aria-hidden', 'true');
        frame.style.position = 'fixed';
        frame.style.width = '1px';
        frame.style.height = '1px';
        frame.style.right = '0';
        frame.style.bottom = '0';
        frame.style.border = '0';
        frame.style.opacity = '0';
        frame.style.pointerEvents = 'none';

        let started = false;

        frame.onload = function () {
            if (started) {
                return;
            }

            started = true;

            setTimeout(() => {
                try {
                    const printWindow = frame.contentWindow;

                    if (!printWindow) {
                        throw new Error('Shift report print service is unavailable.');
                    }

                    printWindow.focus();
                    printWindow.print();

                    showReceiptPrintStatus('Shift report sent to printer.', 'done');

                    setTimeout(() => frame.remove(), 1500);
                    resolve(true);
                } catch (error) {
                    setTimeout(() => frame.remove(), 300);
                    showReceiptPrintStatus('Unable to print shift report.', 'error');
                    reject(error);
                }
            }, 350);
        };

        document.body.appendChild(frame);

        try {
            const frameDocument = frame.contentDocument || frame.contentWindow.document;
            frameDocument.open();
            frameDocument.write(buildShiftCloseReceiptHtml(shiftReceipt));
            frameDocument.close();
        } catch (error) {
            frame.remove();
            showReceiptPrintStatus('Unable to print shift report.', 'error');
            reject(error);
        }
    });
}

function updateCloseShiftCashDenomination() {
    let actualCash = 0;

    document.querySelectorAll('.cash-denom-qty').forEach(input => {
        const cleanedQty = String(input.value || '').replace(/,/g, '').replace(/\D/g, '');
        input.value = cleanedQty === '' ? '' : Number(cleanedQty).toLocaleString('en-US');

        const qty = Number(cleanedQty || 0);
        const denom = Number(input.dataset.denom || 0);
        const amount = qty * denom;
        const amountCell = document.getElementById(`cashAmount_${input.dataset.key}`);

        if (amountCell) {
            amountCell.textContent = fmt(amount);
        }

        actualCash += amount;
    });

    const expectedCash = Number(posShiftData && posShiftData.expected_cash || 0);
    const variance = actualCash - expectedCash;
    const actualInput = document.getElementById('shiftActualCash');
    const varianceEl = document.getElementById('shiftVarianceAmount');

    if (actualInput) {
        actualInput.value = fmt(actualCash);
    }

    const actualText = document.getElementById('shiftActualCashText');
    if (actualText) {
        actualText.textContent = `₱${fmt(actualCash)}`;
    }

    if (varianceEl) {
        varianceEl.textContent = `₱${fmt(variance)}`;
        varianceEl.style.color = variance === 0 ? '#15803d' : '#b91c1c';
    }
}

function setupCloseShiftCashDenomination() {
    document.querySelectorAll('.cash-denom-qty').forEach(input => {
        input.addEventListener('input', updateCloseShiftCashDenomination);
    });

    updateCloseShiftCashDenomination();

    const firstInput = document.querySelector('.cash-denom-qty');
    if (firstInput) {
        firstInput.focus();
    }
}

async function closeShiftAndLogout() {
    await refreshShiftStatus();

    if (!posShiftOpen || !posShiftData) {
        window.location.href = '../logout.php';
        return;
    }

    const expectedCash = Number(posShiftData.expected_cash || 0);
    const result = await Swal.fire({
        title: 'Close POS Shift',
        html: `
            <div class="pos-modal-panel close-shift-panel">
                <div class="pos-close-shift-static">
                    <div class="pos-report-grid" style="grid-template-columns:repeat(4,1fr);gap:6px;">
                        <div class="pos-report-card"><span>Beginning Cash</span><b>₱${fmt(posShiftData.beginning_cash || 0)}</b></div>
                        <div class="pos-report-card"><span>Cash Sales</span><b>₱${fmt(posShiftData.cash_sales || 0)}</b></div>
                        <div class="pos-report-card"><span>Check Payments</span><b>₱${fmt(posShiftData.check_sales || 0)}</b></div>
                        <div class="pos-report-card"><span>Expected Cash</span><b>₱${fmt(expectedCash)}</b></div>
                    </div>

                    <div class="pos-currency-line">
                        <span class="pos-currency-check"><i class="fa-solid fa-check"></i></span>
                        <span>Currency: Philippine Peso (₱)</span>
                    </div>
                </div>

                <div class="pos-close-shift-scroll">
                    <div class="pos-cash-denomination-wrap">
                        <div class="pos-cash-denomination-title">
                            <span>Cash Count Denomination</span>
                            <small>Enter quantity for each denomination</small>
                        </div>
                        <div class="pos-cash-grid">
                            <table class="pos-cash-table">
                                <thead>
                                    <tr><th>Paper Bills</th><th>Qty</th><th>Amount</th></tr>
                                </thead>
                                <tbody>${renderCashBreakdownRows('bills', shiftBillDenominations)}</tbody>
                            </table>
                            <table class="pos-cash-table">
                                <thead>
                                    <tr><th>Coins</th><th>Qty</th><th>Amount</th></tr>
                                </thead>
                                <tbody>${renderCashBreakdownRows('coins', shiftCoinDenominations)}</tbody>
                            </table>
                        </div>
                        <input id="shiftActualCash" type="hidden" value="0.00">
                    </div>

                    <div class="pos-report-grid" style="grid-template-columns:repeat(3,1fr);gap:6px;margin-top:6px;">
                        <div class="pos-report-card"><span>GCash</span><b>₱${fmt(posShiftData.gcash_sales || 0)}</b></div>
                        <div class="pos-report-card"><span>Online Transfer</span><b>₱${fmt(posShiftData.online_transfer_sales || 0)}</b></div>
                        <div class="pos-report-card"><span>Check</span><b>₱${fmt(posShiftData.check_sales || 0)}</b></div>
                    </div>

                    <textarea id="shiftCloseNotes" class="swal2-textarea" placeholder="Notes / reason for variance" style="margin:6px 0 0;width:100%;height:34px;min-height:34px;resize:none;"></textarea>
                </div>
            </div>
        `,
        width: 900,
        customClass: {
            popup: 'close-shift-swal'
        },
        showCancelButton: true,
        confirmButtonText: 'Close Shift & Logout',
        cancelButtonText: 'Cancel',
        didOpen: () => {
            setupCloseShiftCashDenomination();

            const observer = new MutationObserver(() => {
                const actualInput = document.getElementById('shiftActualCash');
                const actualText = document.getElementById('shiftActualCashText');

                if (actualInput && actualText) {
                    actualText.textContent = `₱${actualInput.value || '0.00'}`;
                }
            });

            const actualInput = document.getElementById('shiftActualCash');
            if (actualInput) {
                observer.observe(actualInput, { attributes: true, attributeFilter: ['value'] });
            }
        },
        preConfirm: () => {
            updateCloseShiftCashDenomination();

            const denominationDetails = getCloseShiftDenominationDetails();
            const actualCash = Number(denominationDetails.total || 0);
            const notes = document.getElementById('shiftCloseNotes').value || '';
            const denominationNote = buildCloseShiftDenominationNote();
            const finalNotes = notes.trim() ? `${denominationNote} | Notes: ${notes.trim()}` : denominationNote;

            if (!Number.isFinite(actualCash) || actualCash < 0) {
                Swal.showValidationMessage('Enter a valid actual cash amount.');
                return false;
            }

            return { actualCash, notes: finalNotes, denominations: denominationDetails.rows };
        }
    });

    if (!result.isConfirmed) {
        return;
    }

    const data = await api({
        action: 'close_shift',
        actual_cash: result.value.actualCash,
        notes: result.value.notes
    });

    if (!data.success) {
        Swal.fire('Unable to close shift', data.message || 'Please try again.', 'error');
        return;
    }

    const variance = Number((data.shift && data.shift.variance) || 0);

    try {
        await printShiftCloseReceipt({
            shiftId: posShiftData.shift_id || '',
            openedAt: posShiftData.opened_at || '',
            beginningCash: posShiftData.beginning_cash || 0,
            cashSales: posShiftData.cash_sales || 0,
            cashTransfer: posShiftData.cash_transfer || 0,
            cashTransferRows: posShiftData.cash_transfer_rows || [],
            expectedCash: data.shift.expected_cash || expectedCash,
            actualCash: data.shift.actual_cash || result.value.actualCash,
            gcashSales: posShiftData.gcash_sales || 0,
            onlineTransferSales: posShiftData.online_transfer_sales || 0,
            checkSales: posShiftData.check_sales || 0,
            denominations: result.value.denominations || []
        });
    } catch (printError) {
        console.error('Shift report printing failed:', printError);
    }

    await Swal.fire({
        title: 'Shift Closed',
        html: `
            <div class="pos-modal-panel">
                <div class="pos-report-grid" style="grid-template-columns:1fr 1fr;">
                    <div class="pos-report-card"><span>Expected Cash</span><b>₱${fmt(data.shift.expected_cash || 0)}</b></div>
                    <div class="pos-report-card"><span>Actual Cash</span><b>₱${fmt(data.shift.actual_cash || 0)}</b></div>
                    <div class="pos-report-card" style="grid-column:span 2;"><span>Variance</span><b style="color:${variance === 0 ? '#15803d' : '#b91c1c'};">₱${fmt(variance)}</b></div>
                </div>
            </div>
        `,
        confirmButtonText: 'Logout'
    });

    window.location.href = '../logout.php';
}

function positionSuggestDropdown() {
    const searchArea = document.querySelector('.searcharea');
    const searchRow = document.querySelector('.searchrow');
    const box = document.getElementById('suggest');

    if (!searchArea || !searchRow || !box) {
        return;
    }

    const areaRect = searchArea.getBoundingClientRect();
    const rowRect = searchRow.getBoundingClientRect();

    const preferredWidth = Math.max(areaRect.width, 520);
    const availableRight = window.innerWidth - 10;
    const left = Math.max(10, Math.min(areaRect.right - preferredWidth, availableRight - preferredWidth));
    const width = Math.min(preferredWidth, window.innerWidth - 20);
    const maxHeight = Math.max(180, window.innerHeight - rowRect.bottom - 18);

    box.style.left = left + 'px';
    box.style.top = (rowRect.bottom + 2) + 'px';
    box.style.width = width + 'px';
    box.style.maxHeight = Math.min(420, maxHeight) + 'px';
}

function hideSuggestDropdown() {
    const box = document.getElementById('suggest');

    suggestIndex = -1;

    if (box) {
        box.style.display = 'none';
    }

    clearSuggestActive();
}

function setScanStatus(message, type = 'ready') {
    const status = document.getElementById('scanStatus');
    const input = document.getElementById('productSearch');

    if (status) {
        status.textContent = message || 'Ready to scan';
    }

    if (input) {
        input.classList.remove('scan-ok', 'scan-error');

        if (type === 'ok') {
            input.classList.add('scan-ok');
        }

        if (type === 'error') {
            input.classList.add('scan-error');
        }
    }

    clearTimeout(window.__scanStatusTimer);
    window.__scanStatusTimer = setTimeout(() => {
        if (status) {
            status.textContent = 'Ready to scan';
        }

        if (input) {
            input.classList.remove('scan-ok', 'scan-error');
        }
    }, 1600);
}

function focusBarcodeInput() {
    const input = document.getElementById('productSearch');
    const tenderModalOpen = document.getElementById('tenderModal') && document.getElementById('tenderModal').classList.contains('show');
    const isSweetAlertOpen = document.body.classList.contains('swal2-shown');

    if (input && !tenderModalOpen && !isSweetAlertOpen) {
        input.focus();
    }
}

function parseQtyAndBarcode(rawValue) {
    const raw = String(rawValue || '').trim();
    let qty = 1;
    let barcode = raw;

    // New POS format: QTY*BARCODE, example: 2*4806501020806
    // Backward-compatible din sa dating QTY*BARCODE para hindi masira ang old habit habang testing.
    const match = raw.match(/^([0-9]+(?:\.[0-9]+)?)\s*[\*@]\s*(.+)$/);

    if (match) {
        qty = Number(match[1]);
        barcode = match[2].trim();
    }

    if (!Number.isFinite(qty) || qty <= 0) {
        qty = 1;
    }

    return { qty, barcode };
}

function hasQtyBarcodePrefix(value) {
    return /^([0-9]+(?:\.[0-9]+)?)\s*[\*@]/.test(String(value || '').trim());
}

async function scanBarcodeFromInput() {
    if (!requireOpenShift()) {
        return;
    }

    if (!requireNewCustomerClear()) {
        return;
    }

    const input = document.getElementById('productSearch');
    const parsed = parseQtyAndBarcode(input.value);
    const barcode = parsed.barcode;
    const scanQty = parsed.qty;
    if (barcode === '') { hideSuggestDropdown(); setScanStatus('Ready to scan. Format: QTY*BARCODE'); return; }
    try {
        const data = await api({ action: 'scan_barcode', barcode: barcode, price_level: currentPriceLevel });
        if (data.success && data.product) {
            if (Number(data.product.stock_qty || 0) <= 0) { setScanStatus('No stock available', 'error'); Swal.fire('No stock available', data.product.item_name || barcode, 'warning'); input.value = ''; focusBarcodeInput(); return; }
            addProduct(data.product, true, scanQty);
            setScanStatus('Scanned ' + fmt(scanQty) + ' x ' + (data.product.item_name || barcode), 'ok');
            return;
        }
        setScanStatus('Barcode not found, showing search results', 'error');
        await searchProduct();
    } catch (error) { console.warn('Barcode scan failed.', error); setScanStatus('Scan failed. Please try again.', 'error'); }
}

async function searchProduct() {
    if (!requireOpenShift()) {
        return;
    }

    if (!requireNewCustomerClear()) {
        return;
    }

    const rawTerm = document.getElementById('productSearch').value.trim();
    const parsedTerm = parseQtyAndBarcode(rawTerm);
    const term = hasQtyBarcodePrefix(rawTerm) ? parsedTerm.barcode : rawTerm;
    const box = document.getElementById('suggest');

    suggestIndex = -1;
    box.innerHTML = '';

    let products = [];

    try {
        const data = await api({
            action: 'search_product',
            term: term,
            price_level: currentPriceLevel
        });

        if (data.products && data.products.length > 0) {
            products = data.products;
        }
    } catch (error) {
        console.warn('Database product search failed.', error);
    }

    if (products.length === 0) {
        box.innerHTML = '<div class="suggest-empty">No product found for this branch.</div>';
        positionSuggestDropdown();
        box.style.display = 'block';
        return;
    }

    box.innerHTML = `
        <div class="suggest-header">
            <span>Description</span>
            <span style="text-align:right;">Price</span>
            <span style="text-align:right;">Stock</span>
        </div>
    `;

    products.forEach(p => {
        const uoms = normalizeProductUoms(p);

        uoms.forEach(uom => {
            const div = document.createElement('div');
            const productForUom = {
                ...p,
                selected_uom_key: uom.uom_key,
                uom_id: Number(uom.uom_id || 0),
                uom_name: uom.uom_name || uom.uom_initial || p.unit_type || '',
                uom_initial: uom.uom_initial || uom.uom_name || p.uom_initial || '',
                unit_price: getUomPriceForLevel(uom, currentPriceLevel),
                stock_qty: Number(uom.stock_qty || 0),
                conversion_qty: Math.max(1, Number(uom.conversion_qty || 1))
            };

            div.className = 'suggest-item';
            div.innerHTML = `
                <div class="suggest-main">
                    <span class="suggest-name">${escapeHtml(p.item_name || p.description || '')}</span>
                    <span class="suggest-code">${escapeHtml(uom.barcode || p.barcode || p.item_code || '')}</span>
                    <span class="suggest-uoms">UoM: ${escapeHtml(uom.uom_initial || uom.uom_name || 'UoM')}</span>
                </div>
                <div class="suggest-price">₱${fmt(getUomPriceForLevel(uom, currentPriceLevel))}</div>
                <div class="suggest-stock">${fmt(uom.stock_qty)} ${escapeHtml(uom.uom_initial || '')}</div>
            `;

            div.addEventListener('mouseenter', () => {
                const items = getSuggestItems();
                const hoverIndex = items.indexOf(div);

                if (hoverIndex >= 0) {
                    setSuggestActive(hoverIndex);
                }
            });

            div.onclick = () => {
                const currentRaw = document.getElementById('productSearch').value.trim();
                const currentParsed = parseQtyAndBarcode(currentRaw);
                const selectedQty = hasQtyBarcodePrefix(currentRaw) ? currentParsed.qty : 1;
                addProduct(productForUom, false, selectedQty);
            };

            box.appendChild(div);
        });
    });

    positionSuggestDropdown();
    box.style.display = 'block';
}

function addProduct(p, fromScan = false, addQty = 1) {
    if (!requireNewCustomerClear()) {
        return;
    }

    const uoms = normalizeProductUoms(p);
    const selectedUom = getDefaultProductUom(p);
    const stockQty = Number(selectedUom.stock_qty || 0);
    const qtyToAdd = Math.max(0.01, Number(addQty || 1));
    const existing = cart.find(i => Number(i.item_id) === Number(p.item_id) && String(i.uom_key || 'default') === String(selectedUom.uom_key || 'default'));

    if (stockQty <= 0) {
        setScanStatus('No stock available', 'error');
        Swal.fire('No stock available', `${escapeHtml(p.item_name || 'Selected item')} - ${escapeHtml(selectedUom.uom_initial || selectedUom.uom_name || '')}`, 'warning');
        document.getElementById('productSearch').value = '';
        focusBarcodeInput();
        return;
    }

    if (existing) {
        if (Number(existing.qty || 0) + qtyToAdd > stockQty) {
            setScanStatus('Insufficient stock', 'error');
            Swal.fire('Insufficient stock', `${escapeHtml(existing.name)} has only ${fmt(stockQty)} ${escapeHtml(existing.uom_initial || '')} available.`, 'warning');
            document.getElementById('productSearch').value = '';
            focusBarcodeInput();
            return;
        }

        existing.qty = Number(existing.qty || 0) + qtyToAdd;
        existing.stock_qty = stockQty;
        applyCartUom(existing, selectedUom);
        activeIndex = cart.indexOf(existing);
    } else {
        if (qtyToAdd > stockQty) {
            setScanStatus('Insufficient stock', 'error');
            Swal.fire('Insufficient stock', `${escapeHtml(p.item_name || 'Selected item')} has only ${fmt(stockQty)} ${escapeHtml(selectedUom.uom_initial || '')} available.`, 'warning');
            document.getElementById('productSearch').value = '';
            focusBarcodeInput();
            return;
        }

        const cartItem = {
            item_id: Number(p.item_id),
            name: p.item_name,
            price: getUomPriceForLevel(selectedUom, currentPriceLevel),
            qty: qtyToAdd,
            stock_qty: stockQty,
            barcode: p.barcode || '',
            item_code: p.item_code || '',
            unit_type: p.unit_type || '',
            uoms: uoms,
            uom_key: selectedUom.uom_key || 'default',
            uom_id: Number(selectedUom.uom_id || 0),
            uom_name: selectedUom.uom_name || selectedUom.uom_initial || p.unit_type || '',
            uom_initial: selectedUom.uom_initial || selectedUom.uom_name || p.uom_initial || '',
            conversion_qty: Math.max(1, Number(selectedUom.conversion_qty || 1)),
            price_level: currentPriceLevel,
            points_eligible: Number(p.points_eligible ?? 1),
            discount_type: 'none',
            discount_value: 0,
            discount_amount: 0,
            discount_label: ''
        };

        cart.push(cartItem);
        activeIndex = cart.length - 1;
    }

    hideSuggestDropdown();
    document.getElementById('productSearch').value = '';
    renderCart();
    scrollActiveRowIntoView();
    focusBarcodeInput();
}

function clearCart() {
    if (saleAwaitingNewCustomer || completedSaleSummary) {
        startNewCustomer(true);
        return;
    }

    if (cart.length === 0) {
        return;
    }

    Swal.fire({
        title: 'Cancel current sale?',
        icon: 'warning',
        showCancelButton: true
    }).then(r => {
        if (r.isConfirmed) {
            startNewCustomer(true);
        }
    });
}

function selected() {
    if (activeIndex < 0 || !cart[activeIndex]) {
        Swal.fire('Select item first', '', 'info');
        return null;
    }

    return cart[activeIndex];
}

function changeQty() {
    const it = selected();

    if (!it) {
        return;
    }

    Swal.fire({
        title: 'Change Quantity',
        width: 640,
        input: 'number',
        inputValue: it.qty,
        inputAttributes: {
            min: 0.01,
            step: 0.01
        },
        showCancelButton: true
    }).then(r => {
        if (r.isConfirmed && Number(r.value) > 0) {
            it.qty = Number(r.value);
            renderCart();
        }
    });
}

async function confirmCashierPassword(actionTitle, extraPayload = {}) {
    const approvalScope = extraPayload.approval_scope || 'cashier';
    const isBranchAdminApproval = approvalScope === 'branch_admin';
    const requestPayload = { ...extraPayload };
    delete requestPayload.approval_scope;

    const result = await Swal.fire({
        title: actionTitle,
        html: `
            <div style="text-align:left">
                <label style="display:block;font-weight: 600;margin-bottom:6px;color:#0f172a;">${isBranchAdminApproval ? 'Enter Branch Admin Password' : 'Enter Password'}</label>
                <input id="cashierApprovalPassword" type="password" class="swal2-input" style="width:100%;margin:0;" autocomplete="current-password" placeholder="${isBranchAdminApproval ? 'Branch Admin Password' : 'Password'}">
                ${isBranchAdminApproval ? '<div style="margin-top:8px;font-size:12px;color:#64748b;">Void approval requires the Branch Admin password.</div>' : ''}
            </div>
        `,
        showCancelButton: true,
        confirmButtonText: 'Confirm',
        didOpen: () => {
            const input = document.getElementById('cashierApprovalPassword');
            if (input) {
                input.focus();
                input.addEventListener('keydown', e => {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        Swal.clickConfirm();
                    }
                });
            }
        },
        preConfirm: async () => {
            const password = document.getElementById('cashierApprovalPassword').value || '';

            if (!password) {
                Swal.showValidationMessage(isBranchAdminApproval ? 'Enter Branch Admin password.' : 'Enter your password.');
                return false;
            }

            try {
                const data = await api({
                    action: isBranchAdminApproval ? 'verify_branch_admin_password' : 'verify_cashier_password',
                    password,
                    ...requestPayload
                });

                if (!data.success) {
                    Swal.showValidationMessage(data.message || (isBranchAdminApproval ? 'Incorrect Branch Admin password.' : 'Incorrect password.'));
                    return false;
                }

                return { password };
            } catch (error) {
                Swal.showValidationMessage('Unable to verify password.');
                return false;
            }
        }
    });

    return result.isConfirmed ? result.value : false;
}

async function editPrice() {
    const it = selected();

    if (!it) {
        return;
    }

    const approval = await confirmCashierPassword('EDIT PRICE', {
        action_type: 'EDIT_PRICE_APPROVAL',
        item_id: it.item_id,
        item_name: it.name,
        quantity: Number(it.qty || 0),
        amount: Number(it.price || 0),
        details: 'Edit price approval'
    });

    if (!approval) {
        return;
    }

    Swal.fire({
        title: 'Edit Price',
        input: 'number',
        inputValue: it.price,
        inputAttributes: {
            min: 0,
            step: 0.01
        },
        showCancelButton: true
    }).then(r => {
        if (r.isConfirmed && Number(r.value) >= 0) {
            it.price = Number(r.value);
            renderCart();
        }
    });
}

async function voidSelectedItem() {
    const it = selected();

    if (!it) {
        return;
    }

    const approval = await confirmCashierPassword('VOID ITEM', {
        approval_scope: 'branch_admin',
        action_type: 'VOID_ITEM',
        item_id: it.item_id,
        item_name: it.name,
        quantity: Number(it.qty || 0),
        amount: Math.max(0, getLineGross(it) - getItemDiscount(it)),
        details: 'Cart item removed before sale save'
    });

    if (!approval) {
        return;
    }

    const removedName = it.name;
    cart.splice(activeIndex, 1);

    if (cart.length === 0) {
        activeIndex = -1;
    } else if (activeIndex > cart.length - 1) {
        activeIndex = cart.length - 1;
    }

    renderCart();
    Swal.fire('Item Removed Successfully', removedName, 'success');
    focusBarcodeInput();
}

function editItem() {
    changeQty();
}

async function openDiscount() {
    if (cart.length === 0) {
        Swal.fire('No item in cart', '', 'info');
        return;
    }

    const hasSelected = activeIndex >= 0 && cart[activeIndex];
    const selectedName = hasSelected ? cart[activeIndex].name : 'Selected Item';

    const result = await Swal.fire({
        title: '',
        width: 900,
        padding: 0,
        background: 'transparent',
        color: '#ffffff',
        showConfirmButton: false,
        showCancelButton: false,
        allowOutsideClick: false,
        html: `
            <style>
                .swal2-popup {
                    box-shadow: none !important;
                    border: 0 !important;
                }

                .pos-discount-pad {
                    width: 100%;
                    padding: 0;
                    background: transparent;
                    color: #ffffff;
                    border: 0;
                    border-radius: 0;
                    box-shadow: none;
                    font-family: Inter, Arial, sans-serif;
                }

                .pos-discount-title {
                    font-size: 30px;
                    font-weight: 700;
                    margin: 0 0 18px;
                    color: #ffffff;
                    text-align: left;
                    letter-spacing: .3px;
                    text-shadow: 0 2px 8px rgba(0,0,0,.35);
                }

                .pos-discount-grid {
                    display: grid;
                    grid-template-columns: minmax(360px, 1fr) 360px;
                    gap: 30px;
                    align-items: stretch;
                }

                .pos-discount-left {
                    background: linear-gradient(135deg, rgba(5,42,71,.98), rgba(5,59,51,.96), rgba(4,120,87,.92));
                    border: 1px solid rgba(68,211,78,.32);
                    border-radius: 18px;
                    padding: 20px;
                    box-shadow: 0 22px 48px rgba(0,0,0,.34), inset 0 1px rgba(255,255,255,.14);
                }

                .pos-discount-left label {
                    display: block;
                    font-size: 13px;
                    font-weight: 700;
                    color: #eaffef;
                    margin: 0 0 8px;
                    text-align: left;
                }

                .pos-discount-left select,
                .pos-discount-left input {
                    width: 100%;
                    height: 48px;
                    border: 1px solid rgba(255,255,255,.70);
                    background: #ffffff;
                    color: #052A47;
                    border-radius: 12px;
                    padding: 0 13px;
                    font-size: 15px;
                    font-weight: 600;
                    margin: 0 0 14px;
                    box-shadow: inset 0 1px 4px rgba(15,23,42,.12), 0 8px 18px rgba(0,0,0,.16);
                }

                .pos-discount-left select:focus,
                .pos-discount-left input:focus {
                    outline: none;
                    border-color: #44D34E;
                    box-shadow: 0 0 0 4px rgba(68,211,78,.22), inset 0 1px 4px rgba(15,23,42,.12);
                }

                .pos-discount-value {
                    text-align: right;
                    font-size: 42px !important;
                    height: 78px !important;
                    background: linear-gradient(180deg, #ffffff, #f1f5f9) !important;
                    color: #052A47 !important;
                    font-weight: 700 !important;
                    letter-spacing: .5px;
                }

                .pos-discount-help {
                    display: block;
                    min-height: 40px;
                    font-size: 12px;
                    line-height: 1.35;
                    color: #dfffee;
                    text-align: left;
                    opacity: .95;
                    margin: 2px 0 14px;
                }

                .pos-discount-actions {
                    display: grid;
                    grid-template-columns: 1fr 1fr;
                    gap: 12px;
                    margin-top: 14px;
                }

                .pos-discount-action {
                    height: 70px;
                    border: 1px solid rgba(255,255,255,.18);
                    border-radius: 14px;
                    color: #ffffff;
                    background: linear-gradient(160deg, #16a34a, #047857);
                    font-weight: 700;
                    font-size: 18px;
                    cursor: pointer;
                    box-shadow: inset 0 1px rgba(255,255,255,.22), 0 10px 20px rgba(0,0,0,.26);
                    line-height: 1.15;
                }

                .pos-discount-action small {
                    display: block;
                    font-size: 10px;
                    opacity: .95;
                    margin-bottom: 4px;
                }

                #discountCancelBtn {
                    background: linear-gradient(160deg, #64748b, #334155);
                }

                .pos-numpad {
                    display: grid;
                    grid-template-columns: repeat(3, 1fr);
                    gap: 12px;
                    background: linear-gradient(135deg, rgba(5,42,71,.98), rgba(5,59,51,.96), rgba(4,120,87,.92));
                    border: 1px solid rgba(68,211,78,.32);
                    border-radius: 18px;
                    padding: 20px;
                    box-shadow: 0 22px 48px rgba(0,0,0,.34), inset 0 1px rgba(255,255,255,.14);
                }

                .pos-numpad button {
                    height: 82px;
                    border: 1px solid rgba(255,255,255,.18);
                    border-radius: 16px;
                    color: #ffffff;
                    background: linear-gradient(160deg, #06365c, #052A47);
                    font-size: 32px;
                    font-weight: 700;
                    cursor: pointer;
                    box-shadow: inset 0 1px rgba(255,255,255,.20), 0 10px 20px rgba(0,0,0,.25);
                }

                .pos-numpad button[data-key="del"] {
                    background: linear-gradient(160deg, #ef6b58, #b83228);
                    font-size: 24px;
                }

                .pos-numpad button:hover,
                .pos-discount-action:hover {
                    transform: translateY(-1px);
                    filter: brightness(1.08);
                }

                .pos-numpad button:active,
                .pos-discount-action:active {
                    transform: translateY(1px);
                    filter: brightness(.95);
                }

                @media (max-width: 760px) {
                    .pos-discount-grid {
                        grid-template-columns: 1fr;
                    }

                    .pos-numpad button {
                        height: 72px;
                    }
                }
            </style>

            <div class="pos-discount-pad">
                <div class="pos-discount-title">Enter discount</div>

                <div class="pos-discount-grid">
                    <div class="pos-discount-left">
                        <label>Discount Type</label>
                        <select id="discountType">
                            <option value="amount">Amount</option>
                            <option value="percentage">Percentage</option>
                            <option value="senior">Senior Citizen 20%</option>
                            <option value="pwd">PWD 20%</option>
                            <option value="employee">Employee 10%</option>
                        </select>

                        <label>Apply To</label>
                        <select id="discountScope">
                            <option value="order">Whole Transaction</option>
                            <option value="item" ${hasSelected ? '' : 'disabled'}>Selected Item: ${escapeHtml(selectedName)}</option>
                            <option value="clear_item" ${hasSelected ? '' : 'disabled'}>Clear Selected Item Discount</option>
                            <option value="clear_order">Clear Transaction Discount</option>
                            <option value="clear_all">Clear All Discounts</option>
                        </select>

                        <label>Value</label>
                        <input id="discountValue" class="pos-discount-value" type="text" inputmode="decimal" autocomplete="off" value="">

                        <small id="discountHelp" class="pos-discount-help">
                            Senior, PWD, and Employee discounts are applied to the whole transaction only.
                        </small>

                        <div class="pos-discount-actions">
                            <button type="button" class="pos-discount-action" id="discountOkBtn"><small>[Enter]</small>Ok</button>
                            <button type="button" class="pos-discount-action" id="discountCancelBtn"><small>[Esc]</small>Cancel</button>
                        </div>
                    </div>

                    <div class="pos-numpad" id="discountNumpad">
                        <button type="button" data-key="7">7</button>
                        <button type="button" data-key="8">8</button>
                        <button type="button" data-key="9">9</button>
                        <button type="button" data-key="4">4</button>
                        <button type="button" data-key="5">5</button>
                        <button type="button" data-key="6">6</button>
                        <button type="button" data-key="1">1</button>
                        <button type="button" data-key="2">2</button>
                        <button type="button" data-key="3">3</button>
                        <button type="button" data-key="0">0</button>
                        <button type="button" data-key=".">.</button>
                        <button type="button" data-key="del">Del</button>
                    </div>
                </div>
            </div>
        `,
        didOpen: () => {
            const popup = Swal.getPopup();
            const scope = document.getElementById('discountScope');
            const type = document.getElementById('discountType');
            const value = document.getElementById('discountValue');
            const help = document.getElementById('discountHelp');
            const itemOption = scope.querySelector('option[value="item"]');
            const okBtn = document.getElementById('discountOkBtn');
            const cancelBtn = document.getElementById('discountCancelBtn');
            const numpad = document.getElementById('discountNumpad');

            const appendValue = (key) => {
                if (value.disabled || value.readOnly) {
                    return;
                }

                if (key === 'del') {
                    value.value = value.value.slice(0, -1);
                    value.focus();
                    return;
                }

                if (key === '.' && value.value.includes('.')) {
                    value.focus();
                    return;
                }

                value.value = (value.value + key).replace(/^0+(?=\d)/, '');
                value.focus();
            };

            const syncValue = () => {
                const fixedTransactionDiscount = ['senior', 'pwd', 'employee'].includes(type.value);
                const clearMode = scope.value.startsWith('clear');

                if (fixedTransactionDiscount) {
                    scope.value = 'order';

                    if (itemOption) {
                        itemOption.disabled = true;
                    }

                    value.value = type.value === 'employee' ? '10' : '20';
                    value.readOnly = true;
                    help.textContent = 'Senior, PWD, and Employee discounts are whole transaction discounts only.';
                } else {
                    if (itemOption) {
                        itemOption.disabled = !hasSelected;
                    }

                    value.readOnly = false;
                    if (value.value === '20' || value.value === '10') {
                        value.value = '';
                    }
                    help.textContent = 'Amount and Percentage can be applied to selected item or whole transaction.';
                }

                type.disabled = clearMode;
                value.disabled = clearMode;
            };

            const confirmDiscount = () => {
                let finalScope = scope.value;
                const finalType = type.value;
                const finalValue = Number(value.value || 0);

                if (['senior', 'pwd', 'employee'].includes(finalType)) {
                    finalScope = 'order';
                }

                if (!finalScope.startsWith('clear') && finalValue <= 0) {
                    Swal.showValidationMessage('Enter a valid discount value.');
                    return;
                }

                Swal.clickConfirm();
            };

            type.addEventListener('change', syncValue);
            scope.addEventListener('change', syncValue);

            numpad.addEventListener('click', (event) => {
                const btn = event.target.closest('button[data-key]');
                if (!btn) {
                    return;
                }
                appendValue(btn.dataset.key);
            });

            okBtn.addEventListener('click', confirmDiscount);
            cancelBtn.addEventListener('click', () => Swal.clickCancel());

            popup.addEventListener('keydown', (event) => {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    confirmDiscount();
                }

                if (event.key === 'Escape') {
                    event.preventDefault();
                    Swal.clickCancel();
                }
            });

            value.focus();
            syncValue();
        },
        preConfirm: () => {
            let scope = document.getElementById('discountScope').value;
            const type = document.getElementById('discountType').value;
            const value = Number(document.getElementById('discountValue').value || 0);

            if (['senior', 'pwd', 'employee'].includes(type)) {
                scope = 'order';
            }

            if (!scope.startsWith('clear') && value <= 0) {
                Swal.showValidationMessage('Enter a valid discount value.');
                return false;
            }

            return { scope, type, value };
        }
    });

    if (!result.isConfirmed || !result.value) {
        return;
    }

    const { scope, type, value } = result.value;

    if (scope === 'clear_item' && hasSelected) {
        cart[activeIndex].discount_type = 'none';
        cart[activeIndex].discount_value = 0;
        cart[activeIndex].discount_amount = 0;
        cart[activeIndex].discount_label = '';
        renderCart();
        return;
    }

    if (scope === 'clear_order') {
        orderDiscount = { type: 'none', value: 0, amount: 0, label: '' };
        renderCart();
        return;
    }

    if (scope === 'clear_all') {
        cart.forEach(item => {
            item.discount_type = 'none';
            item.discount_value = 0;
            item.discount_amount = 0;
            item.discount_label = '';
        });
        orderDiscount = { type: 'none', value: 0, amount: 0, label: '' };
        renderCart();
        return;
    }

    const labelMap = {
        amount: 'Amount Discount',
        percentage: value + '% Discount',
        senior: 'Senior Citizen 20%',
        pwd: 'PWD 20%',
        employee: 'Employee 10%'
    };

    if (scope === 'order' || ['senior', 'pwd', 'employee'].includes(type)) {
        orderDiscount = {
            type,
            value,
            amount: 0,
            label: labelMap[type] || 'Transaction Discount'
        };
        renderCart();
        return;
    }

    if (scope === 'item' && hasSelected) {
        const item = cart[activeIndex];
        const gross = getLineGross(item);
        const discountAmount = type === 'amount'
            ? Math.min(gross, value)
            : Math.min(gross, gross * (value / 100));

        item.discount_type = type;
        item.discount_value = value;
        item.discount_amount = discountAmount;
        item.discount_label = labelMap[type] || 'Discount';

        renderCart();
    }
}

function buildSaleItemsForSave() {
    const subtotalAfterItemDiscount = getSubtotalAfterItemDiscount();
    const orderDisc = getOrderDiscountAmount();
    let allocated = 0;

    return cart.map((item, index) => {
        const row = { ...item };
        const itemNet = Math.max(0, getLineGross(item) - getItemDiscount(item));
        let orderShare = 0;

        if (orderDisc > 0 && subtotalAfterItemDiscount > 0) {
            if (index === cart.length - 1) {
                orderShare = Math.max(0, orderDisc - allocated);
            } else {
                orderShare = Math.round((orderDisc * (itemNet / subtotalAfterItemDiscount)) * 100) / 100;
                allocated += orderShare;
            }
        }

        row.discount_amount = Math.min(getLineGross(item), getItemDiscount(item) + orderShare);
        row.discount_type = item.discount_type || 'none';
        row.discount_value = Number(item.discount_value || 0);
        row.discount_label = item.discount_label || '';

        if (orderShare > 0) {
            row.discount_label = row.discount_label
                ? row.discount_label + ' + ' + getOrderDiscountLabel()
                : getOrderDiscountLabel();
        }

        return row;
    });
}

function totalDue() {
    return Math.max(0, getOrderGross() - getTotalDiscountAmount());
}

function totalDueAfterPoints() {
    return Math.max(0, totalDue() - Number(selectedPointsToRedeem || 0));
}

function updateAvailablePointsDisplay() {
    const text = document.getElementById('availablePointsText');
    const input = document.getElementById('pointsRedeemInput');

    if (text) {
        text.textContent = selectedCustomerId > 0
            ? `Available: ${fmt(selectedCustomerPoints)} pts (${escapeHtml(selectedCustomerCode || 'Member')})`
            : 'Select customer first';
    }

    if (input) {
        input.disabled = !(selectedCustomerId > 0);
    }
}

function handlePointsRedeemInput() {
    const input = document.getElementById('pointsRedeemInput');
    if (!input) {
        return;
    }

    input.value = formatTenderAmountValue(input.value);
    const requested = parseMoneyInputValue(input.value);
    const maxRedeem = Math.min(Number(selectedCustomerPoints || 0), totalDue());
    selectedPointsToRedeem = Math.max(0, Math.min(requested, maxRedeem));

    if (requested > maxRedeem) {
        input.value = formatTenderAmountValue(selectedPointsToRedeem);
    }

    const dueInput = document.getElementById('tenderDue');
    if (dueInput) {
        dueInput.value = fmt(totalDueAfterPoints());
    }

    updateTenderChange();
}

function getVatableSales() {
    if (!posVatRegistered) {
        return 0;
    }

    return totalDue() / (1 + Number(posVatRate || 0));
}

function getVatAmount() {
    if (!posVatRegistered) {
        return 0;
    }

    return totalDue() - getVatableSales();
}


function moneyText(value) {
    return fmt(value);
}

function receiptPlain(value) {
    return String(value ?? '').replace(/\s+/g, ' ').trim();
}

function receiptWrapText(value, width = 48) {
    const text = receiptPlain(value);
    const lines = [];

    if (!text) {
        return [''];
    }

    let remaining = text;

    while (remaining.length > width) {
        let cutAt = remaining.lastIndexOf(' ', width);

        if (cutAt < Math.floor(width * 0.55)) {
            cutAt = width;
        }

        lines.push(remaining.substring(0, cutAt).trimEnd());
        remaining = remaining.substring(cutAt).trimStart();
    }

    if (remaining !== '') {
        lines.push(remaining);
    }

    return lines;
}

function receiptLine(left, right, width = 48) {
    const l = receiptPlain(left);
    const r = receiptPlain(right);

    if (!r) {
        return escapeHtml(l.substring(0, width));
    }

    if ((l.length + r.length + 1) > width) {
        const leftLines = receiptWrapText(l, width);
        return leftLines.map(line => escapeHtml(line)).join('\n') + '\n' + escapeHtml(r.padStart(width, ' '));
    }

    return escapeHtml(l.padEnd(width - r.length, ' ') + r);
}

function centerText(text, width = 48) {
    const value = receiptPlain(text);

    if (value.length >= width) {
        return escapeHtml(value.substring(0, width));
    }

    const leftPad = Math.floor((width - value.length) / 2);
    return escapeHtml(' '.repeat(leftPad) + value);
}

function dashLine(width = 48) {
    return '-'.repeat(width);
}

function receiptTableHeader(width = 48) {
    return 'Description'.padEnd(24, ' ') + 'Price'.padStart(8, ' ') + 'Qty'.padStart(6, ' ') + 'Amount'.padStart(10, ' ');
}

function receiptItemRows(items) {
    const width = 48;
    let lines = [];

    items.forEach((item, index) => {
        const barcode = receiptPlain(item.barcode || item.item_code || '');
        const name = receiptPlain(item.name || 'Item');
        const uom = receiptPlain(item.uom_initial || '');
        const itemNameWithUom = uom ? `${name}  ${uom}` : name;
        const description = barcode ? `${barcode}  ${itemNameWithUom}` : itemNameWithUom;
        const price = Number(item.price || 0);
        const qty = Number(item.qty || 0);
        const gross = price * qty;
        const descriptionLines = receiptWrapText(description, width);

        descriptionLines.forEach(line => {
            lines.push(escapeHtml(line.substring(0, width)));
        });

        const priceText = moneyText(price);
        const qtyText = fmt(qty).replace('.00', '');
        const amountText = moneyText(gross);

        lines.push(escapeHtml(
            ''.padEnd(24, ' ') +
            priceText.padStart(8, ' ') +
            qtyText.padStart(6, ' ') +
            amountText.padStart(10, ' ')
        ));

        if (index < items.length - 1) {
            lines.push('');
        }
    });

    return lines.join('\n');
}


function receiptPaymentRows(payments, fallbackMethod, fallbackTendered, paymentReferenceNo, checkNo) {
    const width = 48;
    const rows = Array.isArray(payments) && payments.length
        ? payments
        : [{ payment_method: fallbackMethod || 'Cash', amount: fallbackTendered || 0, reference_no: paymentReferenceNo || '', check_no: checkNo || '' }];

    return rows.map(row => {
        const method = String(row.payment_method || row.method || 'Cash').toUpperCase();
        const amount = Number(row.amount || 0);
        let line = receiptLine(method, moneyText(amount), width);
        const ref = row.reference_no || row.paymentReferenceNo || '';
        const chk = row.check_no || row.checkNo || '';

        if (ref) {
            line += '\n' + receiptLine('REF NO.', ref, width);
        }

        if (chk) {
            line += '\n' + receiptLine('CHECK NO.', chk, width);
        }

        return line;
    }).join('\n');
}

function splitDateTime(dateObj) {
    const dateText = dateObj.toLocaleDateString('en-US', {
        month: '2-digit',
        day: '2-digit',
        year: 'numeric'
    });

    const timeText = dateObj.toLocaleTimeString('en-US', {
        hour: '2-digit',
        minute: '2-digit'
    });

    return { dateText, timeText };
}

function receiptParagraph(text, width = 48) {
    return receiptWrapText(text, width).map(line => escapeHtml(line)).join('\n');
}

function buildReceiptPrintHtml(receipt) {
    const width = 48;
    const nowText = splitDateTime(receipt.printDate || new Date());
    const logoImage = (receipt.receiptInfo && receipt.receiptInfo.logo_image) || '';
    const storeName = (receipt.receiptInfo && receipt.receiptInfo.store_name) || 'AMGC STORE';
    const address = (receipt.receiptInfo && receipt.receiptInfo.address) || '';
    const tin = (receipt.receiptInfo && receipt.receiptInfo.tin) || '';
    const serialNo = (receipt.receiptInfo && receipt.receiptInfo.serial_no) || '';
    const minNo = (receipt.receiptInfo && receipt.receiptInfo.min_no) || '';
    const permitNo = (receipt.receiptInfo && receipt.receiptInfo.permit_no) || '';
    const accrNo = (receipt.receiptInfo && receipt.receiptInfo.accr_no) || '';
    const supplierName = (receipt.receiptInfo && receipt.receiptInfo.supplier_name) || '';
    const supplierAddress = (receipt.receiptInfo && receipt.receiptInfo.supplier_address) || '';
    const supplierTin = (receipt.receiptInfo && receipt.receiptInfo.supplier_tin) || '';
    const footerNote = (receipt.receiptInfo && receipt.receiptInfo.footer_note) || 'Exchange of item for reasons other than those provided under the Consumer Act will only be allowed within 7 days from date of purchase. Please present this Official Receipt.';
    const thankYouText = (receipt.receiptInfo && receipt.receiptInfo.thank_you_text) || 'Thank You!';
    const noticeText = (receipt.receiptInfo && receipt.receiptInfo.notice_text) || 'This is not an official receipt.';

    const vatSales = receipt.vatRegistered ? Number(receipt.vatableSales || 0) : 0;
    const vatAmount = receipt.vatRegistered ? Number(receipt.vatAmount || 0) : 0;
    const nonVatSales = receipt.vatRegistered ? 0 : Number(receipt.total || 0);
    const receiptNo = String(receipt.orNo || receipt.receiptNo || '').trim();
    const soNo = receipt.salesOrderId
        ? String(receipt.salesOrderId).padStart(10, '0')
        : (receipt.saleId ? String(receipt.saleId).padStart(10, '0') : '');

    const headerLines = [];

    const discountLine = receipt.discount > 0
        ? receiptLine(receipt.discountLabel || 'DISCOUNT', '-' + moneyText(receipt.discount), width) + '\n'
        : '';

    const customerBlock = [
        'Customer Name:________________________________',
        'Customer address:_____________________________',
        'Customer Tin#:________________________________',
        'Business Style:_______________________________'
    ].join('\n');

    const supplierBlock = [
        supplierName ? centerText('Accredited POS Supplier Trade Name', width) : '',
        supplierName ? centerText(supplierName, width) : '',
        supplierAddress ? centerText(supplierAddress, width) : '',
        supplierTin ? receiptLine('TIN', supplierTin, width) : '',
        accrNo ? receiptLine('ACCR NO.', accrNo, width) : '',
        permitNo ? receiptLine('PERMIT NO.', permitNo, width) : ''
    ].filter(Boolean).join('\n');

    const bottomBlock = [
        thankYouText ? centerText(thankYouText, width) : '',
        noticeText ? centerText(noticeText, width) : ''
    ].filter(Boolean).join('\n');

    const safeLogoImage = String(logoImage || '').startsWith('data:image/') ? String(logoImage || '') : '';
    const logoHtml = safeLogoImage
        ? `<div class="receipt-logo"><img src="${safeLogoImage}" alt="Receipt Logo"></div>`
        : '';

    const headerInfoHtml = `
        <div class="receipt-header">
            ${logoHtml}
            <div class="receipt-store-name">${escapeHtml(storeName)}</div>
            ${address ? `<div>${escapeHtml(address)}</div>` : ''}
            <div>VAT REG TIN#: ${escapeHtml(tin)}</div>
            <div>SERIAL #: ${escapeHtml(serialNo)}</div>
            <div>MIN: ${escapeHtml(minNo)}</div>
        </div>
    `;

    const receiptText = `${receiptLine('Cashier:' + receipt.cashierName, 'Date:' + nowText.dateText, width)}
${receiptLine('SO#:' + soNo, 'Time:' + nowText.timeText, width)}
${receiptLine('OR#:' + receiptNo, '', width)}
${dashLine(width)}
${receiptTableHeader(width)}
${dashLine(width)}
${receiptItemRows(receipt.items)}
${dashLine(width)}
${receiptLine('SUBTOTAL', moneyText(receipt.subtotal), width)}
${discountLine}${Number(receipt.pointsRedeemed || 0) > 0 ? receiptLine('POINTS USED', '-' + moneyText(receipt.pointsRedeemed), width) + '\n' : ''}${receiptLine('AMOUNT DUE', moneyText(receipt.total), width)}
${receiptLine(String(receipt.paymentMethod || 'CASH').toUpperCase(), moneyText(receipt.tendered), width)}
${receipt.paymentReferenceNo ? receiptLine('REF NO.', receipt.paymentReferenceNo, width) + '\n' : ''}${receipt.checkNo ? receiptLine('CHECK NO.', receipt.checkNo, width) + '\n' : ''}${dashLine(width)}
${receiptLine('CHANGE', moneyText(receipt.change), width)}
${dashLine(width)}
${receipt.customerCode ? receiptLine('MEMBER CODE', receipt.customerCode, width) + '\n' : ''}${Number(receipt.pointsEarned || 0) > 0 ? receiptLine('POINTS EARNED', fmt(receipt.pointsEarned), width) + '\n' : ''}${receipt.customerCode ? receiptLine('POINTS BAL', fmt(receipt.pointsBalance || 0), width) + '\n' : ''}${receiptLine('VAT SALES', moneyText(vatSales), width)}
${receiptLine((receipt.vatRegistered ? fmt(receipt.vatRatePercent).replace('.00','') : '0') + '% VAT', moneyText(vatAmount), width)}
${receiptLine('VAT-EXEMPT SALES', moneyText(0), width)}
${receiptLine('ZERO-RATED SALES', moneyText(0), width)}
${receiptLine('NON-VAT SALES', moneyText(nonVatSales), width)}

${customerBlock}`;

return `<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Receipt ${escapeHtml(receiptNo)}</title>
<style>
@page { size: 80mm auto; margin: 3mm; }
* { box-sizing: border-box; }
html, body { margin: 0; padding: 0; background: #fff; color: #000; }
body { font-family: "Courier New", "Courier", monospace; font-size: 10px; }
.receipt { width: 74mm; margin: 0 auto; padding: 2mm 0; }
.receipt-header { text-align: center; font-size: 10px; line-height: 1.22; margin: 0 0 2mm 0; }
.receipt-store-name { font-weight: 700; text-transform: uppercase; }
.receipt-logo { text-align: center; margin: 0 0 1mm 0; }
.receipt-logo img { max-width: 26mm; max-height: 18mm; object-fit: contain; }

pre {
    margin: 0;
    white-space: pre;
    font-family: "Courier New", "Courier", monospace;
    font-size: 10px;
    line-height: 1.22;
}

.receipt-footer-note {
    display: block;
    width: 100%;
    max-width: none;
    margin: 8px 0;
    padding: 0;
    text-align: justify;
    text-align-last: center;
    text-justify: inter-word;
    white-space: normal;
    font-family: "Courier New", "Courier", monospace;
    font-size: 10px;
    line-height: 1.35;
}

.receipt-bottom-note {
    text-align: center;
}

@media print {
    html, body { width: 80mm; }
    .receipt { width: 74mm; }
}
</style>
</head>
<body>
<div class="receipt">
    ${headerInfoHtml}
    <pre>${receiptText}</pre>

    <div class="receipt-footer-note">
        ${escapeHtml(footerNote)}
    </div>

    <div class="receipt-bottom-note">
        <div>${escapeHtml(thankYouText)}</div>
        <div>${escapeHtml(noticeText)}</div>
    </div>
</div>
</body>
</html>`;
}

function showReceiptPrintStatus(message = 'Printing receipt...', state = 'printing') {
    let indicator = document.getElementById('receiptPrintStatus');

    if (!indicator) {
        indicator = document.createElement('div');
        indicator.id = 'receiptPrintStatus';
        indicator.setAttribute('aria-live', 'polite');
        indicator.style.position = 'fixed';
        indicator.style.right = '14px';
        indicator.style.bottom = '14px';
        indicator.style.zIndex = '99999';
        indicator.style.display = 'none';
        indicator.style.alignItems = 'center';
        indicator.style.gap = '7px';
        indicator.style.maxWidth = '230px';
        indicator.style.padding = '7px 10px';
        indicator.style.border = '1px solid rgba(15, 23, 42, 0.14)';
        indicator.style.borderRadius = '8px';
        indicator.style.background = 'rgba(255, 255, 255, 0.96)';
        indicator.style.color = '#334155';
        indicator.style.fontSize = '12px';
        indicator.style.fontWeight = '500';
        indicator.style.lineHeight = '1.2';
        indicator.style.boxShadow = '0 4px 14px rgba(15, 23, 42, 0.12)';
        indicator.style.pointerEvents = 'none';
        indicator.style.transition = 'opacity 0.18s ease';
        document.body.appendChild(indicator);
    }

    const icon = state === 'done'
        ? '<i class="fa-solid fa-check" style="color:#16a34a"></i>'
        : state === 'error'
            ? '<i class="fa-solid fa-triangle-exclamation" style="color:#dc2626"></i>'
            : '<i class="fa-solid fa-print" style="color:#0f766e"></i>';

    indicator.innerHTML = `${icon}<span>${escapeHtml(message)}</span>`;
    indicator.style.display = 'flex';
    indicator.style.opacity = '1';

    clearTimeout(window.__receiptPrintStatusTimer);

    if (state !== 'printing') {
        window.__receiptPrintStatusTimer = setTimeout(() => {
            indicator.style.opacity = '0';

            setTimeout(() => {
                indicator.style.display = 'none';
            }, 200);
        }, state === 'error' ? 3500 : 1800);
    }
}

function receiptRawHeader(receipt, width) {
    const info = receipt.receiptInfo || {};
    const lines = [];
    const storeName = info.store_name || 'AMGC STORE';
    const address = info.address || '';
    const tin = info.tin || '';
    const serialNo = info.serial_no || '';
    const minNo = info.min_no || '';

    lines.push(centerText(storeName, width));
    if (address) lines.push(centerText(address, width));
    lines.push(centerText('VAT REG TIN#: ' + tin, width));
    lines.push(centerText('SERIAL #: ' + serialNo, width));
    lines.push(centerText('MIN: ' + minNo, width));
    lines.push('');
    return lines.join('\n');
}

function buildReceiptRawText(receipt) {
    const width = 48;
    const nowText = splitDateTime(receipt.printDate || new Date());
    const info = receipt.receiptInfo || {};
    const footerNote = info.footer_note || 'Exchange of item for reasons other than those provided under the Consumer Act will only be allowed within 7 days from date of purchase. Please present this Official Receipt.';
    const thankYouText = info.thank_you_text || 'Thank You!';
    const noticeText = info.notice_text || 'This is not an official receipt.';
    const receiptNo = String(receipt.orNo || receipt.receiptNo || '').trim();
    const soNo = receipt.salesOrderId
        ? String(receipt.salesOrderId).padStart(10, '0')
        : (receipt.saleId ? String(receipt.saleId).padStart(10, '0') : '');
    const vatSales = receipt.vatRegistered ? Number(receipt.vatableSales || 0) : 0;
    const vatAmount = receipt.vatRegistered ? Number(receipt.vatAmount || 0) : 0;
    const nonVatSales = receipt.vatRegistered ? 0 : Number(receipt.total || 0);

    const lines = [];
    lines.push(receiptRawHeader(receipt, width).replace(/\n$/, ''));
    lines.push(receiptLine('Cashier:' + receipt.cashierName, 'Date:' + nowText.dateText, width));
    lines.push(receiptLine('SO#:' + soNo, 'Time:' + nowText.timeText, width));
    lines.push(receiptLine('OR#:' + receiptNo, '', width));
    lines.push(dashLine(width));
    lines.push(receiptTableHeader(width));
    lines.push(dashLine(width));
    lines.push(receiptItemRows(receipt.items));
    lines.push(dashLine(width));
    lines.push(receiptLine('SUBTOTAL', moneyText(receipt.subtotal), width));

    if (Number(receipt.discount || 0) > 0) {
        lines.push(receiptLine(receipt.discountLabel || 'DISCOUNT', '-' + moneyText(receipt.discount), width));
    }

    if (Number(receipt.pointsRedeemed || 0) > 0) {
        lines.push(receiptLine('POINTS USED', '-' + moneyText(receipt.pointsRedeemed), width));
    }

    lines.push(receiptLine('AMOUNT DUE', moneyText(receipt.total), width));
    lines.push(receiptLine(String(receipt.paymentMethod || 'CASH').toUpperCase(), moneyText(receipt.tendered), width));

    if (receipt.paymentReferenceNo) lines.push(receiptLine('REF NO.', receipt.paymentReferenceNo, width));
    if (receipt.checkNo) lines.push(receiptLine('CHECK NO.', receipt.checkNo, width));

    lines.push(dashLine(width));
    lines.push(receiptLine('CHANGE', moneyText(receipt.change), width));
    lines.push(dashLine(width));

    if (receipt.customerCode) lines.push(receiptLine('MEMBER CODE', receipt.customerCode, width));
    if (Number(receipt.pointsEarned || 0) > 0) lines.push(receiptLine('POINTS EARNED', fmt(receipt.pointsEarned), width));
    if (receipt.customerCode) lines.push(receiptLine('POINTS BAL', fmt(receipt.pointsBalance || 0), width));

    lines.push(receiptLine('VAT SALES', moneyText(vatSales), width));
    lines.push(receiptLine((receipt.vatRegistered ? fmt(receipt.vatRatePercent).replace('.00', '') : '0') + '% VAT', moneyText(vatAmount), width));
    lines.push(receiptLine('VAT-EXEMPT SALES', moneyText(0), width));
    lines.push(receiptLine('ZERO-RATED SALES', moneyText(0), width));
    lines.push(receiptLine('NON-VAT SALES', moneyText(nonVatSales), width));
    lines.push('');
    lines.push('Customer Name:________________________________');
    lines.push('Customer address:_____________________________');
    lines.push('Customer Tin#:________________________________');
    lines.push('Business Style:_______________________________');
    lines.push('');

    receiptWrapText(footerNote, 44).forEach(line => lines.push(centerText(line, width)));
    lines.push('');
    lines.push(centerText(thankYouText, width));
    lines.push(centerText(noticeText, width));
    lines.push('');
    lines.push('');
    lines.push('');

    return lines.join('\n');
}

const POS_PENDING_PRINTS_KEY = 'amgc_pos_pending_receipts_v1';

function isAppleMobileDevice() {
    const ua = navigator.userAgent || '';
    const platform = navigator.platform || '';
    const maxTouchPoints = Number(navigator.maxTouchPoints || 0);

    return /iPad|iPhone|iPod/i.test(ua)
        || (platform === 'MacIntel' && maxTouchPoints > 1);
}

function isMobileOrTabletDevice() {
    const ua = navigator.userAgent || '';

    return isAppleMobileDevice()
        || /Android|Mobile|Tablet/i.test(ua);
}

function getPendingReceiptPrints() {
    try {
        const saved = JSON.parse(localStorage.getItem(POS_PENDING_PRINTS_KEY) || '[]');
        return Array.isArray(saved) ? saved : [];
    } catch (error) {
        return [];
    }
}

function savePendingReceiptPrint(receipt, reason = '') {
    const queue = getPendingReceiptPrints();
    const receiptKey = String(receipt.receiptNo || receipt.orNo || receipt.saleId || Date.now());

    const entry = {
        key: receiptKey,
        receipt: receipt,
        reason: String(reason || ''),
        queuedAt: new Date().toISOString()
    };

    const filtered = queue.filter(item => String(item && item.key || '') !== receiptKey);
    filtered.unshift(entry);

    localStorage.setItem(
        POS_PENDING_PRINTS_KEY,
        JSON.stringify(filtered.slice(0, 30))
    );
}

function removePendingReceiptPrint(receipt) {
    const receiptKey = String(receipt.receiptNo || receipt.orNo || receipt.saleId || '');
    const queue = getPendingReceiptPrints().filter(
        item => String(item && item.key || '') !== receiptKey
    );

    localStorage.setItem(POS_PENDING_PRINTS_KEY, JSON.stringify(queue));
}

function browserPrintReceipt(receipt) {
    return new Promise((resolve, reject) => {
        showReceiptPrintStatus(
            isAppleMobileDevice()
                ? 'Opening AirPrint...'
                : 'Printing receipt...',
            'printing'
        );

        const previousFrame = document.getElementById('posUniversalPrintFrame');

        if (previousFrame) {
            previousFrame.remove();
        }

        const frame = document.createElement('iframe');
        frame.id = 'posUniversalPrintFrame';
        frame.title = 'Receipt print frame';
        frame.setAttribute('aria-hidden', 'true');
        frame.style.position = 'fixed';
        frame.style.width = '1px';
        frame.style.height = '1px';
        frame.style.right = '0';
        frame.style.bottom = '0';
        frame.style.border = '0';
        frame.style.opacity = '0';
        frame.style.pointerEvents = 'none';

        let started = false;

        frame.onload = function () {
            if (started) {
                return;
            }

            started = true;

            setTimeout(() => {
                try {
                    const printWindow = frame.contentWindow;

                    if (!printWindow) {
                        throw new Error('Browser print service is unavailable.');
                    }

                    printWindow.focus();
                    printWindow.print();

                    showReceiptPrintStatus(
                        isAppleMobileDevice()
                            ? 'AirPrint opened.'
                            : 'Receipt sent to printer.',
                        'done'
                    );

                    setTimeout(() => frame.remove(), 1500);
                    resolve(true);
                } catch (error) {
                    setTimeout(() => frame.remove(), 300);
                    reject(error);
                }
            }, 350);
        };

        document.body.appendChild(frame);

        try {
            const frameDocument = frame.contentDocument || frame.contentWindow.document;
            frameDocument.open();
            frameDocument.write(buildReceiptPrintHtml(receipt));
            frameDocument.close();
        } catch (error) {
            frame.remove();
            reject(error);
        }
    });
}

async function printReceiptNow(receipt) {
    savePendingReceiptPrint(receipt, 'Waiting to print');

    try {
        showReceiptPrintStatus('Printing receipt...', 'printing');

        await browserPrintReceipt(receipt);

        removePendingReceiptPrint(receipt);
        showReceiptPrintStatus('Receipt sent to printer.', 'done');
        return true;
    } catch (error) {
        console.error('Receipt printing failed:', error);

        savePendingReceiptPrint(
            receipt,
            error && error.message ? error.message : 'Unable to print receipt.'
        );

        showReceiptPrintStatus(
            'Receipt saved. Printer unavailable; use Reprint later.',
            'error'
        );

        return false;
    }
}

function openTender() {
    if (!requireOpenShift()) {
        return;
    }

    if (cart.length === 0) {
        Swal.fire('No item in cart', '', 'info');
        return;
    }

    mixedPayments = [];
    mixedMode = false;

    const tenderBox = document.querySelector('#tenderModal .tender-box');
    if (tenderBox) {
        tenderBox.classList.remove('mixed-payment-mode');
    }

    selectedPointsToRedeem = 0;
    document.getElementById('tenderDue').value = fmt(totalDueAfterPoints());
    const pointsInput = document.getElementById('pointsRedeemInput');
    if (pointsInput) {
        pointsInput.value = '';
        pointsInput.disabled = !(selectedCustomerId > 0);
    }
    updateAvailablePointsDisplay();
    document.getElementById('paymentMethodSelect').value = 'Cash';
    document.getElementById('tenderedAmount').value = '';
    document.getElementById('tenderChange').value = '0.00';
    document.getElementById('paymentReferenceNo').value = '';
    document.getElementById('checkNo').value = '';
    document.getElementById('customerName').value = selectedCustomerName || 'Walk-in Customer';

    document.getElementById('tenderModal').classList.add('show');

    updatePaymentMethodOptions();
    handlePaymentMethodChange();
    setActivePaymentInput('tenderedAmount');
    updateTenderChange();

    setTimeout(() => {
        const amountInput = document.getElementById('tenderedAmount');
        if (amountInput) {
            amountInput.focus();
            amountInput.select();
        }
    }, 80);
}

function closeTender() {
    document.getElementById('tenderModal').classList.remove('show');
    focusBarcodeInput();
}

let activePaymentInputId = 'tenderedAmount';
let mixedMode = false;
let mixedPayments = [];
const tenderPaymentInputIds = ['tenderedAmount'];

function cleanTenderAmountValue(value) {
    value = String(value || '').replace(/,/g, '').replace(/[^\d.]/g, '');

    const parts = value.split('.');
    let whole = parts.shift() || '';
    let decimal = parts.length ? parts.join('').slice(0, 2) : '';

    whole = whole.replace(/^0+(?=\d)/, '');

    if (whole === '' && decimal !== '') {
        whole = '0';
    }

    if (String(value).includes('.')) {
        return `${whole || '0'}.${decimal}`;
    }

    return whole;
}

function formatTenderAmountValue(value) {
    let cleaned = cleanTenderAmountValue(value);

    if (cleaned === '') {
        return '';
    }

    const hasDecimal = cleaned.includes('.');
    const [wholePart, decimalPart = ''] = cleaned.split('.');
    const formattedWhole = wholePart === ''
        ? '0'
        : Number(wholePart || 0).toLocaleString('en-US');

    if (hasDecimal) {
        return `${formattedWhole}.${decimalPart}`;
    }

    return formattedWhole;
}

function parseMoneyInputValue(value) {
    return Number(String(value || '').replace(/,/g, '') || 0);
}

function setActivePaymentInput(inputId) {
    const input = document.getElementById(inputId) || document.getElementById('tenderedAmount');

    activePaymentInputId = input ? input.id : 'tenderedAmount';

    document.querySelectorAll('.payment-amount-input').forEach(el => {
        el.classList.toggle('active-payment-input', el.id === activePaymentInputId);
    });
}

function getActivePaymentInput() {
    let input = document.getElementById(activePaymentInputId);

    if (!input) {
        activePaymentInputId = 'tenderedAmount';
        input = document.getElementById(activePaymentInputId);
    }

    return input;
}

function formatPaymentAmountInput(input) {
    if (!input) {
        return;
    }

    setActivePaymentInput(input.id);
    input.value = formatTenderAmountValue(input.value);
    updateTenderChange();
}

function formatTenderAmountInput() {
    const input = getActivePaymentInput();

    if (!input) {
        return;
    }

    input.value = formatTenderAmountValue(input.value);
    updateTenderChange();
}


function getUsedPaymentMethods() {
    return mixedPayments.map(row => row.payment_method).filter(Boolean);
}

function updatePaymentMethodOptions() {
    const select = document.getElementById('paymentMethodSelect');

    if (!select) {
        return;
    }

    const currentValue = select.value || 'Cash';
    const used = getUsedPaymentMethods();
    let firstAvailable = '';

    Array.from(select.options).forEach(option => {
        const isUsed = used.includes(option.value);
        option.disabled = isUsed;

        if (!isUsed && firstAvailable === '') {
            firstAvailable = option.value;
        }
    });

    if (used.includes(currentValue)) {
        select.value = firstAvailable || '';
    }

    const addBtn = document.querySelector('#tenderModal .add-payment-btn');

    if (addBtn) {
        addBtn.disabled = !firstAvailable && !select.value;
    }
}

function getCurrentPaymentMethod() {
    return document.getElementById('paymentMethodSelect')?.value || 'Cash';
}

function getCurrentPaymentAmount() {
    return parseMoneyInputValue(document.getElementById('tenderedAmount')?.value || '0');
}

function getCurrentPaymentReference() {
    return (document.getElementById('paymentReferenceNo')?.value || '').trim();
}

function getCurrentCheckNo() {
    return (document.getElementById('checkNo')?.value || '').trim();
}

function getPendingPaymentRow() {
    const method = getCurrentPaymentMethod();
    const amount = round2(getCurrentPaymentAmount());

    if (amount <= 0) {
        return null;
    }

    return {
        payment_method: method,
        amount: amount,
        reference_no: ['GCash', 'Online Transfer'].includes(method) ? getCurrentPaymentReference() : '',
        check_no: method === 'Check' ? getCurrentCheckNo() : ''
    };
}

function parseTenderAmount() {
    const pending = getPendingPaymentRow();
    const mixedTotal = mixedPayments.reduce((sum, row) => sum + Number(row.amount || 0), 0);
    return mixedTotal + (pending ? Number(pending.amount || 0) : 0);
}

function updateTenderChange() {
    const due = totalDueAfterPoints();
    const totalPaid = parseTenderAmount();
    const change = Math.max(0, totalPaid - due);
    const balance = Math.max(0, due - totalPaid);

    const changeInput = document.getElementById('tenderChange');
    if (changeInput) changeInput.value = fmt(change);

    const totalPaidEl = document.getElementById('tenderTotalPaid');
    const balanceEl = document.getElementById('tenderBalance');
    const changeEl = document.getElementById('tenderChangeText');

    if (totalPaidEl) totalPaidEl.textContent = fmt(totalPaid);
    if (balanceEl) balanceEl.textContent = fmt(balance);
    if (changeEl) changeEl.textContent = fmt(change);

    renderMixedPayments();
}

function appendTenderKey(key) {
    const input = getActivePaymentInput();

    if (!input) {
        return;
    }

    let value = cleanTenderAmountValue(input.value);

    if (key === '.') {
        if (value.includes('.')) {
            return;
        }

        value = value === '' ? '0.' : value + '.';
    } else {
        value += key;
    }

    input.value = formatTenderAmountValue(value);
    input.focus();
    updateTenderChange();
}

function backspaceTenderKey() {
    const input = getActivePaymentInput();

    if (!input) {
        return;
    }

    const rawValue = cleanTenderAmountValue(input.value);
    input.value = formatTenderAmountValue(rawValue.slice(0, -1));
    input.focus();
    updateTenderChange();
}

function clearTenderKey() {
    const input = getActivePaymentInput();

    if (!input) {
        return;
    }

    input.value = '';
    input.focus();
    updateTenderChange();
}

function round2(value) {
    return Math.round((Number(value || 0) + Number.EPSILON) * 100) / 100;
}

function handlePaymentMethodChange() {
    updatePaymentMethodOptions();

    const method = getCurrentPaymentMethod();
    const due = totalDueAfterPoints();
    const alreadyPaid = mixedPayments.reduce((sum, row) => sum + Number(row.amount || 0), 0);
    const remaining = Math.max(0, due - alreadyPaid);
    const refWrap = document.getElementById('referenceFieldWrap');
    const refLabel = document.getElementById('referenceFieldLabel');
    const checkWrap = document.getElementById('checkFieldWrap');
    const amountLabel = document.getElementById('tenderAmountLabel');
    const amountInput = document.getElementById('tenderedAmount');

    if (amountLabel) {
        amountLabel.textContent = method === 'Cash' ? 'Tendered Amount' : `${method} Amount`;
    }

    if (refWrap) {
        refWrap.style.display = ['GCash', 'Online Transfer'].includes(method) ? 'block' : 'none';
    }

    if (refLabel) {
        refLabel.textContent = method === 'GCash' ? 'GCash Reference No.' : 'Online Transfer Reference No.';
    }

    if (checkWrap) {
        checkWrap.style.display = method === 'Check' ? 'block' : 'none';
    }

    if (amountInput) {
        if (method !== 'Cash' && !mixedMode) {
            amountInput.value = formatTenderAmountValue(remaining.toFixed(2));
        }

        if (method !== 'Cash' && mixedMode && amountInput.value === '') {
            amountInput.value = formatTenderAmountValue(remaining.toFixed(2));
        }

        setActivePaymentInput('tenderedAmount');
    }

    updateTenderChange();
}

function handleTenderEnter(event) {
    if (event.key === 'Enter') {
        event.preventDefault();
        saveSale();
    }
}

function validatePaymentRow(row, showAlert = true) {
    if (!row || !row.payment_method || Number(row.amount || 0) <= 0) {
        if (showAlert) Swal.fire('No amount entered', 'Please enter the payment amount.', 'warning');
        return false;
    }

    if (['GCash', 'Online Transfer'].includes(row.payment_method) && !row.reference_no) {
        if (showAlert) Swal.fire(`${row.payment_method} reference number is required`, '', 'warning');
        return false;
    }

    if (row.payment_method === 'Check' && !row.check_no) {
        if (showAlert) Swal.fire('Check No. is required', '', 'warning');
        return false;
    }

    return true;
}

function addCurrentPaymentToMix() {
    updatePaymentMethodOptions();

    const row = getPendingPaymentRow();

    if (!validatePaymentRow(row, true)) {
        return;
    }

    if (mixedPayments.some(payment => payment.payment_method === row.payment_method)) {
        Swal.fire(`${row.payment_method} is already added`, 'Choose another payment method.', 'warning');
        return;
    }

    mixedMode = true;
    mixedPayments.push(row);

    const tenderBox = document.querySelector('#tenderModal .tender-box');
    if (tenderBox) {
        tenderBox.classList.add('mixed-payment-mode');
    }

    document.getElementById('tenderedAmount').value = '';
    document.getElementById('paymentReferenceNo').value = '';
    document.getElementById('checkNo').value = '';

    updatePaymentMethodOptions();
    handlePaymentMethodChange();

    setTimeout(() => {
        const amountInput = document.getElementById('tenderedAmount');
        if (amountInput) amountInput.focus();
    }, 60);
}

function removeMixedPayment(index) {
    mixedPayments.splice(index, 1);
    mixedMode = mixedPayments.length > 0;

    const tenderBox = document.querySelector('#tenderModal .tender-box');
    if (tenderBox) {
        tenderBox.classList.toggle('mixed-payment-mode', mixedPayments.length > 0);
    }

    updatePaymentMethodOptions();
    handlePaymentMethodChange();
    updateTenderChange();
}

function clearMixedPayments() {
    mixedPayments = [];
    mixedMode = false;

    const tenderBox = document.querySelector('#tenderModal .tender-box');
    if (tenderBox) {
        tenderBox.classList.remove('mixed-payment-mode');
    }

    updatePaymentMethodOptions();
    handlePaymentMethodChange();
    updateTenderChange();
}

function renderMixedPayments() {
    const panel = document.getElementById('mixedPaymentPanel');
    const list = document.getElementById('mixedPaymentList');

    if (!panel || !list) {
        return;
    }

    panel.style.display = mixedPayments.length ? '' : 'none';

    const tenderBox = document.querySelector('#tenderModal .tender-box');
    if (tenderBox) {
        tenderBox.classList.toggle('mixed-payment-mode', mixedPayments.length > 0);
    }

    updatePaymentMethodOptions();

    list.innerHTML = mixedPayments.map((row, index) => {
        const detail = row.reference_no ? `Ref#: ${escapeHtml(row.reference_no)}` : (row.check_no ? `Check#: ${escapeHtml(row.check_no)}` : '');
        return `
            <div class="mixed-payment-row">
                <div>${escapeHtml(row.payment_method)}${detail ? `<small>${detail}</small>` : ''}</div>
                <div>₱ ${fmt(row.amount)}</div>
                <button type="button" onclick="removeMixedPayment(${index})">Remove</button>
            </div>
        `;
    }).join('');
}

function buildTenderPayments() {
    const payments = [...mixedPayments];
    const pending = getPendingPaymentRow();

    if (pending) {
        payments.push(pending);
    }

    return payments.filter(row => Number(row.amount || 0) > 0);
}

async function saveSale() {
    if (window.__savingSale) {
        return;
    }

    window.__savingSale = true;

    const due = totalDueAfterPoints();
    const payments = buildTenderPayments();
    const tendered = payments.reduce((sum, row) => sum + Number(row.amount || 0), 0);

    if (!payments.length) {
        window.__savingSale = false;
        Swal.fire('No payment entered', 'Please enter at least one payment amount.', 'warning');
        return;
    }

    if (tendered < due) {
        window.__savingSale = false;
        Swal.fire('Total payment is insufficient', `Balance: ₱${fmt(due - tendered)}`, 'warning');
        return;
    }

    const gcashPayment = payments.find(row => row.payment_method === 'GCash');
    const onlinePayment = payments.find(row => row.payment_method === 'Online Transfer');
    const checkPayment = payments.find(row => row.payment_method === 'Check');

    if (gcashPayment && !gcashPayment.reference_no) {
        window.__savingSale = false;
        Swal.fire('GCash reference number is required', '', 'warning');
        return;
    }

    if (onlinePayment && !onlinePayment.reference_no) {
        window.__savingSale = false;
        Swal.fire('Online Transfer reference number is required', '', 'warning');
        return;
    }

    if (checkPayment && !checkPayment.check_no) {
        window.__savingSale = false;
        Swal.fire('Check No. is required', '', 'warning');
        return;
    }

    const paymentMethod = payments.length > 1 ? 'Mixed' : payments[0].payment_method;
    const paymentReferenceNo = payments.map(row => row.reference_no).filter(Boolean).join(', ');
    const checkNo = payments.map(row => row.check_no).filter(Boolean).join(', ');
    const customerName = document.getElementById('customerName').value || 'Walk-in Customer';
    const saleItems = buildSaleItemsForSave();
    const receiptSubtotal = getOrderGross();
    const receiptDiscount = getTotalDiscountAmount();
    const receiptTotal = totalDueAfterPoints();
    const receiptDiscountLabel = getOrderDiscountLabel() || 'Discount';
    const receiptItems = saleItems.map(item => ({ ...item }));

    const data = await api({
        action: 'save_sale',
        items: saleItems,
        tendered: tendered,
        payment_method: paymentMethod,
        payment_reference_no: paymentReferenceNo,
        check_no: checkNo,
        payments: payments,
        customer_name: customerName,
        customer_id: selectedCustomerId,
        customer_code: selectedCustomerCode,
        points_redeemed: selectedPointsToRedeem,
        price_level: currentPriceLevel
    });

    window.__savingSale = false;

    if (data.success) {
        const receipt = {
            receiptNo: data.receipt_no,
            orNo: data.or_no || data.receipt_no,
            saleId: data.sale_id || '',
            salesOrderId: data.sales_order_id || '',
            printDate: new Date(),
            cashierName: posCashierName || 'Cashier',
            branchName: posBranchName || 'Store Counter',
            receiptInfo: posReceiptInfo || {},
            customerName: customerName,
            paymentMethod: paymentMethod,
            paymentReferenceNo: paymentReferenceNo,
            checkNo: checkNo,
            payments: payments,
            items: receiptItems,
            subtotal: receiptSubtotal,
            discount: receiptDiscount,
            discountLabel: receiptDiscountLabel,
            pointsRedeemed: selectedPointsToRedeem,
            pointsEarned: Number(data.points_earned || 0),
            pointsBalance: Number(data.points_balance || 0),
            customerCode: selectedCustomerCode,
            total: receiptTotal,
            vatRegistered: posVatRegistered,
            vatRatePercent: posVatRatePercent,
            vatableSales: getVatableSales(),
            vatAmount: getVatAmount(),
            tendered: tendered,
            change: Number(data.change || 0)
        };

        printReceiptNow(receipt);

        completedSaleSummary = {
            subtotal: receiptSubtotal,
            discount: receiptDiscount,
            discountLabel: receiptDiscountLabel,
            total: receiptTotal,
            pointsRedeemed: selectedPointsToRedeem,
            pointsEarned: Number(data.points_earned || 0),
            tendered: tendered,
            change: Number(data.change || 0),
            paymentMethod: paymentMethod,
            payments: payments
        };
        saleAwaitingNewCustomer = true;

        closeTender();
        renderCart();
        setScanStatus('Sale completed. Press ESC for new transaction.', 'ok');
    } else {
        Swal.fire('Error', data.message || 'Sale was not saved.', 'error');
    }
}


async function openBranchSettings() {
    let currentSettings = {
        is_vat_registered: posVatRegistered ? 1 : 0,
        vat_rate: posVatRatePercent || 12,
        receipt_logo_image: (posReceiptInfo && posReceiptInfo.logo_image) || '',
        receipt_store_name: (posReceiptInfo && posReceiptInfo.store_name) || 'AMGC STORE',
        receipt_address: (posReceiptInfo && posReceiptInfo.address) || '',
        receipt_tin: (posReceiptInfo && posReceiptInfo.tin) || '',
        receipt_serial_no: (posReceiptInfo && posReceiptInfo.serial_no) || '',
        receipt_min_no: (posReceiptInfo && posReceiptInfo.min_no) || '',
        receipt_permit_no: (posReceiptInfo && posReceiptInfo.permit_no) || '',
        receipt_accr_no: (posReceiptInfo && posReceiptInfo.accr_no) || '',
        receipt_supplier_name: (posReceiptInfo && posReceiptInfo.supplier_name) || '',
        receipt_supplier_address: (posReceiptInfo && posReceiptInfo.supplier_address) || '',
        receipt_supplier_tin: (posReceiptInfo && posReceiptInfo.supplier_tin) || '',
        receipt_footer_note: (posReceiptInfo && posReceiptInfo.footer_note) || '',
        receipt_thank_you_text: (posReceiptInfo && posReceiptInfo.thank_you_text) || 'Thank You!',
        receipt_notice_text: (posReceiptInfo && posReceiptInfo.notice_text) || 'This is not an official receipt.'
    };

    try {
        const data = await api({ action: 'get_branch_settings' });
        if (data.success) {
            currentSettings = {
                ...currentSettings,
                is_vat_registered: Number(data.is_vat_registered || 0),
                vat_rate: Number(data.vat_rate || 0),
                receipt_logo_image: data.receipt_logo_image || '',
                receipt_store_name: data.receipt_store_name || currentSettings.receipt_store_name,
                receipt_address: data.receipt_address || currentSettings.receipt_address,
                receipt_tin: data.receipt_tin || '',
                receipt_serial_no: data.receipt_serial_no || '',
                receipt_min_no: data.receipt_min_no || '',
                receipt_permit_no: data.receipt_permit_no || '',
                receipt_accr_no: data.receipt_accr_no || '',
                receipt_supplier_name: data.receipt_supplier_name || '',
                receipt_supplier_address: data.receipt_supplier_address || '',
                receipt_supplier_tin: data.receipt_supplier_tin || '',
                receipt_footer_note: data.receipt_footer_note || currentSettings.receipt_footer_note,
                receipt_thank_you_text: data.receipt_thank_you_text || currentSettings.receipt_thank_you_text,
                receipt_notice_text: data.receipt_notice_text || currentSettings.receipt_notice_text
            };
        }
    } catch (error) {
        console.warn('Failed to load branch settings.', error);
    }

    const result = await Swal.fire({
        title: 'Branch Settings',
        width: 820,
        customClass: {
            popup: 'settings-swal',
            confirmButton: 'settings-confirm-btn',
            cancelButton: 'settings-cancel-btn'
        },
        buttonsStyling: false,
        html: `
            <div class="settings-scroll">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                    <div style="grid-column:1 / -1;">
                        <label style="display:block;font-weight: 700;margin-bottom:6px;">Receipt Logo Image <span style="font-weight:700;opacity:.75;">(Optional)</span></label>
                        <div class="logo-upload-card">
                            <label for="receiptLogoImage" class="logo-upload-label">
                                <i class="fa-solid fa-image"></i>
                                <strong id="receiptLogoUploadText">Click to Upload Logo</strong>
                                <span>PNG/JPG/WebP only • Max 5MB</span>
                            </label>
                            <div id="receiptLogoPreviewWrap" class="logo-preview-box">
                                <img id="receiptLogoPreview" src="${escapeHtml(currentSettings.receipt_logo_image || '')}" alt="Receipt Logo" style="display:${currentSettings.receipt_logo_image ? 'block' : 'none'};">
                                <div id="receiptLogoEmpty" class="logo-preview-empty" style="display:${currentSettings.receipt_logo_image ? 'none' : 'block'};">No logo selected</div>
                                <label class="logo-remove-row" style="display:${currentSettings.receipt_logo_image ? 'flex' : 'none'};">
                                    <input id="removeReceiptLogo" type="checkbox">
                                    Remove logo
                                </label>
                            </div>
                        </div>
                        <input id="receiptLogoImage" type="file" accept="image/*" style="display:none;">
                        <input id="receiptLogoImageData" type="hidden" value="${escapeHtml(currentSettings.receipt_logo_image || '')}">
                    </div>
                    <div>
                        <label style="display:block;font-weight: 600;margin-bottom:6px;color:#0f172a;">Store Name</label>
                        <input id="receiptStoreName" value="${escapeHtml(currentSettings.receipt_store_name || '')}" class="swal2-input" style="width:100%;margin:0;">
                    </div>
                    <div>
                        <label style="display:block;font-weight: 600;margin-bottom:6px;color:#0f172a;">TIN</label>
                        <input id="receiptTin" value="${escapeHtml(currentSettings.receipt_tin || '')}" class="swal2-input" style="width:100%;margin:0;">
                    </div>
                    <div style="grid-column:1 / -1;">
                        <label style="display:block;font-weight: 600;margin-bottom:6px;color:#0f172a;">Receipt Address</label>
                        <input id="receiptAddress" value="${escapeHtml(currentSettings.receipt_address || '')}" class="swal2-input" style="width:100%;margin:0;">
                    </div>
                    <div>
                        <label style="display:block;font-weight: 600;margin-bottom:6px;color:#0f172a;">Serial No.</label>
                        <input id="receiptSerialNo" value="${escapeHtml(currentSettings.receipt_serial_no || '')}" class="swal2-input" style="width:100%;margin:0;">
                    </div>
                    <div>
                        <label style="display:block;font-weight: 600;margin-bottom:6px;color:#0f172a;">MIN</label>
                        <input id="receiptMinNo" value="${escapeHtml(currentSettings.receipt_min_no || '')}" class="swal2-input" style="width:100%;margin:0;">
                    </div>
                    <div>
                        <label style="display:block;font-weight: 600;margin-bottom:6px;color:#0f172a;">Permit No.</label>
                        <input id="receiptPermitNo" value="${escapeHtml(currentSettings.receipt_permit_no || '')}" class="swal2-input" style="width:100%;margin:0;">
                    </div>
                    <div>
                        <label style="display:block;font-weight: 600;margin-bottom:6px;color:#0f172a;">Accreditation No.</label>
                        <input id="receiptAccrNo" value="${escapeHtml(currentSettings.receipt_accr_no || '')}" class="swal2-input" style="width:100%;margin:0;">
                    </div>
                </div>

                <hr style="border:0;border-top:1px solid #e2e8f0;margin:14px 0;">

                <label style="display:flex;align-items:center;gap:10px;font-weight: 700;margin-bottom:12px;color:#0f172a;">
                    <input id="branchVatRegistered" type="checkbox" ${currentSettings.is_vat_registered ? 'checked' : ''} style="width:18px;height:18px;">
                    VAT Registered
                </label>

                <label style="display:block;font-weight: 600;margin-bottom:6px;color:#0f172a;">VAT Rate (%)</label>
                <input id="branchVatRate" type="number" min="0" max="100" step="0.01" value="${currentSettings.vat_rate || 12}" class="swal2-input" style="width:100%;margin:0 0 10px 0;">

                <hr style="border:0;border-top:1px solid #e2e8f0;margin:14px 0;">

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                    <div>
                        <label style="display:block;font-weight: 600;margin-bottom:6px;color:#0f172a;">POS Supplier Name</label>
                        <input id="receiptSupplierName" value="${escapeHtml(currentSettings.receipt_supplier_name || '')}" class="swal2-input" style="width:100%;margin:0;">
                    </div>
                    <div>
                        <label style="display:block;font-weight: 600;margin-bottom:6px;color:#0f172a;">Supplier TIN</label>
                        <input id="receiptSupplierTin" value="${escapeHtml(currentSettings.receipt_supplier_tin || '')}" class="swal2-input" style="width:100%;margin:0;">
                    </div>
                    <div style="grid-column:1 / -1;">
                        <label style="display:block;font-weight: 600;margin-bottom:6px;color:#0f172a;">Supplier Address</label>
                        <input id="receiptSupplierAddress" value="${escapeHtml(currentSettings.receipt_supplier_address || '')}" class="swal2-input" style="width:100%;margin:0;">
                    </div>
                    <div style="grid-column:1 / -1;">
                        <label style="display:block;font-weight: 600;margin-bottom:6px;color:#0f172a;">Receipt Footer Note</label>
                        <textarea id="receiptFooterNote" class="swal2-textarea" style="width:100%;margin:0;min-height:86px;">${escapeHtml(currentSettings.receipt_footer_note || '')}</textarea>
                    </div>
                    <div>
                        <label style="display:block;font-weight: 600;margin-bottom:6px;color:#0f172a;">Thank You Text</label>
                        <input id="receiptThankYouText" value="${escapeHtml(currentSettings.receipt_thank_you_text || 'Thank You!')}" class="swal2-input" style="width:100%;margin:0;">
                    </div>
                    <div>
                        <label style="display:block;font-weight: 600;margin-bottom:6px;color:#0f172a;">Receipt Notice</label>
                        <input id="receiptNoticeText" value="${escapeHtml(currentSettings.receipt_notice_text || 'This is not an official receipt.')}" class="swal2-input" style="width:100%;margin:0;">
                    </div>
                </div>
            </div>
        `,
        showCancelButton: true,
        confirmButtonText: 'Save Settings',
        didOpen: () => {
            const check = document.getElementById('branchVatRegistered');
            const rate = document.getElementById('branchVatRate');

            const sync = () => {
                if (check.checked) {
                    rate.disabled = false;
                    if (Number(rate.value || 0) <= 0) {
                        rate.value = 12;
                    }
                } else {
                    rate.value = 0;
                    rate.disabled = true;
                }
            };

            const logoInput = document.getElementById('receiptLogoImage');
            const logoData = document.getElementById('receiptLogoImageData');
            const previewWrap = document.getElementById('receiptLogoPreviewWrap');
            const previewImg = document.getElementById('receiptLogoPreview');
            const previewEmpty = document.getElementById('receiptLogoEmpty');
            const uploadText = document.getElementById('receiptLogoUploadText');
            const removeLogo = document.getElementById('removeReceiptLogo');

            if (logoInput) {
                logoInput.addEventListener('change', () => {
                    const file = logoInput.files && logoInput.files[0] ? logoInput.files[0] : null;

                    if (!file) {
                        return;
                    }

                    if (!file.type || !file.type.startsWith('image/')) {
                        Swal.showValidationMessage('Image file lang ang pwede sa logo.');
                        logoInput.value = '';
                        return;
                    }

                    if (file.size > 5 * 1024 * 1024) {
                        Swal.showValidationMessage('Logo image must be 5MB or smaller.');
                        logoInput.value = '';
                        return;
                    }

                    const setLogoPreview = dataUrl => {
                        logoData.value = dataUrl || '';
                        if (previewImg) {
                            previewImg.src = logoData.value;
                            previewImg.style.display = 'block';
                        }
                        if (previewEmpty) {
                            previewEmpty.style.display = 'none';
                        }
                        if (uploadText) {
                            uploadText.textContent = file.name || 'Logo selected';
                        }
                        if (removeLogo) {
                            removeLogo.checked = false;
                            const removeRow = removeLogo.closest('label');
                            if (removeRow) removeRow.style.display = 'flex';
                        }
                    };

                    const compressLogoForDatabase = sourceImage => {
                        /*
                            XAMPP/MySQL commonly uses 1MB max_allowed_packet.
                            The file input may accept up to 5MB, but before saving to DB
                            the image is resized/compressed so the POST/SQL packet stays safe.
                        */
                        const maxLogoDataUrlLength = 700 * 1024;
                        let maxSide = 900;
                        let quality = 0.86;
                        let finalDataUrl = '';

                        for (let attempt = 0; attempt < 12; attempt++) {
                            let width = sourceImage.width || maxSide;
                            let height = sourceImage.height || maxSide;
                            const scale = Math.min(1, maxSide / Math.max(width, height));

                            width = Math.max(1, Math.round(width * scale));
                            height = Math.max(1, Math.round(height * scale));

                            const canvas = document.createElement('canvas');
                            canvas.width = width;
                            canvas.height = height;

                            const ctx = canvas.getContext('2d');
                            ctx.fillStyle = '#ffffff';
                            ctx.fillRect(0, 0, width, height);
                            ctx.drawImage(sourceImage, 0, 0, width, height);

                            finalDataUrl = canvas.toDataURL('image/jpeg', quality);

                            if (finalDataUrl.length <= maxLogoDataUrlLength) {
                                return finalDataUrl;
                            }

                            quality = Math.max(0.58, quality - 0.06);
                            maxSide = Math.max(320, Math.round(maxSide * 0.82));
                        }

                        return finalDataUrl;
                    };

                    const img = new Image();
                    const objectUrl = URL.createObjectURL(file);

                    img.onload = () => {
                        try {
                            const dataUrl = compressLogoForDatabase(img);

                            if (!dataUrl || dataUrl.length > 900 * 1024) {
                                Swal.showValidationMessage('Logo is still too large after compression. Please use a smaller image.');
                                logoInput.value = '';
                                return;
                            }

                            setLogoPreview(dataUrl);
                        } catch (error) {
                            Swal.showValidationMessage('Hindi ma-process ang logo. Please try another image.');
                            logoInput.value = '';
                        } finally {
                            URL.revokeObjectURL(objectUrl);
                        }
                    };

                    img.onerror = () => {
                        URL.revokeObjectURL(objectUrl);
                        Swal.showValidationMessage('Hindi mabasa ang logo image. Please try another image.');
                        logoInput.value = '';
                    };

                    img.src = objectUrl;
                });
            }

            if (removeLogo) {
                removeLogo.addEventListener('change', () => {
                    if (removeLogo.checked) {
                        logoData.value = '';
                        if (logoInput) logoInput.value = '';
                        if (previewImg) {
                            previewImg.removeAttribute('src');
                            previewImg.style.display = 'none';
                        }
                        if (previewEmpty) previewEmpty.style.display = 'block';
                        if (uploadText) uploadText.textContent = 'Click to Upload Logo';
                    }
                });
            }

            check.addEventListener('change', sync);
            sync();
        },
        preConfirm: () => {
            const isVat = document.getElementById('branchVatRegistered').checked ? 1 : 0;
            let rate = Number(document.getElementById('branchVatRate').value || 0);

            if (isVat && rate <= 0) {
                Swal.showValidationMessage('Enter a valid VAT rate.');
                return false;
            }

            if (rate < 0 || rate > 100) {
                Swal.showValidationMessage('VAT rate must be between 0 and 100.');
                return false;
            }

            if (!isVat) {
                rate = 0;
            }

            return {
                is_vat_registered: isVat,
                vat_rate: rate,
                receipt_logo_image: document.getElementById('removeReceiptLogo') && document.getElementById('removeReceiptLogo').checked
                    ? ''
                    : (document.getElementById('receiptLogoImageData').value || ''),
                receipt_store_name: document.getElementById('receiptStoreName').value.trim(),
                receipt_address: document.getElementById('receiptAddress').value.trim(),
                receipt_tin: document.getElementById('receiptTin').value.trim(),
                receipt_serial_no: document.getElementById('receiptSerialNo').value.trim(),
                receipt_min_no: document.getElementById('receiptMinNo').value.trim(),
                receipt_permit_no: document.getElementById('receiptPermitNo').value.trim(),
                receipt_accr_no: document.getElementById('receiptAccrNo').value.trim(),
                receipt_supplier_name: document.getElementById('receiptSupplierName').value.trim(),
                receipt_supplier_address: document.getElementById('receiptSupplierAddress').value.trim(),
                receipt_supplier_tin: document.getElementById('receiptSupplierTin').value.trim(),
                receipt_footer_note: document.getElementById('receiptFooterNote').value.trim(),
                receipt_thank_you_text: document.getElementById('receiptThankYouText').value.trim(),
                receipt_notice_text: document.getElementById('receiptNoticeText').value.trim()
            };
        }
    });

    if (!result.isConfirmed || !result.value) {
        focusBarcodeInput();
        return;
    }

    const normalizeSettingValue = value => String(value ?? '').trim();
    const normalizeNumberSetting = value => Number(value || 0).toFixed(2);

    const changedGroups = [];
    const savedSettings = result.value || {};

    const vatChanged =
        Number(savedSettings.is_vat_registered || 0) !== Number(currentSettings.is_vat_registered || 0) ||
        normalizeNumberSetting(savedSettings.vat_rate) !== normalizeNumberSetting(currentSettings.vat_rate);

    const receiptFields = [
        'receipt_logo_image',
        'receipt_store_name',
        'receipt_address',
        'receipt_tin',
        'receipt_serial_no',
        'receipt_min_no',
        'receipt_permit_no',
        'receipt_accr_no',
        'receipt_supplier_name',
        'receipt_supplier_address',
        'receipt_supplier_tin',
        'receipt_footer_note',
        'receipt_thank_you_text',
        'receipt_notice_text'
    ];

    const receiptChanged = receiptFields.some(field => normalizeSettingValue(savedSettings[field]) !== normalizeSettingValue(currentSettings[field]));

    if (receiptChanged) {
        changedGroups.push('Receipt settings');
    }

    if (vatChanged) {
        changedGroups.push('VAT settings');
    }

    const savedMessage = changedGroups.length
        ? changedGroups.join(' and ') + ' saved successfully.'
        : 'Settings saved successfully.';

    try {
        const data = await api({
            action: 'save_branch_settings',
            ...savedSettings
        });

        if (!data.success) {
            Swal.fire('Error', data.message || 'Branch settings were not saved.', 'error');
            return;
        }

        posVatRegistered = Number(data.is_vat_registered || 0) === 1;
        posVatRatePercent = Number(data.vat_rate || 0);
        posVatRate = posVatRatePercent / 100;
        posReceiptInfo = {
            logo_image: data.receipt_logo_image || '',
            store_name: data.receipt_store_name || 'AMGC STORE',
            address: data.receipt_address || '',
            tin: data.receipt_tin || '',
            serial_no: data.receipt_serial_no || '',
            min_no: data.receipt_min_no || '',
            permit_no: data.receipt_permit_no || '',
            accr_no: data.receipt_accr_no || '',
            supplier_name: data.receipt_supplier_name || '',
            supplier_address: data.receipt_supplier_address || '',
            supplier_tin: data.receipt_supplier_tin || '',
            footer_note: data.receipt_footer_note || '',
            thank_you_text: data.receipt_thank_you_text || 'Thank You!',
            notice_text: data.receipt_notice_text || 'This is not an official receipt.'
        };

        updateTotals();

        Swal.fire({
            title: 'Settings Saved',
            html: `
                <div style="text-align:center;">
                    <div style="font-weight:700;margin-bottom:6px;">${escapeHtml(savedMessage)}</div>
                    ${vatChanged ? `<div style="font-size:13px;opacity:.75;">VAT: ${posVatRegistered ? `Registered • ${fmt(posVatRatePercent).replace('.00','')}%` : 'Non-VAT branch'}</div>` : ''}
                </div>
            `,
            icon: 'success'
        }).then(() => focusBarcodeInput());
    } catch (error) {
        Swal.fire('Error', error && error.message ? error.message : 'Branch settings were not saved.', 'error');
    }
}

function showNotDone(title) {
    Swal.fire({
        title: title,
        text: 'Not done yet',
        icon: 'info'
    });
}

async function openPickItem() {
    let products = [];

    try {
        const data = await api({
            action: 'search_product',
            term: '',
            price_level: currentPriceLevel
        });

        if (data.products && data.products.length > 0) {
            products = data.products;
        }
    } catch (error) {
        console.warn('Pick item product load failed.', error);
    }

    if (products.length === 0) {
        Swal.fire({
            title: 'Pick Item',
            text: 'No active products found.',
            icon: 'info'
        }).then(() => focusBarcodeInput());
        return;
    }

    const buildPickUomRows = sourceProducts => {
        const rows = [];

        (sourceProducts || []).forEach(product => {
            const uoms = normalizeProductUoms(product);

            uoms.forEach((uom, uomIndex) => {
                const selectedUomKey = String(uom.uom_key || (uom.uom_id ? 'uom_' + uom.uom_id : 'uom_index_' + uomIndex));
                const selectedPrice = getUomPriceForLevel(uom, currentPriceLevel);
                const selectedBarcode = String(uom.barcode || product.barcode || product.item_code || '');

                rows.push({
                    ...product,
                    selected_uom_key: selectedUomKey,
                    uom_id: Number(uom.uom_id || 0),
                    uom_name: uom.uom_name || uom.uom_initial || product.unit_type || '',
                    uom_initial: uom.uom_initial || uom.uom_name || product.uom_initial || '',
                    unit_price: selectedPrice,
                    stock_qty: Number(uom.stock_qty || 0),
                    conversion_qty: Math.max(1, Number(uom.conversion_qty || 1)),
                    barcode: selectedBarcode,
                    pick_base_barcode: product.barcode || '',
                    pick_uom_barcode: uom.barcode || '',
                    pick_uom_key: selectedUomKey,
                    pick_uom_price: selectedPrice,
                    pick_price_level: currentPriceLevel,
                    pick_search_text: [
                        product.item_name,
                        product.item_code,
                        product.barcode,
                        product.description,
                        uom.barcode,
                        uom.uom_name,
                        uom.uom_initial,
                        currentPriceLevel
                    ].join(' ').toLowerCase()
                });
            });
        });

        return rows;
    };

    window.currentPickProducts = buildPickUomRows(products);
    window.filteredPickProducts = window.currentPickProducts.slice(0, 80);

    window.pickDatabaseProduct = function(index) {
        const product = (window.filteredPickProducts || [])[index];

        if (!product) {
            return;
        }

        addProduct(product);
        Swal.close();
        focusBarcodeInput();
    };

    window.renderPickItemList = function(term = '') {
        const query = String(term || '').trim().toLowerCase();
        const source = window.currentPickProducts || [];
        const filtered = source.filter(product => {
            const haystack = product.pick_search_text || [
                product.item_name,
                product.item_code,
                product.barcode,
                product.pick_uom_barcode,
                product.description,
                product.uom_name,
                product.uom_initial
            ].join(' ').toLowerCase();

            return query === '' || haystack.includes(query);
        }).slice(0, 120);

        window.filteredPickProducts = filtered;

        const list = document.getElementById('pickItemList');

        if (!list) {
            return;
        }

        if (!filtered.length) {
            list.innerHTML = '<div class="pick-item-empty">No matching item found.</div>';
            return;
        }

        list.innerHTML = filtered.map((product, index) => {
            const uomLabel = product.uom_initial || product.uom_name || 'UoM';
            const code = product.pick_uom_barcode || product.barcode || product.item_code || '';
            const stock = Number(product.stock_qty || 0);
            const price = getUomPriceForLevel(product, currentPriceLevel);
            const meta = [
                code ? `Barcode: ${escapeHtml(code)}` : '',
                `Stock: ${fmt(stock)} ${escapeHtml(uomLabel)}`,
                `Price Level: ${escapeHtml(currentPriceLevel)}`
            ].filter(Boolean).join(' • ');

            return `
                <button type="button" class="pick-item-row pick-item-uom-row" onclick="window.pickDatabaseProduct(${index})">
                    <span class="pick-item-left">
                        <span class="pick-item-name">${escapeHtml(product.item_name || 'Unnamed Item')}<span class="item-uom">${escapeHtml(uomLabel)}</span></span>
                        <span class="pick-item-meta">${meta}</span>
                    </span>
                    <span class="pick-item-right">
                        <span class="pick-item-price">₱${fmt(price)}</span>
                        <span class="pick-item-stock">${fmt(stock)} ${escapeHtml(uomLabel)}</span>
                    </span>
                </button>
            `;
        }).join('');
    };

    await Swal.fire({
        title: 'Pick Item',
        html: `
            <div class="pick-item-panel">
                <div class="pick-price-level-badge">
                    <span>Price Level</span>
                    <b>${escapeHtml(currentPriceLevel)}</b>
                </div>
                <input
                    id="pickItemSearch"
                    class="pick-item-search"
                    type="text"
                    placeholder="Search item name, barcode, or UoM"
                    autocomplete="off"
                >
                <div id="pickItemList" class="pick-item-list"></div>
            </div>
        `,
        showConfirmButton: false,
        showCloseButton: true,
        width: 760,
        customClass: {
            popup: 'pick-item-swal'
        },
        didOpen: () => {
            window.renderPickItemList('');
            const searchInput = document.getElementById('pickItemSearch');

            if (searchInput) {
                searchInput.focus();
                searchInput.addEventListener('input', () => window.renderPickItemList(searchInput.value));
                searchInput.addEventListener('keydown', event => {
                    const rows = Array.from(document.querySelectorAll('.pick-item-row'));
                    const activeIndex = rows.findIndex(row => row.classList.contains('active'));

                    if (event.key === 'ArrowDown') {
                        event.preventDefault();
                        if (!rows.length) return;
                        const nextIndex = activeIndex < 0 ? 0 : (activeIndex + 1) % rows.length;
                        rows.forEach(row => row.classList.remove('active'));
                        rows[nextIndex].classList.add('active');
                        rows[nextIndex].scrollIntoView({ block: 'nearest' });
                        return;
                    }

                    if (event.key === 'ArrowUp') {
                        event.preventDefault();
                        if (!rows.length) return;
                        const nextIndex = activeIndex < 0 ? rows.length - 1 : (activeIndex - 1 + rows.length) % rows.length;
                        rows.forEach(row => row.classList.remove('active'));
                        rows[nextIndex].classList.add('active');
                        rows[nextIndex].scrollIntoView({ block: 'nearest' });
                        return;
                    }

                    if (event.key === 'Enter') {
                        event.preventDefault();
                        if (activeIndex >= 0 && rows[activeIndex]) {
                            rows[activeIndex].click();
                            return;
                        }

                        if (rows[0]) {
                            rows[0].click();
                        }
                    }
                });
            }
        },
        willClose: () => {
            delete window.renderPickItemList;
            delete window.pickDatabaseProduct;
        }
    });
}

async function openPriceInquiry() {
    let products = [];

    try {
        const data = await api({
            action: 'search_product',
            term: ''
        });

        if (data.products && data.products.length > 0) {
            products = data.products;
        }
    } catch (error) {
        console.warn('Price inquiry product load failed.', error);
    }

    if (products.length === 0) {
        showNotDone('Price Inquiry');
        return;
    }

    const productList = products.slice(0, 20).map(p => {
        const inquiryUom = getDefaultProductUom(p);
        return `
            <div style="display:flex;justify-content:space-between;gap:14px;border-bottom:1px solid #dddddd;padding:8px 0;text-align:left;">
                <span>${escapeHtml(p.item_name)} <small>${escapeHtml(inquiryUom.uom_initial || inquiryUom.uom_name || '')}</small></span>
                <b>₱${fmt(getUomPriceForLevel(inquiryUom, currentPriceLevel))}</b>
            </div>
        `;
    }).join('');

    Swal.fire({
        title: 'Price Inquiry',
        html: `<div style="font-size:14px;">${productList}</div>`,
        icon: 'info',
        width: 460
    });
}


async function openCashCount() {
    if (!requireOpenShift()) {
        return;
    }

    const result = await Swal.fire({
        title: 'Cash Count Denomination',
        width: 780,
        customClass: {
            popup: 'cash-count-swal'
        },
        html: `
            <div class="pos-modal-panel">
                <div class="pos-currency-line" style="margin-top:0;">
                    <span class="pos-currency-check"><i class="fa-solid fa-check"></i></span>
                    <span>Currency: Philippine Peso (₱)</span>
                </div>
                <div class="pos-cash-denomination-wrap">
                    <div class="pos-cash-denomination-title">
                        <span>Cash Count Denomination</span>
                        <small>Enter quantity for each denomination</small>
                    </div>
                    <div class="pos-cash-grid">
                        <table class="pos-cash-table">
                            <thead><tr><th>Paper Bills</th><th>Qty</th><th>Amount</th></tr></thead>
                            <tbody>${renderCashBreakdownRows('count_bills', shiftBillDenominations)}</tbody>
                        </table>
                        <table class="pos-cash-table">
                            <thead><tr><th>Coins</th><th>Qty</th><th>Amount</th></tr></thead>
                            <tbody>${renderCashBreakdownRows('count_coins', shiftCoinDenominations)}</tbody>
                        </table>
                    </div>
                    <div class="pos-cash-total-row">
                        <span>CASH COUNT TOTAL</span>
                        <input id="shiftActualCash" type="text" inputmode="decimal" value="0.00" readonly>
                    </div>
                </div>
                <textarea id="cashCountNotes" class="swal2-textarea" placeholder="Notes" style="margin:6px 0 0;width:100%;height:34px;min-height:34px;resize:none;"></textarea>
            </div>
        `,
        showCancelButton: true,
        confirmButtonText: 'Save Count',
        didOpen: setupCloseShiftCashDenomination,
        preConfirm: () => {
            updateCloseShiftCashDenomination();
            const amount = parseMoneyInputValue(document.getElementById('shiftActualCash').value || '0');
            const notes = document.getElementById('cashCountNotes').value || '';
            const denominationNote = buildCloseShiftDenominationNote();
            const finalNotes = notes.trim() ? `${denominationNote} | Notes: ${notes.trim()}` : denominationNote;

            if (!Number.isFinite(amount) || amount <= 0) {
                Swal.showValidationMessage('Enter at least one denomination quantity.');
                return false;
            }

            return { amount, notes: finalNotes };
        }
    });

    if (!result.isConfirmed) return;

    const data = await api({ action: 'cash_count', amount: result.value.amount, notes: result.value.notes });
    Swal.fire(data.success ? 'Saved' : 'Error', data.message || '', data.success ? 'success' : 'error');
}

async function openCashTransfer() {
    if (!requireOpenShift()) {
        return;
    }

    const r = await Swal.fire({
        title: 'Cash Transfer',
        width: 720,
        html: `
            <div style="text-align:left; display:grid; gap:12px;">
                <label style="font-weight:800; color:#0b2f4a; margin:0;">Transfer Type</label>
                <select id="cashTransferType" class="swal2-select" style="width:100%; margin:0;">
                    <option value="Pick-Up">Pick-Up</option>
                    <option value="Deposit">Deposit</option>
                </select>

                <label style="font-weight:800; color:#0b2f4a; margin:0;">Amount</label>
                <input id="cashTransferAmount" type="number" step="0.01" min="0" class="swal2-input" style="width:100%; margin:0;" placeholder="Enter amount">

                <label style="font-weight:800; color:#0b2f4a; margin:0;">Transfer Notes / Receiver</label>
                <textarea id="cashTransferNotes" class="swal2-textarea" style="width:100%; margin:0; min-height:110px;" placeholder="Example: Supplier payment / Receiver name / Deposit remarks"></textarea>
            </div>
        `,
        showCancelButton: true,
        confirmButtonText: 'Save Transfer',
        preConfirm: () => {
            const transferType = document.getElementById('cashTransferType')?.value || 'Pick-Up';
            const amount = Number(document.getElementById('cashTransferAmount')?.value || 0);
            const notes = (document.getElementById('cashTransferNotes')?.value || '').trim();

            if (!amount || amount <= 0) {
                Swal.showValidationMessage('Amount must be greater than zero.');
                return false;
            }

            if (!notes) {
                Swal.showValidationMessage('Transfer notes / receiver is required.');
                return false;
            }

            return { transferType, amount, notes };
        }
    });

    if (!r.isConfirmed) return;

    const finalNotes = `TRANSFER_TYPE|${r.value.transferType}|${r.value.notes}`;
    const data = await api({ action: 'cash_transfer', amount: r.value.amount, notes: finalNotes });
    Swal.fire(data.success ? 'Saved' : 'Error', data.message || '', data.success ? 'success' : 'error');
}

async function openDrawer() {
    if (!requireOpenShift()) {
        return;
    }

    const data = await api({ action: 'drawer_open', amount: 0, notes: 'Drawer opened from POS.' });
    Swal.fire(data.success ? 'Drawer Logged' : 'Error', data.message || '', data.success ? 'success' : 'error');
}


async function openCustomerLookup() {
    const tenderModal = document.getElementById('tenderModal');
    const customerInput = document.getElementById('customerName');

    if (tenderModal && customerInput && tenderModal.classList.contains('show')) {
        customerInput.focus();
        customerInput.select();
        await searchCustomerSuggestions(customerInput.value || '');
        return;
    }

    window.currentPOSCustomers = [];
    window.customerLookupSuggestIndex = -1;

    const popup = await Swal.fire({
        title: 'Search Customer',
        width: 720,
        customClass: {
            popup: 'customer-lookup-swal'
        },
        html: `
            <div class="pos-modal-panel customer-lookup-panel" style="padding:0;background:#f8fafc;">
                <input id="swalCustomerSearchInput"
                       class="swal2-input customer-lookup-input"
                       style="width:calc(100% - 2em);margin:0 1em 10px;"
                       placeholder="Type customer name, code, store name, or phone number"
                       autocomplete="off">
                <div id="swalCustomerSuggest" class="pos-customer-list customer-lookup-results" style="height:420px;min-height:420px;max-height:420px;overflow-y:auto;text-align:left;"></div>
            </div>
        `,
        showConfirmButton: false,
        showCloseButton: true,
        didOpen: () => {
            const input = document.getElementById('swalCustomerSearchInput');
            if (!input) return;

            const render = async () => {
                const term = input.value || '';
                const list = document.getElementById('swalCustomerSuggest');
                if (!list) return;

                list.innerHTML = '<div class="suggest-empty">Searching customers...</div>';

                try {
                    const data = await api({ action: 'search_customers', term });
                    const customers = data.success && Array.isArray(data.customers) ? data.customers : [];
                    window.currentPOSCustomers = customers;
                    window.customerLookupSuggestIndex = -1;

                    const exactIndex = findExactCustomerByScan(customers, term);
                    if (exactIndex >= 0 && isLikelyCustomerScannerInput(term) && customerScannerAutoSelectedTerm !== normalizeCustomerScanValue(term)) {
                        customerScannerAutoSelectedTerm = normalizeCustomerScanValue(term);
                        window.selectPOSCustomer(exactIndex);
                        return;
                    }

                    if (!customers.length) {
                        list.innerHTML = '<div class="suggest-empty">No customer found.</div>';
                        return;
                    }

                    list.innerHTML = customers.map((c, index) => {
                        const infoParts = [];

                        if (c.customer_code) {
                            infoParts.push(`<span class="customer-info-pill">${escapeHtml(c.customer_code)}</span>`);
                        }

                        infoParts.push(`<span class="customer-info-pill">Points: ${fmt(c.points_balance || 0)}</span>`);

                        if (c.store_name) {
                            infoParts.push(`<span class="customer-info-muted">Store: ${escapeHtml(c.store_name)}</span>`);
                        }

                        if (c.phone_number) {
                            infoParts.push(`<span class="customer-info-muted">Phone: ${escapeHtml(c.phone_number)}</span>`);
                        }

                        if (c.address) {
                            infoParts.push(`<span class="customer-info-muted">Address: ${escapeHtml(c.address)}</span>`);
                        }

                        return `
                            <button type="button" class="pos-customer-card" onclick="window.selectPOSCustomer(${index})">
                                <b>${escapeHtml(c.customer_name || '')}</b>
                                <small class="customer-info-line">${infoParts.join('')}</small>
                            </button>
                        `;
                    }).join('');
                } catch (error) {
                    console.warn('Customer search failed.', error);
                    list.innerHTML = '<div class="suggest-empty">Customer search failed.</div>';
                }
            };

            let customerSearchTimer = null;

            input.addEventListener('input', event => {
                markCustomerInputTyping(event);
                clearTimeout(customerSearchTimer);
                customerSearchTimer = setTimeout(render, isLikelyCustomerScannerInput(input.value || '') ? 40 : 250);
            });

            input.addEventListener('keydown', e => {
                const cards = Array.from(document.querySelectorAll('#swalCustomerSuggest .pos-customer-card'));

                if (e.key === 'ArrowDown' && cards.length) {
                    e.preventDefault();
                    window.customerLookupSuggestIndex = ((window.customerLookupSuggestIndex || -1) + 1) % cards.length;
                    cards.forEach(card => card.classList.remove('active'));
                    cards[window.customerLookupSuggestIndex].classList.add('active');
                    cards[window.customerLookupSuggestIndex].scrollIntoView({ block: 'nearest' });
                    return;
                }

                if (e.key === 'ArrowUp' && cards.length) {
                    e.preventDefault();
                    window.customerLookupSuggestIndex = ((window.customerLookupSuggestIndex || 0) - 1 + cards.length) % cards.length;
                    cards.forEach(card => card.classList.remove('active'));
                    cards[window.customerLookupSuggestIndex].classList.add('active');
                    cards[window.customerLookupSuggestIndex].scrollIntoView({ block: 'nearest' });
                    return;
                }

                if (e.key === 'Enter') {
                    e.preventDefault();
                    selectCustomerByExactScanValue(input.value || '').then(selectedExact => {
                        if (selectedExact) {
                            return;
                        }

                        if (cards.length) {
                            const selectedIndex = window.customerLookupSuggestIndex >= 0 ? window.customerLookupSuggestIndex : 0;
                            cards[selectedIndex].click();
                        }
                    });
                    return;
                }
            });

            setTimeout(() => {
                input.focus();
                render();
            }, 60);
        }
    });

    return popup;
}

let customerSuggestIndex = -1;
let customerSuggestTimer = null;
let customerScannerStartAt = 0;
let customerScannerLastAt = 0;
let customerScannerCharCount = 0;
let customerScannerAutoSelectedTerm = '';

function normalizeCustomerScanValue(value) {
    return String(value || '').trim();
}

function findExactCustomerByScan(customers, value) {
    const needle = normalizeCustomerScanValue(value).toLowerCase();

    if (!needle || !Array.isArray(customers)) {
        return -1;
    }

    return customers.findIndex(c => {
        const code = String(c.customer_code || '').trim().toLowerCase();
        const phone = String(c.phone_number || '').trim().toLowerCase();
        return (code && code === needle) || (phone && phone === needle);
    });
}

async function selectCustomerByExactScanValue(value, closeSwal = true) {
    const term = normalizeCustomerScanValue(value);

    if (!term) {
        return false;
    }

    let customers = Array.isArray(window.currentPOSCustomers) ? window.currentPOSCustomers : [];
    let exactIndex = findExactCustomerByScan(customers, term);

    if (exactIndex < 0) {
        try {
            const data = await api({ action: 'search_customers', term });
            customers = data.success && Array.isArray(data.customers) ? data.customers : [];
            window.currentPOSCustomers = customers;
            exactIndex = findExactCustomerByScan(customers, term);
        } catch (error) {
            console.warn('Exact customer scan lookup failed.', error);
        }
    }

    if (exactIndex < 0) {
        return false;
    }

    customerScannerAutoSelectedTerm = term;
    window.selectPOSCustomer(exactIndex);

    if (!closeSwal && typeof Swal !== 'undefined' && document.body.classList.contains('swal2-shown')) {
        // window.selectPOSCustomer already closes Swal by default. This branch keeps intent explicit.
    }

    return true;
}

function markCustomerInputTyping(event) {
    const now = Date.now();

    if (!customerScannerStartAt || now - customerScannerLastAt > 120) {
        customerScannerStartAt = now;
        customerScannerCharCount = 0;
    }

    customerScannerLastAt = now;
    customerScannerCharCount += 1;
}

function isLikelyCustomerScannerInput(value) {
    const term = normalizeCustomerScanValue(value);
    const elapsed = customerScannerStartAt ? Date.now() - customerScannerStartAt : 9999;

    return term.length >= 4 && customerScannerCharCount >= 4 && elapsed <= 900;
}

function getCustomerSuggestItems() {
    return Array.from(document.querySelectorAll('#customerSuggest .suggest-item'));
}

function clearCustomerSuggestActive() {
    getCustomerSuggestItems().forEach(item => item.classList.remove('active'));
}

function setCustomerSuggestActive(index) {
    const items = getCustomerSuggestItems();

    if (!items.length) {
        customerSuggestIndex = -1;
        return;
    }

    customerSuggestIndex = ((index % items.length) + items.length) % items.length;
    clearCustomerSuggestActive();
    items[customerSuggestIndex].classList.add('active');
    items[customerSuggestIndex].scrollIntoView({ block: 'nearest' });
}

function positionCustomerSuggestDropdown() {
    const input = document.getElementById('customerName');
    const box = document.getElementById('customerSuggest');

    if (!input || !box) {
        return;
    }

    const rect = input.getBoundingClientRect();
    const width = Math.min(Math.max(rect.width, 520), window.innerWidth - 20);
    const left = Math.max(10, Math.min(rect.left, window.innerWidth - width - 10));
    const maxHeight = Math.max(180, window.innerHeight - rect.bottom - 18);

    box.style.left = left + 'px';
    box.style.top = (rect.bottom + 2) + 'px';
    box.style.width = width + 'px';
    box.style.maxHeight = Math.min(320, maxHeight) + 'px';
}

function hideCustomerSuggestDropdown() {
    const box = document.getElementById('customerSuggest');

    customerSuggestIndex = -1;

    if (box) {
        box.style.display = 'none';
    }

    clearCustomerSuggestActive();
}

async function searchCustomerSuggestions(term = '') {
    const box = document.getElementById('customerSuggest');

    if (!box) {
        return;
    }

    customerSuggestIndex = -1;
    box.innerHTML = '<div class="suggest-empty">Searching customers...</div>';
    positionCustomerSuggestDropdown();
    box.style.display = 'block';

    try {
        const data = await api({
            action: 'search_customers',
            term: term || ''
        });

        const customers = data.success && Array.isArray(data.customers) ? data.customers : [];
        window.currentPOSCustomers = customers;

        if (!customers.length) {
            box.innerHTML = '<div class="suggest-empty">No customer found.</div>';
            return;
        }

        box.innerHTML = `
            <div class="suggest-header">
                <span>Customer</span>
                <span style="text-align:right;">Code</span>
                <span style="text-align:right;">Phone</span>
            </div>
        `;

        customers.forEach((c, index) => {
            const div = document.createElement('div');
            div.className = 'suggest-item';
            div.innerHTML = `
                <div class="suggest-main">
                    <span class="suggest-name">${escapeHtml(c.customer_name || '')}</span>
                    <span class="customer-store" style="color:#334155;font-weight:700;opacity:1;">${escapeHtml(c.store_name || c.address || '')}</span>
                </div>
                <div class="suggest-price" style="color:#0f172a;font-weight:800;">${escapeHtml(c.customer_code || '-')}<br><small>Pts: ${fmt(c.points_balance || 0)}</small></div>
                <div class="suggest-stock" style="color:#0f172a;font-weight:800;">${escapeHtml(c.phone_number || '-')}</div>
            `;

            div.addEventListener('mouseenter', () => {
                const items = getCustomerSuggestItems();
                const hoverIndex = items.indexOf(div);

                if (hoverIndex >= 0) {
                    setCustomerSuggestActive(hoverIndex);
                }
            });

            div.onclick = () => window.selectPOSCustomer(index);
            box.appendChild(div);
        });

        positionCustomerSuggestDropdown();
        box.style.display = 'block';
    } catch (error) {
        console.warn('Customer search failed.', error);
        box.innerHTML = '<div class="suggest-empty">Customer search failed.</div>';
    }
}

function handleCustomerSearchFocus() {
    const input = document.getElementById('customerName');

    if (!input) {
        return;
    }

    clearTimeout(customerSuggestTimer);
    customerSuggestTimer = setTimeout(() => {
        searchCustomerSuggestions(input.value || '');
    }, 80);
}

function handleCustomerSearchInput(event) {
    markCustomerInputTyping(event);
    selectedCustomerId = 0;
    selectedCustomerCode = '';
    selectedCustomerPoints = 0;
    selectedPointsToRedeem = 0;
    updateAvailablePointsDisplay();
    clearTimeout(customerSuggestTimer);
    customerSuggestTimer = setTimeout(async () => {
        const term = event.target.value || '';
        await searchCustomerSuggestions(term);

        if (isLikelyCustomerScannerInput(term) && customerScannerAutoSelectedTerm !== normalizeCustomerScanValue(term)) {
            await selectCustomerByExactScanValue(term);
        }
    }, isLikelyCustomerScannerInput(event.target.value || '') ? 40 : 250);
}

function handleCustomerSearchKeydown(event) {
    const box = document.getElementById('customerSuggest');
    const isOpen = box && box.style.display !== 'none';
    const items = getCustomerSuggestItems();

    if (event.key === 'ArrowDown' && isOpen && items.length) {
        event.preventDefault();
        setCustomerSuggestActive(customerSuggestIndex + 1);
        return;
    }

    if (event.key === 'ArrowUp' && isOpen && items.length) {
        event.preventDefault();
        setCustomerSuggestActive(customerSuggestIndex - 1);
        return;
    }

    if (event.key === 'Enter') {
        event.preventDefault();
        const input = document.getElementById('customerName');
        const term = input ? input.value || '' : '';

        selectCustomerByExactScanValue(term).then(selectedExact => {
            if (selectedExact) {
                return;
            }

            if (isOpen && items.length) {
                const selectedIndex = customerSuggestIndex >= 0 ? customerSuggestIndex : 0;
                items[selectedIndex].click();
                return;
            }

            handleTenderEnter(event);
        });
        return;
    }

    if (event.key === 'Escape') {
        hideCustomerSuggestDropdown();
        return;
    }

    handleTenderEnter(event);
}


window.selectPOSCustomer = function(index) {
    const customer = (window.currentPOSCustomers || [])[index];

    if (!customer) {
        return;
    }

    selectedCustomerName = customer.customer_name || 'Walk-in Customer';
    selectedCustomerId = Number(customer.customer_id || 0);
    selectedCustomerCode = customer.customer_code || '';
    selectedCustomerPoints = Number(customer.points_balance || 0);
    selectedPointsToRedeem = 0;

    const input = document.getElementById('customerName');

    if (input) {
        input.value = selectedCustomerName;
    }

    updateAvailablePointsDisplay();
    const pointsInput = document.getElementById('pointsRedeemInput');
    if (pointsInput) {
        pointsInput.value = '';
    }
    const dueInput = document.getElementById('tenderDue');
    if (dueInput) {
        dueInput.value = fmt(totalDueAfterPoints());
    }
    updateTenderChange();

    hideCustomerSuggestDropdown();

    if (typeof Swal !== 'undefined' && document.body.classList.contains('swal2-shown')) {
        Swal.close();
    }

    const tenderModal = document.getElementById('tenderModal');

    if (tenderModal && tenderModal.classList.contains('show')) {
        setTimeout(() => {
            const amountInput = document.getElementById('tenderedAmount');
            if (amountInput) {
                amountInput.focus();
                amountInput.select();
            }
        }, 80);
    }
};

function saleTableHtml(sales, mode, source = '') {
    if (!sales.length) {
        return `
            <div class="pos-modal-table-wrap recent-sales-stable-height">
                <table class="pos-modal-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Receipt</th>
                            <th>Customer</th>
                            <th>Method</th>
                            <th>Status</th>
                            <th class="pos-table-amount">Points Used</th>
                            <th class="pos-table-amount">Points Discount</th>
                            <th class="pos-table-amount">Amount</th>
                            <th style="text-align:center;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td colspan="9" style="height:260px;text-align:center;font-weight:800;color:#64748b;">No sales found.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        `;
    }

    const actionLabel = mode === 'view' ? 'View' : mode === 'void' ? 'Void' : 'Return';

    return `
        <div class="pos-modal-table-wrap recent-sales-stable-height">
            <table class="pos-modal-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Receipt</th>
                        <th>Customer</th>
                        <th>Method</th>
                        <th>Status</th>
                        <th class="pos-table-amount">Points Used</th>
                        <th class="pos-table-amount">Points Discount</th>
                        <th class="pos-table-amount">Amount</th>
                        <th style="text-align:center;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    ${sales.map(s => `
                        <tr>
                            <td>${escapeHtml((typeof formatSaleDateTime === 'function' ? formatSaleDateTime(s.created_at) : (s.created_at || '')))}</td>
                            <td>${escapeHtml(s.receipt_no)}</td>
                            <td>${escapeHtml(s.customer_name || '')}</td>
                            <td>${escapeHtml(s.payment_method || '')}</td>
                            <td>${escapeHtml(s.status || '')}</td>
                            <td class="pos-table-amount">${Number(s.points_redeemed || 0) > 0 ? fmt(s.points_redeemed) + ' pts' : '-'}</td>
                            <td class="pos-table-amount">${Number(s.points_discount_amount || 0) > 0 ? '₱' + fmt(s.points_discount_amount) : '-'}</td>
                            <td class="pos-table-amount">₱${fmt(s.amount_due)}</td>
                            <td style="text-align:center;">
                                <button type="button" class="pos-action-mini" style="min-width:76px!important;min-height:36px!important;padding:7px 12px!important;font-size:13px!important;" onclick="window.handleSaleAction(${Number(s.sale_id)}, '${mode}', '${source}')">
                                    ${actionLabel}
                                </button>
                            </td>
                        </tr>
                    `).join('')}
                </tbody>
            </table>
        </div>
    `;
}

function recentSalesFilterHtml(selectedFilter = 'today') {
    const filters = [
        ['today', 'Today'],
        ['yesterday', 'Yesterday'],
        ['last_7_days', 'Last 7 Days'],
        ['this_month', 'This Month'],
        ['all', 'All']
    ];

    return `
        <div class="pos-modal-panel" style="margin-bottom:10px;">
            <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
                <div style="font-weight:800;color:#003354;">Filter by Day</div>
                <select id="recentSalesDayFilter" class="swal2-select" style="width:210px;margin:0;">
                    ${filters.map(([value, label]) => `<option value="${value}" ${value === selectedFilter ? 'selected' : ''}>${label}</option>`).join('')}
                </select>
            </div>
        </div>
    `;
}

async function loadRecentSalesByFilter(dateFilter = 'today') {
    posLastRecentSalesFilter = dateFilter || 'today';
    const data = await api({
        action: 'recent_sales',
        date_filter: dateFilter
    });

    if (!data.success) {
        Swal.fire('Error', data.message || '', 'error');
        return;
    }

    const body = document.getElementById('recentSalesModalBody');

    if (body) {
        body.innerHTML = saleTableHtml(data.sales || [], 'view', 'recent_sales');
    }
}

async function openRecentSales(selectedFilter = 'today') {
    const defaultFilter = selectedFilter || 'today';
    posLastRecentSalesFilter = defaultFilter;

    const data = await api({
        action: 'recent_sales',
        date_filter: defaultFilter
    });

    if (!data.success) {
        Swal.fire('Error', data.message || '', 'error');
        return;
    }

    Swal.fire({
        title: 'Recent Sales',
        html: `
            ${recentSalesFilterHtml(defaultFilter)}
            <div id="recentSalesModalBody">${saleTableHtml(data.sales || [], 'view', 'recent_sales')}</div>
        `,
        width: '94vw',
        customClass: {
            popup: 'pos-swal sales-order-swal recent-sales-swal'
        },
        showConfirmButton: false,
        showCloseButton: true,
        didOpen: () => {
            const filterSelect = document.getElementById('recentSalesDayFilter');

            if (filterSelect) {
                filterSelect.addEventListener('change', () => {
                    posLastRecentSalesFilter = filterSelect.value || 'today';
                    loadRecentSalesByFilter(posLastRecentSalesFilter);
                });
            }
        }
    });
}

function salesOrderHeaderHtml() {
    return '';
}

async function openSalesOrder() {
    try {
        if (typeof Swal === 'undefined') {
            alert('SweetAlert is not loaded. Please refresh the page.');
            return;
        }

        Swal.fire({
            title: 'Loading Sales Order...',
            allowOutsideClick: false,
            allowEscapeKey: false,
            showConfirmButton: false,
            didOpen: () => Swal.showLoading()
        });

        const data = await api({
            action: 'recent_sales',
            date_filter: 'all'
        });

        if (!data.success) {
            Swal.fire('Error', data.message || 'Unable to load sales orders.', 'error');
            return;
        }

        Swal.fire({
            title: 'Sales Order',
            html: `
                <div id="salesOrderModalBody">${saleTableHtml(data.sales || [], 'view', 'sales_order')}</div>
            `,
            width: '94vw',
            customClass: {
                popup: 'pos-swal sales-order-swal'
            },
            showConfirmButton: false,
            showCloseButton: true
        });
    } catch (error) {
        console.error('openSalesOrder failed:', error);
        if (typeof Swal !== 'undefined') {
            Swal.fire('Error', error.message || 'Unable to open Sales Order modal.', 'error');
        } else {
            alert(error.message || 'Unable to open Sales Order modal.');
        }
    }
}

window.openSalesOrder = openSalesOrder;

async function openVoidSale() {
    const data = await api({ action: 'recent_sales' });

    if (!data.success) {
        Swal.fire('Error', data.message || '', 'error');
        return;
    }

    Swal.fire({
        title: 'Void Sale',
        html: saleTableHtml((data.sales || []).filter(s => s.status === 'completed'), 'void', 'void_sale'),
        width: 1080,
        showConfirmButton: false,
        showCloseButton: true
    });
}

async function openReturnSale() {
    const data = await api({ action: 'recent_sales' });

    if (!data.success) {
        Swal.fire('Error', data.message || '', 'error');
        return;
    }

    Swal.fire({
        title: 'Return Sales',
        html: saleTableHtml((data.sales || []).filter(s => s.status === 'completed'), 'return', 'return_sale'),
        width: 1080,
        showConfirmButton: false,
        showCloseButton: true
    });
}

window.handleSaleAction = async function(saleId, mode, source = '') {
    if (mode === 'view') {
        const data = await api({
            action: 'get_sale',
            sale_id: saleId
        });

        if (!data.success) {
            Swal.fire('Error', data.message || '', 'error');
            return;
        }

        const rows = (data.items || []).map(i => `
            <tr>
                <td>${escapeHtml(i.item_name || '')}</td>
                <td class="pos-table-amount">${fmt(i.quantity)}</td>
                <td class="pos-table-amount">₱${fmt(i.unit_price)}</td>
                <td class="pos-table-amount">₱${fmt(i.line_total)}</td>
            </tr>
        `).join('');

        await Swal.fire({
            title: data.sale.receipt_no,
            html: `
                <div class="pos-modal-panel">
                    <div class="pos-report-grid">
                        <div class="pos-report-card">
                            <span>Customer</span>
                            <b>${escapeHtml(data.sale.customer_name || '')}</b>
                        </div>
                        <div class="pos-report-card">
                            <span>Payment</span>
                            <b>${escapeHtml(data.sale.payment_method || '')}</b>
                        </div>
                        <div class="pos-report-card">
                            <span>Amount Due</span>
                            <b>₱${fmt(data.sale.amount_due)}</b>
                        </div>
                    </div>

                    <div class="pos-report-grid">
                        <div class="pos-report-card">
                            <span>Status</span>
                            <b>${escapeHtml(data.sale.status || '')}</b>
                        </div>
                        <div class="pos-report-card">
                            <span>Subtotal</span>
                            <b>₱${fmt(data.sale.subtotal)}</b>
                        </div>
                        <div class="pos-report-card">
                            <span>Discount</span>
                            <b>₱${fmt(data.sale.discount_amount)}</b>
                        </div>
                    </div>

                    <div class="pos-report-grid">
                        <div class="pos-report-card">
                            <span>Redeemed Points</span>
                            <b>${Number(data.sale.points_redeemed || 0) > 0 ? fmt(data.sale.points_redeemed) + ' pts' : 'No points used'}</b>
                        </div>
                        <div class="pos-report-card">
                            <span>Points Discount</span>
                            <b>${Number(data.sale.points_discount_amount || 0) > 0 ? '₱' + fmt(data.sale.points_discount_amount) : '₱0.00'}</b>
                        </div>
                        <div class="pos-report-card">
                            <span>Points Earned</span>
                            <b>${Number(data.sale.points_earned || 0) > 0 ? fmt(data.sale.points_earned) + ' pts' : '0 pts'}</b>
                        </div>
                    </div>

                    <div class="pos-report-grid">
                        <div class="pos-report-card">
                            <span>Points Eligible Amount</span>
                            <b>₱${fmt(data.sale.points_eligible_amount || 0)}</b>
                        </div>
                        <div class="pos-report-card">
                            <span>Tendered Amount</span>
                            <b>₱${fmt(data.sale.tendered_amount || 0)}</b>
                        </div>
                        <div class="pos-report-card">
                            <span>Change</span>
                            <b>₱${fmt(data.sale.change_amount || 0)}</b>
                        </div>
                    </div>

                    <div class="pos-section-title">Items</div>
                    <div class="pos-modal-table-wrap" style="max-height:280px;">
                        <table class="pos-modal-table">
                            <thead>
                                <tr>
                                    <th>Item</th>
                                    <th class="pos-table-amount">Qty</th>
                                    <th class="pos-table-amount">Price</th>
                                    <th class="pos-table-amount">Total</th>
                                </tr>
                            </thead>
                            <tbody>${rows}</tbody>
                        </table>
                    </div>
                </div>
            `,
            width: 820
        });

        if (source === 'recent_sales') {
            await openRecentSales(posLastRecentSalesFilter || 'today');
        } else if (source === 'sales_order') {
            await openSalesOrder();
        }

        return;
    }

    const confirmTitle = mode === 'void' ? 'VOID SALE' : 'RETURN SALE';
    const approval = await confirmCashierPassword(confirmTitle, {
        approval_scope: 'branch_admin',
        action_type: mode === 'void' ? 'VOID_SALE_APPROVAL' : 'RETURN_SALE_APPROVAL',
        details: mode === 'void' ? 'Void sale approval' : 'Return sale approval'
    });

    if (!approval) {
        return;
    }

    const r = await Swal.fire({
        title: mode === 'void' ? 'Void this sale?' : 'Return this sale?',
        text: 'Stocks will be restored after confirmation.',
        icon: 'warning',
        showCancelButton: true
    });

    if (!r.isConfirmed) {
        return;
    }

    const data = await api({
        action: mode === 'void' ? 'void_sale' : 'return_sale',
        sale_id: saleId,
        password: approval.password
    });

    Swal.fire(data.success ? 'Done' : 'Error', data.message || '', data.success ? 'success' : 'error');
};

async function openZReading() {
    const today = new Date().toISOString().slice(0, 10);
    const data = await api({
        action: 'z_reading',
        date: today
    });

    if (!data.success) {
        Swal.fire('Error', data.message || '', 'error');
        return;
    }

    let grand = 0;

    const rows = (data.rows || []).map(r => {
        grand += Number(r.total_sales || 0);

        return `
            <tr>
                <td>${escapeHtml(r.payment_method)}</td>
                <td class="pos-table-amount">${r.sale_count}</td>
                <td class="pos-table-amount">₱${fmt(r.total_sales)}</td>
                <td class="pos-table-amount">₱${fmt(r.total_discount)}</td>
            </tr>
        `;
    }).join('');

    const moves = (data.movements || []).map(m => `
        <tr>
            <td>${escapeHtml(m.movement_type)}</td>
            <td class="pos-table-amount">${m.movement_count}</td>
            <td class="pos-table-amount">₱${fmt(m.total_amount)}</td>
        </tr>
    `).join('');

    Swal.fire({
        title: 'Z-READING',
        html: `
            <div class="pos-modal-panel">
                <div class="pos-report-grid">
                    <div class="pos-report-card">
                        <span>Date</span>
                        <b>${escapeHtml(data.date)}</b>
                    </div>
                    <div class="pos-report-card">
                        <span>Total Completed Sales</span>
                        <b>₱${fmt(grand)}</b>
                    </div>
                    <div class="pos-report-card">
                        <span>Report Type</span>
                        <b>Z-Reading</b>
                    </div>
                </div>

                <div class="pos-section-title">Payment Summary</div>
                <div class="pos-modal-table-wrap" style="max-height:240px;">
                    <table class="pos-modal-table">
                        <thead>
                            <tr>
                                <th>Payment</th>
                                <th class="pos-table-amount">Count</th>
                                <th class="pos-table-amount">Sales</th>
                                <th class="pos-table-amount">Discount</th>
                            </tr>
                        </thead>
                        <tbody>${rows || '<tr><td colspan="4" style="text-align:center;">No sales.</td></tr>'}</tbody>
                    </table>
                </div>

                <div class="pos-section-title">Cash Movements</div>
                <div class="pos-modal-table-wrap" style="max-height:180px;">
                    <table class="pos-modal-table">
                        <thead>
                            <tr>
                                <th>Movement</th>
                                <th class="pos-table-amount">Count</th>
                                <th class="pos-table-amount">Amount</th>
                            </tr>
                        </thead>
                        <tbody>${moves || '<tr><td colspan="3" style="text-align:center;">No cash movement.</td></tr>'}</tbody>
                    </table>
                </div>
            </div>
        `,
        width: 900,
        confirmButtonText: 'OK'
    });
}

function refreshApp() {
    window.location.reload();
}

document.getElementById('productSearch').addEventListener('focus', () => {
    setScanStatus('Ready to scan. Format: QTY*BARCODE');
});

window.addEventListener('resize', () => {
    positionSuggestDropdown();
    positionCustomerSuggestDropdown();
});

document.addEventListener('mousedown', e => {
    const box = document.getElementById('suggest');
    const searchArea = document.querySelector('.searcharea');

    if (box && searchArea && !box.contains(e.target) && !searchArea.contains(e.target)) {
        hideSuggestDropdown();
    }

    const customerBox = document.getElementById('customerSuggest');
    const customerInput = document.getElementById('customerName');

    if (customerBox && customerInput && !customerBox.contains(e.target) && e.target !== customerInput) {
        hideCustomerSuggestDropdown();
    }
});

let suggestIndex = -1;

function getSuggestItems() {
    return Array.from(document.querySelectorAll('#suggest .suggest-item'));
}

function clearSuggestActive() {
    getSuggestItems().forEach(item => item.classList.remove('active'));
}

function setSuggestActive(index) {
    const items = getSuggestItems();

    if (!items.length) {
        suggestIndex = -1;
        return;
    }

    suggestIndex = ((index % items.length) + items.length) % items.length;
    clearSuggestActive();
    items[suggestIndex].classList.add('active');
    items[suggestIndex].scrollIntoView({
        block: 'nearest'
    });
}

document.getElementById('productSearch').addEventListener('keydown', e => {
    const box = document.getElementById('suggest');
    const isSuggestOpen = box && box.style.display !== 'none';
    const items = getSuggestItems();

    if (e.key === 'ArrowDown') {
        if (isSuggestOpen && items.length) {
            e.preventDefault();
            setSuggestActive(suggestIndex + 1);
            return;
        }
    }

    if (e.key === 'ArrowUp') {
        if (isSuggestOpen && items.length) {
            e.preventDefault();
            setSuggestActive(suggestIndex - 1);
            return;
        }
    }

    if (e.key === 'Enter') {
        e.preventDefault();

        if (isSuggestOpen && suggestIndex >= 0 && items[suggestIndex]) {
            items[suggestIndex].click();
            return;
        }

        clearTimeout(window.__t);
        scanBarcodeFromInput();
        return;
    }

    if (e.key === 'Escape') {
        hideSuggestDropdown();
    }
});

document.getElementById('productSearch').addEventListener('input', e => {
    clearTimeout(window.__t);

    const term = e.target.value.trim();

    if (term.length === 0) {
        hideSuggestDropdown();
        setScanStatus('Ready to scan. Format: QTY*BARCODE');
        return;
    }

    if (hasQtyBarcodePrefix(term)) {
        const parsed = parseQtyAndBarcode(term);

        if (parsed.barcode.length === 0) {
            hideSuggestDropdown();
            setScanStatus('Type or scan the barcode after *');
            return;
        }

        setScanStatus('Qty ' + fmt(parsed.qty) + ' ready. Press Enter after barcode scan.');

        if (parsed.barcode.length >= 2) {
            window.__t = setTimeout(() => {
                searchProduct();
            }, 350);
        }

        return;
    }

    window.__t = setTimeout(() => {
        searchProduct();
    }, 350);
});

document.addEventListener('keydown', e => {
    const tenderModalOpen = document.getElementById('tenderModal').classList.contains('show');
    const activeElementId = document.activeElement.id;
    const key = e.key.toLowerCase();
    const isTextInput = ['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement.tagName);
    const isSweetAlertOpen = document.body.classList.contains('swal2-shown');

    if (e.key === 'Escape' && !tenderModalOpen && !isSweetAlertOpen) {
        // ESC must only start a new transaction after a successful sale.
        // During an active sale, use Cancel or Void Item instead.
        if (saleAwaitingNewCustomer || completedSaleSummary) {
            e.preventDefault();
            startNewCustomer(true);
        }
        return;
    }

    if (!tenderModalOpen && !isSweetAlertOpen && !isTextInput && e.key === 'ArrowDown') {
        e.preventDefault();
        moveActiveRow(1);
        return;
    }

    if (!tenderModalOpen && !isSweetAlertOpen && !isTextInput && e.key === 'ArrowUp') {
        e.preventDefault();
        moveActiveRow(-1);
        return;
    }

    if (e.key === 'F1') {
        e.preventDefault();
        editItem();
    }

    if (e.key === 'F2') {
        e.preventDefault();
        clearCart();
    }

    if (e.key === 'F3') {
        e.preventDefault();
        openDiscount();
    }

    if (e.key === 'F4') {
        e.preventDefault();
        showNotDone('Tag Order');
    }

    if (e.key === 'F5') {
        e.preventDefault();
        openSalesOrder();
    }

    if (e.key === 'F6') {
        e.preventDefault();
        changeQty();
    }

    if (e.key === 'F7') {
        e.preventDefault();
        editPrice();
    }

    if (e.key === 'F8') {
        e.preventDefault();
        voidSelectedItem();
    }


    if (e.key === 'F9') {
        e.preventDefault();
        cyclePriceLevel();
        return;
    }

    if (e.ctrlKey && !e.shiftKey && key === 'l') {
        e.preventDefault();
        cyclePriceLevel();
        return;
    }

    if (e.ctrlKey && !e.shiftKey && key === '1') {
        e.preventDefault();
        openCashCount();
    }

    if (e.ctrlKey && !e.shiftKey && key === '2') {
        e.preventDefault();
        openCashTransfer();
    }

    if (e.ctrlKey && !e.shiftKey && key === '3') {
        e.preventDefault();
        openPickItem();
    }

    if (e.ctrlKey && !e.shiftKey && key === '4') {
        e.preventDefault();
        openCustomerLookup();
    }

    if (e.ctrlKey && !e.shiftKey && key === '6') {
        e.preventDefault();
        showNotDone('Loyalty');
    }

    if (e.ctrlKey && !e.shiftKey && key === '7') {
        e.preventDefault();
        openRecentSales();
    }

    if (e.ctrlKey && !e.shiftKey && key === '8') {
        e.preventDefault();
        openVoidSale();
    }

    if (e.ctrlKey && !e.shiftKey && key === '9') {
        e.preventDefault();
        openReturnSale();
    }

    if (e.ctrlKey && !e.shiftKey && key === '0') {
        e.preventDefault();
        openZReading();
    }

    if (e.ctrlKey && !e.shiftKey && key === 'p') {
        e.preventDefault();
        openPriceInquiry();
    }

    if (e.ctrlKey && e.shiftKey && key === 'd') {
        e.preventDefault();
        openDrawer();
    }

    if (e.key === 'F12') {
        e.preventDefault();

        if (cart.length > 0) {
            openTender();
        }
    }
});

initPriceLevelDropdown();
renderCart();
ensureShiftReady();
focusBarcodeInput();

window.addEventListener('focus', focusBarcodeInput);

document.addEventListener('click', e => {
    const isInteractive = e.target.closest('button, input, select, textarea, .suggest, .swal2-container');

    if (!isInteractive) {
        focusBarcodeInput();
    }
});

function switchToBranchAdmin() {
    window.location.href = '../BranchAdmin/branchdashboard.php';
}

function logoutPOS() {
    closeShiftAndLogout();
}

// Tender Payment NumPad
document.querySelectorAll('.tender-keypad button[data-key]').forEach(btn => {
    btn.addEventListener('click', function () {

        let key = this.dataset.key;

        let activeInput =
            document.querySelector('.active-payment-input') ||
            document.getElementById('tenderedAmount');

        if (!activeInput) return;

        if (key === 'del') {
            activeInput.value = activeInput.value.slice(0, -1);
        } 
        else if (key === 'clear') {
            activeInput.value = '';
        } 
        else if (key === '.') {
            if (!activeInput.value.includes('.')) {
                activeInput.value += '.';
            }
        } 
        else {
            activeInput.value += key;
        }

        // trigger computation ng change/balance
        activeInput.dispatchEvent(new Event('input'));
    });
});

</script>
</body>
</html>