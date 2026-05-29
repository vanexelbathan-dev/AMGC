<?php
require_once '../config/database.php';
require_once '../config/session_handler.php';

// Get current user info and branch context
$user_id = $_SESSION['user_id'] ?? 0;
$user_name = isset($_SESSION['first_name']) ? $_SESSION['first_name'] . ' ' . $_SESSION['last_name'] : 'Branch Admin';
$user_role = isset($_SESSION['role']) ? $_SESSION['role'] : 'branch_admin';
$branch_id = $_SESSION['branch_id'] ?? 0;
$view_all_branches = $_SESSION['view_all_branches'] ?? false;

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

// ========== CHECK AND ADD MISSING COLUMNS ==========
$supplier_id_column_exists = false;
$check_supplier_id = $conn->query("SHOW COLUMNS FROM purchase_orders LIKE 'supplier_id'");
if ($check_supplier_id && $check_supplier_id->num_rows > 0) {
    $supplier_id_column_exists = true;
}

$po_branch_column_exists = false;
$check_po_column = $conn->query("SHOW COLUMNS FROM purchase_orders LIKE 'branch_id'");
if ($check_po_column && $check_po_column->num_rows > 0) {
    $po_branch_column_exists = true;
}

$items_branch_column_exists = false;
$check_items_column = $conn->query("SHOW COLUMNS FROM items LIKE 'branch_id'");
if ($check_items_column && $check_items_column->num_rows > 0) {
    $items_branch_column_exists = true;
}

// Check for discount columns in purchase_order_items
$discount_type_column_exists = false;
$check_discount_type = $conn->query("SHOW COLUMNS FROM purchase_order_items LIKE 'discount_type'");
if ($check_discount_type && $check_discount_type->num_rows > 0) {
    $discount_type_column_exists = true;
}

$discount_value_column_exists = false;
$check_discount_value = $conn->query("SHOW COLUMNS FROM purchase_order_items LIKE 'discount_value'");
if ($check_discount_value && $check_discount_value->num_rows > 0) {
    $discount_value_column_exists = true;
}

// Check and add receive memo column for Receive Inventory notes.
$receive_memo_column_exists = false;
$check_receive_memo = $conn->query("SHOW COLUMNS FROM inventory_transactions LIKE 'receive_memo'");
if ($check_receive_memo && $check_receive_memo->num_rows > 0) {
    $receive_memo_column_exists = true;
} else {
    @$conn->query("ALTER TABLE inventory_transactions ADD COLUMN receive_memo TEXT NULL AFTER reference_id");
    $check_receive_memo_again = $conn->query("SHOW COLUMNS FROM inventory_transactions LIKE 'receive_memo'");
    if ($check_receive_memo_again && $check_receive_memo_again->num_rows > 0) {
        $receive_memo_column_exists = true;
    }
}

// Ensure inventory transaction costing columns exist so transaction details can show the exact unit cost entered during receiving.
$check_transaction_unit_cost = $conn->query("SHOW COLUMNS FROM inventory_transactions LIKE 'unit_cost'");
if (!$check_transaction_unit_cost || $check_transaction_unit_cost->num_rows == 0) {
    @$conn->query("ALTER TABLE inventory_transactions ADD COLUMN unit_cost decimal(12,2) NOT NULL DEFAULT 0.00 AFTER quantity_changed");
}
$check_transaction_total_cost = $conn->query("SHOW COLUMNS FROM inventory_transactions LIKE 'total_cost'");
if (!$check_transaction_total_cost || $check_transaction_total_cost->num_rows == 0) {
    @$conn->query("ALTER TABLE inventory_transactions ADD COLUMN total_cost decimal(14,2) NOT NULL DEFAULT 0.00 AFTER unit_cost");
}

// Use existing receive date and date encoded fields from the database.
$receive_date_column_exists = false;
$check_transaction_receive_date = $conn->query("SHOW COLUMNS FROM inventory_transactions LIKE 'receive_date'");
if ($check_transaction_receive_date && $check_transaction_receive_date->num_rows > 0) {
    $receive_date_column_exists = true;
}

$encoded_date_column_exists = false;
$check_transaction_encoded_date = $conn->query("SHOW COLUMNS FROM inventory_transactions LIKE 'encoded_date'");
if ($check_transaction_encoded_date && $check_transaction_encoded_date->num_rows > 0) {
    $encoded_date_column_exists = true;
}

$receive_inventory_dates_table_exists = false;
$check_receive_inventory_dates_table = $conn->query("SHOW TABLES LIKE 'receive_inventory_dates'");
if ($check_receive_inventory_dates_table && $check_receive_inventory_dates_table->num_rows > 0) {
    $receive_inventory_dates_table_exists = true;
}

// Ensure item inventory total_cost exists so stock quantity and inventory value are stored separately.
$check_item_inventory_total_cost = $conn->query("SHOW COLUMNS FROM item_unit_inventory LIKE 'total_cost'");
if (!$check_item_inventory_total_cost || $check_item_inventory_total_cost->num_rows == 0) {
    @$conn->query("ALTER TABLE item_unit_inventory ADD COLUMN total_cost decimal(14,2) NOT NULL DEFAULT 0.00 AFTER unit_cost");
    @$conn->query("UPDATE item_unit_inventory SET total_cost = COALESCE(current_inventory, 0) * COALESCE(unit_cost, 0) WHERE total_cost IS NULL OR total_cost = 0");
}

// Determine branch filter condition
$po_branch_condition = "";
if ($po_branch_column_exists && !$view_all_branches) {
    $po_branch_condition = "AND po.branch_id = $branch_id";
}

$items_branch_condition = "";
if ($items_branch_column_exists && !$view_all_branches) {
    $items_branch_condition = "AND branch_id = $branch_id";
}

// ========== FETCH SUPPLIERS ==========
$suppliers_query = "SELECT supplier_id, supplier_name, supplier_code, contact_person, email, phone_number 
                   FROM suppliers 
                   WHERE status = 'active'";
if (!$view_all_branches && $branch_id > 0) {
    $check_supplier_branch = $conn->query("SHOW COLUMNS FROM suppliers LIKE 'branch_id'");
    if ($check_supplier_branch && $check_supplier_branch->num_rows > 0) {
        $suppliers_query .= " AND branch_id = $branch_id";
    }
}
$suppliers_query .= " ORDER BY supplier_name ASC";
$suppliers_result = $conn->query($suppliers_query);
$suppliers_list = $suppliers_result ? $suppliers_result->fetch_all(MYSQLI_ASSOC) : [];

// ========== FETCH ITEMS ==========
$item_unit_pricing_exists = false;
$check_item_unit_pricing = $conn->query("SHOW TABLES LIKE 'item_unit_pricing'");
if ($check_item_unit_pricing && $check_item_unit_pricing->num_rows > 0) {
    $item_unit_pricing_exists = true;
}

$item_unit_types_exists = false;
$check_item_unit_types = $conn->query("SHOW TABLES LIKE 'item_unit_types'");
if ($check_item_unit_types && $check_item_unit_types->num_rows > 0) {
    $item_unit_types_exists = true;
}

// Receive Inventory category support.
$item_category_column = null;
foreach (['category', 'item_category', 'category_name'] as $category_candidate) {
    $check_category_column = $conn->query("SHOW COLUMNS FROM items LIKE '" . $conn->real_escape_string($category_candidate) . "'");
    if ($check_category_column && $check_category_column->num_rows > 0) {
        $item_category_column = $category_candidate;
        break;
    }
}
$item_category_select = $item_category_column ? ", `{$item_category_column}` AS category" : ", '' AS category";

$items_query = "SELECT 
    item_id, 
    item_code, 
    item_name, 
    description
    $item_category_select,
    unit_type,
    unit_price as price_piece,
    price_case,
    price_inner_pack,
    price_box,
    price_carton,
    stock,
    stock_in_default_uom,
    branch_id,
    status,
    base_unit_type
FROM items 
WHERE status = 'active' 
$items_branch_condition 
ORDER BY item_name";

$items_result = $conn->query($items_query);
$items_list = $items_result ? $items_result->fetch_all(MYSQLI_ASSOC) : [];

