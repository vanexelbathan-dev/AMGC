<?php
// supplier.php - Supplier Management (UPDATED: Removed Address Fields, Original UI/Color Palette Restored)

require_once '../config/database.php';
require_once '../config/session_handler.php';

// Get current user info and branch context
$user_id = $_SESSION['user_id'] ?? 0;
$user_name = isset($_SESSION['first_name']) ? $_SESSION['first_name'] . ' ' . $_SESSION['last_name'] : 'Branch Admin';
$user_role = $_SESSION['role'] ?? 'branch_admin';
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

// Check if suppliers table exists, create if not
$check_suppliers_table = $conn->query("SHOW TABLES LIKE 'suppliers'");
if ($check_suppliers_table && $check_suppliers_table->num_rows == 0) {
    // Create suppliers table without address fields? But we will keep DB schema for compatibility.
    // We just won't use them in UI.
    $create_table = "CREATE TABLE IF NOT EXISTS suppliers (
        supplier_id INT AUTO_INCREMENT PRIMARY KEY,
        supplier_code VARCHAR(50) NOT NULL UNIQUE,
        supplier_name VARCHAR(150) NOT NULL,
        contact_person VARCHAR(100),
        email VARCHAR(100),
        phone_number VARCHAR(20),
        mobile_number VARCHAR(20),
        
        -- Address fields (kept for legacy, not used in UI)
        region VARCHAR(255),
        province VARCHAR(255),
        city VARCHAR(255),
        city_code VARCHAR(50),
        barangay VARCHAR(255),
        street_address TEXT,
        full_address TEXT,
        latitude DECIMAL(10,8),
        longitude DECIMAL(11,8),
        
        -- Business information
        tax_id VARCHAR(50),
        vat_classification ENUM('VAT Registered','Non-VAT','Zero Rated','Exempt') DEFAULT 'VAT Registered',
        payment_terms VARCHAR(100) DEFAULT 'Net 30',
        credit_limit DECIMAL(12,2) DEFAULT 0.00,
        website VARCHAR(255),
        notes TEXT,
        status ENUM('active','inactive','pending') DEFAULT 'active',
        branch_id INT,
        created_by INT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        
        FOREIGN KEY (branch_id) REFERENCES branches(branch_id) ON DELETE SET NULL,
        FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE SET NULL,
        
        INDEX idx_supplier_code (supplier_code),
        INDEX idx_supplier_name (supplier_name),
        INDEX idx_status (status),
        INDEX idx_branch (branch_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    
    if (!$conn->query($create_table)) {
        error_log("Failed to create suppliers table: " . $conn->error);
    }
} else {
    // Ensure required columns exist (skip address columns to avoid errors)
    $check_payment_terms = $conn->query("SHOW COLUMNS FROM suppliers LIKE 'payment_terms'");
    if ($check_payment_terms && $check_payment_terms->num_rows == 0) {
        $conn->query("ALTER TABLE suppliers ADD COLUMN payment_terms VARCHAR(100) DEFAULT 'Net 30'");
    }
    $check_credit_limit = $conn->query("SHOW COLUMNS FROM suppliers LIKE 'credit_limit'");
    if ($check_credit_limit && $check_credit_limit->num_rows == 0) {
        $conn->query("ALTER TABLE suppliers ADD COLUMN credit_limit DECIMAL(12,2) DEFAULT 0.00");
    }
    $check_website = $conn->query("SHOW COLUMNS FROM suppliers LIKE 'website'");
    if ($check_website && $check_website->num_rows == 0) {
        $conn->query("ALTER TABLE suppliers ADD COLUMN website VARCHAR(255) NULL");
    }
    $check_vat = $conn->query("SHOW COLUMNS FROM suppliers LIKE 'vat_classification'");
    if ($check_vat && $check_vat->num_rows == 0) {
        $conn->query("ALTER TABLE suppliers ADD COLUMN vat_classification ENUM('VAT Registered','Non-VAT','Zero Rated','Exempt') DEFAULT 'VAT Registered'");
    }
    // Status enum check
    $check_status = $conn->query("SHOW COLUMNS FROM suppliers LIKE 'status'");
    if ($check_status && $check_status->num_rows > 0) {
        $status_row = $check_status->fetch_assoc();
        if (strpos($status_row['Type'], 'pending') === false) {
            $conn->query("ALTER TABLE suppliers MODIFY COLUMN status ENUM('active','inactive','pending') DEFAULT 'active'");
        }
    }
}

// Check if branch_id column exists in suppliers table
$suppliers_branch_column_exists = false;
$check_branch_column = $conn->query("SHOW COLUMNS FROM suppliers LIKE 'branch_id'");
if ($check_branch_column && $check_branch_column->num_rows > 0) {
    $suppliers_branch_column_exists = true;
}

// Function to generate unique supplier code
function generateSupplierCode($conn) {
    $prefix = 'SUP-';
    $year = date('Y');
    $month = date('m');
    
    $query = "SELECT supplier_code FROM suppliers 
              WHERE supplier_code LIKE '$prefix$year$month%' 
              ORDER BY supplier_code DESC LIMIT 1";
    $result = $conn->query($query);
    
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $last_code = $row['supplier_code'];
        $sequence = intval(substr($last_code, -4)) + 1;
    } else {
        $sequence = 1;
    }
    
    $new_code = $prefix . $year . $month . '-' . str_pad($sequence, 4, '0', STR_PAD_LEFT);
    return $new_code;
}

// Generate a preview code for the modal
$preview_code = generateSupplierCode($conn);

// Get base64 encoded logo for printing
$logo_path = '../Pictures/amgc3DLogo.png';
$logo_base64 = '';
if (file_exists($logo_path)) {
    $image_data = file_get_contents($logo_path);
    $logo_base64 = 'data:image/png;base64,' . base64_encode($image_data);
}

// Determine branch filter condition
$branch_condition = "";
if ($suppliers_branch_column_exists && !$view_all_branches && $branch_id > 0) {
    $branch_condition = "AND s.branch_id = " . intval($branch_id);
}

// ========== HANDLE AJAX REQUESTS ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    try {
        $conn->begin_transaction();
        
        // ADD SUPPLIER (address fields set to empty)
        if ($_POST['action'] === 'add_supplier') {
            $supplier_code = $_POST['supplier_code'] ?? '';
            $supplier_name = $_POST['supplier_name'] ?? '';
            $contact_person = $_POST['contact_person'] ?? '';
            $email = $_POST['email'] ?? '';
            $phone_number = $_POST['phone_number'] ?? '';
            $mobile_number = $_POST['mobile_number'] ?? '';
            $tax_id = $_POST['tax_id'] ?? '';
            $vat_classification = $_POST['vat_classification'] ?? 'VAT Registered';
            $payment_terms = $_POST['payment_terms'] ?? 'Net 30';
            $credit_limit = $_POST['credit_limit'] ?? 0;
            $website = $_POST['website'] ?? '';
            $notes = $_POST['notes'] ?? '';
            $status = $_POST['status'] ?? 'active';
            $branch_id_val = isset($_POST['branch_id']) ? intval($_POST['branch_id']) : ($view_all_branches ? null : $branch_id);
            
            // Address fields (empty)
            $region = '';
            $province = '';
            $city = '';
            $city_code = '';
            $barangay = '';
            $street_address = '';
            $full_address = '';
            
            $stmt = $conn->prepare("INSERT INTO suppliers (supplier_code, supplier_name, contact_person, email, phone_number, mobile_number, region, province, city, city_code, barangay, street_address, full_address, tax_id, vat_classification, payment_terms, credit_limit, website, notes, status, branch_id, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("ssssssssssssssssdsssii", $supplier_code, $supplier_name, $contact_person, $email, $phone_number, $mobile_number, $region, $province, $city, $city_code, $barangay, $street_address, $full_address, $tax_id, $vat_classification, $payment_terms, $credit_limit, $website, $notes, $status, $branch_id_val, $user_id);
            
            if ($stmt->execute()) {
                $conn->commit();
                echo json_encode(['success' => true, 'message' => 'Supplier added successfully']);
            } else {
                throw new Exception('Failed to add supplier: ' . $stmt->error);
            }
            $stmt->close();
        }
        
        // UPDATE SUPPLIER (address fields set to empty)
        elseif ($_POST['action'] === 'update_supplier') {
            $supplier_id = intval($_POST['supplier_id']);
            $supplier_code = $_POST['supplier_code'] ?? '';
            $supplier_name = $_POST['supplier_name'] ?? '';
            $contact_person = $_POST['contact_person'] ?? '';
            $email = $_POST['email'] ?? '';
            $phone_number = $_POST['phone_number'] ?? '';
            $mobile_number = $_POST['mobile_number'] ?? '';
            $tax_id = $_POST['tax_id'] ?? '';
            $vat_classification = $_POST['vat_classification'] ?? 'VAT Registered';
            $payment_terms = $_POST['payment_terms'] ?? 'Net 30';
            $credit_limit = $_POST['credit_limit'] ?? 0;
            $website = $_POST['website'] ?? '';
            $notes = $_POST['notes'] ?? '';
            $status = $_POST['status'] ?? 'active';
            
            $region = '';
            $province = '';
            $city = '';
            $city_code = '';
            $barangay = '';
            $street_address = '';
            $full_address = '';
            
            $stmt = $conn->prepare("UPDATE suppliers SET supplier_code=?, supplier_name=?, contact_person=?, email=?, phone_number=?, mobile_number=?, region=?, province=?, city=?, city_code=?, barangay=?, street_address=?, full_address=?, tax_id=?, vat_classification=?, payment_terms=?, credit_limit=?, website=?, notes=?, status=? WHERE supplier_id=?");
            $stmt->bind_param("ssssssssssssssssdsssi", $supplier_code, $supplier_name, $contact_person, $email, $phone_number, $mobile_number, $region, $province, $city, $city_code, $barangay, $street_address, $full_address, $tax_id, $vat_classification, $payment_terms, $credit_limit, $website, $notes, $status, $supplier_id);
            
            if ($stmt->execute()) {
                $conn->commit();
                echo json_encode(['success' => true, 'message' => 'Supplier updated successfully']);
            } else {
                throw new Exception('Failed to update supplier: ' . $stmt->error);
            }
            $stmt->close();
        }
        
        // DELETE SUPPLIER
        elseif ($_POST['action'] === 'delete_supplier') {
            $supplier_id = intval($_POST['supplier_id']);
            $check_stmt = $conn->prepare("SELECT COUNT(*) as po_count FROM purchase_orders WHERE supplier_name = (SELECT supplier_name FROM suppliers WHERE supplier_id = ?)");
            $check_stmt->bind_param("i", $supplier_id);
            $check_stmt->execute();
            $result = $check_stmt->get_result();
            $row = $result->fetch_assoc();
            $po_count = $row['po_count'];
            $check_stmt->close();
            
            if ($po_count > 0) {
                $stmt = $conn->prepare("UPDATE suppliers SET status = 'inactive' WHERE supplier_id = ?");
                $stmt->bind_param("i", $supplier_id);
                if ($stmt->execute()) {
                    $conn->commit();
                    echo json_encode(['success' => true, 'message' => 'Supplier has existing purchase orders. Status changed to inactive.']);
                } else {
                    throw new Exception('Failed to deactivate supplier');
                }
                $stmt->close();
            } else {
                $stmt = $conn->prepare("DELETE FROM suppliers WHERE supplier_id = ?");
                $stmt->bind_param("i", $supplier_id);
                if ($stmt->execute()) {
                    $conn->commit();
                    echo json_encode(['success' => true, 'message' => 'Supplier deleted successfully']);
                } else {
                    throw new Exception('Failed to delete supplier');
                }
                $stmt->close();
            }
        }
        
        // GET SUPPLIER DETAILS
        elseif ($_POST['action'] === 'get_supplier') {
            $supplier_id = intval($_POST['supplier_id']);
            $query = "SELECT s.*, u.first_name, u.last_name 
                      FROM suppliers s 
                      LEFT JOIN users u ON s.created_by = u.user_id 
                      WHERE s.supplier_id = ?";
            $stmt = $conn->prepare($query);
            $stmt->bind_param("i", $supplier_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows === 0) {
                throw new Exception('Supplier not found');
            }
            
            $supplier = $result->fetch_assoc();
            $supplier['created_by_name'] = trim(($supplier['first_name'] ?? '') . ' ' . ($supplier['last_name'] ?? ''));
            unset($supplier['first_name'], $supplier['last_name']);
            
            $po_query = "SELECT po_id, po_number, order_date, total_amount, po_status 
                         FROM purchase_orders 
                         WHERE supplier_name = ? 
                         ORDER BY order_date DESC 
                         LIMIT 10";
            $po_stmt = $conn->prepare($po_query);
            $po_stmt->bind_param("s", $supplier['supplier_name']);
            $po_stmt->execute();
            $po_result = $po_stmt->get_result();
            $purchase_orders = $po_result->fetch_all(MYSQLI_ASSOC);
            $po_stmt->close();
            
            $conn->commit();
            echo json_encode([
                'success' => true,
                'supplier' => $supplier,
                'purchase_orders' => $purchase_orders
            ]);
            $stmt->close();
        }
        
        // GET ALL SUPPLIERS (for export/print)
        elseif ($_POST['action'] === 'get_all_suppliers') {
            $branch_condition_sql = "";
            if ($suppliers_branch_column_exists && !$view_all_branches && $branch_id > 0) {
                $branch_condition_sql = "AND branch_id = " . intval($branch_id);
            }
            $query = "SELECT s.*, b.branch_name 
                      FROM suppliers s
                      LEFT JOIN branches b ON s.branch_id = b.branch_id
                      WHERE 1=1 $branch_condition_sql
                      ORDER BY s.supplier_name ASC";
            $result = $conn->query($query);
            $suppliers = $result->fetch_all(MYSQLI_ASSOC);
            echo json_encode(['success' => true, 'suppliers' => $suppliers]);
        }
        
        // PRINT SUPPLIERS REPORT
        elseif ($_POST['action'] === 'print_suppliers') {
            $filter_data = json_decode($_POST['filter_data'], true);
            $status_filter = $filter_data['status'] ?? 'all';
            $search = $filter_data['search'] ?? '';
            
            $branch_condition_sql = "";
            if ($suppliers_branch_column_exists && !$view_all_branches && $branch_id > 0) {
                $branch_condition_sql = "AND s.branch_id = " . intval($branch_id);
            }
            
            $sql = "SELECT s.*, b.branch_name 
                    FROM suppliers s 
                    LEFT JOIN branches b ON s.branch_id = b.branch_id 
                    WHERE 1=1 $branch_condition_sql";
            if ($status_filter !== 'all') {
                $sql .= " AND s.status = '" . $conn->real_escape_string($status_filter) . "'";
            }
            if (!empty($search)) {
                $search = $conn->real_escape_string($search);
                $sql .= " AND (s.supplier_code LIKE '%$search%' OR s.supplier_name LIKE '%$search%' OR s.email LIKE '%$search%')";
            }
            $sql .= " ORDER BY s.supplier_name ASC";
            
            $result = $conn->query($sql);
            $suppliers = $result->fetch_all(MYSQLI_ASSOC);
            
            echo json_encode([
                'success' => true,
                'suppliers' => $suppliers,
                'branch_name' => $branch_name
            ]);
        }
        
        // GENERATE SUPPLIER CODE
        elseif ($_POST['action'] === 'generate_code') {
            $supplier_code = generateSupplierCode($conn);
            echo json_encode([
                'success' => true,
                'supplier_code' => $supplier_code
            ]);
            exit;
        }
        
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
        exit;
    }
    exit;
}

