<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
date_default_timezone_set('Asia/Manila');

require_once '../config/database.php';
require_once '../config/session_handler.php';

if (!$conn) {
    die('Database connection failed: ' . mysqli_connect_error());
}

$user_id = (int)($_SESSION['user_id'] ?? 0);
$user_role = strtolower(trim((string)($_SESSION['role'] ?? '')));
// Branch variables
$branch_id = (int)($_SESSION['branch_id'] ?? 0);
$branch_name = $_SESSION['branch_name'] ?? '';
$view_all_branches = ($_SESSION['view_all_branches'] ?? false);

if ($user_id <= 0) {
    header('Location: ../login.php');
    exit;
}

if ($user_role !== 'motorpool') {
    if ($user_role === 'branch_admin') {
        header('Location: ../Branch_Admin/branchdashboard.php');
    } elseif ($user_role === 'admin') {
        header('Location: ../Admin/dashboard.php');
    } else {
        header('Location: ../login.php');
    }
    exit;
}

$user_name = isset($_SESSION['first_name'])
    ? trim((string)$_SESSION['first_name'] . ' ' . (string)($_SESSION['last_name'] ?? ''))
    : 'Motorpool';

$user_initials = '';
foreach (explode(' ', trim($user_name)) as $part) {
    if ($part !== '') {
        $user_initials .= strtoupper(substr($part, 0, 1));
    }
}
if ($user_initials === '') {
    $user_initials = 'MP';
}

function h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function tableExists(mysqli $conn, string $table): bool {
    $table = $conn->real_escape_string($table);
    $result = $conn->query("SHOW TABLES LIKE '$table'");
    return $result && $result->num_rows > 0;
}

function columnExists(mysqli $conn, string $table, string $column): bool {
    if (!tableExists($conn, $table)) {
        return false;
    }
    $table = preg_replace('/[^A-Za-z0-9_]/', '', $table);
    $column = $conn->real_escape_string($column);
    $result = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    return $result && $result->num_rows > 0;
}

function addColumnIfMissing(mysqli $conn, string $table, string $column, string $definition): void {
    if (!columnExists($conn, $table, $column)) {
        @$conn->query("ALTER TABLE `$table` ADD COLUMN $definition");
    }
}

