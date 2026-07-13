<?php
require_once '../config/database.php';
require_once '../config/session_handler.php';

$user_id = $_SESSION['user_id'] ?? 0;
$user_name = isset($_SESSION['first_name']) ? trim($_SESSION['first_name'] . ' ' . ($_SESSION['last_name'] ?? '')) : 'Motorpool Account';
$user_role = $_SESSION['role'] ?? 'motorpool';
$branch_id = (int)($_SESSION['branch_id'] ?? 0);
$branch_name = $_SESSION['branch_name'] ?? '';
$view_all_branches = ($_SESSION['view_all_branches'] ?? false);

$user_initials = '';
foreach (explode(' ', trim($user_name)) as $part) {
    if ($part !== '') {
        $user_initials .= strtoupper(substr($part, 0, 1));
    }
}
if ($user_initials === '') {
    $user_initials = 'BA';
}



$items = [];
$itemUoms = [];
$accounts = [];
$customers = [];

$mp_inventory_table = 'motorpool_inventory_items';
$mp_items_ready = false;

function mp_safe_identifier($name) {
    return preg_replace('/[^A-Za-z0-9_]/', '', (string)$name);
}

function mp_table_exists($db, $table) {
    try {
        if ($db instanceof PDO) {
            $stmt = $db->prepare("SHOW TABLES LIKE ?");
            $stmt->execute([$table]);
            return (bool)$stmt->fetchColumn();
        }
        if ($db instanceof mysqli) {
            $safe = $db->real_escape_string($table);
            $res = $db->query("SHOW TABLES LIKE '{$safe}'");
            return $res && $res->num_rows > 0;
        }
    } catch (Throwable $e) {}
    return false;
}

function mp_column_exists($db, $table, $column) {
    try {
        $table = mp_safe_identifier($table);
        if ($table === '') return false;
        if ($db instanceof PDO) {
            $stmt = $db->prepare("SHOW COLUMNS FROM `{$table}` LIKE ?");
            $stmt->execute([$column]);
            return (bool)$stmt->fetchColumn();
        }
        if ($db instanceof mysqli) {
            $safeColumn = $db->real_escape_string($column);
            $res = $db->query("SHOW COLUMNS FROM `{$table}` LIKE '{$safeColumn}'");
            return $res && $res->num_rows > 0;
        }
    } catch (Throwable $e) {}
    return false;
}

function mp_first_existing_column($db, $table, array $columns, $fallback = null) {
    foreach ($columns as $column) {
        if (mp_column_exists($db, $table, $column)) return $column;
    }
    return $fallback;
}

function mp_select_expr($db, $table, array $columns, $alias, $fallback = "''") {
    $column = mp_first_existing_column($db, $table, $columns, null);
    if ($column !== null && $column !== '') {
        return "`" . mp_safe_identifier($column) . "` AS `" . mp_safe_identifier($alias) . "`";
    }
    return $fallback . " AS `" . mp_safe_identifier($alias) . "`";
}