// FETCH SUPPLIERS FOR INITIAL DISPLAY
$suppliers_query = "SELECT s.*, b.branch_name,
                    (SELECT COUNT(*) FROM purchase_orders WHERE supplier_name = s.supplier_name) as po_count,
                    (SELECT COALESCE(SUM(total_amount), 0) FROM purchase_orders WHERE supplier_name = s.supplier_name) as total_spent
                  FROM suppliers s
                  LEFT JOIN branches b ON s.branch_id = b.branch_id
                  WHERE 1=1
                  $branch_condition
                  ORDER BY s.supplier_name ASC";

$suppliers_result = $conn->query($suppliers_query);
$suppliers = $suppliers_result ? $suppliers_result->fetch_all(MYSQLI_ASSOC) : [];

// Calculate statistics
$total_suppliers = count($suppliers);
$active_suppliers = count(array_filter($suppliers, fn($s) => $s['status'] === 'active'));
$inactive_suppliers = count(array_filter($suppliers, fn($s) => $s['status'] === 'inactive'));
$pending_suppliers = count(array_filter($suppliers, fn($s) => $s['status'] === 'pending'));
$total_spent = array_sum(array_column($suppliers, 'total_spent'));

function getSupplierStatusClass($status) {
    $badges = [
        'active' => 'bg-success',
        'inactive' => 'bg-danger',
        'pending' => 'bg-warning'
    ];
    return $badges[$status] ?? 'bg-secondary';
}

function formatDate($dateStr) {
    if (!$dateStr) return '';
    $date = new DateTime($dateStr);
    return $date->format('M d, Y');
}

function formatDateTime($dateStr) {
    if (!$dateStr) return '';
    $date = new DateTime($dateStr);
    return $date->format('M d, Y H:i');
}