function ensureMotorpoolSupplierTables(mysqli $conn): void {
    $conn->query("CREATE TABLE IF NOT EXISTS `motorpool_suppliers` (
        `supplier_id` INT AUTO_INCREMENT PRIMARY KEY,
        `supplier_code` VARCHAR(50) NOT NULL UNIQUE,
        `supplier_name` VARCHAR(150) NOT NULL,
        `contact_person` VARCHAR(100) DEFAULT NULL,
        `email` VARCHAR(100) DEFAULT NULL,
        `phone_number` VARCHAR(30) DEFAULT NULL,
        `mobile_number` VARCHAR(30) DEFAULT NULL,
        `tax_id` VARCHAR(50) DEFAULT NULL,
        `vat_classification` VARCHAR(50) DEFAULT 'VAT Registered',
        `payment_terms` VARCHAR(100) DEFAULT 'Net 30',
        `credit_limit` DECIMAL(12,2) DEFAULT 0.00,
        `website` VARCHAR(255) DEFAULT NULL,
        `notes` TEXT DEFAULT NULL,
        `status` VARCHAR(30) DEFAULT 'active',
        `created_by` INT DEFAULT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY `idx_supplier_code` (`supplier_code`),
        KEY `idx_supplier_name` (`supplier_name`),
        KEY `idx_status` (`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $columns = [
        'contact_person' => "`contact_person` VARCHAR(100) DEFAULT NULL AFTER `supplier_name`",
        'email' => "`email` VARCHAR(100) DEFAULT NULL AFTER `contact_person`",
        'phone_number' => "`phone_number` VARCHAR(30) DEFAULT NULL AFTER `email`",
        'mobile_number' => "`mobile_number` VARCHAR(30) DEFAULT NULL AFTER `phone_number`",
        'tax_id' => "`tax_id` VARCHAR(50) DEFAULT NULL AFTER `mobile_number`",
        'vat_classification' => "`vat_classification` VARCHAR(50) DEFAULT 'VAT Registered' AFTER `tax_id`",
        'payment_terms' => "`payment_terms` VARCHAR(100) DEFAULT 'Net 30' AFTER `vat_classification`",
        'credit_limit' => "`credit_limit` DECIMAL(12,2) DEFAULT 0.00 AFTER `payment_terms`",
        'website' => "`website` VARCHAR(255) DEFAULT NULL AFTER `credit_limit`",
        'notes' => "`notes` TEXT DEFAULT NULL AFTER `website`",
        'status' => "`status` VARCHAR(30) DEFAULT 'active' AFTER `notes`",
        'created_by' => "`created_by` INT DEFAULT NULL AFTER `status`"
    ];

    foreach ($columns as $column => $definition) {
        addColumnIfMissing($conn, 'motorpool_suppliers', $column, $definition);
    }
}

function generateMotorpoolSupplierCode(mysqli $conn): string {
    $prefix = 'MP-SUP-' . date('Ym') . '-';
    $safePrefix = $conn->real_escape_string($prefix);
    $result = $conn->query("SELECT supplier_code FROM motorpool_suppliers WHERE supplier_code LIKE '{$safePrefix}%' ORDER BY supplier_id DESC LIMIT 1");
    $next = 1;
    if ($result && ($row = $result->fetch_assoc())) {
        $last = (string)$row['supplier_code'];
        if (preg_match('/(\d+)$/', $last, $match)) {
            $next = ((int)$match[1]) + 1;
        }
    }
    return $prefix . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
}

function getPaymentTermsOptions(string $selected = 'Net 30'): string {
    $options = ['Due on receipt', 'Net 15', 'Net 30', 'Net 45', 'Net 60', 'COD', '2/10 Net 30'];
    $html = '';
    foreach ($options as $option) {
        $html .= '<option value="' . h($option) . '" ' . ($selected === $option ? 'selected' : '') . '>' . h($option) . '</option>';
    }
    return $html;
}

function money($value): string {
    return number_format((float)$value, 2);
}

function getSupplierStatusClass($status): string {
    $status = strtolower(trim((string)$status));
    if ($status === 'active') return 'status-active';
    if ($status === 'inactive') return 'status-inactive';
    if ($status === 'pending') return 'status-pending';
    return 'status-secondary';
}


function ensureMotorpoolBillSupplierCompatibility(mysqli $conn): void {
    if (!tableExists($conn, 'motorpool_billexpenses')) {
        return;
    }

    if (!columnExists($conn, 'motorpool_billexpenses', 'supplier_name')) {
        if (columnExists($conn, 'motorpool_billexpenses', 'vendor_name')) {
            addColumnIfMissing($conn, 'motorpool_billexpenses', 'supplier_name', "`supplier_name` VARCHAR(255) DEFAULT NULL AFTER `vendor_name`");
            @$conn->query("UPDATE `motorpool_billexpenses`
                SET `supplier_name` = `vendor_name`
                WHERE TRIM(COALESCE(`supplier_name`,'')) = ''
                  AND TRIM(COALESCE(`vendor_name`,'')) <> ''");
        } elseif (columnExists($conn, 'motorpool_billexpenses', 'supplier')) {
            addColumnIfMissing($conn, 'motorpool_billexpenses', 'supplier_name', "`supplier_name` VARCHAR(255) DEFAULT NULL");
            @$conn->query("UPDATE `motorpool_billexpenses`
                SET `supplier_name` = `supplier`
                WHERE TRIM(COALESCE(`supplier_name`,'')) = ''
                  AND TRIM(COALESCE(`supplier`,'')) <> ''");
        } elseif (columnExists($conn, 'motorpool_billexpenses', 'account')) {
            addColumnIfMissing($conn, 'motorpool_billexpenses', 'supplier_name', "`supplier_name` VARCHAR(255) DEFAULT NULL");
            @$conn->query("UPDATE `motorpool_billexpenses`
                SET `supplier_name` = `account`
                WHERE TRIM(COALESCE(`supplier_name`,'')) = ''
                  AND TRIM(COALESCE(`account`,'')) <> ''");
        } else {
            addColumnIfMissing($conn, 'motorpool_billexpenses', 'supplier_name', "`supplier_name` VARCHAR(255) DEFAULT NULL");
        }
    }

    if (columnExists($conn, 'motorpool_billexpenses', 'vendor_name')) {
        @$conn->query("UPDATE `motorpool_billexpenses`
            SET `supplier_name` = `vendor_name`
            WHERE TRIM(COALESCE(`supplier_name`,'')) = ''
              AND TRIM(COALESCE(`vendor_name`,'')) <> ''");
    }

    if (!columnExists($conn, 'motorpool_billexpenses', 'total_amount') && columnExists($conn, 'motorpool_billexpenses', 'amount')) {
        addColumnIfMissing($conn, 'motorpool_billexpenses', 'total_amount', "`total_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00");
        @$conn->query("UPDATE `motorpool_billexpenses` SET `total_amount` = COALESCE(`amount`,0) WHERE COALESCE(`total_amount`,0) = 0");
    }
}

ensureMotorpoolSupplierTables($conn);
ensureMotorpoolBillSupplierCompatibility($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');

    try {
        $action = trim((string)$_POST['action']);
        $conn->begin_transaction();

        if ($action === 'generate_code') {
            $conn->commit();
            echo json_encode(['success' => true, 'supplier_code' => generateMotorpoolSupplierCode($conn)]);
            exit;
        }

        if ($action === 'add_supplier' || $action === 'update_supplier') {
            $supplier_id = (int)($_POST['supplier_id'] ?? 0);
            $supplier_code = trim((string)($_POST['supplier_code'] ?? ''));
            $supplier_name = trim((string)($_POST['supplier_name'] ?? ''));
            $contact_person = trim((string)($_POST['contact_person'] ?? ''));
            $email = trim((string)($_POST['email'] ?? ''));
            $phone_number = trim((string)($_POST['phone_number'] ?? ''));
            $mobile_number = trim((string)($_POST['mobile_number'] ?? ''));
            $tax_id = trim((string)($_POST['tax_id'] ?? ''));
            $vat_classification = trim((string)($_POST['vat_classification'] ?? 'VAT Registered'));
            $payment_terms = trim((string)($_POST['payment_terms'] ?? 'Net 30'));
            $credit_limit = (float)str_replace([',', '₱', ' '], '', (string)($_POST['credit_limit'] ?? 0));
            $website = trim((string)($_POST['website'] ?? ''));
            $notes = trim((string)($_POST['notes'] ?? ''));
            $status = strtolower(trim((string)($_POST['status'] ?? 'active')));

            if ($supplier_code === '') {
                $supplier_code = generateMotorpoolSupplierCode($conn);
            }
            if ($supplier_name === '') {
                throw new Exception('Supplier name is required.');
            }
            if (!in_array($status, ['active', 'inactive', 'pending'], true)) {
                $status = 'active';
            }
            if ($credit_limit < 0) {
                throw new Exception('Credit limit cannot be negative.');
            }

            if ($action === 'add_supplier') {
                $stmt = $conn->prepare("INSERT INTO motorpool_suppliers
                    (supplier_code, supplier_name, contact_person, email, phone_number, mobile_number, tax_id, vat_classification, payment_terms, credit_limit, website, notes, status, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                if (!$stmt) {
                    throw new Exception('Failed to prepare supplier insert: ' . $conn->error);
                }
                $stmt->bind_param('sssssssssdsssi', $supplier_code, $supplier_name, $contact_person, $email, $phone_number, $mobile_number, $tax_id, $vat_classification, $payment_terms, $credit_limit, $website, $notes, $status, $user_id);
                if (!$stmt->execute()) {
                    throw new Exception('Failed to add supplier: ' . $stmt->error);
                }
                $stmt->close();
                $message = 'Motorpool supplier added successfully.';
            } else {
                if ($supplier_id <= 0) {
                    throw new Exception('Supplier record was not found.');
                }
                $stmt = $conn->prepare("UPDATE motorpool_suppliers
                    SET supplier_code=?, supplier_name=?, contact_person=?, email=?, phone_number=?, mobile_number=?, tax_id=?, vat_classification=?, payment_terms=?, credit_limit=?, website=?, notes=?, status=?, updated_at=NOW()
                    WHERE supplier_id=?");
                if (!$stmt) {
                    throw new Exception('Failed to prepare supplier update: ' . $conn->error);
                }
                $stmt->bind_param('sssssssssdsssi', $supplier_code, $supplier_name, $contact_person, $email, $phone_number, $mobile_number, $tax_id, $vat_classification, $payment_terms, $credit_limit, $website, $notes, $status, $supplier_id);
                if (!$stmt->execute()) {
                    throw new Exception('Failed to update supplier: ' . $stmt->error);
                }
                $stmt->close();
                $message = 'Motorpool supplier updated successfully.';
            }

            // Keep common supplier text available to Motorpool inventory supplier history.
            if (tableExists($conn, 'motorpool_inventory_items')) {
                // No update here; this page owns supplier master data only.
            }

            $conn->commit();
            echo json_encode(['success' => true, 'message' => $message]);
            exit;
        }

        if ($action === 'delete_supplier') {
            $supplier_id = (int)($_POST['supplier_id'] ?? 0);
            if ($supplier_id <= 0) {
                throw new Exception('Supplier record was not found.');
            }

            $supplierName = '';
            $stmt = $conn->prepare("SELECT supplier_name FROM motorpool_suppliers WHERE supplier_id = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('i', $supplier_id);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $supplierName = trim((string)($row['supplier_name'] ?? ''));
                $stmt->close();
            }

            $hasRecords = false;
            if ($supplierName !== '' && tableExists($conn, 'motorpool_billexpenses')) {
                $stmt = $conn->prepare("SELECT expense_id FROM motorpool_billexpenses WHERE supplier_name = ? LIMIT 1");
                if ($stmt) {
                    $stmt->bind_param('s', $supplierName);
                    $stmt->execute();
                    $hasRecords = (bool)$stmt->get_result()->fetch_assoc();
                    $stmt->close();
                }
            }

            if ($hasRecords) {
                $stmt = $conn->prepare("UPDATE motorpool_suppliers SET status='inactive', updated_at=NOW() WHERE supplier_id=?");
                $stmt->bind_param('i', $supplier_id);
                $stmt->execute();
                $stmt->close();
                $message = 'Supplier has Motorpool transactions. Status changed to inactive.';
            } else {
                $stmt = $conn->prepare("DELETE FROM motorpool_suppliers WHERE supplier_id=?");
                $stmt->bind_param('i', $supplier_id);
                $stmt->execute();
                $stmt->close();
                $message = 'Motorpool supplier deleted successfully.';
            }

            $conn->commit();
            echo json_encode(['success' => true, 'message' => $message]);
            exit;
        }

        if ($action === 'get_supplier') {
            $supplier_id = (int)($_POST['supplier_id'] ?? 0);
            $stmt = $conn->prepare("SELECT * FROM motorpool_suppliers WHERE supplier_id = ? LIMIT 1");
            if (!$stmt) {
                throw new Exception('Failed to prepare supplier query: ' . $conn->error);
            }
            $stmt->bind_param('i', $supplier_id);
            $stmt->execute();
            $supplier = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$supplier) {
                throw new Exception('Supplier not found.');
            }

            $transactions = [];
            if (tableExists($conn, 'motorpool_billexpenses')) {
                $stmt = $conn->prepare("SELECT expense_no, expense_date, total_amount, status FROM motorpool_billexpenses WHERE supplier_name = ? ORDER BY expense_id DESC LIMIT 10");
                if ($stmt) {
                    $supplierName = (string)$supplier['supplier_name'];
                    $stmt->bind_param('s', $supplierName);
                    $stmt->execute();
                    $transactions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                    $stmt->close();
                }
            }

            $conn->commit();
            echo json_encode(['success' => true, 'supplier' => $supplier, 'purchase_orders' => $transactions]);
            exit;
        }

        if ($action === 'get_all_suppliers' || $action === 'print_suppliers') {
            $result = $conn->query("SELECT * FROM motorpool_suppliers ORDER BY supplier_name ASC");
            $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
            $conn->commit();
            echo json_encode(['success' => true, 'suppliers' => $rows, 'branch_name' => 'Motorpool']);
            exit;
        }

        throw new Exception('Invalid action.');
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

// Seed Motorpool supplier master from existing Motorpool inventory supplier text and Motorpool bills.
$seedNames = [];
if (tableExists($conn, 'motorpool_inventory_items') && columnExists($conn, 'motorpool_inventory_items', 'supplier')) {
    $result = $conn->query("SELECT DISTINCT supplier FROM motorpool_inventory_items WHERE TRIM(COALESCE(supplier,'')) <> ''");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $seedNames[] = trim((string)$row['supplier']);
        }
    }
}
if (tableExists($conn, 'motorpool_billexpenses') && columnExists($conn, 'motorpool_billexpenses', 'supplier_name')) {
    $result = $conn->query("SELECT DISTINCT supplier_name FROM motorpool_billexpenses WHERE TRIM(COALESCE(supplier_name,'')) <> ''");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $seedNames[] = trim((string)$row['supplier_name']);
        }
    }
}
$seedNames = array_values(array_unique(array_filter($seedNames)));
foreach ($seedNames as $name) {
    $stmt = $conn->prepare("SELECT supplier_id FROM motorpool_suppliers WHERE LOWER(TRIM(supplier_name)) = LOWER(TRIM(?)) LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('s', $name);
        $stmt->execute();
        $exists = (bool)$stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$exists) {
            $code = generateMotorpoolSupplierCode($conn);
            $terms = 'Net 30';
            $status = 'active';
            $stmt = $conn->prepare("INSERT INTO motorpool_suppliers (supplier_code, supplier_name, payment_terms, status, created_by) VALUES (?, ?, ?, ?, ?)");
            if ($stmt) {
                $stmt->bind_param('ssssi', $code, $name, $terms, $status, $user_id);
                @$stmt->execute();
                $stmt->close();
            }
        }
    }
}