if (!empty($items_list)) {
    foreach ($items_list as &$item) {
        $item['available_uoms'] = [];
        $item['uom_prices'] = [];

        if ($item_unit_pricing_exists) {
            $uom_stmt = $conn->prepare("SELECT ut.unit_type_name, iup.unit_price
                                        FROM item_unit_pricing iup
                                        INNER JOIN unit_types ut ON ut.unit_type_id = iup.unit_type_id
                                        WHERE iup.item_id = ?
                                        ORDER BY iup.pricing_id ASC");
            if ($uom_stmt) {
                $uom_stmt->bind_param("i", $item['item_id']);
                $uom_stmt->execute();
                $uom_result = $uom_stmt->get_result();
                while ($uom_row = $uom_result->fetch_assoc()) {
                    $uom_name = trim((string)($uom_row['unit_type_name'] ?? ''));
                    if ($uom_name === '') continue;
                    $exists = false;
                    foreach ($item['available_uoms'] as $existing_uom) {
                        if (strcasecmp($existing_uom, $uom_name) === 0) {
                            $exists = true;
                            break;
                        }
                    }
                    if (!$exists) {
                        $item['available_uoms'][] = $uom_name;
                    }
                    $item['uom_prices'][strtolower($uom_name)] = (float)$uom_row['unit_price'];
                }
                $uom_stmt->close();
            }
        }

        if ($item_unit_types_exists) {
            $item_types_stmt = $conn->prepare("SELECT unit_type_name
                                               FROM item_unit_types
                                               WHERE item_id = ? AND status = 'active'
                                               ORDER BY is_default_uom DESC, unit_type_name ASC");
            if ($item_types_stmt) {
                $item_types_stmt->bind_param("i", $item['item_id']);
                $item_types_stmt->execute();
                $item_types_result = $item_types_stmt->get_result();
                while ($item_type_row = $item_types_result->fetch_assoc()) {
                    $uom_name = trim((string)($item_type_row['unit_type_name'] ?? ''));
                    if ($uom_name === '') continue;
                    $exists = false;
                    foreach ($item['available_uoms'] as $existing_uom) {
                        if (strcasecmp($existing_uom, $uom_name) === 0) {
                            $exists = true;
                            break;
                        }
                    }
                    if (!$exists) {
                        $item['available_uoms'][] = $uom_name;
                    }
                }
                $item_types_stmt->close();
            }
        }

        foreach ([$item['unit_type'] ?? '', $item['base_unit_type'] ?? ''] as $fallback_uom) {
            $fallback_uom = trim((string)$fallback_uom);
            if ($fallback_uom === '') continue;
            $exists = false;
            foreach ($item['available_uoms'] as $existing_uom) {
                if (strcasecmp($existing_uom, $fallback_uom) === 0) {
                    $exists = true;
                    break;
                }
            }
            if (!$exists) {
                $item['available_uoms'][] = $fallback_uom;
            }
        }

        if (empty($item['uom_prices']) && !empty($item['unit_type'])) {
            $item['uom_prices'][strtolower($item['unit_type'])] = (float)($item['price_piece'] ?? 0);
        }
    }
    unset($item);
}

if (empty($items_list)) {
    error_log("WARNING: No items found for branch ID: " . $branch_id);
} else {
    error_log("SUCCESS: " . count($items_list) . " items loaded for branch ID: " . $branch_id);
}


// ========== RECEIVE ATTACHMENT HELPERS ==========
function receiveAttachmentBaseDir(): string {
    return __DIR__ . '/../uploads/receive_attachments';
}

function receiveAttachmentBaseUrl(): string {
    return '../uploads/receive_attachments';
}

function ensureReceiveAttachmentDirectories(): string {
    $baseDir = receiveAttachmentBaseDir();
    if (!is_dir($baseDir)) {
        @mkdir($baseDir, 0777, true);
    }
    if (!is_dir($baseDir . '/manifests')) {
        @mkdir($baseDir . '/manifests', 0777, true);
    }
    if (!is_dir($baseDir . '/files')) {
        @mkdir($baseDir . '/files', 0777, true);
    }
    return $baseDir;
}

function sanitizeReceiveAttachmentName(string $name): string {
    $name = preg_replace('/[^A-Za-z0-9._-]/', '_', $name);
    return trim($name, '_') ?: 'file';
}

function loadReceiveManifestByTransactionId(int $transactionId): ?array {
    if ($transactionId <= 0) return null;
    $manifestDir = receiveAttachmentBaseDir() . '/manifests';
    if (!is_dir($manifestDir)) return null;
    foreach (glob($manifestDir . '/*.json') ?: [] as $file) {
        $json = @file_get_contents($file);
        if ($json === false) continue;
        $data = json_decode($json, true);
        if (!is_array($data)) continue;
        $txIds = $data['transaction_ids'] ?? [];
        if (is_array($txIds) && in_array($transactionId, array_map('intval', $txIds), true)) {
            $data['_manifest_file'] = $file;
            return $data;
        }
    }
    return null;
}

function loadReceiveManifestByContext(array $context): ?array {
    $manifestDir = receiveAttachmentBaseDir() . '/manifests';
    if (!is_dir($manifestDir)) return null;

    $targetType = trim((string)($context['reference_type'] ?? ''));
    $targetId = (int)($context['reference_id'] ?? 0);
    $targetItemId = (int)($context['item_id'] ?? 0);
    $targetCreatedAt = trim((string)($context['created_at'] ?? ''));

    $bestMatch = null;
    $bestScore = -1;

    foreach (glob($manifestDir . '/*.json') ?: [] as $file) {
        $json = @file_get_contents($file);
        if ($json === false) continue;
        $data = json_decode($json, true);
        if (!is_array($data)) continue;

        $score = 0;

        $manifestType = trim((string)($data['reference_type'] ?? ''));
        $manifestId = (int)($data['reference_id'] ?? 0);
        $manifestCreatedAt = trim((string)($data['created_at'] ?? ''));

        if ($targetType !== '') {
            if ($manifestType !== $targetType) {
                continue;
            }
            $score += 5;
        }

        if ($targetId > 0) {
            if ($manifestId !== $targetId) {
                continue;
            }
            $score += 5;
        }

        if ($targetItemId > 0) {
            $foundItem = false;
            foreach (($data['items'] ?? []) as $mItem) {
                if ((int)($mItem['item_id'] ?? 0) === $targetItemId) {
                    $foundItem = true;
                    $score += 5;
                    break;
                }
            }
            if (!$foundItem) {
                continue;
            }
        }

        if ($targetCreatedAt !== '' && $manifestCreatedAt !== '') {
            $targetTs = strtotime($targetCreatedAt);
            $manifestTs = strtotime($manifestCreatedAt);
            if ($targetTs && $manifestTs) {
                $diff = abs($manifestTs - $targetTs);
                if ($diff <= 5) {
                    $score += 5;
                } elseif ($diff <= 120) {
                    $score += 3;
                } elseif ($diff <= 600) {
                    $score += 1;
                }
            }
        }

        if ($score > $bestScore) {
            $data['_manifest_file'] = $file;
            $bestMatch = $data;
            $bestScore = $score;
        }
    }

    return $bestMatch;
}


function deleteReceiveAttachmentFiles(?array $manifest): void {
    if (!is_array($manifest)) return;
    foreach (($manifest['attachments'] ?? []) as $file) {
        $relative = (string)($file['relative_path'] ?? '');
        if ($relative !== '') {
            $path = receiveAttachmentBaseDir() . '/' . ltrim($relative, '/');
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }
    $manifestFile = (string)($manifest['_manifest_file'] ?? '');
    if ($manifestFile !== '' && is_file($manifestFile)) {
        @unlink($manifestFile);
    }
}

function findReceiveManifestForDelete(int $transactionId, array $context = []): ?array {
    $manifest = loadReceiveManifestByTransactionId($transactionId);
    if ($manifest) return $manifest;
    return loadReceiveManifestByContext($context);
}


// ========== RETURNED MERCHANDISE (RMR) HELPERS ==========
function dbTableExists(mysqli $conn, string $tableName): bool {
    $safe = $conn->real_escape_string($tableName);
    $result = $conn->query("SHOW TABLES LIKE '{$safe}'");
    return $result && $result->num_rows > 0;
}

function dbTableColumns(mysqli $conn, string $tableName): array {
    $columns = [];
    $safe = preg_replace('/[^A-Za-z0-9_]/', '', $tableName);
    if ($safe === '') return $columns;
    $result = $conn->query("SHOW COLUMNS FROM `{$safe}`");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            if (!empty($row['Field'])) $columns[] = $row['Field'];
        }
    }
    return $columns;
}

function firstExistingColumn(array $columns, array $candidates): ?string {
    foreach ($candidates as $candidate) {
        foreach ($columns as $column) {
            if (strcasecmp($column, $candidate) === 0) return $column;
        }
    }
    return null;
}

function sqlIdentifier(string $name): string {
    return '`' . str_replace('`', '``', $name) . '`';
}

function sqlExpr(string $tableAlias, ?string $column, string $fallback = "''"): string {
    return $column ? $tableAlias . '.' . sqlIdentifier($column) : $fallback;
}

function getConfirmedProcessedRmrRequests(mysqli $conn, int $branchId, bool $viewAllBranches): array {
    $candidateTables = ['rmr_requests', 'rmr_request', 'return_merchandise_requests', 'returned_merchandise_requests', 'rmr'];
    $rmrTable = null;
    foreach ($candidateTables as $candidateTable) {
        if (dbTableExists($conn, $candidateTable)) { $rmrTable = $candidateTable; break; }
    }
    if (!$rmrTable) return [];

    $columns = dbTableColumns($conn, $rmrTable);
    if (empty($columns)) return [];

    $idCol = firstExistingColumn($columns, ['rmr_id', 'request_id', 'return_id', 'id']);
    $numberCol = firstExistingColumn($columns, ['rmr_number', 'rmr_no', 'request_number', 'reference_number', 'reference_no', 'rma_number']);
    $statusCol = firstExistingColumn($columns, ['status', 'rmr_status', 'request_status', 'process_status']);
    $branchCol = firstExistingColumn($columns, ['branch_id']);
    $itemCol = firstExistingColumn($columns, ['item_id']);
    $itemNameCol = firstExistingColumn($columns, ['item_name', 'product_name', 'description', 'item_description']);
    $itemCodeCol = firstExistingColumn($columns, ['item_code', 'product_code', 'code']);
    $qtyCol = firstExistingColumn($columns, ['return_quantity', 'quantity', 'qty', 'return_qty', 'returned_qty', 'approved_qty', 'processed_qty']);
    $uomCol = firstExistingColumn($columns, ['unit_type', 'uom', 'unit', 'unit_of_measure']);
    $customerCol = firstExistingColumn($columns, ['customer_name', 'store_name', 'client_name']);
    $customerIdCol = firstExistingColumn($columns, ['customer_id', 'client_id']);
    $deliveryIdCol = firstExistingColumn($columns, ['delivery_id']);
    $reasonCol = firstExistingColumn($columns, ['reason', 'remarks', 'notes', 'return_reason']);
    $processedAtCol = firstExistingColumn($columns, ['processed_at', 'confirmed_at', 'updated_at', 'created_at']);

    if (!$idCol || !$statusCol || !$itemCol || !$qtyCol) return [];

    $tableSql = sqlIdentifier($rmrTable);
    $idExpr = sqlExpr('r', $idCol, '0');
    $numberExpr = $numberCol ? sqlExpr('r', $numberCol) : "CONCAT('RMR-', {$idExpr})";
    $itemNameExpr = $itemNameCol ? sqlExpr('r', $itemNameCol) : 'i.item_name';
    $itemCodeExpr = $itemCodeCol ? sqlExpr('r', $itemCodeCol) : 'i.item_code';
    $uomExpr = $uomCol ? sqlExpr('r', $uomCol) : 'i.unit_type';
    $reasonExpr = sqlExpr('r', $reasonCol, "''");
    $processedAtExpr = sqlExpr('r', $processedAtCol, "''");

    // Build customer fallback safely. Priority: rmr_requests customer_name -> customers via customer_id -> deliveries customer -> blank.
    $joins = "";
    $customerParts = [];
    if ($customerCol) {
        $customerParts[] = "NULLIF(TRIM(" . sqlExpr('r', $customerCol) . "), '')";
    }
    if ($customerIdCol && dbTableExists($conn, 'customers')) {
        $joins .= " LEFT JOIN customers c ON c.customer_id = r." . sqlIdentifier($customerIdCol);
        $customerParts[] = "NULLIF(TRIM(c.customer_name), '')";
    }
    if ($deliveryIdCol && dbTableExists($conn, 'deliveries') && dbTableExists($conn, 'customers')) {
        $joins .= " LEFT JOIN deliveries d ON d.delivery_id = r." . sqlIdentifier($deliveryIdCol);
        $joins .= " LEFT JOIN customers dc ON dc.customer_id = d.customer_id";
        $customerParts[] = "NULLIF(TRIM(dc.customer_name), '')";
    }
    $customerExpr = !empty($customerParts) ? "COALESCE(" . implode(', ', $customerParts) . ", '')" : "''";

    $query = "
        SELECT
            {$idExpr} AS rmr_id,
            {$numberExpr} AS rmr_number,
            r." . sqlIdentifier($statusCol) . " AS status,
            r." . sqlIdentifier($itemCol) . " AS item_id,
            COALESCE({$itemNameExpr}, i.item_name, '') AS item_name,
            COALESCE({$itemCodeExpr}, i.item_code, '') AS item_code,
            COALESCE(i.description, {$itemNameExpr}, '') AS item_description,
            r." . sqlIdentifier($qtyCol) . " AS quantity,
            COALESCE({$uomExpr}, i.unit_type, '') AS unit_type,
            COALESCE(i.unit_price, 0) AS unit_price,
            {$customerExpr} AS customer_name,
            COALESCE({$reasonExpr}, '') AS reason,
            COALESCE({$processedAtExpr}, '') AS processed_at
        FROM {$tableSql} r
        LEFT JOIN items i ON i.item_id = r." . sqlIdentifier($itemCol) . "
        {$joins}
        WHERE LOWER(TRIM(r." . sqlIdentifier($statusCol) . ")) IN ('confirmed', 'processed', 'approved')
          AND r." . sqlIdentifier($itemCol) . " IS NOT NULL
          AND r." . sqlIdentifier($qtyCol) . " > 0
          AND NOT EXISTS (
              SELECT 1 FROM inventory_transactions it
              WHERE it.reference_type = 'rmr'
                AND it.reference_id = {$idExpr}
                AND it.transaction_type = 'in'
          )
    ";
    if (!$viewAllBranches && $branchId > 0 && $branchCol) {
        $query .= " AND r." . sqlIdentifier($branchCol) . " = " . (int)$branchId;
    }
    $query .= " ORDER BY {$idExpr} DESC";
    $result = $conn->query($query);
    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

// Fix older invalid confirmed records caused by ENUM columns that do not allow confirmed.
// We store confirmed RMR as rmr_status = approved so it stays compatible with existing Bad Orders database.
if (dbTableExists($conn, 'rmr_requests')) {
    $rmrFixCols = dbTableColumns($conn, 'rmr_requests');
    if (in_array('rmr_status', $rmrFixCols, true) && in_array('disposition_type', $rmrFixCols, true)) {
        @$conn->query("UPDATE rmr_requests SET rmr_status = 'approved', updated_at = NOW() WHERE (rmr_status IS NULL OR rmr_status = '') AND disposition_type IS NOT NULL");
    }
}

$rmr_requests_list = getConfirmedProcessedRmrRequests($conn, (int)$branch_id, (bool)$view_all_branches);

// ========== HANDLE AJAX REQUESTS ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    try {
        $conn->begin_transaction();
        
        // CREATE PURCHASE ORDER
        if ($_POST['action'] === 'create_po') {
            $po_number = 'PO-' . date('Ymd') . '-' . rand(1000, 9999);
            $supplier_name = $_POST['supplier_name'];
            $supplier_id = isset($_POST['supplier_id']) && !empty($_POST['supplier_id']) ? (int)$_POST['supplier_id'] : null;
            $order_date = $_POST['order_date'];
            $expected_delivery = !empty($_POST['expected_delivery']) ? $_POST['expected_delivery'] : null;
            $total_amount = (float)$_POST['total_amount'];
            $po_status = $_POST['po_status'] ?? 'draft';
            $created_by = $user_id;

            // Build insert data dynamically
            $fields = [
                'po_number' => $po_number,
                'supplier_name' => $supplier_name,
                'order_date' => $order_date,
                'expected_delivery' => $expected_delivery,
                'total_amount' => $total_amount,
                'po_status' => $po_status,
                'created_by' => $created_by,
                'created_at' => 'NOW()',
                'updated_at' => 'NOW()'
            ];
            if ($supplier_id_column_exists && $supplier_id !== null) {
                $fields['supplier_id'] = $supplier_id;
            }
            if ($po_branch_column_exists) {
                $fields['branch_id'] = $branch_id;
            }

            // Build query
            $column_names = array_keys($fields);
            $placeholders = [];
            $types = '';
            $values = [];
            foreach ($fields as $col => $val) {
                if ($val === 'NOW()') {
                    $placeholders[] = 'NOW()';
                } else {
                    $placeholders[] = '?';
                    if (is_int($val)) $types .= 'i';
                    elseif (is_float($val)) $types .= 'd';
                    else $types .= 's';
                    $values[] = $val;
                }
            }

            $insert_query = "INSERT INTO purchase_orders (" . implode(', ', $column_names) . ") 
                           VALUES (" . implode(', ', $placeholders) . ")";
            $insert_stmt = $conn->prepare($insert_query);
            if (!$insert_stmt) throw new Exception('Prepare failed: ' . $conn->error);
            if (!empty($values)) $insert_stmt->bind_param($types, ...$values);
            if (!$insert_stmt->execute()) throw new Exception('Failed to create purchase order: ' . $insert_stmt->error);
            
            $po_id = $conn->insert_id;
            
            // Add items if provided
            if (isset($_POST['items']) && !empty($_POST['items'])) {
                $items = json_decode($_POST['items'], true);
                if (is_array($items) && count($items) > 0) {
                    foreach ($items as $item) {
                        $item_id = (int)$item['item_id'];
                        $quantity = (int)$item['quantity'];
                        $unit_price = (float)$item['unit_price'];
                        $discount_type = isset($item['discount_type']) ? $item['discount_type'] : null;
                        $discount_value = isset($item['discount_value']) ? (float)$item['discount_value'] : 0;
                        
                        // Build item insert
                        $item_fields = ['po_id', 'item_id', 'quantity_ordered', 'unit_price'];
                        $item_placeholders = ['?', '?', '?', '?'];
                        $item_types = 'iiid';
                        $item_values = [$po_id, $item_id, $quantity, $unit_price];
                        
                        if ($discount_type_column_exists && $discount_type) {
                            $item_fields[] = 'discount_type';
                            $item_placeholders[] = '?';
                            $item_types .= 's';
                            $item_values[] = $discount_type;
                        }
                        if ($discount_value_column_exists && $discount_value > 0) {
                            $item_fields[] = 'discount_value';
                            $item_placeholders[] = '?';
                            $item_types .= 'd';
                            $item_values[] = $discount_value;
                        }
                        
                        $item_query = "INSERT INTO purchase_order_items (" . implode(', ', $item_fields) . ") 
                                      VALUES (" . implode(', ', $item_placeholders) . ")";
                        $item_stmt = $conn->prepare($item_query);
                        $item_stmt->bind_param($item_types, ...$item_values);
                        if (!$item_stmt->execute()) {
                            throw new Exception('Failed to add item: ' . $item_stmt->error);
                        }
                    }
                }
            }
            
            $conn->commit();
            echo json_encode([
                'success' => true,
                'message' => 'Purchase order created successfully',
                'po_id' => $po_id,
                'po_number' => $po_number
            ]);
            exit;
        }
        

        // RECEIVE INVENTORY
        elseif ($_POST['action'] === 'receive_inventory') {
            $source = $_POST['source'] ?? 'supplier';
            $supplier_id = isset($_POST['supplier_id']) && $_POST['supplier_id'] !== '' ? (int)$_POST['supplier_id'] : null;
            $supplier_name = trim($_POST['supplier'] ?? '');
            $po_number = trim($_POST['poNumber'] ?? '');
            $with_po = isset($_POST['withPO']) && (string)$_POST['withPO'] === '1';
            $receive_date = trim($_POST['receiveDate'] ?? '');
            $encoded_date = trim($_POST['encodedDate'] ?? '');
            $receive_memo = trim((string)($_POST['receive_memo'] ?? ''));
            $items = json_decode($_POST['items'] ?? '[]', true);
            $rmr_id = isset($_POST['rmr_id']) && $_POST['rmr_id'] !== '' ? (int)$_POST['rmr_id'] : 0;
            $upload_field = $source === 'production' ? 'productionAttachments' : 'supplierAttachments';
            if ($source === 'rmr') {
                $upload_field = '';
            }
            $saved_attachments = [];
            $manifest_item_rows = [];
            $saved_transaction_ids = [];
            $manifest_created_at = date('Y-m-d H:i:s');

            if (!$receive_date) {
                throw new Exception('Receive date is required.');
            }
            if (!$encoded_date) {
                throw new Exception('Date encoded is required.');
            }
            if (!is_array($items) || count($items) === 0) {
                throw new Exception('Please add at least one item to receive.');
            }
            if ($source === 'supplier' && $supplier_name === '') {
                throw new Exception('Please select a supplier.');
            }
            if ($source === 'rmr' && $rmr_id <= 0) {
                throw new Exception('Please select a confirmed or processed RMR request.');
            }

            $quantity_received_column_exists = false;
            $check_qty_received_col = $conn->query("SHOW COLUMNS FROM purchase_order_items LIKE 'quantity_received'");
            if ($check_qty_received_col && $check_qty_received_col->num_rows > 0) {
                $quantity_received_column_exists = true;
            }

            $reference_po_id = null;
            $received_total_amount = 0.00;
            if ($upload_field !== '' && isset($_FILES[$upload_field]) && is_array($_FILES[$upload_field]['name'] ?? null)) {
                ensureReceiveAttachmentDirectories();
                $allowed_extensions = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx'];
                $upload_count = count($_FILES[$upload_field]['name']);
                for ($i = 0; $i < $upload_count; $i++) {
                    if (($_FILES[$upload_field]['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                        continue;
                    }
                    if (($_FILES[$upload_field]['error'][$i] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
                        throw new Exception('One of the attachments failed to upload.');
                    }
                    $original_name = (string)($_FILES[$upload_field]['name'][$i] ?? 'attachment');
                    $tmp_name = (string)($_FILES[$upload_field]['tmp_name'][$i] ?? '');
                    $size = (int)($_FILES[$upload_field]['size'][$i] ?? 0);
                    $ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
                    if (!in_array($ext, $allowed_extensions, true)) {
                        throw new Exception('Invalid attachment type. Allowed files: PDF, JPG, JPEG, PNG, DOC, DOCX.');
                    }
                    if ($size > 10 * 1024 * 1024) {
                        throw new Exception('Attachment file size must be 10MB or below.');
                    }
                    if ($tmp_name === '' || !is_uploaded_file($tmp_name)) {
                        throw new Exception('Invalid uploaded attachment.');
                    }
                    $safe_original = sanitizeReceiveAttachmentName(pathinfo($original_name, PATHINFO_FILENAME));
                    $stored_name = date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '_' . $safe_original . ($ext !== '' ? '.' . $ext : '');
                    $target_dir = ensureReceiveAttachmentDirectories() . '/files';
                    $target_path = $target_dir . '/' . $stored_name;
                    $moved = move_uploaded_file($tmp_name, $target_path);
                    if (!$moved && is_file($tmp_name)) {
                        $moved = @rename($tmp_name, $target_path) || @copy($tmp_name, $target_path);
                    }
                    if (!$moved) {
                        throw new Exception('Failed to save attachment file.');
                    }
                    $saved_attachments[] = [
                        'original_name' => $original_name,
                        'stored_name' => $stored_name,
                        'relative_path' => 'files/' . $stored_name,
                        'url' => receiveAttachmentBaseUrl() . '/files/' . rawurlencode($stored_name),
                        'size' => $size
                    ];
                }
            }

            foreach ($items as $rowItem) {
                $received_total_amount += ((float)($rowItem['qty'] ?? 0)) * ((float)($rowItem['price'] ?? 0));
            }

            if ($source === 'supplier') {
                if ($with_po && $po_number !== '') {
                    $po_lookup_query = "SELECT po_id FROM purchase_orders WHERE po_number = ?";
                    if ($po_branch_column_exists && !$view_all_branches) {
                        $po_lookup_query .= " AND branch_id = ?";
                    }
                    $po_lookup_stmt = $conn->prepare($po_lookup_query);
                    if (!$po_lookup_stmt) {
                        throw new Exception('Unable to prepare PO lookup.');
                    }
                    if ($po_branch_column_exists && !$view_all_branches) {
                        $po_lookup_stmt->bind_param("si", $po_number, $branch_id);
                    } else {
                        $po_lookup_stmt->bind_param("s", $po_number);
                    }
                    $po_lookup_stmt->execute();
                    $po_lookup_result = $po_lookup_stmt->get_result();
                    if ($po_lookup_row = $po_lookup_result->fetch_assoc()) {
                        $reference_po_id = (int)$po_lookup_row['po_id'];
                    } else {
                        throw new Exception('Selected PO was not found.');
                    }
                    $po_lookup_stmt->close();
                } else {
                    $generated_po_number = 'PO-RCV-' . date('YmdHis');
                    $fields = [
                        'po_number' => $generated_po_number,
                        'supplier_name' => $supplier_name,
                        'order_date' => $receive_date,
                        'expected_delivery' => $receive_date,
                        'total_amount' => $received_total_amount,
                        'po_status' => 'received',
                        'created_by' => $user_id,
                        'created_at' => 'NOW()',
                        'updated_at' => 'NOW()'
                    ];
                    if ($po_branch_column_exists) {
                        $fields['branch_id'] = $branch_id;
                    }
                    if ($supplier_id_column_exists && $supplier_id) {
                        $fields['supplier_id'] = $supplier_id;
                    }

                    $column_names = array_keys($fields);
                    $placeholders = [];
                    $types = '';
                    $values = [];
                    foreach ($fields as $col => $val) {
                        if ($val === 'NOW()') {
                            $placeholders[] = 'NOW()';
                        } else {
                            $placeholders[] = '?';
                            if (is_int($val)) $types .= 'i';
                            elseif (is_float($val)) $types .= 'd';
                            else $types .= 's';
                            $values[] = $val;
                        }
                    }

                    $create_receive_po = $conn->prepare("INSERT INTO purchase_orders (" . implode(', ', $column_names) . ") VALUES (" . implode(', ', $placeholders) . ")");
                    if (!$create_receive_po) {
                        throw new Exception('Unable to prepare supplier receipt save.');
                    }
                    if (!empty($values)) {
                        $create_receive_po->bind_param($types, ...$values);
                    }
                    if (!$create_receive_po->execute()) {
                        throw new Exception('Unable to save supplier source: ' . $create_receive_po->error);
                    }
                    $reference_po_id = (int)$conn->insert_id;
                    $po_number = $generated_po_number;
                    $create_receive_po->close();
                }
            }

            $reference_rmr_id = 0;
            if ($source === 'rmr') {
                $rmrValid = false;
                foreach ($rmr_requests_list as $rmrRow) {
                    if ((int)($rmrRow['rmr_id'] ?? 0) === $rmr_id) {
                        $rmrValid = true;
                        break;
                    }
                }
                if (!$rmrValid) {
                    throw new Exception('Selected RMR request is not confirmed/processed/approved, already returned to inventory, or not found.');
                }
                $reference_rmr_id = $rmr_id;
                $po_number = 'RMR-' . $rmr_id;
            }

            foreach ($items as $rowItem) {
                $item_id = isset($rowItem['item_id']) && $rowItem['item_id'] !== null && $rowItem['item_id'] !== '' ? (int)$rowItem['item_id'] : 0;
                $item_name = trim($rowItem['name'] ?? '');
                $item_code = trim($rowItem['code'] ?? '');
                $item_description = trim($rowItem['description'] ?? '');
                $item_category = trim($rowItem['category'] ?? '');
                $item_uom = trim($rowItem['uom'] ?? '');
                $item_qty = (float)($rowItem['qty'] ?? 0);
                $item_price = (float)($rowItem['price'] ?? 0);
                $item_total_cost = $item_qty * $item_price;

                if ($item_name === '' || $item_code === '' || $item_description === '' || $item_category === '' || $item_uom === '' || $item_qty <= 0) {
                    throw new Exception('Please complete all received item details.');
                }

                if ($item_id <= 0) {
                    $existing_item_stmt = $conn->prepare("SELECT item_id FROM items WHERE item_code = ? LIMIT 1");
                    if ($existing_item_stmt) {
                        $existing_item_stmt->bind_param("s", $item_code);
                        $existing_item_stmt->execute();
                        $existing_item_result = $existing_item_stmt->get_result();
                        if ($existing_item_row = $existing_item_result->fetch_assoc()) {
                            $item_id = (int)$existing_item_row['item_id'];
                        }
                        $existing_item_stmt->close();
                    }
                }

                if ($item_id <= 0) {
                    $item_insert_fields = ['item_code', 'item_name', 'description'];
                    $item_insert_placeholders = ['?', '?', '?'];
                    $item_insert_types = 'sss';
                    $item_insert_values = [$item_code, $item_name, $item_description];

                    if ($item_category_column) {
                        $item_insert_fields[] = '`' . $item_category_column . '`';
                        $item_insert_placeholders[] = '?';
                        $item_insert_types .= 's';
                        $item_insert_values[] = $item_category;
                    }

                    $item_insert_fields = array_merge($item_insert_fields, ['unit_type', 'unit_price', 'stock', 'stock_in_default_uom', 'status', 'created_at', 'updated_at']);
                    $item_insert_placeholders = array_merge($item_insert_placeholders, ['?', '?', '?', '?', "'active'", 'NOW()', 'NOW()']);
                    $item_insert_types .= 'sddd';
                    array_push($item_insert_values, $item_uom, $item_price, $item_qty, $item_qty);

                    if ($items_branch_column_exists) {
                        $item_insert_fields[] = 'branch_id';
                        $item_insert_placeholders[] = '?';
                        $item_insert_types .= 'i';
                        $item_insert_values[] = $branch_id;
                    }

                    $insert_item_query = "INSERT INTO items (" . implode(', ', $item_insert_fields) . ") VALUES (" . implode(', ', $item_insert_placeholders) . ")";
                    $insert_item_stmt = $conn->prepare($insert_item_query);
                    if (!$insert_item_stmt) {
                        throw new Exception('Unable to prepare new item save.');
                    }
                    $insert_item_stmt->bind_param($item_insert_types, ...$item_insert_values);
                    if (!$insert_item_stmt->execute()) {
                        throw new Exception('Failed to create new item: ' . $insert_item_stmt->error);
                    }
                    $item_id = (int)$conn->insert_id;
                    $insert_item_stmt->close();
                } else {
                    if ($item_category_column) {
                        $update_item_stmt = $conn->prepare("UPDATE items SET item_name = ?, description = ?, `{$item_category_column}` = ?, unit_type = ?, unit_price = ?, updated_at = NOW() WHERE item_id = ?");
                    } else {
                        $update_item_stmt = $conn->prepare("UPDATE items SET item_name = ?, description = ?, unit_type = ?, unit_price = ?, updated_at = NOW() WHERE item_id = ?");
                    }
                    if ($update_item_stmt) {
                        if ($item_category_column) {
                            $update_item_stmt->bind_param("ssssdi", $item_name, $item_description, $item_category, $item_uom, $item_price, $item_id);
                        } else {
                            $update_item_stmt->bind_param("sssdi", $item_name, $item_description, $item_uom, $item_price, $item_id);
                        }
                        if (!$update_item_stmt->execute()) {
                            throw new Exception('Failed to update item details.');
                        }
                        $update_item_stmt->close();
                    }
                }

                if ($source === 'supplier' && $reference_po_id) {
                    $find_po_item = $conn->prepare("SELECT po_item_id FROM purchase_order_items WHERE po_id = ? AND item_id = ? LIMIT 1");
                    if ($find_po_item) {
                        $find_po_item->bind_param("ii", $reference_po_id, $item_id);
                        $find_po_item->execute();
                        $find_po_item_result = $find_po_item->get_result();
                        if ($find_po_item_row = $find_po_item_result->fetch_assoc()) {
                            $po_item_id = (int)$find_po_item_row['po_item_id'];
                            if ($quantity_received_column_exists) {
                                $update_po_item = $conn->prepare("UPDATE purchase_order_items SET quantity_ordered = ?, quantity_received = ?, unit_price = ? WHERE po_item_id = ?");
                                $update_po_item->bind_param("dddi", $item_qty, $item_qty, $item_price, $po_item_id);
                            } else {
                                $update_po_item = $conn->prepare("UPDATE purchase_order_items SET quantity_ordered = ?, unit_price = ? WHERE po_item_id = ?");
                                $update_po_item->bind_param("ddi", $item_qty, $item_price, $po_item_id);
                            }
                            if (!$update_po_item->execute()) {
                                throw new Exception('Failed to update purchase order item.');
                            }
                            $update_po_item->close();
                        } else {
                            if ($quantity_received_column_exists) {
                                $insert_po_item = $conn->prepare("INSERT INTO purchase_order_items (po_id, item_id, quantity_ordered, quantity_received, unit_price) VALUES (?, ?, ?, ?, ?)");
                                $insert_po_item->bind_param("iiddd", $reference_po_id, $item_id, $item_qty, $item_qty, $item_price);
                            } else {
                                $insert_po_item = $conn->prepare("INSERT INTO purchase_order_items (po_id, item_id, quantity_ordered, unit_price) VALUES (?, ?, ?, ?)");
                                $insert_po_item->bind_param("iidd", $reference_po_id, $item_id, $item_qty, $item_price);
                            }
                            if (!$insert_po_item->execute()) {
                                throw new Exception('Failed to insert purchase order item.');
                            }
                            $insert_po_item->close();
                        }
                        $find_po_item->close();
                    }
                }

                // NOTE: Don't update 'inventory' table here
                // The inventory_transactions record below will be synced to item_unit_inventory by current_inventory.php
                // This ensures a single source of truth for inventory data

                $reference_type = $source === 'supplier' ? 'purchase_order' : ($source === 'rmr' ? 'rmr' : 'production');
                $reference_id = $source === 'rmr' ? $reference_rmr_id : ($reference_po_id ?: 0);
                $transaction_fields = ['branch_id', 'item_id', 'transaction_type', 'quantity_changed', 'unit_cost', 'total_cost'];
                $transaction_placeholders = ['?', '?', "'in'", '?', '?', '?'];
                $transaction_types = 'iiddd';
                $transaction_values = [$branch_id, $item_id, $item_qty, $item_price, $item_total_cost];

                if ($receive_date_column_exists) {
                    $transaction_fields[] = 'receive_date';
                    $transaction_placeholders[] = '?';
                    $transaction_types .= 's';
                    $transaction_values[] = $receive_date;
                }
                if ($encoded_date_column_exists) {
                    $transaction_fields[] = 'encoded_date';
                    $transaction_placeholders[] = '?';
                    $transaction_types .= 's';
                    $transaction_values[] = $encoded_date;
                }

                $transaction_fields[] = 'reference_type';
                $transaction_placeholders[] = '?';
                $transaction_types .= 's';
                $transaction_values[] = $reference_type;

                $transaction_fields[] = 'reference_id';
                $transaction_placeholders[] = '?';
                $transaction_types .= 'i';
                $transaction_values[] = $reference_id;

                if ($receive_memo_column_exists) {
                    $transaction_fields[] = 'receive_memo';
                    $transaction_placeholders[] = '?';
                    $transaction_types .= 's';
                    $transaction_values[] = $receive_memo;
                }

                $transaction_fields[] = 'created_by';
                $transaction_placeholders[] = '?';
                $transaction_types .= 'i';
                $transaction_values[] = $user_id;

                $transaction_fields[] = 'created_at';
                $transaction_placeholders[] = 'NOW()';

                $inventory_transaction = $conn->prepare("INSERT INTO inventory_transactions (" . implode(', ', $transaction_fields) . ") VALUES (" . implode(', ', $transaction_placeholders) . ")");
                if (!$inventory_transaction) {
                    throw new Exception('Unable to prepare inventory transaction save.');
                }
                $inventory_transaction->bind_param($transaction_types, ...$transaction_values);
                if (!$inventory_transaction->execute()) {
                    throw new Exception('Failed to save inventory transaction.');
                }
                $saved_transaction_id = (int)$conn->insert_id;
                $saved_transaction_ids[] = $saved_transaction_id;

                if ($receive_inventory_dates_table_exists && $saved_transaction_id > 0) {
                    $save_receive_dates = $conn->prepare("INSERT INTO receive_inventory_dates (transaction_id, receive_date, encoded_date) VALUES (?, ?, ?)");
                    if ($save_receive_dates) {
                        $save_receive_dates->bind_param('iss', $saved_transaction_id, $receive_date, $encoded_date);
                        if (!$save_receive_dates->execute()) {
                            throw new Exception('Failed to save receive and date encoded.');
                        }
                        $save_receive_dates->close();
                    }
                }

                // Mark this receive transaction as already applied to item_unit_inventory.
                // current_inventory.php has an old safety sync from inventory_transactions; this log prevents double adding.
                @$conn->query("CREATE TABLE IF NOT EXISTS `item_unit_inventory_receive_sync_log` (
                    `sync_id` int(11) NOT NULL AUTO_INCREMENT,
                    `transaction_id` int(11) NOT NULL,
                    `item_id` int(11) NOT NULL,
                    `unit_type_id` int(11) NOT NULL DEFAULT 0,
                    `quantity_added` decimal(12,2) NOT NULL DEFAULT 0.00,
                    `reference_type` varchar(100) DEFAULT NULL,
                    `reference_id` int(11) DEFAULT NULL,
                    `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
                    PRIMARY KEY (`sync_id`),
                    UNIQUE KEY `unique_transaction_sync` (`transaction_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
                
                // DIRECTLY UPDATE EXISTING item_unit_inventory ROW ONLY
                // IMPORTANT: Do NOT insert a new unit type inventory row here.
                // The item already has its UoM rows from Current Inventory > Add/Edit Item.
                // We only add the received quantity to the matching existing UoM row.
                $matched_inventory_id = 0;
                $matched_unit_type_id = 0;
                $matched_current_inventory = 0.00;
                $matched_total_cost = 0.00;

                // 1) Match existing item_unit_inventory by item + UoM name.
                // This prevents creating duplicate UoM rows when unit_types has the same name in another branch/global scope.
                $find_existing_unit_inventory = $conn->prepare("\n                    SELECT \n                        iui.inventory_id,\n                        iui.unit_type_id,\n                        COALESCE(iui.current_inventory, 0) AS current_inventory,\n                        COALESCE(NULLIF(iui.total_cost, 0), COALESCE(iui.current_inventory, 0) * COALESCE(iui.unit_cost, 0)) AS total_cost\n                    FROM item_unit_inventory iui\n                    INNER JOIN unit_types ut ON ut.unit_type_id = iui.unit_type_id\n                    WHERE iui.item_id = ?\n                      AND LOWER(TRIM(ut.unit_type_name)) = LOWER(TRIM(?))\n                    ORDER BY \n                        CASE WHEN ut.branch_id = ? THEN 0 WHEN ut.branch_id IS NULL THEN 1 ELSE 2 END ASC,\n                        iui.inventory_id ASC\n                    LIMIT 1\n                ");
                if ($find_existing_unit_inventory) {
                    $find_existing_unit_inventory->bind_param("isi", $item_id, $item_uom, $branch_id);
                    $find_existing_unit_inventory->execute();
                    $existing_unit_inventory_result = $find_existing_unit_inventory->get_result();
                    if ($existing_unit_inventory_row = $existing_unit_inventory_result->fetch_assoc()) {
                        $matched_inventory_id = (int)$existing_unit_inventory_row['inventory_id'];
                        $matched_unit_type_id = (int)$existing_unit_inventory_row['unit_type_id'];
                        $matched_current_inventory = (float)$existing_unit_inventory_row['current_inventory'];
                        $matched_total_cost = (float)($existing_unit_inventory_row['total_cost'] ?? 0);
                    }
                    $find_existing_unit_inventory->close();
                }

                // 2) Fallback: if no UoM name match, use this item's default UoM inventory row only.
                // Still update only an existing row; never insert a duplicate UoM row from receiving.
                if ($matched_inventory_id <= 0) {
                    $find_default_unit_inventory = $conn->prepare("\n                        SELECT \n                            iui.inventory_id,\n                            iui.unit_type_id,\n                            COALESCE(iui.current_inventory, 0) AS current_inventory,\n                            COALESCE(NULLIF(iui.total_cost, 0), COALESCE(iui.current_inventory, 0) * COALESCE(iui.unit_cost, 0)) AS total_cost\n                        FROM items i\n                        INNER JOIN item_unit_inventory iui \n                            ON iui.item_id = i.item_id \n                           AND iui.unit_type_id = i.default_unit_type_id\n                        WHERE i.item_id = ?\n                        LIMIT 1\n                    ");
                    if ($find_default_unit_inventory) {
                        $find_default_unit_inventory->bind_param("i", $item_id);
                        $find_default_unit_inventory->execute();
                        $default_unit_inventory_result = $find_default_unit_inventory->get_result();
                        if ($default_unit_inventory_row = $default_unit_inventory_result->fetch_assoc()) {
                            $matched_inventory_id = (int)$default_unit_inventory_row['inventory_id'];
                            $matched_unit_type_id = (int)$default_unit_inventory_row['unit_type_id'];
                            $matched_current_inventory = (float)$default_unit_inventory_row['current_inventory'];
                            $matched_total_cost = (float)($default_unit_inventory_row['total_cost'] ?? 0);
                        }
                        $find_default_unit_inventory->close();
                    }
                }

                if ($matched_inventory_id > 0) {
                    // Item and UoM already exist - add only the received quantity to stock.
                    // Total cost is accumulated separately so different unit costs do not change the received quantity.
                    $new_current_inventory = $matched_current_inventory + (float)$item_qty;
                    $new_total_cost = $matched_total_cost + $item_total_cost;
                    $new_average_unit_cost = $new_current_inventory > 0 ? ($new_total_cost / $new_current_inventory) : $item_price;
                    $update_existing_unit_inventory = $conn->prepare("\n                        UPDATE item_unit_inventory\n                        SET current_inventory = ?,\n                            unit_cost = ?,\n                            total_cost = ?,\n                            as_of_date = ?,\n                            updated_at = NOW()\n                        WHERE inventory_id = ?\n                        LIMIT 1\n                    ");
                    if (!$update_existing_unit_inventory) {
                        throw new Exception('Unable to prepare unit inventory update.');
                    }
                    $as_of_date_for_receive = $receive_date ?: date('Y-m-d');
                    $update_existing_unit_inventory->bind_param("dddsi", $new_current_inventory, $new_average_unit_cost, $new_total_cost, $as_of_date_for_receive, $matched_inventory_id);
                    if (!$update_existing_unit_inventory->execute()) {
                        throw new Exception('Failed to update existing unit inventory stock.');
                    }
                    $update_existing_unit_inventory->close();

                    // Keep the items summary synced when the updated UoM is the default UoM.
                    $sync_default_summary = $conn->prepare("\n                        UPDATE items i\n                        INNER JOIN unit_types ut ON ut.unit_type_id = ?\n                        SET i.stock = CASE WHEN i.default_unit_type_id = ? THEN ? ELSE i.stock END,\n                            i.unit_type = CASE WHEN i.default_unit_type_id = ? THEN ut.unit_type_name ELSE i.unit_type END,\n                            i.updated_at = NOW()\n                        WHERE i.item_id = ?\n                    ");
                    if ($sync_default_summary) {
                        $sync_default_summary->bind_param("iidii", $matched_unit_type_id, $matched_unit_type_id, $new_current_inventory, $matched_unit_type_id, $item_id);
                        $sync_default_summary->execute();
                        $sync_default_summary->close();
                    }
                } else {
                    // No matching UoM row exists for this item.
                    // Create a new UoM row for this item since it doesn't exist yet.
                    
                    // First, check if the unit type exists or create it
                    $get_unit_type_id = $conn->prepare("SELECT unit_type_id FROM unit_types WHERE LOWER(TRIM(unit_type_name)) = LOWER(TRIM(?))");
                    $new_unit_type_id = null;
                    if ($get_unit_type_id) {
                        $get_unit_type_id->bind_param("s", $item_uom);
                        $get_unit_type_id->execute();
                        $unit_type_result = $get_unit_type_id->get_result();
                        if ($unit_type_row = $unit_type_result->fetch_assoc()) {
                            $new_unit_type_id = (int)$unit_type_row['unit_type_id'];
                        }
                        $get_unit_type_id->close();
                    }
                    
                    // If unit type doesn't exist, create it
                    if (!$new_unit_type_id) {
                        $create_unit_type = $conn->prepare("INSERT INTO unit_types (unit_type_name, status) VALUES (?, 'active')");
                        if ($create_unit_type) {
                            $create_unit_type->bind_param("s", $item_uom);
                            if ($create_unit_type->execute()) {
                                $new_unit_type_id = (int)$conn->insert_id;
                            }
                            $create_unit_type->close();
                        }
                    }
                    
                    // Now create the item_unit_inventory row for this new UoM
                    if ($new_unit_type_id) {
                        $create_unit_inventory = $conn->prepare("\n                            INSERT INTO item_unit_inventory (item_id, unit_type_id, current_inventory, unit_cost, total_cost, as_of_date, created_at, updated_at)\n                            VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())\n                        ");
                        if ($create_unit_inventory) {
                            $as_of_date_for_receive = $receive_date ?: date('Y-m-d');
                            $create_unit_inventory->bind_param("iiddds", $item_id, $new_unit_type_id, $item_qty, $item_price, $item_total_cost, $as_of_date_for_receive);
                            if (!$create_unit_inventory->execute()) {
                                throw new Exception('Failed to create new unit type inventory row.');
                            }
                            $create_unit_inventory->close();
                        }
                    } else {
                        throw new Exception('Failed to create or find unit type for this item.');
                    }
                }
                
                if (!empty($saved_transaction_id)) {
                    $sync_log_stmt = $conn->prepare("INSERT INTO item_unit_inventory_receive_sync_log (transaction_id, item_id, unit_type_id, quantity_added, reference_type, reference_id)
                        VALUES (?, ?, ?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE
                            item_id = VALUES(item_id),
                            unit_type_id = VALUES(unit_type_id),
                            quantity_added = VALUES(quantity_added),
                            reference_type = VALUES(reference_type),
                            reference_id = VALUES(reference_id)");
                    if ($sync_log_stmt) {
                        $log_unit_type_id = (int)($matched_unit_type_id ?: ($new_unit_type_id ?? 0));
                        $log_qty = (float)$item_qty;
                        $sync_log_stmt->bind_param("iiidsi", $saved_transaction_id, $item_id, $log_unit_type_id, $log_qty, $reference_type, $reference_id);
                        $sync_log_stmt->execute();
                        $sync_log_stmt->close();
                    }
                }

                $manifest_item_rows[] = [
                    'item_id' => (int)$item_id,
                    'item_name' => $item_name,
                    'item_code' => $item_code,
                    'item_description' => $item_description,
                    'category' => $item_category,
                    'uom' => $item_uom,
                    'qty' => $item_qty,
                    'unit_price' => $item_price,
                    'unit_cost' => $item_price,
                    'total_cost' => $item_total_cost
                ];
                $inventory_transaction->close();
            }

            if (!empty($saved_transaction_ids) || !empty($saved_attachments)) {
                ensureReceiveAttachmentDirectories();
                $manifest_id = 'receive_' . date('YmdHis') . '_' . bin2hex(random_bytes(4));
                $manifest_payload = [
                    'manifest_id' => $manifest_id,
                    'source' => $source,
                    'supplier_id' => $supplier_id,
                    'supplier_name' => $supplier_name,
                    'po_number' => $po_number,
                    'reference_type' => $source === 'supplier' ? 'purchase_order' : ($source === 'rmr' ? 'rmr' : 'production'),
                    'reference_id' => (int)($source === 'rmr' ? $reference_rmr_id : ($reference_po_id ?: 0)),
                    'receive_date' => $receive_date,
                    'encoded_date' => $encoded_date,
                    'receive_memo' => $receive_memo,
                    'created_at' => $manifest_created_at,
                    'created_by' => (int)$user_id,
                    'branch_id' => (int)$branch_id,
                    'transaction_ids' => $saved_transaction_ids,
                    'attachments' => $saved_attachments,
                    'items' => $manifest_item_rows
                ];
                @file_put_contents(receiveAttachmentBaseDir() . '/manifests/' . $manifest_id . '.json', json_encode($manifest_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            }

            if ($source === 'supplier' && $reference_po_id) {
                if ($supplier_id_column_exists) {
                    $safe_supplier_id = $supplier_id ?: 0;
                    $update_po_stmt = $conn->prepare("UPDATE purchase_orders SET supplier_name = ?, total_amount = ?, po_status = 'received', supplier_id = ?, updated_at = NOW() WHERE po_id = ?");
                    $update_po_stmt->bind_param("sdii", $supplier_name, $received_total_amount, $safe_supplier_id, $reference_po_id);
                } else {
                    $update_po_stmt = $conn->prepare("UPDATE purchase_orders SET supplier_name = ?, total_amount = ?, po_status = 'received', updated_at = NOW() WHERE po_id = ?");
                    $update_po_stmt->bind_param("sdi", $supplier_name, $received_total_amount, $reference_po_id);
                }
                if (!$update_po_stmt->execute()) {
                    throw new Exception('Failed to update purchase order after receipt.');
                }
                $update_po_stmt->close();
            }

            if ($source === 'rmr' && $reference_rmr_id > 0 && dbTableExists($conn, 'rmr_requests')) {
                $rmr_columns_after_receive = dbTableColumns($conn, 'rmr_requests');
                $rmr_status_col_after_receive = firstExistingColumn($rmr_columns_after_receive, ['rmr_status', 'status', 'request_status', 'process_status']);
                $rmr_id_col_after_receive = firstExistingColumn($rmr_columns_after_receive, ['rmr_id', 'request_id', 'return_id', 'id']);
                $rmr_received_date_col = firstExistingColumn($rmr_columns_after_receive, ['received_date', 'receive_date', 'date_received']);
                $rmr_received_by_col = firstExistingColumn($rmr_columns_after_receive, ['received_by', 'receive_by', 'receiver_id']);

                if ($rmr_status_col_after_receive && $rmr_id_col_after_receive) {
                    $rmr_update_sets = [sqlIdentifier($rmr_status_col_after_receive) . " = 'resolved'", "updated_at = NOW()"];
                    $rmr_update_types = '';
                    $rmr_update_values = [];

                    // After Receive Inventory, reflect the actual receive date and receiver in Bad Orders.
                    if ($rmr_received_date_col) {
                        $rmr_update_sets[] = sqlIdentifier($rmr_received_date_col) . " = ?";
                        $rmr_update_types .= 's';
                        $rmr_update_values[] = $receive_date;
                    }
                    if ($rmr_received_by_col) {
                        $rmr_update_sets[] = sqlIdentifier($rmr_received_by_col) . " = ?";
                        $rmr_update_types .= 'i';
                        $rmr_update_values[] = $user_id;
                    }

                    $rmr_update_types .= 'i';
                    $rmr_update_values[] = $reference_rmr_id;

                    $update_rmr_after_receive = $conn->prepare(
                        "UPDATE rmr_requests SET " . implode(', ', $rmr_update_sets) . " WHERE " . sqlIdentifier($rmr_id_col_after_receive) . " = ?"
                    );
                    if ($update_rmr_after_receive) {
                        $update_rmr_after_receive->bind_param($rmr_update_types, ...$rmr_update_values);
                        if (!$update_rmr_after_receive->execute()) {
                            throw new Exception('Failed to update RMR receive status after inventory receive.');
                        }
                        $update_rmr_after_receive->close();
                    }
                }
            }

            $conn->commit();
            echo json_encode([
                'success' => true,
                'message' => 'Received inventory saved successfully.',
                'po_id' => $reference_po_id,
                'po_number' => $po_number,
                'supplier_name' => $supplier_name,
                'attachments_saved' => count($saved_attachments),
                'manifest_id' => $manifest_id ?? null
            ]);
            exit;
        }


        elseif ($_POST['action'] === 'delete_receive_history') {
    $transaction_id = isset($_POST['transaction_id']) ? (int)$_POST['transaction_id'] : 0;
    $item_id = isset($_POST['item_id']) ? (int)$_POST['item_id'] : 0;
    $reference_type = isset($_POST['reference_type']) ? trim((string)$_POST['reference_type']) : '';
    $reference_id = isset($_POST['reference_id']) ? (int)$_POST['reference_id'] : 0;
    $created_at = isset($_POST['created_at']) ? trim((string)$_POST['created_at']) : '';

    $candidateRows = [];
    $manifest = findReceiveManifestForDelete($transaction_id, [
        'reference_type' => $reference_type,
        'reference_id' => $reference_id,
        'item_id' => $item_id,
        'created_at' => $created_at
    ]);

    if ($transaction_id > 0) {
        $lookup_query = "
            SELECT it.transaction_id, it.branch_id, it.item_id, it.quantity_changed, COALESCE(it.unit_cost,0) AS unit_cost, COALESCE(it.total_cost,0) AS total_cost, it.reference_type, it.reference_id, it.created_at
            FROM inventory_transactions it
            WHERE it.transaction_id = ? AND it.transaction_type = 'in'
        ";
        if (!$view_all_branches) {
            $lookup_query .= " AND it.branch_id = ?";
        }
        $lookup_stmt = $conn->prepare($lookup_query);
        if ($lookup_stmt) {
            if (!$view_all_branches) {
                $lookup_stmt->bind_param('ii', $transaction_id, $branch_id);
            } else {
                $lookup_stmt->bind_param('i', $transaction_id);
            }
            $lookup_stmt->execute();
            $lookup_result = $lookup_stmt->get_result();
            while ($row = $lookup_result ? $lookup_result->fetch_assoc() : null) {
                $candidateRows[] = $row;
            }
            $lookup_stmt->close();
        }
    }

    if (empty($candidateRows)) {
        $fallback_query = "
            SELECT it.transaction_id, it.branch_id, it.item_id, it.quantity_changed, COALESCE(it.unit_cost,0) AS unit_cost, COALESCE(it.total_cost,0) AS total_cost, it.reference_type, it.reference_id, it.created_at
            FROM inventory_transactions it
            WHERE it.transaction_type = 'in'
        ";
        $fallback_types = '';
        $fallback_params = [];
        if ($item_id > 0) {
            $fallback_query .= " AND it.item_id = ?";
            $fallback_types .= 'i';
            $fallback_params[] = $item_id;
        }
        if ($reference_type !== '') {
            $fallback_query .= " AND it.reference_type = ?";
            $fallback_types .= 's';
            $fallback_params[] = $reference_type;
        }
        if ($reference_id > 0 || $reference_type === 'production') {
            $fallback_query .= " AND COALESCE(it.reference_id, 0) = ?";
            $fallback_types .= 'i';
            $fallback_params[] = $reference_id;
        }
        if ($created_at !== '') {
            $fallback_query .= " AND ABS(TIMESTAMPDIFF(SECOND, it.created_at, ?)) <= 600";
            $fallback_types .= 's';
            $fallback_params[] = $created_at;
        }
        if (!$view_all_branches) {
            $fallback_query .= " AND it.branch_id = ?";
            $fallback_types .= 'i';
            $fallback_params[] = $branch_id;
        }
        $fallback_query .= " ORDER BY ABS(TIMESTAMPDIFF(SECOND, it.created_at, ?)) ASC, it.created_at DESC";
        $fallback_types .= 's';
        $fallback_params[] = ($created_at !== '' ? $created_at : date('Y-m-d H:i:s'));

        $fallback_stmt = $conn->prepare($fallback_query);
        if ($fallback_stmt) {
            if ($fallback_types !== '') {
                $fallback_stmt->bind_param($fallback_types, ...$fallback_params);
            }
            $fallback_stmt->execute();
            $fallback_result = $fallback_stmt->get_result();
            while ($row = $fallback_result ? $fallback_result->fetch_assoc() : null) {
                $candidateRows[] = $row;
            }
            $fallback_stmt->close();
        }
    }

    if (empty($candidateRows) && is_array($manifest) && !empty($manifest['items'])) {
        foreach (($manifest['items'] ?? []) as $manifestItem) {
            if ($item_id > 0 && (int)($manifestItem['item_id'] ?? 0) !== $item_id) {
                continue;
            }
            $mfItemId = (int)($manifestItem['item_id'] ?? 0);
            if ($mfItemId <= 0) continue;

            $mfQuery = "
                SELECT it.transaction_id, it.branch_id, it.item_id, it.quantity_changed, COALESCE(it.unit_cost,0) AS unit_cost, COALESCE(it.total_cost,0) AS total_cost, it.reference_type, it.reference_id, it.created_at
                FROM inventory_transactions it
                WHERE it.transaction_type = 'in'
                  AND it.item_id = ?
                  AND it.reference_type = ?
                  AND COALESCE(it.reference_id, 0) = ?
            ";
            $mfTypes = 'isi';
            $mfParams = [$mfItemId, (string)($manifest['reference_type'] ?? $reference_type), (int)($manifest['reference_id'] ?? $reference_id)];
            if (!$view_all_branches) {
                $mfQuery .= " AND it.branch_id = ?";
                $mfTypes .= 'i';
                $mfParams[] = $branch_id;
            }
            if (!empty($manifest['created_at'])) {
                $mfQuery .= " AND ABS(TIMESTAMPDIFF(SECOND, it.created_at, ?)) <= 600";
                $mfTypes .= 's';
                $mfParams[] = (string)$manifest['created_at'];
            }
            $mfQuery .= " ORDER BY it.created_at DESC LIMIT 1";
            $mfStmt = $conn->prepare($mfQuery);
            if ($mfStmt) {
                $mfStmt->bind_param($mfTypes, ...$mfParams);
                $mfStmt->execute();
                $mfRes = $mfStmt->get_result();
                if ($mfRow = ($mfRes ? $mfRes->fetch_assoc() : null)) {
                    $candidateRows[] = $mfRow;
                }
                $mfStmt->close();
            }
        }
    }

    if (empty($candidateRows)) {
        throw new Exception('Receive history record not found.');
    }

    $deletedCount = 0;
    $processedSignatures = [];

    foreach ($candidateRows as $tx) {
        if (!$tx) continue;

        $sig = implode('|', [
            (int)($tx['branch_id'] ?? 0),
            (int)($tx['item_id'] ?? 0),
            (string)($tx['reference_type'] ?? ''),
            (int)($tx['reference_id'] ?? 0),
            (string)($tx['created_at'] ?? '')
        ]);
        if (isset($processedSignatures[$sig])) {
            continue;
        }
        $processedSignatures[$sig] = true;

        $itemId = (int)$tx['item_id'];
        $qty = (float)$tx['quantity_changed'];
        $refType = (string)$tx['reference_type'];
        $refId = (int)$tx['reference_id'];
        $txBranch = (int)$tx['branch_id'];
        $txCreatedAt = (string)$tx['created_at'];
        $txId = (int)$tx['transaction_id'];
        $txUnitCost = (float)($tx['unit_cost'] ?? 0);
        $txTotalCost = (float)($tx['total_cost'] ?? 0);
        if ($txTotalCost <= 0 && $txUnitCost > 0) {
            $txTotalCost = $qty * $txUnitCost;
        }

        // Reverse the same stock and total cost that Receive Inventory added to item_unit_inventory.
        // This keeps Current Inventory consistent when a receive history row is deleted.
        $unitTypeIdToReverse = 0;
        if ($txId > 0) {
            $log_lookup = $conn->prepare("SELECT unit_type_id FROM item_unit_inventory_receive_sync_log WHERE transaction_id = ? LIMIT 1");
            if ($log_lookup) {
                $log_lookup->bind_param('i', $txId);
                $log_lookup->execute();
                $log_result = $log_lookup->get_result();
                if ($log_row = $log_result->fetch_assoc()) {
                    $unitTypeIdToReverse = (int)($log_row['unit_type_id'] ?? 0);
                }
                $log_lookup->close();
            }
        }
        if ($unitTypeIdToReverse <= 0) {
            $default_lookup = $conn->prepare("SELECT default_unit_type_id FROM items WHERE item_id = ? LIMIT 1");
            if ($default_lookup) {
                $default_lookup->bind_param('i', $itemId);
                $default_lookup->execute();
                $default_result = $default_lookup->get_result();
                if ($default_row = $default_result->fetch_assoc()) {
                    $unitTypeIdToReverse = (int)($default_row['default_unit_type_id'] ?? 0);
                }
                $default_lookup->close();
            }
        }
        if ($unitTypeIdToReverse > 0) {
            $unit_inv_lookup = $conn->prepare("SELECT inventory_id, current_inventory, COALESCE(NULLIF(total_cost, 0), COALESCE(current_inventory, 0) * COALESCE(unit_cost, 0)) AS total_cost FROM item_unit_inventory WHERE item_id = ? AND unit_type_id = ? LIMIT 1");
            if ($unit_inv_lookup) {
                $unit_inv_lookup->bind_param('ii', $itemId, $unitTypeIdToReverse);
                $unit_inv_lookup->execute();
                $unit_inv_result = $unit_inv_lookup->get_result();
                if ($unit_inv_row = $unit_inv_result->fetch_assoc()) {
                    $newUnitQty = max(0, (float)$unit_inv_row['current_inventory'] - $qty);
                    $newUnitTotalCost = max(0, (float)$unit_inv_row['total_cost'] - $txTotalCost);
                    $newUnitCost = $newUnitQty > 0 ? ($newUnitTotalCost / $newUnitQty) : 0;
                    $unit_inv_update = $conn->prepare("UPDATE item_unit_inventory SET current_inventory = ?, unit_cost = ?, total_cost = ?, updated_at = NOW() WHERE inventory_id = ? LIMIT 1");
                    if ($unit_inv_update) {
                        $unit_inv_update->bind_param('dddi', $newUnitQty, $newUnitCost, $newUnitTotalCost, $unit_inv_row['inventory_id']);
                        $unit_inv_update->execute();
                        $unit_inv_update->close();
                    }
                }
                $unit_inv_lookup->close();
            }
        }

        $inv_stmt = $conn->prepare("SELECT inventory_id, quantity_on_hand FROM inventory WHERE branch_id = ? AND item_id = ? LIMIT 1");
        if ($inv_stmt) {
            $inv_stmt->bind_param('ii', $txBranch, $itemId);
            $inv_stmt->execute();
            $inv_result = $inv_stmt->get_result();
            if ($inv_row = $inv_result->fetch_assoc()) {
                $newQty = max(0, (float)$inv_row['quantity_on_hand'] - $qty);
                $upd_inv = $conn->prepare("UPDATE inventory SET quantity_on_hand = ?, last_updated_by = ?, updated_at = NOW() WHERE inventory_id = ?");
                if ($upd_inv) {
                    $upd_inv->bind_param('dii', $newQty, $user_id, $inv_row['inventory_id']);
                    if (!$upd_inv->execute()) {
                        throw new Exception('Failed to reverse current inventory stock.');
                    }
                    $upd_inv->close();
                }
            }
            $inv_stmt->close();
        }

        $upd_item = $conn->prepare("UPDATE items SET stock = GREATEST(0, COALESCE(stock,0) - ?), stock_in_default_uom = GREATEST(0, COALESCE(stock_in_default_uom,0) - ?), updated_at = NOW() WHERE item_id = ?");
        if ($upd_item) {
            $upd_item->bind_param('ddi', $qty, $qty, $itemId);
            if (!$upd_item->execute()) {
                throw new Exception('Failed to reverse item stock.');
            }
            $upd_item->close();
        }

        if ($txId > 0) {
            if ($txId > 0) {
            $delete_receive_dates_stmt = $conn->prepare("DELETE FROM receive_inventory_dates WHERE transaction_id = ?");
            if ($delete_receive_dates_stmt) {
                $delete_receive_dates_stmt->bind_param('i', $txId);
                $delete_receive_dates_stmt->execute();
                $delete_receive_dates_stmt->close();
            }
        }

        $del_stmt = $conn->prepare("DELETE FROM inventory_transactions WHERE transaction_id = ? LIMIT 1");
            if ($del_stmt) {
                $del_stmt->bind_param('i', $txId);
                if (!$del_stmt->execute()) {
                    throw new Exception('Failed to delete receive history record.');
                }
                $deletedCount += $del_stmt->affected_rows;
                $del_stmt->close();
            }
        } else {
            $del_query = "
                DELETE FROM inventory_transactions
                WHERE transaction_type = 'in'
                  AND branch_id = ?
                  AND item_id = ?
                  AND reference_type = ?
                  AND COALESCE(reference_id, 0) = ?
                  AND created_at = ?
                LIMIT 1
            ";
            $del_stmt = $conn->prepare($del_query);
            if ($del_stmt) {
                $del_stmt->bind_param('iisds', $txBranch, $itemId, $refType, $refId, $txCreatedAt);
            }
            if ($del_stmt) {
                # fix types and execute separately below
                $del_stmt->close();
            }
            $del_stmt = $conn->prepare("
                DELETE FROM inventory_transactions
                WHERE transaction_type = 'in'
                  AND branch_id = ?
                  AND item_id = ?
                  AND reference_type = ?
                  AND COALESCE(reference_id, 0) = ?
                  AND created_at = ?
                LIMIT 1
            ");
            if ($del_stmt) {
                $del_stmt->bind_param('iisis', $txBranch, $itemId, $refType, $refId, $txCreatedAt);
                if (!$del_stmt->execute()) {
                    throw new Exception('Failed to delete receive history record.');
                }
                $deletedCount += $del_stmt->affected_rows;
                $del_stmt->close();
            }
        }

        if ($refType === 'purchase_order' && $refId > 0) {
            $po_stmt = $conn->prepare("UPDATE purchase_orders SET updated_at = NOW(), po_status = CASE WHEN po_status = 'received' THEN 'approved' ELSE po_status END WHERE po_id = ?");
            if ($po_stmt) {
                $po_stmt->bind_param('i', $refId);
                $po_stmt->execute();
                $po_stmt->close();
            }
        }
    }

    if ($deletedCount === 0) {
        throw new Exception('Receive history record not found.');
    }

    if ($manifest) {
        deleteReceiveAttachmentFiles($manifest);
    }

    $conn->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Receive record deleted and stock reversed successfully.'
    ]);
    exit;
}

elseif ($_POST['action'] === 'get_receive_history_details') {
            $transaction_id = isset($_POST['transaction_id']) ? (int)$_POST['transaction_id'] : 0;
            $item_id = isset($_POST['item_id']) ? (int)$_POST['item_id'] : 0;
            $reference_type = isset($_POST['reference_type']) ? trim($_POST['reference_type']) : '';
            $reference_id = isset($_POST['reference_id']) ? (int)$_POST['reference_id'] : 0;
            $created_at = isset($_POST['created_at']) ? trim($_POST['created_at']) : '';

            $detail = null;

            if ($transaction_id > 0) {
                $detail_query = "
                    SELECT 
                        it.transaction_id,
                        it.branch_id,
                        it.item_id,
                        it.transaction_type,
                        it.quantity_changed,
                        COALESCE(it.unit_cost, 0) AS unit_cost,
                        COALESCE(it.total_cost, 0) AS total_cost,
                        it.reference_type,
                        it.reference_id,
                        COALESCE(it.receive_date, rid.receive_date) AS receive_date,
                        COALESCE(it.encoded_date, rid.encoded_date) AS encoded_date,
                        it.receive_memo,
                        it.created_by,
                        it.created_at,
                        i.item_name,
                        i.item_code,
                        i.description AS item_description,
                        i.unit_type,
                        i.unit_price,
                        po.po_number,
                        po.supplier_name,
                        po.order_date,
                        po.expected_delivery,
                        po.total_amount,
                        po.po_status,
                        rr.rmr_number,
                        COALESCE(NULLIF(TRIM(rc.customer_name), ''), NULLIF(TRIM(rdc.customer_name), '')) AS rmr_customer_name,
                        b.branch_name,
                        CONCAT(u.first_name, ' ', u.last_name) AS received_by_name
                    FROM inventory_transactions it
                    LEFT JOIN receive_inventory_dates rid ON rid.transaction_id = it.transaction_id
                    LEFT JOIN items i ON it.item_id = i.item_id
                    LEFT JOIN purchase_orders po ON it.reference_type = 'purchase_order' AND it.reference_id = po.po_id
                    LEFT JOIN rmr_requests rr ON it.reference_type = 'rmr' AND it.reference_id = rr.rmr_id
                    LEFT JOIN customers rc ON rc.customer_id = rr.customer_id
                    LEFT JOIN deliveries rd ON rd.delivery_id = rr.delivery_id
                    LEFT JOIN customers rdc ON rdc.customer_id = rd.customer_id
                    LEFT JOIN branches b ON it.branch_id = b.branch_id
                    LEFT JOIN users u ON it.created_by = u.user_id
                    WHERE it.transaction_id = ?
                ";
                if (!$view_all_branches) {
                    $detail_query .= " AND it.branch_id = ?";
                }
                $detail_stmt = $conn->prepare($detail_query);
                if ($detail_stmt) {
                    if (!$view_all_branches) {
                        $detail_stmt->bind_param('ii', $transaction_id, $branch_id);
                    } else {
                        $detail_stmt->bind_param('i', $transaction_id);
                    }
                    $detail_stmt->execute();
                    $detail_result = $detail_stmt->get_result();
                    $detail = $detail_result ? $detail_result->fetch_assoc() : null;
                    $detail_stmt->close();
                }
            }

            if (!$detail) {
                $fallback_query = "
                    SELECT 
                        it.transaction_id,
                        it.branch_id,
                        it.item_id,
                        it.transaction_type,
                        it.quantity_changed,
                        COALESCE(it.unit_cost, 0) AS unit_cost,
                        COALESCE(it.total_cost, 0) AS total_cost,
                        it.reference_type,
                        it.reference_id,
                        COALESCE(it.receive_date, rid.receive_date) AS receive_date,
                        COALESCE(it.encoded_date, rid.encoded_date) AS encoded_date,
                        it.receive_memo,
                        it.created_by,
                        it.created_at,
                        i.item_name,
                        i.item_code,
                        i.description AS item_description,
                        i.unit_type,
                        i.unit_price,
                        po.po_number,
                        po.supplier_name,
                        po.order_date,
                        po.expected_delivery,
                        po.total_amount,
                        po.po_status,
                        rr.rmr_number,
                        COALESCE(NULLIF(TRIM(rc.customer_name), ''), NULLIF(TRIM(rdc.customer_name), '')) AS rmr_customer_name,
                        b.branch_name,
                        CONCAT(u.first_name, ' ', u.last_name) AS received_by_name
                    FROM inventory_transactions it
                    LEFT JOIN receive_inventory_dates rid ON rid.transaction_id = it.transaction_id
                    LEFT JOIN items i ON it.item_id = i.item_id
                    LEFT JOIN purchase_orders po ON it.reference_type = 'purchase_order' AND it.reference_id = po.po_id
                    LEFT JOIN rmr_requests rr ON it.reference_type = 'rmr' AND it.reference_id = rr.rmr_id
                    LEFT JOIN customers rc ON rc.customer_id = rr.customer_id
                    LEFT JOIN deliveries rd ON rd.delivery_id = rr.delivery_id
                    LEFT JOIN customers rdc ON rdc.customer_id = rd.customer_id
                    LEFT JOIN branches b ON it.branch_id = b.branch_id
                    LEFT JOIN users u ON it.created_by = u.user_id
                    WHERE it.transaction_type = 'in'
                ";
                $types = '';
                $params = [];
                if ($item_id > 0) {
                    $fallback_query .= " AND it.item_id = ?";
                    $types .= 'i';
                    $params[] = $item_id;
                }
                if ($reference_type !== '') {
                    $fallback_query .= " AND it.reference_type = ?";
                    $types .= 's';
                    $params[] = $reference_type;
                }
                if ($reference_id > 0) {
                    $fallback_query .= " AND it.reference_id = ?";
                    $types .= 'i';
                    $params[] = $reference_id;
                }
                if ($created_at !== '') {
                    $fallback_query .= " AND it.created_at = ?";
                    $types .= 's';
                    $params[] = $created_at;
                }
                if (!$view_all_branches) {
                    $fallback_query .= " AND it.branch_id = ?";
                    $types .= 'i';
                    $params[] = $branch_id;
                }
                $fallback_query .= " ORDER BY it.transaction_id DESC LIMIT 1";
                $fallback_stmt = $conn->prepare($fallback_query);
                if ($fallback_stmt) {
                    if ($types !== '') {
                        $fallback_stmt->bind_param($types, ...$params);
                    }
                    $fallback_stmt->execute();
                    $fallback_result = $fallback_stmt->get_result();
                    $detail = $fallback_result ? $fallback_result->fetch_assoc() : null;
                    $fallback_stmt->close();
                }
            }

            if (!$detail && $item_id > 0) {
                $manual_detail_stmt = $conn->prepare("
                    SELECT 
                        0 AS transaction_id,
                        ? AS branch_id,
                        i.item_id,
                        'in' AS transaction_type,
                        0 AS quantity_changed,
                        0 AS unit_cost,
                        0 AS total_cost,
                        ? AS reference_type,
                        ? AS reference_id,
                        '' AS receive_memo,
                        NULL AS receive_date,
                        NULL AS encoded_date,
                        ? AS created_by,
                        ? AS created_at,
                        i.item_name,
                        i.item_code,
                        i.description AS item_description,
                        i.unit_type,
                        i.unit_price,
                        '' AS po_number,
                        '' AS supplier_name,
                        '' AS order_date,
                        '' AS expected_delivery,
                        0 AS total_amount,
                        '' AS po_status,
                        '' AS branch_name,
                        '' AS received_by_name
                    FROM items i
                    WHERE i.item_id = ?
                    LIMIT 1
                ");
                if ($manual_detail_stmt) {
                    $refTypeTmp = $reference_type;
                    $createdByTmp = $user_id;
                    $createdAtTmp = $created_at !== '' ? $created_at : date('Y-m-d H:i:s');
                    $manual_detail_stmt->bind_param('isissi', $branch_id, $refTypeTmp, $reference_id, $createdByTmp, $createdAtTmp, $item_id);
                    $manual_detail_stmt->execute();
                    $manual_res = $manual_detail_stmt->get_result();
                    $detail = $manual_res ? $manual_res->fetch_assoc() : null;
                    $manual_detail_stmt->close();
                }
            }

            if (!$detail) {
                throw new Exception('Receive history record not found.');
            }

            $po_items = [];
            $manifest = loadReceiveManifestByTransactionId((int)($detail['transaction_id'] ?? 0));
            if (!$manifest) {
                $manifest = loadReceiveManifestByContext([
                    'reference_type' => $detail['reference_type'] ?? $reference_type,
                    'reference_id' => $detail['reference_id'] ?? $reference_id,
                    'item_id' => $detail['item_id'] ?? $item_id,
                    'created_at' => $detail['created_at'] ?? $created_at
                ]);
            }

            if (empty($detail['receive_memo']) && is_array($manifest ?? null) && !empty($manifest['receive_memo'])) {
                $detail['receive_memo'] = (string)$manifest['receive_memo'];
            }

            if (false && ($detail['reference_type'] ?? '') === 'purchase_order' && !empty($detail['reference_id'])) {
                $po_items_stmt = $conn->prepare("
                    SELECT 
                        poi.quantity_ordered,
                        poi.unit_price,
                        i.item_id,
                        i.item_name,
                        i.item_code,
                        i.description AS item_description,
                        i.unit_type
                    FROM purchase_order_items poi
                    LEFT JOIN items i ON poi.item_id = i.item_id
                    WHERE poi.po_id = ?
                    ORDER BY poi.po_item_id ASC
                ");
                if ($po_items_stmt) {
                    $po_ref_id = (int)$detail['reference_id'];
                    $po_items_stmt->bind_param('i', $po_ref_id);
                    $po_items_stmt->execute();
                    $po_items_result = $po_items_stmt->get_result();
                    $po_items = $po_items_result ? $po_items_result->fetch_all(MYSQLI_ASSOC) : [];
                    $po_items_stmt->close();
                }
            }

            $attachments = is_array($manifest['attachments'] ?? null) ? $manifest['attachments'] : [];
            $attachment_note = !empty($attachments)
                ? 'Saved receive attachment(s) found.'
                : 'No saved attachment found for this receive record.';

            echo json_encode([
                'success' => true,
                'detail' => $detail,
                'po_items' => $po_items,
                'attachments' => $attachments,
                'manifest' => $manifest,
                'attachment_note' => $attachment_note
            ]);
            exit;
        }

        // UPDATE PURCHASE ORDER (header only)
        elseif ($_POST['action'] === 'update_po') {
            $po_id = (int)$_POST['po_id'];
            $supplier_name = $_POST['supplier_name'];
            $supplier_id = isset($_POST['supplier_id']) && !empty($_POST['supplier_id']) ? (int)$_POST['supplier_id'] : null;
            $order_date = $_POST['order_date'];
            $expected_delivery = !empty($_POST['expected_delivery']) ? $_POST['expected_delivery'] : null;
            $total_amount = (float)$_POST['total_amount'];
            $po_status = $_POST['po_status'];
            
            // Verify PO belongs to user's branch
            if ($po_branch_column_exists && !$view_all_branches) {
                $check_query = "SELECT po_id FROM purchase_orders WHERE po_id = ? AND branch_id = ?";
                $check_stmt = $conn->prepare($check_query);
                $check_stmt->bind_param("ii", $po_id, $branch_id);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                if ($check_result->num_rows === 0) {
                    throw new Exception('Purchase order not found or access denied');
                }
            }
            
            // Get current PO status before update
            $status_query = "SELECT po_status FROM purchase_orders WHERE po_id = ?";
            $status_stmt = $conn->prepare($status_query);
            $status_stmt->bind_param("i", $po_id);
            $status_stmt->execute();
            $status_result = $status_stmt->get_result();
            $current_po = $status_result->fetch_assoc();
            $old_status = $current_po['po_status'];
            
            // Build update data
            $fields = [
                'supplier_name' => $supplier_name,
                'order_date' => $order_date,
                'expected_delivery' => $expected_delivery,
                'total_amount' => $total_amount,
                'po_status' => $po_status,
                'updated_at' => 'NOW()'
            ];
            if ($supplier_id_column_exists) {
                $fields['supplier_id'] = $supplier_id;
            }
            
            // Build query
            $set_parts = [];
            $types = '';
            $values = [];
            foreach ($fields as $col => $val) {
                if ($val === 'NOW()') {
                    $set_parts[] = "$col = NOW()";
                } else {
                    $set_parts[] = "$col = ?";
                    if (is_int($val)) $types .= 'i';
                    elseif (is_float($val)) $types .= 'd';
                    else $types .= 's';
                    $values[] = $val;
                }
            }
            $types .= 'i';
            $values[] = $po_id;
            
            $update_query = "UPDATE purchase_orders SET " . implode(', ', $set_parts) . " WHERE po_id = ?";
            $update_stmt = $conn->prepare($update_query);
            $update_stmt->bind_param($types, ...$values);
            if (!$update_stmt->execute()) throw new Exception('Failed to update purchase order: ' . $update_stmt->error);
            
            // If status changed to 'received', update inventory
            if ($po_status === 'received' && $old_status !== 'received') {
                $items_query = "SELECT poi.item_id, poi.quantity_ordered, i.item_name 
                               FROM purchase_order_items poi
                               JOIN items i ON poi.item_id = i.item_id
                               WHERE poi.po_id = ?";
                $items_stmt = $conn->prepare($items_query);
                $items_stmt->bind_param("i", $po_id);
                $items_stmt->execute();
                $items_result = $items_stmt->get_result();
                
                while ($item = $items_result->fetch_assoc()) {
                    $item_id = $item['item_id'];
                    $quantity = $item['quantity_ordered'];
                    
                    // Check if inventory record exists
                    $inv_query = "SELECT inventory_id, quantity_on_hand FROM inventory WHERE branch_id = ? AND item_id = ?";
                    $inv_stmt = $conn->prepare($inv_query);
                    $inv_stmt->bind_param("ii", $branch_id, $item_id);
                    $inv_stmt->execute();
                    $inv_result = $inv_stmt->get_result();
                    
                    if ($inv_result->num_rows > 0) {
                        $inv_row = $inv_result->fetch_assoc();
                        $new_quantity = $inv_row['quantity_on_hand'] + $quantity;
                        $update_inv_query = "UPDATE inventory SET quantity_on_hand = ?, last_updated_by = ?, updated_at = NOW() WHERE inventory_id = ?";
                        $update_inv_stmt = $conn->prepare($update_inv_query);
                        $update_inv_stmt->bind_param("iii", $new_quantity, $user_id, $inv_row['inventory_id']);
                        if (!$update_inv_stmt->execute()) throw new Exception('Failed to update inventory for item: ' . $item['item_name']);
                    } else {
                        $insert_inv_query = "INSERT INTO inventory (branch_id, item_id, quantity_on_hand, quantity_reserved, last_updated_by, updated_at) VALUES (?, ?, ?, 0, ?, NOW())";
                        $insert_inv_stmt = $conn->prepare($insert_inv_query);
                        $insert_inv_stmt->bind_param("iiii", $branch_id, $item_id, $quantity, $user_id);
                        if (!$insert_inv_stmt->execute()) throw new Exception('Failed to create inventory record for item: ' . $item['item_name']);
                    }
                    
                    // Record inventory transaction
                    $trans_query = "INSERT INTO inventory_transactions (branch_id, item_id, transaction_type, quantity_changed, reference_type, reference_id, created_by, created_at) VALUES (?, ?, 'in', ?, 'purchase_order', ?, ?, NOW())";
                    $trans_stmt = $conn->prepare($trans_query);
                    $trans_stmt->bind_param("iiiii", $branch_id, $item_id, $quantity, $po_id, $user_id);
                    if (!$trans_stmt->execute()) throw new Exception('Failed to record inventory transaction for item: ' . $item['item_name']);
                }
            }
            
            $conn->commit();
            echo json_encode([
                'success' => true,
                'message' => 'Purchase order updated successfully' . ($po_status === 'received' ? ' and inventory updated' : '')
            ]);
            exit;
        }
        
        // DELETE PURCHASE ORDER
        elseif ($_POST['action'] === 'delete_po') {
            $po_id = (int)$_POST['po_id'];
            
            if ($po_branch_column_exists && !$view_all_branches) {
                $check_query = "SELECT po_id FROM purchase_orders WHERE po_id = ? AND branch_id = ?";
                $check_stmt = $conn->prepare($check_query);
                $check_stmt->bind_param("ii", $po_id, $branch_id);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                if ($check_result->num_rows === 0) throw new Exception('Purchase order not found or access denied');
            }
            
            $status_query = "SELECT po_status FROM purchase_orders WHERE po_id = ?";
            $status_stmt = $conn->prepare($status_query);
            $status_stmt->bind_param("i", $po_id);
            $status_stmt->execute();
            $status_result = $status_stmt->get_result();
            $po_data = $status_result->fetch_assoc();
            if ($po_data['po_status'] === 'received') throw new Exception('Cannot delete a received purchase order');
            
            $delete_items_query = "DELETE FROM purchase_order_items WHERE po_id = ?";
            $delete_items_stmt = $conn->prepare($delete_items_query);
            $delete_items_stmt->bind_param("i", $po_id);
            $delete_items_stmt->execute();
            
            $delete_order_query = "DELETE FROM purchase_orders WHERE po_id = ?";
            $delete_order_stmt = $conn->prepare($delete_order_query);
            $delete_order_stmt->bind_param("i", $po_id);
            if (!$delete_order_stmt->execute()) throw new Exception('Failed to delete purchase order: ' . $delete_order_stmt->error);
            
            $conn->commit();
            echo json_encode(['success' => true, 'message' => 'Purchase order deleted successfully']);
            exit;
        }
        
        // GET PURCHASE ORDER DETAILS (with discount info)
        elseif ($_POST['action'] === 'get_po') {
            $po_id = (int)$_POST['po_id'];
            
            $query = "
                SELECT 
                    po.*,
                    b.branch_name,
                    CONCAT(u.first_name, ' ', u.last_name) as created_by_name,
                    COUNT(poi.po_item_id) as total_items,
                    IFNULL(SUM(poi.quantity_ordered), 0) as total_quantity
                FROM purchase_orders po
                LEFT JOIN branches b ON po.branch_id = b.branch_id
                LEFT JOIN users u ON po.created_by = u.user_id
                LEFT JOIN purchase_order_items poi ON po.po_id = poi.po_id
                WHERE po.po_id = ?
            ";
            if ($po_branch_column_exists && !$view_all_branches) {
                $query .= " AND po.branch_id = ?";
                $stmt = $conn->prepare($query);
                $stmt->bind_param("ii", $po_id, $branch_id);
            } else {
                $stmt = $conn->prepare($query);
                $stmt->bind_param("i", $po_id);
            }
            $stmt->execute();
            $result = $stmt->get_result();
            $po = $result->fetch_assoc();
            
            if ($po) {
                // Get PO items including discount fields
                $items_query = "
                    SELECT 
                        poi.*,
                        i.item_code,
                        i.item_name,
                        i.description,
                        " . ($item_category_column ? "i.`{$item_category_column}` AS category," : "'' AS category,") . "
                        i.unit_type
                    FROM purchase_order_items poi
                    JOIN items i ON poi.item_id = i.item_id
                    WHERE poi.po_id = ?
                    ORDER BY poi.po_item_id
                ";
                $items_stmt = $conn->prepare($items_query);
                $items_stmt->bind_param("i", $po_id);
                $items_stmt->execute();
                $items_result = $items_stmt->get_result();
                $items = $items_result->fetch_all(MYSQLI_ASSOC);
                
                echo json_encode([
                    'success' => true,
                    'po' => $po,
                    'items' => $items
                ]);
            } else {
                throw new Exception('Purchase order not found');
            }
            exit;
        }
        
        // ADD PO ITEM (with discount)
        elseif ($_POST['action'] === 'add_po_item') {
            $po_id = (int)$_POST['po_id'];
            $item_id = (int)$_POST['item_id'];
            $quantity_ordered = (int)$_POST['quantity_ordered'];
            $unit_price = (float)$_POST['unit_price'];
            $discount_type = isset($_POST['discount_type']) ? $_POST['discount_type'] : null;
            $discount_value = isset($_POST['discount_value']) ? (float)$_POST['discount_value'] : 0;
            
            // Verify PO belongs to user's branch
            if ($po_branch_column_exists && !$view_all_branches) {
                $check_query = "SELECT po_id FROM purchase_orders WHERE po_id = ? AND branch_id = ?";
                $check_stmt = $conn->prepare($check_query);
                $check_stmt->bind_param("ii", $po_id, $branch_id);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                if ($check_result->num_rows === 0) throw new Exception('Purchase order not found or access denied');
            }
            
            // Check if PO is received
            $status_query = "SELECT po_status FROM purchase_orders WHERE po_id = ?";
            $status_stmt = $conn->prepare($status_query);
            $status_stmt->bind_param("i", $po_id);
            $status_stmt->execute();
            $status_result = $status_stmt->get_result();
            $po_data = $status_result->fetch_assoc();
            if ($po_data['po_status'] === 'received') throw new Exception('Cannot add items to a received purchase order');
            
            // Build insert
            $item_fields = ['po_id', 'item_id', 'quantity_ordered', 'unit_price'];
            $item_placeholders = ['?', '?', '?', '?'];
            $item_types = 'iiid';
            $item_values = [$po_id, $item_id, $quantity_ordered, $unit_price];
            
            if ($discount_type_column_exists && $discount_type) {
                $item_fields[] = 'discount_type';
                $item_placeholders[] = '?';
                $item_types .= 's';
                $item_values[] = $discount_type;
            }
            if ($discount_value_column_exists && $discount_value > 0) {
                $item_fields[] = 'discount_value';
                $item_placeholders[] = '?';
                $item_types .= 'd';
                $item_values[] = $discount_value;
            }
            
            $insert_query = "INSERT INTO purchase_order_items (" . implode(', ', $item_fields) . ") VALUES (" . implode(', ', $item_placeholders) . ")";
            $insert_stmt = $conn->prepare($insert_query);
            $insert_stmt->bind_param($item_types, ...$item_values);
            if (!$insert_stmt->execute()) throw new Exception('Failed to add item: ' . $insert_stmt->error);
            
            // Update PO total amount (sum of discounted subtotals)
            $update_total_query = "UPDATE purchase_orders 
                                  SET total_amount = (
                                      SELECT IFNULL(SUM(
                                          CASE 
                                              WHEN poi.discount_type = 'percentage' THEN poi.quantity_ordered * poi.unit_price * (1 - poi.discount_value / 100)
                                              WHEN poi.discount_type = 'fixed' THEN poi.quantity_ordered * (poi.unit_price - poi.discount_value)
                                              ELSE poi.quantity_ordered * poi.unit_price
                                          END
                                      ), 0)
                                      FROM purchase_order_items poi
                                      WHERE poi.po_id = ?
                                  )
                                  WHERE po_id = ?";
            $update_total_stmt = $conn->prepare($update_total_query);
            $update_total_stmt->bind_param("ii", $po_id, $po_id);
            $update_total_stmt->execute();
            
            $conn->commit();
            echo json_encode(['success' => true, 'message' => 'Item added successfully']);
            exit;
        }
        
        // DELETE PO ITEM
        elseif ($_POST['action'] === 'delete_po_item') {
            $po_item_id = (int)$_POST['po_item_id'];
            $po_id = (int)$_POST['po_id'];
            
            if ($po_branch_column_exists && !$view_all_branches) {
                $check_query = "SELECT po_id FROM purchase_orders WHERE po_id = ? AND branch_id = ?";
                $check_stmt = $conn->prepare($check_query);
                $check_stmt->bind_param("ii", $po_id, $branch_id);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                if ($check_result->num_rows === 0) throw new Exception('Purchase order not found or access denied');
            }
            
            $status_query = "SELECT po_status FROM purchase_orders WHERE po_id = ?";
            $status_stmt = $conn->prepare($status_query);
            $status_stmt->bind_param("i", $po_id);
            $status_stmt->execute();
            $status_result = $status_stmt->get_result();
            $po_data = $status_result->fetch_assoc();
            if ($po_data['po_status'] === 'received') throw new Exception('Cannot delete items from a received purchase order');
            
            $delete_query = "DELETE FROM purchase_order_items WHERE po_item_id = ?";
            $delete_stmt = $conn->prepare($delete_query);
            $delete_stmt->bind_param("i", $po_item_id);
            if (!$delete_stmt->execute()) throw new Exception('Failed to delete item: ' . $delete_stmt->error);
            
            // Update PO total amount
            $update_total_query = "UPDATE purchase_orders 
                                  SET total_amount = (
                                      SELECT IFNULL(SUM(
                                          CASE 
                                              WHEN poi.discount_type = 'percentage' THEN poi.quantity_ordered * poi.unit_price * (1 - poi.discount_value / 100)
                                              WHEN poi.discount_type = 'fixed' THEN poi.quantity_ordered * (poi.unit_price - poi.discount_value)
                                              ELSE poi.quantity_ordered * poi.unit_price
                                          END
                                      ), 0)
                                      FROM purchase_order_items poi
                                      WHERE poi.po_id = ?
                                  )
                                  WHERE po_id = ?";
            $update_total_stmt = $conn->prepare($update_total_query);
            $update_total_stmt->bind_param("ii", $po_id, $po_id);
            $update_total_stmt->execute();
            
            $conn->commit();
            echo json_encode(['success' => true, 'message' => 'Item deleted successfully']);
            exit;
        }
        
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

// FETCH PURCHASE ORDERS
$po_query = "
    SELECT 
        po.po_id,
        po.po_number,
        po.order_date,
        po.expected_delivery,
        po.total_amount,
        po.po_status,
        po.supplier_name,
        po.branch_id,
        po.created_at,
        po.updated_at,
        po.created_by,
        b.branch_name,
        CONCAT(u.first_name, ' ', u.last_name) as created_by_name,
        COUNT(poi.po_item_id) as total_items,
        IFNULL(SUM(poi.quantity_ordered), 0) as total_quantity
    FROM purchase_orders po
    LEFT JOIN branches b ON po.branch_id = b.branch_id
    LEFT JOIN users u ON po.created_by = u.user_id
    LEFT JOIN purchase_order_items poi ON po.po_id = poi.po_id
    WHERE 1=1
    $po_branch_condition
    GROUP BY po.po_id
    ORDER BY po.created_at DESC, po.po_id DESC
";
$po_result = $conn->query($po_query);
$purchase_orders = $po_result ? $po_result->fetch_all(MYSQLI_ASSOC) : [];

// STATISTICS
$total_po = count($purchase_orders);
$draft_po = count(array_filter($purchase_orders, fn($po) => $po['po_status'] === 'draft'));
$submitted_po = count(array_filter($purchase_orders, fn($po) => $po['po_status'] === 'submitted'));
$approved_po = count(array_filter($purchase_orders, fn($po) => $po['po_status'] === 'approved'));
$received_po = count(array_filter($purchase_orders, fn($po) => $po['po_status'] === 'received'));
$cancelled_po = count(array_filter($purchase_orders, fn($po) => $po['po_status'] === 'cancelled'));

$statTotalPO = $total_po;
$statProcessingPO = $submitted_po + $approved_po;
$statDeliveredPO = $received_po;
$statReturnedPO = $cancelled_po;

// Unique suppliers for filter
$filter_suppliers_query = "SELECT DISTINCT supplier_name FROM purchase_orders 
                    WHERE supplier_name IS NOT NULL AND supplier_name != ''";
if ($po_branch_column_exists && !$view_all_branches) {
    $filter_suppliers_query .= " AND branch_id = $branch_id";
}
$filter_suppliers_query .= " ORDER BY supplier_name";
$filter_suppliers_result = $conn->query($filter_suppliers_query);
$filter_suppliers = $filter_suppliers_result ? $filter_suppliers_result->fetch_all(MYSQLI_ASSOC) : [];


// RECEIVE HISTORY DATA (uses existing inventory transactions save flow)
$receive_history_query = "
    SELECT 
        it.transaction_id,
        it.item_id,
        it.quantity_changed,
        COALESCE(it.unit_cost, 0) AS unit_cost,
        COALESCE(it.total_cost, 0) AS total_cost,
        it.reference_type,
        it.reference_id,
        COALESCE(it.receive_date, rid.receive_date) AS receive_date,
        COALESCE(it.encoded_date, rid.encoded_date) AS encoded_date,
        it.receive_memo,
        it.created_by,
        it.created_at,
        i.item_name,
        i.item_code,
        i.description AS item_description,
        i.unit_type,
        po.po_number,
        po.supplier_name,
        rr.rmr_number,
        COALESCE(NULLIF(TRIM(rc.customer_name), ''), NULLIF(TRIM(rdc.customer_name), '')) AS rmr_customer_name,
        CONCAT(u.first_name, ' ', u.last_name) AS received_by_name
    FROM inventory_transactions it
    LEFT JOIN receive_inventory_dates rid ON rid.transaction_id = it.transaction_id
    LEFT JOIN items i ON it.item_id = i.item_id
    LEFT JOIN purchase_orders po ON it.reference_type = 'purchase_order' AND it.reference_id = po.po_id
    LEFT JOIN rmr_requests rr ON it.reference_type = 'rmr' AND it.reference_id = rr.rmr_id
    LEFT JOIN customers rc ON rc.customer_id = rr.customer_id
    LEFT JOIN deliveries rd ON rd.delivery_id = rr.delivery_id
    LEFT JOIN customers rdc ON rdc.customer_id = rd.customer_id
    LEFT JOIN users u ON it.created_by = u.user_id
    WHERE it.transaction_type = 'in'
      AND it.reference_type IN ('purchase_order', 'production', 'rmr')
";
if (!$view_all_branches) {
    $receive_history_query .= " AND it.branch_id = " . (int)$branch_id;
}
$receive_history_query .= " ORDER BY it.created_at DESC, it.transaction_id DESC";
$receive_history_result = $conn->query($receive_history_query);
$receive_history_list = $receive_history_result ? $receive_history_result->fetch_all(MYSQLI_ASSOC) : [];

// Helper functions
function getPOStatusClass($status) {
    return match($status) {
        'draft' => 'status-draft',
        'submitted' => 'status-processing',
        'approved' => 'status-approved',
        'received' => 'status-delivered',
        'cancelled' => 'status-cancelled',
        default => 'status-draft'
    };
}

function getPOStatusText($status) {
    return match($status) {
        'draft' => 'Draft',
        'submitted' => 'Processing',
        'approved' => 'Approved',
        'received' => 'Delivered',
        'cancelled' => 'Cancelled',
        default => ucfirst($status)
    };
}

function formatDate($dateStr) {
    if (!$dateStr || $dateStr == '0000-00-00' || $dateStr == '0000-00-00 00:00:00') return '';
    try {
        $dateStr = explode(' ', $dateStr)[0];
        $date = new DateTime($dateStr);
        return $date->format('M d, Y');
    } catch (Exception $e) {
        return '';
    }
}

function formatDateTime($dateStr) {
    if (!$dateStr || $dateStr == '0000-00-00 00:00:00') return '';
    try {
        $date = new DateTime($dateStr);
        return $date->format('M d, Y H:i');
    } catch (Exception $e) {
        return '';
    }
}

function formatDateForInput($dateStr) {
    if (!$dateStr || $dateStr == '0000-00-00' || $dateStr == '0000-00-00 00:00:00') return '';
    try {
        $dateStr = explode(' ', $dateStr)[0];
        $date = new DateTime($dateStr);
        return $date->format('Y-m-d');
    } catch (Exception $e) {
        return '';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receive Inventory - Branch Admin</title>
    <link rel="icon" type="image/png" href="../Pictures/favicon-96x96.png" sizes="96x96" />
    <link rel="icon" type="image/svg+xml" href="../Pictures/favicon.svg" />
    <link rel="shortcut icon" href="../Pictures/favicon.ico" />
    <link rel="apple-touch-icon" sizes="180x180" href="../Pictures/apple-touch-icon.png" />
    <link rel="manifest" href="../Pictures/site.webmanifest" />
    <link rel="stylesheet" href="../css/current_inventory.css">
    <link rel="stylesheet" href="../css/purchase_order.css">
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <!-- Select2 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <!-- SheetJS for Excel Export -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <!-- SweetAlert2 -->
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
        <!-- Session Checker -->
    <script src="../js/session-checker.js"></script>
    
    <style>
                /* Branch badge styling */
        .branch-badge {
            background-color: #e7f1ff;
            color: #0d6efd;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
            margin-left: 5px;
        }
        
        /* Alert for missing columns */
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
        
        /* Main layout */
        .main-content {
            padding: 20px 30px;
            transition: margin-left 0.3s ease;
        }
        
        /* ADD BUTTON WRAPPER - OUTSIDE FILTER */
        .action-button-wrapper {
            margin-bottom: 1.25rem;
            text-align: right;
            display: flex;
            justify-content: flex-end;
            gap: 0.75rem;
        }
        
        .btn-outline-success {
            border: 1px solid #198754;
            color: #198754;
            background: white;
            border-radius: 10px;
            padding: 0.6rem 1.2rem;
            font-weight: 600;
            font-size: 0.85rem;
            transition: all 0.3s ease;
        }
        
        .btn-outline-success:hover {
            background: #198754;
            color: white;
            transform: translateY(-2px);
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #059669, #047857) !important;
            border: none !important;
            border-radius: 10px !important;
            padding: 0.6rem 1.2rem !important;
            font-weight: 600 !important;
            font-size: 0.85rem !important;
            transition: all 0.3s ease !important;
            box-shadow: 0 4px 8px rgba(5, 150, 105, 0.25) !important;
            color: white;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px) !important;
            box-shadow: 0 8px 18px rgba(5, 150, 105, 0.35) !important;
            background: linear-gradient(135deg, #047857, #065f46) !important;
        }


        /* SweetAlert2 custom theme - matches system green UI */
        .amgc-swal-popup {
            border-radius: 18px !important;
            padding: 1.5rem !important;
            box-shadow: 0 18px 45px rgba(15, 23, 42, 0.18) !important;
            border: 1px solid rgba(5, 150, 105, 0.12) !important;
        }

        .amgc-swal-title {
            color: #064e3b !important;
            font-weight: 700 !important;
            font-size: 1.25rem !important;
        }

        .amgc-swal-html {
            color: #475569 !important;
            font-size: 0.95rem !important;
            line-height: 1.5 !important;
        }

        .amgc-swal-confirm {
            background: linear-gradient(135deg, #059669, #047857) !important;
            border: none !important;
            border-radius: 10px !important;
            padding: 0.65rem 1.25rem !important;
            font-weight: 600 !important;
            box-shadow: 0 4px 10px rgba(5, 150, 105, 0.28) !important;
        }

        .amgc-swal-confirm:hover {
            background: linear-gradient(135deg, #047857, #065f46) !important;
            box-shadow: 0 8px 18px rgba(5, 150, 105, 0.35) !important;
        }

        .amgc-swal-cancel {
            border-radius: 10px !important;
            padding: 0.65rem 1.25rem !important;
            font-weight: 600 !important;
        }
        
        @media (max-width: 768px) {
            .action-button-wrapper {
                justify-content: center;
                margin-bottom: 1rem;
                gap: 0.5rem;
            }
            
            .btn-outline-success,
            .btn-primary {
                flex: 1;
                padding: 0.5rem 0.8rem !important;
                font-size: 0.75rem !important;
                text-align: center;
                justify-content: center;
            }
        }
        
        @media (max-width: 480px) {
            .action-button-wrapper {
                flex-direction: column;
            }
            
            .btn-outline-success,
            .btn-primary {
                width: 100%;
            }
        }
        
        /* Table wrapper */
        .table-wrapper {
            margin: 0 0 30px 0;
            width: 100%;
        }
        
        /* Table container */
        .table-container {
            background-color: white;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            overflow-x: auto;
            width: 100%;
        }
        
        /* Table styling */
        .po-table {
            margin-bottom: 0;
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }
        
        /* Column width definitions */
        .col-po { width: 11%; }
        .col-supplier { width: 13%; }
        .col-date { width: 10%; }
        <?php if ($po_branch_column_exists && $view_all_branches): ?>
        .col-branch { width: 8%; }
        <?php endif; ?>
        .col-items { width: 7%; }
        .col-qty { width: 8%; }
        .col-amount { width: 12%; }
        .col-status { width: 10%; }
        .col-expected { width: 12%; }
        .col-actions { width: 12%; }
        
        /* Table header styling */
        .po-table thead th {
            background-color: #f8f9fa;
            font-weight: 600;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #495057;
            padding: 16px 12px;
            border-bottom: 2px solid #dee2e6;
            white-space: nowrap;
            vertical-align: middle;
            text-align: left;
        }
        
        /* Table cell styling */
        .po-table tbody td {
            padding: 14px 12px;
            vertical-align: middle;
            border-bottom: 1px solid #e9ecef;
            font-size: 13px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        /* Column-specific alignments */
        .col-items,
        .col-qty {
            text-align: center !important;
        }
        
        .col-items th,
        .col-qty th {
            text-align: center !important;
        }
        
        .col-amount {
            text-align: right !important;
        }
        
        .col-amount th {
            text-align: right !important;
            padding-right: 20px !important;
        }
        
        .col-actions {
            text-align: center !important;
        }
        
        .col-actions th {
            text-align: center !important;
        }
        
        /* Hover effect */
        .po-table tbody tr:hover {
            background-color: #f8f9fa;
        }
        
        /* Status badge styling */
        .status-badge {
            display: inline-block;
            padding: 5px 12px;
            font-size: 12px;
            font-weight: 500;
            border-radius: 20px;
            text-align: center;
            min-width: 85px;
            white-space: nowrap;
        }
        
        .status-draft {
            background-color: #e9ecef;
            color: #495057;
        }
        
        .status-processing {
            background-color: #cfe2ff;
            color: #084298;
        }
        
        .status-approved {
            background-color: #cce5ff;
            color: #004085;
        }
        
        .status-delivered {
            background-color: #d1e7dd;
            color: #0a3622;
        }
        
        .status-cancelled {
            background-color: #f8d7da;
            color: #58151c;
        }
        
        /* Action buttons styling */
        .action-buttons {
            display: flex;
            gap: 6px;
            justify-content: center;
            align-items: center;
        }
        
        .table-btn {
            background: none;
            border: none;
            padding: 6px;
            border-radius: 4px;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 14px;
        }
        
        .table-btn:hover {
            background-color: #e9ecef;
        }
        
        .btn-view { color: #0d6efd; }
        .btn-edit { color: #ffc107; }
        .btn-delete { color: #dc3545; }
        
        /* Select2 customization */
        .select2-container--default .select2-selection--single {
            height: 38px;
            border: 1px solid #ced4da;
            border-radius: 4px;
        }
        
        .select2-container--default .select2-selection--single .select2-selection__rendered {
            line-height: 38px;
            padding-left: 12px;
        }
        
        .select2-container--default .select2-selection--single .select2-selection__arrow {
            height: 36px;
        }
        
        /* PO Details styling */
        .po-details-card {
            background-color: #f8f9fa;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
        }
        
        .detail-label {
            font-size: 12px;
            color: #6c757d;
            margin-bottom: 4px;
        }
        
        .detail-value {
            font-size: 16px;
            font-weight: 600;
            color: #212529;
        }
        
        /* Items table styling */
        .items-table {
            font-size: 13px;
        }
        
        .items-table th {
            background-color: #e9ecef;
            font-weight: 600;
        }
        
        /* New PO items section */
        .po-items-section {
            margin-top: 20px;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 15px;
            background-color: #f8f9fa;
        }
        
        .po-item-row {
            display: flex;
            gap: 10px;
            align-items: center;
            margin-bottom: 10px;
            padding: 10px;
            background-color: white;
            border-radius: 4px;
            border: 1px solid #dee2e6;
            flex-wrap: wrap;
        }
        
        .po-item-row .item-number {
            flex: 0 0 40px;
            font-weight: 600;
            color: #0d6efd;
            text-align: center;
            font-size: 14px;
        }
        
        .po-item-row .item-select {
            flex: 2.5;
            min-width: 200px;
        }
        
        .po-item-row .unit-select {
            flex: 1.5;
            min-width: 120px;
        }
        
        .po-item-row .quantity-container {
            flex: 1.5;
            display: flex;
            align-items: center;
            gap: 5px;
            min-width: 120px;
        }
        
        .po-item-row .quantity-container .item-quantity {
            width: 70px;
            text-align: center;
            padding: 6px 4px;
            -moz-appearance: textfield;
        }
        
        .po-item-row .quantity-container .item-quantity::-webkit-outer-spin-button,
        .po-item-row .quantity-container .item-quantity::-webkit-inner-spin-button {
            -webkit-appearance: none;
            margin: 0;
        }
        
        .po-item-row .quantity-container .quantity-btn {
            width: 32px;
            height: 32px;
            padding: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 16px;
            border: 1px solid #ced4da;
            background-color: #f8f9fa;
            cursor: pointer;
        }
        
        .po-item-row .quantity-container .quantity-btn:hover {
            background-color: #e9ecef;
        }
        
        .po-item-row .price-container {
            flex: 1.5;
            min-width: 120px;
        }
        
        .po-item-row .price-container .item-price {
            width: 100%;
            padding: 6px 8px;
            border: 1px solid #ced4da;
            border-radius: 4px;
            text-align: right;
            font-weight: 600;
        }
        
        /* Discount container styles */
        .po-item-row .discount-container {
            flex: 1.5;
            display: flex;
            gap: 5px;
            align-items: center;
            min-width: 140px;
        }
        
        .po-item-row .discount-container select,
        .po-item-row .discount-container input {
            padding: 6px 8px;
            border: 1px solid #ced4da;
            border-radius: 4px;
        }
        
        .po-item-row .discount-container select {
            width: 70px;
        }
        
        .po-item-row .discount-container input {
            width: 80px;
            text-align: right;
        }
        
        .po-item-row .item-subtotal {
            flex: 1.5;
            min-width: 100px;
            padding: 8px;
            background-color: #d1e7dd;
            border-radius: 4px;
            font-weight: 600;
            color: #0a3622;
            text-align: right;
        }
        
        .remove-item-btn {
            flex: 0 0 40px;
        }
        
        /* Item count badge */
        .item-count-badge {
            display: inline-block;
            background-color: #0d6efd;
            color: white;
            border-radius: 20px;
            padding: 2px 10px;
            font-size: 12px;
            font-weight: 500;
            margin-left: 10px;
        }
        
        /* Discount section styling */
        .discount-section {
            margin-top: 15px;
            padding: 15px;
            background-color: #f8f9fa;
            border-radius: 8px;
            border: 1px solid #dee2e6;
        }
        
        .discount-header {
            display: flex;
            align-items: center;
            margin-bottom: 10px;
        }
        
        .discount-header h6 {
            margin: 0;
            font-weight: 600;
            color: #2E7D32;
        }
        
        .discount-type-select {
            width: 120px;
            margin-right: 10px;
        }
        
        .discount-input-group {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .discount-input {
            width: 150px;
            padding: 8px 12px;
            border: 1px solid #ced4da;
            border-radius: 4px;
        }
        
        .discount-apply-btn {
            padding: 8px 16px;
        }
        
        .discount-summary {
            margin-top: 10px;
            padding: 10px;
            background-color: white;
            border-radius: 4px;
            border-left: 4px solid #2E7D32;
        }
        
        .discount-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 5px;
        }
        
        .discount-label {
            font-weight: 500;
            color: #495057;
        }
        
        .discount-value {
            font-weight: 600;
        }
        
        .grand-total {
            font-size: 1.1rem;
            font-weight: 700;
            color: #2E7D32;
            border-top: 1px solid #dee2e6;
            padding-top: 8px;
            margin-top: 8px;
        }
        
        /* Text alignment utilities */
        .text-center {
            text-align: center;
        }
        
        .text-end {
            text-align: right;
        }
        
        /* Responsive adjustments */
        @media (max-width: 1600px) {
            .col-po { width: 11%; }
            .col-supplier { width: 13%; }
            .col-amount { width: 12%; }
        }
        
        @media (max-width: 1400px) {
            .col-po { width: 11%; }
            .col-supplier { width: 12%; }
            .col-amount { width: 12%; }
            .col-expected { width: 11%; }
        }
        
        @media (max-width: 1200px) {
            .po-table { table-layout: auto; }
            .table-container { overflow-x: auto; }
        }
        
        /* ===== PURCHASE ORDERS TABLE MOBILE STYLES - SIMPLIFIED ===== */
        @media (max-width: 768px) {
            #poTable {
                display: block !important;
                width: 100% !important;
            }
            
            #poTable thead {
                display: none !important;
            }
            
            #poTable tbody {
                display: block !important;
                width: 100% !important;
            }
            
            #poTable tbody tr {
                display: block !important;
                background: white !important;
                border-radius: 16px !important;
                margin-bottom: 16px !important;
                padding: 16px !important;
                box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08) !important;
                border: 1px solid #e9ecef !important;
                position: relative !important;
                width: 100% !important;
                box-sizing: border-box !important;
                cursor: pointer !important;
            }
            
            #poTable tbody tr td {
                display: none !important;
            }
            
            #poTable tbody tr td.col-po {
                display: block !important;
                margin-bottom: 6px !important;
                padding: 0 !important;
                font-weight: 600 !important;
                font-size: 14px !important;
                color: #047857 !important;
            }
            
            #poTable tbody tr td.col-supplier {
                display: block !important;
                margin-bottom: 10px !important;
                padding: 0 !important;
                font-size: 13px !important;
                color: #6c757d !important;
            }
            
            #poTable tbody tr td.col-date {
                display: inline-block !important;
                margin-right: 16px !important;
                padding: 0 !important;
                font-size: 12px !important;
                color: #6c757d !important;
            }
            
            #poTable tbody tr td.col-items {
                display: inline-block !important;
                padding: 0 !important;
                font-size: 12px !important;
                color: #6c757d !important;
            }
            
            #poTable tbody tr td.col-qty,
            #poTable tbody tr td.col-amount,
            #poTable tbody tr td.col-status,
            #poTable tbody tr td.col-expected,
            #poTable tbody tr td.col-branch,
            #poTable tbody tr td.col-actions {
                display: none !important;
            }
            
            #poTable tbody tr::after {
                content: "tap to view" !important;
                position: absolute !important;
                bottom: 12px !important;
                right: 12px !important;
                font-size: 9px !important;
                color: #9ca3af !important;
                background: transparent !important;
                padding: 0 !important;
                pointer-events: none !important;
            }
        }

        /* RECEIVE INVENTORY STYLES */
        .receive-inventory-section {
            width: 100%;
        }

        .receive-inventory-card {
            background-color: white;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            padding: 24px;
        }

        .receive-inventory-header {
            margin-bottom: 24px;
            border-bottom: 2px solid #f0f0f0;
            padding-bottom: 16px;
        }

        .receive-inventory-header h4 {
            margin-bottom: 8px;
            color: #212529;
            font-weight: 600;
        }

        .receive-tabs {
            border-bottom: 2px solid #e9ecef;
            margin-bottom: 20px;
        }

        .receive-tabs .nav-link {
            color: #6c757d;
            border: none;
            padding: 12px 16px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            border-bottom: 3px solid transparent;
        }

        .receive-tabs .nav-link:hover {
            color: #059669;
            border-bottom-color: #059669;
        }

        .receive-tabs .nav-link.active {
            color: #059669;
            border-bottom-color: #059669;
            background-color: transparent;
        }

        .receive-tab-content {
            padding: 20px 0;
        }

        .form-section {
            margin-bottom: 24px;
            padding-bottom: 20px;
            border-bottom: 1px solid #f0f0f0;
        }

        .form-section:last-of-type {
            border-bottom: none;
        }

        .form-section-title {
            color: #212529;
            font-weight: 600;
            margin-bottom: 16px;
            font-size: 0.95rem;
        }

        .receive-form label {
            font-weight: 500;
            color: #495057;
            margin-bottom: 8px;
        }

        .receive-form .form-control,
        .receive-form .form-select {
            border: 1px solid #dee2e6;
            border-radius: 6px;
            padding: 10px 12px;
            font-size: 14px;
        }

        .receive-form .form-control:focus,
        .receive-form .form-select:focus {
            border-color: #059669;
            box-shadow: 0 0 0 0.2rem rgba(5, 150, 105, 0.25);
        }

        .attachment-upload {
            padding: 20px;
            border: 2px dashed #dee2e6;
            border-radius: 6px;
            background-color: #f8f9fa;
            transition: all 0.3s ease;
        }

        .attachment-upload:hover {
            border-color: #059669;
            background-color: #f0f9f7;
        }

        .attachment-upload input[type="file"] {
            cursor: pointer;
        }

        .receive-table-section {
            margin-top: 30px;
        }

        .table-container-receive {
            background-color: white;
            border: 1px solid #dee2e6;
            border-radius: 6px;
            overflow-x: auto;
        }

        .receive-items-table {
            margin-bottom: 0;
            width: 100%;
        }

        .receive-items-table thead th {
            background-color: #f8f9fa;
            font-weight: 600;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #495057;
            padding: 16px 12px;
            border-bottom: 2px solid #dee2e6;
            white-space: nowrap;
        }

        .receive-items-table tbody td {
            padding: 14px 12px;
            border-bottom: 1px solid #e9ecef;
            font-size: 13px;
        }

        .receive-items-table tbody tr:hover {
            background-color: #f8f9fa;
        }

        .receive-form-controls {
            display: flex;
            gap: 12px;
            justify-content: flex-end;
        }

        .receive-form-controls .btn {
            padding: 10px 20px;
            font-size: 14px;
            font-weight: 500;
            border-radius: 6px;
            transition: all 0.3s ease;
        }

        .receive-form-controls .btn-primary {
            background: linear-gradient(135deg, #059669, #047857) !important;
            border: none !important;
            color: white;
        }

        .receive-form-controls .btn-primary:hover {
            background: linear-gradient(135deg, #047857, #065f46) !important;
            transform: translateY(-2px);
            box-shadow: 0 8px 18px rgba(5, 150, 105, 0.35);
        }

        .receive-form-controls .btn-secondary {
            background-color: #e9ecef;
            border: none;
            color: #495057;
        }

        .receive-form-controls .btn-secondary:hover {
            background-color: #dee2e6;
        }

        .add-item-section {
            background-color: #f8f9fa;
            border: 1px solid #e9ecef;
            border-radius: 6px;
            padding: 20px;
        }

        .add-item-section .form-label {
            font-weight: 500;
            color: #495057;
            margin-bottom: 8px;
            font-size: 13px;
        }

        .add-item-section .form-control,
        .add-item-section .form-select {
            border: 1px solid #dee2e6;
            border-radius: 6px;
            padding: 10px 12px;
            font-size: 13px;
            height: 40px;
        }

        .add-item-section .form-control:focus,
        .add-item-section .form-select:focus {
            border-color: #059669;
            box-shadow: 0 0 0 0.2rem rgba(5, 150, 105, 0.25);
        }

        .add-item-section .btn {
            height: 40px;
            padding: 8px 16px;
            font-weight: 500;
            border-radius: 6px;
            transition: all 0.3s ease;
        }

        .add-item-section .btn-success {
            background-color: #059669;
            border-color: #059669;
            color: white;
        }

        .add-item-section .btn-success:hover {
            background-color: #047857;
            border-color: #047857;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(5, 150, 105, 0.3);
        }


        .receive-history-filters {
            background: linear-gradient(135deg, #ffffff 0%, #f8fffc 100%);
            border: 1px solid rgba(5, 150, 105, 0.16);
            border-radius: 14px;
            padding: 16px;
            box-shadow: 0 6px 18px rgba(15, 23, 42, 0.06);
        }

        .receive-history-filters .form-label {
            color: #344054;
            font-weight: 600;
            letter-spacing: 0.2px;
        }

        .receive-history-filters .input-group {
            border-radius: 12px;
            box-shadow: 0 3px 10px rgba(15, 23, 42, 0.05);
        }

        .receive-history-filters .input-group-text {
            background: #f0fdf4;
            border: 1px solid #d1fae5;
            border-right: 0;
            color: #059669;
            border-radius: 12px 0 0 12px;
            padding-left: 13px;
            padding-right: 11px;
        }

        .receive-history-filters .form-control,
        .receive-history-filters .form-select {
            border: 1px solid #d1d5db;
            border-radius: 12px;
            color: #344054;
            background-color: #ffffff;
            min-height: 38px;
            box-shadow: 0 3px 10px rgba(15, 23, 42, 0.04);
            transition: all 0.2s ease;
        }

        .receive-history-filters .input-group .form-control {
            border-left: 0;
            border-radius: 0 12px 12px 0;
            box-shadow: none;
        }

        .receive-history-filters .form-control:focus,
        .receive-history-filters .form-select:focus {
            border-color: #059669;
            box-shadow: 0 0 0 0.2rem rgba(5, 150, 105, 0.14), 0 6px 14px rgba(15, 23, 42, 0.08);
        }

        .receive-history-filters .input-group:focus-within .input-group-text {
            border-color: #059669;
            background: #ecfdf5;
            color: #047857;
        }

        .receive-history-filters .input-group:focus-within .form-control {
            border-color: #059669;
            box-shadow: none;
        }

        .receive-history-filters #receiveHistoryFilterCount {
            background: #f0fdf4;
            color: #047857 !important;
            border: 1px solid #bbf7d0;
            border-radius: 999px;
            padding: 7px 12px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
        }

        .receive-history-filters #clearReceiveHistoryFiltersBtn {
            border-radius: 999px;
            padding: 7px 14px;
            font-weight: 600;
            border-color: #d1d5db;
            background: #ffffff;
            color: #475467;
            box-shadow: 0 3px 10px rgba(15, 23, 42, 0.05);
            transition: all 0.2s ease;
        }

        .receive-history-filters #clearReceiveHistoryFiltersBtn:hover {
            border-color: #059669;
            background: #ecfdf5;
            color: #047857;
            transform: translateY(-1px);
        }

        .receive-history-empty-row {
            display: none;
        }

        @media (max-width: 768px) {
            .receive-history-filters {
                padding: 14px;
                border-radius: 12px;
            }

            .receive-history-filters #receiveHistoryFilterCount,
            .receive-history-filters #clearReceiveHistoryFiltersBtn {
                width: 100%;
                justify-content: center;
            }
        }

        @media (max-width: 768px) {
            .receive-inventory-card {
                padding: 16px;
            }

            .receive-tabs .nav-link {
                padding: 10px 12px;
                font-size: 14px;
            }

            .receive-form-controls {
                flex-direction: column;
                gap: 8px;
            }

            .receive-form-controls .btn {
                width: 100%;
            }
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

        .plain-report-table {
            width: 100%;
            border-collapse: collapse;
            color: #111;
            font-size: 11px;
        }

        .plain-report-table th,
        .plain-report-table td {
            border: 1px solid #333;
            padding: 6px 7px;
            background: #fff;
            color: #111;
        }

        .plain-report-table th {
            font-weight: 700;
            text-align: left;
        }

        .print-only-area {
            display: none;
        }

        @media print {
            body * {
                visibility: hidden !important;
            }
            #receiveHistoryPrintable,
            #receiveHistoryPrintable * {
                visibility: visible !important;
            }
            #receiveHistoryPrintable {
                display: block !important;
                position: fixed;
                left: 0;
                top: 0;
                width: 100%;
                min-height: 100%;
                padding: 12px;
                background: #fff !important;
                color: #111 !important;
                font-family: Arial, sans-serif;
                z-index: 99999;
                overflow: visible !important;
            }
            .table-container-receive {
                overflow: visible !important;
            }
            .no-print,
            .modal-backdrop,
            .modal-footer,
            .btn-close {
                display: none !important;
            }
            .plain-report-header h4 {
                font-size: 16px !important;
            }
            .plain-report-meta {
                font-size: 11px !important;
            }
            .plain-report-table {
                font-size: 10px !important;
            }
            .plain-report-table th,
            .plain-report-table td {
                border: 1px solid #333 !important;
                background: #fff !important;
                color: #111 !important;
                padding: 5px !important;
            }
        }


        /* Hide default browser up/down spinner on number inputs while keeping the existing UI. */
        input[type="number"]::-webkit-outer-spin-button,
        input[type="number"]::-webkit-inner-spin-button {
            -webkit-appearance: none;
            margin: 0;
        }

        input[type="number"] {
            -moz-appearance: textfield;
            appearance: textfield;
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
    <!-- MAIN APPLICATION -->
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
                <a class="nav-link" href="branchdashboard.php">
                <i class="bi bi-speedometer2"></i>
                <span class="nav-text">Dashboard</span></a>
            </li>
                <!-- Warehouse Dropdown - walang dropdown-toggle class -->
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

                
                <!-- Supplier Dropdown - walang dropdown-toggle class -->
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

<li class="nav-item dropdown-nav">
                            <a class="nav-link" href="#" onclick="toggleSidebarDropdown(event, 'customerMenu')">
                                <i class="bi bi-people"></i><span class="nav-text">Customer</span><i class="bi bi-chevron-down dropdown-arrow"></i>
                            </a>
                            <div class="collapse" id="customerMenu">
                                <ul class="nav flex-column ps-4">
                                    <li class="nav-item"><a class="nav-link" href="customer_list.php"><i class="bi bi-person-badge"></i><span class="nav-text">Customer List</span></a></li>
                                    <li class="nav-item"><a class="nav-link" href="approve_credit_requests.php"><i class="bi bi-pencil-square"></i><span class="nav-text">Approve Credit Request</span></a></li>
                                    <li class="nav-item"><a class="nav-link" href="sales_order.php"><i class="bi bi-cart"></i><span class="nav-text">Sales Order</span></a></li>
                                    <li class="nav-item"><a class="nav-link active" href="collections.php"><i class="bi bi-cash-stack"></i><span class="nav-text">Collections</span></a></li>
                                </ul>
                            </div>
                        </li>

<!-- Delivery Dropdown - walang dropdown-toggle class -->
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
            <div class="user-avatar-sidebar"><?php echo $user_initials; ?></div>
            <div class="user-details-sidebar">
                <span class="user-name-sidebar"><?php echo htmlspecialchars($user_name); ?></span>
                <span class="user-role-sidebar"><?php echo ucfirst($user_role); ?></span>
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
            <!-- PURCHASE ORDERS CONTENT -->
            <div id="dashboardContent" class="page-content active">

                <!-- Alerts for missing columns -->
                <?php if (!$po_branch_column_exists): ?>
                    <div class="alert alert-info alert-dismissible fade show" role="alert">
                        <i class="bi bi-info-circle"></i> 
                        <strong>Branch filtering for purchase orders not yet set up.</strong> Please run this SQL in phpMyAdmin to enable branch-specific PO data:
                        <br><br>
                        <code>ALTER TABLE purchase_orders ADD COLUMN branch_id INT NULL;</code>
                        <br>
                        <code>ALTER TABLE purchase_orders ADD FOREIGN KEY (branch_id) REFERENCES branches(branch_id);</code>
                        <br><br>
                        <button type="button" class="btn btn-sm btn-primary" onclick="copySQL('purchase_orders')">
                            <i class="bi bi-files"></i> Copy SQL
                        </button>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if (!$items_branch_column_exists): ?>
                    <div class="alert alert-info alert-dismissible fade show" role="alert">
                        <i class="bi bi-info-circle"></i> 
                        <strong>Branch filtering for items not yet set up.</strong> Please run this SQL in phpMyAdmin to enable branch-specific item data:
                        <br><br>
                        <code>ALTER TABLE items ADD COLUMN branch_id INT NULL;</code>
                        <br>
                        <code>ALTER TABLE items ADD FOREIGN KEY (branch_id) REFERENCES branches(branch_id);</code>
                        <br><br>
                        <button type="button" class="btn btn-sm btn-primary" onclick="copySQL('items')">
                            <i class="bi bi-files"></i> Copy SQL
                        </button>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if (!$supplier_id_column_exists): ?>
                    <div class="alert alert-info alert-dismissible fade show" role="alert">
                        <i class="bi bi-info-circle"></i> 
                        <strong>Supplier ID column not yet set up.</strong> To enable supplier tracking, run this SQL:
                        <br><br>
                        <code>ALTER TABLE purchase_orders ADD COLUMN supplier_id INT NULL;</code>
                        <br>
                        <code>ALTER TABLE purchase_orders ADD FOREIGN KEY (supplier_id) REFERENCES suppliers(supplier_id);</code>
                        <br><br>
                        <button type="button" class="btn btn-sm btn-primary" onclick="copySQL('supplier_id')">
                            <i class="bi bi-files"></i> Copy SQL
                        </button>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if (!$discount_type_column_exists || !$discount_value_column_exists): ?>
                    <div class="alert alert-info alert-dismissible fade show" role="alert">
                        <i class="bi bi-info-circle"></i> 
                        <strong>Discount columns for purchase order items not yet set up.</strong> To enable per-item discounts, run this SQL:
                        <br><br>
                        <code>ALTER TABLE purchase_order_items ADD COLUMN discount_type ENUM('percentage','fixed') NULL;</code>
                        <br>
                        <code>ALTER TABLE purchase_order_items ADD COLUMN discount_value DECIMAL(10,2) DEFAULT 0.00;</code>
                        <br><br>
                        <button type="button" class="btn btn-sm btn-primary" onclick="copySQL('discount_columns')">
                            <i class="bi bi-files"></i> Copy SQL
                        </button>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>


                <!-- RECEIVE INVENTORY SECTION -->
                <div class="receive-inventory-section mb-5">
                    <div class="receive-inventory-card">
                        <div class="receive-inventory-header d-flex justify-content-between align-items-start flex-wrap gap-3">
                            <div>
                                <h4><i class="bi bi-inbox-fill"></i> Receive Inventory</h4>
                                <p class="text-muted mb-0">Add received items from supplier or production</p>
                            </div>
                            <div class="action-button-wrapper mb-0">
                                <button class="btn-primary" onclick="showNewPOModal()">
                                    <i class="bi bi-plus-circle me-1"></i> New PO
                                </button>
                            </div>
                        </div>

                        <!-- TAB NAVIGATION -->
                        <ul class="nav nav-tabs receive-tabs" role="tablist">
                            <li class="nav-item" role="presentation">
                                <button class="nav-link active" id="supplierTab" data-bs-toggle="tab" data-bs-target="#supplierContent" type="button" role="tab" aria-controls="supplierContent" aria-selected="true">
                                    <i class="bi bi-building"></i> Supplier
                                </button>
                            </li>

                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="rmrTab" data-bs-toggle="tab" data-bs-target="#rmrContent" type="button" role="tab" aria-controls="rmrContent" aria-selected="false">Returned Merchandise
                                </button>
                            </li>
                        </ul>

                        <!-- TAB CONTENT -->
                        <div class="tab-content receive-tab-content">
                            <!-- SUPPLIER TAB -->
                            <div class="tab-pane fade show active" id="supplierContent" role="tabpanel" aria-labelledby="supplierTab">
                                <form id="supplierReceiveForm" class="receive-form">
                                    <div class="form-section">
                                        <h6 class="form-section-title">Supplier Information</h6>
                                        <div class="row g-3">
                                            <div class="col-md-6">
                                                <label for="supplierDropdown" class="form-label">Supplier *</label>
                                                <select class="form-select" id="supplierDropdown" required>
                                                    <option value="">-- Select Supplier --</option>
                                                    <?php foreach ($suppliers_list as $supplier): ?>
                                                        <option value="<?= (int)$supplier['supplier_id'] ?>" data-name="<?= htmlspecialchars($supplier['supplier_name']) ?>" data-id="<?= (int)$supplier['supplier_id'] ?>">
                                                            <?= htmlspecialchars($supplier['supplier_name']) ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-md-6">
                                                <label for="poNumberDropdown" class="form-label">PO Number *</label>
                                                <select class="form-select" id="poNumberDropdown" required>
                                                    <option value="">-- Select PO --</option>
                                                </select>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="form-check form-switch">
                                                    <input class="form-check-input" type="checkbox" id="withPOCheckbox" checked>
                                                    <label class="form-check-label" for="withPOCheckbox">
                                                        With PO?
                                                    </label>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <label for="supplierReceiveDate" class="form-label">Receive Date *</label>
                                                <input type="date" class="form-control" id="supplierReceiveDate" required>
                                            </div>
                                            <div class="col-md-6">
                                                <label for="supplierEncodedDate" class="form-label">Date Encoded *</label>
                                                <input type="date" class="form-control" id="supplierEncodedDate" required>
                                            </div>
                                            <div class="col-md-6">
                                                <label for="itemSelector" class="form-label">Item Name</label>
                                                <select class="form-select" id="itemSelector">
                                                    <option value="">-- Select Item --</option>
                                                    <?php foreach ($items_list as $item): ?>
                                                        <option value="<?= (int)$item['item_id'] ?>"><?= htmlspecialchars($item['item_name']) ?></option>
                                                    <?php endforeach; ?>
                                                    <option value="__new__">+ Add New Item</option>
                                                </select>
                                            </div>
                                            <div class="col-md-6">
                                                <label for="itemQty" class="form-label">Qty</label>
                                                <input type="number" class="form-control" id="itemQty" placeholder="0" min="0" step="0.01">
                                            </div>
                                            <div class="col-md-3" id="newItemNameWrapper" style="display:none;">
                                                <label for="newItemName" class="form-label">New Item Name</label>
                                                <input type="text" class="form-control" id="newItemName" placeholder="Enter item name">
                                            </div>
                                            <div class="col-md-3">
                                                <label for="itemCode" class="form-label">Item Code</label>
                                                <input type="text" class="form-control" id="itemCode" placeholder="Item code">
                                            </div>
                                            <div class="col-md-3">
                                                <label for="itemDescription" class="form-label">Item Description</label>
                                                <input type="text" class="form-control" id="itemDescription" placeholder="Item description">
                                            </div>
                                            <div class="col-md-3">
                                                <label for="itemCategory" class="form-label">Category</label>
                                                <input type="text" class="form-control" id="itemCategory" placeholder="Category">
                                            </div>
                                            <div class="col-md-3" id="itemUomSelectWrapper" style="display:none;">
                                                <label for="itemUoM" class="form-label">UoM</label>
                                                <select class="form-select" id="itemUoM">
                                                    <option value="">-- Select UoM --</option>
                                                </select>
                                            </div>
                                            <div class="col-md-3" id="newItemUomWrapper" style="display:none;">
                                                <label for="newItemUom" class="form-label">UoM</label>
                                                <input type="text" class="form-control" id="newItemUom" placeholder="UoM">
                                            </div>
                                            <div class="col-md-3">
                                                <label for="itemPrice" class="form-label">Unit Cost</label>
                                                <input type="number" class="form-control" id="itemPrice" placeholder="0.00" min="0" step="0.01">
                                            </div>
                                            <div class="col-md-9">
                                                <div id="selectedItemPreview" class="alert alert-light border mb-0" style="display:none;"></div>
                                            </div>
                                            <div class="col-md-12 d-flex justify-content-start">
                                                <button type="button" class="btn btn-success" id="addReceiveItemBtn">
                                                    <i class="bi bi-plus-circle"></i> Add to Table
                                                </button>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="form-section">
                                        <h6 class="form-section-title">Attachments</h6>
                                        <div class="attachment-upload">
                                            <label for="supplierAttachments" class="form-label">Upload Files</label>
                                            <input type="file" class="form-control" id="supplierAttachments" multiple accept=".pdf,.jpg,.jpeg,.png,.doc,.docx">
                                            <small class="text-muted d-block mt-2">Accepted formats: PDF, JPG, PNG, DOC, DOCX</small>
                                        </div>
                                    </div>
                                </form>
                            </div>

                            <!-- PRODUCTION TAB -->
                            <div class="tab-pane fade" id="productionContent" role="tabpanel" aria-labelledby="productionTab">
                                <form id="productionReceiveForm" class="receive-form">
                                    <div class="form-section">
                                        <h6 class="form-section-title">Production Information</h6>
                                        <div class="row g-3">
                                            <div class="col-md-6">
                                                <label for="productionReceiveDate" class="form-label">Receive Date *</label>
                                                <input type="date" class="form-control" id="productionReceiveDate" required>
                                            </div>
                                            <div class="col-md-6">
                                                <label for="productionEncodedDate" class="form-label">Date Encoded *</label>
                                                <input type="date" class="form-control" id="productionEncodedDate" required>
                                            </div>
                                            <div class="col-md-6">
                                                <input type="hidden" id="productionTotalItems" value="">
                                            </div>
                                            <div class="col-md-6">
                                                <label for="productionItemSelector" class="form-label">Item Name</label>
                                                <select class="form-select" id="productionItemSelector">
                                                    <option value="">-- Select Item --</option>
                                                    <?php foreach ($items_list as $item): ?>
                                                        <option value="<?= (int)$item['item_id'] ?>"><?= htmlspecialchars($item['item_name']) ?></option>
                                                    <?php endforeach; ?>
                                                    <option value="__new__">+ Add New Item</option>
                                                </select>
                                            </div>
                                            <div class="col-md-6">
                                                <label for="productionItemQty" class="form-label">Qty</label>
                                                <input type="number" class="form-control" id="productionItemQty" placeholder="0" min="0" step="0.01">
                                            </div>
                                            <div class="col-md-3" id="productionNewItemNameWrapper" style="display:none;">
                                                <label for="productionNewItemName" class="form-label">New Item Name</label>
                                                <input type="text" class="form-control" id="productionNewItemName" placeholder="Enter item name">
                                            </div>
                                            <div class="col-md-3">
                                                <label for="productionItemCode" class="form-label">Item Code</label>
                                                <input type="text" class="form-control" id="productionItemCode" placeholder="Item code">
                                            </div>
                                            <div class="col-md-3">
                                                <label for="productionItemDescription" class="form-label">Item Description</label>
                                                <input type="text" class="form-control" id="productionItemDescription" placeholder="Item description">
                                            </div>
                                            <div class="col-md-3">
                                                <label for="productionItemCategory" class="form-label">Category</label>
                                                <input type="text" class="form-control" id="productionItemCategory" placeholder="Category">
                                            </div>
                                            <div class="col-md-3" id="productionItemUomSelectWrapper" style="display:none;">
                                                <label for="productionItemUoM" class="form-label">UoM</label>
                                                <select class="form-select" id="productionItemUoM">
                                                    <option value="">-- Select UoM --</option>
                                                </select>
                                            </div>
                                            <div class="col-md-3" id="productionNewItemUomWrapper" style="display:none;">
                                                <label for="productionNewItemUom" class="form-label">UoM</label>
                                                <input type="text" class="form-control" id="productionNewItemUom" placeholder="UoM">
                                            </div>
                                            <div class="col-md-3">
                                                <label for="productionItemPrice" class="form-label">Unit Cost</label>
                                                <input type="number" class="form-control" id="productionItemPrice" placeholder="0.00" min="0" step="0.01">
                                            </div>
                                            <div class="col-md-9">
                                                <div id="productionSelectedItemPreview" class="alert alert-light border mb-0" style="display:none;"></div>
                                            </div>
                                            <div class="col-md-12 d-flex justify-content-start">
                                                <button type="button" class="btn btn-success" id="addProductionReceiveItemBtn">
                                                    <i class="bi bi-plus-circle"></i> Add to Table
                                                </button>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="form-section">
                                        <h6 class="form-section-title">Attachments</h6>
                                        <div class="attachment-upload">
                                            <label for="productionAttachments" class="form-label">Upload Files</label>
                                            <input type="file" class="form-control" id="productionAttachments" multiple accept=".pdf,.jpg,.jpeg,.png,.doc,.docx">
                                            <small class="text-muted d-block mt-2">Accepted formats: PDF, JPG, PNG, DOC, DOCX</small>
                                        </div>
                                    </div>
                                </form>
                            </div>

                            <!-- RETURNED MERCHANDISE TAB -->
                            <div class="tab-pane fade" id="rmrContent" role="tabpanel" aria-labelledby="rmrTab">
                                <form id="rmrReceiveForm" class="receive-form">
                                    <div class="form-section">
                                        <h6 class="form-section-title">Returned Merchandise Information</h6>
                                        <div class="row g-3">
                                            <div class="col-md-8">
                                                <label for="rmrRequestDropdown" class="form-label">Confirmed / Processed RMR Request *</label>
                                                <select class="form-select" id="rmrRequestDropdown" required>
                                                    <option value="">-- Select RMR Request --</option>
                                                    <?php foreach ($rmr_requests_list as $rmr): ?>
                                                        <option
                                                            value="<?= (int)$rmr['rmr_id'] ?>"
                                                            data-item-id="<?= (int)$rmr['item_id'] ?>"
                                                            data-item-name="<?= htmlspecialchars($rmr['item_name'] ?? '') ?>"
                                                            data-item-code="<?= htmlspecialchars($rmr['item_code'] ?? '') ?>"
                                                            data-item-description="<?= htmlspecialchars($rmr['item_description'] ?? ($rmr['item_name'] ?? '')) ?>"
                                                            data-qty="<?= htmlspecialchars((string)($rmr['quantity'] ?? 0)) ?>"
                                                            data-uom="<?= htmlspecialchars($rmr['unit_type'] ?? '') ?>"
                                                            data-price="<?= htmlspecialchars((string)($rmr['unit_price'] ?? 0)) ?>"
                                                            data-status="<?= htmlspecialchars($rmr['status'] ?? '') ?>"
                                                            data-customer="<?= htmlspecialchars($rmr['customer_name'] ?? '') ?>"
                                                            data-reason="<?= htmlspecialchars($rmr['reason'] ?? '') ?>">
                                                            <?= htmlspecialchars(($rmr['rmr_number'] ?? ('RMR-' . $rmr['rmr_id'])) . ' - ' . (($rmr['customer_name'] ?? '') !== '' ? ($rmr['customer_name'] . ' - ') : '') . ($rmr['item_name'] ?? 'Item') . ' - Qty: ' . ($rmr['quantity'] ?? 0) . ' ' . ($rmr['unit_type'] ?? '')) ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <small class="text-muted d-block mt-2">Only confirmed or processed RMR requests that are not yet returned to inventory are shown here.</small>
                                            </div>
                                            <div class="col-md-4">
                                                <label for="rmrReceiveDate" class="form-label">Receive Date *</label>
                                                <input type="date" class="form-control" id="rmrReceiveDate" required>
                                            </div>
                                            <div class="col-md-6">
                                                <label for="rmrEncodedDate" class="form-label">Date Encoded *</label>
                                                <input type="date" class="form-control" id="rmrEncodedDate" required>
                                            </div>
                                            <div class="col-12">
                                                <div id="rmrRequestPreview" class="alert alert-light border mb-0" style="display:none;"></div>
                                            </div>
                                            <div class="col-md-12 d-flex justify-content-start">
                                                <button type="button" class="btn btn-success" id="addRmrReceiveItemBtn">
                                                    <i class="bi bi-plus-circle"></i> Add RMR Item to Table
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <!-- ITEM TABLE -->
                        <div class="receive-table-section mt-4">
                            <h6 class="form-section-title">Received Items</h6>
                            <div class="table-container-receive">
                                <table class="table receive-items-table" id="receiveItemsTable">
                                    <thead>
                                        <tr>
                                            <th>Item Name</th>
                                            <th>Item Code</th>
                                            <th>Item Description</th>
                                            <th>Category</th>
                                            <th>Qty</th>
                                            <th>UoM</th>
                                            <th>Unit Cost</th>
                                            <th>Total Price</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody id="receiveItemsTableBody">
                                        <tr class="text-center text-muted">
                                            <td colspan="9">No items added yet</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- MEMO AND FORM CONTROLS -->
                        <div class="receive-form-controls mt-4 align-items-end flex-wrap gap-3">
                            <div class="flex-grow-1" style="min-width: 280px; max-width: 650px;">
                                <label for="receiveMemo" class="form-label mb-1">Memo</label>
                                <textarea class="form-control" id="receiveMemo" rows="3" placeholder="Enter memo / receipt notes...(Optional)" style="resize: vertical;"></textarea>
                                </div>
                            <div class="d-flex gap-2 ms-auto">
                                <button type="button" class="btn btn-secondary" onclick="clearReceiveForm()">
                                    <i class="bi bi-arrow-clockwise me-1"></i> Clear
                                </button>
                                <button type="button" class="btn btn-primary" id="submitReceiveBtn" onclick="submitReceiveInventory()">
                                    <i class="bi bi-check-circle me-1"></i> Submit Receipt
                                </button>
                            </div>
                        </div>
                    </div>


                <div class="receive-table-section mb-5">
                    <div class="receive-inventory-card">
                        <div class="receive-inventory-header">
                            <div class="d-flex align-items-center gap-2 flex-wrap w-100">
                                <div>
                                    <h4 class="mb-1"><i class="bi bi-clock-history"></i> Receive History</h4>
                                    <p class="text-muted mb-0">Click any row to view full receive details</p>
                                </div>
                                <div class="ms-auto text-end">
                                    <button type="button" class="btn btn-sm btn-primary no-print" id="printReceiveHistoryBtn">
                                        <i class="bi bi-printer"></i> Print
                                    </button>
                                </div>
                            </div>
                        </div>
                        <div class="receive-history-filters no-print mb-3">
                            <div class="row g-2 align-items-end">
                                <div class="col-12 col-lg-4">
                                    <label for="receiveHistorySearch" class="form-label small mb-1">Search</label>
                                    <div class="input-group input-group-sm">
                                        <span class="input-group-text"><i class="bi bi-search"></i></span>
                                        <input type="text" class="form-control" id="receiveHistorySearch" placeholder="Search item, source, UoM, or encoder">
                                    </div>
                                </div>
                                <div class="col-6 col-lg-2">
                                    <label for="receiveHistorySourceFilter" class="form-label small mb-1">Source</label>
                                    <select class="form-select form-select-sm" id="receiveHistorySourceFilter">
                                        <option value="all">All Sources</option>
                                        <option value="purchase_order">Supplier / PO</option>
                                        <option value="production">Production</option>
                                        <option value="rmr">RMR</option>
                                    </select>
                                </div>
                                <div class="col-6 col-lg-2">
                                    <label for="receiveHistoryEncoderFilter" class="form-label small mb-1">Encoded By</label>
                                    <select class="form-select form-select-sm" id="receiveHistoryEncoderFilter">
                                        <option value="all">All Encoders</option>
                                        <?php
                                            $receive_history_encoders = [];
                                            foreach ($receive_history_list as $encoderRow) {
                                                $encoderName = trim($encoderRow['received_by_name'] ?? '') !== '' ? trim($encoderRow['received_by_name'] ?? '') : 'Unknown';
                                                $receive_history_encoders[$encoderName] = $encoderName;
                                            }
                                            ksort($receive_history_encoders);
                                            foreach ($receive_history_encoders as $encoderName):
                                        ?>
                                            <option value="<?= htmlspecialchars($encoderName, ENT_QUOTES) ?>"><?= htmlspecialchars($encoderName) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-6 col-lg-2">
                                    <label for="receiveHistoryDateFrom" class="form-label small mb-1">Date From</label>
                                    <input type="date" class="form-control form-control-sm" id="receiveHistoryDateFrom">
                                </div>
                                <div class="col-6 col-lg-2">
                                    <label for="receiveHistoryDateTo" class="form-label small mb-1">Date To</label>
                                    <input type="date" class="form-control form-control-sm" id="receiveHistoryDateTo">
                                </div>
                                <div class="col-12 d-flex justify-content-between align-items-center flex-wrap gap-2 mt-2">
                                    <small class="text-muted" id="receiveHistoryFilterCount">Showing <?= count($receive_history_list) ?> record(s)</small>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" id="clearReceiveHistoryFiltersBtn">
                                        <i class="bi bi-x-circle me-1"></i> Clear Filters
                                    </button>
                                </div>
                            </div>
                        </div>
                        <div class="table-container-receive">
                            <table class="table receive-items-table" id="receiveHistoryTable">
                                <thead>
                                    <tr>
                                        <th>Item Name</th>
                                        <th>Qty</th>
                                        <th>Unit Cost</th>
                                        <th>Total Cost</th>
                                        <th>Receive Date</th>
                                        <th>UoM</th>
                                        <th>Encoded By</th>
                                    </tr>
                                </thead>
                                <tbody id="receiveHistoryTableBody">
                                    <?php if (!empty($receive_history_list)): ?>
                                        <?php foreach ($receive_history_list as $history): ?>
                                            <?php
                                                $receiveHistoryEncoder = trim($history['received_by_name'] ?? '') !== '' ? trim($history['received_by_name'] ?? '') : 'Unknown';
                                                $receiveHistoryDateRaw = $history['receive_date'] ?? ($history['created_at'] ?? '');
                                                $receiveHistoryDateForFilter = $receiveHistoryDateRaw ? date('Y-m-d', strtotime($receiveHistoryDateRaw)) : '';
                                                $receiveHistorySourceText = 'Production';
                                                if (($history['reference_type'] ?? '') === 'purchase_order') {
                                                    $receiveHistorySourceText = !empty($history['po_number']) ? 'PO: ' . $history['po_number'] : 'Purchase Order';
                                                } elseif (($history['reference_type'] ?? '') === 'rmr') {
                                                    $receiveHistorySourceText = !empty($history['rmr_number']) ? 'RMR: ' . $history['rmr_number'] : 'RMR';
                                                }
                                                $receiveHistorySupplierCustomer = $history['supplier_name'] ?? ($history['rmr_customer_name'] ?? '');
                                                $receiveHistorySearchText = implode(' ', [
                                                    $history['item_name'] ?? '',
                                                    $history['item_code'] ?? '',
                                                    $history['quantity_changed'] ?? '',
                                                    $history['unit_cost'] ?? '',
                                                    $history['total_cost'] ?? '',
                                                    $history['unit_type'] ?? '',
                                                    $receiveHistoryEncoder,
                                                    $receiveHistorySourceText,
                                                    $receiveHistorySupplierCustomer,
                                                    $history['receive_date'] ?? '',
                                                    $history['encoded_date'] ?? ''
                                                ]);
                                            ?>
                                            <tr class="receive-history-row" data-transaction-id="<?= (int)($history['transaction_id'] ?? 0) ?>" data-item-id="<?= (int)($history['item_id'] ?? 0) ?>" data-reference-type="<?= htmlspecialchars($history['reference_type'] ?? '', ENT_QUOTES) ?>" data-reference-id="<?= (int)($history['reference_id'] ?? 0) ?>" data-created-at="<?= htmlspecialchars($history['created_at'] ?? '', ENT_QUOTES) ?>" data-receive-date="<?= htmlspecialchars($receiveHistoryDateForFilter, ENT_QUOTES) ?>" data-encoder="<?= htmlspecialchars($receiveHistoryEncoder, ENT_QUOTES) ?>" data-search="<?= htmlspecialchars(strtolower($receiveHistorySearchText), ENT_QUOTES) ?>" style="cursor:pointer;">
                                                <td><?= htmlspecialchars($history['item_name'] ?? 'Unknown Item') ?></td>
                                                <td><?= htmlspecialchars(rtrim(rtrim(number_format((float)($history['quantity_changed'] ?? 0), 2, '.', ''), '0'), '.')) ?></td>
                                                <td>₱<?= htmlspecialchars(number_format((float)($history['unit_cost'] ?? 0), 2)) ?></td>
                                                <td>₱<?= htmlspecialchars(number_format((float)($history['total_cost'] ?? 0), 2)) ?></td>
                                                <td><?= htmlspecialchars(formatDate($history['receive_date'] ?? ($history['created_at'] ?? ''))) ?></td>
                                                <td><?= htmlspecialchars($history['unit_type'] ?? '') ?></td>
                                                <td><?= htmlspecialchars($receiveHistoryEncoder) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr class="text-center text-muted receive-history-empty-row">
                                            <td colspan="7">No receive history found yet</td>
                                        </tr>
                                    <?php endif; ?>
                                    <tr class="text-center text-muted receive-history-empty-filter-row" style="display:none;">
                                        <td colspan="7">No receive history matches your filters</td>
                                    </tr>
                                </tbody>
                            </table>
                            <div id="receiveHistoryPrintable" class="print-only-area"></div>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <div class="modal fade" id="receiveHistoryModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="bi bi-eye me-2"></i>Receive History Details</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="receiveHistoryModalBody">
                    <div class="text-center text-muted py-4">Loading details...</div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-danger" id="deleteReceiveHistoryBtn" style="display:none;">
                        <i class="bi bi-trash me-1"></i> Delete Receive
                    </button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- NEW PO MODAL -->
    <div class="modal fade" id="newPOModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="bi bi-plus-circle me-2"></i>Create New Purchase Order</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="newPOForm">
                        <?php if ($po_branch_column_exists && !$view_all_branches): ?>
                            <input type="hidden" name="branch_id" value="<?= $branch_id ?>">
                            <div class="alert alert-info mb-3">
                                <i class="bi bi-info-circle me-2"></i>
                                Creating purchase order for Branch <?= $branch_id ?>
                            </div>
                        <?php endif; ?>
                        
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="supplierName" class="form-label">Supplier Name *</label>
                                <select class="form-select" id="supplierName" required style="width: 100%;">
                                    <option value="">-- Select Supplier --</option>
                                    <?php foreach ($suppliers_list as $supplier): ?>
                                        <option value="<?= htmlspecialchars($supplier['supplier_name']) ?>" 
                                                data-id="<?= $supplier['supplier_id'] ?>"
                                                data-code="<?= htmlspecialchars($supplier['supplier_code'] ?? '') ?>"
                                                data-contact="<?= htmlspecialchars($supplier['contact_person'] ?? '') ?>"
                                                data-email="<?= htmlspecialchars($supplier['email'] ?? '') ?>"
                                                data-phone="<?= htmlspecialchars($supplier['phone_number'] ?? '') ?>">
                                            <?= htmlspecialchars($supplier['supplier_name']) ?> 
                                            <?php if (!empty($supplier['supplier_code'])): ?>
                                                (<?= htmlspecialchars($supplier['supplier_code']) ?>)
                                            <?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="hidden" id="supplierId" name="supplier_id">
                            </div>
                            <div class="col-md-6">
                                <label for="poStatus" class="form-label">Status *</label>
                                <select class="form-select" id="poStatus" required>
                                    <option value="draft">Draft</option>
                                    <option value="submitted">Submit for Approval</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="orderDate" class="form-label">Order Date *</label>
                                <input type="date" class="form-control" id="orderDate" required>
                            </div>
                            <div class="col-md-6">
                                <label for="expectedDelivery" class="form-label">Expected Delivery</label>
                                <input type="date" class="form-control" id="expectedDelivery">
                                <small class="text-muted">Leave empty if not specified</small>
                            </div>
                        </div>
                        
                        <!-- Supplier Info Display (optional) -->
                        <div id="supplierInfo" class="alert alert-info mt-3" style="display: none;">
                            <i class="bi bi-info-circle me-2"></i>
                            <span id="supplierInfoText"></span>
                        </div>
                        
                        <!-- Items Section -->
                        <div class="po-items-section mt-4">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h5 class="mb-0">
                                    <i class="bi bi-box-seam me-2"></i>Order Items
                                    <span class="item-count-badge" id="itemCount">0</span>
                                </h5>
                                <button type="button" class="btn btn-sm btn-success" onclick="addItemRow()">
                                    <i class="bi bi-plus-circle"></i> Add Item
                                </button>
                            </div>
                            
                            <div id="itemsContainer">
                                <!-- Item rows will be added here dynamically -->
                            </div>
                            
                            <!-- Order Discount Section (Overall) -->
                            <div class="discount-section">
                                <div class="discount-header">
                                    <h6 class="mb-0"><i class="bi bi-tag me-2"></i>Apply Order Discount</h6>
                                </div>
                                <div class="row g-2 align-items-center">
                                    <div class="col-auto">
                                        <select class="form-select discount-type-select" id="discountType">
                                            <option value="percentage">Percentage (%)</option>
                                            <option value="fixed">Fixed Amount (₱)</option>
                                        </select>
                                    </div>
                                    <div class="col-auto">
                                        <div class="discount-input-group">
                                            <input type="number" class="form-control discount-input" id="discountValue" placeholder="Enter value" min="0" step="0.01" value="0">
                                            <button type="button" class="btn btn-primary discount-apply-btn" onclick="applyDiscount()">
                                                <i class="bi bi-check-lg"></i> Apply
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Discount Summary -->
                                <div class="discount-summary" id="discountSummary" style="display: none;">
                                    <div class="discount-row">
                                        <span class="discount-label">Subtotal (after item discounts):</span>
                                        <span class="discount-value" id="subtotalDisplay">₱0.00</span>
                                    </div>
                                    <div class="discount-row">
                                        <span class="discount-label" id="discountLabel">Discount (0%):</span>
                                        <span class="discount-value text-danger" id="discountAmountDisplay">-₱0.00</span>
                                    </div>
                                    <div class="discount-row grand-total">
                                        <span class="discount-label">Total Amount:</span>
                                        <span class="discount-value" id="grandTotalDisplay">₱0.00</span>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row mt-3">
                                <div class="col-md-6 offset-md-6">
                                    <div class="card bg-light">
                                        <div class="card-body">
                                            <div class="d-flex justify-content-between">
                                                <span class="fw-bold">Total Amount:</span>
                                                <span class="fw-bold fs-5" id="totalAmountDisplay">₱0.00</span>
                                            </div>
                                            <div class="d-flex justify-content-between mt-2 text-muted">
                                                <span>Total Items:</span>
                                                <span id="totalItemsDisplay">0</span>
                                            </div>
                                            <div class="d-flex justify-content-between text-muted">
                                                <span>Total Quantity:</span>
                                                <span id="totalQuantityDisplay">0</span>
                                            </div>
                                            <input type="hidden" id="totalAmount" name="total_amount" value="0">
                                            <input type="hidden" id="discountType_hidden" name="discount_type" value="">
                                            <input type="hidden" id="discountValue_hidden" name="discount_value" value="0">
                                            <input type="hidden" id="subtotalAmount" name="subtotal_amount" value="0">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Warning if no items available for this branch -->
                        <?php if (empty($items_list)): ?>
                            <div class="alert alert-warning mt-3">
                                <i class="bi bi-exclamation-triangle me-2"></i>
                                <strong>No items found for your branch!</strong> 
                                Please contact administrator to add items to branch <?= $branch_id ?>.
                            </div>
                        <?php endif; ?>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="createPurchaseOrder()">Create PO</button>
                </div>
            </div>
        </div>
    </div>

         <!-- VIEW PO MODAL - SAME AS PICK LIST ITEMS STYLE -->
    <div class="modal fade" id="viewPOModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-eye me-2"></i> Purchase Order Details
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div id="poDetailsContent">
                        <!-- Content will be populated by JavaScript -->
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-circle me-1"></i> Close
                    </button>
                    <button type="button" class="btn btn-warning" id="editFromViewBtn" onclick="editPOFromView()" style="display: none;">
                        <i class="bi bi-pencil me-1"></i> Edit PO
                    </button>
                    <button type="button" class="btn btn-primary" onclick="printPODetails()">
                        <i class="bi bi-printer me-1"></i> Print
                    </button>
                </div>
            </div>
        </div>
    </div>
       <!-- EDIT PO MODAL - SAME AS PICK LIST ITEMS STYLE -->
    <div class="modal fade" id="editPOModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-pencil me-2"></i> Edit Purchase Order
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="editPOForm">
                        <input type="hidden" id="editPOId">
                        <?php if ($po_branch_column_exists && !$view_all_branches): ?>
                            <input type="hidden" name="branch_id" value="<?= $branch_id ?>">
                        <?php endif; ?>
                        
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="editPONumber" class="form-label">PO Number</label>
                                <input type="text" class="form-control" id="editPONumber" readonly>
                            </div>
                            <div class="col-md-6">
                                <label for="editPOStatus" class="form-label">Status *</label>
                                <select class="form-select" id="editPOStatus" required>
                                    <option value="draft">Draft</option>
                                    <option value="submitted">Processing</option>
                                    <option value="approved">Approved</option>
                                    <option value="received">Delivered</option>
                                    <option value="cancelled">Cancelled</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="editSupplierName" class="form-label">Supplier Name *</label>
                                <select class="form-select" id="editSupplierName" required style="width: 100%;">
                                    <option value="">-- Select Supplier --</option>
                                    <?php foreach ($suppliers_list as $supplier): ?>
                                        <option value="<?= htmlspecialchars($supplier['supplier_name']) ?>" 
                                                data-id="<?= $supplier['supplier_id'] ?>">
                                            <?= htmlspecialchars($supplier['supplier_name']) ?>
                                            <?php if (!empty($supplier['supplier_code'])): ?>
                                                (<?= htmlspecialchars($supplier['supplier_code']) ?>)
                                            <?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="hidden" id="editSupplierId" name="supplier_id">
                            </div>
                            <div class="col-md-6">
                                <label for="editOrderDate" class="form-label">Order Date *</label>
                                <input type="date" class="form-control" id="editOrderDate" required>
                            </div>
                            <div class="col-md-6">
                                <label for="editExpectedDelivery" class="form-label">Expected Delivery</label>
                                <input type="date" class="form-control" id="editExpectedDelivery">
                                <small class="text-muted">Leave empty to clear date</small>
                            </div>
                            <div class="col-md-6">
                                <label for="editTotalAmount" class="form-label">Total Amount (₱) *</label>
                                <input type="number" class="form-control" id="editTotalAmount" min="0" step="0.01" required>
                            </div>
                        </div>
                        
                        <?php if ($po_branch_column_exists && $view_all_branches): ?>
                        <div class="row g-3 mt-2">
                            <div class="col-md-6">
                                <label for="editBranch" class="form-label">Branch</label>
                                <input type="text" class="form-control" id="editBranch" readonly>
                            </div>
                        </div>
                        <?php endif; ?>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-circle me-1"></i> Cancel
                    </button>
                    <button type="button" class="btn btn-primary" onclick="updatePurchaseOrder()">
                        <i class="bi bi-check-circle me-1"></i> Update PO
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- ADD ITEM MODAL (with discount) -->
    <div class="modal fade" id="addItemModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title"><i class="bi bi-plus-circle me-2"></i>Add Item to PO</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="addItemForm">
                        <input type="hidden" id="addItemPOId">
                        
                        <div class="mb-3">
                            <label for="itemSelect" class="form-label">Select Item *</label>
                            <select class="form-select" id="itemSelect" required>
                                <option value="">-- Select Item --</option>
                                <?php foreach ($items_list as $item): ?>
                                <option value="<?= $item['item_id'] ?>" 
                                        data-price-piece="<?= $item['price_piece'] ?>"
                                        data-price-case="<?= $item['price_case'] ?? 0 ?>"
                                        data-price-inner="<?= $item['price_inner_pack'] ?? 0 ?>"
                                        data-price-box="<?= $item['price_box'] ?? 0 ?>"
                                        data-price-carton="<?= $item['price_carton'] ?? 0 ?>"
                                        data-code="<?= htmlspecialchars($item['item_code']) ?>"
                                        data-stock="<?= $item['stock'] ?>">
                                    <?= htmlspecialchars($item['item_code'] . ' - ' . $item['item_name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label for="itemUnit" class="form-label">Unit *</label>
                            <select class="form-select" id="itemUnit" onchange="updateUnitPriceModal()">
                                <option value="">-- Select Unit --</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label for="itemQuantity" class="form-label">Quantity *</label>
                            <input type="number" class="form-control" id="itemQuantity" min="1" value="1" required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="itemUnitPrice" class="form-label">Unit Cost (₱) *</label>
                            <input type="number" class="form-control" id="itemUnitPrice" min="0" step="0.01" required>
                        </div>
                        
                        <!-- Item Discount -->
                        <div class="mb-3">
                            <label class="form-label">Item Discount (optional)</label>
                            <div class="row g-2">
                                <div class="col-4">
                                    <select class="form-select" id="itemDiscountType">
                                        <option value="percentage">Percentage (%)</option>
                                        <option value="fixed">Fixed (₱)</option>
                                    </select>
                                </div>
                                <div class="col-8">
                                    <input type="number" class="form-control" id="itemDiscountValue" placeholder="0" min="0" step="0.01" value="0">
                                </div>
                            </div>
                        </div>
                        
                        <div class="alert alert-info" id="itemSubtotal" style="display: none;">
                            Subtotal after discount: ₱<span id="subtotalAmount">0.00</span>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-success" onclick="addItemToPO()">Add Item</button>
                </div>
            </div>
        </div>
    </div>

    <!-- DELETE CONFIRMATION MODAL -->
    <div class="modal fade" id="deletePOModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title"><i class="bi bi-trash me-2"></i>Confirm Delete</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to delete this purchase order?</p>
                    <p class="fw-bold" id="deletePONumber"></p>
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        This action cannot be undone and will remove all associated items.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-danger" onclick="confirmDeletePO()">Delete PO</button>
                </div>
            </div>
        </div>
    </div>

    <!-- DELETE ITEM CONFIRMATION MODAL -->
    <div class="modal fade" id="deleteItemModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title"><i class="bi bi-trash me-2"></i>Confirm Delete Item</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to remove this item from the purchase order?</p>
                    <p class="fw-bold" id="deleteItemName"></p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-danger" onclick="confirmDeleteItem()">Delete Item</button>
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



     <!-- jQuery and Select2 JS -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    // ========== GLOBAL VARIABLES ==========
    let currentPOId = null;
    let currentItemId = null;
    let currentPOData = null;
    let itemCounter = 0;
    let itemsList = <?= json_encode($items_list) ?>;
    let purchaseOrdersList = <?= json_encode($purchase_orders) ?>;
    let suppliersList = <?= json_encode($suppliers_list) ?>;
    const branchId = <?php echo $branch_id; ?>;
    const viewAllBranches = <?php echo $view_all_branches ? 'true' : 'false'; ?>;
    const poBranchColumnExists = <?php echo $po_branch_column_exists ? 'true' : 'false'; ?>;
    const itemsBranchColumnExists = <?php echo $items_branch_column_exists ? 'true' : 'false'; ?>;
    const supplierIdColumnExists = <?php echo $supplier_id_column_exists ? 'true' : 'false'; ?>;
    const discountTypeColumnExists = <?php echo $discount_type_column_exists ? 'true' : 'false'; ?>;
    const discountValueColumnExists = <?php echo $discount_value_column_exists ? 'true' : 'false'; ?>;

    function formatAmount(value, minimumFractionDigits = 2, maximumFractionDigits = 2) {
        const number = Number(value || 0);
        return number.toLocaleString('en-US', {
            minimumFractionDigits: minimumFractionDigits,
            maximumFractionDigits: maximumFractionDigits
        });
    }

    function formatPeso(value, minimumFractionDigits = 2, maximumFractionDigits = 2) {
        return `₱${formatAmount(value, minimumFractionDigits, maximumFractionDigits)}`;
    }
    
    // Order discount variables
    let subtotalAfterItemDiscounts = 0;
    let orderDiscountType = 'percentage';
    let orderDiscountValue = 0;
    let orderGrandTotal = 0;
    
    // Single scroll timeout variable
    let globalScrollTimeout;
    
    // Debug
    console.log('Current Branch ID:', branchId);
    console.log('Items loaded for this branch:', itemsList.length);
    
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

    // ========== SHOW LOADING ==========
    function showLoading() {
        Swal.fire({
            title: 'Processing...',
            text: 'Please wait',
            allowOutsideClick: false,
            didOpen: () => {
                Swal.showLoading();
            }
        });
    }

    // ========== SUPPLIER SELECT FUNCTIONS ==========
    function initializeSupplierSelect() {
        if ($('#supplierName').length) {
            $('#supplierName').select2({
                dropdownParent: $('#newPOModal'),
                width: '100%',
                placeholder: '-- Search and Select Supplier --',
                allowClear: true,
                templateResult: formatSupplierOption,
                templateSelection: formatSupplierSelection
            });
        }
        
        if ($('#editSupplierName').length) {
            $('#editSupplierName').select2({
                dropdownParent: $('#editPOModal'),
                width: '100%',
                placeholder: '-- Search and Select Supplier --',
                allowClear: true,
                templateResult: formatSupplierOption,
                templateSelection: formatSupplierSelection
            });
        }
        
        $('#supplierName').on('change', function() {
            const selected = $(this).find('option:selected');
            const supplierId = selected.data('id');
            const supplierCode = selected.data('code');
            const contactPerson = selected.data('contact');
            const email = selected.data('email');
            const phone = selected.data('phone');
            
            $('#supplierId').val(supplierId || '');
            
            if (selected.val()) {
                let infoText = '';
                if (supplierCode) infoText += `Code: ${supplierCode}`;
                if (contactPerson) infoText += (infoText ? ' | ' : '') + `Contact: ${contactPerson}`;
                if (email) infoText += (infoText ? ' | ' : '') + `Email: ${email}`;
                if (phone) infoText += (infoText ? ' | ' : '') + `Phone: ${phone}`;
                
                if (infoText) {
                    $('#supplierInfoText').text(infoText);
                    $('#supplierInfo').show();
                } else {
                    $('#supplierInfo').hide();
                }
            } else {
                $('#supplierInfo').hide();
                $('#supplierId').val('');
            }
        });
        
        $('#editSupplierName').on('change', function() {
            const selected = $(this).find('option:selected');
            const supplierId = selected.data('id');
            $('#editSupplierId').val(supplierId || '');
        });
    }
    
    function formatSupplierOption(supplier) {
        if (!supplier.id) return supplier.text;
        
        const element = $(supplier.element);
        const supplierCode = element.data('code');
        const contactPerson = element.data('contact');
        
        let displayText = supplier.text;
        let subText = [];
        
        if (supplierCode) subText.push(`Code: ${supplierCode}`);
        if (contactPerson) subText.push(`Contact: ${contactPerson}`);
        
        if (subText.length > 0) {
            return $('<div><strong>' + displayText + '</strong><br><small class="text-muted">' + subText.join(' | ') + '</small></div>');
        }
        
        return $('<div>' + displayText + '</div>');
    }

    function formatSupplierSelection(supplier) {
        return supplier.text.split(' (')[0] || supplier.text;
    }

    // ========== ITEM MANAGEMENT FUNCTIONS ==========
    function buildUnitOptionsHtml(itemObj) {
        const availableUoms = Array.isArray(itemObj?.available_uoms) ? itemObj.available_uoms : [];
        const seen = new Set();
        let options = '<option value="">-- Select Unit --</option>';
        availableUoms.forEach((uom) => {
            const label = String(uom || '').trim();
            if (!label) return;
            const key = label.toLowerCase();
            if (seen.has(key)) return;
            seen.add(key);
            options += `<option value="${escapeHtml(label)}">${escapeHtml(label)}</option>`;
        });
        return options;
    }

    function getUnitPriceForItem(itemObj, unitLabel) {
        if (!itemObj) return 0;
        const normalized = String(unitLabel || '').trim().toLowerCase();
        if (normalized && itemObj.uom_prices && Object.prototype.hasOwnProperty.call(itemObj.uom_prices, normalized)) {
            return parseFloat(itemObj.uom_prices[normalized]) || 0;
        }
        const fallbackMap = {
            'piece': parseFloat(itemObj.price_piece || 0),
            'case': parseFloat(itemObj.price_case || 0),
            'inner pack': parseFloat(itemObj.price_inner_pack || 0),
            'inner-pack': parseFloat(itemObj.price_inner_pack || 0),
            'box': parseFloat(itemObj.price_box || 0),
            'carton': parseFloat(itemObj.price_carton || 0)
        };
        return fallbackMap[normalized] || parseFloat(itemObj.price_piece || 0) || 0;
    }

    function addItemRow() {
        itemCounter++;
        const container = document.getElementById('itemsContainer');
        const itemId = 'itemRow' + itemCounter;

        let options = '<option value="">-- Select Item --</option>';
        itemsList.forEach(item => {
            options += `<option value="${item.item_id}" data-code="${escapeHtml(String(item.item_code || ''))}" data-name="${escapeHtml(String(item.item_name || ''))}">${escapeHtml(String(item.item_code || ''))} - ${escapeHtml(String(item.item_name || ''))}</option>`;
        });

        const itemRow = document.createElement('div');
        itemRow.className = 'po-item-row';
        itemRow.id = itemId;
        itemRow.innerHTML = `
            <div class="item-number">#${itemCounter}</div>
            <select class="form-select item-select" onchange="updateItemDetails(this)" required>
                ${options}
            </select>
            <select class="form-select unit-select" onchange="updateUnitPrice(this)" style="flex: 1.5;">
                <option value="">-- Select Unit --</option>
            </select>
            <div class="quantity-container">
                <button type="button" class="btn btn-sm btn-outline-secondary quantity-btn" onclick="decrementQuantity(this)">-</button>
                <input type="number" class="form-control item-quantity" min="1" value="1" onchange="updateItemSubtotal(this)" required>
                <button type="button" class="btn btn-sm btn-outline-secondary quantity-btn" onclick="incrementQuantity(this)">+</button>
            </div>
            <div class="price-container">
                <input type="number" class="form-control item-price" min="0" step="0.01" value="0.00" onchange="updateItemPrice(this)">
            </div>
            <div class="discount-container">
                <select class="discount-type-select" style="width: 70px;" onchange="updateItemSubtotal(this)">
                    <option value="percentage">%</option>
                    <option value="fixed">₱</option>
                </select>
                <input type="number" class="discount-value-input" placeholder="0" value="0" step="0.01" oninput="updateItemSubtotal(this)">
            </div>
            <div class="item-subtotal">
                ₱0.00
            </div>
            <button type="button" class="btn btn-sm btn-outline-danger remove-item-btn" onclick="removeItemRow(this)">
                <i class="bi bi-trash"></i>
            </button>
        `;

        container.appendChild(itemRow);

        $(`#${itemId} .item-select`).select2({
            dropdownParent: $('#newPOModal'),
            width: '100%',
            templateResult: formatItemOption,
            templateSelection: formatItemSelection
        });

        updateItemCount();
        updateTotalAmount();
    }

    function formatItemOption(item) {
        if (!item.id) return item.text;

        const element = $(item.element);
        const itemId = element.val();
        const itemObj = itemsList.find(row => String(row.item_id) === String(itemId));
        const itemName = element.text();
        const prices = [];

        if (itemObj && itemObj.uom_prices) {
            Object.keys(itemObj.uom_prices).forEach(key => {
                const price = parseFloat(itemObj.uom_prices[key] || 0);
                if (price > 0) prices.push(`${key}: ${formatPeso(price)}`);
            });
        }

        return $('<div><strong>' + itemName + '</strong><br><small class="text-muted">' + (prices.join(' | ') || 'No pricing set') + '</small></div>');
    }

    function formatItemSelection(item) {
        return item.text.split(' - ')[1] || item.text;
    }

    function updateItemDetails(select) {
        const row = select.closest('.po-item-row');
        const selectedId = select.value;
        const itemObj = itemsList.find(item => String(item.item_id) === String(selectedId)) || null;
        const unitSelect = row.querySelector('.unit-select');
        row.dataset.uomPrices = JSON.stringify(itemObj?.uom_prices || {});
        row.dataset.defaultUom = String(itemObj?.unit_type || '');

        unitSelect.innerHTML = buildUnitOptionsHtml(itemObj);
        if (row.dataset.defaultUom) {
            unitSelect.value = row.dataset.defaultUom;
        }
        if (!unitSelect.value && unitSelect.options.length > 1) {
            unitSelect.selectedIndex = 1;
        }

        updateUnitPrice(unitSelect);
    }

    function updateUnitPrice(select) {
        const row = select.closest('.po-item-row');
        const itemSelect = row.querySelector('.item-select');
        const itemObj = itemsList.find(item => String(item.item_id) === String(itemSelect.value)) || null;
        const unit = String(select.value || '').trim();
        const price = getUnitPriceForItem(itemObj, unit);

        row.dataset.selectedUnit = unit;
        row.dataset.unitPrice = price;

        const priceInput = row.querySelector('.item-price');
        if (priceInput) {
            priceInput.value = price.toFixed(2);
        }

        updateItemSubtotal(row.querySelector('.item-quantity'));
    }

    function updateItemPrice(input) {
        const row = input.closest('.po-item-row');
        const price = parseFloat(input.value) || 0;
        row.dataset.unitPrice = price;
        updateItemSubtotal(input);
    }
    
    function incrementQuantity(button) {
        const container = button.closest('.quantity-container');
        const input = container.querySelector('.item-quantity');
        const currentValue = parseInt(input.value) || 1;
        input.value = currentValue + 1;
        updateItemSubtotal(input);
    }

    function decrementQuantity(button) {
        const container = button.closest('.quantity-container');
        const input = container.querySelector('.item-quantity');
        const currentValue = parseInt(input.value) || 1;
        if (currentValue > 1) {
            input.value = currentValue - 1;
            updateItemSubtotal(input);
        }
    }
    
    function updateItemSubtotal(element) {
        const row = element.closest('.po-item-row');
        const quantity = parseFloat(row.querySelector('.item-quantity').value) || 0;
        const unitPrice = parseFloat(row.querySelector('.item-price').value) || 0;
        
        // Get discount
        const discountType = row.querySelector('.discount-type-select').value;
        let discountValue = parseFloat(row.querySelector('.discount-value-input').value) || 0;
        
        // Calculate discounted subtotal
        let discountedUnitPrice = unitPrice;
        if (discountType === 'percentage') {
            discountedUnitPrice = unitPrice * (1 - discountValue / 100);
        } else if (discountType === 'fixed') {
            discountedUnitPrice = Math.max(0, unitPrice - discountValue);
        }
        
        const subtotal = quantity * discountedUnitPrice;
        row.querySelector('.item-subtotal').textContent = formatPeso(subtotal);
        
        updateTotalAmount();
    }
    
    function removeItemRow(button) {
        const row = button.closest('.po-item-row');
        const select = row.querySelector('.item-select');
        if (select) $(select).select2('destroy');
        row.remove();
        updateTotalAmount();
        updateItemCount();
        
        const rows = document.querySelectorAll('.po-item-row');
        rows.forEach((row, index) => {
            const numberDiv = row.querySelector('.item-number');
            if (numberDiv) numberDiv.textContent = `#${index + 1}`;
        });
    }
    
    function updateItemCount() {
        const rows = document.querySelectorAll('.po-item-row');
        document.getElementById('itemCount').textContent = rows.length;
    }
    
    function updateTotalAmount() {
        const rows = document.querySelectorAll('.po-item-row');
        let totalAfterItemDiscounts = 0;
        let totalQty = 0;
        let validItems = 0;
        
        rows.forEach(row => {
            const select = row.querySelector('.item-select');
            if (select && select.value) {
                validItems++;
                const quantity = parseFloat(row.querySelector('.item-quantity').value) || 0;
                const unitPrice = parseFloat(row.querySelector('.item-price').value) || 0;
                const discountType = row.querySelector('.discount-type-select').value;
                let discountValue = parseFloat(row.querySelector('.discount-value-input').value) || 0;
                
                let discountedUnitPrice = unitPrice;
                if (discountType === 'percentage') {
                    discountedUnitPrice = unitPrice * (1 - discountValue / 100);
                } else if (discountType === 'fixed') {
                    discountedUnitPrice = Math.max(0, unitPrice - discountValue);
                }
                
                totalAfterItemDiscounts += quantity * discountedUnitPrice;
                totalQty += quantity;
            }
        });
        
        subtotalAfterItemDiscounts = totalAfterItemDiscounts;
        document.getElementById('totalItemsDisplay').textContent = validItems;
        document.getElementById('totalQuantityDisplay').textContent = totalQty;
        
        applyDiscount(); // apply order discount to this subtotal
    }
    
    // Order discount functions
    function applyDiscount() {
        orderDiscountType = document.getElementById('discountType').value;
        orderDiscountValue = parseFloat(document.getElementById('discountValue').value) || 0;
        
        if (orderDiscountValue < 0) orderDiscountValue = 0;
        
        let discountAmount = 0;
        
        if (orderDiscountType === 'percentage') {
            discountAmount = subtotalAfterItemDiscounts * (orderDiscountValue / 100);
            document.getElementById('discountLabel').textContent = `Order Discount (${orderDiscountValue}%):`;
        } else {
            discountAmount = orderDiscountValue;
            document.getElementById('discountLabel').textContent = `Order Discount (Fixed):`;
        }
        
        if (discountAmount > subtotalAfterItemDiscounts) discountAmount = subtotalAfterItemDiscounts;
        
        orderGrandTotal = subtotalAfterItemDiscounts - discountAmount;
        
        document.getElementById('subtotalDisplay').textContent = formatPeso(subtotalAfterItemDiscounts);
        document.getElementById('discountAmountDisplay').textContent = `-${formatPeso(discountAmount)}`;
        document.getElementById('grandTotalDisplay').textContent = formatPeso(orderGrandTotal);
        document.getElementById('totalAmountDisplay').textContent = formatPeso(orderGrandTotal);
        document.getElementById('totalAmount').value = orderGrandTotal.toFixed(2);
        
        document.getElementById('discountType_hidden').value = orderDiscountType;
        document.getElementById('discountValue_hidden').value = orderDiscountValue;
        document.getElementById('subtotalAmount').value = subtotalAfterItemDiscounts.toFixed(2);
        
        document.getElementById('discountSummary').style.display = 'block';
    }
    
    function getItemsData() {
        const rows = document.querySelectorAll('.po-item-row');
        const items = [];
        
        rows.forEach(row => {
            const select = row.querySelector('.item-select');
            if (select && select.value) {
                const quantity = parseInt(row.querySelector('.item-quantity').value) || 0;
                const unitPrice = parseFloat(row.querySelector('.item-price').value) || 0;
                const discountType = row.querySelector('.discount-type-select').value;
                const discountValue = parseFloat(row.querySelector('.discount-value-input').value) || 0;
                
                const itemData = {
                    item_id: select.value,
                    quantity: quantity,
                    unit_price: unitPrice
                };
                if (discountTypeColumnExists) {
                    if (discountValue > 0) {
                        itemData.discount_type = discountType;
                        itemData.discount_value = discountValue;
                    }
                }
                items.push(itemData);
            }
        });
        
        return items;
    }

    // ========== PURCHASE ORDER FUNCTIONS ==========
    document.addEventListener('DOMContentLoaded', function() {
        console.log("Purchase Orders - with Per-Item Discounts");
        initializeSidebar();
        
        const today = new Date();
        const formattedDate = today.toISOString().slice(0, 10);
        document.getElementById('orderDate').value = formattedDate;
        
        initializeSupplierSelect();
        
        if (document.getElementById('itemSelect')) {
            $('#itemSelect').select2({
                dropdownParent: $('#addItemModal'),
                width: '100%'
            });
        }
        
        $('#itemSelect').on('change', function() {
            const selectedId = $(this).val();
            const itemObj = itemsList.find(item => String(item.item_id) === String(selectedId)) || null;
            const unitSelect = document.getElementById('itemUnit');
            if (unitSelect) {
                unitSelect.innerHTML = buildUnitOptionsHtml(itemObj);
                const defaultUom = String(itemObj?.unit_type || '').trim();
                if (defaultUom) {
                    unitSelect.value = defaultUom;
                }
                if (!unitSelect.value && unitSelect.options.length > 1) {
                    unitSelect.selectedIndex = 1;
                }
            }
            updateUnitPriceModal();
        });
        
        $('#itemUnit').on('change', updateUnitPriceModal);
        document.getElementById('itemQuantity')?.addEventListener('input', updateModalSubtotal);
        document.getElementById('itemUnitPrice')?.addEventListener('input', updateModalSubtotal);
        document.getElementById('itemDiscountValue')?.addEventListener('input', updateModalSubtotal);
        document.getElementById('itemDiscountType')?.addEventListener('change', updateModalSubtotal);
        
        document.getElementById('discountValue')?.addEventListener('input', applyDiscount);
        document.getElementById('discountType')?.addEventListener('change', applyDiscount);
        
        // Mobile menu toggle
        const mobileMenuBtn = document.getElementById('mobileMenuBtn');
        if (mobileMenuBtn) {
            mobileMenuBtn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                toggleSidebar();
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
            const isMobile = window.innerWidth <= 992;
            
            if (isMobile && sidebar.classList.contains('active') && 
                !sidebar.contains(event.target) && 
                !mobileBtn.contains(event.target) &&
                !overlay?.contains(event.target)) {
                closeMobileSidebar();
            }
        });

        window.addEventListener('resize', function() {
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
            
            // Re-run tap to view on resize
            setTimeout(setupMobileTapToView, 100);
        });
        
        // Call setActiveMobileNav
        setActiveMobileNav();
        
        // Initialize mobile tap to view
        setTimeout(setupMobileTapToView, 500);
    });

    function updateUnitPriceModal() {
        const selectedId = $('#itemSelect').val();
        const itemObj = itemsList.find(item => String(item.item_id) === String(selectedId)) || null;
        const unit = $('#itemUnit').val();
        const price = getUnitPriceForItem(itemObj, unit);
        document.getElementById('itemUnitPrice').value = price.toFixed(2);
        updateModalSubtotal();
    }

    function updateModalSubtotal() {
        const quantity = parseFloat(document.getElementById('itemQuantity').value) || 0;
        let unitPrice = parseFloat(document.getElementById('itemUnitPrice').value) || 0;
        const discountType = document.getElementById('itemDiscountType').value;
        let discountValue = parseFloat(document.getElementById('itemDiscountValue').value) || 0;
        
        let discountedPrice = unitPrice;
        if (discountType === 'percentage') {
            discountedPrice = unitPrice * (1 - discountValue / 100);
        } else if (discountType === 'fixed') {
            discountedPrice = Math.max(0, unitPrice - discountValue);
        }
        
        const subtotal = quantity * discountedPrice;
        document.getElementById('subtotalAmount').textContent = formatAmount(subtotal);
        document.getElementById('itemSubtotal').style.display = 'block';
    }

    function filterTable() {
        const statusFilter = document.getElementById('filterStatus').value;
        const supplierFilter = document.getElementById('filterSupplier').value;
        const monthFilter = document.getElementById('filterMonth').value;
        const branchFilter = document.getElementById('filterBranch')?.value || 'all';
        const searchTerm = document.getElementById('searchInput').value.toLowerCase();
        
        const rows = document.querySelectorAll('.po-row');
        let visibleCount = 0;
        
        rows.forEach(row => {
            const poNumber = row.dataset.poNumber?.toLowerCase() || '';
            const supplier = row.dataset.supplier?.toLowerCase() || '';
            const status = row.dataset.status || '';
            const dateStr = row.dataset.date || '';
            const rowBranch = row.dataset.branch || '';
            
            let matchesStatus = statusFilter === 'all' || status === statusFilter;
            let matchesSupplier = supplierFilter === 'all' || row.dataset.supplier === supplierFilter;
            
            let matchesMonth = true;
            if (monthFilter !== 'all' && dateStr) {
                const poMonth = new Date(dateStr).getMonth() + 1;
                matchesMonth = poMonth === parseInt(monthFilter);
            }
            
            let matchesBranch = true;
            if (poBranchColumnExists && viewAllBranches && branchFilter !== 'all') {
                matchesBranch = rowBranch === branchFilter;
            }
            
            let matchesSearch = searchTerm === '' || 
                poNumber.includes(searchTerm) || 
                supplier.includes(searchTerm);
            
            if (matchesStatus && matchesSupplier && matchesMonth && matchesBranch && matchesSearch) {
                row.style.display = '';
                visibleCount++;
            } else {
                row.style.display = 'none';
            }
        });
        
        const emptyState = document.getElementById('emptyState');
        const tableBody = document.getElementById('poTableBody');
        if (visibleCount === 0) {
            if (emptyState) emptyState.style.display = 'block';
            if (tableBody) tableBody.style.display = 'none';
        } else {
            if (emptyState) emptyState.style.display = 'none';
            if (tableBody) tableBody.style.display = 'table-row-group';
        }
        
        // Re-run tap to view after filtering
        setTimeout(setupMobileTapToView, 100);
    }

    function showNewPOModal() {
        <?php if (empty($suppliers_list)): ?>
        Swal.fire({
            title: 'No Suppliers Available',
            text: 'There are no active suppliers. Please add suppliers first.',
            icon: 'warning',
            confirmButtonText: 'OK'
        }).then(() => {
            window.location.href = 'supplier.php';
        });
        return;
        <?php endif; ?>
        
        <?php if (empty($items_list)): ?>
        Swal.fire({
            title: 'No Items Available',
            text: 'There are no active items for your branch. Please contact administrator.',
            icon: 'warning',
            confirmButtonText: 'OK'
        });
        return;
        <?php endif; ?>
        
        document.getElementById('newPOForm').reset();
        $('#supplierName').val(null).trigger('change');
        $('#supplierInfo').hide();
        
        document.getElementById('discountType').value = 'percentage';
        document.getElementById('discountValue').value = '0';
        document.getElementById('discountSummary').style.display = 'none';
        subtotalAfterItemDiscounts = 0;
        orderGrandTotal = 0;
        
        const itemsContainer = document.getElementById('itemsContainer');
        const oldSelects = itemsContainer.querySelectorAll('.item-select');
        oldSelects.forEach(select => $(select).select2('destroy'));
        itemsContainer.innerHTML = '';
        itemCounter = 0;
        
        const today = new Date();
        const formattedDate = today.toISOString().slice(0, 10);
        document.getElementById('orderDate').value = formattedDate;
        
        addItemRow();
        
        updateItemCount();
        updateTotalAmount();
        
        new bootstrap.Modal(document.getElementById('newPOModal')).show();
    }

    function createPurchaseOrder() {
        const supplierName = document.getElementById('supplierName').value;
        const supplierId = document.getElementById('supplierId').value;
        const orderDate = document.getElementById('orderDate').value;
        const expectedDelivery = document.getElementById('expectedDelivery').value;
        const items = getItemsData();
        
        if (!supplierName) {
            Swal.fire('Warning', 'Please select a Supplier', 'warning');
            return;
        }
        
        if (!orderDate) {
            Swal.fire('Warning', 'Order Date is required', 'warning');
            return;
        }
        
        if (items.length === 0) {
            Swal.fire('Warning', 'At least one item is required', 'warning');
            return;
        }
        
        let totalAmount = orderGrandTotal;
        
        showLoading();
        
        const formData = new FormData();
        formData.append('action', 'create_po');
        formData.append('supplier_name', supplierName);
        if (supplierId) formData.append('supplier_id', supplierId);
        formData.append('order_date', orderDate);
        formData.append('expected_delivery', expectedDelivery);
        formData.append('total_amount', totalAmount.toFixed(2));
        formData.append('po_status', document.getElementById('poStatus').value);
        formData.append('items', JSON.stringify(items));
        
        fetch('purchase_order.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            Swal.close();
            if (data.success) {
                Swal.fire({
                    icon: 'success',
                    title: 'Success!',
                    text: data.message,
                    timer: 2000,
                    showConfirmButton: false
                }).then(() => {
                    bootstrap.Modal.getInstance(document.getElementById('newPOModal')).hide();
                    location.reload();
                });
            } else {
                Swal.fire('Error', data.message, 'error');
            }
        })
        .catch(error => {
            Swal.close();
            console.error('Error:', error);
            Swal.fire('Error', 'An error occurred while creating purchase order', 'error');
        });
    }

       function viewPO(id) {
        showLoading();
        
        const formData = new FormData();
        formData.append('action', 'get_po');
        formData.append('po_id', id);
        
        fetch('purchase_order.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            Swal.close();
            
            if (data.success) {
                const po = data.po;
                const items = data.items || [];
                currentPOData = po;
                currentPOId = id;
                
                // Format dates safely
                let orderDate = 'N/A';
                let expectedDate = 'N/A';
                let createdDate = 'N/A';
                let updatedDate = 'N/A';
                
                if (po.order_date && po.order_date !== '0000-00-00') {
                    try {
                        const dateStr = po.order_date.split(' ')[0];
                        const date = new Date(dateStr);
                        if (!isNaN(date.getTime())) {
                            orderDate = date.toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' });
                        }
                    } catch(e) { orderDate = po.order_date; }
                }
                
                if (po.expected_delivery && po.expected_delivery !== '0000-00-00') {
                    try {
                        const dateStr = po.expected_delivery.split(' ')[0];
                        const date = new Date(dateStr);
                        if (!isNaN(date.getTime())) {
                            expectedDate = date.toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' });
                        }
                    } catch(e) { expectedDate = po.expected_delivery; }
                }
                
                if (po.created_at) {
                    try {
                        const date = new Date(po.created_at);
                        if (!isNaN(date.getTime())) {
                            createdDate = date.toLocaleString('en-US', { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
                        }
                    } catch(e) { createdDate = po.created_at; }
                }
                
                if (po.updated_at) {
                    try {
                        const date = new Date(po.updated_at);
                        if (!isNaN(date.getTime())) {
                            updatedDate = date.toLocaleString('en-US', { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
                        }
                    } catch(e) { updatedDate = po.updated_at; }
                }
                
                // Get status class and text
                const statusClass = getStatusClass(po.po_status);
                const statusText = getStatusText(po.po_status);
                
                // Build HTML for Purchase Order Information
                let infoHtml = `
                    <div class="col-md-6">
                        <div class="po-details-card">
                            <h6><i class="bi bi-receipt"></i> Purchase Order Information</h6>
                            <div class="info-row">
                                <span class="info-label">PO Number:</span>
                                <span class="info-value">${po.po_number}</span>
                            </div>
                `;
                
                if (po.branch_name) {
                    infoHtml += `
                            <div class="info-row">
                                <span class="info-label">Branch:</span>
                                <span class="info-value"><span class="badge bg-info">${po.branch_name}</span></span>
                            </div>
                    `;
                }
                
                infoHtml += `
                            <div class="info-row">
                                <span class="info-label">Supplier:</span>
                                <span class="info-value">${po.supplier_name || 'N/A'}</span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Order Date:</span>
                                <span class="info-value">${orderDate}</span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Expected Delivery:</span>
                                <span class="info-value">${expectedDate}</span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Status:</span>
                                <span class="info-value"><span class="status-badge ${statusClass}">${statusText}</span></span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="po-details-card">
                            <h6><i class="bi bi-bar-chart"></i> Order Summary</h6>
                            <div class="info-row">
                                <span class="info-label">Total Items:</span>
                                <span class="info-value">${po.total_items || 0}</span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Total Quantity:</span>
                                <span class="info-value">${po.total_quantity || 0}</span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Total Amount:</span>
                                <span class="info-value total-amount">${formatPeso(po.total_amount || 0)}</span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Created By:</span>
                                <span class="info-value">${po.created_by_name || 'N/A'}</span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Created At:</span>
                                <span class="info-value">${createdDate}</span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Last Updated:</span>
                                <span class="info-value">${updatedDate}</span>
                            </div>
                        </div>
                    </div>
                `;
                
                // Build items table with discount display
                let itemsHtml = '';
                if (items.length > 0) {
                    itemsHtml = `
                        <div class="mt-4">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h6 class="fw-bold mb-0"><i class="bi bi-box-seam"></i> Order Items <span class="item-count-badge">${items.length}</span></h6>
                                ${po.po_status !== 'received' && po.po_status !== 'cancelled' ? 
                                    '<button class="btn btn-sm btn-success" onclick="showAddItemModal(' + po.po_id + ')"><i class="bi bi-plus-circle"></i> Add Item</button>' : ''}
                            </div>
                            <div class="table-responsive">
                                <table class="table table-sm table-bordered items-table">
                                    <thead class="table-light">
                                        <tr>
                                            <th style="width: 5%">#</th>
                                            <th style="width: 15%">Item Code</th>
                                            <th style="width: 30%">Item Name</th>
                                            <th style="width: 10%" class="text-center">Qty</th>
                                            <th style="width: 15%" class="text-end">Unit Cost</th>
                                            <th style="width: 10%" class="text-center">Discount</th>
                                            <th style="width: 15%" class="text-end">Subtotal</th>
                                            ${po.po_status !== 'received' && po.po_status !== 'cancelled' ? '<th style="width: 10%" class="text-center">Action</th>' : ''}
                                        </tr>
                                    </thead>
                                    <tbody>
                    `;
                    
                    let totalQty = 0;
                    let totalAmount = 0;
                    
                    items.forEach((item, index) => {
                        let discountedUnitPrice = item.unit_price;
                        let discountDisplay = '—';
                        if (item.discount_type && item.discount_value > 0) {
                            if (item.discount_type === 'percentage') {
                                discountedUnitPrice = item.unit_price * (1 - item.discount_value / 100);
                                discountDisplay = `${item.discount_value}%`;
                            } else if (item.discount_type === 'fixed') {
                                discountedUnitPrice = Math.max(0, item.unit_price - item.discount_value);
                                discountDisplay = formatPeso(item.discount_value);
                            }
                        }
                        const subtotal = item.quantity_ordered * discountedUnitPrice;
                        totalQty += item.quantity_ordered;
                        totalAmount += subtotal;
                        
                        itemsHtml += `
                            <tr>
                                <td class="text-center">${index + 1}</td>
                                <td>${escapeHtml(item.item_code || 'N/A')}</td>
                                <td>${escapeHtml(item.item_name || 'N/A')}</td>
                                <td class="text-center">${item.quantity_ordered}</td>
                                <td class="text-end">${formatPeso(item.unit_price)}</span></td>
                                <td class="text-center">${discountDisplay}</td>
                                <td class="text-end">${formatPeso(subtotal)}</span></td>
                                ${po.po_status !== 'received' && po.po_status !== 'cancelled' ? 
                                    `<td class="text-center">
                                        <button class="btn btn-sm btn-outline-danger" onclick="deleteItem(${item.po_item_id}, ${po.po_id}, '${escapeHtml(item.item_name)}')">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                     </span>` : ''}
                            </tr>
                        `;
                    });
                    
                    itemsHtml += `
                                    </tbody>
                                    <tfoot class="table-secondary">
                                        <tr>
                                            <th colspan="3" class="text-end">Totals:</th>
                                            <th class="text-center">${totalQty}</th>
                                            <th></th>
                                            <th></th>
                                            <th class="text-end">${formatPeso(totalAmount)}</th>
                                            ${po.po_status !== 'received' && po.po_status !== 'cancelled' ? '<th></th>' : ''}
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>
                    `;
                } else {
                    itemsHtml = `
                        <div class="mt-4">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h6 class="fw-bold mb-0"><i class="bi bi-box-seam"></i> Order Items</h6>
                                ${po.po_status !== 'received' && po.po_status !== 'cancelled' ? 
                                    '<button class="btn btn-sm btn-success" onclick="showAddItemModal(' + po.po_id + ')"><i class="bi bi-plus-circle"></i> Add Item</button>' : ''}
                            </div>
                            <div class="alert alert-info">
                                <i class="bi bi-info-circle me-2"></i>
                                No items added to this purchase order yet.
                            </div>
                        </div>
                    `;
                }
                
                const content = document.getElementById('poDetailsContent');
                content.innerHTML = `
                    <div class="row">
                        ${infoHtml}
                    </div>
                    ${itemsHtml}
                `;
                
                const editBtn = document.getElementById('editFromViewBtn');
                if (po.po_status !== 'received' && po.po_status !== 'cancelled') {
                    editBtn.style.display = 'inline-block';
                } else {
                    editBtn.style.display = 'none';
                }
                
                new bootstrap.Modal(document.getElementById('viewPOModal')).show();
            } else {
                Swal.fire('Error', data.message, 'error');
            }
        })
        .catch(error => {
            Swal.close();
            console.error('Error:', error);
            Swal.fire('Error', 'An error occurred while fetching purchase order details', 'error');
        });
    }
    
    // Helper function to escape HTML
    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function editPOFromView() {
        bootstrap.Modal.getInstance(document.getElementById('viewPOModal')).hide();
        setTimeout(() => editPO(currentPOId), 300);
    }

    function editPO(id) {
        showLoading();
        
        const formData = new FormData();
        formData.append('action', 'get_po');
        formData.append('po_id', id);
        
        fetch('purchase_order.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            Swal.close();
            
            if (data.success) {
                const po = data.po;
                
                let orderDate = '';
                let expectedDate = '';
                
                try {
                    if (po.order_date) {
                        const dateStr = po.order_date.split(' ')[0];
                        const date = new Date(dateStr + 'T12:00:00');
                        if (!isNaN(date.getTime())) orderDate = date.toISOString().slice(0, 10);
                    }
                } catch(e) {}
                try {
                    if (po.expected_delivery && po.expected_delivery !== '0000-00-00') {
                        const dateStr = po.expected_delivery.split(' ')[0];
                        const date = new Date(dateStr + 'T12:00:00');
                        if (!isNaN(date.getTime())) expectedDate = date.toISOString().slice(0, 10);
                    }
                } catch(e) {}
                
                document.getElementById('editPOId').value = po.po_id;
                document.getElementById('editPONumber').value = po.po_number;
                $('#editSupplierName').val(po.supplier_name).trigger('change');
                $('#editSupplierId').val(po.supplier_id || '');
                document.getElementById('editOrderDate').value = orderDate;
                document.getElementById('editExpectedDelivery').value = expectedDate;
                document.getElementById('editTotalAmount').value = po.total_amount || 0;
                document.getElementById('editPOStatus').value = po.po_status;
                
                if (poBranchColumnExists && viewAllBranches) {
                    document.getElementById('editBranch').value = po.branch_name || `Branch ${po.branch_id}`;
                }
                
                currentPOId = id;
                new bootstrap.Modal(document.getElementById('editPOModal')).show();
            } else {
                Swal.fire('Error', data.message, 'error');
            }
        })
        .catch(error => {
            Swal.close();
            console.error('Error:', error);
            Swal.fire('Error', 'An error occurred while fetching purchase order details', 'error');
        });
    }

    function updatePurchaseOrder() {
        const poId = document.getElementById('editPOId').value;
        const supplierName = document.getElementById('editSupplierName').value;
        const supplierId = document.getElementById('editSupplierId').value;
        const orderDate = document.getElementById('editOrderDate').value;
        const expectedDelivery = document.getElementById('editExpectedDelivery').value;
        const totalAmount = document.getElementById('editTotalAmount').value;
        const poStatus = document.getElementById('editPOStatus').value;
        
        if (!supplierName) {
            Swal.fire('Warning', 'Please select a Supplier', 'warning');
            return;
        }
        
        if (!orderDate) {
            Swal.fire('Warning', 'Order Date is required', 'warning');
            return;
        }
        
        if (!totalAmount || totalAmount < 0) {
            Swal.fire('Warning', 'Valid Total Amount is required', 'warning');
            return;
        }
        
        showLoading();
        
        const formData = new FormData();
        formData.append('action', 'update_po');
        formData.append('po_id', poId);
        formData.append('supplier_name', supplierName);
        if (supplierId) formData.append('supplier_id', supplierId);
        formData.append('order_date', orderDate);
        formData.append('expected_delivery', expectedDelivery);
        formData.append('total_amount', totalAmount);
        formData.append('po_status', poStatus);
        
        fetch('purchase_order.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            Swal.close();
            if (data.success) {
                Swal.fire({
                    icon: 'success',
                    title: 'Success!',
                    text: data.message,
                    timer: 2000,
                    showConfirmButton: false
                }).then(() => {
                    bootstrap.Modal.getInstance(document.getElementById('editPOModal')).hide();
                    location.reload();
                });
            } else {
                Swal.fire('Error', data.message, 'error');
            }
        })
        .catch(error => {
            Swal.close();
            console.error('Error:', error);
            Swal.fire('Error', 'An error occurred while updating purchase order', 'error');
        });
    }

    function showAddItemModal(poId) {
        document.getElementById('addItemPOId').value = poId;
        document.getElementById('addItemForm').reset();
        document.getElementById('itemSubtotal').style.display = 'none';
        $('#itemSelect').val(null).trigger('change');
        new bootstrap.Modal(document.getElementById('addItemModal')).show();
    }

    function addItemToPO() {
        const poId = document.getElementById('addItemPOId').value;
        const itemId = document.getElementById('itemSelect').value;
        const quantity = document.getElementById('itemQuantity').value;
        const unitPrice = document.getElementById('itemUnitPrice').value;
        const discountType = document.getElementById('itemDiscountType').value;
        const discountValue = document.getElementById('itemDiscountValue').value;
        
        if (!itemId) {
            Swal.fire('Warning', 'Please select an item', 'warning');
            return;
        }
        if (!quantity || quantity <= 0) {
            Swal.fire('Warning', 'Please enter a valid quantity', 'warning');
            return;
        }
        if (!unitPrice || unitPrice <= 0) {
            Swal.fire('Warning', 'Please enter a valid unit cost', 'warning');
            return;
        }
        
        showLoading();
        
        const formData = new FormData();
        formData.append('action', 'add_po_item');
        formData.append('po_id', poId);
        formData.append('item_id', itemId);
        formData.append('quantity_ordered', quantity);
        formData.append('unit_price', unitPrice);
        if (discountTypeColumnExists && discountValue > 0) {
            formData.append('discount_type', discountType);
            formData.append('discount_value', discountValue);
        }
        
        fetch('purchase_order.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            Swal.close();
            if (data.success) {
                Swal.fire({
                    icon: 'success',
                    title: 'Success!',
                    text: data.message,
                    timer: 2000,
                    showConfirmButton: false
                }).then(() => {
                    bootstrap.Modal.getInstance(document.getElementById('addItemModal')).hide();
                    viewPO(poId);
                });
            } else {
                Swal.fire('Error', data.message, 'error');
            }
        })
        .catch(error => {
            Swal.close();
            console.error('Error:', error);
            Swal.fire('Error', 'An error occurred while adding item', 'error');
        });
    }

    function deleteItem(itemId, poId, itemName) {
        currentItemId = itemId;
        currentPOId = poId;
        document.getElementById('deleteItemName').textContent = itemName;
        new bootstrap.Modal(document.getElementById('deleteItemModal')).show();
    }

    function confirmDeleteItem() {
        showLoading();
        
        const formData = new FormData();
        formData.append('action', 'delete_po_item');
        formData.append('po_item_id', currentItemId);
        formData.append('po_id', currentPOId);
        
        fetch('purchase_order.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            Swal.close();
            if (data.success) {
                Swal.fire({
                    icon: 'success',
                    title: 'Deleted!',
                    text: data.message,
                    timer: 2000,
                    showConfirmButton: false
                }).then(() => {
                    bootstrap.Modal.getInstance(document.getElementById('deleteItemModal')).hide();
                    viewPO(currentPOId);
                });
            } else {
                Swal.fire('Error', data.message, 'error');
            }
        })
        .catch(error => {
            Swal.close();
            console.error('Error:', error);
            Swal.fire('Error', 'An error occurred while deleting item', 'error');
        });
    }

    function deletePO(id) {
        const row = document.querySelector(`.po-row[data-id="${id}"]`);
        if (!row) return;
        document.getElementById('deletePONumber').textContent = row.dataset.poNumber;
        currentPOId = id;
        new bootstrap.Modal(document.getElementById('deletePOModal')).show();
    }

    function confirmDeletePO() {
        showLoading();
        
        const formData = new FormData();
        formData.append('action', 'delete_po');
        formData.append('po_id', currentPOId);
        
        fetch('purchase_order.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            Swal.close();
            if (data.success) {
                Swal.fire({
                    icon: 'success',
                    title: 'Deleted!',
                    text: data.message,
                    timer: 2000,
                    showConfirmButton: false
                }).then(() => {
                    bootstrap.Modal.getInstance(document.getElementById('deletePOModal')).hide();
                    location.reload();
                });
            } else {
                Swal.fire('Error', data.message, 'error');
            }
        })
        .catch(error => {
            Swal.close();
            console.error('Error:', error);
            Swal.fire('Error', 'An error occurred while deleting purchase order', 'error');
        });
    }

    function printPODetails() {
        const content = document.getElementById('poDetailsContent').innerHTML;
        const printWindow = window.open('', '_blank');
        printWindow.document.write(`
            <html>
                <head>
                    <title>Purchase Order Details</title>
                    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
                    <style>
                        body { padding: 20px; }
                        .status-badge { display: inline-block; padding: 5px 12px; font-size: 12px; border-radius: 20px; }
                        .status-draft { background-color: #e9ecef; color: #495057; }
                        .status-processing { background-color: #cfe2ff; color: #084298; }
                        .status-approved { background-color: #cce5ff; color: #004085; }
                        .status-delivered { background-color: #d1e7dd; color: #0a3622; }
                        .status-cancelled { background-color: #f8d7da; color: #58151c; }
                        .po-details-card { background-color: #f8f9fa; border-radius: 8px; padding: 20px; margin-bottom: 20px; }
                        .detail-label { font-size: 12px; color: #6c757d; }
                        .detail-value { font-size: 16px; font-weight: 600; }
                        .items-table { font-size: 13px; }
                        @media print {
                            .btn { display: none; }
                        }
                    </style>
                </head>
                <body>
                    <h2 class="mb-4">Purchase Order Details</h2>
                    ${content}
                </body>
            </html>
        `);
        printWindow.document.close();
        printWindow.print();
    }

    function exportToExcel() {
        const rows = document.querySelectorAll('.po-row:not([style*="display: none"])');
        if (rows.length === 0) {
            Swal.fire('Warning', 'No purchase orders to export', 'warning');
            return;
        }
        
        const excelData = [];
        const headers = [
            'PO Number',
            'Supplier',
            'Order Date',
            ...(poBranchColumnExists && viewAllBranches ? ['Branch'] : []),
            'Items',
            'Quantity',
            'Total Amount (₱)',
            'Status',
            'Expected Delivery'
        ];
        excelData.push(headers);

        rows.forEach(row => {
            if (row.style.display !== 'none') {
                const cells = row.querySelectorAll('td');
                let cellIndex = 0;
                const poNumber = cells[cellIndex++]?.innerText || '';
                const supplier = cells[cellIndex++]?.innerText || '';
                const orderDate = cells[cellIndex++]?.innerText || '';
                let branch = '';
                if (poBranchColumnExists && viewAllBranches) branch = cells[cellIndex++]?.innerText || '';
                const items = parseInt(cells[cellIndex++]?.innerText) || 0;
                const qty = parseInt(cells[cellIndex++]?.innerText.replace(/,/g, '')) || 0;
                const amount = parseFloat(cells[cellIndex++]?.innerText.replace('₱', '').replace(/,/g, '')) || 0;
                const status = cells[cellIndex++]?.innerText || '';
                const expectedDate = cells[cellIndex++]?.innerText || '';
                
                const rowData = [poNumber, supplier, orderDate];
                if (poBranchColumnExists && viewAllBranches) rowData.push(branch);
                rowData.push(items, qty, amount, status, expectedDate);
                excelData.push(rowData);
            }
        });

        const wb = XLSX.utils.book_new();
        const ws = XLSX.utils.aoa_to_sheet(excelData);
        const colWidths = [
            { wch: 15 }, { wch: 25 }, { wch: 15 },
            ...(poBranchColumnExists && viewAllBranches ? [{ wch: 12 }] : []),
            { wch: 10 }, { wch: 12 }, { wch: 18 }, { wch: 15 }, { wch: 15 }
        ];
        ws['!cols'] = colWidths;
        XLSX.utils.book_append_sheet(wb, ws, 'Purchase Orders');
        
        const date = new Date();
        const dateStr = date.toISOString().slice(0,10).replace(/-/g, '');
        let filename = `Purchase_Orders_${dateStr}`;
        if (poBranchColumnExists && !viewAllBranches) filename += `_Branch_${branchId}`;
        filename += '.xlsx';
        XLSX.writeFile(wb, filename);
        
        Swal.fire({ icon: 'success', title: 'Export Complete', timer: 2000, showConfirmButton: false });
    }

    function copySQL(table) {
        let sql = '';
        if (table === 'purchase_orders') {
            sql = "ALTER TABLE purchase_orders ADD COLUMN branch_id INT NULL;\nALTER TABLE purchase_orders ADD FOREIGN KEY (branch_id) REFERENCES branches(branch_id);";
        } else if (table === 'items') {
            sql = "ALTER TABLE items ADD COLUMN branch_id INT NULL;\nALTER TABLE items ADD FOREIGN KEY (branch_id) REFERENCES branches(branch_id);";
        } else if (table === 'supplier_id') {
            sql = "ALTER TABLE purchase_orders ADD COLUMN supplier_id INT NULL;\nALTER TABLE purchase_orders ADD FOREIGN KEY (supplier_id) REFERENCES suppliers(supplier_id);";
        } else if (table === 'discount_columns') {
            sql = "ALTER TABLE purchase_order_items ADD COLUMN discount_type ENUM('percentage','fixed') NULL;\nALTER TABLE purchase_order_items ADD COLUMN discount_value DECIMAL(10,2) DEFAULT 0.00;";
        }
        
        navigator.clipboard.writeText(sql).then(() => {
            Swal.fire({ icon: 'success', title: 'Copied!', text: 'SQL copied to clipboard', timer: 1500, showConfirmButton: false });
        });
    }

    function getStatusClass(status) {
        const classes = {
            'draft': 'status-draft',
            'submitted': 'status-processing',
            'approved': 'status-approved',
            'received': 'status-delivered',
            'cancelled': 'status-cancelled'
        };
        return classes[status] || 'status-draft';
    }

    function getStatusText(status) {
        const texts = {
            'draft': 'Draft',
            'submitted': 'Processing',
            'approved': 'Approved',
            'received': 'Delivered',
            'cancelled': 'Cancelled'
        };
        return texts[status] || status;
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
    document.addEventListener('keydown', function(e) {
        if (e.ctrlKey && e.key === 'b' && window.innerWidth > 992) {
            e.preventDefault();
            toggleSidebar();
        } else if (e.ctrlKey && e.key === 'f') {
            e.preventDefault();
            document.getElementById('searchInput')?.focus();
        } else if (e.ctrlKey && e.key === 'n') {
            e.preventDefault();
            showNewPOModal();
        }
    });
window.addEventListener('scroll', function() {
        if (globalScrollTimeout) clearTimeout(globalScrollTimeout);
        globalScrollTimeout = setTimeout(() => {
            closeAllDropdowns();
        }, 150);
    });


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

        if (typeof cleanupModalBackdrops === 'function') {
            cleanupModalBackdrops();
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


    // ================= PURCHASE DROPDOWN POSITION FIX =================
    function fixPurchaseDropdownPosition() {
        const purchaseDropdown = document.querySelector('#purchaseDropdown .more-dropdown');
        if (purchaseDropdown) {
            purchaseDropdown.style.setProperty('right', '0', 'important');
            purchaseDropdown.style.setProperty('left', 'auto', 'important');
        }
    }
    
    document.addEventListener('DOMContentLoaded', function() {
        fixPurchaseDropdownPosition();
        setActiveMobileNav();
    });
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

    // ========== MOBILE NAV ACTIVE STATE ==========
    function setActiveMobileNav() {
        const currentPage = window.location.pathname.split('/').pop();
        
        // Remove all active classes from ALL navigation elements
        document.querySelectorAll('.mobile-nav .nav-link, .more-btn, .dropdown-item, .has-active').forEach(el => {
            el.classList.remove('active', 'has-active');
        });
        
        // ========== MAIN NAVIGATION (non-dropdown items) ==========
        // Only set active for standalone nav items like Trips
        const mainNavLinks = document.querySelectorAll('.mobile-nav .nav-link:not(.more-btn)');
        mainNavLinks.forEach(link => {
            const href = link.getAttribute('href');
            if (href === currentPage) {
                link.classList.add('active');
            }
        });
        
        // ========== DROPDOWN ITEMS ==========
        // This is where the actual active state should be (the dropdown item itself)
        document.querySelectorAll('.more-dropdown .dropdown-item').forEach(item => {
            const href = item.getAttribute('href');
            if (href === currentPage) {
                // Add active class to the dropdown item (the text inside the dropdown)
                item.classList.add('active');
                
                // Mark the parent more-btn as has-active (for visual indicator only)
                const parentDropdown = item.closest('.dropdown-more');
                if (parentDropdown) {
                    const parentBtn = parentDropdown.querySelector('.more-btn');
                    if (parentBtn) {
                        parentBtn.classList.add('has-active');
                    }
                }
            }
        });
        
        // ========== SPECIAL HANDLING FOR TRIP TICKETS ==========
        // Trip Tickets is a standalone nav item
        if (currentPage === 'trip_tickets.php') {
            const tripLink = document.querySelector('#mobileNav .nav-link[href="trip_tickets.php"]');
            if (tripLink) tripLink.classList.add('active');
        }
        
        // ========== DEBUG LOG ==========
        console.log('Current page:', currentPage);
        const activeDropdownItem = document.querySelector('.more-dropdown .dropdown-item.active');
        console.log('Active dropdown item:', activeDropdownItem ? activeDropdownItem.querySelector('span')?.innerText : 'NONE');
    }
    
    // Filter toggle functionality
    document.addEventListener('DOMContentLoaded', function() {
        const filterToggleBtn = document.getElementById('filterToggleBtn');
        const filterContent = document.getElementById('filterContent');
        
        if (filterToggleBtn && filterContent) {
            filterToggleBtn.addEventListener('click', function() {
                const expanded = this.getAttribute('aria-expanded') === 'true' ? false : true;
                this.setAttribute('aria-expanded', expanded);
                
                if (expanded) {
                    filterContent.classList.remove('collapsed');
                } else {
                    filterContent.classList.add('collapsed');
                }
            });
        }
    });

    // Apply filters function (combines all filters)
    function applyFilters() {
        filterTable(); // Calls the existing filterTable function
        // Re-run tap to view after filters are applied
        setTimeout(setupMobileTapToView, 100);
    }
    
    // ===== SIMPLE MODAL SHIFT FIX =====
    document.addEventListener('hidden.bs.modal', function() {
        // Reset body styles - simple lang
        document.body.style.overflow = '';
        document.body.style.paddingRight = '';
        document.body.style.position = '';
        document.body.style.width = '';
        document.body.style.height = '';
        document.body.style.top = '';
        
        // Reset main content
        const mainContent = document.querySelector('.main-content');
        if (mainContent) {
            mainContent.style.transform = '';
            mainContent.style.marginLeft = '';
            mainContent.style.width = '';
        }
        
        // Remove any leftover backdrop
        const backdrops = document.querySelectorAll('.modal-backdrop');
        backdrops.forEach(backdrop => backdrop.remove());
    });
    
    // ===== SIMPLE TAP TO VIEW ON MOBILE CARDS =====
    function setupMobileTapToView() {
        // Only run on mobile screens (768px and below)
        const isMobile = window.innerWidth <= 768;
        const poRows = document.querySelectorAll('#poTable tbody tr.po-row');
        
        if (isMobile) {
            poRows.forEach(row => {
                // Only add if not already has the event
                if (!row.hasAttribute('data-mobile-listener')) {
                    row.setAttribute('data-mobile-listener', 'true');
                    row.addEventListener('click', handleMobileRowClick);
                    // Add cursor style for better UX
                    row.style.cursor = 'pointer';
                }
                // Only show if row is not hidden by filter
                if (row.style.display !== 'none') {
                    row.style.display = '';
                }
            });
        } else {
            // Desktop: Remove mobile listeners
            poRows.forEach(row => {
                if (row.hasAttribute('data-mobile-listener')) {
                    row.removeEventListener('click', handleMobileRowClick);
                    row.removeAttribute('data-mobile-listener');
                    row.style.cursor = '';
                }
            });
        }
    }

    function handleMobileRowClick(event) {
        // Prevent if the click was on an action button
        if (event.target.closest('.btn-action') || event.target.closest('.action-btn')) {
            return;
        }
        
        // Get the PO ID from the row's data attribute
        const poId = this.getAttribute('data-id');
        
        if (poId) {
            // Call the existing viewPO function
            viewPO(parseInt(poId));
        }
    }

    // Initialize on page load
    document.addEventListener('DOMContentLoaded', function() {
        setTimeout(setupMobileTapToView, 100);
    });

    // Re-run when window resizes (for orientation change)
    let resizeTimeout;
    window.addEventListener('resize', function() {
        clearTimeout(resizeTimeout);
        resizeTimeout = setTimeout(function() {
            setupMobileTapToView();
        }, 250);
    });

    // Also run when table content changes (for dynamic content like filters)
    const observer = new MutationObserver(function(mutations) {
        mutations.forEach(function(mutation) {
            if (mutation.type === 'childList' || mutation.type === 'subtree') {
                setupMobileTapToView();
            }
        });
    });

    // Observe the table body for changes
    const tableBody = document.querySelector('#poTable tbody');
    if (tableBody) {
        observer.observe(tableBody, { childList: true, subtree: true });
    }

    // Also observe the table container for filter changes
    const tableContainer = document.querySelector('#poTable');
    if (tableContainer) {
        observer.observe(tableContainer, { childList: true, subtree: true, attributes: true });
    }
    // ===== FORCE REFRESH MOBILE DISPLAY AFTER FILTER =====
function refreshMobileDisplay() {
    if (window.innerWidth <= 768) {
        const rows = document.querySelectorAll('#poTable tbody tr.po-row');
        rows.forEach(row => {
            // Force reflow para ma-apply ang CSS
            if (row.style.display !== 'none') {
                row.style.display = '';
                // Force browser to recalculate
                void row.offsetHeight;
            }
        });
    }
}

// Override the filter function to refresh display
const originalFilterTable = window.filterTable;
window.filterTable = function() {
    if (typeof originalFilterTable === 'function') {
        originalFilterTable();
    }
    setTimeout(refreshMobileDisplay, 50);
};

// Also call refresh after any filter change
document.addEventListener('DOMContentLoaded', function() {
    const statusFilter = document.getElementById('filterStatus');
    const supplierFilter = document.getElementById('filterSupplier');
    const monthFilter = document.getElementById('filterMonth');
    const branchFilter = document.getElementById('filterBranch');
    const searchInput = document.getElementById('searchInput');
    
    const refreshHandler = function() {
        setTimeout(refreshMobileDisplay, 100);
    };
    
    if (statusFilter) statusFilter.addEventListener('change', refreshHandler);
    if (supplierFilter) supplierFilter.addEventListener('change', refreshHandler);
    if (monthFilter) monthFilter.addEventListener('change', refreshHandler);
    if (branchFilter) branchFilter.addEventListener('change', refreshHandler);
    if (searchInput) searchInput.addEventListener('keyup', refreshHandler);
    
    refreshMobileDisplay();
});
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
    if (!sidebar) return false;
    
    if (window.innerWidth <= 992) {
        // Mobile behavior
        const willOpen = !sidebar.classList.contains('active');
        sidebar.classList.toggle('active');
        let overlay = document.querySelector('.sidebar-overlay');
        if (willOpen) {
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
        } else if (overlay) {
            overlay.classList.remove('active');
            setTimeout(function() { overlay.remove(); }, 300);
        }
    } else {
        // Desktop behavior - toggle collapse
        const wasCollapsed = sidebar.classList.contains('collapsed');
        sidebar.classList.toggle('collapsed');
        localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
        
        if (wasCollapsed && !sidebar.classList.contains('collapsed')) {
            sidebar.style.width = '';
            setTimeout(function() {
                if (typeof expandActiveDropdownContainers === 'function') {
                    expandActiveDropdownContainers();
                }
            }, 150);
        } else if (sidebar.classList.contains('collapsed')) {
            document.querySelectorAll('.sidebar .collapse.show').forEach(function(collapse) {
                collapse.classList.remove('show');
                const parentBtn = document.querySelector(`[onclick*="${collapse.id}"]`);
                if (parentBtn) {
                    const arrow = parentBtn.querySelector('.dropdown-arrow');
                    if (arrow) arrow.style.transform = 'translateY(-50%) rotate(0deg)';
                }
            });
        }
    }
    return false;
}

// Make toggleSidebar available to inline onclick handlers and event listeners.
window.toggleSidebar = toggleSidebar;

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

    // ========== RECEIVE INVENTORY FUNCTIONS ==========

    let receiveItems = [];


    function showSystemAlert(title, message, icon = 'warning', options = {}) {
        const finalTitle = title || (
            icon === 'success' ? 'Success' :
            icon === 'error' ? 'Error' :
            icon === 'info' ? 'Notice' :
            'Reminder'
        );

        if (typeof Swal !== 'undefined') {
            return Swal.fire({
                icon: icon,
                title: finalTitle,
                text: message,
                confirmButtonText: options.confirmButtonText || 'OK',
                confirmButtonColor: '#059669',
                cancelButtonColor: '#64748b',
                background: '#ffffff',
                color: '#475569',
                customClass: {
                    popup: 'amgc-swal-popup',
                    title: 'amgc-swal-title',
                    htmlContainer: 'amgc-swal-html',
                    confirmButton: 'amgc-swal-confirm',
                    cancelButton: 'amgc-swal-cancel'
                },
                ...options
            });
        }

        window.alert(message);
        return Promise.resolve();
    }

    function getSupplierName() {
        const supplierDropdown = document.getElementById('supplierDropdown');
        if (!supplierDropdown) return '';
        const opt = supplierDropdown.options[supplierDropdown.selectedIndex];
        return opt ? ((opt.getAttribute('data-name') || opt.text || '').trim()) : '';
    }

    function getActiveReceiveMode() {
        const activeTab = document.querySelector('.receive-tabs .nav-link.active');
        if (activeTab && activeTab.id === 'productionTab') return 'production';
        if (activeTab && activeTab.id === 'rmrTab') return 'rmr';
        return 'supplier';
    }

    function getReceiveFieldSet(mode = null) {
        const activeMode = mode || getActiveReceiveMode();
        if (activeMode === 'rmr') {
            return {
                selector: null,
                qty: null,
                newItemNameWrapper: null,
                newItemName: null,
                itemCode: null,
                itemDescription: null,
                itemCategory: null,
                itemUomSelectWrapper: null,
                itemUoM: null,
                newItemUomWrapper: null,
                newItemUom: null,
                itemPrice: null,
                selectedItemPreview: document.getElementById('rmrRequestPreview')
            };
        }
        if (activeMode === 'production') {
            return {
                selector: document.getElementById('productionItemSelector'),
                qty: document.getElementById('productionItemQty'),
                newItemNameWrapper: document.getElementById('productionNewItemNameWrapper'),
                newItemName: document.getElementById('productionNewItemName'),
                itemCode: document.getElementById('productionItemCode'),
                itemDescription: document.getElementById('productionItemDescription'),
                itemCategory: document.getElementById('productionItemCategory'),
                itemUomSelectWrapper: document.getElementById('productionItemUomSelectWrapper'),
                itemUoM: document.getElementById('productionItemUoM'),
                newItemUomWrapper: document.getElementById('productionNewItemUomWrapper'),
                newItemUom: document.getElementById('productionNewItemUom'),
                itemPrice: document.getElementById('productionItemPrice'),
                selectedItemPreview: document.getElementById('productionSelectedItemPreview')
            };
        }
        return {
            selector: document.getElementById('itemSelector'),
            qty: document.getElementById('itemQty'),
            newItemNameWrapper: document.getElementById('newItemNameWrapper'),
            newItemName: document.getElementById('newItemName'),
            itemCode: document.getElementById('itemCode'),
            itemDescription: document.getElementById('itemDescription'),
            itemCategory: document.getElementById('itemCategory'),
            itemUomSelectWrapper: document.getElementById('itemUomSelectWrapper'),
            itemUoM: document.getElementById('itemUoM'),
            newItemUomWrapper: document.getElementById('newItemUomWrapper'),
            newItemUom: document.getElementById('newItemUom'),
            itemPrice: document.getElementById('itemPrice'),
            selectedItemPreview: document.getElementById('selectedItemPreview')
        };
    }

    function getSelectedReceiveItem(mode = null) {
        const fields = getReceiveFieldSet(mode);
        const selectedValue = fields.selector ? fields.selector.value : '';
        if (!selectedValue || selectedValue === '__new__') return null;
        return itemsList.find(item => String(item.item_id) === String(selectedValue)) || null;
    }

    function fillReceivePODropdown() {
        const supplierDropdown = document.getElementById('supplierDropdown');
        const selectedOption = supplierDropdown ? supplierDropdown.options[supplierDropdown.selectedIndex] : null;
        const supplierName = selectedOption ? (selectedOption.getAttribute('data-name') || selectedOption.text.trim()) : '';
        const supplierId = selectedOption ? String(selectedOption.value || '').trim() : '';
        const poDropdown = document.getElementById('poNumberDropdown');
        poDropdown.innerHTML = '<option value="">-- Select PO --</option>';
        if (!Array.isArray(purchaseOrdersList) || purchaseOrdersList.length === 0) return;
        if (!supplierName && !supplierId) return;

        const filteredPOs = purchaseOrdersList.filter(po => {
            const poStatus = String(po.po_status || '').toLowerCase();
            if (poStatus === 'received' || poStatus === 'cancelled') return false;

            const poSupplierId = String(po.supplier_id || '').trim();
            const poSupplierName = String(po.supplier_name || '').trim().toLowerCase();

            if (supplierId && poSupplierId && supplierId === poSupplierId) return true;
            if (supplierName && poSupplierName === supplierName.trim().toLowerCase()) return true;
            return false;
        });

        filteredPOs.forEach(po => {
            const option = document.createElement('option');
            option.value = po.po_id;
            option.textContent = po.po_number;
            option.dataset.poId = po.po_id;
            option.dataset.poNumber = po.po_number;
            poDropdown.appendChild(option);
        });
    }

    function populateUomForSelectedItem(item, mode = null) {
        const fields = getReceiveFieldSet(mode);
        const selectWrap = fields.itemUomSelectWrapper;
        const inputWrap = fields.newItemUomWrapper;
        const select = fields.itemUoM;
        const newInput = fields.newItemUom;
        if (!selectWrap || !inputWrap || !select || !newInput) return;

        select.innerHTML = '<option value="">-- Select UoM --</option>';
        newInput.value = '';

        if (item) {
            const seen = new Set();
            const itemUoms = Array.isArray(item.available_uoms) ? item.available_uoms : [];
            itemUoms.forEach((uomValue) => {
                const norm = String(uomValue || '').trim();
                if (!norm || seen.has(norm.toLowerCase())) return;
                seen.add(norm.toLowerCase());
                const opt = document.createElement('option');
                opt.value = norm;
                opt.textContent = norm;
                if (item.uom_prices && Object.prototype.hasOwnProperty.call(item.uom_prices, norm.toLowerCase())) {
                    opt.dataset.price = item.uom_prices[norm.toLowerCase()];
                }
                select.appendChild(opt);
            });

            const defaultUom = String(item.unit_type || '').trim();
            if (defaultUom) {
                select.value = defaultUom;
            }
            if (!select.value && select.options.length > 1) {
                select.selectedIndex = 1;
            }
            if (select.selectedOptions.length > 0 && select.selectedOptions[0].dataset.price) {
                if (fields.itemPrice) fields.itemPrice.value = Number(select.selectedOptions[0].dataset.price).toFixed(2);
            }

            selectWrap.style.display = '';
            inputWrap.style.display = 'none';
        } else {
            selectWrap.style.display = 'none';
            inputWrap.style.display = '';
        }
    }

    function syncReceiveItemCount() {
        const totalQty = receiveItems.reduce((sum, item) => sum + (parseFloat(item.qty) || 0), 0);
        const supplierTotal = document.getElementById('supplierTotalItems');
        const productionTotal = document.getElementById('productionTotalItems');
        if (supplierTotal) supplierTotal.value = totalQty > 0 ? totalQty : '';
        if (productionTotal) productionTotal.value = totalQty > 0 ? totalQty : '';
    }

    function renderReceiveTable() {
        const tbody = document.getElementById('receiveItemsTableBody');
        if (!tbody) return;
        if (receiveItems.length === 0) {
            tbody.innerHTML = '<tr class="text-center text-muted"><td colspan="9">No items added yet</td></tr>';
            syncReceiveItemCount();
            return;
        }

        tbody.innerHTML = receiveItems.map(item => `
            <tr data-item-id="${item.id}">
                <td>${escapeHtml(String(item.name || ''))}</td>
                <td>${escapeHtml(String(item.code || ''))}</td>
                <td>${escapeHtml(String(item.description || ''))}</td>
                <td>${escapeHtml(String(item.category || ''))}</td>
                <td><input type="number" class="form-control form-control-sm qty-input" data-item-id="${item.id}" value="${item.qty}" min="0" step="0.01" inputmode="decimal"></td>
                <td>${escapeHtml(String(item.uom || ''))}</td>
                <td><input type="number" class="form-control form-control-sm price-input" data-item-id="${item.id}" value="${Number(item.price || 0).toFixed(2)}" min="0" step="0.01" inputmode="decimal"></td>
                <td class="total-price" data-total-id="${item.id}">${formatPeso(item.total || 0)}</td>
                <td><button type="button" class="btn btn-sm btn-danger remove-item-btn" data-item-id="${item.id}"><i class="bi bi-trash"></i></button></td>
            </tr>
        `).join('');
        syncReceiveItemCount();
    }

    function resetReceiveItemEntry(mode = null) {
        const fields = getReceiveFieldSet(mode);
        if (fields.selector) fields.selector.value = '';
        if (fields.newItemName) fields.newItemName.value = '';
        if (fields.itemCode) fields.itemCode.value = '';
        if (fields.itemDescription) fields.itemDescription.value = '';
        if (fields.itemCategory) fields.itemCategory.value = '';
        if (fields.qty) fields.qty.value = '';
        if (fields.itemPrice) fields.itemPrice.value = '';
        if (fields.newItemUom) fields.newItemUom.value = '';
        if (fields.selectedItemPreview) {
            fields.selectedItemPreview.style.display = 'none';
            fields.selectedItemPreview.innerHTML = '';
        }
        handleReceiveItemSelection(mode);
    }

    function handleReceiveItemSelection(mode = null) {
        const fields = getReceiveFieldSet(mode);
        const selectedValue = fields.selector ? fields.selector.value : '';
        const selectedItem = getSelectedReceiveItem(mode);

        if (fields.selectedItemPreview) {
            fields.selectedItemPreview.style.display = 'none';
            fields.selectedItemPreview.innerHTML = '';
        }
        if (fields.itemCode) fields.itemCode.value = '';
        if (fields.itemDescription) fields.itemDescription.value = '';
        if (fields.itemCategory) fields.itemCategory.value = '';
        if (fields.itemPrice) fields.itemPrice.value = '';

        if (selectedValue === '__new__') {
            if (fields.newItemNameWrapper) fields.newItemNameWrapper.style.display = 'block';
            if (fields.itemCode) fields.itemCode.readOnly = false;
            if (fields.itemDescription) fields.itemDescription.readOnly = false;
            if (fields.itemCategory) fields.itemCategory.readOnly = false;
            if (fields.itemPrice) fields.itemPrice.readOnly = false;
            populateUomForSelectedItem(null, mode);
            return;
        }

        if (fields.newItemNameWrapper) fields.newItemNameWrapper.style.display = 'none';
        if (fields.newItemName) fields.newItemName.value = '';

        if (selectedItem) {
            if (fields.itemCode) fields.itemCode.value = selectedItem.item_code || '';
            if (fields.itemDescription) fields.itemDescription.value = selectedItem.description || selectedItem.item_name || '';
            if (fields.itemCategory) fields.itemCategory.value = selectedItem.category || '';
            if (fields.itemPrice) fields.itemPrice.value = Number(selectedItem.price_piece || 0).toFixed(2);
            if (fields.itemCode) fields.itemCode.readOnly = true;
            if (fields.itemDescription) fields.itemDescription.readOnly = true;
            if (fields.itemCategory) fields.itemCategory.readOnly = true;
            if (fields.itemPrice) fields.itemPrice.readOnly = false;
            populateUomForSelectedItem(selectedItem, mode);
            if (fields.selectedItemPreview) {
                fields.selectedItemPreview.innerHTML = `
                    <strong>Selected Item:</strong> ${escapeHtml(String(selectedItem.item_name || ''))}<br>
                    <small>
                        <strong>Code:</strong> ${escapeHtml(String(selectedItem.item_code || 'N/A'))} &nbsp;|&nbsp;
                        <strong>Description:</strong> ${escapeHtml(String(selectedItem.description || selectedItem.item_name || 'N/A'))} &nbsp;|&nbsp;
                        <strong>Category:</strong> ${escapeHtml(String(selectedItem.category || 'N/A'))}
                    </small>`;
                fields.selectedItemPreview.style.display = 'block';
            }
        } else {
            if (fields.itemCode) fields.itemCode.readOnly = false;
            if (fields.itemDescription) fields.itemDescription.readOnly = false;
            if (fields.itemCategory) fields.itemCategory.readOnly = false;
            if (fields.itemPrice) fields.itemPrice.readOnly = false;
            populateUomForSelectedItem(null, mode);
        }
    }

    function addItemToTable(mode = null) {
        const fields = getReceiveFieldSet(mode);
        const selectorValue = fields.selector ? fields.selector.value : '';
        const selectedItem = getSelectedReceiveItem(mode);
        const isNewItem = selectorValue === '__new__';
        const itemName = isNewItem ? ((fields.newItemName ? fields.newItemName.value : '') || '').trim() : (selectedItem?.item_name || '');
        const itemCode = ((fields.itemCode ? fields.itemCode.value : '') || '').trim();
        const itemDescription = ((fields.itemDescription ? fields.itemDescription.value : '') || '').trim();
        const itemCategory = ((fields.itemCategory ? fields.itemCategory.value : '') || '').trim();
        const qty = parseFloat(fields.qty ? fields.qty.value : 0) || 0;
        const uom = isNewItem ? (((fields.newItemUom || {}).value || '').trim()) : ((((fields.itemUoM || {}).value || '').trim()));
        const price = parseFloat(fields.itemPrice ? fields.itemPrice.value : 0) || 0;

        if (!selectorValue) {
            showSystemAlert('Reminder', 'Please select an item first.', 'warning');
            return;
        }
        if (isNewItem && !itemName) {
            showSystemAlert('Reminder', 'Please enter the new item name.', 'warning');
            return;
        }
        if (!itemName || !itemCode || !itemDescription || !itemCategory || !uom) {
            showSystemAlert('Reminder', 'Please complete the item details.', 'warning');
            return;
        }
        if (qty <= 0) {
            showSystemAlert('Reminder', 'Quantity must be greater than 0.', 'warning');
            return;
        }

        receiveItems.push({
            id: Date.now() + Math.floor(Math.random() * 1000),
            item_id: selectedItem ? selectedItem.item_id : null,
            is_new: isNewItem,
            name: itemName,
            code: itemCode,
            description: itemDescription,
            category: itemCategory,
            qty: qty,
            uom: uom,
            price: price,
            total: qty * price
        });

        renderReceiveTable();
        resetReceiveItemEntry(mode);
        if (fields.selector) fields.selector.focus();
    }

    function refreshReceiveItemTotalCell(itemId) {
        const item = receiveItems.find(i => String(i.id) === String(itemId));
        if (!item) return;
        const totalCell = document.querySelector(`[data-total-id="${CSS.escape(String(itemId))}"]`);
        if (totalCell) {
            totalCell.textContent = formatPeso(item.total || 0);
        }
        syncReceiveItemCount();
    }

    function updateItemQty(itemId, newQty) {
        const item = receiveItems.find(i => String(i.id) === String(itemId));
        if (!item) return;
        item.qty = parseFloat(newQty) || 0;
        item.total = (parseFloat(item.qty) || 0) * (parseFloat(item.price) || 0);
        refreshReceiveItemTotalCell(itemId);
    }

    function updateItemPrice(itemId, newPrice) {
        const item = receiveItems.find(i => String(i.id) === String(itemId));
        if (!item) return;
        item.price = parseFloat(newPrice) || 0;
        item.total = (parseFloat(item.qty) || 0) * (parseFloat(item.price) || 0);
        refreshReceiveItemTotalCell(itemId);
    }

    function removeItem(itemId) {
        receiveItems = receiveItems.filter(i => String(i.id) !== String(itemId));
        renderReceiveTable();
    }

    document.getElementById('supplierDropdown').addEventListener('change', fillReceivePODropdown);
    fillReceivePODropdown();
    document.getElementById('itemSelector').addEventListener('change', function() { handleReceiveItemSelection('supplier'); });
    document.getElementById('productionItemSelector').addEventListener('change', function() { handleReceiveItemSelection('production'); });
    document.getElementById('itemUoM').addEventListener('change', function() {
        const selectedItem = getSelectedReceiveItem('supplier');
        const selectedUom = String(this.value || '').trim().toLowerCase();
        if (selectedItem && selectedItem.uom_prices && Object.prototype.hasOwnProperty.call(selectedItem.uom_prices, selectedUom)) {
            document.getElementById('itemPrice').value = Number(selectedItem.uom_prices[selectedUom] || 0).toFixed(2);
        }
    });
    document.getElementById('productionItemUoM').addEventListener('change', function() {
        const selectedItem = getSelectedReceiveItem('production');
        const selectedUom = String(this.value || '').trim().toLowerCase();
        if (selectedItem && selectedItem.uom_prices && Object.prototype.hasOwnProperty.call(selectedItem.uom_prices, selectedUom)) {
            document.getElementById('productionItemPrice').value = Number(selectedItem.uom_prices[selectedUom] || 0).toFixed(2);
        }
    });
    document.getElementById('addReceiveItemBtn').addEventListener('click', function() { addItemToTable('supplier'); });
    document.getElementById('addProductionReceiveItemBtn').addEventListener('click', function() { addItemToTable('production'); });

    document.getElementById('poNumberDropdown').addEventListener('change', function() {
        const poId = this.value;
        if (!poId) {
            receiveItems = [];
            renderReceiveTable();
            resetReceiveItemEntry('supplier');
        resetReceiveItemEntry('production');
        const rmrPreview = document.getElementById('rmrRequestPreview');
        if (rmrPreview) {
            rmrPreview.style.display = 'none';
            rmrPreview.innerHTML = '';
        }
            return;
        }

        const selected = purchaseOrdersList.find(po => String(po.po_id) === String(poId));
        if (!selected) return;

        if (selected.supplier_id) {
            document.getElementById('supplierDropdown').value = String(selected.supplier_id);
        }
        if (selected.order_date) {
            document.getElementById('supplierReceiveDate').value = String(selected.order_date).split(' ')[0];
        }
        const supplierItemNameField = document.getElementById('supplierItemName');
        if (supplierItemNameField) {
            supplierItemNameField.value = selected.supplier_name || '';
        }

        const payload = new URLSearchParams();
        payload.append('action', 'get_po');
        payload.append('po_id', poId);

        fetch(window.location.href, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: payload.toString()
        })
        .then(response => response.json())
        .then(data => {
            if (!data.success) throw new Error(data.message || 'Unable to load PO details.');

            if (data.po && data.po.supplier_id) {
                document.getElementById('supplierDropdown').value = String(data.po.supplier_id);
            }
            if (data.po && data.po.order_date) {
                document.getElementById('supplierReceiveDate').value = String(data.po.order_date).split(' ')[0];
            }

            receiveItems = (data.items || []).map((item, idx) => ({
                id: Date.now() + idx,
                item_id: item.item_id || null,
                is_new: false,
                name: item.item_name || '',
                code: item.item_code || '',
                description: item.description || item.item_name || '',
                category: item.category || '',
                qty: parseFloat(item.quantity_ordered || 0),
                uom: item.unit_type || '',
                price: parseFloat(item.unit_price || 0),
                total: (parseFloat(item.quantity_ordered || 0) * parseFloat(item.unit_price || 0))
            }));

            renderReceiveTable();
            resetReceiveItemEntry('supplier');
        resetReceiveItemEntry('production');
        })
        .catch(error => {
            if (typeof Swal !== 'undefined') showSystemAlert('Error', error.message || 'Unable to load PO details.', 'error');
            else showSystemAlert('Error', error.message || 'Unable to load PO details.', 'error');
        });
    });

    document.getElementById('withPOCheckbox').addEventListener('change', function() {
        const poField = document.getElementById('poNumberDropdown').parentElement;
        if (this.checked) {
            poField.style.display = 'block';
            document.getElementById('poNumberDropdown').required = true;
        } else {
            poField.style.display = 'none';
            document.getElementById('poNumberDropdown').required = false;
            document.getElementById('poNumberDropdown').value = '';
        }
    });

    document.getElementById('receiveItemsTableBody').addEventListener('input', function(e) {
        if (e.target.classList.contains('qty-input')) {
            updateItemQty(e.target.dataset.itemId, e.target.value);
        }
        if (e.target.classList.contains('price-input')) {
            updateItemPrice(e.target.dataset.itemId, e.target.value);
        }
    });


    document.getElementById('receiveItemsTableBody').addEventListener('click', function(e) {
        const btn = e.target.closest('.remove-item-btn');
        if (btn) {
            removeItem(btn.dataset.itemId);
        }
    });

    function formatReceiveHistoryValue(value, fallback = 'N/A') {
        const normalized = (value === null || value === undefined) ? '' : String(value).trim();
        return normalized !== '' ? escapeHtml(normalized) : fallback;
    }

    function renderReceiveAttachments(attachments, fallbackText) {
        if (!Array.isArray(attachments) || attachments.length === 0) {
            return `<span>${escapeHtml(fallbackText || 'No saved attachment found for this receive record.')}</span>`;
        }
        return `
            <div class="d-flex flex-column gap-3">
                ${attachments.map(file => {
                    const fileName = escapeHtml(file.original_name || file.stored_name || 'Attachment');
                    const fileUrl = escapeHtml(file.url || '#');
                    const lower = String(file.original_name || file.stored_name || '').toLowerCase();
                    const isImage = ['.jpg','.jpeg','.png','.webp','.gif'].some(ext => lower.endsWith(ext));
                    return `
                        <div class="border rounded p-2 bg-light">
                            <div class="fw-semibold mb-2">${fileName}</div>
                            ${isImage ? `<div class="mb-2"><img src="${fileUrl}" alt="${fileName}" style="max-width:100%; max-height:320px; border-radius:8px; border:1px solid #dee2e6;"></div>` : ''}
                            <div><a href="${fileUrl}" target="_blank" rel="noopener noreferrer">Open Attachment</a></div>
                        </div>
                    `;
                }).join('')}
            </div>
        `;
    }

    let activeReceiveHistoryDeletePayload = null;

    function openReceiveHistoryDetails(transactionId, rowData = {}) {
        const modalBody = document.getElementById('receiveHistoryModalBody');
        const deleteBtn = document.getElementById('deleteReceiveHistoryBtn');
        activeReceiveHistoryDeletePayload = {
            transaction_id: transactionId || '',
            item_id: rowData.itemId || '',
            reference_type: rowData.referenceType || '',
            reference_id: rowData.referenceId || '',
            created_at: rowData.createdAt || ''
        };
        if (deleteBtn) {
            deleteBtn.style.display = 'none';
            deleteBtn.disabled = false;
            deleteBtn.innerHTML = '<i class="bi bi-trash me-1"></i> Delete Receive';
        }
        if (modalBody) {
            modalBody.innerHTML = '<div class="text-center text-muted py-4">Loading details...</div>';
        }

        const payload = new URLSearchParams();
        payload.append('action', 'get_receive_history_details');
        if (transactionId) payload.append('transaction_id', transactionId);
        if (rowData.itemId) payload.append('item_id', rowData.itemId);
        if (rowData.referenceType) payload.append('reference_type', rowData.referenceType);
        if (rowData.referenceId) payload.append('reference_id', rowData.referenceId);
        if (rowData.createdAt) payload.append('created_at', rowData.createdAt);

        fetch(window.location.href, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: payload.toString()
        })
        .then(response => response.text())
        .then(text => {
            let data;
            try {
                data = JSON.parse(text);
            } catch (e) {
                throw new Error(text || 'Failed to parse receive history response.');
            }
            if (!data.success) throw new Error(data.message || 'Unable to load receive history details.');

            const detail = data.detail || {};
            const poItems = Array.isArray(data.po_items) ? data.po_items : [];
            const refType = String(detail.reference_type || '').toLowerCase();
            const sourceLabel = refType === 'production' ? 'Production' : (refType === 'rmr' ? 'Returned Merchandise' : 'Supplier');
            const unitPrice = Number(detail.unit_cost || detail.unit_price || 0);
            const qty = Number(detail.quantity_changed || 0);
            const savedTotalCost = Number(detail.total_cost || 0);
            const totalAmount = savedTotalCost > 0 ? savedTotalCost : (qty * unitPrice);

            let itemsHtml = '';
            if (poItems.length > 0) {
                itemsHtml = `
                    <div class="table-responsive mt-3">
                        <table class="table table-bordered align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Item Name</th>
                                    <th>Item Code</th>
                                    <th>Description</th>
                                    <th>Qty</th>
                                    <th>UoM</th>
                                    <th>Unit Cost</th>
                                    <th>Total Cost</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${poItems.map(item => `
                                    <tr>
                                        <td>${formatReceiveHistoryValue(item.item_name)}</td>
                                        <td>${formatReceiveHistoryValue(item.item_code)}</td>
                                        <td>${formatReceiveHistoryValue(item.item_description)}</td>
                                        <td>${formatReceiveHistoryValue(item.quantity_ordered)}</td>
                                        <td>${formatReceiveHistoryValue(item.unit_type)}</td>
                                        <td>${formatPeso(item.unit_price || 0)}</td>
                                        <td>${formatPeso((Number(item.quantity_ordered || 0) * Number(item.unit_price || 0)) || 0)}</td>
                                    </tr>
                                `).join('')}
                            </tbody>
                        </table>
                    </div>`;
            } else {
                itemsHtml = `
                    <div class="table-responsive mt-3">
                        <table class="table table-bordered align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Item Name</th>
                                    <th>Item Code</th>
                                    <th>Description</th>
                                    <th>Qty</th>
                                    <th>UoM</th>
                                    <th>Unit Cost</th>
                                    <th>Total Price</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td>${formatReceiveHistoryValue(detail.item_name)}</td>
                                    <td>${formatReceiveHistoryValue(detail.item_code)}</td>
                                    <td>${formatReceiveHistoryValue(detail.item_description)}</td>
                                    <td>${qty.toFixed(2).replace(/\.00$/, '')}</td>
                                    <td>${formatReceiveHistoryValue(detail.unit_type)}</td>
                                    <td>${formatPeso(unitPrice)}</td>
                                    <td>${formatPeso(totalAmount)}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>`;
            }

            const html = `
                <div class="row g-3">
                    <div class="col-md-6"><div class="border rounded p-3 h-100"><div class="text-muted small mb-1">Source</div><div class="fw-semibold">${sourceLabel}</div></div></div>
                    <div class="col-md-6"><div class="border rounded p-3 h-100"><div class="text-muted small mb-1">Received By</div><div class="fw-semibold">${formatReceiveHistoryValue(detail.received_by_name)}</div></div></div>
                    <div class="col-md-6"><div class="border rounded p-3 h-100"><div class="text-muted small mb-1">Receive Date</div><div class="fw-semibold">${formatReceiveHistoryValue(detail.receive_date || detail.created_at)}</div></div></div>
                    <div class="col-md-6"><div class="border rounded p-3 h-100"><div class="text-muted small mb-1">Date Encoded</div><div class="fw-semibold">${formatReceiveHistoryValue(detail.encoded_date || detail.created_at)}</div></div></div>
                    <div class="col-md-6"><div class="border rounded p-3 h-100"><div class="text-muted small mb-1">Branch</div><div class="fw-semibold">${formatReceiveHistoryValue(detail.branch_name)}</div></div></div>
                    <div class="col-md-6"><div class="border rounded p-3 h-100"><div class="text-muted small mb-1">Supplier</div><div class="fw-semibold">${sourceLabel === 'Supplier' ? formatReceiveHistoryValue(detail.supplier_name) : 'N/A'}</div></div></div>
                    <div class="col-md-6"><div class="border rounded p-3 h-100"><div class="text-muted small mb-1">PO Number</div><div class="fw-semibold">${sourceLabel === 'Supplier' ? formatReceiveHistoryValue(detail.po_number) : 'N/A'}</div></div></div>
                    <div class="col-md-6"><div class="border rounded p-3 h-100"><div class="text-muted small mb-1">RMR Number</div><div class="fw-semibold">${sourceLabel === 'Returned Merchandise' ? formatReceiveHistoryValue(detail.rmr_number) : 'N/A'}</div></div></div>
                    <div class="col-md-6"><div class="border rounded p-3 h-100"><div class="text-muted small mb-1">Customer</div><div class="fw-semibold">${sourceLabel === 'Returned Merchandise' ? formatReceiveHistoryValue(detail.rmr_customer_name) : 'N/A'}</div></div></div>
                    <div class="col-md-6"><div class="border rounded p-3 h-100"><div class="text-muted small mb-1">PO Status</div><div class="fw-semibold">${sourceLabel === 'Supplier' ? formatReceiveHistoryValue(detail.po_status) : 'N/A'}</div></div></div>
                    <div class="col-md-6"><div class="border rounded p-3 h-100"><div class="text-muted small mb-1">Reference</div><div class="fw-semibold">${formatReceiveHistoryValue(detail.reference_type)} #${formatReceiveHistoryValue(detail.reference_id, '0')}</div></div></div>
                    <div class="col-12"><div class="border rounded p-3 h-100"><div class="text-muted small mb-1">Memo</div><div class="fw-semibold" style="white-space: pre-wrap; word-break: break-word;">${formatReceiveHistoryValue(detail.receive_memo)}</div></div></div>
                    <div class="col-12"><div class="border rounded p-3 h-100"><div class="text-muted small mb-1">Attachment</div><div class="fw-semibold">${renderReceiveAttachments(data.attachments || [], data.attachment_note || 'No saved attachment found for this receive record.')}</div></div></div>
                    <div class="col-12"><div class="border rounded p-3 h-100"><div class="text-muted small mb-2">What was done</div><div class="fw-semibold">Stock was received and posted to current inventory. An inventory transaction record was saved for this item.</div></div></div>
                    <div class="col-12"><div class="border rounded p-3"> <div class="text-muted small mb-2">Received Item Details</div>${itemsHtml}</div></div>
                </div>`;

            activeReceiveHistoryDeletePayload = {
                transaction_id: detail.transaction_id || transactionId || '',
                item_id: detail.item_id || rowData.itemId || '',
                reference_type: detail.reference_type || rowData.referenceType || '',
                reference_id: detail.reference_id || rowData.referenceId || '',
                created_at: detail.created_at || rowData.createdAt || ''
            };

            if (modalBody) modalBody.innerHTML = html;
            if (deleteBtn) {
                deleteBtn.style.display = 'inline-block';
            }
            new bootstrap.Modal(document.getElementById('receiveHistoryModal')).show();
        })
        .catch(error => {
            if (typeof Swal !== 'undefined') {
                showSystemAlert('Error', error.message || 'Unable to load receive history details.', 'error');
            } else {
                showSystemAlert('Error', error.message || 'Unable to load receive history details.', 'error');
            }
        });
    }



    
    const receiveHistoryReportRows = <?php echo json_encode(array_map(function($row) {
        return [
            'item_name' => $row['item_name'] ?? 'Unknown Item',
            'quantity_changed' => $row['quantity_changed'] ?? 0,
            'unit_cost' => $row['unit_cost'] ?? 0,
            'total_cost' => $row['total_cost'] ?? 0,
            'created_at' => formatDateTime($row['created_at'] ?? ''),
            'receive_date' => formatDate($row['receive_date'] ?? ($row['created_at'] ?? '')),
            'receive_date_raw' => !empty($row['receive_date']) ? date('Y-m-d', strtotime($row['receive_date'])) : (!empty($row['created_at']) ? date('Y-m-d', strtotime($row['created_at'])) : ''),
            'encoded_date' => formatDate($row['encoded_date'] ?? ($row['created_at'] ?? '')),
            'encoded_date_raw' => !empty($row['encoded_date']) ? date('Y-m-d', strtotime($row['encoded_date'])) : (!empty($row['created_at']) ? date('Y-m-d', strtotime($row['created_at'])) : ''),
            'unit_type' => $row['unit_type'] ?? '',
            'received_by_name' => trim($row['received_by_name'] ?? '') !== '' ? trim($row['received_by_name'] ?? '') : 'Unknown',
            'reference_type' => $row['reference_type'] ?? '',
            'po_number' => $row['po_number'] ?? '',
            'supplier_name' => $row['supplier_name'] ?? '',
            'rmr_number' => $row['rmr_number'] ?? '',
            'rmr_customer_name' => $row['rmr_customer_name'] ?? ''
        ];
    }, $receive_history_list), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;

    function receiveReportEscape(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function receiveReportCurrency(value) {
        const num = Number(value || 0);
        return '₱' + num.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function receiveReportQty(value) {
        const num = Number(value || 0);
        return num.toLocaleString(undefined, { maximumFractionDigits: 2 });
    }

    function receiveReportSource(row) {
        if ((row.reference_type || '').toLowerCase() === 'purchase_order') {
            return row.po_number ? `PO: ${row.po_number}` : 'Purchase Order';
        }
        if ((row.reference_type || '').toLowerCase() === 'rmr') {
            return row.rmr_number ? `RMR: ${row.rmr_number}` : 'RMR';
        }
        return 'Production';
    }

    function getReceiveHistoryFilteredReportRows() {
        const searchInput = document.getElementById('receiveHistorySearch');
        const sourceFilterInput = document.getElementById('receiveHistorySourceFilter');
        const encoderFilterInput = document.getElementById('receiveHistoryEncoderFilter');
        const dateFromInput = document.getElementById('receiveHistoryDateFrom');
        const dateToInput = document.getElementById('receiveHistoryDateTo');

        const searchTerm = (searchInput?.value || '').trim().toLowerCase();
        const sourceFilter = sourceFilterInput?.value || 'all';
        const encoderFilter = encoderFilterInput?.value || 'all';
        const dateFrom = dateFromInput?.value || '';
        const dateTo = dateToInput?.value || '';
        const rows = Array.isArray(receiveHistoryReportRows) ? receiveHistoryReportRows : [];

        return rows.filter(row => {
            const rowDate = row.receive_date_raw || row.encoded_date_raw || '';
            const rowSource = row.reference_type || '';
            const rowEncoder = row.received_by_name || 'Unknown';
            const rowSearch = [
                row.item_name,
                row.quantity_changed,
                row.unit_cost,
                row.total_cost,
                row.unit_type,
                row.received_by_name,
                row.reference_type,
                row.po_number,
                row.supplier_name,
                row.rmr_number,
                row.rmr_customer_name,
                row.receive_date,
                row.encoded_date
            ].join(' ').toLowerCase();

            const matchesSearch = searchTerm === '' || rowSearch.includes(searchTerm);
            const matchesSource = sourceFilter === 'all' || rowSource === sourceFilter;
            const matchesEncoder = encoderFilter === 'all' || rowEncoder === encoderFilter;
            const matchesDateFrom = dateFrom === '' || (rowDate !== '' && rowDate >= dateFrom);
            const matchesDateTo = dateTo === '' || (rowDate !== '' && rowDate <= dateTo);

            return matchesSearch && matchesSource && matchesEncoder && matchesDateFrom && matchesDateTo;
        });
    }

    function buildReceiveHistoryReportPreview() {
        const rows = getReceiveHistoryFilteredReportRows();
        const totalQty = rows.reduce((sum, row) => sum + Number(row.quantity_changed || 0), 0);
        const totalCost = rows.reduce((sum, row) => sum + Number(row.total_cost || 0), 0);

        let html = `
            <div class="plain-report-header">
                <h4>A. MACALINDONG DEVELOPMENT CORP.</h4>
                <div class="report-title">Receive History Report</div>
            </div>

            <table class="plain-report-meta">
                <tr>
                    <td><strong>Branch:</strong> <?= htmlspecialchars($branch_name, ENT_QUOTES) ?></td>
                    <td><strong>Printed Date:</strong> ${new Date().toLocaleString()}</td>
                </tr>
                <tr>
                    <td><strong>Printed By:</strong> <?= htmlspecialchars($user_name, ENT_QUOTES) ?></td>
                    <td><strong>Total Records:</strong> ${rows.length}</td>
                </tr>
            </table>

            <div class="plain-report-summary">
                <strong>Total Qty:</strong> ${receiveReportQty(totalQty)} &nbsp; | &nbsp;
                <strong>Total Cost:</strong> ${receiveReportCurrency(totalCost)}
            </div>

            <table class="plain-report-table">
                <thead>
                    <tr>
                        <th>Receive Date</th>
                        <th>Date Encoded</th>
                        <th>Item Name</th>
                        <th>Source</th>
                        <th>Supplier/Customer</th>
                        <th>Qty</th>
                        <th>UoM</th>
                        <th style="text-align:right;">Unit Cost</th>
                        <th style="text-align:right;">Total Cost</th>
                        <th>Encoded By</th>
                    </tr>
                </thead>
                <tbody>
        `;

        if (!rows.length) {
            html += `<tr><td colspan="10" style="text-align:center;padding:14px;">No receive history found yet.</td></tr>`;
        } else {
            rows.forEach(row => {
                const supplierCustomer = row.supplier_name || row.rmr_customer_name || '-';
                html += `
                    <tr>
                        <td>${receiveReportEscape(row.receive_date || row.created_at || '-')}</td>
                        <td>${receiveReportEscape(row.encoded_date || row.created_at || '-')}</td>
                        <td>${receiveReportEscape(row.item_name || '-')}</td>
                        <td>${receiveReportEscape(receiveReportSource(row))}</td>
                        <td>${receiveReportEscape(supplierCustomer)}</td>
                        <td>${receiveReportQty(row.quantity_changed)}</td>
                        <td>${receiveReportEscape(row.unit_type || '-')}</td>
                        <td style="text-align:right;">${receiveReportCurrency(row.unit_cost)}</td>
                        <td style="text-align:right;">${receiveReportCurrency(row.total_cost)}</td>
                        <td>${receiveReportEscape(row.received_by_name || 'Unknown')}</td>
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
                        <th style="width:160px;text-align:right;white-space:nowrap;">${receiveReportCurrency(totalCost)}</th>
                    </tr>
                </tbody>
            </table>
        `;

        const printable = document.getElementById('receiveHistoryPrintable');
        if (printable) printable.innerHTML = html;
    }

    window.printReceiveHistoryTable = function() {
        buildReceiveHistoryReportPreview();

        const printable = document.getElementById('receiveHistoryPrintable');
        if (!printable || !printable.innerHTML.trim()) {
            if (typeof showSystemAlert === 'function') {
                showSystemAlert('Error', 'No printable Receive History content found.', 'error');
            } else {
                alert('No printable Receive History content found.');
            }
            return;
        }

        const printFrameId = 'receiveHistoryPrintFrame';
        let printFrame = document.getElementById(printFrameId);
        if (!printFrame) {
            printFrame = document.createElement('iframe');
            printFrame.id = printFrameId;
            printFrame.name = printFrameId;
            printFrame.style.position = 'fixed';
            printFrame.style.right = '0';
            printFrame.style.bottom = '0';
            printFrame.style.width = '0';
            printFrame.style.height = '0';
            printFrame.style.border = '0';
            printFrame.style.opacity = '0';
            document.body.appendChild(printFrame);
        }

        const frameDoc = printFrame.contentWindow || printFrame.contentDocument;
        const doc = printFrame.contentDocument || printFrame.contentWindow.document;

        doc.open();
        doc.write(`<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Receive History Report</title>
    <style>
        * {
            box-sizing: border-box;
        }
        body {
            font-family: Arial, Helvetica, sans-serif;
            margin: 18px;
            color: #111;
            background: #fff;
            font-size: 12px;
        }
        .plain-report-header {
            text-align: center;
            margin-bottom: 12px;
        }
        .plain-report-header h4 {
            margin: 0;
            font-size: 16px;
            font-weight: 700;
        }
        .report-title {
            margin-top: 4px;
            font-size: 14px;
            font-weight: 700;
        }
        .plain-report-meta {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 10px;
            font-size: 11px;
        }
        .plain-report-meta td {
            border: none;
            padding: 2px 0;
        }
        .plain-report-summary {
            margin: 8px 0 12px 0;
            font-size: 12px;
        }
        .plain-report-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 10px;
        }
        .plain-report-table th,
        .plain-report-table td {
            border: 1px solid #333;
            padding: 5px 6px;
            color: #111;
            background: #fff;
            vertical-align: top;
        }
        .plain-report-table th {
            font-weight: 700;
            text-align: left;
        }
        @page {
            size: landscape;
            margin: 12mm;
        }
    </style>
</head>
<body>
    ${printable.innerHTML}
</body>
</html>`);
        doc.close();

        setTimeout(function() {
            printFrame.contentWindow.focus();
            printFrame.contentWindow.print();
        }, 250);
    };

    function bindReceiveHistoryPrintButton() {
        const printReceiveHistoryBtn = document.getElementById('printReceiveHistoryBtn');
        if (!printReceiveHistoryBtn || printReceiveHistoryBtn.dataset.printBound === '1') return;

        printReceiveHistoryBtn.dataset.printBound = '1';
        printReceiveHistoryBtn.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            window.printReceiveHistoryTable();
        });
    }

    bindReceiveHistoryPrintButton();

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bindReceiveHistoryPrintButton);
    } else {
        bindReceiveHistoryPrintButton();
    }

    document.addEventListener('click', function(e) {
        const btn = e.target.closest('#printReceiveHistoryBtn');
        if (!btn) return;
        e.preventDefault();
        e.stopPropagation();
        window.printReceiveHistoryTable();
    });


function previewSelectedRmrRequest() {
        const dropdown = document.getElementById('rmrRequestDropdown');
        const preview = document.getElementById('rmrRequestPreview');
        if (!dropdown || !preview) return;
        const opt = dropdown.options[dropdown.selectedIndex];
        if (!opt || !opt.value) {
            preview.style.display = 'none';
            preview.innerHTML = '';
            return;
        }
        preview.innerHTML = `
            <strong>Selected RMR:</strong> ${escapeHtml(opt.textContent.trim())}<br>
            <small>
                <strong>Customer:</strong> ${escapeHtml(opt.dataset.customer || 'N/A')} &nbsp;|&nbsp;
                <strong>Status:</strong> ${escapeHtml(opt.dataset.status || 'N/A')} &nbsp;|&nbsp;
                <strong>Reason:</strong> ${escapeHtml(opt.dataset.reason || 'N/A')}
            </small>`;
        preview.style.display = 'block';
    }

    function addSelectedRmrItemToTable() {
        const dropdown = document.getElementById('rmrRequestDropdown');
        if (!dropdown || !dropdown.value) {
            showSystemAlert('Reminder', 'Please select an RMR request first.', 'warning');
            return;
        }
        const opt = dropdown.options[dropdown.selectedIndex];
        const itemId = parseInt(opt.dataset.itemId || '0', 10);
        const qty = parseFloat(opt.dataset.qty || '0');
        const price = parseFloat(opt.dataset.price || '0');
        if (!itemId || qty <= 0) {
            showSystemAlert('Reminder', 'Selected RMR request has invalid item or quantity.', 'warning');
            return;
        }
        receiveItems = [{
            id: itemId,
            item_id: itemId,
            name: opt.dataset.itemName || opt.textContent.trim(),
            code: opt.dataset.itemCode || '',
            description: opt.dataset.itemDescription || opt.dataset.itemName || '',
            qty: qty,
            uom: opt.dataset.uom || '',
            price: price,
            total: qty * price,
            source: 'rmr',
            rmr_id: dropdown.value
        }];
        renderReceiveTable();
    }

    const rmrRequestDropdown = document.getElementById('rmrRequestDropdown');
    if (rmrRequestDropdown) {
        rmrRequestDropdown.addEventListener('change', function() {
            receiveItems = [];
            renderReceiveTable();
            previewSelectedRmrRequest();
        });
    }
    const addRmrReceiveItemBtn = document.getElementById('addRmrReceiveItemBtn');
    if (addRmrReceiveItemBtn) {
        addRmrReceiveItemBtn.addEventListener('click', addSelectedRmrItemToTable);
    }

    function filterReceiveHistoryTable() {
        const tableBody = document.getElementById('receiveHistoryTableBody');
        const rows = document.querySelectorAll('#receiveHistoryTable .receive-history-row');
        const emptyFilterRow = document.querySelector('#receiveHistoryTable .receive-history-empty-filter-row');
        const emptyOriginalRow = document.querySelector('#receiveHistoryTable .receive-history-empty-row');
        const countLabel = document.getElementById('receiveHistoryFilterCount');
        const searchTerm = (document.getElementById('receiveHistorySearch')?.value || '').trim().toLowerCase();
        const sourceFilter = document.getElementById('receiveHistorySourceFilter')?.value || 'all';
        const encoderFilter = document.getElementById('receiveHistoryEncoderFilter')?.value || 'all';
        const dateFrom = document.getElementById('receiveHistoryDateFrom')?.value || '';
        const dateTo = document.getElementById('receiveHistoryDateTo')?.value || '';
        let visibleCount = 0;

        rows.forEach(row => {
            const rowSearch = row.dataset.search || row.textContent.toLowerCase();
            const rowSource = row.dataset.referenceType || '';
            const rowEncoder = row.dataset.encoder || 'Unknown';
            const rowDate = row.dataset.receiveDate || '';

            const matchesSearch = searchTerm === '' || rowSearch.includes(searchTerm);
            const matchesSource = sourceFilter === 'all' || rowSource === sourceFilter;
            const matchesEncoder = encoderFilter === 'all' || rowEncoder === encoderFilter;
            const matchesDateFrom = dateFrom === '' || (rowDate !== '' && rowDate >= dateFrom);
            const matchesDateTo = dateTo === '' || (rowDate !== '' && rowDate <= dateTo);
            const shouldShow = matchesSearch && matchesSource && matchesEncoder && matchesDateFrom && matchesDateTo;

            row.style.display = shouldShow ? '' : 'none';
            if (shouldShow) visibleCount++;
        });

        if (emptyFilterRow) {
            emptyFilterRow.style.display = rows.length > 0 && visibleCount === 0 ? '' : 'none';
        }
        if (emptyOriginalRow) {
            emptyOriginalRow.style.display = rows.length === 0 ? '' : 'none';
        }
        if (tableBody && rows.length > 0) {
            tableBody.style.display = 'table-row-group';
        }
        if (countLabel) {
            countLabel.textContent = `Showing ${visibleCount} of ${rows.length} record(s)`;
        }
    }

    ['receiveHistorySearch', 'receiveHistorySourceFilter', 'receiveHistoryEncoderFilter', 'receiveHistoryDateFrom', 'receiveHistoryDateTo'].forEach(id => {
        const input = document.getElementById(id);
        if (!input) return;
        input.addEventListener(id === 'receiveHistorySearch' ? 'keyup' : 'change', filterReceiveHistoryTable);
    });

    const clearReceiveHistoryFiltersBtn = document.getElementById('clearReceiveHistoryFiltersBtn');
    if (clearReceiveHistoryFiltersBtn) {
        clearReceiveHistoryFiltersBtn.addEventListener('click', function() {
            const searchInput = document.getElementById('receiveHistorySearch');
            const sourceFilter = document.getElementById('receiveHistorySourceFilter');
            const encoderFilter = document.getElementById('receiveHistoryEncoderFilter');
            const dateFrom = document.getElementById('receiveHistoryDateFrom');
            const dateTo = document.getElementById('receiveHistoryDateTo');
            if (searchInput) searchInput.value = '';
            if (sourceFilter) sourceFilter.value = 'all';
            if (encoderFilter) encoderFilter.value = 'all';
            if (dateFrom) dateFrom.value = '';
            if (dateTo) dateTo.value = '';
            filterReceiveHistoryTable();
        });
    }
    filterReceiveHistoryTable();

    const receiveHistoryTable = document.getElementById('receiveHistoryTable');
    if (receiveHistoryTable) {
        receiveHistoryTable.addEventListener('click', function(e) {
            const row = e.target.closest('.receive-history-row');
            if (!row) return;
            openReceiveHistoryDetails(row.dataset.transactionId || '', {
                itemId: row.dataset.itemId || '',
                referenceType: row.dataset.referenceType || '',
                referenceId: row.dataset.referenceId || '',
                createdAt: row.dataset.createdAt || ''
            });
        });
    }

    document.getElementById('supplierReceiveDate').valueAsDate = new Date();
    document.getElementById('productionReceiveDate').valueAsDate = new Date();
    const supplierEncodedDate = document.getElementById('supplierEncodedDate');
    const productionEncodedDate = document.getElementById('productionEncodedDate');
    if (supplierEncodedDate) supplierEncodedDate.valueAsDate = new Date();
    if (productionEncodedDate) productionEncodedDate.valueAsDate = new Date();
    const initialRmrReceiveDate = document.getElementById('rmrReceiveDate');
    if (initialRmrReceiveDate) initialRmrReceiveDate.valueAsDate = new Date();
    const initialRmrEncodedDate = document.getElementById('rmrEncodedDate');
    if (initialRmrEncodedDate) initialRmrEncodedDate.valueAsDate = new Date();
    renderReceiveTable();
    handleReceiveItemSelection('supplier');
    handleReceiveItemSelection('production');


    const deleteReceiveHistoryBtn = document.getElementById('deleteReceiveHistoryBtn');
    if (deleteReceiveHistoryBtn) {
        deleteReceiveHistoryBtn.addEventListener('click', function() {
            if (!activeReceiveHistoryDeletePayload) return;
            const proceed = () => {
                deleteReceiveHistoryBtn.disabled = true;
                deleteReceiveHistoryBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Deleting...';
                const payload = new URLSearchParams();
                payload.append('action', 'delete_receive_history');
                Object.entries(activeReceiveHistoryDeletePayload).forEach(([key, value]) => {
                    if (value !== null && value !== undefined) payload.append(key, value);
                });

                fetch(window.location.href, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                    body: payload.toString()
                })
                .then(res => res.text())
                .then(text => {
                    let data;
                    try { data = JSON.parse(text); } catch (e) { throw new Error(text || 'Failed to parse delete response.'); }
                    if (!data.success) throw new Error(data.message || 'Failed to delete receive record.');
                    if (typeof Swal !== 'undefined') {
                        return showSystemAlert('Deleted', data.message || 'Receive record deleted successfully.', 'success');
                    }
                    return showSystemAlert('Deleted', data.message || 'Receive record deleted successfully.', 'success');
                })
                .then(() => window.location.reload())
                .catch(err => {
                    deleteReceiveHistoryBtn.disabled = false;
                    deleteReceiveHistoryBtn.innerHTML = '<i class="bi bi-trash me-1"></i> Delete Receive';
                    if (typeof Swal !== 'undefined') {
                        showSystemAlert('Error', err.message || 'Failed to delete receive record.', 'error');
                    } else {
                        showSystemAlert('Error', err.message || 'Failed to delete receive record.', 'error');
                    }
                });
            };

            if (typeof Swal !== 'undefined') {
                Swal.fire({
                    icon: 'warning',
                    title: 'Delete this receive record?',
                    text: 'This will remove the receive record and deduct the received stock.',
                    showCancelButton: true,
                    confirmButtonText: 'Yes, delete it'
                }).then(result => {
                    if (result.isConfirmed) proceed();
                });
            } else if (confirm('Delete this receive record and deduct the received stock?')) {
                proceed();
            }
        });
    }

    function clearReceiveForm() {
        document.getElementById('supplierReceiveForm').reset();
        document.getElementById('productionReceiveForm').reset();
        const rmrReceiveForm = document.getElementById('rmrReceiveForm');
        if (rmrReceiveForm) rmrReceiveForm.reset();
        document.getElementById('supplierReceiveDate').valueAsDate = new Date();
        document.getElementById('productionReceiveDate').valueAsDate = new Date();
        const supplierEncodedDate = document.getElementById('supplierEncodedDate');
        const productionEncodedDate = document.getElementById('productionEncodedDate');
        if (supplierEncodedDate) supplierEncodedDate.valueAsDate = new Date();
        if (productionEncodedDate) productionEncodedDate.valueAsDate = new Date();
        const rmrReceiveDate = document.getElementById('rmrReceiveDate');
        if (rmrReceiveDate) rmrReceiveDate.valueAsDate = new Date();
        const rmrEncodedDate = document.getElementById('rmrEncodedDate');
        if (rmrEncodedDate) rmrEncodedDate.valueAsDate = new Date();
        document.getElementById('poNumberDropdown').innerHTML = '<option value="">-- Select PO --</option>';
        const supplierAttachmentInput = document.getElementById('supplierAttachments');
        const productionAttachmentInput = document.getElementById('productionAttachments');
        if (supplierAttachmentInput) supplierAttachmentInput.value = '';
        if (productionAttachmentInput) productionAttachmentInput.value = '';
        const receiveMemo = document.getElementById('receiveMemo');
        if (receiveMemo) receiveMemo.value = '';
        receiveItems = [];
        renderReceiveTable();
        resetReceiveItemEntry('supplier');
        resetReceiveItemEntry('production');
    }

    function submitReceiveInventory() {
        const activeTab = document.querySelector('.receive-tabs .nav-link.active').id;
        let formData = {};
        if (activeTab === 'supplierTab') {
            formData = {
                source: 'supplier',
                supplier: document.getElementById('supplierDropdown').value,
                supplierName: getSupplierName(),
                poNumber: document.getElementById('poNumberDropdown').value,
                withPO: document.getElementById('withPOCheckbox').checked,
                receiveDate: document.getElementById('supplierReceiveDate').value,
                encodedDate: document.getElementById('supplierEncodedDate').value,
                totalItems: receiveItems.length
            };
        } else if (activeTab === 'rmrTab') {
            formData = {
                source: 'rmr',
                rmr_id: document.getElementById('rmrRequestDropdown').value,
                receiveDate: document.getElementById('rmrReceiveDate').value,
                encodedDate: document.getElementById('rmrEncodedDate').value,
                totalItems: receiveItems.length
            };
        } else {
            formData = {
                source: 'production',
                receiveDate: document.getElementById('productionReceiveDate').value,
                encodedDate: document.getElementById('productionEncodedDate').value,
                totalItems: document.getElementById('productionTotalItems').value
            };
        }

        if (activeTab === 'supplierTab') {
            if (!formData.supplier) {
                showSystemAlert('Reminder', 'Please select a supplier.', 'warning');
                return;
            }
            if (formData.withPO && !formData.poNumber) {
                showSystemAlert('Reminder', 'Please select a PO number or uncheck "With PO?".', 'warning');
                return;
            }
        }
        if (activeTab === 'rmrTab' && !formData.rmr_id) {
            showSystemAlert('Reminder', 'Please select a confirmed or processed RMR request.', 'warning');
            return;
        }
        if (!formData.receiveDate) {
            showSystemAlert('Reminder', 'Please select a receive date.', 'warning');
            return;
        }
        if (!formData.encodedDate) {
            showSystemAlert('Reminder', 'Please select an date encoded.', 'warning');
            return;
        }
        if (receiveItems.length === 0) {
            showSystemAlert('Reminder', 'Please add at least one item to receive.', 'warning');
            return;
        }

        const payload = new FormData();
        payload.append('action', 'receive_inventory');
        payload.append('source', formData.source);
        payload.append('supplier_id', formData.supplier || '');
        payload.append('supplier', formData.supplierName || '');
        payload.append('poNumber', formData.poNumber || '');
        payload.append('withPO', formData.withPO ? '1' : '0');
        payload.append('rmr_id', formData.rmr_id || '');
        payload.append('receiveDate', formData.receiveDate || '');
        payload.append('encodedDate', formData.encodedDate || '');
        payload.append('receive_memo', (document.getElementById('receiveMemo')?.value || '').trim());
        payload.append('items', JSON.stringify(receiveItems));

        const attachmentInput = activeTab === 'supplierTab'
            ? document.getElementById('supplierAttachments')
            : (activeTab === 'productionTab' ? document.getElementById('productionAttachments') : null);
        if (attachmentInput && attachmentInput.files && attachmentInput.files.length > 0) {
            Array.from(attachmentInput.files).forEach(file => {
                payload.append(activeTab === 'supplierTab' ? 'supplierAttachments[]' : 'productionAttachments[]', file);
            });
        }

        const submitBtn = document.getElementById('submitReceiveBtn');
        const originalSubmitHtml = submitBtn ? submitBtn.innerHTML : '';
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Saving...';
        }

        fetch(window.location.href, {
            method: 'POST',
            body: payload
        })
        .then(response => response.text())
        .then(text => {
            let data;
            try {
                data = JSON.parse(text);
            } catch (e) {
                throw new Error(text || 'Failed to parse server response.');
            }
            if (!data.success) throw new Error(data.message || 'Failed to save received inventory.');
            if (typeof Swal !== 'undefined') {
                return showSystemAlert(
                    'Inventory Received',
                    (data.message || 'Received inventory saved successfully.') + ((data.attachments_saved || 0) > 0 ? ` Attachments saved: ${data.attachments_saved}.` : ''),
                    'success',
                    {
                        timer: 2500,
                        timerProgressBar: true
                    }
                );
            }
            return showSystemAlert('Success', data.message || 'Received inventory saved successfully.', 'success');
            return Promise.resolve();
        })
        .then(() => {
            clearReceiveForm();
            window.location.reload();
        })
        .catch(error => {
            if (typeof Swal !== 'undefined') {
                showSystemAlert('Error', error.message || 'Unable to save received inventory.', 'error');
            } else {
                showSystemAlert('Error', error.message || 'Unable to save received inventory.', 'error');
            }
        });
    }

    window.clearReceiveForm = clearReceiveForm;
    window.submitReceiveInventory = submitReceiveInventory;

    // Helper function to escape HTML
    function escapeHtml(unsafe) {
        return unsafe
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }
});
</script>
</body>
</html>