function getPaymentTermsOptions($selected = 'Net 30') {
    $options = ['Net 30', 'Net 15', 'Net 45', 'Net 60', 'COD', '2/10 Net 30'];
    $html = '';
    foreach ($options as $option) {
        $html .= '<option value="' . $option . '" ' . ($selected == $option ? 'selected' : '') . '>' . $option . '</option>';
    }
    return $html;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Suppliers - Branch Admin</title>
    <link rel="icon" type="image/png" href="../Pictures/favicon-96x96.png" sizes="96x96" />
    <link rel="icon" type="image/svg+xml" href="../Pictures/favicon.svg" />
    <link rel="shortcut icon" href="../Pictures/favicon.ico" />
    <link rel="apple-touch-icon" sizes="180x180" href="../Pictures/apple-touch-icon.png" />
    <link rel="stylesheet" href="../css/current_inventory.css">
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
    <!-- jQuery (required for Select2) -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <!-- Select2 JS -->
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
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
        
        /* Alert for missing table */
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
        
        /* Supplier table styling */
        .supplier-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
            background-color: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }
        
        .supplier-table thead th {
            background-color: #f8f9fa;
            font-weight: 600;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #495057;
            padding: 14px 12px;
            border-bottom: 2px solid #dee2e6;
            white-space: nowrap;
            vertical-align: middle;
            text-align: left;
        }
        
        .supplier-table tbody td {
            padding: 14px 12px;
            vertical-align: middle;
            border-bottom: 1px solid #e9ecef;
            font-size: 13px;
        }
        
        .supplier-table tbody tr:hover {
            background-color: #f8f9fa;
        }
        
        /* Column widths */
        .col-code { width: 10%; }
        .col-name { width: 18%; }
        .col-contact { width: 15%; }
        .col-phone { width: 12%; }
        .col-email { width: 15%; }
        .col-payment { width: 10%; }
        .col-status { width: 8%; }
        <?php if ($suppliers_branch_column_exists && $view_all_branches): ?>
        .col-branch { width: 8%; }
        <?php endif; ?>
        .col-actions { width: 12%; text-align: center; }
        
        /* Stats for supplier metrics */
        .supplier-stat-card {
            background-color: #f8f9fa;
            border-radius: 12px;
            padding: 20px;
            text-align: center;
            border-left: 4px solid #2E7D32;
            height: 100%;
            transition: transform 0.2s;
        }
        
        .supplier-stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        
        .supplier-stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: #212529;
            line-height: 1.2;
        }
        
        .supplier-stat-label {
            font-size: 0.85rem;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        /* Action buttons wrapper */
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
        
        /* Filter section */
        .filter-section {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
            margin-bottom: 25px;
            padding: 16px 20px;
            background-color: #f8f9fa;
            border-radius: 8px;
        }
        
        .filter-controls {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 12px;
            flex: 1;
        }
        
        .filter-actions {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .filter-dropdowns {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            align-items: center;
        }
        
        .filter-dropdown {
            min-width: 160px;
        }
        
        .filter-dropdown .form-select {
            font-size: 13px;
            padding: 8px 12px;
            border-radius: 6px;
            border: 1px solid #ced4da;
            background-color: white;
            cursor: pointer;
        }
        
        .search-box {
            position: relative;
            min-width: 250px;
        }
        
        .search-box i {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #6c757d;
            font-size: 14px;
            z-index: 10;
            pointer-events: none;
        }
        
        .search-box input {
            width: 100%;
            padding: 8px 12px 8px 38px;
            border: 1px solid #ced4da;
            border-radius: 6px;
            height: 40px;
            font-size: 14px;
        }
        
        /* Status badge */
        .status-badge {
            display: inline-block;
            padding: 5px 12px;
            font-size: 12px;
            font-weight: 500;
            border-radius: 20px;
            text-align: center;
            min-width: 85px;
        }
        
        .status-active { background-color: #d4edda; color: #155724; }
        .status-inactive { background-color: #f8d7da; color: #721c24; }
        .status-pending { background-color: #fff3cd; color: #856404; }
        
        /* Action buttons */
        .action-buttons {
            display: flex;
            gap: 3px;
            justify-content: center;
            align-items: center;
        }
        
        .btn-action {
            width: 32px;
            height: 32px;
            border-radius: 6px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: none;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        
        .btn-action i {
            font-size: 14px;
        }
        
        .btn-action.btn-edit {
            background: #f3e5f5;
            color: #7b1fa2;
        }
        
        .btn-action.btn-edit:hover {
            background: #e1bee7;
            transform: translateY(-2px);
        }
        
        .btn-action.btn-delete {
            background: #fee2e2;
            color: #dc2626;
        }
        
        .btn-action.btn-delete:hover {
            background: #fecaca;
            transform: translateY(-2px);
        }
        
        .btn-action.btn-call {
            background: #f6e69e;
            color: #d6af00;
        }
        
        .btn-action.btn-call:hover {
            background: #f5df7d;
            transform: translateY(-2px);
        }
        
        .btn-action.btn-message {
            background: #cbffc0;
            color: #1da000;
        }
        
        .btn-action.btn-message:hover {
            background: #b8f0a8;
            transform: translateY(-2px);
        }
        
        /* Empty state */
        .empty-state-table {
            text-align: center;
            padding: 40px 20px;
            background-color: white;
            border-radius: 8px;
        }
        
        .empty-state-table i {
            font-size: 48px;
            color: #adb5bd;
            margin-bottom: 16px;
        }
        
        .empty-state-table h5 {
            color: #495057;
            margin-bottom: 8px;
        }
        
        .empty-state-table p {
            color: #6c757d;
            margin-bottom: 8px;
        }
        
        /* Supplier details styling */
        .supplier-details-card {
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
        
        .contact-info {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 8px;
        }
        
        .contact-info i {
            font-size: 16px;
            color: #2E7D32;
        }
        
        /* Form section title */
        .form-section-title {
            font-size: 1rem;
            font-weight: 600;
            color: #495057;
            margin-bottom: 15px;
            padding-bottom: 8px;
            border-bottom: 2px solid #e9ecef;
        }
        
        /* Loading indicator */
        .loading-spinner {
            display: inline-block;
            width: 1rem;
            height: 1rem;
            border: 2px solid #f3f3f3;
            border-top: 2px solid #3498db;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        /* Auto-generated code styling */
        .code-preview {
            background-color: #e9ecef;
            border: 1px solid #ced4da;
            border-radius: 5px;
            padding: 10px 15px;
            font-family: monospace;
            font-size: 1.1em;
            color: #0d6efd;
            font-weight: bold;
            margin-bottom: 15px;
        }
        
        .code-label {
            font-size: 0.85em;
            color: #6c757d;
            margin-bottom: 5px;
        }
        
        .refresh-code {
            cursor: pointer;
            color: #0d6efd;
            margin-left: 10px;
        }
        
        .refresh-code:hover {
            color: #0a58ca;
        }
        
        /* Print Frame */
        #printFrame {
            position: absolute;
            left: -9999px;
            top: -9999px;
            width: 1px;
            height: 1px;
            opacity: 0;
            pointer-events: none;
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
        }
        
        /* Modal styling */
        .modal-body {
            padding: 1rem 1rem 0.5rem 1rem;
        }
        
        .modal-footer {
            padding: 0.75rem 1rem;
        }
        
        .modal-xl {
            max-width: 1200px;
        }
        
        .modal .row.g-2 {
            margin-bottom: 0.25rem !important;
        }
        
        .modal .row.g-2 > [class*="col-"] {
            padding-bottom: 0.25rem;
        }
        
        .modal .fw-bold.border-bottom {
            margin-top: 0.25rem !important;
            margin-bottom: 0.5rem !important;
            padding-bottom: 0.25rem !important;
        }
        
        .modal .alert {
            margin-bottom: 0.5rem;
            padding: 0.5rem 1rem;
        }
        
        .modal label.form-label {
            margin-bottom: 0.15rem;
            font-size: 0.85rem;
        }
        
        .modal .form-control, .modal .form-select, .modal .input-group {
            min-height: 32px;
            padding: 0.25rem 0.5rem;
            font-size: 0.9rem;
        }
        
        .modal .input-group .btn {
            padding: 0.25rem 0.75rem;
            font-size: 0.9rem;
        }
        
        .modal textarea.form-control {
            min-height: 60px;
        }
        
        /* Supplier stat cards - modern style */
        .supplier-stat-card {
            background: transparent !important;
            border: none !important;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.08) !important;
            min-height: auto !important;
            height: auto !important;
            padding: 0.8rem !important;
            transition: transform 0.2s ease, box-shadow 0.2s ease !important;
            cursor: default !important;
        }

        .supplier-stat-card.total {
            background: linear-gradient(135deg, #047857, #059669) !important;
            border: none !important;
        }

        .supplier-stat-card.pending {
            background: linear-gradient(135deg, #047857, #059669) !important;
            border: none !important;
        }

        .supplier-stat-card.complete {
            background: linear-gradient(135deg, #047857, #059669) !important;
            border: none !important;
        }

        .supplier-stat-card.spent {
            background: linear-gradient(135deg, #047857, #059669) !important;
            border: none !important;
        }

        .supplier-stat-card .stat-value,
        .supplier-stat-card .stat-label,
        .supplier-stat-card .stat-content,
        .supplier-stat-card small,
        .supplier-stat-card small i,
        .supplier-stat-card .badge {
            color: white !important;
        }

        .supplier-stat-card .stat-content,
        .supplier-stat-card .stat-icon {
            background: transparent !important;
        }

        @media (max-width: 991px) {
            .supplier-stat-card {
                aspect-ratio: 1 / 1 !important;
                display: flex !important;
                flex-direction: column !important;
                justify-content: center !important;
                align-items: center !important;
                text-align: center !important;
                padding: 0.5rem !important;
            }
            
            .supplier-stat-card i,
            .supplier-stat-card .stat-icon {
                display: block !important;
                text-align: center !important;
                margin: 0 auto 0.3rem auto !important;
                font-size: 1.6rem !important;
                width: auto !important;
                float: none !important;
                position: static !important;
            }
            
            .supplier-stat-card .stat-value {
                display: block !important;
                text-align: center !important;
                font-size: 1.2rem !important;
                font-weight: bold !important;
                line-height: 1.2 !important;
                margin: 0.2rem 0 !important;
                width: 100% !important;
            }
            
            .supplier-stat-card .stat-label {
                display: block !important;
                text-align: center !important;
                font-size: 0.7rem !important;
                font-weight: 500 !important;
                width: 100% !important;
            }
            
            .supplier-stat-card small {
                display: none !important;
            }
        }

        @media (min-width: 992px) {
            .supplier-stat-card {
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
            
            .supplier-stat-card i,
            .supplier-stat-card .stat-icon {
                align-self: flex-start !important;
                margin: 0 0.75rem 0 0 !important;
                font-size: 1.6rem !important;
                display: inline-block !important;
                text-align: left !important;
            }
            
            .supplier-stat-card .stat-content {
                display: flex !important;
                flex-direction: column !important;
                align-items: flex-start !important;
                text-align: left !important;
                flex: 1 !important;
            }
            
            .supplier-stat-card .stat-value {
                align-self: flex-start !important;
                margin: 0 0 0.05rem 0 !important;
                font-size: 1.4rem !important;
                line-height: 1.2 !important;
                text-align: left !important;
            }
            
            .supplier-stat-card .stat-label {
                align-self: flex-start !important;
                margin-top: 0.1rem !important;
                font-size: 0.75rem !important;
                font-weight: 500 !important;
                text-align: left !important;
            }
            
            .supplier-stat-card small {
                align-self: flex-start !important;
                margin-top: 0.2rem !important;
                display: block !important;
                font-size: 0.65rem !important;
                opacity: 0.9 !important;
                text-align: left !important;
            }
        }

        /* Supplier filter card */
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

        .supplier-filter-toggle-btn[aria-expanded="true"] i {
            transform: rotate(180deg);
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

        .filter-item.search-item {
            flex: 1.5;
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
            font-size: 0.85rem;
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

        .supplier-search-wrapper {
            position: relative;
            width: 100%;
        }

        .supplier-search-wrapper .search-icon {
            position: absolute;
            left: 0.75rem;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            font-size: 0.9rem;
            pointer-events: none;
        }

        .supplier-search-wrapper .supplier-filter-input {
            padding-left: 2.25rem;
        }

        @media (max-width: 992px) {
            .supplier-filter-one-line {
                flex-direction: column;
                align-items: stretch;
                gap: 0.75rem;
            }
            
            .filter-item,
            .filter-item.search-item,
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
                font-size: 0.8rem;
                padding: 0.4rem 0.6rem;
            }
            
            .supplier-filter-label {
                font-size: 0.65rem;
                margin-bottom: 0.25rem;
            }
        }

        /* Mobile card view for table */
        @media (max-width: 768px) {
            .table-container:has(#supplierTable) {
                overflow-x: visible !important;
            }
            
            #supplierTable,
            #supplierTable tbody,
            #supplierTable tbody tr,
            #supplierTable tbody tr td {
                all: revert !important;
            }
            
            #supplierTable {
                display: block !important;
                width: 100% !important;
            }
            
            #supplierTable thead {
                display: none !important;
            }
            
            #supplierTable tbody {
                display: block !important;
                width: 100% !important;
            }
            
            #supplierTable tbody tr {
                display: block !important;
                background: white !important;
                border-radius: 16px !important;
                margin-bottom: 16px !important;
                padding: 16px !important;
                padding-top: 12px !important;
                padding-bottom: 12px !important;
                box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08) !important;
                border: 1px solid #e9ecef !important;
                position: relative !important;
                width: 100% !important;
                box-sizing: border-box !important;
                cursor: pointer !important;
            }
            
            #supplierTable tbody tr td {
                display: none !important;
            }
            
            #supplierTable tbody tr td.col-code {
                display: block !important;
                margin-bottom: 8px !important;
                padding: 0 !important;
            }
            
            #supplierTable tbody tr td.col-code strong {
                font-size: 12px !important;
                font-weight: 600 !important;
                color: #047857 !important;
                background: transparent !important;
                padding: 0 !important;
                display: inline-block !important;
            }
            
            #supplierTable tbody tr td.col-name {
                display: block !important;
                margin-bottom: 12px !important;
                padding: 0 !important;
                padding-right: 80px !important;
            }
            
            #supplierTable tbody tr td.col-name .value-content {
                font-size: 16px !important;
                font-weight: 600 !important;
                color: #1f2937 !important;
                display: block !important;
                word-break: break-word !important;
                line-height: 1.3 !important;
            }
            
            #supplierTable tbody tr td.col-contact {
                display: flex !important;
                align-items: flex-start !important;
                margin-bottom: 8px !important;
                padding: 0 !important;
                gap: 8px !important;
                flex-wrap: wrap !important;
            }
            
            #supplierTable tbody tr td.col-contact::before {
                content: "Contact:" !important;
                font-size: 12px !important;
                font-weight: 600 !important;
                color: #6c757d !important;
                min-width: 65px !important;
                flex-shrink: 0 !important;
            }
            
            #supplierTable tbody tr td.col-phone {
                display: flex !important;
                align-items: flex-start !important;
                margin-bottom: 8px !important;
                padding: 0 !important;
                gap: 8px !important;
                flex-wrap: wrap !important;
            }
            
            #supplierTable tbody tr td.col-phone::before {
                content: "Phone:" !important;
                font-size: 12px !important;
                font-weight: 600 !important;
                color: #6c757d !important;
                min-width: 65px !important;
                flex-shrink: 0 !important;
            }
            
            #supplierTable tbody tr td.col-email {
                display: flex !important;
                align-items: flex-start !important;
                margin-bottom: 8px !important;
                padding: 0 !important;
                gap: 8px !important;
                flex-wrap: wrap !important;
            }
            
            #supplierTable tbody tr td.col-email::before {
                content: "Email:" !important;
                font-size: 12px !important;
                font-weight: 600 !important;
                color: #6c757d !important;
                min-width: 65px !important;
                flex-shrink: 0 !important;
            }
            
            #supplierTable tbody tr td.col-payment {
                display: inline-block !important;
                margin-right: 16px !important;
                padding: 0 !important;
            }
            
            #supplierTable tbody tr td.col-payment::before {
                content: "Terms:" !important;
                font-size: 12px !important;
                font-weight: 600 !important;
                color: #6c757d !important;
                margin-right: 4px !important;
            }
            
            #supplierTable tbody tr td.col-branch {
                display: inline-block !important;
                margin-right: 16px !important;
                padding: 0 !important;
            }
            
            #supplierTable tbody tr td.col-branch .badge {
                font-size: 10px !important;
                padding: 3px 8px !important;
                display: inline-block !important;
            }
            
            #supplierTable tbody tr td.col-branch::before {
                content: "Branch:" !important;
                font-size: 12px !important;
                font-weight: 600 !important;
                color: #6c757d !important;
                margin-right: 4px !important;
            }
            
            #supplierTable tbody tr td.col-status {
                display: inline-block !important;
                padding: 0 !important;
            }
            
            #supplierTable tbody tr td.col-status .status-badge {
                font-size: 10px !important;
                padding: 3px 10px !important;
                min-width: auto !important;
                display: inline-block !important;
            }
            
            #supplierTable tbody tr td.col-actions {
                display: block !important;
                position: absolute !important;
                top: 12px !important;
                right: 12px !important;
                padding: 0 !important;
                width: auto !important;
            }
            
            #supplierTable tbody tr td.col-actions .action-buttons {
                display: flex !important;
                gap: 8px !important;
                justify-content: flex-end !important;
            }
            
            #supplierTable tbody tr td.col-actions .btn-action {
                width: 32px !important;
                height: 32px !important;
                border-radius: 8px !important;
                display: inline-flex !important;
                align-items: center !important;
                justify-content: center !important;
                background: #f8f9fa !important;
                border: 1px solid #e9ecef !important;
                cursor: pointer !important;
            }
            
            #supplierTable tbody tr td.col-actions .btn-action.btn-view,
            #supplierTable tbody tr td.col-actions .btn-action.btn-edit,
            #supplierTable tbody tr td.col-actions .btn-action.btn-delete {
                display: none !important;
            }
            
            #supplierTable tbody tr td.col-actions .btn-action.btn-call,
            #supplierTable tbody tr td.col-actions .btn-action.btn-message {
                display: inline-flex !important;
            }
            
            #supplierTable tbody tr td.col-actions .btn-action i {
                font-size: 14px !important;
            }
            
            #supplierTable tbody tr::after {
                content: "tap to view" !important;
                position: absolute !important;
                bottom: 8px !important;
                right: 12px !important;
                font-size: 9px !important;
                color: #9ca3af !important;
                background: white !important;
                padding: 2px 8px !important;
                border-radius: 20px !important;
                pointer-events: none !important;
                z-index: 5 !important;
            }
        }

        /* Modern Modal Styling */
        #supplierModal .modal-content {
            border: none !important;
            border-radius: 20px !important;
            overflow: hidden !important;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.15) !important;
            max-height: 90vh !important;
            display: flex !important;
            flex-direction: column !important;
        }

        #supplierModal .modal-header {
            background: linear-gradient(135deg, #047857, #44D34E) !important;
            color: white !important;
            border-bottom: none !important;
            padding: 1rem 1.5rem !important;
            flex-shrink: 0 !important;
        }

        #supplierModal .modal-header .modal-title {
            font-weight: 600 !important;
            font-size: 1.2rem !important;
            display: flex !important;
            align-items: center !important;
            gap: 8px !important;
            color: white !important;
        }

        #supplierModal .modal-header .btn-close {
            filter: brightness(0) invert(1) !important;
            opacity: 0.8 !important;
            background: transparent !important;
        }

        #supplierModal .modal-body {
            padding: 1.5rem !important;
            overflow-y: auto !important;
            flex: 1 !important;
            background: #f8fafc !important;
        }

        #supplierModal .modal-footer {
            border-top: 1px solid #e9ecef !important;
            padding: 1rem 1.5rem !important;
            background: white !important;
            flex-shrink: 0 !important;
            gap: 0.75rem !important;
        }

        #viewSupplierModal .modal-content {
            border: none !important;
            border-radius: 20px !important;
            overflow: hidden !important;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.15) !important;
            max-height: 90vh !important;
            display: flex !important;
            flex-direction: column !important;
        }

        #viewSupplierModal .modal-header {
            background: linear-gradient(135deg, #047857, #44D34E) !important;
            color: white !important;
            border-bottom: none !important;
            padding: 1rem 1.5rem !important;
            flex-shrink: 0 !important;
        }

        #viewSupplierModal .modal-body {
            padding: 1.5rem !important;
            overflow-y: auto !important;
            flex: 1 !important;
            background: #f8fafc !important;
        }

        #viewSupplierContent .view-supplier-container {
            background: white !important;
            border-radius: 16px !important;
            padding: 1.25rem !important;
            border: 1px solid #e9ecef !important;
        }
        
        #viewSupplierContent .info-card {
            background: white !important;
            border-radius: 12px !important;
            padding: 0.875rem 1rem !important;
            margin-bottom: 1rem !important;
            border: 1px solid #e9ecef !important;
        }
        
        #viewSupplierContent .card-title {
            font-size: 0.85rem !important;
            font-weight: 700 !important;
            color: #047857 !important;
            margin-bottom: 0.75rem !important;
            padding-bottom: 0.5rem !important;
            border-bottom: 1px solid #e9ecef !important;
            display: flex !important;
            align-items: center !important;
            gap: 0.5rem !important;
        }
        
        #viewSupplierContent .info-row {
            display: flex !important;
            margin-bottom: 0.6rem !important;
            font-size: 0.85rem !important;
            line-height: 1.4 !important;
        }
        
        #viewSupplierContent .info-label {
            width: 110px !important;
            flex-shrink: 0 !important;
            font-weight: 600 !important;
            color: #6c757d !important;
        }
        
        #viewSupplierContent .info-value {
            flex: 1 !important;
            color: #1f2937 !important;
            word-break: break-word !important;
        }
        
        .po-table-container {
            background: white;
            border-radius: 16px;
            overflow: hidden;
            border: 1px solid #e9ecef;
            width: 100%;
        }
        
        .po-table-header {
            display: flex;
            background: #f8fafc !important;
            border-bottom: 2px solid #e2e8f0;
            padding: 0.75rem 1rem;
            font-weight: 700;
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #1e293b !important;
        }
        
        .po-table-body {
            max-height: 350px;
            overflow-y: auto;
        }
        
        .po-table-row {
            display: flex;
            padding: 0.75rem 1rem;
            border-bottom: 1px solid #f0f0f0;
            transition: all 0.2s ease;
            background: white;
        }
        
        .po-table-row:nth-child(even) {
            background: #fafaf5;
        }
        
        .po-col {
            flex: 1;
            font-size: 0.8rem;
            display: flex;
            align-items: center;
        }
        
        .po-col-number {
            flex: 1.5;
            font-family: monospace;
            font-weight: 600;
            color: #047857;
        }
        
        .po-col-date {
            flex: 1;
            color: #6c757d;
            font-size: 0.75rem;
        }
        
        .po-col-amount {
            flex: 0.8;
            font-weight: 600;
            color: #1f2937;
        }
        
        .po-col-status {
            flex: 0.8;
        }
        
        .po-status-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            padding: 0.25rem 0.75rem;
            font-size: 0.65rem;
            font-weight: 600;
            border-radius: 30px;
            min-width: 85px;
            justify-content: center;
        }
        
        .status-draft {
            background: #f1f5f9;
            color: #64748b;
        }
        
        .status-processing {
            background: #fef3c7;
            color: #d97706;
        }
        
        .status-approved {
            background: #d1fae5;
            color: #059669;
        }
        
        .status-delivered {
            background: #dcfce7;
            color: #16a34a;
        }
        
        .status-cancelled {
            background: #fee2e2;
            color: #dc2626;
        }
        
        .po-empty-state {
            text-align: center;
            padding: 2rem;
            color: #9ca3af;
        }
        
        @media (max-width: 576px) {
            #viewSupplierContent .info-row {
                flex-direction: column !important;
                margin-bottom: 0.75rem !important;
            }
            
            #viewSupplierContent .info-label {
                width: 100% !important;
                margin-bottom: 0.2rem !important;
                font-size: 0.7rem !important;
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
    <!-- Print Frame (hidden) -->
    <iframe id="printFrame" name="printFrame"></iframe>

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
                                    <li class="nav-item"><a class="nav-link" href="current_inventory.php"><i class="bi bi-bar-chart-line"></i><span class="nav-text">Current Inventory</span></a></li>
                                    <li class="nav-item"><a class="nav-link" href="bad_orders.php"><i class="bi bi-recycle"></i><span class="nav-text">Bad Orders</span></a></li>
                                    <li class="nav-item"><a class="nav-link" href="pick_list_items.php"><i class="bi bi-list-check"></i><span class="nav-text">Pick List Items</span></a></li>
                                    <li class="nav-item"><a class="nav-link" href="warehouses.php"><i class="bi bi-shop"></i><span class="nav-text">Warehouses</span></a></li>
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
                                    <li class="nav-item"><a class="nav-link" href="purchase_order.php"><i class="bi bi-box"></i><span class="nav-text">Receive Inventory</span></a></li>
                                    <li class="nav-item"><a class="nav-link" href="supplier.php"><i class="bi bi-people"></i><span class="nav-text">Supplier List</span></a></li>
                                </ul>
                            </div>
                        </li>
                        <!-- Customer Dropdown -->
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
                                    <li class="nav-item"><a class="nav-link" href="trip_tickets.php"><i class="bi bi-ticket-perforated"></i><span class="nav-text">Trip Tickets</span></a></li>
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
                                    <li class="nav-item"><a class="nav-link" href="deposit.php"><i class="bi bi-arrow-down-circle"></i><span class="nav-text">Deposit</span></a></li>
                                    <li class="nav-item"><a class="nav-link" href="Withdrawal.php"><i class="bi bi-arrow-up-circle"></i><span class="nav-text">Withdrawal</span></a></li>
                                    <li class="nav-item"><a class="nav-link" href="bank_statement.php"><i class="bi bi-receipt"></i><span class="nav-text">Bank Statement</span></a></li>
                                    <li class="nav-item"><a class="nav-link" href="expenses.php"><i class="bi bi-cash-stack"></i><span class="nav-text">Expenses</span></a></li>
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
            <div class="page-content active">
                <!-- Navbar Top -->
                <div class="navbar-top">
                    <button class="mobile-menu-btn" id="mobileMenuBtn">
                        <i class="bi bi-list"></i>
                    </button>
                    <div class="page-title">
                        <h2>Suppliers</h2>
                        <p id="dashboardSubtitle">
                            Manage suppliers for purchase orders
                            <?php if (!$view_all_branches && $branch_id > 0): ?>
                                - <?php 
                                    $branch_name_query = "SELECT branch_name FROM branches WHERE branch_id = $branch_id";
                                    $branch_name_result = $conn->query($branch_name_query);
                                    $branch_name_row = $branch_name_result ? $branch_name_result->fetch_assoc() : null;
                                    echo $branch_name_row ? htmlspecialchars($branch_name_row['branch_name']) : 'Branch ' . $branch_id;
                                ?>
                            <?php endif; ?>
                        </p>
                    </div>
                </div>

                <!-- Branch Info Alerts -->
                <?php if (!$suppliers_branch_column_exists): ?>
                    <div class="alert alert-info alert-dismissible fade show" role="alert">
                        <i class="bi bi-info-circle"></i> 
                        <strong>Branch filtering for suppliers not yet set up.</strong> Please run this SQL in phpMyAdmin to enable branch-specific supplier data:
                        <br><br>
                        <code>ALTER TABLE suppliers ADD COLUMN branch_id INT NULL;</code>
                        <br>
                        <code>ALTER TABLE suppliers ADD FOREIGN KEY (branch_id) REFERENCES branches(branch_id);</code>
                        <br><br>
                        <button type="button" class="btn btn-sm btn-primary" onclick="copySQL('suppliers')">
                            <i class="bi bi-files"></i> Copy SQL
                        </button>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Stats Section -->
                <div class="row supplier-stat-card-row g-1 g-sm-2 mb-4">
                    <div class="col">
                        <div class="supplier-stat-card total">
                            <i class="bi bi-building stat-icon" style="color:white;"></i>
                            <div class="stat-content">
                                <div class="stat-value"><?= $total_suppliers ?></div>
                                <div class="stat-label">Total Suppliers</div>
                                <?php if (!$view_all_branches && $branch_id > 0): ?>
                                    <small class="d-block">Your Branch</small>
                                <?php else: ?>
                                    <small class="d-block">All branches</small>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="col">
                        <div class="supplier-stat-card complete">
                            <i class="bi bi-check-circle stat-icon" style="color:white;"></i>
                            <div class="stat-content">
                                <div class="stat-value"><?= $active_suppliers ?></div>
                                <div class="stat-label">Active</div>
                            </div>
                        </div>
                    </div>
                    <div class="col">
                        <div class="supplier-stat-card pending">
                            <i class="bi bi-clock stat-icon" style="color:white;"></i>
                            <div class="stat-content">
                                <div class="stat-value"><?= $pending_suppliers ?></div>
                                <div class="stat-label">Pending</div>
                            </div>
                        </div>
                    </div>
                    <div class="col">
                        <div class="supplier-stat-card spent">
                            <i class="bi bi-cash-stack stat-icon" style="color:white;"></i>
                            <div class="stat-content">
                                <div class="stat-value">₱<?= number_format($total_spent / 1000, 1) ?>K</div>
                                <div class="stat-label">Total Spent</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filter Section -->
                <div class="supplier-filter-card mb-4">
                    <div class="supplier-filter-header">
                        <h5><i class="bi bi-funnel"></i> Filter Suppliers</h5>
                        <button class="supplier-filter-toggle-btn" type="button" id="supplierFilterToggleBtn" aria-expanded="false">
                            <i class="bi bi-chevron-down" id="supplierFilterIcon"></i>
                        </button>
                    </div>
                    
                    <div class="supplier-filter-content collapsed" id="supplierFilterContent">
                        <div class="supplier-filter-one-line">
                            <div class="filter-item">
                                <label class="supplier-filter-label">STATUS</label>
                                <select class="supplier-filter-select" id="statusFilter" onchange="filterSuppliers()">
                                    <option value="all">All Status</option>
                                    <option value="active">Active Only</option>
                                    <option value="inactive">Inactive Only</option>
                                    <option value="pending">Pending Only</option>
                                </select>
                            </div>
                            
                            <?php if ($suppliers_branch_column_exists && $view_all_branches): ?>
                            <div class="filter-item">
                                <label class="supplier-filter-label">BRANCH</label>
                                <select class="supplier-filter-select" id="branchFilter" onchange="filterSuppliers()">
                                    <option value="all">All Branches</option>
                                    <?php
                                    $branches_query = "SELECT branch_id, branch_name FROM branches ORDER BY branch_name";
                                    $branches_result = $conn->query($branches_query);
                                    while ($branch = $branches_result->fetch_assoc()):
                                    ?>
                                    <option value="<?= $branch['branch_id'] ?>"><?= htmlspecialchars($branch['branch_name']) ?></option>
                                    <?php endwhile; ?>
                                </select>
                            </div>
                            <?php endif; ?>
                            
                            <div class="filter-item search-item">
                                <label class="supplier-filter-label">GLOBAL SEARCH</label>
                                <div class="supplier-search-wrapper">
                                    <i class="bi bi-search search-icon"></i>
                                    <input type="text" class="supplier-filter-input" id="searchInput" placeholder="Search by name, code, email..." onkeyup="filterSuppliers()">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Action Buttons -->
                <div class="action-button-wrapper">
                    <button class="btn-outline-success" onclick="printSuppliers()">
                        <i class="bi bi-printer me-1"></i> Print
                    </button>
                    <button class="btn-outline-success" onclick="exportToExcel()">
                        <i class="bi bi-file-earmark-excel me-1"></i> Export
                    </button>
                    <button class="btn-primary" onclick="showAddSupplierModal()">
                        <i class="bi bi-plus-circle me-1"></i> Add Supplier
                    </button>
                </div>

                <!-- Suppliers Table -->
                <div class="table-container">
                    <table class="table custom-table compact-table" id="supplierTable">
                        <thead>
                            <tr>
                                <th class="col-code">CODE</th>
                                <th class="col-name">SUPPLIER NAME</th>
                                <th class="col-contact">CONTACT PERSON</th>
                                <th class="col-phone">PHONE</th>
                                <th class="col-email">EMAIL</th>
                                <th class="col-payment">PAYMENT TERMS</th>
                                <?php if ($suppliers_branch_column_exists && $view_all_branches): ?>
                                    <th class="col-branch">BRANCH</th>
                                <?php endif; ?>
                                <th class="col-status">STATUS</th>
                                <th class="col-actions">ACTIONS</th>
                            </tr>
                        </thead>
                        <tbody id="supplierTableBody">
                            <?php if (empty($suppliers)): ?>
                            <tr>
                                <td colspan="<?= ($suppliers_branch_column_exists && $view_all_branches) ? '9' : '8' ?>" class="empty-state-table">
                                    <i class="bi bi-building"></i>
                                    <h5>No Suppliers Found</h5>
                                    <p class="text-muted">Click the "Add Supplier" button to add your first supplier.</p>
                                </td>
                            </tr>
                            <?php else: ?>
                                <?php foreach ($suppliers as $supplier): 
                                    $phone = !empty($supplier['mobile_number']) ? $supplier['mobile_number'] : $supplier['phone_number'];
                                ?>
                                <tr class="supplier-row" 
                                    data-id="<?= $supplier['supplier_id'] ?>"
                                    data-code="<?= htmlspecialchars($supplier['supplier_code']) ?>"
                                    data-name="<?= htmlspecialchars($supplier['supplier_name']) ?>"
                                    data-status="<?= $supplier['status'] ?>"
                                    data-branch="<?= $supplier['branch_id'] ?? '' ?>"
                                    data-po-count="<?= $supplier['po_count'] ?? 0 ?>"
                                    data-total-spent="<?= $supplier['total_spent'] ?? 0 ?>"
                                    data-phone="<?= htmlspecialchars($phone) ?>">
                                    
                                    <td class="col-code" data-label="CODE">
                                        <strong><?= htmlspecialchars($supplier['supplier_code']) ?></strong>
                                    </td>
                                    <td class="col-name" data-label="SUPPLIER">
                                        <span class="value-content">
                                            <?= htmlspecialchars($supplier['supplier_name']) ?>
                                            <?php if ($supplier['po_count'] > 0): ?>
                                                <span class="badge bg-info ms-1" title="Purchase Orders: <?= $supplier['po_count'] ?>">
                                                    <?= $supplier['po_count'] ?> POs
                                                </span>
                                            <?php endif; ?>
                                        </span>
                                    </td>
                                    <td class="col-contact" data-label="CONTACT PERSON">
                                        <span class="value-content"><?= htmlspecialchars($supplier['contact_person'] ?? '—') ?></span>
                                    </td>
                                    <td class="col-phone" data-label="PHONE">
                                        <span class="value-content">
                                            <?php if (!empty($supplier['phone_number'])): ?>
                                                <?= htmlspecialchars($supplier['phone_number']) ?>
                                            <?php elseif (!empty($supplier['mobile_number'])): ?>
                                                <?= htmlspecialchars($supplier['mobile_number']) ?>
                                            <?php else: ?>
                                                —
                                            <?php endif; ?>
                                        </span>
                                    </td>
                                    <td class="col-email" data-label="EMAIL">
                                        <span class="value-content"><?= htmlspecialchars($supplier['email'] ?? '—') ?></span>
                                    </td>
                                    <td class="col-payment" data-label="PAYMENT TERMS">
                                        <span class="value-content"><?= htmlspecialchars($supplier['payment_terms'] ?? 'Net 30') ?></span>
                                    </td>
                                    <?php if ($suppliers_branch_column_exists && $view_all_branches): ?>
                                        <td class="col-branch" data-label="BRANCH">
                                            <span class="badge bg-info"><?= htmlspecialchars($supplier['branch_name'] ?? 'Branch ' . $supplier['branch_id']) ?></span>
                                        </td>
                                    <?php endif; ?>
                                    <td class="col-status" data-label="STATUS">
                                        <span class="status-badge <?= $supplier['status'] === 'active' ? 'status-active' : ($supplier['status'] === 'pending' ? 'status-pending' : 'status-inactive') ?>">
                                            <?= ucfirst($supplier['status']) ?>
                                        </span>
                                    </td>
                                    <td class="col-actions" data-label="ACTIONS">
                                        <div class="action-buttons">
                                            <?php if (!empty($phone)): ?>
                                                <a href="tel:<?= htmlspecialchars($phone) ?>" class="btn-action btn-call" title="Call">
                                                    <i class="bi bi-telephone"></i>
                                                </a>
                                                <a href="sms:<?= htmlspecialchars($phone) ?>" class="btn-action btn-message" title="Message">
                                                    <i class="bi bi-chat"></i>
                                                </a>
                                            <?php endif; ?>
                                            <button class="btn-action btn-edit" onclick="editSupplier(<?= $supplier['supplier_id'] ?>)" title="Edit">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                            <button class="btn-action btn-delete" onclick="deleteSupplier(<?= $supplier['supplier_id'] ?>)" title="Delete">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- ADD/EDIT SUPPLIER MODAL (no address fields) -->
    <div class="modal fade" id="supplierModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="true" data-bs-keyboard="true">
        <div class="modal-dialog modal-xl modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="supplierModalTitle">
                        <i class="bi bi-plus-circle me-2"></i>Add New Supplier
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="supplierForm" onsubmit="return false;">
                        <input type="hidden" id="supplierId">
                        <?php if ($suppliers_branch_column_exists && !$view_all_branches && $branch_id > 0): ?>
                            <input type="hidden" name="branch_id" value="<?= $branch_id ?>">
                        <?php endif; ?>
                        
                        <?php if ($suppliers_branch_column_exists && !$view_all_branches): ?>
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle me-2"></i>
                            Adding supplier for <strong><?php 
                                $branch_name_query = "SELECT branch_name FROM branches WHERE branch_id = $branch_id";
                                $branch_name_result = $conn->query($branch_name_query);
                                $branch_name_row = $branch_name_result ? $branch_name_result->fetch_assoc() : null;
                                echo $branch_name_row ? htmlspecialchars($branch_name_row['branch_name']) : 'Branch ' . $branch_id;
                            ?></strong>
                        </div>
                        <?php endif; ?>
                        
                        <div class="row g-3">
                            <!-- Supplier Code -->
                            <div class="col-12">
                                <div class="code-preview" id="supplierCodePreview">
                                    <?php echo $preview_code; ?>
                                    <i class="bi bi-arrow-repeat refresh-code" onclick="refreshSupplierCode()" title="Generate new code"></i>
                                </div>
                                <input type="hidden" name="supplier_code" id="supplierCodeInput" value="<?php echo $preview_code; ?>">
                                <small class="text-muted">This code will be automatically generated and assigned to the supplier</small>
                            </div>
                            
                            <!-- Basic Information -->
                            <div class="col-12">
                                <h6 class="fw-bold border-bottom pb-2 mb-3" style="color: #2E7D32;">
                                    <i class="bi bi-info-circle me-2"></i>Basic Information
                                </h6>
                            </div>
                            
                            <div class="col-md-6">
                                <label for="supplierName" class="form-label">Supplier Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="supplierName" required>
                            </div>
                            <div class="col-md-6">
                                <label for="contactPerson" class="form-label">Contact Person</label>
                                <input type="text" class="form-control" id="contactPerson">
                            </div>
                            <div class="col-md-6">
                                <label for="supplierEmail" class="form-label">Email Address</label>
                                <input type="email" class="form-control" id="supplierEmail">
                            </div>
                            
                            <!-- Contact Information -->
                            <div class="col-12">
                                <h6 class="fw-bold border-bottom pb-2 mb-3 mt-2" style="color: #2E7D32;">
                                    <i class="bi bi-telephone me-2"></i>Contact Information
                                </h6>
                            </div>
                            
                            <div class="col-md-4">
                                <label for="phoneNumber" class="form-label">Phone Number</label>
                                <input type="text" class="form-control" id="phoneNumber" placeholder="e.g., 02-1234-5678">
                            </div>
                            <div class="col-md-4">
                                <label for="mobileNumber" class="form-label">Mobile Number</label>
                                <input type="text" class="form-control" id="mobileNumber" placeholder="e.g., 0912-345-6789">
                            </div>
                            <div class="col-md-4">
                                <label for="website" class="form-label">Website</label>
                                <input type="url" class="form-control" id="website" placeholder="https://...">
                            </div>
                            
                            <!-- Business Information -->
                            <div class="col-12">
                                <h6 class="fw-bold border-bottom pb-2 mb-3 mt-2" style="color: #2E7D32;">
                                    <i class="bi bi-briefcase me-2"></i>Business Information
                                </h6>
                            </div>
                            
                            <div class="col-md-4">
                                <label for="taxId" class="form-label">Tax ID / TIN</label>
                                <input type="text" class="form-control" id="taxId">
                            </div>
                            <div class="col-md-4">
                                <label for="vatClassification" class="form-label">VAT Classification</label>
                                <select class="form-select" id="vatClassification">
                                    <option value="VAT Registered">VAT Registered</option>
                                    <option value="Non-VAT">Non-VAT</option>
                                    <option value="Zero Rated">Zero Rated</option>
                                    <option value="Exempt">Exempt</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label for="paymentTerms" class="form-label">Payment Terms</label>
                                <select class="form-select" id="paymentTerms">
                                    <?= getPaymentTermsOptions() ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label for="creditLimit" class="form-label">Credit Limit (₱)</label>
                                <input type="text" class="form-control" id="creditLimit" placeholder="0.00">
                            </div>
                            <div class="col-md-4">
                                <label for="supplierStatus" class="form-label">Status</label>
                                <select class="form-select" id="supplierStatus">
                                    <option value="active">Active</option>
                                    <option value="pending">Pending</option>
                                    <option value="inactive">Inactive</option>
                                </select>
                            </div>
                            
                            <div class="col-12">
                                <label for="notes" class="form-label">Notes / Remarks</label>
                                <textarea class="form-control" id="notes" rows="2"></textarea>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-circle me-1"></i> Cancel
                    </button>
                    <button type="button" class="btn btn-primary" onclick="saveSupplier()">
                        <i class="bi bi-check-circle me-1"></i> Save Supplier
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- VIEW SUPPLIER MODAL (no address card) -->
    <div class="modal fade" id="viewSupplierModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="true" data-bs-keyboard="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-eye me-2"></i>Supplier Details
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div id="viewSupplierContent"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-warning" onclick="editFromView()">
                        <i class="bi bi-pencil me-1"></i> Edit Supplier
                    </button>
                    <button type="button" class="btn btn-primary" onclick="createPOFromSupplier()">
                        <i class="bi bi-plus-circle me-1"></i> Create PO
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- DELETE CONFIRMATION MODAL -->
    <div class="modal fade" id="deleteSupplierModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white py-2">
                    <h5 class="modal-title"><i class="bi bi-trash me-2"></i>Confirm Delete</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body py-3">
                    <p>Are you sure you want to delete this supplier?</p>
                    <p class="fw-bold" id="deleteSupplierName"></p>
                    <div class="alert alert-warning py-2">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        If this supplier has existing purchase orders, it will be deactivated instead.
                    </div>
                </div>
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-sm btn-danger" onclick="confirmDeleteSupplier()">Delete Supplier</button>
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

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    // ========== GLOBAL VARIABLES ==========
    let currentSupplierId = null;
    const branchId = <?php echo $branch_id; ?>;
    const viewAllBranches = <?php echo $view_all_branches ? 'true' : 'false'; ?>;
    const suppliersBranchColumnExists = <?php echo $suppliers_branch_column_exists ? 'true' : 'false'; ?>;
    const logoBase64 = '<?php echo $logo_base64; ?>';
    
    let globalScrollTimeout;

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

    function showLoading() {
        Swal.fire({
            title: 'Processing...',
            text: 'Please wait',
            allowOutsideClick: false,
            didOpen: () => { Swal.showLoading(); }
        });
    }

    // ========== FILTER FUNCTIONS ==========
    function filterSuppliers() {
        const searchTerm = document.getElementById('searchInput').value.toLowerCase();
        const statusFilter = document.getElementById('statusFilter').value;
        const branchFilter = document.getElementById('branchFilter')?.value || 'all';
        
        const rows = document.querySelectorAll('.supplier-row');
        rows.forEach(row => {
            const code = row.dataset.code?.toLowerCase() || '';
            const name = row.dataset.name?.toLowerCase() || '';
            const email = row.querySelector('.col-email')?.innerText.toLowerCase() || '';
            const status = row.dataset.status || '';
            const rowBranch = row.dataset.branch || '';
            
            let showRow = true;
            if (statusFilter !== 'all' && status !== statusFilter) showRow = false;
            if (showRow && branchFilter !== 'all' && suppliersBranchColumnExists && viewAllBranches && rowBranch != branchFilter) showRow = false;
            if (showRow && searchTerm !== '') {
                const searchableText = code + ' ' + name + ' ' + email;
                if (!searchableText.includes(searchTerm)) showRow = false;
            }
            row.style.display = showRow ? '' : 'none';
        });
        
        if (window.innerWidth <= 768) setTimeout(() => initMobileTapToView(), 100);
    }

    // ========== SUPPLIER FUNCTIONS ==========
    document.addEventListener('DOMContentLoaded', function() {
        console.log("Suppliers - Management Page (Address Fields Removed)");
        initializeSidebar();
        
        document.getElementById('mobileMenuBtn').addEventListener('click', function() {
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
        
        const desktopToggleBtn = document.getElementById('desktopToggleBtn');
        if (desktopToggleBtn) {
            desktopToggleBtn.addEventListener('click', function(e) { e.stopPropagation(); toggleSidebar(); });
        }
        
        document.querySelectorAll('.sidebar .nav-link').forEach(link => {
            link.addEventListener('click', function() { if (window.innerWidth <= 992) closeMobileSidebar(); });
        });
        
        document.addEventListener('click', function(event) {
            const sidebar = document.getElementById('sidebar');
            const mobileBtn = document.getElementById('mobileMenuBtn');
            const overlay = document.querySelector('.sidebar-overlay');
            if (window.innerWidth <= 992 && sidebar.classList.contains('active') && 
                !sidebar.contains(event.target) && !mobileBtn.contains(event.target) && !overlay?.contains(event.target)) {
                closeMobileSidebar();
            }
        });
        
        const modals = ['supplierModal', 'viewSupplierModal', 'deleteSupplierModal'];
        modals.forEach(modalId => {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.addEventListener('hidden.bs.modal', function () {
                    document.querySelectorAll('.modal-backdrop').forEach(backdrop => backdrop.remove());
                    document.body.classList.remove('modal-open');
                    document.body.style.removeProperty('padding-right');
                });
            }
        });
        
        setActiveMobileNav();
        initMobileTapToView();
        
        document.querySelectorAll('#supplierTable .btn-action').forEach(btn => {
            btn.addEventListener('click', function(e) { e.stopPropagation(); });
        });
        
        // Filter toggle
        const filterToggleBtn = document.getElementById('supplierFilterToggleBtn');
        const filterContent = document.getElementById('supplierFilterContent');
        const filterIcon = document.getElementById('supplierFilterIcon');
        if (filterToggleBtn && filterContent && filterIcon) {
            filterToggleBtn.addEventListener('click', function() {
                const isExpanded = !filterContent.classList.contains('collapsed');
                if (isExpanded) {
                    filterContent.classList.add('collapsed');
                    filterIcon.classList.remove('bi-chevron-up');
                    filterIcon.classList.add('bi-chevron-down');
                    filterToggleBtn.setAttribute('aria-expanded', 'false');
                } else {
                    filterContent.classList.remove('collapsed');
                    filterIcon.classList.remove('bi-chevron-down');
                    filterIcon.classList.add('bi-chevron-up');
                    filterToggleBtn.setAttribute('aria-expanded', 'true');
                }
            });
        }
        
        let resizeTimer;
        window.addEventListener('resize', function() {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(function() { initMobileTapToView(); }, 250);
        });
    });

    function refreshSupplierCode() {
        showLoading();
        const formData = new FormData();
        formData.append('action', 'generate_code');
        fetch('supplier.php', { method: 'POST', body: formData })
            .then(response => response.json())
            .then(data => {
                Swal.close();
                if (data.success) {
                    const previewDiv = document.getElementById('supplierCodePreview');
                    if (previewDiv) previewDiv.innerHTML = data.supplier_code + ' <i class="bi bi-arrow-repeat refresh-code" onclick="refreshSupplierCode()" title="Generate new code"></i>';
                    const input = document.getElementById('supplierCodeInput');
                    if (input) input.value = data.supplier_code;
                } else {
                    Swal.fire('Error', data.message, 'error');
                }
            })
            .catch(error => { Swal.close(); Swal.fire('Error', 'Failed to generate supplier code', 'error'); });
    }

    function showAddSupplierModal() {
        document.getElementById('supplierModalTitle').innerHTML = '<i class="bi bi-plus-circle me-2"></i>Add New Supplier';
        document.getElementById('supplierForm').reset();
        document.getElementById('supplierId').value = '';
        document.getElementById('supplierStatus').value = 'active';
        document.getElementById('vatClassification').value = 'VAT Registered';
        refreshSupplierCode();
        new bootstrap.Modal(document.getElementById('supplierModal')).show();
    }

    function saveSupplier() {
        const supplierId = document.getElementById('supplierId').value;
        const supplierCode = document.getElementById('supplierCodeInput').value;
        const supplierName = document.getElementById('supplierName').value;
        
        if (!supplierCode) { Swal.fire('Warning', 'Supplier Code is required', 'warning'); return; }
        if (!supplierName) { Swal.fire('Warning', 'Supplier Name is required', 'warning'); return; }
        
        showLoading();
        const formData = new FormData();
        if (supplierId) {
            formData.append('action', 'update_supplier');
            formData.append('supplier_id', supplierId);
        } else {
            formData.append('action', 'add_supplier');
        }
        formData.append('supplier_code', supplierCode);
        formData.append('supplier_name', supplierName);
        formData.append('contact_person', document.getElementById('contactPerson').value || '');
        formData.append('email', document.getElementById('supplierEmail').value || '');
        formData.append('phone_number', document.getElementById('phoneNumber').value || '');
        formData.append('mobile_number', document.getElementById('mobileNumber').value || '');
        formData.append('tax_id', document.getElementById('taxId').value || '');
        formData.append('vat_classification', document.getElementById('vatClassification').value);
        formData.append('payment_terms', document.getElementById('paymentTerms').value);
        formData.append('credit_limit', document.getElementById('creditLimit').value || 0);
        formData.append('website', document.getElementById('website').value || '');
        formData.append('notes', document.getElementById('notes').value || '');
        formData.append('status', document.getElementById('supplierStatus').value);
        
        fetch('supplier.php', { method: 'POST', body: formData })
            .then(response => response.json())
            .then(data => {
                Swal.close();
                if (data.success) {
                    Swal.fire({ icon: 'success', title: 'Success!', text: data.message, timer: 2000, showConfirmButton: false }).then(() => {
                        bootstrap.Modal.getInstance(document.getElementById('supplierModal')).hide();
                        location.reload();
                    });
                } else {
                    Swal.fire('Error', data.message, 'error');
                }
            })
            .catch(error => { Swal.close(); Swal.fire('Error', 'An error occurred while saving the supplier', 'error'); });
    }

    function viewSupplier(id) {
        showLoading();
        const formData = new FormData();
        formData.append('action', 'get_supplier');
        formData.append('supplier_id', id);
        
        fetch('supplier.php', { method: 'POST', body: formData })
            .then(response => response.json())
            .then(data => {
                Swal.close();
                if (data.success) {
                    const supplier = data.supplier;
                    const purchaseOrders = data.purchase_orders || [];
                    const createdDate = supplier.created_at ? new Date(supplier.created_at).toLocaleString() : 'N/A';
                    
                    let contactHtml = '';
                    if (supplier.contact_person) contactHtml += '<div class="contact-info"><i class="bi bi-person"></i><span>' + escapeHtml(supplier.contact_person) + '</span></div>';
                    if (supplier.email) contactHtml += '<div class="contact-info"><i class="bi bi-envelope"></i><span>' + escapeHtml(supplier.email) + '</span></div>';
                    if (supplier.phone_number) contactHtml += '<div class="contact-info"><i class="bi bi-telephone"></i><span>' + escapeHtml(supplier.phone_number) + '</span></div>';
                    if (supplier.mobile_number) contactHtml += '<div class="contact-info"><i class="bi bi-phone"></i><span>' + escapeHtml(supplier.mobile_number) + '</span></div>';
                    if (supplier.website) contactHtml += '<div class="contact-info"><i class="bi bi-globe"></i><span><a href="' + escapeHtml(supplier.website) + '" target="_blank">' + escapeHtml(supplier.website) + '</a></span></div>';
                    if (!contactHtml) contactHtml = '<div class="contact-info text-muted">No contact information available</div>';
                    
                    let poHtml = '';
                    if (purchaseOrders.length > 0) {
                        poHtml = `<div class="po-table-container"><div class="po-table-header"><div class="po-col po-col-number"><i class="bi bi-receipt"></i> PO Number</div><div class="po-col po-col-date"><i class="bi bi-calendar"></i> Order Date</div><div class="po-col po-col-amount"><i class="bi bi-cash-stack"></i> Amount</div><div class="po-col po-col-status"><i class="bi bi-flag"></i> Status</div></div><div class="po-table-body">`;
                        purchaseOrders.forEach(po => {
                            const poDate = po.order_date ? new Date(po.order_date).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) : 'N/A';
                            const statusClass = po.po_status === 'received' ? 'status-delivered' : (po.po_status === 'approved' ? 'status-approved' : (po.po_status === 'submitted' ? 'status-processing' : (po.po_status === 'cancelled' ? 'status-cancelled' : 'status-draft')));
                            const statusText = po.po_status === 'received' ? 'Delivered' : (po.po_status === 'approved' ? 'Approved' : (po.po_status === 'submitted' ? 'Processing' : (po.po_status === 'cancelled' ? 'Cancelled' : 'Draft')));
                            let statusIcon = '';
                            if (po.po_status === 'received') statusIcon = '<i class="bi bi-check-circle-fill"></i>';
                            else if (po.po_status === 'approved') statusIcon = '<i class="bi bi-check-circle"></i>';
                            else if (po.po_status === 'submitted') statusIcon = '<i class="bi bi-arrow-repeat"></i>';
                            else if (po.po_status === 'cancelled') statusIcon = '<i class="bi bi-x-circle"></i>';
                            else statusIcon = '<i class="bi bi-file-text"></i>';
                            poHtml += `<div class="po-table-row"><div class="po-col po-col-number" data-label="PO Number"><i class="bi bi-receipt"></i> ${escapeHtml(po.po_number)}</div><div class="po-col po-col-date" data-label="Order Date"><i class="bi bi-calendar"></i> ${poDate}</div><div class="po-col po-col-amount" data-label="Amount"> ₱${Number(po.total_amount || 0).toLocaleString('en-US', {minimumFractionDigits: 2,maximumFractionDigits: 2})}</div><div class="po-col po-col-status" data-label="Status"><span class="po-status-badge ${statusClass}">${statusIcon} ${statusText}</span></div></div>`;
                        });
                        poHtml += `</div></div>`;
                    } else {
                        poHtml = '<div class="po-empty-state"><i class="bi bi-inbox"></i><p>No purchase orders found</p></div>';
                    }
                    
                    const content = document.getElementById('viewSupplierContent');
                    content.innerHTML = `
                        <div class="view-supplier-container">
                            <div class="supplier-header-section">
                                <div class="supplier-code">${escapeHtml(supplier.supplier_code)}</div>
                                <h4 class="supplier-name">${escapeHtml(supplier.supplier_name)}</h4>
                                <div><span class="supplier-status-badge ${supplier.status === 'active' ? 'bg-success' : (supplier.status === 'pending' ? 'bg-warning' : 'bg-secondary')}">${supplier.status.charAt(0).toUpperCase() + supplier.status.slice(1)}</span></div>
                            </div>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <div class="info-card">
                                        <div class="card-title"><i class="bi bi-building"></i> Business Information</div>
                                        <div class="info-row"><span class="info-label">Tax ID / TIN:</span><span class="info-value">${supplier.tax_id ? escapeHtml(supplier.tax_id) : '—'}</span></div>
                                        <div class="info-row"><span class="info-label">VAT Classification:</span><span class="info-value">${supplier.vat_classification ? escapeHtml(supplier.vat_classification) : 'VAT Registered'}</span></div>
                                        <div class="info-row"><span class="info-label">Payment Terms:</span><span class="info-value">${supplier.payment_terms ? escapeHtml(supplier.payment_terms) : 'Net 30'}</span></div>
                                        <div class="info-row"><span class="info-label">Credit Limit:</span><span class="info-value amount">₱${Number(supplier.credit_limit || 0).toFixed(2)}</span></div>
                                    </div>
                                    <div class="info-card">
                                        <div class="card-title"><i class="bi bi-person"></i> Contact Information</div>
                                        ${contactHtml}
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="info-card">
                                        <div class="card-title"><i class="bi bi-clock"></i> System Information</div>
                                        <div class="info-row"><span class="info-label">Created By:</span><span class="info-value">${supplier.created_by_name ? escapeHtml(supplier.created_by_name) : 'N/A'}</span></div>
                                        <div class="info-row"><span class="info-label">Created At:</span><span class="info-value">${createdDate}</span></div>
                                    </div>
                                </div>
                            </div>
                            ${supplier.notes ? `<div class="info-card mt-3"><div class="card-title"><i class="bi bi-chat"></i> Notes</div><p class="notes-text">${escapeHtml(supplier.notes)}</p></div>` : ''}
                            <div class="info-card mt-3"><div class="card-title"><i class="bi bi-box-seam"></i> Recent Purchase Orders</div>${poHtml}</div>
                        </div>
                    `;
                    currentSupplierId = id;
                    new bootstrap.Modal(document.getElementById('viewSupplierModal')).show();
                } else {
                    Swal.fire('Error', data.message, 'error');
                }
            })
            .catch(error => { Swal.close(); Swal.fire('Error', 'An error occurred while fetching supplier details', 'error'); });
    }

    function escapeHtml(str) {
        if (!str) return '';
        return str.replace(/[&<>]/g, function(m) {
            if (m === '&') return '&amp;';
            if (m === '<') return '&lt;';
            if (m === '>') return '&gt;';
            return m;
        });
    }

    function editFromView() {
        bootstrap.Modal.getInstance(document.getElementById('viewSupplierModal')).hide();
        setTimeout(() => { editSupplier(currentSupplierId); }, 300);
    }

    function editSupplier(id) {
        showLoading();
        const formData = new FormData();
        formData.append('action', 'get_supplier');
        formData.append('supplier_id', id);
        fetch('supplier.php', { method: 'POST', body: formData })
            .then(response => response.json())
            .then(data => {
                Swal.close();
                if (data.success) {
                    const supplier = data.supplier;
                    document.getElementById('supplierModalTitle').innerHTML = '<i class="bi bi-pencil me-2"></i>Edit Supplier';
                    document.getElementById('supplierId').value = supplier.supplier_id;
                    document.getElementById('supplierCodePreview').innerHTML = supplier.supplier_code + ' <i class="bi bi-arrow-repeat refresh-code" onclick="refreshSupplierCode()" title="Generate new code"></i>';
                    document.getElementById('supplierCodeInput').value = supplier.supplier_code;
                    document.getElementById('supplierName').value = supplier.supplier_name || '';
                    document.getElementById('contactPerson').value = supplier.contact_person || '';
                    document.getElementById('supplierEmail').value = supplier.email || '';
                    document.getElementById('phoneNumber').value = supplier.phone_number || '';
                    document.getElementById('mobileNumber').value = supplier.mobile_number || '';
                    document.getElementById('taxId').value = supplier.tax_id || '';
                    document.getElementById('vatClassification').value = supplier.vat_classification || 'VAT Registered';
                    document.getElementById('paymentTerms').value = supplier.payment_terms || 'Net 30';
                    document.getElementById('creditLimit').value = supplier.credit_limit || 0;
                    document.getElementById('website').value = supplier.website || '';
                    document.getElementById('notes').value = supplier.notes || '';
                    document.getElementById('supplierStatus').value = supplier.status || 'active';
                    new bootstrap.Modal(document.getElementById('supplierModal')).show();
                } else {
                    Swal.fire('Error', data.message, 'error');
                }
            })
            .catch(error => { Swal.close(); Swal.fire('Error', 'An error occurred while fetching supplier details', 'error'); });
    }

    function createPOFromSupplier() {
        bootstrap.Modal.getInstance(document.getElementById('viewSupplierModal')).hide();
        setTimeout(() => { window.location.href = 'purchase_order.php?supplier_id=' + currentSupplierId; }, 300);
    }

    function deleteSupplier(id) {
        const row = document.querySelector('.supplier-row[data-id="' + id + '"]');
        if (!row) return;
        document.getElementById('deleteSupplierName').textContent = row.dataset.name;
        currentSupplierId = id;
        new bootstrap.Modal(document.getElementById('deleteSupplierModal')).show();
    }

    function confirmDeleteSupplier() {
        showLoading();
        const formData = new FormData();
        formData.append('action', 'delete_supplier');
        formData.append('supplier_id', currentSupplierId);
        fetch('supplier.php', { method: 'POST', body: formData })
            .then(response => response.json())
            .then(data => {
                Swal.close();
                if (data.success) {
                    Swal.fire({ icon: 'success', title: 'Success!', text: data.message, timer: 2000, showConfirmButton: false }).then(() => {
                        bootstrap.Modal.getInstance(document.getElementById('deleteSupplierModal')).hide();
                        location.reload();
                    });
                } else {
                    Swal.fire('Error', data.message, 'error');
                }
            })
            .catch(error => { Swal.close(); Swal.fire('Error', 'An error occurred while deleting the supplier', 'error'); });
    }

    function printSuppliers() {
        const printBtn = document.querySelector('.btn-outline-success[onclick="printSuppliers()"]');
        if (printBtn) { printBtn.innerHTML = '<i class="bi bi-printer"></i> Preparing...'; printBtn.disabled = true; }
        const filterData = { status: document.getElementById('statusFilter').value, search: document.getElementById('searchInput').value };
        showLoading();
        const formData = new FormData();
        formData.append('action', 'print_suppliers');
        formData.append('filter_data', JSON.stringify(filterData));
        fetch('supplier.php', { method: 'POST', body: formData })
            .then(response => response.json())
            .then(data => {
                Swal.close();
                if (data.success && data.suppliers.length > 0) {
                    const htmlContent = generatePrintHTML(data.suppliers, data.branch_name);
                    const iframe = document.getElementById('printFrame');
                    const iframeDoc = iframe.contentWindow.document;
                    iframeDoc.open();
                    iframeDoc.write(htmlContent);
                    iframeDoc.close();
                    setTimeout(() => iframe.contentWindow.print(), 250);
                } else {
                    Swal.fire({ icon: 'warning', title: 'No Data', text: 'No suppliers match the current filters', confirmButtonColor: '#0d6efd' });
                }
                if (printBtn) { printBtn.innerHTML = '<i class="bi bi-printer"></i> Print'; printBtn.disabled = false; }
            })
            .catch(error => { Swal.close(); console.error(error); Swal.fire({ icon: 'error', title: 'Error', text: 'An error occurred while preparing print', confirmButtonColor: '#0d6efd' }); if (printBtn) { printBtn.innerHTML = '<i class="bi bi-printer"></i> Print'; printBtn.disabled = false; } });
    }

    function generatePrintHTML(suppliers, branchName) {
        let tableRows = '';
        let totalActive = 0, totalInactive = 0, totalPending = 0;
        suppliers.forEach(supplier => {
            if (supplier.status === 'active') totalActive++;
            else if (supplier.status === 'pending') totalPending++;
            else totalInactive++;
            tableRows += '<tr>';
            tableRows += '<td style="padding: 3px; border: 1px solid #000;">' + supplier.supplier_code + '</td>';
            tableRows += '<td style="padding: 3px; border: 1px solid #000;">' + supplier.supplier_name + '</td>';
            tableRows += '<td style="padding: 3px; border: 1px solid #000;">' + (supplier.contact_person || '—') + '</td>';
            tableRows += '<td style="padding: 3px; border: 1px solid #000;">' + (supplier.phone_number || supplier.mobile_number || '—') + '</td>';
            tableRows += '<td style="padding: 3px; border: 1px solid #000;">' + (supplier.email || '—') + '</td>';
            tableRows += '<td style="padding: 3px; border: 1px solid #000;">' + (supplier.payment_terms || 'Net 30') + '</td>';
            if (suppliersBranchColumnExists && viewAllBranches) tableRows += '<td style="padding: 3px; border: 1px solid #000;">' + (supplier.branch_name || 'Branch ' + supplier.branch_id) + '</td>';
            tableRows += '<td style="padding: 3px; border: 1px solid #000;">' + supplier.status + '</td>';
            tableRows += '<td style="padding: 3px; border: 1px solid #000; text-align: right;">' + (supplier.po_count || 0) + '</td>';
            tableRows += '<td style="padding: 3px; border: 1px solid #000; text-align: right;">₱' + Number(supplier.total_spent || 0).toFixed(2) + '</td>';
            tableRows += '</tr>';
        });
        const currentDate = new Date().toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
        return '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Suppliers Report</title><style>body{font-family:Arial;margin:0;padding:0;font-size:9px}.print-container{max-width:100%;margin:0}.print-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:5px;border-bottom:1px solid #000;padding-bottom:3px}.logo-section{display:flex;align-items:center;gap:5px}.company-logo{width:30px;height:auto}.company-info h1{font-size:14px;margin:0;font-weight:bold}.company-info p{font-size:8px;margin:0}.report-title h2{font-size:12px;margin:0}.report-title .date-info{font-size:8px}.summary-box{border:1px solid #000;padding:3px;margin-bottom:5px;display:flex}.summary-item{flex:1;text-align:center;border-right:1px solid #000}.summary-item:last-child{border-right:none}.summary-label{font-size:8px;font-weight:bold}.summary-value{font-size:11px;font-weight:bold}table{width:100%;border-collapse:collapse;font-size:8px}th{border:1px solid #000;padding:3px;text-align:left;font-weight:bold;background:white !important;color:black !important}td{border:1px solid #000;padding:3px}.print-footer{margin-top:5px;border-top:1px solid #000;padding-top:3px;display:flex;justify-content:space-between;font-size:8px}</style></head><body><div class="print-container"><div class="print-header"><div class="logo-section"><img src="' + logoBase64 + '" alt="AMGC Logo" class="company-logo"><div class="company-info"><h1>AMGC</h1><p>Suppliers Report</p></div></div><div class="report-title"><h2>SUPPLIER LIST</h2><div class="date-info">' + currentDate + '</div></div></div><div class="summary-box"><div class="summary-item"><div class="summary-label">Total Suppliers</div><div class="summary-value">' + suppliers.length + '</div></div><div class="summary-item"><div class="summary-label">Active</div><div class="summary-value">' + totalActive + '</div></div><div class="summary-item"><div class="summary-label">Pending</div><div class="summary-value">' + totalPending + '</div></div><div class="summary-item"><div class="summary-label">Inactive</div><div class="summary-value">' + totalInactive + '</div></div><div class="summary-item"><div class="summary-label">Branch</div><div class="summary-value">' + (!viewAllBranches ? branchName : 'All') + '</div></div></div><thead><tr><th>Code</th><th>Supplier Name</th><th>Contact Person</th><th>Phone</th><th>Email</th><th>Payment Terms</th>' + (suppliersBranchColumnExists && viewAllBranches ? '<th>Branch</th>' : '') + '<th>Status</th><th style="text-align: right;">PO Count</th><th style="text-align: right;">Total Spent</th></tr></thead><tbody>' + tableRows + '</tbody></div><div class="print-footer"><div>Generated: ' + currentDate + '</div><div>' + (document.querySelector('.user-name-sidebar')?.textContent || 'Branch Admin') + '</div></div></div></body></html>';
    }

    function exportToExcel() {
        const rows = document.querySelectorAll('.supplier-row:not([style*="display: none"])');
        if (rows.length === 0) { Swal.fire('Warning', 'No suppliers to export', 'warning'); return; }
        const excelData = [];
        const headers = ['Supplier Code', 'Supplier Name', 'Contact Person', 'Phone Number', 'Mobile Number', 'Email', 'VAT Classification', 'Payment Terms', 'Credit Limit', 'Status', ...(suppliersBranchColumnExists && viewAllBranches ? ['Branch'] : []), 'Purchase Orders', 'Total Spent'];
        excelData.push(headers);
        rows.forEach(row => {
            const cells = row.querySelectorAll('td');
            let idx = 0;
            const code = cells[idx++]?.innerText || '';
            const name = cells[idx++]?.innerText.split('\n')[0] || '';
            const contact = cells[idx++]?.innerText || '';
            const phone = cells[idx++]?.innerText || '';
            const email = cells[idx++]?.innerText || '';
            const payment = cells[idx++]?.innerText || '';
            let branch = '';
            if (suppliersBranchColumnExists && viewAllBranches) branch = cells[idx++]?.innerText || '';
            const status = cells[idx++]?.innerText || '';
            excelData.push([code, name, contact, phone, '', email, 'VAT Registered', payment, '', status, ...(suppliersBranchColumnExists && viewAllBranches ? [branch] : []), row.dataset.poCount || 0, row.dataset.totalSpent || 0]);
        });
        const wb = XLSX.utils.book_new();
        const ws = XLSX.utils.aoa_to_sheet(excelData);
        ws['!cols'] = [{ wch: 12 }, { wch: 25 }, { wch: 20 }, { wch: 15 }, { wch: 15 }, { wch: 25 }, { wch: 15 }, { wch: 12 }, { wch: 15 }, { wch: 10 }, ...(suppliersBranchColumnExists && viewAllBranches ? [{ wch: 15 }] : []), { wch: 15 }, { wch: 18 }];
        XLSX.utils.book_append_sheet(wb, ws, 'Suppliers');
        const dateStr = new Date().toISOString().slice(0,10).replace(/-/g, '');
        let filename = 'Suppliers_' + dateStr;
        if (suppliersBranchColumnExists && !viewAllBranches) filename += '_Branch_' + branchId;
        filename += '.xlsx';
        XLSX.writeFile(wb, filename);
        Swal.fire({ icon: 'success', title: 'Export Complete', timer: 2000, showConfirmButton: false });
    }

    function copySQL(table) {
        let sql = 'CREATE TABLE IF NOT EXISTS suppliers (supplier_id INT AUTO_INCREMENT PRIMARY KEY, supplier_code VARCHAR(50) NOT NULL UNIQUE, supplier_name VARCHAR(150) NOT NULL, contact_person VARCHAR(100), email VARCHAR(100), phone_number VARCHAR(20), mobile_number VARCHAR(20), tax_id VARCHAR(50), vat_classification ENUM(\'VAT Registered\',\'Non-VAT\',\'Zero Rated\',\'Exempt\') DEFAULT \'VAT Registered\', payment_terms VARCHAR(100) DEFAULT \'Net 30\', credit_limit DECIMAL(12,2) DEFAULT 0.00, website VARCHAR(255), notes TEXT, status ENUM(\'active\',\'inactive\',\'pending\') DEFAULT \'active\', branch_id INT, created_by INT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, FOREIGN KEY (branch_id) REFERENCES branches(branch_id) ON DELETE SET NULL, FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci';
        navigator.clipboard.writeText(sql).then(() => { Swal.fire({ icon: 'success', title: 'Copied!', timer: 1500, showConfirmButton: false }); });
    }

    function cleanupModalBackdrops() {
        document.querySelectorAll('.modal-backdrop').forEach(backdrop => backdrop.remove());
        document.body.classList.remove('modal-open');
        document.body.style.removeProperty('padding-right');
        document.body.style.removeProperty('overflow');
    }

    
    function confirmLogout() {
        const modal = bootstrap.Modal.getInstance(document.getElementById('profileModal'));
        if (modal) modal.hide();
        Swal.fire({ title: 'Are you sure?', text: 'You will be logged out of the system', icon: 'question', showCancelButton: true, confirmButtonColor: '#07d826', cancelButtonColor: '#6c757d', confirmButtonText: 'Yes, logout' }).then((result) => { if (result.isConfirmed) { localStorage.removeItem('sidebarCollapsed'); window.location.href = '../logout.php'; } });
    }
    function logout() { confirmLogout(); }

    document.addEventListener('keydown', function(e) {
        if (e.ctrlKey && e.key === 'b' && window.innerWidth > 992) { e.preventDefault(); toggleSidebar(); }
        else if (e.ctrlKey && e.key === 'f') { e.preventDefault(); document.getElementById('searchInput').focus(); }
        else if (e.ctrlKey && e.key === 'n') { e.preventDefault(); showAddSupplierModal(); }
    });

    