$result = $conn->query("SELECT ms.*,
    (SELECT COUNT(*) FROM motorpool_billexpenses mbe WHERE mbe.supplier_name = ms.supplier_name) AS po_count,
    (SELECT COALESCE(SUM(total_amount), 0) FROM motorpool_billexpenses mbe WHERE mbe.supplier_name = ms.supplier_name) AS total_spent
    FROM motorpool_suppliers ms
    ORDER BY ms.supplier_name ASC");
$suppliers = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];

$total_suppliers = count($suppliers);
$active_suppliers = count(array_filter($suppliers, fn($s) => strtolower((string)$s['status']) === 'active'));
$inactive_suppliers = count(array_filter($suppliers, fn($s) => strtolower((string)$s['status']) === 'inactive'));
$pending_suppliers = count(array_filter($suppliers, fn($s) => strtolower((string)$s['status']) === 'pending'));
$total_spent = array_sum(array_map(fn($s) => (float)($s['total_spent'] ?? 0), $suppliers));
$preview_code = generateMotorpoolSupplierCode($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Motorpool Supplier List</title>
    <link rel="icon" type="image/png" href="../Pictures/favicon-96x96.png" sizes="96x96">
    <link rel="stylesheet" href="../css/current_inventory.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <script src="../js/session-checker.js"></script>

    <style>
        body,
        input,
        select,
        textarea,
        button,
        table,
        .main-content {
            font-weight: 400 !important;
        }

        .main-content {
            padding: 20px;
        }

        .mobile-page-bar {
            display: none;
            margin-bottom: 12px;
        }

        .page-header-card {
            background: #fff;
            border: 1px solid #d9efe3;
            border-radius: 12px;
            padding: 14px 18px;
            margin-bottom: 14px;
        }

        .page-title {
            margin: 0;
            color: #0b2f4f;
            font-size: 22px;
            font-weight: 500 !important;
        }

        .page-subtitle {
            margin: 2px 0 0;
            color: #64748b;
            font-size: 13px;
            font-weight: 400 !important;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 12px;
            margin-bottom: 14px;
        }

        .stat-card {
            background: linear-gradient(135deg, #047857, #059669);
            color: white;
            border-radius: 12px;
            padding: 14px 16px;
            min-height: 92px;
            display: flex;
            gap: 12px;
            align-items: flex-start;
        }

        .stat-card i {
            font-size: 22px;
            margin-top: 3px;
        }

        .stat-value {
            font-size: 22px;
            line-height: 1.1;
            font-weight: 500 !important;
        }

        .stat-label,
        .stat-small {
            font-size: 12px;
            font-weight: 400 !important;
        }

        .content-card {
            background: #fff;
            border: 1px solid #d9efe3;
            border-radius: 12px;
            padding: 14px;
        }

        .toolbar-row {
            display: flex;
            align-items: end;
            justify-content: space-between;
            gap: 10px;
            flex-wrap: wrap;
            margin-bottom: 12px;
        }

        .filter-group {
            display: flex;
            align-items: end;
            gap: 10px;
            flex-wrap: wrap;
        }

        .filter-field label {
            display: block;
            margin-bottom: 3px;
            color: #344054;
            font-size: 12px;
            font-weight: 400 !important;
        }

        .filter-control,
        .form-control,
        .form-select {
            height: 34px;
            border: 1px solid #d0d7de;
            border-radius: 7px;
            padding: 5px 9px;
            font-size: 13px;
            font-weight: 400 !important;
        }

        textarea.form-control {
            height: auto;
            min-height: 62px;
        }

        .btn-main {
            border: 0;
            border-radius: 8px;
            padding: 7px 12px;
            background: linear-gradient(135deg, #047857, #059669);
            color: white;
            font-size: 13px;
            font-weight: 400 !important;
        }

        .btn-soft {
            border: 1px solid #c7d2fe;
            border-radius: 8px;
            padding: 7px 12px;
            background: #fff;
            color: #047857;
            font-size: 13px;
            font-weight: 400 !important;
        }

        .table-container {
            overflow-x: auto;
        }

        .supplier-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
        }

        .supplier-table th {
            padding: 9px 10px;
            background: #f8fafc;
            border-bottom: 1px solid #dbe3ea;
            color: #344054;
            font-size: 12px;
            font-weight: 400 !important;
            text-align: left;
            white-space: nowrap;
        }

        .supplier-table td {
            padding: 8px 10px;
            border-bottom: 1px solid #edf2f7;
            font-size: 13px;
            color: #1f2937;
            font-weight: 400 !important;
            vertical-align: middle;
        }

        .supplier-table tbody tr:hover {
            background: #f8fff8;
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 72px;
            border-radius: 20px;
            padding: 3px 9px;
            font-size: 11px;
            font-weight: 400 !important;
        }

        .status-active { background: #dcfce7; color: #166534; }
        .status-inactive { background: #fee2e2; color: #991b1b; }
        .status-pending { background: #fef3c7; color: #92400e; }
        .status-secondary { background: #e5e7eb; color: #374151; }

        .action-buttons {
            display: flex;
            gap: 5px;
            justify-content: center;
        }

        .btn-action {
            width: 30px;
            height: 30px;
            border: 0;
            border-radius: 7px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
        }

        .btn-view { background: #e0f2fe; color: #0369a1; }
        .btn-edit { background: #f3e8ff; color: #7e22ce; }
        .btn-delete { background: #fee2e2; color: #dc2626; }

        .modal-title,
        .form-section-title {
            font-weight: 500 !important;
        }

        .form-section-title {
            margin: 6px 0 10px;
            padding-bottom: 6px;
            border-bottom: 1px solid #e5e7eb;
            color: #047857;
            font-size: 14px;
        }

        .code-preview {
            background: #f8fafc;
            border: 1px solid #d0d7de;
            border-radius: 7px;
            padding: 8px 10px;
            color: #047857;
            font-family: monospace;
            font-size: 14px;
            font-weight: 400 !important;
        }

        .empty-state-table {
            text-align: center;
            padding: 34px 14px;
            color: #64748b;
        }

        .mobile-page-bar {
            display: none;
        }

        .sidebar-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, .35);
            z-index: 998;
            display: none;
        }

        .sidebar-overlay.active {
            display: block;
        }

        @media (max-width: 992px) {
            .main-content {
                margin-left: 0 !important;
            }

            .mobile-page-bar {
                display: block;
            }

            .sidebar {
                transform: translateX(-100%);
                transition: transform .25s ease;
                z-index: 999;
            }

            .sidebar.active,
            .sidebar.show {
                transform: translateX(0);
            }

            .sidebar.collapsed {
                width: 300px;
            }

            .stats-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 600px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
        }


        /* ===== SUPPLIER.PHP CONTENT STYLE ===== */
        .main-content {
            padding: 18px 22px 28px !important;
            background: #f7fbf8 !important;
        }

        .supplier-page-header {
            background: #fff;
            border: 1px solid #c9f3d8;
            border-radius: 13px;
            padding: 17px 25px;
            margin-bottom: 20px;
        }

        .supplier-page-header h1 {
            margin: 0;
            color: #0b2f4f;
            font-size: 24px;
            line-height: 1.2;
            font-weight: 500 !important;
        }

        .supplier-page-header p {
            margin: 3px 0 0;
            color: #64748b;
            font-size: 14px;
            font-weight: 400 !important;
        }

        .supplier-stats-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 10px;
            margin-bottom: 22px;
        }

        .supplier-stat-box {
            background: linear-gradient(135deg, #047857, #059669);
            color: #fff;
            border-radius: 11px;
            min-height: 120px;
            padding: 20px 22px;
            display: flex;
            align-items: flex-start;
            gap: 14px;
            box-shadow: 0 9px 18px rgba(0,0,0,0.08);
        }

        .supplier-stat-box i {
            font-size: 27px;
            opacity: .9;
            margin-top: 4px;
        }

        .supplier-stat-value {
            font-size: 25px;
            line-height: 1;
            margin-bottom: 4px;
            font-weight: 600 !important;
        }

        .supplier-stat-label {
            font-size: 13px;
            font-weight: 500 !important;
        }

        .supplier-stat-box small {
            display: block;
            margin-top: 12px;
            font-size: 11px;
            color: rgba(255,255,255,.92);
            font-weight: 400 !important;
        }

        .supplier-filter-card {
            background: #fff;
            border: 1px solid #e3eaf0;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(15,23,42,0.05);
            margin-bottom: 24px;
            overflow: hidden;
        }

        .supplier-filter-header {
            width: 100%;
            min-height: 62px;
            border: 0;
            background: #fff;
            padding: 0 22px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            color: #0f172a;
            font-size: 16px;
            font-weight: 500 !important;
            cursor: pointer;
        }

        .supplier-filter-header span {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            font-weight: 500 !important;
        }

        .supplier-filter-header i:first-child {
            color: #22c55e;
        }

        .supplier-filter-header[aria-expanded="true"] .bi-chevron-down {
            transform: rotate(180deg);
        }

        .supplier-filter-body {
            border-top: 1px solid #eef2f7;
            padding: 18px 22px;
        }

        .supplier-filter-body.collapsed {
            display: none;
        }

        .supplier-filter-row {
            display: flex;
            align-items: flex-end;
            gap: 14px;
            flex-wrap: wrap;
        }

        .supplier-filter-item {
            min-width: 190px;
            flex: 1;
        }

        .supplier-filter-search {
            flex: 2;
        }

        .supplier-filter-item label {
            display: block;
            font-size: 11px;
            color: #334155;
            margin-bottom: 5px;
            text-transform: uppercase;
            letter-spacing: .3px;
            font-weight: 500 !important;
        }

        .supplier-filter-item select,
        .supplier-filter-item input {
            width: 100%;
            height: 38px;
            border: 1px solid #d5dde5;
            border-radius: 8px;
            padding: 7px 10px;
            font-size: 13px;
            font-weight: 400 !important;
            background: #fff;
        }

        .supplier-search-box {
            position: relative;
        }

        .supplier-search-box i {
            position: absolute;
            left: 11px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
        }

        .supplier-search-box input {
            padding-left: 34px;
        }

        .supplier-action-row {
            display: flex;
            justify-content: flex-end;
            align-items: center;
            gap: 12px;
            margin-bottom: 20px;
        }

        .btn-outline-action,
        .btn-add-supplier {
            height: 42px;
            border-radius: 10px;
            padding: 0 20px;
            font-size: 14px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            cursor: pointer;
            font-weight: 500 !important;
        }

        .btn-outline-action {
            background: #fff;
            color: #047857;
            border: 1px solid #059669;
        }

        .btn-add-supplier {
            background: linear-gradient(135deg, #047857, #059669);
            color: #fff;
            border: 0;
            box-shadow: 0 8px 16px rgba(4,120,87,.18);
        }

        .supplier-table-wrap {
            width: 100%;
            overflow-x: auto;
            background: #fff;
        }

        .supplier-main-table {
            width: 100%;
            border-collapse: collapse;
            background: #fff;
            margin: 0;
        }

        .supplier-main-table thead th {
            background: #087f5b !important;
            color: #fff !important;
            padding: 15px 22px !important;
            font-size: 13px !important;
            text-transform: uppercase;
            letter-spacing: .4px;
            border: 0 !important;
            font-weight: 600 !important;
            text-align: left;
            white-space: nowrap;
        }

        .supplier-main-table tbody td {
            padding: 17px 22px !important;
            font-size: 14px !important;
            color: #0b2f4f !important;
            border: 0 !important;
            vertical-align: middle;
            font-weight: 400 !important;
        }

        .supplier-main-table tbody tr:nth-child(even) {
            background: #ccf8df;
        }

        .supplier-main-table tbody tr:nth-child(odd) {
            background: #f8fffb;
        }

        .supplier-main-table tbody tr:hover {
            background: #dbfae8 !important;
        }

        .supplier-main-table .col-code {
            font-weight: 600 !important;
            color: #032d4d !important;
            white-space: nowrap;
        }

        .supplier-name-text {
            display: inline-block;
            margin-right: 8px;
            color: #0b2f4f;
        }

        .po-count-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 64px;
            padding: 5px 12px;
            background: #d7f7ff;
            border: 1px solid #8fe6f5;
            color: #0ea5c6;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 400 !important;
        }

        .status-badge {
            min-width: 88px !important;
            padding: 8px 14px !important;
            border-radius: 999px !important;
            font-size: 13px !important;
            font-weight: 400 !important;
        }

        .status-active {
            background: #d9f2df !important;
            color: #047120 !important;
        }

        .status-inactive {
            background: #fde2e2 !important;
            color: #b91c1c !important;
        }

        .status-pending {
            background: #fff2c8 !important;
            color: #92400e !important;
        }

        .action-buttons {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
        }

        .btn-action {
            width: 34px !important;
            height: 34px !important;
            border-radius: 8px !important;
            border: 0 !important;
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            text-decoration: none !important;
            font-size: 14px !important;
            cursor: pointer;
        }

        .btn-call { background: #fde68a !important; color: #d97706 !important; }
        .btn-message { background: #d9f99d !important; color: #16a34a !important; }
        .btn-edit { background: #f3d8ff !important; color: #9333ea !important; }
        .btn-delete { background: #ffdcdc !important; color: #ef4444 !important; }

        @media (max-width: 992px) {
            .supplier-stats-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
            .supplier-action-row {
                justify-content: stretch;
                flex-wrap: wrap;
            }
            .btn-outline-action,
            .btn-add-supplier {
                flex: 1;
            }
        }

        @media (max-width: 640px) {
            .supplier-stats-grid {
                grid-template-columns: 1fr;
            }
            .supplier-page-header h1 {
                font-size: 21px;
            }
            .supplier-filter-row {
                flex-direction: column;
                align-items: stretch;
            }
            .supplier-filter-item,
            .supplier-filter-search,
            .supplier-filter-actions {
                width: 100%;
            }
        }

        /* ===== STAT CARD COLOR/ICON FIX ===== */
        .supplier-stat-box,
        .supplier-stat-box *,
        .supplier-stat-box .supplier-stat-value,
        .supplier-stat-box .supplier-stat-label,
        .supplier-stat-box small {
            color: #ffffff !important;
        }

        .supplier-stat-box {
            background: linear-gradient(135deg, #047857, #059669) !important;
            color: #ffffff !important;
            border: none !important;
            min-height: 120px !important;
            padding: 20px 22px !important;
            display: flex !important;
            align-items: flex-start !important;
            justify-content: flex-start !important;
            gap: 14px !important;
            box-shadow: 0 9px 18px rgba(0,0,0,0.08) !important;
        }

        .supplier-stat-box i {
            color: #ffffff !important;
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            min-width: 44px !important;
            width: 44px !important;
            height: 44px !important;
            font-size: 32px !important;
            line-height: 1 !important;
            opacity: .95 !important;
            margin: 2px 0 0 0 !important;
            flex-shrink: 0 !important;
        }

        .supplier-stat-box > div {
            display: flex !important;
            flex-direction: column !important;
            align-items: flex-start !important;
            text-align: left !important;
            line-height: 1.25 !important;
        }

        .supplier-stat-value {
            color: #ffffff !important;
            font-size: 26px !important;
            line-height: 1.1 !important;
            margin: 0 0 4px 0 !important;
            font-weight: 600 !important;
            text-align: left !important;
        }

        .supplier-stat-label {
            color: #ffffff !important;
            font-size: 13px !important;
            line-height: 1.2 !important;
            font-weight: 500 !important;
            text-align: left !important;
        }

        .supplier-stat-box small {
            color: rgba(255,255,255,.95) !important;
            display: block !important;
            margin-top: 12px !important;
            font-size: 11px !important;
            font-weight: 400 !important;
            text-align: left !important;
        }

        .supplier-stat-box svg,
        .supplier-stat-box path {
            color: #ffffff !important;
            fill: currentColor !important;
        }


        /* ===== TABLE HEADER + ADD SUPPLIER MODAL CLEAN FIX ===== */
        .supplier-main-table thead th,
        .supplier-main-table thead th.col-code,
        .supplier-main-table thead th.col-name,
        .supplier-main-table thead th.col-contact,
        .supplier-main-table thead th.col-phone,
        .supplier-main-table thead th.col-email,
        .supplier-main-table thead th.col-payment,
        .supplier-main-table thead th.col-status,
        .supplier-main-table thead th.col-actions {
            background: #087f5b !important;
            color: #ffffff !important;
            font-weight: 500 !important;
        }

        .supplier-main-table tbody td.col-code {
            color: #032d4d !important;
            font-weight: 500 !important;
        }

        #supplierModal .modal-dialog {
            max-width: 980px !important;
        }

        #supplierModal .modal-content {
            max-height: none !important;
            overflow: visible !important;
            border-radius: 14px !important;
        }

        #supplierModal .modal-body {
            overflow: visible !important;
            max-height: none !important;
            padding: 12px 16px !important;
        }

        #supplierModal .modal-header,
        #supplierModal .modal-footer {
            padding: 10px 16px !important;
        }

        #supplierModal .form-section-title {
            margin: 4px 0 8px !important;
            padding-bottom: 4px !important;
            font-size: 13px !important;
        }

        #supplierModal .row.g-2 {
            --bs-gutter-x: .5rem !important;
            --bs-gutter-y: .35rem !important;
        }

        #supplierModal label.form-label {
            margin-bottom: 2px !important;
            font-size: 12px !important;
            font-weight: 400 !important;
        }

        #supplierModal .form-control,
        #supplierModal .form-select {
            height: 30px !important;
            min-height: 30px !important;
            padding: 4px 8px !important;
            font-size: 12px !important;
            font-weight: 400 !important;
        }

        #supplierModal textarea.form-control {
            height: 46px !important;
            min-height: 46px !important;
            resize: none !important;
        }

        #supplierModal .mt-3 {
            margin-top: .5rem !important;
        }

        .mobile-nav {
    position: fixed;
    bottom: 0;
    left: 0;
    right: 0;
    background: #ffffff;
    border-top: 1px solid #e5e7eb;
    box-shadow: 0 -2px 12px rgba(0,0,0,.08);
    z-index: 9999;
    display: none;
    padding: 8px 0 12px;
    overflow: visible !important;
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

.mobile-nav .nav::-webkit-scrollbar { display: none; }
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
    padding: 6px 10px;
    color: #9ca3af;
    font-size: .7rem;
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
    line-height: 1;
    margin: 0;
}

.mobile-nav .nav-link span {
    font-size: .65rem;
    font-weight: 600;
}

.mobile-nav .nav-link.active {
    color: #059669;
    background: rgba(5, 150, 105, .1);
}

.mobile-nav .more-dropdown {
    position: absolute;
    bottom: calc(100% + 8px);
    left: 50%;
    transform: translateX(-50%) translateY(8px);
    background: #ffffff;
    border-radius: 12px;
    box-shadow: 0 4px 20px rgba(0,0,0,.15);
    border: 1px solid #e5e7eb;
    min-width: 205px;
    z-index: 10000;
    display: none !important;
    opacity: 0;
    visibility: hidden;
    pointer-events: none;
    transition: opacity .2s ease, transform .2s ease;
    overflow: hidden;
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
    background: #ffffff;
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
    border-bottom: 1px solid #f3f4f6;
    font-size: .85rem;
    background: #ffffff;
    width: 100%;
    text-align: left;
    cursor: pointer;
}

.mobile-nav .dropdown-item:last-child { border-bottom: none; }
.mobile-nav .dropdown-item:hover { background: #f9fafb; }
.mobile-nav .dropdown-item.active {
    background: rgba(5, 150, 105, .1);
    color: #059669;
}
.mobile-nav .dropdown-item i {
    width: 20px;
    font-size: 1rem;
    color: #6b7280;
}
.mobile-nav .dropdown-item.active i { color: #059669; }

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
                                <a class="nav-link active" href="vendors.php">
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

    <main class="main-content no-spinner" id="mainContent">
        <div class="page-content active">
            <div class="navbar-top">
            <button class="mobile-toggle-btn" id="mobileToggleBtn" type="button">
                <i class="bi bi-list"></i>
            </button>
                <div class="page-title">
                    <h2>Suppliers</h2>
                    <p>Manage suppliers for motorpool purchase orders</p>
                </div>
            </div>
        </div>

        <div class="supplier-stats-grid">
            <div class="supplier-stat-box">
                <i class="bi bi-building"></i>
                <div>
                    <div class="supplier-stat-value"><?php echo (int)$total_suppliers; ?></div>
                    <div class="supplier-stat-label">Total Suppliers</div>
                    <small>Motorpool</small>
                </div>
            </div>
            <div class="supplier-stat-box">
                <i class="bi bi-check-circle"></i>
                <div>
                    <div class="supplier-stat-value"><?php echo (int)$active_suppliers; ?></div>
                    <div class="supplier-stat-label">Active</div>
                    <small>Available suppliers</small>
                </div>
            </div>
            <div class="supplier-stat-box">
                <i class="bi bi-clock-history"></i>
                <div>
                    <div class="supplier-stat-value"><?php echo (int)$pending_suppliers; ?></div>
                    <div class="supplier-stat-label">Pending</div>
                    <small>For review</small>
                </div>
            </div>
            <div class="supplier-stat-box">
                <i class="bi bi-cash-stack"></i>
                <div>
                    <div class="supplier-stat-value">₱<?php echo $total_spent >= 100000 ? number_format($total_spent / 1000, 1) . 'K' : money($total_spent); ?></div>
                    <div class="supplier-stat-label">Total Spent</div>
                    <small>Motorpool bills</small>
                </div>
            </div>
        </div>

        <div class="supplier-filter-card">
            <button class="supplier-filter-header" type="button" onclick="toggleSupplierFilter()" aria-expanded="false" id="supplierFilterToggle">
                <span><i class="bi bi-funnel"></i> Filter Suppliers</span>
                <i class="bi bi-chevron-down"></i>
            </button>
            <div class="supplier-filter-body collapsed" id="supplierFilterBody">
                <div class="supplier-filter-row">
                    <div class="supplier-filter-item">
                        <label>Status</label>
                        <select id="statusFilter" onchange="filterSuppliers()">
                            <option value="all">All Status</option>
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                            <option value="pending">Pending</option>
                        </select>
                    </div>
                    <div class="supplier-filter-item supplier-filter-search">
                        <label>Global Search</label>
                        <div class="supplier-search-box">
                            <i class="bi bi-search"></i>
                            <input type="text" id="searchInput" placeholder="Search supplier, contact, phone, email..." onkeyup="filterSuppliers()">
                        </div>
                    </div>
                    <div class="supplier-filter-actions">
                        <button type="button" class="btn-outline-action" onclick="resetSupplierFilters()">
                            <i class="bi bi-arrow-counterclockwise"></i> Reset
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <div class="supplier-action-row">
            <button class="btn-outline-action" type="button" onclick="printSuppliers()">
                <i class="bi bi-printer"></i> Print
            </button>
            <button class="btn-outline-action" type="button" onclick="exportSuppliers()">
                <i class="bi bi-file-earmark-excel"></i> Export
            </button>
            <button class="btn-add-supplier" type="button" onclick="openAddModal()">
                <i class="bi bi-plus-circle"></i> Add Supplier
            </button>
        </div>

        <div class="supplier-table-wrap">
            <table class="supplier-table supplier-main-table" id="supplierTable">
                <thead>
                    <tr>
                        <th class="col-code">Code</th>
                        <th class="col-name">Supplier Name</th>
                        <th class="col-contact">Contact Person</th>
                        <th class="col-phone">Phone</th>
                        <th class="col-email">Email</th>
                        <th class="col-payment">Payment Terms</th>
                        <th class="col-status">Status</th>
                        <th class="col-actions">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($suppliers)): ?>
                        <tr>
                            <td colspan="8">
                                <div class="empty-state-table">
                                    <i class="bi bi-shop"></i>
                                    <h5>No Motorpool suppliers yet</h5>
                                    <p>Add a supplier to start managing Motorpool supplier records.</p>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($suppliers as $supplier): ?>
                            <?php
                                $phoneDisplay = trim((string)($supplier['phone_number'] ?? ''));
                                if ($phoneDisplay === '') $phoneDisplay = trim((string)($supplier['mobile_number'] ?? ''));
                                $poCount = (int)($supplier['po_count'] ?? 0);
                                $supplierName = (string)($supplier['supplier_name'] ?? '');
                            ?>
                            <tr data-status="<?php echo h(strtolower((string)$supplier['status'])); ?>" data-search="<?php echo h(strtolower(($supplier['supplier_code'] ?? '') . ' ' . $supplierName . ' ' . ($supplier['contact_person'] ?? '') . ' ' . ($supplier['email'] ?? '') . ' ' . $phoneDisplay)); ?>">
                                <td class="col-code"><?php echo h($supplier['supplier_code'] ?? ''); ?></td>
                                <td class="col-name">
                                    <span class="supplier-name-text"><?php echo h($supplierName); ?></span>
                                    <span class="po-count-badge"><?php echo $poCount; ?> POs</span>
                                </td>
                                <td class="col-contact"><?php echo h($supplier['contact_person'] ?? ''); ?></td>
                                <td class="col-phone"><?php echo $phoneDisplay !== '' ? h($phoneDisplay) : '—'; ?></td>
                                <td class="col-email"><?php echo trim((string)($supplier['email'] ?? '')) !== '' ? h($supplier['email']) : ''; ?></td>
                                <td class="col-payment"><?php echo h($supplier['payment_terms'] ?? 'Net 30'); ?></td>
                                <td class="col-status"><span class="status-badge <?php echo h(getSupplierStatusClass($supplier['status'] ?? '')); ?>"><?php echo h(ucfirst((string)($supplier['status'] ?? 'active'))); ?></span></td>
                                <td class="col-actions">
                                    <div class="action-buttons">
                                        <?php if ($phoneDisplay !== ''): ?>
                                            <a class="btn-action btn-call" title="Call" href="tel:<?php echo h($phoneDisplay); ?>"><i class="bi bi-telephone"></i></a>
                                        <?php endif; ?>
                                        <?php if ($phoneDisplay !== ''): ?>
                                            <a class="btn-action btn-message" title="Message" href="sms:<?php echo h($phoneDisplay); ?>"><i class="bi bi-chat"></i></a>
                                        <?php endif; ?>
                                        <button class="btn-action btn-edit" type="button" title="Edit" onclick="editSupplier(<?php echo (int)$supplier['supplier_id']; ?>)"><i class="bi bi-pencil"></i></button>
                                        <button class="btn-action btn-delete" type="button" title="Delete" onclick="deleteSupplier(<?php echo (int)$supplier['supplier_id']; ?>)"><i class="bi bi-trash"></i></button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </main>
</div>
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<div class="modal fade" id="supplierModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form id="supplierForm">
                <div class="modal-header">
                    <h5 class="modal-title" id="supplierModalTitle">Add Supplier</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" id="formAction" value="add_supplier">
                    <input type="hidden" name="supplier_id" id="supplierId">

                    <div class="form-section-title">Supplier Information</div>
                    <div class="row g-2">
                        <div class="col-md-4">
                            <label class="form-label">Vendor Code</label>
                            <input type="text" class="form-control" name="supplier_code" id="supplierCode" value="<?php echo h($preview_code); ?>">
                        </div>
                        <div class="col-md-8">
                            <label class="form-label">Supplier Name</label>
                            <input type="text" class="form-control" name="supplier_name" id="supplierName" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Contact Person</label>
                            <input type="text" class="form-control" name="contact_person" id="contactPerson">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Email</label>
                            <input type="email" class="form-control" name="email" id="email">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Phone Number</label>
                            <input type="text" class="form-control" name="phone_number" id="phoneNumber">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Mobile Number</label>
                            <input type="text" class="form-control" name="mobile_number" id="mobileNumber">
                        </div>
                    </div>

                    <div class="form-section-title mt-3">Business Information</div>
                    <div class="row g-2">
                        <div class="col-md-4">
                            <label class="form-label">Tax ID</label>
                            <input type="text" class="form-control" name="tax_id" id="taxId">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">VAT Classification</label>
                            <select class="form-select" name="vat_classification" id="vatClassification">
                                <option value="VAT Registered">VAT Registered</option>
                                <option value="Non-VAT">Non-VAT</option>
                                <option value="Zero Rated">Zero Rated</option>
                                <option value="Exempt">Exempt</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Payment Terms</label>
                            <select class="form-select" name="payment_terms" id="paymentTerms">
                                <?php echo getPaymentTermsOptions('Net 30'); ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Credit Limit</label>
                            <input type="number" step="0.01" min="0" class="form-control" name="credit_limit" id="creditLimit" value="0.00">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Website</label>
                            <input type="text" class="form-control" name="website" id="website">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Status</label>
                            <select class="form-select" name="status" id="status">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                                <option value="pending">Pending</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Notes</label>
                            <textarea class="form-control" name="notes" id="notes" rows="3"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn-main">Save Supplier</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="viewSupplierModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Supplier Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="viewSupplierContent"></div>
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
                <a class="nav-link more-btn active" href="#" onclick="toggleMobileDropdown(event, 'vendorMobileMenu')">
                    <i class="bi bi-building"></i>
                    <span>Vendor</span>
                </a>
                <div class="more-dropdown" id="vendorMobileMenu">
                    <a class="dropdown-item" href="enterbills.php"><i
                            class="bi bi-file-earmark-text"></i><span>Enter Bills</span></a>
                    <a class="dropdown-item" href="paybills.php"><i class="bi bi-currency-dollar"></i><span>Pay
                            Bills</span></a>
                    <a class="dropdown-item active" href="vendors.php"><i class="bi bi-shop"></i><span>Vendor List</span></a>
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
                <a class="nav-link more-btn" href="#" onclick="toggleMobileDropdown(event, 'accountingMobileMenu')">
                    <i class="bi bi-graph-up"></i>
                    <span>Accounting</span>
                </a>
                <div class="more-dropdown" id="accountingMobileMenu">
                    <a class="dropdown-item" href="journal_entries.php"><i class="bi bi-journal"></i><span>Journal
                            Entries</span></a>
                    <a class="dropdown-item" href="batch_transaction.php"><i class="bi bi-collection"></i><span>Batch
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

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
const supplierModal = new bootstrap.Modal(document.getElementById('supplierModal'));
const viewSupplierModal = new bootstrap.Modal(document.getElementById('viewSupplierModal'));

function openAddModal() {
    document.getElementById('supplierForm').reset();
    document.getElementById('supplierModalTitle').textContent = 'Add Supplier';
    document.getElementById('formAction').value = 'add_supplier';
    document.getElementById('supplierId').value = '';
    document.getElementById('supplierCode').value = '<?php echo h($preview_code); ?>';
    document.getElementById('creditLimit').value = '0.00';
    supplierModal.show();
}

function supplierRequest(data) {
    return fetch(window.location.href, { method: 'POST', body: data }).then(response => response.json());
}

document.getElementById('supplierForm').addEventListener('submit', function(event) {
    event.preventDefault();
    const formData = new FormData(this);
    supplierRequest(formData).then(data => {
        if (data.success) {
            Swal.fire({ icon: 'success', title: 'Saved', text: data.message, timer: 1200, showConfirmButton: false })
                .then(() => window.location.reload());
        } else {
            Swal.fire('Error', data.message || 'Unable to save supplier.', 'error');
        }
    }).catch(() => Swal.fire('Error', 'Unable to process request.', 'error'));
});

function editSupplier(id) {
    const formData = new FormData();
    formData.append('action', 'get_supplier');
    formData.append('supplier_id', id);
    supplierRequest(formData).then(data => {
        if (!data.success) {
            Swal.fire('Error', data.message || 'Vendor not found.', 'error');
            return;
        }
        const s = data.supplier;
        document.getElementById('supplierModalTitle').textContent = 'Edit Supplier';
        document.getElementById('formAction').value = 'update_supplier';
        document.getElementById('supplierId').value = s.supplier_id || '';
        document.getElementById('supplierCode').value = s.supplier_code || '';
        document.getElementById('supplierName').value = s.supplier_name || '';
        document.getElementById('contactPerson').value = s.contact_person || '';
        document.getElementById('email').value = s.email || '';
        document.getElementById('phoneNumber').value = s.phone_number || '';
        document.getElementById('mobileNumber').value = s.mobile_number || '';
        document.getElementById('taxId').value = s.tax_id || '';
        document.getElementById('vatClassification').value = s.vat_classification || 'VAT Registered';
        document.getElementById('paymentTerms').value = s.payment_terms || 'Net 30';
        document.getElementById('creditLimit').value = s.credit_limit || '0.00';
        document.getElementById('website').value = s.website || '';
        document.getElementById('status').value = s.status || 'active';
        document.getElementById('notes').value = s.notes || '';
        supplierModal.show();
    }).catch(() => Swal.fire('Error', 'Unable to process request.', 'error'));
}

function viewSupplier(id) {
    const formData = new FormData();
    formData.append('action', 'get_supplier');
    formData.append('supplier_id', id);
    supplierRequest(formData).then(data => {
        if (!data.success) {
            Swal.fire('Error', data.message || 'Vendor not found.', 'error');
            return;
        }
        const s = data.supplier;
        const rows = (data.purchase_orders || []).map(row => `
            <tr>
                <td>${escapeHtml(row.expense_no || '')}</td>
                <td>${escapeHtml(row.expense_date || '')}</td>
                <td>₱${formatMoney(row.total_amount || 0)}</td>
                <td>${escapeHtml(row.status || '')}</td>
            </tr>
        `).join('');
        document.getElementById('viewSupplierContent').innerHTML = `
            <div class="row g-3">
                <div class="col-md-6"><div class="content-card"><p>Vendor Code</p><h6>${escapeHtml(s.supplier_code || '')}</h6></div></div>
                <div class="col-md-6"><div class="content-card"><p>Supplier Name</p><h6>${escapeHtml(s.supplier_name || '')}</h6></div></div>
                <div class="col-md-6"><div class="content-card"><p>Contact Person</p><h6>${escapeHtml(s.contact_person || '')}</h6></div></div>
                <div class="col-md-6"><div class="content-card"><p>Email / Phone</p><h6>${escapeHtml(s.email || '')} ${escapeHtml(s.phone_number || '')}</h6></div></div>
                <div class="col-12">
                    <div class="content-card">
                        <p>Recent Motorpool Bills</p>
                        <div class="table-container">
                            <table class="supplier-table">
                                <thead><tr><th>No.</th><th>Date</th><th>Amount</th><th>Status</th></tr></thead>
                                <tbody>${rows || '<tr><td colspan="4">No recent bills.</td></tr>'}</tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>`;
        viewSupplierModal.show();
    }).catch(() => Swal.fire('Error', 'Unable to process request.', 'error'));
}

function deleteSupplier(id) {
    Swal.fire({
        title: 'Delete supplier?',
        text: 'This will remove the Motorpool supplier if it has no transactions.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Yes, delete',
        confirmButtonColor: '#dc2626'
    }).then(result => {
        if (!result.isConfirmed) return;
        const formData = new FormData();
        formData.append('action', 'delete_supplier');
        formData.append('supplier_id', id);
        supplierRequest(formData).then(data => {
            if (data.success) {
                Swal.fire({ icon: 'success', title: 'Done', text: data.message, timer: 1200, showConfirmButton: false })
                    .then(() => window.location.reload());
            } else {
                Swal.fire('Error', data.message || 'Unable to delete supplier.', 'error');
            }
        }).catch(() => Swal.fire('Error', 'Unable to process request.', 'error'));
    });
}

function filterSuppliers() {
    const status = document.getElementById('statusFilter').value.toLowerCase();
    const search = document.getElementById('searchInput').value.toLowerCase();
    document.querySelectorAll('#supplierTable tbody tr[data-status]').forEach(row => {
        const rowStatus = row.getAttribute('data-status') || '';
        const rowSearch = row.getAttribute('data-search') || '';
        const statusMatch = status === 'all' || rowStatus === status;
        const searchMatch = search === '' || rowSearch.includes(search);
        row.style.display = statusMatch && searchMatch ? '' : 'none';
    });
}

function exportSuppliers() {
    const rows = [['Code', 'Supplier Name', 'Contact Person', 'Phone', 'Email', 'Terms', 'Status']];
    document.querySelectorAll('#supplierTable tbody tr[data-status]').forEach(row => {
        if (row.style.display === 'none') return;
        const cells = row.querySelectorAll('td');
        rows.push(Array.from(cells).slice(0, 7).map(td => td.innerText.trim()));
    });
    const wb = XLSX.utils.book_new();
    const ws = XLSX.utils.aoa_to_sheet(rows);
    XLSX.utils.book_append_sheet(wb, ws, 'Motorpool Vendors');
    XLSX.writeFile(wb, 'motorpool_suppliers.xlsx');
}


function toggleSupplierFilter() {
    const body = document.getElementById('supplierFilterBody');
    const toggle = document.getElementById('supplierFilterToggle');
    if (!body || !toggle) return;
    const isCollapsed = body.classList.toggle('collapsed');
    toggle.setAttribute('aria-expanded', isCollapsed ? 'false' : 'true');
}

function resetSupplierFilters() {
    document.getElementById('statusFilter').value = 'all';
    document.getElementById('searchInput').value = '';
    filterSuppliers();
}

function printSuppliers() {
    const rows = Array.from(document.querySelectorAll('#supplierTable tbody tr[data-status]'))
        .filter(row => row.style.display !== 'none')
        .map(row => {
            const cells = row.querySelectorAll('td');
            return `
                <tr>
                    <td>${escapeHtml(cells[0]?.innerText.trim() || '')}</td>
                    <td>${escapeHtml((cells[1]?.innerText.trim() || '').replace(/\s+POs$/, ''))}</td>
                    <td>${escapeHtml(cells[2]?.innerText.trim() || '')}</td>
                    <td>${escapeHtml(cells[3]?.innerText.trim() || '')}</td>
                    <td>${escapeHtml(cells[4]?.innerText.trim() || '')}</td>
                    <td>${escapeHtml(cells[5]?.innerText.trim() || '')}</td>
                    <td>${escapeHtml(cells[6]?.innerText.trim() || '')}</td>
                </tr>`;
        }).join('');

    const win = window.open('', '_blank');
    win.document.write(`
        <html>
        <head>
            <title>Motorpool Suppliers</title>
            <style>
                body { font-family: Arial, sans-serif; padding: 24px; color: #111827; }
                h2 { margin: 0 0 4px; color: #0b2f4f; font-weight: 600; }
                p { margin: 0 0 18px; color: #64748b; }
                table { width: 100%; border-collapse: collapse; font-size: 12px; }
                th { background: #087f5b; color: #fff; text-align: left; padding: 9px; }
                td { border: 1px solid #d9e2e8; padding: 8px; }
            </style>
        </head>
        <body>
            <h2>Motorpool Suppliers</h2>
            <p>Printed ${new Date().toLocaleString()}</p>
            <table>
                <thead><tr><th>Code</th><th>Supplier Name</th><th>Contact Person</th><th>Phone</th><th>Email</th><th>Terms</th><th>Status</th></tr></thead>
                <tbody>${rows || '<tr><td colspan="7">No suppliers found.</td></tr>'}</tbody>
            </table>
            <script>window.onload = () => { window.print(); setTimeout(() => window.close(), 500); };<\/script>
        </body>
        </html>`);
    win.document.close();
}

function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>'"]/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[char]));
}

function formatMoney(value) {
    return Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function logout() {
    Swal.fire({
        title: 'Are you sure?',
        text: 'You will be logged out of the system',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#07d826',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Yes, logout'
    }).then(result => {
        if (result.isConfirmed) {
            localStorage.removeItem('sidebarCollapsed');
            window.location.href = '../logout.php';
        }
    });
}

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

// Toggle sidebar dropdown - same behavior as chartofaccounts(3).php
function toggleSidebarDropdown(event, targetId) {
    if (event) {
        event.preventDefault();
        event.stopPropagation();
    }

    const target = document.getElementById(targetId);
    const btn = event ? event.currentTarget : null;
    const arrow = btn ? btn.querySelector('.dropdown-arrow') : null;
    const sidebar = document.getElementById('sidebar');

    if (!target) return false;

    // If desktop sidebar is collapsed, expand it first then open selected dropdown.
    if (sidebar && window.innerWidth > 992 && sidebar.classList.contains('collapsed')) {
        sidebar.classList.remove('collapsed');
        localStorage.setItem('sidebarCollapsed', 'false');

        setTimeout(() => {
            document.querySelectorAll('.sidebar .collapse.show').forEach(collapse => {
                if (collapse.id !== targetId) {
                    collapse.classList.remove('show');
                    const otherBtn = document.querySelector(`[onclick*="${collapse.id}"]`);
                    if (otherBtn) {
                        setArrowState(otherBtn.querySelector('.dropdown-arrow'), false);
                    }
                }
            });

            target.classList.add('show');
            setArrowState(arrow, true);
        }, 50);
        return false;
    }

    // Normal expanded/mobile dropdown behavior.
    if (target.classList.contains('show')) {
        target.classList.remove('show');
        setArrowState(arrow, false);
    } else {
        document.querySelectorAll('.sidebar .collapse.show').forEach(collapse => {
            if (collapse.id !== targetId) {
                collapse.classList.remove('show');
                const otherBtn = document.querySelector(`[onclick*="${collapse.id}"]`);
                if (otherBtn) {
                    setArrowState(otherBtn.querySelector('.dropdown-arrow'), false);
                }
            }
        });

        target.classList.add('show');
        setArrowState(arrow, true);
    }

    return false;
}

// Toggle sidebar collapse/open - same behavior as chartofaccounts(3).php, adjusted for mobileToggleBtn id.
window.toggleSidebar = function() {
    const sidebar = document.getElementById('sidebar');
    if (!sidebar) return false;

    if (window.innerWidth <= 992) {
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
        const wasCollapsed = sidebar.classList.contains('collapsed');
        sidebar.classList.toggle('collapsed');
        localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed') ? 'true' : 'false');

        if (wasCollapsed && !sidebar.classList.contains('collapsed')) {
            setTimeout(function() {
                document.querySelectorAll('.dropdown-nav').forEach(dropdownNav => {
                    const activeLink = dropdownNav.querySelector('.nav-link.active');
                    if (activeLink) {
                        const collapseDiv = dropdownNav.querySelector('.collapse');
                        if (collapseDiv && !collapseDiv.classList.contains('show')) {
                            collapseDiv.classList.add('show');
                            const parentLink = dropdownNav.querySelector(':scope > .nav-link');
                            if (parentLink) {
                                setArrowState(parentLink.querySelector('.dropdown-arrow'), true);
                            }
                        }
                    }
                });
            }, 150);
        } else if (sidebar.classList.contains('collapsed')) {
            document.querySelectorAll('.sidebar .collapse.show').forEach(function(collapse) {
                collapse.classList.remove('show');
                const parentBtn = document.querySelector(`[onclick*="${collapse.id}"]`);
                if (parentBtn) {
                    setArrowState(parentBtn.querySelector('.dropdown-arrow'), false);
                }
            });
        }
    }
    return false;
};

// Set active sidebar item based on current page and auto-open its parent dropdown.
function setActiveSidebarItem() {
    const currentPage = window.location.pathname.split('/').pop();

    document.querySelectorAll('.sidebar .nav-link').forEach(link => {
        link.classList.remove('active');
    });

    document.querySelectorAll('.sidebar .nav-link').forEach(link => {
        const href = (link.getAttribute('href') || '').split('?')[0].split('/').pop();
        if (href === currentPage) {
            link.classList.add('active');

            const collapseDiv = link.closest('.collapse');
            if (collapseDiv) {
                collapseDiv.classList.add('show');
                const parentBtn = document.querySelector(`[onclick*="${collapseDiv.id}"]`);
                if (parentBtn) {
                    setArrowState(parentBtn.querySelector('.dropdown-arrow'), true);
                }
            }
        }
    });

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

function initializeSidebar() {
    const sidebar = document.getElementById('sidebar');
    const desktopToggleBtn = document.getElementById('desktopToggleBtn');
    const mobileToggleBtn = document.getElementById('mobileToggleBtn');

    if (sidebar && window.innerWidth > 992) {
        const savedCollapsed = localStorage.getItem('sidebarCollapsed');
        if (savedCollapsed === 'true') {
            sidebar.classList.add('collapsed');
        } else {
            sidebar.classList.remove('collapsed');
        }
    }

    if (desktopToggleBtn) {
        desktopToggleBtn.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            window.toggleSidebar();
        });
    }

    if (mobileToggleBtn) {
        mobileToggleBtn.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            window.toggleSidebar();
        });
    }

    setActiveSidebarItem();

    document.addEventListener('click', function(e) {
        if (window.innerWidth <= 992 && sidebar && sidebar.classList.contains('active') &&
            !sidebar.contains(e.target) && mobileToggleBtn && !mobileToggleBtn.contains(e.target)) {
            sidebar.classList.remove('active');
            const overlay = document.querySelector('.sidebar-overlay');
            if (overlay) overlay.remove();
        }
    });

    document.querySelectorAll('.sidebar .collapse').forEach(collapse => {
        collapse.addEventListener('click', function(e) {
            e.stopPropagation();
        });
    });

    window.addEventListener('resize', function() {
        const sidebar = document.getElementById('sidebar');
        const overlay = document.querySelector('.sidebar-overlay');
        if (!sidebar) return;

        if (window.innerWidth > 992) {
            if (overlay) overlay.remove();
            sidebar.classList.remove('active');
        } else {
            sidebar.classList.remove('collapsed');
        }
    });
}

document.addEventListener('DOMContentLoaded', initializeSidebar);

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
