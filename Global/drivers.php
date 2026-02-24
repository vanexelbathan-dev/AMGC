<?php
// Global drivers.php - User Management for Global Admin
// Mimics the exact functionality of users.php but for Global view

// Turn off error display completely for AJAX requests
if (isset($_POST['action'])) {
    ini_set('display_errors', 0);
    error_reporting(0);
} else {
    // Only enable error display for normal page load
    ini_set('display_errors', 1);
    error_reporting(E_ALL);
}

require_once '../config/database.php';
require_once '../config/session_handler.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

// Get current user info and branch context
$user_id = $_SESSION['user_id'] ?? 0;
$user_name = isset($_SESSION['first_name']) ? $_SESSION['first_name'] . ' ' . $_SESSION['last_name'] : 'Global Admin';
$user_role = isset($_SESSION['role']) ? $_SESSION['role'] : 'global_admin';
$branch_id = $_SESSION['branch_id'] ?? 0;

// Global users can view all branches
$view_all_branches = true; // Always true for Global

// Check if branch_id column exists in drivers table
$drivers_branch_column_exists = false;
$check_column = $conn->query("SHOW COLUMNS FROM drivers LIKE 'branch_id'");
if ($check_column && $check_column->num_rows > 0) {
    $drivers_branch_column_exists = true;
}

// Get all active branches for dropdowns
$branches_sql = "SELECT branch_id, branch_name, branch_code FROM branches WHERE status = 'active' ORDER BY branch_name";
$branches_result = $conn->query($branches_sql);
$branches = [];
while ($row = $branches_result->fetch_assoc()) {
    $branches[] = $row;
}