// ========== MOBILE BOTTOM NAV FIX ==========
window.closeAllMobileDropdowns = function(){
    document.querySelectorAll('.more-dropdown').forEach(el=>{
        el.classList.remove('show');
    });

    document.querySelectorAll('.more-btn').forEach(btn=>{
        btn.classList.remove('active');
        btn.setAttribute('aria-expanded','false');
    });
};

window.toggleMobileDropdown = function(event, dropdownId){
    if(event){
        event.preventDefault();
        event.stopPropagation();
    }

    const dropdown=document.getElementById(dropdownId);
    const btn=event.currentTarget;

    if(!dropdown) return false;

    const isOpen=dropdown.classList.contains('show');

    closeAllMobileDropdowns();

    if(!isOpen){
        dropdown.classList.add('show');
        btn.classList.add('active');
        btn.setAttribute('aria-expanded','true');
    }

    return false;
};

window.toggleDropdown=function(event,dropdownId){
    return toggleMobileDropdown(event,dropdownId);
};

window.showProfileModal=function(event){
    if(event){
        event.preventDefault();
        event.stopPropagation();
    }

    closeAllMobileDropdowns();

    if(typeof cleanupModalBackdrops==='function'){
        cleanupModalBackdrops();
    }

    bootstrap.Modal.getOrCreateInstance(
        document.getElementById('profileModal')
    ).show();

    return false;
};