function mp_fetch_all($db, $sql, array $params = []) {
    if ($db instanceof PDO) {
        $stmt = $db->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(is_int($key) ? $key + 1 : $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    if ($db instanceof mysqli) {
        $stmt = $db->prepare($sql);
        if (!$stmt) return [];
        if (!empty($params)) {
            $types = '';
            $values = [];
            foreach ($params as $value) {
                $types .= is_int($value) ? 'i' : (is_float($value) ? 'd' : 's');
                $values[] = $value;
            }
            $stmt->bind_param($types, ...$values);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        while ($res && ($row = $res->fetch_assoc())) $rows[] = $row;
        $stmt->close();
        return $rows;
    }
    return [];
}

function mp_build_branch_filter($db, $table, $view_all_branches, $branch_id, array &$params, $allow_global = false) {
    if ($view_all_branches || (int)$branch_id <= 0 || !mp_column_exists($db, $table, 'branch_id')) return '';
    if ($allow_global) {
        $params[] = (int)$branch_id;
        return " AND (`branch_id` = ? OR `branch_id` IS NULL OR `branch_id` = 0)";
    }
    $params[] = (int)$branch_id;
    return " AND `branch_id` = ?";
}

try {
    $activeDb = null;
    if (isset($pdo) && $pdo instanceof PDO) {
        $activeDb = $pdo;
    } elseif (isset($conn) && $conn instanceof mysqli) {
        $activeDb = $conn;
    }

    if ($activeDb) {
        $mp_items_ready = mp_table_exists($activeDb, $mp_inventory_table);
        $itemsTable = $mp_items_ready ? $mp_inventory_table : 'items';
        $strictMotorpool = $mp_items_ready;

        if (mp_table_exists($activeDb, $itemsTable)) {
            $idCol = mp_first_existing_column($activeDb, $itemsTable, ['item_id', 'inventory_item_id', 'motorpool_item_id', 'id'], 'item_id');
            $nameCol = mp_first_existing_column($activeDb, $itemsTable, ['item_name', 'name', 'part_name', 'inventory_name'], 'item_name');
            $categoryCol = mp_first_existing_column($activeDb, $itemsTable, ['category', 'item_category', 'category_name'], null);
            $stockCol = mp_first_existing_column($activeDb, $itemsTable, ['stock', 'current_stock', 'quantity_on_hand', 'qty_on_hand', 'available_stock', 'quantity', 'qty'], null);
            $priceCol = mp_first_existing_column($activeDb, $itemsTable, ['unit_price', 'unit_cost', 'cost', 'price', 'last_purchase_price'], null);
            $statusCol = mp_first_existing_column($activeDb, $itemsTable, ['status', 'item_status'], null);

            $selectParts = [
                mp_select_expr($activeDb, $itemsTable, [$idCol], 'item_id', '0'),
                mp_select_expr($activeDb, $itemsTable, ['item_code', 'code', 'sku', 'part_no', 'barcode'], 'item_code', "''"),
                mp_select_expr($activeDb, $itemsTable, [$nameCol], 'item_name', "''"),
                mp_select_expr($activeDb, $itemsTable, ['description', 'remarks', 'item_description'], 'description', "''"),
                mp_select_expr($activeDb, $itemsTable, [$categoryCol], 'category', "'Uncategorized'"),
                mp_select_expr($activeDb, $itemsTable, [$stockCol], 'stock', '0'),
                mp_select_expr($activeDb, $itemsTable, ['base_unit_type', 'base_uom', 'default_unit', 'uom'], 'base_unit_type', "''"),
                mp_select_expr($activeDb, $itemsTable, ['unit_type', 'uom', 'unit', 'default_uom'], 'unit_type', "''"),
                mp_select_expr($activeDb, $itemsTable, ['default_unit_type_id'], 'default_unit_type_id', '0'),
                mp_select_expr($activeDb, $itemsTable, ['default_uom_id'], 'default_uom_id', '0'),
                mp_select_expr($activeDb, $itemsTable, ['smallest_uom_id'], 'smallest_uom_id', '0'),
                mp_select_expr($activeDb, $itemsTable, ['unit_conversions'], 'unit_conversions', "''"),
                mp_select_expr($activeDb, $itemsTable, [$priceCol], 'unit_price', '0'),
                mp_select_expr($activeDb, $itemsTable, ['branch_id'], 'branch_id', '0')
            ];

            $params = [];
            $where = ' WHERE 1=1';
            if ($statusCol) {
                $where .= " AND (`{$statusCol}` IS NULL OR `{$statusCol}` = '' OR LOWER(`{$statusCol}`) IN ('active','available','in stock'))";
            }
            $where .= mp_build_branch_filter($activeDb, $itemsTable, (bool)$view_all_branches, (int)$branch_id, $params, !$strictMotorpool);
            $orderCategory = $categoryCol ? "COALESCE(`{$categoryCol}`, 'Uncategorized')" : "'Uncategorized'";
            $orderName = $nameCol ? "`{$nameCol}`" : "1";
            $items = mp_fetch_all($activeDb, "SELECT " . implode(', ', $selectParts) . " FROM `{$itemsTable}` {$where} ORDER BY {$orderCategory}, {$orderName}", $params);
        }

        $uomTables = $mp_items_ready ? ['motorpool_item_unit_types', 'item_unit_types'] : ['item_unit_types'];
        foreach ($uomTables as $uomTable) {
            if (!mp_table_exists($activeDb, $uomTable)) continue;
            $itemIdCol = mp_first_existing_column($activeDb, $uomTable, ['item_id', 'inventory_item_id', 'motorpool_item_id'], 'item_id');
            $uomNameCol = mp_first_existing_column($activeDb, $uomTable, ['unit_type_name', 'uom_name', 'unit_name', 'unit_type', 'uom'], 'unit_type_name');
            $defaultCol = mp_first_existing_column($activeDb, $uomTable, ['is_default_uom', 'is_default'], null);
            $qtyCol = mp_first_existing_column($activeDb, $uomTable, ['smallest_pack_quantity', 'conversion_qty', 'quantity'], null);
            $statusCol = mp_first_existing_column($activeDb, $uomTable, ['status'], null);
            $select = "`{$itemIdCol}` AS item_id, `{$uomNameCol}` AS unit_type_name, " . ($defaultCol ? "`{$defaultCol}`" : '0') . " AS is_default_uom, " . ($qtyCol ? "`{$qtyCol}`" : '1') . " AS smallest_pack_quantity";
            $where = $statusCol ? " WHERE (`{$statusCol}` IS NULL OR `{$statusCol}` = '' OR LOWER(`{$statusCol}`) = 'active')" : '';
            foreach (mp_fetch_all($activeDb, "SELECT {$select} FROM `{$uomTable}` {$where} ORDER BY item_id, is_default_uom DESC, smallest_pack_quantity ASC, unit_type_name ASC") as $uomRow) {
                $itemUoms[(int)$uomRow['item_id']][] = $uomRow;
            }
            break;
        }

        if (mp_table_exists($activeDb, 'chart_of_accounts')) {
            $params = [];
            $where = " WHERE status = 'active'" . mp_build_branch_filter($activeDb, 'chart_of_accounts', (bool)$view_all_branches, (int)$branch_id, $params, true);
            $accountsRaw = mp_fetch_all($activeDb, "SELECT account_id, parent_account_id, account_title, account_type FROM chart_of_accounts {$where} ORDER BY account_type, account_title", $params);
            $byParent = [];
            foreach ($accountsRaw as $account) {
                $parent = (int)($account['parent_account_id'] ?? 0);
                $byParent[$parent][] = $account;
            }
            $buildAccounts = function ($parentId, $level) use (&$buildAccounts, &$byParent, &$accounts) {
                foreach ($byParent[$parentId] ?? [] as $account) {
                    $account['level'] = $level;
                    $accounts[] = $account;
                    $buildAccounts((int)$account['account_id'], $level + 1);
                }
            };
            $buildAccounts(0, 0);
        }

        // Motorpool item adjustment does not need Branch customer data, but keep customer dropdown compatible.
        if (mp_table_exists($activeDb, 'motorpool_customers')) {
            $params = [];
            $where = " WHERE 1=1" . mp_build_branch_filter($activeDb, 'motorpool_customers', (bool)$view_all_branches, (int)$branch_id, $params, false);
            $customers = mp_fetch_all($activeDb, "SELECT customer_id, customer_name, store_name FROM motorpool_customers {$where} ORDER BY customer_name", $params);
        } elseif (mp_table_exists($activeDb, 'customers') && !$mp_items_ready) {
            $params = [];
            $where = " WHERE status = 'active'" . mp_build_branch_filter($activeDb, 'customers', (bool)$view_all_branches, (int)$branch_id, $params, true);
            $customers = mp_fetch_all($activeDb, "SELECT customer_id, customer_name, store_name FROM customers {$where} ORDER BY customer_name", $params);
        }
    }
} catch (Throwable $e) {
    $items = [];
    $itemUoms = [];
    $accounts = [];
    $customers = [];
}

function ia_json_response($success, $message, $extra = []) {
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/json');
    echo json_encode(array_merge([
        'success' => $success,
        'message' => $message
    ], $extra));
    exit;
}

function ia_table_exists($db, $table) {
    try {
        if ($db instanceof PDO) {
            $stmt = $db->prepare("SHOW TABLES LIKE ?");
            $stmt->execute([$table]);
            return (bool)$stmt->fetchColumn();
        }
        if ($db instanceof mysqli) {
            $safe = $db->real_escape_string($table);
            $res = $db->query("SHOW TABLES LIKE '{$safe}'");
            return $res && $res->num_rows > 0;
        }
    } catch (Throwable $e) {}
    return false;
}

function ia_column_exists($db, $table, $column) {
    try {
        if ($db instanceof PDO) {
            $stmt = $db->prepare("SHOW COLUMNS FROM `{$table}` LIKE ?");
            $stmt->execute([$column]);
            return (bool)$stmt->fetchColumn();
        }
        if ($db instanceof mysqli) {
            $safeTable = $db->real_escape_string($table);
            $safeColumn = $db->real_escape_string($column);
            $res = $db->query("SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeColumn}'");
            return $res && $res->num_rows > 0;
        }
    } catch (Throwable $e) {}
    return false;
}

function ia_exec($db, $sql) {
    if ($db instanceof PDO) {
        $db->exec($sql);
        return;
    }
    if ($db instanceof mysqli) {
        if (!$db->query($sql)) {
            throw new Exception($db->error);
        }
    }
}

function ia_prepare_execute($db, $sql, $params = []) {
    if ($db instanceof PDO) {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    if ($db instanceof mysqli) {
        $stmt = $db->prepare($sql);
        if (!$stmt) {
            throw new Exception($db->error);
        }
        if (!empty($params)) {
            $types = '';
            $values = [];
            foreach ($params as $value) {
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
        }
        if (!$stmt->execute()) {
            throw new Exception($stmt->error);
        }
        return $stmt;
    }

    throw new Exception('Database connection was not found.');
}

function ia_last_insert_id($db) {
    if ($db instanceof PDO) {
        return (int)$db->lastInsertId();
    }
    if ($db instanceof mysqli) {
        return (int)$db->insert_id;
    }
    return 0;
}

function ia_begin($db) {
    if ($db instanceof PDO) {
        $db->beginTransaction();
    } elseif ($db instanceof mysqli) {
        $db->begin_transaction();
    }
}

function ia_commit($db) {
    if ($db instanceof PDO) {
        $db->commit();
    } elseif ($db instanceof mysqli) {
        $db->commit();
    }
}

function ia_rollback($db) {
    try {
        if ($db instanceof PDO && $db->inTransaction()) {
            $db->rollBack();
        } elseif ($db instanceof mysqli) {
            $db->rollback();
        }
    } catch (Throwable $e) {}
}

function ia_num($value) {
    $value = str_replace([',', '₱', '$', ' '], '', (string)$value);
    if ($value === '' || $value === '-' || $value === '.') {
        return 0.00;
    }
    return is_numeric($value) ? (float)$value : 0.00;
}

function ia_str($value) {
    return trim((string)($value ?? ''));
}

function ia_item_uom_options($item, $itemUoms) {
    $options = [];
    $addOption = function ($label) use (&$options) {
        $label = trim((string)$label);
        if ($label === '') {
            return;
        }
        $key = function_exists('mb_strtolower') ? mb_strtolower($label) : strtolower($label);
        if (!isset($options[$key])) {
            $options[$key] = $label;
        }
    };

    foreach ($itemUoms[(int)($item['item_id'] ?? 0)] ?? [] as $uomRow) {
        $addOption($uomRow['unit_type_name'] ?? '');
    }

    $addOption($item['unit_type'] ?? '');
    $addOption($item['base_unit_type'] ?? '');

    $unitConversions = $item['unit_conversions'] ?? '';
    if ($unitConversions) {
        $decoded = json_decode((string)$unitConversions, true);
        if (is_array($decoded)) {
            foreach ($decoded as $key => $value) {
                if (is_array($value)) {
                    $addOption($value['unit'] ?? $value['uom'] ?? $value['unit_type'] ?? $value['name'] ?? $key);
                } else {
                    $addOption(is_string($key) ? $key : $value);
                }
            }
        }
    }

    return array_values($options);
}

function ia_ensure_tables($db) {
    ia_exec($db, "CREATE TABLE IF NOT EXISTS `motorpool_item_adjustments` (
        `adjustment_id` INT NOT NULL AUTO_INCREMENT,
        `reference_no` VARCHAR(50) NOT NULL,
        `adjustment_type` VARCHAR(50) NOT NULL,
        `adjustment_date` DATE NOT NULL,
        `adjustment_account_id` INT DEFAULT NULL,
        `customer_id` INT DEFAULT NULL,
        `memo` TEXT DEFAULT NULL,
        `total_adjustment_value` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
        `branch_id` INT DEFAULT NULL,
        `created_by` INT DEFAULT NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`adjustment_id`),
        KEY `idx_motorpool_item_adjustments_ref` (`reference_no`),
        KEY `idx_motorpool_item_adjustments_date` (`adjustment_date`),
        KEY `idx_motorpool_item_adjustments_branch` (`branch_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    ia_exec($db, "CREATE TABLE IF NOT EXISTS `motorpool_item_adjustment_details` (
        `detail_id` INT NOT NULL AUTO_INCREMENT,
        `adjustment_id` INT NOT NULL,
        `item_id` INT NOT NULL,
        `item_name` VARCHAR(255) DEFAULT NULL,
        `description` TEXT DEFAULT NULL,
        `uom` VARCHAR(100) DEFAULT NULL,
        `qty_on_hand` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
        `new_qty` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
        `qty_difference` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
        `total_value` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
        `new_value` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
        PRIMARY KEY (`detail_id`),
        KEY `idx_motorpool_item_adjustment_details_adj` (`adjustment_id`),
        KEY `idx_motorpool_item_adjustment_details_item` (`item_id`),
        CONSTRAINT `fk_mp_item_adjustment_details_adj`
            FOREIGN KEY (`adjustment_id`) REFERENCES `motorpool_item_adjustments` (`adjustment_id`)
            ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
}

$db = null;
if (isset($pdo) && $pdo instanceof PDO) {
    $db = $pdo;
} elseif (isset($conn) && $conn instanceof mysqli) {
    $db = $conn;
}

if ($db) {
    try {
        ia_ensure_tables($db);
    } catch (Throwable $e) {}
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_item_adjustment') {
    if (!$db) {
        ia_json_response(false, 'Database connection was not found.');
    }

    try {
        ia_ensure_tables($db);

        $adjustmentTypeValue = ia_str($_POST['adjustment_type'] ?? '');
        $adjustmentDateValue = ia_str($_POST['adjustment_date'] ?? date('Y-m-d'));
        $adjustmentAccountId = (int)($_POST['adjustment_account_id'] ?? 0);
        $customerId = (int)($_POST['customer_id'] ?? 0);
        $memoValue = ia_str($_POST['memo'] ?? '');
        $rows = json_decode((string)($_POST['rows'] ?? '[]'), true);

        if (!in_array($adjustmentTypeValue, ['quantity', 'total_value', 'quantity_total_value'], true)) {
            ia_json_response(false, 'Invalid adjustment type.');
        }

        $adjustmentTime = strtotime($adjustmentDateValue);
        if (!$adjustmentTime) {
            ia_json_response(false, 'Invalid adjustment date.');
        }
        $adjustmentDateValue = date('Y-m-d', $adjustmentTime);

        if ($adjustmentAccountId <= 0) {
            ia_json_response(false, 'Please select an Adjustment Account.');
        }

        if (!is_array($rows)) {
            ia_json_response(false, 'Invalid item rows.');
        }

        $cleanRows = [];
        foreach ($rows as $row) {
            $itemId = (int)($row['itemId'] ?? 0);
            $name = ia_str($row['name'] ?? '');
            $description = ia_str($row['description'] ?? '');
            $hasValue = $itemId > 0 || $name !== '' || $description !== '' || ia_str($row['newQty'] ?? '') !== '' || ia_str($row['newValue'] ?? '') !== '';
            if (!$hasValue) {
                continue;
            }
            if ($itemId <= 0) {
                ia_json_response(false, 'Please select an item from the dropdown before saving.');
            }
            $cleanRows[] = $row;
        }

        if (empty($cleanRows)) {
            ia_json_response(false, 'Please select or type at least one item.');
        }

        ia_begin($db);

        $referenceNo = 'IA-' . date('Ymd') . '-' . str_pad((string)random_int(1, 99999), 5, '0', STR_PAD_LEFT);
        $lastStmt = ia_prepare_execute($db, "SELECT COALESCE(MAX(adjustment_id), 0) + 1 AS next_ref FROM motorpool_item_adjustments");
        if ($lastStmt instanceof PDOStatement) {
            $nextRow = $lastStmt->fetch(PDO::FETCH_ASSOC);
            $referenceNo = (string)($nextRow['next_ref'] ?? '1');
        } elseif ($lastStmt instanceof mysqli_stmt) {
            $result = $lastStmt->get_result();
            $nextRow = $result ? $result->fetch_assoc() : null;
            $referenceNo = (string)($nextRow['next_ref'] ?? '1');
            $lastStmt->close();
        }

        $totalAdjustmentValue = 0.00;
        foreach ($cleanRows as $row) {
            $oldValue = ia_num($row['total'] ?? 0);
            $newValue = ia_num($row['newValue'] ?? $oldValue);
            if (ia_str($row['newValue'] ?? '') !== '') {
                $totalAdjustmentValue += ($newValue - $oldValue);
            }
        }

        ia_prepare_execute($db, "INSERT INTO motorpool_item_adjustments
            (reference_no, adjustment_type, adjustment_date, adjustment_account_id, customer_id, memo, total_adjustment_value, branch_id, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)", [
                $referenceNo,
                $adjustmentTypeValue,
                $adjustmentDateValue,
                $adjustmentAccountId,
                $customerId > 0 ? $customerId : null,
                $memoValue,
                (float)$totalAdjustmentValue,
                (int)$branch_id,
                (int)$user_id
            ]);

        $adjustmentId = ia_last_insert_id($db);
        if ($adjustmentId <= 0) {
            throw new Exception('Unable to create item adjustment record.');
        }

        $itemHasUnitPrice = true;
        $itemHasStock = true;

        foreach ($cleanRows as $row) {
            $itemId = (int)($row['itemId'] ?? 0);
            $itemName = ia_str($row['name'] ?? '');
            $description = ia_str($row['description'] ?? '');
            $uom = ia_str($row['uom'] ?? '');
            $qtyOnHand = ia_num($row['stock'] ?? 0);
            $newQtyRaw = ia_str($row['newQty'] ?? '');
            $qtyDiffRaw = ia_str($row['qtyDiff'] ?? '');
            $totalValue = ia_num($row['total'] ?? 0);
            $newValueRaw = ia_str($row['newValue'] ?? '');
            $newValue = $newValueRaw !== '' ? ia_num($newValueRaw) : $totalValue;

            if ($newQtyRaw !== '') {
                $newQty = ia_num($newQtyRaw);
            } elseif ($qtyDiffRaw !== '') {
                $newQty = $qtyOnHand + ia_num($qtyDiffRaw);
            } else {
                $newQty = $qtyOnHand;
            }
            $qtyDifference = $newQty - $qtyOnHand;

            ia_prepare_execute($db, "INSERT INTO motorpool_item_adjustment_details
                (adjustment_id, item_id, item_name, description, uom, qty_on_hand, new_qty, qty_difference, total_value, new_value)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)", [
                    (int)$adjustmentId,
                    (int)$itemId,
                    $itemName,
                    $description,
                    $uom,
                    (float)$qtyOnHand,
                    (float)$newQty,
                    (float)$qtyDifference,
                    (float)$totalValue,
                    (float)$newValue
                ]);

            $activeItemTable = ia_table_exists($db, 'motorpool_inventory_items') ? 'motorpool_inventory_items' : 'items';
            $activeItemIdColumn = ia_column_exists($db, $activeItemTable, 'item_id') ? 'item_id' : (ia_column_exists($db, $activeItemTable, 'inventory_item_id') ? 'inventory_item_id' : (ia_column_exists($db, $activeItemTable, 'motorpool_item_id') ? 'motorpool_item_id' : 'id'));
            $activeStockColumn = null;
            foreach (['stock', 'current_stock', 'quantity_on_hand', 'qty_on_hand', 'available_stock', 'quantity', 'qty'] as $candidateStockColumn) {
                if (ia_column_exists($db, $activeItemTable, $candidateStockColumn)) { $activeStockColumn = $candidateStockColumn; break; }
            }
            $activePriceColumn = null;
            foreach (['unit_price', 'unit_cost', 'cost', 'price', 'last_purchase_price'] as $candidatePriceColumn) {
                if (ia_column_exists($db, $activeItemTable, $candidatePriceColumn)) { $activePriceColumn = $candidatePriceColumn; break; }
            }

            if (($adjustmentTypeValue === 'quantity' || $adjustmentTypeValue === 'quantity_total_value') && $activeStockColumn) {
                ia_prepare_execute($db, "UPDATE `{$activeItemTable}` SET `{$activeStockColumn}` = ? WHERE `{$activeItemIdColumn}` = ?" . (!$view_all_branches && $branch_id > 0 && ia_column_exists($db, $activeItemTable, 'branch_id') ? " AND `branch_id` = ?" : ""),
                    (!$view_all_branches && $branch_id > 0 && ia_column_exists($db, $activeItemTable, 'branch_id')) ? [(float)$newQty, (int)$itemId, (int)$branch_id] : [(float)$newQty, (int)$itemId]);
            }

            if (($adjustmentTypeValue === 'total_value' || $adjustmentTypeValue === 'quantity_total_value') && $activePriceColumn) {
                $basisQty = ($adjustmentTypeValue === 'quantity_total_value') ? $newQty : $qtyOnHand;
                if ($basisQty > 0 && $newValueRaw !== '') {
                    $newUnitPrice = $newValue / $basisQty;
                    ia_prepare_execute($db, "UPDATE `{$activeItemTable}` SET `{$activePriceColumn}` = ? WHERE `{$activeItemIdColumn}` = ?" . (!$view_all_branches && $branch_id > 0 && ia_column_exists($db, $activeItemTable, 'branch_id') ? " AND `branch_id` = ?" : ""),
                        (!$view_all_branches && $branch_id > 0 && ia_column_exists($db, $activeItemTable, 'branch_id')) ? [(float)$newUnitPrice, (int)$itemId, (int)$branch_id] : [(float)$newUnitPrice, (int)$itemId]);
                }
            }
        }

        ia_commit($db);

        ia_json_response(true, 'Item Adjustment has been saved successfully.', [
            'adjustment_id' => $adjustmentId,
            'reference_no' => $referenceNo
        ]);
    } catch (Throwable $e) {
        ia_rollback($db);
        ia_json_response(false, 'Unable to save item adjustment: ' . $e->getMessage());
    }
}

$next_reference_no = 1;
try {
    if ($db) {
        ia_ensure_tables($db);
    }

    if (isset($pdo) && $pdo instanceof PDO) {
        $tableCheck = $pdo->query("SHOW TABLES LIKE 'motorpool_item_adjustments'");
        if ($tableCheck && $tableCheck->rowCount() > 0) {
            $refStmt = $pdo->query("SELECT COALESCE(MAX(adjustment_id), 0) + 1 AS next_ref FROM motorpool_item_adjustments");
            $next_reference_no = (int)($refStmt->fetch(PDO::FETCH_ASSOC)['next_ref'] ?? 1);
        }
    } elseif (isset($conn) && $conn instanceof mysqli) {
        $tableCheck = $conn->query("SHOW TABLES LIKE 'motorpool_item_adjustments'");
        if ($tableCheck && $tableCheck->num_rows > 0) {
            $refResult = $conn->query("SELECT COALESCE(MAX(adjustment_id), 0) + 1 AS next_ref FROM motorpool_item_adjustments");
            if ($refResult) {
                $next_reference_no = (int)($refResult->fetch_assoc()['next_ref'] ?? 1);
            }
        }
    }
} catch (Throwable $e) {
    $next_reference_no = 1;
}

?>
<!DOCTYPE html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Item Adjustment - Motorpool</title>
    <link rel="icon" type="image/png" href="../Pictures/favicon-96x96.png" sizes="96x96" />
    <link rel="stylesheet" href="../css/current_inventory.css">
    <link rel="icon" type="image/svg+xml" href="../Pictures/favicon.svg" />
    <link rel="shortcut icon" href="../Pictures/favicon.ico" />
    <link rel="apple-touch-icon" sizes="180x180" href="../Pictures/apple-touch-icon.png" />
    <link rel="manifest" href="../Pictures/site.webmanifest" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <style>

        :root {
            --qb-green: #3f9b13;
            --qb-line: #d6dce2;
            --qb-blue-row: #eaf7ec;
            --qb-blue-row-alt: #ffffff;
            --qb-text: #111827;
            --qb-muted: #6b7280;
        }

        .main-content {
            background: #ffffff;
            min-height: 100vh;
        }

        .item-adjustment-window {
            font-family: Arial, Helvetica, sans-serif;
            color: var(--qb-text);
            background: #fff;
            min-height: calc(100vh - 16px);
            padding: 0;
            border-top: 0;
        }

        .ia-form-area {
            padding: 18px 16px 0;
        }

        .ia-header-grid {
            display: grid;
            grid-template-columns: 390px 440px;
            column-gap: 90px;
            row-gap: 8px;
            align-items: center;
            max-width: 1050px;
        }

        .ia-field-row {
            display: grid;
            grid-template-columns: 150px 255px;
            align-items: center;
            min-height: 30px;
        }

        .ia-field-row label {
            font-size: 15px;
            font-weight: 400;
            margin: 0;
        }

        .ia-control,
        .ia-select {
            height: 27px;
            border: 1px solid #bfc5cb;
            border-radius: 2px;
            background: #eee;
            font-size: 14px;
            padding: 2px 6px;
            width: 100%;
            box-shadow: inset 0 1px 2px rgba(0,0,0,.08);
        }

        .ia-select.adjustment-type {
            background: var(--qb-green);
            color: #fff;
            font-size: 15px;
            height: 29px;
            padding-left: 2px;
        }

        .ia-select.adjustment-type option {
            background: #fff;
            color: #111;
            font-size: 18px;
        }


        .ia-account-dropdown {
            position: relative;
            width: 100%;
        }

        .ia-account-trigger {
            height: 27px;
            border: 1px solid #bfc5cb;
            border-radius: 2px;
            background: #eee;
            font-size: 14px;
            padding: 2px 28px 2px 6px;
            width: 100%;
            box-shadow: inset 0 1px 2px rgba(0,0,0,.08);
            text-align: left;
            color: #111827;
            position: relative;
            display: flex;
            align-items: center;
            overflow: hidden;
        }

        .ia-account-trigger::after {
            content: "";
            position: absolute;
            right: 9px;
            top: 50%;
            transform: translateY(-35%);
            border-left: 5px solid transparent;
            border-right: 5px solid transparent;
            border-top: 6px solid #333;
            pointer-events: none;
        }

        .ia-account-trigger:focus {
            outline: none;
            border-color: #7aa7d9;
            background: #fff;
        }

        .ia-account-selected {
            display: block;
            width: 100%;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .ia-account-menu {
            display: none;
            position: absolute;
            left: 0;
            top: calc(100% + 2px);
            width: 520px;
            max-width: calc(100vw - 48px);
            background: #fff;
            border: 1px solid #9da3aa;
            box-shadow: 0 4px 12px rgba(0,0,0,.18);
            z-index: 9999;
            overflow: hidden;
        }

        .ia-account-dropdown.open .ia-account-menu {
            display: block;
        }

        .ia-account-head,
        .ia-account-option {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 165px;
            gap: 12px;
            align-items: center;
        }

        .ia-account-head {
            padding: 7px 10px;
            background: #eaf7ec;
            border-bottom: 1px solid #c9e2cc;
            color: var(--qb-green);
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
        }

        .ia-account-options {
            max-height: 240px;
            overflow-y: auto;
            background: #fff;
        }

        .ia-account-option {
            width: 100%;
            border: 0;
            border-bottom: 1px solid #e5e7eb;
            background: #fff;
            padding: 7px 10px;
            font-size: 13px;
            line-height: 1.25;
            color: #111827;
            cursor: pointer;
            text-align: left;
        }

        .ia-account-option:hover,
        .ia-account-option.active {
            background: #dbeafe;
        }

        .ia-account-title {
            min-width: 0;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            padding-left: var(--indent, 0px);
        }

        .ia-account-type {
            color: #6b7280;
            white-space: nowrap;
            text-align: right;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .ia-account-head span:last-child {
            text-align: right;
        }

        .ia-button {
            height: 30px;
            min-width: 190px;
            border: 1px solid #cfd4da;
            border-radius: 3px;
            background: linear-gradient(#fff, #f0f0f0);
            color: #333;
            font-weight: 600;
            font-size: 14px;
        }

        .item-picker-wrap {
            position: relative;
            display: inline-block;
            margin-top: 22px;
        }

        .item-picker-panel {
            display: none;
            position: absolute;
            top: 34px;
            left: 0;
            width: min(1120px, calc(100vw - 48px));
            max-height: 390px;
            background: #f7f7f7;
            border: 1px solid #9da3aa;
            box-shadow: 0 4px 12px rgba(0,0,0,.18);
            z-index: 30;
            overflow: hidden;
        }

        .item-picker-panel.show { display: block; }

        .picker-search {
            width: 100%;
            height: 30px;
            border: 0;
            border-bottom: 1px solid #b8b8b8;
            padding: 4px 10px;
            font-size: 13px;
            outline: none;
        }

        .picker-list {
            max-height: 280px;
            overflow: auto;
        }

        .picker-add,
        .picker-row {
            display: grid;
            grid-template-columns: 280px 140px 1fr;
            gap: 12px;
            padding: 4px 12px;
            cursor: pointer;
            font-size: 13px;
            line-height: 1.25;
            align-items: center;
        }

        .picker-name.child {
            padding-left: 22px;
            font-size: 13px;
        }

        .picker-name.parent {
            font-weight: 600;
            font-size: 13px;
        }

        .picker-muted {
            color: #6b7280;
            font-size: 12px;
        }

        .item-picker-panel {
            width: min(1000px, calc(100vw - 48px));
            max-height: 320px;
        }
        .ia-grid-wrap {
            margin-top: 8px;
            height: 500px;
            border: 1px solid #c9e2cc;
            overflow: auto;
            background: #fff;
        }

        .ia-table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
            font-size: 14px;
        }

        .ia-table thead th {
            position: sticky;
            top: 0;
            background: #fff;
            color: #68717c;
            font-weight: 400;
            text-align: left;
            height: 24px;
            border-right: 1px solid #d3e5d6;
            border-bottom: 1px solid #cbdccf;
            padding: 3px 6px;
            white-space: nowrap;
            z-index: 2;
        }

        .ia-table tbody tr {
            height: 27px;
        }

        .ia-table tbody td {
            min-height: 27px;
            vertical-align: top;
            border-right: 1px solid #d3e5d6;
            padding: 2px 6px;
        }

        .ia-table textarea.row-description,
        .row-description,
        .ia-cell-input.row-description {
            display: block;
            width: 100%;
            min-height: 24px;
            height: 24px;
            resize: none;
            overflow: hidden;
            white-space: pre-wrap !important;
            word-break: break-word;
            overflow-wrap: anywhere;
            line-height: 18px;
            box-sizing: border-box;
        }

        .ia-table tbody tr:nth-child(odd) td { background: var(--qb-blue-row); }
        .ia-table tbody tr:nth-child(even) td { background: var(--qb-blue-row-alt); }

        .ia-table input,
        .ia-table select {
            width: 100%;
            height: 24px;
            min-height: 24px;
            border: 0;
            background: transparent;
            padding: 1px 4px;
            outline: none;
            box-sizing: border-box;
            font-size: 13px;
            display: block;
        }

        .ia-table textarea {
            width: 100%;
            min-height: 24px;
            height: 24px;
            border: 0;
            background: transparent;
            padding: 1px 4px;
            outline: none;
            box-sizing: border-box;
            font-size: 13px;
            line-height: 18px;
            resize: none;
            overflow: hidden;
            display: block;
        }

        .ia-table textarea.row-description {
            min-height: 24px;
            height: 24px;
        }

        .ia-table select.row-uom {
            cursor: pointer;
            appearance: auto;
            -webkit-appearance: auto;
            -moz-appearance: auto;
        }

        .ia-table input:focus,
        .ia-table select:focus,
        .ia-table textarea:focus {
            background: #fff;
            border-color: #7aa7d9;
        }

        .text-end { text-align: right !important; }

        .ia-bottom {
            display: grid;
            grid-template-columns: minmax(520px, 1fr) 360px;
            gap: 30px;
            padding: 18px 8px 0;
        }

        .memo-row {
            display: grid;
            grid-template-columns: 58px minmax(300px, 520px);
            align-items: center;
            margin-bottom: 10px;
        }

        .memo-row label { font-size: 15px; }

        .item-info-box {
            width: 340px;
            border: 1px solid #e5e7eb;
            padding: 8px 12px 14px;
            min-height: 105px;
            font-size: 14px;
        }

        .item-info-title {
            font-weight: 700;
            margin-bottom: 16px;
        }

        .item-info-line {
            margin: 8px 0;
        }

        .summary-box {
            margin-top: 2px;
            font-size: 15px;
        }

        .summary-line {
            display: grid;
            grid-template-columns: 1fr 90px;
            gap: 16px;
            margin-bottom: 10px;
        }

        .ia-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            padding: 12px 8px 10px;
            position: sticky;
            bottom: 0;
            background: #fff;
        }

        .ia-action-btn {
            min-width: 125px;
            height: 30px;
            border: 1px solid #cfd4da;
            border-radius: 2px;
            background: linear-gradient(#fff, #f1f1f1);
            font-size: 14px;
            font-weight: 600;
        }

        .ia-action-btn.primary {
            color: #fff;
            border-color: #31ac28 !important;
            background: linear-gradient( #87dd5f, #40bd2f);
        }


        .ia-item-cell {
            position: relative;
            width: 100%;
        }

        .ia-item-input,
        .ia-item-select,
        .ia-cell-input {
            width: 100%;
            height: 24px;
            min-height: 24px;
            border: 0;
            background: transparent;
            padding: 1px 4px;
            outline: none;
            font-size: 13px;
            box-sizing: border-box;
            display: block;
        }

        .ia-item-input:focus,
        .ia-item-select:focus,
        .ia-cell-input:focus {
            background: #fff;
            border-color: #7aa7d9;
        }

        .ia-item-select {
            cursor: pointer;
            color: #052A47;
            appearance: auto;
            -webkit-appearance: auto;
            -moz-appearance: auto;
        }

        .ia-item-select option {
            background: #ffffff;
            color: #052A47;
        }

        .ia-item-select optgroup {
            background: #e8f5e9;
            color: #047857;
            font-weight: 700;
        }

        .ia-row-dropdown {
            display: none;
            position: fixed;
            min-width: 420px;
            max-width: 720px;
            max-height: 230px;
            overflow: auto;
            background: #fff;
            border: 1px solid #9da3aa;
            box-shadow: 0 4px 12px rgba(0,0,0,.18);
            z-index: 9999;
            font-size: 13px;
        }

        .ia-row-dropdown.show {
            display: block;
        }

        .ia-row-option {
            display: grid;
            grid-template-columns: 170px 105px 1fr;
            gap: 10px;
            padding: 5px 8px;
            cursor: pointer;
            line-height: 1.25;
        }

        .ia-row-option:hover {
            background: #dbeafe;
        }

        .ia-row-option-type {
            color: #6b7280;
            white-space: nowrap;
        }

        .ia-row-option-desc {
            color: #111827;
        }

        @media (max-width: 1100px) {
            .ia-header-grid { grid-template-columns: 1fr; gap: 4px; }
            .ia-bottom { grid-template-columns: 1fr; }
        }


        /* ========== SIDEBAR TOGGLE LAYOUT FIX, SAME AS JOURNAL ENTRIES ========== */
        .main-content {
            transition: margin-left 0.3s ease, width 0.3s ease;
        }

        .main-content.sidebar-expanded {
            margin-left: 292px !important;
            width: calc(100% - 292px) !important;
        }

        .main-content.sidebar-collapsed {
            margin-left: 85px !important;
            width: calc(100% - 85px) !important;
        }

        @media (max-width: 992px) {
            .main-content,
            .main-content.sidebar-expanded,
            .main-content.sidebar-collapsed {
                margin-left: 0 !important;
                width: 100% !important;
            }
        }


        /* ========== COLLAPSED SIDEBAR ACTIVE STYLE FIX ========== */
        /* Same as Journal Entries: active icon highlight only, no green left stripe. */
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
    
/* ========== MOBILE HORIZONTAL OVERFLOW FIX ========== */
html,
body {
    width: 100%;
    max-width: 100%;
    overflow-x: hidden !important;
}

*,
*::before,
*::after {
    box-sizing: border-box;
}

.appPage,
#appPage,
.main-content,
.item-adjustment-window,
.ia-form-area {
    width: 100%;
    max-width: 100%;
    min-width: 0;
}

.ia-grid-wrap {
    width: 100%;
    max-width: 100%;
    overflow-x: auto !important;
    overflow-y: auto;
    -webkit-overflow-scrolling: touch;
}

.ia-table {
    max-width: none;
}

.mobile-nav {
    width: 100%;
    max-width: 100vw;
    box-sizing: border-box;
}

.mobile-nav .nav {
    width: 100%;
    max-width: 100%;
}

@media (max-width: 992px) {
    body {
        position: relative;
    }

    .main-content,
    .main-content.sidebar-expanded,
    .main-content.sidebar-collapsed {
        margin-left: 0 !important;
        width: 100% !important;
        max-width: 100% !important;
        padding-bottom: 92px !important;
        overflow-x: hidden !important;
    }

    .item-adjustment-window {
        min-height: calc(100dvh - 92px);
        overflow-x: hidden;
    }

    .ia-form-area {
        padding: 14px 12px 0;
        overflow-x: hidden;
    }

    .ia-header-grid {
        display: grid;
        grid-template-columns: minmax(0, 1fr) !important;
        column-gap: 0 !important;
        row-gap: 8px;
        width: 100%;
        max-width: 100%;
    }

    .ia-header-grid > div {
        width: 100%;
        min-width: 0;
    }

    .ia-field-row {
        display: grid;
        grid-template-columns: minmax(0, 1fr) !important;
        gap: 5px;
        width: 100%;
        max-width: 100%;
        margin-bottom: 8px;
    }

    .ia-field-row label,
    .ia-control,
    .ia-select,
    .ia-account-dropdown,
    .ia-account-trigger {
        width: 100%;
        max-width: 100%;
        min-width: 0;
    }

    .ia-account-menu {
        position: fixed;
        left: 12px !important;
        right: 12px !important;
        top: auto;
        width: auto !important;
        max-width: none !important;
    }

    .ia-account-head,
    .ia-account-option {
        grid-template-columns: minmax(0, 1fr) minmax(90px, 125px);
        gap: 8px;
    }

    .item-picker-wrap {
        display: block;
        width: 100%;
        max-width: 100%;
        margin-top: 12px;
    }

    .ia-button {
        width: 100%;
        max-width: 100%;
        min-width: 0;
    }

    .item-picker-panel {
        position: fixed;
        left: 12px !important;
        right: 12px !important;
        top: 90px !important;
        width: auto !important;
        max-width: none !important;
        max-height: calc(100dvh - 190px);
    }

    .picker-list {
        max-height: calc(100dvh - 245px);
    }

    .picker-add,
    .picker-row {
        grid-template-columns: minmax(130px, 1fr) minmax(80px, 110px) minmax(160px, 1.3fr);
        min-width: 540px;
    }

    .ia-grid-wrap {
        margin-top: 10px;
        height: min(500px, calc(100dvh - 250px));
        width: 100%;
        max-width: 100%;
        overflow-x: auto !important;
        overflow-y: auto !important;
    }

    .ia-table {
        width: 100%;
        min-width: 900px;
        table-layout: fixed;
    }

    .ia-bottom {
        display: grid;
        grid-template-columns: minmax(0, 1fr) !important;
        gap: 16px;
        width: 100%;
        max-width: 100%;
        padding: 16px 0 0;
    }

    .memo-row {
        display: grid;
        grid-template-columns: minmax(0, 1fr) !important;
        gap: 5px;
        width: 100%;
        max-width: 100%;
    }

    .item-info-box,
    .summary-box {
        width: 100%;
        max-width: 100%;
        min-width: 0;
    }

    .summary-line {
        grid-template-columns: minmax(0, 1fr) auto;
        gap: 10px;
    }

    .ia-actions {
        width: 100%;
        max-width: 100%;
        padding: 12px 0 10px;
        gap: 8px;
        flex-wrap: wrap;
    }

    .ia-action-btn {
        flex: 1 1 calc(50% - 8px);
        min-width: 130px;
        max-width: 100%;
    }

    .ia-row-dropdown {
        left: 12px !important;
        right: 12px !important;
        width: auto !important;
        min-width: 0 !important;
        max-width: none !important;
    }

    .ia-row-option {
        grid-template-columns: minmax(120px, 1fr) minmax(80px, 100px) minmax(150px, 1.2fr);
        min-width: 500px;
    }

    .mobile-nav .nav-link {
        min-width: 0;
        padding-left: 6px;
        padding-right: 6px;
    }

    .mobile-nav .more-dropdown {
        max-width: calc(100vw - 24px);
    }
}

@media (max-width: 480px) {
    .ia-form-area {
        padding-left: 10px;
        padding-right: 10px;
    }

    .ia-actions {
        flex-direction: column;
    }

    .ia-action-btn {
        width: 100%;
        flex: 1 1 auto;
    }

    .mobile-nav .nav {
        justify-content: space-evenly;
    }

    .mobile-nav .nav-item {
        min-width: 0;
        flex: 1 1 0;
    }

    .mobile-nav .nav-link {
        width: 100%;
    }
}

    </style>
</head>
<body>
    <div class="appPage" id="appPage">
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
                                <a class="nav-link" href="batch_transaction.php">
                                    <i class="bi bi-collection"></i>
                                    <span class="nav-text">Batch Transaction</span>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link active" href="item_adjustment.php">
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
                <div class="navbar-top item-adjustment-top">
                    <div class="page-title">
                        <h2>Items Adjustment</h2>
                        <p>Inventory item adjustment</p>
                    </div>
                </div>

                <div class="item-adjustment-window">
                    <form id="itemAdjustmentForm" class="ia-form-area" autocomplete="off">
                        <div class="ia-header-grid">
                            <div>
                                <div class="ia-field-row">
                                    <label for="adjustmentType">Adjustment Type</label>
                                    <select class="ia-select adjustment-type" id="adjustmentType" name="adjustment_type">
                                        <option value="quantity">Quantity</option>
                                        <option value="total_value" selected>Total Value</option>
                                        <option value="quantity_total_value">Quantity and Total Value</option>
                                    </select>
                                </div>
                                <div class="ia-field-row">
                                    <label for="adjustmentDate">Adjustment Date</label>
                                    <input class="ia-control" type="date" id="adjustmentDate" name="adjustment_date" value="<?php echo date('Y-m-d'); ?>">
                                </div>
                                <div class="ia-field-row">
                                    <label for="adjustmentAccount">Adjustment Account</label>
                                    <div class="ia-account-dropdown" id="adjustmentAccountDropdown">
                                        <input type="hidden" id="adjustmentAccount" name="adjustment_account_id" value="">
                                        <button type="button" class="ia-account-trigger" id="adjustmentAccountTrigger">
                                            <span class="ia-account-selected">Select Account</span>
                                        </button>
                                        <div class="ia-account-menu" id="adjustmentAccountMenu">
                                            <div class="ia-account-head">
                                                <span>Account Title</span>
                                                <span>Type of Account</span>
                                            </div>
                                            <div class="ia-account-options">
                                                <?php foreach ($accounts as $account): ?>
                                                    <?php
                                                        $accountLevel = max(0, (int)($account['level'] ?? 0));
                                                        $accountIndent = min($accountLevel * 22, 110);
                                                        $accountTitle = trim((string)($account['account_title'] ?? ''));
                                                        $accountType = trim((string)($account['account_type'] ?? ''));
                                                    ?>
                                                    <button type="button"
                                                            class="ia-account-option"
                                                            data-id="<?php echo (int)$account['account_id']; ?>"
                                                            data-title="<?php echo htmlspecialchars($accountTitle, ENT_QUOTES); ?>"
                                                            data-type="<?php echo htmlspecialchars($accountType, ENT_QUOTES); ?>"
                                                            style="--indent:<?php echo (int)$accountIndent; ?>px;">
                                                        <span class="ia-account-title"><?php echo htmlspecialchars($accountTitle); ?></span>
                                                        <span class="ia-account-type"><?php echo htmlspecialchars($accountType); ?></span>
                                                    </button>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div>
                                <div class="ia-field-row">
                                    <label for="referenceNo">Reference No.</label>
                                    <input class="ia-control" type="text" id="referenceNo" name="reference_no" value="<?php echo (int)$next_reference_no; ?>" readonly>
                                </div>
                                <div class="ia-field-row">
                                    <label for="customerJob">Customer</label>
                                    <select class="ia-select" id="customerJob" name="customer_id">
                                        <option value=""></option>
                                        <?php foreach ($customers as $customer): ?>
                                            <option value="<?php echo (int)$customer['customer_id']; ?>">
                                                <?php echo htmlspecialchars($customer['customer_name'] . (!empty($customer['store_name']) ? ' - ' . $customer['store_name'] : '')); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="item-picker-wrap">
                            <button type="button" class="ia-button" id="findItemsBtn">Find &amp; Select Items...</button>
                            <div class="item-picker-panel" id="itemPickerPanel">
                                <input type="text" class="picker-search" id="itemPickerSearch" placeholder="">
                                <div class="picker-list" id="itemPickerList">
                                    <div class="picker-add">&lt; Add New &gt;</div>
                                    <?php
                                        $lastCategory = null;
                                        foreach ($items as $item):
                                            $category = trim((string)($item['category'] ?? '')) ?: 'Uncategorized';
                                            $isChild = $lastCategory === $category;
                                            $lastCategory = $category;
                                            $totalValue = ((float)($item['stock'] ?? 0)) * ((float)($item['unit_price'] ?? 0));
                                    ?>
                                        <div class="picker-row" data-item-id="<?php echo (int)$item['item_id']; ?>"
                                            data-name="<?php echo htmlspecialchars($item['item_name'], ENT_QUOTES); ?>"
                                            data-description="<?php echo htmlspecialchars($item['description'] ?: $item['item_name'], ENT_QUOTES); ?>"
                                            data-category="<?php echo htmlspecialchars($category, ENT_QUOTES); ?>"
                                            data-stock="<?php echo htmlspecialchars((string)($item['stock'] ?? 0), ENT_QUOTES); ?>"
                                            <?php $itemUomOptions = ia_item_uom_options($item, $itemUoms); ?>
                                            data-uom="<?php echo htmlspecialchars((string)($itemUomOptions[0] ?? ''), ENT_QUOTES); ?>"
                                            data-uoms="<?php echo htmlspecialchars(json_encode($itemUomOptions), ENT_QUOTES); ?>"
                                            data-price="<?php echo htmlspecialchars((string)($item['unit_price'] ?? 0), ENT_QUOTES); ?>"
                                            data-total="<?php echo htmlspecialchars((string)$totalValue, ENT_QUOTES); ?>">
                                            <div class="picker-name <?php echo $isChild ? 'child' : 'parent'; ?>"><?php echo htmlspecialchars($item['item_name']); ?></div>
                                            <div class="picker-muted">Inventory Part</div>
                                            <div><?php echo htmlspecialchars($item['description'] ?: $item['item_name']); ?></div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>

                        <div class="ia-grid-wrap">
                            <table class="ia-table" id="adjustmentTable">
                                <thead>
                                    <tr id="adjustmentHead"></tr>
                                </thead>
                                <tbody id="adjustmentBody"></tbody>
                            </table>
                        </div>

                        <div class="ia-bottom">
                            <div>
                                <div class="memo-row">
                                    <label for="memo">Memo</label>
                                    <input class="ia-control" type="text" id="memo" name="memo">
                                </div>

                                <div class="item-info-box">
                                    <div class="item-info-title">ITEM INFO AFTER ADJUSTMENT</div>
                                    <div class="item-info-line">Quantity on Hand <span id="infoQty"></span></div>
                                    <div class="item-info-line">Avg Cost per Item <span id="infoAvg"></span></div>
                                    <div class="item-info-line">Value <span id="infoValue"></span></div>
                                </div>
                            </div>

                            <div class="summary-box">
                                <div class="summary-line">
                                    <span>Total Value of Adjustment</span>
                                    <strong class="text-end" id="totalAdjustmentValue">0.00</strong>
                                </div>
                                <div class="summary-line">
                                    <span>Number of Item Adjustments</span>
                                    <strong class="text-end" id="numberAdjustments">0</strong>
                                </div>
                            </div>
                        </div>

                        <div class="ia-actions">
                            <button type="button" class="ia-action-btn" id="saveCloseBtn">Save &amp; Close</button>
                            <button type="button" class="ia-action-btn primary" id="saveNewBtn">Save &amp; New</button>
                            <button type="button" class="ia-action-btn" id="clearBtn">Clear</button>
                        </div>
                    </form>
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
                    <a class="dropdown-item" href="batch_transaction.php"><i class="bi bi-collection"></i><span>Batch
                            Transactions</span></a>
                    <a class="dropdown-item active" href="item_adjustment.php"><i class="bi bi-sliders"></i><span>Item
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

    </div>

    
<script>
// ========== SIDEBAR DROPDOWN HANDLING, SAME AS JOURNAL ENTRIES ==========

function toggleSidebarDropdown(event, targetId) {
    event.preventDefault();
    event.stopPropagation();

    const target = document.getElementById(targetId);
    const btn = event.currentTarget;
    const arrow = btn.querySelector('.dropdown-arrow');
    const sidebar = document.getElementById('sidebar');

    if (!target || !sidebar) return;

    if (sidebar.classList.contains('collapsed')) {
        sidebar.classList.remove('collapsed');
        localStorage.setItem('sidebarCollapsed', 'false');

        setTimeout(() => {
            document.querySelectorAll('.sidebar .collapse.show').forEach(collapse => {
                if (collapse.id !== targetId) {
                    collapse.classList.remove('show');
                    const otherBtn = document.querySelector(`[onclick*="${collapse.id}"]`);
                    if (otherBtn) {
                        const otherArrow = otherBtn.querySelector('.dropdown-arrow');
                        if (otherArrow) otherArrow.style.transform = 'translateY(-50%) rotate(0deg)';
                    }
                    const otherParent = collapse.closest('.dropdown-nav');
                    if (otherParent) otherParent.classList.remove('open');
                }
            });

            target.classList.add('show');
            const parent = target.closest('.dropdown-nav');
            if (parent) parent.classList.add('open');
            if (arrow) arrow.style.transform = 'translateY(-50%) rotate(180deg)';
            applySidebarLayoutState();
        }, 50);
        return;
    }

    if (target.classList.contains('show')) {
        target.classList.remove('show');
        const parent = target.closest('.dropdown-nav');
        if (parent) parent.classList.remove('open');
        if (arrow) arrow.style.transform = 'translateY(-50%) rotate(0deg)';
    } else {
        document.querySelectorAll('.sidebar .collapse.show').forEach(collapse => {
            if (collapse.id !== targetId) {
                collapse.classList.remove('show');
                const otherBtn = document.querySelector(`[onclick*="${collapse.id}"]`);
                if (otherBtn) {
                    const otherArrow = otherBtn.querySelector('.dropdown-arrow');
                    if (otherArrow) otherArrow.style.transform = 'translateY(-50%) rotate(0deg)';
                }
                const otherParent = collapse.closest('.dropdown-nav');
                if (otherParent) otherParent.classList.remove('open');
            }
        });

        target.classList.add('show');
        const parent = target.closest('.dropdown-nav');
        if (parent) parent.classList.add('open');
        if (arrow) arrow.style.transform = 'translateY(-50%) rotate(180deg)';
    }

    applySidebarLayoutState();
}

function setActiveSidebarItem() {
    const currentPage = window.location.pathname.split('/').pop();

    document.querySelectorAll('.sidebar .nav-link').forEach(link => {
        link.classList.remove('active');
    });

    document.querySelectorAll('.sidebar .nav-item').forEach(item => {
        item.classList.remove('active');
    });

    document.querySelectorAll('.sidebar .nav-link').forEach(link => {
        const href = link.getAttribute('href');
        if (href === currentPage || (currentPage === '' && href === 'item_adjustment.php')) {
            link.classList.add('active');
            const item = link.closest('.nav-item');
            if (item) item.classList.add('active');

            const collapseDiv = link.closest('.collapse');
            if (collapseDiv) {
                collapseDiv.classList.add('show');
                const dropdownParent = collapseDiv.closest('.dropdown-nav');
                if (dropdownParent) dropdownParent.classList.add('open');

                const parentBtn = document.querySelector(`[onclick*="${collapseDiv.id}"]`);
                if (parentBtn) {
                    parentBtn.setAttribute('aria-expanded', 'true');
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
            dropdownNav.classList.add('open');

            if (parentLink) {
                const arrow = parentLink.querySelector('.dropdown-arrow');
                if (arrow) arrow.style.transform = 'translateY(-50%) rotate(180deg)';

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

    if (sidebar) sidebar.classList.remove('active');

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

        if (!sidebar.classList.contains('active')) closeMobileSidebar();
        return;
    }

    sidebar.classList.toggle('collapsed');
    localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed') ? 'true' : 'false');

    if (sidebar.classList.contains('collapsed')) {
        document.querySelectorAll('.sidebar .collapse.show').forEach(collapse => {
            collapse.classList.remove('show');
            const parent = collapse.closest('.dropdown-nav');
            if (parent) parent.classList.remove('open');

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
            if (window.innerWidth <= 992) closeMobileSidebar();
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

    const sidebarContent = document.querySelector('.sidebar-content');
    const activeLink = document.querySelector('.sidebar .nav-link.active');

    if (sidebarContent && activeLink) {
        setTimeout(() => {
            activeLink.scrollIntoView({
                behavior: 'smooth',
                block: 'center'
            });
        }, 200);
    }
});

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


    function closeAdjustmentAccountDropdown() {
        const dropdown = document.getElementById('adjustmentAccountDropdown');
        if (dropdown) dropdown.classList.remove('open');
    }

    function resetAdjustmentAccountDropdown() {
        const accountInput = document.getElementById('adjustmentAccount');
        const selectedText = document.querySelector('#adjustmentAccountTrigger .ia-account-selected');
        if (accountInput) accountInput.value = '';
        if (selectedText) selectedText.textContent = 'Select Account';
        document.querySelectorAll('.ia-account-option.active').forEach(option => option.classList.remove('active'));
        closeAdjustmentAccountDropdown();
    }

    function initializeAdjustmentAccountDropdown() {
        const dropdown = document.getElementById('adjustmentAccountDropdown');
        const trigger = document.getElementById('adjustmentAccountTrigger');
        const accountInput = document.getElementById('adjustmentAccount');
        const selectedText = trigger ? trigger.querySelector('.ia-account-selected') : null;

        if (!dropdown || !trigger || !accountInput || !selectedText) return;

        trigger.addEventListener('click', function(event) {
            event.preventDefault();
            event.stopPropagation();
            dropdown.classList.toggle('open');
        });

        document.querySelectorAll('.ia-account-option').forEach(option => {
            option.addEventListener('click', function(event) {
                event.preventDefault();
                event.stopPropagation();

                const accountId = this.dataset.id || '';
                const accountTitle = this.dataset.title || '';
                const accountType = this.dataset.type || '';

                accountInput.value = accountId;
                selectedText.textContent = accountType ? `${accountTitle}  ${accountType}` : accountTitle;

                document.querySelectorAll('.ia-account-option.active').forEach(activeOption => activeOption.classList.remove('active'));
                this.classList.add('active');
                closeAdjustmentAccountDropdown();
            });
        });

        document.addEventListener('click', function(event) {
            if (!event.target.closest('#adjustmentAccountDropdown')) {
                closeAdjustmentAccountDropdown();
            }
        });
    }


        const adjustmentType = document.getElementById('adjustmentType');
    const adjustmentHead = document.getElementById('adjustmentHead');
    const adjustmentBody = document.getElementById('adjustmentBody');
    const findItemsBtn = document.getElementById('findItemsBtn');
    const itemPickerPanel = document.getElementById('itemPickerPanel');
    const itemPickerSearch = document.getElementById('itemPickerSearch');
    const totalAdjustmentValue = document.getElementById('totalAdjustmentValue');
    const numberAdjustments = document.getElementById('numberAdjustments');
    const infoQty = document.getElementById('infoQty');
    const infoAvg = document.getElementById('infoAvg');
    const infoValue = document.getElementById('infoValue');

    const columnSets = {
        quantity: [
            ['item', 'ITEM', '24%'], ['description', 'DESCRIPTION', '33%'], ['uom', 'U/M', '7%'],
            ['qty', 'QTY ON HAND', '11%'], ['new_qty', 'NEW QUANTITY', '12%'], ['qty_diff', 'QTY DIFFERE...', '13%']
        ],
        total_value: [
            ['item', 'ITEM', '24%'], ['description', 'DESCRIPTION', '36%'], ['uom', 'U/M', '7%'],
            ['qty', 'QTY ON HAND', '11%'], ['total_value', 'TOTAL VALUE', '11%'], ['new_value', 'NEW VALUE', '11%']
        ],
        quantity_total_value: [
            ['item', 'ITEM', '22%'], ['description', 'DESCRIPTION', '32%'], ['uom', 'U/M', '7%'],
            ['qty', 'QTY ON HAND', '10%'], ['new_qty', 'NEW QUANTITY', '10%'], ['qty_diff', 'QTY DIFFERE...', '9%'], ['new_value', 'NEW VALUE', '10%']
        ]
    };

    function parseItemUoms(rawUoms, fallbackUom) {
        let uoms = [];
        try {
            const decoded = JSON.parse(rawUoms || '[]');
            if (Array.isArray(decoded)) uoms = decoded;
        } catch (e) {
            uoms = [];
        }
        if (fallbackUom) uoms.unshift(fallbackUom);
        const seen = new Set();
        return uoms
            .map(value => String(value || '').trim())
            .filter(value => {
                if (!value) return false;
                const key = value.toLowerCase();
                if (seen.has(key)) return false;
                seen.add(key);
                return true;
            });
    }

    const allItems = Array.from(document.querySelectorAll('.picker-row')).map(row => ({
        id: row.dataset.itemId,
        name: row.dataset.name || '',
        description: row.dataset.description || '',
        category: row.dataset.category || 'Uncategorized',
        stock: Number(row.dataset.stock || 0),
        uom: row.dataset.uom || '',
        uoms: parseItemUoms(row.dataset.uoms, row.dataset.uom || ''),
        price: Number(row.dataset.price || 0),
        total: Number(row.dataset.total || 0)
    }));

    let adjustmentRows = Array.from({ length: 18 }, () => createBlankRow());
    let activeDropdown = null;

    function createBlankRow() {
        return {
            itemId: '',
            name: '',
            description: '',
            category: '',
            stock: '',
            uom: '',
            uoms: [],
            price: 0,
            total: '',
            newQty: '',
            qtyDiff: '',
            newValue: ''
        };
    }

    function buildGroupedItemSelectOptions(selectedId = '') {
        const groupedItems = {};
        allItems.forEach(item => {
            const category = String(item.category || '').trim() || 'Uncategorized';
            if (!groupedItems[category]) groupedItems[category] = [];
            groupedItems[category].push(item);
        });

        let html = '<option value="">-- Select Item --</option>';
        Object.keys(groupedItems).sort((a, b) => a.localeCompare(b)).forEach(category => {
            html += `<optgroup label="${esc(category)}">`;
            groupedItems[category]
                .slice()
                .sort((a, b) => String(a.name || '').localeCompare(String(b.name || '')))
                .forEach(item => {
                    const selected = String(item.id) === String(selectedId) ? ' selected' : '';
                    html += `<option value="${esc(item.id)}"${selected}>${esc(item.name)}</option>`;
                });
            html += '</optgroup>';
        });
        return html;
    }

    function buildUomOptions(row) {
        const options = parseItemUoms(JSON.stringify(row.uoms || []), row.uom || '');
        if (!options.length) {
            return '<option value=""></option>';
        }
        return options.map(uom => {
            const selected = String(uom) === String(row.uom || '') ? ' selected' : '';
            return `<option value="${esc(uom)}"${selected}>${esc(uom)}</option>`;
        }).join('');
    }

    function esc(value) {
        return String(value ?? '').replace(/[&<>"']/g, function (char) {
            return {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            }[char];
        });
    }

    function money(value) {
        if (value === '' || value === null || value === undefined) return '';
        const num = Number(String(value).replace(/,/g, '') || 0);
        return num.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function removeCommas(value) {
        return String(value ?? '').replace(/,/g, '');
    }

    function toNumberValue(value) {
        const cleaned = removeCommas(value).trim();
        if (cleaned === '') return 0;
        const num = Number(cleaned);
        return Number.isFinite(num) ? num : 0;
    }

    function formatNumberWithCommas(value, decimals = null) {
        if (value === '' || value === null || value === undefined) return '';
        const cleaned = removeCommas(value).trim();
        if (cleaned === '' || cleaned === '-' || cleaned === '.') return cleaned;
        const num = Number(cleaned);
        if (!Number.isFinite(num)) return value;

        const hasDecimal = cleaned.includes('.');
        const maxDigits = hasDecimal ? 2 : 0;
        const minDigits = decimals === null ? (hasDecimal ? 2 : 0) : decimals;

        return num.toLocaleString('en-US', {
            minimumFractionDigits: minDigits,
            maximumFractionDigits: decimals === null ? maxDigits : decimals
        });
    }

    function bindCommaFormatter(input, onValueChange, decimals = null) {
        input.addEventListener('focus', () => {
            input.value = removeCommas(input.value);
            setTimeout(() => input.select(), 0);
        });

        input.addEventListener('input', () => {
            let cleanValue = removeCommas(input.value);
            cleanValue = cleanValue.replace(/[^0-9.\-]/g, '');

            const firstDot = cleanValue.indexOf('.');
            if (firstDot !== -1) {
                cleanValue = cleanValue.slice(0, firstDot + 1) + cleanValue.slice(firstDot + 1).replace(/\./g, '');
            }

            if (cleanValue.includes('-')) {
                cleanValue = (cleanValue.startsWith('-') ? '-' : '') + cleanValue.replace(/-/g, '');
            }

            input.value = cleanValue;
            onValueChange(cleanValue);
        });

        input.addEventListener('blur', () => {
            input.value = formatNumberWithCommas(input.value, decimals);
        });
    }

    function renderHead() {
        const cols = columnSets[adjustmentType.value] || columnSets.total_value;
        adjustmentHead.innerHTML = cols.map(col => `<th style="width:${col[2]}">${col[1]}</th>`).join('');
    }

    function ensureRows() {
        while (adjustmentRows.length < 18) adjustmentRows.push(createBlankRow());
    }

    function renderRows() {
        ensureRows();
        const cols = columnSets[adjustmentType.value] || columnSets.total_value;
        let html = '';

        adjustmentRows.forEach((item, rowIndex) => {
            html += `<tr data-row-index="${rowIndex}">`;

            cols.forEach((col) => {
                const key = col[0];

                if (key === 'item') {
                    html += `
                        <td>
                            <div class="ia-item-cell">
                                <select class="ia-item-select row-item-select" data-row-index="${rowIndex}">
                                    ${buildGroupedItemSelectOptions(item.itemId)}
                                </select>
                            </div>
                        </td>`;
                } else if (key === 'description') {
                    html += `<td><textarea class="ia-cell-input row-description" data-row-index="${rowIndex}" rows="1">${esc(item.description)}</textarea></td>`;
                } else if (key === 'uom') {
                    html += `<td><select class="ia-cell-input row-uom" data-row-index="${rowIndex}">${buildUomOptions(item)}</select></td>`;
                } else if (key === 'qty') {
                    html += `<td><input type="text" inputmode="decimal" class="ia-cell-input text-end row-stock" value="${esc(formatNumberWithCommas(item.stock))}" data-row-index="${rowIndex}"></td>`;
                } else if (key === 'total_value') {
                    html += `<td><input type="text" inputmode="decimal" class="ia-cell-input text-end row-total-value" value="${esc(formatNumberWithCommas(item.total, 2))}" data-row-index="${rowIndex}"></td>`;
                } else if (key === 'new_qty') {
                    html += `<td><input type="text" inputmode="decimal" class="ia-cell-input text-end new-qty" value="${esc(formatNumberWithCommas(item.newQty))}" data-row-index="${rowIndex}"></td>`;
                } else if (key === 'qty_diff') {
                    html += `<td><input type="text" inputmode="decimal" class="ia-cell-input text-end qty-diff" value="${esc(formatNumberWithCommas(item.qtyDiff))}" data-row-index="${rowIndex}"></td>`;
                } else if (key === 'new_value') {
                    html += `<td><input type="text" inputmode="decimal" class="ia-cell-input text-end new-value" value="${esc(formatNumberWithCommas(item.newValue, 2))}" data-row-index="${rowIndex}"></td>`;
                }
            });

            html += '</tr>';
        });

        adjustmentBody.innerHTML = html;
        bindRowInputs();
        requestAnimationFrame(resizeAllDescriptions);
        updateSummary();
    }

    function rebuildTable() {
        renderHead();
        renderRows();
    }

    function getFilteredItems(query) {
        const q = String(query || '').toLowerCase().trim();
        if (!q) return allItems.slice(0, 25);
        return allItems.filter(item =>
            item.name.toLowerCase().includes(q) ||
            item.description.toLowerCase().includes(q) ||
            item.uom.toLowerCase().includes(q)
        ).slice(0, 25);
    }

    function hideRowDropdown() {
        if (activeDropdown) {
            activeDropdown.remove();
            activeDropdown = null;
        }
    }

    function showRowDropdown(input) {
        hideRowDropdown();

        const rowIndex = Number(input.dataset.rowIndex);
        const dropdown = document.createElement('div');
        dropdown.className = 'ia-row-dropdown show';

        const rect = input.getBoundingClientRect();
        dropdown.style.left = rect.left + 'px';
        dropdown.style.top = (rect.bottom + 1) + 'px';
        dropdown.style.width = Math.max(rect.width, 520) + 'px';

        const filtered = getFilteredItems(input.value);
        dropdown.innerHTML = filtered.length
            ? filtered.map(item => `
                <div class="ia-row-option" data-item-id="${esc(item.id)}">
                    <div>${esc(item.name)}</div>
                    <div class="ia-row-option-type">Inventory Part</div>
                    <div class="ia-row-option-desc">${esc(item.description)}</div>
                </div>
            `).join('')
            : `<div class="ia-row-option" data-item-id="">No item found</div>`;

        document.body.appendChild(dropdown);
        activeDropdown = dropdown;

        dropdown.querySelectorAll('.ia-row-option[data-item-id]').forEach(option => {
            option.addEventListener('mousedown', (event) => {
                event.preventDefault();
                const itemId = option.dataset.itemId;
                if (!itemId) return;
                const selected = allItems.find(item => String(item.id) === String(itemId));
                if (selected) {
                    setItemToRow(rowIndex, selected);
                    hideRowDropdown();
                    rebuildTable();
                    updateInfo(adjustmentRows[rowIndex]);
                }
            });
        });
    }

    function setItemToRow(rowIndex, selected) {
        adjustmentRows[rowIndex] = {
            itemId: selected.id,
            name: selected.name,
            description: selected.description,
            category: selected.category || 'Uncategorized',
            stock: selected.stock,
            uom: selected.uom,
            uoms: selected.uoms || [],
            price: selected.price,
            total: selected.total,
            newQty: '',
            qtyDiff: '',
            newValue: ''
        };

        if (rowIndex === adjustmentRows.length - 1) {
            adjustmentRows.push(createBlankRow());
        }
    }

    function addItemFromPicker(selected) {
        let rowIndex = adjustmentRows.findIndex(row => !row.itemId && !row.name && !row.description);
        if (rowIndex === -1) {
            adjustmentRows.push(createBlankRow());
            rowIndex = adjustmentRows.length - 1;
        }
        setItemToRow(rowIndex, selected);
        rebuildTable();
        updateInfo(adjustmentRows[rowIndex]);
    }

    function resizeDescriptionTextarea(textarea) {
        if (!textarea) return;

        const defaultHeight = 24;
        textarea.style.height = defaultHeight + 'px';

        const rowHeight = Math.max(defaultHeight, textarea.scrollHeight);
        textarea.style.height = rowHeight + 'px';

        const row = textarea.closest('tr');
        if (!row) return;

        row.style.height = rowHeight > defaultHeight ? (rowHeight + 4) + 'px' : '27px';
    }

    function resizeAllDescriptions() {
        adjustmentBody.querySelectorAll('textarea.row-description').forEach(textarea => {
            resizeDescriptionTextarea(textarea);
        });
    }

    function bindRowInputs() {
        adjustmentBody.querySelectorAll('tr[data-row-index]').forEach(row => {
            const rowIndex = Number(row.dataset.rowIndex);
            const item = adjustmentRows[rowIndex];

            row.addEventListener('click', () => {
                if (item.name || item.description) updateInfo(item);
            });
        });

        adjustmentBody.querySelectorAll('.row-item-select').forEach(select => {
            const rowIndex = Number(select.dataset.rowIndex);

            select.addEventListener('change', () => {
                const selected = allItems.find(item => String(item.id) === String(select.value));
                if (selected) {
                    setItemToRow(rowIndex, selected);
                    rebuildTable();
                    updateInfo(adjustmentRows[rowIndex]);
                } else {
                    adjustmentRows[rowIndex] = createBlankRow();
                    rebuildTable();
                    updateSummary();
                }
            });
        });

        adjustmentBody.querySelectorAll('.row-description').forEach(textarea => {
            resizeDescriptionTextarea(textarea);

            textarea.addEventListener('input', () => {
                adjustmentRows[Number(textarea.dataset.rowIndex)].description = textarea.value;
                resizeDescriptionTextarea(textarea);
                updateSummary();
            });
        });

        adjustmentBody.querySelectorAll('.row-uom').forEach(select => {
            select.addEventListener('change', () => {
                adjustmentRows[Number(select.dataset.rowIndex)].uom = select.value;
            });
        });

        adjustmentBody.querySelectorAll('.row-stock').forEach(input => {
            bindCommaFormatter(input, (value) => {
                const rowIndex = Number(input.dataset.rowIndex);
                adjustmentRows[rowIndex].stock = value;
                recalcQtyDiff(rowIndex);
                updateSummary();
                updateInfo(adjustmentRows[rowIndex]);
            });
        });

        adjustmentBody.querySelectorAll('.row-total-value').forEach(input => {
            bindCommaFormatter(input, (value) => {
                const rowIndex = Number(input.dataset.rowIndex);
                adjustmentRows[rowIndex].total = value;
                updateSummary();
                updateInfo(adjustmentRows[rowIndex]);
            }, 2);
        });

        adjustmentBody.querySelectorAll('.new-qty').forEach(input => {
            bindCommaFormatter(input, (value) => {
                const rowIndex = Number(input.dataset.rowIndex);
                adjustmentRows[rowIndex].newQty = value;
                recalcQtyDiff(rowIndex);
                updateSummary();
                updateInfo(adjustmentRows[rowIndex]);
                refreshQtyDiffInput(rowIndex);
            });
        });

        adjustmentBody.querySelectorAll('.qty-diff').forEach(input => {
            bindCommaFormatter(input, (value) => {
                const rowIndex = Number(input.dataset.rowIndex);
                adjustmentRows[rowIndex].qtyDiff = value;
                updateSummary();
                updateInfo(adjustmentRows[rowIndex]);
            });
        });

        adjustmentBody.querySelectorAll('.new-value').forEach(input => {
            bindCommaFormatter(input, (value) => {
                const rowIndex = Number(input.dataset.rowIndex);
                adjustmentRows[rowIndex].newValue = value;
                updateSummary();
                updateInfo(adjustmentRows[rowIndex]);
            }, 2);
        });
    }

    function recalcQtyDiff(rowIndex) {
        const row = adjustmentRows[rowIndex];
        if (row.newQty !== '' && row.stock !== '') {
            row.qtyDiff = toNumberValue(row.newQty) - toNumberValue(row.stock);
        } else {
            row.qtyDiff = '';
        }
    }

    function refreshQtyDiffInput(rowIndex) {
        const input = adjustmentBody.querySelector(`.qty-diff[data-row-index="${rowIndex}"]`);
        if (input) input.value = formatNumberWithCommas(adjustmentRows[rowIndex].qtyDiff);
    }

    function filledRows() {
        return adjustmentRows.filter(row => row.itemId || row.name || row.description || row.newQty !== '' || row.newValue !== '');
    }

    function updateSummary() {
        let total = 0;
        filledRows().forEach(item => {
            if (item.newValue !== undefined && item.newValue !== '') {
                total += toNumberValue(item.newValue) - toNumberValue(item.total || 0);
            }
        });
        totalAdjustmentValue.textContent = money(total) || '0.00';
        numberAdjustments.textContent = filledRows().length;
    }

    function updateInfo(item) {
        const finalQty = item.newQty !== undefined && item.newQty !== '' ? toNumberValue(item.newQty) : toNumberValue(item.stock || 0);
        const finalValue = item.newValue !== undefined && item.newValue !== '' ? toNumberValue(item.newValue) : toNumberValue(item.total || 0);
        const avg = finalQty > 0 ? finalValue / finalQty : 0;
        infoQty.textContent = finalQty || '';
        infoAvg.textContent = finalQty || finalValue ? ' ' + money(avg) : '';
        infoValue.textContent = finalValue ? ' ' + money(finalValue) : '';
    }

    findItemsBtn.addEventListener('click', () => {
        itemPickerPanel.classList.toggle('show');
        if (itemPickerPanel.classList.contains('show')) itemPickerSearch.focus();
    });

    document.addEventListener('click', (event) => {
        if (!event.target.closest('.item-picker-wrap')) {
            itemPickerPanel.classList.remove('show');
        }

        if (!event.target.closest('.ia-row-dropdown') && !event.target.closest('.row-item-select')) {
            hideRowDropdown();
        }
    });

    window.addEventListener('scroll', hideRowDropdown, true);
    window.addEventListener('resize', hideRowDropdown);

    itemPickerSearch.addEventListener('input', () => {
        const q = itemPickerSearch.value.toLowerCase().trim();
        document.querySelectorAll('.picker-row').forEach(row => {
            row.style.display = row.textContent.toLowerCase().includes(q) ? 'grid' : 'none';
        });
    });

    document.querySelectorAll('.picker-row').forEach(row => {
        row.addEventListener('click', () => {
            const selected = allItems.find(item => String(item.id) === String(row.dataset.itemId));
            if (selected) {
                addItemFromPicker(selected);
            }
            itemPickerPanel.classList.remove('show');
        });
    });

    adjustmentType.addEventListener('change', rebuildTable);

    document.getElementById('clearBtn').addEventListener('click', () => {
        adjustmentRows = Array.from({ length: 18 }, () => createBlankRow());
        document.getElementById('memo').value = '';
        resetAdjustmentAccountDropdown();
        infoQty.textContent = '';
        infoAvg.textContent = '';
        infoValue.textContent = '';
        hideRowDropdown();
        rebuildTable();
    });

    function showSwal(icon, title, text) {
        if (typeof Swal === 'undefined') {
            alert(text || title);
            return Promise.resolve();
        }

        return Swal.fire({
            icon: icon,
            title: title,
            text: text,
            confirmButtonColor: '#44D34E'
        });
    }

    function buildRowsPayload() {
        return filledRows().map(row => ({
            itemId: row.itemId || '',
            name: row.name || '',
            description: row.description || '',
            stock: row.stock || '',
            uom: row.uom || '',
            total: row.total || '',
            newQty: row.newQty || '',
            qtyDiff: row.qtyDiff || '',
            newValue: row.newValue || ''
        }));
    }

    function handleSave(clearAfter) {
        const rowsPayload = buildRowsPayload();

        if (!rowsPayload.length) {
            showSwal('warning', 'No Items Found', 'Please select or type at least one item.');
            return;
        }

        const accountId = document.getElementById('adjustmentAccount').value;
        if (!accountId) {
            showSwal('warning', 'Missing Account', 'Please select an Adjustment Account.');
            return;
        }

        const hasUnselectedItem = rowsPayload.some(row => !row.itemId);
        if (hasUnselectedItem) {
            showSwal('warning', 'Invalid Item', 'Please select the item from the dropdown before saving.');
            return;
        }

        const formData = new FormData();
        formData.append('action', 'save_item_adjustment');
        formData.append('adjustment_type', adjustmentType.value);
        formData.append('adjustment_date', document.getElementById('adjustmentDate').value);
        formData.append('adjustment_account_id', accountId);
        formData.append('customer_id', document.getElementById('customerJob').value || '');
        formData.append('memo', document.getElementById('memo').value || '');
        formData.append('rows', JSON.stringify(rowsPayload));

        if (typeof Swal !== 'undefined') {
            Swal.fire({
                title: 'Saving Item Adjustment...',
                text: 'Please wait...',
                allowOutsideClick: false,
                allowEscapeKey: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });
        }

        fetch(window.location.href, {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (!data.success) {
                throw new Error(data.message || 'Unable to save item adjustment.');
            }

            showSwal('success', 'Saved Successfully', data.message || 'Item Adjustment has been saved successfully.').then(() => {
                if (clearAfter) {
                    document.getElementById('clearBtn').click();
                    document.getElementById('referenceNo').value = data.reference_no ? (Number(data.reference_no) + 1 || '') : '';
                } else {
                    window.location.href = 'journal_entries.php';
                }
            });
        })
        .catch(error => {
            showSwal('error', 'Save Failed', error.message || 'Unable to save item adjustment.');
        });
    }

    document.getElementById('saveCloseBtn').addEventListener('click', () => handleSave(false));
    document.getElementById('saveNewBtn').addEventListener('click', () => handleSave(true));

    initializeAdjustmentAccountDropdown();

    rebuildTable();

    document.addEventListener('DOMContentLoaded', function () {
    const sidebar = document.querySelector('.sidebar-content');
    const activeLink = document.querySelector('.sidebar .nav-link.active');

    if (!sidebar || !activeLink) return;

    // Open parent dropdown if collapsed
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

    // Smooth scroll to active menu
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

document.addEventListener('click', function (e) {
    if (!e.target.closest('.mobile-nav .dropdown-more')) closeAllMobileDropdowns();
});

document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeAllMobileDropdowns();
});
</script>

</body>
</html>