// ========== HANDLE AJAX REQUESTS ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    // Clear any output buffers to prevent HTML contamination
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    // Start fresh output buffer for AJAX response
    ob_start();
    
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    
    $response = ['success' => false, 'message' => ''];
    
    try {
        $conn->begin_transaction();
        
        // ADD DRIVER WITH USER ACCOUNT
        if ($_POST['action'] === 'add_driver') {
            // Get and sanitize inputs
            $driver_name = trim($_POST['driver_name'] ?? '');
            $license_number = trim($_POST['license_number'] ?? '');
            $license_expiry = !empty($_POST['license_expiry']) ? $_POST['license_expiry'] : null;
            $contact_number = !empty($_POST['contact_number']) ? trim($_POST['contact_number']) : null;
            $vehicle_type = !empty($_POST['vehicle_type']) ? trim($_POST['vehicle_type']) : null;
            $vehicle_plate_number = !empty($_POST['vehicle_plate_number']) ? trim($_POST['vehicle_plate_number']) : null;
            $status = $_POST['status'] ?? 'active';
            $branch_id = intval($_POST['branch_id'] ?? 0);
            
            // User account information
            $email = trim($_POST['email'] ?? '');
            $password = $_POST['password'] ?? '';
            $first_name = trim($_POST['first_name'] ?? '');
            $last_name = trim($_POST['last_name'] ?? '');
            
            // Validate required fields
            if (empty($driver_name)) throw new Exception('Driver Name is required');
            if (empty($license_number)) throw new Exception('License Number is required');
            if (empty($branch_id)) throw new Exception('Branch is required');
            if (empty($email)) throw new Exception('Email is required');
            if (empty($password)) throw new Exception('Password is required');
            if (strlen($password) < 6) throw new Exception('Password must be at least 6 characters long');
            if (empty($first_name)) throw new Exception('First Name is required');
            if (empty($last_name)) throw new Exception('Last Name is required');
            
            // Check if email already exists
            $check_email = $conn->prepare("SELECT user_id FROM users WHERE email = ?");
            $check_email->bind_param("s", $email);
            $check_email->execute();
            if ($check_email->get_result()->num_rows > 0) {
                throw new Exception('Email already exists in the system');
            }
            
            // Check if license number already exists
            $check_license = $conn->prepare("SELECT driver_id FROM drivers WHERE license_number = ?");
            $check_license->bind_param("s", $license_number);
            $check_license->execute();
            if ($check_license->get_result()->num_rows > 0) {
                throw new Exception('License number already exists');
            }
            
            // Insert driver
            $insert_driver = $conn->prepare("INSERT INTO drivers (driver_name, license_number, license_expiry, contact_number, vehicle_type, vehicle_plate_number, status, branch_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
            $insert_driver->bind_param("sssssssi", $driver_name, $license_number, $license_expiry, $contact_number, $vehicle_type, $vehicle_plate_number, $status, $branch_id);
            
            if (!$insert_driver->execute()) {
                throw new Exception('Failed to add driver: ' . $insert_driver->error);
            }
            
            $driver_id = $conn->insert_id;
            
            // Hash the password
            $password_hash = password_hash($password, PASSWORD_DEFAULT);
            
            // Insert user account
            $insert_user = $conn->prepare("INSERT INTO users (email, password_hash, first_name, last_name, role, branch_id, driver_id, contact_number, status, created_at, updated_at) VALUES (?, ?, ?, ?, 'delivery', ?, ?, ?, 'active', NOW(), NOW())");
            $insert_user->bind_param("ssssiiss", $email, $password_hash, $first_name, $last_name, $branch_id, $driver_id, $contact_number, $status);
            
            if (!$insert_user->execute()) {
                throw new Exception('Failed to create user account: ' . $insert_user->error);
            }
            
            $conn->commit();
            $response = ['success' => true, 'message' => 'Driver and user account created successfully'];
        }
        
        // ADD BRANCH ADMIN
        elseif ($_POST['action'] === 'add_branch_admin') {
            $email = trim($_POST['email'] ?? '');
            $password = $_POST['password'] ?? '';
            $first_name = trim($_POST['first_name'] ?? '');
            $last_name = trim($_POST['last_name'] ?? '');
            $contact_number = !empty($_POST['contact_number']) ? trim($_POST['contact_number']) : null;
            $status = $_POST['status'] ?? 'active';
            $branch_id = intval($_POST['branch_id'] ?? 0);
            
            // Validate
            if (empty($email)) throw new Exception('Email is required');
            if (empty($password)) throw new Exception('Password is required');
            if (strlen($password) < 6) throw new Exception('Password must be at least 6 characters');
            if (empty($first_name)) throw new Exception('First Name is required');
            if (empty($last_name)) throw new Exception('Last Name is required');
            if (empty($branch_id)) throw new Exception('Branch is required');
            
            // Check email
            $check_email = $conn->prepare("SELECT user_id FROM users WHERE email = ?");
            $check_email->bind_param("s", $email);
            $check_email->execute();
            if ($check_email->get_result()->num_rows > 0) {
                throw new Exception('Email already exists');
            }
            
            $password_hash = password_hash($password, PASSWORD_DEFAULT);
            
            // Insert branch admin
            $insert_user = $conn->prepare("INSERT INTO users (email, password_hash, first_name, last_name, role, branch_id, contact_number, status, created_at, updated_at) VALUES (?, ?, ?, ?, 'branch_admin', ?, ?, ?, NOW(), NOW())");
            $insert_user->bind_param("ssssiss", $email, $password_hash, $first_name, $last_name, $branch_id, $contact_number, $status);
            
            if (!$insert_user->execute()) {
                throw new Exception('Failed to create branch admin: ' . $insert_user->error);
            }
            
            $conn->commit();
            $response = ['success' => true, 'message' => 'Branch admin created successfully'];
        }
        
        // ADD WAREHOUSE STAFF
        elseif ($_POST['action'] === 'add_warehouse') {
            $email = trim($_POST['email'] ?? '');
            $password = $_POST['password'] ?? '';
            $first_name = trim($_POST['first_name'] ?? '');
            $last_name = trim($_POST['last_name'] ?? '');
            $category = !empty($_POST['category']) ? trim($_POST['category']) : null;
            $contact_number = !empty($_POST['contact_number']) ? trim($_POST['contact_number']) : null;
            $status = $_POST['status'] ?? 'active';
            $branch_id = intval($_POST['branch_id'] ?? 0);
            
            // Validate
            if (empty($email)) throw new Exception('Email is required');
            if (empty($password)) throw new Exception('Password is required');
            if (strlen($password) < 6) throw new Exception('Password must be at least 6 characters');
            if (empty($first_name)) throw new Exception('First Name is required');
            if (empty($last_name)) throw new Exception('Last Name is required');
            if (empty($branch_id)) throw new Exception('Branch is required');
            
            // Check email
            $check_email = $conn->prepare("SELECT user_id FROM users WHERE email = ?");
            $check_email->bind_param("s", $email);
            $check_email->execute();
            if ($check_email->get_result()->num_rows > 0) {
                throw new Exception('Email already exists');
            }
            
            $password_hash = password_hash($password, PASSWORD_DEFAULT);
            
            // Insert warehouse staff
            $insert_user = $conn->prepare("INSERT INTO users (email, password_hash, first_name, last_name, role, branch_id, category, contact_number, status, created_at, updated_at) VALUES (?, ?, ?, ?, 'warehouse', ?, ?, ?, ?, NOW(), NOW())");
            $insert_user->bind_param("ssssisss", $email, $password_hash, $first_name, $last_name, $branch_id, $category, $contact_number, $status);
            
            if (!$insert_user->execute()) {
                throw new Exception('Failed to create warehouse staff: ' . $insert_user->error);
            }
            
            $conn->commit();
            $response = ['success' => true, 'message' => 'Warehouse staff created successfully'];
        }
        
        // ADD SALES AGENT
        elseif ($_POST['action'] === 'add_sales') {
            $email = trim($_POST['email'] ?? '');
            $password = $_POST['password'] ?? '';
            $first_name = trim($_POST['first_name'] ?? '');
            $last_name = trim($_POST['last_name'] ?? '');
            $contact_number = !empty($_POST['contact_number']) ? trim($_POST['contact_number']) : null;
            $status = $_POST['status'] ?? 'active';
            $branch_id = intval($_POST['branch_id'] ?? 0);
            
            // Validate
            if (empty($email)) throw new Exception('Email is required');
            if (empty($password)) throw new Exception('Password is required');
            if (strlen($password) < 6) throw new Exception('Password must be at least 6 characters');
            if (empty($first_name)) throw new Exception('First Name is required');
            if (empty($last_name)) throw new Exception('Last Name is required');
            if (empty($branch_id)) throw new Exception('Branch is required');
            
            // Check email
            $check_email = $conn->prepare("SELECT user_id FROM users WHERE email = ?");
            $check_email->bind_param("s", $email);
            $check_email->execute();
            if ($check_email->get_result()->num_rows > 0) {
                throw new Exception('Email already exists');
            }
            
            $password_hash = password_hash($password, PASSWORD_DEFAULT);
            
            // Insert sales agent
            $insert_user = $conn->prepare("INSERT INTO users (email, password_hash, first_name, last_name, role, branch_id, contact_number, status, created_at, updated_at) VALUES (?, ?, ?, ?, 'sales', ?, ?, ?, NOW(), NOW())");
            $insert_user->bind_param("ssssiss", $email, $password_hash, $first_name, $last_name, $branch_id, $contact_number, $status);
            
            if (!$insert_user->execute()) {
                throw new Exception('Failed to create sales agent: ' . $insert_user->error);
            }
            
            $conn->commit();
            $response = ['success' => true, 'message' => 'Sales agent created successfully'];
        }
        
        // UPDATE USER
        elseif ($_POST['action'] === 'update_user') {
            $user_id = (int)$_POST['user_id'];
            $user_role_type = $_POST['user_role_type'];
            
            // Common fields
            $email = trim($_POST['email'] ?? '');
            $password = !empty($_POST['password']) ? $_POST['password'] : null;
            $first_name = trim($_POST['first_name'] ?? '');
            $last_name = trim($_POST['last_name'] ?? '');
            $contact_number = !empty($_POST['contact_number']) ? trim($_POST['contact_number']) : null;
            $status = $_POST['status'] ?? 'active';
            $branch_id = intval($_POST['branch_id'] ?? 0);
            
            // Validate
            if (empty($email)) throw new Exception('Email is required');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new Exception('Invalid email format');
            if (empty($first_name)) throw new Exception('First Name is required');
            if (empty($last_name)) throw new Exception('Last Name is required');
            if (empty($branch_id)) throw new Exception('Branch is required');
            if ($password && strlen($password) < 6) throw new Exception('Password must be at least 6 characters');
            
            // Check if email is already used by another user
            $check_email = $conn->prepare("SELECT user_id FROM users WHERE email = ? AND user_id != ?");
            $check_email->bind_param("si", $email, $user_id);
            $check_email->execute();
            if ($check_email->get_result()->num_rows > 0) {
                throw new Exception('Email is already in use by another user');
            }
            
            // Update driver if applicable
            if ($user_role_type === 'driver') {
                $driver_id = (int)$_POST['driver_id'];
                $driver_name = trim($_POST['driver_name']);
                $license_number = trim($_POST['license_number']);
                $license_expiry = !empty($_POST['license_expiry']) ? $_POST['license_expiry'] : null;
                $vehicle_type = !empty($_POST['vehicle_type']) ? trim($_POST['vehicle_type']) : null;
                $vehicle_plate_number = !empty($_POST['vehicle_plate_number']) ? trim($_POST['vehicle_plate_number']) : null;
                $driver_status = $_POST['driver_status'] ?? $status;
                
                $update_driver = $conn->prepare("UPDATE drivers SET driver_name = ?, license_number = ?, license_expiry = ?, contact_number = ?, vehicle_type = ?, vehicle_plate_number = ?, status = ?, branch_id = ?, updated_at = NOW() WHERE driver_id = ?");
                $update_driver->bind_param("sssssssii", $driver_name, $license_number, $license_expiry, $contact_number, $vehicle_type, $vehicle_plate_number, $driver_status, $branch_id, $driver_id);
                
                if (!$update_driver->execute()) {
                    throw new Exception('Failed to update driver: ' . $update_driver->error);
                }
            }
            
            // Update user
            if ($password) {
                $password_hash = password_hash($password, PASSWORD_DEFAULT);
                
                if ($user_role_type === 'warehouse') {
                    $category = !empty($_POST['category']) ? trim($_POST['category']) : null;
                    $update_user = $conn->prepare("UPDATE users SET email = ?, first_name = ?, last_name = ?, contact_number = ?, category = ?, password_hash = ?, status = ?, branch_id = ?, updated_at = NOW() WHERE user_id = ?");
                    $update_user->bind_param("sssssssii", $email, $first_name, $last_name, $contact_number, $category, $password_hash, $status, $branch_id, $user_id);
                } else {
                    $update_user = $conn->prepare("UPDATE users SET email = ?, first_name = ?, last_name = ?, contact_number = ?, password_hash = ?, status = ?, branch_id = ?, updated_at = NOW() WHERE user_id = ?");
                    $update_user->bind_param("ssssssii", $email, $first_name, $last_name, $contact_number, $password_hash, $status, $branch_id, $user_id);
                }
            } else {
                if ($user_role_type === 'warehouse') {
                    $category = !empty($_POST['category']) ? trim($_POST['category']) : null;
                    $update_user = $conn->prepare("UPDATE users SET email = ?, first_name = ?, last_name = ?, contact_number = ?, category = ?, status = ?, branch_id = ?, updated_at = NOW() WHERE user_id = ?");
                    $update_user->bind_param("ssssssii", $email, $first_name, $last_name, $contact_number, $category, $status, $branch_id, $user_id);
                } else {
                    $update_user = $conn->prepare("UPDATE users SET email = ?, first_name = ?, last_name = ?, contact_number = ?, status = ?, branch_id = ?, updated_at = NOW() WHERE user_id = ?");
                    $update_user->bind_param("sssssii", $email, $first_name, $last_name, $contact_number, $status, $branch_id, $user_id);
                }
            }
            
            if (!$update_user->execute()) {
                throw new Exception('Failed to update user: ' . $update_user->error);
            }
            
            $conn->commit();
            $response = ['success' => true, 'message' => 'User updated successfully'];
        }
        
        // DELETE USER (Soft delete - set status to inactive)
        elseif ($_POST['action'] === 'delete_user') {
            $user_id = (int)$_POST['user_id'];
            
            // Get user details first to check if they have a driver_id
            $get_user = $conn->prepare("SELECT driver_id FROM users WHERE user_id = ?");
            $get_user->bind_param("i", $user_id);
            $get_user->execute();
            $user_data = $get_user->get_result()->fetch_assoc();
            $driver_id = $user_data['driver_id'] ?? null;
            
            // Update user status to inactive
            $update_user = $conn->prepare("UPDATE users SET status = 'inactive', updated_at = NOW() WHERE user_id = ?");
            $update_user->bind_param("i", $user_id);
            
            if (!$update_user->execute()) {
                throw new Exception('Failed to deactivate user: ' . $update_user->error);
            }
            
            // If driver, also update driver status
            if ($driver_id) {
                $update_driver = $conn->prepare("UPDATE drivers SET status = 'inactive', updated_at = NOW() WHERE driver_id = ?");
                $update_driver->bind_param("i", $driver_id);
                $update_driver->execute();
            }
            
            $conn->commit();
            $response = ['success' => true, 'message' => 'User deactivated successfully'];
        }
        
        // GET USER DETAILS
        elseif ($_POST['action'] === 'get_user') {
            $user_id = (int)$_POST['user_id'];
            
            $query = "SELECT u.*, d.driver_id, d.driver_name, d.license_number, d.license_expiry, d.vehicle_type, d.vehicle_plate_number, d.status as driver_status, b.branch_name 
                     FROM users u 
                     LEFT JOIN drivers d ON u.driver_id = d.driver_id 
                     LEFT JOIN branches b ON u.branch_id = b.branch_id
                     WHERE u.user_id = ?";
            
            $stmt = $conn->prepare($query);
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
            
            if ($user) {
                $response = ['success' => true, 'user' => $user];
            } else {
                throw new Exception('User not found');
            }
        }
        
        // Send success response
        http_response_code(200);
        $json_response = json_encode($response, JSON_UNESCAPED_SLASHES);
        if ($json_response === false) {
            $json_response = json_encode(['success' => false, 'message' => 'JSON encoding error']);
        }
        ob_end_clean();
        echo $json_response;
        exit;
        
    } catch (Exception $e) {
        $conn->rollback();
        // Log the error to a file
        error_log("User Management Error: " . $e->getMessage());
        error_log("POST Data: " . print_r($_POST, true));
        
        // Send error response
        http_response_code(400);
        $json_response = json_encode([
            'success' => false, 
            'message' => $e->getMessage()
        ], JSON_UNESCAPED_SLASHES);
        ob_end_clean();
        echo $json_response;
        exit;
    }
}