document.addEventListener('click',function(e){
    if(!e.target.closest('.mobile-nav')){
        closeAllMobileDropdowns();
    }
});

document.addEventListener('keydown',function(e){if(e.key==='Escape')closeAllMobileDropdowns();});
    window.addEventListener('scroll', function() { if (globalScrollTimeout) clearTimeout(globalScrollTimeout); globalScrollTimeout = setTimeout(() => { closeAllDropdowns(); }, 150); });

    function fixPurchaseDropdownPosition() {
        const purchaseDropdown = document.querySelector('#purchaseDropdown .more-dropdown');
        if (purchaseDropdown) { purchaseDropdown.style.setProperty('right', '0', 'important'); purchaseDropdown.style.setProperty('left', 'auto', 'important'); }
    }
    document.addEventListener('DOMContentLoaded', function() { fixPurchaseDropdownPosition(); setActiveMobileNav(); });
    window.addEventListener('resize', fixPurchaseDropdownPosition);
    const purchaseMenu = document.getElementById('purchaseDropdownMenu');
    if (purchaseMenu) {
        new MutationObserver(mutations => { mutations.forEach(mutation => { if (mutation.type === 'attributes' && mutation.attributeName === 'class' && purchaseMenu.classList.contains('show')) fixPurchaseDropdownPosition(); }); }).observe(purchaseMenu, { attributes: true });
    }

    function setActiveMobileNav() {
        const currentPage = window.location.pathname.split('/').pop();
        document.querySelectorAll('.mobile-nav .nav-link, .more-btn, .dropdown-item, .has-active').forEach(el => el.classList.remove('active', 'has-active'));
        document.querySelectorAll('.mobile-nav .nav-link:not(.more-btn)').forEach(link => { if (link.getAttribute('href') === currentPage) link.classList.add('active'); });
        document.querySelectorAll('.more-dropdown .dropdown-item').forEach(item => {
            if (item.getAttribute('href') === currentPage) {
                item.classList.add('active');
                const parentDropdown = item.closest('.dropdown-more');
                if (parentDropdown) { const parentBtn = parentDropdown.querySelector('.more-btn'); if (parentBtn) parentBtn.classList.add('has-active'); }
            }
        });
        if (currentPage === 'trip_tickets.php') { const tripLink = document.querySelector('#mobileNav .nav-link[href="trip_tickets.php"]'); if (tripLink) tripLink.classList.add('active'); }
    }

    function initMobileTapToView() {
        const isMobile = window.innerWidth <= 768;
        const rows = document.querySelectorAll('#supplierTable tbody tr.supplier-row');
        if (isMobile) {
            rows.forEach(row => {
                const visible = row.style.display !== 'none';
                if (visible) {
                    if (!row.hasAttribute('data-mobile-listener')) {
                        row.setAttribute('data-mobile-listener', 'true');
                        row.addEventListener('click', handleMobileRowClick);
                        row.style.cursor = 'pointer';
                    }
                } else {
                    if (row.hasAttribute('data-mobile-listener')) {
                        row.removeEventListener('click', handleMobileRowClick);
                        row.removeAttribute('data-mobile-listener');
                        row.style.cursor = '';
                    }
                }
            });
        } else {
            rows.forEach(row => {
                if (row.hasAttribute('data-mobile-listener')) {
                    row.removeEventListener('click', handleMobileRowClick);
                    row.removeAttribute('data-mobile-listener');
                    row.style.cursor = '';
                }
            });
        }
    }

    function handleMobileRowClick(event) {
        if (event.target.closest('.btn-call') || event.target.closest('.btn-message') ||
            event.target.closest('.btn-edit') || event.target.closest('.btn-delete')) return;
        const row = event.currentTarget;
        const supplierId = row.dataset.id;
        if (supplierId) viewSupplier(supplierId);
    }

    function toggleSidebarDropdown(event, targetId) {
        event.preventDefault(); event.stopPropagation();
        const target = document.getElementById(targetId);
        const btn = event.currentTarget;
        const arrow = btn.querySelector('.dropdown-arrow');
        const sidebar = document.getElementById('sidebar');
        if (sidebar.classList.contains('collapsed')) {
            sidebar.classList.remove('collapsed');
            localStorage.setItem('sidebarCollapsed', 'false');
            setTimeout(() => {
                document.querySelectorAll('.sidebar .collapse.show').forEach(collapse => { if (collapse.id !== targetId) collapse.classList.remove('show'); });
                target.classList.add('show');
                if (arrow) arrow.style.transform = 'translateY(-50%) rotate(180deg)';
            }, 50);
            return;
        }
        if (target.classList.contains('show')) {
            target.classList.remove('show');
            if (arrow) arrow.style.transform = 'translateY(-50%) rotate(0deg)';
        } else {
            document.querySelectorAll('.sidebar .collapse.show').forEach(collapse => { if (collapse.id !== targetId) collapse.classList.remove('show'); });
            target.classList.add('show');
            if (arrow) arrow.style.transform = 'translateY(-50%) rotate(180deg)';
        }
    }

    function setActiveSidebarItem() {
        const currentPage = window.location.pathname.split('/').pop();
        document.querySelectorAll('.sidebar .nav-link').forEach(link => link.classList.remove('active'));
        document.querySelectorAll('.sidebar .nav-link').forEach(link => {
            if (link.getAttribute('href') === currentPage) {
                link.classList.add('active');
                const collapseDiv = link.closest('.collapse');
                if (collapseDiv) {
                    collapseDiv.classList.add('show');
                    const parentBtn = document.querySelector(`[onclick*="${collapseDiv.id}"]`);
                    if (parentBtn) { const arrow = parentBtn.querySelector('.dropdown-arrow'); if (arrow) arrow.style.transform = 'translateY(-50%) rotate(180deg)'; }
                }
            }
        });
    }

    function updateDropdownParentActiveState() {
        const sidebar = document.getElementById('sidebar');
        if (!sidebar) return;
        if (sidebar.classList.contains('collapsed')) {
            document.querySelectorAll('.dropdown-nav').forEach(dropdownNav => {
                const hasActiveChild = dropdownNav.querySelector('.nav-link.active');
                const parentLink = dropdownNav.querySelector(':scope > .nav-link');
                if (hasActiveChild && parentLink) parentLink.classList.add('active');
                else if (parentLink) parentLink.classList.remove('active');
            });
        }
    }

    document.addEventListener('DOMContentLoaded', function() {
        const sidebar = document.getElementById('sidebar');
        if (sidebar && window.innerWidth > 992) {
            if (localStorage.getItem('sidebarCollapsed') === 'true') sidebar.classList.add('collapsed');
            else sidebar.classList.remove('collapsed');
        }
        setActiveSidebarItem();
        updateDropdownParentActiveState();
        document.querySelectorAll('.sidebar .collapse').forEach(collapse => { collapse.addEventListener('click', function(e) { e.stopPropagation(); }); });
        const desktopToggleBtn = document.getElementById('desktopToggleBtn');
        if (desktopToggleBtn) {
            desktopToggleBtn.addEventListener('click', function() {
                setTimeout(() => {
                    if (sidebar.classList.contains('collapsed')) {
                        document.querySelectorAll('.sidebar .collapse.show').forEach(collapse => {
                            collapse.classList.remove('show');
                            const parentBtn = document.querySelector(`[onclick*="${collapse.id}"]`);
                            if (parentBtn) { const arrow = parentBtn.querySelector('.dropdown-arrow'); if (arrow) arrow.style.transform = 'translateY(-50%) rotate(0deg)'; }
                        });
                    }
                }, 50);
            });
        }
        const mobileMenuBtn = document.getElementById('mobileMenuBtn');
        if (mobileMenuBtn) mobileMenuBtn.addEventListener('click', toggleSidebar);
        document.addEventListener('click', function(e) {
            if (window.innerWidth <= 992 && sidebar && sidebar.classList.contains('active') && !sidebar.contains(e.target) && mobileMenuBtn && !mobileMenuBtn.contains(e.target)) {
                sidebar.classList.remove('active');
                const overlay = document.querySelector('.sidebar-overlay');
                if (overlay) overlay.remove();
            }
        });
    });

    document.getElementById('creditLimit').addEventListener('input', function(e) {
        let value = e.target.value.replace(/,/g, '').replace(/[^\d.]/g, '');
        let parts = value.split('.');
        if (parts.length > 2) { value = parts[0] + '.' + parts.slice(1).join(''); parts = value.split('.'); }
        parts[0] = parts[0] ? Number(parts[0]).toLocaleString('en-US') : '';
        e.target.value = parts.join('.');
    });
    </script>
</body>
</html>