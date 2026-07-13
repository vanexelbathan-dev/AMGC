<?php
require_once '../config/database.php';
require_once '../config/session_handler.php';

$user_id = $_SESSION['user_id'] ?? 0;
$user_name = isset($_SESSION['first_name']) ? $_SESSION['first_name'] . ' ' . $_SESSION['last_name'] : 'Branch Admin';
$user_role = $_SESSION['role'] ?? 'branch_admin';
$branch_id = $_SESSION['branch_id'] ?? 0;
$view_all_branches = $_SESSION['view_all_branches'] ?? false;

$user_initials = '';
if (!empty($user_name)) {
    foreach (explode(' ', $user_name) as $part) {
        if (!empty($part)) $user_initials .= strtoupper(substr($part, 0, 1));
    }
}
if ($user_initials === '') $user_initials = 'BA';

$branch_name = 'All Branches';
if (!$view_all_branches && $branch_id > 0) {
    $stmt = $conn->prepare("SELECT branch_name FROM branches WHERE branch_id = ?");
    if ($stmt) {
        $stmt->bind_param('i', $branch_id);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($row = $res->fetch_assoc()) $branch_name = $row['branch_name'];
        $stmt->close();
    }
}

function h($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function tableExists(mysqli $conn, string $table): bool {
    $safe = $conn->real_escape_string($table);
    $res = $conn->query("SHOW TABLES LIKE '{$safe}'");
    return $res && $res->num_rows > 0;
}
function tableColumns(mysqli $conn, string $table): array {
    $safe = preg_replace('/[^A-Za-z0-9_]/', '', $table);
    if ($safe === '') return [];
    $cols = [];
    $res = $conn->query("SHOW COLUMNS FROM `{$safe}`");
    if ($res) while ($row = $res->fetch_assoc()) if (!empty($row['Field'])) $cols[] = $row['Field'];
    return $cols;
}
function firstColumn(array $cols, array $candidates): ?string {
    foreach ($candidates as $candidate) foreach ($cols as $col) if (strcasecmp($col, $candidate) === 0) return $col;
    return null;
}
function qi(string $name): string { return '`' . str_replace('`', '``', $name) . '`'; }
function qexpr(string $alias, ?string $column, string $fallback = "''"): string { return $column ? $alias . '.' . qi($column) : $fallback; }

function ensureReturnMerchandiseColumns(mysqli $conn): array {
    $has = ['receive_date' => false, 'encoded_date' => false, 'receive_memo' => false, 'receive_inventory_dates' => false];

    foreach ([
        'receive_memo' => "ALTER TABLE inventory_transactions ADD COLUMN receive_memo TEXT NULL AFTER reference_id",
        'unit_cost' => "ALTER TABLE inventory_transactions ADD COLUMN unit_cost DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER quantity_changed",
        'total_cost' => "ALTER TABLE inventory_transactions ADD COLUMN total_cost DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER unit_cost"
    ] as $column => $alter) {
        $check = $conn->query("SHOW COLUMNS FROM inventory_transactions LIKE '{$column}'");
        if (!$check || $check->num_rows === 0) @$conn->query($alter);
    }

    foreach (['receive_date', 'encoded_date', 'receive_memo'] as $column) {
        $check = $conn->query("SHOW COLUMNS FROM inventory_transactions LIKE '{$column}'");
        $has[$column] = $check && $check->num_rows > 0;
    }

    $checkTotalCost = $conn->query("SHOW COLUMNS FROM item_unit_inventory LIKE 'total_cost'");
    if (!$checkTotalCost || $checkTotalCost->num_rows === 0) {
        @$conn->query("ALTER TABLE item_unit_inventory ADD COLUMN total_cost DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER unit_cost");
        @$conn->query("UPDATE item_unit_inventory SET total_cost = COALESCE(current_inventory, 0) * COALESCE(unit_cost, 0) WHERE total_cost IS NULL OR total_cost = 0");
    }

    $checkDatesTable = $conn->query("SHOW TABLES LIKE 'receive_inventory_dates'");
    $has['receive_inventory_dates'] = $checkDatesTable && $checkDatesTable->num_rows > 0;
    return $has;
}

function getPendingRmrRequests(mysqli $conn, int $branchId, bool $viewAllBranches): array {
    $rmrTable = null;
    foreach (['rmr_requests', 'rmr_request', 'return_merchandise_requests', 'returned_merchandise_requests', 'rmr'] as $table) {
        if (tableExists($conn, $table)) { $rmrTable = $table; break; }
    }
    if (!$rmrTable) return [];

    $cols = tableColumns($conn, $rmrTable);
    $idCol = firstColumn($cols, ['rmr_id', 'request_id', 'return_id', 'id']);
    $numberCol = firstColumn($cols, ['rmr_number', 'rmr_no', 'request_number', 'reference_number', 'reference_no', 'rma_number']);
    $statusCol = firstColumn($cols, ['status', 'rmr_status', 'request_status', 'process_status']);
    $branchCol = firstColumn($cols, ['branch_id']);
    $itemCol = firstColumn($cols, ['item_id']);
    $itemNameCol = firstColumn($cols, ['item_name', 'product_name', 'description', 'item_description']);
    $itemCodeCol = firstColumn($cols, ['item_code', 'product_code', 'code']);
    $qtyCol = firstColumn($cols, ['return_quantity', 'quantity', 'qty', 'return_qty', 'returned_qty', 'approved_qty', 'processed_qty']);
    $uomCol = firstColumn($cols, ['unit_type', 'uom', 'unit', 'unit_of_measure']);
    $customerCol = firstColumn($cols, ['customer_name', 'store_name', 'client_name']);
    $customerIdCol = firstColumn($cols, ['customer_id', 'client_id']);
    $deliveryIdCol = firstColumn($cols, ['delivery_id']);
    $reasonCol = firstColumn($cols, ['reason', 'remarks', 'notes', 'return_reason']);
    $processedAtCol = firstColumn($cols, ['processed_at', 'confirmed_at', 'updated_at', 'created_at']);
    if (!$idCol || !$statusCol || !$itemCol || !$qtyCol) return [];

    $idExpr = qexpr('r', $idCol, '0');
    $numberExpr = $numberCol ? qexpr('r', $numberCol) : "CONCAT('RMR-', {$idExpr})";
    $itemNameExpr = $itemNameCol ? qexpr('r', $itemNameCol) : 'i.item_name';
    $itemCodeExpr = $itemCodeCol ? qexpr('r', $itemCodeCol) : 'i.item_code';
    $uomExpr = $uomCol ? qexpr('r', $uomCol) : 'i.unit_type';
    $reasonExpr = qexpr('r', $reasonCol, "''");
    $processedExpr = qexpr('r', $processedAtCol, "''");

    $joins = '';
    $customerParts = [];
    if ($customerCol) $customerParts[] = "NULLIF(TRIM(" . qexpr('r', $customerCol) . "), '')";
    if ($customerIdCol && tableExists($conn, 'customers')) {
        $joins .= ' LEFT JOIN customers c ON c.customer_id = r.' . qi($customerIdCol);
        $customerParts[] = "NULLIF(TRIM(c.customer_name), '')";
    }
    if ($deliveryIdCol && tableExists($conn, 'deliveries') && tableExists($conn, 'customers')) {
        $joins .= ' LEFT JOIN deliveries d ON d.delivery_id = r.' . qi($deliveryIdCol);
        $joins .= ' LEFT JOIN customers dc ON dc.customer_id = d.customer_id';
        $customerParts[] = "NULLIF(TRIM(dc.customer_name), '')";
    }
    $customerExpr = $customerParts ? 'COALESCE(' . implode(', ', $customerParts) . ", '')" : "''";

    $sql = "SELECT
                {$idExpr} AS rmr_id,
                {$numberExpr} AS rmr_number,
                r." . qi($statusCol) . " AS status,
                r." . qi($itemCol) . " AS item_id,
                COALESCE({$itemNameExpr}, i.item_name, '') AS item_name,
                COALESCE({$itemCodeExpr}, i.item_code, '') AS item_code,
                COALESCE(i.description, {$itemNameExpr}, '') AS item_description,
                r." . qi($qtyCol) . " AS quantity,
                COALESCE({$uomExpr}, i.unit_type, '') AS unit_type,
                COALESCE(NULLIF(i.unit_price, 0), 0) AS unit_price,
                {$customerExpr} AS customer_name,
                COALESCE({$reasonExpr}, '') AS reason,
                COALESCE({$processedExpr}, '') AS processed_at
            FROM " . qi($rmrTable) . " r
            LEFT JOIN items i ON i.item_id = r." . qi($itemCol) . "
            {$joins}
            WHERE LOWER(TRIM(r." . qi($statusCol) . ")) IN ('confirmed', 'processed', 'approved')
              AND r." . qi($itemCol) . " IS NOT NULL
              AND r." . qi($qtyCol) . " > 0
              AND NOT EXISTS (
                  SELECT 1 FROM inventory_transactions it
                  WHERE it.reference_type = 'rmr'
                    AND it.reference_id = {$idExpr}
                    AND it.transaction_type = 'in'
              )";
    if (!$viewAllBranches && $branchId > 0 && $branchCol) $sql .= ' AND r.' . qi($branchCol) . ' = ' . (int)$branchId;
    $sql .= " ORDER BY {$idExpr} DESC";
    $res = $conn->query($sql);
    return $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
}

$columnFlags = ensureReturnMerchandiseColumns($conn);
$rmr_requests_list = getPendingRmrRequests($conn, (int)$branch_id, (bool)$view_all_branches);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'receive_rmr') {
    header('Content-Type: application/json');
    try {
        $conn->begin_transaction();
        $rmr_id = (int)($_POST['rmr_id'] ?? 0);
        $receive_date = trim((string)($_POST['receive_date'] ?? date('Y-m-d')));
        $encoded_date = trim((string)($_POST['encoded_date'] ?? $receive_date));
        $memo = trim((string)($_POST['memo'] ?? ''));

        if ($rmr_id <= 0) throw new Exception('Please select a Return Merchandise request.');
        if ($receive_date === '') throw new Exception('Receive date is required.');

        $selected = null;
        foreach ($rmr_requests_list as $row) {
            if ((int)$row['rmr_id'] === $rmr_id) { $selected = $row; break; }
        }
        if (!$selected) throw new Exception('Selected RMR is not available or already received.');

        $item_id = (int)$selected['item_id'];
        $qty = (float)$selected['quantity'];
        $unit_cost = (float)$selected['unit_price'];
        $total_cost = $qty * $unit_cost;
        $unit_type = trim((string)($selected['unit_type'] ?? ''));

        $fields = ['branch_id', 'item_id', 'transaction_type', 'quantity_changed', 'unit_cost', 'total_cost'];
        $placeholders = ['?', '?', "'in'", '?', '?', '?'];
        $types = 'iiddd';
        $values = [(int)$branch_id, $item_id, $qty, $unit_cost, $total_cost];

        if ($columnFlags['receive_date']) { $fields[] = 'receive_date'; $placeholders[] = '?'; $types .= 's'; $values[] = $receive_date; }
        if ($columnFlags['encoded_date']) { $fields[] = 'encoded_date'; $placeholders[] = '?'; $types .= 's'; $values[] = $encoded_date; }
        $fields[] = 'reference_type'; $placeholders[] = '?'; $types .= 's'; $values[] = 'rmr';
        $fields[] = 'reference_id'; $placeholders[] = '?'; $types .= 'i'; $values[] = $rmr_id;
        if ($columnFlags['receive_memo']) { $fields[] = 'receive_memo'; $placeholders[] = '?'; $types .= 's'; $values[] = $memo; }
        $fields[] = 'created_by'; $placeholders[] = '?'; $types .= 'i'; $values[] = (int)$user_id;
        $fields[] = 'created_at'; $placeholders[] = 'NOW()';

        $stmt = $conn->prepare('INSERT INTO inventory_transactions (' . implode(', ', $fields) . ') VALUES (' . implode(', ', $placeholders) . ')');
        if (!$stmt) throw new Exception('Unable to prepare inventory transaction.');
        $stmt->bind_param($types, ...$values);
        if (!$stmt->execute()) throw new Exception('Failed to save inventory transaction: ' . $stmt->error);
        $transaction_id = (int)$conn->insert_id;
        $stmt->close();

        if ($columnFlags['receive_inventory_dates'] && $transaction_id > 0) {
            $dateStmt = $conn->prepare('INSERT INTO receive_inventory_dates (transaction_id, receive_date, encoded_date) VALUES (?, ?, ?)');
            if ($dateStmt) {
                $dateStmt->bind_param('iss', $transaction_id, $receive_date, $encoded_date);
                $dateStmt->execute();
                $dateStmt->close();
            }
        }

        $inventory_id = 0;
        $current = 0.0;
        $old_total = 0.0;
        if ($unit_type !== '') {
            $find = $conn->prepare("SELECT iui.inventory_id, COALESCE(iui.current_inventory,0) current_inventory, COALESCE(NULLIF(iui.total_cost,0), COALESCE(iui.current_inventory,0) * COALESCE(iui.unit_cost,0)) total_cost
                                    FROM item_unit_inventory iui
                                    INNER JOIN unit_types ut ON ut.unit_type_id = iui.unit_type_id
                                    WHERE iui.item_id = ? AND LOWER(TRIM(ut.unit_type_name)) = LOWER(TRIM(?))
                                    ORDER BY iui.inventory_id ASC LIMIT 1");
            if ($find) {
                $find->bind_param('is', $item_id, $unit_type);
                $find->execute();
                $res = $find->get_result();
                if ($row = $res->fetch_assoc()) { $inventory_id = (int)$row['inventory_id']; $current = (float)$row['current_inventory']; $old_total = (float)$row['total_cost']; }
                $find->close();
            }
        }

        if ($inventory_id <= 0) {
            $find = $conn->prepare("SELECT iui.inventory_id, COALESCE(iui.current_inventory,0) current_inventory, COALESCE(NULLIF(iui.total_cost,0), COALESCE(iui.current_inventory,0) * COALESCE(iui.unit_cost,0)) total_cost
                                    FROM items i
                                    INNER JOIN item_unit_inventory iui ON iui.item_id = i.item_id AND iui.unit_type_id = i.default_unit_type_id
                                    WHERE i.item_id = ? LIMIT 1");
            if ($find) {
                $find->bind_param('i', $item_id);
                $find->execute();
                $res = $find->get_result();
                if ($row = $res->fetch_assoc()) { $inventory_id = (int)$row['inventory_id']; $current = (float)$row['current_inventory']; $old_total = (float)$row['total_cost']; }
                $find->close();
            }
        }

        if ($inventory_id > 0) {
            $new_current = $current + $qty;
            $new_total = $old_total + $total_cost;
            $avg_cost = $new_current > 0 ? $new_total / $new_current : $unit_cost;
            $upd = $conn->prepare('UPDATE item_unit_inventory SET current_inventory = ?, unit_cost = ?, total_cost = ?, as_of_date = ?, updated_at = NOW() WHERE inventory_id = ? LIMIT 1');
            if (!$upd) throw new Exception('Unable to update unit inventory.');
            $upd->bind_param('dddsi', $new_current, $avg_cost, $new_total, $receive_date, $inventory_id);
            if (!$upd->execute()) throw new Exception('Failed to update unit inventory.');
            $upd->close();
        } else {
            $upd = $conn->prepare('UPDATE items SET stock = COALESCE(stock,0) + ?, stock_in_default_uom = COALESCE(stock_in_default_uom,0) + ?, updated_at = NOW() WHERE item_id = ? LIMIT 1');
            if ($upd) { $upd->bind_param('ddi', $qty, $qty, $item_id); $upd->execute(); $upd->close(); }
        }

        if (tableExists($conn, 'rmr_requests')) {
            $cols = tableColumns($conn, 'rmr_requests');
            $statusCol = firstColumn($cols, ['rmr_status', 'status', 'request_status', 'process_status']);
            $idCol = firstColumn($cols, ['rmr_id', 'request_id', 'return_id', 'id']);
            $receivedDateCol = firstColumn($cols, ['received_date', 'receive_date', 'date_received']);
            $receivedByCol = firstColumn($cols, ['received_by', 'receive_by', 'receiver_id']);
            if ($statusCol && $idCol) {
                $sets = [qi($statusCol) . " = 'resolved'"];
                $types = '';
                $vals = [];
                if (in_array('updated_at', $cols, true)) $sets[] = 'updated_at = NOW()';
                if ($receivedDateCol) { $sets[] = qi($receivedDateCol) . ' = ?'; $types .= 's'; $vals[] = $receive_date; }
                if ($receivedByCol) { $sets[] = qi($receivedByCol) . ' = ?'; $types .= 'i'; $vals[] = (int)$user_id; }
                $types .= 'i'; $vals[] = $rmr_id;
                $rmrUpd = $conn->prepare('UPDATE rmr_requests SET ' . implode(', ', $sets) . ' WHERE ' . qi($idCol) . ' = ?');
                if ($rmrUpd) { $rmrUpd->bind_param($types, ...$vals); $rmrUpd->execute(); $rmrUpd->close(); }
            }
        }

        $conn->commit();
        echo json_encode(['success' => true, 'message' => 'Return merchandise received successfully.']);
    } catch (Throwable $e) {
        $conn->rollback();
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
    <title>Return Merchandise - Branch Admin</title>
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

        .supplier-info-title-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 16px;
            flex-wrap: wrap;
        }

        .supplier-with-po-toggle {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            white-space: nowrap;
        }

        .supplier-with-po-toggle .form-check {
            display: flex;
            align-items: center;
            min-height: auto;
            padding-left: 2.5em;
            margin-bottom: 0;
        }

        .supplier-with-po-toggle .form-check-input {
            cursor: pointer;
            margin-top: 0 !important;
        }

        .supplier-with-po-toggle .form-check-label {
            cursor: pointer;
            font-weight: 600;
            color: #495057;
            margin-bottom: 0 !important;
            line-height: 1;
            display: inline-flex;
            align-items: center;
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
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            overflow-x: auto;
            width: 100%;
        }

        .receive-items-table {
            margin-bottom: 0;
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
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
            vertical-align: middle;
            text-align: left;
        }

        .receive-items-table tbody td {
            padding: 14px 12px;
            vertical-align: middle;
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
.expense-total-badge {
    background: rgba(68, 211, 78, .12);
    border: 1px solid rgba(68, 211, 78, .35);
    color: #047857;
    border-radius: 10px;
    padding: .45rem .8rem;
    font-size: .9rem;
}

.expense-entry-table input {
        min-height:24px;
    height:24px;
    padding:2px 6px;
    font-size:13px;

}

.expense-entry-table tbody tr:nth-child(even) {
    background: rgba(13, 110, 253, .04);
}

.expense-entry-table .btn-outline-danger {
    border-radius: 8px;
}

.recorded-expense-row {
    cursor: pointer;
    transition: background .2s ease;
}

.recorded-expense-row:hover {
    background: rgba(68, 211, 78, .08);
}

.expense-details-list {
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    overflow: hidden;
}

.expense-details-list .expense-detail-line {
    display: grid;
    grid-template-columns: 1.1fr .7fr 1.2fr;
    gap: 12px;
    padding: 12px 14px;
    border-bottom: 1px solid #eef2f7;
    align-items: start;
}

.expense-details-list .expense-detail-line:last-child {
    border-bottom: none;
}

.expense-details-list .expense-detail-head {
    background: #047857 !important;
    font-weight: 700;
    color: #fff !important;
}

.expense-details-list .expense-detail-head > div {
    background: #047857 !important;
    color: #fff !important;
}

.expense-detail-info-card {
    background: #f8fafc;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    padding: 12px 14px;
    height: 100%;
}

.expense-detail-muted {
    color: #6b7280;
    font-size: .875rem;
}

@media (max-width: 576px) {
    .expense-details-list .expense-detail-line {
        grid-template-columns: 1fr;
    }
    .expense-details-list .expense-detail-head {
        display: none;
    }
}

.po-qb-shell {
    background: #f2f2f2;
    border: 1px solid #cfd3e0;
    border-radius: 0;
    padding: 0;
    margin-top: 8px;
    margin-bottom: 8px;
    box-shadow: none;
    width: 100%;
    max-width: none;
    font-family: Arial, Helvetica, sans-serif;
}

.po-qb-modebar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 18px;
    height: 26px;
    padding: 0 4px 4px;
    background: #f2f2f2;
}

.po-qb-mode-left,
.po-qb-mode-right {
    display: flex;
    align-items: center;
    gap: 14px;
}

.po-qb-mode-option {
    min-width: 150px;
    height: 19px;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 0 6px;
    background: linear-gradient(to right, #d9d9d9, #eeeeee);
    color: #333;
    font-size: 14px;
    line-height: 1;
}

.po-qb-mode-option input {
    width: 12px;
    height: 12px;
    margin: 0;
    accent-color: #6b7280;
}

.po-qb-bill-card {
    position: relative;
    min-height: 150px;
    border: 2px solid #c1dcbc;
    outline: 1px solid #f1fbee;
    background-color: #fcfffb;
    background-image:
        repeating-linear-gradient(45deg, rgba(150, 190, 155, 0.1) 0 1px, transparent 1px 6px),
        repeating-linear-gradient(-45deg, rgba(150, 160, 155, .08) 0 1px, transparent 1px 7px);
    padding: 7px 16px 7px;
    overflow: hidden;
}

.po-qb-title {
    color: #333;
    font-size: 30px;
    font-weight: 400;
    line-height: 1;
    margin: 0 0 3px;
}

.po-qb-bill-grid {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 310px;
    gap: 16px;
    align-items: start;
}

.po-qb-form-row {
    display: grid;
    grid-template-columns: 78px minmax(0, 1fr);
    gap: 10px;
    align-items: start;
    margin-bottom: 3px;
}

.po-qb-side-row {
    display: grid;
    grid-template-columns: 80px minmax(0, 1fr);
    gap: 12px;
    align-items: center;
    margin-bottom: 3px;
}

.po-qb-label {
    font-size: 14px;
    text-transform: uppercase;
    color: #3f454f;
    margin: 0;
    font-weight: 400;
    line-height: 28px;
}

.po-qb-input,
.po-qb-select,
.po-qb-textarea {
    border: 1px solid #c4c7cc;
    background: #eeeeee;
    border-radius: 2px;
    min-height: 23px;
    padding: 1px 6px;
    font-size: 13px;
    color: #111;
    box-shadow: inset 0 1px 2px rgba(0, 0, 0, .08);
    width: 100%;
}

.po-qb-select {
    height: 23px;
}

.po-qb-textarea {
    height: 42px;
    resize: none;
}

.po-qb-address-row {
    margin-bottom: 5px;
}

.po-qb-terms-row {
    max-width: 235px;
}

.po-qb-after-terms-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(190px, 260px));
    gap: 3px 14px;
    align-items: start;
    margin-top: 0;
    margin-bottom: 2px;
}

.po-qb-after-terms-grid .po-qb-form-row {
    grid-template-columns: 78px minmax(0, 1fr);
    margin-bottom: 0;
}

.po-qb-after-terms-grid .po-qb-input,
.po-qb-after-terms-grid .po-qb-select {
    max-width: 180px;
}

.po-qb-memo-row {
    display: grid;
    grid-template-columns: 78px minmax(0, 1fr);
    gap: 10px;
    align-items: center;
    margin-top: 2px;
    width: 100%;
}

.po-qb-memo-row .po-qb-input {
    max-width: 760px;
}

.po-qb-side-panel {
    padding-top: 8px;
}

.po-qb-side-row .po-qb-input,
.po-qb-side-row .po-qb-select {
    max-width: 190px;
}

.po-qb-side-spacer {
    height: 12px;
}


.po-qb-bill-card.po-qb-credit-mode {
    min-height: 160px;
}

.po-qb-bill-card.po-qb-credit-mode .po-qb-bill-grid {
    grid-template-columns: minmax(0, 1fr) 360px;
}

.po-qb-bill-card.po-qb-credit-mode .po-qb-address-row,
.po-qb-bill-card.po-qb-credit-mode .po-qb-terms-row,
.po-qb-bill-card.po-qb-credit-mode .po-qb-bill-due-row {
    display: none !important;
}

.po-qb-credit-class-row {
    display: none;
}

.po-qb-bill-card.po-qb-credit-mode .po-qb-credit-class-row {
    display: grid;
    max-width: 270px;
    margin-top: 18px;
}

.po-qb-bill-card.po-qb-credit-mode .po-qb-side-panel {
    padding-top: 12px;
}

.po-qb-bill-card.po-qb-credit-mode .po-qb-side-class-row {
    display: none !important;
}

.po-qb-bill-card.po-qb-credit-mode .po-qb-side-spacer {
    display: none;
}

.po-qb-bill-card.po-qb-credit-mode .po-qb-memo-row {
    margin-top: 20px;
}

.po-qb-bill-card.po-qb-credit-mode .po-qb-memo-row .po-qb-input {
    max-width: 665px;
}


.po-qb-receive-only {
    display: grid;
}

.po-qb-hide-on-expenses.expenses-active,
.po-qb-receive-only.expenses-active {
    display: none !important;
}


@media (max-width: 992px) {
    .po-qb-bill-grid {
        grid-template-columns: 1fr;
        gap: 8px;
    }
    .po-qb-side-panel {
        padding-top: 0;
    }
    .po-qb-side-row .po-qb-input,
    .po-qb-side-row .po-qb-select {
        max-width: none;
    }
}

@media (max-width: 576px) {
    .po-qb-modebar {
        height: auto;
        align-items: stretch;
        flex-direction: column;
        gap: 6px;
    }
    .po-qb-mode-left,
    .po-qb-mode-right {
        width: 100%;
        gap: 6px;
        flex-wrap: wrap;
    }
    .po-qb-mode-option {
        min-width: 0;
        flex: 1;
    }
    .po-qb-bill-card {
        padding: 16px;
    }
    .po-qb-title {
        font-size: 38px;
    }
    .po-qb-form-row,
    .po-qb-side-row,
    .po-qb-memo-row {
        grid-template-columns: 1fr;
        gap: 2px;
    }
    .po-qb-label {
        line-height: 20px;
    }
}

.po-qb-tabs {
    border-bottom: 1px solid #d9d9d9;
    margin-top: 8px;
    gap: 0;
}

.po-qb-tabs .nav-link {
    border: 1px solid #bfc4ca;
    border-bottom: 0;
    border-radius: 0;
    background: #cfcfcf;
    color: #111;
    font-size: 16px;
    font-weight: 500;
    height: 30px;
    line-height: 18px;
    padding: 5px 10px;
    margin-right: 2px;
    min-width: 130px;
}

.po-qb-tabs .nav-link.active {
    background: #fff;
    color: #047857;
    font-weight: 600;
}

.po-qb-tab-amount {
    float: right;
    margin-left: 25px;
    font-weight: 400;
}

.po-qb-sheet {
    border: 1px solid #d9d9d9;
    border-top: 0;
    padding: 0;
    background: #fff;
}

.po-qb-grid-wrap{
    min-height:auto;
    max-height:none;
    overflow:visible;
    border-top:0;
}

.po-qb-grid {
    width: 100%;
    border-collapse: collapse;
    table-layout: fixed;
    margin: 0;
}

.po-qb-grid thead th {
    height: 34px;
    background: #fff;
    color: #6b7280;
    text-transform: uppercase;
    font-size: 14px;
    font-weight: 500;
    border-right: 1px solid #d8dde3;
    border-bottom: 1px solid #9ca3af;
    padding: 5px 7px;
    text-align: left;
}

.po-qb-grid tbody tr:nth-child(odd) {
    background: #fff;
}

.po-qb-grid tbody tr:nth-child(even) {
    background: #e8ffe7;
}

.po-qb-grid tbody td{
    height:24px;
    border-right:1px solid #d8dde3;
    padding:0;
}

.po-qb-grid input {
    width: 100%;
    height: 25px;
    border: 0;
    background: transparent;
    border-radius: 0;
    padding: 2px 6px;
    font-size: 14px;
    outline: none;
}

.po-qb-grid input:focus {
    background: #fff;
    box-shadow: inset 0 0 0 1px #58e84b;
}

.po-qb-grid select,
.po-qb-grid .item-entry-select {
    width: 100%;
    height: 25px;
    border: 0;
    background: transparent;
    border-radius: 0;
    padding: 2px 6px;
    font-size: 14px;
    outline: none;
}

.po-qb-grid select:focus,
.po-qb-grid .item-entry-select:focus {
    background: #fff;
    box-shadow: inset 0 0 0 1px #58e84b;
}

.item-entry-grid-wrap {
    min-height: auto;
    max-height: 520px !important;
    overflow-y: auto !important;
    overflow-x: hidden !important;
}

.item-entry-grid-wrap .po-qb-grid thead th {
    position: sticky;
    top: 0;
    z-index: 5;
}

.item-entry-table thead th {
    background: #fff !important;
    color: #6b7280 !important;
    border-right: 1px solid #d8dde3 !important;
    border-bottom: 1px solid #9ca3af !important;
}


.item-entry-table tbody tr:nth-child(odd) td {
    background: #fff !important;
}

.item-entry-table tbody tr:nth-child(even) td {
    background: #e8ffe7 !important;
}

.item-entry-table tbody tr:hover td {
    background: #f0fff0 !important;
}

.item-entry-row-empty td {
    background: inherit !important;
}

.item-entry-table tbody td {
    height: 32px;
    vertical-align: middle;
}

.item-entry-add-btn{
    display:flex;
    align-items:center;
    justify-content:center;
    gap:6px;
    text-align:center;
}

.item-entry-actions {
    justify-content: flex-end;
    align-items: center;
    padding-top: 8px;
    margin-bottom: 8px;
}

.item-entry-actions .item-entry-add-btn {
    width: auto;
    min-width: 132px;
    height: 32px;
    padding: 4px 14px;
    margin: 0;
    border-radius: 3px;
    font-weight: 700;
}

.item-entry-file-label {
    width: calc(100% - 8px);
    height: 25px;
    margin: 3px 4px;
    padding: 3px 6px;
    border: 1px solid #c4c7cc;
    background: #eeeeee;
    border-radius: 3px;
    color: #374151;
    font-size: 12px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 4px;
    cursor: pointer;
    white-space: nowrap;
}

.item-entry-file-label:hover {
    background: #e8ffe7;
}

.item-entry-file-input {
    position: absolute;
    width: 1px;
    height: 1px;
    opacity: 0;
    pointer-events: none;
}

/* Keep the QuickBooks sheet inside the page width. */
.po-qb-shell,
.po-qb-sheet,
.po-qb-grid-wrap {
    max-width: 100%;
    box-sizing: border-box;
}

.po-qb-grid-wrap {
    overflow-x: hidden !important;
}

.po-qb-grid th,
.po-qb-grid td {
    box-sizing: border-box;
}

html,
body {
    overflow-x: hidden;
}

.main-content,
.content-wrapper,
.page-content,
.container-fluid {
    max-width: 100%;
}

.item-entry-file-label .item-attachment-text {
    display: inline-block;
    max-width: 86px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    vertical-align: middle;
}

.item-entry-file-label.has-file {
    background: #e8ffe7;
    border-color: #047857;
    color: #047857;
    font-weight: 600;
}


.po-qb-grid td.expense-account-cell {
    position: relative;
    overflow: visible;
}

.po-qb-grid .qb-account-picker {
    position: relative;
    width: 100%;
    height: 25px;
}

.po-qb-grid .expense-account-input {
    padding-right: 30px;
    background-image: none !important;
}

.po-qb-grid .expense-account-input:focus {
    background-color: #fff;
    border: 0;
    box-shadow: inset 0 0 0 1px #58e84b, 0 0 0 1px rgba(47, 128, 237, .15);
}

.qb-account-toggle {
    position: absolute;
    right: 1px;
    top: 1px;
    width: 26px;
    height: 23px;
    border: 0;
    border-left: 1px solid transparent;
    background: transparent;
    color: #64748b;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    border-radius: 2px;
    z-index: 2;
    transition: background .15s ease, color .15s ease, transform .15s ease;
}

.qb-account-toggle:hover,
.qb-account-toggle.active {
    background: #eeffee;
    color: #58e84b;
}

.qb-account-toggle i {
    font-size: 12px;
    line-height: 1;
    pointer-events: none;
}

.qb-account-dropdown {
    position: fixed;
    z-index: 99999;
    display: none;
    min-width: 260px;
    max-height: 245px;
    overflow-y: auto;
    background: #ffffff;
    border: 1px solid #a3df9d;
    border-radius: 4px;
    box-shadow: 0 8px 20px rgba(15, 23, 42, .18);
    padding: 5px 0;
    font-size: 13px;
    color: #1f2937;
}

.qb-account-dropdown.show {
    display: block;
}

.qb-account-option {
    padding: 8px 14px;
    cursor: pointer;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    line-height: 1.35;
    background: #fff;
}

.qb-account-option:hover,
.qb-account-option.active {
    background: #e7f2ff;
    color: #0f172a;
}

.qb-account-empty {
    padding: 9px 14px;
    color: #6b7280;
    font-size: 12px;
}

.po-qb-actions {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    padding: 10px 0 0;
}

.po-qb-actions .btn {
    min-width: 132px;
    border-radius: 3px;
    font-weight: 700;
}

.po-qb-actions .btn-primary {
    background: linear-gradient(#5b8fe9, #315db6);
    border-color: #315db6;
}

.po-qb-clear {
    background: #f4f4f4;
    border-color: #d6d6d6;
    color: #444;
}


.po-qb-bill-card.po-qb-credit-mode {
    min-height: 180px;
}

.po-qb-bill-card.po-qb-credit-mode .po-qb-bill-grid {
    grid-template-columns: minmax(0, 1fr) 360px;
}

.po-qb-bill-card.po-qb-credit-mode .po-qb-address-row,
.po-qb-bill-card.po-qb-credit-mode .po-qb-terms-row,
.po-qb-bill-card.po-qb-credit-mode .po-qb-bill-due-row {
    display: none !important;
}

.po-qb-credit-class-row {
    display: none;
}

.po-qb-bill-card.po-qb-credit-mode .po-qb-credit-class-row {
    display: grid;
    max-width: 270px;
    margin-top: 18px;
}

.po-qb-bill-card.po-qb-credit-mode .po-qb-side-panel {
    padding-top: 24px;
}

.po-qb-bill-card.po-qb-credit-mode .po-qb-side-class-row {
    display: none !important;
}

.po-qb-bill-card.po-qb-credit-mode .po-qb-side-spacer {
    display: none;
}

.po-qb-bill-card.po-qb-credit-mode .po-qb-memo-row {
    margin-top: 20px;
}

.po-qb-bill-card.po-qb-credit-mode .po-qb-memo-row .po-qb-input {
    max-width: 665px;
}


.po-qb-receive-only {
    display: grid;
}

.po-qb-hide-on-expenses.expenses-active,
.po-qb-receive-only.expenses-active {
    display: none !important;
}


@media (max-width: 992px) {
    .po-qb-topbar,
    .po-qb-check {
        max-width: 100%;
    }
    .po-qb-check-grid {
        grid-template-columns: 1fr;
    }
    .po-qb-side-row {
        max-width: 260px;
    }
    .po-qb-pay-row {
        grid-template-columns: 1fr;
        margin-top: 20px;
    }
    .po-qb-textarea {
        width: 100%;
    }
}

@media (max-width: 576px) {
    .po-qb-topbar {
        align-items: stretch;
        flex-direction: column;
    }
    .po-qb-fieldline {
        justify-content: space-between;
    }
    .po-qb-bank-select {
        width: 100%;
    }
    .po-qb-tabs {
        overflow-x: auto;
        flex-wrap: nowrap;
    }
    .po-qb-tabs .nav-link {
        white-space: nowrap;
    }
    .po-qb-actions {
        flex-direction: column;
    }
    .po-qb-actions .btn {
        width: 100%;
    }
}

input[type=number]::-webkit-outer-spin-button,
input[type=number]::-webkit-inner-spin-button {
    -webkit-appearance: none;
    margin: 0;
}

input[type=number] {
    -moz-appearance: textfield;
    appearance: textfield;
}


        /* Return Merchandise standalone page overrides */
        #newPoHeaderActionWrapper,
        .po-qb-shell,
        #supplierTab,
        #expensesTab,
        #supplierContent,
        #expensesContent,
        #expenseDetailsModal,
        #dashboardContent > .alert {
            display: none !important;
        }

        .receive-tabs.po-qb-tabs {
            border-bottom: none;
            margin-bottom: 1rem;
        }

        .receive-tabs.po-qb-tabs .nav-item:has(#rmrTab) {
            width: 100%;
        }

        #rmrTab.nav-link {
            cursor: default;
            pointer-events: none;
            border-radius: 14px;
            border: 1px solid rgba(68, 211, 78, 0.25);
            background: rgba(68, 211, 78, 0.08);
            color: var(--dark-color);
            font-weight: 700;
            padding: 0.9rem 1rem;
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
                                        <span class="nav-text">Warehouses</span>
                                    </a>
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
                                    <li class="nav-item"><a class="nav-link" href="collections.php"><i class="bi bi-cash-stack"></i><span class="nav-text">Collections</span></a></li>
                                    <li class="nav-item"><a class="nav-link active" href="return_merchandise.php"><i class="bi bi-cash-stack"></i><span class="nav-text">Return Merchandise</span></a></li>
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
                        <li class="nav-item">
                            <a class="nav-link" href="chartofaccounts.php">
                                <i class="bi bi-graph-up"></i>
                                <span class="nav-text">Chart of Accounts</span>
                            </a>
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
            <div id="dashboardContent" class="page-content active">
                <div class="navbar-top">
                    <button class="mobile-toggle-btn" id="mobileToggleBtn"><i class="bi bi-list"></i></button>
                    <div class="page-title">
                        <h2>Return Merchandise</h2>
                        <p>Receive confirmed returned merchandise back to inventory</p>
                    </div>
                </div>

                <div class="receive-inventory-section mb-5">
                    <div class="receive-inventory-card rmr-only-card">
                        <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-4">
                            <div>
                                <span class="badge rounded-pill bg-success-subtle text-success border border-success-subtle mb-2">Standalone Page</span>
                                <h4 class="mb-1 fw-bold">Returned Merchandise Receiving</h4>
                                <p class="text-muted mb-0">Select a confirmed or processed RMR request, review the item, then save it back to inventory.</p>
                            </div>
                            <div class="text-end">
                                <div class="text-muted small">Branch</div>
                                <div class="fw-semibold"><?= h($branch_name) ?></div>
                            </div>
                        </div>

                        <form id="rmrReceiveForm" class="receive-form">
                            <input type="hidden" id="rmrItemId">
                            <input type="hidden" id="rmrItemName">
                            <input type="hidden" id="rmrItemCode">
                            <input type="hidden" id="rmrItemDescription">
                            <input type="hidden" id="rmrItemQty">
                            <input type="hidden" id="rmrItemUom">
                            <input type="hidden" id="rmrItemPrice">

                            <div class="row g-3 align-items-end mb-4">
                                <div class="col-lg-6">
                                    <label for="rmrRequestDropdown" class="form-label fw-semibold">Confirmed / Processed RMR Request <span class="text-danger">*</span></label>
                                    <select class="form-select form-select-lg" id="rmrRequestDropdown" required>
                                        <option value="">-- Select RMR Request --</option>
                                        <?php foreach ($rmr_requests_list as $rmr): ?>
                                            <option
                                                value="<?= (int)$rmr['rmr_id'] ?>"
                                                data-item-id="<?= (int)$rmr['item_id'] ?>"
                                                data-item-name="<?= h($rmr['item_name'] ?? '') ?>"
                                                data-item-code="<?= h($rmr['item_code'] ?? '') ?>"
                                                data-item-description="<?= h($rmr['item_description'] ?? ($rmr['item_name'] ?? '')) ?>"
                                                data-qty="<?= h($rmr['quantity'] ?? 0) ?>"
                                                data-uom="<?= h($rmr['unit_type'] ?? '') ?>"
                                                data-price="<?= h($rmr['unit_price'] ?? 0) ?>"
                                                data-status="<?= h($rmr['status'] ?? '') ?>"
                                                data-customer="<?= h($rmr['customer_name'] ?? '') ?>"
                                                data-reason="<?= h($rmr['reason'] ?? '') ?>">
                                                <?= h(($rmr['rmr_number'] ?? ('RMR-' . $rmr['rmr_id'])) . ' - ' . (($rmr['customer_name'] ?? '') !== '' ? ($rmr['customer_name'] . ' - ') : '') . ($rmr['item_name'] ?? 'Item') . ' - Qty: ' . ($rmr['quantity'] ?? 0) . ' ' . ($rmr['unit_type'] ?? '')) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label for="rmrReceiveDate" class="form-label fw-semibold">Receive Date</label>
                                    <input type="date" class="form-control form-control-lg" id="rmrReceiveDate" value="<?= date('Y-m-d') ?>" required>
                                </div>
                                <div class="col-md-3">
                                    <label for="rmrEncodedDate" class="form-label fw-semibold">Date Encoded</label>
                                    <input type="date" class="form-control form-control-lg" id="rmrEncodedDate" value="<?= date('Y-m-d') ?>" required>
                                </div>
                            </div>

                            <div id="rmrRequestPreview" class="rmr-preview-empty mb-4">
                                <i class="bi bi-box-arrow-in-down me-2"></i>Select an RMR request to view the item details.
                            </div>

                            <div class="po-qb-sheet item-entry-sheet mb-4">
                                <div class="table-responsive">
                                    <table class="po-qb-grid item-entry-table mb-0">
                                        <thead>
                                            <tr>
                                                <th>Item Name</th>
                                                <th>Item Code</th>
                                                <th>Description</th>
                                                <th class="text-end">Qty</th>
                                                <th>UoM</th>
                                                <th class="text-end">Unit Cost</th>
                                                <th class="text-end">Total Cost</th>
                                            </tr>
                                        </thead>
                                        <tbody id="rmrItemsTableBody">
                                            <tr class="text-center text-muted">
                                                <td colspan="7" class="py-4">No RMR selected yet</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            <div class="row g-3 mb-4">
                                <div class="col-12">
                                    <label for="rmrMemo" class="form-label fw-semibold">Receive Memo</label>
                                    <textarea class="form-control" id="rmrMemo" rows="3" placeholder="Optional remarks for this returned merchandise receiving"></textarea>
                                </div>
                            </div>

                            <div class="d-flex justify-content-end gap-2">
                                <button type="button" class="btn btn-outline-secondary" id="clearRmrBtn">
                                    <i class="bi bi-arrow-counterclockwise me-1"></i> Clear
                                </button>
                                <button type="submit" class="btn btn-primary" id="saveRmrBtn">
                                    <i class="bi bi-save me-1"></i> Save Return Merchandise
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <style>
        .rmr-only-card { border: 1px solid rgba(5, 42, 71, .08); box-shadow: 0 14px 40px rgba(5, 42, 71, .08); }
        .rmr-preview-empty, .rmr-preview-card { border: 1px dashed rgba(4, 120, 87, .35); border-radius: 16px; padding: 18px; background: rgba(68, 211, 78, .06); }
        .rmr-preview-card { border-style: solid; background: #fff; }
        .rmr-preview-card .label { font-size: .78rem; color: #6c757d; margin-bottom: 2px; }
        .rmr-preview-card .value { font-weight: 700; color: #052A47; }
        .bg-success-subtle { background: rgba(68, 211, 78, .12) !important; }
        .text-success { color: #047857 !important; }
        .border-success-subtle { border-color: rgba(68, 211, 78, .35) !important; }
    </style>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
    // ========== SIDEBAR FUNCTIONS ==========
    
    // Helper function to set arrow state without layout shift
    function setArrowState(arrowElement, isOpen) {
        if (!arrowElement) return;
        if (isOpen) {
            arrowElement.style.transform = 'rotate(180deg)';
            arrowElement.style.willChange = 'transform';
        } else {
            arrowElement.style.transform = 'rotate(0deg)';
            arrowElement.style.willChange = '';
        }
    }
    
    // Toggle sidebar dropdown
    window.toggleSidebarDropdown = function(event, targetId) {
        if (event) {
            event.preventDefault();
            event.stopPropagation();
        }
        
        const target = document.getElementById(targetId);
        const btn = event ? event.currentTarget : null;
        const arrow = btn ? btn.querySelector('.dropdown-arrow') : null;
        const sidebar = document.getElementById('sidebar');
        
        if (!target) return false;
        
        // Check if sidebar is collapsed on desktop
        if (sidebar && window.innerWidth > 992 && sidebar.classList.contains('collapsed')) {
            // Expand sidebar first
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
                            setArrowState(otherArrow, false);
                        }
                    }
                });
                
                // Open the clicked dropdown
                target.classList.add('show');
                setArrowState(arrow, true);
            }, 50);
            return false;
        }
        
        // Normal behavior when sidebar is expanded or on mobile
        if (target.classList.contains('show')) {
            target.classList.remove('show');
            setArrowState(arrow, false);
        } else {
            // Close all other open dropdowns
            document.querySelectorAll('.sidebar .collapse.show').forEach(collapse => {
                if (collapse.id !== targetId) {
                    collapse.classList.remove('show');
                    const otherBtn = document.querySelector(`[onclick*="${collapse.id}"]`);
                    if (otherBtn) {
                        const otherArrow = otherBtn.querySelector('.dropdown-arrow');
                        setArrowState(otherArrow, false);
                    }
                }
            });
            
            target.classList.add('show');
            setArrowState(arrow, true);
        }
        
        return false;
    };
    
    // Toggle sidebar (collapse/expand)
    window.toggleSidebar = function() {
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
                // Expanding - restore active dropdowns
                setTimeout(function() {
                    document.querySelectorAll('.dropdown-nav').forEach(dropdownNav => {
                        const activeLink = dropdownNav.querySelector('.nav-link.active');
                        if (activeLink) {
                            const collapseDiv = dropdownNav.querySelector('.collapse');
                            if (collapseDiv && !collapseDiv.classList.contains('show')) {
                                collapseDiv.classList.add('show');
                                const parentLink = dropdownNav.querySelector(':scope > .nav-link');
                                if (parentLink) {
                                    const arrow = parentLink.querySelector('.dropdown-arrow');
                                    setArrowState(arrow, true);
                                }
                            }
                        }
                    });
                }, 150);
            } else if (sidebar.classList.contains('collapsed')) {
                // Collapsing - close all dropdowns
                document.querySelectorAll('.sidebar .collapse.show').forEach(function(collapse) {
                    collapse.classList.remove('show');
                    const parentBtn = document.querySelector(`[onclick*="${collapse.id}"]`);
                    if (parentBtn) {
                        const arrow = parentBtn.querySelector('.dropdown-arrow');
                        setArrowState(arrow, false);
                    }
                });
            }
        }
        return false;
    };
    
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
                        setArrowState(arrow, true);
                    }
                }
            }
        });
        
        // For sidebar collapsed mode - add active class to parent dropdown
        const sidebar = document.getElementById('sidebar');
        if (sidebar && sidebar.classList.contains('collapsed')) {
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
    
    // Initialize sidebar
    function initializeSidebar() {
        const sidebar = document.getElementById('sidebar');
        const desktopToggleBtn = document.getElementById('desktopToggleBtn');
        const mobileMenuBtn = document.getElementById('mobileMenuBtn');
        
        // Restore sidebar state from localStorage for desktop
        if (sidebar && window.innerWidth > 992) {
            const savedCollapsed = localStorage.getItem('sidebarCollapsed');
            if (savedCollapsed === 'true') {
                sidebar.classList.add('collapsed');
            } else {
                sidebar.classList.remove('collapsed');
            }
        }
        
        // Desktop toggle button
        if (desktopToggleBtn) {
            desktopToggleBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                window.toggleSidebar();
            });
        }
        
        // Mobile menu button
        if (mobileMenuBtn) {
            mobileMenuBtn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                window.toggleSidebar();
            });
        }
        
        // Set active sidebar item
        setActiveSidebarItem();
        
        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', function(e) {
            if (window.innerWidth <= 992 && sidebar && sidebar.classList.contains('active') && 
                !sidebar.contains(e.target) && mobileMenuBtn && !mobileMenuBtn.contains(e.target)) {
                sidebar.classList.remove('active');
                const overlay = document.querySelector('.sidebar-overlay');
                if (overlay) overlay.remove();
            }
        });
        
        // Prevent dropdown from closing when clicking inside it
        document.querySelectorAll('.sidebar .collapse').forEach(collapse => {
            collapse.addEventListener('click', function(e) {
                e.stopPropagation();
            });
        });
        
        // Handle window resize
        window.addEventListener('resize', function() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.querySelector('.sidebar-overlay');
            
            if (window.innerWidth > 992) {
                if (overlay) overlay.remove();
                if (sidebar) sidebar.classList.remove('active');
            } else {
                if (sidebar) sidebar.classList.remove('collapsed');
            }
        });
    }

    // ========== RMR FUNCTIONS ==========
    
    const rmrDropdown = document.getElementById('rmrRequestDropdown');
    const preview = document.getElementById('rmrRequestPreview');
    const tbody = document.getElementById('rmrItemsTableBody');
    const form = document.getElementById('rmrReceiveForm');
    const clearBtn = document.getElementById('clearRmrBtn');
    const saveBtn = document.getElementById('saveRmrBtn');

    function money(value) {
        return '₱' + (Number(value || 0)).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function alertBox(title, text, icon = 'info') {
        if (window.Swal) return Swal.fire({ title, text, icon, confirmButtonColor: '#047857' });
        alert(text);
    }

    function resetRmrForm() {
        rmrDropdown.value = '';
        document.getElementById('rmrMemo').value = '';
        preview.className = 'rmr-preview-empty mb-4';
        preview.innerHTML = '<i class="bi bi-box-arrow-in-down me-2"></i>Select an RMR request to view the item details.';
        tbody.innerHTML = '<tr class="text-center text-muted"><td colspan="7" class="py-4">No RMR selected yet</td></tr>';
    }

    rmrDropdown?.addEventListener('change', function () {
        const option = this.options[this.selectedIndex];
        if (!option || !option.value) { resetRmrForm(); return; }

        const qty = Number(option.dataset.qty || 0);
        const price = Number(option.dataset.price || 0);
        const total = qty * price;
        const customer = option.dataset.customer || 'N/A';
        const reason = option.dataset.reason || 'N/A';

        preview.className = 'rmr-preview-card mb-4';
        preview.innerHTML = `
            <div class="row g-3">
                <div class="col-md-3"><div class="label">Customer</div><div class="value">${escapeHtml(customer)}</div></div>
                <div class="col-md-3"><div class="label">Status</div><div class="value text-capitalize">${escapeHtml(option.dataset.status || 'N/A')}</div></div>
                <div class="col-md-3"><div class="label">Quantity</div><div class="value">${qty} ${escapeHtml(option.dataset.uom || '')}</div></div>
                <div class="col-md-3"><div class="label">Reason</div><div class="value">${escapeHtml(reason)}</div></div>
            </div>`;

        tbody.innerHTML = `
            <tr>
                <td>${escapeHtml(option.dataset.itemName || '')}</td>
                <td>${escapeHtml(option.dataset.itemCode || '')}</td>
                <td>${escapeHtml(option.dataset.itemDescription || '')}</td>
                <td class="text-end">${qty}</td>
                <td>${escapeHtml(option.dataset.uom || '')}</td>
                <td class="text-end">${money(price)}</td>
                <td class="text-end fw-semibold">${money(total)}</td>
            </tr>`;
    });

    clearBtn?.addEventListener('click', resetRmrForm);

    form?.addEventListener('submit', async function (e) {
        e.preventDefault();
        const rmrId = rmrDropdown.value;
        if (!rmrId) return alertBox('Missing RMR', 'Please select an RMR request first.', 'warning');

        const confirm = await Swal.fire({
            title: 'Save returned merchandise?',
            text: 'This will add the returned quantity back to inventory.',
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#047857',
            confirmButtonText: 'Yes, save it'
        });
        if (!confirm.isConfirmed) return;

        saveBtn.disabled = true;
        const oldText = saveBtn.innerHTML;
        saveBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Saving...';

        const data = new FormData();
        data.append('action', 'receive_rmr');
        data.append('rmr_id', rmrId);
        data.append('receive_date', document.getElementById('rmrReceiveDate').value);
        data.append('encoded_date', document.getElementById('rmrEncodedDate').value);
        data.append('memo', document.getElementById('rmrMemo').value);

        try {
            const response = await fetch(window.location.href, { method: 'POST', body: data });
            const result = await response.json();
            if (!result.success) throw new Error(result.message || 'Unable to save returned merchandise.');
            await alertBox('Saved', result.message || 'Return merchandise received successfully.', 'success');
            window.location.reload();
        } catch (err) {
            alertBox('Error', err.message, 'error');
        } finally {
            saveBtn.disabled = false;
            saveBtn.innerHTML = oldText;
        }
    });

    // Helper function to escape HTML
    function escapeHtml(str) {
        if (!str) return '';
        return str.replace(/[&<>]/g, function(m) {
            if (m === '&') return '&amp;';
            if (m === '<') return '&lt;';
            if (m === '>') return '&gt;';
            return m;
        }).replace(/[\uD800-\uDBFF][\uDC00-\uDFFF]/g, function(c) {
            return c;
        });
    }

    // Logout function
    window.logout = function() { 
        Swal.fire({
            title: 'Logout?',
            text: 'Are you sure you want to logout?',
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#047857',
            cancelButtonColor: '#64748b',
            confirmButtonText: 'Logout',
            cancelButtonText: 'Cancel'
        }).then(r => { 
            if(r.isConfirmed) window.location.href = '../logout.php'; 
        }); 
    };

    // Initialize everything when DOM is loaded
    document.addEventListener('DOMContentLoaded', function() {
        initializeSidebar();
    });
</script>
</body>
</html>