// FETCH ALL USERS - For Global view (all branches, both active and inactive)
$users_query = "
    SELECT 
        u.user_id,
        u.email,
        u.first_name,
        u.last_name,
        CONCAT(u.first_name, ' ', u.last_name) as full_name,
        u.role,
        u.branch_id,
        u.status as user_status,
        u.created_at,
        u.contact_number,
        u.category,
        d.driver_id,
        d.driver_name,
        d.license_number,
        d.license_expiry,
        d.vehicle_type,
        d.vehicle_plate_number,
        d.status as driver_status,
        b.branch_name,
        b.branch_code
    FROM users u
    LEFT JOIN drivers d ON u.driver_id = d.driver_id
    LEFT JOIN branches b ON u.branch_id = b.branch_id
    WHERE u.role IN ('delivery', 'warehouse', 'sales', 'branch_admin')
    ORDER BY 
        CASE 
            WHEN u.role = 'branch_admin' THEN 1
            WHEN u.role = 'delivery' THEN 2
            WHEN u.role = 'warehouse' THEN 3
            WHEN u.role = 'sales' THEN 4
        END,
        u.first_name ASC
";

$users_result = $conn->query($users_query);
if (!$users_result) {
    die("Query failed: " . $conn->error);
}
$users = $users_result->fetch_all(MYSQLI_ASSOC);

// Group users by role
$branch_admins = array_filter($users, function($u) { return $u['role'] === 'branch_admin'; });
$drivers = array_filter($users, function($u) { return $u['role'] === 'delivery'; });
$warehouse_staff = array_filter($users, function($u) { return $u['role'] === 'warehouse'; });
$sales_agents = array_filter($users, function($u) { return $u['role'] === 'sales'; });

// Statistics
$total_users = count($users);
$total_branch_admins = count($branch_admins);
$total_drivers = count($drivers);
$total_warehouse = count($warehouse_staff);
$total_sales = count($sales_agents);
$active_users = count(array_filter($users, function($u) { return $u['user_status'] === 'active'; }));

// Helper functions - EXACTLY the same as users.php
function getUserRoleBadge($role) {
    switch($role) {
        case 'branch_admin':
            return 'bg-danger';
        case 'delivery':
            return 'bg-primary';
        case 'warehouse':
            return 'bg-success';
        case 'sales':
            return 'bg-warning text-dark';
        default:
            return 'bg-secondary';
    }
}

function getUserRoleText($role) {
    switch($role) {
        case 'branch_admin':
            return 'Branch Admin';
        case 'delivery':
            return 'Driver';
        case 'warehouse':
            return 'Warehouse';
        case 'sales':
            return 'Sales Agent';
        default:
            return ucfirst($role);
    }
}

function getUserStatusClass($status) {
    return $status === 'active' ? 'bg-success' : 'bg-secondary';
}

function formatDate($dateStr) {
    if (!$dateStr || $dateStr == '0000-00-00') return '';
    $date = new DateTime($dateStr);
    return $date->format('M d, Y');
}

function formatPhoneNumber($phone) {
    if (empty($phone)) return 'N/A';
    $phone = preg_replace('/[^0-9]/', '', $phone);
    if (strlen($phone) == 11 && substr($phone, 0, 2) == '09') {
        return substr($phone, 0, 4) . '-' . substr($phone, 4, 3) . '-' . substr($phone, 7, 4);
    }
    return $phone;
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
    $user_initials = 'GA';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Global - User Management</title>
    <link rel="icon" type="image/png" href="../Pictures/favicon-96x96.png" sizes="96x96" />
    <link rel="icon" type="image/svg+xml" href="../Pictures/favicon.svg" />
    <link rel="shortcut icon" href="../Pictures/favicon.ico" />
    <link rel="apple-touch-icon" sizes="180x180" href="../Pictures/apple-touch-icon.png" />
    <link rel="manifest" href="../Pictures/site.webmanifest" />
    <link rel="stylesheet" href="../css/current_inventory.css">
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <!-- SheetJS for Excel Export -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <!-- SweetAlert2 -->
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        /* Branch badge styling - EXACTLY the same as users.php */
        .branch-badge {
            background-color: #e7f1ff;
            color: #0d6efd;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
            margin-left: 5px;
        }
        
        /* Table styles - EXACTLY the same as users.php */
        .user-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
            table-layout: fixed;
        }
        
        .user-table thead th {
            background-color: #f8f9fa;
            font-weight: 600;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #495057;
            padding: 14px 8px;
            border-bottom: 2px solid #dee2e6;
            white-space: nowrap;
            vertical-align: middle;
            text-align: center;
        }
        
        .user-table tbody td {
            padding: 12px 8px;
            vertical-align: middle;
            border-bottom: 1px solid #e9ecef;
            font-size: 13px;
            text-align: center;
            word-wrap: break-word;
        }
        
        .user-table tbody td:first-child {
            text-align: left;
        }
        
        .user-table tbody tr:hover {
            background-color: #f8f9fa;
        }
        
        /* Role-specific row styling */
        .role-branch_admin {
            border-left: 4px solid #dc3545;
        }
        
        .role-delivery {
            border-left: 4px solid #0d6efd;
        }
        
        .role-warehouse {
            border-left: 4px solid #198754;
        }
        
        .role-sales {
            border-left: 4px solid #ffc107;
        }
        
        /* Column widths */
        .col-name { width: 18%; }
        .col-role { width: 10%; }
        .col-details { width: 22%; }
        .col-status { width: 10%; }
        .col-branch { width: 12%; }
        .col-actions { width: 18%; }
        
        .status-badge {
            display: inline-block;
            padding: 4px 8px;
            font-size: 11px;
            font-weight: 500;
            border-radius: 20px;
            text-align: center;
            min-width: 70px;
        }
        
        .status-active { background-color: #d4edda; color: #155724; }
        .status-inactive { background-color: #f8d7da; color: #721c24; }
        
        .role-badge {
            display: inline-block;
            padding: 4px 8px;
            font-size: 11px;
            font-weight: 600;
            border-radius: 20px;
            text-align: center;
            min-width: 90px;
        }
        
        .role-badge i {
            margin-right: 4px;
        }
        
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
            margin-bottom: 20px;
        }
        
        /* Filter section - EXACTLY the same as users.php */
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
        
        .action-buttons {
            display: flex;
            gap: 5px;
            justify-content: center;
            align-items: center;
            flex-wrap: nowrap;
        }
        
        .table-btn {
            background: none;
            border: none;
            padding: 6px 8px;
            border-radius: 4px;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 12px;
            white-space: nowrap;
        }
        
        .table-btn i {
            margin-right: 3px;
        }
        
        .table-btn:hover {
            background-color: #e9ecef;
        }
        
        .btn-view { 
            color: #0d6efd;
            border: 1px solid #0d6efd;
        }
        .btn-edit { 
            color: #198754;
            border: 1px solid #198754;
        }
        .btn-delete { 
            color: #dc3545;
            border: 1px solid #dc3545;
        }
        
        .btn-view:hover { 
            background-color: #0d6efd;
            color: white;
        }
        .btn-edit:hover { 
            background-color: #198754;
            color: white;
        }
        .btn-delete:hover { 
            background-color: #dc3545;
            color: white;
        }
        
        /* Add User Buttons - EXACTLY the same as users.php */
        .add-user-buttons {
            display: flex;
            gap: 8px;
        }
        
        .btn-add-branch-admin {
            background-color: #dc3545;
            color: white;
            font-size: 13px;
            padding: 8px 12px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
        }
        
        .btn-add-driver {
            background-color: #0d6efd;
            color: white;
            font-size: 13px;
            padding: 8px 12px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
        }
        
        .btn-add-warehouse {
            background-color: #198754;
            color: white;
            font-size: 13px;
            padding: 8px 12px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
        }
        
        .btn-add-sales {
            background-color: #ffc107;
            color: #212529;
            font-size: 13px;
            padding: 8px 12px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
        }
        
        /* Modal styling - EXACTLY the same as users.php */
        .detail-label {
            font-size: 12px;
            color: #6c757d;
            margin-bottom: 4px;
        }
        
        .detail-value {
            font-size: 14px;
            font-weight: 600;
            color: #212529;
        }
        
        .password-note {
            background-color: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 10px;
            font-size: 12px;
            margin-top: 5px;
        }
        
        .form-section {
            background-color: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
        }
        
        .form-section-title {
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 15px;
            color: #0d6efd;
            border-bottom: 2px solid #0d6efd;
            padding-bottom: 5px;
        }
        
        /* Details text alignment */
        .details-text {
            font-size: 12px;
            line-height: 1.4;
            text-align: left;
            display: inline-block;
        }
        
        .details-text i {
            width: 16px;
            margin-right: 4px;
        }
        
        /* Responsive adjustments */
        @media (max-width: 1200px) {
            .add-user-buttons {
                flex-wrap: wrap;
            }
            
            .action-buttons {
                flex-wrap: wrap;
            }
        }
    </style>
</head>
<body>
    <div id="appPage">
        <!-- Sidebar - Global version -->
        <div class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <h3>
                    <button class="desktop-toggle-btn" id="desktopToggleBtn">
                        <i class="bi bi-list" id="toggleIcon"></i>
                    </button>
                    <img src="../Pictures/amgc3DLogo.png" alt="Logo" class="logo-icon"> 
                    <span class="nav-text">Global</span>
                </h3>
            </div>
            
            <div class="sidebar-menu">
                <ul class="nav flex-column">
                    <li class="nav-item">
                        <a class="nav-link" href="sales_reports.php">
                            <i class="bi bi-graph-up"></i>
                            <span class="nav-text">Sales Reports</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="branch_records.php">
                            <i class="bi bi-file-text"></i>
                            <span class="nav-text">Branch Records</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="all_items.php">
                            <i class="bi bi-box"></i>
                            <span class="nav-text">All Items</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="drivers.php">
                            <i class="bi bi-people"></i>
                            <span class="nav-text">User Management</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="trip_tickets.php">
                            <i class="bi bi-ticket-perforated"></i>
                            <span class="nav-text">Trip Tickets</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="driver_tracking.php">
                            <i class="bi bi-geo-alt"></i>
                            <span class="nav-text">Driver Tracking</span>
                        </a>
                    </li>
                    <hr class="sidebar-divider">
                </ul>
            </div>
            
            <!-- User Profile Section -->
            <div class="sidebar-footer">
                <div class="user-profile-sidebar">
                    <div class="user-avatar-sidebar"><?php echo $user_initials; ?></div>
                    <div class="user-details-sidebar">
                        <span class="user-name-sidebar"><?php echo htmlspecialchars($user_name); ?></span>
                        <span class="user-role-sidebar">Global Admin</span>
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
                <!-- Navbar Top -->
                <div class="navbar-top">
                    <button class="mobile-menu-btn" id="mobileMenuBtn">
                        <i class="bi bi-list"></i>
                    </button>
                    <div class="page-title">
                        <h2><i class="bi bi-people me-2"></i>User Management</h2>
                        <p id="dashboardSubtitle">
                            Manage all users across all branches - Branch Admins, Drivers, Warehouse Staff, and Sales Agents
                        </p>
                    </div>
                </div>

                <!-- Stats Cards - Updated to include Branch Admins -->
                <div class="row g-3 mb-4">
                    <div class="col-md-3 mb-3">
                        <div class="stat-card" style="background: linear-gradient(135deg, #dc3545, #bb2d3b);">
                            <i class="bi bi-person-badge stat-icon"></i>
                            <div class="stat-value"><?= $total_branch_admins ?></div>
                            <div class="stat-label">Branch Admins</div>
                        </div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <div class="stat-card" style="background: linear-gradient(135deg, #0d6efd, #0b5ed7);">
                            <i class="bi bi-truck stat-icon"></i>
                            <div class="stat-value"><?= $total_drivers ?></div>
                            <div class="stat-label">Drivers</div>
                        </div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <div class="stat-card" style="background: linear-gradient(135deg, #198754, #157347);">
                            <i class="bi bi-building stat-icon"></i>
                            <div class="stat-value"><?= $total_warehouse ?></div>
                            <div class="stat-label">Warehouse</div>
                        </div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <div class="stat-card" style="background: linear-gradient(135deg, #ffc107, #ffb300);">
                            <i class="bi bi-graph-up stat-icon"></i>
                            <div class="stat-value"><?= $total_sales ?></div>
                            <div class="stat-label">Sales Agents</div>
                        </div>
                    </div>
                </div>

                <!-- FILTER SECTION AND ADD BUTTONS - REMOVED USER TYPE FILTER, ONLY SEARCH AND BRANCH -->
                <div class="filter-section">
                    <div class="filter-controls">
                        <div class="filter-dropdowns">
                            <!-- Search Box -->
                            <div class="search-box">
                                <i class="bi bi-search"></i>
                                <input type="text" id="searchInput" placeholder="Search by name, email, role..." onkeyup="filterUsers()">
                            </div>

                            <!-- Branch Filter (for Global) -->
                            <div class="filter-dropdown">
                                <select class="form-select" id="branchFilter" onchange="filterUsers()">
                                    <option value="all">All Branches</option>
                                    <?php foreach ($branches as $branch): ?>
                                        <option value="<?php echo $branch['branch_id']; ?>">
                                            <?php echo htmlspecialchars($branch['branch_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="filter-actions">
                        <button class="btn btn-outline-success me-2" onclick="exportToExcel()">
                            <i class="bi bi-file-earmark-excel me-1"></i> Export
                        </button>
                        
                        <div class="add-user-buttons">
                            <button class="btn-add-branch-admin" onclick="showAddBranchAdminModal()">
                                <i class="bi bi-person-badge me-1"></i> Add Admin
                            </button>
                            <button class="btn-add-driver" onclick="showAddDriverModal()">
                                <i class="bi bi-truck me-1"></i> Add Driver
                            </button>
                            <button class="btn-add-warehouse" onclick="showAddWarehouseModal()">
                                <i class="bi bi-building me-1"></i> Add Warehouse
                            </button>
                            <button class="btn-add-sales" onclick="showAddSalesModal()">
                                <i class="bi bi-graph-up me-1"></i> Add Sales
                            </button>
                        </div>
                    </div>
                </div>

                <!-- USERS TABLE - Fixed role badge HTML -->
                <div class="table-responsive">
                    <table class="table user-table" id="usersTable">
                        <thead>
                            <tr>
                                <th class="col-name">NAME</th>
                                <th class="col-role">ROLE</th>
                                <th class="col-details">DETAILS</th>
                                <th class="col-status">STATUS</th>
                                <th class="col-branch">BRANCH</th>
                                <th class="col-actions">ACTIONS</th>
                            </tr>
                        </thead>
                        <tbody id="usersTableBody">
                            <?php if (empty($users)): ?>
                            <tr>
                                <td colspan="6" class="empty-state-table">
                                    <i class="bi bi-people"></i>
                                    <h5>No Users Found</h5>
                                    <p class="text-muted">Click one of the "Add" buttons above to create users.</p>
                                </td>
                            </tr>
                            <?php else: ?>
                                <?php foreach ($users as $user): ?>
                                <tr class="user-row role-<?= $user['role'] ?>" 
                                    data-id="<?= $user['user_id'] ?>"
                                    data-role="<?= $user['role'] ?>"
                                    data-status="<?= $user['user_status'] ?>"
                                    data-branch="<?= $user['branch_id'] ?>">
                                    <td class="col-name text-start">
                                        <strong><?= htmlspecialchars($user['full_name']) ?></strong>
                                        <br>
                                        <small class="text-muted"><?= htmlspecialchars($user['email']) ?></small>
                                    </td>
                                    <td class="col-role">
                                        
                                            <i class="bi <?= $user['role'] === 'branch_admin' ?  : ($user['role'] === 'delivery' ?  : ($user['role'] === 'warehouse' ?  : '')) ?>"></i>
                                            <?= getUserRoleText($user['role']) ?>
                                        </span>
                                    </td>
                                    <td class="col-details">
                                        <div class="details-text">
                                        <?php if ($user['role'] === 'branch_admin'): ?>
                                            <i class="bi bi-briefcase"></i> Position: Branch Admin<br>
                                            <i class="bi bi-telephone"></i> Contact: <?= formatPhoneNumber($user['contact_number']) ?>
                                        <?php elseif ($user['role'] === 'delivery'): ?>
                                            <i class="bi bi-card-text"></i> License: <?= htmlspecialchars($user['license_number'] ?? 'N/A') ?><br>
                                            <i class="bi bi-calendar"></i> Exp: <?= formatDate($user['license_expiry']) ?><br>
                                            <i class="bi bi-truck"></i> <?= htmlspecialchars($user['vehicle_type'] ?? 'N/A') ?> - <?= htmlspecialchars($user['vehicle_plate_number'] ?? 'N/A') ?>
                                        <?php elseif ($user['role'] === 'warehouse'): ?>
                                            <i class="bi bi-briefcase"></i> Category: <?= htmlspecialchars($user['category'] ?? 'General') ?><br>
                                            <i class="bi bi-telephone"></i> Contact: <?= formatPhoneNumber($user['contact_number']) ?>
                                        <?php elseif ($user['role'] === 'sales'): ?>
                                            <i class="bi bi-briefcase"></i> Position: Sales Agent<br>
                                            <i class="bi bi-telephone"></i> Contact: <?= formatPhoneNumber($user['contact_number']) ?>
                                        <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="col-status">
                                        <span class="status-badge <?= getUserStatusClass($user['user_status']) ?>">
                                            <?= ucfirst($user['user_status']) ?>
                                        </span>
                                    </td>
                                    <td class="col-branch">
                                        <span class="branch-badge">
                                            <i class="bi bi-building"></i> <?= htmlspecialchars($user['branch_name'] ?? 'Branch '.$user['branch_id']) ?>
                                        </span>
                                    </td>
                                    <td class="col-actions">
                                        <div class="action-buttons">
                                            <button class="table-btn btn-view" onclick="viewUser(<?= $user['user_id'] ?>)" title="View">
                                                <i class="bi bi-eye"></i> View
                                            </button>
                                            <button class="table-btn btn-edit" onclick="editUser(<?= $user['user_id'] ?>)" title="Edit">
                                                <i class="bi bi-pencil"></i> Edit
                                            </button>
                                            <button class="table-btn btn-delete" onclick="deleteUser(<?= $user['user_id'] ?>, '<?= $user['role'] ?>', <?= $user['driver_id'] ?? 'null' ?>)" title="Delete">
                                                <i class="bi bi-trash"></i> Delete
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

    <!-- ADD/EDIT BRANCH ADMIN MODAL -->
    <div class="modal fade" id="branchAdminModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header" style="background-color: #dc3545; color: white;">
                    <h5 class="modal-title"><i class="bi bi-person-badge me-2"></i><span id="branchAdminModalTitle">Add Branch Admin</span></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="branchAdminForm" onsubmit="return false;">
                        <input type="hidden" id="branchAdminId" name="user_id">
                        
                        <div class="form-section">
                            <div class="form-section-title">
                                <i class="bi bi-person-workspace me-2"></i>Admin Information
                            </div>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label for="branchAdminFirstName" class="form-label">First Name *</label>
                                    <input type="text" class="form-control" id="branchAdminFirstName" name="first_name" required>
                                </div>
                                <div class="col-md-6">
                                    <label for="branchAdminLastName" class="form-label">Last Name *</label>
                                    <input type="text" class="form-control" id="branchAdminLastName" name="last_name" required>
                                </div>
                                <div class="col-md-6">
                                    <label for="branchAdminEmail" class="form-label">Email Address *</label>
                                    <input type="email" class="form-control" id="branchAdminEmail" name="email" required>
                                </div>
                                <div class="col-md-6">
                                    <label for="branchAdminPassword" class="form-label">Password</label>
                                    <input type="password" class="form-control" id="branchAdminPassword" name="password" minlength="6">
                                    <div class="password-note" id="branchAdminPasswordNote">
                                        <i class="bi bi-info-circle"></i> Required for new users. Leave blank to keep current password when editing.
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <label for="branchAdminContact" class="form-label">Contact Number</label>
                                    <input type="text" class="form-control" id="branchAdminContact" name="contact_number" placeholder="09-1234-5678">
                                </div>
                                <div class="col-md-6">
                                    <label for="branchAdminBranchId" class="form-label">Branch *</label>
                                    <select class="form-select" id="branchAdminBranchId" name="branch_id" required>
                                        <option value="">Select Branch</option>
                                        <?php foreach ($branches as $branch): ?>
                                            <option value="<?php echo $branch['branch_id']; ?>">
                                                <?php echo htmlspecialchars($branch['branch_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label for="branchAdminStatus" class="form-label">Status</label>
                                    <select class="form-select" id="branchAdminStatus" name="status">
                                        <option value="active">Active</option>
                                        <option value="inactive">Inactive</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-danger" onclick="saveBranchAdmin()">Save Branch Admin</button>
                </div>
            </div>
        </div>
    </div>

    <!-- ADD/EDIT DRIVER MODAL - EXACTLY the same as users.php but with Branch dropdown -->
    <div class="modal fade" id="driverModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header" style="background-color: #0d6efd; color: white;">
                    <h5 class="modal-title"><i class="bi bi-truck me-2"></i><span id="driverModalTitle">Add New Driver</span></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="driverForm" onsubmit="return false;">
                        <input type="hidden" id="driverId" name="driver_id">
                        <input type="hidden" id="driverUserId" name="user_id">
                        
                        <!-- Driver Information Section -->
                        <div class="form-section">
                            <div class="form-section-title">
                                <i class="bi bi-truck me-2"></i>Driver Information
                            </div>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label for="driverName" class="form-label">Driver Name *</label>
                                    <input type="text" class="form-control" id="driverName" name="driver_name" required>
                                </div>
                                <div class="col-md-6">
                                    <label for="licenseNumber" class="form-label">License Number *</label>
                                    <input type="text" class="form-control" id="licenseNumber" name="license_number" required>
                                </div>
                                <div class="col-md-6">
                                    <label for="licenseExpiry" class="form-label">License Expiry</label>
                                    <input type="date" class="form-control" id="licenseExpiry" name="license_expiry">
                                </div>
                                <div class="col-md-6">
                                    <label for="contactNumber" class="form-label">Contact Number</label>
                                    <input type="text" class="form-control" id="contactNumber" name="contact_number" placeholder="09-1234-5678">
                                </div>
                                <div class="col-md-6">
                                    <label for="vehicleType" class="form-label">Vehicle Type</label>
                                    <select class="form-select" id="vehicleType" name="vehicle_type">
                                        <option value="">Select Vehicle Type</option>
                                        <option value="Van">Van</option>
                                        <option value="Truck">Truck</option>
                                        <option value="Motorcycle">Motorcycle</option>
                                        <option value="Car">Car</option>
                                        <option value="Pickup">Pickup</option>
                                        <option value="Other">Other</option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label for="vehiclePlate" class="form-label">Plate Number</label>
                                    <input type="text" class="form-control" id="vehiclePlate" name="vehicle_plate_number" placeholder="ABC-1234">
                                </div>
                                <div class="col-md-6">
                                    <label for="driverBranchId" class="form-label">Branch *</label>
                                    <select class="form-select" id="driverBranchId" name="branch_id" required>
                                        <option value="">Select Branch</option>
                                        <?php foreach ($branches as $branch): ?>
                                            <option value="<?php echo $branch['branch_id']; ?>">
                                                <?php echo htmlspecialchars($branch['branch_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label for="driverStatus" class="form-label">Status</label>
                                    <select class="form-select" id="driverStatus" name="status">
                                        <option value="active">Active</option>
                                        <option value="inactive">Inactive</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <!-- User Account Section -->
                        <div class="form-section">
                            <div class="form-section-title">
                                <i class="bi bi-person-circle me-2"></i>User Account
                            </div>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label for="driverFirstName" class="form-label">First Name *</label>
                                    <input type="text" class="form-control" id="driverFirstName" name="first_name" required>
                                </div>
                                <div class="col-md-6">
                                    <label for="driverLastName" class="form-label">Last Name *</label>
                                    <input type="text" class="form-control" id="driverLastName" name="last_name" required>
                                </div>
                                <div class="col-md-6">
                                    <label for="driverEmail" class="form-label">Email Address *</label>
                                    <input type="email" class="form-control" id="driverEmail" name="email" required>
                                </div>
                                <div class="col-md-6">
                                    <label for="driverPassword" class="form-label">Password</label>
                                    <input type="password" class="form-control" id="driverPassword" name="password" minlength="6">
                                    <div class="password-note" id="driverPasswordNote">
                                        <i class="bi bi-info-circle"></i> Required for new users. Leave blank to keep current password when editing.
                                    </div>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="saveDriver()">Save Driver</button>
                </div>
            </div>
        </div>
    </div>

    <!-- ADD/EDIT WAREHOUSE MODAL - EXACTLY the same as users.php but with Branch dropdown -->
    <div class="modal fade" id="warehouseModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header" style="background-color: #198754; color: white;">
                    <h5 class="modal-title"><i class="bi bi-building me-2"></i><span id="warehouseModalTitle">Add Warehouse Staff</span></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="warehouseForm" onsubmit="return false;">
                        <input type="hidden" id="warehouseId" name="user_id">
                        
                        <div class="form-section">
                            <div class="form-section-title">
                                <i class="bi bi-person-badge me-2"></i>Staff Information
                            </div>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label for="warehouseFirstName" class="form-label">First Name *</label>
                                    <input type="text" class="form-control" id="warehouseFirstName" name="first_name" required>
                                </div>
                                <div class="col-md-6">
                                    <label for="warehouseLastName" class="form-label">Last Name *</label>
                                    <input type="text" class="form-control" id="warehouseLastName" name="last_name" required>
                                </div>
                                <div class="col-md-6">
                                    <label for="warehouseEmail" class="form-label">Email Address *</label>
                                    <input type="email" class="form-control" id="warehouseEmail" name="email" required>
                                </div>
                                <div class="col-md-6">
                                    <label for="warehousePassword" class="form-label">Password</label>
                                    <input type="password" class="form-control" id="warehousePassword" name="password" minlength="6">
                                    <div class="password-note" id="warehousePasswordNote">
                                        <i class="bi bi-info-circle"></i> Required for new users. Leave blank to keep current password when editing.
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <label for="warehouseCategory" class="form-label">Category</label>
                                    <select class="form-select" id="warehouseCategory" name="category">
                                        <option value="">Select Category</option>
                                        <option value="Oil">Oil</option>
                                        <option value="Cement">Cement</option>
                                        <option value="General">General</option>
                                        <option value="Lubricants">Lubricants</option>
                                        <option value="Chemicals">Chemicals</option>
                                        <option value="Tools">Tools</option>
                                        <option value="Equipment">Equipment</option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label for="warehouseContact" class="form-label">Contact Number</label>
                                    <input type="text" class="form-control" id="warehouseContact" name="contact_number" placeholder="09-1234-5678">
                                </div>
                                <div class="col-md-6">
                                    <label for="warehouseBranchId" class="form-label">Branch *</label>
                                    <select class="form-select" id="warehouseBranchId" name="branch_id" required>
                                        <option value="">Select Branch</option>
                                        <?php foreach ($branches as $branch): ?>
                                            <option value="<?php echo $branch['branch_id']; ?>">
                                                <?php echo htmlspecialchars($branch['branch_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label for="warehouseStatus" class="form-label">Status</label>
                                    <select class="form-select" id="warehouseStatus" name="status">
                                        <option value="active">Active</option>
                                        <option value="inactive">Inactive</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-success" onclick="saveWarehouse()">Save Warehouse Staff</button>
                </div>
            </div>
        </div>
    </div>

    <!-- ADD/EDIT SALES MODAL - EXACTLY the same as users.php but with Branch dropdown -->
    <div class="modal fade" id="salesModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header" style="background-color: #ffc107; color: #212529;">
                    <h5 class="modal-title"><i class="bi bi-graph-up me-2"></i><span id="salesModalTitle">Add Sales Agent</span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="salesForm" onsubmit="return false;">
                        <input type="hidden" id="salesId" name="user_id">
                        
                        <div class="form-section">
                            <div class="form-section-title">
                                <i class="bi bi-person-workspace me-2"></i>Agent Information
                            </div>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label for="salesFirstName" class="form-label">First Name *</label>
                                    <input type="text" class="form-control" id="salesFirstName" name="first_name" required>
                                </div>
                                <div class="col-md-6">
                                    <label for="salesLastName" class="form-label">Last Name *</label>
                                    <input type="text" class="form-control" id="salesLastName" name="last_name" required>
                                </div>
                                <div class="col-md-6">
                                    <label for="salesEmail" class="form-label">Email Address *</label>
                                    <input type="email" class="form-control" id="salesEmail" name="email" required>
                                </div>
                                <div class="col-md-6">
                                    <label for="salesPassword" class="form-label">Password</label>
                                    <input type="password" class="form-control" id="salesPassword" name="password" minlength="6">
                                    <div class="password-note" id="salesPasswordNote">
                                        <i class="bi bi-info-circle"></i> Required for new users. Leave blank to keep current password when editing.
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <label for="salesContact" class="form-label">Contact Number</label>
                                    <input type="text" class="form-control" id="salesContact" name="contact_number" placeholder="09-1234-5678">
                                </div>
                                <div class="col-md-6">
                                    <label for="salesBranchId" class="form-label">Branch *</label>
                                    <select class="form-select" id="salesBranchId" name="branch_id" required>
                                        <option value="">Select Branch</option>
                                        <?php foreach ($branches as $branch): ?>
                                            <option value="<?php echo $branch['branch_id']; ?>">
                                                <?php echo htmlspecialchars($branch['branch_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label for="salesStatus" class="form-label">Status</label>
                                    <select class="form-select" id="salesStatus" name="status">
                                        <option value="active">Active</option>
                                        <option value="inactive">Inactive</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn" style="background-color: #ffc107; color: #212529;" onclick="saveSales()">Save Sales Agent</button>
                </div>
            </div>
        </div>
    </div>

    <!-- VIEW USER MODAL - EXACTLY the same as users.php -->
    <div class="modal fade" id="viewUserModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title"><i class="bi bi-eye me-2"></i>User Details</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row" id="viewUserContent"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-warning" onclick="editFromView()">Edit User</button>
                </div>
            </div>
        </div>
    </div>

    <!-- DELETE CONFIRMATION MODAL - EXACTLY the same as users.php -->
    <div class="modal fade" id="deleteUserModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title"><i class="bi bi-trash me-2"></i>Confirm Deactivate</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to deactivate this user?</p>
                    <p class="fw-bold" id="deleteUserName"></p>
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        This action will deactivate the user. They will no longer be able to log in.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-danger" onclick="confirmDeleteUser()">Deactivate User</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <style>
        /* Add custom column width for 5 stats cards */
        .col-md-2-4 {
            flex: 0 0 auto;
            width: 20%;
        }
        @media (max-width: 768px) {
            .col-md-2-4 {
                width: 50%;
            }
        }
    </style>

    <script>
    // ========== GLOBAL VARIABLES ==========
    let currentUserId = null;
    let currentUserRole = null;
    let currentDriverId = null;
    const viewAllBranches = true; // Always true for Global
    
    // ========== SIDEBAR FUNCTIONS (EXACTLY the same as users.php) ==========
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

    // ========== DOM READY ==========
    document.addEventListener('DOMContentLoaded', function() {
        initializeSidebar();
        
        // Mobile menu toggle
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

        // Fix modal backdrop issue
        const modals = ['branchAdminModal', 'driverModal', 'warehouseModal', 'salesModal', 'viewUserModal', 'deleteUserModal'];
        modals.forEach(modalId => {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.addEventListener('hidden.bs.modal', function () {
                    const backdrop = document.querySelector('.modal-backdrop');
                    if (backdrop) backdrop.remove();
                    document.body.classList.remove('modal-open');
                    document.body.style.removeProperty('padding-right');
                    document.body.style.removeProperty('overflow');
                });
            }
        });

        // Set default license expiry
        const today = new Date();
        const oneYearFromNow = new Date(today);
        oneYearFromNow.setFullYear(today.getFullYear() + 1);
        if (document.getElementById('licenseExpiry')) {
            document.getElementById('licenseExpiry').value = oneYearFromNow.toISOString().split('T')[0];
        }
    });

    // ========== FILTER FUNCTIONS (UPDATED - ONLY SEARCH AND BRANCH) ==========
    function filterUsers() {
        const branch = document.getElementById('branchFilter').value;
        const search = document.getElementById('searchInput').value.toLowerCase();
        
        document.querySelectorAll('.user-row').forEach(row => {
            let show = true;
            
            // Filter by branch
            if (branch !== 'all' && row.dataset.branch !== branch) {
                show = false;
            }
            
            // Filter by search text
            if (show && search) {
                const text = row.innerText.toLowerCase();
                show = text.includes(search);
            }
            
            row.style.display = show ? '' : 'none';
        });
    }

    // ========== MODAL FUNCTIONS ==========
    function showAddBranchAdminModal() {
        document.getElementById('branchAdminForm').reset();
        document.getElementById('branchAdminId').value = '';
        document.getElementById('branchAdminModalTitle').textContent = 'Add Branch Admin';
        document.getElementById('branchAdminPassword').required = true;
        document.getElementById('branchAdminPasswordNote').innerHTML = '<i class="bi bi-info-circle"></i> Required for new users.';
        
        new bootstrap.Modal(document.getElementById('branchAdminModal')).show();
    }

    function showAddDriverModal() {
        document.getElementById('driverForm').reset();
        document.getElementById('driverId').value = '';
        document.getElementById('driverUserId').value = '';
        document.getElementById('driverModalTitle').textContent = 'Add New Driver';
        document.getElementById('driverPassword').required = true;
        document.getElementById('driverPasswordNote').innerHTML = '<i class="bi bi-info-circle"></i> Required for new users.';
        
        const today = new Date();
        const oneYearFromNow = new Date(today);
        oneYearFromNow.setFullYear(today.getFullYear() + 1);
        document.getElementById('licenseExpiry').value = oneYearFromNow.toISOString().split('T')[0];
        
        new bootstrap.Modal(document.getElementById('driverModal')).show();
    }

    function showAddWarehouseModal() {
        document.getElementById('warehouseForm').reset();
        document.getElementById('warehouseId').value = '';
        document.getElementById('warehouseModalTitle').textContent = 'Add Warehouse Staff';
        document.getElementById('warehousePassword').required = true;
        document.getElementById('warehousePasswordNote').innerHTML = '<i class="bi bi-info-circle"></i> Required for new users.';
        
        new bootstrap.Modal(document.getElementById('warehouseModal')).show();
    }

    function showAddSalesModal() {
        document.getElementById('salesForm').reset();
        document.getElementById('salesId').value = '';
        document.getElementById('salesModalTitle').textContent = 'Add Sales Agent';
        document.getElementById('salesPassword').required = true;
        document.getElementById('salesPasswordNote').innerHTML = '<i class="bi bi-info-circle"></i> Required for new users.';
        
        new bootstrap.Modal(document.getElementById('salesModal')).show();
    }

    function viewUser(id) {
        showLoading();
        
        const formData = new FormData();
        formData.append('action', 'get_user');
        formData.append('user_id', id);
        
        fetch('drivers.php', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
            Swal.close();
            
            if (data.success) {
                const u = data.user;
                currentUserId = u.user_id;
                currentUserRole = u.role;
                
                const created = u.created_at ? new Date(u.created_at).toLocaleString() : 'N/A';
                let html = '';
                
                if (u.role === 'branch_admin') {
                    html = `
                        <div class="col-md-12">
                            <div class="form-section">
                                <h6 class="fw-bold mb-3">Branch Admin Information</h6>
                                <table class="table table-sm">
                                    <tr><td>Name:</td><td class="fw-bold">${u.first_name} ${u.last_name}</td></tr>
                                    <tr><td>Email:</td><td>${u.email}</td></tr>
                                    <tr><td>Contact:</td><td>${u.contact_number || 'Not provided'}</td></tr>
                                    <tr><td>Branch:</td><td>${u.branch_name || 'Branch ' + u.branch_id}</td></tr>
                                    <tr><td>Status:</td><td><span class="badge ${u.status === 'active' ? 'bg-success' : 'bg-secondary'}">${u.status}</span></td></tr>
                                    <tr><td>Created:</td><td>${created}</td></tr>
                                </table>
                            </div>
                        </div>
                    `;
                } else if (u.role === 'delivery') {
                    html = `
                        <div class="col-md-6">
                            <div class="form-section">
                                <h6 class="fw-bold mb-3">Driver Information</h6>
                                <table class="table table-sm">
                                    <tr><td>Driver Name:</td><td class="fw-bold">${u.driver_name || u.full_name}</td></tr>
                                    <tr><td>License:</td><td>${u.license_number || 'N/A'}</td></tr>
                                    <tr><td>Expiry:</td><td>${u.license_expiry ? new Date(u.license_expiry).toLocaleDateString() : 'Not set'}</td></tr>
                                    <tr><td>Vehicle:</td><td>${u.vehicle_type || 'Not specified'}</td></tr>
                                    <tr><td>Plate:</td><td>${u.vehicle_plate_number || 'Not specified'}</td></tr>
                                </table>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-section">
                                <h6 class="fw-bold mb-3">User Account</h6>
                                <table class="table table-sm">
                                    <tr><td>Name:</td><td>${u.first_name} ${u.last_name}</td></tr>
                                    <tr><td>Email:</td><td>${u.email}</td></tr>
                                    <tr><td>Contact:</td><td>${u.contact_number || 'Not provided'}</td></tr>
                                    <tr><td>Branch:</td><td>${u.branch_name || 'Branch ' + u.branch_id}</td></tr>
                                    <tr><td>Status:</td><td><span class="badge ${u.status === 'active' ? 'bg-success' : 'bg-secondary'}">${u.status}</span></td></tr>
                                    <tr><td>Created:</td><td>${created}</td></tr>
                                </table>
                            </div>
                        </div>
                    `;
                } else if (u.role === 'warehouse') {
                    html = `
                        <div class="col-md-12">
                            <div class="form-section">
                                <h6 class="fw-bold mb-3">Warehouse Staff Information</h6>
                                <table class="table table-sm">
                                    <tr><td>Name:</td><td class="fw-bold">${u.first_name} ${u.last_name}</td></tr>
                                    <tr><td>Email:</td><td>${u.email}</td></tr>
                                    <tr><td>Category:</td><td>${u.category || 'General'}</td></tr>
                                    <tr><td>Contact:</td><td>${u.contact_number || 'Not provided'}</td></tr>
                                    <tr><td>Branch:</td><td>${u.branch_name || 'Branch ' + u.branch_id}</td></tr>
                                    <tr><td>Status:</td><td><span class="badge ${u.status === 'active' ? 'bg-success' : 'bg-secondary'}">${u.status}</span></td></tr>
                                    <tr><td>Created:</td><td>${created}</td></tr>
                                </table>
                            </div>
                        </div>
                    `;
                } else if (u.role === 'sales') {
                    html = `
                        <div class="col-md-12">
                            <div class="form-section">
                                <h6 class="fw-bold mb-3">Sales Agent Information</h6>
                                <table class="table table-sm">
                                    <tr><td>Name:</td><td class="fw-bold">${u.first_name} ${u.last_name}</td></tr>
                                    <tr><td>Email:</td><td>${u.email}</td></tr>
                                    <tr><td>Contact:</td><td>${u.contact_number || 'Not provided'}</td></tr>
                                    <tr><td>Branch:</td><td>${u.branch_name || 'Branch ' + u.branch_id}</td></tr>
                                    <tr><td>Status:</td><td><span class="badge ${u.status === 'active' ? 'bg-success' : 'bg-secondary'}">${u.status}</span></td></tr>
                                    <tr><td>Created:</td><td>${created}</td></tr>
                                </table>
                            </div>
                        </div>
                    `;
                }
                
                document.getElementById('viewUserContent').innerHTML = html;
                new bootstrap.Modal(document.getElementById('viewUserModal')).show();
            } else {
                Swal.fire('Error', data.message, 'error');
            }
        })
        .catch(error => {
            Swal.close();
            console.error('Error:', error);
            Swal.fire('Error', 'An error occurred: ' + error.message, 'error');
        });
    }

    function editFromView() {
        bootstrap.Modal.getInstance(document.getElementById('viewUserModal')).hide();
        setTimeout(() => editUser(currentUserId), 300);
    }

    function editUser(id) {
        showLoading();
        
        const formData = new FormData();
        formData.append('action', 'get_user');
        formData.append('user_id', id);
        
        fetch('drivers.php', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
            Swal.close();
            
            if (data.success) {
                const u = data.user;
                currentUserId = u.user_id;
                currentUserRole = u.role;
                currentDriverId = u.driver_id;
                
                if (u.role === 'branch_admin') {
                    document.getElementById('branchAdminModalTitle').textContent = 'Edit Branch Admin';
                    document.getElementById('branchAdminId').value = u.user_id;
                    document.getElementById('branchAdminFirstName').value = u.first_name || '';
                    document.getElementById('branchAdminLastName').value = u.last_name || '';
                    document.getElementById('branchAdminEmail').value = u.email || '';
                    document.getElementById('branchAdminContact').value = u.contact_number || '';
                    document.getElementById('branchAdminBranchId').value = u.branch_id || '';
                    document.getElementById('branchAdminStatus').value = u.status || 'active';
                    
                    document.getElementById('branchAdminPassword').required = false;
                    document.getElementById('branchAdminPassword').value = '';
                    document.getElementById('branchAdminPasswordNote').innerHTML = '<i class="bi bi-info-circle"></i> Leave blank to keep current password.';
                    
                    new bootstrap.Modal(document.getElementById('branchAdminModal')).show();
                    
                } else if (u.role === 'delivery') {
                    document.getElementById('driverModalTitle').textContent = 'Edit Driver';
                    document.getElementById('driverId').value = u.driver_id || '';
                    document.getElementById('driverUserId').value = u.user_id;
                    document.getElementById('driverName').value = u.driver_name || '';
                    document.getElementById('licenseNumber').value = u.license_number || '';
                    document.getElementById('licenseExpiry').value = u.license_expiry || '';
                    document.getElementById('contactNumber').value = u.contact_number || '';
                    document.getElementById('vehicleType').value = u.vehicle_type || '';
                    document.getElementById('vehiclePlate').value = u.vehicle_plate_number || '';
                    document.getElementById('driverBranchId').value = u.branch_id || '';
                    document.getElementById('driverStatus').value = u.status || 'active';
                    document.getElementById('driverFirstName').value = u.first_name || '';
                    document.getElementById('driverLastName').value = u.last_name || '';
                    document.getElementById('driverEmail').value = u.email || '';
                    
                    document.getElementById('driverPassword').required = false;
                    document.getElementById('driverPassword').value = '';
                    document.getElementById('driverPasswordNote').innerHTML = '<i class="bi bi-info-circle"></i> Leave blank to keep current password.';
                    
                    new bootstrap.Modal(document.getElementById('driverModal')).show();
                    
                } else if (u.role === 'warehouse') {
                    document.getElementById('warehouseModalTitle').textContent = 'Edit Warehouse Staff';
                    document.getElementById('warehouseId').value = u.user_id;
                    document.getElementById('warehouseFirstName').value = u.first_name || '';
                    document.getElementById('warehouseLastName').value = u.last_name || '';
                    document.getElementById('warehouseEmail').value = u.email || '';
                    document.getElementById('warehouseCategory').value = u.category || '';
                    document.getElementById('warehouseContact').value = u.contact_number || '';
                    document.getElementById('warehouseBranchId').value = u.branch_id || '';
                    document.getElementById('warehouseStatus').value = u.status || 'active';
                    
                    document.getElementById('warehousePassword').required = false;
                    document.getElementById('warehousePassword').value = '';
                    document.getElementById('warehousePasswordNote').innerHTML = '<i class="bi bi-info-circle"></i> Leave blank to keep current password.';
                    
                    new bootstrap.Modal(document.getElementById('warehouseModal')).show();
                    
                } else if (u.role === 'sales') {
                    document.getElementById('salesModalTitle').textContent = 'Edit Sales Agent';
                    document.getElementById('salesId').value = u.user_id;
                    document.getElementById('salesFirstName').value = u.first_name || '';
                    document.getElementById('salesLastName').value = u.last_name || '';
                    document.getElementById('salesEmail').value = u.email || '';
                    document.getElementById('salesContact').value = u.contact_number || '';
                    document.getElementById('salesBranchId').value = u.branch_id || '';
                    document.getElementById('salesStatus').value = u.status || 'active';
                    
                    document.getElementById('salesPassword').required = false;
                    document.getElementById('salesPassword').value = '';
                    document.getElementById('salesPasswordNote').innerHTML = '<i class="bi bi-info-circle"></i> Leave blank to keep current password.';
                    
                    new bootstrap.Modal(document.getElementById('salesModal')).show();
                }
            } else {
                Swal.fire('Error', data.message, 'error');
            }
        })
        .catch(error => {
            Swal.close();
            console.error('Error:', error);
            Swal.fire('Error', 'An error occurred: ' + error.message, 'error');
        });
    }

    // ========== SAVE FUNCTIONS ==========
    function saveBranchAdmin() {
        const userId = document.getElementById('branchAdminId').value;
        const firstName = document.getElementById('branchAdminFirstName').value.trim();
        const lastName = document.getElementById('branchAdminLastName').value.trim();
        const email = document.getElementById('branchAdminEmail').value.trim();
        const password = document.getElementById('branchAdminPassword').value;
        const branchId = document.getElementById('branchAdminBranchId').value;
        
        if (!firstName) return Swal.fire('Warning', 'First Name is required', 'warning');
        if (!lastName) return Swal.fire('Warning', 'Last Name is required', 'warning');
        if (!email) return Swal.fire('Warning', 'Email is required', 'warning');
        if (!branchId) return Swal.fire('Warning', 'Branch is required', 'warning');
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
            return Swal.fire('Warning', 'Invalid email format', 'warning');
        }
        
        if (!userId && !password) {
            return Swal.fire('Warning', 'Password is required for new users', 'warning');
        }
        if (password && password.length < 6) {
            return Swal.fire('Warning', 'Password must be at least 6 characters', 'warning');
        }
        
        showLoading();
        
        const formData = new FormData(document.getElementById('branchAdminForm'));
        if (userId) {
            formData.append('action', 'update_user');
            formData.append('user_role_type', 'branch_admin');
            formData.append('user_id', userId);
        } else {
            formData.append('action', 'add_branch_admin');
        }
        
        fetch('drivers.php', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
            Swal.close();
            if (data.success) {
                Swal.fire({ icon: 'success', title: 'Success!', text: data.message, timer: 2000, showConfirmButton: false })
                .then(() => {
                    bootstrap.Modal.getInstance(document.getElementById('branchAdminModal'))?.hide();
                    location.reload();
                });
            } else {
                Swal.fire('Error', data.message, 'error');
            }
        })
        .catch(error => {
            Swal.close();
            console.error('Error:', error);
            Swal.fire('Error', 'An error occurred: ' + error.message, 'error');
        });
    }

    function saveDriver() {
        const driverId = document.getElementById('driverId').value;
        const driverName = document.getElementById('driverName').value.trim();
        const licenseNumber = document.getElementById('licenseNumber').value.trim();
        const firstName = document.getElementById('driverFirstName').value.trim();
        const lastName = document.getElementById('driverLastName').value.trim();
        const email = document.getElementById('driverEmail').value.trim();
        const password = document.getElementById('driverPassword').value;
        const branchId = document.getElementById('driverBranchId').value;
        
        if (!driverName) return Swal.fire('Warning', 'Driver Name is required', 'warning');
        if (!licenseNumber) return Swal.fire('Warning', 'License Number is required', 'warning');
        if (!branchId) return Swal.fire('Warning', 'Branch is required', 'warning');
        if (!firstName) return Swal.fire('Warning', 'First Name is required', 'warning');
        if (!lastName) return Swal.fire('Warning', 'Last Name is required', 'warning');
        if (!email) return Swal.fire('Warning', 'Email is required', 'warning');
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
            return Swal.fire('Warning', 'Invalid email format', 'warning');
        }
        
        if (!driverId && !password) {
            return Swal.fire('Warning', 'Password is required for new users', 'warning');
        }
        if (password && password.length < 6) {
            return Swal.fire('Warning', 'Password must be at least 6 characters', 'warning');
        }
        
        showLoading();
        
        const formData = new FormData(document.getElementById('driverForm'));
        if (driverId) {
            formData.append('action', 'update_user');
            formData.append('user_role_type', 'driver');
            formData.append('user_id', currentUserId);
            formData.append('driver_id', driverId);
            formData.append('driver_status', document.getElementById('driverStatus').value);
        } else {
            formData.append('action', 'add_driver');
        }
        
        fetch('drivers.php', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
            Swal.close();
            if (data.success) {
                Swal.fire({ icon: 'success', title: 'Success!', text: data.message, timer: 2000, showConfirmButton: false })
                .then(() => {
                    bootstrap.Modal.getInstance(document.getElementById('driverModal'))?.hide();
                    location.reload();
                });
            } else {
                Swal.fire('Error', data.message, 'error');
            }
        })
        .catch(error => {
            Swal.close();
            console.error('Error:', error);
            Swal.fire('Error', 'An error occurred: ' + error.message, 'error');
        });
    }

    function saveWarehouse() {
        const userId = document.getElementById('warehouseId').value;
        const firstName = document.getElementById('warehouseFirstName').value.trim();
        const lastName = document.getElementById('warehouseLastName').value.trim();
        const email = document.getElementById('warehouseEmail').value.trim();
        const password = document.getElementById('warehousePassword').value;
        const branchId = document.getElementById('warehouseBranchId').value;
        
        if (!firstName) return Swal.fire('Warning', 'First Name is required', 'warning');
        if (!lastName) return Swal.fire('Warning', 'Last Name is required', 'warning');
        if (!email) return Swal.fire('Warning', 'Email is required', 'warning');
        if (!branchId) return Swal.fire('Warning', 'Branch is required', 'warning');
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
            return Swal.fire('Warning', 'Invalid email format', 'warning');
        }
        
        if (!userId && !password) {
            return Swal.fire('Warning', 'Password is required for new users', 'warning');
        }
        if (password && password.length < 6) {
            return Swal.fire('Warning', 'Password must be at least 6 characters', 'warning');
        }
        
        showLoading();
        
        const formData = new FormData(document.getElementById('warehouseForm'));
        if (userId) {
            formData.append('action', 'update_user');
            formData.append('user_role_type', 'warehouse');
            formData.append('user_id', userId);
        } else {
            formData.append('action', 'add_warehouse');
        }
        
        fetch('drivers.php', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
            Swal.close();
            if (data.success) {
                Swal.fire({ icon: 'success', title: 'Success!', text: data.message, timer: 2000, showConfirmButton: false })
                .then(() => {
                    bootstrap.Modal.getInstance(document.getElementById('warehouseModal'))?.hide();
                    location.reload();
                });
            } else {
                Swal.fire('Error', data.message, 'error');
            }
        })
        .catch(error => {
            Swal.close();
            console.error('Error:', error);
            Swal.fire('Error', 'An error occurred: ' + error.message, 'error');
        });
    }

    function saveSales() {
        const userId = document.getElementById('salesId').value;
        const firstName = document.getElementById('salesFirstName').value.trim();
        const lastName = document.getElementById('salesLastName').value.trim();
        const email = document.getElementById('salesEmail').value.trim();
        const password = document.getElementById('salesPassword').value;
        const branchId = document.getElementById('salesBranchId').value;
        
        if (!firstName) return Swal.fire('Warning', 'First Name is required', 'warning');
        if (!lastName) return Swal.fire('Warning', 'Last Name is required', 'warning');
        if (!email) return Swal.fire('Warning', 'Email is required', 'warning');
        if (!branchId) return Swal.fire('Warning', 'Branch is required', 'warning');
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
            return Swal.fire('Warning', 'Invalid email format', 'warning');
        }
        
        if (!userId && !password) {
            return Swal.fire('Warning', 'Password is required for new users', 'warning');
        }
        if (password && password.length < 6) {
            return Swal.fire('Warning', 'Password must be at least 6 characters', 'warning');
        }
        
        showLoading();
        
        const formData = new FormData(document.getElementById('salesForm'));
        if (userId) {
            formData.append('action', 'update_user');
            formData.append('user_role_type', 'sales');
            formData.append('user_id', userId);
        } else {
            formData.append('action', 'add_sales');
        }
        
        fetch('drivers.php', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
            Swal.close();
            if (data.success) {
                Swal.fire({ icon: 'success', title: 'Success!', text: data.message, timer: 2000, showConfirmButton: false })
                .then(() => {
                    bootstrap.Modal.getInstance(document.getElementById('salesModal'))?.hide();
                    location.reload();
                });
            } else {
                Swal.fire('Error', data.message, 'error');
            }
        })
        .catch(error => {
            Swal.close();
            console.error('Error:', error);
            Swal.fire('Error', 'An error occurred: ' + error.message, 'error');
        });
    }

    function deleteUser(id, role, driverId) {
        const row = document.querySelector(`.user-row[data-id="${id}"]`);
        if (!row) return;
        
        document.getElementById('deleteUserName').textContent = row.querySelector('td:first-child strong').textContent;
        currentUserId = id;
        currentUserRole = role;
        currentDriverId = driverId;
        new bootstrap.Modal(document.getElementById('deleteUserModal')).show();
    }

    function confirmDeleteUser() {
        if (!currentUserId) {
            return Swal.fire('Error', 'No user selected', 'error');
        }
        
        showLoading();
        
        const formData = new FormData();
        formData.append('action', 'delete_user');
        formData.append('user_id', currentUserId);
        
        fetch('drivers.php', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
            Swal.close();
            if (data.success) {
                Swal.fire({ icon: 'success', title: 'Deactivated!', text: data.message, timer: 2000, showConfirmButton: false })
                .then(() => {
                    bootstrap.Modal.getInstance(document.getElementById('deleteUserModal'))?.hide();
                    location.reload();
                });
            } else {
                Swal.fire('Error', data.message, 'error');
            }
        })
        .catch(error => {
            Swal.close();
            console.error('Error:', error);
            Swal.fire('Error', 'An error occurred: ' + error.message, 'error');
        });
    }

    // ========== EXCEL EXPORT (EXACTLY the same as users.php but with branch always included) ==========
    function exportToExcel() {
        const rows = document.querySelectorAll('.user-row:not([style*="display: none"])');
        if (!rows.length) {
            return Swal.fire('Warning', 'No users to export', 'warning');
        }
        
        const data = [['Full Name', 'Email', 'Role', 'Details', 'Status', 'Branch']];
        
        rows.forEach(row => {
            const cells = row.querySelectorAll('td');
            let i = 0;
            const nameCell = cells[i++]?.innerText || '';
            const nameLines = nameCell.split('\n');
            const rowData = [
                nameLines[0] || '',
                nameLines[1]?.trim() || '',
                cells[i++]?.innerText.trim() || '',
                cells[i++]?.innerText.replace(/\n/g, ' | ') || '',
                cells[i++]?.innerText.split('\n')[0] || '',
                cells[i++]?.innerText || ''
            ];
            data.push(rowData);
        });
        
        const wb = XLSX.utils.book_new();
        XLSX.utils.book_append_sheet(wb, XLSX.utils.aoa_to_sheet(data), 'Users');
        XLSX.writeFile(wb, `Global_Users_${new Date().toISOString().slice(0,10).replace(/-/g, '')}.xlsx`);
        
        Swal.fire({ icon: 'success', title: 'Export Complete', timer: 1500, showConfirmButton: false });
    }

    // ========== LOGOUT ==========
    function logout() {
        Swal.fire({
            title: 'Are you sure?',
            text: 'You will be logged out',
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#0d6efd',
            confirmButtonText: 'Yes, logout'
        }).then((result) => {
            if (result.isConfirmed) {
                localStorage.removeItem('sidebarCollapsed');
                window.location.href = '../logout.php';
            }
        });
    }

    // ========== KEYBOARD SHORTCUTS ==========
    document.addEventListener('keydown', function(e) {
        if (e.ctrlKey && e.key === 'b' && window.innerWidth > 992) {
            e.preventDefault();
            toggleSidebar();
        } else if (e.ctrlKey && e.key === '1') {
            e.preventDefault();
            showAddBranchAdminModal();
        } else if (e.ctrlKey && e.key === '2') {
            e.preventDefault();
            showAddDriverModal();
        } else if (e.ctrlKey && e.key === '3') {
            e.preventDefault();
            showAddWarehouseModal();
        } else if (e.ctrlKey && e.key === '4') {
            e.preventDefault();
            showAddSalesModal();
        } else if (e.ctrlKey && e.key === 'f') {
            e.preventDefault();
            document.getElementById('searchInput').focus();
        }
    });
    </script>
</body>
</html>